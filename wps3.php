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
		$this->s3_client = new \Aws\S3\S3Client([
			'version' => 'latest',
			'region' => $this->bucket_region,
			'credentials' => [
				'key' => get_option('wps3_access_key'),
				'secret' => get_option('wps3_secret_key')
			]
		]);

		// Load the plugin's settings.
		$this->bucket_name = get_option('wps3_bucket_name');
		$this->bucket_region = get_option('wps3_bucket_region');
		$this->bucket_folder = get_option('wps3_bucket_folder');
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
	 */
	protected function upload_file($file)
	{
		$key = $this->bucket_folder . '/' . basename($file);
		$this->s3_client->putObject([
			'Bucket' => $this->bucket_name,
			'Key' => $key,
			'Body' => file_get_contents($file),
		]);
	}

	/**
	 * Override the WordPress upload process to upload files to the S3 bucket.
	 *
	 * @param array $file The uploaded file information.
	 * @return array
	 */
	public function upload_overrides($file)
	{
		// Check if the plugin is enabled.
		if (!get_option('wps3_enabled')) {
			return $file;
		}

		$upload_dir = wp_upload_dir();
		$file['url'] = $upload_dir['baseurl'] . '/' . $this->bucket_folder . '/' . $file['name'];

		return $file;
	}

	/**
	 * Delete a file from the S3 bucket when it is deleted from WordPress.
	 *
	 * @param int $attachment_id The attachment ID.
	 */
	public function delete_attachment($attachment_id)
	{
		$attachment = get_post_meta($attachment_id, '_wp_attached_file', true);

		if (!empty($attachment)) {
			$this->s3_client->deleteObject([
				'Bucket' => $this->bucket_name,
				'Key' => $attachment,
			]);
		}
	}

	/**
	 * Add the plugin's settings page to the admin menu.
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
	}

	/**
	 * Register the plugin's settings.
	 */
	public function register_settings()
	{
		add_settings_section(
			'wps3_section',
			__('S3 Uploads Offloader', 'wps3'),
			[$this, 'settings_section_callback'],
			'wps3-settings'
		);

		add_settings_field(
			'wps3_bucket_name',
			__('S3 Bucket Name', 'wps3'),
			[$this, 'settings_field_bucket_name_callback'],
			'wps3-settings',
			'wps3_section'
		);

		add_settings_field(
			'wps3_bucket_region',
			__('S3 Bucket Region', 'wps3'),
			[$this, 'settings_field_bucket_region_callback'],
			'wps3-settings',
			'wps3_section'
		);

		add_settings_field(
			'wps3_bucket_folder',
			__('S3 Bucket Folder', 'wps3'),
			[$this, 'settings_field_bucket_folder_callback'],
			'wps3-settings',
			'wps3_section'
		);

		register_setting(
			'wps3',
			'wps3_bucket_name',
			['sanitize_callback' => 'sanitize_text_field']
		);

		register_setting(
			'wps3',
			'wps3_bucket_region',
			['sanitize_callback' => 'sanitize_text_field']
		);

		register_setting(
			'wps3',
			'wps3_bucket_folder',
			['sanitize_callback' => 'sanitize_text_field']
		);
	}

	/**
	 * Render the S3 Uploads Offloader settings section.
	 */
	public function settings_section_callback()
	{
		?>
		<p>
			<?php _e('This plugin allows you to offload all WordPress uploads to an S3-compatible storage service.', 'wps3'); ?>
		</p>
		<?php
	}

	/**
	 * Render the S3 Bucket Name settings field.
	 */
	public function settings_field_bucket_name_callback()
	{
		?>
		<input type="text" name="wps3_bucket_name"
			value="<?php echo esc_attr(get_option('wps3_bucket_name')); ?>" />
		<p class="description">
			<?php _e('The name of your S3 bucket.', 'wps3'); ?>
		</p>
		<?php
	}

	/**
	 * Render the S3 Bucket Region settings field.
	 */
	public function settings_field_bucket_region_callback()
	{
		?>
		<input type="text" name="wps3_bucket_region"
			value="<?php echo esc_attr(get_option('wps3_bucket_region')); ?>" />
		<p class="description">
			<?php _e('The region of your S3 bucket.', 'wps3'); ?>
		</p>
		<?php
	}

	/**
	 * Render the S3 Bucket Folder settings field.
	 */
	public function settings_field_bucket_folder_callback()
	{
		?>
		<input type="text" name="wps3_bucket_folder"
			value="<?php echo esc_attr(get_option('wps3_bucket_folder')); ?>" />
		<p class="description">
			<?php _e('The folder in your S3 bucket where files should be stored.', 'wps3'); ?>
		</p>
		<?php
	}

	/**
	 * Validate the S3 Bucket Name setting.
	 *
	 * @param string $value The value of the setting.
	 * @return string
	 */
	public function validate_bucket_name($value)
	{
		if (empty($value)) {
			add_settings_error(
				'wps3_bucket_name',
				'empty',
				__('Please enter a value for the S3 Bucket Name.', 'wps3')
			);
		}

		return $value;
	}

	/**
	 * Validate the S3 Bucket Region setting.
	 *
	 * @param string $value The value of the setting.
	 * @return string
	 */
	public function validate_bucket_region($value)
	{
		if (empty($value)) {
			add_settings_error(
				'wps3_bucket_region',
				'empty',
				__('Please enter a value for the S3 Bucket Region.', 'wps3')
			);
		}

		return $value;
	}

	/**
	 * Validate the S3 Bucket Folder setting.
	 *
	 * @param string $value The value of the setting.
	 * @return string
	 */
	public function validate_bucket_folder($value)
	{
		if (empty($value)) {
			add_settings_error(
				'wps3_bucket_folder',
				'empty',
				__('Please enter a value for the S3 Bucket Folder.', 'wps3')
			);
		}

		return $value;
	}

	/**
	 * Render the settings page for the plugin.
	 */
	public function render_settings_page()
	{
		?>
		<div class="wrap">
			<h1><?php _e('S3 Uploads Offloader Settings', 'wps3'); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields('wps3');
				do_settings_sections('wps3-settings');
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

} // end class WPS3

/**
 * Register the S3 Uploads Offloader plugin.
 */
function register_wps3()
{
	$wps3 = new WPS3();
	$wps3->register_hooks();
	$wps3->add_admin_menu();
}

add_action('plugins_loaded', 'register_wps3');
