# CLIN-CLIENT-CLINICAL: Client Clinical

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Health and clinical
- Legacy family: `CLIN-CLIENT-CLINICAL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `clients/{client}/clinical/observations` (`clients.clinical.observations.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD clients/{client}/clinical/observations` (`clients.clinical.observations.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST clients/{client}/clinical/events` (`clients.clinical.events.store`, action `storeEvent`). Source category: **created/recorded**; controller `app/Http/Controllers/Clinical/ClientClinicalController.php:84-104`; no exact validation fields extracted.
3. Invoke only the owning control for `POST clients/{client}/clinical/observations` (`clients.clinical.observations.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Clinical/ClientClinicalController.php:60-79`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeEvent` / `ROUTE-0143` at `app/Http/Controllers/Clinical/ClientClinicalController.php:84`; it is not runtime-observed.
- **information presented** is applicable only to `observations` / `ROUTE-0144` at `app/Http/Controllers/Clinical/ClientClinicalController.php:26`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0145` at `app/Http/Controllers/Clinical/ClientClinicalController.php:60`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0143` / `storeEvent`: success app/Http/Controllers/Clinical/ClientClinicalController.php:103 `return back()->with('success', 'Clinical event recorded successfully.');`.
- `ROUTE-0145` / `store`: success app/Http/Controllers/Clinical/ClientClinicalController.php:78 `return back()->with('success', $type->label() . ' recorded successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Clinical/ClientClinicalController.php:95 `return response()->json([`; app/Http/Controllers/Clinical/ClientClinicalController.php:103 `return back()->with('success', 'Clinical event recorded successfully.');`; app/Http/Controllers/Clinical/ClientClinicalController.php:54 `return response()->json($observations);`; app/Http/Controllers/Clinical/ClientClinicalController.php:71 `return response()->json([`; app/Http/Controllers/Clinical/ClientClinicalController.php:78 `return back()->with('success', $type->label() . ' recorded successfully.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST clients/{client}/clinical/events` — `clients.clinical.events.store` — `App\Http\Controllers\Clinical\ClientClinicalController@storeEvent` — `app/Http/Controllers/Clinical/ClientClinicalController.php:84` — middleware `web, auth`
- `GET|HEAD clients/{client}/clinical/observations` — `clients.clinical.observations.index` — `App\Http\Controllers\Clinical\ClientClinicalController@observations` — `app/Http/Controllers/Clinical/ClientClinicalController.php:26` — middleware `web, auth`
- `POST clients/{client}/clinical/observations` — `clients.clinical.observations.store` — `App\Http\Controllers\Clinical\ClientClinicalController@store` — `app/Http/Controllers/Clinical/ClientClinicalController.php:60` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Clinical/ClientClinicalController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
