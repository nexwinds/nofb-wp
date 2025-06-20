<?php
/**
 * Plugin Name: Nexoffload for Bunny
 * Plugin Full Name: Nexoffload Media for Bunny – Optimize & Deliver
 * Plugin Slug: nexoffload-for-bunny
 * Plugin URI: https://nexoffload.nexwinds.com
 * Description: Seamlessly optimize and offload WordPress media to Bunny.net Edge Storage for blazing-fast delivery and lighter server load.
 * Version: 1.0.0
 * Author: Nexwinds
 * Author URI: https://nexwinds.com
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: nexoffload-for-bunny
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('NOFB_VERSION', '1.0.0');
define('NOFB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NOFB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NOFB_PLUGIN_FILE', __FILE__);

// For backward compatibility with older naming
define('nofb_VERSION', NOFB_VERSION);
define('nofb_PLUGIN_DIR', NOFB_PLUGIN_DIR);
define('nofb_PLUGIN_URL', NOFB_PLUGIN_URL);
define('nofb_PLUGIN_FILE', NOFB_PLUGIN_FILE);

// Define default settings
define('NOFB_DEFAULT_MAX_FILE_SIZE', 150); // KB
define('NOFB_MAX_INTERNAL_QUEUE', 100);

// Internal batch size globals (not user-configurable)
global $nofb_migration_batch_size, $nofb_optimization_batch_size;
$nofb_migration_batch_size = 5;
$nofb_optimization_batch_size = 5;

// Load configuration from wp-config.php
if (!defined('BUNNY_API_KEY')) {
    define('BUNNY_API_KEY', '');
}
if (!defined('BUNNY_STORAGE_ZONE')) {
    define('BUNNY_STORAGE_ZONE', '');
}
if (!defined('BUNNY_CUSTOM_HOSTNAME')) {
    define('BUNNY_CUSTOM_HOSTNAME', '');
}
if (!defined('NOFB_API_KEY')) {
    define('NOFB_API_KEY', '');
}
if (!defined('NOFB_API_REGION')) {
    define('NOFB_API_REGION', 'us'); // Default to US region
}

// Activation hook
register_activation_hook(__FILE__, 'nofb_activate');
function nofb_activate() {
    // Set default options
    $defaults = array(
        'nofb_auto_optimize' => false,
        'nofb_auto_migrate' => false,
        'nofb_file_versioning' => false,
        'nofb_max_file_size' => NOFB_DEFAULT_MAX_FILE_SIZE,
        'nofb_optimization_queue' => array(),
        'nofb_migration_queue' => array()
    );
    
    foreach ($defaults as $key => $value) {
        if (get_option($key) === false) {
            add_option($key, $value);
        }
    }
    
    // Schedule cron jobs
    if (!wp_next_scheduled('nofb_process_optimization_queue')) {
        wp_schedule_event(time(), 'every_minute', 'nofb_process_optimization_queue');
    }
    if (!wp_next_scheduled('nofb_process_migration_queue')) {
        wp_schedule_event(time(), 'every_minute', 'nofb_process_migration_queue');
    }
}

/**
 * Synchronize metadata between old and new keys to ensure consistency
 * This helps fix issues after plugin renaming
 */
function nofb_synchronize_metadata() {
    global $wpdb;
    
    // Get all attachment IDs
    $cache_key = 'nofb_sync_attachments';
    $attachments = wp_cache_get($cache_key, 'bunny_media_offload');
    
    if (false === $attachments) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $attachments = $wpdb->get_results(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'"
        );
        
        wp_cache_set($cache_key, $attachments, 'bunny_media_offload', HOUR_IN_SECONDS);
    }
    
    $updated_count = 0;
    
    // Key pairs to check for synchronization (old => new)
    $meta_key_pairs = array(
        '_bmfo_migrated' => '_nofb_migrated',
        '_bmfo_bunny_url' => '_nofb_bunny_url',
        '_bmfo_optimized' => '_nofb_optimized',
        '_bmfo_version' => '_nofb_version',
        '_bmfo_original_size' => '_nofb_original_size',
        '_bmfo_optimized_size' => '_nofb_optimized_size'
    );
    
    foreach ($attachments as $attachment) {
        $attachment_id = $attachment->ID;
        $updated = false;
        
        foreach ($meta_key_pairs as $old_key => $new_key) {
            $meta_value = get_post_meta($attachment_id, $old_key, true);
            if ($meta_value) {
                update_post_meta($attachment_id, $new_key, $meta_value);
                $updated = true;
            }
        }
        
        if ($updated) {
            $updated_count++;
        }
    }
    
    return $updated_count;
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'nofb_deactivate');
function nofb_deactivate() {
    // Clear scheduled hooks
    wp_clear_scheduled_hook('nofb_process_optimization_queue');
    wp_clear_scheduled_hook('nofb_process_migration_queue');
}

// Add custom cron interval
add_filter('cron_schedules', 'nofb_add_cron_interval');
function nofb_add_cron_interval($schedules) {
    $schedules['every_minute'] = array(
        'interval' => 60,
        'display'  => esc_html__('Every Minute', 'nexoffload-for-bunny')
    );
    return $schedules;
}

// Load class files
require_once NOFB_PLUGIN_DIR . 'includes/class-nofb-api.php';
require_once NOFB_PLUGIN_DIR . 'includes/class-nofb-queue.php';
require_once NOFB_PLUGIN_DIR . 'includes/class-nofb-optimizer.php';
require_once NOFB_PLUGIN_DIR . 'includes/class-nofb-migrator.php';
require_once NOFB_PLUGIN_DIR . 'includes/class-nofb-media-library.php';
require_once NOFB_PLUGIN_DIR . 'includes/class-nofb-eligibility.php';
require_once NOFB_PLUGIN_DIR . 'includes/class-nofb-ajax.php';
require_once NOFB_PLUGIN_DIR . 'admin/class-nofb-admin.php';

// Optimized helper functions

/**
 * Get cached plugin options to avoid repeated database calls
 */
function nofb_get_cached_options() {
    static $options_cache = null;
    
    if ($options_cache === null) {
        $options_cache = array(
            'file_versioning' => get_option('nofb_file_versioning', false),
            'auto_optimize' => get_option('nofb_auto_optimize', false),
            'auto_migrate' => get_option('nofb_auto_migrate', false),
            'custom_hostname' => BUNNY_CUSTOM_HOSTNAME,
            'storage_zone' => BUNNY_STORAGE_ZONE
        );
    }
    
    return $options_cache;
}

/**
 * Get attachment ID from URL with caching
 */
function nofb_get_attachment_id_from_url($url) {
    static $id_cache = array();
    
    $cache_key = md5($url);
    if (isset($id_cache[$cache_key])) {
        return $id_cache[$cache_key];
    }
    
    // Try direct lookup
    $attachment_id = attachment_url_to_postid($url);
    
    if (!$attachment_id) {
        // Try without size suffix
        $clean_url = preg_replace('/-\d+x\d+(\.[a-zA-Z]+)$/', '$1', $url);
        $attachment_id = attachment_url_to_postid($clean_url);
    }
    
    if (!$attachment_id) {
        // Database lookup as last resort
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['baseurl'] . '/', '', $url);
        $relative_path_clean = preg_replace('/-\d+x\d+(\.[a-zA-Z]+)$/', '$1', $relative_path);
        
        global $wpdb;
        $cache_key_db = 'nofb_attachment_by_file_' . md5($relative_path . $relative_path_clean);
        $attachment_id = wp_cache_get($cache_key_db, 'nexoffload_for_bunny');
        
        if (false === $attachment_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $attachment_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta 
                 WHERE meta_key = '_wp_attached_file' 
                 AND (meta_value = %s OR meta_value = %s)",
                $relative_path,
                $relative_path_clean
            ));
            
            wp_cache_set($cache_key_db, $attachment_id, 'nexoffload_for_bunny', HOUR_IN_SECONDS);
        }
    }
    
    $id_cache[$cache_key] = $attachment_id ? intval($attachment_id) : 0;
    return $id_cache[$cache_key];
}

/**
 * Centralized URL replacement logic
 */
function nofb_get_bunny_url($attachment_id, $original_url = '') {
    static $url_cache = array();
    
    $cache_key = $attachment_id . '_' . md5($original_url);
    if (isset($url_cache[$cache_key])) {
        return $url_cache[$cache_key];
    }
    
    $is_migrated = get_post_meta($attachment_id, '_nofb_migrated', true);
    $bunny_url = get_post_meta($attachment_id, '_nofb_bunny_url', true);
    
    if (!$is_migrated || empty($bunny_url)) {
        $url_cache[$cache_key] = false;
        return false;
    }
    
    $bunny_base_url = rtrim(dirname($bunny_url), '/') . '/';
    $filename = $original_url ? basename($original_url) : basename($bunny_url);
    $new_url = $bunny_base_url . $filename;
    
    // Add version parameter if enabled
    $options = nofb_get_cached_options();
    if ($options['file_versioning']) {
        $version = get_post_meta($attachment_id, '_nofb_version', true);
        if ($version) {
            $new_url = add_query_arg('v', $version, $new_url);
        }
    }
    
    $url_cache[$cache_key] = $new_url;
    return $new_url;
}

/**
 * Process srcset with Bunny URLs
 */
function nofb_process_srcset($srcset, $attachment_id) {
    if (empty($srcset)) {
        return $srcset;
    }
    
    $srcset_urls = explode(', ', $srcset);
    $new_srcset = array();
    
    foreach ($srcset_urls as $srcset_url) {
        $parts = explode(' ', $srcset_url);
        if (count($parts) >= 2) {
            $url = $parts[0];
            $descriptor = $parts[1];
            
            $new_url = nofb_get_bunny_url($attachment_id, $url);
            if ($new_url) {
                $new_srcset[] = $new_url . ' ' . $descriptor;
            } else {
                $new_srcset[] = $srcset_url;
            }
        }
    }
    
    return implode(', ', $new_srcset);
}

/**
 * Process HTML images with Bunny URLs
 */
function nofb_process_html_images($html, $attachment_id = null) {
    if (empty($html) || strpos($html, '<img') === false) {
        return $html;
    }
    
    static $dom_cache = array();
    $cache_key = md5($html . $attachment_id);
    
    if (isset($dom_cache[$cache_key])) {
        return $dom_cache[$cache_key];
    }
    
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();
    
    $images = $dom->getElementsByTagName('img');
    $modified = false;
    
    foreach ($images as $img) {
        $src = $img->getAttribute('src');
        if (empty($src)) continue;
        
        $current_attachment_id = $attachment_id ?: nofb_get_attachment_id_from_url($src);
        if (!$current_attachment_id) continue;
        
        $new_url = nofb_get_bunny_url($current_attachment_id, $src);
        if ($new_url) {
        $img->setAttribute('src', $new_url);
        
            // Process srcset
        if ($img->hasAttribute('srcset')) {
                $srcset = nofb_process_srcset($img->getAttribute('srcset'), $current_attachment_id);
                $img->setAttribute('srcset', $srcset);
            }
            
            // Process data attributes for WooCommerce
            foreach (array('data-src', 'data-large_image', 'data-thumb') as $attr) {
                if ($img->hasAttribute($attr)) {
                    $data_url = $img->getAttribute($attr);
                    $new_data_url = nofb_get_bunny_url($current_attachment_id, $data_url);
                    if ($new_data_url) {
                        $img->setAttribute($attr, $new_data_url);
                    }
                }
            }
            
            $modified = true;
        }
    }
    
    if ($modified) {
        $body = $dom->getElementsByTagName('body')->item(0);
            $new_html = '';
            foreach ($body->childNodes as $child) {
                $new_html .= $dom->saveHTML($child);
            }
        $dom_cache[$cache_key] = $new_html;
            return $new_html;
    }
    
    $dom_cache[$cache_key] = $html;
    return $html;
}

/**
 * Batch get attachment meta to reduce database queries
 */
function nofb_get_attachments_meta($attachment_ids) {
    global $wpdb;
    
    if (empty($attachment_ids)) return array();
    
    $cache_key = 'nofb_batch_meta_' . md5(implode(',', $attachment_ids));
    $cached = wp_cache_get($cache_key, 'nofb_attachments');
    
    if ($cached !== false) {
        return $cached;
    }
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct DB query required for bulk metadata retrieval with custom caching (see wp_cache_set below)
    $query = $wpdb->prepare(
        sprintf(
            "SELECT post_id, meta_key, meta_value FROM $wpdb->postmeta 
             WHERE post_id IN (%s) 
             AND meta_key IN ('_nofb_migrated', '_nofb_bunny_url', '_nofb_version')",
            implode(',', array_fill(0, count($attachment_ids), '%d'))
        ),
        $attachment_ids
    );
    /* phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- $query is properly prepared above; Required for bulk metadata retrieval with custom caching */
    $results = $wpdb->get_results($query);
    
    $organized = array();
    foreach ($results as $row) {
        $organized[$row->post_id][$row->meta_key] = $row->meta_value;
    }
    
    wp_cache_set($cache_key, $organized, 'nofb_attachments', HOUR_IN_SECONDS);
    return $organized;
}

// Initialize the plugin
add_action('init', 'nofb_init');
function nofb_init() {
    global $nofb_media_library, $nofb_admin, $nofb_ajax;
    
    // Load text domain for translations
    load_plugin_textdomain('nexoffload-for-bunny', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    
    // Initialize classes
    $nofb_media_library = new NOFB_Media_Library();
    
    // Admin functionality
    if (is_admin()) {
        $nofb_admin = new NOFB_Admin();
        $nofb_ajax = new NOFB_AJAX();
    }
    
    // Hook into media upload if auto-optimize is enabled
    $options = nofb_get_cached_options();
    if ($options['auto_optimize']) {
        add_filter('wp_handle_upload', 'nofb_handle_upload', 10, 2);
    }
    
    // URL rewriting for migrated files
    add_filter('wp_get_attachment_url', 'nofb_filter_attachment_url', 10, 2);
    add_filter('wp_get_attachment_image_src', 'nofb_filter_attachment_image_src', 10, 4);
    add_filter('wp_calculate_image_srcset', 'nofb_filter_image_srcset', 10, 5);
    add_filter('wp_get_attachment_image', 'nofb_filter_attachment_image', 10, 5);
    
    // Handle all image sizes including WooCommerce thumbnails
    add_filter('wp_get_attachment_image_attributes', 'nofb_filter_image_attributes', 10, 3);
    
    // Page Builder integrations
    // Elementor support
    if (class_exists('Elementor\Plugin')) {
        add_filter('elementor/image_size/get_attachment_image_html', 'nofb_process_html_images', 10, 1); 
        // Ensure JSON in Elementor data is processed
        add_filter('elementor/files/allow_unfiltered_upload', '__return_true', 999);
    }
    
    // Beaver Builder support
    if (class_exists('FLBuilder')) {
        add_filter('fl_builder_photo_data', function($data) {
            if (isset($data['url']) && !empty($data['url']) && isset($data['id']) && !empty($data['id'])) {
                $new_url = nofb_get_bunny_url($data['id'], $data['url']);
                if ($new_url) {
                    $data['url'] = $new_url;
                }
            }
            return $data;
        }, 10, 1);
    }
    
    // Brizy support
    if (class_exists('Brizy_Editor')) {
        add_filter('brizy_content', 'nofb_comprehensive_url_replacement', 10, 1);
    }
    
    // Divi support
    if (defined('ET_BUILDER_VERSION')) {
        add_filter('et_pb_module_shortcode_attributes', function($props, $atts, $slug) {
            if (isset($props['src']) && !empty($props['src']) && strpos($props['src'], 'wp-content/uploads') !== false) {
                $attachment_id = nofb_get_attachment_id_from_url($props['src']);
                if ($attachment_id) {
                    $new_url = nofb_get_bunny_url($attachment_id, $props['src']);
                    if ($new_url) {
                        $props['src'] = $new_url;
                    }
                }
            }
            return $props;
        }, 10, 3);
    }
    
    // WooCommerce specific filters
    if (class_exists('WooCommerce')) {
        add_filter('woocommerce_product_get_image', 'nofb_filter_woocommerce_product_image', 10, 2);
        add_filter('woocommerce_single_product_image_thumbnail_html', 'nofb_filter_woocommerce_gallery_image_html', 10, 2);
        // Add filter for WooCommerce thumbnail URLs
        add_filter('woocommerce_product_get_gallery_image_ids', 'nofb_ensure_wc_gallery_urls', 10, 2);
        // Filter for product thumbnails
        add_filter('post_thumbnail_html', 'nofb_filter_post_thumbnail_html', 10, 5);
        
        // Additional filters for WooCommerce product variations and thumbnails
        add_filter('woocommerce_available_variation', 'nofb_filter_wc_variation_data', 10, 3);
        add_filter('woocommerce_product_thumbnails', 'nofb_filter_product_thumbnails', 99);
        add_filter('wp_get_attachment_thumb_url', 'nofb_filter_thumb_url', 10, 2);
        
        // Ensure compatibility with default WooCommerce product templates
        add_filter('woocommerce_placeholder_img_src', 'nofb_maybe_filter_placeholder', 10);
    }
}

// Handle file upload
function nofb_handle_upload($upload, $context) {
    if (!is_array($upload) || isset($upload['error'])) {
        return $upload;
    }
    
    // Skip the optimization if user disables it explicitly or site is not HTTPS
    if (get_option('nofb_disable_auto_optimize', false) || !is_ssl()) {
        return $upload;
    }
    
    // Add to optimization queue
    $queue = new NOFB_Queue('optimization');
    $queue->add($upload['file']);
    
    return $upload;
}

// Filter attachment URL
function nofb_filter_attachment_url($url, $attachment_id) {
    $bunny_url = nofb_get_bunny_url($attachment_id, $url);
    return $bunny_url ? $bunny_url : $url;
}

// Filter image src
function nofb_filter_attachment_image_src($image, $attachment_id, $size, $icon) {
    if (!$image) {
        return $image;
    }
    
    $new_url = nofb_get_bunny_url($attachment_id, $image[0]);
    if ($new_url) {
        $image[0] = $new_url;
    }
    
    return $image;
}

// Filter srcset
function nofb_filter_image_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
    if (empty($sources) || !$attachment_id) {
        return $sources;
    }
    
    foreach ($sources as &$source) {
        $new_url = nofb_get_bunny_url($attachment_id, $source['url']);
        if ($new_url) {
            $source['url'] = $new_url;
        }
    }
    
    return $sources;
}

// Filter wp_get_attachment_image
function nofb_filter_attachment_image($html, $attachment_id, $size, $icon, $attr) {
    return nofb_process_html_images($html, $attachment_id);
}

// Filter image attributes (for all sizes including WooCommerce)
function nofb_filter_image_attributes($attr, $attachment, $size) {
    if (!isset($attr['src']) || !is_object($attachment) || !isset($attachment->ID)) {
        return $attr;
    }
    
    $attachment_id = $attachment->ID;
    
    // Update src attribute
    $new_url = nofb_get_bunny_url($attachment_id, $attr['src']);
    if ($new_url) {
        $attr['src'] = $new_url;
        
        // Update srcset if it exists
        if (isset($attr['srcset'])) {
            $attr['srcset'] = nofb_process_srcset($attr['srcset'], $attachment_id);
        }
        
        // Update data attributes for WooCommerce
        foreach (array('data-src', 'data-large_image', 'data-thumb') as $data_attr) {
            if (isset($attr[$data_attr])) {
                $new_data_url = nofb_get_bunny_url($attachment_id, $attr[$data_attr]);
                if ($new_data_url) {
                    $attr[$data_attr] = $new_data_url;
                }
            }
        }
    }
    
    return $attr;
}

// Filter WooCommerce product image
function nofb_filter_woocommerce_product_image($image, $product) {
    if (empty($image)) {
        return $image;
    }
    
        $image_id = $product->get_image_id();
    return nofb_process_html_images($image, $image_id);
}

// Filter WooCommerce gallery image HTML
function nofb_filter_woocommerce_gallery_image_html($html, $attachment_id) {
    return nofb_process_html_images($html, $attachment_id);
}

// Process optimization queue cron job
add_action('nofb_process_optimization_queue', 'nofb_process_optimization_queue_callback');
function nofb_process_optimization_queue_callback() {
    try {
        nofb_log('Starting optimization process...');
        
        $queue = new NOFB_Queue('optimization');
        $optimizer = new NOFB_Optimizer();
        
        global $nofb_optimization_batch_size;
        
        // Allow filtering the batch size
        $batch_size = apply_filters('nofb_optimization_batch_size', $nofb_optimization_batch_size);
        
        // Ensure batch size is within limits (1-5)
        $batch_size = min(max(1, $batch_size), 5);
        
        $batch = $queue->get_batch($batch_size);
        if (!empty($batch)) {
            nofb_log('Processing batch of ' . count($batch) . ' files...');
            
            // Verify files exist and are readable before proceeding
            $valid_batch = array();
            foreach ($batch as $file_path) {
                if (!file_exists($file_path)) {
                    nofb_log('File does not exist: ' . basename($file_path), 'warning');
                    // Remove from queue to prevent processing attempts
                    $queue->remove($file_path);
                    continue;
                }
                
                if (!is_readable($file_path)) {
                    nofb_log('File is not readable: ' . basename($file_path), 'warning');
                    // Remove from queue to prevent processing attempts
                    $queue->remove($file_path);
                    continue;
                }
                
                $valid_batch[] = $file_path;
            }
            
            // Log files being optimized
            if (defined('WP_DEBUG') && WP_DEBUG) {
                nofb_log('Files to optimize: ' . implode(', ', array_map('basename', $valid_batch)));
            }
            
            // Skip processing if no valid files remain
            if (empty($valid_batch)) {
                nofb_log('No valid files to process in batch.', 'warning');
                return;
            }
            
            // Process valid batch
            $result = $optimizer->optimize_batch($valid_batch);
            
            if ($result === false) {
                nofb_log('Error: Optimization failed.', 'error');
                // Add more detailed error context
                if (function_exists('error_get_last') && $error = error_get_last()) {
                    nofb_log('Last PHP error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line'], 'error');
                }
                
                // Check server resource limits
                $memory_usage = function_exists('memory_get_usage') ? round(memory_get_usage() / 1024 / 1024, 2) . 'MB' : 'N/A';
                $memory_limit = ini_get('memory_limit');
                nofb_log("System info - Memory usage: {$memory_usage}, Memory limit: {$memory_limit}", 'info');
                
                // Keep failed files in queue for retry unless tried too many times
                foreach ($valid_batch as $file_path) {
                    $retry_count = $queue->get_retry_count($file_path);
                    if ($retry_count >= 3) {
                        nofb_log('Removing file after 3 failed attempts: ' . basename($file_path), 'warning');
                        $queue->remove($file_path);
                    }
                }
            } else if ($result === 0) {
                nofb_log('Optimization completed but no files were optimized. Check API response for details.', 'warning');
                // Remove processed files from queue
                $queue->remove_batch($valid_batch);
            } else {
                nofb_log('Processed ' . count($valid_batch) . ' files with ' . $result . ' successes.');
                // Remove successfully processed files from queue
                $queue->remove_batch($valid_batch);
            }
        } else {
            nofb_log('No files to process in queue.');
        }
    } catch (Exception $e) {
        nofb_log('Exception in optimization process: ' . $e->getMessage(), 'error');
        nofb_log('Exception trace: ' . $e->getTraceAsString(), 'error');
    } catch (Error $e) {
        nofb_log('Fatal error in optimization process: ' . $e->getMessage(), 'error');
        nofb_log('Error trace: ' . $e->getTraceAsString(), 'error');
    }
}

// Process migration queue cron job
add_action('nofb_process_migration_queue', 'nofb_process_migration_queue_callback');
function nofb_process_migration_queue_callback() {
    try {
        nofb_log('Starting migration process...');
        
        // Only process migration queue if automatic migration is enabled
        $options = nofb_get_cached_options();
        if (!$options['auto_migrate']) {
            nofb_log('Auto migration is disabled. Skipping.');
            return;
        }
        
        $queue = new NOFB_Queue('migration');
        $migrator = new NOFB_Migrator();
        
        global $nofb_migration_batch_size;
        $batch = $queue->get_batch($nofb_migration_batch_size);
        
        if (!empty($batch)) {
            // Log what we're about to process
            nofb_log('Processing batch of ' . count($batch) . ' files for migration...');
            
            // Debug: Log file paths being migrated
            if (defined('WP_DEBUG') && WP_DEBUG) {
                nofb_log('Files to migrate: ' . implode(', ', array_map('basename', $batch)));
            }
            
            $result = $migrator->migrate_batch($batch);
            
            if ($result === false) {
                nofb_log('Migration process failed. Please check Bunny CDN settings.', 'error');
                // Add additional error context if available
                if (function_exists('error_get_last') && $error = error_get_last()) {
                    nofb_log('Last PHP error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line'], 'error');
                }
            } else if ($result === 0) {
                nofb_log('No files found for migration. Try updating your settings to include more file types.', 'warning');
            } else {
                nofb_log('Migration process completed! Successfully migrated ' . $result . ' files.');
            }
        } else {
            nofb_log('No files found for migration. Try updating your settings to include more file types.', 'info');
        }
    } catch (Exception $e) {
        nofb_log('Exception in migration process: ' . $e->getMessage(), 'error');
        nofb_log('Exception trace: ' . $e->getTraceAsString(), 'error');
    } catch (Error $e) {
        nofb_log('Fatal error in migration process: ' . $e->getMessage(), 'error');
        nofb_log('Error trace: ' . $e->getTraceAsString(), 'error');
    }
}

/**
 * Filter: nofb_api_timeout
 * 
 * Allows customizing the API timeout value for image optimization requests.
 * If you experience timeout issues, you can increase this value.
 * 
 * @param int $timeout The timeout value in seconds. Default: 120
 * @return int Modified timeout value
 * 
 * Example usage:
 * add_filter('nofb_api_timeout', function($timeout) { return 180; }); // Set to 3 minutes
 */

/**
 * Filter: nofb_optimization_batch_size
 * 
 * Allows dynamically adjusting the optimization batch size.
 * If you experience timeout issues, you can reduce this value.
 * 
 * @param int $batch_size The batch size (1-5). Default: 5
 * @return int Modified batch size
 * 
 * Example usage:
 * add_filter('nofb_optimization_batch_size', function($size) { return 2; }); // Process 2 images at a time
 */

/**
 * Ensure gallery image IDs are processed for Bunny CDN URLs
 * This is a passthrough filter that ensures gallery images are processed correctly
 */
function nofb_ensure_wc_gallery_urls($gallery_image_ids, $product) {
    // Pre-warm the attachment URL cache for these gallery images
    if (!empty($gallery_image_ids)) {
        foreach ($gallery_image_ids as $attachment_id) {
            // Check if the attachment is migrated
            $is_migrated = get_post_meta($attachment_id, '_nofb_migrated', true);
            if ($is_migrated) {
                // Force processing of all thumbnail sizes for this attachment
                $metadata = wp_get_attachment_metadata($attachment_id);
                if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
                    // Generate an array of common WooCommerce sizes to ensure they're migrated
                    $wc_sizes = array(
                        'woocommerce_thumbnail', 
                        'woocommerce_single', 
                        'woocommerce_gallery_thumbnail',
                        'shop_single', 
                        'shop_thumbnail', 
                        'shop_catalog'
                    );
                    
                    // Force processing each size to ensure it's in BunnyCDN
                    foreach ($wc_sizes as $size) {
                        $image = wp_get_attachment_image_src($attachment_id, $size);
                        if ($image) {
                            // Also force the srcset to be generated
                            $metadata = wp_get_attachment_metadata($attachment_id);
                            $size_array = isset($metadata['sizes'][$size]) ? array($metadata['sizes'][$size]['width'], $metadata['sizes'][$size]['height']) : array(100, 100);
                            wp_calculate_image_srcset($size_array, $image[0], $metadata, $attachment_id);
                        }
                    }
                }
            }
        }
    }
    return $gallery_image_ids;
}

/**
 * Filter post thumbnail HTML
 */
function nofb_filter_post_thumbnail_html($html, $post_id, $post_thumbnail_id, $size, $attr) {
    return nofb_process_html_images($html, $post_thumbnail_id);
}

/**
 * Filter WooCommerce variation data to ensure images are served from BunnyCDN
 */
function nofb_filter_wc_variation_data($variation_data, $product, $variation) {
    if (!isset($variation_data['image']) || !is_array($variation_data['image'])) {
        return $variation_data;
    }
    
    $attachment_id = $variation_data['image_id'];
    if (!$attachment_id) {
        return $variation_data;
    }
    
    // Update the variation image URLs
    foreach (array('src', 'thumb_src') as $key) {
        if (isset($variation_data['image'][$key])) {
            $new_url = nofb_get_bunny_url($attachment_id, $variation_data['image'][$key]);
            if ($new_url) {
                $variation_data['image'][$key] = $new_url;
                }
            }
        }
        
        // Update srcset if available
        if (isset($variation_data['image']['srcset'])) {
        $variation_data['image']['srcset'] = nofb_process_srcset($variation_data['image']['srcset'], $attachment_id);
    }
    
    return $variation_data;
}

/**
 * Filter product thumbnails to ensure they are served from BunnyCDN
 */
function nofb_filter_product_thumbnails() {
    // This is just a hook to ensure all thumbnails get processed
    // The actual work is done by the image filters already in place
    return;
}

/**
 * Filter thumbnail URLs directly
 */
function nofb_filter_thumb_url($url, $attachment_id) {
    // Simply reuse the attachment URL filter
    return nofb_filter_attachment_url($url, $attachment_id);
}

/**
 * Maybe filter WooCommerce placeholder image
 */
function nofb_maybe_filter_placeholder($src) {
    // We only want to process actual uploads, not placeholder images
    // This is here just to maintain compatibility
    return $src;
}

/**
 * Utility function to fix missing or incorrect Bunny URLs for migrated attachments
 * This can be called via WP-CLI or admin action to fix migration issues
 */
function nofb_fix_migrated_urls() {
    global $wpdb;
    
    // Find all attachments marked as migrated but potentially missing proper bunny_url
    $cache_key = 'nofb_migrated_attachments_fix';
    $migrated_attachments = wp_cache_get($cache_key, 'bunny_media_offload');
    
    if (false === $migrated_attachments) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $migrated_attachments = $wpdb->get_results(
            "SELECT p.ID, 
                pm1.meta_value as migrated,
                pm2.meta_value as bunny_url, 
                pm3.meta_value as attached_file
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_nofb_migrated'
             LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_nofb_bunny_url'
             LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment' 
             AND pm1.meta_value = '1'
             AND p.post_status = 'inherit'"
        );
        
        // Cache the result for 10 minutes (600 seconds) since this is used for fixing issues
        wp_cache_set($cache_key, $migrated_attachments, 'bunny_media_offload', 600);
    }
    
    $fixed_count = 0;
    $issues_found = array();
    
    foreach ($migrated_attachments as $attachment) {
        $attachment_id = $attachment->ID;
        $bunny_url = $attachment->bunny_url;
        $attached_file = $attachment->attached_file;
        
        // Check for missing bunny_url
        if (empty($bunny_url)) {
            $issues_found[] = "Missing bunny_url for attachment ID: $attachment_id";
            continue;
        }
        
        // Check for malformed bunny_url (missing hostname)
        if (strpos($bunny_url, 'https://') !== 0) {
            $issues_found[] = "Malformed bunny_url for attachment ID: $attachment_id - $bunny_url";
            
            // Try to fix it
            $custom_hostname = defined('BUNNY_CUSTOM_HOSTNAME') ? BUNNY_CUSTOM_HOSTNAME : get_option('bunny_custom_hostname', '');
            $storage_zone = defined('BUNNY_STORAGE_ZONE') ? BUNNY_STORAGE_ZONE : get_option('bunny_storage_zone', '');
            
            if (!empty($custom_hostname) && !empty($attached_file)) {
                $corrected_url = 'https://' . $custom_hostname . '/' . $attached_file;
                update_post_meta($attachment_id, '_nofb_bunny_url', $corrected_url);
                $fixed_count++;
            } elseif (!empty($storage_zone) && !empty($attached_file)) {
                $corrected_url = 'https://' . $storage_zone . '.b-cdn.net/' . $attached_file;
                update_post_meta($attachment_id, '_nofb_bunny_url', $corrected_url);
                $fixed_count++;
            }
        }
        
        // Clear any cached attachment URLs to force regeneration
        wp_cache_delete($attachment_id, 'posts');
        clean_attachment_cache($attachment_id);
    }
    
    // Return diagnostic information
    return array(
        'fixed_count' => $fixed_count,
        'issues_found' => $issues_found,
        'total_migrated' => count($migrated_attachments)
    );
}

/**
 * Debug function to check URL replacement for a specific attachment
 */
function nofb_debug_attachment_urls($attachment_id) {
    $debug_info = array();
    
    // Get basic attachment info
    $debug_info['attachment_id'] = $attachment_id;
    $debug_info['is_migrated'] = get_post_meta($attachment_id, '_nofb_migrated', true);
    $debug_info['bunny_url'] = get_post_meta($attachment_id, '_nofb_bunny_url', true);
    $debug_info['attached_file'] = get_post_meta($attachment_id, '_wp_attached_file', true);
    $debug_info['version'] = get_post_meta($attachment_id, '_nofb_version', true);
    
    // Test various WordPress functions
    $debug_info['wp_get_attachment_url'] = wp_get_attachment_url($attachment_id);
    $debug_info['wp_get_attachment_image_src'] = wp_get_attachment_image_src($attachment_id, 'full');
    
    // Test srcset generation
    $metadata = wp_get_attachment_metadata($attachment_id);
    if ($metadata) {
        $debug_info['metadata_sizes'] = array_keys($metadata['sizes'] ?? array());
        
        $size_array = array($metadata['width'] ?? 0, $metadata['height'] ?? 0);
        $debug_info['srcset'] = wp_calculate_image_srcset($size_array, $debug_info['wp_get_attachment_url'], $metadata, $attachment_id);
    }
    
    return $debug_info;
}

/**
 * Enhanced URL replacement function for comprehensive image variation handling
 * This function catches any URLs that might have been missed by other filters
 */
function nofb_comprehensive_url_replacement($content) {
    // Skip if no content or no uploads directory references
    if (empty($content) || (strpos($content, 'wp-content/uploads') === false && strpos($content, '/uploads/') === false)) {
        return $content;
    }
    
    // Get upload directory info
    $upload_dir = wp_upload_dir();
    $upload_url = $upload_dir['baseurl'];
    $upload_base = basename($upload_dir['basedir']); // Usually 'uploads'
    
    // Improved pattern to match more URL variations
    // 1. Full URLs with domain (https://domain.com/wp-content/uploads/...)
    // 2. Root-relative URLs (/wp-content/uploads/...)  
    // 3. Uploads-only URLs (/uploads/...)
    // 4. Relative URLs (../uploads/...)
    // 5. Even filename-only references in some contexts
    $pattern = '/((?:https?:\/\/[^\/]+)?(?:\/(?:wp-content\/)?uploads|\.\.\/uploads|\/' . $upload_base . ')\/[^\s\'"<>}\)]+\.(?:jpe?g|png|gif|webp|avif|svg))/i';
    
    if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
        return $content; // No image URLs found
    }
    
    $url_replacements = array();
    
    foreach ($matches as $match) {
        $original_url = $match[1];
        
        // Skip if we've already processed this URL
        if (isset($url_replacements[$original_url])) {
            continue;
        }
        
        // Handle various URL formats to standardize for attachment lookup
        $lookup_url = $original_url;
        
        // Convert to absolute URL for proper lookup if it's not already
        if (strpos($original_url, 'http') !== 0) {
            if (strpos($original_url, '/') === 0) {
                // Root-relative URL (/wp-content/uploads/...)
                $site_url = site_url();
                $lookup_url = $site_url . $original_url;
            } elseif (strpos($original_url, '../') === 0 || strpos($original_url, './') === 0) {
                // Relative URLs, we need to make a best guess
                $lookup_url = $upload_url . '/' . basename($original_url);
            } elseif (strpos($original_url, $upload_base) !== false) {
                // Path contains 'uploads' directory but in a different format
                $parts = explode($upload_base, $original_url, 2);
                if (!empty($parts[1])) {
                    $lookup_url = $upload_url . $parts[1];
                }
            }
        }
        
        $attachment_id = nofb_get_attachment_id_from_url($lookup_url);
        
        if (!$attachment_id && strpos($lookup_url, '-scaled.') !== false) {
            // Try without -scaled suffix
            $non_scaled_url = str_replace('-scaled.', '.', $lookup_url);
            $attachment_id = nofb_get_attachment_id_from_url($non_scaled_url);
        }
        
        if (!$attachment_id && preg_match('/-\d+x\d+\.(jpe?g|png|gif|webp|avif|svg)$/i', $lookup_url)) {
            // Try without dimensions suffix for thumbnails
            $clean_url = preg_replace('/-\d+x\d+\.(jpe?g|png|gif|webp|avif|svg)$/i', '.$1', $lookup_url);
            $attachment_id = nofb_get_attachment_id_from_url($clean_url);
        }
        
        // If still no attachment_id, try by filename only as last resort
        if (!$attachment_id) {
            $filename = basename($lookup_url);
            global $wpdb;
            
            // Create a cache key for this filename lookup
            $cache_key = 'nofb_file_lookup_' . md5($filename);
            $cached_id = wp_cache_get($cache_key, 'bunny_media_offload');
            
            if (false === $cached_id) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Using custom caching solution
                $attachment_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM $wpdb->postmeta 
                     WHERE meta_key = '_wp_attached_file' 
                     AND meta_value LIKE %s",
                    '%/' . $wpdb->esc_like($filename)
                ));
                
                // Cache the result for 1 hour
                wp_cache_set($cache_key, $attachment_id, 'bunny_media_offload', HOUR_IN_SECONDS);
            } else {
                $attachment_id = $cached_id;
            }
        }
        
        if ($attachment_id) {
            $new_url = nofb_get_bunny_url($attachment_id, $lookup_url);
            if ($new_url) {
                $url_replacements[$original_url] = $new_url;
            }
        }
    }
    
    // Apply all URL replacements
    if (!empty($url_replacements)) {
        $content = str_replace(array_keys($url_replacements), array_values($url_replacements), $content);
    }
    
    return $content;
}

/**
 * Universal content filter that combines HTML processing and URL replacement
 */
function nofb_filter_all_content_types($content) {
    // Skip if no content or no potential image references
    if (empty($content) || (strpos($content, '<img') === false && strpos($content, 'wp-content/uploads') === false)) {
        return $content;
    }
    
    // Process HTML images first (more efficient for content with image tags)
    if (strpos($content, '<img') !== false) {
        $content = nofb_process_html_images($content);
    }
    
    // Then do comprehensive URL replacement for any remaining URLs
    return nofb_comprehensive_url_replacement($content);
}

// Add comprehensive content filtering
add_filter('the_content', 'nofb_filter_all_content_types', 999);
add_filter('widget_text', 'nofb_filter_all_content_types', 999);
add_filter('widget_content', 'nofb_filter_all_content_types', 999);
add_filter('widget_text_content', 'nofb_filter_all_content_types', 999);
add_filter('the_excerpt', 'nofb_filter_all_content_types', 999);
add_filter('post_thumbnail_html', 'nofb_filter_all_content_types', 999);
add_filter('get_header_image_tag', 'nofb_filter_all_content_types', 999);
add_filter('wp_get_custom_css', 'nofb_filter_all_content_types', 999);
add_filter('the_editor_content', 'nofb_filter_all_content_types', 999);
add_filter('pre_option_blogdescription', 'nofb_filter_all_content_types', 999);
add_filter('pre_option_blogname', 'nofb_filter_all_content_types', 999);

// PAGE BUILDER SPECIFIC FILTERS
// Gutenberg blocks filter
add_filter('render_block', 'nofb_filter_all_content_types', 999);
add_filter('render_block_data', 'nofb_filter_block_data', 999);

// Common page builder meta fields filter (post_meta and serialized data)
add_filter('get_post_metadata', 'nofb_filter_meta_content', 10, 4);
add_filter('get_post_metadata', 'nofb_filter_page_builder_meta', 10, 4);

/**
 * Filter Gutenberg block data to ensure URLs are properly updated
 * 
 * @param array $block The block data
 * @return array The filtered block data
 */
function nofb_filter_block_data($block) {
    if (empty($block)) {
        return $block;
    }
    
    // Convert block to JSON to process all URL references
    $block_json = wp_json_encode($block);
    
    if ($block_json && strpos($block_json, 'wp-content/uploads') !== false) {
        // Process all URLs in the JSON
        $processed_json = nofb_comprehensive_url_replacement($block_json);
        
        if ($processed_json !== $block_json) {
            // Decode the processed JSON back to an array
            $processed_block = json_decode($processed_json, true);
            if (is_array($processed_block)) {
                return $processed_block;
            }
        }
    }
    
    return $block;
}

/**
 * Filter page builder meta fields to replace image URLs
 * Specifically for Elementor, Brizy, Divi, and other page builders
 * 
 * @param mixed $value The meta value
 * @param int $object_id The object ID
 * @param string $meta_key The meta key
 * @param bool $single Whether to return a single value
 * @return mixed The filtered value
 */
function nofb_filter_page_builder_meta($value, $object_id, $meta_key, $single) {
    // Skip if already filtered or not what we're looking for
    if ($value !== null || !$single || !is_string($meta_key)) {
        return $value;
    }
    
    // Check if this is a page builder meta key
    $page_builder_keys = array(
        // Elementor
        '_elementor_data',
        'elementor_library_category',
        '_elementor_page_settings',
        
        // Brizy
        'brizy',
        'brizy_attachment_uid',
        'brizy_post_data',
        
        // Divi
        'et_pb_post_content',
        'et_pb_use_builder',
        '_et_pb_post_settings',
        
        // Beaver Builder
        '_fl_builder_data',
        '_fl_builder_data_settings'
    );
    
    // Skip if not a page builder meta key
    if (!in_array($meta_key, $page_builder_keys) && strpos($meta_key, '_elementor_') !== 0 && 
        strpos($meta_key, '_et_pb_') !== 0 && strpos($meta_key, 'brizy') !== 0) {
        return $value;
    }
    
    // Get the original meta value
    remove_filter('get_post_metadata', 'nofb_filter_page_builder_meta', 10);
    $original_value = get_post_meta($object_id, $meta_key, $single);
    add_filter('get_post_metadata', 'nofb_filter_page_builder_meta', 10, 4);
    
    // Skip if empty or not string or array
    if (empty($original_value) || (!is_string($original_value) && !is_array($original_value))) {
        return $value;
    }
    
    // Process JSON strings
    if (is_string($original_value) && (strpos($original_value, 'wp-content/uploads') !== false || 
                                     strpos($original_value, 'http') !== false)) {
        try {
            if (strpos($original_value, '{') === 0 || strpos($original_value, '[') === 0) {
                // Probably a JSON string, try to decode and re-encode to process URLs
                $decoded = json_decode($original_value, true);
                if (is_array($decoded)) {
                    // Convert to JSON, apply our URL replacement, then back to array
                    $json_string = wp_json_encode($decoded);
                    $processed_json = nofb_comprehensive_url_replacement($json_string);
                    
                    if ($processed_json !== $json_string) {
                        // Return the processed value as the same type as original
                        return $processed_json;
                    }
                }
            }
            
            // Standard string processing
            return nofb_comprehensive_url_replacement($original_value);
        } catch (Exception $e) {
            nofb_log('Error processing page builder meta: ' . $e->getMessage(), 'error');
            return $original_value;
        }
    }
    
    // Process arrays (like serialized data)
    if (is_array($original_value)) {
        try {
            // Convert to JSON, apply our URL replacement, then back to array
            $json_string = wp_json_encode($original_value);
            
            if (strpos($json_string, 'wp-content/uploads') !== false || strpos($json_string, 'http') !== false) {
                $processed_json = nofb_comprehensive_url_replacement($json_string);
                
                if ($processed_json !== $json_string) {
                    $processed_value = json_decode($processed_json, true);
                    if (is_array($processed_value)) {
                        return $processed_value;
                    }
                }
            }
        } catch (Exception $e) {
            nofb_log('Error processing page builder array meta: ' . $e->getMessage(), 'error');
        }
    }
    
    return $value;
}

/**
 * Filter post metadata to replace image URLs in custom fields
 */
function nofb_filter_meta_content($value, $object_id, $meta_key, $single) {
    // Skip if not a single value, if meta_key is not a string, or if it's one of our own meta keys
    if (!$single || !is_string($meta_key) || strpos($meta_key, '_nofb_') === 0 || strpos($meta_key, '_wp_') === 0) {
        return $value;
    }
    
    // Only process if it looks like it might contain URLs
    if (is_string($value) && (strpos($value, 'http') !== false || strpos($value, '/wp-content/uploads/') !== false)) {
        return nofb_comprehensive_url_replacement($value);
    }
    
    return $value;
}

/**
 * Internal logging function with proper debug checks
 * 
 * @param string $message Message to log
 * @param string $level Log level (info, warning, error)
 * @return void
 */
function nofb_log($message, $level = 'info') {
    // Only log if debugging is enabled
    if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        $timestamp = '[' . gmdate('H:i:s') . '.' . sprintf('%03d', round(microtime(true) % 1 * 1000)) . ']';
        $formatted_message = $timestamp . ' nofb [' . strtoupper($level) . ']: ' . esc_html($message);
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only used in development environments
        error_log($formatted_message);
    }
    
    // Always log critical errors regardless of debug setting
    if ($level === 'error' && defined('nofb_CRITICAL_LOGGING') && nofb_CRITICAL_LOGGING) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only used for critical errors in production
        error_log('nofb Critical Error: ' . esc_html($message));
    }
}

/**
 * Get attachment posts with the migrated flag set
 *
 * @return array Array of post IDs that are marked as migrated
 */
function nofb_get_migrated_attachments() {
    global $wpdb;
    
    // Check cache first
    $cache_key = 'nofb_migrated_attachments';
    $cached_results = wp_cache_get($cache_key, 'bunny_media_offload');
    
    if (false !== $cached_results) {
        return $cached_results;
    }
    
    // If not in cache, perform the query
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Using custom caching solution
    $results = $wpdb->get_results(
        "SELECT post_id 
         FROM {$wpdb->postmeta} 
         WHERE meta_key = '_nofb_migrated' 
         AND meta_value = '1'"
    );
    
    // Cache the results for 5 minutes
    wp_cache_set($cache_key, $results, 'bunny_media_offload', 5 * MINUTE_IN_SECONDS);
    
    return $results;
}

/**
 * Force refresh all migrated URLs
 * This function can be called to fix issues with migrated images not displaying correctly
 */
function nofb_refresh_all_migrated_urls() {
    // Get migrated attachments using the cached function
    $results = nofb_get_migrated_attachments();
    $fixed_count = 0;
    
    foreach ($results as $result) {
        $attachment_id = $result->post_id;
        
        // Force refresh of bunny URL by clearing caches
        wp_cache_delete($attachment_id, 'posts');
        clean_attachment_cache($attachment_id);
        
        // Get original attachment URL
        $original_url = wp_get_attachment_url($attachment_id);
        $original_file = get_post_meta($attachment_id, '_wp_attached_file', true);
        $bunny_url = get_post_meta($attachment_id, '_nofb_bunny_url', true);
        
        // Check if the bunny URL is malformed or missing
        if (empty($bunny_url) || strpos($bunny_url, 'https://') !== 0) {
            // Try to fix it using the same logic as in nofb_fix_migrated_urls()
            $custom_hostname = defined('BUNNY_CUSTOM_HOSTNAME') ? BUNNY_CUSTOM_HOSTNAME : get_option('bunny_custom_hostname', '');
            $storage_zone = defined('BUNNY_STORAGE_ZONE') ? BUNNY_STORAGE_ZONE : get_option('bunny_storage_zone', '');
            
            if (!empty($custom_hostname) && !empty($original_file)) {
                $corrected_url = 'https://' . $custom_hostname . '/' . $original_file;
                update_post_meta($attachment_id, '_nofb_bunny_url', $corrected_url);
                $fixed_count++;
            } elseif (!empty($storage_zone) && !empty($original_file)) {
                $corrected_url = 'https://' . $storage_zone . '.b-cdn.net/' . $original_file;
                update_post_meta($attachment_id, '_nofb_bunny_url', $corrected_url);
                $fixed_count++;
            }
        }
    }
    
    // Update page builder content if option is set
    // Check nonce before processing form data
    $update_page_builders = false;
    if (isset($_POST['nofb_refresh_urls']) && isset($_POST['_wpnonce']) && 
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'nofb_refresh_urls') && 
        isset($_POST['nofb_fix_page_builder_content'])) {
        $update_page_builders = true;
    }
    
    $pages_updated = 0;
    if ($update_page_builders) {
        $pages_updated = nofb_fix_page_builder_urls();
    }
    
    // Clear the cache to ensure fresh results next time
    wp_cache_delete('nofb_migrated_attachments', 'bunny_media_offload');
    
    return array(
        'processed' => count($results),
        'fixed' => $fixed_count,
        'pages_updated' => $pages_updated
    );
}

/**
 * Fix URLs in page builder content
 * Specifically targets Elementor, Brizy, Divi, and Gutenberg pages
 */
function nofb_fix_page_builder_urls() {
    global $wpdb;
    
    // Find pages that use page builders
    $builder_meta_keys = array(
        '_elementor_data',          // Elementor
        'brizy_post_data',          // Brizy
        '_et_pb_use_builder',       // Divi
        '_fl_builder_data',         // Beaver Builder
    );
    
    // Cache the results to avoid repeated queries
    $cache_key = 'nofb_page_builder_posts';
    $page_builder_posts = wp_cache_get($cache_key, 'bunny_media_offload');
    
    if (false === $page_builder_posts) {
        // Build the query dynamically to avoid SQL interpolation
        $query = "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE (";
        $query_params = array();
        
        // Add each meta key as a condition with proper placeholder
        for ($i = 0; $i < count($builder_meta_keys); $i++) {
            if ($i > 0) {
                $query .= " OR ";
            }
            $query .= "meta_key = %s";
            $query_params[] = $builder_meta_keys[$i];
        }
        
        $query .= ") AND post_id IN (
            SELECT ID FROM {$wpdb->posts} 
            WHERE post_status = 'publish'
        )";
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Using custom caching solution
        $page_builder_posts = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is properly prepared with placeholders
            $wpdb->prepare($query, $query_params)
        );
        
        // Cache the results for 1 hour
        wp_cache_set($cache_key, $page_builder_posts, 'bunny_media_offload', HOUR_IN_SECONDS);
    }
    
    if (empty($page_builder_posts)) {
        return 0;
    }
    
    $updated_count = 0;
    $upload_dir = wp_upload_dir();
    $upload_url = $upload_dir['baseurl'];
    
    foreach ($page_builder_posts as $post) {
        $post_id = $post->post_id;
        $updated_post = false;
        
        // Process Elementor data
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        if (!empty($elementor_data) && is_string($elementor_data) && 
            (strpos($elementor_data, $upload_url) !== false || strpos($elementor_data, 'wp-content/uploads') !== false)) {
            
            $updated_elementor_data = nofb_comprehensive_url_replacement($elementor_data);
            if ($updated_elementor_data !== $elementor_data) {
                update_post_meta($post_id, '_elementor_data', $updated_elementor_data);
                $updated_post = true;
            }
        }
        
        // Process Brizy data
        $brizy_data = get_post_meta($post_id, 'brizy_post_data', true);
        if (!empty($brizy_data) && 
            (is_string($brizy_data) && 
             (strpos($brizy_data, $upload_url) !== false || strpos($brizy_data, 'wp-content/uploads') !== false))) {
            
            $updated_brizy_data = nofb_comprehensive_url_replacement($brizy_data);
            if ($updated_brizy_data !== $brizy_data) {
                update_post_meta($post_id, 'brizy_post_data', $updated_brizy_data);
                $updated_post = true;
            }
        }
        
        // Process Divi data
        $divi_data = get_post_meta($post_id, '_et_pb_post_settings', true);
        if (!empty($divi_data) && is_array($divi_data)) {
            $divi_json = wp_json_encode($divi_data);
            
            if (strpos($divi_json, $upload_url) !== false || strpos($divi_json, 'wp-content/uploads') !== false) {
                $updated_divi_json = nofb_comprehensive_url_replacement($divi_json);
                
                if ($updated_divi_json !== $divi_json) {
                    $updated_divi_data = json_decode($updated_divi_json, true);
                    if (is_array($updated_divi_data)) {
                        update_post_meta($post_id, '_et_pb_post_settings', $updated_divi_data);
                        $updated_post = true;
                    }
                }
            }
        }
        
        // Process Gutenberg blocks (stored in post_content)
        // Use cache for post content
        $cache_key = 'nofb_post_content_' . $post_id;
        $post_content = wp_cache_get($cache_key, 'bunny_media_offload');
        
        if (false === $post_content) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Using custom caching solution
            $post_content = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d",
                    $post_id
                )
            );
            
            // Cache post content for 1 hour
            wp_cache_set($cache_key, $post_content, 'bunny_media_offload', HOUR_IN_SECONDS);
        }
        
        if (!empty($post_content) && 
            (strpos($post_content, $upload_url) !== false || strpos($post_content, 'wp-content/uploads') !== false)) {
            
            $updated_content = nofb_comprehensive_url_replacement($post_content);
            
            if ($updated_content !== $post_content) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Necessary update with caching
                $wpdb->update(
                    $wpdb->posts,
                    array('post_content' => $updated_content),
                    array('ID' => $post_id),
                    array('%s'),
                    array('%d')
                );
                $updated_post = true;
                clean_post_cache($post_id);
                wp_cache_delete($cache_key, 'bunny_media_offload');
            }
        }
        
        if ($updated_post) {
            $updated_count++;
        }
    }
    
    return $updated_count;
}

// If admin, add a menu item to force URL refresh
add_action('admin_menu', 'nofb_add_tools_menu');
function nofb_add_tools_menu() {
    add_management_page(
        'Fix Bunny Media URLs', 
        'Fix Bunny Media', 
        'manage_options', 
        'nofb-fix-urls', 
        'nofb_fix_urls_page'
    );
}

// Fix URLs admin page callback
function nofb_fix_urls_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $message = '';
    
    // Process refresh if requested
    if (isset($_POST['nofb_refresh_urls']) && check_admin_referer('nofb_refresh_urls')) {
        $result = nofb_refresh_all_migrated_urls();
        $message = sprintf(
            '<div class="notice notice-success"><p>%s</p></div>',
            esc_html(sprintf('Processed %d images, fixed %d URLs.%s', 
                intval($result['processed']), 
                intval($result['fixed']),
                isset($result['pages_updated']) ? ' Updated content in ' . intval($result['pages_updated']) . ' pages.' : ''
            ))
        );
    }
    
    // Force update post content if requested
    if (isset($_POST['nofb_deep_fix_urls']) && check_admin_referer('nofb_deep_fix_urls')) {
        $result = nofb_deep_fix_all_urls();
        $message = sprintf(
            '<div class="notice notice-success"><p>%s</p></div>',
            esc_html(sprintf('Deep scan completed. Processed %d posts and updated %d URLs.', 
                intval($result['processed']), 
                intval($result['updated'])
            ))
        );
    }
    
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Fix Bunny Media URLs', 'nexoffload-for-bunny'); ?></h1>
        <?php echo wp_kses_post($message); ?>
        
        <div class="card">
            <h2><?php esc_html_e('Standard URL Fix', 'nexoffload-for-bunny'); ?></h2>
            <p><?php esc_html_e('This tool will update metadata for migrated images and fix URLs in page builder content.', 'nexoffload-for-bunny'); ?></p>
            <form method="post" action="">
                <?php wp_nonce_field('nofb_refresh_urls'); ?>
                <p>
                    <label>
                        <input type="checkbox" name="nofb_fix_page_builder_content" value="1" checked="checked">
                        <?php esc_html_e('Also fix URLs in page builder content (Elementor, Brizy, Divi, Gutenberg)', 'nexoffload-for-bunny'); ?>
                    </label>
                </p>
                <p class="description">
                    <?php esc_html_e('This will scan and update image URLs in content created with page builders like Elementor, Brizy, and Divi. May take longer for sites with many pages.', 'nexoffload-for-bunny'); ?>
                </p>
                <p><input type="submit" name="nofb_refresh_urls" class="button button-primary" value="<?php esc_attr_e('Refresh All Migrated URLs', 'nexoffload-for-bunny'); ?>"></p>
            </form>
        </div>
        
        <div class="card" style="margin-top: 20px;">
            <h2><?php esc_html_e('Deep URL Fix (Advanced)', 'nexoffload-for-bunny'); ?></h2>
            <p><?php esc_html_e('Use this option if you are still seeing broken images after trying the standard fix.', 'nexoffload-for-bunny'); ?></p>
            <form method="post" action="">
                <?php wp_nonce_field('nofb_deep_fix_urls'); ?>
                <p class="description">
                    <?php esc_html_e('This performs a more aggressive scan of ALL posts, pages, and custom post types and directly updates the content. May take several minutes to complete.', 'nexoffload-for-bunny'); ?>
                </p>
                <p><input type="submit" name="nofb_deep_fix_urls" class="button button-secondary" value="<?php esc_attr_e('Deep Fix All URLs (Advanced)', 'nexoffload-for-bunny'); ?>"></p>
            </form>
        </div>
    </div>
    <?php
}

/**
 * Perform a deep fix of all URLs in all post types
 */
function nofb_deep_fix_all_urls() {
    global $wpdb;
    
    // Get all published posts, pages, and custom post types
    $post_types = get_post_types(array('public' => true));
    
    // Build the query dynamically to avoid SQL interpolation
    $query = "SELECT ID, post_content, post_type FROM {$wpdb->posts} WHERE post_status = 'publish' AND (";
    $query_params = array();
    
    // Add each post type as a condition with proper placeholder
    for ($i = 0; $i < count($post_types); $i++) {
        if ($i > 0) {
            $query .= " OR ";
        }
        $query .= "post_type = %s";
        $query_params[] = $post_types[$i];
    }
    
    $query .= ")";
    
    // Create a cache key for this query
    $cache_key = 'nofb_deep_fix_posts_' . md5(implode('|', $post_types));
    $posts = wp_cache_get($cache_key, 'bunny_media_offload');
    
    if (false === $posts) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Using custom caching solution
        $posts = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is properly prepared with placeholders
            $wpdb->prepare($query, $query_params)
        );
        
        // Cache results for 15 minutes
        wp_cache_set($cache_key, $posts, 'bunny_media_offload', 15 * MINUTE_IN_SECONDS);
    }
    
    $processed = 0;
    $updated = 0;
    
    // Update all post content
    foreach ($posts as $post) {
        $processed++;
        $post_id = $post->ID;
        $content = $post->post_content;
        
        // Skip if no content or no potential image URLs
        if (empty($content) || (strpos($content, '/uploads/') === false && 
                             strpos($content, 'wp-content') === false)) {
            continue;
        }
        
        $updated_content = nofb_comprehensive_url_replacement($content);
        
        if ($updated_content !== $content) {
            // Update the post content
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Necessary update
            $wpdb->update(
                $wpdb->posts,
                array('post_content' => $updated_content),
                array('ID' => $post_id),
                array('%s'),
                array('%d')
            );
            clean_post_cache($post_id);
            $updated++;
        }
        
        // Also update post meta that might contain URLs
        nofb_fix_post_meta_urls($post_id);
    }
    
    return array(
        'processed' => $processed,
        'updated' => $updated
    );
}

/**
 * Fix URLs in post meta fields that might contain image references
 */
function nofb_fix_post_meta_urls($post_id) {
    global $wpdb;
    
    // Common meta keys that might contain image URLs
    $important_meta_keys = array(
        '_elementor_data',
        'brizy_post_data',
        '_et_pb_post_settings',
        '_fl_builder_data',
        '_cornerstone_data',
        '_wpb_shortcodes_custom_css',
        '_vc_post_settings',
        '_fusion_builder_content',
        '_wp_page_template',
        '_thumbnail_id'
    );
    
    // Build query dynamically to avoid SQL interpolation
    $query = "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND (
        meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s";
    
    // Initial parameters
    $query_params = array(
        $post_id,
        '%wp-content/uploads%',
        '%/uploads/%',
        '%s:5:"value"%' // Serialized data that might contain URLs
    );
    
    // Add meta key conditions
    if (!empty($important_meta_keys)) {
        $query .= " OR (";
        
        for ($i = 0; $i < count($important_meta_keys); $i++) {
            if ($i > 0) {
                $query .= " OR ";
            }
            $query .= "meta_key = %s";
            $query_params[] = $important_meta_keys[$i];
        }
        
        $query .= ")";
    }
    
    $query .= ")";
    
    // Create a cache key for this meta query
    $cache_key = 'nofb_meta_urls_' . $post_id . '_' . md5(implode('|', $important_meta_keys));
    $meta_entries = wp_cache_get($cache_key, 'bunny_media_offload');
    
    if (false === $meta_entries) {
        // Get all meta for this post that might contain URLs
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Using custom caching solution
        $meta_entries = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is properly prepared with placeholders
            $wpdb->prepare($query, $query_params)
        );
        
        // Cache results for 15 minutes
        wp_cache_set($cache_key, $meta_entries, 'bunny_media_offload', 15 * MINUTE_IN_SECONDS);
    }
    
    foreach ($meta_entries as $meta) {
        $meta_key = $meta->meta_key;
        $meta_value = $meta->meta_value;
        
        // Skip our own meta keys
        if (strpos($meta_key, '_nofb_') === 0) {
            continue;
        }
        
        // Skip if empty
        if (empty($meta_value)) {
            continue;
        }
        
        $is_serialized = is_serialized($meta_value);
        $updated_value = $meta_value;
        
        if ($is_serialized) {
            // Handle serialized data
            $unserialized = maybe_unserialize($meta_value);
            if (is_array($unserialized) || is_object($unserialized)) {
                $json = wp_json_encode($unserialized);
                if ($json && (strpos($json, 'wp-content/uploads') !== false || 
                           strpos($json, '/uploads/') !== false)) {
                    
                    $updated_json = nofb_comprehensive_url_replacement($json);
                    if ($updated_json !== $json) {
                        $updated_unserialized = json_decode($updated_json, true);
                        if (is_array($updated_unserialized) || is_object($updated_unserialized)) {
                            $updated_value = maybe_serialize($updated_unserialized);
                        }
                    }
                }
            }
        } else if (is_string($meta_value) && 
                 (strpos($meta_value, 'wp-content/uploads') !== false || 
                  strpos($meta_value, '/uploads/') !== false)) {
            
            // Handle string values with direct substitution
            $updated_value = nofb_comprehensive_url_replacement($meta_value);
        }
        
        // Update if changed
        if ($updated_value !== $meta_value) {
            update_post_meta($post_id, $meta_key, $updated_value);
        }
    }
    
    return true;
}

// Add filters to handle inline styles and background images
add_filter('style_loader_tag', 'nofb_filter_all_content_types', 999);
add_filter('script_loader_tag', 'nofb_filter_all_content_types', 999);

// Ensure we handle dynamic content too
add_filter('the_editor_content', 'nofb_filter_all_content_types', 999);
add_filter('admin_post_thumbnail_html', 'nofb_process_html_images', 10);
add_filter('post_thumbnail_html', 'nofb_process_html_images', 10);

// Support for CSS background-image urls
add_filter('wp_get_custom_css', 'nofb_filter_css_urls', 999);
function nofb_filter_css_urls($css) {
    if (empty($css) || strpos($css, 'url') === false) {
        return $css;
    }
    
    // Match URL patterns in CSS
    preg_match_all('/url\([\'"]?([^\'")]+)[\'"]?\)/i', $css, $matches);
    
    if (empty($matches[1])) {
        return $css;
    }
    
    $replacements = array();
    foreach ($matches[1] as $url) {
        // Only process if it looks like an upload URL
        if (strpos($url, 'wp-content/uploads') === false && strpos($url, '/uploads/') === false) {
            continue;
        }
        
        $attachment_id = nofb_get_attachment_id_from_url($url);
        if ($attachment_id) {
            $new_url = nofb_get_bunny_url($attachment_id, $url);
            if ($new_url) {
                $original_pattern = '/url\([\'"]?' . preg_quote($url, '/') . '[\'"]?\)/i';
                $replacement = 'url("' . $new_url . '")';
                $replacements[$original_pattern] = $replacement;
            }
        }
    }
    
    // Apply all replacements
    if (!empty($replacements)) {
        foreach ($replacements as $pattern => $replacement) {
            $css = preg_replace($pattern, $replacement, $css);
        }
    }
    
    return $css;
}

/**
 * Force update all image URLs to ensure proper formatting after plugin rename
 * This can be called from admin tools to fix display issues
 */
function nofb_force_update_image_urls() {
    global $wpdb;
    
    // Find all migrated images
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query used for maintenance tool with proper results handling
    $migrated_images = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT post_id 
             FROM $wpdb->postmeta 
             WHERE meta_key = %s 
             AND meta_value = %s",
            '_nofb_migrated',
            '1'
        )
    );
    
    if (empty($migrated_images)) {
        return array(
            'status' => 'error',
            'message' => 'No migrated images found to update'
        );
    }
    
    $updated = 0;
    $errors = 0;
    
    foreach ($migrated_images as $image) {
        $attachment_id = $image->post_id;
        $bunny_url = get_post_meta($attachment_id, '_nofb_bunny_url', true);
        
        if (empty($bunny_url)) {
            // URL is missing, try to regenerate it
            $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
            if (!empty($attached_file)) {
                $custom_hostname = defined('BUNNY_CUSTOM_HOSTNAME') ? BUNNY_CUSTOM_HOSTNAME : '';
                $storage_zone = defined('BUNNY_STORAGE_ZONE') ? BUNNY_STORAGE_ZONE : '';
                
                if (!empty($custom_hostname)) {
                    $new_url = 'https://' . $custom_hostname . '/' . ltrim($attached_file, '/');
                    update_post_meta($attachment_id, '_nofb_bunny_url', $new_url);
                    $updated++;
                } elseif (!empty($storage_zone)) {
                    $new_url = 'https://' . $storage_zone . '.b-cdn.net/' . ltrim($attached_file, '/');
                    update_post_meta($attachment_id, '_nofb_bunny_url', $new_url);
                    $updated++;
                } else {
                    $errors++;
                }
            } else {
                $errors++;
            }
            continue;
        }
        
        // Check URL format
        if (strpos($bunny_url, 'https://') !== 0) {
            // Malformed URL, need to fix it
            $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
            if (!empty($attached_file)) {
                $custom_hostname = defined('BUNNY_CUSTOM_HOSTNAME') ? BUNNY_CUSTOM_HOSTNAME : '';
                $storage_zone = defined('BUNNY_STORAGE_ZONE') ? BUNNY_STORAGE_ZONE : '';
                
                if (!empty($custom_hostname)) {
                    $new_url = 'https://' . $custom_hostname . '/' . ltrim($attached_file, '/');
                    update_post_meta($attachment_id, '_nofb_bunny_url', $new_url);
                    $updated++;
                } elseif (!empty($storage_zone)) {
                    $new_url = 'https://' . $storage_zone . '.b-cdn.net/' . ltrim($attached_file, '/');
                    update_post_meta($attachment_id, '_nofb_bunny_url', $new_url);
                    $updated++;
                } else {
                    $errors++;
                }
            } else {
                $errors++;
            }
        }
        
        // Clear caches for this attachment
        wp_cache_delete($attachment_id, 'posts');
        clean_attachment_cache($attachment_id);
    }
    
    // Clear any page caches to ensure new URLs are used
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
    
    return array(
        'status' => 'success',
        'total' => count($migrated_images),
        'updated' => $updated,
        'errors' => $errors
    );
}