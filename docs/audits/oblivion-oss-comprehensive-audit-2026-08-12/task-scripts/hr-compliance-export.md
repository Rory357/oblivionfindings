# HR-COMPLIANCE-EXPORT: Compliance Export

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.compliance.view`
- Owning module: Human resources
- Legacy family: `HR-COMPLIANCE-EXPORT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/compliance/export` (`hr.compliance.export`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.compliance.view`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.compliance.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/compliance/export` (`hr.compliance.export`); the route is exact, but menu visibility and runtime access were not executed.

## Source-applicable states and transitions

- **file/report delivered** is applicable only to `export` / `ROUTE-1364` at `app/Http/Controllers/Hr/ComplianceExportController.php:24`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1364` / `export`: fields `dataset`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to the requested file/report being returned or the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/compliance/export` — `hr.compliance.export` — `App\Http\Controllers\Hr\ComplianceExportController@export` — `app/Http/Controllers/Hr/ComplianceExportController.php:24` — middleware `web, auth, permission:hr.compliance.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/ComplianceExportController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
