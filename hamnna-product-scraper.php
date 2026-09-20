<?php
/**
 * Plugin Name: Hamnna Product Scraper for WooCommerce
 * Description: Imports available products from hamnna.ir every 10 minutes, with full manual catalog resync and exact price synchronization.
 * Version: 1.5.1
 * Author: Hamnna
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

define('HAMNNA_SCRAPER_VERSION', '1.5.1');
define('HAMNNA_SCRAPER_FILE', __FILE__);
define('HAMNNA_SCRAPER_DIR', plugin_dir_path(__FILE__));

require_once HAMNNA_SCRAPER_DIR . 'includes/class-hamnna-scraper.php';
require_once HAMNNA_SCRAPER_DIR . 'includes/class-hamnna-report.php';
require_once HAMNNA_SCRAPER_DIR . 'includes/class-hamnna-speed.php';
require_once HAMNNA_SCRAPER_DIR . 'includes/class-hamnna-full-import.php';
require_once HAMNNA_SCRAPER_DIR . 'includes/class-hamnna-product-slider.php';

add_filter('cron_schedules', array('Hamnna_Scraper', 'cron_schedules'));
register_activation_hook(__FILE__, array('Hamnna_Scraper', 'activate'));
register_deactivation_hook(__FILE__, array('Hamnna_Scraper', 'deactivate'));

add_action('init', array('Hamnna_Scraper', 'ensure_schedule'));
add_action('hamnna_scraper_cron', array('Hamnna_Scraper', 'run_cron'));
add_action('admin_menu', array('Hamnna_Scraper', 'admin_menu'));
add_action('admin_init', array('Hamnna_Scraper', 'admin_init'));
add_action('admin_post_hamnna_scraper_clear_logs', array('Hamnna_Scraper', 'clear_logs'));
add_action('admin_post_hamnna_scraper_reset_queue', array('Hamnna_Scraper', 'reset_queue'));
add_action('admin_post_hamnna_price_sync', array('Hamnna_Scraper', 'price_sync_action'));

Hamnna_Product_Slider::init();

add_action('elementor/widgets/register', function ($widgets_manager) {
    $widget_file = HAMNNA_SCRAPER_DIR . 'includes/class-hamnna-elementor-product-slider.php';

    if (file_exists($widget_file)) {
        require_once $widget_file;
    }

    if (class_exists('Hamnna_Elementor_Product_Slider_Widget')) {
        $widgets_manager->register(new Hamnna_Elementor_Product_Slider_Widget());
    }
}, 5);
