# HR-SKILLS: Skills

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`
- Owning module: Human resources
- Legacy family: `HR-SKILLS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/performance/skills` (`hr.performance.skills.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/performance/skills` (`hr.performance.skills.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/performance/skills/matrix` (`hr.performance.skills.matrix`, action `matrix`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/SkillsController.php:67-84`.
3. Invoke only the owning control for `POST hr/performance/skills` (`hr.performance.skills.store`, action `storeSkill`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/SkillsController.php:90-110`; `name`.
4. Invoke only the owning control for `POST hr/performance/skills/assess` (`hr.performance.skills.assess`, action `assessEmployee`). Source category: **mutation outcome source gap (assessEmployee)**; controller `app/Http/Controllers/Hr/SkillsController.php:116-141`; `employee_profile_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1650` at `app/Http/Controllers/Hr/SkillsController.php:22`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeSkill` / `ROUTE-1651` at `app/Http/Controllers/Hr/SkillsController.php:90`; it is not runtime-observed.
- **mutation outcome source gap (assessEmployee)** is applicable only to `assessEmployee` / `ROUTE-1652` at `app/Http/Controllers/Hr/SkillsController.php:116`; it is not runtime-observed.
- **information presented** is applicable only to `matrix` / `ROUTE-1653` at `app/Http/Controllers/Hr/SkillsController.php:67`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/performance/skills/index.tsx`, `resources/js/pages/hr/performance/skills/matrix.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1651` / `storeSkill`: fields `name`; success app/Http/Controllers/Hr/SkillsController.php:109 `return redirect()->back()->with('success', 'Skill created.');`.
- `ROUTE-1652` / `assessEmployee`: fields `employee_profile_id`; success app/Http/Controllers/Hr/SkillsController.php:140 `return redirect()->back()->with('success', 'Skill assessment recorded.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/SkillsController.php:101 `HrSkill::create([`; responses app/Http/Controllers/Hr/SkillsController.php:48 `return Inertia::render('hr/performance/skills/index', [`; app/Http/Controllers/Hr/SkillsController.php:109 `return redirect()->back()->with('success', 'Skill created.');`; app/Http/Controllers/Hr/SkillsController.php:140 `return redirect()->back()->with('success', 'Skill assessment recorded.');`; app/Http/Controllers/Hr/SkillsController.php:76 `return Inertia::render('hr/performance/skills/matrix', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/performance/skills` — `hr.performance.skills.index` — `App\Http\Controllers\Hr\SkillsController@index` — `app/Http/Controllers/Hr/SkillsController.php:22` — middleware `web, auth, permission:hr.performance.view`
- `POST hr/performance/skills` — `hr.performance.skills.store` — `App\Http\Controllers\Hr\SkillsController@storeSkill` — `app/Http/Controllers/Hr/SkillsController.php:90` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `POST hr/performance/skills/assess` — `hr.performance.skills.assess` — `App\Http\Controllers\Hr\SkillsController@assessEmployee` — `app/Http/Controllers/Hr/SkillsController.php:116` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `GET|HEAD hr/performance/skills/matrix` — `hr.performance.skills.matrix` — `App\Http\Controllers\Hr\SkillsController@matrix` — `app/Http/Controllers/Hr/SkillsController.php:67` — middleware `web, auth, permission:hr.performance.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/SkillsController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/performance/skills/index.tsx`, `resources/js/pages/hr/performance/skills/matrix.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
