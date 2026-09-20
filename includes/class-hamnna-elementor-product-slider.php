<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('Hamnna_Elementor_Product_Slider_Widget') && class_exists('Elementor\\Widget_Base')) {
    class Hamnna_Elementor_Product_Slider_Widget extends \\Elementor\\Widget_Base {
        public function get_name() {
            return 'hamnna_product_slider';
        }

        public function get_title() {
            return 'Hamnna Product Slider';
        }

        public function get_icon() {
            return 'eicon-products';
        }

        public function get_categories() {
            return array('general');
        }

        public function get_keywords() {
            return array('hamnna', 'woocommerce', 'product', 'slider', 'محصولات', 'اسلایدر');
        }

        protected function register_controls() {
            $this->start_controls_section(
                'hm_content',
                array(
                    'label' => 'اسلایدر محصولات همنا',
                    'tab'   => \\Elementor\\Controls_Manager::TAB_CONTENT,
                )
            );

            $this->add_control(
                'hm_info',
                array(
                    'type' => \\Elementor\\Controls_Manager::RAW_HTML,
                    'raw'  => '<div style="line-height:1.9">تنظیمات تب‌ها، مرتب‌سازی، لینک «مشاهده همه»، تعداد محصولات و حرکت خودکار از مسیر <strong>Hamnna Product Scraper → اسلایدر محصولات</strong> مدیریت می‌شود. موبایل همیشه ۳ محصول همزمان نمایش می‌دهد.</div>',
                )
            );

            $this->end_controls_section();
        }

        protected function render() {
            if (shortcode_exists('hamnna_product_slider')) {
                echo do_shortcode('[hamnna_product_slider]');
            } else {
                echo '<div>اسلایدر محصولات Hamnna در دسترس نیست.</div>';
            }
        }
    }
}
