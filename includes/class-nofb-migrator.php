<?php
/**
 * NOFB Migrator Class
 * Handles migration of media to Bunny Storage
 */

if (!defined('ABSPATH')) {
    exit;
}

class NOFB_Migrator {
    
    private $bunny_api_key;
    private $storage_zone;
    private $custom_hostname;
    private $storage_endpoint;
    private static $config_cache = null;
    
    public function __construct() {
        $config = $this->get_config();
        $this->bunny_api_key = $config['api_key'];
        $this->storage_zone = $config['storage_zone'];
        $this->custom_hostname = $config['custom_hostname'];
        
        // Bunny Storage API endpoint (defaults to NY region)
        // Can be changed to other regions: de.storage.bunnycdn.com, uk.storage.bunnycdn.com, etc.
        $this->storage_endpoint = 'https://storage.bunnycdn.com';
    }
    
    /**
     * Get cached configuration to avoid repeated get_option calls
     */
    private function get_config() {
        if (self::$config_cache === null) {
            self::$config_cache = array(
                'api_key' => BUNNY_API_KEY,
                'storage_zone' => BUNNY_STORAGE_ZONE,
                'custom_hostname' => BUNNY_CUSTOM_HOSTNAME,
                'max_file_size' => get_option('nofb_max_file_size', NOFB_DEFAULT_MAX_FILE_SIZE),
                'file_versioning' => get_option('nofb_file_versioning', false)
            );
        }
        return self::$config_cache;
    }
    
    /**
     * Generate consistent cache keys
     */
    private function get_cache_key($type, $identifier) {
        return 'nofb_' . $type . '_' . md5($identifier);
    }
    
    /**
     * Get attachment IDs by multiple file paths in a single query
     */
    private function get_attachment_ids_by_paths($file_paths) {
        global $wpdb;
        
        if (empty($file_paths)) {
            return array();
        }
        
        $upload_dir = wp_upload_dir();
        $relative_paths = array();
        
        foreach ($file_paths as $file_path) {
            $relative_paths[] = str_replace($upload_dir['basedir'] . '/', '', $file_path);
        }
        
        $cache_key = $this->get_cache_key('batch_attachment_ids', implode('|', $relative_paths));
        $results = wp_cache_get($cache_key, 'nofb_migrator');
        
        if ($results === false) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct DB query needed for bulk attachment lookup by path with custom caching implementation (see wp_cache_set below)
            $query = $wpdb->prepare(
                sprintf(
                    "SELECT post_id, meta_value FROM $wpdb->postmeta 
                     WHERE meta_key = '_wp_attached_file' 
                     AND meta_value IN (%s)",
                    implode(',', array_fill(0, count($relative_paths), '%s'))
                ),
                $relative_paths
            );
            /* phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- $query is properly prepared above; Needed for bulk lookup with custom caching */
            $results = $wpdb->get_results($query, OBJECT_K);
            
            wp_cache_set($cache_key, $results, 'nofb_migrator', HOUR_IN_SECONDS);
        }
        
        $path_to_id_map = array();
        foreach ($results as $result) {
            $full_path = $upload_dir['basedir'] . '/' . $result->meta_value;
            $path_to_id_map[$full_path] = intval($result->post_id);
        }
        
        return $path_to_id_map;
    }
    
    /**
     * Comprehensive pattern matching for image variations
     */
    private function is_image_variation($file, $filename_no_ext, $file_ext) {
        $patterns = array(
            // Standard WordPress naming (filename-123x456.ext)
            '/^' . preg_quote($filename_no_ext, '/') . '-\d+x\d+\.' . preg_quote($file_ext, '/') . '$/i',
            // Scaled versions (filename-scaled.ext) - WordPress 5.3+
            '/^' . preg_quote($filename_no_ext, '/') . '-scaled\.' . preg_quote($file_ext, '/') . '$/i',
            // Rotated versions (filename-rotated.ext)
            '/^' . preg_quote($filename_no_ext, '/') . '-rotated\.' . preg_quote($file_ext, '/') . '$/i',
            // Edited versions (filename-e[0-9]+.ext) - WordPress image editor
            '/^' . preg_quote($filename_no_ext, '/') . '-e\d+\.' . preg_quote($file_ext, '/') . '$/i'
        );
        
        // Check standard patterns
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $file)) {
                return true;
            }
        }
        
        // Named size variations (filename-sizename.ext)
        if (strpos($file, $filename_no_ext . '-') === 0 && 
            strrpos($file, '.' . $file_ext) === (strlen($file) - strlen('.' . $file_ext))) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Collect all files related to the main image file in one pass
     */
    private function collect_all_related_files($main_file_path) {
        $base_dir = dirname($main_file_path);
        $original_filename = basename($main_file_path);
        $filename_no_ext = pathinfo($original_filename, PATHINFO_FILENAME);
        $file_ext = pathinfo($original_filename, PATHINFO_EXTENSION);
        
        if (!is_dir($base_dir)) {
            return array();
        }
        
        $all_files = @scandir($base_dir);
        if (!$all_files) {
            return array();
        }
        
        $related_files = array();
        
        foreach ($all_files as $file) {
            // Skip directories, main file, and system files
            if (is_dir($base_dir . '/' . $file) || $file === $original_filename || $file === '.' || $file === '..') {
                continue;
            }
            
            if ($this->is_image_variation($file, $filename_no_ext, $file_ext)) {
                $related_files[] = $file;
            }
        }
        
        return $related_files;
    }
    
    /**
     * Common upload method to reduce code duplication
     */
    private function upload_to_bunny($file_path, $relative_path, $timeout = 60) {
        $bunny_path = '/' . $this->storage_zone . '/' . $relative_path;
        $upload_url = $this->storage_endpoint . $bunny_path;
        
        // Check file readability
        if (!is_readable($file_path)) {
            $this->log('nofb Migrator Error: File is not readable: ' . $file_path);
            return false;
        }
        
        // For large files, consider using stream approach
        $file_size = filesize($file_path);
        if ($file_size > 5 * 1024 * 1024) { // 5MB threshold
            return $this->upload_file_stream($file_path, $upload_url, $timeout);
        }
        
        // Standard upload for smaller files
        $file_contents = file_get_contents($file_path);
        if (!$file_contents) {
            $this->log('nofb Migrator Error: Could not read file: ' . $file_path);
            return false;
        }
        
        $mime_type = wp_check_filetype($file_path)['type'];
        
        $response = wp_remote_request(
            $upload_url,
            array(
                'method' => 'PUT',
                'headers' => array(
                    'AccessKey' => $this->bunny_api_key,
                    'Content-Type' => $mime_type
                ),
                'body' => $file_contents,
                'timeout' => $timeout,
                'sslverify' => true
            )
        );
        
        if (is_wp_error($response)) {
            $this->log('nofb Migrator Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        return ($response_code === 201 || $response_code === 200) ? $response : false;
    }
    
    /**
     * Stream-based upload for large files
     */
    private function upload_file_stream($file_path, $upload_url, $timeout = 300) {
        $mime_type = wp_check_filetype($file_path)['type'];
        
        // Initialize WP_Filesystem
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;
        
        // Read file contents using WP_Filesystem
        $file_contents = $wp_filesystem->get_contents($file_path);
        
        if (!$file_contents) {
            $this->log('nofb Migrator Error (Stream): Could not read file: ' . $file_path);
            return false;
        }
        
        $response = wp_remote_request(
            $upload_url,
            array(
                'method' => 'PUT',
                'headers' => array(
                    'AccessKey' => $this->bunny_api_key,
                    'Content-Type' => $mime_type
                ),
                'body' => $file_contents,
                'timeout' => $timeout,
                'sslverify' => true
            )
        );
        
        if (is_wp_error($response)) {
            $this->log('nofb Migrator Error (Stream): ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        return ($response_code === 201 || $response_code === 200) ? $response : false;
    }
    
    /**
     * Log a message only when debugging is enabled
     *
     * @param string $message The message to log
     * @return void
     */
    private function log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Using wp_debug_log function to handle logging properly based on WordPress settings
            if (function_exists('wp_debug_log')) {
                wp_debug_log('nofb Migrator: ' . $message);
            }
        }
    }
    
    /**
     * Migrate a batch of files to Bunny storage
     */
    public function migrate_batch($file_paths) {
        if (empty($file_paths) || !is_array($file_paths)) {
            return false;
        }
        
        $success_count = 0;
        $failures = array();
        $retried = array();
        
        foreach ($file_paths as $file_path) {
            // Normalize the file path to handle environment differences
            $normalized_path = $this->normalize_file_path($file_path);
            
            try {
                if ($this->migrate_file($normalized_path)) {
                    $success_count++;
                } else {
                    // Try force migration for files that fail the standard migration
                    $retried[] = basename($normalized_path);
                    
                    if ($this->force_migrate_file($normalized_path)) {
                        $success_count++;
                    } else {
                        $failures[] = $normalized_path;
                    }
                }
            } catch (Exception $e) {
                $failures[] = $normalized_path;
            }
            
            // 200ms delay between uploads as required by Bunny API
            usleep(200000); // 200ms = 200,000 microseconds
        }
        
        $total_files = count($file_paths);

        
        // Tracking batch migration results
        
        return $success_count;
    }
    
    /**
     * Migrate a single file to Bunny storage
     */
    private function migrate_file($file_path) {
        if (!$this->is_eligible_for_migration($file_path)) {
            return false;
        }
        
        $attachment_id = $this->get_attachment_id_by_path($file_path);
        if (!$attachment_id) {
            $this->log('nofb Migrator Error: Could not determine attachment ID for ' . $file_path);
            return false;
        }
        
        // Get file info
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['basedir'] . '/', '', $file_path);
        
        // Upload to Bunny using optimized method
        $response = $this->upload_to_bunny($file_path, $relative_path, 300);
        
        if ($response) {
            // Success - now migrate all image sizes for this attachment
            $this->migrate_image_sizes($attachment_id, $file_path);
            
            // Update database
            $this->mark_as_migrated($attachment_id, $file_path, $relative_path);
            
            // Always delete local file after successful migration
            $this->delete_local_file($file_path, $attachment_id);
            
            return true;
        } else {
            $this->log('nofb Migrator Error: Failed to upload to Bunny');
            return false;
        }
    }
    
    /**
     * Migrate all image sizes for an attachment
     */
    private function migrate_image_sizes($attachment_id, $main_file_path) {
        // Get attachment metadata
        $metadata = wp_get_attachment_metadata($attachment_id);
        
        if (!isset($metadata['sizes']) || !is_array($metadata['sizes']) || empty($metadata['sizes'])) {
            // Even if no metadata sizes, we should still check for physical files
            $this->log('nofb Migrator: No sizes in metadata for attachment ' . $attachment_id . ', checking for physical files only.');
        }
        
        $base_dir = dirname($main_file_path);
        $upload_dir = wp_upload_dir();
        $success_count = 0;
        $total_sizes = isset($metadata['sizes']) ? count($metadata['sizes']) : 0;
        
        // First, migrate all sizes that are explicitly defined in metadata
        if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $size_data) {
                // Skip if no file info
                if (!isset($size_data['file'])) {
                    continue;
                }
                
                $size_file_path = $base_dir . '/' . $size_data['file'];
                $relative_path = str_replace($upload_dir['basedir'] . '/', '', $size_file_path);
                
                // Check if file exists
                if (!file_exists($size_file_path)) {
                    $this->log('nofb Migrator Error: Size file not found: ' . $size_file_path);
                    continue;
                }
                
                // Upload using optimized method
                $response = $this->upload_to_bunny($size_file_path, $relative_path, 60);
                
                if ($response) {
                    $success_count++;
                } else {
                    $this->log('nofb Migrator Error: Failed to upload size ' . $size);
                }
                
                // Wait 200ms between uploads as required by Bunny API
                usleep(200000);
            }
        }
        
        // Now scan for ALL additional files in the same directory that might be image variations
        // This includes WordPress-generated sizes, WooCommerce sizes, theme-specific sizes, and plugin-generated sizes
        $related_files = $this->collect_all_related_files($main_file_path);
        
        foreach ($related_files as $file) {
            // Found a variation, upload it to Bunny
            $size_file_path = $base_dir . '/' . $file;
            $relative_path = str_replace($upload_dir['basedir'] . '/', '', $size_file_path);
            
            // Check if file exists and is readable
            if (!file_exists($size_file_path)) {
                $this->log('nofb Migrator Error: Variation file not found: ' . $size_file_path);
                continue;
            }
            
            // Upload using optimized method
            $response = $this->upload_to_bunny($size_file_path, $relative_path, 60);
            
            if ($response) {
                $success_count++;
                $this->log('nofb Migrator: Successfully uploaded variation: ' . $file);
            } else {
                $this->log('nofb Migrator Error: Failed to upload variation ' . $file);
            }
            
            // Wait 200ms between uploads as required by Bunny API
            usleep(200000);
        }
        
        // Additional check: Force generation and migration of common sizes that might be missing
        $this->ensure_common_sizes_migrated($attachment_id, $main_file_path);
        
        $this->log('nofb Migrator: Migrated ' . $success_count . ' image variations for attachment ' . $attachment_id);
        return $success_count;
    }
    
    /**
     * Ensure common WordPress and WooCommerce sizes are generated and migrated
     */
    private function ensure_common_sizes_migrated($attachment_id, $main_file_path) {
        // List of critical sizes to ensure are available
        $critical_sizes = array(
            'thumbnail',
            'medium', 
            'medium_large',
            'large'
        );
        
        // Add WooCommerce sizes if WooCommerce is active
        if (class_exists('WooCommerce')) {
            $critical_sizes = array_merge($critical_sizes, array(
                'woocommerce_thumbnail',
                'woocommerce_single', 
                'woocommerce_gallery_thumbnail',
                'shop_single',
                'shop_thumbnail', 
                'shop_catalog'
            ));
        }
        
        // Get current metadata
        $metadata = wp_get_attachment_metadata($attachment_id);
        
        foreach ($critical_sizes as $size) {
            // Check if this size already exists in metadata
            if (isset($metadata['sizes'][$size])) {
                continue; // Already handled in main migration
            }
            
            // Try to generate the image size
            $image_src = wp_get_attachment_image_src($attachment_id, $size);
            if ($image_src && isset($image_src[0])) {
                // Convert URL to file path
                $upload_dir = wp_upload_dir();
                $size_file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $image_src[0]);
                
                // Check if the file actually exists and is different from main file
                if (file_exists($size_file_path) && $size_file_path !== $main_file_path) {
                    // Upload this size to Bunny using optimized method
                    $relative_path = str_replace($upload_dir['basedir'] . '/', '', $size_file_path);
                    
                    $response = $this->upload_to_bunny($size_file_path, $relative_path, 60);
                    
                    if ($response) {
                        $this->log('nofb Migrator: Successfully uploaded generated size: ' . $size . ' for attachment ' . $attachment_id);
                    }
                    
                    // Wait 200ms between uploads
                    usleep(200000);
                }
            }
        }
    }
    
    /**
     * Regenerate and migrate all image sizes for an attachment
     * This is useful for ensuring all possible image variations are created and migrated
     */
    public function regenerate_and_migrate_sizes($attachment_id) {
        $file_path = get_attached_file($attachment_id);
        
        if (!$file_path || !file_exists($file_path)) {
            $this->log('nofb Migrator Error: File not found for attachment ' . $attachment_id);
            return false;
        }
        
        // Check if it's an image
        $mime_type = get_post_mime_type($attachment_id);
        if (strpos($mime_type, 'image/') !== 0) {
            $this->log('nofb Migrator: Skipping non-image file for attachment ' . $attachment_id);
            return false;
        }
        
        $this->log('nofb Migrator: Starting regeneration and migration for attachment ' . $attachment_id);
        
        // Get all registered image sizes
        $image_sizes = wp_get_additional_image_sizes();
        $default_sizes = array('thumbnail', 'medium', 'medium_large', 'large');
        
        // Add WooCommerce sizes if available
        if (class_exists('WooCommerce')) {
            $wc_sizes = array(
                'woocommerce_thumbnail' => array('width' => 300, 'height' => 300, 'crop' => true),
                'woocommerce_single' => array('width' => 600, 'height' => 600, 'crop' => true),
                'woocommerce_gallery_thumbnail' => array('width' => 100, 'height' => 100, 'crop' => true),
                'shop_single' => array('width' => 600, 'height' => 600, 'crop' => true),
                'shop_thumbnail' => array('width' => 300, 'height' => 300, 'crop' => true),
                'shop_catalog' => array('width' => 300, 'height' => 300, 'crop' => true)
            );
            $image_sizes = array_merge($image_sizes, $wc_sizes);
        }
        
        // Force regeneration of metadata and intermediate sizes
        $editor = wp_get_image_editor($file_path);
        if (!is_wp_error($editor)) {
            // Generate all intermediate sizes
            $generated_sizes = $editor->multi_resize($image_sizes);
            
            if (!is_wp_error($generated_sizes) && !empty($generated_sizes)) {
                // Update metadata with new sizes
                $metadata = wp_get_attachment_metadata($attachment_id);
                if (!$metadata) {
                    $metadata = array();
                }
                
                // Get image dimensions
                $size = $editor->get_size();
                $metadata['width'] = $size['width'];
                $metadata['height'] = $size['height'];
                
                // Merge generated sizes
                if (!isset($metadata['sizes'])) {
                    $metadata['sizes'] = array();
                }
                $metadata['sizes'] = array_merge($metadata['sizes'], $generated_sizes);
                
                // Update the metadata
                wp_update_attachment_metadata($attachment_id, $metadata);
                
                $this->log('nofb Migrator: Regenerated ' . count($generated_sizes) . ' image sizes for attachment ' . $attachment_id);
            }
        }
        
        // Now migrate all the regenerated sizes
        return $this->migrate_image_sizes($attachment_id, $file_path);
    }
    
    /**
     * Batch regenerate and migrate sizes for multiple attachments
     */
    public function batch_regenerate_and_migrate($attachment_ids) {
        $success_count = 0;
        $total_count = count($attachment_ids);
        
        foreach ($attachment_ids as $attachment_id) {
            try {
                if ($this->regenerate_and_migrate_sizes($attachment_id)) {
                    $success_count++;
                }
            } catch (Exception $e) {
                $this->log('nofb Migrator Error: Exception during regeneration for attachment ' . $attachment_id . ': ' . $e->getMessage());
            }
            
            // Small delay between attachments to prevent server overload
            usleep(100000); // 100ms
        }
        
        $this->log('nofb Migrator: Batch regeneration completed. Successfully processed ' . $success_count . ' out of ' . $total_count . ' attachments.');
        
        return $success_count;
    }
    
    /**
     * Verify migration completeness for an attachment
     * Checks if all local image variations have been migrated and local files deleted
     */
    public function verify_migration_completeness($attachment_id) {
        $file_path = get_attached_file($attachment_id);
        
        if (!$file_path) {
            return array(
                'status' => 'error',
                'message' => 'File path not found for attachment ' . $attachment_id
            );
        }
        
        $is_migrated = get_post_meta($attachment_id, '_nofb_migrated', true);
        $bunny_url = get_post_meta($attachment_id, '_nofb_bunny_url', true);
        $local_deleted = get_post_meta($attachment_id, '_nofb_local_deleted', true);
        
        $result = array(
            'attachment_id' => $attachment_id,
            'is_migrated' => $is_migrated,
            'bunny_url' => $bunny_url,
            'local_deleted' => $local_deleted,
            'local_files_found' => array(),
            'missing_on_bunny' => array(),
            'status' => 'unknown'
        );
        
        // Check for remaining local files
        $base_dir = dirname($file_path);
        $original_filename = basename($file_path);
        $filename_no_ext = pathinfo($original_filename, PATHINFO_FILENAME);
        $file_ext = pathinfo($original_filename, PATHINFO_EXTENSION);
        
        if (is_dir($base_dir)) {
            $all_files = scandir($base_dir);
            
            foreach ($all_files as $file) {
                if (is_dir($base_dir . '/' . $file) || $file === '.' || $file === '..') {
                    continue;
                }
                
                // Check if this file is related to our attachment
                if ($file === $original_filename || $this->is_image_variation($file, $filename_no_ext, $file_ext)) {
                    $result['local_files_found'][] = $file;
                }
            }
        }
        
        // Check if migrated files exist on Bunny (optional - requires API call)
        if ($is_migrated && !empty($bunny_url)) {
            // We can add Bunny verification here if needed
            // For now, we trust the migration metadata
        }
        
        // Determine status
        if (!$is_migrated) {
            $result['status'] = 'not_migrated';
            $result['message'] = 'Attachment not marked as migrated';
        } elseif (empty($bunny_url)) {
            $result['status'] = 'missing_bunny_url';
            $result['message'] = 'Migrated but missing Bunny URL';
        } elseif (!empty($result['local_files_found']) && !$local_deleted) {
            $result['status'] = 'incomplete_deletion';
            $result['message'] = 'Local files still exist: ' . implode(', ', $result['local_files_found']);
        } elseif (!empty($result['local_files_found']) && $local_deleted) {
            $result['status'] = 'deletion_failed';
            $result['message'] = 'Marked as deleted but files still exist: ' . implode(', ', $result['local_files_found']);
        } else {
            $result['status'] = 'complete';
            $result['message'] = 'Migration appears complete';
        }
        
        return $result;
    }
    
    /**
     * Verify migration completeness for multiple attachments
     */
    public function batch_verify_migration($attachment_ids) {
        $results = array();
        
        foreach ($attachment_ids as $attachment_id) {
            $results[$attachment_id] = $this->verify_migration_completeness($attachment_id);
        }
        
        return $results;
    }
    
    /**
     * Fix incomplete migrations by re-running migration and cleanup
     */
    public function fix_incomplete_migration($attachment_id) {
        $verification = $this->verify_migration_completeness($attachment_id);
        
        if ($verification['status'] === 'complete') {
            return array('status' => 'already_complete', 'message' => 'Migration already complete');
        }
        
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return array('status' => 'error', 'message' => 'File not found');
        }
        
        $this->log('nofb Migrator: Fixing incomplete migration for attachment ' . $attachment_id);
        
        // Re-run migration
        if ($this->migrate_file($file_path)) {
            // Verify again
            $new_verification = $this->verify_migration_completeness($attachment_id);
            return array(
                'status' => 'fixed',
                'message' => 'Migration re-run completed',
                'new_status' => $new_verification['status']
            );
        } else {
            return array('status' => 'error', 'message' => 'Migration re-run failed');
        }
    }
    
    /**
     * Normalize file path to match the current server environment
     * This helps handle differences between development and production paths
     */
    public function normalize_file_path($file_path) {
        // If it's a URL (particularly a CDN URL), don't try to normalize it as a local path
        if (filter_var($file_path, FILTER_VALIDATE_URL)) {
            return $file_path;
        }
        
        // Try remapping environments first using the NOFB_Optimizer class
        $optimizer = new NOFB_Optimizer();
        $remapped_path = $optimizer->remap_environment_path($file_path);
        if ($remapped_path !== $file_path && file_exists($remapped_path)) {
            return $remapped_path;
        }
        
        // If the file exists as-is, return it
        if (file_exists($file_path)) {
            return $file_path;
        }
        
        // Get the relative path from the uploads directory
        $upload_dir = wp_upload_dir();
        
        // Check if the file path includes a different base directory structure
        $relative_path = '';
        
        // Common path patterns to try to extract the relative path
        $path_patterns = array(
            // Pattern: /home/user/path/wp-content/uploads/
            '/wp-content/uploads/',
            // Pattern: /var/www/html/wp-content/uploads/
            '/var/www/html/wp-content/uploads/',
            // Pattern: /home/nexwinds-dev/htdocs/dev.nexwinds.com/wp-content/uploads/
            '/home/nexwinds-dev/htdocs/dev.nexwinds.com/wp-content/uploads/',
            // Pattern: /home/nexwinds-dev/htdocs/dev.nexwinds.com/public/wp-content/uploads/
            '/home/nexwinds-dev/htdocs/dev.nexwinds.com/public/wp-content/uploads/'
        );
        
        foreach ($path_patterns as $pattern) {
            if (strpos($file_path, $pattern) !== false) {
                $parts = explode($pattern, $file_path);
                if (isset($parts[1])) {
                    $relative_path = $parts[1];
                    break;
                }
            }
        }
        
        // If we couldn't extract a relative path, try to get it from the meta
        if (empty($relative_path)) {
            // Try to extract just the filename
            $basename = basename($file_path);
            
            // Look for this filename in the uploads directory
            global $wpdb;
            $like_path = '%/' . $basename;
            
            // Try to get from cache first
            $cache_key = 'nofb_file_meta_' . md5($like_path);
            $meta_value = wp_cache_get($cache_key, 'nofb_migrator');
            
            if ($meta_value === false) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for performance to find attachments by path with proper caching
                $meta_value = $wpdb->get_var($wpdb->prepare(
                    "SELECT meta_value FROM $wpdb->postmeta 
                     WHERE meta_key = '_wp_attached_file' 
                     AND meta_value LIKE %s
                     LIMIT 1",
                    $like_path
                ));
                
                // Cache the result for 1 hour
                wp_cache_set($cache_key, $meta_value, 'nofb_migrator', HOUR_IN_SECONDS);
            }
            
            if ($meta_value) {
                $relative_path = $meta_value;
            } else {
                // Last resort: just use the basename
                $year_month = gmdate('Y/m');
                $relative_path = $year_month . '/' . $basename;
            }
        }
        
        // Construct the correct path for the current environment
        $normalized_path = $upload_dir['basedir'] . '/' . $relative_path;
        
        if (file_exists($normalized_path)) {
            return $normalized_path;
        }
        
        // If we still can't find the file, return the original path
        // (it will fail, but at least we tried)
        return $file_path;
    }
    
    /**
     * Check if a file is eligible for migration
     * @param string $file_path File path
     * @return bool Eligibility status
     */
    public function is_eligible_for_migration($file_path) {
        // Normalize the file path to handle server environment differences
        $file_path = $this->normalize_file_path($file_path);
        
        if (!file_exists($file_path)) {
            $this->log('nofb Migrator Error: File does not exist: ' . $file_path);
            return false;
        }
        
        // Get attachment ID
        $attachment_id = $this->get_attachment_id_by_path($file_path);
        if (!$attachment_id) {
            $this->log('nofb Migrator Error: Could not get attachment ID for: ' . $file_path);
            return false;
        }
        
        // Check if file is actually stored on Bunny CDN
        // We need to verify if the attachment is ACTUALLY on the CDN and not just appearing to be
        $attachment_url = wp_get_attachment_url($attachment_id);
        
        // Get the stored migration status from post meta
        $is_migrated = get_post_meta($attachment_id, '_nofb_migrated', true);
        $bunny_url = get_post_meta($attachment_id, '_nofb_bunny_url', true);
        
        if ($is_migrated && !empty($bunny_url)) {
            $this->log('nofb Migrator Error: File already migrated: ' . basename($file_path) . ' (Found in post meta)');
            return false;
        }
        
        // Double check custom hostname against actual URL
        if (!empty($this->custom_hostname) && 
            (strpos($attachment_url, $this->custom_hostname) !== false || 
             strpos($attachment_url, 'bunnycdn.com') !== false)) {
            // Verify this is really a bunny CDN URL and not a false positive
            if ($is_migrated === '1' && !empty($bunny_url)) {
                $this->log('nofb Migrator Error: File is already on Bunny CDN: ' . basename($file_path));
                return false;
            } else {
                // URL appears to be on CDN but metadata doesn't confirm it
                $this->log('nofb Migrator Warning: URL appears to be on CDN but migration metadata is missing. Proceeding with migration.');
                // Reset the attachment URL to force migration
                update_post_meta($attachment_id, '_wp_attachment_url', '');
            }
        }
        
        // Check file type
        $mime_type = wp_check_filetype($file_path)['type'];
        $eligible_types = array(
            'image/avif', 'image/webp', 'image/svg+xml', 
            'image/jpeg', 'image/jpg', 'image/png',
            'image/heic', 'image/heif', 'image/tiff'
        );
        
        if (!in_array($mime_type, $eligible_types)) {
            $this->log('nofb Migrator Error: Unsupported file type for migration: ' . $mime_type . ' for ' . basename($file_path));
            return false;
        }
        
        // Check file size using cached configuration
        $config = $this->get_config();
        $file_size_kb = filesize($file_path) / 1024;
        
        if ($file_size_kb > $config['max_file_size']) {
            $this->log('nofb Migrator Error: File too large for migration: ' . basename($file_path) . ' (' . round($file_size_kb) . ' KB)');
            return false;
        }
        
        // File is eligible for migration
        return true;
    }
    
    /**
     * Clear caches when files are migrated or modified
     * 
     * @param int $attachment_id The attachment ID
     * @param string|null $file_path Optional file path
     * @return void
     */
    private function clear_caches($attachment_id, $file_path = null) {
        if ($file_path) {
            // Clear file-specific caches
            wp_cache_delete($this->get_cache_key('attachment_id', $file_path), 'nofb_migrator');
            
            // Clear basename-specific cache
            $basename = basename($file_path);
            $like_path = '%/' . $basename;
            wp_cache_delete($this->get_cache_key('file_meta', $like_path), 'nofb_migrator');
            
            // Clear path-related caches
            $upload_dir = wp_upload_dir();
            $relative_path = str_replace($upload_dir['basedir'] . '/', '', $file_path);
            wp_cache_delete($this->get_cache_key('exact_path', $relative_path), 'nofb_migrator');
            wp_cache_delete($this->get_cache_key('local_path', $file_path), 'nofb_migrator');
        }
        
        // Clear eligibility caches
        wp_cache_delete('nofb_optimization_attachments', 'nofb_eligibility');
        wp_cache_delete('nofb_migration_attachments', 'nofb_eligibility');
        wp_cache_delete('nofb_optimized_ids', 'nofb_eligibility');
        wp_cache_delete('nofb_migrated_ids', 'nofb_eligibility');
    }
    
    /**
     * Mark file as migrated in database
     */
    private function mark_as_migrated($attachment_id, $file_path, $relative_path) {
        // Construct Bunny CDN URL
        if (!empty($this->custom_hostname)) {
            $bunny_url = 'https://' . $this->custom_hostname . '/' . $relative_path;
        } else {
            // Fallback to default bunnycdn.com URL if custom hostname is not set
            $bunny_url = 'https://' . $this->storage_zone . '.b-cdn.net/' . $relative_path;
        }
        
        // Get the original WordPress URL for this attachment
        $upload_dir = wp_upload_dir();
        $original_url = $upload_dir['baseurl'] . '/' . $relative_path;
        
        // Generate version hash for cache busting using cached configuration
        $config = $this->get_config();
        if ($config['file_versioning']) {
            $version = substr(md5_file($file_path), 0, 3);
            update_post_meta($attachment_id, '_nofb_version', $version);
        }
        
        // Update attachment metadata
        update_post_meta($attachment_id, '_nofb_migrated', true);
        update_post_meta($attachment_id, '_nofb_bunny_url', $bunny_url);
        update_post_meta($attachment_id, '_nofb_migration_date', current_time('mysql'));
        update_post_meta($attachment_id, '_nofb_original_url', $original_url);
        
        // Store all sizes for proper URL replacement
        $metadata = wp_get_attachment_metadata($attachment_id);
        $size_urls = array();
        
        if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $size_data) {
                if (isset($size_data['file'])) {
                    $size_relative_path = dirname($relative_path) . '/' . $size_data['file'];
                    $original_size_url = $upload_dir['baseurl'] . '/' . $size_relative_path;
                    
                    if (!empty($this->custom_hostname)) {
                        $bunny_size_url = 'https://' . $this->custom_hostname . '/' . $size_relative_path;
                    } else {
                        $bunny_size_url = 'https://' . $this->storage_zone . '.b-cdn.net/' . $size_relative_path;
                    }
                    
                    $size_urls[$size] = array(
                        'original' => $original_size_url,
                        'bunny' => $bunny_size_url
                    );
                }
            }
        }
        
        // Store all size URLs for comprehensive replacements
        if (!empty($size_urls)) {
            update_post_meta($attachment_id, '_nofb_size_urls', $size_urls);
        }
        
        // Update URLs in content
        $this->update_content_urls($attachment_id, $original_url, $bunny_url, $size_urls);
        
        // Clear caches
        $this->clear_caches($attachment_id, $file_path);
        
        $this->log('nofb Migrator: Successfully marked attachment ' . $attachment_id . ' as migrated to Bunny CDN');
        return true;
    }
    
    /**
     * Update URLs in content after migration
     * 
     * @param int $attachment_id Attachment ID
     * @param string $original_url Original local URL
     * @param string $bunny_url New Bunny CDN URL
     * @param array $size_urls Array of size URLs to replace
     * @return void
     */
    private function update_content_urls($attachment_id, $original_url, $bunny_url, $size_urls = array()) {
        global $wpdb;
        
        // Track URL replacements to make
        $url_replacements = array(
            $original_url => $bunny_url,
        );
        
        // Add size-specific URL replacements
        foreach ($size_urls as $size => $urls) {
            if (!empty($urls['original']) && !empty($urls['bunny'])) {
                $url_replacements[$urls['original']] = $urls['bunny'];
            }
        }
        
        // First, update post content - find posts that might contain any of our URLs
        $like_patterns = array();
        foreach ($url_replacements as $old_url => $new_url) {
            $like_patterns[] = '%' . $wpdb->esc_like($old_url) . '%';
        }
        
        if (empty($like_patterns)) {
            return;
        }
        
        // Build the WHERE clause parts separately for better linter support
        $conditions = array();
        $query_params = array('publish');
        
        foreach ($like_patterns as $pattern) {
            $conditions[] = 'post_content LIKE %s';
            $query_params[] = $pattern;
        }
        
        $where_conditions = implode(' OR ', $conditions);
        
        // Cache key for this specific query
        $cache_key = 'nofb_content_urls_' . md5(implode('', $like_patterns));
        $posts = wp_cache_get($cache_key, 'nofb_migrator');
        
        if (false === $posts) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Using custom caching solution
            $posts = $wpdb->get_results(
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic WHERE conditions built with proper escaping
                $wpdb->prepare(
                    "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status = %s AND ($where_conditions)",
                    $query_params
                )
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            );
            
            // Cache results for a short time (15 minutes)
            wp_cache_set($cache_key, $posts, 'nofb_migrator', 15 * MINUTE_IN_SECONDS);
        }
        
        $updated_count = 0;
        
        foreach ($posts as $post) {
            $updated_content = $post->post_content;
            $content_changed = false;
            
            // Replace each URL
            foreach ($url_replacements as $old_url => $new_url) {
                $temp_content = str_replace($old_url, $new_url, $updated_content);
                if ($temp_content !== $updated_content) {
                    $updated_content = $temp_content;
                    $content_changed = true;
                }
            }
            
            // Only update if changes were made
            if ($content_changed) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Necessary update with immediate impact
                $wpdb->update(
                    $wpdb->posts,
                    array('post_content' => $updated_content),
                    array('ID' => $post->ID),
                    array('%s'),
                    array('%d')
                );
                clean_post_cache($post->ID);
                $updated_count++;
            }
        }
        
        // Next, update post meta that might contain URLs
        foreach ($url_replacements as $old_url => $new_url) {
            // Find meta values that might contain this URL
            // Cache key for this specific meta query
            $meta_cache_key = 'nofb_meta_urls_' . md5($old_url);
            $meta_entries = wp_cache_get($meta_cache_key, 'nofb_migrator');
            
            if (false === $meta_entries) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Using custom caching solution
                $meta_entries = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT post_id, meta_key, meta_value 
                         FROM {$wpdb->postmeta} 
                         WHERE meta_key NOT LIKE %s 
                         AND meta_value LIKE %s",
                        '_nofb\\_%',  // Properly escaped wildcard for NOT LIKE
                        '%' . $wpdb->esc_like($old_url) . '%'
                    )
                );
                
                // Cache results for a short time (15 minutes)
                wp_cache_set($meta_cache_key, $meta_entries, 'nofb_migrator', 15 * MINUTE_IN_SECONDS);
            }
            
            foreach ($meta_entries as $meta) {
                // Skip our own meta
                if (strpos($meta->meta_key, '_nofb_') === 0 || strpos($meta->meta_key, '_wp_attachment_metadata') === 0) {
                    continue;
                }
                
                if (is_serialized($meta->meta_value)) {
                    // Handle serialized data
                    $unserialized = maybe_unserialize($meta->meta_value);
                    
                    if (is_array($unserialized) || is_object($unserialized)) {
                        // Convert to JSON and back to handle deep replacements
                        $json = wp_json_encode($unserialized);
                        if ($json && strpos($json, $old_url) !== false) {
                            $updated_json = str_replace($old_url, $new_url, $json);
                            $updated_unserialized = json_decode($updated_json, true);
                            
                            if ($updated_unserialized) {
                                update_post_meta($meta->post_id, $meta->meta_key, $updated_unserialized);
                            }
                        }
                    }
                } else if (is_string($meta->meta_value) && strpos($meta->meta_value, $old_url) !== false) {
                    // Handle string values
                    $updated_value = str_replace($old_url, $new_url, $meta->meta_value);
                    update_post_meta($meta->post_id, $meta->meta_key, $updated_value);
                }
            }
        }
        
        // Also update any Elementor, Brizy, or Divi data specifically
        $this->update_page_builder_urls($url_replacements);
        
        $this->log('nofb Migrator: Updated URLs in ' . $updated_count . ' posts after migration');
    }
    
    /**
     * Update URLs in page builder content
     * 
     * @param array $url_replacements Array of URL replacements
     * @return void
     */
    private function update_page_builder_urls($url_replacements) {
        global $wpdb;
        
        // Common page builder meta keys
        $page_builder_keys = array(
            '_elementor_data',          // Elementor
            'brizy_post_data',          // Brizy
            '_et_pb_post_settings',     // Divi
            '_et_pb_use_builder',       // Divi
            '_fl_builder_data',         // Beaver Builder
        );
        
        // For each page builder, get posts that use it and update their content
        foreach ($page_builder_keys as $meta_key) {
            // Create a cache key for this meta key query
            $cache_key = 'nofb_pb_posts_' . md5($meta_key);
            $builder_posts = wp_cache_get($cache_key, 'nofb_migrator');
            
            if (false === $builder_posts) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Using custom caching solution
                $builder_posts = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
                        $meta_key
                    )
                );
                
                // Cache results for 1 hour
                wp_cache_set($cache_key, $builder_posts, 'nofb_migrator', HOUR_IN_SECONDS);
            }
            
            foreach ($builder_posts as $post) {
                $meta_value = $post->meta_value;
                $modified = false;
                
                // Skip if empty
                if (empty($meta_value)) {
                    continue;
                }
                
                // Process data based on format
                if (is_serialized($meta_value)) {
                    // Handle serialized data
                    $unserialized = maybe_unserialize($meta_value);
                    
                    if (is_array($unserialized) || is_object($unserialized)) {
                        // Convert to JSON for string replacement
                        $json = wp_json_encode($unserialized);
                        if ($json) {
                            $updated_json = $json;
                            
                            // Replace each URL
                            foreach ($url_replacements as $old_url => $new_url) {
                                $updated_json = str_replace($old_url, $new_url, $updated_json);
                            }
                            
                            if ($updated_json !== $json) {
                                $updated_data = json_decode($updated_json, true);
                                if ($updated_data) {
                                    update_post_meta($post->post_id, $meta_key, $updated_data);
                                    $modified = true;
                                }
                            }
                        }
                    }
                } else if (is_string($meta_value)) {
                    // String data (like JSON or base64)
                    $updated_value = $meta_value;
                    
                    // Replace each URL
                    foreach ($url_replacements as $old_url => $new_url) {
                        $updated_value = str_replace($old_url, $new_url, $updated_value);
                    }
                    
                    if ($updated_value !== $meta_value) {
                        update_post_meta($post->post_id, $meta_key, $updated_value);
                        $modified = true;
                    }
                }
                
                if ($modified) {
                    clean_post_cache($post->post_id);
                }
            }
        }
    }
    
    /**
     * Delete local file after successful migration
     */
    private function delete_local_file($file_path, $attachment_id) {
        // Delete the main file
        if (file_exists($file_path)) {
            wp_delete_file($file_path);
            
            // Also delete intermediate sizes
            $metadata = wp_get_attachment_metadata($attachment_id);
            $base_dir = dirname($file_path);
            $original_filename = basename($file_path);
            $filename_no_ext = pathinfo($original_filename, PATHINFO_FILENAME);
            $file_ext = pathinfo($original_filename, PATHINFO_EXTENSION);
            
            // First delete all sizes in metadata
            if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size => $size_data) {
                    $size_file = $base_dir . '/' . $size_data['file'];
                    if (file_exists($size_file)) {
                        wp_delete_file($size_file);
                        $this->log('nofb Migrator: Deleted metadata size file: ' . basename($size_file));
                    }
                }
            }
            
            // Also check for ALL image variations not in metadata using optimized pattern matching
            $related_files = $this->collect_all_related_files($file_path);
            
            foreach ($related_files as $file) {
                $size_file = $base_dir . '/' . $file;
                if (file_exists($size_file)) {
                    wp_delete_file($size_file);
                    $this->log('nofb Migrator: Deleted variation file: ' . $file);
                }
            }
            
            // Mark as deleted locally
            update_post_meta($attachment_id, '_nofb_local_deleted', true);
            $this->log('nofb Migrator: Completed local file deletion for attachment ' . $attachment_id);
        }
    }
    
    /**
     * Delete file from Bunny storage (for sync purposes)
     */
    public function delete_from_bunny($attachment_id) {
        // Check if file was migrated
        $migrated = get_post_meta($attachment_id, '_nofb_migrated', true);
        $bunny_url = get_post_meta($attachment_id, '_nofb_bunny_url', true);
        
        if (!$migrated || !$bunny_url) {
            return false;
        }
        
        // Extract relative path from Bunny URL
        $relative_path = str_replace('https://' . $this->custom_hostname . '/', '', $bunny_url);
        $delete_url = $this->storage_endpoint . '/' . $this->storage_zone . '/' . $relative_path;
        
        $response = wp_remote_request(
            $delete_url,
            array(
                'method' => 'DELETE',
                'headers' => array(
                    'AccessKey' => $this->bunny_api_key
                ),
                'timeout' => 30,
                'sslverify' => true
            )
        );
        
        if (is_wp_error($response)) {
            $this->log('nofb Delete Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200 || $response_code === 404) {
            // Success or file already deleted
            delete_post_meta($attachment_id, '_nofb_migrated');
            delete_post_meta($attachment_id, '_nofb_bunny_url');
            delete_post_meta($attachment_id, '_nofb_migration_date');
            delete_post_meta($attachment_id, '_nofb_version');
            
            // Clear caches
            $this->clear_caches($attachment_id);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Reset metadata and delete local file
     * @param int $attachment_id
     */
    private function reset_metadata($attachment_id) {
        // Use direct update_post_meta instead of $wpdb->update for better performance
        update_post_meta($attachment_id, '_nofb_migrated', '0');
        
        // Clear other caches related to this attachment
        $this->clear_caches($attachment_id);
    }

    /**
     * Get attachment ID by file path
     */
    private function get_attachment_id_by_path($file_path) {
        global $wpdb;
        
        // Generate cache key based on file path
        $cache_key = $this->get_cache_key('attachment_id', $file_path);
        $attachment_id = wp_cache_get($cache_key, 'nofb_migrator');
        
        if ($attachment_id !== false) {
            return $attachment_id ? intval($attachment_id) : 0;
        }
        
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['basedir'] . '/', '', $file_path);
        
        // Ensure the path has forward slashes
        $relative_path = str_replace('\\', '/', $relative_path);
        
        // First, check for exact match
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Using custom caching solution below
        $attachment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = '_wp_attached_file' 
                AND meta_value = %s",
                $relative_path
            )
        );
        
        if ($attachment) {
            $result = intval($attachment->post_id);
            
            // Cache the result for 1 hour
            wp_cache_set($cache_key, $result, 'nofb_migrator', HOUR_IN_SECONDS);
            
            return $result;
        }
        
        // If exact match fails, try a LIKE query
        // Extract base filename for better matching
        $path_parts = pathinfo($relative_path);
        $filename = $path_parts['basename'];
        
        // Cache key for LIKE query
        $like_cache_key = $this->get_cache_key('attachment_like', $filename);
        $like_results = wp_cache_get($like_cache_key, 'nofb_migrator');
        
        if (false === $like_results) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Using custom caching solution below
            $like_results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT post_id, meta_value FROM {$wpdb->postmeta} 
                    WHERE meta_key = '_wp_attached_file' 
                    AND (meta_value LIKE %s OR meta_value = %s)",
                    '%/' . $filename,
                    $filename
                )
            );
            
            // Cache the results for 1 hour
            wp_cache_set($like_cache_key, $like_results, 'nofb_migrator', HOUR_IN_SECONDS);
        }
        
        if (!empty($like_results)) {
            foreach ($like_results as $result) {
                // Store each result in its own cache entry
                $result_path = $upload_dir['basedir'] . '/' . $result->meta_value;
                $result_key = $this->get_cache_key('attachment_id', $result_path);
                wp_cache_set($result_key, intval($result->post_id), 'nofb_migrator', HOUR_IN_SECONDS);
                
                // Check for exact path match
                if ($result->meta_value === $relative_path) {
                    $final_id = intval($result->post_id);
                    wp_cache_set($cache_key, $final_id, 'nofb_migrator', HOUR_IN_SECONDS);
                    return $final_id;
                }
                
                // Check for filename match
                $result_filename = basename($result->meta_value);
                if ($result_filename === $filename) {
                    $final_id = intval($result->post_id);
                    wp_cache_set($cache_key, $final_id, 'nofb_migrator', HOUR_IN_SECONDS);
                    return $final_id;
                }
            }
        }
        
        // No match found - cache a negative result to avoid repeated lookups
        wp_cache_set($cache_key, 0, 'nofb_migrator', 5 * MINUTE_IN_SECONDS);
        
        return 0;
    }
    
    /**
     * Test Bunny.net API connection
     */
    public function test_connection() {
        // Check if API key and storage zone are set
        if (empty($this->bunny_api_key) || empty($this->storage_zone)) {
            return array(
                'status' => 'error',
                'message' => __('Bunny.net API credentials are not configured.', 'nexoffload-for-bunny')
            );
        }
        
        // Send test request
        $url = $this->storage_endpoint . '/' . $this->storage_zone;
        
        $response = wp_remote_get(
            $url,
            array(
                'headers' => array(
                    'AccessKey' => $this->bunny_api_key
                ),
                'timeout' => 15,
                'sslverify' => true
            )
        );
        
        if (is_wp_error($response)) {
            return array(
                'status' => 'error',
                'message' => $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200) {
            return array(
                'status' => 'success',
                'message' => __('Bunny.net API connection successful.', 'nexoffload-for-bunny')
            );
        } elseif ($response_code === 401) {
            return array(
                'status' => 'error',
                'message' => __('Bunny.net API key is invalid.', 'nexoffload-for-bunny')
            );
        } elseif ($response_code === 404) {
            return array(
                'status' => 'error',
                'message' => __('Bunny.net storage zone not found.', 'nexoffload-for-bunny')
            );
        } else {
            return array(
                'status' => 'error',
                /* translators: %s: HTTP status code from Bunny.net API */
                'message' => sprintf(__('Bunny.net API returned unexpected status: %s', 'nexoffload-for-bunny'), $response_code)
            );
        }
    }
    
    /**
     * Force migrate a file regardless of eligibility checks
     * This is useful for files that were incorrectly marked as migrated or CDN URLs
     */
    public function force_migrate_file($file_path) {
        if (!file_exists($file_path)) {
            $this->log('nofb Migrator Error: File does not exist: ' . $file_path);
            return false;
        }
        
        $attachment_id = $this->get_attachment_id_by_path($file_path);
        if (!$attachment_id) {
            $this->log('nofb Migrator Error: Could not determine attachment ID for ' . $file_path);
            return false;
        }
        
        // Clear any existing migration metadata
        delete_post_meta($attachment_id, '_nofb_migrated');
        delete_post_meta($attachment_id, '_nofb_bunny_url');
        delete_post_meta($attachment_id, '_nofb_migration_date');
        
        // Reset attachment URL if it's pointing to CDN
        $attachment_url = wp_get_attachment_url($attachment_id);
        if (strpos($attachment_url, $this->custom_hostname) !== false || 
            strpos($attachment_url, 'bunnycdn.com') !== false) {
            update_post_meta($attachment_id, '_wp_attachment_url', '');
        }
        
        $this->log('nofb Migrator: Force migrating file: ' . basename($file_path));
        
        // Get file info
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['basedir'] . '/', '', $file_path);
        
        $file_size = filesize($file_path);
        $mime_type = wp_check_filetype($file_path)['type'];
        
        $this->log('nofb Migrator: File size: ' . round($file_size / 1024, 2) . ' KB');
        $this->log('nofb Migrator: File MIME type: ' . $mime_type);
        
        // Upload using optimized method
        $response = $this->upload_to_bunny($file_path, $relative_path, 300);
        
        if ($response) {
            // Success - now migrate all image sizes for this attachment
            $this->migrate_image_sizes($attachment_id, $file_path);
            
            // Update database
            $this->mark_as_migrated($attachment_id, $file_path, $relative_path);
            
            // Always delete local file after successful migration
            $this->delete_local_file($file_path, $attachment_id);
            
            $this->log('nofb Migrator: Successfully force migrated file: ' . basename($file_path));
            return true;
        } else {
            $this->log('nofb Migrator Error: Failed to force migrate file');
            return false;
        }
    }
} 