<?php
/** Run only against an isolated, disposable WordPress database named wd29woo. */
$root = getenv('WD29_WP_ROOT');
if (!$root || !is_file($root . '/wp-load.php')) { throw new RuntimeException('Set WD29_WP_ROOT to the disposable test installation.'); }
require $root . '/wp-load.php';
if (DB_NAME !== 'wd29woo' || parse_url(home_url(), PHP_URL_HOST) !== 'woo.example.test') { throw new RuntimeException('Refusing to run outside the disposable test database.'); }
add_filter('pre_wp_mail', '__return_true');
require_once __DIR__ . '/protocol.php';
use WD29\Bridge\Protocol;
use WD29\Bridge\Engine;
$e = wd29_bridge();
$e->install(); $e->sql('TRUNCATE TABLE {b}contacts'); $e->sql('TRUNCATE TABLE {b}queue'); $e->sql('TRUNCATE TABLE {b}map');
update_option('wd29_bridge_config', ['mode' => 'audit', 'peer' => 'https://ps.example.test/webhook', 'secret' => str_repeat('fixture-', 8)]);
update_option('woocommerce_currency', 'EUR'); update_option('woocommerce_calc_taxes', 'no');
$apply = new ReflectionMethod(Engine::class, 'apply'); $apply->setAccessible(true);
$ready = new ReflectionMethod(Engine::class, 'ready'); $ready->setAccessible(true);
$drain = function () use ($e,$apply,$ready) {
    for ($round = 0; $round < 20; $round++) {
        $rows = $ready->invoke($e,'in'); if (!$rows) { break; }
        foreach ($rows as $row) { $apply->invoke($e,$row); }
    }
};
$send = function ($id,$kind,$key,$payload) use ($e,$drain) {
    $e->receive(['source'=>'ps','op'=>'events','events'=>[['id'=>$id,'kind'=>$kind,'key'=>$key,'payload'=>$payload]]]); $drain();
};
$p = new WC_Product_Simple(); $p->set_name('Fixture original'); $p->set_regular_price('12.50'); $p->set_price('12.50');
$p->set_status('publish'); $p->set_manage_stock(true); $p->set_stock_quantity(10); $p->save();
$e->capture('product',$p->get_id()); $data = $e->adapter->product($p->get_id());
expect($data['inventory'][0]['quantity'] === 10, 'Source stock export failed');
wc_update_product_stock($p,2,'decrease'); $e->capture('product',$p->get_id());
$rows = $e->sql("SELECT payload FROM {b}queue WHERE direction='out' AND kind='stock'");
expect(count($rows) === 1 && json_decode($rows[0]['payload'],true)['delta'] === -2,'Source sale did not produce exactly one delta');
$foreign = $data; $foreign['key'] = 'ps:product:900'; $foreign['inventory'][0]['key'] = $foreign['key'];
$foreign['brands']=['Fixture Brand']; $foreign['tags']=['Summer','Outlet'];
$foreign['name'] = 'Fixture remote'; $hash = Engine::catalogHash($foreign);
$send(str_repeat('a',32),'product',$foreign['key'],['base'=>'','hash'=>$hash,'data'=>$foreign]);
$mapped = $e->mapping($foreign['key']);
expect($mapped !== null,'Native product import failed: ' . json_encode($e->report()));
expect((int) wc_get_product($mapped['local_id'])->get_stock_quantity() === 10,'Initial quantity was not preserved');
$send(str_repeat('b',32),'stock',$foreign['key'],['delta'=>-3]);
expect((int) wc_get_product($mapped['local_id'])->get_stock_quantity() === 7,'Incoming stock delta failed');
$send(str_repeat('b',32),'stock',$foreign['key'],['delta'=>-3]);
expect((int) wc_get_product($mapped['local_id'])->get_stock_quantity() === 7,'Duplicate webhook decremented twice');
// A local sale that happens before its capture must survive an incoming stock update.
$remote = wc_get_product($mapped['local_id']); wc_update_product_stock($remote,1,'decrease');
$send(str_repeat('c',32),'stock',$foreign['key'],['delta'=>-2]);
$e->capture('product',(int) $mapped['local_id']);
expect((int) wc_get_product($mapped['local_id'])->get_stock_quantity() === 4,'Concurrent inventory movements were lost');
$last = $e->sql("SELECT payload FROM {b}queue WHERE direction='out' AND kind='stock' AND record_key=? ORDER BY seq DESC LIMIT 1",[$foreign['key']]);
expect(json_decode($last[0]['payload'],true)['delta'] === -1,'Incoming movement echoed as a local sale');
$unknown = $foreign; $unknown['key']='ps:product:901'; $unknown['inventory'][0]['key']=$unknown['key']; $unknown['inventory'][0]['quantity']=null;
$send(str_repeat('d',32),'product',$unknown['key'],['base'=>'','hash'=>Engine::catalogHash($unknown),'data'=>$unknown]);
$um=$e->mapping($unknown['key']); expect($um && !wc_get_product($um['local_id'])->managing_stock(),'Unknown count became an invented inventory');
$remote = wc_get_product($mapped['local_id']); $remote->set_name('Local revised title'); $remote->save(); $e->capture('product',(int)$mapped['local_id']);
$changed=$foreign; $changed['name']='Concurrent remote title';
$send(str_repeat('e',32),'product',$foreign['key'],['base'=>$hash,'hash'=>Engine::catalogHash($changed),'data'=>$changed]);
expect(wc_get_product($mapped['local_id'])->get_name()==='Local revised title','Conflict overwrote local data');
expect($e->sql('SELECT state FROM {b}queue WHERE event_id=?',[str_repeat('e',32)])[0]['state']==='conflict','Conflict was not surfaced');
$order=['key'=>'ps:order:100','number'=>'FIXTURE','status'=>'processing','currency'=>'EUR','total'=>'12.50','tax'=>'0','shipping_net'=>'0','shipping_tax'=>'0','discount'=>'0',
    'billing'=>['first_name'=>'Test','last_name'=>'Customer','email'=>'customer@example.test','address_1'=>'1 Test Street','city'=>'Test','postcode'=>'00000','country'=>'FR'],
    'shipping'=>[],'items'=>[['product'=>$foreign['key'],'name'=>'Fixture remote','quantity'=>1,'net'=>'12.50','tax'=>'0']],'created'=>'2026-01-01T12:00:00+00:00'];
$before=(int) wc_get_product($mapped['local_id'])->get_stock_quantity();
$send(str_repeat('f',32),'order',$order['key'],['base'=>'','hash'=>Protocol::fingerprint($order),'data'=>$order]);
$om=$e->mapping($order['key']); expect($om!==null,'Native order import failed: '.json_encode($e->report()));
expect((int) wc_get_product($mapped['local_id'])->get_stock_quantity()===$before,'Mirrored order deducted stock again');
$mirror=wc_get_order($om['local_id']); $mirror->update_status('cancelled');
expect((int) wc_get_product($mapped['local_id'])->get_stock_quantity()===$before,'Mirror cancellation added stock again');
$variant=$foreign; $variant['key']='ps:product:902'; $variant['type']='variable'; $variant['inventory']=[['key'=>$variant['key'],'quantity'=>null,'status'=>'instock','backorders'=>false],['key'=>'ps:variant:903','quantity'=>3,'status'=>'instock','backorders'=>false]];
$variant['attributes']=[['name'=>'Size','options'=>['M'],'variation'=>true]];
$variant['variants']=[['key'=>'ps:variant:903','sku'=>'','attributes'=>['Size'=>'M'],'prices'=>$variant['prices'],'weight_kg'=>'0','status'=>'publish']];
$send(str_repeat('1',32),'product',$variant['key'],['base'=>'','hash'=>Engine::catalogHash($variant),'data'=>$variant]);
$vm=$e->mapping('ps:variant:903'); expect($vm && (int)wc_get_product($vm['local_id'])->get_stock_quantity()===3,'Variation stock was not preserved');
$beforeEvents=count($e->sql("SELECT seq FROM {b}queue WHERE direction='out' AND kind='product' AND record_key=?",[$variant['key']]));
$e->capture('product',(int)$e->mapping($variant['key'])['local_id']);
expect(count($e->sql("SELECT seq FROM {b}queue WHERE direction='out' AND kind='product' AND record_key=?",[$variant['key']]))===$beforeEvents,'Native normalization created a synchronization echo');
$request=new WP_REST_Request('POST','/wd29-bridge/v1/webhook');
$body=Protocol::encode(['version'=>1,'source'=>'ps','op'=>'health']);$timestamp=(string)time();$nonce=str_repeat('9',32);
$request->set_body($body);$request->set_header('content-type','application/json');
$request->set_header('x-wd29-timestamp',$timestamp);$request->set_header('x-wd29-nonce',$nonce);
$request->set_header('x-wd29-signature',Protocol::sign($body,$e->config()['secret'],$timestamp,$nonce));
expect(rest_do_request($request)->get_status()===200,'Signed native REST request failed');
$request->set_header('x-wd29-signature',str_repeat('0',64));
expect(rest_do_request($request)->get_status()===401,'Invalid REST signature was accepted');
if (getenv('WD29_FIXTURE_OUTPUT')) { file_put_contents(getenv('WD29_FIXTURE_OUTPUT'),Protocol::encode($e->adapter->product($p->get_id()))); }
echo "PASS: native WooCommerce product, variation, initial stock, local delta, replay, concurrent delta, unknown quantity, conflict, order mirror and cancellation\n";
$roundtrip=$e->adapter->product((int)$mapped['local_id']);
expect($roundtrip['brands']===['Fixture Brand'] && $roundtrip['tags']===['Outlet','Summer'],'Native brand/tag roundtrip failed');
$discounted=$order; $discounted['key']='ps:order:101'; $discounted['total']='10.00'; $discounted['discount']='2.50';
$send(str_repeat('2',32),'order',$discounted['key'],['base'=>'','hash'=>Protocol::fingerprint($discounted),'data'=>$discounted]);
$dm=$e->mapping($discounted['key']); expect($dm!==null,'Declared source discount import failed');
$do=wc_get_order($dm['local_id']); expect(count($do->get_items('fee'))===1 && (float)$do->get_total()===10.0,'Source discount not recorded on mirror');
expect((int)wc_get_product($mapped['local_id'])->get_stock_quantity()===$before,'Discounted mirror changed stock');
echo "PASS: native brands, tags and declared global order discount\n";
$historic=$order; $historic['key']='ps:order:102'; $historic['items'][0]['product']='ps:product:999999';
$send(str_repeat('3',32),'order',$historic['key'],['base'=>'','hash'=>Protocol::fingerprint($historic),'data'=>$historic]);
$hm=$e->mapping($historic['key']); expect($hm!==null,'Missing catalog product blocked historical order');
expect($e->adapter->orderSummary((int)$hm['local_id'])['unlinked_lines']===1,'Unlinked line was lost');
$late=$foreign; $late['key']='ps:product:999999'; $late['inventory'][0]['key']=$late['key'];
$send(str_repeat('4',32),'product',$late['key'],['base'=>'','hash'=>Engine::catalogHash($late),'data'=>$late]);
$e->adapter->reconcileOrderLinks((int)$hm['local_id']);
expect($e->adapter->orderSummary((int)$hm['local_id'])['unlinked_lines']===0,'Late product link was not repaired');
expect((float)wc_get_order($hm['local_id'])->get_total()===12.5,'Late link changed historical total');
expect((int)wc_get_product($e->mapping($late['key'])['local_id'])->get_stock_quantity()===10,'Late link moved stock');
$contact=['key'=>'ps:customer:987','first_name'=>'Contact','last_name'=>'Fixture','email'=>'contact-only@example.test','phone'=>'1234','company'=>'Fixture','billing'=>[],'shipping'=>[],'guest'=>false,'deleted'=>false];
$send(str_repeat('5',32),'customer',$contact['key'],['base'=>'','hash'=>Protocol::fingerprint($contact),'data'=>$contact]);
$cm=$e->mapping($contact['key']); expect($cm!==null,'Contact mirror missing');
expect(!get_user_by('email',$contact['email']),'Contact mirror created a login account');
$send(str_repeat('5',32),'customer',$contact['key'],['base'=>'','hash'=>Protocol::fingerprint($contact),'data'=>$contact]);
expect(count($e->customerReport())===1,'Contact replay created duplicates');
$oldHash=Protocol::fingerprint($contact); $contact['phone']='5678';
$send(str_repeat('6',32),'customer',$contact['key'],['base'=>$oldHash,'hash'=>Protocol::fingerprint($contact),'data'=>$contact]);
expect($e->customer((int)$cm['local_id'])['phone']==='5678','Source contact edit did not arrive');
echo "PASS: historical unlinked order, late catalog attachment without stock movement, contact updates/replay without login accounts\n";
$sourceOrder=new WC_Order(); $sourceOrder->set_billing_first_name('Guest'); $sourceOrder->set_billing_last_name('Fixture'); $sourceOrder->set_billing_email('guest-fixture@example.test'); $sourceOrder->save();
$guestKey=$e->adapter->orderContact($sourceOrder->get_id()); expect(strpos($guestKey,'woo:guest:')===0,'Guest contact identity missing');
$e->capture('customer',$e->contactId($guestKey)); $guest=$e->customer($e->contactId($guestKey));
expect($guest['email']==='guest-fixture@example.test' && !isset($guest['password']) && !isset($guest['roles']),'Guest profile fields were not safely captured');
expect($e->adapter->orderContact((int)$hm['local_id'])===null,'Mirrored customer was re-exported as a new source');
$sourceUser=wp_insert_user(['user_login'=>'contact_fixture_'.uniqid(),'user_pass'=>'fixture-only-password','user_email'=>'contact_'.uniqid().'@example.test','role'=>'customer','first_name'=>'Profile','last_name'=>'Fixture']);
expect(!is_wp_error($sourceUser),'Source customer fixture failed');
$cid=$e->contactId(Protocol::key('woo','customer',(int)$sourceUser)); $e->capture('customer',$cid);
expect($e->customer($cid)['first_name']==='Profile','Native customer profile was not exported');
echo "PASS: native customer and guest source profiles, no mirror contact echo\n";
$custom=$historic; $custom['key']='ps:order:989'; $custom['status']='ps-state-77'; $custom['source_status']=['id'=>77,'label'=>'Reçue'];
$send(str_repeat('8',32),'order',$custom['key'],['base'=>'','hash'=>Protocol::fingerprint($custom),'data'=>$custom]);
$cm=$e->mapping($custom['key']); expect($cm!==null,'Custom source status blocked order');
expect(wc_get_order($cm['local_id'])->get_status()==='ps-state-77','Custom status was not retained');
expect((wc_get_order_statuses()['wc-ps-state-77']??'')==='PrestaShop: Reçue','Source label was not registered');
update_option('wd29_bridge_status_mapping',['ps-state-77'=>'on-hold'],false);
$e->adapter->applyStatusMappings();
expect(wc_get_order($cm['local_id'])->get_status()==='on-hold','Configured mapping was not applied');
expect($e->adapter->order($cm['local_id'])['status']==='ps-state-77','Presentation mapping changed source status');
expect((float)wc_get_order($cm['local_id'])->get_total()===12.5,'Status mapping changed historical total');
update_option('wd29_bridge_status_mapping',[],false); $e->adapter->applyStatusMappings();
expect(wc_get_order($cm['local_id'])->get_status()==='ps-state-77','Automatic mapping could not be restored');
echo "PASS: automatic custom source status, configurable mapping and canonical source status preservation\n";
update_option('wd29_bridge_config', ['mode'=>'disabled']);

$audited = $e->catalogAudit();
expect(count($audited)>0, "Catalog audit omitted captured products");
expect(strpos(json_encode($audited), "customer@example.test") === false, "Catalog audit leaked order data");
echo "PASS: catalog audit includes products without customer order data\n";
