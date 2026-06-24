# Leave & Absence Redesign — Progress Tracker

**Source:** `Downloads/HR Leave redesign prompt.zip` (handover §0–§8 + `Leave Hub.dc.html` design).
**Branch/worktree:** `claude/elated-heisenberg-8a5d79`.
**Ground truth:** `HrLeaveRequest` = single source of truth; `StaffTimeOff` = roster-read projection; everything routes through `LeaveService`. NZ locale/statute. `canDo()` for authz (no `Gate::before`). App is effectively single-tenant (tenant scoping = defensive/dormant).

**Verification env:** worktree has its OWN real `vendor/` (composer install — autoload resolves to worktree, isolated from parent). `node_modules` junctioned from parent (frontend tooling, safe). PHP tests run against throwaway DB `oblivion_findings_loop_test` via `phpunit.loop.xml` (NEVER the dev `oblivion_findings_codex_test`). Both loop-infra files are in `.git/info/exclude`.

---

## Already healthy — DO NOT REBUILD (audit verified)
- Accrual is hours-based + config-driven (`ProcessLeaveBalanceAccrualJob` reads `config('hr.leave.*')`; ledger `hours_delta` decimal). Only cadence hardcoded — forward-compat note only.
- Alt/lieu days already accrue (`AlternativeHolidayService` from `TimesheetApprovalService:113`) AND render in balances. Label polish optional.
- All leave routes / console schedules / `LeaveService` surface / models / perm keys exist. `hr.leave.viewAny/viewOwn/approve/manage` seeded `RbacSeeder:389-392`. No `hr.leave.view` key (correct).
- `MyHrController::submitLeave` already accepts doc upload, all 10 types, engine hours. Only the pre-submit preview read-path is missing (Phase 5.3).
- Pending-leave overlay already on roster (`RosteringController:519-541`); only eligibility decision-time signal missing (6.4).

## Migrations (additive/nullable/constraint-only — run local autonomously per standing policy)
- **M1** `staff_time_offs.tenant_id` (nullable, indexed) + backfill — Phase 2
- **M2** `staff_time_offs.hr_leave_request_id` (FK nullable, `constrained->nullOnDelete`) + backfill — Phase 2
- **M3** `hr_leave_requests.time_off_id` → real FK `nullOnDelete` (null orphans FIRST) — Phase 2
- **M4** `staff_time_offs.period` (string, default `full_day`) — Phase 4

---

## Build order / status

### PHASE 1 — Gate fix + dead-code cleanup ✅ DONE (no migration; manage-only-200 test green in Phase 2)
- [x] 1.1 `routes/hr.php` approval group gate → `permission:hr.leave.approve|hr.leave.manage`
- [x] 1.2 Delete 3 stray root files (`HrLeaveRequest.php`, `HrRosteringContract.php`, `ComplianceMatrixService.php`)
- [x] 1.3 Delete dead `app/Domain/Hr/Services/HrRosteringContract.php` interface (zero callers)
- [x] 1.4 Delete uninvoked `HrLeaveRequestPolicy` + its `AuthServiceProvider` import/mapping
- Verified: php-lint clean; route:list shows combined gate; manage-only user gets 200 on `leave.show`.

### PHASE 2 — FK + tenant_id + observer + two-way sync [M1–M4] ✅ DONE (6 Pest green, 20 assert)
- [x] 2.1 Migration `2026_06_24_000001` (M1 tenant_id + M2 hr_leave_request_id FK + M3 time_off_id→FK + M4 period) with backfill (orphans nulled before FK add); `StaffTimeOff` fillable+`leaveRequest()`+`scopeForTenant`+period default; `HrLeaveRequest::timeOff()`; `approveRequest` stamps tenant_id+hr_leave_request_id+period
- [x] 2.2 `HrLeaveRequestObserver` (edit re-sync of approved→projection via `LeaveService::syncApprovedProjection`); roster `StaffTimeOffController::store(type=leave)` → `LeaveService::createRosterLeave` (auto-approved, reserves balance, visible to HR); `unavailable`/`training` tenant-stamped; `destroy()` of a linked projection blocked. Test: `tests/Feature/Hr/LeaveProjectionSyncTest.php`

### PHASE 3 — Inbox query + conflict/balance payload ✅ DONE (3 Pest green, 46 assert)
- [x] 3.1 `LeaveService::pendingInbox()` — cross-page, SLA-ordered, 4 segments; `inbox` prop. Verified bulk-select sees all 22 pending, not just page 1.
- [x] 3.2 `annotateRequestsContext()` batch (no N+1) → per-request `roster_conflict` + `balance_impact`; shared `transformLeaveRow()`. Test: `LeaveInboxTest.php`

### PHASE 4 — PH-aware hours + part-day [M4] ✅ DONE (3 Pest green)
- [x] 4.1 `calculateRequestedHours` injects `PublicHolidayCalendar`, skips stat days (national default, region-aware)
- [x] 4.2 `period` wired through form rules (+multi-day half-day rejection) → submitRequest → hours calc → projection. Test: `LeaveHoursCalculationTest.php`

### PHASE 5 — Shared modal consolidation + self-service preview ◐
- [x] 5.2 `LeaveRequestDialog` enriched: `mode='self'|'manager'`, part-day `period` (single-day), server-driven review preview (engine hours, balance before→after, insufficient soft-warn, roster conflict, approver+SLA), confetti on self-submit. tsc clean.
- [x] 5.1 **Duplicate modal eliminated**: both `MyHrLeaveWizard` mounts (`pages/hr/my/{index,leave}.tsx`) → `<LeaveRequestDialog mode="self">` (pages transform `leaveTypes` string[]→{value,label} inline; `currentUser` from `myHr.profile.name`; `initial`/duplicate seed wired via new dialog prop). Deleted `my-hr-leave-wizard.tsx` + barrel export; moved `LeaveBalanceLite` local. ONE shared modal now.
- [x] 5.3 `LeaveService::previewRequest()` + `GET /hr/leave/preview` (manager) + `GET /hr/my/leave/preview` (self). Test: `LeaveBalanceAdjustTest.php`

### PHASE 6 — Adjust/ledger + export + calendar feed + pending signal + collapse reads ◐
- [x] 6.1 `POST /hr/leave/balances/adjust` (credit/debit/set_opening) + `GET /hr/leave/balances/{user}/ledger`; `LeaveService::adjustBalance()`+`balanceLedger()` (no migration — entry_type is plain string). Test: `LeaveBalanceAdjustTest.php`
- [x] 6.2 Export CSV/Excel(=CSV)/PDF for requests/balances/reports (streamDownload + dompdf; formula-injection guard). Test: `LeaveExportTest.php`
- [x] 6.3 On-page Calendar feed (`LeaveService::calendarFeed()` — approved+pending entries, grouped people, PH shading; lazy `calendar` prop only when `?tab=calendar`). Test: `LeaveCalendarFeedTest.php`
- [ ] 6.4 Eligibility pending-leave WARNING (`checkPendingLeave`, overrideable) — roster subsystem; pending OVERLAY already on roster (`RosteringController`). DEFERRED to post-frontend backend cleanup (low priority, not user-facing leave feature)
- [ ] 6.5 Collapse duplicate eligibility leave reads — audit says risky; SAFE interim = consistency test only, no read removal. DEFERRED to post-frontend cleanup
- [x] 6.6 Site-scoped escalation fallback (`getEscalationTarget` prefers same-`primary_site_id` approver, closest role first, before global). Assigned approver already surfaced via 3.1 awaiting-my-decision segment. (Dormant in single-site demo.)

### PHASE 7 — Frontend hub redesign (5-tab) ◐
- [ ] 7.1 Real in-page tabbed hub (Overview/Requests/Approvals/Calendar/Balances/Reports) via `?tab=`; fold balances/reports; keep Holidays behind "More" overflow (NOT delete)  ← still uses LeaveTabs separate-page nav
- [x] 7.2 Approvals section rebuilt as the segmented **cross-page** inbox (Awaiting my decision/Escalated to me/All pending/Recently decided) sourced from `inbox` prop — bulk-select now reaches every pending request; per-row roster-conflict + balance-impact (before→after, ⚠ insufficient) + doc 📎 + escalated-from + SLA/status badges + empty states. (right-click ctx menu = follow-up)
- [ ] 7.3 Calendar tab pane (PH shading, pending dashed bars, site filters, coverage banner, week/day toggle)
- [x] 7.4 Balances: clickable rows → immutable **ledger drawer** (fetches `/balances/{user}/ledger`) + **Adjust / opening balance** modal (credit/debit/set_opening → `/balances/adjust`) + **Export** button. tsc/eslint clean.
- [x] 7.5 Reports: **Export split-button** (CSV/Excel/PDF → `/reports/export`). (by-site utilisation + type-donut relocation = follow-up polish)
- [ ] 7.6 Hero quick-actions + "Needs you" chips + on-leave Mix/Rate donut rail (shared `PageHero`)
- [~] 7.7 Adjust modal + ledger drawer done (in 7.4); ctx menus + confetti-on-approve = follow-up

### PHASE 8 — Replace native dialogs + retire legacy surfaces ◐
- [x] 8.1 `show.tsx` native `confirm()/alert()` replaced with an AlertDialog confirm (approve/decline) + toast for the required decline reason. tsc/eslint clean.
- [x] 8.2 Deleted dead `create.tsx` (route already redirects to hub; confirmed zero real inbound links). ("View" → in-hub detail modal = follow-up; `show.tsx` remains the detail page)

---

## New discoveries (handover missed)
1. `create.tsx` is dead UI (route already redirects to hub, test asserts it) — delete in 8.2.
2. `show.tsx` is legacy full-page in a modal-first design — convert in 8.2.
3. `holidays.tsx` PH-CRUD tab is EXTRA (not in 5-tab design) — keep behind "More", do NOT silently delete.
4. Pending-leave overlay already shipped on roster — only eligibility decision-time signal missing (6.4).

## Definition of done
All gap items DONE+verified; duplicate modal gone (one `LeaveRequestDialog mode=self|manager`); Approvals = true cross-page SLA queue; roster/HR can't disagree on who's off; clean build/types/lint; Pest green; screenshots match `Leave Hub.dc.html`; merged→origin/main; deployed; Chrome-verified on .com.
