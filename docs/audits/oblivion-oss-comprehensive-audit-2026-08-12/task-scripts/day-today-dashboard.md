# DAY-TODAY-DASHBOARD: Today Dashboard

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`
- Owning module: Frontline and My Day
- Legacy family: `DAY-TODAY-DASHBOARD`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `today` (`today`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`.
- Exact middleware atoms: `web`, `auth`, `verified`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD today` (`today`); the route is exact, but menu visibility and runtime access were not executed.

## Source-applicable states and transitions

- **information presented** is applicable only to `App\Http\Controllers\TodayDashboardController` / `ROUTE-3000` at `app/Http/Controllers/TodayDashboardController.php:13`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/dashboard/today.tsx`, `resources/js/pages/emar/_cd-dialogs.tsx`, `resources/js/pages/emar/components/generate-rounds-modal.tsx`, `resources/js/pages/my-day/components/my-day-hero.tsx`, `resources/js/pages/sites/_dialog-shared.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to  the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD today` — `today` — `App\Http\Controllers\TodayDashboardController` — `app/Http/Controllers/TodayDashboardController.php:13` — middleware `web, auth, verified`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/TodayDashboardController.php`.
- Exact render/action page relationships: `resources/js/pages/dashboard/today.tsx`, `resources/js/pages/emar/_cd-dialogs.tsx`, `resources/js/pages/emar/components/generate-rounds-modal.tsx`, `resources/js/pages/my-day/components/my-day-hero.tsx`, `resources/js/pages/sites/_dialog-shared.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
