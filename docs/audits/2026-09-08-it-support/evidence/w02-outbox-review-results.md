# W02 outbox review and regression evidence

Date: 2026-09-09. Scope: first W02 ticket creation/outbox slice; code and isolated tests only. Real provider verification and the later W12 programme remain separate.

## Confirmed defects and implementation

1. A pending delivery already marked `sending` could become `failed` when the recipient lost access. The first provider result remained unknown, but later restored access could enable a duplicate manual retry. Access suppression now preserves `sending` and its uncertainty, records an access-revocation audit once for that suppression, and never rewrites an acknowledged provider state. Only a never-started queued message becomes a local failure.
2. A local failure or the outbox exception handler could overwrite a provider callback recorded after the loaded model snapshot. Local updates now require the current database state to remain eligible and `provider_status_at` to remain null. A started send stays uncertain immediately, including before the outbox catches the exception. A local error cannot replace delivered, bounced, provider-confirmed failed or accepted outcomes.
3. The atomic mail claim now additionally requires an absent provider outcome and an absent prior sending timestamp. A late acceptance clears an obsolete local uncertainty message.

Changed files:

- `app/Domain/It/Services/ItEmailDeliveryService.php`
- `tests/Feature/It/ItTicketNotificationOutboxTest.php`

## Verification

- Pint: passed for both changed files.
- Fresh isolated grouped regression run: **42 passed, 382 assertions, 261.46 seconds**, exit 0. Includes 14 ticket notification outbox cases, 20 existing service/provider operations tests and 8 notification access cases. Initial schema preparation contributed 235.30 seconds to the first test.
- Test invocation uses `run-isolated-it-tests.ps1`; all 14 preflight guards passed, including loopback MySQL, a new disposable schema, array mail and no broadcast transport. Schema: `oblivion_it_support_test_it_d3ded04d35a842f5`.
- New cases cover sending plus revocation with one audit and retry denial; delivered/bounced/provider-failed callbacks followed by local failure; and explicit retry only after an authoritative provider failure resolves an uncertain attempt.

## Independent review observations handed to the integrating agent

- Raise-ticket knowledge suggestions discarded the wizard directly through the parent modal setter; this bypassed dirty-close confirmation. Preserve the wizard while reading or request an explicit discard.
- The file dropzone uses a non-native drag target that does not inherit disabled behavior from a fieldset. Pass its disabled prop and guard the callback while the command is frozen.
- An uncertain result needs an explicit, truthfully worded acknowledged exit. Stopping the HTTP wait never cancels or rolls back a server command.
- Concealing the form after HTTP 403 also needs to purge the integrating form state. The follow-up implementation now distinguishes actor-owned committed receipts whose record is unavailable with typed HTTP 404 (`access_unavailable`), while unknown UUIDs and other actors' receipts remain generic 404. The hook purges on the typed denial and retains uncertainty for ordinary 404. Its expanded focused tests pass **19/19**. The PHP command-contract run passed **12 tests, 119 assertions, 203.49 seconds**, including inaccessible/missing owned records, private payload exclusion, generic unknown/other-actor denial and legacy redirect field errors. This result preceded the subsequent commit-cleanup implementation below.

The named MySQL lock normally serializes dispatchers. A connection reconnect can release it, so it is not the sole duplicate-send guarantee: the conditional queued-to-sending update remains the final mail claim, and database notifications retain a unique UUID primary key. No test here claims provider exactly-once delivery or exercises a live connection failure.

## W02 attachment cleanup follow-up

The review confirmed that `ItTicketIntakeService::performCreate` previously deleted every staged path for any exception returned by `DB::transaction`. Laravel runs the PDO commit before after-commit callbacks and its `committed` connection event. An exception from either post-commit hook could therefore delete files referenced by committed ticket/receipt/attachment rows. A lost acknowledgement from database COMMIT has a similar uncertainty.

The authorized narrow fix now tracks completed callback results. After a completed callback throws, it preserves files and attempts reconciliation through a fresh temporary write-primary connection, including current actor/access checks through the canonical services. A matching actor/UUID/hash/record receipt proves the same result; the legacy path requires the exact allocated ticket ID and reference. A missing result or failed reconciliation never becomes rollback proof and never returns success. Failed callbacks before commit clean up only when the original connection has returned to its prior transaction/savepoint state.

Standalone verification **passed: 1 test, 36 assertions, 256.17 seconds**, exit 0. `tests/Concurrency/It/ItTicketCommitRecoveryTest.php` is deliberately outside transactional Feature/Integration suites and must run alone through the guarded wrapper. It naturally committed fixtures in its own disposable schema (`oblivion_it_support_test_it_b387ca4f071b45de`), asserted array mail, and exercised post-commit listener failure for UUID and legacy intake, unavailable reconciliation, current access revocation, stable replay without duplicate ticket/activity/audit/outbox rows, confirmed rollback cleanup and temporary connection cleanup. No application-wide transaction rewrite or live connection failure was performed.

The existing normal Feature-suite rollback case was rerun against the final code under its savepoint/outer test transaction: **1 passed, 8 assertions, 192.96 seconds**. It verifies receipt failure leaves no ticket, receipt, attachment or delivery rows, removes staged files, and permits subsequent valid creation. Its guarded disposable schema was `oblivion_it_support_test_it_ba1019fc120e4d25`.

The change preserves potentially committed files when database acknowledgement remains unknown; it does not promise that every retained file is committed or invent a rollback result. Orphan reconciliation and operational recovery remain part of the later release checks. The whole W02 package and its browser criteria are not declared complete by this focused evidence.
