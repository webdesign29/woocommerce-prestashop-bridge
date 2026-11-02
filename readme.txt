=== Inklura Sync ===
Tags: inventory, synchronization, prestashop, shopify, orders
Requires at least: 6.5
Tested up to: 7.1.3
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.8.13
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WooCommerce and PrestaShop, with optional Shopify products, stock, customers and order consultation.

== Description ==

Shopify is available as a separate GraphQL Admin API connection with audit/live modes. Import products and customers, update linked records and stock in both directions, create simple/variable products and customers from WooCommerce, and retain Shopify order copies for consultation. One inventory location is selected explicitly. Payment/refund processing and Shopify wallets remain owned by Shopify; the KerAwen balance is not yet exposed to Shopify checkout.


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

The Marketplace edition makes no Inklura license, entitlement or billing requests. The WooCommerce/PrestaShop pair sends selected commerce records to the explicitly configured partner using signed HTTPS messages. Native WooCommerce account/update services are handled by WooCommerce and its Update Manager.

When the optional Shopify connection is enabled, selected product, inventory, customer, order and promotion data is exchanged with the configured STORE.myshopify.com Admin GraphQL API (version 2026-10). Client-credentials mode obtains access tokens from that store's /admin/oauth/access_token endpoint; Shopify sends signed webhooks to this site's receiver. Optional image imports download approved Shopify CDN images. Optional initial product photo export sends public URLs for this site’s native uploads to Shopify for retrieval; it preserves already-mapped galleries. Optional account consultation verifies Shopify's signed app-proxy requests and reads the linked customer's KerAwen benefits through Commerce Pro and the configured PrestaShop partner. It does not create a Shopify wallet or enable checkout spending. See https://plugins.inklura.fr/docs/shopify and docs/SHOPIFY.md for scopes and setup. Disabling the connection preserves mappings and queued history.

The direct-sale edition sends its license key, platform, local store URL, configured partner URL and plugin version to https://plugins.inklura.fr for activation, daily verification and licensed updates. No synchronized catalog, order or customer records are sent to that license service.

== Changelog ==

= 0.8.13 =
* English-default WooCommerce administration with French translations; coordinated Marketplace companion.

= 0.8.12 =
Coordinated direct release with PrestaShop 0.8.12, Shopify catalogue/customer/inventory/photo sync and Commerce Pro transport. Optional features remain individually configured.

= 0.8.11 =
* Reuse native photo attachments using Shopify product-image identities rather than media image-source IDs.

= 0.8.10 =
* Defer webhooks for our own unfinished product/customer creations to preserve the original native identity.

= 0.8.9 =
* Copy public native product photos on initial Shopify creation, preserving existing galleries and recovering lost responses.

= 0.8.8 =
* Open Shopify settings from the main Sync navigation and plugin actions.
* Update Shopify setup, external-service disclosure and KerAwen integration scope.
* Keep the Marketplace package description in English after direct-header changes.

= 0.8.7 =
* Show Shopify fulfillment, refund and transaction history as source consultation data.
* Preserve physical/virtual shipping flags and guard unsupported gift-card, subscription and bundle products.

= 0.8.6 =
* Synchronize compatible unlimited promotion codes, hold incompatible entitlements and recover uncertain creation responses.
* Add a signed customer-scoped Shopify page for read-only KerAwen benefits.

= 0.8.5 =
* Initialize tag baselines correctly for newly exported tagged products.
* Defer media import when the worker cannot write the native upload directory.

= 0.8.4 =
* Upgrade unchanged legacy customer address baselines before accepting new edits.

= 0.8.3 =
* Create variable products with durable option identities and recover lost creation responses.
* Add configurable default-address export, tag sync, additive Shopify image and collection imports.

= 0.8.2 =
* Match product update error fields and preserve taxable flags in both directions.


= 0.8.1 =
* Correct the live-verified Shopify inventory comparison contract.


= 0.8.0 =
* Add Shopify connection with its own queue, inventory retry protection and native WooCommerce records.


= 0.7.11 =
* Signed commerce transport for the optional Commerce Pro authority and verified source-order proofs.

= 0.7.10 =
* Preserve registered customer identity on orders; protect native mirror updates.

= 0.7.5 =
* Prevent stale outgoing catalogue snapshots from undoing accepted imports.

= 0.7.4 =
Preserve native tax CRUD status while retrying fiscal events; tax-rate deletion succeeds with empty and waiting queues.

= 0.7.3 =
Clear native tax diagnostics, selective recovery after VAT settings changes, whole-decimal inventory support, and concurrent independent product creation coverage.

= 0.7.2 =
Added separate Woo Marketplace distribution, included PrestaShop companion, native Woo update ownership and subscription-independent installed synchronization. Direct-sale licensing and updates are preserved.

= 0.6.7 =
Added required-action notices and settings recovery for blocked synchronization.

== Frequently Asked Questions ==

= Does the WooCommerce Marketplace edition have a 400-product limit? =

No. The Marketplace edition has no built-in product-count cap and needs no separate Inklura license key. The 400-product Essentiel offer belongs to the separate direct-sale plan. Report display limits and batch sizes do not cap synchronization.

= Is the WooCommerce admin available in English? =

Yes, from 0.8.13. English is the default and a French translation is bundled. Native shop content is kept in its original language.
