<?php
if (!defined('ABSPATH')) exit;

class Hamnna_Elementor_Product_Slider {
    public static function init() {
        add_action('elementor/widgets/register', array(__CLASS__, 'register'));
    }

    public static function register($widgets_manager) {
        if (!class_exists('Elementor\\Widget_Base')) return;
        $widgets_manager->register(new Hamnna_Elementor_Product_Slider_Widget());
    }
}

class Hamnna_Elementor_Product_Slider_Widget extends \Elementor\Widget_Base {
    public function get_name() { return 'hamnna_product_slider'; }
    public function get_title() { return 'Hamnna Product Slider'; }
    public function get_icon() { return 'eicon-products'; }
    public function get_categories() { return array('general'); }
    public function get_keywords() { return array('hamnna','woocommerce','product','slider','محصولات','اسلایدر'); }

    protected function register_controls() {
        $this->start_controls_section('hm_content', array(
            'label' => 'اسلایدر محصولات همنا',
            'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
        ));
        $this->add_control('hm_info', array(
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw' => '<div style="line-height:1.9">تب‌ها، نوع مرتب‌سازی، لینک «مشاهده همه»، تعداد محصولات و حرکت خودکار از مسیر <strong>Hamnna Product Scraper → اسلایدر محصولات</strong> مدیریت می‌شود. موبایل حداقل ۳ محصول همزمان نمایش می‌دهد.</div>',
        ));
        $this->end_controls_section();
    }

    protected function render() {
        echo do_shortcode('[hamnna_product_slider]');
    }
}
