# SITE-SITE-HARDWARE: Site Hardware

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:siteHardware.view`, `permission:siteHardware.manage`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-HARDWARE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/hardware` (`sites.hardware.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:siteHardware.view`, `permission:siteHardware.manage`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:siteHardware.view`, `permission:siteHardware.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/hardware` (`sites.hardware.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `DELETE sites/{site}/hardware/{device}/pin` (`sites.hardware.unpin`, action `unpinDevice`). Source category: **mutation outcome source gap (unpinDevice)**; controller `app/Http/Controllers/Sites/SiteHardwareController.php:275-292`; no exact validation fields extracted.
3. Invoke only the owning control for `POST sites/{site}/hardware/{device}/pin` (`sites.hardware.pin`, action `pinDevice`). Source category: **mutation outcome source gap (pinDevice)**; controller `app/Http/Controllers/Sites/SiteHardwareController.php:228-273`; `x`.
4. Invoke only the owning control for `POST sites/{site}/hardware/{hardware}/assign-room` (`sites.hardware.assignRoom`, action `assignRoom`). Source category: **mutation outcome source gap (assignRoom)**; controller `app/Http/Controllers/Sites/SiteHardwareController.php:123-154`; `room_id`.
5. Invoke only the owning control for `POST sites/{site}/hardware/rooms` (`sites.hardware.manageRooms`, action `manageRooms`). Source category: **mutation outcome source gap (manageRooms)**; controller `app/Http/Controllers/Sites/SiteHardwareController.php:156-226`; `action`, `name`, `room_id`, `rooms`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2795` at `app/Http/Controllers/Sites/SiteHardwareController.php:23`; it is not runtime-observed.
- **mutation outcome source gap (unpinDevice)** is applicable only to `unpinDevice` / `ROUTE-2796` at `app/Http/Controllers/Sites/SiteHardwareController.php:275`; it is not runtime-observed.
- **mutation outcome source gap (pinDevice)** is applicable only to `pinDevice` / `ROUTE-2797` at `app/Http/Controllers/Sites/SiteHardwareController.php:228`; it is not runtime-observed.
- **mutation outcome source gap (assignRoom)** is applicable only to `assignRoom` / `ROUTE-2798` at `app/Http/Controllers/Sites/SiteHardwareController.php:123`; it is not runtime-observed.
- **mutation outcome source gap (manageRooms)** is applicable only to `manageRooms` / `ROUTE-2799` at `app/Http/Controllers/Sites/SiteHardwareController.php:156`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/sites/hardware/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2796` / `unpinDevice`: success app/Http/Controllers/Sites/SiteHardwareController.php:291 `return back()->with('success', 'Hardware pin removed.');`.
- `ROUTE-2797` / `pinDevice`: fields `x`; success app/Http/Controllers/Sites/SiteHardwareController.php:272 `return back()->with('success', 'Hardware pinned to plan.');`.
- `ROUTE-2798` / `assignRoom`: fields `room_id`; success app/Http/Controllers/Sites/SiteHardwareController.php:153 `return redirect()->back()->with('success', 'Hardware room assignment updated.');`.
- `ROUTE-2799` / `manageRooms`: fields `action`, `name`, `room_id`, `rooms`; success app/Http/Controllers/Sites/SiteHardwareController.php:182 `return redirect()->back()->with('success', 'Room added successfully.');`; app/Http/Controllers/Sites/SiteHardwareController.php:195 `return redirect()->back()->with('success', 'Room renamed successfully.');`; app/Http/Controllers/Sites/SiteHardwareController.php:210 `return redirect()->back()->with('success', 'Rooms reordered successfully.');`; app/Http/Controllers/Sites/SiteHardwareController.php:222 `return redirect()->back()->with('success', 'Room deleted successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteHardwareController.php:285 `->delete();`; app/Http/Controllers/Sites/SiteHardwareController.php:175 `SiteRoom::create([`; app/Http/Controllers/Sites/SiteHardwareController.php:193 `$room->update(['name' => $request->input('name')]);`; app/Http/Controllers/Sites/SiteHardwareController.php:207 `->update(['sort_order' => $roomData['sort_order']]);`; app/Http/Controllers/Sites/SiteHardwareController.php:220 `$room->delete();`; responses app/Http/Controllers/Sites/SiteHardwareController.php:52 `return [`; app/Http/Controllers/Sites/SiteHardwareController.php:103 `return inertia('sites/hardware/index', [`; app/Http/Controllers/Sites/SiteHardwareController.php:288 `return response()->json(['deleted' => true]);`; app/Http/Controllers/Sites/SiteHardwareController.php:291 `return back()->with('success', 'Hardware pin removed.');`; app/Http/Controllers/Sites/SiteHardwareController.php:267 `return response()->json([`; app/Http/Controllers/Sites/SiteHardwareController.php:272 `return back()->with('success', 'Hardware pinned to plan.');`; app/Http/Controllers/Sites/SiteHardwareController.php:153 `return redirect()->back()->with('success', 'Hardware room assignment updated.');`; app/Http/Controllers/Sites/SiteHardwareController.php:182 `return redirect()->back()->with('success', 'Room added successfully.');`; app/Http/Controllers/Sites/SiteHardwareController.php:195 `return redirect()->back()->with('success', 'Room renamed successfully.');`; app/Http/Controllers/Sites/SiteHardwareController.php:210 `return redirect()->back()->with('success', 'Rooms reordered successfully.');`; app/Http/Controllers/Sites/SiteHardwareController.php:222 `return redirect()->back()->with('success', 'Room deleted successfully.');`; app/Http/Controllers/Sites/SiteHardwareController.php:225 `return redirect()->back();`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD sites/{site}/hardware` — `sites.hardware.index` — `App\Http\Controllers\Sites\SiteHardwareController@index` — `app/Http/Controllers/Sites/SiteHardwareController.php:23` — middleware `web, auth, verified, permission:sites.viewAny, permission:siteHardware.view`
- `DELETE sites/{site}/hardware/{device}/pin` — `sites.hardware.unpin` — `App\Http\Controllers\Sites\SiteHardwareController@unpinDevice` — `app/Http/Controllers/Sites/SiteHardwareController.php:275` — middleware `web, auth, verified, permission:sites.viewAny, permission:siteHardware.manage`
- `POST sites/{site}/hardware/{device}/pin` — `sites.hardware.pin` — `App\Http\Controllers\Sites\SiteHardwareController@pinDevice` — `app/Http/Controllers/Sites/SiteHardwareController.php:228` — middleware `web, auth, verified, permission:sites.viewAny, permission:siteHardware.manage`
- `POST sites/{site}/hardware/{hardware}/assign-room` — `sites.hardware.assignRoom` — `App\Http\Controllers\Sites\SiteHardwareController@assignRoom` — `app/Http/Controllers/Sites/SiteHardwareController.php:123` — middleware `web, auth, verified, permission:sites.viewAny, permission:siteHardware.manage`
- `POST sites/{site}/hardware/rooms` — `sites.hardware.manageRooms` — `App\Http\Controllers\Sites\SiteHardwareController@manageRooms` — `app/Http/Controllers/Sites/SiteHardwareController.php:156` — middleware `web, auth, verified, permission:sites.viewAny, permission:siteHardware.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteHardwareController.php`.
- Exact render/action page relationships: `resources/js/pages/sites/hardware/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
