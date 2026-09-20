<?php
if (!defined('ABSPATH')) exit;

class Hamnna_Product_Slider {
    const OPTION = 'hamnna_product_slider_settings';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_shortcode('hamnna_product_slider', array(__CLASS__, 'shortcode'));
        add_action('wp_ajax_hamnna_slider_products', array(__CLASS__, 'ajax_products'));
        add_action('wp_ajax_nopriv_hamnna_slider_products', array(__CLASS__, 'ajax_products'));
    }

    public static function defaults() {
        return array(
            'view_all_text' => 'مشاهده همه',
            'limit' => 12,
            'mobile' => 3,
            'tablet' => 4,
            'desktop' => 6,
            'autoplay' => 1,
            'interval' => 5000,
            'tabs' => array(
                array('title'=>'جدیدترین‌ها','type'=>'date','url'=>''),
                array('title'=>'پرفروش‌ترین‌ها','type'=>'popularity','url'=>''),
                array('title'=>'تخفیف‌ها','type'=>'onsale','url'=>''),
            ),
        );
    }

    public static function get_settings() {
        $saved = get_option(self::OPTION, array());
        $settings = wp_parse_args(is_array($saved) ? $saved : array(), self::defaults());
        if (!isset($settings['tabs']) || !is_array($settings['tabs']) || !$settings['tabs']) {
            $settings['tabs'] = self::defaults()['tabs'];
        }
        return $settings;
    }

    public static function register_settings() {
        register_setting('hamnna_product_slider_group', self::OPTION, array(__CLASS__, 'sanitize_settings'));
    }

    public static function sanitize_settings($input) {
        $d=self::defaults(); $out=$d;
        $out['view_all_text']=isset($input['view_all_text']) ? sanitize_text_field($input['view_all_text']) : $d['view_all_text'];
        $out['limit']=isset($input['limit']) ? max(4,min(30,absint($input['limit']))) : 12;
        $out['mobile']=3;
        $out['tablet']=4;
        $out['desktop']=6;
        $out['autoplay']=empty($input['autoplay'])?0:1;
        $out['interval']=max(2500,min(30000,absint($input['interval'] ?? 5000)));
        $out['tabs']=array();
        $allowed=array('date','modified','popularity','rating','price','price-desc','onsale','rand','menu_order');
        if(isset($input['tabs']) && is_array($input['tabs'])) foreach($input['tabs'] as $tab){
            $title=sanitize_text_field($tab['title']??'');
            $type=sanitize_key($tab['type']??'date');
            $url=esc_url_raw($tab['url']??'');
            if($title!=='' && in_array($type,$allowed,true)) $out['tabs'][]=array('title'=>$title,'type'=>$type,'url'=>$url);
        }
        if(!$out['tabs']) $out['tabs']=$d['tabs'];
        return $out;
    }

    public static function admin_menu() {
        add_submenu_page('hamnna-scraper','اسلایدر محصولات Hamnna','اسلایدر محصولات','manage_options','hamnna-product-slider',array(__CLASS__,'settings_page'));
    }

    public static function settings_page() {
        if(!current_user_can('manage_options')) return;
        $s=self::get_settings(); ?>
        <div class="wrap" dir="rtl">
            <h1>اسلایدر محصولات Hamnna</h1>
            <p>تب‌ها، مرتب‌سازی و لینک «مشاهده همه» را از اینجا کنترل کن. موبایل همیشه ۳ محصول همزمان نشان می‌دهد.</p>
            <form method="post" action="options.php">
                <?php settings_fields('hamnna_product_slider_group'); ?>
                <table class="form-table" role="presentation">
                    <tr><th>متن مشاهده همه</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[view_all_text]" value="<?php echo esc_attr($s['view_all_text']); ?>"></td></tr>
                    <tr><th>تعداد محصولات</th><td><input type="number" min="4" max="30" name="<?php echo esc_attr(self::OPTION); ?>[limit]" value="<?php echo esc_attr($s['limit']); ?>"></td></tr>
                    <tr><th>فاصله حرکت خودکار</th><td><input type="number" min="2500" max="30000" step="500" name="<?php echo esc_attr(self::OPTION); ?>[interval]" value="<?php echo esc_attr($s['interval']); ?>"> میلی‌ثانیه</td></tr>
                    <tr><th>حرکت خودکار</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[autoplay]" value="1" <?php checked($s['autoplay'],1); ?>> فعال</label></td></tr>
                </table>
                <h2>تب‌ها</h2>
                <table class="widefat striped" style="max-width:1000px"><thead><tr><th>عنوان</th><th>نوع</th><th>لینک مشاهده همه</th></tr></thead><tbody>
                <?php for($i=0;$i<8;$i++): $tab=$s['tabs'][$i]??array('title'=>'','type'=>'date','url'=>''); ?>
                    <tr>
                        <td><input style="width:100%" name="<?php echo esc_attr(self::OPTION); ?>[tabs][<?php echo $i; ?>][title]" value="<?php echo esc_attr($tab['title']); ?>" placeholder="مثلاً جدیدترین‌ها"></td>
                        <td><select name="<?php echo esc_attr(self::OPTION); ?>[tabs][<?php echo $i; ?>][type]">
                        <?php foreach(array('date'=>'جدیدترین‌ها','modified'=>'آخرین ویرایش','popularity'=>'پرفروش‌ترین‌ها','rating'=>'محبوب‌ترین/امتیاز','price'=>'ارزان‌ترین','price-desc'=>'گران‌ترین','onsale'=>'تخفیف‌دار','rand'=>'تصادفی','menu_order'=>'ترتیب منو') as $k=>$v): ?>
                            <option value="<?php echo esc_attr($k); ?>" <?php selected($tab['type'],$k); ?>><?php echo esc_html($v); ?></option>
                        <?php endforeach; ?></select></td>
                        <td><input style="width:100%" name="<?php echo esc_attr(self::OPTION); ?>[tabs][<?php echo $i; ?>][url]" value="<?php echo esc_attr($tab['url']); ?>" placeholder="اختیاری؛ خالی = فروشگاه"></td>
                    </tr>
                <?php endfor; ?></tbody></table>
                <?php submit_button('ذخیره تنظیمات'); ?>
            </form>
            <h2>قرار دادن اسلایدر</h2><code>[hamnna_product_slider]</code>
        </div>
        <?php
    }

    private static function query_args($type,$limit){
        $args=array('post_type'=>'product','post_status'=>'publish','posts_per_page'=>$limit,'ignore_sticky_posts'=>true);
        switch($type){
            case 'popularity': $args['meta_key']='total_sales'; $args['orderby']='meta_value_num'; $args['order']='DESC'; break;
            case 'rating': $args['meta_key']='_wc_average_rating'; $args['orderby']='meta_value_num'; $args['order']='DESC'; break;
            case 'price': $args['meta_key']='_price'; $args['orderby']='meta_value_num'; $args['order']='ASC'; break;
            case 'price-desc': $args['meta_key']='_price'; $args['orderby']='meta_value_num'; $args['order']='DESC'; break;
            case 'onsale':
                $ids=function_exists('wc_get_product_ids_on_sale') ? wc_get_product_ids_on_sale() : array();
                $args['post__in']=$ids?$ids:array(0); $args['orderby']='date'; $args['order']='DESC'; break;
            case 'modified': $args['orderby']='modified'; $args['order']='DESC'; break;
            case 'rand': $args['orderby']='rand'; break;
            case 'menu_order': $args['orderby']='menu_order title'; $args['order']='ASC'; break;
            default: $args['orderby']='date'; $args['order']='DESC';
        }
        return $args;
    }

    private static function cards($type,$limit){
        $q=new WP_Query(self::query_args($type,$limit)); ob_start();
        if($q->have_posts()) while($q->have_posts()){ $q->the_post(); global $product;
            if(!$product || !is_a($product,'WC_Product')) continue; ?>
            <article class="hm-card"><a class="hm-card-link" href="<?php the_permalink(); ?>">
                <div class="hm-card-image"><?php echo $product->get_image('woocommerce_thumbnail'); ?><?php if($product->is_on_sale()): ?><span class="hm-sale">تخفیف</span><?php endif; ?></div>
                <h3><?php the_title(); ?></h3><div class="hm-price"><?php echo wp_kses_post($product->get_price_html()); ?></div>
            </a></article>
        <?php } else { ?><div class="hm-empty">محصولی برای نمایش پیدا نشد.</div><?php }
        wp_reset_postdata(); return ob_get_clean();
    }

    public static function ajax_products(){
        check_ajax_referer('hamnna_slider_nonce','nonce');
        $type=sanitize_key($_POST['type']??'date');
        $allowed=array('date','modified','popularity','rating','price','price-desc','onsale','rand','menu_order');
        if(!in_array($type,$allowed,true)) $type='date';
        $s=self::get_settings(); $limit=max(4,min(30,absint($_POST['limit']??$s['limit'])));
        wp_send_json_success(array('html'=>self::cards($type,$limit)));
    }

    public static function shortcode($atts=array()){
        if(!class_exists('WooCommerce')) return '';
        $s=self::get_settings(); $uid='hm-'.wp_rand(10000,99999); $tabs=$s['tabs'];
        $shop=wc_get_page_permalink('shop');
        ob_start(); ?>
        <section id="<?php echo esc_attr($uid); ?>" class="hm-product-slider" dir="rtl" data-limit="<?php echo esc_attr($s['limit']); ?>" data-autoplay="<?php echo esc_attr($s['autoplay']); ?>" data-interval="<?php echo esc_attr($s['interval']); ?>">
            <div class="hm-slider-head">
                <div class="hm-slider-tabs">
                    <?php foreach($tabs as $i=>$tab): $url=$tab['url']?:$shop; ?>
                        <button type="button" class="hm-tab <?php echo $i===0?'is-active':''; ?>" data-type="<?php echo esc_attr($tab['type']); ?>" data-url="<?php echo esc_url($url); ?>"><?php echo esc_html($tab['title']); ?></button>
                    <?php endforeach; ?>
                </div>
                <a class="hm-view-all" href="<?php echo esc_url($tabs[0]['url']?:$shop); ?>"><?php echo esc_html($s['view_all_text']); ?></a>
            </div>
            <div class="hm-slider-shell"><button type="button" class="hm-arrow hm-prev" aria-label="قبلی">‹</button><div class="hm-track-wrap"><div class="hm-track"><?php echo self::cards($tabs[0]['type'],$s['limit']); ?></div></div><button type="button" class="hm-arrow hm-next" aria-label="بعدی">›</button></div>
        </section>
        <style>
        #<?php echo esc_attr($uid); ?>{--g:#569f60;--gd:#3f8249;--gap:14px;width:100%;font-family:inherit}#<?php echo esc_attr($uid); ?> *{box-sizing:border-box}.hm-slider-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 16px}.hm-slider-tabs{display:flex;flex:1 1 auto;min-width:0;gap:7px;overflow:auto;scrollbar-width:none}.hm-slider-tabs::-webkit-scrollbar{display:none}.hm-view-all{display:inline-flex!important;align-items:center;justify-content:center;flex:0 0 auto;min-height:38px;padding:8px 15px;border:1px solid #569f60;border-radius:999px;background:#fff;color:#3f8249!important;font-weight:800;text-decoration:none!important;white-space:nowrap;box-shadow:0 5px 16px rgba(86,159,96,.12);transition:.25s}.hm-view-all:hover{background:linear-gradient(135deg,#569f60,#3f8249);color:#fff!important;transform:translateY(-2px)}.hm-tab{font:inherit;border:1px solid #e6eee8;background:#fff;color:#36513d;border-radius:999px;padding:9px 15px;white-space:nowrap;cursor:pointer;transition:.25s}.hm-tab.is-active,.hm-tab:hover{color:#fff;border-color:var(--g);background:linear-gradient(135deg,var(--g),var(--gd));box-shadow:0 7px 18px rgba(86,159,96,.2)}.hm-slider-shell{position:relative}.hm-track-wrap{overflow:hidden;padding:4px 1px 12px;touch-action:pan-y;cursor:grab}.hm-track-wrap.is-dragging{cursor:grabbing}.hm-track{display:flex;gap:var(--gap);transition:transform .55s cubic-bezier(.22,.61,.36,1);will-change:transform}.hm-card{flex:0 0 calc((100% - 5 * var(--gap))/6);width:calc((100% - 5 * var(--gap))/6);min-width:0;border:1px solid #edf1ee;border-radius:18px;background:#fff;overflow:hidden;box-shadow:0 8px 28px rgba(25,45,30,.07);transition:.28s}.hm-card:hover{transform:translateY(-5px);box-shadow:0 14px 34px rgba(25,45,30,.12)}.hm-card-link{display:block;padding:10px;color:inherit;text-decoration:none}.hm-card-image{position:relative;aspect-ratio:1/1;display:flex;align-items:center;justify-content:center;background:linear-gradient(145deg,#fff,#f7faf7);border-radius:13px;overflow:hidden}.hm-card-image img{width:100%;height:100%;object-fit:contain}.hm-card h3{font-size:14px;line-height:1.7;margin:10px 3px 4px;font-weight:700;color:#1f2922;height:47px;overflow:hidden}.hm-price{font-size:13px;font-weight:800;color:var(--gd);margin:0 3px 4px}.hm-sale{position:absolute;top:8px;right:8px;background:var(--gd);color:#fff;border-radius:8px;padding:4px 7px;font-size:11px;font-weight:700}.hm-arrow{position:absolute;z-index:3;top:46%;transform:translateY(-50%);width:38px;height:38px;border:1px solid #e4ebe5;background:#fff;border-radius:50%;box-shadow:0 7px 20px rgba(0,0,0,.1);color:var(--gd);font-size:28px;cursor:pointer;display:flex;align-items:center;justify-content:center}.hm-prev{right:-8px}.hm-next{left:-8px}.hm-empty{padding:30px;text-align:center;width:100%}
        @media(max-width:1100px){#<?php echo esc_attr($uid); ?> .hm-card{flex-basis:calc((100% - 3 * var(--gap))/4);width:calc((100% - 3 * var(--gap))/4);max-width:calc((100% - 3 * var(--gap))/4)}}
        @media(max-width:700px){#<?php echo esc_attr($uid); ?>{--gap:8px}#<?php echo esc_attr($uid); ?> .hm-slider-head{display:flex;flex-wrap:wrap;align-items:stretch;gap:9px}#<?php echo esc_attr($uid); ?> .hm-slider-tabs{order:1;flex:1 1 100%;width:100%;max-width:none;padding-bottom:2px}#<?php echo esc_attr($uid); ?> .hm-view-all{order:2;display:flex!important;width:100%;min-height:36px;font-size:12px}#<?php echo esc_attr($uid); ?> .hm-card{flex:0 0 calc((100% - 2 * var(--gap))/3);width:calc((100% - 2 * var(--gap))/3);max-width:calc((100% - 2 * var(--gap))/3)}#<?php echo esc_attr($uid); ?> .hm-tab{padding:8px 12px;font-size:12px}#<?php echo esc_attr($uid); ?> .hm-card-link{padding:7px}#<?php echo esc_attr($uid); ?> .hm-card h3{font-size:11px;height:40px;line-height:1.8;margin-top:7px}#<?php echo esc_attr($uid); ?> .hm-price{font-size:10px}#<?php echo esc_attr($uid); ?> .hm-arrow{width:30px;height:30px;font-size:22px}}
        </style>
        <script>(function(){const r=document.getElementById('<?php echo esc_js($uid); ?>');if(!r)return;const w=r.querySelector('.hm-track-wrap'),t=r.querySelector('.hm-track'),tabs=[...r.querySelectorAll('.hm-tab')],all=r.querySelector('.hm-view-all');let i=0,x=0,d=0,drag=false,timer=null;const nonce='<?php echo esc_js(wp_create_nonce('hamnna_slider_nonce')); ?>';function vis(){return innerWidth<=700?3:innerWidth<=1100?4:6}function step(){let c=t.querySelector('.hm-card');return c?c.getBoundingClientRect().width+parseFloat(getComputedStyle(t).gap||0):0}function max(){return Math.max(0,t.children.length-vis())}function draw(anim=true){i=Math.max(0,Math.min(i,max()));t.style.transition=anim?'transform .55s cubic-bezier(.22,.61,.36,1)':'none';t.style.transform='translate3d('+(i*step())+'px,0,0)'}function restart(){clearInterval(timer);if(r.dataset.autoplay==='1')timer=setInterval(()=>{i=i>=max()?0:i+1;draw()},+r.dataset.interval||5000)}function load(tab){r.classList.add('hm-loading');const fd=new FormData();fd.append('action','hamnna_slider_products');fd.append('nonce',nonce);fd.append('type',tab.dataset.type);fd.append('limit',r.dataset.limit);fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>',{method:'POST',body:fd,credentials:'same-origin'}).then(q=>q.json()).then(q=>{if(q.success){t.innerHTML=q.data.html;i=0;draw(false);restart()}}).finally(()=>r.classList.remove('hm-loading'))}r.querySelector('.hm-prev').onclick=()=>{i=i?i-1:max();draw();restart()};r.querySelector('.hm-next').onclick=()=>{i=i>=max()?0:i+1;draw();restart()};tabs.forEach(tab=>tab.onclick=()=>{tabs.forEach(a=>a.classList.remove('is-active'));tab.classList.add('is-active');all.href=tab.dataset.url||all.href;load(tab)});w.onpointerdown=e=>{drag=true;x=e.clientX;d=0;w.classList.add('is-dragging');w.setPointerCapture(e.pointerId);t.style.transition='none'};w.onpointermove=e=>{if(!drag)return;d=e.clientX-x;t.style.transform='translate3d('+(-i*step()+d)+'px,0,0)'};w.onpointerup=()=>{if(!drag)return;drag=false;w.classList.remove('is-dragging');if(Math.abs(d)>35)i=d<0?(i>=max()?0:i+1):(i<=0?max():i-1);draw();restart()};w.onpointercancel=()=>{drag=false;draw()};r.onmouseenter=()=>clearInterval(timer);r.onmouseleave=restart;addEventListener('resize',()=>draw(false));draw(false);restart()})();</script>
        <?php return ob_get_clean();
    }
}
