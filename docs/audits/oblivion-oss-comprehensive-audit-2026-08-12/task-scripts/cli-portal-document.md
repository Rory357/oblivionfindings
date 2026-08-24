# CLI-PORTAL-DOCUMENT: Portal Document

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Clients and supported people
- Legacy family: `CLI-PORTAL-DOCUMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `portal/clients/{client}/documents` (`portal.clients.documents`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD portal/clients/{client}/documents` (`portal.clients.documents`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST portal/clients/{client}/documents` (`portal.clients.documents.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Portal/PortalDocumentController.php:78-127`; `file`, `title`, `notes`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2252` at `app/Http/Controllers/Portal/PortalDocumentController.php:15`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2253` at `app/Http/Controllers/Portal/PortalDocumentController.php:78`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/portal/documents.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2253` / `store`: fields `file`, `title`, `notes`; success app/Http/Controllers/Portal/PortalDocumentController.php:126 `return redirect()->back()->with('success', 'Document uploaded successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Portal/PortalDocumentController.php:93 `$doc = ClientDocument::create([`; responses app/Http/Controllers/Portal/PortalDocumentController.php:66 `return inertia('portal/documents', [`; app/Http/Controllers/Portal/PortalDocumentController.php:126 `return redirect()->back()->with('success', 'Document uploaded successfully.');`; audit calls app/Http/Controllers/Portal/PortalDocumentController.php:124 `AuditLogger::log('portal.document.upload', $client);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD portal/clients/{client}/documents` — `portal.clients.documents` — `App\Http\Controllers\Portal\PortalDocumentController@index` — `app/Http/Controllers/Portal/PortalDocumentController.php:15` — middleware `web, auth`
- `POST portal/clients/{client}/documents` — `portal.clients.documents.store` — `App\Http\Controllers\Portal\PortalDocumentController@store` — `app/Http/Controllers/Portal/PortalDocumentController.php:78` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Portal/PortalDocumentController.php`.
- Exact render/action page relationships: `resources/js/pages/portal/documents.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
