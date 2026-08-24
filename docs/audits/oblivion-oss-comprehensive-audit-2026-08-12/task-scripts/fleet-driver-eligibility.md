# FLEET-DRIVER-ELIGIBILITY: Driver Eligibility

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.driver.view`, `permission:hr.driver.manage`
- Owning module: Fleet and vehicles
- Legacy family: `FLEET-DRIVER-ELIGIBILITY`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/compliance/drivers` (`hr.drivers.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.driver.view`, `permission:hr.driver.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.driver.view`, `permission:hr.driver.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/compliance/drivers` (`hr.drivers.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/compliance/drivers/{eligibility}` (`hr.drivers.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/DriverEligibilityController.php:96-155`.
3. Invoke only the owning control for `POST hr/compliance/drivers` (`hr.drivers.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/DriverEligibilityController.php:161-209`; `user_id`.
4. Invoke only the owning control for `PUT hr/compliance/drivers/{eligibility}` (`hr.drivers.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/DriverEligibilityController.php:215-241`; `licence_number`.
5. Invoke only the owning control for `POST hr/compliance/drivers/{eligibility}/approve` (`hr.drivers.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Hr/DriverEligibilityController.php:247-288`; `notes`.
6. Invoke only the owning control for `POST hr/compliance/drivers/{eligibility}/suspend` (`hr.drivers.suspend`, action `suspend`). Source category: **mutation outcome source gap (suspend)**; controller `app/Http/Controllers/Hr/DriverEligibilityController.php:294-328`; `suspension_reason`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1358` at `app/Http/Controllers/Hr/DriverEligibilityController.php:26`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1359` at `app/Http/Controllers/Hr/DriverEligibilityController.php:161`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1360` at `app/Http/Controllers/Hr/DriverEligibilityController.php:96`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1361` at `app/Http/Controllers/Hr/DriverEligibilityController.php:215`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-1362` at `app/Http/Controllers/Hr/DriverEligibilityController.php:247`; it is not runtime-observed.
- **mutation outcome source gap (suspend)** is applicable only to `suspend` / `ROUTE-1363` at `app/Http/Controllers/Hr/DriverEligibilityController.php:294`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/drivers/index.tsx`, `resources/js/pages/hr/drivers/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1359` / `store`: fields `user_id`; success app/Http/Controllers/Hr/DriverEligibilityController.php:208 `return redirect()->back()->with('success', 'Driver eligibility record created.');`.
- `ROUTE-1361` / `update`: fields `licence_number`; success app/Http/Controllers/Hr/DriverEligibilityController.php:240 `return redirect()->back()->with('success', 'Driver eligibility record updated.');`.
- `ROUTE-1362` / `approve`: fields `notes`; success app/Http/Controllers/Hr/DriverEligibilityController.php:287 `return redirect()->back()->with('success', 'Driver approved to transport clients.');`.
- `ROUTE-1363` / `suspend`: fields `suspension_reason`; success app/Http/Controllers/Hr/DriverEligibilityController.php:327 `return redirect()->back()->with('success', 'Driving privileges suspended.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/DriverEligibilityController.php:197 `HrDriverEligibility::create([`; app/Http/Controllers/Hr/DriverEligibilityController.php:236 `$eligibility->update($validated);`; app/Http/Controllers/Hr/DriverEligibilityController.php:262 `$eligibility->update([`; app/Http/Controllers/Hr/DriverEligibilityController.php:279 `$employeeProfile->update([`; app/Http/Controllers/Hr/DriverEligibilityController.php:305 `$eligibility->update([`; app/Http/Controllers/Hr/DriverEligibilityController.php:319 `$employeeProfile->update([`; responses app/Http/Controllers/Hr/DriverEligibilityController.php:76 `return Inertia::render('hr/drivers/index', [`; app/Http/Controllers/Hr/DriverEligibilityController.php:185 `return redirect()->back()->with('error', 'Selected staff member is not in your HR tenant scope.');`; app/Http/Controllers/Hr/DriverEligibilityController.php:194 `return redirect()->back()->with('error', 'A driver eligibility record already exists for this staff member.');`; app/Http/Controllers/Hr/DriverEligibilityController.php:208 `return redirect()->back()->with('success', 'Driver eligibility record created.');`; app/Http/Controllers/Hr/DriverEligibilityController.php:131 `return Inertia::render('hr/drivers/show', [`; app/Http/Controllers/Hr/DriverEligibilityController.php:240 `return redirect()->back()->with('success', 'Driver eligibility record updated.');`; app/Http/Controllers/Hr/DriverEligibilityController.php:259 `return redirect()->back()->with('error', 'Cannot approve: licence has expired.');`; app/Http/Controllers/Hr/DriverEligibilityController.php:287 `return redirect()->back()->with('success', 'Driver approved to transport clients.');`; app/Http/Controllers/Hr/DriverEligibilityController.php:327 `return redirect()->back()->with('success', 'Driving privileges suspended.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/compliance/drivers` — `hr.drivers.index` — `App\Http\Controllers\Hr\DriverEligibilityController@index` — `app/Http/Controllers/Hr/DriverEligibilityController.php:26` — middleware `web, auth, permission:hr.driver.view`
- `POST hr/compliance/drivers` — `hr.drivers.store` — `App\Http\Controllers\Hr\DriverEligibilityController@store` — `app/Http/Controllers/Hr/DriverEligibilityController.php:161` — middleware `web, auth, permission:hr.driver.view, permission:hr.driver.manage`
- `GET|HEAD hr/compliance/drivers/{eligibility}` — `hr.drivers.show` — `App\Http\Controllers\Hr\DriverEligibilityController@show` — `app/Http/Controllers/Hr/DriverEligibilityController.php:96` — middleware `web, auth, permission:hr.driver.view`
- `PUT hr/compliance/drivers/{eligibility}` — `hr.drivers.update` — `App\Http\Controllers\Hr\DriverEligibilityController@update` — `app/Http/Controllers/Hr/DriverEligibilityController.php:215` — middleware `web, auth, permission:hr.driver.view, permission:hr.driver.manage`
- `POST hr/compliance/drivers/{eligibility}/approve` — `hr.drivers.approve` — `App\Http\Controllers\Hr\DriverEligibilityController@approve` — `app/Http/Controllers/Hr/DriverEligibilityController.php:247` — middleware `web, auth, permission:hr.driver.view, permission:hr.driver.manage`
- `POST hr/compliance/drivers/{eligibility}/suspend` — `hr.drivers.suspend` — `App\Http\Controllers\Hr\DriverEligibilityController@suspend` — `app/Http/Controllers/Hr/DriverEligibilityController.php:294` — middleware `web, auth, permission:hr.driver.view, permission:hr.driver.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/DriverEligibilityController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/drivers/index.tsx`, `resources/js/pages/hr/drivers/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
