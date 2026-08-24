# CAP-HS-WORKER-PARTICIPATION-COMMITTEES: Health and safety committees and meeting creation

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.view`, `permission:hazards.manage`
- Owning module: Health and safety
- Legacy family: `HS-WORKER-PARTICIPATION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/worker-participation` (`health-safety.worker-participation.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hazards.view`, `permission:hazards.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.view`, `permission:hazards.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/worker-participation` (`health-safety.worker-participation.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD health-safety/worker-participation/export` (`health-safety.worker-participation.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:695-743`.
3. Invoke only the owning control for `POST health-safety/worker-participation/committees` (`health-safety.worker-participation.committees.store`, action `storeCommittee`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:361-412`; `name`.
4. Invoke only the owning control for `POST health-safety/worker-participation/committees/{committee}/meetings` (`health-safety.worker-participation.committees.meetings.store`, action `storeMeeting`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:414-435`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1237` at `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:63`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeCommittee` / `ROUTE-1238` at `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:361`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeMeeting` / `ROUTE-1239` at `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:414`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-1245` at `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:695`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/health-safety/worker-participation/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1238` / `storeCommittee`: fields `name`; success app/Http/Controllers/HealthSafety/WorkerParticipationController.php:408 `return back()->with('success', 'Committee created and first meeting scheduled.');`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:411 `return back()->with('success', 'Committee created successfully.')->with('created_committee_id', $committee->id);`.
- `ROUTE-1239` / `storeMeeting`: FormRequest `StoreMeetingRequest` unresolved; success app/Http/Controllers/HealthSafety/WorkerParticipationController.php:434 `return back()->with('success', 'Meeting scheduled successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/WorkerParticipationController.php:384 `$committee = HsCommittee::create([`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:396 `$meeting = $committee->meetings()->create([`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:405 `$meeting->attendeeUsers()->sync($attendeeIds);`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:418 `$meeting = $committee->meetings()->create([`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:430 `$meeting->attendeeUsers()->sync($attendeeIds);`; responses app/Http/Controllers/HealthSafety/WorkerParticipationController.php:83 `return Inertia::render('health-safety/worker-participation/index', [`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:408 `return back()->with('success', 'Committee created and first meeting scheduled.');`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:411 `return back()->with('success', 'Committee created successfully.')->with('created_committee_id', $committee->id);`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:434 `return back()->with('success', 'Meeting scheduled successfully.');`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:708 `return response()->streamDownload(function () use ($reps, $meetings, $consultations) {`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD health-safety/worker-participation` — `health-safety.worker-participation.index` — `App\Http\Controllers\HealthSafety\WorkerParticipationController@index` — `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:63` — middleware `web, auth, permission:hazards.view`
- `POST health-safety/worker-participation/committees` — `health-safety.worker-participation.committees.store` — `App\Http\Controllers\HealthSafety\WorkerParticipationController@storeCommittee` — `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:361` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/worker-participation/committees/{committee}/meetings` — `health-safety.worker-participation.committees.meetings.store` — `App\Http\Controllers\HealthSafety\WorkerParticipationController@storeMeeting` — `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:414` — middleware `web, auth, permission:hazards.manage`
- `GET|HEAD health-safety/worker-participation/export` — `health-safety.worker-participation.export` — `App\Http\Controllers\HealthSafety\WorkerParticipationController@export` — `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:695` — middleware `web, auth, permission:hazards.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/WorkerParticipationController.php`.
- Exact render/action page relationships: `resources/js/pages/health-safety/worker-participation/index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
