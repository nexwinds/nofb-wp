/**
 * Bunny Media Offload - Admin JavaScript
 */

(function($) {
    'use strict';
    
    // Initialize the admin functionality
    $(document).ready(function() {
        initApiCheck();
        initOptimizationQueue();
        initMigrationQueue();
        initQueueReinitialize();
    });
    
    /**
     * Initialize API status check
     */
    function initApiCheck() {
        $('#nofb-check-api').on('click', function() {
            const $button = $(this);
            const originalText = $button.text();
            
            $button.text(nofbAdminVars.processing).prop('disabled', true);
            
            // Send API check request
            $.post(nofbAdminVars.ajaxUrl, {
                action: 'nofb_check_api',
                nonce: nofbAdminVars.nonce
            }, function(response) {
                if (response.success) {
                    const data = response.data;
                    
                    // Update nofb API status
                    if (data.nofb_status.status === 'success') {
                        $('#nofb-api-status').removeClass('nofb-status-unknown nofb-status-error')
                            .addClass('nofb-status-success')
                            .text(data.nofb_status.message);
                    } else {
                        $('#nofb-api-status').removeClass('nofb-status-unknown nofb-status-success')
                            .addClass('nofb-status-error')
                            .text(data.nofb_status.message);
                    }
                    
                    // Update Bunny API status
                    if (data.bunny_status.status === 'success') {
                        $('#bunny-api-status').removeClass('nofb-status-unknown nofb-status-error')
                            .addClass('nofb-status-success')
                            .text(data.bunny_status.message);
                    } else {
                        $('#bunny-api-status').removeClass('nofb-status-unknown nofb-status-success')
                            .addClass('nofb-status-error')
                            .text(data.bunny_status.message);
                    }
                    
                    // Update credits count
                    $('#nofb-api-credits').text(data.credits);
                } else {
                    alert(response.data);
                }
                
                $button.text(originalText).prop('disabled', false);
            }).fail(function() {
                alert(nofbAdminVars.error);
                $button.text(originalText).prop('disabled', false);
            });
        });
    }
    
    /**
     * Initialize optimization queue handlers
     */
    function initOptimizationQueue() {
        // Scan media library
        $('#nofb-scan-optimization').on('click', function() {
            queueAction('optimization', 'scan');
        });
        
        // Process queue
        $('#nofb-process-optimization').on('click', function() {
            queueAction('optimization', 'process');
        });
        
        // Clear queue
        $('#nofb-clear-optimization').on('click', function() {
            if (confirm(nofbAdminVars.confirmClear)) {
                queueAction('optimization', 'clear');
            }
        });
    }
    
    /**
     * Initialize migration queue handlers
     */
    function initMigrationQueue() {
        // Scan optimized files
        $('#nofb-scan-migration').on('click', function() {
            queueAction('migration', 'scan');
        });
        
        // Process queue
        $('#nofb-process-migration').on('click', function() {
            queueAction('migration', 'process');
        });
        
        // Clear queue
        $('#nofb-clear-migration').on('click', function() {
            if (confirm(nofbAdminVars.confirmClear)) {
                queueAction('migration', 'clear');
            }
        });
    }
    
    /**
     * Handle queue actions (scan, process, clear)
     */
    function queueAction(queueType, action) {
        // Disable all buttons
        $('.nofb-actions button').prop('disabled', true);
        
        // Show progress bar
        const $progress = $('.nofb-progress-wrapper');
        const $bar = $('.nofb-progress-bar');
        const $status = $('.nofb-progress-status');
        
        $bar.css('width', '0%');
        $progress.show();
        $status.text(nofbAdminVars.processing);
        
        let ajaxAction = '';
        
        switch (action) {
            case 'scan':
                ajaxAction = 'nofb_scan_library';
                break;
            case 'process':
                ajaxAction = 'nofb_process_queue';
                break;
            case 'clear':
                ajaxAction = 'nofb_clear_queue';
                break;
            default:
                return;
        }
        
        // Send request
        $.post(nofbAdminVars.ajaxUrl, {
            action: ajaxAction,
            queue_type: queueType,
            nonce: nofbAdminVars.nonce
        }, function(response) {
            if (response.success) {
                updateStats(queueType, response.data.stats);
                
                $bar.css('width', '100%');
                $status.text(nofbAdminVars.complete);
                
                // Hide progress bar after a delay
                setTimeout(function() {
                    $progress.hide();
                }, 2000);
            } else {
                alert(response.data);
                $progress.hide();
            }
            
            // Enable buttons again
            $('.nofb-actions button').prop('disabled', false);
        }).fail(function() {
            alert(nofbAdminVars.error);
            $progress.hide();
            $('.nofb-actions button').prop('disabled', false);
        });
    }
    
    /**
     * Update queue statistics
     */
    function updateStats(queueType, stats) {
        if (queueType === 'optimization') {
            $('#nofb-optimization-queue-size').text(stats.queue_size);
            $('#nofb-optimization-total').text(stats.total_optimized);
            $('#nofb-optimization-pending').text(stats.pending_optimization);
        } else if (queueType === 'migration') {
            $('#nofb-migration-queue-size').text(stats.queue_size);
            $('#nofb-migration-total').text(stats.total_migrated);
            $('#nofb-migration-pending').text(stats.pending_migration);
        }
    }
    
    // API Connection Test
    $('#nofb-test-api').on('click', function() {
        var button = $(this);
        var resultContainer = $('#nofb-api-test-result');
        
        button.prop('disabled', true).text('Testing...');
        resultContainer.html('<p>Testing connection...</p>');
        
        $.ajax({
            url: nofb_params.ajax_url,
            type: 'POST',
            data: {
                action: 'nofb_test_api_connection',
                nonce: nofb_params.nonce
            },
            success: function(response) {
                button.prop('disabled', false).text('Test Connection');
                
                if (response.success) {
                    var html = '';
                    var results = response.data.results;
                    
                    // nofb API Result
                    html += '<div class="nofb-api-result-item">';
                    html += '<h4>nofb API</h4>';
                    if (results.nofb_api.status === 'success') {
                        html += '<div class="nofb-notice nofb-success"><p>' + results.nofb_api.message + '</p></div>';
                    } else {
                        html += '<div class="nofb-notice nofb-error"><p>' + results.nofb_api.message + '</p></div>';
                    }
                    html += '</div>';
                    
                    // Bunny API Result
                    html += '<div class="nofb-api-result-item">';
                    html += '<h4>Bunny API</h4>';
                    if (results.bunny_api.status === 'success') {
                        html += '<div class="nofb-notice nofb-success"><p>' + results.bunny_api.message + '</p></div>';
                    } else {
                        html += '<div class="nofb-notice nofb-error"><p>' + results.bunny_api.message + '</p></div>';
                    }
                    html += '</div>';
                    
                    resultContainer.html(html);
                } else {
                    var html = '<div class="nofb-notice nofb-error"><p>' + response.data.message + '</p></div>';
                    
                    // Show detailed results if available
                    if (response.data.results) {
                        var results = response.data.results;
                        
                        // nofb API Result
                        html += '<div class="nofb-api-result-item">';
                        html += '<h4>nofb API</h4>';
                        if (results.nofb_api.status === 'success') {
                            html += '<div class="nofb-notice nofb-success"><p>' + results.nofb_api.message + '</p></div>';
                        } else {
                            html += '<div class="nofb-notice nofb-error"><p>' + results.nofb_api.message + '</p></div>';
                        }
                        html += '</div>';
                        
                        // Bunny API Result
                        html += '<div class="nofb-api-result-item">';
                        html += '<h4>Bunny API</h4>';
                        if (results.bunny_api.status === 'success') {
                            html += '<div class="nofb-notice nofb-success"><p>' + results.bunny_api.message + '</p></div>';
                        } else {
                            html += '<div class="nofb-notice nofb-error"><p>' + results.bunny_api.message + '</p></div>';
                        }
                        html += '</div>';
                    }
                    
                    resultContainer.html(html);
                }
            },
            error: function() {
                button.prop('disabled', false).text('Test Connection');
                resultContainer.html('<div class="nofb-notice nofb-error"><p>Connection test failed. Please check your server configuration.</p></div>');
            }
        });
    });
    
    // Auto-Optimize Toggle
    $('#nofb-auto-optimize-toggle').on('change', function() {
        var checkbox = $(this);
        
        $.ajax({
            url: nofb_params.ajax_url,
            type: 'POST',
            data: {
                action: 'nofb_toggle_auto_optimize',
                enabled: checkbox.is(':checked') ? 1 : 0,
                nonce: nofb_params.nonce
            },
            success: function(response) {
                if (!response.success) {
                    alert('Error: ' + response.data.message);
                    checkbox.prop('checked', !checkbox.is(':checked'));
                }
            },
            error: function() {
                alert('Failed to save setting. Please try again.');
                checkbox.prop('checked', !checkbox.is(':checked'));
            }
        });
    });
    
    // Optimization Process
    $('#nofb-optimize-all').on('click', function() {
        var button = $(this);
        var stopButton = $('#nofb-stop-optimization');
        var log = $('#nofb-optimization-log .nofb-log');
        
        button.prop('disabled', true);
        stopButton.prop('disabled', false);
        log.prepend('<div>' + getCurrentTime() + ' Starting optimization process...</div>');
        
        // Enable stop flag for AJAX calls
        window.nofbStopOptimization = false;
        
        // Start the process
        optimizeBatch();
        
        function optimizeBatch() {
            if (window.nofbStopOptimization) {
                log.prepend('<div>' + getCurrentTime() + ' Optimization process stopped by user.</div>');
                button.prop('disabled', false);
                stopButton.prop('disabled', true);
                return;
            }
            
            $.ajax({
                url: nofb_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'nofb_process_optimization_batch',
                    nonce: nofb_params.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Update log
                        if (response.data.messages && response.data.messages.length > 0) {
                            response.data.messages.forEach(function(message) {
                                log.prepend('<div>' + getCurrentTime() + ' ' + message + '</div>');
                            });
                        }
                        
                        // Update progress
                        updateProgress(response.data.progress, response.data.total);
                        
                        // Continue if more files need processing
                        if (response.data.continue) {
                            setTimeout(optimizeBatch, 1000);
                        } else {
                            log.prepend('<div>' + getCurrentTime() + ' Optimization process completed!</div>');
                            button.prop('disabled', false);
                            stopButton.prop('disabled', true);
                            refreshRecentOptimizations();
                        }
                    } else {
                        log.prepend('<div class="error">' + getCurrentTime() + ' Error: ' + response.data.message + '</div>');
                        button.prop('disabled', false);
                        stopButton.prop('disabled', true);
                    }
                },
                error: function() {
                    log.prepend('<div class="error">' + getCurrentTime() + ' Server error occurred. Process stopped.</div>');
                    button.prop('disabled', false);
                    stopButton.prop('disabled', true);
                }
            });
        }
    });
    
    // Stop Optimization Process
    $('#nofb-stop-optimization').on('click', function() {
        window.nofbStopOptimization = true;
        $(this).prop('disabled', true);
    });
    
    // Migration Process
    $('#nofb-migrate-all').on('click', function() {
        var button = $(this);
        var stopButton = $('#nofb-stop-migration');
        var log = $('#nofb-migration-log .nofb-log');
        
        button.prop('disabled', true);
        stopButton.prop('disabled', false);
        log.prepend('<div>' + getCurrentTime() + ' Starting migration process...</div>');
        
        // Enable stop flag for AJAX calls
        window.nofbStopMigration = false;
        
        // Start the process
        migrateBatch();
        
        function migrateBatch() {
            if (window.nofbStopMigration) {
                log.prepend('<div>' + getCurrentTime() + ' Migration process stopped by user.</div>');
                button.prop('disabled', false);
                stopButton.prop('disabled', true);
                return;
            }
            
            $.ajax({
                url: nofb_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'nofb_process_migration_batch',
                    nonce: nofb_params.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Update log
                        if (response.data.messages && response.data.messages.length > 0) {
                            response.data.messages.forEach(function(message) {
                                log.prepend('<div>' + getCurrentTime() + ' ' + message + '</div>');
                            });
                        }
                        
                        // Update progress
                        updateMigrationProgress(response.data.progress, response.data.total);
                        
                        // Continue if more files need processing
                        if (response.data.continue) {
                            setTimeout(migrateBatch, 1000);
                        } else {
                            log.prepend('<div>' + getCurrentTime() + ' Migration process completed!</div>');
                            button.prop('disabled', false);
                            stopButton.prop('disabled', true);
                            refreshRecentMigrations();
                        }
                    } else {
                        log.prepend('<div class="error">' + getCurrentTime() + ' Error: ' + response.data.message + '</div>');
                        button.prop('disabled', false);
                        stopButton.prop('disabled', true);
                    }
                },
                error: function() {
                    log.prepend('<div class="error">' + getCurrentTime() + ' Server error occurred. Process stopped.</div>');
                    button.prop('disabled', false);
                    stopButton.prop('disabled', true);
                }
            });
        }
    });
    
    // Stop Migration Process
    $('#nofb-stop-migration').on('click', function() {
        window.nofbStopMigration = true;
        $(this).prop('disabled', true);
    });
    
    // Load Recent Optimizations
    function refreshRecentOptimizations() {
        var container = $('#nofb-recent-optimizations');
        
        if (container.length === 0) {
            return;
        }
        
        $.ajax({
            url: nofb_params.ajax_url,
            type: 'POST',
            data: {
                action: 'nofb_get_recent_optimizations',
                nonce: nofb_params.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    container.html(response.data.html);
                } else {
                    container.html('<tr><td colspan="6">No recent optimizations found.</td></tr>');
                }
            },
            error: function() {
                container.html('<tr><td colspan="6">Failed to load recent optimizations.</td></tr>');
            }
        });
    }
    
    // Load Recent Migrations
    function refreshRecentMigrations() {
        var container = $('#nofb-recent-migrations');
        
        if (container.length === 0) {
            return;
        }
        
        $.ajax({
            url: nofb_params.ajax_url,
            type: 'POST',
            data: {
                action: 'nofb_get_recent_migrations',
                nonce: nofb_params.nonce
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    container.html(response.data.html);
                } else {
                    container.html('<tr><td colspan="6">No recent migrations found.</td></tr>');
                }
            },
            error: function() {
                container.html('<tr><td colspan="6">Failed to load recent migrations.</td></tr>');
            }
        });
    }
    
    // Helper function to update progress
    function updateProgress(progress, total) {
        var progressBar = $('.nofb-progress-container .nofb-progress');
        var percentage = total > 0 ? Math.round((progress / total) * 100) : 0;
        
        progressBar.css('width', percentage + '%');
        
        // Update the info text if it exists
        $('.nofb-progress-info p').text(progress + ' / ' + total + ' files processed (' + percentage + '%)');
    }
    
    // Helper function to update migration progress
    function updateMigrationProgress(progress, total) {
        var progressBar = $('#nofb-migration .nofb-progress-container .nofb-progress');
        var percentage = total > 0 ? Math.round((progress / total) * 100) : 0;
        
        progressBar.css('width', percentage + '%');
        
        // Update the info text if it exists
        $('#nofb-migration .nofb-progress-info p').text(progress + ' / ' + total + ' files processed (' + percentage + '%)');
    }
    
    // Helper function to get current time string
    function getCurrentTime() {
        var now = new Date();
        var hours = String(now.getHours()).padStart(2, '0');
        var minutes = String(now.getMinutes()).padStart(2, '0');
        var seconds = String(now.getSeconds()).padStart(2, '0');
        
        return '[' + hours + ':' + minutes + ':' + seconds + ']';
    }
    
    // Initialize recent data on page load
    if ($('#nofb-recent-optimizations').length > 0) {
        refreshRecentOptimizations();
    }
    
    if ($('#nofb-recent-migrations').length > 0) {
        refreshRecentMigrations();
    }
    
    // FAQ Toggle
    $('.nofb-faq-item h3').on('click', function() {
        $(this).next('.nofb-faq-content').slideToggle();
    });
    
    /**
     * Initialize queue reinitialization handlers
     */
    function initQueueReinitialize() {
        // Optimization queue reinitialize
        $('#nofb-reinitialize-optimization-queue').on('click', function() {
            if (confirm('This will rebuild the optimization queue with proper file paths. Continue?')) {
                reinitializeQueue('optimization');
            }
        });
        
        // Migration queue reinitialize
        $('#nofb-reinitialize-migration-queue').on('click', function() {
            if (confirm('This will rebuild the migration queue with proper file paths. Continue?')) {
                reinitializeQueue('migration');
            }
        });
    }
    
    /**
     * Reinitialize a queue to fix path issues
     */
    function reinitializeQueue(queueType) {
        // Disable buttons
        $('.nofb-actions button').prop('disabled', true);
        
        // Show notification
        const log = queueType === 'optimization' ? 
                   $('#nofb-optimization-log .nofb-log') : 
                   $('#nofb-migration-log .nofb-log');
        
        log.prepend('<p>[' + getCurrentTime() + '] Reinitializing queue...</p>');
        
        // Make API request
        $.ajax({
            url: nofb_params.ajax_url,
            type: 'POST',
            data: {
                action: 'nofb_reinitialize_queue',
                queue_type: queueType,
                nonce: nofb_params.nonce
            },
            success: function(response) {
                if (response.success) {
                    log.prepend('<p>[' + getCurrentTime() + '] ' + response.data.message + '</p>');
                    
                    // Update eligibility count
                    if (queueType === 'optimization') {
                        $('#nofb-optimize-all').prop('disabled', response.data.added === 0);
                    } else {
                        $('#nofb-migrate-all').prop('disabled', response.data.added === 0);
                    }
                } else {
                    log.prepend('<p>[' + getCurrentTime() + '] Error: ' + response.data.message + '</p>');
                }
                
                // Re-enable buttons
                $('.nofb-actions button').prop('disabled', false);
            },
            error: function() {
                log.prepend('<p>[' + getCurrentTime() + '] Server error while reinitializing queue.</p>');
                $('.nofb-actions button').prop('disabled', false);
            }
        });
    }
})(jQuery); 