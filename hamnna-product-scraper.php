<?php
/**
 * Plugin Name: Hamnna Product Scraper for WooCommerce
 * Description: Imports available products from hamnna.ir, skips existing products, checks every 10 minutes, and provides exact manual price synchronization plus detailed reports.
 * Version: 1.3.0
 * Author: Hamnna
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
if(!defined('ABSPATH'))exit;
define('HAMNNA_SCRAPER_VERSION','1.3.0');
define('HAMNNA_SCRAPER_FILE',__FILE__);define('HAMNNA_SCRAPER_DIR',plugin_dir_path(__FILE__));
require_once HAMNNA_SCRAPER_DIR.'includes/class-hamnna-scraper.php';
require_once HAMNNA_SCRAPER_DIR.'includes/class-hamnna-image-optimizer.php';
require_once HAMNNA_SCRAPER_DIR.'includes/class-hamnna-report.php';
add_filter('cron_schedules',['Hamnna_Scraper','cron_schedules']);
register_activation_hook(__FILE__,['Hamnna_Scraper','activate']);register_activation_hook(__FILE__,['Hamnna_Image_Optimizer','activate']);
register_deactivation_hook(__FILE__,['Hamnna_Scraper','deactivate']);register_deactivation_hook(__FILE__,['Hamnna_Image_Optimizer','deactivate']);
add_action('init',['Hamnna_Scraper','ensure_schedule']);
add_action('hamnna_scraper_cron',['Hamnna_Scraper','run_cron']);
add_action('admin_menu',['Hamnna_Scraper','admin_menu']);add_action('admin_init',['Hamnna_Scraper','admin_init']);
add_action('admin_post_hamnna_scraper_run',['Hamnna_Scraper','manual_run']);add_action('admin_post_hamnna_scraper_clear_logs',['Hamnna_Scraper','clear_logs']);
add_action('admin_post_hamnna_scraper_reset_queue',['Hamnna_Scraper','reset_queue']);add_action('admin_post_hamnna_price_sync',['Hamnna_Scraper','price_sync_action']);
