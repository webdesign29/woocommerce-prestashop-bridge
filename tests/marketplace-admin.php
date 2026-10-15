<?php
/** Security and update-channel behavior with WordPress boundary stubs. */
$root = $argv[1] ?? '';
require $root . '/includes/Distribution.php';
require $root . '/includes/MarketplaceAdmin.php';
require $root . '/includes/Updater.php';
$capable = false; $nonceValid = false; $nonceCalls = 0; $hooks = [];
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function add_action($name, $handler) { global $hooks; $hooks[] = $name; }
function add_filter($name, $handler, ...$extra) { global $hooks; $hooks[] = $name; }
function current_user_can($cap) { global $capable; check($cap === 'manage_options', 'Wrong download capability'); return $capable; }
function check_admin_referer($action) { global $nonceValid, $nonceCalls; $nonceCalls++; check($action === 'inklura_sync_companion', 'Wrong nonce action'); if (!$nonceValid) { throw new DomainException('invalid nonce'); } }
function wp_die($message, $title = '', $args = []) { throw new DomainException((string) ($args['response'] ?? 500)); }
function esc_html__($value, $domain) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_url($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function admin_url($path) { return 'https://woo.example.test/wp-admin/' . $path; }
function wp_nonce_url($url, $action) { return $url . '&_wpnonce=fixture'; }
function plugin_basename($file) { return 'inklura-sync/inklura-sync.php'; }
use WD29\Bridge\MarketplaceAdmin;
use WD29\Bridge\Updater;
MarketplaceAdmin::register();
check($hooks === ['admin_post_inklura_sync_companion'], 'Companion download exposed anonymously or wrong hook');
$updater = new Updater(function () { throw new RuntimeException('Update channel constructed the sync engine'); }, $root . '/inklura-sync.php');
$updater->register(); check($hooks === ['admin_post_inklura_sync_companion'], 'Marketplace registered competing updater hooks');
check($updater->manifest(true) === null, 'Marketplace fetched external manifest');
try { MarketplaceAdmin::download(); throw new RuntimeException('Unauthorized download accepted'); } catch (DomainException $e) { check($e->getMessage() === '403', 'Wrong denied status'); }
check($nonceCalls === 0, 'Unauthorized download reached nonce/archive handling');
$capable = true;
try { MarketplaceAdmin::download(); throw new RuntimeException('Invalid nonce accepted'); } catch (DomainException $e) { check($e->getMessage() === 'invalid nonce', 'Wrong nonce failure'); }
$html = MarketplaceAdmin::panel();
check(strpos($html, 'admin-post.php?action=inklura_sync_companion') !== false && strpos($html, '_wpnonce=fixture') !== false, 'Download does not use the protected endpoint');
check(strpos($html, 'plugins.inklura.fr') === false, 'Marketplace points to additional purchase/license service');
check(is_file($root . '/companion/wd29woobridge.zip'), 'Companion not included');
echo "PASS: authorized local companion download requires a nonce; no anonymous endpoint or competing updater\n";
