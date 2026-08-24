# INC-SAFEGUARDING-ACTION-PLAN: Safeguarding Action Plan

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Incidents and safeguarding
- Legacy family: `INC-SAFEGUARDING-ACTION-PLAN`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST safeguarding/{concern}/action-plans` (`safeguarding.actionPlans.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/SafeguardingActionPlanController.php:16-38`; `action_description`, `action_type`, `assigned_to_user_id`, `due_date`, `priority`.
3. Invoke only the owning control for `PUT safeguarding/{concern}/action-plans/{actionPlan}` (`safeguarding.actionPlans.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/SafeguardingActionPlanController.php:43-59`; `action_description`, `due_date`, `completion_notes`.
4. Invoke only the owning control for `POST safeguarding/{concern}/action-plans/{actionPlan}/complete` (`safeguarding.actionPlans.complete`, action `complete`). Source category: **completed/closed/released**; controller `app/Http/Controllers/SafeguardingActionPlanController.php:64-81`; `completion_notes`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-2506` at `app/Http/Controllers/SafeguardingActionPlanController.php:16`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2507` at `app/Http/Controllers/SafeguardingActionPlanController.php:43`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `complete` / `ROUTE-2508` at `app/Http/Controllers/SafeguardingActionPlanController.php:64`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2506` / `store`: fields `action_description`, `action_type`, `assigned_to_user_id`, `due_date`, `priority`; success app/Http/Controllers/SafeguardingActionPlanController.php:37 `return back()->with('success', 'Action plan created successfully.');`.
- `ROUTE-2507` / `update`: fields `action_description`, `due_date`, `completion_notes`; success app/Http/Controllers/SafeguardingActionPlanController.php:58 `return back()->with('success', 'Action plan updated successfully.');`.
- `ROUTE-2508` / `complete`: fields `completion_notes`; success app/Http/Controllers/SafeguardingActionPlanController.php:80 `return back()->with('success', 'Action plan marked as completed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/SafeguardingActionPlanController.php:35 `SafeguardingActionPlan::create($validated);`; app/Http/Controllers/SafeguardingActionPlanController.php:56 `$actionPlan->update($validated);`; app/Http/Controllers/SafeguardingActionPlanController.php:72 `$actionPlan->update([`; responses app/Http/Controllers/SafeguardingActionPlanController.php:37 `return back()->with('success', 'Action plan created successfully.');`; app/Http/Controllers/SafeguardingActionPlanController.php:58 `return back()->with('success', 'Action plan updated successfully.');`; app/Http/Controllers/SafeguardingActionPlanController.php:80 `return back()->with('success', 'Action plan marked as completed.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST safeguarding/{concern}/action-plans` — `safeguarding.actionPlans.store` — `App\Http\Controllers\SafeguardingActionPlanController@store` — `app/Http/Controllers/SafeguardingActionPlanController.php:16` — middleware `web, auth`
- `PUT safeguarding/{concern}/action-plans/{actionPlan}` — `safeguarding.actionPlans.update` — `App\Http\Controllers\SafeguardingActionPlanController@update` — `app/Http/Controllers/SafeguardingActionPlanController.php:43` — middleware `web, auth`
- `POST safeguarding/{concern}/action-plans/{actionPlan}/complete` — `safeguarding.actionPlans.complete` — `App\Http\Controllers\SafeguardingActionPlanController@complete` — `app/Http/Controllers/SafeguardingActionPlanController.php:64` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/SafeguardingActionPlanController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
