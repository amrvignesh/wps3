<?php

/**
 * Class WPS3_Settings
 */
class WPS3_Settings {

    /**
     * WPS3_Settings constructor.
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    /**
     * Add admin menu.
     */
    public function add_admin_menu() {
        add_options_page(
            'S3 Uploads Offloader Settings',
            'S3 Uploads Offloader',
            'manage_options',
            'wps3-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Enqueue admin scripts and styles.
     */
    public function enqueue_admin_scripts($hook) {
        if ('settings_page_wps3-settings' !== $hook) {
            return;
        }

        wp_enqueue_script('jquery');

        // Add setup wizard functionality
        wp_add_inline_script('jquery', $this->get_setup_wizard_script());
    }

    /**
     * Register settings.
     */
    public function register_settings() {
        add_settings_section(
            'wps3_section',
            __('S3 Uploads Offloader', 'wps3'),
            [$this, 'settings_section_callback'],
            'wps3'
        );

        add_settings_field(
            'wps3_enabled',
            __('Enable S3 Uploads Offloader', 'wps3'),
            [$this, 'settings_field_enabled_callback'],
            'wps3',
            'wps3_section'
        );

        add_settings_field(
            'wps3_bucket_name',
            __('S3 Bucket Name', 'wps3'),
            [$this, 'settings_field_bucket_name_callback'],
            'wps3',
            'wps3_section'
        );

        add_settings_field(
            'wps3_bucket_folder',
            __('S3 Bucket Folder', 'wps3'),
            [$this, 'settings_field_bucket_folder_callback'],
            'wps3',
            'wps3_section'
        );

        add_settings_field(
            'wps3_s3_endpoint_url',
            __('S3 Endpoint URL', 'wps3'),
            [$this, 'settings_field_s3_endpoint_url_callback'],
            'wps3',
            'wps3_section'
        );

        add_settings_field(
            'wps3_s3_region',
            __('S3 Region', 'wps3'),
            [$this, 'settings_field_s3_region_callback'],
            'wps3',
            'wps3_section'
        );

        add_settings_field(
            'wps3_access_key',
            __('Access Key', 'wps3'),
            [$this, 'settings_field_access_key_callback'],
            'wps3',
            'wps3_section'
        );

        add_settings_field(
            'wps3_secret_key',
            __('Secret Key', 'wps3'),
            [$this, 'settings_field_secret_key_callback'],
            'wps3',
            'wps3_section'
        );

        add_settings_field(
            'wps3_cdn_domain',
            __('CDN Domain', 'wps3'),
            [$this, 'settings_field_cdn_domain_callback'],
            'wps3',
            'wps3_section'
        );

        add_settings_field(
            'wps3_delete_local',
            __('Delete Local Files', 'wps3'),
            [$this, 'settings_field_delete_local_callback'],
            'wps3',
            'wps3_section'
        );

        register_setting('wps3', 'wps3_enabled', ['sanitize_callback' => 'absint']);
        register_setting('wps3', 'wps3_bucket_name', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('wps3', 'wps3_bucket_folder', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('wps3', 'wps3_s3_endpoint_url', ['sanitize_callback' => 'esc_url_raw']);
        register_setting('wps3', 'wps3_s3_region', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('wps3', 'wps3_access_key', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('wps3', 'wps3_secret_key', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('wps3', 'wps3_cdn_domain', ['sanitize_callback' => [$this, 'validate_cdn_domain']]);
        register_setting('wps3', 'wps3_delete_local', ['sanitize_callback' => 'absint']);
    }

    /**
     * Settings section callback.
     */
    public function settings_section_callback() {
        ?>
        <p>
            <?php _e('This plugin allows you to offload all WordPress uploads to an S3-compatible storage service.', 'wps3'); ?>
        </p>
        <?php
    }

    /**
     * Settings field enabled callback.
     */
    public function settings_field_enabled_callback() {
        ?>
        <input type="checkbox" name="wps3_enabled" value="1" <?php checked(1, get_option('wps3_enabled'), true); ?> />
        <p class="description">
            <?php _e('Enable offloading uploads to S3.', 'wps3'); ?>
        </p>
        <?php
    }

    /**
     * Settings field delete local callback.
     */
    public function settings_field_delete_local_callback() {
        ?>
        <input type="checkbox" name="wps3_delete_local" value="1" <?php checked(1, get_option('wps3_delete_local'), true); ?> />
        <p class="description">
            <?php _e('Delete local files after they have been successfully uploaded to S3.', 'wps3'); ?>
            <strong><?php _e('Warning: Make sure your S3 configuration is working correctly before enabling this option.', 'wps3'); ?></strong>
        </p>
        <?php
    }

    /**
     * Settings field S3 bucket name callback.
     */
    public function settings_field_bucket_name_callback() {
        ?>
        <input type="text" name="wps3_bucket_name" value="<?php echo esc_attr(get_option('wps3_bucket_name')); ?>" class="regular-text" placeholder="your-bucket-name" />
        <p class="description">
            <?php _e('Enter your S3 bucket name.', 'wps3'); ?>
        </p>
        <?php
    }

    /**
     * Settings field S3 bucket folder callback.
     */
    public function settings_field_bucket_folder_callback() {
        ?>
        <input type="text" name="wps3_bucket_folder" value="<?php echo esc_attr(get_option('wps3_bucket_folder')); ?>" class="regular-text" placeholder="optional-folder" />
        <p class="description">
            <?php _e('Enter an optional folder name to store your files in.', 'wps3'); ?>
        </p>
        <?php
    }

    /**
     * Settings field S3 endpoint URL callback.
     */
    public function settings_field_s3_endpoint_url_callback() {
        ?>
        <input type="text" name="wps3_s3_endpoint_url" value="<?php echo esc_attr(get_option('wps3_s3_endpoint_url')); ?>" class="regular-text" placeholder="https://s3.example.com" />
        <p class="description">
            <?php _e('Enter the full S3 endpoint URL, including the protocol (e.g., <code>https://s3.example.com</code>).', 'wps3'); ?>
        </p>
        <?php
    }

    /**
     * Settings field S3 region callback.
     */
    public function settings_field_s3_region_callback() {
        ?>
        <input type="text" name="wps3_s3_region" value="<?php echo esc_attr(get_option('wps3_s3_region')); ?>" class="regular-text" placeholder="us-east-1" />
        <p class="description">
            <?php _e('Enter the S3 region.', 'wps3'); ?>
        </p>
        <?php
    }

    /**
     * Settings field access key callback.
     */
    public function settings_field_access_key_callback() {
        ?>
        <input type="text" name="wps3_access_key" value="<?php echo esc_attr(get_option('wps3_access_key')); ?>" class="regular-text" />
        <p class="description">
            <?php _e('The access key for your S3 service.', 'wps3'); ?>
        </p>
        <?php
    }

    /**
     * Settings field secret key callback.
     */
    public function settings_field_secret_key_callback() {
        ?>
        <input type="password" name="wps3_secret_key" value="<?php echo esc_attr(get_option('wps3_secret_key')); ?>" class="regular-text" />
        <p class="description">
            <?php _e('The secret key for your S3 service.', 'wps3'); ?>
        </p>
        <?php
    }

    /**
     * Settings field CDN domain callback.
     */
    public function settings_field_cdn_domain_callback() {
        ?>
        <input type="text" name="wps3_cdn_domain" value="<?php echo esc_attr(get_option('wps3_cdn_domain')); ?>" class="regular-text" placeholder="e.g., cdn.example.com" />
        <p class="description">
            <?php _e('Enter your CDN domain name if you are using a CDN to serve your S3 files. Leave blank if not using a CDN.', 'wps3'); ?>
        </p>
        <?php
    }


    /**
     * Validate CDN domain.
     *
     * @param string $value
     * @return string
     */
    public function validate_cdn_domain($value) {
        if (!empty($value)) {
            if (!filter_var('https://' . $value, FILTER_VALIDATE_URL)) {
                add_settings_error('wps3_cdn_domain', 'invalid', __('The CDN domain is not a valid domain name.', 'wps3'));
            }
        }
        return sanitize_text_field($value);
    }

    /**
     * Render settings page.
     */
    public function render_settings_page() {
        // Check if settings are properly configured
        $is_configured = get_option('wps3_enabled') &&
                        !empty(get_option('wps3_bucket_name')) &&
                        !empty(get_option('wps3_s3_endpoint_url')) &&
                        !empty(get_option('wps3_access_key'));

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(__('S3 Uploads Offloader Settings', 'wps3')); ?></h1>

            <?php if (!$is_configured): ?>
            <div class="notice notice-info">
                <p><strong><?php _e('Welcome to WPS3!', 'wps3'); ?></strong> <?php _e('Configure your S3 settings below to start offloading your media files.', 'wps3'); ?></p>
            </div>
            <?php endif; ?>

            <div class="wps3-settings-container">
                <form method="post" action="options.php" class="wps3-settings-form">
                    <?php settings_fields('wps3'); ?>

                    <!-- Basic Settings Card -->
                    <div class="wps3-settings-card">
                        <div class="wps3-card-header">
                            <h2><span class="dashicons dashicons-admin-generic"></span> <?php _e('Basic Settings', 'wps3'); ?></h2>
                            <p class="wps3-card-description"><?php _e('Enable the plugin and configure basic options.', 'wps3'); ?></p>
                        </div>
                        <div class="wps3-card-content">
                            <?php do_settings_sections('wps3'); ?>
                        </div>
                    </div>

                    <!-- S3 Configuration Card -->
                    <div class="wps3-settings-card">
                        <div class="wps3-card-header">
                            <h2><span class="dashicons dashicons-cloud"></span> <?php _e('S3 Configuration', 'wps3'); ?></h2>
                            <p class="wps3-card-description"><?php _e('Configure your S3-compatible storage settings.', 'wps3'); ?></p>
                        </div>
                        <div class="wps3-card-content">
                            <div class="wps3-form-row">
                                <div class="wps3-form-group">
                                    <?php $this->settings_field_bucket_name_callback(); ?>
                                </div>
                                <div class="wps3-form-group">
                                    <?php $this->settings_field_bucket_folder_callback(); ?>
                                </div>
                            </div>
                            <div class="wps3-form-row">
                                <div class="wps3-form-group">
                                    <?php $this->settings_field_s3_endpoint_url_callback(); ?>
                                </div>
                                <div class="wps3-form-group">
                                    <?php $this->settings_field_s3_region_callback(); ?>
                                </div>
                            </div>
                            <div class="wps3-form-row">
                                <div class="wps3-form-group">
                                    <?php $this->settings_field_access_key_callback(); ?>
                                </div>
                                <div class="wps3-form-group">
                                    <?php $this->settings_field_secret_key_callback(); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Advanced Settings Card -->
                    <div class="wps3-settings-card">
                        <div class="wps3-card-header">
                            <h2><span class="dashicons dashicons-admin-settings"></span> <?php _e('Advanced Settings', 'wps3'); ?></h2>
                            <p class="wps3-card-description"><?php _e('Optional settings for CDN and file management.', 'wps3'); ?></p>
                        </div>
                        <div class="wps3-card-content">
                            <div class="wps3-form-row">
                                <div class="wps3-form-group">
                                    <?php $this->settings_field_cdn_domain_callback(); ?>
                                </div>
                                <div class="wps3-form-group">
                                    <?php $this->settings_field_delete_local_callback(); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php submit_button(__('Save Settings', 'wps3'), 'primary wps3-save-button'); ?>
                </form>

                <!-- Status Card -->
                <div class="wps3-settings-card wps3-status-card">
                    <div class="wps3-card-header">
                        <h2><span class="dashicons dashicons-info"></span> <?php _e('Connection Status', 'wps3'); ?></h2>
                    </div>
                    <div class="wps3-card-content">
                        <div class="wps3-status-item">
                            <span class="wps3-status-label"><?php _e('Plugin Status:', 'wps3'); ?></span>
                            <span class="wps3-status-value <?php echo get_option('wps3_enabled') ? 'enabled' : 'disabled'; ?>">
                                <?php echo get_option('wps3_enabled') ? '✅ ' . __('Enabled', 'wps3') : '❌ ' . __('Disabled', 'wps3'); ?>
                            </span>
                        </div>
                        <div class="wps3-status-item">
                            <span class="wps3-status-label"><?php _e('S3 Connection:', 'wps3'); ?></span>
                            <span class="wps3-status-value <?php echo $is_configured ? 'enabled' : 'disabled'; ?>">
                                <?php echo $is_configured ? '✅ ' . __('Configured', 'wps3') : '❌ ' . __('Not Configured', 'wps3'); ?>
                            </span>
                        </div>
                        <?php if ($is_configured): ?>
                        <div class="wps3-status-item">
                            <span class="wps3-status-label"><?php _e('Bucket:', 'wps3'); ?></span>
                            <span class="wps3-status-value"><?php echo esc_html(get_option('wps3_bucket_name')); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Analytics Card -->
                <?php if ($is_configured): ?>
                <div class="wps3-settings-card wps3-analytics-card">
                    <div class="wps3-card-header">
                        <h2><span class="dashicons dashicons-chart-bar"></span> <?php _e('Quick Stats', 'wps3'); ?></h2>
                    </div>
                    <div class="wps3-card-content">
                        <?php
                        $wps3 = WPS3::get_instance();
                        $analytics = $wps3->get_analytics();
                        ?>
                        <div class="wps3-analytics-grid">
                            <div class="wps3-analytics-item">
                                <div class="wps3-analytics-number"><?php echo number_format($analytics['total_attachments']); ?></div>
                                <div class="wps3-analytics-label"><?php _e('Total Files', 'wps3'); ?></div>
                            </div>
                            <div class="wps3-analytics-item">
                                <div class="wps3-analytics-number"><?php echo number_format($analytics['migrated_attachments']); ?></div>
                                <div class="wps3-analytics-label"><?php _e('On S3', 'wps3'); ?></div>
                            </div>
                            <div class="wps3-analytics-item">
                                <div class="wps3-analytics-number"><?php echo number_format($analytics['pending_attachments']); ?></div>
                                <div class="wps3-analytics-label"><?php _e('Pending', 'wps3'); ?></div>
                            </div>
                            <div class="wps3-analytics-item">
                                <div class="wps3-analytics-number">
                                    <?php
                                    $savings = $analytics['estimated_monthly_savings'];
                                    if ($savings > 1) {
                                        echo '$' . number_format($savings, 2);
                                    } else {
                                        echo '$' . number_format($savings * 1000, 0) . 'K';
                                    }
                                    ?>
                                </div>
                                <div class="wps3-analytics-label"><?php _e('Monthly Savings', 'wps3'); ?></div>
                            </div>
                        </div>
                        <div class="wps3-analytics-footer">
                            <a href="<?php echo admin_url('upload.php?page=wps3-migration'); ?>" class="wps3-analytics-link">
                                <span class="dashicons dashicons-migrate"></span>
                                <?php _e('View Migration Dashboard', 'wps3'); ?>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <style>
        .wps3-settings-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }

        .wps3-settings-form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .wps3-settings-card {
            background: #fff;
            border: 1px solid #e1e1e1;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .wps3-card-header {
            padding: 20px;
            border-bottom: 1px solid #e1e1e1;
            background: #f8f9fa;
        }

        .wps3-card-header h2 {
            margin: 0 0 5px 0;
            font-size: 18px;
            color: #23282d;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .wps3-card-header .dashicons {
            color: #0073aa;
        }

        .wps3-card-description {
            margin: 0;
            color: #666;
            font-size: 14px;
        }

        .wps3-card-content {
            padding: 20px;
        }

        .wps3-form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 15px;
        }

        .wps3-form-group {
            display: flex;
            flex-direction: column;
        }

        .wps3-form-group input[type="text"],
        .wps3-form-group input[type="password"] {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .wps3-form-group input[type="checkbox"] {
            margin-right: 8px;
        }

        .wps3-form-group .description {
            font-size: 13px;
            color: #666;
            margin-top: 5px;
        }

        .wps3-save-button {
            background: #0073aa;
            border-color: #0073aa;
            color: white;
            padding: 12px 24px;
            font-size: 16px;
            border-radius: 6px;
            cursor: pointer;
            align-self: flex-start;
        }

        .wps3-save-button:hover {
            background: #005a87;
            border-color: #005a87;
        }

        .wps3-status-card {
            position: sticky;
            top: 20px;
            height: fit-content;
        }

        .wps3-status-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }

        .wps3-status-item:last-child {
            border-bottom: none;
        }

        .wps3-status-label {
            font-weight: 600;
            color: #23282d;
        }

        .wps3-status-value {
            font-weight: 500;
        }

        .wps3-status-value.enabled {
            color: #28a745;
        }

        .wps3-status-value.disabled {
            color: #dc3545;
        }

        .wps3-analytics-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .wps3-analytics-card .wps3-card-header {
            background: rgba(255,255,255,0.1);
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }

        .wps3-analytics-card .wps3-card-header h2 {
            color: white;
        }

        .wps3-analytics-card .wps3-card-header .dashicons {
            color: white;
        }

        .wps3-analytics-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        .wps3-analytics-item {
            text-align: center;
            padding: 15px;
            background: rgba(255,255,255,0.1);
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.2);
        }

        .wps3-analytics-number {
            font-size: 24px;
            font-weight: bold;
            color: white;
            display: block;
            margin-bottom: 5px;
        }

        .wps3-analytics-label {
            font-size: 12px;
            color: rgba(255,255,255,0.9);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .wps3-analytics-footer {
            text-align: center;
            padding-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.2);
        }

        .wps3-analytics-link {
            color: white;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 8px 12px;
            background: rgba(255,255,255,0.2);
            border-radius: 4px;
            transition: background 0.2s;
        }

        .wps3-analytics-link:hover {
            background: rgba(255,255,255,0.3);
            color: white;
            text-decoration: none;
        }

        .wps3-analytics-link .dashicons {
            font-size: 14px;
        }

        @media (max-width: 768px) {
            .wps3-settings-container {
                grid-template-columns: 1fr;
            }

            .wps3-form-row {
                grid-template-columns: 1fr;
            }

            .wps3-analytics-grid {
                grid-template-columns: 1fr;
            }
        }
        </style>
        <?php
    }

    /**
     * Get setup wizard JavaScript.
     */
    private function get_setup_wizard_script() {
        return "
        (function($) {
            'use strict';

            $(document).ready(function() {
                // Add helpful tooltips and validation
                addSetupHelpers();
                addConnectionTesting();
            });

            function addSetupHelpers() {
                // Add provider-specific help
                $('#wps3_s3_endpoint_url').on('input', function() {
                    var url = $(this).val();
                    if (url.includes('digitalocean')) {
                        showProviderHelp('DigitalOcean Spaces', 'us-east-1');
                    } else if (url.includes('amazonaws.com')) {
                        showProviderHelp('Amazon S3', 'us-east-1');
                    } else if (url.includes('minio')) {
                        showProviderHelp('MinIO', 'us-east-1');
                    } else {
                        hideProviderHelp();
                    }
                });

                // Auto-fill region based on endpoint
                $('#wps3_s3_endpoint_url').on('blur', function() {
                    var url = $(this).val();
                    if (url.includes('us-east-1')) {
                        $('#wps3_s3_region').val('us-east-1');
                    } else if (url.includes('us-west-2')) {
                        $('#wps3_s3_region').val('us-west-2');
                    }
                });
            }

            function showProviderHelp(provider, defaultRegion) {
                var helpHtml = '<div class=\"wps3-provider-help\" style=\"background: #e8f4f8; padding: 10px; margin: 5px 0; border-left: 4px solid #0073aa; border-radius: 4px;\">' +
                    '<strong>📡 ' + provider + ' detected!</strong><br>' +
                    '<small>Region automatically set to: <code>' + defaultRegion + '</code></small>' +
                    '</div>';
                $('#wps3_s3_endpoint_url').after(helpHtml);
            }

            function hideProviderHelp() {
                $('.wps3-provider-help').remove();
            }

            function addConnectionTesting() {
                // Add test connection button
                var testButton = '<button type=\"button\" id=\"wps3-test-connection\" class=\"button\" style=\"margin-left: 10px;\">Test Connection</button>';
                $('#wps3_access_key').after(testButton);

                $('#wps3-test-connection').on('click', function() {
                    var button = $(this);
                    var originalText = button.text();

                    button.prop('disabled', true).text('Testing...');

                    // Get and validate input data
                    var bucketName = $('#wps3_bucket_name').val();
                    var endpointUrl = $('#wps3_s3_endpoint_url').val();
                    var region = $('#wps3_s3_region').val();
                    var accessKey = $('#wps3_access_key').val();
                    var secretKey = $('#wps3_secret_key').val();

                    // Client-side validation
                    if (!bucketName || !endpointUrl || !region || !accessKey || !secretKey) {
                        showTestResult('❌ Please fill in all required fields.', 'error');
                        button.prop('disabled', false).text(originalText);
                        return;
                    }

                    if (!endpointUrl.match(/^https?:\/\/.+/)) {
                        showTestResult('❌ Endpoint URL must start with http:// or https://', 'error');
                        button.prop('disabled', false).text(originalText);
                        return;
                    }

                    if (!bucketName.match(/^[a-z0-9.-]+$/)) {
                        showTestResult('❌ Bucket name can only contain lowercase letters, numbers, hyphens, and periods.', 'error');
                        button.prop('disabled', false).text(originalText);
                        return;
                    }

                    var testData = {
                        action: 'wps3_test_connection',
                        nonce: '" . esc_js(wp_create_nonce('wps3_test_connection')) . "',
                        bucket_name: bucketName,
                        endpoint_url: endpointUrl,
                        region: region,
                        access_key: accessKey,
                        secret_key: secretKey
                    };

                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: testData,
                        success: function(response) {
                            if (response.success) {
                                showTestResult('✅ Connection successful!', 'success');
                            } else {
                                showTestResult('❌ Connection failed: ' + (response.data || 'Unknown error'), 'error');
                            }
                        },
                        error: function(xhr, status, error) {
                            showTestResult('❌ Connection test failed. Please check your settings.', 'error');
                        },
                        complete: function() {
                            button.prop('disabled', false).text(originalText);
                        }
                    });
                });
            }

            function showTestResult(message, type) {
                var resultHtml = '<div class=\"wps3-test-result notice notice-' + type + ' is-dismissible\" style=\"margin: 10px 0;\">' +
                    '<p>' + message + '</p>' +
                    '<button type=\"button\" class=\"notice-dismiss\"></button>' +
                    '</div>';
                $('#wps3-test-connection').after(resultHtml);

                // Auto-dismiss after 5 seconds
                setTimeout(function() {
                    $('.wps3-test-result').fadeOut();
                }, 5000);
            }

            // Add real-time validation
            $('input[name*=\"wps3\"]').on('blur', function() {
                validateField($(this));
            });

            function validateField(field) {
                var value = field.val();
                var name = field.attr('name');

                // Remove previous validation errors
                field.removeClass('wps3-field-error');
                $('.wps3-field-error').remove();

                // Basic validation
                if (name === 'wps3_bucket_name' && value && !/^[a-z0-9.-]+$/.test(value)) {
                    showFieldError(field, 'Bucket name can only contain lowercase letters, numbers, hyphens, and periods.');
                } else if (name === 'wps3_s3_endpoint_url' && value && !value.startsWith('http')) {
                    showFieldError(field, 'Endpoint URL must start with http:// or https://');
                } else if (name === 'wps3_access_key' && value && value.length < 16) {
                    showFieldError(field, 'Access key seems too short. Please check your credentials.');
                }
            }

            function showFieldError(field, message) {
                field.addClass('wps3-field-error');
                field.after('<div class=\"wps3-field-error\" style=\"color: #dc3545; font-size: 13px; margin-top: 5px;\">' + message + '</div>');
            }

        })(jQuery);
        ";
    }

    // Template for secure custom admin actions (for future use):
    /*
    public function handle_custom_action() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'wps3'));
        }
        check_admin_referer('wps3_custom_action'); // Nonce check
        // ... handle action ...
    }
    */
}
