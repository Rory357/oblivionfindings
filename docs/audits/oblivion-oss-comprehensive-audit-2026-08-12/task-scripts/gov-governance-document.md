# GOV-GOVERNANCE-DOCUMENT: Governance Document

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.documents.view`, `permission:governance.documents.manage`
- Owning module: Governance
- Legacy family: `GOV-GOVERNANCE-DOCUMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/documents` (`governance.documents.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.documents.view`, `permission:governance.documents.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.documents.view`, `permission:governance.documents.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/documents` (`governance.documents.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD governance/documents/{document}` (`governance.documents.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:75-100`.
3. Use `GET|HEAD governance/documents/{document}/download` (`governance.documents.download`, action `download`) only from its authorised surface; inspect the returned file/report. Source: `app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:102-110`.
4. Invoke only the owning control for `POST governance/documents` (`governance.documents.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:46-73`; `title`, `category`, `description`, `file`.
5. Invoke only the owning control for `DELETE governance/documents/{document}` (`governance.documents.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:112-119`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0915` at `app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:12`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0916` at `app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:46`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0917` at `app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:112`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0918` at `app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:75`; it is not runtime-observed.
- **file/report delivered** is applicable only to `download` / `ROUTE-0919` at `app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:102`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Governance/Documents/Index.tsx`, `resources/js/pages/Governance/Documents/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0916` / `store`: fields `title`, `category`, `description`, `file`; success app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:72 `return redirect()->back()->with('success', 'Document uploaded.');`.
- `ROUTE-0917` / `destroy`: success app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:118 `return redirect()->back()->with('success', 'Document removed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:59 `GovernanceDocument::create([`; app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:116 `$document->delete();`; responses app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:32 `return Inertia::render('Governance/Documents/Index', [`; app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:72 `return redirect()->back()->with('success', 'Document uploaded.');`; app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:118 `return redirect()->back()->with('success', 'Document removed.');`; app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:81 `return Inertia::render('Governance/Documents/Show', [`; app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:109 `return response()->download($path, basename($document->file_path));`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD governance/documents` — `governance.documents.index` — `App\Domain\Governance\Http\Controllers\GovernanceDocumentController@index` — `app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:12` — middleware `web, auth, permission:governance.documents.view`
- `POST governance/documents` — `governance.documents.store` — `App\Domain\Governance\Http\Controllers\GovernanceDocumentController@store` — `app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:46` — middleware `web, auth, permission:governance.documents.view, permission:governance.documents.manage`
- `DELETE governance/documents/{document}` — `governance.documents.destroy` — `App\Domain\Governance\Http\Controllers\GovernanceDocumentController@destroy` — `app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:112` — middleware `web, auth, permission:governance.documents.view, permission:governance.documents.manage`
- `GET|HEAD governance/documents/{document}` — `governance.documents.show` — `App\Domain\Governance\Http\Controllers\GovernanceDocumentController@show` — `app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:75` — middleware `web, auth, permission:governance.documents.view`
- `GET|HEAD governance/documents/{document}/download` — `governance.documents.download` — `App\Domain\Governance\Http\Controllers\GovernanceDocumentController@download` — `app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:102` — middleware `web, auth, permission:governance.documents.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php`.
- Exact render/action page relationships: `resources/js/pages/Governance/Documents/Index.tsx`, `resources/js/pages/Governance/Documents/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
