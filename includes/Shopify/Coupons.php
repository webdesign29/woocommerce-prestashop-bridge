<?php
namespace WD29\Bridge\Shopify;

trait Coupons
{
    public function coupon(int $id):array
    {
        $c=new \WC_Coupon($id);if(!$c->get_id())throw new Failure('native_coupon_missing');
        $this->couponCodeIdentity($c->get_code('edit'),$id);
        $type=$c->get_discount_type('edit');
        if(!in_array($type,['percent','fixed_cart','fixed_product'],true) || $c->get_meta('_inklura_native_origin')==='ps')throw new Failure('kerawen_entitlement_needs_authority_review');
        foreach(['usage_limit','usage_limit_per_user','limit_usage_to_x_items','product_ids','excluded_product_ids','product_categories','excluded_product_categories','email_restrictions','exclude_sale_items','free_shipping','minimum_amount','maximum_amount']as$field){$getter='get_'.$field;$value=$c->$getter('edit');if(is_array($value)?count($value)>0:(bool)(is_numeric($value)?(float)$value:$value))throw new Failure('coupon_eligibility_or_quota_needs_review');}
        if(!$c->get_individual_use('edit'))throw new Failure('discount_stacking_needs_review');
        $amount=Model::money((string)$c->get_amount('edit'));if((float)$amount<=0 || ($type==='percent' && (float)$amount>100))throw new Failure('discount_amount_needs_review');
        $expires=$c->get_date_expires('edit');$ends=$expires?$expires->getTimestamp():null;$starts=(int)$c->get_meta('_inklura_shopify_starts_at');if(!$starts)$starts=$c->get_date_created('edit')->getTimestamp();
        return ['code'=>Promotion::code($c->get_code('edit')),'type'=>$type,'amount'=>$amount,'starts'=>$starts,'expires'=>$ends,'active'=>get_post_status($id)==='publish' && $starts<=time() && ($ends===null||$ends>time())];
    }
    public function markCoupon(int $id,string $remote):void
    {
        $c=new \WC_Coupon($id);$known=$c->get_meta('_inklura_shopify_gid');if(!$c->get_id() || ($known && $known!==$remote))throw new Failure('native_coupon_identity_changed');$c->update_meta_data('_inklura_shopify_gid',$remote);$c->save_meta_data();
    }
    public function applyCoupon(array $data,string $remote,int $id=0):int
    {
        if(!$id){$ids=get_posts(['post_type'=>'shop_coupon','post_status'=>'any','fields'=>'ids','posts_per_page'=>2,'meta_key'=>'_inklura_shopify_gid','meta_value'=>$remote]);if(count($ids)>1)throw new Failure('native_coupon_identity_changed');$id=(int)($ids[0]??0);}
        $this->couponCodeIdentity($data['code'],$id);
        $c=$id?new \WC_Coupon($id):new \WC_Coupon();if($id && $c->get_meta('_inklura_shopify_gid')!==$remote)throw new Failure('native_coupon_identity_changed');
        $c->set_code($data['code']);$c->set_discount_type($data['type']);$c->set_amount($data['amount']);$c->set_individual_use(true);$c->set_date_expires($data['expires']);$c->set_status($data['active']?'publish':'draft');$c->update_meta_data('_inklura_shopify_gid',$remote);$c->update_meta_data('_inklura_shopify_starts_at',$data['starts']);$c->update_meta_data('_inklura_shopify_coupon_held','');$c->save();return $c->get_id();
    }
    public function couponSnapshot(int $id):string
    {
        $c=new \WC_Coupon($id);$data=$c->get_data();
        unset($data['meta_data'],$data['date_created'],$data['date_modified'],$data['usage_count'],$data['used_by']);
        $date=$c->get_date_expires('edit');$data['date_expires']=$date?$date->getTimestamp():null;$data['starts_at']=(int)$c->get_meta('_inklura_shopify_starts_at');return Model::hash($data);
    }
    private function couponCodeIdentity(string $code,int $id):void
    {
        global $wpdb;
        $other=$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='shop_coupon' AND LOWER(post_title)=LOWER(%s) AND ID<>%d LIMIT 1",$code,$id));
        if($other)throw new Failure('coupon_code_identity_conflict');
    }
    public function couponCanResume(int $id,string $remote):bool
    {
        $c=new \WC_Coupon($id);return $c->get_meta('_inklura_shopify_gid')===$remote && $c->get_meta('_inklura_shopify_hold_origin')==='source' && $c->get_meta('_inklura_shopify_hold_hash')===$this->couponSnapshot($id);
    }
    public function clearCouponHold(int $id):void
    {
        $c=new \WC_Coupon($id);$c->update_meta_data('_inklura_shopify_coupon_held','');$c->update_meta_data('_inklura_shopify_hold_hash','');$c->update_meta_data('_inklura_shopify_hold_origin','');$c->save_meta_data();
    }
    public function holdCoupon(int $id,string $remote,string $reason,string $origin='source'):void
    {
        $c=new \WC_Coupon($id);if(!$c->get_id() || $c->get_meta('_inklura_shopify_gid')!==$remote)throw new Failure('native_coupon_identity_changed');
        $first=!$c->get_meta('_inklura_shopify_coupon_held');$c->update_meta_data('_inklura_shopify_coupon_held',$reason);$c->set_status('draft');$c->save();
        if($first){$c=new \WC_Coupon($id);$c->update_meta_data('_inklura_shopify_hold_origin',$origin);$c->update_meta_data('_inklura_shopify_hold_hash',$this->couponSnapshot($id));$c->save_meta_data();}
    }
}
