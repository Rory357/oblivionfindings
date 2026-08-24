# OPS-STAFF-TIME-OFF: Staff Time Off

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:staff.availability.updateAny|staff.availability.updateSelf`
- Owning module: Operations and rostering
- Legacy family: `OPS-STAFF-TIME-OFF`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:staff.availability.updateAny|staff.availability.updateSelf`.
- Exact middleware atoms: `web`, `auth`, `permission:staff.availability.updateAny|staff.availability.updateSelf`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST operations/rostering/time-off` (`operations.rostering.time_off.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/StaffTimeOffController.php:13-64`; `user_id`.
3. Invoke only the owning control for `DELETE operations/rostering/time-off/{staffTimeOff}` (`operations.rostering.time_off.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/StaffTimeOffController.php:66-85`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-2167` at `app/Http/Controllers/StaffTimeOffController.php:13`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2168` at `app/Http/Controllers/StaffTimeOffController.php:66`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2167` / `store`: fields `user_id`; success app/Http/Controllers/StaffTimeOffController.php:49 `return redirect($returnTo)->with('success', 'Leave recorded and synced to the staff member’s HR balance.');`; app/Http/Controllers/StaffTimeOffController.php:63 `return redirect($returnTo)->with('success', 'Time off saved.');`; failure app/Http/Controllers/StaffTimeOffController.php:31 `abort(403);`.
- `ROUTE-2168` / `destroy`: success app/Http/Controllers/StaffTimeOffController.php:84 `return redirect($returnTo)->with('success', 'Time off deleted.');`; failure app/Http/Controllers/StaffTimeOffController.php:72 `abort(403);`.

## Failure and recovery paths

- `store`: app/Http/Controllers/StaffTimeOffController.php:31 `abort(403);`.
- `destroy`: app/Http/Controllers/StaffTimeOffController.php:72 `abort(403);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/StaffTimeOffController.php:52 `StaffTimeOff::create([`; app/Http/Controllers/StaffTimeOffController.php:82 `$staffTimeOff->delete();`; responses app/Http/Controllers/StaffTimeOffController.php:46 `return redirect($returnTo)->with('error', $e->getMessage());`; app/Http/Controllers/StaffTimeOffController.php:49 `return redirect($returnTo)->with('success', 'Leave recorded and synced to the staff member’s HR balance.');`; app/Http/Controllers/StaffTimeOffController.php:63 `return redirect($returnTo)->with('success', 'Time off saved.');`; app/Http/Controllers/StaffTimeOffController.php:79 `return redirect($returnTo)->with('error', 'This time off comes from an approved leave request — cancel it from the Leave module so the balance stays correct.');`; app/Http/Controllers/StaffTimeOffController.php:84 `return redirect($returnTo)->with('success', 'Time off deleted.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST operations/rostering/time-off` — `operations.rostering.time_off.store` — `App\Http\Controllers\StaffTimeOffController@store` — `app/Http/Controllers/StaffTimeOffController.php:13` — middleware `web, auth, permission:staff.availability.updateAny|staff.availability.updateSelf`
- `DELETE operations/rostering/time-off/{staffTimeOff}` — `operations.rostering.time_off.destroy` — `App\Http\Controllers\StaffTimeOffController@destroy` — `app/Http/Controllers/StaffTimeOffController.php:66` — middleware `web, auth, permission:staff.availability.updateAny|staff.availability.updateSelf`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/StaffTimeOffController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
