# FLEET-MILEAGE: Mileage

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.mileage.approve|fleet.manage`
- Owning module: Fleet and vehicles
- Legacy family: `FLEET-MILEAGE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `fleet-assets/mileage` (`fleet-assets.mileage.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.mileage.approve|fleet.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.mileage.approve|fleet.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD fleet-assets/mileage` (`fleet-assets.mileage.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD fleet-assets/mileage/create` (`fleet-assets.mileage.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/MileageController.php:187-190`.
3. Use `GET|HEAD fleet-assets/mileage/export` (`fleet-assets.mileage.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/FleetAssets/MileageController.php:286-332`.
4. Invoke only the owning control for `POST fleet-assets/mileage` (`fleet-assets.mileage.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/FleetAssets/MileageController.php:211-239`; `date`.
5. Invoke only the owning control for `POST fleet-assets/mileage/{trip}/approve` (`fleet-assets.mileage.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/FleetAssets/MileageController.php:241-255`; no exact validation fields extracted.
6. Invoke only the owning control for `POST fleet-assets/mileage/{trip}/mark-paid` (`fleet-assets.mileage.mark-paid`, action `markPaid`). Source category: **mutation outcome source gap (markPaid)**; controller `app/Http/Controllers/FleetAssets/MileageController.php:273-284`; no exact validation fields extracted.
7. Invoke only the owning control for `POST fleet-assets/mileage/{trip}/reject` (`fleet-assets.mileage.reject`, action `reject`). Source category: **rejected/returned**; controller `app/Http/Controllers/FleetAssets/MileageController.php:257-271`; `notes`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0790` at `app/Http/Controllers/FleetAssets/MileageController.php:16`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0791` at `app/Http/Controllers/FleetAssets/MileageController.php:211`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-0792` at `app/Http/Controllers/FleetAssets/MileageController.php:241`; it is not runtime-observed.
- **mutation outcome source gap (markPaid)** is applicable only to `markPaid` / `ROUTE-0793` at `app/Http/Controllers/FleetAssets/MileageController.php:273`; it is not runtime-observed.
- **rejected/returned** is applicable only to `reject` / `ROUTE-0794` at `app/Http/Controllers/FleetAssets/MileageController.php:257`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0795` at `app/Http/Controllers/FleetAssets/MileageController.php:187`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-0796` at `app/Http/Controllers/FleetAssets/MileageController.php:286`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/fleet-assets/mileage/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0791` / `store`: fields `date`; success app/Http/Controllers/FleetAssets/MileageController.php:238 `->with('success', 'Mileage claim submitted.');`.
- `ROUTE-0792` / `approve`: success app/Http/Controllers/FleetAssets/MileageController.php:254 `return back()->with('success', 'Mileage claim approved.');`.
- `ROUTE-0793` / `markPaid`: success app/Http/Controllers/FleetAssets/MileageController.php:283 `return back()->with('success', 'Claim marked as paid.');`.
- `ROUTE-0794` / `reject`: fields `notes`; success app/Http/Controllers/FleetAssets/MileageController.php:270 `return back()->with('success', 'Mileage claim rejected.');`.
- `ROUTE-0796` / `export`: failure app/Http/Controllers/FleetAssets/MileageController.php:289 `abort(404);`.

## Failure and recovery paths

- `export`: app/Http/Controllers/FleetAssets/MileageController.php:289 `abort(404);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/FleetAssets/MileageController.php:230 `$trip = FleetPersonalTrip::create($data);`; app/Http/Controllers/FleetAssets/MileageController.php:246 `$trip->update([`; app/Http/Controllers/FleetAssets/MileageController.php:277 `$trip->update([`; app/Http/Controllers/FleetAssets/MileageController.php:263 `$trip->update([`; responses app/Http/Controllers/FleetAssets/MileageController.php:28 `return Inertia::render('fleet-assets/mileage/index', [`; app/Http/Controllers/FleetAssets/MileageController.php:136 `return Inertia::render('fleet-assets/mileage/index', [`; app/Http/Controllers/FleetAssets/MileageController.php:237 `return redirect()->route('fleet-assets.mileage.index')`; app/Http/Controllers/FleetAssets/MileageController.php:254 `return back()->with('success', 'Mileage claim approved.');`; app/Http/Controllers/FleetAssets/MileageController.php:283 `return back()->with('success', 'Claim marked as paid.');`; app/Http/Controllers/FleetAssets/MileageController.php:270 `return back()->with('success', 'Mileage claim rejected.');`; app/Http/Controllers/FleetAssets/MileageController.php:189 `return redirect()->route('fleet-assets.mileage.index', ['new' => 1]);`; app/Http/Controllers/FleetAssets/MileageController.php:309 `return response()->streamDownload(function () use ($all) {`; audit calls app/Http/Controllers/FleetAssets/MileageController.php:232 `AuditLogger::log('fleet.mileage.create', $trip, [`; app/Http/Controllers/FleetAssets/MileageController.php:252 `AuditLogger::log('fleet.mileage.approve', $trip);`; app/Http/Controllers/FleetAssets/MileageController.php:281 `AuditLogger::log('fleet.mileage.paid', $trip);`; app/Http/Controllers/FleetAssets/MileageController.php:268 `AuditLogger::log('fleet.mileage.reject', $trip);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD fleet-assets/mileage` — `fleet-assets.mileage.index` — `App\Http\Controllers\FleetAssets\MileageController@index` — `app/Http/Controllers/FleetAssets/MileageController.php:16` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `POST fleet-assets/mileage` — `fleet-assets.mileage.store` — `App\Http\Controllers\FleetAssets\MileageController@store` — `app/Http/Controllers/FleetAssets/MileageController.php:211` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `POST fleet-assets/mileage/{trip}/approve` — `fleet-assets.mileage.approve` — `App\Http\Controllers\FleetAssets\MileageController@approve` — `app/Http/Controllers/FleetAssets/MileageController.php:241` — middleware `web, auth, permission:fleet.mileage.approve|fleet.manage`
- `POST fleet-assets/mileage/{trip}/mark-paid` — `fleet-assets.mileage.mark-paid` — `App\Http\Controllers\FleetAssets\MileageController@markPaid` — `app/Http/Controllers/FleetAssets/MileageController.php:273` — middleware `web, auth, permission:fleet.mileage.approve|fleet.manage`
- `POST fleet-assets/mileage/{trip}/reject` — `fleet-assets.mileage.reject` — `App\Http\Controllers\FleetAssets\MileageController@reject` — `app/Http/Controllers/FleetAssets/MileageController.php:257` — middleware `web, auth, permission:fleet.mileage.approve|fleet.manage`
- `GET|HEAD fleet-assets/mileage/create` — `fleet-assets.mileage.create` — `App\Http\Controllers\FleetAssets\MileageController@create` — `app/Http/Controllers/FleetAssets/MileageController.php:187` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `GET|HEAD fleet-assets/mileage/export` — `fleet-assets.mileage.export` — `App\Http\Controllers\FleetAssets\MileageController@export` — `app/Http/Controllers/FleetAssets/MileageController.php:286` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/FleetAssets/MileageController.php`.
- Exact render/action page relationships: `resources/js/pages/fleet-assets/mileage/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
