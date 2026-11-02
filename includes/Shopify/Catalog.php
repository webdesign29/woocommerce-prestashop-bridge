<?php
namespace WD29\Bridge\Shopify;

/** Optional catalogue enrichments keep separate baselines from the core projection. */
trait Catalog
{
    private function catalogueExtras(string $remote):void
    {
        if (empty($this->config['sync_tags']) && empty($this->config['import_media']) && empty($this->config['import_collections'])) { return; }
        $fields='id';
        if (!empty($this->config['sync_tags'])) { $fields.=' tags'; }
        if (!empty($this->config['import_media'])) { $fields.=' images(first:20) { nodes { id url altText } pageInfo { hasNextPage } }'; }
        if (!empty($this->config['import_collections'])) { $fields.=' collections(first:100) { nodes { id title } pageInfo { hasNextPage } }'; }
        $value=$this->api->query('query InkluraCatalogueExtras($id:ID!) { product(id:$id) { '.$fields.' } }',['id'=>$remote]);
        $row=$value['product']??null;if(!$row)throw new Failure('source_product_missing');
        $map=$this->repo->map('product',$remote);$id=(int)$map['local_id'];
        if (!empty($this->config['sync_tags'])) {
            $local=$this->native->tags($id);$source=Model::tags($row['tags']);$stored=get_post_meta($id,'_inklura_shopify_tags_state',true);$state=is_array($stored)?$stored:[];
            if ($local===$source) { $direction='same'; }
            elseif (!$state) {
                if ($local && $source) { throw new Failure('initial_product_tags_need_review'); }
                $direction=$local?'push':'pull';
            } else { $direction=Model::direction(Model::hash($local),Model::hash($source),$state); }
            if ($direction==='push') {
                if (($this->config['catalog_direction']??'import')!=='both')throw new Failure('native_product_tags_need_review');
                $updated=$this->api->mutation('productUpdate','mutation InkluraTagsUpdate($product:ProductUpdateInput!) { productUpdate(product:$product) { product { id tags } userErrors { field message } } }',['product'=>['id'=>$remote,'tags'=>$local]]);
                $source=Model::tags($updated['product']['tags']??[]);if($source!==$local)throw new Failure('product_tag_translation_needs_review');
            } elseif ($direction==='pull') { $this->native->setTags($id,$source);$local=$this->native->tags($id);if($local!==$source)throw new Failure('product_tag_translation_needs_review'); }
            update_post_meta($id,'_inklura_shopify_tags_state',['local_hash'=>Model::hash($local),'remote_hash'=>Model::hash($source)]);
        }
        if (!empty($this->config['import_media'])) {
            if (!empty($row['images']['pageInfo']['hasNextPage']))throw new Failure('product_exceeds_20_images');
            $this->native->importImages($id,$row['images']['nodes']);
        }
        if (!empty($this->config['import_collections'])) {
            if (!empty($row['collections']['pageInfo']['hasNextPage']))throw new Failure('product_exceeds_100_collections');
            $this->native->importCollections($id,$row['collections']['nodes'],$this->config['shop']);
        }
    }
}
