<?php
/**
 * Plugin Name: Hamnna Product Scraper for WooCommerce
 * Description: Imports products and autonomously synchronizes prices from hamnna.ir to WooCommerce using multi-signal product matching.
 * Version: 2.1.0
 * Author: Hamnna
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

define('HAMNNA_SCRAPER_VERSION', '2.1.0');
define('HAMNNA_SCRAPER_FILE', __FILE__);
define('HAMNNA_SCRAPER_DIR', plugin_dir_path(__FILE__));

require_once HAMNNA_SCRAPER_DIR . 'includes/class-hamnna-scraper.php';
require_once HAMNNA_SCRAPER_DIR . 'includes/class-hamnna-price-sync.php';
require_once HAMNNA_SCRAPER_DIR . 'includes/class-hamnna-price-sync-admin.php';
require_once HAMNNA_SCRAPER_DIR . 'includes/class-hamnna-report.php';
require_once HAMNNA_SCRAPER_DIR . 'includes/class-hamnna-speed.php';
require_once HAMNNA_SCRAPER_DIR . 'includes/class-hamnna-full-import.php';
require_once HAMNNA_SCRAPER_DIR . 'includes/class-hamnna-product-slider.php';

add_filter('cron_schedules', array('Hamnna_Scraper', 'cron_schedules'));
add_filter('cron_schedules', function($s) {
    $s['hamnna_5min'] = array('interval'=>5*MINUTE_IN_SECONDS,'display'=>'Hamnna - Every 5 minutes');
    return $s;
});

register_activation_hook(__FILE__, function() {
    Hamnna_Scraper::activate();
    if (!wp_next_scheduled('hamnna_price_sync_cron')) {
        wp_schedule_event(time()+120, 'hamnna_5min', 'hamnna_price_sync_cron');
    }
});
register_deactivation_hook(__FILE__, function() {
    Hamnna_Scraper::deactivate();
    wp_clear_scheduled_hook('hamnna_price_sync_cron');
});

add_action('init', array('Hamnna_Scraper', 'ensure_schedule'));
add_action('hamnna_scraper_cron', array('Hamnna_Scraper', 'run_cron'));
add_action('hamnna_price_sync_cron', array('Hamnna_Price_Sync', 'cron'));

add_action('admin_menu', array('Hamnna_Scraper', 'admin_menu'));
add_action('admin_init', array('Hamnna_Scraper', 'admin_init'));
add_action('admin_post_hamnna_scraper_clear_logs', array('Hamnna_Scraper', 'clear_logs'));
add_action('admin_post_hamnna_scraper_reset_queue', array('Hamnna_Scraper', 'reset_queue'));
add_action('admin_post_hamnna_price_sync', array('Hamnna_Scraper', 'price_sync_action'));

add_action('wp_ajax_hamnna_price_sync_now', function() {
    if (!current_user_can('manage_woocommerce') || !check_ajax_referer('hamnna_price_sync_now','nonce',false)) {
        wp_send_json_error(array('message'=>'دسترسی غیرمجاز'),403);
    }
    wp_send_json_success(Hamnna_Price_Sync::sync_all());
});

Hamnna_Price_Sync_Admin::init();
Hamnna_Product_Slider::init();

add_action('elementor/widgets/register', function ($widgets_manager) {
    $widget_file = HAMNNA_SCRAPER_DIR . 'includes/class-hamnna-elementor-product-slider.php';
    if (file_exists($widget_file)) require_once $widget_file;
    if (class_exists('Hamnna_Elementor_Product_Slider_Widget')) {
        $widgets_manager->register(new Hamnna_Elementor_Product_Slider_Widget());
    }
}, 5);
