<?php
namespace WD29\Bridge;

/** Source-owned audit records. Never initiates a payment, restock or fiscal document. */
final class Refunds
{
    public static function validate(array $rows, string $origin): array
    {
        if (!in_array($origin,['woo','ps'],true) || count($rows)>500) { throw new \RuntimeException('Invalid refund ledger.'); }
        $seen=[];
        foreach ($rows as $row) {
            if (!is_array($row) || !preg_match('/^'.preg_quote($origin,'/').':refund:[1-9][0-9]*$/D',(string)($row['source_id']??'')) || isset($seen[$row['source_id']])) { throw new \RuntimeException('Invalid or duplicate refund identity.'); }
            $seen[$row['source_id']]=true;
            foreach (['amount','net','tax','shipping_net','shipping_tax'] as $field) {
                if (!array_key_exists($field,$row) || ($row[$field]!==null && (!is_scalar($row[$field]) || !is_numeric($row[$field]) || !is_finite((float)$row[$field]) || (float)$row[$field]<0 || (float)$row[$field]>1000000000000))) { throw new \RuntimeException('Invalid refund amount.'); }
            }
            if (!is_string($row['reason']??null) || strlen($row['reason'])>4000 || !is_string($row['created']??null) || strlen($row['created'])>40 || !in_array($row['payment_refunded']??null,[true,false,null],true) || !is_array($row['lines']??null) || count($row['lines'])>500) { throw new \RuntimeException('Invalid refund details.'); }
            $lineIds=[];
            foreach ($row['lines'] as $line) {
                if (!is_array($line) || !preg_match('/^[1-9][0-9]*$/D',(string)($line['source_line_id']??'')) || isset($lineIds[$line['source_line_id']])) { throw new \RuntimeException('Invalid refund line identity.'); }
                $lineIds[$line['source_line_id']]=true;
                foreach (['quantity','net','tax'] as $field) { if (!isset($line[$field]) || !is_scalar($line[$field]) || !is_numeric($line[$field]) || !is_finite((float)$line[$field]) || (float)$line[$field]<0 || (float)$line[$field]>1000000000000) { throw new \RuntimeException('Invalid refund line amount.'); } }
            }
        }
        usort($rows,function($a,$b){return strcmp($a['source_id'],$b['source_id']);});
        return array_values($rows);
    }
    public static function validateOrder(array $data): array
    {
        $origin=explode(':',(string)($data['key']??''))[0];
        $rows=self::validate($data['refunds']??[],$origin); $sum=0.0;
        foreach ($rows as $row) { if ($row['amount']!==null) { $sum+=(float)$row['amount']; } }
        if (!isset($data['total']) || !is_numeric($data['total']) || $sum>(float)$data['total']+0.02) { throw new \RuntimeException('Refund total exceeds original order total.'); }
        return $rows;
    }
    public static function export($order): array
    {
        $rows=[];
        foreach ($order->get_refunds() as $refund) {
            $lines=[];
            foreach ($refund->get_items(['line_item','shipping','fee']) as $item) {
                $source=(int)$item->get_meta('_refunded_item_id');
                if (!$source) { continue; }
                $lines[]=['source_line_id'=>(string)$source,'quantity'=>abs((float)$item->get_quantity()),'net'=>(string)abs((float)$item->get_total()),'tax'=>(string)abs((float)$item->get_total_tax())];
            }
            $rows[]=['source_id'=>'woo:refund:'.$refund->get_id(),'amount'=>(string)$refund->get_amount(),'net'=>null,'tax'=>null,
                'shipping_net'=>(string)abs((float)$refund->get_shipping_total()),'shipping_tax'=>(string)abs((float)$refund->get_shipping_tax()),
                'reason'=>(string)$refund->get_reason(),'created'=>$refund->get_date_created()?$refund->get_date_created()->date('c'):'',
                'payment_refunded'=>(bool)$refund->get_refunded_payment(),'lines'=>$lines];
        }
        return self::validate($rows,'woo');
    }
    public static function apply($order,array $data): array
    {
        $origin=explode(':',(string)($data['key']??''))[0];
        $rows=self::validateOrder($data);
        if ($origin!=='ps') { throw new \RuntimeException('Only source PrestaShop refund records may be mirrored.'); }
        $order->update_meta_data('_wd29_bridge_refunds',$rows);
        return $rows;
    }
}
