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

        register_setting('wps3', 'wps3_enabled');
        register_setting('wps3', 'wps3_bucket_name');
        register_setting('wps3', 'wps3_bucket_folder');
        register_setting('wps3', 'wps3_s3_endpoint_url');
        register_setting('wps3', 'wps3_s3_region');
        register_setting('wps3', 'wps3_access_key');
        register_setting('wps3', 'wps3_secret_key');
        register_setting('wps3', 'wps3_cdn_domain', [$this, 'validate_cdn_domain']);
        register_setting('wps3', 'wps3_delete_local');
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
        ?>
        <div class="wrap">
            <h1><?php _e('S3 Uploads Offloader Settings', 'wps3'); ?></h1>
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
}
