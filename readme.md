# WPS3
## S3 Uploads Offloader
This plugin offloads all WordPress uploads to an S3-compatible storage service while maintaining proper URL references to serve media directly from S3, dramatically reducing your WordPress server's bandwidth usage.

### License
This plugin is licensed under the GNU General Public License v2 or later. See the [LICENSE](LICENSE) file for the full license text.

> **Note**: This project was previously licensed under the MIT License. The license was changed to GPL v2 or later to better align with WordPress plugin repository requirements and to ensure compatibility with WordPress core, which is also GPL-licensed.

### Primary Benefits
- **Bandwidth Reduction**: Media files are served directly from S3 to your visitors, not through your WordPress server
- **Cost Savings**: Significantly reduces hosting bandwidth costs by offloading large media files to S3
- **Performance Improvement**: Reduces load on your WordPress server by delegating media delivery to S3's infrastructure
- **Scalability**: S3's infrastructure can handle high traffic spikes better than most WordPress hosting environments

### Features
- Offloads all WordPress uploads to an S3-compatible storage service
- Supports any S3-compatible storage provider (AWS S3, DigitalOcean Spaces, MinIO, etc.)
- Batch migrates existing files from the WordPress uploads folder to S3 with progress tracking
- Automatically uploads new media to S3 as it's added to the WordPress media library
- Rewrites media URLs to serve files directly from S3 rather than your server
- Properly handles image sizes and thumbnails for all media
- Optionally deletes local files after successful S3 upload to save disk space
- Deletes files from S3 when they're deleted from WordPress
- Provides an intuitive settings page to configure your S3 connection
- Includes a dedicated migration dashboard with real-time progress tracking

### Requirements
- WordPress 5.0 or later
- PHP 7.0 or later
- An S3-compatible storage service (AWS S3, DigitalOcean Spaces, MinIO, etc.)
- Access and secret keys for your S3 service

### Installation
1. Download the plugin from GitHub.
2. In WordPress, go to Plugins > Add New.
3. Click the "Upload Plugin" button.
4. Select the plugin zip file that you downloaded in step 1.
5. Click the "Install Now" button.
6. Click the "Activate" button.

### Configuration
1. Go to Settings > S3 Uploads Offloader
2. Enable the plugin by checking "Enable S3 Uploads Offloader"
3. Enter your S3 storage path in the format: `s3://bucket-name/folder-path?region=region-name&endpoint=custom-endpoint`
   - For AWS S3: `s3://my-bucket/wp-uploads?region=us-west-2`
   - For custom S3 providers: `s3://my-bucket/wp-uploads?region=us-east-1&endpoint=https://s3.example.com`
4. Enter your Access Key and Secret Key
5. Optionally enable "Delete Local Files" to remove local copies after successful S3 upload
6. (Optional) Enable debug logging by checking "Enable Debug Logging" to help troubleshoot any issues. Debug logs will be written to the WordPress debug log file when WP_DEBUG is enabled.
7. Save your settings

### Migration
1. Go to Media > S3 Migration
2. Review the number of files that will be migrated
3. Click "Start Migration" to begin the process
4. The migration dashboard shows real-time progress with a visual progress bar
5. You can pause and resume the migration at any time
6. The migration log tracks successful uploads and any errors that occur

### How Bandwidth Offloading Works
When a visitor accesses your WordPress site:

1. Your WordPress server delivers the page HTML, CSS, and JavaScript
2. Media file URLs (images, videos, documents) point directly to your S3 bucket
3. The visitor's browser requests these files directly from S3, not from your server
4. Your WordPress server's bandwidth is only used for the core content, not the media files
5. Since media typically accounts for 60-80% of a website's bandwidth usage, this results in significant bandwidth savings

This direct delivery model is particularly effective for:
- Image-heavy websites and blogs
- Sites that host video content
- Portfolio and photography sites
- E-commerce stores with multiple product images
- Sites with limited hosting bandwidth allocations

### Usage
- After configuration, all new uploads will be automatically stored in your S3 bucket
- Existing media will be served from S3 once you've run the migration
- When using the media library, all URLs will point to your S3 storage instead of the local server
- If you enable "Delete Local Files," your server storage usage will be greatly reduced
- Your WordPress site will consume significantly less bandwidth since media is served directly from S3

### Support
If you have any questions or problems with the plugin, please open an issue on GitHub.

## Technical Documentation

### Main Components
- `WPS3` - Main plugin class that coordinates all functionality
- `register_hooks()` - Registers WordPress hooks for the plugin
- `init()` - Initializes the plugin after WordPress is loaded
- `upload_file()` - Uploads a single file to S3
- `rewrite_attachment_url()` - Rewrites WordPress media URLs to point to S3
- `rewrite_image_downsize()` - Handles image thumbnails and resized versions
- `upload_attachment()` - Automatically uploads new attachments to S3
- `delete_attachment()` - Removes files from S3 when deleted in WordPress
- `ajax_process_batch()` - Processes batches during migration

### S3 Path Format
The plugin uses a unified S3 path format that includes all necessary configuration in a single string:
```
s3://bucket-name/folder-path?region=region-name&endpoint=custom-endpoint
```

This format makes it easy to configure any S3-compatible storage provider by specifying the custom endpoint if needed.

### Batched Migration
The plugin implements a batch processing system for migrating files to S3, which:
- Prevents server overload during large migrations
- Provides detailed progress tracking
- Allows pausing and resuming migrations
- Reports errors for individual files
- Works asynchronously via AJAX for better user experience

### URL Rewriting System
The plugin's URL rewriting system ensures that:
- All media references in your content point directly to S3
- WordPress core functions that generate media URLs are intercepted to use S3 URLs
- Image srcset and sizes attributes for responsive images also use S3 URLs
- Both full-size images and thumbnails are properly served from S3
