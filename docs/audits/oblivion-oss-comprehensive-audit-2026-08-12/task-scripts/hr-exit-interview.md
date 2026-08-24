# HR-EXIT-INTERVIEW: Exit Interview

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.exit-interviews.view|hr.exit-interviews.manage`, `permission:hr.exit-interviews.manage`
- Owning module: Human resources
- Legacy family: `HR-EXIT-INTERVIEW`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/exit-interviews` (`hr.exit-interviews.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.exit-interviews.view|hr.exit-interviews.manage`, `permission:hr.exit-interviews.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.exit-interviews.view|hr.exit-interviews.manage`, `permission:hr.exit-interviews.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/exit-interviews` (`hr.exit-interviews.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/exit-interviews/{exitInterview}` (`hr.exit-interviews.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/ExitInterviewController.php:154-171`.
3. Use `GET|HEAD hr/exit-interviews/create` (`hr.exit-interviews.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/ExitInterviewController.php:96-102`.
4. Use `GET|HEAD hr/exit-interviews/trends` (`hr.exit-interviews.trends`, action `trends`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/ExitInterviewController.php:176-198`.
5. Invoke only the owning control for `POST hr/exit-interviews` (`hr.exit-interviews.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/ExitInterviewController.php:107-149`; `employee_profile_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1427` at `app/Http/Controllers/Hr/ExitInterviewController.php:45`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1428` at `app/Http/Controllers/Hr/ExitInterviewController.php:107`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1429` at `app/Http/Controllers/Hr/ExitInterviewController.php:154`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1430` at `app/Http/Controllers/Hr/ExitInterviewController.php:96`; it is not runtime-observed.
- **information presented** is applicable only to `trends` / `ROUTE-1431` at `app/Http/Controllers/Hr/ExitInterviewController.php:176`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/exit-interviews/index.tsx`, `resources/js/pages/hr/exit-interviews/show.tsx`, `resources/js/pages/hr/exit-interviews/trends.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1428` / `store`: fields `employee_profile_id`; success app/Http/Controllers/Hr/ExitInterviewController.php:145 `return redirect()->back()->with('success', 'Exit interview recorded.');`; app/Http/Controllers/Hr/ExitInterviewController.php:148 `return redirect()->route('hr.exit-interviews.index')->with('success', 'Exit interview recorded.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/ExitInterviewController.php:68 `return Inertia::render('hr/exit-interviews/index', [`; app/Http/Controllers/Hr/ExitInterviewController.php:145 `return redirect()->back()->with('success', 'Exit interview recorded.');`; app/Http/Controllers/Hr/ExitInterviewController.php:148 `return redirect()->route('hr.exit-interviews.index')->with('success', 'Exit interview recorded.');`; app/Http/Controllers/Hr/ExitInterviewController.php:165 `return Inertia::render('hr/exit-interviews/show', [`; app/Http/Controllers/Hr/ExitInterviewController.php:101 `return redirect()->route('hr.exit-interviews.index', ['new' => 1]);`; app/Http/Controllers/Hr/ExitInterviewController.php:191 `return Inertia::render('hr/exit-interviews/trends', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/exit-interviews` — `hr.exit-interviews.index` — `App\Http\Controllers\Hr\ExitInterviewController@index` — `app/Http/Controllers/Hr/ExitInterviewController.php:45` — middleware `web, auth, permission:hr.exit-interviews.view|hr.exit-interviews.manage`
- `POST hr/exit-interviews` — `hr.exit-interviews.store` — `App\Http\Controllers\Hr\ExitInterviewController@store` — `app/Http/Controllers/Hr/ExitInterviewController.php:107` — middleware `web, auth, permission:hr.exit-interviews.view|hr.exit-interviews.manage, permission:hr.exit-interviews.manage`
- `GET|HEAD hr/exit-interviews/{exitInterview}` — `hr.exit-interviews.show` — `App\Http\Controllers\Hr\ExitInterviewController@show` — `app/Http/Controllers/Hr/ExitInterviewController.php:154` — middleware `web, auth, permission:hr.exit-interviews.view|hr.exit-interviews.manage`
- `GET|HEAD hr/exit-interviews/create` — `hr.exit-interviews.create` — `App\Http\Controllers\Hr\ExitInterviewController@create` — `app/Http/Controllers/Hr/ExitInterviewController.php:96` — middleware `web, auth, permission:hr.exit-interviews.view|hr.exit-interviews.manage, permission:hr.exit-interviews.manage`
- `GET|HEAD hr/exit-interviews/trends` — `hr.exit-interviews.trends` — `App\Http\Controllers\Hr\ExitInterviewController@trends` — `app/Http/Controllers/Hr/ExitInterviewController.php:176` — middleware `web, auth, permission:hr.exit-interviews.view|hr.exit-interviews.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/ExitInterviewController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/exit-interviews/index.tsx`, `resources/js/pages/hr/exit-interviews/show.tsx`, `resources/js/pages/hr/exit-interviews/trends.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
