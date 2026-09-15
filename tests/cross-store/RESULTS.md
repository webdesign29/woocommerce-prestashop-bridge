# Results — 15 September 2026

Latest successful run: `run-1789482315405606510`.

| Stage | Woo quantity | PrestaShop quantity | Expected remote PS ledger entries |
|---|---:|---:|---:|
| Initial synchronized product | 20 | 20 | 0 |
| Concurrent Woo checkout and PS native movement before exchange | 18 | 17 | 0 |
| Exchange movements and mirror native order | 15 | 15 | 1 |
| Replay identical signed events | 15 | 15 | 1 |
| Cancel/restock source Woo order; replay mirrored cancellation | 17 | 17 | 2 |
| PS native restoration and replay | 20 | 20 | 2 |

Source and mirrored order totals match (24 EUR), each has one linked line, and the order mirror does not move stock again. Calling Woo stock reduction/restoration twice respects Woo's native flags. Concurrent source operations run in separate PHP processes before either receives peer events.

## Bug found and corrected

The initial run exposed a PrestaShop worker/webhook stock failure that the earlier suite masked by setting employee 1. `StockAvailable::updateQuantity(..., true)` writes a native stock movement whose `id_employee` cannot be NULL. The bridge now supplies an unsaved system actor (ID 0, WD29 Bridge) only when no logged-in employee exists, and restores the original Context object in `finally`. No employee account is created and no staff identity is impersonated.

The final test explicitly checks Context identity restoration and that native bridge movement records have ID 0 and the system label. Replays do not duplicate native movement records.

The native PrestaShop movement is a substitute for the unavailable commercial KerAwen module. HTTPS transport, browser checkout interaction, payment gateway calls and real KerAwen sales/returns are outside this fixture's coverage.
