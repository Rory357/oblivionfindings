# Control Room, Incident, H&S and Universal Tasks Remediation Design

**Status:** Approved design

**Date:** 2026-07-16

**Branch:** `codex/control-room-hs-remediation`

**Baseline:** clean `origin/main` at `b5b5df463ce788fbbf988c74f5142b7fcbb52628`

**Authoritative audit:** `C:\Users\steph\Herd\oblivionfindings\docs\audits\control-room-multi-role-manual-ux-audit-2026-07-16.md`

## Goal

Fix every finding D-01 through D-19 from the manual multi-role audit without deferral, then prove the complete Control Room → Incident → H&S → investigation → corrective action → independent verification → module closure → Universal Tasks journey locally and on the live development server.

The goal is complete only when:

- every finding has implemented code, focused regression coverage and durable evidence;
- every role can complete its expected responsibility without Admin substitution;
- the seven-persona golden journey closes successfully in actual desktop Chrome;
- alternate branches A–F are manually exercised;
- database, audit and log integrity agree with the visible UI;
- the updated audit contains no `Deferred`, `Not tested`, `Partial`, or unresolved P0–P3 row.

## Why the existing completion evidence was insufficient

The merged unified-journey branch already contains substantial orchestration and automated coverage. Its prior completion audit claimed that WorkSafe truth, corrective-action verification, task reconciliation, shift handover, permissions and closure gates were closed.

The live seven-persona audit contradicted those claims:

- the verifier could not see evidence that automated tests had inserted directly into requests;
- WorkSafe notification worked only when a record was already flagged, while the real post-handover UI had no explicit decision step;
- Universal Tasks exposed technically valid provider rows but misleading status and action language;
- the task-transfer service worked when called directly, but the recommendation-created action flow did not reconcile the real operational task;
- shift handover passed deterministic fixtures but became unusable against a long-running shift with a large active queue;
- route authorization blocked a worker correctly, but the Tasks UI still advertised the forbidden action;
- prior browser tests did not require a genuine multi-account relay through the live workflow.

This remediation therefore strengthens the existing canonical architecture and changes the acceptance strategy. It does not create a parallel journey system.

## Approaches considered

### Approach A — Strengthen the existing canonical journey

Keep `ControlRoomAlert`, `ClientIncident`, `HsEvent`, `HsInvestigation`, `HsCorrectiveAction`, `AlertTask` and `TaskAggregator` as the accountable records. Add missing decision state, explicit links, evidence presentation, permission-aware actions and bounded handover semantics.

**Benefits**

- reuses the existing transactional journey and site-scoping rules;
- keeps audit history and official references intact;
- avoids a second task or governance lifecycle;
- directly addresses the live defects.

**Cost**

- touches several connected modules and requires coordinated migrations, presenters, UI and tests.

### Approach B — UI-only remediation

Expose existing fields and adjust labels without changing domain state.

**Rejected because**

- `false` cannot distinguish an explicit WorkSafe decision from an untouched default;
- the recommendation flow has no durable link to the operational task;
- file evidence and stale-shift recovery require backend ownership;
- presentation-only closure gates can drift from server enforcement.

### Approach C — New cross-module journey aggregate

Create a new top-level journey record that owns all state and tasks.

**Rejected because**

- it duplicates existing accountable records;
- it introduces migration and reconciliation risk;
- it would create another source of truth after the prior programme explicitly removed duplicate registers.

## Chosen architecture

Use Approach A in one isolated branch with small milestone commits. Domain services remain authoritative; presenters expose the same truth to every UI; Universal Tasks remains a projection rather than a second workflow engine.

The work is divided into these connected design units:

1. explicit WorkSafe decision and regulatory closure truth;
2. evidence-complete corrective-action ownership and verification;
3. explicit operational-task transfer and Universal Tasks reconciliation;
4. cross-module evidence continuity and truthful closure semantics;
5. bounded, recoverable Control Room shift handover;
6. role-aware actions, reliable inputs, date-only values and accessibility recovery;
7. complete automated, browser and live acceptance proof.

## 1. Explicit WorkSafe decision

### Problem

`HsEvent.worksafe_notifiable` is a non-null boolean. `false` currently means both:

- an authorised person explicitly assessed the event as not notifiable; and
- nobody made a decision and the default remained false.

The closure gate treats both cases as complete. Notification and acknowledgement controls render only when the value is already true.

### Data model

Make `hs_events.worksafe_notifiable` nullable:

- `null` — decision not recorded;
- `false` — explicitly assessed as not notifiable;
- `true` — explicitly assessed as notifiable.

Add:

- `worksafe_decided_at` nullable timestamp;
- `worksafe_decided_by_user_id` nullable foreign key to `users`;
- `worksafe_decision_reason` nullable text;
- `worksafe_decision_source` nullable string with supported values `manual`, `incident_report`, `classifier`, `migration`.

Retain the existing:

- `worksafe_status`;
- notification date, method and reference;
- acknowledgement date;
- site-preservation state.

### Migration and legacy handling

Migration rules are conservative:

- any event with `worksafe_notifiable = true` or existing WorkSafe notification state becomes explicit `true`;
- any open event with `worksafe_notifiable = false` and no decision metadata becomes `null`;
- closed historical events remain unchanged so the migration does not retroactively reopen completed records;
- new records default to `null`;
- an explicit incident-report or classifier decision writes decision metadata at creation time.

The deployment runbook must report counts for each migration category before and after migration.

### Domain service

Add an H&S service operation to record or revise the decision:

```php
recordWorksafeDecision(
    HsEvent $event,
    bool $notifiable,
    string $reason,
    User $actor,
    string $source = 'manual',
): HsEvent
```

Rules:

- requires `hazards.manage`;
- reason is required;
- actor, timestamp and source are always stored;
- setting true initializes `worksafe_status` to `pending` when needed;
- setting false clears pending-only state but never erases a completed notification;
- an event that has been notified cannot be changed to false through the normal path;
- every decision or revision writes an audit entry with before/after state.

### HTTP and UI

Add a visible `Record WorkSafe decision` action after H&S acceptance.

The pane presents:

- `Not notifiable` and `Notifiable` as explicit choices;
- a required rationale;
- the current decision actor and time when revising;
- clear notice that a notifiable decision begins the notification duty.

Display states everywhere:

- `Decision not recorded`;
- `Not notifiable — decision recorded`;
- `Notification pending`;
- `Notified — acknowledgement pending`;
- `Acknowledged`.

### Closure gate

`worksafe_ok` is true only when:

- an explicit false decision exists with decision actor and timestamp; or
- an explicit true decision has reached the required notification state.

An undecided event adds:

`Record the WorkSafe notifiability decision before closing this event.`

The gate payload includes a direct action route and is shared by H&S, incident and Control Room closure presentations.

## 2. Corrective-action ownership, evidence and verification

### Problem

Recommendation-created actions can be ownerless. The completion UI accepts notes but not actual attachments. The H&S event payload omits completion notes, evidence paths and return reason. The verifier is asked to attest effectiveness without seeing the submission.

### Ownership

All new corrective actions require:

- `assigned_to_user_id`;
- `due_date`;
- site eligibility;
- actor and assigned timestamp.

This applies to:

- standalone actions;
- recommendation-created actions;
- operational-task transfers.

The recommendation action is created through a short handover pane containing:

- recommendation text;
- eligible owner;
- due date;
- priority;
- optional linked Control Room task or explicit `New responsibility` choice.

An action cannot be created ownerless.

### Evidence storage

Reuse the private polymorphic `hs_attachments` table.

Add `HsCorrectiveAction::attachments(): MorphMany`.

Create a focused evidence controller for:

- upload;
- authenticated download;
- remove before verification/closure.

Storage rules:

- private disk;
- safe generated path;
- original name, MIME type, size, uploader and description retained;
- allow PDF, common image formats and office documents;
- enforce a documented per-file size limit;
- site and action permissions are checked before every operation;
- failed database writes remove newly stored files;
- removal is audited and unavailable after verified/closed state.

`completion_evidence_paths` remains readable for legacy records during migration, but new uploads use `HsAttachment`. A one-time compatibility presenter exposes both sources until legacy paths are reconciled.

### Completion

The completion pane supports:

- completion notes;
- one or more evidence files;
- visible upload success/failure;
- existing attached evidence;
- a statement that independent verification remains required.

Completion requires notes or at least one retained attachment. It records a submission snapshot in the audit log.

### Rework history

The current `verification_notes` field remains the latest actionable return reason.

Human-readable history is derived from the existing append-only audit records and exposes:

- original completion;
- verifier return reason;
- owner resubmission;
- changed notes/evidence;
- actor and timestamp for each transition.

No machine action names are shown directly to users.

### Verification

The event payload and corrective-action register include:

- completion notes;
- all current evidence attachments;
- legacy evidence paths;
- completer and completion time;
- latest rework reason;
- readable lifecycle history;
- linked investigation recommendation;
- linked operational task;
- owner and due date.

The verification pane is ordered:

1. required recommendation and source responsibility;
2. owner submission;
3. prior rework and resubmission;
4. verifier decision.

The evidence section must load successfully before Verify is enabled. The request includes a required `evidence_reviewed = true` acknowledgement. Server-side separation of duties remains mandatory.

## 3. Operational task transfer and duplicate prevention

### Problem

The existing transfer service correctly replaces an `AlertTask` when that service is invoked. The audited journey instead created an action from an investigation recommendation, leaving the overlapping operational task open.

### Data link

Add nullable unique `source_control_room_task_id` to `hs_corrective_actions`.

Relationship:

- `HsCorrectiveAction::sourceControlRoomTask()`;
- `AlertTask::transferredCorrectiveAction()`.

One operational task can transfer to at most one corrective action.

### Transfer choice

When creating an action from a recommendation, show unresolved tasks from the linked Control Room alert.

The H&S owner must select either:

- `Transfer this operational task`; or
- `This is new work`.

For new work, a short reason is required so the duplication decision is auditable.

### Atomic service behavior

When a source task is selected:

- lock the recommendation, action candidates and source task;
- verify the H&S handover is accepted;
- verify the owner and task are site eligible;
- create the corrective action;
- set `source_control_room_task_id`;
- mark the source task `transferred`;
- preserve source task title, history and timestamps;
- record reciprocal audit entries;
- notify the action owner;
- make retry return the existing action.

The original task is no longer active, but remains visible in history as:

`Transferred to CA-YYYY-NNNN`.

### Universal Tasks

Universal Tasks continues to show one active corrective-action responsibility. It does not create a second alert-task provider lifecycle for transferred work.

Search indexes include the linked source task title and description.

## 4. Universal Tasks truth and role-aware actions

### Shared presentation state

Extend `TaskItem` with an explicit human display state separate from raw model status.

For corrective actions:

- `open` → `Not started`;
- `in_progress` → `In progress`;
- `completed` → `Awaiting independent verification`;
- `verified` → `Verified — ready to close`;
- `closed` → `Closed`.

Only closed work is in the done/history bucket. Completed and verified work remains active until its next governance action is finished.

### Search

The search haystack includes:

- official record reference;
- all journey references;
- record title and description;
- source context;
- client name;
- site name;
- assignee name;
- linked source task title and description;
- incident narrative;
- plain-language lifecycle state.

Search tests must cover the exact client and task-title values from the manual audit.

### Permission-aware action contract

Provider CTAs use the same domain permission checks as the destination.

Control Room examples:

- manager/operator with mutation permission → `Continue Control Room response`;
- view-only user with a valid read route → `View alert`;
- user without a valid destination → no CTA and `No action for you`.

The task drawer must not show Watch, Assign, Continue, Review or governance actions unless the corresponding operation is authorised.

Provider and controller authorization share focused access services rather than duplicating permission expressions.

An unexpected authorization failure returns to `/tasks` with plain language and the filtered query preserved; it never abandons the user on a bare 403 page.

### Accessibility and navigation

- Escape and Close restore focus to the invoking task row.
- Browser Back returns to the filtered queue.
- focus is visible;
- tab order follows the visual action order;
- empty navigation groups are not rendered;
- activity entries use human labels.

## 5. Evidence continuity across Control Room, Incident and H&S

### Immediate controls

Creating a high/critical incident from Control Room requires `immediate_action_taken`.

The form is prefilled from the latest marked immediate-controls note on the alert. The operator can edit it before submission.

If no marked note exists, the form explains the missing canonical field and blocks submission until the operator records the controls or explicitly records that none were possible.

### Marked Control Room note

Operator note creation supports a typed purpose:

- general update;
- immediate controls;
- escalation/handover.

The existing note history remains intact. The typed purpose makes prefill deterministic and avoids treating an arbitrary free-text note as a control.

### Linked evidence presentation

Incident and H&S detail both render, read-only:

- Control Room operational notes;
- source tasks and transfer state;
- evidence packs and evidence items;
- communication summaries;
- official references and source timestamps.

The underlying Control Room records remain canonical. Files are linked through authorised download routes rather than copied into incident attachments.

Incident attachments and follow-ups remain separately labelled as official incident records so users can distinguish linked operational evidence from formal incident evidence.

## 6. Truthful closure semantics

### Shared gate shape

Each domain service returns a structured gate:

```php
[
    'allowed' => false,
    'requirements' => [
        [
            'key' => 'open_operational_tasks',
            'complete' => false,
            'label' => 'Complete or transfer the open Control Room task',
            'href' => '/control-room/alerts/123',
        ],
    ],
]
```

The server remains authoritative. Dialogs render this gate before accepting final text.

### Alert resolve

Resolve means operational response is complete.

It requires:

- no open/cancel-pending operational task;
- required resolution note;
- evidence/playbook requirements already enforced by the lifecycle.

It does not claim H&S governance is closed.

The preflight disables Resolve when an operational task remains and links directly to it.

### Alert close

Close means the complete linked journey is closed.

For linked incidents/H&S events it additionally requires:

- incident closed;
- H&S event closed;
- no active transferred or duplicate responsibility.

### Incident close

Requires:

- manager review;
- required follow-ups complete;
- required investigation complete;
- linked H&S governance closed when the incident entered H&S governance.

### H&S close

Requires:

- handover accepted when required;
- explicit WorkSafe decision and required notification state;
- investigation complete;
- every recommendation dispositioned;
- every corrective action verified or closed;
- closure summary.

### Shared language

Every journey surface uses:

- `Operational response active`;
- `Operationally resolved`;
- `Incident review complete`;
- `H&S acceptance pending`;
- `H&S governance active`;
- `Awaiting independent verification`;
- `Governance closed`;
- `Journey closed`.

## 7. Bounded and recoverable Control Room shift handover

### Problem

The current service requires the outgoing lead to review every active Critical/High alert visible to them. On the live development shift this meant 1,602 individual reviews and no recovery when the named outgoing lead was unavailable.

### Handover scope

Create `ControlRoomHandoverScopeService`.

An alert requires individual review when any of these occurred during the shift:

- created;
- acknowledged, triaged, escalated, snoozed, unsnoozed or materially updated;
- assigned to or watched by a shift member;
- SLA breached or entered an at-risk threshold;
- received an open task due before the next expected shift;
- entered an incident/H&S/verification decision state;
- was explicitly pinned by the outgoing lead.

Pre-existing active alerts with no shift-period change are not individually checked. They appear in a carry-forward summary with:

- counts by severity and queue;
- oldest age;
- breached count;
- a drill-down link;
- an explicit outgoing acknowledgement.

No critical alert changed during the shift may be hidden by pagination or a hard cap.

### Prepared snapshot

The immutable prepared snapshot contains:

- exact required-alert query time and criteria;
- reviewed alerts;
- priority alerts;
- open tasks;
- linked governance state;
- carry-forward summary;
- pinned/follow-up notes;
- outgoing actor and time;
- selected incoming lead/team;
- override information when used.

Incoming acceptance continues to atomically close the outgoing shift and create the new active shift.

### Stale-shift recovery

A shift older than a configured threshold displays a prominent stale-shift banner.

If the outgoing lead is unavailable, an authorised user with a dedicated handover-override permission may prepare the handover with:

- required reason;
- the same review and snapshot rules;
- explicit override label;
- strict audit entry.

The override does not bypass incoming acceptance.

Fixture setup creates a fresh active shift with a bounded required set. Live acceptance never relies on historical global demo backlog.

## 8. Reliable controls, date-only values and consistent UI

### Select controls

`SelectInput` stays controlled from first render:

```tsx
<Select value={value} onValueChange={onChange}>
```

Empty forms use a stable empty-string value or an explicit sentinel where Radix requires a non-empty item value.

Regression coverage includes:

- mouse selection commits the clicked client;
- keyboard selection commits the highlighted client;
- task assignee selection commits exactly one intended user;
- reopening a form restores the stored value;
- no controlled/uncontrolled warning appears.

### Date-only values

Investigation target and corrective-action due dates remain `YYYY-MM-DD` strings across request, model presentation and UI.

Date-only values use a date-only formatter, never `formatDateTime` or `toISOString`.

Tests run under New Zealand and UTC-adjacent timezone conditions to prove no day shift.

### Dashboard and navigation

- H&S Awaiting acceptance is a first-priority worklist, not only a register filter.
- Back and Close preserve the originating filter/search.
- user menus work in expanded and collapsed sidebar states.
- empty permission-only menu groups are hidden.
- status, priority, severity, escalation and SLA receive short inline explanations.

## 9. File and ownership boundaries

The implementation plan will use these existing ownership surfaces:

### H&S domain

- `app/Models/HsEvent.php`
- `app/Models/HsCorrectiveAction.php`
- `app/Models/HsAttachment.php`
- `app/Services/HealthSafety/HsEventService.php`
- `app/Services/HealthSafety/HsCorrectiveActionService.php`
- `app/Http/Controllers/HealthSafety/HsEventController.php`
- `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php`
- new `app/Http/Controllers/HealthSafety/HsWorksafeDecisionController.php`
- new `app/Http/Controllers/HealthSafety/HsCorrectiveActionEvidenceController.php`
- new `app/Http/Requests/HealthSafety/RecordHsWorksafeDecisionRequest.php`
- new `app/Http/Requests/HealthSafety/UploadHsCorrectiveActionEvidenceRequest.php`
- `resources/js/components/health-safety/event-detail-dialog.tsx`
- `resources/js/pages/health-safety/corrective-actions/index.tsx`
- H&S dashboard worklists

### Incident journey

- `app/Services/Incidents/IncidentJourneyService.php`
- `app/Services/Incidents/IncidentJourneyPresenter.php`
- `app/Http/Controllers/IncidentController.php`
- `resources/js/components/incidents/incident-report-dialog.tsx`
- `resources/js/components/incidents/incident-detail-dialog.tsx`

### Control Room

- `app/Models/ControlRoom/AlertTask.php`
- `app/Services/ControlRoom/ControlRoomAlertLifecycleService.php`
- `app/Services/ControlRoom/ControlRoomShiftHandoverService.php`
- new `ControlRoomHandoverScopeService`
- Control Room alert/task/handover controllers
- `resources/js/components/control-room/alert-workspace-dialog.tsx`
- `resources/js/pages/control-room/shifts/handover.tsx`

### Universal Tasks and shared UI

- `app/Services/Tasks/TaskItem.php`
- `app/Services/Tasks/TaskAggregator.php`
- incident-journey task providers
- `resources/js/pages/tasks/index.tsx`
- task detail components
- `resources/js/components/wizard/primitives.tsx`
- shared journey/status components
- app-sidebar navigation composition

Large existing components are not rewritten wholesale. New evidence, gate, history and handover-scope logic is extracted into focused components/services when a unit has a distinct responsibility.

## 10. Error handling and recovery

- All lifecycle and transfer operations are transactional and retry-safe.
- File storage compensates on database failure.
- stale version conflicts preserve draft data and instruct the user to reload;
- failed evidence load prevents verification;
- permission changes between list and click return a recoverable message to the source queue;
- migration commands report affected row counts;
- no repair command runs automatically on live data;
- live deployment takes a backup and read-only pre-migration snapshot before migration.

## 11. Automated verification strategy

Use test-driven development for every behavior change.

### Backend feature/service coverage

- WorkSafe unknown/false/true decision and closure matrix;
- decision actor/time/reason/source and audit entries;
- notification guards after decision;
- action creation requires eligible owner;
- evidence upload/download/removal and private storage;
- completion/return/resubmission/verification payload;
- separation of duties with evidence acknowledgement;
- recommendation-to-task atomic transfer and retry;
- TaskAggregator natural-language search and truthful display status;
- provider CTA permission parity and no bare 403;
- incident immediate-control prefill and linked evidence payload;
- resolve/close gate matrices;
- handover scope, carry-forward summary, stale override and acceptance;
- New Zealand date-only persistence;
- site and role isolation for every new endpoint.

### Frontend component coverage

- verifier sees notes, files, history and return reason;
- WorkSafe decision pane and truthful gate labels;
- required owner/source-task selection;
- Tasks status and CTA variants;
- incident linked operational evidence;
- closure preflight direct routes;
- bounded handover sections;
- Select mouse/keyboard reliability and no warnings;
- focus return and filtered Back recovery;
- date-only rendering;
- human activity labels and terminology help.

### Browser coverage

Deterministic production-built desktop scenarios cover:

- the exact seven-persona relay;
- one rework/resubmission cycle;
- WorkSafe decision, notification and acknowledgement;
- operational task transfer;
- incoming shift acceptance;
- all final closures;
- worker view-only permission behavior;
- search by CR/INC/HS/CA, client, site and source-task title;
- alternate branches A–F;
- keyboard focus and console assertions.

Automated browser tests supplement but do not replace the final actual-Chrome live pass.

## 12. Deployment and live acceptance

Local completion gates:

- focused tests for each milestone;
- full relevant Control Room, Incidents, H&S and Tasks suites;
- frontend unit suite;
- TypeScript;
- scoped ESLint and Prettier;
- Pint and `git diff --check`;
- client production build;
- SSR production build;
- production-built desktop browser suite.

Delivery gates:

- intentional commits on `codex/control-room-hs-remediation`;
- review against this specification and implementation plan;
- merge to current main;
- push;
- deploy to the live development server;
- migrations and permission synchronization;
- live fixture setup with a fresh bounded shift;
- actual desktop Chrome seven-persona relay;
- alternate branches A–F;
- final read-only database and log integrity check;
- updated durable audit and evidence bundle.

## 13. Finding-to-design coverage

| Finding | Design ownership |
|---|---|
| D-01 verifier cannot see evidence | Sections 2 and 11 |
| D-02 WorkSafe flag/false completion | Section 1 |
| D-03 Tasks says Completed | Section 4 |
| D-04 action owner picker absent | Sections 2 and 3 |
| D-05 unusable shift handover | Section 7 |
| D-06 search omissions | Section 4 |
| D-07 duplicate operational responsibility | Section 3 |
| D-08 evidence/context fragmentation | Section 5 |
| D-09 worker CTA → 403 | Section 4 |
| D-10 resolve preflight misleading | Section 6 |
| D-11 picker mis-selection | Section 8 |
| D-12 rework reason invisible | Section 2 |
| D-13 date shifted | Section 8 |
| D-14 intended manager cannot find action | Sections 2 and 4 |
| D-15 H&S dashboard misses acceptance | Section 8 |
| D-16 Escape loses focus | Sections 4 and 8 |
| D-17 Select warnings | Section 8 |
| D-18 machine activity labels | Sections 2 and 4 |
| D-19 Back/sidebar inconsistency | Sections 4 and 8 |

Every row is mandatory. The implementation plan may split work into milestones, but it may not move a row to another programme or future backlog.

## 14. Completion evidence

The final closeout must contain:

- exact commits and deployed SHA;
- migration and permission output;
- test commands, counts and exit codes;
- client and SSR build results;
- browser results and screenshots;
- seven-persona matrix with Pass for every tester;
- golden journey with every acceptance criterion Passed;
- alternate A–F matrix with every branch Passed;
- record references and direct links;
- H&S decision/notification actors and timestamps;
- action owner, completer and independent verifier;
- source-task transfer proof;
- final closure actors/timestamps for alert, incident, H&S and action;
- Universal Tasks active/history reconciliation;
- final console and server-log result;
- explicit statement that no finding was deferred.

The goal remains incomplete if any evidence is missing, indirect, stale, fixture-only where live proof was required, or contradicted by the visible application.
