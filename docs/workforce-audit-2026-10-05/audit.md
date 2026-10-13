# Workforce and Rostering audit — 5 October 2026

**Status: audit and implementation planning complete; implementation has not started.**

The application has substantial supported-living functionality worth retaining. It is not ready to be described as fully verified for daily rostering: several source-level correctness and access-boundary defects remain, and this review could not execute isolated end-to-end mutation tests. The recommended approach is incremental repair and navigation consolidation, with functional parity checked before any old entry point is removed.

This report prioritises Rostering and follows its connections across every sidebar module. The expanded pass followed 137 visible links (132 distinct destinations) and traced the principal two-way dependencies in source. It covers entry points, eligibility, demand, leave, employment, safety, care, resources, time, money and reporting. It is a desktop integration review, not a full write-path certification of every module. It does not propose a new worker home, a native app, a replacement care system or a Control Room merger.

**The user's decisions govern the plan:** preserve and improve functionality; make supported living easy to understand; keep Control Room independent; bring only Control Room rostering into the rostering scope. Desk operations, alerts, incidents, devices, messaging and operational handover remain in Control Room.

Companion documents:

- [Every sidebar module: state, connection and improvement](module-status.md)
- [Cross-module ownership, two-way change impacts and architecture](cross-module-architecture.md)
- [Navigation and function-preservation map](navigation.md)
- [Sequenced implementation plan](implementation-plan.md)
- [Evidence, coverage and verification limits](verification.md)

## Evidence and priority

Source baseline: `main`, commit `3710147631295c795c6be7a919856eedf393dc2f`, in `C:\Users\steph\Herd\oblivionfindings`. File references below refer to that baseline. Line numbers are evidence locators, not a claim that a runtime test passed.

Browser evidence came from the existing synthetic-data eMAR preview on `http://127.0.0.1:8765`, signed in as Demo Admin. This is a separate checkout, not a verified build of main. Ten relevant files were compared read-only: nine matched; the Rostering index differed only by replacing a nested `main` with `div`. The added Qualifications controller comparison matched exactly. Browser findings therefore corroborate source where stated, but do not certify the entire preview or main. No roster, clock, claim, handover, payroll or notification mutation was submitted.

- **P1:** correct before daily reliance or navigation rollout that exposes the affected action more widely.
- **P2:** essential usability, workflow consistency or completeness; deliver in the initial improvement sequence.
- **P3:** useful extension after the foundations work.
- **S:** source-backed finding. **B:** observed in the read-only browser. **R:** isolated algorithm reproduction, not an application test.

No P0 outage, production data loss or actual unauthorised disclosure was demonstrated. Access findings below identify missing or inconsistent enforcement in inspected paths; restricted-role exploitation was not tested.

## Ranked findings

### WF-01 · P1 · Conflict actions can report a change that was never saved [S]

`resources/js/pages/operations/rostering/conflicts.tsx:454` explicitly leaves reject-claim unwired and calls `resolveLocally`. Lines 497–504 report “2:1 exception approved” or “Set to 1:1 — overlap dropped” using the same local-only path. Missing-record fallback branches can also display successful approval/cancellation wording. `resources/js/components/rostering/conflict-queue/use-conflict-queue.ts:109` stores resolved IDs in component state; line 132 labels a session count `resolvedToday`.

**Impact:** a coordinator can believe cover, leave or care-ratio decisions are complete when the underlying records are unchanged. **Correction:** distinguish saved resolution, saved acknowledgement and an unfinished action. Persist real decisions with actor, reason and canonical record IDs; refresh the queue from the server. Missing IDs must produce a clear unavailable/error state. Preserve working server-backed coverage acknowledgements and dismissals. **Acceptance:** reload and a second session show the same outcome; rejection changes the claim; a ratio decision cannot claim success without an authorised, durable decision.

### WF-02 · P1 · Two-person support is presented as inherently blocking [S+B]

`resources/js/components/rostering/conflict-queue/types.ts:109` describes client overlap as a warning but sets `blocking: true`. The preview displayed 11 client overlaps as “Two staff rostered at once”, contributing to 21 blocking conflicts. `app/Services/ShiftConflictService.php` treats client overlap as a warning; `app/Domain/Rostering/RosterPublishValidator.php:105` also has a client-overlap warning path.

**Impact:** planned 2:1 support can be mistaken for unsafe duplicate allocation. **Correction:** compare planned cover with the person's authorised support requirements and site coverage rules, while keeping a staff member's simultaneous assignments a separate conflict. Show “Planned two-person support”, “Support requirement needs review” or “Staff member double-booked” as appropriate. An operational acknowledgement must not silently amend a care plan or service agreement. **Acceptance:** intentional 2:1 cover is identifiable; insufficient cover and genuine double-booking remain actionable; display and publication rules agree.

### WF-03 · P1 · Rostering reads do not consistently enforce approved-site boundaries [S]

`app/Http/Controllers/RosteringController.php:102` uses page-view/manage permissions as site bypass permissions. The main shift query and `conflicts()` at line 882 do not use the canonical shift scope consistently. `buildRosterTemplates()` at line 737 and `buildSeriesDetail()` at line 805 expose additional paths; `app/Services/Operations/ShiftSeriesPresenter.php:25` lists series without an actor/site argument. The conflict path is broader than the own-shift restriction used for non-managers on the index.

**Correction:** use the approved-site and record-ownership services for queries, pickers, counts, template contents, series lists and direct IDs. Reserve any organisation-wide bypass for an explicitly intended capability. Do not turn `rostering.viewAny` into permission to every house by accident. **Acceptance:** a coordinator approved for House A cannot retrieve House B through a direct series ID, conflict queue, template, count, filter or lazy payload. Legitimate organisation-wide roles retain their intended access.

### WF-04 · P1 · Calendar has a separate, weaker access path [S]

`app/Http/Controllers/CalendarController.php:39`, `:218` and `:350` use own-or-manage checks and existence validation without the same approved-site/canonical-record checks as the main Shift controller. A valid client or worker ID is not enough to establish authority to assign them at a house.

**Correction:** route calendar reads and mutations through the same scope, client/site relationship checks and assignment decision path as normal shift edits. Keep client-site identity canonical. **Acceptance:** the same actor receives the same denial through calendar JSON, drag/drop, create, ordinary edit and direct URL/API paths. Test with sites A/B and deliberately mismatched IDs.

### WF-05 · P1 · Shift Notes privacy wording exceeds inspected enforcement [S]

`app/Http/Controllers/Operations/ShiftNoteController.php:26`, `:173`, `:230` and `:251` enforce general permissions and legacy organisation context but do not consistently apply approved-site/private-note scope. The create catalogue also builds broad related-record lists. `resources/js/pages/operations/shift-notes/components/note-wizard.tsx:590` promises private notes are only visible to managers and senior staff. The inspected read path does not enforce that promise; client-note model inspection did not reveal a global privacy scope that supplies it.

**Correction:** reconcile this surface with the existing note-privacy policy, apply it to read/export/lookup/direct IDs and expose only eligible shifts and people. Do not invent a new privacy policy or infer that the family portal is affected without tracing it separately. **Acceptance:** private/non-private and own/other-site fixtures exercise read, edit, review and export for each relevant role.

### WF-06 · P1 · Payroll-adjustment queue omits the normal timesheet scope [S]

`app/Http/Controllers/TimesheetController.php:1392` fetches all approved outstanding payroll amendments without the scope used by the normal index. `markPayrollAdjustmentProcessed()` at line 1434 checks permission/status but does not authorise the amendment's canonical timesheet/site or lock a fresh record before changing `applied_at`.

**Correction:** reuse the timesheet action boundary for queue and direct amendment IDs; make processing atomic and idempotent with durable audit. Keep “processed externally” distinct from exporting or paying a worker. **Acceptance:** House A reviewers cannot see or mark House B adjustments; two simultaneous requests produce one logical processing event; normal approved-payroll locks remain intact.

### WF-07 · P1 · Default roster week can be wrong at the local week boundary [S+B]

`app/Http/Controllers/RosteringController.php:85` and `:889` derive the week with unqualified `now()`/`Carbon::parse()`. `config/app.php:99` uses UTC storage/application time; line 113 specifies Pacific/Auckland worker time. In the same preview session on Monday 5 October, Attendance, Shifts and Shift Notes showed 5–11 October, while Rostering showed 28 September–4 October.

**Correction:** derive dates in configured worker time, then convert interval boundaries to UTC for queries. Use one shared period contract across header, body, filters, reports and linked workflows. **Acceptance:** Monday before UTC reaches Monday, midnight, year-end and the DST weekends all produce the correct Auckland day/week and inclusive visible coverage.

### WF-08 · P1 · Capacity hours collapse to zero [S+B]

`app/Http/Controllers/RosteringController.php:285` calculates `max(0, $end->diffInMinutes($start))`. `composer.lock` pins Carbon 3.11.4, whose default difference is signed: an earlier target produces a negative result. See the [Carbon 3.11.4 implementation](https://raw.githubusercontent.com/briannesbitt/Carbon/3.11.4/src/Carbon/Traits/Difference.php). The preview showed Demo Admin at 0h despite visible assigned 12-hour shifts.

**Correction:** calculate forward elapsed time, clip to the selected reporting interval and clearly distinguish scheduled hours from paid hours/breaks. **Acceptance:** ordinary, overnight, cross-week and DST shifts give independently calculated totals; capacity rail and heatmap agree. This was source/library verified, not executed in PHP here.

### WF-09 · P1 · Conflict summaries miss nested overlaps [S+R]

`app/Http/Controllers/RosteringController.php:209` and `:920` compare only adjacent intervals after sorting by start. A 09:00–17:00 shift, 10:00–11:00 shift and 12:00–13:00 shift produce A/B but omit A/C. A small isolated reproduction confirmed this property. This finding concerns the overview/queue, not a claim that the canonical assignment guard uses this algorithm.

**Correction:** reuse the full overlap service or a correct interval sweep, with stable pair IDs and deduplication. **Acceptance:** nested, equal-start, touching-boundary and cross-midnight cases match canonical conflict results; no false conflict when one shift ends exactly as another starts.

### WF-10 · P1 · Calendar updates use stale state for the response and notification [S]

In `app/Http/Controllers/CalendarController.php:520`, the transaction replaces its captured local `$shift` with a freshly locked model. The outer model is not returned or refreshed. Lines 537–549 load relations, then compare old attributes to the old snapshot, potentially suppressing the update notification and returning stale shift data. Timeline sync separately calls `fresh()` and is not the same defect.

**Correction:** return the updated locked model, recheck applicable authority/state/eligibility after locking, and use the committed values for the response and post-commit notification. Add a revision conflict response for stale edits. **Acceptance:** a time or assignee change is returned correctly and reaches the correct recipients once; a competing edit cannot silently overwrite a newer record. Preserve approved-timesheet guards already present on Shift.

### WF-11 · P1 · Overnight cover disappears from subsequent days [S]

`resources/js/components/rostering/calendar-pane.tsx:1110` groups events only under their start `dateKey`. The Rostering grid mapping around `resources/js/pages/operations/rostering/index.tsx:941` similarly assigns a start-day key. Backend overlap retrieval alone does not render the continuation on the next day.

**Correction:** render an interval on every intersected day with continuation labels and clipped daily hours, using one underlying shift identity. Midnight end is exclusive. **Acceptance:** Sunday 22:00–Monday 07:00 appears correctly in both weeks; clicking either segment opens the same shift; totals do not double-count. Include sleepover and awake-night cases.

### WF-12 · P1 · View permission permits cancellation of a recurring series [S]

`app/Http/Controllers/ShiftSeriesController.php:137` accepts `canManageSeries()`, which at line 484 includes `rostering.viewAny`. Cancellation selects all future active occurrences for the series. Read access is consequently also an action capability on this path.

**Correction:** use explicit series/shift mutation authority plus approved-site/direct-object checks on every affected occurrence, with fresh transactional state. Preserve cancellation cascades, replacements, reservations, timeline records and approved-timesheet protection. **Acceptance:** a view-only role can inspect but cannot cancel; mixed-access or locked occurrences produce a comprehensible atomic result rather than a partial surprise.

### WF-13 · P2 · Calendar and header describe different periods [S+B]

`resources/js/components/rostering/calendar-pane.tsx:971` starts an independent month cursor. The parent header and signal rail retain their week. Browser navigation from October to November left September/October header totals and needs-attention items visible. The global New shift action then opened with 5 October defaults rather than the viewed November context.

**Correction:** give header, calendar, filters and create actions one selected date/range contract. Cell Add retains its date; global Add uses a clearly selected date in the visible period. Label any intentionally independent metric explicitly. **Acceptance:** November, week, day and report links agree; returning from an edit retains the user's position.

### WF-14 · P2 · Date entry follows the browser timezone, not the configured work timezone [S]

`resources/js/pages/operations/shifts/components/create-shift-dialog.tsx:219` converts naive input with `new Date(value).toISOString()`. Native datetime fields at lines 2109 and 2133 expose no explicit worker-timezone contract. Calendar drag logic at `calendar-pane.tsx:1277` also performs local-browser date arithmetic.

**Correction:** use the approved date and exact-minute time controls, visibly show Auckland/configured timezone, and define nonexistent/ambiguous local-time handling. Keep UTC storage. **Acceptance:** a coordinator's laptop set to another timezone creates the intended Auckland shift; DST changes do not silently change wall times or duration.

### WF-15 · P2 · “Coverage” currently means assigned shifts, not required support [S+B]

`app/Http/Controllers/RosteringController.php:385` calculates assigned non-cancelled shifts divided by all non-cancelled shifts, returning 100% for no shifts. The header describes this as “filled vs demand”. Existing `ShiftCoverageService` and coverage-rule paths contain richer demand information.

**Correction:** label this measure “Shifts assigned”; show separately whether required staffing windows, roles and support ratios are met. An empty roster is not proof of safe coverage. **Acceptance:** one assigned shift against a requirement for two staff shows a real gap; absent demand rules show “Requirements not configured”, not a green safe-coverage assertion.

### WF-16 · P2 · Status counts and reassuring labels overstate what is known [S+B]

Rostering `timesheets_pending` at `RosteringController.php:258` includes drafts/returned rows while the UI at `index.tsx:2355` says “waiting on approval”. The browser showed 4 there versus 2 submitted in Timesheets. `TimesheetController.php:512` derives “next payroll” as next Friday rather than a configured payroll calendar. `job-board-hero.tsx:92` defaults to “Your compliance is current”, and line 111 always says “refreshed just now”. Shift Notes displayed “All reviewed”/“All caught up” alongside a missing-note indicator.

**Correction:** measure the exact displayed state, use real refresh/compliance evidence, and use “No notes awaiting review” when that is all the count proves. Payroll dates must come from the actual configured process. **Acceptance:** every metric opens the same population it counts; unknown/loading/error are distinct from zero/success; no new mandatory note policy is invented.

### WF-17 · P2 · Selected context is lost across tabs and related pages [S+B]

`resources/js/pages/operations/rostering/index.tsx:740` changes most tabs only in component state; only lazy-data tabs perform navigation. Refresh/back/deep links can therefore return to a different tab. Conflict links and its back link omit selected sites/person; `RosteringConflictsRequest.php:19` accepts only week. `newRecurringShift()` at line 815 drops the current roster context. Staff availability redirects omit the selected person. Conflict site filters use presentation-derived locations rather than canonical site IDs.

**Correction:** persist allowed tab/view/date/site/staff/person/search state in URL parameters, preserve it in return links, and validate canonical IDs. Preserve the existing good period/site context in publish review/diff. **Acceptance:** copy link, refresh, back, calendar edit, recurrence creation and conflict resolution all return to the same permitted context.

### WF-18 · P2 · Overnight availability cannot be entered directly [S]

`app/Http/Controllers/StaffAvailabilityController.php:65` requires the end time to be after the start time on the same day. That rejects an ordinary Monday 21:00–Tuesday 07:00 availability window. The pane also uses a different week ordering from the roster.

**Correction:** support explicit next-day ending, or an atomic user-facing split into linked daily windows if compatible with the existing model. Teach eligibility the same semantics. Standardise the visible week order. **Acceptance:** an overnight worker can enter, inspect, edit and remove availability without pretending two unrelated shifts; the resulting eligibility assessment covers the entire night.

### WF-19 · P2 · Availability data and controls do not consistently follow scope/capability [S+B]

`RosteringController.php:820` builds availability across broad staff data and `now()`, rather than the selected roster period and approved-site/person filter. The preview body showed 25 people, including administrative roles, versus 24 in the header staff picker. `StaffAvailabilityController` uses self/any permissions, while the Rostering pane is controlled by shift-management permission.

**Correction:** show relevant authorised rosterable staff and the selected period; make self-service and coordination permissions explicit. Distinguish “Not supplied” from “Unavailable”. Keep HR leave ownership and avoid exposing sensitive reasons unnecessarily. **Acceptance:** availability-only, self-service and coordinator roles can perform exactly the actions their permissions allow; filters and counts agree.

### WF-20 · P2 · Template and timesheet UI capabilities disagree with routes [S]

The Rostering template flag combines several permissions (`RosteringController.php:61`), while create/edit/apply/delete routes use separate exact capabilities (`routes/operations.php:969`). A template-only read permission reaches a redirect whose destination requires `rostering.viewAny`. Timesheet submit/resubmit route alternatives at lines 1107–1114 do not exactly match their controller checks.

**Correction:** send action-specific server capabilities and align route, controller, policy and UI. A shared shell must not require broader permissions than the selected function. **Acceptance:** custom roles with one capability get a working permitted action, and never an enabled button leading to an avoidable 403 or broader access.

### WF-21 · P2 · Notes export can disagree with the visible results [S]

`resources/js/pages/operations/shift-notes/Index.tsx:214` exports only the week, dropping the current person/staff/type/search filters. `ShiftNoteController.php:59` lists by effective shift date, but export at line 197 uses note creation date. Index results are silently capped at 400 (`:74`). A late-written note can therefore appear on screen but disappear from the corresponding export.

**Correction:** use one authorised filtered query and effective-date definition for list, totals and export; add pagination/complete-result disclosure. **Acceptance:** a late note, more than 400 records and each filter yield matching screen/export results, with private notes still protected.

### WF-22 · P2 · Timesheet staff link uses the wrong identity type [S]

`resources/js/pages/operations/timesheets/index.tsx:770` links to `/hr/people/${row.staff.id}`. `app/Models/Timesheet.php:396` defines staff as a User, while `routes/hr.php:266` and `EmployeeProfileController.php:1276` bind an `HrEmployeeProfile`. User and employee-profile primary keys are not interchangeable.

**Correction:** provide the authorised canonical profile URL/ID from the server and handle a missing/inaccessible profile explicitly. **Acceptance:** deliberately different user/profile IDs open the correct person; lack of HR access does not expose or misroute a profile. Existing HR timesheet-sync identity tests address a different identity boundary and do not cover this link.

### WF-23 · P2 · Workforce headers and navigation use two design generations [S+B]

Rostering, Shifts index, Shift Notes and Conflict Queue already use PageHeader. Attendance, Job Board, Timesheets, Handovers, Shift detail and Handover detail retain PageHero paths. The current design contracts supersede the earlier June audit's PageHero recommendation. Timesheets still renders the older `TabStrip` (`index.tsx:880`) with item-assigned tones; the current Rostering PageHeader rail already discards those old tone values.

**Correction:** migrate remaining pages using Rory's existing PageHeader, linked meters, Home breadcrumbs, connected rail and shared spacing/status rules. Keep all actions and detail tabs. Do not introduce an override guide or rework pages already compliant without cause. **Acceptance:** desktop navigation, title, range, actions and selected section are consistently placed, with no duplicate header/filter rail.

### WF-24 · P2 · Create/edit workflows need consistent recovery and review [S+B]

The shift creator is a custom multi-step Dialog (`resources/js/pages/operations/shifts/components/create-shift-dialog.tsx`) rather than the approved WizardShell. Native date/time fields and direct close paths leave inconsistent review, dirty-close and failed-save behaviour. Templates and availability have similar bespoke controls. Existing per-step validation must be retained; it is not absent. The browser confirmed a six-step shift dialog; no draft/save mutation was performed.

**Correction:** adapt existing fields and services into the shared shell, searchable directories, approved date/time controls, review and success states. Validate before advancing; preserve input after server errors; guard dirty close and double submission. Keep a direct, keyboard-accessible edit alternative to drag/drop. **Acceptance:** every existing field survives the migration; cancel/Escape/back/error/retry work without accidental loss or duplicate shifts.

### WF-25 · P2 · Too many entry points conceal the relationship between tasks [S+B]

The Workforce sidebar exposes nine entries, including Shifts, Rostering, Availability and Conflict Queue. Rostering itself has ten sections, with six hidden under More at the observed width. Users must infer which page owns planning, cover, availability and actual worked time.

**Correction:** use the six-entry Workforce proposal and one Rostering workspace in [navigation.md](navigation.md). Shifts becomes its full list view, not a reduced replacement. Keep detail routes and old-link compatibility. **Acceptance:** coordinators can plan, find cover, check availability and resolve a conflict without hunting through the sidebar; workers stay on My Day for current care work.

### WF-26 · P2 · Job Board has claim/approve, but lacks a complete interest/withdraw/reject cycle [S]

`app/Http/Controllers/Operations/JobBoardController.php:361` reserves one claimant; `:444` approves it. `routes/operations.php:1227` exposes claim/approve, with no matching worker withdrawal or coordinator rejection endpoint. The conflict queue's rejection TODO corroborates the gap. This is a real existing claim workflow, not an absent job board.

**Correction:** complete claim withdrawal, reasoned rejection, reopening, expiry and notifications first. If multiple expressions of interest are required, add them explicitly without replacing the current immediate-claim mode silently. Describe this as “Express interest”, not bidding for wages. **Acceptance:** losing/rejected/withdrawn applicants have a clear outcome, one worker is assigned, eligibility is rechecked at assignment and the original replacement worker remains responsible until transfer is approved.

### WF-27 · P2 · Control Room staffing needs a clear model boundary [S+B]

`app/Http/Controllers/ControlRoom/ControlRoomShiftController.php:188` starts a duty session immediately from name, lead and team. The model is `App\Models\ControlRoom\Shift`, distinct from the care Shift. The preview describes live-desk staffing and prepared/accepted handover, with Start New Shift and no future scheduling interval.

**Correction:** put only the staffing/rostering entry in the Rostering workspace, with exact Control Room permissions and a clear link to its independent operational workspace. Preserve start duty, recent duty history, prepared handover, acceptance, notes, stale-version checks and override rules there. A future Control Room roster is a bounded extension, not something a navigation move already supplies. Never create fake clients to fit operator duty into care shifts. **Acceptance:** a Control Room-only user can reach staffing without receiving care-roster access; operational handover remains intact.

### WF-28 · P2 · Limited and lazy lists need truthful completeness [S]

Recurring series uses a default limit of 100 (`app/Services/Operations/ShiftSeriesPresenter.php:25`), notes use 400, and several roster panes arrive through optional/lazy payloads. A user needs to distinguish the complete result from a cached/loading/limited subset. The browser briefly showed zero calendar metrics before loading real values.

**Correction:** expose total/loading/error/limited state, paginate server-side where needed, cancel/ignore stale filter responses and invalidate lazy data after relevant writes. Templates may intentionally be a reusable library independent of week: label that scope explicitly rather than imposing a misleading week filter. **Acceptance:** rapid site/week changes never display prior-scope data as current; errors preserve the selected context and provide Retry.

### WF-29 · P3 · Supported-living planning can explain cover and continuity better [S; proposal]

Reuse the existing coverage, eligibility, templates, series and suggestion services for a house-first planner: required versus planned staff by time window; awake-night versus sleepover/on-call labels; named competency requirements; continuity with familiar staff; handover overlap; leave impact; clear candidate rejection reasons. Avoid inventing pay, care-ratio or qualification policy. Suggestions remain reviewable and permissioned.

**Acceptance:** a coordinator can explain why a person is suggested, see unmet requirements and choose an alternative; an automatic suggestion cannot bypass safety, privacy, site eligibility or current approvals. Implement after WF-01–12, not as a substitute for those repairs.

## Additional cross-module findings

### WF-30 · P1 · Roster-entered leave can bypass HR approval capability [S]

`app/Http/Controllers/StaffTimeOffController.php:18` accepts availability update-self/update-any capability. Its leave branch at line 45 calls `LeaveService::createRosterLeave()`. `app/Domain/Hr/Services/LeaveService.php:629` submits and, at line 641, unconditionally approves the request. `approveRequest()` at line 268 checks current scoped subject/request and pending status, but not the HR approval capability. `HrLeaveAccessService::currentRequest()` at line 125 checks current staff scope. The canonical HR controller's approval path separately requires `hr.leave.approve` or `hr.leave.manage`.

**Impact:** an actor with availability-edit permission and a permitted current subject can reach a balance-affecting approved leave path without the approval authority required in HR. This is a source-level alternate-path defect; a restricted-role exploit was not executed. **Correction:** enforce action authority in the shared leave command, not just one controller. Self-service submits pending; direct recording of approved leave requires explicit approval authority and audit. Keep ledger/projection/cover creation in the existing transaction. **Acceptance:** self/other-site/view-only/availability-editor/HR-approver roles behave identically from HR, My HR, roster and direct request; denied approval produces no approved projection, ledger movement or cover notification.

### WF-31 · P1 · HR/H&S eligibility does not consistently evaluate future duty validity [S]

`app/Services/HealthSafety/HsTrainingComplianceService.php:28` selects requirements for the shift context, but `checkRequirement()` at line 92 receives no shift date. It returns stored current status; the grace check uses `isFuture()` relative to now. Lines 114–115 treat a requirement without an HR mapping as compliant. `app/Domain/Hr/Services/ComplianceMatrixService.php:220` accepts a Shift but calls the live hard-stop validator without it at line 226. These paths do not establish validity throughout a future shift. Medication competency already uses the shift end and driving has duty-window checks; preserve those better boundaries.

**Correction:** evaluate required evidence for the actual duty interval, including expiry midway through an overnight shift. Distinguish unmapped/not configured/unknown from compliant; apply configured enforcement and exact override authority, without inventing new policy. **Acceptance:** valid today but expired at the future start/end; approved grace period; missing mapping; renewal after publication; and revocation before start give the same decision through every assignment path. No blanket “passed” from absent evidence.

### WF-32 · P1 · House/person qualification registers are disconnected from canonical assignment checks [S]

`SiteStaffRequirement` is maintained by site create/update and `SiteComplianceController`, and displayed by `SiteProfilePeoplePresenter::staffRequirements()`. Inspected application usages do not consume it in assignment eligibility. Person-specific `StaffQualificationRequirement` is checked separately by `QualificationMatchController::checkShift()` at line 150, matching `qualification_name` to credential `type` and checking expiry against now. Neither register is present in the inspected canonical eligibility rule stack at `ShiftStaffEligibilityService.php:50–77`. Site coverage role requirements do feed coverage checks; these are different records and must not be conflated.

**Impact:** a requirement can look mandatory in a house/person record without proof it restricts roster assignment. **Correction:** map each enforceable requirement to a canonical HR/domain competency ID and effective scope; label unmapped informational entries honestly. Reuse the assignment result on the qualification check page. **Acceptance:** a configured house/person requirement changes the same worker's decision through create/calendar/template/claim/publish; spelling changes to a display label cannot change eligibility; unknown mapping triggers the defined review policy.

### WF-33 · P1 · Recorded modified-duty hours/restrictions do not feed the inspected roster decision [S]

`app/Models/ModifiedDuty.php:25` stores `hours_per_day`, dates and restrictions; `ReturnToWorkController::storeModifiedDuty()` at line 758 manages these records. Application usage inspection found model/controller/plan presentation, but no consumer in `ShiftStaffEligibilityService` or the fatigue rule. `FatigueRule.php:95` uses the configured general daily maximum. The injury→safety event→Control Room journey exists; the missing connection is authorised work capacity into assignment.

**Correction:** expose a minimal, explicitly approved restriction projection: worker, relevant duty capabilities/max hours, effective dates, review date, decision owner and source reference. Do not infer fitness, diagnoses or restrictions from free-text notes. Evaluate across relevant committed duties and surface affected shifts for a human decision. **Acceptance:** an approved lower-hours restriction changes assignment/capacity decisions during its effective period; expiry/change rechecks work; medical/case detail remains inaccessible to roster-only viewers. No silent unassignment of an active worker.

### WF-34 · P2 · Existing leave/offboarding cover automation lacks a complete visible return journey [S]

`LeaveService.php:325`, `:601`, `:685` and `WorkforceAvailabilityCoverageService.php:38`, `:82`, `:149`, `:320` already create/reconcile source-owned replacement/handover actions. The source service preserves independent replacement ownership. `LeaveService.php:1015` provides up to three conflict summaries; `resources/js/components/hr/leave-detail-modal.tsx:77–78` selects only the first conflict. No complete UI projection of `WorkforceAvailabilityCoverageAction` and canonical replacement progress was found in the inspected paths.

**Correction:** show a complete, paginated affected-shifts receipt in Leave and employee Offboarding, with permitted deep links, current cover/handover progress, next action and owner. Derive progress from actual replacement state; do not create a second “resolved” lifecycle. **Acceptance:** approval, edit, cancellation, replacement acceptance/rejection and exit-date change give consistent results on both sides, including more than three affected shifts and independently created cover work.

### WF-35 · P2 · Nightly eligibility refresh has a bounded horizon and needs source-change impact handling [S]

`app/Jobs/RecalculateFutureShiftEligibility.php:44–49` scans assigned `scheduled` shifts starting after now and before now plus 14 days. `routes/console.php` schedules this nightly at 01:30 in Auckland time. It emits source-linked warnings and does not silently unassign staff, which should be retained. That fallback alone cannot establish prompt validity for imminent/current duties or a published roster farther ahead. Leave/offboarding already have immediate specialised impact handling; this finding does not claim they wait for the nightly job.

**Correction:** target affected duties when a competency, requirement, approved restriction or employment/site fact changes; show freshness/failure; retain periodic reconciliation. Reuse durable jobs/signals with idempotent source/version keys and reconcile resolved conditions. **Acceptance:** renew/revoke/change then retry; published next-month duty; change just before start; failed queue job; repeated source event; alert acknowledgement without underlying correction. Do not close unrelated incidents when eligibility clears.

### WF-36 · P2 · IT and care scheduling check shared work commitments in only one direction [S]

`app/Domain/It/Services/ItTicketBookingService.php:91` checks booked technician intervals, StaffTimeOff, approved HR leave and visible/published care shifts, returning only busy/free. The canonical care eligibility stack does not consume IT booking intervals. A worker with duties across modules can therefore receive different conflict answers depending on where scheduling starts.

**Correction:** use source-linked committed duty intervals in both directions and include future operator duties when introduced. Define buffers, cancellation and authorised parallel-duty policy; do not turn every private calendar appointment into a mandatory employment restriction. **Acceptance:** IT→care and care→IT conflicts agree; cancelled bookings release time; linked records count once for workload; the roster exposes busy status without private ticket/appointment details.

### WF-37 · P2 · Several modules overstate assurance from empty or contradictory evidence [S+B]

The preview's empty Checklist library displayed 100% on track; Training displayed 100% current with no courses/enrolments; Wellbeing displayed 100% with zero people. H&S Drills showed four sites overdue alongside “drills current”. `resources/js/pages/health-safety/lone-workers/index.tsx:514` supplies an unconditional duty-met label; `resources/js/pages/privacy/dashboard.tsx:763` supplies an unconditional legal-compliance label. These badges are not proof of organisational compliance. This finding evaluates product evidence/copy, not legal compliance.

**Correction:** derive status from a declared scope, requirement denominator, effective period and evidence freshness. Use Not configured / Not assessed / No records / No known exceptions appropriately. Reuse the clearer unknown/unverified patterns already present in Fleet, IT and parts of H&S. **Acceptance:** zero records, missing mandatory configuration, expired evidence, deferred load and contradictory source statuses never produce unsupported success; drill-through shows the records behind the metric.

### WF-38 · P1 · Operations Qualifications entry fails to load [S+B]

`/operations/qualifications` returned a database error for ambiguous `service_context_id`. `app/Http/Controllers/Operations/QualificationMatchController.php:73–78` joins `clients` onto the visible requirements query; line 201 uses unqualified `whereNull('service_context_id')`. Both tables carry that field. SHA256 comparison confirmed this controller is identical in main and the preview checkout.

**Correction:** qualify columns against the canonical table or use a scoped relation query for the client picker while preserving approved-site/service-context checks. Do not drop the scope to make the query run. **Acceptance:** the page, picker, filters and pagination load for scoped and broad roles; requirements with null/matching/mismatched service context return the intended records; cross-site direct checks remain denied.

### WF-39 · P2 · Respite stay and employee-duty semantics need explicit separation [S; architecture risk]

`app/Services/Respite/RespiteShiftSync.php` creates one unassigned Shift for a booking's whole interval, updates it on extension, sets `in_progress` on stay check-in and completes it on discharge. Canonical graph/locking/terminal-state protections are present. These are stay lifecycle semantics, not proof of an employee clocking in or working that whole interval. This audit did not demonstrate erroneous payroll or run a multi-day stay mutation.

**Correction:** preserve the existing booking linkage and introduce an explicit distinction in staffing/capacity/report consumers between stay demand and actual employee duties. Generate/review coverage against the stay interval through existing demand/roster services; do not automatically assign the entire stay to one worker or count a stay check-in as attendance. **Acceptance:** multi-day respite with several shifts, extension after publication, early discharge, reassignment and approved time records retains correct demand, actual attendance, care evidence and billable allocations without duplicate shifts or retrospective pay edits.

## Functionality that should be retained

The audit found useful canonical foundations, not a reason to rebuild the module:

- `ShiftStaffEligibilityService::evaluate()` already combines conflicts, leave, turnaround, coverage roles, availability, fatigue, site assignment, driving, training, medication competence and onboarding. Reuse this decision path.
- Job Board approval locks position/shift/worker and re-evaluates assignment; replacement services preserve current responsibility and have site and state checks. Extend these, rather than adding a parallel assignment system.
- Timesheet approval, amendments, immutable snapshots, HR identity sync and approved-shift locks already have dedicated services and test coverage in the repository. Preserve them while fixing overlooked paths.
- My Day already connects active-site residents, tasks, medication permissions, incoming handover, clock state and timesheets. It remains the frontline home.
- Templates, recurrence, coverage reservations, publication review/diff/republish and human-reviewed suggestions exist. Feature flags and specialised routes must not disappear during navigation work.
- Care handover and Control Room handover have separate purposes and services. Preserve drafts, submission, acknowledgement/acceptance, follow-ups and existing concurrency safeguards.
- HR leave and offboarding already create and reconcile cover actions; preserve their source ownership, balances and original assignment responsibility. Add the missing user-visible progress rather than rebuilding the workflow.
- Fleet readiness/holds, governed location evidence, canonical attendance, private external calendar sync and approved-time billing have useful boundaries. Their integration should retain each module's authority.

The initial release should resolve the P1 findings, make counts/dates/context dependable, connect source-change impacts and then consolidate navigation with a signed-off parity checklist. The detailed sequence, complete sidebar state register, cross-module architecture and desktop acceptance matrix are in the companion documents.
