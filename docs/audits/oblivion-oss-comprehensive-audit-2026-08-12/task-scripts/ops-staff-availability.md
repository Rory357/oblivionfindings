# OPS-STAFF-AVAILABILITY: Staff Availability

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:staff.viewAny|staff.availability.updateAny|staff.availability.updateSelf`, `permission:staff.availability.updateAny|staff.availability.updateSelf`
- Owning module: Operations and rostering
- Legacy family: `OPS-STAFF-AVAILABILITY`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `staff/{user}/availability` (`staff.availability.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:staff.viewAny|staff.availability.updateAny|staff.availability.updateSelf`, `permission:staff.availability.updateAny|staff.availability.updateSelf`.
- Exact middleware atoms: `web`, `auth`, `permission:staff.viewAny|staff.availability.updateAny|staff.availability.updateSelf`, `permission:staff.availability.updateAny|staff.availability.updateSelf`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD staff/{user}/availability` (`staff.availability.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST staff/{user}/availability` (`staff.availability.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/StaffAvailabilityController.php:51-76`; `day_of_week`.
3. Invoke only the owning control for `DELETE staff/{user}/availability/{availability}` (`staff.availability.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/StaffAvailabilityController.php:78-91`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2921` at `app/Http/Controllers/StaffAvailabilityController.php:11`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2922` at `app/Http/Controllers/StaffAvailabilityController.php:51`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2923` at `app/Http/Controllers/StaffAvailabilityController.php:78`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/staff/availability.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2921` / `index`: failure app/Http/Controllers/StaffAvailabilityController.php:17 `abort(403);`.
- `ROUTE-2922` / `store`: fields `day_of_week`; success app/Http/Controllers/StaffAvailabilityController.php:75 `return back()->with('success', 'Availability added.');`; failure app/Http/Controllers/StaffAvailabilityController.php:57 `abort(403);`.
- `ROUTE-2923` / `destroy`: success app/Http/Controllers/StaffAvailabilityController.php:90 `return back()->with('success', 'Availability removed.');`; failure app/Http/Controllers/StaffAvailabilityController.php:84 `abort(403);`.

## Failure and recovery paths

- `index`: app/Http/Controllers/StaffAvailabilityController.php:17 `abort(403);`.
- `store`: app/Http/Controllers/StaffAvailabilityController.php:57 `abort(403);`.
- `destroy`: app/Http/Controllers/StaffAvailabilityController.php:84 `abort(403);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/StaffAvailabilityController.php:68 `StaffAvailability::create([`; app/Http/Controllers/StaffAvailabilityController.php:89 `$availability->delete();`; responses app/Http/Controllers/StaffAvailabilityController.php:31 `return redirect()->route('operations.rostering.index', ['tab' => 'availability']);`; app/Http/Controllers/StaffAvailabilityController.php:44 `return inertia('staff/availability', [`; app/Http/Controllers/StaffAvailabilityController.php:75 `return back()->with('success', 'Availability added.');`; app/Http/Controllers/StaffAvailabilityController.php:90 `return back()->with('success', 'Availability removed.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD staff/{user}/availability` — `staff.availability.index` — `App\Http\Controllers\StaffAvailabilityController@index` — `app/Http/Controllers/StaffAvailabilityController.php:11` — middleware `web, auth, permission:staff.viewAny|staff.availability.updateAny|staff.availability.updateSelf`
- `POST staff/{user}/availability` — `staff.availability.store` — `App\Http\Controllers\StaffAvailabilityController@store` — `app/Http/Controllers/StaffAvailabilityController.php:51` — middleware `web, auth, permission:staff.availability.updateAny|staff.availability.updateSelf`
- `DELETE staff/{user}/availability/{availability}` — `staff.availability.destroy` — `App\Http\Controllers\StaffAvailabilityController@destroy` — `app/Http/Controllers/StaffAvailabilityController.php:78` — middleware `web, auth, permission:staff.availability.updateAny|staff.availability.updateSelf`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/StaffAvailabilityController.php`.
- Exact render/action page relationships: `resources/js/pages/staff/availability.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
