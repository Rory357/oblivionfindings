# Native monitoring IT delivery: actual workers

W14 / F06 / B04 / E13. This bounded actual-worker verification passed; no full package or browser claim.

New standalone `tests/Concurrency/It/ItMonitoringDeliveryConcurrencyTest.php` and guarded `tests/Support/It/monitoring-delivery-concurrency-worker.php` use real commits and independent PHP workers in the wrapper-owned schema. The child validates the exact parent token/schema, loopback database, fake notification/HTTP configuration and its own barrier paths. Only owned children and exact barrier files are stopped/removed in `finally`.

The harness forces these interleavings:

1. A worker holds the IT outbox row while a second reaches the same locking query. Both deliveries must produce one ticket, snapshot, creation event and completion audit.
2. A worker stops after inserting a ticket inside an uncommitted transaction. Its waiting competitor must recover after rollback without losing or recreating the existing Control Room alert.
3. A worker stops after the IT transaction commits but before acknowledgement. Replay must preserve the committed result.
4. Two manual retries contend on a dead-letter outcome. One is accepted and one rejected; exactly one allowance/audit is recorded, and the next delivery preserves the previous attempt count.

First attempt `it_3d49ef7711a74f07` stopped during PHPUnit class discovery: helper `result()` conflicted with a final PHPUnit method. No schema import or worker execution; all14 postflight checks/schema absence passed. Renamed the helper `workerResult`; no application change.

Final run15974 / token `it_a2344e1cb5cb4c42`, log `w14-monitoring-concurrency-recheck.txt`: **1 test / 87 assertions / 180.69s**, terminal0. All14 isolation postflight checks and exact schema absence passed. Both interrupted workers were explicitly confirmed stopped; all owned barriers were removed and their absence asserted. Independent final exact-token barrier enumeration found none. Final formatting and whitespace checks passed; no protected-design diff. Current13-source hash bundle: `w14-monitoring-concurrency-source-hashes.json`.

No application defect was found in these interleavings; the only initial failure was the corrected PHPUnit helper name. Next: exact native outage episode linkage and direct nonurgent intake. Existing Feature51/446 and final delivery/audit39/631 results are in `w14-monitoring-durable-delivery-results.md`. Working database and protected design files remain unchanged. No active test/build/owned preview runtime or workers remain.
