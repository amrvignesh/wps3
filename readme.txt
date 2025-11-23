=== WPS3 ===
Contributors: amrvignesh
Tags: s3, storage, uploads, aws, cloud
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.0
Stable tag: 0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload WordPress uploads directory to S3 compatible storage.

== Description ==

WPS3 is a WordPress plugin that allows you to offload your media uploads to any S3-compatible storage service. This includes Amazon S3, DigitalOcean Spaces, MinIO, and other S3-compatible services.

= Features =

* Offload media uploads to S3-compatible storage
* Support for custom S3 endpoints
* Automatic URL rewriting
* Migration tool for existing files
* Progress tracking and logging
* Error handling and recovery
* Batch processing for large migrations

= Requirements =

* PHP 7.0 or higher
* WordPress 5.0 or higher
* S3-compatible storage service
* AWS SDK for PHP

== Installation ==

1. Upload the `wps3` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > S3 Uploads Offloader to configure your S3 settings

== Frequently Asked Questions ==

= What S3-compatible services are supported? =

The plugin supports any service that is compatible with the S3 API, including:
* Amazon S3
* DigitalOcean Spaces
* MinIO
* Backblaze B2
* Wasabi
* Telnyx
* And more...

= Do I need to keep local files? =

You can choose to delete local files after they are uploaded to S3. This is configurable in the plugin settings.

= How do I migrate existing files? =

Use the S3 Migration tool under the Media menu to migrate your existing files to S3.

== Screenshots ==

1. Plugin settings page
2. Migration tool interface
3. Progress tracking

== Changelog ==

= 0.2 =
* Added migration tool
* Added progress tracking
* Added error handling
* Added logging system
* Added batch processing

= 0.1 =
* Initial release

== Upgrade Notice ==

= 0.2 =
This version adds a migration tool, progress tracking, and improved error handling.

== Developer Documentation ==

The plugin provides several hooks for developers:

= Actions =

* `wps3_before_upload` - Fires before a file is uploaded to S3
* `wps3_after_upload` - Fires after a file is uploaded to S3

= Filters =

* `wps3_upload_options` - Modify upload options before sending to S3
* `wps3_file_url` - Modify the URL of a file stored in S3 
