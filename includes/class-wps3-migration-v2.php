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
            <h1>S3 Migration Control Panel</h1>
            
            <div class="wps3-migration-status">
                <div class="wps3-control-panel">
                    <div class="wps3-buttons">
                        <button id="wps3-start" class="button button-primary">
                            <span class="dashicons dashicons-controls-play"></span> Start Migration
                        </button>
                        <button id="wps3-pause" class="button" disabled>
                            <span class="dashicons dashicons-controls-pause"></span> Pause
                        </button>
                        <button id="wps3-resume" class="button" disabled>
                            <span class="dashicons dashicons-controls-play"></span> Resume
                        </button>
                        <button id="wps3-cancel" class="button" disabled>
                            <span class="dashicons dashicons-no"></span> Cancel
                        </button>
                    </div>
                    
                    <div class="wps3-progress-section">
                        <div class="wps3-progress-bar-container">
                            <div class="wps3-progress-bar" id="wps3-progress-bar" style="width: 0%;">
                                <span id="wps3-progress-text">0%</span>
                            </div>
                        </div>
                        
                        <div class="wps3-stats-grid">
                            <div class="wps3-stat-item">
                                <div class="wps3-stat-label">✅ Completed</div>
                                <div class="wps3-stat-value" id="stat-done">0</div>
                            </div>
                            <div class="wps3-stat-item">
                                <div class="wps3-stat-label">⚡ In Progress</div>
                                <div class="wps3-stat-value" id="stat-processing">0</div>
                            </div>
                            <div class="wps3-stat-item">
                                <div class="wps3-stat-label">⏳ Queued</div>
                                <div class="wps3-stat-value" id="stat-waiting">0</div>
                            </div>
                            <div class="wps3-stat-item">
                                <div class="wps3-stat-label">📁 Total Files</div>
                                <div class="wps3-stat-value" id="stat-total">0</div>
                            </div>
                            <div class="wps3-stat-item">
                                <div class="wps3-stat-label">🚀 Upload Rate</div>
                                <div class="wps3-stat-value" id="stat-speed">0 /min</div>
                            </div>
                            <div class="wps3-stat-item">
                                <div class="wps3-stat-label">⏱️ Time Running</div>
                                <div class="wps3-stat-value" id="stat-elapsed">--</div>
                            </div>
                            <div class="wps3-stat-item">
                                <div class="wps3-stat-label">⏰ Time Remaining</div>
                                <div class="wps3-stat-value" id="stat-eta">--</div>
                            </div>
                            <div class="wps3-stat-item">
                                <div class="wps3-stat-label">📊 Migration Status</div>
                                <div class="wps3-stat-value" id="stat-status">Ready</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="wps3-error-section">
                        <div id="wps3-error" class="wps3-error-message"></div>
                    </div>
                </div>
            </div>
            
            <div class="wps3-info-section">
                <h3>How it works</h3>
                <ul>
                    <li><strong>Background Processing:</strong> Migration runs in the background using WordPress Action Scheduler</li>
                    <li><strong>Survives Reloads:</strong> Close your browser, the migration continues running</li>
                    <li><strong>Server Restarts:</strong> Migration automatically resumes after server restarts</li>
                    <li><strong>Real-time Updates:</strong> Live metrics update every 3 seconds</li>
                    <li><strong>Full Control:</strong> Start, pause, resume, or cancel at any time</li>
                </ul>
            </div>
        </div>

        <script>
        (function($) {
            'use strict';
            
            const AJAX_URL = wps3_ajax.ajax_url;
            const NONCE = wps3_ajax.nonce;
            let refreshTimer;
            let lastState = {};
            
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
            }
            
            // Perform migration action
            function performAction(action) {
                const button = $('#wps3-' + action);
                const originalText = button.html();
                
                button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Processing...');
                
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
                
                // Calculate and display metrics
                if (state.started_at && state.done > 0) {
                    const elapsed = Math.max(1, Date.now() / 1000 - state.started_at);
                    const speed = Math.round((state.done / elapsed) * 60); // per minute
                    const remaining = (state.total || 0) - (state.done || 0);
                    const eta = speed > 0 ? Math.round(remaining / speed) : 0;
                    
                    $('#stat-speed').text(speed + ' /min');
                    $('#stat-elapsed').text(formatTime(elapsed));
                    $('#stat-eta').text(eta > 0 ? formatTime(eta * 60) : '--');
                } else {
                    $('#stat-speed').text('0 /min');
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
            
            // Initialize when document is ready
            $(document).ready(init);
            
            // Cleanup on page unload
            $(window).on('beforeunload', function() {
                clearTimeout(refreshTimer);
            });
            
        })(jQuery);
        </script>
        <?php
    }
} 
