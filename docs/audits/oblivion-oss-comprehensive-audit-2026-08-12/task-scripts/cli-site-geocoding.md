# CLI-SITE-GEOCODING: Site Geocoding

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clients.create`, `permission:hazards.view`, `permission:sites.update`
- Owning module: Clients and supported people
- Legacy family: `CLI-SITE-GEOCODING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `clients/geocode/search` (`clients.geocode.search`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:clients.create`, `permission:hazards.view`, `permission:sites.update`.
- Exact middleware atoms: `web`, `auth`, `permission:clients.create`, `permission:hazards.view`, `permission:sites.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD clients/geocode/search` (`clients.geocode.search`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD health-safety/lone-workers/geocode/search` (`health-safety.lone-workers.geocode.search`, action `search`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteGeocodingController.php:13-43`.
3. Use `GET|HEAD sites/geocode/search` (`sites.geocode.search`, action `search`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteGeocodingController.php:13-43`.

## Source-applicable states and transitions

- **information presented** is applicable only to `search` / `ROUTE-0201` at `app/Http/Controllers/Sites/SiteGeocodingController.php:13`; it is not runtime-observed.
- **information presented** is applicable only to `search` / `ROUTE-1144` at `app/Http/Controllers/Sites/SiteGeocodingController.php:13`; it is not runtime-observed.
- **information presented** is applicable only to `search` / `ROUTE-2902` at `app/Http/Controllers/Sites/SiteGeocodingController.php:13`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0201` / `search`: fields `q`.
- `ROUTE-1144` / `search`: fields `q`.
- `ROUTE-2902` / `search`: fields `q`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to  the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD clients/geocode/search` — `clients.geocode.search` — `App\Http\Controllers\Sites\SiteGeocodingController@search` — `app/Http/Controllers/Sites/SiteGeocodingController.php:13` — middleware `web, auth, permission:clients.create`
- `GET|HEAD health-safety/lone-workers/geocode/search` — `health-safety.lone-workers.geocode.search` — `App\Http\Controllers\Sites\SiteGeocodingController@search` — `app/Http/Controllers/Sites/SiteGeocodingController.php:13` — middleware `web, auth, permission:hazards.view`
- `GET|HEAD sites/geocode/search` — `sites.geocode.search` — `App\Http\Controllers\Sites\SiteGeocodingController@search` — `app/Http/Controllers/Sites/SiteGeocodingController.php:13` — middleware `web, auth, permission:sites.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteGeocodingController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
