<?php
/**
 * Settings template for Bunny Media Offload
 */
if (!defined('ABSPATH')) {
    exit;
}

// Get active tab with proper sanitization and unslashing
$active_tab = 'api'; // Default tab

// Verify nonce for GET requests
$nonce_verified = true;
if (!empty($_GET) && (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'nofb_settings_tab'))) {
    $nonce_verified = false;
}

// If tab parameter exists, sanitize and unslash it
if (isset($_GET['tab'])) {
    $active_tab = sanitize_text_field(wp_unslash($_GET['tab']));
}

// We only need nonce verification for form submissions, not for tab navigation
// Form submissions are handled by WordPress's built-in options.php handler
?>

<div class="wrap nofb-settings">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <h2 class="nav-tab-wrapper">
        <a href="<?php echo esc_url(add_query_arg(array('page' => 'nexoffload-for-bunny-settings', 'tab' => 'api', '_wpnonce' => wp_create_nonce('nofb_settings_tab')), admin_url('admin.php'))); ?>" class="nav-tab <?php echo esc_attr($active_tab == 'api' ? 'nav-tab-active' : ''); ?>">
            <?php esc_html_e('API Settings', 'nexoffload-for-bunny'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg(array('page' => 'nexoffload-for-bunny-settings', 'tab' => 'general', '_wpnonce' => wp_create_nonce('nofb_settings_tab')), admin_url('admin.php'))); ?>" class="nav-tab <?php echo esc_attr($active_tab == 'general' ? 'nav-tab-active' : ''); ?>">
            <?php esc_html_e('General Settings', 'nexoffload-for-bunny'); ?>
        </a>
    </h2>
    
    <?php
    if ($active_tab === 'api'):
        // Check for constants defined in wp-config.php
        $api_key_defined = defined('BUNNY_API_KEY') && !empty(BUNNY_API_KEY);
        $storage_zone_defined = defined('BUNNY_STORAGE_ZONE') && !empty(BUNNY_STORAGE_ZONE);
        $hostname_defined = defined('BUNNY_CUSTOM_HOSTNAME') && !empty(BUNNY_CUSTOM_HOSTNAME);
        $NOFB_API_KEY_defined = defined('NOFB_API_KEY') && !empty(NOFB_API_KEY);
        $NOFB_API_REGION_defined = defined('NOFB_API_REGION') && !empty(NOFB_API_REGION);
    ?>
    <div class="nofb-settings-container">
        <div class="nofb-api-instruction">
            <h3><?php esc_html_e('API Configuration', 'nexoffload-for-bunny'); ?></h3>
            <p><?php esc_html_e('For security reasons, all API settings should be defined in your wp-config.php file.', 'nexoffload-for-bunny'); ?></p>
            <pre><code>// Bunny.net Storage API
define('BUNNY_API_KEY', 'your_bunny_api_key_here');
define('BUNNY_STORAGE_ZONE', 'your_storage_zone_here');
define('BUNNY_CUSTOM_HOSTNAME', 'cdn.yourdomain.com');

// nofb Optimization API 
define('NOFB_API_KEY', 'your_nofb_api_key_here');
define('NOFB_API_REGION', 'us'); // Options: us, eu, asia</code></pre>
        </div>
        
        <form method="post" action="options.php">
            <?php
            settings_fields('nofb_api_settings');
            do_settings_sections('nofb_api_settings');
            ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="bunny_api_key"><?php esc_html_e('Bunny API Key', 'nexoffload-for-bunny'); ?></label>
                    </th>
                    <td>
                        <?php if ($api_key_defined): ?>
                            <input type="text" id="bunny_api_key" class="regular-text" value="<?php echo esc_attr('************' . substr(BUNNY_API_KEY, -4)); ?>" disabled />
                            <p class="description"><?php esc_html_e('Defined in wp-config.php', 'nexoffload-for-bunny'); ?></p>
                        <?php else: ?>
                            <input type="text" id="bunny_api_key" class="regular-text" value="<?php esc_html_e('Not defined', 'nexoffload-for-bunny'); ?>" disabled />
                            <p class="description"><?php esc_html_e('Define BUNNY_API_KEY in wp-config.php to use Bunny.net storage.', 'nexoffload-for-bunny'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="bunny_storage_zone"><?php esc_html_e('Storage Zone', 'nexoffload-for-bunny'); ?></label>
                    </th>
                    <td>
                        <?php if ($storage_zone_defined): ?>
                            <input type="text" id="bunny_storage_zone" class="regular-text" value="<?php echo esc_attr(BUNNY_STORAGE_ZONE); ?>" disabled />
                            <p class="description"><?php esc_html_e('Defined in wp-config.php', 'nexoffload-for-bunny'); ?></p>
                        <?php else: ?>
                            <input type="text" id="bunny_storage_zone" class="regular-text" value="<?php esc_html_e('Not defined', 'nexoffload-for-bunny'); ?>" disabled />
                            <p class="description"><?php esc_html_e('Define BUNNY_STORAGE_ZONE in wp-config.php. Get it from your Bunny.net dashboard.', 'nexoffload-for-bunny'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="bunny_custom_hostname"><?php esc_html_e('Custom Hostname', 'nexoffload-for-bunny'); ?></label>
                    </th>
                    <td>
                        <?php if ($hostname_defined): ?>
                            <input type="text" id="bunny_custom_hostname" class="regular-text" value="<?php echo esc_attr(BUNNY_CUSTOM_HOSTNAME); ?>" disabled />
                            <p class="description"><?php esc_html_e('Defined in wp-config.php', 'nexoffload-for-bunny'); ?></p>
                        <?php else: ?>
                            <input type="text" id="bunny_custom_hostname" class="regular-text" value="<?php esc_html_e('Not defined', 'nexoffload-for-bunny'); ?>" disabled />
                            <p class="description"><?php esc_html_e('Define BUNNY_CUSTOM_HOSTNAME in wp-config.php (e.g. cdn.yourdomain.com).', 'nexoffload-for-bunny'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            
            <h3><?php esc_html_e('nofb Optimization API', 'nexoffload-for-bunny'); ?></h3>
            <p><?php esc_html_e('These settings are for the Bunny Media Offload optimization API service.', 'nexoffload-for-bunny'); ?></p>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="NOFB_API_KEY"><?php esc_html_e('nofb API Key', 'nexoffload-for-bunny'); ?></label>
                    </th>
                    <td>
                        <?php if ($NOFB_API_KEY_defined): ?>
                            <input type="text" id="NOFB_API_KEY" class="regular-text" value="<?php echo esc_attr('************' . substr(NOFB_API_KEY, -4)); ?>" disabled />
                            <p class="description"><?php esc_html_e('Defined in wp-config.php', 'nexoffload-for-bunny'); ?></p>
                        <?php else: ?>
                            <input type="text" id="NOFB_API_KEY" class="regular-text" value="<?php esc_html_e('Not defined', 'nexoffload-for-bunny'); ?>" disabled />
                            <p class="description"><?php esc_html_e('Define NOFB_API_KEY in wp-config.php for image optimization.', 'nexoffload-for-bunny'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="NOFB_API_REGION"><?php esc_html_e('nofb API Region', 'nexoffload-for-bunny'); ?></label>
                    </th>
                    <td>
                        <?php if ($NOFB_API_REGION_defined): ?>
                            <input type="text" id="NOFB_API_REGION" class="regular-text" value="<?php echo esc_attr(NOFB_API_REGION); ?>" disabled />
                            <p class="description"><?php esc_html_e('Defined in wp-config.php', 'nexoffload-for-bunny'); ?></p>
                        <?php else: ?>
                            <input type="text" id="NOFB_API_REGION" class="regular-text" value="<?php echo esc_attr(NOFB_API_REGION); ?>" disabled />
                            <p class="description"><?php esc_html_e('Default region (US). Define NOFB_API_REGION in wp-config.php to change.', 'nexoffload-for-bunny'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </form>
        
        <div class="nofb-api-tester">
            <h3><?php esc_html_e('Connection Test', 'nexoffload-for-bunny'); ?></h3>
            <p><?php esc_html_e('Test your API connection to ensure everything is working correctly.', 'nexoffload-for-bunny'); ?></p>
            <button id="nofb-test-api" class="button button-secondary"><?php esc_html_e('Test Connection', 'nexoffload-for-bunny'); ?></button>
            <div id="nofb-api-test-result" class="nofb-test-result"></div>
        </div>
    </div>
    <?php else: // General Settings Tab ?>
    <div class="nofb-settings-container">
        <form method="post" action="options.php">
            <?php
            settings_fields('nofb_general_settings');
            do_settings_sections('nofb_general_settings');
            ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="nofb_auto_optimize"><?php esc_html_e('Auto Optimization', 'nexoffload-for-bunny'); ?></label>
                    </th>
                    <td>
                        <label for="nofb_auto_optimize">
                            <input type="checkbox" name="nofb_auto_optimize" id="nofb_auto_optimize" value="1" <?php checked(get_option('nofb_auto_optimize', false)); ?> />
                            <?php esc_html_e('Automatically optimize new media uploads', 'nexoffload-for-bunny'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="nofb_auto_migrate"><?php esc_html_e('Auto Migration', 'nexoffload-for-bunny'); ?></label>
                    </th>
                    <td>
                        <label for="nofb_auto_migrate">
                            <input type="checkbox" name="nofb_auto_migrate" id="nofb_auto_migrate" value="1" <?php checked(get_option('nofb_auto_migrate', false)); ?> />
                            <?php esc_html_e('Automatically migrate optimized files to Bunny CDN', 'nexoffload-for-bunny'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When disabled, you can manually trigger migration using the "Migrate All" button.', 'nexoffload-for-bunny'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="nofb_file_versioning"><?php esc_html_e('File Versioning', 'nexoffload-for-bunny'); ?></label>
                    </th>
                    <td>
                        <label for="nofb_file_versioning">
                            <input type="checkbox" name="nofb_file_versioning" id="nofb_file_versioning" value="1" <?php checked(get_option('nofb_file_versioning', false)); ?> />
                            <?php esc_html_e('Enable file versioning for better cache control', 'nexoffload-for-bunny'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="nofb_max_file_size"><?php esc_html_e('Max File Size (KB)', 'nexoffload-for-bunny'); ?></label>
                    </th>
                    <td>
                        <div class="nofb-option-control">
                            <input type="number" name="nofb_max_file_size" id="nofb_max_file_size" 
                                class="regular-text" 
                                value="<?php echo esc_attr(get_option('nofb_max_file_size', NOFB_DEFAULT_MAX_FILE_SIZE)); ?>" min="1" />
                            <p class="description">
                                <?php esc_html_e('Maximum file size in KB that will be processed.', 'nexoffload-for-bunny'); ?>
                            </p>
                        </div>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(); ?>
        </form>

        <div class="nofb-tools-section" style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
            <h3><?php esc_html_e('Maintenance Tools', 'nexoffload-for-bunny'); ?></h3>
            
            <div class="nofb-tool-card" style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; margin-bottom: 20px;">
                <h4><?php esc_html_e('Fix Image URLs', 'nexoffload-for-bunny'); ?></h4>
                <p><?php esc_html_e('If you are experiencing issues with image URLs after plugin updates or renaming, use this tool to fix them.', 'nexoffload-for-bunny'); ?></p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('nofb_fix_urls_action', 'nofb_fix_urls_nonce'); ?>
                    <input type="hidden" name="nofb_action" value="fix_image_urls">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Fix Image URLs', 'nexoffload-for-bunny'); ?>
                    </button>
                </form>
                
                <?php
                // Process URL fixing if requested
                if (isset($_POST['nofb_action']) && $_POST['nofb_action'] === 'fix_image_urls') {
                    // Verify nonce
                    if (isset($_POST['nofb_fix_urls_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nofb_fix_urls_nonce'])), 'nofb_fix_urls_action')) {
                        $result = nofb_force_update_image_urls();
                        if ($result['status'] === 'success') {
                            /* translators: %1$d: Total count of image URLs processed, %2$d: Count of successfully updated URLs, %3$d: Error count */
                            echo '<div class="notice notice-success inline"><p>' . sprintf(esc_html__('Image URLs fixed successfully. Total: %1$d, Updated: %2$d, Errors: %3$d', 'nexoffload-for-bunny'), esc_html($result['total']), esc_html($result['updated']), esc_html($result['errors'])) . '</p></div>';
                        } else {
                            /* translators: %s: Error message from the URL fixing process */
                            echo '<div class="notice notice-error inline"><p>' . esc_html($result['message']) . '</p></div>';
                        }
                    } else {
                        echo '<div class="notice notice-error inline"><p>' . esc_html__('Security check failed.', 'nexoffload-for-bunny') . '</p></div>';
                    }
                }
                ?>
            </div>
            
            <div class="nofb-tool-card" style="background: #fff; padding: 15px; border: 1px solid #ccd0d4;">
                <h4><?php esc_html_e('Clear Plugin Cache', 'nexoffload-for-bunny'); ?></h4>
                <p><?php esc_html_e('Clear the plugin cache to refresh data and resolve potential display issues.', 'nexoffload-for-bunny'); ?></p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('nofb_clear_cache_action', 'nofb_clear_cache_nonce'); ?>
                    <input type="hidden" name="nofb_action" value="clear_cache">
                    <button type="submit" class="button button-secondary">
                        <?php esc_html_e('Clear Cache', 'nexoffload-for-bunny'); ?>
                    </button>
                </form>
                
                <?php
                // Process cache clearing if requested
                if (isset($_POST['nofb_action']) && $_POST['nofb_action'] === 'clear_cache') {
                    // Verify nonce
                    if (isset($_POST['nofb_clear_cache_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nofb_clear_cache_nonce'])), 'nofb_clear_cache_action')) {
                        // Clear all plugin related transients and caches
                        global $wpdb;
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query used for maintenance cleanup operation
                        $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '%nofb_%' AND option_name LIKE '%transient%'");
                        wp_cache_flush();
                        
                        echo '<div class="notice notice-success inline"><p>' . esc_html__('Plugin cache cleared successfully.', 'nexoffload-for-bunny') . '</p></div>';
                    } else {
                        echo '<div class="notice notice-error inline"><p>' . esc_html__('Security check failed.', 'nexoffload-for-bunny') . '</p></div>';
                    }
                }
                ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div> 