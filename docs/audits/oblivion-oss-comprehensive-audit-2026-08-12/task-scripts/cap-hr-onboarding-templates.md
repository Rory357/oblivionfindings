# CAP-HR-ONBOARDING-TEMPLATES: Onboarding templates

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.onboarding.view`, `permission:hr.onboarding.manage`
- Owning module: Human resources
- Legacy family: `HR-ONBOARDING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/onboarding` (`hr.onboarding.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.onboarding.view`, `permission:hr.onboarding.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.onboarding.view`, `permission:hr.onboarding.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/onboarding` (`hr.onboarding.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PUT hr/onboarding/templates` (`hr.onboarding.templates.update`, action `updateTemplates`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/OnboardingController.php:590-624`; FormRequest `app/Http/Requests/Hr/StoreOnboardingTemplateRequest.php:17`; `template_id`, `role`, `site_type`, `is_active`, `tasks`.
3. Invoke only the owning control for `DELETE hr/onboarding/templates/{template}` (`hr.onboarding.templates.destroy`, action `destroyTemplate`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/OnboardingController.php:668-678`; no exact validation fields extracted.
4. Invoke only the owning control for `POST hr/onboarding/templates/{template}/active` (`hr.onboarding.templates.active`, action `setTemplateActive`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/OnboardingController.php:655-666`; `is_active`.
5. Invoke only the owning control for `POST hr/onboarding/templates/{template}/duplicate` (`hr.onboarding.templates.duplicate`, action `duplicateTemplate`). Source category: **mutation outcome source gap (duplicateTemplate)**; controller `app/Http/Controllers/Hr/OnboardingController.php:626-653`; no exact validation fields extracted.

## Source-applicable states and transitions

- **updated/revised** is applicable only to `updateTemplates` / `ROUTE-1579` at `app/Http/Controllers/Hr/OnboardingController.php:590`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyTemplate` / `ROUTE-1580` at `app/Http/Controllers/Hr/OnboardingController.php:668`; it is not runtime-observed.
- **updated/revised** is applicable only to `setTemplateActive` / `ROUTE-1581` at `app/Http/Controllers/Hr/OnboardingController.php:655`; it is not runtime-observed.
- **mutation outcome source gap (duplicateTemplate)** is applicable only to `duplicateTemplate` / `ROUTE-1582` at `app/Http/Controllers/Hr/OnboardingController.php:626`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1579` / `updateTemplates`: FormRequest `app/Http/Requests/Hr/StoreOnboardingTemplateRequest.php:17`; fields `template_id`, `role`, `site_type`, `is_active`, `tasks`; success app/Http/Controllers/Hr/OnboardingController.php:611 `return redirect()->back()->with('success', 'Onboarding template updated.');`; app/Http/Controllers/Hr/OnboardingController.php:623 `return redirect()->back()->with('success', 'Onboarding template created.');`.
- `ROUTE-1580` / `destroyTemplate`: success app/Http/Controllers/Hr/OnboardingController.php:677 `return redirect()->back()->with('success', 'Template deleted.');`.
- `ROUTE-1581` / `setTemplateActive`: fields `is_active`; success app/Http/Controllers/Hr/OnboardingController.php:665 `return redirect()->back()->with('success', $validated['is_active'] ? 'Template activated.' : 'Template deactivated.');`.
- `ROUTE-1582` / `duplicateTemplate`: success app/Http/Controllers/Hr/OnboardingController.php:652 `return redirect()->back()->with('success', "Duplicated \"{$template->role}\" template.");`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/OnboardingController.php:603 `$template->update([`; app/Http/Controllers/Hr/OnboardingController.php:614 `HrOnboardingTemplate::create([`; app/Http/Controllers/Hr/OnboardingController.php:675 `$template->delete();`; app/Http/Controllers/Hr/OnboardingController.php:663 `$template->update(['is_active' => $validated['is_active'], 'updated_by' => $user->id]);`; app/Http/Controllers/Hr/OnboardingController.php:643 `HrOnboardingTemplate::create([`; responses app/Http/Controllers/Hr/OnboardingController.php:611 `return redirect()->back()->with('success', 'Onboarding template updated.');`; app/Http/Controllers/Hr/OnboardingController.php:623 `return redirect()->back()->with('success', 'Onboarding template created.');`; app/Http/Controllers/Hr/OnboardingController.php:677 `return redirect()->back()->with('success', 'Template deleted.');`; app/Http/Controllers/Hr/OnboardingController.php:665 `return redirect()->back()->with('success', $validated['is_active'] ? 'Template activated.' : 'Template deactivated.');`; app/Http/Controllers/Hr/OnboardingController.php:652 `return redirect()->back()->with('success', "Duplicated \"{$template->role}\" template.");`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `PUT hr/onboarding/templates` — `hr.onboarding.templates.update` — `App\Http\Controllers\Hr\OnboardingController@updateTemplates` — `app/Http/Controllers/Hr/OnboardingController.php:590` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`
- `DELETE hr/onboarding/templates/{template}` — `hr.onboarding.templates.destroy` — `App\Http\Controllers\Hr\OnboardingController@destroyTemplate` — `app/Http/Controllers/Hr/OnboardingController.php:668` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`
- `POST hr/onboarding/templates/{template}/active` — `hr.onboarding.templates.active` — `App\Http\Controllers\Hr\OnboardingController@setTemplateActive` — `app/Http/Controllers/Hr/OnboardingController.php:655` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`
- `POST hr/onboarding/templates/{template}/duplicate` — `hr.onboarding.templates.duplicate` — `App\Http\Controllers\Hr\OnboardingController@duplicateTemplate` — `app/Http/Controllers/Hr/OnboardingController.php:626` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/OnboardingController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
