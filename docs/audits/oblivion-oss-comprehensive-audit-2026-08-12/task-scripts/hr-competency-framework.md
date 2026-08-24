# HR-COMPETENCY-FRAMEWORK: Competency Framework

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:competency.viewAny`, `permission:competency.manage`
- Owning module: Human resources
- Legacy family: `HR-COMPETENCY-FRAMEWORK`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `competency/frameworks` (`competency.frameworks.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:competency.viewAny`, `permission:competency.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:competency.viewAny`, `permission:competency.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD competency/frameworks` (`competency.frameworks.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD competency/frameworks/{framework}` (`competency.frameworks.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Training/CompetencyFrameworkController.php:12-12`.
3. Use `GET|HEAD competency/frameworks/{framework}/edit` (`competency.frameworks.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Training/CompetencyFrameworkController.php:15-15`.
4. Use `GET|HEAD competency/frameworks/create` (`competency.frameworks.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Training/CompetencyFrameworkController.php:13-13`.
5. Invoke only the owning control for `POST competency/frameworks` (`competency.frameworks.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Training/CompetencyFrameworkController.php:14-14`; no exact validation fields extracted.
6. Invoke only the owning control for `PUT competency/frameworks/{framework}` (`competency.frameworks.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Training/CompetencyFrameworkController.php:16-16`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0202` at `app/Http/Controllers/Training/CompetencyFrameworkController.php:11`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0203` at `app/Http/Controllers/Training/CompetencyFrameworkController.php:14`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0204` at `app/Http/Controllers/Training/CompetencyFrameworkController.php:12`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0205` at `app/Http/Controllers/Training/CompetencyFrameworkController.php:16`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-0206` at `app/Http/Controllers/Training/CompetencyFrameworkController.php:15`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0207` at `app/Http/Controllers/Training/CompetencyFrameworkController.php:13`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Training/CompetencyFrameworkController.php:11 `public function index(): RedirectResponse { return redirect()->route('hr.competencies.index'); }`; app/Http/Controllers/Training/CompetencyFrameworkController.php:14 `public function store(Request $request) { return redirect()->back(); }`; app/Http/Controllers/Training/CompetencyFrameworkController.php:12 `public function show($framework): RedirectResponse { return redirect()->route('hr.competencies.index'); }`; app/Http/Controllers/Training/CompetencyFrameworkController.php:16 `public function update(Request $request, $framework) { return redirect()->back(); }`; app/Http/Controllers/Training/CompetencyFrameworkController.php:15 `public function edit($framework): RedirectResponse { return redirect()->route('hr.competencies.index'); }`; app/Http/Controllers/Training/CompetencyFrameworkController.php:13 `public function create(): RedirectResponse { return redirect()->route('hr.competencies.index'); }`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD competency/frameworks` — `competency.frameworks.index` — `App\Http\Controllers\Training\CompetencyFrameworkController@index` — `app/Http/Controllers/Training/CompetencyFrameworkController.php:11` — middleware `web, auth, permission:competency.viewAny`
- `POST competency/frameworks` — `competency.frameworks.store` — `App\Http\Controllers\Training\CompetencyFrameworkController@store` — `app/Http/Controllers/Training/CompetencyFrameworkController.php:14` — middleware `web, auth, permission:competency.manage`
- `GET|HEAD competency/frameworks/{framework}` — `competency.frameworks.show` — `App\Http\Controllers\Training\CompetencyFrameworkController@show` — `app/Http/Controllers/Training/CompetencyFrameworkController.php:12` — middleware `web, auth, permission:competency.viewAny`
- `PUT competency/frameworks/{framework}` — `competency.frameworks.update` — `App\Http\Controllers\Training\CompetencyFrameworkController@update` — `app/Http/Controllers/Training/CompetencyFrameworkController.php:16` — middleware `web, auth, permission:competency.manage`
- `GET|HEAD competency/frameworks/{framework}/edit` — `competency.frameworks.edit` — `App\Http\Controllers\Training\CompetencyFrameworkController@edit` — `app/Http/Controllers/Training/CompetencyFrameworkController.php:15` — middleware `web, auth, permission:competency.manage`
- `GET|HEAD competency/frameworks/create` — `competency.frameworks.create` — `App\Http\Controllers\Training\CompetencyFrameworkController@create` — `app/Http/Controllers/Training/CompetencyFrameworkController.php:13` — middleware `web, auth, permission:competency.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Training/CompetencyFrameworkController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
