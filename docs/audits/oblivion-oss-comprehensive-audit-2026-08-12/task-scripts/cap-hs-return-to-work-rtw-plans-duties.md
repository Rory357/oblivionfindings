# CAP-HS-RETURN-TO-WORK-RTW-PLANS-DUTIES: Return-to-work plans and modified duties

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.manage`
- Owning module: Health and safety
- Legacy family: `HS-RETURN-TO-WORK`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/injuries` (`health-safety.injuries.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hazards.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/injuries` (`health-safety.injuries.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST health-safety/injuries/{injury}/rtw-plans` (`health-safety.injuries.rtw-plans.store`, action `storeRtwPlan`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:570-597`; `plan_start_date`.
3. Invoke only the owning control for `PUT health-safety/injuries/rtw-plans/{rtwPlan}` (`health-safety.injuries.rtw-plans.update`, action `updateRtwPlan`). Source category: **updated/revised**; controller `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:599-623`; no exact validation fields extracted.
4. Invoke only the owning control for `POST health-safety/injuries/rtw-plans/{rtwPlan}/modified-duties` (`health-safety.injuries.modified-duties.store`, action `storeModifiedDuty`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:647-666`; `start_date`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeRtwPlan` / `ROUTE-1135` at `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:570`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateRtwPlan` / `ROUTE-1139` at `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:599`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeModifiedDuty` / `ROUTE-1140` at `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:647`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1135` / `storeRtwPlan`: fields `plan_start_date`; success app/Http/Controllers/HealthSafety/ReturnToWorkController.php:596 `return back()->with('success', 'Return-to-work plan created.');`.
- `ROUTE-1139` / `updateRtwPlan`: success app/Http/Controllers/HealthSafety/ReturnToWorkController.php:622 `return back()->with('success', 'Return-to-work plan updated.');`.
- `ROUTE-1140` / `storeModifiedDuty`: fields `start_date`; success app/Http/Controllers/HealthSafety/ReturnToWorkController.php:665 `return back()->with('success', 'Modified duty added.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/ReturnToWorkController.php:590 `$injury->returnToWorkPlans()->create(array_merge($validated, [`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:620 `$rtwPlan->update($validated);`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:659 `$rtwPlan->modifiedDuties()->create(array_merge($validated, [`; responses app/Http/Controllers/HealthSafety/ReturnToWorkController.php:596 `return back()->with('success', 'Return-to-work plan created.');`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:622 `return back()->with('success', 'Return-to-work plan updated.');`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:665 `return back()->with('success', 'Modified duty added.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST health-safety/injuries/{injury}/rtw-plans` — `health-safety.injuries.rtw-plans.store` — `App\Http\Controllers\HealthSafety\ReturnToWorkController@storeRtwPlan` — `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:570` — middleware `web, auth, permission:hazards.manage`
- `PUT health-safety/injuries/rtw-plans/{rtwPlan}` — `health-safety.injuries.rtw-plans.update` — `App\Http\Controllers\HealthSafety\ReturnToWorkController@updateRtwPlan` — `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:599` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/injuries/rtw-plans/{rtwPlan}/modified-duties` — `health-safety.injuries.modified-duties.store` — `App\Http\Controllers\HealthSafety\ReturnToWorkController@storeModifiedDuty` — `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:647` — middleware `web, auth, permission:hazards.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/ReturnToWorkController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
