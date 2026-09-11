# W14 Fleet IT delivery — actual worker verification

12 September 2026. W14 / F06 / B04 / E13. This concurrency slice is locally verified. Full W14/E13 and release acceptance remain incomplete.

Added `tests/Concurrency/It/ItFleetDeliveryConcurrencyTest.php` and extended `tests/Support/It/monitoring-delivery-concurrency-worker.php` with an explicit allowlisted Fleet source argument. The default native Device path remains unchanged. Both use the same guarded disposable-schema worker harness, real database commits and independent PHP processes; no application code changed in this slice.

The Fleet fixtures use real telemetry ingestion, offline detection and acknowledged source jobs. For both High operational routing and Medium direct IT routing, workers exercise:

- A competing delivery while the first holds the outbox claim; only one ticket, source link set, checksum-valid v3 snapshot and completion audit commit.
- Termination after an uncommitted ticket insertion; the other worker completes the real delivery with no visible partial record or duplicate.
- Termination after commit; replay preserves the committed ticket and outcome without a second completion audit.
- Two simultaneous operator retry requests against an exhausted IT outcome; one wins, one rejects, lifetime attempts remain3 and the allowance becomes4. One retry audit and one successful delivery result persist.
- Separate offline/recovery outboxes contending while the offline worker holds an uncommitted ticket. Both complete; one exact recovery event remains, the actual Fleet source is preserved, and the ticket stays Open for verification.

## Actual runs and cleanup

1. `w14-fleet-it-worker-tests.txt`, token `it_ae1ea6db05104e66`: stopped before database initialization with a duplicate import in the new test (Pest255, wrapper1). Corrected the duplicate; both PHP syntax checks passed. All14 pre/postflight checks confirmed isolation and schema absence.
2. `w14-fleet-it-worker-final-tests.txt`, session32434, token `it_5f29b16d2daf4d6b`: Fleet case **passed330 assertions /189.27s**. The following native class refused combined execution at its explicit standalone guard. Aggregate **1passed/1failed /330 assertions /189.53s**, Pest2, wrapper terminal1. This is not a green combined suite. All14 cleanup checks passed and its exact schema was absent.
3. `w14-fleet-it-native-worker-regression.txt`, session3838, token `it_3f24fbc4b9eb405b`: native regression run separately as required, **1passed /272 assertions /192.90s**, wrapper terminal0. All14 cleanup checks passed and its exact schema was absent. No isolation guard was relaxed to obtain this pass.

Workers and owned barrier files are cleaned in each test's finally block. No active test/import remains. Run these standalone concurrency classes in separate wrapper invocations in future; do not combine them. The Fleet pass is retained as actual case evidence despite the unrelated following class's invocation failure.

Final Pint check and source whitespace check passed. The two test/worker hashes are in `w14-fleet-it-worker-source-hashes.json`; all28 application/test/browser-fixture hashes from the preceding intake slice still match. All10 protected design hashes match (`w14-fleet-it-worker-design-check.json`). No application, asset or browser-fixture edits occurred, so the existing current-build desktop proof remains applicable; no additional browser journey is claimed for this test-only change.

Working database and migrations17–29 remain untouched. No real communications, live provider changes, deployment or production AI. Next: operator create/link-existing handoff through canonical intake/link/receipt services, then delivery-failure/retry UI and remaining source coverage. Focused handoff findings: `w14-operator-handoff-current-gaps.md`.
