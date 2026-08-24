# MED-EMAR-REPORT: Emar Report

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:reports.viewAny`
- Owning module: eMAR and medications
- Legacy family: `MED-EMAR-REPORT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `emar/reports` (`emar.reports`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:reports.viewAny`.
- Exact middleware atoms: `web`, `auth`, `permission:reports.viewAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD emar/reports` (`emar.reports`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD emar/reports/export` (`emar.reports.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Emar/EmarReportController.php:511-560`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0407` at `app/Http/Controllers/Emar/EmarReportController.php:26`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-0408` at `app/Http/Controllers/Emar/EmarReportController.php:511`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/emar/Reports.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0407` / `index`: fields `date_from`.
- `ROUTE-0408` / `export`: fields `date_from`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to the requested file/report being returned or the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD emar/reports` — `emar.reports` — `App\Http\Controllers\Emar\EmarReportController@index` — `app/Http/Controllers/Emar/EmarReportController.php:26` — middleware `web, auth, permission:reports.viewAny`
- `GET|HEAD emar/reports/export` — `emar.reports.export` — `App\Http\Controllers\Emar\EmarReportController@export` — `app/Http/Controllers/Emar/EmarReportController.php:511` — middleware `web, auth, permission:reports.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Emar/EmarReportController.php`.
- Exact render/action page relationships: `resources/js/pages/emar/Reports.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
