# OPS-OPS-NOTIFICATION: Ops Notification

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Operations and rostering
- Legacy family: `OPS-OPS-NOTIFICATION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/notifications` (`operations.notifications.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/notifications` (`operations.notifications.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PATCH operations/notifications/{notification}/read` (`operations.notifications.read`, action `markRead`). Source category: **mutation outcome source gap (markRead)**; controller `app/Http/Controllers/Operations/OpsNotificationController.php:28-40`; no exact validation fields extracted.
3. Invoke only the owning control for `POST operations/notifications/mark-all-read` (`operations.notifications.read_all`, action `markAllRead`). Source category: **mutation outcome source gap (markAllRead)**; controller `app/Http/Controllers/Operations/OpsNotificationController.php:42-53`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2117` at `app/Http/Controllers/Operations/OpsNotificationController.php:11`; it is not runtime-observed.
- **mutation outcome source gap (markRead)** is applicable only to `markRead` / `ROUTE-2118` at `app/Http/Controllers/Operations/OpsNotificationController.php:28`; it is not runtime-observed.
- **mutation outcome source gap (markAllRead)** is applicable only to `markAllRead` / `ROUTE-2119` at `app/Http/Controllers/Operations/OpsNotificationController.php:42`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/notifications/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2118` / `markRead`: success app/Http/Controllers/Operations/OpsNotificationController.php:39 `return redirect()->back()->with('success', 'Notification marked as read.');`.
- `ROUTE-2119` / `markAllRead`: success app/Http/Controllers/Operations/OpsNotificationController.php:52 `return redirect()->back()->with('success', 'All notifications marked as read.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/OpsNotificationController.php:37 `$notification->update(['read_at' => now()]);`; app/Http/Controllers/Operations/OpsNotificationController.php:50 `->update(['read_at' => now()]);`; responses app/Http/Controllers/Operations/OpsNotificationController.php:23 `return inertia('operations/notifications/Index', [`; app/Http/Controllers/Operations/OpsNotificationController.php:39 `return redirect()->back()->with('success', 'Notification marked as read.');`; app/Http/Controllers/Operations/OpsNotificationController.php:52 `return redirect()->back()->with('success', 'All notifications marked as read.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/notifications` — `operations.notifications.index` — `App\Http\Controllers\Operations\OpsNotificationController@index` — `app/Http/Controllers/Operations/OpsNotificationController.php:11` — middleware `web, auth`
- `PATCH operations/notifications/{notification}/read` — `operations.notifications.read` — `App\Http\Controllers\Operations\OpsNotificationController@markRead` — `app/Http/Controllers/Operations/OpsNotificationController.php:28` — middleware `web, auth`
- `POST operations/notifications/mark-all-read` — `operations.notifications.read_all` — `App\Http\Controllers\Operations\OpsNotificationController@markAllRead` — `app/Http/Controllers/Operations/OpsNotificationController.php:42` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/OpsNotificationController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/notifications/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
