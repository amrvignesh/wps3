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
            'wps3_s3_endpoint_url',
            __('S3 Endpoint URL', 'wps3'),
            [$this, 'settings_field_s3_endpoint_url_callback'],
            'wps3',
            'wps3_section'
        );

        add_settings_field(
            'wps3_bucket_name',
            __('Bucket Name', 'wps3'),
            [$this, 'settings_field_bucket_name_callback'],
            'wps3',
            'wps3_section'
        );

        add_settings_field(
            'wps3_bucket_folder',
            __('Bucket Folder (Optional)', 'wps3'),
            [$this, 'settings_field_bucket_folder_callback'],
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
        register_setting('wps3', 'wps3_s3_endpoint_url', [$this, 'validate_s3_endpoint_url']);
        register_setting('wps3', 'wps3_bucket_name', [$this, 'validate_bucket_name']);
        register_setting('wps3', 'wps3_bucket_folder', [$this, 'validate_bucket_folder']);
        register_setting('wps3', 'wps3_s3_region', [$this, 'validate_s3_region']);
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
     * Settings field S3 endpoint URL callback.
     */
    public function settings_field_s3_endpoint_url_callback() {
        ?>
        <input type="text" name="wps3_s3_endpoint_url" value="<?php echo esc_attr(get_option('wps3_s3_endpoint_url')); ?>" class="regular-text" placeholder="e.g., https://s3.example.com" />
        <p class="description">
            <?php _e('Enter the S3 endpoint URL. For AWS S3, this might be like <code>https://s3.your-region.amazonaws.com</code>. For other services, refer to their documentation (e.g., <code>https://us-central-1.telnyxstorage.com</code>).', 'wps3'); ?>
        </p>
        <?php
    }

    /**
     * Settings field bucket name callback.
     */
    public function settings_field_bucket_name_callback() {
        ?>
        <input type="text" name="wps3_bucket_name" value="<?php echo esc_attr(get_option('wps3_bucket_name')); ?>" class="regular-text" placeholder="e.g., my-wordpress-bucket" />
        <p class="description">
            <?php _e('Enter your S3 bucket name.', 'wps3'); ?>
        </p>
        <?php
    }

    /**
     * Settings field bucket folder callback.
     */
    public function settings_field_bucket_folder_callback() {
        ?>
        <input type="text" name="wps3_bucket_folder" value="<?php echo esc_attr(get_option('wps3_bucket_folder')); ?>" class="regular-text" placeholder="e.g., wp-content/uploads" />
        <p class="description">
            <?php _e('Optional. Enter a folder path within your bucket to store uploads. Leave blank to use the bucket root.', 'wps3'); ?>
        </p>
        <?php
    }

    /**
     * Settings field S3 region callback.
     */
    public function settings_field_s3_region_callback() {
        ?>
        <input type="text" name="wps3_s3_region" value="<?php echo esc_attr(get_option('wps3_s3_region')); ?>" class="regular-text" placeholder="e.g., us-west-2" />
        <p class="description">
            <?php _e('Enter the S3 region for your bucket (e.g., <code>us-west-2</code>, <code>eu-central-1</code>). This is required by some S3-compatible providers.', 'wps3'); ?>
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
     * Validate S3 endpoint URL.
     *
     * @param string $value
     * @return string
     */
    public function validate_s3_endpoint_url($value) {
        if (empty($value)) {
            add_settings_error('wps3_s3_endpoint_url', 'empty', __('Please enter the S3 Endpoint URL.', 'wps3'));
            return '';
        }
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            add_settings_error('wps3_s3_endpoint_url', 'invalid', __('The S3 Endpoint URL is not a valid URL.', 'wps3'));
            return esc_url_raw($value);
        }
        return esc_url_raw(rtrim($value, '/'));
    }

    /**
     * Validate bucket name.
     *
     * @param string $value
     * @return string
     */
    public function validate_bucket_name($value) {
        if (empty($value)) {
            add_settings_error('wps3_bucket_name', 'empty', __('Please enter the Bucket Name.', 'wps3'));
            return '';
        }
        if (!preg_match('/^(?=.{3,63}$)[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $value)) {
            add_settings_error('wps3_bucket_name', 'invalid', __('The Bucket Name is not valid. It should be 3-63 characters, lowercase letters, numbers, dots, and hyphens.', 'wps3'));
        }
        return sanitize_text_field($value);
    }

    /**
     * Validate bucket folder.
     *
     * @param string $value
     * @return string
     */
    public function validate_bucket_folder($value) {
        if (!empty($value)) {
            return trim(sanitize_text_field($value), '/');
        }
        return '';
    }

    /**
     * Validate S3 region.
     *
     * @param string $value
     * @return string
     */
    public function validate_s3_region($value) {
        if (empty($value)) {
            add_settings_error('wps3_s3_region', 'empty', __('Please enter the S3 Region.', 'wps3'));
            return '';
        }
        if (!preg_match('/^[a-z0-9-]+$/', $value)) {
            add_settings_error('wps3_s3_region', 'invalid', __('The S3 Region is not valid (e.g., us-west-2).', 'wps3'));
        }
        return sanitize_text_field($value);
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
