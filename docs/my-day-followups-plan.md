# My Day audit — deferred follow-ups (for Codex)

**Date:** 2026-06-09
**Author:** Claude (deferred items from the My Day audit; main fixes already shipped to main in a6d1454b).
**Scope:** The non-blocking follow-ups intentionally left out of `docs/my-day-audit-fix-plan.md`. Each is small and independent.

> ## ⚡ STATUS — read first
> The core My Day audit fixes are **shipped** (commit `946e129b`, merged `a6d1454b`,
> deployed to oblivionfindings.com). These four items were **deferred** as non-blocking.
> Do them in any order; they don't depend on each other. Line numbers are from
> 2026-06-09 — re-read before editing.

---

## 0. TL;DR

| # | Sev | Item |
|---|-----|------|
| F1 | 🟡 | N+1: `getMedicationsDue` + `WorkerMedsController::medicationsDue` issue one admin query per dose-slot, on the 60s-refresh hot path |
| F2 | 🟡 | "Today's timesheet" button silently no-ops when the worker has no shift today (no FE error handling) |
| F3 | 🟡 | `break_minutes` cap (240 vs 600) and default (30 vs 0) are inconsistent across surfaces — needs a product decision (D1) |
| F4 | ⚪ | Verify the My Day "Mark as given" undoable button in a **foreground** browser (prior "no POST" was almost certainly Chrome background-tab timer throttling, not a real bug) |

---

## 1. How to run & verify

- **Local:** Herd `https://oblivionfindings.test` (needs Herd Desktop on **PHP 8.4** — `herd isolate 8.4` if it's on 8.3; delete `public/hot` if pages render blank). Or `https://oblivionfindings.com` (auto-deployed main, demo admin).
- **Tests:** `php artisan test --filter=...` **non-parallel** (per-worker DBs aren't migrated here). `npm run types` must be 0; `npm run build` must pass.
- Convention reminder: store UTC, convert at `app.worker_timezone` (Pacific/Auckland); don't add catch-all `catch (\Throwable)` that swallows fatals (that pattern hid the regression we just fixed — always `report($e)`).

---

## F1 — Kill the medications-due N+1 (🟡)

**Problem.** Both frontline meds lists generate slots per medication per day and then run **one `ClientMedicationAdministration` query per in-window slot** to reconcile given/refused state. On a multi-resident house (e.g. 6 residents × ~5 meds) that's ~30–45 queries, re-run every 60s by the `/my-day` live refresh.
- [app/Http/Controllers/MyTasksController.php:645](../app/Http/Controllers/MyTasksController.php) — `getMedicationsDue`: per-slot `ClientMedicationAdministration::query()->where(...)->whereBetween('scheduled_for', [$slotStartUtc,$slotEndUtc])->latest('id')->first()`.
- [app/Http/Controllers/Emar/WorkerMedsController.php:262](../app/Http/Controllers/Emar/WorkerMedsController.php) — `medicationsDue`: same pattern.

**Fix.** Pre-fetch once, match in memory — **mirror the pattern Codex already wrote** in [app/Http/Controllers/TodayDashboardController.php:64-95](../app/Http/Controllers/TodayDashboardController.php) (single `ClientMedicationAdministration::whereIn('client_id',$ids)->whereBetween('scheduled_for',[dayStartUtc,dayEndUtc])->get()->keyBy(client_id|client_medication_id|UTC-minute)`):
1. Before the medication loop, compute the UTC day-window covering `[$windowStart, $windowEnd]` (use `MarScheduleService::utcDayWindow`, accounting for the window possibly spanning 2 local days).
2. One query: all administrations for `$clientIds` in that window, `keyBy(fn ($a) => $a->client_medication_id.':'.Carbon::parse($a->getRawOriginal('scheduled_for'),'UTC')->format('Y-m-d H:i'))`.
3. In the loop, look up `$key = $med->id.':'.$scheduled->copy()->utc()->format('Y-m-d H:i')` in memory instead of querying. Keep the exact given/refused/withheld(/missed) status logic.
- Keep the snooze `Cache::has` as-is unless the cache driver is `database` (then batch with `Cache::many`); it's cheap on redis/file.

**Acceptance:** identical output to today, but a `/my-day` (and `/meds/today`) load issues **one** administration query regardless of resident/med count. Add a query-count assertion (`DB::enableQueryLog()` around the controller, or a feature test seeding N residents and asserting the administration table is queried once). Existing `MyDayMedicationsDuePayloadTest` / `WorkerMedsTodayPayloadTest` must stay green.

---

## F2 — "Today's timesheet" silent no-op when no shift today (🟡)

**Problem.** [resources/js/pages/my-day/index.tsx](../resources/js/pages/my-day/index.tsx) `handleOpenTimesheets` does `router.post('/my-tasks/timesheet/ensure-today', {}, { preserveScroll: true })` with **no `onError`**. When the worker has no shift today, [app/Http/Controllers/MyDayActionsController.php:92-95](../app/Http/Controllers/MyDayActionsController.php) returns `back()->withErrors(['timesheet' => 'No shift today to write a timesheet against.'])` — and nothing on `/my-day` reads `errors.timesheet`, so the button appears dead. (A prior audit doc claimed a `window.alert` was added — it wasn't.)

**Fix.** Add `onError` to the `router.post` that surfaces the message. The app already wires **sonner** globally (`app.tsx`) — `import { toast } from 'sonner'` and `onError: (errors) => toast.error(errors.timesheet ?? 'No timesheet to open for today.')`. (Optionally also guard the hero button's enabled state when `props.shifts` is empty, but the toast is the minimum.)

**Acceptance:** a worker with no shift today taps "Today's timesheet" and sees a clear message instead of nothing. The existing draft/returned-exists path and the flash `open_timesheet_id` → review-dialog path are unchanged. Add a small e2e/vitest or at least manually verify.

---

## F3 — Unify `break_minutes` cap + default (🟡, needs Decision D1)

**Problem.** Validation ceilings and defaults disagree across surfaces:
- Cap: clock-out **240** ([app/Http/Controllers/AttendanceController.php:154](../app/Http/Controllers/AttendanceController.php)) vs timesheet create/edit **600** ([app/Http/Controllers/TimesheetController.php:626,924,1072](../app/Http/Controllers/TimesheetController.php)).
- Default: `ensureTodayTimesheet` seeds `expected_break_minutes ?? 30` ([app/Http/Controllers/MyDayActionsController.php:115](../app/Http/Controllers/MyDayActionsController.php)); `DraftTimesheetService` uses `?? 0` ([app/Domain/Shifts/Timesheets/Drafts/DraftTimesheetService.php:74](../app/Domain/Shifts/Timesheets/Drafts/DraftTimesheetService.php)). Clock-out reconciles with `max(existing, session)` ([:134](../app/Domain/Shifts/Timesheets/Drafts/DraftTimesheetService.php)) — so a no-break shift whose draft was seeded at 30 keeps a 30-min break deducted.

**Fix (after D1):**
- Cap: set one shared maximum (recommend **240** everywhere — a 10h "break" is not a real break; lower `TimesheetController` 600→240, or extract a shared rule/const).
- Default: pick one (recommend `expected_break_minutes ?? 0` so a no-break shift doesn't fabricate a 30-min deduction; or keep 30 if payroll policy mandates a default unpaid break — Stephan's call).

**Acceptance:** the same break value is accepted/seeded regardless of entry surface; a no-break shift doesn't silently deduct a default break unless that's the chosen policy. Update/extend `AttendanceClockWorkflowTest` + `TimesheetControllerTest` accordingly.

---

## F4 — Verify the "Mark as given" undoable button (⚪ verify-only, likely a non-bug)

**Context.** During the audit, clicking `/my-day` → "Mark as given" produced no toast and no `POST /my-day/medications/{id}/administer` under **Chrome MCP automation**. But the flow is sound by inspection: [resources/js/components/undo-toast.tsx](../resources/js/components/undo-toast.tsx) uses `window.setTimeout(commit, 5000)` + sonner, and [resources/js/hooks/use-undoable-action.ts](../resources/js/hooks/use-undoable-action.ts) `flush()`es on unmount — so the POST should fire within 5s or on navigation regardless of toast visibility. The most likely cause of the no-op is **Chrome throttling `setTimeout` in a backgrounded/unfocused tab** (the automated tab wasn't foreground), not a real defect. These are pre-existing components (not changed by the audit work).

**Task.** Reproduce manually in a **foreground, focused** browser tab as a frontline worker with a due dose: click the give circle, **wait 5s without switching tabs**, and confirm (a) the "Marking dose given…" toast with Undo appears, (b) after 5s a `POST …/administer` fires, (c) the row flips to "Given". 
- If it works → no fix; just note it's an automation artifact.
- If it genuinely fails foreground → then investigate (is `<Toaster/>` mounted in `app.tsx`? does `runUndoable`'s `onCommit` reach `router.post`?) and fix. Apply the same check to the refuse + clock-out + timesheet-submit undoable paths (they share `useUndoableAction`).

---

## 2. Decision needed from Stephan
- **D1 (F3):** What is the maximum valid `break_minutes` (240 vs 600), and should a no-break shift default to a 30-min unpaid break or 0?

## 3. Suggested PR slicing
- **PR-followups-perf** — F1 (self-contained, add query-count test).
- **PR-followups-ux** — F2 (+ F4 verification notes).
- **PR-followups-break** — F3 (gated on D1).
