# WooCommerce Marketplace edition

The Marketplace archive uses the immutable `woo-marketplace` distribution channel. It includes the matching PrestaShop companion and requires no separate Inklura account, subscription or license key. WooCommerce.com manages purchase, renewals, support and updates. Expiry does not stop installed synchronization.

There is no built-in product-count cap in this edition. The 400-product Essentiel offer on plugins.inklura.fr belongs to the separate direct-sale plan. A report can display the latest 200 records and capture tools process batches of 10; neither is a catalogue-size entitlement limit. Practical throughput still depends on the hosting, worker schedule and connected platform.

The WooCommerce admin defaults to English in 0.8.13, including settings, comparison panels, diagnostics and JavaScript messages. French users retain French labels through the bundled `fr_FR` gettext catalogue. Store content, native order labels, protocol identities, license headers and credentials are not translated. The PrestaShop companion's existing interface is unchanged in this release.

Plugin-authored literals use `I18n::t`; shared standalone classes fall back to English when WordPress gettext is unavailable. JavaScript receives only a message catalogue through `wp_localize_script`. Run `python3 scripts/update-translations.py` after editing literal messages and maintain `languages/inklura-sync-fr_FR.po`.

The native regression checks render every main admin view in English and French, retain merchant-supplied French content unchanged, verify ownership badges and nonces, and exercise 405 native products. The compiled archive must include the language files, `includes/i18n.js` and the matching companion ZIP.

Marketplace publication and WordPress.org directory approval are independent external reviews. A successful local test or uploaded archive does not imply either approval.
