<?php
require_once __DIR__.'/../includes/Protocol.php';
require_once __DIR__.'/../includes/Shopify/Admin.php';
use WD29\Bridge\Shopify\Client;
use WD29\Bridge\Shopify\Model;
use WD29\Bridge\Shopify\Failure;
use WD29\Bridge\Shopify\Admin;
$checks=0;
function shopify_check($value,string $message):void { global $checks; $checks++; if (!$value) { throw new RuntimeException($message); } }
function shopify_reject(callable $fn,string $code):void { try { $fn(); } catch (Failure $e) { shopify_check($e->reason===$code,'Unexpected failure: '.$e->reason.' instead of '.$code); return; } throw new RuntimeException('Accepted: '.$code); }
shopify_check(Client::shop('ONE-Test.myshopify.com')==='one-test.myshopify.com','normalize store domain');
foreach (['https://one.myshopify.com','one.myshopify.com.evil.test','127.0.0.1','localhost','one.myshopify.com/path','one.myshopify.com:443','one.myshopify.com@evil.test','-one.myshopify.com'] as $shop) { shopify_reject(static function()use($shop){Client::shop($shop);},'invalid_shop_domain'); }
shopify_check(Client::gid('Product','9007199254740999')==='gid://shopify/Product/9007199254740999','GIDs never rounded through JavaScript numbers');
shopify_reject(static function(){Client::gid('Product','gid://shopify/Customer/1');},'invalid_resource_id');
shopify_reject(static function(){Client::gid('Product',0);},'invalid_resource_id');
shopify_check(Model::stockPlan(10,10,7)==='pull','remote stock adjustment');
shopify_check(Model::stockPlan(10,8,10)==='push','local stock adjustment');
shopify_check(Model::stockPlan(10,7,7)==='same','same change not double deducted');
shopify_check(Model::stockPlan(null,null,null)==='same','unknown inventory stays unknown');
shopify_reject(static function(){Model::stockPlan(10,8,7);},'inventory_concurrent_changes');
shopify_reject(static function(){Model::stockPlan(null,0,null);},'inventory_management_changed');
shopify_reject(static function(){Model::stockPlan(2,null,2);},'inventory_management_changed');
shopify_check(Model::quantity(['inventoryItem'=>['tracked'=>false]])===null,'untracked is not zero');
shopify_reject(static function(){Model::quantity(['inventoryItem'=>['tracked'=>true,'inventoryLevel'=>null]]);},'inventory_not_at_selected_location');
shopify_check(Model::imageUrl('https://cdn.shopify.com/s/files/1/example.png')==='https://cdn.shopify.com/s/files/1/example.png','Shopify CDN URL accepted');
foreach(['https://127.0.0.1/s/files/a.png','https://cdn.shopify.com.evil.test/s/files/a.png','http://cdn.shopify.com/s/files/a.png','https://cdn.shopify.com/admin/a.png','https://user@cdn.shopify.com/s/files/a.png'] as $url){shopify_reject(static function()use($url){Model::imageUrl($url);},'unsupported_shopify_image_host');}
shopify_check(Model::fullAddressHash(['first_name'=>'A','last_name'=>'B'])!==Model::fullAddressHash(['first_name'=>'C','last_name'=>'B']),'address recipient participates in baseline');
shopify_reject(static function(){Model::addressInput(['address_1'=>'Street','country'=>'']);},'customer_address_incomplete');
$config=['shop'=>'one.myshopify.com','client_secret'=>str_repeat('secret',6)]; $body='{"id":12}';
$headers=['shop'=>$config['shop'],'id'=>'dedup-delivery-123456','hmac'=>base64_encode(hash_hmac('sha256',$body,$config['client_secret'],true))];
shopify_check(Model::verifyWebhook($body,$headers,$config)['id']===12,'raw-body webhook signature');
shopify_reject(static function()use($body,$headers,$config){Model::verifyWebhook($body.' ',$headers,$config);},'webhook_authentication_failed');
$bad=$headers; $bad['shop']='other.myshopify.com'; shopify_reject(static function()use($body,$bad,$config){Model::verifyWebhook($body,$bad,$config);},'webhook_authentication_failed');
$bad=$headers; $bad['hmac']='invalid'; shopify_reject(static function()use($body,$bad,$config){Model::verifyWebhook($body,$bad,$config);},'webhook_authentication_failed');
$old=['local_hash'=>'local','remote_hash'=>'remote'];
shopify_check(Model::direction('local','remote',$old)==='same','no profile echo');
shopify_check(Model::direction('new','remote',$old)==='push','local profile edit');
shopify_check(Model::direction('local','new',$old)==='pull','remote profile edit');
shopify_reject(static function()use($old){Model::direction('new','new',$old);},'profile_concurrent_changes');
foreach (['12.000'=>'12','0'=>'0','12.50'=>'12.5'] as $input=>$expected) { shopify_check(Model::money((string)$input)===$expected,'exact supported decimal'); }
foreach (['-1','NaN','1e3','1,23'] as $input) { shopify_reject(static function()use($input){Model::money($input);},'invalid_money'); }
$cfg=['shop'=>'one.myshopify.com','location'=>'gid://shopify/Location/1','client_secret'=>str_repeat('secret',6),'access_token'=>'fixture','mode'=>'audit'];
$parsed=Admin::settings($cfg,$cfg,false); shopify_check($parsed['mode']==='audit','audit settings accepted');
$input=$cfg; $input['shop']='https://one.myshopify.com/'; shopify_check(Admin::settings($input,$cfg,true)['shop']==='one.myshopify.com','user store URL normalized');
$input=$cfg; $input['shop']='two.myshopify.com'; shopify_reject(static function()use($input,$cfg){Admin::settings($input,$cfg,true);},'connection_migration_required');
$input=$cfg; $input['location']='2'; shopify_reject(static function()use($input,$cfg){Admin::settings($input,$cfg,true);},'connection_migration_required');
$input=$cfg; $input['access_token']=''; shopify_check(Admin::settings($input,$cfg,false)['access_token']==='fixture','blank secret preserves credential');
$input=$cfg; $input['client_secret']="oops\nheader"; shopify_reject(static function()use($input,$cfg){Admin::settings($input,$cfg,false);},'invalid_credentials');
$transport=static function($url,$token,$body){shopify_check($url==='https://one.myshopify.com/admin/api/2026-10/graphql.json','pinned API endpoint');shopify_check($token==='fixture','token stays in header channel');return ['status'=>200,'body'=>'{"data":{"shop":{"id":"123"}}}'];};
shopify_check((new Client($cfg,$transport))->query('{ shop { id } }')['shop']['id']==='123','GraphQL response');
foreach ([['status'=>429,'body'=>'','retry_after'=>17],['status'=>503,'body'=>''],['status'=>401,'body'=>''],['status'=>200,'body'=>'{"errors":[{"message":"private@example.invalid","extensions":{"code":"THROTTLED"}}]}'],['status'=>200,'body'=>'not json']] as $index=>$response) {
    $codes=['shopify_unavailable','shopify_unavailable','shopify_access_denied','shopify_throttled','shopify_invalid_response'];
    shopify_reject(static function()use($cfg,$response){(new Client($cfg,static function()use($response){return $response;}))->query('{shop{id}}');},$codes[$index]);
}
$calls=0;$saved=[];$auth=$cfg+['client_id'=>str_repeat('a',32)];$auth['auth']='client_credentials';$auth['access_token']='';
$client=new Client($auth,static function($url,$token,$body)use(&$calls){$calls++;if(strpos($url,'/oauth/')!==false){$input=json_decode($body,true);shopify_check($input['grant_type']==='client_credentials','current own-organization grant');return ['status'=>200,'body'=>'{"access_token":"renewed","expires_in":86399}'];}shopify_check($token==='renewed','renewed token used');return ['status'=>200,'body'=>'{"data":{}}'];},static function($token,$expires)use(&$saved){$saved=[$token,$expires];});
$client->query('{shop{id}}');$client->query('{shop{id}}');shopify_check($calls===3 && $saved[0]==='renewed' && $saved[1]>time()+86000,'token renewed once and persisted');
require_once __DIR__.'/shopify-fixture.php';
$promoFixture=new ShopifyFixture();
$specialProduct=$promoFixture->product('gid://shopify/Product/1','gid://shopify/ProductVariant/2','gid://shopify/InventoryItem/3','fixture');
foreach(['gift','subscription','bundle']as$kind){$row=$specialProduct;if($kind==='gift'){$row['isGiftCard']=true;$reason='gift_card_product_needs_authority_review';}elseif($kind==='subscription'){$row['requiresSellingPlan']=true;$reason='subscription_product_needs_review';}else{$row['variants']['nodes'][0]['requiresComponents']=true;$reason='bundle_product_needs_review';}shopify_reject(static function()use($row){Model::product($row);},$reason);}
$promoData=['code'=>'fixture-code','type'=>'percent','amount'=>'12.5','starts'=>time()-3600,'expires'=>null,'active'=>true];
$promoRow=$promoFixture->promotion('gid://shopify/DiscountCodeNode/1',\WD29\Bridge\Shopify\Promotion::input($promoData));
shopify_check(\WD29\Bridge\Shopify\Promotion::source($promoRow,'EUR')===$promoData,'ordinary promotion round trip');
foreach(['fixed_cart','fixed_product']as$type){$data=$promoData;$data['type']=$type;$data['amount']='3.5';$row=$promoFixture->promotion('gid://shopify/DiscountCodeNode/1',\WD29\Bridge\Shopify\Promotion::input($data));shopify_check(\WD29\Bridge\Shopify\Promotion::source($row,'EUR')===$data,'fixed promotion semantics');}
foreach(['quota','once','minimum','subscription','stacking','customer','items','multiple']as$case){$row=$promoRow;$d=&$row['codeDiscount'];
 if($case==='quota'){$d['usageLimit']=1;$code='shared_discount_quota_needs_authority_review';}
 elseif($case==='once'){$d['appliesOncePerCustomer']=true;$code='shared_discount_quota_needs_authority_review';}
 elseif($case==='minimum'){$d['minimumRequirement']=['__typename'=>'DiscountMinimumSubtotal'];$code='discount_eligibility_needs_review';}
 elseif($case==='subscription'){$d['customerGets']['appliesOnSubscription']=true;$code='discount_eligibility_needs_review';}
 elseif($case==='stacking'){$d['combinesWith']['orderDiscounts']=true;$code='discount_stacking_needs_review';}
 elseif($case==='customer'){$d['context']['__typename']='DiscountCustomers';$code='discount_rule_needs_review';}
 elseif($case==='items'){$d['customerGets']['items']['__typename']='DiscountProducts';$code='discount_rule_needs_review';}
 else{$d['codes']['nodes'][]=['code'=>'other'];$code='discount_multiple_codes_needs_review';}
 shopify_reject(static function()use($row){\WD29\Bridge\Shopify\Promotion::source($row,'EUR');},$code);unset($d);
}
$proxyConfig=['shop'=>'one.myshopify.com','client_secret'=>str_repeat('fixture-secret-',3),'account_proxy_path'=>'/apps/inklura-benefits'];$proxyTime=time();
$proxyValues=['extra'=>['1','2'],'shop'=>['one.myshopify.com'],'logged_in_customer_id'=>['42'],'path_prefix'=>['/apps/inklura-benefits'],'timestamp'=>[(string)$proxyTime]];
$signProxy=static function($values)use($proxyConfig){$chunks=[];$query=[];foreach($values as$key=>$list){$chunks[]=$key.'='.implode(',',$list);foreach($list as$value){$query[]=urlencode($key).'='.urlencode($value);}}sort($chunks,SORT_STRING);$query[]='signature='.hash_hmac('sha256',implode('',$chunks),$proxyConfig['client_secret']);return implode('&',$query);};
$proxyQuery=$signProxy($proxyValues);
shopify_check(\WD29\Bridge\Shopify\Account::subject($proxyQuery,$proxyConfig,$proxyTime)==='gid://shopify/Customer/42','proxy sorted query with repeated custom parameters');
shopify_reject(static function()use($proxyQuery,$proxyConfig,$proxyTime){\WD29\Bridge\Shopify\Account::subject(str_replace('customer_id=42','customer_id=43',$proxyQuery),$proxyConfig,$proxyTime);},'proxy_authentication_failed');
shopify_reject(static function()use($proxyQuery,$proxyConfig,$proxyTime){\WD29\Bridge\Shopify\Account::subject($proxyQuery,$proxyConfig,$proxyTime+301);},'proxy_authentication_failed');
shopify_reject(static function()use($proxyQuery,$proxyConfig,$proxyTime){\WD29\Bridge\Shopify\Account::subject($proxyQuery.'&logged_in_customer_id=43',$proxyConfig,$proxyTime);},'proxy_authentication_failed');
$values=$proxyValues;$values['logged_in_customer_id']=[''];shopify_check(\WD29\Bridge\Shopify\Account::subject($signProxy($values),$proxyConfig,$proxyTime)===null,'anonymous signed proxy has no customer subject');
$values=$proxyValues;$values['shop']=['other.myshopify.com'];$wrong=$signProxy($values);shopify_reject(static function()use($wrong,$proxyConfig,$proxyTime){\WD29\Bridge\Shopify\Account::subject($wrong,$proxyConfig,$proxyTime);},'proxy_authentication_failed');
$values=$proxyValues;$values['path_prefix']=['/apps/other'];$wrong=$signProxy($values);shopify_reject(static function()use($wrong,$proxyConfig,$proxyTime){\WD29\Bridge\Shopify\Account::subject($wrong,$proxyConfig,$proxyTime);},'proxy_authentication_failed');
echo 'PASS Shopify protocol, settings, authentication and conflict contracts: '.$checks." checks\n";
