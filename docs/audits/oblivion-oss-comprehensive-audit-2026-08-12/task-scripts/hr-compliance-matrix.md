# HR-COMPLIANCE-MATRIX: Compliance Matrix

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.compliance.view`, `permission:hr.compliance.manage`
- Owning module: Human resources
- Legacy family: `HR-COMPLIANCE-MATRIX`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/compliance/matrix` (`hr.compliance.matrix`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.compliance.view`, `permission:hr.compliance.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.compliance.view`, `permission:hr.compliance.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/compliance/matrix` (`hr.compliance.matrix`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/compliance/matrix` (`hr.compliance.matrix.update`, action `updateMatrix`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/ComplianceMatrixController.php:209-253`; `requirement_id`.
3. Invoke only the owning control for `POST hr/compliance/requirements` (`hr.compliance.requirements.store`, action `storeRequirement`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/ComplianceMatrixController.php:83-113`; `code`.
4. Invoke only the owning control for `DELETE hr/compliance/requirements/{requirement}` (`hr.compliance.requirements.destroy`, action `destroyRequirement`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/ComplianceMatrixController.php:184-203`; no exact validation fields extracted.
5. Invoke only the owning control for `PUT hr/compliance/requirements/{requirement}` (`hr.compliance.requirements.update`, action `updateRequirement`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/ComplianceMatrixController.php:151-178`; `code`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1365` at `app/Http/Controllers/Hr/ComplianceMatrixController.php:26`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateMatrix` / `ROUTE-1366` at `app/Http/Controllers/Hr/ComplianceMatrixController.php:209`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeRequirement` / `ROUTE-1369` at `app/Http/Controllers/Hr/ComplianceMatrixController.php:83`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyRequirement` / `ROUTE-1370` at `app/Http/Controllers/Hr/ComplianceMatrixController.php:184`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateRequirement` / `ROUTE-1371` at `app/Http/Controllers/Hr/ComplianceMatrixController.php:151`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/compliance/matrix.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1366` / `updateMatrix`: fields `requirement_id`; success app/Http/Controllers/Hr/ComplianceMatrixController.php:242 `return redirect()->back()->with('success', 'Matrix entry assigned.');`; app/Http/Controllers/Hr/ComplianceMatrixController.php:252 `return redirect()->back()->with('success', 'Matrix entry removed.');`.
- `ROUTE-1369` / `storeRequirement`: fields `code`; success app/Http/Controllers/Hr/ComplianceMatrixController.php:112 `return redirect()->back()->with('success', 'Compliance requirement created.');`.
- `ROUTE-1370` / `destroyRequirement`: success app/Http/Controllers/Hr/ComplianceMatrixController.php:202 `return redirect()->back()->with('success', 'Compliance requirement deactivated.');`.
- `ROUTE-1371` / `updateRequirement`: fields `code`; success app/Http/Controllers/Hr/ComplianceMatrixController.php:177 `return redirect()->back()->with('success', 'Compliance requirement updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/ComplianceMatrixController.php:229 `HrComplianceMatrix::updateOrCreate(`; app/Http/Controllers/Hr/ComplianceMatrixController.php:250 `->delete();`; app/Http/Controllers/Hr/ComplianceMatrixController.php:103 `$requirement = HrComplianceRequirement::create([`; app/Http/Controllers/Hr/ComplianceMatrixController.php:192 `$requirement->update([`; app/Http/Controllers/Hr/ComplianceMatrixController.php:200 `->delete();`; app/Http/Controllers/Hr/ComplianceMatrixController.php:173 `$requirement->update($validated);`; responses app/Http/Controllers/Hr/ComplianceMatrixController.php:66 `return Inertia::render('hr/compliance/matrix', [`; app/Http/Controllers/Hr/ComplianceMatrixController.php:242 `return redirect()->back()->with('success', 'Matrix entry assigned.');`; app/Http/Controllers/Hr/ComplianceMatrixController.php:252 `return redirect()->back()->with('success', 'Matrix entry removed.');`; app/Http/Controllers/Hr/ComplianceMatrixController.php:112 `return redirect()->back()->with('success', 'Compliance requirement created.');`; app/Http/Controllers/Hr/ComplianceMatrixController.php:202 `return redirect()->back()->with('success', 'Compliance requirement deactivated.');`; app/Http/Controllers/Hr/ComplianceMatrixController.php:177 `return redirect()->back()->with('success', 'Compliance requirement updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/compliance/matrix` — `hr.compliance.matrix` — `App\Http\Controllers\Hr\ComplianceMatrixController@index` — `app/Http/Controllers/Hr/ComplianceMatrixController.php:26` — middleware `web, auth, permission:hr.compliance.view, permission:hr.compliance.manage`
- `POST hr/compliance/matrix` — `hr.compliance.matrix.update` — `App\Http\Controllers\Hr\ComplianceMatrixController@updateMatrix` — `app/Http/Controllers/Hr/ComplianceMatrixController.php:209` — middleware `web, auth, permission:hr.compliance.view, permission:hr.compliance.manage`
- `POST hr/compliance/requirements` — `hr.compliance.requirements.store` — `App\Http\Controllers\Hr\ComplianceMatrixController@storeRequirement` — `app/Http/Controllers/Hr/ComplianceMatrixController.php:83` — middleware `web, auth, permission:hr.compliance.view, permission:hr.compliance.manage`
- `DELETE hr/compliance/requirements/{requirement}` — `hr.compliance.requirements.destroy` — `App\Http\Controllers\Hr\ComplianceMatrixController@destroyRequirement` — `app/Http/Controllers/Hr/ComplianceMatrixController.php:184` — middleware `web, auth, permission:hr.compliance.view, permission:hr.compliance.manage`
- `PUT hr/compliance/requirements/{requirement}` — `hr.compliance.requirements.update` — `App\Http\Controllers\Hr\ComplianceMatrixController@updateRequirement` — `app/Http/Controllers/Hr/ComplianceMatrixController.php:151` — middleware `web, auth, permission:hr.compliance.view, permission:hr.compliance.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/ComplianceMatrixController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/compliance/matrix.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
