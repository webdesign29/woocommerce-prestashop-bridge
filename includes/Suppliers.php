<?php
namespace WD29\Bridge;

/** Additional supplier purchasing records, independent of the primary supplier. */
final class Suppliers
{
    public static function validate(array $rows): array
    {
        if (count($rows)>100) { throw new \RuntimeException('Too many supplier records.'); }
        $seen=[]; $out=[];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['name']??null) || trim($row['name'])!==$row['name'] || $row['name']==='' || mb_strlen($row['name'])>64 || preg_match('/[<>;={}\x00-\x1f]/u',$row['name'])) { throw new \RuntimeException('Invalid supplier name.'); }
            if (isset($seen[$row['name']])) { throw new \RuntimeException('Duplicate supplier name.'); } $seen[$row['name']]=true;
            if (!is_string($row['reference']??null) || mb_strlen($row['reference'])>64 || preg_match('/[<>;={}\x00-\x1f]/u',$row['reference'])) { throw new \RuntimeException('Invalid supplier reference.'); }
            $price=$row['purchase_price_net']??null;
            if (!is_scalar($price) || !is_numeric($price) || !is_finite((float)$price) || (float)$price<0 || (float)$price>1000000000000) { throw new \RuntimeException('Invalid supplier purchasing price.'); }
            if (!is_string($row['currency']??null) || !preg_match('/^[A-Z]{3}$/D',$row['currency'])) { throw new \RuntimeException('Supplier currency must be an ISO code.'); }
            $out[]=['name'=>$row['name'],'reference'=>$row['reference'],'purchase_price_net'=>number_format((float)$price,6,'.',''),'currency'=>$row['currency']];
        }
        usort($out,function($a,$b){return strcmp($a['name'],$b['name']);});
        return $out;
    }
    public static function export($product): array
    {
        $rows=$product->get_meta('_wd29_suppliers');
        return self::validate(is_array($rows)?$rows:[]);
    }
    public static function apply($product,array $rows): void
    {
        $rows=self::validate($rows); $currencies=get_woocommerce_currencies();
        foreach ($rows as $row) { if (!isset($currencies[$row['currency']])) { throw new \RuntimeException('Unknown supplier currency.'); } }
        // Additive upsert matches PrestaShop: absent suppliers are not implicitly deleted.
        $merged=[];
        foreach (self::export($product) as $row) { $merged[$row['name']]=$row; }
        foreach ($rows as $row) { $merged[$row['name']]=$row; }
        $product->update_meta_data('_wd29_suppliers',self::validate(array_values($merged)));
    }
}
