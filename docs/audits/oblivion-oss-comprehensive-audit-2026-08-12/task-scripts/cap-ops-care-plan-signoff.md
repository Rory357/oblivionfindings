# CAP-OPS-CARE-PLAN-SIGNOFF: Care plan sign-off management

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:care_plans.update`
- Owning module: Operations and rostering
- Legacy family: `OPS-CARE-PLAN`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/care-plans` (`operations.care_plans.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:care_plans.update`.
- Exact middleware atoms: `web`, `auth`, `permission:care_plans.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/care-plans` (`operations.care_plans.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST operations/care-plans/{carePlan}/sign-offs` (`operations.care_plans.sign_offs.store`, action `storeSignOff`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/CarePlanController.php:543-597`; `party_role`.
3. Invoke only the owning control for `DELETE operations/care-plans/{carePlan}/sign-offs/{signOff}` (`operations.care_plans.sign_offs.destroy`, action `destroySignOff`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Operations/CarePlanController.php:599-617`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeSignOff` / `ROUTE-1923` at `app/Http/Controllers/Operations/CarePlanController.php:543`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroySignOff` / `ROUTE-1924` at `app/Http/Controllers/Operations/CarePlanController.php:599`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1923` / `storeSignOff`: fields `party_role`; success app/Http/Controllers/Operations/CarePlanController.php:596 `return back()->with('success', 'Sign-off recorded.');`.
- `ROUTE-1924` / `destroySignOff`: success app/Http/Controllers/Operations/CarePlanController.php:616 `return back()->with('success', 'Sign-off removed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/CarePlanController.php:564 `$signOff = $carePlan->signOffs()->create([`; app/Http/Controllers/Operations/CarePlanController.php:613 `$signOff->delete();`; responses app/Http/Controllers/Operations/CarePlanController.php:596 `return back()->with('success', 'Sign-off recorded.');`; app/Http/Controllers/Operations/CarePlanController.php:616 `return back()->with('success', 'Sign-off removed.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST operations/care-plans/{carePlan}/sign-offs` — `operations.care_plans.sign_offs.store` — `App\Http\Controllers\Operations\CarePlanController@storeSignOff` — `app/Http/Controllers/Operations/CarePlanController.php:543` — middleware `web, auth, permission:care_plans.update`
- `DELETE operations/care-plans/{carePlan}/sign-offs/{signOff}` — `operations.care_plans.sign_offs.destroy` — `App\Http\Controllers\Operations\CarePlanController@destroySignOff` — `app/Http/Controllers/Operations/CarePlanController.php:599` — middleware `web, auth, permission:care_plans.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/CarePlanController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
