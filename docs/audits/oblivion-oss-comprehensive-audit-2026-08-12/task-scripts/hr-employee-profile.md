# HR-EMPLOYEE-PROFILE: Employee Profile

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.employees.viewAny`, `permission:hr.employees.manage`
- Owning module: Human resources
- Legacy family: `HR-EMPLOYEE-PROFILE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/people` (`hr.people.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.employees.viewAny`, `permission:hr.employees.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.employees.viewAny`, `permission:hr.employees.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/people` (`hr.people.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/people/{profile}` (`hr.people.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/EmployeeProfileController.php:644-960`.
3. Use `GET|HEAD hr/people/{profile}/edit` (`hr.people.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/EmployeeProfileController.php:996-1027`.
4. Invoke only the owning control for `POST hr/people` (`hr.people.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/EmployeeProfileController.php:474-528`; FormRequest `app/Http/Requests/Hr/StoreEmployeeRequest.php:15`; `name`, `email`, `preferred_name`, `role`, `position_id`, `position_title`, `employment_type`, `department`, `primary_site_id`, `manager_user_id`, `start_date`, `work_phone`, `work_rights_status`, `visa_type`, `visa_expires_at`, `emergency_contacts`, `start_onboarding`, `send_invite`, `link_existing`.
5. Invoke only the owning control for `PUT hr/people/{profile}` (`hr.people.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/EmployeeProfileController.php:1033-1042`; FormRequest `app/Http/Requests/Hr/UpdateEmployeeProfileRequest.php:15`; `employee_number`, `date_of_birth`, `gender`, `ethnicity`, `work_rights_status`, `visa_type`, `visa_expires_at`, `personal_email`, `personal_phone`, `home_address`, `work_email`, `work_phone`, `position_title`, `position_role`, `employment_type`, `contract_type`, `hours_per_week`, `hourly_rate`, `annual_salary`, `pay_frequency`, `start_date`, `end_date`, `probation_end_date`, `termination_reason`, `is_active`, `primary_site_id`, `secondary_site_ids`, `emergency_contacts`, `bank_account`, `ird_number`, `tax_code`, `kiwisaver_rate`, `can_drive_clients`, `is_first_aider`, `is_fire_warden`, `notes`, `restricted_notes`.
6. Invoke only the owning control for `PATCH hr/people/{profile}/active` (`hr.people.active`, action `setActive`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/EmployeeProfileController.php:534-557`; `is_active`.
7. Invoke only the owning control for `POST hr/people/{profile}/invite` (`hr.people.invite`, action `resendInvite`). Source category: **mutation outcome source gap (resendInvite)**; controller `app/Http/Controllers/Hr/EmployeeProfileController.php:454-468`; no exact validation fields extracted.
8. Invoke only the owning control for `POST hr/people/{profile}/rehire` (`hr.people.rehire`, action `rehire`). Source category: **mutation outcome source gap (rehire)**; controller `app/Http/Controllers/Hr/EmployeeProfileController.php:563-602`; `start_date`.
9. Invoke only the owning control for `POST hr/people/bulk` (`hr.people.bulk`, action `bulkAction`). Source category: **mutation outcome source gap (bulkAction)**; controller `app/Http/Controllers/Hr/EmployeeProfileController.php:608-638`; `action`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1598` at `app/Http/Controllers/Hr/EmployeeProfileController.php:49`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1599` at `app/Http/Controllers/Hr/EmployeeProfileController.php:474`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1600` at `app/Http/Controllers/Hr/EmployeeProfileController.php:644`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1601` at `app/Http/Controllers/Hr/EmployeeProfileController.php:1033`; it is not runtime-observed.
- **updated/revised** is applicable only to `setActive` / `ROUTE-1602` at `app/Http/Controllers/Hr/EmployeeProfileController.php:534`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-1608` at `app/Http/Controllers/Hr/EmployeeProfileController.php:996`; it is not runtime-observed.
- **mutation outcome source gap (resendInvite)** is applicable only to `resendInvite` / `ROUTE-1609` at `app/Http/Controllers/Hr/EmployeeProfileController.php:454`; it is not runtime-observed.
- **mutation outcome source gap (rehire)** is applicable only to `rehire` / `ROUTE-1610` at `app/Http/Controllers/Hr/EmployeeProfileController.php:563`; it is not runtime-observed.
- **mutation outcome source gap (bulkAction)** is applicable only to `bulkAction` / `ROUTE-1611` at `app/Http/Controllers/Hr/EmployeeProfileController.php:608`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/employees/edit.tsx`, `resources/js/pages/hr/employees/index.tsx`, `resources/js/pages/hr/employees/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1599` / `store`: FormRequest `app/Http/Requests/Hr/StoreEmployeeRequest.php:15`; fields `name`, `email`, `preferred_name`, `role`, `position_id`, `position_title`, `employment_type`, `department`, `primary_site_id`, `manager_user_id`, `start_date`, `work_phone`, `work_rights_status`, `visa_type`, `visa_expires_at`, `emergency_contacts`, `start_onboarding`, `send_invite`, `link_existing`; success app/Http/Controllers/Hr/EmployeeProfileController.php:527 `->with('success', "{$data['name']} has been added to your team.");`; failure app/Http/Controllers/Hr/EmployeeProfileController.php:493 `->withErrors([`.
- `ROUTE-1601` / `update`: FormRequest `app/Http/Requests/Hr/UpdateEmployeeProfileRequest.php:15`; fields `employee_number`, `date_of_birth`, `gender`, `ethnicity`, `work_rights_status`, `visa_type`, `visa_expires_at`, `personal_email`, `personal_phone`, `home_address`, `work_email`, `work_phone`, `position_title`, `position_role`, `employment_type`, `contract_type`, `hours_per_week`, `hourly_rate`, `annual_salary`, `pay_frequency`, `start_date`, `end_date`, `probation_end_date`, `termination_reason`, `is_active`, `primary_site_id`, `secondary_site_ids`, `emergency_contacts`, `bank_account`, `ird_number`, `tax_code`, `kiwisaver_rate`, `can_drive_clients`, `is_first_aider`, `is_fire_warden`, `notes`, `restricted_notes`; success app/Http/Controllers/Hr/EmployeeProfileController.php:1041 `return redirect()->back()->with('success', 'Employee profile updated successfully.');`.
- `ROUTE-1602` / `setActive`: fields `is_active`.
- `ROUTE-1609` / `resendInvite`: success app/Http/Controllers/Hr/EmployeeProfileController.php:467 `return back()->with('success', "Login invite sent to {$account->name}.");`.
- `ROUTE-1610` / `rehire`: fields `start_date`; success app/Http/Controllers/Hr/EmployeeProfileController.php:601 `return back()->with('success', "{$profile->user?->name} has been re-hired — welcome back!");`.
- `ROUTE-1611` / `bulkAction`: fields `action`; success app/Http/Controllers/Hr/EmployeeProfileController.php:637 `return back()->with('success', "{$count} " . ($count === 1 ? 'person' : 'people') . ' updated.');`.

## Failure and recovery paths

- `store`: app/Http/Controllers/Hr/EmployeeProfileController.php:493 `->withErrors([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/EmployeeProfileController.php:1039 `$profile->update($validated);`; app/Http/Controllers/Hr/EmployeeProfileController.php:542 `$profile->update(['is_active' => $data['is_active']]);`; app/Http/Controllers/Hr/EmployeeProfileController.php:548 `$profile->user->forceFill(['approved_at' => now()])->save();`; app/Http/Controllers/Hr/EmployeeProfileController.php:624 `'deactivate' => $query->update(['is_active' => false]),`; app/Http/Controllers/Hr/EmployeeProfileController.php:625 `'reactivate' => $query->update(['is_active' => true]),`; app/Http/Controllers/Hr/EmployeeProfileController.php:626 `'assign_site' => $query->update(['primary_site_id' => $data['site_id']]),`; app/Http/Controllers/Hr/EmployeeProfileController.php:627 `'assign_department' => $query->update([`; app/Http/Controllers/Hr/EmployeeProfileController.php:633 `'assign_manager' => $query->update(['manager_user_id' => $data['manager_user_id']]),`; responses app/Http/Controllers/Hr/EmployeeProfileController.php:141 `return [`; app/Http/Controllers/Hr/EmployeeProfileController.php:330 `return Inertia::render('hr/employees/index', [`; app/Http/Controllers/Hr/EmployeeProfileController.php:491 `return back()`; app/Http/Controllers/Hr/EmployeeProfileController.php:525 `return redirect()`; app/Http/Controllers/Hr/EmployeeProfileController.php:887 `return Inertia::render('hr/employees/show', [`; app/Http/Controllers/Hr/EmployeeProfileController.php:1041 `return redirect()->back()->with('success', 'Employee profile updated successfully.');`; app/Http/Controllers/Hr/EmployeeProfileController.php:551 `return back()->with(`; app/Http/Controllers/Hr/EmployeeProfileController.php:1018 `return Inertia::render('hr/employees/edit', [`; app/Http/Controllers/Hr/EmployeeProfileController.php:460 `return back()->with('error', 'This employee has no login account to invite.');`; app/Http/Controllers/Hr/EmployeeProfileController.php:467 `return back()->with('success', "Login invite sent to {$account->name}.");`; app/Http/Controllers/Hr/EmployeeProfileController.php:570 `return back()->with('error', "{$profile->user?->name} is already active — nothing to re-hire.");`; app/Http/Controllers/Hr/EmployeeProfileController.php:598 `return back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/EmployeeProfileController.php:601 `return back()->with('success', "{$profile->user?->name} has been re-hired — welcome back!");`; app/Http/Controllers/Hr/EmployeeProfileController.php:637 `return back()->with('success', "{$count} " . ($count === 1 ? 'person' : 'people') . ' updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/people` — `hr.people.index` — `App\Http\Controllers\Hr\EmployeeProfileController@index` — `app/Http/Controllers/Hr/EmployeeProfileController.php:49` — middleware `web, auth, permission:hr.employees.viewAny`
- `POST hr/people` — `hr.people.store` — `App\Http\Controllers\Hr\EmployeeProfileController@store` — `app/Http/Controllers/Hr/EmployeeProfileController.php:474` — middleware `web, auth, permission:hr.employees.viewAny, permission:hr.employees.manage`
- `GET|HEAD hr/people/{profile}` — `hr.people.show` — `App\Http\Controllers\Hr\EmployeeProfileController@show` — `app/Http/Controllers/Hr/EmployeeProfileController.php:644` — middleware `web, auth, permission:hr.employees.viewAny`
- `PUT hr/people/{profile}` — `hr.people.update` — `App\Http\Controllers\Hr\EmployeeProfileController@update` — `app/Http/Controllers/Hr/EmployeeProfileController.php:1033` — middleware `web, auth, permission:hr.employees.viewAny, permission:hr.employees.manage`
- `PATCH hr/people/{profile}/active` — `hr.people.active` — `App\Http\Controllers\Hr\EmployeeProfileController@setActive` — `app/Http/Controllers/Hr/EmployeeProfileController.php:534` — middleware `web, auth, permission:hr.employees.viewAny, permission:hr.employees.manage`
- `GET|HEAD hr/people/{profile}/edit` — `hr.people.edit` — `App\Http\Controllers\Hr\EmployeeProfileController@edit` — `app/Http/Controllers/Hr/EmployeeProfileController.php:996` — middleware `web, auth, permission:hr.employees.viewAny, permission:hr.employees.manage`
- `POST hr/people/{profile}/invite` — `hr.people.invite` — `App\Http\Controllers\Hr\EmployeeProfileController@resendInvite` — `app/Http/Controllers/Hr/EmployeeProfileController.php:454` — middleware `web, auth, permission:hr.employees.viewAny, permission:hr.employees.manage`
- `POST hr/people/{profile}/rehire` — `hr.people.rehire` — `App\Http\Controllers\Hr\EmployeeProfileController@rehire` — `app/Http/Controllers/Hr/EmployeeProfileController.php:563` — middleware `web, auth, permission:hr.employees.viewAny, permission:hr.employees.manage`
- `POST hr/people/bulk` — `hr.people.bulk` — `App\Http\Controllers\Hr\EmployeeProfileController@bulkAction` — `app/Http/Controllers/Hr/EmployeeProfileController.php:608` — middleware `web, auth, permission:hr.employees.viewAny, permission:hr.employees.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/EmployeeProfileController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/employees/edit.tsx`, `resources/js/pages/hr/employees/index.tsx`, `resources/js/pages/hr/employees/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
