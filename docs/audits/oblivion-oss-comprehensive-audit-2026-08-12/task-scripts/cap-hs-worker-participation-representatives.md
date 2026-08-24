# CAP-HS-WORKER-PARTICIPATION-REPRESENTATIVES: Health and safety representative administration

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.manage`
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

- Actor satisfying exact route middleware `auth`, `permission:hazards.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/worker-participation` (`health-safety.worker-participation.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST health-safety/worker-participation/representatives` (`health-safety.worker-participation.representatives.store`, action `storeRepresentative`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:251-263`; FormRequest `app/Http/Requests/HealthSafety/StoreRepresentativeRequest.php:18`; `user_id`, `site_id`, `work_group`, `election_method`, `elected_at`, `term_expires_at`, `training_days_completed`, `initial_training_completed_at`, `notes`.
3. Invoke only the owning control for `PUT health-safety/worker-participation/representatives/{representative}` (`health-safety.worker-participation.representatives.update`, action `updateRepresentative`). Source category: **updated/revised**; controller `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:265-282`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeRepresentative` / `ROUTE-1252` at `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:251`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateRepresentative` / `ROUTE-1253` at `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:265`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1252` / `storeRepresentative`: FormRequest `app/Http/Requests/HealthSafety/StoreRepresentativeRequest.php:18`; fields `user_id`, `site_id`, `work_group`, `election_method`, `elected_at`, `term_expires_at`, `training_days_completed`, `initial_training_completed_at`, `notes`; success app/Http/Controllers/HealthSafety/WorkerParticipationController.php:262 `return back()->with('success', 'H&S representative added successfully.');`.
- `ROUTE-1253` / `updateRepresentative`: success app/Http/Controllers/HealthSafety/WorkerParticipationController.php:281 `return back()->with('success', 'Representative updated successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/WorkerParticipationController.php:253 `$rep = HsRepresentative::create(array_merge($request->validated(), [`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:276 `$representative->update($validated);`; responses app/Http/Controllers/HealthSafety/WorkerParticipationController.php:262 `return back()->with('success', 'H&S representative added successfully.');`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:281 `return back()->with('success', 'Representative updated successfully.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST health-safety/worker-participation/representatives` — `health-safety.worker-participation.representatives.store` — `App\Http\Controllers\HealthSafety\WorkerParticipationController@storeRepresentative` — `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:251` — middleware `web, auth, permission:hazards.manage`
- `PUT health-safety/worker-participation/representatives/{representative}` — `health-safety.worker-participation.representatives.update` — `App\Http\Controllers\HealthSafety\WorkerParticipationController@updateRepresentative` — `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:265` — middleware `web, auth, permission:hazards.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/WorkerParticipationController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
