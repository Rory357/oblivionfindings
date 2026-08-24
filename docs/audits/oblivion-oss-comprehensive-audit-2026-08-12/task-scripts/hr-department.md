# HR-DEPARTMENT: Department

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.settings.manage|hr.employees.manage`
- Owning module: Human resources
- Legacy family: `HR-DEPARTMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/departments` (`hr.departments.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.settings.manage|hr.employees.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.settings.manage|hr.employees.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/departments` (`hr.departments.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/departments/{department}` (`hr.departments.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/DepartmentController.php:79-135`.
3. Invoke only the owning control for `POST hr/departments` (`hr.departments.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/DepartmentController.php:41-72`; `name`.
4. Invoke only the owning control for `DELETE hr/departments/{department}` (`hr.departments.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/DepartmentController.php:177-196`; no exact validation fields extracted.
5. Invoke only the owning control for `PUT hr/departments/{department}` (`hr.departments.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/DepartmentController.php:137-175`; `name`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1387` at `app/Http/Controllers/Hr/DepartmentController.php:26`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1388` at `app/Http/Controllers/Hr/DepartmentController.php:41`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1389` at `app/Http/Controllers/Hr/DepartmentController.php:177`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1390` at `app/Http/Controllers/Hr/DepartmentController.php:79`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1391` at `app/Http/Controllers/Hr/DepartmentController.php:137`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1388` / `store`: fields `name`; success app/Http/Controllers/Hr/DepartmentController.php:71 `return redirect()->back()->with('success', 'Department created successfully.');`.
- `ROUTE-1389` / `destroy`: success app/Http/Controllers/Hr/DepartmentController.php:195 `return redirect()->back()->with('success', 'Department deactivated.');`.
- `ROUTE-1391` / `update`: fields `name`; success app/Http/Controllers/Hr/DepartmentController.php:174 `return redirect()->back()->with('success', 'Department updated successfully.');`; failure app/Http/Controllers/Hr/DepartmentController.php:160 `throw ValidationException::withMessages([`.

## Failure and recovery paths

- `update`: app/Http/Controllers/Hr/DepartmentController.php:160 `throw ValidationException::withMessages([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/DepartmentController.php:63 `$department = HrDepartment::create([`; app/Http/Controllers/Hr/DepartmentController.php:69 `$department->sites()->sync($siteIds);`; app/Http/Controllers/Hr/DepartmentController.php:191 `->update(['parent_id' => $department->parent_id]);`; app/Http/Controllers/Hr/DepartmentController.php:193 `$department->update(['is_active' => false]);`; app/Http/Controllers/Hr/DepartmentController.php:166 `$department->update($validated);`; app/Http/Controllers/Hr/DepartmentController.php:171 `$department->sites()->sync($request->input('site_ids', []));`; responses app/Http/Controllers/Hr/DepartmentController.php:38 `return redirect()->route('hr.people.index', $params);`; app/Http/Controllers/Hr/DepartmentController.php:71 `return redirect()->back()->with('success', 'Department created successfully.');`; app/Http/Controllers/Hr/DepartmentController.php:184 `return redirect()->back()->with('error', "Cannot deactivate department with {$activeEmployees} active employee(s). Reassign them first.");`; app/Http/Controllers/Hr/DepartmentController.php:195 `return redirect()->back()->with('success', 'Department deactivated.');`; app/Http/Controllers/Hr/DepartmentController.php:99 `return response()->json([`; app/Http/Controllers/Hr/DepartmentController.php:174 `return redirect()->back()->with('success', 'Department updated successfully.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/departments` — `hr.departments.index` — `App\Http\Controllers\Hr\DepartmentController@index` — `app/Http/Controllers/Hr/DepartmentController.php:26` — middleware `web, auth, permission:hr.settings.manage|hr.employees.manage`
- `POST hr/departments` — `hr.departments.store` — `App\Http\Controllers\Hr\DepartmentController@store` — `app/Http/Controllers/Hr/DepartmentController.php:41` — middleware `web, auth, permission:hr.settings.manage|hr.employees.manage`
- `DELETE hr/departments/{department}` — `hr.departments.destroy` — `App\Http\Controllers\Hr\DepartmentController@destroy` — `app/Http/Controllers/Hr/DepartmentController.php:177` — middleware `web, auth, permission:hr.settings.manage|hr.employees.manage`
- `GET|HEAD hr/departments/{department}` — `hr.departments.show` — `App\Http\Controllers\Hr\DepartmentController@show` — `app/Http/Controllers/Hr/DepartmentController.php:79` — middleware `web, auth, permission:hr.settings.manage|hr.employees.manage`
- `PUT hr/departments/{department}` — `hr.departments.update` — `App\Http\Controllers\Hr\DepartmentController@update` — `app/Http/Controllers/Hr/DepartmentController.php:137` — middleware `web, auth, permission:hr.settings.manage|hr.employees.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/DepartmentController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
