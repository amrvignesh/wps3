<?php
/*
Plugin Name: WPS3
Plugin URI: https://github.com/amrvignesh/wps3
Description: Offload WordPress uploads directory to S3 compatible storage
Version: 0.4
Requires at least: 5.0
Requires PHP: 7.0
Author: Vignesh AMR
Author URI: https://gigillion.com/wps3
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: wps3
Domain Path: /languages
*/

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WPS3_VERSION', '0.4');
define('WPS3_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPS3_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPS3_PLUGIN_FILE', __FILE__);

require_once 'vendor/aws/aws-autoloader.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once WPS3_PLUGIN_DIR . 'includes/class-wps3-s3-client.php';
require_once WPS3_PLUGIN_DIR . 'includes/class-wps3-settings.php';
require_once WPS3_PLUGIN_DIR . 'includes/class-wps3-migration.php';
require_once WPS3_PLUGIN_DIR . 'includes/class-wps3-migration-v2.php';

class WPS3
{
    private $s3_client_wrapper;
    protected $uploaded_file_s3_info = [];
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * WPS3 constructor.
     */
    public function __construct()
    {
        $this->s3_client_wrapper = new WPS3_S3_Client();
        new WPS3_Settings();
        // Use the new v2 migration with live control panel
        new WPS3_Migration_V2($this->s3_client_wrapper);

        add_action('wp_loaded', [$this, 'init']);
        add_action('add_attachment', [$this, 'upload_attachment']);
        add_action('delete_attachment', [$this, 'delete_attachment']);
        add_action('plugins_loaded', [$this, 'load_textdomain']);

        // Filters for rewriting URLs
        add_filter('wp_get_attachment_url', [$this, 'rewrite_attachment_url'], 99, 2);
        add_filter('image_downsize', [$this, 'rewrite_image_downsize'], 99, 3);
        add_filter('wp_handle_upload', [$this, 'upload_overrides']);
        add_filter('attachment_link', [$this, 'rewrite_attachment_url'], 99, 2);
        add_filter('wp_get_attachment_image_src', [$this, 'rewrite_image_src'], 99, 4);
        add_filter('wp_calculate_image_srcset', [$this, 'rewrite_srcset'], 99, 5);
        add_filter('wp_get_attachment_thumb_url', [$this, 'rewrite_attachment_url'], 99, 2);
        add_filter('wp_get_attachment_image_attributes', [$this, 'rewrite_image_attributes'], 99, 3);
        add_filter('wp_prepare_attachment_for_js', [$this, 'prepare_attachment_for_js'], 99, 2);
    }

    /**
     * Load plugin textdomain.
     */
    public function load_textdomain()
    {
        load_plugin_textdomain('wps3', false, dirname(plugin_basename(WPS3_PLUGIN_FILE)) . '/languages');
    }

    /**
     * Initialize the plugin.
     */
    public function init()
    {
        if (!get_option('wps3_enabled')) {
            return;
        }

        if (!$this->s3_client_wrapper->get_s3_client()) {
            add_action('admin_notices', function () {
                echo '<div class="error"><p>';
                echo esc_html__('WPS3: S3 client could not be initialized. Please ensure Endpoint URL, Bucket Name, and S3 Region are correctly configured in settings.', 'wps3');
                echo '</p></div>';
            });
            return;
        }
    }

    /**
     * Override the WordPress upload process to upload files to the S3 bucket.
     *
     * @param array $file_data File data.
     * @return array
     */
    public function upload_overrides($file_data)
    {
        if (!get_option('wps3_enabled') || !$this->s3_client_wrapper->get_s3_client()) {
            return $file_data;
        }

        $source_file_path = $file_data['file'];
        $upload_dir_info = wp_upload_dir();
        $relative_path = str_replace(trailingslashit($upload_dir_info['basedir']), '', $source_file_path);
        $unique_filename = basename($source_file_path);

        // Construct the S3 object key
        $key_parts = [];
        if (!empty($this->s3_client_wrapper->get_bucket_folder())) {
            $key_parts[] = trim($this->s3_client_wrapper->get_bucket_folder(), '/');
        }
        $key_parts[] = ltrim($relative_path, '/');
        $s3_object_key = implode('/', array_filter($key_parts));

        // Fire action before upload
        do_action('wps3_before_upload', $source_file_path, $s3_object_key, $file_data);

        // Allow filtering of upload options
        $upload_options = apply_filters('wps3_upload_options', [
            'Bucket'      => $this->s3_client_wrapper->get_bucket_name(),
            'Key'         => $s3_object_key,
            'Body'        => fopen($source_file_path, 'r'),
            'ACL'         => 'public-read',
            'ContentType' => $this->s3_client_wrapper->get_mime_type($source_file_path),
        ], $source_file_path, $s3_object_key, $file_data);

        // Upload the file to S3
        $upload_success = $this->s3_client_wrapper->upload_file_with_options($upload_options);
        
        if ($upload_success) {
            $s3_url = $this->s3_client_wrapper->get_s3_url($s3_object_key);
            
            // Update the file info with S3 data
            $file_data['url'] = $s3_url;
            
            // Store S3 info temporarily for upload_attachment to pick up
            $this->uploaded_file_s3_info[$unique_filename] = [
                'bucket' => $this->s3_client_wrapper->get_bucket_name(),
                'key'    => $s3_object_key,
                'url'    => $s3_url,
            ];
            
            $this->wps3_log("Successfully uploaded main file to S3: $source_file_path -> $s3_object_key", 'info');
            
            // Fire action after successful upload
            do_action('wps3_after_upload', $source_file_path, $s3_object_key, $s3_url, $file_data);
            
            if (get_option('wps3_delete_local')) {
                @unlink($source_file_path);
            }
        } else {
            $this->wps3_log("S3 upload failed in upload_overrides for file: $source_file_path", 'error');
        }

        return $file_data;
    }

    /**
     * Delete a file from the S3 bucket.
     *
     * @param int $attachment_id Attachment ID.
     */
    public function delete_attachment($attachment_id)
    {
        if (!$this->s3_client_wrapper->get_s3_client()) {
            return;
        }

        $s3_info = get_post_meta($attachment_id, 'wps3_s3_info', true);

        if (!empty($s3_info) && isset($s3_info['key'])) {
            $this->s3_client_wrapper->delete_object($s3_info['key']);

            $metadata = wp_get_attachment_metadata($attachment_id);
            if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size_info) {
                    $s3_key_base = dirname($s3_info['key']);
                    $s3_key_base = ($s3_key_base === '.' || $s3_key_base === '/') ? '' : trailingslashit($s3_key_base);
                    $s3_thumb_key = $s3_key_base . $size_info['file'];
                    $this->s3_client_wrapper->delete_object($s3_thumb_key);
                }
            }
        }
    }

    /**
     * Upload attachment to S3 when it's added to WordPress.
     *
     * @param int $attachment_id Attachment ID.
     */
    public function upload_attachment($attachment_id)
    {
        // This hook is now responsible for saving the S3 meta and uploading thumbnails.
        // The main file has already been uploaded by upload_overrides().
        
        // If plugin is not enabled, do nothing
        if (!get_option('wps3_enabled') || empty($this->s3_client_wrapper)) {
            return;
        }
        
        // Get the filename that WordPress has saved
        $attached_file_path_relative = get_post_meta($attachment_id, '_wp_attached_file', true);
        if (empty($attached_file_path_relative)) {
            return;
        }
        $unique_filename = basename($attached_file_path_relative);

        // Check if we have temporary S3 info for this filename
        if (isset($this->uploaded_file_s3_info[$unique_filename])) {
            $s3_info = $this->uploaded_file_s3_info[$unique_filename];
            
            // Save the S3 info as permanent post meta. This is the official tracking record.
            update_post_meta($attachment_id, 'wps3_s3_info', $s3_info);
            
            // Now, upload resized versions (thumbnails) if they exist
            $metadata = wp_get_attachment_metadata($attachment_id);
            if (!empty($metadata['sizes'])) {
                $upload_dir_info = wp_upload_dir();
                $base_dir_for_thumbnails_local = trailingslashit(dirname(trailingslashit($upload_dir_info['basedir']) . $attached_file_path_relative));
                
                $this->wps3_log("Processing thumbnails for attachment ID: $attachment_id, base dir: $base_dir_for_thumbnails_local", 'info');
                
                foreach ($metadata['sizes'] as $size_name => $size_data) {
                    $size_file_local_path = $base_dir_for_thumbnails_local . $size_data['file'];
                    if (file_exists($size_file_local_path)) {
                        // Generate S3 key for thumbnail
                        $wp_upload_dir = wp_upload_dir();
                        $thumb_relative_path = str_replace(trailingslashit($wp_upload_dir['basedir']), '', $size_file_local_path);
                        $thumb_key_parts = [];
                        if (!empty($this->s3_client_wrapper->get_bucket_folder())) {
                            $thumb_key_parts[] = trim($this->s3_client_wrapper->get_bucket_folder(), '/');
                        }
                        $thumb_key_parts[] = ltrim($thumb_relative_path, '/');
                        $thumb_s3_key = implode('/', array_filter($thumb_key_parts));
                        
                        // Fire action before thumbnail upload
                        do_action('wps3_before_upload', $size_file_local_path, $thumb_s3_key, ['size' => $size_name, 'attachment_id' => $attachment_id]);
                        
                        // upload_file will use its default hierarchical key generation for thumbnails
                        $thumb_upload_success = $this->s3_client_wrapper->upload_file($size_file_local_path);
                        if (!$thumb_upload_success) {
                            $this->wps3_log("Failed to upload thumbnail $size_name for attachment ID: $attachment_id, Path: $size_file_local_path", 'error');
                        } else {
                            $this->wps3_log("Successfully uploaded thumbnail $size_name for attachment ID: $attachment_id", 'info');
                            
                            // Fire action after successful thumbnail upload
                            $thumb_s3_url = $this->s3_client_wrapper->get_s3_url($thumb_upload_success);
                            do_action('wps3_after_upload', $size_file_local_path, $thumb_upload_success, $thumb_s3_url, ['size' => $size_name, 'attachment_id' => $attachment_id]);
                        }
                    } else {
                        $this->wps3_log("Thumbnail file not found for $size_name, attachment ID: $attachment_id, Path: $size_file_local_path", 'warning');
                    }
                }
            } else {
                $this->wps3_log("No thumbnail metadata found for attachment ID: $attachment_id", 'warning');
            }
            
            // Clean up the temporary storage
            unset($this->uploaded_file_s3_info[$unique_filename]);
            
            $this->wps3_log("Successfully processed attachment ID: $attachment_id with S3 info: " . print_r($s3_info, true), 'info');
        } else {
            $this->wps3_log("No temporary S3 info found for attachment ID: $attachment_id, filename: $unique_filename", 'warning');
        }
    }

    /**
     * Rewrite attachment URLs to point to S3.
     *
     * @param string|array $url Attachment URL (can be string or array).
     * @param int    $attachment_id Attachment ID.
     * @return string|array
     */
    public function rewrite_attachment_url($url, $attachment_id)
    {
        if (!get_option('wps3_enabled') || !$this->s3_client_wrapper->get_s3_client() || !is_numeric($attachment_id)) {
            return $url;
        }

        // Handle array input (sometimes WordPress passes arrays)
        if (is_array($url)) {
            $this->wps3_log("rewrite_attachment_url received array input for attachment {$attachment_id}: " . print_r($url, true), 'warning');
            return $url;
        }

        // Ensure we have a string
        if (!is_string($url) || empty($url)) {
            $this->wps3_log("rewrite_attachment_url received non-string input for attachment {$attachment_id}: " . gettype($url), 'warning');
            return $url;
        }

        $s3_info = get_post_meta($attachment_id, 'wps3_s3_info', true);
        if (empty($s3_info) || empty($s3_info['key'])) {
            return $url;
        }

        // Check if the URL is already an S3 URL
        if (strpos($url, $this->s3_client_wrapper->get_bucket_name()) !== false) {
            return $url;
        }

        $cdn_domain = get_option('wps3_cdn_domain');
        $s3_key = $s3_info['key'];

        if (!empty($cdn_domain)) {
            $s3_url = 'https://' . rtrim($cdn_domain, '/') . '/' . ltrim($s3_key, '/');
        } else {
            $s3_url = $this->s3_client_wrapper->get_s3_url($s3_key);
        }

        // Allow filtering of the final URL
        return apply_filters('wps3_file_url', $s3_url, $attachment_id, $s3_info, $url);
    }

    /**
     * Rewrite image downsize URLs to point to S3 or CDN.
     *
     * @param bool|array $downsize      Whether to short-circuit the image downsize.
     * @param int        $attachment_id Attachment ID for image.
     * @param string|array $size          Either a string keyword or a 2-item array representing width and height in pixels.
     * @return bool|array
     */
    public function rewrite_image_downsize($downsize, $attachment_id, $size)
    {
        if (!get_option('wps3_enabled') || !$this->s3_client_wrapper->get_s3_client()) {
            return $downsize;
        }

        $s3_info = get_post_meta($attachment_id, 'wps3_s3_info', true);
        if (empty($s3_info) || empty($s3_info['key'])) {
            return $downsize;
        }

        $meta = wp_get_attachment_metadata($attachment_id);
        if (empty($meta['sizes'])) {
            return $downsize;
        }

        if (is_string($size) && isset($meta['sizes'][$size])) {
            $size_info = $meta['sizes'][$size];
            if (isset($size_info['s3_key'])) {
                $s3_key = $size_info['s3_key'];
            } else {
                // Fallback for older uploads
                $s3_key = dirname($s3_info['key']) . '/' . $size_info['file'];
            }

            $cdn_domain = get_option('wps3_cdn_domain');

            if (!empty($cdn_domain)) {
                $url = 'https://' . rtrim($cdn_domain, '/') . '/' . ltrim($s3_key, '/');
            } else {
                $url = $this->s3_client_wrapper->get_s3_url($s3_key);
            }
            
            // Allow filtering of the final URL
            $url = apply_filters('wps3_file_url', $url, $attachment_id, $s3_info, null);
            
            return [$url, $size_info['width'], $size_info['height'], true];
        }

        return $downsize;
    }

    /**
     * Rewrite image src URLs in the media library.
     *
     * @param array|false $image         Array of image data, or boolean false if no image.
     * @param int         $attachment_id Image attachment ID.
     * @param string|array $size          Requested size.
     * @param bool        $icon          Whether the image should be treated as an icon.
     * @return array|false
     */
    public function rewrite_image_src($image, $attachment_id, $size, $icon) {
        if (!$image) {
            return $image;
        }

        $s3_info = get_post_meta($attachment_id, 'wps3_s3_info', true);
        if (!empty($s3_info) && isset($s3_info['key']) && $s3_info['bucket'] !== 'error') {
            $cdn_domain = get_option('wps3_cdn_domain');
            if (!empty($cdn_domain)) {
                $cdn_base = rtrim($cdn_domain, '/');
                if (strpos($cdn_base, 'http') !== 0) {
                    $cdn_base = 'https://' . $cdn_base;
                }
                $s3_key = ltrim($s3_info['key'], '/');
                $image[0] = $cdn_base . '/' . $s3_key;
            } elseif (isset($s3_info['url'])) {
                $image[0] = $s3_info['url'];
            } else {
                $image[0] = $this->s3_client_wrapper->get_s3_url($s3_info['key']);
            }
            
            // Allow filtering of the final URL
            $image[0] = apply_filters('wps3_file_url', $image[0], $attachment_id, $s3_info, $image[0]);
        }

        return $image;
    }

    /**
     * Rewrite srcset URLs for responsive images.
     *
     * @param array  $sources       Array of image sources.
     * @param array  $size_array    Array of width and height values.
     * @param string $image_src     The 'src' of the image.
     * @param array  $image_meta    The image meta data.
     * @param int    $attachment_id Image attachment ID.
     * @return array
     */
    public function rewrite_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        if (empty($sources)) {
            return $sources;
        }

        $s3_info = get_post_meta($attachment_id, 'wps3_s3_info', true);
        if (!empty($s3_info) && isset($s3_info['key']) && $s3_info['bucket'] !== 'error') {
            $cdn_domain = get_option('wps3_cdn_domain');
            $base_url = '';
            
            if (!empty($cdn_domain)) {
                $base_url = rtrim($cdn_domain, '/');
                if (strpos($base_url, 'http') !== 0) {
                    $base_url = 'https://' . $base_url;
                }
            } elseif (isset($s3_info['url'])) {
                $base_url = dirname($s3_info['url']);
            } else {
                $base_url = dirname($this->s3_client_wrapper->get_s3_url($s3_info['key']));
            }

            foreach ($sources as &$source) {
                if (isset($source['url'])) {
                    $filename = basename($source['url']);
                    $source['url'] = $base_url . '/' . $filename;
                }
            }
        }

        return $sources;
    }

    /**
     * Rewrite image attributes in the media library.
     *
     * @param array   $attr       Array of attribute values.
     * @param WP_Post $attachment Image attachment post.
     * @param string|array $size       Requested size.
     * @return array
     */
    public function rewrite_image_attributes($attr, $attachment, $size)
    {
        if (isset($attr['src']) && is_string($attr['src'])) {
            $attr['src'] = $this->rewrite_attachment_url($attr['src'], $attachment->ID);
        }

        if (isset($attr['srcset']) && is_string($attr['srcset'])) {
            // For srcset, we need to parse and rewrite each URL
            $srcset_parts = explode(',', $attr['srcset']);
            $rewritten_parts = [];
            
            foreach ($srcset_parts as $part) {
                $part = trim($part);
                if (preg_match('/^(.+?)\s+(\d+w|\d+x)$/', $part, $matches)) {
                    $url = trim($matches[1]);
                    $descriptor = $matches[2];
                    $rewritten_url = $this->rewrite_attachment_url($url, $attachment->ID);
                    $rewritten_parts[] = $rewritten_url . ' ' . $descriptor;
                } else {
                    $rewritten_parts[] = $part;
                }
            }
            
            $attr['srcset'] = implode(', ', $rewritten_parts);
        }

        return $attr;
    }

    /**
     * Prepare attachment data for the media modal.
     *
     * @param array   $response   Array of attachment data.
     * @param WP_Post $attachment Attachment object.
     * @return array
     */
    public function prepare_attachment_for_js($response, $attachment) {
        if (!get_option('wps3_enabled') || !$this->s3_client_wrapper->get_s3_client()) {
            return $response;
        }
        
        $s3_info = get_post_meta($attachment->ID, 'wps3_s3_info', true);
        if (!empty($s3_info) && isset($s3_info['key']) && $s3_info['bucket'] !== 'error') {
            // Rewrite the main attachment URL (only if it's a string)
            if (isset($response['url']) && is_string($response['url'])) {
                $response['url'] = $this->rewrite_attachment_url($response['url'], $attachment->ID);
            }

            // Rewrite the icon URL if present (for non-image files)
            if (isset($response['icon']) && is_string($response['icon'])) {
                $response['icon'] = $this->rewrite_attachment_url($response['icon'], $attachment->ID);
            }

            // Rewrite the image preview URL if present (for PDFs, etc.)
            if (isset($response['image']) && is_string($response['image'])) {
                $response['image'] = $this->rewrite_attachment_url($response['image'], $attachment->ID);
            }

            // Rewrite URLs for all available sizes (for images)
            if (isset($response['sizes']) && is_array($response['sizes'])) {
                foreach ($response['sizes'] as $size_name => &$size_data) {
                    if (is_array($size_data) && isset($size_data['url']) && is_string($size_data['url'])) {
                        $downsized = $this->rewrite_image_downsize(false, $attachment->ID, $size_name);
                        if (is_array($downsized) && isset($downsized[0])) {
                            $size_data['url'] = $downsized[0];
                        }
                    }
                }
                unset($size_data); // break reference
            }
        }
        return $response;
    }

    /**
     * Log messages using WordPress's error logging system.
     *
     * @param string $message
     * @param string $level (optional) Log level: 'error', 'warning', 'info', etc.
     */
    protected function wps3_log($message, $level = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WPS3 [{$level}]: $message");
        }
    }

    /**
     * Plugin activation hook.
     */
    public static function activate()
    {
        add_option('wps3_enabled', false);
        add_option('wps3_s3_path', '');
        add_option('wps3_access_key', '');
        add_option('wps3_secret_key', '');
        add_option('wps3_cdn_domain', '');
        add_option('wps3_delete_local', false);
        add_option('wps3_migrated_files', 0);
        add_option('wps3_migration_running', false);
        add_option('wps3_migration_file_list', []);
    }

    /**
     * Plugin deactivation hook.
     */
    public static function deactivate()
    {
        update_option('wps3_migration_running', false);
    }

    /**
     * Plugin uninstall hook.
     */
    public static function uninstall()
    {
        delete_option('wps3_enabled');
        delete_option('wps3_s3_path');
        delete_option('wps3_access_key');
        delete_option('wps3_secret_key');
        delete_option('wps3_cdn_domain');
        delete_option('wps3_delete_local');
        delete_option('wps3_migrated_files');
        delete_option('wps3_migration_running');
        delete_option('wps3_migration_file_list');
    }

    /**
     * Check S3 configuration status for debugging.
     *
     * @return array Configuration status
     */
    public function get_s3_config_status() {
        $status = [
            'plugin_enabled' => get_option('wps3_enabled'),
            'bucket_name' => get_option('wps3_bucket_name'),
            'bucket_folder' => get_option('wps3_bucket_folder'),
            's3_endpoint_url' => get_option('wps3_s3_endpoint_url'),
            's3_region' => get_option('wps3_s3_region'),
            'access_key' => !empty(get_option('wps3_access_key')) ? 'Set' : 'Not set',
            'secret_key' => !empty(get_option('wps3_secret_key')) ? 'Set' : 'Not set',
            's3_client_available' => $this->s3_client_wrapper->get_s3_client() !== null,
            'action_scheduler_available' => function_exists('as_enqueue_async_action'),
        ];
        
        return $status;
    }

    /**
     * Get S3 client wrapper.
     *
     * @return WPS3_S3_Client
     */
    public function get_s3_client_wrapper() {
        return $this->s3_client_wrapper;
    }

    /**
     * Upload a file by attachment ID (for migration).
     *
     * @param int $attachment_id Attachment ID.
     * @return bool|WP_Error
     */
    public function upload_file($attachment_id) {
        if (!get_option('wps3_enabled') || !$this->s3_client_wrapper->get_s3_client()) {
            return new WP_Error('s3_disabled', 'S3 upload is disabled or not configured');
        }

        // Get the attachment file path
        $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
        if (empty($attached_file)) {
            return new WP_Error('no_file', 'No attached file found for attachment ID: ' . $attachment_id);
        }

        $upload_dir = wp_upload_dir();
        $file_path = trailingslashit($upload_dir['basedir']) . $attached_file;

        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', 'File not found: ' . $file_path);
        }

        // Check if already migrated
        $s3_info = get_post_meta($attachment_id, 'wps3_s3_info', true);
        if (!empty($s3_info) && isset($s3_info['key'])) {
            $this->wps3_log("File already migrated, skipping: " . $file_path, 'info');
            return true;
        }

        try {
            // Fire action before upload
            do_action('wps3_before_upload', $file_path, '', ['migration' => true, 'attachment_id' => $attachment_id]);

            // Upload main file
            $s3_key = $this->s3_client_wrapper->upload_file($file_path);
            if (!$s3_key) {
                return new WP_Error('upload_failed', 'Failed to upload file to S3: ' . $file_path);
            }

            // Save S3 info
            $s3_info = [
                'bucket' => $this->s3_client_wrapper->get_bucket_name(),
                'key'    => $s3_key,
                'url'    => $this->s3_client_wrapper->get_s3_url($s3_key),
            ];
            update_post_meta($attachment_id, 'wps3_s3_info', $s3_info);

            $this->wps3_log("Successfully migrated attachment ID {$attachment_id}: {$file_path}", 'info');

            // Fire action after upload
            do_action('wps3_after_upload', $file_path, $s3_key, $s3_info['url'], [
                'migration' => true,
                'attachment_id' => $attachment_id
            ]);

            // Upload thumbnails
            $this->upload_attachment_thumbnails($attachment_id, $file_path);

            return true;

        } catch (Exception $e) {
            $error_msg = 'Exception during migration: ' . $e->getMessage();
            $this->wps3_log($error_msg, 'error');
            return new WP_Error('migration_exception', $error_msg);
        }
    }

    /**
     * Upload thumbnails for an attachment during migration.
     *
     * @param int $attachment_id Attachment ID.
     * @param string $main_file_path Main file path.
     */
    private function upload_attachment_thumbnails($attachment_id, $main_file_path) {
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (empty($metadata['sizes'])) {
            return;
        }

        $upload_dir = wp_upload_dir();
        $base_dir = trailingslashit(dirname($main_file_path));
        $uploaded_thumbnails = 0;

        foreach ($metadata['sizes'] as $size_name => $size_data) {
            $thumb_path = $base_dir . $size_data['file'];
            
            if (file_exists($thumb_path)) {
                try {
                    // Fire action before thumbnail migration
                    do_action('wps3_before_upload', $thumb_path, '', [
                        'migration' => true,
                        'thumbnail' => true,
                        'size' => $size_name,
                        'attachment_id' => $attachment_id
                    ]);
                    
                    $thumb_key = $this->s3_client_wrapper->upload_file($thumb_path);
                    if ($thumb_key) {
                        $uploaded_thumbnails++;
                        $this->wps3_log("Migrated thumbnail {$size_name} for attachment {$attachment_id}", 'info');
                        
                        // Fire action after thumbnail migration
                        do_action('wps3_after_upload', $thumb_path, $thumb_key, $this->s3_client_wrapper->get_s3_url($thumb_key), [
                            'migration' => true,
                            'thumbnail' => true,
                            'size' => $size_name,
                            'attachment_id' => $attachment_id
                        ]);
                    }
                } catch (Exception $e) {
                    $this->wps3_log("Failed to migrate thumbnail {$size_name} for attachment {$attachment_id}: " . $e->getMessage(), 'error');
                }
            }
        }
        
        if ($uploaded_thumbnails > 0) {
            $this->wps3_log("Migrated {$uploaded_thumbnails} thumbnails for attachment {$attachment_id}", 'info');
        }
    }
}

// Action Scheduler Integration for Background Jobs
add_action('init', function() {
    if (!function_exists('as_enqueue_async_action')) {
        // Add admin notice if Action Scheduler is not available
        add_action('admin_notices', function() {
            if (current_user_can('manage_options')) {
                echo '<div class="notice notice-warning is-dismissible">';
                echo '<p><strong>WPS3:</strong> Action Scheduler plugin is required for background migration. Please install and activate the Action Scheduler plugin for optimal migration performance.</p>';
                echo '</div>';
            }
        });
        return;
    }
    
    // Register the batch worker action
    add_action('wps3_do_batch', 'wps3_batch_worker', 10, 1);
});

/**
 * Background job worker for processing individual attachments
 */
function wps3_batch_worker($attachment_id) {
    $state = get_option('wps3_migration_state', []);
    if (empty($state) || 'running' !== $state['status']) {
        WPS3_S3_Client::wps3_log("Background job skipped - migration not running. State: " . print_r($state, true), 'info');
        return;
    }

    try {
        // Get the WPS3 instance and upload the file
        $wps3 = WPS3::get_instance();
        $s3_client_wrapper = $wps3->get_s3_client_wrapper();
        
        if (!$s3_client_wrapper || !$s3_client_wrapper->get_s3_client()) {
            throw new Exception('S3 client not available');
        }

        WPS3_S3_Client::wps3_log("Starting background job for attachment {$attachment_id}", 'info');
        
        $result = $wps3->upload_file($attachment_id);
        
        // Update state
        $state = get_option('wps3_migration_state', []); // Re-fetch to avoid race conditions
        $state['processing'] = max(0, $state['processing'] - 1);
        $state['done'] += 1;
        $state['queued'] = max(0, $state['queued'] - 1);
        
        if (is_wp_error($result)) {
            $state['last_error'] = $result->get_error_message();
            WPS3_S3_Client::wps3_log("Migration failed for attachment {$attachment_id}: " . $result->get_error_message(), 'error');
        } else {
            $state['last_error'] = '';
            WPS3_S3_Client::wps3_log("Successfully processed attachment {$attachment_id} in background job", 'info');
        }
        
        update_option('wps3_migration_state', $state);
        
    } catch (Exception $e) {
        $state = get_option('wps3_migration_state', []); // Re-fetch to avoid race conditions
        $state['processing'] = max(0, $state['processing'] - 1);
        $state['last_error'] = $e->getMessage();
        update_option('wps3_migration_state', $state);
        WPS3_S3_Client::wps3_log("Background job exception for attachment {$attachment_id}: " . $e->getMessage(), 'error');
    }
}

// REST API Endpoints for Migration Control
add_action('rest_api_init', function() {
    register_rest_route('wps3/v1', '/migrate', [
        'methods' => 'POST',
        'callback' => 'wps3_api_migrate',
        'permission_callback' => 'wps3_check_permissions',
        'args' => [
            'do' => [
                'required' => true,
                'type' => 'string',
                'enum' => ['start', 'pause', 'resume', 'cancel'],
            ],
        ],
    ]);
    
    register_rest_route('wps3/v1', '/status', [
        'methods' => 'GET',
        'callback' => 'wps3_api_status',
        'permission_callback' => 'wps3_check_permissions',
    ]);
    
    register_rest_route('wps3/v1', '/debug', [
        'methods' => 'GET',
        'callback' => 'wps3_api_debug',
        'permission_callback' => 'wps3_check_permissions',
    ]);
});

/**
 * Check permissions for REST API endpoints
 */
function wps3_check_permissions($request) {
    // Check if user has manage_options capability
    if (!current_user_can('manage_options')) {
        return false;
    }
    
    // For admin-ajax requests, check nonce
    if (defined('DOING_AJAX') && DOING_AJAX) {
        return wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wps3_nonce');
    }
    
    // For REST requests, check if user is logged in and has proper capabilities
    if (is_user_logged_in()) {
        return true;
    }
    
    return false;
}

/**
 * REST API endpoint for migration control
 */
function wps3_api_migrate($request) {
    $action = $request->get_param('do');
    $state = get_option('wps3_migration_state', []);
    
    switch ($action) {
        case 'start':
            if (empty($state) || in_array($state['status'], ['finished', 'error', 'cancelled'])) {
                global $wpdb;
                
                // Get all attachment IDs that need migration (not already migrated)
                $ids = $wpdb->get_col(
                    "SELECT p.ID FROM {$wpdb->posts} p
                     LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'wps3_s3_info'
                     WHERE p.post_type = 'attachment' 
                     AND p.post_mime_type != ''
                     AND pm.meta_value IS NULL
                     ORDER BY p.ID ASC"
                );
                
                $batch_size = 50;
                $total_ids = count($ids);
                
                if ($total_ids === 0) {
                    $state = [
                        'status' => 'finished',
                        'total' => 0,
                        'done' => 0,
                        'queued' => 0,
                        'processing' => 0,
                        'started_at' => time(),
                        'last_error' => 'No attachments found that need migration',
                        'batch_size' => $batch_size,
                    ];
                    update_option('wps3_migration_state', $state);
                    WPS3_S3_Client::wps3_log("No attachments found that need migration", 'info');
                    break;
                }
                
                $state = [
                    'status' => 'running',
                    'total' => $total_ids,
                    'done' => 0,
                    'queued' => $total_ids,
                    'processing' => 0,
                    'started_at' => time(),
                    'last_error' => '',
                    'batch_size' => $batch_size,
                ];
                
                update_option('wps3_migration_state', $state);
                WPS3_S3_Client::wps3_log("Starting migration for {$total_ids} attachments", 'info');
                
                // Queue all jobs if Action Scheduler is available
                if (function_exists('as_enqueue_async_action')) {
                    foreach ($ids as $id) {
                        as_enqueue_async_action('wps3_do_batch', [$id], 'wps3');
                        $state['processing']++;
                    }
                    update_option('wps3_migration_state', $state);
                    WPS3_S3_Client::wps3_log("Queued {$total_ids} jobs with Action Scheduler", 'info');
                } else {
                    $state['last_error'] = 'Action Scheduler not available - background migration disabled';
                    $state['status'] = 'error';
                    update_option('wps3_migration_state', $state);
                    WPS3_S3_Client::wps3_log("Action Scheduler not available for background migration", 'error');
                }
            }
            break;
            
        case 'pause':
            if (!empty($state) && $state['status'] === 'running') {
                $state['status'] = 'paused';
                update_option('wps3_migration_state', $state);
            }
            break;
            
        case 'resume':
            if (!empty($state) && $state['status'] === 'paused') {
                $state['status'] = 'running';
                update_option('wps3_migration_state', $state);
            }
            break;
            
        case 'cancel':
            if (!empty($state) && in_array($state['status'], ['running', 'paused'])) {
                $state['status'] = 'cancelled';
                update_option('wps3_migration_state', $state);
                
                // Cancel all pending jobs
                if (function_exists('as_unschedule_all_actions')) {
                    as_unschedule_all_actions('wps3_do_batch', [], 'wps3');
                }
            }
            break;
    }
    
    return rest_ensure_response($state);
}

/**
 * REST API endpoint for getting migration status
 */
function wps3_api_status($request) {
    $state = get_option('wps3_migration_state', []);
    
    // Set default values if state is empty
    if (empty($state)) {
        $state = [
            'status' => 'ready',
            'total' => 0,
            'done' => 0,
            'queued' => 0,
            'processing' => 0,
            'started_at' => 0,
            'last_error' => '',
            'batch_size' => 50,
        ];
    }
    
    return rest_ensure_response($state);
}

/**
 * REST API endpoint for debugging
 */
function wps3_api_debug($request) {
    $wps3 = WPS3::get_instance();
    $s3_client_wrapper = $wps3->get_s3_client_wrapper();
    
    $debug_info = [
        'plugin_enabled' => get_option('wps3_enabled'),
        's3_client_available' => $s3_client_wrapper->get_s3_client() !== null,
        's3_config' => $wps3->get_s3_config_status(),
        'migration_state' => get_option('wps3_migration_state', []),
    ];
    
    return rest_ensure_response($debug_info);
}

// Admin AJAX shim for backward compatibility
add_action('wp_ajax_wps3_state', function() {
    $state = get_option('wps3_migration_state', []);
    
    // Set default values if state is empty
    if (empty($state)) {
        $state = [
            'status' => 'ready',
            'total' => 0,
            'done' => 0,
            'queued' => 0,
            'processing' => 0,
            'started_at' => 0,
            'last_error' => '',
            'batch_size' => 50,
        ];
    }
    
    wp_send_json_success($state);
});

add_action('wp_ajax_wps3_api', function() {
    // Verify nonce for admin-ajax requests
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wps3_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
        return;
    }
    
    $action = sanitize_text_field($_POST['do'] ?? '');
    $request = new WP_REST_Request('POST', '/wps3/v1/migrate');
    $request->set_param('do', $action);
    $response = wps3_api_migrate($request);
    wp_send_json_success($response->get_data());
});

add_action('wp_ajax_wps3_debug', function() {
    // Verify nonce for admin-ajax requests
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wps3_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
        return;
    }
    
    $wps3 = WPS3::get_instance();
    $debug_info = $wps3->get_s3_config_status();
    $debug_info['migration_state'] = get_option('wps3_migration_state', []);
    
    // Get sample of attachments that need migration
    global $wpdb;
    $sample_attachments = $wpdb->get_results(
        "SELECT p.ID, p.post_title, pm.meta_value as file_path
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
         LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'wps3_s3_info'
         WHERE p.post_type = 'attachment' 
         AND p.post_mime_type != ''
         AND pm.meta_value IS NOT NULL
         AND pm2.meta_value IS NULL
         ORDER BY p.ID DESC
         LIMIT 5"
    );
    
    $debug_info['sample_attachments'] = $sample_attachments;
    $debug_info['total_attachments_needing_migration'] = $wpdb->get_var(
        "SELECT COUNT(p.ID) FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'wps3_s3_info'
         WHERE p.post_type = 'attachment' 
         AND p.post_mime_type != ''
         AND pm.meta_value IS NULL"
    );
    
    wp_send_json_success($debug_info);
});

/**
 * Initialize the plugin.
 */
function register_wps3()
{
    WPS3::get_instance();
    add_filter('plugin_action_links_' . plugin_basename(WPS3_PLUGIN_FILE), function ($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=wps3-settings') . '">' . __('Settings', 'wps3') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    });
}

add_action('plugins_loaded', 'register_wps3');

register_activation_hook(WPS3_PLUGIN_FILE, ['WPS3', 'activate']);
register_deactivation_hook(WPS3_PLUGIN_FILE, ['WPS3', 'deactivate']);
register_uninstall_hook(WPS3_PLUGIN_FILE, ['WPS3', 'uninstall']);
