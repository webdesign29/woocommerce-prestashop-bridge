<?php
namespace WD29\Bridge;
require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/AdminInput.php';

/** Native product sidebar; peer discovery happens only after the editor is visible. */
final class ProductLinksAdmin
{
    private static $pluginFile = '';

    public static function register(string $pluginFile): void
    {
        self::$pluginFile = $pluginFile;
        add_action('wp_ajax_wd29_product_link', [self::class, 'ajax']);
    }

    public static function addBox($post): void
    {
        $id = (int) ($post->ID ?? 0);
        if ($id < 1 || get_post_type($id) !== 'product' || !current_user_can('edit_post', $id)) { return; }
        add_meta_box('wd29-product-link', 'Inklura Sync', [self::class, 'render'], 'product', 'side', 'default');
    }

    public static function render($post): void
    {
        $id = (int) ($post->ID ?? 0);
        if ($id < 1 || get_post_type($id) !== 'product' || !current_user_can('edit_post', $id)) { return; }
        try { $local = ProductLinks::local(\wd29_bridge(), $id); }
        catch (\Throwable $error) { $local = ['state' => 'unavailable']; }
        $state = (string) ($local['state'] ?? 'unavailable');
        $messages = [
            'ready' => \WD29\Bridge\I18n::t('The PrestaShop link will be checked when this panel opens.'),
            'unmapped' => \WD29\Bridge\I18n::t('This product has no synchronized mapping yet.'),
            'disconnected' => \WD29\Bridge\I18n::t('No PrestaShop store connected.'),
            'missing' => \WD29\Bridge\I18n::t('This product is no longer available.'),
            'unavailable' => \WD29\Bridge\I18n::t('The link cannot be checked right now.'),
        ];
        wp_enqueue_script('wd29-product-links', plugins_url('includes/product-links.js', self::$pluginFile), [], defined('WD29_BRIDGE_VERSION') ? WD29_BRIDGE_VERSION : '1', true);
        echo '<div data-wd29-product-link data-product="' . esc_attr((string) $id) . '" data-state="' . esc_attr($state) . '" data-endpoint="' . esc_url(admin_url('admin-ajax.php')) . '" data-nonce="' . esc_attr(wp_create_nonce('wd29_product_link_' . $id)) . '">';
        if (!empty($local['peer_host'])) { echo '<p><strong>PrestaShop</strong><br><span data-wd29-link-host>' . esc_html((string) $local['peer_host']) . '</span></p>'; }
        else { echo '<p><strong>PrestaShop</strong><br><span data-wd29-link-host></span></p>'; }
        if (!empty($local['key'])) {
            $origin = strpos((string) $local['key'], 'woo:') === 0 ? 'Original WooCommerce' : \WD29\Bridge\I18n::t('Copy imported from PrestaShop');
            echo '<p class="wd29-record-description" style="font-size:13px;color:#646970">' . esc_html($origin) . '</p>';
        }
        echo '<p data-wd29-link-status role="status" aria-live="polite">' . esc_html($messages[$state] ?? $messages['unavailable']) . '</p>';
        echo \WD29\Bridge\I18n::t('<p><a class="button" data-wd29-link-public hidden style="display:none" target="_blank" rel="noopener noreferrer">View on PrestaShop ↗</a></p>');
        echo \WD29\Bridge\I18n::t('<p><button type="button" class="button" data-wd29-link-retry hidden style="display:none">Check link</button></p>');
        echo \WD29\Bridge\I18n::t('<noscript><p class="wd29-record-description" style="font-size:13px;color:#646970">Enable JavaScript to check the partner store link.</p></noscript></div>');
    }

    public static function ajax(): void
    {
        nocache_headers();
        if (AdminInput::text($_SERVER, 'REQUEST_METHOD') !== 'POST') {
            wp_send_json(['ok' => false, 'error' => \WD29\Bridge\I18n::t('Method not allowed.')], 405); return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification -- Product ID determines the per-record nonce action; verification below precedes peer lookup.
        $raw = AdminInput::text($_POST, 'product', null, 10);
        if (!is_scalar($raw) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $raw)) {
            wp_send_json(['ok' => false, 'error' => \WD29\Bridge\I18n::t('Invalid product.')], 400); return;
        }
        $id = (int) $raw;
        if (!current_user_can('edit_post', $id)) {
            wp_send_json(['ok' => false, 'error' => \WD29\Bridge\I18n::t('Access denied.')], 403); return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification -- Value is verified immediately below with wp_verify_nonce before any peer lookup.
        $nonce = AdminInput::text($_POST, 'nonce', null, 128);
        if (!is_string($nonce) || strlen($nonce) > 128 || !wp_verify_nonce($nonce, 'wd29_product_link_' . $id)) {
            wp_send_json(['ok' => false, 'error' => \WD29\Bridge\I18n::t('Session expired. Refresh the page.')], 403); return;
        }
        if (get_post_type($id) !== 'product') {
            wp_send_json(['ok' => false, 'error' => \WD29\Bridge\I18n::t('Product unavailable.')], 404); return;
        }
        try { $result = ProductLinks::remote(\wd29_bridge(), $id); }
        catch (\Throwable $error) { $result = ['state' => 'unavailable', 'url' => '']; }
        wp_send_json(['ok' => true, 'data' => $result]);
    }
}
