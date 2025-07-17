<?php
/**
 * WPS3 Developer Examples
 * 
 * This file contains examples of how to use the WPS3 hooks and filters.
 * Do not include this file in your theme or plugin - these are just examples.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Example 1: Log before file upload
 */
add_action('wps3_before_upload', function($file_path, $s3_key, $file_data) {
    error_log("About to upload: $file_path to S3 key: $s3_key");
});

/**
 * Example 2: Log after successful upload
 */
add_action('wps3_after_upload', function($file_path, $s3_key, $s3_url, $file_data) {
    error_log("Successfully uploaded: $file_path to $s3_url");
    
    // You could send a notification, update a database, etc.
    // wp_mail('admin@example.com', 'File Uploaded', "File uploaded to: $s3_url");
});

/**
 * Example 3: Modify upload options (e.g., change ACL or add metadata)
 */
add_filter('wps3_upload_options', function($options, $file_path, $s3_key, $file_data) {
    // Make files private instead of public-read
    $options['ACL'] = 'private';
    
    // Add custom metadata
    $options['Metadata'] = [
        'uploaded-by' => 'wps3-plugin',
        'upload-time' => date('Y-m-d H:i:s'),
        'file-size' => filesize($file_path)
    ];
    
    // Add cache control for images
    if (strpos($options['ContentType'], 'image/') === 0) {
        $options['CacheControl'] = 'max-age=31536000'; // 1 year
    }
    
    return $options;
}, 10, 4);

/**
 * Example 4: Modify file URLs (e.g., add query parameters or use signed URLs)
 */
add_filter('wps3_file_url', function($url, $attachment_id, $s3_info, $original_url) {
    // Add a version parameter to bust cache
    $url = add_query_arg('v', get_post_modified_time('U', false, $attachment_id), $url);
    
    // Or for private files, you could generate signed URLs
    // (This is just an example - you'd need to implement the signing logic)
    // if (is_user_logged_in()) {
    //     $url = generate_signed_url($url, '+1 hour');
    // }
    
    return $url;
}, 10, 4);

/**
 * Example 5: Handle thumbnail uploads differently
 */
add_action('wps3_before_upload', function($file_path, $s3_key, $file_data) {
    // Check if this is a thumbnail upload
    if (isset($file_data['size']) && isset($file_data['attachment_id'])) {
        $size_name = $file_data['size'];
        $attachment_id = $file_data['attachment_id'];
        
        error_log("Uploading thumbnail '$size_name' for attachment $attachment_id");
        
        // You could modify thumbnail handling here
        // For example, skip certain thumbnail sizes, apply different compression, etc.
    }
});

/**
 * Example 6: Custom error handling
 */
add_action('wps3_before_upload', function($file_path, $s3_key, $file_data) {
    // Validate file before upload
    if (filesize($file_path) > 10 * 1024 * 1024) { // 10MB limit
        error_log("File too large: $file_path");
        // You could prevent upload by throwing an exception or modifying the file
    }
});

/**
 * Example 7: Integration with other plugins
 */
add_action('wps3_after_upload', function($file_path, $s3_key, $s3_url, $file_data) {
    // Trigger other plugin actions
    do_action('my_plugin_file_uploaded', $s3_url, $file_data);
    
    // Update custom database tables
    global $wpdb;
    $wpdb->insert('my_uploaded_files', [
        'file_path' => $file_path,
        's3_key' => $s3_key,
        's3_url' => $s3_url,
        'upload_time' => current_time('mysql')
    ]);
});

/**
 * Example 8: Conditional URL modification based on user role
 */
add_filter('wps3_file_url', function($url, $attachment_id, $s3_info, $original_url) {
    // Only modify URLs for logged-in users
    if (is_user_logged_in()) {
        // Add user-specific parameters
        $user_id = get_current_user_id();
        $url = add_query_arg('user', $user_id, $url);
    }
    
    return $url;
}, 10, 4); 