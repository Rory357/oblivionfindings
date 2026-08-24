# MED-MEDICATION-ADMINISTRATION-CORRECTION: Medication Administration Correction

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.administer.correct|clients.update`, `permission:medications.administer.correct`
- Owning module: eMAR and medications
- Legacy family: `MED-MEDICATION-ADMINISTRATION-CORRECTION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.administer.correct|clients.update`, `permission:medications.administer.correct`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.administer.correct|clients.update`, `permission:medications.administer.correct`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST clients/{client}/mar/administrations/{administration}/corrections` (`clients.mar.administrations.corrections.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/MedicationAdministrationCorrectionController.php:63-121`; no exact validation fields extracted.
3. Invoke only the owning control for `POST emar/corrections/{correction}/approve` (`emar.corrections.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/MedicationAdministrationCorrectionController.php:14-38`; no exact validation fields extracted.
4. Invoke only the owning control for `POST emar/corrections/{correction}/reject` (`emar.corrections.reject`, action `reject`). Source category: **rejected/returned**; controller `app/Http/Controllers/MedicationAdministrationCorrectionController.php:40-61`; `reason`.
5. Invoke only the owning control for `POST medications/corrections/{correction}/approve` (`medications.corrections.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/MedicationAdministrationCorrectionController.php:14-38`; no exact validation fields extracted.
6. Invoke only the owning control for `POST medications/corrections/{correction}/reject` (`medications.corrections.reject`, action `reject`). Source category: **rejected/returned**; controller `app/Http/Controllers/MedicationAdministrationCorrectionController.php:40-61`; `reason`.
7. Invoke only the owning control for `POST operations/clients/{client}/mar/administrations/{administration}/corrections` (`operations.clients.mar.administrations.corrections.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/MedicationAdministrationCorrectionController.php:63-121`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-0161` at `app/Http/Controllers/MedicationAdministrationCorrectionController.php:63`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-0359` at `app/Http/Controllers/MedicationAdministrationCorrectionController.php:14`; it is not runtime-observed.
- **rejected/returned** is applicable only to `reject` / `ROUTE-0360` at `app/Http/Controllers/MedicationAdministrationCorrectionController.php:40`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-1876` at `app/Http/Controllers/MedicationAdministrationCorrectionController.php:14`; it is not runtime-observed.
- **rejected/returned** is applicable only to `reject` / `ROUTE-1877` at `app/Http/Controllers/MedicationAdministrationCorrectionController.php:40`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2006` at `app/Http/Controllers/MedicationAdministrationCorrectionController.php:63`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0161` / `store`: success app/Http/Controllers/MedicationAdministrationCorrectionController.php:120 `return back()->with('success', 'Correction submitted for approval.');`.
- `ROUTE-0359` / `approve`: success app/Http/Controllers/MedicationAdministrationCorrectionController.php:37 `return back()->with('success', 'Correction approved.');`.
- `ROUTE-0360` / `reject`: fields `reason`; success app/Http/Controllers/MedicationAdministrationCorrectionController.php:60 `return back()->with('success', 'Correction rejected.');`.
- `ROUTE-1876` / `approve`: success app/Http/Controllers/MedicationAdministrationCorrectionController.php:37 `return back()->with('success', 'Correction approved.');`.
- `ROUTE-1877` / `reject`: fields `reason`; success app/Http/Controllers/MedicationAdministrationCorrectionController.php:60 `return back()->with('success', 'Correction rejected.');`.
- `ROUTE-2006` / `store`: success app/Http/Controllers/MedicationAdministrationCorrectionController.php:120 `return back()->with('success', 'Correction submitted for approval.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/MedicationAdministrationCorrectionController.php:105 `$corr->save();`; app/Http/Controllers/MedicationAdministrationCorrectionController.php:25 `$correction->update([`; app/Http/Controllers/MedicationAdministrationCorrectionController.php:47 `$correction->update([`; responses app/Http/Controllers/MedicationAdministrationCorrectionController.php:86 `return back()->withInput()->with('error', 'Please provide a correction reason (outside the 30-minute edit window).');`; app/Http/Controllers/MedicationAdministrationCorrectionController.php:120 `return back()->with('success', 'Correction submitted for approval.');`; app/Http/Controllers/MedicationAdministrationCorrectionController.php:22 `return back()->with('error', 'A correction must be approved by someone other than the person who raised it.');`; app/Http/Controllers/MedicationAdministrationCorrectionController.php:37 `return back()->with('success', 'Correction approved.');`; app/Http/Controllers/MedicationAdministrationCorrectionController.php:60 `return back()->with('success', 'Correction rejected.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST clients/{client}/mar/administrations/{administration}/corrections` — `clients.mar.administrations.corrections.store` — `App\Http\Controllers\MedicationAdministrationCorrectionController@store` — `app/Http/Controllers/MedicationAdministrationCorrectionController.php:63` — middleware `web, auth, permission:medications.administer.correct|clients.update`
- `POST emar/corrections/{correction}/approve` — `emar.corrections.approve` — `App\Http\Controllers\MedicationAdministrationCorrectionController@approve` — `app/Http/Controllers/MedicationAdministrationCorrectionController.php:14` — middleware `web, auth, permission:medications.administer.correct`
- `POST emar/corrections/{correction}/reject` — `emar.corrections.reject` — `App\Http\Controllers\MedicationAdministrationCorrectionController@reject` — `app/Http/Controllers/MedicationAdministrationCorrectionController.php:40` — middleware `web, auth, permission:medications.administer.correct`
- `POST medications/corrections/{correction}/approve` — `medications.corrections.approve` — `App\Http\Controllers\MedicationAdministrationCorrectionController@approve` — `app/Http/Controllers/MedicationAdministrationCorrectionController.php:14` — middleware `web, auth, permission:medications.administer.correct`
- `POST medications/corrections/{correction}/reject` — `medications.corrections.reject` — `App\Http\Controllers\MedicationAdministrationCorrectionController@reject` — `app/Http/Controllers/MedicationAdministrationCorrectionController.php:40` — middleware `web, auth, permission:medications.administer.correct`
- `POST operations/clients/{client}/mar/administrations/{administration}/corrections` — `operations.clients.mar.administrations.corrections.store` — `App\Http\Controllers\MedicationAdministrationCorrectionController@store` — `app/Http/Controllers/MedicationAdministrationCorrectionController.php:63` — middleware `web, auth, permission:medications.administer.correct|clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/MedicationAdministrationCorrectionController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
