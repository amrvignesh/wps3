<?php
/*
Plugin Name: WPS3
Description: Offload WordPress uploads directory to S3 compatible storage
Version: 0.1
Author: Vignesh AMR
*/

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

require_once 'aws/aws-autoloader.php';

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
	 * The constructor.
	 */
	public function __construct()
	{
		// Load the plugin's settings.
		$this->bucket_name = get_option('wps3_bucket_name');
		$this->bucket_region = get_option('wps3_bucket_region');
		$this->bucket_folder = get_option('wps3_bucket_folder');
		if (!empty($this->bucket_region)) {
			$this->s3_client = new \Aws\S3\S3Client([
				'version' => 'latest',
				'region' => $this->bucket_region,
				'credentials' => [
					'key' => get_option('wps3_access_key'),
					'secret' => get_option('wps3_secret_key')
				]
			]);
		}
	}

	/**
	 * Register the plugin's hooks.
	 */
	public function register_hooks()
	{
		add_action('wp_loaded', [$this, 'init']);
		add_action('wp_insert_attachment', [$this, 'upload_attachment']);
		add_action('delete_attachment', [$this, 'delete_attachment']);
		add_action('admin_menu', [$this, 'add_admin_menu']);
		add_action('admin_init', [$this, 'register_settings']);
	}

	/**
	 * Initialize the plugin.
	 */
	public function init()
	{
		// Check if the plugin is enabled.
		if (!get_option('wps3_enabled')) {
			return;
		}

		// Check if the S3 bucket exists.
		if (!$this->s3_client->doesBucketExist($this->bucket_name)) {
			throw new \Exception('The S3 bucket does not exist.');
		}

		// Move existing files to the S3 bucket.
		$this->move_existing_files();

		// Intercept the upload process.
		add_filter('wp_handle_upload', [$this, 'upload_overrides']);
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
	 **/
	// Include necessary WordPress files
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	class WPS3 {

		private $bucket_name;
		private $bucket_region;
		private $bucket_folder;
		private $s3_client;

		public function __construct() {
			$this->bucket_name = get_option('wps3_bucket_name');
			$this->bucket_region = get_option('wps3_bucket_region');
			$this->bucket_folder = get_option('wps3_bucket_folder');

			// Initialize S3 client
			$this->s3_client = new Aws\S3\S3Client([
				'region' => $this->bucket_region,
				'version' => 'latest',
			]);
		}

		/**
		 * Register hooks for the plugin.
		 */
		public function register_hooks() {
			add_filter('wp_handle_upload', [$this, 'upload_overrides']);
			add_action('delete_attachment', [$this, 'delete_attachment']);
			add_action('admin_menu', [$this, 'add_admin_menu']);
			add_action('admin_init', [$this, 'register_settings']);
		}

		/**
		 * Upload a file to the S3 bucket.
		 *
		 * @param string $file The path to the file to upload.
		 */
		protected function upload_file($file) {
			$key = $this->bucket_folder . '/' . basename($file);
			$this->s3_client->putObject([
				'Bucket' => $this->bucket_name,
				'Key' => $key,
				'Body' => file_get_contents($file),
			]);
		}

		/**
		 * Override the WordPress upload process to upload files to the S3 bucket.
		<?php
		/**
		 * Plugin Name: S3 Uploads Offloader
		 * Plugin URI: https://example.com/plugins/s3-uploads-offloader/
		 * Description: Offload WordPress uploads to an S3-compatible storage service.
		 * Version: 1.0.0
		 * Author: Your Name
		 * Author URI: https://example.com/
		 * License: GPL2
		 */

		if ( ! defined( 'ABSPATH' ) ) {
			exit; // Exit if accessed directly.
		}

		class WPS3 {

			private $bucket_name;
			private $bucket_region;
			private $bucket_folder;
			private $s3_client;

			public function __construct() {
				$this->bucket_name    = get_option( 'wps3_bucket_name' );
				$this->bucket_region  = get_option( 'wps3_bucket_region' );
				$this->bucket_folder  = get_option( 'wps3_bucket_folder' );
				$this->s3_client      = $this->get_s3_client();
			}

			public function register_hooks() {
				add_filter( 'wp_handle_upload', array( $this, 'upload_overrides' ) );
				add_action( 'delete_attachment', array( $this, 'delete_attachment' ) );
				add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
				add_action( 'admin_init', array( $this, 'register_settings' ) );
			}

			public function upload_overrides( $file ) {
				// Check if the plugin is enabled.
				if ( ! get_option( 'wps3_enabled' ) ) {
					return $file;
				}

				$upload_dir      = wp_upload_dir();
				$file['url']     = $upload_dir['baseurl'] . '/' . $this->bucket_folder . '/' . $file['name'];
				$file['type']    = $this->get_mime_type( $file['file'] );
				$file['file']    = $this->upload_file( $file['file'] );
				$file['size']    = filesize( $file['file'] );
				$file['is_s3']   = true;
				$file['s3_info'] = array(
					'bucket' => $this->bucket_name,
					'key'    => $this->bucket_folder . '/' . $file['name'],
					'url'    => $file['url'],
				);

				return $file;
			}

			public function delete_attachment( $attachment_id ) {
				$attachment = get_post_meta( $attachment_id, '_wp_attached_file', true );

				if ( ! empty( $attachment ) ) {
					$this->s3_client->deleteObject(
						array(
							'Bucket' => $this->bucket_name,
							'Key'    => $this->bucket_folder . '/' . basename( $attachment ),
						)
					);
				}
			}

			public function add_admin_menu() {
				add_options_page(
					'S3 Uploads Offloader Settings',
					'S3 Uploads Offloader',
					'manage_options',
					'wps3-settings',
					array( $this, 'render_settings_page' )
				);
			}

			public function register_settings() {
				add_settings_section(
					'wps3_section',
					__( 'S3 Uploads Offloader', 'wps3' ),
					array( $this, 'settings_section_callback' ),
					'wps3'
				);

				add_settings_field(
					'wps3_bucket_name',
					__( 'S3 Bucket Name', 'wps3' ),
					array( $this, 'settings_field_bucket_name_callback' ),
					'wps3',
					'wps3_section'
				);

				add_settings_field(
					'wps3_bucket_region',
					__( 'S3 Bucket Region', 'wps3' ),
					array( $this, 'settings_field_bucket_region_callback' ),
					'wps3',
					'wps3_section'
				);

				add_settings_field(
					'wps3_bucket_folder',
					__( 'S3 Bucket Folder', 'wps3' ),
					array( $this, 'settings_field_bucket_folder_callback' ),
					'wps3',
					'wps3_section'
				);

				register_setting(
					'wps3',
					'wps3_bucket_name',
					array( $this, 'validate_bucket_name' )
				);

				register_setting(
					'wps3',
					'wps3_bucket_region',
					array( $this, 'validate_bucket_region' )
				);

				register_setting(
					'wps3',
					'wps3_bucket_folder',
					array( $this, 'validate_bucket_folder' )
				);
			}

			public function settings_section_callback() {
				?>
				<p>
					<?php _e( 'This plugin allows you to offload all WordPress uploads to an S3-compatible storage service.', 'wps3' ); ?>
				</p>
				<?php
			}

			public function settings_field_bucket_name_callback() {
				?>
				<input type="text" name="wps3_bucket_name" value="<?php echo esc_attr( get_option( 'wps3_bucket_name' ) ); ?>" />
				<p class="description">
					<?php _e( 'The name of your S3 bucket.', 'wps3' ); ?>
				</p>
				<?php
			}

			public function settings_field_bucket_region_callback() {
				?>
				<input type="text" name="wps3_bucket_region" value="<?php echo esc_attr( get_option( 'wps3_bucket_region' ) ); ?>" />
				<p class="description">
					<?php _e( 'The region of your S3 bucket.', 'wps3' ); ?>
				</p>
				<?php
			}

			public function settings_field_bucket_folder_callback() {
				?>
				<input type="text" name="wps3_bucket_folder" value="<?php echo esc_attr( get_option( 'wps3_bucket_folder' ) ); ?>" />
				<p class="description">
					<?php _e( 'The folder in your S3 bucket where files should be stored.', 'wps3' ); ?>
				</p>
				<?php
			}

			public function validate_bucket_name( $value ) {
				if ( empty( $value ) ) {
					add_settings_error(
						'wps3_bucket_name',
						'empty',
						__( 'Please enter a value for the S3 Bucket Name.', 'wps3' )
					);
				}

				return $value;
			}

			public function validate_bucket_region( $value ) {
				if ( empty( $value ) ) {
					add_settings_error(
						'wps3_bucket_region',
						'empty',
						__( 'Please enter a value for the S3 Bucket Region.', 'wps3' )
					);
				}

				return $value;
			}

			public function validate_bucket_folder( $value ) {
				if ( empty( $value ) ) {
					add_settings_error(
						'wps3_bucket_folder',
						'empty',
						__( 'Please enter a value for the S3 Bucket Folder.', 'wps3' )
					);
				}

				return $value;
			}

			public function render_settings_page() {
				?>
				<div class="wrap">
					<h1>
						<?php _e( 'S3 Uploads Offloader Settings', 'wps3' ); ?>
					</h1>
					<form method="post" action="options.php">
						<?php
						settings_fields( 'wps3' );
						do_settings_sections( 'wps3' );
						submit_button();
						?>
					</form>
				</div>
				<?php
			}

			private function get_s3_client() {
				require_once ABSPATH . 'wp-content/plugins/s3-uploads-offloader/vendor/autoload.php';

				$credentials = new Aws\Credentials\Credentials(
					get_option( 'wps3_access_key' ),
					get_option( 'wps3_secret_key' )
				);

				$params = array(
					'credentials' => $credentials,
					'region'      => $this->bucket_region,
					'version'     => 'latest',
				);

				return new Aws\S3\S3Client( $params );
			}

			private function upload_file( $file_path ) {
				$file_name = basename( $file_path );
				$key       = $this->bucket_folder . '/' . $file_name;

				try {
					$result = $this->s3_client->putObject(
						array(
							'Bucket' => $this->bucket_name,
							'Key'    => $key,
							'SourceFile' => $file_path,
							'ACL'    => 'public-read',
						)
					);

					return $result['ObjectURL'];
				} catch ( Exception $e ) {
					error_log( 'Error uploading file to S3: ' . $e->getMessage() );
					return $file_path;
				}
			}

			private function get_mime_type( $file_path ) {
				$mime_type = wp_check_filetype( $file_path );

				if ( ! empty( $mime_type['type'] ) ) {
					return $mime_type['type'];
				}

				return 'application/octet-stream';
			}

		}

		function register_wps3() {
			$wps3 = new WPS3();
			$wps3->register_hooks();
		}

		add_action( 'plugins_loaded', 'register_wps3' );

		function replace_url_with_s3_url($local_url) {
			// Check if the file is a local upload. If not, return original URL.
			$uploads_dir = wp_upload_dir();
			if (false === strpos($local_url, $uploads_dir['baseurl'] . '/')) {
				return $local_url;
			}
		
			// Get the file path relative to the WordPress uploads directory
			$file_path = str_replace($uploads_dir['baseurl'] . '/', '', $local_url);
		
			// Construct the S3 URL using the bucket name and file path
			$s3_url = 'https://s3.' . AWS_REGION . '.amazonaws.com/' . AWS_BUCKET_NAME . '/' . $file_path;
		
			return $s3_url;
		}
		add_filter('wp_get_attachment_url', 'replace_url_with_s3_url');
/ * * 
   *   A J A X   h a n d l e r   f o r   s t a r t i n g   m i g r a t i o n . 
   * /  
 / /   M i g r a t i o n   s t a t u s   t r a c k i n g   v a r i a b l e s  
 /** 
 * AJAX handler for starting migration. 
 */ 
// Migration status tracking variables 
/** 
 * AJAX handler for starting migration. 
 */ 
// Migration status tracking variables 
protected $batch_size = 5; 
try { } catch (Exception $e) { } 
// URL rewriting constants for S3 
// Verify nonce and permissions 
// Support for custom S3 endpoint URLs 
// Option to delete local files after upload 
function rewrite_image_downsize() { } 
load_plugin_textdomain('wps3'); 
register_activation_hook(__FILE__, 'wps3_activate'); 
register_deactivation_hook(__FILE__, 'wps3_deactivate'); 
// Clean up settings on uninstall 
// Support for additional file types 
// Check PHP version compatibility 
try { $this->s3_client = new S3Client(); } catch(Exception $e) { } 
add_filter('plugin_action_links', 'wps3_settings_link'); 
<select name=\"bucket_region\"> 
protected function parse_s3_path() { } 
if (!$this->s3_client->doesBucketExist($bucket)) { } 
// Auto-retry for failed uploads 
function get_mime_type($file) { } 
<button type=\"button\" id=\"test-connection\">Test Connection</button> 
add_action('wp_ajax_wps3_test_connection', 'test_connection'); 
<input type=\"number\" name=\"batch_size\" min=\"1\" max=\"50\"> 
protected $log_level = 'info'; 
function filterByType($files, $type) { } 
<h3>Advanced Options</h3> 
<input type=\"text\" name=\"file_prefix\"> 
// Track batch processing time 
// Add support for S3 transfer acceleration 
<input type=\"checkbox\" name=\"use_acceleration\"> 
function formatFileSize($bytes) { } 
<div class=\"file-count\">Total Files: <span id=\"total-files\">0</span></div> 
<div class=\"migration-summary\"></div> 
