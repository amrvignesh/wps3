<?php
/*
Plugin Name: WPS3
Plugin URI: https://github.com/amrvignesh/wps3
Description: Offload WordPress uploads directory to S3 compatible storage
Version: 0.2
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
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'WPS3_VERSION', '0.2' );
define( 'WPS3_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPS3_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPS3_PLUGIN_FILE', __FILE__ );
define( 'WPS3_MAX_BATCH_SIZE', 10 );
define( 'WPS3_RETRY_ATTEMPTS', 3 );
define( 'WPS3_TIMEOUT', 30 );
define( 'WPS3_PROCESS_BATCH_DELAY', 1000 ); // Delay between batch processing in milliseconds

require_once 'aws/aws-autoloader.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

/**
 * Interface for S3 storage operations
 */
interface S3StorageInterface {
    /**
     * Upload a file to S3 storage
     *
     * @param string $file Path to the file to upload
     * @param array  $options Additional upload options
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function upload( $file, $options = array() );

    /**
     * Delete a file from S3 storage
     *
     * @param string $key The S3 object key
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function delete( $key );

    /**
     * Get the URL for an S3 object
     *
     * @param string $key The S3 object key
     * @return string|WP_Error The URL or WP_Error on failure
     */
    public function getUrl( $key );

    /**
     * Check if an object exists in S3
     *
     * @param string $key The S3 object key
     * @return bool Whether the object exists
     */
    public function exists( $key );
}

/**
 * Custom exceptions for WPS3
 */
class WPS3Exception extends Exception {}
class ConfigurationException extends WPS3Exception {}
class FileSizeException extends WPS3Exception {}
class InvalidUrlException extends WPS3Exception {}

/**
 * The S3 Uploads Offloader class.
 */
class WPS3 implements S3StorageInterface {
    /**
     * The S3 client.
     *
     * @var \Aws\S3\S3Client
     */
    protected $s3_client;

    /**
     * The S3 bucket name.
     *
     * @var string
     */
    protected $bucket_name;

    /**
     * The S3 bucket region.
     *
     * @var string
     */
    protected $bucket_region;

    /**
     * The S3 bucket folder.
     *
     * @var string
     */
    protected $bucket_folder;

    /**
     * The S3 endpoint URL.
     *
     * @var string
     */
    protected $endpoint;

    /**
     * The batch size for file migration
     * 
     * @var int
     */
    protected $batch_size = 5;

    /**
     * The constructor.
     */
    public function __construct() {
        // Get configuration from options
        $this->bucket_name = get_option('wps3_bucket_name');
        $this->endpoint = get_option('wps3_endpoint');
        $this->bucket_folder = get_option('wps3_folder', '');
        $this->bucket_region = get_option('wps3_region', 'us-east-1');

        // Initialize S3 client if we have the necessary configuration
        if (!empty($this->bucket_name) && !empty($this->endpoint)) {
            $this->init_s3_client();
        }
    }

    /**
     * Initialize the S3 client
     */
    private function init_s3_client() {
        $access_key = get_option('wps3_access_key');
        $secret_key = get_option('wps3_secret_key');

        if (empty($access_key) || empty($secret_key)) {
            return;
        }

        $config = array(
            'version'     => 'latest',
            'region'      => $this->bucket_region,
            'credentials' => array(
                'key'    => $access_key,
                'secret' => $secret_key,
            ),
            'use_aws_shared_config_files' => false,
            '@http' => array(
                'verify' => true,
                'timeout' => WPS3_TIMEOUT
            )
        );

        // Add custom endpoint if not AWS
        if (!empty($this->endpoint) && strpos($this->endpoint, 'amazonaws.com') === false) {
            $config['endpoint'] = rtrim($this->endpoint, '/');
            $config['use_path_style_endpoint'] = true;
        }

        try {
            $this->s3_client = new \Aws\S3\S3Client($config);
        } catch (\Exception $e) {
            error_log('WPS3: Error initializing S3 client: ' . $e->getMessage());
            add_action('admin_notices', function() use ($e) {
                echo '<div class="error"><p>';
                echo esc_html__('WPS3: Error initializing S3 client: ', 'wps3') . esc_html($e->getMessage());
                echo '</p></div>';
            });
        }
    }

    /**
     * Register the plugin's hooks.
     *
     * @return void
     */
    public function register_hooks() {
        // Actions
        add_action('wp_loaded', [$this, 'init']);
        add_action('wp_insert_attachment', [$this, 'upload_attachment']);
        add_action('delete_attachment', [$this, 'delete_attachment']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        
        // AJAX handlers
        add_action('wp_ajax_wps3_start_migration', [$this, 'ajax_start_migration']);
        add_action('wp_ajax_wps3_process_batch', [$this, 'ajax_process_batch']);
        add_action('wp_ajax_wps3_get_migration_status', [$this, 'ajax_get_migration_status']);
        add_action('wp_ajax_wps3_pause_migration', [$this, 'ajax_pause_migration']);
        add_action('wp_ajax_wps3_reset_migration', [$this, 'ajax_reset_migration']);
        add_action('wp_ajax_wps3_update_option', [$this, 'ajax_update_option']);
        
        // Filters for rewriting URLs
        add_filter('wp_get_attachment_url', [$this, 'rewrite_attachment_url'], 10, 2);
        add_filter('image_downsize', [$this, 'rewrite_image_downsize'], 10, 3);
        add_filter('wp_handle_upload', [$this, 'upload_overrides']);
    }

    /**
     * Load plugin text domain.
     *
     * @return void
     */
    public function load_textdomain() {
        load_plugin_textdomain('wps3', false, dirname(plugin_basename(WPS3_PLUGIN_FILE)) . '/languages');
    }

    /**
     * Initialize the plugin.
     *
     * @return void
     */
    public function init() {
        // Check if the plugin is enabled.
        if (!get_option('wps3_enabled')) {
            return;
        }

        // Check if S3 client is properly initialized
        if (empty($this->s3_client)) {
            return;
        }

        // Check if the S3 bucket exists.
        try {
            if (!$this->s3_client->doesBucketExist($this->bucket_name)) {
                add_action('admin_notices', function() {
                    echo '<div class="error"><p>';
                    echo esc_html__('WPS3: The S3 bucket does not exist. Please check your settings.', 'wps3');
                    echo '</p></div>';
                });
                return;
            }
        } catch (\Exception $e) {
            error_log('WPS3: Error checking bucket existence: ' . $e->getMessage());
            return;
        }
    }

    /**
     * Upload a file to S3 storage
     *
     * @param string $file Path to the file to upload
     * @param array $options Additional upload options
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function upload($file, $options = []) {
        try {
            // Validate file
            if (!file_exists($file)) {
                return new \WP_Error('file_not_found', 'File does not exist: ' . $file);
            }

            // Validate file size
            $this->validate_file_size($file);

            // Get file info
            $file_info = wp_check_filetype(basename($file));
            if (!$file_info['type']) {
                return new \WP_Error('invalid_file_type', 'Invalid file type for: ' . $file);
            }

            // Prepare upload key
            $relative_path = $this->get_relative_path($file);
            $key = trim($this->bucket_folder . '/' . $relative_path, '/');
            
            // Upload to S3 with retry logic
            $attempts = 0;
            $max_attempts = WPS3_RETRY_ATTEMPTS;
            
            while ($attempts < $max_attempts) {
                try {
                    $result = $this->s3_client->putObject([
                        'Bucket' => $this->bucket_name,
                        'Key' => $key,
                        'Body' => fopen($file, 'r'),
                        'ContentType' => $file_info['type'],
                        'ACL' => 'public-read',
                    ]);
                    
                    // Success - break out of retry loop
                    break;
                } catch (\Aws\S3\Exception\S3Exception $e) {
                    $attempts++;
                    if ($attempts >= $max_attempts) {
                        $this->log_error($e, 'upload', [
                            'file' => $file,
                            'key' => $key,
                            'attempts' => $attempts
                        ]);
                        return new \WP_Error('s3_error', $e->getMessage());
                    }
                    // Wait before retry
                    sleep(1);
                }
            }

            // Delete local file if enabled
            if (get_option('wps3_delete_local')) {
                @unlink($file);
            }

            return true;
        } catch (\Exception $e) {
            $this->log_error($e, 'upload', ['file' => $file, 'options' => $options]);
            return new \WP_Error('general_error', $e->getMessage());
        }
    }

    /**
     * Delete a file from S3 storage
     *
     * @param string $key The S3 object key
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function delete($key) {
        try {
            $this->s3_client->deleteObject([
                'Bucket' => $this->bucket_name,
                'Key' => $key
            ]);
            return true;
        } catch (\Exception $e) {
            $this->log_error($e, 'delete', ['key' => $key, 'bucket' => $this->bucket_name]);
            return new \WP_Error('delete_error', $e->getMessage());
        }
    }

    /**
     * Get the URL for an S3 object
     *
     * @param string $key The S3 object key
     * @return string|WP_Error The URL or WP_Error on failure
     */
    public function getUrl($key) {
        try {
            $url = $this->generate_object_url($key);
            return esc_url($url);
        } catch (\Exception $e) {
            $this->log_error($e, 'getUrl', ['key' => $key]);
            return new \WP_Error('url_error', $e->getMessage());
        }
    }

    /**
     * Check if an object exists in S3
     *
     * @param string $key The S3 object key
     * @return bool Whether the object exists
     */
    public function exists($key) {
        try {
            return $this->s3_client->doesObjectExist($this->bucket_name, $key);
        } catch (\Exception $e) {
            error_log('WPS3: Existence check error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get relative path from uploads directory
     */
    private function get_relative_path($file_path) {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        
        if (strpos($file_path, $base_dir) === 0) {
            return ltrim(substr($file_path, strlen($base_dir)), '/');
        }
        
        return basename($file_path);
    }

    /**
     * Validate file size before upload
     *
     * @param string $file_path Path to the file
     * @throws FileSizeException If file is too large
     */
    private function validate_file_size($file_path) {
        $max_size = wp_max_upload_size();
        if (filesize($file_path) > $max_size) {
            throw new FileSizeException("File exceeds maximum allowed size of " . size_format($max_size));
        }
    }

    /**
     * Override the WordPress upload process to upload files to the S3 bucket.
     */
    public function upload_overrides($file) {
        // Check if the plugin is enabled.
        if (!get_option('wps3_enabled') || empty($this->s3_client)) {
            return $file;
        }

        // Upload the file to S3
        $upload_result = $this->upload($file['file']);
        
        if (is_wp_error($upload_result)) {
            // Log error but don't break the upload process
            error_log('WPS3: Failed to upload to S3: ' . $upload_result->get_error_message());
            return $file;
        }
        
        // Generate the S3 URL for the file
        $relative_path = $this->get_relative_path($file['file']);
        $key = trim($this->bucket_folder . '/' . $relative_path, '/');
        $s3_url = $this->generate_object_url($key);
        
        // Update the file info with S3 data
        $file['url'] = $s3_url;
        $file['s3_info'] = array(
            'bucket' => $this->bucket_name,
            'key' => $key,
            'url' => $s3_url,
        );

        return $file;
    }

    /**
     * Delete a file from the S3 bucket.
     *
     * @param int $attachment_id The ID of the attachment.
     */
    public function delete_attachment($attachment_id) {
        if (!get_option('wps3_enabled') || empty($this->s3_client)) {
            return;
        }

        $attachment_file = get_post_meta($attachment_id, '_wp_attached_file', true);

        if (!empty($attachment_file)) {
            $key = trim($this->bucket_folder . '/' . $attachment_file, '/');
            $this->delete($key);
            
            // Also delete any image sizes
            $metadata = wp_get_attachment_metadata($attachment_id);
            if (!empty($metadata['sizes'])) {
                $base_dir = trailingslashit(dirname($attachment_file));
                
                foreach ($metadata['sizes'] as $size => $size_data) {
                    $size_key = trim($this->bucket_folder . '/' . $base_dir . $size_data['file'], '/');
                    $this->delete($size_key);
                }
            }
        }
    }

    /**
     * Upload attachment to S3 when it's added to WordPress.
     * 
     * @param int $attachment_id The attachment ID.
     * @return void
     */
    public function upload_attachment($attachment_id) {
        // If plugin is not enabled, do nothing
        if (!get_option('wps3_enabled') || empty($this->s3_client)) {
            return;
        }
        
        // Get the file path
        $file_path = get_attached_file($attachment_id);
        if (empty($file_path) || !file_exists($file_path)) {
            return;
        }
        
        // Upload the main file
        $this->upload($file_path);
        
        // Upload resized versions if they exist
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!empty($metadata['sizes'])) {
            $base_dir = trailingslashit(dirname($file_path));
            
            foreach ($metadata['sizes'] as $size => $size_data) {
                $size_file_path = $base_dir . $size_data['file'];
                if (file_exists($size_file_path)) {
                    $this->upload($size_file_path);
                }
            }
        }
    }

    /**
     * Rewrite attachment URLs to point to S3.
     */
    public function rewrite_attachment_url($url, $attachment_id) {
        // If plugin is not enabled, return the original URL
        if (!get_option('wps3_enabled') || empty($this->s3_client)) {
            return $url;
        }
        
        // Get the attachment file path
        $attachment_file = get_post_meta($attachment_id, '_wp_attached_file', true);
        if (empty($attachment_file)) {
            return $url;
        }
        
        // Check if the attachment exists in S3
        try {
            $key = trim($this->bucket_folder . '/' . $attachment_file, '/');
            if ($this->exists($key)) {
                return $this->generate_object_url($key);
            }
        } catch (\Exception $e) {
            $this->log_error($e, 'rewrite_attachment_url', [
                'attachment_id' => $attachment_id,
                'attachment_file' => $attachment_file
            ]);
        }
        
        return $url;
    }
    
    /**
     * Rewrite image resize URLs to point to S3.
     *
     * @param array|false $downsize The existing downsize result
     * @param int $attachment_id The attachment ID
     * @param string|array $size The requested size
     * @return array|false The modified downsize result
     */
    public function rewrite_image_downsize($downsize, $attachment_id, $size) {
        // If already short-circuited or plugin is not enabled, return
        if ($downsize !== false || !get_option('wps3_enabled') || empty($this->s3_client)) {
            return $downsize;
        }
        
        // If $size is an array (e.g., [150, 150]), let WordPress handle it.
        // Using an array as a key for $metadata['sizes'] causes a fatal error.
        if (is_array($size)) {
            return $downsize;
        }
        
        // Get the metadata
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (empty($metadata)) {
            return $downsize;
        }

        // Handle full size image
        if ($size === 'full' || empty($metadata['sizes'][$size])) {
            $url = $this->rewrite_attachment_url(wp_get_attachment_url($attachment_id), $attachment_id);

            // If a valid URL could not be determined, return original $downsize
            if (!is_string($url) || empty($url)) {
                return $downsize;
            }

            $width = isset($metadata['width']) ? intval($metadata['width']) : 0;
            $height = isset($metadata['height']) ? intval($metadata['height']) : 0;
            return [$url, $width, $height, false];
        }

        // Handle intermediate sizes
        if (isset($metadata['sizes'][$size])) {
            $size_data = $metadata['sizes'][$size];
            $attachment_path = get_post_meta($attachment_id, '_wp_attached_file', true);
            
            if (empty($attachment_path)) {
                return $downsize;
            }

            $base_dir = trailingslashit(dirname($attachment_path));
            $size_file = $base_dir . $size_data['file'];
            
            try {
                $key = trim($this->bucket_folder . '/' . $size_file, '/');
                
                // Check if file exists in S3 with retry logic
                $exists = false;
                $attempts = 0;
                $max_attempts = WPS3_RETRY_ATTEMPTS;
                
                while ($attempts < $max_attempts) {
                    try {
                        $exists = $this->exists($key);
                        break;
                    } catch (\Exception $e) {
                        $attempts++;
                        if ($attempts >= $max_attempts) {
                            throw $e;
                        }
                        sleep(1);
                    }
                }

                if ($exists) {
                    $url = $this->generate_object_url($key);
                    return [
                        $url,
                        intval($size_data['width']),
                        intval($size_data['height']),
                        true
                    ];
                }
            } catch (\Exception $e) {
                $this->log_error($e, 'rewrite_image_downsize', [
                    'attachment_id' => $attachment_id,
                    'size' => $size,
                    'size_data' => $size_data,
                    'key' => $key ?? null
                ]);
                
                // Fallback to local file if available
                $local_file = wp_upload_dir()['basedir'] . '/' . $size_file;
                if (file_exists($local_file)) {
                    $url = wp_upload_dir()['baseurl'] . '/' . $size_file;
                    return [
                        $url,
                        intval($size_data['width']),
                        intval($size_data['height']),
                        true
                    ];
                }
            }
        }
        
        return $downsize;
    }

    /**
     * Generate the URL for an S3 object
     */
    protected function generate_object_url($key) {
        // For custom endpoints, use path-style URLs
        if (!empty($this->endpoint) && strpos($this->endpoint, 'amazonaws.com') === false) {
            return sprintf('%s/%s/%s', rtrim($this->endpoint, '/'), $this->bucket_name, $key);
        }
        
        // For AWS, use virtual-hosted-style URLs
        return sprintf('https://%s.s3.%s.amazonaws.com/%s', $this->bucket_name, $this->bucket_region, $key);
    }

    /**
     * Add an admin menu for the plugin.
     */
    public function add_admin_menu() {
        add_options_page(
            'WPS3 Settings',
            'WPS3',
            'manage_options',
            'wps3-settings',
            [$this, 'render_settings_page']
        );
        
        add_media_page(
            'S3 Migration',
            'S3 Migration',
            'manage_options',
            'wps3-migration',
            [$this, 'render_migration_page']
        );
    }

    /**
     * Register the plugin's settings.
     */
    public function register_settings() {
        // Add settings section
        add_settings_section(
            'wps3_section',
            __('S3 Configuration', 'wps3'),
            [$this, 'settings_section_callback'],
            'wps3'
        );

        // Register all settings
        $settings = [
            'wps3_enabled' => 'sanitize_boolean',
            'wps3_bucket_name' => 'sanitize_bucket_name',
            'wps3_endpoint' => 'sanitize_endpoint',
            'wps3_folder' => 'sanitize_folder',
            'wps3_access_key' => 'sanitize_access_key',
            'wps3_secret_key' => 'sanitize_secret_key',
            'wps3_region' => 'sanitize_region',
            'wps3_delete_local' => 'sanitize_boolean'
        ];

        foreach ($settings as $setting => $callback) {
            register_setting('wps3', $setting, [$this, $callback]);
            
            // Add settings field
            $field_name = str_replace('wps3_', '', $setting);
            add_settings_field(
                $setting,
                ucwords(str_replace('_', ' ', $field_name)),
                [$this, 'settings_field_' . $field_name . '_callback'],
                'wps3',
                'wps3_section'
            );
        }
    }

    // Sanitization methods
    public function sanitize_boolean($value) {
        return (bool) $value;
    }

    public function sanitize_bucket_name($value) {
        $value = sanitize_text_field($value);
        if (empty($value)) {
            add_settings_error('wps3_bucket_name', 'empty', __('Bucket name is required.', 'wps3'));
            return get_option('wps3_bucket_name');
        }
        return $value;
    }

    public function sanitize_endpoint($value) {
        $value = esc_url_raw($value);
        if (empty($value)) {
            add_settings_error('wps3_endpoint', 'empty', __('Endpoint URL is required.', 'wps3'));
            return get_option('wps3_endpoint');
        }
        return rtrim($value, '/');
    }

    public function sanitize_folder($value) {
        return trim(sanitize_text_field($value), '/');
    }

    public function sanitize_access_key($value) {
        $value = sanitize_text_field($value);
        if (empty($value)) {
            add_settings_error('wps3_access_key', 'empty', __('Access key is required.', 'wps3'));
            return get_option('wps3_access_key');
        }
        return $value;
    }

    public function sanitize_secret_key($value) {
        $value = sanitize_text_field($value);
        if (empty($value)) {
            add_settings_error('wps3_secret_key', 'empty', __('Secret key is required.', 'wps3'));
            return get_option('wps3_secret_key');
        }
        return $value;
    }

    public function sanitize_region($value) {
        return sanitize_text_field($value);
    }

    // Settings field callbacks
    public function settings_section_callback() {
        echo '<p>' . __('Configure your S3-compatible storage settings below.', 'wps3') . '</p>';
    }

    public function settings_field_enabled_callback() {
        $value = get_option('wps3_enabled');
        echo '<input type="checkbox" name="wps3_enabled" value="1" ' . checked(1, $value, false) . ' />';
        echo '<p class="description">' . __('Enable S3 uploads offloading', 'wps3') . '</p>';
    }

    public function settings_field_bucket_name_callback() {
        $value = get_option('wps3_bucket_name');
        echo '<input type="text" name="wps3_bucket_name" value="' . esc_attr($value) . '" class="regular-text" required />';
        echo '<p class="description">' . __('Your S3 bucket name', 'wps3') . '</p>';
    }

    public function settings_field_endpoint_callback() {
        $value = get_option('wps3_endpoint');
        echo '<input type="url" name="wps3_endpoint" value="' . esc_attr($value) . '" class="regular-text" required />';
        echo '<p class="description">' . __('S3 endpoint URL (e.g., https://s3.amazonaws.com)', 'wps3') . '</p>';
    }

    public function settings_field_folder_callback() {
        $value = get_option('wps3_folder');
        echo '<input type="text" name="wps3_folder" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Optional folder path within bucket', 'wps3') . '</p>';
    }

    public function settings_field_access_key_callback() {
        $value = get_option('wps3_access_key');
        echo '<input type="text" name="wps3_access_key" value="' . esc_attr($value) . '" class="regular-text" required />';
        echo '<p class="description">' . __('S3 access key', 'wps3') . '</p>';
    }

    public function settings_field_secret_key_callback() {
        $value = get_option('wps3_secret_key');
        echo '<input type="password" name="wps3_secret_key" value="' . esc_attr($value) . '" class="regular-text" required />';
        echo '<p class="description">' . __('S3 secret key', 'wps3') . '</p>';
    }

    public function settings_field_region_callback() {
        $value = get_option('wps3_region', 'us-east-1');
        echo '<input type="text" name="wps3_region" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('S3 region (default: us-east-1)', 'wps3') . '</p>';
    }

    public function settings_field_delete_local_callback() {
        $value = get_option('wps3_delete_local');
        echo '<input type="checkbox" name="wps3_delete_local" value="1" ' . checked(1, $value, false) . ' />';
        echo '<p class="description">' . __('<strong>Warning:</strong> Delete local files after S3 upload', 'wps3') . '</p>';
    }

    /**
     * Render the settings page.
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('wps3');
                do_settings_sections('wps3');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Enqueue admin scripts.
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'media_page_wps3-migration') {
            return;
        }
        
        wp_enqueue_script(
            'wps3-admin-js',
            WPS3_PLUGIN_URL . 'js/admin.js',
            ['jquery'],
            WPS3_VERSION,
            true
        );
        
        wp_localize_script('wps3-admin-js', 'wps3_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wps3_ajax_nonce'),
            'process_batch_delay' => WPS3_PROCESS_BATCH_DELAY,
        ]);
        
        wp_enqueue_style(
            'wps3-admin-css',
            WPS3_PLUGIN_URL . 'css/admin.css',
            [],
            WPS3_VERSION
        );
    }
    
    /**
     * Ensure asset directories exist.
     */
    private function ensure_asset_directories() {
        wp_mkdir_p(WPS3_PLUGIN_DIR . 'js');
        wp_mkdir_p(WPS3_PLUGIN_DIR . 'css');
    }
    
    /**
     * Create default JS file.
     */
    private function create_default_js_file() {
        $js_content = file_get_contents(__DIR__ . '/assets/admin.js');
        if ($js_content === false) {
            // Fallback minimal JS
            $js_content = "jQuery(document).ready(function($) { console.log('WPS3 Admin loaded'); });";
        }
        file_put_contents(WPS3_PLUGIN_DIR . 'js/wps3-admin.js', $js_content);
    }
    
    /**
     * Create default CSS file.
     */
    private function create_default_css_file() {
        $css_content = file_get_contents(__DIR__ . '/assets/admin.css');
        if ($css_content === false) {
            // Fallback minimal CSS
            $css_content = ".wps3-migration-status { margin: 20px 0; }";
        }
        file_put_contents(WPS3_PLUGIN_DIR . 'css/wps3-admin.css', $css_content);
    }

    /**
     * Render the migration page.
     */
    public function render_migration_page() {
        $total_files = $this->count_files_to_migrate();
        $migrated_files = get_option('wps3_migrated_files', 0);
        $migration_running = get_option('wps3_migration_running', false);
        $percent_complete = $total_files > 0 ? round(($migrated_files / $total_files) * 100) : 0;
        
        ?>
        <div class="wrap">
            <h1><?php _e('S3 Migration Status', 'wps3'); ?></h1>
            
            <div class="wps3-migration-status">
                <div class="wps3-progress-bar-container">
					<div class="wps3-progress-bar" style="width: <?php echo esc_attr($percent_complete); ?>%;">
						<span><?php echo esc_html($percent_complete); ?>%</span>
					</div>
                </div>
                
                <div class="wps3-migration-stats">
                    <p><?php printf(__('Progress: %1$d of %2$d files migrated', 'wps3'), $migrated_files, $total_files); ?></p>
                </div>
                
                <div class="wps3-migration-controls">
                    <?php if ($migration_running): ?>
                        <button type="button" id="wps3-pause-migration" class="button button-primary">
                            <?php _e('Pause Migration', 'wps3'); ?>
                        </button>
                    <?php else: ?>
                        <button type="button" id="wps3-start-migration" class="button button-primary">
                            <?php _e('Start Migration', 'wps3'); ?>
                        </button>
						<button type="button" id="wps3-reset-migration" class="button button-secondary">
							<?php _e('Reset Migration', 'wps3'); ?>
						</button>
                    <?php endif; ?>
                </div>
                
                <div class="wps3-migration-log">
                    <h3><?php _e('Migration Log', 'wps3'); ?></h3>
                    <div id="wps3-log-container"></div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Count the number of files to migrate.
     */
    protected function count_files_to_migrate() {
        $upload_dir = wp_upload_dir();
        if (!is_dir($upload_dir['basedir'])) {
            return 0;
        }
        
        $files = $this->get_all_files_recursive($upload_dir['basedir']);
        return count($files);
    }
    
    /**
     * Get all files in a directory recursively.
     */
    protected function get_all_files_recursive($dir) {
        $files = [];
        
        if (!is_dir($dir)) {
            return $files;
        }
        
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files[] = $file->getPathname();
                }
            }
        } catch (\Exception $e) {
            error_log('WPS3: Error reading directory: ' . $e->getMessage());
        }
        
        return $files;
    }

    // AJAX handlers with proper error handling
    public function ajax_start_migration() {
        if (!check_ajax_referer('wps3_ajax_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed');
        }

        try {
            update_option('wps3_migration_running', true);
            update_option('wps3_migrated_files', 0);
            
            wp_send_json_success(['message' => 'Migration started successfully']);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajax_process_batch() {
        if (!check_ajax_referer('wps3_ajax_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed');
        }

        try {
            $result = $this->process_migration_batch();
            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajax_get_migration_status() {
        if (!check_ajax_referer('wps3_ajax_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed');
        }

        $total_files = $this->count_files_to_migrate();
        $migrated_files = get_option('wps3_migrated_files', 0);
        $migration_running = get_option('wps3_migration_running', false);

        wp_send_json_success([
            'total_files' => $total_files,
            'migrated_files' => $migrated_files,
            'migration_running' => $migration_running,
            'percent_complete' => $total_files > 0 ? round(($migrated_files / $total_files) * 100) : 0
        ]);
    }

    public function ajax_pause_migration() {
        if (!check_ajax_referer('wps3_ajax_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed');
        }

        update_option('wps3_migration_running', false);
        wp_send_json_success(['message' => 'Migration paused']);
    }

    public function ajax_reset_migration() {
        if (!check_ajax_referer('wps3_ajax_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed');
        }

        // Reset migration options
        update_option('wps3_migrated_files', 0);
        update_option('wps3_migration_running', false);

        wp_send_json_success(['message' => 'Migration reset']);
    }

    public function ajax_update_option() {
        if (!check_ajax_referer('wps3_ajax_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed');
        }

        $option_name = sanitize_text_field($_POST['option_name'] ?? '');
        $option_value = sanitize_text_field($_POST['option_value'] ?? '');

        // Only allow updating specific WPS3 options
        $allowed_options = [
            'wps3_migration_running',
            'wps3_migrated_files'
        ];

        if (!in_array($option_name, $allowed_options)) {
            wp_send_json_error('Invalid option name');
        }

        // Convert string 'false' to boolean false
        if ($option_value === 'false') {
            $option_value = false;
        } elseif ($option_value === 'true') {
            $option_value = true;
        }

        update_option($option_name, $option_value);
        wp_send_json_success(['message' => 'Option updated successfully']);
    }

    /**
     * Process a batch of files for migration
     */
    private function process_migration_batch() {
        $files = $this->get_all_files_recursive(wp_upload_dir()['basedir']);
        $migrated_count = get_option('wps3_migrated_files', 0);
        $batch_start = $migrated_count;
        $batch_end = min($batch_start + WPS3_MAX_BATCH_SIZE, count($files));
        
        $errors = [];
        $success_count = 0;
        
        for ($i = $batch_start; $i < $batch_end; $i++) {
            $file = $files[$i];
            $result = $this->upload($file);
            
            if (is_wp_error($result)) {
                $errors[] = [
                    'file' => $file,
                    'error' => $result->get_error_message()
                ];
            } else {
                $success_count++;
            }
        }
        
        // Update progress
        $new_migrated_count = $migrated_count + $success_count;
        update_option('wps3_migrated_files', $new_migrated_count);
        
        // Check if migration is complete
        $complete = $new_migrated_count >= count($files);
        if ($complete) {
            update_option('wps3_migration_running', false);
        }
        
        return [
            'migrated_files' => $new_migrated_count,
            'total_files' => count($files),
            'percent_complete' => count($files) > 0 ? round(($new_migrated_count / count($files)) * 100) : 0,
            'complete' => $complete,
            'error_count' => count($errors),
            'errors' => $errors,
            'message' => sprintf('Processed %d files, %d errors', $success_count, count($errors))
        ];
    }

    /**
     * Log detailed error information
     */
    private function log_error($error, $context = '', $extra_data = []) {
        $error_message = sprintf(
            'WPS3 Error in %s: %s',
            $context,
            $error->getMessage()
        );
        
        if (!empty($extra_data)) {
            $error_message .= ' | Data: ' . json_encode($extra_data);
        }
        
        error_log($error_message);
    }

    /**
     * Plugin activation hook.
     */
    public static function activate() {
        $defaults = [
            'wps3_enabled' => false,
            'wps3_delete_local' => false,
            'wps3_bucket_name' => '',
            'wps3_endpoint' => '',
            'wps3_folder' => '',
            'wps3_access_key' => '',
            'wps3_secret_key' => '',
            'wps3_region' => 'us-east-1',
            'wps3_migrated_files' => 0,
            'wps3_migration_running' => false
        ];

        foreach ($defaults as $option => $value) {
            add_option($option, $value);
        }
    }

    /**
     * Plugin deactivation hook.
     */
    public static function deactivate() {
        update_option('wps3_migration_running', false);
    }

    /**
     * Plugin uninstall hook.
     */
    public static function uninstall() {
        // Optionally remove all plugin data
        if (defined('WPS3_REMOVE_DATA') && WPS3_REMOVE_DATA) {
            $options = [
                'wps3_enabled', 'wps3_delete_local', 'wps3_bucket_name',
                'wps3_endpoint', 'wps3_folder', 'wps3_access_key',
                'wps3_secret_key', 'wps3_region', 'wps3_migrated_files',
                'wps3_migration_running'
            ];

            foreach ($options as $option) {
                delete_option($option);
            }
        }
    }
}

/**
 * Initialize the plugin.
 */
function wps3_init() {
    $wps3 = new WPS3();
    $wps3->register_hooks();
    
    // Add Settings link to plugins page
    add_filter('plugin_action_links_' . plugin_basename(WPS3_PLUGIN_FILE), function($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=wps3-settings') . '">' . __('Settings', 'wps3') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    });
}

// Initialize the plugin
add_action('plugins_loaded', 'wps3_init');

// Register hooks
register_activation_hook(WPS3_PLUGIN_FILE, ['WPS3', 'activate']);
register_deactivation_hook(WPS3_PLUGIN_FILE, ['WPS3', 'deactivate']);
register_uninstall_hook(WPS3_PLUGIN_FILE, ['WPS3', 'uninstall']);
