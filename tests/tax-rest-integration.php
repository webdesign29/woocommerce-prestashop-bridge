<?php
/** Native Woo tax CRUD, against newly created synthetic rates in the disposable fixture only. */
$root=getenv('WD29_WP_ROOT');
if(!$root||!is_file($root.'/wp-load.php')){throw new RuntimeException('Set WD29_WP_ROOT');}
require $root.'/wp-load.php';
if(DB_NAME!=='wd29woo'||parse_url(home_url(),PHP_URL_HOST)!=='woo.example.test'){throw new RuntimeException('Disposable fixture required');}
function tax_expect($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function tax_request($method,$path,$params=[]){$r=new WP_REST_Request($method,$path);foreach($params as $k=>$v){$r->set_param($k,$v);}return rest_do_request($r);}
$admins=get_users(['role'=>'administrator','number'=>1]);tax_expect((bool)$admins,'Fixture administrator missing');wp_set_current_user($admins[0]->ID);
$e=wd29_bridge();$e->install();$original=$e->config();
foreach(['disabled','audit','live'] as $mode){
 foreach(['empty','waiting'] as $queueCase){
 $config=$original;$config['mode']=$mode;$e->adapter->saveConfig($config);
 $created=tax_request('POST','/wc/v3/taxes',['country'=>'FR','rate'=>'5.5','name'=>'Disposable QIT tax '.$mode.' '.bin2hex(random_bytes(4)),'priority'=>1]);
 tax_expect($created->get_status()===201,'Native tax create failed: '.json_encode($created->get_data()));
 $id=(int)$created->get_data()['id'];
 tax_expect(tax_request('GET','/wc/v3/taxes/'.$id)->get_status()===200,'Native tax read failed');
 tax_expect(tax_request('PUT','/wc/v3/taxes/'.$id,['rate'=>'6.5'])->get_status()===200,'Native tax update failed');
 $eventId=null;$unrelatedId=null;
 if($queueCase==='waiting'){
  $eventId=bin2hex(random_bytes(16));$unrelatedId=bin2hex(random_bytes(16));
  $e->enqueue('in','product','ps:product:99999991',[], $eventId);
  $e->enqueue('in','stock','ps:product:99999992',[], $unrelatedId);
  $e->sql("UPDATE {b}queue SET state='failed',attempts=8,error=? WHERE event_id=?",['No matching WooCommerce tax class.',$eventId]);
  $e->sql("UPDATE {b}queue SET state='failed',attempts=8,error='Unrelated stock error' WHERE event_id=?",[$unrelatedId]);
 }
 $deleted=tax_request('DELETE','/wc/v3/taxes/'.$id,['force'=>true]);
 tax_expect($deleted->get_status()===200,'Native tax delete returned '.$deleted->get_status().' with '.$mode.' bridge: '.json_encode($deleted->get_data()));
 tax_expect((int)$deleted->get_data()['id']===$id,'Delete response lost native tax ID');
 tax_expect(!WC_Tax::_get_tax_rate($id),'Synthetic rate was not deleted');
 tax_expect(tax_request('DELETE','/wc/v3/taxes/'.$id,['force'=>true])->get_status()===400,'Missing-rate deletion no longer preserves native validation');
 if($eventId){
  $row=$e->sql('SELECT state,attempts,next_try FROM {b}queue WHERE event_id=?',[$eventId])[0];
  tax_expect($row['state']==='pending'&&(int)$row['attempts']===0&&(int)$row['next_try']===0,'Tax deletion did not selectively requeue fiscal event');
  tax_expect($e->sql('SELECT state FROM {b}queue WHERE event_id=?',[$unrelatedId])[0]['state']==='failed','Tax deletion retried unrelated stock failure');
 }
 }

}
// A failure in optional bridge recovery cannot change a successful native DELETE to 500.
$created=tax_request('POST','/wc/v3/taxes',['country'=>'FR','rate'=>'7.5','name'=>'Disposable retry fault '.bin2hex(random_bytes(4))]);
tax_expect($created->get_status()===201,'Fault fixture tax create failed');$id=(int)$created->get_data()['id'];
$inject=function($query){if(strpos($query,'wd29_bridge_queue')!==false){throw new RuntimeException('Injected retry query failure');}return $query;};
add_filter('query',$inject);
try{$deleted=tax_request('DELETE','/wc/v3/taxes/'.$id,['force'=>true]);}finally{remove_filter('query',$inject);}
tax_expect($deleted->get_status()===200,'Optional retry failure broke native tax deletion');
tax_expect(!WC_Tax::_get_tax_rate($id),'Fault fixture native rate was not deleted');
$e->adapter->saveConfig($original);
echo "PASS: native Woo REST tax create/read/update/delete in disabled, audit and live modes; empty and waiting queues; selective recovery\n";
