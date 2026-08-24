# OPS-REPORT: Report

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:operations.reports.view`
- Owning module: Operations and rostering
- Legacy family: `OPS-REPORT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/reports` (`operations.reports.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:operations.reports.view`.
- Exact middleware atoms: `web`, `auth`, `permission:operations.reports.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/reports` (`operations.reports.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/reports/{type}` (`operations.reports.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/ReportController.php:54-122`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2136` at `app/Http/Controllers/Operations/ReportController.php:44`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2137` at `app/Http/Controllers/Operations/ReportController.php:54`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/reports/Index.tsx`, `resources/js/pages/operations/reports/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2137` / `show`: fields `date_from`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to  the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/reports` — `operations.reports.index` — `App\Http\Controllers\Operations\ReportController@index` — `app/Http/Controllers/Operations/ReportController.php:44` — middleware `web, auth, permission:operations.reports.view`
- `GET|HEAD operations/reports/{type}` — `operations.reports.show` — `App\Http\Controllers\Operations\ReportController@show` — `app/Http/Controllers/Operations/ReportController.php:54` — middleware `web, auth, permission:operations.reports.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/ReportController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/reports/Index.tsx`, `resources/js/pages/operations/reports/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
