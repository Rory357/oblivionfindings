# CAP-HR-TIME-TRACKING-CLOCKING: Staff clock-in clock-out and on-behalf clocking

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:timesheets.viewAny`, `permission:timesheets.manageAny|timesheets.approve`
- Owning module: Human resources
- Legacy family: `HR-TIME-TRACKING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/time` (`hr.time.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:timesheets.viewAny`, `permission:timesheets.manageAny|timesheets.approve`.
- Exact middleware atoms: `web`, `auth`, `permission:timesheets.viewAny`, `permission:timesheets.manageAny|timesheets.approve`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/time` (`hr.time.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/time/clock-in` (`hr.time.clock-in`, action `clockIn`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/TimeTrackingController.php:602-625`; `shift_id`.
3. Invoke only the owning control for `POST hr/time/clock-on-behalf` (`hr.time.clock-on-behalf`, action `clockOnBehalf`). Source category: **mutation outcome source gap (clockOnBehalf)**; controller `app/Http/Controllers/Hr/TimeTrackingController.php:660-672`; FormRequest `app/Http/Requests/Hr/ClockOnBehalfRequest.php:16`; `target_user_id`, `clock_in`, `clock_out`, `shift_id`, `site_id`, `client_id`, `break_minutes`, `mileage_km`, `pay_type`, `is_sleepover`, `is_on_call`, `is_public_holiday`, `sleepover_disturbances`, `reason`, `notes`.
4. Invoke only the owning control for `POST hr/time/clock-out` (`hr.time.clock-out`, action `clockOut`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Hr/TimeTrackingController.php:631-654`; `break_minutes`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `clockIn` / `ROUTE-1774` at `app/Http/Controllers/Hr/TimeTrackingController.php:602`; it is not runtime-observed.
- **mutation outcome source gap (clockOnBehalf)** is applicable only to `clockOnBehalf` / `ROUTE-1775` at `app/Http/Controllers/Hr/TimeTrackingController.php:660`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `clockOut` / `ROUTE-1776` at `app/Http/Controllers/Hr/TimeTrackingController.php:631`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1774` / `clockIn`: fields `shift_id`; success app/Http/Controllers/Hr/TimeTrackingController.php:624 `return redirect()->back()->with('success', 'Clocked in successfully.');`.
- `ROUTE-1775` / `clockOnBehalf`: FormRequest `app/Http/Requests/Hr/ClockOnBehalfRequest.php:16`; fields `target_user_id`, `clock_in`, `clock_out`, `shift_id`, `site_id`, `client_id`, `break_minutes`, `mileage_km`, `pay_type`, `is_sleepover`, `is_on_call`, `is_public_holiday`, `sleepover_disturbances`, `reason`, `notes`; success app/Http/Controllers/Hr/TimeTrackingController.php:671 `return redirect()->back()->with('success', 'Time entry created on behalf of staff member.');`.
- `ROUTE-1776` / `clockOut`: fields `break_minutes`; success app/Http/Controllers/Hr/TimeTrackingController.php:653 `return redirect()->back()->with('success', 'Clocked out successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/TimeTrackingController.php:621 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/TimeTrackingController.php:624 `return redirect()->back()->with('success', 'Clocked in successfully.');`; app/Http/Controllers/Hr/TimeTrackingController.php:668 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/TimeTrackingController.php:671 `return redirect()->back()->with('success', 'Time entry created on behalf of staff member.');`; app/Http/Controllers/Hr/TimeTrackingController.php:650 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/TimeTrackingController.php:653 `return redirect()->back()->with('success', 'Clocked out successfully.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/time/clock-in` — `hr.time.clock-in` — `App\Http\Controllers\Hr\TimeTrackingController@clockIn` — `app/Http/Controllers/Hr/TimeTrackingController.php:602` — middleware `web, auth, permission:timesheets.viewAny`
- `POST hr/time/clock-on-behalf` — `hr.time.clock-on-behalf` — `App\Http\Controllers\Hr\TimeTrackingController@clockOnBehalf` — `app/Http/Controllers/Hr/TimeTrackingController.php:660` — middleware `web, auth, permission:timesheets.viewAny, permission:timesheets.manageAny|timesheets.approve`
- `POST hr/time/clock-out` — `hr.time.clock-out` — `App\Http\Controllers\Hr\TimeTrackingController@clockOut` — `app/Http/Controllers/Hr/TimeTrackingController.php:631` — middleware `web, auth, permission:timesheets.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/TimeTrackingController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
