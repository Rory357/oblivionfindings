# CAP-HS-HAZARDOUS-SUBSTANCE-STATUS-GOVERNANCE: Hazardous substance status governance

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.manage`
- Owning module: Health and safety
- Legacy family: `HS-HAZARDOUS-SUBSTANCE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/substances` (`health-safety.substances.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hazards.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/substances` (`health-safety.substances.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PATCH health-safety/substances/{substance}/status` (`health-safety.substances.status`, action `updateStatus`). Source category: **updated/revised**; controller `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:293-318`; no exact validation fields extracted.

## Source-applicable states and transitions

- **updated/revised** is applicable only to `updateStatus` / `ROUTE-1234` at `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:293`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1234` / `updateStatus`: success app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:317 `return back()->with('success', "Substance {$label}.");`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:306 `$substance->update([`; responses app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:303 `return back()->with('error', 'A reason is required to mark a substance inactive or removed.');`; app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:317 `return back()->with('success', "Substance {$label}.");`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `PATCH health-safety/substances/{substance}/status` — `health-safety.substances.status` — `App\Http\Controllers\HealthSafety\HazardousSubstanceController@updateStatus` — `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:293` — middleware `web, auth, permission:hazards.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
