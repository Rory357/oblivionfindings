# CAP-SET-USERS-ACCOUNT-LIFECYCLE: User creation approval update suspension and deletion

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`, `verified`
- Owning module: Settings and system access
- Legacy family: `SET-USERS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `system/users` (`system.users.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`, `verified`.
- Exact middleware atoms: `web`, `auth`, `permission:settings.access.manage`, `verified`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD system/users` (`system.users.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD system/users/{target}` (`system.users.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/System/UsersController.php:286-338`.
3. Use `GET|HEAD system/users/create` (`system.users.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/System/UsersController.php:151-175`.
4. Invoke only the owning control for `PUT settings/users/{target}` (`settings.users.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/System/UsersController.php:343-373`; `name`.
5. Invoke only the owning control for `POST settings/users/{target}/approve` (`settings.users.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/System/UsersController.php:405-432`; `role_ids`.
6. Invoke only the owning control for `POST settings/users/{target}/suspend` (`settings.users.suspend`, action `suspend`). Source category: **mutation outcome source gap (suspend)**; controller `app/Http/Controllers/System/UsersController.php:437-456`; no exact validation fields extracted.
7. Invoke only the owning control for `POST system/users` (`system.users.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/System/UsersController.php:180-281`; `name`.
8. Invoke only the owning control for `DELETE system/users/{target}` (`system.users.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/System/UsersController.php:378-400`; no exact validation fields extracted.
9. Invoke only the owning control for `PUT system/users/{target}` (`system.users.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/System/UsersController.php:343-373`; `name`.
10. Invoke only the owning control for `POST system/users/{target}/approve` (`system.users.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/System/UsersController.php:405-432`; `role_ids`.
11. Invoke only the owning control for `POST system/users/{target}/suspend` (`system.users.suspend`, action `suspend`). Source category: **mutation outcome source gap (suspend)**; controller `app/Http/Controllers/System/UsersController.php:437-456`; no exact validation fields extracted.

## Source-applicable states and transitions

- **updated/revised** is applicable only to `update` / `ROUTE-2703` at `app/Http/Controllers/System/UsersController.php:343`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-2704` at `app/Http/Controllers/System/UsersController.php:405`; it is not runtime-observed.
- **mutation outcome source gap (suspend)** is applicable only to `suspend` / `ROUTE-2707` at `app/Http/Controllers/System/UsersController.php:437`; it is not runtime-observed.
- **information presented** is applicable only to `index` / `ROUTE-2959` at `app/Http/Controllers/System/UsersController.php:25`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2960` at `app/Http/Controllers/System/UsersController.php:180`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2961` at `app/Http/Controllers/System/UsersController.php:378`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2962` at `app/Http/Controllers/System/UsersController.php:286`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2963` at `app/Http/Controllers/System/UsersController.php:343`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-2964` at `app/Http/Controllers/System/UsersController.php:405`; it is not runtime-observed.
- **mutation outcome source gap (suspend)** is applicable only to `suspend` / `ROUTE-2968` at `app/Http/Controllers/System/UsersController.php:437`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2969` at `app/Http/Controllers/System/UsersController.php:151`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/settings/users/index.tsx`, `resources/js/pages/settings/users/show.tsx`, `resources/js/pages/system/users/Create.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2703` / `update`: fields `name`; success app/Http/Controllers/System/UsersController.php:372 `return redirect()->back()->with('success', 'User updated successfully.');`.
- `ROUTE-2704` / `approve`: fields `role_ids`; success app/Http/Controllers/System/UsersController.php:431 `return redirect()->back()->with('success', 'User approved successfully.');`.
- `ROUTE-2707` / `suspend`: success app/Http/Controllers/System/UsersController.php:455 `return redirect()->back()->with('success', 'User suspended successfully.');`.
- `ROUTE-2960` / `store`: fields `name`; success app/Http/Controllers/System/UsersController.php:280 `->with('success', 'User created successfully.');`.
- `ROUTE-2961` / `destroy`: success app/Http/Controllers/System/UsersController.php:399 `->with('success', 'User deleted successfully.');`.
- `ROUTE-2963` / `update`: fields `name`; success app/Http/Controllers/System/UsersController.php:372 `return redirect()->back()->with('success', 'User updated successfully.');`.
- `ROUTE-2964` / `approve`: fields `role_ids`; success app/Http/Controllers/System/UsersController.php:431 `return redirect()->back()->with('success', 'User approved successfully.');`.
- `ROUTE-2968` / `suspend`: success app/Http/Controllers/System/UsersController.php:455 `return redirect()->back()->with('success', 'User suspended successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/System/UsersController.php:355 `$target->update([`; app/Http/Controllers/System/UsersController.php:361 `$target->roles()->sync($data['role_ids']);`; app/Http/Controllers/System/UsersController.php:363 `$target->forceFill(['role' => $primaryRole?->name ?? 'support_worker'])->save();`; app/Http/Controllers/System/UsersController.php:415 `$target->update([`; app/Http/Controllers/System/UsersController.php:421 `$target->roles()->sync($data['role_ids']);`; app/Http/Controllers/System/UsersController.php:423 `$target->forceFill(['role' => $primaryRole?->name ?? 'support_worker'])->save();`; app/Http/Controllers/System/UsersController.php:444 `$target->update(['approved_at' => null]);`; app/Http/Controllers/System/UsersController.php:448 `$target->staffProfile->update(['status' => 'suspended']);`; app/Http/Controllers/System/UsersController.php:209 `$newUser = User::create([`; app/Http/Controllers/System/UsersController.php:219 `$newUser->roles()->sync($data['role_ids']);`; app/Http/Controllers/System/UsersController.php:221 `$newUser->forceFill(['role' => $primaryRole?->name ?? 'support_worker'])->save();`; app/Http/Controllers/System/UsersController.php:226 `$newUser->roles()->sync([$supportWorkerRole->id]);`; app/Http/Controllers/System/UsersController.php:227 `$newUser->forceFill(['role' => 'support_worker'])->save();`; app/Http/Controllers/System/UsersController.php:233 `$newUser->roles()->sync([$nokRole->id]);`; app/Http/Controllers/System/UsersController.php:234 `$newUser->forceFill(['role' => 'next_of_kin'])->save();`; app/Http/Controllers/System/UsersController.php:241 `Staff::create([`; app/Http/Controllers/System/UsersController.php:251 `Client::create([`; app/Http/Controllers/System/UsersController.php:263 `NextOfKin::create([`; app/Http/Controllers/System/UsersController.php:388 `$target->staffProfile->delete();`; app/Http/Controllers/System/UsersController.php:396 `$target->delete();`; responses app/Http/Controllers/System/UsersController.php:372 `return redirect()->back()->with('success', 'User updated successfully.');`; app/Http/Controllers/System/UsersController.php:431 `return redirect()->back()->with('success', 'User approved successfully.');`; app/Http/Controllers/System/UsersController.php:455 `return redirect()->back()->with('success', 'User suspended successfully.');`; app/Http/Controllers/System/UsersController.php:128 `return Inertia::render('settings/users/index', [`; app/Http/Controllers/System/UsersController.php:279 `return redirect()->route('system.users.index')`; app/Http/Controllers/System/UsersController.php:398 `return redirect()->route('system.users.index')`; app/Http/Controllers/System/UsersController.php:295 `return Inertia::render('settings/users/show', [`; app/Http/Controllers/System/UsersController.php:167 `return Inertia::render('system/users/Create', [`; audit calls app/Http/Controllers/System/UsersController.php:366 `AuditLogger::log('user.updated', $target, [`; app/Http/Controllers/System/UsersController.php:426 `AuditLogger::log('user.approved', $target, [`; app/Http/Controllers/System/UsersController.php:451 `AuditLogger::log('user.suspended', $target, [`; app/Http/Controllers/System/UsersController.php:273 `AuditLogger::log('user.created', $newUser, [`; app/Http/Controllers/System/UsersController.php:391 `AuditLogger::log('user.deleted', $target, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `PUT settings/users/{target}` — `settings.users.update` — `App\Http\Controllers\System\UsersController@update` — `app/Http/Controllers/System/UsersController.php:343` — middleware `web, auth, permission:settings.access.manage`
- `POST settings/users/{target}/approve` — `settings.users.approve` — `App\Http\Controllers\System\UsersController@approve` — `app/Http/Controllers/System/UsersController.php:405` — middleware `web, auth, permission:settings.access.manage`
- `POST settings/users/{target}/suspend` — `settings.users.suspend` — `App\Http\Controllers\System\UsersController@suspend` — `app/Http/Controllers/System/UsersController.php:437` — middleware `web, auth, permission:settings.access.manage`
- `GET|HEAD system/users` — `system.users.index` — `App\Http\Controllers\System\UsersController@index` — `app/Http/Controllers/System/UsersController.php:25` — middleware `web, auth, verified, permission:settings.access.manage`
- `POST system/users` — `system.users.store` — `App\Http\Controllers\System\UsersController@store` — `app/Http/Controllers/System/UsersController.php:180` — middleware `web, auth, verified, permission:settings.access.manage`
- `DELETE system/users/{target}` — `system.users.destroy` — `App\Http\Controllers\System\UsersController@destroy` — `app/Http/Controllers/System/UsersController.php:378` — middleware `web, auth, verified, permission:settings.access.manage`
- `GET|HEAD system/users/{target}` — `system.users.show` — `App\Http\Controllers\System\UsersController@show` — `app/Http/Controllers/System/UsersController.php:286` — middleware `web, auth, verified, permission:settings.access.manage`
- `PUT system/users/{target}` — `system.users.update` — `App\Http\Controllers\System\UsersController@update` — `app/Http/Controllers/System/UsersController.php:343` — middleware `web, auth, verified, permission:settings.access.manage`
- `POST system/users/{target}/approve` — `system.users.approve` — `App\Http\Controllers\System\UsersController@approve` — `app/Http/Controllers/System/UsersController.php:405` — middleware `web, auth, verified, permission:settings.access.manage`
- `POST system/users/{target}/suspend` — `system.users.suspend` — `App\Http\Controllers\System\UsersController@suspend` — `app/Http/Controllers/System/UsersController.php:437` — middleware `web, auth, verified, permission:settings.access.manage`
- `GET|HEAD system/users/create` — `system.users.create` — `App\Http\Controllers\System\UsersController@create` — `app/Http/Controllers/System/UsersController.php:151` — middleware `web, auth, verified, permission:settings.access.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/System/UsersController.php`.
- Exact render/action page relationships: `resources/js/pages/settings/users/index.tsx`, `resources/js/pages/settings/users/show.tsx`, `resources/js/pages/system/users/Create.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
