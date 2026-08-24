# SITE-SITE-MEAL-WEEK-TEMPLATE: Site Meal Week Template

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.meals.view`, `permission:sites.meals.plan`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-MEAL-WEEK-TEMPLATE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/meal-templates` (`sites.meals.templates.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.meals.view`, `permission:sites.meals.plan`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.meals.view`, `permission:sites.meals.plan`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/meal-templates` (`sites.meals.templates.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST sites/{site}/meal-templates` (`sites.meals.templates.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:35-51`; no exact validation fields extracted.
3. Invoke only the owning control for `DELETE sites/{site}/meal-templates/{template}` (`sites.meals.templates.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:68-74`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT sites/{site}/meal-templates/{template}` (`sites.meals.templates.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:53-66`; no exact validation fields extracted.
5. Invoke only the owning control for `POST sites/{site}/meal-templates/{template}/apply` (`sites.meals.templates.apply`, action `apply`). Source category: **mutation outcome source gap (apply)**; controller `app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:76-120`; `week`, `replace`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2851` at `app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:18`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2852` at `app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:35`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2853` at `app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:68`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2854` at `app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:53`; it is not runtime-observed.
- **mutation outcome source gap (apply)** is applicable only to `apply` / `ROUTE-2855` at `app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:76`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2855` / `apply`: fields `week`, `replace`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:40 `SiteMealWeekTemplate::create([`; app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:72 `$template->delete();`; app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:59 `$template->update([`; app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:92 `->delete();`; app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:105 `SiteMealPlanEntry::create([`; responses app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:32 `return response()->json(['templates' => $templates]);`; app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:50 `return $this->inertiaOrJson($request, 'Template saved');`; app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:73 `return $this->inertiaOrJson($request, 'Template deleted');`; app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:65 `return $this->inertiaOrJson($request, 'Template updated');`; app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:119 `return $this->inertiaOrJson($request, "Applied “{$template->name}” · {$applied} meal" . ($applied === 1 ? '' : 's'));`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD sites/{site}/meal-templates` — `sites.meals.templates.index` — `App\Http\Controllers\Sites\SiteMealWeekTemplateController@index` — `app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:18` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view`
- `POST sites/{site}/meal-templates` — `sites.meals.templates.store` — `App\Http\Controllers\Sites\SiteMealWeekTemplateController@store` — `app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:35` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.plan`
- `DELETE sites/{site}/meal-templates/{template}` — `sites.meals.templates.destroy` — `App\Http\Controllers\Sites\SiteMealWeekTemplateController@destroy` — `app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:68` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.plan`
- `PUT sites/{site}/meal-templates/{template}` — `sites.meals.templates.update` — `App\Http\Controllers\Sites\SiteMealWeekTemplateController@update` — `app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:53` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.plan`
- `POST sites/{site}/meal-templates/{template}/apply` — `sites.meals.templates.apply` — `App\Http\Controllers\Sites\SiteMealWeekTemplateController@apply` — `app/Http/Controllers/Sites/SiteMealWeekTemplateController.php:76` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.plan`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteMealWeekTemplateController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
