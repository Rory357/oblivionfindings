# CAP-SITE-SITE-CALENDAR-EVENT-PLANNING: Site calendar event creation update approval and exceptions

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:calendar.view`, `permission:calendar.manage_recurring`, `permission:sites.viewAny`, `permission:calendar.create`, `permission:calendar.manage`, `permission:calendar.approve`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-CALENDAR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `calendar` (`sites.calendar.global`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:calendar.view`, `permission:calendar.manage_recurring`, `permission:sites.viewAny`, `permission:calendar.create`, `permission:calendar.manage`, `permission:calendar.approve`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:calendar.view`, `permission:calendar.manage_recurring`, `permission:sites.viewAny`, `permission:calendar.create`, `permission:calendar.manage`, `permission:calendar.approve`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD calendar` (`sites.calendar.global`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD sites/{site}/calendar` (`sites.calendar.index`, action `index`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteCalendarController.php:35-69`.
3. Use `GET|HEAD sites/{site}/calendar/events` (`sites.calendar.events`, action `events`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteCalendarController.php:74-83`.
4. Invoke only the owning control for `POST calendar/events/{event}/exception` (`sites.calendar.exception`, action `createException`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteCalendarController.php:342-360`; `exception_date`, `is_cancelled`, `overridden_fields`.
5. Invoke only the owning control for `POST sites/{site}/calendar/events` (`sites.calendar.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteCalendarController.php:101-137`; `event_type`, `title`, `description`, `room`, `start_at`, `end_at`, `recurrence_rule`, `owner_user_id`, `attendee_user_ids`, `reminder_minutes`, `all_day`.
6. Invoke only the owning control for `DELETE sites/{site}/calendar/events/{event}` (`sites.calendar.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Sites/SiteCalendarController.php:328-340`; no exact validation fields extracted.
7. Invoke only the owning control for `PUT sites/{site}/calendar/events/{event}` (`sites.calendar.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteCalendarController.php:139-176`; `event_type`, `title`, `description`, `room`, `start_at`, `end_at`, `owner_user_id`, `attendee_user_ids`, `recurrence_rule`, `reminder_minutes`, `all_day`.
8. Invoke only the owning control for `POST sites/{site}/calendar/events/{event}/approve` (`sites.calendar.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Sites/SiteCalendarController.php:178-193`; no exact validation fields extracted.
9. Invoke only the owning control for `POST sites/{site}/calendar/events/{event}/reject` (`sites.calendar.reject`, action `reject`). Source category: **rejected/returned**; controller `app/Http/Controllers/Sites/SiteCalendarController.php:195-218`; `approval_notes`.

## Source-applicable states and transitions

- **information presented** is applicable only to `global` / `ROUTE-0082` at `app/Http/Controllers/Sites/SiteCalendarController.php:362`; it is not runtime-observed.
- **created/recorded** is applicable only to `createException` / `ROUTE-0083` at `app/Http/Controllers/Sites/SiteCalendarController.php:342`; it is not runtime-observed.
- **information presented** is applicable only to `index` / `ROUTE-2734` at `app/Http/Controllers/Sites/SiteCalendarController.php:35`; it is not runtime-observed.
- **information presented** is applicable only to `events` / `ROUTE-2735` at `app/Http/Controllers/Sites/SiteCalendarController.php:74`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2736` at `app/Http/Controllers/Sites/SiteCalendarController.php:101`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2737` at `app/Http/Controllers/Sites/SiteCalendarController.php:328`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2738` at `app/Http/Controllers/Sites/SiteCalendarController.php:139`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-2739` at `app/Http/Controllers/Sites/SiteCalendarController.php:178`; it is not runtime-observed.
- **rejected/returned** is applicable only to `reject` / `ROUTE-2740` at `app/Http/Controllers/Sites/SiteCalendarController.php:195`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/calendar/global.tsx`, `resources/js/pages/sites/calendar/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0083` / `createException`: fields `exception_date`, `is_cancelled`, `overridden_fields`; success app/Http/Controllers/Sites/SiteCalendarController.php:359 `return redirect()->back()->with('success', 'Exception created.');`.
- `ROUTE-2736` / `store`: fields `event_type`, `title`, `description`, `room`, `start_at`, `end_at`, `recurrence_rule`, `owner_user_id`, `attendee_user_ids`, `reminder_minutes`, `all_day`; success app/Http/Controllers/Sites/SiteCalendarController.php:136 `return redirect()->back()->with('success', 'Event created.');`.
- `ROUTE-2737` / `destroy`: success app/Http/Controllers/Sites/SiteCalendarController.php:339 `return redirect()->back()->with('success', 'Event deleted.');`.
- `ROUTE-2738` / `update`: fields `event_type`, `title`, `description`, `room`, `start_at`, `end_at`, `owner_user_id`, `attendee_user_ids`, `recurrence_rule`, `reminder_minutes`, `all_day`; success app/Http/Controllers/Sites/SiteCalendarController.php:175 `return redirect()->back()->with('success', 'Event updated.');`.
- `ROUTE-2739` / `approve`: success app/Http/Controllers/Sites/SiteCalendarController.php:192 `return redirect()->back()->with('success', 'Event approved.');`.
- `ROUTE-2740` / `reject`: fields `approval_notes`; success app/Http/Controllers/Sites/SiteCalendarController.php:217 `return redirect()->back()->with('success', 'Event rejected.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteCalendarController.php:125 `$event = SiteCalendarEvent::create([`; app/Http/Controllers/Sites/SiteCalendarController.php:337 `$event->delete();`; app/Http/Controllers/Sites/SiteCalendarController.php:171 `$event->update($validated);`; app/Http/Controllers/Sites/SiteCalendarController.php:183 `$event->update([`; app/Http/Controllers/Sites/SiteCalendarController.php:204 `$event->update([`; responses app/Http/Controllers/Sites/SiteCalendarController.php:374 `return inertia('calendar/global', [`; app/Http/Controllers/Sites/SiteCalendarController.php:359 `return redirect()->back()->with('success', 'Exception created.');`; app/Http/Controllers/Sites/SiteCalendarController.php:41 `return inertia('sites/calendar/index', [`; app/Http/Controllers/Sites/SiteCalendarController.php:80 `return response()->json([`; app/Http/Controllers/Sites/SiteCalendarController.php:136 `return redirect()->back()->with('success', 'Event created.');`; app/Http/Controllers/Sites/SiteCalendarController.php:339 `return redirect()->back()->with('success', 'Event deleted.');`; app/Http/Controllers/Sites/SiteCalendarController.php:175 `return redirect()->back()->with('success', 'Event updated.');`; app/Http/Controllers/Sites/SiteCalendarController.php:192 `return redirect()->back()->with('success', 'Event approved.');`; app/Http/Controllers/Sites/SiteCalendarController.php:217 `return redirect()->back()->with('success', 'Event rejected.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD calendar` — `sites.calendar.global` — `App\Http\Controllers\Sites\SiteCalendarController@global` — `app/Http/Controllers/Sites/SiteCalendarController.php:362` — middleware `web, auth, verified, permission:calendar.view`
- `POST calendar/events/{event}/exception` — `sites.calendar.exception` — `App\Http\Controllers\Sites\SiteCalendarController@createException` — `app/Http/Controllers/Sites/SiteCalendarController.php:342` — middleware `web, auth, verified, permission:calendar.manage_recurring`
- `GET|HEAD sites/{site}/calendar` — `sites.calendar.index` — `App\Http\Controllers\Sites\SiteCalendarController@index` — `app/Http/Controllers/Sites/SiteCalendarController.php:35` — middleware `web, auth, verified, permission:sites.viewAny`
- `GET|HEAD sites/{site}/calendar/events` — `sites.calendar.events` — `App\Http\Controllers\Sites\SiteCalendarController@events` — `app/Http/Controllers/Sites/SiteCalendarController.php:74` — middleware `web, auth, verified, permission:sites.viewAny`
- `POST sites/{site}/calendar/events` — `sites.calendar.store` — `App\Http\Controllers\Sites\SiteCalendarController@store` — `app/Http/Controllers/Sites/SiteCalendarController.php:101` — middleware `web, auth, verified, permission:sites.viewAny, permission:calendar.create`
- `DELETE sites/{site}/calendar/events/{event}` — `sites.calendar.destroy` — `App\Http\Controllers\Sites\SiteCalendarController@destroy` — `app/Http/Controllers/Sites/SiteCalendarController.php:328` — middleware `web, auth, verified, permission:sites.viewAny, permission:calendar.manage`
- `PUT sites/{site}/calendar/events/{event}` — `sites.calendar.update` — `App\Http\Controllers\Sites\SiteCalendarController@update` — `app/Http/Controllers/Sites/SiteCalendarController.php:139` — middleware `web, auth, verified, permission:sites.viewAny, permission:calendar.manage`
- `POST sites/{site}/calendar/events/{event}/approve` — `sites.calendar.approve` — `App\Http\Controllers\Sites\SiteCalendarController@approve` — `app/Http/Controllers/Sites/SiteCalendarController.php:178` — middleware `web, auth, verified, permission:sites.viewAny, permission:calendar.approve`
- `POST sites/{site}/calendar/events/{event}/reject` — `sites.calendar.reject` — `App\Http\Controllers\Sites\SiteCalendarController@reject` — `app/Http/Controllers/Sites/SiteCalendarController.php:195` — middleware `web, auth, verified, permission:sites.viewAny, permission:calendar.approve`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteCalendarController.php`.
- Exact render/action page relationships: `resources/js/pages/calendar/global.tsx`, `resources/js/pages/sites/calendar/index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
