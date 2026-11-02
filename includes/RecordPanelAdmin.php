<?php
namespace WD29\Bridge;
require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/AdminInput.php';

/** Native editor integration. Rendering never contacts the partner. */
final class RecordPanelAdmin
{
    private static $pluginFile = '';
    public static function register(string $pluginFile): void
    {
        self::$pluginFile = $pluginFile;
        add_action('add_meta_boxes_product', [self::class, 'productBox']);
        add_action('add_meta_boxes', [self::class, 'orderBox']);
        add_action('edit_user_profile', [self::class, 'customerProfile']);
        add_action('show_user_profile', [self::class, 'customerProfile']);
        add_action('woocommerce_product_after_variable_attributes', [self::class, 'variationNote'], 20, 3);
        add_action('wp_ajax_wd29_record_tools', [self::class, 'ajax']);
        add_action('admin_post_wd29_open_record', [self::class, 'openRecord']);
        add_action('admin_post_nopriv_wd29_open_record', [self::class, 'loginForRecord']);
    }
    public static function loginForRecord(): void
    {
        if (AdminInput::text($_SERVER, 'REQUEST_METHOD') !== 'GET') { wp_die(\WD29\Bridge\I18n::t('Method not allowed.'), '', ['response'=>405]); return; }
        // The native login flow retains the requested URL; no record is resolved before login.
        auth_redirect();
    }
    public static function openRecord(): void
    {
        if (AdminInput::text($_SERVER, 'REQUEST_METHOD') !== 'GET') { wp_die(\WD29\Bridge\I18n::t('Method not allowed.'), '', ['response'=>405]); return; }
        if (!is_user_logged_in()) { auth_redirect(); return; }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only native editor navigation checks login and per-record capabilities before resolving/redirecting.
        $kind = AdminInput::text($_GET, 'kind', null, 16); $key = AdminInput::text($_GET, 'key', null, 96);
        if (!is_string($kind) || !in_array($kind, ['product','order','customer'], true) || !is_string($key) || strlen($key)>96) { wp_die('Fiche indisponible.', '', ['response'=>404]); return; }
        try { $id = RecordPanel::nativeId(\wd29_bridge(), $kind, $key); }
        catch (\Throwable $error) { $id = 0; }
        if ($id < 1 || !self::allowed($kind,$id)) { wp_die(\WD29\Bridge\I18n::t('Record unavailable or access denied.'), '', ['response'=>403]); return; }
        if ($kind === 'product') { $url = get_edit_post_link($id, 'raw'); }
        elseif ($kind === 'order') { $order=wc_get_order($id); $url=$order ? $order->get_edit_order_url() : ''; }
        else { $url = get_edit_user_link($id); }
        if (!is_string($url) || $url === '') { wp_die('Fiche indisponible.', '', ['response'=>404]); return; }
        wp_safe_redirect($url);
        exit;
    }
    public static function allowed(string $kind, int $id): bool
    {
        if ($id < 1) { return false; }
        if ($kind === 'product') { return get_post_type($id) === 'product' && current_user_can('edit_post', $id); }
        if ($kind === 'order') {
            if (!current_user_can('edit_shop_order', $id)) { return false; }
            $order = wc_get_order($id);
            return $order && $order->get_type() === 'shop_order';
        }
        if ($kind === 'customer') {
            if (!current_user_can('list_users') || !current_user_can('edit_user', $id)) { return false; }
            $user = get_userdata($id);
            return $user && in_array('customer', (array) $user->roles, true);
        }
        return false;
    }
    public static function productBox($post): void
    {
        if (self::allowed('product', (int) ($post->ID ?? 0))) { add_meta_box('wd29-product-link', 'Inklura Sync', [self::class, 'product'], 'product', 'side', 'default'); }
    }
    public static function product($post): void { self::render('product', (int) ($post->ID ?? 0)); }
    public static function orderBox(): void
    {
        $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'woocommerce_page_wc-orders';
        foreach (array_unique(['shop_order', $screen]) as $id) { add_meta_box('wd29-order-sync', 'Inklura Sync', [self::class, 'order'], $id, 'side', 'default'); }
    }
    public static function order($object): void
    {
        $id = is_object($object) && method_exists($object, 'get_id') ? (int) $object->get_id() : (int) ($object->ID ?? 0);
        self::render('order', $id);
    }
    public static function customerProfile($user): void
    {
        $id = (int) ($user->ID ?? 0);
        if (!self::allowed('customer', $id)) { return; }
        echo '<h2>Inklura Sync</h2><div style="max-width:760px">'; self::render('customer', $id); echo '</div>';
    }
    public static function variationNote($loop, $data, $variation): void
    {
        $id = (int) ($variation->post_parent ?? 0);
        if (!self::allowed('product', $id)) { return; }
        echo \WD29\Bridge\I18n::t('<p class="form-row form-row-full wd29-record-description">Inklura Sync: save your variations, then <a href="#wd29-product-link">compare the product and its variations</a> in the Inklura Sync panel.</p>');
    }
    public static function render(string $kind, int $id): void
    {
        if (!self::allowed($kind, $id)) { return; }
        try { $local = RecordPanel::local(\wd29_bridge(), $kind, $id); }
        catch (\Throwable $error) { $local = ['state'=>'unavailable','reason'=>\WD29\Bridge\I18n::t('This record cannot be compared right now.')]; }
        if ($kind === 'product') { ProductLinksAdmin::render(get_post($id)); echo '<hr>'; }
        wp_enqueue_script('wd29-record-panel', plugins_url('includes/record-panel.js', self::$pluginFile), [], defined('WD29_BRIDGE_VERSION') ? WD29_BRIDGE_VERSION : '1', true);
        echo '<div data-wd29-record-panel data-kind="'.esc_attr($kind).'" data-id="'.esc_attr((string)$id).'" data-state="'.esc_attr((string)($local['state']??'unavailable')).'" data-endpoint="'.esc_url(admin_url('admin-ajax.php')).'" data-nonce="'.esc_attr(wp_create_nonce('wd29_record_tools_'.$kind.'_'.$id)).'">';
        echo \WD29\Bridge\I18n::t('<p><strong>Comparison and synchronization</strong></p><p class="wd29-record-description" style="font-size:13px;color:#646970">Save your changes before comparing. Only saved data is synchronized').($kind==='product'?\WD29\Bridge\I18n::t(', including variations'):'').'.</p>';
        echo '<p data-record-status role="status" aria-live="polite">'.esc_html((string)($local['reason']??\WD29\Bridge\I18n::t('Reading synchronization status…'))).'</p>';
        echo \WD29\Bridge\I18n::t('<div data-record-comparison hidden style="display:none"><p><strong data-record-direction></strong></p><p data-record-origin class="wd29-record-description" style="font-size:13px;color:#646970"></p><dl style="margin:12px 0"><dt><strong>WooCommerce</strong></dt><dd data-record-woo style="margin:4px 0 10px"></dd><dt><strong>PrestaShop</strong></dt><dd data-record-ps style="margin:4px 0 10px"></dd></dl><p><a class="button" data-record-admin-link hidden style="display:none;max-width:100%;white-space:normal;line-height:1.5;padding-top:4px;padding-bottom:4px" target="_blank" rel="noopener noreferrer">Remote administration ↗</a><br><small data-record-admin-note hidden style="display:none">Sign-in required on the partner store.</small></p><p data-record-changes></p><p data-record-variations class="wd29-record-description" style="font-size:13px;color:#646970"></p><details data-record-variation-details hidden style="display:none"><summary>Affected variations</summary><ul data-record-variation-rows></ul></details><p data-record-customer class="wd29-record-description" style="font-size:13px;color:#646970"></p><p data-record-native class="wd29-record-description" style="font-size:13px;color:#646970"></p><p data-record-stock class="wd29-record-description" style="font-size:13px;color:#646970"></p><p data-record-events class="wd29-record-description" style="font-size:13px;color:#646970"></p></div>');
        echo \WD29\Bridge\I18n::t('<p><button type="button" class="button" data-record-refresh>Compare</button> <button type="button" class="button button-primary" data-record-sync hidden style="display:none">Synchronize</button></p><noscript><p>Enable JavaScript to compare this record.</p></noscript></div>');
    }
    public static function ajax(): void
    {
        nocache_headers();
        if (AdminInput::text($_SERVER, 'REQUEST_METHOD') !== 'POST') { wp_send_json(['ok'=>false,'error'=>\WD29\Bridge\I18n::t('Method not allowed.')],405); return; }
        // phpcs:ignore WordPress.Security.NonceVerification -- Typed selectors are read to determine the per-record nonce action; verification below precedes all dispatch.
        $kind = AdminInput::text($_POST, 'kind', null, 16); $raw = AdminInput::text($_POST, 'id', null, 10); $op = AdminInput::text($_POST, 'op', null, 16);
        if (!is_string($kind) || !in_array($kind,['product','order','customer'],true) || !is_scalar($raw) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$raw) || !in_array($op,['compare','sync'],true)) { wp_send_json(['ok'=>false,'error'=>\WD29\Bridge\I18n::t('Invalid request.')],400); return; }
        $id = (int)$raw;
        if (!self::allowed($kind,$id)) { wp_send_json(['ok'=>false,'error'=>\WD29\Bridge\I18n::t('Access denied.')],403); return; }
        // phpcs:ignore WordPress.Security.NonceVerification -- Value is verified immediately below with wp_verify_nonce before any dispatch.
        $nonce = AdminInput::text($_POST, 'nonce', null, 128);
        if (!is_string($nonce) || strlen($nonce)>128 || !wp_verify_nonce($nonce,'wd29_record_tools_'.$kind.'_'.$id)) { wp_send_json(['ok'=>false,'error'=>\WD29\Bridge\I18n::t('Session expired. Refresh the page.')],403); return; }
        $input=[];
        if ($op === 'sync') {
            foreach (['key','direction','hash','destination','confirm'] as $field) {
                $value = AdminInput::text($_POST, $field, null, 160);
                if ($value === null) { wp_send_json(['ok'=>false,'error'=>\WD29\Bridge\I18n::t('Invalid confirmation. Refresh the comparison.')],400); return; }
                $input[$field]=$value;
            }
            if ($input['confirm']!=='1') { wp_send_json(['ok'=>false,'error'=>\WD29\Bridge\I18n::t('Confirmation required.')],400); return; }
        }
        try {
            $result = $op === 'compare' ? RecordPanel::compare(\wd29_bridge(),$kind,$id) : RecordPanel::sync(\wd29_bridge(),$kind,$id,$input);
        } catch (\Throwable $error) { wp_send_json(['ok'=>false,'error'=>\WD29\Bridge\I18n::t('Comparison or synchronization could not be confirmed. Refresh the comparison and check Inklura Sync diagnostics.')],409); return; }
        wp_send_json(['ok'=>true,'data'=>$result]);
    }
}
