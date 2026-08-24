# HR-STAFF-INDUCTION: Staff Induction

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:training.viewAny`, `permission:training.record`
- Owning module: Human resources
- Legacy family: `HR-STAFF-INDUCTION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `staff/{user}/induction` (`staff.induction.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:training.viewAny`, `permission:training.record`.
- Exact middleware atoms: `web`, `auth`, `permission:training.viewAny`, `permission:training.record`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD staff/{user}/induction` (`staff.induction.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST staff/{user}/induction` (`staff.induction.create`, action `create`). Source category: **created/recorded**; controller `app/Http/Controllers/Training/StaffInductionController.php:12-12`; no exact validation fields extracted.
3. Invoke only the owning control for `PUT staff/induction/{induction}` (`staff.induction.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Training/StaffInductionController.php:13-13`; no exact validation fields extracted.
4. Invoke only the owning control for `POST staff/induction/{induction}/complete` (`staff.induction.complete`, action `complete`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Training/StaffInductionController.php:14-14`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `show` / `ROUTE-2932` at `app/Http/Controllers/Training/StaffInductionController.php:11`; it is not runtime-observed.
- **created/recorded** is applicable only to `create` / `ROUTE-2933` at `app/Http/Controllers/Training/StaffInductionController.php:12`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2941` at `app/Http/Controllers/Training/StaffInductionController.php:13`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `complete` / `ROUTE-2942` at `app/Http/Controllers/Training/StaffInductionController.php:14`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Training/StaffInductionController.php:11 `public function show($user): RedirectResponse { return redirect()->route('hr.onboarding.index'); }`; app/Http/Controllers/Training/StaffInductionController.php:12 `public function create(Request $request, $user) { return redirect()->back(); }`; app/Http/Controllers/Training/StaffInductionController.php:13 `public function update(Request $request, $induction) { return redirect()->back(); }`; app/Http/Controllers/Training/StaffInductionController.php:14 `public function complete(Request $request, $induction) { return redirect()->back(); }`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD staff/{user}/induction` — `staff.induction.show` — `App\Http\Controllers\Training\StaffInductionController@show` — `app/Http/Controllers/Training/StaffInductionController.php:11` — middleware `web, auth, permission:training.viewAny`
- `POST staff/{user}/induction` — `staff.induction.create` — `App\Http\Controllers\Training\StaffInductionController@create` — `app/Http/Controllers/Training/StaffInductionController.php:12` — middleware `web, auth, permission:training.record`
- `PUT staff/induction/{induction}` — `staff.induction.update` — `App\Http\Controllers\Training\StaffInductionController@update` — `app/Http/Controllers/Training/StaffInductionController.php:13` — middleware `web, auth, permission:training.record`
- `POST staff/induction/{induction}/complete` — `staff.induction.complete` — `App\Http\Controllers\Training\StaffInductionController@complete` — `app/Http/Controllers/Training/StaffInductionController.php:14` — middleware `web, auth, permission:training.record`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Training/StaffInductionController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
