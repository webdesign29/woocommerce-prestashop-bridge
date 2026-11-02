<?php
$_SERVER['REQUEST_METHOD']=$_SERVER['REQUEST_METHOD']??'GET';
$root=getenv('WD29_WP_ROOT');if(!$root)throw new RuntimeException('Fixture root required');require $root.'/wp-load.php';if(DB_NAME!=='wd29woo'||parse_url(home_url(),PHP_URL_HOST)!=='woo.example.test')throw new RuntimeException('Disposable fixture required');
set_exception_handler(static function(Throwable $error){fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);});
add_filter('pre_wp_mail','__return_true');$e=wd29_bridge();$e->install();$cfg=$e->config();$cfg['native_customers']=true;$e->adapter->saveConfig($cfg);
function check($ok,$m){if(!$ok)throw new RuntimeException($m);}
$s=(string)random_int(100000,999999);$contact=['key'=>'ps:customer:'.$s,'first_name'=>'Fixture','last_name'=>'Shared','email'=>'shared'.$s.'@example.test','phone'=>'','company'=>'','guest'=>false,'deleted'=>false,'active'=>true,'billing'=>['first_name'=>'Fixture','last_name'=>'Shared','address_1'=>'1 Test Street','city'=>'Brest','postcode'=>'29200','country'=>'FR'],'shipping'=>[]];
$applyContact=new ReflectionMethod($e,'applyCustomer');$applyContact->setAccessible(true);$applyContact->invoke($e,$contact);$id=(int)$e->sql('SELECT native_id FROM {b}account_links WHERE record_key=?',[$contact['key']])[0]['native_id'];$data=['key'=>'ps:order:'.$s,'number'=>$s,'status'=>'pending','currency'=>'EUR','total'=>'0','tax'=>'0','shipping_net'=>'0','shipping_tax'=>'0','discount'=>'0','customer_key'=>$contact['key'],'billing'=>['first_name'=>'Fixture','last_name'=>'Shared','email'=>$contact['email'],'address_1'=>'1 Test Street','city'=>'Brest','postcode'=>'29200','country'=>'FR'],'shipping'=>[],'items'=>[],'created'=>gmdate('c'),'refunds'=>[]];
$e->orderApplying=true;$order=$e->adapter->applyOrder($data,null);check((int)wc_get_order($order)->get_customer_id()===$id,'Imported registered order became guest');
$local=wc_create_order(['customer_id'=>$id]);$local->set_billing_email($contact['email']);$local->save();check($e->adapter->order($local->get_id())['customer_key']===$contact['key'],'Imported shopper acquired Woo identity on native purchase');check($e->adapter->orderContact($local->get_id())===$contact['key'],'Order contact duplicates imported identity');
$replay=$e->adapter->applyOrder($data,['local_id'=>$order]);check($replay===$order&&(int)wc_get_order($order)->get_customer_id()===$id,'Replay changed customer/order');
$guest=$data;$guest['key']='ps:order:'.((int)$s+1);$guest['customer_key']=null;$g=$e->adapter->applyOrder($guest,null);check((int)wc_get_order($g)->get_customer_id()===0,'Guest was merged');
$token=WP_Session_Tokens::get_instance($id)->create(time()+3600);$_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($id,time()+3600,'logged_in',$token);
check((int)apply_filters('determine_current_user',false)===$id,'Positive control: valid native login cookie failed');
$hash=get_userdata($id)->user_pass;$disabled=$contact;$disabled['active']=false;
$applyContact->invoke($e,$disabled);check(WD29\Bridge\CustomerAccounts::disabled($id),'Disabled source left active copy');
check(is_wp_error(apply_filters('wp_authenticate_user',get_userdata($id))),'Password authentication allowed');
check(!apply_filters('determine_current_user',false),'Existing valid login cookie allowed');
check(WD29\Bridge\OrderCustomers::source($e,$id)===$contact['key'],'Disabled historical customer lost identity');
$enabled=$contact;$enabled['active']=true;$applyContact->invoke($e,$enabled);check(!WD29\Bridge\CustomerAccounts::disabled($id),'Reactivated source copy stayed disabled');
check(!is_wp_error(apply_filters('wp_authenticate_user',get_userdata($id))),'Reactivated customer cannot authenticate');
check((int)apply_filters('determine_current_user',false)===$id,'Reactivation did not restore cookie authentication');
check(hash_equals($hash,get_userdata($id)->user_pass),'Account status changed password');
$native=new WC_Customer($id);$original=$native->get_billing_city();$native->set_billing_city('Local review edit');$native->save();
try{$applyContact->invoke($e,$enabled);throw new LogicException('Local edit was overwritten');}catch(WD29\Bridge\NativeCustomerReview $error){}
check((new WC_Customer($id))->get_billing_city()==='Local review edit','Local edit lost');$native->set_billing_city($original);$native->save();$applyContact->invoke($e,$enabled);
$user=new WP_User($id);$user->add_role('editor');try{$applyContact->invoke($e,$enabled);throw new LogicException('Privileged account was overwritten');}catch(WD29\Bridge\NativeCustomerReview $error){}$user->set_role('customer');
$deleted=$enabled;$deleted['deleted']=true;$applyContact->invoke($e,$deleted);check(WD29\Bridge\CustomerAccounts::disabled($id),'Deleted source did not disable retained account');
check(get_userdata($id)!==false,'Account was deleted');
check(WD29\Bridge\CustomerRecordGuard::inspect($e,$contact['key'])['state']==='linked','Retained disabled account was reported missing');
$storage=\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()?'HPOS':'legacy';
echo $storage." PASS native Woo imported registered order/account, canonical source on a new native purchase, replay, guest isolation, disabled password/cookie authentication, reactivation, password preservation and local/privileged edit protection\n";
