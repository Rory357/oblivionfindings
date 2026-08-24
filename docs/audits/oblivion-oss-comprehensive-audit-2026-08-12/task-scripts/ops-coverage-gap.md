# OPS-COVERAGE-GAP: Coverage Gap

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:rostering.viewAny`
- Owning module: Operations and rostering
- Legacy family: `OPS-COVERAGE-GAP`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:rostering.viewAny`.
- Exact middleware atoms: `web`, `auth`, `role_scope:my-day`, `permission:rostering.viewAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST operations/rostering/coverage/{key}/ack` (`operations.rostering.coverage.ack`, action `ack`). Source category: **mutation outcome source gap (ack)**; controller `app/Http/Controllers/CoverageGapController.php:16-19`; no exact validation fields extracted.
3. Invoke only the owning control for `DELETE operations/rostering/coverage/{key}/clear` (`operations.rostering.coverage.clear`, action `clear`). Source category: **mutation outcome source gap (clear)**; controller `app/Http/Controllers/CoverageGapController.php:26-56`; no exact validation fields extracted.
4. Invoke only the owning control for `POST operations/rostering/coverage/{key}/dismiss` (`operations.rostering.coverage.dismiss`, action `dismiss`). Source category: **mutation outcome source gap (dismiss)**; controller `app/Http/Controllers/CoverageGapController.php:21-24`; no exact validation fields extracted.

## Source-applicable states and transitions

- **mutation outcome source gap (ack)** is applicable only to `ack` / `ROUTE-2147` at `app/Http/Controllers/CoverageGapController.php:16`; it is not runtime-observed.
- **mutation outcome source gap (clear)** is applicable only to `clear` / `ROUTE-2148` at `app/Http/Controllers/CoverageGapController.php:26`; it is not runtime-observed.
- **mutation outcome source gap (dismiss)** is applicable only to `dismiss` / `ROUTE-2149` at `app/Http/Controllers/CoverageGapController.php:21`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/CoverageGapController.php:45 `->update(['cleared_at' => now()]);`; responses app/Http/Controllers/CoverageGapController.php:18 `return $this->store($key, $request, $siteAccess, CoverageGapAcknowledgement::STATE_ACKED);`; app/Http/Controllers/CoverageGapController.php:55 `return $this->respond($request, ['status' => 'cleared']);`; app/Http/Controllers/CoverageGapController.php:23 `return $this->store($key, $request, $siteAccess, CoverageGapAcknowledgement::STATE_DISMISSED);`; audit calls app/Http/Controllers/CoverageGapController.php:47 `AuditLogger::log('rostering.coverage.clear', $acknowledgement, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST operations/rostering/coverage/{key}/ack` — `operations.rostering.coverage.ack` — `App\Http\Controllers\CoverageGapController@ack` — `app/Http/Controllers/CoverageGapController.php:16` — middleware `web, auth, role_scope:my-day, permission:rostering.viewAny`
- `DELETE operations/rostering/coverage/{key}/clear` — `operations.rostering.coverage.clear` — `App\Http\Controllers\CoverageGapController@clear` — `app/Http/Controllers/CoverageGapController.php:26` — middleware `web, auth, role_scope:my-day, permission:rostering.viewAny`
- `POST operations/rostering/coverage/{key}/dismiss` — `operations.rostering.coverage.dismiss` — `App\Http\Controllers\CoverageGapController@dismiss` — `app/Http/Controllers/CoverageGapController.php:21` — middleware `web, auth, role_scope:my-day, permission:rostering.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/CoverageGapController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
