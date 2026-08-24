# SET-ACCESS-CONTROL: Access Control

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:settings.access.manage`
- Owning module: Settings and system access
- Legacy family: `SET-ACCESS-CONTROL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `system/access` (`system.access.dashboard`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:settings.access.manage`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:settings.access.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD system/access` (`system.access.dashboard`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD system/access/assignments` (`system.access.assignments`, action `assignments`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/System/AccessControlController.php:249-279`.
3. Use `GET|HEAD system/access/matrix` (`system.access.matrix`, action `matrix`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/System/AccessControlController.php:219-244`.
4. Use `GET|HEAD system/access/roles` (`system.access.roles`, action `roles`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/System/AccessControlController.php:58-105`.
5. Invoke only the owning control for `PUT system/access/assignments/{target}` (`system.access.assignments.update`, action `updateAssignments`). Source category: **updated/revised**; controller `app/Http/Controllers/System/AccessControlController.php:284-301`; `role_ids`.
6. Invoke only the owning control for `POST system/access/roles` (`system.access.roles.store`, action `storeRole`). Source category: **created/recorded**; controller `app/Http/Controllers/System/AccessControlController.php:110-140`; `name`.
7. Invoke only the owning control for `DELETE system/access/roles/{role}` (`system.access.roles.destroy`, action `destroyRole`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/System/AccessControlController.php:204-214`; no exact validation fields extracted.
8. Invoke only the owning control for `PUT system/access/roles/{role}` (`system.access.roles.update`, action `updateRole`). Source category: **updated/revised**; controller `app/Http/Controllers/System/AccessControlController.php:145-172`; `label`.
9. Invoke only the owning control for `POST system/access/roles/{role}/clone` (`system.access.roles.clone`, action `cloneRole`). Source category: **mutation outcome source gap (cloneRole)**; controller `app/Http/Controllers/System/AccessControlController.php:177-199`; `name`.

## Source-applicable states and transitions

- **information presented** is applicable only to `dashboard` / `ROUTE-2950` at `app/Http/Controllers/System/AccessControlController.php:19`; it is not runtime-observed.
- **information presented** is applicable only to `assignments` / `ROUTE-2951` at `app/Http/Controllers/System/AccessControlController.php:249`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateAssignments` / `ROUTE-2952` at `app/Http/Controllers/System/AccessControlController.php:284`; it is not runtime-observed.
- **information presented** is applicable only to `matrix` / `ROUTE-2953` at `app/Http/Controllers/System/AccessControlController.php:219`; it is not runtime-observed.
- **information presented** is applicable only to `roles` / `ROUTE-2954` at `app/Http/Controllers/System/AccessControlController.php:58`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeRole` / `ROUTE-2955` at `app/Http/Controllers/System/AccessControlController.php:110`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyRole` / `ROUTE-2956` at `app/Http/Controllers/System/AccessControlController.php:204`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateRole` / `ROUTE-2957` at `app/Http/Controllers/System/AccessControlController.php:145`; it is not runtime-observed.
- **mutation outcome source gap (cloneRole)** is applicable only to `cloneRole` / `ROUTE-2958` at `app/Http/Controllers/System/AccessControlController.php:177`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/system/access/Assignments.tsx`, `resources/js/pages/system/access/Dashboard.tsx`, `resources/js/pages/system/access/Matrix.tsx`, `resources/js/pages/system/access/Roles.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2952` / `updateAssignments`: fields `role_ids`; success app/Http/Controllers/System/AccessControlController.php:300 `return redirect()->back()->with('success', 'User roles updated successfully.');`.
- `ROUTE-2955` / `storeRole`: fields `name`; success app/Http/Controllers/System/AccessControlController.php:139 `return redirect()->back()->with('success', 'Role created successfully.');`.
- `ROUTE-2956` / `destroyRole`: success app/Http/Controllers/System/AccessControlController.php:213 `return redirect()->back()->with('success', 'Role deleted successfully.');`.
- `ROUTE-2957` / `updateRole`: fields `label`; success app/Http/Controllers/System/AccessControlController.php:171 `return redirect()->back()->with('success', 'Role updated successfully.');`.
- `ROUTE-2958` / `cloneRole`: fields `name`; success app/Http/Controllers/System/AccessControlController.php:198 `return redirect()->back()->with('success', 'Role cloned successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/System/AccessControlController.php:294 `$target->roles()->sync($data['role_ids']);`; app/Http/Controllers/System/AccessControlController.php:298 `$target->forceFill(['role' => $primaryRole?->name ?? 'support_worker'])->save();`; app/Http/Controllers/System/AccessControlController.php:126 `$role = Role::create([`; app/Http/Controllers/System/AccessControlController.php:136 `$role->permissions()->sync($permissionIds);`; app/Http/Controllers/System/AccessControlController.php:211 `$role->delete();`; app/Http/Controllers/System/AccessControlController.php:160 `$role->update([`; app/Http/Controllers/System/AccessControlController.php:168 `$role->permissions()->sync($permissionIds);`; app/Http/Controllers/System/AccessControlController.php:187 `$newRole = Role::create([`; app/Http/Controllers/System/AccessControlController.php:196 `$newRole->permissions()->sync($role->permissions->pluck('id'));`; responses app/Http/Controllers/System/AccessControlController.php:49 `return Inertia::render('system/access/Dashboard', [`; app/Http/Controllers/System/AccessControlController.php:275 `return Inertia::render('system/access/Assignments', [`; app/Http/Controllers/System/AccessControlController.php:300 `return redirect()->back()->with('success', 'User roles updated successfully.');`; app/Http/Controllers/System/AccessControlController.php:238 `return Inertia::render('system/access/Matrix', [`; app/Http/Controllers/System/AccessControlController.php:99 `return Inertia::render('system/access/Roles', [`; app/Http/Controllers/System/AccessControlController.php:139 `return redirect()->back()->with('success', 'Role created successfully.');`; app/Http/Controllers/System/AccessControlController.php:213 `return redirect()->back()->with('success', 'Role deleted successfully.');`; app/Http/Controllers/System/AccessControlController.php:171 `return redirect()->back()->with('success', 'Role updated successfully.');`; app/Http/Controllers/System/AccessControlController.php:198 `return redirect()->back()->with('success', 'Role cloned successfully.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD system/access` — `system.access.dashboard` — `App\Http\Controllers\System\AccessControlController@dashboard` — `app/Http/Controllers/System/AccessControlController.php:19` — middleware `web, auth, verified, permission:settings.access.manage`
- `GET|HEAD system/access/assignments` — `system.access.assignments` — `App\Http\Controllers\System\AccessControlController@assignments` — `app/Http/Controllers/System/AccessControlController.php:249` — middleware `web, auth, verified, permission:settings.access.manage`
- `PUT system/access/assignments/{target}` — `system.access.assignments.update` — `App\Http\Controllers\System\AccessControlController@updateAssignments` — `app/Http/Controllers/System/AccessControlController.php:284` — middleware `web, auth, verified, permission:settings.access.manage`
- `GET|HEAD system/access/matrix` — `system.access.matrix` — `App\Http\Controllers\System\AccessControlController@matrix` — `app/Http/Controllers/System/AccessControlController.php:219` — middleware `web, auth, verified, permission:settings.access.manage`
- `GET|HEAD system/access/roles` — `system.access.roles` — `App\Http\Controllers\System\AccessControlController@roles` — `app/Http/Controllers/System/AccessControlController.php:58` — middleware `web, auth, verified, permission:settings.access.manage`
- `POST system/access/roles` — `system.access.roles.store` — `App\Http\Controllers\System\AccessControlController@storeRole` — `app/Http/Controllers/System/AccessControlController.php:110` — middleware `web, auth, verified, permission:settings.access.manage`
- `DELETE system/access/roles/{role}` — `system.access.roles.destroy` — `App\Http\Controllers\System\AccessControlController@destroyRole` — `app/Http/Controllers/System/AccessControlController.php:204` — middleware `web, auth, verified, permission:settings.access.manage`
- `PUT system/access/roles/{role}` — `system.access.roles.update` — `App\Http\Controllers\System\AccessControlController@updateRole` — `app/Http/Controllers/System/AccessControlController.php:145` — middleware `web, auth, verified, permission:settings.access.manage`
- `POST system/access/roles/{role}/clone` — `system.access.roles.clone` — `App\Http\Controllers\System\AccessControlController@cloneRole` — `app/Http/Controllers/System/AccessControlController.php:177` — middleware `web, auth, verified, permission:settings.access.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/System/AccessControlController.php`.
- Exact render/action page relationships: `resources/js/pages/system/access/Assignments.tsx`, `resources/js/pages/system/access/Dashboard.tsx`, `resources/js/pages/system/access/Matrix.tsx`, `resources/js/pages/system/access/Roles.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
