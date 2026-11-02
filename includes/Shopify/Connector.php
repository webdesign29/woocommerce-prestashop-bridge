<?php
namespace WD29\Bridge\Shopify;

final class Connector
{
    use Exports;
    use Catalog;
    use Promotions;
    public $repo;
    private $api;
    private $native;
    private $config;
    public function __construct(Repository $repo, Client $api, $native, array $config) { $this->repo=$repo; $this->api=$api; $this->native=$native; $this->config=$config; }
    public function check(bool $requireLocation = true): array
    {
        $value = $this->api->query('query InkluraConnection { shop { myshopifyDomain currencyCode taxesIncluded } currentAppInstallation { accessScopes { handle } } locations(first:100) { nodes { id name isActive } pageInfo { hasNextPage } } }');
        if (($value['shop']['myshopifyDomain'] ?? '') !== Client::shop($this->config['shop'] ?? '')) { throw new Failure('shop_identity_mismatch'); }
        $scopes = array_column($value['currentAppInstallation']['accessScopes'] ?? [],'handle');
        $required = ['read_products','read_inventory','read_locations'];
        if (!empty($this->config['customers'])) { $required[]='read_customers'; }
        if (!empty($this->config['orders'])) { $required[]='read_orders'; }
        if (!empty($this->config['coupons'])) { $required[]='read_discounts';if(($this->config['coupon_direction']??'import')==='both')$required[]='write_discounts'; }
        if (!empty($this->config['account_proxy'])) { $required[]='write_app_proxy'; }
        if (($this->config['catalog_direction'] ?? 'import') === 'both') { $required[]='write_products'; }
        if (($this->config['customer_direction'] ?? 'import') === 'both' && !empty($this->config['customers'])) { $required[]='write_customers'; }
        if (($this->config['stock_direction'] ?? 'both') === 'both') { $required[]='write_inventory'; }
        // Shopify write scopes imply their corresponding read scope.
        foreach ($scopes as $scope) { if (strpos($scope,'write_')===0) { $scopes[]= 'read_'.substr($scope,6); } }
        foreach ($required as $scope) { if (!in_array($scope,$scopes,true)) { throw new Failure('missing_scope_' . $scope); } }
        $locations = $value['locations']['nodes'] ?? []; $location = ($this->config['location'] ?? '')!==''?Client::gid('Location',$this->config['location']):''; $found=false;
        foreach ($locations as $row) { if ($row['id']===$location && $row['isActive']) { $found=true; } }
        if ($requireLocation && !$found) { throw new Failure('selected_location_not_active'); }
        if (($value['shop']['currencyCode'] ?? '') !== get_woocommerce_currency()) { throw new Failure('currency_mapping_required'); }
        if ((bool)$value['shop']['taxesIncluded'] !== wc_prices_include_tax()) { throw new Failure('price_tax_basis_mapping_required'); }
        return ['shop'=>$value['shop'], 'locations'=>$locations, 'scopes'=>$scopes];
    }
    /** One page per resource, with an independent persisted cursor; missed webhooks recover. */
    public function poll(array $cursors): array
    {
        foreach (['product'=>'products','customer'=>'customers','order'=>'orders'] as $kind=>$connection) {
            if (($kind==='customer' && empty($this->config['customers'])) || ($kind==='order' && empty($this->config['orders']))) { continue; }
            $data=$this->api->query('query InkluraPage($after:String) { '.$connection.'(first:10,after:$after,sortKey:ID) { nodes { id updatedAt } pageInfo { hasNextPage endCursor } } }',['after'=>$cursors[$kind] ?? null]);
            $page=$data[$connection] ?? null;
            if (!is_array($page) || !isset($page['pageInfo']['hasNextPage'])) { throw new Failure('shopify_invalid_page'); }
            foreach ($page['nodes'] as $row) {
                $remote=Client::gid(ucfirst($kind),$row['id']);$signature=$this->pollSignature($kind,$row);$seenKey=$kind.':'.$remote;
                if(($cursors['_seen'][$seenKey]??'')!==$signature){$this->repo->enqueue($kind,$remote);$cursors['_seen'][$seenKey]=$signature;}
            }
            $cursors[$kind] = $page['pageInfo']['hasNextPage'] ? $page['pageInfo']['endCursor'] : null;
        }
        if(!empty($this->config['coupons'])){
            $data=$this->api->query('query InkluraPromotionPage($after:String){codeDiscountNodes(first:10,after:$after){nodes{id codeDiscount{__typename ... on DiscountCodeBasic{updatedAt}}}pageInfo{hasNextPage endCursor}}}',['after'=>$cursors['coupon']??null]);$page=$data['codeDiscountNodes']??null;if(!is_array($page))throw new Failure('shopify_invalid_page');
            foreach($page['nodes']as$row){$remote=Client::gid('DiscountCodeNode',$row['id']);$map=$this->repo->map('coupon',$remote);$local=null;if($map){try{$local=$this->native->coupon((int)$map['local_id']);}catch(\Throwable $e){$local=['needs_review'=>true];}}$signature=Model::hash(['source'=>$row['codeDiscount'],'local'=>$local,'sweep'=>(int)floor(time()/21600)]);$key='coupon:'.$remote;if(($cursors['_seen'][$key]??'')!==$signature){$this->repo->enqueue('coupon',$remote);$cursors['_seen'][$key]=$signature;}}
            $cursors['coupon']=$page['pageInfo']['hasNextPage']?$page['pageInfo']['endCursor']:null;
        }
        return $cursors;
    }
    private function pollSignature(string $kind,array $row):string
    {
        $local=null;$map=$this->repo->map($kind,$row['id']);
        if($map){
            try{
                if($kind==='product'){
                    $local=$this->native->product((int)$map['local_id'],$this->repo);$local['inventory']=[];
                    foreach($this->repo->sql("SELECT * FROM {s}map WHERE kind='variant' AND parent_id=? ORDER BY remote_id",[$row['id']]) as $variant){$local['inventory'][]=['id'=>$variant['remote_id'],'quantity'=>$this->native->quantity((int)$variant['local_id']),'taxable'=>$this->native->taxable((int)$variant['local_id']),'requires_shipping'=>$this->native->requiresShipping((int)$variant['local_id'])];}
                }elseif($kind==='customer'){$local=$this->native->customer((int)$map['local_id']);}
            }catch(\Throwable $error){$local=['unavailable'=>true];}
        }
        // A periodic full check also covers inventory integrations that fail to advance product.updatedAt.
        if($kind==='product' && $map && !empty($this->config['sync_tags'])){$local['tags']=$this->native->tags((int)$map['local_id']);}
        return Model::hash(['updated_at'=>$row['updatedAt']??'','local'=>$local,'sweep'=>$kind==='product'?(int)floor(time()/21600):0,'features'=>[$this->config['sync_tags']??false,$this->config['import_media']??false,$this->config['import_collections']??false,$this->config['order_details']??false]]);
    }
    public function webhook(string $body, array $headers): void
    {
        $value=Model::verifyWebhook($body,$headers,$this->config);
        $topic=(string)($headers['topic'] ?? '');
        if ($topic==='app/uninstalled') { throw new Failure('app_uninstalled'); }
        $kind = null; $type = null;
        if (in_array($topic,['products/create','products/update','products/delete'],true)) { $kind=$topic==='products/delete'?'product_deleted':'product'; $type='Product'; }
        elseif (!empty($this->config['customers']) && in_array($topic,['customers/create','customers/update','customers/delete'],true)) { $kind=$topic==='customers/delete'?'customer_deleted':'customer'; $type='Customer'; }
        elseif (!empty($this->config['orders']) && in_array($topic,['orders/create','orders/updated','orders/paid','orders/cancelled'],true)) { $kind='order'; $type='Order'; }
        elseif (!empty($this->config['coupons']) && in_array($topic,['discounts/create','discounts/update','discounts/delete'],true)) { $kind=$topic==='discounts/delete'?'coupon_deleted':'coupon';$type='DiscountCodeNode'; }
        elseif ($topic==='refunds/create' && !empty($this->config['orders'])) { $kind='order'; $type='Order'; $value['id']=$value['order_id'] ?? null; unset($value['admin_graphql_api_id']); }
        elseif ($topic==='inventory_levels/update') {
            if (Client::gid('Location',$value['location_id'] ?? '') !== Client::gid('Location',$this->config['location'] ?? '')) { return; }
            $kind='inventory'; $type='InventoryItem'; $value['id']=$value['inventory_item_id'] ?? null; unset($value['admin_graphql_api_id']);
        }
        if ($kind===null) { return; }
        if($type==='DiscountCodeNode' && strpos((string)($value['admin_graphql_api_id']??''),'gid://shopify/DiscountAutomaticNode/')===0){$type='DiscountAutomaticNode';$kind='discount_unsupported';}
        $remote=Client::gid($type,$value['admin_graphql_api_id'] ?? $value['id'] ?? '');
        if ($kind==='inventory') {
            // Every path to one product shares its queue order, including retries with an unfinished intent.
            $variant=$this->repo->sql("SELECT parent_id FROM {s}map WHERE kind='variant' AND inventory_id=?",[$remote])[0] ?? null;
            if (!$variant) { return; } // The product scan recovers stock for not-yet-mapped products.
            $kind='product'; $remote=$variant['parent_id'];
        }
        $this->repo->enqueue($kind,$remote,'webhook:'.$headers['id']);
    }
    public function work(): int
    {
        $count=0;
        foreach ($this->repo->jobs() as $job) {
            try {
                $this->process($job);
                $this->repo->state($job,($this->config['mode'] ?? '')==='live'?'done':'audit'); $count++;
            } catch (Failure $error) {
                $retry=$error->retryAfter;
                if (!$retry && in_array($error->reason,['shopify_transport_error','shopify_unavailable','shopify_throttled'],true)) { $retry=60; }
                $state=$retry && (int)$job['attempts']<12?'pending':(strpos($error->reason,'conflict')!==false || strpos($error->reason,'review')!==false || strpos($error->reason,'concurrent_changes')!==false?'conflict':'failed');
                $this->repo->state($job,$state,$error->reason,max($retry,min(3600,30*(2**min(7,(int)$job['attempts'])))));
            } catch (\Throwable $error) { $this->repo->state($job,'failed','native_operation_failed'); }
        }
        return $count;
    }
    private function process(array $job): void
    {
        $kind=$job['kind']; $remote=$job['remote_id'];
        if (in_array($kind,['export_product','export_customer'],true)) {
            $nativeKind=substr($kind,7);
            if (!preg_match('/^woo:'.$nativeKind.':([1-9][0-9]{0,14})$/D',$remote,$match)) { throw new Failure('invalid_export_identity'); }
            $this->export($nativeKind,(int)$match[1],$job); return;
        }
        if ($kind==='product_deleted' || $kind==='customer_deleted') { throw new Failure('source_deletion_needs_review'); }
        if($kind==='discount_unsupported')throw new Failure('automatic_discount_needs_review');
        if($kind==='coupon_deleted'){
            if(!empty($this->config['coupons']) && ($this->config['mode']??'')==='live' && ($map=$this->repo->map('coupon',$remote)))$this->native->holdCoupon((int)$map['local_id'],$remote,'source_discount_deleted_needs_review');
            throw new Failure('source_discount_deleted_needs_review');
        }
        if ($kind==='inventory') {
            $map=$this->repo->sql("SELECT * FROM {s}map WHERE kind='variant' AND inventory_id=?",[$remote])[0] ?? null;
            if (!$map) { return; } $kind='product'; $remote=$map['parent_id'];
        }
        if ($kind==='product') { $this->product($remote,$job); }
        elseif ($kind==='export_coupon') {if(!preg_match('/^woo:coupon:([1-9][0-9]{0,14})$/D',$remote,$match))throw new Failure('invalid_export_identity');$this->exportPromotion((int)$match[1],$job);}
        elseif ($kind==='coupon') { $this->promotion($remote); }
        elseif ($kind==='customer') { if (!empty($this->config['customers'])) { $this->customer($remote); } }
        elseif ($kind==='order') { if (!empty($this->config['orders'])) { $this->order($remote); } }
    }
    private function productRow(string $remote): array
    {
        $value=$this->api->query('query InkluraProduct($id:ID!,$location:ID!) { product(id:$id) { '.Model::PRODUCT_FIELDS.' } }',['id'=>Client::gid('Product',$remote),'location'=>Client::gid('Location',$this->config['location'])]);
        if (!is_array($value['product'] ?? null)) { throw new Failure('source_product_missing'); }
        return $value['product'];
    }
    private function product(string $remote, array $job): void
    {
        $row=$this->productRow($remote); $data=Model::product($row);
        usort($data['variants'],static function($a,$b){return strcmp($a['id'],$b['id']);});
        $map=$this->repo->map('product',$remote);
        if (($this->config['mode'] ?? '')!=='live') { return; }
        if(!$map)$this->awaitOwnCreation('product',$row);
        $taxesReady=[];
        if ($map) { foreach ($row['variants']['nodes'] as $variant) { if ($this->repo->map('variant',$variant['id'])) { $this->taxes($variant); $taxesReady[$variant['id']]=true; } } }
        if (!$map) {
            $this->repo->sql('START TRANSACTION');
            try {
                $id=$this->native->applyProduct($row,$this->repo,$this->config);
                $this->repo->hashes('product',$remote,$this->native->product($id,$this->repo),$data); $this->repo->sql('COMMIT');
            } catch (\Throwable $error) { $this->repo->sql('ROLLBACK'); throw $error; }
        } else {
            $local=$this->native->product((int)$map['local_id'],$this->repo);
            $direction=Model::direction(Model::hash($local),Model::hash($data),$map);
            if ($direction==='push' && ($this->config['catalog_direction'] ?? 'import')!=='both') { throw new Failure('native_catalog_edit_needs_review'); }
            if ($direction==='pull') {
                $this->repo->sql('START TRANSACTION');
                try {
                    $this->native->applyProduct($row,$this->repo,$this->config);
                    $this->repo->hashes('product',$remote,$this->native->product((int)$map['local_id'],$this->repo),$data); $this->repo->sql('COMMIT');
                } catch (\Throwable $error) { $this->repo->sql('ROLLBACK'); throw $error; }
            } elseif ($direction==='push') {
                // Updating a list through productSet would remove missing variants. Use ID-only bulk updates.
                $this->api->mutation('productUpdate','mutation InkluraProductUpdate($product:ProductUpdateInput!) { productUpdate(product:$product) { product { id } userErrors { field message } } }',
                    ['product'=>['id'=>$remote,'title'=>$local['title'],'descriptionHtml'=>$local['description']]]);
                $variants=[];
                foreach ($local['variants'] as $variant) { $vm=$this->repo->map('variant',$variant['id']);$variants[]=['id'=>$variant['id'],'price'=>$variant['price'],'compareAtPrice'=>$variant['compare_at']!==''?$variant['compare_at']:null,'barcode'=>$variant['barcode'],'taxable'=>$this->native->taxable((int)$vm['local_id']),'inventoryItem'=>['sku'=>$variant['sku']]]; }
                $this->api->mutation('productVariantsBulkUpdate','mutation InkluraVariantUpdate($id:ID!,$variants:[ProductVariantsBulkInput!]!) { productVariantsBulkUpdate(productId:$id,variants:$variants,allowPartialUpdates:false) { productVariants { id } userErrors { code field message } } }',['id'=>$remote,'variants'=>$variants]);
                $row=$this->productRow($remote); $fresh=Model::product($row); usort($fresh['variants'],static function($a,$b){return strcmp($a['id'],$b['id']);});
                $this->repo->hashes('product',$remote,$local,$fresh);
            }
        }
        foreach ($row['variants']['nodes'] as $variant) { if(!isset($taxesReady[$variant['id']])){$this->taxes($variant);} $this->inventory($variant,$job);if(!empty($this->config['shipping_flags']))$this->shipping($variant); }
        $this->catalogueExtras($remote);
    }
    private function shipping(array $variant):void
    {
        $map=$this->repo->map('variant',$variant['id']);$id=(int)$map['local_id'];$local=['requires_shipping'=>$this->native->requiresShipping($id)];$source=['requires_shipping'=>(bool)$variant['inventoryItem']['requiresShipping']];$stored=get_post_meta($id,'_inklura_shopify_shipping_state',true);$state=is_array($stored)?$stored:[];
        if($local!==$source){
            if(!$state)throw new Failure('shipping_flag_baseline_needs_review');$direction=Model::direction(Model::hash($local),Model::hash($source),$state);
            if($direction==='push'){
                if(($this->config['catalog_direction']??'import')!=='both')throw new Failure('native_shipping_flag_needs_review');
                $this->api->mutation('productVariantsBulkUpdate','mutation InkluraVariantShipping($id:ID!,$variants:[ProductVariantsBulkInput!]!){productVariantsBulkUpdate(productId:$id,variants:$variants,allowPartialUpdates:false){productVariants{id}userErrors{field message}}}',['id'=>$map['parent_id'],'variants'=>[['id'=>$variant['id'],'inventoryItem'=>['requiresShipping'=>$local['requires_shipping']]]]]);$source=$local;
            }elseif($direction==='pull'){$this->native->setRequiresShipping($id,$source['requires_shipping']);$local=$source;}
        }
        update_post_meta($id,'_inklura_shopify_shipping_state',['local_hash'=>Model::hash($local),'remote_hash'=>Model::hash($source)]);
    }
    private function taxes(array $variant):void
    {
        $map=$this->repo->map('variant',$variant['id']);if(!$map)throw new Failure('variant_mapping_missing');
        $local=$this->native->taxable((int)$map['local_id']);$remote=(bool)$variant['taxable'];
        $localData=['taxable'=>$local];$remoteData=['taxable'=>$remote];
        if($local!==$remote){
            if($map['local_hash']==='' || $map['remote_hash']==='')throw new Failure('taxability_mapping_needs_review');
            $direction=Model::direction(Model::hash($localData),Model::hash($remoteData),$map);
            if($direction==='push'){
                if(($this->config['catalog_direction']??'import')!=='both')throw new Failure('native_taxability_edit_needs_review');
                $this->api->mutation('productVariantsBulkUpdate','mutation InkluraVariantTax($id:ID!,$variants:[ProductVariantsBulkInput!]!) { productVariantsBulkUpdate(productId:$id,variants:$variants,allowPartialUpdates:false) { productVariants { id } userErrors { field message } } }',['id'=>$map['parent_id'],'variants'=>[['id'=>$variant['id'],'taxable'=>$local]]]);$remoteData=$localData;
            }elseif($direction==='pull'){$this->native->setTaxable((int)$map['local_id'],$remote);$localData=$remoteData;}
            else throw new Failure('taxability_mapping_needs_review');
        }
        $this->repo->hashes('variant',$variant['id'],$localData,$remoteData);
    }
    private function inventory(array $variant, array $job): void
    {
        $map=$this->repo->map('variant',$variant['id']);
        if (!$map) { throw new Failure('variant_mapping_missing'); }
        $base=\WD29\Bridge\Protocol::quantity($map['quantity']);
        $local=$this->native->quantity((int)$map['local_id']); $remote=Model::quantity($variant);
        $freshJob=$this->repo->sql('SELECT intent FROM {s}queue WHERE seq=?',[$job['seq']])[0] ?? [];
        $saved=!empty($freshJob['intent'])?json_decode($freshJob['intent'],true,64,JSON_THROW_ON_ERROR):[];
        // A job can reconcile several variants. Each completed step is retained with its original key and arguments.
        $intent=$saved[$variant['id']] ?? null;
        if ($intent && empty($intent['complete'])) {
            if (time()-(int)$intent['created_at']>82800) { throw new Failure('inventory_retry_window_needs_review'); }
            $this->sendInventory($intent);
            $this->repo->sql("UPDATE {s}map SET quantity=? WHERE kind='variant' AND remote_id=?",[$intent['target'],$variant['id']]);
            $saved[$variant['id']]['complete']=true; $this->repo->intent($job,$saved); return;
        }
        $plan=Model::stockPlan($base,$local,$remote);
        if ($plan==='push' && ($this->config['stock_direction'] ?? 'both')!=='both') { throw new Failure('native_inventory_edit_needs_review'); }
        if ($plan==='pull') {
            $this->repo->sql('START TRANSACTION');
            try {
                $this->native->stock((int)$map['local_id'],$local,$remote);
                $this->repo->sql("UPDATE {s}map SET quantity=? WHERE kind='variant' AND remote_id=?",[$remote,$variant['id']]); $this->repo->sql('COMMIT');
            } catch (\Throwable $error) { $this->repo->sql('ROLLBACK'); throw $error; }
        } elseif ($plan==='push') {
            $intent=['created_at'=>time(),'key'=>bin2hex(random_bytes(16)),'item'=>Client::gid('InventoryItem',$map['inventory_id']),'location'=>Client::gid('Location',$this->config['location']),'before'=>$remote,'target'=>$local];
            $saved[$variant['id']]=$intent; $this->repo->intent($job,$saved);
            $this->sendInventory($intent);
            $this->repo->sql("UPDATE {s}map SET quantity=? WHERE kind='variant' AND remote_id=?",[$local,$variant['id']]);
            $saved[$variant['id']]['complete']=true; $this->repo->intent($job,$saved);
        } else { $this->repo->sql("UPDATE {s}map SET quantity=? WHERE kind='variant' AND remote_id=?",[$local,$variant['id']]); }
    }
    private function sendInventory(array $intent): void
    {
        $this->api->mutation('inventorySetQuantities','mutation InkluraInventory($input:InventorySetQuantitiesInput!,$key:String!) { inventorySetQuantities(input:$input) @idempotent(key:$key) { inventoryAdjustmentGroup { referenceDocumentUri } userErrors { code field message } } }',[
            'key'=>$intent['key'],'input'=>['name'=>'available','reason'=>'correction','referenceDocumentUri'=>'inklura-sync://inventory/'.$intent['key'],
                'quantities'=>[['inventoryItemId'=>$intent['item'],'locationId'=>$intent['location'],'quantity'=>$intent['target'],'changeFromQuantity'=>$intent['before']]]]]);
    }
    private function awaitOwnCreation(string $kind,array $row):void
    {
        $uuid=(string)($this->config['source_uuid']??'');$identity=(string)($row['sourceIdentity']['value']??'');
        if(preg_match('/^[a-f0-9]{32}$/D',$uuid)&&preg_match('/^'.preg_quote($uuid.':'.$kind.':','/').'[1-9][0-9]{0,14}$/D',$identity))throw new Failure('own_export_creation_waiting',60);
    }
    private function customer(string $remote): void
    {
        $value=$this->api->query('query InkluraCustomer($id:ID!) { customer(id:$id) { '.Model::CUSTOMER_FIELDS.' } }',['id'=>Client::gid('Customer',$remote)]);
        $row=$value['customer'] ?? null;
        if (!$row) { throw new Failure('source_customer_missing'); }
        $data=Model::customer($row); $map=$this->repo->map('customer',$remote);
        if (($this->config['mode'] ?? '')!=='live') { return; }
        if(!$map)$this->awaitOwnCreation('customer',$row);
        $direction=$map?Model::direction(Model::hash($this->native->customer((int)$map['local_id'])),Model::hash($data),$map):'pull';
        if ($map && $direction==='same' && !get_user_meta((int)$map['local_id'],'_inklura_shopify_address_hash',true)) {
            // Both complete profiles still match their saved baselines: safe upgrade without accepting edits.
            update_user_meta((int)$map['local_id'],'_inklura_shopify_address_hash',Model::fullAddressHash($this->native->customer((int)$map['local_id'])['billing']));
        }
        if ($direction==='push' && ($this->config['customer_direction'] ?? 'import')!=='both') { throw new Failure('native_customer_edit_needs_review'); }
        if ($direction==='push') {
            $local=$this->native->customer((int)$map['local_id']);
            $addressHash=(string)get_user_meta((int)$map['local_id'],'_inklura_shopify_address_hash',true);
            if ($addressHash==='') {
                // Upgrade old baselines only when the previously untracked recipient names are still equal.
                if (Model::addressHash($local['billing'])!==$map['address_hash'] || $local['billing']['first_name']!==$data['billing']['first_name'] || $local['billing']['last_name']!==$data['billing']['last_name']) { throw new Failure('customer_address_baseline_needs_review'); }
                $addressHash=Model::fullAddressHash($local['billing']);
            }
            $addressChanged=Model::fullAddressHash($local['billing'])!==$addressHash;
            if ($addressChanged && ($this->config['customer_address_direction'] ?? 'import')!=='both') { throw new Failure('customer_address_needs_review'); }
            if ($addressChanged) { Model::addressInput($local['billing']); }
            $updated=$this->api->mutation('customerUpdate','mutation InkluraCustomerUpdate($input:CustomerInput!) { customerUpdate(input:$input) { customer { '.Model::CUSTOMER_FIELDS.' } userErrors { field message } } }',
                ['input'=>['id'=>$remote,'firstName'=>$local['first_name'],'lastName'=>$local['last_name'],'email'=>$local['email'],'phone'=>$local['phone']!==''?$local['phone']:null]]);
            $fresh=$updated['customer'];
            if ($addressChanged) { $fresh=$this->pushAddress($fresh,$local['billing']); }
            $this->repo->hashes('customer',$remote,$local,Model::customer($fresh));
        } elseif ($direction==='pull') {
            $this->repo->sql('START TRANSACTION');
            try {
                $id=$this->native->applyCustomer($row,$this->repo);
                $this->repo->hashes('customer',$remote,$this->native->customer($id),$data); $this->repo->sql('COMMIT');
            } catch (\Throwable $error) { $this->repo->sql('ROLLBACK'); throw $error; }
        }
    }
    private function pushAddress(array $row,array $billing):array
    {
        if (Model::fullAddressHash(Model::address($row['defaultAddress']??null))===Model::fullAddressHash($billing)) { return $row; }
        $variables=['customerId'=>$row['id'],'address'=>Model::addressInput($billing)];
        if (!empty($row['defaultAddress']['id'])) {
            $variables['addressId']=$row['defaultAddress']['id'];
            $this->api->mutation('customerAddressUpdate','mutation InkluraAddressUpdate($customerId:ID!,$addressId:ID!,$address:MailingAddressInput!) { customerAddressUpdate(customerId:$customerId,addressId:$addressId,address:$address,setAsDefault:true) { address { id } userErrors { field message } } }',$variables);
        } else {
            $this->api->mutation('customerAddressCreate','mutation InkluraAddressCreate($customerId:ID!,$address:MailingAddressInput!) { customerAddressCreate(customerId:$customerId,address:$address,setAsDefault:true) { address { id } userErrors { field message } } }',$variables);
        }
        $value=$this->api->query('query InkluraCustomer($id:ID!) { customer(id:$id) { '.Model::CUSTOMER_FIELDS.' } }',['id'=>$row['id']]);
        $fresh=$value['customer']??null;
        if (!$fresh || Model::fullAddressHash(Model::address($fresh['defaultAddress']??null))!==Model::fullAddressHash($billing)) { throw new Failure('customer_address_write_needs_review'); }
        return $fresh;
    }
    private function order(string $remote): void
    {
        $value=$this->api->query('query InkluraOrder($id:ID!) { order(id:$id) { '.Model::ORDER_FIELDS.' } }',['id'=>Client::gid('Order',$remote)]);
        if (!is_array($value['order'] ?? null)) { throw new Failure('source_order_missing'); }
        if (($this->config['mode'] ?? '')==='live') { $this->native->applyOrder($value['order'],$this->repo); }
        if(!empty($this->config['order_details'])){
            $details=$this->api->query('query InkluraOrderDetails($id:ID!){order(id:$id){'.OrderDetails::FIELDS.'}}',['id'=>$remote]);
            if(!is_array($details['order']??null))throw new Failure('source_order_missing');OrderDetails::projection($details['order'],get_woocommerce_currency());
            if(($this->config['mode']??'')==='live')OrderDetails::apply($details['order'],$this->repo);
        }
    }
}
