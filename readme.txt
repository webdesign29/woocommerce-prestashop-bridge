=== Inklura Sync ===
Tags: inventory, synchronization, prestashop, orders
Requires at least: 6.5
Tested up to: 7.1.3
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.7.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync products, stock movements, orders and customer records directly between WooCommerce and PrestaShop.

== Description ==

Inklura Sync pairs one WooCommerce store with one PrestaShop store. Signed HTTPS webhooks exchange selected commerce records directly. Audit mode lets administrators review pending changes before enabling live synchronization. Durable queues, retries and conflict reports support ongoing operation.

Payments and financial refunds remain on the originating store. Mirrored records never execute a second payment, and mirrored orders do not duplicate stock reductions. Asynchronous stock movements cannot provide atomic last-unit reservations across simultaneous checkouts.

The Woo Marketplace edition includes the PrestaShop companion and requires no separate Inklura license key or subscription. WooCommerce.com manages annual updates and support. Installed synchronization remains functional after subscription expiry. Download the companion from the Licence & mises a jour tab after each connector update, then update the module using PrestaShop's Module Manager.

The direct-sale edition retains its signed Inklura license and existing update channel. Distribution is fixed during packaging and is not a store setting.

The current admin UI is French. An English guide explains the visible labels at https://plugins.inklura.fr/en/docs/licence. KerAwen is not certified. Packs, multistore, private POS fields and complex custom-field configurations need separate validation.

== Installation ==

The Woo Marketplace edition currently requires WordPress 7.0+ and WooCommerce 11.2.1+. It has been tested on WordPress 7.1.3, WooCommerce 11.2.1 and PHP 8.3, with both legacy order storage and HPOS without compatibility synchronization. Earlier Woo versions are not claimed for this new distribution yet. The direct-sale edition retains its existing requirements.

1. Install and activate WooCommerce, then upload and activate the Inklura Sync ZIP in Plugins > Add New > Upload Plugin.
2. In WooCommerce > Inklura Sync, open Licence & mises a jour and download the bundled PrestaShop companion.
3. Install wd29woobridge.zip in PrestaShop 8.2 or 9.1 using Modules > Module Manager > Upload a module. Preserve its wd29woobridge folder name.
4. On each store, copy the partner's webhook URL into the connector settings and enter the same newly generated shared secret. Start in audit mode.
5. Confirm tax rates, field selection, order status mapping and catalog conflict policy, then test the signed connection in both directions.
6. Compare synthetic records and examine the reports. Install each host's minute worker using OPERATIONS.md.
7. Enable live mode on the test pair and verify a product edit, stock movement, synthetic order and replay handling before using real stores.

If switching from the direct-sale edition, deactivate that plugin before activating the Marketplace edition. Do not uninstall it. Settings, shared secrets, mappings, stored direct-sale keys and history are reused without deletion. Use the companion from the same Marketplace release on PrestaShop, and verify both stores in audit mode before resuming live operation.

== External services and data ==

The Marketplace edition makes no Inklura license, entitlement or billing requests. Commerce records are transmitted only to the explicitly configured partner store, using signed HTTPS messages. Native WooCommerce account/update services are handled by WooCommerce and its Update Manager.

The direct-sale edition sends its license key, platform, local store URL, configured partner URL and plugin version to https://plugins.inklura.fr for activation, daily verification and licensed updates. No synchronized catalog, order or customer records are sent to that license service.

== Changelog ==

= 0.7.0 =
Added separate Woo Marketplace distribution, included PrestaShop companion, native Woo update ownership and subscription-independent installed synchronization. Direct-sale licensing and updates are preserved.

= 0.6.7 =
Added required-action notices and settings recovery for blocked synchronization.
