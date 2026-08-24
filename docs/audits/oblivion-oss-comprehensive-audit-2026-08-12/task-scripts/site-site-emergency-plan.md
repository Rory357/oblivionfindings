# SITE-SITE-EMERGENCY-PLAN: Site Emergency Plan

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.update`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-EMERGENCY-PLAN`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/emergency-plan` (`sites.emergency-plan.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.update`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/emergency-plan` (`sites.emergency-plan.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD sites/{site}/emergency-plan.pdf` (`sites.emergency-plan.download`, action `download`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Sites/SiteEmergencyPlanController.php:58-66`.
3. Invoke only the owning control for `PUT sites/{site}/emergency-plan` (`sites.emergency-plan.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteEmergencyPlanController.php:39-56`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `show` / `ROUTE-2785` at `app/Http/Controllers/Sites/SiteEmergencyPlanController.php:24`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2786` at `app/Http/Controllers/Sites/SiteEmergencyPlanController.php:39`; it is not runtime-observed.
- **file/report delivered** is applicable only to `download` / `ROUTE-2787` at `app/Http/Controllers/Sites/SiteEmergencyPlanController.php:58`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/sites/emergency-plan/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Sites/SiteEmergencyPlanController.php:31 `return Inertia::render('sites/emergency-plan/index', $this->emergencyPlans->viewModel($site, $plan) + [`; app/Http/Controllers/Sites/SiteEmergencyPlanController.php:51 `return response()->json([`; app/Http/Controllers/Sites/SiteEmergencyPlanController.php:65 `return $this->pdfs->download($site, $plan, (string) $request->query('paper', data_get($plan->layout, 'export.paper', 'a4')));`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD sites/{site}/emergency-plan` — `sites.emergency-plan.show` — `App\Http\Controllers\Sites\SiteEmergencyPlanController@show` — `app/Http/Controllers/Sites/SiteEmergencyPlanController.php:24` — middleware `web, auth, verified, permission:sites.viewAny`
- `PUT sites/{site}/emergency-plan` — `sites.emergency-plan.update` — `App\Http\Controllers\Sites\SiteEmergencyPlanController@update` — `app/Http/Controllers/Sites/SiteEmergencyPlanController.php:39` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.update`
- `GET|HEAD sites/{site}/emergency-plan.pdf` — `sites.emergency-plan.download` — `App\Http\Controllers\Sites\SiteEmergencyPlanController@download` — `app/Http/Controllers/Sites/SiteEmergencyPlanController.php:58` — middleware `web, auth, verified, permission:sites.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteEmergencyPlanController.php`.
- Exact render/action page relationships: `resources/js/pages/sites/emergency-plan/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
