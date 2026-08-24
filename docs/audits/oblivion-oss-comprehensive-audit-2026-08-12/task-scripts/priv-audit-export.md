# PRIV-AUDIT-EXPORT: Audit Export

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.reports.view`, `permission:finance.admin`
- Owning module: Privacy and compliance
- Legacy family: `PRIV-AUDIT-EXPORT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/audit-exports` (`finance.audit-exports.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.reports.view`, `permission:finance.admin`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.reports.view`, `permission:finance.admin`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/audit-exports` (`finance.audit-exports.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/audit-exports/{export}/download` (`finance.audit-exports.download`, action `download`) only from its authorised surface; inspect the returned file/report. Source: `app/Domain/Finance/Http/Controllers/AuditExportController.php:69-90`.
3. Invoke only the owning control for `POST finance/audit-exports` (`finance.audit-exports.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/AuditExportController.php:32-67`; `export_name`, `period_from`, `period_to`, `include_journals`, `include_bank_reconciliations`, `include_ap`, `include_ar`, `include_gst`, `include_fixed_assets`, `notes`.
4. Invoke only the owning control for `DELETE finance/audit-exports/{export}` (`finance.audit-exports.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Domain/Finance/Http/Controllers/AuditExportController.php:92-100`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0470` at `app/Domain/Finance/Http/Controllers/AuditExportController.php:14`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0471` at `app/Domain/Finance/Http/Controllers/AuditExportController.php:32`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0472` at `app/Domain/Finance/Http/Controllers/AuditExportController.php:92`; it is not runtime-observed.
- **file/report delivered** is applicable only to `download` / `ROUTE-0473` at `app/Domain/Finance/Http/Controllers/AuditExportController.php:69`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/audit-exports/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0471` / `store`: fields `export_name`, `period_from`, `period_to`, `include_journals`, `include_bank_reconciliations`, `include_ap`, `include_ar`, `include_gst`, `include_fixed_assets`, `notes`; success app/Domain/Finance/Http/Controllers/AuditExportController.php:66 `->with('success', 'Audit export is being generated. You will be able to download it shortly.');`.
- `ROUTE-0472` / `destroy`: success app/Domain/Finance/Http/Controllers/AuditExportController.php:99 `->with('success', 'Audit export deleted.');`.
- `ROUTE-0473` / `download`: failure app/Domain/Finance/Http/Controllers/AuditExportController.php:72 `return back()->withErrors(['export' => 'Export is not ready for download.']);`; app/Domain/Finance/Http/Controllers/AuditExportController.php:78 `return back()->withErrors(['export' => 'Export file not found. Please regenerate.']);`.

## Failure and recovery paths

- `download`: app/Domain/Finance/Http/Controllers/AuditExportController.php:72 `return back()->withErrors(['export' => 'Export is not ready for download.']);`; app/Domain/Finance/Http/Controllers/AuditExportController.php:78 `return back()->withErrors(['export' => 'Export file not found. Please regenerate.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Finance/Http/Controllers/AuditExportController.php:47 `$export = FinAuditExport::create([`; app/Domain/Finance/Http/Controllers/AuditExportController.php:96 `$export->delete();`; responses app/Domain/Finance/Http/Controllers/AuditExportController.php:24 `return Inertia::render('finance/audit-exports/Index', [`; app/Domain/Finance/Http/Controllers/AuditExportController.php:65 `return redirect()->route('finance.audit-exports.index')`; app/Domain/Finance/Http/Controllers/AuditExportController.php:98 `return redirect()->route('finance.audit-exports.index')`; app/Domain/Finance/Http/Controllers/AuditExportController.php:72 `return back()->withErrors(['export' => 'Export is not ready for download.']);`; app/Domain/Finance/Http/Controllers/AuditExportController.php:78 `return back()->withErrors(['export' => 'Export file not found. Please regenerate.']);`; app/Domain/Finance/Http/Controllers/AuditExportController.php:85 `return response()->streamDownload(`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Domain/Finance/Http/Controllers/AuditExportController.php:63 `GenerateAuditExportJob::dispatch($export->id);`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD finance/audit-exports` — `finance.audit-exports.index` — `App\Domain\Finance\Http\Controllers\AuditExportController@index` — `app/Domain/Finance/Http/Controllers/AuditExportController.php:14` — middleware `web, auth, permission:finance.reports.view`
- `POST finance/audit-exports` — `finance.audit-exports.store` — `App\Domain\Finance\Http\Controllers\AuditExportController@store` — `app/Domain/Finance/Http/Controllers/AuditExportController.php:32` — middleware `web, auth, permission:finance.admin`
- `DELETE finance/audit-exports/{export}` — `finance.audit-exports.destroy` — `App\Domain\Finance\Http\Controllers\AuditExportController@destroy` — `app/Domain/Finance/Http/Controllers/AuditExportController.php:92` — middleware `web, auth, permission:finance.admin`
- `GET|HEAD finance/audit-exports/{export}/download` — `finance.audit-exports.download` — `App\Domain\Finance\Http\Controllers\AuditExportController@download` — `app/Domain/Finance/Http/Controllers/AuditExportController.php:69` — middleware `web, auth, permission:finance.reports.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/AuditExportController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/audit-exports/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
