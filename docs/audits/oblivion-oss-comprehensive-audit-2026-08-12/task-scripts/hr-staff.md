# HR-STAFF: Staff

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:staff.viewAny`, `permission:staff.update`
- Owning module: Human resources
- Legacy family: `HR-STAFF`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `staff` (`staff.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:staff.viewAny`, `permission:staff.update`.
- Exact middleware atoms: `web`, `auth`, `permission:staff.viewAny`, `permission:staff.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD staff` (`staff.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD staff/{user}` (`staff.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/StaffController.php:56-124`.
3. Use `GET|HEAD staff/{user}/edit` (`staff.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/StaffController.php:223-240`.
4. Invoke only the owning control for `PUT staff/{user}` (`staff.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/StaffController.php:242-293`; `name`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2916` at `app/Http/Controllers/StaffController.php:21`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2917` at `app/Http/Controllers/StaffController.php:56`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2918` at `app/Http/Controllers/StaffController.php:242`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-2931` at `app/Http/Controllers/StaffController.php:223`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/staff/edit.tsx`, `resources/js/pages/staff/index.tsx`, `resources/js/pages/staff/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2918` / `update`: fields `name`; success app/Http/Controllers/StaffController.php:292 `return redirect()->route('staff.show', $user)->with('success', 'Staff updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/StaffController.php:268 `$user->update([`; app/Http/Controllers/StaffController.php:275 `$user->roles()->sync($data['role_ids']);`; app/Http/Controllers/StaffController.php:279 `$user->forceFill(['role' => $first?->name])->save();`; responses app/Http/Controllers/StaffController.php:50 `return inertia('staff/index', [`; app/Http/Controllers/StaffController.php:114 `return inertia('staff/show', [`; app/Http/Controllers/StaffController.php:292 `return redirect()->route('staff.show', $user)->with('success', 'Staff updated.');`; app/Http/Controllers/StaffController.php:236 `return inertia('staff/edit', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD staff` — `staff.index` — `App\Http\Controllers\StaffController@index` — `app/Http/Controllers/StaffController.php:21` — middleware `web, auth, permission:staff.viewAny`
- `GET|HEAD staff/{user}` — `staff.show` — `App\Http\Controllers\StaffController@show` — `app/Http/Controllers/StaffController.php:56` — middleware `web, auth`
- `PUT staff/{user}` — `staff.update` — `App\Http\Controllers\StaffController@update` — `app/Http/Controllers/StaffController.php:242` — middleware `web, auth, permission:staff.update`
- `GET|HEAD staff/{user}/edit` — `staff.edit` — `App\Http\Controllers\StaffController@edit` — `app/Http/Controllers/StaffController.php:223` — middleware `web, auth, permission:staff.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/StaffController.php`.
- Exact render/action page relationships: `resources/js/pages/staff/edit.tsx`, `resources/js/pages/staff/index.tsx`, `resources/js/pages/staff/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
