<?php
if (!defined('ABSPATH')) exit;

class Hamnna_Scraper {
    const OPT = 'hamnna_scraper_settings';
    const LOG = 'hamnna_scraper_logs';
    const LOCK = 'hamnna_scraper_lock';
    const CRON = 'hamnna_scraper_cron';

    public static function defaults() {
        return array(
            'source_url' => 'https://hamnna.ir',
            'products_url' => 'https://hamnna.ir/product/',
            'interval' => '3hours',
            'batch_size' => 20,
            'user_agent' => 'Hamnna-WooCommerce-Scraper/0.1',
            'enabled' => 1,
        );
    }

    public static function activate() {
        if (!get_option(self::OPT)) update_option(self::OPT, self::defaults());
        self::schedule();
        if (!get_option(self::LOG)) update_option(self::LOG, array());
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON);
        delete_transient(self::LOCK);
    }

    public static function schedule() {
        wp_clear_scheduled_hook(self::CRON);
        $s = self::settings();
        if (!empty($s['enabled'])) wp_schedule_event(time() + 60, 'hamnna_3hours', self::CRON);
    }

    public static function settings() {
        return wp_parse_args(get_option(self::OPT, array()), self::defaults());
    }

    public static function admin_init() {
        register_setting('hamnna_scraper', self::OPT, array(__CLASS__, 'sanitize_settings'));
    }

    public static function sanitize_settings($in) {
        $d = self::defaults();
        $out = $d;
        $out['source_url'] = esc_url_raw($in['source_url'] ?? $d['source_url']);
        $out['products_url'] = esc_url_raw($in['products_url'] ?? $d['products_url']);
        $out['batch_size'] = max(1, min(100, absint($in['batch_size'] ?? 20)));
        $out['enabled'] = empty($in['enabled']) ? 0 : 1;
        self::schedule();
        return $out;
    }

    public static function admin_menu() {
        add_menu_page('اسکرپر همنا', 'اسکرپر همنا', 'manage_woocommerce', 'hamnna-scraper', array(__CLASS__, 'admin_page'), 'dashicons-update', 56);
    }

    public static function admin_page() {
        if (!current_user_can('manage_woocommerce')) return;
        $s = self::settings();
        $logs = get_option(self::LOG, array());
        $next = wp_next_scheduled(self::CRON);
        ?>
        <div class="wrap">
        <h1>اسکرپر محصولات همنا</h1>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:18px 0">
            <?php self::card('وضعیت', !empty($s['enabled']) ? 'فعال 🟢' : 'خاموش 🔴'); ?>
            <?php self::card('اجرای بعدی', $next ? wp_date('Y-m-d H:i', $next) : '—'); ?>
            <?php self::card('آخرین اجرا', !empty($logs[0]['time']) ? esc_html($logs[0]['time']) : '—'); ?>
        </div>
        <p><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=hamnna_scraper_run'),'hamnna_run')); ?>">اجرای دستی الان</a>
        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=hamnna_scraper_clear_logs'),'hamnna_clear')); ?>">پاک کردن گزارش</a></p>
        <form method="post" action="options.php">
        <?php settings_fields('hamnna_scraper'); ?>
        <table class="form-table"><tr><th>آدرس منبع</th><td><input class="regular-text" name="<?php echo self::OPT; ?>[source_url]" value="<?php echo esc_attr($s['source_url']); ?>"></td></tr>
        <tr><th>آدرس بخش محصولات</th><td><input class="regular-text" name="<?php echo self::OPT; ?>[products_url]" value="<?php echo esc_attr($s['products_url']); ?>"><p class="description">برای شروع روی /product/ تنظیم شده است.</p></td></tr>
        <tr><th>تعداد در هر اجرا</th><td><input type="number" min="1" max="100" name="<?php echo self::OPT; ?>[batch_size]" value="<?php echo esc_attr($s['batch_size']); ?>"></td></tr>
        <tr><th>فعال</th><td><label><input type="checkbox" name="<?php echo self::OPT; ?>[enabled]" value="1" <?php checked($s['enabled'],1); ?>> اجرای خودکار</label></td></tr></table>
        <?php submit_button('ذخیره تنظیمات'); ?></form>
        <h2>گزارش آخرین اجراها</h2><table class="widefat striped"><thead><tr><th>زمان</th><th>بررسی</th><th>جدید</th><th>تکراری</th><th>ناموجود</th><th>خطا</th><th>پیام</th></tr></thead><tbody>
        <?php foreach (array_slice($logs,0,30) as $l): ?><tr><td><?php echo esc_html($l['time']??''); ?></td><td><?php echo esc_html($l['checked']??0); ?></td><td><?php echo esc_html($l['new']??0); ?></td><td><?php echo esc_html($l['existing']??0); ?></td><td><?php echo esc_html($l['unavailable']??0); ?></td><td><?php echo esc_html($l['errors']??0); ?></td><td><?php echo esc_html($l['message']??''); ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php
    }

    private static function card($title,$value){ echo '<div style="background:#fff;border:1px solid #ddd;border-radius:10px;padding:16px"><strong>'.esc_html($title).'</strong><div style="font-size:20px;margin-top:8px">'.esc_html($value).'</div></div>'; }

    public static function manual_run() {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('hamnna_run')) wp_die('دسترسی غیرمجاز');
        $r = self::run();
        wp_safe_redirect(add_query_arg(array('page'=>'hamnna-scraper','hamnna_result'=>rawurlencode($r['message'])), admin_url('admin.php'))); exit;
    }

    public static function clear_logs() {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('hamnna_clear')) wp_die('دسترسی غیرمجاز');
        update_option(self::LOG, array());
        wp_safe_redirect(admin_url('admin.php?page=hamnna-scraper')); exit;
    }

    public static function run_cron() { self::run(); }

    public static function run() {
        if (!class_exists('WooCommerce')) return self::save_log(array('message'=>'WooCommerce فعال نیست.'));
        if (get_transient(self::LOCK)) return self::save_log(array('message'=>'اجرای قبلی هنوز در حال انجام است.'));
        set_transient(self::LOCK, 1, 20 * MINUTE_IN_SECONDS);
        $s=self::settings(); $stats=array('checked'=>0,'new'=>0,'existing'=>0,'unavailable'=>0,'errors'=>0,'message'=>'');
        try {
            $urls=self::discover_product_urls($s['products_url'],$s['batch_size']);
            foreach($urls as $url){
                $stats['checked']++;
                $source_id=self::source_id($url);
                if(!$source_id){$stats['errors']++;continue;}
                if(self::existing_by_source($source_id)){ $stats['existing']++; continue; }
                $p=self::scrape_product($url,$s);
                if(is_wp_error($p)){ $stats['errors']++; self::log_error($url,$p->get_error_message()); continue; }
                if(!$p['available']){$stats['unavailable']++;continue;}
                $id=self::import_product($p,$source_id);
                if(is_wp_error($id)){ $stats['errors']++; self::log_error($url,$id->get_error_message()); } else $stats['new']++;
            }
            $stats['message']='اجرا با موفقیت انجام شد.';
        } catch(Exception $e){ $stats['errors']++; $stats['message']=$e->getMessage(); }
        delete_transient(self::LOCK);
        return self::save_log($stats);
    }

    private static function discover_product_urls($index,$limit){
        $html=self::get($index); if(is_wp_error($html)) return array();
        $dom=self::dom($html); $urls=array();
        foreach($dom->getElementsByTagName('a') as $a){$href=trim($a->getAttribute('href')); if(!$href)continue; $u=esc_url_raw(self::absolute_url($index,$href)); if(preg_match('~/product/(?:\d+/)?[^?#]*~i',$u)) $urls[$u]=true;}
        return array_slice(array_keys($urls),0,max(1,$limit));
    }

    private static function scrape_product($url,$s){
        $html=self::get($url); if(is_wp_error($html)) return $html; $dom=self::dom($html);
        $title=self::first_text($dom,array('h1','.product-title','.product-name','title'));
        $description=self::first_html($dom,array('.product-description','.description','.product-detail','article'));
        $price=self::first_text($dom,array('.price','.product-price','[class*=price]'));
        $imgs=array(); foreach($dom->getElementsByTagName('img') as $img){$src=$img->getAttribute('data-src')?:$img->getAttribute('src'); if($src)$imgs[]=self::absolute_url($url,$src);} $imgs=array_values(array_unique($imgs));
        $body=strtolower(wp_strip_all_tags($html));
        $unavailable=array('ناموجود','اتمام موجودی','تمام شده','نا موجود','out of stock','unavailable','sold out');
        $available=true; foreach($unavailable as $word){if(strpos($body,mb_strtolower($word,'UTF-8'))!==false){$available=false;break;}}
        $positive=array('افزودن به سبد','خرید محصول','موجود','add to cart','in stock'); foreach($positive as $word){if(strpos($body,mb_strtolower($word,'UTF-8'))!==false){$available=true;break;}}
        $content=self::clean_text($description); if(!$title) return new WP_Error('no_title','عنوان محصول پیدا نشد.');
        return array('url'=>$url,'title'=>$title,'description'=>$content,'price'=>self::number_price($price),'images'=>$imgs,'available'=>$available);
    }

    private static function import_product($p,$source_id){
        $product=new WC_Product_Simple(); $product->set_name($p['title']); $product->set_status('publish'); $product->set_catalog_visibility('visible'); $product->set_description($p['description']); $product->set_manage_stock(false); $product->set_stock_status('instock');
        if($p['price']!=='') $product->set_regular_price($p['price']);
        $product_id=$product->save();
        update_post_meta($product_id,'_hamnna_source_id',$source_id); update_post_meta($product_id,'_hamnna_source_url',esc_url_raw($p['url'])); update_post_meta($product_id,'_hamnna_imported_at',current_time('mysql'));
        if(!empty($p['images'])){ $att=self::download_image($p['images'][0],$product_id); if($att) set_post_thumbnail($product_id,$att); }
        return $product_id;
    }

    private static function existing_by_source($source_id){$q=new WP_Query(array('post_type'=>'product','post_status'=>'any','posts_per_page'=>1,'fields'=>'ids','meta_key'=>'_hamnna_source_id','meta_value'=>$source_id)); return !empty($q->posts);}
    private static function source_id($url){if(preg_match('~/product/(\d+)~i',$url,$m))return $m[1]; $path=parse_url($url,PHP_URL_PATH); return md5($path?:$url);}

    private static function download_image($url,$post_id){require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/media.php';require_once ABSPATH.'wp-admin/includes/image.php'; $tmp=download_url($url,30); if(is_wp_error($tmp))return 0; $name=basename(parse_url($url,PHP_URL_PATH))?:'hamnna.jpg'; $file=array('name'=>sanitize_file_name($name),'tmp_name'=>$tmp); $id=media_handle_sideload($file,$post_id); if(is_wp_error($id)){@unlink($tmp);return 0;} return $id;}
    private static function get($url){$r=wp_remote_get($url,array('timeout'=>30,'redirection'=>5,'user-agent'=>self::settings()['user_agent'],'headers'=>array('Accept-Language'=>'fa-IR,fa;q=0.9,en;q=0.8'))); if(is_wp_error($r))return $r; $code=wp_remote_retrieve_response_code($r); if($code<200||$code>=400)return new WP_Error('http','HTTP '.$code.' for '.$url); return wp_remote_retrieve_body($r);}
    private static function dom($html){$d=new DOMDocument();libxml_use_internal_errors(true);$d->loadHTML('<?xml encoding="utf-8"?>'.$html);libxml_clear_errors();return $d;}
    private static function first_text($dom,$selectors){foreach($selectors as $sel){if($sel==='title'){$n=$dom->getElementsByTagName('title');if($n->length)return trim($n->item(0)->textContent);} $xp=self::xpath($dom,$sel);if($xp->length)return trim(preg_replace('/\s+/u',' ',$xp->item(0)->textContent));}return '';}
    private static function first_html($dom,$selectors){foreach($selectors as $sel){$xp=self::xpath($dom,$sel);if($xp->length){$n=$xp->item(0);$out='';foreach($n->childNodes as $c)$out.=$n->ownerDocument->saveHTML($c);return $out;}}return '';}
    private static function xpath($dom,$sel){$x=new DOMXPath($dom); if(strpos($sel,'[')===0||strpos($sel,'.')===0||strpos($sel,'#')===0||strpos($sel,'[')!==false){$parts=explode(' ',$sel);$last=end($parts);if(strpos($last,'.')===0)$q="//*[contains(concat(' ',normalize-space(@class),' '),' ".substr($last,1)." ')]";elseif(strpos($last,'#')===0)$q="//*[@id='".substr($last,1)."']";elseif(strpos($last,'[class*=')===0)$q="//*[contains(@class,".substr($last,7,-1).")]";else $q='//*';}else $q='//'.$sel; return $x->query($q);}
    private static function absolute_url($base,$href){if(strpos($href,'//')===0)return (is_ssl()?'https:':'http:').$href;if(preg_match('~^https?://~i',$href))return $href;$p=parse_url($base);$root=$p['scheme'].'://'.$p['host'];if(strpos($href,'/')===0)return $root.$href;return trailingslashit(dirname($p['path']??'/')).$href;}
    private static function clean_text($html){$html=preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is','',$html);return wp_kses_post(trim($html));}
    private static function number_price($s){$s=trim($s);if($s==='')return ''; $s=strtr($s,array('۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٬'=>'',','=>'',' تومان'=>'','تومان'=>'','ریال'=>'','ریال'=>'',' '=>'')); preg_match('/\d+(?:\.\d+)?/',$s,$m);return $m[0]??'';}
    private static function log_error($url,$msg){$logs=get_option(self::LOG,array());array_unshift($logs,array('time'=>current_time('mysql'),'message'=>substr($msg,0,300),'url'=>$url));update_option(self::LOG,array_slice($logs,0,100));}
    private static function save_log($stats){$logs=get_option(self::LOG,array());$stats['time']=current_time('mysql');array_unshift($logs,$stats);update_option(self::LOG,array_slice($logs,0,100));return $stats;}
}

add_filter('cron_schedules', function($s){$s['hamnna_3hours']=array('interval'=>3*HOUR_IN_SECONDS,'display'=>'هر ۳ ساعت');return $s;});
