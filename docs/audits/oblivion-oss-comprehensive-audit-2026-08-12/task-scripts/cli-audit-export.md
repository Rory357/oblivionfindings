# CLI-AUDIT-EXPORT: Audit Export

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:audit.viewAny`
- Owning module: Clients and supported people
- Legacy family: `CLI-AUDIT-EXPORT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `audit-exports/clients/{client}` (`audit.exports.client`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:audit.viewAny`.
- Exact middleware atoms: `web`, `auth`, `permission:audit.viewAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD audit-exports/clients/{client}` (`audit.exports.client`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD audit-exports/incidents/{incident}` (`audit.exports.incident`, action `exportIncident`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/AuditExportController.php:14-67`.

## Source-applicable states and transitions

- **file/report delivered** is applicable only to `exportClient` / `ROUTE-0074` at `app/Http/Controllers/AuditExportController.php:69`; it is not runtime-observed.
- **file/report delivered** is applicable only to `exportIncident` / `ROUTE-0075` at `app/Http/Controllers/AuditExportController.php:14`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to the requested file/report being returned or the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD audit-exports/clients/{client}` — `audit.exports.client` — `App\Http\Controllers\AuditExportController@exportClient` — `app/Http/Controllers/AuditExportController.php:69` — middleware `web, auth, permission:audit.viewAny`
- `GET|HEAD audit-exports/incidents/{incident}` — `audit.exports.incident` — `App\Http\Controllers\AuditExportController@exportIncident` — `app/Http/Controllers/AuditExportController.php:14` — middleware `web, auth, permission:audit.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/AuditExportController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
