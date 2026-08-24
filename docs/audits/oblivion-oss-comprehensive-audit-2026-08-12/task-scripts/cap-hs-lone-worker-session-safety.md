# CAP-HS-LONE-WORKER-SESSION-SAFETY: Lone-worker session check-in panic and emergency lifecycle

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.view`, `permission:hazards.manage`
- Owning module: Health and safety
- Legacy family: `HS-LONE-WORKER`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/lone-workers` (`health-safety.lone-workers.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hazards.view`, `permission:hazards.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.view`, `permission:hazards.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/lone-workers` (`health-safety.lone-workers.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST health-safety/lone-workers/sessions` (`health-safety.lone-workers.sessions.store`, action `startSession`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/LoneWorkerController.php:167-206`; `user_id`.
3. Invoke only the owning control for `DELETE health-safety/lone-workers/sessions/{session}` (`health-safety.lone-workers.sessions.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/HealthSafety/LoneWorkerController.php:314-325`; no exact validation fields extracted.
4. Invoke only the owning control for `PATCH health-safety/lone-workers/sessions/{session}` (`health-safety.lone-workers.sessions.update`, action `updateSession`). Source category: **updated/revised**; controller `app/Http/Controllers/HealthSafety/LoneWorkerController.php:212-234`; `expected_end_at`.
5. Invoke only the owning control for `POST health-safety/lone-workers/sessions/{session}/acknowledge-panic` (`health-safety.lone-workers.sessions.acknowledge-panic`, action `acknowledgePanic`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/HealthSafety/LoneWorkerController.php:411-433`; no exact validation fields extracted.
6. Invoke only the owning control for `POST health-safety/lone-workers/sessions/{session}/check-in` (`health-safety.lone-workers.sessions.check-in`, action `checkIn`). Source category: **mutation outcome source gap (checkIn)**; controller `app/Http/Controllers/HealthSafety/LoneWorkerController.php:246-291`; no exact validation fields extracted.
7. Invoke only the owning control for `POST health-safety/lone-workers/sessions/{session}/emergency` (`health-safety.lone-workers.sessions.emergency`, action `triggerEmergency`). Source category: **mutation outcome source gap (triggerEmergency)**; controller `app/Http/Controllers/HealthSafety/LoneWorkerController.php:330-354`; `emergency_notes`.
8. Invoke only the owning control for `POST health-safety/lone-workers/sessions/{session}/end` (`health-safety.lone-workers.sessions.end`, action `endSession`). Source category: **mutation outcome source gap (endSession)**; controller `app/Http/Controllers/HealthSafety/LoneWorkerController.php:296-305`; no exact validation fields extracted.
9. Invoke only the owning control for `POST health-safety/lone-workers/sessions/{session}/locate` (`health-safety.lone-workers.sessions.locate`, action `locateNow`). Source category: **mutation outcome source gap (locateNow)**; controller `app/Http/Controllers/HealthSafety/LoneWorkerController.php:394-405`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1141` at `app/Http/Controllers/HealthSafety/LoneWorkerController.php:49`; it is not runtime-observed.
- **created/recorded** is applicable only to `startSession` / `ROUTE-1145` at `app/Http/Controllers/HealthSafety/LoneWorkerController.php:167`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1146` at `app/Http/Controllers/HealthSafety/LoneWorkerController.php:314`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateSession` / `ROUTE-1147` at `app/Http/Controllers/HealthSafety/LoneWorkerController.php:212`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `acknowledgePanic` / `ROUTE-1148` at `app/Http/Controllers/HealthSafety/LoneWorkerController.php:411`; it is not runtime-observed.
- **mutation outcome source gap (checkIn)** is applicable only to `checkIn` / `ROUTE-1149` at `app/Http/Controllers/HealthSafety/LoneWorkerController.php:246`; it is not runtime-observed.
- **mutation outcome source gap (triggerEmergency)** is applicable only to `triggerEmergency` / `ROUTE-1150` at `app/Http/Controllers/HealthSafety/LoneWorkerController.php:330`; it is not runtime-observed.
- **mutation outcome source gap (endSession)** is applicable only to `endSession` / `ROUTE-1151` at `app/Http/Controllers/HealthSafety/LoneWorkerController.php:296`; it is not runtime-observed.
- **mutation outcome source gap (locateNow)** is applicable only to `locateNow` / `ROUTE-1152` at `app/Http/Controllers/HealthSafety/LoneWorkerController.php:394`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/health-safety/lone-workers/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1145` / `startSession`: fields `user_id`; success app/Http/Controllers/HealthSafety/LoneWorkerController.php:201 `return back()->with('success', 'Lone worker session started successfully.');`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:205 `->with('success', 'Lone worker session started successfully.');`.
- `ROUTE-1146` / `destroy`: success app/Http/Controllers/HealthSafety/LoneWorkerController.php:324 `->with('success', 'Session removed from the register (retained for audit).');`.
- `ROUTE-1147` / `updateSession`: fields `expected_end_at`; success app/Http/Controllers/HealthSafety/LoneWorkerController.php:233 `return back()->with('success', 'Session updated.');`.
- `ROUTE-1148` / `acknowledgePanic`: success app/Http/Controllers/HealthSafety/LoneWorkerController.php:432 `return back()->with('success', 'Panic acknowledged.');`.
- `ROUTE-1149` / `checkIn`: success app/Http/Controllers/HealthSafety/LoneWorkerController.php:290 `return redirect()->back()->with('success', 'Check-in recorded successfully.');`.
- `ROUTE-1150` / `triggerEmergency`: fields `emergency_notes`; success app/Http/Controllers/HealthSafety/LoneWorkerController.php:353 `return redirect()->back()->with('success', 'Emergency alert sent to Control Room.');`.
- `ROUTE-1151` / `endSession`: success app/Http/Controllers/HealthSafety/LoneWorkerController.php:304 `return redirect()->back()->with('success', 'Lone worker session ended successfully.');`.
- `ROUTE-1152` / `locateNow`: success app/Http/Controllers/HealthSafety/LoneWorkerController.php:404 `return back()->with('success', 'Locate now queued — the tracker will report on its next connection.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/LoneWorkerController.php:191 `LoneWorkerSession::create(array_merge($validated, [`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:320 `$session->update(['updated_by' => $request->user()->id]);`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:321 `$session->delete();`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:227 `$session->update(array_merge($validated, [`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:420 `$device->forceFill(['meta' => $meta])->save();`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:426 `->update([`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:260 `$session->checkIns()->create(array_merge($validated, [`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:265 `$session->update([`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:273 `$session->update([`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:280 `$session->alerts()->create([`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:336 `$session->update([`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:344 `$session->alerts()->create([`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:298 `$session->update([`; responses app/Http/Controllers/HealthSafety/LoneWorkerController.php:130 `return Inertia::render('health-safety/lone-workers/index', [`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:201 `return back()->with('success', 'Lone worker session started successfully.');`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:204 `return redirect()->route('health-safety.lone-workers.index')`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:317 `return back()->with('error', 'Only completed sessions can be removed. End the session first.');`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:323 `return redirect()->route('health-safety.lone-workers.index')`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:215 `return back()->with('error', 'Only active or overdue sessions can be edited.');`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:233 `return back()->with('success', 'Session updated.');`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:432 `return back()->with('success', 'Panic acknowledged.');`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:290 `return redirect()->back()->with('success', 'Check-in recorded successfully.');`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:353 `return redirect()->back()->with('success', 'Emergency alert sent to Control Room.');`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:304 `return redirect()->back()->with('success', 'Lone worker session ended successfully.');`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:399 `return back()->with('error', 'This worker does not have a paired GPS tracker.');`; app/Http/Controllers/HealthSafety/LoneWorkerController.php:404 `return back()->with('success', 'Locate now queued — the tracker will report on its next connection.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD health-safety/lone-workers` — `health-safety.lone-workers.index` — `App\Http\Controllers\HealthSafety\LoneWorkerController@index` — `app/Http/Controllers/HealthSafety/LoneWorkerController.php:49` — middleware `web, auth, permission:hazards.view`
- `POST health-safety/lone-workers/sessions` — `health-safety.lone-workers.sessions.store` — `App\Http\Controllers\HealthSafety\LoneWorkerController@startSession` — `app/Http/Controllers/HealthSafety/LoneWorkerController.php:167` — middleware `web, auth, permission:hazards.manage`
- `DELETE health-safety/lone-workers/sessions/{session}` — `health-safety.lone-workers.sessions.destroy` — `App\Http\Controllers\HealthSafety\LoneWorkerController@destroy` — `app/Http/Controllers/HealthSafety/LoneWorkerController.php:314` — middleware `web, auth, permission:hazards.manage`
- `PATCH health-safety/lone-workers/sessions/{session}` — `health-safety.lone-workers.sessions.update` — `App\Http\Controllers\HealthSafety\LoneWorkerController@updateSession` — `app/Http/Controllers/HealthSafety/LoneWorkerController.php:212` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/lone-workers/sessions/{session}/acknowledge-panic` — `health-safety.lone-workers.sessions.acknowledge-panic` — `App\Http\Controllers\HealthSafety\LoneWorkerController@acknowledgePanic` — `app/Http/Controllers/HealthSafety/LoneWorkerController.php:411` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/lone-workers/sessions/{session}/check-in` — `health-safety.lone-workers.sessions.check-in` — `App\Http\Controllers\HealthSafety\LoneWorkerController@checkIn` — `app/Http/Controllers/HealthSafety/LoneWorkerController.php:246` — middleware `web, auth`
- `POST health-safety/lone-workers/sessions/{session}/emergency` — `health-safety.lone-workers.sessions.emergency` — `App\Http\Controllers\HealthSafety\LoneWorkerController@triggerEmergency` — `app/Http/Controllers/HealthSafety/LoneWorkerController.php:330` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/lone-workers/sessions/{session}/end` — `health-safety.lone-workers.sessions.end` — `App\Http\Controllers\HealthSafety\LoneWorkerController@endSession` — `app/Http/Controllers/HealthSafety/LoneWorkerController.php:296` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/lone-workers/sessions/{session}/locate` — `health-safety.lone-workers.sessions.locate` — `App\Http\Controllers\HealthSafety\LoneWorkerController@locateNow` — `app/Http/Controllers/HealthSafety/LoneWorkerController.php:394` — middleware `web, auth, permission:hazards.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/LoneWorkerController.php`.
- Exact render/action page relationships: `resources/js/pages/health-safety/lone-workers/index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
