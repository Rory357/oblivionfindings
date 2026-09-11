# W13 scheduler diagnostics and execution outcomes

Status: Implemented and locally Verified for this bounded slice. Mapping: W13/E12 safe Operations diagnostics; F05/A10 operational truthfulness. Full W13, E12, W26 and the final release gate remain incomplete.

## Confirmed defects and changes

- Laravel's installed `ScheduleRunCommand` emits `ScheduledTaskFinished` before `ScheduledTaskFailed` for a nonzero command exit. The recorder previously wrote success, removed its run association, then created a second failed row. It now checks the exit result and keeps a weak association through both events. A new start creates a new run; background dispatch cannot claim completed execution.
- Terminal recording now locks the canonical run and accepts only its first terminal outcome. A stale completion cannot change the original status, timestamp, duration or result. No second scheduler or run table was introduced.
- Scheduler exception text was stored verbatim, and Operations returned legacy non-cleanup errors verbatim. New failures use canonical job-specific messages. Read projections also use these messages for historical rows; no destructive historical rewrite is performed. Model serialization hides raw error/result fields, and existing typed cleanup projection and permissions remain in place.

## Changed files

- `app/Domain/It/Services/ItAutomationRunDiagnostics.php`
- `app/Domain/It/Services/ItAutomationRunRecorder.php`
- `app/Domain/It/Services/ItAttachmentCleanupReadService.php`
- `app/Models/ItAutomationRun.php`
- `app/Http/Controllers/It/ItServiceManagementSetupController.php`
- `tests/Feature/It/ItServiceOperationsTest.php`
- `docs/audits/2026-09-08-it-support/evidence/w13-api-browser-fixture.php` (opt-in isolated fixture only)

## Verification results

- Scoped Pint passed on six production/test files; fixture PHP syntax passed.
- Guarded tests: session 76717, token `it_129c4e697e884ad0`, log `w13-scheduler-diagnostics-feature.txt`: **50 tests / 601 assertions passed**, terminal exit 0 and all 14 postflight checks passed, including exact schema absence. Three suites: service operations, attachment cleanup operations and approval responsibility. Machine-readable outcomes: `it_129c4e697e884ad0.diagnostic.jsonl`.
- All 10 original protected design hashes remain unchanged (`w13-scheduler-design-check.json`); seven implementation/test/fixture hashes recorded in `w13-scheduler-source-hashes.json`.
- Browser preview fingerprint `c3b153f816f7f962a7eddc020a69b3d3ba8ab7cb405c166984ef1c5494cfd9d8`; API fixtures enabled. Bootstrap 28708 completed with exit 0 for owned token `6bf7a82beed84985`. The live identity endpoint proved exact checkout/schema, array mail, synchronous queue, normal CSRF and disabled SSR. Current unchanged frontend manifest: `a2de2272f4257e1f200b2e18b19db1f174ad6091323b2f1f7ae3a9462927b069`. No frontend source changed in this slice; no new build was needed.
- Actual in-app desktop tab 46: normal technician login, then `http://127.0.0.1:8766/it/setup?tab=operations`. Close-resolved showed Failed with last successful check Not recorded. Recent failures contained exactly the closure fixture and the historical notification fixture, each with its appropriate safe recovery message. Synthetic private exception/payload markers were absent. The isolated fixture separately asserted that the framework Finished/Failed event sequence produced one failed row; it did not execute an operational closure command.
- Inline desktop screenshot inspected after scrolling to Recent failures. No resizing or saved image was needed. This verified the rendered diagnostics, not the full design/recovery acceptance of the pre-existing automation cards and failure blocks.
- Normal sign-out, then requester sign-in using Return. Direct Operations navigation returned 403 Forbidden; subsequent navigation to `/it/tickets/1` loaded that requester's permitted ticket. No scheduler details leaked into the denial page.
- Cleanup 50638 completed with exit 0. `w13-scheduler-browser-cleanup.txt` records removal of only the owned database/directory; independent `w13-scheduler-browser-postflight.json` confirms both absent without mutations. Tab 46 closed; user tab 3 preserved. Final seven source hashes remained unchanged (`w13-scheduler-final-source-check.json`). No active runtime, tests, build, import or owned tab remains.

## Remaining scope

Mailbox bounded scans currently record a successful job even when connections are skipped or scan work remains pending. Assess and expose the distinction between a finished bounded job and a completed mailbox poll, including historical freshness evidence. Full scheduler recovery controls/typed categories and durable automatic-ticket outcomes remain open; the latter depends on W14 source/episode rules. This slice does not prove provider readiness or full W13 completion.

No working database migration/reset, live provider changes, external communications, production AI execution or protected design-file edits were performed. Desktop browser verification preserved user tab 3 without resizing.
