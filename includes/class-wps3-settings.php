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
            'wps3_s3_path',
            __('S3 Path', 'wps3'),
            [$this, 'settings_field_s3_path_callback'],
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
        register_setting('wps3', 'wps3_s3_path', [$this, 'validate_s3_path']);
        register_setting('wps3', 'wps3_access_key');
        register_setting('wps3', 'wps3_secret_key');
        $this->maybe_migrate_old_settings();
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
     * Settings field S3 path callback.
     */
    public function settings_field_s3_path_callback() {
        ?>
        <input type="text" name="wps3_s3_path" value="<?php echo esc_attr(get_option('wps3_s3_path')); ?>" class="regular-text" placeholder="s3://your-bucket/optional-folder?region=your-region&amp;endpoint=your-endpoint" />
        <p class="description">
            <?php
            _e('Enter the S3 path in the format: <code>s3://bucket-name/folder-path?region=region-name&amp;endpoint=custom-endpoint</code>', 'wps3');
            echo '<br>';
            _e('For AWS S3: <code>s3://my-bucket/wp-uploads?region=us-west-2</code>', 'wps3');
            echo '<br>';
            _e('For custom S3 providers: <code>s3://my-bucket/wp-uploads?region=us-east-1&amp;endpoint=https://s3.example.com</code>', 'wps3');
            ?>
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
     * Validate S3 path.
     *
     * @param string $value
     * @return string
     */
    public function validate_s3_path($value) {
        if (empty($value)) {
            add_settings_error('wps3_s3_path', 'empty', __('Please enter the S3 Path.', 'wps3'));
            return '';
        }

        $parsed_url = parse_url($value);

        if (!isset($parsed_url['scheme']) || $parsed_url['scheme'] !== 's3') {
            add_settings_error('wps3_s3_path', 'invalid_scheme', __('The S3 Path must start with <code>s3://</code>.', 'wps3'));
        }

        if (!isset($parsed_url['host'])) {
            add_settings_error('wps3_s3_path', 'invalid_bucket', __('The S3 Path must include a bucket name.', 'wps3'));
        }

        if (isset($parsed_url['query'])) {
            parse_str($parsed_url['query'], $query_params);
            if (empty($query_params['region'])) {
                add_settings_error('wps3_s3_path', 'invalid_region', __('The S3 Path must include a <code>region</code> query parameter.', 'wps3'));
            }
            if (empty($query_params['endpoint'])) {
                add_settings_error('wps3_s3_path', 'invalid_endpoint', __('The S3 Path must include an <code>endpoint</code> query parameter.', 'wps3'));
            }
        } else {
            add_settings_error('wps3_s3_path', 'missing_query', __('The S3 Path must include <code>region</code> and <code>endpoint</code> query parameters.', 'wps3'));
        }

        return esc_url_raw($value);
    }

    /**
     * Maybe migrate old settings to the new S3 path format.
     */
    public function maybe_migrate_old_settings() {
        $s3_path = get_option('wps3_s3_path');
        if ($s3_path) {
            return;
        }

        $endpoint = get_option('wps3_s3_endpoint_url');
        $bucket = get_option('wps3_bucket_name');
        $folder = get_option('wps3_bucket_folder');
        $region = get_option('wps3_s3_region');

        if ($endpoint && $bucket && $region) {
            $path = sprintf('s3://%s', $bucket);
            if ($folder) {
                $path .= '/' . $folder;
            }
            $path .= '?' . http_build_query([
                'region' => $region,
                'endpoint' => $endpoint,
            ]);
            update_option('wps3_s3_path', $path);

            delete_option('wps3_s3_endpoint_url');
            delete_option('wps3_bucket_name');
            delete_option('wps3_bucket_folder');
            delete_option('wps3_s3_region');
        }
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
