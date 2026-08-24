# HR-BONUS: Bonus

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.compensation.view`, `permission:hr.compensation.manage`
- Owning module: Human resources
- Legacy family: `HR-BONUS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/compensation/bonuses` (`hr.compensation.bonuses`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.compensation.view`, `permission:hr.compensation.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.compensation.view`, `permission:hr.compensation.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/compensation/bonuses` (`hr.compensation.bonuses`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/compensation/bonuses` (`hr.compensation.bonuses.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/BonusController.php:83-110`; `employee_profile_id`.
3. Invoke only the owning control for `POST hr/compensation/bonuses/{bonus}/approve` (`hr.compensation.bonuses.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Hr/BonusController.php:115-131`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1328` at `app/Http/Controllers/Hr/BonusController.php:25`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1329` at `app/Http/Controllers/Hr/BonusController.php:83`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-1330` at `app/Http/Controllers/Hr/BonusController.php:115`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/compensation/bonuses.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1329` / `store`: fields `employee_profile_id`; success app/Http/Controllers/Hr/BonusController.php:109 `return redirect()->back()->with('success', 'Bonus payment created.');`.
- `ROUTE-1330` / `approve`: success app/Http/Controllers/Hr/BonusController.php:130 `return redirect()->back()->with('success', 'Bonus payment approved.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/BonusController.php:97 `HrBonusPayment::create([`; app/Http/Controllers/Hr/BonusController.php:124 `$bonus->update([`; responses app/Http/Controllers/Hr/BonusController.php:68 `return Inertia::render('hr/compensation/bonuses', [`; app/Http/Controllers/Hr/BonusController.php:109 `return redirect()->back()->with('success', 'Bonus payment created.');`; app/Http/Controllers/Hr/BonusController.php:121 `return redirect()->back()->with('error', 'Only pending bonuses can be approved.');`; app/Http/Controllers/Hr/BonusController.php:130 `return redirect()->back()->with('success', 'Bonus payment approved.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/compensation/bonuses` — `hr.compensation.bonuses` — `App\Http\Controllers\Hr\BonusController@index` — `app/Http/Controllers/Hr/BonusController.php:25` — middleware `web, auth, permission:hr.compensation.view`
- `POST hr/compensation/bonuses` — `hr.compensation.bonuses.store` — `App\Http\Controllers\Hr\BonusController@store` — `app/Http/Controllers/Hr/BonusController.php:83` — middleware `web, auth, permission:hr.compensation.view, permission:hr.compensation.manage`
- `POST hr/compensation/bonuses/{bonus}/approve` — `hr.compensation.bonuses.approve` — `App\Http\Controllers\Hr\BonusController@approve` — `app/Http/Controllers/Hr/BonusController.php:115` — middleware `web, auth, permission:hr.compensation.view, permission:hr.compensation.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/BonusController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/compensation/bonuses.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
