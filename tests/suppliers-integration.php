<?php
$root=getenv('WD29_WP_ROOT'); if (!$root) { throw new RuntimeException('Disposable WP root required.'); }
require $root.'/wp-load.php';
if (DB_NAME!=='wd29woo' || parse_url(home_url(),PHP_URL_HOST)!=='woo.example.test') { throw new RuntimeException('Disposable WP database required.'); }
require_once __DIR__.'/../includes/Suppliers.php';
use WD29\Bridge\Suppliers;
function supplierCheck($ok,$message) { if (!$ok) { throw new RuntimeException($message); } }
$p=new WC_Product_Simple(); $p->set_name('Supplier fixture'); $p->set_regular_price('12'); $p->update_meta_data('_wd29_supplier_name','Existing primary'); $p->save();
try {
 $a=['name'=>'Fixture supplier A','reference'=>'A-1','purchase_price_net'=>'3.25','currency'=>'EUR'];
 $b=['name'=>'Fixture supplier B','reference'=>'B-1','purchase_price_net'=>'0','currency'=>'USD'];
 Suppliers::apply($p,[$a,$b]); $p->save();
 $p=wc_get_product($p->get_id()); supplierCheck(Suppliers::export($p)===Suppliers::validate([$a,$b]),'Supplier metadata roundtrip differs');
 $a['reference']='A-2'; Suppliers::apply($p,[$a]); Suppliers::apply($p,[$a]); $p->save();
 supplierCheck(count(Suppliers::export($p))===2 && Suppliers::export($p)[0]['reference']==='A-2','Additive upsert/replay lost unrelated supplier');
 supplierCheck($p->get_meta('_wd29_supplier_name')==='Existing primary','Primary supplier changed');
 foreach (['price','currency','duplicate'] as $kind) {
  $bad=[$a]; if ($kind==='price') { $bad[0]['purchase_price_net']='-1'; } elseif ($kind==='currency') { $bad[0]['currency']='ZZZ'; } else { $bad[]=$a; }
  $failed=false; try { Suppliers::apply($p,$bad); } catch (RuntimeException $e) { $failed=true; } supplierCheck($failed,'Invalid supplier '.$kind.' accepted');
 }
 echo "PASS: supplier metadata roundtrip, additive replay, primary preservation and validation\n";
} finally { $p->delete(true); }
