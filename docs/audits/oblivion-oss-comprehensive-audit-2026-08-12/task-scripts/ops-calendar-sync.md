# OPS-CALENDAR-SYNC: Calendar Sync

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Operations and rostering
- Legacy family: `OPS-CALENDAR-SYNC`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/calendar-sync` (`operations.calendar_sync.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/calendar-sync` (`operations.calendar_sync.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/calendar-sync/create` (`operations.calendar_sync.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/CalendarSyncController.php:27-33`.
3. Invoke only the owning control for `POST operations/calendar-sync` (`operations.calendar_sync.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/CalendarSyncController.php:35-57`; `provider`.
4. Invoke only the owning control for `DELETE operations/calendar-sync/{sync}` (`operations.calendar_sync.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Operations/CalendarSyncController.php:59-71`; no exact validation fields extracted.
5. Invoke only the owning control for `POST operations/calendar-sync/{sync}/trigger` (`operations.calendar_sync.trigger`, action `triggerSync`). Source category: **mutation outcome source gap (triggerSync)**; controller `app/Http/Controllers/Operations/CalendarSyncController.php:73-92`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1900` at `app/Http/Controllers/Operations/CalendarSyncController.php:11`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1901` at `app/Http/Controllers/Operations/CalendarSyncController.php:35`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1902` at `app/Http/Controllers/Operations/CalendarSyncController.php:59`; it is not runtime-observed.
- **mutation outcome source gap (triggerSync)** is applicable only to `triggerSync` / `ROUTE-1903` at `app/Http/Controllers/Operations/CalendarSyncController.php:73`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1904` at `app/Http/Controllers/Operations/CalendarSyncController.php:27`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/calendar-sync/Create.tsx`, `resources/js/pages/operations/calendar-sync/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1901` / `store`: fields `provider`; success app/Http/Controllers/Operations/CalendarSyncController.php:56 `return redirect()->back()->with('success', 'Calendar sync created.');`.
- `ROUTE-1902` / `destroy`: success app/Http/Controllers/Operations/CalendarSyncController.php:70 `return redirect()->back()->with('success', 'Calendar sync removed.');`.
- `ROUTE-1903` / `triggerSync`: success app/Http/Controllers/Operations/CalendarSyncController.php:91 `return redirect()->back()->with('success', 'Calendar sync triggered.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/CalendarSyncController.php:47 `CalendarSync::create([`; app/Http/Controllers/Operations/CalendarSyncController.php:68 `$sync->delete();`; app/Http/Controllers/Operations/CalendarSyncController.php:84 `$sync->update([`; responses app/Http/Controllers/Operations/CalendarSyncController.php:22 `return inertia('operations/calendar-sync/Index', [`; app/Http/Controllers/Operations/CalendarSyncController.php:56 `return redirect()->back()->with('success', 'Calendar sync created.');`; app/Http/Controllers/Operations/CalendarSyncController.php:70 `return redirect()->back()->with('success', 'Calendar sync removed.');`; app/Http/Controllers/Operations/CalendarSyncController.php:91 `return redirect()->back()->with('success', 'Calendar sync triggered.');`; app/Http/Controllers/Operations/CalendarSyncController.php:32 `return inertia('operations/calendar-sync/Create');`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Operations/CalendarSyncController.php:89 `// SyncCalendarJob::dispatch($sync);`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD operations/calendar-sync` — `operations.calendar_sync.index` — `App\Http\Controllers\Operations\CalendarSyncController@index` — `app/Http/Controllers/Operations/CalendarSyncController.php:11` — middleware `web, auth`
- `POST operations/calendar-sync` — `operations.calendar_sync.store` — `App\Http\Controllers\Operations\CalendarSyncController@store` — `app/Http/Controllers/Operations/CalendarSyncController.php:35` — middleware `web, auth`
- `DELETE operations/calendar-sync/{sync}` — `operations.calendar_sync.destroy` — `App\Http\Controllers\Operations\CalendarSyncController@destroy` — `app/Http/Controllers/Operations/CalendarSyncController.php:59` — middleware `web, auth`
- `POST operations/calendar-sync/{sync}/trigger` — `operations.calendar_sync.trigger` — `App\Http\Controllers\Operations\CalendarSyncController@triggerSync` — `app/Http/Controllers/Operations/CalendarSyncController.php:73` — middleware `web, auth`
- `GET|HEAD operations/calendar-sync/create` — `operations.calendar_sync.create` — `App\Http\Controllers\Operations\CalendarSyncController@create` — `app/Http/Controllers/Operations/CalendarSyncController.php:27` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/CalendarSyncController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/calendar-sync/Create.tsx`, `resources/js/pages/operations/calendar-sync/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
