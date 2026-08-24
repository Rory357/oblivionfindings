# OPS-MILEAGE-CLAIM: Mileage Claim

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:mileage.viewAny|mileage.viewOwn`, `permission:mileage.create`, `permission:mileage.approve`
- Owning module: Operations and rostering
- Legacy family: `OPS-MILEAGE-CLAIM`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/mileage` (`operations.mileage.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:mileage.viewAny|mileage.viewOwn`, `permission:mileage.create`, `permission:mileage.approve`.
- Exact middleware atoms: `web`, `auth`, `permission:mileage.viewAny|mileage.viewOwn`, `permission:mileage.create`, `permission:mileage.approve`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/mileage` (`operations.mileage.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/mileage/create` (`operations.mileage.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/MileageClaimController.php:74-80`.
3. Invoke only the owning control for `POST operations/mileage` (`operations.mileage.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/MileageClaimController.php:82-111`; `date`.
4. Invoke only the owning control for `POST operations/mileage/{claim}/approve` (`operations.mileage.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Operations/MileageClaimController.php:131-148`; no exact validation fields extracted.
5. Invoke only the owning control for `POST operations/mileage/{claim}/submit` (`operations.mileage.submit`, action `submit`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/MileageClaimController.php:113-129`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2106` at `app/Http/Controllers/Operations/MileageClaimController.php:11`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2107` at `app/Http/Controllers/Operations/MileageClaimController.php:82`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-2108` at `app/Http/Controllers/Operations/MileageClaimController.php:131`; it is not runtime-observed.
- **created/recorded** is applicable only to `submit` / `ROUTE-2109` at `app/Http/Controllers/Operations/MileageClaimController.php:113`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2110` at `app/Http/Controllers/Operations/MileageClaimController.php:74`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/mileage/Create.tsx`, `resources/js/pages/operations/mileage/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2107` / `store`: fields `date`; success app/Http/Controllers/Operations/MileageClaimController.php:110 `return redirect()->back()->with('success', 'Mileage claim created.');`.
- `ROUTE-2108` / `approve`: success app/Http/Controllers/Operations/MileageClaimController.php:147 `return redirect()->back()->with('success', 'Mileage claim approved.');`.
- `ROUTE-2109` / `submit`: success app/Http/Controllers/Operations/MileageClaimController.php:128 `return redirect()->back()->with('success', 'Mileage claim submitted.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/MileageClaimController.php:97 `MileageClaim::create([`; app/Http/Controllers/Operations/MileageClaimController.php:141 `$claim->update([`; app/Http/Controllers/Operations/MileageClaimController.php:123 `$claim->update([`; responses app/Http/Controllers/Operations/MileageClaimController.php:53 `return inertia('operations/mileage/Index', [`; app/Http/Controllers/Operations/MileageClaimController.php:110 `return redirect()->back()->with('success', 'Mileage claim created.');`; app/Http/Controllers/Operations/MileageClaimController.php:147 `return redirect()->back()->with('success', 'Mileage claim approved.');`; app/Http/Controllers/Operations/MileageClaimController.php:128 `return redirect()->back()->with('success', 'Mileage claim submitted.');`; app/Http/Controllers/Operations/MileageClaimController.php:79 `return inertia('operations/mileage/Create');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/mileage` — `operations.mileage.index` — `App\Http\Controllers\Operations\MileageClaimController@index` — `app/Http/Controllers/Operations/MileageClaimController.php:11` — middleware `web, auth, permission:mileage.viewAny|mileage.viewOwn`
- `POST operations/mileage` — `operations.mileage.store` — `App\Http\Controllers\Operations\MileageClaimController@store` — `app/Http/Controllers/Operations/MileageClaimController.php:82` — middleware `web, auth, permission:mileage.create`
- `POST operations/mileage/{claim}/approve` — `operations.mileage.approve` — `App\Http\Controllers\Operations\MileageClaimController@approve` — `app/Http/Controllers/Operations/MileageClaimController.php:131` — middleware `web, auth, permission:mileage.approve`
- `POST operations/mileage/{claim}/submit` — `operations.mileage.submit` — `App\Http\Controllers\Operations\MileageClaimController@submit` — `app/Http/Controllers/Operations/MileageClaimController.php:113` — middleware `web, auth, permission:mileage.create`
- `GET|HEAD operations/mileage/create` — `operations.mileage.create` — `App\Http\Controllers\Operations\MileageClaimController@create` — `app/Http/Controllers/Operations/MileageClaimController.php:74` — middleware `web, auth, permission:mileage.create`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/MileageClaimController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/mileage/Create.tsx`, `resources/js/pages/operations/mileage/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
