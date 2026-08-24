# CAP-HR-HR-DOCUMENT-GENERATION-PREVIEW: HR document generation and preview

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
2. Invoke only the owning control for `POST hr/documents/generate` (`hr.documents.generate`, action `generate`). Source category: **mutation outcome source gap (generate)**; controller `app/Http/Controllers/Hr/HrDocumentController.php:373-424`; `template_id`.
3. Invoke only the owning control for `POST hr/documents/preview` (`hr.documents.preview`, action `preview`). Source category: **mutation outcome source gap (preview)**; controller `app/Http/Controllers/Hr/HrDocumentController.php:429-467`; `template_id`.

## Source-applicable states and transitions

- **mutation outcome source gap (generate)** is applicable only to `generate` / `ROUTE-1405` at `app/Http/Controllers/Hr/HrDocumentController.php:373`; it is not runtime-observed.
- **mutation outcome source gap (preview)** is applicable only to `preview` / `ROUTE-1419` at `app/Http/Controllers/Hr/HrDocumentController.php:429`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1405` / `generate`: fields `template_id`; success app/Http/Controllers/Hr/HrDocumentController.php:423 `return redirect()->route('hr.documents.index')->with('success', 'Document generated from template.');`.
- `ROUTE-1419` / `preview`: fields `template_id`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/HrDocumentController.php:420 `$document->update(['title' => $data['title']]);`; responses app/Http/Controllers/Hr/HrDocumentController.php:402 `return redirect()->back()->with('error', 'This template requires approval before documents can be generated from it.');`; app/Http/Controllers/Hr/HrDocumentController.php:423 `return redirect()->route('hr.documents.index')->with('success', 'Document generated from template.');`; app/Http/Controllers/Hr/HrDocumentController.php:461 `return response()->json([`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/documents/generate` — `hr.documents.generate` — `App\Http\Controllers\Hr\HrDocumentController@generate` — `app/Http/Controllers/Hr/HrDocumentController.php:373` — middleware `web, auth, permission:hr.documents.view, permission:hr.documents.manage`
- `POST hr/documents/preview` — `hr.documents.preview` — `App\Http\Controllers\Hr\HrDocumentController@preview` — `app/Http/Controllers/Hr/HrDocumentController.php:429` — middleware `web, auth, permission:hr.documents.view, permission:hr.documents.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/HrDocumentController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
