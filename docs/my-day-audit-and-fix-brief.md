# My Day — audit & fix brief (hand to a fresh Claude session)

**One-file handoff.** Give this to a fresh Claude session. Goal: **independently re-audit
the My Day change that shipped to main on 2026-06-09, FIX anything still wrong, and
implement the deferred follow-ups (F1–F4).** Don't just report — fix, verify, and report.
Treat the prior session as UNTRUSTED; re-derive everything.

- App: NZ Supported Living CRM, Laravel + Inertia/React, PHP 8.4.
- Shipped commits: `946e129b` (implementation + critical fix) → merge `a6d1454b` on `main`, auto-deployed to oblivionfindings.com.
- Spec that was implemented: `docs/my-day-audit-fix-plan.md` (tasks M1–M5, O1–O2, H1–H2, A1–A4, T1–T3, N1, G1, C1, X1–X2).

---

## 0. Already fixed by the prior session (your baseline — verify, don't redo)
1. `app/Services/MarScheduleService.php` → `use Carbon\Carbon` (its methods had been narrowed to `Illuminate\Support\Carbon`, causing a `TypeError` for callers passing `Carbon\Carbon`).
2. `app/Http/Controllers/Emar/WorkerMedsController.php` → added missing `use App\Models\ClientMedicationAdministration;` + `report($e)` in the `medicationsDue` catch. **These two faults were swallowed by a `catch (\Throwable) { return [] }` and silently made `/meds/today` show 0 meds.**
3. `tests/Feature/MedicationControllerTest.php` → 2 stale assertions changed from UTC `now()` to worker-tz (the MAR now redirects to the worker-local "today").

Confirm these are present and correct, then move on.

---

## 1. Setup, conventions & how to verify (READ FIRST — these bit the last session)
- **Tests:** `php artisan test --filter=...` **NON-PARALLEL** (this repo's per-worker DBs aren't migrated → thousands of false failures with `--parallel`). `npm run types` must be 0; `npm run build` must pass.
- **DB:** `.env` + `phpunit.xml` both use `oblivion_findings_codex_test`; tests run in transactions (they don't wipe seeded demo data).
- **Timezone:** store UTC; convert at `app.worker_timezone` (Pacific/Auckland). Call `->utc()` before storing a tz-aware Carbon. When reading an Eloquent datetime back to serialize, prefer `getRawOriginal(col)` parsed as UTC (Eloquent formats a Carbon in its own tz and can reintroduce the offset bug).
- **Never** add `catch (\Throwable)` that returns empty without `report($e)` — that pattern hid the regression above.
- **Permissions are seeded, not migrated**, and deploys skip seeders — a new permission-gated feature 403s until its `*PermissionsSeeder` is run `--force`. No super-admin bypass in `canDo()`.
- **Local browser (Herd):** `https://oblivionfindings.test` needs Herd Desktop running on **PHP 8.4** (`herd isolate 8.4` if it's on 8.3 — it was mis-set); if pages render blank, delete `public/hot` (a stale file forces a dead Vite :5173 dev server). `php artisan serve` cannot bind a port in the agent sandbox — use Herd or the deployed site.
- **Deployed dev:** `https://oblivionfindings.com` auto-pulls + builds main (~5–8 min); log in as demo admin. Frontline demo user: `sw1@demo.test` / `password` (its lifecycle seed data may be stale-dated — a shift "today" with in-window meds may need re-seeding).

---

## PART A — Re-audit and FIX anything still wrong

Scope: `/my-day` (`app/Http/Controllers/MyTasksController.php`, `MyDayMedicationsController.php`, `MyDayActionsController.php`, `resources/js/pages/my-day/**`) and every downstream flow it touches — medications/eMAR, clinical observations, shift handover, attendance/clock, timesheets, checklists, notifications.

Audit hardest on these; **fix what you find** (with a test):

1. **Swallowed fatals (this class already bit once).** Many fetches are wrapped in `try { ... } catch (\Throwable) { return [] }`. Grep EVERY changed PHP file's class references against its `use` imports, and check `Illuminate\Support\Carbon` vs `Carbon\Carbon` hint/arg mismatches and any undefined method/prop. A fatal here shows as an empty section, not an error. Add `report($e)` to any remaining swallow-and-return-empty catches on safety-critical data (meds, incidents).
2. **Timezone convergence across the whole MAR module.** `MarScheduleService` serves dose slots in worker-tz with `utcDayWindow`/`utcSlotWindow`/`parseWorkerDateTime` + `getRawOriginal→UTC` read-back. Verify `/my-day`, `/meds/today`, and the eMAR MAR grid AGREE on a dose's time, and an administration recorded on any surface reconciles on the others (no residual 12h offset). Check every caller (`EmarController`, `MedicationsApiController`, `ShiftController`, `ClientMarController`, `GuidedRoundController`, `TodayDashboardController`, `SendMedicationAlerts`) passes worker-tz dates.
3. **Med safety.** Confirm My Day give/refuse truly route through `EnhancedMarService` — controlled-drug witness/stock/`reason_code` actually enforced (not bypassable), and no duplicate/double-dose rows under double-submit (idempotency).
4. **Wrong-client observation (O1).** `app/Http/Controllers/Clinical/ShiftClinicalController.php::store` must record against the selected co-resident `client_id` (validated as on the shift's site roster) and reject off-roster ids.
5. **Cross-surface consistency.** Handover digest read+acknowledge (`props.handover`), notifications digest (`props.notifications`), guided-round banner (`active_round`), and view-only checklists all populate and act correctly.

---

## PART B — Implement the deferred follow-ups (F1–F4)

### F1 🟡 — Kill the medications-due N+1
Both lists run **one `ClientMedicationAdministration` query per in-window dose-slot**, re-run every 60s by the `/my-day` live refresh (~30–45 queries for a 6-resident house).
- `app/Http/Controllers/MyTasksController.php:645` — `getMedicationsDue`, the per-slot `ClientMedicationAdministration::query()->...->whereBetween('scheduled_for',[slotStart,slotEnd])->latest('id')->first()`.
- `app/Http/Controllers/Emar/WorkerMedsController.php:262` — `medicationsDue`, same pattern.
- **Fix:** pre-fetch once and match in memory — mirror the pattern already in `app/Http/Controllers/TodayDashboardController.php:64-95` (single `whereIn('client_id',$ids)->whereBetween('scheduled_for',[dayStartUtc,dayEndUtc])->get()->keyBy(client_medication_id.':'.UTC-minute)`; build the day-window via `MarScheduleService::utcDayWindow`, allow for the window spanning 2 local days). Keep the snooze `Cache::has` as-is (cheap) unless the cache driver is `database`.
- **Acceptance:** identical output, but one admin query regardless of resident/med count. Add a query-count assertion; `MyDayMedicationsDuePayloadTest` + `WorkerMedsTodayPayloadTest` stay green.

### F2 🟡 — "Today's timesheet" silent no-op when no shift today
`resources/js/pages/my-day/index.tsx` `handleOpenTimesheets` does `router.post('/my-tasks/timesheet/ensure-today', {}, { preserveScroll:true })` with **no `onError`**. When there's no shift today, `app/Http/Controllers/MyDayActionsController.php:92-95` returns `back()->withErrors(['timesheet' => 'No shift today…'])` and nothing reads it → the button looks dead.
- **Fix:** add `onError: (errors) => toast.error(errors.timesheet ?? 'No timesheet to open for today.')` (sonner is wired globally in `app.tsx`). Optionally disable the hero button when `props.shifts` is empty.
- **Acceptance:** a worker with no shift today gets a clear message; the existing draft-exists and `open_timesheet_id` flash paths are unchanged.

### F3 🟡 — Unify `break_minutes` cap + default (needs Decision D1)
- Cap: clock-out **240** (`app/Http/Controllers/AttendanceController.php:154`) vs timesheet create/edit **600** (`app/Http/Controllers/TimesheetController.php:626,924,1072`).
- Default: `ensureTodayTimesheet` seeds `expected_break_minutes ?? 30` (`app/Http/Controllers/MyDayActionsController.php:115`); `DraftTimesheetService` uses `?? 0` (`app/Domain/Shifts/Timesheets/Drafts/DraftTimesheetService.php:74`); clock-out reconciles `max(existing, session)` (`:134`) — so a no-break shift seeded at 30 keeps a 30-min deduction.
- **Fix (after D1):** one shared cap (recommend 240 everywhere) and one default (recommend `?? 0` so a no-break shift doesn't fabricate a break — unless payroll wants a default). Update `AttendanceClockWorkflowTest` + `TimesheetControllerTest`.

### F4 ⚪ — Verify the "Mark as given" undoable button (likely a non-bug)
Under browser **automation** the give button produced no toast/POST. The code is sound: `resources/js/components/undo-toast.tsx` uses `window.setTimeout(commit, 5000)` + sonner, and `resources/js/hooks/use-undoable-action.ts` `flush()`es on unmount — so it should POST within 5s or on navigation. The likely cause was **Chrome throttling `setTimeout` in a backgrounded tab**.
- **Task:** in a real, FOREGROUND tab as a frontline worker with a due dose, click the give circle, wait 5s without switching tabs, and confirm the toast + `POST …/administer` + the row flipping to "Given". If it works → no fix (automation artifact). If it genuinely fails → check `<Toaster/>` is mounted and `onCommit` reaches `router.post`, then fix (and the shared refuse/clock-out/timesheet-submit undoable paths).

---

## 2. Decision needed (gates F3)
**D1:** What is the maximum valid `break_minutes` (240 vs 600), and should a no-break shift default to a 30-min unpaid break or 0? (If unsure, implement F1/F2 first and leave F3 as a flagged TODO.)

## 3. Working method
- Work on a branch (e.g. `codex/my-day-followups`), not directly on main.
- For each fix: change → add/extend a test → run the relevant `--filter` suite non-parallel → `npm run types` + `npm run build`.
- Then browser-verify the user-facing ones (F2, F4, and the Part-A meds/tz items) on Herd or oblivionfindings.com.
- Deliver: a prioritized findings list (each `[severity]` + `file:line`, CONFIRMED/REFUTED for Part A, plus any NEW bugs), the fixes applied, and the test/verify results. Don't commit/push unless asked.

---

## RESULTS — fresh re-audit + follow-ups (2026-06-09, branch `codex/my-day-followups`, uncommitted)

### Section 0 baseline (verify, don't redo)
All three present & correct: `MarScheduleService` uses `Carbon\Carbon` (line 7); `WorkerMedsController` has `use ClientMedicationAdministration` + `report($e)` in `medicationsDue`; `MedicationControllerTest` 2 MAR assertions use worker-tz `now(config('app.worker_timezone'))`.

### PART A — re-audit verdicts
- **A1 Swallowed fatals — the shipped defect does NOT recur** (every changed file's class refs resolve to imports/same-ns/global; Carbon `Illuminate\Support` vs `Carbon\Carbon` directions all valid; model members exist). **NEW (same family), FIXED:** 5 `catch (\Throwable)` in `WorkerMedsController` swallowed med data without `report($e)` — `:203/:217` (`assignedClientIdsFor`), `:378` (`prnMedications`), `:428` (`activeRound`), `:473` (`upcomingRounds`). Added `report($e)` to all 5. (Two non-safety NOTE-level swallows left as-is: `MyDayActionsController::endOfShiftFor`, `ShiftController::coverageContextFromWindow`.)
- **A2 Timezone convergence — CONFIRMED correct.** `/my-day`, `/meds/today`, eMAR grid, guided round, today-dashboard, API, overdue cron all generate slots via `scheduledTimesForDate` (worker-tz) and match on `utcSlotWindow` against UTC-stored `scheduled_for` read back via `getRawOriginal`. What `/my-day` stores (`MyDayMedicationsController:56/111`) is the same UTC instant the eMAR queries → no residual ~12h offset. (Out-of-scope note: `EmarController::dashboard` + `MedicationsApiController` report ranges use UTC-midnight day boundaries — reporting only, not slot convergence.)
- **A3 Med safety — CONFIRMED.** Give/refuse route through `EnhancedMarService::recordAdministration`; controlled-drug witness enforced (`validateWitness`, different user + `medications.controlled.witness` + password `Hash::check`); idempotent (`DB::transaction`+`lockForUpdate`, slot-window dedupe → `duplicate=true`); stock decrement + CD register once; reason_code enforced for not-given.
- **A4 Wrong-client observation (O1) — CONFIRMED.** `ShiftClinicalController::resolveObservationClient` validates the picked `client_id` is the shift's client or same-site, else 422.
- **A5 Cross-surface consistency — CONFIRMED.** Controller sends `handover`/`notifications`/`active_round`/`shiftChecklists`; FE consumes (DigestPanel + ack PATCH, ActiveRoundBanner, ShiftChecklistsCard view/run gating).

### PART B — follow-ups implemented
- **F1 (N+1) — DONE.** New shared `MarScheduleService::administrationsForWindow()` + `slotKey()` (one keyed query over the UTC day-window, mirrors `TodayDashboardController`). Rewired `MyTasksController::getMedicationsDue` and `WorkerMedsController::medicationsDue`; removed now-dead `ClientMedicationAdministration` imports. Snooze `Cache::has` left as-is (driver is `array`, not `database`). Query-count tests assert exactly **1** admin query for 6/9 slots.
- **F2 (silent timesheet no-op) — DONE.** `index.tsx handleOpenTimesheets` now passes `onError` → `toast.error(errors.timesheet ?? …)`. Added `toast` import + `toast_dose_record_failed` label (en/mi/hook). Also hardened `handleGiveMed`/`handleRefuseMed` with `onError` so a server-rejected dose (controlled-without-witness / outside-window) surfaces instead of looking dead.
- **F3 (break cap + default) — DONE per D1 (cap 240, default 0).** `TimesheetController` `break_minutes` `max:600`→`max:240` (×3); `MyDayActionsController::ensureTodayTimesheet` default `?? 30`→`?? 0`. `AttendanceController` already 240; `DraftTimesheetService` already 0; no `?? 30` remains. **NOTE (out of D1 scope):** the HR module (`StoreTimesheetRequest`, `ClockOnBehalfRequest`, `TimeTrackingController`, `MyHrController`, `UpdateTimeEntryRequest`) still caps at **480**, and `expected_break_minutes` (planned break, different field) at **720** — left unchanged; unify separately if desired.
- **F4 (give button) — VERIFIED non-bug.** `<Toaster/>` mounted (`app.tsx:53`); `useUndoableAction` flushes on unmount; `showUndoToast` uses `window.setTimeout(commit, 5000)` → `router.post(.../administer)` — fires normally in a foreground tab (the "no POST" was background-tab `setTimeout` throttling under automation). Backend give path unit-tested. Hardened with `onError` (see F2).

### Tests / gates (all green)
- PHP (non-parallel): MyDayMedicationsDuePayload (+N+1 query-count), Emar/WorkerMedsTodayPayload (+query-count), MyDayMedicationAction, Hr/AttendanceClockWorkflow (+240 cap), TimesheetController (+cap×2, +ensure-today-default), MedicationOverdueAlerts, AttendanceClockOutBlocker, Domain/Clinical/ShiftClinicalController (O1), MyDayActiveSite/HandoverDigest/NotificationsDigest/PreShiftBriefing/PreviousShift.
- `npm run types` 0 errors · `npm run build` OK · vitest My Day specs 10 passed (incl. new F2 toast test).
- Real browser (Playwright/Chromium, built assets): my-day lifecycle-smoke, roster, pre-shift-briefing, end-of-shift, returned-timesheet — pass.

### NEW finding (out of scope, tracked separately)
- **[critical] `aria-valid-attr-value`** + **[serious] `color-contrast`** on `/my-day` (axe via `tests/e2e/my-day-a11y.spec.ts`). Pre-existing (Radix Tabs `aria-controls` to an unmounted panel; muted token `#787a83` on `#f7f8fd` = 4.03). Not caused by F1–F4; spun off to its own a11y task (touches shared UI/tokens).
