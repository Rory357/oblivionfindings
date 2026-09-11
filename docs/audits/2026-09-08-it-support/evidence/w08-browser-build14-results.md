# W08 — Build14 desktop approval verification

Observed10 September2026, approximately09:31–09:37 NZ. Bounded W08/E07 evidence, not whole-package acceptance.

## Environment and actual assets

- Checkout `C:\Users\steph\Herd\oblivionfindings`; unchanged HEAD `c4817a1dfe5462b76ed10e046e5c03039a5f4966`, existing dirty work preserved.
- Build14 session26270 exited0 in3m30s. Manifest `d2d7fc9baa7a5a60c69d643966508d4e6d5fa52dde303e619eb61b5629787b3f`; actual in-app DOM loaded `http://127.0.0.1:8766/build/assets/app-CQGfB0JP.js`.
- Six-helper/migration/asset preview `w08-browser-build14-preview.json`, fingerprint `083363bd21ab688450c24cf5bdb84d669580524f3c114037be91982e17d75930`.
- Fresh token `0602c48a4735490d`, exact schema `oblivion_it_draft_browser_0602c48a4735490d`, PID20724. Start61516 exited0. Canonical synthetic accounts/Site access, real sign-in/CSRF, array mail, sync queue, cleared providers; no real communications.
- Existing in-app browser1/tab9, natural1133×856 desktop, inherited dark theme. No browser resizing, mobile work or theme changes.

## Verified browser journey

1. Technician3 opened ticket5 Approvals. The approved WizardShell showed responsibility, reason/timing and review steps. Primary4 and cover5 were explicit eligible choices; the raiser was absent from its approver list.
2. Native date entry committed deadline10Sept12:00 and reminder11:00. Pointer Continue reached **Review only**. While review remained visible, the guarded read-only database observation found ticket5/version1 with **zero approval records, receipts, events and audits**. This closes the specific Build13 native submit defect. See `w08-browser-build14-before-save.json`.
3. A separate Request approval click created request1, then the typed confirmation and Done control appeared. The page showed named primary/cover, dates and reason; the raiser had Cancel request but no Approve/Reject controls. The review screenshot was inspected inline in the CUA result; no persistent screenshot file is claimed.
4. Normal sign-out/sign-in as restricted primary4 showed exactly one assigned IT approval in **My Day**. Its button reached `/it/tickets/5?tab=approvals#approval-1`. All Tasks showed the same canonical approval and owner. Keyboard Enter opened its task preview; Review IT approval reached the canonical decision workspace. The preview does not itself contain a Reject button; an initial selector attempt before following Review IT approval correctly found no such control.
5. Empty rejection was refused with a focused reason textarea. Escape opened the dirty-close confirmation. Keep draft and close produced an opaque retained-draft notice; explicit Resume revalidated access and restored **reject**, not approve, and the exact reason. Saving returned Rejection recorded. My Day then removed the pending approval (its My tasks region is absent when empty).
6. Primary4 raised request2 with technician3 as its named primary and user5 as cover. The separate review/save sequence worked again. History showed both the new pending request and immutable rejected request1 with distinct original request/decision reasons and actual actors/times.
7. Cover5 could inspect the work but had **no decision or cancellation controls while primary3 was eligible and available**. This proves inactive-cover denial, not active absence takeover.
8. Technician3 approved request2 with an explicit note. History retained request1 rejected and request2 approved with their separate actors/reasons/times. No new-request/decision controls remained on the approved current request.
9. Requester1 opened a copied approval deep link. The page selected its permitted public Conversation, omitted the Work/Approvals workspace, and exposed no private request/decision text, responsibility choices or history controls. A separate attempt to navigate directly to the private JSON history URL was blocked by the browser adapter (`net::ERR_BLOCKED_BY_CLIENT`); **no application HTTP denial is inferred from that attempt**. Direct-ID/server privacy remains supported by the existing isolated Feature tests.
10. Bounded error/warning browser log checks returned empty arrays during the successful application journeys. Adapter selector/navigation errors above are recorded separately.

## Persisted evidence and cleanup

`w08-browser-build14-record-evidence.json` was captured by the existing guarded read-only helper, without app bootstrap or raw private reasons. Ticket5 is open/version5; request1 is rejected by4, request2 approved by3, each with the selected primary/cover. There are exactly4 command receipts,4 approval events and4 approval audits, one per actual request/decision. The first request's dates are persisted; the second deliberately has neither optional date. Reasons are hashed in this artifact. Database datetime and timestamp columns have different storage representations; do not compare their raw strings as if they share a storage timezone.

StopAndRemove84496 exited0 using the original reviewed fingerprint. Independent `w08-browser-build14-cleanup-postflight.json` exited0 and confirmed the exact schema/root are absent. Working Herd environment unchanged. Never reuse the token or submit a stale disposed-fixture form.

## Discovered gaps and follow-up

- **Confirmed:** All Tasks queue/preview reported Due in1d for the same deadline that My Day correctly reported Due in2h. `resources/js/pages/tasks/types.ts` now uses the existing `formatRelative` helper below24hours. Focused task tests **10passed/3files/3.45s**, lint0; `w08-approval-task-due-tests.txt`. This correction is later source and needs fresh built-browser verification.
- **Confirmed source gap, now changed but tests running:** new ownerless requests were allowed by the older request format. The canonical new-request boundary now requires a selected eligible primary; original saved ownerless command receipts still replay before that validation. Tests include a historical receipt and JSON/form/direct-service denial.
- **Confirmed source gap, now changed but tests running:** scheduler-lag expiry prevented replacement. Current policy now permits replacement after the deadline; the locked request transaction records expiry/audit/outbox and creates the new generation atomically. Failed replacement preserves the previous evidence. No operational timing defaults were invented.
- Still open: browser active absence-cover, expiry/reminder and lost-ack/session/concurrent decision recovery; exact historical approval target resolution beyond the current summary; catalogue/template binding to the correct approval generation; full W08/E07 and subsequent work packages. Build14 does not claim any of these complete.
