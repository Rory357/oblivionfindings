# Audit: /my-day support-worker shift workflow

Use this file as the seed prompt for a fresh Claude session to continue the audit.

## Context

NZ Supported Living CRM (Pacific/Auckland TZ, NZ currency, residential houses + 1:1 community support). The audit covers the frontline `/my-day` workflow end-to-end: clock-in → shift activities → break → handover → clock-out → timesheet allocation → approval → billing.

Repo: `C:\Users\steph\Herd\oblivionfindings`
- Worktree workflow: commit on `claude/...` branch → `git push origin HEAD:main` → `cd` to parent → `git pull origin main` → `npm run build` (Vite is in prod-build mode).
- Live local: `https://oblivionfindings.test` (Herd, parent dir).
- Memory: `C:\Users\steph\.claude\projects\C--Users-steph-Herd-oblivionfindings\memory\MEMORY.md`.

Seeded test scenario:
- `sw1@demo.test` (Support Worker 1, user id 26, role `support_worker`).
- Today's shift #9322 at Rimu House with 3 residents (Margaret Hewitt 9076, Hone Tāmati 9077, Aroha Lee 9078).
- TS #6 is the multi-client draft (3 allocations × 2.5h = 7.5h).
- Reseed via `php artisan db:seed --class=SwOneMyDayDemoSeeder` (non-destructive).
- Cannot enter passwords on the user's behalf — ask the user to log in as admin and impersonate via `/system/users` → ⋯ → "Impersonate".

## Workflow being audited (and key files)

1. Hero "Clock in" — `POST /attendance/clock-in` ([AttendanceController.php:113](../app/Http/Controllers/AttendanceController.php#L113))
2. Quick actions — meds, care note, vitals, incident, care plan ([my-day-hero.tsx](../resources/js/pages/my-day/components/my-day-hero.tsx))
3. Stream item interactions — task complete, give/refuse/snooze med, add note ([index.tsx](../resources/js/pages/my-day/index.tsx))
4. Break start/end — `POST /attendance/break/{start,end}` ([AttendanceController.php:209](../app/Http/Controllers/AttendanceController.php#L209))
5. Handover read — `PATCH /attendance/handover/{handover}/acknowledge`
6. Handover write — `WriteHandoverDialog` → `POST /attendance/handover` ([_dialogs.tsx:922](../resources/js/pages/my-day/_dialogs.tsx#L922))
7. "Today's timesheet" hero button — `POST /my-tasks/timesheet/ensure-today` → flash `open_timesheet_id` → `TimesheetReviewDialog` opens ([_dialogs.tsx:1143](../resources/js/pages/my-day/_dialogs.tsx#L1143))
8. Allocation method tile picker (single | residential_house | equal_split | manual | time_segmented), per-client tabs, sum balance check
9. Submit — `POST /my-tasks/timesheet/{id}/submit` ([MyDayActionsController.php:151](../app/Http/Controllers/MyDayActionsController.php#L151))
10. End shift — `EndOfShiftChecklist` Dialog → `POST /attendance/clock-out` ([end-of-shift-checklist.tsx](../resources/js/components/end-of-shift-checklist.tsx))
11. Manager approval — `/operations/timesheets/{id}` → `TimesheetApprovalService` → `BillingService::generateFromTimesheet` ([BillingService.php:34](../app/Services/Operations/BillingService.php#L34))

Popup style guide: [docs/POPUP_STYLE_GUIDE.md](POPUP_STYLE_GUIDE.md). All dialogs on `/my-day` must follow it.

## Fixes shipped (commit `227aabc9` on main)

Leaky → Fixed:
- `ensureTodayTimesheet` now checks `timesheets.create` ([MyDayActionsController.php:75](../app/Http/Controllers/MyDayActionsController.php#L75)).
- `submitTimesheet` now checks `timesheets.submit` ([MyDayActionsController.php:159-163](../app/Http/Controllers/MyDayActionsController.php#L159)).
- "Create" button on `/operations/timesheets` hidden for workers' own-list view ([operations/timesheets/index.tsx:271](../resources/js/pages/operations/timesheets/index.tsx#L271)). Page route still works for managers/admins.

Missing → Fixed:
- Hero "Submit timesheet" quick action now opens the popup instead of bouncing to the list ([my-day-hero.tsx:269](../resources/js/pages/my-day/components/my-day-hero.tsx#L269)).
- `onError` handler added to ensure-today so "no shift today" surfaces via `window.alert` instead of failing silently ([index.tsx:346](../resources/js/pages/my-day/index.tsx#L346)).

Polish → Fixed:
- Flash watcher guard via `useRef` so the popup doesn't re-open after the worker closes it ([index.tsx:362](../resources/js/pages/my-day/index.tsx#L362)).

Verified live via probe (`probe.php` deleted after use): permission gates return 403 when stripped, 302/200 otherwise.

## Fixes shipped (this session)

Residential allocation semantics — Decision A confirmed (divide hours across residents):
- Validator no longer bypasses the sum check for `residential_house` ([MyDayActionsController.php:245](../app/Http/Controllers/MyDayActionsController.php#L245)). Frontend's equal-split already produces an exact sum, so this just stops a future caller from sneaking in 3× double-billing without the popup.

Incident `shift_id` audit trail — was being silently dropped:
- `IncidentController::create` now reads `?shift_id=...` and `?client_id=...` query params (used by `/my-day` hero + resident cards), validates the worker is on that shift, and passes them through as a `prefill` prop ([IncidentController.php:97-117](../app/Http/Controllers/IncidentController.php#L97)).
- `IncidentController::store` now accepts `shift_id`, re-validates ownership (worker on shift, or `incidents.viewAny`), and persists it instead of hard-coding `null` ([IncidentController.php:163-174](../app/Http/Controllers/IncidentController.php#L163)).
- `incidents/create.tsx` now accepts the `prefill` prop, seeds `data.client_id` from it, and forwards `shift_id` in the create POST ([incidents/create.tsx:130](../resources/js/pages/incidents/create.tsx#L130)).

Returned → submitted cycle — stale metadata fixed:
- `MyDayActionsController::submitTimesheet` now mirrors `TimesheetApprovalService::submittedFields()` and clears `returned_at`/`returned_by`/`returned_notes` plus the approval fields when transitioning out of `returned` ([MyDayActionsController.php:174-188](../app/Http/Controllers/MyDayActionsController.php#L174)). Without this, a returned timesheet that was resubmitted via the `/my-day` popup kept the old "returned" stamp alongside the new "submitted" status, confusing manager re-review.

## Open decisions / flagged but not fixed

1. ~~**Residential allocation semantics**~~ — Resolved in this session: Decision A (divide hours). Validator tightened; see "Fixes shipped (this session)".

2. **`clients_candidates` excludes `shift_clients` pivot** ([MyTasksController.php:758](../app/Http/Controllers/MyTasksController.php#L758)). Validator allows allocating to group-shift clients ([MyDayActionsController.php:306](../app/Http/Controllers/MyDayActionsController.php#L306)) but the popup doesn't list them. Harmless while the schema is dormant; will need a fix when group shifts go live.

3. **Multi-resident shifts lose top-level Care plan quick action** ([my-day-hero.tsx:247](../resources/js/pages/my-day/components/my-day-hero.tsx#L247)). Care plans are still accessible via avatar-stack popovers per resident. Acceptable today.

4. **Time-segmented allocation hours are decoupled from the time range** ([_dialogs.tsx:1579](../resources/js/pages/my-day/_dialogs.tsx#L1579), [MyDayActionsController.php:264](../app/Http/Controllers/MyDayActionsController.php#L264)). The popup shows start/end time inputs per row but doesn't:
   - Compute hours from the time range automatically (worker can enter `09:00–10:00` but type `5h` in the hours field — the sum check still passes if other rows compensate).
   - Validate that segments don't overlap (same worker shouldn't be 1:1 with two clients at the same wall-clock time).
   - Confirm segments stay within the timesheet's `starts_at`/`ends_at` window.
   The feature works for trusted workers but is currently more "manual mode with extra timestamp fields" than a real time-segmented audit trail. Worth tightening before sequential 1:1 shifts go live.

## What I didn't check (TODO for next session)

- **Mobile sheets** (`active-shift-card`, `clock-in-card` use `Sheet` on mobile) — out of scope per audit instructions (web-only).
- **Live end-to-end submit → approve → BillingEntry generation** — couldn't impersonate via browser. Reconciliation guard fires for seeded sw1 because actual clock-in is far off planned (446 min difference). Static review of `TimesheetApprovalService::approve` → `syncApprovedTimesheet` → `BillingService::generateFromTimesheet` confirms the path is correct; covered by `GenerateFromTimesheetAllocationsTest`. To live-test:
  - Reset sw1's open session and re-clock-in close to planned 09:00 NZ.
  - Or seed a fresh shift with planned times aligned to "now".
- **Care plan / observations dialog drives** — `RecordObservationDialog` and `VitalsRecordDialog` follow the popup style guide (shell+body, inline width, conditional render); didn't record one of each observation type to the DB.
- ~~**Direct `POST /operations/timesheets/store` by a worker**~~ — Audited: gated by `timesheets.create`, restricted to own shift, duplicate-prevented, snapshot-built, reconciled. Safe.
- ~~**Time-segmented allocation method**~~ — Audited statically; popup logic and validator look consistent. Real defects (no overlap check, hours decoupled from range) recorded in "Open decisions" §4 above.
- ~~**Returned timesheet re-submit cycle**~~ — Audited: wiring correct (paperwork panel → TimesheetReviewDialog → `/my-tasks/timesheet/{id}/submit` accepts both `draft` and `returned`). Fixed stale-metadata bug while in there; see "Fixes shipped (this session)".
- ~~**Incident creation form**~~ — Audited: found `shift_id` was being silently dropped, fixed. See "Fixes shipped (this session)".

## How to resume

Open this file and ask: "Continue the /my-day audit from `docs/audit-my-day-shift-workflow.md`. Pick up the open decisions and untested items."

Hard rules to keep:
- Don't break existing single-client billing or approve flows.
- Don't seed destructively — demo accounts are shared.
- Commit each fix on `claude/...` branch → `git push origin HEAD:main` → pull on parent → `npm run build`.
- Use NZ context (NZD, Pacific/Auckland, residential houses).
- One Dialog per `/my-day` popup; follow [POPUP_STYLE_GUIDE.md](POPUP_STYLE_GUIDE.md) strictly.
- Probe DB with a temp `probe.php` at the repo root; delete after use.
