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

// Function to render the configuration page HTML
function s3_config_page() {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1>S3 Uploads Offloader Settings</h1>
        <form method="post" action="options.php">
            <?php
                // Output the settings fields
                settings_fields('s3-uploads-offloader-settings');
                do_settings_sections('s3-uploads-offloader-settings');
                submit_button('Save Settings');
            ?>
        </form>
    </div>
    <?php
}

// Hook into WordPress admin init action to register and define the settings fields
add_action('admin_init', 's3_config_init');

// Function to register and define the settings fields
function s3_config_init() {
    // Register settings
    register_setting(
        's3-uploads-offloader-settings',
        's3-uploads-offloader-options',
        's3_validate_options'
    );

    // Add settings section
    add_settings_section(
        's3-uploads-offloader-section',
        'S3 Configuration',
        's3_config_section_callback',
        's3-uploads-offloader-settings'
    );

    // Add settings fields
    add_settings_field(
        's3-endpoint',
        'S3 Endpoint',
        's3_endpoint_field_callback',
        's3-uploads-offloader-settings',
        's3-uploads-offloader-section'
    );

    add_settings_field(
        's3-bucket',
        'S3 Bucket Name',
        's3_bucket_field_callback',
        's3-uploads-offloader-settings',
        's3-uploads-offloader-section'
    );

    add_settings_field(
        's3-access-key',
        'S3 Access Key',
        's3_access_key_field_callback',
        's3-uploads-offloader-settings',
        's3-uploads-offloader-section'
    );

    add_settings_field(
        's3-secret-key',
        'S3 Secret Key',
        's3_secret_key_field_callback',
        's3-uploads-offloader-settings',
        's3-uploads-offloader-section'
    );
}

// Callback function for the settings section
function s3_config_section_callback() {
    echo '<p>Configure the S3 settings below:</p>';
}

// Callback function for the S3 endpoint field
function s3_endpoint_field_callback() {
    $options = get_option('s3-uploads-offloader-options');
    $endpoint = isset($options['s3-endpoint']) ? esc_attr($options['s3-endpoint']) : '';
    echo '<input type="text" name="s3-uploads-offloader-options[s3-endpoint]" value="' . $endpoint . '" class="regular-text">';
}

// Callback function for the S3 bucket field
function s3_bucket_field_callback() {
    $options = get_option('s3-uploads-offloader-options');
    $bucket = isset($options['s3-bucket']) ? esc_attr($options['s3-bucket']) : '';
    echo '<input type="text" name="s3-uploads-offloader-options[s3-bucket]" value="' . $bucket . '" class="regular-text">';
}

// Callback function for the S3 access key field
function s3_access_key_field_callback() {
    $options = get_option('s3-uploads-offloader-options');
    $access_key = isset($options['s3-access-key']) ? esc_attr($options['s3-access-key']) : '';
    echo '<input type="text" name="s3-uploads-offloader-options[s3-access-key]" value="' . $access_key . '" class="regular-text">';
}

// Callback function for the S3 secret key field
function s3_secret_key_field_callback() {
    $options = get_option('s3-uploads-offloader-options');
    $secret_key = isset($options['s3-secret-key']) ? esc_attr($options['s3-secret-key']) : '';
    echo '<input type="text" name="s3-uploads-offloader-options[s3-secret-key]" value="' . $secret_key . '" class="regular-text">';
}

// Function to validate the S3 options
function s3_validate_options($input) {
    $validated = array();

    // Validate and sanitize endpoint URL
    if (isset($input['s3-endpoint'])) {
        $validated['s3-endpoint'] = sanitize_text_field($input['s3-endpoint']);
    }

    // Validate and sanitize bucket name
    if (isset($input['s3-bucket'])) {
        $validated['s3-bucket'] = sanitize_text_field($input['s3-bucket']);
    }

    // Validate and sanitize access key
    if (isset($input['s3-access-key'])) {
        $validated['s3-access-key'] = sanitize_text_field($input['s3-access-key']);
    }

    // Validate and sanitize secret key
    if (isset($input['s3-secret-key'])) {
        $validated['s3-secret-key'] = sanitize_text_field($input['s3-secret-key']);
    }

    return $validated;
}

// Function to offload uploads folder to S3
function s3_uploads_offloader($uploads)
{
    global $s3_client;

    // Get the saved configuration options
    $options = get_option('s3-uploads-offloader-options');
    $endpoint = isset($options['s3-endpoint']) ? $options['s3-endpoint'] : '';
    $bucket_name = isset($options['s3-bucket']) ? $options['s3-bucket'] : '';
    $access_key = isset($options['s3-access-key']) ? $options['s3-access-key'] : '';
    $secret_key = isset($options['s3-secret-key']) ? $options['s3-secret-key'] : '';

    // Initialize the S3 client with the saved options
    $s3_client = new S3Client([
        'version' => 'latest',
        'region' => 'your_s3_bucket_region',
        'credentials' => [
            'key' => $access_key,
            'secret' => $secret_key,
        ],
        'endpoint' => $endpoint,
        'use_path_style_endpoint' => true,
    ]);

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
    
    // Get the saved configuration options
    $options = get_option('s3-uploads-offloader-options');
    $endpoint = isset($options['s3-endpoint']) ? $options['s3-endpoint'] : '';
    $bucket_name = isset($options['s3-bucket']) ? $options['s3-bucket'] : '';
    $access_key = isset($options['s3-access-key']) ? $options['s3-access-key'] : '';
    $secret_key = isset($options['s3-secret-key']) ? $options['s3-secret-key'] : '';

    // Initialize the S3 client with the saved options
    $s3_client = new S3Client([
        'version' => 'latest',
        'region' => 'your_s3_bucket_region',
        'credentials' => [
            'key' => $access_key,
            'secret' => $secret_key,
        ],
        'endpoint' => $endpoint,
        'use_path_style_endpoint' => true,
    ]);

    // Get the attachment's S3 key
    $attachment_meta = wp_get_attachment_metadata($post_id);
    $s3_key = 'your_desired_folder_in_s3_bucket/' . $attachment_meta['file'];

    // Delete the file from S3 bucket
    try {
        $s3_client->deleteObject([
            'Bucket' => $bucket_name,
            'Key' => $s3_key,
        ]);
    } catch (S3Exception $e) {
        // Handle any error that occurred during deletion
    }
}
