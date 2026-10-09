<?php
namespace WD29\Bridge;

/** Read-only protection for the optional native customer copy. Never returns PII. */
final class CustomerRecordGuard
{
    public static function inspect(Engine $engine, string $key, ?array $incoming=null): array
    {
        $result=['state'=>'conflict','native_id'=>0,'reason'=>'identity_invalid'];
        $foreign=$engine->adapter->site()==='woo'?'ps':'woo';
        if (!preg_match('/^'.$foreign.':(?:customer|guest):[1-9][0-9]{0,14}$/D',$key)) { return $result; }
        try {
            $links=$engine->sql('SELECT native_id FROM {b}account_links WHERE record_key=? LIMIT 2',[$key]);
            $result['native_id']=(int)($links[0]['native_id']??0);
            if (empty($engine->config()['native_customers'])) { return array_merge($result,['state'=>'directory_only','reason'=>'native_customers_disabled']); }
            if (strpos($key,':guest:')!==false) { return array_merge($result,['state'=>$links?'conflict':'directory_only','reason'=>$links?'account_link_invalid':'guest_contact_only']); }
            if (!$links) { return array_merge($result,['state'=>'not_linked','reason'=>'native_account_not_created']); }
            if (count($links)!==1 || $result['native_id']<1) { return array_merge($result,['reason'=>'account_link_invalid']); }
            $id=$result['native_id'];
            $owners=$engine->sql('SELECT record_key FROM {b}account_links WHERE native_id=? LIMIT 2',[$id]);
            if (count($owners)!==1 || $owners[0]['record_key']!==$key) { return array_merge($result,['reason'=>'account_link_ambiguous']); }
            $user=get_userdata($id);
            if (!$user) { return array_merge($result,['reason'=>'native_account_missing']); }
            // An imported customer must never grow into a privileged/mixed-role account unnoticed.
            if (array_values((array)$user->roles)!==['customer'] || user_can($user,'manage_options') || user_can($user,'manage_woocommerce') || user_can($user,'edit_users')) { return array_merge($result,['reason'=>'native_account_role_changed']); }
            if (get_user_meta($id,'_wd29_bridge_customer_origin',true)!==$key) { return array_merge($result,['reason'=>'native_account_origin_changed']); }
            $rows=$engine->sql('SELECT data FROM {b}contacts WHERE record_key=? LIMIT 2',[$key]);
            $data=count($rows)===1?json_decode($rows[0]['data'],true):null;
            if (!is_array($data) || ($data['key']??'')!==$key || !empty($data['guest']) || !empty($data['deleted']) || !is_array($data['billing']??null) || !is_array($data['shipping']??null)) { return array_merge($result,['reason'=>'contact_baseline_missing']); }
            if ($incoming!==null && (($incoming['key']??'')!==$key || !is_array($incoming['billing']??null) || !is_array($incoming['shipping']??null))) { return array_merge($result,['reason'=>'incoming_contact_invalid']); }
            $actual=new \WC_Customer($id);
            // Use native setters on an unsaved object for the same normalization as the importer.
            $expected=new \WC_Customer();
            foreach (['email','first_name','last_name'] as $field) {
                if (!is_string($data[$field]??null)) { return array_merge($result,['reason'=>'contact_baseline_invalid']); }
                $set='set_'.$field; $get='get_'.$field; $expected->$set($data[$field]);
                if ((string)$actual->$get('edit')!==(string)$expected->$get('edit')) { return array_merge($result,['reason'=>'native_profile_changed']); }
            }
            foreach (['billing','shipping'] as $kind) {
                foreach (['first_name','last_name','company','address_1','address_2','city','postcode','country','state','phone'] as $field) {
                    $set='set_'.$kind.'_'.$field; $get='get_'.$kind.'_'.$field;
                    if (!array_key_exists($field,$data[$kind]) || !method_exists($expected,$set)) { continue; }
                    $expected->$set($data[$kind][$field]);
                    if ((string)$actual->$get('edit')!==(string)$expected->$get('edit')) { return array_merge($result,['reason'=>'native_address_changed']); }
                }
            }
            if ($incoming!==null) {
                foreach (['billing','shipping'] as $kind) {
                    foreach (['first_name','last_name','company','address_1','address_2','city','postcode','country','state','phone'] as $field) {
                        $set='set_'.$kind.'_'.$field; $get='get_'.$kind.'_'.$field;
                        if (array_key_exists($field,$data[$kind]) || !array_key_exists($field,$incoming[$kind]) || !method_exists($expected,$set)) { continue; }
                        $expected->$set($incoming[$kind][$field]); $current=(string)$actual->$get('edit');
                        if ($current!=='' && $current!==(string)$expected->$get('edit')) { return array_merge($result,['reason'=>'native_new_address_field_conflict']); }
                    }
                }
            }
            $expected->set_billing_email($data['email']);
            if ((string)$actual->get_billing_email('edit')!==(string)$expected->get_billing_email('edit')) { return array_merge($result,['reason'=>'native_address_changed']); }
            if (array_key_exists('custom_fields',$data) || ($incoming!==null && array_key_exists('custom_fields',$incoming))) {
                $baselineFields=$data['custom_fields']??[]; $incomingFields=$incoming['custom_fields']??[];
                if (!is_array($baselineFields) || !is_array($incomingFields)) { return array_merge($result,['reason'=>'contact_baseline_invalid']); }
                $fields=CustomFields::exportCustomer($id);
                foreach (CustomFields::rules(get_option('wd29_bridge_custom_fields',[])) as $rule) {
                    if (!in_array('customer',$rule['entities'],true)) { continue; }
                    $field=$rule['id']; $current=$fields[$field]??null;
                    if (array_key_exists($field,$baselineFields)) {
                        if (!self::sameCustomField($baselineFields[$field],$current,$rule['source'])) { return array_merge($result,['reason'=>'native_custom_fields_changed']); }
                    } elseif (array_key_exists($field,$incomingFields)) {
                        // A new mapping may encounter an existing local value; never silently replace it.
                        if (!is_array($current) || !is_bool($current['present']??null) || (!empty($current['present']) && !self::sameCustomField($incomingFields[$field],$current,$rule['source']))) { return array_merge($result,['reason'=>'native_new_custom_field_conflict']); }
                    }
                }
            }
            return array_merge($result,['state'=>'linked','reason'=>'native_account_unchanged']);
        } catch (\Throwable $error) { return array_merge($result,['state'=>'conflict','reason'=>'native_account_unavailable']); }
    }
    private static function sameCustomField($expected,$actual,string $source): bool
    {
        if (!is_array($expected) || !is_array($actual) || !is_bool($expected['present']??null) || $expected['present']!==($actual['present']??null)) { return false; }
        if (!$expected['present']) { return true; }
        $a=$expected['value']??null; $b=$actual['value']??null;
        // WordPress stores scalar non-ACF metadata as strings; serialized arrays retain types.
        if ($source==='meta' && (is_scalar($a) || $a===null) && (is_scalar($b) || $b===null)) { $a=(string)$a; $b=(string)$b; }
        return Protocol::fingerprint(['value'=>$a])===Protocol::fingerprint(['value'=>$b]);
    }

}
