# SITE-SITE-TYPE-PLAN: Site Type Plan

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.update`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-TYPE-PLAN`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/plan` (`sites.plan.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.update`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/plan` (`sites.plan.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `DELETE sites/{site}/plan/draft` (`sites.plan.draft.destroy`, action `discardDraft`). Source category: **mutation outcome source gap (discardDraft)**; controller `app/Http/Controllers/Sites/SiteTypePlanController.php:96-105`; no exact validation fields extracted.
3. Invoke only the owning control for `POST sites/{site}/plan/draft` (`sites.plan.draft.store`, action `storeDraft`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteTypePlanController.php:36-52`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT sites/{site}/plan/draft` (`sites.plan.draft.update`, action `updateDraft`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteTypePlanController.php:54-70`; no exact validation fields extracted.
5. Invoke only the owning control for `POST sites/{site}/plan/duplicate-to-draft` (`sites.plan.duplicate`, action `duplicate`). Source category: **mutation outcome source gap (duplicate)**; controller `app/Http/Controllers/Sites/SiteTypePlanController.php:84-94`; no exact validation fields extracted.
6. Invoke only the owning control for `POST sites/{site}/plan/publish` (`sites.plan.publish`, action `publish`). Source category: **mutation outcome source gap (publish)**; controller `app/Http/Controllers/Sites/SiteTypePlanController.php:72-82`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `show` / `ROUTE-2859` at `app/Http/Controllers/Sites/SiteTypePlanController.php:18`; it is not runtime-observed.
- **mutation outcome source gap (discardDraft)** is applicable only to `discardDraft` / `ROUTE-2860` at `app/Http/Controllers/Sites/SiteTypePlanController.php:96`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeDraft` / `ROUTE-2861` at `app/Http/Controllers/Sites/SiteTypePlanController.php:36`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateDraft` / `ROUTE-2862` at `app/Http/Controllers/Sites/SiteTypePlanController.php:54`; it is not runtime-observed.
- **mutation outcome source gap (duplicate)** is applicable only to `duplicate` / `ROUTE-2863` at `app/Http/Controllers/Sites/SiteTypePlanController.php:84`; it is not runtime-observed.
- **mutation outcome source gap (publish)** is applicable only to `publish` / `ROUTE-2867` at `app/Http/Controllers/Sites/SiteTypePlanController.php:72`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/sites/plan/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Sites/SiteTypePlanController.php:22 `return Inertia::render('sites/plan/index', [`; app/Http/Controllers/Sites/SiteTypePlanController.php:102 `return $this->respond($request, 'Draft plan discarded.', [`; app/Http/Controllers/Sites/SiteTypePlanController.php:48 `return $this->respond($request, 'Draft plan saved.', [`; app/Http/Controllers/Sites/SiteTypePlanController.php:66 `return $this->respond($request, 'Draft plan updated.', [`; app/Http/Controllers/Sites/SiteTypePlanController.php:90 `return $this->respond($request, 'Published plan copied to draft.', [`; app/Http/Controllers/Sites/SiteTypePlanController.php:78 `return $this->respond($request, 'Plan published.', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD sites/{site}/plan` — `sites.plan.show` — `App\Http\Controllers\Sites\SiteTypePlanController@show` — `app/Http/Controllers/Sites/SiteTypePlanController.php:18` — middleware `web, auth, verified, permission:sites.viewAny`
- `DELETE sites/{site}/plan/draft` — `sites.plan.draft.destroy` — `App\Http\Controllers\Sites\SiteTypePlanController@discardDraft` — `app/Http/Controllers/Sites/SiteTypePlanController.php:96` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.update`
- `POST sites/{site}/plan/draft` — `sites.plan.draft.store` — `App\Http\Controllers\Sites\SiteTypePlanController@storeDraft` — `app/Http/Controllers/Sites/SiteTypePlanController.php:36` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.update`
- `PUT sites/{site}/plan/draft` — `sites.plan.draft.update` — `App\Http\Controllers\Sites\SiteTypePlanController@updateDraft` — `app/Http/Controllers/Sites/SiteTypePlanController.php:54` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.update`
- `POST sites/{site}/plan/duplicate-to-draft` — `sites.plan.duplicate` — `App\Http\Controllers\Sites\SiteTypePlanController@duplicate` — `app/Http/Controllers/Sites/SiteTypePlanController.php:84` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.update`
- `POST sites/{site}/plan/publish` — `sites.plan.publish` — `App\Http\Controllers\Sites\SiteTypePlanController@publish` — `app/Http/Controllers/Sites/SiteTypePlanController.php:72` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteTypePlanController.php`.
- Exact render/action page relationships: `resources/js/pages/sites/plan/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
