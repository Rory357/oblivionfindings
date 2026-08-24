# CAP-GOV-RESOLUTION-FINALIZATION: Resolution finalization

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.resolutions.view`, `permission:governance.resolutions.manage`
- Owning module: Governance
- Legacy family: `GOV-RESOLUTION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/resolutions` (`governance.resolutions.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.resolutions.view`, `permission:governance.resolutions.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.resolutions.view`, `permission:governance.resolutions.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/resolutions` (`governance.resolutions.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST governance/resolutions/{resolution}/finalize` (`governance.resolutions.finalize`, action `finalize`). Source category: **completed/closed/released**; controller `app/Domain/Governance/Http/Controllers/ResolutionController.php:225-250`; `notes`.

## Source-applicable states and transitions

- **completed/closed/released** is applicable only to `finalize` / `ROUTE-0997` at `app/Domain/Governance/Http/Controllers/ResolutionController.php:225`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0997` / `finalize`: fields `notes`; success app/Domain/Governance/Http/Controllers/ResolutionController.php:249 `return redirect()->back()->with('success', 'Resolution finalized.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/Governance/Http/Controllers/ResolutionController.php:235 `return redirect()->back()->with('error', 'Resolution must be closed before finalizing.');`; app/Domain/Governance/Http/Controllers/ResolutionController.php:249 `return redirect()->back()->with('success', 'Resolution finalized.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST governance/resolutions/{resolution}/finalize` — `governance.resolutions.finalize` — `App\Domain\Governance\Http\Controllers\ResolutionController@finalize` — `app/Domain/Governance/Http/Controllers/ResolutionController.php:225` — middleware `web, auth, permission:governance.resolutions.view, permission:governance.resolutions.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/ResolutionController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
