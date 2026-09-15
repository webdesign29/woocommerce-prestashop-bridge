<?php
/** Narrow native archive tests; only fresh disposable fixture products/media are touched. */
$fixturePlatform=$argv[1]??'';
function archiveCheck($value,$message){if(!$value){throw new RuntimeException($message);}}
if($fixturePlatform==='woo'){
    require '/tmp/wd29-bridge-tests/wordpress/wp-load.php';
    archiveCheck(DB_NAME==='wd29woo'&&parse_url(home_url(),PHP_URL_HOST)==='woo.example.test','Disposable Woo store required');
    add_filter('pre_wp_mail','__return_true');$e=wd29_bridge();$e->install();$e->catalogApplying=true;$p=null;$media=[];
    try {
        $p=new WC_Product_Simple();$p->set_name('Native archive fixture');$p->set_status('publish');$p->set_regular_price('17');$p->set_manage_stock(true);$p->set_stock_quantity(8);
        for($i=0;$i<2;$i++){
            $upload=wp_upload_bits('archive-fixture-'.bin2hex(random_bytes(5)).'.jpg',null,file_get_contents('/tmp/wd29-bridge-tests/prestashop/img/logo.jpg'));
            archiveCheck(empty($upload['error']),'Fixture upload failed');$aid=wp_insert_attachment(['post_title'=>'Archive fixture image','post_mime_type'=>'image/jpeg','post_status'=>'inherit'],$upload['file'],0,true);
            archiveCheck(!is_wp_error($aid),'Fixture attachment failed');$media[]=['id'=>$aid,'file'=>$upload['file'],'hash'=>hash_file('sha256',$upload['file'])];
        }
        $p->set_image_id($media[0]['id']);$p->set_gallery_image_ids([$media[1]['id']]);$p->save();$id=$p->get_id();
        archiveCheck($e->adapter->productExists($id),'Published Woo product not found');$e->adapter->archiveProduct($id);$after=wc_get_product($id);
        archiveCheck($after->get_status()==='draft','Woo mirror not unpublished');archiveCheck((int)$after->get_stock_quantity()===8&&$after->get_regular_price()==='17','Woo archive changed stock or price');
        archiveCheck((int)$after->get_image_id()===(int)$media[0]['id']&&array_map('intval',$after->get_gallery_image_ids())===[(int)$media[1]['id']],'Woo image associations changed');
        foreach($media as $image){archiveCheck(get_post_type($image['id'])==='attachment'&&hash_file('sha256',$image['file'])===$image['hash'],'Woo archive changed media');}
        wp_update_post(['ID'=>$id,'post_status'=>'trash']);archiveCheck($e->adapter->productExists($id),'Woo trash treated as permanent deletion');$e->adapter->archiveProduct($id);archiveCheck(get_post_status($id)==='trash','Woo archive restored trash');
        $p->delete(true);archiveCheck(!$e->adapter->productExists($id),'Permanently deleted Woo product still exists');$p=null;
        echo "PASS native Woo archive: draft, unchanged stock/price/media; trash exists and stays trash; permanent deletion detected\n";
    } finally {if($p&&$p->get_id()){$p->delete(true);}foreach($media as $image){wp_delete_attachment($image['id'],true);}$e->catalogApplying=false;}
}elseif($fixturePlatform==='ps'){
    $_SERVER['HTTP_HOST']='ps.example.test';$_SERVER['REQUEST_URI']='/';require '/tmp/wd29-bridge-tests/prestashop/config/config.inc.php';
    archiveCheck(_DB_NAME_==='wd29ps'&&Configuration::get('PS_SHOP_DOMAIN')==='ps.example.test','Disposable PrestaShop required');
    Context::getContext()->shop=new Shop(1);Context::getContext()->language=new Language((int)Configuration::get('PS_LANG_DEFAULT'));Context::getContext()->currency=new Currency((int)Configuration::get('PS_CURRENCY_DEFAULT'));
    require_once '/tmp/wd29-bridge-tests/prestashop/app/AppKernel.php';$kernel=new AppKernel('prod',false);$kernel->boot();$e=Module::getInstanceByName('wd29woobridge')->bridge();$e->install();$e->catalogApplying=true;$p=null;
    try {
        $p=new Product();foreach(Language::getLanguages(false) as $language){$p->name[(int)$language['id_lang']]='Native archive fixture';$p->link_rewrite[(int)$language['id_lang']]='native-archive-fixture';}
        $p->price=17;$p->active=true;$p->available_for_order=true;$p->show_price=true;$p->id_category_default=2;$p->id_tax_rules_group=0;archiveCheck($p->add(),'PS fixture create failed');$id=(int)$p->id;StockAvailable::setQuantity($id,0,9,1,false);
        $image=new Image();$image->id_product=$id;$image->position=1;$image->cover=true;archiveCheck($image->add(),'PS fixture image failed');$file=$image->getPathForCreation().'.jpg';archiveCheck(copy('/tmp/wd29-bridge-tests/prestashop/img/logo.jpg',$file),'PS fixture image file failed');$hash=hash_file('sha256',$file);
        archiveCheck($e->adapter->productExists($id),'PS source not found');$e->adapter->archiveProduct($id);$after=new Product($id);
        archiveCheck(!$after->active&&!$after->available_for_order,'PS mirror still available');archiveCheck((int)StockAvailable::getQuantityAvailableByProduct($id,0,1)===9&&(float)$after->price===17.0,'PS archive changed stock or price');
        archiveCheck((new Image((int)$image->id))->id_product==$id&&is_file($file)&&hash_file('sha256',$file)===$hash,'PS archive changed media');
        $p->delete();$p=null;archiveCheck(!$e->adapter->productExists($id),'Permanently deleted PS product still exists');
        echo "PASS native PrestaShop archive: inactive/non-orderable, unchanged stock/price/media; permanent deletion detected\n";
    } finally {if($p&&$p->id){$p->delete();}$e->catalogApplying=false;}
}else{throw new RuntimeException('Use woo or ps');}
