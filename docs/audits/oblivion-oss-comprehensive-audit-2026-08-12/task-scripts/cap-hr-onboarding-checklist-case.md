# CAP-HR-ONBOARDING-CHECKLIST-CASE: Onboarding checklist cases and bulk control

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
2. Use `GET|HEAD hr/onboarding/{checklist}` (`hr.onboarding.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/OnboardingController.php:145-223`.
3. Use `GET|HEAD hr/onboarding/create` (`hr.onboarding.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/OnboardingController.php:240-244`.
4. Use `GET|HEAD hr/onboarding/export` (`hr.onboarding.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/OnboardingController.php:107-143`.
5. Invoke only the owning control for `POST hr/onboarding` (`hr.onboarding.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/OnboardingController.php:250-314`; FormRequest `app/Http/Requests/Hr/StoreOnboardingChecklistRequest.php:29`; `hire_mode`, `employee_profile_id`, `name`, `email`, `position_title`, `role`, `employment_type`, `primary_site_id`, `manager_user_id`, `start_date`, `template_id`, `assign_compliance`, `send_welcome_email`, `welcome_email_id`.
6. Invoke only the owning control for `DELETE hr/onboarding/{checklist}` (`hr.onboarding.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/OnboardingController.php:574-584`; no exact validation fields extracted.
7. Invoke only the owning control for `POST hr/onboarding/{checklist}/complete` (`hr.onboarding.complete`, action `completeChecklist`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Hr/OnboardingController.php:453-463`; no exact validation fields extracted.
8. Invoke only the owning control for `POST hr/onboarding/{checklist}/reassign` (`hr.onboarding.reassign`, action `reassignChecklist`). Source category: **mutation outcome source gap (reassignChecklist)**; controller `app/Http/Controllers/Hr/OnboardingController.php:558-572`; `owner_id`.
9. Invoke only the owning control for `POST hr/onboarding/{checklist}/remind` (`hr.onboarding.remind`, action `remindChecklist`). Source category: **mutation outcome source gap (remindChecklist)**; controller `app/Http/Controllers/Hr/OnboardingController.php:481-493`; no exact validation fields extracted.
10. Invoke only the owning control for `POST hr/onboarding/{checklist}/status` (`hr.onboarding.status`, action `setChecklistStatus`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/OnboardingController.php:465-479`; no exact validation fields extracted.
11. Invoke only the owning control for `POST hr/onboarding/bulk` (`hr.onboarding.bulk`, action `bulkAction`). Source category: **mutation outcome source gap (bulkAction)**; controller `app/Http/Controllers/Hr/OnboardingController.php:495-523`; `action`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1554` at `app/Http/Controllers/Hr/OnboardingController.php:43`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1555` at `app/Http/Controllers/Hr/OnboardingController.php:250`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1556` at `app/Http/Controllers/Hr/OnboardingController.php:574`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1557` at `app/Http/Controllers/Hr/OnboardingController.php:145`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `completeChecklist` / `ROUTE-1558` at `app/Http/Controllers/Hr/OnboardingController.php:453`; it is not runtime-observed.
- **mutation outcome source gap (reassignChecklist)** is applicable only to `reassignChecklist` / `ROUTE-1559` at `app/Http/Controllers/Hr/OnboardingController.php:558`; it is not runtime-observed.
- **mutation outcome source gap (remindChecklist)** is applicable only to `remindChecklist` / `ROUTE-1560` at `app/Http/Controllers/Hr/OnboardingController.php:481`; it is not runtime-observed.
- **updated/revised** is applicable only to `setChecklistStatus` / `ROUTE-1561` at `app/Http/Controllers/Hr/OnboardingController.php:465`; it is not runtime-observed.
- **mutation outcome source gap (bulkAction)** is applicable only to `bulkAction` / `ROUTE-1564` at `app/Http/Controllers/Hr/OnboardingController.php:495`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1565` at `app/Http/Controllers/Hr/OnboardingController.php:240`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-1573` at `app/Http/Controllers/Hr/OnboardingController.php:107`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/onboarding/index.tsx`, `resources/js/pages/hr/onboarding/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1555` / `store`: FormRequest `app/Http/Requests/Hr/StoreOnboardingChecklistRequest.php:29`; fields `hire_mode`, `employee_profile_id`, `name`, `email`, `position_title`, `role`, `employment_type`, `primary_site_id`, `manager_user_id`, `start_date`, `template_id`, `assign_compliance`, `send_welcome_email`, `welcome_email_id`; success app/Http/Controllers/Hr/OnboardingController.php:313 `->with('success', "Onboarding checklist created with {$checklist->tasks->count()} tasks.");`.
- `ROUTE-1556` / `destroy`: success app/Http/Controllers/Hr/OnboardingController.php:583 `return redirect()->route('hr.onboarding.index')->with('success', 'Checklist deleted.');`.
- `ROUTE-1558` / `completeChecklist`: success app/Http/Controllers/Hr/OnboardingController.php:462 `return redirect()->back()->with('success', 'Onboarding marked complete.');`.
- `ROUTE-1559` / `reassignChecklist`: fields `owner_id`; success app/Http/Controllers/Hr/OnboardingController.php:571 `return redirect()->back()->with('success', 'Checklist owner reassigned.');`.
- `ROUTE-1560` / `remindChecklist`: success app/Http/Controllers/Hr/OnboardingController.php:490 `return redirect()->back()->with('success', $count > 0`.
- `ROUTE-1561` / `setChecklistStatus`: success app/Http/Controllers/Hr/OnboardingController.php:478 `return redirect()->back()->with('success', 'Checklist updated.');`.
- `ROUTE-1564` / `bulkAction`: fields `action`; success app/Http/Controllers/Hr/OnboardingController.php:522 `return redirect()->back()->with('success', $checklists->count()." checklist(s) {$verb}.");`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/OnboardingController.php:581 `$checklist->delete();`; app/Http/Controllers/Hr/OnboardingController.php:569 `$checklist->update(['created_by' => $validated['owner_id']]);`; responses app/Http/Controllers/Hr/OnboardingController.php:83 `return Inertia::render('hr/onboarding/index', [`; app/Http/Controllers/Hr/OnboardingController.php:290 `return redirect()->back()->with('error', 'An active onboarding checklist already exists for this employee.');`; app/Http/Controllers/Hr/OnboardingController.php:300 `return redirect()->back()->with('error', $exception->getMessage());`; app/Http/Controllers/Hr/OnboardingController.php:311 `return redirect()`; app/Http/Controllers/Hr/OnboardingController.php:583 `return redirect()->route('hr.onboarding.index')->with('success', 'Checklist deleted.');`; app/Http/Controllers/Hr/OnboardingController.php:173 `return [`; app/Http/Controllers/Hr/OnboardingController.php:196 `return Inertia::render('hr/onboarding/show', [`; app/Http/Controllers/Hr/OnboardingController.php:462 `return redirect()->back()->with('success', 'Onboarding marked complete.');`; app/Http/Controllers/Hr/OnboardingController.php:571 `return redirect()->back()->with('success', 'Checklist owner reassigned.');`; app/Http/Controllers/Hr/OnboardingController.php:490 `return redirect()->back()->with('success', $count > 0`; app/Http/Controllers/Hr/OnboardingController.php:478 `return redirect()->back()->with('success', 'Checklist updated.');`; app/Http/Controllers/Hr/OnboardingController.php:522 `return redirect()->back()->with('success', $checklists->count()." checklist(s) {$verb}.");`; app/Http/Controllers/Hr/OnboardingController.php:243 `return redirect()->route('hr.onboarding.index');`; app/Http/Controllers/Hr/OnboardingController.php:125 `return response()->streamDownload(function () use ($rows) {`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Hr/OnboardingController.php:308 `SendOnboardingEmailJob::dispatch((int) $validated['welcome_email_id'], $profile->id);`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD hr/onboarding` — `hr.onboarding.index` — `App\Http\Controllers\Hr\OnboardingController@index` — `app/Http/Controllers/Hr/OnboardingController.php:43` — middleware `web, auth, permission:hr.onboarding.view`
- `POST hr/onboarding` — `hr.onboarding.store` — `App\Http\Controllers\Hr\OnboardingController@store` — `app/Http/Controllers/Hr/OnboardingController.php:250` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`
- `DELETE hr/onboarding/{checklist}` — `hr.onboarding.destroy` — `App\Http\Controllers\Hr\OnboardingController@destroy` — `app/Http/Controllers/Hr/OnboardingController.php:574` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`
- `GET|HEAD hr/onboarding/{checklist}` — `hr.onboarding.show` — `App\Http\Controllers\Hr\OnboardingController@show` — `app/Http/Controllers/Hr/OnboardingController.php:145` — middleware `web, auth, permission:hr.onboarding.view`
- `POST hr/onboarding/{checklist}/complete` — `hr.onboarding.complete` — `App\Http\Controllers\Hr\OnboardingController@completeChecklist` — `app/Http/Controllers/Hr/OnboardingController.php:453` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`
- `POST hr/onboarding/{checklist}/reassign` — `hr.onboarding.reassign` — `App\Http\Controllers\Hr\OnboardingController@reassignChecklist` — `app/Http/Controllers/Hr/OnboardingController.php:558` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`
- `POST hr/onboarding/{checklist}/remind` — `hr.onboarding.remind` — `App\Http\Controllers\Hr\OnboardingController@remindChecklist` — `app/Http/Controllers/Hr/OnboardingController.php:481` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`
- `POST hr/onboarding/{checklist}/status` — `hr.onboarding.status` — `App\Http\Controllers\Hr\OnboardingController@setChecklistStatus` — `app/Http/Controllers/Hr/OnboardingController.php:465` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`
- `POST hr/onboarding/bulk` — `hr.onboarding.bulk` — `App\Http\Controllers\Hr\OnboardingController@bulkAction` — `app/Http/Controllers/Hr/OnboardingController.php:495` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`
- `GET|HEAD hr/onboarding/create` — `hr.onboarding.create` — `App\Http\Controllers\Hr\OnboardingController@create` — `app/Http/Controllers/Hr/OnboardingController.php:240` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`
- `GET|HEAD hr/onboarding/export` — `hr.onboarding.export` — `App\Http\Controllers\Hr\OnboardingController@export` — `app/Http/Controllers/Hr/OnboardingController.php:107` — middleware `web, auth, permission:hr.onboarding.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/OnboardingController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/onboarding/index.tsx`, `resources/js/pages/hr/onboarding/show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
