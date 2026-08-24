# MED-MEDICATIONS-REPORT: Medications Report

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:reports.viewAny`
- Owning module: eMAR and medications
- Legacy family: `MED-MEDICATIONS-REPORT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `emar/reports/export-controlled-discrepancies` (`emar.reports.export_discrepancies`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:reports.viewAny`.
- Exact middleware atoms: `web`, `auth`, `permission:reports.viewAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD emar/reports/export-controlled-discrepancies` (`emar.reports.export_discrepancies`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD emar/reports/export-mar` (`emar.reports.export_mar`, action `exportMarCsv`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/MedicationsReportController.php:171-246`.
3. Use `GET|HEAD reports/medications` (`reports.medications`, action `index`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/MedicationsReportController.php:16-169`.
4. Use `GET|HEAD reports/medications/export-controlled-discrepancies` (`reports.medications.export_discrepancies`, action `exportDiscrepanciesCsv`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/MedicationsReportController.php:248-334`.
5. Use `GET|HEAD reports/medications/export-mar` (`reports.medications.export_mar`, action `exportMarCsv`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/MedicationsReportController.php:171-246`.

## Source-applicable states and transitions

- **file/report delivered** is applicable only to `exportDiscrepanciesCsv` / `ROUTE-0409` at `app/Http/Controllers/MedicationsReportController.php:248`; it is not runtime-observed.
- **file/report delivered** is applicable only to `exportMarCsv` / `ROUTE-0410` at `app/Http/Controllers/MedicationsReportController.php:171`; it is not runtime-observed.
- **information presented** is applicable only to `index` / `ROUTE-2352` at `app/Http/Controllers/MedicationsReportController.php:16`; it is not runtime-observed.
- **file/report delivered** is applicable only to `exportDiscrepanciesCsv` / `ROUTE-2353` at `app/Http/Controllers/MedicationsReportController.php:248`; it is not runtime-observed.
- **file/report delivered** is applicable only to `exportMarCsv` / `ROUTE-2354` at `app/Http/Controllers/MedicationsReportController.php:171`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/reports/medications.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0409` / `exportDiscrepanciesCsv`: fields `date_from`.
- `ROUTE-0410` / `exportMarCsv`: fields `date_from`.
- `ROUTE-2352` / `index`: fields `date_from`.
- `ROUTE-2353` / `exportDiscrepanciesCsv`: fields `date_from`.
- `ROUTE-2354` / `exportMarCsv`: fields `date_from`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to the requested file/report being returned or the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD emar/reports/export-controlled-discrepancies` — `emar.reports.export_discrepancies` — `App\Http\Controllers\MedicationsReportController@exportDiscrepanciesCsv` — `app/Http/Controllers/MedicationsReportController.php:248` — middleware `web, auth, permission:reports.viewAny`
- `GET|HEAD emar/reports/export-mar` — `emar.reports.export_mar` — `App\Http\Controllers\MedicationsReportController@exportMarCsv` — `app/Http/Controllers/MedicationsReportController.php:171` — middleware `web, auth, permission:reports.viewAny`
- `GET|HEAD reports/medications` — `reports.medications` — `App\Http\Controllers\MedicationsReportController@index` — `app/Http/Controllers/MedicationsReportController.php:16` — middleware `web, auth, permission:reports.viewAny`
- `GET|HEAD reports/medications/export-controlled-discrepancies` — `reports.medications.export_discrepancies` — `App\Http\Controllers\MedicationsReportController@exportDiscrepanciesCsv` — `app/Http/Controllers/MedicationsReportController.php:248` — middleware `web, auth, permission:reports.viewAny`
- `GET|HEAD reports/medications/export-mar` — `reports.medications.export_mar` — `App\Http\Controllers\MedicationsReportController@exportMarCsv` — `app/Http/Controllers/MedicationsReportController.php:171` — middleware `web, auth, permission:reports.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/MedicationsReportController.php`.
- Exact render/action page relationships: `resources/js/pages/reports/medications.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
