# Workforce improvement sequence

Implementation has not started. This plan improves the existing single-organisation supported-living application, preserves working functionality, follows Rory's current design contracts, and keeps Control Room independent. See [findings](audit.md), [all sidebar module states](module-status.md), [cross-module architecture](cross-module-architecture.md), [navigation/parity register](navigation.md) and [verification](verification.md).

Do not combine this work with the independent eMAR checkout, preview, database or branch. Do not change design guides, add tenant boundaries, migrate legacy organisation columns, rename every route or replace the working shift/pay/care services.

## Delivery order

The expanded module audit adds mandatory packages A–E below. Keep the original numbered packages as stable references: begin 0–5, include A–C before broad navigation rollout, integrate D–E with 6–9, and use 10 as the final cross-module release gate. These are small reviewable increments, not one large rewrite. Parallel subject areas still share one tested canonical assignment boundary.

### 0. Establish an isolated, reproducible baseline

**Purpose:** make changes and safety tests trustworthy without touching the active eMAR work.

- Use an appropriate isolated checkout and disposable database with safe notification sinks and an unused preview port. Record branch, commit, database identity, feature flags, fixtures and frontend build identity.
- Capture the current functional register before moving any navigation. Inventory exact action permissions and redirect behaviour, including feature-flagged publication and suggestions.
- Run the existing focused baseline suites only after confirming the database is disposable. Record existing failures separately.
- Seed supported-living examples: two houses and a facility, restricted coordinator, worker, read-only role, payroll reviewer, Control Room-only lead, planned 2:1 cover, overnight/sleepover/on-call shifts, unavailable/ineligible staff and locked pay records.

**Exit:** repeatable main-branch preview and isolated test execution; no dependency on port 8765 or the eMAR worktree. No roster migration yet.

### 1. Make conflict decisions real and supported-living aware

**Findings:** WF-01, WF-02, WF-09. **Primary files:** `rostering/conflicts.tsx`, conflict-queue hook/types, `RosteringController`, `ShiftConflictService`, `RosterPublishValidator`, canonical coverage/claim/leave services.

- Replace misleading local-success branches with actual canonical mutations, or explicitly incomplete/unavailable action states until the mutation exists.
- Persist operational acknowledgements separately from resolution, with stable IDs, actor, reason and timestamp. Re-fetch after a decision.
- Reuse complete interval detection. Distinguish staff double-booking from planned multi-worker support and insufficient coverage.
- Keep care requirement changes within existing authorised care/coverage ownership; a roster acknowledgement cannot amend a support agreement.

**Acceptance:** reload/second-session consistency; nested overlap and overnight tests; planned 2:1 versus unsafe double-booking; absent-ID failures; role/permission checks; existing coverage ack/dismiss/clear still work. Read-only roles cannot resolve by mutation.

### 2. Align access and action capabilities across alternate paths

**Findings:** WF-03–06, WF-12, WF-19–20, WF-30, WF-38. **Primary files:** Rostering/Calendar/StaffAvailability/StaffTimeOff/ShiftNote/ShiftSeries/Timesheet/QualificationMatch controllers, HR LeaveService/access service, relevant requests, `UserSiteAccessService`, route permission declarations, page capability payloads.

- Scope all alternate reads and direct object actions by approved sites, canonical ownership and privacy. Include counts, pickers, templates, recurrence, lazy payloads and exports.
- Correct payroll-adjustment scope and use a locked, idempotent processing operation with an accurate audit event.
- Separate read/create/edit/apply/delete/cancel/approve capabilities. Correct redirect destinations that require a broader permission than their source.
- Put leave approval authority in the shared command so roster self-service cannot auto-approve through availability permission. Repair the Qualifications join without removing its site/service-context scope.
- Preserve intentional broad access where explicitly authorised; document it instead of reusing unrelated page-view permission as bypass.

**Acceptance:** positive/negative role matrix, direct IDs across two sites, private notes, mixed-site templates/series, restricted payroll amendments, and UI/API agreement. Existing broad administrative workflows still function. No schema removal or hypothetical tenancy work.

### 3. Repair the shift mutation response and stale-edit behaviour

**Findings:** WF-10, relevant WF-04. **Primary files:** `CalendarController`, existing shift lifecycle/assignment services, notification service and calendar action handlers.

- Return the fresh locked model from calendar edits and calculate notifications from committed before/after values.
- Recheck state and applicable eligibility under the mutation's concurrency boundary. Protect against stale form and drag updates using the established versioning approach, or introduce a narrowly scoped version token if none exists.
- Keep task-reminder invalidation, timeline sync, coverage reservation fulfil/release and approved-timesheet locks.

**Acceptance:** concurrent edits, changed assignee, changed time, cancellation/approval racing an edit, rollback and retry. Verify correct notification recipient and one logical event; do not send live notifications while testing.

### 4. Unify dates, overnight intervals and trustworthy measures

**Findings:** WF-07–08, WF-11, WF-13–16, WF-18. **Primary files:** date helpers, Rostering/Calendar controllers, calendar/grid mappers, shift wizard, availability validation, metric presenters.

- Establish the selected-period contract in worker timezone with UTC query boundaries.
- Correct capacity arithmetic and daily/week clipping. Distinguish scheduled hours from paid hours and breaks.
- Render overnight continuations without duplicating identity or totals. Add explicit next-day availability support compatible with existing storage.
- Separate assignment fill from staffing requirements; correct approval counts and unknown/loading labels; obtain payroll dates from the actual configuration source.

**Acceptance:** Monday local/UTC boundary, year-crossing week, both NZ DST transitions, laptop in a different timezone, exact midnight, sleepover/awake-night/on-call, planned 2:1, no demand configured, zero results and overtime/capacity examples. Independently calculate expected times and totals.

### 5. Preserve navigation context and correct record links

**Findings:** WF-17, WF-21–22, WF-28. **Primary files:** Rostering state/router integration, conflict request/filter payload, Shift Notes list/export, Timesheet presenter/link, recurrence/availability redirects.

- Put supported section/view/date/site/staff/person/search state in validated URLs and retain return context.
- Use canonical IDs for sites and employee-profile URLs. Handle missing profiles safely.
- Make Shift Notes list/export share the authorised query and effective date; add honest pagination and total disclosure.
- Make lazy-data invalidation and stale-response handling explicit.

**Acceptance:** refresh/back/bookmark/deep link; duplicate site names; several site selections; late-written notes; more than 400 notes/100 series; rapid filter changes and failed requests. Existing publish review/diff context stays intact.

### 6. Consolidate the navigation with functional parity

**Findings:** WF-23, WF-25, WF-27. **Primary files:** sidebar/navigation definitions, shared PageHeader, Rostering shell and existing Workforce pages.

- Introduce the six Workforce destinations and six Rostering sections in [navigation.md](navigation.md), preserving old entry routes until parity and redirects pass.
- Bring the complete Shifts list into Roster. Keep resource planning grid and full shift detail; do not replace them with reduced cards.
- Move Availability and Conflict Queue into contextual in-page work. Keep Available shifts, Attendance, Timesheets, Handovers and Shift notes directly discoverable.
- Represent Control Room staffing using a capability-specific route/payload in the same shell, with an independent operational link. Do not broaden care-roster permissions to admit Control Room users.
- Migrate remaining PageHero headers to the current shared PageHeader. Preserve detail-page second-level sections, actions and linked metrics. Use the shared positional rail styling; no design-guide changes.

**Acceptance:** every preservation-register row has a reachable equivalent; old deep links resolve; no new 403 dead ends; authorised roles see their functions; Control Room-only users see no care payload; desktop widths/zoom and keyboard navigation verified.

### 7. Bring calendar and forms onto the shared design contracts

**Findings:** WF-13–14, WF-23–24, WF-28. **Primary files:** `CalendarPane`, shared `SiteCalendar` adapter, shift creator/editor, template dialogs, availability editor, existing reason/review components.

- Adapt conventional calendars to SiteCalendar, retaining the resource grid as a purposeful planning view with shared interval/date logic.
- Put create/edit workflows into the existing WizardShell while preserving field semantics, task details, recurrence, eligibility and per-step checks.
- Use searchable person/staff/site selectors, approved date and exact-minute time controls, work-timezone display, dirty-close guard and consistent review/success.
- Standardise loading/empty/error/retry/locked/stale states. Retain input on failure and provide visible alternatives to drag/context-menu actions.

**Acceptance:** create and edit each supported shift type, templates and availability; cancel/Escape/back, failed validation, stale save, double-click, keyboard-only and 200% zoom. Confirm the full existing form-field inventory before deleting old components.

### 8. Finish the open-work lifecycle

**Finding:** WF-26, WF-01 rejection path. **Primary files:** Job Board controller/routes/presenter, replacement service, position model, notifications and Available shifts UI.

- Add authorised worker withdrawal, coordinator reasoned rejection, reopening and expiry behaviour around the existing claim/approve mechanism.
- Preserve current assignments until replacement approval succeeds. Display claim, responsibility and handover state separately.
- Surface exact eligibility reasons and safe limited shift information before assignment; recheck eligibility at decision time.
- Make duplicate clicks, simultaneous claims, expired posts and permission changes understandable.

**Acceptance:** claim → approve; claim → withdraw → reopen; claim → reject → notify; replacement cancellation; two simultaneous claimants; expiry during decision; eligibility revoked after claim; safe recipient/content tests. One canonical assignment and one logical notification per outcome.

If the organisation needs several workers to express interest before selection, add a small application/interest relation to the existing open position with statuses such as interested/withdrawn/selected/not-selected/expired. Keep the existing immediate-claim mode explicitly selectable by policy. Show eligibility, hours/availability and relevant continuity context to the coordinator; retain human selection with a reason. Do not rank people using sensitive personal attributes or introduce a wage auction. Define notification and retention behaviour before release.

### 9. Add future Control Room staffing without moving its operations

**Finding:** WF-27. **Dependency:** packages 2, 4–6. **Primary owner:** existing Control Room staffing/duty services with Workforce presentation integration.

- First expose current permitted staffing/duty/history links coherently; this must preserve prepared handover, acceptance, versions and overrides.
- For forward planning, define a planned operator-duty interval, lead, team and link to the actual session. Reuse suitable staff availability checks without pretending operator duty is client care.
- Detect operator staffing overlaps and leave conflicts; make the transition from planned to active duty explicit and auditable.

**Acceptance:** a future assignment does not start live duty; starting the correct planned duty retains lead/team and history; outgoing/incoming handover remains in Control Room; care rosters and Control Room operational permissions remain independent. Define whether operators may concurrently hold other duties before overlap policy is enforced.

### 10. Verify the entire shift-to-care-to-pay journey and release incrementally

**Scope:** all numbered packages, mandatory cross-module packages A–E and [verification matrix](verification.md).

- Plan/assign/publish a supported-living shift; worker sees it on My Day; clock in, tasks/care/handover, break and clock out; review/submit/return/resubmit/approve the timesheet; follow the existing HR/payroll amendment path.
- Exercise replacements, cancellation, recurrence exceptions, care notifications and old deep links along that journey.
- Compare source/build identity, role results and old/new action parity. Run the existing targeted integration suites, then browser accessibility/layout/performance checks justified by the changes.
- Roll out in small reviewable changes with reversibility. Do not declare success from an admin happy path or visual inspection alone.

**Exit:** P1 findings corrected and verified; parity register complete; source change → affected shifts → decision → visible return outcome proven; date/site/context consistency proven; supported-living scenarios passed; evidence records exact environment and any remaining limitations. No unrelated eMAR, HR, Finance or Control Room redesign.

## Mandatory cross-module packages from the expanded audit

### A. Make leave, absence and offboarding a complete two-way journey

**Findings:** WF-30, WF-34. **Owner/source:** `LeaveService`, `HrLeaveAccessService`, `WorkforceAvailabilityCoverageService`, `HrLeaveRequestObserver`, employee lifecycle services, HR leave/detail UI and Rostering cover view.

- Preserve current ledger, StaffTimeOff projection, source-owned replacement/handover actions and independent replacement protection.
- Add a scoped presenter for all affected shifts and actual replacement/hand-over progress, with pagination, canonical IDs, owner and safe return links.
- Add the receipt to HR Leave, employee Offboarding and the corresponding Rostering source drawer. Do not confuse pending leave, approved absence and confirmed cover.
- Approval, date edits, cancellation and exit-date changes must reconcile both surfaces. Do not delete an already accepted replacement simply because leave was cancelled.

**Acceptance:** extend `tests/Feature/Hr/WorkforceAvailabilityLifecycleTest.php` and `LeaveProjectionSyncTest.php` with restricted-role alternate entry, >3 impacts, independent replacements, concurrent edits, current-shift relief and visible receipt outcomes. No real notifications.

### B. Align future eligibility, mapped requirements and authorised restrictions

**Findings:** WF-31–33. **Owner/source:** canonical eligibility stack, `HsTrainingComplianceService`, `ComplianceMatrixService`, SiteStaffRequirement, StaffQualificationRequirement, coverage roles and authorised RTW source.

- Define canonical requirement IDs, applicable role/site/person/service and effective intervals. Map existing descriptive requirements through reviewed configuration, preserving original data and unmapped status.
- Evaluate evidence across the relevant duty window, including overnight expiry and configured grace. Keep medication/driver canonical policies and exact override authority.
- Add a minimal approved work-restriction reader; no diagnoses, case text or inferred fitness decisions. Reconcile daily capacity across relevant duties under organisational policy.
- Expand configurable skill mix where required for supported living, including first aid or person-specific support skills. Keep role count separate from headcount and continuity preference.

**Acceptance:** extend `HsTrainingEligibilityTest`, `ShiftStaffEligibilityServiceTest`, medication/driver eligibility and coverage suites; add focused mapped-requirement and effective-restriction cases. Same result through create/calendar/template/series/claim/publish. Unknown mapping must not silently pass.

### C. Recheck changed sources promptly and show truthful status

**Findings:** WF-35, WF-37; reinforces WF-01, WF-15–16, WF-28. **Owner/source:** source services/observers, existing job/signal/outbox patterns, `RecalculateFutureShiftEligibility`, scoped metric presenters.

- Dispatch targeted after-commit impact evaluation for changed requirements, competence, employment/site approval and restrictions. Use source/version identity, visible failure/retry and current permission checks.
- Retain nightly reconciliation as fallback; define treatment of imminent, in-progress and beyond-14-day published work. Never silently unassign an active worker.
- Derive exception resolution from actual source correction or replacement. Acknowledgement, notification and resolution are separate facts.
- Replace unsupported all-clear/100%/legal-assurance badges with evidence-based states and meaningful denominators, starting with staffing-facing H&S/HR/house summaries.

**Acceptance:** renewal/revocation, queue failure/retry, duplicate/out-of-order events, scope change, no-data and stale-data scenarios. Exact affected duties and authorised recipients; no duplicate incident or cover lifecycle.

### D. Complete house/person/resource entry points and shared duty conflicts

**Findings:** WF-17, WF-29, WF-32, WF-36, WF-39. **Owner/source:** site People presenters/coverage editor, client profile, clinical shift reader, RespiteShiftSync, IT booking service, Fleet transport/readiness and Workforce period/context contract.

- Add house/person/employee/date/source-preserving entry and return links as defined in the architecture table. Reuse canonical editors rather than embedding duplicate forms.
- Preview staffing impact of effective house/plan changes and respite extension/transfer/discharge; preserve stay versus employee-duty semantics and completed history.
- Add shared committed-work intervals for IT and later operator duties; make conflict policy reciprocal, avoid double counting, preserve private busy details and configured buffers.
- Show transport/resource disruptions alongside affected staff decisions while preserving Fleet hold/release authority. Keep linked observation/dose tasks distinct by source occurrence.
- Correct visible-but-forbidden HR Calendar/Meal Planner navigation only after tracing effective route capability in the main baseline; do not grant permissions to fix a link.

**Acceptance:** house demand update; planned 2:1; person move; multi-day respite and extension; repeated clinical occurrences; care↔IT booking; vehicle hold/replacement; contextual back/refresh; permission loss during action. Existing domain journeys remain intact.

### E. Reconcile downstream time, care, money, tasks and reporting

**Owner/source:** AttendanceService, TimesheetHrSyncService, BillingService, payroll allocation, shared task providers, calendar sync and report definitions.

- Preserve one attendance record and approved immutable financial sources. Show scheduled, actually worked, approved, amended, exported and posted values distinctly.
- Project actionable cover items into All Tasks from canonical source records. Completing a generic task cannot approve HR leave or certify a clinical/safety action.
- Keep family/external calendar views redacted and updated after publication/assignment changes; expose integration failure rather than assuming delivery.
- Document measure definitions and scoped drill-throughs shared by Workforce, Overview, Operations, Sites, HR, H&S and Finance. Reports must show missing/limited evidence.

**Acceptance:** the complete shift-to-care-to-pay journey in package 10; locked-pay correction; duplicate approval/retry; source-permission change during export; cancelled roster external event; receiving worker's care/task handover and authorised reporting.

## High-value follow-on improvements

After the mandatory integration work is dependable, deepen these capabilities rather than treating A–E as optional:

1. **House coverage planning:** demand by time window, required skills, handover overlap and authorised support ratios in one view. Reuse coverage rules, not a second demand system.
2. **Continuity of support:** explain familiar-worker suggestions using authorised care/team history; coordinator can inspect the reason and choose differently.
3. **Coordinator workload:** one scoped exception list with owner, age and next action; no duplicate notifications for the same cover problem.
4. **Fair access to available work:** clear interest deadline, status history and decision reasons; preserve exact eligibility/privacy boundaries.
5. **Template exceptions:** preview absences, changed care needs, public holidays and locked occurrences before applying a pattern. Preserve existing recurrence history.

Each should ship only with a concrete user journey, canonical data owner, permissions, empty/error states and acceptance evidence. Assistive scheduling remains human reviewed; it does not compensate for inaccurate cover or eligibility data.

## Product decisions to resolve during implementation

These are genuine policy/configuration dependencies, not assumptions made by the audit:

- Which canonical care/coverage record authorises each person's ratio and shared-house staffing requirement, and who may change it?
- What are the actual payroll closing dates and how are corrections reconciled externally?
- Which shifts require a note, and what is the approved private-note visibility rule across every consumer?
- Is available work first-claim, several expressions of interest, or explicitly configurable by shift category?
- What does a planned Control Room shift mean operationally, and can operator duty overlap other work?
- Which roles intentionally have organisation-wide visibility beyond their approved-site assignments?

Implementers should locate existing policy/configuration first, then obtain missing decisions with concrete behaviour ready to review. Do not silently invent these rules while polishing the interface.
