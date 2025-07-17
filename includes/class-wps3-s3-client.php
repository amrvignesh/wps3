<?php

use Aws\S3\S3Client;

/**
 * Class WPS3_S3_Client
 */
class WPS3_S3_Client {

    /**
     * S3 client.
     *
     * @var S3Client
     */
    private $s3_client;

    /**
     * Bucket name.
     *
     * @var string
     */
    private $bucket_name;

    /**
     * Bucket folder.
     *
     * @var string
     */
    private $bucket_folder;

    /**
     * WPS3_S3_Client constructor.
     */
    public function __construct() {
        $endpoint_url = get_option('wps3_s3_endpoint_url');
        $this->bucket_name = get_option('wps3_bucket_name');
        $this->bucket_folder = get_option('wps3_bucket_folder', '');
        $region = get_option('wps3_s3_region');
        $access_key = get_option('wps3_access_key');
        $secret_key = get_option('wps3_secret_key');

        if (!empty($endpoint_url) && !empty($this->bucket_name) && !empty($region) && !empty($access_key) && !empty($secret_key)) {
            $config = [
                'version'     => 'latest',
                'region'      => $region,
                'endpoint'    => $endpoint_url,
                'credentials' => [
                    'key'    => $access_key,
                    'secret' => $secret_key,
                ],
                'use_path_style_endpoint' => true,
            ];

            try {
                $this->s3_client = new S3Client($config);
            } catch (\Exception $e) {
                self::wps3_log('Error initializing S3 client: ' . $e->getMessage(), 'error');
                $this->s3_client = null;
            }
        }
    }

    /**
     * Get S3 client.
     *
     * @return S3Client|null
     */
    public function get_s3_client() {
        return $this->s3_client;
    }

    /**
     * Get bucket name.
     *
     * @return string
     */
    public function get_bucket_name() {
        return $this->bucket_name;
    }

    /**
     * Get bucket folder.
     *
     * @return string
     */
    public function get_bucket_folder() {
        return $this->bucket_folder;
    }

    /**
     * Get S3 URL.
     *
     * @param string $key
     * @return string
     */
    public function get_s3_url($key) {
        if (!$this->s3_client) {
            return '';
        }
        return $this->s3_client->getObjectUrl($this->bucket_name, $key);
    }

    /**
     * Upload file.
     *
     * @param string $file_path
     * @param string|null $custom_key Optional custom S3 key
     * @return bool|string
     */
    public function upload_file($file_path, $custom_key = null) {
        if (!$this->s3_client) {
            return false;
        }

        if (!file_exists($file_path)) {
            return false;
        }

        if ($custom_key !== null) {
            $s3_key = $custom_key;
        } else {
            $wp_upload_dir = wp_upload_dir();
            $s3_key = str_replace($wp_upload_dir['basedir'], '', $file_path);
            $s3_key = ltrim($s3_key, '/');

            if (!empty($this->bucket_folder)) {
                $s3_key = trailingslashit($this->bucket_folder) . $s3_key;
            }
        }

        try {
            $this->s3_client->putObject([
                'Bucket'      => $this->bucket_name,
                'Key'         => $s3_key,
                'Body'        => fopen($file_path, 'r'),
                'ACL'         => 'public-read',
                'ContentType' => $this->get_mime_type($file_path),
            ]);

            return $s3_key;
        } catch (\Exception $e) {
            self::wps3_log('S3 upload error for file ' . $file_path . ': ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Upload file with custom options.
     *
     * @param array $upload_options S3 putObject options
     * @return bool|string
     */
    public function upload_file_with_options($upload_options) {
        if (!$this->s3_client) {
            return false;
        }

        try {
            $this->s3_client->putObject($upload_options);
            return isset($upload_options['Key']) ? $upload_options['Key'] : true;
        } catch (\Exception $e) {
            self::wps3_log('S3 upload error with custom options: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Delete object.
     *
     * @param string $key
     * @return bool
     */
    public function delete_object($key) {
        if (!$this->s3_client) {
            return false;
        }

        try {
            $this->s3_client->deleteObject([
                'Bucket' => $this->bucket_name,
                'Key'    => $key,
            ]);
            return true;
        } catch (\Exception $e) {
            self::wps3_log('Error deleting object ' . $key . ' from S3: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Get mime type (public method).
     *
     * @param string $file_path
     * @return string
     */
    public function get_mime_type($file_path) {
        $mime_type = wp_check_filetype($file_path);
        return $mime_type['type'] ?? 'application/octet-stream';
    }

    /**
     * Get mime type (private method for backward compatibility).
     *
     * @param string $file_path
     * @return string
     */
    private function get_mime_type_private($file_path) {
        return $this->get_mime_type($file_path);
    }

    /**
     * Log messages using WordPress's error logging system.
     *
     * @param string $message
     * @param string $level (optional) Log level: 'error', 'warning', 'info', etc.
     */
    public static function wps3_log($message, $level = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WPS3 [{$level}]: $message");
        }
    }
}
