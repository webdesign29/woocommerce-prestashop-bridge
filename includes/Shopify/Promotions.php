<?php
namespace WD29\Bridge\Shopify;

/** Ordinary unlimited promotions only. Consumable entitlements remain with their authority. */
final class Promotion
{
    const FIELDS='id codeDiscount { __typename ... on DiscountCodeBasic { title updatedAt status startsAt endsAt usageLimit appliesOncePerCustomer combinesWith { orderDiscounts productDiscounts shippingDiscounts productDiscountsWithTagsOnSameCartLine } context { __typename } codes(first:2) { nodes { code } pageInfo { hasNextPage } } minimumRequirement { __typename } customerGets { appliesOnSubscription appliesOnOneTimePurchase items { __typename } value { __typename ... on DiscountPercentage { percentage } ... on DiscountAmount { amount { amount currencyCode } appliesOnEachItem } } } } }';
    public static function code(string $code):string
    {
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/D',$code))throw new Failure('coupon_code_needs_review');return strtolower($code);
    }
    public static function timestamp(?string $value):?int
    {
        if($value===null)return null;
        if(!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)$/D',$value))throw new Failure('discount_date_needs_review');
        try{return (new \DateTimeImmutable($value))->getTimestamp();}catch(\Throwable $error){throw new Failure('discount_date_needs_review');}
    }
    public static function source(array $row,string $currency):array
    {
        $d=$row['codeDiscount']??[];$gets=$d['customerGets']??[];
        if(($d['__typename']??'')!=='DiscountCodeBasic' || ($d['context']['__typename']??'')!=='DiscountBuyerSelectionAll' || ($gets['items']['__typename']??'')!=='AllDiscountItems')throw new Failure('discount_rule_needs_review');
        if(($d['usageLimit']??null)!==null || !empty($d['appliesOncePerCustomer']))throw new Failure('shared_discount_quota_needs_authority_review');
        if(!empty($d['minimumRequirement']) || empty($gets['appliesOnOneTimePurchase']) || !empty($gets['appliesOnSubscription']))throw new Failure('discount_eligibility_needs_review');
        $combines=$d['combinesWith']??[];
        if(!array_key_exists('orderDiscounts',$combines) || !array_key_exists('productDiscounts',$combines) || !array_key_exists('shippingDiscounts',$combines) || array_filter($combines))throw new Failure('discount_stacking_needs_review');
        if(count($d['codes']['nodes']??[])!==1 || !empty($d['codes']['pageInfo']['hasNextPage']))throw new Failure('discount_multiple_codes_needs_review');
        $v=$gets['value'];
        if(($v['__typename']??'')==='DiscountPercentage'){$type='percent';$amount=Model::money(number_format((float)$v['percentage']*100,6,'.',''));if((float)$amount>100)throw new Failure('discount_amount_needs_review');}
        elseif(($v['__typename']??'')==='DiscountAmount'){
            if(($v['amount']['currencyCode']??'')!==$currency)throw new Failure('discount_currency_needs_review');
            $type=!empty($v['appliesOnEachItem'])?'fixed_product':'fixed_cart';$amount=Model::money($v['amount']['amount']);
        }else{throw new Failure('discount_value_needs_review');}
        if((float)$amount<=0)throw new Failure('discount_amount_needs_review');
        $starts=self::timestamp($d['startsAt']);$ends=self::timestamp($d['endsAt']);
        return ['code'=>self::code($d['codes']['nodes'][0]['code']),'type'=>$type,'amount'=>$amount,'starts'=>$starts,'expires'=>$ends,'active'=>$d['status']==='ACTIVE' && $starts<=time() && ($ends===null||$ends>time())];
    }
    public static function input(array $data,?string $marker=null):array
    {
        $value=$data['type']==='percent'?['percentage'=>(float)$data['amount']/100]:['discountAmount'=>['amount'=>$data['amount'],'appliesOnEachItem'=>$data['type']==='fixed_product']];
        $ends=$data['expires'];if(!$data['active'] && $data['starts']<=time() && ($ends===null||$ends>time()))$ends=time();
        // Ordinary discounts use Shopify’s one-time-purchase defaults. Explicit
        // subscription flags are rejected on shops without subscriptions. Reads
        // and the post-write check still require one-time-only eligibility.
        $out=['title'=>'WooCommerce — '.$data['code'],'code'=>$data['code'],'context'=>['all'=>'ALL'],'startsAt'=>gmdate('Y-m-d\TH:i:s\Z',$data['starts']),'endsAt'=>$ends===null?null:gmdate('Y-m-d\TH:i:s\Z',$ends),'usageLimit'=>null,'appliesOncePerCustomer'=>false,'combinesWith'=>['orderDiscounts'=>false,'productDiscounts'=>false,'shippingDiscounts'=>false],
            'customerGets'=>['items'=>['all'=>true],'value'=>$value]];
        if($marker!==null)$out['tags']=[$marker];return $out;
    }
}

trait Promotions
{
    private function promotionRow(string $remote):array
    {
        $value=$this->api->query('query InkluraPromotion($id:ID!) { codeDiscountNode(id:$id) { '.Promotion::FIELDS.' } }',['id'=>Client::gid('DiscountCodeNode',$remote)]);
        if(!is_array($value['codeDiscountNode']??null))throw new Failure('source_discount_missing_needs_review');return $value['codeDiscountNode'];
    }
    private function promotion(string $remote):void
    {
        if(empty($this->config['coupons']))return;
        $map=$this->repo->map('coupon',$remote);
        try{$row=$this->promotionRow($remote);$source=Promotion::source($row,get_woocommerce_currency());}
        catch(Failure $error){if($map && ($this->config['mode']??'')==='live')$this->native->holdCoupon((int)$map['local_id'],$remote,$error->reason);throw $error;}
        if(($this->config['mode']??'')!=='live')return;
        if($source['starts']>time()){
            if($map)$this->native->holdCoupon((int)$map['local_id'],$remote,'scheduled_discount_waiting');
            throw new Failure('scheduled_discount_waiting',$source['starts']-time()+1);
        }
        if(!$map){$id=$this->native->applyCoupon($source,$remote);$this->repo->bind('coupon',$remote,$id);$this->repo->hashes('coupon',$remote,$this->native->coupon($id),$source);return;}
        if($this->native->couponCanResume((int)$map['local_id'],$remote)){
            $this->native->applyCoupon($source,$remote,(int)$map['local_id']);$this->native->clearCouponHold((int)$map['local_id']);$this->repo->hashes('coupon',$remote,$this->native->coupon((int)$map['local_id']),$source);return;
        }
        try{$local=$this->native->coupon((int)$map['local_id']);}
        catch(Failure $error){
            $this->native->holdCoupon((int)$map['local_id'],$remote,$error->reason,'native');
            if(($this->config['coupon_direction']??'import')==='both'){
                $this->deactivatePromotion($remote);$heldSource=Promotion::source($this->promotionRow($remote),get_woocommerce_currency());
                if($heldSource['active'])throw new Failure('discount_deactivation_needs_review');
                $this->repo->sql("UPDATE {s}map SET remote_hash=? WHERE kind='coupon' AND remote_id=?",[Model::hash($heldSource),$remote]);
            }
            throw $error;
        }
        $direction=$local===$source?'same':Model::direction(Model::hash($local),Model::hash($source),$map);
        if($direction==='push'){
            if(($this->config['coupon_direction']??'import')!=='both')throw new Failure('native_coupon_edit_needs_review');
            $this->api->mutation('discountCodeBasicUpdate','mutation InkluraPromotionUpdate($id:ID!,$input:DiscountCodeBasicInput!){discountCodeBasicUpdate(id:$id,basicCodeDiscount:$input){codeDiscountNode{id}userErrors{field message}}}',['id'=>$remote,'input'=>Promotion::input($local)]);
            $source=Promotion::source($this->promotionRow($remote),get_woocommerce_currency());
            foreach(['code','type','amount','starts']as$field){if($local[$field]!==$source[$field])throw new Failure('discount_write_needs_review');}
            if($local['active'] && $local['expires']!==$source['expires'])throw new Failure('discount_write_needs_review');
        }elseif($direction==='pull'){$this->native->applyCoupon($source,$remote,(int)$map['local_id']);$local=$this->native->coupon((int)$map['local_id']);}
        $this->native->clearCouponHold((int)$map['local_id']);$this->repo->hashes('coupon',$remote,$local,$source);
    }
    private function deactivatePromotion(string $remote):void
    {
        $this->api->mutation('discountCodeDeactivate','mutation InkluraPromotionHold($id:ID!){discountCodeDeactivate(id:$id){codeDiscountNode{id}userErrors{field message}}}',['id'=>$remote]);
    }
    private function exportPromotion(int $id,array $job):void
    {
        if(empty($this->config['coupons'])||($this->config['coupon_direction']??'import')!=='both')throw new Failure('coupon_export_not_enabled');
        if($map=$this->repo->local('coupon',$id)){$this->repo->enqueue('coupon',$map['remote_id']);return;}
        $local=$this->native->coupon($id);if(!$local['active'])throw new Failure('new_coupon_export_requires_active_coupon');
        if(($this->config['mode']??'')!=='live')return;
        $intent=!empty($job['intent'])?json_decode($job['intent'],true,64,JSON_THROW_ON_ERROR):[];
        if(isset($intent['export_hash']) && $intent['export_hash']!==Model::hash($local))throw new Failure('native_export_changed_needs_review');
        $intent+=['export_hash'=>Model::hash($local),'marker'=>'inklura-sync-'.bin2hex(random_bytes(24))];$this->repo->intent($job,$intent);
        $found=$this->api->query('query InkluraPromotionLookup($code:String!){codeDiscountNodeByCode(code:$code){'.Promotion::FIELDS.' codeDiscount { ... on DiscountCodeBasic { tags } }}}',['code'=>$local['code']]);$row=$found['codeDiscountNodeByCode']??null;
        if($row){if(!in_array($intent['marker'],$row['codeDiscount']['tags']??[],true))throw new Failure('coupon_code_identity_conflict');}
        else{$created=$this->api->mutation('discountCodeBasicCreate','mutation InkluraPromotionCreate($input:DiscountCodeBasicInput!){discountCodeBasicCreate(basicCodeDiscount:$input){codeDiscountNode{'.Promotion::FIELDS.'}userErrors{field message}}}',['input'=>Promotion::input($local,$intent['marker'])]);$row=$created['codeDiscountNode']??null;}
        if(!$row || Promotion::source($row,get_woocommerce_currency())!==$local)throw new Failure('created_discount_needs_review');
        $remote=Client::gid('DiscountCodeNode',$row['id']);$this->native->markCoupon($id,$remote);$this->repo->bind('coupon',$remote,$id);$this->repo->hashes('coupon',$remote,$local,Promotion::source($row,get_woocommerce_currency()));
    }
}
