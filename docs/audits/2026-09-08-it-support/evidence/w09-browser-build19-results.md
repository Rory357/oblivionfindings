# W09 Build19 rating dialog verification

10 September 2026, approximately12:05–12:11 NZ. Rating layout and transport slice verified; nested-discard focus failed and is being corrected. W09/E08 remain open.

## Identity and isolation

Build67238 exited0 in3m35s. Actual browser DOM script: `http://127.0.0.1:8766/build/assets/app-0i9SE3tg.js`; manifest `bb7fa21ed52b1e3e5c8aea6e5810453684d3f1852b258c528ad23927713d1300`. CreateAndStart55767 exited0, PID4936, readiness00:04:12 UTC. Token `c3bec69077b34e5e`, exact database `oblivion_it_draft_browser_c3bec69077b34e5e`, original fingerprint `ab1960a729b9867aac2d04a53000b33f6fbb3967db1836cd90eb5ec1b398b289`. All six helpers and migration hashes unchanged from18. Runtime/assets frozen throughout browser verification. Array mail, sync queue, synthetic data only; no real communications or provider changes.

## Actual browser evidence

- Normally signed in as technician3; resolved ticket4 with an explicit synthetic public note and notifications unchecked. Actual persisted-resolution acknowledgement inspected.
- Normally logged out and signed in as requester1 (`it.request` only). Staff work controls absent; compact rating action appears in People & feedback. Opened the new dialog with Enter. Actual screenshots inspected at the naturally sized desktop window; no resize or mobile work.
- Approved 720px dialog, shared header/footer, visible rating/comment label and character count. The ordinary form and full stale-rating comparison both fit within the current desktop window. The editor no longer occupies the narrow supporting rail.
- Arrow keys selected three stars. Escape opened the discard confirmation. Cancel retained the exact comment. **Focus defect reproduced twice:** starting from an explicitly focused textarea, cancelling the nested prompt left `document.activeElement` on BODY after the exit animation. This is not a passed keyboard criterion.
- Deliberate discard closed the editor and returned focus to its trigger. Reopening started without the discarded score/comment. An early chained click during overlay transitions did not establish settled dismissal; a subsequent explicit confirmation and snapshot established the closed state. No successful save was fabricated.
- Tab9 retained a three-star proposal. Fresh tab10 saved competing two-star feedback with strict acknowledgement. Tab9's stale submission was refused without success; fields remained retained/disabled. GET review showed the actual competing rating/comment. Explicit adoption kept the proposal, and a separate Update rating saved it.
- Edited to four stars and a new comment. Normal logout in tab10 expired the shared session. Tab9 save concealed all textarea fields and showed sign-in recovery. Normal same-account login in tab10, GET review, explicit adoption and separate Update rating restored and saved the exact retained comment.
- Done closed the saved editor; requester Confirm and close acknowledged closure. Bounded tab9 error/warn console read, limit8, returned `[]`.

## Persisted verification and cleanup

Read-only evidence helper exited0: `w09-browser-build19-record-evidence.json`. Ticket4 closed/version8, score4, original first-submission timestamp00:08:29 UTC unchanged. One rating submission, two rating updates and one requester confirmation; matching actor audits. Failed stale/session attempts produced no rating events. Public comment1 speaker `it`, source `browser`, canonical last-public pointer1 and next-response requester agree.

StopAndRemove85241 exited0 using the original fingerprint. Independent postflight exited0; `w09-browser-build19-cleanup-postflight.json` confirms exact schema and owned directory absent, no database mutations during postflight. No live browser environment remains. Tabs9/10 are historical and must not submit.

## Follow-up

Focused regression `w09-rating-dialog-focus-before.txt` reproduced the browser focus failure:1 failed/18 skipped, exit1. Local rating-body focus restoration now remembers only controls physically inside the editor (excluding React portal focus events); fallback is its Close editor button. This correction is unverified pending focused tests/build/browser. Prior modal tests88730:53 passed/4files/10.24s, exit0; lint0 and full TypeScript87253 exit0 preceded this focus fix. No claim of whole-package completion.
