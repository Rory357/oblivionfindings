# RESP-RESPITE-PROCEDURE-TEMPLATE: Respite Procedure Template

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:respite.procedures.manage`
- Owning module: Respite
- Legacy family: `RESP-RESPITE-PROCEDURE-TEMPLATE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `respite/procedures` (`respite.procedures.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:respite.procedures.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:respite.procedures.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD respite/procedures` (`respite.procedures.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD respite/procedures/{template}` (`respite.procedures.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:60-65`.
3. Use `GET|HEAD respite/procedures/create` (`respite.procedures.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:27-30`.
4. Invoke only the owning control for `POST respite/procedures` (`respite.procedures.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:32-58`; `name`, `version`, `trigger_event`, `description`, `steps_json`, `required_roles`, `active`.
5. Invoke only the owning control for `PUT respite/procedures/{template}` (`respite.procedures.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:67-86`; `description`, `steps_json`, `required_roles`, `active`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2412` at `app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:15`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2413` at `app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:32`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2414` at `app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:60`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2415` at `app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:67`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2416` at `app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:27`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/respite/procedures/create.tsx`, `resources/js/pages/respite/procedures/index.tsx`, `resources/js/pages/respite/procedures/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2413` / `store`: fields `name`, `version`, `trigger_event`, `description`, `steps_json`, `required_roles`, `active`; success app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:57 `->with('success', 'Procedure template created.');`.
- `ROUTE-2415` / `update`: fields `description`, `steps_json`, `required_roles`, `active`; success app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:85 `return back()->with('success', 'Procedure template updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:47 `$template = ProcedureTemplate::create($validated);`; app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:77 `$template->update($validated);`; responses app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:22 `return Inertia::render('respite/procedures/index', [`; app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:55 `return redirect()`; app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:62 `return Inertia::render('respite/procedures/show', [`; app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:85 `return back()->with('success', 'Procedure template updated.');`; app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:29 `return Inertia::render('respite/procedures/create');`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:49 `event(new RespiteEvent('respite.procedure_template.created', [`; app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:79 `event(new RespiteEvent('respite.procedure_template.updated', [`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD respite/procedures` — `respite.procedures.index` — `App\Http\Controllers\Respite\RespiteProcedureTemplateController@index` — `app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:15` — middleware `web, auth, permission:respite.procedures.manage`
- `POST respite/procedures` — `respite.procedures.store` — `App\Http\Controllers\Respite\RespiteProcedureTemplateController@store` — `app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:32` — middleware `web, auth, permission:respite.procedures.manage`
- `GET|HEAD respite/procedures/{template}` — `respite.procedures.show` — `App\Http\Controllers\Respite\RespiteProcedureTemplateController@show` — `app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:60` — middleware `web, auth, permission:respite.procedures.manage`
- `PUT respite/procedures/{template}` — `respite.procedures.update` — `App\Http\Controllers\Respite\RespiteProcedureTemplateController@update` — `app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:67` — middleware `web, auth, permission:respite.procedures.manage`
- `GET|HEAD respite/procedures/create` — `respite.procedures.create` — `App\Http\Controllers\Respite\RespiteProcedureTemplateController@create` — `app/Http/Controllers/Respite/RespiteProcedureTemplateController.php:27` — middleware `web, auth, permission:respite.procedures.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Respite/RespiteProcedureTemplateController.php`.
- Exact render/action page relationships: `resources/js/pages/respite/procedures/create.tsx`, `resources/js/pages/respite/procedures/index.tsx`, `resources/js/pages/respite/procedures/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
