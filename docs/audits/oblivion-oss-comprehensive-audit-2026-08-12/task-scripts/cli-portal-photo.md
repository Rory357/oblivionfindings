# CLI-PORTAL-PHOTO: Portal Photo

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Clients and supported people
- Legacy family: `CLI-PORTAL-PHOTO`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `portal/clients/{client}/photos` (`portal.clients.photos`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD portal/clients/{client}/photos` (`portal.clients.photos`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST portal/clients/{client}/photos` (`portal.clients.photos.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Portal/PortalPhotoController.php:65-118`; `photo`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2271` at `app/Http/Controllers/Portal/PortalPhotoController.php:23`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2272` at `app/Http/Controllers/Portal/PortalPhotoController.php:65`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/portal/photos.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2272` / `store`: fields `photo`; success app/Http/Controllers/Portal/PortalPhotoController.php:117 `return redirect()->back()->with('success', 'Photo uploaded successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Portal/PortalPhotoController.php:87 `$photo = ClientPhoto::create([`; responses app/Http/Controllers/Portal/PortalPhotoController.php:39 `return [`; app/Http/Controllers/Portal/PortalPhotoController.php:51 `return inertia('portal/photos', [`; app/Http/Controllers/Portal/PortalPhotoController.php:117 `return redirect()->back()->with('success', 'Photo uploaded successfully.');`; audit calls app/Http/Controllers/Portal/PortalPhotoController.php:115 `AuditLogger::log('portal.photo.upload', $photo);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD portal/clients/{client}/photos` — `portal.clients.photos` — `App\Http\Controllers\Portal\PortalPhotoController@index` — `app/Http/Controllers/Portal/PortalPhotoController.php:23` — middleware `web, auth`
- `POST portal/clients/{client}/photos` — `portal.clients.photos.store` — `App\Http\Controllers\Portal\PortalPhotoController@store` — `app/Http/Controllers/Portal/PortalPhotoController.php:65` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Portal/PortalPhotoController.php`.
- Exact render/action page relationships: `resources/js/pages/portal/photos.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
