# CAP-HR-PAYROLL-EXPORT-EXPORT-PROFILES: Payroll export profile configuration

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.payroll.view`, `permission:hr.payroll.export`
- Owning module: Human resources
- Legacy family: `HR-PAYROLL-EXPORT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/payroll` (`hr.payroll.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.payroll.view`, `permission:hr.payroll.export`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.payroll.view`, `permission:hr.payroll.export`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/payroll` (`hr.payroll.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/payroll/export-profiles` (`hr.payroll.profiles.store`, action `storeProfile`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/PayrollExportController.php:281-338`; `name`.
3. Invoke only the owning control for `PUT hr/payroll/export-profiles/{profile}` (`hr.payroll.profiles.update`, action `updateProfile`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/PayrollExportController.php:340-400`; `name`.
4. Invoke only the owning control for `POST hr/payroll/export-profiles/{profile}/set-default` (`hr.payroll.profiles.set-default`, action `setDefaultProfile`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/PayrollExportController.php:402-421`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeProfile` / `ROUTE-1586` at `app/Http/Controllers/Hr/PayrollExportController.php:281`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateProfile` / `ROUTE-1587` at `app/Http/Controllers/Hr/PayrollExportController.php:340`; it is not runtime-observed.
- **updated/revised** is applicable only to `setDefaultProfile` / `ROUTE-1588` at `app/Http/Controllers/Hr/PayrollExportController.php:402`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1586` / `storeProfile`: fields `name`; success app/Http/Controllers/Hr/PayrollExportController.php:337 `return redirect()->back()->with('success', 'Payroll export profile created.');`; failure app/Http/Controllers/Hr/PayrollExportController.php:309 `return redirect()->back()->withErrors([`.
- `ROUTE-1587` / `updateProfile`: fields `name`; success app/Http/Controllers/Hr/PayrollExportController.php:399 `return redirect()->back()->with('success', 'Payroll export profile updated.');`; failure app/Http/Controllers/Hr/PayrollExportController.php:381 `return redirect()->back()->withErrors([`.
- `ROUTE-1588` / `setDefaultProfile`: success app/Http/Controllers/Hr/PayrollExportController.php:420 `return redirect()->back()->with('success', 'Default payroll export profile updated.');`.

## Failure and recovery paths

- `storeProfile`: app/Http/Controllers/Hr/PayrollExportController.php:309 `return redirect()->back()->withErrors([`.
- `updateProfile`: app/Http/Controllers/Hr/PayrollExportController.php:381 `return redirect()->back()->withErrors([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/PayrollExportController.php:318 `->update(['is_default' => false]);`; app/Http/Controllers/Hr/PayrollExportController.php:321 `HrPayrollExportProfile::query()->create([`; app/Http/Controllers/Hr/PayrollExportController.php:393 `->update(['is_default' => false]);`; app/Http/Controllers/Hr/PayrollExportController.php:396 `$profile->update($updatePayload);`; app/Http/Controllers/Hr/PayrollExportController.php:412 `->update(['is_default' => false]);`; app/Http/Controllers/Hr/PayrollExportController.php:414 `$profile->update([`; responses app/Http/Controllers/Hr/PayrollExportController.php:309 `return redirect()->back()->withErrors([`; app/Http/Controllers/Hr/PayrollExportController.php:337 `return redirect()->back()->with('success', 'Payroll export profile created.');`; app/Http/Controllers/Hr/PayrollExportController.php:381 `return redirect()->back()->withErrors([`; app/Http/Controllers/Hr/PayrollExportController.php:399 `return redirect()->back()->with('success', 'Payroll export profile updated.');`; app/Http/Controllers/Hr/PayrollExportController.php:420 `return redirect()->back()->with('success', 'Default payroll export profile updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/payroll/export-profiles` — `hr.payroll.profiles.store` — `App\Http\Controllers\Hr\PayrollExportController@storeProfile` — `app/Http/Controllers/Hr/PayrollExportController.php:281` — middleware `web, auth, permission:hr.payroll.view, permission:hr.payroll.export`
- `PUT hr/payroll/export-profiles/{profile}` — `hr.payroll.profiles.update` — `App\Http\Controllers\Hr\PayrollExportController@updateProfile` — `app/Http/Controllers/Hr/PayrollExportController.php:340` — middleware `web, auth, permission:hr.payroll.view, permission:hr.payroll.export`
- `POST hr/payroll/export-profiles/{profile}/set-default` — `hr.payroll.profiles.set-default` — `App\Http\Controllers\Hr\PayrollExportController@setDefaultProfile` — `app/Http/Controllers/Hr/PayrollExportController.php:402` — middleware `web, auth, permission:hr.payroll.view, permission:hr.payroll.export`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/PayrollExportController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
