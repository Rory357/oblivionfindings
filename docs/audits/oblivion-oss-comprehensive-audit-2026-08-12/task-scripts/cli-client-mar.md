# CLI-CLIENT-MAR: Client Mar

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clients.viewAny|clients.viewAssigned`
- Owning module: Clients and supported people
- Legacy family: `CLI-CLIENT-MAR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `clients/{client}/mar` (`clients.mar.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:clients.viewAny|clients.viewAssigned`.
- Exact middleware atoms: `web`, `auth`, `permission:clients.viewAny|clients.viewAssigned`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD clients/{client}/mar` (`clients.mar.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD clients/{client}/mar/export.csv` (`clients.mar.export_csv`, action `exportCsv`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/ClientMarController.php:37-82`.
3. Use `GET|HEAD operations/clients/{client}/mar` (`operations.clients.mar.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ClientMarController.php:19-35`.
4. Use `GET|HEAD operations/clients/{client}/mar/export.csv` (`operations.clients.mar.export_csv`, action `exportCsv`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/ClientMarController.php:37-82`.

## Source-applicable states and transitions

- **information presented** is applicable only to `show` / `ROUTE-0160` at `app/Http/Controllers/ClientMarController.php:19`; it is not runtime-observed.
- **file/report delivered** is applicable only to `exportCsv` / `ROUTE-0162` at `app/Http/Controllers/ClientMarController.php:37`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2005` at `app/Http/Controllers/ClientMarController.php:19`; it is not runtime-observed.
- **file/report delivered** is applicable only to `exportCsv` / `ROUTE-2007` at `app/Http/Controllers/ClientMarController.php:37`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to the requested file/report being returned or the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD clients/{client}/mar` — `clients.mar.show` — `App\Http\Controllers\ClientMarController@show` — `app/Http/Controllers/ClientMarController.php:19` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned`
- `GET|HEAD clients/{client}/mar/export.csv` — `clients.mar.export_csv` — `App\Http\Controllers\ClientMarController@exportCsv` — `app/Http/Controllers/ClientMarController.php:37` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned`
- `GET|HEAD operations/clients/{client}/mar` — `operations.clients.mar.show` — `App\Http\Controllers\ClientMarController@show` — `app/Http/Controllers/ClientMarController.php:19` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned`
- `GET|HEAD operations/clients/{client}/mar/export.csv` — `operations.clients.mar.export_csv` — `App\Http\Controllers\ClientMarController@exportCsv` — `app/Http/Controllers/ClientMarController.php:37` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ClientMarController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
