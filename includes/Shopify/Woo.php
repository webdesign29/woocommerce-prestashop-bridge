<?php
namespace WD29\Bridge\Shopify;

/** Native WooCommerce operations. Shopify order mirrors never charge, mail or move stock. */
final class Woo
{
    use Orders;
    use Coupons;
    public function product(int $id, Repository $repo): array
    {
        $p = wc_get_product($id);
        if (!$p || !in_array($p->get_type(), ['simple','variable'], true)) { throw new Failure('unsupported_native_product'); }
        $variants = [];
        $maps = $repo->sql("SELECT * FROM {s}map WHERE kind='variant' AND parent_id=? ORDER BY remote_id", [$repo->local('product',$id)['remote_id'] ?? '']);
        $expected = $p->is_type('variable') ? array_map('intval',$p->get_children()) : [$id]; $actual = [];
        foreach ($maps as $map) {
            $v = wc_get_product((int)$map['local_id']);
            if (!$v || ($v->is_type('variation') && $v->get_parent_id() !== $id)) { throw new Failure('native_variant_identity_changed'); }
            $actual[] = (int)$v->get_id();
            $attrs = [];
            if ($v->is_type('variation')) {
                $stored = (array)$v->get_meta('_inklura_shopify_options');
                $attrs = $this->variationOptions($p,$v);
                if ($attrs !== $stored) { throw new Failure('variant_options_need_review'); }
            } else { $attrs = ['Title'=>'Default Title']; }
            $regular = (string)$v->get_regular_price('edit'); $sale = (string)$v->get_sale_price('edit');
            if ($v->get_date_on_sale_from() || $v->get_date_on_sale_to()) { throw new Failure('scheduled_price_needs_review'); }
            $variants[] = ['id'=>$map['remote_id'],'sku'=>(string)$v->get_sku('edit'),'barcode'=>(string)$v->get_global_unique_id('edit'),
                'price'=>Model::money($sale !== '' ? $sale : $regular),'compare_at'=>$sale !== '' ? Model::money($regular) : '', 'attributes'=>$attrs];
        }
        sort($expected); sort($actual);
        if ($actual !== $expected) { throw new Failure('variant_structure_needs_review'); }
        usort($variants, static function ($a,$b) { return strcmp($a['id'],$b['id']); });
        return ['title'=>$p->get_name('edit'),'description'=>$p->get_description('edit'),'status'=>$p->get_status('edit'),'variants'=>$variants];
    }
    public function applyProduct(array $row, Repository $repo, array $config): int
    {
        $data = Model::product($row); $map = $repo->map('product',$row['id']);
        $simple = count($data['variants']) === 1 && $data['variants'][0]['attributes'] === ['Title'=>'Default Title'];
        $id = $map ? (int)$map['local_id'] : $this->recover('product',$row['id']);
        $p = $id ? wc_get_product($id) : ($simple ? new \WC_Product_Simple() : new \WC_Product_Variable());
        if (!$p || $p->get_type() !== ($simple ? 'simple' : 'variable')) { throw new Failure('product_type_needs_review'); }
        // Never remove variants, including a default variant that used to be simple.
        $old = $map ? $repo->sql("SELECT remote_id FROM {s}map WHERE kind='variant' AND parent_id=?", [$row['id']]) : [];
        foreach ($old as $variant) { if (!in_array($variant['remote_id'],array_column($data['variants'],'id'),true)) { throw new Failure('variant_removal_needs_review'); } }
        $p->set_name($data['title']); $p->set_description(wp_kses_post($data['description']));
        if (!$id) { $p->set_status(!empty($config['publish_imports']) && $data['status']==='ACTIVE' ? 'publish' : 'draft'); $p->set_tax_status(!empty($row['variants']['nodes'][0]['taxable'])?'taxable':'none'); }
        elseif ($data['status'] !== 'ACTIVE') { $p->set_status('draft'); }
        $p->update_meta_data('_inklura_shopify_gid',$row['id']);
        if (!$simple) {
            $attrs = [];
            foreach ($row['options'] as $pos=>$option) {
                $attr = new \WC_Product_Attribute(); $attr->set_name($option['name']); $attr->set_options($option['values']); $attr->set_position($pos); $attr->set_visible(true); $attr->set_variation(true); $attrs[] = $attr;
            }
            $p->set_attributes($attrs);
        }
        $p->save(); $repo->bind('product',$row['id'],$p->get_id());
        foreach ($data['variants'] as $index=>$dataVariant) {
            $source = $row['variants']['nodes'][$index]; $vm = $repo->map('variant',$dataVariant['id']);
            $vid = $vm ? (int)$vm['local_id'] : $this->recover($simple ? 'product' : 'product_variation',$dataVariant['id']);
            $v = $simple ? $p : ($vid ? wc_get_product($vid) : new \WC_Product_Variation());
            if (!$v || (!$simple && $vid && $v->get_parent_id() !== $p->get_id())) { throw new Failure('native_variant_identity_changed'); }
            if (!$simple) {
                $v->set_parent_id($p->get_id()); $v->set_status('publish'); $attrs = [];
                foreach ($dataVariant['attributes'] as $name=>$value) { $attrs[sanitize_title($name)] = $value; }
                $v->set_attributes($attrs); $v->update_meta_data('_inklura_shopify_options',$dataVariant['attributes']);
            }
            $v->set_sku($dataVariant['sku']); $v->set_global_unique_id($dataVariant['barcode']);
            if (!$vm) { $v->set_tax_status(!empty($source['taxable'])?'taxable':'none');$v->set_virtual(!($source['inventoryItem']['requiresShipping']??true)); }
            $v->set_regular_price($dataVariant['compare_at'] !== '' ? $dataVariant['compare_at'] : $dataVariant['price']);
            $v->set_sale_price($dataVariant['compare_at'] !== '' ? $dataVariant['price'] : '');
            $v->set_price($dataVariant['price']); $v->set_date_on_sale_from(null); $v->set_date_on_sale_to(null);
            if (!$simple) { $v->update_meta_data('_inklura_shopify_gid',$dataVariant['id']); }
            if (!$vm) {
                $quantity = Model::quantity($source); $v->set_manage_stock($quantity !== null);
                if ($quantity !== null) { $v->set_stock_quantity($quantity); }
            }
            $v->save();
            $repo->bind('variant',$dataVariant['id'],$v->get_id(),$row['id'],Client::gid('InventoryItem',$source['inventoryItem']['id']));
            if (!$vm) { $repo->sql("UPDATE {s}map SET quantity=? WHERE kind='variant' AND remote_id=?", [Model::quantity($source),$dataVariant['id']]); }
        }
        if (!$simple) { \WC_Product_Variable::sync($p->get_id()); }
        return $p->get_id();
    }
    private function recover(string $type, string $remote): int
    {
        $ids = get_posts(['post_type'=>$type,'post_status'=>'any','fields'=>'ids','posts_per_page'=>2,'meta_key'=>'_inklura_shopify_gid','meta_value'=>$remote]);
        if (count($ids) > 1) { throw new Failure('native_identity_ambiguous'); }
        return (int)($ids[0] ?? 0);
    }
    public function quantity(int $id): ?int
    {
        $p = wc_get_product($id);
        if (!$p) { throw new Failure('native_product_missing'); }
        if ($p->get_manage_stock('edit') !== true) { return null; }
        if ($p->get_stock_managed_by_id() !== $id) { throw new Failure('shared_stock_owner_needs_review'); }
        global $wpdb;
        return \WD29\Bridge\Protocol::quantity($wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_stock'",$id)));
    }
    /** Caller owns transaction; lock the row used by Woo's native stock SQL. */
    public function stock(int $id, int $before, int $target): void
    {
        global $wpdb;
        $wpdb->get_results($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_stock' FOR UPDATE",$id));
        if ($this->quantity($id) !== $before) { throw new Failure('native_inventory_compare_conflict'); }
        $result = wc_update_product_stock($id,$target,'set');
        if (is_wp_error($result) || $this->quantity($id) !== $target) { throw new Failure('native_inventory_update_failed'); }
    }
    public function customer(int $id): array
    {
        $user = get_userdata($id);
        if (!$user || array_values((array)$user->roles) !== ['customer']) { throw new Failure('native_customer_identity_changed'); }
        $c = new \WC_Customer($id); $billing = [];
        foreach (array_keys(Model::address(null)) as $field) { $getter = 'get_billing_' . $field; $billing[$field] = (string)$c->$getter('edit'); }
        return ['first_name'=>$c->get_first_name('edit'),'last_name'=>$c->get_last_name('edit'),'email'=>$c->get_email('edit'),'phone'=>$c->get_billing_phone('edit'),'billing'=>$billing];
    }
    public function applyCustomer(array $row, Repository $repo): int
    {
        $data = Model::customer($row); $map = $repo->map('customer',$row['id']);
        $id = $map ? (int)$map['local_id'] : 0;
        if (!$id) {
            $ids = get_users(['meta_key'=>'_inklura_shopify_gid','meta_value'=>$row['id'],'fields'=>'ID','number'=>2]);
            if (count($ids)>1) { throw new Failure('native_identity_ambiguous'); } $id = (int)($ids[0] ?? 0);
        }
        if (!is_email($data['email'])) { throw new Failure('customer_email_unavailable'); }
        $collision = email_exists($data['email']);
        if ($collision && (int)$collision !== $id) { throw new Failure('customer_email_identity_conflict'); }
        if ($id) { $this->customer($id); if (get_user_meta($id,'_inklura_shopify_gid',true) !== $row['id']) { throw new Failure('native_customer_identity_changed'); } }
        $c = $id ? new \WC_Customer($id) : new \WC_Customer();
        if (!$id) { $c->set_username('shopify_' . substr(hash('sha256',$row['id']),0,24)); $c->set_password(wp_generate_password(48,true,true)); $c->set_role('customer'); }
        $c->set_email($data['email']); $c->set_first_name($data['first_name']); $c->set_last_name($data['last_name']);
        if ($row['defaultAddress']!==null) { foreach ($data['billing'] as $field=>$value) { $setter='set_billing_'.$field; $c->$setter($value); } }
        $c->set_billing_email($data['email']); $c->set_billing_phone($data['phone']); $c->update_meta_data('_inklura_shopify_gid',$row['id']); $c->save();
        $repo->bind('customer',$row['id'],$c->get_id()); return $c->get_id();
    }
    public function markCustomer(int $id,string $remote):void
    {
        $this->customer($id);$known=(string)get_user_meta($id,'_inklura_shopify_gid',true);
        if ($known!=='' && $known!==$remote) { throw new Failure('native_customer_identity_changed'); }
        update_user_meta($id,'_inklura_shopify_gid',$remote);
    }
    public function requiresShipping(int $id):bool{$p=wc_get_product($id);if(!$p)throw new Failure('native_product_missing');return !$p->is_virtual();}
    public function setRequiresShipping(int $id,bool $required):void{$p=wc_get_product($id);if(!$p)throw new Failure('native_product_missing');$p->set_virtual(!$required);$p->save();}
    public function taxable(int $id):bool { $p=wc_get_product($id);if(!$p)throw new Failure('native_product_missing');return $p->get_tax_status('edit')==='taxable'; }
    public function setTaxable(int $id,bool $taxable):void { $p=wc_get_product($id);if(!$p)throw new Failure('native_product_missing');if($p->is_type('variation')){$p=wc_get_product($p->get_parent_id());if(!$p)throw new Failure('native_product_missing');}$p->set_tax_status($taxable?'taxable':'none');$p->save(); }
    public function exportProduct(int $id):array
    {
        $p=wc_get_product($id);
        if (!$p || !in_array($p->get_type(),['simple','variable'],true)) { throw new Failure('unsupported_native_product'); }
        $children=$p->is_type('variable')?$p->get_children():[$id];
        if (!$children || count($children)>100) { throw new Failure('product_variant_count_needs_review'); }
        $this->assertFinancialProducts($children);
        $variants=[];$native=[];$options=[];$seen=[];
        foreach ($children as $child) {
            $v=wc_get_product($child);
            if (!$v || $v->get_date_on_sale_from() || $v->get_date_on_sale_to()) { throw new Failure('scheduled_price_needs_review'); }
            if ($v->is_downloadable()) { throw new Failure('digital_product_export_needs_review'); }
            if ($p->is_type('variable') && (!$v->is_type('variation') || $v->get_parent_id()!==$id || $v->get_status('edit')!=='publish')) { throw new Failure('inactive_variant_needs_review'); }
            $attrs=$p->is_type('variable')?$this->variationOptions($p,$v):['Title'=>'Default Title'];
            $key=Model::hash($attrs);
            if (isset($seen[$key])) { throw new Failure('ambiguous_variant_options'); } $seen[$key]=true;
            $values=[];
            foreach ($attrs as $name=>$value) { $options[$name][$value]=true; $values[]=['optionName'=>(string)$name,'name'=>$value]; }
            $regular=Model::money((string)$v->get_regular_price('edit'));$sale=(string)$v->get_sale_price('edit');$quantity=$this->quantity((int)$child);
            $variants[]=['optionValues'=>$values,'price'=>$sale!==''?Model::money($sale):$regular,'compareAtPrice'=>$sale!==''?$regular:null,
                'barcode'=>(string)$v->get_global_unique_id('edit'),'taxable'=>$v->get_tax_status('edit')==='taxable','inventoryItem'=>['sku'=>(string)$v->get_sku('edit'),'tracked'=>$quantity!==null,'requiresShipping'=>!$v->is_virtual()]];
            $native[]=['id'=>(int)$child,'quantity'=>$quantity,'attributes'=>$attrs];
        }
        $productOptions=[];
        foreach ($options as $name=>$values) { $productOptions[]=['name'=>(string)$name,'values'=>array_map(static function($name){return ['name'=>(string)$name];},array_keys($values))]; }
        return ['title'=>$p->get_name('edit'),'descriptionHtml'=>$p->get_description('edit'),'status'=>'DRAFT','native_variants'=>$native,'productOptions'=>$productOptions,'variants'=>$variants];
    }
    private function assertFinancialProducts(array $ids):void
    {
        if(!class_exists('Inklura\\CommercePro\\WooPlugin'))return;
        $commerce=new \Inklura\CommercePro\WooPlugin();if(empty($commerce->config()['gift_cards']['enabled']))return;
        $keys=[];foreach($ids as$id){$v=wc_get_product($id);if(!$v)throw new Failure('native_product_missing');$keys[]=\wd29_bridge()->identity($v->is_type('variation')?'variant':'product',(int)$id);}
        try{$reply=$commerce->financeRequest('product_features',['products'=>$keys]);}
        catch(\Throwable $error){throw new Failure('product_authority_unavailable',60);}
        foreach($keys as$key){$flag=$reply['products'][$key]['gift_card']??null;if(!is_bool($flag))throw new Failure('product_authority_unavailable',60);if($flag)throw new Failure('gift_card_product_needs_authority_review');}
    }
    /** Resolve taxonomy slugs to the same displayed option values used by Shopify. */
    private function variationOptions($parent,$variation):array
    {
        $out=[];$values=$variation->get_attributes('edit');
        foreach ($parent->get_attributes('edit') as $attribute) {
            if (!$attribute->get_variation()) { continue; }
            $key=sanitize_title($attribute->get_name());$value=(string)($values[$key] ?? '');
            if ($value==='') { throw new Failure('wildcard_variant_options_need_review'); }
            $name=$attribute->is_taxonomy()?wc_attribute_label($attribute->get_name(),$parent):$attribute->get_name();
            if ($attribute->is_taxonomy()) { $term=get_term_by('slug',$value,$attribute->get_name());if(!$term || is_wp_error($term))throw new Failure('variant_term_missing');$value=$term->name; }
            if (isset($out[$name])) { throw new Failure('ambiguous_variant_options'); } $out[$name]=$value;
        }
        if (!$out || count($out)>3) { throw new Failure('unsupported_product_options'); } return $out;
    }
    public function markVariantOptions(int $id,array $attributes):void
    {
        $v=wc_get_product($id);if(!$v)throw new Failure('native_product_missing');
        if($v->is_type('variation')){$v->update_meta_data('_inklura_shopify_options',$attributes);$v->save();}
    }
    public function tags(int $id):array
    {
        $terms=wp_get_post_terms($id,'product_tag',['fields'=>'names']);if(is_wp_error($terms))throw new Failure('native_product_tags_unavailable');return Model::tags($terms);
    }
    public function setTags(int $id,array $tags):void
    {
        $ids=[];
        foreach($tags as $name){$term=term_exists($name,'product_tag');if(!$term)$term=wp_insert_term($name,'product_tag');if(is_wp_error($term))throw new Failure('product_tag_translation_needs_review');$native=get_term((int)$term['term_id'],'product_tag');if(!$native||$native->name!==$name)throw new Failure('product_tag_translation_needs_review');$ids[]=(int)$term['term_id'];}
        if(is_wp_error(wp_set_object_terms($id,$ids,'product_tag',false)))throw new Failure('native_product_tags_update_failed');
    }
    public function importCollections(int $id,array $rows,string $shop):void
    {
        if(!$rows)return;
        $rootSlug='inklura-shopify-'.substr(hash('sha256',$shop),0,16);$root=get_term_by('slug',$rootSlug,'product_cat');
        if(!$root){$created=wp_insert_term('Shopify collections','product_cat',['slug'=>$rootSlug]);if(is_wp_error($created))throw new Failure('collection_root_needs_review');$root=get_term((int)$created['term_id'],'product_cat');update_term_meta($root->term_id,'_inklura_shopify_root',$shop);}
        if(get_term_meta($root->term_id,'_inklura_shopify_root',true)!==$shop)throw new Failure('collection_root_needs_review');
        $ids=[];
        foreach($rows as $row){
            $gid=Client::gid('Collection',$row['id']);$key=hash('sha256',$shop.':'.$gid);$slug='shopify-'.substr($key,0,24);$term=get_term_by('slug',$slug,'product_cat');
            if(!$term){$created=wp_insert_term($row['title'],'product_cat',['slug'=>$slug,'parent'=>(int)$root->term_id]);if(is_wp_error($created))throw new Failure('collection_term_creation_failed');$term=get_term((int)$created['term_id'],'product_cat');update_term_meta($term->term_id,'_inklura_shopify_collection',$key);update_term_meta($term->term_id,'_inklura_shopify_title',$row['title']);}
            if(get_term_meta($term->term_id,'_inklura_shopify_collection',true)!==$key)throw new Failure('collection_identity_needs_review');
            $before=(string)get_term_meta($term->term_id,'_inklura_shopify_title',true);
            if($term->name!==$row['title']){if($term->name!==$before)throw new Failure('collection_title_needs_review');$changed=wp_update_term($term->term_id,'product_cat',['name'=>$row['title']]);if(is_wp_error($changed))throw new Failure('collection_term_update_failed');update_term_meta($term->term_id,'_inklura_shopify_title',$row['title']);}
            $ids[]=(int)$term->term_id;
        }
        // Flat collections add categories without erasing local or historical assignments.
        if($ids && is_wp_error(wp_set_object_terms($id,$ids,'product_cat',true)))throw new Failure('collection_assignment_failed');
    }
    /** Initial creation only: public native uploads, stable names and exact attachment snapshots. */
    public function exportImages(int $id,string $uuid):array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$uuid))throw new Failure('source_identity_not_configured');
        $p=wc_get_product($id);if(!$p)throw new Failure('native_product_missing');
        $ids=array_values(array_unique(array_filter(array_merge([(int)$p->get_image_id()],array_map('intval',$p->get_gallery_image_ids())))));
        if(count($ids)>20)throw new Failure('product_exceeds_20_images');
        if(!$ids)return [];
        $uploads=wp_upload_dir();$base=rtrim((string)$uploads['baseurl'],'/').'/';$directory=realpath($uploads['basedir']);
        $origin=parse_url(home_url());$u=parse_url($base);
        if(($u['scheme']??'')!=='https'||($u['host']??'')!==($origin['host']??'')||isset($u['user'])||isset($u['pass'])||isset($u['query'])||isset($u['fragment'])||isset($u['port']))throw new Failure('native_media_public_url_required');
        $out=[];
        foreach($ids as $attachment){
            $record=get_post($attachment);
            if(!$record||$record->post_type!=='attachment'||$record->post_status!=='inherit')throw new Failure('native_media_identity_needs_review');
            $url=(string)wp_get_attachment_url($attachment);$parts=parse_url($url);$file=realpath((string)get_attached_file($attachment));
            if(strpos($url,$base)!==0||isset($parts['query'])||isset($parts['fragment'])||!$directory||!$file||strpos($file,$directory.DIRECTORY_SEPARATOR)!==0||!is_readable($file))throw new Failure('native_media_public_url_required');
            \WD29\Bridge\Protocol::publicEndpoint($url);
            $size=filesize($file);$image=getimagesize($file);$extension=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$image['mime']??'']??null;
            if(!$extension||$size<1||$size>10*1024*1024||$image[0]*$image[1]>40000000)throw new Failure('native_media_format_needs_review');
            $digest=hash_file('sha256',$file);if(!$digest)throw new Failure('native_media_unreadable');
            $out[]=['attachment'=>$attachment,'sha256'=>$digest,'file'=>['originalSource'=>$url,'contentType'=>'IMAGE','alt'=>sanitize_text_field(get_post_meta($attachment,'_wp_attachment_image_alt',true)),
                'filename'=>'inklura-'.$uuid.'-'.$id.'-'.$attachment.'-'.substr($digest,0,16).'.'.$extension,'duplicateResolutionMode'=>'RAISE_ERROR']];
        }
        return $out;
    }
    public function importImages(int $id,array $rows):void
    {
        if(!$rows)return;
        require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/media.php';require_once ABSPATH.'wp-admin/includes/image.php';
        $images=[];
        foreach($rows as $row){
            $url=Model::imageUrl($row['url']);$key=hash('sha256',$row['id'].':'.$url);
            $existing=get_posts(['post_type'=>'attachment','post_status'=>'inherit','numberposts'=>2,'meta_key'=>'_inklura_shopify_image','meta_value'=>$key,'fields'=>'ids']);
            if(count($existing)>1)throw new Failure('media_identity_needs_review');
            if($existing){$images[]=(int)$existing[0];continue;}
            $uploads=wp_upload_dir();
            if(!empty($uploads['error']) || !is_writable($uploads['path']))throw new Failure('native_media_storage_unavailable',60);
            $bytes=\WD29\Bridge\Protocol::imageBytes($url,'https://cdn.shopify.com');$info=getimagesizefromstring($bytes);$extension=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$info['mime']];$tmp=wp_tempnam('inklura-shopify-image');
            try{if(!$tmp||file_put_contents($tmp,$bytes)===false)throw new Failure('media_staging_failed');$attachment=media_handle_sideload(['name'=>$key.'.'.$extension,'tmp_name'=>$tmp],$id);if(is_wp_error($attachment))throw new Failure('media_import_failed');update_post_meta($attachment,'_inklura_shopify_image',$key);update_post_meta($attachment,'_wp_attachment_image_alt',sanitize_text_field($row['altText']??''));$images[]=(int)$attachment;}
            finally{if($tmp&&is_file($tmp))unlink($tmp);}
        }
        $p=wc_get_product($id);\WD29\Bridge\Gallery::apply($p,$images,false);$p->save();
    }
}
