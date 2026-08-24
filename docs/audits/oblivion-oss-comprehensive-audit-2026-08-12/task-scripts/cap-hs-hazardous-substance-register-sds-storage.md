# CAP-HS-HAZARDOUS-SUBSTANCE-REGISTER-SDS-STORAGE: Hazardous substance register SDS and storage

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.view`, `permission:hazards.manage|hazards.create`, `permission:hazards.manage`
- Owning module: Health and safety
- Legacy family: `HS-HAZARDOUS-SUBSTANCE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/substances` (`health-safety.substances.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hazards.view`, `permission:hazards.manage|hazards.create`, `permission:hazards.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.view`, `permission:hazards.manage|hazards.create`, `permission:hazards.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/substances` (`health-safety.substances.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD health-safety/substances/{substance}` (`health-safety.substances.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:268-271`.
3. Use `GET|HEAD health-safety/substances/{substance}/sds/{sds}/download` (`health-safety.substances.sds.download`, action `downloadSds`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:359-369`.
4. Use `GET|HEAD health-safety/substances/create` (`health-safety.substances.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:202-207`.
5. Invoke only the owning control for `POST health-safety/substances` (`health-safety.substances.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:212-226`; `created_by`.
6. Invoke only the owning control for `PUT health-safety/substances/{substance}` (`health-safety.substances.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:276-287`; no exact validation fields extracted.
7. Invoke only the owning control for `POST health-safety/substances/{substance}/sds` (`health-safety.substances.sds.store`, action `storeSds`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:323-354`; `version`.
8. Invoke only the owning control for `POST health-safety/substances/{substance}/storage-locations` (`health-safety.substances.storage-locations.store`, action `storeStorageLocation`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:374-404`; `site_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1226` at `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:30`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1227` at `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:212`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1228` at `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:268`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1229` at `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:276`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeSds` / `ROUTE-1232` at `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:323`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadSds` / `ROUTE-1233` at `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:359`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeStorageLocation` / `ROUTE-1235` at `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:374`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1236` at `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:202`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/health-safety/substances/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1227` / `store`: fields `created_by`; success app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:224 `->with('success', 'Hazardous substance registered successfully.')`.
- `ROUTE-1229` / `update`: success app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:285 `->with('success', 'Substance updated successfully.')`.
- `ROUTE-1232` / `storeSds`: fields `version`; success app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:353 `return redirect()->back()->with('success', 'Safety Data Sheet uploaded successfully.');`.
- `ROUTE-1235` / `storeStorageLocation`: fields `site_id`; success app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:403 `return redirect()->back()->with('success', 'Storage location added successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:216 `$substance = HazardousSubstance::create(array_merge($validated, [`; app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:282 `$substance->update($validated);`; app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:337 `->update(['status' => 'superseded']);`; app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:341 `$substance->safetyDataSheets()->create([`; app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:389 `$substance->storageLocations()->create([`; responses app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:46 `return $query`; app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:160 `return Inertia::render('health-safety/substances/index', [`; app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:221 `// The add flow is modal-over-register: return to it with the new id so the`; app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:223 `return back()`; app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:270 `return redirect()->route('health-safety.substances.index', ['substance' => $substance->id]);`; app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:284 `return back()`; app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:353 `return redirect()->back()->with('success', 'Safety Data Sheet uploaded successfully.');`; app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:364 `return $this->streamPrivateAttachment(`; app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:403 `return redirect()->back()->with('success', 'Storage location added successfully.');`; app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:206 `return redirect()->route('health-safety.substances.index', ['new' => 1]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD health-safety/substances` — `health-safety.substances.index` — `App\Http\Controllers\HealthSafety\HazardousSubstanceController@index` — `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:30` — middleware `web, auth, permission:hazards.view`
- `POST health-safety/substances` — `health-safety.substances.store` — `App\Http\Controllers\HealthSafety\HazardousSubstanceController@store` — `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:212` — middleware `web, auth, permission:hazards.manage|hazards.create`
- `GET|HEAD health-safety/substances/{substance}` — `health-safety.substances.show` — `App\Http\Controllers\HealthSafety\HazardousSubstanceController@show` — `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:268` — middleware `web, auth, permission:hazards.view`
- `PUT health-safety/substances/{substance}` — `health-safety.substances.update` — `App\Http\Controllers\HealthSafety\HazardousSubstanceController@update` — `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:276` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/substances/{substance}/sds` — `health-safety.substances.sds.store` — `App\Http\Controllers\HealthSafety\HazardousSubstanceController@storeSds` — `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:323` — middleware `web, auth, permission:hazards.manage|hazards.create`
- `GET|HEAD health-safety/substances/{substance}/sds/{sds}/download` — `health-safety.substances.sds.download` — `App\Http\Controllers\HealthSafety\HazardousSubstanceController@downloadSds` — `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:359` — middleware `web, auth, permission:hazards.view`
- `POST health-safety/substances/{substance}/storage-locations` — `health-safety.substances.storage-locations.store` — `App\Http\Controllers\HealthSafety\HazardousSubstanceController@storeStorageLocation` — `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:374` — middleware `web, auth, permission:hazards.manage|hazards.create`
- `GET|HEAD health-safety/substances/create` — `health-safety.substances.create` — `App\Http\Controllers\HealthSafety\HazardousSubstanceController@create` — `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:202` — middleware `web, auth, permission:hazards.manage|hazards.create`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php`.
- Exact render/action page relationships: `resources/js/pages/health-safety/substances/index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
