# PORT-PORTAL-NOTIFICATION: Portal Notification

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Client and family portal
- Legacy family: `PORT-PORTAL-NOTIFICATION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `portal/notifications` (`portal.notifications`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD portal/notifications` (`portal.notifications`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST portal/notifications/{notification}/read` (`portal.notifications.read`, action `markRead`). Source category: **mutation outcome source gap (markRead)**; controller `app/Http/Controllers/Portal/PortalNotificationController.php:40-49`; no exact validation fields extracted.
3. Invoke only the owning control for `POST portal/notifications/read-all` (`portal.notifications.readAll`, action `markAllRead`). Source category: **mutation outcome source gap (markAllRead)**; controller `app/Http/Controllers/Portal/PortalNotificationController.php:51-59`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2285` at `app/Http/Controllers/Portal/PortalNotificationController.php:10`; it is not runtime-observed.
- **mutation outcome source gap (markRead)** is applicable only to `markRead` / `ROUTE-2286` at `app/Http/Controllers/Portal/PortalNotificationController.php:40`; it is not runtime-observed.
- **mutation outcome source gap (markAllRead)** is applicable only to `markAllRead` / `ROUTE-2287` at `app/Http/Controllers/Portal/PortalNotificationController.php:51`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/portal/notifications.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Portal/PortalNotificationController.php:46 `$notification->update(['read_at' => now()]);`; app/Http/Controllers/Portal/PortalNotificationController.php:56 `$user->unreadNotifications()->update(['read_at' => now()]);`; responses app/Http/Controllers/Portal/PortalNotificationController.php:33 `return inertia('portal/notifications', [`; app/Http/Controllers/Portal/PortalNotificationController.php:48 `return redirect()->back();`; app/Http/Controllers/Portal/PortalNotificationController.php:58 `return redirect()->back();`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD portal/notifications` — `portal.notifications` — `App\Http\Controllers\Portal\PortalNotificationController@index` — `app/Http/Controllers/Portal/PortalNotificationController.php:10` — middleware `web, auth`
- `POST portal/notifications/{notification}/read` — `portal.notifications.read` — `App\Http\Controllers\Portal\PortalNotificationController@markRead` — `app/Http/Controllers/Portal/PortalNotificationController.php:40` — middleware `web, auth`
- `POST portal/notifications/read-all` — `portal.notifications.readAll` — `App\Http\Controllers\Portal\PortalNotificationController@markAllRead` — `app/Http/Controllers/Portal/PortalNotificationController.php:51` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Portal/PortalNotificationController.php`.
- Exact render/action page relationships: `resources/js/pages/portal/notifications.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
