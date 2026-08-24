# CAP-HR-HR-DOCUMENT-PROFILE-DOCUMENTS: Employee profile document management

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.employees.viewAny`, `permission:hr.employees.manage`
- Owning module: Human resources
- Legacy family: `HR-HR-DOCUMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/people/{profile}/documents` (`hr.people.documents`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.employees.viewAny`, `permission:hr.employees.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.employees.viewAny`, `permission:hr.employees.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/people/{profile}/documents` (`hr.people.documents`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/people/{profile}/documents/{document}/download` (`hr.people.documents.download`, action `download`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/HrDocumentController.php:472-499`.
3. Invoke only the owning control for `POST hr/people/{profile}/documents` (`hr.people.documents.store`, action `storeForProfile`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/HrDocumentController.php:1034-1073`; `file`.
4. Invoke only the owning control for `DELETE hr/people/{profile}/documents/{document}` (`hr.people.documents.destroy`, action `destroyForProfile`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/HrDocumentController.php:1094-1107`; no exact validation fields extracted.
5. Invoke only the owning control for `PUT hr/people/{profile}/documents/{document}` (`hr.people.documents.update`, action `updateForProfile`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/HrDocumentController.php:1075-1092`; `title`.

## Source-applicable states and transitions

- **information presented** is applicable only to `profileDocuments` / `ROUTE-1603` at `app/Http/Controllers/Hr/HrDocumentController.php:995`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeForProfile` / `ROUTE-1604` at `app/Http/Controllers/Hr/HrDocumentController.php:1034`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyForProfile` / `ROUTE-1605` at `app/Http/Controllers/Hr/HrDocumentController.php:1094`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateForProfile` / `ROUTE-1606` at `app/Http/Controllers/Hr/HrDocumentController.php:1075`; it is not runtime-observed.
- **file/report delivered** is applicable only to `download` / `ROUTE-1607` at `app/Http/Controllers/Hr/HrDocumentController.php:472`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/employees/documents.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1604` / `storeForProfile`: fields `file`; success app/Http/Controllers/Hr/HrDocumentController.php:1072 `return redirect()->back()->with('success', 'Document uploaded.');`.
- `ROUTE-1605` / `destroyForProfile`: success app/Http/Controllers/Hr/HrDocumentController.php:1106 `return redirect()->back()->with('success', 'Document deleted.');`.
- `ROUTE-1606` / `updateForProfile`: fields `title`; success app/Http/Controllers/Hr/HrDocumentController.php:1091 `return redirect()->back()->with('success', 'Document updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/HrDocumentController.php:1054 `HrDocument::create([`; app/Http/Controllers/Hr/HrDocumentController.php:1101 `Storage::disk($document->storage_disk ?? 'private')->delete($document->storage_path);`; app/Http/Controllers/Hr/HrDocumentController.php:1104 `$document->delete();`; app/Http/Controllers/Hr/HrDocumentController.php:1089 `$document->update($validated);`; responses app/Http/Controllers/Hr/HrDocumentController.php:1021 `return Inertia::render('hr/employees/documents', [`; app/Http/Controllers/Hr/HrDocumentController.php:1072 `return redirect()->back()->with('success', 'Document uploaded.');`; app/Http/Controllers/Hr/HrDocumentController.php:1106 `return redirect()->back()->with('success', 'Document deleted.');`; app/Http/Controllers/Hr/HrDocumentController.php:1091 `return redirect()->back()->with('success', 'Document updated.');`; app/Http/Controllers/Hr/HrDocumentController.php:498 `return Storage::disk($document->storage_disk)->download($document->storage_path, $filename);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/people/{profile}/documents` — `hr.people.documents` — `App\Http\Controllers\Hr\HrDocumentController@profileDocuments` — `app/Http/Controllers/Hr/HrDocumentController.php:995` — middleware `web, auth, permission:hr.employees.viewAny`
- `POST hr/people/{profile}/documents` — `hr.people.documents.store` — `App\Http\Controllers\Hr\HrDocumentController@storeForProfile` — `app/Http/Controllers/Hr/HrDocumentController.php:1034` — middleware `web, auth, permission:hr.employees.viewAny, permission:hr.employees.manage`
- `DELETE hr/people/{profile}/documents/{document}` — `hr.people.documents.destroy` — `App\Http\Controllers\Hr\HrDocumentController@destroyForProfile` — `app/Http/Controllers/Hr/HrDocumentController.php:1094` — middleware `web, auth, permission:hr.employees.viewAny, permission:hr.employees.manage`
- `PUT hr/people/{profile}/documents/{document}` — `hr.people.documents.update` — `App\Http\Controllers\Hr\HrDocumentController@updateForProfile` — `app/Http/Controllers/Hr/HrDocumentController.php:1075` — middleware `web, auth, permission:hr.employees.viewAny, permission:hr.employees.manage`
- `GET|HEAD hr/people/{profile}/documents/{document}/download` — `hr.people.documents.download` — `App\Http\Controllers\Hr\HrDocumentController@download` — `app/Http/Controllers/Hr/HrDocumentController.php:472` — middleware `web, auth, permission:hr.employees.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/HrDocumentController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/employees/documents.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
