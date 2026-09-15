# Explicit custom fields

Only configured fields synchronize. The bridge does not enumerate WordPress metadata or ACF groups. Do not allowlist authentication, payment credentials, permissions, or information that the destination should not hold.

A mapping has `id` (stable portable name), `source` (`meta` or `acf`), `key` (local meta key or local ACF field key/name), and optional `entities` (`product`, `variation`, `order`, `customer`). Existing mappings without `entities` retain their product + variation scope. IDs must be unique; a key can be reused in nonoverlapping entity scopes.

```json
[
  {"id":"care","source":"meta","key":"care_instructions","entities":["product","variation"]},
  {"id":"delivery_note","source":"meta","key":"delivery_note","entities":["order"]},
  {"id":"preferences","source":"acf","key":"field_local_preferences","entities":["customer"]}
]
```

Generic WooCommerce metadata uses native CRUD APIs. Order metadata supports HPOS. Customer metadata helpers require an existing registered user; they do not create or merge accounts, and guests have no user metadata. Native WooCommerce fields and reserved/sensitive metadata keys are rejected.

## ACF

Supported local schemas: text, textarea, number, range, email, URL, boolean, select, checkbox, radio, button group, date, date-time, time, color, and nested groups/repeaters of those types. Repeaters require ACF PRO installed on the WordPress site. Group/repeater payloads use subfield **names**, never portable numeric IDs or remote ACF field keys. Applying values uses the local ACF schema and native `update_field`/`delete_field`, including child references and deletion.

Every group or repeater row must contain its complete set of configured subfields. Unknown or missing subfields are rejected; partial rows do not silently erase siblings. Mapping a group containing unsupported subfields rejects the group. Image/gallery fields and relationship/post-object fields are supported in product and variation scopes through explicit identity resolvers. Images transport HTTPS URLs on the WooCommerce or configured peer host; galleries require ACF PRO. Relationships accept already synchronized nonvariation WooCommerce products and transport canonical bridge product keys. Native post IDs and attachment IDs never cross sites. Unmapped products and noncatalog posts are rejected. File, taxonomy, user, clone, and flexible-content fields still require dedicated identity/schema adapters and are not supported. ACF order mappings are rejected because the generic ACF post-meta API is not a verified HPOS adapter; explicitly selected Woo order metadata remains supported.

`present: false` is an explicit deletion. Omitting a mapped field leaves its current value intact. Values are bounded to 64 KiB and eight nested JSON levels. The complete incoming set is validated before custom-field writes begin. Image resolver callbacks can create attachments during identity resolution; adapters must apply their existing import transaction and media-cleanup policy.

## Adapter integration

- `CustomFields::export($object, $entity = null, $resolvers = [])` returns selected envelopes; Woo product, variation, order and customer types are detected.
- `CustomFields::apply($object, $fields, $entity = null, $resolvers = [])` stages generic CRUD metadata; the caller must save it. ACF calls write through native ACF APIs immediately, so the native object must already have an ID and these calls belong inside the import transaction.
- `CustomFields::exportCustomer($userId)` / `applyCustomer($userId, $fields)` use an existing `WC_Customer`, with ACF `user_ID` context. Never use these with an unverified remote user ID: the adapter must resolve its local identity first.
- Optional resolver array: `productKey(int $localId): string` returns the already mapped canonical product key; `productId(string $key): int` resolves the local mapped product; `imageId(string $url): int` imports an image using the adapter’s host-pinned, size/MIME-checked importer; `peerHost` is the configured peer hostname. Own-host image URLs first resolve to existing local attachments without downloading. Resolver results are validated against native WooCommerce products/image attachments. Callbacks must not create or merge products implicitly. Resolvers propagate through nested groups/repeaters.
- Existing mapped fields require envelope preservation in PrestaShop. These envelopes do not automatically create a native PrestaShop field editor or a KerAwen field mapping.

## Verification

`tests/custom-fields-extended.php` runs only against the disposable `wd29woo` / `woo.example.test` fixture. It tests real HPOS order meta, registered-customer meta and ACF references, nested ACF groups and tombstones, entity scoping, reserved keys, validation before mutation, and recursive repeater name/key conversion. It temporarily synchronizes fixture order tables and restores the original HPOS setting. Native image and product-relationship storage are tested using synthetic local attachments and mapped products, without network requests. Unsafe image hosts, unresolved product identities and noncatalog relationships must fail. Native repeater/gallery storage is tested only when the fixture has ACF PRO; otherwise it explicitly reports a skip while recursive/URL translation is still tested. This does not assert validation against live ACF fields or KerAwen private schemas.
