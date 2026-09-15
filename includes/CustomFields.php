<?php
namespace WD29\Bridge;

/** Explicit product/variation allowlist. Never enumerates site metadata or ACF groups. */
final class CustomFields
{
    public static function rules($rules): array
    {
        if (!is_array($rules) || count($rules)>50) { throw new \RuntimeException('Custom fields must be a JSON list of at most 50 mappings.'); }
        $seen=[]; $targets=[];
        foreach ($rules as $rule) {
            if (!is_array($rule) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$rule['id']??'') || !in_array($rule['source']??'', ['meta','acf'],true) || !preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,127}$/D',$rule['key']??'')) { throw new \RuntimeException('Each mapping needs a unique id, source (meta/acf), and key.'); }
            if (isset($seen[$rule['id']]) || isset($targets[$rule['key']])) { throw new \RuntimeException('Duplicate custom field mapping.'); }
            self::safeKey($rule['key']);
            $seen[$rule['id']]=true; $targets[$rule['key']]=true;
        }
        return array_values($rules);
    }
    private static function safeKey(string $key): void
    {
        $native=array_merge(array_keys((new \WC_Product_Simple())->get_data()),array_keys((new \WC_Product_Variation())->get_data()));
        if (in_array(ltrim($key,'_'),$native,true) || preg_match('/(?:password|secret|token|api.?key|credential|session|capabilit|nonce)|^_(?:wd29|wp|edit|oembed|yoast|acf|product|stock|price|regular|sale|tax|download|virtual|thumbnail|default|variation)/i',$key)) { throw new \RuntimeException('Reserved or sensitive metadata key cannot be synchronized.'); }
    }
    public static function value($value, int $depth=0): void
    {
        if ($depth>8 || is_object($value) || is_resource($value) || (is_float($value) && !is_finite($value))) { throw new \RuntimeException('Custom fields require bounded JSON values.'); }
        if (is_array($value)) {
            if (count($value)>500) { throw new \RuntimeException('Custom field array too large.'); }
            foreach ($value as $v) { self::value($v,$depth+1); }
        }
        if ($depth===0 && strlen((string)json_encode($value,JSON_THROW_ON_ERROR))>65536) { throw new \RuntimeException('Custom field exceeds 64 KiB.'); }
    }
    private static function target(array $rule, int $id): array
    {
        if ($rule['source']==='meta') { return [$rule['key'],null,null]; }
        if (!function_exists('get_field_object')) { throw new \RuntimeException('ACF is required for configured ACF mappings.'); }
        $field=get_field_object($rule['key'],$id,false,false);
        if (!$field || !in_array($field['type'],['text','textarea','number','range','email','url','true_false','select','checkbox','radio','button_group','date_picker','date_time_picker','time_picker','color_picker'],true)) { throw new \RuntimeException('ACF mapping requires an existing supported scalar or choice field.'); }
        self::safeKey($field['name']);
        return [$field['name'],$field['key'],$field];
    }
    private static function acfValue(array $field,$value): void
    {
        $multiple=$field['type']==='checkbox' || ($field['type']==='select' && !empty($field['multiple']));
        if (is_array($value) && !$multiple) { throw new \RuntimeException('ACF scalar field cannot accept an array.'); }
        if (in_array($field['type'],['number','range'],true) && $value!=='' && $value!==null && !is_numeric($value)) { throw new \RuntimeException('ACF number must be numeric.'); }
        if ($field['type']==='true_false' && !in_array($value,[true,false,0,1,'0','1',''],true)) { throw new \RuntimeException('Invalid ACF boolean.'); }
        if (isset($field['choices']) && is_array($field['choices'])) {
            foreach (is_array($value)?$value:[$value] as $choice) {
                if (is_array($choice) || ($choice!==null && $choice!=='' && !array_key_exists((string)$choice,$field['choices']))) { throw new \RuntimeException('Unknown ACF choice.'); }
            }
        }
    }
    public static function export($product): array
    {
        $out=[];
        foreach (self::rules(get_option('wd29_bridge_custom_fields',[])) as $rule) {
            [$key,$acf,$definition]=self::target($rule,$product->get_id());
            $exists=$product->meta_exists($key);
            $value=$exists?$product->get_meta($key,true,'edit'):null;
            self::value($value);
            if ($exists && $definition) { self::acfValue($definition,$value); }
            $out[$rule['id']]=['present'=>$exists,'value'=>$value];
        }
        return $out;
    }
    public static function apply($product,array $fields): void
    {
        foreach (self::rules(get_option('wd29_bridge_custom_fields',[])) as $rule) {
            // Omission means untouched. A tombstone explicitly deletes only this allowed field.
            if (!array_key_exists($rule['id'],$fields)) { continue; }
            $entry=$fields[$rule['id']];
            if (!is_array($entry) || !is_bool($entry['present']??null) || !array_key_exists('value',$entry)) { throw new \RuntimeException('Invalid custom field envelope.'); }
            self::value($entry['value']);
            [$key,$acf,$definition]=self::target($rule,$product->get_id());
            if ($entry['present'] && $definition) { self::acfValue($definition,$entry['value']); }
            if ($entry['present']) {
                $product->update_meta_data($key,$entry['value']);
                // ACF's native reference is local; never transport field keys from another site.
                if ($acf) { $product->update_meta_data('_'.$key,$acf); }
            } else {
                $product->delete_meta_data($key);
                if ($acf) { $product->delete_meta_data('_'.$key); }
            }
        }
    }
}
