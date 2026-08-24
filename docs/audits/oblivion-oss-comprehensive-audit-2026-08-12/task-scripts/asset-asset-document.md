# ASSET-ASSET-DOCUMENT: Asset Document

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:assets.documents.manage`, `permission:assets.viewAny|assets.viewAssigned`
- Owning module: Assets and equipment
- Legacy family: `ASSET-ASSET-DOCUMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `assets/{asset}/documents/{document}/download` (`assets.documents.download`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:assets.documents.manage`, `permission:assets.viewAny|assets.viewAssigned`.
- Exact middleware atoms: `web`, `auth`, `permission:assets.documents.manage`, `permission:assets.viewAny|assets.viewAssigned`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD assets/{asset}/documents/{document}/download` (`assets.documents.download`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST assets/{asset}/documents` (`assets.documents.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/AssetDocumentController.php:13-59`; `file`.
3. Invoke only the owning control for `DELETE assets/{asset}/documents/{document}` (`assets.documents.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/AssetDocumentController.php:78-93`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-0049` at `app/Http/Controllers/AssetDocumentController.php:13`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0050` at `app/Http/Controllers/AssetDocumentController.php:78`; it is not runtime-observed.
- **file/report delivered** is applicable only to `download` / `ROUTE-0051` at `app/Http/Controllers/AssetDocumentController.php:61`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0049` / `store`: fields `file`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/AssetDocumentController.php:36 `$doc = AssetDocument::create([`; app/Http/Controllers/AssetDocumentController.php:83 `Storage::disk($document->storage_disk)->delete($document->storage_path);`; app/Http/Controllers/AssetDocumentController.php:84 `$document->delete();`; responses app/Http/Controllers/AssetDocumentController.php:58 `return back();`; app/Http/Controllers/AssetDocumentController.php:92 `return back();`; app/Http/Controllers/AssetDocumentController.php:72 `return Storage::disk($document->storage_disk)->download(`; audit calls app/Http/Controllers/AssetDocumentController.php:52 `AuditLogger::log('assets.documents.create', $doc, [`; app/Http/Controllers/AssetDocumentController.php:86 `AuditLogger::log('assets.documents.delete', $document, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST assets/{asset}/documents` — `assets.documents.store` — `App\Http\Controllers\AssetDocumentController@store` — `app/Http/Controllers/AssetDocumentController.php:13` — middleware `web, auth, permission:assets.documents.manage`
- `DELETE assets/{asset}/documents/{document}` — `assets.documents.destroy` — `App\Http\Controllers\AssetDocumentController@destroy` — `app/Http/Controllers/AssetDocumentController.php:78` — middleware `web, auth, permission:assets.documents.manage`
- `GET|HEAD assets/{asset}/documents/{document}/download` — `assets.documents.download` — `App\Http\Controllers\AssetDocumentController@download` — `app/Http/Controllers/AssetDocumentController.php:61` — middleware `web, auth, permission:assets.viewAny|assets.viewAssigned`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/AssetDocumentController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
