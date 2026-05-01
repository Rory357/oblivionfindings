# Rostering / HR / Staff / Training — Production-Readiness Plan

Scope: the overlap between `routes/operations.php`, `routes/hr.php`, `routes/staff.php`, `routes/training.php`, and `routes/shifts.php` (attendance).

Goal: make this app production-ready with minimal churn. Preserve existing work. No rewrites.

---

## 1. Ownership boundaries (target state — already mostly true today)

The current architecture is workable. Recommendation is to **codify** the lines below, not redraw them.

**Operations** — canonical surface for scheduler/admin workflows.
- Shifts + shift series, rostering board, roster templates, roster suggestions, publishing, conflicts, coverage gaps/reservations, availability planner, timesheets (review/approval/payroll), job board, qualification matching, EVV.
- Routes already canonical at `/operations/*` (`routes/operations.php`). All scheduler entry points already carry `role_scope:my-day` to bounce frontline workers home.

**HR** — canonical surface for the employment lifecycle and self-service.
- People profiles, recruitment + ATS, onboarding/offboarding, leave, performance/supervision/PIPs/goals, compensation, benefits, expenses, **policies + attestations**, **compliance matrix + calendar**, **vetting register**, **driver eligibility**, **payroll exports + payslips**, **training catalog + enrolments + certificates**, HR documents, analytics/headcount, departments/positions, org chart.
- Self-service lives at `/hr/my/*` (`routes/hr.php:83`).

**Staff** — thin "person record" surface used by Operations and HR.
- Staff list/profile, staff edit, **client assignments** (who supports whom), **credentials** (certifications/registrations attached to a person), **availability** patterns.
- Should NOT own rostering, training, or compliance dashboards.

**Training** — narrow, distinct surfaces only.
- `competency/frameworks/*` (active — `CompetencyFrameworkController`).
- `staff/{user}/induction/*` (active — `StaffInductionController`).
- `staff/background-checks/*` (active — `Staff\StaffBackgroundCheckController`, the actual implementation behind HR vetting).
- `training/courses/*` is a **legacy redirect bridge** to `hr.training.*`; treat as compatibility only.

**Attendance** — frontline clock-in/out + breaks + handover.
- `routes/shifts.php:93-113`. Canonical per PR 4.5; do not move.

---

## 2. Concrete risks (with evidence)

### R1 — Duplicate `rostering.index` route, only one is guarded
- `routes/staff.php:76` — `GET /rostering` → `RosteringController@index`, name `rostering.index`. Permission only.
- `routes/operations.php:554` — `GET /operations/rostering` → same controller method. Permission **plus** `role_scope:my-day`.
- Sidebar uses `/operations/rostering` (`resources/js/components/app-sidebar.tsx:823`). No code references `route('rostering.index')`. The legacy route is dead weight that diverges from the canonical guard set.

### R2 — Time-off mutations live under the legacy rostering namespace
- `rostering.time_off.store` / `rostering.time_off.destroy` (`routes/staff.php:79-84`) post to `/rostering/time-off/*` even though the index is `/operations/rostering`. Path/name namespaces are split.

### R3 — Permission seeds for stubbed routes
- `staff.training.*` and `staff.competency.*` routes are commented out in `routes/training.php:76-135`, but the permissions are still seeded in `database/seeders/RbacSeeder.php`. Admins can assign them; nothing happens. Confusing during role setup.
- Stub controllers `Training\StaffTrainingRecordController` and `Training\StaffCompetencyController` exist as empty shells. No callers — but the files invite re-enabling without a real implementation.

### R4 — Permission overlap at the timesheet/HR-time boundary
Approval routes on `operations.timesheets.*` accept `timesheets.approve|timesheets.manageAny|hr.time.manage|hr.time.approveTeam` (`routes/operations.php:678-686`). The HR module re-implements approval at `hr.time.timesheets.*` (`routes/hr.php:957-979`). Two screens for the same logical action.

### R5 — Three clock-in endpoints, one underlying table
`hr.my.time.clock-in`, `hr.time.clock-in`, and `attendance.clockIn` all touch `hr_attendance_sessions` / `timesheets`. **Verify** that they cannot produce overlapping open sessions for the same user; if not enforced at the service layer, two open sessions at once is a real production hazard.

### R6 — Two training namespaces in nav-discoverable form
- `hr.training.*` (real) and `training.courses.*` (redirect bridge) coexist. The bridge is intentional, but is undocumented in code and looks like duplication.

### R7 — `vetting.*` vs `hr.vetting.*` permissions
- `routes/training.php` background-check routes use `vetting.viewAny|manage|verify|assessRisk`; `routes/hr.php` register uses `hr.vetting.view|manage`. Same conceptual entitlement, two namespaces. Functional today, confusing for RBAC review.

### R8 — Legacy stub files invite future drift
- `Training\StaffTrainingRecordController` (~19 lines) and `Training\StaffCompetencyController` (~13 lines) are shells. Without an explicit "do not enable" marker they look revivable.

---

## 3. Minimal implementation plan

### P0 — required before production

The codebase is in better shape than the route count suggests. Only one P0 is unambiguous:

- **P0.1 — Verify single-active-session guarantee for clock-in.** Read `MyHrController::clockIn`, `Hr\TimeTrackingController::clockIn`, `AttendanceController::clockIn`. Confirm at least one writes through a service that rejects a second open `HrAttendanceSession` for the same user, and that the other two delegate to the same path or also enforce. If they don't, add the guard at the service/model layer. Do **not** remove any of the three endpoints — different audiences use them.
- **P0.2 — Decide and document the canonical training catalog path.** `Training\TrainingCourseController` is a redirect-only bridge; add a one-line class docblock saying so. No behaviour change. Prevents future confusion or accidental re-implementation.

### P1 — cleanup that reduces confusion (no rebuilds)

- **P1.1 — Retire the legacy `/rostering` route.** Replace `routes/staff.php:76` with a 301 redirect to `operations.rostering.index`. Drop the `rostering.index` name (no callers) — or keep the name aliased to `operations.rostering.index` for one release if you want safety.
- **P1.2 — Move `rostering.time_off.*` under operations.** Re-namespace to `operations.rostering.time_off.store|destroy` at `/operations/rostering/time-off/*`. Keep 308 redirects at the old paths for any external POSTers, mirroring the pattern in `routes/shifts.php`.
- **P1.3 — Mark stub controllers as not-implemented.** Add `@deprecated stub — see Hr\TrainingController / StaffCredentialController` to the class docblocks of `Training\StaffTrainingRecordController` and `Training\StaffCompetencyController`. Remove the corresponding commented-out route blocks in `routes/training.php:76-135` (kept code, no behaviour).
- **P1.4 — Prune orphaned permissions in `RbacSeeder`.** Remove `staff.training.*` and `staff.competency.*` seeds (they gate nothing). Keep `training.*` and `competency.*` since active routes still use them.
- **P1.5 — Sidebar audit.** Confirm there is no link to a stub or a doubled training entry. Already mostly true (`resources/js/components/app-sidebar.tsx:823, 829, 2079, 2289-2295, 2330`); verify after P1.1/P1.2 land.
- **P1.6 — One-page route ownership doc.** A short `docs/route-ownership.md` codifying §1. Cheaper than future arguments.

### P2 — optional, defer

- **P2.1 — Permission consolidation.** Long-term, collapse `timesheets.* | hr.time.*` and `vetting.* | hr.vetting.*` to a single namespace each, with the loser kept as a synonym in the policy layer. Touches RBAC seeds, controllers, and tests; not worth doing under deadline.
- **P2.2 — Remove the stub controller files entirely** once P1.4 has shipped and no one has re-enabled them for one release cycle.
- **P2.3 — `training.courses.*` retirement.** When you're confident no external bookmarks or emails use the old URLs, remove the bridge controller and replace with route-level redirects.

---

## 4. Do NOT change

- The split between Operations (scheduler) and HR (employment lifecycle) — the boundaries map cleanly to who uses each surface.
- `role_scope:my-day` on the operations scheduler routes — it's the friendly-redirect safety net for stale links.
- The Attendance routes in `routes/shifts.php` — explicitly canonical per PR 4.5, frontline-facing, called from `/my-day`.
- The legacy 301/308 redirect compatibility layer in `routes/shifts.php` — protects bookmarks and queued POSTs.
- The HR self-service surface (`/hr/my/*`) — distinct, used, no overlap with Operations.
- The `Staff` namespace's narrow scope (profile, assignments, credentials, availability) — do not push training, leave, or rostering into it.
- `Training\CompetencyFrameworkController`, `Staff\StaffBackgroundCheckController`, `Training\StaffInductionController` — real, used implementations.
- The OR-permission middleware patterns. They look messy but are the legitimate way to support multiple roles converging on one screen.

---

## 5. Verification plan

Run all of this on a clean branch after each P1 item.

**Route layer**
- `php artisan route:list --columns=method,uri,name,middleware | grep -Ei 'rostering|training|timesheet|attendance|vetting|competency'` — eyeball before and after.
- Assert `route('rostering.index')` and `route('operations.rostering.index')` resolve to the same controller (or that the legacy name is gone) — `php artisan tinker` one-liner.
- Assert `route('rostering.time_off.store')` either still resolves (alias kept) or is gone (alias dropped). Pick one and document.

**Permissions / RBAC**
- `php artisan db:seed --class=RbacSeeder` on a scratch DB. Diff `permissions` table before/after P1.4. Should lose only `staff.training.*` and `staff.competency.*`.
- Spot-check that no policy or middleware uses the removed permissions: `grep -RE "staff\.(training|competency)\." app/ resources/`.

**Feature tests (already exist; just run them)**
- `tests/Feature/Rostering/*` — full suite.
- `tests/Feature/ShiftControllerTest.php`, `ShiftCancellationCascadeTest.php`.
- `tests/Feature/TimesheetControllerTest.php`, `TimesheetAmendmentWorkflowTest.php`, `TimesheetReconciliationTest.php`.
- `tests/Feature/Attendance*Test.php` (4 files).
- `tests/Feature/MyDayPreShiftBriefingTest.php`, `MyDayPreviousShiftTest.php`.

**New thin tests to add (cheap; no rebuild)**
- A redirect test: `GET /rostering` returns 301 to `/operations/rostering`.
- A redirect test for `rostering.time_off.*` after P1.2.
- A `role_scope` test: a frontline-only user hitting `/operations/rostering` lands on `/my-day`, and now-redirected `/rostering` does the same after the redirect chain.
- A clock-in concurrency test (P0.1 evidence): start a session via one endpoint, attempt to start a second via each of the others; assert rejection or graceful merge.

**Manual smoke (browser paths)**
- Scheduler login → `/operations/rostering` → conflicts, coverage, suggestions, templates, publish review.
- Scheduler login → `/operations/timesheets/approvals` → approve, return, bulk.
- Frontline login → `/my-day` → start/complete a shift, clock-in/out, submit handover, submit timesheet, request leave (`/hr/my/leave`).
- HR admin login → `/hr/training/catalog`, `/hr/compliance`, `/hr/compliance/vetting`, `/hr/people`, `/hr/payroll`.
- Staff profile → `/staff/{id}` → credentials, availability, assignments. Confirm no broken link to `/staff/{id}/training` (stub).
- Old bookmark probe: hit `/rostering` and `/shifts/{id}` and confirm both 301/308 to operations.

**Sidebar audit**
- Open the app as scheduler and as frontline; confirm nav matches §1 ownership and that no link points at a disabled stub or duplicate.

---

## 6. Why no larger refactor

The architecture is already on the right side of every boundary that matters: Operations is canonical for scheduling, HR owns the employment lifecycle, Staff is correctly thin, Attendance has been deliberately scoped, and `routes/shifts.php` already demonstrates the right legacy-redirect pattern. The duplication that does exist is either (a) intentional bridges (`training.courses.*` → `hr.training.*`), (b) defensive OR-permission lists, or (c) one stale legacy name (`rostering.index`). None of these justify a rebuild. P0 is one verification + one docblock; P1 is six small cleanups; P2 is deferred. That's the minimum to ship.
