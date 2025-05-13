<?php
/*
Plugin Name: WPS3
Plugin URI: https://github.com/amrvignesh/wps3
Description: Offload WordPress uploads directory to S3 compatible storage
Version: 0.2
Requires at least: 5.0
Requires PHP: 7.0
Author: Vignesh AMR
Author URI: https://gigillion.com/wps3
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: wps3
Domain Path: /languages
*/

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

// Define plugin constants
define('WPS3_VERSION', '0.2');
define('WPS3_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPS3_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPS3_PLUGIN_FILE', __FILE__);

require_once 'aws/aws-autoloader.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

/**
 * The S3 Uploads Offloader class.
 */
class WPS3
{
	/**
	 * The S3 client.
	 *
	 * @var \Aws\S3\S3Client
	 */
	protected $s3_client;

	/**
	 * The S3 bucket name.
	 *
	 * @var string
	 */
	protected $bucket_name;

	/**
	 * The S3 bucket region.
	 *
	 * @var string
	 */
	protected $bucket_region;

	/**
	 * The S3 bucket folder.
	 *
	 * @var string
	 */
	protected $bucket_folder;

	/**
	 * The S3 endpoint URL.
	 *
	 * @var string
	 */
	protected $endpoint;

	/**
	 * The batch size for file migration
	 * 
	 * @var int
	 */
	protected $batch_size = 5;

	/**
	 * The constructor.
	 */
	public function __construct()
	{
		// Parse the S3 path into components
		$this->parse_s3_path(get_option('wps3_s3_path'));

		// Initialize S3 client if we have the necessary configuration
		if (!empty($this->bucket_region)) {
			$config = [
				'version' => 'latest',
				'region' => $this->bucket_region,
				'credentials' => [
					'key' => get_option('wps3_access_key'),
					'secret' => get_option('wps3_secret_key')
				]
			];

			// Add custom endpoint if provided
			if (!empty($this->endpoint)) {
				$config['endpoint'] = $this->endpoint;
			}

			try {
				$this->s3_client = new \Aws\S3\S3Client($config);
			} catch (\Exception $e) {
				// Log the error but don't crash
				error_log('WPS3: Error initializing S3 client: ' . $e->getMessage());
			}
		}
	}

	/**
	 * Parse the S3 path into components.
	 * 
	 * @param string $s3_path The S3 path in the format 's3://bucket-name/folder-path?region=region-name&endpoint=custom-endpoint'
	 * @return void
	 */
	protected function parse_s3_path($s3_path)
	{
		if (empty($s3_path)) {
			return;
		}

		// Remove the s3:// prefix if present
		$s3_path = preg_replace('/^s3:\/\//', '', $s3_path);

		// Extract query parameters if they exist
		$parts = explode('?', $s3_path, 2);
		$path = $parts[0];
		$query = isset($parts[1]) ? $parts[1] : '';

		// Parse the path to get bucket name and folder
		$path_parts = explode('/', $path, 2);
		$this->bucket_name = $path_parts[0];
		$this->bucket_folder = isset($path_parts[1]) ? $path_parts[1] : '';

		// Parse query parameters
		$params = [];
		if (!empty($query)) {
			parse_str($query, $params);
		}

		// Extract region and endpoint from query parameters
		$this->bucket_region = isset($params['region']) ? sanitize_text_field($params['region']) : '';
		$this->endpoint = isset($params['endpoint']) ? esc_url_raw($params['endpoint']) : '';
	}

	/**
	 * Register the plugin's hooks.
	 *
	 * @return void
	 */
	public function register_hooks()
	{
		// Actions
		add_action('wp_loaded', [$this, 'init']);
		add_action('wp_insert_attachment', [$this, 'upload_attachment']);
		add_action('delete_attachment', [$this, 'delete_attachment']);
		add_action('admin_menu', [$this, 'add_admin_menu']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
		add_action('plugins_loaded', [$this, 'load_textdomain']);
		
		// AJAX handlers
		add_action('wp_ajax_wps3_start_migration', [$this, 'ajax_start_migration']);
		add_action('wp_ajax_wps3_process_batch', [$this, 'ajax_process_batch']);
		add_action('wp_ajax_wps3_get_migration_status', [$this, 'ajax_get_migration_status']);
		add_action('wp_ajax_wps3_update_option', [$this, 'ajax_update_option']);
		
		// Filters for rewriting URLs
		add_filter('wp_get_attachment_url', [$this, 'rewrite_attachment_url'], 10, 2);
		add_filter('image_downsize', [$this, 'rewrite_image_downsize'], 10, 3);
		add_filter('wp_handle_upload', [$this, 'upload_overrides']);
	}

	/**
	 * Load plugin text domain.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain('wps3', false, dirname(plugin_basename(WPS3_PLUGIN_FILE)) . '/languages');
	}

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 */
	public function init()
	{
		// Check if the plugin is enabled.
		if (!get_option('wps3_enabled')) {
			return;
		}

		// Check if S3 client is properly initialized
		if (empty($this->s3_client)) {
			return;
		}

		// Check if the S3 bucket exists.
		try {
			if (!$this->s3_client->doesBucketExist($this->bucket_name)) {
				add_action('admin_notices', function() {
					echo '<div class="error"><p>';
					echo esc_html__('WPS3: The S3 bucket does not exist. Please check your settings.', 'wps3');
					echo '</p></div>';
				});
				return;
			}
		} catch (\Exception $e) {
			error_log('WPS3: Error checking bucket existence: ' . $e->getMessage());
			return;
		}
	}

	/**
	 * Move existing files to the S3 bucket.
	 */
	protected function move_existing_files()
	{
		$uploads_dir = wp_upload_dir();
		$files = glob($uploads_dir['path'] . '/*');

		foreach ($files as $file) {
			$this->upload_file($file);
		}
	}

	/**
	 * Upload a file to the S3 bucket.
	 *
	 * @param string $file The path to the file.
	 * @return bool Whether the upload was successful.
	 */
	protected function upload_file($file)
	{
		if (!file_exists($file)) {
			return false;
		}

		try {
			$key = $this->bucket_folder . '/' . basename($file);
			$this->s3_client->putObject([
				'Bucket' => $this->bucket_name,
				'Key' => $key,
				'Body' => file_get_contents($file),
				'ACL' => 'public-read',
			]);

			// Delete the local file if that option is enabled
			if (get_option('wps3_delete_local')) {
				@unlink($file);
			}

			return true;
		} catch (\Exception $e) {
			error_log('S3 upload error: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Override the WordPress upload process to upload files to the S3 bucket.
	 */
	public function upload_overrides($file)
	{
		// Check if the plugin is enabled.
		if (!get_option('wps3_enabled')) {
			return $file;
		}

		// Upload the file to S3
		$this->upload_file($file['file']);
		
		// Generate the S3 URL for the file
		$upload_dir = wp_upload_dir();
		$s3_url = '';
		
		// If we have a custom endpoint, use it for the URL
		if (!empty($this->endpoint)) {
			$s3_url = $this->endpoint . '/' . $this->bucket_name . '/' . $this->bucket_folder . '/' . basename($file['file']);
		} else {
			// Standard AWS S3 URL format
			$s3_url = 'https://' . $this->bucket_name . '.s3.' . $this->bucket_region . '.amazonaws.com/' . $this->bucket_folder . '/' . basename($file['file']);
		}
		
		// Update the file info with S3 data
		$file['url'] = $s3_url;
		$file['s3_info'] = array(
			'bucket' => $this->bucket_name,
			'key' => $this->bucket_folder . '/' . basename($file['file']),
			'url' => $s3_url,
		);

		return $file;
	}

	/**
	 * Delete a file from the S3 bucket.
	 *
	 * @param int $attachment_id The ID of the attachment.
	 */
	public function delete_attachment($attachment_id)
	{
		$attachment = get_post_meta($attachment_id, '_wp_attached_file', true);

		if (!empty($attachment)) {
			$this->s3_client->deleteObject([
				'Bucket' => $this->bucket_name,
				'Key' => $this->bucket_folder . '/' . basename($attachment),
			]);
		}
	}

	/**
	 * Add an admin menu for the plugin.
	 */
	public function add_admin_menu()
	{
		add_options_page(
			'S3 Uploads Offloader Settings',
			'S3 Uploads Offloader',
			'manage_options',
			'wps3-settings',
			[$this, 'render_settings_page']
		);
		
		add_media_page(
			'S3 Migration',
			'S3 Migration',
			'manage_options',
			'wps3-migration',
			[$this, 'render_migration_page']
		);
	}

	/**
	 * Get the MIME type of a file.
	 *
	 * @param string $file_path The path to the file.
	 * @return string The MIME type of the file.
	 */
	private function get_mime_type($file_path)
	{
		$mime_type = wp_check_filetype($file_path);

		if (!empty($mime_type['type'])) {
			return $mime_type['type'];
		}

		return 'application/octet-stream';
	}

	/**
	 * Register the plugin's settings.
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
			__('S3 Storage Path', 'wps3'),
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
			'wps3_delete_local',
			__('Delete Local Files', 'wps3'),
			[$this, 'settings_field_delete_local_callback'],
			'wps3',
			'wps3_section'
		);

		register_setting(
			'wps3',
			'wps3_enabled'
		);

		register_setting(
			'wps3',
			'wps3_s3_path',
			[$this, 'validate_s3_path']
		);

		register_setting(
			'wps3',
			'wps3_access_key'
		);

		register_setting(
			'wps3',
			'wps3_secret_key'
		);

		register_setting(
			'wps3',
			'wps3_delete_local'
		);
	}

	/**
	 * Render the settings section.
	 */
	public function settings_section_callback() {
		?>
		<p>
			<?php _e('This plugin allows you to offload all WordPress uploads to an S3-compatible storage service.', 'wps3'); ?>
		</p>
		<?php
	}

	/**
	 * Render the enabled settings field.
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
	 * Render the delete local files settings field.
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
	 * Render the S3 path settings field.
	 */
	public function settings_field_s3_path_callback() {
		?>
		<input type="text" name="wps3_s3_path" value="<?php echo esc_attr(get_option('wps3_s3_path')); ?>" class="regular-text" />
		<p class="description">
			<?php _e('Enter the S3 path in the format: <code>s3://bucket-name/folder-path?region=region-name&endpoint=custom-endpoint</code>', 'wps3'); ?>
			<br>
			<?php _e('Example for AWS S3: <code>s3://my-bucket/wp-uploads?region=us-west-2</code>', 'wps3'); ?>
			<br>
			<?php _e('Example for custom S3 provider: <code>s3://my-bucket/wp-uploads?region=us-east-1&endpoint=https://s3.example.com</code>', 'wps3'); ?>
		</p>
		<?php
	}

	/**
	 * Render the access key settings field.
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
	 * Render the secret key settings field.
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
	 * Validate the S3 path.
	 *
	 * @param string $value The S3 path value.
	 * @return string The validated S3 path.
	 */
	public function validate_s3_path($value) {
		if (empty($value)) {
			add_settings_error(
				'wps3_s3_path',
				'empty',
				__('Please enter a value for the S3 Storage Path.', 'wps3')
			);
			return '';
		}

		// Basic validation that the path contains at least a bucket name
		if (!preg_match('/^s3:\/\/[a-zA-Z0-9.-]+/', $value)) {
			add_settings_error(
				'wps3_s3_path',
				'invalid',
				__('The S3 Storage Path should start with s3:// followed by a bucket name.', 'wps3')
			);
		}

		return $value;
	}

	/**
	 * Render the settings page.
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

	/**
	 * Enqueue admin scripts.
	 */
	public function enqueue_admin_scripts($hook)
	{
		if ($hook !== 'media_page_wps3-migration') {
			return;
		}
		
		// Ensure directories exist
		$this->ensure_asset_directories();
		
		// Create JS file if it doesn't exist
		$js_file = plugin_dir_path(__FILE__) . 'js/wps3-admin.js';
		if (!file_exists($js_file)) {
			$this->create_default_js_file();
		}
		
		// Create CSS file if it doesn't exist
		$css_file = plugin_dir_path(__FILE__) . 'css/wps3-admin.css';
		if (!file_exists($css_file)) {
			$this->create_default_css_file();
		}
		
		wp_enqueue_script(
			'wps3-admin-js',
			plugin_dir_url(__FILE__) . 'js/wps3-admin.js',
			['jquery'],
			'1.0.0',
			true
		);
		
		wp_localize_script(
			'wps3-admin-js',
			'wps3_ajax',
			[
				'ajax_url' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('wps3_ajax_nonce'),
			]
		);
		
		wp_enqueue_style(
			'wps3-admin-css',
			plugin_dir_url(__FILE__) . 'css/wps3-admin.css',
			[],
			'1.0.0'
		);
	}
	
	/**
	 * Ensure asset directories exist.
	 */
	private function ensure_asset_directories()
	{
		// Create JS directory if it doesn't exist
		$js_dir = plugin_dir_path(__FILE__) . 'js';
		if (!file_exists($js_dir)) {
			wp_mkdir_p($js_dir);
		}
		
		// Create CSS directory if it doesn't exist
		$css_dir = plugin_dir_path(__FILE__) . 'css';
		if (!file_exists($css_dir)) {
			wp_mkdir_p($css_dir);
		}
	}
	
	/**
	 * Create default JS file.
	 */
	private function create_default_js_file()
	{
		$js_file = plugin_dir_path(__FILE__) . 'js/wps3-admin.js';
		$js_content = <<<'JS'
jQuery(document).ready(function($) {
    const STATUS_CHECK_INTERVAL = 5000; // 5 seconds
    let statusCheckTimer = null;
    let processingBatch = false;
    
    // DOM Elements
    const $startButton = $('#wps3-start-migration');
    const $pauseButton = $('#wps3-pause-migration');
    const $resetButton = $('#wps3-reset-migration');
    const $progressBar = $('.wps3-progress-bar');
    const $progressText = $('.wps3-progress-bar span');
    const $logContainer = $('#wps3-log-container');
    const $migrationStats = $('.wps3-migration-stats p');
    
    /**
     * Log a message to the migration log
     */
    function logMessage(message, type = 'info') {
        const timestamp = new Date().toLocaleTimeString();
        const $entry = $('<div>', {
            class: 'wps3-log-entry wps3-log-' + type,
            text: `[${timestamp}] ${message}`
        });
        
        $logContainer.prepend($entry);
    }
    
    /**
     * Update the progress bar
     */
    function updateProgress(percent, migratedFiles, totalFiles) {
        $progressBar.css('width', percent + '%');
        $progressText.text(percent + '%');
        $migrationStats.text(`Progress: ${migratedFiles} of ${totalFiles} files migrated`);
    }
    
    /**
     * Start the migration process
     */
    function startMigration(reset = false) {
        $.ajax({
            url: wps3_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wps3_start_migration',
                nonce: wps3_ajax.nonce,
                reset: reset ? 'true' : 'false'
            },
            beforeSend: function() {
                $startButton.prop('disabled', true);
                logMessage('Starting migration...');
            },
            success: function(response) {
                if (response.success) {
                    logMessage(response.data.message);
                    $startButton.hide();
                    $pauseButton.show();
                    
                    // Process the first batch
                    processBatch();
                    
                    // Start status check timer
                    startStatusCheck();
                } else {
                    logMessage('Error: ' + response.data.message, 'error');
                    $startButton.prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                logMessage('AJAX Error: ' + error, 'error');
                $startButton.prop('disabled', false);
            }
        });
    }
    
    /**
     * Pause the migration process
     */
    function pauseMigration() {
        stopStatusCheck();
        processingBatch = false;
        
        $.ajax({
            url: wps3_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wps3_get_migration_status',
                nonce: wps3_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.migration_running) {
                        // Update option via AJAX
                        $.post(wps3_ajax.ajax_url, {
                            action: 'wps3_update_option',
                            nonce: wps3_ajax.nonce,
                            option_name: 'wps3_migration_running',
                            option_value: false
                        });
                    }
                    
                    $pauseButton.hide();
                    $startButton.show().prop('disabled', false);
                    logMessage('Migration paused');
                }
            }
        });
    }
    
    /**
     * Reset the migration
     */
    function resetMigration() {
        if (confirm('Are you sure you want to reset the migration progress? This will start over from the beginning.')) {
            pauseMigration();
            startMigration(true);
        }
    }
    
    /**
     * Process a batch of files
     */
    function processBatch() {
        if (processingBatch) return;
        
        processingBatch = true;
        
        $.ajax({
            url: wps3_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wps3_process_batch',
                nonce: wps3_ajax.nonce
            },
            success: function(response) {
                processingBatch = false;
                
                if (response.success) {
                    // Update progress
                    updateProgress(
                        response.data.percent_complete,
                        response.data.migrated_files,
                        response.data.total_files
                    );
                    
                    // Log the result
                    logMessage(response.data.message);
                    
                    // Log any errors
                    if (response.data.error_count > 0) {
                        response.data.errors.forEach(function(error) {
                            logMessage(`Failed to migrate ${error.path}: ${error.error}`, 'error');
                        });
                    }
                    
                    // If migration is complete, update UI
                    if (response.data.complete) {
                        logMessage('Migration complete!', 'success');
                        $pauseButton.hide();
                        $startButton.show().prop('disabled', false);
                        stopStatusCheck();
                    } else {
                        // Process the next batch
                        setTimeout(processBatch, 1000);
                    }
                } else {
                    logMessage('Error: ' + response.data.message, 'error');
                    $pauseButton.hide();
                    $startButton.show().prop('disabled', false);
                    stopStatusCheck();
                }
            },
            error: function(xhr, status, error) {
                processingBatch = false;
                logMessage('AJAX Error: ' + error, 'error');
                // Retry after a delay
                setTimeout(processBatch, 5000);
            }
        });
    }
    
    /**
     * Start the status check timer
     */
    function startStatusCheck() {
        statusCheckTimer = setInterval(checkStatus, STATUS_CHECK_INTERVAL);
    }
    
    /**
     * Stop the status check timer
     */
    function stopStatusCheck() {
        if (statusCheckTimer) {
            clearInterval(statusCheckTimer);
            statusCheckTimer = null;
        }
    }
    
    /**
     * Check the migration status
     */
    function checkStatus() {
        $.ajax({
            url: wps3_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wps3_get_migration_status',
                nonce: wps3_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateProgress(
                        response.data.percent_complete,
                        response.data.migrated_files,
                        response.data.total_files
                    );
                    
                    // If migration is no longer running, update UI
                    if (!response.data.migration_running && statusCheckTimer) {
                        $pauseButton.hide();
                        $startButton.show().prop('disabled', false);
                        stopStatusCheck();
                    }
                }
            }
        });
    }
    
    // Event Handlers
    $startButton.on('click', function() {
        startMigration();
    });
    
    $pauseButton.on('click', function() {
        pauseMigration();
    });
    
    $resetButton.on('click', function() {
        resetMigration();
    });
    
    // Check initial status on page load
    checkStatus();
});
JS;
		file_put_contents($js_file, $js_content);
	}
	
	/**
	 * Create default CSS file.
	 */
	private function create_default_css_file()
	{
		$css_file = plugin_dir_path(__FILE__) . 'css/wps3-admin.css';
		$css_content = <<<'CSS'
.wps3-migration-status {
    margin-top: 20px;
    max-width: 800px;
}

.wps3-progress-bar-container {
    width: 100%;
    background-color: #e0e0e0;
    border-radius: 4px;
    margin-bottom: 20px;
    overflow: hidden;
}

.wps3-progress-bar {
    height: 30px;
    background-color: #2271b1;
    text-align: center;
    color: white;
    line-height: 30px;
    transition: width 0.3s ease;
    min-width: 20px;
}

.wps3-migration-controls {
    margin: 20px 0;
}

.wps3-migration-log {
    margin-top: 30px;
    border: 1px solid #ddd;
    padding: 10px;
    max-height: 300px;
    overflow-y: auto;
    background-color: #f9f9f9;
}

#wps3-log-container {
    font-family: monospace;
    white-space: pre-wrap;
}

.wps3-log-entry {
    margin-bottom: 5px;
    padding: 5px;
    border-bottom: 1px solid #eee;
}

.wps3-log-success {
    color: #008000;
}

.wps3-log-error {
    color: #ff0000;
}

.wps3-log-info {
    color: #0073aa;
}

.wps3-migration-stats {
    font-size: 14px;
    font-weight: bold;
}
CSS;
		file_put_contents($css_file, $css_content);
	}

	/**
	 * Render the migration page.
	 */
	public function render_migration_page()
	{
		// Get migration status
		$total_files = $this->count_files_to_migrate();
		$migrated_files = get_option('wps3_migrated_files', 0);
		$migration_running = get_option('wps3_migration_running', false);
		$percent_complete = $total_files > 0 ? round(($migrated_files / $total_files) * 100) : 0;
		
		?>
		<div class="wrap">
			<h1><?php _e('S3 Migration Status', 'wps3'); ?></h1>
			
			<div class="wps3-migration-status">
				<div class="wps3-progress-bar-container">
					<div class="wps3-progress-bar" style="width: <?php echo esc_attr($percent_complete); ?>%;">
						<span><?php echo esc_html($percent_complete); ?>%</span>
					</div>
				</div>
				
				<div class="wps3-migration-stats">
					<p>
						<?php printf(
							__('Progress: %1$d of %2$d files migrated', 'wps3'),
							$migrated_files,
							$total_files
						); ?>
					</p>
				</div>
				
				<div class="wps3-migration-controls">
					<?php if ($migration_running): ?>
						<button type="button" id="wps3-pause-migration" class="button button-primary">
							<?php _e('Pause Migration', 'wps3'); ?>
						</button>
					<?php else: ?>
						<button type="button" id="wps3-start-migration" class="button button-primary">
							<?php _e('Start Migration', 'wps3'); ?>
						</button>
					<?php endif; ?>
					
					<button type="button" id="wps3-reset-migration" class="button">
						<?php _e('Reset Migration', 'wps3'); ?>
					</button>
				</div>
				
				<div class="wps3-migration-log">
					<h3><?php _e('Migration Log', 'wps3'); ?></h3>
					<div id="wps3-log-container"></div>
				</div>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Count the number of files to migrate.
	 * 
	 * @return int The number of files to migrate.
	 */
	protected function count_files_to_migrate()
	{
		$uploads_dir = wp_upload_dir();
		$files = $this->get_all_files_recursive($uploads_dir['basedir']);
		return count($files);
	}
	
	/**
	 * Get all files in a directory recursively.
	 * 
	 * @param string $dir The directory to search.
	 * @return array The list of file paths.
	 */
	protected function get_all_files_recursive($dir)
	{
		$files = [];
		$dir_iterator = new RecursiveDirectoryIterator($dir);
		$iterator = new RecursiveIteratorIterator($dir_iterator, RecursiveIteratorIterator::SELF_FIRST);
		
		foreach ($iterator as $file) {
			if ($file->isFile()) {
				$files[] = $file->getPathname();
			}
		}
		
		return $files;
	}
	
	/**
	 * AJAX handler for starting migration.
	 *
	 * @return void
	 */
	public function ajax_start_migration()
	{
		// Verify nonce and user permissions
		if (!check_ajax_referer('wps3_ajax_nonce', 'nonce', false) || !current_user_can('manage_options')) {
			wp_send_json_error([
				'message' => __('Security check failed or insufficient permissions.', 'wps3')
			]);
			return;
		}
		
		// Reset migration status if requested
		$reset = isset($_POST['reset']) ? sanitize_text_field($_POST['reset']) : 'false';
		if ($reset === 'true') {
			update_option('wps3_migrated_files', 0);
			update_option('wps3_migration_file_list', []);
		}
		
		// Get list of files to migrate
		$uploads_dir = wp_upload_dir();
		$all_files = $this->get_all_files_recursive($uploads_dir['basedir']);
		
		// Store the file list for later use
		update_option('wps3_migration_file_list', $all_files);
		update_option('wps3_migration_running', true);
		
		wp_send_json_success([
			'total_files' => count($all_files),
			'migrated_files' => get_option('wps3_migrated_files', 0),
			'message' => sprintf(
				/* translators: %d: number of files */
				_n('Starting migration of %d file', 'Starting migration of %d files', count($all_files), 'wps3'),
				count($all_files)
			)
		]);
	}
	
	/**
	 * AJAX handler for processing a batch of files.
	 *
	 * @return void
	 */
	public function ajax_process_batch()
	{
		// Verify nonce and user permissions
		if (!check_ajax_referer('wps3_ajax_nonce', 'nonce', false) || !current_user_can('manage_options')) {
			wp_send_json_error([
				'message' => __('Security check failed or insufficient permissions.', 'wps3')
			]);
			return;
		}
		
		// Check if migration is running
		if (!get_option('wps3_migration_running', false)) {
			wp_send_json_error([
				'message' => __('Migration is not running.', 'wps3')
			]);
			return;
		}
		
		// Get the file list
		$all_files = get_option('wps3_migration_file_list', []);
		$migrated_files = absint(get_option('wps3_migrated_files', 0));
		
		// Get batch of files to process
		$batch = array_slice($all_files, $migrated_files, $this->batch_size);
		
		if (empty($batch)) {
			// Migration complete
			update_option('wps3_migration_running', false);
			wp_send_json_success([
				'complete' => true,
				'total_files' => count($all_files),
				'migrated_files' => $migrated_files,
				'message' => __('Migration complete!', 'wps3')
			]);
			return;
		}
		
		// Process the batch
		$success_count = 0;
		$error_files = [];
		
		foreach ($batch as $file_path) {
			try {
				$result = $this->upload_file($file_path);
				if ($result) {
					$success_count++;
				} else {
					$error_files[] = [
						'path' => $file_path,
						'error' => __('Upload failed', 'wps3')
					];
				}
			} catch (\Exception $e) {
				$error_files[] = [
					'path' => $file_path,
					'error' => $e->getMessage()
				];
			}
		}
		
		// Update progress
		$new_migrated_count = $migrated_files + $success_count;
		update_option('wps3_migrated_files', $new_migrated_count);
		
		wp_send_json_success([
			'complete' => false,
			'total_files' => count($all_files),
			'migrated_files' => $new_migrated_count,
			'percent_complete' => round(($new_migrated_count / count($all_files)) * 100),
			'success_count' => $success_count,
			'error_count' => count($error_files),
			'errors' => $error_files,
			'message' => sprintf(
				/* translators: %1$d: total files processed, %2$d: successful uploads, %3$d: failed uploads */
				__('Processed %1$d files (%2$d successful, %3$d failed)', 'wps3'), 
				count($batch), 
				$success_count, 
				count($error_files)
			)
		]);
	}
	
	/**
	 * AJAX handler for getting migration status.
	 *
	 * @return void
	 */
	public function ajax_get_migration_status()
	{
		// Verify nonce and user permissions
		if (!check_ajax_referer('wps3_ajax_nonce', 'nonce', false) || !current_user_can('manage_options')) {
			wp_send_json_error([
				'message' => __('Security check failed or insufficient permissions.', 'wps3')
			]);
			return;
		}
		
		$all_files = get_option('wps3_migration_file_list', []);
		$migrated_files = absint(get_option('wps3_migrated_files', 0));
		$migration_running = (bool) get_option('wps3_migration_running', false);
		$total_files = count($all_files);
		
		wp_send_json_success([
			'total_files' => $total_files,
			'migrated_files' => $migrated_files,
			'percent_complete' => $total_files > 0 ? round(($migrated_files / $total_files) * 100) : 0,
			'migration_running' => $migration_running
		]);
	}
	
	/**
	 * AJAX handler for updating options.
	 *
	 * @return void
	 */
	public function ajax_update_option()
	{
		// Verify nonce and user permissions
		if (!check_ajax_referer('wps3_ajax_nonce', 'nonce', false) || !current_user_can('manage_options')) {
			wp_send_json_error([
				'message' => __('Security check failed or insufficient permissions.', 'wps3')
			]);
			return;
		}
		
		$option_name = isset($_POST['option_name']) ? sanitize_key($_POST['option_name']) : '';
		$option_value = isset($_POST['option_value']) ? sanitize_text_field($_POST['option_value']) : '';
		
		// Only allow updating specific options related to migration
		$allowed_options = [
			'wps3_migration_running',
			'wps3_migrated_files',
		];
		
		if (!in_array($option_name, $allowed_options, true)) {
			wp_send_json_error([
				'message' => __('Option not allowed.', 'wps3')
			]);
			return;
		}
		
		// For boolean values
		if ($option_value === 'true') {
			$option_value = true;
		} elseif ($option_value === 'false') {
			$option_value = false;
		}
		
		$result = update_option($option_name, $option_value);
		
		if ($result) {
			wp_send_json_success([
				'message' => sprintf(
					/* translators: %s: option name */
					__('Option %s updated.', 'wps3'),
					$option_name
				)
			]);
		} else {
			wp_send_json_error([
				'message' => sprintf(
					/* translators: %s: option name */
					__('Failed to update option %s.', 'wps3'),
					$option_name
				)
			]);
		}
	}

	/**
	 * Plugin activation hook.
	 *
	 * @return void
	 */
	public static function activate() {
		// Add default options
		add_option('wps3_enabled', false);
		add_option('wps3_delete_local', false);
		add_option('wps3_s3_path', '');
		add_option('wps3_access_key', '');
		add_option('wps3_secret_key', '');
		add_option('wps3_migrated_files', 0);
		add_option('wps3_migration_running', false);
		add_option('wps3_migration_file_list', []);
	}

	/**
	 * Plugin deactivation hook.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Reset migration status
		update_option('wps3_migration_running', false);
	}

	/**
	 * Plugin uninstall hook.
	 *
	 * @return void
	 */
	public static function uninstall() {
		// Remove all plugin options
		delete_option('wps3_enabled');
		delete_option('wps3_delete_local');
		delete_option('wps3_s3_path');
		delete_option('wps3_access_key');
		delete_option('wps3_secret_key');
		delete_option('wps3_migrated_files');
		delete_option('wps3_migration_running');
		delete_option('wps3_migration_file_list');
	}

	/**
	 * Rewrite attachment URLs to point to S3.
	 *
	 * @param string $url The attachment URL.
	 * @param int $attachment_id The attachment ID.
	 * @return string The rewritten URL.
	 */
	public function rewrite_attachment_url($url, $attachment_id)
	{
		// If plugin is not enabled, return the original URL
		if (!get_option('wps3_enabled')) {
			return $url;
		}
		
		// Check if S3 client is configured
		if (empty($this->s3_client)) {
			return $url;
		}
		
		// Get the attachment file path
		$attachment_file = get_post_meta($attachment_id, '_wp_attached_file', true);
		if (empty($attachment_file)) {
			return $url;
		}
		
		// Check if the attachment exists in S3
		try {
			$key = $this->bucket_folder . '/' . basename($attachment_file);
			$s3_exists = $this->s3_client->doesObjectExist($this->bucket_name, $key);
			
			if ($s3_exists) {
				// Generate S3 URL
				if (!empty($this->endpoint)) {
					// Custom endpoint
					return esc_url($this->endpoint . '/' . $this->bucket_name . '/' . $key);
				} else {
					// Standard AWS S3 URL
					return esc_url('https://' . $this->bucket_name . '.s3.' . $this->bucket_region . '.amazonaws.com/' . $key);
				}
			}
		} catch (\Exception $e) {
			// Log error but continue with local URL
			error_log('WPS3: S3 URL rewrite error: ' . $e->getMessage());
		}
		
		return $url;
	}
	
	/**
	 * Rewrite image resize URLs to point to S3.
	 *
	 * @param array|false $downsize Whether to short-circuit the image downsize.
	 * @param int $attachment_id The attachment ID.
	 * @param array|string $size Requested size.
	 * @return array|false The image downsize value.
	 */
	public function rewrite_image_downsize($downsize, $attachment_id, $size)
	{
		// If already short-circuited or plugin is not enabled, return
		if ($downsize !== false || !get_option('wps3_enabled')) {
			return $downsize;
		}
		
		// Check if S3 client is configured
		if (empty($this->s3_client)) {
			return $downsize;
		}
		
		// Get the metadata
		$metadata = wp_get_attachment_metadata($attachment_id);
		if (empty($metadata)) {
			return $downsize;
		}
		
		// If size is the full size, use the attachment URL
		if ($size === 'full' || empty($metadata['sizes'][$size])) {
			$url = $this->rewrite_attachment_url(wp_get_attachment_url($attachment_id), $attachment_id);
			$width = isset($metadata['width']) ? intval($metadata['width']) : 0;
			$height = isset($metadata['height']) ? intval($metadata['height']) : 0;
			return [$url, $width, $height, false];
		}
		
		// Handle intermediate sizes
		if (isset($metadata['sizes'][$size])) {
			$size_data = $metadata['sizes'][$size];
			$attachment_path = get_post_meta($attachment_id, '_wp_attached_file', true);
			
			if ($attachment_path) {
				$base_url = trailingslashit(dirname($attachment_path));
				$size_file = $base_url . $size_data['file'];
				
				try {
					$key = $this->bucket_folder . '/' . $size_data['file'];
					$s3_exists = $this->s3_client->doesObjectExist($this->bucket_name, $key);
					
					if ($s3_exists) {
						// Generate S3 URL for the specific size
						if (!empty($this->endpoint)) {
							// Custom endpoint
							$url = esc_url($this->endpoint . '/' . $this->bucket_name . '/' . $key);
						} else {
							// Standard AWS S3 URL
							$url = esc_url('https://' . $this->bucket_name . '.s3.' . $this->bucket_region . '.amazonaws.com/' . $key);
						}
						
						return [$url, intval($size_data['width']), intval($size_data['height']), true];
					}
				} catch (\Exception $e) {
					// Log error but continue with WordPress default handling
					error_log('WPS3: S3 resized image URL rewrite error: ' . $e->getMessage());
				}
			}
		}
		
		return $downsize;
	}

	/**
	 * Upload attachment to S3 when it's added to WordPress.
	 * 
	 * @param int $attachment_id The attachment ID.
	 * @return void
	 */
	public function upload_attachment($attachment_id)
	{
		// If plugin is not enabled, do nothing
		if (!get_option('wps3_enabled')) {
			return;
		}
		
		// Check if S3 client is configured
		if (empty($this->s3_client)) {
			return;
		}
		
		// Get the file path
		$file_path = get_attached_file($attachment_id);
		if (empty($file_path) || !file_exists($file_path)) {
			return;
		}
		
		// Upload the main file
		$this->upload_file($file_path);
		
		// Upload resized versions if they exist
		$metadata = wp_get_attachment_metadata($attachment_id);
		if (!empty($metadata['sizes'])) {
			$base_dir = trailingslashit(dirname($file_path));
			
			foreach ($metadata['sizes'] as $size => $size_data) {
				$size_file_path = $base_dir . $size_data['file'];
				if (file_exists($size_file_path)) {
					$this->upload_file($size_file_path);
				}
			}
		}
	}
}

/**
 * Initialize the plugin.
 *
 * @return void
 */
function register_wps3() {
	$wps3 = new WPS3();
	$wps3->register_hooks();
}

// Initialize the plugin when WordPress is loaded.
add_action('plugins_loaded', 'register_wps3');

// Register activation, deactivation, and uninstall hooks
register_activation_hook(WPS3_PLUGIN_FILE, ['WPS3', 'activate']);
register_deactivation_hook(WPS3_PLUGIN_FILE, ['WPS3', 'deactivate']);
register_uninstall_hook(WPS3_PLUGIN_FILE, ['WPS3', 'uninstall']);
