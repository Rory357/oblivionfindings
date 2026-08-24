# CAP-HR-TRAINING-CLAIMS-EXPORT: Training fee claims and export

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.training.view|training.viewAny`
- Owning module: Human resources
- Legacy family: `HR-TRAINING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/training/export` (`hr.training.export`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.training.view|training.viewAny`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.training.view|training.viewAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/training/export` (`hr.training.export`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/training/claims` (`hr.training.claims.store`, action `claimFee`). Source category: **mutation outcome source gap (claimFee)**; controller `app/Http/Controllers/Hr/TrainingController.php:544-585`; `title`.

## Source-applicable states and transitions

- **mutation outcome source gap (claimFee)** is applicable only to `claimFee` / `ROUTE-1792` at `app/Http/Controllers/Hr/TrainingController.php:544`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-1803` at `app/Http/Controllers/Hr/TrainingController.php:591`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1792` / `claimFee`: fields `title`; success app/Http/Controllers/Hr/TrainingController.php:584 `return redirect()->back()->with('success', 'Expense claim submitted.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/TrainingController.php:584 `return redirect()->back()->with('success', 'Expense claim submitted.');`; app/Http/Controllers/Hr/TrainingController.php:604 `return response()->streamDownload(function () use ($headers, $rows) {`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/training/claims` — `hr.training.claims.store` — `App\Http\Controllers\Hr\TrainingController@claimFee` — `app/Http/Controllers/Hr/TrainingController.php:544` — middleware `web, auth, permission:hr.training.view|training.viewAny`
- `GET|HEAD hr/training/export` — `hr.training.export` — `App\Http\Controllers\Hr\TrainingController@export` — `app/Http/Controllers/Hr/TrainingController.php:591` — middleware `web, auth, permission:hr.training.view|training.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/TrainingController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
