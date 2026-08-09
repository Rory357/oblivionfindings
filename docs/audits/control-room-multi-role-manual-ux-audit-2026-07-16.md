# Control Room multi-role manual acceptance and usability audit — 2026-07-16

## Executive decision

**Recommendation: unsafe to release for the tested Control Room → Incident → H&S governance journey.**

Seven distinct personas were exercised sequentially in actual desktop Chrome against the live development server. The shared journey successfully created and linked exactly one Control Room alert, one incident, one H&S event, one completed investigation, and one corrective action. It then reached a reproducible safety-critical blocker: the independent verifier could not see the action owner's amended completion evidence or the return-for-rework context in the verification surface. The action was therefore not verified, and the H&S event, incident, and operational alert were deliberately left open.

The same journey also exposed a material WorkSafe risk. The H&S owner had no visible way to mark the event notifiable. Because the stored flag remained false, the closure checklist reported the WorkSafe requirement as complete even though the H&S acceptance note explicitly said WorkSafe notification was an immediate priority.

This was an audit only. No application code was edited, fixed, committed, pushed, deployed, or cleaned up.

## 1. Tested baseline

| Item | Tested value |
|---|---|
| Live URL | `https://oblivionfindings.com` |
| Live deployed SHA | `b5b5df463ce788fbbf988c74f5142b7fcbb52628` |
| Live server branch/worktree | clean `main` at `/var/www/oblivionfindings` |
| Read-only source-correlation worktree | `C:\Users\steph\.config\superpowers\worktrees\oblivionfindings\codex-ticketing-main-integration`, clean `main` at the same SHA |
| Browser | user's actual desktop Chrome through the Codex Chrome extension |
| Viewport | 1440 × 900 |
| Audit date | 16 July 2026, Pacific/Auckland |
| Fixture seeder | `IncidentHandoverE2ESeeder`, run exactly once |
| Marker | `MANUAL-CR-HS-20260716-164157` |
| Site | `9401` — Playwright Incident Handover House |
| Client | `9401` — Playwright Aroha Handover |
| Console result | no final-pass console errors; React Select warnings were observed during earlier workflow steps |
| Server log result | expected INFO creation events for HS/INV/CA; zero `ERROR`, `CRITICAL`, `ALERT`, or `EMERGENCY` entries in the 2026-07-16 Laravel log |

The canonical working directory was already dirty and detached at `ec440c97ed019f6e92b32f44706a071da6e454e1`. Its unrelated user changes were not modified. This report and the audit screenshots are intentionally uncommitted.

### Fixture and role note

The canonical fixture contained only one suitable Control Room operator. The brief explicitly permitted a tagged second incoming operator when required for shift-handover testing. One live demo fixture account was therefore created for the audit:

- `manual-cr-incoming-20260716@demo.test`
- user ID `117`
- role `coordinator`
- site `9401`
- the same 17 explicit Control Room permissions as the fixture operator

The account remains in the development fixture data because destructive cleanup was outside the audit scope.

## 2. Prioritised findings

| ID | Priority | Finding | Release impact |
|---|---:|---|---|
| F-01 | P1 | The verifier cannot see completion notes, amended evidence, or supporting evidence paths in the verification pane. | Independent verification cannot be performed safely; the golden journey is blocked. |
| F-02 | P1 | There is no visible post-review control to mark the H&S event WorkSafe-notifiable. The closure gate then treats WorkSafe as complete because the stored flag is false. | Material regulatory false-completion risk. |
| F-03 | P1 | `/tasks` labels the corrective action `Completed` while H&S truthfully shows `Awaiting verification`. | Managers can believe the responsibility is finished when governance remains open. |
| F-04 | P1 | A recommendation-created corrective action is unassigned and the H&S owner has no visible general assignee picker; `/tasks` only offers self-assignment/unassignment. | Ownership transfer depends on the intended owner finding and claiming the work. |
| F-05 | P2 | The live Control Room shift is about 213 hours old with 1,720 active alerts. Preparing handover requires reviewing 1,602 Critical/High alerts and is restricted to a different shift lead. | Incoming handover is operationally unusable and could not be completed. |
| F-06 | P2 | Universal Tasks search omits client/site names and the nested Control Room operational task title. | Handover recipients cannot reliably find work using the information people naturally know. |
| F-07 | P2 | The original Control Room task remains open while the corrective action is completed/awaiting verification, and it is not represented as its own Universal Tasks item. | Duplicate responsibility cannot be reconciled from the shared queue. |
| F-08 | P2 | Incident/H&S surfaces do not carry the full operational evidence forward: immediate controls are blank, incident attachments/follow-ups are zero, and evidence is primarily visible only on Control Room. | Reviewers must revisit modules and cannot trust that the canonical incident is complete. |
| F-09 | P2 | A novice worker sees `Continue Control Room response` and other restricted affordances in `/tasks`, then reaches an unexplained 403. | Server authorization works, but the UI advertises work the user cannot perform and offers poor recovery. |
| F-10 | P2 | Core picker controls were unreliable: mouse client selection silently failed and the task assignee picker twice selected the wrong highlighted user; React also reported uncontrolled-to-controlled Select warnings. | Under pressure, the wrong client or owner can be recorded without a clear error. |

## 3. Tester/persona matrix

| Tester | Persona/account | Result | Main outcome |
|---|---|---:|---|
| 1 | Experienced Control Room Operator — `incident-e2e-operator@demo.test` | Partial | Created the alert, claimed/acknowledged/triaged it, added note/task/evidence, escalated it, created the incident/H&S handover, and preserved the linked references. Picker defects and fragmented context reduced confidence. |
| 2 | Incident Reviewer / Provider Manager — `incident-e2e-reviewer@demo.test` | Partial | Found the journey from `/tasks`, completed manager review, and proved incident closure was blocked by the incomplete investigation. Search and context were incomplete. |
| 3 | H&S Owner — `incident-e2e-owner@demo.test` | Partial | Found the event, accepted the handover, completed the investigation, dispositioned the recommendation, and created a corrective action. Could not mark WorkSafe notifiable or assign the action through a normal owner picker. |
| 4 | Corrective Action Owner / Site Manager — manager first, then approved fixture fallback `incident-e2e-reviewer@demo.test` | Partial | The intended manager could not find the action in scope. The fallback owner self-assigned, started, completed, received rework, amended, and resubmitted it. The return reason was not visible. |
| 5 | Independent H&S Verifier — `incident-e2e-verifier@demo.test` | **Fail** | Separation of duties was enforced and return-for-rework worked, but amended completion evidence remained invisible. Verification was deliberately not performed. |
| 6 | Incoming Control Room Operator / Closure Auditor — tagged user `manual-cr-incoming-20260716@demo.test` | **Fail** | Shift handover could not be prepared or accepted. The journey was findable from alerts and `/tasks`, but governance remained open and alert resolution offered no governance gate. |
| 7 | Novice Support Worker — `incident-e2e-worker@demo.test` | Partial | Could find a scoped alert/incident view in `/tasks`, but saw a restricted Control Room CTA that ended in 403; Escape closed the task detail but focus fell to the body. |

No persona achieved a full Pass. Admin was not used to hide a role or permission failure.

## 4. Golden journey stage-by-stage result

### Canonical record relay

| Record | Reference / ID | Final tested state | Direct link |
|---|---|---|---|
| Control Room alert | `CR-2026-2135` / `2202` | `triaging`, escalation level 2, one open operational task | `https://oblivionfindings.com/control-room/alerts/2202` |
| Incident | `INC-2026-0141` / `260` | `reviewed`, unassigned, not closed | `https://oblivionfindings.com/incidents/260` |
| H&S event | `HS-2026-0080` / `136` | `corrective_action`, handover accepted, not closed | `https://oblivionfindings.com/health-safety/events/136` |
| Investigation | `INV-2026-9007` / `7` | `completed`, one recommendation, dispositioned to corrective action | H&S event lifecycle |
| Corrective action | `CA-2026-9010` / `10` | database `completed`; UI governance state `Awaiting verification`; not verified/closed | `https://oblivionfindings.com/health-safety/corrective-actions/10` |
| Universal Tasks | journey query | four provider rows: CA, INC, HS, CR; nested CR task absent | `https://oblivionfindings.com/tasks?q=CR-2026-2135` |

### Acceptance criteria

| # | Required acceptance | Result | Evidence and interpretation |
|---:|---|---:|---|
| 1 | One Control Room alert exists | Pass | Read-only DB count: 1. |
| 2 | One official incident exists | Pass | Read-only DB count: 1. |
| 3 | One H&S event exists | Pass | Read-only DB count: 1. |
| 4 | CR, INC, and HS links agree | Pass | Incident links to alert 2202; H&S links to incident 260 and alert 2202. |
| 5 | Original operational notes, tasks, evidence, client, site, and timing survive | Partial | Site/client/references survive. One task and one completed evidence pack survive on Control Room, but incident immediate controls/attachments/follow-ups do not carry the evidence forward and the operational note is not a first-class operator-note row. |
| 6 | H&S explicitly accepts ownership | Pass | Accepted by Playwright H&S Owner at `2026-07-16 05:41:44 UTC`, with marked acceptance note and owner ID 115. |
| 7 | WorkSafe state is complete and consistent | **Fail** | Both incident and H&S remain `notifiable=false`, with no notification, reference, or acknowledgement. The UI had no flagging control, yet the H&S close checklist displayed the WorkSafe prerequisite as complete. |
| 8 | Investigation is complete | Pass | `INV-2026-9007` completed at `05:48:21 UTC`. |
| 9 | Every recommendation is dispositioned | Pass | One recommendation; disposition `corrective_action`; linked action ID 10. |
| 10 | Every corrective action is independently verified or closed | **Fail** | Action is `completed`, verifier/verified time are null, close fields are null. Verifier could not see the amended evidence. |
| 11 | Incident review and follow-ups are complete | Partial | Manager review is complete, but the incident has no follow-up records and closure remains blocked by open H&S governance. |
| 12 | Operational alert is resolved and closed | Not completed | Deliberately left `triaging`; the shared journey was not governance-complete. |
| 13 | H&S governance is closed independently | Not completed | Corrective action gate correctly blocks H&S closure. |
| 14 | Universal Tasks has no duplicate or incorrectly active responsibility | **Fail** | `/tasks` says CA `Completed`, while governance says awaiting verification; the original CR task remains open but is not its own queue item. |
| 15 | Completed/history views preserve references and audit trail | Partial | References and extensive audit logs are preserved, but the journey never reached completed/history closure. Activity labels include machine-oriented names such as `Hscorrectiveaction.update`. |
| 16 | Every role sees only authorised information and controls | **Fail** | A support worker sees a restricted `Continue Control Room response` CTA and then receives 403. No cross-site leakage was found. |
| 17 | No unexplained 403/404/419/500, blank modal, stale state, or console error | **Fail** | Reproducible worker 403, stale/overloaded shift state, misleading cross-module state, and Select warnings occurred. No 404/419/500 was observed. |

### Golden journey stopping point

The verifier returned `CA-2026-9010` once for rework with this reason:

> MANUAL-CR-HS-20260716-164157 verifier requests clearer effectiveness evidence

The owner amended and resubmitted the action through the UI. The database contains the amended `completion_notes` and retains the verifier's `verification_notes`, but the verification form still shows neither. `completion_evidence_paths` is null. Verifying at that point would have required blind trust, so the audit correctly stopped.

The H&S close-event gate then showed all items complete except:

- `Blocked: All corrective actions verified or closed`

The close button remained disabled. This is the correct final blocker for the action state, but the WorkSafe line was incorrectly reported complete because the event had never been flagged notifiable.

## 5. Alternate workflow matrix

The brief required alternate records only **after** the golden journey. The golden journey never reached safe closure. Creating further tagged live records would have increased noise in an already stale Control Room shift and would have violated the ordered acceptance strategy. These branches are therefore reported honestly as not tested rather than inferred from code or automated tests.

| Branch | Result | What was exercised | Gap |
|---|---:|---|---|
| A. Routine alert requiring no incident | Not tested | None after the blocked golden journey | No claim made about pressure to create an unnecessary incident. |
| B. False-positive sensor/detection | Not tested | None | Confirm/dismiss paths and queue/SLA removal were not manually exercised. |
| C. Resolved alert later found to need incident | Not tested | None | Reopen-for-incident history/evidence preservation was not manually exercised. |
| D. Snooze and escalation | Partial within golden journey | Escalation from level 1 to level 2 was performed and persisted. | Snooze/unsnooze and the full escalation queue were not tested. Severity/priority/escalation/SLA wording remained confusing. |
| E. Task transfer to H&S | Partial within golden journey | Operational task and recommendation-created corrective action coexist. | There is no truthful visible one-for-one replacement in `/tasks`; the original task remains open and hidden as a nested task. |
| F. Closure gates and recovery | Partial | Incident closure blocked on investigation; H&S closure blocked on unverified action; Control Room resolve preflight warned that an open task would remain. Entered incident closure text was preserved. | The complete prerequisite-by-prerequisite matrix was not run. Control Room resolution did not present a governance gate and appeared willing to resolve while leaving the task open. |

## 6. Consolidated defect register

| ID | Priority | Category | Reproduction | Expected | Actual | Reproducible / probable ownership |
|---|---:|---|---|---|---|---|
| D-01 | P1 | Functional / safety | As verifier, open `CA-2026-9010` after owner resubmits amended notes; open Verify action. | See completion notes, uploaded evidence, prior return reason, and enough audit context to independently judge effectiveness. | Verification pane shows separation-of-duties text, checkbox, and a blank verification-notes field only. | Yes. `HsEventController.php:555-580` omits `completion_notes`, `completion_evidence_paths`, and `verification_notes`; `event-detail-dialog.tsx:2220-2300` has no evidence display. |
| D-02 | P1 | Regulatory / data | H&S owner accepts a scenario explicitly requiring WorkSafe notification, then tries to mark it notifiable. | Visible decision control followed by notify and acknowledge workflow; closure blocked until complete. | No visible flagging control. Event remains false/unflagged. Closure gate considers WorkSafe complete. | Yes. WorkSafe UI is conditional on an already-true flag at `event-detail-dialog.tsx:678-730,2694`; `HsEventService.php:259-284` treats false as complete. |
| D-03 | P1 | Status integrity | Complete the corrective action and view it in H&S and `/tasks`. | Shared label such as `Awaiting independent verification`; not counted as completed work. | H&S says awaiting verification; `/tasks` says `Completed` and places it in the done bucket. | Yes. Provider/task status mapping needs a governance-aware presentation state. |
| D-04 | P1 | Ownership | Create action from recommendation as H&S owner and try to assign the intended manager. | Required owner picker with eligible site-scoped staff; creation should not end ownerless. | Action is created unassigned. H&S event detail offers no general owner selection; `/tasks` offers only `Assign to me` or `Unassign`. | Yes. Backend provider can assign (`HsCorrectiveActionProvider.php:34-85`), but task UI actions at `tasks/index.tsx:463-476` expose only self-assignment. |
| D-05 | P2 | Handover / fixture operability | Open active Control Room shift and Prepare Handover as a non-lead incoming/outgoing operator. | A current shift with a bounded priority list and explicit outgoing/incoming acceptance relay. | Shift `R10 Optional Notes Check` is ~213 hours old; 1,720 active alerts; 1,602 Critical/High require review; only Demo Coordinator can prepare; incoming user sees `No handover for this shift`. | Yes on current dev data. Requires fixture/state hygiene and handover scalability review. |
| D-06 | P2 | Search / findability | In `/tasks`, search client name, exact nested task title, and journey refs. | All known identifiers and natural-language responsibility text find the journey. | Client name returns zero; exact CR task title returns zero; exact journey refs return provider records. | Yes. `TaskAggregator.php:308-318` search haystack omits client/site and nested task content. |
| D-07 | P2 | Duplication / orchestration | Create CR task, later create/complete H&S corrective action for the same responsibility. | One responsibility replaces or explicitly links/retire the other; no duplicate active work. | CR task ID 16 remains open; CA is completed/awaiting verification; Universal Tasks represents the alert, not the nested operational task. | Yes. ControlRoomAlertProvider projects the alert record rather than `AlertTask`. |
| D-08 | P2 | Data continuity | Reviewer opens incident/H&S after Control Room recorded note/evidence. | Immediate controls and evidence are visible in the canonical incident/H&S decision surface. | Incident immediate actions are blank, attachments 0, follow-ups 0; Control Room has one task and completed evidence pack. | Yes. Presenter/controller mapping preserves some links but not the actionable evidence fields. |
| D-09 | P2 | Permission UX / accessibility | Support worker searches `CR-2026-2135`, opens task detail, chooses `Continue Control Room response`. | Restricted CTA absent, or explanatory read-only route with recovery. | CTA is visible and active; result is a bare `403 Forbidden`. | Yes. Server authorization blocks correctly; task-detail action visibility does not mirror it. |
| D-10 | P2 | Closure semantics | Incoming operator opens Resolve on alert with one open task and open governance. | Explicit gate distinguishes operational resolution from governance closure and links to blockers. | Preflight says the open task will remain after resolving and still enables `Resolve alert`; no incident/H&S completion check is shown. | Yes. Resolution was cancelled to avoid mutating the golden record. |
| D-11 | P2 | Form reliability | Mouse-select fixture client; select task assignee while an option is highlighted. | Click/selection commits exactly the highlighted option. | Client click silently failed; keyboard workaround was required. Assignee twice committed Playwright H&S Owner instead of the highlighted operator. | Reproduced during Tester 1. Shared Select control is a likely surface. |
| D-12 | P2 | Recovery | Verifier returns action for rework; owner reopens it. | Owner sees return reason prominently beside the next action. | Reason is stored in DB but absent from action detail, timeline, and task activity. | Yes. Service writes `verification_notes` (`HsCorrectiveActionService.php:235-245`); event payload omits it. |
| D-13 | P2 | Date integrity | H&S owner enters investigation target 24 July 2026. | Same local date displays after save. | UI displays 30 July at noon. | Reproduced once; requires date-input/payload/timezone tracing. |
| D-14 | P2 | Role scope / findability | Intended manager starts from `/tasks` and searches `CA-2026-9010`. | Assigned or eligible action visible to intended owner. | Zero result; fixture fallback reviewer was required and then self-assigned. | Reproducible for that account/site scope. Do not classify as leakage; it is an ownership/site-scope mismatch. |
| D-15 | P2 | H&S findability | H&S owner starts from dashboard. | Awaiting acceptance is surfaced as priority work. | Dashboard did not surface the relay; Events register did. | Reproduced. H&S dashboard attention model needs handover acceptance state. |
| D-16 | P2 | Accessibility | Worker opens task detail and presses Escape. | Modal closes and focus returns to the invoking row. | Modal closes, then focus lands on `BODY`. | Reproduced. Keyboard continuation is lost. |
| D-17 | P2 | Component state | Exercise client/assignee and H&S selects. | Stable controlled selection with no React warnings. | Multiple uncontrolled-to-controlled Select warnings; shared `SelectInput` uses `value={value || undefined}` at `wizard/primitives.tsx:182`. | Reproducible in several flows. |
| D-18 | P3 | Audit readability | Read task/H&S activity as a normal user. | Human labels such as `Corrective action returned for rework`. | Machine labels such as `Hscorrectiveaction.update`, `ControlRoom.alert.view`. | Reproducible. Audit remains present but is hard to interpret. |
| D-19 | P3 | Navigation consistency | Use browser Back and expanded user/sidebar menus. | Predictable return and working menu controls. | Back was surprising in operator flow; manager user menu failed while sidebar was expanded and recovered only after collapsing. | Reproduced in individual passes. |

No P0 tenant leak, corruption, or data loss was identified. The highest confirmed risks are P1 workflow/safety/regulatory failures.

## 7. Findings by category

### Functional

- Independent verification cannot be performed safely because the verifier cannot inspect the owner's submitted evidence.
- WorkSafe flagging cannot be initiated from the H&S event detail after acceptance/review.
- Corrective-action assignment is not a complete handover workflow.
- Shift handover is blocked by stale data volume and outgoing-lead ownership.
- Incident/H&S/Control Room final closure could not be completed because verification remained open.
- Alert resolution presents no cross-module governance gate and appears able to leave operational tasks open.

### Permission and security

- Server-side authorization correctly denied the support worker's Control Room continuation with 403.
- The UI incorrectly advertised the restricted action instead of hiding it or presenting a read-only explanation.
- The support worker could see the site-scoped CR and incident journey through `/tasks`, but not H&S or corrective-action provider rows. No cross-site or unrelated sensitive data exposure was found in the tested query.
- Provider Manager's broad Control Room controls align with its current role-permission inheritance; this is a role-design concern, not a proven authorization bypass.
- The intended manager's inability to see the corrective action is a scope/ownership defect, not evidence of tenant leakage.

### Data integrity and duplication

- Exact DB counts are one alert, one incident, one H&S event, one investigation, and one corrective action.
- Alert, incident, and H&S all resolve to site/client 9401.
- H&S acceptance actor, owner, time, and note are preserved.
- Corrective action completer is Playwright Incident Reviewer; verifier remains null, preserving separation of duties.
- Closure actors/timestamps remain null across alert, incident, H&S, and action, matching the deliberately blocked journey.
- The original CR task remains open while a corrective action exists for overlapping work; `/tasks` does not expose the operational task as a separate reconciliable item.
- `completion_notes` and the return reason are stored, but the verification presentation drops them.
- The WorkSafe false flag makes the stored data internally consistent but semantically wrong for the scenario.

### Visual consistency

- The overall shell, hero tiles, tables, and dialogs generally use the same design system.
- Status language diverges materially between modules: `Completed` in Universal Tasks versus `Awaiting verification` in H&S.
- H&S activity uses raw machine event names, unlike the more guided Control Room workspace.
- Empty or irrelevant sidebar groups remain visible to restricted roles, which makes the shell feel permission-agnostic rather than role-specific.
- The worker's 403 page is visually and contextually disconnected from the task dialog that sent them there.

### Usability

- Search works best only when users already know a formal CR/INC/HS reference.
- People cannot reliably use client name or the actual task wording to find responsibility.
- WorkSafe, corrective-action ownership, and independent verification lack one clear next-action path.
- Rework is technically recoverable but the owner cannot see why it was returned.
- The incoming operator cannot consume a bounded handover and would need to speak to the outgoing operator.
- Picker controls and focus return are not reliable enough for pressured or keyboard-only use.
- Terms such as severity, priority, escalation, SLA breach, corrective action, disposition, and verification are not consistently explained in context.

## 8. Independent tester user voice

### Tester 1 — Control Room Operator

- **What I thought was happening:** “I was creating the operational source record and expected the system to carry that truth into the incident and H&S journey.”
- **What I believed I owned:** “Immediate triage, evidence, the operational task, escalation, and a clean handoff.”
- **What I expected next:** “The reviewer should receive one obvious incident responsibility with my note, task, and evidence already visible.”
- **Where I hesitated:** “The client picker looked selected but did not commit, and the assignee picker chose someone other than the highlighted operator.”
- **What felt disconnected:** “The alert, incident, H&S record, and `/tasks` each showed a different slice of the same situation.”
- **Wording I did not understand:** “The difference between alert status, incident status, H&S status, escalation, and SLA was not explained as one lifecycle.”
- **Would I trust the handover:** “Only partly; the references survived, but the reviewer cannot see all the operational evidence in one place.”
- **Could I recover without help:** “I recovered with keyboard and coordinate workarounds, but those are not reasonable production expectations.”
- **Single change:** “Make the alert's one next action create a prefilled canonical handover summary and show exactly what will transfer.”
- **Scores:** ease 2/5; confidence/safety 2/5; handover confidence 2/5.

### Tester 2 — Incident Reviewer / Provider Manager

- **What I thought was happening:** “I was reviewing the formal incident created from Control Room and checking whether it was safe to close.”
- **What I believed I owned:** “Factual incident review and manager follow-up, not the H&S investigation or corrective action.”
- **What I expected next:** “A clear H&S-owned next step and a closure gate that explained the missing prerequisite.”
- **Where I hesitated:** “`/tasks` returned broad, noisy results, and client/title searches did not find the journey.”
- **What felt disconnected:** “The incident had no immediate controls, attachments, or follow-ups even though Control Room had task/evidence activity.”
- **Wording I did not understand:** “The queue did not explain whether a row was a record to review, a task to perform, or a journey reference.”
- **Would I trust the handover:** “Partly. The closure gate was useful, but the evidence context was incomplete.”
- **Could I recover without help:** “Yes for the investigation blocker, because the message was explicit and preserved my text.”
- **Single change:** “Show a role-labelled responsibility card: ‘You own incident review; H&S owns investigation and closure.’”
- **Scores:** ease 3/5; confidence/safety 3/5; handover confidence 3/5.

### Tester 3 — H&S Owner

- **What I thought was happening:** “I was accepting governance ownership and expected to complete the regulatory, investigation, and corrective-action chain.”
- **What I believed I owned:** “H&S acceptance, WorkSafe decision/notification, investigation, recommendation disposition, and assigning the action owner.”
- **What I expected next:** “After acceptance, the page should step me through WorkSafe, investigation, recommendation, action owner, and verification.”
- **Where I hesitated:** “The dashboard did not surface the handover, the WorkSafe flagging action was absent, and the new action had no owner picker.”
- **What felt disconnected:** “I had to infer that evidence on Control Room was enough while the canonical immediate-controls field stayed blank.”
- **Wording I did not understand:** “Investigation terminology was manageable, but the system did not distinguish regulatory decision from notification status.”
- **Would I trust the handover:** “No for regulatory closure; it can say WorkSafe is complete when no decision or notification was recorded.”
- **Could I recover without help:** “I could continue the investigation, but could not recover the missing WorkSafe or assignment steps through visible UI.”
- **Single change:** “Provide a persistent governance checklist with required actor, state, evidence, and one CTA per step.”
- **Scores:** ease 2/5; confidence/safety 2/5; handover confidence 2/5.

### Tester 4 — Corrective Action Owner / Site Manager

- **What I thought was happening:** “I had been asked to complete one concrete action and return it for independent verification.”
- **What I believed I owned:** “Doing and evidencing the action, not approving my own work.”
- **What I expected next:** “The action should appear in My Tasks, explain the source journey, and clearly change to Awaiting verification after completion.”
- **Where I hesitated:** “The intended manager found no action. The fallback user had to self-assign, and ‘Start corrective action’ first deep-linked rather than starting it.”
- **What felt disconnected:** “`/tasks` called it Completed while H&S still required verification; the rework reason was nowhere visible.”
- **Wording I did not understand:** “`Completed` implied the responsibility was over, which was false.”
- **Would I trust the handover:** “I trusted that my notes saved, but not that the verifier would see them.”
- **Could I recover without help:** “I resubmitted only because the test lead supplied the exact return reason; the UI did not.”
- **Single change:** “Show the return reason and a before/after evidence checklist at the top of the action.”
- **Scores:** find/ease 2/5; completion confidence 4/5; verification-handover confidence 5/5 before discovering the verifier presentation gap.

### Tester 5 — Independent H&S Verifier

- **What I thought was happening:** “I was independently checking whether the completed action was effective and safe to verify.”
- **What I believed I owned:** “Evidence review, one rework loop, independent verification, then H&S closure.”
- **What I expected next:** “The owner's completion note and evidence should be beside the verification decision.”
- **Where I hesitated:** “The form asked me to confirm effectiveness without showing the evidence I was meant to assess.”
- **What felt disconnected:** “The return reason was stored but absent from owner and verifier views; the activity trail was machine-labelled.”
- **Wording I did not understand:** “`Completed` in `/tasks` conflicted with the actual awaiting-verification duty.”
- **Would I trust the handover:** “No. Verifying would be a blind attestation.”
- **Could I recover without help:** “I could return it once, but the corrected submission still did not become reviewable.”
- **Single change:** “Make verification a read-only evidence review page first, then enable Verify or Return for rework.”
- **Scores:** ease 3/5; confidence/safety 2/5; handover confidence 2/5.

### Tester 6 — Incoming Control Room Operator / Closure Auditor

- **What I thought was happening:** “I was taking over an active shift and expected a prepared, explicitly accepted handover with one closing action.”
- **What I believed I owned:** “Understanding the operational situation, accepting the handover, and resolving/closing only after governance was complete.”
- **What I expected next:** “A bounded list of priority alerts and a handover summary for `CR-2026-2135`.”
- **Where I hesitated:** “The active shift was nine days old, had 1,720 alerts, required 1,602 reviews, and said only Demo Coordinator could prepare it.”
- **What felt disconnected:** “My landing view said no handover existed, while Desk and `/tasks` still exposed the journey.”
- **Wording I did not understand:** “Resolve alert warned that the task would remain but did not explain operational versus governance closure.”
- **Would I trust the handover:** “No; there was no handover to accept and the queue volume made the process non-credible.”
- **Could I recover without help:** “I could search the formal reference, but could not create or accept the required shift handover.”
- **Single change:** “Generate a shift handover from only changed/owned/escalated priority work, with explicit outgoing submit and incoming accept.”
- **Scores:** ease 2/5; confidence/safety 1/5; handover confidence 1/5.

### Tester 7 — Novice Support Worker

- **What I thought was happening:** “I was looking for the incident I might need to know about and whether I still had work.”
- **What I believed I owned:** “Only normal support-worker follow-up, not Control Room or H&S governance.”
- **What I expected next:** “A read-only explanation of what happened, who owns it, and whether I had any task.”
- **Where I hesitated:** “I saw Control Room and H&S menu headings, then a `Continue Control Room response` button in the task detail.”
- **What felt disconnected:** “The button sent me to a bare 403, which felt like leaving the application.”
- **Wording I did not understand:** “Triaging, Reviewed, Corrective action, journey references, escalation, and SLA did not tell me what I should do.”
- **Would I trust the handover:** “Only partly; I could see the references but not a plain-language next action.”
- **Could I recover without help:** “Browser Back returned me to My Day, not the task context; Escape also dropped keyboard focus to the page body.”
- **Single change:** “Replace restricted actions with a plain ‘No action for you — H&S Owner is handling this’ summary.”
- **Scores:** ease 3/5; confidence/safety 2/5; handover confidence 2/5.

## 9. Interaction metrics

Click instrumentation was not globally available. Where a tester did not supply an exact counter, the table reports a conservative range of significant control activations reconstructed from the manual audit transcript; it is not presented as full analytics telemetry.

| Tester | Pages/surfaces visited | Significant activations | Backtracks | Dead ends | Main hesitation points | Scores E/C/H |
|---|---|---:|---:|---:|---|---|
| 1 | Desk, alert register/workspace, incident creation, `/tasks` | at least 20 | 2 | 2 | client commit, assignee commit, canonical ownership | 2/2/2 |
| 2 | `/tasks`, incident detail, closure dialog | at least 10 | 1 | 2 | noisy results, absent client/title search, missing evidence | 3/3/3 |
| 3 | H&S dashboard, events register/detail, investigation, action handoff | at least 20 | 2 | 2 | WorkSafe action, assignee, date change, dashboard findability | 2/2/2 |
| 4 | manager `/tasks`, fallback `/tasks`, action detail/start/complete/rework/resubmit | at least 15 | 2 | 3 | scope zero-result, self-assignment, hidden return reason | 2/4/5* |
| 5 | `/tasks`, corrective action, return for rework, verification, H&S closure | **16 exact** | 0 | 1 | absent evidence in verification form | 3/2/2 |
| 6 | active shift, handover page, Desk, alert register/detail, `/tasks`, resolve preflight | about 12 | 1 | 3 | stale shift, outgoing lead, no incoming handover, closure semantics | 2/1/1 |
| 7 | My Day, sidebar menus, `/tasks`, task detail, 403, keyboard modal flow | about 9 | 1 | 2 | restricted CTA, 403 recovery, focus loss | 3/2/2 |

`E/C/H` means ease, confidence/safety, and handover confidence. Tester 4's original categories were find/ease, completion confidence, and verification-handover confidence.

## 10. Evidence paths and references

All screenshots are under:

`output/manual-audits/control-room-multi-role-2026-07-16/`

### Tester 1

- `tester1-before-handover.png`
- `tester1-after-handover.png`
- `tester1-client-selection-defect.png`
- `tester1-client-selection-stuck.png`
- `tester1-task-assignee-selector-defect.png`
- `tester1-final-tasks-handoff.png`
- `tester1-blocked-final-state.png`

### Tester 2

- `tester2-before-review.png`
- `tester2-after-review.png`
- `tester2-defect-client-search-zero.png`
- `tester2-defect-task-title-search-zero.png`
- `tester2-closure-gate-blocked.png`

### Tester 3

- `tester3-before-acceptance.png`
- `tester3-after-acceptance.png`
- `tester3-corrective-action-handoff.png`
- `tester3-defect-worksafe-flag-missing.png`
- `tester3-defect-action-assignment-missing.png`

### Tester 4

- `tester4-manager-scope-defect.png`
- `tester4-before-action.png`
- `tester4-after-completion.png`
- `tester4-rework-owner-view.png`
- `tester4-after-resubmit.png`

### Tester 5

- `tester5-before-rework.png`
- `tester5-after-rework.png`
- `tester5-after-verification.png` — filename retained from the pass, but the image shows the **blocked verification form**, not successful verification.
- `tester5-hs-closure.png`

### Tester 6

- `tester6-shift-handover-blocked.png`
- `tester6-incoming-journey.png`
- `tester6-incoming-tasks.png`
- `tester6-closure-preflight.png`

### Tester 7

- `tester7-worker-view.png`
- `tester7-permission-boundary.png`

### Read-only integrity evidence

- Exact counts: CR 1, incident 1, H&S event 1, investigation 1, corrective action 1.
- H&S acceptance: owner/accepted by Playwright H&S Owner at `2026-07-16 05:41:44 UTC`.
- Site/client: all canonical records resolve to 9401.
- Alert: triaging, escalation level 2, one open task ID 16, one evidence pack.
- Incident: reviewed at `05:27:20 UTC`, not closed, attachments 0, follow-ups 0, immediate action fields null.
- Investigation: completed at `05:48:21 UTC`, one recommendation, dispositioned to action ID 10.
- Corrective action: assigned/completed by Playwright Incident Reviewer, amended at `06:32:37 UTC`; verifier/verified/closed fields null; evidence paths null; return reason preserved.
- Audit logs: 44 alert entries, H&S create + 3 updates, investigation create + 4 updates + disposition, action create + 5 updates.
- Live log creation entries: H&S event line 725, investigation line 810, corrective action line 820 in `laravel-2026-07-16.log`.

## 11. Remediation backlog

### P0

No confirmed P0 issue in this audit.

### P1 — required before release

1. Make corrective-action verification evidence-complete: include completion notes, evidence attachments/paths, prior return reasons, completer, timestamps, linked recommendation, and a readable audit history in the verification pane.
2. Add an explicit, auditable WorkSafe decision step to H&S event governance. Do not infer “not required” from a default false value. Require actor, time, rationale, and, when notifiable, notification/acknowledgement details.
3. Make WorkSafe closure truth depend on an explicit decision state, not `!worksafe_notifiable`.
4. Map corrective-action `completed` to `Awaiting verification` in Universal Tasks and keep it out of the done bucket until verified/closed.
5. Provide a required site-scoped action-owner picker when creating or handing over a corrective action; show notification and acceptance state.
6. Reconcile transferred work: close/cancel/link the original Control Room task or expose both with an explicit duplicate/transfer relationship and one owner.

### P2 — major usability and recovery

1. Redesign Control Room shift handover around changed/owned/escalated priority items, not every active Critical/High alert in a stale shift.
2. Surface Awaiting H&S acceptance on the H&S dashboard.
3. Expand `/tasks` search to client, site, journey title, nested source task title/description, and human owner names.
4. Carry Control Room immediate controls and evidence into incident/H&S review summaries without retyping.
5. Hide or disable task-detail CTAs using the same permission checks as the destination route; replace 403 dead ends with role-specific read-only guidance.
6. Add cross-module closure language: Operationally resolved, Incident review complete, Governance awaiting verification, Governance closed.
7. Make rework reason prominent for the owner and carry it into activity using human wording.
8. Repair shared Select controlled state and selection commit; add mouse and keyboard regression coverage for client/assignee pickers.
9. Fix investigation target-date round-tripping and local-date display.
10. Restore focus to the invoking task row after Escape/Close and verify visible focus order.

### P3 — polish

1. Translate machine audit actions into plain activity labels.
2. Remove empty/restricted sidebar groups for roles with no useful child links.
3. Make Back/Close behaviour return to the immediately prior filtered worklist.
4. Clarify severity, priority, escalation level, SLA status, and governance stage with short inline definitions.

## 12. Recommended workflow, copy, and UI improvements

### A single journey header

Every linked record should show one shared header:

`CR-2026-2135 → INC-2026-0141 → HS-2026-0080 → INV-2026-9007 → CA-2026-9010`

Under it, show role-labelled stages and one truthful next action:

- Operations — Triaging — Playwright Control Room Operator — one open task
- Incident review — Reviewed — no current reviewer task
- H&S acceptance — Accepted by Playwright H&S Owner at 5:41 pm
- WorkSafe decision — **Not recorded**
- Investigation — Complete
- Corrective action — **Awaiting independent verification**
- Final closure — Blocked by corrective-action verification

### Recommended status copy

- Replace task `Completed` with `Awaiting independent verification` until verified.
- Replace a false WorkSafe “Complete” gate with `Decision not recorded`, `Not notifiable — decision recorded by …`, `Notification pending`, `Notified — acknowledgement pending`, or `Acknowledged`.
- Replace `Continue Control Room response` for workers without permission with `No action for you` plus the current owner and contact route.
- Replace machine activity names with human events: `Action returned for rework`, `Owner resubmitted evidence`, `H&S handover accepted`.

### Verification design

Use a two-column or sequential evidence-review layout:

1. **What was required** — recommendation, root-cause link, due date, original operational task.
2. **What the owner submitted** — completion notes, files, photos, timestamps, completer.
3. **Prior rework** — verifier reason and owner response.
4. **Verifier decision** — effective/not effective, notes, Verify or Return for rework.

The Verify button should remain disabled until the evidence section has loaded successfully and the verifier has acknowledged reviewing it.

### Handover design

Create a bounded handover pack from:

- alerts created/changed during the outgoing shift;
- owned or watched alerts;
- escalated or SLA-breached alerts;
- unresolved tasks due before the next shift;
- journeys awaiting incident/H&S/verification decisions.

Require outgoing submit and incoming accept, record both actors/times, and show exceptions instead of requiring review of the entire global active queue.

## 13. What was not tested and why

- Final independent verification and action closure — blocked because evidence was not visible to the verifier.
- H&S closure — blocked by the unverified corrective action.
- Incident closure after governance — blocked because H&S remained open.
- Control Room resolve/close — resolve was taken to the final preflight and cancelled; completing it would have broken the ordered golden journey while governance remained open.
- Completed/history views after full closure — no safe completed state existed.
- Alternate records A-E and complete branch F — the brief ordered them after golden closure, which never occurred.
- Full snooze/unsnooze and false-positive confirm/dismiss — not executed after the blocker.
- All possible site/role combinations — seven required personas were tested; this is not an exhaustive permission matrix.
- Mobile/WebView — explicitly outside the desktop Chrome scope.
- Automated Playwright/API substitution — intentionally not used for acceptance actions.
- Server repairs or fixture cleanup — explicitly outside audit scope.

## 14. Ten-second novice-worker comprehension result

**Fail.**

Within ten seconds, the worker could see an incident and alert row with formal references, but could not reliably answer all six required questions:

- what happened — partially, from a truncated description;
- whether the incident was submitted/reviewed — yes, `Reviewed` was visible;
- who owns it now — the alert owner was visible, but the H&S owner was not surfaced as the current governance owner;
- whether H&S accepted it — only through journey references/detail, not as plain worker guidance;
- whether the worker still has work — `Assigned to me 0` suggested no work, but a restricted action was still advertised;
- what happens next — not clear.

The obvious Control Room CTA led to 403, and the page did not say who to contact. A minimally trained worker would likely ask another person for help.

## 15. Does the complete experience feel like one application?

**No.**

The visual shell is shared, but the workflow behaves like connected modules rather than one coherent application:

- each module uses different status language for the same responsibility;
- evidence and return reasons disappear between modules;
- search capabilities change depending on which identifier is used;
- ownership is not consistently explicit;
- the worker's permission denial abandons the task context;
- operational resolution, incident review, and H&S governance do not share one closure model.

The strongest unifying element is the journey-reference strip. It needs to become the canonical state and responsibility summary rather than only a set of links.

## 16. Final recommendation

**Unsafe to release** for the tested end-to-end safety journey.

The release gate should require, at minimum:

1. verifier-visible completion/rework evidence;
2. explicit WorkSafe decision and truthful closure gate;
3. truthful Universal Tasks verification status;
4. complete corrective-action assignment/ownership;
5. no restricted worker CTA that ends in 403;
6. a practical Control Room shift-handover path;
7. a successful repeat of this exact seven-persona golden journey through final CR, incident, H&S, action, and Universal Tasks closure.

Until those conditions are met, the system can produce false completion, blind verification, and an incomplete regulatory record even though individual module transitions appear to work.
