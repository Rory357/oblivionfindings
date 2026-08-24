# SEC-DEVICE-GROUP: Device Group

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.groups.manage`
- Owning module: Security and devices
- Legacy family: `SEC-DEVICE-GROUP`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `security-devices/device-groups` (`security-devices.device-groups`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.groups.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.groups.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD security-devices/device-groups` (`security-devices.device-groups`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD security-devices/device-groups/{group}` (`security-devices.device-groups.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:67-119`.
3. Use `GET|HEAD security-devices/device-groups/{group}/auto-rules/preview` (`security-devices.device-groups.auto-rules.preview`, action `previewAutoRules`) only from its authorised surface; inspect the returned information. Source: `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:187-203`.
4. Use `GET|HEAD security-devices/device-groups/{group}/edit` (`security-devices.device-groups.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:150-163`.
5. Use `GET|HEAD security-devices/device-groups/create` (`security-devices.device-groups.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:121-127`.
6. Invoke only the owning control for `POST security-devices/device-groups` (`security-devices.device-groups.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:129-148`; `name`.
7. Invoke only the owning control for `DELETE security-devices/device-groups/{group}` (`security-devices.device-groups.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:227-237`; no exact validation fields extracted.
8. Invoke only the owning control for `PUT security-devices/device-groups/{group}` (`security-devices.device-groups.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:165-181`; `name`.
9. Invoke only the owning control for `POST security-devices/device-groups/{group}/auto-rules/sync` (`security-devices.device-groups.auto-rules.sync`, action `syncAutoRules`). Source category: **retried/replayed/reconciled**; controller `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:209-225`; no exact validation fields extracted.
10. Invoke only the owning control for `POST security-devices/device-groups/{group}/members` (`security-devices.device-groups.add-member`, action `addMember`). Source category: **created/recorded**; controller `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:239-256`; `device_id`.
11. Invoke only the owning control for `DELETE security-devices/device-groups/{group}/members/{device}` (`security-devices.device-groups.remove-member`, action `removeMember`). Source category: **cancelled/removed/archived**; controller `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:258-266`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2531` at `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:23`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2532` at `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:129`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2533` at `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:227`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2534` at `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:67`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2535` at `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:165`; it is not runtime-observed.
- **information presented** is applicable only to `previewAutoRules` / `ROUTE-2536` at `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:187`; it is not runtime-observed.
- **retried/replayed/reconciled** is applicable only to `syncAutoRules` / `ROUTE-2537` at `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:209`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-2538` at `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:150`; it is not runtime-observed.
- **created/recorded** is applicable only to `addMember` / `ROUTE-2539` at `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:239`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `removeMember` / `ROUTE-2540` at `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:258`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2541` at `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:121`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/security-devices/device-groups/create.tsx`, `resources/js/pages/security-devices/device-groups/edit.tsx`, `resources/js/pages/security-devices/device-groups/index.tsx`, `resources/js/pages/security-devices/device-groups/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2532` / `store`: fields `name`; success app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:147 `->with('success', "Group '{$group->name}' created.");`.
- `ROUTE-2533` / `destroy`: success app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:236 `->with('success', "Group '{$name}' deleted.");`.
- `ROUTE-2535` / `update`: fields `name`; success app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:180 `->with('success', "Group '{$group->name}' updated.");`.
- `ROUTE-2539` / `addMember`: fields `device_id`; success app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:255 `return back()->with('success', 'Device added to group.');`; failure app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:250 `return back()->withErrors(['device_id' => 'Device is already a member of this group.']);`.
- `ROUTE-2540` / `removeMember`: success app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:265 `return back()->with('success', 'Device removed from group.');`.

## Failure and recovery paths

- `addMember`: app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:250 `return back()->withErrors(['device_id' => 'Device is already a member of this group.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:144 `$group = DeviceGroup::create($validated);`; app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:233 `$group->delete(); // soft delete`; app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:177 `$group->update($validated);`; app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:253 `$group->devices()->attach($validated['device_id']);`; app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:263 `$group->devices()->detach($device->id);`; responses app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:46 `return Inertia::render('security-devices/device-groups/index', [`; app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:146 `return redirect()->route('security-devices.device-groups.show', $group)`; app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:235 `return redirect()->route('security-devices.device-groups')`; app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:92 `return Inertia::render('security-devices/device-groups/show', [`; app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:179 `return redirect()->route('security-devices.device-groups.show', $group)`; app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:194 `return response()->json([`; app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:216 `return redirect()->back()->with('error', 'This group has no auto-rules configured.');`; app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:221 `return redirect()->back()->with(`; app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:155 `return Inertia::render('security-devices/device-groups/edit', [`; app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:250 `return back()->withErrors(['device_id' => 'Device is already a member of this group.']);`; app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:255 `return back()->with('success', 'Device added to group.');`; app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:265 `return back()->with('success', 'Device removed from group.');`; app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:126 `return Inertia::render('security-devices/device-groups/create');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD security-devices/device-groups` — `security-devices.device-groups` — `App\Domain\SecurityDevices\Http\Controllers\DeviceGroupController@index` — `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:23` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.groups.manage`
- `POST security-devices/device-groups` — `security-devices.device-groups.store` — `App\Domain\SecurityDevices\Http\Controllers\DeviceGroupController@store` — `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:129` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.groups.manage`
- `DELETE security-devices/device-groups/{group}` — `security-devices.device-groups.destroy` — `App\Domain\SecurityDevices\Http\Controllers\DeviceGroupController@destroy` — `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:227` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.groups.manage`
- `GET|HEAD security-devices/device-groups/{group}` — `security-devices.device-groups.show` — `App\Domain\SecurityDevices\Http\Controllers\DeviceGroupController@show` — `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:67` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.groups.manage`
- `PUT security-devices/device-groups/{group}` — `security-devices.device-groups.update` — `App\Domain\SecurityDevices\Http\Controllers\DeviceGroupController@update` — `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:165` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.groups.manage`
- `GET|HEAD security-devices/device-groups/{group}/auto-rules/preview` — `security-devices.device-groups.auto-rules.preview` — `App\Domain\SecurityDevices\Http\Controllers\DeviceGroupController@previewAutoRules` — `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:187` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.groups.manage`
- `POST security-devices/device-groups/{group}/auto-rules/sync` — `security-devices.device-groups.auto-rules.sync` — `App\Domain\SecurityDevices\Http\Controllers\DeviceGroupController@syncAutoRules` — `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:209` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.groups.manage`
- `GET|HEAD security-devices/device-groups/{group}/edit` — `security-devices.device-groups.edit` — `App\Domain\SecurityDevices\Http\Controllers\DeviceGroupController@edit` — `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:150` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.groups.manage`
- `POST security-devices/device-groups/{group}/members` — `security-devices.device-groups.add-member` — `App\Domain\SecurityDevices\Http\Controllers\DeviceGroupController@addMember` — `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:239` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.groups.manage`
- `DELETE security-devices/device-groups/{group}/members/{device}` — `security-devices.device-groups.remove-member` — `App\Domain\SecurityDevices\Http\Controllers\DeviceGroupController@removeMember` — `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:258` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.groups.manage`
- `GET|HEAD security-devices/device-groups/create` — `security-devices.device-groups.create` — `App\Domain\SecurityDevices\Http\Controllers\DeviceGroupController@create` — `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:121` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.groups.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php`.
- Exact render/action page relationships: `resources/js/pages/security-devices/device-groups/create.tsx`, `resources/js/pages/security-devices/device-groups/edit.tsx`, `resources/js/pages/security-devices/device-groups/index.tsx`, `resources/js/pages/security-devices/device-groups/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
