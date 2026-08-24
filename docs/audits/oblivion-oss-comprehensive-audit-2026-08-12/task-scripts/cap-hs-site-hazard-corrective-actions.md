# CAP-HS-SITE-HAZARD-CORRECTIVE-ACTIONS: Hazard corrective action completion

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:hazards.manage`
- Owning module: Health and safety
- Legacy family: `HS-SITE-HAZARD`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `compliance/hazards` (`compliance.hazards`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:hazards.manage`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:hazards.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD compliance/hazards` (`compliance.hazards`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hazard-actions/{action}/complete` (`sites.hazards.actions.complete`, action `completeAction`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Sites/SiteHazardController.php:543-558`; `completion_notes`.

## Source-applicable states and transitions

- **completed/closed/released** is applicable only to `completeAction` / `ROUTE-1044` at `app/Http/Controllers/Sites/SiteHazardController.php:543`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1044` / `completeAction`: fields `completion_notes`; success app/Http/Controllers/Sites/SiteHazardController.php:557 `return back()->with('success', 'Corrective action completed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteHazardController.php:550 `$action->update([`; responses app/Http/Controllers/Sites/SiteHazardController.php:557 `return back()->with('success', 'Corrective action completed.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hazard-actions/{action}/complete` — `sites.hazards.actions.complete` — `App\Http\Controllers\Sites\SiteHazardController@completeAction` — `app/Http/Controllers/Sites/SiteHazardController.php:543` — middleware `web, auth, verified, permission:hazards.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteHazardController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
