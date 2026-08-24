# SET-NOTIFICATION-ESCALATIONS: Notification Escalations

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`
- Owning module: Settings and system access
- Legacy family: `SET-NOTIFICATION-ESCALATIONS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `settings/notifications/escalations` (`settings.notifications.escalations`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:settings.access.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD settings/notifications/escalations` (`settings.notifications.escalations`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PUT settings/notifications/escalations` (`settings.notifications.escalations.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Settings/NotificationEscalationsController.php:54-93`; `rules`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2662` at `app/Http/Controllers/Settings/NotificationEscalationsController.php:11`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2663` at `app/Http/Controllers/Settings/NotificationEscalationsController.php:54`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/settings/notification-escalations.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2663` / `update`: fields `rules`; success app/Http/Controllers/Settings/NotificationEscalationsController.php:92 `return redirect()->back()->with('success', 'Escalation rules updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Settings/NotificationEscalationsController.php:77 `NotificationEscalationRule::updateOrCreate([`; responses app/Http/Controllers/Settings/NotificationEscalationsController.php:41 `return inertia('settings/notification-escalations', [`; app/Http/Controllers/Settings/NotificationEscalationsController.php:92 `return redirect()->back()->with('success', 'Escalation rules updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD settings/notifications/escalations` — `settings.notifications.escalations` — `App\Http\Controllers\Settings\NotificationEscalationsController@index` — `app/Http/Controllers/Settings/NotificationEscalationsController.php:11` — middleware `web, auth, permission:settings.access.manage`
- `PUT settings/notifications/escalations` — `settings.notifications.escalations.update` — `App\Http\Controllers\Settings\NotificationEscalationsController@update` — `app/Http/Controllers/Settings/NotificationEscalationsController.php:54` — middleware `web, auth, permission:settings.access.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Settings/NotificationEscalationsController.php`.
- Exact render/action page relationships: `resources/js/pages/settings/notification-escalations.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
