<?php
namespace WD29\Bridge;

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
            'ready' => 'Le lien PrestaShop sera vérifié à l’ouverture de ce panneau.',
            'unmapped' => 'Ce produit n’a pas encore de correspondance synchronisée.',
            'disconnected' => 'Aucune boutique PrestaShop connectée.',
            'missing' => 'Ce produit n’est plus disponible.',
            'unavailable' => 'Impossible de vérifier le lien pour le moment.',
        ];
        wp_enqueue_script('wd29-product-links', plugins_url('includes/product-links.js', self::$pluginFile), [], defined('WD29_BRIDGE_VERSION') ? WD29_BRIDGE_VERSION : '1', true);
        echo '<div data-wd29-product-link data-product="' . esc_attr((string) $id) . '" data-state="' . esc_attr($state) . '" data-endpoint="' . esc_url(admin_url('admin-ajax.php')) . '" data-nonce="' . esc_attr(wp_create_nonce('wd29_product_link_' . $id)) . '">';
        if (!empty($local['peer_host'])) { echo '<p><strong>PrestaShop</strong><br><span data-wd29-link-host>' . esc_html((string) $local['peer_host']) . '</span></p>'; }
        else { echo '<p><strong>PrestaShop</strong><br><span data-wd29-link-host></span></p>'; }
        if (!empty($local['key'])) {
            $origin = strpos((string) $local['key'], 'woo:') === 0 ? 'Original WooCommerce' : 'Copie importée depuis PrestaShop';
            echo '<p class="description">' . esc_html($origin) . '</p>';
        }
        echo '<p data-wd29-link-status role="status" aria-live="polite">' . esc_html($messages[$state] ?? $messages['unavailable']) . '</p>';
        echo '<p><a class="button" data-wd29-link-public hidden style="display:none" target="_blank" rel="noopener noreferrer">Voir sur PrestaShop ↗</a></p>';
        echo '<p><button type="button" class="button" data-wd29-link-retry hidden style="display:none">Vérifier le lien</button></p>';
        echo '<noscript><p class="description">Activez JavaScript pour vérifier le lien vers la boutique partenaire.</p></noscript></div>';
    }

    public static function ajax(): void
    {
        nocache_headers();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_send_json(['ok' => false, 'error' => 'Méthode non autorisée.'], 405); return;
        }
        $raw = $_POST['product'] ?? null;
        if (!is_scalar($raw) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $raw)) {
            wp_send_json(['ok' => false, 'error' => 'Produit invalide.'], 400); return;
        }
        $id = (int) $raw;
        if (!current_user_can('edit_post', $id)) {
            wp_send_json(['ok' => false, 'error' => 'Accès refusé.'], 403); return;
        }
        $nonce = $_POST['nonce'] ?? null;
        if (!is_string($nonce) || strlen($nonce) > 128 || !wp_verify_nonce(wp_unslash($nonce), 'wd29_product_link_' . $id)) {
            wp_send_json(['ok' => false, 'error' => 'Session expirée. Actualisez la page.'], 403); return;
        }
        if (get_post_type($id) !== 'product') {
            wp_send_json(['ok' => false, 'error' => 'Produit indisponible.'], 404); return;
        }
        try { $result = ProductLinks::remote(\wd29_bridge(), $id); }
        catch (\Throwable $error) { $result = ['state' => 'unavailable', 'url' => '']; }
        wp_send_json(['ok' => true, 'data' => $result]);
    }
}
