# FLEET-ASSET: Asset

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:assets.viewAny|assets.viewAssigned`, `permission:assets.create`, `permission:assets.update`
- Owning module: Fleet and vehicles
- Legacy family: `FLEET-ASSET`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `fleet-assets/assets` (`fleet-assets.assets.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:assets.viewAny|assets.viewAssigned`, `permission:assets.create`, `permission:assets.update`.
- Exact middleware atoms: `web`, `auth`, `permission:assets.viewAny|assets.viewAssigned`, `permission:assets.create`, `permission:assets.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD fleet-assets/assets` (`fleet-assets.assets.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD fleet-assets/assets/{asset}` (`fleet-assets.assets.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/AssetController.php:199-476`.
3. Use `GET|HEAD fleet-assets/assets/{asset}/edit` (`fleet-assets.assets.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/AssetController.php:572-575`.
4. Use `GET|HEAD fleet-assets/assets/create` (`fleet-assets.assets.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/AssetController.php:483-492`.
5. Invoke only the owning control for `POST fleet-assets/assets` (`fleet-assets.assets.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/FleetAssets/AssetController.php:494-566`; `name`.
6. Invoke only the owning control for `PUT fleet-assets/assets/{asset}` (`fleet-assets.assets.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/FleetAssets/AssetController.php:577-624`; `name`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0706` at `app/Http/Controllers/FleetAssets/AssetController.php:57`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0707` at `app/Http/Controllers/FleetAssets/AssetController.php:494`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0708` at `app/Http/Controllers/FleetAssets/AssetController.php:199`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0709` at `app/Http/Controllers/FleetAssets/AssetController.php:577`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-0710` at `app/Http/Controllers/FleetAssets/AssetController.php:572`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0711` at `app/Http/Controllers/FleetAssets/AssetController.php:483`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/fleet-assets/assets/index.tsx`, `resources/js/pages/fleet-assets/assets/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0707` / `store`: fields `name`; success app/Http/Controllers/FleetAssets/AssetController.php:561 `->with('success', 'Asset created successfully.');`; app/Http/Controllers/FleetAssets/AssetController.php:565 `->with('success', 'Asset created successfully.');`; failure app/Http/Controllers/FleetAssets/AssetController.php:534 `return back()->withErrors(['site_id' => 'Select a site or a client.'])->withInput();`.
- `ROUTE-0709` / `update`: fields `name`; success app/Http/Controllers/FleetAssets/AssetController.php:623 `->with('success', 'Asset updated successfully.');`.

## Failure and recovery paths

- `store`: app/Http/Controllers/FleetAssets/AssetController.php:534 `return back()->withErrors(['site_id' => 'Select a site or a client.'])->withInput();`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/FleetAssets/AssetController.php:547 `$asset = Asset::create($data);`; app/Http/Controllers/FleetAssets/AssetController.php:616 `$asset->update($data);`; responses app/Http/Controllers/FleetAssets/AssetController.php:72 `return response()->streamDownload(function () use ($allAssets) {`; app/Http/Controllers/FleetAssets/AssetController.php:153 `return Inertia::render('fleet-assets/assets/index', [`; app/Http/Controllers/FleetAssets/AssetController.php:534 `return back()->withErrors(['site_id' => 'Select a site or a client.'])->withInput();`; app/Http/Controllers/FleetAssets/AssetController.php:560 `return redirect()->route('fleet-assets.assets.index', ['created' => $asset->id])`; app/Http/Controllers/FleetAssets/AssetController.php:564 `return redirect()->route('fleet-assets.assets.show', $asset)`; app/Http/Controllers/FleetAssets/AssetController.php:459 `return Inertia::render('fleet-assets/assets/show', [`; app/Http/Controllers/FleetAssets/AssetController.php:622 `return redirect()->route('fleet-assets.assets.show', $asset)`; app/Http/Controllers/FleetAssets/AssetController.php:574 `return redirect()->route('fleet-assets.assets.show', ['asset' => $asset, 'edit' => 1]);`; app/Http/Controllers/FleetAssets/AssetController.php:491 `return redirect()->route('fleet-assets.assets.index', ['new' => 1] + $params);`; audit calls app/Http/Controllers/FleetAssets/AssetController.php:549 `AuditLogger::log('assets.create', $asset, [`; app/Http/Controllers/FleetAssets/AssetController.php:618 `AuditLogger::log('assets.update', $asset, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD fleet-assets/assets` — `fleet-assets.assets.index` — `App\Http\Controllers\FleetAssets\AssetController@index` — `app/Http/Controllers/FleetAssets/AssetController.php:57` — middleware `web, auth, permission:assets.viewAny|assets.viewAssigned`
- `POST fleet-assets/assets` — `fleet-assets.assets.store` — `App\Http\Controllers\FleetAssets\AssetController@store` — `app/Http/Controllers/FleetAssets/AssetController.php:494` — middleware `web, auth, permission:assets.create`
- `GET|HEAD fleet-assets/assets/{asset}` — `fleet-assets.assets.show` — `App\Http\Controllers\FleetAssets\AssetController@show` — `app/Http/Controllers/FleetAssets/AssetController.php:199` — middleware `web, auth, permission:assets.viewAny|assets.viewAssigned`
- `PUT fleet-assets/assets/{asset}` — `fleet-assets.assets.update` — `App\Http\Controllers\FleetAssets\AssetController@update` — `app/Http/Controllers/FleetAssets/AssetController.php:577` — middleware `web, auth, permission:assets.update`
- `GET|HEAD fleet-assets/assets/{asset}/edit` — `fleet-assets.assets.edit` — `App\Http\Controllers\FleetAssets\AssetController@edit` — `app/Http/Controllers/FleetAssets/AssetController.php:572` — middleware `web, auth, permission:assets.update`
- `GET|HEAD fleet-assets/assets/create` — `fleet-assets.assets.create` — `App\Http\Controllers\FleetAssets\AssetController@create` — `app/Http/Controllers/FleetAssets/AssetController.php:483` — middleware `web, auth, permission:assets.create`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/FleetAssets/AssetController.php`.
- Exact render/action page relationships: `resources/js/pages/fleet-assets/assets/index.tsx`, `resources/js/pages/fleet-assets/assets/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
