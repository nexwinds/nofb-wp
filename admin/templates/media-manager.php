<?php
/**
 * Media Manager template for Bunny Media Offload
 */
if (!defined('ABSPATH')) {
    exit;
}

// Verify request only when performing actions, not for tab navigation
$nonce_is_valid = true;
if (isset($_REQUEST['action']) && isset($_REQUEST['_wpnonce'])) {
    $nonce_is_valid = wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), 'nofb_media_manager');
    if (!$nonce_is_valid) {
        wp_die(esc_html__('Security check failed', 'nexoffload-for-bunny'));
    }
}

// Get active tab
$active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'optimization';

// Get media status counts from cache or database
global $wpdb;
$cache_key = 'nofb_media_counts';
$counts = wp_cache_get($cache_key, 'nofb_media_manager');

if ($counts === false) {
    // Individual cache keys for each query
    $total_cache_key = 'nofb_total_media_count';
    $optimized_cache_key = 'nofb_optimized_media_count';
    $migrated_cache_key = 'nofb_migrated_media_count';
    
    // Try to get total media count from cache first
    $total_media = wp_cache_get($total_cache_key, 'nofb_media_manager');
    if ($total_media === false) {
        // Get counts from database using $wpdb->prepare
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query used with proper caching for performance when counting media files
        $total_media = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 
                'attachment'
            )
        );
        // Cache individual query result
        wp_cache_set($total_cache_key, $total_media, 'nofb_media_manager', 5 * MINUTE_IN_SECONDS);
    }
    
    // Try to get optimized count from cache first
    $optimized_count = wp_cache_get($optimized_cache_key, 'nofb_media_manager');
    if ($optimized_count === false) {
        // Use $wpdb->prepare with proper caching for better performance
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query with proper caching improves performance when counting optimized files
        $optimized_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", 
                '_nofb_optimized', '1'
            )
        );
        // Cache individual query result
        wp_cache_set($optimized_cache_key, $optimized_count, 'nofb_media_manager', 5 * MINUTE_IN_SECONDS);
    }
    
    // Try to get migrated count from cache first
    $migrated_count = wp_cache_get($migrated_cache_key, 'nofb_media_manager');
    if ($migrated_count === false) {
        // Use $wpdb->prepare with proper caching for better performance
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query with proper caching improves performance when counting migrated files
        $migrated_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", 
                '_nofb_migrated', '1'
            )
        );
        // Cache individual query result
        wp_cache_set($migrated_cache_key, $migrated_count, 'nofb_media_manager', 5 * MINUTE_IN_SECONDS);
    }
    
    // Store in array for caching
    $counts = array(
        'total_media' => $total_media,
        'optimized_count' => $optimized_count,
        'migrated_count' => $migrated_count
    );
    
    // Cache for 5 minutes
    wp_cache_set($cache_key, $counts, 'nofb_media_manager', 5 * MINUTE_IN_SECONDS);
} else {
    // Extract counts from cache
    $total_media = $counts['total_media'];
    $optimized_count = $counts['optimized_count'];
    $migrated_count = $counts['migrated_count'];
}

// Get eligibility criteria stats
$eligibility = new NOFB_Eligibility();
$optimization_stats = $eligibility->get_optimization_stats();
$migration_stats = $eligibility->get_migration_stats();

// Pending counts
$pending_optimization = $optimization_stats['eligible_total'];
$pending_migration = $migration_stats['eligible_total'];
?>

<div class="wrap nofb-media-manager">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <div class="nofb-stats-bar">
        <div class="nofb-stat-item">
            <span class="nofb-stat-number"><?php echo esc_html(number_format($total_media)); ?></span>
            <span class="nofb-stat-label"><?php esc_html_e('Total Media Files', 'nexoffload-for-bunny'); ?></span>
        </div>
        <div class="nofb-stat-item">
            <span class="nofb-stat-number"><?php echo esc_html(number_format($optimized_count)); ?></span>
            <span class="nofb-stat-label"><?php esc_html_e('Optimized Files', 'nexoffload-for-bunny'); ?></span>
        </div>
        <div class="nofb-stat-item">
            <span class="nofb-stat-number"><?php echo esc_html(number_format($migrated_count)); ?></span>
            <span class="nofb-stat-label"><?php esc_html_e('Migrated Files', 'nexoffload-for-bunny'); ?></span>
        </div>
    </div>
    
    <h2 class="nav-tab-wrapper">
        <a href="<?php echo esc_url(admin_url('admin.php?page=nexoffload-for-bunny-manager&tab=optimization')); ?>" class="nav-tab <?php echo esc_attr($active_tab == 'optimization' ? 'nav-tab-active' : ''); ?>">
            <?php esc_html_e('Optimization', 'nexoffload-for-bunny'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=nexoffload-for-bunny-manager&tab=migration')); ?>" class="nav-tab <?php echo esc_attr($active_tab == 'migration' ? 'nav-tab-active' : ''); ?>">
            <?php esc_html_e('Migration', 'nexoffload-for-bunny'); ?>
        </a>
    </h2>
    
    <?php if ($active_tab === 'optimization'): ?>
    <div id="nofb-optimization" class="nofb-tab-content">
        <div class="nofb-card">
            <div class="nofb-card-header">
                <h3><?php esc_html_e('Optimize Media Files', 'nexoffload-for-bunny'); ?></h3>
                <p class="description"><?php esc_html_e('Optimize your media files to reduce file size without losing quality.', 'nexoffload-for-bunny'); ?></p>
            </div>
            <div class="nofb-card-body">
                <!-- Eligibility Criteria Stats -->
                <div class="nofb-eligibility-criteria">
                    <h4><?php esc_html_e('Eligibility Criteria', 'nexoffload-for-bunny'); ?></h4>
                    <div class="nofb-criteria-table">
                        <div class="nofb-criteria-item">
                            <div class="nofb-criteria-name"><?php esc_html_e('Locally Stored', 'nexoffload-for-bunny'); ?></div>
                            <div class="nofb-criteria-count"><?php echo esc_html(number_format($optimization_stats['locally_stored'])); ?> / <?php echo esc_html(number_format($optimization_stats['total_images'])); ?></div>
                            <div class="nofb-criteria-description"><?php esc_html_e('Files must be stored locally (not on Bunny CDN)', 'nexoffload-for-bunny'); ?></div>
                        </div>
                        <div class="nofb-criteria-item">
                            <div class="nofb-criteria-name"><?php esc_html_e('Correct Size', 'nexoffload-for-bunny'); ?></div>
                            <div class="nofb-criteria-count"><?php echo esc_html(number_format($optimization_stats['correct_size'])); ?> / <?php echo esc_html(number_format($optimization_stats['locally_stored'])); ?></div>
                            <div class="nofb-criteria-description"><?php 
                            /* translators: %d: Maximum file size in KB */
                            printf(esc_html__('Files must be > %d KB and < 10 MB', 'nexoffload-for-bunny'), esc_html(get_option('nofb_max_file_size', NOFB_DEFAULT_MAX_FILE_SIZE))); ?></div>
                        </div>
                        <div class="nofb-criteria-item">
                            <div class="nofb-criteria-name"><?php esc_html_e('Valid File Type', 'nexoffload-for-bunny'); ?></div>
                            <div class="nofb-criteria-count"><?php echo esc_html(number_format($optimization_stats['correct_type'])); ?> / <?php echo esc_html(number_format($optimization_stats['correct_size'])); ?></div>
                            <div class="nofb-criteria-description"><?php esc_html_e('AVIF, WebP, JPEG, JPG, PNG, HEIC, TIFF', 'nexoffload-for-bunny'); ?></div>
                        </div>
                        <div class="nofb-criteria-item nofb-criteria-total">
                            <div class="nofb-criteria-name"><?php esc_html_e('Total Eligible', 'nexoffload-for-bunny'); ?></div>
                            <div class="nofb-criteria-count"><?php echo esc_html(number_format($optimization_stats['eligible_total'])); ?> / <?php echo esc_html(number_format($optimization_stats['total_images'])); ?></div>
                            <div class="nofb-criteria-description"><?php esc_html_e('Files meeting all criteria', 'nexoffload-for-bunny'); ?></div>
                            <div class="nofb-criteria-note"><?php esc_html_e('Note: Files of type JPEG, JPG, PNG, HEIC, TIFF are eligible for optimization regardless of size.', 'nexoffload-for-bunny'); ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="nofb-progress-container">
                    <label><?php 
                    /* translators: %1$d: Number of optimized files, %2$d: Total number of eligible files */
                    printf(esc_html__('Optimization Progress: %1$d / %2$d eligible files', 'nexoffload-for-bunny'), 
                           esc_html($optimized_count), 
                           esc_html(($optimized_count + $pending_optimization))); ?></label>
                    <div class="nofb-progress-bar">
                        <div class="nofb-progress" style="width: <?php echo esc_attr(($optimized_count + $pending_optimization) > 0 ? round(($optimized_count / ($optimized_count + $pending_optimization)) * 100, 1) : 0); ?>%"></div>
                    </div>
                    <div class="nofb-progress-info">
                        <?php if ($pending_optimization > 0): ?>
                            <p><?php 
                            /* translators: %d: Number of files pending optimization */
                            printf(esc_html__('%d files pending optimization', 'nexoffload-for-bunny'), esc_html($pending_optimization)); ?></p>
                        <?php else: ?>
                            <p><?php esc_html_e('All eligible files have been optimized!', 'nexoffload-for-bunny'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="nofb-actions">
                    <button id="nofb-optimize-all" class="button button-primary" <?php echo esc_attr($pending_optimization > 0 ? '' : 'disabled'); ?>>
                        <?php esc_html_e('Optimize All Media Files', 'nexoffload-for-bunny'); ?>
                    </button>
                    <button id="nofb-stop-optimization" class="button" disabled>
                        <?php esc_html_e('Stop Optimization', 'nexoffload-for-bunny'); ?>
                    </button>
                    <button id="nofb-reinitialize-optimization-queue" class="button">
                        <?php esc_html_e('Reinitialize Queue', 'nexoffload-for-bunny'); ?>
                    </button>
                </div>
                
                <div id="nofb-optimization-log" class="nofb-log-container">
                    <h4><?php esc_html_e('Optimization Log', 'nexoffload-for-bunny'); ?></h4>
                    <div class="nofb-log"></div>
                </div>
                
                <div class="nofb-settings-toggle">
                    <h4><?php esc_html_e('Optimization Settings', 'nexoffload-for-bunny'); ?></h4>
                    <label for="nofb-auto-optimize-toggle">
                        <input type="checkbox" id="nofb-auto-optimize-toggle" <?php checked(get_option('nofb_auto_optimize', false)); ?>>
                        <?php esc_html_e('Automatically optimize new uploads', 'nexoffload-for-bunny'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('Changes to this setting will be saved immediately.', 'nexoffload-for-bunny'); ?></p>
                </div>
            </div>
        </div>
        
        <div class="nofb-card">
            <div class="nofb-card-header">
                <h3><?php esc_html_e('Recent Optimizations', 'nexoffload-for-bunny'); ?></h3>
            </div>
            <div class="nofb-card-body">
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Thumbnail', 'nexoffload-for-bunny'); ?></th>
                            <th><?php esc_html_e('File Name', 'nexoffload-for-bunny'); ?></th>
                            <th><?php esc_html_e('Original Size', 'nexoffload-for-bunny'); ?></th>
                            <th><?php esc_html_e('Optimized Size', 'nexoffload-for-bunny'); ?></th>
                            <th><?php esc_html_e('Savings', 'nexoffload-for-bunny'); ?></th>
                            <th><?php esc_html_e('Date', 'nexoffload-for-bunny'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="nofb-recent-optimizations">
                        <tr>
                            <td colspan="6"><?php esc_html_e('Loading recent optimizations...', 'nexoffload-for-bunny'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php else: // Migration Tab ?>
    <div id="nofb-migration" class="nofb-tab-content">
        <div class="nofb-card">
            <div class="nofb-card-header">
                <h3><?php esc_html_e('Migrate Media Files to Bunny.net CDN', 'nexoffload-for-bunny'); ?></h3>
                <p class="description"><?php esc_html_e('Offload your media files to Bunny.net CDN for faster delivery.', 'nexoffload-for-bunny'); ?></p>
            </div>
            <div class="nofb-card-body">
                <?php
                // Check if API is configured
                $api_key = defined('BUNNY_API_KEY') ? BUNNY_API_KEY : get_option('bunny_api_key', '');
                $storage_zone = defined('BUNNY_STORAGE_ZONE') ? BUNNY_STORAGE_ZONE : get_option('bunny_storage_zone', '');
                
                if (empty($api_key) || empty($storage_zone)):
                ?>
                <div class="nofb-notice nofb-warning">
                    <p><?php esc_html_e('API configuration is incomplete. Please configure your API settings before migrating files.', 'nexoffload-for-bunny'); ?></p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=nexoffload-for-bunny-settings&tab=api')); ?>" class="button">
                        <?php esc_html_e('Configure API', 'nexoffload-for-bunny'); ?>
                    </a>
                </div>
                <?php else: ?>
                
                <!-- Eligibility Criteria Stats -->
                <div class="nofb-eligibility-criteria">
                    <h4><?php esc_html_e('Eligibility Criteria', 'nexoffload-for-bunny'); ?></h4>
                    <div class="nofb-criteria-table">
                        <div class="nofb-criteria-item">
                            <div class="nofb-criteria-name"><?php esc_html_e('Locally Stored', 'nexoffload-for-bunny'); ?></div>
                            <div class="nofb-criteria-count"><?php echo esc_html(number_format($migration_stats['locally_stored'])); ?> / <?php echo esc_html(number_format($migration_stats['total_images'])); ?></div>
                            <div class="nofb-criteria-description"><?php esc_html_e('Files must be stored locally (not on Bunny CDN)', 'nexoffload-for-bunny'); ?></div>
                        </div>
                        <div class="nofb-criteria-item">
                            <div class="nofb-criteria-name"><?php esc_html_e('Correct Size', 'nexoffload-for-bunny'); ?></div>
                            <div class="nofb-criteria-count"><?php echo esc_html(number_format($migration_stats['correct_size'])); ?> / <?php echo esc_html(number_format($migration_stats['locally_stored'])); ?></div>
                            <div class="nofb-criteria-description"><?php 
                            /* translators: %d: Maximum file size in KB */
                            printf(esc_html__('Files must be < %d KB', 'nexoffload-for-bunny'), esc_html(get_option('nofb_max_file_size', NOFB_DEFAULT_MAX_FILE_SIZE))); ?></div>
                        </div>
                        <div class="nofb-criteria-item">
                            <div class="nofb-criteria-name"><?php esc_html_e('Valid File Type', 'nexoffload-for-bunny'); ?></div>
                            <div class="nofb-criteria-count"><?php echo esc_html(number_format($migration_stats['correct_type'])); ?> / <?php echo esc_html(number_format($migration_stats['correct_size'])); ?></div>
                            <div class="nofb-criteria-description"><?php esc_html_e('AVIF, WebP, SVG', 'nexoffload-for-bunny'); ?></div>
                        </div>
                        <div class="nofb-criteria-item nofb-criteria-total">
                            <div class="nofb-criteria-name"><?php esc_html_e('Total Eligible', 'nexoffload-for-bunny'); ?></div>
                            <div class="nofb-criteria-count"><?php echo esc_html(number_format($migration_stats['eligible_total'])); ?> / <?php echo esc_html(number_format($migration_stats['total_images'])); ?></div>
                            <div class="nofb-criteria-description"><?php esc_html_e('Files meeting all criteria', 'nexoffload-for-bunny'); ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="nofb-progress-container">
                    <label><?php 
                    /* translators: %1$d: Number of migrated files, %2$d: Total number of eligible files */
                    printf(esc_html__('Migration Progress: %1$d / %2$d eligible files', 'nexoffload-for-bunny'), 
                          esc_html($migrated_count), 
                          esc_html(($migrated_count + $pending_migration))); ?></label>
                    <div class="nofb-progress-bar">
                        <div class="nofb-progress" style="width: <?php echo esc_attr(($migrated_count + $pending_migration) > 0 ? round(($migrated_count / ($migrated_count + $pending_migration)) * 100, 1) : 0); ?>%"></div>
                    </div>
                    <div class="nofb-progress-info">
                        <?php if ($pending_migration > 0): ?>
                            <p><?php 
                            /* translators: %d: Number of files pending migration */
                            printf(esc_html__('%d files pending migration', 'nexoffload-for-bunny'), esc_html($pending_migration)); ?></p>
                        <?php else: ?>
                            <p><?php esc_html_e('All eligible files have been migrated!', 'nexoffload-for-bunny'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="nofb-actions">
                    <button id="nofb-migrate-all" class="button button-primary" <?php echo esc_attr($pending_migration > 0 ? '' : 'disabled'); ?>>
                        <?php esc_html_e('Migrate All Eligible Files', 'nexoffload-for-bunny'); ?>
                    </button>
                    <button id="nofb-stop-migration" class="button" disabled>
                        <?php esc_html_e('Stop Migration', 'nexoffload-for-bunny'); ?>
                    </button>
                    <button id="nofb-reinitialize-migration-queue" class="button">
                        <?php esc_html_e('Reinitialize Queue', 'nexoffload-for-bunny'); ?>
                    </button>
                </div>
                
                <div id="nofb-migration-log" class="nofb-log-container">
                    <h4><?php esc_html_e('Migration Log', 'nexoffload-for-bunny'); ?></h4>
                    <div class="nofb-log"></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="nofb-card">
            <div class="nofb-card-header">
                <h3><?php esc_html_e('Recent Migrations', 'nexoffload-for-bunny'); ?></h3>
            </div>
            <div class="nofb-card-body">
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Thumbnail', 'nexoffload-for-bunny'); ?></th>
                            <th><?php esc_html_e('File Name', 'nexoffload-for-bunny'); ?></th>
                            <th><?php esc_html_e('Local Path', 'nexoffload-for-bunny'); ?></th>
                            <th><?php esc_html_e('CDN URL', 'nexoffload-for-bunny'); ?></th>
                            <th><?php esc_html_e('Size', 'nexoffload-for-bunny'); ?></th>
                            <th><?php esc_html_e('Date', 'nexoffload-for-bunny'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="nofb-recent-migrations">
                        <tr>
                            <td colspan="6"><?php esc_html_e('Loading recent migrations...', 'nexoffload-for-bunny'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script type="text/javascript">
    jQuery(document).ready(function($) {
        // Initialize optimization and migration processes
        
        // Make sure the script is loaded
        if (typeof nofb_params === 'undefined') {
            console.error('Bunny Media Offload: JavaScript parameters not loaded correctly.');
            return;
        }
        
        // Load recent data on page load
        if ($('#nofb-recent-optimizations').length) {
            refreshRecentOptimizations();
        }
        
        if ($('#nofb-recent-migrations').length) {
            refreshRecentMigrations();
        }
        
        // Helper function to get current time
        function getCurrentTime() {
            var now = new Date();
            var hours = now.getHours().toString().padStart(2, '0');
            var minutes = now.getMinutes().toString().padStart(2, '0');
            var seconds = now.getSeconds().toString().padStart(2, '0');
            return '[' + hours + ':' + minutes + ':' + seconds + ']';
        }
        
        // Helper function to update progress
        function updateProgress(progress, total) {
            var percent = total > 0 ? Math.round((progress / total) * 100) : 0;
            $('.nofb-progress').css('width', percent + '%');
            $('.nofb-progress-container label').text('Optimization Progress: ' + progress + ' / ' + total + ' eligible files');
        }
        
        // Helper function to update migration progress
        function updateMigrationProgress(progress, total) {
            var percent = total > 0 ? Math.round((progress / total) * 100) : 0;
            $('.nofb-progress').css('width', percent + '%');
            $('.nofb-progress-container label').text('Migration Progress: ' + progress + ' / ' + total + ' eligible files');
        }
        
        // Helper function to refresh recent optimizations
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
        
        // Helper function to refresh recent migrations
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
    });
</script> 