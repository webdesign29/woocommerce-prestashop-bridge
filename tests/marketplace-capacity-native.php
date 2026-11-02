<?php
/** Capacity regression in an isolated native store; retain synthetic records for inspection. */
$root=getenv('WD29_WP_ROOT');if(!$root)throw new RuntimeException('Set WD29_WP_ROOT');
define('WP_DISABLE_FATAL_ERROR_HANDLER',true);require $root.'/wp-load.php';
if(DB_NAME!=='wd29woo'||parse_url(home_url(),PHP_URL_HOST)!=='woo.example.test')throw new RuntimeException('Not an isolated fixture');
if(!WD29\Bridge\Distribution::isMarketplace())throw new RuntimeException('Marketplace artifact required');
$engine=wd29_bridge();$before=$engine->config();$oldTaxes=get_option('woocommerce_calc_taxes');
update_option('woocommerce_calc_taxes','no');update_option('wd29_bridge_config',['mode'=>'audit','peer'=>'https://ps.example.test/webhook','secret'=>str_repeat('fixture-',8)]);
$ids=[];$start=microtime(true);
try{
 for($n=1;$n<=405;$n++){
  global $wpdb; $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title=%s AND post_type='product' ORDER BY ID DESC LIMIT 1",'English capacity fixture '.$n));
  if($existing){$ids[]=$existing;$engine->capture('product',$existing);continue;}
  $product=new WC_Product_Simple();$product->set_name('English capacity fixture '.$n);$product->set_regular_price('10');$product->set_tax_status('none');$product->set_status('publish');$product->set_manage_stock(true);$product->set_stock_quantity(1);$id=$product->save();$ids[]=$id;$engine->capture('product',$id);
 }
 $count=0;foreach($ids as $id){$rows=$engine->sql("SELECT seq FROM {b}queue WHERE record_key=? AND kind='product'",['woo:product:'.$id]);if(!$rows)throw new RuntimeException('Product not captured: '.$id);$count++;}
 if($count!==405)throw new RuntimeException('Capture cap detected');
 echo 'PASS: '.$count.' native products captured, including products 401–405; no Marketplace 400-product cap. '.round(microtime(true)-$start,1)." seconds\n";
}finally{update_option('wd29_bridge_config',$before);update_option('woocommerce_calc_taxes',$oldTaxes);}
