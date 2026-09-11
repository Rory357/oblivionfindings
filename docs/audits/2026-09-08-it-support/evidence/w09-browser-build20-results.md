# W09 Build20 rating dialog focus correction

10 September 2026, approximately12:22–12:24 NZ. This completes the focused rating-dialog layout/keyboard slice; W09/E08 and the whole Goal remain open.

## Identity and checks

Build11560 exited0 in3m22s. Actual DOM script read from the browser: `http://127.0.0.1:8766/build/assets/app-BoCoI-2Y.js`; manifest `6c799eb58efd644a070326a008be0a0126ac499e66b63c8471fc479f1798ba51`. CreateAndStart91280 exited0, PID56128, readiness12:21:31 NZ. Token `98a93f78da0244f4`, exact schema `oblivion_it_draft_browser_98a93f78da0244f4`, original fingerprint `d86d45e51f38fbd79a24582c1a8a5bff1527967843aad5894ae0a06ba88e3228`. All six helper/migration hashes unchanged from19. Runtime/assets frozen during verification. Array mail/sync queue, synthetic-only fixtures, normal auth/CSRF, no provider changes or real communications.

Pre-build checks: focused UI43984 **54 passed/4files/9.40s,exit0**; four-file ESLint0; full TypeScript24449 exit0. The new focus regression failed before correction (1failed/18skipped,exit1), matching the actual Build19 browser defect. Logs retained.

## Actual browser journey

- Normally signed in as restricted technician4 (request/view/manage, no sensitive-work capability), resolved the approved-site ticket4 using a synthetic public note, notifications unchecked. Actual saved acknowledgement inspected. Normal logout, then normal sign-in as requester1 (request only).
- Opened the rating editor with Enter. Arrow keys moved one→two→three stars. Entered a comment, explicitly focused it and pressed Escape. Cancelled the discard prompt with Enter. After the overlay closed, read-only DOM evidence showed active element TEXTAREA with label Feedback comment and the exact retained text. Screenshot visually confirmed its visible focus ring in the approved dialog.
- Clicked Close editor; cancelled its confirmation. Focus returned to the Close editor button. Activated it again and deliberately confirmed Discard feedback. No open dialog remained; focus returned to Rate IT’s help.
- Reopened with Enter. No textarea and no selected star remained: the discarded proposal was cleared. Selected four stars, entered new feedback and explicitly submitted. Strict saved acknowledgement appeared.
- Done returned focus to Edit rating. Reopening showed the actual saved four-star score and exact comment. Escape from this unchanged editor closed without a discard prompt; focus returned to Edit rating and no dialog remained.
- Bounded error/warn console read, limit8, returned `[]`. No browser resize or mobile work. This focused check did not repeat unchanged conflict/session transport; those real journeys passed in Build19.

## Persisted evidence and cleanup

Bounded read-only helper exited0: `w09-browser-build20-record-evidence.json`. Ticket4 resolved/version5, score4, one `csat_submitted` event and matching requester1 audit; discarded proposals produced no rating records. Public resolution comment1 belongs to restricted technician4 with speaker `it`, source `browser`, canonical public pointer1 and requester next-response party.

Guarded StopAndRemove98013 exited0. Independent postflight exited0: exact schema and owned directory absent (`w09-browser-build20-cleanup-postflight.json`). Postflight performed no database mutations. No live server or test session remains; browser tabs refer to disposed fixtures and must not submit.

## Next work

Continue W09 resolution quality: explicit outcome, public explanation and verification/evidence; permitted canonical known-error/article links and consistent reviewed knowledge draft integration. Reopen/close recovery, related/duplicate/merge safeguards and reporting remain open. Production settings unchanged; protected design sources have no diff.
