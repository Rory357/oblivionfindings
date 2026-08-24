# CAP-HR-ASSET-CUSTODY: Employee asset custody and returns

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.assets.view`, `permission:hr.assets.manage`
- Owning module: Human resources
- Legacy family: `HR-ASSET`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/assets/fleet-search` (`hr.assets.fleet-search`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.assets.view`, `permission:hr.assets.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.assets.view`, `permission:hr.assets.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/assets/fleet-search` (`hr.assets.fleet-search`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/assets/{asset}/assign` (`hr.assets.assign`, action `assign`). Source category: **assigned**; controller `app/Http/Controllers/Hr/AssetController.php:260-292`; `employee_profile_id`.
3. Invoke only the owning control for `POST hr/assets/assignments/{assignment}/return` (`hr.assets.assignments.return`, action `returnAsset`). Source category: **rejected/returned**; controller `app/Http/Controllers/Hr/AssetController.php:294-321`; `returned_at`.

## Source-applicable states and transitions

- **assigned** is applicable only to `assign` / `ROUTE-1283` at `app/Http/Controllers/Hr/AssetController.php:260`; it is not runtime-observed.
- **rejected/returned** is applicable only to `returnAsset` / `ROUTE-1289` at `app/Http/Controllers/Hr/AssetController.php:294`; it is not runtime-observed.
- **information presented** is applicable only to `fleetSearch` / `ROUTE-1294` at `app/Http/Controllers/Hr/AssetController.php:589`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1283` / `assign`: fields `employee_profile_id`; success app/Http/Controllers/Hr/AssetController.php:291 `return redirect()->back()->with('success', 'Asset assigned.');`.
- `ROUTE-1289` / `returnAsset`: fields `returned_at`; success app/Http/Controllers/Hr/AssetController.php:320 `return redirect()->back()->with('success', 'Asset returned.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/AssetController.php:288 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/AssetController.php:291 `return redirect()->back()->with('success', 'Asset assigned.');`; app/Http/Controllers/Hr/AssetController.php:308 `// A damaged/lost return parks the asset in maintenance so the follow-up`; app/Http/Controllers/Hr/AssetController.php:317 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/AssetController.php:320 `return redirect()->back()->with('success', 'Asset returned.');`; app/Http/Controllers/Hr/AssetController.php:596 `return response()->json(['data' => $results]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/assets/{asset}/assign` — `hr.assets.assign` — `App\Http\Controllers\Hr\AssetController@assign` — `app/Http/Controllers/Hr/AssetController.php:260` — middleware `web, auth, permission:hr.assets.view, permission:hr.assets.manage`
- `POST hr/assets/assignments/{assignment}/return` — `hr.assets.assignments.return` — `App\Http\Controllers\Hr\AssetController@returnAsset` — `app/Http/Controllers/Hr/AssetController.php:294` — middleware `web, auth, permission:hr.assets.view, permission:hr.assets.manage`
- `GET|HEAD hr/assets/fleet-search` — `hr.assets.fleet-search` — `App\Http\Controllers\Hr\AssetController@fleetSearch` — `app/Http/Controllers/Hr/AssetController.php:589` — middleware `web, auth, permission:hr.assets.view, permission:hr.assets.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/AssetController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
