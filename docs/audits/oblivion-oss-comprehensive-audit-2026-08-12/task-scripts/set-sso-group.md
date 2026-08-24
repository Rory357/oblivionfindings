# SET-SSO-GROUP: Sso Group

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`
- Owning module: Settings and system access
- Legacy family: `SET-SSO-GROUP`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `settings/sso-groups` (`settings.sso_groups.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:settings.access.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD settings/sso-groups` (`settings.sso_groups.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST settings/sso-groups` (`settings.sso_groups.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Settings/SsoGroupController.php:61-77`; `provider`, `external_group_id`, `external_group_name`, `role_id`, `auto_assign`, `auto_remove`.
3. Invoke only the owning control for `DELETE settings/sso-groups/{mapping}` (`settings.sso_groups.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Settings/SsoGroupController.php:94-101`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT settings/sso-groups/{mapping}` (`settings.sso_groups.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Settings/SsoGroupController.php:79-92`; `role_id`, `auto_assign`, `auto_remove`.
5. Invoke only the owning control for `POST settings/sso-groups/fetch` (`settings.sso_groups.fetch`, action `fetchGroups`). Source category: **mutation outcome source gap (fetchGroups)**; controller `app/Http/Controllers/Settings/SsoGroupController.php:34-59`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2688` at `app/Http/Controllers/Settings/SsoGroupController.php:16`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2689` at `app/Http/Controllers/Settings/SsoGroupController.php:61`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2690` at `app/Http/Controllers/Settings/SsoGroupController.php:94`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2691` at `app/Http/Controllers/Settings/SsoGroupController.php:79`; it is not runtime-observed.
- **mutation outcome source gap (fetchGroups)** is applicable only to `fetchGroups` / `ROUTE-2692` at `app/Http/Controllers/Settings/SsoGroupController.php:34`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/settings/sso-groups.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2689` / `store`: fields `provider`, `external_group_id`, `external_group_name`, `role_id`, `auto_assign`, `auto_remove`; success app/Http/Controllers/Settings/SsoGroupController.php:76 `return back()->with('success', 'Group mapping created.');`.
- `ROUTE-2690` / `destroy`: success app/Http/Controllers/Settings/SsoGroupController.php:100 `return back()->with('success', 'Group mapping deleted.');`.
- `ROUTE-2691` / `update`: fields `role_id`, `auto_assign`, `auto_remove`; success app/Http/Controllers/Settings/SsoGroupController.php:91 `return back()->with('success', 'Group mapping updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Settings/SsoGroupController.php:74 `SsoGroupMapping::create($data);`; app/Http/Controllers/Settings/SsoGroupController.php:98 `$mapping->delete();`; app/Http/Controllers/Settings/SsoGroupController.php:89 `$mapping->update($data);`; responses app/Http/Controllers/Settings/SsoGroupController.php:23 `return Inertia::render('settings/sso-groups', [`; app/Http/Controllers/Settings/SsoGroupController.php:76 `return back()->with('success', 'Group mapping created.');`; app/Http/Controllers/Settings/SsoGroupController.php:100 `return back()->with('success', 'Group mapping deleted.');`; app/Http/Controllers/Settings/SsoGroupController.php:91 `return back()->with('success', 'Group mapping updated.');`; app/Http/Controllers/Settings/SsoGroupController.php:45 `return back()->with('error', 'No Microsoft identity found for your account. Please connect a Microsoft account first.');`; app/Http/Controllers/Settings/SsoGroupController.php:49 `return back()->with('error', 'Microsoft token has expired. Please reconnect your Microsoft account.');`; app/Http/Controllers/Settings/SsoGroupController.php:55 `return back()->with('error', 'Could not fetch Microsoft groups. Please try again or reconnect your Microsoft account.');`; app/Http/Controllers/Settings/SsoGroupController.php:58 `return back()->with('groups', $groups);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD settings/sso-groups` — `settings.sso_groups.index` — `App\Http\Controllers\Settings\SsoGroupController@index` — `app/Http/Controllers/Settings/SsoGroupController.php:16` — middleware `web, auth, permission:settings.access.manage`
- `POST settings/sso-groups` — `settings.sso_groups.store` — `App\Http\Controllers\Settings\SsoGroupController@store` — `app/Http/Controllers/Settings/SsoGroupController.php:61` — middleware `web, auth, permission:settings.access.manage`
- `DELETE settings/sso-groups/{mapping}` — `settings.sso_groups.destroy` — `App\Http\Controllers\Settings\SsoGroupController@destroy` — `app/Http/Controllers/Settings/SsoGroupController.php:94` — middleware `web, auth, permission:settings.access.manage`
- `PUT settings/sso-groups/{mapping}` — `settings.sso_groups.update` — `App\Http\Controllers\Settings\SsoGroupController@update` — `app/Http/Controllers/Settings/SsoGroupController.php:79` — middleware `web, auth, permission:settings.access.manage`
- `POST settings/sso-groups/fetch` — `settings.sso_groups.fetch` — `App\Http\Controllers\Settings\SsoGroupController@fetchGroups` — `app/Http/Controllers/Settings/SsoGroupController.php:34` — middleware `web, auth, permission:settings.access.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Settings/SsoGroupController.php`.
- Exact render/action page relationships: `resources/js/pages/settings/sso-groups.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
