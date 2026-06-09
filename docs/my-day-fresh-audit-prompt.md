# My Day — fresh-context audit prompt

**Purpose:** paste the block below into a **new** Claude session to get an independent,
adversarial re-audit of the My Day change shipped to main on 2026-06-09 (commit
`946e129b`, merge `a6d1454b`). It's written to stand alone, though a fresh session will
also auto-load the project memory (`memory/project_my_day_audit.md`).

**Companion docs:** `docs/my-day-audit-fix-plan.md` (what was implemented) ·
`docs/my-day-followups-plan.md` (deferred F1–F4).

---

```
Do an independent, adversarial audit of the "My Day" change that was just merged to
main (commits 946e129b + merge a6d1454b) and is auto-deploying to oblivionfindings.com.
Treat the prior session as UNTRUSTED — re-derive everything, don't rubber-stamp. The
spec it implemented is docs/my-day-audit-fix-plan.md (tasks M1–M5, O1–O2, H1–H2, A1–A4,
T1–T3, N1, G1, C1, X1–X2). It was Codex-implemented, then a prior Claude session found
+ fixed a critical regression. Your job is to find what's still wrong.

Scope: /my-day (app/Http/Controllers/MyTasksController.php, MyDayMedicationsController,
MyDayActionsController, resources/js/pages/my-day/**) and every downstream flow it
touches — medications/eMAR, clinical observations, shift handover, attendance/clock,
timesheets, checklists, notifications.

Focus hardest on these (highest risk):
1. CATCH-ALL FAIL-SOFT (this already bit once): the meds/MyTasks code wraps fetches in
   `try { ... } catch (\Throwable) { return [] }`. A missing `use` import + a Carbon
   type-hint mismatch were SWALLOWED and silently emptied /meds/today (showed 0 meds).
   Hunt the whole touched diff for ANY other swallowed fatal: missing model imports,
   `Illuminate\Support\Carbon` vs `Carbon\Carbon` hint/arg mismatches, undefined
   methods/props. grep every changed PHP file's class references against its imports.
2. TIMEZONE CONVERGENCE (M3, whole MAR module): MarScheduleService now serves dose slots
   in worker-tz (Pacific/Auckland) with utcDayWindow/utcSlotWindow + getRawOriginal→UTC
   read-back. Verify /my-day, /meds/today, and the eMAR MAR grid now AGREE on a dose's
   time, and an administration recorded on any surface reconciles on the others (no
   residual 12h offset). Check EVERY caller passes worker-tz dates.
3. MED SAFETY: confirm My Day give/refuse truly route through EnhancedMarService —
   controlled-drug witness/stock/reason_code actually enforced (not bypassable), and
   no duplicate/double-dose rows (idempotency) under double-submit.
4. Wrong-client observation (O1): confirm the shift-scoped endpoint records against the
   selected co-resident and rejects off-roster client_id.
5. Deferred items the prior session did NOT fix — confirm impact + flag: N+1 in
   getMedicationsDue + WorkerMedsController::medicationsDue (per-slot query on the 60s
   refresh); T3 "no shift today" → ensureTodayTimesheet returns a validation error with
   no front-end onError (button silently no-ops); A2 break cap 240-vs-600 + default
   30-vs-0 not unified. (These are written up in docs/my-day-followups-plan.md.)
6. The /my-day "Mark as given" undoable button produced NO toast/POST under browser
   automation last session (useUndoableAction/showUndoToast — PRE-EXISTING code; likely
   Chrome background-tab timer throttling, not a real bug). Verify manually in a real,
   FOREGROUND browser tab whether it actually works.

How to verify:
- Tests: php artisan test --filter=... NON-PARALLEL (this repo's per-worker DBs aren't
  migrated). Run the meds/clinical/attendance/My Day suites. tsc 0 + npm run build.
- Browser: oblivionfindings.com is the auto-deployed dev server (log in as demo admin).
  Test /meds/today, /my-day, and the eMAR MAR grid for the same client/date and confirm
  they agree. (Local Herd alt: oblivionfindings.test needs Herd Desktop running on PHP
  8.4 — the site was mis-isolated to 8.3; and delete public/hot if pages render blank.)

Deliverable: a prioritized findings list — each with [severity] + file:line, CONFIRMED/
REFUTED for the focus items above, plus any NEW bugs. Do not fix anything; report first.
```
