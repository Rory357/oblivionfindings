# CAP-OPS-TIMESHEET-AUTHOR-SUBMIT: Timesheet authoring submission and resubmission

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:timesheets.viewAny|timesheets.viewAssigned`, `permission:timesheets.create`, `permission:timesheets.update`, `permission:timesheets.update|timesheets.manageAny`, `permission:timesheets.submit|timesheets.manageAny`
- Owning module: Operations and rostering
- Legacy family: `OPS-TIMESHEET`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/timesheets` (`operations.timesheets.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:timesheets.viewAny|timesheets.viewAssigned`, `permission:timesheets.create`, `permission:timesheets.update`, `permission:timesheets.update|timesheets.manageAny`, `permission:timesheets.submit|timesheets.manageAny`.
- Exact middleware atoms: `web`, `auth`, `permission:timesheets.viewAny|timesheets.viewAssigned`, `permission:timesheets.create`, `permission:timesheets.update`, `permission:timesheets.update|timesheets.manageAny`, `permission:timesheets.submit|timesheets.manageAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/timesheets` (`operations.timesheets.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/timesheets/{timesheet}` (`operations.timesheets.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/TimesheetController.php:600-611`.
3. Use `GET|HEAD operations/timesheets/{timesheet}/edit` (`operations.timesheets.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/TimesheetController.php:905-908`.
4. Invoke only the owning control for `POST operations/timesheets` (`operations.timesheets.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/TimesheetController.php:658-821`; `mode`.
5. Invoke only the owning control for `PUT operations/timesheets/{timesheet}` (`operations.timesheets.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/TimesheetController.php:910-997`; `client_id`.
6. Invoke only the owning control for `POST operations/timesheets/{timesheet}/resubmit` (`operations.timesheets.resubmit`, action `resubmit`). Source category: **mutation outcome source gap (resubmit)**; controller `app/Http/Controllers/TimesheetController.php:1052-1146`; `client_id`.
7. Invoke only the owning control for `POST operations/timesheets/{timesheet}/submit` (`operations.timesheets.submit`, action `submit`). Source category: **created/recorded**; controller `app/Http/Controllers/TimesheetController.php:999-1041`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2221` at `app/Http/Controllers/TimesheetController.php:215`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2222` at `app/Http/Controllers/TimesheetController.php:658`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2223` at `app/Http/Controllers/TimesheetController.php:600`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2224` at `app/Http/Controllers/TimesheetController.php:910`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-2227` at `app/Http/Controllers/TimesheetController.php:905`; it is not runtime-observed.
- **mutation outcome source gap (resubmit)** is applicable only to `resubmit` / `ROUTE-2230` at `app/Http/Controllers/TimesheetController.php:1052`; it is not runtime-observed.
- **created/recorded** is applicable only to `submit` / `ROUTE-2232` at `app/Http/Controllers/TimesheetController.php:999`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/timesheets/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2222` / `store`: fields `mode`; success app/Http/Controllers/TimesheetController.php:820 `->with('success', 'Timesheet created.');`; failure app/Http/Controllers/TimesheetController.php:707 `abort(403);`; app/Http/Controllers/TimesheetController.php:805 `} catch (ValidationException $e) {`; app/Http/Controllers/TimesheetController.php:806 `return back()->withErrors($e->errors());`.
- `ROUTE-2224` / `update`: fields `client_id`; success app/Http/Controllers/TimesheetController.php:996 `return redirect()->back()->with('success', 'Timesheet updated.');`; failure app/Http/Controllers/TimesheetController.php:917 `abort(403);`.
- `ROUTE-2230` / `resubmit`: fields `client_id`; success app/Http/Controllers/TimesheetController.php:1145 `return redirect()->back()->with('success', 'Timesheet updated and resubmitted.');`; failure app/Http/Controllers/TimesheetController.php:1061 `abort(403);`.
- `ROUTE-2232` / `submit`: success app/Http/Controllers/TimesheetController.php:1040 `return redirect()->back()->with('success', 'Timesheet submitted.');`; failure app/Http/Controllers/TimesheetController.php:1006 `abort(403);`.

## Failure and recovery paths

- `store`: app/Http/Controllers/TimesheetController.php:707 `abort(403);`; app/Http/Controllers/TimesheetController.php:805 `} catch (ValidationException $e) {`; app/Http/Controllers/TimesheetController.php:806 `return back()->withErrors($e->errors());`.
- `update`: app/Http/Controllers/TimesheetController.php:917 `abort(403);`.
- `resubmit`: app/Http/Controllers/TimesheetController.php:1061 `abort(403);`.
- `submit`: app/Http/Controllers/TimesheetController.php:1006 `abort(403);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/TimesheetController.php:748 `$timesheet = Timesheet::create([`; app/Http/Controllers/TimesheetController.php:982 `$timesheet->save();`; responses app/Http/Controllers/TimesheetController.php:344 `return inertia('operations/timesheets/index', [`; app/Http/Controllers/TimesheetController.php:723 `return response()->json([`; app/Http/Controllers/TimesheetController.php:729 `return back()->with('error', $message)->withInput();`; app/Http/Controllers/TimesheetController.php:806 `return back()->withErrors($e->errors());`; app/Http/Controllers/TimesheetController.php:811 `return response()->json([`; app/Http/Controllers/TimesheetController.php:818 `return redirect()`; app/Http/Controllers/TimesheetController.php:607 `return $this->showTimesheetCard($request, $timesheet);`; app/Http/Controllers/TimesheetController.php:610 `return redirect()->to("/operations/timesheets?view={$timesheet->id}");`; app/Http/Controllers/TimesheetController.php:924 `return back()->with('error', 'Only draft or returned timesheets can be edited.');`; app/Http/Controllers/TimesheetController.php:929 `return back()->with('error', 'This timesheet is locked by a payroll run and cannot be edited.');`; app/Http/Controllers/TimesheetController.php:933 `return back()->with('error', 'Approved or payroll-linked timesheets require a controlled correction workflow.');`; app/Http/Controllers/TimesheetController.php:996 `return redirect()->back()->with('success', 'Timesheet updated.');`; app/Http/Controllers/TimesheetController.php:907 `return redirect()->to("/operations/timesheets?edit={$timesheet->id}");`; app/Http/Controllers/TimesheetController.php:1067 `return back()->with('error', 'Only draft or returned timesheets can be resubmitted.');`; app/Http/Controllers/TimesheetController.php:1071 `return back()->with('error', 'This timesheet is locked by a payroll run and cannot be resubmitted.');`; app/Http/Controllers/TimesheetController.php:1075 `return back()->with('error', 'Approved or payroll-linked timesheets require a controlled correction workflow.');`; app/Http/Controllers/TimesheetController.php:1145 `return redirect()->back()->with('success', 'Timesheet updated and resubmitted.');`; app/Http/Controllers/TimesheetController.php:1015 `return back()->with('error', 'This timesheet is locked by a payroll run and cannot be submitted.');`; app/Http/Controllers/TimesheetController.php:1019 `return back()->with('error', 'Approved or payroll-linked timesheets cannot be resubmitted.');`; app/Http/Controllers/TimesheetController.php:1040 `return redirect()->back()->with('success', 'Timesheet submitted.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/timesheets` — `operations.timesheets.index` — `App\Http\Controllers\TimesheetController@index` — `app/Http/Controllers/TimesheetController.php:215` — middleware `web, auth, permission:timesheets.viewAny|timesheets.viewAssigned`
- `POST operations/timesheets` — `operations.timesheets.store` — `App\Http\Controllers\TimesheetController@store` — `app/Http/Controllers/TimesheetController.php:658` — middleware `web, auth, permission:timesheets.create`
- `GET|HEAD operations/timesheets/{timesheet}` — `operations.timesheets.show` — `App\Http\Controllers\TimesheetController@show` — `app/Http/Controllers/TimesheetController.php:600` — middleware `web, auth, permission:timesheets.viewAny|timesheets.viewAssigned`
- `PUT operations/timesheets/{timesheet}` — `operations.timesheets.update` — `App\Http\Controllers\TimesheetController@update` — `app/Http/Controllers/TimesheetController.php:910` — middleware `web, auth, permission:timesheets.update`
- `GET|HEAD operations/timesheets/{timesheet}/edit` — `operations.timesheets.edit` — `App\Http\Controllers\TimesheetController@edit` — `app/Http/Controllers/TimesheetController.php:905` — middleware `web, auth, permission:timesheets.viewAny|timesheets.viewAssigned`
- `POST operations/timesheets/{timesheet}/resubmit` — `operations.timesheets.resubmit` — `App\Http\Controllers\TimesheetController@resubmit` — `app/Http/Controllers/TimesheetController.php:1052` — middleware `web, auth, permission:timesheets.update|timesheets.manageAny`
- `POST operations/timesheets/{timesheet}/submit` — `operations.timesheets.submit` — `App\Http\Controllers\TimesheetController@submit` — `app/Http/Controllers/TimesheetController.php:999` — middleware `web, auth, permission:timesheets.submit|timesheets.manageAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/TimesheetController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/timesheets/index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
