# HR-STAFF-BACKGROUND-CHECK: Staff Background Check

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.vetting.view`, `permission:hr.vetting.manage`
- Owning module: Human resources
- Legacy family: `HR-STAFF-BACKGROUND-CHECK`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `staff/{user}/background-checks` (`staff.background-checks.user`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.vetting.view`, `permission:hr.vetting.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.vetting.view`, `permission:hr.vetting.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD staff/{user}/background-checks` (`staff.background-checks.user`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD staff/{user}/background-checks/create` (`staff.background-checks.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:14-14`.
3. Use `GET|HEAD staff/background-checks` (`staff.background-checks.index`, action `index`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:11-11`.
4. Use `GET|HEAD staff/background-checks/{check}` (`staff.background-checks.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:13-13`.
5. Use `GET|HEAD staff/background-checks/{check}/edit` (`staff.background-checks.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:16-16`.
6. Invoke only the owning control for `POST staff/{user}/background-checks` (`staff.background-checks.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:15-15`; no exact validation fields extracted.
7. Invoke only the owning control for `PUT staff/background-checks/{check}` (`staff.background-checks.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:17-17`; no exact validation fields extracted.
8. Invoke only the owning control for `POST staff/background-checks/{check}/assess-risk` (`staff.background-checks.assess-risk`, action `assessRisk`). Source category: **mutation outcome source gap (assessRisk)**; controller `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:19-19`; no exact validation fields extracted.
9. Invoke only the owning control for `POST staff/background-checks/{check}/verify` (`staff.background-checks.verify`, action `verify`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:18-18`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `userChecks` / `ROUTE-2924` at `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:12`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2925` at `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:15`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2926` at `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:14`; it is not runtime-observed.
- **information presented** is applicable only to `index` / `ROUTE-2935` at `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:11`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2936` at `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:13`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2937` at `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:17`; it is not runtime-observed.
- **mutation outcome source gap (assessRisk)** is applicable only to `assessRisk` / `ROUTE-2938` at `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:19`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-2939` at `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:16`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `verify` / `ROUTE-2940` at `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:18`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Staff/StaffBackgroundCheckController.php:12 `public function userChecks($user): RedirectResponse { return redirect()->route('hr.vetting.index'); }`; app/Http/Controllers/Staff/StaffBackgroundCheckController.php:15 `public function store(Request $request, $user) { return redirect()->back(); }`; app/Http/Controllers/Staff/StaffBackgroundCheckController.php:14 `public function create($user): RedirectResponse { return redirect()->route('hr.vetting.create'); }`; app/Http/Controllers/Staff/StaffBackgroundCheckController.php:11 `public function index(): RedirectResponse { return redirect()->route('hr.vetting.index'); }`; app/Http/Controllers/Staff/StaffBackgroundCheckController.php:13 `public function show($check): RedirectResponse { return redirect()->route('hr.vetting.show', ['check' => $check]); }`; app/Http/Controllers/Staff/StaffBackgroundCheckController.php:17 `public function update(Request $request, $check) { return redirect()->back(); }`; app/Http/Controllers/Staff/StaffBackgroundCheckController.php:19 `public function assessRisk(Request $request, $check) { return redirect()->back(); }`; app/Http/Controllers/Staff/StaffBackgroundCheckController.php:16 `public function edit($check): RedirectResponse { return redirect()->route('hr.vetting.edit', ['check' => $check]); }`; app/Http/Controllers/Staff/StaffBackgroundCheckController.php:18 `public function verify(Request $request, $check) { return redirect()->back(); }`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD staff/{user}/background-checks` — `staff.background-checks.user` — `App\Http\Controllers\Staff\StaffBackgroundCheckController@userChecks` — `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:12` — middleware `web, auth, permission:hr.vetting.view`
- `POST staff/{user}/background-checks` — `staff.background-checks.store` — `App\Http\Controllers\Staff\StaffBackgroundCheckController@store` — `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:15` — middleware `web, auth, permission:hr.vetting.manage`
- `GET|HEAD staff/{user}/background-checks/create` — `staff.background-checks.create` — `App\Http\Controllers\Staff\StaffBackgroundCheckController@create` — `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:14` — middleware `web, auth, permission:hr.vetting.manage`
- `GET|HEAD staff/background-checks` — `staff.background-checks.index` — `App\Http\Controllers\Staff\StaffBackgroundCheckController@index` — `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:11` — middleware `web, auth, permission:hr.vetting.view`
- `GET|HEAD staff/background-checks/{check}` — `staff.background-checks.show` — `App\Http\Controllers\Staff\StaffBackgroundCheckController@show` — `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:13` — middleware `web, auth, permission:hr.vetting.view`
- `PUT staff/background-checks/{check}` — `staff.background-checks.update` — `App\Http\Controllers\Staff\StaffBackgroundCheckController@update` — `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:17` — middleware `web, auth, permission:hr.vetting.manage`
- `POST staff/background-checks/{check}/assess-risk` — `staff.background-checks.assess-risk` — `App\Http\Controllers\Staff\StaffBackgroundCheckController@assessRisk` — `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:19` — middleware `web, auth, permission:hr.vetting.manage`
- `GET|HEAD staff/background-checks/{check}/edit` — `staff.background-checks.edit` — `App\Http\Controllers\Staff\StaffBackgroundCheckController@edit` — `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:16` — middleware `web, auth, permission:hr.vetting.manage`
- `POST staff/background-checks/{check}/verify` — `staff.background-checks.verify` — `App\Http\Controllers\Staff\StaffBackgroundCheckController@verify` — `app/Http/Controllers/Staff/StaffBackgroundCheckController.php:18` — middleware `web, auth, permission:hr.vetting.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Staff/StaffBackgroundCheckController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
