# RESP-RESPITE-DAILY-NOTE: Respite Daily Note

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:respite.daily-notes.view`, `permission:respite.daily-notes.manage`
- Owning module: Respite
- Legacy family: `RESP-RESPITE-DAILY-NOTE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `respite/daily-notes` (`respite.daily-notes.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:respite.daily-notes.view`, `permission:respite.daily-notes.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:respite.daily-notes.view`, `permission:respite.daily-notes.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD respite/daily-notes` (`respite.daily-notes.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD respite/daily-notes/{dailyNote}` (`respite.daily-notes.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteDailyNoteController.php:116-135`.
3. Use `GET|HEAD respite/daily-notes/create` (`respite.daily-notes.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteDailyNoteController.php:42-58`.
4. Use `GET|HEAD respite/daily-notes/with-concerns` (`respite.daily-notes.with-concerns`, action `withConcerns`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteDailyNoteController.php:212-223`.
5. Use `GET|HEAD respite/daily-notes/with-incidents` (`respite.daily-notes.with-incidents`, action `withIncidents`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteDailyNoteController.php:225-236`.
6. Use `GET|HEAD respite/stays/{stay}/daily-notes` (`respite.stays.daily-notes`, action `forStay`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteDailyNoteController.php:188-210`.
7. Invoke only the owning control for `POST respite/daily-notes` (`respite.daily-notes.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Respite/RespiteDailyNoteController.php:60-114`; `stay_id`, `client_id`, `note_date`, `shift_period`, `mood`, `appetite`, `sleep_quality`, `engagement`, `taha_wairua`, `taha_whanau`, `whanau_contact`, `cultural_support_provided`, `mobility`, `activities`, `observations`, `concerns`, `goals_progress`, `medication_notes`, `personal_care_notes`, `nutrition_notes`, `incident_occurred`, `linked_incident_id`, `sensitive_flag`.
8. Invoke only the owning control for `PUT respite/daily-notes/{dailyNote}` (`respite.daily-notes.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Respite/RespiteDailyNoteController.php:137-186`; `mood`, `appetite`, `sleep_quality`, `engagement`, `taha_wairua`, `taha_whanau`, `whanau_contact`, `cultural_support_provided`, `mobility`, `activities`, `observations`, `concerns`, `goals_progress`, `medication_notes`, `personal_care_notes`, `nutrition_notes`, `incident_occurred`, `linked_incident_id`, `sensitive_flag`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2378` at `app/Http/Controllers/Respite/RespiteDailyNoteController.php:18`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2379` at `app/Http/Controllers/Respite/RespiteDailyNoteController.php:60`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2380` at `app/Http/Controllers/Respite/RespiteDailyNoteController.php:116`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2381` at `app/Http/Controllers/Respite/RespiteDailyNoteController.php:137`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2382` at `app/Http/Controllers/Respite/RespiteDailyNoteController.php:42`; it is not runtime-observed.
- **information presented** is applicable only to `withConcerns` / `ROUTE-2383` at `app/Http/Controllers/Respite/RespiteDailyNoteController.php:212`; it is not runtime-observed.
- **information presented** is applicable only to `withIncidents` / `ROUTE-2384` at `app/Http/Controllers/Respite/RespiteDailyNoteController.php:225`; it is not runtime-observed.
- **information presented** is applicable only to `forStay` / `ROUTE-2449` at `app/Http/Controllers/Respite/RespiteDailyNoteController.php:188`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/respite/daily-notes/create.tsx`, `resources/js/pages/respite/daily-notes/for-stay.tsx`, `resources/js/pages/respite/daily-notes/index.tsx`, `resources/js/pages/respite/daily-notes/show.tsx`, `resources/js/pages/respite/daily-notes/with-concerns.tsx`, `resources/js/pages/respite/daily-notes/with-incidents.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2379` / `store`: fields `stay_id`, `client_id`, `note_date`, `shift_period`, `mood`, `appetite`, `sleep_quality`, `engagement`, `taha_wairua`, `taha_whanau`, `whanau_contact`, `cultural_support_provided`, `mobility`, `activities`, `observations`, `concerns`, `goals_progress`, `medication_notes`, `personal_care_notes`, `nutrition_notes`, `incident_occurred`, `linked_incident_id`, `sensitive_flag`; success app/Http/Controllers/Respite/RespiteDailyNoteController.php:113 `->with('success', 'Daily note created.');`.
- `ROUTE-2381` / `update`: fields `mood`, `appetite`, `sleep_quality`, `engagement`, `taha_wairua`, `taha_whanau`, `whanau_contact`, `cultural_support_provided`, `mobility`, `activities`, `observations`, `concerns`, `goals_progress`, `medication_notes`, `personal_care_notes`, `nutrition_notes`, `incident_occurred`, `linked_incident_id`, `sensitive_flag`; success app/Http/Controllers/Respite/RespiteDailyNoteController.php:185 `return back()->with('success', 'Daily note updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Respite/RespiteDailyNoteController.php:90 `$note = RespiteDailyNote::create($validated);`; app/Http/Controllers/Respite/RespiteDailyNoteController.php:167 `$dailyNote->update($validated);`; responses app/Http/Controllers/Respite/RespiteDailyNoteController.php:34 `return Inertia::render('respite/daily-notes/index', [`; app/Http/Controllers/Respite/RespiteDailyNoteController.php:111 `return redirect()`; app/Http/Controllers/Respite/RespiteDailyNoteController.php:130 `return Inertia::render('respite/daily-notes/show', [`; app/Http/Controllers/Respite/RespiteDailyNoteController.php:185 `return back()->with('success', 'Daily note updated.');`; app/Http/Controllers/Respite/RespiteDailyNoteController.php:50 `return Inertia::render('respite/daily-notes/create', [`; app/Http/Controllers/Respite/RespiteDailyNoteController.php:220 `return Inertia::render('respite/daily-notes/with-concerns', [`; app/Http/Controllers/Respite/RespiteDailyNoteController.php:233 `return Inertia::render('respite/daily-notes/with-incidents', [`; app/Http/Controllers/Respite/RespiteDailyNoteController.php:205 `return Inertia::render('respite/daily-notes/for-stay', [`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Respite/RespiteDailyNoteController.php:103 `event(new RespiteEvent('respite.daily_note.created', [`; app/Http/Controllers/Respite/RespiteDailyNoteController.php:180 `event(new RespiteEvent('respite.daily_note.updated', [`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD respite/daily-notes` — `respite.daily-notes.index` — `App\Http\Controllers\Respite\RespiteDailyNoteController@index` — `app/Http/Controllers/Respite/RespiteDailyNoteController.php:18` — middleware `web, auth, permission:respite.daily-notes.view`
- `POST respite/daily-notes` — `respite.daily-notes.store` — `App\Http\Controllers\Respite\RespiteDailyNoteController@store` — `app/Http/Controllers/Respite/RespiteDailyNoteController.php:60` — middleware `web, auth, permission:respite.daily-notes.manage`
- `GET|HEAD respite/daily-notes/{dailyNote}` — `respite.daily-notes.show` — `App\Http\Controllers\Respite\RespiteDailyNoteController@show` — `app/Http/Controllers/Respite/RespiteDailyNoteController.php:116` — middleware `web, auth, permission:respite.daily-notes.view`
- `PUT respite/daily-notes/{dailyNote}` — `respite.daily-notes.update` — `App\Http\Controllers\Respite\RespiteDailyNoteController@update` — `app/Http/Controllers/Respite/RespiteDailyNoteController.php:137` — middleware `web, auth, permission:respite.daily-notes.manage`
- `GET|HEAD respite/daily-notes/create` — `respite.daily-notes.create` — `App\Http\Controllers\Respite\RespiteDailyNoteController@create` — `app/Http/Controllers/Respite/RespiteDailyNoteController.php:42` — middleware `web, auth, permission:respite.daily-notes.view`
- `GET|HEAD respite/daily-notes/with-concerns` — `respite.daily-notes.with-concerns` — `App\Http\Controllers\Respite\RespiteDailyNoteController@withConcerns` — `app/Http/Controllers/Respite/RespiteDailyNoteController.php:212` — middleware `web, auth, permission:respite.daily-notes.view`
- `GET|HEAD respite/daily-notes/with-incidents` — `respite.daily-notes.with-incidents` — `App\Http\Controllers\Respite\RespiteDailyNoteController@withIncidents` — `app/Http/Controllers/Respite/RespiteDailyNoteController.php:225` — middleware `web, auth, permission:respite.daily-notes.view`
- `GET|HEAD respite/stays/{stay}/daily-notes` — `respite.stays.daily-notes` — `App\Http\Controllers\Respite\RespiteDailyNoteController@forStay` — `app/Http/Controllers/Respite/RespiteDailyNoteController.php:188` — middleware `web, auth, permission:respite.daily-notes.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Respite/RespiteDailyNoteController.php`.
- Exact render/action page relationships: `resources/js/pages/respite/daily-notes/create.tsx`, `resources/js/pages/respite/daily-notes/for-stay.tsx`, `resources/js/pages/respite/daily-notes/index.tsx`, `resources/js/pages/respite/daily-notes/show.tsx`, `resources/js/pages/respite/daily-notes/with-concerns.tsx`, `resources/js/pages/respite/daily-notes/with-incidents.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
