<?php
/** Licence panel, shared by both plugins. The platform supplies its own form token. GPL-2.0-or-later. */
namespace WD29\Bridge;
require_once __DIR__ . '/I18n.php';

final class LicenceAdmin
{
    private static function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

    /** Handle a licence form action. Returns the message to display, or null if not a licence action. */
    public static function handle(Engine $engine, string $action, string $key): ?string
    {
        $licence = $engine->licence();
        if (Distribution::isMarketplace() && in_array($action, ['licence_save', 'licence_check', 'licence_remove'], true)) {
            return $licence->summary()['text'];
        }
        if ($action === 'licence_save') { $licence->setKey($key); return $licence->summary()['text'] ?: \WD29\Bridge\I18n::t('Key saved.'); }
        if ($action === 'licence_check') { $licence->check('check'); $s = $licence->summary(); return $s['error'] !== '' ? $s['error'] : ($s['text'] ?: \WD29\Bridge\I18n::t('License checked.')); }
        if ($action === 'licence_remove') { return (string) ($licence->removeKey()['message'] ?? \WD29\Bridge\I18n::t('Key removed.')); }
        return null;
    }

    /** @param string $hidden platform form token fields; $update extra HTML for the update row (platform specific) */
    public static function render(Engine $engine, string $hidden, string $update = ''): string
    {
        $s = $engine->licence()->summary();
        if (Distribution::isMarketplace()) {
            // Preserve the section key used by the shared admin layout.
            return \WD29\Bridge\I18n::t('<h2>License</h2><div class="wd-licence"><p><strong>') . self::e($s['label']) . '</strong></p>'
                . '<p>' . self::e($s['text']) . '</p>'
                . '<p>Manage purchase, renewals and support in your <a href="https://woocommerce.com/my-account/my-subscriptions/" target="_blank" rel="noopener noreferrer">WooCommerce.com account</a>.</p>'
                . ($engine->adapter->site() === 'ps' ? '<p>Update this companion module using the archive included with your WooCommerce connector. No separate PrestaShop subscription is required.</p>' : '')
                . $update . '</div>';
        }
        $configured = $engine->config()['mode'] ?? 'disabled';
        $state = ['ok' => 'wd-state-good', 'warn' => 'wd-state-warn', 'bad' => 'wd-state-error'][$s['tone']];
        $html = \WD29\Bridge\I18n::t('<h2>License</h2><div class="wd-licence"><p><span class="wd-state ') . $state . '">' . self::e($s['label']) . '</span> ' . self::e($s['text']) . '</p>';
        if ($configured === 'live' && !$s['live']) { $html .= \WD29\Bridge\I18n::t('<p class="wd-licence-alert">Live mode is configured but paused by the license: this store is running in audit mode.</p>'); }
        $rows = array_filter([\WD29\Bridge\I18n::t('Key') => $s['hint'], \WD29\Bridge\I18n::t('Plan') => $s['plan'] ? ucfirst($s['plan']) : '', \WD29\Bridge\I18n::t('Pairs in use') => $s['pairs'], \WD29\Bridge\I18n::t('Expiry') => $s['expires'], \WD29\Bridge\I18n::t('Last check') => $s['checked']]);
        if ($rows) {
            $html .= '<table class="widefat table wd-licence-facts"><tbody>';
            foreach ($rows as $label => $value) { $html .= '<tr><th>' . self::e($label) . '</th><td>' . self::e($value) . '</td></tr>'; }
            $html .= '</tbody></table>';
        }
        if ($s['error'] !== '') { $html .= '<p><small>Dernier essai : ' . self::e($s['error']) . ' Nouvel essai automatique ; rien ne change en attendant.</small></p>'; }
        $html .= $update;
        $html .= '<form method="post">' . $hidden . \WD29\Bridge\I18n::t('<p><label>License key <input name="licence_key" autocomplete="off" spellcheck="false" placeholder="WD29-XXXX-XXXX-XXXX-XXXX" value=""></label></p>')
            . '<button name="bridge_action" value="licence_save">' . ($s['hint'] !== '' ? \WD29\Bridge\I18n::t('Replace key') : \WD29\Bridge\I18n::t('Save key')) . '</button>';
        if ($s['hint'] !== '') {
            $html .= \WD29\Bridge\I18n::t('<button name="bridge_action" value="licence_check">Check now</button>')
                . \WD29\Bridge\I18n::t('<button name="bridge_action" value="licence_remove" onclick="return confirm(\'Release the pair for this store and remove the key?\')">Remove key from this store</button>');
        }
        $html .= \WD29\Bridge\I18n::t('</form><p><small>Only the key, store type, store URL, partner store URL and module version are sent to plugins.inklura.fr once a day. No catalogue, order or customer data is sent.</small></p></div>');
        return $html;
    }
}
