# CAP-HR-HR-DOCUMENT-LIBRARY: HR document library movement and audit

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.documents.view`, `permission:hr.documents.manage`
- Owning module: Human resources
- Legacy family: `HR-HR-DOCUMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/documents` (`hr.documents.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.documents.view`, `permission:hr.documents.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.documents.view`, `permission:hr.documents.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/documents` (`hr.documents.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/documents/{document}/audit` (`hr.documents.audit`, action `audit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/HrDocumentController.php:505-570`.
3. Use `GET|HEAD hr/documents/{document}/download` (`hr.documents.download`, action `download`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/HrDocumentController.php:472-499`.
4. Use `GET|HEAD hr/documents/{document}/signed` (`hr.documents.signed`, action `downloadSigned`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/HrDocumentController.php:575-603`.
5. Use `GET|HEAD hr/documents/bulk-download` (`hr.documents.bulk-download`, action `bulkDownload`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/HrDocumentController.php:608-664`.
6. Use `GET|HEAD hr/documents/export` (`hr.documents.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/HrDocumentController.php:747-785`.
7. Use `GET|HEAD hr/documents/upload` (`hr.documents.upload`, action `createUpload`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/HrDocumentController.php:293-317`.
8. Invoke only the owning control for `POST hr/documents` (`hr.documents.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/HrDocumentController.php:322-368`; `employee_profile_id`.
9. Invoke only the owning control for `DELETE hr/documents/{document}` (`hr.documents.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/HrDocumentController.php:790-805`; no exact validation fields extracted.
10. Invoke only the owning control for `PUT hr/documents/{document}` (`hr.documents.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/HrDocumentController.php:723-742`; `title`.
11. Invoke only the owning control for `POST hr/documents/bulk-delete` (`hr.documents.bulk-delete`, action `bulkDestroy`). Source category: **mutation outcome source gap (bulkDestroy)**; controller `app/Http/Controllers/Hr/HrDocumentController.php:693-718`; `ids`.
12. Invoke only the owning control for `POST hr/documents/move` (`hr.documents.move`, action `move`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/HrDocumentController.php:669-688`; `ids`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1395` at `app/Http/Controllers/Hr/HrDocumentController.php:39`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1396` at `app/Http/Controllers/Hr/HrDocumentController.php:322`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1397` at `app/Http/Controllers/Hr/HrDocumentController.php:790`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1398` at `app/Http/Controllers/Hr/HrDocumentController.php:723`; it is not runtime-observed.
- **information presented** is applicable only to `audit` / `ROUTE-1399` at `app/Http/Controllers/Hr/HrDocumentController.php:505`; it is not runtime-observed.
- **file/report delivered** is applicable only to `download` / `ROUTE-1400` at `app/Http/Controllers/Hr/HrDocumentController.php:472`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadSigned` / `ROUTE-1401` at `app/Http/Controllers/Hr/HrDocumentController.php:575`; it is not runtime-observed.
- **mutation outcome source gap (bulkDestroy)** is applicable only to `bulkDestroy` / `ROUTE-1402` at `app/Http/Controllers/Hr/HrDocumentController.php:693`; it is not runtime-observed.
- **information presented** is applicable only to `bulkDownload` / `ROUTE-1403` at `app/Http/Controllers/Hr/HrDocumentController.php:608`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-1404` at `app/Http/Controllers/Hr/HrDocumentController.php:747`; it is not runtime-observed.
- **updated/revised** is applicable only to `move` / `ROUTE-1406` at `app/Http/Controllers/Hr/HrDocumentController.php:669`; it is not runtime-observed.
- **information presented** is applicable only to `createUpload` / `ROUTE-1426` at `app/Http/Controllers/Hr/HrDocumentController.php:293`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/documents/index.tsx`, `resources/js/pages/hr/documents/upload.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1396` / `store`: fields `employee_profile_id`; success app/Http/Controllers/Hr/HrDocumentController.php:367 `return redirect()->route('hr.documents.index')->with('success', 'Document uploaded.');`.
- `ROUTE-1397` / `destroy`: success app/Http/Controllers/Hr/HrDocumentController.php:804 `return redirect()->back()->with('success', 'Document deleted.');`.
- `ROUTE-1398` / `update`: fields `title`; success app/Http/Controllers/Hr/HrDocumentController.php:741 `return redirect()->back()->with('success', 'Document updated.');`.
- `ROUTE-1402` / `bulkDestroy`: fields `ids`; success app/Http/Controllers/Hr/HrDocumentController.php:717 `return redirect()->back()->with('success', $documents->count() . ' document(s) deleted.');`.
- `ROUTE-1403` / `bulkDownload`: fields `ids`.
- `ROUTE-1406` / `move`: fields `ids`; success app/Http/Controllers/Hr/HrDocumentController.php:687 `return redirect()->back()->with('success', 'Documents moved.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/HrDocumentController.php:349 `HrDocument::create([`; app/Http/Controllers/Hr/HrDocumentController.php:799 `Storage::disk($document->storage_disk)->delete($document->storage_path);`; app/Http/Controllers/Hr/HrDocumentController.php:802 `$document->delete();`; app/Http/Controllers/Hr/HrDocumentController.php:739 `$document->update($data);`; app/Http/Controllers/Hr/HrDocumentController.php:712 `Storage::disk($document->storage_disk ?? 'private')->delete($document->storage_path);`; app/Http/Controllers/Hr/HrDocumentController.php:714 `$document->delete();`; app/Http/Controllers/Hr/HrDocumentController.php:685 `->update(['folder' => $data['folder']]);`; responses app/Http/Controllers/Hr/HrDocumentController.php:96 `return $doc['expiry'] !== null && $doc['expiry']['status'] !== 'valid';`; app/Http/Controllers/Hr/HrDocumentController.php:106 `return Inertia::render('hr/documents/index', [`; app/Http/Controllers/Hr/HrDocumentController.php:367 `return redirect()->route('hr.documents.index')->with('success', 'Document uploaded.');`; app/Http/Controllers/Hr/HrDocumentController.php:804 `return redirect()->back()->with('success', 'Document deleted.');`; app/Http/Controllers/Hr/HrDocumentController.php:741 `return redirect()->back()->with('success', 'Document updated.');`; app/Http/Controllers/Hr/HrDocumentController.php:569 `return response()->json(['entries' => $sorted]);`; app/Http/Controllers/Hr/HrDocumentController.php:498 `return Storage::disk($document->storage_disk)->download($document->storage_path, $filename);`; app/Http/Controllers/Hr/HrDocumentController.php:602 `return Storage::disk('private')->download($document->signed_document_path, $filename);`; app/Http/Controllers/Hr/HrDocumentController.php:717 `return redirect()->back()->with('success', $documents->count() . ' document(s) deleted.');`; app/Http/Controllers/Hr/HrDocumentController.php:626 `return redirect()->back()->with('error', 'No documents found to download.');`; app/Http/Controllers/Hr/HrDocumentController.php:658 `return response()->streamDownload(function () use ($tmp) {`; app/Http/Controllers/Hr/HrDocumentController.php:766 `return response()->streamDownload(function () use ($documents) {`; app/Http/Controllers/Hr/HrDocumentController.php:687 `return redirect()->back()->with('success', 'Documents moved.');`; app/Http/Controllers/Hr/HrDocumentController.php:313 `return Inertia::render('hr/documents/upload', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/documents` — `hr.documents.index` — `App\Http\Controllers\Hr\HrDocumentController@index` — `app/Http/Controllers/Hr/HrDocumentController.php:39` — middleware `web, auth, permission:hr.documents.view`
- `POST hr/documents` — `hr.documents.store` — `App\Http\Controllers\Hr\HrDocumentController@store` — `app/Http/Controllers/Hr/HrDocumentController.php:322` — middleware `web, auth, permission:hr.documents.view, permission:hr.documents.manage`
- `DELETE hr/documents/{document}` — `hr.documents.destroy` — `App\Http\Controllers\Hr\HrDocumentController@destroy` — `app/Http/Controllers/Hr/HrDocumentController.php:790` — middleware `web, auth, permission:hr.documents.view, permission:hr.documents.manage`
- `PUT hr/documents/{document}` — `hr.documents.update` — `App\Http\Controllers\Hr\HrDocumentController@update` — `app/Http/Controllers/Hr/HrDocumentController.php:723` — middleware `web, auth, permission:hr.documents.view, permission:hr.documents.manage`
- `GET|HEAD hr/documents/{document}/audit` — `hr.documents.audit` — `App\Http\Controllers\Hr\HrDocumentController@audit` — `app/Http/Controllers/Hr/HrDocumentController.php:505` — middleware `web, auth, permission:hr.documents.view`
- `GET|HEAD hr/documents/{document}/download` — `hr.documents.download` — `App\Http\Controllers\Hr\HrDocumentController@download` — `app/Http/Controllers/Hr/HrDocumentController.php:472` — middleware `web, auth, permission:hr.documents.view`
- `GET|HEAD hr/documents/{document}/signed` — `hr.documents.signed` — `App\Http\Controllers\Hr\HrDocumentController@downloadSigned` — `app/Http/Controllers/Hr/HrDocumentController.php:575` — middleware `web, auth, permission:hr.documents.view`
- `POST hr/documents/bulk-delete` — `hr.documents.bulk-delete` — `App\Http\Controllers\Hr\HrDocumentController@bulkDestroy` — `app/Http/Controllers/Hr/HrDocumentController.php:693` — middleware `web, auth, permission:hr.documents.view, permission:hr.documents.manage`
- `GET|HEAD hr/documents/bulk-download` — `hr.documents.bulk-download` — `App\Http\Controllers\Hr\HrDocumentController@bulkDownload` — `app/Http/Controllers/Hr/HrDocumentController.php:608` — middleware `web, auth, permission:hr.documents.view, permission:hr.documents.manage`
- `GET|HEAD hr/documents/export` — `hr.documents.export` — `App\Http\Controllers\Hr\HrDocumentController@export` — `app/Http/Controllers/Hr/HrDocumentController.php:747` — middleware `web, auth, permission:hr.documents.view`
- `POST hr/documents/move` — `hr.documents.move` — `App\Http\Controllers\Hr\HrDocumentController@move` — `app/Http/Controllers/Hr/HrDocumentController.php:669` — middleware `web, auth, permission:hr.documents.view, permission:hr.documents.manage`
- `GET|HEAD hr/documents/upload` — `hr.documents.upload` — `App\Http\Controllers\Hr\HrDocumentController@createUpload` — `app/Http/Controllers/Hr/HrDocumentController.php:293` — middleware `web, auth, permission:hr.documents.view, permission:hr.documents.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/HrDocumentController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/documents/index.tsx`, `resources/js/pages/hr/documents/upload.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
