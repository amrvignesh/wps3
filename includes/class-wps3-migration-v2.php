<?php

/**
 * WPS3 Migration V2 - Background Job System with Live Control Panel
 */
class WPS3_Migration_V2 {

    /**
     * S3 client.
     *
     * @var WPS3_S3_Client
     */
    private $s3_client;

    /**
     * WPS3_Migration_V2 constructor.
     *
     * @param WPS3_S3_Client $s3_client
     */
    public function __construct(WPS3_S3_Client $s3_client) {
        $this->s3_client = $s3_client;

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    /**
     * Add admin menu.
     */
    public function add_admin_menu() {
        add_media_page(
            'S3 Migration Control',
            'S3 Migration',
            'manage_options',
            'wps3-migration',
            [$this, 'render_migration_page_v2']
        );
    }

    /**
     * Enqueue admin scripts.
     *
     * @param string $hook
     */
    public function enqueue_admin_scripts($hook) {
        if ('media_page_wps3-migration' !== $hook) {
            return;
        }

        wp_enqueue_script('jquery');
        wp_enqueue_style(
            'wps3-migration-v2',
            WPS3_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WPS3_VERSION
        );
        
        wp_localize_script('jquery', 'wps3_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => rest_url('wps3/v1/'),
            'nonce' => wp_create_nonce('wps3_nonce'),
        ]);
    }

    /**
     * Render the v2 migration page with live control panel.
     */
    public function render_migration_page_v2() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-cloud-upload" style="font-size: 24px; width: 24px; height: 24px; vertical-align: middle; margin-right: 8px;"></span>
                <?php _e('S3 Migration', 'wps3'); ?>
            </h1>
            <hr class="wp-header-end">

            <!-- Admin Notices Placeholder -->
            <div id="wps3-notices"></div>

            <!-- Two Column Layout -->
            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    
                    <!-- Main Column -->
                    <div id="post-body-content">
                        
                        <!-- Migration Control Postbox -->
                        <div class="postbox">
                            <div class="postbox-header">
                                <h2 class="hndle"><span class="dashicons dashicons-controls-play"></span> <?php _e('Migration Controls', 'wps3'); ?></h2>
                                <div class="handle-actions hide-if-no-js">
                                    <button type="button" class="handlediv" aria-expanded="true">
                                        <span class="screen-reader-text"><?php _e('Toggle panel', 'wps3'); ?></span>
                                        <span class="toggle-indicator" aria-hidden="true"></span>
                                    </button>
                                </div>
                            </div>
                            <div class="inside">
                                <div class="wps3-control-section">
                                    <p class="wps3-status-badge">
                                        <span class="dashicons dashicons-info" style="color: #2271b1;"></span>
                                        <strong><?php _e('Status:', 'wps3'); ?></strong> <span id="migration-status-text">Ready</span>
                                    </p>
                                    
                                    <div class="wps3-button-group">
                                        <button id="wps3-start" class="button button-primary button-hero">
                                            <span class="dashicons dashicons-controls-play"></span>
                                            <?php _e('Start Migration', 'wps3'); ?>
                                        </button>
                                        <button id="wps3-pause" class="button button-large" disabled>
                                            <span class="dashicons dashicons-controls-pause"></span>
                                            <?php _e('Pause', 'wps3'); ?>
                                        </button>
                                        <button id="wps3-resume" class="button button-large" disabled style="display: none;">
                                            <span class="dashicons dashicons-controls-play"></span>
                                            <?php _e('Resume', 'wps3'); ?>
                                        </button>
                                        <button id="wps3-cancel" class="button button-link-delete" disabled>
                                            <span class="dashicons dashicons-no"></span>
                                            <?php _e('Cancel', 'wps3'); ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Progress Monitor Postbox -->
                        <div class="postbox">
                            <div class="postbox-header">
                                <h2 class="hndle"><span class="dashicons dashicons-chart-bar"></span> <?php _e('Migration Progress', 'wps3'); ?></h2>
                                <div class="handle-actions hide-if-no-js">
                                    <button type="button" class="handlediv" aria-expanded="true">
                                        <span class="screen-reader-text"><?php _e('Toggle panel', 'wps3'); ?></span>
                                        <span class="toggle-indicator" aria-hidden="true"></span>
                                    </button>
                                </div>
                            </div>
                            <div class="inside">
                                <!-- Progress Bar -->
                                <div class="wps3-wp-progress-wrapper">
                                    <div class="wps3-progress-label">
                                        <span id="progress-percentage">0%</span>
                                        <span class="wps3-progress-count" id="progress-count">0 / 0 files</span>
                                    </div>
                                    <div class="wps3-wp-progress-bar">
                                        <div class="wps3-wp-progress-fill" id="progress-fill" style="width: 0%;"></div>
                                    </div>
                                </div>

                                <!-- Current File -->
                                <div id="current-file-section" style="display: none; margin-top: 15px; padding: 10px; background: #f0f6fc; border-left: 3px solid #2271b1; border-radius: 3px;">
                                    <small style="color: #666;"><?php _e('Currently migrating:', 'wps3'); ?></small>
                                    <code id="current-file-name" style="display: block; margin-top: 4px;">--</code>
                                </div>

                                <!-- Stats Table -->
                                <table class="widefat fixed striped" style="margin-top: 20px;">
                                    <tbody>
                                        <tr>
                                            <td style="width: 30%;"><strong><?php _e('Total Files:', 'wps3'); ?></strong></td>
                                            <td id="total-files">0</td>
                                        </tr>
                                        <tr>
                                            <td><strong><?php _e('Completed:', 'wps3'); ?></strong></td>
                                            <td id="completed-files">0</td>
                                        </tr>
                                        <tr>
                                            <td><strong><?php _e('Speed:', 'wps3'); ?></strong></td>
                                            <td id="current-speed">0 /min</td>
                                        </tr>
                                        <tr>
                                            <td><strong><?php _e('Elapsed Time:', 'wps3'); ?></strong></td>
                                            <td id="elapsed-time">--</td>
                                        </tr>
                                        <tr>
                                            <td><strong><?php _e('Estimated Time:', 'wps3'); ?></strong></td>
                                            <td id="eta-time">--</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Failed Files Postbox -->
                        <div class="postbox" id="failed-files-section" style="display: none;">
                            <div class="postbox-header">
                                <h2 class="hndle">
                                    <span class="dashicons dashicons-warning" style="color: #d63638;"></span>
                                    <?php _e('Failed Files', 'wps3'); ?>
                                    <span class="wps3-count-badge" id="failed-count">0</span>
                                </h2>
                                <div class="handle-actions hide-if-no-js">
                                    <button type="button" class="handlediv" aria-expanded="true">
                                        <span class="screen-reader-text"><?php _e('Toggle panel', 'wps3'); ?></span>
                                        <span class="toggle-indicator" aria-hidden="true"></span>
                                    </button>
                                </div>
                            </div>
                            <div class="inside">
                                <div style="margin-bottom: 15px;">
                                    <button id="retry-all-failed" class="button">
                                        <span class="dashicons dashicons-update-alt"></span>
                                        <?php _e('Retry All', 'wps3'); ?>
                                    </button>
                                    <button id="clear-failed" class="button">
                                        <span class="dashicons dashicons-dismiss"></span>
                                        <?php _e('Clear List', 'wps3'); ?>
                                    </button>
                                </div>
                                <table class="wp-list-table widefat fixed striped" id="failed-files-table" style="display: none;">
                                    <thead>
                                        <tr>
                                            <th style="width: 50%;"><?php _e('File', 'wps3'); ?></th>
                                            <th style="width: 35%;"><?php _e('Error', 'wps3'); ?></th>
                                            <th style="width: 15%;"><?php _e('Action', 'wps3'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody id="failed-files-list">
                                        <!-- Failed files populated here -->
                                    </tbody>
                                </table>
                                <p id="no-failed-message"><?php _e('No failed files.', 'wps3'); ?></p>
                            </div>
                        </div>

                    </div><!-- /post-body-content -->

                    <!-- Sidebar -->
                    <div id="postbox-container-1" class="postbox-container">
                        
                        <!-- Quick Stats -->
                        <div class="postbox">
                            <div class="postbox-header">
                                <h2 class="hndle"><?php _e('Quick Stats', 'wps3'); ?></h2>
                            </div>
                            <div class="inside">
                                <ul class="wps3-stats-sidebar">
                                    <li>
                                        <span class="dashicons dashicons-cloud-saved"></span>
                                        <div>
                                            <strong id="total-size-migrated">0 MB</strong>
                                            <small><?php _e('Migrated', 'wps3'); ?></small>
                                        </div>
                                    </li>
                                    <li>
                                        <span class="dashicons dashicons-chart-line"></span>
                                        <div>
                                            <strong id="bandwidth-saved">0 GB/mo</strong>
                                            <small><?php _e('Bandwidth Saved', 'wps3'); ?></small>
                                        </div>
                                    </li>
                                    <li>
                                        <span class="dashicons dashicons-upload"></span>
                                        <div>
                                            <strong id="files-offloaded">0</strong>
                                            <small><?php _e('Files Offloaded', 'wps3'); ?></small>
                                        </div>
                                    </li>
                                </ul>
                                <p class="description" style="margin: 15px 0 0 0; padding: 10px; background: #f0f6fc; border-radius: 3px;">
                                    <small><?php _e('💡 Estimated monthly bandwidth reduction based on 70% media traffic.', 'wps3'); ?></small>
                                </p>
                            </div>
                        </div>

                        <!-- How It Works -->
                        <div class="postbox">
                            <div class="postbox-header">
                                <h2 class="hndle"><?php _e('How It Works', 'wps3'); ?></h2>
                            </div>
                            <div class="inside">
                                <ul style="margin: 0; padding-left: 20px;">
                                    <li style="margin-bottom: 10px;">
                                        <strong><?php _e('Background Processing', 'wps3'); ?></strong>
                                        <p class="description" style="margin: 4px 0 0 0;"><?php _e('Runs in background using WordPress Action Scheduler', 'wps3'); ?></p>
                                    </li>
                                    <li style="margin-bottom: 10px;">
                                        <strong><?php _e('Survives Reloads', 'wps3'); ?></strong>
                                        <p class="description" style="margin: 4px 0 0 0;"><?php _e('Close your browser - migration continues', 'wps3'); ?></p>
                                    </li>
                                    <li style="margin-bottom: 10px;">
                                        <strong><?php _e('Auto-Resumes', 'wps3'); ?></strong>
                                        <p class="description" style="margin: 4px 0 0 0;"><?php _e('Automatically resumes after server restarts', 'wps3'); ?></p>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- Debug Actions -->
                        <div class="postbox" style="display: none;" id="debug-actions-box">
                            <div class="postbox-header">
                                <h2 class="hndle"><?php _e('Debug', 'wps3'); ?></h2>
                            </div>
                            <div class="inside">
                                <button id="wps3-debug" class="button button-small" style="width: 100%;">
                                    <?php _e('Show Debug Info', 'wps3'); ?>
                                </button>
                                <button id="wps3-force-complete" class="button button-small" style="width: 100%; margin-top: 8px;">
                                    <?php _e('Force Complete', 'wps3'); ?>
                                </button>
                            </div>
                        </div>

                    </div><!-- /postbox-container-1 -->

                </div><!-- /post-body -->
            </div><!-- /poststuff -->

        </div><!-- /wrap -->

        <script>
        (function($) {
            'use strict';
            
            const AJAX_URL = wps3_ajax.ajax_url;
            const NONCE = wps3_ajax.nonce;
            let refreshTimer;
            let lastState = {};
            let failedFiles = [];
            let totalBytesMigrated = 0;
            let startTime = null;
            
            // Initialize the control panel
            function init() {
                bindEvents();
                refresh();
            }
            
            // Bind button events
            function bindEvents() {
                $('#wps3-start').on('click', () => performAction('start'));
                $('#wps3-pause').on('click', () => performAction('pause'));
                $('#wps3-resume').on('click', () => performAction('resume'));
                $('#wps3-cancel').on('click', () => performAction('cancel'));
                $('#wps3-force-complete').on('click', () => performAction('force_complete'));
                $('#wps3-debug').on('click', () => showDebugInfo());
                
                // Failed files handlers
                $('#retry-all-failed').on('click', retryAllFailed);
                $('#clear-failed').on('click', clearFailedList);
                $(document).on('click', '.retry-single', function() {
                    const fileName = $(this).data('file');
                    retrySingleFile(fileName);
                });
            }
            
            // Perform migration action
            function performAction(action) {
                const button = $('#wps3-' + action.replace('_', '-'));
                const originalText = button.html();
                
                button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Processing...');
                
                // Handle force complete differently
                if (action === 'force_complete') {
                    $.ajax({
                        url: AJAX_URL,
                        method: 'POST',
                        data: {
                            action: 'wps3_force_complete',
                            nonce: NONCE
                        }
                    }).done(function(response) {
                        if (response.success && response.data) {
                            updateUI(response.data.state || response.data);
                            showMessage('Migration marked as complete!', 'success');
                        } else {
                            showError('Force complete failed: ' + (response.data?.message || 'Unknown error'));
                        }
                    }).fail(function(xhr) {
                        console.error('Force complete failed:', xhr);
                        showError('Force complete failed: ' + (xhr.responseJSON?.message || 'Network error'));
                    }).always(function() {
                        button.prop('disabled', false).html(originalText);
                    });
                    return;
                }
                
                // Handle other actions normally
                $.ajax({
                    url: AJAX_URL,
                    method: 'POST',
                    data: {
                        action: 'wps3_api',
                        do: action,
                        nonce: NONCE
                    }
                }).done(function(response) {
                    if (response.success && response.data) {
                        updateUI(response.data);
                    } else {
                        showError('Action failed: ' + (response.data?.message || 'Unknown error'));
                    }
                }).fail(function(xhr) {
                    console.error('Action failed:', xhr);
                    showError('Action failed: ' + (xhr.responseJSON?.message || 'Network error'));
                }).always(function() {
                    button.prop('disabled', false).html(originalText);
                });
            }
            
            // Refresh migration status
            function refresh() {
                $.ajax({
                    url: AJAX_URL,
                    method: 'POST',
                    data: {
                        action: 'wps3_state',
                        nonce: NONCE
                    }
                }).done(function(response) {
                    if (response.success && response.data) {
                        updateUI(response.data);
                    }
                }).fail(function(xhr) {
                    console.error('Status refresh failed:', xhr);
                }).always(function() {
                    // Schedule next refresh
                    clearTimeout(refreshTimer);
                    refreshTimer = setTimeout(refresh, 3000);
                });
            }
            
            // Update UI with current state
            function updateUI(state) {
                if (!state || typeof state !== 'object') {
                    return;
                }
                
                lastState = state;
                
                // Update statistics
                $('#stat-done').text(state.done || 0);
                $('#stat-processing').text(state.processing || 0);
                $('#stat-waiting').text(state.queued || 0);
                $('#stat-total').text(state.total || 0);
                $('#stat-status').text(formatStatus(state.status || 'ready'));
                
                // Calculate and display metrics based on migration status
                const status = state.status || 'ready';
                
                if (status === 'ready' || (!state.started_at && state.done === 0)) {
                    // Migration not started yet
                    $('#stat-speed').text('--');
                    $('#stat-elapsed').text('--');
                    $('#stat-eta').text('--');
                } else if (status === 'finished') {
                    // Migration completed - show final stats
                    if (state.started_at) {
                        const totalElapsed = Math.max(1, Date.now() / 1000 - state.started_at);
                        const finalSpeed = state.done > 0 ? Math.round((state.done / totalElapsed) * 60) : 0;
                        
                        $('#stat-speed').text(finalSpeed + ' /min (final)');
                        $('#stat-elapsed').text(formatTime(totalElapsed) + ' (total)');
                        $('#stat-eta').text('Complete ✅');
                    } else {
                        $('#stat-speed').text('--');
                        $('#stat-elapsed').text('--');
                        $('#stat-eta').text('Complete ✅');
                    }
                } else if (status === 'cancelled') {
                    // Migration was cancelled - show stats up to cancellation
                    if (state.started_at) {
                        const cancelledElapsed = Math.max(1, Date.now() / 1000 - state.started_at);
                        const cancelledSpeed = state.done > 0 ? Math.round((state.done / cancelledElapsed) * 60) : 0;
                        
                        $('#stat-speed').text(cancelledSpeed + ' /min (cancelled)');
                        $('#stat-elapsed').text(formatTime(cancelledElapsed) + ' (before cancel)');
                        $('#stat-eta').text('Cancelled ❌');
                    } else {
                        $('#stat-speed').text('0 /min');
                        $('#stat-elapsed').text('--');
                        $('#stat-eta').text('Cancelled ❌');
                    }
                } else if (status === 'error') {
                    // Migration had an error - show stats up to error
                    if (state.started_at) {
                        const errorElapsed = Math.max(1, Date.now() / 1000 - state.started_at);
                        const errorSpeed = state.done > 0 ? Math.round((state.done / errorElapsed) * 60) : 0;
                        
                        $('#stat-speed').text(errorSpeed + ' /min (error)');
                        $('#stat-elapsed').text(formatTime(errorElapsed) + ' (before error)');
                        $('#stat-eta').text('Error ⚠️');
                    } else {
                        $('#stat-speed').text('0 /min');
                        $('#stat-elapsed').text('--');
                        $('#stat-eta').text('Error ⚠️');
                    }
                } else if (['running', 'paused'].includes(status)) {
                    // Migration is active - show live stats
                    if (state.started_at) {
                        const elapsed = Math.max(1, Date.now() / 1000 - state.started_at);
                        const speed = state.done > 0 ? Math.round((state.done / elapsed) * 60) : 0;
                        const remaining = (state.total || 0) - (state.done || 0);
                        const eta = speed > 0 && remaining > 0 ? Math.round(remaining / speed) : 0;
                        
                        const speedSuffix = status === 'paused' ? ' (paused)' : '';
                        $('#stat-speed').text(speed + ' /min' + speedSuffix);
                        $('#stat-elapsed').text(formatTime(elapsed));
                        
                        if (status === 'paused') {
                            $('#stat-eta').text('Paused ⏸️');
                        } else if (eta > 0) {
                            $('#stat-eta').text(formatTime(eta * 60));
                        } else {
                            $('#stat-eta').text('Calculating...');
                        }
                    } else {
                        $('#stat-speed').text('0 /min');
                        $('#stat-elapsed').text('--');
                        $('#stat-eta').text(status === 'paused' ? 'Paused ⏸️' : 'Starting...');
                    }
                } else {
                    // Fallback for unknown states
                    $('#stat-speed').text('--');
                    $('#stat-elapsed').text('--');
                    $('#stat-eta').text('--');
                }
                
                // Update progress bar
                if (state.total && state.total > 0) {
                    const progress = Math.round((state.done / state.total) * 100);
                    $('#wps3-progress-bar').css('width', progress + '%');
                    $('#wps3-progress-text').text(progress + '%');
                } else {
                    $('#wps3-progress-bar').css('width', '0%');
                    $('#wps3-progress-text').text('0%');
                }
                
                
                // Update button states
                updateButtons(state.status);
                
                // Update bandwidth savings (call our new function)
                calculateBandwidthSavings(state.total || 0, state.done || 0, state.total_size_migrated || 0);
                
                // Update current file display (if available in state)
                if (state.current_file) {
                    updateCurrentFile(state.current_file);
                } else if (state.status === 'running') {
                    updateCurrentFile('Processing...');
                } else {
                    updateCurrentFile(null);
                }
                
                lastState = state; // Store the current state after all updates
                // Show errors
                if (state.last_error) {
                    showError(state.last_error);
                } else {
                    hideError();
                }
            }
            
            // Update button states based on migration status
            function updateButtons(status) {
                const isRunning = status === 'running';
                const isPaused = status === 'paused';
                const isFinished = ['finished', 'cancelled', 'error'].includes(status);
                
                $('#wps3-start').prop('disabled', isRunning || isPaused);
                $('#wps3-pause').prop('disabled', !isRunning);
                $('#wps3-resume').prop('disabled', !isPaused);
                $('#wps3-cancel').prop('disabled', isFinished || !status || status === 'ready');
                $('#wps3-force-complete').prop('disabled', isFinished || !status || status === 'ready');
            }
            
            // Format status for display
            function formatStatus(status) {
                const statusMap = {
                    'running': 'Running',
                    'paused': 'Paused',
                    'finished': 'Completed',
                    'cancelled': 'Cancelled',
                    'error': 'Error',
                    'ready': 'Ready'
                };
                return statusMap[status] || status;
            }
            
            // Format time duration
            function formatTime(seconds) {
                if (seconds < 60) {
                    return Math.round(seconds) + 's';
                } else if (seconds < 3600) {
                    return Math.round(seconds / 60) + 'm';
                } else {
                    const hours = Math.floor(seconds / 3600);
                    const minutes = Math.round((seconds % 3600) / 60);
                    return hours + 'h ' + minutes + 'm';
                }
            }
            
            // Show error message
            function showError(message) {
                $('#wps3-error').text(message).show();
            }
            
            // Hide error message
            function hideError() {
                $('#wps3-error').hide();
            }
            
            // Show success message
            function showMessage(message, type = 'info') {
                const $message = $('<div>', {
                    class: 'notice notice-' + type + ' is-dismissible',
                    html: '<p>' + message + '</p>'
                });
                $('.wrap h1').after($message);
                
                // Auto-hide after 3 seconds
                setTimeout(function() {
                    $message.fadeOut();
                }, 3000);
            }
            
            // Show debug information
            function showDebugInfo() {
                $.ajax({
                    url: AJAX_URL,
                    method: 'POST',
                    data: {
                        action: 'wps3_debug',
                        nonce: NONCE
                    }
                }).done(function(response) {
                    if (response.success && response.data) {
                        const info = response.data;
                        let debugHtml = '<div style="background: #f1f1f1; padding: 15px; border-radius: 5px; margin: 10px 0; font-family: monospace; font-size: 12px;">';
                        debugHtml += '<h4>🔧 Debug Information</h4>';
                        debugHtml += '<p><strong>Plugin Enabled:</strong> ' + (info.plugin_enabled ? '✅ Yes' : '❌ No') + '</p>';
                        debugHtml += '<p><strong>S3 Client Available:</strong> ' + (info.s3_client_available ? '✅ Yes' : '❌ No') + '</p>';
                        debugHtml += '<p><strong>Action Scheduler:</strong> ' + (info.action_scheduler_available ? '✅ Available' : '❌ Not Available') + '</p>';
                        debugHtml += '<p><strong>Bucket Name:</strong> ' + (info.bucket_name || 'Not set') + '</p>';
                        debugHtml += '<p><strong>Endpoint URL:</strong> ' + (info.s3_endpoint_url || 'Not set') + '</p>';
                        debugHtml += '<p><strong>Region:</strong> ' + (info.s3_region || 'Not set') + '</p>';
                        debugHtml += '<p><strong>Access Key:</strong> ' + info.access_key + '</p>';
                        debugHtml += '<p><strong>Secret Key:</strong> ' + info.secret_key + '</p>';
                        debugHtml += '<p><strong>Attachments Needing Migration:</strong> ' + (info.total_attachments_needing_migration || 0) + '</p>';
                        
                        if (info.migration_state && Object.keys(info.migration_state).length > 0) {
                            debugHtml += '<p><strong>Migration State:</strong> ' + JSON.stringify(info.migration_state, null, 2) + '</p>';
                        }
                        
                        if (info.sample_attachments && info.sample_attachments.length > 0) {
                            debugHtml += '<p><strong>Sample Attachments:</strong></p>';
                            debugHtml += '<ul>';
                            info.sample_attachments.forEach(function(att) {
                                debugHtml += '<li>ID: ' + att.ID + ', File: ' + att.file_path + '</li>';
                            });
                            debugHtml += '</ul>';
                        }
                        
                        debugHtml += '</div>';
                        
                        // Show debug info in a modal-like overlay
                        $('body').append('<div id="wps3-debug-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 10000; display: flex; align-items: center; justify-content: center;"><div style="background: white; padding: 20px; border-radius: 10px; max-width: 80%; max-height: 80%; overflow: auto;"><button id="wps3-close-debug" style="float: right; margin-bottom: 10px;">Close</button>' + debugHtml + '</div></div>');
                        
                        $('#wps3-close-debug').on('click', function() {
                            $('#wps3-debug-overlay').remove();
                        });
                    } else {
                        showError('Failed to get debug info: ' + (response.data?.message || 'Unknown error'));
                    }
                }).fail(function(xhr) {
                    showError('Debug request failed: ' + (xhr.responseJSON?.message || 'Network error'));
                });
            }
            
            // Initialize when document is ready
            $(document).ready(init);
            
            // Cleanup on page unload
            $(window).on('beforeunload', function() {
                clearTimeout(refreshTimer);
            });
            
            // WordPress Admin Notices
            function showNotice(message, type = 'info') {
                const noticeClass = 'notice-' + type;
                const isDismissible = type !== 'error' ? 'is-dismissible' : '';
                
                const $notice = $(`
                    <div class="notice ${noticeClass} ${isDismissible}">
                        <p>${message}</p>
                        ${isDismissible ? '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>' : ''}
                    </div>
                `);
                
                $('#wps3-notices').html($notice);
                
                // Handle dismiss button
                $notice.find('.notice-dismiss').on('click', function() {
                    $notice.fadeOut(300, function() { $(this).remove(); });
                });
                
                // Auto-dismiss success/info notices after 5 seconds
                if (['success', 'info'].includes(type)) {
                    setTimeout(() => {
                        $notice.fadeOut(300, function() { $(this).remove(); });
                    }, 5000);
                }
            }
            
            // Failed Files Functions (updated for WordPress table)
            function addFailedFile(fileName, errorMessage) {
                if (!failedFiles.find(f => f.file === fileName)) {
                    failedFiles.push({file: fileName, error: errorMessage});
                    updateFailedFilesDisplay();
                }
            }
            
            function updateFailedFilesDisplay() {
                const count = failedFiles.length;
                $('#failed-count').text(count);
                
                if (count > 0) {
                    $('#failed-files-section').slideDown();
                    $('#failed-files-table').show();
                    $('#no-failed-message').hide();
                    
                    let html = '';
                    failedFiles.forEach(item => {
                        html += `
                            <tr>
                                <td><code>${escapeHtml(item.file)}</code></td>
                                <td style="color: #d63638;">${escapeHtml(item.error)}</td>
                                <td>
                                    <button class="button button-small retry-single" data-file="${escapeHtml(item.file)}">
                                        <span class="dashicons dashicons-update-alt" style="font-size: 13px; width: 13px; height: 13px;"></span>
                                        <?php _e('Retry', 'wps3'); ?>
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                    $('#failed-files-list').html(html);
                } else {
                    $('#failed-files-section').slideUp();
                    $('#failed-files-table').hide();
                    $('#no-failed-message').show();
                }
            }
            
            function retryAllFailed() {
                if (failedFiles.length === 0) {
                    return;
                }
                
                showNotice('Retrying ' + failedFiles.length + ' failed files...', 'info');
                // TODO: Implement retry logic via AJAX
                // For now, just clear the list
                clearFailedList();
            }
            
            function retrySingleFile(fileName) {
                showNotice('Retrying: ' + fileName, 'info');
                // TODO: Implement single file retry logic
                failedFiles = failedFiles.filter(f => f.file !== fileName);
                updateFailedFilesDisplay();
            }
            
            function clearFailedList() {
                failedFiles = [];
                updateFailedFilesDisplay();
            }
            
            // Bandwidth Savings Functions
            function calculateBandwidthSavings(totalFiles, completedFiles, totalSize) {
                if (completedFiles > 0) {
                    $('#savings-section').slideDown();
                }
                
                // Calculate total migrated size (assume average file size if not available)
                const avgFileSize = totalSize || (completedFiles * 500 * 1024); // 500KB average
                const totalMB = (avgFileSize / (1024 * 1024)).toFixed(2);
                const bandwidthSavedGB = ((avgFileSize * 0.7 * 30) / (1024 * 1024 * 1024)).toFixed(2); // 70% * 30 days
                
                $('#total-size-migrated').text(totalMB + ' MB');
                $('#bandwidth-saved').text(bandwidthSavedGB + ' GB/mo');
                $('#files-offloaded').text(completedFiles.toLocaleString());
            }
            
            // Current File Display
            function updateCurrentFile(fileName) {
                if (fileName && fileName !== '--') {
                    $('#current-file-section').slideDown();
                    $('#current-file-name').text(fileName);
                } else {
                    $('#current-file-section').slideUp();
                }
            }
            
            // Utility function
            function escapeHtml(text) {
                const map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                };
                return text ? text.replace(/[&<>"']/g, m => map[m]) : '';
            }
            
            // Initialize
            $(document).ready(init);
            
        })(jQuery);
        </script>
        <?php
    }
}
