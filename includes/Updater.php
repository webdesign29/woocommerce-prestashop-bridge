<?php
/** WordPress update channel for the licensed plugin (plugins.inklura.fr). GPL-2.0-or-later. */
namespace WD29\Bridge;

/**
 * - The manifest is fetched at most every 12 hours (1 hour after a failure), and only when
 *   WordPress itself refreshes plugin updates; the engine is not built before that.
 * - The package URL in the manifest expires after 15 minutes, so the update transient
 *   holds a marker instead; the real link is fetched when the update actually runs, and
 *   the archive's SHA-256 is checked against the manifest before WordPress installs it.
 * - Without a usable licence the update is listed but cannot be installed automatically.
 */
final class Updater
{
    const SLUG = 'woocommerce-prestashop-bridge';
    const CACHE = 'wd29_bridge_update';
    const MARKER = 'https://plugins.inklura.fr/wd29-bridge-package/';

    private $engine;
    private $file;

    /** @param callable $engine returns the Engine; resolved only when WordPress refreshes updates. */
    public function __construct(callable $engine, string $file) { $this->engine = $engine; $this->file = plugin_basename($file); }

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject']);
        add_filter('plugins_api', [$this, 'details'], 10, 3);
        add_filter('upgrader_pre_download', [$this, 'download'], 10, 3);
        add_action('upgrader_process_complete', function () { delete_site_transient(self::CACHE); });
    }

    public static function forget(): void { delete_site_transient(self::CACHE); delete_site_transient('update_plugins'); }

    /** @return array|null manifest, null when unavailable */
    public function manifest(bool $fresh = false): ?array
    {
        if (!$fresh) {
            $cached = get_site_transient(self::CACHE);
            if (is_array($cached)) { return $cached['manifest'] ?? null; }
        }
        $headers = ($this->engine)()->licence()->downloadHeaders() + ['Accept' => 'application/json'];
        $response = wp_remote_get(Licence::server() . '/api/update?slug=' . self::SLUG, ['timeout' => 8, 'redirection' => 0, 'headers' => $headers]);
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = !is_wp_error($response) && $code === 200 ? json_decode((string) wp_remote_retrieve_body($response), true) : null;
        $ok = is_array($data) && isset($data['version']) && preg_match('/^[0-9][0-9A-Za-z.+-]{0,39}$/D', (string) $data['version']);
        if (!$fresh) { set_site_transient(self::CACHE, ['manifest' => $ok ? $data : null], $ok ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS); }
        return $ok ? $data : null;
    }

    public function inject($transient)
    {
        if (!is_object($transient) || empty($transient->checked)) { return $transient; }
        $m = $this->manifest();
        if (!$m) { return $transient; }
        $installed = (string) ($transient->checked[$this->file] ?? WD29_BRIDGE_VERSION);
        $item = (object) [
            'id' => self::SLUG, 'slug' => self::SLUG, 'plugin' => $this->file, 'new_version' => (string) $m['version'],
            'url' => (string) ($m['homepage'] ?? ''), 'tested' => (string) ($m['tested'] ?? ''), 'requires_php' => (string) ($m['requires_php'] ?? ''),
            'package' => !empty($m['package']) ? self::MARKER . rawurlencode((string) $m['version']) : '',
            'upgrade_notice' => empty($m['package']) ? 'Licence active requise pour la mise à jour automatique (plugins.inklura.fr/compte).' : '',
        ];
        if (version_compare((string) $m['version'], $installed, '>')) { $transient->response[$this->file] = $item; unset($transient->no_update[$this->file]); }
        else { $transient->no_update[$this->file] = $item; unset($transient->response[$this->file]); }
        return $transient;
    }

    public function details($result, $action, $args)
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== self::SLUG) { return $result; }
        $m = $this->manifest();
        if (!$m) { return $result; }
        return (object) [
            'name' => (string) ($m['name'] ?? 'Inklura Sync'), 'slug' => self::SLUG, 'version' => (string) $m['version'],
            'author' => (string) ($m['author'] ?? ''), 'homepage' => (string) ($m['homepage'] ?? ''), 'requires' => (string) ($m['requires'] ?? ''),
            'tested' => (string) ($m['tested'] ?? ''), 'requires_php' => (string) ($m['requires_php'] ?? ''), 'last_updated' => (string) ($m['last_updated'] ?? ''),
            'sections' => array_map('wp_kses_post', (array) ($m['sections'] ?? [])), 'download_link' => '',
        ];
    }

    public function download($reply, $package, $upgrader)
    {
        if (!is_string($package) || strpos($package, self::MARKER) !== 0) { return $reply; }
        $m = $this->manifest(true);
        if (!$m || empty($m['package']) || !preg_match('/^[a-f0-9]{64}$/D', (string) ($m['sha256'] ?? ''))) {
            return new \WP_Error('wd29_licence', 'Mise à jour indisponible : licence inactive ou serveur injoignable. Téléchargez l\'archive depuis plugins.inklura.fr/compte.');
        }
        if (strpos((string) $m['package'], Licence::server() . '/') !== 0) { return new \WP_Error('wd29_package', 'Adresse de paquet inattendue.'); }
        $file = download_url((string) $m['package'], 120);
        if (is_wp_error($file)) { return $file; }
        if (!hash_equals((string) $m['sha256'], (string) hash_file('sha256', $file))) {
            @unlink($file);
            return new \WP_Error('wd29_checksum', 'Empreinte SHA-256 de l\'archive incorrecte : mise à jour annulée.');
        }
        return $file;
    }
}
