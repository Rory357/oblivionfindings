# CAP-OPS-TIMESHEET-MANAGER-REVIEW: Timesheet manager decisions and bulk review

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:timesheets.approve|timesheets.manageAny`
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

- Actor satisfying exact route middleware `auth`, `permission:timesheets.approve|timesheets.manageAny`.
- Exact middleware atoms: `web`, `auth`, `permission:timesheets.approve|timesheets.manageAny`, `role_scope:my-day`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/timesheets` (`operations.timesheets.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST operations/timesheets/{timesheet}/approve` (`operations.timesheets.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/TimesheetController.php:1148-1181`; `decision_notes`.
3. Invoke only the owning control for `POST operations/timesheets/{timesheet}/reject` (`operations.timesheets.reject`, action `reject`). Source category: **rejected/returned**; controller `app/Http/Controllers/TimesheetController.php:1183-1216`; `decision_notes`.
4. Invoke only the owning control for `POST operations/timesheets/{timesheet}/return` (`operations.timesheets.return`, action `returnForChanges`). Source category: **rejected/returned**; controller `app/Http/Controllers/TimesheetController.php:1218-1251`; `returned_notes`.
5. Invoke only the owning control for `POST operations/timesheets/bulk-approve` (`operations.timesheets.bulkApprove`, action `bulkApprove`). Source category: **mutation outcome source gap (bulkApprove)**; controller `app/Http/Controllers/TimesheetController.php:116-147`; `ids`.
6. Invoke only the owning control for `POST operations/timesheets/bulk-reject` (`operations.timesheets.bulkReject`, action `bulkReject`). Source category: **mutation outcome source gap (bulkReject)**; controller `app/Http/Controllers/TimesheetController.php:182-213`; `ids`.
7. Invoke only the owning control for `POST operations/timesheets/bulk-return` (`operations.timesheets.bulkReturn`, action `bulkReturnForChanges`). Source category: **mutation outcome source gap (bulkReturnForChanges)**; controller `app/Http/Controllers/TimesheetController.php:149-180`; `ids`.

## Source-applicable states and transitions

- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-2225` at `app/Http/Controllers/TimesheetController.php:1148`; it is not runtime-observed.
- **rejected/returned** is applicable only to `reject` / `ROUTE-2228` at `app/Http/Controllers/TimesheetController.php:1183`; it is not runtime-observed.
- **rejected/returned** is applicable only to `returnForChanges` / `ROUTE-2231` at `app/Http/Controllers/TimesheetController.php:1218`; it is not runtime-observed.
- **mutation outcome source gap (bulkApprove)** is applicable only to `bulkApprove` / `ROUTE-2235` at `app/Http/Controllers/TimesheetController.php:116`; it is not runtime-observed.
- **mutation outcome source gap (bulkReject)** is applicable only to `bulkReject` / `ROUTE-2236` at `app/Http/Controllers/TimesheetController.php:182`; it is not runtime-observed.
- **mutation outcome source gap (bulkReturnForChanges)** is applicable only to `bulkReturnForChanges` / `ROUTE-2237` at `app/Http/Controllers/TimesheetController.php:149`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2225` / `approve`: fields `decision_notes`; success app/Http/Controllers/TimesheetController.php:1169 `return redirect()->back()->with('success', 'Timesheet already approved.');`; app/Http/Controllers/TimesheetController.php:1180 `return redirect()->back()->with('success', 'Timesheet approved.');`; failure app/Http/Controllers/TimesheetController.php:1161 `} catch (ValidationException $exception) {`; app/Http/Controllers/TimesheetController.php:1162 `return back()->withErrors($exception->errors());`.
- `ROUTE-2228` / `reject`: fields `decision_notes`; success app/Http/Controllers/TimesheetController.php:1215 `return redirect()->back()->with('success', 'Timesheet rejected.');`; failure app/Http/Controllers/TimesheetController.php:1201 `return back()->withErrors(['decision_notes' => 'Decision notes are required.']);`.
- `ROUTE-2231` / `returnForChanges`: fields `returned_notes`; success app/Http/Controllers/TimesheetController.php:1250 `return redirect()->back()->with('success', 'Timesheet returned for changes.');`; failure app/Http/Controllers/TimesheetController.php:1236 `return back()->withErrors(['returned_notes' => 'Returned notes are required.']);`.
- `ROUTE-2235` / `bulkApprove`: fields `ids`; success app/Http/Controllers/TimesheetController.php:146 `return redirect()->back()->with('success', 'Selected timesheets approved.');`.
- `ROUTE-2236` / `bulkReject`: fields `ids`; success app/Http/Controllers/TimesheetController.php:212 `return redirect()->back()->with('success', 'Selected timesheets rejected.');`.
- `ROUTE-2237` / `bulkReturnForChanges`: fields `ids`; success app/Http/Controllers/TimesheetController.php:179 `return redirect()->back()->with('success', 'Selected timesheets returned for changes.');`.

## Failure and recovery paths

- `approve`: app/Http/Controllers/TimesheetController.php:1161 `} catch (ValidationException $exception) {`; app/Http/Controllers/TimesheetController.php:1162 `return back()->withErrors($exception->errors());`.
- `reject`: app/Http/Controllers/TimesheetController.php:1201 `return back()->withErrors(['decision_notes' => 'Decision notes are required.']);`.
- `returnForChanges`: app/Http/Controllers/TimesheetController.php:1236 `return back()->withErrors(['returned_notes' => 'Returned notes are required.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/TimesheetController.php:1162 `return back()->withErrors($exception->errors());`; app/Http/Controllers/TimesheetController.php:1169 `return redirect()->back()->with('success', 'Timesheet already approved.');`; app/Http/Controllers/TimesheetController.php:1180 `return redirect()->back()->with('success', 'Timesheet approved.');`; app/Http/Controllers/TimesheetController.php:1191 `return back()->with('error', 'Payroll-linked timesheets cannot be rejected after export preparation.');`; app/Http/Controllers/TimesheetController.php:1201 `return back()->withErrors(['decision_notes' => 'Decision notes are required.']);`; app/Http/Controllers/TimesheetController.php:1215 `return redirect()->back()->with('success', 'Timesheet rejected.');`; app/Http/Controllers/TimesheetController.php:1226 `return back()->with('error', 'Payroll-linked timesheets cannot be returned after export preparation.');`; app/Http/Controllers/TimesheetController.php:1236 `return back()->withErrors(['returned_notes' => 'Returned notes are required.']);`; app/Http/Controllers/TimesheetController.php:1250 `return redirect()->back()->with('success', 'Timesheet returned for changes.');`; app/Http/Controllers/TimesheetController.php:146 `return redirect()->back()->with('success', 'Selected timesheets approved.');`; app/Http/Controllers/TimesheetController.php:212 `return redirect()->back()->with('success', 'Selected timesheets rejected.');`; app/Http/Controllers/TimesheetController.php:165 `abort_if($timesheets->count() !== count($data['ids']), 403, 'You are not authorized to return timesheets for one or more selected sites.');`; app/Http/Controllers/TimesheetController.php:179 `return redirect()->back()->with('success', 'Selected timesheets returned for changes.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST operations/timesheets/{timesheet}/approve` — `operations.timesheets.approve` — `App\Http\Controllers\TimesheetController@approve` — `app/Http/Controllers/TimesheetController.php:1148` — middleware `web, auth, permission:timesheets.approve|timesheets.manageAny`
- `POST operations/timesheets/{timesheet}/reject` — `operations.timesheets.reject` — `App\Http\Controllers\TimesheetController@reject` — `app/Http/Controllers/TimesheetController.php:1183` — middleware `web, auth, permission:timesheets.approve|timesheets.manageAny`
- `POST operations/timesheets/{timesheet}/return` — `operations.timesheets.return` — `App\Http\Controllers\TimesheetController@returnForChanges` — `app/Http/Controllers/TimesheetController.php:1218` — middleware `web, auth, permission:timesheets.approve|timesheets.manageAny`
- `POST operations/timesheets/bulk-approve` — `operations.timesheets.bulkApprove` — `App\Http\Controllers\TimesheetController@bulkApprove` — `app/Http/Controllers/TimesheetController.php:116` — middleware `web, auth, role_scope:my-day, permission:timesheets.approve|timesheets.manageAny`
- `POST operations/timesheets/bulk-reject` — `operations.timesheets.bulkReject` — `App\Http\Controllers\TimesheetController@bulkReject` — `app/Http/Controllers/TimesheetController.php:182` — middleware `web, auth, role_scope:my-day, permission:timesheets.approve|timesheets.manageAny`
- `POST operations/timesheets/bulk-return` — `operations.timesheets.bulkReturn` — `App\Http\Controllers\TimesheetController@bulkReturnForChanges` — `app/Http/Controllers/TimesheetController.php:149` — middleware `web, auth, role_scope:my-day, permission:timesheets.approve|timesheets.manageAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/TimesheetController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
