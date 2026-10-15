<?php
/** An older direct edition may already have loaded its global functions. */
define('ABSPATH', __DIR__);
define('WD29_BRIDGE_VERSION', '0.6.7');
function wd29_bridge() { return 'older-edition'; }
function wd29_bridge_capture_later($kind, $id) {}
function wd29_bridge_admin() {}
$activation = null; $hooks = [];
function register_activation_hook($file, $handler) { global $activation; $activation = $handler; }
function add_action($name, $handler) { global $hooks; $hooks[] = $name; }
function wp_die($message) { throw new DomainException($message); }
require $argv[1];
if (wd29_bridge() !== 'older-edition' || WD29_BRIDGE_VERSION !== '0.6.7' || $hooks !== ['admin_notices'] || !is_callable($activation)) {
    throw new RuntimeException('The other edition was overwritten or bootstrapped');
}
try { $activation(); throw new RuntimeException('Second edition could activate'); }
catch (DomainException $expected) {
    if (strpos($expected->getMessage(), 'Deactivate') === false) { throw $expected; }
}
echo "PASS: loading alongside the older direct-sale edition does not redeclare functions or replace its engine, and rejects dual activation\n";
