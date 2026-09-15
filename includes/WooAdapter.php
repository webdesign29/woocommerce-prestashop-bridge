<?php
namespace WD29\Bridge;

final class WooAdapter
{
    public $engine;
    public function restoreGallery(string $key): void
    {
        $lock=substr($this->prefix().'wd29_bridge_worker',0,64);
        if ((int)($this->sql('SELECT GET_LOCK(?,5) AS acquired',[$lock])[0]['acquired']??0)!==1) { throw new \RuntimeException('Gallery is busy; retry.'); }
        try {
            $this->sql('START TRANSACTION'); $map=$this->engine->mapping($key);
            if (!$map || !in_array($map['kind'],['product','variant'],true)) { throw new \RuntimeException('Choose a mapped product or variation key.'); }
            $product=wc_get_product((int)$map['local_id']);
            if (!$product) { throw new \RuntimeException('Mapped product no longer exists.'); }
            Gallery::restore($product); $product->save();
            $pid=$product->is_type('variation')?(int)$product->get_parent_id():(int)$product->get_id();
            $this->sql('COMMIT'); $this->engine->capture('product',$pid);
        } catch (\Throwable $error) { $this->sql('ROLLBACK'); throw $error; }
        finally { $this->sql('SELECT RELEASE_LOCK(?)',[$lock]); }
    }

    public function afterRollback(string $kind,int $id): void
    {
        if (function_exists('acf_get_store')) { acf_get_store('values')->reset(); }
        if ($id>0 && in_array($kind,['product','stock','order'],true)) {
            clean_post_cache($id);
            if ($kind==='order') { wc_delete_shop_order_transients($id); }
            else { wc_delete_product_transients($id); }
        }
    }
    public function site(): string { return 'woo'; }
    /** A trashed product still exists and is not a permanent deletion. */
    public function productExists(int $id): bool { return get_post_type($id)==='product'; }
    public function archiveProduct(int $id): void
    {
        $product=wc_get_product($id);
        if (!$product) { throw new \RuntimeException('Mirror product no longer exists.'); }
        if ($product->get_status()!=='trash') { $product->set_status('draft'); $product->save(); }
    }

    public function prefix(): string { global $wpdb; return $wpdb->prefix; }
    public function config(): array { return (array) get_option('wd29_bridge_config', ['mode' => 'disabled']); }
    public function workerStatus(?string $state=null): array
    {
        if ($state!==null) { update_option('wd29_bridge_worker',['state'=>$state,'at'=>gmdate('c')],false); }
        return (array)get_option('wd29_bridge_worker',[]);
    }
    public function notice(string $text): void { update_option('wd29_bridge_notice', sanitize_text_field($text), false); }
    public function scanOffset(string $kind): int { $value=(array)get_option('wd29_bridge_scan',[]); return (int)($value[$kind]??0); }
    public function saveScanOffset(string $kind,int $offset): void { $value=(array)get_option('wd29_bridge_scan',[]); $value[$kind]=$offset; update_option('wd29_bridge_scan',$value,false); }
    public function sql(string $query, array $args = [])
    {
        global $wpdb;
        if ($args) {
            $parts = explode('?', $query);
            if (count($parts) !== count($args) + 1) { throw new \LogicException('SQL parameter mismatch.'); }
            $query = array_shift($parts);
            foreach ($args as $i => $value) {
                $query .= ($value === null ? 'NULL' : $wpdb->prepare('%s', $value)) . $parts[$i];
            }
        }
        $result = preg_match('/^\s*SELECT/i', $query) ? $wpdb->get_results($query, ARRAY_A) : $wpdb->query($query);
        if ($wpdb->last_error) { throw new \RuntimeException('Bridge database operation failed.'); }
        return $result;
    }

    public function ids(string $kind, int $offset, int $limit): array
    {
        if ($kind === 'customer') {
            $ids=get_users(['role'=>'customer','meta_query'=>[['key'=>'_wd29_bridge_customer_origin','compare'=>'NOT EXISTS']],'fields'=>'ID','number'=>$limit,'offset'=>$offset,'orderby'=>'ID','order'=>'ASC']);
            return array_map(function($id){return $this->engine->contactId(Protocol::key('woo','customer',(int)$id));},$ids);
        }
        if ($kind === 'order') {
            return wc_get_orders(['return' => 'ids', 'limit' => $limit, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC', 'type' => 'shop_order']);
        }
        global $wpdb;
        return array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type='product' AND (p.post_status IN ('publish','private','draft','pending') OR (p.post_status='trash' AND EXISTS (SELECT 1 FROM {$wpdb->prefix}woocommerce_order_itemmeta m WHERE m.meta_key='_product_id' AND m.meta_value=CAST(p.ID AS CHAR)))) ORDER BY p.ID LIMIT %d OFFSET %d",$limit,$offset)));
    }

    private function prices($product): array
    {
        $rates = $product->is_taxable() ? \WC_Tax::get_base_tax_rates($product->get_tax_class()) : [];
        $rate = 0.0;
        foreach ($rates as $row) {
            if (!empty($row['compound']) && $row['compound'] === 'yes') { throw new \RuntimeException('Compound taxes need an explicit mapping.'); }
            $rate += (float) $row['rate'];
        }
        $convert = function ($price) use ($product) {
            return $price === '' ? null : number_format(wc_get_price_excluding_tax($product, ['price' => (float) $price]), 6, '.', '');
        };
        return ['regular' => $convert($product->get_regular_price()), 'sale' => $convert($product->get_sale_price()),
            'tax_rate' => wc_tax_enabled() ? $rate : null, 'basis' => wc_tax_enabled() ? 'net' : 'display'];
    }

    private function inventory($product, string $key): array
    {
        // Parent-managed variations must not each duplicate the shared parent inventory.
        $managed = $product->get_manage_stock('edit') === true;
        return ['key' => $key, 'quantity' => $managed ? Protocol::quantity($product->get_stock_quantity()) : null,
            'status' => $product->get_stock_status(), 'backorders' => $product->get_backorders() !== 'no'];
    }

    private function fieldResolvers(int $parentId): array
    {
        return [
            'peerHost'=>(string)parse_url($this->config()['peer']??'',PHP_URL_HOST),
            'imageId'=>function(string $url) use ($parentId) { return $this->importImage($url,$parentId); },
            'productKey'=>function(int $id) {
                $rows=$this->engine->sql("SELECT record_key FROM {b}map WHERE kind='product' AND local_id=?",[$id]);
                if (!$rows) { throw new \RuntimeException('ACF referenced product must be synchronized first.'); }
                return $rows[0]['record_key'];
            },
            'productId'=>function(string $key) {
                $map=$this->engine->mapping($key);
                if (!$map || $map['kind']!=='product') { throw new \RuntimeException('ACF referenced product is not mapped yet.'); }
                return (int)$map['local_id'];
            },
        ];
    }
    private function extraFields($p): array
    {
        $ids=(array)$p->get_meta('_wd29_identifiers');
        $gtin=method_exists($p,'get_global_unique_id')?$p->get_global_unique_id():($ids['gtin']??'');
        $ids['gtin']=$gtin;
        if (preg_match('/^[0-9]{13}$/D',$gtin) || preg_match('/^[0-9]{8}$/D',$gtin)) { $ids['ean13']=$gtin; }
        elseif (preg_match('/^[0-9]{12}$/D',$gtin)) { $ids['upc']=$gtin; }
        $dimensions=[];
        foreach (['length','width','height'] as $field) { $value=$p->{'get_'.$field}(); $dimensions[$field]=$value===''?null:(string)wc_get_dimension((float)$value,'cm'); }
        $extra=['identifiers'=>$ids,'dimensions_cm'=>$dimensions,'suppliers'=>Suppliers::export($p),'custom_fields'=>CustomFields::export($p,null,$this->fieldResolvers($p->get_id()))];
        if (metadata_exists('post',$p->get_id(),'_wd29_purchase_net')) { $extra['purchase_price_net']=(string)$p->get_meta('_wd29_purchase_net'); }
        if (metadata_exists('post',$p->get_id(),'_wd29_supplier_name')) { $extra['supplier']=['name'=>(string)$p->get_meta('_wd29_supplier_name'),'reference'=>(string)$p->get_meta('_wd29_supplier_reference')]; }
        if (!$p->is_type('variation') && (metadata_exists('post',$p->get_id(),'_yoast_wpseo_title') || metadata_exists('post',$p->get_id(),'_yoast_wpseo_metadesc'))) {
            $title=(string)get_post_meta($p->get_id(),'_yoast_wpseo_title',true); $description=(string)get_post_meta($p->get_id(),'_yoast_wpseo_metadesc',true);
            if (function_exists('wpseo_replace_vars')) { $title=wpseo_replace_vars($title,get_post($p->get_id())); $description=wpseo_replace_vars($description,get_post($p->get_id())); }
            if (strpos($title.$description,'%%')===false) { $extra['seo']=['title'=>$title,'description'=>$description]; }
        }
        return $extra;
    }
    private function applyExtraFields($p,array $row): void
    {

        if (isset($row['purchase_price_net'])) {
            if (!is_numeric($row['purchase_price_net']) || (float)$row['purchase_price_net']<0) { throw new \RuntimeException('Invalid net purchasing cost.'); }
            $p->update_meta_data('_wd29_purchase_net',(string)$row['purchase_price_net']);
        }
        if (isset($row['supplier'])) { $p->update_meta_data('_wd29_supplier_name',sanitize_text_field($row['supplier']['name']??'')); $p->update_meta_data('_wd29_supplier_reference',sanitize_text_field($row['supplier']['reference']??'')); }
        if (isset($row['seo']) && !$p->is_type('variation')) {
            foreach (['title'=>'_yoast_wpseo_title','description'=>'_yoast_wpseo_metadesc'] as $field=>$meta) {
                // Preserve local dynamic templates on their originating store.
                if (strpos((string)$p->get_meta($meta),'%%')!==false && strpos($row['key']??'','woo:')===0) { continue; }
                $p->update_meta_data($meta,sanitize_text_field($row['seo'][$field]??''));
            }
        }
        if (isset($row['identifiers'])) {
            $ids=array_intersect_key($row['identifiers'],array_flip(['gtin','ean13','upc','isbn','mpn']));
            foreach ($ids as $v) { if (!is_string($v)||strlen($v)>64) { throw new \RuntimeException('Invalid product identifier.'); } }
            $p->update_meta_data('_wd29_identifiers',$ids);
            $gtin=($ids['gtin']??'')?: (($ids['ean13']??'')?:($ids['upc']??''));
            if (method_exists($p,'set_global_unique_id')) { $p->set_global_unique_id($gtin); }
        }
        if (isset($row['dimensions_cm'])) {
            foreach (['length','width','height'] as $field) {
                if (!array_key_exists($field,$row['dimensions_cm'])) { continue; }
                $value=$row['dimensions_cm'][$field];
                if ($value!==null && (!is_numeric($value)||(float)$value<0)) { throw new \RuntimeException('Invalid product dimension.'); }
                $p->{'set_'.$field}($value===null?'':wc_get_dimension((float)$value,get_option('woocommerce_dimension_unit','cm'),'cm'));
            }
        }
    }
    public function product(int $id): array
    {
        $p = wc_get_product($id);
        if (!$p) { throw new \RuntimeException('Product no longer exists.'); }
        if ($p->is_type('variation')) { $p = wc_get_product($p->get_parent_id()); }
        if (!$p || !in_array($p->get_type(), ['simple', 'variable'], true) || $p->is_downloadable()) {
            throw new \RuntimeException('Only simple and variable, non-downloadable products are supported.');
        }
        $key = $this->engine->identity('product', $p->get_id());
        $data = ['key' => $key, 'type' => $p->get_type(), 'name' => $p->get_name(), 'sku' => $p->get_sku(),
            'description' => $p->get_description(), 'short_description' => $p->get_short_description(),
            'status' => $p->get_status() === 'publish' ? 'publish' : 'draft', 'archived'=>$p->get_status()==='trash', 'virtual' => $p->is_virtual(),
            'currency' => get_woocommerce_currency(), 'prices' => $this->prices($p),
            'weight_kg' => (string) wc_get_weight((float) $p->get_weight(), 'kg'),
            'categories' => [], 'images' => [], 'attributes' => [], 'variants' => [], 'inventory' => [$this->inventory($p, $key)]];
        $data += $this->extraFields($p);
        foreach ($p->get_category_ids() as $termId) {
            $path = [];
            $ids = array_reverse(get_ancestors($termId, 'product_cat'));
            $ids[] = $termId;
            foreach ($ids as $tid) { $term = get_term($tid, 'product_cat'); if ($term && !is_wp_error($term)) { $path[] = $term->name; } }
            if ($path) { $data['categories'][] = $path; }
        }
        foreach (array_unique(array_filter(array_merge([$p->get_image_id()], $p->get_gallery_image_ids()))) as $imageId) {
            $url = wp_get_attachment_url($imageId);
            if ($url) { $data['images'][] = $url; }
        }
        foreach ($p->get_attributes() as $attribute) {
            $options = $attribute->is_taxonomy() ? wc_get_product_terms($p->get_id(), $attribute->get_name(), ['fields' => 'names']) : $attribute->get_options();
            $data['attributes'][] = ['name' => wc_attribute_label($attribute->get_name()), 'options' => array_values($options), 'variation' => $attribute->get_variation()];
        }
        foreach ($p->is_type('variable') ? $p->get_children() : [] as $vid) {
            $v = wc_get_product($vid);
            if (!$v) { continue; }
            $vkey = $this->engine->identity('variant', $vid);
            $attributes = [];
            foreach ($v->get_attributes() as $name => $value) {
                if ($value === '') { throw new \RuntimeException('Wildcard variations must be expanded before synchronization.'); }
                $term = taxonomy_exists($name) ? get_term_by('slug', $value, $name) : false;
                $attributes[wc_attribute_label($name)] = $term ? $term->name : $value;
            }
            $data['variants'][] = ['key' => $vkey, 'sku' => $v->get_sku(), 'attributes' => $attributes,
                'prices' => $this->prices($v), 'weight_kg' => (string) wc_get_weight((float) $v->get_weight(), 'kg'),
                'status' => $v->get_status() === 'publish' ? 'publish' : 'draft'] + $this->extraFields($v);
            $variantImages=array_values(array_unique(array_filter(array_merge([$v->get_image_id('edit')],(array)$v->get_meta('_wd29_variant_image_ids')))));
            $data['variants'][count($data['variants'])-1]['images']=array_values(array_filter(array_map('wp_get_attachment_url',$variantImages)));
            $data['inventory'][] = $this->inventory($v, $vkey);
        }
        $data['brands'] = taxonomy_exists('product_brand') ? wc_get_product_terms($p->get_id(), 'product_brand', ['fields'=>'names']) : [];
        $data['tags'] = wc_get_product_terms($p->get_id(), 'product_tag', ['fields'=>'names']);
        $data['brands'] = array_map('html_entity_decode', $data['brands']); $data['tags'] = array_map('html_entity_decode', $data['tags']);
        sort($data['brands']); sort($data['tags']);
        return $data;
    }

    private function applyPrices($p, array $prices): void
    {
        if ($prices['basis'] === 'display') {
            if (wc_tax_enabled()) { throw new \RuntimeException('Display-only source price needs a confirmed tax mapping.'); }
            $factor = 1.0;
            $p->set_tax_status('none');
        } else {
            $rate = (float) $prices['tax_rate'];
            if ($rate > 0 && !wc_tax_enabled()) { throw new \RuntimeException('Destination taxes are disabled; confirm a tax mapping before importing taxed prices.'); }
            $factor = wc_prices_include_tax() ? 1 + $rate / 100 : 1;
            $p->set_tax_status($rate > 0 ? 'taxable' : 'none');
            if ($rate > 0) {
                $found = false;
                foreach (array_merge([''], array_map('sanitize_title', \WC_Tax::get_tax_classes())) as $class) {
                    $rates = \WC_Tax::get_base_tax_rates($class);
                    if (count($rates) === 1 && abs((float) reset($rates)['rate'] - $rate) < 0.00001) {
                        $p->set_tax_class($class); $found = true; break;
                    }
                }
                if (!$found) { throw new \RuntimeException('No matching WooCommerce tax class.'); }
            }
        }
        $p->set_regular_price($prices['regular'] === null ? '' : wc_format_decimal((float) $prices['regular'] * $factor, 6));
        $p->set_sale_price($prices['sale'] === null ? '' : wc_format_decimal((float) $prices['sale'] * $factor, 6));
        $p->set_price($p->get_sale_price() !== '' ? $p->get_sale_price() : $p->get_regular_price());
    }

    private function initializeStock($p, string $key, array $inventories): void
    {
        foreach ($inventories as $item) {
            if ($item['key'] !== $key) { continue; }
            $qty = Protocol::quantity($item['quantity']);
            $p->set_manage_stock($qty !== null);
            if ($qty !== null) { $p->set_stock_quantity($qty); }
            $p->set_stock_status(in_array($item['status'], ['instock', 'outofstock', 'onbackorder'], true) ? $item['status'] : 'outofstock');
            $p->set_backorders(!empty($item['backorders']) ? 'yes' : 'no');
            $p->save();
            $this->engine->sql('UPDATE {b}map SET quantity=?,stock_initialized=1 WHERE record_key=?', [$qty, $key]);
            return;
        }
    }

    public function applyProduct(array $data, ?array $map): int
    {
        if ($data['currency'] !== get_woocommerce_currency()) { throw new \RuntimeException('Currency mismatch.'); }
        if (!in_array($data['type'], ['simple', 'variable'], true)) { throw new \RuntimeException('Unsupported product type.'); }
        $p = $map ? wc_get_product((int) $map['local_id']) : ($data['type'] === 'variable' ? new \WC_Product_Variable() : new \WC_Product_Simple());
        if ($p && $p->is_type('simple') && $data['type'] === 'variable') { $p = new \WC_Product_Variable($p->get_id()); }
        if (!$p || $p->get_type() !== $data['type']) { throw new \RuntimeException('Changing product type needs manual review.'); }
        $p->set_name(wp_strip_all_tags($data['name']));
        $p->set_sku($data['sku']);
        $p->set_description(wp_kses_post($data['description']));
        $p->set_short_description(wp_kses_post($data['short_description']));
        if (!($p->get_status()==='trash' && strpos($data['key'],'woo:')===0)) { $p->set_status(!empty($data['archived'])?'draft':($data['status'] === 'publish' ? 'publish' : 'draft')); }
        $p->set_virtual((bool) $data['virtual']);
        $p->set_weight(wc_get_weight((float) $data['weight_kg'], get_option('woocommerce_weight_unit', 'kg'), 'kg'));
        $this->applyPrices($p, $data['prices']);
        $this->applyExtraFields($p,$data);
        $categories = [];
        foreach ($data['categories'] as $path) {
            $parent = 0;
            foreach ($path as $name) {
                $term = term_exists($name, 'product_cat', $parent);
                if (!$term) { $term = wp_insert_term($name, 'product_cat', ['parent' => $parent]); }
                if (is_wp_error($term)) { throw new \RuntimeException('Category creation failed.'); }
                $parent = (int) (is_array($term) ? $term['term_id'] : $term);
            }
            if ($parent) { $categories[] = $parent; }
        }
        $p->set_category_ids($categories);
        $attributes = [];
        foreach ($data['attributes'] as $row) {
            $attribute = new \WC_Product_Attribute();
            $attribute->set_name($row['name']); $attribute->set_options($row['options']);
            $attribute->set_visible(true); $attribute->set_variation((bool) $row['variation']);
            $attributes[] = $attribute;
        }
        $p->set_attributes($attributes);
        $p->save();
        if (isset($data['suppliers'])) { Suppliers::apply($p,$data['suppliers']); $p->save(); }
        if (isset($data['custom_fields'])) { CustomFields::apply($p,$data['custom_fields'],null,$this->fieldResolvers($p->get_id())); $p->save(); }
        foreach (['brands'=>'product_brand','tags'=>'product_tag'] as $field=>$taxonomy) {
            if (!isset($data[$field])) { continue; }
            if (!taxonomy_exists($taxonomy)) { if ($data[$field]) { throw new \RuntimeException('Required product taxonomy is unavailable.'); } continue; }
            $terms = wp_set_object_terms($p->get_id(), array_values($data[$field]), $taxonomy, false);
            if (is_wp_error($terms)) { throw new \RuntimeException('Product taxonomy mapping failed.'); }
        }
        $this->engine->bind($data['key'], 'product', $p->get_id());
        if (!$map) { $this->initializeStock($p, $data['key'], $data['inventory']); }
        foreach ($data['variants'] as $row) {
            $vm = $this->engine->mapping($row['key']);
            $v = $vm ? wc_get_product((int) $vm['local_id']) : new \WC_Product_Variation();
            if (!$v || ($vm && $v->get_parent_id() !== $p->get_id())) { throw new \RuntimeException('Variation parent mismatch.'); }
            $v->set_parent_id($p->get_id()); $v->set_sku($row['sku']);
            $v->set_status($row['status'] === 'publish' ? 'publish' : 'private');
            $v->set_attributes(array_combine(array_map('sanitize_title', array_keys($row['attributes'])), array_values($row['attributes'])));
            $this->applyPrices($v, $row['prices']);
            $this->applyExtraFields($v,$row);
            $v->set_weight(wc_get_weight((float) $row['weight_kg'], get_option('woocommerce_weight_unit', 'kg'), 'kg'));
            $v->save();
            if (isset($row['suppliers'])) { Suppliers::apply($v,$row['suppliers']); $v->save(); }
            if (isset($row['custom_fields'])) { CustomFields::apply($v,$row['custom_fields'],null,$this->fieldResolvers($p->get_id())); $v->save(); }
            $this->engine->bind($row['key'], 'variant', $v->get_id());
            if (!$vm) { $this->initializeStock($v, $row['key'], $data['inventory']); }
        }
        if ($p->is_type('variable')) { \WC_Product_Variable::sync($p->get_id()); }
        $images=[];
        foreach ($data['images'] as $url) { $images[]=$this->importImage($url,$p->get_id()); }
        if (array_key_exists('images',$data)) { Gallery::apply($p,$images,!empty($this->config()['sync_gallery_removals'])); $p->save(); }
        foreach ($data['variants'] as $row) {
            if (!array_key_exists('images',$row)) { continue; }
            $vm=$this->engine->mapping($row['key']); $v=wc_get_product((int)$vm['local_id']); $ids=[];
            foreach ($row['images'] as $url) { $ids[]=$this->importImage($url,$p->get_id()); }
            Gallery::apply($v,$ids,!empty($this->config()['sync_gallery_removals'])); $v->save();
        }
        return $p->get_id();
    }

    private function importImage(string $url,int $parent): int
    {
        require_once ABSPATH.'wp-admin/includes/file.php'; require_once ABSPATH.'wp-admin/includes/media.php'; require_once ABSPATH.'wp-admin/includes/image.php';
        $existing=get_posts(['post_type'=>'attachment','post_status'=>'inherit','numberposts'=>1,'meta_key'=>'_wd29_bridge_source','meta_value'=>hash('sha256',$url),'fields'=>'ids']);
        if ($existing) { return (int)$existing[0]; }
        $bytes=Protocol::imageBytes($url,$this->config()['peer']); $info=getimagesizefromstring($bytes);
        $extension=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$info['mime']]; $tmp=wp_tempnam('wd29-image');
        try {
            if (!$tmp || file_put_contents($tmp,$bytes)===false) { throw new \RuntimeException('Image staging failed.'); }
            $id=media_handle_sideload(['name'=>hash('sha256',$url).'.'.$extension,'tmp_name'=>$tmp],$parent);
            if (is_wp_error($id)) { throw new \RuntimeException('Image import failed.'); }
            update_post_meta($id,'_wd29_bridge_source',hash('sha256',$url)); return (int)$id;
        } finally { if ($tmp && is_file($tmp)) { unlink($tmp); } }
    }
    public function normalizeUnknownVariantStock(int $offset): array
    {
        $changed=0; $products=$this->ids('product',$offset,10);
        foreach ($products as $id) {
            $p=wc_get_product($id);
            if (!$p || !$p->is_type('variable') || strpos($this->engine->identity('product',$id),'woo:')!==0) { continue; }
            if ($p->get_manage_stock('edit')===true) { throw new \RuntimeException('Parent-managed inventory needs an allocation decision.'); }
            foreach ($p->get_children() as $vid) {
                $v=wc_get_product($vid); if (!$v || $v->get_manage_stock('edit')===true) { continue; }
                $v->set_manage_stock(true); $v->set_stock_quantity(0); $v->set_backorders('no'); $v->set_stock_status('outofstock'); $v->save(); $changed++;
            }
            \WC_Product_Variable::sync($id); $this->engine->capture('product',$id);
        }
        return ['products'=>count($products),'changed_variations'=>$changed,'next_offset'=>$offset+10];
    }

    public function stockDelta(array $map, int $delta): void
    {
        $p = wc_get_product((int) $map['local_id']);
        if (!$p || !$p->managing_stock()) { throw new \RuntimeException('Destination stock is not quantity-managed.'); }
        $result = wc_update_product_stock($p, abs($delta), $delta < 0 ? 'decrease' : 'increase');
        if (is_wp_error($result)) { throw new \RuntimeException('Native stock update failed.'); }
    }

    public function stockQuantity(array $map): ?int
    {
        $p = wc_get_product((int) $map['local_id']);
        if (!$p) { throw new \RuntimeException('Stock product no longer exists.'); }
        if ($p->get_manage_stock('edit') !== true) { return null; }
        global $wpdb;
        return Protocol::quantity($wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_stock' LIMIT 1", (int) $map['local_id'])));
    }
    public function stockSet(array $map, ?int $quantity): void
    {
        $p = wc_get_product((int) $map['local_id']);
        if (!$p) { throw new \RuntimeException('Stock product no longer exists.'); }
        $p->set_manage_stock($quantity !== null);
        if ($quantity !== null) { $p->set_stock_quantity($quantity); }
        $p->save();
    }

    public function contactProfile(string $kind, int $id): array
    {
        $blank=['first_name'=>'','last_name'=>'','email'=>'','phone'=>'','company'=>'','billing'=>[],'shipping'=>[],'guest'=>$kind==='guest','deleted'=>false];
        if ($kind==='guest') {
            $o=wc_get_order($id);
            if (!$o || $o->get_meta('_wd29_bridge_origin')) { $blank['deleted']=true; return $blank; }
            $billing=$o->get_address('billing'); $shipping=$o->get_address('shipping');
        } else {
            $u=get_userdata($id);
            if (!$u || !in_array('customer',$u->roles,true)) { $blank['deleted']=true; return $blank; }
            $blank['custom_fields']=CustomFields::exportCustomer($id);
            $c=new \WC_Customer($id); $billing=$c->get_billing(); $shipping=$c->get_shipping();
            $billing['email']=$c->get_email();
            $billing['first_name']=$billing['first_name']?:$c->get_first_name();
            $billing['last_name']=$billing['last_name']?:$c->get_last_name();
        }
        foreach (['first_name','last_name','email','phone','company'] as $field) { $blank[$field]=(string)($billing[$field]??''); }
        $blank['billing']=$billing; $blank['shipping']=$shipping; $blank['addresses']=[['id'=>'billing','label'=>'Billing']+$billing,['id'=>'shipping','label'=>'Shipping']+$shipping]; return $blank;
    }

    public function applyCustomerAccount(array $data): int { return CustomerAccounts::apply($this->engine,$data); }

    public function orderContact(int $id): ?string
    {
        $o=wc_get_order($id); if (!$o || $o->get_meta('_wd29_bridge_origin')) { return null; }
        $u=$o->get_customer_id()?get_userdata($o->get_customer_id()):false;
        return $u && in_array('customer',$u->roles,true) ? Protocol::key('woo','customer',$u->ID) : Protocol::key('woo','guest',$id);
    }

    public function reconcileOrderLinks(int $id): void
    {
        $o=wc_get_order($id); if (!$o || !$o->get_meta('_wd29_bridge_origin')) { return; }
        $snapshot=$o->get_meta('_wd29_bridge_snapshot'); $index=0;
        foreach ($o->get_items() as $item) {
            $key=$item->get_meta('_wd29_source_product')?:($snapshot['items'][$index]['product']??null); $index++;
            if ($item->get_product_id() || !$key) { continue; }
            $map=$this->engine->mapping($key); $p=$map?wc_get_product((int)$map['local_id']):false;
            if ($p) { $name=$item->get_name(); $item->set_product($p); $item->set_name($name); $item->save(); }
        }
    }

    public function orderSummary(int $id): array
    {
        $o=wc_get_order($id); $missing=0;
        foreach ($o->get_items() as $item) { if (!$item->get_product_id()) { $missing++; } }
        return ['local_id'=>$id,'total'=>$o->get_total(),'currency'=>$o->get_currency(),'status'=>$o->get_status(),'lines'=>count($o->get_items()),'unlinked_lines'=>$missing];
    }

    public function order(int $id): array
    {
        $o = wc_get_order($id);
        if (!$o || $o->get_type() !== 'shop_order') { throw new \RuntimeException('Order not found.'); }
        $snapshot = $o->get_meta('_wd29_bridge_snapshot');
        if (is_array($snapshot) && strpos($snapshot['key'], 'ps:') === 0) {
            if ($o->get_status()!==($o->get_meta('_wd29_bridge_mapped_status')?:$snapshot['status'])) { $snapshot['status'] = $o->get_status(); }
            $snapshot['custom_fields']=CustomFields::export($o,'order');
            return $snapshot;
        }
        $items = [];
        foreach ($o->get_items() as $item) {
            $pid = $item->get_variation_id() ?: $item->get_product_id();
            $items[] = ['line_id'=>(string)$item->get_id(), 'product' => $pid ? $this->engine->identity($item->get_variation_id() ? 'variant' : 'product', $pid) : null,
                'name' => $item->get_name(), 'quantity' => (int) $item->get_quantity(),
                'net' => $item->get_total(), 'tax' => $item->get_total_tax()];
        }
        return ['key' => $this->engine->identity('order', $id), 'number' => (string) $o->get_order_number(),
            'status' => $o->get_status(), 'currency' => $o->get_currency(), 'total' => $o->get_total(),
            'tax' => $o->get_total_tax(), 'shipping_net' => $o->get_shipping_total(), 'shipping_tax' => $o->get_shipping_tax(),
            'discount' => $o->get_discount_total(), 'billing' => $o->get_address('billing'), 'shipping' => $o->get_address('shipping'),
            'refunds'=>Refunds::export($o), 'custom_fields'=>CustomFields::export($o,'order'), 'items' => $items, 'created' => $o->get_date_created() ? $o->get_date_created()->date('c') : null];
    }

    /** Return a conflict-comparison snapshot only after checking hidden native mirror edits. */
    public function orderConflictSnapshot(int $id): array
    {
        $data=$this->order($id); $o=wc_get_order($id);
        if (strpos($data['key'],'ps:order:')!==0) { return $data; }
        $snapshot=$o->get_meta('_wd29_bridge_snapshot');
        if (!is_array($snapshot) || $snapshot['key']!==$data['key'] || !is_array($snapshot['items']??null)) { throw new \RuntimeException('Mirror snapshot missing; explicit order review required.'); }
        $decimals=wc_get_price_decimals();
        if ($o->get_currency()!==$snapshot['currency'] || $o->get_refunds() || $o->get_transaction_id()!=='' || $o->get_payment_method()!=='') { throw new \RuntimeException('Native mirror currency/payment/refund changed; explicit order review required.'); }
        foreach (['total'=>'get_total','tax'=>'get_total_tax','shipping_net'=>'get_shipping_total','shipping_tax'=>'get_shipping_tax','discount'=>'get_discount_total'] as $field=>$getter) { OrderConflicts::assertMoney($o->$getter(),$snapshot[$field]??null,$decimals); }
        OrderConflicts::assertAddress($o->get_address('billing'),$snapshot['billing']);
        $expectedShipping=$snapshot['shipping']; unset($expectedShipping['email']); // Woo shipping addresses have no native email field.
        OrderConflicts::assertAddress($o->get_address('shipping'),$expectedShipping);
        $items=array_values($o->get_items());
        if (count($items)!==count($snapshot['items'])) { throw new \RuntimeException('Native mirror line count changed; explicit order review required.'); }
        foreach ($snapshot['items'] as $index=>$line) {
            $item=$items[$index]; $expectedKey=isset($line['line_id'])?'source:'.$line['line_id']:'legacy:'.$index;
            $storedKey=(string)$item->get_meta('_wd29_source_line');
            if (($storedKey!=='' && $storedKey!==$expectedKey) || $item->get_name()!==$line['name'] || (int)$item->get_quantity()!==(int)$line['quantity']) { throw new \RuntimeException('Native mirror line identity or quantity changed; explicit order review required.'); }
            $actualProduct=(int)($item->get_variation_id()?:$item->get_product_id());
            $productMap=!empty($line['product'])?$this->engine->mapping($line['product']):null;
            if (($line['product']!==null && (!$productMap || $actualProduct!==(int)$productMap['local_id'])) || ($line['product']===null && $actualProduct!==0)) { throw new \RuntimeException('Native mirror product link changed; explicit order review required.'); }
            OrderConflicts::assertMoney($item->get_total(),$line['net'],$decimals);
            OrderConflicts::assertMoney($item->get_total_tax(),$line['tax'],$decimals);
            OrderConflicts::assertMoney($item->get_subtotal(),$line['net'],$decimals);
            OrderConflicts::assertMoney($item->get_subtotal_tax(),$line['tax'],$decimals);
        }
        // Fees are generated only for the source discount; extra native fees cannot be ignored.
        $lineNet=(float)$snapshot['shipping_net']; $lineTax=(float)$snapshot['shipping_tax'];
        foreach ($snapshot['items'] as $line) { $lineNet+=(float)$line['net']; $lineTax+=(float)$line['tax']; }
        $discountNet=$lineNet-((float)$snapshot['total']-(float)$snapshot['tax']);
        $discountTax=$lineTax-(float)$snapshot['tax']; $fees=array_values($o->get_items('fee'));
        $expectedFee=abs($lineNet+$lineTax-(float)$snapshot['total'])>0.02;
        if (count($fees)!==($expectedFee?1:0) || $o->get_items('coupon')) { throw new \RuntimeException('Native mirror fees/coupons changed; explicit order review required.'); }
        if ($expectedFee) { OrderConflicts::assertMoney($fees[0]->get_total(),-$discountNet,$decimals); OrderConflicts::assertMoney($fees[0]->get_total_tax(),-$discountTax,$decimals); }
        $shipping=array_values($o->get_items('shipping'));
        if (count($shipping)!==1) { throw new \RuntimeException('Native mirror shipping lines changed; explicit order review required.'); }
        OrderConflicts::assertMoney($shipping[0]->get_total(),$snapshot['shipping_net'],$decimals);
        OrderConflicts::assertMoney($shipping[0]->get_total_tax(),$snapshot['shipping_tax'],$decimals);
        return $data;
    }

    public static function registerSourceStatuses(): void
    {
        foreach ((array)get_option('wd29_bridge_source_statuses',[]) as $key=>$label) {
            if (!preg_match('/^ps-state-[1-9][0-9]{0,7}$/D',$key)) { continue; }
            register_post_status('wc-'.$key,['label'=>'PrestaShop: '.$label,'public'=>false,'exclude_from_search'=>true,'show_in_admin_all_list'=>true,'show_in_admin_status_list'=>true]);
        }
    }
    public static function statusList(array $statuses): array
    {
        foreach ((array)get_option('wd29_bridge_source_statuses',[]) as $key=>$label) {
            if (preg_match('/^ps-state-[1-9][0-9]{0,7}$/D',$key)) { $statuses['wc-'.$key]='PrestaShop: '.$label; }
        }
        return $statuses;
    }
    public function destinationStatus(array $data): string
    {
        $status=(string)$data['status'];
        if (strpos($data['key'],'ps:')===0 && preg_match('/^ps-state-([1-9][0-9]{0,7})$/D',$status,$match)) {
            if ((int)($data['source_status']['id']??0)!==(int)$match[1]) { throw new \RuntimeException('Invalid source status identity.'); }
            $label=sanitize_text_field($data['source_status']['label']??'');
            if ($label==='' || strlen($label)>200) { throw new \RuntimeException('Invalid source status label.'); }
            $known=(array)get_option('wd29_bridge_source_statuses',[]);
            if (($known[$status]??null)!==$label) { $known[$status]=$label; update_option('wd29_bridge_source_statuses',$known,false); }
            self::registerSourceStatuses();
            $mapping=(array)get_option('wd29_bridge_status_mapping',[]);
            return $mapping[$status]??$status;
        }
        return $status;
    }
    public function applyStatusMappings(): int
    {
        $count=0; $this->engine->orderApplying=true;
        try {
            foreach ($this->engine->sql("SELECT local_id FROM {b}map WHERE kind='order' AND record_key LIKE 'ps:%'") as $row) {
                $o=wc_get_order((int)$row['local_id']); if (!$o) { continue; }
                $snapshot=$o->get_meta('_wd29_bridge_snapshot');
                if (!is_array($snapshot) || strpos($snapshot['status']??'','ps-state-')!==0) { continue; }
                if ($o->get_status()!==($o->get_meta('_wd29_bridge_mapped_status')?:$snapshot['status'])) { continue; }
                $target=$this->destinationStatus($snapshot);
                $o->set_status($target); $o->update_meta_data('_wd29_bridge_mapped_status',$target); $o->save(); $count++;
            }
        } finally { $this->engine->orderApplying=false; }
        return $count;
    }

    public function applyOrder(array $data, ?array $map): int
    {
        if (isset($data['refunds'])) { Refunds::validateOrder($data); }
        $destinationStatus=$this->destinationStatus($data);
        if (!isset(wc_get_order_statuses()['wc-' . $destinationStatus])) { throw new \RuntimeException('Unknown destination order status.'); }
        if ($map && strpos($data['key'], 'woo:') === 0) {
            $current = $this->order((int) $map['local_id']);
            $incoming = $data;
            unset($current['status'], $incoming['status'], $current['custom_fields'], $incoming['custom_fields']);
            foreach (['current','incoming'] as $side) { foreach (${$side}['items'] as &$line) { unset($line['line_id']); } unset($line); }
            if (!array_key_exists('refunds',$incoming)) { unset($current['refunds']); }
            if (Protocol::fingerprint($current) !== Protocol::fingerprint($incoming)) {
                throw new \RuntimeException('Financial order edits must be made on the originating store.');
            }
            $o = wc_get_order((int) $map['local_id']);
            $o->set_status($destinationStatus);
            if (isset($data['custom_fields'])) { CustomFields::apply($o,$data['custom_fields'],'order'); }
            $o->save();
            return $o->get_id();
        }
        $o = $map ? wc_get_order((int) $map['local_id']) : new \WC_Order();
        if (!$o) { throw new \RuntimeException('Mapped order no longer exists.'); }
        if (!$map) {
            // A mirror is a record of a sale, never a second payment or second stock movement.
            $o->set_created_via('wd29_bridge');
            $o->set_customer_id(0);
            $o->update_meta_data('_wd29_bridge_origin', 'ps');
        }
        if (isset($data['refunds'])) { Refunds::apply($o,$data); }
        $o->update_meta_data('_wd29_bridge_snapshot', $data);
        $o->update_meta_data('_wd29_bridge_mapped_status',$destinationStatus);
        $o->set_currency($data['currency']);
        $o->set_address($data['billing'], 'billing'); $o->set_address($data['shipping'], 'shipping');
        $o->set_status($destinationStatus);
        $o->set_payment_method('');
        $o->set_payment_method_title('Recorded on source store');
        if (!$map && !empty($data['created'])) { $o->set_date_created($data['created']); }
        $o->save();
        if (isset($data['custom_fields'])) { CustomFields::apply($o,$data['custom_fields'],'order'); $o->save(); }
        // Keep native line identities stable for external references and refund reconciliation.
        $existingLines=array_values($o->get_items()); $bySource=[];
        foreach ($existingLines as $index=>$item) { $lineKey=$item->get_meta('_wd29_source_line'); $bySource[$lineKey!==''?$lineKey:'legacy:'.$index]=$item; }
        $kept=[];
        foreach ($o->get_items(['shipping', 'tax', 'fee']) as $item) { $o->remove_item($item->get_id()); }
        $sum = (float) $data['shipping_net'] + (float) $data['shipping_tax'];
        foreach ($data['items'] as $index=>$row) {
            $lineKey=isset($row['line_id'])?'source:'.$row['line_id']:'legacy:'.$index;
            if (isset($kept[$lineKey])) { throw new \RuntimeException('Duplicate source order line.'); }
            $item=$bySource[$lineKey]??($bySource['legacy:'.$index]??new \WC_Order_Item_Product());
            $kept[$lineKey]=$item->get_id(); $item->update_meta_data('_wd29_source_line',$lineKey);
            if (!empty($row['product'])) {
                $productMap = $this->engine->mapping($row['product']);
                $p = $productMap ? wc_get_product((int)$productMap['local_id']) : false;
                if ($p) { $item->set_product($p); }
                $item->update_meta_data('_wd29_source_product', $row['product']);
            }
            $item->set_name($row['name']); $item->set_quantity((int) $row['quantity']);
            $item->set_subtotal($row['net']); $item->set_total($row['net']);
            $item->set_taxes(['subtotal' => [0 => $row['tax']], 'total' => [0 => $row['tax']]]);
            $o->add_item($item);
            $sum += (float) $row['net'] + (float) $row['tax'];
        }
        foreach ($existingLines as $old) { if (!in_array($old->get_id(),array_values($kept),true)) { $o->remove_item($old->get_id()); } }
        if (abs($sum - (float) $data['total']) > 0.02) {
            $lineNet = (float)$data['shipping_net']; $lineTax = (float)$data['shipping_tax'];
            foreach ($data['items'] as $row) { $lineNet += (float)$row['net']; $lineTax += (float)$row['tax']; }
            $discountNet = $lineNet - ((float)$data['total'] - (float)$data['tax']);
            $discountTax = $lineTax - (float)$data['tax'];
            // Only a declared source discount can explain this difference; never invent balancing amounts.
            if ($discountNet <= 0 || $discountTax < -0.02 || abs($discountNet - (float)$data['discount']) > 0.02) {
                throw new \RuntimeException('Order total differs from lines plus shipping; fees/discount mapping needs review.');
            }
            $fee = new \WC_Order_Item_Fee(); $fee->set_name('Source order discount');
            $fee->set_amount(-$discountNet); $fee->set_total(-$discountNet);
            $fee->set_tax_status($discountTax > 0 ? 'taxable' : 'none'); $fee->set_taxes(['total'=>[0=>-$discountTax]]);
            $o->add_item($fee);
        }
        $shipping = new \WC_Order_Item_Shipping();
        $shipping->set_method_title('Source shipping'); $shipping->set_total($data['shipping_net']);
        $shipping->set_taxes(['total' => [0 => $data['shipping_tax']]]); $o->add_item($shipping);
        if ((float) $data['tax'] > 0) {
            $tax = new \WC_Order_Item_Tax(); $tax->set_rate_id(0); $tax->set_label('Source tax');
            $tax->set_tax_total((float) $data['tax'] - (float) $data['shipping_tax']);
            $tax->set_shipping_tax_total($data['shipping_tax']); $o->add_item($tax);
        }
        $o->set_shipping_total($data['shipping_net']); $o->set_shipping_tax($data['shipping_tax']);
        $o->set_cart_tax((float) $data['tax'] - (float) $data['shipping_tax']);
        $o->set_discount_total($data['discount']); $o->set_total($data['total']); $o->save();
        return $o->get_id();
    }
}
