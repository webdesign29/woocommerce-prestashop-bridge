<?php
$root=getenv('WD29_WP_ROOT');
if (!$root) { throw new RuntimeException('Set disposable WD29_WP_ROOT.'); }
require $root.'/wp-load.php';
if (DB_NAME!=='wd29woo' || parse_url(home_url(),PHP_URL_HOST)!=='woo.example.test') { throw new RuntimeException('Disposable database required.'); }
require __DIR__.'/protocol.php';
use WD29\Bridge\CustomFields;
if (!function_exists('acf_add_local_field_group')) { throw new RuntimeException('Install ACF in disposable fixture.'); }
acf_add_local_field_group(['key'=>'group_wd29_fixture','title'=>'Fixture','fields'=>[
 ['key'=>'field_wd29_note','name'=>'fixture_note','type'=>'text','label'=>'Note'],
 ['key'=>'field_wd29_choice','name'=>'fixture_choice','type'=>'checkbox','label'=>'Choice','choices'=>['a'=>'A','b'=>'B']],
 ['key'=>'field_wd29_relation','name'=>'fixture_relation','type'=>'file','label'=>'Unsupported file'],
]]);
$rules=[['id'=>'note','source'=>'acf','key'=>'field_wd29_note'],['id'=>'choice','source'=>'acf','key'=>'field_wd29_choice'],['id'=>'extra','source'=>'meta','key'=>'fixture_extra']];
update_option('wd29_bridge_custom_fields',CustomFields::rules($rules));
try {
 $p=new WC_Product_Simple(); $p->set_name('Custom fixture'); $p->set_regular_price('10'); $p->save();
 $fields=['note'=>['present'=>true,'value'=>'Été "bleu"'],'choice'=>['present'=>true,'value'=>['a','b']],'extra'=>['present'=>true,'value'=>['zero'=>0,'false'=>false,'empty'=>'','nested'=>['x'=>1]]],'ignored'=>['present'=>true,'value'=>'never written']];
 CustomFields::apply($p,$fields); $p->save();
 $p=wc_get_product($p->get_id());
 expect(get_post_meta($p->get_id(),'_fixture_note',true)==='field_wd29_note','ACF reference missing');
 expect(get_field('field_wd29_note',$p->get_id())==='Été "bleu"','Native ACF read failed');
 expect(get_field('field_wd29_choice',$p->get_id())===['a','b'],'ACF choice read failed');
 unset($fields['ignored']); expect(CustomFields::export($p)===$fields,'Custom JSON roundtrip failed');
 expect(!$p->meta_exists('ignored'),'Non-allowed field written');
 CustomFields::apply($p,['note'=>['present'=>false,'value'=>null]]); $p->save();
 expect(!metadata_exists('post',$p->get_id(),'fixture_note') && !metadata_exists('post',$p->get_id(),'_fixture_note'),'Deletion did not clear ACF value/reference');
 expect($p->meta_exists('fixture_extra'),'Omitted mapping was removed');
 $v=new WC_Product_Variation(); $v->set_parent_id($p->get_id()); $v->set_regular_price('10'); $v->save();
 CustomFields::apply($v,$fields); $v->save(); expect(CustomFields::export(wc_get_product($v->get_id()))===$fields,'Variation metadata lost');
 foreach (['_price','_stock','_wd29_secret','api_token'] as $key) {
  $blocked=false; try { CustomFields::rules([['id'=>'bad','source'=>'meta','key'=>$key]]); } catch (Throwable $e) { $blocked=true; } expect($blocked,'Reserved key accepted');
 }
 $blocked=false; try { CustomFields::value(new stdClass()); } catch (Throwable $e) { $blocked=true; } expect($blocked,'Object accepted');
 $blocked=false; try { CustomFields::apply($p,['choice'=>['present'=>true,'value'=>['unknown']]]); } catch (Throwable $e) { $blocked=true; } expect($blocked,'Unknown ACF choice accepted');
 update_option('wd29_bridge_custom_fields',[['id'=>'relation','source'=>'acf','key'=>'field_wd29_relation']]);
 $blocked=false; try { CustomFields::export($p); } catch (Throwable $e) { $blocked=true; } expect($blocked,'Unsupported ACF file accepted');
 echo "PASS: native ACF values/references, choices, custom JSON, variation, tombstone, omission, allowlist and reserved fields\n";
} finally { update_option('wd29_bridge_custom_fields',[]); }
