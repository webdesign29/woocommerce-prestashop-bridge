<?php
$root=getenv('WD29_WP_ROOT');$commerce=getenv('INKLURA_COMMERCE_ROOT');
if(!$root || !$commerce || !is_file($root.'/wp-load.php') || !is_file($commerce.'/shared/RewardsAccount.php'))throw new RuntimeException('Disposable WordPress and Commerce source roots required');
require $root.'/wp-load.php';
if(DB_NAME!=='wd29woo' || parse_url(home_url(),PHP_URL_HOST)!=='woo.example.test')throw new RuntimeException('Disposable host/database required');
require_once $commerce.'/shared/RewardsAccount.php';
use WD29\Bridge\Shopify\Account;
use WD29\Bridge\Shopify\Admin;
use WD29\Bridge\Shopify\Client;
use WD29\Bridge\Shopify\Failure;
$checks=0;$reads=0;
function proxy_check($ok,$why){global $checks;++$checks;if(!$ok)throw new RuntimeException($why);}
function proxy_denied($fn){global $checks;try{$fn();}catch(Throwable $e){++$checks;return;}throw new RuntimeException('Unexpected account access');}
$old=get_option('wd29_bridge_config');$licence=get_option('wd29_bridge_licence');$oldShopify=Admin::config();$query=$_SERVER['QUERY_STRING']??'';
$disabled=(array)$old;$disabled['mode']='disabled';update_option('wd29_bridge_config',$disabled,false);add_filter('pre_wp_mail','__return_true',PHP_INT_MAX);
try{
 $seed=time().random_int(100000,999999);$remote=Client::gid('Customer',$seed);$customer=new WC_Customer();$customer->set_username('proxy_fixture_'.$seed);$customer->set_email('proxy-'.$seed.'@example.invalid');$customer->set_role('customer');$customer->set_password(wp_generate_password(48));$customer->update_meta_data('_inklura_shopify_gid',$remote);$customer->save();$id=$customer->get_id();$repo=Admin::repo();$repo->install();$repo->bind('customer',$remote,$id);
 $live=$disabled;$live['mode']='live';$live['native_customers']=true;update_option('wd29_bridge_config',$live,false);update_option('wd29_bridge_licence',['unlicensed_since'=>time()],false);
 $key=WD29\Bridge\OrderCustomers::source(wd29_bridge(),$id);$reader=static function($subject)use($key,&$reads){++$reads;proxy_check($subject===$key,'Reader received another customer');return ['ok'=>true,'authority'=>'kerawen','customer_key'=>$subject,'capabilities'=>['read'=>true],'loyalty'=>['points'=>37],'rules'=>[['kind'=>'gift_card','code'=>'fixture-<script>','available'=>true,'reduction_amount'=>'10.00','currency'=>'EUR']]];};
 $account=Account::read($remote,$repo,$reader);proxy_check($account['points']==='37' && $account['rules'][0]['amount']==='10.00','Canonical account not projected');$html=Inklura\CommercePro\RewardsAccount::html($account);proxy_check(strpos($html,'&lt;script&gt;')!==false && strpos($html,'<script>')===false,'Account benefit text not escaped');
 proxy_denied(static function()use($repo,$reader){Account::read('gid://shopify/Customer/99999999999999999999',$repo,$reader);});proxy_check($reads===1,'Unmapped account read authority');
 update_user_meta($id,'_wd29_bridge_customer_disabled',true);proxy_denied(static function()use($remote,$repo,$reader){Account::read($remote,$repo,$reader);});update_user_meta($id,'_wd29_bridge_customer_disabled',false);
 update_user_meta($id,'_inklura_shopify_gid','gid://shopify/Customer/1');proxy_denied(static function()use($remote,$repo,$reader){Account::read($remote,$repo,$reader);});update_user_meta($id,'_inklura_shopify_gid',$remote);
 $user=new WP_User($id);$user->add_role('administrator');proxy_denied(static function()use($remote,$repo,$reader){Account::read($remote,$repo,$reader);});$user->remove_role('administrator');proxy_check($reads===1,'Privileged/disabled/rebound customer read authority');
 $cfg=['mode'=>'live','shop'=>'one.myshopify.com','client_secret'=>str_repeat('fixture-secret-',3),'account_proxy'=>true];update_option('inklura_shopify_config',$cfg,false);$_SERVER['QUERY_STRING']='shop=one.myshopify.com';$request=new WP_REST_Request('GET','/inklura-sync/v1/shopify/account');$response=rest_do_request($request);proxy_check($response->get_status()===403 && strpos($response->get_data(),'37')===false,'Unsigned route exposed balance');proxy_check(strpos($response->get_headers()['Cache-Control'],'no-store')!==false,'Account route cacheable');
 $params=['shop'=>'one.myshopify.com','timestamp'=>(string)time(),'logged_in_customer_id'=>'','path_prefix'=>'/apps/inklura-benefits'];$chunks=[];foreach($params as$k=>$v)$chunks[]=$k.'='.$v;sort($chunks,SORT_STRING);$params['signature']=hash_hmac('sha256',implode('',$chunks),$cfg['client_secret']);$_SERVER['QUERY_STRING']=http_build_query($params);$response=rest_do_request($request);proxy_check($response->get_status()===200 && strpos($response->get_data(),'Sign in')!==false,'Anonymous signed storefront request not handled');
 $cfg['account_proxy']=false;update_option('inklura_shopify_config',$cfg,false);proxy_check(rest_do_request($request)->get_status()===404,'Disabled account route still active');
 echo "PASS native Shopify account ownership and REST guards: $checks checks\n";
}finally{update_option('wd29_bridge_config',$old,false);update_option('wd29_bridge_licence',$licence,false);update_option('inklura_shopify_config',$oldShopify,false);$_SERVER['QUERY_STRING']=$query;}
