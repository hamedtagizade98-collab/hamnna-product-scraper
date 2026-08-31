<?php
/**
 * Plugin Name: Hamnna SEO Links Manager
 * Description: Adds لینک داخلی and لینک خارجی tabs to WooCommerce products and exports/imports complete product SEO CSV data.
 * Version: 1.0.0
 * Author: Hamnna
 * Requires Plugins: woocommerce
 */
if (!defined('ABSPATH')) exit;

class Hamnna_SEO_Links_Manager {
    const INT_KEY = '_hamnna_internal_links';
    const EXT_KEY = '_hamnna_external_links';

    public function __construct() {
        add_filter('woocommerce_product_data_tabs', [$this, 'tabs']);
        add_action('woocommerce_product_data_panels', [$this, 'panels']);
        add_action('woocommerce_process_product_meta', [$this, 'save']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_hamnna_export_links_csv', [$this, 'export_csv']);
        add_action('admin_post_hamnna_import_links_csv', [$this, 'import_csv']);
        add_filter('manage_edit-product_columns', [$this, 'columns']);
        add_action('manage_product_posts_custom_column', [$this, 'column_content'], 10, 2);
    }

    public function tabs($tabs) {
        $tabs['hamnna_internal_links'] = [
            'label' => 'لینک داخلی',
            'target' => 'hamnna_internal_links_panel',
            'class' => ['show_if_simple','show_if_variable','show_if_external','show_if_grouped'],
            'priority' => 65
        ];
        $tabs['hamnna_external_links'] = [
            'label' => 'لینک خارجی',
            'target' => 'hamnna_external_links_panel',
            'class' => ['show_if_simple','show_if_variable','show_if_external','show_if_grouped'],
            'priority' => 66
        ];
        return $tabs;
    }

    private function value($key) { return get_post_meta(get_the_ID(), $key, true); }

    public function panels() { ?>
        <div id="hamnna_internal_links_panel" class="panel woocommerce_options_panel hidden">
            <div class="options_group">
                <p class="form-field">
                    <label for="hamnna_internal_links">لینک‌های داخلی</label>
                    <textarea class="short" style="height:180px" name="hamnna_internal_links" id="hamnna_internal_links" placeholder="هر لینک در یک خط&#10;https://hamnna.com/product/...&#10;https://hamnna.com/product-category/..."><?php echo esc_textarea($this->value(self::INT_KEY)); ?></textarea>
                    <span class="description">هر URL در یک خط. این مقدار در ستون «لینک داخلی» CSV ذخیره می‌شود.</span>
                </p>
            </div>
        </div>
        <div id="hamnna_external_links_panel" class="panel woocommerce_options_panel hidden">
            <div class="options_group">
                <p class="form-field">
                    <label for="hamnna_external_links">لینک‌های خارجی</label>
                    <textarea class="short" style="height:180px" name="hamnna_external_links" id="hamnna_external_links" placeholder="هر لینک در یک خط&#10;https://example.com/source&#10;https://brand.com/..."><?php echo esc_textarea($this->value(self::EXT_KEY)); ?></textarea>
                    <span class="description">هر URL در یک خط. این مقدار در ستون «لینک خارجی» CSV ذخیره می‌شود.</span>
                </p>
            </div>
        </div>
    <?php }

    public function save($post_id) {
        if (isset($_POST['hamnna_internal_links'])) update_post_meta($post_id, self::INT_KEY, $this->clean_urls(wp_unslash($_POST['hamnna_internal_links'])));
        if (isset($_POST['hamnna_external_links'])) update_post_meta($post_id, self::EXT_KEY, $this->clean_urls(wp_unslash($_POST['hamnna_external_links'])));
    }

    private function clean_urls($text) {
        $lines = preg_split('/\R+/', (string)$text);
        $out = [];
        foreach ($lines as $line) {
            $url = esc_url_raw(trim($line));
            if ($url) $out[] = $url;
        }
        return implode("\n", array_values(array_unique($out)));
    }

    public function menu() {
        add_submenu_page('woocommerce', 'Hamnna SEO Links', 'Hamnna SEO Links', 'manage_woocommerce', 'hamnna-seo-links', [$this, 'admin_page']);
    }

    public function admin_page() {
        if (!current_user_can('manage_woocommerce')) return;
        $count = wp_count_posts('product');
        $total = isset($count->publish) ? (int)$count->publish : 0; ?>
        <div class="wrap" dir="rtl">
            <h1>مدیریت لینک‌های سئو همنا</h1>
            <p>محصولات منتشرشده: <strong><?php echo esc_html($total); ?></strong></p>
            <div style="background:#fff;border:1px solid #ddd;border-radius:12px;padding:22px;max-width:900px">
                <h2>خروجی همه محصولات</h2>
                <p>CSV شامل اطلاعات محصول، توضیحات، جدول ویژگی/توضیحات کوتاه، سئو عنوان، متا، آلت، برچسب، URL، کلمه کلیدی و دو ستون جدید لینک داخلی و لینک خارجی است.</p>
                <p><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=hamnna_export_links_csv'), 'hamnna_export')); ?>">دانلود CSV همه محصولات</a></p>
                <hr>
                <h2>ورود CSV</h2>
                <p>با وارد کردن CSV، فقط ستون‌های لینک داخلی و لینک خارجی بر اساس URL محصول به‌روزرسانی می‌شوند.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="hamnna_import_links_csv">
                    <?php wp_nonce_field('hamnna_import'); ?>
                    <input type="file" name="hamnna_csv" accept=".csv" required>
                    <button class="button button-primary">وارد کردن لینک‌ها</button>
                </form>
            </div>
        </div>
    <?php }

    private function seo_meta($id, $type) {
        if ($type === 'title') {
            $v = get_post_meta($id, '_yoast_wpseo_title', true);
            return $v ?: get_post_meta($id, 'rank_math_title', true);
        }
        if ($type === 'desc') {
            $v = get_post_meta($id, '_yoast_wpseo_metadesc', true);
            return $v ?: get_post_meta($id, 'rank_math_description', true);
        }
        $v = get_post_meta($id, '_yoast_wpseo_focuskw', true);
        return $v ?: get_post_meta($id, 'rank_math_focus_keyword', true);
    }

    public function export_csv() {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('hamnna_export')) wp_die('دسترسی غیرمجاز');
        while (ob_get_level()) ob_end_clean();
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=hamnna-products-seo-links.csv');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['اسم محصول','توضیحات محصول','جدول ویژگی (توضیحات کوتاه)','سئو عنوان','متا','آلت عکس','برچسب','URL','کلمه کلیدی','لینک داخلی','لینک خارجی']);
        $q = new WP_Query(['post_type'=>'product','post_status'=>['publish','draft','pending','private'],'posts_per_page'=>-1,'orderby'=>'ID','order'=>'ASC','fields'=>'ids']);
        foreach ($q->posts as $id) {
            $product = wc_get_product($id); if (!$product) continue;
            $tags = wp_get_post_terms($id, 'product_tag', ['fields'=>'names']);
            $img_id = $product->get_image_id();
            $alt = $img_id ? get_post_meta($img_id, '_wp_attachment_image_alt', true) : '';
            $keyword = $this->seo_meta($id, 'keyword');
            fputcsv($out, [
                $product->get_name(), wp_strip_all_tags($product->get_description()), wp_strip_all_tags($product->get_short_description()),
                $this->seo_meta($id,'title'), $this->seo_meta($id,'desc'), $alt, implode(', ', $tags), get_permalink($id),
                is_array($keyword) ? implode(', ', $keyword) : $keyword,
                get_post_meta($id,self::INT_KEY,true), get_post_meta($id,self::EXT_KEY,true)
            ]);
        }
        fclose($out); exit;
    }

    public function import_csv() {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('hamnna_import')) wp_die('دسترسی غیرمجاز');
        if (empty($_FILES['hamnna_csv']['tmp_name'])) wp_die('فایل ارسال نشده است.');
        $fh = fopen($_FILES['hamnna_csv']['tmp_name'], 'r'); if (!$fh) wp_die('CSV قابل خواندن نیست.');
        $headers = fgetcsv($fh); if (!$headers) wp_die('CSV خالی است.');
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        $map=[]; foreach ($headers as $i=>$h) $map[trim($h)]=$i;
        if (!isset($map['URL'])) wp_die('ستون URL پیدا نشد.');
        $updated=0;
        while (($row=fgetcsv($fh))!==false) {
            $url = trim($row[$map['URL']] ?? ''); if (!$url) continue;
            $id=url_to_postid($url); if (!$id || get_post_type($id)!=='product') continue;
            if (isset($map['لینک داخلی'])) update_post_meta($id,self::INT_KEY,$this->clean_urls($row[$map['لینک داخلی']] ?? ''));
            if (isset($map['لینک خارجی'])) update_post_meta($id,self::EXT_KEY,$this->clean_urls($row[$map['لینک خارجی']] ?? ''));
            $updated++;
        }
        fclose($fh);
        wp_safe_redirect(add_query_arg(['page'=>'hamnna-seo-links','updated'=>$updated], admin_url('admin.php'))); exit;
    }

    public function columns($columns) { $columns['hamnna_internal']='لینک داخلی'; $columns['hamnna_external']='لینک خارجی'; return $columns; }
    public function column_content($column,$post_id) {
        if ($column==='hamnna_internal') echo get_post_meta($post_id,self::INT_KEY,true) ? '✓' : '—';
        if ($column==='hamnna_external') echo get_post_meta($post_id,self::EXT_KEY,true) ? '✓' : '—';
    }
}
new Hamnna_SEO_Links_Manager();