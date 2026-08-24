# CAP-HR-COMPENSATION-STRUCTURE-HISTORY: Pay bands settings and compensation history

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.compensation.view`, `permission:hr.compensation.manage`
- Owning module: Human resources
- Legacy family: `HR-COMPENSATION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/compensation/bands` (`hr.compensation.bands`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.compensation.view`, `permission:hr.compensation.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.compensation.view`, `permission:hr.compensation.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/compensation/bands` (`hr.compensation.bands`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/compensation/bands/export` (`hr.compensation.bands.export`, action `exportBands`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/CompensationController.php:120-170`.
3. Use `GET|HEAD hr/compensation/history` (`hr.compensation.history.index`, action `historyIndex`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/CompensationController.php:314-335`.
4. Use `GET|HEAD hr/compensation/history/{profile}` (`hr.compensation.history`, action `history`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/CompensationController.php:289-309`.
5. Use `GET|HEAD hr/compensation/settings` (`hr.compensation.settings`, action `settings`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/CompensationController.php:340-362`.
6. Invoke only the owning control for `POST hr/compensation/bands` (`hr.compensation.bands.store`, action `storeBand`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/CompensationController.php:175-202`; `position_role`.
7. Invoke only the owning control for `PUT hr/compensation/bands/{band}` (`hr.compensation.bands.update`, action `updateBand`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/CompensationController.php:207-249`; `position_role`.

## Source-applicable states and transitions

- **information presented** is applicable only to `bands` / `ROUTE-1318` at `app/Http/Controllers/Hr/CompensationController.php:30`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeBand` / `ROUTE-1319` at `app/Http/Controllers/Hr/CompensationController.php:175`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateBand` / `ROUTE-1320` at `app/Http/Controllers/Hr/CompensationController.php:207`; it is not runtime-observed.
- **file/report delivered** is applicable only to `exportBands` / `ROUTE-1321` at `app/Http/Controllers/Hr/CompensationController.php:120`; it is not runtime-observed.
- **information presented** is applicable only to `historyIndex` / `ROUTE-1341` at `app/Http/Controllers/Hr/CompensationController.php:314`; it is not runtime-observed.
- **information presented** is applicable only to `history` / `ROUTE-1342` at `app/Http/Controllers/Hr/CompensationController.php:289`; it is not runtime-observed.
- **information presented** is applicable only to `settings` / `ROUTE-1351` at `app/Http/Controllers/Hr/CompensationController.php:340`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/compensation/bands.tsx`, `resources/js/pages/hr/compensation/history-index.tsx`, `resources/js/pages/hr/compensation/history.tsx`, `resources/js/pages/hr/compensation/settings.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1319` / `storeBand`: fields `position_role`; success app/Http/Controllers/Hr/CompensationController.php:201 `return redirect()->back()->with('success', 'Salary band created.');`.
- `ROUTE-1320` / `updateBand`: fields `position_role`; success app/Http/Controllers/Hr/CompensationController.php:248 `return redirect()->back()->with('success', 'Salary band updated.');`; failure app/Http/Controllers/Hr/CompensationController.php:241 `throw ValidationException::withMessages([`.

## Failure and recovery paths

- `updateBand`: app/Http/Controllers/Hr/CompensationController.php:241 `throw ValidationException::withMessages([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/CompensationController.php:195 `HrSalaryBand::create([`; app/Http/Controllers/Hr/CompensationController.php:246 `$band->update($data);`; responses app/Http/Controllers/Hr/CompensationController.php:73 `return null;`; app/Http/Controllers/Hr/CompensationController.php:76 `return [`; app/Http/Controllers/Hr/CompensationController.php:95 `return $band;`; app/Http/Controllers/Hr/CompensationController.php:98 `return Inertia::render('hr/compensation/bands', [`; app/Http/Controllers/Hr/CompensationController.php:201 `return redirect()->back()->with('success', 'Salary band created.');`; app/Http/Controllers/Hr/CompensationController.php:248 `return redirect()->back()->with('success', 'Salary band updated.');`; app/Http/Controllers/Hr/CompensationController.php:145 `return response()->streamDownload(function () use ($bands) {`; app/Http/Controllers/Hr/CompensationController.php:328 `return Inertia::render('hr/compensation/history-index', [`; app/Http/Controllers/Hr/CompensationController.php:302 `return Inertia::render('hr/compensation/history', [`; app/Http/Controllers/Hr/CompensationController.php:346 `return Inertia::render('hr/compensation/settings', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/compensation/bands` — `hr.compensation.bands` — `App\Http\Controllers\Hr\CompensationController@bands` — `app/Http/Controllers/Hr/CompensationController.php:30` — middleware `web, auth, permission:hr.compensation.view`
- `POST hr/compensation/bands` — `hr.compensation.bands.store` — `App\Http\Controllers\Hr\CompensationController@storeBand` — `app/Http/Controllers/Hr/CompensationController.php:175` — middleware `web, auth, permission:hr.compensation.view, permission:hr.compensation.manage`
- `PUT hr/compensation/bands/{band}` — `hr.compensation.bands.update` — `App\Http\Controllers\Hr\CompensationController@updateBand` — `app/Http/Controllers/Hr/CompensationController.php:207` — middleware `web, auth, permission:hr.compensation.view, permission:hr.compensation.manage`
- `GET|HEAD hr/compensation/bands/export` — `hr.compensation.bands.export` — `App\Http\Controllers\Hr\CompensationController@exportBands` — `app/Http/Controllers/Hr/CompensationController.php:120` — middleware `web, auth, permission:hr.compensation.view`
- `GET|HEAD hr/compensation/history` — `hr.compensation.history.index` — `App\Http\Controllers\Hr\CompensationController@historyIndex` — `app/Http/Controllers/Hr/CompensationController.php:314` — middleware `web, auth, permission:hr.compensation.view`
- `GET|HEAD hr/compensation/history/{profile}` — `hr.compensation.history` — `App\Http\Controllers\Hr\CompensationController@history` — `app/Http/Controllers/Hr/CompensationController.php:289` — middleware `web, auth, permission:hr.compensation.view`
- `GET|HEAD hr/compensation/settings` — `hr.compensation.settings` — `App\Http\Controllers\Hr\CompensationController@settings` — `app/Http/Controllers/Hr/CompensationController.php:340` — middleware `web, auth, permission:hr.compensation.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/CompensationController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/compensation/bands.tsx`, `resources/js/pages/hr/compensation/history-index.tsx`, `resources/js/pages/hr/compensation/history.tsx`, `resources/js/pages/hr/compensation/settings.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
