# OPS-ROSTER-TEMPLATE: Roster Template

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:roster_templates.create`, `permission:roster_templates.delete`, `permission:roster_templates.update`
- Owning module: Operations and rostering
- Legacy family: `OPS-ROSTER-TEMPLATE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:roster_templates.create`, `permission:roster_templates.delete`, `permission:roster_templates.update`.
- Exact middleware atoms: `web`, `auth`, `permission:roster_templates.create`, `permission:roster_templates.delete`, `permission:roster_templates.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST operations/rostering/templates` (`operations.rostering.templates.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/RosterTemplateController.php:24-52`; FormRequest `app/Http/Requests/Operations/Rostering/StoreRosterTemplateRequest.php:16`; no exact validation fields extracted.
3. Invoke only the owning control for `DELETE operations/rostering/templates/{template}` (`operations.rostering.templates.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Operations/RosterTemplateController.php:84-95`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT operations/rostering/templates/{template}` (`operations.rostering.templates.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/RosterTemplateController.php:54-82`; FormRequest `app/Http/Requests/Operations/Rostering/UpdateRosterTemplateRequest.php:line unresolved`; no exact validation fields extracted.
5. Invoke only the owning control for `POST operations/rostering/templates/{template}/apply` (`operations.rostering.templates.apply`, action `apply`). Source category: **mutation outcome source gap (apply)**; controller `app/Http/Controllers/Operations/RosterTemplateController.php:156-245`; FormRequest `app/Http/Requests/Operations/Rostering/ApplyRosterTemplateRequest.php:16`; `week_start`, `cycles`, `confirm_warnings`.
6. Invoke only the owning control for `POST operations/rostering/templates/{template}/duplicate` (`operations.rostering.templates.duplicate`, action `duplicate`). Source category: **mutation outcome source gap (duplicate)**; controller `app/Http/Controllers/Operations/RosterTemplateController.php:97-140`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-2162` at `app/Http/Controllers/Operations/RosterTemplateController.php:24`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2163` at `app/Http/Controllers/Operations/RosterTemplateController.php:84`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2164` at `app/Http/Controllers/Operations/RosterTemplateController.php:54`; it is not runtime-observed.
- **mutation outcome source gap (apply)** is applicable only to `apply` / `ROUTE-2165` at `app/Http/Controllers/Operations/RosterTemplateController.php:156`; it is not runtime-observed.
- **mutation outcome source gap (duplicate)** is applicable only to `duplicate` / `ROUTE-2166` at `app/Http/Controllers/Operations/RosterTemplateController.php:97`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2162` / `store`: FormRequest `app/Http/Requests/Operations/Rostering/StoreRosterTemplateRequest.php:16`.
- `ROUTE-2164` / `update`: FormRequest `app/Http/Requests/Operations/Rostering/UpdateRosterTemplateRequest.php:line unresolved`.
- `ROUTE-2165` / `apply`: FormRequest `app/Http/Requests/Operations/Rostering/ApplyRosterTemplateRequest.php:16`; fields `week_start`, `cycles`, `confirm_warnings`; failure app/Http/Controllers/Operations/RosterTemplateController.php:203 `throw ValidationException::withMessages([`; app/Http/Controllers/Operations/RosterTemplateController.php:212 `throw ValidationException::withMessages([`; app/Http/Controllers/Operations/RosterTemplateController.php:239 `throw $exception;`.

## Failure and recovery paths

- `apply`: app/Http/Controllers/Operations/RosterTemplateController.php:203 `throw ValidationException::withMessages([`; app/Http/Controllers/Operations/RosterTemplateController.php:212 `throw ValidationException::withMessages([`; app/Http/Controllers/Operations/RosterTemplateController.php:239 `throw $exception;`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/RosterTemplateController.php:31 `$template = RosterTemplate::create([`; app/Http/Controllers/Operations/RosterTemplateController.php:90 `$template->delete();`; app/Http/Controllers/Operations/RosterTemplateController.php:62 `$template->update([`; app/Http/Controllers/Operations/RosterTemplateController.php:69 `$template->templateShifts()->delete();`; app/Http/Controllers/Operations/RosterTemplateController.php:229 `$shift = $lifecycle->create($occurrence['attributes'], $auth);`; app/Http/Controllers/Operations/RosterTemplateController.php:106 `$copy = RosterTemplate::create([`; responses app/Http/Controllers/Operations/RosterTemplateController.php:49 `return redirect()`; app/Http/Controllers/Operations/RosterTemplateController.php:92 `return redirect()`; app/Http/Controllers/Operations/RosterTemplateController.php:79 `return redirect()`; app/Http/Controllers/Operations/RosterTemplateController.php:188 `return redirect()`; app/Http/Controllers/Operations/RosterTemplateController.php:221 `return redirect()`; app/Http/Controllers/Operations/RosterTemplateController.php:242 `return redirect()`; app/Http/Controllers/Operations/RosterTemplateController.php:137 `return redirect()`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST operations/rostering/templates` — `operations.rostering.templates.store` — `App\Http\Controllers\Operations\RosterTemplateController@store` — `app/Http/Controllers/Operations/RosterTemplateController.php:24` — middleware `web, auth, permission:roster_templates.create`
- `DELETE operations/rostering/templates/{template}` — `operations.rostering.templates.destroy` — `App\Http\Controllers\Operations\RosterTemplateController@destroy` — `app/Http/Controllers/Operations/RosterTemplateController.php:84` — middleware `web, auth, permission:roster_templates.delete`
- `PUT operations/rostering/templates/{template}` — `operations.rostering.templates.update` — `App\Http\Controllers\Operations\RosterTemplateController@update` — `app/Http/Controllers/Operations/RosterTemplateController.php:54` — middleware `web, auth, permission:roster_templates.update`
- `POST operations/rostering/templates/{template}/apply` — `operations.rostering.templates.apply` — `App\Http\Controllers\Operations\RosterTemplateController@apply` — `app/Http/Controllers/Operations/RosterTemplateController.php:156` — middleware `web, auth, permission:roster_templates.update`
- `POST operations/rostering/templates/{template}/duplicate` — `operations.rostering.templates.duplicate` — `App\Http\Controllers\Operations\RosterTemplateController@duplicate` — `app/Http/Controllers/Operations/RosterTemplateController.php:97` — middleware `web, auth, permission:roster_templates.create`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/RosterTemplateController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
