# CAP-SITE-SITE-COMPLIANCE-COVERAGE-STAFF: Site coverage and staff compliance requirements

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.update`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-COMPLIANCE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/compliance` (`sites.compliance.dashboard`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.update`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/compliance` (`sites.compliance.dashboard`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST sites/{site}/coverage-requirements` (`sites.coverage_requirements.store`, action `storeCoverageRequirement`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteComplianceController.php:273-314`; `name`, `coverage_type`, `day_of_week`, `starts_time`, `ends_time`, `minimum_staff`, `service_context_id`, `preferred_client_id`, `role_requirements`, `allow_overstaffing`, `shift_type`, `notes`.
3. Invoke only the owning control for `DELETE sites/{site}/coverage-requirements/{requirement}` (`sites.coverage_requirements.destroy`, action `destroyCoverageRequirement`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Sites/SiteComplianceController.php:356-365`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT sites/{site}/coverage-requirements/{requirement}` (`sites.coverage_requirements.update`, action `updateCoverageRequirement`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteComplianceController.php:316-354`; `name`, `coverage_type`, `day_of_week`, `starts_time`, `ends_time`, `minimum_staff`, `service_context_id`, `preferred_client_id`, `role_requirements`, `allow_overstaffing`, `shift_type`, `notes`, `is_active`.
5. Invoke only the owning control for `POST sites/{site}/staff-requirements` (`sites.staff_requirements.store`, action `storeStaffRequirement`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteComplianceController.php:221-240`; `requirement_name`, `category`, `description`, `certification_required`, `expiry_period_months`.
6. Invoke only the owning control for `DELETE sites/{site}/staff-requirements/{requirement}` (`sites.staff_requirements.destroy`, action `destroyStaffRequirement`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Sites/SiteComplianceController.php:262-271`; no exact validation fields extracted.
7. Invoke only the owning control for `PUT sites/{site}/staff-requirements/{requirement}` (`sites.staff_requirements.update`, action `updateStaffRequirement`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteComplianceController.php:242-260`; `requirement_name`, `category`, `description`, `certification_required`, `expiry_period_months`, `is_active`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeCoverageRequirement` / `ROUTE-2760` at `app/Http/Controllers/Sites/SiteComplianceController.php:273`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyCoverageRequirement` / `ROUTE-2761` at `app/Http/Controllers/Sites/SiteComplianceController.php:356`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateCoverageRequirement` / `ROUTE-2762` at `app/Http/Controllers/Sites/SiteComplianceController.php:316`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeStaffRequirement` / `ROUTE-2884` at `app/Http/Controllers/Sites/SiteComplianceController.php:221`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyStaffRequirement` / `ROUTE-2885` at `app/Http/Controllers/Sites/SiteComplianceController.php:262`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateStaffRequirement` / `ROUTE-2886` at `app/Http/Controllers/Sites/SiteComplianceController.php:242`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2760` / `storeCoverageRequirement`: fields `name`, `coverage_type`, `day_of_week`, `starts_time`, `ends_time`, `minimum_staff`, `service_context_id`, `preferred_client_id`, `role_requirements`, `allow_overstaffing`, `shift_type`, `notes`; success app/Http/Controllers/Sites/SiteComplianceController.php:313 `return redirect()->back()->with('success', 'Coverage requirement added successfully.');`.
- `ROUTE-2761` / `destroyCoverageRequirement`: success app/Http/Controllers/Sites/SiteComplianceController.php:364 `return redirect()->back()->with('success', 'Coverage requirement removed successfully.');`.
- `ROUTE-2762` / `updateCoverageRequirement`: fields `name`, `coverage_type`, `day_of_week`, `starts_time`, `ends_time`, `minimum_staff`, `service_context_id`, `preferred_client_id`, `role_requirements`, `allow_overstaffing`, `shift_type`, `notes`, `is_active`; success app/Http/Controllers/Sites/SiteComplianceController.php:353 `return redirect()->back()->with('success', 'Coverage requirement updated successfully.');`.
- `ROUTE-2884` / `storeStaffRequirement`: fields `requirement_name`, `category`, `description`, `certification_required`, `expiry_period_months`; success app/Http/Controllers/Sites/SiteComplianceController.php:239 `return redirect()->back()->with('success', 'Staff requirement added successfully.');`.
- `ROUTE-2885` / `destroyStaffRequirement`: success app/Http/Controllers/Sites/SiteComplianceController.php:270 `return redirect()->back()->with('success', 'Staff requirement removed successfully.');`.
- `ROUTE-2886` / `updateStaffRequirement`: fields `requirement_name`, `category`, `description`, `certification_required`, `expiry_period_months`, `is_active`; success app/Http/Controllers/Sites/SiteComplianceController.php:259 `return redirect()->back()->with('success', 'Staff requirement updated successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteComplianceController.php:305 `SiteCoverageRequirement::create([`; app/Http/Controllers/Sites/SiteComplianceController.php:362 `$requirement->delete();`; app/Http/Controllers/Sites/SiteComplianceController.php:351 `$requirement->update($validated);`; app/Http/Controllers/Sites/SiteComplianceController.php:233 `SiteStaffRequirement::create([`; app/Http/Controllers/Sites/SiteComplianceController.php:268 `$requirement->delete();`; app/Http/Controllers/Sites/SiteComplianceController.php:257 `$requirement->update($validated);`; responses app/Http/Controllers/Sites/SiteComplianceController.php:313 `return redirect()->back()->with('success', 'Coverage requirement added successfully.');`; app/Http/Controllers/Sites/SiteComplianceController.php:364 `return redirect()->back()->with('success', 'Coverage requirement removed successfully.');`; app/Http/Controllers/Sites/SiteComplianceController.php:353 `return redirect()->back()->with('success', 'Coverage requirement updated successfully.');`; app/Http/Controllers/Sites/SiteComplianceController.php:239 `return redirect()->back()->with('success', 'Staff requirement added successfully.');`; app/Http/Controllers/Sites/SiteComplianceController.php:270 `return redirect()->back()->with('success', 'Staff requirement removed successfully.');`; app/Http/Controllers/Sites/SiteComplianceController.php:259 `return redirect()->back()->with('success', 'Staff requirement updated successfully.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST sites/{site}/coverage-requirements` — `sites.coverage_requirements.store` — `App\Http\Controllers\Sites\SiteComplianceController@storeCoverageRequirement` — `app/Http/Controllers/Sites/SiteComplianceController.php:273` — middleware `web, auth, verified, permission:sites.update`
- `DELETE sites/{site}/coverage-requirements/{requirement}` — `sites.coverage_requirements.destroy` — `App\Http\Controllers\Sites\SiteComplianceController@destroyCoverageRequirement` — `app/Http/Controllers/Sites/SiteComplianceController.php:356` — middleware `web, auth, verified, permission:sites.update`
- `PUT sites/{site}/coverage-requirements/{requirement}` — `sites.coverage_requirements.update` — `App\Http\Controllers\Sites\SiteComplianceController@updateCoverageRequirement` — `app/Http/Controllers/Sites/SiteComplianceController.php:316` — middleware `web, auth, verified, permission:sites.update`
- `POST sites/{site}/staff-requirements` — `sites.staff_requirements.store` — `App\Http\Controllers\Sites\SiteComplianceController@storeStaffRequirement` — `app/Http/Controllers/Sites/SiteComplianceController.php:221` — middleware `web, auth, verified, permission:sites.update`
- `DELETE sites/{site}/staff-requirements/{requirement}` — `sites.staff_requirements.destroy` — `App\Http\Controllers\Sites\SiteComplianceController@destroyStaffRequirement` — `app/Http/Controllers/Sites/SiteComplianceController.php:262` — middleware `web, auth, verified, permission:sites.update`
- `PUT sites/{site}/staff-requirements/{requirement}` — `sites.staff_requirements.update` — `App\Http\Controllers\Sites\SiteComplianceController@updateStaffRequirement` — `app/Http/Controllers/Sites/SiteComplianceController.php:242` — middleware `web, auth, verified, permission:sites.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteComplianceController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
