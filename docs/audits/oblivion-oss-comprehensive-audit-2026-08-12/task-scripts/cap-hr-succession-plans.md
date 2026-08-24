# CAP-HR-SUCCESSION-PLANS: Succession plan lifecycle

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`
- Owning module: Human resources
- Legacy family: `HR-SUCCESSION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/succession` (`hr.succession.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/succession` (`hr.succession.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/succession/{plan}` (`hr.succession.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/SuccessionController.php:173-235`.
3. Use `GET|HEAD hr/succession/create` (`hr.succession.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/SuccessionController.php:94-100`.
4. Invoke only the owning control for `POST hr/succession` (`hr.succession.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/SuccessionController.php:105-168`; `position_id`.
5. Invoke only the owning control for `DELETE hr/succession/{plan}` (`hr.succession.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/SuccessionController.php:314-322`; no exact validation fields extracted.
6. Invoke only the owning control for `PUT hr/succession/{plan}` (`hr.succession.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/SuccessionController.php:240-257`; `role_title`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1759` at `app/Http/Controllers/Hr/SuccessionController.php:21`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1760` at `app/Http/Controllers/Hr/SuccessionController.php:105`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1761` at `app/Http/Controllers/Hr/SuccessionController.php:314`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1762` at `app/Http/Controllers/Hr/SuccessionController.php:173`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1763` at `app/Http/Controllers/Hr/SuccessionController.php:240`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1768` at `app/Http/Controllers/Hr/SuccessionController.php:94`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/succession/index.tsx`, `resources/js/pages/hr/succession/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1760` / `store`: fields `position_id`; success app/Http/Controllers/Hr/SuccessionController.php:164 `return redirect()->back()->with('success', 'Succession plan created.');`; app/Http/Controllers/Hr/SuccessionController.php:167 `return redirect()->route('hr.succession.index')->with('success', 'Succession plan created.');`.
- `ROUTE-1761` / `destroy`: success app/Http/Controllers/Hr/SuccessionController.php:321 `return redirect()->route('hr.succession.index')->with('success', 'Succession plan deleted.');`.
- `ROUTE-1763` / `update`: fields `role_title`; success app/Http/Controllers/Hr/SuccessionController.php:256 `return redirect()->back()->with('success', 'Succession plan updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/SuccessionController.php:138 `$plan = HrSuccessionPlan::create([`; app/Http/Controllers/Hr/SuccessionController.php:151 `$plan->candidates()->create([`; app/Http/Controllers/Hr/SuccessionController.php:319 `$plan->delete();`; app/Http/Controllers/Hr/SuccessionController.php:254 `$plan->update($data);`; responses app/Http/Controllers/Hr/SuccessionController.php:64 `return Inertia::render('hr/succession/index', [`; app/Http/Controllers/Hr/SuccessionController.php:164 `return redirect()->back()->with('success', 'Succession plan created.');`; app/Http/Controllers/Hr/SuccessionController.php:167 `return redirect()->route('hr.succession.index')->with('success', 'Succession plan created.');`; app/Http/Controllers/Hr/SuccessionController.php:321 `return redirect()->route('hr.succession.index')->with('success', 'Succession plan deleted.');`; app/Http/Controllers/Hr/SuccessionController.php:193 `return Inertia::render('hr/succession/show', [`; app/Http/Controllers/Hr/SuccessionController.php:256 `return redirect()->back()->with('success', 'Succession plan updated.');`; app/Http/Controllers/Hr/SuccessionController.php:99 `return redirect()->route('hr.succession.index', ['new' => 1]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/succession` — `hr.succession.index` — `App\Http\Controllers\Hr\SuccessionController@index` — `app/Http/Controllers/Hr/SuccessionController.php:21` — middleware `web, auth, permission:hr.performance.view`
- `POST hr/succession` — `hr.succession.store` — `App\Http\Controllers\Hr\SuccessionController@store` — `app/Http/Controllers/Hr/SuccessionController.php:105` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `DELETE hr/succession/{plan}` — `hr.succession.destroy` — `App\Http\Controllers\Hr\SuccessionController@destroy` — `app/Http/Controllers/Hr/SuccessionController.php:314` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `GET|HEAD hr/succession/{plan}` — `hr.succession.show` — `App\Http\Controllers\Hr\SuccessionController@show` — `app/Http/Controllers/Hr/SuccessionController.php:173` — middleware `web, auth, permission:hr.performance.view`
- `PUT hr/succession/{plan}` — `hr.succession.update` — `App\Http\Controllers\Hr\SuccessionController@update` — `app/Http/Controllers/Hr/SuccessionController.php:240` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `GET|HEAD hr/succession/create` — `hr.succession.create` — `App\Http\Controllers\Hr\SuccessionController@create` — `app/Http/Controllers/Hr/SuccessionController.php:94` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/SuccessionController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/succession/index.tsx`, `resources/js/pages/hr/succession/show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
