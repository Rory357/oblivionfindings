# HR-HR-REPORT: Hr Report

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.reports.view`, `permission:hr.reports.export`
- Owning module: Human resources
- Legacy family: `HR-HR-REPORT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/reports` (`hr.reports.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.reports.view`, `permission:hr.reports.export`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.reports.view`, `permission:hr.reports.export`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/reports` (`hr.reports.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|POST|HEAD hr/reports/export` (`hr.reports.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/HrReportController.php:181-207`.
3. Use `GET|HEAD hr/reports/exports/{export}` (`hr.reports.exports.show`, action `showExport`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/HrReportController.php:209-242`.
4. Use `GET|HEAD hr/reports/exports/{export}/download` (`hr.reports.exports.download`, action `downloadExport`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/HrReportController.php:244-257`.
5. Use `GET|POST|HEAD hr/reports/generate` (`hr.reports.generate`, action `generate`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/HrReportController.php:135-179`.
6. Invoke only the owning control for `POST hr/reports/subscriptions` (`hr.reports.subscriptions.store`, action `storeSubscription`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/HrReportController.php:259-323`; `report_type`.
7. Invoke only the owning control for `PUT hr/reports/subscriptions/{subscription}` (`hr.reports.subscriptions.update`, action `updateSubscription`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/HrReportController.php:325-410`; `report_type`.
8. Invoke only the owning control for `POST hr/reports/subscriptions/{subscription}/toggle-active` (`hr.reports.subscriptions.toggleActive`, action `toggleSubscription`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/HrReportController.php:412-427`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1718` at `app/Http/Controllers/Hr/HrReportController.php:24`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-1722` at `app/Http/Controllers/Hr/HrReportController.php:181`; it is not runtime-observed.
- **information presented** is applicable only to `showExport` / `ROUTE-1723` at `app/Http/Controllers/Hr/HrReportController.php:209`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadExport` / `ROUTE-1724` at `app/Http/Controllers/Hr/HrReportController.php:244`; it is not runtime-observed.
- **information presented** is applicable only to `generate` / `ROUTE-1725` at `app/Http/Controllers/Hr/HrReportController.php:135`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeSubscription` / `ROUTE-1731` at `app/Http/Controllers/Hr/HrReportController.php:259`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateSubscription` / `ROUTE-1732` at `app/Http/Controllers/Hr/HrReportController.php:325`; it is not runtime-observed.
- **updated/revised** is applicable only to `toggleSubscription` / `ROUTE-1733` at `app/Http/Controllers/Hr/HrReportController.php:412`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/reports/index.tsx`, `resources/js/pages/hr/reports/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1722` / `export`: fields `report_type`.
- `ROUTE-1725` / `generate`: fields `report_type`.
- `ROUTE-1731` / `storeSubscription`: fields `report_type`; success app/Http/Controllers/Hr/HrReportController.php:322 `return redirect()->back()->with('success', 'Report subscription created.');`; failure app/Http/Controllers/Hr/HrReportController.php:289 `return redirect()->back()->withErrors(['timezone' => 'Timezone is not supported.']);`.
- `ROUTE-1732` / `updateSubscription`: fields `report_type`; success app/Http/Controllers/Hr/HrReportController.php:409 `return redirect()->back()->with('success', 'Report subscription updated.');`; failure app/Http/Controllers/Hr/HrReportController.php:356 `return redirect()->back()->withErrors(['timezone' => 'Timezone is not supported.']);`.
- `ROUTE-1733` / `toggleSubscription`: success app/Http/Controllers/Hr/HrReportController.php:426 `return redirect()->back()->with('success', $subscription->is_active ? 'Subscription resumed.' : 'Subscription paused.');`.

## Failure and recovery paths

- `storeSubscription`: app/Http/Controllers/Hr/HrReportController.php:289 `return redirect()->back()->withErrors(['timezone' => 'Timezone is not supported.']);`.
- `updateSubscription`: app/Http/Controllers/Hr/HrReportController.php:356 `return redirect()->back()->withErrors(['timezone' => 'Timezone is not supported.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/HrReportController.php:320 `$subscription->save();`; app/Http/Controllers/Hr/HrReportController.php:407 `$subscription->save();`; app/Http/Controllers/Hr/HrReportController.php:424 `$subscription->save();`; responses app/Http/Controllers/Hr/HrReportController.php:90 `return Inertia::render('hr/reports/index', [`; app/Http/Controllers/Hr/HrReportController.php:204 `return Storage::disk('private')->download($export->storage_path, $filename, [`; app/Http/Controllers/Hr/HrReportController.php:228 `return Inertia::render('hr/reports/show', [`; app/Http/Controllers/Hr/HrReportController.php:254 `return Storage::disk('private')->download($export->storage_path, $filename, [`; app/Http/Controllers/Hr/HrReportController.php:165 `return Inertia::render('hr/reports/show', [`; app/Http/Controllers/Hr/HrReportController.php:289 `return redirect()->back()->withErrors(['timezone' => 'Timezone is not supported.']);`; app/Http/Controllers/Hr/HrReportController.php:322 `return redirect()->back()->with('success', 'Report subscription created.');`; app/Http/Controllers/Hr/HrReportController.php:356 `return redirect()->back()->withErrors(['timezone' => 'Timezone is not supported.']);`; app/Http/Controllers/Hr/HrReportController.php:409 `return redirect()->back()->with('success', 'Report subscription updated.');`; app/Http/Controllers/Hr/HrReportController.php:426 `return redirect()->back()->with('success', $subscription->is_active ? 'Subscription resumed.' : 'Subscription paused.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/reports` — `hr.reports.index` — `App\Http\Controllers\Hr\HrReportController@index` — `app/Http/Controllers/Hr/HrReportController.php:24` — middleware `web, auth, permission:hr.reports.view`
- `GET|POST|HEAD hr/reports/export` — `hr.reports.export` — `App\Http\Controllers\Hr\HrReportController@export` — `app/Http/Controllers/Hr/HrReportController.php:181` — middleware `web, auth, permission:hr.reports.view, permission:hr.reports.export`
- `GET|HEAD hr/reports/exports/{export}` — `hr.reports.exports.show` — `App\Http\Controllers\Hr\HrReportController@showExport` — `app/Http/Controllers/Hr/HrReportController.php:209` — middleware `web, auth, permission:hr.reports.view`
- `GET|HEAD hr/reports/exports/{export}/download` — `hr.reports.exports.download` — `App\Http\Controllers\Hr\HrReportController@downloadExport` — `app/Http/Controllers/Hr/HrReportController.php:244` — middleware `web, auth, permission:hr.reports.view, permission:hr.reports.export`
- `GET|POST|HEAD hr/reports/generate` — `hr.reports.generate` — `App\Http\Controllers\Hr\HrReportController@generate` — `app/Http/Controllers/Hr/HrReportController.php:135` — middleware `web, auth, permission:hr.reports.view`
- `POST hr/reports/subscriptions` — `hr.reports.subscriptions.store` — `App\Http\Controllers\Hr\HrReportController@storeSubscription` — `app/Http/Controllers/Hr/HrReportController.php:259` — middleware `web, auth, permission:hr.reports.view, permission:hr.reports.export`
- `PUT hr/reports/subscriptions/{subscription}` — `hr.reports.subscriptions.update` — `App\Http\Controllers\Hr\HrReportController@updateSubscription` — `app/Http/Controllers/Hr/HrReportController.php:325` — middleware `web, auth, permission:hr.reports.view, permission:hr.reports.export`
- `POST hr/reports/subscriptions/{subscription}/toggle-active` — `hr.reports.subscriptions.toggleActive` — `App\Http\Controllers\Hr\HrReportController@toggleSubscription` — `app/Http/Controllers/Hr/HrReportController.php:412` — middleware `web, auth, permission:hr.reports.view, permission:hr.reports.export`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/HrReportController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/reports/index.tsx`, `resources/js/pages/hr/reports/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
