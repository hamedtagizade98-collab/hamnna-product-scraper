<?php
if(!defined('ABSPATH'))exit;
final class Hamnna_Manual_Run{
 public static function init(){add_action('wp_ajax_hamnna_manual_start',[__CLASS__,'start']);add_action('wp_ajax_hamnna_manual_tick',[__CLASS__,'tick']);}
 public static function start(){if(!current_user_can('manage_woocommerce'))wp_send_json_error('forbidden',403);check_ajax_referer('hamnna_manual_run','nonce');update_option('hamnna_manual_run',['running'=>true,'started'=>time(),'processed'=>0,'imported'=>0,'skipped'=>0,'errors'=>0],false);wp_send_json_success(['started'=>true]);}
 public static function tick(){if(!current_user_can('manage_woocommerce'))wp_send_json_error('forbidden',403);check_ajax_referer('hamnna_manual_run','nonce');$s=get_option('hamnna_manual_run',[]);if(empty($s['running']))wp_send_json_success(['running'=>false]);$deadline=time()+8;while(time()<$deadline){$before=(int)($s['processed']??0);do_action('hamnna_scraper_process_one_manual',$s);$s=get_option('hamnna_manual_run',$s);if((int)($s['processed']??0)===$before)break;}if(time()-(int)$s['started']>=900)$s['running']=false;update_option('hamnna_manual_run',$s,false);wp_send_json_success($s);}
}
Hamnna_Manual_Run::init();
