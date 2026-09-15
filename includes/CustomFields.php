<?php
namespace WD29\Bridge;

/** Explicit allowlist. Never enumerates site metadata or ACF groups. */
final class CustomFields
{
    public static function rules($rules): array
    {
        if (!is_array($rules) || count($rules)>50) { throw new \RuntimeException('Custom fields must be a JSON list of at most 50 mappings.'); }
        $seen=[]; $targets=[];
        foreach ($rules as &$rule) {
            if (!is_array($rule) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$rule['id']??'') || !in_array($rule['source']??'', ['meta','acf'],true) || !preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,127}$/D',$rule['key']??'')) { throw new \RuntimeException('Each mapping needs a unique id, source (meta/acf), and key.'); }
            $rule['entities']=$rule['entities']??['product','variation'];
            if (!is_array($rule['entities']) || !$rule['entities'] || count(array_unique($rule['entities'],SORT_REGULAR))!==count($rule['entities'])) { throw new \RuntimeException('Custom field entities must be a nonempty unique list.'); }
            if (isset($seen[$rule['id']])) { throw new \RuntimeException('Duplicate custom field mapping id.'); }
            foreach ($rule['entities'] as $entity) {
                if (!in_array($entity,['product','variation','order','customer'],true)) { throw new \RuntimeException('Unsupported custom field entity.'); }
                if ($entity==='order' && $rule['source']==='acf') { throw new \RuntimeException('ACF order fields require a verified HPOS-compatible ACF adapter; use explicit Woo order meta mappings.'); }
                if (isset($targets[$entity.':'.$rule['key']])) { throw new \RuntimeException('Duplicate custom field target.'); }
                self::safeKey($rule['key'],$entity);
                $targets[$entity.':'.$rule['key']]=true;
            }
            $seen[$rule['id']]=true;
        }
        unset($rule);
        return array_values($rules);
    }
    private static function safeKey(string $key,string $entity): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,127}$/D',$key)) { throw new \RuntimeException('Invalid custom field key.'); }
        $class=['product'=>'WC_Product_Simple','variation'=>'WC_Product_Variation','order'=>'WC_Order','customer'=>'WC_Customer'][$entity];
        $native=array_keys((new $class())->get_data());
        if (in_array(ltrim($key,'_'),$native,true) || preg_match('/(?:password|secret|token|api.?key|credential|session|capabilit|nonce|user_level)|^_?(?:wd29|wp_|edit_|oembed|yoast|acf_|product_|stock|price|regular_price|sale_price|tax_|download|virtual|thumbnail|default_|variation|billing_|shipping_|payment_|transaction_|order_|customer_|user_|last_update|paying_customer)/i',$key)) { throw new \RuntimeException('Reserved or sensitive metadata key cannot be synchronized.'); }
    }
    public static function value($value,int $depth=0): void
    {
        if ($depth>8 || is_object($value) || is_resource($value) || (is_float($value) && !is_finite($value))) { throw new \RuntimeException('Custom fields require bounded JSON values.'); }
        if (is_array($value)) {
            if (count($value)>500) { throw new \RuntimeException('Custom field array too large.'); }
            foreach ($value as $v) { self::value($v,$depth+1); }
        }
        if ($depth===0 && strlen((string)json_encode($value,JSON_THROW_ON_ERROR))>65536) { throw new \RuntimeException('Custom field exceeds 64 KiB.'); }
    }
    private static function entity($object,?string $entity): string
    {
        if ($entity===null) {
            $entity=$object instanceof \WC_Order?'order':($object instanceof \WC_Customer?'customer':($object instanceof \WC_Product_Variation?'variation':'product'));
        }
        $class=['product'=>'WC_Product','variation'=>'WC_Product_Variation','order'=>'WC_Order','customer'=>'WC_Customer'][$entity]??null;
        if (!$class || !($object instanceof $class)) { throw new \RuntimeException('Custom field entity does not match its WooCommerce object.'); }
        return $entity;
    }
    private static function context($object,string $entity)
    {
        if (!$object->get_id()) { throw new \RuntimeException('Save the native object before applying ACF fields.'); }
        return $entity==='customer'?'user_'.$object->get_id():$object->get_id();
    }
    private static function target(array $rule,$object,string $entity): ?array
    {
        if ($rule['source']==='meta') { return null; }
        if (!function_exists('get_field_object') || !function_exists('get_field') || !function_exists('update_field') || !function_exists('delete_field')) { throw new \RuntimeException('ACF is required for configured ACF mappings.'); }
        $field=get_field_object($rule['key'],self::context($object,$entity),false,false);
        if (!$field) { throw new \RuntimeException('ACF mapping requires an existing local field definition.'); }
        self::schema($field,$entity);
        return $field;
    }
    private static function schema(array $field,string $entity,int $depth=0): void
    {
        if ($depth>6 || !isset($field['name'],$field['key'],$field['type'])) { throw new \RuntimeException('Invalid or excessively nested ACF schema.'); }
        self::safeKey($field['name'],$entity);
        if (function_exists('acf_get_field_type') && !acf_get_field_type($field['type'])) { throw new \RuntimeException('ACF field type is not installed: '.$field['type'].'. Repeater support requires ACF PRO.'); }
        if (in_array($field['type'],['group','repeater'],true)) {
            if (empty($field['sub_fields']) || count($field['sub_fields'])>100) { throw new \RuntimeException('ACF group/repeater requires bounded local subfields.'); }
            $names=[];
            foreach ($field['sub_fields'] as $sub) {
                self::schema($sub,$entity,$depth+1);
                if (isset($names[$sub['name']])) { throw new \RuntimeException('Duplicate ACF subfield name.'); }
                $names[$sub['name']]=true;
            }
        } elseif (in_array($field['type'],['image','gallery','relationship','post_object'],true)) {
            if (!in_array($entity,['product','variation'],true)) { throw new \RuntimeException('ACF identity fields currently require product or variation scope.'); }
        } elseif (!in_array($field['type'],['text','textarea','number','range','email','url','true_false','select','checkbox','radio','button_group','date_picker','date_time_picker','time_picker','color_picker'],true)) {
            throw new \RuntimeException('Unsupported ACF type: '.$field['type'].'. Media, relationship and other local IDs require an explicit identity adapter.');
        }
    }
    /** Normalize raw ACF local keys to portable names, or portable names to local keys. */
    private static function acfValue(array $field,$value,bool $toLocal=false,array $resolvers=[])
    {
        if ($field['type']==='repeater') {
            if ($value===false || $value===null || $value==='') { return []; }
            if (!is_array($value) || array_values($value)!==$value) { throw new \RuntimeException('ACF repeater must contain a list of rows.'); }
            if (count($value)<(int)($field['min']??0) || (!empty($field['max']) && count($value)>(int)$field['max'])) { throw new \RuntimeException('ACF repeater row count is outside local limits.'); }
            $group=$field; $group['type']='group';
            return array_map(static function($row) use ($group,$toLocal,$resolvers) { return self::acfValue($group,$row,$toLocal,$resolvers); },$value);
        }
        if ($field['type']==='group') {
            if (!is_array($value)) { throw new \RuntimeException('ACF group must be an object of configured subfields.'); }
            $out=[]; $allowed=[];
            foreach ($field['sub_fields'] as $sub) {
                $name=$sub['name']; $key=$sub['key'];
                $allowed[$name]=true;
                if (!$toLocal) { $allowed[$key]=true; }
                $input=array_key_exists($name,$value)?$name:(!$toLocal && array_key_exists($key,$value)?$key:null);
                if ($input===null) { throw new \RuntimeException('Missing ACF subfield '.$name.'. Send a complete group/row.'); }
                if (!$toLocal && $name!==$key && array_key_exists($name,$value) && array_key_exists($key,$value)) { throw new \RuntimeException('Ambiguous ACF subfield.'); }
                $out[$toLocal?$key:$name]=self::acfValue($sub,$value[$input],$toLocal,$resolvers);
            }
            foreach ($value as $key=>$unused) { if (!isset($allowed[$key])) { throw new \RuntimeException('Unknown ACF subfield.'); } }
            return $out;
        }
        if (in_array($field['type'],['image','gallery','relationship','post_object'],true)) { return self::referenceValue($field,$value,$toLocal,$resolvers); }
        $multiple=$field['type']==='checkbox' || ($field['type']==='select' && !empty($field['multiple']));
        if (is_array($value) && !$multiple) { throw new \RuntimeException('ACF scalar field cannot accept an array.'); }
        if ($multiple && is_array($value) && array_values($value)!==$value) { throw new \RuntimeException('ACF multiple choice requires a list.'); }
        if (in_array($field['type'],['number','range'],true) && $value!=='' && $value!==null && !is_numeric($value)) { throw new \RuntimeException('ACF number must be numeric.'); }
        if ($field['type']==='true_false' && !in_array($value,[true,false,0,1,'0','1',''],true)) { throw new \RuntimeException('Invalid ACF boolean.'); }
        if (isset($field['choices']) && is_array($field['choices'])) {
            foreach (is_array($value)?$value:[$value] as $choice) {
                if (is_array($choice) || ($choice!==null && $choice!=='' && $choice!==false && !array_key_exists((string)$choice,$field['choices']))) { throw new \RuntimeException('Unknown ACF choice.'); }
            }
        }
        return $value;
    }
    private static function referenceValue(array $field,$value,bool $toLocal,array $resolvers)
    {
        $multiple=in_array($field['type'],['gallery','relationship'],true) || ($field['type']==='post_object' && !empty($field['multiple']));
        if (in_array($value,[false,null,'',0,'0'],true)) { return $multiple?[]:null; }
        if ($multiple && (!is_array($value) || array_values($value)!==$value)) { throw new \RuntimeException('ACF reference list must be an array of identities.'); }
        $out=[];
        foreach ($multiple?$value:[$value] as $reference) {
            if (in_array($field['type'],['image','gallery'],true)) {
                if ($toLocal) {
                    self::imageUrl($reference,$resolvers['peerHost']??null);
                    $id=0;
                    if (strtolower((string)parse_url($reference,PHP_URL_HOST))===strtolower((string)parse_url(home_url(),PHP_URL_HOST))) { $id=(int)attachment_url_to_postid($reference); }
                    if (!$id && is_callable($resolvers['imageId']??null)) { $id=(int)$resolvers['imageId']($reference); }
                    if (!$id || !wp_attachment_is_image($id)) { throw new \RuntimeException('ACF image has no resolved local image attachment.'); }
                    $out[]=$id;
                } else {
                    if ((!is_int($reference) && !(is_string($reference) && ctype_digit($reference))) || !wp_attachment_is_image((int)$reference)) { throw new \RuntimeException('ACF image must reference an existing image attachment.'); }
                    $url=wp_get_attachment_url((int)$reference);
                    self::imageUrl($url);
                    $out[]=$url;
                }
            } else {
                if ($toLocal) {
                    if (!is_string($reference) || !preg_match('/^(woo|ps):product:[1-9][0-9]*$/D',$reference) || !is_callable($resolvers['productId']??null)) { throw new \RuntimeException('ACF product reference requires a canonical product key and identity resolver.'); }
                    $id=(int)$resolvers['productId']($reference);
                    $product=$id?wc_get_product($id):false;
                    if (!$product || $product instanceof \WC_Product_Variation) { throw new \RuntimeException('ACF related product is not synchronized locally yet.'); }
                    $out[]=$id;
                } else {
                    if (!is_int($reference) && !(is_string($reference) && ctype_digit($reference))) { throw new \RuntimeException('ACF related product must have a local product ID.'); }
                    $product=wc_get_product((int)$reference);
                    if (!$product || $product instanceof \WC_Product_Variation || !is_callable($resolvers['productKey']??null)) { throw new \RuntimeException('ACF relationships support synchronized catalog products only.'); }
                    $key=$resolvers['productKey']((int)$reference);
                    if (!is_string($key) || !preg_match('/^(woo|ps):product:[1-9][0-9]*$/D',$key)) { throw new \RuntimeException('ACF related product has no canonical synchronized identity.'); }
                    $out[]=$key;
                }
            }
        }
        return $multiple?$out:$out[0];
    }
    private static function imageUrl($url,?string $peerHost=null): void
    {
        $parts=is_string($url)?parse_url($url):false;
        $hosts=[strtolower((string)parse_url(home_url(),PHP_URL_HOST))];
        if ($peerHost!==null && $peerHost!=='') { $hosts[]=strtolower($peerHost); }
        if (!$parts || ($parts['scheme']??'')!=='https' || !in_array(strtolower($parts['host']??''),$hosts,true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port']!==443)) { throw new \RuntimeException('ACF images require an HTTPS URL on the WooCommerce or configured peer host.'); }
    }
    public static function export($object,?string $entity=null,array $resolvers=[]): array
    {
        $entity=self::entity($object,$entity); $out=[];
        foreach (self::rules(get_option('wd29_bridge_custom_fields',[])) as $rule) {
            if (!in_array($entity,$rule['entities'],true)) { continue; }
            $field=self::target($rule,$object,$entity); $key=$field['name']??$rule['key'];
            $exists=$field?metadata_exists($entity==='customer'?'user':'post',$object->get_id(),$key):$object->meta_exists($key);
            $value=$exists?($field?get_field($field['key'],self::context($object,$entity),false):$object->get_meta($key,true,'edit')):null;
            if ($exists && $field) { $value=self::acfValue($field,$value,false,$resolvers); }
            self::value($value);
            $out[$rule['id']]=['present'=>$exists,'value'=>$value];
        }
        return $out;
    }
    public static function apply($object,array $fields,?string $entity=null,array $resolvers=[]): void
    {
        $entity=self::entity($object,$entity); $pending=[];
        foreach (self::rules(get_option('wd29_bridge_custom_fields',[])) as $rule) {
            if (!in_array($entity,$rule['entities'],true) || !array_key_exists($rule['id'],$fields)) { continue; }
            $entry=$fields[$rule['id']];
            if (!is_array($entry) || !is_bool($entry['present']??null) || !array_key_exists('value',$entry)) { throw new \RuntimeException('Invalid custom field envelope.'); }
            self::value($entry['value']);
            $field=self::target($rule,$object,$entity);
            $value=$entry['value'];
            if ($entry['present'] && $field) { $value=self::acfValue($field,$value,true,$resolvers); }
            $pending[]=[$rule,$field,$entry['present'],$value];
        }
        // Validate the entire envelope before any write. Native callers save CRUD meta themselves.
        foreach ($pending as [$rule,$field,$present,$value]) {
            if ($field) {
                $context=self::context($object,$entity);
                if ($present) { update_field($field['key'],$value,$context); }
                else { delete_field($field['key'],$context); }
            } elseif ($present) { $object->update_meta_data($rule['key'],$value); }
            else { $object->delete_meta_data($rule['key']); }
        }
    }
    public static function exportCustomer(int $id): array
    {
        if ($id<1 || !get_userdata($id)) { throw new \RuntimeException('Customer metadata requires an existing registered customer.'); }
        return self::export(new \WC_Customer($id),'customer');
    }
    public static function applyCustomer(int $id,array $fields): void
    {
        if ($id<1 || !get_userdata($id)) { throw new \RuntimeException('Customer metadata requires an existing registered customer.'); }
        $customer=new \WC_Customer($id); self::apply($customer,$fields,'customer'); $customer->save_meta_data();
    }
}
