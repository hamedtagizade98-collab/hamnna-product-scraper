<?php
/**
 * Plugin Name: Hamnna Product Scraper for WooCommerce
 * Description: Imports available products from hamnna.ir into WooCommerce every 3 hours, skips existing source products, supports sitemap discovery, logs, manual runs and safe batching. Includes automatic WebP image optimization with a 200KB target.
 * Version: 1.1.0
 * Author: Hamnna
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;
define('HAMNNA_SCRAPER_VERSION','1.1.0');
define('HAMNNA_SCRAPER_FILE',__FILE__);
define('HAMNNA_SCRAPER_DIR',plugin_dir_path(__FILE__));
require_once HAMNNA_SCRAPER_DIR.'includes/class-hamnna-scraper.php';
require_once HAMNNA_SCRAPER_DIR.'includes/class-hamnna-image-optimizer.php';
add_filter('cron_schedules',array('Hamnna_Scraper','cron_schedules'));
register_activation_hook(__FILE__,array('Hamnna_Scraper','activate'));
register_activation_hook(__FILE__,array('Hamnna_Image_Optimizer','activate'));
register_deactivation_hook(__FILE__,array('Hamnna_Scraper','deactivate'));
register_deactivation_hook(__FILE__,array('Hamnna_Image_Optimizer','deactivate'));
add_action('hamnna_scraper_cron',array('Hamnna_Scraper','run_cron'));
add_action('admin_menu',array('Hamnna_Scraper','admin_menu'));
add_action('admin_init',array('Hamnna_Scraper','admin_init'));
add_action('admin_post_hamnna_scraper_run',array('Hamnna_Scraper','manual_run'));
add_action('admin_post_hamnna_scraper_clear_logs',array('Hamnna_Scraper','clear_logs'));
add_action('admin_post_hamnna_scraper_reset_queue',array('Hamnna_Scraper','reset_queue'));
