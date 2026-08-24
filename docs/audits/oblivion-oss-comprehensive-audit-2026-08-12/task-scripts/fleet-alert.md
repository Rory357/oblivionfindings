# FLEET-ALERT: Alert

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:assets.viewAny|assets.alerts.view`, `permission:controlRoom.alerts.manage`
- Owning module: Fleet and vehicles
- Legacy family: `FLEET-ALERT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `fleet-assets/alerts` (`fleet-assets.alerts.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:assets.viewAny|assets.alerts.view`, `permission:controlRoom.alerts.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:assets.viewAny|assets.alerts.view`, `permission:controlRoom.alerts.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD fleet-assets/alerts` (`fleet-assets.alerts.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST fleet-assets/alerts/{alert}/acknowledge` (`fleet-assets.alerts.acknowledge`, action `acknowledge`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/FleetAssets/AlertController.php:19-24`; no exact validation fields extracted.
3. Invoke only the owning control for `POST fleet-assets/alerts/{alert}/resolve` (`fleet-assets.alerts.resolve`, action `resolve`). Source category: **completed/closed/released**; controller `app/Http/Controllers/FleetAssets/AlertController.php:29-34`; no exact validation fields extracted.
4. Invoke only the owning control for `POST fleet-assets/alerts/bulk-action` (`fleet-assets.alerts.bulk-action`, action `bulkAction`). Source category: **mutation outcome source gap (bulkAction)**; controller `app/Http/Controllers/FleetAssets/AlertController.php:142-234`; `action`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0702` at `app/Http/Controllers/FleetAssets/AlertController.php:36`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `acknowledge` / `ROUTE-0703` at `app/Http/Controllers/FleetAssets/AlertController.php:19`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `resolve` / `ROUTE-0704` at `app/Http/Controllers/FleetAssets/AlertController.php:29`; it is not runtime-observed.
- **mutation outcome source gap (bulkAction)** is applicable only to `bulkAction` / `ROUTE-0705` at `app/Http/Controllers/FleetAssets/AlertController.php:142`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/fleet-assets/alerts/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0705` / `bulkAction`: fields `action`; success app/Http/Controllers/FleetAssets/AlertController.php:233 `return back()->with('success', $message);`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/FleetAssets/AlertController.php:183 `$alert->update([`; app/Http/Controllers/FleetAssets/AlertController.php:209 `$alert->update([`; responses app/Http/Controllers/FleetAssets/AlertController.php:110 `return Inertia::render('fleet-assets/alerts/index', [`; app/Http/Controllers/FleetAssets/AlertController.php:23 `return $canonical->acknowledge($request, $alert);`; app/Http/Controllers/FleetAssets/AlertController.php:33 `return $canonical->resolve($request, $alert);`; app/Http/Controllers/FleetAssets/AlertController.php:233 `return back()->with('success', $message);`; audit calls app/Http/Controllers/FleetAssets/AlertController.php:191 `AuditLogger::log('controlRoom.alert.acknowledge', $alert, [`; app/Http/Controllers/FleetAssets/AlertController.php:218 `AuditLogger::log('controlRoom.alert.resolve', $alert, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD fleet-assets/alerts` — `fleet-assets.alerts.index` — `App\Http\Controllers\FleetAssets\AlertController@index` — `app/Http/Controllers/FleetAssets/AlertController.php:36` — middleware `web, auth, permission:assets.viewAny|assets.alerts.view`
- `POST fleet-assets/alerts/{alert}/acknowledge` — `fleet-assets.alerts.acknowledge` — `App\Http\Controllers\FleetAssets\AlertController@acknowledge` — `app/Http/Controllers/FleetAssets/AlertController.php:19` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST fleet-assets/alerts/{alert}/resolve` — `fleet-assets.alerts.resolve` — `App\Http\Controllers\FleetAssets\AlertController@resolve` — `app/Http/Controllers/FleetAssets/AlertController.php:29` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST fleet-assets/alerts/bulk-action` — `fleet-assets.alerts.bulk-action` — `App\Http\Controllers\FleetAssets\AlertController@bulkAction` — `app/Http/Controllers/FleetAssets/AlertController.php:142` — middleware `web, auth, permission:controlRoom.alerts.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/FleetAssets/AlertController.php`.
- Exact render/action page relationships: `resources/js/pages/fleet-assets/alerts/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
