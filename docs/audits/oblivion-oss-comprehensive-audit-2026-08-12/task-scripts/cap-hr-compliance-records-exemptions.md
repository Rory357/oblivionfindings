# CAP-HR-COMPLIANCE-RECORDS-EXEMPTIONS: Staff compliance records evidence and exemptions

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.compliance.view`, `permission:hr.compliance.manage`
- Owning module: Human resources
- Legacy family: `HR-COMPLIANCE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/compliance` (`hr.compliance.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.compliance.view`, `permission:hr.compliance.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.compliance.view`, `permission:hr.compliance.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/compliance` (`hr.compliance.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/compliance/staff/{staff}` (`hr.compliance.staff`, action `staffDetail`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/ComplianceController.php:239-369`.
3. Use `GET|HEAD hr/compliance/status/{status}/evidence` (`hr.compliance.status.evidence`, action `evidence`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/ComplianceController.php:501-516`.
4. Invoke only the owning control for `POST hr/compliance/assign` (`hr.compliance.assign`, action `assign`). Source category: **assigned**; controller `app/Http/Controllers/Hr/ComplianceController.php:637-673`; `requirement_ids`.
5. Invoke only the owning control for `POST hr/compliance/bulk-exempt` (`hr.compliance.bulk.exempt`, action `bulkExempt`). Source category: **mutation outcome source gap (bulkExempt)**; controller `app/Http/Controllers/Hr/ComplianceController.php:595-631`; `user_ids`.
6. Invoke only the owning control for `POST hr/compliance/bulk-record` (`hr.compliance.bulk.record`, action `bulkRecord`). Source category: **mutation outcome source gap (bulkRecord)**; controller `app/Http/Controllers/Hr/ComplianceController.php:554-593`; `user_ids`.
7. Invoke only the owning control for `POST hr/compliance/staff/{staff}/status` (`hr.compliance.status.store`, action `storeStatus`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/ComplianceController.php:375-403`; no exact validation fields extracted.
8. Invoke only the owning control for `PUT hr/compliance/status/{status}` (`hr.compliance.status.update`, action `updateStatus`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/ComplianceController.php:405-417`; no exact validation fields extracted.
9. Invoke only the owning control for `POST hr/compliance/status/{status}/exempt` (`hr.compliance.status.exempt`, action `exempt`). Source category: **mutation outcome source gap (exempt)**; controller `app/Http/Controllers/Hr/ComplianceController.php:467-495`; `exemption_reason`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1352` at `app/Http/Controllers/Hr/ComplianceController.php:42`; it is not runtime-observed.
- **assigned** is applicable only to `assign` / `ROUTE-1353` at `app/Http/Controllers/Hr/ComplianceController.php:637`; it is not runtime-observed.
- **mutation outcome source gap (bulkExempt)** is applicable only to `bulkExempt` / `ROUTE-1354` at `app/Http/Controllers/Hr/ComplianceController.php:595`; it is not runtime-observed.
- **mutation outcome source gap (bulkRecord)** is applicable only to `bulkRecord` / `ROUTE-1355` at `app/Http/Controllers/Hr/ComplianceController.php:554`; it is not runtime-observed.
- **information presented** is applicable only to `staffDetail` / `ROUTE-1372` at `app/Http/Controllers/Hr/ComplianceController.php:239`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeStatus` / `ROUTE-1373` at `app/Http/Controllers/Hr/ComplianceController.php:375`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateStatus` / `ROUTE-1374` at `app/Http/Controllers/Hr/ComplianceController.php:405`; it is not runtime-observed.
- **information presented** is applicable only to `evidence` / `ROUTE-1375` at `app/Http/Controllers/Hr/ComplianceController.php:501`; it is not runtime-observed.
- **mutation outcome source gap (exempt)** is applicable only to `exempt` / `ROUTE-1376` at `app/Http/Controllers/Hr/ComplianceController.php:467`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/compliance/index.tsx`, `resources/js/pages/hr/compliance/staff-detail.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1353` / `assign`: fields `requirement_ids`; success app/Http/Controllers/Hr/ComplianceController.php:672 `return redirect()->back()->with('success', "Assigned across {$count} role/site combinations.");`.
- `ROUTE-1354` / `bulkExempt`: fields `user_ids`; success app/Http/Controllers/Hr/ComplianceController.php:630 `return redirect()->back()->with('success', "Waiver applied to {$affected} staff.");`.
- `ROUTE-1355` / `bulkRecord`: fields `user_ids`; success app/Http/Controllers/Hr/ComplianceController.php:592 `return redirect()->back()->with('success', "Compliance recorded for {$affected} staff.");`.
- `ROUTE-1372` / `staffDetail`: failure app/Http/Controllers/Hr/ComplianceController.php:250 `abort(404);`.
- `ROUTE-1373` / `storeStatus`: success app/Http/Controllers/Hr/ComplianceController.php:402 `return redirect()->back()->with('success', "Compliance recorded for {$staff->name}.");`.
- `ROUTE-1374` / `updateStatus`: success app/Http/Controllers/Hr/ComplianceController.php:416 `return redirect()->back()->with('success', 'Compliance status updated.');`.
- `ROUTE-1376` / `exempt`: fields `exemption_reason`; success app/Http/Controllers/Hr/ComplianceController.php:494 `return redirect()->back()->with('success', 'Exemption recorded and hard-stop lifted.');`.

## Failure and recovery paths

- `staffDetail`: app/Http/Controllers/Hr/ComplianceController.php:250 `abort(404);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/ComplianceController.php:658 `\App\Domain\Hr\Models\HrComplianceMatrix::updateOrCreate(`; app/Http/Controllers/Hr/ComplianceController.php:626 `$status->save();`; app/Http/Controllers/Hr/ComplianceController.php:588 `$status->save();`; app/Http/Controllers/Hr/ComplianceController.php:400 `$status->save();`; app/Http/Controllers/Hr/ComplianceController.php:414 `$status->save();`; app/Http/Controllers/Hr/ComplianceController.php:481 `$status->update([`; responses app/Http/Controllers/Hr/ComplianceController.php:127 `return [`; app/Http/Controllers/Hr/ComplianceController.php:148 `return Inertia::render('hr/compliance/index', [`; app/Http/Controllers/Hr/ComplianceController.php:672 `return redirect()->back()->with('success', "Assigned across {$count} role/site combinations.");`; app/Http/Controllers/Hr/ComplianceController.php:630 `return redirect()->back()->with('success', "Waiver applied to {$affected} staff.");`; app/Http/Controllers/Hr/ComplianceController.php:592 `return redirect()->back()->with('success', "Compliance recorded for {$affected} staff.");`; app/Http/Controllers/Hr/ComplianceController.php:337 `return Inertia::render('hr/compliance/staff-detail', [`; app/Http/Controllers/Hr/ComplianceController.php:402 `return redirect()->back()->with('success', "Compliance recorded for {$staff->name}.");`; app/Http/Controllers/Hr/ComplianceController.php:416 `return redirect()->back()->with('success', 'Compliance status updated.');`; app/Http/Controllers/Hr/ComplianceController.php:509 `return $this->streamPrivateAttachment(`; app/Http/Controllers/Hr/ComplianceController.php:494 `return redirect()->back()->with('success', 'Exemption recorded and hard-stop lifted.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/compliance` — `hr.compliance.index` — `App\Http\Controllers\Hr\ComplianceController@index` — `app/Http/Controllers/Hr/ComplianceController.php:42` — middleware `web, auth, permission:hr.compliance.view`
- `POST hr/compliance/assign` — `hr.compliance.assign` — `App\Http\Controllers\Hr\ComplianceController@assign` — `app/Http/Controllers/Hr/ComplianceController.php:637` — middleware `web, auth, permission:hr.compliance.view, permission:hr.compliance.manage`
- `POST hr/compliance/bulk-exempt` — `hr.compliance.bulk.exempt` — `App\Http\Controllers\Hr\ComplianceController@bulkExempt` — `app/Http/Controllers/Hr/ComplianceController.php:595` — middleware `web, auth, permission:hr.compliance.view, permission:hr.compliance.manage`
- `POST hr/compliance/bulk-record` — `hr.compliance.bulk.record` — `App\Http\Controllers\Hr\ComplianceController@bulkRecord` — `app/Http/Controllers/Hr/ComplianceController.php:554` — middleware `web, auth, permission:hr.compliance.view, permission:hr.compliance.manage`
- `GET|HEAD hr/compliance/staff/{staff}` — `hr.compliance.staff` — `App\Http\Controllers\Hr\ComplianceController@staffDetail` — `app/Http/Controllers/Hr/ComplianceController.php:239` — middleware `web, auth, permission:hr.compliance.view`
- `POST hr/compliance/staff/{staff}/status` — `hr.compliance.status.store` — `App\Http\Controllers\Hr\ComplianceController@storeStatus` — `app/Http/Controllers/Hr/ComplianceController.php:375` — middleware `web, auth, permission:hr.compliance.view, permission:hr.compliance.manage`
- `PUT hr/compliance/status/{status}` — `hr.compliance.status.update` — `App\Http\Controllers\Hr\ComplianceController@updateStatus` — `app/Http/Controllers/Hr/ComplianceController.php:405` — middleware `web, auth, permission:hr.compliance.view, permission:hr.compliance.manage`
- `GET|HEAD hr/compliance/status/{status}/evidence` — `hr.compliance.status.evidence` — `App\Http\Controllers\Hr\ComplianceController@evidence` — `app/Http/Controllers/Hr/ComplianceController.php:501` — middleware `web, auth, permission:hr.compliance.view`
- `POST hr/compliance/status/{status}/exempt` — `hr.compliance.status.exempt` — `App\Http\Controllers\Hr\ComplianceController@exempt` — `app/Http/Controllers/Hr/ComplianceController.php:467` — middleware `web, auth, permission:hr.compliance.view, permission:hr.compliance.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/ComplianceController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/compliance/index.tsx`, `resources/js/pages/hr/compliance/staff-detail.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
