<?php
/**
 * NOFB Queue Class
 * Manages internal queues for optimization and migration
 */

if (!defined('ABSPATH')) {
    exit;
}

class NOFB_Queue {
    
    private $queue_type;
    private $option_name;
    private $max_queue_size = NOFB_MAX_INTERNAL_QUEUE; // 100 items
    
    public function __construct($queue_type) {
        $this->queue_type = $queue_type;
        $this->option_name = 'nofb_' . $queue_type . '_queue';
    }
    
    /**
     * Get cache key for specific operation
     * @param string $operation Operation name
     * @return string Cache key
     */
    private function get_cache_key($operation) {
        return 'nofb_' . $this->queue_type . '_' . $operation;
    }
    
    /**
     * Get cached count with fallback query
     * @param string $meta_key Meta key to count
     * @param string $meta_value Meta value to match
     * @param string $operation Operation name for cache key
     * @param int $cache_duration Cache duration in seconds
     * @return int Count result
     */
    private function get_cached_count($meta_key, $meta_value, $operation, $cache_duration = 300) {
        $cache_key = $this->get_cache_key($operation . '_count');
        $count = wp_cache_get($cache_key, 'nofb_queue');
        
        if ($count === false) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query with proper caching for performance when counting metadata
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(post_id) 
                    FROM {$wpdb->postmeta} 
                    WHERE meta_key = %s 
                    AND meta_value = %s",
                    $meta_key, $meta_value
                )
            );
            
            wp_cache_set($cache_key, $count, 'nofb_queue', $cache_duration);
        }
        
        return (int) $count;
    }
    
    /**
     * Get attachment IDs by meta key/value with caching
     * @param string $meta_key Meta key
     * @param string $meta_value Meta value
     * @param string $operation Operation name for cache key
     * @return array Array of attachment IDs
     */
    private function get_attachment_ids_by_meta($meta_key, $meta_value, $operation) {
        $cache_key = $this->get_cache_key($operation . '_ids');
        $ids = wp_cache_get($cache_key, 'nofb_queue');
        
        if ($ids === false) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query with proper caching for performance when retrieving attachment IDs
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} 
                    WHERE meta_key = %s 
                    AND meta_value = %s",
                    $meta_key, $meta_value
                )
            );
            
            wp_cache_set($cache_key, $ids, 'nofb_queue', 5 * MINUTE_IN_SECONDS);
        }
        
        return $ids;
    }
    
    /**
     * Get all image attachment IDs with caching
     * @return array Array of attachment IDs
     */
    private function get_all_image_attachment_ids() {
        $cache_key = $this->get_cache_key('all_images');
        $ids = wp_cache_get($cache_key, 'nofb_queue');
        
        if ($ids === false) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query with proper caching for performance when retrieving all image attachments
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} 
                    WHERE post_type = %s 
                    AND post_mime_type LIKE %s 
                    AND post_status = %s",
                    'attachment', 'image%', 'inherit'
                )
            );
            
            wp_cache_set($cache_key, $ids, 'nofb_queue', 5 * MINUTE_IN_SECONDS);
        }
        
        return $ids;
    }
    
    /**
     * Calculate pending count efficiently
     * @param array $all_ids All eligible IDs
     * @param array $processed_ids Already processed IDs
     * @return int Pending count
     */
    private function calculate_pending_count($all_ids, $processed_ids) {
        if (empty($all_ids)) {
            return 0;
        }
        
        return count($all_ids) - count($processed_ids);
    }
    
    /**
     * Get statistics for optimization queue
     * @return array Statistics array
     */
    private function get_optimization_statistics() {
        $stats = array();
        
        // Get total optimized count
        $stats['total_optimized'] = $this->get_cached_count('_nofb_optimized', '1', 'optimized');
        
        // Get all images and optimized images for pending calculation
        $all_images = $this->get_all_image_attachment_ids();
        $optimized_ids = $this->get_attachment_ids_by_meta('_nofb_optimized', '1', 'optimized');
        
        $stats['pending_optimization'] = $this->calculate_pending_count($all_images, $optimized_ids);
        
        return $stats;
    }
    
    /**
     * Get statistics for migration queue
     * @return array Statistics array
     */
    private function get_migration_statistics() {
        $stats = array();
        
        // Get total migrated count
        $stats['total_migrated'] = $this->get_cached_count('_nofb_migrated', '1', 'migrated');
        
        // Get optimized and migrated IDs for pending calculation
        $optimized_ids = $this->get_attachment_ids_by_meta('_nofb_optimized', '1', 'optimized');
        $migrated_ids = $this->get_attachment_ids_by_meta('_nofb_migrated', '1', 'migrated');
        
        $stats['pending_migration'] = $this->calculate_pending_count($optimized_ids, $migrated_ids);
        
        return $stats;
    }
    
    /**
     * Scan attachments in chunks to avoid memory issues
     * @param int $chunk_size Number of attachments to process at once
     * @param array $mime_types Allowed mime types
     * @return int Number of items added to queue
     */
    private function scan_attachments_chunked($chunk_size = 100, $mime_types = null) {
        $added = 0;
        $offset = 0;
        
        // Initialize processor based on queue type
        $processor = ($this->queue_type === 'optimization') ? new NOFB_Optimizer() : new NOFB_Migrator();
        
        do {
            $args = array(
                'post_type' => 'attachment',
                'posts_per_page' => $chunk_size,
                'offset' => $offset,
                'post_status' => 'inherit',
                'fields' => 'ids' // Only get IDs for better performance
            );
            
            // Set mime types based on queue type
            if ($mime_types) {
                $args['post_mime_type'] = $mime_types;
            } elseif ($this->queue_type === 'optimization') {
                $args['post_mime_type'] = 'image';
            }
            
            $attachment_ids = get_posts($args);
            
            if (empty($attachment_ids)) {
                break;
            }
            
            $added += $this->process_attachment_chunk($attachment_ids, $processor);
            
            $offset += $chunk_size;
            
        } while (count($attachment_ids) === $chunk_size);
        
        return $added;
    }
    
    /**
     * Process a chunk of attachment IDs
     * @param array $attachment_ids Array of attachment IDs
     * @param object $processor Optimizer or Migrator instance
     * @return int Number of items added to queue
     */
    private function process_attachment_chunk($attachment_ids, $processor) {
        $added = 0;
        
        foreach ($attachment_ids as $attachment_id) {
            $file_path = ($this->queue_type === 'optimization') 
                ? $processor->get_attachment_path($attachment_id)
                : get_attached_file($attachment_id);
            
            if (!$file_path) {
                continue;
            }
            
            // Check eligibility based on queue type
            $is_eligible = ($this->queue_type === 'optimization')
                ? $processor->is_eligible_for_optimization($file_path)
                : $processor->is_eligible_for_migration($file_path);
            
            if ($is_eligible && $this->add($file_path)) {
                $added++;
            }
        }
        
        return $added;
    }
    
    /**
     * Get allowed mime types for migration
     * @return array Array of allowed mime types
     */
    private function get_migration_mime_types() {
        if (defined('nofb_ALLOWED_MIME_TYPES')) {
            return nofb_ALLOWED_MIME_TYPES;
        }
        
        // Default allowed mime types
        return array(
            'image/jpeg',
            'image/jpg', 
            'image/png',
            'image/webp'
        );
    }
    
    /**
     * Invalidate relevant caches when queue is modified
     */
    private function invalidate_caches() {
        $cache_keys = array(
            $this->get_cache_key('all_images'),
            $this->get_cache_key('optimized_count'),
            $this->get_cache_key('optimized_ids'),
            $this->get_cache_key('migrated_count'),
            $this->get_cache_key('migrated_ids')
        );
        
        foreach ($cache_keys as $cache_key) {
            wp_cache_delete($cache_key, 'nofb_queue');
        }
    }
    
    /**
     * Add item to queue
     */
    public function add($item) {
        $queue = $this->get_queue();
        
        // Check if item already exists in queue
        if (in_array($item, $queue)) {
            return false;
        }
        
        // Add new item without queue size restriction
        $queue[] = $item;
        
        // Save queue
        update_option($this->option_name, $queue);
        
        return true;
    }
    
    /**
     * Add multiple items to queue
     */
    public function add_batch($items) {
        if (!is_array($items)) {
            return false;
        }
        
        $added = 0;
        $queue = $this->get_queue();
        $original_size = count($queue);
        
        foreach ($items as $item) {
            if (!in_array($item, $queue)) {
                $queue[] = $item;
                $added++;
            }
        }
        
        // Only save and invalidate if items were actually added
        if ($added > 0) {
            update_option($this->option_name, $queue);
        }
        
        return $added;
    }
    
    /**
     * Get batch of items from queue
     */
    public function get_batch($batch_size = 5) {
        $queue = $this->get_queue();
        
        if (empty($queue)) {
            return array();
        }
        
        // Get batch from beginning of queue
        $batch = array_slice($queue, 0, $batch_size);
        
        return $batch;
    }
    
    /**
     * Remove items from queue
     */
    public function remove_batch($items) {
        if (!is_array($items) || empty($items)) {
            return false;
        }
        
        $queue = $this->get_queue();
        $original_size = count($queue);
        
        // Remove items from queue
        $queue = array_diff($queue, $items);
        
        // Re-index array
        $queue = array_values($queue);
        
        // Only save if queue actually changed
        if (count($queue) !== $original_size) {
            update_option($this->option_name, $queue);
        }
        
        return true;
    }
    
    /**
     * Get optimal batch size for processing
     * @return int Optimal batch size
     */
    private function get_optimal_batch_size() {
        if ($this->queue_type === 'optimization') {
            global $nofb_optimization_batch_size;
            $batch_size = apply_filters('nofb_optimization_batch_size', $nofb_optimization_batch_size);
            // Ensure batch size is within limits (1-5)
            return min(max(1, $batch_size), 5);
        } else {
            global $nofb_migration_batch_size;
            return $nofb_migration_batch_size;
        }
    }
    
    /**
     * Get current queue
     */
    public function get_queue() {
        $queue = get_option($this->option_name, array());
        
        if (!is_array($queue)) {
            $queue = array();
        }
        
        return $queue;
    }
    
    /**
     * Get queue size
     */
    public function get_size() {
        return count($this->get_queue());
    }
    
    /**
     * Clear queue
     */
    public function clear() {
        update_option($this->option_name, array());
    }
    
    /**
     * Process queue items
     */
    public function process() {
        if (empty($this->queue_type) || !in_array($this->queue_type, array('optimization', 'migration'), true)) {
            return false;
        }
        
        $batch_size = $this->get_optimal_batch_size();
        $batch = $this->get_batch($batch_size);
        
        if (empty($batch)) {
            return 0;
        }
        
        $processed = 0;
        
        if ($this->queue_type === 'optimization') {
            $optimizer = new NOFB_Optimizer();
            $processed = $optimizer->optimize_batch($batch);
        } else {
            $migrator = new NOFB_Migrator();
            $processed = $migrator->migrate_batch($batch);
        }
        
        // Remove processed items from queue
        if ($processed > 0) {
            $this->remove_batch(array_slice($batch, 0, $processed));
        }
        
        return $processed;
    }
    
    /**
     * Get queue statistics
     */
    public function get_stats() {
        $stats = array(
            'queue_size' => $this->get_size(),
            'queue_items' => $this->get_queue()
        );
        
        if ($this->queue_type === 'optimization') {
            $optimization_stats = $this->get_optimization_statistics();
            $stats = array_merge($stats, $optimization_stats);
        } elseif ($this->queue_type === 'migration') {
            $migration_stats = $this->get_migration_statistics();
            $stats = array_merge($stats, $migration_stats);
        }
        
        return $stats;
    }
    
    /**
     * Scan the media library for images that need to be optimized
     */
    public function scan_media_library() {
        return $this->scan_attachments_chunked(100, 'image');
    }
    
    /**
     * Scan media library for migration
     */
    public function scan_media_library_for_migration() {
        $this->log('Scanning media library for migration eligible files...');
        // We don't require optimization first - migrate any eligible media files
        $migrator = new NOFB_Migrator();
        $added = $this->scan_attachments_chunked(100, array(
            'image/jpeg', 'image/jpg', 'image/png', 
            'image/avif', 'image/webp', 'image/svg+xml',
            'image/heic', 'image/heif', 'image/tiff'
        ));
        $this->log('Added ' . $added . ' files to migration queue');
        return $added;
    }
    
    /**
     * Remove a single item from queue
     */
    public function remove($item) {
        return $this->remove_batch(array($item));
    }
    
    /**
     * Log message with queue type prefix
     * @param string $message The message to log
     * @param string $level Log level (info, warning, error)
     */
    private function log($message, $level = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $timestamp = '[' . gmdate('H:i:s') . ']';
            $formatted_message = $timestamp . ' nofb ' . ucfirst($this->queue_type) . ' Queue [' . strtoupper($level) . ']: ' . esc_html($message);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only used in development environments
            error_log($formatted_message);
        }
    }
    
    /**
     * Get the number of retry attempts for a specific file
     *
     * @param string $file_path File path
     * @return int Number of retry attempts
     */
    public function get_retry_count($file_path) {
        $retry_key = 'nofb_' . $this->queue_type . '_retry_' . md5($file_path);
        $retry_count = get_option($retry_key, 0);
        
        // Increment the retry count
        $retry_count++;
        update_option($retry_key, $retry_count);
        
        return $retry_count;
    }
    
    /**
     * Reset retry count for a specific file
     *
     * @param string $file_path File path
     * @return void
     */
    public function reset_retry_count($file_path) {
        $retry_key = 'nofb_' . $this->queue_type . '_retry_' . md5($file_path);
        delete_option($retry_key);
    }
} 