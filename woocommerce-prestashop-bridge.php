<?php
/**
 * Plugin Name: Inklura Sync
 * Description: Synchronize WooCommerce, PrestaShop and Shopify: catalogue, stock, orders and customers.
 * Version: 0.8.13
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: Inklura
 * Author URI: https://plugins.inklura.fr
 * Plugin URI: https://plugins.inklura.fr/bridge-woocommerce-prestashop
 * Developer: Inklura
 * Developer URI: https://plugins.inklura.fr
 * WC tested up to: 11.2.1
 * License: GPL-2.0-or-later
 * Text Domain: inklura-sync
 * Domain Path: /languages
 */
defined('ABSPATH') || exit;
// Only one distribution may bootstrap the shared engine. Switching editions
// requires deactivation, not uninstalling or removing any settings or history.
$wd29_other_connector = basename(__FILE__) === 'inklura-sync.php'
    ? 'woocommerce-prestashop-bridge/woocommerce-prestashop-bridge.php'
    : 'inklura-sync/inklura-sync.php';
if (defined('WD29_BRIDGE_VERSION') || in_array($wd29_other_connector, (array) get_option('active_plugins', []), true)
    || isset(((array) get_site_option('active_sitewide_plugins', []))[$wd29_other_connector])) {
    register_activation_hook(__FILE__, function () {
        wp_die('Deactivate the other Inklura Sync edition before activating this one. Do not uninstall it: your settings and synchronization history will be reused.');
    });
    add_action('admin_notices', function () {
        if (current_user_can('activate_plugins')) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Only one Inklura Sync edition can be active. Deactivate the other edition before activating this one; do not uninstall it.', 'inklura-sync') . '</p></div>';
        }
    });
    return;
}
unset($wd29_other_connector);
// Keep function declarations conditional too: PHP registers unconditional
// declarations before an early return, which would collide with an older edition.
if (!defined('WD29_BRIDGE_VERSION')) {
require_once __DIR__ . '/includes/I18n.php';
\WD29\Bridge\I18n::register(__FILE__);

define('WD29_BRIDGE_VERSION', '0.8.13');
require_once __DIR__ . '/includes/Protocol.php';
require_once __DIR__ . '/includes/AdminInput.php';
require_once __DIR__ . '/includes/Licence.php';
require_once __DIR__ . '/includes/LicenceAdmin.php';
require_once __DIR__ . '/includes/Updater.php';
require_once __DIR__ . '/includes/MarketplaceAdmin.php';
require_once __DIR__ . '/includes/Engine.php';
require_once __DIR__ . '/includes/ManualOrdersAdmin.php';
require_once __DIR__ . '/includes/ManualRecordsAdmin.php';
require_once __DIR__ . '/includes/ProductLinksAdmin.php';
require_once __DIR__ . '/includes/RecordPanelAdmin.php';
require_once __DIR__ . '/includes/AdminDesign.php';
require_once __DIR__ . '/includes/OrderConflicts.php';
require_once __DIR__ . '/includes/CustomerAccounts.php';
require_once __DIR__ . '/includes/OrderCustomers.php';
require_once __DIR__ . '/includes/KerawenAccount.php';
add_action('woocommerce_account_dashboard',['\WD29\Bridge\KerawenAccount','render']);
require_once __DIR__ . '/includes/Refunds.php';
require_once __DIR__ . '/includes/Suppliers.php';
require_once __DIR__ . '/includes/Gallery.php';
require_once __DIR__ . '/includes/DiagnosticsAdmin.php';
require_once __DIR__ . '/includes/ActionsAdmin.php';
require_once __DIR__ . '/includes/WooAdapter.php';
require_once __DIR__ . '/includes/Shopify/Admin.php';
\WD29\Bridge\Shopify\Admin::register();
require_once __DIR__ . '/includes/CustomFields.php';
require_once __DIR__ . '/includes/CustomFieldsAdmin.php';
// Both storage modes pass the native Woo integration suite. Synchronization
// uses native order objects and does not customize the cart/checkout interface.
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

function wd29_bridge(): \WD29\Bridge\Engine {
    static $engine;
    if (!$engine) {
        $adapter = new \WD29\Bridge\WooAdapter();
        $engine = new \WD29\Bridge\Engine($adapter);
        $adapter->engine = $engine;
    }
    return $engine;
}
\WD29\Bridge\ProductLinksAdmin::register(__FILE__);
\WD29\Bridge\RecordPanelAdmin::register(__FILE__);
add_action('init', ['\WD29\Bridge\WooAdapter','registerSourceStatuses']);
(new \WD29\Bridge\Updater('wd29_bridge', __FILE__))->register();
\WD29\Bridge\MarketplaceAdmin::register();
// One admin notice while live mode is paused or the grace period is running (reads one option).
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=wd29-bridge')) . \WD29\Bridge\I18n::t('">Settings</a>'));
    $links[] = '<a href="' . esc_url(admin_url('admin.php?page=inklura-shopify')) . '">Shopify</a>';
    return $links;
});
add_action('admin_notices', function () {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page selector for showing notices; no state change.
    if (!current_user_can('manage_options') || (\WD29\Bridge\AdminInput::text($_GET, 'page', '') === 'wd29-bridge')) { return; }
    $engine = wd29_bridge();
    if (($engine->config()['mode'] ?? 'disabled') === 'disabled') { return; }
    $s = $engine->licence()->summary();
    if ($s['tone'] === 'ok') { return; }
    echo '<div class="notice notice-' . ($s['live'] ? 'warning' : 'error') . '"><p><strong>Inklura Sync : ' . esc_html($s['label']) . '.</strong> ' . esc_html($s['text']) . ' <a href="' . esc_url(admin_url('admin.php?page=wd29-bridge&wd_view=licence#wd-licence')) . \WD29\Bridge\I18n::t('">License</a></p></div>');
});
// Decisions or settings blocking synchronization, on every admin screen, with a link to the page that resolves them.
add_action('admin_notices', function () {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page selector for showing notices; no state change.
    if (!current_user_can('manage_options') || (\WD29\Bridge\AdminInput::text($_GET, 'page', '') === 'wd29-bridge')) { return; }
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted form renderer escapes dynamic values at their HTML contexts; retains nonces and controls.
    echo \WD29\Bridge\ActionsAdmin::notice(wd29_bridge(), 'woo', function ($view) { return admin_url('admin.php?page=wd29-bridge&wd_view=' . rawurlencode($view)); });
});
add_filter('wc_order_statuses', ['\WD29\Bridge\WooAdapter','statusList']);
// Source-disabled imported accounts cannot authenticate through cookies, passwords or application passwords.
add_filter('wp_authenticate_user',function($user){
    if ($user instanceof WP_User && \WD29\Bridge\CustomerAccounts::disabled((int)$user->ID)) { return new WP_Error('wd29_customer_disabled',\WD29\Bridge\I18n::t('This customer account is disabled.')); }
    return $user;
},99);
add_filter('determine_current_user',function($id){return $id && \WD29\Bridge\CustomerAccounts::disabled((int)$id)?0:$id;},99);

register_activation_hook(__FILE__, function () {
    if (!class_exists('WooCommerce') || !extension_loaded('curl')) { wp_die(\WD29\Bridge\I18n::t('WooCommerce and the PHP cURL extension are required.')); }
    wd29_bridge()->install();
    add_option('wd29_bridge_config', ['mode' => 'disabled', 'peer' => '', 'secret' => ''], '', false);
    if (!wp_next_scheduled('wd29_bridge_tick')) { wp_schedule_event(time() + 60, 'wd29_minute', 'wd29_bridge_tick'); }
});
register_deactivation_hook(__FILE__, function () { wp_clear_scheduled_hook('wd29_bridge_tick'); });
add_filter('cron_schedules', function ($schedules) { $schedules['wd29_minute'] = ['interval' => 60, 'display' => 'Every minute (WD29 bridge)']; return $schedules; });
add_action('plugins_loaded', function () {
    if (get_option('wd29_bridge_schema') !== '7') { wd29_bridge()->install(); update_option('wd29_bridge_schema','7',false); }
}, 30);
/** Native VAT changes unblock only tax-related failures, preserving other failures and conflicts. */
function wd29_bridge_tax_settings_changed(): void {
    global $wpdb;
    // Woo's tax DELETE controller reads rows_affected after this hook. Queue queries must
    // not replace the native mutation result (including when no retry rows are updated).
    $native_rows = $wpdb->rows_affected;
    $native_insert = $wpdb->insert_id;
    $native_error = $wpdb->last_error;
    try {
        wd29_bridge()->requeueSettingErrors([
            'Destination taxes are disabled; confirm a tax mapping before importing taxed prices.',
            'No matching WooCommerce tax class.',
            'Display-only source price needs a confirmed tax mapping.',
        ]);
    } catch (\Throwable $error) {
        // Recovery is best effort; a bridge failure must not fail a native tax operation.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational failure only; no payload, credentials or customer data.
        error_log('Inklura Sync: fiscal retry deferred after a native tax settings change.');
    } finally {
        $wpdb->rows_affected = $native_rows;
        $wpdb->insert_id = $native_insert;
        $wpdb->last_error = $native_error;
    }
}
function wd29_bridge_tax_option_changed($name): void {
    if (in_array($name, ['woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_default_country', 'woocommerce_store_city', 'woocommerce_store_postcode', 'woocommerce_tax_classes', 'woocommerce_tax_based_on'], true)) { wd29_bridge_tax_settings_changed(); }
}
add_action('updated_option', 'wd29_bridge_tax_option_changed');
add_action('added_option', 'wd29_bridge_tax_option_changed');
foreach (['woocommerce_tax_rate_added', 'woocommerce_tax_rate_updated', 'woocommerce_tax_rate_deleted'] as $hook) {
    add_action($hook, 'wd29_bridge_tax_settings_changed');
}
add_action('wd29_bridge_tick', function () { if (function_exists('wc_get_product')) { wd29_bridge()->tick(); } });
function wd29_bridge_capture_later(string $kind, int $id): void {
    static $pending = [], $registered = false;
    $engine = wd29_bridge();
    if ($id < 1 || !$engine->enabled() || ($kind === 'product' && ($engine->catalogApplying || $engine->stockApplying)) || ($kind === 'order' && $engine->orderApplying)) { return; }
    $pending[$kind][$id] = $id;
    if (!$registered) {
        $registered = true;
        register_shutdown_function(function () use (&$pending) {
            foreach (['product','order'] as $kind) { foreach ($pending[$kind] ?? [] as $id) { wd29_bridge()->capture($kind, $id); } }
        });
    }
}
add_action('woocommerce_after_product_object_save', function ($product) { wd29_bridge_capture_later('product', $product->get_parent_id() ?: $product->get_id()); }, 100);
add_action('woocommerce_product_set_stock', function ($product) { wd29_bridge_capture_later('product', $product->get_parent_id() ?: $product->get_id()); }, 100);
add_action('woocommerce_variation_set_stock', function ($product) { wd29_bridge_capture_later('product', $product->get_parent_id()); }, 100);
add_action('woocommerce_after_order_object_save', function ($order) { if ($order->get_type() === 'shop_order') { wd29_bridge_capture_later('order', $order->get_id()); } }, 100);
foreach (['woocommerce_can_reduce_order_stock', 'woocommerce_can_restore_order_stock'] as $filter) {
    add_filter($filter, function ($allowed, $order) { return $order && $order->get_meta('_wd29_bridge_origin') ? false : $allowed; }, 100, 2);
}
foreach (['new_order','cancelled_order','failed_order','customer_on_hold_order','customer_processing_order','customer_completed_order','customer_refunded_order','customer_invoice','customer_note'] as $email) {
    add_filter('woocommerce_email_enabled_' . $email, function ($enabled, $order) {
        return wd29_bridge()->orderApplying || ($order instanceof \WC_Order && $order->get_meta('_wd29_bridge_origin')) ? false : $enabled;
    }, 100, 2);
}
/** Authenticate the exact request body before decoding or dispatching it.
 * @return true|WP_Error WordPress rejects the request when authentication fails.
 */
function wd29_bridge_webhook_permission($request) {
    try {
        \WD29\Bridge\Protocol::verify($request->get_body(), wd29_bridge()->config()['secret'] ?? '', [
            'timestamp' => $request->get_header('x-wd29-timestamp'),
            'nonce' => $request->get_header('x-wd29-nonce'),
            'signature' => $request->get_header('x-wd29-signature'),
        ]);
        return true;
    } catch (\Throwable $error) {
        return new WP_Error('bridge_auth', 'Invalid signed request.', ['status' => 401]);
    }
}
add_action('rest_api_init', function () {
    register_rest_route('wd29-bridge/v1', '/webhook', [
        'methods' => 'POST',
        'permission_callback' => 'wd29_bridge_webhook_permission',
        'callback' => function ($request) {
            try { return rest_ensure_response(wd29_bridge()->receive($request->get_json_params())); }
            catch (\Throwable $e) { return new \WP_Error('bridge_request', 'Request could not be processed.', ['status' => 400]); }
        },
    ]);
});
add_action('admin_menu', function () { add_submenu_page('woocommerce', 'Inklura Sync', 'Inklura Sync', 'manage_options', 'wd29-bridge', 'wd29_bridge_admin'); });

add_action('admin_post_wd29_sync_batch',function(){
    if(!current_user_can('manage_options')){wp_send_json(['ok'=>false,'error'=>\WD29\Bridge\I18n::t('Access denied.')],403);}
    if(\WD29\Bridge\AdminInput::text($_SERVER,'REQUEST_METHOD')!=='POST'){wp_send_json(['ok'=>false,'error'=>\WD29\Bridge\I18n::t('POST required.')],405);}
    check_admin_referer('wd29_bridge_admin');
    try{$result=\WD29\Bridge\ManualRecordsAdmin::batch(wd29_bridge(),wp_unslash($_POST));}
    catch(\Throwable $error){$result=['ok'=>false,'error'=>$error->getMessage()];}
    nocache_headers();wp_send_json($result);
});

function wd29_bridge_admin(): void {
    if (!current_user_can('manage_options')) { return; }
    $engine = wd29_bridge();
    $message = '';
    if (\WD29\Bridge\AdminInput::text($_SERVER,'REQUEST_METHOD') === 'POST') {
        check_admin_referer('wd29_bridge_admin');
        try {
            $action = sanitize_key($_POST['bridge_action'] ?? '');
            $decisionMessage = \WD29\Bridge\ActionsAdmin::handle($engine, $action, wp_unslash($_POST));
            if ($decisionMessage !== null) { $message = $decisionMessage; $action = ''; }
            $recordMessage=$action===''?null:\WD29\Bridge\ManualRecordsAdmin::handle($engine,wp_unslash($_POST));
            $licenceMessage = \WD29\Bridge\LicenceAdmin::handle($engine, $action, \WD29\Bridge\AdminInput::opaque($_POST, 'licence_key', '', 4096));
            $manualMessage = \WD29\Bridge\ManualOrdersAdmin::handle($engine, wp_unslash($_POST));
            if ($action === '') {}
            elseif ($recordMessage !== null) { $message=$recordMessage; }
            elseif ($manualMessage !== null) { $message=$manualMessage; }
            elseif ($licenceMessage !== null) { $message = $licenceMessage; \WD29\Bridge\Updater::forget(); }
            elseif ($action === 'save') {
                $config = $engine->config();
                $mode = sanitize_key($_POST['mode'] ?? 'disabled');
                if (!in_array($mode, ['disabled', 'audit', 'live'], true)) { throw new \RuntimeException(\WD29\Bridge\I18n::t('Invalid mode.')); }
                $peer = esc_url_raw(\WD29\Bridge\AdminInput::requiredText($_POST, 'peer'));
                if ($peer !== '') { \WD29\Bridge\Protocol::publicEndpoint($peer); }
                $secret = trim(\WD29\Bridge\AdminInput::opaque($_POST, 'secret', '', 4096));
                if ($secret !== '' && strlen($secret) < 32) { throw new \RuntimeException(\WD29\Bridge\I18n::t('The shared secret must contain at least 32 characters.')); }
                $policy = sanitize_key($_POST['conflict_policy'] ?? 'review');
                if (!in_array($policy, ['review','woo','ps'], true)) { throw new \RuntimeException(\WD29\Bridge\I18n::t('Invalid conflict policy.')); }
                $engine->validateSettings($mode, $peer, $secret !== '' ? $secret : ($config['secret'] ?? ''));
                update_option('wd29_bridge_config', ['mode' => $mode, 'peer' => $peer, 'secret' => $secret !== '' ? $secret : ($config['secret'] ?? ''), 'conflict_policy' => $policy, 'native_customers'=>!empty($_POST['native_customers']), 'sync_gallery_removals'=>!empty($_POST['sync_gallery_removals']), 'kerawen_accounts'=>!empty($_POST['kerawen_accounts'])] + $config, false);
                $message = \WD29\Bridge\I18n::t('Settings saved.') . $engine->settingsChanged($config);
            } elseif ($action === 'restore_gallery') {
                $engine->adapter->restoreGallery((\WD29\Bridge\AdminInput::text($_POST, 'gallery_record', '') ?? '')); $message=\WD29\Bridge\I18n::t('Gallery images reattached; product captured.');
            } elseif ($action === 'save_field_rows') {
                update_option('wd29_bridge_custom_fields', \WD29\Bridge\CustomFieldsAdmin::submitted(\WD29\Bridge\AdminInput::rows($_POST, 'field_rows')),false);
                $message=\WD29\Bridge\I18n::t('Custom field mappings saved.');
            } elseif ($action === 'save_custom_fields') {
                $rules=json_decode(\WD29\Bridge\AdminInput::opaque($_POST, 'custom_fields', '[]'),true,32,JSON_THROW_ON_ERROR);
                update_option('wd29_bridge_custom_fields', \WD29\Bridge\CustomFields::rules($rules),false);
                $message=\WD29\Bridge\I18n::t('Field list saved. Capture the catalogues to synchronize the selected fields.');
            } elseif ($action === 'save_statuses') {
                $mapping=[]; $submitted=\WD29\Bridge\AdminInput::rows($_POST, 'status_mapping');
                if (!is_array($submitted)) { throw new \RuntimeException(\WD29\Bridge\I18n::t('Invalid status mappings.')); }
                foreach ((array)get_option('wd29_bridge_source_statuses',[]) as $key=>$label) {
                    $target=\WD29\Bridge\AdminInput::requiredText($submitted,(string)$key,(string)$key);
                    if ($target!==$key && !in_array($target,['pending','on-hold','processing','completed','cancelled','refunded','failed'],true)) { throw new \RuntimeException(\WD29\Bridge\I18n::t('Invalid destination status.')); }
                    $mapping[$key]=$target;
                }
                update_option('wd29_bridge_status_mapping',$mapping,false);
                $message=\WD29\Bridge\I18n::t('Status mappings saved; mirrors updated: ').$engine->adapter->applyStatusMappings();
            } elseif ($action === 'normalize_stock') { $message=wp_json_encode($engine->adapter->normalizeUnknownVariantStock(max(0,(int)(\WD29\Bridge\AdminInput::text($_POST, 'offset', '0') ?? '0')))); } elseif ($action === 'health') { $message = wp_json_encode($engine->peer(['op' => 'health'])); }
            elseif ($action === 'tick') { $engine->tick(); $message = \WD29\Bridge\I18n::t('Queue processed: see the result below.'); }
            elseif ($action === 'retry') { $engine->retry(); $message = \WD29\Bridge\I18n::t('Failed events requeued.'); }
            elseif ($action === 'resolve_order_upgrades') { $message = \WD29\Bridge\I18n::t('Equivalent order updates requeued: ') . $engine->retryEquivalentOrderConflicts(); }
                elseif ($action === 'resolve_catalog') { $engine->retryCatalogConflicts(); $message = \WD29\Bridge\I18n::t('Catalogue conflicts requeued with the selected priority.'); }
            elseif (in_array($action, ['seed_products','seed_orders','seed_customers'], true)) {
                $kind = $action === 'seed_customers' ? 'customer' : ($action === 'seed_orders' ? 'order' : 'product');
                $offset = max(0, (int) (\WD29\Bridge\AdminInput::text($_POST, 'offset', '0') ?? '0'));
                $count = $engine->seed($kind, $offset);
                $peer = $engine->peer(['op' => 'seed', 'kind' => $kind, 'offset' => $offset]);
                $message = \WD29\Bridge\I18n::t('Batch captured: ') . $count . \WD29\Bridge\I18n::t(' on WordPress, ') . $peer['count'] . \WD29\Bridge\I18n::t(' on PrestaShop. Next offset: ') . ($offset + 10);
            }
        } catch (\Throwable $e) { $message = $e->getMessage(); }
    }
    $view=\WD29\Bridge\AdminDesign::currentView();
    $config = $engine->config();
    ob_start();
    echo \WD29\Bridge\I18n::t('<div class="wrap"><h1>PrestaShop Bridge</h1><p>Direct connection. In audit mode, incoming changes are queued without being applied. Every record keeps its source identity; an empty SKU is never used to match two records.</p>');
    if ($message) { echo '<div class="notice notice-info"><p>' . esc_html($message) . '</p></div>'; }
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted form renderer escapes dynamic values at their HTML contexts; retains nonces and controls.
    echo \WD29\Bridge\ActionsAdmin::render($engine, 'woo', wp_nonce_field('wd29_bridge_admin', '_wpnonce', true, false));
    echo \WD29\Bridge\I18n::t('<p><strong>Webhook for this store:</strong> <code>') . esc_html(rest_url('wd29-bridge/v1/webhook')) . '</code></p>';
    echo '<form method="post">'; wp_nonce_field('wd29_bridge_admin');
    echo '<p><label>Mode <select name="mode">';
    foreach (['disabled' => \WD29\Bridge\I18n::t('Stopped'), 'audit' => \WD29\Bridge\I18n::t('Audit: receive without applying'), 'live' => \WD29\Bridge\I18n::t('Live synchronization')] as $value => $label) {
        echo '<option value="' . esc_attr($value) . '" ' . selected($config['mode'] ?? 'disabled', $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label></p><p><label>Webhook PrestaShop <input class="large-text" type="url" name="peer" value="' . esc_attr($config['peer'] ?? '') . '"></label></p>';
    echo \WD29\Bridge\I18n::t('<p><label>Simultaneous catalogue changes <select name="conflict_policy">');
    foreach (['review'=>\WD29\Bridge\I18n::t('Pause for review'),'woo'=>\WD29\Bridge\I18n::t('WooCommerce takes priority'),'ps'=>\WD29\Bridge\I18n::t('PrestaShop takes priority')] as $value=>$label) { echo '<option value="'.esc_attr($value).'" '.selected($config['conflict_policy'] ?? 'review',$value,false).'>'.esc_html($label).'</option>'; }
    echo \WD29\Bridge\I18n::t('</select></label> Choose the same policy on both stores.</p>');
    echo \WD29\Bridge\I18n::t('<p><label>Shared secret <input type="password" name="secret" autocomplete="new-password" value=""></label> Leave blank to keep the saved secret.</p>');
    echo '<p><label><input type="checkbox" name="native_customers" value="1" '.checked(!empty($config['native_customers']),true,false).\WD29\Bridge\I18n::t('> Create native customer accounts for registered customers from the other store (independent passwords, no merging by email)</label></p>');
    echo '<p><label><input type="checkbox" name="sync_gallery_removals" value="1" '.checked(!empty($config['sync_gallery_removals']),true,false).\WD29\Bridge\I18n::t('> Detach imported images removed from the partner store (retain files and manually added images)</label></p>');
    echo '<p><label><input type="checkbox" name="kerawen_accounts" value="1" '.checked(!empty($config['kerawen_accounts']),true,false).\WD29\Bridge\I18n::t('> Show KerAwen points and benefits in the WooCommerce customer account (read only)</label></p>');
    echo \WD29\Bridge\I18n::t('<button class="button button-primary" name="bridge_action" value="save">Save settings</button></form><hr><form method="post">');
    wp_nonce_field('wd29_bridge_admin');
    echo \WD29\Bridge\I18n::t('<p><label>Product or variation identity <input name="gallery_record" placeholder="ps:product:123"></label> <button class="button" name="bridge_action" value="restore_gallery">Reattach detached images</button></p><p>Reattaches retained images for this record, then captures the product.</p>');
    echo \WD29\Bridge\I18n::t('<p><label>Batch offset <input type="number" min="0" name="offset" value="0"></label> Each batch contains up to 10 records per store.</p>');
    foreach (['health' => \WD29\Bridge\I18n::t('Test connection'), 'seed_products' => \WD29\Bridge\I18n::t('Catalogues: WordPress ↔ PrestaShop'), 'seed_orders' => \WD29\Bridge\I18n::t('Orders: WordPress ↔ PrestaShop'), 'seed_customers' => \WD29\Bridge\I18n::t('Contacts: WordPress ↔ PrestaShop'), 'tick' => \WD29\Bridge\I18n::t('Process WordPress events'), 'retry' => \WD29\Bridge\I18n::t('Retry failures'), 'resolve_order_upgrades'=>\WD29\Bridge\I18n::t('Retry equivalent order updates'), 'resolve_catalog'=>\WD29\Bridge\I18n::t('Retry catalogue conflicts with the selected priority'), 'normalize_stock'=>\WD29\Bridge\I18n::t('Set unknown Woo variation quantities to zero')] as $value => $label) {
        echo '<button class="button" name="bridge_action" value="' . esc_attr($value) . '">' . esc_html($label) . '</button> ';
    }
    echo '</form>';
    if($view==='settings')\WD29\Bridge\CustomFieldsAdmin::render();
    echo \WD29\Bridge\I18n::t('<h2>Order statuses</h2><p>Unknown PrestaShop statuses are created automatically with their original label. Map them to an existing WooCommerce status if needed: only the mirror display changes; the original PrestaShop status stays the same.</p><form method="post">');
    wp_nonce_field('wd29_bridge_admin');
    $statusMappings=(array)get_option('wd29_bridge_status_mapping',[]);
    foreach ((array)get_option('wd29_bridge_source_statuses',[]) as $key=>$label) {
        echo '<p><label>'.esc_html($label).' <select name="status_mapping['.esc_attr($key).']">';
        foreach ([$key=>\WD29\Bridge\I18n::t('Automatic (PrestaShop: ').$label,'pending'=>\WD29\Bridge\I18n::t('Pending payment'),'on-hold'=>\WD29\Bridge\I18n::t('On hold'),'processing'=>\WD29\Bridge\I18n::t('Processing'),'completed'=>\WD29\Bridge\I18n::t('Completed'),'cancelled'=>\WD29\Bridge\I18n::t('Cancelled'),'refunded'=>\WD29\Bridge\I18n::t('Refunded'),'failed'=>\WD29\Bridge\I18n::t('Failed')] as $value=>$text) {
            echo '<option value="'.esc_attr($value).'" '.(($statusMappings[$key]??$key)===$value?' selected="selected"':'').'>'.esc_html($text).'</option>';
        }
        echo '</select></label></p>';
    }
    echo \WD29\Bridge\I18n::t('<button class="button" name="bridge_action" value="save_statuses">Save statuses</button>');
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted form renderer escapes dynamic values at their HTML contexts; retains nonces and controls.
    echo '</form>'.\WD29\Bridge\DiagnosticsAdmin::render($engine,$view).\WD29\Bridge\I18n::t('<p>Last saved message (see diagnostics for the current state): ') . esc_html(get_option('wd29_bridge_notice', '')) . \WD29\Bridge\I18n::t('</p><h2>Event log</h2><table class="widefat"><thead><tr>');
    foreach (['N°',\WD29\Bridge\I18n::t('Direction'),'Type',\WD29\Bridge\I18n::t('Identity'),\WD29\Bridge\I18n::t('State'),\WD29\Bridge\I18n::t('Attempts'),\WD29\Bridge\I18n::t('Error'),\WD29\Bridge\I18n::t('Created')] as $heading) { echo '<th>' . esc_html($heading) . '</th>'; }
    echo '</tr></thead><tbody>';
    foreach (($view==='activity'?$engine->report():[]) as $row) { echo '<tr>'; foreach ($row as $cell) { echo '<td>' . esc_html((string) $cell) . '</td>'; } echo '</tr>'; }
    echo \WD29\Bridge\I18n::t('</tbody></table><h2>Catalogue</h2><p>Latest transmitted snapshots; unknown stock does not mean zero stock. Displays up to 200 products; this is a report limit, not a synchronization cap.</p><table class="widefat"><thead><tr>');
    foreach ([\WD29\Bridge\I18n::t('Origin'),\WD29\Bridge\I18n::t('Local ID'),\WD29\Bridge\I18n::t('Name'),\WD29\Bridge\I18n::t('Brands'),\WD29\Bridge\I18n::t('Tags'),'Type',\WD29\Bridge\I18n::t('Price'),'Promo',\WD29\Bridge\I18n::t('Tax'),'Base',\WD29\Bridge\I18n::t('Initial stock'),\WD29\Bridge\I18n::t('Identifiers'),'Dimensions (cm)',\WD29\Bridge\I18n::t('Attributes'),\WD29\Bridge\I18n::t('Variation images'),\WD29\Bridge\I18n::t('Archived'),\WD29\Bridge\I18n::t('Purchase excluding tax'),\WD29\Bridge\I18n::t('Supplier'),'SEO'] as $heading) { echo '<th>' . esc_html($heading) . '</th>'; }
    echo '</tr></thead><tbody>';
    foreach (($view==='reports'?$engine->catalogAudit():[]) as $row) { echo '<tr>'; foreach ($row as $cell) { echo '<td>' . esc_html((string)$cell) . '</td>'; } echo '</tr>'; }
    echo \WD29\Bridge\I18n::t('</tbody></table><h2>Orders</h2><p>Unlinked historical lines retain their original details; catalogue links are created when the product becomes available.</p><table class="widefat"><thead><tr>');
    foreach ([\WD29\Bridge\I18n::t('Origin'),\WD29\Bridge\I18n::t('Local ID'),'Total',\WD29\Bridge\I18n::t('Currency'),\WD29\Bridge\I18n::t('Status'),\WD29\Bridge\I18n::t('Lines'),\WD29\Bridge\I18n::t('Unlinked lines')] as $heading) { echo '<th>'.esc_html($heading).'</th>'; }
    echo '</tr></thead><tbody>';
    foreach (($view==='reports'?$engine->orderReport():[]) as $row) { echo '<tr>'; foreach ($row as $cell) { echo '<td>'.esc_html((string)$cell).'</td>'; } echo '</tr>'; }
    echo \WD29\Bridge\I18n::t('</tbody></table><h2>Customer contacts</h2><p>Read-only copies: edit them in their source store. This contact view does not copy accounts, passwords or marketing consent; email is never used to merge records. Displays up to 200 contacts.</p><table class="widefat"><thead><tr>');
    foreach ([\WD29\Bridge\I18n::t('Origin'),\WD29\Bridge\I18n::t('Name'),'E-mail',\WD29\Bridge\I18n::t('Phone'),\WD29\Bridge\I18n::t('Company'),\WD29\Bridge\I18n::t('Billing'),\WD29\Bridge\I18n::t('Addresses'),\WD29\Bridge\I18n::t('Shipping'),'Type'] as $heading) { echo '<th>'.esc_html($heading).'</th>'; }
    echo '</tr></thead><tbody>';
    foreach (($view==='reports'?$engine->customerReport():[]) as $row) { echo '<tr>'; foreach ($row as $cell) { echo '<td>'.esc_html((string)$cell).'</td>'; } echo '</tr>'; }
    echo '</tbody></table>';
    $update = $engine->licence()->updateAvailable();
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted form renderer escapes dynamic values at their HTML contexts; retains nonces and controls.
    echo \WD29\Bridge\LicenceAdmin::render($engine, wp_nonce_field('wd29_bridge_admin', '_wpnonce', true, false),
        \WD29\Bridge\Distribution::isMarketplace() ? \WD29\Bridge\MarketplaceAdmin::panel() :
        ($update ? '<p>Version ' . esc_html($update) . \WD29\Bridge\I18n::t(' available. <a href="') . esc_url(admin_url('plugins.php')) . \WD29\Bridge\I18n::t('">Update from Plugins</a>.</p>') : ''));
    echo '</div>';
    $html=ob_get_clean();$end=strrpos($html,'</div>');
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Rendering only; POST mutation handlers above require check_admin_referer.
    $panel=\WD29\Bridge\ManualRecordsAdmin::render($engine,wp_nonce_field('wd29_bridge_admin','_wpnonce',true,false),wp_unslash($_POST),admin_url('admin-post.php?action=wd29_sync_batch')).\WD29\Bridge\ManualOrdersAdmin::render($engine,wp_nonce_field('wd29_bridge_admin','_wpnonce',true,false),wp_unslash($_POST));
    if($end!==false){$html=substr_replace($html,$panel,$end,0);}
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted form renderer escapes dynamic values at their HTML contexts; retains nonces and controls.
    echo \WD29\Bridge\AdminDesign::render($html, $engine, 'woo');
}

add_action('woocommerce_product_options_general_product_data',function(){
    echo '<div class="options_group">';
    woocommerce_wp_text_input(['id'=>'_wd29_purchase_net','label'=>\WD29\Bridge\I18n::t('Purchase price excluding tax'),'description'=>\WD29\Bridge\I18n::t('Unit cost excluding tax, synchronized with PrestaShop. Leave blank if unknown.'),'desc_tip'=>true,'type'=>'number','custom_attributes'=>['step'=>'0.000001','min'=>'0']]);
    woocommerce_wp_text_input(['id'=>'_wd29_supplier_name','label'=>\WD29\Bridge\I18n::t('Main supplier')]);
    woocommerce_wp_text_input(['id'=>'_wd29_supplier_reference','label'=>\WD29\Bridge\I18n::t('Supplier reference')]);
    echo '</div>';
});
add_action('woocommerce_admin_process_product_object',function($product){
    foreach (['_wd29_purchase_net','_wd29_supplier_name','_wd29_supplier_reference'] as $field) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Woo's product save handler verifies its nonce and edit capability before this hook.
        if (!isset($_POST[$field])) { continue; }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Woo's product save handler verifies its nonce and edit capability before this hook.
        $value=\WD29\Bridge\AdminInput::text($_POST,$field);
        if ($value===null) { continue; }
        if ($field==='_wd29_purchase_net' && $value==='') { $product->delete_meta_data($field); continue; }
        if ($field==='_wd29_purchase_net' && (!is_numeric($value)||(float)$value<0)) { continue; }
        $product->update_meta_data($field,$value);
    }
});
}
