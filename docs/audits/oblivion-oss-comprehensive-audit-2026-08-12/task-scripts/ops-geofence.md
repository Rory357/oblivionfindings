# OPS-GEOFENCE: Geofence

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:evv.viewAny`
- Owning module: Operations and rostering
- Legacy family: `OPS-GEOFENCE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/geofences` (`operations.geofences.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:evv.viewAny`.
- Exact middleware atoms: `web`, `auth`, `permission:evv.viewAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/geofences` (`operations.geofences.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/geofences/create` (`operations.geofences.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/GeofenceController.php:51-65`.
3. Invoke only the owning control for `POST operations/geofences` (`operations.geofences.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/GeofenceController.php:67-93`; `name`.
4. Invoke only the owning control for `DELETE operations/geofences/{zone}` (`operations.geofences.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Operations/GeofenceController.php:118-130`; no exact validation fields extracted.
5. Invoke only the owning control for `PUT operations/geofences/{zone}` (`operations.geofences.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/GeofenceController.php:95-116`; `name`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2082` at `app/Http/Controllers/Operations/GeofenceController.php:11`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2083` at `app/Http/Controllers/Operations/GeofenceController.php:67`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2084` at `app/Http/Controllers/Operations/GeofenceController.php:118`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2085` at `app/Http/Controllers/Operations/GeofenceController.php:95`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2086` at `app/Http/Controllers/Operations/GeofenceController.php:51`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/geofences/Create.tsx`, `resources/js/pages/operations/geofences/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2082` / `index`: fields `q`.
- `ROUTE-2083` / `store`: fields `name`; success app/Http/Controllers/Operations/GeofenceController.php:92 `return redirect()->back()->with('success', 'Geofence zone created.');`.
- `ROUTE-2084` / `destroy`: success app/Http/Controllers/Operations/GeofenceController.php:129 `return redirect()->back()->with('success', 'Geofence zone deleted.');`.
- `ROUTE-2085` / `update`: fields `name`; success app/Http/Controllers/Operations/GeofenceController.php:115 `return redirect()->back()->with('success', 'Geofence zone updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/GeofenceController.php:81 `GeofenceZone::create([`; app/Http/Controllers/Operations/GeofenceController.php:127 `$zone->delete();`; app/Http/Controllers/Operations/GeofenceController.php:113 `$zone->update($data);`; responses app/Http/Controllers/Operations/GeofenceController.php:43 `return inertia('operations/geofences/Index', [`; app/Http/Controllers/Operations/GeofenceController.php:92 `return redirect()->back()->with('success', 'Geofence zone created.');`; app/Http/Controllers/Operations/GeofenceController.php:129 `return redirect()->back()->with('success', 'Geofence zone deleted.');`; app/Http/Controllers/Operations/GeofenceController.php:115 `return redirect()->back()->with('success', 'Geofence zone updated.');`; app/Http/Controllers/Operations/GeofenceController.php:62 `return inertia('operations/geofences/Create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/geofences` — `operations.geofences.index` — `App\Http\Controllers\Operations\GeofenceController@index` — `app/Http/Controllers/Operations/GeofenceController.php:11` — middleware `web, auth, permission:evv.viewAny`
- `POST operations/geofences` — `operations.geofences.store` — `App\Http\Controllers\Operations\GeofenceController@store` — `app/Http/Controllers/Operations/GeofenceController.php:67` — middleware `web, auth, permission:evv.viewAny`
- `DELETE operations/geofences/{zone}` — `operations.geofences.destroy` — `App\Http\Controllers\Operations\GeofenceController@destroy` — `app/Http/Controllers/Operations/GeofenceController.php:118` — middleware `web, auth, permission:evv.viewAny`
- `PUT operations/geofences/{zone}` — `operations.geofences.update` — `App\Http\Controllers\Operations\GeofenceController@update` — `app/Http/Controllers/Operations/GeofenceController.php:95` — middleware `web, auth, permission:evv.viewAny`
- `GET|HEAD operations/geofences/create` — `operations.geofences.create` — `App\Http\Controllers\Operations\GeofenceController@create` — `app/Http/Controllers/Operations/GeofenceController.php:51` — middleware `web, auth, permission:evv.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/GeofenceController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/geofences/Create.tsx`, `resources/js/pages/operations/geofences/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
