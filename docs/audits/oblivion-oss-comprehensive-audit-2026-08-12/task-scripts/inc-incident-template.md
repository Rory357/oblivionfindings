# INC-INCIDENT-TEMPLATE: Incident Template

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:incidents.templates.manage`
- Owning module: Incidents and safeguarding
- Legacy family: `INC-INCIDENT-TEMPLATE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `incidents/templates` (`incidents.templates.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:incidents.templates.manage`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:incidents.templates.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD incidents/templates` (`incidents.templates.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD incidents/templates/{template}` (`incidents.templates.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/IncidentTemplateController.php:52-59`.
3. Use `GET|HEAD incidents/templates/create` (`incidents.templates.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/IncidentTemplateController.php:24-31`.
4. Invoke only the owning control for `POST incidents/templates` (`incidents.templates.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/IncidentTemplateController.php:33-50`; `name`.
5. Invoke only the owning control for `PUT incidents/templates/{template}` (`incidents.templates.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/IncidentTemplateController.php:61-78`; `name`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1855` at `app/Http/Controllers/IncidentTemplateController.php:10`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1856` at `app/Http/Controllers/IncidentTemplateController.php:33`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-1857` at `app/Http/Controllers/IncidentTemplateController.php:52`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1858` at `app/Http/Controllers/IncidentTemplateController.php:61`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1859` at `app/Http/Controllers/IncidentTemplateController.php:24`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/incidents/templates/edit.tsx`, `resources/js/pages/incidents/templates/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1856` / `store`: fields `name`; success app/Http/Controllers/IncidentTemplateController.php:49 `return redirect()->route('incidents.templates.edit', $template)->with('success', 'Template created.');`.
- `ROUTE-1858` / `update`: fields `name`; success app/Http/Controllers/IncidentTemplateController.php:77 `return back()->with('success', 'Template updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/IncidentTemplateController.php:47 `$template = IncidentTemplate::create($data);`; app/Http/Controllers/IncidentTemplateController.php:75 `$template->update($data);`; responses app/Http/Controllers/IncidentTemplateController.php:19 `return inertia('incidents/templates/index', [`; app/Http/Controllers/IncidentTemplateController.php:49 `return redirect()->route('incidents.templates.edit', $template)->with('success', 'Template created.');`; app/Http/Controllers/IncidentTemplateController.php:56 `return inertia('incidents/templates/edit', [`; app/Http/Controllers/IncidentTemplateController.php:77 `return back()->with('success', 'Template updated.');`; app/Http/Controllers/IncidentTemplateController.php:28 `return inertia('incidents/templates/edit', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD incidents/templates` — `incidents.templates.index` — `App\Http\Controllers\IncidentTemplateController@index` — `app/Http/Controllers/IncidentTemplateController.php:10` — middleware `web, auth, verified, permission:incidents.templates.manage`
- `POST incidents/templates` — `incidents.templates.store` — `App\Http\Controllers\IncidentTemplateController@store` — `app/Http/Controllers/IncidentTemplateController.php:33` — middleware `web, auth, verified, permission:incidents.templates.manage`
- `GET|HEAD incidents/templates/{template}` — `incidents.templates.edit` — `App\Http\Controllers\IncidentTemplateController@edit` — `app/Http/Controllers/IncidentTemplateController.php:52` — middleware `web, auth, verified, permission:incidents.templates.manage`
- `PUT incidents/templates/{template}` — `incidents.templates.update` — `App\Http\Controllers\IncidentTemplateController@update` — `app/Http/Controllers/IncidentTemplateController.php:61` — middleware `web, auth, verified, permission:incidents.templates.manage`
- `GET|HEAD incidents/templates/create` — `incidents.templates.create` — `App\Http\Controllers\IncidentTemplateController@create` — `app/Http/Controllers/IncidentTemplateController.php:24` — middleware `web, auth, verified, permission:incidents.templates.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/IncidentTemplateController.php`.
- Exact render/action page relationships: `resources/js/pages/incidents/templates/edit.tsx`, `resources/js/pages/incidents/templates/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
