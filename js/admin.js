jQuery(document).ready(function($) {
    const STATUS_CHECK_INTERVAL = 5000; // 5 seconds
    let statusCheckTimer = null;
    let processingBatch = false;
    
    // DOM Elements
    const $startButton = $('#wps3-start-migration');
    const $pauseButton = $('#wps3-pause-migration');
    const $resetButton = $('#wps3-reset-migration');
    const $progressBar = $('.wps3-progress-bar');
    const $progressText = $('.wps3-progress-bar span');
    const $logContainer = $('#wps3-log-container');
    const $migrationStats = $('.wps3-migration-stats p');
    
    /**
     * Log a message to the migration log
     */
    function logMessage(message, type = 'info') {
        const timestamp = new Date().toLocaleTimeString();
        const $entry = $('<div>', {
            class: 'wps3-log-entry wps3-log-' + type,
            text: `[${timestamp}] ${message}`
        });
        
        $logContainer.prepend($entry);
    }
    
    /**
     * Update the progress bar
     */
    function updateProgress(percent, migratedFiles, totalFiles) {
        $progressBar.css('width', percent + '%');
        $progressText.text(percent + '%');
        $migrationStats.text(`Progress: ${migratedFiles} of ${totalFiles} files migrated`);
    }
    
    /**
     * Start the migration process
     */
    function startMigration(reset = false) {
        $.ajax({
            url: wps3_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wps3_start_migration',
                nonce: wps3_ajax.nonce,
                reset: reset ? 'true' : 'false'
            },
            beforeSend: function() {
                $startButton.prop('disabled', true);
                logMessage('Starting migration...');
            },
            success: function(response) {
                if (response.success) {
                    logMessage(response.data.message);
                    $startButton.hide();
                    $pauseButton.show();
                    
                    // Process the first batch
                    processBatch();
                    
                    // Start status check timer
                    startStatusCheck();
                } else {
                    logMessage('Error: ' + response.data.message, 'error');
                    $startButton.prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                logMessage('AJAX Error: ' + error, 'error');
                $startButton.prop('disabled', false);
            }
        });
    }
    
    /**
     * Pause the migration process
     */
    function pauseMigration() {
        stopStatusCheck();
        processingBatch = false;
        
        $.ajax({
            url: wps3_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wps3_get_migration_status',
                nonce: wps3_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.migration_running) {
                        // Update option via AJAX
                        $.post(wps3_ajax.ajax_url, {
                            action: 'wps3_update_option',
                            nonce: wps3_ajax.nonce,
                            option_name: 'wps3_migration_running',
                            option_value: false
                        });
                    }
                    
                    $pauseButton.hide();
                    $startButton.show().prop('disabled', false);
                    logMessage('Migration paused');
                }
            }
        });
    }
    
    /**
     * Reset the migration
     */
    function resetMigration() {
        if (confirm('Are you sure you want to reset the migration progress? This will start over from the beginning.')) {
            pauseMigration();
            startMigration(true);
        }
    }
    
    /**
     * Process a batch of files
     */
    function processBatch() {
        if (processingBatch) return;
        
        processingBatch = true;
        
        $.ajax({
            url: wps3_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wps3_process_batch',
                nonce: wps3_ajax.nonce
            },
            success: function(response) {
                processingBatch = false;
                
                if (response.success) {
                    // Update progress
                    updateProgress(
                        response.data.percent_complete,
                        response.data.migrated_files,
                        response.data.total_files
                    );
                    
                    // Log the result
                    logMessage(response.data.message);
                    
                    // Log any errors
                    if (response.data.error_count > 0) {
                        response.data.errors.forEach(function(error) {
                            logMessage(`Failed to migrate ${error.path}: ${error.error}`, 'error');
                        });
                    }
                    
                    // If migration is complete, update UI
                    if (response.data.complete) {
                        logMessage('Migration complete!', 'success');
                        $pauseButton.hide();
                        $startButton.show().prop('disabled', false);
                        stopStatusCheck();
                    } else {
                        // Process the next batch
                        setTimeout(processBatch, 1000);
                    }
                } else {
                    logMessage('Error: ' + response.data.message, 'error');
                    $pauseButton.hide();
                    $startButton.show().prop('disabled', false);
                    stopStatusCheck();
                }
            },
            error: function(xhr, status, error) {
                processingBatch = false;
                logMessage('AJAX Error: ' + error, 'error');
                // Retry after a delay
                setTimeout(processBatch, 5000);
            }
        });
    }
    
    /**
     * Start the status check timer
     */
    function startStatusCheck() {
        statusCheckTimer = setInterval(checkStatus, STATUS_CHECK_INTERVAL);
    }
    
    /**
     * Stop the status check timer
     */
    function stopStatusCheck() {
        if (statusCheckTimer) {
            clearInterval(statusCheckTimer);
            statusCheckTimer = null;
        }
    }
    
    /**
     * Check the migration status
     */
    function checkStatus() {
        $.ajax({
            url: wps3_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wps3_get_migration_status',
                nonce: wps3_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateProgress(
                        response.data.percent_complete,
                        response.data.migrated_files,
                        response.data.total_files
                    );
                    
                    // If migration is no longer running, update UI
                    if (!response.data.migration_running && statusCheckTimer) {
                        $pauseButton.hide();
                        $startButton.show().prop('disabled', false);
                        stopStatusCheck();
                    }
                }
            },
            error: function(xhr, status, error) {
                // Log the error to the migration log
                logMessage('Status check failed: ' + error, 'error');
                
                // If we encounter too many consecutive failures, we might want to 
                // pause the status checks and provide UI feedback
                // For now, we'll continue with the timer
            }
        });
    }
    
    // Event Handlers
    $startButton.on('click', function() {
        startMigration();
    });
    
    $pauseButton.on('click', function() {
        pauseMigration();
    });
    
    $resetButton.on('click', function() {
        resetMigration();
    });
    
    // Check initial status on page load
    checkStatus();
}); 