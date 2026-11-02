<?php
namespace WD29\Bridge\Shopify;

trait Orders
{
    private function orderStatus(array $row): string
    {
        if ($row['cancelledAt']) { return 'shopify-cancel'; }
        if (in_array($row['displayFinancialStatus'],['REFUNDED','PARTIALLY_REFUNDED'],true)) { return 'shopify-refund'; }
        return $row['displayFinancialStatus']==='PAID'?'shopify-paid':'shopify-pending';
    }
    private function moneyBag(array $bag, string $currency): float
    {
        if (($bag['shopMoney']['currencyCode'] ?? '')!==$currency) { throw new Failure('order_currency_mismatch'); }
        return (float)Model::money($bag['shopMoney']['amount']);
    }
    private function orderTaxes(array $lines, array $address, string $currency, string $class = ''): array
    {
        $taxes=[];
        $rates=\WC_Tax::find_rates(['country'=>$address['country'],'state'=>$address['state'],'postcode'=>$address['postcode'],'city'=>$address['city'],'tax_class'=>$class]);
        foreach ($lines as $tax) {
            $amount=$this->moneyBag($tax['priceSet'],$currency); if ($amount==0) { continue; }
            $found=[];
            foreach ($rates as $id=>$rate) { if (abs((float)$rate['rate']-100*(float)$tax['rate'])<0.00001 && ($rate['compound'] ?? 'no')==='no') { $found[]=$id; } }
            if (count($found)!==1) { throw new Failure('order_tax_mapping_needs_review'); }
            $taxes[$found[0]]=($taxes[$found[0]] ?? 0)+$amount;
        }
        return $taxes;
    }
    public function orderSnapshot($order): array
    {
        $items=[];
        foreach ($order->get_items(['line_item','shipping','tax','fee','coupon']) as $item) {
            $line=['id'=>(int)$item->get_id(),'type'=>$item->get_type(),'name'=>$item->get_name(),'source'=>$item->get_meta('_inklura_shopify_line')];
            foreach (['product_id','variation_id','quantity','total','subtotal','total_tax','subtotal_tax','tax_total','shipping_tax_total','method_id','instance_id','rate_id'] as $field) {
                $getter='get_'.$field;if (method_exists($item,$getter)) { $value=$item->$getter();$line[$field]=is_numeric($value)?number_format((float)$value,6,'.',''):(string)$value; }
            }
            if (method_exists($item,'get_taxes')) { $taxes=$item->get_taxes();foreach($taxes as &$values){foreach($values as &$amount){$amount=number_format((float)$amount,6,'.','');}unset($amount);}unset($values);$line['taxes']=$taxes; }
            $items[]=$line;
        }
        return ['currency'=>$order->get_currency(),'total'=>number_format((float)$order->get_total(),6,'.',''),'tax'=>number_format((float)$order->get_total_tax(),6,'.',''),'billing'=>$order->get_address('billing'),'shipping'=>$order->get_address('shipping'),
            'customer'=>$order->get_customer_id(),'status'=>$order->get_status(),'payment'=>$order->get_payment_method(),'transaction'=>$order->get_transaction_id(),'refunds'=>array_map(static function($r){return $r->get_id();},$order->get_refunds()),'items'=>$items];
    }
    public function applyOrder(array $row, Repository $repo): void
    {
        if (!empty($row['lineItems']['pageInfo']['hasNextPage']) || !empty($row['shippingLines']['pageInfo']['hasNextPage'])) { throw new Failure('order_exceeds_supported_lines'); }
        $currency=$row['currencyCode'];
        if ($currency!==get_woocommerce_currency()) { throw new Failure('order_currency_mismatch'); }
        $map=$repo->map('order',$row['id']);
        if ($map) {
            $o=wc_get_order((int)$map['local_id']);
            if (!$o || $o->get_meta('_inklura_shopify_gid')!==$row['id'] || $o->get_meta('_inklura_shopify_order')!=='1') { throw new Failure('native_order_identity_changed'); }
            if (Model::hash($this->orderSnapshot($o))!==$map['local_hash']) { throw new Failure('native_order_edit_needs_review'); }
            if (Model::hash($row)===$map['remote_hash']) { return; }
            // Preserve native line IDs and accounting history. A changed financial structure requires review.
            $original=$o->get_meta('_inklura_shopify_financial_hash');
            $finance=$row; unset($finance['displayFinancialStatus'],$finance['displayFulfillmentStatus'],$finance['cancelledAt'],$finance['totalRefundedSet']);
            if ($original!==Model::hash($finance)) { throw new Failure('shopify_order_financial_edit_needs_review'); }
            $o->set_status($this->orderStatus($row));
            $o->update_meta_data('_inklura_shopify_financial_status',$row['displayFinancialStatus']);
            $o->update_meta_data('_inklura_shopify_fulfillment_status',$row['displayFulfillmentStatus']);
            $o->update_meta_data('_inklura_shopify_refunded',$row['totalRefundedSet']['shopMoney']); $o->save();
            $repo->hashes('order',$row['id'],$this->orderSnapshot($o),$row); return;
        }
        $existing=wc_get_orders(['limit'=>2,'return'=>'ids','meta_key'=>'_inklura_shopify_gid','meta_value'=>$row['id']]);
        if ($existing) { throw new Failure('unmapped_native_order_needs_review'); }
        $customer=0;
        if (!empty($row['customer']['id'])) {
            $contact=$repo->map('customer',$row['customer']['id']);
            if (!$contact) { $repo->enqueue('customer',$row['customer']['id']); throw new Failure('order_customer_pending',60); }
            $customer=(int)$contact['local_id']; $this->customer($customer);
        }
        $address=Model::address($row['shippingAddress'] ?? $row['billingAddress'] ?? null);
        $products=[]; $netTotal=0; $taxTotal=0;
        foreach ($row['lineItems']['nodes'] as $line) {
            $variant=!empty($line['variant']['id'])?$repo->map('variant',$line['variant']['id']):null;
            if (!$variant || !($p=wc_get_product((int)$variant['local_id']))) {
                if (!empty($line['variant']['product']['id'])) { $repo->enqueue('product',$line['variant']['product']['id']); throw new Failure('order_products_pending',60); }
                throw new Failure('custom_or_deleted_order_item_needs_review');
            }
            $quantity=\WD29\Bridge\Protocol::quantity($line['quantity']);
            if ($quantity===null || $quantity<1) { throw new Failure('invalid_order_quantity'); }
            $taxes=$this->orderTaxes($line['taxLines'],$address,$currency,$p->get_tax_class()); $tax=array_sum($taxes);
            $amount=$this->moneyBag($line['discountedTotalSet'],$currency); $net=$amount-($row['taxesIncluded']?$tax:0);
            if ($net<0) { throw new Failure('invalid_order_money'); }
            $products[]=['source'=>$line,'product'=>$p,'quantity'=>$quantity,'net'=>$net,'taxes'=>$taxes]; $netTotal+=$net; $taxTotal+=$tax;
        }
        $shipping=[];
        foreach ($row['shippingLines']['nodes'] as $line) {
            $taxes=$this->orderTaxes($line['taxLines'],$address,$currency); $tax=array_sum($taxes);
            $net=$this->moneyBag($line['discountedPriceSet'],$currency)-($row['taxesIncluded']?$tax:0);
            if ($net<0) { throw new Failure('invalid_order_money'); }
            $shipping[]=['source'=>$line,'net'=>$net,'taxes'=>$taxes]; $netTotal+=$net; $taxTotal+=$tax;
        }
        $total=$this->moneyBag($row['totalPriceSet'],$currency); $sourceTax=$this->moneyBag($row['totalTaxSet'],$currency);
        if (abs($netTotal+$taxTotal-$total)>0.01 || abs($taxTotal-$sourceTax)>0.01) { throw new Failure('order_total_components_needs_review'); }
        $repo->sql('START TRANSACTION');
        try {
            $o=new \WC_Order(); $o->set_created_via('inklura_shopify'); $o->set_status($this->orderStatus($row));
            $o->set_currency($currency); $o->set_customer_id($customer); $billing=Model::address($row['billingAddress'] ?? null); $billing['email']=(string)($row['email'] ?? '');
            $o->set_address($billing,'billing'); $o->set_address(Model::address($row['shippingAddress'] ?? null),'shipping'); $o->set_date_created($row['createdAt']);
            $o->set_payment_method(''); $o->set_payment_method_title('Recorded on Shopify');
            $o->update_meta_data('_inklura_shopify_gid',$row['id']); $o->update_meta_data('_inklura_shopify_order','1');
            $o->update_meta_data('_inklura_shopify_number',$row['name']); $o->update_meta_data('_inklura_shopify_financial_status',$row['displayFinancialStatus']);
            $o->update_meta_data('_inklura_shopify_fulfillment_status',$row['displayFulfillmentStatus']); $o->update_meta_data('_inklura_shopify_refunded',$row['totalRefundedSet']['shopMoney']);
            $finance=$row; unset($finance['displayFinancialStatus'],$finance['displayFulfillmentStatus'],$finance['cancelledAt'],$finance['totalRefundedSet']); $o->update_meta_data('_inklura_shopify_financial_hash',Model::hash($finance));
            foreach ($products as $line) {
                $item=new \WC_Order_Item_Product(); $item->set_product($line['product']); $item->set_name($line['source']['name']); $item->set_quantity($line['quantity']);
                $item->set_subtotal($line['net']); $item->set_total($line['net']); $item->set_taxes(['subtotal'=>$line['taxes'],'total'=>$line['taxes']]);
                $item->update_meta_data('_inklura_shopify_line',$line['source']['id']); $o->add_item($item);
            }
            foreach ($shipping as $line) {
                $item=new \WC_Order_Item_Shipping(); $item->set_method_title($line['source']['title']); $item->set_method_id('inklura_shopify'); $item->set_total($line['net']); $item->set_taxes(['total'=>$line['taxes']]); $o->add_item($item);
            }
            $o->save(); $o->update_taxes(); $o->calculate_totals(false); $o->save();
            if (abs((float)$o->get_total()-$total)>0.01 || abs((float)$o->get_total_tax()-$sourceTax)>0.01) { throw new Failure('native_order_total_mismatch'); }
            $repo->bind('order',$row['id'],$o->get_id()); $repo->hashes('order',$row['id'],$this->orderSnapshot($o),$row); $repo->sql('COMMIT');
        } catch (\Throwable $error) { $repo->sql('ROLLBACK'); throw $error; }
    }
}
