# W08 approval commands and reason evidence — implemented, verification in progress

Latest evidence,10 September07:28 NZ: the corrected combined approval/provider/privacy Feature batch75/782 passed; standalone approval command concurrency3/84 passed, both with exact owned-schema absence. Typed frontend contract and command-hook tests passed, but the visible approval controls, private RAM integration, named primary/cover and expiry/reminders remain unfinished. See `w08-readiness-and-personal-work-results.md`. Earlier prepared/failing checkpoints below are retained as provenance.

## Next implementation boundary, revalidated in current code

- `TicketApprovalControls` still uses the legacy router form. Integrate the existing typed approval command hook, original-outcome recovery and shared document-memory store; do not introduce another persisted draft store. The server's persisted draft-purpose enum intentionally does not contain `task_work`; task RAM uses its bounded candidate endpoint. Approval RAM needs an equally explicit actor/ticket/approval/operation proof, including every retained historical selection, instead of adding private approval content to a public draft purpose.
- `ItTicketApproval` currently has pending/approved/rejected status and an actual decider, but no designated pending primary/cover or deadline/reminder fields. `reasonEvidence()` already preserves request/decision provenance. Extend this same record; preserve legacy unassigned/unknown evidence without fabricated ownership or dates. The existing schema status is varchar, not an enum.
- Reuse `ItStaffDirectory::agentsForTicket` for current work access, and `ItTicketRoutingEligibility` for current employment/approved-Site/approved HR leave checks. A new approval selection must satisfy both access and responsibility eligibility. Cover activation must use canonical availability and retain its basis. Do not turn `approver_id` (the actual recorded decider) into a pending assignment field.
- Carry new lifecycle operations through canonical ticket locks/version/receipt/audit/outbox, strict UI identity and same-generation task bindings. Automatic expiry must retain historical human decisions; reminders must recheck exact current generation and audience. Avoid silently choosing an organisation-wide timeout/reminder policy; explicit request or reviewed configuration supplies operational timing.
- Finish private paginated approval review, visible pending owner/cover/rejected/expired/cancelled states, explicit corrective actions and canonical personal approval work. Then verify designated/cover/direct-ID/self/expired/concurrent/cancel/retry/session cases and catalogue task unlock. These remain implementation requirements, not completed behaviour.

This bounded slice implements the existing approval service's command reliability and immutable request/decision reasons. It does not implement approval expiry, reminders, designated primary/cover authority, business approval cancellation, catalogue task binding or the later frontend recovery adapter. The first isolated Feature batch completed with one test-fixture failure; its precise results and the corrected frozen source are recorded below. No approval browser journey or standalone worker test has run for this slice.

## Canonical behavior

- `ItTicketApprovalService` still owns request and approve/reject. It locks the canonical ticket, refreshes/locks the actor, checks current `it.manage` and `canWork`, then locks the exact nested approval for decision. New mutations require open/unmerged work. Existing separation of duties remains: the approval raiser cannot decide their own request. No additional beneficiary, cover or manager-only policy is invented.
- Each actual request or decision saves the ticket once and advances the aggregate version once. A second independent request while pending/approved, or a second independent decision on a terminal row, fails truthfully. It produces no new receipt/event/audit/outbox and no fabricated no-op success. Same-command replay returns the original outcome instead.
- Request/decision reason fields are allowlisted and trimmed, max 1,000 characters. Reject requires a reason; request/approve may deliberately omit one. Form transport fields and client-supplied model state/decider fields never enter model writes.
- Notification preparation uses the existing `ItEmailDeliveryService::prepare` inside the same transaction as approval, ticket version, receipt, event and required audit. Preparation/audit failure rolls all of them back. After-response dispatch failure cannot replace a committed acknowledgement; the existing outbox drain remains available. No provider configuration changed.
- A new request prepares the same existing audience of other current work-eligible agents. A decision prepares the raiser only if currently work-eligible. New delivery context includes the exact canonical approval ID. At dispatch/retry, requested notifications require that generation still pending, current eligibility and a recipient other than the raiser; decision notifications require that exact terminal status and its raiser. Legacy notifications lacking a generation retain the current work boundary without inferring an old approval ID. No new recipient or escalation policy is introduced.

## Transport

Existing URL/name compatibility is retained through the new `ItTicketApprovalController`:

- `POST /it/tickets/{ticket}/approvals` — request.
- `POST /it/approvals/{approval}/decide` — existing decision URL.
- `POST /it/tickets/{ticket}/approvals/{approval}/decide` — explicit nested decision URL; mismatched parent/approval is concealed with 404.
- `GET /it/tickets/{ticket}/approval-commands/{operation}/{requestUuid}` — prove the existing original outcome.
- `POST /it/tickets/{ticket}/approval-commands/{operation}/{requestUuid}/cancel` — permanently fence an uncommitted command identity, or return the prior commit if it already won.

Route operation is `request` or `decide`. Recovery/cancel `decide` requires `approval_id`; request prohibits it. Cancel requires original `actor_user_id`. Recovery optionally accepts actor identity and rejects a mismatch. Receipt ownership always uses the current authenticated actor, never another submitted identity. Mutations require the full `actor_user_id`, `request_uuid`, `expected_version` tuple for JSON or whenever any tuple field is present. Already-served legacy Inertia forms may omit all three; their canonical service still reauthorizes and advances the aggregate.

The immutable fingerprint covers original ticket, operation, approval target, expected aggregate version and normalized reason/decision. Receipt operations are `approval.request` and `approval.decide` in existing `ItTicketCommandReceipt`; only opaque IDs, status, committed version and flags enter `result_metadata`. Reasons are not duplicated in receipts.

Committed JSON envelope:

```json
{"status":"committed","data":{"id":1,"viewer_user_id":3,"request_uuid":"UUID","operation":"approval.request","approval_id":1,"approval_status":"pending","lock_version":2,"changed":true,"replayed":false}}
```

First request returns HTTP 201; decision and replay return 200. Request receipt always retains its original pending status/version even after the same approval is decided. A decision receipt retains its own terminal status/version. Fresh current record review is separate from this proof. Current work access is required even for historical recovery on settled/merged tickets.

Cancelled envelope:

```json
{"status":"cancelled","data":{"id":1,"viewer_user_id":3,"request_uuid":"UUID","operation":"approval.request","approval_id":null,"replayed":false,"cancelled_at":"ISO-8601"}}
```

This cancels the command UUID, not the canonical approval request. A cancelled decision retains its exact approval ID and leaves that approval pending. Cancellation has no ticket version advance; one required audit records the tombstone. A late original command under that UUID receives cancelled. GET 404 is never proof of absence or permission to reuse an unknown identity. If the original already committed, cancellation returns that original committed result.

Errors: `stale_ticket` 409 includes the established safe current ticket/version corrective link; `idempotency_conflict` 409 rejects changed same-UUID details; `approval_validation_failed` 422 reports business rejection; field validation remains 422. Current actor/capability denial is 403 and inaccessible/mismatched objects are 404. An unconfirmed persistence/commit failure returns `approval_outcome_unknown` 500 with no raw exception/reason leakage. Successful JSON, conflict, business-error and unknown-result responses carry `Cache-Control: no-store, private`. Legacy failures use form errors, preventing a success callback for a rejected change.

Post-commit reconciliation preserves the original primary connection configuration, reads from the write connection, locks/reauthorizes current record/actor and compares the original fingerprint plus approval target before acknowledging anything. Missing/different evidence leaves the original failure uncertain. The worker tests below are prepared to prove this with actual outer commits; that proof is not yet executed.

## Additive reason evidence

Migration `2026_09_09_000012_preserve_it_approval_reason_evidence.php` adds `request_reason`, `request_reason_recorded_at`, `decision_reason`, `decision_reason_recorded_at`. It performs no data backfill and refuses a lossy down operation when any new field contains evidence, including a recorded null reason's timestamp. SHA256: `ecc906f9cb633fb93b47ef7f55748519717b40882a6c1c57521efdfb88ab1fa2`.

The original stored `reason` remains unchanged. New request and decision each record their own explicit field/marker once. Decider and decision/reason timestamps are written together. Model guards prevent changing original request identity/reason evidence, overwriting terminal decision state/actor/time/reason, resetting terminal rows or deleting canonical approval rows. The compatibility `reason` accessor displays decision text, then request text, then legacy raw text without rewriting storage.

`ItTicketApproval::reasonEvidence()` returns private projection data:

- `request: {value, provenance: recorded_at_request | legacy_unattributed, recorded_at}`.
- `decision: {value, provenance: recorded_at_decision | not_decided | legacy_unattributed, recorded_at}`.
- `legacy_reason`: the observed old raw value only when request provenance is unknown.

Null recorded-at means no reliable historical attribution, not an explicitly empty known reason. A new decision on a legacy pending request records only its new decision; it does not invent an old request reason. Callers must apply current `canWork` before serializing this richer evidence. The main show/drawer projection and approval RAM proof/UI remain coordinated later work; this slice did not add private fields to `showPayload`.

## Changed sources and prepared verification

Canonical source: approval service/model/policy; narrow `ItTicketPolicy::requestApproval`; new approval input/result/command service/controller/identity request and tuple concern; existing request/decide FormRequests; approval receipt operation constants; preserved existing routes plus recovery/cancel/nested routes; `TicketApprovalNotification`; only the approval eligibility/reconstruction branches of `ItEmailDeliveryService`. Removed only the two delegated approval handlers and unused approval imports/injection from `ItTicketController`; its payload remains root-owned. Migration 000012 is reserved for this slice. Existing six browser helpers are untouched.

Prepared Feature file `ItTicketApprovalCommandTest.php`: original tuple/allowlist, immutable original replay versus current status, stale and independent repeats, request/decision command cancellation, committed cancellation recovery, actor/self/nested/Site denial, stale actor approval revocation, settled/merged mutation denial with historical recovery, truthful legacy reasons, immutable terminal evidence, audit/preparation rollback with exact retry, delayed obsolete-request notification suppression, and no-DDL rollback guard.

Prepared standalone `ItTicketApprovalCommandConcurrencyTest.php` and its dedicated worker: duplicate request, opposing decisions, cancel-versus-request, approval-versus-settlement, actual after-commit failure recovery without duplicate outbox, and a different same-UUID payload winning after rollback without falsely acknowledging the original. Only the parent owns the exact guarded test schema; worker barriers are restricted to its token-prefixed test directory entries. No worker resets schema or performs provider sends. These sources are not executed evidence.

Actual initial static checks: scoped Pint passed after formatting; PHP `-l` passed all 21 implementation/test/route files; whitespace checks were clean. Browser/working-database migrations remain unapplied. The standalone test must run separately after the coordinated Feature correction batch.

## Initial Feature result and bounded correction

Guarded run `42605`, token `it_e9d8f6c81648409a`, completed all 50 cases from 13:14:51 to 13:18:23 UTC on 9 September. Diagnostic event evidence gives **49 passed, 1 failed, 435 assertions**. Pest and wrapper exit were 1. The wrapper's separate read-only postflight confirmed the exact schema absent. `w08-approval-feature-tests.txt` preserves preflight/exit/postflight; `it_e9d8f6c81648409a.diagnostic.jsonl` preserves individual Passed/Failed/Finished events and final TestRunner/Application Finished. Pest's normal output remained in an output buffer, so no human-formatted test summary is claimed.

- `ItTicketApprovalCommandTest.php`: 15 passed, 1 failed, 184 assertions (16 finished).
- `ItTicketApprovalTest.php`: 22 passed, 106 assertions.
- Corrected `ItWorkTaskHistoryTest.php`: 12 passed, 145 assertions.

The sole failure was the notification-preparation failure test's exact retry at its then-line 204. Its `Event::forget` removed the real `ItEmailDelivery` creating hook together with the injected failure; that hook supplies required legacy storage compatibility fields. The correction disarms only the injected listener before retry. Canonical storage behavior is unchanged. Initial failure evidence remains intact.

Root's review also confirmed a real failure-path gap: exceptions raised before the approval controller did not pass through the private no-store JSON renderer. The approved narrow correction extends `ProtectItDraftResponses` to approval command recovery/cancel and mutation paths carrying JSON or any original command tuple field. The all-omitted legacy Inertia path remains unchanged. Approval validation, expired-session/CSRF, access denial, missing objects and unexpected failures now return safe approval-specific JSON, `Cache-Control: no-store, private`, and no old-input flashing; unexpected reports contain class/location metadata instead of raw exception/payload text. Existing draft/history behavior is preserved. Three additional Feature cases cover these boundaries, including injected pre-controller CSRF and unexpected exceptions, and legacy validation redirects. Scoped Pint and PHP `-l` passed both corrected files; the new cases are not yet verified.

Precise resumption: approval PHP/runtime/migration/test source refrozen after the bounded correction. Root is preparing the canonical personal-task provider; wait for its source freeze and explicit serial slot before the combined directly affected Feature batch. W02 has not completed an independent approval identity review and no signoff is inferred. Do not claim W08, approval UI recovery, policy-dependent work or E07 complete.
