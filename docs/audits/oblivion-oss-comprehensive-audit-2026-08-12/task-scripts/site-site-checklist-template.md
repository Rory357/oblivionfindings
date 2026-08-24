# SITE-SITE-CHECKLIST-TEMPLATE: Site Checklist Template

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:checklists.manage_templates`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-CHECKLIST-TEMPLATE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:checklists.manage_templates`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:checklists.manage_templates`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST sites/checklists/templates` (`sites.checklists.templates.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteChecklistTemplateController.php:19-41`; no exact validation fields extracted.
3. Invoke only the owning control for `DELETE sites/checklists/templates/{template}` (`sites.checklists.templates.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Sites/SiteChecklistTemplateController.php:66-77`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT sites/checklists/templates/{template}` (`sites.checklists.templates.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteChecklistTemplateController.php:43-64`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-2898` at `app/Http/Controllers/Sites/SiteChecklistTemplateController.php:19`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2899` at `app/Http/Controllers/Sites/SiteChecklistTemplateController.php:66`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2900` at `app/Http/Controllers/Sites/SiteChecklistTemplateController.php:43`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2898` / `store`: success app/Http/Controllers/Sites/SiteChecklistTemplateController.php:40 `return redirect()->back()->with('success', 'Checklist template created.');`.
- `ROUTE-2899` / `destroy`: success app/Http/Controllers/Sites/SiteChecklistTemplateController.php:76 `return redirect()->back()->with('success', 'Checklist template deleted.');`.
- `ROUTE-2900` / `update`: success app/Http/Controllers/Sites/SiteChecklistTemplateController.php:63 `return redirect()->back()->with('success', 'Checklist template updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteChecklistTemplateController.php:26 `$template = SiteChecklistTemplate::create([`; app/Http/Controllers/Sites/SiteChecklistTemplateController.php:74 `$template->delete();`; app/Http/Controllers/Sites/SiteChecklistTemplateController.php:50 `$template->update([`; responses app/Http/Controllers/Sites/SiteChecklistTemplateController.php:40 `return redirect()->back()->with('success', 'Checklist template created.');`; app/Http/Controllers/Sites/SiteChecklistTemplateController.php:71 `return redirect()->back()->with('error', 'Cannot delete a template with active site assignments. Remove the assignments first.');`; app/Http/Controllers/Sites/SiteChecklistTemplateController.php:76 `return redirect()->back()->with('success', 'Checklist template deleted.');`; app/Http/Controllers/Sites/SiteChecklistTemplateController.php:63 `return redirect()->back()->with('success', 'Checklist template updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST sites/checklists/templates` — `sites.checklists.templates.store` — `App\Http\Controllers\Sites\SiteChecklistTemplateController@store` — `app/Http/Controllers/Sites/SiteChecklistTemplateController.php:19` — middleware `web, auth, verified, permission:checklists.manage_templates`
- `DELETE sites/checklists/templates/{template}` — `sites.checklists.templates.destroy` — `App\Http\Controllers\Sites\SiteChecklistTemplateController@destroy` — `app/Http/Controllers/Sites/SiteChecklistTemplateController.php:66` — middleware `web, auth, verified, permission:checklists.manage_templates`
- `PUT sites/checklists/templates/{template}` — `sites.checklists.templates.update` — `App\Http\Controllers\Sites\SiteChecklistTemplateController@update` — `app/Http/Controllers/Sites/SiteChecklistTemplateController.php:43` — middleware `web, auth, verified, permission:checklists.manage_templates`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteChecklistTemplateController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
