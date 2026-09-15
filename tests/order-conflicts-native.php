<?php
/** Standalone disposable Woo or PS native guard regression; no map truncation. */
$woo=getenv('WD29_WP_ROOT'); $ps=getenv('WD29_PS_ROOT');
if ($woo) {
 require $woo.'/wp-load.php';
 if (DB_NAME!=='wd29woo' || parse_url(home_url(),PHP_URL_HOST)!=='woo.example.test') { throw new RuntimeException('Disposable Woo fixture required.'); }
 $e=wd29_bridge(); $site='woo';
} elseif ($ps) {
 $_SERVER['HTTP_HOST']='ps.example.test'; $_SERVER['REQUEST_URI']='/'; require $ps.'/config/config.inc.php';
 if (_DB_NAME_!=='wd29ps' || Configuration::get('PS_SHOP_DOMAIN')!=='ps.example.test') { throw new RuntimeException('Disposable PS fixture required.'); }
 Context::getContext()->shop=new Shop(1); Context::getContext()->language=new Language((int)Configuration::get('PS_LANG_DEFAULT')); Context::getContext()->currency=new Currency((int)Configuration::get('PS_CURRENCY_DEFAULT')); Context::getContext()->employee=new Employee(1);
 require_once $ps.'/app/AppKernel.php'; $kernel=new AppKernel('prod',false); $kernel->boot();
 $e=Module::getInstanceByName('wd29woobridge')->bridge(); $site='ps';
} else { throw new RuntimeException('Set disposable WP or PS fixture root.'); }
require __DIR__.'/order-conflicts.php';
$a=$e->adapter; $old=$e->config(); $config=$old; $config['mode']='off';
if ($woo) { update_option('wd29_bridge_config',$config); $oldFields=get_option('wd29_bridge_custom_fields',[]); update_option('wd29_bridge_custom_fields',[]); }
else { Configuration::updateValue('WD29_BRIDGE_CONFIG',json_encode($config)); }
$e->orderApplying=true;
try {
 $address=['first_name'=>'Synthetic','last_name'=>'Fixture','company'=>'','address_1'=>'1 Fixture Street','address_2'=>'','city'=>'Brest','postcode'=>'29200','country'=>'FR','state'=>'','email'=>'fixture@example.test','phone'=>''];
 $data=['key'=>($woo?'ps':'woo').':order:'.random_int(800000000,900000000),'number'=>'FIXTURE','status'=>'pending','currency'=>'EUR','total'=>'24.00','tax'=>'4.00','shipping_net'=>'0.00','shipping_tax'=>'0.00','discount'=>'0.00','billing'=>$address,'shipping'=>$address,'items'=>[['product'=>null,'name'=>'Synthetic guard line','quantity'=>1,'net'=>'20.00','tax'=>'4.00']],'created'=>'2026-09-15T12:00:00+00:00'];
 if (!$woo && empty($config['mirror_states']['pending'])) { throw new RuntimeException('Fixture requires a nonfinancial pending mirror state.'); }
 $id=$a->applyOrder($data,null); $e->bind($data['key'],'order',$id);
 $current=$a->orderConflictSnapshot($id);
 checkConflict(\WD29\Bridge\OrderConflicts::equivalentAugmentation($data,$current),'Unchanged native mirror rejected');
 $changed=function(callable $mutate,callable $restore,string $message) use ($a,$id,$data,$e) {
  $mutate(); $blocked=false; try { $a->orderConflictSnapshot($id); } catch (Throwable $error) { $blocked=true; }
  $restore(); checkConflict($blocked,$message); $a->applyOrder($data,$e->mapping($data['key']));
 };
 if ($woo) {
  $changed(static function() use($id) { $o=wc_get_order($id); $o->set_total('25'); $o->save(); },static function() use($id) { $o=wc_get_order($id); $o->set_total('24'); $o->save(); },'Hidden native Woo total change accepted');
  $line=array_values(wc_get_order($id)->get_items())[0]; $lineId=$line->get_id();
  $changed(static function() use($lineId) { $line=new WC_Order_Item_Product($lineId); $line->set_quantity(2); $line->save(); },static function() use($lineId) { $line=new WC_Order_Item_Product($lineId); $line->set_quantity(1); $line->save(); },'Hidden native Woo quantity change accepted');
  $changed(static function() use($lineId) { $line=new WC_Order_Item_Product($lineId); $line->set_total('21'); $line->save(); },static function() use($lineId) { $line=new WC_Order_Item_Product($lineId); $line->set_total('20'); $line->save(); },'Hidden native Woo line money change accepted');
  $changed(static function() use($id) { $o=wc_get_order($id); $o->set_billing_city('Changed'); $o->save(); },static function() use($id) { $o=wc_get_order($id); $o->set_billing_city('Brest'); $o->save(); },'Hidden native Woo address change accepted');
 } else {
  $changed(static function() use($id) { $o=new Order($id); $o->total_paid_tax_incl=25; $o->save(); },static function() use($id) { $o=new Order($id); $o->total_paid_tax_incl=24; $o->save(); },'Hidden native PS total change accepted');
  $line=(new Order($id))->getOrderDetailList()[0]; $lineId=(int)$line['id_order_detail'];
  $changed(static function() use($lineId) { $line=new OrderDetail($lineId); $line->product_quantity=2; $line->save(); },static function() use($lineId) { $line=new OrderDetail($lineId); $line->product_quantity=1; $line->save(); },'Hidden native PS quantity change accepted');
  $changed(static function() use($lineId) { $line=new OrderDetail($lineId); $line->total_price_tax_excl=21; $line->save(); },static function() use($lineId) { $line=new OrderDetail($lineId); $line->total_price_tax_excl=20; $line->save(); },'Hidden native PS line money change accepted');
  $changed(static function() use($id) { $o=new Order($id); $o->total_discounts_tax_excl=1; $o->save(); },static function() use($id) { $o=new Order($id); $o->total_discounts_tax_excl=0; $o->save(); },'Hidden native PS discount change accepted');
 }
 $upgraded=$data; $upgraded['refunds']=[]; $upgraded['custom_fields']=[]; $upgraded['items'][0]['line_id']='12';
 checkConflict(\WD29\Bridge\OrderConflicts::equivalentAugmentation($a->orderConflictSnapshot($id),$upgraded),'Safe upgraded incoming mirror blocked after restored native data');
 echo 'PASS: '.$site." native mirror conflict guard rejects hidden total/quantity/line financial/address or discount edits; safe augmentation retained\n";
} finally {
 $e->orderApplying=false;
 if ($woo) { update_option('wd29_bridge_config',$old); update_option('wd29_bridge_custom_fields',$oldFields); }
 else { Configuration::updateValue('WD29_BRIDGE_CONFIG',json_encode($old)); }
}
