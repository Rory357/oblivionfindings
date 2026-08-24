# SET-ROLES: Roles

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`
- Owning module: Settings and system access
- Legacy family: `SET-ROLES`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `settings/roles` (`settings.roles.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:settings.access.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD settings/roles` (`settings.roles.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD settings/roles/{role}/edit` (`settings.roles.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Settings/RolesController.php:107-129`.
3. Use `GET|HEAD settings/roles/create` (`settings.roles.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Settings/RolesController.php:46-74`.
4. Invoke only the owning control for `POST settings/roles` (`settings.roles.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Settings/RolesController.php:76-105`; `name`.
5. Invoke only the owning control for `PUT settings/roles/{role}` (`settings.roles.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Settings/RolesController.php:142-169`; `name`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2676` at `app/Http/Controllers/Settings/RolesController.php:19`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2677` at `app/Http/Controllers/Settings/RolesController.php:76`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2678` at `app/Http/Controllers/Settings/RolesController.php:142`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-2679` at `app/Http/Controllers/Settings/RolesController.php:107`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2680` at `app/Http/Controllers/Settings/RolesController.php:46`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/settings/roles/edit.tsx`, `resources/js/pages/settings/roles/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2677` / `store`: fields `name`.
- `ROUTE-2678` / `update`: fields `name`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Settings/RolesController.php:91 `$role = Role::create([`; app/Http/Controllers/Settings/RolesController.php:101 `$role->permissions()->sync($permissionIds);`; app/Http/Controllers/Settings/RolesController.php:157 `$role->update([`; app/Http/Controllers/Settings/RolesController.php:166 `$role->permissions()->sync($permissionIds);`; responses app/Http/Controllers/Settings/RolesController.php:33 `return inertia('settings/roles/index', [`; app/Http/Controllers/Settings/RolesController.php:104 `return redirect()->route('settings.roles.index');`; app/Http/Controllers/Settings/RolesController.php:168 `return redirect()->route('settings.roles.index');`; app/Http/Controllers/Settings/RolesController.php:115 `return inertia('settings/roles/edit', [`; app/Http/Controllers/Settings/RolesController.php:68 `return inertia('settings/roles/edit', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD settings/roles` — `settings.roles.index` — `App\Http\Controllers\Settings\RolesController@index` — `app/Http/Controllers/Settings/RolesController.php:19` — middleware `web, auth, permission:settings.access.manage`
- `POST settings/roles` — `settings.roles.store` — `App\Http\Controllers\Settings\RolesController@store` — `app/Http/Controllers/Settings/RolesController.php:76` — middleware `web, auth, permission:settings.access.manage`
- `PUT settings/roles/{role}` — `settings.roles.update` — `App\Http\Controllers\Settings\RolesController@update` — `app/Http/Controllers/Settings/RolesController.php:142` — middleware `web, auth, permission:settings.access.manage`
- `GET|HEAD settings/roles/{role}/edit` — `settings.roles.edit` — `App\Http\Controllers\Settings\RolesController@edit` — `app/Http/Controllers/Settings/RolesController.php:107` — middleware `web, auth, permission:settings.access.manage`
- `GET|HEAD settings/roles/create` — `settings.roles.create` — `App\Http\Controllers\Settings\RolesController@create` — `app/Http/Controllers/Settings/RolesController.php:46` — middleware `web, auth, permission:settings.access.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Settings/RolesController.php`.
- Exact render/action page relationships: `resources/js/pages/settings/roles/edit.tsx`, `resources/js/pages/settings/roles/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
