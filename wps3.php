<?php
/*
Plugin Name: S3 Uploads Offloader
Description: Offload WordPress uploads directory to S3 compatible storage
Version: 0.1
Author: Vignesh AMR
*/

// Define AWS SDK dependency
require_once(ABSPATH . 'wp-content/plugins/s3-uploads/vendor/autoload.php');

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

// Initialize the S3 client
$s3_client = new S3Client([
    'version' => 'latest',
    'region' => 'your_s3_bucket_region',
    'credentials' => [
        'key' => 'your_s3_access_key',
        'secret' => 'your_s3_secret_key',
    ],
    'endpoint' => 'your_s3_compatible_endpoint',
    // Update with your S3-compatible storage endpoint
    'use_path_style_endpoint' => true, // Set to true if your S3-compatible storage uses path-style URLs
]);

// Hook into WordPress actions
add_filter('upload_dir', 's3_uploads_offloader');
add_action('delete_attachment', 's3_delete_attachment');

// Function to offload uploads folder to S3
function s3_uploads_offloader($uploads)
{
    global $s3_client;

    // Set S3 bucket name
    $bucket_name = 'your_s3_bucket_name';

    // Check if the bucket exists, create if necessary
    try {
        $s3_client->headBucket(['Bucket' => $bucket_name]);
    } catch (S3Exception $e) {
        $s3_client->createBucket(['Bucket' => $bucket_name]);
    }

    // Move existing files to S3 bucket
    $local_uploads_dir = wp_upload_dir();
    $local_files = scandir($local_uploads_dir['path']);
    foreach ($local_files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $local_file_path = $local_uploads_dir['path'] . '/' . $file;
        $s3_key = 'your_desired_folder_in_s3_bucket/' . $file;
        $s3_client->putObject([
            'Bucket' => $bucket_name,
            'Key' => $s3_key,
            'SourceFile' => $local_file_path,
        ]);
        // Delete the local file after successful upload to S3 (optional)
        unlink($local_file_path);
    }

    // Configure WordPress to use S3 bucket for future uploads
    $uploads['baseurl'] = "https://{$bucket_name}.your_s3_compatible_endpoint";
    $uploads['url'] = $uploads['baseurl'] . '/your_desired_folder_in_s3_bucket';
    $uploads['path'] = wp_normalize_path(WP_CONTENT_DIR . '/uploads');

    return $uploads;
}

// Function to delete attachment from S3
function s3_delete_attachment($post_id)
{
    global $s3_client;

    // Get the attachment's S3 key
    $attachment_meta = wp_get_attachment_metadata($post_id);
    $s3_key = 'your_desired_folder_in_s3_bucket/' . $attachment_meta['file'];

    // Delete the file from S3 bucket
    try {
        $s3_client->deleteObject([
            'Bucket' => 'your_s3_bucket_name',
            'Key' => $s3_key,
        ]);
    } catch (S3Exception $e) {
        // Handle any error that occurred during deletion
    }
}

// Add menu item in the WordPress admin dashboard
add_action('admin_menu', 's3_config_menu');

// Function to create the configuration page in the admin dashboard
function s3_config_menu() {
    add_options_page(
        'S3 Uploads Offloader Settings',
        'S3 Uploads Offloader',
        'manage_options',
        's3-uploads-offloader',
        's3_config_page'
    );
}
