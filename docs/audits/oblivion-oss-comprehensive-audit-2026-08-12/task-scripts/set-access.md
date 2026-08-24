# SET-ACCESS: Access

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`
- Owning module: Settings and system access
- Legacy family: `SET-ACCESS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `settings/access` (`settings.access`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:settings.access.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD settings/access` (`settings.access`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PUT settings/access/{target}` (`settings.access.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Settings/AccessController.php:53-110`; `role_ids`.
3. Invoke only the owning control for `POST settings/access/{target}/approve` (`settings.access.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Settings/AccessController.php:112-139`; `role_ids`.
4. Invoke only the owning control for `POST settings/board-members` (`settings.board-members.store`, action `storeBoardMember`). Source category: **created/recorded**; controller `app/Http/Controllers/Settings/AccessController.php:175-223`; `user_id`.
5. Invoke only the owning control for `DELETE settings/board-members/{boardMember}` (`settings.board-members.destroy`, action `destroyBoardMember`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Settings/AccessController.php:225-241`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2614` at `app/Http/Controllers/Settings/AccessController.php:15`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2615` at `app/Http/Controllers/Settings/AccessController.php:53`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-2616` at `app/Http/Controllers/Settings/AccessController.php:112`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeBoardMember` / `ROUTE-2628` at `app/Http/Controllers/Settings/AccessController.php:175`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyBoardMember` / `ROUTE-2629` at `app/Http/Controllers/Settings/AccessController.php:225`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/settings/access.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2615` / `update`: fields `role_ids`; success app/Http/Controllers/Settings/AccessController.php:109 `return redirect()->back()->with('success', 'Access updated.');`.
- `ROUTE-2616` / `approve`: fields `role_ids`; success app/Http/Controllers/Settings/AccessController.php:138 `return redirect()->back()->with('success', 'User approved.');`.
- `ROUTE-2628` / `storeBoardMember`: fields `user_id`; success app/Http/Controllers/Settings/AccessController.php:222 `return redirect()->back()->with('success', 'Board member appointed.');`.
- `ROUTE-2629` / `destroyBoardMember`: success app/Http/Controllers/Settings/AccessController.php:240 `return redirect()->back()->with('success', 'Board member removed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Settings/AccessController.php:68 `$target->roles()->sync($roleIds);`; app/Http/Controllers/Settings/AccessController.php:81 `])->save();`; app/Http/Controllers/Settings/AccessController.php:92 `$target->permissionOverrides()->detach($permissionId);`; app/Http/Controllers/Settings/AccessController.php:131 `])->save();`; app/Http/Controllers/Settings/AccessController.php:197 `$existing->restore();`; app/Http/Controllers/Settings/AccessController.php:198 `$existing->update([`; app/Http/Controllers/Settings/AccessController.php:206 `BoardMember::create([`; app/Http/Controllers/Settings/AccessController.php:232 `$boardMember->update(['is_active' => false]);`; app/Http/Controllers/Settings/AccessController.php:233 `$boardMember->delete();`; responses app/Http/Controllers/Settings/AccessController.php:44 `return inertia('settings/access', [`; app/Http/Controllers/Settings/AccessController.php:109 `return redirect()->back()->with('success', 'Access updated.');`; app/Http/Controllers/Settings/AccessController.php:138 `return redirect()->back()->with('success', 'User approved.');`; app/Http/Controllers/Settings/AccessController.php:222 `return redirect()->back()->with('success', 'Board member appointed.');`; app/Http/Controllers/Settings/AccessController.php:240 `return redirect()->back()->with('success', 'Board member removed.');`; audit calls app/Http/Controllers/Settings/AccessController.php:83 `\App\Services\AuditLogger::log('rbac.roles.updated', $target, [`; app/Http/Controllers/Settings/AccessController.php:103 `\App\Services\AuditLogger::log('rbac.permission.override', $target, [`; app/Http/Controllers/Settings/AccessController.php:133 `\App\Services\AuditLogger::log('rbac.user.approved', $target, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD settings/access` — `settings.access` — `App\Http\Controllers\Settings\AccessController@index` — `app/Http/Controllers/Settings/AccessController.php:15` — middleware `web, auth, permission:settings.access.manage`
- `PUT settings/access/{target}` — `settings.access.update` — `App\Http\Controllers\Settings\AccessController@update` — `app/Http/Controllers/Settings/AccessController.php:53` — middleware `web, auth, permission:settings.access.manage`
- `POST settings/access/{target}/approve` — `settings.access.approve` — `App\Http\Controllers\Settings\AccessController@approve` — `app/Http/Controllers/Settings/AccessController.php:112` — middleware `web, auth, permission:settings.access.manage`
- `POST settings/board-members` — `settings.board-members.store` — `App\Http\Controllers\Settings\AccessController@storeBoardMember` — `app/Http/Controllers/Settings/AccessController.php:175` — middleware `web, auth, permission:settings.access.manage`
- `DELETE settings/board-members/{boardMember}` — `settings.board-members.destroy` — `App\Http\Controllers\Settings\AccessController@destroyBoardMember` — `app/Http/Controllers/Settings/AccessController.php:225` — middleware `web, auth, permission:settings.access.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Settings/AccessController.php`.
- Exact render/action page relationships: `resources/js/pages/settings/access.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
