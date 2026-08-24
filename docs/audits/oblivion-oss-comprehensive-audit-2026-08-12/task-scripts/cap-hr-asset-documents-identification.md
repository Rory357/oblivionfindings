# CAP-HR-ASSET-DOCUMENTS-IDENTIFICATION: Employee asset documents and QR identification

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.assets.view`, `permission:hr.assets.manage`
- Owning module: Human resources
- Legacy family: `HR-ASSET`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/assets/{asset}/qr.svg` (`hr.assets.qr.svg`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.assets.view`, `permission:hr.assets.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.assets.view`, `permission:hr.assets.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/assets/{asset}/qr.svg` (`hr.assets.qr.svg`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/assets/documents/{document}/download` (`hr.assets.documents.download`, action `downloadDocument`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/AssetController.php:432-447`.
3. Use `GET|HEAD hr/assets/qr/{token}` (`hr.assets.qr.redirect`, action `qrRedirect`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/AssetController.php:466-476`.
4. Invoke only the owning control for `POST hr/assets/{asset}/documents` (`hr.assets.documents.store`, action `storeDocument`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/AssetController.php:397-430`; `title`.
5. Invoke only the owning control for `DELETE hr/assets/documents/{document}` (`hr.assets.documents.destroy`, action `destroyDocument`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/AssetController.php:449-459`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeDocument` / `ROUTE-1284` at `app/Http/Controllers/Hr/AssetController.php:397`; it is not runtime-observed.
- **information presented** is applicable only to `qrSvg` / `ROUTE-1286` at `app/Http/Controllers/Hr/AssetController.php:479`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyDocument` / `ROUTE-1291` at `app/Http/Controllers/Hr/AssetController.php:449`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadDocument` / `ROUTE-1292` at `app/Http/Controllers/Hr/AssetController.php:432`; it is not runtime-observed.
- **information presented** is applicable only to `qrRedirect` / `ROUTE-1295` at `app/Http/Controllers/Hr/AssetController.php:466`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1284` / `storeDocument`: fields `title`; success app/Http/Controllers/Hr/AssetController.php:429 `return redirect()->back()->with('success', 'Document uploaded.');`.
- `ROUTE-1291` / `destroyDocument`: success app/Http/Controllers/Hr/AssetController.php:458 `return redirect()->back()->with('success', 'Document removed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/AssetController.php:414 `HrAssetDocument::create([`; app/Http/Controllers/Hr/AssetController.php:455 `Storage::disk($document->storage_disk)->delete($document->storage_path);`; app/Http/Controllers/Hr/AssetController.php:456 `$document->delete();`; responses app/Http/Controllers/Hr/AssetController.php:429 `return redirect()->back()->with('success', 'Document uploaded.');`; app/Http/Controllers/Hr/AssetController.php:497 `return Response::make($result->getString(), 200, [`; app/Http/Controllers/Hr/AssetController.php:458 `return redirect()->back()->with('success', 'Document removed.');`; app/Http/Controllers/Hr/AssetController.php:442 `return $disk->download(`; app/Http/Controllers/Hr/AssetController.php:475 `return redirect()->route('hr.assets.show', $asset);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/assets/{asset}/documents` — `hr.assets.documents.store` — `App\Http\Controllers\Hr\AssetController@storeDocument` — `app/Http/Controllers/Hr/AssetController.php:397` — middleware `web, auth, permission:hr.assets.view, permission:hr.assets.manage`
- `GET|HEAD hr/assets/{asset}/qr.svg` — `hr.assets.qr.svg` — `App\Http\Controllers\Hr\AssetController@qrSvg` — `app/Http/Controllers/Hr/AssetController.php:479` — middleware `web, auth, permission:hr.assets.view`
- `DELETE hr/assets/documents/{document}` — `hr.assets.documents.destroy` — `App\Http\Controllers\Hr\AssetController@destroyDocument` — `app/Http/Controllers/Hr/AssetController.php:449` — middleware `web, auth, permission:hr.assets.view, permission:hr.assets.manage`
- `GET|HEAD hr/assets/documents/{document}/download` — `hr.assets.documents.download` — `App\Http\Controllers\Hr\AssetController@downloadDocument` — `app/Http/Controllers/Hr/AssetController.php:432` — middleware `web, auth, permission:hr.assets.view`
- `GET|HEAD hr/assets/qr/{token}` — `hr.assets.qr.redirect` — `App\Http\Controllers\Hr\AssetController@qrRedirect` — `app/Http/Controllers/Hr/AssetController.php:466` — middleware `web, auth, permission:hr.assets.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/AssetController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
