# CLI-CLIENT-RISK: Client Risk

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:risks.viewAny|risks.viewAssigned`, `permission:risks.create`, `permission:risks.delete`, `permission:risks.update`
- Owning module: Clients and supported people
- Legacy family: `CLI-CLIENT-RISK`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `clients/{client}/risks` (`clients.risks.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:risks.viewAny|risks.viewAssigned`, `permission:risks.create`, `permission:risks.delete`, `permission:risks.update`.
- Exact middleware atoms: `web`, `auth`, `permission:risks.viewAny|risks.viewAssigned`, `permission:risks.create`, `permission:risks.delete`, `permission:risks.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD clients/{client}/risks` (`clients.risks.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/clients/{client}/risks` (`operations.clients.risks.index`, action `index`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ClientRiskController.php:12-35`.
3. Invoke only the owning control for `POST clients/{client}/risks` (`clients.risks.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientRiskController.php:37-57`; `label`.
4. Invoke only the owning control for `DELETE clients/{client}/risks/{risk}` (`clients.risks.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ClientRiskController.php:81-89`; no exact validation fields extracted.
5. Invoke only the owning control for `PUT clients/{client}/risks/{risk}` (`clients.risks.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientRiskController.php:59-79`; `label`.
6. Invoke only the owning control for `POST operations/clients/{client}/risks` (`operations.clients.risks.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientRiskController.php:37-57`; `label`.
7. Invoke only the owning control for `DELETE operations/clients/{client}/risks/{risk}` (`operations.clients.risks.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ClientRiskController.php:81-89`; no exact validation fields extracted.
8. Invoke only the owning control for `PUT operations/clients/{client}/risks/{risk}` (`operations.clients.risks.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientRiskController.php:59-79`; `label`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0190` at `app/Http/Controllers/ClientRiskController.php:12`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0191` at `app/Http/Controllers/ClientRiskController.php:37`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0192` at `app/Http/Controllers/ClientRiskController.php:81`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0193` at `app/Http/Controllers/ClientRiskController.php:59`; it is not runtime-observed.
- **information presented** is applicable only to `index` / `ROUTE-2042` at `app/Http/Controllers/ClientRiskController.php:12`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2043` at `app/Http/Controllers/ClientRiskController.php:37`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2044` at `app/Http/Controllers/ClientRiskController.php:81`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2045` at `app/Http/Controllers/ClientRiskController.php:59`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/clients/risks.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0191` / `store`: fields `label`.
- `ROUTE-0193` / `update`: fields `label`.
- `ROUTE-2043` / `store`: fields `label`.
- `ROUTE-2045` / `update`: fields `label`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ClientRiskController.php:50 `ClientRisk::create([`; app/Http/Controllers/ClientRiskController.php:87 `$risk->delete();`; app/Http/Controllers/ClientRiskController.php:73 `$risk->update([`; responses app/Http/Controllers/ClientRiskController.php:26 `return inertia('operations/clients/risks', [`; app/Http/Controllers/ClientRiskController.php:56 `return back();`; app/Http/Controllers/ClientRiskController.php:88 `return back();`; app/Http/Controllers/ClientRiskController.php:78 `return back();`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD clients/{client}/risks` — `clients.risks.index` — `App\Http\Controllers\ClientRiskController@index` — `app/Http/Controllers/ClientRiskController.php:12` — middleware `web, auth, permission:risks.viewAny|risks.viewAssigned`
- `POST clients/{client}/risks` — `clients.risks.store` — `App\Http\Controllers\ClientRiskController@store` — `app/Http/Controllers/ClientRiskController.php:37` — middleware `web, auth, permission:risks.create`
- `DELETE clients/{client}/risks/{risk}` — `clients.risks.destroy` — `App\Http\Controllers\ClientRiskController@destroy` — `app/Http/Controllers/ClientRiskController.php:81` — middleware `web, auth, permission:risks.delete`
- `PUT clients/{client}/risks/{risk}` — `clients.risks.update` — `App\Http\Controllers\ClientRiskController@update` — `app/Http/Controllers/ClientRiskController.php:59` — middleware `web, auth, permission:risks.update`
- `GET|HEAD operations/clients/{client}/risks` — `operations.clients.risks.index` — `App\Http\Controllers\ClientRiskController@index` — `app/Http/Controllers/ClientRiskController.php:12` — middleware `web, auth, permission:risks.viewAny|risks.viewAssigned`
- `POST operations/clients/{client}/risks` — `operations.clients.risks.store` — `App\Http\Controllers\ClientRiskController@store` — `app/Http/Controllers/ClientRiskController.php:37` — middleware `web, auth, permission:risks.create`
- `DELETE operations/clients/{client}/risks/{risk}` — `operations.clients.risks.destroy` — `App\Http\Controllers\ClientRiskController@destroy` — `app/Http/Controllers/ClientRiskController.php:81` — middleware `web, auth, permission:risks.delete`
- `PUT operations/clients/{client}/risks/{risk}` — `operations.clients.risks.update` — `App\Http\Controllers\ClientRiskController@update` — `app/Http/Controllers/ClientRiskController.php:59` — middleware `web, auth, permission:risks.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ClientRiskController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/clients/risks.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
