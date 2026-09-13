# W09 guarded merge command and recovery foundation

10 September 2026. W09 remains In progress, not Implemented or Verified as a package. This is preparation for the complete reviewed merge UI. No new browser assets or browser journey is claimed.

## Implementation

- The canonical `ItTicketMergeService` now provides version-bound execution and lookup/cancellation using the existing actor/channel/operation command receipts. No duplicate ticket or command inventory was created. Preview locks both parents in ascending order, refreshes the actor, and supplies a ten-minute encrypted proof of the exact pair, versions and displayed inventory. The writer rechecks the proof, current authorization, audience and work before committing. The proof lifetime is a technical review freshness limit, not a business retention decision.
- Staff access predicates must agree as well as requester/requested-for audience: site, organisation-wide marker, sensitivity, responsible people, team and queue. Role/site authorization remains canonical. Optional unfinished tasks and nonexpired pending approvals must be completed/cancelled/withdrawn first; required work must remain complete and approved. The source becomes read-only, so unfinished work is not stranded there.
- Canonical closure transitions protect required-work evidence, clocks, status/workflow and audit. The source advances twice (closure then merge pointer); the survivor advances once. Comments and ticket-root attachments move with their original IDs. Comment files retain their existing comment parent. Watchers are checked against current eligibility before transfer; ineligible watchers cause refusal, not silent removal.
- Completed task generations, approvals and links retain original parent IDs and immutable evidence. The survivor still needs a discoverable original-record section; navigation-only history links are not a complete disposition UI.
- Public conversation pointers reconcile moved history; observer messages do not determine the next response party. Unknown legacy speaker provenance remains unknown. No first-response clock is fabricated by the merge.
- Exact committed receipts replay without needing an unexpired review. A different intent under the same UUID produces HTTP409. Cancellation is durable and serialized; it returns an already committed merge if commitment won. Audit failure rolls back all movements, closure, versions and receipt.
- Strict client contracts retain the review token, freeze reason/pair/versions/UUID, require an exact actor/pair/operation receipt and validate the canonical navigation URL. The recovery hook supports lookup, exact retry, stop-waiting and cancellation. Browser storage contains only actor/source-scoped target IDs and UUIDs; no reasons, titles or review proof. Session/access denial conceals private proposals, and even lookup404 is not treated as proof that the merge failed. A stale-ticket rejection is distinct from an unknown or command-identity conflict. Host callback failure cannot undo a confirmed result.

## Changed files for this foundation

- `app/Domain/It/Services/ItTicketMergeService.php`
- `app/Models/ItTicketCommandReceipt.php`
- `app/Policies/ItTicketPolicy.php`
- `app/Http/Requests/It/MergeTicketRequest.php`
- `app/Http/Requests/It/ReadTicketMergeCommandRequest.php`
- `app/Http/Controllers/It/ItTicketController.php`
- `routes/web.php`
- `tests/Feature/It/ItTicketMergeTest.php`
- `resources/js/hooks/it-ticket-merge-preview.ts` and its test
- `resources/js/hooks/use-it-ticket-merge-preview.test.tsx`
- `resources/js/hooks/it-ticket-merge-contract.ts` and its test
- `resources/js/hooks/use-it-ticket-merge-command.ts` and its test

These files include earlier work in the dirty tree. No claim that their entire Git diff was created in this slice. Opening status contained 473 entries; all existing changes were preserved.

## Actual verification

- Initial isolated PHP session61993, token `it_6f7008e7da124f24`: **43 Passed, 2 Failed, 1 Errored, 46 Finished**, 307 recorded assertions, 258 seconds execution, Pest exit2/wrapper exit1. The exact owned schema was independently absent at wrapper postflight, with all14 isolation checks true. See `w09-merge-command-backend-first.txt` and the matching diagnostic JSONL.
- Real defect: controller's broad DomainException catch converted command-identity conflict into422. Added the explicit409 response before the lifecycle catch.
- Two test-fixture corrections: the sensitivity comparison requires a technician who can actually work both tickets (explicit test-only `it.viewSensitive` grant); immutable approval comparison now compares database snapshots on both sides instead of a newly created model missing database-default attributes.
- Corrected targeted session89861, token `it_37a9075c10db474d`: **5 Passed, 0 Failed/Errored**, 41 assertions, 196 seconds execution, Pest/wrapper exit0. Exact schema absent and all14 isolation checks true. This covers the corrected cases including all three staff-scope datasets; it does not rewrite the initial full run as green. The combined evidence supports 46 distinct focused cases after correction; there was no second full46-case run.
- Initial client contract/preview group: **53 tests/3 files passed**, 4.46s, exit0 (`w09-merge-command-contract-tests.txt`). Transport extension: **67/4 files passed**, 2.17s, exit0 (`w09-merge-command-client-tests.txt`). Final conflict/callback follow-up: **71/4 files passed**, 2.97s, exit0 (`w09-merge-command-client-final-tests.txt`). Overlapping groups are not added together.
- Initial Prettier failed on a PHP-style arrow accidentally entered in one TypeScript expectation; corrected before any test run. Final formatter exit0. Initial scoped ESLint exit1 on a latest-operation-counter cleanup warning; documented the required epoch invalidation with a narrow lint explanation, final scoped seven-file ESLint exit0/zero warnings (`w09-merge-command-eslint-final.txt`). Final seven-file backend Pint read-only pass, exit0. `git diff --check` exit0; protected DESIGN.md/design_styles diff empty.
- Initial TypeScript session75215 exited1 with six test-only unknown Axios payload accesses. Replaced those assumptions with an explicit record/string UUID assertion. Final transport-only follow-up18 tests passed, exit0/2.55s (`w09-merge-command-typed-transport-tests.txt`), final test-file lint0. Full TypeScript rerun session43695 **exited0** (`w09-merge-command-types-final.txt`). All task processes are terminal; no browser runtime was started.
- Fifteen final source hashes, including the corrected test adapter, are recorded in `w09-merge-command-foundation-source-hashes.json`.

## Remaining acceptance and next step

1. **The live MergeTicketDialog is still the old immediate Inertia form.** The new strict endpoint now requires actor/UUID/versions/review_token, which that old form does not send. Complete the dialog integration before presenting this as a usable merge feature or running acceptance. Do not interpret a redirect/flash as successful merge. The old dialog's hardcoded100% and unconditional success toast must be removed.
2. Reuse approved WizardShell, StepHead, ReviewCard/ReviewRow, Field and confirmation/recovery patterns. Show choose-survivor → actual preview → deliberate commit → confirmed result. Explain exactly which conversation/files/watchers move and which immutable tasks/approvals/context stay discoverable on the original. Preserve the reason across retry, stale read/adoption and cancelled close; support explicit discard, keyboard focus and original-actor recovery. Add lock_version to merge target projections and pass the source version/current actor from `resources/js/pages/it/tickets/show.tsx`.
3. Integrate the new command hook with the host's current authorization and draft/RAM/leave guards. Private reasons must not enter browser persistence. An opaque pending reference alone never authorizes exposing saved text. Provide recovery for references even if the target is no longer listed as an open candidate. Confirm cancellation before starting a new command.
4. Add survivor-to-original links for preserved work/approvals/context independently of a `merged_from` query; recheck each original's current access. Cover multi-hop merges and inaccessible intermediates. Finish other W09 related/duplicate/known-error/article work under the original plan.
5. Run standalone real-worker merge races and postcommit recovery against the reviewed isolated wrapper, then build and verify actual desktop in-app browser flows, restricted roles, keyboard, stale reviews, cancellation, uncertain outcomes and evidence reconciliation. **No resize.** Build29 remains the last compiled/browser checkpoint and all disposable environments through29 are removed. Do not submit stale tab content into a new runtime.
6. W09/E08 and all mapped full-scope criteria remain open. DP02 SLA reopen policy and external operational/provider decisions are unchanged; production AI stays disabled. No live providers, real communications, deployment, destructive production operation, design-rule edit or working-Herd database reset occurred.
