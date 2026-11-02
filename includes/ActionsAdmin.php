<?php
namespace WD29\Bridge;
require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/AdminDesign.php';

/** Decisions and settings blocking synchronization: banner with one-click fixes, host-admin notice and handlers. */
final class ActionsAdmin
{
    private static function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

    /** Banner shown on every connector page; AdminDesign keeps alerts above the navigation. */
    public static function render(Engine $engine, string $platform, string $token): string
    {
        try { $actions = $engine->actionsNeeded(); } catch (\Throwable $e) { return ''; }
        if (!$actions) { return ''; }
        $error = false; foreach ($actions as $action) { $error = $error || $action['severity'] === 'error'; }
        $class = $platform === 'ps' ? 'alert alert-' . ($error ? 'danger' : 'warning') : 'notice notice-' . ($error ? 'error' : 'warning');
        $html = '<div id="wd-actions" class="' . $class . ' wd-actions"><p><strong>Inklura Sync : ' . count($actions) . \WD29\Bridge\I18n::t(' action(s) required for synchronization to continue.</strong></p>');
        foreach ($actions as $action) {
            $html .= '<div class="wd-action" data-action-code="' . self::e($action['code']) . '"><p><strong>' . self::e($action['title']) . '.</strong> ' . self::e($action['text']) . '</p>';
            $form = self::form($action, $platform);
            $link = '<a href="' . self::e(self::actionUrl($action, $platform, AdminDesign::viewUrl($action['view']))) . '">' . ($action['view'] === 'settings' ? \WD29\Bridge\I18n::t('Open settings') : \WD29\Bridge\I18n::t('View activity')) . '</a>';
            $html .= $form !== '' ? '<form method="post">' . $token . $form . ' ' . $link . '</form>' : '<p>' . $link . '</p>';
            $html .= '</div>';
        }
        return $html . '</div>';
    }

    /** Native tax settings resolve these errors; connector settings do not enable WooCommerce VAT. */
    private static function actionUrl(array $action, string $platform, string $fallback): string
    {
        if ($platform === 'woo' && $action['code'] === 'woo_taxes_disabled') { return 'admin.php?page=wc-settings&tab=general#woocommerce_calc_taxes'; }
        if ($platform === 'woo' && $action['code'] === 'woo_tax_class') { return 'admin.php?page=wc-settings&tab=tax'; }
        return $fallback;
    }

    private static function form(array $action, string $platform): string
    {
        $local = $platform === 'ps' ? 'ps' : 'woo';
        switch ($action['decision']) {
            case 'tax_rate':
                $suggest = $action['suggest'] ?? null;
                return \WD29\Bridge\I18n::t('<label>Applied rate <input name="decision_tax_rate" inputmode="decimal" size="5" required style="display:inline-block;width:6em" value="') . self::e($suggest ?? '') . '"> %</label> '
                    . \WD29\Bridge\I18n::t('<label>Ces prix sont saisis <select name="decision_tax_basis" style="display:inline-block;width:auto"><option value="gross">Incl. tax</option><option value="net">Excl. tax</option></select></label> ')
                    . ($suggest !== null ? '<small>Suggestion : ' . self::e($suggest) . \WD29\Bridge\I18n::t(' %, most common catalogue rate.</small> ') : '')
                    . \WD29\Bridge\I18n::t('<button class="btn-primary button-primary" name="bridge_action" value="decide_tax_rate">Confirm and retry</button>');
            case 'conflict_policy':
                $out = '';
                foreach ([$local, $local === 'ps' ? 'woo' : 'ps'] as $side) {
                    $out .= '<button class="' . ($side === $local ? 'btn-primary button-primary' : '') . '" name="bridge_action" value="decide_policy_' . $side . \WD29\Bridge\I18n::t('">Priority: ') . self::e($side === 'ps' ? 'PrestaShop' : 'WooCommerce') . '</button> ';
                }
                return $out;
            case 'resolve_catalog':
                return \WD29\Bridge\I18n::t('<button class="btn-primary button-primary" name="bridge_action" value="decide_resolve_catalog">Retry conflicts</button>');
            case 'retry':
                return \WD29\Bridge\I18n::t('<button class="btn-primary button-primary" name="bridge_action" value="decide_retry">Retry failures</button>');
        }
        return '';
    }

    /** Handles the banner's buttons; null when the action is not one of them. */
    public static function handle(Engine $engine, string $action, array $post): ?string
    {
        if ($action === 'decide_tax_rate') { return $engine->decide('tax_rate', ['rate' => (string)($post['decision_tax_rate'] ?? ''), 'basis' => (string)($post['decision_tax_basis'] ?? 'gross')]); }
        if ($action === 'decide_policy_ps' || $action === 'decide_policy_woo') { return $engine->decide('conflict_policy', ['policy' => substr($action, 14)]); }
        if ($action === 'decide_resolve_catalog') { return $engine->decide('resolve_catalog', []); }
        if ($action === 'decide_retry') { return $engine->decide('retry', []); }
        return null;
    }

    /** One-line notice for the rest of the back office, linking to the page where the action is done. */
    public static function notice(Engine $engine, string $platform, callable $url): string
    {
        try { $actions = $engine->actionsNeeded(); } catch (\Throwable $e) { return ''; }
        if (!$actions) { return ''; }
        $first = $actions[0]; $error = false; foreach ($actions as $action) { $error = $error || $action['severity'] === 'error'; }
        $more = count($actions) > 1 ? ' (+' . (count($actions) - 1) . ' autre' . (count($actions) > 2 ? 's' : '') . ')' : '';
        $text = '<strong>Inklura Sync : ' . self::e($first['title']) . '.</strong> ' . self::e($first['text']) . $more . ' <a href="' . self::e(self::actionUrl($first, $platform, $url($first['view']) . '#wd-actions')) . '">Traiter maintenant</a>';
        return $platform === 'ps'
            ? '<div class="alert alert-' . ($error ? 'danger' : 'warning') . '" id="wd29-actions-alert" style="margin:16px 0">' . $text . '</div>'
            : '<div class="notice notice-' . ($error ? 'error' : 'warning') . '"><p>' . $text . '</p></div>';
    }
}
