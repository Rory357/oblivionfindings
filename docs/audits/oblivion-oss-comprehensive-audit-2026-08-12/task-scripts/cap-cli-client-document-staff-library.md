# CAP-CLI-CLIENT-DOCUMENT-STAFF-LIBRARY: Staff client document library

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clients.update`, `permission:clients.viewAny|clients.viewAssigned`
- Owning module: Clients and supported people
- Legacy family: `CLI-CLIENT-DOCUMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `clients/{client}/documents` (`clients.documents.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:clients.update`, `permission:clients.viewAny|clients.viewAssigned`.
- Exact middleware atoms: `web`, `auth`, `permission:clients.update`, `permission:clients.viewAny|clients.viewAssigned`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD clients/{client}/documents` (`clients.documents.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD clients/{client}/documents/{document}/download` (`clients.documents.download`, action `download`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/ClientDocumentController.php:224-259`.
3. Use `GET|HEAD operations/clients/{client}/documents` (`operations.clients.documents.index`, action `index`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ClientDocumentController.php:23-82`.
4. Use `GET|HEAD operations/clients/{client}/documents/{document}/download` (`operations.clients.documents.download`, action `download`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/ClientDocumentController.php:224-259`.
5. Invoke only the owning control for `POST clients/{client}/document-folders` (`clients.document-folders.store`, action `storeFolder`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientDocumentController.php:208-222`; `name`.
6. Invoke only the owning control for `POST clients/{client}/documents` (`clients.documents.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientDocumentController.php:84-170`; `file`.
7. Invoke only the owning control for `DELETE clients/{client}/documents/{document}` (`clients.documents.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ClientDocumentController.php:261-276`; no exact validation fields extracted.
8. Invoke only the owning control for `PUT clients/{client}/documents/{document}` (`clients.documents.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientDocumentController.php:172-206`; `title`.
9. Invoke only the owning control for `POST operations/clients/{client}/document-folders` (`operations.clients.document-folders.store`, action `storeFolder`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientDocumentController.php:208-222`; `name`.
10. Invoke only the owning control for `POST operations/clients/{client}/documents` (`operations.clients.documents.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientDocumentController.php:84-170`; `file`.
11. Invoke only the owning control for `DELETE operations/clients/{client}/documents/{document}` (`operations.clients.documents.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ClientDocumentController.php:261-276`; no exact validation fields extracted.
12. Invoke only the owning control for `PUT operations/clients/{client}/documents/{document}` (`operations.clients.documents.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientDocumentController.php:172-206`; `title`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeFolder` / `ROUTE-0146` at `app/Http/Controllers/ClientDocumentController.php:208`; it is not runtime-observed.
- **information presented** is applicable only to `index` / `ROUTE-0147` at `app/Http/Controllers/ClientDocumentController.php:23`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0148` at `app/Http/Controllers/ClientDocumentController.php:84`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0149` at `app/Http/Controllers/ClientDocumentController.php:261`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0150` at `app/Http/Controllers/ClientDocumentController.php:172`; it is not runtime-observed.
- **file/report delivered** is applicable only to `download` / `ROUTE-0151` at `app/Http/Controllers/ClientDocumentController.php:224`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeFolder` / `ROUTE-1963` at `app/Http/Controllers/ClientDocumentController.php:208`; it is not runtime-observed.
- **information presented** is applicable only to `index` / `ROUTE-1964` at `app/Http/Controllers/ClientDocumentController.php:23`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1965` at `app/Http/Controllers/ClientDocumentController.php:84`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1966` at `app/Http/Controllers/ClientDocumentController.php:261`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1967` at `app/Http/Controllers/ClientDocumentController.php:172`; it is not runtime-observed.
- **file/report delivered** is applicable only to `download` / `ROUTE-1968` at `app/Http/Controllers/ClientDocumentController.php:224`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/clients/documents.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0146` / `storeFolder`: fields `name`; success app/Http/Controllers/ClientDocumentController.php:221 `return back()->with('success', 'Folder created.');`; failure app/Http/Controllers/ClientDocumentController.php:218 `return back()->withErrors(['name' => 'The folder name field is required.']);`.
- `ROUTE-0148` / `store`: fields `file`; success app/Http/Controllers/ClientDocumentController.php:169 `return back()->with('success', 'Document uploaded.');`.
- `ROUTE-0149` / `destroy`: success app/Http/Controllers/ClientDocumentController.php:275 `return back()->with('success', 'Document deleted.');`.
- `ROUTE-0150` / `update`: fields `title`; success app/Http/Controllers/ClientDocumentController.php:205 `return back()->with('success', 'Document updated.');`.
- `ROUTE-1963` / `storeFolder`: fields `name`; success app/Http/Controllers/ClientDocumentController.php:221 `return back()->with('success', 'Folder created.');`; failure app/Http/Controllers/ClientDocumentController.php:218 `return back()->withErrors(['name' => 'The folder name field is required.']);`.
- `ROUTE-1965` / `store`: fields `file`; success app/Http/Controllers/ClientDocumentController.php:169 `return back()->with('success', 'Document uploaded.');`.
- `ROUTE-1966` / `destroy`: success app/Http/Controllers/ClientDocumentController.php:275 `return back()->with('success', 'Document deleted.');`.
- `ROUTE-1967` / `update`: fields `title`; success app/Http/Controllers/ClientDocumentController.php:205 `return back()->with('success', 'Document updated.');`.

## Failure and recovery paths

- `storeFolder`: app/Http/Controllers/ClientDocumentController.php:218 `return back()->withErrors(['name' => 'The folder name field is required.']);`.
- `storeFolder`: app/Http/Controllers/ClientDocumentController.php:218 `return back()->withErrors(['name' => 'The folder name field is required.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ClientDocumentController.php:106 `$doc = ClientDocument::create([`; app/Http/Controllers/ClientDocumentController.php:129 `$client->forceFill(['openai_vector_store_id' => $vsId])->save();`; app/Http/Controllers/ClientDocumentController.php:138 `$doc->forceFill(['openai_file_id' => $fileId])->save();`; app/Http/Controllers/ClientDocumentController.php:266 `Storage::disk($document->storage_disk)->delete($document->storage_path);`; app/Http/Controllers/ClientDocumentController.php:267 `$document->delete();`; app/Http/Controllers/ClientDocumentController.php:197 `$document->update($data);`; responses app/Http/Controllers/ClientDocumentController.php:218 `return back()->withErrors(['name' => 'The folder name field is required.']);`; app/Http/Controllers/ClientDocumentController.php:221 `return back()->with('success', 'Folder created.');`; app/Http/Controllers/ClientDocumentController.php:54 `return inertia('operations/clients/documents', [`; app/Http/Controllers/ClientDocumentController.php:169 `return back()->with('success', 'Document uploaded.');`; app/Http/Controllers/ClientDocumentController.php:275 `return back()->with('success', 'Document deleted.');`; app/Http/Controllers/ClientDocumentController.php:205 `return back()->with('success', 'Document updated.');`; app/Http/Controllers/ClientDocumentController.php:255 `return Storage::disk($document->storage_disk)->download(`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST clients/{client}/document-folders` — `clients.document-folders.store` — `App\Http\Controllers\ClientDocumentController@storeFolder` — `app/Http/Controllers/ClientDocumentController.php:208` — middleware `web, auth, permission:clients.update`
- `GET|HEAD clients/{client}/documents` — `clients.documents.index` — `App\Http\Controllers\ClientDocumentController@index` — `app/Http/Controllers/ClientDocumentController.php:23` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned`
- `POST clients/{client}/documents` — `clients.documents.store` — `App\Http\Controllers\ClientDocumentController@store` — `app/Http/Controllers/ClientDocumentController.php:84` — middleware `web, auth, permission:clients.update`
- `DELETE clients/{client}/documents/{document}` — `clients.documents.destroy` — `App\Http\Controllers\ClientDocumentController@destroy` — `app/Http/Controllers/ClientDocumentController.php:261` — middleware `web, auth, permission:clients.update`
- `PUT clients/{client}/documents/{document}` — `clients.documents.update` — `App\Http\Controllers\ClientDocumentController@update` — `app/Http/Controllers/ClientDocumentController.php:172` — middleware `web, auth, permission:clients.update`
- `GET|HEAD clients/{client}/documents/{document}/download` — `clients.documents.download` — `App\Http\Controllers\ClientDocumentController@download` — `app/Http/Controllers/ClientDocumentController.php:224` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned`
- `POST operations/clients/{client}/document-folders` — `operations.clients.document-folders.store` — `App\Http\Controllers\ClientDocumentController@storeFolder` — `app/Http/Controllers/ClientDocumentController.php:208` — middleware `web, auth, permission:clients.update`
- `GET|HEAD operations/clients/{client}/documents` — `operations.clients.documents.index` — `App\Http\Controllers\ClientDocumentController@index` — `app/Http/Controllers/ClientDocumentController.php:23` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned`
- `POST operations/clients/{client}/documents` — `operations.clients.documents.store` — `App\Http\Controllers\ClientDocumentController@store` — `app/Http/Controllers/ClientDocumentController.php:84` — middleware `web, auth, permission:clients.update`
- `DELETE operations/clients/{client}/documents/{document}` — `operations.clients.documents.destroy` — `App\Http\Controllers\ClientDocumentController@destroy` — `app/Http/Controllers/ClientDocumentController.php:261` — middleware `web, auth, permission:clients.update`
- `PUT operations/clients/{client}/documents/{document}` — `operations.clients.documents.update` — `App\Http\Controllers\ClientDocumentController@update` — `app/Http/Controllers/ClientDocumentController.php:172` — middleware `web, auth, permission:clients.update`
- `GET|HEAD operations/clients/{client}/documents/{document}/download` — `operations.clients.documents.download` — `App\Http\Controllers\ClientDocumentController@download` — `app/Http/Controllers/ClientDocumentController.php:224` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ClientDocumentController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/clients/documents.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
