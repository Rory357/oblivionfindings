# CAP-HS-HS-CORRECTIVE-ACTION-DELIVERY-CLOSURE: Corrective action seeding start completion and closure

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.manage`
- Owning module: Health and safety
- Legacy family: `HS-HS-CORRECTIVE-ACTION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hazards.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST health-safety/events/{event}/corrective-actions` (`health-safety.events.corrective-actions.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:25-52`; `title`.
3. Invoke only the owning control for `POST health-safety/events/{event}/corrective-actions/{action}/close` (`health-safety.events.corrective-actions.close`, action `close`). Source category: **completed/closed/released**; controller `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:129-140`; no exact validation fields extracted.
4. Invoke only the owning control for `POST health-safety/events/{event}/corrective-actions/{action}/complete` (`health-safety.events.corrective-actions.complete`, action `complete`). Source category: **completed/closed/released**; controller `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:87-103`; `completion_notes`.
5. Invoke only the owning control for `POST health-safety/events/{event}/corrective-actions/{action}/start` (`health-safety.events.corrective-actions.start`, action `start`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:71-84`; `assigned_to_user_id`.
6. Invoke only the owning control for `POST health-safety/events/{event}/investigations/{investigation}/seed-action` (`health-safety.events.investigations.seed-action`, action `seedFromRecommendation`). Source category: **mutation outcome source gap (seedFromRecommendation)**; controller `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:55-68`; `recommendation_index`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-1099` at `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:25`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `close` / `ROUTE-1100` at `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:129`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `complete` / `ROUTE-1101` at `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:87`; it is not runtime-observed.
- **created/recorded** is applicable only to `start` / `ROUTE-1103` at `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:71`; it is not runtime-observed.
- **mutation outcome source gap (seedFromRecommendation)** is applicable only to `seedFromRecommendation` / `ROUTE-1109` at `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:55`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1099` / `store`: fields `title`; success app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:51 `return back()->with('success', 'Corrective action added.');`.
- `ROUTE-1100` / `close`: success app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:139 `return back()->with('success', 'Corrective action closed.');`.
- `ROUTE-1101` / `complete`: fields `completion_notes`; success app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:102 `return back()->with('success', 'Corrective action completed.');`.
- `ROUTE-1103` / `start`: fields `assigned_to_user_id`; success app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:83 `return back()->with('success', 'Corrective action started.');`.
- `ROUTE-1109` / `seedFromRecommendation`: fields `recommendation_index`; success app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:67 `return back()->with('success', 'Corrective action created from recommendation.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:48 `return back()->with('error', $e->getMessage());`; app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:51 `return back()->with('success', 'Corrective action added.');`; app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:136 `return back()->with('error', $e->getMessage());`; app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:139 `return back()->with('success', 'Corrective action closed.');`; app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:99 `return back()->with('error', $e->getMessage());`; app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:102 `return back()->with('success', 'Corrective action completed.');`; app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:80 `return back()->with('error', $e->getMessage());`; app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:83 `return back()->with('success', 'Corrective action started.');`; app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:64 `return back()->with('error', $e->getMessage());`; app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:67 `return back()->with('success', 'Corrective action created from recommendation.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST health-safety/events/{event}/corrective-actions` — `health-safety.events.corrective-actions.store` — `App\Http\Controllers\HealthSafety\HsCorrectiveActionController@store` — `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:25` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/events/{event}/corrective-actions/{action}/close` — `health-safety.events.corrective-actions.close` — `App\Http\Controllers\HealthSafety\HsCorrectiveActionController@close` — `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:129` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/events/{event}/corrective-actions/{action}/complete` — `health-safety.events.corrective-actions.complete` — `App\Http\Controllers\HealthSafety\HsCorrectiveActionController@complete` — `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:87` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/events/{event}/corrective-actions/{action}/start` — `health-safety.events.corrective-actions.start` — `App\Http\Controllers\HealthSafety\HsCorrectiveActionController@start` — `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:71` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/events/{event}/investigations/{investigation}/seed-action` — `health-safety.events.investigations.seed-action` — `App\Http\Controllers\HealthSafety\HsCorrectiveActionController@seedFromRecommendation` — `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:55` — middleware `web, auth, permission:hazards.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
