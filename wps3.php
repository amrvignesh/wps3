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
class S3_Uploads_Offloader
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
		$this->s3_client = new \Aws\S3\S3Client();

		// Load the plugin's settings.
		$this->bucket_name = get_option('s3_uploads_offloader_bucket_name');
		$this->bucket_region = get_option('s3_uploads_offloader_bucket_region');
		$this->bucket_folder = get_option('s3_uploads_offloader_bucket_folder');
	}

	/**
	 * Register the plugin's hooks.
	 */
	public function register_hooks()
	{
		add_action('wp_loaded', [$this, 'init']);
		add_action('wp_insert_attachment', [$this, 'upload_attachment']);
		add_action('delete_attachment', [$this, 'delete_attachment']);
	}

	/**
	 * Initialize the plugin.
	 */
	public function init()
	{
		// Check if the plugin is enabled.
		if (!get_option('s3_uploads_offloader_enabled')) {
			return;
		}

		// Check if the S3 bucket exists.
		if (!$this->s3_client->doesBucketExist($this->bucket_name)) {
			throw new \Exception('The S3 bucket does not exist.');
		}

		// Move existing files to the S3 bucket.
		$this->move_existing_files();

		// Intercept the upload process.
		add_filter('wp_handle_upload_overrides', [$this, 'upload_overrides']);
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
		$key = basename($file);
		$this->s3_client->putObject([
			'Bucket' => $this->bucket_name,
			'Key' => $key,
			'Body' => file_get_contents($file),
		]);
	}

	/**
	 * Override the WordPress upload process to upload files to the S3 bucket.
	 *
	 * @param array $overrides The upload overrides.
	 * @return array
	 */
	public function upload_overrides($overrides)
	{
		// Check if the plugin is enabled.
		if (!get_option('s3_uploads_offloader_enabled')) {
			return $overrides;
		}

		// Set the upload destination to the S3 bucket.
		$overrides['file_destination'] = $this->bucket_name . '/' . $this->bucket_folder;

		return $overrides;
	}

	/**
	 * Delete a file from the S3 bucket when it is deleted from WordPress.
	 *
	 * @param int $attachment_id The attachment ID.
	 */
	public function delete_attachment($attachment_id)
	{
		$attachment = get_attachment_metadata($attachment_id);

		if (!empty($attachment['file'])) {
			$this->s3_client->deleteObject([
				'Bucket' => $this->bucket_name,
				'Key' => $attachment['file'],
			]);
		}
	}

	/**
	 * Register the plugin's settings.
	 */
	public function register_settings()
	{
		add_settings_section(
			's3_uploads_offloader_section',
			__('S3 Uploads Offloader', 's3_uploads_offloader'),
			[$this, 'settings_section_callback'],
			's3_uploads_offloader'
		);

		add_settings_field(
			's3_uploads_offloader_bucket_name',
			__('S3 Bucket Name', 's3_uploads_offloader'),
			[$this, 'settings_field_bucket_name_callback'],
			's3_uploads_offloader',
			's3_uploads_offloader_section'
		);

		add_settings_field(
			's3_uploads_offloader_bucket_region',
			__('S3 Bucket Region', 's3_uploads_offloader'),
			[$this, 'settings_field_bucket_region_callback'],
			's3_uploads_offloader',
			's3_uploads_offloader_section'
		);

		add_settings_field(
			's3_uploads_offloader_bucket_folder',
			__('S3 Bucket Folder', 's3_uploads_offloader'),
			[$this, 'settings_field_bucket_folder_callback'],
			's3_uploads_offloader',
			's3_uploads_offloader_section'
		);

		register_setting(
			's3_uploads_offloader',
			's3_uploads_offloader_bucket_name',
			[$this, 'validate_bucket_name']
		);

		register_setting(
			's3_uploads_offloader',
			's3_uploads_offloader_bucket_region',
			[$this, 'validate_bucket_region']
		);

		register_setting(
			's3_uploads_offloader',
			's3_uploads_offloader_bucket_folder',
			[$this, 'validate_bucket_folder']
		);
	}

	/**
	 * Render the S3 Uploads Offloader settings section.
	 */
	public function settings_section_callback()
	{
		?>
		<p>
			<?php _e('This plugin allows you to offload all WordPress uploads to an S3-compatible storage service.', 's3_uploads_offloader'); ?>
		</p>
		<?php
	}

	/**
	 * Render the S3 Bucket Name settings field.
	 */
	public function settings_field_bucket_name_callback()
	{
		?>
		<input type="text" name="s3_uploads_offloader_bucket_name"
			value="<?php echo esc_attr(get_option('s3_uploads_offloader_bucket_name')); ?>" />
		<p class="description">
			<?php _e('The name of your S3 bucket.', 's3_uploads_offloader'); ?>
		</p>
		<?php
	}

	/**
	 * Render the S3 Bucket Region settings field.
	 */
	public function settings_field_bucket_region_callback()
	{
		?>
		<input type="text" name="s3_uploads_offloader_bucket_region"
			value="<?php echo esc_attr(get_option('s3_uploads_offloader_bucket_region')); ?>" />
		<p class="description">
			<?php _e('The region of your S3 bucket.', 's3_uploads_offloader'); ?>
		</p>
		<?php
	}

	/**
	 * Render the S3 Bucket Folder settings field.
	 */
	public function settings_field_bucket_folder_callback()
	{
		?>
		<input type="text" name="s3_uploads_offloader_bucket_folder"
			value="<?php echo esc_attr(get_option('s3_uploads_offloader_bucket_folder')); ?>" />
		<p class="description">
			<?php _e('The folder in your S3 bucket where files should be stored.', 's3_uploads_offloader'); ?>
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
				's3_uploads_offloader_bucket_name',
				'empty',
				__('Please enter a value for the S3 Bucket Name.', 's3_uploads_offloader')
			);

			return '';
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
				's3_uploads_offloader_bucket_region',
				'empty',
				__('Please enter a value for the S3 Bucket Region.', 's3_uploads_offloader')
			);

			return '';
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
				's3_uploads_offloader_bucket_folder',
				'empty',
				__('Please enter a value for the S3 Bucket Folder.', 's3_uploads_offloader')
			);

			return '';
		}

		return $value;
	}

} // end class S3_Uploads_Offloader

/**
 * Register the S3 Uploads Offloader plugin.
 */
function register_s3_uploads_offloader()
{
	register_plugin(
		's3_uploads_offloader',
		__FILE__,
		'Vignesh',
		'0.1',
		'https://github.com/amrvignesh/wps3'
	);
}

add_action('plugins_loaded', 'register_s3_uploads_offloader');
