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

### PHASE 3 — Inbox query + conflict/balance payload ⬜
- [ ] 3.1 `LeaveService::pendingInbox()` — cross-page, SLA-ordered, 4 segments (Awaiting my decision / Escalated to me / All pending / Recently decided); `inbox` prop
- [ ] 3.2 Per-request `rosterConflict` + `balanceImpact` in list payload (batch-loaded, no N+1)

### PHASE 4 — PH-aware hours + part-day [M4] ⬜
- [ ] 4.1 `calculateRequestedHours` skips `HrPublicHoliday` (tenant+region) via `PublicHolidayCalendar`
- [ ] 4.2 Wire `period` (half_day_am/pm) through request rules → hours calc → projection (M4) → calendar

### PHASE 5 — Shared modal consolidation + self-service preview ⬜
- [ ] 5.1 Add `mode='self'|'manager'` to `LeaveRequestDialog`; replace both `MyHrLeaveWizard` mounts; delete `my-hr-leave-wizard.tsx`
- [ ] 5.2 Enrich modal: PH-aware computed hours, part-day, insufficient soft-warn, shift-conflict, approver+SLA (server preview, no days×8)
- [ ] 5.3 `LeaveService::previewRequest()` + `GET /hr/my/leave/preview` → `MyHrController::previewLeave`

### PHASE 6 — Adjust/ledger + export + calendar feed + pending signal + collapse reads ⬜
- [ ] 6.1 `POST /hr/leave/balances/adjust` + `GET /hr/leave/balances/{user}/ledger` (gated manage); `LeaveService::adjustBalance()`
- [ ] 6.2 Export CSV/Excel(=CSV)/PDF for requests/balances/reports (reuse `streamDownload` + dompdf; CSV-injection guard)
- [ ] 6.3 On-page Calendar feed (`LeaveService::calendarFeed()`, lazy prop when `?tab=calendar`)
- [ ] 6.4 Eligibility pending-leave WARNING (`checkPendingLeave`, overrideable) — gated on Phase 2
- [ ] 6.5 Collapse duplicate eligibility leave reads — GATED on Phase 2 landing (test-first interim)
- [ ] 6.6 Site-scoped escalation fallback + surface assigned approver

### PHASE 7 — Frontend hub redesign (5-tab) ⬜
- [ ] 7.1 Real in-page tabbed hub (Overview/Requests/Approvals/Calendar/Balances/Reports) via `?tab=`; fold balances/reports; keep Holidays behind "More" overflow (NOT delete)
- [ ] 7.2 Approvals tab: segmented cross-page queue + conflict/balance/SLA badges + bulk bar + right-click ctx menu
- [ ] 7.3 Calendar tab pane (PH shading, pending dashed bars, site filters, coverage banner, week/day toggle)
- [ ] 7.4 Balances tab: row → immutable ledger drawer + Adjust button
- [ ] 7.5 Reports tab: export split-button, by-site utilisation, relocate type donut
- [ ] 7.6 Hero quick-actions + "Needs you" chips + on-leave Mix/Rate donut rail (shared `PageHero`)
- [ ] 7.7 Wizard/modals/confetti/toaster (adjust modal, ledger drawer, ctx menus, confetti on approve)

### PHASE 8 — Replace native dialogs + retire legacy surfaces ⬜
- [ ] 8.1 Replace `show.tsx` native `confirm()/alert()` with AlertDialog + required-reason Decline dialog
- [ ] 8.2 Delete dead `create.tsx`; convert Requests "View" → in-hub detail modal (keep `show.tsx` as deep-link fallback)

---

## New discoveries (handover missed)
1. `create.tsx` is dead UI (route already redirects to hub, test asserts it) — delete in 8.2.
2. `show.tsx` is legacy full-page in a modal-first design — convert in 8.2.
3. `holidays.tsx` PH-CRUD tab is EXTRA (not in 5-tab design) — keep behind "More", do NOT silently delete.
4. Pending-leave overlay already shipped on roster — only eligibility decision-time signal missing (6.4).

## Definition of done
All gap items DONE+verified; duplicate modal gone (one `LeaveRequestDialog mode=self|manager`); Approvals = true cross-page SLA queue; roster/HR can't disagree on who's off; clean build/types/lint; Pest green; screenshots match `Leave Hub.dc.html`; merged→origin/main; deployed; Chrome-verified on .com.
