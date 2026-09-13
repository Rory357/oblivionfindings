# W08 — Required approval ownership and scheduler-lag replacement

10 September2026. Implemented in the existing approval service, policy, command recovery and private history. Production configuration, migrations, provider access and design sources unchanged.

## Resulting behaviour

- Every **new** approval request needs an explicit eligible primary approver. JSON, already-served legacy forms and direct canonical service calls cannot create ownerless work. Current eligibility, separation of duties and optional distinct cover remain unchanged. Outbox preparation targets the actual current primary or active cover.
- A previously committed ownerless request can still recover/replay its exact original receipt. Validation of new responsibility occurs only after command receipt lookup; historical assignment/reason evidence is not rewritten.
- A deadline-passed pending request no longer leaves replacement unavailable until the scheduler runs. The policy distinguishes effective pending work from expired work. Under the canonical ticket/approval locks, a valid replacement records expiry (no human decider), its event/audit/outbox and the new request in one transaction. Invalid replacement leaves the old recorded state unchanged. Later scheduler checks skip an already recorded expiry; receipt replay does not duplicate it.
- The existing private history endpoint now accepts an optional exact `approval_id`, verifies ownership within the already-authorized ticket and resolves its containing10-record page. The response binds actor/ticket/version/review nonce/target. Later sequential pages retain the reviewed version. Client navigation loads and focuses the requested historical record and ignores an old target's late response. This source has client verification; final backend/browser verification remains pending below.
- The All Tasks queue and preview use the canonical relative-time formatter for deadlines below24hours. A deadline two hours away no longer reads Due in1d.

## Changed files

Runtime: `app/Domain/It/Services/ItTicketApprovalService.php`, `app/Policies/ItTicketPolicy.php`, `app/Http/Controllers/It/ItTicketApprovalController.php`, `app/Domain/It/Presenters/ItTicketApprovalPresenter.php`, `app/Http/Middleware/ProtectItDraftResponses.php`, `resources/js/pages/tasks/types.ts`, `resources/js/hooks/use-it-approval-history.ts`, and the existing ticket approval controls/history/record components.

Tests: approval responsibility/command/legacy Feature files; ItApprovalCandidateTest; standalone approval command concurrency parent/worker; history hook/component and task due-time tests. Existing test fixtures now name the intended approver. Opposing real-worker decisions use two independent commands from the same eligible primary, preserving the decision-race test without allowing an unrelated actor to decide.

## Actual verification

- Five-file approval Feature batch58769: **67passed/1failed/641assertions**, exit1. Exact isolated token `it_e740c507184e490e` absent at independent postflight. The only failure was the new historical-receipt immutability test comparing an in-memory newly-created model (without reloaded database defaults) to a fresh read; receipt replay itself had passed. No runtime change was needed for this failure.
- Corrected that comparison to use fresh database reads on both sides. Focused rerun63030: **1passed/7assertions**, exit0; exact token `it_4f21d6d5f66c441b` absent. Filtered runner shutdown recorded a Pest NameFilter warning location; the diagnostic confirms the intended test actually ran/passed. Do not sum overlapping assertion totals or call the first run wholly passing.
- Standalone real-worker approval races78098: **4passed/135assertions**, exit0; exact token `it_8bf168b0386e4544` absent. Duplicate named requests, opposing decisions, command cancellation, settlement, reminder/withdrawal/expiry races, actual post-commit recovery and different-hash winner rejection passed.
- Deadline UI: **10passed/3files/3.45s**, scopedlint0. Linked-history UI: **19passed/3files/19.36s**; full TypeScript61529 exit0. Initial lint found one effect dependency warning; the explicit `loadHistory` binding correction passes scopedlint0. Explicit PHP Pint0; protected design diff empty.
- Logs: `w08-approval-owner-expiry-feature-tests.txt`, `w08-approval-ownerless-replay-rerun.txt`, `w08-approval-owner-expiry-concurrency-tests.txt`, `w08-approval-task-due-tests.txt`, `w08-approval-linked-history-ui-tests.txt`, corresponding diagnostic JSONL/type/lint/Pint files.

## Remaining verification and next step

The linked-history endpoint's new target/page/foreign-ID assertions passed in Feature session88398: **8passed/109assertions**, exit0, exact token `it_b349e3a324904534` absent (`w08-approval-linked-history-feature-tests.txt`). Build16 session73757 exited0 in4m04s (`w08-approval-desktop-build16.txt`), entry `app-DgK0fEQX.js`, manifest `7f23e773a55569d11d5139754b7cf70620db6260004950a8bfdd0c5ae2cd26a7`. Build15 is superseded because source changed while it built. Fresh guarded browser bootstrap14689 is importing token `06eacb4368dd44d0`, fingerprint `4a66727b584f0af2992c03d29104aef4922f5e9531d9eab335bec0debd09427a`; no browser success is claimed yet.

The next guarded browser environment adds a clearly labelled approved-leave fixture (sixth synthetic actor) and a separate ticket with12 seeded historical requests. These are fixtures, not evidence of actual HR decisions or user actions. Verify active cover, exact older-page links, expiry/replacement and the corrected due display with current assets, then finish remaining approval recovery, W08/E07 catalogue integration and subsequent packages. Preserve the full W00–W27 Goal; do not mark W08 or the release gate Verified from these bounded checks.
