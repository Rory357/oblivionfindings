# HR-PAYSLIP: Payslip

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.payslips.view`, `permission:hr.payslips.generate`
- Owning module: Human resources
- Legacy family: `HR-PAYSLIP`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/my/payslips` (`hr.my.payslips`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.payslips.view`, `permission:hr.payslips.generate`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.payslips.view`, `permission:hr.payslips.generate`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/my/payslips` (`hr.my.payslips`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/my/payslips/{payslip}` (`hr.my.payslips.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/PayslipController.php:121-159`.
3. Use `GET|HEAD hr/my/payslips/{payslip}/download` (`hr.my.payslips.download`, action `download`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/PayslipController.php:164-188`.
4. Use `GET|HEAD hr/payroll/payslips` (`hr.payslips.index`, action `index`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/PayslipController.php:28-62`.
5. Use `GET|HEAD hr/payroll/payslips/{payslip}` (`hr.payslips.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/PayslipController.php:121-159`.
6. Use `GET|HEAD hr/payroll/payslips/{payslip}/download` (`hr.payslips.download`, action `download`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/PayslipController.php:164-188`.
7. Invoke only the owning control for `POST hr/payroll/payslips/generate` (`hr.payslips.generate`, action `generate`). Source category: **mutation outcome source gap (generate)**; controller `app/Http/Controllers/Hr/PayslipController.php:67-116`; `period_start`.

## Source-applicable states and transitions

- **information presented** is applicable only to `myPayslips` / `ROUTE-1532` at `app/Http/Controllers/Hr/PayslipController.php:193`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1533` at `app/Http/Controllers/Hr/PayslipController.php:121`; it is not runtime-observed.
- **file/report delivered** is applicable only to `download` / `ROUTE-1534` at `app/Http/Controllers/Hr/PayslipController.php:164`; it is not runtime-observed.
- **information presented** is applicable only to `index` / `ROUTE-1589` at `app/Http/Controllers/Hr/PayslipController.php:28`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1590` at `app/Http/Controllers/Hr/PayslipController.php:121`; it is not runtime-observed.
- **file/report delivered** is applicable only to `download` / `ROUTE-1591` at `app/Http/Controllers/Hr/PayslipController.php:164`; it is not runtime-observed.
- **mutation outcome source gap (generate)** is applicable only to `generate` / `ROUTE-1592` at `app/Http/Controllers/Hr/PayslipController.php:67`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/my/payslips.tsx`, `resources/js/pages/hr/payroll/payslip-detail.tsx`, `resources/js/pages/hr/payroll/payslips.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1534` / `download`: failure app/Http/Controllers/Hr/PayslipController.php:180 `abort(404, 'Payslip document not found.');`.
- `ROUTE-1591` / `download`: failure app/Http/Controllers/Hr/PayslipController.php:180 `abort(404, 'Payslip document not found.');`.
- `ROUTE-1592` / `generate`: fields `period_start`; success app/Http/Controllers/Hr/PayslipController.php:115 `return redirect()->back()->with('success', "{$count} payslip(s) generated successfully.");`; failure app/Http/Controllers/Hr/PayslipController.php:112 `return redirect()->back()->withErrors(['generate' => $e->getMessage()]);`.

## Failure and recovery paths

- `download`: app/Http/Controllers/Hr/PayslipController.php:180 `abort(404, 'Payslip document not found.');`.
- `download`: app/Http/Controllers/Hr/PayslipController.php:180 `abort(404, 'Payslip document not found.');`.
- `generate`: app/Http/Controllers/Hr/PayslipController.php:112 `return redirect()->back()->withErrors(['generate' => $e->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/PayslipController.php:204 `return Inertia::render('hr/my/payslips', [`; app/Http/Controllers/Hr/PayslipController.php:155 `return Inertia::render('hr/payroll/payslip-detail', [`; app/Http/Controllers/Hr/PayslipController.php:183 `return Storage::disk('private')->download(`; app/Http/Controllers/Hr/PayslipController.php:49 `return Inertia::render('hr/payroll/payslips', [`; app/Http/Controllers/Hr/PayslipController.php:112 `return redirect()->back()->withErrors(['generate' => $e->getMessage()]);`; app/Http/Controllers/Hr/PayslipController.php:115 `return redirect()->back()->with('success', "{$count} payslip(s) generated successfully.");`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/my/payslips` — `hr.my.payslips` — `App\Http\Controllers\Hr\PayslipController@myPayslips` — `app/Http/Controllers/Hr/PayslipController.php:193` — middleware `web, auth`
- `GET|HEAD hr/my/payslips/{payslip}` — `hr.my.payslips.show` — `App\Http\Controllers\Hr\PayslipController@show` — `app/Http/Controllers/Hr/PayslipController.php:121` — middleware `web, auth`
- `GET|HEAD hr/my/payslips/{payslip}/download` — `hr.my.payslips.download` — `App\Http\Controllers\Hr\PayslipController@download` — `app/Http/Controllers/Hr/PayslipController.php:164` — middleware `web, auth`
- `GET|HEAD hr/payroll/payslips` — `hr.payslips.index` — `App\Http\Controllers\Hr\PayslipController@index` — `app/Http/Controllers/Hr/PayslipController.php:28` — middleware `web, auth, permission:hr.payslips.view`
- `GET|HEAD hr/payroll/payslips/{payslip}` — `hr.payslips.show` — `App\Http\Controllers\Hr\PayslipController@show` — `app/Http/Controllers/Hr/PayslipController.php:121` — middleware `web, auth, permission:hr.payslips.view`
- `GET|HEAD hr/payroll/payslips/{payslip}/download` — `hr.payslips.download` — `App\Http\Controllers\Hr\PayslipController@download` — `app/Http/Controllers/Hr/PayslipController.php:164` — middleware `web, auth, permission:hr.payslips.view`
- `POST hr/payroll/payslips/generate` — `hr.payslips.generate` — `App\Http\Controllers\Hr\PayslipController@generate` — `app/Http/Controllers/Hr/PayslipController.php:67` — middleware `web, auth, permission:hr.payslips.view, permission:hr.payslips.generate`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/PayslipController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/my/payslips.tsx`, `resources/js/pages/hr/payroll/payslip-detail.tsx`, `resources/js/pages/hr/payroll/payslips.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
