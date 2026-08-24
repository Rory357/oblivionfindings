# REP-COMBINED-REPORT: Combined Report

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:reports.viewAny`
- Owning module: Reporting and summaries
- Legacy family: `REP-COMBINED-REPORT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `reports/combined/{report}` (`reports.combined.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:reports.viewAny`.
- Exact middleware atoms: `web`, `auth`, `permission:reports.viewAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD reports/combined/{report}` (`reports.combined.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD reports/combined/{report}/export` (`reports.combined.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/CombinedReportController.php:32-65`.

## Source-applicable states and transitions

- **information presented** is applicable only to `show` / `ROUTE-2348` at `app/Http/Controllers/CombinedReportController.php:21`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-2349` at `app/Http/Controllers/CombinedReportController.php:32`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/reports/combined.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to the requested file/report being returned or the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD reports/combined/{report}` — `reports.combined.show` — `App\Http\Controllers\CombinedReportController@show` — `app/Http/Controllers/CombinedReportController.php:21` — middleware `web, auth, permission:reports.viewAny`
- `GET|HEAD reports/combined/{report}/export` — `reports.combined.export` — `App\Http\Controllers\CombinedReportController@export` — `app/Http/Controllers/CombinedReportController.php:32` — middleware `web, auth, permission:reports.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/CombinedReportController.php`.
- Exact render/action page relationships: `resources/js/pages/reports/combined.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
