# CAP-HS-FIRST-AID-FOLLOWUP: First-aid follow-up and completion

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.manage|hazards.create`
- Owning module: Health and safety
- Legacy family: `HS-FIRST-AID`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/first-aid` (`health-safety.first-aid.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hazards.manage|hazards.create`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.manage|hazards.create`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/first-aid` (`health-safety.first-aid.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST health-safety/first-aid/{record}/followups` (`health-safety.first-aid.followups.add`, action `addFollowup`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/FirstAidController.php:262-283`; `notes`.
3. Invoke only the owning control for `PATCH health-safety/first-aid/{record}/followups/{followup}/complete` (`health-safety.first-aid.followups.complete`, action `completeFollowup`). Source category: **completed/closed/released**; controller `app/Http/Controllers/HealthSafety/FirstAidController.php:285-295`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `addFollowup` / `ROUTE-1123` at `app/Http/Controllers/HealthSafety/FirstAidController.php:262`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `completeFollowup` / `ROUTE-1124` at `app/Http/Controllers/HealthSafety/FirstAidController.php:285`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1123` / `addFollowup`: fields `notes`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/FirstAidController.php:272 `$record->followups()->create([`; app/Http/Controllers/HealthSafety/FirstAidController.php:290 `$followup->update(['completed_at' => now()]);`; responses app/Http/Controllers/HealthSafety/FirstAidController.php:282 `return $this->inertiaOrJson($request, 'Follow-up added.');`; app/Http/Controllers/HealthSafety/FirstAidController.php:294 `return $this->inertiaOrJson($request, 'Follow-up completed.');`; audit calls app/Http/Controllers/HealthSafety/FirstAidController.php:280 `AuditLogger::log('firstaidrecord.followup.add', $record, ['notes' => \Illuminate\Support\Str::limit($data['notes'], 80)]);`; app/Http/Controllers/HealthSafety/FirstAidController.php:292 `AuditLogger::log('firstaidrecord.followup.complete', $record, ['followup_id' => $followup->id]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST health-safety/first-aid/{record}/followups` — `health-safety.first-aid.followups.add` — `App\Http\Controllers\HealthSafety\FirstAidController@addFollowup` — `app/Http/Controllers/HealthSafety/FirstAidController.php:262` — middleware `web, auth, permission:hazards.manage|hazards.create`
- `PATCH health-safety/first-aid/{record}/followups/{followup}/complete` — `health-safety.first-aid.followups.complete` — `App\Http\Controllers\HealthSafety\FirstAidController@completeFollowup` — `app/Http/Controllers/HealthSafety/FirstAidController.php:285` — middleware `web, auth, permission:hazards.manage|hazards.create`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/FirstAidController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
