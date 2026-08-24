# SET-NOTIFICATION-PREFERENCES: Notification Preferences

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`
- Owning module: Settings and system access
- Legacy family: `SET-NOTIFICATION-PREFERENCES`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `settings/notifications` (`settings.notifications`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:settings.access.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD settings/notifications` (`settings.notifications`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD settings/notifications/roles` (`settings.notifications.roles`, action `roles`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Settings/NotificationPreferencesController.php:122-161`.
3. Invoke only the owning control for `PUT settings/notifications` (`settings.notifications.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Settings/NotificationPreferencesController.php:65-91`; `prefs`.
4. Invoke only the owning control for `PUT settings/notifications/delivery` (`settings.notifications.delivery.update`, action `updateDelivery`). Source category: **updated/revised**; controller `app/Http/Controllers/Settings/NotificationPreferencesController.php:98-120`; `dnd_enabled`.
5. Invoke only the owning control for `PUT settings/notifications/roles` (`settings.notifications.roles.update`, action `updateRoles`). Source category: **updated/revised**; controller `app/Http/Controllers/Settings/NotificationPreferencesController.php:163-192`; `matrix`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2659` at `app/Http/Controllers/Settings/NotificationPreferencesController.php:15`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2660` at `app/Http/Controllers/Settings/NotificationPreferencesController.php:65`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateDelivery` / `ROUTE-2661` at `app/Http/Controllers/Settings/NotificationPreferencesController.php:98`; it is not runtime-observed.
- **information presented** is applicable only to `roles` / `ROUTE-2666` at `app/Http/Controllers/Settings/NotificationPreferencesController.php:122`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateRoles` / `ROUTE-2667` at `app/Http/Controllers/Settings/NotificationPreferencesController.php:163`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/settings/notification-defaults.tsx`, `resources/js/pages/settings/notifications.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2660` / `update`: fields `prefs`; success app/Http/Controllers/Settings/NotificationPreferencesController.php:90 `return redirect()->back()->with('success', 'Notification preferences updated.');`.
- `ROUTE-2661` / `updateDelivery`: fields `dnd_enabled`; success app/Http/Controllers/Settings/NotificationPreferencesController.php:119 `return redirect()->back()->with('success', 'Delivery preferences updated.');`.
- `ROUTE-2667` / `updateRoles`: fields `matrix`; success app/Http/Controllers/Settings/NotificationPreferencesController.php:191 `return redirect()->back()->with('success', 'Role notification defaults updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Settings/NotificationPreferencesController.php:79 `UserNotificationPreference::updateOrCreate([`; app/Http/Controllers/Settings/NotificationPreferencesController.php:117 `])))->save();`; app/Http/Controllers/Settings/NotificationPreferencesController.php:179 `RoleNotificationPreference::updateOrCreate([`; responses app/Http/Controllers/Settings/NotificationPreferencesController.php:33 `return inertia('settings/notifications', [`; app/Http/Controllers/Settings/NotificationPreferencesController.php:54 `return [`; app/Http/Controllers/Settings/NotificationPreferencesController.php:90 `return redirect()->back()->with('success', 'Notification preferences updated.');`; app/Http/Controllers/Settings/NotificationPreferencesController.php:119 `return redirect()->back()->with('success', 'Delivery preferences updated.');`; app/Http/Controllers/Settings/NotificationPreferencesController.php:156 `return inertia('settings/notification-defaults', [`; app/Http/Controllers/Settings/NotificationPreferencesController.php:191 `return redirect()->back()->with('success', 'Role notification defaults updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD settings/notifications` — `settings.notifications` — `App\Http\Controllers\Settings\NotificationPreferencesController@index` — `app/Http/Controllers/Settings/NotificationPreferencesController.php:15` — middleware `web, auth`
- `PUT settings/notifications` — `settings.notifications.update` — `App\Http\Controllers\Settings\NotificationPreferencesController@update` — `app/Http/Controllers/Settings/NotificationPreferencesController.php:65` — middleware `web, auth`
- `PUT settings/notifications/delivery` — `settings.notifications.delivery.update` — `App\Http\Controllers\Settings\NotificationPreferencesController@updateDelivery` — `app/Http/Controllers/Settings/NotificationPreferencesController.php:98` — middleware `web, auth`
- `GET|HEAD settings/notifications/roles` — `settings.notifications.roles` — `App\Http\Controllers\Settings\NotificationPreferencesController@roles` — `app/Http/Controllers/Settings/NotificationPreferencesController.php:122` — middleware `web, auth, permission:settings.access.manage`
- `PUT settings/notifications/roles` — `settings.notifications.roles.update` — `App\Http\Controllers\Settings\NotificationPreferencesController@updateRoles` — `app/Http/Controllers/Settings/NotificationPreferencesController.php:163` — middleware `web, auth, permission:settings.access.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Settings/NotificationPreferencesController.php`.
- Exact render/action page relationships: `resources/js/pages/settings/notification-defaults.tsx`, `resources/js/pages/settings/notifications.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
