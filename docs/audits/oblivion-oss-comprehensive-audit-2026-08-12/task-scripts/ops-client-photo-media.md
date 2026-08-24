# OPS-CLIENT-PHOTO-MEDIA: Client Photo Media

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clients.viewAny|clients.viewAssigned`
- Owning module: Operations and rostering
- Legacy family: `OPS-CLIENT-PHOTO-MEDIA`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/clients/{client}/gallery-photos/{photo}/media` (`operations.clients.gallery-photos.media`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:clients.viewAny|clients.viewAssigned`.
- Exact middleware atoms: `web`, `auth`, `permission:clients.viewAny|clients.viewAssigned`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/clients/{client}/gallery-photos/{photo}/media` (`operations.clients.gallery-photos.media`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/clients/{client}/gallery-photos/{photo}/thumbnail` (`operations.clients.gallery-photos.thumbnail`, action `staffThumbnail`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ClientPhotoMediaController.php:40-46`.
3. Use `GET|HEAD portal/clients/{client}/photos/{photo}/media` (`portal.clients.photos.media`, action `portalMedia`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ClientPhotoMediaController.php:16-22`.
4. Use `GET|HEAD portal/clients/{client}/photos/{photo}/thumbnail` (`portal.clients.photos.thumbnail`, action `portalThumbnail`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ClientPhotoMediaController.php:24-30`.

## Source-applicable states and transitions

- **information presented** is applicable only to `staffMedia` / `ROUTE-1977` at `app/Http/Controllers/ClientPhotoMediaController.php:32`; it is not runtime-observed.
- **information presented** is applicable only to `staffThumbnail` / `ROUTE-1978` at `app/Http/Controllers/ClientPhotoMediaController.php:40`; it is not runtime-observed.
- **information presented** is applicable only to `portalMedia` / `ROUTE-2273` at `app/Http/Controllers/ClientPhotoMediaController.php:16`; it is not runtime-observed.
- **information presented** is applicable only to `portalThumbnail` / `ROUTE-2274` at `app/Http/Controllers/ClientPhotoMediaController.php:24`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to  the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/clients/{client}/gallery-photos/{photo}/media` — `operations.clients.gallery-photos.media` — `App\Http\Controllers\ClientPhotoMediaController@staffMedia` — `app/Http/Controllers/ClientPhotoMediaController.php:32` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned`
- `GET|HEAD operations/clients/{client}/gallery-photos/{photo}/thumbnail` — `operations.clients.gallery-photos.thumbnail` — `App\Http\Controllers\ClientPhotoMediaController@staffThumbnail` — `app/Http/Controllers/ClientPhotoMediaController.php:40` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned`
- `GET|HEAD portal/clients/{client}/photos/{photo}/media` — `portal.clients.photos.media` — `App\Http\Controllers\ClientPhotoMediaController@portalMedia` — `app/Http/Controllers/ClientPhotoMediaController.php:16` — middleware `web, auth`
- `GET|HEAD portal/clients/{client}/photos/{photo}/thumbnail` — `portal.clients.photos.thumbnail` — `App\Http\Controllers\ClientPhotoMediaController@portalThumbnail` — `app/Http/Controllers/ClientPhotoMediaController.php:24` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ClientPhotoMediaController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
