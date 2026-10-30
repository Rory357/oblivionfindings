# Evidence, coverage and verification limits

Audit date: 5 October 2026, Pacific/Auckland. This document distinguishes what was inspected, what was observed, what was reproduced and what remains untested.

## Environment and safety of the audit

| Item | Recorded state |
| --- | --- |
| Source workspace | `C:\Users\steph\Herd\oblivionfindings` |
| Source branch/HEAD | `main` / `3710147631295c795c6be7a919856eedf393dc2f` |
| Existing work | Untracked eMAR audit/completion documents and Fleet & Assets audit outputs/logs were already present and left untouched |
| Browser target | Existing `http://127.0.0.1:8765` synthetic eMAR review preview |
| Browser identity | Demo Admin; existing authenticated session, no role changes |
| Browser handling | Separate agent-created hidden tab; user tabs left alone |
| Browser viewport | Existing in-app desktop viewport; a displayed screenshot was approximately 1012 × 569. No claim of 1280/1440/1920, zoom or mobile verification |
| Preview checkout | `C:\Users\steph\.codex\worktrees\emar-completion-integration\oblivionfindings`, read-only comparison only |
| Preview restrictions | No build, server restart, checkout/reset, commit, database edit or fixture creation there |
| Submissions | None: no saved shift, claim, clock action, handover, note, payroll action, notification preference or publication |
| Files produced | Only this new dated audit directory; no application, permission, migration, design-guide or configuration changes |
| Coordination | No messages sent to the originating eMAR chat or any other task; no subagents used |

The audit is not a deployment or a production acceptance certificate.

## Expanded sidebar coverage

The complete expanded navigation exposed **137 links to 132 distinct destinations**. Every visible destination was followed read-only across this turn's initial and expanded passes. The final comparison of observed sidebar links against checked destinations returned **no unaccounted links**. Duplicate report and vendor links share their canonical page. The [module status register](module-status.md) names every visible entry, its Workforce connection, observed state and proposed improvement.

| Group | Visible sidebar links | Evidence depth |
| --- | ---: | --- |
| My Day / Overview / My Calendar / All Tasks | 4 | Landing pages; initial My Day/shift journey inspection; task/attendance/provider source |
| Sites & Locations | 11 | Every landing/filter; house detail People/Shift Coverage; coverage/requirement/stay source |
| Operations | 21 | Every landing; person/shift integration source; qualification failure and query trace; billing/location/calendar boundaries |
| Workforce | 9 | Every page; selected roster sections, shift/detail/dialog/attendance/pay/handover paths; deep source audit |
| Medication | 7 | Read-only landing and presence/competency messaging; canonical eligibility boundary in source; independent eMAR work untouched |
| Health & Clinical | 1 | Landing; shift protocol reader and task helper source |
| Health & Safety | 21 | Every register landing; training, RTW, lone-worker, coverage and assurance source |
| Fleet & Assets | 8 | Every hub landing; transport/shift graph, readiness/holds and resource-source boundaries |
| IT & Support | 10 | Every landing including redirect; settled reports; two-way availability source trace |
| HR | 18 | Every visible landing; leave/projection/cover, employment, compliance, attendance/payroll source |
| Finance | 8 | Every hub/redirect landing; approved-time billing/payroll allocation source, no financial action |
| Reporting | 6 | Every visible link, including reused module reports and builder |
| Control Room | 12 | Every landing; duty/handover source boundary from original pass |
| Settings | 1 | Profile destination, visible settings areas and calendar-sync page; no settings changed |
| Security & Devices | 0 child links | Header visible; direct module root returned 403; source navigation and integration boundaries inventoried |

This counts entry checks, not 132 complete lifecycle test passes. Most non-Workforce fixture registers were empty. Internal tabs, record details, restricted roles, background jobs, concurrency, downloads and mutations were not exhaustively executed. Hidden conditional HR/Governance entries are explicitly source-only, not included as browser passes. The module architecture evaluates their role in Workforce, not their entire production readiness.

### Material observations from the expanded pass

- **House → People → Shift Coverage:** actual demand editor and “Open Rostering” link; fixture had no windows. Source uses ShiftCoverageService, while the separate Staff Requirements register lacks an inspected canonical eligibility consumer.
- **HR Leave/Offboarding:** empty UI fixtures prevented live approval/cancellation verification. Source tracing established existing projection/ledger/cover creation, cancellation and approved-date resync; therefore the proposal extends those services rather than claiming they are absent.
- **Operations Qualifications:** database ambiguity error on landing; main/preview controller hashes match. No attempt to alter its database/query was made.
- **HR Calendar and Meal Planner:** visible menu entries returned 403 for Demo Admin. Record as preview access/entry mismatch; effective-role diagnosis and regression test remain required. No permission bypass.
- **Security & Devices:** root 403 and no accessible child links. Control Room map/device pages explicitly withheld identity/location/registry details, which is a useful boundary to retain.
- **Local period:** Operations displayed Sun 4 Oct during local Mon 5 Oct; Checklists showed week 28 Sept–4 Oct. This corroborates the need for shared worker-local periods beyond the roster alone.
- **Unsupported assurance:** empty checklists/training/wellbeing screens displayed 100% or success language; drills simultaneously showed sites overdue and “current”; static lone-worker/privacy assurance labels were confirmed in source. These observations do not establish actual legal compliance or noncompliance.
- **IT Work planning:** redirects to `/it?tab=tickets&view=mine`. Fleet Maps redirects to `/fleet-assets/geofences?tab=map`. Finance hubs redirect to their first permitted page. Preserve legitimate canonical redirects; improve confusing labels rather than assuming every redirect is broken.
- **Deferred views:** People Locations settled to a timestamped governed map with no permitted matching people; IT Reports settled to scoped results and an unverified watchdog. Site Calendar settled to a zero-entry calendar. Initial loading snapshots are not treated as persistent failures. My Calendar's settled event contents were not independently certified.
- **Medication:** reading and recording authority were visibly distinct; Meds today explained missing covering clocked-in duty, and Settings showed unconfigured on-call contacts. No medication interaction was submitted.
- **Control Room:** actual operator duty start/handovers are separate from future staffing; no session was started and no message/broadcast was sent.
- **Finance/Payroll:** hub pages/empty runs were reviewed; no export, payment, journal, integration connection or financial approval was executed.

No personal credentials were revealed, no report was generated/downloaded, and no application data was intentionally modified by this read-only audit. Ordinary page requests may produce standard access logs; the audit makes no claim to suppress those.

### Preview/source comparison

Read-only file comparisons were performed between main and the preview checkout:

| File | Result |
| --- | --- |
| `app/Http/Controllers/Operations/QualificationMatchController.php` | Same; added during expanded sidebar pass. SHA256 `49E11C65510B6E577CF386D4414A2C6C8179896911842ABCF4A6970BC1EDE8AB` |
| `resources/js/components/rostering/calendar-pane.tsx` | Same |
| `app/Http/Controllers/RosteringController.php` | Same |
| `app/Http/Controllers/CalendarController.php` | Same |
| `resources/js/pages/operations/shifts/index.tsx` | Same |
| `resources/js/pages/attendance/index.tsx` | Same |
| `resources/js/pages/operations/timesheets/index.tsx` | Same |
| `resources/js/pages/operations/job-board/Index.tsx` | Same |
| `app/Services/ShiftStaffEligibilityService.php` | Same |
| `resources/js/pages/operations/rostering/index.tsx` | Only nested `main` opening/closing tags changed to `div` in preview |

This confirms these source comparisons, not the identity of every served compiled asset. My Day and other eMAR-dependent browser content are observations of that preview, not proof of identical main behaviour. The nested-landmark fix in the preview must not be overwritten by later integration.

## Read-only browser journeys

The following are observed text/state evidence from the live UI. Data is synthetic. Fixture conflicts, absent availability and missed clock-outs are not themselves classified as product defects.

| Journey | Observation | Finding/use |
| --- | --- | --- |
| Workforce sidebar | Nine entries: Shifts, Job Board, Rostering, Availability, Handovers, Shift Notes, Timesheets, Attendance, Conflict Queue | WF-25; preservation map |
| Rostering initial view | 28 Sep–4 Oct 2026, 31 shifts, 100% coverage, 10 conflicts; signal rail also 11 client overlaps and 4 timesheets pending | WF-07, WF-15–16 |
| Capacity rail | Demo Admin displayed 0h despite visible assigned 12-hour shifts | WF-08 |
| Calendar tab | October 2026 loaded 30 shifts and 280.4 hours; initial metrics appeared as zero during loading | WF-28; values are fixture observations, not expected future totals |
| Calendar next month | November body with original weekly header/signal scope; tab URL did not update | WF-13, WF-17 |
| Global New shift from November | Six-step wizard opened with 5 October 09:00–17:00 defaults; native date-time fields; cancelled without saving | WF-13–14, WF-24 |
| Job Board | For you/all open/pending approval/my claims/replacements; zero available records; “Your compliance is current” and “refreshed just now” | WF-16; action inventory; no claim/approval executed |
| Attendance | Monday 5 October, week 41 (5–11 Oct), own sessions/on-clock/handovers; nine likely missed clock-outs in fixtures | WF-07 comparison; no clock action executed |
| Timesheets | Week 40; four rows: one draft, two submitted, one returned; summary two awaiting approval; payroll closes 09 Oct | WF-16; no pay action executed |
| Handovers | Week 41, Cards/List/Board, drafts/signoff/acknowledgement controls; empty synthetic scope | Header/navigation and state inventory; submission/acknowledgement untested |
| Shift Notes | Week 41, zero notes, one missing-note indicator while “All reviewed”/“All caught up” displayed; filters, export, Cards/List | WF-16, WF-21; no export or note mutation |
| Availability | Twenty-five body entries versus twenty-four in header staff picker; administrative roles included; “No data”; different week ordering | WF-18–19; no availability edited |
| Conflict queue | Twenty-one blocking (10 staff, 11 client overlaps); client label “Two staff rostered at once”; one site derived from displayed conflicts | WF-02, WF-17; no resolve action submitted |
| Control Room shifts | “No active shift”, Start New Shift, live-desk/accepted-handover description; independent operational rail | WF-27; no duty session started |
| Shifts index | 5–11 Oct, one synthetic eight-hour shift; list/calendar, status/site/staff/person filters, Add to day, density | Full-list parity requirements; week comparison |
| Shift quick view | Who/where, schedule, Open full view, Edit and Start shift | Detail navigation verified; no lifecycle action |
| Shift full detail | Auckland timezone, task/medication counters; Tasks, Notes, Audit history, Incidents, Medications, Assignment, Observations, Forms, Transport, Replacement; My Day guidance | Full detail function inventory; dependent care actions untested |
| My Day | Admin's own “No rostered shift” state; Today/Handover/My shift; clock/roster/care links and other assigned work | Canonical worker-home integration observed; not a worker-role journey |

Calendar console inspection returned no errors at the inspected moment. This is not a complete console/network or performance audit. Browser screenshots were inspected inline; no standalone screenshot evidence files are claimed.

## Source coverage and disposition

| Area | Inspected source/contract | Disposition |
| --- | --- | --- |
| Routes and sidebar | Operations routes, relevant web/shifts/HR/Control Room routes; existing page definitions/navigation | Entry points and alternate paths mapped; no route-list command executed without dependencies |
| Rostering all ten sections | Shifts, Calendar, Open, Coverage, Time off, Availability, Capacity, Analytics, Templates, Recurring | Source inventory complete at section level; Calendar/Availability/main and conflict browser paths checked; other stateful pane actions untested |
| Creation and editing | Shift controller, calendar controller, create/edit dialog, eligibility, tasks/coverage links | Scope, time handling, action parity and stale model findings; no successful save test |
| Shift detail/lifecycle | Detail page, lifecycle/guard/model references, relevant route/service/test inventory | Existing functionality recorded; start/complete/cancel/reopen concurrency not executed |
| Eligibility and HR | Eligibility service/rules, staff/site service, leave/availability, employee-profile identity, onboarding/competency paths | Reuse recommended; no assertion every rule/configuration was exercised |
| Templates and recurrence | Template controller, requests, roster payload builders, series presenter/controller | Existing apply/eligibility/transaction features preserved; read/cancel boundary issues recorded |
| Publication and suggestions | Feature flag declarations, review/diff routes/pages, publishing validator and controller paths | Existing but browser mutation/flag-on journeys not verified; keep in scope |
| Open work and replacements | Job Board routes/controller/hero, replacement service, conflict rejection path | Current claim/approve exists; withdrawal/rejection/multiple-interest gap documented |
| Attendance | Index UI, routes, clock/handover integration and existing service/test inventory | Read-only page checked; actual sessions, blockers, corrections, reconciliation require isolated tests |
| Timesheets/payroll/HR | Controller, model, index/hero, adjustment queue, HR sync identity tests | Scope/count/profile-link findings; existing approval/snapshot/lock architecture retained |
| Handovers | Index/detail UI, canonical handover services/permissions and My Day incoming/follow-up references | Care/operational separation retained; real recipient/acknowledgement flows untested |
| Shift Notes | Controller, note model scope, index filters/export, privacy form | Current general auth exists; site/privacy/export/limit issues recorded |
| My Day/tasks/eMAR | MyTasksController active-site/permission/handover/clock references, action routes and UI | Integration boundary reviewed; no independent eMAR audit or modifications |
| Sites/locations | Canonical client/site/service context and approved-site helpers | No invented multi-organisation architecture; selectors/queries must share IDs and scopes |
| Notifications/reports | Calendar notification path, shift/claim/replacement links, report URLs, feature-flagged audit/history | Specific stale-notification risk found; delivery/queue transport and all report exports not exercised |
| Control Room | Shift controller/model/page and handover service/test inventory | Current operator-duty function distinguished from future rostering; operational redesign excluded |
| Rory design | DESIGN, current PageHeader/navigation/calendar/popup/list/loader contracts | Current rules take precedence over old PageHero advice; no guides changed |
| Empty/error/loading/accessibility | Inspected source paths and observed empty/loading pages/dialog | Specific shortcomings recorded; failed network, keyboard sweep, zoom and screen-reader verification still required |

### Historic audit findings reconciled

- `docs/workforce-nav-audit-fix-plan.md`: the historical absence of Shift Notes general authentication is **not repeated** as current; `ShiftNoteController` now checks `shifts.viewAny`. New scope/privacy findings concern what that authorisation does not cover.
- Historical Job Board access mismatch: the current route/controller permits the intended assigned-worker access alternatives; do not reintroduce an old fixed finding.
- `docs/workforce-outstanding-handoff-2026-06-10.md`: preserve completed recurrence, template, publication and audit work. The older PageHero visual recommendation is superseded by the current PageHeader contract.
- Existing choices retained: `/my-day` as worker home; `/operations` URL compatibility; HR owns leave; no invented universal shift-note requirement.
- Existing test files are evidence of intended behaviour and useful regression coverage, **not evidence that they passed in this audit**.

## Checks actually performed

1. Read-only branch/status/HEAD inspection, targeted source searches and source reading.
2. Nine-file preview/main comparison and focused diff of the differing file.
3. Read-only browser journeys in the table above; opening and cancelling the shift wizard; no persisted application mutation.
4. Carbon version/source check supporting WF-08. The locked version is 3.11.4; its signed-difference behaviour is documented in the linked upstream source in the finding.
5. Isolated JavaScript reproduction of the **algorithm shape** in WF-09:

   - Input: A 09:00–17:00; B 10:00–11:00; C 12:00–13:00.
   - Adjacent-only algorithm: A/B.
   - All-pairs interval comparison: A/B and A/C.
   - Missing conflict: A/C.
   - This did not execute Laravel or query application data.

No PHP, frontend unit, browser automation suite, build or lint success is claimed. In this workspace `vendor/autoload.php` and `node_modules/vitest/vitest.mjs` are absent. PHP is not available through the ordinary shell path; an attempted installed-runtime access was permission denied. No escalation or automatic approval-review rejection occurred. The test configuration names persistent MySQL `oblivion_findings_codex_test` and uses RefreshDatabase. Tests were not pointed at that database or at the active eMAR environment.

## Required acceptance matrix for implementation

Every row below is **planned verification**, not an audit pass.

The fourteen scenarios in [cross-module-architecture.md](cross-module-architecture.md) extend this matrix and are mandatory for the affected implementation packages. In particular: HR leave approval authority from roster entry; source-owned cover reconciliation; future credential expiry/unmapped requirements; approved work restrictions; house demand changes; IT/care reciprocal booking; multi-day respite; transport disruption; clinical occurrence ownership; and independent operator staffing. Existing relevant tests include `tests/Feature/Hr/WorkforceAvailabilityLifecycleTest.php`, `LeaveProjectionSyncTest.php`, `tests/Feature/HealthSafety/HsTrainingEligibilityTest.php`, the Respite integrity/scope suites, Clinical protocol suites and `tests/Feature/FleetAssets/ResidentTransportJourneySecurityTest.php`. Their existence is not a pass result.

| Scenario | Required outcome | Useful existing test families to extend |
| --- | --- | --- |
| Worker/house coordinator/view-only/payroll/Control Room-only permissions | Correct navigation, payloads and direct-object denial across two houses and a facility | Site-boundary tests, Rostering route ownership, Control Room scope tests |
| Planned 2:1 plus genuine double booking | Correct support requirement shown; worker overlap still blocks/needs action appropriately | ShiftConflictServiceTest, ShiftCoverageServiceTest, roster publication validator tests |
| Nested overlaps and touching boundaries | Complete pairs, no duplicates, no false adjacency conflict | ShiftConflictServiceTest; operations-rostering-conflicts e2e |
| Overnight/week boundary/exclusive midnight | Same shift displayed on each intersected day; no double-counted hours | Calendar tab tests, shift lifecycle/date tests |
| NZ DST and foreign laptop timezone | Intended local schedule and actual elapsed duration preserved | Date helpers, availability eligibility, calendar create/edit tests |
| Sleepover/on-call/awake-night/break/travel | Existing type, rate segmentation and worked-time semantics preserved | ShiftRateSegmenterTest, TimesheetSafetyGuardsTest, AttendanceBreakTest |
| Medication competency/expired driving/training/onboarding | Clear current eligibility reasons; assignment rechecks and overrides retain audit | MedicationCompetencyEligibilityTest, DriverLicenceEligibilityWarningTest, ShiftStaffEligibilityServiceTest |
| Template apply with overlap/leave/locked occurrence | Preview and server decision agree; safe atomic result and no duplicates | TemplateApplyTest, ShiftSeriesEligibilitySamplerTest |
| Recurrence edit/cancel/reopen/exception | Correct occurrence scope, permission and history; approved-pay locks retained | RecurringSeriesTabTest, cancellation/lifecycle contract tests |
| Publish/republish/diff/unpublish, flags on/off | Correct period/site, warnings/blocks, recipient notifications and audit | RosterPublishingTest, RosterPublishAuditTest, publication integration tests, republish e2e |
| Suggestions queued/fail/accept/dismiss/apply twice | Human review, fresh eligibility, idempotent apply and useful progress/error | SuggestionEndToEndTest, SuggestionApplierTest, SuggestionAuditTrailTest |
| Claim/withdraw/reject/approve/race/expiry | One canonical assignee, clear outcomes, safe details and correct recipients | Job Board readiness/feature tests; replacement service tests |
| Worker calls in sick and replacement covers | Original responsibility retained until approval; handover/task ownership remains consistent | ShiftReplacementServiceTest, shift signal pipeline tests |
| My Day clock in → care/tasks → handover → clock out | Correct people/site, task and medication access; draft time record once | MyDayActiveSiteTest, pre-shift/person-handover tests, lifecycle e2e |
| Missed clock-out/correction/early finish | Reasoned correction, blockers visible, no unsafe completion or pay rewrite | AttendanceAdminEndSessionTest, AttendanceCorrectSessionTest, clock-out blocker tests |
| Submit/return/resubmit/approve/amend/payroll processing | Clear states, idempotent HR sync, immutable original, authorised scoped adjustment | TimesheetAmendmentWorkflowTest, TimesheetApprovalWritePathTest, TimesheetHrIdentityTest |
| Private note and late note export | Only authorised readers/exporters; list/export same effective week and filters | Add targeted Shift Notes privacy/filter/export coverage |
| Care handover saved/interrupted/submitted/acknowledged | Draft recovery, correct recipient, safe follow-up and durable history | MyDayHandoverDigestTest, MyDayHandoverFollowUpTest, handover access tests |
| Planned Control Room staffing → actual duty → accepted handover | Independent permissions and models, no premature start, stale handover protections | ControlRoomShiftControllerTest, ShiftHandoverAcceptance/Scope/Stale tests |
| Notifications and old deep links | Correct allowed recipients, no duplicate alert, direct arrival rechecks access | ShiftEligibilityNotificationRecipientPrivacyTest, legacy redirect tests |
| Failed load/stale edit/duplicate click/rapid filters | Honest error, retained context/input, safe retry and no phantom success | Focused component/integration tests for changed flows |
| Desktop 1280/1440/1920 and 200% zoom, keyboard | Readable header/rail, visible primary action, focus order, reachable overflow and no drag-only action | Existing rostering a11y/performance e2e, My Day desktop tests |

Medication/care acceptance uses the existing canonical interfaces and permission rules. It must be coordinated through the eventual integration process without altering the separate eMAR work during this audit.

## Readiness disposition

The written deliverables were checked for local Markdown companion links and explicitly named source-file paths; none were missing. The finding register contains 39 numbered findings (WF-01–39). Git inspection showed no tracked application changes; only the new audit directory was added by this work, alongside the user's pre-existing untracked audit outputs. This document consistency check is not an application test.

There is a strong reusable foundation, but P1 findings and the unexecuted isolated role/lifecycle/concurrency checks prevent a daily-use-ready verdict. The audit has completed its source/read-only assessment and planning deliverables. Implementation completion requires evidence against the parity register and the acceptance matrix; it cannot be inferred from this report or from the existence of tests.
