<?php
if(!defined('ABSPATH'))exit;
/**
 * Speed/full-import tuning. Keeps the 10-minute schedule but processes a larger
 * controlled batch so a fresh/empty destination can rebuild the catalog faster.
 */
final class Hamnna_Speed_Tuning{
 public static function init(){
  add_action('init',[__CLASS__,'apply'],20);
 }
 public static function apply(){
  $o=get_option('hamnna_scraper_settings',[]);
  if(!is_array($o))$o=[];
  $changed=false;
  // 50 products per 10-minute run. Existing products are still skipped by the scraper.
  if((int)($o['batch']??0)<50){$o['batch']=50;$changed=true;}
  if($changed)update_option('hamnna_scraper_settings',$o,false);
 }
}
Hamnna_Speed_Tuning::init();
