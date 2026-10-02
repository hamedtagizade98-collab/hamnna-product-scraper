<?php
/**
 * Plugin Name: Hamnna Live Price Sync
 * Description: Secure, autonomous price synchronization from hamnna.ir to hamnna.com with independent admin panel, token authentication, multi-signal matching, batching, locks, audit logs and safe guards.
 * Version: 3.1.0
 * Author: Hamnna
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */
if (!defined('ABSPATH')) exit;

final class Hamnna_Live_Price_Sync {
    const VER='3.1.0';
    const OPT='hamnna_lps_settings';
    const LOG='hamnna_lps_log';
    const LOCK='hamnna_lps_lock';
    const CURSOR='hamnna_lps_cursor';
    const CATALOG='hamnna_lps_catalog';
    const CRON='hamnna_lps_cron';

    public static function init(){
        add_filter('cron_schedules', [__CLASS__,'schedules']);
        add_action(self::CRON,[__CLASS__,'cron']);
        add_action('admin_menu',[__CLASS__,'menu']);
        add_action('admin_init',[__CLASS__,'settings']);
        add_action('admin_post_hamnna_lps_run',[__CLASS__,'manual_run']);
        add_action('admin_post_hamnna_lps_test',[__CLASS__,'test_connection']);
        add_action('admin_post_hamnna_lps_rebuild',[__CLASS__,'rebuild_catalog']);
        add_action('admin_post_hamnna_lps_clear',[__CLASS__,'clear_log']);
    }
    public static function defaults(){
        return [
            'enabled'=>0,
            'source'=>'https://hamnna.ir',
            'endpoint'=>'/wp-json/wc/v3/products',
            'auth'=>'bearer',
            'token'=>'',
            'batch'=>25,
            'interval'=>5,
            'min_score'=>90,
            'min_margin'=>8,
            'max_change'=>50,
            'currency'=>'auto',
            'rial_ratio'=>10,
            'dry_run'=>1,
            'only_changed'=>1,
            'sync_sale'=>1,
            'include_variations'=>1,
        ];
    }
    public static function get(){
        return wp_parse_args(get_option(self::OPT,[]),self::defaults());
    }
    public static function schedules($s){
        $s['hamnna_1min']=['interval'=>60,'display'=>'Hamnna - Every minute'];
        $s['hamnna_5min']=['interval'=>300,'display'=>'Hamnna - Every 5 minutes'];
        return $s;
    }
    public static function activate(){
        if(!get_option(self::OPT)) add_option(self::OPT,self::defaults());
        self::schedule();
    }
    public static function schedule(){
        wp_clear_scheduled_hook(self::CRON);
        $o=self::get();
        $rec=($o['interval']<=1?'hamnna_1min':'hamnna_5min');
        wp_schedule_event(time()+30,$rec,self::CRON);
    }
    public static function deactivate(){ wp_clear_scheduled_hook(self::CRON); }
    public static function menu(){
        add_menu_page('همگام‌سازی قیمت همنا','همگام‌سازی قیمت همنا','manage_woocommerce','hamnna-live-price-sync',[__CLASS__,'page'],'dashicons-update',56);
    }
    public static function settings(){
        register_setting(self::OPT,self::OPT,[__CLASS__,'sanitize']);
    }
    public static function sanitize($in){
        $d=self::defaults(); $o=[];
        $o['enabled']=empty($in['enabled'])?0:1;
        $o['source']=esc_url_raw(trim($in['source']??$d['source']));
        $o['endpoint']=sanitize_text_field($in['endpoint']??$d['endpoint']);
        $o['auth']=in_array(($in['auth']??'bearer'),['bearer','header'],true)?$in['auth']:'bearer';
        $old=self::get()['token'];
        $new=trim((string)($in['token']??''));
        $o['token']=$new!==''?$new:$old;
        $o['batch']=max(1,min(100,(int)($in['batch']??25)));
        $o['interval']=((int)($in['interval']??5)===1?1:5);
        $o['min_score']=max(70,min(100,(int)($in['min_score']??90)));
        $o['min_margin']=max(0,min(30,(int)($in['min_margin']??8)));
        $o['max_change']=max(1,min(500,(float)($in['max_change']??50)));
        $o['currency']=in_array(($in['currency']??'auto'),['auto','toman','rial'],true)?$in['currency']:'auto';
        $o['rial_ratio']=max(1,min(1000,(int)($in['rial_ratio']??10)));
        foreach(['dry_run','only_changed','sync_sale','include_variations'] as $k) $o[$k]=empty($in[$k])?0:1;
        if($o['interval']!==self::get()['interval']) self::schedule();
        return $o;
    }
    private static function admin(){ return current_user_can('manage_woocommerce'); }
    private static function nonce($action){ return wp_nonce_field($action,'_wpnonce',true,false); }
    private static function headers($o){
        $h=['Accept'=>'application/json','User-Agent'=>'Hamnna-Live-Price-Sync/'.self::VER];
        if($o['auth']==='bearer' && $o['token']!=='') $h['Authorization']='Bearer '.$o['token'];
        elseif($o['auth']==='header' && $o['token']!=='') $h['X-API-Key']=$o['token'];
        return $h;
    }
    private static function api_url($o,$path=''){
        $base=rtrim($o['source'],'/');
        $ep='/'.ltrim($o['endpoint'],'/');
        return $base.$ep.$path;
    }
    private static function request($url,$o,$args=[]){
        $args=wp_parse_args($args,['timeout'=>25,'headers'=>self::headers($o),'sslverify'=>true]);
        return wp_remote_get($url,$args);
    }
    private static function decode($r){
        if(is_wp_error($r)) return new WP_Error('http',$r->get_error_message());
        $code=wp_remote_retrieve_response_code($r); $body=wp_remote_retrieve_body($r);
        if($code<200||$code>=300) return new WP_Error('http_'.$code,'HTTP '.$code.': '.substr(wp_strip_all_tags($body),0,300));
        $j=json_decode($body,true);
        return is_array($j)?$j:new WP_Error('json','پاسخ JSON معتبر نیست');
    }
    public static function test_connection(){
        if(!self::admin()||!check_admin_referer('hamnna_lps_test')) wp_die('دسترسی غیرمجاز');
        $o=self::get(); $r=self::request(self::api_url($o,'?per_page=1&page=1'),$o);
        $j=self::decode($r);
        if(is_wp_error($j)) self::flash('error','اتصال ناموفق: '.$j->get_error_message());
        else self::flash('success','اتصال موفق است و API پاسخ معتبر داد.');
        wp_safe_redirect(admin_url('admin.php?page=hamnna-live-price-sync')); exit;
    }
    public static function rebuild_catalog(){
        if(!self::admin()||!check_admin_referer('hamnna_lps_rebuild')) wp_die('دسترسی غیرمجاز');
        delete_transient(self::CATALOG); self::flash('success','کش کاتالوگ مبدأ پاک شد. اجرای بعدی کاتالوگ را دوباره می‌سازد.');
        wp_safe_redirect(admin_url('admin.php?page=hamnna-live-price-sync')); exit;
    }
    private static function source_catalog($force=false){
        if(!$force){$c=get_transient(self::CATALOG);if(is_array($c))return $c;}
        $o=self::get(); $all=[]; $page=1;
        do{
            $url=self::api_url($o,'?per_page=100&page='.$page);
            $j=self::decode(self::request($url,$o,['timeout'=>30]));
            if(is_wp_error($j)) { self::log('catalog_error',$j->get_error_message()); break; }
            if(!is_array($j)||!$j) break;
            foreach($j as $p){
                if(!is_array($p)) continue;
                $all[]=[
                    'id'=>(int)($p['id']??0),
                    'name'=>(string)($p['name']??''),
                    'slug'=>(string)($p['slug']??''),
                    'sku'=>(string)($p['sku']??''),
                    'price'=>(string)($p['price']??''),
                    'regular_price'=>(string)($p['regular_price']??''),
                    'sale_price'=>(string)($p['sale_price']??''),
                    'type'=>(string)($p['type']??'simple'),
                    'permalink'=>(string)($p['permalink']??''),
                    'status'=>(string)($p['status']??''),
                    'attributes'=>is_array($p['attributes']??null)?$p['attributes']:[],
                ];
            }
            $page++;
        }while(count($j)===100 && $page<=200);
        if($all) set_transient(self::CATALOG,$all,5*MINUTE_IN_SECONDS);
        return $all;
    }
    private static function norm($s){
        $s=wp_strip_all_tags((string)$s);
        $map=['ي'=>'ی','ى'=>'ی','ك'=>'ک','ۀ'=>'ه','ة'=>'ه','ؤ'=>'و','إ'=>'ا','أ'=>'ا','ٱ'=>'ا'];
        $s=strtr($s,$map);
        $fa=['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];$en=['0','1','2','3','4','5','6','7','8','9'];$s=str_replace($fa,$en,$s);
        $s=preg_replace('/[^\p{L}\p{N}]+/u',' ',$s);
        return trim(preg_replace('/\s+/u',' ',mb_strtolower($s,'UTF-8')));
    }
    private static function tokens($s){$s=self::norm($s);return $s?array_values(array_unique(preg_split('/\s+/u',$s))):[];}
    private static function volume($s){
        if(preg_match('/(?:^|\D)(\d+(?:[\.,]\d+)?)\s*(?:ml|میل|میلی.?لیتر|cc)\b/ui',(string)$s,$m)) return (float)str_replace(',','.',$m[1]);
        return 0;
    }
    private static function score($d,$s){
        $score=0; $why=[];
        if($d['sku']!==''&&$s['sku']!==''&&self::norm($d['sku'])===self::norm($s['sku'])){$score+=60;$why[]='SKU';}
        if($d['id']&&$s['id']&&$d['id']===$s['id']){$score+=65;$why[]='ID';}
        if($d['slug']!==''&&$s['slug']!==''&&self::norm($d['slug'])===self::norm($s['slug'])){$score+=35;$why[]='slug';}
        $dn=self::norm($d['name']);$sn=self::norm($s['name']);
        similar_text($dn,$sn,$pct); $score+=min(38,$pct*.38); if($pct>=70)$why[]='name';
        $dt=self::tokens($d['name']);$st=array_flip(self::tokens($s['name']));$common=0;
        foreach($dt as $t) if(isset($st[$t])&&mb_strlen($t,'UTF-8')>=2)$common++;
        if($dt) $score+=min(22,22*$common/max(1,count($dt)));
        $dv=self::volume($d['name']);$sv=self::volume($s['name']);if($dv&&$sv&&abs($dv-$sv)<.01){$score+=8;$why[]='volume';}
        return [min(100,$score),implode(', ',$why)];
    }
    private static function destination_products($offset,$limit){
        $q=new WP_Query(['post_type'=>'product','post_status'=>['publish','private','draft'],'posts_per_page'=>$limit,'offset'=>$offset,'orderby'=>'ID','order'=>'ASC','fields'=>'ids']);
        return $q->posts;
    }
    private static function signals($id){
        $p=wc_get_product($id); if(!$p)return null;
        return ['id'=>$p->get_id(),'name'=>$p->get_name(),'slug'=>$p->get_slug(),'sku'=>$p->get_sku(),'url'=>get_permalink($p->get_id()),'regular'=>$p->get_regular_price(),'sale'=>$p->get_sale_price()];
    }
    private static function source_price($s){
        $v=$s['price']!==''?$s['price']:($s['sale_price']!==''?$s['sale_price']:$s['regular_price']);
        $v=(float)preg_replace('/[^0-9.\-]/','',str_replace(',','.',$v));
        if($v<=0)return 0;
        $o=self::get();
        if($o['currency']==='rial') $v=$v/$o['rial_ratio'];
        return $v;
    }
    private static function find_match($d,$catalog){
        $best=null;$second=null;
        foreach($catalog as $s){
            [$sc,$why]=self::score($d,$s);
            if($best===null||$sc>$best['score']){$second=$best;$best=['source'=>$s,'score'=>$sc,'why'=>$why];}
            elseif($second===null||$sc>$second['score'])$second=['source'=>$s,'score'=>$sc,'why'=>$why];
        }
        if(!$best)return null;
        $margin=$second?$best['score']-$second['score']:100;
        $o=self::get();
        if($best['score']<$o['min_score']||$margin<$o['min_margin']) return ['ambiguous'=>true,'best'=>$best,'margin'=>$margin];
        return ['ambiguous'=>false,'best'=>$best,'margin'=>$margin];
    }
    public static function cron(){ if(self::get()['enabled']) self::sync(); }
    public static function manual_run(){
        if(!self::admin()||!check_admin_referer('hamnna_lps_run')) wp_die('دسترسی غیرمجاز');
        $r=self::sync(true);
        self::flash($r['error']?'error':'success',$r['message']);
        wp_safe_redirect(admin_url('admin.php?page=hamnna-live-price-sync'));exit;
    }
    public static function sync($manual=false){
        if(get_transient(self::LOCK))return ['error'=>true,'message'=>'یک همگام‌سازی دیگر در حال اجراست.'];
        set_transient(self::LOCK,1,120);
        try{
            $o=self::get();$catalog=self::source_catalog();
            if(!$catalog){return ['error'=>true,'message'=>'کاتالوگ مبدأ خالی است یا API در دسترس نیست.'];}
            $offset=max(0,(int)get_option(self::CURSOR,0));$ids=self::destination_products($offset,$o['batch']);
            if(!$ids){$offset=0;$ids=self::destination_products(0,$o['batch']);}
            $changed=0;$matched=0;$amb=0;$skipped=0;$errors=0;
            foreach($ids as $id){
                $d=self::signals($id);if(!$d)continue;
                $lock=(bool)get_post_meta($id,'_hamnna_price_manual_lock',true);if($lock){$skipped++;continue;}
                $m=self::find_match($d,$catalog);
                if(!$m||$m['ambiguous']){$amb++;continue;}
                $matched++;$s=$m['best']['source'];$new=self::source_price($s);if($new<=0){$errors++;continue;}
                $p=wc_get_product($id);$old=(float)$p->get_regular_price();$base=$old>0?$old:(float)$p->get_price();
                if($base>0&&abs($new-$base)/$base*100>$o['max_change']){$skipped++;self::log('guard',['product'=>$id,'old'=>$base,'new'=>$new,'source'=>$s['id']]);continue;}
                if($o['only_changed']&&abs($new-$base)<0.001){continue;}
                if(!$o['dry_run']){
                    try{
                        $p->set_regular_price((string)$new);
                        if($o['sync_sale'] && $s['sale_price']!=='') $p->set_sale_price((string)$new);
                        elseif($p->get_sale_price()!=='' && !$o['sync_sale']) { /* preserve sale */ }
                        $p->save();
                        update_post_meta($id,'_hamnna_source_product_id',$s['id']);
                        update_post_meta($id,'_hamnna_source_url',$s['permalink']);
                        update_post_meta($id,'_hamnna_last_price',$new);
                        update_post_meta($id,'_hamnna_last_sync',current_time('mysql'));
                    }catch(Throwable $e){$errors++;continue;}
                }
                $changed++;self::log('price_change',['product'=>$id,'source'=>$s['id'],'old'=>$base,'new'=>$new,'dry_run'=>(int)$o['dry_run']]);
            }
            $next=$offset+count($ids);$total=(int)wp_count_posts('product')->publish;
            update_option(self::CURSOR,$next,false);
            $done=$next>=max(1,$total);
            if($done)update_option(self::CURSOR,0,false);
            $msg='انجام شد | بررسی: '.count($ids).' | تطبیق: '.$matched.' | تغییر/پیشنهاد: '.$changed.' | مبهم: '.$amb.' | رد/قفل: '.$skipped.' | خطا: '.$errors;
            self::log('run',['message'=>$msg,'offset'=>$offset,'next'=>$done?0:$next]);
            return ['error'=>false,'message'=>$msg];
        }finally{delete_transient(self::LOCK);}
    }
    private static function log($type,$data){
        $logs=get_option(self::LOG,[]);if(!is_array($logs))$logs=[];
        array_unshift($logs,['time'=>current_time('mysql'),'type'=>$type,'data'=>$data]);
        update_option(self::LOG,array_slice($logs,0,200),false);
    }
    public static function clear_log(){
        if(!self::admin()||!check_admin_referer('hamnna_lps_clear'))wp_die('دسترسی غیرمجاز');
        delete_option(self::LOG);self::flash('success','گزارش‌ها پاک شدند.');wp_safe_redirect(admin_url('admin.php?page=hamnna-live-price-sync'));exit;
    }
    private static function flash($type,$msg){set_transient('hamnna_lps_flash',['type'=>$type,'msg'=>$msg],30);}
    private static function flash_html(){ $f=get_transient('hamnna_lps_flash');delete_transient('hamnna_lps_flash');if($f)echo '<div class="notice notice-'.esc_attr($f['type']).'"><p>'.esc_html($f['msg']).'</p></div>'; }
    public static function page(){
        if(!self::admin())return;$o=self::get();$logs=get_option(self::LOG,[]);$next=wp_next_scheduled(self::CRON);
        echo '<div class="wrap" dir="rtl"><h1>همگام‌سازی قیمت همنا</h1>';self::flash_html();
        echo '<style>.hlps{max-width:1100px}.hlps .card{background:#fff;border:1px solid #ddd;border-radius:12px;padding:20px;margin:14px 0}.hlps input,.hlps select{min-width:320px;padding:8px}.hlps .row{display:grid;grid-template-columns:1fr 1fr;gap:18px}.hlps .badge{display:inline-block;padding:6px 12px;border-radius:20px;background:#eef7ef}.hlps table{width:100%;border-collapse:collapse}.hlps td,.hlps th{border-bottom:1px solid #eee;padding:9px;text-align:right}@media(max-width:800px){.hlps .row{grid-template-columns:1fr}}</style><div class="hlps">";
        echo '<div class="card"><h2>وضعیت</h2><p><span class="badge">'.($o['enabled']?'فعال':'غیرفعال').'</span> &nbsp; حالت: <b>'.($o['dry_run']?'آزمایشی':'واقعی').'</b></p><p>اجرای بعدی: '.($next?date_i18n('Y/m/d H:i',$next):'زمان‌بندی نشده').'</p></div>';
        echo '<div class="card"><h2>عملیات</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline">'.self::nonce('hamnna_lps_test').'<input type="hidden" name="action" value="hamnna_lps_test"><button class="button">تست اتصال</button></form> ';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline;margin-right:8px">'.self::nonce('hamnna_lps_rebuild').'<input type="hidden" name="action" value="hamnna_lps_rebuild"><button class="button">بازسازی کاتالوگ</button></form> ';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline;margin-right:8px">'.self::nonce('hamnna_lps_run').'<input type="hidden" name="action" value="hamnna_lps_run"><button class="button button-primary">همگام‌سازی الآن</button></form></div>';
        echo '<div class="card"><h2>اتصال مبدأ</h2><form method="post" action="options.php">'.settings_fields(self::OPT).'<div class="row">';
        $fields=[['source','آدرس مبدأ','text'],['endpoint','Endpoint API','text'],['token','API Token','password']];
        foreach($fields as $f)echo '<p><label><b>'.esc_html($f[1]).'</b><br><input type="'.$f[2].'" name="'.self::OPT.'['.$f[0].']" value="'.esc_attr($o[$f[0]]).'" autocomplete="new-password"></label></p>';
        echo '<p><label><b>روش احراز هویت</b><br><select name="'.self::OPT.'[auth]"><option value="bearer" '.selected($o['auth'],'bearer',false).'>Bearer Token</option><option value="header" '.selected($o['auth'],'header',false).'>X-API-Key</option></select></label></p>';
        echo '<p><label><input type="checkbox" name="'.self::OPT.'[enabled]" value="1" '.checked($o['enabled'],1,false).'> فعال‌سازی خودکار</label></p></div>';
        echo '<p><b>نکته امنیتی:</b> توکن در لاگ ذخیره نمی‌شود و در گزارش‌ها نمایش داده نمی‌شود. برای تغییر توکن، مقدار جدید را وارد و ذخیره کنید.</p></div>';
        echo '<div class="card"><h2>قواعد همگام‌سازی</h2><div class="row">';
        $nums=[['batch','تعداد محصول در هر اجرا'],['min_score','حداقل امتیاز تطبیق'],['min_margin','حداقل فاصله با تطبیق دوم'],['max_change','حداکثر درصد تغییر مجاز'],['rial_ratio','نسبت ریال به تومان']];
        foreach($nums as $f)echo '<p><label><b>'.esc_html($f[1]).'</b><br><input type="number" step="0.1" name="'.self::OPT.'['.$f[0].']" value="'.esc_attr($o[$f[0]]).'" form="hlps-form"></label></p>';
        echo '</div><p><label>واحد قیمت مبدأ <select name="'.self::OPT.'[currency]" form="hlps-form"><option value="auto">خودکار</option><option value="toman" '.selected($o['currency'],'toman',false).'>تومان</option><option value="rial" '.selected($o['currency'],'rial',false).'>ریال</option></select></label></p>';
        echo '<p><label><input type="checkbox" name="'.self::OPT.'[dry_run]" value="1" '.checked($o['dry_run'],1,false).' form="hlps-form"> حالت آزمایشی (بدون تغییر قیمت)</label></p><p><label><input type="checkbox" name="'.self::OPT.'[sync_sale]" value="1" '.checked($o['sync_sale'],1,false).' form="hlps-form"> همگام‌سازی قیمت فروش</label></p>';
        echo '<form id="hlps-form" method="post" action="options.php">'.settings_fields(self::OPT).'<input type="hidden" name="'.self::OPT.'[source]" value="'.esc_attr($o['source']).'"><input type="hidden" name="'.self::OPT.'[endpoint]" value="'.esc_attr($o['endpoint']).'"><input type="hidden" name="'.self::OPT.'[auth]" value="'.esc_attr($o['auth']).'"><input type="hidden" name="'.self::OPT.'[token]" value="'.esc_attr($o['token']).'"><input type="hidden" name="'.self::OPT.'[enabled]" value="'.(int)$o['enabled'].'"><input type="hidden" name="'.self::OPT.'[interval]" value="'.(int)$o['interval'].'"><input type="hidden" name="'.self::OPT.'[only_changed]" value="1"><input type="hidden" name="'.self::OPT.'[include_variations]" value="1"><p><button class="button button-primary">ذخیره تنظیمات</button></p></form></div>';
        echo '<div class="card"><h2>قفل دستی محصول</h2><p>برای جلوگیری از تغییر خودکار قیمت یک محصول، متای <code>_hamnna_price_manual_lock</code> را روی مقدار <code>1</code> قرار دهید.</p></div>';
        echo '<div class="card"><h2>آخرین گزارش‌ها</h2><table><tr><th>زمان</th><th>نوع</th><th>جزئیات</th></tr>';
        foreach(array_slice((array)$logs,0,30) as $l){$detail=is_scalar($l['data']??'')?$l['data']:wp_json_encode($l['data'],JSON_UNESCAPED_UNICODE);echo '<tr><td>'.esc_html($l['time']).'</td><td>'.esc_html($l['type']).'</td><td>'.esc_html($detail).'</td></tr>';}
        echo '</table><p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.self::nonce('hamnna_lps_clear').'<input type="hidden" name="action" value="hamnna_lps_clear"><button class="button">پاک‌کردن گزارش‌ها</button></form></p></div>';
        echo '<div class="card"><p><b>نسخه:</b> '.self::VER.' — برای اجرای واقعی، ابتدا «حالت آزمایشی» را چند اجرا بررسی کنید.</p></div></div></div>';
    }
}
register_activation_hook(__FILE__,['Hamnna_Live_Price_Sync','activate']);
register_deactivation_hook(__FILE__,['Hamnna_Live_Price_Sync','deactivate']);
Hamnna_Live_Price_Sync::init();
