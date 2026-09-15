<?php
/**
 * Plugin Name: WD29 WooCommerce PrestaShop Bridge
 * Description: Direct signed webhooks, initial catalog reconciliation and durable synchronization with PrestaShop.
 * Version: 0.1.4
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: Webdesign29
 * License: GPL-2.0-or-later
 * Text Domain: wd29-bridge
 */
defined('ABSPATH') || exit;
require_once __DIR__ . '/includes/Protocol.php';
require_once __DIR__ . '/includes/Engine.php';
require_once __DIR__ . '/includes/WooAdapter.php';

function wd29_bridge(): \WD29\Bridge\Engine {
    static $engine;
    if (!$engine) {
        $adapter = new \WD29\Bridge\WooAdapter();
        $engine = new \WD29\Bridge\Engine($adapter);
        $adapter->engine = $engine;
    }
    return $engine;
}
register_activation_hook(__FILE__, function () {
    if (!class_exists('WooCommerce') || !extension_loaded('curl')) { wp_die('WooCommerce and PHP cURL are required.'); }
    wd29_bridge()->install();
    add_option('wd29_bridge_config', ['mode' => 'disabled', 'peer' => '', 'secret' => ''], '', false);
    if (!wp_next_scheduled('wd29_bridge_tick')) { wp_schedule_event(time() + 60, 'wd29_minute', 'wd29_bridge_tick'); }
});
register_deactivation_hook(__FILE__, function () { wp_clear_scheduled_hook('wd29_bridge_tick'); });
add_filter('cron_schedules', function ($schedules) { $schedules['wd29_minute'] = ['interval' => 60, 'display' => 'Every minute (WD29 bridge)']; return $schedules; });
add_action('plugins_loaded', function () {
    if (get_option('wd29_bridge_schema') !== '4') { wd29_bridge()->install(); update_option('wd29_bridge_schema','4',false); }
}, 30);
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
add_action('rest_api_init', function () {
    register_rest_route('wd29-bridge/v1', '/webhook', [
        'methods' => 'POST',
        'permission_callback' => function ($request) {
            try {
                \WD29\Bridge\Protocol::verify($request->get_body(), wd29_bridge()->config()['secret'] ?? '', [
                    'timestamp' => $request->get_header('x-wd29-timestamp'), 'nonce' => $request->get_header('x-wd29-nonce'), 'signature' => $request->get_header('x-wd29-signature')]);
                return true;
            } catch (\Throwable $e) { return new \WP_Error('bridge_auth', 'Invalid signed request.', ['status' => 401]); }
        },
        'callback' => function ($request) {
            try { return rest_ensure_response(wd29_bridge()->receive($request->get_json_params())); }
            catch (\Throwable $e) { return new \WP_Error('bridge_request', 'Request could not be processed.', ['status' => 400]); }
        },
    ]);
});
add_action('admin_menu', function () { add_submenu_page('woocommerce', 'PrestaShop Bridge', 'PrestaShop Bridge', 'manage_options', 'wd29-bridge', 'wd29_bridge_admin'); });

function wd29_bridge_admin(): void {
    if (!current_user_can('manage_options')) { return; }
    $engine = wd29_bridge();
    $message = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_admin_referer('wd29_bridge_admin');
        try {
            $action = sanitize_key($_POST['bridge_action'] ?? '');
            if ($action === 'save') {
                $config = $engine->config();
                $mode = sanitize_key($_POST['mode'] ?? 'disabled');
                if (!in_array($mode, ['disabled', 'audit', 'live'], true)) { throw new \RuntimeException('Invalid mode.'); }
                $peer = trim(wp_unslash($_POST['peer'] ?? ''));
                if ($peer !== '') { \WD29\Bridge\Protocol::publicEndpoint($peer); }
                $secret = trim(wp_unslash($_POST['secret'] ?? ''));
                if ($secret !== '' && strlen($secret) < 32) { throw new \RuntimeException('Secret must contain at least 32 characters.'); }
                $policy = sanitize_key($_POST['conflict_policy'] ?? 'review');
                if (!in_array($policy, ['review','woo','ps'], true)) { throw new \RuntimeException('Invalid conflict policy.'); }
                $engine->validateSettings($mode, $peer, $secret !== '' ? $secret : ($config['secret'] ?? ''));
                update_option('wd29_bridge_config', ['mode' => $mode, 'peer' => $peer, 'secret' => $secret !== '' ? $secret : ($config['secret'] ?? ''), 'conflict_policy' => $policy], false);
                $message = 'Settings saved.';
            } elseif ($action === 'health') { $message = wp_json_encode($engine->peer(['op' => 'health'])); }
            elseif ($action === 'tick') { $engine->tick(); $message = 'Queue processed; inspect the result below.'; }
            elseif ($action === 'retry') { $engine->retry(); $message = 'Failed events queued again.'; }
            elseif ($action === 'resolve_catalog') { $engine->retryCatalogConflicts(); $message = 'Catalog conflicts queued with the selected priority.'; }
            elseif (in_array($action, ['seed_products','seed_orders','seed_customers'], true)) {
                $kind = $action === 'seed_customers' ? 'customer' : ($action === 'seed_orders' ? 'order' : 'product');
                $offset = max(0, (int) ($_POST['offset'] ?? 0));
                $count = $engine->seed($kind, $offset);
                $peer = $engine->peer(['op' => 'seed', 'kind' => $kind, 'offset' => $offset]);
                $message = 'Batch captured: local ' . $count . ', peer ' . $peer['count'] . '. Next offset: ' . ($offset + 10);
            }
        } catch (\Throwable $e) { $message = $e->getMessage(); }
    }
    $config = $engine->config();
    echo '<div class="wrap"><h1>PrestaShop Bridge</h1><p>Direct connection. Audit mode queues incoming changes without applying them. Existing records keep their source identity; blank SKUs never match automatically.</p>';
    if ($message) { echo '<div class="notice notice-info"><p>' . esc_html($message) . '</p></div>'; }
    echo '<p><strong>Local webhook:</strong> <code>' . esc_html(rest_url('wd29-bridge/v1/webhook')) . '</code></p>';
    echo '<form method="post">'; wp_nonce_field('wd29_bridge_admin');
    echo '<p><label>Mode <select name="mode">';
    foreach (['disabled' => 'Disabled', 'audit' => 'Audit — no incoming writes', 'live' => 'Live synchronization'] as $value => $label) {
        echo '<option value="' . esc_attr($value) . '" ' . selected($config['mode'] ?? 'disabled', $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label></p><p><label>PrestaShop webhook <input class="large-text" type="url" name="peer" value="' . esc_attr($config['peer'] ?? '') . '"></label></p>';
    echo '<p><label>Simultaneous catalog edits <select name="conflict_policy">';
    foreach (['review'=>'Pause for review','woo'=>'Prefer WooCommerce','ps'=>'Prefer PrestaShop'] as $value=>$label) { echo '<option value="'.esc_attr($value).'" '.selected($config['conflict_policy'] ?? 'review',$value,false).'>'.esc_html($label).'</option>'; }
    echo '</select></label> Use the same policy on both stores.</p>';
    echo '<p><label>Shared secret <input type="password" name="secret" autocomplete="new-password" value=""></label> Leave blank to keep the configured secret.</p>';
    echo '<button class="button button-primary" name="bridge_action" value="save">Save settings</button></form><hr><form method="post">';
    wp_nonce_field('wd29_bridge_admin');
    echo '<p><label>Batch offset <input type="number" min="0" name="offset" value="0"></label> Batches contain up to 10 records per store.</p>';
    foreach (['health' => 'Test connection', 'seed_products' => 'Capture both catalogs', 'seed_orders' => 'Capture both order histories', 'seed_customers' => 'Capture customer contacts', 'tick' => 'Process queue', 'retry' => 'Retry failures', 'resolve_catalog'=>'Retry catalog conflicts with selected priority'] as $value => $label) {
        echo '<button class="button" name="bridge_action" value="' . esc_attr($value) . '">' . esc_html($label) . '</button> ';
    }
    echo '</form><p>' . esc_html(get_option('wd29_bridge_notice', '')) . '</p><h2>Latest events</h2><table class="widefat"><thead><tr>';
    foreach (['seq','direction','kind','record_key','state','attempts','error','created_at'] as $heading) { echo '<th>' . esc_html($heading) . '</th>'; }
    echo '</tr></thead><tbody>';
    foreach ($engine->report() as $row) { echo '<tr>'; foreach ($row as $cell) { echo '<td>' . esc_html((string) $cell) . '</td>'; } echo '</tr>'; }
    echo '</tbody></table><h2>Catalog audit</h2><p>Latest transmitted snapshots; unknown stock is not zero. Up to 200 products.</p><table class="widefat"><thead><tr>';
    foreach (['source','local_id','name','brands','tags','type','regular','sale','tax','basis','initial_stock_snapshot'] as $heading) { echo '<th>' . esc_html($heading) . '</th>'; }
    echo '</tr></thead><tbody>';
    foreach ($engine->catalogAudit() as $row) { echo '<tr>'; foreach ($row as $cell) { echo '<td>' . esc_html((string)$cell) . '</td>'; } echo '</tr>'; }
    echo '</tbody></table><h2>Order reconciliation</h2><p>Unlinked historical lines retain their source details; catalog links are repaired once products are available.</p><table class="widefat"><thead><tr>';
    foreach (['source','local_id','total','currency','status','lines','unlinked_lines'] as $heading) { echo '<th>'.esc_html($heading).'</th>'; }
    echo '</tr></thead><tbody>';
    foreach ($engine->orderReport() as $row) { echo '<tr>'; foreach ($row as $cell) { echo '<td>'.esc_html((string)$cell).'</td>'; } echo '</tr>'; }
    echo '</tbody></table><h2>Customer contact directory</h2><p>Read-only copies of contact details, edited on their source store. No login accounts, passwords or marketing consents are copied. Emails never merge identities automatically. Up to 200 contacts.</p><table class="widefat"><thead><tr>';
    foreach (['source','name','email','phone','company','billing','type'] as $heading) { echo '<th>'.esc_html($heading).'</th>'; }
    echo '</tr></thead><tbody>';
    foreach ($engine->customerReport() as $row) { echo '<tr>'; foreach ($row as $cell) { echo '<td>'.esc_html((string)$cell).'</td>'; } echo '</tr>'; }
    echo '</tbody></table></div>';
}
