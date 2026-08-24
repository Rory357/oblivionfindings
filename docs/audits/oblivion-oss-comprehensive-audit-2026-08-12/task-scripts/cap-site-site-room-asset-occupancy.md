# CAP-SITE-SITE-ROOM-ASSET-OCCUPANCY: Room asset custody resident assignment and door card

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.update`, `permission:sites.viewAny`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-ROOM`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/rooms/{room}/door-card` (`sites.rooms.door-card`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.update`, `permission:sites.viewAny`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.update`, `permission:sites.viewAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/rooms/{room}/door-card` (`sites.rooms.door-card`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST sites/{site}/rooms/{room}/assets` (`sites.rooms.assets.attach`, action `attachAsset`). Source category: **mutation outcome source gap (attachAsset)**; controller `app/Http/Controllers/Sites/SiteRoomController.php:391-407`; `asset_id`.
3. Invoke only the owning control for `DELETE sites/{site}/rooms/{room}/assets/{asset}` (`sites.rooms.assets.detach`, action `detachAsset`). Source category: **mutation outcome source gap (detachAsset)**; controller `app/Http/Controllers/Sites/SiteRoomController.php:409-420`; no exact validation fields extracted.
4. Invoke only the owning control for `POST sites/{site}/rooms/{room}/assign` (`sites.rooms.assign`, action `assign`). Source category: **assigned**; controller `app/Http/Controllers/Sites/SiteRoomController.php:324-384`; `client_id`.

## Source-applicable states and transitions

- **mutation outcome source gap (attachAsset)** is applicable only to `attachAsset` / `ROUTE-2876` at `app/Http/Controllers/Sites/SiteRoomController.php:391`; it is not runtime-observed.
- **mutation outcome source gap (detachAsset)** is applicable only to `detachAsset` / `ROUTE-2877` at `app/Http/Controllers/Sites/SiteRoomController.php:409`; it is not runtime-observed.
- **assigned** is applicable only to `assign` / `ROUTE-2878` at `app/Http/Controllers/Sites/SiteRoomController.php:324`; it is not runtime-observed.
- **information presented** is applicable only to `doorCard` / `ROUTE-2879` at `app/Http/Controllers/Sites/SiteRoomController.php:453`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2876` / `attachAsset`: fields `asset_id`; success app/Http/Controllers/Sites/SiteRoomController.php:406 `return back()->with('success', 'Asset attached to room.');`.
- `ROUTE-2877` / `detachAsset`: success app/Http/Controllers/Sites/SiteRoomController.php:419 `return back()->with('success', 'Asset removed from bedroom.');`.
- `ROUTE-2878` / `assign`: fields `client_id`; failure app/Http/Controllers/Sites/SiteRoomController.php:345 `)->withErrors([`.

## Failure and recovery paths

- `assign`: app/Http/Controllers/Sites/SiteRoomController.php:345 `)->withErrors([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteRoomController.php:404 `$asset->save();`; app/Http/Controllers/Sites/SiteRoomController.php:417 `$asset->save();`; app/Http/Controllers/Sites/SiteRoomController.php:360 `?->update(['assigned_until' => $today]);`; app/Http/Controllers/Sites/SiteRoomController.php:363 `$room->update([`; app/Http/Controllers/Sites/SiteRoomController.php:370 `$room->history()->create([`; responses app/Http/Controllers/Sites/SiteRoomController.php:406 `return back()->with('success', 'Asset attached to room.');`; app/Http/Controllers/Sites/SiteRoomController.php:419 `return back()->with('success', 'Asset removed from bedroom.');`; app/Http/Controllers/Sites/SiteRoomController.php:342 `return back()->with(`; app/Http/Controllers/Sites/SiteRoomController.php:380 `return back()->with(`; app/Http/Controllers/Sites/SiteRoomController.php:467 `return view('sites.rooms.door-card', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST sites/{site}/rooms/{room}/assets` — `sites.rooms.assets.attach` — `App\Http\Controllers\Sites\SiteRoomController@attachAsset` — `app/Http/Controllers/Sites/SiteRoomController.php:391` — middleware `web, auth, verified, permission:sites.update`
- `DELETE sites/{site}/rooms/{room}/assets/{asset}` — `sites.rooms.assets.detach` — `App\Http\Controllers\Sites\SiteRoomController@detachAsset` — `app/Http/Controllers/Sites/SiteRoomController.php:409` — middleware `web, auth, verified, permission:sites.update`
- `POST sites/{site}/rooms/{room}/assign` — `sites.rooms.assign` — `App\Http\Controllers\Sites\SiteRoomController@assign` — `app/Http/Controllers/Sites/SiteRoomController.php:324` — middleware `web, auth, verified, permission:sites.update`
- `GET|HEAD sites/{site}/rooms/{room}/door-card` — `sites.rooms.door-card` — `App\Http\Controllers\Sites\SiteRoomController@doorCard` — `app/Http/Controllers/Sites/SiteRoomController.php:453` — middleware `web, auth, verified, permission:sites.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteRoomController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
