# CAP-HS-EMERGENCY-DRILL-FINDINGS: Emergency drill findings and resolution

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.manage`
- Owning module: Health and safety
- Legacy family: `HS-EMERGENCY-DRILL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/drills` (`health-safety.drills.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hazards.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/drills` (`health-safety.drills.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST health-safety/drills/{drill}/findings` (`health-safety.drills.findings.store`, action `addFinding`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:378-395`; `finding_type`.
3. Invoke only the owning control for `POST health-safety/drills/{drill}/findings/{finding}/resolve` (`health-safety.drills.findings.resolve`, action `resolveFinding`). Source category: **completed/closed/released**; controller `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:422-438`; `resolution_notes`.
4. Invoke only the owning control for `PUT health-safety/drills/findings/{finding}` (`health-safety.drills.findings.update`, action `updateFinding`). Source category: **updated/revised**; controller `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:400-417`; `finding_type`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `addFinding` / `ROUTE-1092` at `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:378`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `resolveFinding` / `ROUTE-1093` at `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:422`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateFinding` / `ROUTE-1097` at `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:400`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1092` / `addFinding`: fields `finding_type`; success app/Http/Controllers/HealthSafety/EmergencyDrillController.php:394 `return back()->with('success', 'Finding recorded.');`.
- `ROUTE-1093` / `resolveFinding`: fields `resolution_notes`; success app/Http/Controllers/HealthSafety/EmergencyDrillController.php:437 `return back()->with('success', 'Finding resolved.');`.
- `ROUTE-1097` / `updateFinding`: fields `finding_type`; success app/Http/Controllers/HealthSafety/EmergencyDrillController.php:416 `return back()->with('success', 'Finding updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/EmergencyDrillController.php:389 `$drill->findings()->create(array_merge($validated, [`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:430 `$finding->update([`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:414 `$finding->update($validated);`; responses app/Http/Controllers/HealthSafety/EmergencyDrillController.php:394 `return back()->with('success', 'Finding recorded.');`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:437 `return back()->with('success', 'Finding resolved.');`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:416 `return back()->with('success', 'Finding updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST health-safety/drills/{drill}/findings` — `health-safety.drills.findings.store` — `App\Http\Controllers\HealthSafety\EmergencyDrillController@addFinding` — `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:378` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/drills/{drill}/findings/{finding}/resolve` — `health-safety.drills.findings.resolve` — `App\Http\Controllers\HealthSafety\EmergencyDrillController@resolveFinding` — `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:422` — middleware `web, auth, permission:hazards.manage`
- `PUT health-safety/drills/findings/{finding}` — `health-safety.drills.findings.update` — `App\Http\Controllers\HealthSafety\EmergencyDrillController@updateFinding` — `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:400` — middleware `web, auth, permission:hazards.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/EmergencyDrillController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
