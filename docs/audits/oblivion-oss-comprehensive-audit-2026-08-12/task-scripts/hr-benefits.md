# HR-BENEFITS: Benefits

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.benefits.view`, `permission:hr.benefits.manage`
- Owning module: Human resources
- Legacy family: `HR-BENEFITS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/compensation/benefits` (`hr.compensation.benefits.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.benefits.view`, `permission:hr.benefits.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.benefits.view`, `permission:hr.benefits.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/compensation/benefits` (`hr.compensation.benefits.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/compensation/benefits/plans` (`hr.compensation.benefits.plans`, action `plans`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/BenefitsController.php:89-117`.
3. Invoke only the owning control for `POST hr/compensation/benefits/enroll` (`hr.compensation.benefits.enroll`, action `enroll`). Source category: **mutation outcome source gap (enroll)**; controller `app/Http/Controllers/Hr/BenefitsController.php:147-167`; `employee_profile_id`.
4. Invoke only the owning control for `PUT hr/compensation/benefits/enrollments/{enrollment}` (`hr.compensation.benefits.enrollments.update`, action `updateEnrollment`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/BenefitsController.php:172-209`; no exact validation fields extracted.
5. Invoke only the owning control for `POST hr/compensation/benefits/plans` (`hr.compensation.benefits.plans.store`, action `storePlan`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/BenefitsController.php:122-142`; `name`.
6. Invoke only the owning control for `PUT hr/compensation/benefits/plans/{plan}` (`hr.compensation.benefits.plans.update`, action `updatePlan`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/BenefitsController.php:216-233`; `is_active`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1322` at `app/Http/Controllers/Hr/BenefitsController.php:27`; it is not runtime-observed.
- **mutation outcome source gap (enroll)** is applicable only to `enroll` / `ROUTE-1323` at `app/Http/Controllers/Hr/BenefitsController.php:147`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateEnrollment` / `ROUTE-1324` at `app/Http/Controllers/Hr/BenefitsController.php:172`; it is not runtime-observed.
- **information presented** is applicable only to `plans` / `ROUTE-1325` at `app/Http/Controllers/Hr/BenefitsController.php:89`; it is not runtime-observed.
- **created/recorded** is applicable only to `storePlan` / `ROUTE-1326` at `app/Http/Controllers/Hr/BenefitsController.php:122`; it is not runtime-observed.
- **updated/revised** is applicable only to `updatePlan` / `ROUTE-1327` at `app/Http/Controllers/Hr/BenefitsController.php:216`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/compensation/benefits/index.tsx`, `resources/js/pages/hr/compensation/benefits/plans.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1323` / `enroll`: fields `employee_profile_id`; success app/Http/Controllers/Hr/BenefitsController.php:166 `return redirect()->back()->with('success', 'Employee enrolled in benefit plan.');`.
- `ROUTE-1324` / `updateEnrollment`: success app/Http/Controllers/Hr/BenefitsController.php:208 `return redirect()->back()->with('success', 'Enrollment updated.');`.
- `ROUTE-1326` / `storePlan`: fields `name`; success app/Http/Controllers/Hr/BenefitsController.php:141 `return redirect()->back()->with('success', 'Benefit plan created.');`.
- `ROUTE-1327` / `updatePlan`: fields `is_active`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/BenefitsController.php:187 `$enrollment->update($data);`; app/Http/Controllers/Hr/BenefitsController.php:136 `HrBenefitPlan::create([`; app/Http/Controllers/Hr/BenefitsController.php:227 `$plan->update($data);`; responses app/Http/Controllers/Hr/BenefitsController.php:68 `return Inertia::render('hr/compensation/benefits/index', [`; app/Http/Controllers/Hr/BenefitsController.php:166 `return redirect()->back()->with('success', 'Employee enrolled in benefit plan.');`; app/Http/Controllers/Hr/BenefitsController.php:208 `return redirect()->back()->with('success', 'Enrollment updated.');`; app/Http/Controllers/Hr/BenefitsController.php:102 `return Inertia::render('hr/compensation/benefits/plans', [`; app/Http/Controllers/Hr/BenefitsController.php:141 `return redirect()->back()->with('success', 'Benefit plan created.');`; app/Http/Controllers/Hr/BenefitsController.php:229 `return redirect()->back()->with(`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/compensation/benefits` — `hr.compensation.benefits.index` — `App\Http\Controllers\Hr\BenefitsController@index` — `app/Http/Controllers/Hr/BenefitsController.php:27` — middleware `web, auth, permission:hr.benefits.view`
- `POST hr/compensation/benefits/enroll` — `hr.compensation.benefits.enroll` — `App\Http\Controllers\Hr\BenefitsController@enroll` — `app/Http/Controllers/Hr/BenefitsController.php:147` — middleware `web, auth, permission:hr.benefits.view, permission:hr.benefits.manage`
- `PUT hr/compensation/benefits/enrollments/{enrollment}` — `hr.compensation.benefits.enrollments.update` — `App\Http\Controllers\Hr\BenefitsController@updateEnrollment` — `app/Http/Controllers/Hr/BenefitsController.php:172` — middleware `web, auth, permission:hr.benefits.view, permission:hr.benefits.manage`
- `GET|HEAD hr/compensation/benefits/plans` — `hr.compensation.benefits.plans` — `App\Http\Controllers\Hr\BenefitsController@plans` — `app/Http/Controllers/Hr/BenefitsController.php:89` — middleware `web, auth, permission:hr.benefits.view`
- `POST hr/compensation/benefits/plans` — `hr.compensation.benefits.plans.store` — `App\Http\Controllers\Hr\BenefitsController@storePlan` — `app/Http/Controllers/Hr/BenefitsController.php:122` — middleware `web, auth, permission:hr.benefits.view, permission:hr.benefits.manage`
- `PUT hr/compensation/benefits/plans/{plan}` — `hr.compensation.benefits.plans.update` — `App\Http\Controllers\Hr\BenefitsController@updatePlan` — `app/Http/Controllers/Hr/BenefitsController.php:216` — middleware `web, auth, permission:hr.benefits.view, permission:hr.benefits.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/BenefitsController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/compensation/benefits/index.tsx`, `resources/js/pages/hr/compensation/benefits/plans.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
