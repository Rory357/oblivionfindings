# FLEET-HANDOVER: Handover

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.manage`
- Owning module: Fleet and vehicles
- Legacy family: `FLEET-HANDOVER`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `fleet-assets/handovers` (`fleet-assets.handovers.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD fleet-assets/handovers` (`fleet-assets.handovers.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD fleet-assets/handovers/{handover}` (`fleet-assets.handovers.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/HandoverController.php:245-292`.
3. Use `GET|HEAD fleet-assets/handovers/create` (`fleet-assets.handovers.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/HandoverController.php:146-152`.
4. Invoke only the owning control for `POST fleet-assets/handovers` (`fleet-assets.handovers.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/FleetAssets/HandoverController.php:187-243`; `asset_id`.
5. Invoke only the owning control for `POST fleet-assets/handovers/{handover}/accept` (`fleet-assets.handovers.accept`, action `accept`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/FleetAssets/HandoverController.php:294-322`; no exact validation fields extracted.
6. Invoke only the owning control for `POST fleet-assets/handovers/{handover}/dispute` (`fleet-assets.handovers.dispute`, action `dispute`). Source category: **mutation outcome source gap (dispute)**; controller `app/Http/Controllers/FleetAssets/HandoverController.php:324-357`; `dispute_reason`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0743` at `app/Http/Controllers/FleetAssets/HandoverController.php:19`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0744` at `app/Http/Controllers/FleetAssets/HandoverController.php:187`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0745` at `app/Http/Controllers/FleetAssets/HandoverController.php:245`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `accept` / `ROUTE-0746` at `app/Http/Controllers/FleetAssets/HandoverController.php:294`; it is not runtime-observed.
- **mutation outcome source gap (dispute)** is applicable only to `dispute` / `ROUTE-0747` at `app/Http/Controllers/FleetAssets/HandoverController.php:324`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0748` at `app/Http/Controllers/FleetAssets/HandoverController.php:146`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/fleet-assets/handovers/index.tsx`, `resources/js/pages/fleet-assets/handovers/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0744` / `store`: fields `asset_id`; success app/Http/Controllers/FleetAssets/HandoverController.php:242 `->with('success', 'Shift handover created successfully.');`.
- `ROUTE-0746` / `accept`: success app/Http/Controllers/FleetAssets/HandoverController.php:321 `return back()->with('success', 'Handover accepted.');`.
- `ROUTE-0747` / `dispute`: fields `dispute_reason`; success app/Http/Controllers/FleetAssets/HandoverController.php:356 `return back()->with('success', 'Handover disputed. Management has been notified.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/FleetAssets/HandoverController.php:216 `$handover = FleetShiftHandover::create([`; app/Http/Controllers/FleetAssets/HandoverController.php:312 `$handover->update([`; app/Http/Controllers/FleetAssets/HandoverController.php:346 `$handover->update([`; responses app/Http/Controllers/FleetAssets/HandoverController.php:33 `return Inertia::render('fleet-assets/handovers/index', [`; app/Http/Controllers/FleetAssets/HandoverController.php:128 `return Inertia::render('fleet-assets/handovers/index', [`; app/Http/Controllers/FleetAssets/HandoverController.php:240 `return redirect()`; app/Http/Controllers/FleetAssets/HandoverController.php:259 `return Inertia::render('fleet-assets/handovers/show', [`; app/Http/Controllers/FleetAssets/HandoverController.php:303 `return back()->with('error', 'This handover has already been processed.');`; app/Http/Controllers/FleetAssets/HandoverController.php:321 `return back()->with('success', 'Handover accepted.');`; app/Http/Controllers/FleetAssets/HandoverController.php:333 `return back()->with('error', 'This handover has already been processed.');`; app/Http/Controllers/FleetAssets/HandoverController.php:356 `return back()->with('success', 'Handover disputed. Management has been notified.');`; app/Http/Controllers/FleetAssets/HandoverController.php:148 `return redirect()->route(`; audit calls app/Http/Controllers/FleetAssets/HandoverController.php:234 `AuditLogger::log('fleet.handover.create', $handover, [`; app/Http/Controllers/FleetAssets/HandoverController.php:317 `AuditLogger::log('fleet.handover.accept', $handover, [`; app/Http/Controllers/FleetAssets/HandoverController.php:351 `AuditLogger::log('fleet.handover.dispute', $handover, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD fleet-assets/handovers` — `fleet-assets.handovers.index` — `App\Http\Controllers\FleetAssets\HandoverController@index` — `app/Http/Controllers/FleetAssets/HandoverController.php:19` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `POST fleet-assets/handovers` — `fleet-assets.handovers.store` — `App\Http\Controllers\FleetAssets\HandoverController@store` — `app/Http/Controllers/FleetAssets/HandoverController.php:187` — middleware `web, auth, permission:fleet.manage`
- `GET|HEAD fleet-assets/handovers/{handover}` — `fleet-assets.handovers.show` — `App\Http\Controllers\FleetAssets\HandoverController@show` — `app/Http/Controllers/FleetAssets/HandoverController.php:245` — middleware `web, auth`
- `POST fleet-assets/handovers/{handover}/accept` — `fleet-assets.handovers.accept` — `App\Http\Controllers\FleetAssets\HandoverController@accept` — `app/Http/Controllers/FleetAssets/HandoverController.php:294` — middleware `web, auth`
- `POST fleet-assets/handovers/{handover}/dispute` — `fleet-assets.handovers.dispute` — `App\Http\Controllers\FleetAssets\HandoverController@dispute` — `app/Http/Controllers/FleetAssets/HandoverController.php:324` — middleware `web, auth`
- `GET|HEAD fleet-assets/handovers/create` — `fleet-assets.handovers.create` — `App\Http\Controllers\FleetAssets\HandoverController@create` — `app/Http/Controllers/FleetAssets/HandoverController.php:146` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/FleetAssets/HandoverController.php`.
- Exact render/action page relationships: `resources/js/pages/fleet-assets/handovers/index.tsx`, `resources/js/pages/fleet-assets/handovers/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
