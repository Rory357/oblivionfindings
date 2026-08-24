# OPS-RESPITE-HANDOVER-NOTE: Respite Handover Note

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:respite.handovers.view`, `permission:respite.handovers.manage`
- Owning module: Operations and rostering
- Legacy family: `OPS-RESPITE-HANDOVER-NOTE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `respite/handover-notes` (`respite.handover-notes.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:respite.handovers.view`, `permission:respite.handovers.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:respite.handovers.view`, `permission:respite.handovers.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD respite/handover-notes` (`respite.handover-notes.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD respite/handover-notes/{handoverNote}` (`respite.handover-notes.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:90-107`.
3. Use `GET|HEAD respite/handover-notes/create` (`respite.handover-notes.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:33-53`.
4. Use `GET|HEAD respite/handover-notes/unacknowledged` (`respite.handover-notes.unacknowledged`, action `unacknowledged`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:185-196`.
5. Use `GET|HEAD respite/stays/{stay}/handover-notes` (`respite.stays.handover-notes`, action `forStay`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:171-183`.
6. Invoke only the owning control for `POST respite/handover-notes` (`respite.handover-notes.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:55-88`; `stay_id`, `handover_type`, `notes`, `sensitive_flag`.
7. Invoke only the owning control for `PUT respite/handover-notes/{handoverNote}` (`respite.handover-notes.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:109-138`; `handover_type`, `notes`, `sensitive_flag`.
8. Invoke only the owning control for `POST respite/handover-notes/{handoverNote}/acknowledge` (`respite.handover-notes.acknowledge`, action `acknowledge`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:140-169`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2394` at `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:17`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2395` at `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:55`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2396` at `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:90`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2397` at `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:109`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `acknowledge` / `ROUTE-2398` at `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:140`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2399` at `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:33`; it is not runtime-observed.
- **information presented** is applicable only to `unacknowledged` / `ROUTE-2400` at `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:185`; it is not runtime-observed.
- **information presented** is applicable only to `forStay` / `ROUTE-2453` at `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:171`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/respite/handover-notes/create.tsx`, `resources/js/pages/respite/handover-notes/for-stay.tsx`, `resources/js/pages/respite/handover-notes/index.tsx`, `resources/js/pages/respite/handover-notes/show.tsx`, `resources/js/pages/respite/handover-notes/unacknowledged.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2395` / `store`: fields `stay_id`, `handover_type`, `notes`, `sensitive_flag`; success app/Http/Controllers/Respite/RespiteHandoverNoteController.php:87 `->with('success', 'Handover note created.');`.
- `ROUTE-2397` / `update`: fields `handover_type`, `notes`, `sensitive_flag`; success app/Http/Controllers/Respite/RespiteHandoverNoteController.php:137 `return back()->with('success', 'Handover note updated.');`.
- `ROUTE-2398` / `acknowledge`: success app/Http/Controllers/Respite/RespiteHandoverNoteController.php:168 `return back()->with('success', 'Handover acknowledged.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Respite/RespiteHandoverNoteController.php:66 `$note = RespiteHandoverNote::create($validated);`; app/Http/Controllers/Respite/RespiteHandoverNoteController.php:120 `$handoverNote->update($validated);`; app/Http/Controllers/Respite/RespiteHandoverNoteController.php:146 `$handoverNote->update([`; responses app/Http/Controllers/Respite/RespiteHandoverNoteController.php:27 `return Inertia::render('respite/handover-notes/index', [`; app/Http/Controllers/Respite/RespiteHandoverNoteController.php:85 `return redirect()`; app/Http/Controllers/Respite/RespiteHandoverNoteController.php:104 `return Inertia::render('respite/handover-notes/show', [`; app/Http/Controllers/Respite/RespiteHandoverNoteController.php:137 `return back()->with('success', 'Handover note updated.');`; app/Http/Controllers/Respite/RespiteHandoverNoteController.php:143 `return back()->with('error', 'Already acknowledged.');`; app/Http/Controllers/Respite/RespiteHandoverNoteController.php:168 `return back()->with('success', 'Handover acknowledged.');`; app/Http/Controllers/Respite/RespiteHandoverNoteController.php:41 `return Inertia::render('respite/handover-notes/create', [`; app/Http/Controllers/Respite/RespiteHandoverNoteController.php:193 `return Inertia::render('respite/handover-notes/unacknowledged', [`; app/Http/Controllers/Respite/RespiteHandoverNoteController.php:179 `return Inertia::render('respite/handover-notes/for-stay', [`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Respite/RespiteHandoverNoteController.php:78 `event(new RespiteEvent('respite.handover.created', [`; app/Http/Controllers/Respite/RespiteHandoverNoteController.php:132 `event(new RespiteEvent('respite.handover.updated', [`; app/Http/Controllers/Respite/RespiteHandoverNoteController.php:162 `event(new RespiteEvent('respite.handover.acknowledged', [`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD respite/handover-notes` — `respite.handover-notes.index` — `App\Http\Controllers\Respite\RespiteHandoverNoteController@index` — `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:17` — middleware `web, auth, permission:respite.handovers.view`
- `POST respite/handover-notes` — `respite.handover-notes.store` — `App\Http\Controllers\Respite\RespiteHandoverNoteController@store` — `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:55` — middleware `web, auth, permission:respite.handovers.manage`
- `GET|HEAD respite/handover-notes/{handoverNote}` — `respite.handover-notes.show` — `App\Http\Controllers\Respite\RespiteHandoverNoteController@show` — `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:90` — middleware `web, auth, permission:respite.handovers.view`
- `PUT respite/handover-notes/{handoverNote}` — `respite.handover-notes.update` — `App\Http\Controllers\Respite\RespiteHandoverNoteController@update` — `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:109` — middleware `web, auth, permission:respite.handovers.manage`
- `POST respite/handover-notes/{handoverNote}/acknowledge` — `respite.handover-notes.acknowledge` — `App\Http\Controllers\Respite\RespiteHandoverNoteController@acknowledge` — `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:140` — middleware `web, auth, permission:respite.handovers.manage`
- `GET|HEAD respite/handover-notes/create` — `respite.handover-notes.create` — `App\Http\Controllers\Respite\RespiteHandoverNoteController@create` — `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:33` — middleware `web, auth, permission:respite.handovers.view`
- `GET|HEAD respite/handover-notes/unacknowledged` — `respite.handover-notes.unacknowledged` — `App\Http\Controllers\Respite\RespiteHandoverNoteController@unacknowledged` — `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:185` — middleware `web, auth, permission:respite.handovers.view`
- `GET|HEAD respite/stays/{stay}/handover-notes` — `respite.stays.handover-notes` — `App\Http\Controllers\Respite\RespiteHandoverNoteController@forStay` — `app/Http/Controllers/Respite/RespiteHandoverNoteController.php:171` — middleware `web, auth, permission:respite.handovers.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Respite/RespiteHandoverNoteController.php`.
- Exact render/action page relationships: `resources/js/pages/respite/handover-notes/create.tsx`, `resources/js/pages/respite/handover-notes/for-stay.tsx`, `resources/js/pages/respite/handover-notes/index.tsx`, `resources/js/pages/respite/handover-notes/show.tsx`, `resources/js/pages/respite/handover-notes/unacknowledged.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
