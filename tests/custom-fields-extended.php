<?php
$root=getenv('WD29_WP_ROOT');
if (!$root) { throw new RuntimeException('Set disposable WD29_WP_ROOT.'); }
require $root.'/wp-load.php';
if (DB_NAME!=='wd29woo' || parse_url(home_url(),PHP_URL_HOST)!=='woo.example.test') { throw new RuntimeException('Disposable database required.'); }
require __DIR__.'/protocol.php';
use WD29\Bridge\CustomFields;
if (!function_exists('acf_add_local_field_group')) { throw new RuntimeException('Install ACF in disposable fixture.'); }
function rejected(callable $f,string $message): void { $failed=false; try { $f(); } catch (Throwable $e) { $failed=true; } expect($failed,$message); }
function fixtureSyncOrders(): void {
 $sync=wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class);
 $sync->create_database_tables();
 for ($i=0;$i<20;$i++) { $batch=$sync->get_next_batch_to_process(100); if (!$batch) { return; } $sync->process_batch($batch); }
 throw new RuntimeException('Fixture order synchronization did not finish within bound.');
}
$group=['key'=>'field_wd29_group','name'=>'fixture_group','type'=>'group','label'=>'Details','sub_fields'=>[
 ['key'=>'field_wd29_caption','name'=>'caption','type'=>'text','label'=>'Caption'],
 ['key'=>'field_wd29_nested','name'=>'nested','type'=>'group','label'=>'Nested','sub_fields'=>[
  ['key'=>'field_wd29_amount','name'=>'amount','type'=>'number','label'=>'Amount'],
  ['key'=>'field_wd29_flags','name'=>'flags','type'=>'checkbox','label'=>'Flags','choices'=>['a'=>'A','b'=>'B']],
 ]],
]];
$repeater=['key'=>'field_wd29_rows','name'=>'fixture_rows','type'=>'repeater','label'=>'Rows','sub_fields'=>[
 ['key'=>'field_wd29_row_caption','name'=>'caption','type'=>'text','label'=>'Caption'],
]];
acf_add_local_field_group(['key'=>'group_wd29_extended','title'=>'Extended fixture','fields'=>[$group,$repeater,
 ['key'=>'field_wd29_danger_group','name'=>'fixture_danger_group','type'=>'group','label'=>'Danger','sub_fields'=>[
  ['key'=>'field_wd29_media','name'=>'document','type'=>'file','label'=>'Document'],
 ]],
 ['key'=>'field_wd29_user_note','name'=>'fixture_user_note','type'=>'text','label'=>'User note'],
]]);
acf_add_local_field_group(['key'=>'group_wd29_identity_fixture','title'=>'Identity fixture','fields'=>[
 ['key'=>'field_wd29_picture','name'=>'fixture_picture','type'=>'image','label'=>'Picture','return_format'=>'id'],
 ['key'=>'field_wd29_related','name'=>'fixture_related','type'=>'relationship','label'=>'Related','post_type'=>['product'],'return_format'=>'id'],
 ['key'=>'field_wd29_postobject','name'=>'fixture_postobject','type'=>'post_object','label'=>'Related single','post_type'=>['product'],'return_format'=>'id'],
 ['key'=>'field_wd29_gallery','name'=>'fixture_gallery','type'=>'gallery','label'=>'Gallery','return_format'=>'id'],
]]);
$old=get_option('wd29_bridge_custom_fields',[]); $oldHpos=get_option('woocommerce_custom_orders_table_enabled','no'); $userId=0;
try {
 $defaults=CustomFields::rules([['id'=>'legacy','source'=>'meta','key'=>'fixture_legacy']]);
 expect($defaults[0]['entities']===['product','variation'],'Legacy mapping scope changed');
 foreach (['_order_total','billing_email','_payment_tokens','wp_capabilities','user_pass','_customer_user','_stock','_price'] as $key) {
  rejected(static function() use ($key) { CustomFields::rules([['id'=>'bad','source'=>'meta','key'=>$key,'entities'=>['order','customer']]]); },'Unsafe key accepted: '.$key);
 }
 rejected(static function() { CustomFields::rules([['id'=>'bad','source'=>'acf','key'=>'field_note','entities'=>['order']]]); },'Unverified ACF HPOS support accepted');
 rejected(static function() { CustomFields::rules([['id'=>'bad','source'=>'meta','key'=>'fixture_a','entities'=>['customer','customer']]]); },'Duplicate scope accepted');
 $rules=[['id'=>'product_note','source'=>'meta','key'=>'fixture_note'],['id'=>'order_note','source'=>'meta','key'=>'fixture_note','entities'=>['order']],['id'=>'customer_note','source'=>'meta','key'=>'fixture_note','entities'=>['customer']],['id'=>'group','source'=>'acf','key'=>'field_wd29_group']];
 update_option('wd29_bridge_custom_fields',CustomFields::rules($rules));
 $p=new WC_Product_Simple(); $p->set_name('Extended custom fixture'); $p->set_regular_price('10'); $p->save();
 $value=['caption'=>'Été','nested'=>['amount'=>'0','flags'=>['a','b']]];
 CustomFields::apply($p,['group'=>['present'=>true,'value'=>$value],'order_note'=>['present'=>true,'value'=>'must ignore']]); $p->save();
 expect(CustomFields::export(wc_get_product($p->get_id()))['group']['value']===$value,'Native nested group roundtrip lost values');
 expect(get_post_meta($p->get_id(),'_fixture_group_nested_amount',true)==='field_wd29_amount','Nested local ACF reference missing');
 expect(!$p->meta_exists('fixture_note'),'Wrong entity field applied');
 $before=get_field('field_wd29_group',$p->get_id(),false);
 rejected(static function() use ($p) { CustomFields::apply($p,['product_note'=>['present'=>true,'value'=>'partial write'],'group'=>['present'=>true,'value'=>['caption'=>'bad']]]); },'Incomplete group accepted');
 expect(!$p->meta_exists('fixture_note'),'Validation error caused partial CRUD mutation');
 expect(get_field('field_wd29_group',$p->get_id(),false)===$before,'Invalid group changed native ACF value');
 CustomFields::apply($p,['group'=>['present'=>false,'value'=>null]]); $p->save();
 expect(!metadata_exists('post',$p->get_id(),'fixture_group_nested_amount'),'Group tombstone left child value');
 expect(!metadata_exists('post',$p->get_id(),'_fixture_group_nested_amount'),'Group tombstone left child reference');
 fixtureSyncOrders();
 update_option('woocommerce_custom_orders_table_enabled','yes');
 $order=wc_create_order();
 expect($order->get_data_store()->get_current_class_name()==='Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStore','Fixture must exercise real HPOS order storage');
 $note=['present'=>true,'value'=>['zero'=>0,'text'=>'internal fixture']];
 CustomFields::apply($order,['order_note'=>$note,'product_note'=>['present'=>true,'value'=>'ignore']]); $order->save();
 expect(CustomFields::export(wc_get_order($order->get_id()))===['order_note'=>$note],'Order CRUD scope/roundtrip failed');
 CustomFields::apply($order,['order_note'=>['present'=>false,'value'=>null]]); $order->save();
 expect(CustomFields::export(wc_get_order($order->get_id()))['order_note']['present']===false,'Order tombstone failed');
 $userId=wp_create_user('wd29fixture_'.bin2hex(random_bytes(6)),wp_generate_password(32),'fixture_'.bin2hex(random_bytes(6)).'@example.test');
 if (is_wp_error($userId)) { throw new RuntimeException($userId->get_error_message()); }
 CustomFields::applyCustomer($userId,['customer_note'=>$note]);
 expect(CustomFields::exportCustomer($userId)===['customer_note'=>$note],'Customer CRUD roundtrip failed');
 CustomFields::applyCustomer($userId,['customer_note'=>['present'=>false,'value'=>null]]);
 expect(!metadata_exists('user',$userId,'fixture_note'),'Customer tombstone failed');
 update_option('wd29_bridge_custom_fields',[['id'=>'user_acf','source'=>'acf','key'=>'field_wd29_user_note','entities'=>['customer']]]);
 CustomFields::applyCustomer($userId,['user_acf'=>['present'=>true,'value'=>'Private synthetic note']]);
 expect(get_user_meta($userId,'_fixture_user_note',true)==='field_wd29_user_note','ACF customer reference missing');
 expect(CustomFields::exportCustomer($userId)['user_acf']['value']==='Private synthetic note','ACF customer context incorrect');
 update_option('wd29_bridge_custom_fields',[['id'=>'danger','source'=>'acf','key'=>'field_wd29_danger_group']]);
 rejected(static function() use ($p) { CustomFields::export($p); },'Nested unsupported file schema accepted');
 // Exercise recursive wire validation even when fixture has free ACF without repeater storage.
 $method=new ReflectionMethod(CustomFields::class,'acfValue'); $method->setAccessible(true);
 $rows=[['caption'=>'one'],['caption'=>'two']];
 $local=$method->invoke(null,$repeater,$rows,true);
 expect($local===[['field_wd29_row_caption'=>'one'],['field_wd29_row_caption'=>'two']],'Repeater local key translation failed');
 expect($method->invoke(null,$repeater,$local,false)===$rows,'Repeater portable name translation failed');
 rejected(static function() use ($method,$repeater) { $method->invoke(null,$repeater,[['caption'=>'x','image'=>12]],true); },'Unknown repeater field accepted');
 if (acf_get_field_type('repeater')) {
  update_option('wd29_bridge_custom_fields',[['id'=>'rows','source'=>'acf','key'=>'field_wd29_rows']]);
  CustomFields::apply($p,['rows'=>['present'=>true,'value'=>$rows]]); $p->save();
  expect(CustomFields::export(wc_get_product($p->get_id()))['rows']['value']===$rows,'Native repeater roundtrip failed');
  CustomFields::apply($p,['rows'=>['present'=>false,'value'=>null]]); $p->save();
  expect(!metadata_exists('post',$p->get_id(),'fixture_rows_0_caption'),'Repeater tombstone left child');
  echo "PASS: native ACF repeater storage and deletion\n";
 } else { echo "SKIP: native repeater storage requires ACF PRO; recursive schema/value translation tested\n"; }
 // Synthetic local identities only; no media network request or live inventory.
 $linked=new WC_Product_Simple(); $linked->set_name('Related fixture'); $linked->set_regular_price('12'); $linked->save();
 $imageId=wp_insert_attachment(['post_title'=>'Synthetic image','post_mime_type'=>'image/png','post_status'=>'inherit','guid'=>'https://woo.example.test/wordpress/wp-content/uploads/wd29-synthetic.png']);
 update_post_meta($imageId,'_wp_attached_file','wd29-synthetic.png');
 $filter=static function($url,$id) use ($imageId) { return $id===$imageId?'https://woo.example.test/wordpress/wp-content/uploads/wd29-synthetic.png':$url; };
 add_filter('wp_get_attachment_url',$filter,10,2);
 $key='woo:product:'.$linked->get_id();
 $resolvers=[
  'productKey'=>static function($id) use ($linked,$key) { return $id===$linked->get_id()?$key:null; },
  'productId'=>static function($ref) use ($linked,$key) { return $ref===$key?$linked->get_id():0; },
  'imageId'=>static function($url) use ($imageId) { return $url==='https://ps.example.test/img/fixture.png'?$imageId:0; },
  'peerHost'=>'ps.example.test',
 ];
 $identityRules=[['id'=>'picture','source'=>'acf','key'=>'field_wd29_picture'],['id'=>'related','source'=>'acf','key'=>'field_wd29_related'],['id'=>'single','source'=>'acf','key'=>'field_wd29_postobject']];
 update_option('wd29_bridge_custom_fields',$identityRules);
 $payload=['picture'=>['present'=>true,'value'=>'https://ps.example.test/img/fixture.png'],'related'=>['present'=>true,'value'=>[$key]],'single'=>['present'=>true,'value'=>$key]];
 CustomFields::apply($p,$payload,null,$resolvers); $p->save();
 $portable=CustomFields::export($p,null,$resolvers);
 expect($portable['picture']['value']==='https://woo.example.test/wordpress/wp-content/uploads/wd29-synthetic.png','Image was not translated to host URL');
 expect($portable['related']===$payload['related'] && $portable['single']===$payload['single'],'Related product identity roundtrip failed');
 expect(get_field('field_wd29_related',$p->get_id(),false)===[$linked->get_id()] || get_field('field_wd29_related',$p->get_id(),false)===[(string)$linked->get_id()],'Relationship did not store native product ID');
 CustomFields::apply($p,$portable,null,$resolvers); $p->save();
 expect(CustomFields::export($p,null,$resolvers)===$portable,'Own-host image identity could not roundtrip without download');
 rejected(static function() use ($p,$resolvers) { CustomFields::apply($p,['related'=>['present'=>true,'value'=>['woo:product:99999999']]],null,$resolvers); },'Missing related product accepted');
 rejected(static function() use ($p,$resolvers) { CustomFields::apply($p,['picture'=>['present'=>true,'value'=>'https://untrusted.example/image.png']],null,$resolvers); },'Third-party image host accepted');
 rejected(static function() use ($p,$resolvers) { CustomFields::apply($p,['picture'=>['present'=>true,'value'=>'http://ps.example.test/image.png']],null,$resolvers); },'HTTP image accepted');
 $post=wp_insert_post(['post_type'=>'post','post_title'=>'Noncatalog fixture','post_status'=>'draft']);
 update_field('field_wd29_related',[$post],$p->get_id());
 rejected(static function() use ($p,$resolvers) { CustomFields::export($p,null,$resolvers); },'Noncatalog relationship accepted');
 wp_delete_post($post,true);
 $identityGroup=['key'=>'field_fixture_identity_group','name'=>'identity_group','type'=>'group','sub_fields'=>[['key'=>'field_inner_related','name'=>'related','type'=>'relationship']]];
 expect($method->invoke(null,$identityGroup,['related'=>[$key]],true,$resolvers)===['field_inner_related'=>[$linked->get_id()]],'Nested identity resolver not propagated');
 $gallery=['key'=>'field_wd29_gallery','name'=>'fixture_gallery','type'=>'gallery'];
 expect($method->invoke(null,$gallery,[$imageId],false,$resolvers)===['https://woo.example.test/wordpress/wp-content/uploads/wd29-synthetic.png'],'Gallery URL translation failed');
 if (acf_get_field_type('gallery')) {
  update_option('wd29_bridge_custom_fields',[['id'=>'gallery','source'=>'acf','key'=>'field_wd29_gallery']]);
  CustomFields::apply($p,['gallery'=>['present'=>true,'value'=>['https://ps.example.test/img/fixture.png']]],null,$resolvers); $p->save();
  expect(CustomFields::export($p,null,$resolvers)['gallery']['value']===['https://woo.example.test/wordpress/wp-content/uploads/wd29-synthetic.png'],'Native gallery roundtrip failed');
  echo "PASS: native ACF gallery storage\n";
 } else { echo "SKIP: native ACF gallery storage requires ACF PRO; URL translation tested\n"; }
 remove_filter('wp_get_attachment_url',$filter,10);
 wp_delete_attachment($imageId,true);
 echo "PASS: ACF image URL and canonical product identity roundtrips; unsafe hosts/unresolved IDs/noncatalog references rejected\n";
 echo 'PASS: scoped allowlist, order CRUD (HPOS='.($order->get_data_store()->get_current_class_name())."), customer CRUD/ACF, nested ACF groups and tombstones, safe schema rejection\n";
} finally {
 update_option('wd29_bridge_custom_fields',$old);
 if (get_option('woocommerce_custom_orders_table_enabled')!==$oldHpos) { fixtureSyncOrders(); update_option('woocommerce_custom_orders_table_enabled',$oldHpos); }
 if (is_int($userId) && $userId>0) { require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($userId); }
}
