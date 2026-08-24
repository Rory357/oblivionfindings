# CAP-HS-LONE-WORKER-ALERT-RESPONSE: Lone-worker alert acknowledgement and resolution

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.manage`
- Owning module: Health and safety
- Legacy family: `HS-LONE-WORKER`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/lone-workers` (`health-safety.lone-workers.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hazards.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/lone-workers` (`health-safety.lone-workers.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST health-safety/lone-workers/alerts/{alert}/acknowledge` (`health-safety.lone-workers.alerts.acknowledge`, action `acknowledgeAlert`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/HealthSafety/LoneWorkerController.php:359-369`; no exact validation fields extracted.
3. Invoke only the owning control for `POST health-safety/lone-workers/alerts/{alert}/resolve` (`health-safety.lone-workers.alerts.resolve`, action `resolveAlert`). Source category: **completed/closed/released**; controller `app/Http/Controllers/HealthSafety/LoneWorkerController.php:374-388`; `resolution_notes`.

## Source-applicable states and transitions

- **approved/acknowledged/verified** is applicable only to `acknowledgeAlert` / `ROUTE-1142` at `app/Http/Controllers/HealthSafety/LoneWorkerController.php:359`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `resolveAlert` / `ROUTE-1143` at `app/Http/Controllers/HealthSafety/LoneWorkerController.php:374`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1142` / `acknowledgeAlert`: success app/Http/Controllers/HealthSafety/LoneWorkerController.php:368 `->with('success', 'Alert acknowledged. For operational triage and escalation, use the Control Room.');`.
- `ROUTE-1143` / `resolveAlert`: fields `resolution_notes`; success app/Http/Controllers/HealthSafety/LoneWorkerController.php:387 `->with('success', 'Alert resolved. Ensure the corresponding Control Room alert is also resolved.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/LoneWorkerController.php:361 `$alert->update([`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:380 `$alert->update([`; responses app/Http/Controllers/HealthSafety/LoneWorkerController.php:367 `return redirect()->back()`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:386 `return redirect()->back()`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST health-safety/lone-workers/alerts/{alert}/acknowledge` — `health-safety.lone-workers.alerts.acknowledge` — `App\Http\Controllers\HealthSafety\LoneWorkerController@acknowledgeAlert` — `app/Http/Controllers/HealthSafety/LoneWorkerController.php:359` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/lone-workers/alerts/{alert}/resolve` — `health-safety.lone-workers.alerts.resolve` — `App\Http\Controllers\HealthSafety\LoneWorkerController@resolveAlert` — `app/Http/Controllers/HealthSafety/LoneWorkerController.php:374` — middleware `web, auth, permission:hazards.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/LoneWorkerController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
