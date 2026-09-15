<?php
$root=getenv('WD29_WP_ROOT'); if (!$root) { throw new RuntimeException('Disposable root required.'); } require $root.'/wp-load.php';
if (DB_NAME!=='wd29woo' || parse_url(home_url(),PHP_URL_HOST)!=='woo.example.test') { throw new RuntimeException('Disposable database required.'); }
require __DIR__.'/protocol.php';
$e=wd29_bridge(); $e->install(); $suffix=(string)random_int(100000,999999);
$d=['key'=>'ps:customer:'.$suffix,'first_name'=>'Fixture','last_name'=>'Account','email'=>'account'.$suffix.'@example.test','company'=>'','guest'=>false,'deleted'=>false,'billing'=>['address_1'=>'1 Test Street','city'=>'Paris','postcode'=>'75001','country'=>'FR'],'shipping'=>[]];
$id=WD29\Bridge\CustomerAccounts::apply($e,$d); $c=new WC_Customer($id); $hash=get_userdata($id)->user_pass;
expect($c->get_billing_address_1()==='1 Test Street' && in_array('customer',get_userdata($id)->roles,true),'Native Woo account/address failed');
$d['first_name']='Updated'; expect(WD29\Bridge\CustomerAccounts::apply($e,$d)===$id,'Duplicate account on replay');
expect(get_userdata($id)->user_pass===$hash,'Account update reset password');
$other=$d; $other['key']='ps:customer:'.($suffix+1); $blocked=false; try { WD29\Bridge\CustomerAccounts::apply($e,$other); } catch (Throwable $error) { $blocked=true; } expect($blocked,'Email collision merged');
$ids=$e->adapter->ids('customer',0,10000); foreach ($ids as $contactId) { $data=$e->customer($contactId); expect($data['key']!=='woo:customer:'.$id,'Native mirror customer echoed as new origin'); }
echo "PASS: native Woo account, address, independent password retained, replay, collision and source exclusion\n";
