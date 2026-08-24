# HR-OFFBOARDING: Offboarding

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.onboarding.view`, `permission:hr.onboarding.manage`
- Owning module: Human resources
- Legacy family: `HR-OFFBOARDING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/offboarding` (`hr.offboarding.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.onboarding.view`, `permission:hr.onboarding.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.onboarding.view`, `permission:hr.onboarding.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/offboarding` (`hr.offboarding.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/offboarding/{checklist}` (`hr.offboarding.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/OffboardingController.php:176-201`.
3. Use `GET|HEAD hr/offboarding/create` (`hr.offboarding.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/OffboardingController.php:207-213`.
4. Invoke only the owning control for `POST hr/offboarding` (`hr.offboarding.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/OffboardingController.php:215-263`; `employee_profile_id`.
5. Invoke only the owning control for `POST hr/offboarding/tasks/{task}/complete` (`hr.offboarding.tasks.complete`, action `completeTask`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Hr/OffboardingController.php:265-292`; `evidence_path`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1549` at `app/Http/Controllers/Hr/OffboardingController.php:26`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1550` at `app/Http/Controllers/Hr/OffboardingController.php:215`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1551` at `app/Http/Controllers/Hr/OffboardingController.php:176`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1552` at `app/Http/Controllers/Hr/OffboardingController.php:207`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `completeTask` / `ROUTE-1553` at `app/Http/Controllers/Hr/OffboardingController.php:265`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/offboarding/index.tsx`, `resources/js/pages/hr/offboarding/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1550` / `store`: fields `employee_profile_id`; success app/Http/Controllers/Hr/OffboardingController.php:262 `->with('success', "Offboarding checklist created with {$checklist->tasks->count()} tasks.");`.
- `ROUTE-1553` / `completeTask`: fields `evidence_path`; success app/Http/Controllers/Hr/OffboardingController.php:291 `return redirect()->back()->with('success', "Task '{$task->title}' completed.");`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/OffboardingController.php:74 `return Inertia::render('hr/offboarding/index', [`; app/Http/Controllers/Hr/OffboardingController.php:239 `return redirect()->back()->with('error', 'An active offboarding checklist already exists for this employee.');`; app/Http/Controllers/Hr/OffboardingController.php:261 `return redirect()->route('hr.offboarding.show', $checklist)`; app/Http/Controllers/Hr/OffboardingController.php:192 `return Inertia::render('hr/offboarding/show', [`; app/Http/Controllers/Hr/OffboardingController.php:212 `return redirect()->route('hr.offboarding.index', ['new' => 1]);`; app/Http/Controllers/Hr/OffboardingController.php:282 `return redirect()->back()->with('error', 'This task requires sign-off. Please specify the sign-off user.');`; app/Http/Controllers/Hr/OffboardingController.php:288 `return redirect()->back()->with('error', $exception->getMessage());`; app/Http/Controllers/Hr/OffboardingController.php:291 `return redirect()->back()->with('success', "Task '{$task->title}' completed.");`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/offboarding` — `hr.offboarding.index` — `App\Http\Controllers\Hr\OffboardingController@index` — `app/Http/Controllers/Hr/OffboardingController.php:26` — middleware `web, auth, permission:hr.onboarding.view`
- `POST hr/offboarding` — `hr.offboarding.store` — `App\Http\Controllers\Hr\OffboardingController@store` — `app/Http/Controllers/Hr/OffboardingController.php:215` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`
- `GET|HEAD hr/offboarding/{checklist}` — `hr.offboarding.show` — `App\Http\Controllers\Hr\OffboardingController@show` — `app/Http/Controllers/Hr/OffboardingController.php:176` — middleware `web, auth, permission:hr.onboarding.view`
- `GET|HEAD hr/offboarding/create` — `hr.offboarding.create` — `App\Http\Controllers\Hr\OffboardingController@create` — `app/Http/Controllers/Hr/OffboardingController.php:207` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`
- `POST hr/offboarding/tasks/{task}/complete` — `hr.offboarding.tasks.complete` — `App\Http\Controllers\Hr\OffboardingController@completeTask` — `app/Http/Controllers/Hr/OffboardingController.php:265` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/OffboardingController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/offboarding/index.tsx`, `resources/js/pages/hr/offboarding/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
