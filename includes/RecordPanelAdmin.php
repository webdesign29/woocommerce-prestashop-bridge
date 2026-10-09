<?php
namespace WD29\Bridge;

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
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { wp_die('Méthode non autorisée.', '', ['response'=>405]); return; }
        // The native login flow retains the requested URL; no record is resolved before login.
        auth_redirect();
    }
    public static function openRecord(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { wp_die('Méthode non autorisée.', '', ['response'=>405]); return; }
        if (!is_user_logged_in()) { auth_redirect(); return; }
        $kind = $_GET['kind'] ?? null; $key = $_GET['key'] ?? null;
        if (!is_string($kind) || !in_array($kind, ['product','order','customer'], true) || !is_string($key) || strlen($key)>96) { wp_die('Fiche indisponible.', '', ['response'=>404]); return; }
        $key = wp_unslash($key);
        try { $id = RecordPanel::nativeId(\wd29_bridge(), $kind, $key); }
        catch (\Throwable $error) { $id = 0; }
        if ($id < 1 || !self::allowed($kind,$id)) { wp_die('Fiche indisponible ou accès refusé.', '', ['response'=>403]); return; }
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
        echo '<p class="form-row form-row-full description">Inklura Sync : enregistrez vos déclinaisons, puis <a href="#wd29-product-link">comparez le produit et ses déclinaisons</a> dans le panneau Inklura Sync.</p>';
    }
    public static function render(string $kind, int $id): void
    {
        if (!self::allowed($kind, $id)) { return; }
        try { $local = RecordPanel::local(\wd29_bridge(), $kind, $id); }
        catch (\Throwable $error) { $local = ['state'=>'unavailable','reason'=>'Cette fiche ne peut pas être comparée pour le moment.']; }
        if ($kind === 'product') { ProductLinksAdmin::render(get_post($id)); echo '<hr>'; }
        wp_enqueue_script('wd29-record-panel', plugins_url('includes/record-panel.js', self::$pluginFile), [], defined('WD29_BRIDGE_VERSION') ? WD29_BRIDGE_VERSION : '1', true);
        echo '<div data-wd29-record-panel data-kind="'.esc_attr($kind).'" data-id="'.esc_attr((string)$id).'" data-state="'.esc_attr((string)($local['state']??'unavailable')).'" data-endpoint="'.esc_url(admin_url('admin-ajax.php')).'" data-nonce="'.esc_attr(wp_create_nonce('wd29_record_tools_'.$kind.'_'.$id)).'">';
        echo '<p><strong>Comparaison et synchronisation</strong></p><p class="description">Enregistrez vos modifications avant de comparer. Seules les données enregistrées sont synchronisées'.($kind==='product'?', y compris les déclinaisons':'').'.</p>';
        echo '<p data-record-status role="status" aria-live="polite">'.esc_html((string)($local['reason']??'Lecture de l’état de synchronisation…')).'</p>';
        echo '<div data-record-comparison hidden style="display:none"><p><strong data-record-direction></strong></p><p data-record-origin class="description"></p><dl style="margin:12px 0"><dt><strong>WooCommerce</strong></dt><dd data-record-woo style="margin:4px 0 10px"></dd><dt><strong>PrestaShop</strong></dt><dd data-record-ps style="margin:4px 0 10px"></dd></dl><p><a class="button" data-record-admin-link hidden style="display:none;max-width:100%;white-space:normal;line-height:1.5;padding-top:4px;padding-bottom:4px" target="_blank" rel="noopener noreferrer">Administration distante ↗</a><br><small data-record-admin-note hidden style="display:none">Connexion requise sur la boutique partenaire.</small></p><p data-record-changes></p><p data-record-variations class="description"></p><details data-record-variation-details hidden style="display:none"><summary>Déclinaisons concernées</summary><ul data-record-variation-rows></ul></details><p data-record-customer class="description"></p><p data-record-native class="description"></p><p data-record-stock class="description"></p><p data-record-events class="description"></p></div>';
        echo '<p><button type="button" class="button" data-record-refresh>Comparer</button> <button type="button" class="button button-primary" data-record-sync hidden style="display:none">Synchroniser</button></p><noscript><p>Activez JavaScript pour comparer cette fiche.</p></noscript></div>';
    }
    public static function ajax(): void
    {
        nocache_headers();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_send_json(['ok'=>false,'error'=>'Méthode non autorisée.'],405); return; }
        $kind = $_POST['kind'] ?? null; $raw = $_POST['id'] ?? null; $op = $_POST['op'] ?? null;
        if (!is_string($kind) || !in_array($kind,['product','order','customer'],true) || !is_scalar($raw) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$raw) || !in_array($op,['compare','sync'],true)) { wp_send_json(['ok'=>false,'error'=>'Requête invalide.'],400); return; }
        $id = (int)$raw;
        if (!self::allowed($kind,$id)) { wp_send_json(['ok'=>false,'error'=>'Accès refusé.'],403); return; }
        $nonce = $_POST['nonce'] ?? null;
        if (!is_string($nonce) || strlen($nonce)>128 || !wp_verify_nonce(wp_unslash($nonce),'wd29_record_tools_'.$kind.'_'.$id)) { wp_send_json(['ok'=>false,'error'=>'Session expirée. Actualisez la page.'],403); return; }
        $input=[];
        if ($op === 'sync') {
            foreach (['key','direction','hash','destination','confirm'] as $field) {
                if (!isset($_POST[$field]) || !is_scalar($_POST[$field]) || strlen((string)$_POST[$field])>160) { wp_send_json(['ok'=>false,'error'=>'Confirmation invalide. Actualisez la comparaison.'],400); return; }
                $input[$field]=wp_unslash((string)$_POST[$field]);
            }
            if ($input['confirm']!=='1') { wp_send_json(['ok'=>false,'error'=>'Confirmation requise.'],400); return; }
        }
        try {
            $result = $op === 'compare' ? RecordPanel::compare(\wd29_bridge(),$kind,$id) : RecordPanel::sync(\wd29_bridge(),$kind,$id,$input);
        } catch (\Throwable $error) { wp_send_json(['ok'=>false,'error'=>'La comparaison ou la synchronisation n’a pas pu être confirmée. Actualisez la comparaison et consultez les diagnostics Inklura Sync.'],409); return; }
        wp_send_json(['ok'=>true,'data'=>$result]);
    }
}
