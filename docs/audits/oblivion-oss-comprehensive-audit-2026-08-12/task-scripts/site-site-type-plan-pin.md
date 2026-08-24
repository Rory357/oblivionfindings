# SITE-SITE-TYPE-PLAN-PIN: Site Type Plan Pin

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.update`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-TYPE-PLAN-PIN`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.update`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST sites/{site}/plan/pins` (`sites.plan.pins.store`, action `storeBatch`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteTypePlanPinController.php:20-40`; no exact validation fields extracted.
3. Invoke only the owning control for `DELETE sites/{site}/plan/pins/{pin}` (`sites.plan.pins.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Sites/SiteTypePlanPinController.php:55-63`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT sites/{site}/plan/pins/{pin}` (`sites.plan.pins.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteTypePlanPinController.php:42-53`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeBatch` / `ROUTE-2864` at `app/Http/Controllers/Sites/SiteTypePlanPinController.php:20`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2865` at `app/Http/Controllers/Sites/SiteTypePlanPinController.php:55`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2866` at `app/Http/Controllers/Sites/SiteTypePlanPinController.php:42`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteTypePlanPinController.php:60 `$pin->delete();`; app/Http/Controllers/Sites/SiteTypePlanPinController.php:48 `$pin->update($data);`; responses app/Http/Controllers/Sites/SiteTypePlanPinController.php:36 `return response()->json([`; app/Http/Controllers/Sites/SiteTypePlanPinController.php:62 `return response()->json(['deleted' => true]);`; app/Http/Controllers/Sites/SiteTypePlanPinController.php:50 `return response()->json([`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST sites/{site}/plan/pins` — `sites.plan.pins.store` — `App\Http\Controllers\Sites\SiteTypePlanPinController@storeBatch` — `app/Http/Controllers/Sites/SiteTypePlanPinController.php:20` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.update`
- `DELETE sites/{site}/plan/pins/{pin}` — `sites.plan.pins.destroy` — `App\Http\Controllers\Sites\SiteTypePlanPinController@destroy` — `app/Http/Controllers/Sites/SiteTypePlanPinController.php:55` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.update`
- `PUT sites/{site}/plan/pins/{pin}` — `sites.plan.pins.update` — `App\Http\Controllers\Sites\SiteTypePlanPinController@update` — `app/Http/Controllers/Sites/SiteTypePlanPinController.php:42` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteTypePlanPinController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
