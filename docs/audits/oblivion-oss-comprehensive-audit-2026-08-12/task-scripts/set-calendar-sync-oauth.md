# SET-CALENDAR-SYNC-OAUTH: Calendar Sync OAuth

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:integrations.manage_tenant_secrets`
- Owning module: Settings and system access
- Legacy family: `SET-CALENDAR-SYNC-OAUTH`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `settings/calendar-sync/callback/{provider}` (`settings.calendar-sync.callback`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:integrations.manage_tenant_secrets`.
- Exact middleware atoms: `web`, `auth`, `permission:integrations.manage_tenant_secrets`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD settings/calendar-sync/callback/{provider}` (`settings.calendar-sync.callback`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD settings/calendar-sync/connect/{provider}` (`settings.calendar-sync.connect`, action `redirect`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Settings/CalendarSyncOAuthController.php:40-60`.
3. Invoke only the owning control for `DELETE settings/calendar-sync/connect/{provider}` (`settings.calendar-sync.disconnect`, action `disconnect`). Source category: **mutation outcome source gap (disconnect)**; controller `app/Http/Controllers/Settings/CalendarSyncOAuthController.php:100-112`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `callback` / `ROUTE-2633` at `app/Http/Controllers/Settings/CalendarSyncOAuthController.php:62`; it is not runtime-observed.
- **mutation outcome source gap (disconnect)** is applicable only to `disconnect` / `ROUTE-2634` at `app/Http/Controllers/Settings/CalendarSyncOAuthController.php:100`; it is not runtime-observed.
- **information presented** is applicable only to `redirect` / `ROUTE-2635` at `app/Http/Controllers/Settings/CalendarSyncOAuthController.php:40`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2633` / `callback`: success app/Http/Controllers/Settings/CalendarSyncOAuthController.php:97 `->with('success', ucfirst($provider).' calendar connected.');`; failure app/Http/Controllers/Settings/CalendarSyncOAuthController.php:75 `->withErrors([$provider => 'Could not connect '.ucfirst($provider).': '.$e->getMessage()]);`.
- `ROUTE-2634` / `disconnect`: success app/Http/Controllers/Settings/CalendarSyncOAuthController.php:111 `->with('success', ucfirst($provider).' calendar disconnected.');`.
- `ROUTE-2635` / `redirect`: failure app/Http/Controllers/Settings/CalendarSyncOAuthController.php:47 `->withErrors([$provider => ucfirst($provider).' is not configured. Set its OAuth client credentials first.']);`.

## Failure and recovery paths

- `callback`: app/Http/Controllers/Settings/CalendarSyncOAuthController.php:75 `->withErrors([$provider => 'Could not connect '.ucfirst($provider).': '.$e->getMessage()]);`.
- `redirect`: app/Http/Controllers/Settings/CalendarSyncOAuthController.php:47 `->withErrors([$provider => ucfirst($provider).' is not configured. Set its OAuth client credentials first.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Settings/CalendarSyncOAuthController.php:108 `->delete();`; responses app/Http/Controllers/Settings/CalendarSyncOAuthController.php:74 `return redirect()->route('settings.calendar-sync')`; app/Http/Controllers/Settings/CalendarSyncOAuthController.php:96 `return redirect()->route('settings.calendar-sync')`; app/Http/Controllers/Settings/CalendarSyncOAuthController.php:110 `return redirect()->route('settings.calendar-sync')`; app/Http/Controllers/Settings/CalendarSyncOAuthController.php:46 `return redirect()->route('settings.calendar-sync')`; app/Http/Controllers/Settings/CalendarSyncOAuthController.php:59 `return $socialite->redirect();`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD settings/calendar-sync/callback/{provider}` — `settings.calendar-sync.callback` — `App\Http\Controllers\Settings\CalendarSyncOAuthController@callback` — `app/Http/Controllers/Settings/CalendarSyncOAuthController.php:62` — middleware `web, auth, permission:integrations.manage_tenant_secrets`
- `DELETE settings/calendar-sync/connect/{provider}` — `settings.calendar-sync.disconnect` — `App\Http\Controllers\Settings\CalendarSyncOAuthController@disconnect` — `app/Http/Controllers/Settings/CalendarSyncOAuthController.php:100` — middleware `web, auth, permission:integrations.manage_tenant_secrets`
- `GET|HEAD settings/calendar-sync/connect/{provider}` — `settings.calendar-sync.connect` — `App\Http\Controllers\Settings\CalendarSyncOAuthController@redirect` — `app/Http/Controllers/Settings/CalendarSyncOAuthController.php:40` — middleware `web, auth, permission:integrations.manage_tenant_secrets`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Settings/CalendarSyncOAuthController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
