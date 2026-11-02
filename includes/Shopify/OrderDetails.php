<?php
namespace WD29\Bridge\Shopify;
require_once __DIR__ . '/../I18n.php';

/** Additional source history is consultation data, independent of the native financial snapshot. */
final class OrderDetails
{
    const FIELDS='id fulfillments(first:11) { id status createdAt updatedAt trackingInfo(first:11) { company number url } } refunds(first:11) { id createdAt totalRefundedSet { shopMoney { amount currencyCode } } } transactions(first:51) { id kind status gateway processedAt amountSet { shopMoney { amount currencyCode } } }';
    private static function text($value,int $limit=256):string
    {
        if($value===null)return '';if(!is_string($value)||strlen($value)>$limit||preg_match('/[\x00-\x1f\x7f]/',$value))throw new Failure('order_detail_text_needs_review');return $value;
    }
    private static function amount(array $bag,string $currency):string
    {
        if(($bag['shopMoney']['currencyCode']??'')!==$currency)throw new Failure('order_detail_currency_needs_review');return Model::money($bag['shopMoney']['amount']);
    }
    public static function projection(array $row,string $currency):array
    {
        if(count($row['fulfillments']??[])>10 || count($row['refunds']??[])>10 || count($row['transactions']??[])>50)throw new Failure('order_history_exceeds_supported_details');
        $out=['fulfillments'=>[],'refunds'=>[],'transactions'=>[]];
        foreach($row['fulfillments']??[]as$f){
            if(count($f['trackingInfo'])>10)throw new Failure('fulfillment_exceeds_10_tracking_records');$tracking=[];
            foreach($f['trackingInfo']as$t){$tracking[]=['company'=>self::text($t['company']),'number'=>self::text($t['number']),'url'=>self::text($t['url'],2048)];}
            $out['fulfillments'][]=['id'=>Client::gid('Fulfillment',$f['id']),'status'=>self::text($f['status']),'created'=>self::text($f['createdAt']),'updated'=>self::text($f['updatedAt']),'tracking'=>$tracking];
        }
        foreach($row['refunds']??[]as$r){$out['refunds'][]=['id'=>Client::gid('Refund',$r['id']),'created'=>self::text($r['createdAt']),'amount'=>self::amount($r['totalRefundedSet'],$currency),'currency'=>$currency];}
        foreach($row['transactions']??[]as$t){$out['transactions'][]=['id'=>Client::gid('OrderTransaction',$t['id']),'kind'=>self::text($t['kind']),'status'=>self::text($t['status']),'gateway'=>self::text($t['gateway']),'processed'=>self::text($t['processedAt']),'amount'=>self::amount($t['amountSet'],$currency),'currency'=>$currency];}
        foreach($out as&$rows){usort($rows,static function($a,$b){return strcmp($a['id'],$b['id']);});if(count(array_unique(array_column($rows,'id')))!==count($rows))throw new Failure('duplicate_order_detail_identity');}unset($rows);return $out;
    }
    public static function apply(array $row,Repository $repo):void
    {
        $remote=Client::gid('Order',$row['id']);$map=$repo->map('order',$remote);$o=$map?wc_get_order((int)$map['local_id']):null;
        if(!$o || $o->get_meta('_inklura_shopify_order')!=='1' || $o->get_meta('_inklura_shopify_gid')!==$remote)throw new Failure('native_order_identity_changed');
        $data=self::projection($row,$o->get_currency());$old=$o->get_meta('_inklura_shopify_details');$previous=is_array($old)?$old:[];
        if($previous && Model::hash($previous)!==$o->get_meta('_inklura_shopify_details_hash'))throw new Failure('native_order_history_edit_needs_review');
        foreach($previous as$kind=>$rows){foreach($rows as$record){if(!in_array($record['id'],array_column($data[$kind], 'id'),true))throw new Failure('source_order_detail_removal_needs_review');}}
        if($data===$previous)return;
        $o->update_meta_data('_inklura_shopify_details',$data);$o->update_meta_data('_inklura_shopify_details_hash',Model::hash($data));$o->save_meta_data();
    }
    public static function render($order):void
    {
        $data=$order->get_meta('_inklura_shopify_details');if(!is_array($data))return;
        echo '<div class="order_data_column"><h4>Historique Shopify — consultation</h4>';
        foreach(['fulfillments'=>\WD29\Bridge\I18n::t('Shipments'),'refunds'=>\WD29\Bridge\I18n::t('Refunds'),'transactions'=>\WD29\Bridge\I18n::t('Payments')]as$kind=>$label){
            if(empty($data[$kind]))continue;echo '<p><strong>'.esc_html($label).'</strong></p><ul>';
            foreach($data[$kind]as$row){
                if($kind==='fulfillments'){$parts=[$row['status'],$row['updated']];foreach($row['tracking']as$tracking){$parts[]=trim($tracking['company'].' '.$tracking['number']);}}
                else{$parts=[$kind==='refunds'?$row['created']:trim($row['kind'].' '.$row['status'].' '.$row['gateway']),$row['amount'].' '.$row['currency']];}
                echo '<li>'.esc_html(implode(' · ',array_filter($parts))).'</li>';
            }echo '</ul>';
        }echo '</div>';
    }
}
