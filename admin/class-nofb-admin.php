<?php
/**
 * NOFB Admin Class
 * Handles the plugin's admin interface and settings page
 */

if (!defined('ABSPATH')) {
    exit;
}

class NOFB_Admin {
    
    public function __construct() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        // Add admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Clear cache when media is modified
        add_action('added_post_meta', array($this, 'maybe_clear_statistics_cache'), 10, 3);
        add_action('updated_post_meta', array($this, 'maybe_clear_statistics_cache'), 10, 3);
        add_action('deleted_post_meta', array($this, 'maybe_clear_statistics_cache'), 10, 3);
        add_action('delete_attachment', array($this, 'clear_statistics_cache'));
        
        // Initialize maintenance tools
        add_action('admin_init', array($this, 'init_maintenance_tools'));
    }
    
    /**
     * Add admin menu item and subpages
     */
    public function add_admin_menu() {
        // Main menu item
        add_menu_page(
            __('Bunny Media Offload', 'nexoffload-for-bunny'),
            __('Bunny Media', 'nexoffload-for-bunny'),
            'manage_options',
            'nexoffload-for-bunny',
            array($this, 'render_dashboard_page'),
            'dashicons-cloud-upload',
            81 // Position after Settings
        );
        
        // Dashboard submenu
        add_submenu_page(
            'nexoffload-for-bunny',
            __('Dashboard', 'nexoffload-for-bunny'),
            __('Dashboard', 'nexoffload-for-bunny'),
            'manage_options',
            'nexoffload-for-bunny',
            array($this, 'render_dashboard_page')
        );
        
        // Settings submenu
        add_submenu_page(
            'nexoffload-for-bunny',
            __('Settings', 'nexoffload-for-bunny'),
            __('Settings', 'nexoffload-for-bunny'),
            'manage_options',
            'nexoffload-for-bunny-settings',
            array($this, 'render_settings_page')
        );
        
        // Media Manager submenu
        add_submenu_page(
            'nexoffload-for-bunny',
            __('Media Manager', 'nexoffload-for-bunny'),
            __('Media Manager', 'nexoffload-for-bunny'),
            'manage_options',
            'nexoffload-for-bunny-manager',
            array($this, 'render_media_manager_page')
        );
        
        // Documentation submenu
        add_submenu_page(
            'nexoffload-for-bunny',
            __('Documentation', 'nexoffload-for-bunny'),
            __('Documentation', 'nexoffload-for-bunny'),
            'manage_options',
            'nexoffload-for-bunny-documentation',
            array($this, 'render_documentation_page')
        );
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        // API Settings are now defined via wp-config.php only
        
        // General Settings
        register_setting('nofb_general_settings', 'nofb_auto_optimize', array(
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'type' => 'boolean'
        ));
        register_setting('nofb_general_settings', 'nofb_auto_migrate', array(
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'type' => 'boolean'
        ));
        register_setting('nofb_general_settings', 'nofb_file_versioning', array(
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'type' => 'boolean'
        ));
        register_setting('nofb_general_settings', 'nofb_max_file_size', array(
            'sanitize_callback' => 'absint',
            'type' => 'integer'
        ));
    }
    
    /**
     * Sanitize checkbox values
     * 
     * @param mixed $input The value to sanitize
     * @return bool
     */
    public function sanitize_checkbox($input) {
        return (isset($input) && $input == 1) ? 1 : 0;
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on plugin pages
        if (strpos($hook, 'nexoffload-for-bunny') === false) {
            return;
        }
        
        // Make sure we have dashicons
        wp_enqueue_style('dashicons');
        
        wp_enqueue_style(
            'nofb-admin-style',
            NOFB_PLUGIN_URL . 'assets/css/admin.css',
            array('dashicons'),
            NOFB_VERSION
        );
        
        wp_enqueue_script(
            'nofb-admin-script',
            NOFB_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            NOFB_VERSION,
            true
        );
        
        wp_localize_script('nofb-admin-script', 'nofb_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('nofb_nonce'),
            'processing' => __('Processing...', 'nexoffload-for-bunny'),
            'complete' => __('Complete!', 'nexoffload-for-bunny'),
            'error' => __('An error occurred.', 'nexoffload-for-bunny'),
            'confirmClear' => __('Are you sure you want to clear the queue?', 'nexoffload-for-bunny')
        ));
    }
    
    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        require_once NOFB_PLUGIN_DIR . 'admin/templates/dashboard.php';
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        require_once NOFB_PLUGIN_DIR . 'admin/templates/settings.php';
    }
    
    /**
     * Render media manager page
     */
    public function render_media_manager_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        require_once NOFB_PLUGIN_DIR . 'admin/templates/media-manager.php';
    }
    
    /**
     * Render documentation page
     */
    public function render_documentation_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        require_once NOFB_PLUGIN_DIR . 'admin/templates/documentation.php';
    }
    
    /**
     * Get statistics for the dashboard
     */
    public function get_statistics() {
        global $wpdb;
        
        // Try to get cached statistics
        $cache_key = 'nofb_dashboard_statistics';
        $stats = wp_cache_get($cache_key, 'nofb_admin');
        
        if ($stats !== false) {
            return $stats;
        }
        
        $stats = array(
            'total_files' => 0,
            'optimized_files' => 0,
            'migrated_files' => 0,
            'total_size' => 0,
            'saved_size' => 0
        );
        
        // Cache keys for individual queries to improve performance
        $total_cache_key = 'nofb_total_files_count';
        $optimized_cache_key = 'nofb_optimized_files_count';
        $migrated_cache_key = 'nofb_migrated_files_count';
        
        // Total files in media library
        $stats['total_files'] = wp_cache_get($total_cache_key, 'nofb_admin');
        if ($stats['total_files'] === false) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query used for performance with proper caching implementation
            $stats['total_files'] = $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'attachment')
            );
            wp_cache_set($total_cache_key, $stats['total_files'], 'nofb_admin', 5 * MINUTE_IN_SECONDS);
        }
        
        // Optimized files (using post meta instead of custom table)
        $stats['optimized_files'] = wp_cache_get($optimized_cache_key, 'nofb_admin');
        if ($stats['optimized_files'] === false) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for counting optimized files with proper caching
            $stats['optimized_files'] = $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", '_nofb_optimized', '1')
            );
            wp_cache_set($optimized_cache_key, $stats['optimized_files'], 'nofb_admin', 5 * MINUTE_IN_SECONDS);
        }
        
        // Migrated files (using post meta instead of custom table)
        $stats['migrated_files'] = wp_cache_get($migrated_cache_key, 'nofb_admin');
        if ($stats['migrated_files'] === false) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query used for performance when counting migrated files with proper caching
            $stats['migrated_files'] = $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", '_nofb_migrated', '1')
            );
            wp_cache_set($migrated_cache_key, $stats['migrated_files'], 'nofb_admin', 5 * MINUTE_IN_SECONDS);
        }
        
        // Total size of media library files and saved size would need custom calculation
        // This could be calculated by summing up file sizes from post meta, but leaving as placeholder for now
        $stats['total_size'] = 0; 
        $stats['saved_size'] = 0; 
        
        // Cache the statistics for 5 minutes
        wp_cache_set($cache_key, $stats, 'nofb_admin', 5 * MINUTE_IN_SECONDS);
        
        return $stats;
    }
    
    /**
     * Clear admin statistics cache
     */
    public function clear_statistics_cache() {
        wp_cache_delete('nofb_dashboard_statistics', 'nofb_admin');
        wp_cache_delete('nofb_total_files_count', 'nofb_admin');
        wp_cache_delete('nofb_optimized_files_count', 'nofb_admin');
        wp_cache_delete('nofb_migrated_files_count', 'nofb_admin');
    }
    
    /**
     * Clear statistics cache if relevant metadata changes
     */
    public function maybe_clear_statistics_cache($meta_id, $object_id, $meta_key) {
        if ($meta_key === '_nofb_migrated' || $meta_key === '_nofb_optimized' || $meta_key === '_nofb_bunny_url') {
            $this->clear_statistics_cache();
        }
    }
    
    /**
     * Initialize URL fixing and maintenance tools
     * Handles the processing of URL fixing requests from the settings page
     */
    public function init_maintenance_tools() {
        // Process URL fixing if requested
        if (isset($_POST['nofb_action']) && $_POST['nofb_action'] === 'fix_image_urls') {
            // Check capabilities
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'nexoffload-for-bunny'));
            }
            
            // Verify nonce
            if (!isset($_POST['nofb_fix_urls_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nofb_fix_urls_nonce'])), 'nofb_fix_urls_action')) {
                // Invalid nonce
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Security check failed.', 'nexoffload-for-bunny') . '</p></div>';
                });
                return;
            }
            
            // Run the URL fixing function
            $result = nofb_force_update_image_urls();
            
            // Add admin notice with the result
            add_action('admin_notices', function() use ($result) {
                if ($result['status'] === 'success') {
                    /* translators: %1$d: Total count of image URLs processed, %2$d: Count of successfully updated URLs, %3$d: Count of errors encountered */
                    echo '<div class="notice notice-success"><p>' . sprintf(esc_html__('Image URLs fixed successfully. Total: %1$d, Updated: %2$d, Errors: %3$d', 'nexoffload-for-bunny'), esc_html($result['total']), esc_html($result['updated']), esc_html($result['errors'])) . '</p></div>';
                } else {
                    /* translators: %s: Error message from the URL fixing process */
                    echo '<div class="notice notice-error"><p>' . esc_html($result['message']) . '</p></div>';
                }
            });
        }
        
        // Process cache clearing if requested
        if (isset($_POST['nofb_action']) && $_POST['nofb_action'] === 'clear_cache') {
            // Check capabilities
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'nexoffload-for-bunny'));
            }
            
            // Verify nonce
            if (!isset($_POST['nofb_clear_cache_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nofb_clear_cache_nonce'])), 'nofb_clear_cache_action')) {
                // Invalid nonce
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Security check failed.', 'nexoffload-for-bunny') . '</p></div>';
                });
                return;
            }
            
            // Clear all plugin related transients and caches
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query used for maintenance cleanup operation
            $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '%nofb_%' AND option_name LIKE '%transient%'");
            wp_cache_flush();
            
            // Add admin notice
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success"><p>' . esc_html__('Plugin cache cleared successfully.', 'nexoffload-for-bunny') . '</p></div>';
            });
        }
    }
    
    /**
     * Render support information meta box
     */
    public function render_support_box() {
        ?>
        <div class="nofb-card nofb-support-card">
            <div class="nofb-card-header">
                <h3><?php esc_html_e('Support & Information', 'nexoffload-for-bunny'); ?></h3>
            </div>
            <div class="nofb-card-body">
                <div class="nofb-support-info">
                    <p>
                        <?php esc_html_e('This plugin and all media migration features are 100% free, maintained by us as a thank-you for creating your Bunny.net account through our affiliate link.', 'nexoffload-for-bunny'); ?>
                    </p>
                    
                    <div class="nofb-support-links">
                        <div class="nofb-support-link-item">
                            <span class="dashicons dashicons-admin-site"></span>
                            <a href="https://bunny.net?ref=99jl5w7iou" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('Create a Bunny.net Account', 'nexoffload-for-bunny'); ?>
                            </a>
                        </div>
                        
                        <div class="nofb-support-link-item">
                            <span class="dashicons dashicons-coffee"></span>
                            <a href="https://coff.ee/diogocardoso" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('Support the Developer', 'nexoffload-for-bunny'); ?>
                            </a>
                        </div>
                        
                        <div class="nofb-support-link-item">
                            <span class="dashicons dashicons-star-filled"></span>
                            <a href="https://wordpress.org/plugins/nexoffload-for-bunny/" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('Rate on WordPress.org', 'nexoffload-for-bunny'); ?>
                            </a>
                        </div>
                        
                        <div class="nofb-support-link-item">
                            <span class="dashicons dashicons-github"></span>
                            <a href="https://github.com/nexwinds/nofb-wp" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('GitHub Repository', 'nexoffload-for-bunny'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
} 