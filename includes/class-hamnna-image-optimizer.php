<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Hamnna_Image_Optimizer {
    const CRON = 'hamnna_image_optimizer_cron';
    const BATCH = 25;
    const MAX_BYTES = 204800;

    public static function init() {
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        add_action('wp_generate_attachment_metadata', array(__CLASS__, 'optimize_attachment'), 20, 2);
        add_action('hamnna_image_optimizer_cron', array(__CLASS__, 'cron_run'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_post_hamnna_optimize_images', array(__CLASS__, 'manual_run'));
    }

    public static function cron_schedules($schedules) {
        $schedules['hamnna_5minutes'] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => 'Hamnna - Every 5 minutes',
        );
        return $schedules;
    }

    public static function activate() {
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 120, 'hamnna_5minutes', self::CRON);
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON);
    }

    public static function admin_menu() {
        add_submenu_page(
            'hamnna-scraper',
            'بهینه‌سازی تصاویر',
            'بهینه‌سازی تصاویر',
            'manage_woocommerce',
            'hamnna-image-optimizer',
            array(__CLASS__, 'page')
        );
    }

    public static function page() {
        if (!current_user_can('manage_woocommerce')) return;
        $count = self::count_unoptimized();
        echo '<div class="wrap" dir="rtl">';
        echo '<h1>بهینه‌سازی تصاویر همنا</h1>';
        echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:20px;max-width:850px">';
        echo '<h2>WebP + حداکثر ۲۰۰ کیلوبایت</h2>';
        echo '<p>افزونه تصاویر کتابخانه رسانه را به WebP تبدیل می‌کند و برای رسیدن به حداکثر <strong>۲۰۰KB</strong> کیفیت را مرحله‌ای کم می‌کند.</p>';
        echo '<p>تعداد تقریبی تصاویر پردازش‌نشده: <strong>'.esc_html($count).'</strong></p>';
        echo '<p><a class="button button-primary" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=hamnna_optimize_images'),'hamnna_optimize_images')).'">بهینه‌سازی ۲۵ تصویر بعدی</a></p>';
        echo '<p style="color:#666">بهینه‌سازی خودکار نیز هر ۵ دقیقه حداکثر ۲۵ تصویر را پردازش می‌کند.</p>';
        echo '</div></div>';
    }

    public static function manual_run() {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('hamnna_optimize_images')) wp_die('دسترسی غیرمجاز');
        self::process_batch(self::BATCH);
        wp_safe_redirect(admin_url('admin.php?page=hamnna-image-optimizer'));
        exit;
    }

    public static function cron_run() {
        self::process_batch(self::BATCH);
    }

    public static function optimize_attachment($metadata, $attachment_id) {
        self::optimize($attachment_id, $metadata);
        return wp_get_attachment_metadata($attachment_id) ?: $metadata;
    }

    private static function process_batch($limit) {
        $ids = get_posts(array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => array('image/jpeg','image/png','image/webp'),
            'posts_per_page' => absint($limit),
            'fields'         => 'ids',
            'meta_query'     => array(
                array('key' => '_hamnna_webp_optimized', 'compare' => 'NOT EXISTS')
            ),
        ));
        foreach ($ids as $id) {
            self::optimize($id, wp_get_attachment_metadata($id));
        }
    }

    private static function optimize($attachment_id, $metadata) {
        if (get_post_meta($attachment_id, '_hamnna_webp_optimized', true)) return false;
        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) return false;
        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) return false;

        $result = self::save_under_limit($editor, $file);
        if (is_wp_error($result)) return false;
        $new_file = $result['path'];

        if ($new_file !== $file && file_exists($file)) @unlink($file);
        update_attached_file($attachment_id, $new_file);
        wp_update_post(array('ID' => $attachment_id, 'post_mime_type' => 'image/webp'));

        $metadata = is_array($metadata) ? $metadata : array();
        $metadata['file'] = _wp_relative_upload_path($new_file);
        $metadata['mime_type'] = 'image/webp';

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $size_data) {
                if (empty($size_data['file'])) continue;
                $old_size = trailingslashit(dirname($file)) . $size_data['file'];
                if (!file_exists($old_size)) continue;
                $size_editor = wp_get_image_editor($old_size);
                if (is_wp_error($size_editor)) continue;
                $saved = self::save_under_limit($size_editor, $old_size);
                if (is_wp_error($saved)) continue;
                $new_size = $saved['path'];
                if ($new_size !== $old_size && file_exists($old_size)) @unlink($old_size);
                $metadata['sizes'][$size]['file'] = basename($new_size);
                $metadata['sizes'][$size]['mime-type'] = 'image/webp';
                $metadata['sizes'][$size]['mime'] = 'image/webp';
                $metadata['sizes'][$size]['filesize'] = filesize($new_size);
            }
        }

        update_post_meta($attachment_id, '_hamnna_webp_optimized', current_time('mysql'));
        wp_update_attachment_metadata($attachment_id, $metadata);
        clean_post_cache($attachment_id);
        return true;
    }

    private static function save_under_limit($editor, $source) {
        $quality = 82;
        $last = null;
        for ($i = 0; $i < 8; $i++) {
            $quality = max(35, 82 - ($i * 7));
            $saved = $editor->save(self::webp_path($source), 'image/webp', $quality);
            if (is_wp_error($saved)) return $saved;
            $last = $saved;
            if (!empty($saved['path']) && file_exists($saved['path']) && filesize($saved['path']) <= self::MAX_BYTES) return $saved;
        }
        return $last ?: new WP_Error('webp_failed', 'WebP conversion failed.');
    }

    private static function webp_path($source) {
        return preg_replace('/\.(jpe?g|png|gif|webp)$/i', '.webp', $source);
    }

    private static function count_unoptimized() {
        $q = new WP_Query(array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => array('image/jpeg','image/png','image/webp'),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => array(array('key'=>'_hamnna_webp_optimized','compare'=>'NOT EXISTS')),
        ));
        return (int) $q->found_posts;
    }
}

Hamnna_Image_Optimizer::init();
