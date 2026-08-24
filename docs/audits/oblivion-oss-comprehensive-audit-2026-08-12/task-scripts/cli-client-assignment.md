# CLI-CLIENT-ASSIGNMENT: Client Assignment

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clients.assignments.update`
- Owning module: Clients and supported people
- Legacy family: `CLI-CLIENT-ASSIGNMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `clients/{client}/assignments` (`clients.assignments.edit`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:clients.assignments.update`.
- Exact middleware atoms: `web`, `auth`, `permission:clients.assignments.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD clients/{client}/assignments` (`clients.assignments.edit`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/clients/{client}/assignments` (`operations.clients.assignments.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ClientAssignmentController.php:12-39`.
3. Invoke only the owning control for `PUT clients/{client}/assignments` (`clients.assignments.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientAssignmentController.php:41-92`; `user_ids`.
4. Invoke only the owning control for `PUT operations/clients/{client}/assignments` (`operations.clients.assignments.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientAssignmentController.php:41-92`; `user_ids`.

## Source-applicable states and transitions

- **information presented** is applicable only to `edit` / `ROUTE-0130` at `app/Http/Controllers/ClientAssignmentController.php:12`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0131` at `app/Http/Controllers/ClientAssignmentController.php:41`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-1942` at `app/Http/Controllers/ClientAssignmentController.php:12`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1943` at `app/Http/Controllers/ClientAssignmentController.php:41`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/clients/assignments.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0131` / `update`: fields `user_ids`; success app/Http/Controllers/ClientAssignmentController.php:86 `return back()->with('success', 'Assignments updated.');`; app/Http/Controllers/ClientAssignmentController.php:91 `->with('success', 'Assignments updated.');`.
- `ROUTE-1943` / `update`: fields `user_ids`; success app/Http/Controllers/ClientAssignmentController.php:86 `return back()->with('success', 'Assignments updated.');`; app/Http/Controllers/ClientAssignmentController.php:91 `->with('success', 'Assignments updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ClientAssignmentController.php:69 `$client->supportWorkers()->sync($allowedWorkerIds);`; responses app/Http/Controllers/ClientAssignmentController.php:35 `return response()->json($payload);`; app/Http/Controllers/ClientAssignmentController.php:38 `return inertia('operations/clients/assignments', $payload);`; app/Http/Controllers/ClientAssignmentController.php:86 `return back()->with('success', 'Assignments updated.');`; app/Http/Controllers/ClientAssignmentController.php:89 `return redirect()`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD clients/{client}/assignments` — `clients.assignments.edit` — `App\Http\Controllers\ClientAssignmentController@edit` — `app/Http/Controllers/ClientAssignmentController.php:12` — middleware `web, auth, permission:clients.assignments.update`
- `PUT clients/{client}/assignments` — `clients.assignments.update` — `App\Http\Controllers\ClientAssignmentController@update` — `app/Http/Controllers/ClientAssignmentController.php:41` — middleware `web, auth, permission:clients.assignments.update`
- `GET|HEAD operations/clients/{client}/assignments` — `operations.clients.assignments.edit` — `App\Http\Controllers\ClientAssignmentController@edit` — `app/Http/Controllers/ClientAssignmentController.php:12` — middleware `web, auth, permission:clients.assignments.update`
- `PUT operations/clients/{client}/assignments` — `operations.clients.assignments.update` — `App\Http\Controllers\ClientAssignmentController@update` — `app/Http/Controllers/ClientAssignmentController.php:41` — middleware `web, auth, permission:clients.assignments.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ClientAssignmentController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/clients/assignments.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
