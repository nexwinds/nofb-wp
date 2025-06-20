<?php
/**
 * Dashboard template for Bunny Media Offload
 */
if (!defined('ABSPATH')) {
    exit;
}

// Get statistics
$stats = $this->get_statistics();
?>

<div class="wrap nofb-dashboard">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <div class="nofb-dashboard-header">
        <div class="nofb-dashboard-title">
            <h2><?php esc_html_e('Dashboard', 'nexoffload-for-bunny'); ?></h2>
            <p class="description"><?php esc_html_e('Overview of your media files optimization and migration.', 'nexoffload-for-bunny'); ?></p>
        </div>
        <div class="nofb-dashboard-actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=nexoffload-for-bunny-manager')); ?>" class="button button-primary">
                <?php esc_html_e('Manage Media Files', 'nexoffload-for-bunny'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=nexoffload-for-bunny-settings')); ?>" class="button">
                <?php esc_html_e('Settings', 'nexoffload-for-bunny'); ?>
            </a>
        </div>
    </div>
    
    <div class="nofb-card-container nofb-three-column-layout">
        <!-- Statistics Overview -->
        <div class="nofb-card nofb-stats-card">
            <div class="nofb-card-header">
                <h3><?php esc_html_e('Media Library Overview', 'nexoffload-for-bunny'); ?></h3>
            </div>
            <div class="nofb-card-body">
                <div class="nofb-stat-grid">
                    <div class="nofb-stat-item">
                        <span class="nofb-stat-number"><?php echo esc_html(number_format($stats['total_files'])); ?></span>
                        <span class="nofb-stat-label"><?php esc_html_e('Total Files', 'nexoffload-for-bunny'); ?></span>
                    </div>
                    <div class="nofb-stat-item">
                        <span class="nofb-stat-number"><?php echo esc_html(number_format($stats['optimized_files'])); ?></span>
                        <span class="nofb-stat-label"><?php esc_html_e('Optimized Files', 'nexoffload-for-bunny'); ?></span>
                    </div>
                    <div class="nofb-stat-item">
                        <span class="nofb-stat-number"><?php echo esc_html(number_format($stats['migrated_files'])); ?></span>
                        <span class="nofb-stat-label"><?php esc_html_e('Migrated Files', 'nexoffload-for-bunny'); ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Status Card -->
        <div class="nofb-card nofb-status-card">
            <div class="nofb-card-header">
                <h3><?php esc_html_e('Status', 'nexoffload-for-bunny'); ?></h3>
            </div>
            <div class="nofb-card-body">
                <?php
                // Check if API key is configured
                $api_key = defined('BUNNY_API_KEY') ? BUNNY_API_KEY : get_option('bunny_api_key', '');
                $storage_zone = defined('BUNNY_STORAGE_ZONE') ? BUNNY_STORAGE_ZONE : get_option('bunny_storage_zone', '');
                
                if (empty($api_key) || empty($storage_zone)):
                ?>
                <div class="nofb-notice nofb-warning">
                    <p><?php esc_html_e('API configuration is incomplete. Please configure your API settings.', 'nexoffload-for-bunny'); ?></p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=nexoffload-for-bunny-settings')); ?>" class="button">
                        <?php esc_html_e('Configure API', 'nexoffload-for-bunny'); ?>
                    </a>
                </div>
                <?php else: ?>
                <div class="nofb-notice nofb-success">
                    <p><?php esc_html_e('API configuration is complete.', 'nexoffload-for-bunny'); ?></p>
                </div>
                <?php endif; ?>
                
                <?php
                // Get queue status
                $optimization_queue = get_option('nofb_optimization_queue', array());
                $migration_queue = get_option('nofb_migration_queue', array());
                ?>
                <div class="nofb-queue-status">
                    <h4><?php esc_html_e('Queue Status', 'nexoffload-for-bunny'); ?></h4>
                    <div class="nofb-queue-items">
                        <div class="nofb-queue-item">
                            <span class="nofb-queue-label"><?php esc_html_e('Optimization Queue:', 'nexoffload-for-bunny'); ?></span>
                            <span class="nofb-queue-count"><?php echo esc_html(count($optimization_queue)); ?> <?php esc_html_e('files', 'nexoffload-for-bunny'); ?></span>
                        </div>
                        <div class="nofb-queue-item">
                            <span class="nofb-queue-label"><?php esc_html_e('Migration Queue:', 'nexoffload-for-bunny'); ?></span>
                            <span class="nofb-queue-count"><?php echo esc_html(count($migration_queue)); ?> <?php esc_html_e('files', 'nexoffload-for-bunny'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Shortcuts (previously Quick Start) -->
        <div class="nofb-card nofb-shortcuts-card">
            <div class="nofb-card-header">
                <h3><?php esc_html_e('Shortcuts', 'nexoffload-for-bunny'); ?></h3>
            </div>
            <div class="nofb-card-body">
                <div class="nofb-quickstart-steps">
                    <div class="nofb-quickstart-step">
                        <span class="nofb-step-icon dashicons dashicons-image-filter"></span>
                        <div class="nofb-step-content">
                            <h4><?php esc_html_e('Optimize Your Media', 'nexoffload-for-bunny'); ?></h4>
                            <p><?php esc_html_e('Start optimizing your existing media files.', 'nexoffload-for-bunny'); ?></p>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=nexoffload-for-bunny-manager')); ?>" class="button">
                                <?php esc_html_e('Optimize', 'nexoffload-for-bunny'); ?>
                            </a>
                        </div>
                    </div>
                    
                    <div class="nofb-quickstart-step">
                        <span class="nofb-step-icon dashicons dashicons-cloud-upload"></span>
                        <div class="nofb-step-content">
                            <h4><?php esc_html_e('Migrate to Bunny.net CDN', 'nexoffload-for-bunny'); ?></h4>
                            <p><?php esc_html_e('Offload your optimized media files to Bunny.net CDN.', 'nexoffload-for-bunny'); ?></p>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=nexoffload-for-bunny-manager&tab=migration')); ?>" class="button">
                                <?php esc_html_e('Migrate', 'nexoffload-for-bunny'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Support Information Box with Two Inner Columns -->
    <div class="nofb-wide-card-container">
        <?php $this->render_support_box(); ?>
    </div>
</div> 