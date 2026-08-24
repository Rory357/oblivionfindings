# ASSET-ASSET-QR: Asset Qr

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:assets.viewAny|assets.viewAssigned`
- Owning module: Assets and equipment
- Legacy family: `ASSET-ASSET-QR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `assets/{asset}/qr.png` (`assets.qr.png`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:assets.viewAny|assets.viewAssigned`.
- Exact middleware atoms: `web`, `auth`, `permission:assets.viewAny|assets.viewAssigned`, `throttle:qr-generation`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD assets/{asset}/qr.png` (`assets.qr.png`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD assets/{asset}/qr.png/download` (`assets.qr.download`, action `downloadPng`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/AssetQrController.php:70-83`.
3. Use `GET|HEAD assets/{asset}/qr.svg` (`assets.qr.svg`, action `svg`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/AssetQrController.php:47-68`.
4. Use `GET|HEAD assets/qr/{token}` (`assets.qr.redirect`, action `redirectByToken`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/AssetQrController.php:16-22`.

## Source-applicable states and transitions

- **information presented** is applicable only to `png` / `ROUTE-0057` at `app/Http/Controllers/AssetQrController.php:24`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadPng` / `ROUTE-0058` at `app/Http/Controllers/AssetQrController.php:70`; it is not runtime-observed.
- **information presented** is applicable only to `svg` / `ROUTE-0059` at `app/Http/Controllers/AssetQrController.php:47`; it is not runtime-observed.
- **information presented** is applicable only to `redirectByToken` / `ROUTE-0064` at `app/Http/Controllers/AssetQrController.php:16`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to the requested file/report being returned or the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD assets/{asset}/qr.png` — `assets.qr.png` — `App\Http\Controllers\AssetQrController@png` — `app/Http/Controllers/AssetQrController.php:24` — middleware `web, auth, permission:assets.viewAny|assets.viewAssigned, throttle:qr-generation`
- `GET|HEAD assets/{asset}/qr.png/download` — `assets.qr.download` — `App\Http\Controllers\AssetQrController@downloadPng` — `app/Http/Controllers/AssetQrController.php:70` — middleware `web, auth, permission:assets.viewAny|assets.viewAssigned, throttle:qr-generation`
- `GET|HEAD assets/{asset}/qr.svg` — `assets.qr.svg` — `App\Http\Controllers\AssetQrController@svg` — `app/Http/Controllers/AssetQrController.php:47` — middleware `web, auth, permission:assets.viewAny|assets.viewAssigned, throttle:qr-generation`
- `GET|HEAD assets/qr/{token}` — `assets.qr.redirect` — `App\Http\Controllers\AssetQrController@redirectByToken` — `app/Http/Controllers/AssetQrController.php:16` — middleware `web, auth, permission:assets.viewAny|assets.viewAssigned`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/AssetQrController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
