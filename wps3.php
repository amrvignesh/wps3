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
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'WPS3_VERSION', '0.2' );
define( 'WPS3_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPS3_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPS3_PLUGIN_FILE', __FILE__ );
define( 'WPS3_MAX_BATCH_SIZE', 10 );
define( 'WPS3_RETRY_ATTEMPTS', 3 );
define( 'WPS3_TIMEOUT', 30 );
define( 'WPS3_PROCESS_BATCH_DELAY', 1000 ); // Delay between batch processing in milliseconds

require_once 'vendor/aws/aws-autoloader.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

/**
 * Interface for S3 storage operations
 */
interface S3StorageInterface {
	/**
	 * Upload a file to S3 storage
	 *
	 * @param string $file Path to the file to upload
	 * @param array  $options Additional upload options
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	public function upload( $file, $options = array() );

	/**
	 * Delete a file from S3 storage
	 *
	 * @param string $key The S3 object key
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	public function delete( $key );

	/**
	 * Get the URL for an S3 object
	 *
	 * @param string $key The S3 object key
	 * @return string|WP_Error The URL or WP_Error on failure
	 */
	public function getUrl( $key );

	/**
	 * Check if an object exists in S3
	 *
	 * @param string $key The S3 object key
	 * @return bool Whether the object exists
	 */
	public function exists( $key );
}

/**
 * Custom exceptions for WPS3
 */
class WPS3Exception extends Exception {}
class ConfigurationException extends WPS3Exception {}
class FileSizeException extends WPS3Exception {}
class InvalidUrlException extends WPS3Exception {}

/**
 * The S3 Uploads Offloader class.
 */
class WPS3 implements S3StorageInterface {
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
	protected $bucket_region; // Ensure this property exists

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
	public function __construct() {
		// Fetch S3 configuration options
		$this->endpoint = get_option( 'wps3_s3_endpoint_url' );
		$this->bucket_name = get_option( 'wps3_bucket_name' );
		$this->bucket_folder = get_option( 'wps3_bucket_folder', '' ); // Default to empty if not set
		$this->bucket_region = get_option( 'wps3_s3_region' ); // Fetch the S3 region

		// Initialize S3 client if we have the necessary configuration
		if ( ! empty( $this->endpoint ) && ! empty( $this->bucket_name ) && ! empty( $this->bucket_region ) ) { // Check for bucket_region
			$config = array(
				'version'     => 'latest',
				'region'      => $this->bucket_region, // Use the fetched S3 region
				'endpoint'    => $this->endpoint,
				'credentials' => array(
					'key'    => get_option( 'wps3_access_key' ),
					'secret' => get_option( 'wps3_secret_key' ),
				),
				'use_path_style_endpoint' => true, // Important for S3-compatible services
			);

			try {
				// Corrected S3Client instantiation
				$this->s3_client = new \Aws\S3\S3Client( $config );
			} catch ( \Exception $e ) { 
				// Log the error but don't crash
				error_log( 'WPS3: Error initializing S3 client: ' . $e->getMessage() );
			}
		}
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
		add_action('wp_ajax_wps3_pause_migration', [$this, 'ajax_pause_migration']);
		
		// Filters for rewriting URLs - Increased priority to 99
		add_filter('wp_get_attachment_url', [$this, 'rewrite_attachment_url'], 99, 2);
		add_filter('image_downsize', [$this, 'rewrite_image_downsize'], 99, 3);
		add_filter('wp_handle_upload', [$this, 'upload_overrides']);
		
		// Additional filters for media library interface
		add_filter('attachment_link', [$this, 'rewrite_attachment_url'], 99, 2);
		add_filter('wp_get_attachment_image_src', [$this, 'rewrite_image_src'], 99, 4);
		add_filter('wp_calculate_image_srcset', [$this, 'rewrite_srcset'], 99, 5);
		add_filter('wp_get_attachment_thumb_url', [$this, 'rewrite_attachment_url'], 99, 2);
		add_filter('wp_get_attachment_image_attributes', [$this, 'rewrite_image_attributes'], 99, 3);
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
			// Display an admin notice if the S3 client failed to initialize due to missing configuration
			if ( empty( $this->endpoint ) || empty( $this->bucket_name ) || empty( $this->bucket_region ) ) {
				add_action('admin_notices', function() {
					echo '<div class="error"><p>';
					echo esc_html__('WPS3: S3 client could not be initialized. Please ensure Endpoint URL, Bucket Name, and S3 Region are correctly configured in settings.', 'wps3');
					echo '</p></div>';
				});
			}
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
			// Display a generic error if bucket check fails for other reasons
			add_action('admin_notices', function() use ($e) {
				echo '<div class="error"><p>';
				printf(
					/* translators: %s: Error message */
					esc_html__('WPS3: Error connecting to S3 provider: %s. Please check your S3 settings and network connectivity.', 'wps3'),
					esc_html($e->getMessage())
				);
				echo '</p></div>';
			});
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
			$file_basename = basename($file);
			$key_parts = [];
			if (!empty($this->bucket_folder)) {
				$key_parts[] = trim($this->bucket_folder, '/');
			}
			$key_parts[] = $file_basename;
			$key = implode('/', $key_parts);
			
			$this->s3_client->putObject([
				'Bucket' => $this->bucket_name,
				'Key' => $key,
				'Body' => fopen($file, 'r'), // Use fopen for better memory management with large files
				'ACL' => 'public-read',
				'ContentType' => $this->get_mime_type($file),
			]);

			// Delete the local file if that option is enabled
			if (get_option('wps3_delete_local')) {
				@unlink($file);
			}

			return true;
		} catch ( \Exception $e) { // Corrected: Single backslash for root namespace Exception
			error_log('S3 upload error for file ' . $file . ': ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Override the WordPress upload process to upload files to the S3 bucket.
	 */
	public function upload_overrides($file_data) // Parameter changed from $file to $file_data for clarity
	{
		// Check if the plugin is enabled.
		if (!get_option('wps3_enabled') || empty($this->s3_client)) {
			return $file_data;
		}

		// Upload the file to S3
		$upload_success = $this->upload_file($file_data['file']);
		
		if ($upload_success) {
			$file_basename = basename($file_data['file']);
			$key_parts = [];
			if (!empty($this->bucket_folder)) {
				$key_parts[] = trim($this->bucket_folder, '/');
			}
			$key_parts[] = $file_basename;
			$s3_object_key = implode('/', $key_parts);

			$s3_url = $this->get_s3_url($s3_object_key);
			
			// Update the file info with S3 data
			$file_data['url'] = $s3_url;
			$file_data['s3_info'] = array(
				'bucket' => $this->bucket_name,
				'key'    => $s3_object_key,
				'url'    => $s3_url,
			);
		} else {
			// Handle upload failure? For now, return original data.
			// WordPress will store it locally.
		}

		return $file_data;
	}

	/**
	 * Delete a file from the S3 bucket.
	 *
	 * @param int $attachment_id The ID of the attachment.
	 */
	public function delete_attachment($attachment_id)
	{
		if (empty($this->s3_client) || empty($this->bucket_name)) {
			return;
		}
		$attachment_meta = get_post_meta($attachment_id, '_wp_attached_file', true);

		if (!empty($attachment_meta)) {
			$file_basename = basename($attachment_meta);
			$key_parts = [];
			if (!empty($this->bucket_folder)) {
				$key_parts[] = trim($this->bucket_folder, '/');
			}
			$key_parts[] = $file_basename;
			$s3_object_key = implode('/', $key_parts);
			
			try {
				$this->s3_client->deleteObject([
					'Bucket' => $this->bucket_name,
					'Key'    => $s3_object_key,
				]);

				// Also delete thumbnails/intermediate sizes from S3
				$metadata = wp_get_attachment_metadata($attachment_id);
				if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
					foreach ($metadata['sizes'] as $size_info) {
						$thumbnail_basename = $size_info['file'];
						$thumb_key_parts = [];
						if (!empty($this->bucket_folder)) {
							$thumb_key_parts[] = trim($this->bucket_folder, '/');
						}
						// If original file was in a year/month subfolder, thumbnails are too.
						// dirname($attachment_meta) will give that path relative to uploads folder.
						$original_file_dir = dirname($attachment_meta);
						if ($original_file_dir !== '.') {
							$thumb_key_parts[] = $original_file_dir;
						}
						$thumb_key_parts[] = $thumbnail_basename;
						$s3_thumb_key = implode('/', array_filter($thumb_key_parts));
						
						$this->s3_client->deleteObject([
							'Bucket' => $this->bucket_name,
							'Key'    => $s3_thumb_key,
						]);
					}
				}
			} catch ( \Exception $e) { // Corrected: Single backslash for root namespace Exception
				error_log('WPS3: Error deleting attachment ' . $attachment_id . ' from S3: ' . $e->getMessage());
			}
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
			__( 'S3 Uploads Offloader', 'wps3' ),
			[$this, 'settings_section_callback'],
			'wps3'
		);

		add_settings_field(
			'wps3_enabled',
			__( 'Enable S3 Uploads Offloader', 'wps3' ),
			[$this, 'settings_field_enabled_callback'],
			'wps3',
			'wps3_section'
		);

		add_settings_field(
			'wps3_s3_endpoint_url',
			__( 'S3 Endpoint URL', 'wps3' ),
			[$this, 'settings_field_s3_endpoint_url_callback'],
			'wps3',
			'wps3_section'
		);

		add_settings_field(
			'wps3_bucket_name',
			__( 'Bucket Name', 'wps3' ),
			[$this, 'settings_field_bucket_name_callback'],
			'wps3',
			'wps3_section'
		);
		
		add_settings_field(
			'wps3_bucket_folder',
			__( 'Bucket Folder (Optional)', 'wps3' ),
			[$this, 'settings_field_bucket_folder_callback'],
			'wps3',
			'wps3_section'
		);

		// Re-add settings_field for wps3_s3_region
		add_settings_field(
			'wps3_s3_region',
			__( 'S3 Region', 'wps3' ),
			[$this, 'settings_field_s3_region_callback'], // Ensure this callback exists
			'wps3',
			'wps3_section'
		);

		add_settings_field(
			'wps3_access_key',
			__( 'Access Key', 'wps3' ),
			[$this, 'settings_field_access_key_callback'],
			'wps3',
			'wps3_section'
		);

		add_settings_field(
			'wps3_secret_key',
			__( 'Secret Key', 'wps3' ),
			[$this, 'settings_field_secret_key_callback'],
			'wps3',
			'wps3_section'
		);

		add_settings_field(
			'wps3_cdn_domain',
			__( 'CDN Domain', 'wps3' ),
			[$this, 'settings_field_cdn_domain_callback'],
			'wps3',
			'wps3_section'
		);

		add_settings_field(
			'wps3_delete_local',
			__( 'Delete Local Files', 'wps3' ),
			[$this, 'settings_field_delete_local_callback'],
			'wps3',
			'wps3_section'
		);

		register_setting('wps3', 'wps3_enabled');
		register_setting('wps3', 'wps3_s3_endpoint_url', [$this, 'validate_s3_endpoint_url']);
		register_setting('wps3', 'wps3_bucket_name', [$this, 'validate_bucket_name']);
		register_setting('wps3', 'wps3_bucket_folder', [$this, 'validate_bucket_folder']);
		// Re-add register_setting for wps3_s3_region
		register_setting('wps3', 'wps3_s3_region', [$this, 'validate_s3_region']); // Ensure this validation function exists
		register_setting('wps3', 'wps3_access_key');
		register_setting('wps3', 'wps3_secret_key');
		register_setting('wps3', 'wps3_cdn_domain', [$this, 'validate_cdn_domain']);
		register_setting('wps3', 'wps3_delete_local');
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
	 * Render the S3 Endpoint URL settings field.
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
	 * Render the Bucket Name settings field.
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
	 * Render the Bucket Folder settings field.
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
	 * Render the S3 Region settings field.
	 */
	public function settings_field_s3_region_callback() { // Re-add this callback method
		?>
		<input type="text" name="wps3_s3_region" value="<?php echo esc_attr(get_option('wps3_s3_region')); ?>" class="regular-text" placeholder="e.g., us-west-2" />
		<p class="description">
			<?php _e('Enter the S3 region for your bucket (e.g., <code>us-west-2</code>, <code>eu-central-1</code>). This is required by some S3-compatible providers.', 'wps3'); ?>
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
	 * Render the CDN domain settings field.
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
	 * Validate the S3 Endpoint URL.
	 * @param string $value The S3 endpoint URL value.
	 * @return string The validated S3 endpoint URL.
	 */
	public function validate_s3_endpoint_url($value) {
		if (empty($value)) {
			add_settings_error('wps3_s3_endpoint_url', 'empty', __('Please enter the S3 Endpoint URL.', 'wps3'));
			return '';
		}
		if (!filter_var($value, FILTER_VALIDATE_URL)) {
			add_settings_error('wps3_s3_endpoint_url', 'invalid', __('The S3 Endpoint URL is not a valid URL.', 'wps3'));
			return esc_url_raw($value); // Return sanitized value even if invalid to show user
		}
		return esc_url_raw(rtrim($value, '/')); // Store without trailing slash
	}

	/**
	 * Validate the Bucket Name.
	 * @param string $value The bucket name value.
	 * @return string The validated bucket name.
	 */
	public function validate_bucket_name($value) {
		if (empty($value)) {
			add_settings_error('wps3_bucket_name', 'empty', __('Please enter the Bucket Name.', 'wps3'));
			return '';
		}
		// Basic S3 bucket naming rules (simplified): 3-63 chars, lowercase, numbers, hyphens, dots (not at start/end)
		if (!preg_match('/^(?=.{3,63}$)[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $value)) {
			add_settings_error('wps3_bucket_name', 'invalid', __('The Bucket Name is not valid. It should be 3-63 characters, lowercase letters, numbers, dots, and hyphens.', 'wps3'));
		}
		return sanitize_text_field($value);
	}

	/**
	 * Validate the Bucket Folder.
	 * @param string $value The bucket folder value.
	 * @return string The validated bucket folder.
	 */
	public function validate_bucket_folder($value) {
		// Optional field. If provided, sanitize. Remove leading/trailing slashes.
		if (!empty($value)) {
			return trim(sanitize_text_field($value), '/');
		}
		return ''; // Return empty if not provided
	}

	/**
	 * Validate the S3 Region.
	 * @param string $value The S3 region value.
	 * @return string The validated S3 region.
	 */
	public function validate_s3_region($value) { // Re-add this validation method
		if (empty($value)) {
			add_settings_error('wps3_s3_region', 'empty', __('Please enter the S3 Region.', 'wps3'));
			// Return current value to avoid clearing the field on error, or '' if you prefer to clear it.
			// For consistency with other validation, let's return '' to indicate an error and clear.
			return ''; 
		}
		// Basic validation for region format (lowercase letters, numbers, hyphens)
		if (!preg_match('/^[a-z0-9-]+$/', $value)) {
			add_settings_error('wps3_s3_region', 'invalid', __('The S3 Region is not valid (e.g., us-west-2).', 'wps3'));
			// Return the sanitized input to show the user what they typed, even if invalid.
			// Or return get_option('wps3_s3_region') to revert to the previously saved valid value.
			// For now, let's return the sanitized input.
		}
		return sanitize_text_field($value);
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
		$js_file = plugin_dir_path(__FILE__) . 'assets/js/admin.js';
		if (!file_exists($js_file)) {
			$this->create_default_js_file();
		}
		
		// Create CSS file if it doesn't exist
		$css_file = plugin_dir_path(__FILE__) . 'assets/css/admin.css';
		if (!file_exists($css_file)) {
			$this->create_default_css_file();
		}
		
		wp_enqueue_script(
			'wps3-admin-js',
			plugin_dir_url(__FILE__) . 'assets/js/admin.js',
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
				'process_batch_delay' => WPS3_PROCESS_BATCH_DELAY,
			]
		);
		
		wp_enqueue_style(
			'wps3-admin-css',
			plugin_dir_url(__FILE__) . 'assets/css/admin.css',
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
		$js_file = plugin_dir_path(__FILE__) . 'assets/js/admin.js';
		$js_content = <<<'JS'
jQuery(document).ready(function($) {
    const STATUS_CHECK_INTERVAL = 5000; // 5 seconds
    const MAX_LOG_ENTRIES = 100; // Maximum number of log entries to keep
    let statusCheckTimer = null;
    let processingBatch = false;
    let logFragment = null;
    let logUpdateTimeout = null;
    
    // DOM Elements
    const $startButton = $('#wps3-start-migration');
    const $pauseButton = $('#wps3-pause-migration');
    const $resetButton = $('#wps3-reset-migration');
    const $progressBar = $('.wps3-progress-bar');
    const $progressText = $('.wps3-progress-bar span');
    const $logContainer = $('#wps3-log-container');
    const $migrationStats = $('.wps3-migration-stats p');
    
    /**
     * Initialize a new log fragment
     */
    function initLogFragment() {
        logFragment = document.createDocumentFragment();
    }
    
    /**
     * Flush the log fragment to the DOM
     */
    function flushLogFragment() {
        if (logFragment && logFragment.children.length > 0) {
            $logContainer.append(logFragment);
            
            // Remove excess entries if we're over the limit
            const $entries = $logContainer.children();
            if ($entries.length > MAX_LOG_ENTRIES) {
                $entries.slice(0, $entries.length - MAX_LOG_ENTRIES).remove();
            }
            
            logFragment = null;
        }
    }
    
    /**
     * Log a message to the migration log
     */
    function logMessage(message, type = 'info') {
        const timestamp = new Date().toLocaleTimeString();
        const $entry = $('<div>', {
            class: 'wps3-log-entry wps3-log-' + type,
            text: `[${timestamp}] ${message}`
        });
        
        // Initialize fragment if needed
        if (!logFragment) {
            initLogFragment();
        }
        
        // Add entry to fragment
        logFragment.appendChild($entry[0]);
        
        // Clear any existing timeout
        if (logUpdateTimeout) {
            clearTimeout(logUpdateTimeout);
        }
        
        // Schedule a flush of the fragment
        logUpdateTimeout = setTimeout(flushLogFragment, 100);
    }
    
    /**
     * Load existing logs from the server
     */
    function loadExistingLogs() {
        $.ajax({
            url: wps3_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wps3_get_migration_status',
                nonce: wps3_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data.logs) {
                    // Clear existing logs
                    clearLog();
                    
                    // Add each log entry
                    response.data.logs.forEach(function(log) {
                        const timestamp = new Date(log.timestamp).toLocaleTimeString();
                        logMessage(`[${timestamp}] ${log.message}`, log.type);
                    });
                }
            }
        });
    }
    
    /**
     * Clear the log
     */
    function clearLog() {
        $logContainer.empty();
        if (logFragment) {
            logFragment = null;
        }
        if (logUpdateTimeout) {
            clearTimeout(logUpdateTimeout);
            logUpdateTimeout = null;
        }
    }
    
    /**
     * Update the progress bar
     */
    function updateProgress(percent, migratedFiles, totalFiles) {
        $progressBar.css('width', percent + '%');
        $progressText.text(percent + '%');
        $progressBarContainer.attr('aria-valuenow', percent);
        $migrationStats.text(`Progress: ${migratedFiles} of ${totalFiles} files migrated`);
    }
    
    /**
     * Start the migration process
     */
    function startMigration(reset = false) {
        if (reset) {
            clearLog();
        }
        
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
                action: 'wps3_pause_migration',
                nonce: wps3_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $pauseButton.hide();
                    $startButton.show().prop('disabled', false);
                    logMessage('Migration paused');
                } else {
                    logMessage('Error: ' + response.data, 'error');
                }
            },
            error: function(xhr, status, error) {
                logMessage('AJAX Error: ' + error, 'error');
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
                        setTimeout(processBatch, wps3_ajax.process_batch_delay);
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
                setTimeout(processBatch, wps3_ajax.process_batch_delay * 5); // Use 5x delay for retries
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
    
    // Load existing logs when the page loads
    loadExistingLogs();
});
JS;
		file_put_contents($js_file, $js_content);
	}
	
	/**
	 * Create default CSS file.
	 */
	private function create_default_css_file()
	{
		$css_file = plugin_dir_path(__FILE__) . 'assets/css/admin.css';
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
				<div class="wps3-progress-bar-container" role="progressbar" aria-valuenow="<?php echo esc_attr($percent_complete); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e('Migration Progress', 'wps3'); ?>">
					<div class="wps3-progress-bar" style="width: <?php echo esc_attr($percent_complete); ?>%;">
						<span><?php echo esc_html($percent_complete); ?>%</span>
					</div>
				</div>
				
				<div class="wps3-migration-stats" aria-live="polite">
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
						<button type="button" id="wps3-pause-migration" class="button button-primary" aria-label="<?php esc_attr_e('Pause the migration process', 'wps3'); ?>">
							<?php _e('Pause Migration', 'wps3'); ?>
						</button>
					<?php else: ?>
						<button type="button" id="wps3-start-migration" class="button button-primary" aria-label="<?php esc_attr_e('Start the migration process', 'wps3'); ?>">
							<?php _e('Start Migration', 'wps3'); ?>
						</button>
					<?php endif; ?>
					
					<button type="button" id="wps3-reset-migration" class="button" aria-label="<?php esc_attr_e('Reset the migration progress and start over', 'wps3'); ?>">
						<?php _e('Reset Migration', 'wps3'); ?>
					</button>
				</div>
				
				<div class="wps3-migration-log" aria-live="polite">
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
	 * AJAX handler for starting migration
	 */
	public function ajax_start_migration() {
		// Verify nonce and user permissions
		if (!check_ajax_referer('wps3_ajax_nonce', 'nonce', false) || !current_user_can('manage_options')) {
			wp_send_json_error('Security check failed');
		}

		try {
			// Validate configuration
			$this->validate_configuration();

			// Reset migration status if requested
			$reset = isset($_POST['reset']) ? sanitize_text_field($_POST['reset']) : 'false';
			if ($reset === 'true') {
				update_option('wps3_migration_position', 0);
				update_option('wps3_migration_status', 'in_progress');
				delete_transient('wps3_migration_progress');
				$this->clear_logs();
				$this->add_log_entry('Migration started', 'info');
			}

			// Start migration
			$result = $this->process_batch(0);
			if (is_wp_error($result)) {
				$recovery_result = $this->recover_from_error($result);
				if (is_wp_error($recovery_result)) {
					$this->add_log_entry($recovery_result->get_error_message(), 'error');
					wp_send_json_error($recovery_result->get_error_message());
				}
			}

			wp_send_json_success([
				'status' => get_option('wps3_migration_status'),
				'position' => get_option('wps3_migration_position'),
				'progress' => get_transient('wps3_migration_progress')
			]);
		} catch (\Exception $e) {
			$this->add_log_entry($e->getMessage(), 'error');
			error_log('WPS3: Migration start error: ' . $e->getMessage());
			wp_send_json_error($e->getMessage());
		}
	}

	/**
	 * AJAX handler for processing a batch
	 */
	public function ajax_process_batch() {
		// Verify nonce and user permissions
		if (!check_ajax_referer('wps3_ajax_nonce', 'nonce', false) || !current_user_can('manage_options')) {
			wp_send_json_error('Security check failed');
		}

		try {
			$position = get_option('wps3_migration_position');
			$result = $this->process_batch($position);
			
			if (is_wp_error($result)) {
				$recovery_result = $this->recover_from_error($result);
				if (is_wp_error($recovery_result)) {
					wp_send_json_error($recovery_result->get_error_message());
				}
			}

			wp_send_json_success([
				'status' => get_option('wps3_migration_status'),
				'position' => get_option('wps3_migration_position'),
				'progress' => get_transient('wps3_migration_progress')
			]);
		} catch (\Exception $e) {
			error_log('WPS3: Batch processing error: ' . $e->getMessage());
			wp_send_json_error($e->getMessage());
		}
	}

	/**
	 * AJAX handler for getting migration status
	 */
	public function ajax_get_migration_status() {
		// Verify nonce and user permissions
		if (!check_ajax_referer('wps3_ajax_nonce', 'nonce', false) || !current_user_can('manage_options')) {
			wp_send_json_error('Security check failed');
		}

		try {
			// Get recent logs
			$logs = $this->get_recent_logs();
			$formatted_logs = array_map(function($log) {
				return [
					'message' => $log->message,
					'type' => $log->type,
					'timestamp' => $log->created_at
				];
			}, $logs);
			
			wp_send_json_success([
				'status' => get_option('wps3_migration_status'),
				'position' => get_option('wps3_migration_position'),
				'progress' => get_transient('wps3_migration_progress'),
				'logs' => $formatted_logs
			]);
		} catch (\Exception $e) {
			error_log('WPS3: Status check error: ' . $e->getMessage());
			wp_send_json_error($e->getMessage());
		}
	}

	/**
	 * AJAX handler for updating options
	 */
	public function ajax_update_option() {
		// Verify nonce and user permissions
		if (!check_ajax_referer('wps3_ajax_nonce', 'nonce', false) || !current_user_can('manage_options')) {
			wp_send_json_error('Security check failed');
		}

		try {
			$option_name = isset($_POST['option_name']) ? sanitize_key($_POST['option_name']) : '';
			$option_value = isset($_POST['option_value']) ? sanitize_text_field($_POST['option_value']) : '';

			if (empty($option_name)) {
				throw new \Exception('Option name is required');
			}

			// Validate option value based on option name
			switch ($option_name) {
				case 'wps3_s3_path':
					$this->validate_s3_path($option_value);
					break;
				case 'wps3_access_key':
				case 'wps3_secret_key':
					if (empty($option_value)) {
						throw new \Exception('Access and secret keys are required');
					}
					break;
			}

			update_option($option_name, $option_value);
			wp_send_json_success();
		} catch (\Exception $e) {
			error_log('WPS3: Option update error: ' . $e->getMessage());
			wp_send_json_error($e->getMessage());
		}
	}

	/**
	 * AJAX handler for pausing migration
	 */
	public function ajax_pause_migration() {
		// Verify nonce and user permissions
		if (!check_ajax_referer('wps3_ajax_nonce', 'nonce', false) || !current_user_can('manage_options')) {
			wp_send_json_error('Security check failed');
		}

		try {
			$status = get_option('wps3_migration_status');
			if ($status !== 'in_progress') {
				wp_send_json_error('Migration is not in progress');
				return;
			}

			// Update migration status
			update_option('wps3_migration_status', 'paused');
			$this->add_log_entry('Migration paused', 'info');

			wp_send_json_success([
				'status' => 'paused',
				'position' => get_option('wps3_migration_position'),
				'progress' => get_transient('wps3_migration_progress')
			]);
		} catch (\Exception $e) {
			$this->add_log_entry('Error pausing migration: ' . $e->getMessage(), 'error');
			error_log('WPS3: Pause migration error: ' . $e->getMessage());
			wp_send_json_error($e->getMessage());
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
		add_option('wps3_s3_endpoint_url', '');
		add_option('wps3_bucket_name', '');
		add_option('wps3_bucket_folder', '');
		add_option('wps3_s3_region', ''); // Re-add option
		add_option('wps3_access_key', '');
		add_option('wps3_secret_key', '');
		add_option('wps3_cdn_domain', '');
		add_option('wps3_delete_local', false);
		add_option('wps3_migrated_files', 0);
		add_option('wps3_migration_running', false);
		add_option('wps3_migration_file_list', []);
		
		// Create logs table
		global $wpdb;
		$table_name = $wpdb->prefix . 'wps3_logs';
		$charset_collate = $wpdb->get_charset_collate();
		
		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			message text NOT NULL,
			type varchar(20) NOT NULL DEFAULT 'info',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";
		
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
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
		delete_option('wps3_s3_endpoint_url');
		delete_option('wps3_bucket_name');
		delete_option('wps3_bucket_folder');
		delete_option('wps3_s3_region'); // Re-add option for deletion
		delete_option('wps3_access_key');
		delete_option('wps3_secret_key');
		delete_option('wps3_cdn_domain');
		delete_option('wps3_delete_local');
		delete_option('wps3_migrated_files');
		delete_option('wps3_migration_running');
		delete_option('wps3_migration_file_list');
		
		// Remove logs table
		global $wpdb;
		$table_name = $wpdb->prefix . 'wps3_logs';
		$wpdb->query("DROP TABLE IF EXISTS $table_name");
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
		// Check if the plugin is enabled and S3 client is initialized
		if (!get_option('wps3_enabled') || empty($this->s3_client)) {
			// If not enabled or client not ready, log why and return original URL
			// Optional: Add error_log here for debugging if needed in the future
			// if (!get_option('wps3_enabled')) {
			// error_log("WPS3 rewrite_attachment_url: Plugin not enabled for attachment ID $attachment_id.");
			// }
			// if (empty($this->s3_client)) {
			// error_log("WPS3 rewrite_attachment_url: S3 client not initialized for attachment ID $attachment_id.");
			// }
			return $url;
		}

		$s3_info = get_post_meta($attachment_id, 'wps3_s3_info', true);

		if (!empty($s3_info) && isset($s3_info['key'])) {
			$cdn_domain = get_option('wps3_cdn_domain');
			if (!empty($cdn_domain)) {
				// Ensure CDN domain does not have a trailing slash and key does not have a leading slash
				$cdn_base = rtrim($cdn_domain, '/');
				$s3_key = ltrim($s3_info['key'], '/');
				// Corrected CDN URL construction to ensure a single slash
				return $cdn_base . "/" . $s3_key;
			} elseif (isset($s3_info['url']) && !empty($s3_info['url'])) {
				// Fallback to S3 URL if CDN is not set but S3 info URL exists
				return $s3_info['url'];
			}
			// If only key is present, construct S3 URL
			return $this->get_s3_url($s3_info['key']);
		}
		return $url;
	}

	/**
	 * Rewrite image downsize URLs to point to S3 or CDN.
	 *
	 * @param bool|array $downsize Whether to short-circuit the image downsize. Default false.
	 * @param int        $id Attachment ID for image.
	 * @param string|array $size Either a string keyword (thumbnail, medium, large, full) or a 2-item array representing width and height in pixels, e.g. array(32,32).
	 * @return bool|array False to continue with default behavior, or array of image data.
	 */
	public function rewrite_image_downsize($downsize, $id, $size)
	{
		// Check if the plugin is enabled and S3 client is initialized
		if (!get_option('wps3_enabled') || empty($this->s3_client)) {
			return $downsize;
		}

		$s3_info = get_post_meta($id, 'wps3_s3_info', true);
		$cdn_domain = get_option('wps3_cdn_domain');

		if (!empty($s3_info) && isset($s3_info['key'])) {
			// Get the base URL (S3 or CDN) for the full-sized image first
			// This reuses the logic from rewrite_attachment_url for the main image.
			$full_image_url = $this->rewrite_attachment_url(wp_get_attachment_url($id), $id);
			
			$meta = wp_get_attachment_metadata($id);

			if (is_string($size)) {
				if (isset($meta['sizes'][$size])) {
					$s3_key_base = dirname($s3_info['key']);
					// Handle cases where the original key might be at the root (dirname returns '.')
					$s3_key_base = ($s3_key_base === '.' || $s3_key_base === '/') ? '' : trailingslashit($s3_key_base);
					
					$resized_file_name = $meta['sizes'][$size]['file'];
					$s3_resized_key = $s3_key_base . $resized_file_name;

					$img_url_for_size = '';
					if (!empty($cdn_domain)) {
						$cdn_base = rtrim($cdn_domain, '/');
						// Corrected CDN URL construction
						$img_url_for_size = $cdn_base . '/' . ltrim($s3_resized_key, '/');
					} else {
						$img_url_for_size = $this->get_s3_url($s3_resized_key);
					}
					$width = $meta['sizes'][$size]['width'];
					$height = $meta['sizes'][$size]['height'];
					return array($img_url_for_size, $width, $height, true);
				}
				// If specific size not found, fall through to use full image URL
			} elseif (is_array($size)) {
				// For custom array sizes, WordPress typically uses the full image and resizes via HTML/CSS.
				// We return the S3/CDN URL for the full image.
				$width = isset($meta['width']) ? $meta['width'] : null;
				$height = isset($meta['height']) ? $meta['height'] : null;
				return array($full_image_url, $width, $height, false); // false indicates it\'s not an exact match for $size
			}

			// Fallback for full size or if specific string size not found in meta
			$width = isset($meta['width']) ? $meta['width'] : null;
			$height = isset($meta['height']) ? $meta['height'] : null;
			return array($full_image_url, $width, $height, true); 
		}

		return $downsize;
	}

	/**
	 * Get the S3 URL for a given key.
	 *
	 * @param string $key The S3 object key.
	 * @return string The S3 URL.
	 */
	protected function get_s3_url($key)
	{
		if (empty($this->s3_client) || empty($this->bucket_name)) {
			return ''; // Or handle error appropriately
		}
		return $this->s3_client->getObjectUrl($this->bucket_name, $key);
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
		if (!get_option('wps3_enabled') || empty($this->s3_client)) {
			return;
		}
		
		// Get the file path
		$file_path = get_attached_file($attachment_id);
		if (empty($file_path) || !file_exists($file_path)) {
			error_log("WPS3: File path not found for attachment ID: $attachment_id");
			return;
		}
		
		// Upload the main file
		$main_upload_success = $this->upload_file($file_path);

		if (!$main_upload_success) {
			error_log("WPS3: Failed to upload main file for attachment ID: $attachment_id, Path: $file_path");
			// Decide if we should stop or try to update meta anyway / or with local URL
			return; 
		}
		
		// Update post meta with S3 info after successful main file upload
		// This ensures that even if thumbnails fail, the main file URL is S3
		$file_basename = basename($file_path);
		$key_parts = [];
		if (!empty($this->bucket_folder)) {
			// If the file is in a subdirectory (e.g. year/month), include that in the S3 key
			$upload_dir_info = wp_upload_dir();
			$relative_path = str_replace(trailingslashit($upload_dir_info['basedir']), '', dirname($file_path));
			$full_folder_path = trim($this->bucket_folder, '/');
			if (!empty($relative_path) && $relative_path !== '.') {
				$full_folder_path .= '/' . trim($relative_path, '/');
			}
			$key_parts[] = trim($full_folder_path, '/');
		} else {
			// If no bucket folder, check for year/month subdirectories from WordPress
			$upload_dir_info = wp_upload_dir();
			$relative_path = str_replace(trailingslashit($upload_dir_info['basedir']), '', dirname($file_path));
			if (!empty($relative_path) && $relative_path !== '.') {
				$key_parts[] = trim($relative_path, '/');
			}
		}
		$key_parts[] = $file_basename;
		$s3_object_key = implode('/', array_filter($key_parts)); // array_filter to remove empty parts

		$s3_url = $this->get_s3_url($s3_object_key);
		update_post_meta($attachment_id, 'wps3_s3_info', [
			'bucket' => $this->bucket_name,
			'key'    => $s3_object_key,
			'url'    => $s3_url,
		]);
		
		// Upload resized versions if they exist
		$metadata = wp_get_attachment_metadata($attachment_id);
		if (!empty($metadata['sizes'])) {
			$upload_dir_info = wp_upload_dir();
			// Path of the originally uploaded file, relative to WP uploads base directory
			$original_file_relative_path = get_post_meta($attachment_id, '_wp_attached_file', true); 
			$base_dir_for_thumbnails = trailingslashit(dirname(trailingslashit($upload_dir_info['basedir']) . $original_file_relative_path));
			
			foreach ($metadata['sizes'] as $size_name => $size_data) {
				$size_file_path = $base_dir_for_thumbnails . $size_data['file'];
				if (file_exists($size_file_path)) {
					$thumb_upload_success = $this->upload_file($size_file_path);
					if (!$thumb_upload_success) {
						error_log("WPS3: Failed to upload thumbnail $size_name for attachment ID: $attachment_id, Path: $size_file_path");
					}
				} else {
					error_log("WPS3: Thumbnail file not found for $size_name, attachment ID: $attachment_id, Path: $size_file_path");
				}
			}
		}
	}

	/**
	 * Validate the plugin configuration
	 *
	 * @throws ConfigurationException If configuration is invalid
	 */
	private function validate_configuration() {
		// Re-add 'bucket_region' to required fields for S3 client initialization check
		$required_for_s3_client = ['endpoint', 'bucket_name', 'bucket_region']; 
		$missing_for_s3_client = [];

		foreach ($required_for_s3_client as $field) {
			if (empty($this->$field)) {
				$missing_for_s3_client[] = ucfirst(str_replace('_', ' ', $field));
			}
		}

		if (!empty($missing_for_s3_client)) {
			$error_message = sprintf(
				/* translators: %s: Comma-separated list of missing field names. */
				__('WPS3: S3 client cannot be initialized. Missing required configuration: %s.', 'wps3'),
				implode(', ', $missing_for_s3_client)
			);
			// This notice will be shown if init() fails due to no s3_client
			// For direct calls to validate_configuration (e.g. before migration), throw exception
			if (empty($this->s3_client)) { // Check if client is actually not initialized
				throw new ConfigurationException($error_message);
			}
		}
		
		// If S3 client itself is null after attempting initialization, it means config was insufficient.
		if (empty($this->s3_client)) {
			// Construct a more specific message if possible, otherwise a general one.
			$specific_missing = [];
			if (empty($this->endpoint)) $specific_missing[] = 'S3 Endpoint URL';
			if (empty($this->bucket_name)) $specific_missing[] = 'Bucket Name';
			if (empty($this->bucket_region)) $specific_missing[] = 'S3 Region';

			if (!empty($specific_missing)) {
				throw new ConfigurationException(
					sprintf(
						/* translators: %s: Comma-separated list of missing field names. */
						__('WPS3: S3 client initialization failed. Please provide: %s.', 'wps3'),
						implode(', ', $specific_missing)
					)
				);
			} else {
				// This case should ideally not be hit if the constructor logic is correct,
				// but as a fallback:
				throw new ConfigurationException(__('WPS3: S3 client initialization failed. Please check S3 settings.', 'wps3'));
			}
		}
	}

	/**
	 * Validate file size before upload
	 *
	 * @param string $file_path Path to the file
	 * @throws FileSizeException If file is too large
	 */
	private function validate_file_size($file_path) {
		$max_size = wp_max_upload_size();
		if (filesize($file_path) > $max_size) {
			throw new FileSizeException("File exceeds maximum allowed size of " . size_format($max_size));
		}
	}

	/**
	 * Validate URL format
	 *
	 * @param string $url URL to validate
	 * @throws InvalidUrlException If URL is invalid
	 */
	private function validate_url($url) {
		if (!filter_var($url, FILTER_VALIDATE_URL)) {
			throw new InvalidUrlException("Invalid URL format: $url");
		}
	}

	/**
	 * Upload a file to S3 storage
	 *
	 * @param string $file Path to the file to upload
	 * @param array $options Additional upload options
	 * @return bool|WP_Error True on success, WP_Error on failure
	 * @throws S3Exception If S3 upload fails
	 */
	public function upload($file, $options = []) {
		try {
			// Validate file
			if (!file_exists($file)) {
				return new WP_Error('file_not_found', 'File does not exist');
			}

			// Validate file size
			$this->validate_file_size($file);

			// Get file info
			$file_info = wp_check_filetype(basename($file));
			if (!$file_info['type']) {
				return new WP_Error('invalid_file_type', 'Invalid file type');
			}

			// Prepare upload
			$key = $this->bucket_folder . '/' . basename($file);
			
			// Add hooks
			do_action('wps3_before_upload', $file);

			// Upload to S3
			$result = $this->s3_client->putObject([
				'Bucket' => $this->bucket_name,
				'Key' => $key,
				'Body' => file_get_contents($file),
				'ContentType' => $file_info['type'],
				'ACL' => 'public-read',
			]);

			// Delete local file if enabled
			if (get_option('wps3_delete_local')) {
				@unlink($file);
			}

			do_action('wps3_after_upload', $file, $result);

			return true;
		} catch (\Aws\S3\Exception\S3Exception $e) {
			error_log('WPS3: S3 specific error: ' . $e->getMessage());
			return new WP_Error('s3_error', $e->getMessage());
		} catch (FileSizeException $e) {
			error_log('WPS3: File size error: ' . $e->getMessage());
			return new WP_Error('file_size_error', $e->getMessage());
		} catch (\Exception $e) {
			error_log('WPS3: General error: ' . $e->getMessage());
			return new WP_Error('general_error', $e->getMessage());
		}
	}

	/**
	 * Delete a file from S3 storage
	 *
	 * @param string $key The S3 object key
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	public function delete($key) {
		try {
			$this->s3_client->deleteObject([
				'Bucket' => $this->bucket_name,
				'Key' => $key
			]);
			return true;
		} catch (\Exception $e) {
			error_log('WPS3: Delete error: ' . $e->getMessage());
			return new WP_Error('delete_error', $e->getMessage());
		}
	}

	/**
	 * Get the URL for an S3 object
	 *
	 * @param string $key The S3 object key
	 * @return string|WP_Error The URL or WP_Error on failure
	 */
	public function getUrl($key) {
		try {
			$this->validate_url($key);
			return $this->s3_client->getObjectUrl($this->bucket_name, $key);
		} catch (\Exception $e) {
			error_log('WPS3: URL generation error: ' . $e->getMessage());
			return new WP_Error('url_error', $e->getMessage());
		}
	}

	/**
	 * Check if an object exists in S3
	 *
	 * @param string $key The S3 object key
	 * @return bool Whether the object exists
	 */
	public function exists($key) {
		try {
			return $this->s3_client->doesObjectExist($this->bucket_name, $key);
		} catch (\Exception $e) {
			error_log('WPS3: Existence check error: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Get cached bucket information
	 *
	 * @return array|false Bucket information or false if not cached
	 */
	private function get_cached_bucket_info() {
		$cache_key = 'wps3_bucket_info';
		$cached = wp_cache_get($cache_key);
		if (false === $cached) {
			try {
				$info = $this->s3_client->getBucketInfo();
				wp_cache_set($cache_key, $info, '', 3600);
				return $info;
			} catch (\Exception $e) {
				error_log('WPS3: Error getting bucket info: ' . $e->getMessage());
				return false;
			}
		}
		return $cached;
	}

	/**
	 * Resume a paused migration
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	public function resume_migration() {
		try {
			$last_position = get_option('wps3_migration_position');
			if (!$last_position) {
				return new WP_Error('no_resume_point', 'No migration to resume');
			}

			$status = get_option('wps3_migration_status');
			if ($status !== 'paused') {
				return new WP_Error('invalid_status', 'Migration is not paused');
			}

			return $this->process_batch($last_position);
		} catch (\Exception $e) {
			error_log('WPS3: Migration resume error: ' . $e->getMessage());
			return new WP_Error('resume_error', $e->getMessage());
		}
	}

	/**
	 * Process a batch of files for migration
	 *
	 * @param int $start_position Starting position in the file list
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	protected function process_batch($start_position) {
		try {
			$files = $this->get_all_files_recursive(wp_upload_dir()['path']);
					$total_files = count($files);
			$batch_size = WPS3_MAX_BATCH_SIZE;
			$end_position = min($start_position + $batch_size, $total_files);

			for ($i = $start_position; $i < $end_position; $i++) {
				$file = $files[$i];
				$result = $this->upload($file);
				
				if (is_wp_error($result)) {
					$this->add_log_entry("Error uploading file {$file}: " . $result->get_error_message(), 'error');
					continue;
				}

				$this->add_log_entry("Successfully uploaded file: {$file}", 'success');

				// Update progress
				$progress = ($i + 1) / $total_files * 100;
				set_transient('wps3_migration_progress', $progress, HOUR_IN_SECONDS);
			}

			// Update position
			if ($end_position < $total_files) {
				update_option('wps3_migration_position', $end_position);
				update_option('wps3_migration_status', 'in_progress');
			} else {
				update_option('wps3_migration_position', 0);
				update_option('wps3_migration_status', 'completed');
				delete_transient('wps3_migration_progress');
				$this->add_log_entry('Migration completed successfully', 'success');
			}

			return true;
		} catch (\Exception $e) {
			$this->add_log_entry('Batch processing error: ' . $e->getMessage(), 'error');
			error_log('WPS3: Batch processing error: ' . $e->getMessage());
			return new WP_Error('batch_error', $e->getMessage());
		}
	}

	/**
	 * Recover from an error during migration
	 *
	 * @param WP_Error $error The error to recover from
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	public function recover_from_error($error) {
		try {
			$error_code = $error->get_error_code();
			$error_message = $error->get_error_message();

			// Log the error
			error_log('WPS3: Recovery from error: ' . $error_code . ' - ' . $error_message);

			switch ($error_code) {
				case 's3_error':
					// Check S3 connection
					$this->validate_configuration();
					break;

				case 'file_size_error':
					// Skip the problematic file and continue
					$position = get_option('wps3_migration_position');
					update_option('wps3_migration_position', $position + 1);
					break;

				case 'general_error':
					// Pause migration for manual review
					update_option('wps3_migration_status', 'paused');
					break;

				default:
					// Unknown error, pause migration
					update_option('wps3_migration_status', 'paused');
					return new WP_Error('unknown_error', 'Unknown error type: ' . $error_code);
			}

			return true;
		} catch (\Exception $e) {
			error_log('WPS3: Error recovery failed: ' . $e->getMessage());
			return new WP_Error('recovery_failed', $e->getMessage());
		}
	}

	/**
	 * Add a log entry to the database
	 *
	 * @param string $message The log message
	 * @param string $type The log type (info, error, success)
	 * @return bool Whether the log was successfully added
	 */
	private function add_log_entry($message, $type = 'info') {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'wps3_logs';
		
		// Create table if it doesn't exist
		$this->create_log_table();
		
		$result = $wpdb->insert(

			$table_name,
			[
				'message' => $message,
				'type' => $type,
				'created_at' => current_time('mysql')
			],
			['%s', '%s', '%s']
		);
		
		return $result !== false;
	}
	
	/**
	 * Create the logs table if it doesn't exist
	 */
	private function create_log_table() {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'wps3_logs';
		$charset_collate = $wpdb->get_charset_collate();
		
		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			message text NOT NULL,
			type varchar(20) NOT NULL DEFAULT 'info',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";
		
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}
	
	/**
	 * Get recent log entries
	 *
	 * @param int $limit Number of entries to retrieve
	 * @return array Array of log entries
	 */
 private function get_recent_logs($limit = 100) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'wps3_logs';
		
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d",
				$limit
			)
		);
	}
	
	/**
	 * Clear all logs
	 */
	private function clear_logs() {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'wps3_logs';
		$wpdb->query("TRUNCATE TABLE $table_name");
	}

	/**
	 * Rewrite image src URLs in the media library
	 *
	 * @param array|false $image Array of image data, or boolean false if no image.
	 * @param int $attachment_id Image attachment ID.
	 * @param string|array $size Requested size.
	 * @param bool $icon Whether the image should be treated as an icon.
	 * @return array|false Modified image data or false if no image.
	 */
	public function rewrite_image_src($image, $attachment_id, $size, $icon) {
		if (!$image) {
			return $image;
		}

		$s3_info = get_post_meta($attachment_id, 'wps3_s3_info', true);
		if (!empty($s3_info) && isset($s3_info['key'])) {
			$cdn_domain = get_option('wps3_cdn_domain');
			if (!empty($cdn_domain)) {
				$cdn_base = rtrim($cdn_domain, '/');
				$s3_key = ltrim($s3_info['key'], '/');
				$image[0] = $cdn_base . '/' . $s3_key;
			} elseif (isset($s3_info['url'])) {
				$image[0] = $s3_info['url'];
			} else {
				$image[0] = $this->get_s3_url($s3_info['key']);
			}
		}

		return $image;
	}

	/**
	 * Rewrite srcset URLs for responsive images
	 *
	 * @param array $sources Array of image sources.
	 * @param array $size_array Array of width and height values.
	 * @param string $image_src The 'src' of the image.
	 * @param array $image_meta The image meta data as returned by 'wp_get_attachment_metadata()'.
	 * @param int $attachment_id Image attachment ID.
	 * @return array Modified sources array.
	 */
	public function rewrite_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
		if (empty($sources)) {
			return $sources;
		}

		$s3_info = get_post_meta($attachment_id, 'wps3_s3_info', true);
		if (!empty($s3_info) && isset($s3_info['key'])) {
			$cdn_domain = get_option('wps3_cdn_domain');
			$base_url = '';
			
			if (!empty($cdn_domain)) {
				$base_url = rtrim($cdn_domain, '/');
			} elseif (isset($s3_info['url'])) {
				$base_url = dirname($s3_info['url']);
			} else {
				$base_url = dirname($this->get_s3_url($s3_info['key']));
			}

			foreach ($sources as &$source) {
				if (isset($source['url'])) {
					$filename = basename($source['url']);
					$source['url'] = $base_url . '/' . $filename;
				}
			}
		}

		return $sources;
	}

	/**
	 * Rewrite image attributes in the media library
	 *
	 * @param array $attr Array of attribute values.
	 * @param WP_Post $attachment Image attachment post.
	 * @param string|array $size Requested size.
	 * @return array Modified attributes array.
	 */
	public function rewrite_image_attributes($attr, $attachment, $size) {
		if (isset($attr['src'])) {
			$s3_info = get_post_meta($attachment->ID, 'wps3_s3_info', true);
			if (!empty($s3_info) && isset($s3_info['key'])) {
				$cdn_domain = get_option('wps3_cdn_domain');
				if (!empty($cdn_domain)) {
					$cdn_base = rtrim($cdn_domain, '/');
					$s3_key = ltrim($s3_info['key'], '/');
					$attr['src'] = $cdn_base . '/' . $s3_key;
				} elseif (isset($s3_info['url'])) {
					$attr['src'] = $s3_info['url'];
				} else {
					$attr['src'] = $this->get_s3_url($s3_info['key']);
				}
			}
		}

		return $attr;
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
	
	// Add Settings link to plugins page
	add_filter( 'plugin_action_links_' . plugin_basename( WPS3_PLUGIN_FILE ), function( $links ) {
		$settings_link = '<a href="' . admin_url( 'options-general.php?page=wps3-settings' ) . '">' . __( 'Settings', 'wps3' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	});
}

// Initialize the plugin when WordPress is loaded.
add_action('plugins_loaded', 'register_wps3');

// Register activation, deactivation, and uninstall hooks
register_activation_hook(WPS3_PLUGIN_FILE, ['WPS3', 'activate']);
register_deactivation_hook(WPS3_PLUGIN_FILE, ['WPS3', 'deactivate']);
register_uninstall_hook(WPS3_PLUGIN_FILE, ['WPS3', 'uninstall']);
?>
