# OPS-CLIENT-PATH-PLAN: Client Path Plan

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clients.update`
- Owning module: Operations and rostering
- Legacy family: `OPS-CLIENT-PATH-PLAN`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST operations/clients/{client}/path-plan` (`operations.clients.path_plan.upsert`, action `upsert`). Source category: **mutation outcome source gap (upsert)**; controller `app/Http/Controllers/Operations/ClientPathPlanController.php:12-54`; `dream`, `life_story`.
3. Invoke only the owning control for `DELETE operations/clients/{client}/path-plan/{plan}` (`operations.clients.path_plan.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Operations/ClientPathPlanController.php:56-64`; no exact validation fields extracted.

## Source-applicable states and transitions

- **mutation outcome source gap (upsert)** is applicable only to `upsert` / `ROUTE-2028` at `app/Http/Controllers/Operations/ClientPathPlanController.php:12`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2029` at `app/Http/Controllers/Operations/ClientPathPlanController.php:56`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2028` / `upsert`: fields `dream`, `life_story`; success app/Http/Controllers/Operations/ClientPathPlanController.php:53 `return back()->with('success', "PATH plan saved (#{$plan->id}).");`.
- `ROUTE-2029` / `destroy`: success app/Http/Controllers/Operations/ClientPathPlanController.php:63 `return back()->with('success', "PATH plan removed.");`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/ClientPathPlanController.php:41 `$client->update($narrative);`; app/Http/Controllers/Operations/ClientPathPlanController.php:44 `$plan = ClientPathPlan::updateOrCreate(`; app/Http/Controllers/Operations/ClientPathPlanController.php:61 `$plan->delete();`; responses app/Http/Controllers/Operations/ClientPathPlanController.php:53 `return back()->with('success', "PATH plan saved (#{$plan->id}).");`; app/Http/Controllers/Operations/ClientPathPlanController.php:63 `return back()->with('success', "PATH plan removed.");`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST operations/clients/{client}/path-plan` — `operations.clients.path_plan.upsert` — `App\Http\Controllers\Operations\ClientPathPlanController@upsert` — `app/Http/Controllers/Operations/ClientPathPlanController.php:12` — middleware `web, auth, permission:clients.update`
- `DELETE operations/clients/{client}/path-plan/{plan}` — `operations.clients.path_plan.destroy` — `App\Http\Controllers\Operations\ClientPathPlanController@destroy` — `app/Http/Controllers/Operations/ClientPathPlanController.php:56` — middleware `web, auth, permission:clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/ClientPathPlanController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
