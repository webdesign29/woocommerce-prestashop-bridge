<?php
$root=getenv('WD29_WP_ROOT'); if (!$root) { throw new RuntimeException('Disposable WP root required.'); }
require $root.'/wp-load.php';
if (DB_NAME!=='wd29woo' || parse_url(home_url(),PHP_URL_HOST)!=='woo.example.test') { throw new RuntimeException('Disposable WP database required.'); }
require_once __DIR__.'/../includes/Refunds.php';
use WD29\Bridge\Refunds;
function checkRefund($ok,$message) { if (!$ok) { throw new RuntimeException($message); } }
$p=new WC_Product_Simple(); $p->set_name('Refund record fixture'); $p->set_regular_price('12'); $p->set_manage_stock(true); $p->set_stock_quantity(7); $p->save();
$o=new WC_Order(); $o->set_status('pending'); $o->set_currency('EUR'); $o->add_product($p,1); $o->set_total('12'); $o->save();
$mirror=new WC_Order(); $mirror->set_status('pending'); $mirror->set_total('12'); $mirror->save();
try {
 $refund=wc_create_refund(['order_id'=>$o->get_id(),'amount'=>'5','reason'=>'Fixture partial refund','refund_payment'=>false,'restock_items'=>false]);
 checkRefund(!is_wp_error($refund),'Native fixture refund failed');
 $rows=Refunds::export(wc_get_order($o->get_id()));
 checkRefund(count($rows)===1 && (float)$rows[0]['amount']===5.0 && $rows[0]['payment_refunded']===false,'Source refund export differs');
 $rows[0]['source_id']='ps:refund:123'; $data=['key'=>'ps:order:123','total'=>'12','refunds'=>$rows];
 Refunds::apply($mirror,$data); $mirror->save(); Refunds::apply($mirror,$data); $mirror->save();
 $fresh=wc_get_order($mirror->get_id());
 checkRefund(count($fresh->get_meta('_wd29_bridge_refunds'))===1 && count($fresh->get_refunds())===0,'Replay created native refund or duplicate ledger');
 checkRefund((int)wc_get_product($p->get_id())->get_stock_quantity()===7,'Record mirror moved stock');
 foreach (['negative','excess','duplicate','foreign'] as $mode) {
  $bad=$data;
  if ($mode==='negative') { $bad['refunds'][0]['amount']='-1'; }
  if ($mode==='excess') { $bad['refunds'][0]['amount']='13'; }
  if ($mode==='duplicate') { $bad['refunds'][]=$bad['refunds'][0]; }
  if ($mode==='foreign') { $bad['refunds'][0]['source_id']='woo:refund:123'; }
  $rejected=false; try { Refunds::apply($mirror,$bad); } catch (RuntimeException $e) { $rejected=true; }
  checkRefund($rejected,'Invalid '.$mode.' ledger accepted');
 }
 echo "PASS: source refund export, replay-safe audit-only mirror, zero stock/native refund side effects, invalid ledger rejection\n";
} finally {
 if (isset($refund) && $refund instanceof WC_Order_Refund) { $refund->delete(true); }
 $mirror->delete(true); $o->delete(true); $p->delete(true);
}
