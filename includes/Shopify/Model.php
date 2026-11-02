<?php
namespace WD29\Bridge\Shopify;

/** Explicit supported field projections; marketing consent and balances are never inferred. */
final class Model
{
    const PRODUCT_FIELDS = 'sourceIdentity:metafield(namespace:"$app:inklura_sync",key:"source_id"){value} id title descriptionHtml status vendor tags isGiftCard requiresSellingPlan options { name values } variants(first:100) { nodes { id title sku barcode price compareAtPrice taxable requiresComponents selectedOptions { name value } inventoryItem { id tracked requiresShipping inventoryLevel(locationId:$location) { quantities(names:["available"]) { name quantity } } } } pageInfo { hasNextPage } }';
    const CUSTOMER_FIELDS = 'sourceIdentity:metafield(namespace:"$app:inklura_sync",key:"source_id"){value} id firstName lastName defaultEmailAddress { emailAddress } defaultPhoneNumber { phoneNumber } defaultAddress { id firstName lastName company address1 address2 city zip countryCodeV2 provinceCode phone }';
    const ORDER_FIELDS = 'id name createdAt cancelledAt displayFinancialStatus displayFulfillmentStatus currencyCode taxesIncluded customer { id } billingAddress { firstName lastName company address1 address2 city zip countryCodeV2 provinceCode phone } shippingAddress { firstName lastName company address1 address2 city zip countryCodeV2 provinceCode phone } email totalPriceSet { shopMoney { amount currencyCode } } totalTaxSet { shopMoney { amount currencyCode } } totalRefundedSet { shopMoney { amount currencyCode } } shippingLines(first:20) { nodes { title discountedPriceSet { shopMoney { amount currencyCode } } taxLines { rate title priceSet { shopMoney { amount currencyCode } } } } pageInfo { hasNextPage } } lineItems(first:100) { nodes { id name quantity variant { id product { id } } discountedTotalSet { shopMoney { amount currencyCode } } taxLines { rate title priceSet { shopMoney { amount currencyCode } } } } pageInfo { hasNextPage } }';

    public static function hash(array $data): string { return \WD29\Bridge\Protocol::fingerprint($data); }
    public static function money($value): string
    {
        if (!is_string($value) || !preg_match('/^[0-9]{1,12}(?:\.[0-9]{1,6})?$/D', $value)) { throw new Failure('invalid_money'); }
        return rtrim(rtrim(number_format((float)$value, 6, '.', ''), '0'), '.') ?: '0';
    }
    public static function address(?array $row): array
    {
        $row = $row ?? []; $out = [];
        foreach (['firstName'=>'first_name','lastName'=>'last_name','company'=>'company','address1'=>'address_1','address2'=>'address_2','city'=>'city','zip'=>'postcode','countryCodeV2'=>'country','provinceCode'=>'state','phone'=>'phone'] as $from=>$to) { $out[$to] = (string)($row[$from] ?? ''); }
        return $out;
    }
    public static function customer(array $row): array
    {
        return ['first_name'=>(string)($row['firstName'] ?? ''), 'last_name'=>(string)($row['lastName'] ?? ''),
            'email'=>(string)($row['defaultEmailAddress']['emailAddress'] ?? ''), 'phone'=>(string)($row['defaultPhoneNumber']['phoneNumber'] ?? ''),
            'billing'=>self::address($row['defaultAddress'] ?? null)];
    }
    public static function addressHash(array $billing): string
    {
        unset($billing['phone'],$billing['first_name'],$billing['last_name']); return self::hash($billing);
    }
    public static function fullAddressHash(array $billing):string
    {
        // Woo's billing phone is already the shared primary customer phone.
        unset($billing['phone']);return self::hash($billing);
    }
    public static function addressInput(array $billing):array
    {
        if (!preg_match('/^[A-Z]{2}$/D',$billing['country'] ?? '') || trim($billing['address_1'] ?? '')==='') { throw new Failure('customer_address_incomplete'); }
        $out=[];
        foreach (['firstName'=>'first_name','lastName'=>'last_name','company'=>'company','address1'=>'address_1','address2'=>'address_2','city'=>'city','zip'=>'postcode','countryCode'=>'country','provinceCode'=>'state','phone'=>'phone'] as $to=>$from) { $out[$to]=(string)($billing[$from]??''); }
        return $out;
    }
    public static function tags(array $tags):array
    {
        $out=[];foreach($tags as $tag){if(!is_string($tag)||trim($tag)===''||strlen($tag)>255)throw new Failure('invalid_product_tag');$out[]=trim($tag);}
        $out=array_values(array_unique($out));sort($out,SORT_STRING);return $out;
    }
    public static function imageUrl(string $url):string
    {
        $parts=parse_url($url);
        if (!$parts || ($parts['scheme']??'')!=='https' || strtolower($parts['host']??'')!=='cdn.shopify.com' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || strpos($parts['path']??'','/s/files/')!==0) { throw new Failure('unsupported_shopify_image_host'); }
        return $url;
    }
    public static function product(array $row): array
    {
        if(!empty($row['isGiftCard']))throw new Failure('gift_card_product_needs_authority_review');
        if(!empty($row['requiresSellingPlan']))throw new Failure('subscription_product_needs_review');
        if (!empty($row['variants']['pageInfo']['hasNextPage'])) { throw new Failure('product_exceeds_100_variants'); }
        if (count($row['options'] ?? []) > 3) { throw new Failure('unsupported_product_options'); }
        $variants = [];
        foreach ($row['variants']['nodes'] ?? [] as $v) {
            if(!empty($v['requiresComponents']))throw new Failure('bundle_product_needs_review');
            Client::gid('ProductVariant', $v['id']); $attrs = [];
            foreach ($v['selectedOptions'] ?? [] as $option) { $attrs[(string)$option['name']] = (string)$option['value']; }
            $variants[] = ['id'=>$v['id'], 'sku'=>(string)($v['sku'] ?? ''), 'barcode'=>(string)($v['barcode'] ?? ''),
                'price'=>self::money($v['price']), 'compare_at'=>$v['compareAtPrice'] === null ? '' : self::money($v['compareAtPrice']), 'attributes'=>$attrs];
        }
        if (!$variants) { throw new Failure('product_without_variants'); }
        $taxFlags=array_unique(array_map(static function($v){return !empty($v['taxable'])?1:0;},$row['variants']['nodes']));
        if(count($taxFlags)>1)throw new Failure('mixed_variant_taxability_needs_review');
        return ['title'=>(string)$row['title'], 'description'=>(string)$row['descriptionHtml'], 'status'=>(string)$row['status'],
            'variants'=>$variants];
    }
    public static function quantity(array $variant): ?int
    {
        if (empty($variant['inventoryItem']['tracked'])) { return null; }
        $level = $variant['inventoryItem']['inventoryLevel'] ?? null;
        if (!$level) { throw new Failure('inventory_not_at_selected_location'); }
        foreach ($level['quantities'] ?? [] as $quantity) {
            if ($quantity['name'] === 'available') { return \WD29\Bridge\Protocol::quantity($quantity['quantity']); }
        }
        throw new Failure('inventory_quantity_missing');
    }
    /** Both shops changing the same balance requires review, never a guessed sum. */
    public static function stockPlan(?int $base, ?int $local, ?int $remote): string
    {
        if ($local === $remote) { return 'same'; }
        if ($base === null || $local === null || $remote === null) { throw new Failure('inventory_management_changed'); }
        if ($local !== $base && $remote !== $base) { throw new Failure('inventory_concurrent_changes'); }
        return $local === $base ? 'pull' : 'push';
    }
    public static function direction(string $localHash, string $remoteHash, array $map): string
    {
        $localChanged = $localHash !== $map['local_hash']; $remoteChanged = $remoteHash !== $map['remote_hash'];
        if ($localChanged && $remoteChanged) { throw new Failure('profile_concurrent_changes'); }
        return $localChanged ? 'push' : ($remoteChanged ? 'pull' : 'same');
    }
    public static function verifyWebhook(string $body, array $headers, array $config): array
    {
        $secret = (string)($config['client_secret'] ?? '');
        $signature = base64_decode((string)($headers['hmac'] ?? ''), true);
        if (strlen($body) > 4194304 || strlen($secret) < 16 || $signature === false || strlen($signature) !== 32
            || !hash_equals(hash_hmac('sha256', $body, $secret, true), $signature)
            || ($headers['shop'] ?? '') !== Client::shop($config['shop'] ?? '')
            || !preg_match('/^[a-zA-Z0-9-]{16,128}$/D', $headers['id'] ?? '')) { throw new Failure('webhook_authentication_failed'); }
        try { $value = json_decode($body, true, 64, JSON_THROW_ON_ERROR); }
        catch (\Throwable $error) { throw new Failure('webhook_invalid_body'); }
        if (!is_array($value)) { throw new Failure('webhook_invalid_body'); }
        return $value;
    }
}
