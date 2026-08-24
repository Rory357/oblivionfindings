# CAP-HR-TIME-TRACKING-ENTRY-CORRECTIONS: Time entry notes corrections and voids

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:timesheets.viewAny`, `permission:timesheets.manageAny|timesheets.approve`, `permission:timesheets.manageAny`
- Owning module: Human resources
- Legacy family: `HR-TIME-TRACKING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/time/entries/{entry}/amendments` (`hr.time.entries.amendments`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:timesheets.viewAny`, `permission:timesheets.manageAny|timesheets.approve`, `permission:timesheets.manageAny`.
- Exact middleware atoms: `web`, `auth`, `permission:timesheets.viewAny`, `permission:timesheets.manageAny|timesheets.approve`, `permission:timesheets.manageAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/time/entries/{entry}/amendments` (`hr.time.entries.amendments`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/time/entries` (`hr.time.entries.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/TimeTrackingController.php:678-686`; FormRequest `app/Http/Requests/Hr/StoreTimesheetRequest.php:18`; `user_id`, `clock_in`, `clock_out`, `break_minutes`, `pay_type`, `is_sleepover`, `is_on_call`, `is_public_holiday`, `sleepover_disturbances`, `mileage_km`, `site_id`, `client_id`, `shift_id`, `notes`, `project_code`, `cost_centre`.
3. Invoke only the owning control for `PUT hr/time/entries/{entry}` (`hr.time.entries.update`, action `updateEntry`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/TimeTrackingController.php:692-707`; FormRequest `app/Http/Requests/Hr/UpdateTimeEntryRequest.php:16`; `clock_in`, `clock_out`, `break_minutes`, `pay_type`, `notes`, `is_sleepover`, `is_on_call`, `is_public_holiday`, `sleepover_disturbances`, `mileage_km`, `cost_centre`, `project_code`, `amendment_reason`.
4. Invoke only the owning control for `POST hr/time/entries/{entry}/correct` (`hr.time.entries.correct`, action `correct`). Source category: **mutation outcome source gap (correct)**; controller `app/Http/Controllers/Hr/TimeTrackingController.php:738-763`; `clock_out`.
5. Invoke only the owning control for `POST hr/time/entries/{entry}/note` (`hr.time.entries.note`, action `addNote`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/TimeTrackingController.php:792-805`; `note`.
6. Invoke only the owning control for `POST hr/time/entries/{entry}/void` (`hr.time.entries.void`, action `void`). Source category: **mutation outcome source gap (void)**; controller `app/Http/Controllers/Hr/TimeTrackingController.php:769-786`; `reason`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-1777` at `app/Http/Controllers/Hr/TimeTrackingController.php:678`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateEntry` / `ROUTE-1778` at `app/Http/Controllers/Hr/TimeTrackingController.php:692`; it is not runtime-observed.
- **information presented** is applicable only to `entryAmendments` / `ROUTE-1779` at `app/Http/Controllers/Hr/TimeTrackingController.php:713`; it is not runtime-observed.
- **mutation outcome source gap (correct)** is applicable only to `correct` / `ROUTE-1780` at `app/Http/Controllers/Hr/TimeTrackingController.php:738`; it is not runtime-observed.
- **created/recorded** is applicable only to `addNote` / `ROUTE-1781` at `app/Http/Controllers/Hr/TimeTrackingController.php:792`; it is not runtime-observed.
- **mutation outcome source gap (void)** is applicable only to `void` / `ROUTE-1782` at `app/Http/Controllers/Hr/TimeTrackingController.php:769`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1777` / `store`: FormRequest `app/Http/Requests/Hr/StoreTimesheetRequest.php:18`; fields `user_id`, `clock_in`, `clock_out`, `break_minutes`, `pay_type`, `is_sleepover`, `is_on_call`, `is_public_holiday`, `sleepover_disturbances`, `mileage_km`, `site_id`, `client_id`, `shift_id`, `notes`, `project_code`, `cost_centre`; success app/Http/Controllers/Hr/TimeTrackingController.php:685 `return redirect()->back()->with('success', 'Time entry created.');`.
- `ROUTE-1778` / `updateEntry`: FormRequest `app/Http/Requests/Hr/UpdateTimeEntryRequest.php:16`; fields `clock_in`, `clock_out`, `break_minutes`, `pay_type`, `notes`, `is_sleepover`, `is_on_call`, `is_public_holiday`, `sleepover_disturbances`, `mileage_km`, `cost_centre`, `project_code`, `amendment_reason`; success app/Http/Controllers/Hr/TimeTrackingController.php:706 `return redirect()->back()->with('success', 'Time entry updated.');`.
- `ROUTE-1780` / `correct`: fields `clock_out`; success app/Http/Controllers/Hr/TimeTrackingController.php:762 `return redirect()->back()->with('success', 'Clock-out corrected.');`.
- `ROUTE-1781` / `addNote`: fields `note`; success app/Http/Controllers/Hr/TimeTrackingController.php:804 `return redirect()->back()->with('success', 'Note added.');`.
- `ROUTE-1782` / `void`: fields `reason`; success app/Http/Controllers/Hr/TimeTrackingController.php:785 `return redirect()->back()->with('success', 'Time entry voided.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/TimeTrackingController.php:685 `return redirect()->back()->with('success', 'Time entry created.');`; app/Http/Controllers/Hr/TimeTrackingController.php:703 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/TimeTrackingController.php:706 `return redirect()->back()->with('success', 'Time entry updated.');`; app/Http/Controllers/Hr/TimeTrackingController.php:731 `return response()->json($amendments);`; app/Http/Controllers/Hr/TimeTrackingController.php:759 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/TimeTrackingController.php:762 `return redirect()->back()->with('success', 'Clock-out corrected.');`; app/Http/Controllers/Hr/TimeTrackingController.php:804 `return redirect()->back()->with('success', 'Note added.');`; app/Http/Controllers/Hr/TimeTrackingController.php:782 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/TimeTrackingController.php:785 `return redirect()->back()->with('success', 'Time entry voided.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/time/entries` — `hr.time.entries.store` — `App\Http\Controllers\Hr\TimeTrackingController@store` — `app/Http/Controllers/Hr/TimeTrackingController.php:678` — middleware `web, auth, permission:timesheets.viewAny`
- `PUT hr/time/entries/{entry}` — `hr.time.entries.update` — `App\Http\Controllers\Hr\TimeTrackingController@updateEntry` — `app/Http/Controllers/Hr/TimeTrackingController.php:692` — middleware `web, auth, permission:timesheets.viewAny, permission:timesheets.manageAny|timesheets.approve`
- `GET|HEAD hr/time/entries/{entry}/amendments` — `hr.time.entries.amendments` — `App\Http\Controllers\Hr\TimeTrackingController@entryAmendments` — `app/Http/Controllers/Hr/TimeTrackingController.php:713` — middleware `web, auth, permission:timesheets.viewAny, permission:timesheets.manageAny|timesheets.approve`
- `POST hr/time/entries/{entry}/correct` — `hr.time.entries.correct` — `App\Http\Controllers\Hr\TimeTrackingController@correct` — `app/Http/Controllers/Hr/TimeTrackingController.php:738` — middleware `web, auth, permission:timesheets.viewAny, permission:timesheets.manageAny|timesheets.approve`
- `POST hr/time/entries/{entry}/note` — `hr.time.entries.note` — `App\Http\Controllers\Hr\TimeTrackingController@addNote` — `app/Http/Controllers/Hr/TimeTrackingController.php:792` — middleware `web, auth, permission:timesheets.viewAny, permission:timesheets.manageAny|timesheets.approve`
- `POST hr/time/entries/{entry}/void` — `hr.time.entries.void` — `App\Http\Controllers\Hr\TimeTrackingController@void` — `app/Http/Controllers/Hr/TimeTrackingController.php:769` — middleware `web, auth, permission:timesheets.viewAny, permission:timesheets.manageAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/TimeTrackingController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
