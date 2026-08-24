# CAP-MED-EMAR-CLINICAL-MONITORING: INR syringe-driver and medication monitoring settings

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.orders.manage`
- Owning module: eMAR and medications
- Legacy family: `MED-EMAR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `emar/clients/{client}/inr` (`emar.clients.inr.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.orders.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.view`, `permission:medications.orders.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD emar/clients/{client}/inr` (`emar.clients.inr.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST emar/clients/{client}/inr` (`emar.clients.inr.store`, action `storeInr`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/EmarController.php:3304-3334`; `client_medication_id`.
3. Invoke only the owning control for `POST emar/clients/{client}/medication-settings` (`emar.clients.medication_settings`, action `updateMedicationSettings`). Source category: **updated/revised**; controller `app/Http/Controllers/Emar/EmarController.php:3274-3291`; `care_level`.
4. Invoke only the owning control for `POST emar/clients/{client}/syringe-drivers` (`emar.clients.syringe_drivers.store`, action `storeSyringeDriver`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/EmarController.php:3347-3390`; `site_id`.
5. Invoke only the owning control for `POST emar/inr/{inr}/disable` (`emar.inr.disable`, action `disableInr`). Source category: **mutation outcome source gap (disableInr)**; controller `app/Http/Controllers/Emar/EmarController.php:3336-3345`; no exact validation fields extracted.
6. Invoke only the owning control for `POST emar/syringe-drivers/{driver}/checks` (`emar.syringe_drivers.checks.store`, action `addSyringeDriverCheck`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/EmarController.php:3392-3409`; `checked_at`.
7. Invoke only the owning control for `POST emar/syringe-drivers/{driver}/complete` (`emar.syringe_drivers.complete`, action `completeSyringeDriver`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Emar/EmarController.php:3411-3435`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `inrHistory` / `ROUTE-0343` at `app/Http/Controllers/Emar/EmarController.php:3299`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeInr` / `ROUTE-0344` at `app/Http/Controllers/Emar/EmarController.php:3304`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateMedicationSettings` / `ROUTE-0345` at `app/Http/Controllers/Emar/EmarController.php:3274`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeSyringeDriver` / `ROUTE-0346` at `app/Http/Controllers/Emar/EmarController.php:3347`; it is not runtime-observed.
- **mutation outcome source gap (disableInr)** is applicable only to `disableInr` / `ROUTE-0382` at `app/Http/Controllers/Emar/EmarController.php:3336`; it is not runtime-observed.
- **created/recorded** is applicable only to `addSyringeDriverCheck` / `ROUTE-0443` at `app/Http/Controllers/Emar/EmarController.php:3392`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `completeSyringeDriver` / `ROUTE-0444` at `app/Http/Controllers/Emar/EmarController.php:3411`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0344` / `storeInr`: fields `client_medication_id`; success app/Http/Controllers/Emar/EmarController.php:3333 `return redirect()->back()->with('success', 'INR result recorded.');`.
- `ROUTE-0345` / `updateMedicationSettings`: fields `care_level`; success app/Http/Controllers/Emar/EmarController.php:3290 `return redirect()->back()->with('success', 'Medication chart settings updated.');`.
- `ROUTE-0346` / `storeSyringeDriver`: fields `site_id`; success app/Http/Controllers/Emar/EmarController.php:3389 `return redirect()->back()->with('success', "Syringe driver {$driver->id} commenced.");`.
- `ROUTE-0382` / `disableInr`: success app/Http/Controllers/Emar/EmarController.php:3344 `return redirect()->back()->with('success', 'INR result disabled.');`.
- `ROUTE-0443` / `addSyringeDriverCheck`: fields `checked_at`; success app/Http/Controllers/Emar/EmarController.php:3408 `return redirect()->back()->with('success', 'Syringe driver check recorded.');`.
- `ROUTE-0444` / `completeSyringeDriver`: success app/Http/Controllers/Emar/EmarController.php:3434 `return redirect()->back()->with('success', 'Syringe driver completed.');`; failure app/Http/Controllers/Emar/EmarController.php:3422 `throw ValidationException::withMessages([`.

## Failure and recovery paths

- `completeSyringeDriver`: app/Http/Controllers/Emar/EmarController.php:3422 `throw ValidationException::withMessages([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Emar/EmarController.php:3326 `$client->inrRecords()->create([`; app/Http/Controllers/Emar/EmarController.php:3286 `])->save();`; app/Http/Controllers/Emar/EmarController.php:3373 `$driver = $client->syringeDrivers()->create([`; app/Http/Controllers/Emar/EmarController.php:3402 `$driver->checks()->create([`; app/Http/Controllers/Emar/EmarController.php:3432 `])->save();`; responses app/Http/Controllers/Emar/EmarController.php:3301 `return redirect()->route('emar.mar', ['client_id' => $client->id]);`; app/Http/Controllers/Emar/EmarController.php:3333 `return redirect()->back()->with('success', 'INR result recorded.');`; app/Http/Controllers/Emar/EmarController.php:3290 `return redirect()->back()->with('success', 'Medication chart settings updated.');`; app/Http/Controllers/Emar/EmarController.php:3389 `return redirect()->back()->with('success', "Syringe driver {$driver->id} commenced.");`; app/Http/Controllers/Emar/EmarController.php:3344 `return redirect()->back()->with('success', 'INR result disabled.');`; app/Http/Controllers/Emar/EmarController.php:3408 `return redirect()->back()->with('success', 'Syringe driver check recorded.');`; app/Http/Controllers/Emar/EmarController.php:3434 `return redirect()->back()->with('success', 'Syringe driver completed.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD emar/clients/{client}/inr` — `emar.clients.inr.index` — `App\Http\Controllers\Emar\EmarController@inrHistory` — `app/Http/Controllers/Emar/EmarController.php:3299` — middleware `web, auth, permission:medications.view`
- `POST emar/clients/{client}/inr` — `emar.clients.inr.store` — `App\Http\Controllers\Emar\EmarController@storeInr` — `app/Http/Controllers/Emar/EmarController.php:3304` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/clients/{client}/medication-settings` — `emar.clients.medication_settings` — `App\Http\Controllers\Emar\EmarController@updateMedicationSettings` — `app/Http/Controllers/Emar/EmarController.php:3274` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/clients/{client}/syringe-drivers` — `emar.clients.syringe_drivers.store` — `App\Http\Controllers\Emar\EmarController@storeSyringeDriver` — `app/Http/Controllers/Emar/EmarController.php:3347` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/inr/{inr}/disable` — `emar.inr.disable` — `App\Http\Controllers\Emar\EmarController@disableInr` — `app/Http/Controllers/Emar/EmarController.php:3336` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/syringe-drivers/{driver}/checks` — `emar.syringe_drivers.checks.store` — `App\Http\Controllers\Emar\EmarController@addSyringeDriverCheck` — `app/Http/Controllers/Emar/EmarController.php:3392` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/syringe-drivers/{driver}/complete` — `emar.syringe_drivers.complete` — `App\Http\Controllers\Emar\EmarController@completeSyringeDriver` — `app/Http/Controllers/Emar/EmarController.php:3411` — middleware `web, auth, permission:medications.orders.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Emar/EmarController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
