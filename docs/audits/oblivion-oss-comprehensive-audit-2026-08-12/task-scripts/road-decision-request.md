# ROAD-DECISION-REQUEST: Decision Request

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:roadmap.decisions.view|governance.resolutions.view`, `permission:roadmap.decisions.manage|governance.resolutions.manage`
- Owning module: Roadmap
- Legacy family: `ROAD-DECISION-REQUEST`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `roadmap/decisions` (`roadmap.decisions.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:roadmap.decisions.view|governance.resolutions.view`, `permission:roadmap.decisions.manage|governance.resolutions.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:roadmap.decisions.view|governance.resolutions.view`, `permission:roadmap.decisions.manage|governance.resolutions.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD roadmap/decisions` (`roadmap.decisions.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST roadmap/decisions/{decisionRequest}/resolve` (`roadmap.decisions.resolve`, action `resolve`). Source category: **completed/closed/released**; controller `app/Domain/Roadmap/Http/Controllers/DecisionRequestController.php:57-71`; FormRequest `app/Domain/Roadmap/Http/Requests/UpdateDecisionRequestRequest.php:14`, `app/Domain/Roadmap/Models/DecisionRequest.php:line unresolved`; `notes`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2475` at `app/Domain/Roadmap/Http/Controllers/DecisionRequestController.php:23`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `resolve` / `ROUTE-2476` at `app/Domain/Roadmap/Http/Controllers/DecisionRequestController.php:57`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Roadmap/Decisions/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2475` / `index`: FormRequest `app/Domain/Roadmap/Models/DecisionRequest.php:line unresolved`.
- `ROUTE-2476` / `resolve`: FormRequest `app/Domain/Roadmap/Http/Requests/UpdateDecisionRequestRequest.php:14`, `app/Domain/Roadmap/Models/DecisionRequest.php:line unresolved`; fields `notes`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/Roadmap/Http/Controllers/DecisionRequestController.php:45 `return response()->json(['items' => $items]);`; app/Domain/Roadmap/Http/Controllers/DecisionRequestController.php:48 `return Inertia::render('Roadmap/Decisions/Index', [`; app/Domain/Roadmap/Http/Controllers/DecisionRequestController.php:70 `return response()->json(['item' => $decisionRequest->fresh()]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD roadmap/decisions` — `roadmap.decisions.index` — `App\Domain\Roadmap\Http\Controllers\DecisionRequestController@index` — `app/Domain/Roadmap/Http/Controllers/DecisionRequestController.php:23` — middleware `web, auth, permission:roadmap.decisions.view|governance.resolutions.view`
- `POST roadmap/decisions/{decisionRequest}/resolve` — `roadmap.decisions.resolve` — `App\Domain\Roadmap\Http\Controllers\DecisionRequestController@resolve` — `app/Domain/Roadmap/Http/Controllers/DecisionRequestController.php:57` — middleware `web, auth, permission:roadmap.decisions.manage|governance.resolutions.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Roadmap/Http/Controllers/DecisionRequestController.php`.
- Exact render/action page relationships: `resources/js/pages/Roadmap/Decisions/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
