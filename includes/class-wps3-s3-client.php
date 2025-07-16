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
                error_log('WPS3: Error initializing S3 client: ' . $e->getMessage());
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
     * @return bool|string
     */
    public function upload_file($file_path) {
        if (!$this->s3_client) {
            return false;
        }

        if (!file_exists($file_path)) {
            return false;
        }

        try {
            $file_basename = basename($file_path);
            $key_parts = [];
            if (!empty($this->bucket_folder)) {
                $key_parts[] = trim($this->bucket_folder, '/');
            }
            $key_parts[] = $file_basename;
            $key = implode('/', $key_parts);

            $this->s3_client->putObject([
                'Bucket'      => $this->bucket_name,
                'Key'         => $key,
                'Body'        => fopen($file_path, 'r'),
                'ACL'         => 'public-read',
                'ContentType' => $this->get_mime_type($file_path),
            ]);

            return $key;
        } catch (\Exception $e) {
            error_log('S3 upload error for file ' . $file_path . ': ' . $e->getMessage());
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
            error_log('WPS3: Error deleting object ' . $key . ' from S3: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get mime type.
     *
     * @param string $file_path
     * @return string
     */
    private function get_mime_type($file_path) {
        $mime_type = wp_check_filetype($file_path);
        return $mime_type['type'] ?? 'application/octet-stream';
    }
}
