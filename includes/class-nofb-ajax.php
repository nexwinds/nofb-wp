<?php
/**
 * NOFB AJAX Class
 * Handles AJAX requests from the admin interface
 */

if (!defined('ABSPATH')) {
    exit;
}

class NOFB_AJAX {
    
    public function __construct() {
        // Register AJAX actions
        add_action('wp_ajax_nofb_process_optimization_batch', array($this, 'process_optimization_batch'));
        add_action('wp_ajax_nofb_process_migration_batch', array($this, 'process_migration_batch'));
        add_action('wp_ajax_nofb_get_recent_optimizations', array($this, 'get_recent_optimizations'));
        add_action('wp_ajax_nofb_get_recent_migrations', array($this, 'get_recent_migrations'));
        add_action('wp_ajax_nofb_toggle_auto_optimize', array($this, 'toggle_auto_optimize'));
        add_action('wp_ajax_nofb_test_api_connection', array($this, 'test_api_connection'));
        add_action('wp_ajax_nofb_reinitialize_queue', array($this, 'reinitialize_queue'));
        add_action('wp_ajax_nofb_check_config_status', array($this, 'check_config_status'));
    }
    
    /**
     * Centralized security verification
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    private function verify_security() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'nofb_nonce')) {
            return new WP_Error('security_failed', __('Security check failed.', 'nexoffload-for-bunny'));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            return new WP_Error('permission_denied', __('Permission denied.', 'nexoffload-for-bunny'));
        }
        
        return true;
    }
    
    /**
     * Send standardized error response
     * @param WP_Error $error Error object
     * @param array $additional_data Additional data to include
     */
    private function send_error_response($error, $additional_data = array()) {
        $response = array_merge(array('message' => $error->get_error_message()), $additional_data);
        wp_send_json_error($response);
    }
    
    /**
     * Send standardized success response
     * @param array $data Response data
     */
    private function send_success_response($data) {
        wp_send_json_success($data);
    }
    
    /**
     * Validate and normalize file batch
     * @param array $batch Array of file paths
     * @param object $processor Optimizer or Migrator instance
     * @param object $queue Queue instance
     * @return array Array with 'valid_batch' and 'skipped_files' keys
     */
    private function validate_file_batch($batch, $processor, $queue) {
        $valid_batch = array();
        $skipped_files = 0;
        
        foreach ($batch as $file_path) {
            $normalized_path = $processor->normalize_file_path($file_path);
            
            if (file_exists($normalized_path) && is_readable($normalized_path)) {
                $valid_batch[] = $normalized_path;
            } else {
                // Try environment path remapping for optimization
                if (method_exists($processor, 'remap_environment_path')) {
                    $remapped_path = $processor->remap_environment_path($file_path);
                    if ($remapped_path !== $file_path && file_exists($remapped_path) && is_readable($remapped_path)) {
                        $valid_batch[] = $remapped_path;
                        continue;
                    }
                }
                
                $this->log_debug('File does not exist or is not readable: ' . $file_path);
                $skipped_files++;
                $queue->remove_batch(array($file_path));
            }
        }
        
        return array(
            'valid_batch' => $valid_batch,
            'skipped_files' => $skipped_files
        );
    }
    
    /**
     * Log debug message when WP_DEBUG is enabled
     * @param string $message Message to log
     */
    private function log_debug($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            do_action('nofb_log_error', 'nofb AJAX: ' . $message);
        }
    }
    
    /**
     * Get cached database results with fallback query
     * @param string $cache_key Cache key
     * @param callable $query_callback Callback that returns query results
     * @param int $cache_duration Cache duration in seconds (default 300)
     * @return mixed Cached or fresh query results
     */
    private function get_cached_query_results($cache_key, $query_callback, $cache_duration = 300) {
        $results = wp_cache_get($cache_key);
        
        if ($results === false) {
            $results = call_user_func($query_callback);
            wp_cache_set($cache_key, $results, '', $cache_duration);
        }
        
        return $results;
    }
    
    /**
     * Batch resolve attachment IDs from file paths
     * @param array $file_paths Array of file paths
     * @return array Associative array mapping file paths to attachment IDs
     */
    private function batch_resolve_attachment_ids($file_paths) {
        if (empty($file_paths)) {
            return array();
        }
        
        $upload_dir = wp_upload_dir();
        $attachment_map = array();
        $uncached_paths = array();
        
        // Check cache first
        foreach ($file_paths as $file_path) {
            $relative_path = str_replace($upload_dir['basedir'] . '/', '', $file_path);
            $cache_key = 'nofb_attachment_id_' . md5($relative_path);
            $attachment_id = wp_cache_get($cache_key);
            
            if ($attachment_id !== false) {
                $attachment_map[$file_path] = $attachment_id;
            } else {
                $uncached_paths[] = array('file_path' => $file_path, 'relative_path' => $relative_path);
            }
        }
        
        // Batch query for uncached paths
        if (!empty($uncached_paths)) {
            global $wpdb;
            $relative_paths = array_column($uncached_paths, 'relative_path');
            $placeholders = implode(',', array_fill(0, count($relative_paths), '%s'));
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct DB query needed for complex attachment mapping that requires specific SQL and custom caching is implemented below
            $query = $wpdb->prepare(
                sprintf(
                    "SELECT post_id, meta_value
                     FROM {$wpdb->postmeta}
                     WHERE meta_key = '_wp_attached_file'
                     AND meta_value IN (%s)",
                    implode(',', array_fill(0, count($relative_paths), '%s'))
                ),
                $relative_paths
            );
            /* phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- $query is properly prepared above; Complex SQL needed for attachment mapping with custom caching */
            $results = $wpdb->get_results($query);
            
            // Map results and cache
            $db_map = array();
            foreach ($results as $result) {
                $db_map[$result->meta_value] = $result->post_id;
            }
            
            foreach ($uncached_paths as $path_data) {
                $file_path = $path_data['file_path'];
                $relative_path = $path_data['relative_path'];
                $attachment_id = isset($db_map[$relative_path]) ? $db_map[$relative_path] : null;
                
                // Try pattern matching if direct match failed
                if (!$attachment_id) {
                    $path_info = pathinfo($relative_path);
                    $base_path = $path_info['dirname'] . '/' . $path_info['filename'];
                    
                    foreach ($db_map as $db_path => $db_id) {
                        if (strpos($db_path, $base_path) === 0) {
                            $attachment_id = $db_id;
                            break;
                        }
                    }
                }
                
                $attachment_map[$file_path] = $attachment_id;
                
                // Cache result
                $cache_key = 'nofb_attachment_id_' . md5($relative_path);
                wp_cache_set($cache_key, $attachment_id, '', DAY_IN_SECONDS);
            }
        }
        
        return $attachment_map;
    }
    
    /**
     * Generate HTML for recent items table
     * @param array $items Array of item data
     * @param string $type Type of items ('optimizations' or 'migrations')
     * @return string Generated HTML
     */
    private function generate_recent_items_html($items, $type) {
        if (empty($items)) {
            $colspan = ($type === 'optimizations') ? '6' : '6';
            $message = ($type === 'optimizations') 
                ? __('No recent optimizations found.', 'nexoffload-for-bunny')
                : __('No recent migrations found.', 'nexoffload-for-bunny');
            return '<tr><td colspan="' . $colspan . '">' . $message . '</td></tr>';
        }
        
        $html = '';
        foreach ($items as $item) {
            if ($type === 'optimizations') {
                $html .= $this->generate_optimization_row_html($item);
            } else {
                $html .= $this->generate_migration_row_html($item);
            }
        }
        
        return $html;
    }
    
    /**
     * Generate HTML for optimization table row
     * @param object $item Optimization item data
     * @return string Generated HTML row
     */
    private function generate_optimization_row_html($item) {
        $attachment_id = $item->attachment_id;
        $attachment = get_post($attachment_id);
        if (!$attachment) {
            return '';
        }
        
        $file_path = get_attached_file($attachment_id);
        if (!$file_path) {
            return '';
        }
        
        $thumbnail = wp_get_attachment_image($attachment_id, 'thumbnail');
        
        // Get optimization data from post meta
        $optimization_data = get_post_meta($attachment_id, '_nofb_optimization_data', true);
        
        // Use API-provided values when available
        if (is_array($optimization_data) && !empty($optimization_data)) {
            // API values are stored in KB, so multiply by 1 to keep them in KB
            $original_size = isset($optimization_data['originalSize']) ? floatval($optimization_data['originalSize']) : 0;
            $optimized_size = isset($optimization_data['compressedSize']) ? floatval($optimization_data['compressedSize']) : 0;
            $savings = isset($optimization_data['compressionRatio']) ? floatval($optimization_data['compressionRatio']) : 0;
        } else {
            // Fallback to stored file size metadata
            $original_size = get_post_meta($attachment_id, '_nofb_original_size', true);
            $optimized_size = get_post_meta($attachment_id, '_nofb_file_size', true);
            
            // Convert bytes to KB
            $original_size = $original_size ? round($original_size / 1024, 2) : 0;
            $optimized_size = $optimized_size ? round($optimized_size / 1024, 2) : 0;
            
            // Calculate savings percentage
            $savings = $original_size > 0 ? round(100 - ($optimized_size / $original_size * 100), 1) : 0;
        }
        
        return sprintf(
            '<tr><td>%s</td><td>%s</td><td>%s KB</td><td>%s KB</td><td>%s%%</td><td>%s</td></tr>',
            $thumbnail,
            basename($file_path),
            $original_size,
            $optimized_size,
            $savings,
            date_i18n(get_option('date_format'), strtotime($item->optimization_date))
        );
    }
    
    /**
     * Generate HTML for migration table row
     * @param object $item Migration item data
     * @return string Generated HTML row
     */
    private function generate_migration_row_html($item) {
        $attachment_id = $item->attachment_id;
        $attachment = get_post($attachment_id);
        if (!$attachment) {
            return '';
        }
        
        $file_path = get_attached_file($attachment_id);
        $file_size = get_post_meta($attachment_id, '_nofb_file_size', true);
        $file_size = $file_size ? round($file_size / 1024, 2) : 0;
        $bunny_url = $item->bunny_url;
        
        $thumbnail = wp_get_attachment_image($attachment_id, 'thumbnail');
        
        return sprintf(
            '<tr><td>%s</td><td>%s</td><td>%s</td><td><a href="%s" target="_blank">%s</a></td><td>%s KB</td><td>%s</td></tr>',
            $thumbnail,
            basename($file_path),
            $file_path,
            $bunny_url,
            $bunny_url,
            $file_size,
            date_i18n(get_option('date_format'), strtotime($item->migration_date))
        );
    }
    
    /**
     * Get batch for processing with fallback scanning
     * @param object $queue Queue instance
     * @param int $batch_size Batch size
     * @param string $type Processing type ('optimization' or 'migration')
     * @return array Batch of files
     */
    private function get_processing_batch($queue, $batch_size, $type) {
        $batch = $queue->get_batch($batch_size);
        
        if (empty($batch)) {
            // Scan for eligible files
            if ($type === 'optimization') {
                $queue->scan_media_library();
            } else {
                $queue->scan_media_library_for_migration();
            }
            
            $batch = $queue->get_batch($batch_size);
            
            // Additional fallback for optimization
            if (empty($batch) && $type === 'optimization') {
                $batch = $this->get_optimization_fallback_batch($batch_size);
            }
        }
        
        return $batch;
    }
    
    /**
     * Get fallback batch for optimization when queue is empty
     * @param int $batch_size Batch size
     * @return array Batch of files
     */
    private function get_optimization_fallback_batch($batch_size) {
        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => 50,
            'post_status' => 'inherit'
        );
        
        $attachments = get_posts($args);
        $optimizer = new NOFB_Optimizer();
        $queue = new NOFB_Queue('optimization');
        $batch = array();
        
        foreach ($attachments as $attachment) {
            $file_path = $optimizer->get_attachment_path($attachment->ID);
            
            if (!$file_path) {
                continue;
            }
            
            if ($optimizer->is_eligible_for_optimization($file_path)) {
                $batch[] = $file_path;
                $queue->add($file_path);
                
                if (count($batch) >= $batch_size) {
                    break;
                }
            }
        }
        
        return $batch;
    }
    
    /**
     * Process optimization batch
     */
    public function process_optimization_batch() {
        $security_check = $this->verify_security();
        if (is_wp_error($security_check)) {
            $this->send_error_response($security_check);
            return;
        }
        
        // Get eligible files
        $eligibility = new NOFB_Eligibility();
        $stats = $eligibility->get_optimization_stats();
        
        // If no eligible files, scan the queue and check again before returning
        if ($stats['eligible_total'] === 0) {
            $queue = new NOFB_Queue('optimization');
            $added = $queue->scan_media_library();
            
            if ($added > 0) {
                $stats = $eligibility->get_optimization_stats();
            }
            
            if ($stats['eligible_total'] === 0) {
                $this->send_success_response(array(
                    'continue' => false,
                    'progress' => 0,
                    'total' => 0,
                    'messages' => array(__('No eligible files found for optimization.', 'nexoffload-for-bunny'))
                ));
                return;
            }
        }
        
        // Process batch
        $optimizer = new NOFB_Optimizer();
        $queue = new NOFB_Queue('optimization');
        
        global $nofb_optimization_batch_size;
        
        // Get batch with fallback
        $batch = $this->get_processing_batch($queue, $nofb_optimization_batch_size, 'optimization');
        
        if (empty($batch)) {
            $this->send_success_response(array(
                'continue' => false,
                'progress' => 0,
                'total' => 0,
                'messages' => array(__('No files found for optimization.', 'nexoffload-for-bunny'))
            ));
            return;
        }
        
        // Validate files in batch
        $validation_result = $this->validate_file_batch($batch, $optimizer, $queue);
        $valid_batch = $validation_result['valid_batch'];
        $skipped_files = $validation_result['skipped_files'];
        
        if (empty($valid_batch)) {
            $this->send_error_response(
                new WP_Error('invalid_batch', __('All files in batch were invalid or unreadable.', 'nexoffload-for-bunny')),
                // translators: %d is the number of files that were skipped
                array('messages' => array(sprintf(__('Skipped %d invalid files.', 'nexoffload-for-bunny'), $skipped_files)))
            );
            return;
        }
        
        // Process batch with valid files
        try {
            // Check API credentials
            if (!defined('NOFB_API_KEY') || empty(NOFB_API_KEY)) {
                $this->send_error_response(
                    new WP_Error('api_not_configured', __('API credentials not configured.', 'nexoffload-for-bunny')),
                    array('messages' => array(__('Please define NOFB_API_KEY in your wp-config.php file to enable image optimization.', 'nexoffload-for-bunny')))
                );
                return;
            }
            
            $result = $optimizer->optimize_batch($valid_batch);
            $this->handle_optimization_result($result, $valid_batch, $skipped_files, $eligibility, $queue);
            
        } catch (Exception $e) {
            $this->log_debug('Exception: ' . $e->getMessage());
            $this->send_error_response(
                new WP_Error('optimization_exception', __('Optimization failed with an exception.', 'nexoffload-for-bunny')),
                array('messages' => array($e->getMessage()))
            );
        }
    }
    
    /**
     * Handle optimization result and send response
     * @param mixed $result Optimization result
     * @param array $valid_batch Valid files batch
     * @param int $skipped_files Number of skipped files
     * @param object $eligibility Eligibility instance
     * @param object $queue Queue instance
     */
    private function handle_optimization_result($result, $valid_batch, $skipped_files, $eligibility, $queue) {
        $updated_stats = $eligibility->get_optimization_stats();
        $optimized_count = $updated_stats['total_images'] - $updated_stats['not_optimized'];
        $total_eligible = $optimized_count + $updated_stats['eligible_total'];
        
        $messages = array();
        
        if ($skipped_files > 0) {
            // translators: %d is the number of files that were skipped
            $messages[] = sprintf(__('Skipped %d invalid files.', 'nexoffload-for-bunny'), $skipped_files);
        }
        
        if ($result) {
            // translators: %d is the number of files that were successfully optimized
            $messages[] = sprintf(__('Successfully optimized %d files.', 'nexoffload-for-bunny'), is_array($result) ? count($result) : $result);
            $queue->remove_batch($valid_batch);
            
            $this->send_success_response(array(
                'continue' => ($updated_stats['eligible_total'] > 0),
                'progress' => $optimized_count,
                'total' => $total_eligible,
                'messages' => $messages
            ));
        } else {
            $messages[] = __('Failed to optimize files. Please check your API configuration.', 'nexoffload-for-bunny');
            $this->send_error_response(
                new WP_Error('optimization_failed', __('Optimization failed.', 'nexoffload-for-bunny')),
                array('messages' => $messages)
            );
        }
    }
    
    /**
     * Process migration batch
     */
    public function process_migration_batch() {
        $security_check = $this->verify_security();
        if (is_wp_error($security_check)) {
            $this->send_error_response($security_check);
            return;
        }
        
        // Get eligible files
        $eligibility = new NOFB_Eligibility();
        $stats = $eligibility->get_migration_stats();
        
        if ($stats['eligible_total'] === 0) {
            $this->send_success_response(array(
                'continue' => false,
                'progress' => 0,
                'total' => 0,
                'messages' => array(__('No eligible files found for migration.', 'nexoffload-for-bunny'))
            ));
            return;
        }
        
        // Process batch
        $migrator = new NOFB_Migrator();
        $queue = new NOFB_Queue('migration');
        
        // Get batch of files (up to 3 for migration since these can be large files)
        $batch = $this->get_processing_batch($queue, 3, 'migration');
        
        if (empty($batch)) {
            $this->send_success_response(array(
                'continue' => false,
                'progress' => 0,
                'total' => 0,
                'messages' => array(__('No files found for migration. Try updating your settings to include more file types.', 'nexoffload-for-bunny'))
            ));
            return;
        }
        
        // Reset problematic migrations
        $this->reset_problematic_migrations($batch);
        
        // Validate files in batch
        $validation_result = $this->validate_file_batch($batch, $migrator, $queue);
        $valid_batch = $validation_result['valid_batch'];
        $skipped_files = $validation_result['skipped_files'];
        
        if (empty($valid_batch)) {
            $this->send_error_response(
                new WP_Error('invalid_batch', __('All files in batch were invalid or unreadable.', 'nexoffload-for-bunny')),
                // translators: %d is the number of files that were skipped
                array('messages' => array(sprintf(__('Skipped %d invalid files.', 'nexoffload-for-bunny'), $skipped_files)))
            );
            return;
        }
        
        // Process batch with valid files
        try {
            $result = $migrator->migrate_batch($valid_batch);
            $this->handle_migration_result($result, $valid_batch, $skipped_files, $eligibility, $queue);
            
        } catch (Exception $e) {
            $this->log_debug('Exception: ' . $e->getMessage());
            $this->send_error_response(
                new WP_Error('migration_exception', __('Migration failed with an exception.', 'nexoffload-for-bunny')),
                array('messages' => array($e->getMessage()))
            );
        }
    }
    
    /**
     * Handle migration result and send response
     * @param mixed $result Migration result
     * @param array $valid_batch Valid files batch
     * @param int $skipped_files Number of skipped files
     * @param object $eligibility Eligibility instance
     * @param object $queue Queue instance
     */
    private function handle_migration_result($result, $valid_batch, $skipped_files, $eligibility, $queue) {
        $updated_stats = $eligibility->get_migration_stats();
        $migrated_count = $updated_stats['total_images'] - $updated_stats['not_migrated'];
        $total_eligible = $migrated_count + $updated_stats['eligible_total'];
        
        $messages = array();
        
        if ($skipped_files > 0) {
            // translators: %d is the number of files that were skipped
            $messages[] = sprintf(__('Skipped %d invalid files.', 'nexoffload-for-bunny'), $skipped_files);
        }
        
        if ($result) {
            // translators: %d is the number of files that were successfully migrated
            $messages[] = sprintf(__('Successfully migrated %d files.', 'nexoffload-for-bunny'), $result);
            $queue->remove_batch($valid_batch);
            
            $this->send_success_response(array(
                'continue' => ($updated_stats['eligible_total'] > 0),
                'progress' => $migrated_count,
                'total' => $total_eligible,
                'messages' => $messages
            ));
        } else {
            $messages[] = __('Failed to migrate files. Check PHP error logs for details.', 'nexoffload-for-bunny');
            $this->log_debug('Migration failed for batch. Result was: ' . wp_json_encode($result));
            
            $this->send_error_response(
                new WP_Error('migration_failed', __('Migration failed.', 'nexoffload-for-bunny')),
                array('messages' => $messages)
            );
        }
    }
    
    /**
     * Get recent optimizations
     */
    public function get_recent_optimizations() {
        $security_check = $this->verify_security();
        if (is_wp_error($security_check)) {
            $this->send_error_response($security_check);
            return;
        }
        
        $cache_key = 'nofb_recent_optimizations';
        $recent_items = $this->get_cached_query_results($cache_key, function() {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query necessary for performance with proper caching when joining multiple tables
            $recent_optimizations = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.ID as attachment_id, pm.meta_value as optimization_date 
                    FROM {$wpdb->posts} p
                    JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    WHERE p.post_type = 'attachment'
                    AND p.post_status = 'inherit'
                    AND pm.meta_key = %s
                    ORDER BY pm.meta_value DESC
                    LIMIT 10",
                    '_nofb_optimization_date'
                )
            );
            
            $recent_ids = array();
            if (!empty($recent_optimizations)) {
                foreach ($recent_optimizations as $item) {
                    $recent_ids[] = (object) array(
                        'attachment_id' => $item->attachment_id,
                        'optimization_date' => $item->optimization_date
                    );
                }
            }
            
            return $recent_ids;
        });
        
        $html = $this->generate_recent_items_html($recent_items, 'optimizations');
        $this->send_success_response(array('html' => $html));
    }
    
    /**
     * Get recent migrations
     */
    public function get_recent_migrations() {
        $security_check = $this->verify_security();
        if (is_wp_error($security_check)) {
            $this->send_error_response($security_check);
            return;
        }
        
        $cache_key = 'nofb_recent_migrations';
        $recent_items = $this->get_cached_query_results($cache_key, function() {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query necessary for performance with proper caching when retrieving recent migrations
            $recent_migrations = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.ID as attachment_id, 
                           pm1.meta_value as migration_date,
                           pm2.meta_value as bunny_url
                    FROM {$wpdb->posts} p
                    JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id
                    JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id
                    WHERE p.post_type = 'attachment'
                    AND p.post_status = 'inherit'
                    AND pm1.meta_key = %s
                    AND pm2.meta_key = %s
                    ORDER BY pm1.meta_value DESC
                    LIMIT 10",
                    '_nofb_migration_date',
                    '_nofb_bunny_url'
                )
            );
            
            $recent_ids = array();
            if (!empty($recent_migrations)) {
                foreach ($recent_migrations as $item) {
                    $recent_ids[] = (object) array(
                        'attachment_id' => $item->attachment_id,
                        'migration_date' => $item->migration_date,
                        'bunny_url' => $item->bunny_url
                    );
                }
            }
            
            return $recent_ids;
        });
        
        $html = $this->generate_recent_items_html($recent_items, 'migrations');
        $this->send_success_response(array('html' => $html));
    }
    
    /**
     * Toggle auto optimize setting
     */
    public function toggle_auto_optimize() {
        $security_check = $this->verify_security();
        if (is_wp_error($security_check)) {
            $this->send_error_response($security_check);
            return;
        }
        
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Security check handled by verify_security()
        $enabled = isset($_POST['enabled']) ? (bool) $_POST['enabled'] : false;
        update_option('nofb_auto_optimize', $enabled);
        
        $this->send_success_response(array('enabled' => $enabled));
    }
    
    /**
     * Test API connection
     */
    public function test_api_connection() {
        $security_check = $this->verify_security();
        if (is_wp_error($security_check)) {
            $this->send_error_response($security_check);
            return;
        }
        
        $results = array(
            'nofb_api' => array(
                'status' => 'error',
                'message' => __('nofb API Key not defined in wp-config.php', 'nexoffload-for-bunny')
            ),
            'bunny_api' => array(
                'status' => 'error',
                'message' => __('Bunny API Key not defined in wp-config.php', 'nexoffload-for-bunny')
            )
        );
        
        // Check nofb API configuration
        $NOFB_API_KEY_configured = defined('NOFB_API_KEY') && !empty(NOFB_API_KEY);
        if ($NOFB_API_KEY_configured) {
            // Test nofb API connection
            $api = new NOFB_API();
            $nofb_result = $api->test_connection();
            $results['nofb_api'] = $nofb_result;
        }
        
        // Check Bunny API configuration
        $bunny_api_key_configured = defined('BUNNY_API_KEY') && !empty(BUNNY_API_KEY) && 
                                   defined('BUNNY_STORAGE_ZONE') && !empty(BUNNY_STORAGE_ZONE);
        if ($bunny_api_key_configured) {
            // Test Bunny API connection
            $migrator = new NOFB_Migrator();
            $bunny_result = $migrator->test_connection();
            $results['bunny_api'] = $bunny_result;
        }
        
        // Set overall status
        $overall_success = ($NOFB_API_KEY_configured && $results['nofb_api']['status'] === 'success') || 
                          ($bunny_api_key_configured && $results['bunny_api']['status'] === 'success');
        
        if ($overall_success) {
            $this->send_success_response(array(
                'message' => __('Connection test completed. See results for details.', 'nexoffload-for-bunny'),
                'results' => $results
            ));
        } else {
            $this->send_error_response(
                new WP_Error('api_connection_failed', __('API connection failed. Check wp-config.php configuration.', 'nexoffload-for-bunny')),
                array('results' => $results)
            );
        }
    }
    
    /**
     * Check configuration status for debugging
     */
    public function check_config_status() {
        $security_check = $this->verify_security();
        if (is_wp_error($security_check)) {
            $this->send_error_response($security_check);
            return;
        }
        
        // Gather configuration status
        $status = array(
            'NOFB_API_KEY' => array(
                'defined' => defined('NOFB_API_KEY'),
                'empty' => defined('NOFB_API_KEY') ? empty(NOFB_API_KEY) : true,
                'length' => defined('NOFB_API_KEY') && !empty(NOFB_API_KEY) ? strlen(NOFB_API_KEY) : 0
            ),
            'NOFB_API_REGION' => array(
                'defined' => defined('NOFB_API_REGION'),
                'value' => defined('NOFB_API_REGION') ? NOFB_API_REGION : 'not set'
            ),
            'BUNNY_API_KEY' => array(
                'defined' => defined('BUNNY_API_KEY'),
                'empty' => defined('BUNNY_API_KEY') ? empty(BUNNY_API_KEY) : true
            ),
            'BUNNY_STORAGE_ZONE' => array(
                'defined' => defined('BUNNY_STORAGE_ZONE'),
                'empty' => defined('BUNNY_STORAGE_ZONE') ? empty(BUNNY_STORAGE_ZONE) : true
            ),
            'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
            'wp_debug_log' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG
        );
        
        $this->send_success_response(array(
            'message' => __('Configuration status retrieved.', 'nexoffload-for-bunny'),
            'status' => $status
        ));
    }
    
    /**
     * Reset migration status for files that appear to be on CDN but aren't actually migrated
     * @param array $file_paths Array of file paths to check and reset
     * @return int Number of files reset
     */
    private function reset_problematic_migrations($file_paths) {
        if (empty($file_paths)) {
            return 0;
        }
        
        // Batch resolve attachment IDs for efficiency
        $attachment_map = $this->batch_resolve_attachment_ids($file_paths);
        $reset_count = 0;
        
        foreach ($file_paths as $file_path) {
            $attachment_id = $attachment_map[$file_path] ?? null;
            
            if (!$attachment_id) {
                continue;
            }
            
            $reset_count += $this->reset_attachment_migration_status($attachment_id, $file_path);
        }
        
        return $reset_count;
    }
    
    /**
     * Reset migration status for a specific attachment
     * @param int $attachment_id Attachment ID
     * @param string $file_path File path for logging
     * @return int 1 if reset, 0 if not
     */
    private function reset_attachment_migration_status($attachment_id, $file_path) {
        $attachment_url = wp_get_attachment_url($attachment_id);
        $is_url_on_cdn = (strpos($attachment_url, 'bunnycdn.com') !== false || 
                         strpos($attachment_url, defined('BUNNY_CUSTOM_HOSTNAME') ? BUNNY_CUSTOM_HOSTNAME : '') !== false);
        
        $is_migrated = get_post_meta($attachment_id, '_nofb_migrated', true);
        
        if ($is_url_on_cdn && $is_migrated !== '1') {
            // URL says it's on CDN but metadata doesn't confirm - reset URL
            update_post_meta($attachment_id, '_wp_attachment_url', '');
            $this->log_debug('Reset attachment URL for ID ' . $attachment_id . ' (' . basename($file_path) . ')');
            return 1;
        }
        
        if (!$is_url_on_cdn && $is_migrated === '1') {
            // Metadata says it's migrated but URL doesn't match - reset metadata
            delete_post_meta($attachment_id, '_nofb_migrated');
            delete_post_meta($attachment_id, '_nofb_bunny_url');
            delete_post_meta($attachment_id, '_nofb_migration_date');
            $this->log_debug('Reset migration metadata for ID ' . $attachment_id . ' (' . basename($file_path) . ')');
            return 1;
        }
        
        return 0;
    }
    
    /**
     * Reinitialize queue with normalized paths
     * This is a recovery function to rebuild the queue with proper paths
     */
    public function reinitialize_queue() {
        $security_check = $this->verify_security();
        if (is_wp_error($security_check)) {
            $this->send_error_response($security_check);
            return;
        }
        
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Security check handled by verify_security()
        $queue_type = isset($_POST['queue_type']) ? sanitize_text_field(wp_unslash($_POST['queue_type'])) : 'optimization';
        if (!in_array($queue_type, array('optimization', 'migration'))) {
            $queue_type = 'optimization';
        }
        
        // Clear and rebuild the queue
        $queue = new NOFB_Queue($queue_type);
        $queue->clear();
        
        // Process attachments and add eligible ones to queue
        $added = $this->populate_queue_with_eligible_files($queue, $queue_type);
        
        // Get eligibility stats
        $eligibility = new NOFB_Eligibility();
        $stats = ($queue_type === 'optimization') 
            ? $eligibility->get_optimization_stats()
            : $eligibility->get_migration_stats();
        
        $this->send_success_response(array(
            'queue_type' => $queue_type,
            'added' => $added,
            'queue_size' => $queue->get_size(),
            'eligible_count' => $stats['eligible_total'],
            // translators: %d is the number of files that were added to the queue
            'message' => sprintf(__('Queue reinitialized. Added %d files.', 'nexoffload-for-bunny'), $added)
        ));
    }
    
    /**
     * Populate queue with eligible files
     * @param object $queue Queue instance
     * @param string $queue_type Type of queue ('optimization' or 'migration')
     * @return int Number of files added
     */
    private function populate_queue_with_eligible_files($queue, $queue_type) {
        $this->log_debug('Populating ' . $queue_type . ' queue with eligible files...');
        
        // Use different mime types based on queue type
        $mime_types = array('image/jpeg', 'image/jpg', 'image/png');
        
        if ($queue_type === 'migration') {
            // For migration, include all supported formats
            $mime_types = array(
                'image/webp', 'image/avif', 'image/svg+xml'
            );
        }
        
        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => $mime_types,
            'posts_per_page' => -1,
            'post_status' => 'inherit'
        );
        
        $this->log_debug('Querying attachments with mime types: ' . implode(', ', $mime_types));
        $attachments = get_posts($args);
        $this->log_debug('Found ' . count($attachments) . ' total attachments');
        
        $added = 0;
        $skipped = 0;
        
        // Initialize processor based on queue type
        $processor = ($queue_type === 'optimization') ? new NOFB_Optimizer() : new NOFB_Migrator();
        
        foreach ($attachments as $attachment) {
            $file_path = get_attached_file($attachment->ID);
            
            if (!$file_path) {
                $skipped++;
                continue;
            }
            
            $normalized_path = $processor->normalize_file_path($file_path);
            
            if (!file_exists($normalized_path)) {
                $skipped++;
                continue;
            }
            
            // Check eligibility based on queue type
            $is_eligible = false;
            try {
                $is_eligible = ($queue_type === 'optimization')
                    ? $processor->is_eligible_for_optimization($normalized_path)
                    : $processor->is_eligible_for_migration($normalized_path);
            } catch (Exception $e) {
                $this->log_debug('Error checking eligibility: ' . $e->getMessage());
                $skipped++;
                continue;
            }
            
            if ($is_eligible) {
                $queue->add($normalized_path);
                $added++;
            } else {
                $skipped++;
            }
        }
        
        $this->log_debug('Added ' . $added . ' files to ' . $queue_type . ' queue, skipped ' . $skipped . ' files');
        return $added;
    }
} 