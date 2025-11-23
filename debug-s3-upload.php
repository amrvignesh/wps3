<?php
/**
 * WPS3 Debug Helper
 * Place this file in wp-content/plugins/wps3/ and access it via:
 * wp-admin/admin.php?page=wps3-debug
 */

// Hook into admin menu
add_action('admin_menu', 'wps3_debug_menu');

function wps3_debug_menu() {
    add_submenu_page(
        'tools.php',
        'WPS3 Debug',
        'WPS3 Debug',
        'manage_options',
        'wps3-debug',
        'wps3_debug_page'
    );
}

function wps3_debug_page() {
    ?>
    <div class="wrap">
        <h1>WPS3 Debug Information</h1>
        
        <?php
        // Check if plugin is enabled
        $enabled = get_option('wps3_enabled');
        $bucket_name = get_option('wps3_bucket_name');
        $endpoint_url = get_option('wps3_s3_endpoint_url');
        $region = get_option('wps3_s3_region');
        $access_key = get_option('wps3_access_key');
        $secret_key = get_option('wps3_secret_key');
        
        echo '<h2>Configuration Status</h2>';
        echo '<table class="widefat">';
        echo '<tr><td><strong>Plugin Enabled:</strong></td><td>' . ($enabled ? '✅ Yes' : '❌ No' ) . '</td></tr>';
        echo '<tr><td><strong>Bucket Name:</strong></td><td>' . ($bucket_name ? '✅ ' . esc_html($bucket_name) : '❌ Not set') . '</td></tr>';
        echo '<tr><td><strong>Endpoint URL:</strong></td><td>' . ($endpoint_url ? '✅ ' . esc_html($endpoint_url) : '❌ Not set') . '</td></tr>';
        echo '<tr><td><strong>Region:</strong></td><td>' . ($region ? '✅ ' . esc_html($region) : '❌ Not set') . '</td></tr>';
        echo '<tr><td><strong>Access Key:</strong></td><td>' . ($access_key ? '✅ Set (' . strlen($access_key) . ' chars)' : '❌ Not set') . '</td></tr>';
        echo '<tr><td><strong>Secret Key:</strong></td><td>' . ($secret_key ? '✅ Set (' . strlen($secret_key) . ' chars)' : '❌ Not set') . '</td></tr>';
        echo '</table>';
        
        // Check S3 Client
        echo '<h2>S3 Client Status</h2>';
        if (class_exists('WPS3')) {
            $wps3 = WPS3::get_instance();
            $s3_client_wrapper = $wps3->get_s3_client_wrapper();
            $s3_client = $s3_client_wrapper->get_s3_client();
            
            if ($s3_client) {
                echo '<p style="color: green;"><strong>✅ S3 Client initialized successfully</strong></p>';
            } else {
                echo '<p style="color: red;"><strong>❌ S3 Client NOT initialized</strong></p>';
                echo '<p>Possible reasons:</p>';
                echo '<ul>';
                if (!$enabled) echo '<li>Plugin is not enabled</li>';
                if (!$bucket_name) echo '<li>Bucket name is missing</li>';
                if (!$endpoint_url) echo '<li>Endpoint URL is missing</li>';
                if (!$region) echo '<li>Region is missing</li>';
                if (!$access_key) echo '<li>Access key is missing</li>';
                if (!$secret_key) echo '<li>Secret key is missing</li>';
                echo '</ul>';
            }
        } else {
            echo '<p style="color: red;"><strong>❌ WPS3 class not found</strong></p>';
        }
        
        // Check hooks
        echo '<h2>Hook Status</h2>';
        $upload_override_filter = has_filter('wp_handle_upload', 'upload_overrides');
        echo '<table class="widefat">';
        echo '<tr><td><strong>wp_handle_upload filter:</strong></td><td>' . ($upload_override_filter !== false ? '✅ Registered (priority: ' . $upload_override_filter . ')' : '❌ Not registered') . '</td></tr>';
        echo '</table>';
        
        // Test S3 connection
        echo '<h2>Test S3 Connection</h2>';
        if (isset($_POST['test_connection'])) {
            check_admin_referer('wps3_debug_test');
            
            try {
                require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
                
                $test_client = new \Aws\S3\S3Client([
                    'version' => 'latest',
                    'region' => $region,
                    'endpoint' => $endpoint_url,
                    'credentials' => [
                        'key' => $access_key,
                        'secret' => $secret_key,
                    ],
                    'use_path_style_endpoint' => true,
                ]);
                
                $result = $test_client->headBucket(['Bucket' => $bucket_name]);
                echo '<p style="color: green;"><strong>✅ Connection successful!</strong></p>';
            } catch (\Exception $e) {
               echo '<p style="color: red;"><strong>❌ Connection failed:</strong> ' . esc_html($e->getMessage()) . '</p>';
            }
        }
        
        echo '<form method="post">';
        wp_nonce_field('wps3_debug_test');
        echo '<button type="submit" name="test_connection" class="button button-primary">Test S3 Connection Now</button>';
        echo '</form>';
        
        // Check recent uploads
        echo '<h2>Recent Upload Attempts</h2>';
        echo '<p>Check your debug.log file for WPS3 log entries. Enable WP_DEBUG in wp-config.php to see detailed logs.</p>';
        echo '<p><code>define(\'WP_DEBUG\', true);</code></p>';
        echo '<p><code>define(\'WP_DEBUG_LOG\', true);</code></p>'; 
        ?>
    </div>
    <?php
}
