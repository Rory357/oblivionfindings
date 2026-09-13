# W07 bounded attachment cleanup command

Later checkpoint: the approved catalogue/recorder/Operations integration is now implemented in source. See [its separate current evidence](w07-cleanup-operations-integration-results.md). The original manual-only checkpoint below is preserved as execution provenance; it does not describe the later source registration or claim that new integration was included in the earlier pure test run.

Status: **implemented; pure command contracts verified; not scheduled or database-integrated yet**. Builds on [the verified storage-intent foundation](w07-attachment-storage-foundation-results.md). No scanner, retention duration, deletion entitlement, provider call, migration or working-database mutation is introduced.

## Exact source and command contract

- [RetryItAttachmentCleanup](../../../../app/Console/Commands/RetryItAttachmentCleanup.php) exposes `it:retry-attachment-cleanup --limit=100`, accepting only integer limits1–1000. The existing commands-directory discovery makes it available for an explicitly invoked Artisan run; **no catalogue/schedule registration was added**.
- [Pure unit tests](../../../../tests/Unit/It/RetryItAttachmentCleanupTest.php) use a bare container with mocked schema, storage, query and recorder contracts. They do not bootstrap `Tests\TestCase`, migrate, connect to a database, access private files or call providers.
- Existing `ItAttachmentStorageService::retryPending(limit)` remains the only physical cleanup implementation. The command neither enumerates files nor decides whether a reserved/unknown intention should be removed.
- The command uses `ItAutomationRunRecorder::begin/completeRun` with key `it.retry-attachment-cleanup`. It verifies automation-record storage before beginning and stops before cleanup if a run cannot be recorded. This currently manual command does not claim a schedule expression.
- Missing/incomplete000010 yields a recorded failed readiness result with actionable migration copy, no cleanup and null unavailable counts. Database/readiness errors use safe fixed codes; exception messages, SQL, file paths and provider responses are never printed or persisted by this command.

One JSON line contains `automation_key`, UTC ISO `checked_at`, `status`, `outcome`, `error_code`, `message`, `limit`, `counts` and `remaining`. `counts` is the strictly validated canonical `{requested,deleted,failed,deferred,reconciliation_required}` envelope. Missing, negative, excessive or contradictory counts fail closed. `remaining` is a fresh primary-read aggregate of `{cleanup_pending,reserved_unclassified,reconciliation_required}`; reserved rows stay unclassified, regardless of age.

Exit0 means this bounded batch was successfully processed/observed. Remaining pending work is stated explicitly and may require further batches. Failed/deferred work, encountered or existing reconciliation, unavailable readiness/evidence, and failed terminal run recording return exit1. Invalid limit returns exit2 without accessing schema or creating a run. Partial deletion retains successful counts; an exception reading final evidence or persisting the terminal run does not replace those known counts with zeros or report success. A failure before counts are known reports null. Existing `reconciliation_required` rows prevent an apparently healthy automation result even if the current pending batch is empty.

## Actual verification

- PHP syntax and explicit-file Pint passed for both new files.
- `php vendor/bin/pest tests/Unit/It/RetryItAttachmentCleanupTest.php --colors=never`: **22 passed / 257 assertions / 0.35s**, exit0. [Actual output](w07-attachment-cleanup-command-unit-initial.txt).
- Coverage includes bounded defaults/invalid limits, missing automation storage, missing table/columns for000010, no cleanup without a recorded start, successful bounded backlog, reserved-only evidence, partial and deferred failure, existing/new reconciliation, malformed results, safe exception handling, and preserved known counts after evidence/terminal-record failure.
- No database-import slot was used. The actual foundation's physical storage and two-worker fencing were previously verified separately; this result does not claim the new command's end-to-end recorder/database integration or scheduled execution.

## Registration proposal and remaining gate

Propose **every five minutes**, batch100, Auckland schedule timezone, canonical overlap prevention and one-server scheduling. The original caller already attempts cleanup immediately after proven rollback; this cadence provides prompt mechanical recovery without retrying a persistent storage failure every minute. It is a technical retry interval, not a file-retention period or removal authority. The existing per-intention locks remain the correctness fence for concurrent manual/scheduled passes.

Register one canonical catalogue entry with key `it.retry-attachment-cleanup` and handler `it:retry-attachment-cleanup`, expression `*/5 * * * *`. **At the same time**, add this key to `ItAutomationRunRecorder::isSchedulerRecordedAutomation()`'s self-recorded exclusions, as already done for mailbox polling and the SLA watchdog. Otherwise scheduler events would create a duplicate run and could overwrite the command's truthful partial-failure result with scheduler success. Neither change was made in this task.

After root releases browser/runtime and serial-test constraints: run the command against a fresh isolated schema/private test disk with real pending success/failure and reconciliation fixtures; assert the persisted run matches output/exit status, readiness is safe, a bounded pass leaves newer work, and exactly one run exists when invoked by the scheduler. Then verify catalogue freshness and scheduler overlap/recovery evidence. Unknown reservation reconciliation and intentional submitted-file removal remain explicit separate lifecycle work.
