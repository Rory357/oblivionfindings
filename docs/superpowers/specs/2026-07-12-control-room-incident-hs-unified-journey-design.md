# Control Room, Incidents, and H&S Unified Journey Design

**Date:** 2026-07-12

**Status:** Approved for implementation by the user's instruction to plan fully and implement the recommended audit direction

**Surface:** Desktop only. Mobile layouts and mobile verification are explicitly out of scope.

## 1. Outcome

Control Room, Incidents, and Health & Safety must feel like one application and one understandable operational journey. The implementation keeps the three existing accountable records because they serve different purposes, but removes duplicate entry, ambiguous ownership, conflicting counts, and disconnected handovers.

The shared journey is:

> Receive → Acknowledge → Triage and control → Record and hand over → Resolve → Close

The three parallel records remain:

- **Control Room alert:** immediate risk, SLA, playbook, communications, evidence, and operational tasks.
- **Client incident:** the official factual report, reporter, manager review, attachments, and incident follow-ups.
- **H&S event:** handover acceptance, WorkSafe, investigation, recommendations, corrective actions, monitoring, and governance closure.

The application presents these as one joined journey with three clearly labelled lifecycle states:

- Operational response
- Incident review
- H&S governance

## 2. Approaches considered

### A. Cosmetic Control Room refresh only

This would restyle the dashboard with the H&S hero and cards but leave duplicate records, false handover instructions, conflicting WorkSafe counts, and lifecycle defects. It does not satisfy feature completeness.

### B. One joined journey over the existing records — selected

This establishes one idempotent orchestration service, explicit links, a shared handover presenter, honest lifecycle gates, and a common desktop visual language. It preserves domain accountability without making staff understand database boundaries.

### C. Replace all three records with a new single incident model

This would remove conceptual duplication but would be a high-risk rewrite of operational, legal, reporting, and integration behaviour. It would also erase useful separation between immediate response and governance. It is not selected.

## 3. People and permissions

### Control Room operator or coordinator

The operator needs the next operational action immediately, can acknowledge and triage alerts, can create an official incident from an existing alert without duplicating it, prepares shift handover, and can see whether H&S accepted the safety handover.

### Support worker

The support worker reports incidents through the canonical incident wizard and completes assigned actions in the existing My Day surface. They do not receive the dense Control Room command-centre dashboard or unrestricted incident/safeguarding registers.

### Incident reviewer or manager

The reviewer validates the official incident facts, manages incident follow-ups, and closes the incident review independently from the operational and governance records.

### H&S officer

The H&S officer can open the originating incident when authorised, accept ownership of the H&S handover, manage WorkSafe, investigation, recommendation disposition, corrective actions, monitoring, and governance closure.

### Visibility contract

- Existing `UserSiteAccessService` scoping remains the base for Control Room alert and dashboard queries.
- Every nested alert mutation authorises access to its parent alert before reading or changing tasks, evidence, discussions, watchers, communications, and time entries.
- Pickers return only visible sites, clients, users, and queues.
- The Control Room safety-handover surface does not aggregate raw safeguarding or medication records into a second register. It lists canonical `ClientIncident` journeys and opens the canonical domain surface.
- Safeguarding details are never returned without the safeguarding domain's visibility decision.
- Role UI is driven by server-provided capabilities; unavailable actions are not advertised.

## 4. Canonical record and linking contract

`ClientIncident` is the journey anchor for incident workflows. It may have no Control Room alert for low/medium reports, but every submitted incident must have exactly one linked H&S event.

### Required links

- `client_incidents.control_room_alert_id` is the direct incident-to-alert link when an alert exists.
- `client_incidents.hs_event_id` becomes the direct incident-to-H&S link.
- `hs_events.source_type/source_id` continues to point to the source incident and its unique idempotency key remains enforced.
- `hs_events.control_room_alert_id` mirrors the journey's alert when one exists.
- Alert presentation resolves the incident through the direct incident link, not only ad-hoc JSON context.
- Existing `context.incident_id` is maintained as compatibility metadata but is not authoritative.

### Idempotency invariants

- Repeating “Create incident and hand over” on the same alert returns the existing incident and H&S event.
- Repeating “Create operational alert” on the same incident returns the existing alert.
- A distinct incident created within another incident's 30-minute deduplication window is never dropped or left unlinked.
- Incident-backed alert deduplication keys on the incident identity, not merely client/type/time.
- Sensor and medication integrations call the same journey service and produce one correlation.
- All three direct links agree after every successful orchestration call.

### Failure behaviour

Journey creation is transactional. A request never reports success with only part of the relationship committed. Observer-based compatibility paths enqueue reconciliation on failure instead of swallowing the exception and leaving an orphan silently.

## 5. Reporting and entry paths

There is one canonical incident report wizard reused from Incidents, H&S, Control Room, My Day, client profile, and shift deep links.

The final step has two explicit actions:

- **Save draft:** saves `status=draft`; creates neither an H&S event nor an automatic Control Room alert.
- **Submit incident:** saves `status=submitted` and `submitted_at`; creates the H&S event synchronously and creates a Control Room alert only when severity/rules require one.

The success copy states the actual result. A saved draft says “Draft saved.” A submitted record says “Incident submitted” and shows the official incident reference plus handover state.

### Existing Control Room alert

“Create incident and hand over” opens the canonical wizard prefilled from the alert. Submission reuses the originating alert, creates one incident, creates one H&S event, preserves evidence and official references, and changes the alert workspace to show the joined journey.

### Existing incident

Low/medium incidents can remain without a Control Room alert. An authorised reviewer can deliberately create an operational alert; high/critical rules can create it automatically. Both paths are idempotent.

### Sensor confirmation

Confirming a sensor event creates one submitted incident, links the existing sensor alert, carries the sensor evidence into the shared handover summary, and creates one H&S event. Dismissal creates no incident and ends SLA handling as a false positive.

### Medication incident

Medication integrations create or locate the official incident first, then use the journey service to create at most one alert and one H&S event. A separate signal may enrich the same journey but must not create a disconnected second alert.

## 6. Incident-time context and handover summary

`ClientIncident.site_id` stores the incident-time site snapshot. The selected shift, client, and site must belong to the same accessible context. H&S and dashboard site filtering use this snapshot rather than the client's current site.

Every alert, incident, and H&S workspace exposes the same `IncidentJourneyPresenter` contract:

- official alert, incident, and H&S references;
- person/client, incident-time site, shift, occurred time, reporter, and source;
- factual narrative, witnesses, immediate controls, and potential consequence;
- incident attachments and Control Room evidence;
- playbook outcome and important Control Room communications;
- open operational tasks and any transferred corrective action;
- current operational owner, incident reviewer, H&S owner, and H&S acceptance;
- WorkSafe state from the H&S event;
- the three lifecycle states;
- one recommended next action that the current user can perform.

Raw database IDs such as `CR-275` or `Incident #139` are replaced by generated official references.

## 7. H&S acceptance and WorkSafe

Submitted incidents land in H&S as `awaiting_acceptance`. An authorised H&S user explicitly accepts the handover, becoming owner and recording `accepted_by`, `accepted_at`, and optional acceptance notes. The Control Room and incident workspaces show this acceptance immediately.

`HsEvent` is the authoritative WorkSafe state for this journey:

- `worksafe_notifiable`
- `worksafe_status`
- `worksafe_notified_at`
- `worksafe_method`
- `worksafe_reference`
- `worksafe_acknowledged_at`
- `worksafe_site_preserved`

Draft incident fields are provisional inputs. On submission they initialise the H&S event. Thereafter the incident and H&S interfaces read the H&S state. The H&S dashboard/worklist and incident register therefore report the same pending count. Legacy `ClientIncident` and `NotifiableIncident` fields are compatibility projections, not competing workflow sources.

## 8. Lifecycle and closure rules

### Operational alert

Active states are `open`, `ack`, `triaging`, and `confirmed`. Terminal worklist states are `resolved`, `closed`, and `dismissed`.

Human workflow transitions are:

- `open → ack`
- `ack → triaging`
- `triaging|confirmed → resolved`
- `resolved → closed`
- sensor `open|ack|triaging → confirmed|dismissed`

An automated-resolution API may complete skipped clocks explicitly, with an audit reason; human users do not jump directly from open to resolved.

One lifecycle service updates the alert timestamps, actor, audit log, and SLA record together. Dismissed false positives are excluded from operational SLA compliance rather than appearing compliant. A resolved/closed/dismissed alert is excluded from active counts, breach jobs, escalations, and worklists.

An alert cannot resolve while a task is open or blocked. Every task must be completed, cancelled with a reason, or transferred to an H&S corrective action. Transfer stores the linked corrective-action ID and audit actor/time.

### Incident review

The incident moves `draft → submitted → reviewed → closed`. Closing requires manager review and no open incident follow-ups. It does not claim that H&S governance is closed. Reopening an incident records a journey attention flag and gives the operator an explicit “Reopen operational response” action when the linked alert is terminal.

### H&S governance

H&S closure requires:

- accepted handover for incident-backed events;
- WorkSafe not pending;
- required investigation completed;
- every recommendation explicitly dispositioned as corrective action, accepted risk, duplicate, or no action with reason;
- every corrective action verified or closed;
- closure summary.

Operational resolution and incident closure do not silently close H&S. The UI can therefore truthfully show “Operational response complete · H&S investigation continuing.”

## 9. Control Room desktop information architecture

The primary navigation becomes:

- **Desk** — the action-first dashboard.
- **Active alerts** — the full operational worklist.
- **Escalations** — escalation oversight.
- **Safety handovers** — incident/H&S continuity states.
- **My queue** — operator/coordinator work; not labelled My Day.
- **Shifts** — active shift and prepared/accepted handover.
- Communications, devices, playbooks, SLA, settings, and analytics retain their specialist destinations.

Support workers use the canonical application My Day and do not receive a second Control Room “My Day.”

### Universal Tasks contract

`/tasks` remains the application-wide operational work hub. Control Room `My queue` is a focused Control Room view; it does not replace or compete with Universal Tasks.

Every actionable Control Room alert, incident follow-up, H&S investigation and H&S corrective action must reach `/tasks` through its canonical task provider with the same permission, tenant and site rules as its source module. Rows show the source module, official journey references, person/site where authorised, owner, due/SLA state, current status and one clear next action. The destination opens the canonical Alert, Incident or H&S workspace rather than a duplicate task detail workflow.

The hub defaults to active work. Resolved, closed, dismissed, completed, cancelled and transferred source records appear only through the explicit completed/history view. A transfer from a Control Room operational task to an H&S corrective action must replace the active source work with the H&S action; it must not create two active tickets for the same responsibility. Journey grouping may show the related accountable records together, but must not collapse genuinely separate responsibilities such as incident review and an assigned corrective action.

## 10. Desk dashboard design

The first desktop viewport is an operational decision surface, not an analytics wall.

### Shared operational ribbon

`Receive → Acknowledge → Triage & control → Record & hand over → Resolve → Close`

### Hero

- Title: `Control Room · Command centre`
- Freshness: exact “Updated” time plus `Refreshing` and `Stale` states.
- Primary action: `Open next priority alert`.
- Secondary actions: `Raise alert`, `Report incident`, and `Prepare handover` when authorised.

### Now cluster

- Needs acknowledgement
- SLA at risk or breached
- Critical
- Unassigned

### Continuity cluster

- My queue
- Open operational tasks
- Safety handovers awaiting H&S
- Shift handover due

### Footer filters

- site;
- queue;
- source;
- search;
- clear;
- updated/stale state.

### Priority worklist

The worklist is above historical analytics and defaults to active work. Each row shows:

- official reference;
- person/client and site;
- plain-language summary;
- severity with icon and text;
- SLA state with icon, text, and remaining/overdue time;
- assignee;
- journey state;
- one recommended CTA.

Shared ordering is:

1. active before inactive;
2. SLA breached, then at risk;
3. explicit desk priority;
4. severity;
5. escalation level;
6. oldest waiting work.

### Continuity panel and service health

The right panel shows active shift, prepared incoming handover, H&S acceptance, and governance continuing after operational resolution. The dashboard retains only a small “Last 24 hours” service-health summary with a link to analytics. Historical charts, site comparisons, source distribution, and staff-performance analysis move to Stats/Reports and are not polled with the live worklist.

## 11. Active alerts and workspace

- Default tab: active alerts, not all records.
- Snoozed is separate and excluded from action counts until due.
- Backend summary, playbook progress, SLA text, and official references are rendered rather than discarded.
- Sorting labels and backend sorting agree.
- Sort controls are keyboard buttons with `aria-sort`; selection controls have accessible names.
- Status, severity, relative time, and SLA vocabulary use shared helpers and `en-NZ`/`Pacific/Auckland` formatting.
- The alert workspace keeps the existing `WizardShell`, but shows one primary next action. Reassign, escalate, edit, and other utilities are secondary.
- The linked-records section offers the in-context `Create incident and hand over` action and never directs users to create a disconnected record elsewhere.

## 12. Safety handovers

`/control-room/incidents` is renamed and rebuilt as Safety handovers. It is not a second incident register and does not merge raw medication and safeguarding models in memory.

Lenses are:

- Needs incident record
- Awaiting H&S acceptance
- Accepted / investigation active
- Operationally resolved / governance continuing
- Complete

Rows use the shared journey presenter and open the canonical incident or H&S detail surface. “Create Alert” is shown only when the journey has no alert and the user is authorised; it is absent for already-linked or closed journeys.

## 13. Shift handover

Shift handover uses two explicit states:

- **Prepared:** outgoing lead selects an incoming lead, reviews all critical/high alerts, and saves a structured snapshot.
- **Accepted:** incoming lead reviews and accepts; only then is outgoing shift completed and the incoming shift activated atomically.

The structured snapshot contains alert reference, summary, person/site, assignee, SLA, open tasks, incident/H&S links, current owner, and next action. Priority items are linked records, not free-text strings. The draft autosaves and supports resume. A version check prevents overwriting a newer handover.

The old acknowledge endpoint must not reactivate a completed shift.

## 14. Reconciliation and existing data

An idempotent console command audits and repairs:

- incident ↔ alert ↔ H&S link agreement;
- missing official references;
- submitted incidents missing H&S events;
- incident-backed alerts missing direct links;
- duplicate incident alerts;
- H&S WorkSafe state versus legacy projections;
- incident-time site snapshots where a reliable historical source exists;
- dismissed alerts still treated as active;
- H&S acceptance defaults for pre-existing actively managed events.

The command supports `--dry-run` and apply mode, prints counts by issue type, records unresolved ambiguities, and is safe to rerun.

## 15. Performance, accessibility, and error states

- The Desk polls only the operational worklist, counts, continuity, and freshness token.
- Historical analytics load on demand and may be cached separately.
- A request failure changes the freshness label to stale and preserves the last visible worklist.
- Critical-alert notification compares stable alert identities, not only the count.
- Status and SLA never rely on colour alone.
- Interactive rows are buttons/links or have equivalent keyboard semantics.
- Empty states state why the list is empty and the next available action.
- Loading states preserve layout and do not present zero as a measured value.
- Backend null response-time metrics remain `null`/unavailable; they are never cast to `0m`.

## 16. Five required end-to-end scenarios

Each scenario must be proved through browser actions and database invariants on desktop.

1. **Existing manual Control Room alert:** acknowledge, start triage, create and submit incident from the alert, verify one linked incident and H&S event, accept in H&S, transfer/finish tasks, resolve operational response, and show governance continuing.
2. **Support-worker manual report:** save draft and verify no H&S event/alert; submit the same draft and verify one H&S event awaiting acceptance, no automatic alert for low/medium severity, accept in H&S, review and close incident independently.
3. **High/notifiable manual incident:** submit and verify exactly one automatic alert and one H&S event, consistent WorkSafe pending count in Incidents and H&S, record/acknowledge WorkSafe, complete investigation/recommendation disposition/actions, then close governance.
4. **Sensor fall confirmation:** open the existing sensor alert, confirm it, verify one sensor incident, one H&S event, evidence visible in the handover, no duplicate alert, H&S acceptance, and operational resolution.
5. **Medication safety incident:** create through the medication integration, verify one official incident/alert/H&S correlation despite the signal path, accept in H&S, verify source/evidence, and complete the applicable review/governance handoff.

For every scenario the assertions include official references, incident-time site, role visibility, exact record counts, matching foreign keys, H&S appearance, acceptance actor/time, the three lifecycle states, and the expected Universal Tasks entries before and after assignment, transfer and completion.

## 17. Requirement-to-evidence ledger

| ID  | Requirement                                 | Required completion evidence                                                                                     |
| --- | ------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| R1  | One understandable cross-module journey     | Browser proof and presenter contract tests show the same references and three states in all workspaces           |
| R2  | Idempotent alert/incident/H&S linking       | Feature tests repeat every entry path and assert record counts plus matching direct links                        |
| R3  | Draft versus submit is truthful             | Controller and browser tests prove draft creates no H&S event and submit does                                    |
| R4  | H&S handover is usable                      | H&S detail includes narrative, controls, evidence, tasks, source refs, ownership, and next action                |
| R5  | H&S acceptance exists                       | Migration, service tests, permission tests, and browser acceptance evidence                                      |
| R6  | One WorkSafe source                         | Incidents and H&S query the same H&S state and show equal counts in tests and browser                            |
| R7  | Site and sensitivity isolation              | Role/site feature tests cover lists, pickers, workspaces, and every nested mutation                              |
| R8  | Honest alert lifecycle/SLA                  | State-machine tests, SLA clock tests, dismissed exclusion tests, breach-job tests                                |
| R9  | Closure gates are explicit                  | Alert-task, incident-follow-up, WorkSafe, recommendation, action, close, and reopen tests                        |
| R10 | Dashboard is action first                   | Desktop screenshot and DOM order show priority worklist in first viewport and no analytics wall                  |
| R11 | UI feels like H&S                           | Shared hero/ribbon/filter/status components are used by Control Room and visual review confirms matching grammar |
| R12 | Active work is prioritised                  | Query-order tests and browser rows prove SLA/priority/severity/escalation/age order                              |
| R13 | Safety handovers replace duplicate register | Controller no longer merges raw domains; browser presents only linked journey lenses                             |
| R14 | Shift handover requires acceptance          | Service/controller/browser tests prove Prepared → Accepted atomic transition and no reactivation bug             |
| R15 | Official references everywhere              | Presenter and browser tests contain generated references and no fabricated raw-ID labels                         |
| R16 | Performance/freshness is honest             | Query/payload tests, network observation, and stale-state browser test                                           |
| R17 | Five incidents work end to end              | Five independent browser traces plus database invariant report                                                   |
| R18 | Existing data can be repaired               | Dry-run/apply/rerun command tests and a reconciliation report                                                    |
| R19 | Desktop accessibility basics                | Automated component/browser checks for text+icon states, sort semantics, names, and keyboard actions             |
| R20 | No mobile scope leakage                     | Implementation plan and verification report contain desktop targets only                                         |
| R21 | Universal Tasks is the cross-module work hub | Provider, UI and browser tests prove scoped, deduplicated journey work with canonical links and truthful history  |

## 18. Completion definition

This programme is complete only when:

- all R1–R21 evidence exists and is current;
- targeted backend and frontend tests pass;
- the full relevant feature suites pass;
- frontend unit tests, type checking, client build, and SSR build pass or any unrelated baseline failure is isolated with evidence;
- five desktop incident scenarios pass end to end and land in H&S as specified;
- a fresh code/UI/workflow re-audit finds no open P0/P1 issue within this scope;
- no tracked user work outside the isolated branch has been changed.
