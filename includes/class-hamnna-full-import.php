<?php
if(!defined('ABSPATH'))exit;
final class Hamnna_Full_Import{
 public static function init(){add_action('admin_post_hamnna_scraper_run',[__CLASS__,'run'],1);}
 public static function run(){
  if(!current_user_can('manage_woocommerce')||!check_admin_referer('hamnna_run'))wp_die('دسترسی غیرمجاز');
  if(!class_exists('WooCommerce'))wp_die('WooCommerce فعال نیست.');
  $s=get_option('hamnna_scraper_settings',[]);$batch=max(1,min(100,(int)($s['batch']??50)));$ref=new ReflectionClass('Hamnna_Scraper');
  $discover=$ref->getMethod('discover');$discover->setAccessible(true);$discover->invoke(null);
  $queue=get_option('hamnna_scraper_queue',[]);if(!is_array($queue))$queue=[];
  $scrape=$ref->getMethod('scrape');$scrape->setAccessible(true);$find=$ref->getMethod('find_product_id');$find->setAccessible(true);
  $start=microtime(true);$r=['checked'=>0,'new'=>0,'updated'=>0,'unavailable'=>0,'errors'=>0,'remaining'=>count($queue),'seconds'=>0];
  $work=array_slice($queue,0,$batch);$rest=array_slice($queue,$batch);update_option('hamnna_scraper_queue',$rest,false);
  foreach($work as$url){$url=trim((string)$url);if(!$url){$r['errors']++;continue;}$r['checked']++;try{$p=$scrape->invoke(null,$url);if(is_wp_error($p)){$r['errors']++;continue;}if(empty($p['available'])){$r['unavailable']++;continue;}$sid=preg_match('~/product/(\d+)~i',wp_parse_url($url,PHP_URL_PATH)?:'',$m)?$m[1]:md5($url);$id=(int)$find->invoke(null,$sid,$url);if($id){$product=wc_get_product($id);if(!$product){$r['errors']++;continue;}$product->set_name($p['title']);$product->set_description($p['description']);if($p['price']!==''){$product->set_regular_price($p['price']);$product->set_price($p['price']);}$product->set_stock_status('instock');$product->save();update_post_meta($id,'_hamnna_source_url',esc_url_raw($url));update_post_meta($id,'_hamnna_source_id',(string)$sid);update_post_meta($id,'_hamnna_resynced_at',current_time('mysql'));$r['updated']++;}else{$im=$ref->getMethod('import');$im->setAccessible(true);$v=$im->invoke(null,$p,$sid);if(is_wp_error($v))$r['errors']++;else$r['new']++;}}catch(Throwable$e){$r['errors']++;}}
  $r['remaining']=count($rest);$r['seconds']=round(microtime(true)-$start,2);$logs=get_option('hamnna_scraper_logs',[]);if(!is_array($logs))$logs=[];$r['time']=current_time('mysql');$r['manual_full']=1;array_unshift($logs,$r);update_option('hamnna_scraper_logs',array_slice($logs,0,100),false);
  wp_safe_redirect(add_query_arg(['page'=>'hamnna-scraper','hamnna_full_done'=>1],admin_url('admin.php')));exit;
 }
}
Hamnna_Full_Import::init();
