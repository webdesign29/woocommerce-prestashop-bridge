<?php
// Local disposable stores ONLY. No network transport or KerAwen commercial module.
ob_start();
$fixturePlatform=$argv[1]??'';$fixtureAction=$argv[2]??'';$fixtureStatePath=$argv[3]??'';
if (!in_array($fixturePlatform,['woo','ps'],true) || strpos($fixtureStatePath,__DIR__.'/run-')!==0) {throw new RuntimeException('Fixture arguments required.');}
$fixtureState=is_file($fixtureStatePath)?json_decode(file_get_contents($fixtureStatePath),true):[];
if ($fixturePlatform==='woo') {
    require '/tmp/wd29-bridge-tests/wordpress/wp-load.php';
    if (DB_NAME!=='wd29woo'||parse_url(home_url(),PHP_URL_HOST)!=='woo.example.test') {throw new RuntimeException('Disposable Woo store required.');}
    add_filter('pre_wp_mail','__return_true');
    $e=wd29_bridge();
} else {
    $_SERVER['HTTP_HOST']='ps.example.test';$_SERVER['REQUEST_URI']='/';
    require '/tmp/wd29-bridge-tests/prestashop/config/config.inc.php';
    if (_DB_NAME_!=='wd29ps'||Configuration::get('PS_SHOP_DOMAIN')!=='ps.example.test') {throw new RuntimeException('Disposable PrestaShop required.');}
    Context::getContext()->shop=new Shop(1);Context::getContext()->language=new Language((int)Configuration::get('PS_LANG_DEFAULT'));Context::getContext()->currency=new Currency((int)Configuration::get('PS_CURRENCY_DEFAULT'));
    require_once '/tmp/wd29-bridge-tests/prestashop/app/AppKernel.php';$kernel=new AppKernel('prod',false);$kernel->boot();
    $e=Module::getInstanceByName('wd29woobridge')->bridge();
}
$fixtureConfig=$e->config();$fixtureConfig['mode']='audit';$fixtureConfig['native_customers']=false;
if($fixturePlatform==='woo'){update_option('wd29_bridge_config',$fixtureConfig);}else{Configuration::updateValue('WD29_BRIDGE_CONFIG',json_encode($fixtureConfig));}
$e->install();
use WD29\Bridge\Engine;
use WD29\Bridge\Protocol;
function check($value,$message){if(!$value){throw new RuntimeException($message);}}
function deliver($e,$fixturePlatform,$file){
    $bundle=json_decode(file_get_contents($file),true);$secret=str_repeat('disposable-e2e-',4);
    $message=Protocol::verify($bundle['body'],$secret,$bundle['headers']);
    check($message['source']!==$fixturePlatform,'Wrong bundle direction');$e->receive($message);
    $apply=new ReflectionMethod(Engine::class,'apply');$apply->setAccessible(true);
    foreach($message['events'] as $event){
        $row=$e->sql("SELECT * FROM {b}queue WHERE event_id=? AND direction='in'",[$event['id']])[0];
        if($row['state']==='pending'){
            $fixturePreviousEmployee=$fixturePlatform==='ps'?Context::getContext()->employee:null;
            $apply->invoke($e,$row);
            if($fixturePlatform==='ps'&&$row['kind']==='stock'){check(Context::getContext()->employee===$fixturePreviousEmployee,'Bridge stock movement leaked its system actor into Context');}
        }
        $result=$e->sql("SELECT state,error FROM {b}queue WHERE event_id=? AND direction='in'",[$event['id']])[0];
        check(in_array($result['state'],['applied','ignored'],true),'Incoming fixture event failed: '.json_encode($result));
    }
}
if($fixtureAction==='init'){
    check($fixturePlatform==='woo','Init must use Woo');
    $p=new WC_Product_Simple();$p->set_name('E2E concurrent stock '.bin2hex(random_bytes(4)));$p->set_status('publish');$p->set_regular_price('12');$p->set_tax_status('none');$p->set_manage_stock(true);$p->set_stock_quantity(20);$p->save();
    $e->capture('product',$p->get_id());$fixtureState=['product_key'=>$e->identity('product',$p->get_id()),'woo_product'=>$p->get_id()];
}elseif($fixtureAction==='receive'){
    deliver($e,$fixturePlatform,$argv[4]);
}elseif($fixtureAction==='sale'){
    $map=$e->mapping($fixtureState['product_key']);check($map!==null,'Product map absent');$id=(int)$map['local_id'];
    if($fixturePlatform==='woo'){
        $order=wc_create_order();$order->set_billing_first_name('Fixture');$order->set_billing_last_name('Only');$order->set_billing_email('e2e@example.test');$order->set_billing_address_1('1 Fixture Street');$order->set_billing_city('Brest');$order->set_billing_postcode('29200');$order->set_billing_country('FR');
        $order->set_payment_method('cod');$order->add_product(wc_get_product($id),2);$order->calculate_totals();$order->save();$order->update_status('processing');
        wc_maybe_reduce_stock_levels($order->get_id());wc_maybe_reduce_stock_levels($order->get_id());
        $e->capture('order',$order->get_id());$fixtureState['woo_order']=$order->get_id();$fixtureState['order_key']=$e->identity('order',$order->get_id());
    }else{
        // Native PrestaShop stock movement substitutes for an unavailable KerAwen sale.
        StockAvailable::updateQuantity($id,0,-3,1);
    }
    $e->capture('product',$id);
}elseif($fixtureAction==='cancel'){
    check($fixturePlatform==='woo','Cancellation is the actual Woo source order');$order=wc_get_order($fixtureState['woo_order']);$order->update_status('cancelled');
    wc_maybe_increase_stock_levels($order->get_id());wc_maybe_increase_stock_levels($order->get_id());$e->capture('order',$order->get_id());$e->capture('product',$fixtureState['woo_product']);
}elseif($fixtureAction==='restore'){
    check($fixturePlatform==='ps','Restoration fixture uses PS native stock');$map=$e->mapping($fixtureState['product_key']);StockAvailable::updateQuantity((int)$map['local_id'],0,3,1);$e->capture('product',(int)$map['local_id']);
}elseif(!in_array($fixtureAction,['export','status'],true)){throw new RuntimeException('Unknown fixture action.');}
file_put_contents($fixtureStatePath,json_encode($fixtureState));chmod($fixtureStatePath,0600);
$result=['platform'=>$fixturePlatform,'action'=>$fixtureAction];
if($fixtureAction==='export'){
    $keys=[$fixtureState['product_key']];if(isset($fixtureState['order_key'])){$keys[]=$fixtureState['order_key'];}
    $events=[];
    foreach($keys as $key){foreach($e->sql("SELECT * FROM {b}queue WHERE direction='out' AND record_key=? AND state='pending' ORDER BY seq",[$key]) as $row){$events[]=['seq'=>(int)$row['seq'],'id'=>$row['event_id'],'kind'=>$row['kind'],'key'=>$row['record_key'],'payload'=>json_decode($row['payload'],true)];}}
    usort($events,function($a,$b){return $a['seq']<=>$b['seq'];});foreach($events as &$event){unset($event['seq']);}unset($event);
    check(count($events)<=20,'Fixture bundle too large');
    $body=Protocol::encode(['version'=>1,'source'=>$fixturePlatform,'op'=>'events','events'=>$events]);$timestamp=(string)time();$nonce=bin2hex(random_bytes(16));
    $result=['body'=>$body,'headers'=>['timestamp'=>$timestamp,'nonce'=>$nonce,'signature'=>Protocol::sign($body,str_repeat('disposable-e2e-',4),$timestamp,$nonce)]];
}else{
    $map=$e->mapping($fixtureState['product_key']);if($map){$result['quantity']=$e->adapter->stockQuantity($map);
        if($fixturePlatform==='ps'){
            $stockId=(int)StockAvailable::getStockAvailableIdByProductId((int)$map['local_id'],0,1);
            $movements=$e->adapter->sql('SELECT id_employee,employee_firstname,employee_lastname,physical_quantity,sign FROM `'._DB_PREFIX_.'stock_mvt` WHERE id_stock=?',[$stockId]);
            $result['system_movements']=count($movements);
            foreach($movements as $movement){check((int)$movement['id_employee']===0&&$movement['employee_firstname']==='WD29'&&$movement['employee_lastname']==='Bridge','Stock ledger attributed bridge movement to staff');}
        }}
    if(isset($fixtureState['order_key'])){$om=$e->mapping($fixtureState['order_key']);if($om){$result['order']=$e->adapter->orderSummary((int)$om['local_id']);}}
}
while(ob_get_level()>0){ob_end_clean();}echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
