# Cross-store stock and order fixture

Run only after the standalone WooCommerce and PrestaShop integration suites have finished. Those suites truncate bridge maps; this test deliberately does not. It uses the two disposable databases `wd29woo` and `wd29ps`, refuses other configured store hosts, and creates a fresh Woo product and source order for each run.

```sh
python3 /home/pc1/dev/bridge-e2e-tests/run.py
```

The local MySQL fixture must be running at `/tmp/wd29-bridge-tests/mysql.sock`; both bridge extensions must be installed/enabled in their fixture platforms. The fixture environment blocks outbound mail and network requests. No external store is contacted.

The test starts with 20 units on both stores. Two separate PHP processes perform a native Woo checkout for two units and a native PrestaShop stock movement of minus three before either receives the other's events. Signed JSON bundles pass through the real protocol verifier, durable receiver and native event application. Both stores must converge on 15 units. It checks the native mirrored order has matching total and linked lines, replays the bundles, then cancels/restocks the actual Woo source order (17 units), and applies/replays a native PrestaShop restoration (20 units).

Scope limits:

- PrestaShop's native `StockAvailable::updateQuantity` is a substitute for the unavailable commercial KerAwen module. This is **not a KerAwen sale, return or cash-register test**.
- Signed file bundles exercise signature verification, durable receipt and application; they do not exercise HTTPS, DNS, webserver authentication headers or a real payment gateway.
- Woo's native order API/status transition and stock handling are exercised; this is not a browser checkout UX test.
- Fresh fixture products/orders and the private `run-*` output directories are retained for inspection. This test does not truncate shared maps or delete unrelated records.
