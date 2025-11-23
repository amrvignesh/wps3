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
        
        // AJAX handlers with nonce verification
        add_action('wp_ajax_wps3_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_wps3_get_storage_usage', [$this, 'ajax_get_storage_usage']);
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

                    <!-- Settings Card -->
                    <div class="wps3-settings-card">
                        <div class="wps3-card-header">
                            <h2><span class="dashicons dashicons-admin-settings"></span> <?php _e('Plugin Configuration', 'wps3'); ?></h2>
                            <p class="wps3-card-description"><?php _e('Configure your S3 storage and plugin options.', 'wps3'); ?></p>
                        </div>
                        <div class="wps3-card-content">
                            <?php do_settings_sections('wps3'); ?>
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
                        <div class="wps3-status-item">
                            <span class="wps3-status-label"><?php _e('S3 Storage Used:', 'wps3'); ?></span>
                            <span class="wps3-status-value" id="wps3-storage-usage">
                                <span class="spinner" style="float: none; margin: 0;"></span>
                            </span>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Connection Test Button -->
                        <div class="wps3-status-item" style="border: none; padding-top: 15px;">
                            <button type="button" id="wps3-test-connection-btn" class="button button-secondary" style="width: 100%;">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <?php _e('Test S3 Connection', 'wps3'); ?>
                            </button>
                        </div>
                        <div id="wps3-connection-result" style="display: none; margin-top: 10px;"></div>
                    </div>
                </div>
                
                <!-- Help & Resources Card -->
                <div class="wps3-settings-card">
                    <div class="wps3-card-header">
                        <h2><span class="dashicons dashicons-editor-help"></span> <?php _e('Help & Resources', 'wps3'); ?></h2>
                    </div>
                    <div class="wps3-card-content">
                        <div class="wps3-help-section">
                            <h4><?php _e('Sample S3 URL Format', 'wps3'); ?></h4>
                            <div class="wps3-sample-url">
                                <p><strong><?php _e('For AWS S3:', 'wps3'); ?></strong></p>
                                <code>https://s3.us-west-2.amazonaws.com</code>
                                <p style="margin-top: 10px;"><strong><?php _e('For DigitalOcean Spaces:', 'wps3'); ?></strong></p>
                                <code>https://nyc3.digitaloceanspaces.com</code>
                                <p style="margin-top: 10px;"><strong><?php _e('For Custom S3:', 'wps3'); ?></strong></p>
                                <code>https://s3.example.com</code>
                            </div>
                        </div>
                        
                        <div class="wps3-help-section" style="margin-top: 15px;">
                            <h4><?php _e('Quick Links', 'wps3'); ?></h4>
                            <ul class="wps3-help-links">
                                <li>
                                    <a href="#" id="wps3-show-faq">
                                        <span class="dashicons dashicons-format-chat"></span>
                                        <?php _e('Frequently Asked Questions', 'wps3'); ?>
                                    </a>
                                </li>
                                <li>
                                    <a href="<?php echo admin_url('upload.php?page=wps3-migration'); ?>">
                                        <span class="dashicons dashicons-migrate"></span>
                                        <?php _e('Go to Migration Dashboard', 'wps3'); ?>
                                    </a>
                                </li>
                                <li>
                                    <a href="https://github.com/amrvignesh/wps3" target="_blank">
                                        <span class="dashicons dashicons-external"></span>
                                        <?php _e('Documentation & Troubleshooting', 'wps3'); ?>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <!-- FAQ Overlay (Hidden by default) -->
                <div id="wps3-faq-overlay" style="display: none;">
                    <div class="wps3-faq-modal">
                        <div class="wps3-faq-header">
                            <h2><?php _e('Frequently Asked Questions', 'wps3'); ?></h2>
                            <button type="button" id="wps3-close-faq" class="button">×</button>
                        </div>
                        <div class="wps3-faq-content">
                            <div class="wps3-faq-item">
                                <h3><?php _e('Will my existing media files be automatically uploaded to S3?', 'wps3'); ?></h3>
                                <p><?php _e('No. Only new files uploaded after enabling the plugin will go to S3. To migrate existing files, use the Migration Dashboard.', 'wps3'); ?></p>
                            </div>
                            
                            <div class="wps3-faq-item">
                                <h3><?php _e('What happens if I disable the plugin?', 'wps3'); ?></h3>
                                <p><?php _e('Your media files will remain on S3, but URLs will no longer be rewritten. You may experience broken images until you re-enable the plugin or migrate files back.', 'wps3'); ?></p>
                            </div>
                            
                            <div class="wps3-faq-item">
                                <h3><?php _e('Can I use a CDN with this plugin?', 'wps3'); ?></h3>
                                <p><?php _e('Yes! Enter your CDN domain in the "CDN Domain" field under Advanced Settings. All S3 URLs will be rewritten to use your CDN.', 'wps3'); ?></p>
                            </div>
                            
                            <div class="wps3-faq-item">
                                <h3><?php _e('Is it safe to enable "Delete Local Files"?', 'wps3'); ?></h3>
                                <p><?php _e('Only enable this after confirming your S3 connection works properly. Local files will be deleted immediately after successful S3 upload.', 'wps3'); ?></p>
                            </div>
                            
                            <div class="wps3-faq-item">
                                <h3><?php _e('How much will bandwidth savings cost me?', 'wps3'); ?></h3>
                                <p><?php _e('S3 typically costs $0.09/GB for bandwidth, compared to $0.12-0.40/GB for most hosting providers. Plus, S3 reduces server load significantly.', 'wps3'); ?></p>
                            </div>
                            
                            <div class="wps3-faq-item">
                                <h3><?php _e('Connection test fails - what should I check?', 'wps3'); ?></h3>
                                <p><?php _e('Common issues: (1) Incorrect access/secret keys, (2) Wrong bucket region, (3) Bucket permissions not set to allow your keys, (4) Endpoint URL typo.', 'wps3'); ?></p>
                            </div>
                        </div>
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
        
        <?php echo $this->get_additional_styles(); ?>
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
            
            // Connection Test Button Handler
            $('#wps3-test-connection-btn').on('click', function(e) {
                e.preventDefault();
                var btn = $(this);
                var originalHtml = btn.html();
                
                btn.prop('disabled', true).html('<span class=\"spinner is-active\" style=\"float:none; margin:0 5px 0 0;\"></span> Testing...');
                $('#wps3-connection-result').hide();
                
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'wps3_test_connection',
                        nonce: '" . esc_js(wp_create_nonce('wps3_test_connection')) . "',
                        bucket_name: $('input[name=\"wps3_bucket_name\"]').val(),
                        endpoint_url: $('input[name=\"wps3_s3_endpoint_url\"]').val(),
                        region: $('input[name=\"wps3_s3_region\"]').val(),
                        access_key: $('input[name=\"wps3_access_key\"]').val(),
                        secret_key: $('input[name=\"wps3_secret_key\"]').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#wps3-connection-result')
                                .removeClass('notice-error')
                                .addClass('notice notice-success')
                                .html('<p><strong>✅ ' + response.data.message + '</strong></p>')
                                .slideDown();
                        } else {
                            $('#wps3-connection-result')
                                .removeClass('notice-success')
                                .addClass('notice notice-error')
                                .html('<p><strong>❌ ' + (response.data.message || 'Connection failed') + '</strong></p>')
                                .slideDown();
                        }
                    },
                    error: function(xhr) {
                        $('#wps3-connection-result')
                            .removeClass('notice-success')
                            .addClass('notice notice-error')
                            .html('<p><strong>❌ Connection test failed. Please check your settings.</strong></p>')
                            .slideDown();
                    },
                    complete: function() {
                        btn.prop('disabled', false).html(originalHtml);
                    }
                });
            });
            
            
            // Load S3 Storage Usage
            function loadStorageUsage() {
                var storageElement = $('#wps3-storage-usage');
                if (storageElement.length && '" . esc_js(get_option('wps3_enabled')) . "' === '1') {
                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'wps3_get_storage_usage',
                            nonce: '" . esc_js(wp_create_nonce('wps3_nonce')) . "'
                        },
                        success: function(response) {
                            if (response.success && response.data) {
                                var estimated = response.data.estimated ? ' (estimated)' : '';
                                storageElement.html(response.data.total_size_formatted + estimated);
                            } else {
                                storageElement.html('" . esc_js(__('Unable to load', 'wps3')) . "');
                            }
                        },
                        error: function() {
                            storageElement.html('" . esc_js(__('Error loading', 'wps3')) . "');
                        }
                    });
                }
            }
            
            // Load storage usage on page load
            loadStorageUsage();
            
            // FAQ Modal Handlers
            $('#wps3-show-faq').on('click', function(e) {
                e.preventDefault();
                $('#wps3-faq-overlay').fadeIn(200);
            });
            
            $('#wps3-close-faq, #wps3-faq-overlay').on('click', function(e) {
                if (e.target === this) {
                    $('#wps3-faq-overlay').fadeOut(200);
                }
            });

        })(jQuery);
        ";
    }
    
    /**
     * Get additional CSS for new UI elements
     */
    private function get_additional_styles() {
        return "
        <style>
        .wps3-help-section h4 {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: #23282d;
        }
        
        .wps3-sample-url code {
            display: block;
            background: #f5f5f5;
            padding: 8px 12px;
            border-left: 3px solid #0073aa;
            font-size: 13px;
            color: #333;
        }
        
        .wps3-help-links {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        
        .wps3-help-links li {
            margin-bottom: 8px;
        }
        
        .wps3-help-links a {
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            color: #0073aa;
            padding: 8px;
            border-radius: 4px;
            transition: background 0.2s;
        }
        
        .wps3-help-links a:hover {
            background: #f0f0f0;
        }
        
        #wps3-faq-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 100000;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .wps3-faq-modal {
            background: white;
            padding: 0;
            border-radius: 8px;
            max-width: 800px;
            width: 90%;
            max-height: 80vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        .wps3-faq-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid #e1e1e1;
            background: #f8f9fa;
        }
        
        .wps3-faq-header h2 {
            margin: 0;
            font-size: 20px;
        }
        
        .wps3-faq-header button {
            font-size: 24px;
            line-height: 1;
            padding: 0 10px;
        }
        
        .wps3-faq-content {
            padding: 20px;
            overflow-y: auto;
        }
        
        .wps3-faq-item {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e1e1e1;
        }
        
        .wps3-faq-item:last-child {
            border-bottom: none;
        }
        
.wps3-faq-item h3 {
            margin: 0 0 10px 0;
            font-size: 16px;
            color: #0073aa;
        }
        
        .wps3-faq-item p {
            margin: 0;
            color: #666;
            line-height: 1.6;
        }
        
        #wps3-connection-result {
            padding: 10px;
            border-radius: 4px;
        }
        
        @media (max-width: 768px) {
            .wps3-faq-modal {
                width: 95%;
                max-height: 90vh;
            }
        }
        </style>
        ";
    }

    /**
     * AJAX handler for testing S3 connection.
     * Implements proper nonce verification and capability checks.
     */
    public function ajax_test_connection() {
        // Security checks
        check_ajax_referer('wps3_test_connection', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'wps3')], 403);
        }
        
        // Sanitize and validate input
        $bucket_name = isset($_POST['bucket_name']) ? sanitize_text_field($_POST['bucket_name']) : '';
        $endpoint_url = isset($_POST['endpoint_url']) ? esc_url_raw($_POST['endpoint_url']) : '';
        $region = isset($_POST['region']) ? sanitize_text_field($_POST['region']) : '';
        $access_key = isset($_POST['access_key']) ? sanitize_text_field($_POST['access_key']) : '';
        $secret_key = isset($_POST['secret_key']) ? sanitize_text_field($_POST['secret_key']) : '';
        
        // Validate required fields
        if (empty($bucket_name) || empty($endpoint_url) || empty($region) || empty($access_key) || empty($secret_key)) {
            wp_send_json_error(['message' => __('All fields are required for connection testing.', 'wps3')], 400);
        }
        
        // Validate bucket name format
        if (!preg_match('/^[a-z0-9.-]+$/', $bucket_name)) {
            wp_send_json_error(['message' => __('Invalid bucket name format. Use only lowercase letters, numbers, hyphens, and periods.', 'wps3')], 400);
        }
        
        // Validate endpoint URL
        if (!filter_var($endpoint_url, FILTER_VALIDATE_URL)) {
            wp_send_json_error(['message' => __('Invalid endpoint URL format.', 'wps3')], 400);
        }
        
        try {
            // Create temporary S3 client for testing
            require_once WPS3_PLUGIN_DIR . 'vendor/autoload.php';
            
            $config = [
                'version'     => 'latest',
                'region'      => $region,
                'endpoint'    => $endpoint_url,
                'credentials' => [
                    'key'    => $access_key,
                    'secret' => $secret_key,
                ],
                'use_path_style_endpoint' => true,
                'http' => [
                    'timeout' => 10,
                    'connect_timeout' => 5,
                ],
            ];
            
            $s3_client = new \Aws\S3\S3Client($config);
            
            // Test connection by checking if bucket exists
            $result = $s3_client->headBucket([
                'Bucket' => $bucket_name,
            ]);
            
            wp_send_json_success([
                'message' => __('Connection successful! Bucket is accessible.', 'wps3'),
                'bucket_region' => $result->get('@metadata')['headers']['x-amz-bucket-region'] ?? $region,
            ]);
            
        } catch (\Aws\S3\Exception\S3Exception $e) {
            $error_code = $e->getAwsErrorCode();
            $error_message = $e->getAwsErrorMessage();
            
            // Provide specific error messages
            if ($error_code === 'NoSuchBucket') {
                wp_send_json_error(['message' => __('Bucket does not exist. Please check the bucket name.', 'wps3')], 404);
            } elseif ($error_code === 'InvalidAccessKeyId') {
                wp_send_json_error(['message' => __('Invalid access key. Please check your credentials.', 'wps3')], 401);
            } elseif ($error_code === 'SignatureDoesNotMatch') {
                wp_send_json_error(['message' => __('Invalid secret key. Please check your credentials.', 'wps3')], 401);
            } elseif ($error_code === 'AccessDenied') {
                wp_send_json_error(['message' => __('Access denied. Check bucket permissions and credentials.', 'wps3')], 403);
            } else {
                wp_send_json_error(['message' => sprintf(__('S3 Error: %s', 'wps3'), $error_message)], 500);
            }
            
        } catch (\Exception $e) {
            wp_send_json_error(['message' => sprintf(__('Connection failed: %s', 'wps3'), $e->getMessage())], 500);
        }
    }
    
    /**
     * AJAX handler for getting S3 storage usage.
     * Implements proper nonce verification and capability checks.
     */
    public function ajax_get_storage_usage() {
        // Security checks
        check_ajax_referer('wps3_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'wps3')], 403);
        }
        
        // Check if S3 is configured
        if (!get_option('wps3_enabled') || empty(get_option('wps3_bucket_name'))) {
            wp_send_json_error(['message' => __('S3 is not configured.', 'wps3')], 400);
        }
        
        try {
            $wps3 = WPS3::get_instance();
            $s3_client_wrapper = $wps3->get_s3_client_wrapper();
            $s3_client = $s3_client_wrapper->get_s3_client();
            
            if (!$s3_client) {
                wp_send_json_error(['message' => __('S3 client not initialized.', 'wps3')], 500);
            }
            
            $bucket_name = $s3_client_wrapper->get_bucket_name();
            $bucket_folder = $s3_client_wrapper->get_bucket_folder();
            
            // Get list of objects to calculate usage
            $prefix = !empty($bucket_folder) ? trailingslashit($bucket_folder) : '';
            
            $total_size = 0;
            $total_objects = 0;
            $continuation_token = null;
            
            do {
                $params = [
                    'Bucket' => $bucket_name,
                    'Prefix' => $prefix,
                ];
                
                if ($continuation_token) {
                    $params['ContinuationToken'] = $continuation_token;
                }
                
                $result = $s3_client->listObjectsV2($params);
                
                if (isset($result['Contents'])) {
                    foreach ($result['Contents'] as $object) {
                        $total_size += $object['Size'];
                        $total_objects++;
                    }
                }
                
                $continuation_token = $result['IsTruncated'] ? $result['NextContinuationToken'] : null;
                
                // Limit to prevent timeout (max 1000 objects for initial load)
                if ($total_objects >= 1000) {
                    break;
                }
                
            } while ($continuation_token);
            
            wp_send_json_success([
                'total_size' => $total_size,
                'total_size_formatted' => size_format($total_size, 2),
                'total_objects' => $total_objects,
                'estimated' => $total_objects >= 1000,
            ]);
            
        } catch (\Exception $e) {
            wp_send_json_error(['message' => sprintf(__('Failed to get storage usage: %s', 'wps3'), $e->getMessage())], 500);
        }
    }
}
