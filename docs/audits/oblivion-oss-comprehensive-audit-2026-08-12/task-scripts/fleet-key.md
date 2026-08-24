# FLEET-KEY: Key

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.manage`
- Owning module: Fleet and vehicles
- Legacy family: `FLEET-KEY`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `fleet-assets/keys` (`fleet-assets.keys.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD fleet-assets/keys` (`fleet-assets.keys.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST fleet-assets/keys/checkout` (`fleet-assets.keys.checkout`, action `checkout`). Source category: **mutation outcome source gap (checkout)**; controller `app/Http/Controllers/FleetAssets/KeyController.php:123-148`; `asset_id`, `user_id`, `key_number`, `location`, `notes`.
3. Invoke only the owning control for `POST fleet-assets/keys/return` (`fleet-assets.keys.return`, action `returnKey`). Source category: **rejected/returned**; controller `app/Http/Controllers/FleetAssets/KeyController.php:150-173`; `asset_id`, `key_number`, `location`, `notes`.
4. Invoke only the owning control for `POST fleet-assets/keys/transfer` (`fleet-assets.keys.transfer`, action `transfer`). Source category: **mutation outcome source gap (transfer)**; controller `app/Http/Controllers/FleetAssets/KeyController.php:175-201`; `asset_id`, `transferred_to_user_id`, `key_number`, `location`, `notes`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0768` at `app/Http/Controllers/FleetAssets/KeyController.php:16`; it is not runtime-observed.
- **mutation outcome source gap (checkout)** is applicable only to `checkout` / `ROUTE-0769` at `app/Http/Controllers/FleetAssets/KeyController.php:123`; it is not runtime-observed.
- **rejected/returned** is applicable only to `returnKey` / `ROUTE-0770` at `app/Http/Controllers/FleetAssets/KeyController.php:150`; it is not runtime-observed.
- **mutation outcome source gap (transfer)** is applicable only to `transfer` / `ROUTE-0771` at `app/Http/Controllers/FleetAssets/KeyController.php:175`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/fleet-assets/keys/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0769` / `checkout`: fields `asset_id`, `user_id`, `key_number`, `location`, `notes`; success app/Http/Controllers/FleetAssets/KeyController.php:147 `return back()->with('success', 'Key checked out successfully.');`.
- `ROUTE-0770` / `returnKey`: fields `asset_id`, `key_number`, `location`, `notes`; success app/Http/Controllers/FleetAssets/KeyController.php:172 `return back()->with('success', 'Key returned successfully.');`.
- `ROUTE-0771` / `transfer`: fields `asset_id`, `transferred_to_user_id`, `key_number`, `location`, `notes`; success app/Http/Controllers/FleetAssets/KeyController.php:200 `return back()->with('success', 'Key transferred successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/FleetAssets/KeyController.php:133 `$log = FleetKeyLog::create([`; app/Http/Controllers/FleetAssets/KeyController.php:159 `$log = FleetKeyLog::create([`; app/Http/Controllers/FleetAssets/KeyController.php:185 `$log = FleetKeyLog::create([`; responses app/Http/Controllers/FleetAssets/KeyController.php:42 `return [`; app/Http/Controllers/FleetAssets/KeyController.php:111 `return Inertia::render('fleet-assets/keys/index', [`; app/Http/Controllers/FleetAssets/KeyController.php:147 `return back()->with('success', 'Key checked out successfully.');`; app/Http/Controllers/FleetAssets/KeyController.php:172 `return back()->with('success', 'Key returned successfully.');`; app/Http/Controllers/FleetAssets/KeyController.php:200 `return back()->with('success', 'Key transferred successfully.');`; audit calls app/Http/Controllers/FleetAssets/KeyController.php:142 `AuditLogger::log('fleet-assets.keys.checkout', $log, [`; app/Http/Controllers/FleetAssets/KeyController.php:168 `AuditLogger::log('fleet-assets.keys.return', $log, [`; app/Http/Controllers/FleetAssets/KeyController.php:195 `AuditLogger::log('fleet-assets.keys.transfer', $log, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD fleet-assets/keys` — `fleet-assets.keys.index` — `App\Http\Controllers\FleetAssets\KeyController@index` — `app/Http/Controllers/FleetAssets/KeyController.php:16` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `POST fleet-assets/keys/checkout` — `fleet-assets.keys.checkout` — `App\Http\Controllers\FleetAssets\KeyController@checkout` — `app/Http/Controllers/FleetAssets/KeyController.php:123` — middleware `web, auth, permission:fleet.manage`
- `POST fleet-assets/keys/return` — `fleet-assets.keys.return` — `App\Http\Controllers\FleetAssets\KeyController@returnKey` — `app/Http/Controllers/FleetAssets/KeyController.php:150` — middleware `web, auth, permission:fleet.manage`
- `POST fleet-assets/keys/transfer` — `fleet-assets.keys.transfer` — `App\Http\Controllers\FleetAssets\KeyController@transfer` — `app/Http/Controllers/FleetAssets/KeyController.php:175` — middleware `web, auth, permission:fleet.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/FleetAssets/KeyController.php`.
- Exact render/action page relationships: `resources/js/pages/fleet-assets/keys/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
