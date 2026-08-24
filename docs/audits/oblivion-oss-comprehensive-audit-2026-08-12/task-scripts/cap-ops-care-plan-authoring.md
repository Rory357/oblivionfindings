# CAP-OPS-CARE-PLAN-AUTHORING: Care plan authoring and export

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:care_plans.viewAny`, `permission:care_plans.create`, `permission:care_plans.delete`, `permission:care_plans.update`
- Owning module: Operations and rostering
- Legacy family: `OPS-CARE-PLAN`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/care-plans` (`operations.care_plans.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:care_plans.viewAny`, `permission:care_plans.create`, `permission:care_plans.delete`, `permission:care_plans.update`.
- Exact middleware atoms: `web`, `auth`, `permission:care_plans.viewAny`, `permission:care_plans.create`, `permission:care_plans.delete`, `permission:care_plans.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/care-plans` (`operations.care_plans.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/care-plans/{carePlan}` (`operations.care_plans.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/CarePlanController.php:222-300`.
3. Use `GET|HEAD operations/care-plans/{carePlan}/edit` (`operations.care_plans.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/CarePlanController.php:302-321`.
4. Use `GET|HEAD operations/care-plans/{carePlan}/pdf` (`operations.care_plans.pdf`, action `exportPdf`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Operations/CarePlanController.php:619-658`.
5. Use `GET|HEAD operations/care-plans/create` (`operations.care_plans.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/CarePlanController.php:102-115`.
6. Invoke only the owning control for `POST operations/care-plans` (`operations.care_plans.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/CarePlanController.php:117-220`; `client_id`.
7. Invoke only the owning control for `DELETE operations/care-plans/{carePlan}` (`operations.care_plans.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Operations/CarePlanController.php:525-541`; no exact validation fields extracted.
8. Invoke only the owning control for `PUT operations/care-plans/{carePlan}` (`operations.care_plans.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/CarePlanController.php:323-376`; `client_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1905` at `app/Http/Controllers/Operations/CarePlanController.php:25`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1906` at `app/Http/Controllers/Operations/CarePlanController.php:117`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1907` at `app/Http/Controllers/Operations/CarePlanController.php:525`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1908` at `app/Http/Controllers/Operations/CarePlanController.php:222`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1909` at `app/Http/Controllers/Operations/CarePlanController.php:323`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-1911` at `app/Http/Controllers/Operations/CarePlanController.php:302`; it is not runtime-observed.
- **file/report delivered** is applicable only to `exportPdf` / `ROUTE-1922` at `app/Http/Controllers/Operations/CarePlanController.php:619`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1926` at `app/Http/Controllers/Operations/CarePlanController.php:102`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/care-plans/Create.tsx`, `resources/js/pages/operations/care-plans/Edit.tsx`, `resources/js/pages/operations/care-plans/Index.tsx`, `resources/js/pages/operations/care-plans/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1905` / `index`: fields `q`.
- `ROUTE-1906` / `store`: fields `client_id`; success app/Http/Controllers/Operations/CarePlanController.php:215 `->with('success', 'Care plan created and onboarding step completed.');`; app/Http/Controllers/Operations/CarePlanController.php:219 `->with('success', 'Care plan created.');`; failure app/Http/Controllers/Operations/CarePlanController.php:153 `throw ValidationException::withMessages([`.
- `ROUTE-1907` / `destroy`: success app/Http/Controllers/Operations/CarePlanController.php:540 `->with('success', 'Care plan deleted.');`.
- `ROUTE-1909` / `update`: fields `client_id`; success app/Http/Controllers/Operations/CarePlanController.php:375 `->with('success', 'Care plan updated.');`; failure app/Http/Controllers/Operations/CarePlanController.php:360 `throw ValidationException::withMessages([`; app/Http/Controllers/Operations/CarePlanController.php:369 `return back()->withErrors(['goals' => 'Cannot activate a care plan without at least one goal or support domain.']);`.

## Failure and recovery paths

- `store`: app/Http/Controllers/Operations/CarePlanController.php:153 `throw ValidationException::withMessages([`.
- `update`: app/Http/Controllers/Operations/CarePlanController.php:360 `throw ValidationException::withMessages([`; app/Http/Controllers/Operations/CarePlanController.php:369 `return back()->withErrors(['goals' => 'Cannot activate a care plan without at least one goal or support domain.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/CarePlanController.php:158 `$carePlan = CarePlan::create([`; app/Http/Controllers/Operations/CarePlanController.php:205 `$step->update([`; app/Http/Controllers/Operations/CarePlanController.php:537 `$carePlan->delete();`; app/Http/Controllers/Operations/CarePlanController.php:372 `$carePlan->update($data);`; responses app/Http/Controllers/Operations/CarePlanController.php:93 `return inertia('operations/care-plans/Index', [`; app/Http/Controllers/Operations/CarePlanController.php:214 `return redirect("/operations/clients/{$data['client_id']}?tab=onboarding")`; app/Http/Controllers/Operations/CarePlanController.php:218 `return redirect("/operations/clients/{$data['client_id']}?tab=care_plans")`; app/Http/Controllers/Operations/CarePlanController.php:539 `return redirect()->route('operations.care_plans.index')`; app/Http/Controllers/Operations/CarePlanController.php:293 `return inertia('operations/care-plans/Show', [`; app/Http/Controllers/Operations/CarePlanController.php:369 `return back()->withErrors(['goals' => 'Cannot activate a care plan without at least one goal or support domain.']);`; app/Http/Controllers/Operations/CarePlanController.php:374 `return redirect("/operations/clients/{$carePlan->client_id}?tab=care_plans")`; app/Http/Controllers/Operations/CarePlanController.php:317 `return inertia('operations/care-plans/Edit', [`; app/Http/Controllers/Operations/CarePlanController.php:657 `return $pdf->download($filename);`; app/Http/Controllers/Operations/CarePlanController.php:112 `return inertia('operations/care-plans/Create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/care-plans` — `operations.care_plans.index` — `App\Http\Controllers\Operations\CarePlanController@index` — `app/Http/Controllers/Operations/CarePlanController.php:25` — middleware `web, auth, permission:care_plans.viewAny`
- `POST operations/care-plans` — `operations.care_plans.store` — `App\Http\Controllers\Operations\CarePlanController@store` — `app/Http/Controllers/Operations/CarePlanController.php:117` — middleware `web, auth, permission:care_plans.create`
- `DELETE operations/care-plans/{carePlan}` — `operations.care_plans.destroy` — `App\Http\Controllers\Operations\CarePlanController@destroy` — `app/Http/Controllers/Operations/CarePlanController.php:525` — middleware `web, auth, permission:care_plans.delete`
- `GET|HEAD operations/care-plans/{carePlan}` — `operations.care_plans.show` — `App\Http\Controllers\Operations\CarePlanController@show` — `app/Http/Controllers/Operations/CarePlanController.php:222` — middleware `web, auth, permission:care_plans.viewAny`
- `PUT operations/care-plans/{carePlan}` — `operations.care_plans.update` — `App\Http\Controllers\Operations\CarePlanController@update` — `app/Http/Controllers/Operations/CarePlanController.php:323` — middleware `web, auth, permission:care_plans.update`
- `GET|HEAD operations/care-plans/{carePlan}/edit` — `operations.care_plans.edit` — `App\Http\Controllers\Operations\CarePlanController@edit` — `app/Http/Controllers/Operations/CarePlanController.php:302` — middleware `web, auth, permission:care_plans.update`
- `GET|HEAD operations/care-plans/{carePlan}/pdf` — `operations.care_plans.pdf` — `App\Http\Controllers\Operations\CarePlanController@exportPdf` — `app/Http/Controllers/Operations/CarePlanController.php:619` — middleware `web, auth, permission:care_plans.viewAny`
- `GET|HEAD operations/care-plans/create` — `operations.care_plans.create` — `App\Http\Controllers\Operations\CarePlanController@create` — `app/Http/Controllers/Operations/CarePlanController.php:102` — middleware `web, auth, permission:care_plans.create`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/CarePlanController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/care-plans/Create.tsx`, `resources/js/pages/operations/care-plans/Edit.tsx`, `resources/js/pages/operations/care-plans/Index.tsx`, `resources/js/pages/operations/care-plans/Show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
