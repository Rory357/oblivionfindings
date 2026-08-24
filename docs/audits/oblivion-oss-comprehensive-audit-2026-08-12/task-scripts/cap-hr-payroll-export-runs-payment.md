# CAP-HR-PAYROLL-EXPORT-RUNS-PAYMENT: Payroll run export locking and payment

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
2. Use `GET|HEAD hr/payroll/runs/{run}/net-pay-file` (`hr.payroll.runs.net-pay-file`, action `downloadNetPayFile`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/PayrollExportController.php:218-236`.
3. Invoke only the owning control for `POST hr/payroll/runs` (`hr.payroll.runs.store`, action `createRun`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/PayrollExportController.php:114-141`; `period_start`.
4. Invoke only the owning control for `POST hr/payroll/runs/{run}/export` (`hr.payroll.runs.export`, action `export`). Source category: **mutation outcome source gap (export)**; controller `app/Http/Controllers/Hr/PayrollExportController.php:241-279`; `profile_id`.
5. Invoke only the owning control for `POST hr/payroll/runs/{run}/lock` (`hr.payroll.runs.lock`, action `lockRun`). Source category: **mutation outcome source gap (lockRun)**; controller `app/Http/Controllers/Hr/PayrollExportController.php:146-176`; no exact validation fields extracted.
6. Invoke only the owning control for `POST hr/payroll/runs/{run}/pay` (`hr.payroll.runs.pay`, action `payNet`). Source category: **mutation outcome source gap (payNet)**; controller `app/Http/Controllers/Hr/PayrollExportController.php:182-213`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1585` at `app/Http/Controllers/Hr/PayrollExportController.php:34`; it is not runtime-observed.
- **created/recorded** is applicable only to `createRun` / `ROUTE-1593` at `app/Http/Controllers/Hr/PayrollExportController.php:114`; it is not runtime-observed.
- **mutation outcome source gap (export)** is applicable only to `export` / `ROUTE-1594` at `app/Http/Controllers/Hr/PayrollExportController.php:241`; it is not runtime-observed.
- **mutation outcome source gap (lockRun)** is applicable only to `lockRun` / `ROUTE-1595` at `app/Http/Controllers/Hr/PayrollExportController.php:146`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadNetPayFile` / `ROUTE-1596` at `app/Http/Controllers/Hr/PayrollExportController.php:218`; it is not runtime-observed.
- **mutation outcome source gap (payNet)** is applicable only to `payNet` / `ROUTE-1597` at `app/Http/Controllers/Hr/PayrollExportController.php:182`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/payroll/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1593` / `createRun`: fields `period_start`; success app/Http/Controllers/Hr/PayrollExportController.php:140 `return redirect()->back()->with('success', 'Payroll run created.');`; failure app/Http/Controllers/Hr/PayrollExportController.php:137 `return redirect()->back()->withErrors(['period' => $e->getMessage()]);`.
- `ROUTE-1594` / `export`: fields `profile_id`; failure app/Http/Controllers/Hr/PayrollExportController.php:247 `abort(404);`; app/Http/Controllers/Hr/PayrollExportController.php:265 `return redirect()->back()->withErrors(['export' => $e->getMessage()]);`.
- `ROUTE-1595` / `lockRun`: success app/Http/Controllers/Hr/PayrollExportController.php:175 `return redirect()->back()->with('success', 'Payroll run locked.');`; failure app/Http/Controllers/Hr/PayrollExportController.php:152 `abort(404);`; app/Http/Controllers/Hr/PayrollExportController.php:157 `} catch (ValidationException $e) {`; app/Http/Controllers/Hr/PayrollExportController.php:158 `return redirect()->back()->withErrors($e->errors());`; app/Http/Controllers/Hr/PayrollExportController.php:160 `return redirect()->back()->withErrors(['lock' => $e->getMessage()]);`.
- `ROUTE-1596` / `downloadNetPayFile`: failure app/Http/Controllers/Hr/PayrollExportController.php:224 `abort(404);`.
- `ROUTE-1597` / `payNet`: success app/Http/Controllers/Hr/PayrollExportController.php:212 `return redirect()->back()->with('success', 'Net pay disbursed and payslips marked paid.');`; failure app/Http/Controllers/Hr/PayrollExportController.php:188 `abort(404);`.

## Failure and recovery paths

- `createRun`: app/Http/Controllers/Hr/PayrollExportController.php:137 `return redirect()->back()->withErrors(['period' => $e->getMessage()]);`.
- `export`: app/Http/Controllers/Hr/PayrollExportController.php:247 `abort(404);`; app/Http/Controllers/Hr/PayrollExportController.php:265 `return redirect()->back()->withErrors(['export' => $e->getMessage()]);`.
- `lockRun`: app/Http/Controllers/Hr/PayrollExportController.php:152 `abort(404);`; app/Http/Controllers/Hr/PayrollExportController.php:157 `} catch (ValidationException $e) {`; app/Http/Controllers/Hr/PayrollExportController.php:158 `return redirect()->back()->withErrors($e->errors());`; app/Http/Controllers/Hr/PayrollExportController.php:160 `return redirect()->back()->withErrors(['lock' => $e->getMessage()]);`.
- `downloadNetPayFile`: app/Http/Controllers/Hr/PayrollExportController.php:224 `abort(404);`.
- `payNet`: app/Http/Controllers/Hr/PayrollExportController.php:188 `abort(404);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/PayrollExportController.php:134 `$run->update(['notes' => $data['notes']]);`; responses app/Http/Controllers/Hr/PayrollExportController.php:97 `return Inertia::render('hr/payroll/index', [`; app/Http/Controllers/Hr/PayrollExportController.php:137 `return redirect()->back()->withErrors(['period' => $e->getMessage()]);`; app/Http/Controllers/Hr/PayrollExportController.php:140 `return redirect()->back()->with('success', 'Payroll run created.');`; app/Http/Controllers/Hr/PayrollExportController.php:265 `return redirect()->back()->withErrors(['export' => $e->getMessage()]);`; app/Http/Controllers/Hr/PayrollExportController.php:276 `return Storage::disk('private')->download($path, basename($path), [`; app/Http/Controllers/Hr/PayrollExportController.php:158 `return redirect()->back()->withErrors($e->errors());`; app/Http/Controllers/Hr/PayrollExportController.php:160 `return redirect()->back()->withErrors(['lock' => $e->getMessage()]);`; app/Http/Controllers/Hr/PayrollExportController.php:175 `return redirect()->back()->with('success', 'Payroll run locked.');`; app/Http/Controllers/Hr/PayrollExportController.php:231 `return response()->streamDownload(`; app/Http/Controllers/Hr/PayrollExportController.php:192 `return redirect()->back()->with('error', 'Post the payroll run to the GL before paying net pay.');`; app/Http/Controllers/Hr/PayrollExportController.php:196 `return redirect()->back()->with('error', 'Net pay for this run has already been paid.');`; app/Http/Controllers/Hr/PayrollExportController.php:202 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/PayrollExportController.php:212 `return redirect()->back()->with('success', 'Net pay disbursed and payslips marked paid.');`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Hr/PayrollExportController.php:164 `PostPayrollJournalJob::dispatch($run);`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD hr/payroll` — `hr.payroll.index` — `App\Http\Controllers\Hr\PayrollExportController@index` — `app/Http/Controllers/Hr/PayrollExportController.php:34` — middleware `web, auth, permission:hr.payroll.view`
- `POST hr/payroll/runs` — `hr.payroll.runs.store` — `App\Http\Controllers\Hr\PayrollExportController@createRun` — `app/Http/Controllers/Hr/PayrollExportController.php:114` — middleware `web, auth, permission:hr.payroll.view, permission:hr.payroll.export`
- `POST hr/payroll/runs/{run}/export` — `hr.payroll.runs.export` — `App\Http\Controllers\Hr\PayrollExportController@export` — `app/Http/Controllers/Hr/PayrollExportController.php:241` — middleware `web, auth, permission:hr.payroll.view, permission:hr.payroll.export`
- `POST hr/payroll/runs/{run}/lock` — `hr.payroll.runs.lock` — `App\Http\Controllers\Hr\PayrollExportController@lockRun` — `app/Http/Controllers/Hr/PayrollExportController.php:146` — middleware `web, auth, permission:hr.payroll.view, permission:hr.payroll.export`
- `GET|HEAD hr/payroll/runs/{run}/net-pay-file` — `hr.payroll.runs.net-pay-file` — `App\Http\Controllers\Hr\PayrollExportController@downloadNetPayFile` — `app/Http/Controllers/Hr/PayrollExportController.php:218` — middleware `web, auth, permission:hr.payroll.view, permission:hr.payroll.export`
- `POST hr/payroll/runs/{run}/pay` — `hr.payroll.runs.pay` — `App\Http\Controllers\Hr\PayrollExportController@payNet` — `app/Http/Controllers/Hr/PayrollExportController.php:182` — middleware `web, auth, permission:hr.payroll.view, permission:hr.payroll.export`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/PayrollExportController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/payroll/index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
