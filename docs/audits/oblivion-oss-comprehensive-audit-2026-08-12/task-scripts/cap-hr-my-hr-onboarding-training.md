# CAP-HR-MY-HR-ONBOARDING-TRAINING: My onboarding and training

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Human resources
- Legacy family: `HR-MY-HR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/my/training` (`hr.my.training`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/my/training` (`hr.my.training`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/my/onboarding/tasks/{task}/complete` (`hr.my.onboarding.tasks.complete`, action `completeOnboardingTask`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Hr/MyHrController.php:412-437`; `notes`.

## Source-applicable states and transitions

- **completed/closed/released** is applicable only to `completeOnboardingTask` / `ROUTE-1529` at `app/Http/Controllers/Hr/MyHrController.php:412`; it is not runtime-observed.
- **information presented** is applicable only to `training` / `ROUTE-1548` at `app/Http/Controllers/Hr/MyHrController.php:667`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/my/training.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1529` / `completeOnboardingTask`: fields `notes`; success app/Http/Controllers/Hr/MyHrController.php:436 `return redirect()->back()->with('success', "Nice — “{$task->title}” done.");`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/MyHrController.php:433 `return redirect()->back()->with('error', $exception->getMessage());`; app/Http/Controllers/Hr/MyHrController.php:436 `return redirect()->back()->with('success', "Nice — “{$task->title}” done.");`; app/Http/Controllers/Hr/MyHrController.php:729 `return [`; app/Http/Controllers/Hr/MyHrController.php:748 `return Inertia::render('hr/my/training', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/my/onboarding/tasks/{task}/complete` — `hr.my.onboarding.tasks.complete` — `App\Http\Controllers\Hr\MyHrController@completeOnboardingTask` — `app/Http/Controllers/Hr/MyHrController.php:412` — middleware `web, auth`
- `GET|HEAD hr/my/training` — `hr.my.training` — `App\Http\Controllers\Hr\MyHrController@training` — `app/Http/Controllers/Hr/MyHrController.php:667` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/MyHrController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/my/training.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
