# CAP-OPS-SHIFT-EXECUTION-RECOVERY: Shift start completion occurrence cancellation and reopen

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:shifts.manageAny`, `permission:shifts.update|shifts.manageAny`
- Owning module: Operations and rostering
- Legacy family: `OPS-SHIFT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/shifts` (`operations.shifts.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:shifts.manageAny`, `permission:shifts.update|shifts.manageAny`.
- Exact middleware atoms: `web`, `auth`, `permission:shifts.manageAny`, `permission:shifts.update|shifts.manageAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/shifts` (`operations.shifts.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PATCH operations/shifts/{shift}/cancel` (`operations.shifts.cancel`, action `cancelOccurrence`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ShiftController.php:1605-1618`; no exact validation fields extracted.
3. Invoke only the owning control for `PATCH operations/shifts/{shift}/complete` (`operations.shifts.complete`, action `complete`). Source category: **completed/closed/released**; controller `app/Http/Controllers/ShiftController.php:1552-1603`; `final_note_subject`.
4. Invoke only the owning control for `PATCH operations/shifts/{shift}/reopen` (`operations.shifts.reopen`, action `reopenOccurrence`). Source category: **mutation outcome source gap (reopenOccurrence)**; controller `app/Http/Controllers/ShiftController.php:1792-1822`; `reason`.
5. Invoke only the owning control for `PATCH operations/shifts/{shift}/start` (`operations.shifts.start`, action `start`). Source category: **created/recorded**; controller `app/Http/Controllers/ShiftController.php:1528-1550`; no exact validation fields extracted.

## Source-applicable states and transitions

- **cancelled/removed/archived** is applicable only to `cancelOccurrence` / `ROUTE-2198` at `app/Http/Controllers/ShiftController.php:1605`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `complete` / `ROUTE-2200` at `app/Http/Controllers/ShiftController.php:1552`; it is not runtime-observed.
- **mutation outcome source gap (reopenOccurrence)** is applicable only to `reopenOccurrence` / `ROUTE-2207` at `app/Http/Controllers/ShiftController.php:1792`; it is not runtime-observed.
- **created/recorded** is applicable only to `start` / `ROUTE-2210` at `app/Http/Controllers/ShiftController.php:1528`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2198` / `cancelOccurrence`: success app/Http/Controllers/ShiftController.php:1612 `return back()->with('success', 'Shift is already cancelled.');`; app/Http/Controllers/ShiftController.php:1617 `return back()->with('success', 'Shift occurrence cancelled.');`.
- `ROUTE-2200` / `complete`: fields `final_note_subject`; success app/Http/Controllers/ShiftController.php:1579 `return back()->with('success', 'Shift already completed.');`; app/Http/Controllers/ShiftController.php:1593 `? back()->with('success', 'Shift already completed. Missing draft timesheet has been created.')`; failure app/Http/Controllers/ShiftController.php:1559 `abort(403);`.
- `ROUTE-2207` / `reopenOccurrence`: fields `reason`; success app/Http/Controllers/ShiftController.php:1821 `return back()->with('success', $message);`; failure app/Http/Controllers/ShiftController.php:1810 `return back()->withErrors([`.
- `ROUTE-2210` / `start`: success app/Http/Controllers/ShiftController.php:1549 `return back()->with('success', 'Shift started.');`; failure app/Http/Controllers/ShiftController.php:1535 `abort(403);`; app/Http/Controllers/ShiftController.php:1545 `} catch (ValidationException $exception) {`; app/Http/Controllers/ShiftController.php:1546 `return back()->withErrors($exception->errors());`.

## Failure and recovery paths

- `complete`: app/Http/Controllers/ShiftController.php:1559 `abort(403);`.
- `reopenOccurrence`: app/Http/Controllers/ShiftController.php:1810 `return back()->withErrors([`.
- `start`: app/Http/Controllers/ShiftController.php:1535 `abort(403);`; app/Http/Controllers/ShiftController.php:1545 `} catch (ValidationException $exception) {`; app/Http/Controllers/ShiftController.php:1546 `return back()->withErrors($exception->errors());`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/ShiftController.php:1612 `return back()->with('success', 'Shift is already cancelled.');`; app/Http/Controllers/ShiftController.php:1617 `return back()->with('success', 'Shift occurrence cancelled.');`; app/Http/Controllers/ShiftController.php:1579 `return back()->with('success', 'Shift already completed.');`; app/Http/Controllers/ShiftController.php:1592 `return $timesheetResult['success']`; app/Http/Controllers/ShiftController.php:1602 `return back()->with($flashKey, $flashMessage);`; app/Http/Controllers/ShiftController.php:1799 `return back()->with('error', 'Only cancelled or completed occurrences can be reopened.');`; app/Http/Controllers/ShiftController.php:1810 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1821 `return back()->with('success', $message);`; app/Http/Controllers/ShiftController.php:1546 `return back()->withErrors($exception->errors());`; app/Http/Controllers/ShiftController.php:1549 `return back()->with('success', 'Shift started.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `PATCH operations/shifts/{shift}/cancel` — `operations.shifts.cancel` — `App\Http\Controllers\ShiftController@cancelOccurrence` — `app/Http/Controllers/ShiftController.php:1605` — middleware `web, auth, permission:shifts.manageAny`
- `PATCH operations/shifts/{shift}/complete` — `operations.shifts.complete` — `App\Http\Controllers\ShiftController@complete` — `app/Http/Controllers/ShiftController.php:1552` — middleware `web, auth, permission:shifts.update|shifts.manageAny`
- `PATCH operations/shifts/{shift}/reopen` — `operations.shifts.reopen` — `App\Http\Controllers\ShiftController@reopenOccurrence` — `app/Http/Controllers/ShiftController.php:1792` — middleware `web, auth, permission:shifts.manageAny`
- `PATCH operations/shifts/{shift}/start` — `operations.shifts.start` — `App\Http\Controllers\ShiftController@start` — `app/Http/Controllers/ShiftController.php:1528` — middleware `web, auth, permission:shifts.update|shifts.manageAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ShiftController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
