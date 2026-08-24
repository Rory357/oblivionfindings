# OPS-HANDOVER: Handover

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:handovers.viewAny|shifts.viewAny|shifts.viewAssigned|shifts.update|shifts.manageAny`, `permission:handovers.create|shifts.update|shifts.manageAny`
- Owning module: Operations and rostering
- Legacy family: `OPS-HANDOVER`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/handovers` (`operations.handovers.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:handovers.viewAny|shifts.viewAny|shifts.viewAssigned|shifts.update|shifts.manageAny`, `permission:handovers.create|shifts.update|shifts.manageAny`.
- Exact middleware atoms: `web`, `auth`, `permission:handovers.viewAny|shifts.viewAny|shifts.viewAssigned|shifts.update|shifts.manageAny`, `permission:handovers.create|shifts.update|shifts.manageAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/handovers` (`operations.handovers.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/handovers/{handover}` (`operations.handovers.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/HandoverController.php:97-127`.
3. Invoke only the owning control for `POST operations/handovers` (`operations.handovers.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/HandoverController.php:129-187`; `shift_id`.
4. Invoke only the owning control for `PUT operations/handovers/{handover}` (`operations.handovers.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/HandoverController.php:189-234`; `incoming_shift_id`.
5. Invoke only the owning control for `PATCH operations/handovers/{handover}/acknowledge` (`operations.handovers.acknowledge`, action `acknowledge`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Operations/HandoverController.php:262-282`; no exact validation fields extracted.
6. Invoke only the owning control for `PATCH operations/handovers/{handover}/submit` (`operations.handovers.submit`, action `submit`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/HandoverController.php:236-260`; no exact validation fields extracted.
7. Invoke only the owning control for `POST operations/shifts/{shift}/handover` (`operations.shifts.handover.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/HandoverController.php:129-187`; `shift_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2087` at `app/Http/Controllers/Operations/HandoverController.php:23`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2088` at `app/Http/Controllers/Operations/HandoverController.php:129`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2089` at `app/Http/Controllers/Operations/HandoverController.php:97`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2090` at `app/Http/Controllers/Operations/HandoverController.php:189`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `acknowledge` / `ROUTE-2091` at `app/Http/Controllers/Operations/HandoverController.php:262`; it is not runtime-observed.
- **created/recorded** is applicable only to `submit` / `ROUTE-2092` at `app/Http/Controllers/Operations/HandoverController.php:236`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2203` at `app/Http/Controllers/Operations/HandoverController.php:129`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/handovers/Index.tsx`, `resources/js/pages/operations/handovers/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2087` / `index`: fields `week`.
- `ROUTE-2088` / `store`: fields `shift_id`; failure app/Http/Controllers/Operations/HandoverController.php:167 `abort(403);`.
- `ROUTE-2090` / `update`: fields `incoming_shift_id`; success app/Http/Controllers/Operations/HandoverController.php:233 `return redirect()->back()->with('success', 'Handover updated.');`.
- `ROUTE-2091` / `acknowledge`: success app/Http/Controllers/Operations/HandoverController.php:281 `return redirect()->back()->with('success', 'Handover acknowledged.');`.
- `ROUTE-2092` / `submit`: success app/Http/Controllers/Operations/HandoverController.php:259 `return redirect()->back()->with('success', 'Handover submitted.');`.
- `ROUTE-2203` / `store`: fields `shift_id`; failure app/Http/Controllers/Operations/HandoverController.php:167 `abort(403);`.

## Failure and recovery paths

- `store`: app/Http/Controllers/Operations/HandoverController.php:167 `abort(403);`.
- `store`: app/Http/Controllers/Operations/HandoverController.php:167 `abort(403);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/HandoverController.php:170 `$result = $this->handoverService->save($outgoingShift, $auth, [`; responses app/Http/Controllers/Operations/HandoverController.php:80 `return inertia('operations/handovers/Index', [`; app/Http/Controllers/Operations/HandoverController.php:183 `return redirect()->back()->with(`; app/Http/Controllers/Operations/HandoverController.php:124 `return inertia('operations/handovers/Show', [`; app/Http/Controllers/Operations/HandoverController.php:233 `return redirect()->back()->with('success', 'Handover updated.');`; app/Http/Controllers/Operations/HandoverController.php:281 `return redirect()->back()->with('success', 'Handover acknowledged.');`; app/Http/Controllers/Operations/HandoverController.php:259 `return redirect()->back()->with('success', 'Handover submitted.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/handovers` — `operations.handovers.index` — `App\Http\Controllers\Operations\HandoverController@index` — `app/Http/Controllers/Operations/HandoverController.php:23` — middleware `web, auth, permission:handovers.viewAny|shifts.viewAny|shifts.viewAssigned|shifts.update|shifts.manageAny`
- `POST operations/handovers` — `operations.handovers.store` — `App\Http\Controllers\Operations\HandoverController@store` — `app/Http/Controllers/Operations/HandoverController.php:129` — middleware `web, auth, permission:handovers.create|shifts.update|shifts.manageAny`
- `GET|HEAD operations/handovers/{handover}` — `operations.handovers.show` — `App\Http\Controllers\Operations\HandoverController@show` — `app/Http/Controllers/Operations/HandoverController.php:97` — middleware `web, auth, permission:handovers.viewAny|shifts.viewAny|shifts.viewAssigned|shifts.update|shifts.manageAny`
- `PUT operations/handovers/{handover}` — `operations.handovers.update` — `App\Http\Controllers\Operations\HandoverController@update` — `app/Http/Controllers/Operations/HandoverController.php:189` — middleware `web, auth, permission:handovers.create|shifts.update|shifts.manageAny`
- `PATCH operations/handovers/{handover}/acknowledge` — `operations.handovers.acknowledge` — `App\Http\Controllers\Operations\HandoverController@acknowledge` — `app/Http/Controllers/Operations/HandoverController.php:262` — middleware `web, auth, permission:handovers.viewAny|shifts.viewAny|shifts.viewAssigned|shifts.update|shifts.manageAny`
- `PATCH operations/handovers/{handover}/submit` — `operations.handovers.submit` — `App\Http\Controllers\Operations\HandoverController@submit` — `app/Http/Controllers/Operations/HandoverController.php:236` — middleware `web, auth, permission:handovers.create|shifts.update|shifts.manageAny`
- `POST operations/shifts/{shift}/handover` — `operations.shifts.handover.store` — `App\Http\Controllers\Operations\HandoverController@store` — `app/Http/Controllers/Operations/HandoverController.php:129` — middleware `web, auth, permission:handovers.create|shifts.update|shifts.manageAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/HandoverController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/handovers/Index.tsx`, `resources/js/pages/operations/handovers/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
