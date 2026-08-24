# CAP-HR-GOAL-CHECKINS-PROGRESS: Goal check-ins and progress updates

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`
- Owning module: Human resources
- Legacy family: `HR-GOAL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/goals` (`hr.goals.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.performance.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/goals` (`hr.goals.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/goals/{goal}/checkin` (`hr.goals.checkin`, action `checkin`). Source category: **mutation outcome source gap (checkin)**; controller `app/Http/Controllers/Hr/GoalController.php:566-619`; `confidence`.
3. Invoke only the owning control for `POST hr/goals/{goal}/progress` (`hr.goals.progress`, action `updateProgress`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/GoalController.php:530-555`; `current_value`.
4. Invoke only the owning control for `POST hr/my/goals/{goal}/checkin` (`hr.my.goals.checkin`, action `checkin`). Source category: **mutation outcome source gap (checkin)**; controller `app/Http/Controllers/Hr/GoalController.php:566-619`; `confidence`.

## Source-applicable states and transitions

- **mutation outcome source gap (checkin)** is applicable only to `checkin` / `ROUTE-1460` at `app/Http/Controllers/Hr/GoalController.php:566`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateProgress` / `ROUTE-1464` at `app/Http/Controllers/Hr/GoalController.php:530`; it is not runtime-observed.
- **mutation outcome source gap (checkin)** is applicable only to `checkin` / `ROUTE-1521` at `app/Http/Controllers/Hr/GoalController.php:566`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1460` / `checkin`: fields `confidence`; success app/Http/Controllers/Hr/GoalController.php:618 `return redirect()->back()->with('success', 'Check-in logged.');`.
- `ROUTE-1464` / `updateProgress`: fields `current_value`; success app/Http/Controllers/Hr/GoalController.php:554 `return redirect()->back()->with('success', 'Progress updated.');`.
- `ROUTE-1521` / `checkin`: fields `confidence`; success app/Http/Controllers/Hr/GoalController.php:618 `return redirect()->back()->with('success', 'Check-in logged.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/GoalController.php:618 `return redirect()->back()->with('success', 'Check-in logged.');`; app/Http/Controllers/Hr/GoalController.php:554 `return redirect()->back()->with('success', 'Progress updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/goals/{goal}/checkin` — `hr.goals.checkin` — `App\Http\Controllers\Hr\GoalController@checkin` — `app/Http/Controllers/Hr/GoalController.php:566` — middleware `web, auth, permission:hr.performance.view`
- `POST hr/goals/{goal}/progress` — `hr.goals.progress` — `App\Http\Controllers\Hr\GoalController@updateProgress` — `app/Http/Controllers/Hr/GoalController.php:530` — middleware `web, auth, permission:hr.performance.view`
- `POST hr/my/goals/{goal}/checkin` — `hr.my.goals.checkin` — `App\Http\Controllers\Hr\GoalController@checkin` — `app/Http/Controllers/Hr/GoalController.php:566` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/GoalController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
