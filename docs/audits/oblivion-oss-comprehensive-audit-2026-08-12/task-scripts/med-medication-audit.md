# MED-MEDICATION-AUDIT: Medication Audit

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.reports.export`, `permission:medications.audit.view`
- Owning module: eMAR and medications
- Legacy family: `MED-MEDICATION-AUDIT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `emar/audit/export` (`emar.audit.export`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.reports.export`, `permission:medications.audit.view`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.reports.export`, `permission:medications.audit.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD emar/audit/export` (`emar.audit.export`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD medications/audit` (`medications.audit.index`, action `index`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/MedicationAuditController.php:28-74`.
3. Use `GET|HEAD medications/audit/export` (`medications.audit.export`, action `exportCsv`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/MedicationAuditController.php:76-105`.

## Source-applicable states and transitions

- **file/report delivered** is applicable only to `exportCsv` / `ROUTE-0335` at `app/Http/Controllers/MedicationAuditController.php:76`; it is not runtime-observed.
- **information presented** is applicable only to `index` / `ROUTE-1874` at `app/Http/Controllers/MedicationAuditController.php:28`; it is not runtime-observed.
- **file/report delivered** is applicable only to `exportCsv` / `ROUTE-1875` at `app/Http/Controllers/MedicationAuditController.php:76`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/medications/audit.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to the requested file/report being returned or the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD emar/audit/export` — `emar.audit.export` — `App\Http\Controllers\MedicationAuditController@exportCsv` — `app/Http/Controllers/MedicationAuditController.php:76` — middleware `web, auth, permission:medications.reports.export`
- `GET|HEAD medications/audit` — `medications.audit.index` — `App\Http\Controllers\MedicationAuditController@index` — `app/Http/Controllers/MedicationAuditController.php:28` — middleware `web, auth, permission:medications.audit.view`
- `GET|HEAD medications/audit/export` — `medications.audit.export` — `App\Http\Controllers\MedicationAuditController@exportCsv` — `app/Http/Controllers/MedicationAuditController.php:76` — middleware `web, auth, permission:medications.reports.export`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/MedicationAuditController.php`.
- Exact render/action page relationships: `resources/js/pages/medications/audit.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
