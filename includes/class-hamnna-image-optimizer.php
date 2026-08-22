<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Hamnna_Image_Optimizer {
    const CRON = 'hamnna_image_optimizer_cron';
    const BATCH = 25;
    const MAX_BYTES = 204800; // 200 KiB
    const START_QUALITY = 92;
    const MIN_QUALITY = 82;
    const MAX_WIDTH = 1600;
    const MAX_HEIGHT = 1600;

    public static function init() {
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        add_action('wp_generate_attachment_metadata', array(__CLASS__, 'optimize_attachment'), 20, 2);
        add_action('hamnna_image_optimizer_cron', array(__CLASS__, 'cron_run'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_post_hamnna_optimize_images', array(__CLASS__, 'manual_run'));
    }

    public static function cron_schedules($schedules) {
        $schedules['hamnna_5minutes'] = array('interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Hamnna - Every 5 minutes');
        return $schedules;
    }

    public static function activate() {
        if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 120, 'hamnna_5minutes', self::CRON);
    }

    public static function deactivate() { wp_clear_scheduled_hook(self::CRON); }

    public static function admin_menu() {
        add_submenu_page('hamnna-scraper', 'بهینه‌سازی تصاویر', 'بهینه‌سازی تصاویر', 'manage_woocommerce', 'hamnna-image-optimizer', array(__CLASS__, 'page'));
    }

    public static function page() {
        if (!current_user_can('manage_woocommerce')) return;
        echo '<div class="wrap" dir="rtl">';
        echo '<h1>بهینه‌سازی تصاویر همنا</h1>';
        echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:20px;max-width:900px">';
        echo '<h2>WebP با کیفیت بصری بالا + حداکثر ۲۰۰KB</h2>';
        echo '<p>تصاویر به WebP تبدیل می‌شوند. افزونه ابتدا کیفیت بالا را حفظ می‌کند و فقط در صورت نیاز، اندازه تصویر را هوشمندانه کاهش می‌دهد تا حجم به زیر <strong>۲۰۰KB</strong> برسد.</p>';
        echo '<p><strong>هدف:</strong> بدون افت محسوس کیفیت برای نمایش سایت، نه فشرده‌سازی شدید.</p>';
        echo '<p>تعداد تقریبی تصاویر پردازش‌نشده: <strong>'.esc_html(self::count_unoptimized()).'</strong></p>';
        echo '<p><a class="button button-primary" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=hamnna_optimize_images'),'hamnna_optimize_images')).'">بهینه‌سازی ۲۵ تصویر بعدی</a></p>';
        echo '<p style="color:#666">پردازش خودکار هر ۵ دقیقه، حداکثر ۲۵ تصویر.</p>';
        echo '</div></div>';
    }

    public static function manual_run() {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('hamnna_optimize_images')) wp_die('دسترسی غیرمجاز');
        self::process_batch(self::BATCH);
        wp_safe_redirect(admin_url('admin.php?page=hamnna-image-optimizer'));
        exit;
    }

    public static function cron_run() { self::process_batch(self::BATCH); }

    public static function optimize_attachment($metadata, $attachment_id) {
        self::optimize($attachment_id, $metadata);
        return wp_get_attachment_metadata($attachment_id) ?: $metadata;
    }

    private static function process_batch($limit) {
        $ids = get_posts(array(
            'post_type' => 'attachment', 'post_status' => 'inherit',
            'post_mime_type' => array('image/jpeg','image/png','image/webp','image/gif'),
            'posts_per_page' => absint($limit), 'fields' => 'ids',
            'meta_query' => array(array('key' => '_hamnna_webp_optimized', 'compare' => 'NOT EXISTS')),
        ));
        foreach ($ids as $id) self::optimize($id, wp_get_attachment_metadata($id));
    }

    private static function optimize($attachment_id, $metadata) {
        if (get_post_meta($attachment_id, '_hamnna_webp_optimized', true)) return false;
        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) return false;
        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) return false;

        $result = self::save_high_quality_under_limit($editor, $file);
        if (is_wp_error($result)) return false;
        $new_file = $result['path'];

        if ($new_file !== $file && file_exists($file)) @unlink($file);
        update_attached_file($attachment_id, $new_file);
        wp_update_post(array('ID' => $attachment_id, 'post_mime_type' => 'image/webp'));

        $metadata = is_array($metadata) ? $metadata : array();
        $metadata['file'] = _wp_relative_upload_path($new_file);
        $metadata['mime_type'] = 'image/webp';
        $metadata['width'] = $result['width'];
        $metadata['height'] = $result['height'];
        $metadata['filesize'] = filesize($new_file);
        $metadata['sizes'] = array();

        update_post_meta($attachment_id, '_hamnna_webp_optimized', current_time('mysql'));
        update_post_meta($attachment_id, '_hamnna_webp_filesize', filesize($new_file));
        update_post_meta($attachment_id, '_hamnna_webp_quality', $result['quality']);
        wp_update_attachment_metadata($attachment_id, $metadata);
        clean_post_cache($attachment_id);
        return true;
    }

    private static function save_high_quality_under_limit($editor, $source) {
        $size = $editor->get_size();
        if (is_wp_error($size)) return $size;
        $width = (int) $size['width'];
        $height = (int) $size['height'];

        // First try WebP at very high quality. Only resize when necessary.
        $scale = min(1, self::MAX_WIDTH / max(1, $width), self::MAX_HEIGHT / max(1, $height));
        $target_w = max(1, (int) round($width * $scale));
        $target_h = max(1, (int) round($height * $scale));

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $working = wp_get_image_editor($source);
            if (is_wp_error($working)) return $working;
            if ($target_w !== $width || $target_h !== $height) {
                $resized = $working->resize($target_w, $target_h, false);
                if (is_wp_error($resized)) return $resized;
            }

            $quality = self::START_QUALITY;
            if ($attempt > 4) $quality = self::MIN_QUALITY;
            elseif ($attempt > 2) $quality = 88;

            $path = self::unique_webp_path($source);
            $saved = $working->save($path, 'image/webp', $quality);
            if (is_wp_error($saved)) return $saved;
            if (file_exists($path) && filesize($path) <= self::MAX_BYTES) {
                return array('path' => $path, 'width' => $target_w, 'height' => $target_h, 'quality' => $quality);
            }
            if (file_exists($path)) @unlink($path);

            // Preserve high visual quality by reducing dimensions before reducing quality.
            $target_w = max(320, (int) floor($target_w * 0.88));
            $target_h = max(320, (int) floor($target_h * 0.88));
        }
        return new WP_Error('webp_limit_failed', 'Could not reach the 200KB target without excessive quality loss.');
    }

    private static function unique_webp_path($source) {
        $base = preg_replace('/\.(jpe?g|png|gif|webp)$/i', '', $source);
        return $base . '.webp';
    }

    private static function count_unoptimized() {
        $q = new WP_Query(array(
            'post_type' => 'attachment', 'post_status' => 'inherit',
            'post_mime_type' => array('image/jpeg','image/png','image/webp','image/gif'),
            'posts_per_page' => 1, 'fields' => 'ids',
            'meta_query' => array(array('key'=>'_hamnna_webp_optimized','compare'=>'NOT EXISTS')),
        ));
        return (int) $q->found_posts;
    }
}

Hamnna_Image_Optimizer::init();
