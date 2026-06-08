# My Day audit — fix plan (for Codex)

**Date:** 2026-06-08
**Author:** Audit by Claude (multi-agent, cross-checked). Implementation handed to Codex.
**Scope:** `/my-day` (the frontline support-worker home, [resources/js/pages/my-day/index.tsx](../resources/js/pages/my-day/index.tsx)) and **all flows downstream of it** — medications, clinical observations, shift handover, attendance/clock, timesheets, checklists, notifications.

> ## ⚡ STATUS — read first
> Codex implementation started on 2026-06-08. The medication safety,
> medication timezone convergence, overdue alert generation, observation,
> handover, attendance/clock-out, timesheet, notification, guided-round,
> checklist, and cleanup items from this plan have been implemented and verified
> locally. D2 was resolved by converging scheduled doses on worker-local wall
> time with UTC storage. D3 was resolved by requiring manager-level capability
> for force-clocking out through clinical blockers. See **Section 5** for the
> implementation ledger and verification evidence.
>
> **Line numbers are from the audit snapshot on 2026-06-08. Re-read the cited
> code before editing — verify the anchor still matches.**

---

## 0. TL;DR — the two failure modes

The desktop `/my-day` was a redesign. It left flows broken in two distinct ways:

1. **Disconnected backend work.** [MyTasksController](../app/Http/Controllers/MyTasksController.php) still computes data the new page never renders, and **two digest tabs are bound to props the server never sends** (`props.handover`, `props.notifications`).
2. **"Lightweight" action endpoints that bypass the trusted services.** The meds give/refuse/snooze buttons write straight to the DB, skipping every safety/reconciliation gate the real eMAR enforces.

| # | Area | Severity | One-liner |
|---|------|----------|-----------|
| M1 | Meds | 🔴 | Give/refuse bypass `EnhancedMarService` → no witness/safety/stock/reason_code |
| M2 | Meds | 🔴 | Given/refused doses never clear; "meds given" stuck at 0; snooze is a no-op |
| M3 | Meds | 🔴 | My Day slots in worker-tz, eMAR slots in UTC → 12h offset, administrations never reconcile |
| M4 | Meds | 🔴 | No idempotency → duplicate/double-dose rows |
| M5 | Meds | 🟠 | Overdue-med alerts can never fire (no `pending` admin rows exist) |
| O1 | Observations | 🔴 | Shift-scoped endpoint ignores `client_id` → wrong-client (or 422) on multi-resident shifts |
| O2 | Observations | 🟡 | No value/range validation; field-level errors never render |
| H1 | Handover | 🔴 | Incoming handover **read + acknowledge is dead** on the desktop page |
| H2 | Handover | 🟠 | Loose matching can surface another resident's handover; first-arriver claims it |
| A1 | Attendance | 🟠 | Clock-out blockers invisible (non-Inertia JSON 422 + no `onError`) |
| A2 | Attendance | 🟠 | Clock-out overwrites worker-edited `break_minutes` |
| A3 | Attendance | 🟠 | Forced clock-out lets frontline self-bypass meds/incident blockers |
| A4 | Attendance | 🟡 | ±4h clock-in grace can auto-bind to the wrong adjacent shift |
| T1 | Timesheets | 🟠 | Residential/equal-split rounding rejected *after* UI says OK |
| T2 | Timesheets | 🟠 | `time_segmented` hours not validated against the window |
| T3 | Timesheets | 🟡 | Inline per-row errors never render (key mismatch); "Today's timesheet" can dead-end |
| N1 | Notifications | 🟡 | "Updates" digest tab always empty (`props.notifications` never sent) |
| G1 | Guided round | 🟡 | Resume banner dropped from `/my-day` (`active_round` computed, unused) |
| C1 | Checklists | 🟡 | Invisible to workers with `checklists.view` but not `.run` |
| X1 | Cross-cutting | 🟡 | Pervasive `try/catch (\Throwable) { return []; }` hides failures as empty sections |
| X2 | Cleanup | ⚪ | Dead payload + dead `ensure-today` endpoint |

---

## 1. How to run & verify

- **Local (preferred):** Herd site `https://oblivionfindings.test` (needs Herd Desktop). Assets: `npm run dev` / `npm run build`.
- **Remote test:** `https://oblivionfindings.com` (demo admin). Deploy webhook auto-pulls + builds (~5–8 min).
- **Tests:** run **non-parallel** and change-scoped — `php artisan test --filter=MyDay`, `--filter=Attendance`, `--filter=Mar`, etc. **Do NOT use `--parallel`** in this repo (per-worker DBs aren't migrated → thousands of false failures).
- **Frontend gates:** `npm run types` (or `tsc`) must be 0, `npm run build` must pass. Vitest specs live next to components (`*.test.tsx`).
- **Existing tests to extend, not replace:** `tests/Feature/MyDayMedicationActionTest.php`, `tests/Feature/MyDayMedicationsDuePayloadTest.php`, `tests/Feature/MyDayActiveSiteTest.php`, `tests/Feature/MyDayPreShiftBriefingTest.php`, `tests/e2e/my-day-*.spec.ts`, `resources/js/components/end-of-shift-checklist.test.tsx`.

### House conventions you MUST follow (these have bitten before)
- **Timezone:** store UTC; convert at the `app.worker_timezone` (`Pacific/Auckland`) boundary. Call `->utc()` before storing a tz-aware Carbon. (This is the root of M3.)
- **Permissions are seeded, not migrated**, and deploys skip seeders. If you add a permission, add it to the relevant `*PermissionsSeeder` and note that it must be run with `--force` on the server or the feature 403s. There is **no super-admin bypass** in `canDo()`.
- **Don't ship stubs / "coming soon" toasts** — hide an action with no backend rather than faking it.
- **Fix incidental errors you find while verifying** (console warnings, type errors) — don't dismiss them as "pre-existing".
- **Inertia vs axios:** a bare `back()` on an endpoint also hit by plain axios makes the axios call follow the 302 to a GET-only SPA route → 405. Use the `RespondsToInertiaOrJson` trait / content-negotiate. (Relevant to A1.)

---

## PHASE 1 — Medications (🔴 clinical safety). Do this first.

### Context: the trusted path already exists
The PRN button on `/meds/today` is correct: `WorkerMedsController::recordPrn` delegates to `EnhancedMarService::recordAdministration` ([app/Services/EnhancedMarService.php](../app/Services/EnhancedMarService.php)), which runs inside a `DB::transaction` + `lockForUpdate`, and enforces administrability, dose/allergy/interaction safety checks, time-window validation, **controlled-drug witness + register + stock decrement**, and a structured `reason_code` for any not-given status. **The scheduled-dose path on `/my-day` is the only one that skips all of this.** The fixes below make My Day reuse that path.

### M1 — Route give/refuse through `EnhancedMarService` (🔴)
- **Files:** [app/Http/Controllers/MyDayMedicationsController.php:31](../app/Http/Controllers/MyDayMedicationsController.php:31) (`administer`), [:72](../app/Http/Controllers/MyDayMedicationsController.php:72) (`refuse`).
- **Fix:** replace the direct `ClientMedicationAdministration::create([...])` calls with a delegation to `EnhancedMarService::recordAdministration(...)`, mirroring `WorkerMedsController::recordPrn`. Pass `status` (`given`/`refused`), `scheduled_for`, `dose_given`, and the `reason_code`/`reason` (see M2-refusal below). Keep the existing `AuditLogger` call.
- **Why:** restores witness enforcement for controlled drugs, stock decrement, safety checks, and idempotency (the service already does `lockForUpdate` + an existing-administration check — this also resolves **M4**).
- **Acceptance:**
  - Giving a controlled drug from `/my-day` requires the same witness flow the eMAR requires (or is blocked if witness can't be supplied from this surface — see Decision D1).
  - Double-tapping "Give" does **not** create a second `ClientMedicationAdministration` row for the same `(client_medication_id, scheduled_for)`.
  - Stock decrements once, register entry written for controlled drugs.
- **Tests:** extend `MyDayMedicationActionTest.php` — assert no duplicate on double-submit; assert controlled-drug give without witness is rejected; assert stock decremented.

### M2 — Reconcile the rail against administrations + honour snooze (🔴)
- **File:** [app/Http/Controllers/MyTasksController.php:550](../app/Http/Controllers/MyTasksController.php:550) (`getMedicationsDue`).
- **Problems being fixed:**
  - It never queries `ClientMedicationAdministration`, so a dose given via My Day **or** the eMAR still shows `overdue` on the next 60s refresh ([index.tsx:147](../resources/js/pages/my-day/index.tsx:147) live-refresh).
  - It only ever emits `overdue|due|upcoming`, so `medsGiven = filter(status==='given')` ([index.tsx:245](../resources/js/pages/my-day/index.tsx:245)) is always 0.
  - The snooze cache key written by [MyDayMedicationsController::snooze:126](../app/Http/Controllers/MyDayMedicationsController.php:126) is never read here, so snooze is a no-op.
- **Fix:**
  1. For each generated slot, look up an administration for that `(client_medication_id, scheduled_for)`. If `given`/`refused`/`withheld`, set the row `status` accordingly (add `'given'`/`'refused'` to the emitted statuses and the TS union in [resources/js/pages/my-day/lib/types.ts](../resources/js/pages/my-day/lib/types.ts)) **or** drop it from the rail — match whatever the hero/`WhatsNextRail` expect (the hero counts `medsGiven`, so keep a `given` row, don't drop it).
  2. Read the snooze cache key (`my-day.med-snooze.user-{id}.med-{id}.{scheduled_for}`) and skip snoozed slots for this worker.
  - **Self-consistency note:** because `getMedicationsDue` controls *both* the slot iso it generates and the `scheduled_for` it sends to `administer`, the lookup will match its own administrations even before M3 is done. So the rail becomes correct standalone; M3 is what makes it agree with the eMAR.
- **Acceptance:** give a dose → it shows `given` (counter increments) and does not revert on refresh; snooze a dose → it stays hidden for this worker for the window and reappears after; a dose signed in the eMAR for the same slot also shows resolved on My Day **once M3 lands**.
- **Tests:** extend `MyDayMedicationsDuePayloadTest.php` — seed an administration and assert the slot is `given`; seed a snooze key and assert the slot is absent.

### M3 — Align dose-slot timezone so My Day and the eMAR reconcile (🔴, higher blast radius — see Decision D2)
- **The bug:** My Day builds slots in worker-tz (`Carbon::now('Pacific/Auckland')` → [MyTasksController.php:53](../app/Http/Controllers/MyTasksController.php:53),[:576](../app/Http/Controllers/MyTasksController.php:576)). The eMAR parses its date with **no timezone** = app UTC (verified at [ClientMarController.php:44](../app/Http/Controllers/ClientMarController.php:44); same pattern in `MedicationsApiController`, `ShiftController`, and `MarScheduleService::scheduledTimesForDate`). A `09:00` dose is therefore `09:00 NZ` on My Day and `09:00 UTC` (= 21:00 NZ) on the eMAR. The eMAR matches administrations to slots within ±1 min ([EnhancedMarService.php](../app/Services/EnhancedMarService.php) `buildScheduledRow`), so a My Day administration never attaches to the eMAR slot, and vice-versa.
- **Secondary divergence:** My Day + GuidedRoundService read dose times from the `dose_times` column; `MarScheduleService` parses the free-text `frequency` column. Three schedule sources.
- **Correct fix (per house tz convention):** dose times are wall-clock **local** times — interpret them in worker-tz everywhere. Make the eMAR build its `$date` in `app.worker_timezone` (then `->utc()` for storage/queries), and converge all three surfaces on **one shared "scheduled doses for client + date" service** sourced from a single column.
- **Because this touches the whole meds module, treat M3 as its own task with its own regression pass.** Do **not** "fix" it by making My Day match UTC — that would display doses at the wrong local time. Get Stephan's sign-off (D2) before changing eMAR date handling.
- **Acceptance:** the same `09:00` dose shows at `09:00` NZ on `/my-day`, `/meds/today`, and the client MAR grid; an administration recorded on any surface resolves the slot on all three.
- **Tests:** a feature test that records via My Day and asserts the eMAR `build()` for that date shows the slot completed (and the reverse).

### M5 — Overdue-medication alerts can never fire (🟠)
- **File:** [app/Console/Commands/SendMedicationAlerts.php:45](../app/Console/Commands/SendMedicationAlerts.php:45) — `checkOverdueMedications` queries administrations with `status='pending'`, but **no code ever creates a `pending` administration row** (rows exist only once a dose is acted on). The query matches the empty set; `MedicationOverdueNotification` is never sent.
- **Fix:** rework overdue detection to derive missed doses from *scheduled slots with no administration past their window* (reuse the shared schedule service from M3), not from non-existent `pending` rows. Coordinate with M3 so "missed" is defined once.
- **Acceptance:** a non-PRN scheduled dose left unsigned past its window triggers the overdue notification in a feature test.

---

## PHASE 2 — Handover (🔴 safety). Reconnect what already exists.

### H1 — Incoming handover read + acknowledge is dead on the desktop page (🔴)
- **The bug:** [DigestPanel](../resources/js/pages/my-day/components/digest-panel.tsx)'s default tab and `handleConfirmHandoverRead` ([index.tsx:453](../resources/js/pages/my-day/index.tsx:453),[:672](../resources/js/pages/my-day/index.tsx:672)) are bound to `props.handover`, which **neither [MyTasksController](../app/Http/Controllers/MyTasksController.php) nor [HandleInertiaRequests](../app/Http/Middleware/HandleInertiaRequests.php) ever sends.** So the tab always shows the empty state and confirm-read early-returns. Meanwhile the backend computes the handover at `clock.active_shift.incoming_handover` ([MyTasksController.php:393](../app/Http/Controllers/MyTasksController.php:393)) and `next_shift_briefing.incoming_handover` ([:664](../app/Http/Controllers/MyTasksController.php:664)) — both unused — and a fully-built [resources/js/components/handover-read-card.tsx](../resources/js/components/handover-read-card.tsx) exists but is imported only by the orphaned `pre-shift-briefing-card.tsx`.
- **Fix (recommended):**
  1. In `MyTasksController`, add a top-level **`handover`** prop: the most relevant incoming handover for the worker right now (their open session's shift if clocked in, else the imminent shift). Reuse `findIncomingHandover()` ([:464](../app/Http/Controllers/MyTasksController.php:464)) — but tighten it per **H2**. Shape it to the `MyDayHandover` type the DigestPanel already consumes (`from`, `summary`, `flags`, `recorded_at`, `unread`, `id`).
  2. Confirm `handleConfirmHandoverRead` PATCHes `/attendance/handover/{id}/acknowledge` with the real id (the route + lifecycle already work — verified).
  3. Also surface the richer payload (meds/incidents/follow-ups) — either render `HandoverReadCard` in `TomorrowPanel` from `next_shift_briefing.incoming_handover`, or expand the digest's `HandoverPane`. Don't build new UI; reuse `HandoverReadCard`.
  4. Fix the TS type: [resources/js/pages/my-day/lib/types.ts:295](../resources/js/pages/my-day/lib/types.ts:295) declares `incoming_handover?: { summary?: string }` but the backend sends the full `HandoverReadPayload` shape (see `handover-read-card.tsx`). Align them.
- **Acceptance:** a submitted handover for the worker's shift renders in the digest Handover tab with sender + notes; "Confirm read" acknowledges it (status → acknowledged) and the badge clears; it does not reappear.
- **Tests:** feature test asserting the `handover` prop is populated for an incoming worker; e2e covering the confirm-read click.

### H2 — Tighten incoming-handover matching (🟠)
- **File:** [MyTasksController.php:464](../app/Http/Controllers/MyTasksController.php:464) (`findIncomingHandover`) + [AttendanceController.php](../app/Http/Controllers/AttendanceController.php) `acknowledgeHandover` (~line 355).
- **Bug:** for a site/house shift with no `client_id`, the 24h fallback applies no client/site scoping and matches `incoming_staff_id IS NULL`, so it can surface a handover meant for someone else / another resident. Acknowledge then **writes the current user in as `incoming_staff_id`**, so the first arriver silently becomes the official recipient.
- **Fix:** scope the fallback to the shift's site/residents (not "any unassigned handover in 24h"); only auto-claim `incoming_staff_id` when the handover was actually targeted at this user or their shift. Otherwise require an explicit match.
- **Acceptance:** worker A never sees/acks a handover addressed to worker B or to a resident not on A's shift.

---

## PHASE 3 — Attendance & Timesheets (🟠 payroll accuracy)

### A1 — Clock-out blockers are invisible to the worker (🟠)
- **Files:** [AttendanceController.php:187](../app/Http/Controllers/AttendanceController.php:187) (blocked clock-out returns `response()->json([...], 422)`); [resources/js/components/end-of-shift-checklist.tsx](../resources/js/components/end-of-shift-checklist.tsx) (`postClockOut` registers `onSuccess`/`onFinish` but **no `onError`**).
- **Bug:** Inertia always sends the AJAX header, so `expectsJson()` is always true and the JSON branch always fires — but a raw JSON body isn't an Inertia response, so the worker gets Inertia's "invalid response" modal (or a silent failure) with **no blocker list**. The passing test masks this by POSTing without the AJAX header.
- **Fix:** return blockers via an Inertia-compatible response (content-negotiate with `RespondsToInertiaOrJson`, or `back()->with('clock_out_blockers', ...)` — note the `flash.clock_out_blockers` key already exists in [HandleInertiaRequests.php:198](../app/Http/Middleware/HandleInertiaRequests.php:198)). Add `onError`/flash handling in the checklist so blockers render.
- **Acceptance:** an un-forced clock-out with incomplete tasks/meds/handover shows the specific blockers in the dialog; the test exercises the **real** AJAX path.

### A2 — Clock-out overwrites worker-edited break minutes (🟠)
- **Files:** [MyDayActionsController.php:98](../app/Http/Controllers/MyDayActionsController.php:98) (`ensureTodayTimesheet`, seeds `break_minutes = expected_break_minutes ?? 30`) vs the clock-out draft writer in [app/Domain/Hr/Services/AttendanceService.php](../app/Domain/Hr/Services/AttendanceService.php) / `DraftTimesheetService::fromAttendanceSession` (overwrites `break_minutes` from the session, default 0).
- **Bug:** both key on `(shift_id,user_id)`; clock-out's `update()` clobbers a worker-edited break value without warning.
- **Fix:** on clock-out, do not overwrite a worker-edited break (or reconcile: prefer the larger / flag a mismatch for review). Also unify the **default break inconsistency** (30 vs 0) and the **cap inconsistency** (240 on clock-out vs 600 on timesheet edit — [AttendanceController.php:154](../app/Http/Controllers/AttendanceController.php:154) vs `TimesheetController`).
- **Acceptance:** a worker who edits break to 45m mid-shift still has 45m after clock-out (or sees an explicit reconcile prompt).

### A3 — Forced clock-out lets frontline self-bypass meds/incident blockers (🟠 governance — see Decision D3)
- **Files:** [AttendanceService.php](../app/Domain/Hr/Services/AttendanceService.php) (force path), [end-of-shift-checklist.tsx](../resources/js/components/end-of-shift-checklist.tsx) (override reason ≥4 chars).
- **Bug:** anyone with `shifts.viewAssigned` can pass `force=true` + a short reason to clock out past unsigned-meds / draft-incident blockers. Audited, but not approval-gated or escalated.
- **Fix (pending D3):** either require a supervisor/manager capability to override clinical blockers, or raise a control-room escalation when a clinical blocker is force-bypassed. Keep self-service override for *non-clinical* blockers (e.g. unwritten handover) if desired.

### A4 — ±4h clock-in grace can auto-bind to the wrong adjacent shift (🟡)
- **File:** [app/Domain/Hr/Services/AttendanceService.php](../app/Domain/Hr/Services/AttendanceService.php) — `eligibleShiftsForUser` / `resolveShift` use `AUTO_MATCH_GRACE_HOURS = 4`.
- **Bug:** a clock-in with **no `shift_id`** matches any assigned shift starting within +4h or ending within −4h. For back-to-back shifts that window overlaps two shifts, so the session can attach to the wrong one and mis-key the draft timesheet `(shift_id,user_id)`. The `/my-day` card always sends `shift_id`, but the bare `POST /attendance/clock-in` and `attendance/index.tsx` can omit it.
- **Fix:** when the grace window matches >1 eligible shift, don't silently pick one — require disambiguation (return the candidates / force `shift_id`), or narrow the window. Also surface the "no open attendance session" 422 on the no-op clock-out path (omitted `session_id`, stale prop) via the same `onError`/flash handling added in A1.
- **Acceptance:** clocking in during an overlap of two assigned shifts without `shift_id` does not arbitrarily bind to one; the draft timesheet keys to the intended shift.

### T1 — Residential/equal-split rounding rejected after the UI says OK (🟠)
- **Files:** [_dialogs.tsx:1275](../resources/js/pages/my-day/_dialogs.tsx:1275) (`sumOk = isResidential || ...`), [:1477](../resources/js/pages/my-day/_dialogs.tsx:1477) (submit enabled), vs [MyDayActionsController.php:257](../app/Http/Controllers/MyDayActionsController.php:257) (backend enforces sum==total within 0.02 **unconditionally**).
- **Bug:** the dialog forces `sumOk=true` for residential but sends N per-resident rows rounded with `toFixed(2)`; for ~7 residents on a 12h day the sum drifts 0.03 → backend 422, and the error only shows in the raw catch-all block.
- **Fix:** make the residential/equal-split allocation balance exactly — e.g. compute integer-cent shares and put the rounding remainder on the last row so the sum is always == total. Apply the same on the FE seed (`seedRows`) and validate the FE `sumOk` the same way the backend does (drop the residential bypass, or have the backend accept a `residential_house` method without the strict sum if that's the real intent — pick one and make both sides agree).
- **Acceptance:** a residential timesheet for 5/6/7/8 residents on common shift lengths submits without a 422; FE and BE agree on what "balanced" means.

### T2 — `time_segmented` hours not validated against the window (🟠)
- **File:** [MyDayActionsController.php:272](../app/Http/Controllers/MyDayActionsController.php:272) — requires start+end present but never checks `(end−start) ≈ hours`. A model accessor `segmentHours` exists ([app/Models/TimesheetClientAllocation.php](../app/Models/TimesheetClientAllocation.php)) but is unused.
- **Fix:** validate that each time_segmented row's `hours` matches its window within tolerance (or derive hours from the window and ignore the submitted value). Allocations feed billing (`BillingService`, `ClientCostService`), so they must be internally consistent.

### T3 — Inline errors never render + "Today's timesheet" can dead-end (🟡)
- **Inline errors:** [_dialogs.tsx:1616](../resources/js/pages/my-day/_dialogs.tsx:1616) reads `errors["client_allocations.{client_id}.hours"]`, but Laravel keys them by **array index** (`client_allocations.0.hours`) per the `client_allocations.*` rules at [MyDayActionsController.php:219](../app/Http/Controllers/MyDayActionsController.php:219). Map FE error lookup to the row index (the dialog has the index in `rows.map((r,i)=>...)`). Do the same for `data.<field>` observation errors (**O2**).
- **Dead-end:** [index.tsx:408](../resources/js/pages/my-day/index.tsx:408) `handleOpenTimesheets` redirects to `/operations/timesheets?create=1&shift_id=` when no draft exists, but `availableShiftsForCreate` filters out shifts that already have a timesheet — if a draft exists (e.g. from a prior clock-out) the dialog opens on a blank picker. Either open the existing draft, or include the active shift in the create list. (Note: `/my-tasks/timesheet/ensure-today` is **dead** — referenced only in comments; either wire it as the find-or-create entry for this button, or delete it — see X2.)

---

## PHASE 4 — Observations + Medium gaps

### O1 — Shift-scoped observation ignores `client_id` → wrong client (🔴, but grouped here as a self-contained one-file fix)
- **File:** [app/Http/Controllers/Clinical/ShiftClinicalController.php:51](../app/Http/Controllers/Clinical/ShiftClinicalController.php:51) (`store`). Verified: it validates `observation_type/data/notes/recorded_at/protocol_schedule_id` only — **`client_id` is never read** — and records against `$shift->client` ([:83](../app/Http/Controllers/Clinical/ShiftClinicalController.php:83)). The dialog deliberately sends `client_id: <picked resident>` ([_dialogs.tsx:400](../resources/js/pages/my-day/_dialogs.tsx:400)).
- **Fix:** accept an optional `client_id` in the validator; when present, assert it belongs to the shift's site/roster and record against **that** client; keep `$shift->client` as the fallback. Reject (422) a `client_id` not on the shift's roster.
- **Acceptance:** on a multi-resident house shift, recording for resident B writes against B; an off-roster `client_id` is rejected. (This can be a standalone task / spin-off PR — it doesn't depend on the meds work.)
- **Tests:** feature test posting to `/shifts/{id}/clinical/observations` with a co-resident `client_id` and asserting the observation's `client_id`.

### O2 — Observation value/range validation (🟡)
- **File:** [app/Domain/Clinical/Services/ClinicalObservationService.php:118](../app/Domain/Clinical/Services/ClinicalObservationService.php:118) — only checks required keys exist; values are unchecked (pain=500, bristol=99, systolic="abc" persist). Errors are keyed `data` (catch-all), so the dialog's `data.<field>` bindings never fire.
- **Fix:** add per-type range/numeric validation, returning `data.<field>`-keyed messages so the existing FE field errors render. Keep ranges clinically sane (BP, pulse, temp, SpO₂, pain 0–10, bristol 1–7).

### N1 — "Updates" digest tab always empty (🟡)
- **File:** [index.tsx:675](../resources/js/pages/my-day/index.tsx:675) passes `props.notifications` (never sent). The data exists as a deferred shared prop `inbox.notifications.items` ([HandleInertiaRequests.php:212](../app/Http/Middleware/HandleInertiaRequests.php:212)).
- **Fix (recommended):** have `MyTasksController` send a synchronous `notifications` prop (map the user's latest unread notifications to the `MyDayNotification` shape), OR have the DigestPanel consume `inbox.notifications` and handle the deferred load. Prefer the controller prop for a synchronous tab. Keep the bell badge (`stats.notifications_unread`) consistent with the list.

### G1 — Guided-round resume banner dropped from `/my-day` (🟡)
- `MyTasksController` computes `active_round` ([:117](../app/Http/Controllers/MyTasksController.php:117)) but only `/meds/today` renders it ([resources/js/pages/meds/today/index.tsx](../resources/js/pages/meds/today/index.tsx)). The controller comment still claims it surfaces on `/my-day`.
- **Fix:** render an active-round resume banner on `/my-day` (reuse the `/meds/today` banner markup), or, if intentionally dropped, remove the dead `active_round` computation and update the comment. Decide with X2.

### C1 — Checklists invisible to view-only workers (🟡)
- [index.tsx:660](../resources/js/pages/my-day/index.tsx:660) renders the card only with `checklists.run`. A worker with `checklists.view` sees no due/overdue checklists and no indication.
- **Fix:** show due checklists read-only (with a "you can't complete these" hint) when the worker has `view` but not `run`, or confirm with Stephan that hiding is intended (Decision D4).

### Other medium/low to fold in while you're in these files
- **Refusal reason (M2-refusal):** `handleRefuseMed` ([index.tsx:495](../resources/js/pages/my-day/index.tsx:495)) sends no reason; backend defaults `'Resident declined'` with no `reason_code`. Add a small reason prompt (reuse the eMAR's not-given reason codes) so refusals carry a real, structured reason — required once M1 routes through the service.
- **break-minute truncation** to whole minutes; **dead `flag:'PRN'`** field ([MyTasksController.php:613](../app/Http/Controllers/MyTasksController.php:613), always null since PRN is filtered out); **checklist `is_overdue` tz basis** differs from the model accessor.

---

## PHASE 5 — Cross-cutting & cleanup

### X1 — Silent fail-soft hides failures as empty sections (🟡)
- Nearly every fetch in [MyTasksController](../app/Http/Controllers/MyTasksController.php) is wrapped in `try { ... } catch (\Throwable) { return []; }` (e.g. `getMedicationsDue`, `getIncidents`, `getShifts`, `getCrTasks`). A thrown query renders "no meds due" / "no incidents" — a dangerous false-negative in a care setting.
- **Fix:** log the exception (don't swallow silently) and, where a section is safety-critical (meds, incidents), surface a non-blocking "couldn't load — retry" state to the worker instead of an empty list that reads as "nothing here". At minimum, `report($e)` in every catch.

### X2 — Dead payload + dead endpoint (⚪)
- Computed-but-unused by the new page: `previous_shift`, `manager_data`, `is_manager`, `leave`, `pending_claims_count`, `runDetail`, `clock.active_shift`, `clock.eligible_shifts`. And `/my-tasks/timesheet/ensure-today` ([MyDayActionsController.php:70](../app/Http/Controllers/MyDayActionsController.php:70)) is referenced only in comments.
- **Fix:** for each, either **wire it** (e.g. the manager summary, the guided round in G1, the ensure-today button in T3) or **delete it** (stop computing it + drop the prop + remove the endpoint/route). Don't leave queries running for props nothing reads. Confirm intent per Decision D5.

---

## 2. Decisions called out by the audit

- **D1 (M1):** Resolved conservatively for this pass. My Day does not add a witness credential UI; controlled-drug one-tap give remains blocked unless witness details are supplied, and workers should use the full eMAR controlled-drug flow.
- **D2 (M3):** Resolved in this pass by converging the meds module on worker-local dose-time interpretation with UTC storage/query windows.
- **D3 (A3):** Resolved in this pass by requiring manager-level capability to force clock out through clinical blockers.
- **D4 (C1):** Resolved in this pass by showing due checklists read-only to `checklists.view` workers who do not have `checklists.run`.
- **D5 (X2/G1):** Resolved conservatively by wiring the still-useful My Day flows and deleting only props with no current consumer or test.

---

## 3. Verified OK — do NOT "fix" these (they work)

Cross-user clock-in/out guards; double clock-in/out/break prevention; the `(shift_id,user_id)` timesheet unique constraint; incident-create `?shift_id=` prefill; handover **write** + acknowledge lifecycle/idempotency (the *read* path is the broken bit — H1); observation **permission gating** (real, no super-admin bypass) and observations do surface on the client profile; checklist run-modal endpoints exist, are `checklists.run`-gated and site-scoped; timesheet returned→submitted reset; `total_hours` derivation consistent FE/BE; PRN recording on `/meds/today` (already uses the trusted service). Don't regress these — several have existing tests that should stay green.

---

## 4. Suggested PR slicing from the original audit

1. **PR-meds-safety** — M1 + M2 + M4 + refusal reason (self-consistent rail, trusted service). Big safety win, contained.
2. **PR-meds-tz** — M3 + M5 (the cross-module convergence; its own regression pass).
3. **PR-handover** — H1 + H2.
4. **PR-attendance-timesheet** — A1 + A2 + T1 + T2 + T3.
5. **PR-observations** — O1 + O2 (O1 can ship alone immediately).
6. **PR-myday-gaps** — N1 + G1 + C1 + X1 + X2.

This implementation completed the slices together in one local pass. Each PR: `php artisan test --filter=...` green (non-parallel), `npm run types` 0, `npm run build` OK, then verify on `oblivionfindings.test` and after merge on `oblivionfindings.com`.

---

## 5. Codex implementation ledger — 2026-06-08

### Implemented in this pass

- **M1/M2/M4 + refusal reason:** `/my-day` medication give/refuse now routes through `EnhancedMarService`, including duplicate-slot idempotency, controlled-drug witness enforcement, stock/register handling when valid witness details are supplied, structured refusal reason data, resolved `given/refused/withheld` rail statuses, and worker-scoped snooze filtering.
- **D1 decision applied conservatively:** the My Day UI still does not collect a witness credential, so controlled-drug one-tap "Give" is blocked unless witness details are supplied by the request. Workers should use the full eMAR controlled-drug flow until a proper witness UI is added to My Day.
- **M3:** scheduled medication slots now converge through `MarScheduleService`, interpreting `dose_times` as worker-local wall-clock times and storing/querying administration slots in UTC. My Day, client MAR, worker meds, guided rounds, API/offline conflict checks, shift summaries, today dashboard, and medication reporting entry points now share the same scheduler and UTC slot/day windows.
- **M5:** overdue medication alerts now derive from unsigned scheduled slots past their MAR window instead of querying impossible `pending` administration rows, with per-slot dedupe and round-assignee notification coverage.
- **O1:** shift-scoped clinical observations now accept an optional `client_id`, record against the selected resident when that resident belongs to the shift/site, and reject off-site residents.
- **H1/H2 safety slice:** `MyTasksController` now sends a top-level digest-ready `handover` prop; the My Day handover type accepts the full read payload; the Tomorrow panel reuses `HandoverReadCard` for the richer incoming handover payload; acknowledge now rejects unrelated unassigned handovers instead of letting the first worker claim another resident's handover.
- **H2 schema correction:** the current migrations require `shifts.client_id`, so the exact "site/house shift with no client_id" case from the audit could not be reproduced. The implemented guard covers the real unassigned-claim risk by requiring exact client matching for unassigned handover acknowledgement.
- **A1:** Inertia clock-out blocker responses now redirect back with `clock_out_blockers` flash instead of returning raw JSON, and the end-of-shift checklist reads flashed blockers so the worker sees the specific unresolved items in the dialog.
- **A2:** clock-out draft timesheet sync now preserves/reconciles an existing draft/returned break value by keeping the larger of the worker-edited draft break and attendance-session break minutes.
- **A3:** force clock-out through clinical blockers (`incidents_draft`, `meds_unsigned`) now requires manager-level authority (`shifts.manageAny`, `timesheets.manageAny`, or `clients.update`). The end-of-shift checklist disables the self-service override and shows manager-required copy when the worker lacks that capability.
- **A4:** the existing ambiguous clock-in guard is locked with regression coverage so overlapping eligible shifts without an explicit `shift_id` do not silently bind to one shift.
- **T1:** residential/equal-split allocation seeding now uses integer-hundredth splits and puts rounding remainder into later rows, and the frontend balance check no longer bypasses residential rows.
- **T2:** time-segmented allocations now validate that submitted `hours` matches the submitted start/end duration within tolerance.
- **T3 inline errors:** the timesheet allocation dialog now maps backend row errors by Laravel's array index keys before falling back to the old client-id key pattern.
- **O2:** clinical observations now perform per-type numeric/range validation for vitals, weight, bowel, sleep, fluid intake, and pain observations, and validation messages are keyed to `data.<field>` so My Day field errors can render inline.
- **N1:** `MyTasksController` now sends a synchronous `notifications` prop for the Updates digest tab, mapped from the worker's latest unread database notifications without changing the shared deferred inbox payload. Notification ids now match Laravel's UUID notification ids on the My Day type.
- **T3 dead-end cleanup:** the My Day "Today's timesheet" button now calls the existing `/my-tasks/timesheet/ensure-today` endpoint when no draft/returned timesheet is already loaded, so the worker stays on `/my-day` and the existing `open_timesheet_id` flash path opens the refreshed popup.
- **G1:** the active guided medication round computed by `MyTasksController` now renders as a My Day resume/start banner with progress and the guided-round URL.
- **C1:** due checklists now render for workers with `checklists.view` even without `checklists.run`; the run modal switches to read-only when `can.run` is false.
- **X1:** `MyTasksController` fail-soft catches now call `report($e)` before returning their existing fallback values, so load failures are no longer silently hidden.
- **X2:** dead My Day payload was reduced by removing unused HR leave, manager summary, pending open-shift claim, and legacy `clock.active_shift/eligible_shifts` props. `previous_shift` and checklist `runDetail` were intentionally kept because they remain covered by current tests and consumers.

### Verification evidence

- `php artisan test tests/Feature/MyDayMedicationActionTest.php tests/Feature/MyDayMedicationsDuePayloadTest.php tests/Feature/Domain/Clinical/ShiftClinicalControllerTest.php tests/Feature/MyDayHandoverDigestTest.php` — **26 tests, 153 assertions passed**.
- `php artisan test tests/Feature/AttendanceClockOutBlockerTest.php tests/Feature/Hr/AttendanceClockWorkflowTest.php tests/Feature/MyDayTimesheetAllocationTest.php` — **21 tests, 95 assertions passed** after the red regression run confirmed A1/A2/T2 failures.
- `php artisan test tests/Feature/MyDayMedicationActionTest.php tests/Feature/MyDayMedicationsDuePayloadTest.php tests/Feature/Domain/Clinical/ShiftClinicalControllerTest.php tests/Feature/MyDayHandoverDigestTest.php tests/Feature/AttendanceClockOutBlockerTest.php tests/Feature/Hr/AttendanceClockWorkflowTest.php tests/Feature/MyDayTimesheetAllocationTest.php` — **47 tests, 248 assertions passed**.
- `php artisan test tests/Feature/Domain/Clinical/ShiftClinicalControllerTest.php` — **16 tests, 41 assertions passed** after adding O2 field-level validation coverage.
- `php artisan test tests/Feature/MyDayNotificationsDigestTest.php` — **1 test, 22 assertions passed** after the red run confirmed the missing top-level `notifications` prop.
- `php artisan test tests/Feature/MyDayActiveSiteTest.php tests/Feature/MyDayPreviousShiftTest.php tests/Feature/MyDayPreShiftBriefingTest.php tests/Feature/MyDayNotificationsDigestTest.php` — **11 tests, 199 assertions passed** after G1/C1/X2 wiring and cleanup.
- `npm run test -- resources/js/pages/my-day/index-audit-fixes.test.tsx resources/js/components/end-of-shift-checklist.test.tsx resources/js/pages/my-day/lib/timesheet-allocation.test.ts` — **3 files, 7 tests passed** after red runs confirmed the T3, G1, and C1 frontend gaps.
- `npm run test -- resources/js/pages/my-day/lib/timesheet-allocation.test.ts resources/js/components/end-of-shift-checklist.test.tsx` — **2 files, 4 tests passed**.
- `npm run types` — passed.
- `npm run build` — passed; Vite generated Wayfinder types and built production assets.
- Continuation backend slice: `php artisan test tests/Feature/MyDayMedicationActionTest.php tests/Feature/MyDayMedicationsDuePayloadTest.php tests/Feature/MedicationOverdueAlertsTest.php tests/Feature/AttendanceClockOutBlockerTest.php tests/Feature/MyDayHandoverDigestTest.php` — **24 tests, 178 assertions passed**.
- Continuation frontend slice: `npm run test -- resources/js/components/end-of-shift-checklist.test.tsx resources/js/pages/my-day/components/tomorrow-panel.test.tsx resources/js/pages/my-day/index-audit-fixes.test.tsx resources/js/pages/my-day/lib/timesheet-allocation.test.ts` — **4 files, 9 tests passed**.
- Continuation broad backend pass: `php artisan test tests/Feature/MyDayMedicationActionTest.php tests/Feature/MyDayMedicationsDuePayloadTest.php tests/Feature/MedicationOverdueAlertsTest.php tests/Feature/Domain/Clinical/ShiftClinicalControllerTest.php tests/Feature/MyDayHandoverDigestTest.php tests/Feature/AttendanceClockOutBlockerTest.php tests/Feature/Hr/AttendanceClockWorkflowTest.php tests/Feature/MyDayTimesheetAllocationTest.php tests/Feature/MyDayActiveSiteTest.php tests/Feature/MyDayPreviousShiftTest.php tests/Feature/MyDayPreShiftBriefingTest.php tests/Feature/MyDayNotificationsDigestTest.php` — **66 tests, 493 assertions passed**.
- Continuation `npm run types` — passed.
- Continuation `npm run build` — passed; Vite generated Wayfinder types and built production assets.
- Browser check: the in-app Browser tool was unavailable in this thread, so verification used Playwright. `PLAYWRIGHT_PORT=4185 npx playwright test -c playwright.config.ts tests/e2e/my-day-pre-shift-briefing.spec.ts --project=chromium-desktop --reporter=list` — **1 passed**, loading `/my-day` with built assets as `sw1@demo.test`.
- Browser check continuation: the in-app Browser control still was not callable, so verification again used Playwright. `PLAYWRIGHT_PORT=4187 npx playwright test -c playwright.config.ts tests/e2e/my-day-lifecycle-smoke.spec.ts tests/e2e/my-day-end-of-shift.spec.ts tests/e2e/my-day-returned-timesheet.spec.ts tests/e2e/my-day-pre-shift-briefing.spec.ts --project=chromium-desktop --reporter=list` — **3 passed, 2 skipped**. The skipped specs depended on the current demo worker having an active shift/returned timesheet.
- Browser fixture note: the first Playwright run emitted a `FrontlineLifecycleDemoSeeder` warning. Direct reproduction showed it was caused by stale local `source=manual` open attendance for `sw1@demo.test` from 2026-05-22, not this change. That local row was not modified.
- Browser fixture note continuation: the later Playwright run still logged the same demo seeder warning, but the selected route-level My Day smoke tests loaded successfully with built assets.

### Remaining work / gated decisions

- No audit item from this plan is intentionally left open in the local implementation pass.
- **D1 follow-up:** if My Day should support one-tap controlled-drug give, add a proper witness-credential UI. Until then, the endpoint/service enforcement blocks the action without witness details.
- **Deployment note:** run the permission seeders with `--force` if the target environment does not already have the manager capabilities used by the A3 gate.
- **T3/G1/C1/X1/X2 are closed for this pass:** the remaining dead-payload decision was resolved conservatively by wiring the still-useful My Day flows and deleting only props with no current consumer or test.
