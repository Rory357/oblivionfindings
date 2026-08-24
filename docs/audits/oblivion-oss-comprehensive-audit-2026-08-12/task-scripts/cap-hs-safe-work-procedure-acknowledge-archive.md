# CAP-HS-SAFE-WORK-PROCEDURE-ACKNOWLEDGE-ARCHIVE: Procedure acknowledgement archive and restore

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:procedures.view`, `permission:procedures.manage`
- Owning module: Health and safety
- Legacy family: `HS-SAFE-WORK-PROCEDURE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/procedures` (`health-safety.procedures.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:procedures.view`, `permission:procedures.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:procedures.view`, `permission:procedures.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/procedures` (`health-safety.procedures.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST health-safety/procedures/{procedure}/acknowledge` (`health-safety.procedures.acknowledge`, action `acknowledge`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:288-296`; no exact validation fields extracted.
3. Invoke only the owning control for `POST health-safety/procedures/{procedure}/archive` (`health-safety.procedures.archive`, action `archive`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:255-268`; no exact validation fields extracted.
4. Invoke only the owning control for `POST health-safety/procedures/{procedure}/restore` (`health-safety.procedures.restore`, action `restore`). Source category: **mutation outcome source gap (restore)**; controller `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:270-285`; no exact validation fields extracted.

## Source-applicable states and transitions

- **approved/acknowledged/verified** is applicable only to `acknowledge` / `ROUTE-1181` at `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:288`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `archive` / `ROUTE-1183` at `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:255`; it is not runtime-observed.
- **mutation outcome source gap (restore)** is applicable only to `restore` / `ROUTE-1190` at `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:270`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1181` / `acknowledge`: success app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:295 `return back()->with('success', 'Procedure acknowledged.');`.
- `ROUTE-1183` / `archive`: success app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:258 `return back()->with('success', 'Procedure is already archived.');`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:267 `return back()->with('success', 'Procedure archived.');`.
- `ROUTE-1190` / `restore`: success app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:284 `return back()->with('success', 'Procedure restored from the archive.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:290 `ProcedureAcknowledgement::updateOrCreate(`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:261 `$procedure->update([`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:278 `$procedure->update([`; responses app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:295 `return back()->with('success', 'Procedure acknowledged.');`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:258 `return back()->with('success', 'Procedure is already archived.');`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:267 `return back()->with('success', 'Procedure archived.');`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:284 `return back()->with('success', 'Procedure restored from the archive.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST health-safety/procedures/{procedure}/acknowledge` — `health-safety.procedures.acknowledge` — `App\Http\Controllers\HealthSafety\SafeWorkProcedureController@acknowledge` — `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:288` — middleware `web, auth, permission:procedures.view`
- `POST health-safety/procedures/{procedure}/archive` — `health-safety.procedures.archive` — `App\Http\Controllers\HealthSafety\SafeWorkProcedureController@archive` — `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:255` — middleware `web, auth, permission:procedures.manage`
- `POST health-safety/procedures/{procedure}/restore` — `health-safety.procedures.restore` — `App\Http\Controllers\HealthSafety\SafeWorkProcedureController@restore` — `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:270` — middleware `web, auth, permission:procedures.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
