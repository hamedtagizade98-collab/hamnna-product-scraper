<?php
if (!defined('ABSPATH')) exit;

final class Hamnna_Price_Sync_Admin {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu'], 20);
        add_action('admin_post_hamnna_price_sync_advanced', [__CLASS__, 'run']);
        add_action('admin_post_hamnna_price_sync_catalog', [__CLASS__, 'catalog']);
        add_action('admin_post_hamnna_price_sync_settings', [__CLASS__, 'save']);
    }

    public static function menu() {
        add_submenu_page(
            'hamnna-scraper',
            'همگام‌سازی هوشمند قیمت',
            'همگام‌سازی هوشمند قیمت',
            'manage_woocommerce',
            'hamnna-price-sync',
            [__CLASS__, 'page']
        );
    }

    private static function nonce($action) {
        return wp_nonce_field($action, '_hamnna_nonce', true, false);
    }

    public static function page() {
        if (!current_user_can('manage_woocommerce')) return;
        $s = Hamnna_Price_Sync::settings();
        $logs = get_option(Hamnna_Price_Sync::LOG, []);
        $catalog = get_transient(Hamnna_Price_Sync::CATALOG);
        $next = wp_next_scheduled('hamnna_price_sync_cron');
        echo '<div class="wrap" dir="rtl"><style>
        .hs{max-width:1350px}.hs-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin:18px 0}
        .hs-card,.hs-panel{background:#fff;border:1px solid #d9e2d9;border-radius:16px;padding:20px;box-shadow:0 5px 20px rgba(86,159,96,.08)}
        .hs-card b{display:block;font-size:24px;color:#569f60;margin-top:7px}.hs-panel{margin-top:18px}
        .hs-btn{background:#569f60!important;border-color:#569f60!important;color:#fff!important}.hs-note{background:#f7fbf7;border-right:4px solid #569f60;padding:14px;border-radius:10px}
        .hs-table{width:100%;border-collapse:collapse}.hs-table th,.hs-table td{padding:10px;border-bottom:1px solid #eee;text-align:right}
        input[type=number]{max-width:110px}.hs-danger{color:#a00}
        </style><div class="hs"><h1>همگام‌سازی هوشمند قیمت همنا</h1>
        <div class="hs-note"><b>مبدأ:</b> hamnna.ir &nbsp; ← &nbsp; <b>مقصد:</b> hamnna.com<br>
        موتور تطبیق از URL/ID منبع، SKU، نام فارسی، slug، برند، حجم و شباهت متنی استفاده می‌کند و برای تطبیق مبهم قیمت را تغییر نمی‌دهد.</div>
        <div class="hs-grid">
          <div class="hs-card">وضعیت<b>'.($s['enabled']?'فعال 🟢':'خاموش 🔴').'</b></div>
          <div class="hs-card">فاصله اجرا<b>'.esc_html($s['interval']).' دقیقه</b></div>
          <div class="hs-card">حداقل اطمینان<b>'.esc_html($s['min_score']).'</b></div>
          <div class="hs-card">فاصله از گزینه دوم<b>'.esc_html($s['min_margin']).'</b></div>
          <div class="hs-card">کاتالوگ منبع<b>'.(is_array($catalog)?count($catalog):'هنوز ساخته نشده').'</b></div>
          <div class="hs-card">اجرای بعدی<b>'.($next?esc_html(wp_date('Y-m-d H:i',$next)):'—').'</b></div>
        </div>
        <div class="hs-panel"><h2>کنترل</h2>
        <p><a class="button hs-btn" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=hamnna_price_sync_advanced'),'hamnna_price_sync_advanced')).'">اجرای همگام‌سازی</a>
        <a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=hamnna_price_sync_catalog'),'hamnna_price_sync_catalog')).'">بازسازی کاتالوگ مبدا</a></p>
        <p>برای اولین اجرا بهتر است ابتدا «بازسازی کاتالوگ مبدا» و سپس همگام‌سازی را اجرا کنید.</p></div>
        <div class="hs-panel"><h2>تنظیمات</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">
        <input type="hidden" name="action" value="hamnna_price_sync_settings">'.self::nonce('hamnna_price_sync_settings').'
        <table class="form-table"><tr><th>فعال‌سازی</th><td><input type="checkbox" name="enabled" value="1" '.checked($s['enabled'],1,false).'></td></tr>
        <tr><th>حداقل امتیاز Match</th><td><input type="number" name="min_score" min="70" max="100" value="'.esc_attr($s['min_score']).'"></td></tr>
        <tr><th>حداقل اختلاف با Match دوم</th><td><input type="number" name="min_margin" min="1" max="30" value="'.esc_attr($s['min_margin']).'"></td></tr>
        <tr><th>تعداد محصول در هر اجرا</th><td><input type="number" name="batch" min="1" max="200" value="'.esc_attr($s['batch']).'"></td></tr>
        <tr><th>مدت کش کاتالوگ (ثانیه)</th><td><input type="number" name="catalog_ttl" min="60" max="3600" value="'.esc_attr($s['catalog_ttl']).'"></td></tr>
        <tr><th>حالت آزمایشی</th><td><label><input type="checkbox" name="dry_run" value="1" '.checked($s['dry_run'],1,false).'> قیمت‌ها تغییر نکنند و فقط گزارش تطبیق ساخته شود.</label></td></tr>
        </table><p><button class="button hs-btn" type="submit">ذخیره تنظیمات</button></p></form></div>
        <div class="hs-panel"><h2>آخرین گزارش‌ها</h2><table class="hs-table"><thead><tr><th>زمان</th><th>بررسی</th><th>Match</th><th>تغییر</th><th>بدون تغییر</th><th>مبهم</th><th>قفل</th><th>خطا</th></tr></thead><tbody>';
        foreach(array_slice(is_array($logs)?$logs:[],0,30) as $x) {
            $r=$x['result']??[];
            echo '<tr><td>'.esc_html($x['time']??'').'</td><td>'.esc_html($r['checked']??0).'</td><td>'.esc_html($r['matched']??0).'</td><td>'.esc_html($r['updated']??0).'</td><td>'.esc_html($r['unchanged']??0).'</td><td>'.esc_html($r['ambiguous']??0).'</td><td>'.esc_html($r['locked']??0).'</td><td>'.esc_html($r['errors']??0).'</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    public static function run() {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('hamnna_price_sync_advanced','_hamnna_nonce')) wp_die('دسترسی غیرمجاز');
        Hamnna_Price_Sync::sync_all();
        wp_safe_redirect(admin_url('admin.php?page=hamnna-price-sync'));
        exit;
    }

    public static function catalog() {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('hamnna_price_sync_catalog','_hamnna_nonce')) wp_die('دسترسی غیرمجاز');
        Hamnna_Price_Sync::refresh_catalog();
        wp_safe_redirect(admin_url('admin.php?page=hamnna-price-sync'));
        exit;
    }

    public static function save() {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('hamnna_price_sync_settings','_hamnna_nonce')) wp_die('دسترسی غیرمجاز');
        $old=Hamnna_Price_Sync::settings();
        $new=$old;
        $new['enabled']=empty($_POST['enabled'])?0:1;
        $new['min_score']=max(70,min(100,absint($_POST['min_score']??88)));
        $new['min_margin']=max(1,min(30,absint($_POST['min_margin']??7)));
        $new['batch']=max(1,min(200,absint($_POST['batch']??50)));
        $new['catalog_ttl']=max(60,min(3600,absint($_POST['catalog_ttl']??600)));
        $new['dry_run']=empty($_POST['dry_run'])?0:1;
        update_option('hamnna_price_sync_settings',$new,false);
        wp_clear_scheduled_hook('hamnna_price_sync_cron');
        if($new['enabled']) wp_schedule_event(time()+60,'hamnna_5min','hamnna_price_sync_cron');
        wp_safe_redirect(admin_url('admin.php?page=hamnna-price-sync'));
        exit;
    }
}
