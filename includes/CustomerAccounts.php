<?php
namespace WD29\Bridge;
final class CustomerAccounts
{
    public static function apply(Engine $engine,array $data): int
    {
        $suppress=static function() { return true; };
        add_filter('pre_wp_mail',$suppress,PHP_INT_MAX);
        try { return self::applyNative($engine,$data); }
        finally { remove_filter('pre_wp_mail',$suppress,PHP_INT_MAX); }
    }
    private static function applyNative(Engine $engine,array $data): int
    {
        if (!preg_match('/^ps:customer:[1-9][0-9]*$/D',$data['key']) || !empty($data['guest']) || !empty($data['deleted'])) { throw new \RuntimeException('Only active registered source customers can create accounts.'); }
        $link=$engine->sql('SELECT native_id FROM {b}account_links WHERE record_key=?',[$data['key']])[0]??null;
        $id=(int)($link['native_id']??0);
        $email=$data['email']; if (!is_email($email)) { throw new \RuntimeException('Native account requires a valid email.'); }
        $collision=email_exists($email);
        if ($collision && (int)$collision!==$id) { throw new \RuntimeException('Customer email already belongs to another account; manual identity review required.'); }
        if ($id && (!get_userdata($id) || get_user_meta($id,'_wd29_bridge_customer_origin',true)!==$data['key'])) { throw new \RuntimeException('Mapped native account missing or origin changed.'); }
        $customer=$id?new \WC_Customer($id):new \WC_Customer();
        if (!$id) { $customer->set_username('wd29_'.substr(hash('sha256',$data['key']),0,24)); $customer->set_password(wp_generate_password(48,true,true)); $customer->set_role('customer'); }
        $customer->set_email($email); $customer->set_first_name($data['first_name']); $customer->set_last_name($data['last_name']);
        foreach (['billing','shipping'] as $kind) {
            foreach (['first_name','last_name','company','address_1','address_2','city','postcode','country','state','phone'] as $field) {
                $method='set_'.$kind.'_'.$field;
                if (array_key_exists($field,$data[$kind]) && method_exists($customer,$method)) { $customer->$method($data[$kind][$field]); }
            }
        }
        $customer->set_billing_email($email);
        $customer->update_meta_data('_wd29_bridge_customer_origin',$data['key']);
        $customer->save();
        if (isset($data['custom_fields'])) { CustomFields::apply($customer,$data['custom_fields'],'customer'); $customer->save(); }
        $engine->sql('INSERT INTO {b}account_links (record_key,native_id) VALUES (?,?) ON DUPLICATE KEY UPDATE native_id=VALUES(native_id)',[$data['key'],$customer->get_id()]);
        return $customer->get_id();
    }
}
