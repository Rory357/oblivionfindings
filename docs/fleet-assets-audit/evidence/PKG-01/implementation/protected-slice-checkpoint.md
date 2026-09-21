# PKG-01 protected slice checkpoint

20 September 2026. Implementer `01a0bc28-a386-7653-9b29-361f93478222`, branch `codex/pkg-01-maintenance`, approved base `e62b569ff42ab471300fb6713a68758b647b2c32`. This is an early Designer review checkpoint, not package acceptance or live activation.

## Implemented so far

- Additive migration adds versioned policy assignments, site Coordinator/backup routes, category/site scoped append-only release reviewer grant history, immutable report and check provenance, work actions, restrictions, attachments, effects and FinBill links. Down migration refuses to remove populated evidence tables.
- Report service checks actor permission and approved asset site, locks the canonical asset, requires an approved Coordinator and nominated backup who remain current staff at the exact site, and binds idempotency to actor, operation and full payload. A manager can append a second report to an existing same-asset work order; the first report is retained.
- Check service saves explicit answers and immutable rule snapshots. Only the assigned, approved, exact rule version with unchanged template and explicit passing answers produces Passed. Missing rules or stale templates produce Needs assessment; corrections create new runs.
- Transition service locks asset before work order, checks version and replay identity, and records actions. Handover keeps the prior assignee until the target accepts. Completion requires approved repair policy, attestation and saved evidence. Existing automatic estimated/actual cost FinancialEvent creation was removed from the work order observer; H&S event handling remains.
- Work-order and check controllers now use protected services and approved-site resource scopes for the implemented paths. Frontend and remaining services are still in progress.

## Verification completed

- Forced test configuration `phpunit.pkg01.xml` uses the unique `oblivion_findings_pkg01_2375_test` base, loopback MySQL, null queue/broadcast, array mail/cache/session. No operational `.env`, `.env.testing` or config cache was used. The machine-specific XML is ignored by Git.
- Existing `FleetWorkOrderSiteScopeTest.php`: 1 test, 6 assertions, exit 0.
- New `Pkg01MaintenanceProtectedSliceTest.php`: 3 tests, 28 assertions, exit 0. It covers missing/approved site routing, same-key retry and changed-payload conflict, append-only duplicate report, unconfigured/approved/stale checks, immutable correction, guarded completion, action replay and pending/accepted handover, and no FinancialEvent from estimates/costs.
- PHP migration and new service/test files were linted during initial implementation; the latest route-staff hardening is pending another lint/test pass. PHPUnit displayed only a bootstrap warning: phpdotenv attempted to read this worktree's absent `.env`. It did not change test connection or fail the assertions.

## Open work and limitations

Booking restriction enforcement, repair/provider/retest/custody/release, durable effect delivery, private attachment routes, FinBill approval bridge, desktop UI, browser QA and wider regressions remain. Current focused tests are sequential; overlapping MySQL transaction proof is still required. No real operating policy, reviewer grants or route mapping was seeded. No production DB, deployment, integration or publication was touched. Designer review of access, immutable sources and absence of automatic Finance/release effects is requested now while implementation continues.

## Subsequent initial-build revision (01:15 UTC)

Designer identified I01–I04 in this unfinished initial slice. The revised focused suite passed **5 tests, 51 assertions, exit 0** on the same uniquely prefixed disposable schema. It now covers the N/A evidence-required bypass, forged client evidence flag, saved same-work private evidence, immutable presented template labels before/after mutation, report-only HTTP entry/search/submit and recipient acceptance, and explicit `ProcessFinancialEventJob` non-dispatch. The `.env` probe warning remains the only PHPUnit warning. This result supersedes the earlier 3/28 checkpoint for that slice; the earlier result is retained as history. Additional Designer follow-up requires rendered historical snapshot/options, conditional evidence positive and negative paths, and removed/superseded recipient denial. Those changes are in progress and have not yet been rerun.
