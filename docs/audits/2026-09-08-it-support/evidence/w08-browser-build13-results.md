# Build13 approval browser verification

10 September2026 approximately09:11–09:20 NZ. **Bounded observations only; the mandatory separate Review/Save gate failed. W08 remains In progress.**

## Exact environment

- Checkout `C:/Users/steph/Herd/oblivionfindings`; compiled entry `app-J8yMKukg.js`, manifest `14cb526b3e7ebdca1c8636d63c2274d2b0cd014850b7a27a21515c64b5c9e871`. Build87749 exit0/4m03s; actual DOM script matched.
- Token `a1a5ffb2afc14c6b`, original fingerprint `0531a132cdb8a1d7756d173ef5339bdfa9834811552c25d9676e56ee497bd873`, synthetic schema `oblivion_it_draft_browser_a1a5ffb2afc14c6b`, registry1005. Start93857 exit0/PID39256. Fixture helper adds synthetic cover5 and approval ticket5; requester1, technician3, restricted technician4 remain canonical local fixtures.
- In-app browser1/tab9, route `http://127.0.0.1:8766/it/tickets/5?tab=approvals`. Natural1133×856, dark theme inherited, no viewport or appearance changes. No interaction with unrelated Chrome2. Real sign-in/CSRF; array mail, sync queue, cleared provider configuration and blocked stray HTTP.

## Observed outcomes

1. Current approval workspace loaded with no fabricated current request, required-work0 and unmeasured SLA. Request uses the approved WizardShell; screenshot inspected inline at1133×856.
2. Continue with no primary stayed on Responsibility, showed inline/error-summary feedback and moved focus to the primary combobox. The raiser3 was excluded. Primary4 and cover5 were eligible; primary4 was disabled in the cover list.
3. Escape from the reason opened a dirty-close confirmation. Pointer Cancel restored focus to the same reason textarea (`_r_4b_-reason`). Keep draft and close removed the private form and returned focus to Request approval. The workspace showed opaque retained-draft metadata. Reopening did not reveal the original reason; explicit Resume performed current candidate authorization and recovered reason/primary/cover and step.
4. **Confirmed defect:** pointer Continue from step2 changed the same rendered Button into the form-associated submit button. The browser's default click action submitted before a separate review confirmation. A typed request receipt succeeded and created approval1. This is a failed review gate, not successful three-step acceptance. The component tests did not reproduce that browser default-action timing.
5. The resulting synthetic request correctly showed primary4, cover5 and requester3 separately, with no human verdict. Technician3 could not self-approve. A reasoned Cancel request action saved a canonical cancellation, preserved the original request reason, and displayed the separate cancellation time/reason. Approval history loaded the canonical cancelled record through its private read endpoint. No console warning/error was recorded in this bounded journey.
6. Initial automated `fill()` on datetime-local fields changed their DOM values but did not commit React state; a subsequent field edit reset them. Native ArrowUp/ArrowDown input produced committed values. A fresh unsent draft with native-entered deadline10Sept12:00 and reminder10Sept11:00 retained both exact values across Keep/close/explicit Resume. This is evidence of a tool-entry limitation, not a demonstrated loss of app date recovery. The diagnostic draft was explicitly discarded without another approval request.

## Persisted metadata and cleanup

`w08-browser-build13-record-evidence.json` is a bounded read-only observation under the reviewed owner/manifest guard. Ticket5 remained open/version3. Exactly one approval existed, cancelled by3; primary4/cover5, no decider/decision time, two command receipts, two approval events and two audits. Reasons are hashes only. The original request has no deadline/reminder because its initial fill did not commit; no date persistence claim is made for that request.

Owned stop/removal85282 exit0; independent `w08-browser-build13-cleanup-postflight.json` exit0 confirms exact schema and directory absent. PID39256 was stopped by the owned helper. Never reuse the token. All working Herd data/provider settings remain unchanged.

## Follow-up

`TicketApprovalDialog` now prevents the Continue click's default action and gives Continue/Save different keys, matching the established task wizard safeguard.12tests/2files/5.14s and scopedlint0 passed (`w08-approval-review-fix-tests.txt`); date Resume assertions were added. **This fix requires a fresh actual browser pass.** Build14 is running in26270, `w08-approval-desktop-build14.txt`. Do not count Build13 as approval request/review acceptance.

Pending full approval journeys include restricted primary/active-cover decisions, expired/replacement paths, exact older-history targets, personal approval handoff, native navigation/lost-acknowledgement recovery and catalogue/template bindings. Full W08/E07, W00–W27/E01–E23 and release gate remain open.
