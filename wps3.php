<?php
/*
Plugin Name: WPS3
Plugin URI: https://github.com/amrvignesh/wps3
Description: Offload WordPress uploads directory to S3 compatible storage
Version: 0.3
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
define('WPS3_VERSION', '0.3');
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

class WPS3
{
    private $s3_client_wrapper;

    /**
     * WPS3 constructor.
     */
    public function __construct()
    {
        $this->s3_client_wrapper = new WPS3_S3_Client();
        new WPS3_Settings();
        new WPS3_Migration($this->s3_client_wrapper);

        add_action('wp_loaded', [$this, 'init']);
        add_action('wp_insert_attachment', [$this, 'upload_attachment']);
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

        $s3_key = $this->s3_client_wrapper->upload_file($file_data['file']);

        if ($s3_key) {
            $s3_url = $this->s3_client_wrapper->get_s3_url($s3_key);
            $file_data['url'] = $s3_url;
            $file_data['s3_info'] = [
                'bucket' => $this->s3_client_wrapper->get_bucket_name(),
                'key'    => $s3_key,
                'url'    => $s3_url,
            ];

            if (get_option('wps3_delete_local')) {
                @unlink($file_data['file']);
            }
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
        if (!get_option('wps3_enabled') || !$this->s3_client_wrapper->get_s3_client()) {
            return;
        }

        $file_path = get_attached_file($attachment_id);
        if (empty($file_path) || !file_exists($file_path)) {
            return;
        }

        $s3_key = $this->s3_client_wrapper->upload_file($file_path);

        if ($s3_key) {
            $s3_url = $this->s3_client_wrapper->get_s3_url($s3_key);
            update_post_meta($attachment_id, 'wps3_s3_info', [
                'bucket' => $this->s3_client_wrapper->get_bucket_name(),
                'key'    => $s3_key,
                'url'    => $s3_url,
            ]);

            $metadata = wp_get_attachment_metadata($attachment_id);
            if (!empty($metadata['sizes'])) {
                $base_dir = trailingslashit(dirname($file_path));
                foreach ($metadata['sizes'] as $size_data) {
                    $size_file_path = $base_dir . $size_data['file'];
                    if (file_exists($size_file_path)) {
                        $this->s3_client_wrapper->upload_file($size_file_path);
                    }
                }
            }
        }
    }

    /**
     * Rewrite attachment URLs to point to S3.
     *
     * @param string $url Attachment URL.
     * @param int    $attachment_id Attachment ID.
     * @return string
     */
    public function rewrite_attachment_url($url, $attachment_id)
    {
        if (!get_option('wps3_enabled') || !$this->s3_client_wrapper->get_s3_client() || !is_numeric($attachment_id)) {
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
        $filename = basename(get_attached_file($attachment_id));
        $s3_filename = basename($s3_key);

        if ($filename !== $s3_filename) {
            $s3_key = str_replace($s3_filename, $filename, $s3_key);
        }

        if (!empty($cdn_domain)) {
            return 'https://' . rtrim($cdn_domain, '/') . '/' . ltrim($s3_key, '/');
        }

        return $this->s3_client_wrapper->get_s3_url($s3_key);
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
            $s3_key = dirname($s3_info['key']) . '/' . $meta['sizes'][$size]['file'];
            $url = $this->rewrite_attachment_url($this->s3_client_wrapper->get_s3_url($s3_key), $attachment_id);
            return [$url, $meta['sizes'][$size]['width'], $meta['sizes'][$size]['height'], true];
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
    public function rewrite_image_src($image, $attachment_id, $size, $icon)
    {
        if ($image) {
            $image[0] = $this->rewrite_attachment_url($image[0], $attachment_id);
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
    public function rewrite_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id)
    {
        if (is_array($sources)) {
            foreach ($sources as $width => &$source) {
                $source['url'] = $this->rewrite_attachment_url($source['url'], $attachment_id);
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
        if (isset($attr['src'])) {
            $attr['src'] = $this->rewrite_attachment_url($attr['src'], $attachment->ID);
        }
        if (isset($attr['srcset'])) {
            $attr['srcset'] = $this->rewrite_srcset($attr['srcset'], [], '', [], $attachment->ID);
        }
        return $attr;
    }

    /**
     * Plugin activation hook.
     */
    public static function activate()
    {
        add_option('wps3_enabled', false);
        add_option('wps3_s3_endpoint_url', '');
        add_option('wps3_bucket_name', '');
        add_option('wps3_bucket_folder', '');
        add_option('wps3_s3_region', '');
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
        delete_option('wps3_s3_endpoint_url');
        delete_option('wps3_bucket_name');
        delete_option('wps3_bucket_folder');
        delete_option('wps3_s3_region');
        delete_option('wps3_access_key');
        delete_option('wps3_secret_key');
        delete_option('wps3_cdn_domain');
        delete_option('wps3_delete_local');
        delete_option('wps3_migrated_files');
        delete_option('wps3_migration_running');
        delete_option('wps3_migration_file_list');
    }
}

/**
 * Initialize the plugin.
 */
function register_wps3()
{
    new WPS3();
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
