# W14 handoff desktop browser verification — 12 September 2026

Mappings: W14 / F06 / B04 / E13. The complete package, remaining recovery scenarios and release gate remain open.

## First browser run

- Owned token `140c194b52f045c0`, fingerprint `6627226a7eac54572c1c9a1eeed6ed16c0d64d2c1ab2562ec31b1cbc61c0f96b`. CreateAndStart21637 completed terminal0; server PID11844. The identity endpoint confirmed this checkout, exact owned schema, normal CSRF, array mail, sync queue, disabled SSR and manifest `746e400799272446eaa626b3350a34088d6026c9010d08eac0b0528aa27d9851`. The browser DOM loaded `app-1Ak8Fi_9.js`.
- Only owned in-app tab8 was used, at its existing desktop size. No resizing. The user's tab2 was preserved. A wizard screenshot was inspected in tool output; no saved screenshot file is claimed.
- Technician3 opened `/control-room/alerts/5`, Linked records → Prepare IT handoff. The approved WizardShell presented create/link choices, explicit approved Site A, technical details and review.
- Leaving required fields empty showed validation while retaining the title. Filling Network, Site impact, High urgency, the active canonical network service and explicit technical reason produced the correct review. Keyboard submission created **IT-000020** and focused **Open IT ticket** after confirmed success.
- Returning refreshed the canonical alert link and preserved **Open / Operational response active**. Reopening the handoff showed **This alert already has IT work**, with the existing ticket and no create action. Opening ticket20 showed the submitted technical description, canonical service, real synthetic assignee, urgent priority and measured SLA clocks.
- On `/control-room/alerts/6`, the search field's Enter key searched without submitting the handoff. Selecting **IT-000018**, entering a reason and confirming the review linked existing work. Success focused the canonical-ticket action; no extra ticket was created.
- On alert7, Escape opened a discard confirmation with Cancel focused. Return cancelled the discard and retained the title. A later explicit Discard draft returned to the unchanged alert; no handoff receipt or link was created.
- Technician direct alert9 (unapproved Site C) and alert10 (private source context) each returned404 without source content.
- Audit9 signed in normally and opened alert5's Linked records. The permitted canonical IT link was available, but Prepare IT handoff and operational mutation controls were absent. A separate direct handoff-discovery navigation was blocked by browser tooling (`ERR_BLOCKED_BY_CLIENT`); it is **not** a passed HTTP permission check.

## Persisted reconciliation and cleanup

`w14-handoff-browser-final-records.json` was captured with a read-only PDO transaction scoped to the exact token/fingerprint. Alert5 has one source link and one committed created receipt for ticket20; alert6 has one source link and one committed linked receipt for ticket18. Both remain open. Alert7 has zero links/receipts. Total tickets:20 (19 fixtures plus one created ticket).

The initial read helper used the class name instead of the application's morph alias and an uncast JSON comparison, causing empty link/receipt output. That observation is preserved in `w14-handoff-browser-after-create.json`; it is superseded by the corrected helper and `w14-handoff-browser-after-create-final.json` / final records. No database correction was needed.

Owned tab8 closed. Exact-fingerprint StopAndRemove69870 completed terminal0. Independent read-only postflight completed terminal0 and confirmed both schema and owned directory absent. See `w14-handoff-browser-cleanup.txt` and `w14-handoff-browser-postflight.json`.

## Browser-discovered defect and correction

After returning from handoff, the new alert workspace briefly received focus but the outgoing Radix dialog's deferred close handler restored focus to the workspace header instead of Prepare IT handoff. This is a **confirmed browser failure**, not a verified journey.

The shared WizardShell now forwards its existing DialogContent close-focus contract. Only the handoff that replaces an alert workspace suppresses its own deferred restoration, allowing the replacement workspace to own return focus. Full-page handoffs retain normal restoration. No global focus behavior is changed.

The real-dialog replacement regression and related workspace/handoff suites passed **35 tests across4 files, 5.37s, terminal0** (`w14-handoff-return-focus-tests.txt`). Build and real browser retest are recorded in the current resumption note; do not assume the source correction is browser verified until that retest passes.

## Remaining scenarios

Retest corrected return focus with current assets. Complete stale target/source refresh, lost response and immutable retry, pending cancellation and reload recovery, expired/revoked access concealment, and full-page handoff integration. Previous backend/hook tests remain evidence for their own scope; they do not substitute for these browser journeys.

## Second run: focus, actual lost response and two-editor recovery

This section supersedes the matching pending cases above, without closing the entire package.

Owned token `b3093c8c6f734216`, fingerprint `80ff3de5e75b11b7125efc39fac7e3b4697b37ad50c1652276658dfaf2461078`; CreateAndStart79528 terminal0, server5572. Identity and the rendered script confirmed manifest `d5b0fbd4e99828302ee12fa4065538b2fdd99638a492a5efe6d085e1db4a31bb` and `app-B1t21vFx.js`, the return-focus build80138 (3m17s, terminal0). Normal CSRF and synthetic array/sync sinks remained active. Only owned in-app tabs9/10 were used; no resizing.

- **Return focus verified:** On alert7, Back to alert returned focus to Prepare IT handoff. Focus remained there after the workspace refresh, and Return reopened the wizard. After a pending-command acknowledged exit, the same opener retained focus. This closes the first run's confirmed focus defect for the replacement-dialog journey.
- **Actual lost-response recovery verified:** The guarded helper held only synthetic alert7's row with SELECT FOR UPDATE for25 seconds, then rolled back without changing records. Lock session16337 emitted held/released evidence and terminal0. The browser submitted its reviewed link to ticket18, used Stop waiting while pending, saw an unconfirmed outcome and acknowledged Leave and check later. After the lock released, a full page reload and reopened wizard offered Check saved result and Cancel pending handoff, without the old private form or an in-memory retry action. Check saved result recovered the committed **IT-000018** outcome and focused Open IT ticket. No resubmission was performed.
- **Stale source recovery verified except explanatory copy:** Tab9 reviewed alert8 → ticket19. Tab10 acknowledged alert8 through its normal confirmation form. The old review was rejected; its reason was retained and save disabled until Refresh records and review again. Refresh cleared the ticket selection and required explicit selection before proceeding. However, the banner showed only the generic validation message, omitting the server's alert-changed explanation. That specific UI failure is corrected in source below and still requires a rebuilt browser check.
- **Stale target recovery verified:** After refreshing the source review, tab10 added an internal note to ticket19 through the canonical composer, advancing its version. Tab9's save was rejected with the clear selected-ticket-changed message, preserved reason and disabled save. Another refresh/re-selection/review saved one link to ticket19. The operational alert remained Acknowledged. The focused tab's final captured error log list was empty.

Read-only final reconciliation (`w14-handoff-focus-browser-final-records.json`) confirms exactly one handoff receipt/link for alert7→ticket18 and one for alert8→ticket19, despite the lost response and two rejected reviews. Alert7 remains open; alert8 retains the second editor's acknowledged state. Total tickets19, unchanged from fixtures. The intermediate recovery records and bounded lock log are preserved separately.

Owned tabs9/10 closed. StopAndRemove80251 terminal0; independent postflight terminal0 confirms exact schema and owned directory absent. See the focus-browser cleanup/postflight artifacts. User tab2 and the working database were preserved.

## Validation-detail correction awaiting rebuilt browser check

The handoff review now renders the server's field-error summary, including non-visible fields such as alert_version, inside the focused error region. Current access loss conceals the summary immediately. It retains the existing refresh/review requirement and never changes the submitted command to force a retry.

Regression plus shell/workspace/hook suites: **36 passed /4 files /5.48s / terminal0** (`w14-handoff-validation-tests.txt`). Full TypeScript terminal0; scoped lint terminal0 with the existing epoch-ref warning. Build80114 (`w14-handoff-validation-build.txt`) was launched for this change; its exact completion and browser retest are the next step.

Still open: final explanatory-copy browser retest, unchanged-request retry after uncertain response, server cancellation before/after commit in the browser, expired/revoked access concealment, full-page integration, remaining W14 source/operational dependencies and the final release gate. Do not conflate the fixture named cancel with a verified cancellation: that fixture was used to verify lost-response recovery.

## Third run: immutable retry, cancellation and session loss

This section supersedes matching pending cases above. Owned token `74a3f446003f4ad8`, fingerprint `d138038ce9028707ef2ad7ff08c7e1ee0398b7ed7d831d759a7585f1dae7f401`; CreateAndStart90094 terminal0, server15436. Build80114 completed terminal0/3m18s. Identity and browser script confirmed `app-B4L1HiuJ.js` and manifest `d8c7c30eac6872893a143705a83d74ff3b816b762f2a4f13f1ed58f7328aceb8`. Only owned in-app tabs11/12 were used, without resizing. Normal CSRF, fake array mail and sync queue remained active.

- **Cancellation before commit:** A bounded loopback-only fault proxy returned503 for one exact synthetic alert7 handoff POST before forwarding it. The UI displayed an uncertain outcome. Cancel pending handoff contacted the real application and returned Handoff cancelled, with no created or linked ticket. Read-only `w14-handoff-browser-cancelled-records.json` confirms one cancelled receipt, zero links and19 fixture tickets.
- **Unchanged retry:** A fresh logical handoff from alert7 to ticket18 encountered the same controlled pre-forward503. Retry unchanged request reused UUID `f9b36c7f-8dc8-433a-89b9-f2a9d53f80b7` and identical raw-body SHA256 `45192961f2af2cdcc657c89c86752b6edb842ea0cbe18d23e2c828ee83da38ab`. The proxy forwarded the retry to the real app. `w14-handoff-browser-retry-proxy-trace.jsonl` records both requests without their body or credentials.
- **Cancellation after commit:** During that retry the guarded helper held only synthetic alert7's row for25 seconds. Stop waiting exposed the uncertain state; the helper then rolled back its lock without changing records (session51757 terminal0). Cancel pending handoff subsequently returned the already committed IT-000018 result and focused Open IT ticket. It did not falsely report cancellation or duplicate work.
- **Validation explanation:** On direct `/control-room/alerts/8`, tab11 reviewed a link to ticket19. Tab12 acknowledged the alert through its normal confirmation. Tab11's stale submission displayed the accessible Handoff validation errors list with the exact explanation: The operational alert changed. Refresh the handoff before submitting again. The reason remained visible; save stayed disabled pending refresh/review. This verifies the final field-summary correction on current assets.
- **Sign-out/session-loss concealment:** Tab12 signed out using the normal user menu. Tab11's Refresh records and review again then displayed IT handoff access unavailable and concealed the prior technical reason, selected ticket, site details and validation summary. Back to alert exited without a discard confirmation and completed the normal redirect to `/login`. This verifies explicit sign-out/session loss, not time-based expiry or role revocation.

Final read-only reconciliation in `w14-handoff-validation-browser-final-records.json`: alert7 remains Open with exactly one source link to ticket18, one committed receipt and the separate earlier cancelled tombstone. Alert8 remains Acknowledged with no handoff link or receipt. Total tickets19, unchanged from fixtures. No working-database operations or real notifications occurred.

The first proxy attempt produced cross-origin module loading failure; the second encountered the UI's canonical-link origin validation. These were isolated test-harness limitations, not application defects. The final proxy rewrote loopback HTML asset URLs and canonical ticket href origins only, while forwarding actual application responses and retaining security checks. All four proxy sessions89213/29790/17011/60194 were stopped with terminal0. No production source was changed to accommodate the proxy.

Owned tabs11/12 closed. Exact-fingerprint StopAndRemove87576 terminal0; independent postflight terminal0 confirmed schema and owned directory absent. See `w14-handoff-validation-browser-cleanup.txt` and `w14-handoff-validation-browser-postflight.json`. The user's tab2 was preserved.

## Remaining scope after these runs

The direct/shareable `control-room/show.tsx` route renders AlertWorkspaceDialog, so the direct-route integration was exercised throughout these journeys. The earlier description of a separate full-page flow was inaccurate; production callers use the shared workspace. List-origin entry and return still need explicit browser coverage. Timed session expiry and role revocation must not be claimed from the sign-out case alone. Remaining W14 source/operational dependencies, broader package criteria, architecture failure and the final release gate remain open.
