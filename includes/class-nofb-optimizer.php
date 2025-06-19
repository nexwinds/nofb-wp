<?php
/**
 * NOFB Optimizer Class
 * Handles image optimization using the NW Optimization API
 */

if (!defined('ABSPATH')) {
    exit;
}

class NOFB_Optimizer {
    
    private $api_key;
    private $api_region;
    private $api_base_url;
    private $max_file_size_kb;
    private $custom_hostname;
    private $config_cache = null;
    
    public function __construct() {
        $this->api_key = NOFB_API_KEY;
        $this->api_region = NOFB_API_REGION;
        $this->api_base_url = $this->get_api_base_url();
        $this->max_file_size_kb = get_option('nofb_max_file_size', NOFB_DEFAULT_MAX_FILE_SIZE);
        $this->custom_hostname = BUNNY_CUSTOM_HOSTNAME;
    }
    
    /**
     * Get cached configuration settings
     * @return array Configuration array
     */
    private function get_cached_config() {
        if ($this->config_cache === null) {
            $this->config_cache = array(
                'api_key' => $this->api_key,
                'api_region' => $this->api_region,
                'api_base_url' => $this->api_base_url,
                'max_file_size_kb' => $this->max_file_size_kb,
                'custom_hostname' => $this->custom_hostname,
                'auto_migrate' => get_option('nofb_auto_migrate', false),
                'api_timeout' => apply_filters('nofb_api_timeout', 120)
            );
        }
        
        return $this->config_cache;
    }
    
    /**
     * Generate cache key for specific operation
     * @param string $operation Operation identifier
     * @param string $identifier Unique identifier for the cached data
     * @return string Cache key
     */
    private function get_cache_key($operation, $identifier = '') {
        $key = 'nofb_optimizer_' . $operation;
        if (!empty($identifier)) {
            $key .= '_' . md5($identifier);
        }
        return $key;
    }
    
    /**
     * Get cached attachment ID by file path
     * @param string $file_path File path
     * @return int|false Attachment ID or false if not found
     */
    private function get_cached_attachment_id($file_path) {
        $cache_key = $this->get_cache_key('attachment_id', $file_path);
        $attachment_id = wp_cache_get($cache_key, 'nofb_optimizer');
        
        if ($attachment_id !== false) {
            return $attachment_id ? intval($attachment_id) : 0;
        }
        
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['basedir'] . '/', '', $file_path);
        
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for performance with proper caching
        $attachment_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = '_wp_attached_file' 
                AND meta_value = %s 
                LIMIT 1",
                $relative_path
            )
        );
        
        wp_cache_set($cache_key, $attachment_id, 'nofb_optimizer', HOUR_IN_SECONDS);
        return $attachment_id ? intval($attachment_id) : 0;
    }
    
    /**
     * Batch resolve attachment IDs from file paths
     * @param array $file_paths Array of file paths
     * @return array Mapping of file paths to attachment IDs
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
            $cache_key = $this->get_cache_key('attachment_id', $file_path);
            $attachment_id = wp_cache_get($cache_key, 'nofb_optimizer');
            
            if ($attachment_id !== false) {
                $attachment_map[$file_path] = $attachment_id ? intval($attachment_id) : 0;
            } else {
                $relative_path = str_replace($upload_dir['basedir'] . '/', '', $file_path);
                $uncached_paths[$file_path] = $relative_path;
            }
        }
        
        // Batch query for uncached paths
        if (!empty($uncached_paths)) {
            global $wpdb;
            $relative_paths = array_values($uncached_paths);
            $placeholders = implode(',', array_fill(0, count($relative_paths), '%s'));
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct DB query necessary for bulk path-to-attachment mapping with custom caching solution implemented below
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
            /* phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- $query is properly prepared above; Required for bulk path mapping with custom caching */
            $results = $wpdb->get_results($query);
            
            // Map results and cache
            $db_map = array();
            foreach ($results as $result) {
                $db_map[$result->meta_value] = intval($result->post_id);
            }
            
            foreach ($uncached_paths as $file_path => $relative_path) {
                $attachment_id = isset($db_map[$relative_path]) ? $db_map[$relative_path] : 0;
                $attachment_map[$file_path] = $attachment_id;
                
                // Cache result
                $cache_key = $this->get_cache_key('attachment_id', $file_path);
                wp_cache_set($cache_key, $attachment_id, 'nofb_optimizer', HOUR_IN_SECONDS);
            }
        }
        
        return $attachment_map;
    }
    
    /**
     * Get file information batch (size, mime type, existence)
     * @param array $file_paths Array of file paths
     * @return array File information mapping
     */
    private function get_file_info_batch($file_paths) {
        $file_info = array();
        
        foreach ($file_paths as $file_path) {
            $cache_key = $this->get_cache_key('file_info', $file_path);
            $info = wp_cache_get($cache_key, 'nofb_optimizer');
            
            if ($info === false) {
                $info = array(
                    'exists' => file_exists($file_path),
                    'readable' => is_readable($file_path),
                    'size' => file_exists($file_path) ? filesize($file_path) : 0,
                    'mime_type' => wp_check_filetype($file_path)['type']
                );
                
                wp_cache_set($cache_key, $info, 'nofb_optimizer', 30 * MINUTE_IN_SECONDS);
            }
            
            $file_info[$file_path] = $info;
        }
        
        return $file_info;
    }
    
    /**
     * Get format-specific validation rules
     * @param string $mime_type MIME type
     * @return array Validation rules
     */
    private function get_format_rules($mime_type) {
        static $rules_cache = null;
        
        if ($rules_cache === null) {
            $config = $this->get_cached_config();
            
            $rules_cache = array(
                'always_eligible' => array(
                    'image/jpeg', 'image/jpg', 'image/png', 
                    'image/heic', 'image/heif', 'image/tiff'
                ),
                'size_restricted' => array(
                    'image/webp', 'image/avif'
                ),
                'supported_types' => array(
                    'image/jpeg', 'image/jpg', 'image/png', 'image/webp',
                    'image/avif', 'image/heic', 'image/heif', 'image/tiff'
                ),
                'max_size_kb' => $config['max_file_size_kb'],
                'max_total_size_kb' => 10240 // 10MB
            );
        }
        
        return $rules_cache;
    }
    
    /**
     * Validate file eligibility for optimization
     * @param string $file_path File path
     * @param array $file_info File information from get_file_info_batch
     * @return array Validation result with eligible flag and reason
     */
    private function validate_file_eligibility($file_path, $file_info) {
        // Check file existence and readability
        if (!$file_info['exists']) {
            return array('eligible' => false, 'reason' => 'File does not exist');
        }
        
        if (!$file_info['readable']) {
            return array('eligible' => false, 'reason' => 'File is not readable');
        }
        
        // Check file format
        $rules = $this->get_format_rules($file_info['mime_type']);
        if (!in_array($file_info['mime_type'], $rules['supported_types'])) {
            return array('eligible' => false, 'reason' => 'Unsupported file type: ' . $file_info['mime_type']);
        }
        
        // Check if file is on CDN
        $attachment_id = $this->get_cached_attachment_id($file_path);
        if ($attachment_id) {
            $attachment_url = wp_get_attachment_url($attachment_id);
            $config = $this->get_cached_config();
            
            if (strpos($attachment_url, $config['custom_hostname']) !== false || 
                strpos($attachment_url, 'bunnycdn.com') !== false) {
                return array('eligible' => false, 'reason' => 'File is already on CDN');
            }
            
            // Check if already optimized
            if (get_post_meta($attachment_id, '_nofb_optimized', true)) {
                return array('eligible' => false, 'reason' => 'File already optimized');
            }
        }
        
        // Size validation
        $file_size_kb = $file_info['size'] / 1024;
        
        if (in_array($file_info['mime_type'], $rules['always_eligible'])) {
            return array('eligible' => true, 'reason' => 'Always eligible format');
        }
        
        if (in_array($file_info['mime_type'], $rules['size_restricted'])) {
            if ($file_size_kb <= $rules['max_size_kb'] || $file_size_kb > $rules['max_total_size_kb']) {
                return array('eligible' => false, 'reason' => 'File size not eligible: ' . round($file_size_kb) . 'KB');
            }
        }
        
        return array('eligible' => true, 'reason' => 'File eligible for optimization');
    }
    
    /**
     * Build API request payload
     * @param array $file_data Prepared file data
     * @return array API request payload
     */
    private function build_api_request($file_data) {
        $config = $this->get_cached_config();
        
        return array(
            'images' => $file_data,
            'maxSizeKb' => $config['max_file_size_kb'],
            'supportsAVIF' => true,
            'supportsHEIF' => true
        );
    }
    
    /**
     * Handle API response with improved error detection
     */
    private function handle_api_response($response) {
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log('API Error: ' . $error_message, 'error');
            
            // Handle timeout specifically
            if (strpos($error_message, 'cURL error 28') !== false || 
                strpos($error_message, 'timed out') !== false) {
                $this->log('Timeout occurred during API request', 'error');
                // Already logged via this->log above
            }
            
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        // Log response code for monitoring
        $this->log('API Response Code: ' . $response_code);
        
        // Check for empty response body
        if (empty($response_body)) {
            $this->log('API returned empty response body', 'error');
            // Already logged via this->log above
            return false;
        }
        
        // Check for truncated or malformed JSON
        $trimmed_body = trim($response_body);
        if (empty($trimmed_body)) {
            $this->log('API returned only whitespace in response body', 'error');
            return false;
        }
        
        $last_char = substr($trimmed_body, -1);
        if ($last_char !== '}' && $last_char !== ']') {
            $this->log('API response appears to be truncated (does not end with } or ])', 'error');
            $this->log('Response end: ...' . substr($response_body, -100), 'error');
            return false;
        }
        
        // Check for balanced braces
        if (substr_count($trimmed_body, '{') !== substr_count($trimmed_body, '}')) {
            $this->log('API response has unbalanced braces', 'error');
            $this->log('API returned malformed JSON with imbalanced braces', 'error');
            return false; 
        }
        
        // Parse JSON response with error handling
        $json_data = json_decode($response_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('API returned invalid JSON: ' . json_last_error_msg(), 'error');
            $this->log('JSON parse error. Raw response (first 500 chars): ' . substr($response_body, 0, 500), 'error');
            return false;
        }
        
        // Handle different response codes
        switch ($response_code) {
            case 200:
                // Validate success indicator
                if (isset($json_data['success']) && $json_data['success'] !== true) {
                    $this->log('API returned success: false', 'error');
                    return false;
                }
                
                // Handle single result (non-batch response)
                if (isset($json_data['batch']) && $json_data['batch'] === false && isset($json_data['results'])) {
                    // Convert single result object to an array with one item
                    if (is_array($json_data['results']) && !isset($json_data['results'][0])) {
                        $this->log('Converting single result object to array', 'info');
                        return array($json_data['results']);
                    }
                }
                
                // Validate response structure - handle both array and object formats
                if (isset($json_data['results'])) {
                    // Log API usage for monitoring
                    if (isset($json_data['creditsUsed'])) {
                        $this->log('API credits used: ' . $json_data['creditsUsed'] . ', Images processed: ' . 
                            (isset($json_data['imagesProcessed']) ? $json_data['imagesProcessed'] : 'N/A'));
                    }
                    
                    // Handle case where results is a single object instead of an array
                    if (is_array($json_data['results']) && !isset($json_data['results'][0]) && isset($json_data['results']['success'])) {
                        $this->log('API returned a single result object, converting to array format', 'info');
                        return array($json_data['results']);
                    }
                    
                    // Standard array format
                    if (is_array($json_data['results'])) {
                        // Additional validation to ensure results is populated correctly
                        if (empty($json_data['results'])) {
                            $this->log('API returned empty results array', 'warning');
                            // Return empty array instead of false to indicate successful request with no results
                            return array();
                        }
                        
                        return $json_data['results'];
                    }
                }
                
                // Special case: if processed is 0, return empty array
                if (isset($json_data['processed']) && $json_data['processed'] === 0) {
                    $this->log('API processed 0 images', 'warning');
                    return array();
                }
                
                // Response code is 200 but missing expected data
                $this->log('API returned 200 but missing or invalid "results" field: ' . substr(wp_json_encode($json_data), 0, 500), 'error');
                return false;
                
            case 401:
                $this->log('API Error: Invalid API key', 'error');
                break;
                
            case 402:
                $this->log('API Error: Insufficient credits', 'error');
                break;
                
            case 429:
                $this->log('API Error: Rate limit exceeded', 'error');
                break;
                
            default:
                $error_msg = isset($json_data['message']) ? $json_data['message'] : 'Unknown error';
                $this->log('API Error: ' . $response_code . ' - ' . $error_msg, 'error');
                if (isset($json_data['details'])) {
                    $this->log('Error details: ' . wp_json_encode($json_data['details']), 'error');
                }
        }
        
        return false;
    }
    
    /**
     * Prepare file data for API transmission
     * @param array $file_paths Array of file paths
     * @return array|false Prepared file data or false on error
     */
    private function prepare_file_data_batch($file_paths) {
        $file_data = array();
        $failed_files = array();
        
        foreach ($file_paths as $file_path) {
            $data_url = $this->prepare_image_data($file_path);
            if (!$data_url) {
                $failed_files[] = basename($file_path);
                continue;
            }
            
            $file_data[] = array(
                'file' => basename($file_path),
                'imageData' => $data_url
            );
        }
        
        if (!empty($failed_files)) {
            $this->log('Failed to prepare data for files: ' . implode(', ', $failed_files));
        }
        
        return empty($file_data) ? false : $file_data;
    }
    
    /**
     * Update optimization metadata for multiple attachments
     * @param array $updates Array of update data keyed by file path
     * @return int Number of successful updates
     */
    private function update_optimization_metadata_batch($updates) {
        if (empty($updates)) {
            return 0;
        }
        
        $success_count = 0;
        
        foreach ($updates as $file_path => $data) {
            $attachment_id = $this->get_cached_attachment_id($file_path);
            if (!$attachment_id) {
                continue;
            }
            
            // Update basic optimization metadata
            update_post_meta($attachment_id, '_nofb_optimized', true);
            update_post_meta($attachment_id, '_nofb_optimization_date', current_time('mysql'));
            update_post_meta($attachment_id, '_nofb_file_size', filesize($file_path));
            update_post_meta($attachment_id, '_nofb_local_path', $file_path);
            
            // Update detailed optimization data if available
            if (isset($data['optimization_data'])) {
                update_post_meta($attachment_id, '_nofb_optimization_data', $data['optimization_data']);
            }
            
            $success_count++;
        }
        
        return $success_count;
    }
    
    /**
     * Log a message with consistent formatting
     * @param string $message Message to log
     * @param string $level Log level (info, warning, error)
     */
    private function log($message, $level = 'info') {
        // Generate timestamp with microseconds
        $timestamp = '[' . gmdate('H:i:s') . '.' . sprintf('%03d', round(microtime(true) % 1 * 1000)) . ']';
        
        // Format the message with context information
        $caller = 'unknown';
        
        // Only get the backtrace if debugging is enabled
        if ($this->is_debug_enabled()) {
            // Use debug_backtrace only when debugging is enabled
            $context = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
            $caller = isset($context[1]['function']) ? $context[1]['function'] : 'unknown';
            
            // Format and log the message
            $formatted_message = $timestamp . ' nofb Optimizer [' . strtoupper($level) . '] [' . $caller . ']: ' . esc_html($message);
            $this->write_to_log($formatted_message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
        
        // Always log critical errors to a separate log if specifically configured
        if ($level === 'error' && defined('nofb_CRITICAL_LOGGING') && nofb_CRITICAL_LOGGING) {
            $this->write_to_log('nofb Error: ' . esc_html($message)); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }
    
    /**
     * Check if debug mode is enabled
     * @return bool Whether debug mode is enabled
     */
    private function is_debug_enabled() {
        return (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG);
    }
    
    /**
     * Write message to log
     * @param string $message Message to log
     */
    private function write_to_log($message) {
        if ($this->is_debug_enabled()) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log($message);
        }
    }
    
    /**
     * Get API base URL based on region
     */
    private function get_api_base_url() {
        if ($this->api_region === 'eu' || $this->api_region === 'me') {
            return 'https://api-eu.nofb.nexwinds.com';
        }
        return 'https://api-us.nofb.nexwinds.com';
    }
    
    /**
     * Optimize a batch of images (up to 5)
     */
    public function optimize_batch($file_paths) {
        $this->log('optimize_batch called with ' . (is_array($file_paths) ? count($file_paths) : 0) . ' files');
        
        if (empty($file_paths) || !is_array($file_paths)) {
            $this->log('No files to optimize or invalid input', 'error');
            return false;
        }
        
        // Initialize and validate API credentials
        $config = $this->get_cached_config();
        if (empty($config['api_key']) || empty($config['api_base_url'])) {
            $this->log('API credentials not configured', 'error');
            return false;
        }
        
        // Batch validate files
        $file_info = $this->get_file_info_batch($file_paths);
        $valid_files = $this->filter_eligible_files($file_paths, $file_info);
        
        if (empty($valid_files)) {
            $this->log('No eligible files found for optimization');
            return 0;
        }
        
        $this->log('Preparing to optimize ' . count($valid_files) . ' files');
        
        // Prepare file data for API
        $file_data = $this->prepare_file_data_batch($valid_files);
        if (!$file_data) {
            $this->log('Failed to prepare file data for optimization', 'error');
            return 0;
        }
        
        // Send API request
        $results = $this->send_optimization_request($file_data);
        if (!$results) {
            return false;
        }
        
        // Process results
        return $this->process_optimization_results($valid_files, $results);
    }
    
    /**
     * Filter eligible files from batch
     * @param array $file_paths File paths to check
     * @param array $file_info File information from get_file_info_batch
     * @return array Array of eligible file paths
     */
    private function filter_eligible_files($file_paths, $file_info) {
        $valid_files = array();
        
        foreach ($file_paths as $file_path) {
            $info = $file_info[$file_path] ?? array();
            $validation = $this->validate_file_eligibility($file_path, $info);
            
            if ($validation['eligible']) {
                $valid_files[] = $file_path;
            } else {
                $this->log('File not eligible: ' . basename($file_path) . ' - ' . $validation['reason']);
            }
        }
        
        return $valid_files;
    }
    
    /**
     * Send optimization request to API
     * @param array $file_data Prepared file data
     * @return array|false API results or false on error
     */
    private function send_optimization_request($file_data) {
        $config = $this->get_cached_config();
        $request_body = $this->build_api_request($file_data);
        
        // Log request information for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $file_count = count($file_data);
            $total_size = 0;
            foreach ($file_data as $item) {
                if (isset($item['imageData'])) {
                    $total_size += strlen($item['imageData']);
                }
            }
            $this->log('Sending optimization request with ' . $file_count . ' files (total size: ' . round($total_size / 1024 / 1024, 2) . ' MB)');
        }
        
        $response = wp_remote_post(
            $config['api_base_url'] . '/v1/images/wp/optimize',
            array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'x-api-key' => $config['api_key'],
                    'Accept' => 'application/json'
                ),
                'body' => json_encode($request_body),
                'timeout' => $config['api_timeout'],
                'sslverify' => true,
                'httpversion' => '1.1',
                'blocking' => true,
            )
        );
        
        return $this->handle_api_response($response);
    }
    
    /**
     * Normalize file path to ensure consistent path format
     */
    public function normalize_file_path($file_path) {
        $upload_dir = wp_upload_dir();
        
        // Quick check if file exists as-is
        if (file_exists($file_path)) {
            return $this->extract_relative_path($file_path, $upload_dir);
        }
        
        // Try environment remapping
        $remapped_path = $this->remap_environment_path($file_path);
        if (file_exists($remapped_path)) {
            return $remapped_path;
        }
        
        // Try to find file by basename
        $basename = basename($file_path);
        if (empty($basename)) {
            $this->log('Invalid file path: ' . $file_path, 'error');
            return false;
        }
        
        $resolved_path = $this->resolve_file_by_basename($basename, $upload_dir);
        if ($resolved_path && file_exists($resolved_path)) {
            $this->log('Normalized path: ' . $resolved_path);
            return $resolved_path;
        }
        
        // Last resort - return original path
        $this->log('Could not find file after normalization: ' . $file_path, 'warning');
        return $file_path;
    }
    
    /**
     * Extract relative path from absolute path
     * @param string $file_path Absolute file path
     * @param array $upload_dir Upload directory info
     * @return string Normalized file path
     */
    private function extract_relative_path($file_path, $upload_dir) {
        $upload_base = $upload_dir['basedir'];
        
        if (strpos($file_path, $upload_base) === 0) {
            $relative_path = ltrim(str_replace($upload_base, '', $file_path), '/');
            return $upload_dir['basedir'] . '/' . $relative_path;
        }
        
        // Fallback: construct with current year/month
        $year_month = gmdate('Y/m');
        $relative_path = $year_month . '/' . basename($file_path);
        return $upload_dir['basedir'] . '/' . $relative_path;
    }
    
    /**
     * Resolve file path by basename using cached database lookup
     * @param string $basename File basename
     * @param array $upload_dir Upload directory info
     * @return string|false Resolved file path or false
     */
    private function resolve_file_by_basename($basename, $upload_dir) {
        $cache_key = $this->get_cache_key('file_meta', $basename);
        $relative_path = wp_cache_get($cache_key, 'nofb_optimizer');
        
        if ($relative_path === false) {
            $attachment_id = $this->find_attachment_by_basename($basename);
            
            if ($attachment_id) {
                $relative_path = get_post_meta($attachment_id, '_wp_attached_file', true);
                wp_cache_set($cache_key, $relative_path, 'nofb_optimizer', HOUR_IN_SECONDS);
            }
        }
        
        if (empty($relative_path)) {
            // Generate fallback path
            $year_month = gmdate('Y/m');
            $relative_path = $year_month . '/' . $basename;
        }
        
        return $upload_dir['basedir'] . '/' . $relative_path;
    }
    
    /**
     * Find attachment ID by file basename
     * @param string $basename File basename
     * @return int|false Attachment ID or false
     */
    private function find_attachment_by_basename($basename) {
        $query_cache_key = $this->get_cache_key('basename_query', $basename);
        $attachment_id = wp_cache_get($query_cache_key, 'nofb_optimizer');
        
        if ($attachment_id === false) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query for performance with proper caching
            $attachment_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} 
                    WHERE meta_key = '_wp_attached_file' 
                    AND meta_value LIKE %s 
                    LIMIT 1",
                    '%' . $wpdb->esc_like($basename)
                )
            );
            
            wp_cache_set($query_cache_key, $attachment_id, 'nofb_optimizer', HOUR_IN_SECONDS);
        }
        
        return $attachment_id ? intval($attachment_id) : false;
    }
    
    /**
     * Check if file is eligible for optimization
     */
    public function is_eligible_for_optimization($file_path) {
        // Normalize the file path
        $file_path = $this->normalize_file_path($file_path);
        
        // Get file information
        $file_info = $this->get_file_info_batch(array($file_path));
        $info = $file_info[$file_path] ?? array();
        
        // Validate eligibility
        $validation = $this->validate_file_eligibility($file_path, $info);
        
        // Handle special case for size-restricted files
        if (!$validation['eligible'] && strpos($validation['reason'], 'File size not eligible') === 0) {
            $config = $this->get_cached_config();
            if ($config['auto_migrate'] && $info['size'] / 1024 <= $config['max_file_size_kb']) {
                // Add directly to migration queue
                $queue = new NOFB_Queue('migration');
                $queue->add($file_path);
                $this->log('File added to migration queue: ' . basename($file_path));
            }
        }
        
        $this->log($validation['reason'] . ': ' . basename($file_path));
        return $validation['eligible'];
    }
    
    /**
     * Prepare image data as base64 data URL
     */
    private function prepare_image_data($file_path) {
        try {
            // Verify file exists right before reading
            if (!file_exists($file_path)) {
                $this->log('Error: File does not exist when preparing image data: ' . $file_path);
                return false;
            }
            
            if (!is_readable($file_path)) {
                $this->log('Error: File is not readable when preparing image data: ' . $file_path);
                return false;
            }
            
            // Get file size
            $file_size = filesize($file_path);
            if ($file_size <= 0) {
                $this->log('Error: File is empty or size could not be determined: ' . basename($file_path) . ' (' . $file_size . ' bytes)');
                return false;
            }
            
            // Attempt to get file contents
            $image_data = @file_get_contents($file_path);
            
            // Check if data was read successfully
            if ($image_data === false) {
                $error = error_get_last();
                $this->log('Error: Failed to read file contents: ' . basename($file_path) . ' - ' . ($error ? $error['message'] : 'Unknown error'));
                return false;
            }
            
            if (empty($image_data)) {
                $this->log('Error: Empty image data for ' . basename($file_path));
                return false;
            }
            
            $mime_type = wp_check_filetype($file_path)['type'];
            if (empty($mime_type)) {
                $this->log('Error: Could not determine MIME type for ' . basename($file_path));
                return false;
            }
            
            // Log success with file info
            $this->log('Successfully prepared image data for ' . basename($file_path) . ' (' . round($file_size/1024, 2) . ' KB, ' . $mime_type . ')');
            
            return 'data:' . $mime_type . ';base64,' . base64_encode($image_data);
        } catch (Exception $e) {
            $this->log('Exception when preparing image data: ' . $e->getMessage() . ' for file ' . basename($file_path));
            return false;
        }
    }
    
    /**
     * Process optimization results
     */
    private function process_optimization_results($file_paths, $results) {
        // Safety check for empty or invalid results
        if (empty($results) || !is_array($results)) {
            $this->log('No valid results to process', 'warning');
            return 0;
        }
        
        $success_count = 0;
        $processed_files = array();
        
        // Check if we have a special case of a single result that's not indexed
        if (isset($results['success']) && !isset($results[0])) {
            $this->log('Special case: Single non-indexed result detected, converting format');
            // Handle a single result that isn't properly formatted as an array
            if (count($file_paths) > 0) {
                $file_path = $file_paths[0];
                if ($this->handle_optimization_result($file_path, $results)) {
                    $success_count++;
                    $processed_files[] = $file_path;
                }
            } else {
                $this->log('Cannot process single result - no file paths available', 'error');
            }
            
            return $success_count;
        }
        
        // Standard case: Process array of results
        foreach ($results as $index => $result) {
            // Validate result structure
            if (!is_array($result)) {
                $this->log('Invalid result at index ' . $index . ': ' . wp_json_encode($result), 'error');
                continue;
            }
            
            if (!isset($file_paths[$index])) {
                $this->log('File path not found for result index ' . $index, 'error');
                continue;
            }
            
            $file_path = $file_paths[$index];
            
            if ($this->handle_optimization_result($file_path, $result)) {
                $success_count++;
                $processed_files[] = $file_path;
            }
        }
        
        if ($success_count === 0 && !empty($results)) {
            $this->log('Batch processing completed but no files were successfully optimized', 'warning');
        } else {
            $this->log('Successfully optimized ' . $success_count . ' files: ' . implode(', ', array_map('basename', $processed_files)));
        }
        
        return $success_count;
    }
    
    /**
     * Handle individual optimization result
     * @param string $file_path File path
     * @param array $result Optimization result
     * @return bool Success status
     */
    private function handle_optimization_result($file_path, $result) {
        // Handle successful optimization
        if (isset($result['success']) && $result['success'] === true && isset($result['data']['base64'])) {
            return $this->save_optimized_image($file_path, $result);
        }
        
        // Handle skipped files
        if (isset($result['skipped']) && $result['skipped'] === true) {
            $this->mark_as_optimized($file_path, $result);
            $this->log('Skipped optimization for ' . basename($file_path) . ' (marked as optimized)');
            return true;
        }
        
        // Handle failed optimization
        $reason = isset($result['error']) ? $result['error'] : 'Unknown reason';
        $this->log('Failed to optimize ' . basename($file_path) . '. Reason: ' . $reason, 'error');
        return false;
    }
    
    /**
     * Save optimized image data to file system
     * @param string $file_path Original file path
     * @param array $result Optimization result data
     * @return bool Success status
     */
    private function save_optimized_image($file_path, $result) {
        // Decode image data
        $image_data = $this->decode_data_url($result['data']['base64']);
        if (!$image_data) {
            $this->log('Failed to decode image data for ' . basename($file_path), 'error');
            return false;
        }
        
        // Validate image data size
        if (!$this->validate_image_data($image_data, $result)) {
            return false;
        }
        
        // Handle format conversion and file updates
        $final_file_path = $this->handle_format_conversion($file_path, $result, $image_data);
        if (!$final_file_path) {
            return false;
        }
        
        // Update database metadata
        $this->mark_as_optimized($final_file_path, $result);
        
        // Check migration eligibility
        $this->check_migration_eligibility($final_file_path);
        
        return true;
    }
    
    /**
     * Decode data URL to binary image data
     * @param string $data_url Base64 encoded data URL
     * @return string|false Binary image data or false on error
     */
    private function decode_data_url($data_url) {
        if (empty($data_url)) {
            $this->log('Empty data URL provided for decoding', 'error');
            return false;
        }

        // Handle URLs that already have the data:image prefix
        if (preg_match('/^data:image\/[a-z]+;base64,/i', $data_url)) {
            // Extract the base64 part after the comma
            $base64_data = explode(',', $data_url, 2);
            if (count($base64_data) !== 2) {
                $this->log('Invalid data URL format', 'error');
                return false;
            }
            $base64_string = trim($base64_data[1]);
        } else {
            // Assume it's just the base64 string without the prefix
            $base64_string = trim($data_url);
        }
        
        // Check for empty base64 string after trimming
        if (empty($base64_string)) {
            $this->log('Empty base64 string after parsing data URL', 'error');
            return false;
        }
        
        // Validate base64 string format
        if (!preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $base64_string)) {
            $this->log('Invalid base64 format detected', 'error');
            return false;
        }
        
        // Decode base64 data
        $binary_data = base64_decode($base64_string, true);
        
        if ($binary_data === false) {
            $this->log('Base64 decoding failed - invalid data', 'error');
            return false;
        }
        
        if (strlen($binary_data) === 0) {
            $this->log('Base64 decoding produced empty binary data', 'error');
            return false;
        }
        
        return $binary_data;
    }
    
    /**
     * Validate decoded image data
     * @param string $image_data Decoded image data
     * @param array $result Optimization result
     * @return bool Valid status
     */
    private function validate_image_data($image_data, $result) {
        if (!is_string($image_data) || empty($image_data)) {
            $this->log('Invalid or empty image data received', 'error');
            return false;
        }
        
        $data_length = strlen($image_data);
        $min_size = 50; // Default minimum size
        $format = isset($result['data']['targetFormat']) ? strtolower($result['data']['targetFormat']) : '';
        
        // Different size validation for various formats
        if ($format === 'heif' || $format === 'avif') {
            // AVIF/HEIF can be much smaller due to efficient compression
            $min_size = 20;
            
            // Check for AVIF/HEIF header markers
            $has_valid_header = false;
            
            // Check for AVIF file signature/magic bytes
            if (substr($image_data, 4, 8) === 'ftypavif' || 
                substr($image_data, 4, 8) === 'ftypavis' || 
                substr($image_data, 4, 8) === 'ftypheic' || 
                substr($image_data, 4, 8) === 'ftypheix') {
                $has_valid_header = true;
            }
            
            if (!$has_valid_header) {
                $this->log('AVIF/HEIF image data lacks valid header signature', 'warning');
                // We'll still try to continue despite the warning
            }
            
            if ($data_length < $min_size) {
                $this->log('AVIF/HEIF image data is very small (' . $data_length . ' bytes). Attempting to save anyway.', 'warning');
            }
            
            return true; // Still try to save AVIF/HEIF even with warnings
        }
        
        // Handle other image formats
        if ($data_length < $min_size) {
            $this->log('Image data too small (' . $data_length . ' bytes)', 'error');
            return false;
        }
        
        // Validate common image format headers/magic bytes
        if ($format === 'jpeg' || $format === 'jpg') {
            // Check for JPEG header (SOI marker)
            if (substr($image_data, 0, 2) !== "\xFF\xD8") {
                $this->log('Invalid JPEG data (missing SOI marker)', 'error');
                return false;
            }
        } elseif ($format === 'png') {
            // Check for PNG signature
            if (substr($image_data, 0, 8) !== "\x89PNG\r\n\x1A\n") {
                $this->log('Invalid PNG data (incorrect signature)', 'error');
                return false;
            }
        } elseif ($format === 'webp') {
            // Check for WebP signature
            if (substr($image_data, 0, 4) !== 'RIFF' || substr($image_data, 8, 4) !== 'WEBP') {
                $this->log('Invalid WebP data (incorrect signature)', 'warning');
                // Continue despite the warning
            }
        }
        
        return true;
    }
    
    /**
     * Handle format conversion and file updates
     * @param string $file_path Original file path
     * @param array $result Optimization result
     * @param string $image_data Binary image data
     * @return string|false New file path or false on error
     */
    private function handle_format_conversion($file_path, $result, $image_data) {
        // Extract format information
        $original_format = pathinfo($file_path, PATHINFO_EXTENSION);
        $target_format = isset($result['data']['targetFormat']) ? strtolower($result['data']['targetFormat']) : $original_format;

        // Ensure target format is valid, use original as fallback
        if (empty($target_format) || !in_array($target_format, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heif'))) {
            $target_format = $original_format;
        }
        
        // Standardize JPEG format name
        if ($target_format === 'jpg') {
            $target_format = 'jpeg';
        }
        
        // If no format change, just update the existing file
        if (strtolower($original_format) === $target_format || 
            (strtolower($original_format) === 'jpg' && $target_format === 'jpeg') ||
            (strtolower($original_format) === 'jpeg' && $target_format === 'jpg')) {
            
            // Use WordPress filesystem API instead of direct PHP file operations
            global $wp_filesystem;
            
            // Initialize the WordPress Filesystem
            if (!function_exists('WP_Filesystem')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            
            // Initialize WP_Filesystem
            if (!WP_Filesystem()) {
                $this->log('Failed to initialize WP_Filesystem', 'error');
                return false;
            }
            
            // Write the file using WordPress filesystem API
            $write_success = $wp_filesystem->put_contents($file_path, $image_data, FS_CHMOD_FILE);
            
            if (!$write_success) {
                $this->log('Failed to write image data to file: ' . $file_path, 'error');
                return false;
            }
            
            $this->log('Successfully updated existing file (no format change): ' . basename($file_path));
            return $file_path;
        }
        
        // For format changes, create a new file
        $this->log('Converting file format from ' . $original_format . ' to ' . $target_format . ': ' . basename($file_path));
        $new_file_path = $this->convert_file_format($file_path, $target_format, $image_data);
        
        if (!$new_file_path) {
            $this->log('Format conversion failed for: ' . basename($file_path), 'error');
            return false;
        }
        
        // Get attachment ID for metadata update
        $attachment_id = $this->get_attachment_id_by_path($file_path);
        
        if ($attachment_id) {
            // Update WordPress attachment metadata for the new format
            $update_success = $this->update_attachment_format($file_path, $new_file_path, $target_format);
            
            if ($update_success) {
                // Trigger URL path update for the modified file
                $this->notify_url_updated($attachment_id, $file_path, $new_file_path);
                
                // Delete the original file if the format changed
                if (is_file($file_path) && $file_path !== $new_file_path) {
                    $this->delete_original_file($file_path);
                }
            } else {
                $this->log('Failed to update attachment metadata after format conversion: ' . basename($file_path), 'error');
            }
        } else {
            $this->log('No attachment ID found for optimized file: ' . basename($file_path), 'warning');
        }
        
        return $new_file_path;
    }
    
    /**
     * Notify the system that a URL has been updated due to format conversion
     * This ensures proper URL updates in content
     * 
     * @param int $attachment_id Attachment ID
     * @param string $old_file_path Old file path
     * @param string $new_file_path New file path
     * @return void
     */
    private function notify_url_updated($attachment_id, $old_file_path, $new_file_path) {
        if (!$attachment_id) {
            return;
        }
        
        // Store the original path for reference
        $original_path = get_post_meta($attachment_id, '_wp_attached_file', true);
        update_post_meta($attachment_id, '_nofb_original_path', $original_path);
        
        // Store the old and new URLs for later content replacement
        $upload_dir = wp_upload_dir();
        $old_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $old_file_path);
        $new_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $new_file_path);
        
        update_post_meta($attachment_id, '_nofb_old_url', $old_url);
        update_post_meta($attachment_id, '_nofb_new_url', $new_url);
        
        // Immediately trigger content updates to fix any existing references
        $this->update_content_urls($attachment_id, $old_url, $new_url);
        
        // Force regenerate attachment metadata to ensure all sizes are updated
        $metadata = wp_generate_attachment_metadata($attachment_id, $new_file_path);
        wp_update_attachment_metadata($attachment_id, $metadata);
        
        $this->log('URL change notification: ' . basename($old_file_path) . ' → ' . basename($new_file_path));
    }
    
    /**
     * Update content URLs to fix references to changed file paths
     * 
     * @param int $attachment_id Attachment ID
     * @param string $old_url Old URL
     * @param string $new_url New URL
     * @return void
     */
    private function update_content_urls($attachment_id, $old_url, $new_url) {
        global $wpdb;
        
        if ($old_url === $new_url) {
            return;
        }
        
        // Cache key for posts query
        $cache_key = 'nofb_url_posts_' . md5($old_url);
        $posts = wp_cache_get($cache_key, 'nofb_optimizer');
        
        if (false === $posts) {
            // Update posts that might contain the old URL
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Using custom caching solution
            $posts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_content FROM {$wpdb->posts} 
                     WHERE post_content LIKE %s AND post_status = 'publish'",
                    '%' . $wpdb->esc_like($old_url) . '%'
                )
            );
            
            // Cache results for 15 minutes
            wp_cache_set($cache_key, $posts, 'nofb_optimizer', 15 * MINUTE_IN_SECONDS);
        }
        
        $updated_count = 0;
        
        if (!empty($posts)) {
            foreach ($posts as $post) {
                $updated_content = str_replace($old_url, $new_url, $post->post_content);
                
                if ($updated_content !== $post->post_content) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Necessary for URL updates
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
        }
        
        // Cache key for meta entries query
        $meta_cache_key = 'nofb_url_meta_' . md5($old_url);
        $meta_entries = wp_cache_get($meta_cache_key, 'nofb_optimizer');
        
        if (false === $meta_entries) {
            // Also update post meta
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Using custom caching solution
            $meta_entries = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} 
                     WHERE meta_value LIKE %s",
                    '%' . $wpdb->esc_like($old_url) . '%'
                )
            );
            
            // Cache results for 15 minutes
            wp_cache_set($meta_cache_key, $meta_entries, 'nofb_optimizer', 15 * MINUTE_IN_SECONDS);
        }
        
        if (!empty($meta_entries)) {
            foreach ($meta_entries as $meta) {
                // Skip our own meta
                if (strpos($meta->meta_key, '_nofb_') === 0) {
                    continue;
                }
                
                if (is_serialized($meta->meta_value)) {
                    // Handle serialized data
                    $unserialized = maybe_unserialize($meta->meta_value);
                    
                    // Convert to JSON and back to process deep URLs
                    $json = wp_json_encode($unserialized);
                    if ($json && strpos($json, $old_url) !== false) {
                        $updated_json = str_replace($old_url, $new_url, $json);
                        $updated_data = json_decode($updated_json, true);
                        
                        if ($updated_data) {
                            update_post_meta($meta->post_id, $meta->meta_key, $updated_data);
                        }
                    }
                } else if (is_string($meta->meta_value) && strpos($meta->meta_value, $old_url) !== false) {
                    // Handle string values
                    $updated_value = str_replace($old_url, $new_url, $meta->meta_value);
                    update_post_meta($meta->post_id, $meta->meta_key, $updated_value);
                }
            }
        }
        
        if ($updated_count > 0) {
            $this->log('Updated URL references in ' . $updated_count . ' posts');
        }
        
        // Also notify WP core that the attachment URL has changed
        clean_attachment_cache($attachment_id);
    }
    
    /**
     * Convert file to new format
     * @param string $original_path Original file path
     * @param string $target_format Target format
     * @param string $image_data Image data
     * @return string|false New file path or false on error
     */
    private function convert_file_format($original_path, $target_format, $image_data) {
        $path_info = pathinfo($original_path);
        $new_extension = $this->get_file_extension($target_format);
        $new_file_path = $path_info['dirname'] . '/' . $path_info['filename'] . '.' . $new_extension;
        
        $this->log('Converting format to ' . $target_format . ' (' . $new_extension . ')');
        
        // Check if target format is supported
        $allowed_formats = array('jpg', 'jpeg', 'png', 'webp', 'avif', 'heif');
        if (!in_array(strtolower($target_format), $allowed_formats)) {
            $this->log('Unsupported target format: ' . $target_format, 'error');
            return false;
        }
        
        // Create a temporary file first to ensure safe writing
        $temp_file_path = $new_file_path . '.tmp';
        
        // Write new file
        $write_result = file_put_contents($temp_file_path, $image_data);
        if ($write_result === false) {
            $this->log('Failed to write converted image to temporary file', 'error');
            if (file_exists($temp_file_path)) {
                wp_delete_file($temp_file_path);
            }
            return false;
        }
        
        // Verify the written file
        if (!file_exists($temp_file_path) || filesize($temp_file_path) === 0) {
            $this->log('Converted image file is empty or not written: ' . basename($temp_file_path), 'error');
            wp_delete_file($temp_file_path);
            return false;
        }
        
        // Move temporary file to final destination
        global $wp_filesystem;
        if (!WP_Filesystem() || !$wp_filesystem->move($temp_file_path, $new_file_path, true)) {
            $this->log('Failed to rename temporary file to final destination', 'error');
            wp_delete_file($temp_file_path);
            return false;
        }
        
        // Update WordPress attachment metadata
        if (!$this->update_attachment_format($original_path, $new_file_path, $target_format)) {
            $this->log('Failed to update attachment metadata for format conversion', 'error');
            return false;
        }
        
        // Clean up original file if different
        if ($original_path !== $new_file_path && file_exists($original_path)) {
            $this->log('Removing original file: ' . basename($original_path));
            if (!wp_delete_file($original_path)) {
                $this->log('Warning: Failed to delete original file: ' . basename($original_path), 'warning');
                // Continue despite the warning
            }
        }
        
        $this->log('Successfully converted ' . basename($original_path) . ' to ' . basename($new_file_path));
        return $new_file_path;
    }
    
    /**
     * Get file extension for format
     * @param string $format Format name
     * @return string File extension
     */
    private function get_file_extension($format) {
        $extensions = array(
            'jpeg' => 'jpg',
            'jpg' => 'jpg',
            'png' => 'png',
            'webp' => 'webp',
            'avif' => 'avif',
            'heif' => 'avif',
            'svg' => 'svg'
        );
        
        return $extensions[$format] ?? $format;
    }
    
    /**
     * Update WordPress attachment metadata for format change
     * @param string $original_path Original file path
     * @param string $new_path New file path
     * @param string $target_format Target format
     * @return bool Success status
     */
    private function update_attachment_format($original_path, $new_path, $target_format) {
        $attachment_id = $this->get_cached_attachment_id($original_path);
        if (!$attachment_id) {
            return false;
        }
        
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['basedir'] . '/', '', $new_path);
        
        // Update file path
        update_post_meta($attachment_id, '_wp_attached_file', $relative_path);
        
        // Update metadata
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (is_array($metadata)) {
            $metadata['file'] = $relative_path;
            wp_update_attachment_metadata($attachment_id, $metadata);
        }
        
        // Update MIME type
        $new_mime_type = $this->get_mime_type($target_format);
        if ($new_mime_type) {
            wp_update_post(array(
                'ID' => $attachment_id,
                'post_mime_type' => $new_mime_type
            ));
            $this->log('Updated mime type to ' . $new_mime_type . ' for attachment ID ' . $attachment_id);
        }
        
        return true;
    }
    
    /**
     * Get MIME type for format
     * @param string $format Format name
     * @return string MIME type
     */
    private function get_mime_type($format) {
        $mime_types = array(
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'heif' => 'image/avif',
            'svg' => 'image/svg+xml'
        );
        
        return $mime_types[$format] ?? '';
    }
    
    /**
     * Mark file as optimized in database
     */
    private function mark_as_optimized($file_path, $result) {
        $attachment_id = $this->get_cached_attachment_id($file_path);
        if (!$attachment_id) {
            return;
        }
        
        $file_size = filesize($file_path);
        
        // Update attachment metadata
        update_post_meta($attachment_id, '_nofb_optimized', true);
        update_post_meta($attachment_id, '_nofb_optimization_date', current_time('mysql'));
        update_post_meta($attachment_id, '_nofb_file_size', $file_size);
        update_post_meta($attachment_id, '_nofb_local_path', $file_path);
        
        // Update optimization metadata
        if (isset($result['data'])) {
            $metadata = array(
                'originalFormat' => isset($result['data']['originalFormat']) ? $result['data']['originalFormat'] : '',
                'targetFormat' => isset($result['data']['targetFormat']) ? $result['data']['targetFormat'] : '',
                'originalSize' => isset($result['data']['originalSize']) ? $result['data']['originalSize'] : 0,
                'compressedSize' => isset($result['data']['compressedSize']) ? $result['data']['compressedSize'] : 0,
                'compressionRatio' => isset($result['data']['compressionRatio']) ? $result['data']['compressionRatio'] : 0,
                'optimizedQuality' => isset($result['data']['optimizedQuality']) ? $result['data']['optimizedQuality'] : 0,
            );
            update_post_meta($attachment_id, '_nofb_optimization_data', $metadata);
        }
    }
    
    /**
     * Check if optimized file is eligible for migration
     */
    private function check_migration_eligibility($file_path) {
        $config = $this->get_cached_config();
        
        // Only add to migration queue if auto-migration is enabled
        if (!$config['auto_migrate']) {
            return;
        }
        
        $mime_type = wp_check_filetype($file_path)['type'];
        $file_size_kb = filesize($file_path) / 1024;
        
        // Check if AVIF, WebP, or SVG and within size limit
        $eligible_types = array('image/avif', 'image/webp', 'image/svg+xml');
        
        if (in_array($mime_type, $eligible_types) && $file_size_kb <= $config['max_file_size_kb']) {
            // Add to migration queue
            $queue = new NOFB_Queue('migration');
            $queue->add($file_path);
        }
    }
    
    /**
     * Get attachment ID by file path
     */
    private function get_attachment_id_by_path($file_path) {
        return $this->get_cached_attachment_id($file_path);
    }
    
    /**
     * Get the correct attachment path directly from WordPress
     * This is a fallback when normalize_file_path fails
     */
    public function get_attachment_path($attachment_id) {
        if (!$attachment_id) {
            return false;
        }
        
        // Get the file path directly from WordPress
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            $this->log('Debug: Attachment file path not found or doesn\'t exist for ID ' . $attachment_id);
            
            // Try to get from metadata as a fallback
            $metadata = wp_get_attachment_metadata($attachment_id);
            if (!empty($metadata['file'])) {
                $upload_dir = wp_upload_dir();
                $file_path = $upload_dir['basedir'] . '/' . $metadata['file'];
                
                if (file_exists($file_path)) {
                    $this->log('Debug: Found file via metadata: ' . $file_path);
                    return $file_path;
                }
            }
            
            return false;
        }
        
        return $file_path;
    }
    
    /**
     * Remap file path between different environments
     * This handles cases where database contains paths from a different server
     */
    public function remap_environment_path($file_path) {
        // If file exists as-is, no need to remap
        if (file_exists($file_path)) {
            return $file_path;
        }
        
        // Known environment path mappings
        $path_mappings = array(
            // Old dev path → Current server path
            '/home/nexwinds-dev/htdocs/dev.nexwinds.com/wp-content/uploads/' => wp_upload_dir()['basedir'] . '/'
        );
        
        // Check if the path matches any known environment patterns
        foreach ($path_mappings as $old_path => $new_path) {
            if (strpos($file_path, $old_path) === 0) {
                // Extract the relative path (just the year/month/filename part)
                $relative_path = substr($file_path, strlen($old_path));
                // Construct the new path
                $mapped_path = $new_path . $relative_path;
                
                $this->log('Remapped environment path: ' . $file_path . ' → ' . $mapped_path);
                
                if (file_exists($mapped_path)) {
                    return $mapped_path;
                }
            }
        }
        
        // If we still don't have a working path, try extracting just the year/month/filename
        $pattern = '/\/(\d{4}\/\d{2}\/.+\.(jpg|jpeg|png|gif|webp|avif|heic|heif|tiff|svg))$/i';
        if (preg_match($pattern, $file_path, $matches)) {
            $year_month_file = $matches[1];
            $mapped_path = wp_upload_dir()['basedir'] . '/' . $year_month_file;
            
            $this->log('Extracted year/month path: ' . $mapped_path);
            
            if (file_exists($mapped_path)) {
                return $mapped_path;
            }
        }
        
        // No working path found
        return $file_path;
    }
    
    /**
     * Delete the original file after optimization
     */
    private function delete_original_file($file_path) {
        if (file_exists($file_path)) {
            // Use wp_delete_file() which handles checking if file is writable
            wp_delete_file($file_path);
            return true;
        }
        return false;
    }
} 