# SITE-SITE-DOCUMENT: Site Document

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:sites.update`, `permission:sites.viewAny`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-DOCUMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/documents` (`sites.documents.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:sites.update`, `permission:sites.viewAny`.
- Exact middleware atoms: `web`, `auth`, `permission:sites.update`, `permission:sites.viewAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/documents` (`sites.documents.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD sites/{site}/documents/{document}/download` (`sites.documents.download`, action `download`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/SiteDocumentController.php:199-213`.
3. Invoke only the owning control for `POST sites/{site}/document-folders` (`sites.document-folders.store`, action `storeFolder`). Source category: **created/recorded**; controller `app/Http/Controllers/SiteDocumentController.php:183-197`; `name`.
4. Invoke only the owning control for `POST sites/{site}/documents` (`sites.documents.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/SiteDocumentController.php:79-149`; `file`.
5. Invoke only the owning control for `DELETE sites/{site}/documents/{document}` (`sites.documents.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/SiteDocumentController.php:215-235`; no exact validation fields extracted.
6. Invoke only the owning control for `PUT sites/{site}/documents/{document}` (`sites.documents.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/SiteDocumentController.php:151-181`; `title`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeFolder` / `ROUTE-2778` at `app/Http/Controllers/SiteDocumentController.php:183`; it is not runtime-observed.
- **information presented** is applicable only to `index` / `ROUTE-2779` at `app/Http/Controllers/SiteDocumentController.php:16`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2780` at `app/Http/Controllers/SiteDocumentController.php:79`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2781` at `app/Http/Controllers/SiteDocumentController.php:215`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2782` at `app/Http/Controllers/SiteDocumentController.php:151`; it is not runtime-observed.
- **file/report delivered** is applicable only to `download` / `ROUTE-2783` at `app/Http/Controllers/SiteDocumentController.php:199`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/sites/documents.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2778` / `storeFolder`: fields `name`; success app/Http/Controllers/SiteDocumentController.php:196 `return back()->with('success', 'Folder created.');`; failure app/Http/Controllers/SiteDocumentController.php:193 `return back()->withErrors(['name' => 'The folder name field is required.']);`.
- `ROUTE-2780` / `store`: fields `file`; success app/Http/Controllers/SiteDocumentController.php:148 `return back()->with('success', 'Document uploaded.');`.
- `ROUTE-2781` / `destroy`: success app/Http/Controllers/SiteDocumentController.php:234 `return back()->with('success', 'Document deleted.');`.
- `ROUTE-2782` / `update`: fields `title`; success app/Http/Controllers/SiteDocumentController.php:180 `return back()->with('success', 'Document updated.');`.

## Failure and recovery paths

- `storeFolder`: app/Http/Controllers/SiteDocumentController.php:193 `return back()->withErrors(['name' => 'The folder name field is required.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/SiteDocumentController.php:100 `$doc = SiteDocument::create([`; app/Http/Controllers/SiteDocumentController.php:220 `Storage::disk($document->storage_disk)->delete($document->storage_path);`; app/Http/Controllers/SiteDocumentController.php:221 `$document->delete();`; app/Http/Controllers/SiteDocumentController.php:170 `$document->update($data);`; responses app/Http/Controllers/SiteDocumentController.php:193 `return back()->withErrors(['name' => 'The folder name field is required.']);`; app/Http/Controllers/SiteDocumentController.php:196 `return back()->with('success', 'Folder created.');`; app/Http/Controllers/SiteDocumentController.php:44 `return inertia('sites/documents', [`; app/Http/Controllers/SiteDocumentController.php:130 `return response()->json([`; app/Http/Controllers/SiteDocumentController.php:148 `return back()->with('success', 'Document uploaded.');`; app/Http/Controllers/SiteDocumentController.php:234 `return back()->with('success', 'Document deleted.');`; app/Http/Controllers/SiteDocumentController.php:180 `return back()->with('success', 'Document updated.');`; app/Http/Controllers/SiteDocumentController.php:209 `return Storage::disk($document->storage_disk)->download(`; audit calls app/Http/Controllers/SiteDocumentController.php:118 `AuditLogger::log('sites.documents.upload', $doc, [`; app/Http/Controllers/SiteDocumentController.php:223 `AuditLogger::log('sites.documents.delete', $site, [`; app/Http/Controllers/SiteDocumentController.php:172 `AuditLogger::log('sites.documents.update', $document, ['site_id' => $site->id]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST sites/{site}/document-folders` — `sites.document-folders.store` — `App\Http\Controllers\SiteDocumentController@storeFolder` — `app/Http/Controllers/SiteDocumentController.php:183` — middleware `web, auth, permission:sites.update`
- `GET|HEAD sites/{site}/documents` — `sites.documents.index` — `App\Http\Controllers\SiteDocumentController@index` — `app/Http/Controllers/SiteDocumentController.php:16` — middleware `web, auth, permission:sites.viewAny`
- `POST sites/{site}/documents` — `sites.documents.store` — `App\Http\Controllers\SiteDocumentController@store` — `app/Http/Controllers/SiteDocumentController.php:79` — middleware `web, auth, permission:sites.update`
- `DELETE sites/{site}/documents/{document}` — `sites.documents.destroy` — `App\Http\Controllers\SiteDocumentController@destroy` — `app/Http/Controllers/SiteDocumentController.php:215` — middleware `web, auth, permission:sites.update`
- `PUT sites/{site}/documents/{document}` — `sites.documents.update` — `App\Http\Controllers\SiteDocumentController@update` — `app/Http/Controllers/SiteDocumentController.php:151` — middleware `web, auth, permission:sites.update`
- `GET|HEAD sites/{site}/documents/{document}/download` — `sites.documents.download` — `App\Http\Controllers\SiteDocumentController@download` — `app/Http/Controllers/SiteDocumentController.php:199` — middleware `web, auth, permission:sites.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/SiteDocumentController.php`.
- Exact render/action page relationships: `resources/js/pages/sites/documents.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
