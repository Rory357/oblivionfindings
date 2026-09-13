# Workforce and rostering: deep audit prompt for a new session

Audit the Rostering / Workforce module in:
`C:\Users\steph\Herd\oblivionfindings`

**AUDIT ONLY. Do not implement fixes, add bidding, redesign pages, or change application code during this session.** I want a deep, evidence-backed assessment of the current module, everywhere it touches, missing capabilities, possible issues, and worthwhile improvements. Produce a practical roadmap for later implementation.

The target is: **ready for our organisation to rely on every day**, from planning safe coverage to workers completing shifts and receiving correct time/pay outcomes. Shift bidding similar to ShiftCare is a required capability to assess and plan.

## 1. Use our IT & Support audit as the reference

Read the pinned Codex task **“Audit IT support module”**, task ID `01a07f1e-21b2-7f93-be44-359345f9a8f0`, using the task-reading tools if available. Do not send it messages or resume its work.

Read these local outputs:

- `docs/audits/2026-09-08-it-support/audit.md`
- Relevant examples in `docs/audits/2026-09-08-it-support/evidence/`
- `docs/audits/2026-09-08-it-support/implementation-plan.md`, as a reference for the detail of the eventual handoff only. **Its implementation instructions do not apply to this audit.**

Match that audit's method and practical depth: actual browser journeys, source-backed handoff tracing, candid readiness assessment, explicit evidence limits, prioritised findings, useful feature ambition, and a dependency-ordered roadmap. Apply the method to Workforce; do not carry over IT-specific features or assumptions. The local reports are the fallback if the pinned task is unavailable.

## 2. Boundaries and working rules

- Follow `AGENTS.md` and `docs/architecture/single-tenant-application.md`. This is one operating organisation across multiple sites. Authorization uses roles, permissions, approved sites, canonical record ownership, direct-object denial and privacy. Do not propose tenant switching, cross-tenant workflows or tenant-based acceptance tests. Legacy `tenant_id` / `organization_id` fields are compatibility context; do not propagate or remove them during this work.
- **Web application only.** Phone-width checks mean responsive browser use. Do not propose a native mobile app, another worker home, a second app shell or a separate offline product. Keep `/my-day` as the canonical frontline home.
- Inspect branch, HEAD and working-tree changes first. Preserve all existing work, including the IT audit directory. Ignore stale `.claude/worktrees` copies when determining the current implementation.
- **Follow Rory's design rules exactly. Do not edit `DESIGN.md`, `design_styles/`, or create an override guide.** Recommend changes within the approved design.
- Writing a new dated audit report, evidence and planning documents is permitted. Application source, migrations, persistent configuration, permissions and existing audit reports must remain unchanged. Do not commit, push, merge or deploy.
- Use existing fixtures and, where needed, minimal disposable local records through normal workflows after confirming a local environment and safe notification sinks. Do not alter real rosters, approve real timesheets, export real payroll, send real emails/SMS, change integrations or expand permissions. Record any disposable records and their final state. If safe fixtures are unavailable, continue independent checks and mark dependent journeys blocked.
- Keep the depth focused on Workforce and its real dependency paths. Do not turn each connected module into another whole-module audit. Do not stop after merely listing routes or reading an old report.

## 3. Establish the live scope before forming findings

Start with `routes/operations.php`, then relevant parts of `routes/web.php`, `routes/shifts.php`, `routes/portal.php` and `routes/console.php`. Discover actual Workforce navigation and role-specific entry points from the current UI and `resources/js/components/app-sidebar.tsx`.

Starting surfaces include Rostering, Shifts, Job Board, My Roster, My Day, Availability/Time Off, Conflict Queue, Attendance, Handovers, Shift Notes and Timesheets. Confirm actual destinations, embedded tabs, permissions and legacy redirects. Do not assume the UI label “Workforce” requires renaming `/operations/*` URLs.

Use these verified starting points, then follow their real dependencies:

- `app/Http/Controllers/RosteringController.php`
- `app/Http/Controllers/RosterController.php`
- `app/Domain/Rostering/`, including publishing, snapshots, validation and auto-schedule suggestions
- `app/Http/Controllers/Operations/JobBoardController.php`
- `app/Models/ShiftOpenPosition.php`
- `app/Services/ShiftStaffEligibilityService.php`
- `app/Services/Eligibility/AssignmentEligibilityGateway.php`
- `app/Services/CoverageReservationService.php`
- `app/Services/ShiftReplacementService.php`
- `app/Services/ShiftCoverageService.php`
- `app/Domain/Hr/Services/WorkforceAvailabilityCoverageService.php`
- `resources/js/pages/operations/rostering/`, `resources/js/components/rostering/`
- `resources/js/pages/operations/job-board/Index.tsx`, `resources/js/components/job-board/`
- `resources/js/pages/my-roster/`, `resources/js/pages/my-day/`

At prompt preparation, the job board has claim and approval routes, and `ShiftOpenPosition` stores a single `claimed_by`. That is a starting observation, **not proof of complete bidding or a repository-wide proof that bidding is absent**. Trace all relevant models, migrations, services, routes, UI and tests before concluding what exists.

Read the earlier workforce material as historical context:

- `docs/rostering-frontline-end-to-end-audit.md`
- `docs/workforce-outstanding-handoff-2026-06-10.md`
- Relevant `docs/rostering-*-readiness-plan.md` files and `docs/workforce-nav-audit-fix-plan.md`

Revalidate old findings against current code and browser behaviour. Record whether they remain open, are resolved, have regressed, were withdrawn, or represent a documented product decision. Do not copy old “complete” claims as current evidence or reopen declined work without a concrete reason.

## 4. Mandatory design and browser work

Read `DESIGN.md` and the relevant `design_styles/` guides, particularly `PAGE_HEADER_STYLE_GUIDE.md`, `NAVIGATION_STYLE_GUIDE.md`, `LIST_STYLE_GUIDE.md`, `POPUP_STYLE_GUIDE.md` and `APP_SHELL_STYLE_GUIDE.md`. Apply the repository's frontline UX and browser-verification skills where available.

Use the **Codex in-app browser** to inspect and interact with:

- `https://oblivionfindings.test/sites` as the approved live design reference.
- `https://oblivionfindings.test/operations/rostering`
- `https://oblivionfindings.test/operations/job-board`
- `https://oblivionfindings.test/my-roster`
- `https://oblivionfindings.test/my-day`
- Other actual Workforce destinations reached from the UI.

Check the current browser session before asking for login help. Confirm the host, checkout and current assets; do not reuse the IT audit's old hashes. Use Codex browser navigation, interactions and screenshots, not terminal-driven browser automation as a substitute. Code, HTTP responses and component tests cannot replace browser journeys. Diagnose access/host limitations without turning this into infrastructure repair.

Check scheduler/coordinator, restricted site manager and frontline worker journeys using existing accounts where possible. Include relevant HR/payroll boundaries. Admin success does not prove restricted-role correctness. Capture desktop and measured phone-width web layouts, approximately 390 × 844, with screenshots of important states. State what role, viewport, fixture and build each observation covers.

Give an early concise diagnosis after the first browser pass, then continue the full audit. Report blocked browser checks honestly and continue useful source/dependency work; do not call blocked journeys verified.

## 5. Trace the whole workforce journey

Follow:

**Demand and required coverage → availability and eligibility → roster planning → review and publication → worker visibility/acknowledgement → open-shift filling or bidding → assignment and last-minute changes → attendance and delivery → handover/notes → timesheet review → payroll/finance handoff → reporting and audit history.**

For each transition, identify the initiating person/system, canonical record, writer, authorization decision, resulting state, next owner, downstream consumers, notification and failure/recovery behaviour. Check both forward changes and cancellations/reversals.

Audit at least these areas:

1. **Demand and coverage:** site/client/service requirements, staffing ratios, required roles/skills, time-specific coverage, vacant versus assigned work, and whether counts reflect the actual selected scope. Distinguish acknowledged/dismissed coverage alerts from resolved shortages.
2. **Planning:** day/week/calendar views, dates, filtering, search, shift creation/editing, duplication, drag/drop, bulk actions, templates, recurring series and occurrence exceptions. Check preservation of client/site/task/competency metadata across every creation path.
3. **Eligibility and availability:** active employment/onboarding, approved sites, training/competency validity at shift time, medication competency, driver eligibility where relevant, availability, approved leave, overlaps, travel feasibility, rest/fatigue and hours constraints. Establish which rules are configured policy, hard stops, warnings or explicit overrides, and who may override with what record.
4. **Publication:** draft/published visibility, preflight review, warnings, publish/republish/unpublish, snapshots, differences, concurrent changes and worker notifications. Check what happens if publication partly fails or a published shift is subsequently edited.
5. **Worker experience:** My Roster/My Day agreement, understandable shift details and next action, acknowledgement/acceptance/decline where supported, changed-shift awareness, availability requests and recovery after interruption. A pending bid must not look like an assigned shift.
6. **Exceptions and cover:** sickness, no-show, cancellation, replacement requests, unassignment, swaps, partial cover, reassignment, urgent gaps, expiry and reopening. Establish who owns the unresolved gap and what remains visible until safe coverage is confirmed.
7. **Attendance and delivery:** clock-in/out, breaks, late arrival, early finish, overnight work, sleepovers/awake time where applicable, duplicate/stale sessions, corrections and shift completion. Trace effects on tasks, handovers, notes and recorded service delivery.
8. **Time and pay:** scheduled versus actual hours, allocations, overtime/allowances/travel where implemented, approvals, return/reject/correct cycles, payroll locks and exports. Check how roster edits, replacements and cancellations affect approved/exported records, including prevention of silent duplicate or altered pay inputs.
9. **Reliability:** assignment atomicity, concurrent scheduling and bidding, stale screens, duplicate submissions, retry/idempotency, reservations and expiry, queue/scheduler failures, stale auto-schedule suggestions, event ordering, cache invalidation and audit attribution.
10. **Operational visibility:** actionable conflict/approval queues, truthful coverage and fill-rate metrics, filters versus totals, refresh age, exports, exception reports, configuration readiness and ownership of failed automation.

Check New Zealand date/time presentation and `Pacific/Auckland` behaviour, including overnight shifts, Monday week boundaries and daylight-saving transitions. Discover actual employment/pay rules; do not invent legal limits or import Australian/US rules from ShiftCare. Verify any legal claims using current official sources and distinguish law, agreement, organisational policy and missing configuration.

## 6. Map everywhere Workforce touches

Produce a dependency map and a detailed touchpoint register. Search for both things Workforce calls and things that call, read or mutate Workforce records. Include controllers/services, events/listeners/jobs, scheduled commands, shared models, notifications, reports and exports; navigation links alone are insufficient.

Investigate these candidate connections, marking any unsupported connection as not found or unverified rather than inventing it:

- **HR/Staff:** employment state, onboarding, qualifications, compliance, leave, availability, hours and workforce analytics.
- **Sites:** access, service requirements, staffing levels, site status and coverage ownership.
- **Clients/Care/eMAR:** care requirements, client/service context, clinical competency, shift tasks, handovers and access before/after assignment.
- **Attendance/Timesheets/Payroll/Finance:** planned/actual time, allocation, approval locks, exports, costing and billing where a real dependency exists.
- **Portal/Respite/Bookings/Calendar:** demand creation, confirmation, changes and cancellations that affect staffing.
- **Fleet/Transport:** driver/vehicle requirements, bookings, travel and transport duties where linked to shifts.
- **Lone Worker/Safety/Incidents/Control Room:** on-duty status, missed attendance, unsafe coverage and urgent operational escalation. Decide which issues belong in immediate Control Room response and which remain in the normal staffing queue, with explicit criteria and no duplicate incident systems.
- **Notifications/Settings/Reports/Audit:** recipients, privacy, delivery status, preferences, feature flags, scheduled processing and operational evidence.

For each actual connection record: source → target; business purpose; owning module and canonical IDs; read/write direction; trigger and payload; permission/site/privacy rules; downstream effects of edits, cancellation and reassignment; duplicate/retry handling; failure owner and recovery; exact file references; browser/test evidence and remaining gap.

Identify conflicting sources of truth, bypass paths, orphaned records, duplicated UI/stores, stale derived data and circular automation. Recommend reuse of sound existing services. Explicitly identify which proposed changes belong in Workforce and which require a coordinated change in another owning module.

## 7. Required capability: shift bidding similar to ShiftCare

Treat “bidding” as workers expressing interest in available shifts and a coordinator choosing eligible applicants. Do not assume it means workers auctioning their pay rates. Assess optional instant claiming separately and record any policy decisions without blocking the rest of the audit.

Use a small number of current official ShiftCare sources. Start with [Managing the Job Board and Vacant Shifts](https://help.shiftcare.com/en/articles/11813017-managing-the-job-board-and-vacant-shifts), checked when this prompt was prepared on 9 September 2026. Its documented baseline includes manager-reviewed applications and automatic claiming, applicant withdrawal, selection outcomes, per-slot staffing, visibility criteria, waitlists and recurring occurrences. Recheck the source when auditing; distinguish verified vendor behaviour, Oblivion's present behaviour and your proposed requirements. Translate the useful capability into our responsive web application and architecture.

**Do not label the existing single-claim approval path “bidding complete” without demonstrating that multiple applicants can remain under consideration for the same vacancy.** Determine whether the existing design can be extended incrementally and identify the exact reuse points and limitations.

Produce a dedicated bidding gap assessment and proposed lifecycle, covering:

- Separate vacancy/publication, application and actual assignment states. Define permitted transitions, actors, deadlines and recovery; do not force all three lifecycles into one status field merely because the current model does so.
- Multiple interested workers for one position; one worker's application must not consume actual coverage or conceal the vacancy from others. Investigate whether present coverage reservations would incorrectly reserve a worker's time just for applying.
- Worker and coordinator journeys, including enough information to make an informed decision, clear pending/confirmed outcomes and a manageable review queue. Evaluate withdrawal, rejection, expiry, cancellation and reopening as complete workflows.
- Server-side eligibility when listing, applying and finally assigning. Recheck changes to employment, site approval, leave, qualifications and competing assignments between those moments. Keep sensitive client details and applicants' identities restricted to the appropriate actors.
- Assignment races: two coordinators choosing different people, the same person winning overlapping shifts, manual assignment while applications are open, double submission and stale approval after a shift changes. Define invariants and atomic outcomes, including losing/pending applications and reservation cleanup.
- Cardinality: distinguish multiple applicants from multiple required workers. Determine whether one shift can safely represent several staffed positions/roles and how the current `Shift.user_id`, coverage records and open-position model constrain that. Do not duplicate a client service or payable shift simply to collect bids.
- Material edits after application: changed time/site/client/required competency, partial cancellation and recurring-series changes. Define when an application remains valid, needs reconfirmation or must close.
- After award: the canonical roster, My Roster, My Day, coverage/conflicts and notifications agree; attendance and timesheets follow the actual assigned worker; unsuccessful applications create no attendance/pay entitlement or premature care-record access.
- Short-notice cover and fair allocation: identify operationally useful selection information, transparent criteria, who decides, and how a decision can be explained. Do not invent automated ranking or relax safety rules to copy a competitor.
- Audit and operations: actor/reason/timestamps, meaningful delivery states, failure recovery, retention/privacy and reporting on demand, applications, filling and cancellations with defined denominators.

Recommend a minimum complete bidding release and subsequent enhancements. For each, state the existing pieces to reuse, conceptual data/API/UI changes needed later, owning modules, unresolved policies and measurable acceptance. No schema changes or implementation now.

## 8. UI/UX is a primary acceptance criterion

Assess schedulers and frontline workers separately. Prioritise safe coverage, pay correctness, interruption recovery and clear next actions before visual polish.

Inspect approved headers, meters, filters, tabs, lists, dialogs and wizards; navigation continuity; calendar density; understandable status labels; accessible alternatives to drag/drop; keyboard/focus; touch targets; phone-width legibility; loading/empty/error/permission-denied states; validation beside the failed action; draft preservation; refresh and retry feedback.

Check for dead controls, duplicated destinations, inaccessible worker actions, manager-only controls leaking into worker views, misleading “covered/available/approved/saved/sent” claims and colour-only meaning. Verify that important numbers and actions use the same date/site/role scope.

For each concrete UX issue, recommend the intended behaviour, useful wording, CTA placement and existing component/workspace to extend. Preserve necessary manager detail. Do not equate more controls or another dashboard with improvement.

## 9. Evidence and targeted verification

Read existing tests as supporting evidence. Useful starting areas include `tests/Feature/Rostering/`, `tests/Feature/Operations/JobBoardControllerTest.php`, `tests/Feature/Operations/ShiftAssignmentAuthorizationConcurrencyTest.php`, relevant HR eligibility tests, and focused rostering/My Roster browser specs. Follow actual test paths at the current HEAD.

**Do not run the full suite, all PHP/frontend tests, broad end-to-end suites or repeated expensive builds.** Only run a small selected test/file when it directly resolves a material uncertainty, after verifying it uses isolated test data. Record commands and outcomes. Tests read are not tests run; local test success does not prove external delivery or production operations.

For every finding provide a stable ID, priority P0/P1/P2/P3, affected role/journey, impact, reproducible trigger or source trace, expected versus actual behaviour, file/line references, evidence, confidence/limitations, recommended correction and acceptance criterion. Separate observed failures, source-confirmed defects, suspected risks, configuration gaps, missing product capabilities and optional enhancements. Reproduce important claims where feasible and actively check plausible alternative explanations.

Use these inventory states:

- **Working:** the stated journey/slice was demonstrated, with limits recorded.
- **Partial:** meaningful implementation exists but a specified part of the journey is incomplete.
- **Missing:** no implementation found within the explicitly stated search scope.
- **Blocked:** access, fixtures or configuration prevented a specified check.
- **Unverified:** identified but not exercised sufficiently to reach a conclusion.

No unsupported completion percentages. Maintain a bounded coverage checklist with each discovered major surface, required journey and actual handoff marked reviewed, verified, blocked or unverified. A test name or route count is not a readiness score. Report measured performance only with the route, dataset, environment and method; otherwise describe a source-based risk.

## 10. Deliverables and completion gate

Create a new directory `docs/audits/YYYY-MM-DD-workforce-rostering/` using the audit date. If it already belongs to another session, use a distinct suffix. Keep durable outputs together:

1. **`audit.md`:** plain-English daily-use readiness judgement; feature inventory; prioritised findings; role-based UI/UX assessment; current-state limitations; and a concise account of what is already sound and should be retained.
2. **`touchpoints.md`:** end-to-end dependency diagram and detailed cross-module ownership/handoff register, including evidence of direct and background paths.
3. **`shift-bidding.md`:** verified ShiftCare comparison, current claim-versus-bidding assessment, proposed lifecycle and UI placement, reuse/data boundaries, policy decisions, minimum complete scope and acceptance scenarios.
4. **`roadmap.md`:** a substantial prioritised feature backlog and dependency-ordered future work packages. Separate daily-use blockers, essential completeness including bidding, high-value improvements and later ideas. For every proposal explain the beneficiary/problem, reuse versus new work, UI location, owner/dependencies, permission/data boundaries and a concrete end-to-end acceptance criterion. Include relevant files, risks and targeted verification. Clearly mark this as planning, with implementation not started.
5. **`verification.md` and `evidence/`:** checkout/build/role/viewport details, browser journeys and screenshots, focused tests actually run, safe fixture outcomes, unverified checks and external dependencies. Include the coverage checklist and dispositions of relevant old workforce findings.

Use diagrams and a readable companion visual where useful, without replacing the durable written evidence. Reference shared finding IDs across outputs to avoid inconsistent copies.

Include worthwhile features I have not explicitly named, grounded in real operational problems. Consider roster templates and exception handling, continuity of care, staffing-demand visibility, understandable matching suggestions, coordinator workload, fair access to open work and proactive exception handling. Assess assistive automation as a later option with human review and current data boundaries; it must not distract from complete basic workflows.

Before finishing, ensure every required area and discovered major handoff has a recorded disposition, significant findings have evidence and correction criteria, and the bidding work is specific enough for a later implementation session to act on. Summarise critical unknowns and business decisions without pretending they are resolved. Inspect the final working tree for unintended application/design changes.

Finish with a concise summary of readiness, the most consequential issues, key connected modules, the actual bidding gap, recommended implementation order and links to the reports. **Stop at audit and planning. Do not begin fixing anything.**
