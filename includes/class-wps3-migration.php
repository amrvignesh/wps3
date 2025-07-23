<?php

/**
 * Class WPS3_Migration
 */
class WPS3_Migration {

    /**
     * S3 client.
     *
     * @var WPS3_S3_Client
     */
    private $s3_client;

    /**
     * WPS3_Migration constructor.
     *
     * @param WPS3_S3_Client $s3_client
     */
    public function __construct(WPS3_S3_Client $s3_client) {
        $this->s3_client = $s3_client;

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_wps3_start_migration', [$this, 'ajax_start_migration']);
        add_action('wp_ajax_wps3_process_batch', [$this, 'ajax_process_batch']);
        add_action('wp_ajax_wps3_get_migration_status', [$this, 'ajax_get_migration_status']);
		add_action('wp_ajax_wps3_pause_migration', [$this, 'ajax_pause_migration']);
        add_action('wp_ajax_wps3_reset_migration', [$this, 'ajax_reset_migration']);
    }

    /**
     * Add admin menu.
     */
    public function add_admin_menu() {
        add_media_page(
            'S3 Migration',
            'S3 Migration',
            'manage_options',
            'wps3-migration',
            [$this, 'render_migration_page']
        );
    }

    /**
     * Enqueue admin scripts.
     *
     * @param string $hook
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'media_page_wps3-migration') {
            return;
        }

        wp_enqueue_script(
            'wps3-admin-js',
            plugin_dir_url(__FILE__) . '../assets/js/admin.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script(
            'wps3-admin-js',
            'wps3_ajax',
            [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('wps3_ajax_nonce'),
				'process_batch_delay' => 1000,
            ]
        );

        wp_enqueue_style(
            'wps3-admin-css',
            plugin_dir_url(__FILE__) . '../assets/css/admin.css',
            [],
            '1.0.0'
        );
    }

    /**
     * Render migration page.
     */
    public function render_migration_page() {
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
                    <?php if ($migration_running) : ?>
                        <button type="button" id="wps3-pause-migration" class="button button-primary" aria-label="<?php esc_attr_e('Pause the migration process', 'wps3'); ?>">
                            <?php _e('Pause Migration', 'wps3'); ?>
                        </button>
                    <?php else : ?>
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
     * AJAX handler for starting migration.
     */
    public function ajax_start_migration() {
        check_ajax_referer('wps3_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        $reset = isset($_POST['reset']) && $_POST['reset'] === 'true';

        if ($reset) {
            update_option('wps3_migrated_files', 0);
            update_option('wps3_migration_file_list', []);
            delete_transient('wps3_migration_errors');
        }

        update_option('wps3_migration_running', true);

        wp_send_json_success(['message' => 'Migration started.']);
    }

    /**
     * AJAX handler for processing a batch.
     */
    public function ajax_process_batch() {
        check_ajax_referer('wps3_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        if (!get_option('wps3_migration_running', false)) {
            wp_send_json_error(['message' => 'Migration is not running.']);
        }

        $file_list = get_option('wps3_migration_file_list', []);
        if (empty($file_list)) {
            // Use the new method that only gets WordPress attachments
            $file_list = $this->get_files_needing_migration();
            update_option('wps3_migration_file_list', $file_list);
            $this->wps3_log("Found " . count($file_list) . " files needing migration", 'info');
        }

        $batch_size = apply_filters('wps3_migration_batch_size', 5); // Reduced for better stability
        $migrated_files_count = get_option('wps3_migrated_files', 0);
        $files_to_process = array_slice($file_list, $migrated_files_count, $batch_size);

        $errors = get_transient('wps3_migration_errors') ?: [];
        $processed_count = 0;
        $skipped_count = 0;
        $success_count = 0;

        foreach ($files_to_process as $file_path) {
            if ($this->is_file_migrated($file_path)) {
                $skipped_count++;
                $processed_count++;
                continue;
            }

            // Fire action before migration
            do_action('wps3_before_upload', $file_path, '', ['migration' => true]);

            try {
                $key = $this->s3_client->upload_file($file_path);
                if ($key) {
                    $this->update_attachment_s3_meta($file_path, $key);
                    
                    // Also migrate thumbnails for this attachment
                    $this->migrate_attachment_thumbnails($file_path);
                    
                    $success_count++;
                    $this->wps3_log("Successfully migrated: " . basename($file_path), 'info');
                } else {
                    $error_msg = 'S3 upload failed';
                    $errors[] = ['path' => $file_path, 'error' => $error_msg];
                    $this->wps3_log("Failed to migrate: " . basename($file_path) . " - " . $error_msg, 'error');
                }
            } catch (Exception $e) {
                $error_msg = 'Exception: ' . $e->getMessage();
                $errors[] = ['path' => $file_path, 'error' => $error_msg];
                $this->wps3_log("Exception migrating: " . basename($file_path) . " - " . $error_msg, 'error');
            }
            
            $processed_count++;
        }

        $new_migrated_count = $migrated_files_count + $processed_count;
        update_option('wps3_migrated_files', $new_migrated_count);
        set_transient('wps3_migration_errors', $errors, DAY_IN_SECONDS);

        $total_files = count($file_list);
        $complete = $new_migrated_count >= $total_files;

        if ($complete) {
            update_option('wps3_migration_running', false);
            update_option('wps3_migration_file_list', []);
            $this->wps3_log("Legacy migration completed! Processed {$new_migrated_count} of {$total_files} files", 'info');
        }

        wp_send_json_success([
            'migrated_files' => $new_migrated_count,
            'total_files'    => $total_files,
            'percent_complete' => $total_files > 0 ? round(($new_migrated_count / $total_files) * 100) : 100,
            'complete'       => $complete,
            'error_count'    => count($errors),
            'errors'         => array_slice($errors, -10), // Return last 10 errors
            'message'        => "Processed {$processed_count} files. Skipped {$skipped_count} already migrated files.",
        ]);
    }

    /**
     * AJAX handler for getting migration status.
     */
    public function ajax_get_migration_status() {
        check_ajax_referer('wps3_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        $total_files = $this->count_files_to_migrate();
        $migrated_files = get_option('wps3_migrated_files', 0);
        $migration_running = get_option('wps3_migration_running', false);
        $percent_complete = $total_files > 0 ? round(($migrated_files / $total_files) * 100) : 0;

        wp_send_json_success([
            'migrated_files' => $migrated_files,
            'total_files'    => $total_files,
            'percent_complete' => $percent_complete,
            'migration_running' => $migration_running,
        ]);
    }

    /**
     * AJAX handler for pausing migration.
     */
	public function ajax_pause_migration() {
		check_ajax_referer('wps3_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        update_option('wps3_migration_running', false);
        $this->wps3_log('Migration paused by user', 'info');

        wp_send_json_success(['message' => 'Migration paused.']);
	}

    /**
     * AJAX handler for resetting migration.
     */
    public function ajax_reset_migration() {
        check_ajax_referer('wps3_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        // Stop migration
        update_option('wps3_migration_running', false);
        
        // Reset counters and file list
        update_option('wps3_migrated_files', 0);
        update_option('wps3_migration_file_list', []);
        
        // Clear errors
        delete_transient('wps3_migration_errors');
        
        $this->wps3_log('Migration reset by user', 'info');

        wp_send_json_success(['message' => 'Migration reset successfully.']);
    }

    /**
     * Count files to migrate.
     *
     * @return int
     */
    private function count_files_to_migrate() {
        $file_list = get_option('wps3_migration_file_list', []);
        if (!empty($file_list)) {
            return count($file_list);
        }
        
        // Use the new method that only counts WordPress attachments
        return count($this->get_files_needing_migration());
    }

    /**
     * Get all files in a directory recursively.
     *
     * @param string $dir
     * @return array
     */
    private function get_all_files_recursive($dir) {
        $files = [];
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'doc', 'docx', 'mp4', 'mp3', 'zip'];
        
        try {
            $dir_iterator = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
            $iterator = new RecursiveIteratorIterator($dir_iterator, RecursiveIteratorIterator::SELF_FIRST);

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
                    
                    // Only include files with allowed extensions
                    if (in_array($extension, $allowed_extensions)) {
                        $files[] = $file->getPathname();
                    }
                }
            }
        } catch (Exception $e) {
            $this->wps3_log("Error scanning directory {$dir}: " . $e->getMessage(), 'error');
        }

        return $files;
    }

    /**
     * Update attachment S3 meta.
     *
     * @param string $file_path
     * @param string $s3_key
     */
    private function update_attachment_s3_meta($file_path, $s3_key) {
        global $wpdb;
        
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace(trailingslashit($upload_dir['basedir']), '', $file_path);
        
        // First try to find by _wp_attached_file meta
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
            $relative_path
        ));
        
        // If not found, try to find by filename in the relative path
        if (!$attachment_id) {
            $filename = basename($relative_path);
            $attachment_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
                '%' . $wpdb->esc_like($filename)
            ));
        }

        if ($attachment_id) {
            $s3_info = [
                'bucket' => $this->s3_client->get_bucket_name(),
                'key'    => $s3_key,
                'url'    => $this->s3_client->get_s3_url($s3_key),
            ];
            
            update_post_meta($attachment_id, 'wps3_s3_info', $s3_info);
            
            $this->wps3_log("Updated S3 metadata for attachment ID {$attachment_id}: {$relative_path}", 'info');
            
            // Fire action after migration
            do_action('wps3_after_upload', $file_path, $s3_key, $s3_info['url'], [
                'migration' => true,
                'attachment_id' => $attachment_id
            ]);
        } else {
            $this->wps3_log("Could not find attachment ID for file: {$relative_path}", 'warning');
        }
    }

    /**
     * Check if a file is migrated.
     *
     * @param string $file_path
     * @return bool
     */
    private function is_file_migrated($file_path) {
        global $wpdb;
        
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace(trailingslashit($upload_dir['basedir']), '', $file_path);
        
        // First try to find by _wp_attached_file meta
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
            $relative_path
        ));
        
        // If not found, try to find by filename
        if (!$attachment_id) {
            $filename = basename($relative_path);
            $attachment_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
                '%' . $wpdb->esc_like($filename)
            ));
        }

        if ($attachment_id) {
            $s3_info = get_post_meta($attachment_id, 'wps3_s3_info', true);
            return !empty($s3_info) && isset($s3_info['key']) && $s3_info['bucket'] !== 'error';
        }

        return false;
    }

    /**
     * Get files that need migration (only WordPress attachments).
     *
     * @return array
     */
    private function get_files_needing_migration() {
        global $wpdb;
        
        // Get all attachments that don't have S3 info
        $attachments = $wpdb->get_results("
            SELECT p.ID, pm.meta_value as file_path
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'wps3_s3_info'
            WHERE p.post_type = 'attachment'
            AND pm.meta_value IS NOT NULL
            AND pm2.meta_value IS NULL
            ORDER BY p.ID DESC
        ");
        
        $upload_dir = wp_upload_dir();
        $files = [];
        
        foreach ($attachments as $attachment) {
            $full_path = trailingslashit($upload_dir['basedir']) . $attachment->file_path;
            if (file_exists($full_path)) {
                $files[] = $full_path;
            }
        }
        
        return $files;
    }

    /**
     * Migrate thumbnails for an attachment.
     *
     * @param string $file_path
     */
    private function migrate_attachment_thumbnails($file_path) {
        global $wpdb;
        
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace(trailingslashit($upload_dir['basedir']), '', $file_path);
        
        // Get attachment ID
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
            $relative_path
        ));
        
        if (!$attachment_id) {
            return;
        }
        
        // Get attachment metadata
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (empty($metadata['sizes'])) {
            return;
        }
        
        $base_dir = trailingslashit(dirname($file_path));
        $uploaded_thumbnails = 0;
        
        foreach ($metadata['sizes'] as $size_name => $size_data) {
            $thumb_path = $base_dir . $size_data['file'];
            
            if (file_exists($thumb_path)) {
                try {
                    // Fire action before thumbnail migration
                    do_action('wps3_before_upload', $thumb_path, '', [
                        'migration' => true,
                        'thumbnail' => true,
                        'size' => $size_name,
                        'attachment_id' => $attachment_id
                    ]);
                    
                    $thumb_key = $this->s3_client->upload_file($thumb_path);
                    if ($thumb_key) {
                        $uploaded_thumbnails++;
                        $this->wps3_log("Migrated thumbnail {$size_name} for attachment {$attachment_id}", 'info');
                        
                        // Fire action after thumbnail migration
                        do_action('wps3_after_upload', $thumb_path, $thumb_key, $this->s3_client->get_s3_url($thumb_key), [
                            'migration' => true,
                            'thumbnail' => true,
                            'size' => $size_name,
                            'attachment_id' => $attachment_id
                        ]);
                    }
                } catch (Exception $e) {
                    $this->wps3_log("Failed to migrate thumbnail {$size_name} for attachment {$attachment_id}: " . $e->getMessage(), 'error');
                }
            }
        }
        
        if ($uploaded_thumbnails > 0) {
            $this->wps3_log("Migrated {$uploaded_thumbnails} thumbnails for attachment {$attachment_id}", 'info');
        }
    }

    /**
     * Log messages using WordPress's error logging system.
     *
     * @param string $message
     * @param string $level
     */
    private function wps3_log($message, $level = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WPS3 Migration [{$level}]: $message");
        }
    }
}
