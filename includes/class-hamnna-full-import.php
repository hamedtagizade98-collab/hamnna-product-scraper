<?php
if(!defined('ABSPATH'))exit;
final class Hamnna_Full_Import{
 public static function init(){add_action('admin_post_hamnna_scraper_run',[__CLASS__,'run'],1);}
 public static function run(){
  if(!current_user_can('manage_woocommerce')||!check_admin_referer('hamnna_run'))wp_die('دسترسی غیرمجاز');
  if(!class_exists('WooCommerce'))wp_die('WooCommerce فعال نیست.');
  @ignore_user_abort(true);@set_time_limit(900);
  $ref=new ReflectionClass('Hamnna_Scraper');
  $discover=$ref->getMethod('discover');$discover->setAccessible(true);$discover->invoke(null);
  $scrape=$ref->getMethod('scrape');$scrape->setAccessible(true);
  $find=$ref->getMethod('find_product_id');$find->setAccessible(true);
  $import=$ref->getMethod('import');$import->setAccessible(true);
  $start=microtime(true);$deadline=$start+895;
  $r=['checked'=>0,'new'=>0,'updated'=>0,'existing'=>0,'unavailable'=>0,'errors'=>0,'remaining'=>0,'seconds'=>0,'batches'=>0];
  while(microtime(true)<$deadline){
   $queue=get_option('hamnna_scraper_queue',[]);if(!is_array($queue))$queue=[];
   if(empty($queue))break;
   $work=array_splice($queue,0,100);update_option('hamnna_scraper_queue',array_values($queue),false);$r['batches']++;
   foreach($work as$url){
    if(microtime(true)>=$deadline)break;
    $url=trim((string)$url);if(!$url){$r['errors']++;continue;}$r['checked']++;
    try{
     $sid=preg_match('~/product/(\d+)~i',wp_parse_url($url,PHP_URL_PATH)?:'',$m)?$m[1]:md5($url);
     if($find->invoke(null,$sid,$url)){$r['existing']++;continue;}
     $p=$scrape->invoke(null,$url);
     if(is_wp_error($p)){$r['errors']++;continue;}
     if(empty($p['available'])){$r['unavailable']++;continue;}
     $v=$import->invoke(null,$p,$sid);
     if(is_wp_error($v))$r['errors']++;else$r['new']++;
    }catch(Throwable$e){$r['errors']++;}
   }
  }
  $q=get_option('hamnna_scraper_queue',[]);$r['remaining']=is_array($q)?count($q):0;$r['seconds']=round(microtime(true)-$start,2);$r['time']=current_time('mysql');$r['manual_full']=1;
  $logs=get_option('hamnna_scraper_logs',[]);if(!is_array($logs))$logs=[];array_unshift($logs,$r);update_option('hamnna_scraper_logs',array_slice($logs,0,100),false);
  $msg=$r['remaining']>0?'اسکن دستی در ۱۵ دقیقه به پایان زمان رسید؛ صف باقی‌مانده در اجرای بعدی ادامه می‌یابد.':'اسکن دستی کل صف با موفقیت تمام شد.';
  wp_safe_redirect(add_query_arg(['page'=>'hamnna-scraper','hamnna_full_done'=>1,'hamnna_msg'=>rawurlencode($msg)],admin_url('admin.php')));exit;
 }
}
Hamnna_Full_Import::init();
