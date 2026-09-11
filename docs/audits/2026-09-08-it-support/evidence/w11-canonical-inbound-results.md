# W11 canonical email lifecycle — 11 September 2026

Scope: partial W11/F03/B01/B02/E05/E10. Whole W11 remains incomplete.

## Current implementation

`ItTicketCommandChannel` supplies trusted Browser/Email provenance separately from submitted fields. Existing intake/comment commands, receipt queries and recovery/reconciliation use this channel. Browser is the unchanged default; browser cancellation remains browser-scoped. An email reply is public, cannot consume a browser draft, and has an explicit bounded larger body contract. Submitted `channel` or `source` cannot select email behavior.

`InboundEmailIngestor` now calls the existing `ItTicketIntakeService::createCommand` and `ItTicketInteractionService::addCommentCommand`. UUIDv5 binds the normalized message ID to the command inside the same inbound transaction. The canonical writers own scope, priority/routing, requester identity, first response, conversation responsibility, waiting transitions, versions, audit and durable notification intent. Direct email ticket/comment creation has been removed. Merge changes during resolution trigger rollback/retry instead of writing to an obsolete source.

Intake uncertain-commit reconciliation now runs only when that command owns the outer transaction. A nested command cannot prove a savepoint outcome using a separate connection while the inbound transaction is still uncommitted; the outer owner must roll back/retry or reconcile its receipt.

Changed files: `app/Domain/It/Enums/ItTicketCommandChannel.php`, `app/Domain/It/Services/ItTicketIntakeService.php`, `app/Domain/It/Services/ItTicketInteractionService.php`, `app/Domain/It/InboundEmailIngestor.php`, `tests/Feature/It/ItInboundCommandLifecycleTest.php`.

## Actual verification

Initial six-file suite session54603 terminal1/Pest2:19 passed, one version assertion failed, one QueryException stopped the remaining tests;150 assertions. Token`it_84dd03e500f3498b`, log`w11-canonical-inbound-initial.txt`, all14postflight true and exact schema absent. The version assertion incorrectly assumed exactly one model save; canonical first-response evidence performs more than one versioned write. It now checks monotonic advance and unchanged committed version on replay. Source inspection also found that a valid long ticket title plus generated notification prefix exceeds the old delivery subject column; the capacity correction below covers it. Initial SQL message was intentionally not retained, so its exact classification was not proved by that diagnostic.

Corrected expanded six-file suite **101 passed,788 assertions,213 seconds**; session78892 terminal0, token`it_208cf36ee2854508`, log`w11-canonical-inbound-capacity.txt`. All14postflight checks true and exact disposable schema absent. Coverage includes canonical intake/audit/notification intent, IT first response, requester waiting recovery, typed channel isolation, public-only email, nested acknowledgement/outer rollback recovery, full100000-byte ASCII/UTF-8 content, guarded capacity rollback, eligible requester/agent reopening, outside-window denial and the existing browser intake/comment command suites. Counts overlap prior identity tests and are not additive.

Expanded real-worker race **1 passed,58 assertions,179 seconds**; session96851 terminal0, token`it_38ee693013b84330`, log`w11-canonical-inbound-concurrency.txt`, all14postflight true and exact schema absent. Three rounds force simultaneous identical intake, conflicting content and duplicate reply through the new canonical commands, checking exact audit/receipt/delivery counts. All three forced-overlap rounds passed. Scoped Pint and diff whitespace checks pass. Source snapshot`w11-canonical-inbound-source-hashes.json`.

## Confirmed follow-up before acceptance

- Implemented and covered by the101-test run: DP04 preserves the existing seven-day requester window and responsible-agent authority through `reopenWithReason`. Its transition input carries trusted Email provenance into the canonical reason record. The public incoming reply and reopening commit together, with normal author/audience rules and one replay outcome. Ineligible settled mail quarantines as`settled_reference_requires_related_request`; its governed related-request recovery remains incomplete. DP02 clock review is not a blocker to existing authorized reopening.
- Migration000018 widens existing ticket description/comment body to MEDIUMTEXT and delivery subject to TEXT; its rollback refuses to truncate retained content. Actual100000-byte ASCII/Unicode intake/reply and complete long notification subject tests passed. Migration000018 has only run in disposable test schemas, not the working database.
- Actual browser slice passed; see w11-canonical-browser-results.md and w11-canonical-browser-reconciliation-verified.json. Both31-message provider flows proved54tickets/2replies, canonical audits and ACK-only retry. Requester UI and direct403/404 verified at unchanged1235x856 with current Build41 assets. Bootstrap96470 terminal0; cleanup97675 terminal0 and independent exact-schema/directory absence. Owned tabs18/19/20 closed, usertabs1–3 untouched. Full E10 remains open.
- Full browser E10, provider-header/sender ambiguity, bounded protected attachments, loops/bounces, quarantine recovery/retention, legacy backfill and mailbox-nested contention remain open. DP07 live provider authorization is external. No working DB migration/reset, real communication, deployment or design-file change.
