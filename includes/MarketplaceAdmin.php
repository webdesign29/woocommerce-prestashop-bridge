<?php
/** The bundled companion is served locally; Woo owns update delivery and billing. */
namespace WD29\Bridge;
require_once __DIR__ . '/Distribution.php';

final class MarketplaceAdmin
{
    public static function register(): void
    {
        if (!Distribution::isMarketplace()) { return; }
        add_action('admin_post_inklura_sync_companion', [self::class, 'download']);
    }

    public static function panel(): string
    {
        if (!Distribution::isMarketplace()) { return ''; }
        $url = wp_nonce_url(admin_url('admin-post.php?action=inklura_sync_companion'), 'inklura_sync_companion');
        return '<p><a class="button button-primary" href="' . esc_url($url) . '">'
            . esc_html__('Download PrestaShop companion', 'inklura-sync') . '</a></p>'
            . '<p>' . esc_html__('Install this archive through the PrestaShop Module Manager. When the WooCommerce connector is updated, download its included companion again and update the PrestaShop module. Settings, mappings and synchronization history are preserved.', 'inklura-sync') . '</p>'
            . '<p>' . esc_html__('WooCommerce.com Update Manager delivers the WooCommerce connector updates. Connect this store to the Woo account that owns the subscription to receive updates.', 'inklura-sync') . '</p>';
    }

    public static function download(): void
    {
        if (!Distribution::isMarketplace() || !current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to download the companion module.', 'inklura-sync'), '', ['response' => 403]);
            return;
        }
        check_admin_referer('inklura_sync_companion');
        $path = dirname(__DIR__) . '/companion/wd29woobridge.zip';
        if (!is_file($path) || !is_readable($path)) {
            wp_die(esc_html__('The companion archive is missing. Reinstall the Woo Marketplace package or contact support.', 'inklura-sync'), '', ['response' => 404]);
            return;
        }
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="wd29woobridge.zip"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }
}
