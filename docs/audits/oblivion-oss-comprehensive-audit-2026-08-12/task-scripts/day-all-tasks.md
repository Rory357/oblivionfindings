# DAY-ALL-TASKS: All Tasks

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`
- Owning module: Frontline and My Day
- Legacy family: `DAY-ALL-TASKS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `tasks` (`tasks.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`.
- Exact middleware atoms: `web`, `auth`, `verified`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD tasks` (`tasks.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD tasks/detail` (`tasks.detail`, action `detail`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/AllTasksController.php:94-166`.
3. Use `GET|HEAD tasks/lookup` (`tasks.lookup`, action `lookup`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/AllTasksController.php:334-354`.
4. Use `GET|HEAD tasks/reports` (`tasks.reports`, action `reports`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/AllTasksController.php:453-541`.
5. Use `GET|HEAD tasks/users` (`tasks.users`, action `users`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/AllTasksController.php:317-329`.
6. Invoke only the owning control for `POST tasks/{source}/{id}/assign` (`tasks.assign`, action `assign`). Source category: **assigned**; controller `app/Http/Controllers/AllTasksController.php:171-210`; `assignee_id`.
7. Invoke only the owning control for `POST tasks/{source}/{id}/split` (`tasks.split`, action `split`). Source category: **mutation outcome source gap (split)**; controller `app/Http/Controllers/AllTasksController.php:255-310`; `title`.
8. Invoke only the owning control for `POST tasks/{source}/{id}/watch` (`tasks.watch`, action `watch`). Source category: **mutation outcome source gap (watch)**; controller `app/Http/Controllers/AllTasksController.php:218-247`; `watching`.
9. Invoke only the owning control for `POST tasks/default-view` (`tasks.default-view`, action `saveDefaultView`). Source category: **mutation outcome source gap (saveDefaultView)**; controller `app/Http/Controllers/AllTasksController.php:359-374`; `view`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2971` at `app/Http/Controllers/AllTasksController.php:34`; it is not runtime-observed.
- **assigned** is applicable only to `assign` / `ROUTE-2972` at `app/Http/Controllers/AllTasksController.php:171`; it is not runtime-observed.
- **mutation outcome source gap (split)** is applicable only to `split` / `ROUTE-2973` at `app/Http/Controllers/AllTasksController.php:255`; it is not runtime-observed.
- **mutation outcome source gap (watch)** is applicable only to `watch` / `ROUTE-2974` at `app/Http/Controllers/AllTasksController.php:218`; it is not runtime-observed.
- **mutation outcome source gap (saveDefaultView)** is applicable only to `saveDefaultView` / `ROUTE-2975` at `app/Http/Controllers/AllTasksController.php:359`; it is not runtime-observed.
- **information presented** is applicable only to `detail` / `ROUTE-2976` at `app/Http/Controllers/AllTasksController.php:94`; it is not runtime-observed.
- **information presented** is applicable only to `lookup` / `ROUTE-2977` at `app/Http/Controllers/AllTasksController.php:334`; it is not runtime-observed.
- **file/report delivered** is applicable only to `reports` / `ROUTE-2978` at `app/Http/Controllers/AllTasksController.php:453`; it is not runtime-observed.
- **information presented** is applicable only to `users` / `ROUTE-2979` at `app/Http/Controllers/AllTasksController.php:317`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/tasks/index.tsx`, `resources/js/pages/tasks/reports.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2972` / `assign`: fields `assignee_id`; success app/Http/Controllers/AllTasksController.php:209 `return back()->with('success', $assigneeId === null ? 'Task unassigned.' : 'Task assigned.');`; failure app/Http/Controllers/AllTasksController.php:185 `throw ValidationException::withMessages([`; app/Http/Controllers/AllTasksController.php:194 `} catch (ValidationException $e) {`.
- `ROUTE-2973` / `split`: fields `title`; success app/Http/Controllers/AllTasksController.php:309 `return back()->with('success', 'Child task created.');`; failure app/Http/Controllers/AllTasksController.php:272 `} catch (ValidationException $e) {`.
- `ROUTE-2974` / `watch`: fields `watching`; success app/Http/Controllers/AllTasksController.php:246 `return back()->with('success', $validated['watching'] ? 'Following this task.' : 'Stopped following.');`.
- `ROUTE-2975` / `saveDefaultView`: fields `view`; success app/Http/Controllers/AllTasksController.php:373 `return back()->with('success', $view === [] ? 'Default view cleared.' : 'Saved as your default view.');`.

## Failure and recovery paths

- `assign`: app/Http/Controllers/AllTasksController.php:185 `throw ValidationException::withMessages([`; app/Http/Controllers/AllTasksController.php:194 `} catch (ValidationException $e) {`.
- `split`: app/Http/Controllers/AllTasksController.php:272 `} catch (ValidationException $e) {`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/AllTasksController.php:240 `->delete();`; app/Http/Controllers/AllTasksController.php:371 `])->save();`; responses app/Http/Controllers/AllTasksController.php:66 `return $this->exportCsv($filtered);`; app/Http/Controllers/AllTasksController.php:76 `return Inertia::render('tasks/index', [`; app/Http/Controllers/AllTasksController.php:197 `return back()->with('error', collect($e->errors())->flatten()->first()`; app/Http/Controllers/AllTasksController.php:209 `return back()->with('success', $assigneeId === null ? 'Task unassigned.' : 'Task assigned.');`; app/Http/Controllers/AllTasksController.php:275 `return back()->with('error', collect($e->errors())->flatten()->first()`; app/Http/Controllers/AllTasksController.php:309 `return back()->with('success', 'Child task created.');`; app/Http/Controllers/AllTasksController.php:246 `return back()->with('success', $validated['watching'] ? 'Following this task.' : 'Stopped following.');`; app/Http/Controllers/AllTasksController.php:373 `return back()->with('success', $view === [] ? 'Default view cleared.' : 'Saved as your default view.');`; app/Http/Controllers/AllTasksController.php:157 `return response()->json([`; app/Http/Controllers/AllTasksController.php:340 `return response()->json(['match' => null]);`; app/Http/Controllers/AllTasksController.php:346 `return response()->json([`; app/Http/Controllers/AllTasksController.php:526 `return Inertia::render('tasks/reports', [`; app/Http/Controllers/AllTasksController.php:328 `return response()->json(['users' => $users]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD tasks` — `tasks.index` — `App\Http\Controllers\AllTasksController@index` — `app/Http/Controllers/AllTasksController.php:34` — middleware `web, auth, verified`
- `POST tasks/{source}/{id}/assign` — `tasks.assign` — `App\Http\Controllers\AllTasksController@assign` — `app/Http/Controllers/AllTasksController.php:171` — middleware `web, auth, verified`
- `POST tasks/{source}/{id}/split` — `tasks.split` — `App\Http\Controllers\AllTasksController@split` — `app/Http/Controllers/AllTasksController.php:255` — middleware `web, auth, verified`
- `POST tasks/{source}/{id}/watch` — `tasks.watch` — `App\Http\Controllers\AllTasksController@watch` — `app/Http/Controllers/AllTasksController.php:218` — middleware `web, auth, verified`
- `POST tasks/default-view` — `tasks.default-view` — `App\Http\Controllers\AllTasksController@saveDefaultView` — `app/Http/Controllers/AllTasksController.php:359` — middleware `web, auth, verified`
- `GET|HEAD tasks/detail` — `tasks.detail` — `App\Http\Controllers\AllTasksController@detail` — `app/Http/Controllers/AllTasksController.php:94` — middleware `web, auth, verified`
- `GET|HEAD tasks/lookup` — `tasks.lookup` — `App\Http\Controllers\AllTasksController@lookup` — `app/Http/Controllers/AllTasksController.php:334` — middleware `web, auth, verified`
- `GET|HEAD tasks/reports` — `tasks.reports` — `App\Http\Controllers\AllTasksController@reports` — `app/Http/Controllers/AllTasksController.php:453` — middleware `web, auth, verified`
- `GET|HEAD tasks/users` — `tasks.users` — `App\Http\Controllers\AllTasksController@users` — `app/Http/Controllers/AllTasksController.php:317` — middleware `web, auth, verified`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/AllTasksController.php`.
- Exact render/action page relationships: `resources/js/pages/tasks/index.tsx`, `resources/js/pages/tasks/reports.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
