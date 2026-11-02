<?php
/** In-memory Shopify API emulator. Only native Woo/MySQL is real in the native suite. */
final class ShopifyFixture
{
    public $orderDetails=[];
    public $promotions=[],$loseCreatePromotionReply=false;
    public $products=[],$customers=[],$orders=[],$requests=[],$inventoryKeys=[],$loseInventoryReply=false;
    public $shop='one.myshopify.com',$location='gid://shopify/Location/1';
    public $addressCreates=0,$loseAddressReply=false;
    public $customProducts=[],$customCustomers=[],$definitions=[],$loseCreateProductReply=false,$loseCreateCustomerReply=false;
    public function product(string $id,string $variant,string $item,string $sku,int $quantity=10):array
    {
        return ['id'=>$id,'title'=>'Shopify fixture '.$sku,'descriptionHtml'=>'<p>Native fixture</p>','status'=>'ACTIVE','tags'=>[],'images'=>['nodes'=>[],'pageInfo'=>['hasNextPage'=>false]],'collections'=>['nodes'=>[],'pageInfo'=>['hasNextPage'=>false]],'options'=>[['name'=>'Title','values'=>['Default Title']]],
            'variants'=>['nodes'=>[['id'=>$variant,'title'=>'Default Title','sku'=>$sku,'barcode'=>'','price'=>'12.5','compareAtPrice'=>null,'taxable'=>false,'selectedOptions'=>[['name'=>'Title','value'=>'Default Title']],
                'inventoryItem'=>['id'=>$item,'tracked'=>true,'requiresShipping'=>true,'inventoryLevel'=>['quantities'=>[['name'=>'available','quantity'=>$quantity]]]]]],'pageInfo'=>['hasNextPage'=>false]]];
    }
    public function promotion(string $id,array $input):array
    {
        $old=$this->promotions[$id]['codeDiscount']??[];$d=['__typename'=>'DiscountCodeBasic','updatedAt'=>'fixture-stable','title'=>$input['title'],'status'=>'ACTIVE','startsAt'=>$input['startsAt'],'endsAt'=>$input['endsAt'],'usageLimit'=>$input['usageLimit'],'appliesOncePerCustomer'=>$input['appliesOncePerCustomer'],'combinesWith'=>$input['combinesWith'],'context'=>['__typename'=>'DiscountBuyerSelectionAll'],'codes'=>['nodes'=>[['code'=>$input['code']]],'pageInfo'=>['hasNextPage'=>false]],'minimumRequirement'=>null,'tags'=>$input['tags']??($old['tags']??[])];
        $gets=$input['customerGets'];$value=$gets['value'];
        if(isset($value['percentage'])){$value=['__typename'=>'DiscountPercentage','percentage'=>$value['percentage']];}else{$v=$value['discountAmount'];$value=['__typename'=>'DiscountAmount','amount'=>['amount'=>$v['amount'],'currencyCode'=>'EUR'],'appliesOnEachItem'=>$v['appliesOnEachItem']];}
        $d['customerGets']=['appliesOnOneTimePurchase'=>$gets['appliesOnOneTimePurchase']??true,'appliesOnSubscription'=>$gets['appliesOnSubscription']??false,'items'=>['__typename'=>'AllDiscountItems'],'value'=>$value];
        if(strtotime($d['startsAt'])>time())$d['status']='SCHEDULED';elseif($d['endsAt']!==null&&strtotime($d['endsAt'])<=time())$d['status']='EXPIRED';
        return ['id'=>$id,'codeDiscount'=>$d];
    }
    public function transport(string $url,string $token,string $body):array
    {
        $input=json_decode($body,true);$query=$input['query'];$v=$input['variables'];$this->requests[]=$input;
        if(strpos($query,'InkluraPromotionPage')!==false){$data=['codeDiscountNodes'=>['nodes'=>array_values($this->promotions),'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null]]];}
        elseif(strpos($query,'InkluraPromotionLookup')!==false){$found=null;foreach($this->promotions as$p){if(strtolower($p['codeDiscount']['codes']['nodes'][0]['code'])===strtolower($v['code']))$found=$p;}$data=['codeDiscountNodeByCode'=>$found];}
        elseif((strpos($query,'InkluraPromotionCreate')!==false || strpos($query,'InkluraPromotionUpdate')!==false) && (array_key_exists('appliesOnOneTimePurchase',$v['input']['customerGets']??[]) || array_key_exists('appliesOnSubscription',$v['input']['customerGets']??[]))){$field=strpos($query,'InkluraPromotionCreate')!==false?'discountCodeBasicCreate':'discountCodeBasicUpdate';$data=[$field=>['codeDiscountNode'=>null,'userErrors'=>[['field'=>['basicCodeDiscount','customerGets'],'message'=>'Subscription fields are not permitted on this shop.']]]];}
        elseif(strpos($query,'InkluraPromotionCreate')!==false){$id='gid://shopify/DiscountCodeNode/'.time().random_int(100000,999999);$row=$this->promotion($id,$v['input']);$this->promotions[$id]=$row;$data=['discountCodeBasicCreate'=>['codeDiscountNode'=>$row,'userErrors'=>[]]];if($this->loseCreatePromotionReply){$this->loseCreatePromotionReply=false;return ['status'=>0,'body'=>''];}}
        elseif(strpos($query,'InkluraPromotionUpdate')!==false){$id=$v['id'];$this->promotions[$id]=$this->promotion($id,$v['input']);$data=['discountCodeBasicUpdate'=>['codeDiscountNode'=>$this->promotions[$id],'userErrors'=>[]]];}
        elseif(strpos($query,'InkluraPromotionHold')!==false){$this->promotions[$v['id']]['codeDiscount']['endsAt']=gmdate('Y-m-d\TH:i:s\Z');$this->promotions[$v['id']]['codeDiscount']['status']='EXPIRED';$data=['discountCodeDeactivate'=>['codeDiscountNode'=>['id'=>$v['id']],'userErrors'=>[]]];}
        elseif(strpos($query,'InkluraPromotion(')!==false){$data=['codeDiscountNode'=>$this->promotions[$v['id']]??null];}
        elseif (strpos($query,'InkluraDefinitionCreate')!==false) { $this->definitions[$v['definition']['ownerType']]=true;$data=['metafieldDefinitionCreate'=>['createdDefinition'=>['id'=>'fixture-definition'],'userErrors'=>[]]]; }
        elseif (strpos($query,'InkluraDefinition(')!==false) { $data=['metafieldDefinitions'=>['nodes'=>isset($this->definitions[$v['owner']])?[['id'=>'fixture-definition','type'=>['name'=>'id']]]:[]]]; }
        elseif (strpos($query,'InkluraProductLookup')!==false) { $key=$v['identifier']['customId']['value'];$data=['productByIdentifier'=>isset($this->customProducts[$key])?$this->products[$this->customProducts[$key]]:null]; }
        elseif (strpos($query,'InkluraProductCreate')!==false) {
            $key=$v['identifier']['customId']['value'];$p=$v['input'];$seed=(string)(time()*100000+random_int(10000,99999));$id='gid://shopify/Product/'.$seed.'1';$vid='gid://shopify/ProductVariant/'.$seed.'2';$iid='gid://shopify/InventoryItem/'.$seed.'3';
            $row=$this->product($id,$vid,$iid,'',0);$row['title']=$p['title'];$row['descriptionHtml']=$p['descriptionHtml'];$row['status']=$p['status'];$row['options']=array_map(static function($o){return ['name'=>$o['name'],'values'=>array_column($o['values'],'name')];},$p['productOptions']);$row['variants']['nodes']=[];
            foreach($p['variants'] as $n=>$inputVariant){
                $variant=$this->product($id,$vid.(string)$n,$iid.(string)$n,$inputVariant['inventoryItem']['sku'],0)['variants']['nodes'][0];
                foreach(['taxable','price','compareAtPrice','barcode'] as $field){$variant[$field]=$inputVariant[$field];}
                $variant['selectedOptions']=array_map(static function($o){return ['name'=>$o['optionName'],'value'=>$o['name']];},$inputVariant['optionValues']);$variant['inventoryItem']['tracked']=$inputVariant['inventoryItem']['tracked'];$variant['inventoryItem']['requiresShipping']=$inputVariant['inventoryItem']['requiresShipping'];
                $row['variants']['nodes'][]=$variant;
            }
            $row['sourceIdentity']=['value'=>$key];$row['variants']['nodes']=array_reverse($row['variants']['nodes']);
            $row['media']=['nodes'=>[],'pageInfo'=>['hasNextPage'=>false]];
            foreach($p['files']??[]as$n=>$file){$image=['id'=>'gid://shopify/ProductImage/'.$seed.$n,'url'=>'https://cdn.shopify.com/s/files/1/fixture/'.$file['filename'],'altText'=>$file['alt']];$row['images']['nodes'][]=$image;$row['media']['nodes'][]=['__typename'=>'MediaImage','id'=>'gid://shopify/MediaImage/'.$seed.$n,'fileStatus'=>'READY','image'=>['id'=>'gid://shopify/ImageSource/'.$seed.$n,'url'=>$image['url']]];}
            $this->products[$id]=$row;$this->customProducts[$key]=$id;$data=['productSet'=>['product'=>$row,'userErrors'=>[]]];
            if($this->loseCreateProductReply){$this->loseCreateProductReply=false;return ['status'=>0,'body'=>''];}
        } elseif (strpos($query,'InkluraCreatedImages')!==false) { $data=['product'=>$this->products[$v['id']]??null];
        } elseif (strpos($query,'InkluraCustomerLookup')!==false) { $key=$v['identifier']['customId']['value'];$data=['customerByIdentifier'=>isset($this->customCustomers[$key])?$this->customers[$this->customCustomers[$key]]:null]; }
        elseif (strpos($query,'InkluraCustomerCreate')!==false) {
            $p=$v['input'];$key=$v['identifier']['customId']['value'];$id='gid://shopify/Customer/'.time().random_int(100000,999999);$row=['id'=>$id,'firstName'=>$p['firstName'],'lastName'=>$p['lastName'],'defaultEmailAddress'=>['emailAddress'=>$p['email']],'defaultPhoneNumber'=>['phoneNumber'=>$p['phone']],'defaultAddress'=>null];$row['sourceIdentity']=['value'=>$key];$this->customers[$id]=$row;$this->customCustomers[$key]=$id;$data=['customerSet'=>['customer'=>$row,'userErrors'=>[]]];if($this->loseCreateCustomerReply){$this->loseCreateCustomerReply=false;return ['status'=>0,'body'=>''];}
        } elseif (strpos($query,'InkluraConnection')!==false) { $data=['shop'=>['myshopifyDomain'=>$this->shop,'currencyCode'=>'EUR','taxesIncluded'=>false],'currentAppInstallation'=>['accessScopes'=>array_map(static function($s){return ['handle'=>$s];},['write_products','write_inventory','read_locations','write_customers','read_orders','write_discounts'])],'locations'=>['nodes'=>[['id'=>$this->location,'name'=>'Fixture','isActive'=>true]],'pageInfo'=>['hasNextPage'=>false]]]; }
        elseif (strpos($query,'InkluraPage')!==false) {
            $key=strpos($query,'products(')!==false?'products':(strpos($query,'customers(')!==false?'customers':'orders');$rows=$this->$key;
            $data=[$key=>['nodes'=>array_map(static function($r){return ['id'=>$r['id'],'updatedAt'=>$r['updatedAt']??'fixture-stable'];},array_values($rows)),'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null]]];
        } elseif (strpos($query,'InkluraProductUpdate')!==false) { if(strpos($query,'userErrors { code')!==false)throw new RuntimeException('UserError has no code field');$p=$v['product'];$this->products[$p['id']]['title']=$p['title'];$this->products[$p['id']]['descriptionHtml']=$p['descriptionHtml'];$data=['productUpdate'=>['product'=>['id'=>$p['id']],'userErrors'=>[]]]; }
        elseif ((strpos($query,'InkluraVariantUpdate')!==false || strpos($query,'InkluraVariantTax')!==false || strpos($query,'InkluraVariantShipping')!==false)) {
            foreach($v['variants'] as $change){foreach($this->products[$v['id']]['variants']['nodes'] as &$row){if($row['id']!==$change['id'])continue;foreach(['price','compareAtPrice','barcode','taxable'] as $field){if(array_key_exists($field,$change))$row[$field]=$change[$field];}if(isset($change['inventoryItem']['sku']))$row['sku']=$change['inventoryItem']['sku'];if(isset($change['inventoryItem']['requiresShipping']))$row['inventoryItem']['requiresShipping']=$change['inventoryItem']['requiresShipping'];}unset($row);}
            $data=['productVariantsBulkUpdate'=>['productVariants'=>[],'userErrors'=>[]]];
        } elseif (strpos($query,'InkluraCatalogueExtras')!==false) { $data=['product'=>$this->products[$v['id']]??null];
        } elseif (strpos($query,'InkluraTagsUpdate')!==false) { $this->products[$v['product']['id']]['tags']=$v['product']['tags'];$data=['productUpdate'=>['product'=>$this->products[$v['product']['id']],'userErrors'=>[]]];
        } elseif (strpos($query,'InkluraProduct(')!==false) { $data=['product'=>$this->products[$v['id']] ?? null]; }
        elseif (strpos($query,'InkluraCustomerUpdate')!==false) {
            $p=$v['input'];$row=&$this->customers[$p['id']];$row['firstName']=$p['firstName'];$row['lastName']=$p['lastName'];$row['defaultEmailAddress']=['emailAddress'=>$p['email']];$row['defaultPhoneNumber']=['phoneNumber'=>$p['phone']];$data=['customerUpdate'=>['customer'=>$row,'userErrors'=>[]]];
        } elseif (strpos($query,'InkluraAddressCreate')!==false || strpos($query,'InkluraAddressUpdate')!==false) {
            if(strpos($query,'userErrors { code')!==false || strpos($query,'MailingAddressInput!')===false)throw new RuntimeException('Address API contract mismatch');
            $create=strpos($query,'InkluraAddressCreate')!==false;$row=&$this->customers[$v['customerId']];
            if($create){$this->addressCreates++;$addressId='gid://shopify/MailingAddress/'.time().random_int(10000,99999);}
            else {$addressId=$v['addressId'];if(($row['defaultAddress']['id']??'')!==$addressId)throw new RuntimeException('Wrong address identity');}
            $address=$v['address'];$address['id']=$addressId;$address['countryCodeV2']=$address['countryCode'];unset($address['countryCode']);$row['defaultAddress']=$address;
            $data=[$create?'customerAddressCreate':'customerAddressUpdate'=>['address'=>['id'=>$addressId],'userErrors'=>[]]];
            if($this->loseAddressReply){$this->loseAddressReply=false;return ['status'=>0,'body'=>''];}
        } elseif (strpos($query,'InkluraCustomer(')!==false) { $data=['customer'=>$this->customers[$v['id']] ?? null]; }
        elseif (strpos($query,'InkluraOrderDetails(')!==false) { $data=['order'=>$this->orderDetails[$v['id']]??['id'=>$v['id'],'fulfillments'=>[],'refunds'=>[],'transactions'=>[]]]; }
        elseif (strpos($query,'InkluraOrder(')!==false) { $data=['order'=>$this->orders[$v['id']] ?? null]; }
        elseif (strpos($query,'InkluraInventory(')!==false) {
            $key=$v['key'];$change=$v['input']['quantities'][0];if(!array_key_exists('changeFromQuantity',$change) || array_key_exists('compareQuantity',$change))throw new RuntimeException('2026-10 stock input contract mismatch');
            if(isset($this->inventoryKeys[$key])){$data=$this->inventoryKeys[$key];}
            else {
                $errors=[];$found=false;
                foreach($this->products as &$product){foreach($product['variants']['nodes'] as &$variant){if($variant['inventoryItem']['id']!==$change['inventoryItemId'])continue;$found=true;$q=&$variant['inventoryItem']['inventoryLevel']['quantities'][0]['quantity'];if($q!==$change['changeFromQuantity']){$errors[]=['code'=>'CHANGE_FROM_QUANTITY_STALE','field'=>['input'],'message'=>'Quantity changed'];}else{$q=$change['quantity'];}}unset($variant);}unset($product);
                if(!$found)throw new RuntimeException('Fixture inventory item missing');$data=['inventorySetQuantities'=>['inventoryAdjustmentGroup'=>['referenceDocumentUri'=>$v['input']['referenceDocumentUri']],'userErrors'=>$errors]];$this->inventoryKeys[$key]=$data;
                if($this->loseInventoryReply){$this->loseInventoryReply=false;return ['status'=>0,'body'=>''];}
            }
        } else { throw new RuntimeException('Unexpected fixture query'); }
        return ['status'=>200,'body'=>json_encode(['data'=>$data],JSON_THROW_ON_ERROR)];
    }
}
