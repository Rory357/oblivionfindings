# W11 deletion audit failure and browser file verification

11 September2026. Backend audit-failure continuation verified. Full W11/E05/E10 remain incomplete.

Final status: superseded by `w11-attachment-browser-results.md` for the completed browser journey, additional safe-guidance fix/tests, current assets, exact teardown and remaining download-tool limitation. Bootstrap75833 completed; runtime724533b40323406b was removed by cleanup40158 with independent postflight. No active handles remain. The active checkpoint at the bottom records an earlier point in this same turn.

`ItInboundAttachmentStaging::cleanupPending` now reports a required audit/transaction failure per file and continues the remaining selected batch. It attempts to preserve bounded failure timing in a separate transaction after rollback, only while the current receipt remains accepted and the file remains explicitly pending. It neither promotes unknown ownership nor reports failed/unavailable evidence as deleted. A physically removed copy is completed idempotently on a later confirmed-absence retry. The existing runbook explains the result.

Actual verification:

- Command standalone87493 terminal0, `it_b853404eee5740df`:1test74assertions170s. Two accepted copies: first completion audit throws after physical deletion; first stays pending with `cleanup_record_failed`/attempt1, neighbour completes, command records partial counts. Retry completes the first once; another pass creates no duplicate audit. Final canonical bytes, quarantine and unknown reservations remain.
- Staging standalone4896 terminal0, `it_106dc9d5e53d4710`:1test86assertions181s. Updated existing audit-failure contract plus full private staging/scanning/commit/rollback coverage. Both runs all14postflight checks; exact schemas absent. No live provider/scanner acceptance claimed.
- Focused Pint passed; two fixture helper syntax checks and diff check passed. Fourteen current source hashes and ten unchanged protected design files recorded by `w11-cleanup-audit-failure-checkpoint.cjs` in `w11-cleanup-audit-failure-source-hashes.json`. Previous slice hashes remain historical.

Browser fixtures are implemented, not yet verified: existing opt-in mailbox helper now43messages/provider,29expected new requests/provider; new file request39, file reply40 to previously accepted request1, duplicate41, unknownsender42, synthetic infected43. Explicit bounded Graph list/detail and Gmail file responses; harmless clearly labelled bytes. Test-only MalwareScanner injection fails the first request39 scan, then returns clean; synthetic infected verdict remains private. No production code imports this helper. Read-only reconciliation now records sanitized attachment IDs/scan states/object existence/hash match without private paths.

ACTIVE bootstrap75833, ownedruntime724533b40323406b/fingerprint3c9e842997c9428fe97d504998d3e0e62ee80450255f32b8a334a3f0c95847b5, MailboxFixtures enabled. Wait for terminal/readiness before browser use. Asset manifest unchanged36a47bd0d34f8b80b0442bfd43cee08039557dd89a1ed0e168bd4cacdd7ab000. No other tests/imports active. Do not resize browser. WorkingDB/migrations17/18/19 untouched; no real communications/provider/scannerconfiguration/deploy; AIoff.
