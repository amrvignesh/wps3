=== WPS3 - S3 Uploads Offloader ===
Contributors: vigneshes
Donate link: https://gigillion.com/wps3
Tags: s3, uploads, offload, storage, aws, digitalocean, spaces
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.0
Stable tag: 0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload WordPress uploads directory to S3 compatible storage services like AWS S3, DigitalOcean Spaces, and more.

== Description ==

WPS3 is a powerful WordPress plugin that automatically offloads your media uploads to S3-compatible storage services. This helps reduce server storage usage and improves website performance by serving media files from a CDN-capable storage service.

= Features =

* Automatically upload new media files to S3-compatible storage
* Migrate existing media files to S3 storage
* Support for AWS S3, DigitalOcean Spaces, and other S3-compatible services
* Configurable folder structure within your bucket
* Optional deletion of local files after successful upload
* Bulk migration tool with progress tracking
* Seamless URL rewriting for media files
* Support for all WordPress image sizes

= Supported Storage Services =

* Amazon S3
* DigitalOcean Spaces
* Linode Object Storage
* Wasabi Hot Cloud Storage
* Any S3-compatible storage service

= Requirements =

* WordPress 5.0 or later
* PHP 7.0 or later
* S3-compatible storage account
* Basic server configuration knowledge

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/wps3` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to Settings > WPS3 to configure your S3 settings.
4. Enter your storage credentials and bucket information.
5. Test the connection and enable the plugin.
6. Use the migration tool to move existing files to S3 storage.

== Frequently Asked Questions ==

= Does this plugin work with AWS S3? =

Yes, this plugin works with AWS S3 and any S3-compatible storage service.

= Will my existing images be moved to S3? =

Yes, you can use the built-in migration tool to move existing media files to your S3 bucket.

= What happens if my S3 service is down? =

The plugin includes fallback mechanisms. If S3 is unavailable, WordPress will attempt to serve files locally if they exist.

= Can I use a custom domain/CDN? =

Yes, you can configure a custom endpoint URL that points to your CDN or custom domain.

= Is this plugin compatible with image optimization plugins? =

Yes, the plugin works with most image optimization plugins as it hooks into WordPress's standard upload process.

== Screenshots ==

1. Plugin settings page
2. Migration tool interface
3. S3 configuration options

== Changelog ==

= 0.2 =
* Added comprehensive error handling and logging
* Improved security with proper nonce verification
* Added bulk migration tool with progress tracking
* Enhanced S3 client configuration options
* Added support for custom endpoints
* Improved accessibility and user interface
* Added proper input sanitization and validation
* Fixed asset file loading issues

= 0.1 =
* Initial release
* Basic S3 upload functionality
* Settings page for configuration

== Upgrade Notice ==

= 0.2 =
This version includes important security improvements and new features. Please update your settings after upgrading.

== Security ==

This plugin follows WordPress security best practices:
* All user inputs are properly sanitized and validated
* CSRF protection using WordPress nonces
* Capability checks for admin functions
* Secure storage of sensitive credentials

== Support ==

For support and documentation, please visit our [GitHub repository](https://github.com/amrvignesh/wps3) or contact us through the WordPress.org support forums.