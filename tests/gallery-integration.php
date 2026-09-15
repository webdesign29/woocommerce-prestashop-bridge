<?php
$root=getenv('WD29_WP_ROOT'); if (!$root) { throw new RuntimeException('Disposable WP root required.'); } require $root.'/wp-load.php';
if (DB_NAME!=='wd29woo' || parse_url(home_url(),PHP_URL_HOST)!=='woo.example.test') { throw new RuntimeException('Disposable WP database required.'); }
require_once __DIR__.'/../includes/Gallery.php';
use WD29\Bridge\Gallery;
function galleryCheck($ok,$message) { if (!$ok) { throw new RuntimeException($message); } }
$p=new WC_Product_Simple(); $p->set_name('Gallery fixture'); $p->save(); $p=wc_get_product($p->get_id()); $ids=[];
for ($i=0;$i<3;$i++) { $ids[]=wp_insert_attachment(['post_title'=>'Gallery fixture '.$i,'post_mime_type'=>'image/png','post_status'=>'inherit'],false,$p->get_id()); }
update_post_meta($ids[0],'_wd29_bridge_source',hash('sha256','fixture-old')); update_post_meta($ids[1],'_wd29_bridge_source',hash('sha256','fixture-new'));
try {
 $p->set_image_id($ids[0]); $p->set_gallery_image_ids([$ids[2]]); $p->save(); $p=wc_get_product($p->get_id());
 Gallery::apply($p,[$ids[1]],false); $p->save(); $p=wc_get_product($p->get_id()); galleryCheck(in_array($ids[0],$p->get_gallery_image_ids()),'Default mode detached old image');
 Gallery::apply($p,[$ids[1]],true); $p->save(); $p=wc_get_product($p->get_id());
 galleryCheck((int)$p->get_image_id()===$ids[1] && array_map('intval',$p->get_gallery_image_ids())===[$ids[2]],'Owned-only removal lost manual image');
 galleryCheck(get_post_type($ids[0])==='attachment' && in_array($ids[0],$p->get_meta('_wd29_gallery_detached')),'Attachment was deleted or recovery absent');
 Gallery::apply($p,[$ids[1]],true); $p->save(); $p=wc_get_product($p->get_id()); galleryCheck(count($p->get_meta('_wd29_gallery_detached'))===1,'Replay duplicated recovery record');
 Gallery::restore($p); $p->save(); $p=wc_get_product($p->get_id()); galleryCheck(in_array($ids[0],array_merge([$p->get_image_id()],$p->get_gallery_image_ids())),'Restore failed');
 Gallery::apply($p,[],true); $p->save(); $p=wc_get_product($p->get_id()); galleryCheck((int)$p->get_image_id()===$ids[2] && count($p->get_gallery_image_ids())===0,'Empty source removed manual image or kept mapped images');
 $adapter=new \WD29\Bridge\WooAdapter();
 $adapter->engine=new class($p->get_id()) {
  public $pid; public $captured=[];
  public function __construct($pid) { $this->pid=$pid; }
  public function mapping($key) { return $key==='woo:product:999'?['kind'=>'product','local_id'=>$this->pid]:null; }
  public function capture($kind,$id) { $this->captured[]=[$kind,$id]; }
 };
 $adapter->restoreGallery('woo:product:999'); $p=wc_get_product($p->get_id());
 galleryCheck(count($adapter->engine->captured)===1 && count(array_merge([$p->get_image_id()],$p->get_gallery_image_ids()))===3,'Restore wrapper did not save/capture');
 $refused=false; try { $adapter->restoreGallery('woo:order:999'); } catch (RuntimeException $error) { $refused=true; }
 galleryCheck($refused,'Restore accepted unmapped/order identity');
 echo "PASS: default additive gallery, owned-only detach, empty source, replay, attachment retention and restore\n";
} finally { $p->delete(true); foreach ($ids as $id) { wp_delete_attachment($id,true); } }
