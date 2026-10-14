# Supported-living Workforce: connected architecture proposal

5 October 2026 · Proposal, not implemented · One organisation across approved houses/sites

Rostering should answer **what support is needed, who can provide it, whether it is covered, and what needs a decision**. Each source module keeps its existing authority. A coordinator should be able to start in a house, employee, leave request, person supported, safety record or transport plan and arrive at the same dated staffing picture. The return journey must show the outcome of the action.

This extends the [module-by-module state report](module-status.md), [ranked findings](audit.md), [navigation/parity plan](navigation.md) and [implementation packages](implementation-plan.md). Current source and proposed behaviour are deliberately distinguished. No application changes have been made.

## 1. Ownership: one fact, one authoritative owner

| Fact | Authoritative owner | What Workforce consumes | What Workforce must not take over |
| --- | --- | --- | --- |
| Person's placement and approved service context | Operations / Sites | Dated person–site–service relationship | Placement/consent approval |
| Support needs, ratios and person-specific skills | Approved care/support plan and explicit coverage requirements | Effective demand windows, roles/skills and authorised continuity preferences | Clinical decisions, plan approval or a funding-only inference of need |
| House minimums and skill mix | Sites coverage requirements, with H&S/HR requirement references | Required headcount and qualified cover by interval | Duplicate editable copies of the house rule |
| Employment, site approval and onboarding | HR and canonical site-access services | Current and future duty eligibility; start/exit dates; relevant prerequisite status | Personnel case files or role grants |
| Leave entitlement and approval | HR LeaveService | Pending/approved absence, affected shifts and cover source ID | A roster-side balance ledger or approval bypass |
| Availability preference and other unavailable time | Existing staff availability/time-off records | Duty-window suitability with type/source | Pretending a preference is approved leave |
| Verified competencies | HR and domain-specific competency policies | Validity throughout relevant duty interval | Requiring users to update duplicate qualifications by name |
| Safety controls / authorised work restrictions | H&S / authorised HR work-capacity decision | Minimal structured duty restrictions, dates and review owner | Diagnoses, allegations or automated fitness decisions |
| Assignment and published roster | Existing Shift, assignment, replacement and publication services | Canonical assignee, scope, version and publication state | An alternative assignment in a dashboard/task card |
| Actual attendance and breaks | AttendanceService and session records | Actual presence/elapsed work/corrections | Guessing attendance from GPS, a booking or publication |
| Care, medication and observation evidence | Existing care/eMAR/clinical services | Task responsibility and appropriately limited readiness/status | Completing a clinical action from a roster acknowledgement |
| Vehicle booking, readiness and release | Fleet | Reserved resource and exact-use decision | Vehicle release after repair or driver competence approval |
| IT work booking/access fulfilment | IT | Committed duty intervals / access-ready status | Ticket secrets or automatic permissions |
| Approved worked time, payroll and billing | Timesheet → HR payroll / Operations billing → Finance | Estimate/approved/exported/posted references and immutable snapshots | Rewriting historical pay or creating charges from scheduled hours |
| Planned operator staffing | Bounded Control Room planning addition | Separate planned operator intervals | Reusing a care Shift with a fake person supported |
| Live operator duty, alerts and accepted handover | Independent Control Room | Permitted actual-duty linkage and exception outcome | Starting duty or transferring alert ownership from publication |
| Location/device evidence | Security & Devices / governed tracking | Authorised readiness signal with timestamp and source | Broad identity/location access or proof of safety |
| Reports and universal tasks | Read models/providers of the above | Source-linked measures and action projections | A second approval, resolution or record lifecycle |

Use existing services and projections. Do not introduce a new platform, a second roster store or a general-purpose rules engine merely to connect pages. Extend the canonical eligibility and coverage services with small source adapters where evidence proves a gap.

## 2. Two-way change-impact contract

The integration must handle both “is this worker available when I assign?” and “what assignments are affected when availability changes?”. A cross-link alone does not supply the second half.

| Change at source | Existing foundation | Required impact and user receipt | Entry and return locations |
| --- | --- | --- | --- |
| Leave requested | HR pending workflow | Show overlaps as provisional information. Do not treat pending as approved absence unless explicit policy says so. | My HR / HR Leave → affected shifts; Roster → request |
| Leave approved | LeaveService creates time off, ledger and cover actions | Show all affected shifts, replacement/handover action, owner, due time and actual cover progress. | Leave detail ↔ Cover & conflicts / house roster |
| Approved leave edited/cancelled | Observer/projection sync and source-owned cancellation exist | Recalculate added/removed impacts; preserve independently raised replacements and already accepted cover. Explain remaining actions. | Same leave receipt ↔ same canonical shifts |
| Worker declares unavailable/training time | StaffTimeOff exists | Distinguish unavailable time from leave. Show conflict impact; do not silently approve HR leave. Training attendance and certification are distinct. | Availability ↔ HR Training / relevant shifts |
| Exit date or onboarding readiness changes | Offboarding cover service and assignment onboarding gate exist | Evaluate effective date, future shifts and ongoing handover. A cancelled exit must not cancel someone else's open position. | Employee lifecycle ↔ affected work |
| Competency expires, is renewed or revoked | Canonical rule stack; nightly future scan | Evaluate every affected planned window, including expiry during the duty. Refresh named exceptions promptly and close only genuinely resolved ones. | HR/H&S/eMAR source ↔ worker eligibility ↔ impacted shifts |
| Approved modified duty changes | H&S stores hours/restrictions; no inspected eligibility consumer | Publish an authorised minimal work-capacity record; evaluate relevant assignments and identify a coordinator decision. | RTW detail ↔ restricted eligibility summary / affected duties |
| House minimum or required skills change | SiteCoverageRequirement feeds coverage service | Preview affected draft/published windows; save effective dates and show resulting gaps. Do not silently rewrite historical or published assignments. | House People → Shift Coverage ↔ roster coverage window |
| Care plan / behaviour plan approved | Versioned source plans exist | Explicit structured staffing effect with source/version/effective date; draft remains draft. Show continuity and specific skills without over-sharing care details. | Person plan ↔ person support schedule / house roster |
| Person moves house / service changes | Canonical placement/site context | Reauthorise future record graph; flag invalid future assignments/resources and return a review receipt. Historical evidence keeps original scope/history. | Client/site source ↔ impacted shifts |
| Respite admission/extension/discharge | Locked booking/stay/Shift sync exists | Separate stay demand from staff duties. Re-evaluate the changed window, travel, tasks and cover; actual stay check-in cannot prove staff clock-in. | Respite stay ↔ dated staffing demand |
| Shift changes worker/time/site or cancels | Shift lifecycle, timeline, reservations and notification services | Recheck eligibility, coverage, linked work/resource impact and recipients against fresh records. Preserve completed care and approved pay. | Any shift entry point → one review/result |
| Claim/replacement approved | Locked assignment and re-evaluation exist | One canonical assignment; explicit responsibility/acknowledgement; update care/transport follow-ups and close matching cover exception. | Available shifts ↔ roster ↔ original HR/absence source |
| Vehicle/essential equipment held or booking changes | Fleet holds/readiness/transport graph | Identify affected transport/support duties; owner decides alternative resource/staffing. Do not mark released because repair is complete. | Fleet source ↔ transport plan ↔ care duty |
| IT booking added or care duty added | IT checks leave and shifts; reverse gap | Shared committed-work busy intervals in both directions, with buffers/policy. A private appointment's title never reaches Rostering. | IT work booking ↔ staff availability/roster |
| Safety incident or hazard changes | H&S events/actions and Control Room signals | Only a confirmed structured control/restriction changes eligibility; otherwise a linked review/briefing task. | H&S source ↔ affected duty/briefing |
| Lone-worker duty starts/ends or session fails | Scoped sessions and alert services | Confirm required monitoring session and response coverage; display unmonitored/stale states. Preserve independent live response ownership. | Active shift ↔ H&S monitoring ↔ Control Room |
| Timesheet approved/amended | Locked HR sync, snapshots and payroll safeguards | One approved projection; later adjustment has its own review and processing state. Published shift edits cannot change approved time. | Timesheet ↔ HR time/payroll ↔ downstream reconciliation |
| Published roster changes | Publication/diff, notifications, external calendar sync | Notify allowed affected people once; expose delivery failure and meaningful acknowledgement separately. Cancel/redact old calendar events. | Publish receipt ↔ worker My Day / My HR / permitted portal |
| Planned operator assignment changes | Actual desk-duty service currently separate | Check leave/other committed duties; update only the plan. Starting/ending duty and accepted handover remain explicit Control Room actions. | Rostering → Control Room staffing ↔ independent duty page |

### Existing leave workflow: improve visibility rather than rebuild it

`LeaveService::approveRequest()` calls `WorkforceAvailabilityCoverageService::syncApprovedLeave()` in its transaction. The latter locks affected shifts and creates/reuses replacement actions; in-progress shifts receive a handover-kind action. It preserves the original worker and tracks whether it owns the replacement. Cancellation is designed not to cancel independently created work. Offboarding uses this same foundation.

The new receipt should query these records plus the canonical replacement/open-position state: “4 affected shifts · 2 covered · 1 awaiting approval · 1 needs a handover”. Those numbers are an illustrative design example, not audited fixture data. A coordinator sees source, next action and owner. HR sees a permitted operational summary without needing broad roster access. A worker sees only their own relevant request and outcome. An `open` source-action row is not itself proof that cover remains unresolved: derive the result from the replacement's current state.

## 3. One assignment decision through every entry point

Extend `ShiftStaffEligibilityService`, retaining its conflict, time-off, rest, availability, fatigue, site, driving, H&S, medication, coverage and onboarding rules. Keep authoritative medication and driving policies; do not downgrade a non-overridable block to a generic roster warning.

All assignment-affecting commands must call the same scoped decision: normal create/edit, calendar drag, template application, recurrence/exception edit, suggestions, claims, replacement approval and publish review. Preview decisions can explain candidates; commit decisions must re-read current source state inside the relevant locking/version boundary. Shift-start checks need duty-specific handling: surface newly unsafe duties and escalation without unexpectedly blocking unrelated essential care through a generic page denial.

A shared result should contain structured reason codes, severity, applicable interval, evaluated-at time, source record/version, permitted source-action link, and whether an override is allowed with which authority. UI should say “First-aid evidence expires before this shift ends” or “Unavailable during this shift”, not expose raw implementation codes or a medical/leave reason. Unknown, not configured, not applicable, pending and passed are different states.

**Add narrowly where missing:** effective-date HR/H&S evidence; mapped site/person requirements; approved work-capacity restrictions; and committed IT/operator duties. Coverage needs qualified people per required interval as well as headcount. Configure first aid or specific support skills through recognised requirement identities. Do not silently equate a caregiver label with all support competence.

For cross-domain fatigue, use source-linked duty intervals with domain and commitment state. Avoid counting both an IT booking and its linked duty twice. Keep planned time, actual work, travel, sleepover, on-call and breaks distinct; thresholds/overrides come from approved organisational policy. Do not invent statutory limits or automatically infer a worker's health from hours.

## 4. Change propagation, freshness and safe correction

Reuse existing domain events/jobs and durable signal/outbox patterns rather than building a separate transport. For missing source-change paths, record an idempotent impact/recheck request after the source transaction commits. Carry source type/ID/version, effective interval, affected staff/site/person references and correlation key; not private source narratives. Failed work must be visible and retryable. A periodic reconciliation remains the fallback, not the only mechanism.

The current future-eligibility job scans only assigned `scheduled` shifts starting within 14 days and runs nightly. It is useful, but cannot alone guarantee that next month's roster or a shift about to start reflects a new restriction. Target changed records immediately, prioritise imminent/current duties for human response, and expose “checked at / update pending / could not check”. Re-check server-side at the actual assignment/publish action even if background work is current.

Resolve exceptions by source identity and current evidence. Reissuing a signal must not create multiple independent staffing problems; renewing training must not close an unrelated safety incident. Closing an alert does not make the underlying worker eligible. Cancelling leave does not prove the new cover arrangement should be undone. Show these outcomes explicitly.

### Lifecycle rules

| State of work | Permitted correction approach |
| --- | --- |
| Draft | Edit plan; recalculate candidate eligibility, demand and estimated hours; no worker notification implied. |
| Published future duty | Preview change and affected parties; fresh validation; versioned commit and publish/change receipt. |
| In progress | Preserve actual start and responsibility; explicit relief/handover; escalate if required skill/cover is lost. No silent unassignment. |
| Completed | Preserve original evidence; corrections append reasoned history and use the existing authorised workflow. |
| Approved/exported/payroll-locked | Retain immutable original; authorised adjustment/reconciliation, never rewriting by roster drag or recurrence edit. |
| Cancelled | Keep historical references; cancel only still-open source-owned projections, not completed observations, posted costs or other owners' work. |

## 5. Navigation and understandable outcomes

Keep the six Workforce destinations and six Rostering sections from [navigation.md](navigation.md). Add **contextual entry points** without duplicating edit forms:

| Starting point | Action presented | Destination/context | Back at source |
| --- | --- | --- | --- |
| House → People → Shift Coverage | View cover / Review gaps | House + local period + requirement window | Updated required/planned/qualified cover, checked time |
| House → Staff Requirements | Check staffing impact | Mapped requirement + affected duties | Mapping state and unresolved gaps |
| HR employee | View work schedule / Check eligibility | Canonical employee + period | Hours, absence and relevant eligibility summary |
| Leave request | Review affected shifts | Source request + authorised shift list | All impacts and cover progress, not only first conflict |
| H&S restriction/training source | Review affected work | Limited reason + effective interval | Rechecked/awaiting decision/failed and named owner |
| Person supported | View support schedule | Person + current placement + period | Continuity and required/covered support |
| Transport plan / respite stay | Review staffing impact | Source ID + interval + people/site | Resource/staff plan outcome and outstanding decisions |
| Timesheet / HR payroll | View original shift / adjustment | Correct canonical record identity | Reconciliation state |
| Control Room duty | View staffing plan | Operator domain and exact date | Planned versus actual session link |

Use validated URL state for period, view and appropriate IDs. Safe return context must be same-application and allowlisted, not an unrestricted redirect. Direct arrivals still enforce site/ownership/privacy. When a user cannot open the source, provide an authorised summary and “Contact the responsible team”, not a dead link or leaked source details.

Use the repository's PageHeader, connected tab rail, WizardShell, shared date/time controls and calendar contract. Retain the resource grid for resource planning. Keep one visible primary action, useful filters, keyboard alternatives and clear review/success/failure states. Forms must preserve current per-step validation, all specialised fields and unsaved input.

At every action, answer: what changed; what did not yet complete; who acts next; and where to continue. “Leave approved” and “Cover confirmed” are distinct successes. “Message sent” and “Replacement accepted” are distinct. Empty requirements must not become “100% compliant”.

## 6. Supported-living acceptance scenarios

These are release requirements, **not tests passed during this audit**.

1. **House and person demand:** a house has a minimum team and a person has planned 2:1 support for one window. The roster distinguishes shared house cover, that person's two workers, travel absences and genuine worker double-booking. No double-counting one worker across incompatible duties.
2. **Leave both directions:** authorised HR approval creates time off/cover actions once; self-service roster entry without approval capability stays pending or is denied approval. Date edits/cancellation reconcile balances and only source-owned work. HR and Rostering show the same complete impact list.
3. **Short-notice sickness:** an active worker cannot continue. Their duty remains recorded, a named coordinator finds eligible relief, the receiving worker accepts the relevant handover, and unfinished care/tasks/transport retain accountable ownership. Messages alone do not establish cover.
4. **Future validity:** training valid today expires before a shift ends next month. All assignment paths give the same result for that future interval. Renewal updates the exception; missing HR mapping is unknown/configuration action, never silent compliance.
5. **Modified duties:** an authorised temporary hours/duty restriction applies across house, travel, IT and operator commitments where policy requires. The roster sees the restriction and review date; it cannot read diagnosis/case notes. Ending the restriction rechecks future work with audit.
6. **House requirement change:** a future staffing/skill minimum increases. The editor previews affected published windows; existing shifts remain intact and an owner receives the actual gaps. Historic coverage reports retain the previous effective version.
7. **Client move and respite extension:** changing a placement/stay evaluates the new demand interval and permissions. One multi-day stay does not become one multi-day paid worker shift. Completed care, existing staffing and financial locks remain intact.
8. **Medication and observations:** replacement staff meet the canonical duty competence and actual-presence rules. Repeated clinical occurrences remain distinct; completed doses/observations are not duplicated, deleted or marked done by transfer.
9. **Transport disruption:** the vehicle becomes unavailable or a driver's eligibility changes. Affected trips and support cover are shown to the right owner. A substitute requires both Fleet readiness and staffing eligibility; travel buffers and return work remain visible.
10. **Dual-role work:** an IT booking blocks an incompatible care duty and a care duty blocks the IT booking under the same policy. A linked booking/duty counts once; busy reasons remain private. Operator availability is handled through a separate domain.
11. **Safety monitoring:** a configured lone-working duty lacks a current session/device response path. The status is not “safe”; the worker sees the required action and Control Room owns operational response. A zero-alert counter cannot certify monitoring coverage.
12. **Care to pay:** plan → publish → clock/break/care/handover → clock out → submit/return/resubmit/approve → HR payroll/billing. A late correction uses an adjustment and does not duplicate time entries, pay, billing or notifications.
13. **Permissions and failure:** House A coordinator, worker, HR approver, payroll reviewer and Control Room-only actor receive correct direct-ID/URL/picker/export decisions across two houses. Changes in source permission take effect before action. Queued recheck failure is visible; retries, concurrent edits and duplicate clicks are safe.
14. **Navigation and parity:** every existing function has a reachable equivalent; bookmarks preserve period/person/site; 1280/1440/1920 desktop, 200% zoom and keyboard navigation work. No field, recurring exception, audit trail, export, approval or emergency path disappears in simplification.

## 7. Evidence anchors and delivery order

Primary source paths:

- `app/Domain/Hr/Services/LeaveService.php`, `HrLeaveAccessService.php`, `WorkforceAvailabilityCoverageService.php`, `TimeTrackingService.php`, `ComplianceMatrixService.php`.
- `app/Services/ShiftStaffEligibilityService.php`, `ShiftCoverageService.php`, `CoverageRoleService.php`; `app/Services/Eligibility/Rules/`; `app/Jobs/RecalculateFutureShiftEligibility.php`; `routes/console.php`.
- `app/Services/Sites/Profile/SiteProfilePeoplePresenter.php`; `app/Http/Controllers/Sites/SiteComplianceController.php`; `app/Http/Controllers/Operations/QualificationMatchController.php`.
- `app/Services/HealthSafety/HsTrainingComplianceService.php`, `WorkplaceInjuryJourneyService.php`, `LoneWorkerSessionAccessService.php`; `app/Models/ModifiedDuty.php`.
- `app/Services/Respite/RespiteShiftSync.php`; `app/Domain/Clinical/Services/ClinicalProtocolService.php`; `app/Http/Controllers/Clinical/ShiftClinicalController.php`.
- `app/Services/Fleet/ResidentTransportJourneyService.php`; `app/Domain/It/Services/ItTicketBookingService.php`; `app/Services/WorkCalendar/WorkCalendarSyncService.php`; `app/Services/Tracking/PeopleLocationService.php`.
- `app/Services/Operations/TimesheetHrSyncService.php`, `BillingService.php`; `app/Domain/Finance/Services/PayrollCostAllocationService.php`; `app/Services/Tasks/Providers/`.

Deliver access/action correctness and trustworthy date/metric fixes first. Add leave/cover receipts, future eligibility and mapped restrictions before redesigning broad navigation. Then connect house/person/resource entry points, complete claims and cross-domain duties, and add planned Control Room staffing. Each package extends existing tests and must preserve its original feature inventory. Use an isolated seeded environment for writes and notification fakes; this audit's shared preview remains untouched.
