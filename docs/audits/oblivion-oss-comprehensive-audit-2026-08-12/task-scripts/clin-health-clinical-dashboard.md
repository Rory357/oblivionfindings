# CLIN-HEALTH-CLINICAL-DASHBOARD: Health Clinical Dashboard

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clinical.dashboard`, `permission:clinical.assessments.viewAny`, `permission:clinical.assessments.record`, `permission:clinical.behaviour.viewAny`, `permission:clinical.events.viewAny`, `permission:clinical.events.record`, `permission:clinical.events.escalate`, `permission:clinical.events.review|clinical.events.record`, `permission:clinical.events.review`, `permission:clinical.monitoring.viewAny`, `permission:clinical.observations.viewAny`, `permission:clinical.observations.record`, `permission:clinical.observations.viewAny|clinical.observations.viewAssigned`
- Owning module: Health and clinical
- Legacy family: `CLIN-HEALTH-CLINICAL-DASHBOARD`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-clinical` (`health-clinical.dashboard`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:clinical.dashboard`, `permission:clinical.assessments.viewAny`, `permission:clinical.assessments.record`, `permission:clinical.behaviour.viewAny`, `permission:clinical.events.viewAny`, `permission:clinical.events.record`, `permission:clinical.events.escalate`, `permission:clinical.events.review|clinical.events.record`, `permission:clinical.events.review`, `permission:clinical.monitoring.viewAny`, `permission:clinical.observations.viewAny`, `permission:clinical.observations.record`, `permission:clinical.observations.viewAny|clinical.observations.viewAssigned`.
- Exact middleware atoms: `web`, `auth`, `permission:clinical.dashboard`, `permission:clinical.assessments.viewAny`, `permission:clinical.assessments.record`, `permission:clinical.behaviour.viewAny`, `permission:clinical.events.viewAny`, `permission:clinical.events.record`, `permission:clinical.events.escalate`, `permission:clinical.events.review|clinical.events.record`, `permission:clinical.events.review`, `permission:clinical.monitoring.viewAny`, `permission:clinical.observations.viewAny`, `permission:clinical.observations.record`, `permission:clinical.observations.viewAny|clinical.observations.viewAssigned`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-clinical` (`health-clinical.dashboard`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD health-clinical/assessments` (`health-clinical.assessments.index`, action `assessments`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:365-420`.
3. Use `GET|HEAD health-clinical/behaviour` (`health-clinical.behaviour.index`, action `behaviour`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:157-209`.
4. Use `GET|HEAD health-clinical/care-plans` (`health-clinical.care-plans.index`, action `carePlans`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:216-230`.
5. Use `GET|HEAD health-clinical/clients/{client}/clinical-card` (`health-clinical.clients.clinical-card`, action `clinicalCard`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:554-567`.
6. Use `GET|HEAD health-clinical/clients/search` (`health-clinical.clients.search`, action `clientSearch`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:506-548`.
7. Use `GET|HEAD health-clinical/events` (`health-clinical.events.index`, action `events`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:99-152`.
8. Use `GET|HEAD health-clinical/health-monitoring` (`health-clinical.health-monitoring.index`, action `healthMonitoring`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:235-251`.
9. Use `GET|HEAD health-clinical/observations` (`health-clinical.observations.index`, action `observations`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:60-94`.
10. Use `GET|HEAD health-clinical/trends` (`health-clinical.trends.index`, action `trends`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:257-317`.
11. Invoke only the owning control for `POST health-clinical/assessments` (`health-clinical.assessments.store`, action `storeAssessment`). Source category: **created/recorded**; controller `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:427-461`; `client_id`, `assessed_at`.
12. Invoke only the owning control for `POST health-clinical/events` (`health-clinical.events.store`, action `storeEvent`). Source category: **created/recorded**; controller `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:345-359`; `client_id`.
13. Invoke only the owning control for `POST health-clinical/events/{event}/escalate` (`health-clinical.events.escalate`, action `escalateEvent`). Source category: **escalated/flagged**; controller `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:597-603`; no exact validation fields extracted.
14. Invoke only the owning control for `PATCH health-clinical/events/{event}/follow-up/complete` (`health-clinical.events.followup.complete`, action `completeEventFollowup`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:586-592`; no exact validation fields extracted.
15. Invoke only the owning control for `PATCH health-clinical/events/{event}/review` (`health-clinical.events.review`, action `reviewEvent`). Source category: **mutation outcome source gap (reviewEvent)**; controller `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:575-581`; no exact validation fields extracted.
16. Invoke only the owning control for `POST health-clinical/observations` (`health-clinical.observations.store`, action `storeObservation`). Source category: **created/recorded**; controller `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:324-338`; `client_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1054` at `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:40`; it is not runtime-observed.
- **information presented** is applicable only to `assessments` / `ROUTE-1055` at `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:365`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeAssessment` / `ROUTE-1056` at `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:427`; it is not runtime-observed.
- **information presented** is applicable only to `behaviour` / `ROUTE-1057` at `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:157`; it is not runtime-observed.
- **information presented** is applicable only to `carePlans` / `ROUTE-1058` at `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:216`; it is not runtime-observed.
- **information presented** is applicable only to `clinicalCard` / `ROUTE-1059` at `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:554`; it is not runtime-observed.
- **information presented** is applicable only to `clientSearch` / `ROUTE-1062` at `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:506`; it is not runtime-observed.
- **information presented** is applicable only to `events` / `ROUTE-1063` at `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:99`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeEvent` / `ROUTE-1064` at `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:345`; it is not runtime-observed.
- **escalated/flagged** is applicable only to `escalateEvent` / `ROUTE-1065` at `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:597`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `completeEventFollowup` / `ROUTE-1066` at `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:586`; it is not runtime-observed.
- **mutation outcome source gap (reviewEvent)** is applicable only to `reviewEvent` / `ROUTE-1067` at `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:575`; it is not runtime-observed.
- **information presented** is applicable only to `healthMonitoring` / `ROUTE-1068` at `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:235`; it is not runtime-observed.
- **information presented** is applicable only to `observations` / `ROUTE-1069` at `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:60`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeObservation` / `ROUTE-1070` at `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:324`; it is not runtime-observed.
- **information presented** is applicable only to `trends` / `ROUTE-1077` at `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:257`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/health-clinical/Assessments.tsx`, `resources/js/pages/health-clinical/Behaviour.tsx`, `resources/js/pages/health-clinical/CarePlans.tsx`, `resources/js/pages/health-clinical/Events.tsx`, `resources/js/pages/health-clinical/HealthMonitoring.tsx`, `resources/js/pages/health-clinical/index.tsx`, `resources/js/pages/health-clinical/observations.tsx`, `resources/js/pages/health-clinical/Trends.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1055` / `assessments`: fields `client_id`.
- `ROUTE-1056` / `storeAssessment`: fields `client_id`, `assessed_at`; success app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:460 `return back()->with('success', $type->shortLabel().' assessment recorded successfully.');`.
- `ROUTE-1057` / `behaviour`: fields `client_id`.
- `ROUTE-1063` / `events`: fields `client_id`.
- `ROUTE-1064` / `storeEvent`: fields `client_id`; success app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:358 `return back()->with('success', 'Clinical event recorded successfully.');`.
- `ROUTE-1065` / `escalateEvent`: success app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:602 `return back()->with('success', 'Clinical event escalated to on-call leadership.');`.
- `ROUTE-1066` / `completeEventFollowup`: success app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:591 `return back()->with('success', 'Follow-up marked complete.');`.
- `ROUTE-1067` / `reviewEvent`: success app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:580 `return back()->with('success', 'Clinical event reviewed and signed off.');`.
- `ROUTE-1068` / `healthMonitoring`: fields `client_id`.
- `ROUTE-1069` / `observations`: fields `client_id`.
- `ROUTE-1070` / `storeObservation`: fields `client_id`; success app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:337 `return back()->with('success', $type->label().' recorded successfully.');`.
- `ROUTE-1077` / `trends`: fields `client_id`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:47 `return inertia('health-clinical/index', [`; app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:412 `return inertia('health-clinical/Assessments', [`; app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:460 `return back()->with('success', $type->shortLabel().' assessment recorded successfully.');`; app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:199 `return inertia('health-clinical/Behaviour', [`; app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:224 `return inertia('health-clinical/CarePlans', [`; app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:566 `return response()->json($this->dashboardService->getClinicalCard($client));`; app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:547 `return response()->json(['clients' => $clients]);`; app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:125 `return inertia('health-clinical/Events', [`; app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:358 `return back()->with('success', 'Clinical event recorded successfully.');`; app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:602 `return back()->with('success', 'Clinical event escalated to on-call leadership.');`; app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:591 `return back()->with('success', 'Follow-up marked complete.');`; app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:580 `return back()->with('success', 'Clinical event reviewed and signed off.');`; app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:244 `return inertia('health-clinical/HealthMonitoring', [`; app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:78 `return inertia('health-clinical/observations', [`; app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:337 `return back()->with('success', $type->label().' recorded successfully.');`; app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:304 `return inertia('health-clinical/Trends', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD health-clinical` — `health-clinical.dashboard` — `App\Http\Controllers\Clinical\HealthClinicalDashboardController@index` — `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:40` — middleware `web, auth, permission:clinical.dashboard`
- `GET|HEAD health-clinical/assessments` — `health-clinical.assessments.index` — `App\Http\Controllers\Clinical\HealthClinicalDashboardController@assessments` — `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:365` — middleware `web, auth, permission:clinical.assessments.viewAny`
- `POST health-clinical/assessments` — `health-clinical.assessments.store` — `App\Http\Controllers\Clinical\HealthClinicalDashboardController@storeAssessment` — `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:427` — middleware `web, auth, permission:clinical.assessments.record`
- `GET|HEAD health-clinical/behaviour` — `health-clinical.behaviour.index` — `App\Http\Controllers\Clinical\HealthClinicalDashboardController@behaviour` — `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:157` — middleware `web, auth, permission:clinical.behaviour.viewAny`
- `GET|HEAD health-clinical/care-plans` — `health-clinical.care-plans.index` — `App\Http\Controllers\Clinical\HealthClinicalDashboardController@carePlans` — `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:216` — middleware `web, auth, permission:clinical.dashboard`
- `GET|HEAD health-clinical/clients/{client}/clinical-card` — `health-clinical.clients.clinical-card` — `App\Http\Controllers\Clinical\HealthClinicalDashboardController@clinicalCard` — `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:554` — middleware `web, auth`
- `GET|HEAD health-clinical/clients/search` — `health-clinical.clients.search` — `App\Http\Controllers\Clinical\HealthClinicalDashboardController@clientSearch` — `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:506` — middleware `web, auth`
- `GET|HEAD health-clinical/events` — `health-clinical.events.index` — `App\Http\Controllers\Clinical\HealthClinicalDashboardController@events` — `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:99` — middleware `web, auth, permission:clinical.events.viewAny`
- `POST health-clinical/events` — `health-clinical.events.store` — `App\Http\Controllers\Clinical\HealthClinicalDashboardController@storeEvent` — `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:345` — middleware `web, auth, permission:clinical.events.record`
- `POST health-clinical/events/{event}/escalate` — `health-clinical.events.escalate` — `App\Http\Controllers\Clinical\HealthClinicalDashboardController@escalateEvent` — `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:597` — middleware `web, auth, permission:clinical.events.escalate`
- `PATCH health-clinical/events/{event}/follow-up/complete` — `health-clinical.events.followup.complete` — `App\Http\Controllers\Clinical\HealthClinicalDashboardController@completeEventFollowup` — `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:586` — middleware `web, auth, permission:clinical.events.review|clinical.events.record`
- `PATCH health-clinical/events/{event}/review` — `health-clinical.events.review` — `App\Http\Controllers\Clinical\HealthClinicalDashboardController@reviewEvent` — `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:575` — middleware `web, auth, permission:clinical.events.review`
- `GET|HEAD health-clinical/health-monitoring` — `health-clinical.health-monitoring.index` — `App\Http\Controllers\Clinical\HealthClinicalDashboardController@healthMonitoring` — `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:235` — middleware `web, auth, permission:clinical.monitoring.viewAny`
- `GET|HEAD health-clinical/observations` — `health-clinical.observations.index` — `App\Http\Controllers\Clinical\HealthClinicalDashboardController@observations` — `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:60` — middleware `web, auth, permission:clinical.observations.viewAny`
- `POST health-clinical/observations` — `health-clinical.observations.store` — `App\Http\Controllers\Clinical\HealthClinicalDashboardController@storeObservation` — `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:324` — middleware `web, auth, permission:clinical.observations.record`
- `GET|HEAD health-clinical/trends` — `health-clinical.trends.index` — `App\Http\Controllers\Clinical\HealthClinicalDashboardController@trends` — `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:257` — middleware `web, auth, permission:clinical.observations.viewAny|clinical.observations.viewAssigned`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php`.
- Exact render/action page relationships: `resources/js/pages/health-clinical/Assessments.tsx`, `resources/js/pages/health-clinical/Behaviour.tsx`, `resources/js/pages/health-clinical/CarePlans.tsx`, `resources/js/pages/health-clinical/Events.tsx`, `resources/js/pages/health-clinical/HealthMonitoring.tsx`, `resources/js/pages/health-clinical/index.tsx`, `resources/js/pages/health-clinical/observations.tsx`, `resources/js/pages/health-clinical/Trends.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
