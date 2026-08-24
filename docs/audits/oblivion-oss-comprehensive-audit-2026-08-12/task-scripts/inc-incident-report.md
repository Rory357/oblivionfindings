# INC-INCIDENT-REPORT: Incident Report

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:reports.viewAny|incidents.export`, `permission:incidents.export`
- Owning module: Incidents and safeguarding
- Legacy family: `INC-INCIDENT-REPORT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `reports/incidents` (`reports.incidents.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:reports.viewAny|incidents.export`, `permission:incidents.export`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:reports.viewAny|incidents.export`, `permission:incidents.export`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD reports/incidents` (`reports.incidents.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD reports/incidents/export` (`reports.incidents.export`, action `exportCsv`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/IncidentReportController.php:30-101`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2350` at `app/Http/Controllers/IncidentReportController.php:12`; it is not runtime-observed.
- **file/report delivered** is applicable only to `exportCsv` / `ROUTE-2351` at `app/Http/Controllers/IncidentReportController.php:30`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/reports/incidents.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2351` / `exportCsv`: fields `from`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to the requested file/report being returned or the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD reports/incidents` — `reports.incidents.index` — `App\Http\Controllers\IncidentReportController@index` — `app/Http/Controllers/IncidentReportController.php:12` — middleware `web, auth, verified, permission:reports.viewAny|incidents.export`
- `GET|HEAD reports/incidents/export` — `reports.incidents.export` — `App\Http\Controllers\IncidentReportController@exportCsv` — `app/Http/Controllers/IncidentReportController.php:30` — middleware `web, auth, verified, permission:reports.viewAny|incidents.export, permission:incidents.export`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/IncidentReportController.php`.
- Exact render/action page relationships: `resources/js/pages/reports/incidents.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
