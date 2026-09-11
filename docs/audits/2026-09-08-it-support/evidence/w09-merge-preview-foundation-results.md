# W09 — canonical merge preview foundation

10 September 2026. W09 / B07 / E08 in progress. This is an implemented and focused-test-verified read foundation; the review dialog and guarded merge writer still require integration and browser acceptance.

## Changes

- Extended `app/Domain/It/Services/ItTicketMergeService.php` with `preview`. It refreshes the approved actor through the existing version service, locks both canonical parents in ascending ID order, checks current work permission and both selected versions, then returns recorded inventory and existing lifecycle blockers without mutation.
- Inventory covers public/internal comments, ticket/comment files, watchers, links, tasks, unfinished required tasks and approval generations. Expired pending approvals are counted as expired, not awaiting a decision. Counts describe existing records; they do not promise which records will move.
- Explicit access-scope field differences cover site/organisation-wide/sensitivity, participants, assignee, owner, team and queue. This is a comparison of recorded scope inputs, not a claim that actual audiences are equivalent or permission to merge.
- `PreviewTicketMergeRequest`, `ItTicketController::mergePreview`, and GET `/it/tickets/{ticket}/merge-preview`: required browser actor, selected target, source/target versions and review UUID; existing staff route and concealed object boundaries; private/no-store JSON. No filenames, file paths, comment bodies or approval reasons are included in the inventory response.
- `resources/js/hooks/it-ticket-merge-preview.ts`: typed actor/pair/version/nonce-bound decoder validates counts, scope fields, references and lifecycle blockers. It returns no `can_merge` permission.
- `use-it-ticket-merge-preview.ts`: on-demand, cancellable read; ignores late responses after cancellation, actor/pair/version changes or unmount; hides failed/stale review data and supports separate retry. No automatic retry loop or write.

## Actual verification

- `w09-merge-preview-initial.txt`, process15300, token`it_2c14209210cb46ba`: 25 passed /1 failed /26 finished /195 assertions, exit1. The failing assertion expected404 for a requester, but the existing `permission:it.manage` route group correctly returns403 before object lookup. Corrected the expectation without widening access.
- `w09-merge-preview-final.txt`, process49265, token`it_2aedd7a3b2bd40a8`: all7 preview cases passed /43 assertions /exit0. Covers no-mutation inventory, omission of private contents, overdue approvals, stale source and target versions, wrong actor, requester denial, inaccessible target concealment, closed-target blocker, scope comparison, current actor revocation, and invalid version/review identity.
- Both guarded test postflights confirm their exact disposable schemas absent. Array mail/sync queue/no broadcast; working Herd database untouched.
- `w09-preview-contract-ui.txt`:21 tests /2 files passed /5.25s /exit0.
- `w09-preview-reader-ui.txt`:32 tests /3 files passed /4.61s /exit0. Includes decoder boundary cases, reader cancellation/late-response suppression, session/access/stale/server failures and explicit retry, plus all3 reply attachment integration cases. These frontend runs overlap; do not sum as unique coverage.
- Scoped Pint and diff whitespace checks passed. Four preview-file ESLint passed. Contract TypeScript24567 and final reader TypeScript65081 both passed, exit0. No task process remains active.
- No new browser build/runtime has been started for this foundation. Build29 is still the last browser-verified frontend and its environment is removed. Do not claim this endpoint or reader has been browser verified or that the merge dialog now uses it.

## W07 attachment follow-up

- Expanded the real composer integration test to text, normal saved upload and retained selected File after enabling persisted drafts. Transport simulates the actual saved attachment response and retains original File identity for the selected-file path; both send two distinct notes and start a clean next note.
- `w07-file-handoff-red.txt` is the attempted reproduction filename; its actual result is **3 passed /exit0**, not a red test. Neither attachment path reproduced the suspected extra defect. No production attachment logic was changed.
- Revalidation corrected the previous resumption assumption: normal draft-enabled attachment selection uses `TicketDraftFiles` and uploads to the draft; `chooseFiles` is the non-persisted selection path. The earlier conservative hook test involves a different state sequence. Keep its preservation guard unless a real, applicable failing sequence proves a change is needed.
- Attachment-only types90396 and lint passed. Later32-test run also includes all3 attachment paths. No additional browser claim for attachments is made in this turn.

## Next integration

1. Wire the reader and inventory into the approved merge dialog, with actor/pair/version binding, usable review/retry/cancel/session/stale states, preserved reason, genuine completion progress and focus handling.
2. Add `lock_version` to canonical merge target options and pass actor/source version from the workspace. Current `MergeTicketDialog` remains unchanged and does not use the new preview.
3. Complete canonical commit-time current actor/audience/version/inventory validation, strict acknowledgement, uncertain-result recovery and immutable evidence handling. Do not treat a matching preview as write authorization. The existing writer still moves only comments/watchers, leaves source workflow_state mismatched and does not advance the survivor version. Outstanding tasks/approvals/files/links, scope changes, source provenance and history require explicit treatment.
4. Rebuild, create a fresh reviewed isolated runtime and verify full restricted-role merge/old-reference/error/recovery journeys. W09 and E08 remain open.
