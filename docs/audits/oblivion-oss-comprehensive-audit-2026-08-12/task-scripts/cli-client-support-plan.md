# CLI-CLIENT-SUPPORT-PLAN: Client Support Plan

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clients.update`
- Owning module: Clients and supported people
- Legacy family: `CLI-CLIENT-SUPPORT-PLAN`
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
2. Invoke only the owning control for `PUT clients/{client}/support-plan` (`clients.support_plan.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientSupportPlanController.php:12-38`; `goals`.
3. Invoke only the owning control for `PUT operations/clients/{client}/support-plan` (`operations.clients.support_plan.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientSupportPlanController.php:12-38`; `goals`.

## Source-applicable states and transitions

- **updated/revised** is applicable only to `update` / `ROUTE-0194` at `app/Http/Controllers/ClientSupportPlanController.php:12`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2049` at `app/Http/Controllers/ClientSupportPlanController.php:12`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0194` / `update`: fields `goals`; success app/Http/Controllers/ClientSupportPlanController.php:37 `return back()->with('success', 'Support plan updated.');`.
- `ROUTE-2049` / `update`: fields `goals`; success app/Http/Controllers/ClientSupportPlanController.php:37 `return back()->with('success', 'Support plan updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ClientSupportPlanController.php:27 `$plan = ClientSupportPlan::updateOrCreate(`; responses app/Http/Controllers/ClientSupportPlanController.php:37 `return back()->with('success', 'Support plan updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `PUT clients/{client}/support-plan` — `clients.support_plan.update` — `App\Http\Controllers\ClientSupportPlanController@update` — `app/Http/Controllers/ClientSupportPlanController.php:12` — middleware `web, auth, permission:clients.update`
- `PUT operations/clients/{client}/support-plan` — `operations.clients.support_plan.update` — `App\Http\Controllers\ClientSupportPlanController@update` — `app/Http/Controllers/ClientSupportPlanController.php:12` — middleware `web, auth, permission:clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ClientSupportPlanController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
