# CAP-HR-MY-HR-GOALS: My goals and progress

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Human resources
- Legacy family: `HR-MY-HR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/my/goals` (`hr.my.goals`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/my/goals` (`hr.my.goals`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PUT hr/my/goals/{goal}` (`hr.my.goals.update`, action `updateGoal`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/MyHrController.php:1148-1170`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `goals` / `ROUTE-1519` at `app/Http/Controllers/Hr/MyHrController.php:1074`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateGoal` / `ROUTE-1520` at `app/Http/Controllers/Hr/MyHrController.php:1148`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/my/goals.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1520` / `updateGoal`: success app/Http/Controllers/Hr/MyHrController.php:1169 `return redirect()->back()->with('success', 'Goal updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/MyHrController.php:1167 `$goal->update($validated);`; responses app/Http/Controllers/Hr/MyHrController.php:1141 `return Inertia::render('hr/my/goals', [`; app/Http/Controllers/Hr/MyHrController.php:1169 `return redirect()->back()->with('success', 'Goal updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/my/goals` — `hr.my.goals` — `App\Http\Controllers\Hr\MyHrController@goals` — `app/Http/Controllers/Hr/MyHrController.php:1074` — middleware `web, auth`
- `PUT hr/my/goals/{goal}` — `hr.my.goals.update` — `App\Http\Controllers\Hr\MyHrController@updateGoal` — `app/Http/Controllers/Hr/MyHrController.php:1148` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/MyHrController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/my/goals.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
