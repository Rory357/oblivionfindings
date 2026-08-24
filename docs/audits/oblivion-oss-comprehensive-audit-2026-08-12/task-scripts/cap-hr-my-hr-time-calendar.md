# CAP-HR-MY-HR-TIME-CALENDAR: My time clock shifts and calendar

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Human resources
- Legacy family: `HR-MY-HR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/my/calendar` (`hr.my.calendar`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/my/calendar` (`hr.my.calendar`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/my/time` (`hr.my.time`, action `time`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/MyHrController.php:1248-1388`.
3. Use `GET|HEAD hr/my/time/shifts/{shift}/calendar` (`hr.my.time.shift-calendar`, action `shiftCalendar`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/MyHrController.php:1489-1528`.
4. Invoke only the owning control for `POST hr/my/time/clock-in` (`hr.my.time.clock-in`, action `clockIn`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/MyHrController.php:1390-1429`; `shift_id`.
5. Invoke only the owning control for `POST hr/my/time/clock-out` (`hr.my.time.clock-out`, action `clockOut`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Hr/MyHrController.php:1431-1486`; `break_minutes`.

## Source-applicable states and transitions

- **information presented** is applicable only to `calendar` / `ROUTE-1511` at `app/Http/Controllers/Hr/MyHrController.php:1730`; it is not runtime-observed.
- **information presented** is applicable only to `time` / `ROUTE-1544` at `app/Http/Controllers/Hr/MyHrController.php:1248`; it is not runtime-observed.
- **created/recorded** is applicable only to `clockIn` / `ROUTE-1545` at `app/Http/Controllers/Hr/MyHrController.php:1390`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `clockOut` / `ROUTE-1546` at `app/Http/Controllers/Hr/MyHrController.php:1431`; it is not runtime-observed.
- **information presented** is applicable only to `shiftCalendar` / `ROUTE-1547` at `app/Http/Controllers/Hr/MyHrController.php:1489`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/my/time.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1545` / `clockIn`: fields `shift_id`; success app/Http/Controllers/Hr/MyHrController.php:1428 `return redirect()->back()->with('success', 'Clocked in successfully.');`.
- `ROUTE-1546` / `clockOut`: fields `break_minutes`; success app/Http/Controllers/Hr/MyHrController.php:1485 `return redirect()->back()->with('success', 'Clocked out successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/MyHrController.php:1743 `return response()->json(`; app/Http/Controllers/Hr/MyHrController.php:1364 `return Inertia::render('hr/my/time', [`; app/Http/Controllers/Hr/MyHrController.php:1408 `return redirect()->back()->with('error', 'You are already clocked in.');`; app/Http/Controllers/Hr/MyHrController.php:1425 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/MyHrController.php:1428 `return redirect()->back()->with('success', 'Clocked in successfully.');`; app/Http/Controllers/Hr/MyHrController.php:1482 `return redirect()->back()->with('error', 'You are not currently clocked in.');`; app/Http/Controllers/Hr/MyHrController.php:1485 `return redirect()->back()->with('success', 'Clocked out successfully.');`; app/Http/Controllers/Hr/MyHrController.php:1524 `return response(implode("\r\n", $lines)."\r\n", 200, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/my/calendar` — `hr.my.calendar` — `App\Http\Controllers\Hr\MyHrController@calendar` — `app/Http/Controllers/Hr/MyHrController.php:1730` — middleware `web, auth`
- `GET|HEAD hr/my/time` — `hr.my.time` — `App\Http\Controllers\Hr\MyHrController@time` — `app/Http/Controllers/Hr/MyHrController.php:1248` — middleware `web, auth`
- `POST hr/my/time/clock-in` — `hr.my.time.clock-in` — `App\Http\Controllers\Hr\MyHrController@clockIn` — `app/Http/Controllers/Hr/MyHrController.php:1390` — middleware `web, auth`
- `POST hr/my/time/clock-out` — `hr.my.time.clock-out` — `App\Http\Controllers\Hr\MyHrController@clockOut` — `app/Http/Controllers/Hr/MyHrController.php:1431` — middleware `web, auth`
- `GET|HEAD hr/my/time/shifts/{shift}/calendar` — `hr.my.time.shift-calendar` — `App\Http\Controllers\Hr\MyHrController@shiftCalendar` — `app/Http/Controllers/Hr/MyHrController.php:1489` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/MyHrController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/my/time.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
