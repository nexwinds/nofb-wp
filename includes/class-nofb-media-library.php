<?php
/**
 * NOFB Media Library Class
 * Handles integration with the WordPress media library
 */

if (!defined('ABSPATH')) {
    exit;
}

class NOFB_Media_Library {
    
    public function __construct() {
        // Add custom columns to media library list view
        add_filter('manage_media_columns', array($this, 'add_media_columns'));
        add_action('manage_media_custom_column', array($this, 'media_custom_column'), 10, 2);
        
        // Add styles for the media library
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        
        // Add bulk actions
        add_filter('bulk_actions-upload', array($this, 'register_bulk_actions'));
        add_filter('handle_bulk_actions-upload', array($this, 'handle_bulk_actions'), 10, 3);
        
        // Add filter dropdown
        add_action('restrict_manage_posts', array($this, 'add_media_filters'));
        add_action('pre_get_posts', array($this, 'filter_media_query'));
        
        // Listen for attachment deletions
        add_action('delete_attachment', array($this, 'on_delete_attachment'));
        
        // Clear caches when attachments are modified
        add_action('added_post_meta', array($this, 'clear_caches'), 10, 3);
        add_action('updated_post_meta', array($this, 'clear_caches'), 10, 3);
        add_action('deleted_post_meta', array($this, 'clear_caches'), 10, 3);
    }
    
    /**
     * Add custom columns to media library
     */
    public function add_media_columns($columns) {
        $columns['nofb_status'] = __('Bunny Media', 'nexoffload-for-bunny');
        return $columns;
    }
    
    /**
     * Fill custom column with data
     */
    public function media_custom_column($column_name, $attachment_id) {
        if ($column_name !== 'nofb_status') {
            return;
        }
        
        // Get attachment status from post meta instead of custom table
        $is_optimized = get_post_meta($attachment_id, '_nofb_optimized', true);
        $is_migrated = get_post_meta($attachment_id, '_nofb_migrated', true);
        $bunny_url = get_post_meta($attachment_id, '_nofb_bunny_url', true);
        
        // Get attachment info
        $file_path = get_attached_file($attachment_id);
        $mime_type = get_post_mime_type($attachment_id);
        $is_image = strpos($mime_type, 'image/') === 0;
        
        if (!$is_image) {
            echo '<span class="nofb-status nofb-not-applicable">' . esc_html__('Not Image', 'nexoffload-for-bunny') . '</span>';
            return;
        }
        
        if (!$is_optimized && !$is_migrated) {
            // Check eligibility
            if (!file_exists($file_path)) {
                echo '<span class="nofb-status nofb-not-found">' . esc_html__('File Not Found', 'nexoffload-for-bunny') . '</span>';
                return;
            }
            
            $file_size_kb = filesize($file_path) / 1024;
            $max_file_size = get_option('nofb_max_file_size', NOFB_DEFAULT_MAX_FILE_SIZE);
            
            if ($file_size_kb > $max_file_size && $file_size_kb < 10240) {
                echo '<span class="nofb-status nofb-eligible-optimize">' . esc_html__('Eligible for Optimization', 'nexoffload-for-bunny') . '</span>';
                echo '<div class="row-actions">';
                echo '<span class="optimize"><a href="' . esc_url(admin_url('admin.php?page=nexoffload-for-bunny&action=optimize&attachment_id=' . $attachment_id)) . '">' . esc_html__('Optimize', 'nexoffload-for-bunny') . '</a></span>';
                echo '</div>';
            } else {
                echo '<span class="nofb-status nofb-not-eligible">' . esc_html__('Not Eligible', 'nexoffload-for-bunny') . '</span>';
                echo '<div class="row-actions">';
                echo '<span class="info">' . esc_html__('Size', 'nexoffload-for-bunny') . ': ' . esc_html(round($file_size_kb)) . ' KB</span>';
                echo '</div>';
            }
            return;
        }
        
        // Display status based on post meta
        if ($is_migrated) {
            echo '<span class="nofb-status nofb-migrated">' . esc_html__('On Bunny CDN', 'nexoffload-for-bunny') . '</span>';
            echo '<div class="row-actions">';
            echo '<span class="view"><a href="' . esc_url($bunny_url) . '" target="_blank">' . esc_html__('View on CDN', 'nexoffload-for-bunny') . '</a></span>';
            echo '</div>';
        } else if ($is_optimized) {
            echo '<span class="nofb-status nofb-optimized">' . esc_html__('Optimized', 'nexoffload-for-bunny') . '</span>';
            echo '<div class="row-actions">';
            echo '<span class="migrate"><a href="' . esc_url(admin_url('admin.php?page=nexoffload-for-bunny&action=migrate&attachment_id=' . $attachment_id)) . '">' . esc_html__('Migrate to CDN', 'nexoffload-for-bunny') . '</a></span>';
            echo '</div>';
        } else {
            echo '<span class="nofb-status nofb-pending">' . esc_html__('Pending', 'nexoffload-for-bunny') . '</span>';
        }
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function admin_enqueue_scripts($hook) {
        if ($hook !== 'upload.php') {
            return;
        }
        
        wp_enqueue_style(
            'nofb-media-library',
            NOFB_PLUGIN_URL . 'assets/css/media-library.css',
            array(),
            NOFB_VERSION
        );
        
        // Add a hidden nonce field for our filters
        add_action('admin_footer', array($this, 'add_media_filter_nonce'));
    }
    
    /**
     * Add nonce field to media filters
     */
    public function add_media_filter_nonce() {
        // Add a hidden nonce field for our filter
        echo '<input type="hidden" name="nofb_media_filter_nonce" value="' . esc_attr(wp_create_nonce('nofb_filter_media')) . '" />';
    }
    
    /**
     * Register bulk actions
     */
    public function register_bulk_actions($bulk_actions) {
        $bulk_actions['nofb_optimize'] = __('Optimize with Bunny Media', 'nexoffload-for-bunny');
        $bulk_actions['nofb_migrate'] = __('Migrate to Bunny CDN', 'nexoffload-for-bunny');
        return $bulk_actions;
    }
    
    /**
     * Handle bulk actions
     */
    public function handle_bulk_actions($redirect_to, $action, $post_ids) {
        if ($action !== 'nofb_optimize' && $action !== 'nofb_migrate') {
            return $redirect_to;
        }
        
        // Verify nonce
        $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'bulk-media') && !wp_verify_nonce($nonce, 'bulk-posts')) {
            // Add error message to redirect
            $redirect_to = add_query_arg(
                array(
                    'nofb_error' => 'nonce_failed',
                ),
                $redirect_to
            );
            return $redirect_to;
        }
        
        $processed = 0;
        
        foreach ($post_ids as $post_id) {
            $file_path = get_attached_file($post_id);
            
            if (!$file_path) {
                continue;
            }
            
            if ($action === 'nofb_optimize') {
                $queue = new NOFB_Queue('optimization');
                if ($queue->add($file_path)) {
                    $processed++;
                }
            } elseif ($action === 'nofb_migrate') {
                $queue = new NOFB_Queue('migration');
                if ($queue->add($file_path)) {
                    $processed++;
                }
            }
        }
        
        // Add query args for admin notice
        $redirect_to = add_query_arg(
            array(
                'nofb_bulk_action' => $action,
                'nofb_processed' => $processed,
                'nofb_total' => count($post_ids)
            ),
            $redirect_to
        );
        
        return $redirect_to;
    }
    
    /**
     * Add media library filters
     */
    public function add_media_filters($post_type) {
        if ($post_type !== 'attachment') {
            return;
        }
        
        // Verify nonce first when coming from a GET request with parameters
        $nonce_verified = false;
        if (isset($_GET['nofb_media_filter_nonce'])) {
            $nonce_verified = wp_verify_nonce(
                sanitize_key(wp_unslash($_GET['nofb_media_filter_nonce'])), 
                'nofb_filter_media'
            );
        }
        
        // Always sanitize and unslash user input - only use it if nonce is verified or not set yet
        $status = '';
        if ($nonce_verified && isset($_GET['nofb_status'])) {
            $status = sanitize_text_field(wp_unslash($_GET['nofb_status']));
        }
        
        echo '<select name="nofb_status">';
        echo '<option value="">' . esc_html__('Bunny Media Status', 'nexoffload-for-bunny') . '</option>';
        echo '<option value="not_processed" ' . selected($status, 'not_processed', false) . '>' . esc_html__('Not Processed', 'nexoffload-for-bunny') . '</option>';
        echo '<option value="optimized" ' . selected($status, 'optimized', false) . '>' . esc_html__('Optimized', 'nexoffload-for-bunny') . '</option>';
        echo '<option value="migrated" ' . selected($status, 'migrated', false) . '>' . esc_html__('On Bunny CDN', 'nexoffload-for-bunny') . '</option>';
        echo '</select>';
        
        // Add our nonce field right after the select control
        wp_nonce_field('nofb_filter_media', 'nofb_media_filter_nonce', false);
    }
    
    /**
     * Filter media query by nofb status
     */
    public function filter_media_query($query) {
        if (!is_admin() || !$query->is_main_query() || !function_exists('get_current_screen')) {
            return;
        }
        
        $screen = get_current_screen();
        if ($screen->id !== 'upload') {
            return;
        }
        
        if (empty($_GET['nofb_status'])) {
            return;
        }
        
        // Add nonce field to our filter form for verification
        $nonce_verified = false;
        if (isset($_GET['nofb_media_filter_nonce']) && wp_verify_nonce(wp_unslash(sanitize_key($_GET['nofb_media_filter_nonce'])), 'nofb_filter_media')) {
            $nonce_verified = true;
        }
        
        // Get status value - always sanitize even if nonce fails
        $status = isset($_GET['nofb_status']) ? sanitize_text_field(wp_unslash($_GET['nofb_status'])) : '';
        
        // Only proceed with filtering if nonce is valid
        if (!$nonce_verified) {
            // Just log or add admin notice, but don't exit as this is just a filter
            return;
        }
        
        // Get attachment IDs based on status filter using post meta
        switch ($status) {
            case 'not_processed':
                // Get IDs of attachments that don't have our meta keys
                $cache_key = 'nofb_not_processed_ids';
                $query_cache_key = 'nofb_processed_query_result';
                $cached_query_result = wp_cache_get($query_cache_key, 'nofb_media_library');
                
                if ($cached_query_result === false) {
                    // Use direct DB query instead of meta_query for better performance
                    global $wpdb;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for complex filtering with improved performance and proper caching
                    $processed_ids = $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} 
                            WHERE meta_key IN (%s, %s)",
                            '_nofb_optimized', '_nofb_migrated'
                        )
                    );
                    
                    // Convert to integers
                    $processed_ids = array_map('intval', $processed_ids);
                    
                    // Cache the query result
                    wp_cache_set($query_cache_key, $processed_ids, 'nofb_media_library', HOUR_IN_SECONDS);
                } else {
                    $processed_ids = $cached_query_result;
                }
                
                // Cache the final processed IDs
                wp_cache_set($cache_key, $processed_ids, 'nofb_media_library', HOUR_IN_SECONDS);
                
                if (!empty($processed_ids)) {
                    // Get all image attachments not in processed list
                    $query->set('post_mime_type', 'image');
                    $query->set('post__not_in', $processed_ids);
                }
                break;
                
            case 'optimized':
                // Get all attachment IDs that are optimized but not migrated
                $cache_key = 'nofb_optimized_only_ids';
                $query_cache_key = 'nofb_optimized_query_result';
                $cached_query_result = wp_cache_get($query_cache_key, 'nofb_media_library');
                
                if ($cached_query_result === false) {
                    // Use direct database query instead of meta_query
                    global $wpdb;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query needed for complex subquery filtering with proper caching
                    $optimized_ids = $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT pm1.post_id FROM {$wpdb->postmeta} pm1
                            WHERE pm1.meta_key = %s AND pm1.meta_value = %s
                            AND NOT EXISTS (
                                SELECT 1 FROM {$wpdb->postmeta} pm2
                                WHERE pm2.post_id = pm1.post_id
                                AND pm2.meta_key = %s
                                AND pm2.meta_value = %s
                            )",
                            '_nofb_optimized', '1', '_nofb_migrated', '1'
                        )
                    );
                    
                    // Convert to integers
                    $optimized_ids = array_map('intval', $optimized_ids);
                    
                    // Cache the query result
                    wp_cache_set($query_cache_key, $optimized_ids, 'nofb_media_library', HOUR_IN_SECONDS);
                } else {
                    $optimized_ids = $cached_query_result;
                }
                
                // Cache the final processed IDs
                wp_cache_set($cache_key, $optimized_ids, 'nofb_media_library', HOUR_IN_SECONDS);
                
                if (!empty($optimized_ids)) {
                    $query->set('post__in', $optimized_ids);
                } else {
                    // No matching IDs, force no results
                    $query->set('post__in', array(0));
                }
                break;
                
            case 'migrated':
                // Get all attachment IDs that are migrated
                $cache_key = 'nofb_migrated_ids';
                $migrated_ids = wp_cache_get($cache_key, 'nofb_media_library');
                
                if ($migrated_ids === false) {
                    // Use direct database query instead of meta_query
                    global $wpdb;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query used for performance with proper caching implementation
                    $migrated_ids = $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT post_id FROM {$wpdb->postmeta}
                            WHERE meta_key = %s AND meta_value = '1'",
                            '_nofb_migrated'
                        )
                    );
                    
                    // Convert to integers
                    $migrated_ids = array_map('intval', $migrated_ids);
                    
                    // Cache the results for 1 hour
                    wp_cache_set($cache_key, $migrated_ids, 'nofb_media_library', HOUR_IN_SECONDS);
                }
                
                if (!empty($migrated_ids)) {
                    $query->set('post__in', $migrated_ids);
                } else {
                    // No matching IDs, force no results
                    $query->set('post__in', array(0));
                }
                break;
        }
    }
    
    /**
     * Handle attachment deletion
     */
    public function on_delete_attachment($attachment_id) {
        // Always delete from Bunny if the file was migrated
        $migrator = new NOFB_Migrator();
        $migrator->delete_from_bunny($attachment_id);
    }
    
    /**
     * Clear media library caches when relevant post meta is changed
     * 
     * @param int $meta_id Meta ID
     * @param int $post_id Post ID
     * @param string $meta_key Meta key
     */
    public function clear_caches($meta_id, $post_id, $meta_key) {
        // Only clear caches for relevant post meta keys
        if ($post_id && in_array($meta_key, array('_nofb_optimized', '_nofb_migrated', '_nofb_bunny_url'))) {
            // Clear all relevant caches
            wp_cache_delete('nofb_not_processed_ids', 'nofb_media_library');
            wp_cache_delete('nofb_optimized_only_ids', 'nofb_media_library');
            wp_cache_delete('nofb_migrated_ids', 'nofb_media_library');
        }
    }
    
    /**
     * Reset counters when queue is cleared
     */
    public function reset_counters() {
        wp_cache_delete('nofb_eligible_files_count', 'nofb_media');
        wp_cache_delete('nofb_migrated_files_count', 'nofb_media');
    }
    
    /**
     * Get potential queue size
     */
    public function get_queue_potential_sizes() {
        $queue_opt = new NOFB_Queue('optimization');
        $queue_mig = new NOFB_Queue('migration');
        
        // ... existing code ...
    }

    /**
     * Migrate a media file to Bunny.net
     */
    public function migrate_media_file($attachment_id) {
        $file = get_attached_file($attachment_id);
        $migrator = new NOFB_Migrator();
        // ... existing code ...
    }
} 