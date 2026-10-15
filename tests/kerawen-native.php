<?php
/** Non-destructive fixtures: fresh products only; disposable WordPress required. */
$root=getenv('WD29_WP_ROOT');
if(!$root||!is_file($root.'/wp-load.php')){throw new RuntimeException('Set WD29_WP_ROOT');}
require $root.'/wp-load.php';
if(DB_NAME!=='wd29woo'||parse_url(home_url(),PHP_URL_HOST)!=='woo.example.test'){throw new RuntimeException('Disposable fixture required');}
use WD29\Bridge\Engine;
use WD29\Bridge\Protocol;
function check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
$e=wd29_bridge();$e->install();
$config=$e->config();$config['mode']='audit';$config['secret']=str_repeat('fixture-',8);$config['peer']='https://ps.example.test/webhook';$e->adapter->saveConfig($config);
update_option('woocommerce_currency','EUR');update_option('woocommerce_default_country','FR');
update_option('woocommerce_calc_taxes','no');update_option('woocommerce_prices_include_tax','yes');
$p=new WC_Product_Simple();$p->set_name('KerAwen fixture Woo '.bin2hex(random_bytes(4)));$p->set_status('publish');$p->set_sku('');
$p->set_regular_price('20');$p->set_manage_stock(true);$p->set_stock_quantity(10);$p->save();$id=$p->get_id();
$e->capture('product',$id);$localKey=$e->identity('product',$id);$originalName=$p->get_name();
// Both stores create independently before either sees the other. Same numeric ID, empty SKU.
$foreign=$e->adapter->product($id);$foreign['key']='ps:product:'.$id;$foreign['name']='KerAwen fixture PS '.bin2hex(random_bytes(4));
$foreign['prices']=['basis'=>'net','regular'=>'21.000000','sale'=>null,'tax_rate'=>20];$foreign['inventory'][0]['key']=$foreign['key'];
$apply=new ReflectionMethod(Engine::class,'apply');$apply->setAccessible(true);
$eventId=bin2hex(random_bytes(16));
$message=['version'=>1,'source'=>'ps','op'=>'events','events'=>[['id'=>$eventId,'kind'=>'product','key'=>$foreign['key'],'payload'=>['base'=>'','hash'=>Engine::catalogHash($foreign),'data'=>$foreign]]]];
$body=Protocol::encode($message);$timestamp=(string)time();$nonce=bin2hex(random_bytes(16));
$request=new WP_REST_Request('POST','/wd29-bridge/v1/webhook');$request->set_body($body);$request->set_header('content-type','application/json');
$request->set_header('x-wd29-timestamp',$timestamp);$request->set_header('x-wd29-nonce',$nonce);$request->set_header('x-wd29-signature',Protocol::sign($body,$config['secret'],$timestamp,$nonce));
check(rest_do_request($request)->get_status()===200,'Signed delivery rejected');
$get=function()use($e,$eventId){return $e->sql("SELECT * FROM {b}queue WHERE direction='in' AND event_id=?",[$eventId])[0];};
$apply->invoke($e,$get());$row=$get();
check(!$e->mapping($foreign['key'])&&$row['state']==='pending'&&(int)$row['attempts']===1,'Tax-disabled import did not wait safely');
check(in_array('woo_taxes_disabled',array_column($e->actionsNeeded(),'code'),true),'Disabled native taxes not diagnosed');
check(wc_get_product($id)->get_name()===$originalName,'Unrelated original overwritten by foreign ID');
// A separate untaxed import continues while the taxed product waits.
$other=$foreign;$other['key']='ps:product:'.($id+1000000);$other['inventory'][0]['key']=$other['key'];$other['prices']['tax_rate']=0;
$otherId=bin2hex(random_bytes(16));$e->enqueue('in','product',$other['key'],['base'=>'','hash'=>Engine::catalogHash($other),'data'=>$other],$otherId);
$otherRow=$e->sql("SELECT * FROM {b}queue WHERE direction='in' AND event_id=?",[$otherId])[0];$apply->invoke($e,$otherRow);
check($e->mapping($other['key'])!==null,'Configuration error blocked another product');
update_option('woocommerce_calc_taxes','yes');
check((int)$get()['attempts']===0&&(int)$get()['next_try']===0,'Enabling native taxes did not unblock queue');
$apply->invoke($e,$get());
check(in_array('woo_tax_class',array_column($e->actionsNeeded(),'code'),true),'Missing native VAT class not diagnosed');
$rateId=WC_Tax::_insert_tax_rate(['tax_rate_country'=>'FR','tax_rate_state'=>'','tax_rate'=>'20','tax_rate_name'=>'Fixture TVA 20%','tax_rate_priority'=>1,'tax_rate_compound'=>0,'tax_rate_shipping'=>1,'tax_rate_order'=>0,'tax_rate_class'=>'']);
WC_Cache_Helper::invalidate_cache_group('taxes');
check((int)$get()['attempts']===0&&(int)$get()['next_try']===0,'Native rate creation did not unblock queue');
$apply->invoke($e,$get());
check($get()['state']==='applied','Taxed import still failed: '.$get()['error']);
$map=$e->mapping($foreign['key']);$mirrorId=(int)$map['local_id'];
check($mirrorId!==$id&&$e->identity('product',$id)===$localKey,'Simultaneous creation collapsed identities');
$mirror=wc_get_product($mirrorId);
check($mirror->get_name()===$foreign['name']&&wc_get_product($id)->get_name()===$originalName,'Distinct names not preserved');
check(abs((float)$mirror->get_regular_price()-25.2)<0.000001,'21 EUR net at 20% did not preserve 25.20 EUR gross');
check(abs((float)$e->adapter->product($mirrorId)['prices']['regular']-21)<0.000001,'Native VAT export changed net price');
// Replay the signed delivery; durable receipt must retain a single mirror.
check(rest_do_request($request)->get_status()===200,'Signed replay rejected instead of acknowledged');
check((int)$e->mapping($foreign['key'])['local_id']===$mirrorId&&count($e->sql("SELECT seq FROM {b}queue WHERE direction='in' AND event_id=?",[$eventId]))===1,'Replay duplicated product or event');
// Native Woo stock writes use decimal strings; capture must still produce exactly one delta.
wc_update_product_stock($mirror,2,'decrease');$e->capture('product',$mirrorId);
$stock=$e->sql("SELECT payload FROM {b}queue WHERE direction='out' AND kind='stock' AND record_key=?",[$foreign['key']]);
check(count($stock)===1&&json_decode($stock[0]['payload'],true)['delta']===-2,'Native decimal stock capture lost or duplicated a movement');
echo "PASS: signed native receipt, same-ID simultaneous creation, empty SKUs, tax-disabled/missing-rate recovery, independent queue progress, net/gross price preservation, replay, native stock delta\n";
