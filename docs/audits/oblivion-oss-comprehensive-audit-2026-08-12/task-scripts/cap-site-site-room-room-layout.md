# CAP-SITE-SITE-ROOM-ROOM-LAYOUT: Room record lifecycle ordering defaults and restoration

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.update`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-ROOM`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/rooms` (`sites.rooms.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.update`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/rooms` (`sites.rooms.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST sites/{site}/rooms` (`sites.rooms.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteRoomController.php:229-258`; `name`, `notes`, `assigned_client_id`, `is_assignable`.
3. Invoke only the owning control for `DELETE sites/{site}/rooms/{room}` (`sites.rooms.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Sites/SiteRoomController.php:308-316`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT sites/{site}/rooms/{room}` (`sites.rooms.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteRoomController.php:260-306`; `name`, `notes`, `assigned_client_id`, `is_assignable`.
5. Invoke only the owning control for `POST sites/{site}/rooms/{room}/restore` (`sites.rooms.restore`, action `restore`). Source category: **mutation outcome source gap (restore)**; controller `app/Http/Controllers/Sites/SiteRoomController.php:479-487`; no exact validation fields extracted.
6. Invoke only the owning control for `PATCH sites/{site}/rooms/order` (`sites.rooms.reorder`, action `reorder`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteRoomController.php:427-446`; `ordered_ids`.
7. Invoke only the owning control for `POST sites/{site}/rooms/seed-defaults` (`sites.rooms.seed-defaults`, action `seedDefaults`). Source category: **mutation outcome source gap (seedDefaults)**; controller `app/Http/Controllers/Sites/SiteRoomController.php:193-227`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2872` at `app/Http/Controllers/Sites/SiteRoomController.php:16`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2873` at `app/Http/Controllers/Sites/SiteRoomController.php:229`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2874` at `app/Http/Controllers/Sites/SiteRoomController.php:308`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2875` at `app/Http/Controllers/Sites/SiteRoomController.php:260`; it is not runtime-observed.
- **mutation outcome source gap (restore)** is applicable only to `restore` / `ROUTE-2880` at `app/Http/Controllers/Sites/SiteRoomController.php:479`; it is not runtime-observed.
- **updated/revised** is applicable only to `reorder` / `ROUTE-2881` at `app/Http/Controllers/Sites/SiteRoomController.php:427`; it is not runtime-observed.
- **mutation outcome source gap (seedDefaults)** is applicable only to `seedDefaults` / `ROUTE-2882` at `app/Http/Controllers/Sites/SiteRoomController.php:193`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/sites/rooms/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2873` / `store`: fields `name`, `notes`, `assigned_client_id`, `is_assignable`; success app/Http/Controllers/Sites/SiteRoomController.php:257 `return redirect()->back()->with('success', 'Bedroom added.');`.
- `ROUTE-2874` / `destroy`: success app/Http/Controllers/Sites/SiteRoomController.php:315 `return redirect()->back()->with('success', 'Bedroom deactivated.');`.
- `ROUTE-2875` / `update`: fields `name`, `notes`, `assigned_client_id`, `is_assignable`; success app/Http/Controllers/Sites/SiteRoomController.php:305 `return redirect()->back()->with('success', 'Bedroom updated.');`.
- `ROUTE-2880` / `restore`: success app/Http/Controllers/Sites/SiteRoomController.php:486 `return back()->with('success', 'Bedroom restored.');`.
- `ROUTE-2881` / `reorder`: fields `ordered_ids`; success app/Http/Controllers/Sites/SiteRoomController.php:445 `return back()->with('success', 'Bedroom order updated.');`.
- `ROUTE-2882` / `seedDefaults`: success app/Http/Controllers/Sites/SiteRoomController.php:226 `return back()->with('success', 'Standard rooms added.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteRoomController.php:249 `$room = SiteHouseRoom::create([`; app/Http/Controllers/Sites/SiteRoomController.php:313 `$room->update(['is_active' => false]);`; app/Http/Controllers/Sites/SiteRoomController.php:281 `?->update(['assigned_until' => now()->toDateString()]);`; app/Http/Controllers/Sites/SiteRoomController.php:295 `$room->history()->create([`; app/Http/Controllers/Sites/SiteRoomController.php:303 `$room->update($validated);`; app/Http/Controllers/Sites/SiteRoomController.php:484 `$room->update(['is_active' => true]);`; app/Http/Controllers/Sites/SiteRoomController.php:441 `->update(['sort_order' => $index + 1]);`; responses app/Http/Controllers/Sites/SiteRoomController.php:82 `return inertia('sites/rooms/index', [`; app/Http/Controllers/Sites/SiteRoomController.php:93 `return [`; app/Http/Controllers/Sites/SiteRoomController.php:257 `return redirect()->back()->with('success', 'Bedroom added.');`; app/Http/Controllers/Sites/SiteRoomController.php:315 `return redirect()->back()->with('success', 'Bedroom deactivated.');`; app/Http/Controllers/Sites/SiteRoomController.php:305 `return redirect()->back()->with('success', 'Bedroom updated.');`; app/Http/Controllers/Sites/SiteRoomController.php:486 `return back()->with('success', 'Bedroom restored.');`; app/Http/Controllers/Sites/SiteRoomController.php:445 `return back()->with('success', 'Bedroom order updated.');`; app/Http/Controllers/Sites/SiteRoomController.php:226 `return back()->with('success', 'Standard rooms added.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD sites/{site}/rooms` — `sites.rooms.index` — `App\Http\Controllers\Sites\SiteRoomController@index` — `app/Http/Controllers/Sites/SiteRoomController.php:16` — middleware `web, auth, verified, permission:sites.viewAny`
- `POST sites/{site}/rooms` — `sites.rooms.store` — `App\Http\Controllers\Sites\SiteRoomController@store` — `app/Http/Controllers/Sites/SiteRoomController.php:229` — middleware `web, auth, verified, permission:sites.update`
- `DELETE sites/{site}/rooms/{room}` — `sites.rooms.destroy` — `App\Http\Controllers\Sites\SiteRoomController@destroy` — `app/Http/Controllers/Sites/SiteRoomController.php:308` — middleware `web, auth, verified, permission:sites.update`
- `PUT sites/{site}/rooms/{room}` — `sites.rooms.update` — `App\Http\Controllers\Sites\SiteRoomController@update` — `app/Http/Controllers/Sites/SiteRoomController.php:260` — middleware `web, auth, verified, permission:sites.update`
- `POST sites/{site}/rooms/{room}/restore` — `sites.rooms.restore` — `App\Http\Controllers\Sites\SiteRoomController@restore` — `app/Http/Controllers/Sites/SiteRoomController.php:479` — middleware `web, auth, verified, permission:sites.update`
- `PATCH sites/{site}/rooms/order` — `sites.rooms.reorder` — `App\Http\Controllers\Sites\SiteRoomController@reorder` — `app/Http/Controllers/Sites/SiteRoomController.php:427` — middleware `web, auth, verified, permission:sites.update`
- `POST sites/{site}/rooms/seed-defaults` — `sites.rooms.seed-defaults` — `App\Http\Controllers\Sites\SiteRoomController@seedDefaults` — `app/Http/Controllers/Sites/SiteRoomController.php:193` — middleware `web, auth, verified, permission:sites.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteRoomController.php`.
- Exact render/action page relationships: `resources/js/pages/sites/rooms/index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
