<?php
namespace WD29\Bridge;

/** Translate only plugin-authored literals; never transform merchant data or rendered HTML. */
final class I18n
{
    public static function t(string $message): string
    {
        // Shared domain classes also run in standalone workers and regression fixtures.
        return function_exists('__') ? __($message, 'inklura-sync') : $message;
    }

    public static function register(string $pluginFile): void
    {
        add_action('init', static function () use ($pluginFile) {
            load_plugin_textdomain('inklura-sync', false, dirname(plugin_basename($pluginFile)) . '/languages');
        }, 0);
        add_action('admin_enqueue_scripts', static function () use ($pluginFile) {
            if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) { return; }
            wp_enqueue_script('inklura-sync-i18n', plugins_url('includes/i18n.js', $pluginFile), [], WD29_BRIDGE_VERSION, false);
            wp_localize_script('inklura-sync-i18n', 'InkluraSyncMessages', require __DIR__ . '/js-messages.php');
        });
    }
}
