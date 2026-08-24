# OPS-CARE-NOTE-TEMPLATE: Care Note Template

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:care_note_templates.viewAny`
- Owning module: Operations and rostering
- Legacy family: `OPS-CARE-NOTE-TEMPLATE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/note-templates` (`operations.note_templates.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:care_note_templates.viewAny`.
- Exact middleware atoms: `web`, `auth`, `permission:care_note_templates.viewAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/note-templates` (`operations.note_templates.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/note-templates/{template}/edit` (`operations.note_templates.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/CareNoteTemplateController.php:82-94`.
3. Use `GET|HEAD operations/note-templates/create` (`operations.note_templates.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/CareNoteTemplateController.php:50-56`.
4. Invoke only the owning control for `POST operations/note-templates` (`operations.note_templates.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/CareNoteTemplateController.php:58-80`; `name`.
5. Invoke only the owning control for `DELETE operations/note-templates/{template}` (`operations.note_templates.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Operations/CareNoteTemplateController.php:117-129`; no exact validation fields extracted.
6. Invoke only the owning control for `PUT operations/note-templates/{template}` (`operations.note_templates.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/CareNoteTemplateController.php:96-115`; `name`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2111` at `app/Http/Controllers/Operations/CareNoteTemplateController.php:11`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2112` at `app/Http/Controllers/Operations/CareNoteTemplateController.php:58`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2113` at `app/Http/Controllers/Operations/CareNoteTemplateController.php:117`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2114` at `app/Http/Controllers/Operations/CareNoteTemplateController.php:96`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-2115` at `app/Http/Controllers/Operations/CareNoteTemplateController.php:82`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2116` at `app/Http/Controllers/Operations/CareNoteTemplateController.php:50`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/note-templates/Create.tsx`, `resources/js/pages/operations/note-templates/Edit.tsx`, `resources/js/pages/operations/note-templates/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2111` / `index`: fields `q`.
- `ROUTE-2112` / `store`: fields `name`; success app/Http/Controllers/Operations/CareNoteTemplateController.php:79 `return redirect()->back()->with('success', 'Note template created.');`.
- `ROUTE-2113` / `destroy`: success app/Http/Controllers/Operations/CareNoteTemplateController.php:128 `return redirect()->back()->with('success', 'Note template deleted.');`.
- `ROUTE-2114` / `update`: fields `name`; success app/Http/Controllers/Operations/CareNoteTemplateController.php:114 `return redirect()->back()->with('success', 'Note template updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/CareNoteTemplateController.php:70 `CareNoteTemplate::create([`; app/Http/Controllers/Operations/CareNoteTemplateController.php:126 `$template->delete();`; app/Http/Controllers/Operations/CareNoteTemplateController.php:112 `$template->update($data);`; responses app/Http/Controllers/Operations/CareNoteTemplateController.php:41 `return inertia('operations/note-templates/Index', [`; app/Http/Controllers/Operations/CareNoteTemplateController.php:79 `return redirect()->back()->with('success', 'Note template created.');`; app/Http/Controllers/Operations/CareNoteTemplateController.php:128 `return redirect()->back()->with('success', 'Note template deleted.');`; app/Http/Controllers/Operations/CareNoteTemplateController.php:114 `return redirect()->back()->with('success', 'Note template updated.');`; app/Http/Controllers/Operations/CareNoteTemplateController.php:91 `return inertia('operations/note-templates/Edit', [`; app/Http/Controllers/Operations/CareNoteTemplateController.php:55 `return inertia('operations/note-templates/Create');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/note-templates` — `operations.note_templates.index` — `App\Http\Controllers\Operations\CareNoteTemplateController@index` — `app/Http/Controllers/Operations/CareNoteTemplateController.php:11` — middleware `web, auth, permission:care_note_templates.viewAny`
- `POST operations/note-templates` — `operations.note_templates.store` — `App\Http\Controllers\Operations\CareNoteTemplateController@store` — `app/Http/Controllers/Operations/CareNoteTemplateController.php:58` — middleware `web, auth, permission:care_note_templates.viewAny`
- `DELETE operations/note-templates/{template}` — `operations.note_templates.destroy` — `App\Http\Controllers\Operations\CareNoteTemplateController@destroy` — `app/Http/Controllers/Operations/CareNoteTemplateController.php:117` — middleware `web, auth, permission:care_note_templates.viewAny`
- `PUT operations/note-templates/{template}` — `operations.note_templates.update` — `App\Http\Controllers\Operations\CareNoteTemplateController@update` — `app/Http/Controllers/Operations/CareNoteTemplateController.php:96` — middleware `web, auth, permission:care_note_templates.viewAny`
- `GET|HEAD operations/note-templates/{template}/edit` — `operations.note_templates.edit` — `App\Http\Controllers\Operations\CareNoteTemplateController@edit` — `app/Http/Controllers/Operations/CareNoteTemplateController.php:82` — middleware `web, auth, permission:care_note_templates.viewAny`
- `GET|HEAD operations/note-templates/create` — `operations.note_templates.create` — `App\Http\Controllers\Operations\CareNoteTemplateController@create` — `app/Http/Controllers/Operations/CareNoteTemplateController.php:50` — middleware `web, auth, permission:care_note_templates.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/CareNoteTemplateController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/note-templates/Create.tsx`, `resources/js/pages/operations/note-templates/Edit.tsx`, `resources/js/pages/operations/note-templates/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
