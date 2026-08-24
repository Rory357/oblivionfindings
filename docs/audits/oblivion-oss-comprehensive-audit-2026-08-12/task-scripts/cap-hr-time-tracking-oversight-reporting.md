# CAP-HR-TIME-TRACKING-OVERSIGHT-REPORTING: Time oversight timesheets reports and export

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:timesheets.viewAny`
- Owning module: Human resources
- Legacy family: `HR-TIME-TRACKING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/time` (`hr.time.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:timesheets.viewAny`.
- Exact middleware atoms: `web`, `auth`, `permission:timesheets.viewAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/time` (`hr.time.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/time/export` (`hr.time.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/TimeTrackingController.php:840-893`.
3. Use `GET|HEAD hr/time/report/pdf` (`hr.time.report.pdf`, action `reportPdf`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/TimeTrackingController.php:948-968`.
4. Use `GET|HEAD hr/time/timesheets` (`hr.time.timesheets`, action `timesheets`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/TimeTrackingController.php:811-817`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1773` at `app/Http/Controllers/Hr/TimeTrackingController.php:146`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-1783` at `app/Http/Controllers/Hr/TimeTrackingController.php:840`; it is not runtime-observed.
- **file/report delivered** is applicable only to `reportPdf` / `ROUTE-1784` at `app/Http/Controllers/Hr/TimeTrackingController.php:948`; it is not runtime-observed.
- **information presented** is applicable only to `timesheets` / `ROUTE-1785` at `app/Http/Controllers/Hr/TimeTrackingController.php:811`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/time/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to the requested file/report being returned or the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/time` — `hr.time.index` — `App\Http\Controllers\Hr\TimeTrackingController@index` — `app/Http/Controllers/Hr/TimeTrackingController.php:146` — middleware `web, auth, permission:timesheets.viewAny`
- `GET|HEAD hr/time/export` — `hr.time.export` — `App\Http\Controllers\Hr\TimeTrackingController@export` — `app/Http/Controllers/Hr/TimeTrackingController.php:840` — middleware `web, auth, permission:timesheets.viewAny`
- `GET|HEAD hr/time/report/pdf` — `hr.time.report.pdf` — `App\Http\Controllers\Hr\TimeTrackingController@reportPdf` — `app/Http/Controllers/Hr/TimeTrackingController.php:948` — middleware `web, auth, permission:timesheets.viewAny`
- `GET|HEAD hr/time/timesheets` — `hr.time.timesheets` — `App\Http\Controllers\Hr\TimeTrackingController@timesheets` — `app/Http/Controllers/Hr/TimeTrackingController.php:811` — middleware `web, auth, permission:timesheets.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/TimeTrackingController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/time/index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
