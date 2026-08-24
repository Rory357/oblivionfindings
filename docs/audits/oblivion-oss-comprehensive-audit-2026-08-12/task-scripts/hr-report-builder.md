# HR-REPORT-BUILDER: Report Builder

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.reports.view`
- Owning module: Human resources
- Legacy family: `HR-REPORT-BUILDER`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/reports/builder` (`hr.reports.builder`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.reports.view`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.reports.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/reports/builder` (`hr.reports.builder`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/reports/saved` (`hr.reports.saved`, action `index`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/ReportBuilderController.php:22-51`.
3. Use `GET|HEAD hr/reports/saved/{report}/export` (`hr.reports.saved.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/ReportBuilderController.php:170-187`.
4. Invoke only the owning control for `POST hr/reports/builder` (`hr.reports.builder.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/ReportBuilderController.php:111-142`; `name`.
5. Invoke only the owning control for `POST hr/reports/builder/preview` (`hr.reports.builder.preview`, action `preview`). Source category: **mutation outcome source gap (preview)**; controller `app/Http/Controllers/Hr/ReportBuilderController.php:71-105`; `report_type`.
6. Invoke only the owning control for `DELETE hr/reports/saved/{report}` (`hr.reports.saved.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/ReportBuilderController.php:193-201`; no exact validation fields extracted.
7. Invoke only the owning control for `POST hr/reports/saved/{report}/run` (`hr.reports.saved.run`, action `run`). Source category: **mutation outcome source gap (run)**; controller `app/Http/Controllers/Hr/ReportBuilderController.php:148-164`; no exact validation fields extracted.
8. Invoke only the owning control for `POST hr/reports/saved/{report}/schedule` (`hr.reports.saved.schedule`, action `schedule`). Source category: **mutation outcome source gap (schedule)**; controller `app/Http/Controllers/Hr/ReportBuilderController.php:207-228`; `is_scheduled`.

## Source-applicable states and transitions

- **information presented** is applicable only to `create` / `ROUTE-1719` at `app/Http/Controllers/Hr/ReportBuilderController.php:57`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1720` at `app/Http/Controllers/Hr/ReportBuilderController.php:111`; it is not runtime-observed.
- **mutation outcome source gap (preview)** is applicable only to `preview` / `ROUTE-1721` at `app/Http/Controllers/Hr/ReportBuilderController.php:71`; it is not runtime-observed.
- **information presented** is applicable only to `index` / `ROUTE-1726` at `app/Http/Controllers/Hr/ReportBuilderController.php:22`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1727` at `app/Http/Controllers/Hr/ReportBuilderController.php:193`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-1728` at `app/Http/Controllers/Hr/ReportBuilderController.php:170`; it is not runtime-observed.
- **mutation outcome source gap (run)** is applicable only to `run` / `ROUTE-1729` at `app/Http/Controllers/Hr/ReportBuilderController.php:148`; it is not runtime-observed.
- **mutation outcome source gap (schedule)** is applicable only to `schedule` / `ROUTE-1730` at `app/Http/Controllers/Hr/ReportBuilderController.php:207`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/reports/builder.tsx`, `resources/js/pages/hr/reports/saved.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1720` / `store`: fields `name`; success app/Http/Controllers/Hr/ReportBuilderController.php:141 `return redirect()->route('hr.reports.saved')->with('success', 'Report saved successfully.');`.
- `ROUTE-1721` / `preview`: fields `report_type`.
- `ROUTE-1727` / `destroy`: success app/Http/Controllers/Hr/ReportBuilderController.php:200 `return redirect()->route('hr.reports.saved')->with('success', 'Report deleted.');`.
- `ROUTE-1730` / `schedule`: fields `is_scheduled`; success app/Http/Controllers/Hr/ReportBuilderController.php:227 `return redirect()->back()->with('success', $message);`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/ReportBuilderController.php:128 `HrSavedReport::create([`; app/Http/Controllers/Hr/ReportBuilderController.php:198 `$report->delete();`; app/Http/Controllers/Hr/ReportBuilderController.php:219 `$report->update([`; responses app/Http/Controllers/Hr/ReportBuilderController.php:62 `return Inertia::render('hr/reports/builder', [`; app/Http/Controllers/Hr/ReportBuilderController.php:141 `return redirect()->route('hr.reports.saved')->with('success', 'Report saved successfully.');`; app/Http/Controllers/Hr/ReportBuilderController.php:100 `return response()->json([`; app/Http/Controllers/Hr/ReportBuilderController.php:47 `return Inertia::render('hr/reports/saved', [`; app/Http/Controllers/Hr/ReportBuilderController.php:200 `return redirect()->route('hr.reports.saved')->with('success', 'Report deleted.');`; app/Http/Controllers/Hr/ReportBuilderController.php:183 `return response($csv, 200, [`; app/Http/Controllers/Hr/ReportBuilderController.php:155 `return response()->json([`; app/Http/Controllers/Hr/ReportBuilderController.php:227 `return redirect()->back()->with('success', $message);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/reports/builder` — `hr.reports.builder` — `App\Http\Controllers\Hr\ReportBuilderController@create` — `app/Http/Controllers/Hr/ReportBuilderController.php:57` — middleware `web, auth, permission:hr.reports.view`
- `POST hr/reports/builder` — `hr.reports.builder.store` — `App\Http\Controllers\Hr\ReportBuilderController@store` — `app/Http/Controllers/Hr/ReportBuilderController.php:111` — middleware `web, auth, permission:hr.reports.view`
- `POST hr/reports/builder/preview` — `hr.reports.builder.preview` — `App\Http\Controllers\Hr\ReportBuilderController@preview` — `app/Http/Controllers/Hr/ReportBuilderController.php:71` — middleware `web, auth, permission:hr.reports.view`
- `GET|HEAD hr/reports/saved` — `hr.reports.saved` — `App\Http\Controllers\Hr\ReportBuilderController@index` — `app/Http/Controllers/Hr/ReportBuilderController.php:22` — middleware `web, auth, permission:hr.reports.view`
- `DELETE hr/reports/saved/{report}` — `hr.reports.saved.destroy` — `App\Http\Controllers\Hr\ReportBuilderController@destroy` — `app/Http/Controllers/Hr/ReportBuilderController.php:193` — middleware `web, auth, permission:hr.reports.view`
- `GET|HEAD hr/reports/saved/{report}/export` — `hr.reports.saved.export` — `App\Http\Controllers\Hr\ReportBuilderController@export` — `app/Http/Controllers/Hr/ReportBuilderController.php:170` — middleware `web, auth, permission:hr.reports.view`
- `POST hr/reports/saved/{report}/run` — `hr.reports.saved.run` — `App\Http\Controllers\Hr\ReportBuilderController@run` — `app/Http/Controllers/Hr/ReportBuilderController.php:148` — middleware `web, auth, permission:hr.reports.view`
- `POST hr/reports/saved/{report}/schedule` — `hr.reports.saved.schedule` — `App\Http\Controllers\Hr\ReportBuilderController@schedule` — `app/Http/Controllers/Hr/ReportBuilderController.php:207` — middleware `web, auth, permission:hr.reports.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/ReportBuilderController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/reports/builder.tsx`, `resources/js/pages/hr/reports/saved.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
