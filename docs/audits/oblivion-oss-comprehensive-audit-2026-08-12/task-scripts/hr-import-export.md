# HR-IMPORT-EXPORT: Import Export

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.employees.manage`
- Owning module: Human resources
- Legacy family: `HR-IMPORT-EXPORT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/import-export` (`hr.import-export.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.employees.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.employees.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/import-export` (`hr.import-export.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/import-export/template` (`hr.import-export.template`, action `template`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/ImportExportController.php:70-82`.
3. Invoke only the owning control for `POST hr/import-export/export` (`hr.import-export.export`, action `export`). Source category: **mutation outcome source gap (export)**; controller `app/Http/Controllers/Hr/ImportExportController.php:43-65`; no exact validation fields extracted.
4. Invoke only the owning control for `POST hr/import-export/import` (`hr.import-export.import`, action `import`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/ImportExportController.php:87-102`; `file`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1482` at `app/Http/Controllers/Hr/ImportExportController.php:24`; it is not runtime-observed.
- **mutation outcome source gap (export)** is applicable only to `export` / `ROUTE-1483` at `app/Http/Controllers/Hr/ImportExportController.php:43`; it is not runtime-observed.
- **created/recorded** is applicable only to `import` / `ROUTE-1484` at `app/Http/Controllers/Hr/ImportExportController.php:87`; it is not runtime-observed.
- **information presented** is applicable only to `template` / `ROUTE-1485` at `app/Http/Controllers/Hr/ImportExportController.php:70`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/import-export/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1484` / `import`: fields `file`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/ImportExportController.php:31 `return Inertia::render('hr/import-export/index', [`; app/Http/Controllers/Hr/ImportExportController.php:60 `return response()->streamDownload(function () use ($csv) {`; app/Http/Controllers/Hr/ImportExportController.php:101 `return back()->with('importResult', $result);`; app/Http/Controllers/Hr/ImportExportController.php:77 `return response()->streamDownload(function () use ($csv) {`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/import-export` — `hr.import-export.index` — `App\Http\Controllers\Hr\ImportExportController@index` — `app/Http/Controllers/Hr/ImportExportController.php:24` — middleware `web, auth, permission:hr.employees.manage`
- `POST hr/import-export/export` — `hr.import-export.export` — `App\Http\Controllers\Hr\ImportExportController@export` — `app/Http/Controllers/Hr/ImportExportController.php:43` — middleware `web, auth, permission:hr.employees.manage`
- `POST hr/import-export/import` — `hr.import-export.import` — `App\Http\Controllers\Hr\ImportExportController@import` — `app/Http/Controllers/Hr/ImportExportController.php:87` — middleware `web, auth, permission:hr.employees.manage`
- `GET|HEAD hr/import-export/template` — `hr.import-export.template` — `App\Http\Controllers\Hr\ImportExportController@template` — `app/Http/Controllers/Hr/ImportExportController.php:70` — middleware `web, auth, permission:hr.employees.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/ImportExportController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/import-export/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
