# FLEET-OUTING: Outing

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.outings.manage|fleet.manage`
- Owning module: Fleet and vehicles
- Legacy family: `FLEET-OUTING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `fleet-assets/outings` (`fleet-assets.outings.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.outings.manage|fleet.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.outings.manage|fleet.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD fleet-assets/outings` (`fleet-assets.outings.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD fleet-assets/outings/{outing}` (`fleet-assets.outings.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/OutingController.php:317-391`.
3. Use `GET|HEAD fleet-assets/outings/create` (`fleet-assets.outings.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/OutingController.php:166-230`.
4. Invoke only the owning control for `POST fleet-assets/outings` (`fleet-assets.outings.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/FleetAssets/OutingController.php:232-315`; `title`.
5. Invoke only the owning control for `POST fleet-assets/outings/{outing}/cancel` (`fleet-assets.outings.cancel`, action `cancel`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/FleetAssets/OutingController.php:463-481`; no exact validation fields extracted.
6. Invoke only the owning control for `POST fleet-assets/outings/{outing}/complete` (`fleet-assets.outings.complete`, action `complete`). Source category: **completed/closed/released**; controller `app/Http/Controllers/FleetAssets/OutingController.php:418-440`; no exact validation fields extracted.
7. Invoke only the owning control for `POST fleet-assets/outings/{outing}/residents/{resident}/return` (`fleet-assets.outings.resident-return`, action `markResidentReturned`). Source category: **mutation outcome source gap (markResidentReturned)**; controller `app/Http/Controllers/FleetAssets/OutingController.php:442-450`; no exact validation fields extracted.
8. Invoke only the owning control for `POST fleet-assets/outings/{outing}/residents/return-all` (`fleet-assets.outings.return-all`, action `returnAllResidents`). Source category: **rejected/returned**; controller `app/Http/Controllers/FleetAssets/OutingController.php:452-461`; no exact validation fields extracted.
9. Invoke only the owning control for `POST fleet-assets/outings/{outing}/start` (`fleet-assets.outings.start`, action `start`). Source category: **created/recorded**; controller `app/Http/Controllers/FleetAssets/OutingController.php:393-416`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0798` at `app/Http/Controllers/FleetAssets/OutingController.php:20`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0799` at `app/Http/Controllers/FleetAssets/OutingController.php:232`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0800` at `app/Http/Controllers/FleetAssets/OutingController.php:317`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `cancel` / `ROUTE-0801` at `app/Http/Controllers/FleetAssets/OutingController.php:463`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `complete` / `ROUTE-0802` at `app/Http/Controllers/FleetAssets/OutingController.php:418`; it is not runtime-observed.
- **mutation outcome source gap (markResidentReturned)** is applicable only to `markResidentReturned` / `ROUTE-0803` at `app/Http/Controllers/FleetAssets/OutingController.php:442`; it is not runtime-observed.
- **rejected/returned** is applicable only to `returnAllResidents` / `ROUTE-0804` at `app/Http/Controllers/FleetAssets/OutingController.php:452`; it is not runtime-observed.
- **created/recorded** is applicable only to `start` / `ROUTE-0805` at `app/Http/Controllers/FleetAssets/OutingController.php:393`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0806` at `app/Http/Controllers/FleetAssets/OutingController.php:166`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/fleet-assets/outings/create.tsx`, `resources/js/pages/fleet-assets/outings/index.tsx`, `resources/js/pages/fleet-assets/outings/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0799` / `store`: fields `title`; success app/Http/Controllers/FleetAssets/OutingController.php:314 `->with('success', 'Outing created successfully.');`; failure app/Http/Controllers/FleetAssets/OutingController.php:257 `return back()->withErrors([`.
- `ROUTE-0801` / `cancel`: success app/Http/Controllers/FleetAssets/OutingController.php:480 `return back()->with('success', 'Outing cancelled.');`.
- `ROUTE-0802` / `complete`: success app/Http/Controllers/FleetAssets/OutingController.php:439 `return back()->with('success', 'Outing completed.');`.
- `ROUTE-0803` / `markResidentReturned`: success app/Http/Controllers/FleetAssets/OutingController.php:449 `return back()->with('success', 'Resident marked as returned.');`.
- `ROUTE-0804` / `returnAllResidents`: success app/Http/Controllers/FleetAssets/OutingController.php:460 `return back()->with('success', 'All residents marked as returned.');`.
- `ROUTE-0805` / `start`: success app/Http/Controllers/FleetAssets/OutingController.php:415 `return back()->with('success', 'Outing started.');`.

## Failure and recovery paths

- `store`: app/Http/Controllers/FleetAssets/OutingController.php:257 `return back()->withErrors([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/FleetAssets/OutingController.php:264 `$outing = FleetOuting::create([`; app/Http/Controllers/FleetAssets/OutingController.php:281 `FleetOutingResident::create([`; app/Http/Controllers/FleetAssets/OutingController.php:289 `$booking = FleetVehicleBooking::create([`; app/Http/Controllers/FleetAssets/OutingController.php:301 `$outing->update(['booking_id' => $booking->id]);`; app/Http/Controllers/FleetAssets/OutingController.php:469 `$outing->update([`; app/Http/Controllers/FleetAssets/OutingController.php:475 `FleetVehicleBooking::where('id', $outing->booking_id)->update(['status' => 'cancelled']);`; app/Http/Controllers/FleetAssets/OutingController.php:432 `$outing->update([`; app/Http/Controllers/FleetAssets/OutingController.php:447 `$resident->update(['returned_at' => now()]);`; app/Http/Controllers/FleetAssets/OutingController.php:458 `->update(['returned_at' => now()]);`; app/Http/Controllers/FleetAssets/OutingController.php:408 `$outing->update([`; responses app/Http/Controllers/FleetAssets/OutingController.php:23 `return Inertia::render('fleet-assets/outings/index', [`; app/Http/Controllers/FleetAssets/OutingController.php:112 `return Inertia::render('fleet-assets/outings/index', [`; app/Http/Controllers/FleetAssets/OutingController.php:257 `return back()->withErrors([`; app/Http/Controllers/FleetAssets/OutingController.php:304 `return $outing;`; app/Http/Controllers/FleetAssets/OutingController.php:313 `return redirect()->route('fleet-assets.outings.show', $outing)`; app/Http/Controllers/FleetAssets/OutingController.php:341 `return Inertia::render('fleet-assets/outings/show', [`; app/Http/Controllers/FleetAssets/OutingController.php:466 `return back()->with('error', 'Outing cannot be cancelled.');`; app/Http/Controllers/FleetAssets/OutingController.php:480 `return back()->with('success', 'Outing cancelled.');`; app/Http/Controllers/FleetAssets/OutingController.php:421 `return back()->with('error', 'Outing can only be completed from active status.');`; app/Http/Controllers/FleetAssets/OutingController.php:429 `return back()->with('error', "Cannot complete outing: {$unreturnedResidents} resident(s) not yet marked as returned.");`; app/Http/Controllers/FleetAssets/OutingController.php:439 `return back()->with('success', 'Outing completed.');`; app/Http/Controllers/FleetAssets/OutingController.php:449 `return back()->with('success', 'Resident marked as returned.');`; app/Http/Controllers/FleetAssets/OutingController.php:460 `return back()->with('success', 'All residents marked as returned.');`; app/Http/Controllers/FleetAssets/OutingController.php:396 `return back()->with('error', 'Outing can only be started from planned status.');`; app/Http/Controllers/FleetAssets/OutingController.php:404 `return back()->with('error', 'All residents must have their pre-departure check completed before starting the outing.');`; app/Http/Controllers/FleetAssets/OutingController.php:415 `return back()->with('success', 'Outing started.');`; app/Http/Controllers/FleetAssets/OutingController.php:217 `return Inertia::render('fleet-assets/outings/create', [`; audit calls app/Http/Controllers/FleetAssets/OutingController.php:307 `AuditLogger::log('fleet.outing.create', $outing, [`; app/Http/Controllers/FleetAssets/OutingController.php:478 `AuditLogger::log('fleet.outing.cancel', $outing);`; app/Http/Controllers/FleetAssets/OutingController.php:437 `AuditLogger::log('fleet.outing.complete', $outing);`; app/Http/Controllers/FleetAssets/OutingController.php:413 `AuditLogger::log('fleet.outing.start', $outing);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD fleet-assets/outings` — `fleet-assets.outings.index` — `App\Http\Controllers\FleetAssets\OutingController@index` — `app/Http/Controllers/FleetAssets/OutingController.php:20` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `POST fleet-assets/outings` — `fleet-assets.outings.store` — `App\Http\Controllers\FleetAssets\OutingController@store` — `app/Http/Controllers/FleetAssets/OutingController.php:232` — middleware `web, auth, permission:fleet.outings.manage|fleet.manage`
- `GET|HEAD fleet-assets/outings/{outing}` — `fleet-assets.outings.show` — `App\Http\Controllers\FleetAssets\OutingController@show` — `app/Http/Controllers/FleetAssets/OutingController.php:317` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `POST fleet-assets/outings/{outing}/cancel` — `fleet-assets.outings.cancel` — `App\Http\Controllers\FleetAssets\OutingController@cancel` — `app/Http/Controllers/FleetAssets/OutingController.php:463` — middleware `web, auth, permission:fleet.outings.manage|fleet.manage`
- `POST fleet-assets/outings/{outing}/complete` — `fleet-assets.outings.complete` — `App\Http\Controllers\FleetAssets\OutingController@complete` — `app/Http/Controllers/FleetAssets/OutingController.php:418` — middleware `web, auth, permission:fleet.outings.manage|fleet.manage`
- `POST fleet-assets/outings/{outing}/residents/{resident}/return` — `fleet-assets.outings.resident-return` — `App\Http\Controllers\FleetAssets\OutingController@markResidentReturned` — `app/Http/Controllers/FleetAssets/OutingController.php:442` — middleware `web, auth, permission:fleet.outings.manage|fleet.manage`
- `POST fleet-assets/outings/{outing}/residents/return-all` — `fleet-assets.outings.return-all` — `App\Http\Controllers\FleetAssets\OutingController@returnAllResidents` — `app/Http/Controllers/FleetAssets/OutingController.php:452` — middleware `web, auth, permission:fleet.outings.manage|fleet.manage`
- `POST fleet-assets/outings/{outing}/start` — `fleet-assets.outings.start` — `App\Http\Controllers\FleetAssets\OutingController@start` — `app/Http/Controllers/FleetAssets/OutingController.php:393` — middleware `web, auth, permission:fleet.outings.manage|fleet.manage`
- `GET|HEAD fleet-assets/outings/create` — `fleet-assets.outings.create` — `App\Http\Controllers\FleetAssets\OutingController@create` — `app/Http/Controllers/FleetAssets/OutingController.php:166` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/FleetAssets/OutingController.php`.
- Exact render/action page relationships: `resources/js/pages/fleet-assets/outings/create.tsx`, `resources/js/pages/fleet-assets/outings/index.tsx`, `resources/js/pages/fleet-assets/outings/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
