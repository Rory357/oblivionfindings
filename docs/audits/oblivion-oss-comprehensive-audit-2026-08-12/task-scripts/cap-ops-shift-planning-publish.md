# CAP-OPS-SHIFT-PLANNING-PUBLISH: Shift planning series duplication and publication

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:shifts.viewAny|shifts.viewAssigned`, `permission:shifts.create`, `permission:shifts.update`, `permission:shifts.manageAny`, `permission:shifts.create|shifts.update`
- Owning module: Operations and rostering
- Legacy family: `OPS-SHIFT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/shifts` (`operations.shifts.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:shifts.viewAny|shifts.viewAssigned`, `permission:shifts.create`, `permission:shifts.update`, `permission:shifts.manageAny`, `permission:shifts.create|shifts.update`.
- Exact middleware atoms: `web`, `auth`, `permission:shifts.viewAny|shifts.viewAssigned`, `role_scope:my-day`, `permission:shifts.create`, `permission:shifts.update`, `permission:shifts.manageAny`, `permission:shifts.create|shifts.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/shifts` (`operations.shifts.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/shifts/{shift}` (`operations.shifts.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ShiftController.php:273-682`.
3. Use `GET|HEAD operations/shifts/{shift}/editable` (`operations.shifts.editable`, action `editable`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ShiftController.php:1127-1148`.
4. Use `GET|HEAD operations/shifts/create` (`operations.shifts.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ShiftController.php:684-814`.
5. Use `GET|HEAD operations/shifts/eligibility-preview` (`operations.shifts.eligibility_preview`, action `eligibilityPreview`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ShiftController.php:820-855`.
6. Invoke only the owning control for `POST operations/shifts` (`operations.shifts.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/ShiftController.php:857-1012`; `client_id`.
7. Invoke only the owning control for `PUT operations/shifts/{shift}` (`operations.shifts.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/ShiftController.php:1194-1526`; `client_id`.
8. Invoke only the owning control for `POST operations/shifts/{shift}/duplicate` (`operations.shifts.duplicate`, action `duplicate`). Source category: **mutation outcome source gap (duplicate)**; controller `app/Http/Controllers/ShiftController.php:1014-1125`; `date`.
9. Invoke only the owning control for `POST operations/shifts/{shift}/promote-to-series` (`operations.shifts.promoteToSeries`, action `promoteToSeries`). Source category: **mutation outcome source gap (promoteToSeries)**; controller `app/Http/Controllers/ShiftController.php:1700-1765`; `weekdays`.
10. Invoke only the owning control for `PATCH operations/shifts/{shift}/publish` (`operations.shifts.publishShift`, action `publishShift`). Source category: **mutation outcome source gap (publishShift)**; controller `app/Http/Controllers/ShiftController.php:1767-1790`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2191` at `app/Http/Controllers/ShiftController.php:56`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2192` at `app/Http/Controllers/ShiftController.php:857`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2193` at `app/Http/Controllers/ShiftController.php:273`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2194` at `app/Http/Controllers/ShiftController.php:1194`; it is not runtime-observed.
- **mutation outcome source gap (duplicate)** is applicable only to `duplicate` / `ROUTE-2201` at `app/Http/Controllers/ShiftController.php:1014`; it is not runtime-observed.
- **information presented** is applicable only to `editable` / `ROUTE-2202` at `app/Http/Controllers/ShiftController.php:1127`; it is not runtime-observed.
- **mutation outcome source gap (promoteToSeries)** is applicable only to `promoteToSeries` / `ROUTE-2205` at `app/Http/Controllers/ShiftController.php:1700`; it is not runtime-observed.
- **mutation outcome source gap (publishShift)** is applicable only to `publishShift` / `ROUTE-2206` at `app/Http/Controllers/ShiftController.php:1767`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2213` at `app/Http/Controllers/ShiftController.php:684`; it is not runtime-observed.
- **information presented** is applicable only to `eligibilityPreview` / `ROUTE-2214` at `app/Http/Controllers/ShiftController.php:820`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/shifts/index.tsx`, `resources/js/pages/operations/shifts/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2192` / `store`: fields `client_id`; success app/Http/Controllers/ShiftController.php:1011 `return redirect($data['return_to'] ?? route('operations.shifts.index'))->with('success', 'Shift created.');`; failure app/Http/Controllers/ShiftController.php:909 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:931 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:977 `throw $e;`.
- `ROUTE-2193` / `show`: failure app/Http/Controllers/ShiftController.php:279 `abort(403);`.
- `ROUTE-2194` / `update`: fields `client_id`; failure app/Http/Controllers/ShiftController.php:1209 `abort(403);`; app/Http/Controllers/ShiftController.php:1278 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1284 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1290 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1300 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1360 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1370 `throw $e;`; app/Http/Controllers/ShiftController.php:1391 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1412 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1433 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1461 `throw $e;`.
- `ROUTE-2201` / `duplicate`: fields `date`; success app/Http/Controllers/ShiftController.php:1123 `->with('success', 'Shift duplicated as draft.')`; failure app/Http/Controllers/ShiftController.php:1026 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1056 `return back()->withErrors([`.
- `ROUTE-2202` / `editable`: failure app/Http/Controllers/ShiftController.php:1134 `abort(403);`.
- `ROUTE-2205` / `promoteToSeries`: fields `weekdays`; success app/Http/Controllers/ShiftController.php:1764 `return back()->with('success', "Shift promoted to recurring series (series #{$series->id}). Future occurrences will need to be generated separately.");`; failure app/Http/Controllers/ShiftController.php:1731 `return back()->withErrors([`.
- `ROUTE-2206` / `publishShift`: success app/Http/Controllers/ShiftController.php:1789 `return back()->with('success', $message);`.
- `ROUTE-2214` / `eligibilityPreview`: fields `user_id`.

## Failure and recovery paths

- `store`: app/Http/Controllers/ShiftController.php:909 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:931 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:977 `throw $e;`.
- `show`: app/Http/Controllers/ShiftController.php:279 `abort(403);`.
- `update`: app/Http/Controllers/ShiftController.php:1209 `abort(403);`; app/Http/Controllers/ShiftController.php:1278 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1284 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1290 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1300 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1360 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1370 `throw $e;`; app/Http/Controllers/ShiftController.php:1391 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1412 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1433 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1461 `throw $e;`.
- `duplicate`: app/Http/Controllers/ShiftController.php:1026 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1056 `return back()->withErrors([`.
- `editable`: app/Http/Controllers/ShiftController.php:1134 `abort(403);`.
- `promoteToSeries`: app/Http/Controllers/ShiftController.php:1731 `return back()->withErrors([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ShiftController.php:961 `$shift = Shift::create([`; app/Http/Controllers/ShiftController.php:1443 `$lockedShift->update(Arr::except($data, ['tasks', 'series_scope', 'coverage_reservation_token', 'coverage_rule_id']));`; app/Http/Controllers/ShiftController.php:1451 `ShiftEligibilityOverride::create([`; app/Http/Controllers/ShiftController.php:1094 `$copy->save();`; app/Http/Controllers/ShiftController.php:1102 `ShiftTask::create([`; app/Http/Controllers/ShiftController.php:1737 `$series = ShiftSeries::create([`; app/Http/Controllers/ShiftController.php:1759 `$shift->forceFill(['shift_series_id' => $series->id])->save();`; app/Http/Controllers/ShiftController.php:1783 `])->save();`; responses app/Http/Controllers/ShiftController.php:213 `return 0;`; app/Http/Controllers/ShiftController.php:216 `return $s->starts_at->diffInMinutes($s->ends_at);`; app/Http/Controllers/ShiftController.php:233 `return inertia('operations/shifts/index', [`; app/Http/Controllers/ShiftController.php:909 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:931 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:973 `return $shift;`; app/Http/Controllers/ShiftController.php:1011 `return redirect($data['return_to'] ?? route('operations.shifts.index'))->with('success', 'Shift created.');`; app/Http/Controllers/ShiftController.php:509 `return inertia('operations/shifts/show', [`; app/Http/Controllers/ShiftController.php:638 `return $timesheet;`; app/Http/Controllers/ShiftController.php:657 `return $h ? [`; app/Http/Controllers/ShiftController.php:1216 `return back()->with('error', 'This shift is locked and can no longer be edited.');`; app/Http/Controllers/ShiftController.php:1278 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1284 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1290 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1300 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1360 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1391 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1398 `return back()`; app/Http/Controllers/ShiftController.php:1412 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1433 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1522 `return redirect($returnTo)->with(`; app/Http/Controllers/ShiftController.php:1026 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1056 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1114 `return $copy;`; app/Http/Controllers/ShiftController.php:1122 `return redirect($returnTo)`; app/Http/Controllers/ShiftController.php:1147 `return response()->json($this->editableShiftPayload($shift));`; app/Http/Controllers/ShiftController.php:1707 `return back()->with('error', 'Only draft or scheduled shifts can be promoted to a recurring series.');`; app/Http/Controllers/ShiftController.php:1711 `return back()->with('error', 'This shift is already part of a recurring series.');`; app/Http/Controllers/ShiftController.php:1715 `return back()->with('error', 'Source shift must have a start and end time.');`; app/Http/Controllers/ShiftController.php:1731 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1761 `return $series;`; app/Http/Controllers/ShiftController.php:1764 `return back()->with('success', "Shift promoted to recurring series (series #{$series->id}). Future occurrences will need to be generated separately.");`; app/Http/Controllers/ShiftController.php:1774 `return back()->with('error', 'Only draft shifts can be published.');`; app/Http/Controllers/ShiftController.php:1789 `return back()->with('success', $message);`; app/Http/Controllers/ShiftController.php:804 `return response()->json($props);`; app/Http/Controllers/ShiftController.php:810 `return redirect()->route('operations.shifts.index', array_merge(`; app/Http/Controllers/ShiftController.php:854 `return response()->json($result->toArray());`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/shifts` — `operations.shifts.index` — `App\Http\Controllers\ShiftController@index` — `app/Http/Controllers/ShiftController.php:56` — middleware `web, auth, permission:shifts.viewAny|shifts.viewAssigned, role_scope:my-day`
- `POST operations/shifts` — `operations.shifts.store` — `App\Http\Controllers\ShiftController@store` — `app/Http/Controllers/ShiftController.php:857` — middleware `web, auth, permission:shifts.create`
- `GET|HEAD operations/shifts/{shift}` — `operations.shifts.show` — `App\Http\Controllers\ShiftController@show` — `app/Http/Controllers/ShiftController.php:273` — middleware `web, auth, permission:shifts.viewAny|shifts.viewAssigned`
- `PUT operations/shifts/{shift}` — `operations.shifts.update` — `App\Http\Controllers\ShiftController@update` — `app/Http/Controllers/ShiftController.php:1194` — middleware `web, auth, permission:shifts.update`
- `POST operations/shifts/{shift}/duplicate` — `operations.shifts.duplicate` — `App\Http\Controllers\ShiftController@duplicate` — `app/Http/Controllers/ShiftController.php:1014` — middleware `web, auth, permission:shifts.create`
- `GET|HEAD operations/shifts/{shift}/editable` — `operations.shifts.editable` — `App\Http\Controllers\ShiftController@editable` — `app/Http/Controllers/ShiftController.php:1127` — middleware `web, auth, permission:shifts.update`
- `POST operations/shifts/{shift}/promote-to-series` — `operations.shifts.promoteToSeries` — `App\Http\Controllers\ShiftController@promoteToSeries` — `app/Http/Controllers/ShiftController.php:1700` — middleware `web, auth, permission:shifts.manageAny`
- `PATCH operations/shifts/{shift}/publish` — `operations.shifts.publishShift` — `App\Http\Controllers\ShiftController@publishShift` — `app/Http/Controllers/ShiftController.php:1767` — middleware `web, auth, permission:shifts.manageAny`
- `GET|HEAD operations/shifts/create` — `operations.shifts.create` — `App\Http\Controllers\ShiftController@create` — `app/Http/Controllers/ShiftController.php:684` — middleware `web, auth, permission:shifts.create`
- `GET|HEAD operations/shifts/eligibility-preview` — `operations.shifts.eligibility_preview` — `App\Http\Controllers\ShiftController@eligibilityPreview` — `app/Http/Controllers/ShiftController.php:820` — middleware `web, auth, permission:shifts.create|shifts.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ShiftController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/shifts/index.tsx`, `resources/js/pages/operations/shifts/show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
