<?php
/**
 * NOFB Eligibility Class
 * Handles determining media eligibility for optimization and migration
 */

if (!defined('ABSPATH')) {
    exit;
}

class NOFB_Eligibility {
    
    private $custom_hostname;
    private $storage_zone;
    private $max_file_size_kb;
    private $auto_optimize;
    
    public function __construct() {
        // Make sure we're in WordPress environment
        if (!function_exists('get_option')) {
            return;
        }
        
        $this->custom_hostname = BUNNY_CUSTOM_HOSTNAME;
        $this->storage_zone = BUNNY_STORAGE_ZONE;
        $this->max_file_size_kb = get_option('nofb_max_file_size', NOFB_DEFAULT_MAX_FILE_SIZE);
        $this->auto_optimize = get_option('nofb_auto_optimize', false);
    }
    
    /**
     * Get optimization eligibility statistics
     */
    public function get_optimization_stats() {
        global $wpdb;
        
        $stats = array(
            'total_images' => 0,
            'locally_stored' => 0,
            'correct_size' => 0,
            'correct_type' => 0,
            'not_optimized' => 0,
            'eligible_total' => 0
        );
        
        // Make sure we're in WordPress environment
        if (!function_exists('get_attached_file') || !function_exists('wp_get_attachment_url')) {
            return $stats;
        }
        
        // Get all attachment IDs
        $cache_key = 'nofb_optimization_attachments';
        $attachments = wp_cache_get($cache_key, 'nofb_eligibility');
        
        if ($attachments === false) {
            // Use WP_Query instead of direct database query
            $query_args = array(
                'post_type' => 'attachment',
                'post_mime_type' => 'image',
                'post_status' => 'inherit',
                'posts_per_page' => -1,
                'fields' => 'id=>parent', // Get ID and post_mime_type
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            );
            
            $image_query = new WP_Query($query_args);
            $attachments = array();
            
            if ($image_query->have_posts()) {
                foreach ($image_query->posts as $post) {
                    // Get post mime type
                    $mime_type = get_post_mime_type($post->ID);
                    $attachments[] = (object) array(
                        'ID' => $post->ID,
                        'post_mime_type' => $mime_type
                    );
                }
            }
            
            wp_cache_set($cache_key, $attachments, 'nofb_eligibility', HOUR_IN_SECONDS);
        }
        
        $stats['total_images'] = count($attachments);
        if ($stats['total_images'] === 0) {
            return $stats;
        }
        
        // Get already optimized attachment IDs
        $cache_key = 'nofb_optimized_ids';
        $optimized_ids = wp_cache_get($cache_key, 'nofb_eligibility');
        
        if ($optimized_ids === false) {
            // Using a more efficient approach with pre-built index on post_type + meta_key
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query is necessary for performance with proper caching when retrieving optimized IDs
            $optimized_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} 
                    WHERE meta_key = %s AND meta_value = '1'",
                    '_nofb_optimized'
                )
            );
            
            // Convert to integers
            $optimized_ids = array_map('intval', $optimized_ids);
            
            // Cache the result for future use
            wp_cache_set($cache_key, $optimized_ids, 'nofb_eligibility', HOUR_IN_SECONDS);
        }
        
        $stats['not_optimized'] = $stats['total_images'] - count($optimized_ids);
        
        foreach ($attachments as $attachment) {
            // Skip already optimized files
            if (in_array($attachment->ID, $optimized_ids)) {
                continue;
            }
            
            $file_path = get_attached_file($attachment->ID);
            if (!$file_path || !file_exists($file_path)) {
                continue;
            }
            
            // Check if locally stored - if path contains "/wp-content/"
            if (strpos($file_path, '/wp-content/') !== false) {
                $stats['locally_stored']++;
                
                // Get file size and mime type for later checks
                $file_size_kb = filesize($file_path) / 1024;
                $mime_type = $attachment->post_mime_type;
                
                // Define allowed types and types that skip size check
                $allowed_types = array(
                    'image/jpeg',
                    'image/jpg',
                    'image/png',
                    'image/webp',
                    'image/avif',
                    'image/heic',
                    'image/heif',
                    'image/tiff'
                );
                
                $skip_size_check_types = array('image/webp', 'image/avif', 'image/svg+xml');
                
                // Check file type first
                if (in_array($mime_type, $allowed_types)) {
                    $stats['correct_type']++;
                    
                    // For non-AVIF, non-WebP, non-SVG files, add to eligible regardless of size
                    if (!in_array($mime_type, $skip_size_check_types)) {
                        $stats['eligible_total']++;
                        // Still count correct size files for display purposes
                        if ($file_size_kb > $this->max_file_size_kb && $file_size_kb < 10240) {
                            $stats['correct_size']++;
                        }
                    }
                    // For AVIF, WebP, or SVG files, check size as before
                    else if ($file_size_kb > $this->max_file_size_kb && $file_size_kb < 10240) {
                        $stats['correct_size']++;
                        
                        // Check if file might be eligible for direct migration
                        $migration_types = array('image/avif', 'image/webp', 'image/svg+xml');
                        if (!($file_size_kb <= $this->max_file_size_kb && in_array($mime_type, $migration_types))) {
                            // If not eligible for migration, then it's eligible for optimization
                            $stats['eligible_total']++;
                        }
                    }
                }
            }
        }
        
        return $stats;
    }
    
    /**
     * Get migration eligibility statistics
     */
    public function get_migration_stats() {
        global $wpdb;
        
        $stats = array(
            'total_images' => 0,
            'locally_stored' => 0,
            'correct_size' => 0,
            'correct_type' => 0,
            'not_migrated' => 0,
            'eligible_total' => 0
        );
        
        // Make sure we're in WordPress environment
        if (!function_exists('get_attached_file') || !function_exists('wp_get_attachment_url')) {
            return $stats;
        }
        
        // Get all attachment IDs
        $cache_key = 'nofb_migration_attachments';
        $attachments = wp_cache_get($cache_key, 'nofb_eligibility');
        
        if ($attachments === false) {
            // Use WP_Query instead of direct database query
            $query_args = array(
                'post_type' => 'attachment',
                'post_mime_type' => 'image',
                'post_status' => 'inherit',
                'posts_per_page' => -1,
                'fields' => 'id=>parent', // Get ID and post_mime_type
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            );
            
            $image_query = new WP_Query($query_args);
            $attachments = array();
            
            if ($image_query->have_posts()) {
                foreach ($image_query->posts as $post) {
                    // Get post mime type
                    $mime_type = get_post_mime_type($post->ID);
                    $attachments[] = (object) array(
                        'ID' => $post->ID,
                        'post_mime_type' => $mime_type
                    );
                }
            }
            
            wp_cache_set($cache_key, $attachments, 'nofb_eligibility', HOUR_IN_SECONDS);
        }
        
        $stats['total_images'] = count($attachments);
        if ($stats['total_images'] === 0) {
            return $stats;
        }
        
        // Get already migrated attachment IDs
        $cache_key = 'nofb_migrated_ids';
        $migrated_ids = wp_cache_get($cache_key, 'nofb_eligibility');
        
        if ($migrated_ids === false) {
            // Using a more efficient approach with pre-built index on post_type + meta_key
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for performance with proper caching when retrieving migrated IDs
            $migrated_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} 
                    WHERE meta_key = %s AND meta_value = '1'",
                    '_nofb_migrated'
                )
            );
            
            // Convert to integers
            $migrated_ids = array_map('intval', $migrated_ids);
            
            // Cache the result for future use
            wp_cache_set($cache_key, $migrated_ids, 'nofb_eligibility', HOUR_IN_SECONDS);
        }
        
        $stats['not_migrated'] = $stats['total_images'] - count($migrated_ids);
        
        foreach ($attachments as $attachment) {
            // Skip already migrated files
            if (in_array($attachment->ID, $migrated_ids)) {
                continue;
            }
            
            $file_path = get_attached_file($attachment->ID);
            if (!$file_path || !file_exists($file_path)) {
                continue;
            }
            
            // Check if locally stored - if path contains "/wp-content/"
            if (strpos($file_path, '/wp-content/') !== false) {
                $stats['locally_stored']++;
                
                // Check file size
                $file_size_kb = filesize($file_path) / 1024;
                if ($file_size_kb <= $this->max_file_size_kb) { // <= max_size
                    $stats['correct_size']++;
                    
                    // Check file type
                    $mime_type = $attachment->post_mime_type;
                    $allowed_types = array('image/avif', 'image/webp', 'image/svg+xml');
                    
                    if (in_array($mime_type, $allowed_types)) {
                        $stats['correct_type']++;
                        $stats['eligible_total']++;
                    }
                }
            }
        }
        
        return $stats;
    }
} 