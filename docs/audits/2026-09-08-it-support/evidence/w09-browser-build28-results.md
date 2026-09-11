# Build28 — canonical ticket navigation and original history

10 September 2026. W09 / B07 / E08, with W07/W08 follow-ups below. These are actual in-app browser observations, not an assertion that the complete merge lifecycle is verified.

## Environment and source

- Checkout `C:\Users\steph\Herd\oblivionfindings`; in-app browser1/tab9 (`itMergeBrowser`), loopback8766. No viewport resize; actual observed desktop viewport **1235 × 856** throughout this journey.
- Build28: exit0 / 4m38s; DOM entry `app-5s_A4obS.js` matches the current manifest `cdd72acb1f41a16f8da82ca9ee389baa612d52b09c1474fce698d47b9edbdcd4`.
- Disposable token `68bc520ebf5d468d`; exact review fingerprint `9c846b0b64ec9958867ea3235ccca75a25718af0bd9a2696e72ae08b1fd3885a`. Six runtime helpers, all migrations and schema matched reviewed27. Bootstrap82534 exit0; ready=true; serverPID53304. Array mail, sync queue, external HTTP blocked by the unchanged reviewed runtime, normal login/CSRF. No working Herd reset or provider communication.
- The browser found an existing policy projection defect: a merged original offered **Reopen ticket** although its canonical writer refuses reopening. `ItTicketPolicy::reopen` now rejects merged records. The same Build28 frontend was reloaded against that corrected backend and the control was absent. Policy regression evidence is tracked separately in `w09-merge-navigation-results.md`.

## Actual journeys

1. Fresh login as restricted technician4 (`w06-restricted@demo.test`), normal `/it/tickets/6` with twelve synthetic historical rejected approvals. Entered internal note `Build28 synthetic internal investigation retained through canonical navigation.` and submitted with Ctrl+Enter. The note appeared as Internal and the command acknowledged it.
2. Used the existing merge action on6, selected1, and submitted reason `Build28 synthetic duplicate consolidated for navigation verification.`. The surviving ticket1 showed the same internal note. The old merge dialog improperly remained mounted against the new ticket ID; closed it using its normal Cancel control. This is an open merge-command defect, not passing dialog acceptance.
3. Visited `/it/tickets/6?tab=history`. The browser reached `/it/tickets/1?merged_from=6&tab=history`, selected History, showed the merge event, and offered **View original record IT-000006**. Pressing Enter on that link opened `/it/tickets/6/original`.
4. The original showed the authorized surviving reference and a read-only conversation. Work → Approvals, including keyboard activation, remained at `/it/tickets/6/original?tab=approvals`. All original request/decision details remained readable to the technician. After the policy correction and reload, no Reopen button remained. Watcher modification and ticket editing controls were absent.
5. Expanded **Approval history · 12 requests**, loaded it using Enter, then used Enter on **Older requests**. Page1 displayed10 histories; Page2 displayed requests2 and1. IDs/reasons were subsequently reconciled with the database baseline. Screenshots of the original approval view and requester view were visually inspected in CUA output; no screenshot file was saved. No horizontal document overflow was observed. The final-page pagination action lost keyboard focus to BODY after its button disappeared; this remains an open W08 focus defect.
6. Logged out through the normal menu; logout returned to the public page, so explicitly opened `/login` after a locator found no login textbox there. Signed in as requester1. Old `/it/tickets/6` reached `/it/tickets/1?merged_from=6`. The internal note and Work controls were absent. Enter on **View original record IT-000006** opened the source; private request/decision reasons, approval-history control, internal note, Work and Reopen were absent, and the conversation was read-only.
7. Requester1 opened `/it/tickets/3/original`, an unrelated fixture: rendered404/Not Found. Returning to their own old6 link recovered normally to survivor1. Both queried warning/error log snapshots returned empty arrays; this does not replace explicit response/access assertions.

## Persisted reconciliation

`w09-browser-build28-records-before.json` and `...-after.json` come from the bounded read-only helper tied to this exact owner, token, readiness and assets. `w09-browser-build28-reconciliation.json` records **9 checks true**:

- Source6 closed and points to1; survivor1 remains open; unrelated4 unchanged.
- All12 approval IDs, original parents, statuses and request/decision reason hashes unchanged.
- Exactly one internal note remains, author4 and same body hash, now on1.
- Exactly two matching merge events and one source6→target1 audit; reason hashes match the submitted synthetic reason.
- Both evidence reads performed no database mutation.

The same reads confirm existing merge-writer gaps: source6 has closed status but workflow_state remains submitted, and survivor1's version remains1. These must be corrected by the guarded merge command; the nine bounded reconciliation checks do not certify those lifecycle fields.

## Open acceptance and cleanup

- W09: inventory/preview, current-version commit, frozen source/target/actor identity, settled success state, complete record treatment, cancellation/recovery, source-history discovery and scoped relationship/duplicate commands remain open. Do not treat the original archive as a substitute for transferring or otherwise completing outstanding work.
- W07: after the actual note acknowledgement, the old composer displayed both “Internal note added”/“This draft was submitted successfully” and “Browser copy 1 · earlier result unconfirmed”, with the composer disabled. This persisted across multiple reads before merging. Investigate the committed draft/RAM handoff; do not mark that contradictory interaction verified.
- W08: correct final-page focus and context-inappropriate “request a new approval” guidance on read-only settled history.
- Cleanup39583 **exit0** for the exact token/fingerprint. Independent `w09-browser-build28-cleanup-postflight.json` **exit0** confirms both schema and owned directory absent. Working Herd environment unchanged. The browser tab is now stale and must not submit into another environment.
