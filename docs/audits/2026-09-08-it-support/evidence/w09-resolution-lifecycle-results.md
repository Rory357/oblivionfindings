# W09 resolution lifecycle implementation evidence

10 September 2026. Package In progress; F16/A08/E08 and related E06 acceptance remain open. B07 merge/related work and C08/W21 reviewed knowledge are not covered by this slice.

## Canonical automatic closure

Confirmed the old scheduler selected resolved tickets and then saved closed status without rechecking under a lock. It now calls `ItWorkTransitionService::autoCloseResolved`, which locks the current canonical ticket, checks resolution age/status/merge, uses the same required task/approval gate as manual settlement and records one transactional system event/audit. Duplicate or stale candidates cannot overwrite a reopen or newer resolution. Blocked work is counted explicitly; storage/audit failures still fail the run. The existing default seven-day policy is preserved. Unknown legacy completion provenance remains unknown; no new operational policy was selected.

Changed: `CloseResolvedItTickets.php`, `ItWorkTransitionService.php`, `ItWorkTaskReadinessService.php`, `ItTicketAutoCloseTest.php`, `ItTicketAutoCloseConcurrencyTest.php`, existing guarded `task-command-concurrency-worker.php`. No migration, provider configuration, working database or design-file change.

- Explicit six-file Pint exited0.
- Isolated Feature90492: **34 Passed/34 Finished,356 assertions, exit0**, token `it_7304cb57bfb0461e`. Covered new auto-close cases plus Lifecycle, TaskReadiness and Transition regressions. Wrapper final independent read-only preflight confirms exact schema absent. Log `w09-auto-close-feature-tests.txt`; detailed events in the token diagnostic file. No human summary was invented from buffered Pest output.
- Separate real-worker70313: **1 Passed/1 Finished,26 assertions, exit0**, token `it_4cd6ce61c4fd4547`, exact schema absent. Both workers demonstrably reached the held parent lock. Two scheduler workers produced one close/one skip; scheduler versus real reopen ended open with one reopen comment/event. Log `w09-auto-close-concurrency-tests.txt`.

## Requester confirmation (implementation and checks in progress)

The existing ticket/transition/permission records now support explicit requester confirmation and closure without granting private work access. Actor and reviewed version are bound; the current requester/state are checked again inside the ticket lock. Required private work refuses confirmation with a generic message, no task title or ID. The canonical public timeline records `resolution_confirmed`. Existing satisfaction ratings remain editable while resolved and become locked on closure; no revision policy was changed.

The desktop ticket offers Confirm the fix and I still need help. A shared simple Dialog preserves unknown/session/error states, checks the exact confirmation acknowledgement and requires read-only current-version review before a separate retry. Existing TicketVersionConflict is extended with the confirmation capability and appropriate no-draft wording. The requester/technician dual-role reopen wording now follows actual requester identity; a participant-only IT requester retains the ordinary seven-day public reopen path.

Additional changed files: `ConfirmTicketResolutionRequest.php`, `ItTicketPolicy.php`, `ItTicketController.php`, `ItTicketActivityPresenter.php`, `routes/web.php`, ticket `pages/it/tickets/_dialogs.tsx`, `ticket-version-conflict.tsx`, `ticket-thread.tsx`, ticket `show.tsx`, `ticket-confirm-resolution-dialog.test.tsx`, `ItTicketResolutionConfirmationTest.php` and the existing Lifecycle regression. The new simple confirmation uses the guide's co-location, shell/body split, inline480px width token, scroll bound and shared buttons.

- Final shell UI32172 **35passed/3files/10.86s**, strict five-file ESLint exit0. Initial and interim groups are superseded, not summed. Final shell full types50772 exited0.
- Confirmation Feature54183: **23passed/1failed,24finished,319assertions, exit1**, token `it_2d3b41c8e5494d0b`; cleanup confirms schema absent. New confirmation lifecycle/authorization/blocker-redaction/audit-rollback cases passed. The one failure is the older Lifecycle line321 expectation that an IT requester lacks ordinary public reopen rights on their own sensitive ticket. Updated it to match the new requester fallback and added explicit private-work denial and expired-seven-day denial. No runtime change was needed after that run. Filtered rerun25929 is active under token `it_b2e36023cfca41a2`; do not yet call the corrected regression verified.
- Corrected filtered requester-boundary rerun25929 **1passed/31assertions**, exit0; token `it_b2e36023cfca41a2` schema absent. Log `w09-requester-reopen-boundary-rerun.txt`. The failed initial run is retained above, not relabelled green.
- Browser Build17 session85051 exited0 in4m23s (`w09-build17.txt`), asset `app-C0nsoapa.js`, manifest `4bebfed6e417f408f4cb55e86d41e0e824d05cdc60974f53999f13bf303c956b`. Guarded Preview confirms no helper or migration hash changed since Build16. CreateAndStart81767 now imports token `26a0705b30b848af`, original reviewed fingerprint `9cacb63d9939511d8b10608084e0e1153b8d1833554b5cf524a307be4f1d541a`; no real-browser confirmation acceptance yet. No runtime/assets changes while this fixture is live.

Remaining W09 scope includes meaningful resolution code/evidence/links, quality reporting, consistent resolution-to-knowledge, complete close/reopen/CSAT draft/recovery/concurrency behavior, related-ticket/duplicate/merge previews and canonical references. Complete E08 and the final release gate remain open.

## Next confirmed source gaps (not yet browser-reproduced)

- `ItTicketInteractionService::resolveWithPublicNote` still defaults the resolution code to `restored`; the current resolve dialog supplies only the public note/notification choice. Meaningful outcome selection and verification/knowledge linkage are still required.
- Existing `TicketReopenDialog` closes by clearing its reason with no dirty-close confirmation. The requester wording fix does not close that recovery criterion. Existing close and CSAT editors also need their complete W09 recovery review.
- `ItTicketMergeService` moves comments and watchers but never saves the target ticket to advance its version/reconcile conversation responsibility. Its audience comparison checks requester/requested-for identity but does not compare sensitivity or the staff access scope; a merge must preserve internal-note/file restrictions as well. Source tasks/approvals and old references require the full planned preview/canonical-preservation work. These are current-code observations, not a claimed successful exploit or completed merge acceptance.

Do these after the frozen Build17 browser journey and exact teardown. Do not change runtime mid-verification or label W09 complete from the confirmation slice.
