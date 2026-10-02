<?php
if (!defined('ABSPATH')) exit;

final class Hamnna_Price_Sync {
    const LOG = 'hamnna_price_sync_log';
    const LOCK = 'hamnna_price_sync_lock';

    public static function settings() {
        $s = Hamnna_Scraper::settings();
        return wp_parse_args(get_option('hamnna_price_sync_settings', []), [
            'enabled' => 1,
            'interval' => 5,
            'min_score' => 88,
            'min_margin' => 7,
            'batch' => 50,
            'dry_run' => 0,
            'manual_lock_meta' => '_hamnna_price_manual_lock',
            'source_catalog' => 'auto'
        ]);
    }

    public static function normalize($text) {
        $text = html_entity_decode((string)$text, ENT_QUOTES, 'UTF-8');
        $map = [
            'ي'=>'ی','ى'=>'ی','ك'=>'ک','ۀ'=>'ه','ة'=>'ه','ؤ'=>'و','إ'=>'ا','أ'=>'ا','ٱ'=>'ا',
            '‌'=>' ','ـ'=>' ','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'
        ];
        $text = strtr(mb_strtolower(wp_strip_all_tags($text), 'UTF-8'), $map);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private static function tokens($text) {
        $stop = ['عطر','ادکلن','ادوپرفیوم','پرفیوم','مردانه','زنانه','یونیسکس','مینی','میل','میلی','لیتر','مدل','اصل','اورجینال','الحمبرا','ویکینگ'];
        $out = [];
        foreach (preg_split('/\s+/u', self::normalize($text)) as $t) {
            if ($t !== '' && !in_array($t, $stop, true) && mb_strlen($t,'UTF-8') > 1) $out[$t] = true;
        }
        return array_keys($out);
    }

    private static function similarity($a, $b) {
        if ($a === '' || $b === '') return 0;
        similar_text($a, $b, $pct);
        return (float)$pct;
    }

    private static function score($dst, $src) {
        $dn = self::normalize($dst['name'].' '.$dst['brand'].' '.$dst['sku'].' '.$dst['slug']);
        $sn = self::normalize($src['name'].' '.$src['brand'].' '.$src['sku'].' '.$src['slug']);
        $score = 0;
        $reasons = [];
        if ($dst['sku'] && $src['sku'] && self::normalize($dst['sku']) === self::normalize($src['sku'])) {
            $score += 55; $reasons[] = 'SKU';
        }
        if ($dst['source_id'] && $src['id'] && (string)$dst['source_id'] === (string)$src['id']) {
            $score += 100; $reasons[] = 'SOURCE_ID';
        }
        if ($dst['source_url'] && $src['url'] && untrailingslashit($dst['source_url']) === untrailingslashit($src['url'])) {
            $score += 100; $reasons[] = 'SOURCE_URL';
        }
        if ($dst['slug'] && $src['slug'] && self::normalize($dst['slug']) === self::normalize($src['slug'])) {
            $score += 35; $reasons[] = 'SLUG';
        }
        $nameSim = self::similarity(self::normalize($dst['name']), self::normalize($src['name']));
        $score += min(35, $nameSim * 0.35);
        if ($nameSim >= 92) $reasons[] = 'NAME_EXACT';
        elseif ($nameSim >= 82) $reasons[] = 'NAME_FUZZY';
        $dt = self::tokens($dst['name']); $st = self::tokens($src['name']);
        if ($dt && $st) {
            $inter = count(array_intersect($dt, $st));
            $union = count(array_unique(array_merge($dt, $st)));
            $j = $union ? ($inter / $union) * 100 : 0;
            $score += min(25, $j * 0.25);
            if ($j >= 75) $reasons[] = 'TOKEN';
        }
        if ($dst['brand'] && $src['brand'] && self::normalize($dst['brand']) === self::normalize($src['brand'])) {
            $score += 10; $reasons[] = 'BRAND';
        }
        $dv = self::extract_volume($dst['name']); $sv = self::extract_volume($src['name']);
        if ($dv && $sv && $dv === $sv) { $score += 8; $reasons[] = 'VOLUME'; }
        return ['score'=>min(100, round($score,2)), 'reason'=>implode('+',$reasons)];
    }

    private static function extract_volume($s) {
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:ml|میل|میلیs*لیتر|l|لیتر)\b/iu', (string)$s, $m)) return str_replace(',','.', $m[1]);
        return '';
    }

    public static function find_match($dst, $sources) {
        $ranked = [];
        foreach ($sources as $src) {
            $x = self::score($dst, $src);
            $ranked[] = ['src'=>$src] + $x;
        }
        usort($ranked, function($a,$b){ return $b['score'] <=> $a['score']; });
        $best = $ranked[0] ?? null;
        $second = $ranked[1]['score'] ?? 0;
        $cfg = self::settings();
        if (!$best || $best['score'] < (float)$cfg['min_score'] || ($best['score'] - $second) < (float)$cfg['min_margin']) {
            return ['matched'=>false,'reason'=>'AMBIGUOUS_OR_LOW_CONFIDENCE','best'=>$best,'second'=>$second];
        }
        return ['matched'=>true,'source'=>$best['src'],'score'=>$best['score'],'method'=>$best['reason'],'second'=>$second];
    }

    public static function sync_all($limit = null, $dry_run = null) {
        if (!class_exists('WooCommerce')) return ['error'=>'WooCommerce is not active'];
        if (get_transient(self::LOCK)) return ['error'=>'sync_locked'];
        set_transient(self::LOCK, 1, 15 * MINUTE_IN_SECONDS);
        $cfg = self::settings();
        $limit = $limit ?: (int)$cfg['batch'];
        $dry_run = $dry_run === null ? (bool)$cfg['dry_run'] : (bool)$dry_run;
        $sources = self::discover_source_catalog();
        $products = get_posts([
            'post_type'=>'product','post_status'=>'any','posts_per_page'=>$limit,
            'fields'=>'ids','orderby'=>'ID','order'=>'ASC'
        ]);
        $r = ['checked'=>0,'matched'=>0,'updated'=>0,'unchanged'=>0,'ambiguous'=>0,'locked'=>0,'missing'=>0,'errors'=>0,'items'=>[]];
        foreach ($products as $id) {
            $r['checked']++;
            $p = wc_get_product($id); if (!$p) { $r['errors']++; continue; }
            if (get_post_meta($id, $cfg['manual_lock_meta'], true)) { $r['locked']++; continue; }
            $dst = [
                'id'=>$id,'name'=>$p->get_name(),'slug'=>get_post_field('post_name',$id),
                'sku'=>$p->get_sku(),'brand'=>get_post_meta($id,'_hamnna_brand',true),
                'source_id'=>get_post_meta($id,'_hamnna_source_id',true),
                'source_url'=>get_post_meta($id,'_hamnna_source_url',true)
            ];
            $m = self::find_match($dst, $sources);
            if (!$m['matched']) { $r['ambiguous']++; continue; }
            $r['matched']++;
            $src = $m['source'];
            if ($src['price'] === '' || !is_numeric($src['price'])) { $r['missing']++; continue; }
            $old = (float)$p->get_regular_price(); $new = (float)$src['price'];
            if ($old === $new) { $r['unchanged']++; continue; }
            if (!$dry_run) {
                $p->set_regular_price((string)$new);
                if ($p->is_on_sale()) {
                    $sale = $p->get_sale_price();
                    if ($sale !== '' && (float)$sale === $old) $p->set_sale_price((string)$new);
                }
                $p->set_price($p->get_sale_price() !== '' ? $p->get_sale_price() : (string)$new);
                $p->save();
                update_post_meta($id,'_hamnna_last_source_price',(string)$new);
                update_post_meta($id,'_hamnna_last_price_sync',current_time('mysql'));
                update_post_meta($id,'_hamnna_price_match_score',$m['score']);
                update_post_meta($id,'_hamnna_price_match_method',$m['method']);
                if ($src['url']) update_post_meta($id,'_hamnna_source_url',esc_url_raw($src['url']));
                if ($src['id']) update_post_meta($id,'_hamnna_source_id',(string)$src['id']);
            }
            $r['updated']++;
            $r['items'][]=['id'=>$id,'title'=>$p->get_name(),'old'=>$old,'new'=>$new,'score'=>$m['score'],'method'=>$m['method'],'source'=>$src['url']];
        }
        delete_transient(self::LOCK);
        self::log($r);
        return $r;
    }

    public static function discover_source_catalog() {
        $urls = [];
        $base = 'https://hamnna.ir';
        $sitemaps = [$base.'/product-sitemap.xml',$base.'/sitemap_index.xml',$base.'/sitemap.xml'];
        foreach ($sitemaps as $map) {
            $body = wp_remote_retrieve_body(wp_remote_get($map,['timeout'=>20,'user-agent'=>'Hamnna-Price-Sync']));
            if (!$body) continue;
            if (preg_match_all('/<loc>\s*(.*?)\s*<\/loc>/is',$body,$m)) foreach ($m[1] as $u) {
                $u = esc_url_raw(html_entity_decode(trim($u)));
                if (preg_match('~/product/\d+(?:/|$)~i',wp_parse_url($u,PHP_URL_PATH) ?: '')) $urls[$u]=1;
                elseif (stripos($u,'sitemap')!==false) $sitemaps[]=$u;
            }
            if (count($urls) >= 5000) break;
        }
        $catalog=[];
        foreach (array_keys($urls) as $u) {
            $x=self::parse_source($u);
            if ($x) $catalog[]=$x;
        }
        return $catalog;
    }

    private static function parse_source($url) {
        $r=wp_remote_get($url,['timeout'=>25,'redirection'=>3,'user-agent'=>'Hamnna-Price-Sync/2.0']);
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) >= 400) return null;
        $html=wp_remote_retrieve_body($r); if (!$html) return null;
        $id=''; if (preg_match('~/product/(\d+)~i',wp_parse_url($url,PHP_URL_PATH) ?: '',$m)) $id=$m[1];
        $name=''; $price=''; $sku=''; $brand='';
        if (preg_match('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is',$html,$m)) {
            $j=json_decode(trim($m[1]),true);
            $arr=isset($j['@graph'])?$j['@graph']:(isset($j[0])?$j:[$j]);
            foreach ((array)$arr as $o) if (is_array($o) && in_array('Product',(array)($o['@type']??[]),true)) {
                $name=$o['name']??$name; $sku=$o['sku']??$sku;
                $b=$o['brand']??''; $brand=is_array($b)?($b['name']??''):$b;
                $of=$o['offers']??[]; if(isset($of[0]))$of=$of[0]; $price=$of['price']??$price; break;
            }
        }
        if (!$name && preg_match('/<h1[^>]*>(.*?)<\/h1>/is',$html,$m)) $name=wp_strip_all_tags($m[1]);
        if (!$price && preg_match('/(?:itemprop=["\']price["\'][^>]*content=["\']([^"\']+)|class=["\'][^"\']*price[^"\']*["\'][^>]*>\s*([^<]+)/is',$html,$m)) $price=$m[1]?:$m[2];
        $price=self::money($price);
        if (!$name) return null;
        return ['id'=>$id,'url'=>$url,'name'=>trim(preg_replace('/\s+/u',' ',wp_strip_all_tags($name))),'slug'=>basename(untrailingslashit(wp_parse_url($url,PHP_URL_PATH) ?: '')),'sku'=>trim($sku),'brand'=>trim($brand),'price'=>$price];
    }

    private static function money($v) {
        $v=html_entity_decode((string)$v,ENT_QUOTES,'UTF-8');
        $v=strtr($v,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٬'=>',']);
        $v=preg_replace('/(?:تومان|تومن|ریال|ريال|﷼|IRT|IRR)/iu','',$v);
        $v=trim(preg_replace('/[^0-9,.]/u','',$v)); if($v==='') return '';
        if (strpos($v,',')!==false && strpos($v,'.')===false) {
            $parts=explode(',',$v); if(count(end($parts))===3) $v=implode('',$parts);
        } elseif (strpos($v,'.')!==false && strpos($v,',')===false) {
            $parts=explode('.',$v); if(count(end($parts))===3) $v=implode('',$parts);
        } else { $v=str_replace(',','',$v); }
        return (string)round((float)$v);
    }

    private static function log($r) {
        $log=get_option(self::LOG,[]); if(!is_array($log))$log=[];
        array_unshift($log,['time'=>current_time('mysql'),'result'=>$r]);
        update_option(self::LOG,array_slice($log,0,100),false);
    }

    public static function cron() {
        if (self::settings()['enabled']) self::sync_all();
    }
}
