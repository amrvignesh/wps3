# WPS3
## S3 Uploads Offloader
This plugin offloads all WordPress uploads to an S3-compatible storage service.

### Features
- Offloads all WordPress uploads to an S3-compatible storage service.
- Moves existing files from the local uploads folder to the S3 bucket.
- Overrides the WordPress upload process to upload files to the S3 bucket.
- Deletes files from the S3 bucket when they are deleted from WordPress.
- Provides a settings page to configure the plugin.

### Requirements
- WordPress 5.0 or later
- An S3-compatible storage service

### Installation
- Download the plugin from GitHub.
- In WordPress, go to Plugins > Add New.
- Click the "Upload Plugin" button.
- Select the plugin file that you downloaded in step 1.
- Click the "Install Now" button.
- Click the "Activate" button.

### Settings
- The plugin has a settings page that can be accessed from WordPress > Settings > S3 Uploads Offloader.
- The following settings are available:
- - S3 Bucket Name: The name of your S3 bucket.
- - S3 Bucket Region: The region of your S3 bucket.
- - S3 Bucket Folder: The folder in your S3 bucket where files should be stored.

### Usage
- Once the plugin is installed and configured, all new uploads will be stored in your S3 bucket. Existing files will be moved to the S3 bucket the next time you visit the WordPress uploads page.
- You can delete files from the S3 bucket by deleting them from WordPress. The plugin will automatically remove the files from the S3 bucket when you do this.

### Support
If you have any questions or problems with the plugin, please open an issue on GitHub.

### License
The plugin is licensed under the MIT License.

## Code Documentation

- The `WPS3` class is the main class of the plugin. It contains all of the logic for offloading uploads to S3.
- The `register_wps3()` function registers the plugin with WordPress.
- The `wp_loaded` hook is used to register the plugin's hooks.
- The `init()` method initializes the plugin.
- The `move_existing_files()` method moves existing files from the local uploads folder to the S3 bucket.
- The `upload_file()` method uploads a file to the S3 bucket.
- The `upload_overrides()` method overrides the WordPress upload process to upload files to the S3 bucket.
- The `delete_attachment()` method deletes a file from the S3 bucket when it is deleted from WordPress.
- The `register_settings()` method registers the plugin's settings.
- The `settings_section_callback()` method renders the plugin's settings section.
- The `settings_field_bucket_name_callback()` method renders the plugin's S3 Bucket Name settings field.
- The `settings_field_bucket_region_callback()` method renders the plugin's S3 Bucket Region settings field.
- The `settings_field_bucket_folder_callback()` method renders the plugin's S3 Bucket Folder settings field.
- The `validate_bucket_name()` method validates the S3 Bucket Name setting.
- The `validate_bucket_region()` method validates the S3 Bucket Region setting.
- The `validate_bucket_folder()` method validates the S3 Bucket Folder setting.
- Batch migrates existing files to S3 
### S3 Path Format 
// Bandwidth optimization benefits 
- Provides detailed timing statistics for migrations 
## Bandwidth Savings 
### CDN Integration 
- Integrates with CDNs for even better performance 
