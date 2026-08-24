# SET-CALENDAR-SYNC-SETTINGS: Calendar Sync Settings

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:integrations.manage_tenant_secrets`
- Owning module: Settings and system access
- Legacy family: `SET-CALENDAR-SYNC-SETTINGS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `settings/calendar-sync` (`settings.calendar-sync`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:integrations.manage_tenant_secrets`.
- Exact middleware atoms: `web`, `auth`, `permission:integrations.manage_tenant_secrets`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD settings/calendar-sync` (`settings.calendar-sync`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD settings/calendar-sync/resources/{provider}` (`settings.calendar-sync.resources`, action `resources`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Settings/CalendarSyncSettingsController.php:176-194`.
3. Invoke only the owning control for `PUT settings/calendar-sync/mapping` (`settings.calendar-sync.mapping`, action `updateMapping`). Source category: **updated/revised**; controller `app/Http/Controllers/Settings/CalendarSyncSettingsController.php:100-154`; `site_id`.
4. Invoke only the owning control for `POST settings/calendar-sync/mapping/{mapping}/reset-feed` (`settings.calendar-sync.reset-feed`, action `resetFeed`). Source category: **mutation outcome source gap (resetFeed)**; controller `app/Http/Controllers/Settings/CalendarSyncSettingsController.php:215-223`; no exact validation fields extracted.
5. Invoke only the owning control for `PUT settings/calendar-sync/settings` (`settings.calendar-sync.settings`, action `updateGlobal`). Source category: **updated/revised**; controller `app/Http/Controllers/Settings/CalendarSyncSettingsController.php:156-171`; `cadence_minutes`.
6. Invoke only the owning control for `POST settings/calendar-sync/sync-now` (`settings.calendar-sync.sync-now`, action `syncNow`). Source category: **retried/replayed/reconciled**; controller `app/Http/Controllers/Settings/CalendarSyncSettingsController.php:196-213`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2632` at `app/Http/Controllers/Settings/CalendarSyncSettingsController.php:36`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateMapping` / `ROUTE-2636` at `app/Http/Controllers/Settings/CalendarSyncSettingsController.php:100`; it is not runtime-observed.
- **mutation outcome source gap (resetFeed)** is applicable only to `resetFeed` / `ROUTE-2637` at `app/Http/Controllers/Settings/CalendarSyncSettingsController.php:215`; it is not runtime-observed.
- **information presented** is applicable only to `resources` / `ROUTE-2638` at `app/Http/Controllers/Settings/CalendarSyncSettingsController.php:176`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateGlobal` / `ROUTE-2639` at `app/Http/Controllers/Settings/CalendarSyncSettingsController.php:156`; it is not runtime-observed.
- **retried/replayed/reconciled** is applicable only to `syncNow` / `ROUTE-2640` at `app/Http/Controllers/Settings/CalendarSyncSettingsController.php:196`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/settings/calendar-sync.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2636` / `updateMapping`: fields `site_id`; success app/Http/Controllers/Settings/CalendarSyncSettingsController.php:123 `return back()->with('success', 'House calendar sync disabled.');`; app/Http/Controllers/Settings/CalendarSyncSettingsController.php:153 `return back()->with('success', 'House calendar mapping saved.');`.
- `ROUTE-2637` / `resetFeed`: success app/Http/Controllers/Settings/CalendarSyncSettingsController.php:222 `return back()->with('success', 'House feed link reset.');`.
- `ROUTE-2639` / `updateGlobal`: fields `cadence_minutes`; success app/Http/Controllers/Settings/CalendarSyncSettingsController.php:170 `return back()->with('success', 'Calendar sync settings saved.');`.
- `ROUTE-2640` / `syncNow`: success app/Http/Controllers/Settings/CalendarSyncSettingsController.php:212 `return back()->with('success', 'Calendar sync queued.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Settings/CalendarSyncSettingsController.php:121 `->update(['is_active' => false]);`; app/Http/Controllers/Settings/CalendarSyncSettingsController.php:144 `$mapping->save();`; app/Http/Controllers/Settings/CalendarSyncSettingsController.php:151 `->update(['is_active' => false]);`; app/Http/Controllers/Settings/CalendarSyncSettingsController.php:220 `$mapping->update(['ical_feed_token' => Str::random(48)]);`; app/Http/Controllers/Settings/CalendarSyncSettingsController.php:165 `AppSetting::updateOrCreate(`; responses app/Http/Controllers/Settings/CalendarSyncSettingsController.php:50 `return [`; app/Http/Controllers/Settings/CalendarSyncSettingsController.php:74 `return [`; app/Http/Controllers/Settings/CalendarSyncSettingsController.php:84 `return Inertia::render('settings/calendar-sync', [`; app/Http/Controllers/Settings/CalendarSyncSettingsController.php:123 `return back()->with('success', 'House calendar sync disabled.');`; app/Http/Controllers/Settings/CalendarSyncSettingsController.php:153 `return back()->with('success', 'House calendar mapping saved.');`; app/Http/Controllers/Settings/CalendarSyncSettingsController.php:222 `return back()->with('success', 'House feed link reset.');`; app/Http/Controllers/Settings/CalendarSyncSettingsController.php:187 `return response()->json(['resources' => [], 'connected' => false]);`; app/Http/Controllers/Settings/CalendarSyncSettingsController.php:190 `return response()->json([`; app/Http/Controllers/Settings/CalendarSyncSettingsController.php:170 `return back()->with('success', 'Calendar sync settings saved.');`; app/Http/Controllers/Settings/CalendarSyncSettingsController.php:212 `return back()->with('success', 'Calendar sync queued.');`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Settings/CalendarSyncSettingsController.php:210 `SyncResourceCalendarsJob::dispatch($mappingId);`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD settings/calendar-sync` — `settings.calendar-sync` — `App\Http\Controllers\Settings\CalendarSyncSettingsController@index` — `app/Http/Controllers/Settings/CalendarSyncSettingsController.php:36` — middleware `web, auth, permission:integrations.manage_tenant_secrets`
- `PUT settings/calendar-sync/mapping` — `settings.calendar-sync.mapping` — `App\Http\Controllers\Settings\CalendarSyncSettingsController@updateMapping` — `app/Http/Controllers/Settings/CalendarSyncSettingsController.php:100` — middleware `web, auth, permission:integrations.manage_tenant_secrets`
- `POST settings/calendar-sync/mapping/{mapping}/reset-feed` — `settings.calendar-sync.reset-feed` — `App\Http\Controllers\Settings\CalendarSyncSettingsController@resetFeed` — `app/Http/Controllers/Settings/CalendarSyncSettingsController.php:215` — middleware `web, auth, permission:integrations.manage_tenant_secrets`
- `GET|HEAD settings/calendar-sync/resources/{provider}` — `settings.calendar-sync.resources` — `App\Http\Controllers\Settings\CalendarSyncSettingsController@resources` — `app/Http/Controllers/Settings/CalendarSyncSettingsController.php:176` — middleware `web, auth, permission:integrations.manage_tenant_secrets`
- `PUT settings/calendar-sync/settings` — `settings.calendar-sync.settings` — `App\Http\Controllers\Settings\CalendarSyncSettingsController@updateGlobal` — `app/Http/Controllers/Settings/CalendarSyncSettingsController.php:156` — middleware `web, auth, permission:integrations.manage_tenant_secrets`
- `POST settings/calendar-sync/sync-now` — `settings.calendar-sync.sync-now` — `App\Http\Controllers\Settings\CalendarSyncSettingsController@syncNow` — `app/Http/Controllers/Settings/CalendarSyncSettingsController.php:196` — middleware `web, auth, permission:integrations.manage_tenant_secrets`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Settings/CalendarSyncSettingsController.php`.
- Exact render/action page relationships: `resources/js/pages/settings/calendar-sync.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
