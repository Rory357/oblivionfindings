# HR Module — End-to-End Audit & Fix Plan

**Date:** 2026-06-10
**Audited by:** Claude (7 parallel static-analysis sweeps + targeted code verification + full `tests/Feature/Hr` run + live production probes on oblivionfindings.com as demo admin)
**Implementer:** Codex
**Post-implementation auditor:** Claude (will re-verify every acceptance criterion below)

---

## How to read this document

- Every item has **Problem → Evidence → Fix → Acceptance**. Acceptance criteria are what the post-implementation audit will check — make them pass.
- Tags: **[VERIFIED]** = confirmed by direct code read / test run / live probe. **[REPORTED]** = surfaced by a static sweep; re-verify the file:line before changing (line numbers may drift).
- Scope discipline: fix what's listed; if you find adjacent breakage, fix it and note it in the PR description. Do **not** stub UI for missing backends — hide the control instead (house rule).

### Working agreement (house rules that apply to this work)

1. **Tests:** never `php artisan test --parallel` (per-worker DBs aren't migrated → thousands of false failures). Run scoped: `php artisan test tests/Feature/Hr` plus any suites you touch.
2. **Test env:** the suite's behaviour currently depends on the developer's `.env` (see P2-4). Until that lands, run with `FEATURE_ROSTERING_PUBLISH=false`.
3. **Timezones:** store UTC, convert at the `app.worker_timezone` (Pacific/Auckland) boundary. Eloquent formats Carbon in its own tz — call `->utc()` before persisting tz-aware Carbons.
4. **Permissions are seeded, not migrated**, and deploys skip seeders. Any new permission key must be added to a seeder that `DatabaseSeeder` calls, and the deploy runbook (§6) must be executed on the server.
5. **Endpoints called by both Inertia and axios** must content-negotiate (`RespondsToInertiaOrJson` trait exists).
6. Full-width layout convention; no centered max-width caps on page bodies.
7. NZ locale and terminology everywhere (en-NZ, NZD, NZ statutory names).

### Architecture primer (read before touching anything)

- HR domain models live in `app/Domain/Hr/Models` (not `app/Models/Hr`); services in `app/Domain/Hr/Services`; controllers in `app/Http/Controllers/Hr`; pages in `resources/js/pages/hr`; routes in `routes/hr.php` (220 routes, name prefix `hr.`), `routes/api-hr.php`, `routes/training.php`.
- **RBAC:** `User::canDo()` (app/Models/User.php:333) = deny-override → allow-override → role permissions via `role_user` pivot. **No wildcard, no admin bypass.** `EnsurePermission` middleware splits `permission:a|b` on `|` as OR. Alias map in `permissionLookupKeys()` bridges `timesheets.*` ↔ `hr.time.*`.
- **Time & attendance (the part that already works):** all clock surfaces (`AttendanceController` for My Day, `MyHrController` self-service, HR `TimeTrackingController` clock actions) flow through `App\Domain\Hr\Services\AttendanceService`, which creates `HrAttendanceSession` + `HrTimeEntry` and on clock-out generates a draft **operations `Timesheet`** via `DraftTimesheetService::fromAttendanceSession` (AttendanceService.php:198,296). Payroll (`PayrollExportService`) reads **approved operations `Timesheet` rows only** (line ~316). This pipeline is correct — don't re-plumb it.
- **Leave (also works):** `LeaveService` creates a `StaffTimeOff` row on approval (line ~188) and deletes it on cancel (line ~366); `AvailabilityRule` (app/Services/Eligibility/Rules/AvailabilityRule.php:127-166) independently blocks rostering against approved `HrLeaveRequest`s. Leave **is** integrated with rostering.
- **Legacy:** `App\Models\Staff` has zero `use` imports anywhere — dead model; rostering assigns `Shift.user_id` → `User` directly. Do not build on `Staff`.
- **Tenancy:** HR models scope by `tenant_id` via `forTenant()` + `ResolvesHrTenant` (with a default-tenant fallback covered by tests). Operations models scope by `site_id`. Keep HR queries tenant-scoped.

### Test-run baseline (2026-06-10)

`tests/Feature/Hr`: **84/84 pass** with `FEATURE_ROSTERING_PUBLISH=false` (83/84 without pinning — see P2-4). The module's defects are mostly in the seams tests don't cover: permission seeding, dead workflows, cross-module bridges.

---

## §1 P0 — Ship blockers

### P0-1 · Permission seeding gaps 403 ~15 HR areas in production [VERIFIED + live-confirmed]

**Problem.** Three distinct failure classes, all confirmed:

1. `SeedHrPermissionsSeeder` defines 38 keys (orgchart, positions, compensation, benefits, goals, assets, calendar, analytics, surveys, expenses, skills, announcements, exit-interviews, approvals, signatures, payslips, reports.builder, settings, wellbeing, training.enroll, time aliases) **but is not called by `DatabaseSeeder`** (database/seeders/DatabaseSeeder.php — not in the list) and was never run on the production server.
   **Live evidence:** `https://oblivionfindings.com/hr/orgchart` → **403 Forbidden for the demo admin**. `/hr/leave` (RbacSeeder-covered) renders fine.
2. Seven keys are used in route middleware/policies but **defined in no production seeder at all** (only in `DuskDatabaseSeeder`, which is test-only):
   `training.viewAny`, `training.enrol`, `training.manageCourses`, `training.record`, `competency.viewAny`, `competency.manage` (all used by `routes/training.php` and as OR-alternatives in `routes/hr.php` training routes), and `hr.expenses.viewAny` (used by `app/Domain/Hr/Policies/HrExpenseClaimPolicy.php:12,17` while routes + seeders use `hr.expenses.view`).
   **Live evidence:** `https://oblivionfindings.com/training/matrix` → **403 for demo admin**.
3. Because `canDo()` has no admin bypass, missing definitions block **everyone including admins**; `HandleInertiaRequests` (~line 551+) also exposes these as always-false flags, so nav/buttons hide even where the route would work.

**Fix.**
1. In `DatabaseSeeder`, call `SeedHrPermissionsSeeder::class` right after `RbacSeeder::class`, and call `SeedAllPermissionsToAdminSeeder::class` **last** (after all permission seeders) so admin always holds everything.
2. Add the missing definitions. Put `training.viewAny/enrol/manageCourses/record` and `competency.viewAny/manage` into `RbacSeeder`'s permission catalogue (they're platform-wide, not HR-only), with sensible role grants (see 4).
3. Resolve `hr.expenses.viewAny`: change `HrExpenseClaimPolicy` to use the canonical seeded key `hr.expenses.view` (routes and seeder agree on it). Check whether that policy is registered/used at all; if it's dead, align it anyway.
4. Role grants beyond admin (proposal — adjust to the existing role names in `RbacSeeder`): `coordinator` and `team_lead` get `hr.leave.viewAny/approve`, `hr.employees.viewAny`, `hr.compliance.view`, `hr.training.view`, `training.viewAny`, `timesheets.approve`-aligned keys they already hold; `support_worker` gets nothing new (self-service `/hr/my/*` is ungated by design).
5. **Durable guard:** add `tests/Feature/PermissionDefinitionCoverageTest.php` that (a) boots the route collection, (b) extracts every `permission:` middleware parameter (splitting on `|`), (c) asserts each key exists in the `permissions` table after seeding (`RbacSeeder` + all permission seeders `DatabaseSeeder` calls). This kills the entire bug class — new routes with unseeded keys fail CI.
6. Deploy runbook §6 must be executed (the code fix alone won't repair the live DB).

**Acceptance.**
- Fresh `migrate:fresh --seed`: admin can load `/hr/orgchart`, `/hr/positions`, `/hr/compensation/bands`, `/hr/benefits`, `/hr/assets`, `/hr/analytics`, `/hr/headcount`, `/hr/announcements/create`, `/hr/exit-interviews`, `/hr/approvals/pending`, `/hr/expenses`, `/hr/skills`, `/hr/surveys`, `/hr/settings/webhooks`, `/hr/settings/custom-fields`, `/hr/settings/audit-log`, `/hr/reports/builder`, `/training/matrix`, `/competency/frameworks`, `/staff/background-checks` — all 200.
- `PermissionDefinitionCoverageTest` passes and fails when a bogus `permission:not.seeded` route is added (prove once locally).
- After server deploy + runbook: live probes above return 200 for demo admin (post-audit will re-probe).

### P0-2 · The HR Timesheets workflow operates on a permanently empty table [VERIFIED]

**Problem.** `HrTimesheet` (table `hr_timesheets`, period-based) has **no creator anywhere** — `grep` finds zero `HrTimesheet::create/firstOrCreate/updateOrCreate` outside tests/factories. Yet `/hr/time` (resources/js/pages/hr/time/index.tsx:389-480) ships a full submit/approve/reject/return + bulk workflow, an approval queue (`TimeTrackingController`:113,195-224), 7 routes (`hr.time.timesheets.*`), `HrTimesheetApprovalService`, and `HrNotificationService::notifyTimesheetSubmitted` — all against a table that is empty in every real environment. Tests pass only because factories insert rows directly. Meanwhile the **real** timesheet pipeline (operations `Timesheet`, fed by attendance clock-outs, approved in the Timesheets module, consumed by payroll) already exists and works (see primer).

**Fix (consolidation, mirroring the scheduling→rostering precedent).**
1. Re-point the `/hr/time` "Timesheets" tab and approval queue to **operations `Timesheet`** data (week-scoped, same shape the Timesheets module uses). Reuse the existing operations approval endpoints/services for approve/reject/return and bulk actions — do not build a second approval state machine. If reusing endpoints cross-module is awkward, render the list read-only with deep links into the existing `/timesheets` module screens.
2. Remove the dead surface: `hr.time.timesheets.submit/approve/reject/return/bulk-*` routes operating on `HrTimesheet`, the `HrTimesheet`-based queue in `TimeTrackingController@index`, and `HrTimesheetApprovalService` call sites (keep the table + model for now; drop in a later migration once nothing references them). Update `tests/Feature/Hr/HrTimeTrackingTest.php`, `HrTimeTrackingAuthorizationTest.php`, and `tests/Unit/.../HrTimesheetApprovalServiceTest.php` to target the consolidated flow.
3. Delete or wire the orphan pages `resources/js/pages/hr/time/timesheets.tsx` and `hr/time/entries.tsx` (currently rendered by nothing — `timesheets()` redirects to `index?tab=timesheets`).
4. `MyHrController` personal time page is on `HrTimeEntry` and is fine — leave it.

**Acceptance.**
- A worker who clocked in/out (attendance) sees that draft timesheet in `/hr/time` Timesheets tab; a manager with `timesheets.approve` can act on it and the **same row** changes status in the operations Timesheets module (single source of truth).
- No route, page, or service still reads/writes `hr_timesheets`. Feature tests green.

### P0-3 · Report Builder save posts to a nonexistent URL [VERIFIED]

**Problem.** `resources/js/pages/hr/reports/builder.tsx:157` does `router.post('/hr/reports/save', ...)`. No such route — the store route is `POST /hr/reports/builder` (`hr.reports.builder.store`, routes/hr.php:1023). Saving any custom report 404s; the builder's core action is dead.

**Fix.** Post to `/hr/reports/builder` (or `route('hr.reports.builder.store')`). Verify the payload keys match `ReportBuilderController@store` validation; add a feature test that saves a report from the builder payload and asserts a `HrSavedReport` row.

**Acceptance.** Build → Save in the UI creates the saved report and lands on `/hr/reports/saved` with it listed; test green.

### P0-4 · People index "Export" button does nothing [VERIFIED]

**Problem.** `resources/js/pages/hr/employees/index.tsx:261-264` renders an Export button in the hero with no `onClick`/`href`.

**Fix.** Wire it to the existing exporter: `POST /hr/import-export/export` (`hr.import-export.export`, gated `hr.employees.manage` — same audience as this page), passing the page's current filters if the controller supports them; otherwise trigger the default people export. A file download must result. (If you instead choose to remove the button, that violates nothing — but the backend exists, so wiring is preferred.)

**Acceptance.** Clicking Export on `/hr/people` downloads the CSV/XLSX produced by `ImportExportController@export`.

---

## §2 P1 — Major functional fixes

### P1-1 · Hired candidates get a User with no RBAC role and no way to log in [VERIFIED]

**Problem.** `RecruitmentService::convertToEmployee` (app/Domain/Hr/Services/RecruitmentService.php:214-223) creates the `User` with a legacy `role` **string column** and a random bcrypt password, but never attaches a `Role` via the `role_user` pivot — and `canDo()`/`hasRole()` read **only the pivot**. The new employee can authenticate (if they ever learned the password — nothing is sent) but holds zero permissions, sees an empty shell, and is invisible to any role-filtered picker.

**Fix.** In `convertToEmployee`: (1) resolve `Role::where('name', $offer->position_role ?: 'support_worker')` and `$user->roles()->syncWithoutDetaching([...])`; (2) dispatch an invite/password-set notification (use the app's existing invite or password-reset flow) so the hire can actually sign in; (3) keep `firstOrCreate(['email' => ...])` but guard the relink case — if the matched existing User already has an `HrEmployeeProfile` for a *different* candidate, abort with a validation error instead of silently rebinding.

**Acceptance.** Feature test: accepted offer → convert → assert `role_user` row exists for the role, a notification was queued to the new user, and converting a second candidate with the same email errors cleanly. Manual: converted user appears in rostering's worker pickers.

### P1-2 · HR course completions are invisible to compliance [VERIFIED]

**Problem.** Completing an HR catalog enrollment (`TrainingController@completeEnrollment` → `trainingService->completeEnrollment`) updates `HrCourseEnrollment` only. `ComplianceMatrixService` (line ~263) and `TrainingDashboardController` read **`StaffTrainingRecord`** exclusively. A worker who completes mandatory training through the HR catalog stays "expired/not started" on the compliance matrix — and compliance gates shift eligibility, so this can wrongly block rostering. There are two parallel course catalogues (`HrCourse` vs `TrainingCourse`) with no FK between them.

**Fix.** Bridge at the write: when an enrollment completes and the `HrCourse` is linked to a compliance requirement (`compliance_requirement_id`) or has a mapped `TrainingCourse`, upsert a `StaffTrainingRecord` (user, course reference, `completed_at`, `expires_at` derived from the course's validity period, certificate reference). If an `HrCourse` has no mapping, nothing extra happens. Add the inverse note to docs: `StaffTrainingRecord` remains the compliance source of truth; the HR catalog is an enrolment/delivery layer. (Full catalogue consolidation is deferred — §5.)

**Acceptance.** Feature test: user enrolled in an HrCourse linked to a compliance requirement → `completeEnrollment` → `ComplianceMatrixService` evaluates the requirement as met; training dashboard counts it.

### P1-3 · Manual time entries: naive timezone parsing and invisible to payroll [VERIFIED]

**Problem.** `TimeTrackingService::createManualEntry` (app/Domain/Hr/Services/TimeTrackingService.php:114-138):
1. `Carbon::parse($data['clock_in'])` — parses in server/app tz with no `worker_timezone` handling (the +12h bug class this codebase has hit repeatedly).
2. Creates a bare `HrTimeEntry` (status `submitted`) with **no attendance session and no draft operations `Timesheet`** — and payroll reads only approved operations `Timesheet` rows, so manually-entered hours can never be paid.
3. [REPORTED] `HrTimeEntry` may lack `clock_in`/`clock_out` datetime casts — verify and add.

**Fix.** Parse inputs as `Carbon::parse($value, config('app.worker_timezone'))->utc()`; add the missing casts; after creating the entry, generate a draft operations `Timesheet` for it (extend `DraftTimesheetService` with a `fromManualEntry()` analogous to `fromAttendanceSession()`, linking `timesheets.hr_time_entry_id`). Same tz treatment in `clockOnBehalf` (`ClockOnBehalfRequest`) and anywhere HR accepts wall-clock datetimes.

**Acceptance.** Feature test: manual entry 09:00–17:00 entered by an NZ user stores the correct UTC instants (assert raw DB values), produces a draft timesheet for that user/date, and a subsequent payroll run over the period includes those hours once approved.

### P1-4 · Public-holidays feature is dark — table, model and page exist, nothing connects them [VERIFIED]

**Problem.** `hr_public_holidays` table (migration 2026_03_22_200005) + `HrPublicHoliday` model exist; `resources/js/pages/hr/leave/holidays.tsx` exists but **no route or controller renders it** (orphan), no seeder populates NZ holidays, and nothing consults holidays in leave or payroll logic. For an NZ provider this matters: leave spanning public holidays must not consume annual-leave balance, and Holidays Act day-types drive pay.

**Fix (phase 1 — make it real).** Add `GET /hr/leave/holidays` (view, `hr.leave.viewAny`) + CRUD (manage, `hr.leave.manage`) rendering the existing page; seed NZ statutory holidays for 2026–2027 (incl. Matariki and regional anniversary days, region field) via a seeder `DatabaseSeeder` calls; show holidays on the Time-Off calendar (`TimeOffCalendarController`). **Phase 2 (note in code, don't build now):** subtract public holidays from working-day/hours calculations in `LeaveService`, and alternative-holiday accrual when staff work one (see §5 gap G-2).

**Acceptance.** `/hr/leave/holidays` lists seeded NZ holidays; create/edit/delete works for `hr.leave.manage`; holidays render on the time-off calendar; feature test covers index + store.

### P1-5 · Announcements (and onboarding tasks) notify nobody [VERIFIED for announcements]

**Problem.** `AnnouncementController@store` creates the announcement with zero `Notification` dispatch (grep: no notify/Notification in the controller) — staff only see it if they wander into `/hr/announcements`. [REPORTED] Onboarding task assignment likewise sends nothing — verify.

**Fix.** On publish, dispatch an in-app notification (existing notification system; respect the announcement's audience targeting fields; queue it) linking to the announcement. Same pattern for onboarding checklist assignment (notify the new hire's user when a checklist with tasks is generated for them) if verification confirms the gap. Follow the existing `LeaveApprovedNotification` shape in `app/Domain/Hr/Notifications`.

**Acceptance.** Tests: publishing an announcement creates notifications for targeted users; generating an onboarding checklist notifies the subject user. Manual: bell badge increments.

### P1-6 · Anyone authenticated can fan out e-signature requests [VERIFIED]

**Problem.** `POST /hr/signatures/request` (routes/hr.php:914) has no permission middleware, and `ESignatureController@request` (line ~120-140) validates input but performs **no `canDo` check** — any logged-in user can create signature requests against any users/documents. (The signer-side endpoints are properly self-scoped: `show`/`sign`/`decline` assert `signer_user_id === user->id`.)

**Fix.** Gate `request` (and any other initiator-side action) behind `hr.documents.manage` — or `hr.signatures.manage`, which `SeedHrPermissionsSeeder` already defines (and which becomes live with P0-1). Route middleware preferred, controller `abort_unless` as well.

**Acceptance.** Test: regular user → 403 on request; `hr.documents.manage`/`hr.signatures.manage` holder succeeds; signer flow unchanged.

### P1-7 · Deleting a User cascade-deletes statutory payroll/leave records [VERIFIED]

**Problem.** `hr_leave_requests.user_id`, `hr_payslips.user_id`, payroll-run item user FK (2026_02_12_100004:14, 2026_03_22_200003:15, 2026_02_12_100010:36) are `cascadeOnDelete`. NZ law requires wages/time and holiday/leave records be retained ~6–7 years (Employment Relations Act 2000 s130; Holidays Act 2003 s81). One user deletion silently destroys them. [REPORTED] same pattern likely on `hr_time_entries`, `hr_cases` — verify while in there.

**Fix.** New migration switching those FKs to `restrictOnDelete` (drop + re-add constraints). Deactivation (`is_active=false`, `approved_at` null-out) is the supported termination path; document that in the migration comment. Verify nothing in the app currently relies on hard-deleting users (grep `User::...delete`); if an admin user-delete flow exists, make it block with a clear message when HR records exist.

**Acceptance.** Test: deleting a user with a payslip/leave request throws a constraint violation (or the app surfaces "deactivate instead"); migration runs clean on a seeded DB.

### P1-8 · HR API endpoints: confirm authorization + add coverage [PARTIALLY VERIFIED]

**Problem.** `routes/api-hr.php` (8 read endpoints, `auth:sanctum`) relies on per-method `canDo` checks inside `HrApiController`. Spot-check confirmed `employees()` checks `hr.employees.viewAny`; the other seven (employee, leaveRequests, leaveBalances{userId}, positions, complianceStatus, timeEntries, payrollRuns) are unverified, and the area has **zero tests**.

**Fix.** Audit all 8: each must enforce an appropriate `canDo` AND tenant scoping (`forTenant`); `leaveBalances/{userId}` must not leak other users' balances to non-managers. Add a feature test file covering 403-without-permission and scoped-200-with-permission per endpoint.

**Acceptance.** New `tests/Feature/Hr/HrApiTest.php` green; no endpoint returns data without its permission.

### P1-9 · Leave datetime parsing ignores worker timezone [VERIFIED pattern]

**Problem.** `LeaveService` (~line 58) does `Carbon::parse($data['starts_at'])->startOfDay()` on user-entered dates with no timezone anchor; stored as UTC datetimes. Day-granularity leave booked from NZ can land on the wrong UTC day, shifting roster-blocking (`AvailabilityRule` compares UTC instants) and balance day-counts by one day either side.

**Fix.** Parse leave bounds in `config('app.worker_timezone')`, take `startOfDay()`/`endOfDay()` there, then `->utc()` before persisting (the established codebase pattern). Re-check `LeaveController` SLA timestamps and any leave-hours derivation for the same assumption.

**Acceptance.** Test: leave for "2026-06-15" submitted by an NZ user blocks a roster shift on June 15 NZT (e.g. starting 2026-06-14T20:00Z) and does not block June 14 NZT; raw DB values assert the converted UTC instants.

---

## §3 P2 — Consistency, hygiene, hardening

### P2-1 · en-NZ locale sweep [VERIFIED count]
23 files under `resources/js/pages/hr/**` call `toLocaleDateString('en-GB', ...)` (announcements, assets, benefits, cases, compensation×4, exit-interviews×2, performance×4, policies×3, settings×2, vetting×2, etc. — `grep -rln "en-GB" resources/js/pages/hr`). Replace with `en-NZ` (or the codebase's shared date-format helper if one exists — prefer the helper). Formats are near-identical; this is consistency, not breakage.

### P2-2 · Orphan pages [VERIFIED for time/*, holidays; REPORTED for the rest]
Never rendered by any controller: `hr/leave/holidays.tsx` (wired by P1-4), `hr/time/entries.tsx`, `hr/time/timesheets.tsx` (handled by P0-2), `hr/my/expenses.tsx`, `hr/performance/competencies/assess.tsx`. For the last two: wire them if a route obviously should exist (`/hr/my/expenses` self-service is plausible — `hr.expenses.*` routes exist), otherwise delete. No dead files left.

### P2-3 · Dead/duplicate permission keys [VERIFIED]
Defined but unused: `hr.disciplinary.view`, `hr.vetting.view_disclosures`, `hr.payslips.view/generate`, `hr.reports.builder`, `hr.goals.view/manage`, `hr.skills.view/manage` (goals/skills routes gate on `hr.performance.*` instead). Either adopt them on their natural routes (payslips routes currently reuse `hr.payroll.view`; goals/skills reuse performance) or delete from seeders. **Pick one; recommend adopting `hr.payslips.*` on the payslip routes (finer-grained payroll access) and deleting the rest.** Keep the `hr.time.*` aliases — `permissionLookupKeys()` references them.

### P2-4 · Hermetic test env [VERIFIED]
`AttendanceClockWorkflowTest` fails when the developer's `.env` sets `FEATURE_ROSTERING_PUBLISH=true` (phpunit.xml doesn't pin it; the test's shifts are unpublished → invisible to frontline → no ambiguity error). Pin `<env name="FEATURE_ROSTERING_PUBLISH" value="false"/>` (and `FEATURE_ROSTERING_AUTO_SCHEDULE`) in `phpunit.xml`, or make the test create published shifts. Suite must pass regardless of `.env`.

### P2-5 · Recruitment index dead prop [REPORTED]
`RecruitmentController@index` passes `stages` the page never consumes — drop from payload or use it.

### P2-6 · Factories for core HR models [VERIFIED gap]
Only 2 HR factories exist (compliance pair) for ~116 models; the suite hand-rolls rows. Add factories for the 10 workhorses: `HrEmployeeProfile`, `HrLeaveRequest`, `HrLeaveBalance`, `HrCandidate`+`HrApplication`, `HrTimeEntry`, `HrDocument`, `HrPolicy`, `HrExpenseClaim`, `HrPayrollRun`, `HrCourse`+`HrCourseEnrollment`. Use them in the new tests this plan adds.

### P2-7 · Demo data for empty HR areas [VERIFIED gap]
`HrSeeder` seeds compliance requirements, 3 policies, leave balances, onboarding templates, ~20 profiles. Everything else is blank in demo (leave requests, recruitment pipeline, cases, time entries, expenses, payroll, documents, reviews, assets, training catalog, goals, surveys…). Extend the demo chain (e.g. an `HrDemoSeeder` called near `DemoSeeder`) with a believable NZ dataset: ~6 leave requests in mixed states, 2 job requisitions + 5 candidates across stages, 1 case, 2 weeks of time entries for 3 workers, 2 expense claims, 1 locked payroll run, 4 documents, 2 reviews + 1 supervision, 3 assets with 1 assignment, 4 courses + enrollments, 2 announcements. Demo must not look broken.

### P2-8 · Exit-interview route gating simplification [VERIFIED oddity]
Exit-interview routes OR-in `hr.onboarding.view|manage` with `hr.exit-interviews.*` (routes/hr.php:940-947). Once P0-1 seeds `hr.exit-interviews.*` for real, drop the onboarding fallbacks for clarity.

---

## §4 What was checked and is fine (don't "fix")

- **Route↔controller↔page wiring:** all 220 web routes, 8 API routes, 152 `Inertia::render` targets resolve; bindings (`{profile}`, `{check}`, `{case}`, `{eligibility}`…) all map to real models. Zero broken wiring.
- **Leave ↔ rostering:** covered in both directions — `AvailabilityRule` blocks on approved HR leave; `LeaveService` mirrors to `StaffTimeOff`. Don't add a third path.
- **Attendance → timesheet → payroll backbone:** clock flows generate draft operations timesheets; payroll aggregates approved timesheets with rate rules; `ShiftPayrollBackboneIntegrationTest` covers it end-to-end.
- **Vetting:** single shared `StaffBackgroundCheck` table for HR vetting register + staff background-check pages; consent capture, clear/renew flows present.
- **Scheduled jobs:** compliance matrix evaluation, leave accrual + SLA escalation, expiry reminders, candidate-data archival (privacy), scheduled reports, webhook deliveries — all registered in `routes/console.php`.
- **My-HR self-service:** `MyHrController` consistently scopes to `auth()->id()` (IDOR-checked); calendar event mutations check `canManage` in-controller; e-signature signer endpoints self-scope.
- **Money:** decimal columns throughout; sensitive rates encrypted at rest (note: encrypted columns can't be SQL-aggregated — known trade-off, fine).
- **File storage:** HR documents and candidate docs on the private disk with permission-checked streaming downloads.
- **Payslips:** PAYE, KiwiSaver employee+employer, student loan columns already modelled.
- **Tests:** 84/84 HR feature tests pass (env pinned); good workflow coverage on payroll, recruitment offers, attendance, automations.

---

## §5 Gap analysis

### A. NZ statutory/regulatory gaps (build-next candidates, not part of this fix round unless tagged)

| # | Gap | Detail | Suggested home |
|---|-----|--------|----------------|
| G-1 | **Family violence leave** missing | `LeaveService::LEAVE_TYPES` = annual, sick, bereavement, parental, public_holiday, unpaid, toil, other. Holidays Act amendments grant 10 days/yr family violence leave; distinct from sick. | Add `family_violence` type + balance seeding (tag onto P1-4 PR if trivial) |
| G-2 | **Alternative holidays ≠ TOIL** | Working a public holiday must accrue an *alternative holiday* (statutory) — `toil` is a contractual concept. Needs holiday calendar (P1-4) + accrual on worked public holidays + payroll day-type rates (time-and-a-half). | Phase 2 after P1-4 |
| G-3 | **Leave accrual model honesty** | Accrual job is monthly/hours-based; Holidays Act entitlements are weeks-based (4 weeks annual after 12 months; sick 10 days after 6 months). Document the simplification in-code and in the HR settings UI so admins aren't misled; full Holidays Act engine is a project, not a fix. | Doc note now; engine later |
| G-4 | **Right-to-work / visa expiry** | Profile has `ird_number` (encrypted) + `kiwisaver_rate`, but no visa/work-rights fields or expiry reminders. Care workforce relies heavily on migrant labour — expiry must alert like vetting expiry does. | New profile fields + compliance requirement type |
| G-5 | **Children's Act / safety-check workforce fields** | Vetting register covers police vetting; a structured "safety check" record (identity verification, 2 referees, risk assessment, 3-yearly re-check) per the Children's Act 2014 / vulnerable-adults policy would round it out. | Extend vetting check types |
| G-6 | **Pay equity bands** | Care and Support Workers (Pay Equity) Settlement pay points by qualification level (L0–L4a) map naturally onto `HrSalaryBand` — currently free-form. Seed NZ pay-equity bands as defaults. | Compensation seeder |
| G-7 | **ESCT** | Payslips carry KiwiSaver both sides + student loan; no ESCT (employer superannuation contribution tax) line. Payroll *export* profiles can carry it through to the payroll provider — confirm mapping fields suffice. | Payroll export profile mapping |
| G-8 | **Record retention** | P1-7 fixes deletion; a stated retention policy (6–7 yrs wages/leave) in HR settings + privacy module alignment would complete it. | Settings/docs |

### B. Cross-module duplication map (current verdicts)

| Area | Verdict | Action |
|------|---------|--------|
| Time: `HrAttendanceSession`/`HrTimeEntry` → operations `Timesheet` | **Integrated** (single pipeline) | Keep; P1-3 closes the manual-entry side door; P0-2 removes the phantom second approval pipeline |
| Leave: `HrLeaveRequest` ↔ `StaffTimeOff` ↔ eligibility | **Integrated** (write-bridge + direct rule) | Keep |
| Training: `HrCourse`/`HrCourseEnrollment` vs `TrainingCourse`/`StaffTrainingRecord` | **Parallel, write-bridged by P1-2** | Full catalogue merge deferred; HR catalog is canonical UI (legacy routes already 301) |
| Competencies: `HrCompetency` (role assessments) vs `CompetencyFramework` (clinical frameworks) | **Parallel by design** | Document the split; no merge now |
| Vetting | **Shared table** | None |
| Identity: `User` + `HrEmployeeProfile` (+dead `Staff`) | **Two live systems, one dead** | P1-1 fixes role attach; never build on `Staff` |
| Payroll | **Single source** (operations timesheets) | Keep |

### C. Notifications coverage matrix

| Event | Notifies? | Action |
|-------|-----------|--------|
| Leave submitted/approved/declined | ✅ | — |
| Development goal assigned | ✅ | — |
| Job posting approval request | ✅ | — |
| Timesheet submitted (HR pipeline) | ⚠️ exists but pipeline dead | Resolves with P0-2 |
| Announcement published | ❌ | P1-5 |
| Onboarding checklist/tasks assigned | ❌ (verify) | P1-5 |
| E-signature requested | verify while doing P1-6 | add if missing |
| Compliance/vetting expiry | ✅ (scheduled reminders) | — |

### D. Navigation [REPORTED — verify during implementation]
The permission sweep couldn't fully resolve sidebar gating. While doing P0-1, check the HR nav/sidebar component: every HR area above should be reachable for a permitted user, and entries must be gated by the same keys as their routes (the `auth.can.hr.*` flags from `HandleInertiaRequests` become trustworthy once P0-1 lands). List any HR route with no nav path in the PR description.

---

## §6 Deploy runbook (server, after merge)

Deploys auto-pull + auto-build but **skip seeders**. After this work merges:

```bash
php artisan migrate --force
php artisan db:seed --class=RbacSeeder --force
php artisan db:seed --class=SeedHrPermissionsSeeder --force
php artisan db:seed --class=SeedAllPermissionsToAdminSeeder --force
# + the new NZ public-holidays seeder from P1-4
php artisan db:seed --class=NzPublicHolidaysSeeder --force
```

Then live-verify: `/hr/orgchart`, `/training/matrix`, `/hr/settings/webhooks` as admin → 200.

---

## §7 Acceptance checklist (post-Codex audit script)

| # | Check | How |
|---|-------|-----|
| 1 | All §1 P0 + §2 P1 acceptance criteria | as written per item |
| 2 | `php artisan test tests/Feature/Hr` (no env tricks) | 100% pass incl. new tests |
| 3 | `PermissionDefinitionCoverageTest` exists and guards routes | inspect + run |
| 4 | `npx tsc --noEmit` and `npm run build` | clean |
| 5 | Live probes after deploy + runbook | §6 list returns 200 |
| 6 | No `hr_timesheets` reads/writes outside migrations | grep |
| 7 | Manual time entry → payroll run inclusion | test + manual |
| 8 | Converted hire has role + invite notification | test |
| 9 | en-GB count in `resources/js/pages/hr` = 0 | grep |
| 10 | Orphan page list empty (rendered or deleted) | grep `Inertia::render` cross-check |

## §8 Out of scope (explicitly)

- Building a full Holidays Act calculation engine (G-3) — document the simplification only.
- Merging the two course catalogues or the two competency systems (bridged/documented instead).
- A payroll engine — export-to-provider remains the model.
- Dusk/browser suite changes beyond what P0-2 forces.
- The `/hr/feed`, wellbeing survey audience-scoping, and report subscription internals — reviewed, no defects found worth scheduling; revisit only if QA surfaces issues.

---

## §9 Codex implementation close-out (2026-06-10)

### Scope completed

- P0-1 through P0-4 implemented: HR permission seed coverage/backfills, legacy HR timesheet approval surface removal, report-builder save wiring, and people CSV export wiring.
- P1-1 through P1-9 implemented: recruitment conversion roles/invite, training-compliance bridge, manual HR time-entry to operations-timesheet bridge, NZ public holidays, announcement/onboarding notifications, e-signature request permission gate, HR record-retention delete restrictions, protected HR API endpoints, and worker-local leave date parsing.
- P2-1 through P2-8 implemented: HR `en-NZ` locale sweep, orphan page routes, permission-key cleanup, hermetic rostering feature flags in `phpunit.xml`, recruitment dead prop removal, core HR model factories, `HrDemoSeeder`, and exit-interview route/controller permission simplification.
- Legacy HR timesheet application layer removed: workflow service/result/request/notification/test/pages and the unused `HrTimesheet` model are gone; operations timesheets are the remaining approval/payroll path.

### Verification passed

```bash
vendor\bin\pint --dirty
php artisan test tests/Feature/Hr --env=testing
php artisan test tests/Feature/PermissionDefinitionCoverageTest.php --env=testing
php artisan test tests/Feature/Hr/HrTimeTrackingAuthorizationTest.php --env=testing
php artisan wayfinder:generate
npm run types
npm run build
rg -n "en-GB" resources/js/pages/hr
rg -n "\bHrTimesheet\b|hr_timesheets" app routes resources tests database/seeders -S
```

Results:

- `php artisan test tests/Feature/Hr --env=testing`: 109 passed, 1164 assertions.
- `PermissionDefinitionCoverageTest`: 6 passed, 15 assertions.
- `HrTimeTrackingAuthorizationTest`: 7 passed, 52 assertions after deleting the dead `HrTimesheet` model.
- `npm run types` initially failed because generated `@/routes` modules were missing; `php artisan wayfinder:generate` refreshed `resources/js/actions` and `resources/js/routes`, and the rerun passed.
- `npm run build` passed.
- `en-GB` grep returned no HR-page matches.
- Legacy HR timesheet grep returned no application/test/seeder matches outside migrations.

### Review boundary

- Live deployment and the deploy runbook in §6 are not executed in this worktree.
- The statutory/regulatory gaps listed in §5A remain future work unless separately requested.

---

## §10 NZ statutory gap round (Claude, 2026-06-11)

§5A gaps implemented after the P0–P2 round merged (details + legal references in
`docs/hr-nz-statutory-notes.md`):

- **G-1** `family_violence` and `alternative` leave types across the engine
  (LEAVE_TYPES, config labels/entitlements/accrual, seeded balances, frontend
  filter + colour maps).
- **G-2** Public-holiday awareness end-to-end: `PublicHolidayCalendar` (national
  + region-matched anniversary days), draft timesheets auto-flag
  `public_holiday` on all three creation paths, and `AlternativeHolidayService`
  credits one alternative-holiday day on approval of a worked public holiday
  (ledger-deduped per timesheet; casual/contractor excluded).
- **G-4** Right-to-work tracking: `work_rights_status` / `visa_type` /
  `visa_expires_at` on profiles (edit + show UI with expiry warning),
  `SendExpiryRemindersJob` notifies worker **and manager** at the standard
  reminder intervals (`VisaExpiryNotification`).
- **G-5** Vetting label modernised to "Children's Act Safety Check" (stored
  value unchanged).
- **G-6** `HrPayEquityBandsSeeder` — Care & Support Worker settlement band
  structure (rates are editable defaults), wired into `DatabaseSeeder`.
- **G-7** Confirmed export-to-provider boundary covers ESCT/KiwiSaver needs
  (documented; no schema change).
- **G-8** `hr_time_entries.user_id` now `restrictOnDelete`
  (migration `2026_06_11_000002`), completing the retention FK set.

**Incidental fixes found while implementing:**
- `work_date` on attendance- and shift-generated timesheets was the **UTC**
  date — NZ morning shifts were dated one day early. Now derived in
  `app.worker_timezone` (`DraftTimesheetService`).
- Approving any **shift-less** timesheet 500'd: `snapshotForTimesheet`
  regenerated `shift_type_snapshot` to null and `syncToHr` persisted it before
  the billing snapshot guard ran. Fixed with a draft-value fallback
  (`ShiftOperationalSnapshotService`).
- People edit page selects (`employmentTypes` etc.) received plain strings but
  rendered `{value,label}` objects — selects were broken. Controller now sends
  objects.

**New tests:** `tests/Feature/Hr/NzStatutoryGapRoundTest.php` (7 tests, 33
assertions). Deploy note: run `HrPayEquityBandsSeeder` alongside the §6
runbook seeders; migrations add visa columns + the FK change.
