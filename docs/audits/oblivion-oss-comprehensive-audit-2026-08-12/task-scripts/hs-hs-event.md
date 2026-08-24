# HS-HS-EVENT: Hs Event

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.view`, `permission:hazards.manage`
- Owning module: Health and safety
- Legacy family: `HS-HS-EVENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/corrective-actions` (`health-safety.corrective-actions.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hazards.view`, `permission:hazards.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.view`, `permission:hazards.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/corrective-actions` (`health-safety.corrective-actions.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD health-safety/events` (`health-safety.events.index`, action `index`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/HealthSafety/HsEventController.php:38-189`.
3. Use `GET|HEAD health-safety/events/{hsEvent}` (`health-safety.events.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/HealthSafety/HsEventController.php:250-255`.
4. Invoke only the owning control for `POST health-safety/events/{hsEvent}/close` (`health-safety.events.close`, action `close`). Source category: **completed/closed/released**; controller `app/Http/Controllers/HealthSafety/HsEventController.php:262-281`; `closure_summary`.
5. Invoke only the owning control for `POST health-safety/events/{hsEvent}/worksafe/acknowledge` (`health-safety.events.worksafe.acknowledge`, action `worksafeAcknowledge`). Source category: **mutation outcome source gap (worksafeAcknowledge)**; controller `app/Http/Controllers/HealthSafety/HsEventController.php:314-327`; `acknowledged_at`.
6. Invoke only the owning control for `POST health-safety/events/{hsEvent}/worksafe/notify` (`health-safety.events.worksafe.notify`, action `worksafeNotify`). Source category: **mutation outcome source gap (worksafeNotify)**; controller `app/Http/Controllers/HealthSafety/HsEventController.php:287-309`; `notified_at`.

## Source-applicable states and transitions

- **information presented** is applicable only to `correctiveActions` / `ROUTE-1082` at `app/Http/Controllers/HealthSafety/HsEventController.php:542`; it is not runtime-observed.
- **information presented** is applicable only to `index` / `ROUTE-1098` at `app/Http/Controllers/HealthSafety/HsEventController.php:38`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1111` at `app/Http/Controllers/HealthSafety/HsEventController.php:250`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `close` / `ROUTE-1112` at `app/Http/Controllers/HealthSafety/HsEventController.php:262`; it is not runtime-observed.
- **mutation outcome source gap (worksafeAcknowledge)** is applicable only to `worksafeAcknowledge` / `ROUTE-1113` at `app/Http/Controllers/HealthSafety/HsEventController.php:314`; it is not runtime-observed.
- **mutation outcome source gap (worksafeNotify)** is applicable only to `worksafeNotify` / `ROUTE-1114` at `app/Http/Controllers/HealthSafety/HsEventController.php:287`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/health-safety/corrective-actions/index.tsx`, `resources/js/pages/health-safety/events/index.tsx`, `resources/js/pages/health-safety/events/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1112` / `close`: fields `closure_summary`; success app/Http/Controllers/HealthSafety/HsEventController.php:280 `return back()->with('success', 'Event closed.');`.
- `ROUTE-1113` / `worksafeAcknowledge`: fields `acknowledged_at`; success app/Http/Controllers/HealthSafety/HsEventController.php:326 `return back()->with('success', 'WorkSafe acknowledgement recorded.');`.
- `ROUTE-1114` / `worksafeNotify`: fields `notified_at`; success app/Http/Controllers/HealthSafety/HsEventController.php:308 `return back()->with('success', 'WorkSafe notification recorded.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/HealthSafety/HsEventController.php:665 `return Inertia::render('health-safety/corrective-actions/index', [`; app/Http/Controllers/HealthSafety/HsEventController.php:169 `return Inertia::render('health-safety/events/index', [`; app/Http/Controllers/HealthSafety/HsEventController.php:252 `return Inertia::render('health-safety/events/show', [`; app/Http/Controllers/HealthSafety/HsEventController.php:277 `return back()->with('error', $e->getMessage());`; app/Http/Controllers/HealthSafety/HsEventController.php:280 `return back()->with('success', 'Event closed.');`; app/Http/Controllers/HealthSafety/HsEventController.php:323 `return back()->with('error', $e->getMessage());`; app/Http/Controllers/HealthSafety/HsEventController.php:326 `return back()->with('success', 'WorkSafe acknowledgement recorded.');`; app/Http/Controllers/HealthSafety/HsEventController.php:305 `return back()->with('error', $e->getMessage());`; app/Http/Controllers/HealthSafety/HsEventController.php:308 `return back()->with('success', 'WorkSafe notification recorded.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD health-safety/corrective-actions` — `health-safety.corrective-actions.index` — `App\Http\Controllers\HealthSafety\HsEventController@correctiveActions` — `app/Http/Controllers/HealthSafety/HsEventController.php:542` — middleware `web, auth, permission:hazards.view`
- `GET|HEAD health-safety/events` — `health-safety.events.index` — `App\Http\Controllers\HealthSafety\HsEventController@index` — `app/Http/Controllers/HealthSafety/HsEventController.php:38` — middleware `web, auth, permission:hazards.view`
- `GET|HEAD health-safety/events/{hsEvent}` — `health-safety.events.show` — `App\Http\Controllers\HealthSafety\HsEventController@show` — `app/Http/Controllers/HealthSafety/HsEventController.php:250` — middleware `web, auth, permission:hazards.view`
- `POST health-safety/events/{hsEvent}/close` — `health-safety.events.close` — `App\Http\Controllers\HealthSafety\HsEventController@close` — `app/Http/Controllers/HealthSafety/HsEventController.php:262` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/events/{hsEvent}/worksafe/acknowledge` — `health-safety.events.worksafe.acknowledge` — `App\Http\Controllers\HealthSafety\HsEventController@worksafeAcknowledge` — `app/Http/Controllers/HealthSafety/HsEventController.php:314` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/events/{hsEvent}/worksafe/notify` — `health-safety.events.worksafe.notify` — `App\Http\Controllers\HealthSafety\HsEventController@worksafeNotify` — `app/Http/Controllers/HealthSafety/HsEventController.php:287` — middleware `web, auth, permission:hazards.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/HsEventController.php`.
- Exact render/action page relationships: `resources/js/pages/health-safety/corrective-actions/index.tsx`, `resources/js/pages/health-safety/events/index.tsx`, `resources/js/pages/health-safety/events/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
