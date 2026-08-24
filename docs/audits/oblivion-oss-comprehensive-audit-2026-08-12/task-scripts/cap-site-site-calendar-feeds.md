# CAP-SITE-SITE-CALENDAR-FEEDS: Calendar feeds views and reset

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:calendar.view`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-CALENDAR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `calendar/feed/{token}.ics` (`calendar.feed`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:calendar.view`.
- Exact middleware atoms: `web`, `throttle:60,1`, `auth`, `verified`, `permission:calendar.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD calendar/feed/{token}.ics` (`calendar.feed`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD calendar/items` (`sites.calendar.global.events`, action `globalEvents`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteCalendarController.php:88-99`.
3. Use `GET|HEAD calendar/site/{site}/feed/{token}.ics` (`calendar.house-feed`, action `houseFeed`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteCalendarController.php:286-310`.
4. Invoke only the owning control for `POST calendar/feed/reset` (`sites.calendar.feed.reset`, action `resetFeed`). Source category: **mutation outcome source gap (resetFeed)**; controller `app/Http/Controllers/Sites/SiteCalendarController.php:249-258`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `feed` / `ROUTE-0084` at `app/Http/Controllers/Sites/SiteCalendarController.php:224`; it is not runtime-observed.
- **mutation outcome source gap (resetFeed)** is applicable only to `resetFeed` / `ROUTE-0085` at `app/Http/Controllers/Sites/SiteCalendarController.php:249`; it is not runtime-observed.
- **information presented** is applicable only to `globalEvents` / `ROUTE-0086` at `app/Http/Controllers/Sites/SiteCalendarController.php:88`; it is not runtime-observed.
- **information presented** is applicable only to `houseFeed` / `ROUTE-0087` at `app/Http/Controllers/Sites/SiteCalendarController.php:286`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0085` / `resetFeed`: success app/Http/Controllers/Sites/SiteCalendarController.php:257 `return redirect()->back()->with('success', 'Calendar subscribe link reset.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteCalendarController.php:255 `$user->save();`; responses app/Http/Controllers/Sites/SiteCalendarController.php:243 `return response($this->icsFeedBuilder->build($items, 'Oblivion Findings — Site Calendar'), 200, [`; app/Http/Controllers/Sites/SiteCalendarController.php:257 `return redirect()->back()->with('success', 'Calendar subscribe link reset.');`; app/Http/Controllers/Sites/SiteCalendarController.php:96 `return response()->json([`; app/Http/Controllers/Sites/SiteCalendarController.php:306 `return response($this->icsFeedBuilder->build($items, $site->name.' — Calendar'), 200, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD calendar/feed/{token}.ics` — `calendar.feed` — `App\Http\Controllers\Sites\SiteCalendarController@feed` — `app/Http/Controllers/Sites/SiteCalendarController.php:224` — middleware `web, throttle:60,1`
- `POST calendar/feed/reset` — `sites.calendar.feed.reset` — `App\Http\Controllers\Sites\SiteCalendarController@resetFeed` — `app/Http/Controllers/Sites/SiteCalendarController.php:249` — middleware `web, auth, verified, permission:calendar.view`
- `GET|HEAD calendar/items` — `sites.calendar.global.events` — `App\Http\Controllers\Sites\SiteCalendarController@globalEvents` — `app/Http/Controllers/Sites/SiteCalendarController.php:88` — middleware `web, auth, verified, permission:calendar.view`
- `GET|HEAD calendar/site/{site}/feed/{token}.ics` — `calendar.house-feed` — `App\Http\Controllers\Sites\SiteCalendarController@houseFeed` — `app/Http/Controllers/Sites/SiteCalendarController.php:286` — middleware `web, throttle:60,1`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteCalendarController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
