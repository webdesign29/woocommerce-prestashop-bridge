<?php
namespace WD29\Bridge\Shopify;

/** Creation uses Shopify unique custom IDs, never SKU/email matching or blind create retries. */
trait Exports
{
    private function definition(string $owner): void
    {
        $value=$this->api->query('query InkluraDefinition($owner:MetafieldOwnerType!) { metafieldDefinitions(first:2,ownerType:$owner,namespace:"$app:inklura_sync",key:"source_id") { nodes { id type { name } } } }',['owner'=>$owner]);
        $nodes=$value['metafieldDefinitions']['nodes'] ?? [];
        if ($nodes) { if (count($nodes)!==1 || $nodes[0]['type']['name']!=='id') { throw new Failure('custom_id_definition_needs_review'); } return; }
        $this->api->mutation('metafieldDefinitionCreate','mutation InkluraDefinitionCreate($definition:MetafieldDefinitionInput!) { metafieldDefinitionCreate(definition:$definition) { createdDefinition { id } userErrors { code field message } } }',
            ['definition'=>['name'=>'Inklura source identity','namespace'=>'$app:inklura_sync','key'=>'source_id','type'=>'id','ownerType'=>$owner]]);
    }
    private function customId(string $kind,int $id): array
    {
        $uuid=(string)($this->config['source_uuid'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/D',$uuid)) { throw new Failure('source_identity_not_configured'); }
        return ['namespace'=>'$app:inklura_sync','key'=>'source_id','value'=>$uuid.':'.$kind.':'.$id];
    }
    private function export(string $kind,int $id,array $job): void
    {
        if ($kind==='customer' && (empty($this->config['customers']) || ($this->config['customer_direction'] ?? '')!=='both')) { throw new Failure('customer_export_not_enabled'); }
        if ($kind==='product' && ($this->config['catalog_direction'] ?? '')!=='both') { throw new Failure('product_export_not_enabled'); }
        if ($known=$this->repo->local($kind,$id)) { $this->repo->enqueue($kind,$known['remote_id']); return; }
        $data=$kind==='product'?$this->native->exportProduct($id):$this->native->customer($id);
        if($kind==='product'&&!empty($this->config['export_media'])){
            $data['native_images']=$this->native->exportImages($id,(string)($this->config['source_uuid']??''));
            $data['files']=array_column($data['native_images'],'file');
        }
        if (($this->config['mode'] ?? '')!=='live') { return; }
        $intent=!empty($job['intent'])?json_decode($job['intent'],true,64,JSON_THROW_ON_ERROR):[];
        $hash=Model::hash($data);
        if (isset($intent['export_hash']) && $intent['export_hash']!==$hash) { throw new Failure('native_export_changed_needs_review'); }
        $this->repo->intent($job,['export_hash'=>$hash]);
        $this->definition(strtoupper($kind)); $identifier=['customId'=>$this->customId($kind,$id)];
        if ($kind==='customer') {
            $result=$this->api->query('query InkluraCustomerLookup($identifier:CustomerIdentifierInput!) { customerByIdentifier(identifier:$identifier) { '.Model::CUSTOMER_FIELDS.' } }',['identifier'=>$identifier]);
            $row=$result['customerByIdentifier'] ?? null;
            if (!$row) {
                $created=$this->api->mutation('customerSet','mutation InkluraCustomerCreate($identifier:CustomerSetIdentifiers!,$input:CustomerSetInput!) { customerSet(identifier:$identifier,input:$input) { customer { '.Model::CUSTOMER_FIELDS.' } userErrors { code field message } } }',
                    ['identifier'=>$identifier,'input'=>['firstName'=>$data['first_name'],'lastName'=>$data['last_name'],'email'=>$data['email'],'phone'=>$data['phone']!==''?$data['phone']:null]]);
                $row=$created['customer'] ?? null;
            }
            if (!$row || Model::customer($row)['email']!==$data['email'] || Model::customer($row)['first_name']!==$data['first_name'] || Model::customer($row)['last_name']!==$data['last_name']) { throw new Failure('created_customer_needs_review'); }
            if (($this->config['customer_address_direction']??'import')==='both' && trim($data['billing']['address_1'])!=='') { $row=$this->pushAddress($row,$data['billing']); }
            $remote=Client::gid('Customer',$row['id']); $this->native->markCustomer($id,$remote); $this->repo->bind('customer',$remote,$id); $this->repo->hashes('customer',$remote,$data,Model::customer($row));
        } else {
            $result=$this->api->query('query InkluraProductLookup($identifier:ProductIdentifierInput!,$location:ID!) { productByIdentifier(identifier:$identifier) { '.Model::PRODUCT_FIELDS.' } }',['identifier'=>$identifier,'location'=>$this->config['location']]);
            $row=$result['productByIdentifier'] ?? null;
            if (!$row) {
                $input=$data; unset($input['native_variants'],$input['native_images']);
                foreach ($input['variants'] as $index=>&$variant) {
                    $variant['inventoryQuantities']=$data['native_variants'][$index]['quantity']===null?[]:[['locationId'=>$this->config['location'],'name'=>'available','quantity'=>0]];
                } unset($variant);
                $created=$this->api->mutation('productSet','mutation InkluraProductCreate($identifier:ProductSetIdentifiers!,$input:ProductSetInput!,$location:ID!) { productSet(identifier:$identifier,input:$input,synchronous:true) { product { '.Model::PRODUCT_FIELDS.' } userErrors { code field message } } }',['identifier'=>$identifier,'input'=>$input,'location'=>$this->config['location']]);
                $row=$created['product'] ?? null;
            }
            if (!$row) { throw new Failure('created_product_missing'); }
            $projection=Model::product($row);
            if (count($projection['variants'])!==count($data['variants']) || $projection['title']!==$data['title'] || $projection['description']!==$data['descriptionHtml']) { throw new Failure('created_product_needs_review'); }
            // Shopify can reorder variants. Bind exact option tuples, never position, SKU or price.
            $matches=[];$used=[];
            foreach ($projection['variants'] as $index=>$variant) {
                $found=null;
                foreach ($data['native_variants'] as $n=>$native) { if (Model::hash($native['attributes'])===Model::hash($variant['attributes'])) { if($found!==null)throw new Failure('ambiguous_variant_options');$found=$n; } }
                if ($found===null || isset($used[$found])) { throw new Failure('created_product_needs_review'); } $used[$found]=true;
                $wanted=$data['variants'][$found];$source=$row['variants']['nodes'][$index];
                if ($variant['sku']!==$wanted['inventoryItem']['sku'] || $variant['price']!==$wanted['price'] || $variant['compare_at']!==($wanted['compareAtPrice']??'') || $variant['barcode']!==$wanted['barcode'] || (bool)$source['taxable']!==$wanted['taxable'] || (bool)$source['inventoryItem']['tracked']!==$wanted['inventoryItem']['tracked'] || (bool)$source['inventoryItem']['requiresShipping']!==$wanted['inventoryItem']['requiresShipping']) { throw new Failure('created_product_needs_review'); }
                $matches[]=['remote'=>Client::gid('ProductVariant',$variant['id']),'item'=>Client::gid('InventoryItem',$source['inventoryItem']['id']),'native'=>$data['native_variants'][$found]];
            }
            $remote=Client::gid('Product',$row['id']);
            if(!empty($data['native_images']))$this->createdProductImages($remote,$data['native_images']);
            $this->repo->sql('START TRANSACTION');
            try {
                $this->repo->bind('product',$remote,$id);
                // New exported drafts start at zero; the normal journalled stock operation transfers the count.
                foreach ($matches as $match) {
                    $native=$match['native'];$this->native->markVariantOptions($native['id'],$native['attributes']);
                    $this->repo->bind('variant',$match['remote'],$native['id'],$remote,$match['item']);
                    $this->repo->sql("UPDATE {s}map SET quantity=? WHERE kind='variant' AND remote_id=?",[$native['quantity']===null?null:0,$match['remote']]);
                }
                usort($projection['variants'],static function($a,$b){return strcmp($a['id'],$b['id']);});
                $this->repo->hashes('product',$remote,$this->native->product($id,$this->repo),$projection);$this->repo->sql('COMMIT');
            } catch (\Throwable $error) { $this->repo->sql('ROLLBACK'); throw $error; }
            $this->repo->enqueue('product',$remote);
        }
    }
    private function createdProductImages(string $remote,array $images):void
    {
        $r=$this->api->query('query InkluraCreatedImages($id:ID!){product(id:$id){media(first:20){nodes{__typename ... on MediaImage{id fileStatus image{id url}}}pageInfo{hasNextPage}} images(first:20){nodes{id url}pageInfo{hasNextPage}}}}',['id'=>$remote]);
        $media=$r['product']['media']??null;
        if(!$media||!empty($media['pageInfo']['hasNextPage'])||count($media['nodes'])!==count($images))throw new Failure('created_media_needs_review');
        $byName=[];
        foreach($media['nodes']as$item){
            if(($item['__typename']??'')!=='MediaImage'||($item['fileStatus']??'')==='FAILED')throw new Failure('created_media_needs_review');
            if(($item['fileStatus']??'')!=='READY'||empty($item['image']['url']))throw new Failure('created_media_processing',60);
            $url=Model::imageUrl($item['image']['url']);$name=rawurldecode(basename(parse_url($url,PHP_URL_PATH)));
            if(isset($byName[$name]))throw new Failure('created_media_identity_needs_review');$byName[$name]=$item['image'];
        }
        $productImages=$r['product']['images']??null;
        if(!$productImages||!empty($productImages['pageInfo']['hasNextPage']))throw new Failure('created_media_needs_review');
        if(count($productImages['nodes'])<count($images))throw new Failure('created_media_processing',60);
        if(count($productImages['nodes'])!==count($images))throw new Failure('created_media_needs_review');
        $byUrl=[];
        foreach($productImages['nodes']as$image){$url=Model::imageUrl($image['url']);Client::gid('ProductImage',$image['id']);if(isset($byUrl[$url]))throw new Failure('created_media_identity_needs_review');$byUrl[$url]=$image;}
        $references=[];
        foreach($images as$image){
            $source=$byName[$image['file']['filename']]??null;if(!$source)throw new Failure('created_media_identity_needs_review');
            $productImage=$byUrl[$source['url']]??null;if(!$productImage)throw new Failure('created_media_identity_needs_review');
            $references[]=['id'=>$image['attachment'],'key'=>hash('sha256',$productImage['id'].':'.$productImage['url'])];
        }
        // Reuse native attachments when the subsequent image import sees these Shopify copies.
        foreach($references as$ref){
            if(!in_array($ref['key'],get_post_meta($ref['id'],'_inklura_shopify_image',false),true))add_post_meta($ref['id'],'_inklura_shopify_image',$ref['key'],false);
            if(!in_array($ref['key'],get_post_meta($ref['id'],'_inklura_shopify_image',false),true))throw new Failure('native_media_reference_not_saved');
        }
    }
}
