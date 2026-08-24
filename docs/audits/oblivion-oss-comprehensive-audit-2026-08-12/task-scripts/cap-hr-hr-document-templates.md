# CAP-HR-HR-DOCUMENT-TEMPLATES: HR document templates

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.documents.view`, `permission:hr.documents.manage`
- Owning module: Human resources
- Legacy family: `HR-HR-DOCUMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/documents/templates` (`hr.documents.templates`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.documents.view`, `permission:hr.documents.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.documents.view`, `permission:hr.documents.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/documents/templates` (`hr.documents.templates`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/documents/templates/{template}/edit` (`hr.documents.templates.edit`, action `editTemplate`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/HrDocumentController.php:868-890`.
3. Use `GET|HEAD hr/documents/templates/create` (`hr.documents.templates.create`, action `createTemplate`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/HrDocumentController.php:854-863`.
4. Invoke only the owning control for `POST hr/documents/templates` (`hr.documents.templates.store`, action `storeTemplate`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/HrDocumentController.php:895-930`; `name`.
5. Invoke only the owning control for `PUT hr/documents/templates/{template}` (`hr.documents.templates.update`, action `updateTemplate`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/HrDocumentController.php:935-970`; `name`.
6. Invoke only the owning control for `POST hr/documents/templates/{template}/toggle-active` (`hr.documents.templates.toggleActive`, action `toggleTemplateActive`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/HrDocumentController.php:975-989`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `templates` / `ROUTE-1420` at `app/Http/Controllers/Hr/HrDocumentController.php:810`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeTemplate` / `ROUTE-1421` at `app/Http/Controllers/Hr/HrDocumentController.php:895`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateTemplate` / `ROUTE-1422` at `app/Http/Controllers/Hr/HrDocumentController.php:935`; it is not runtime-observed.
- **information presented** is applicable only to `editTemplate` / `ROUTE-1423` at `app/Http/Controllers/Hr/HrDocumentController.php:868`; it is not runtime-observed.
- **updated/revised** is applicable only to `toggleTemplateActive` / `ROUTE-1424` at `app/Http/Controllers/Hr/HrDocumentController.php:975`; it is not runtime-observed.
- **information presented** is applicable only to `createTemplate` / `ROUTE-1425` at `app/Http/Controllers/Hr/HrDocumentController.php:854`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/documents/create-template.tsx`, `resources/js/pages/hr/documents/edit-template.tsx`, `resources/js/pages/hr/documents/templates.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1421` / `storeTemplate`: fields `name`; success app/Http/Controllers/Hr/HrDocumentController.php:929 `return redirect()->route('hr.documents.templates')->with('success', 'Document template created.');`.
- `ROUTE-1422` / `updateTemplate`: fields `name`; success app/Http/Controllers/Hr/HrDocumentController.php:969 `return redirect()->route('hr.documents.templates')->with('success', 'Document template updated.');`.
- `ROUTE-1424` / `toggleTemplateActive`: success app/Http/Controllers/Hr/HrDocumentController.php:988 `return redirect()->back()->with('success', 'Template status updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/HrDocumentController.php:916 `HrDocumentTemplate::create([`; app/Http/Controllers/Hr/HrDocumentController.php:967 `$template->update($data);`; app/Http/Controllers/Hr/HrDocumentController.php:983 `$template->update([`; responses app/Http/Controllers/Hr/HrDocumentController.php:838 `return Inertia::render('hr/documents/templates', [`; app/Http/Controllers/Hr/HrDocumentController.php:929 `return redirect()->route('hr.documents.templates')->with('success', 'Document template created.');`; app/Http/Controllers/Hr/HrDocumentController.php:969 `return redirect()->route('hr.documents.templates')->with('success', 'Document template updated.');`; app/Http/Controllers/Hr/HrDocumentController.php:876 `return Inertia::render('hr/documents/edit-template', [`; app/Http/Controllers/Hr/HrDocumentController.php:988 `return redirect()->back()->with('success', 'Template status updated.');`; app/Http/Controllers/Hr/HrDocumentController.php:859 `return Inertia::render('hr/documents/create-template', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/documents/templates` — `hr.documents.templates` — `App\Http\Controllers\Hr\HrDocumentController@templates` — `app/Http/Controllers/Hr/HrDocumentController.php:810` — middleware `web, auth, permission:hr.documents.view, permission:hr.documents.manage`
- `POST hr/documents/templates` — `hr.documents.templates.store` — `App\Http\Controllers\Hr\HrDocumentController@storeTemplate` — `app/Http/Controllers/Hr/HrDocumentController.php:895` — middleware `web, auth, permission:hr.documents.view, permission:hr.documents.manage`
- `PUT hr/documents/templates/{template}` — `hr.documents.templates.update` — `App\Http\Controllers\Hr\HrDocumentController@updateTemplate` — `app/Http/Controllers/Hr/HrDocumentController.php:935` — middleware `web, auth, permission:hr.documents.view, permission:hr.documents.manage`
- `GET|HEAD hr/documents/templates/{template}/edit` — `hr.documents.templates.edit` — `App\Http\Controllers\Hr\HrDocumentController@editTemplate` — `app/Http/Controllers/Hr/HrDocumentController.php:868` — middleware `web, auth, permission:hr.documents.view, permission:hr.documents.manage`
- `POST hr/documents/templates/{template}/toggle-active` — `hr.documents.templates.toggleActive` — `App\Http\Controllers\Hr\HrDocumentController@toggleTemplateActive` — `app/Http/Controllers/Hr/HrDocumentController.php:975` — middleware `web, auth, permission:hr.documents.view, permission:hr.documents.manage`
- `GET|HEAD hr/documents/templates/create` — `hr.documents.templates.create` — `App\Http\Controllers\Hr\HrDocumentController@createTemplate` — `app/Http/Controllers/Hr/HrDocumentController.php:854` — middleware `web, auth, permission:hr.documents.view, permission:hr.documents.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/HrDocumentController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/documents/create-template.tsx`, `resources/js/pages/hr/documents/edit-template.tsx`, `resources/js/pages/hr/documents/templates.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
