# CAP-MED-CLIENT-MEDICAL-ADMINISTRATION-STOCK: Medication administration stock and discrepancy closure

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.controlled.record|clients.update`, `permission:medications.administer.record|clients.update|medications.orders.manage`, `permission:medications.stock.update|medications.controlled.record|clients.update`
- Owning module: eMAR and medications
- Legacy family: `MED-CLIENT-MEDICAL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `clients/{client}/medical` (`clients.medical.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.controlled.record|clients.update`, `permission:medications.administer.record|clients.update|medications.orders.manage`, `permission:medications.stock.update|medications.controlled.record|clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.controlled.record|clients.update`, `permission:medications.administer.record|clients.update|medications.orders.manage`, `permission:medications.stock.update|medications.controlled.record|clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD clients/{client}/medical` (`clients.medical.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST clients/{client}/medical/controlled-discrepancies/{discrepancy}/close` (`clients.medical.controlled_discrepancies.close`, action `closeControlledDiscrepancy`). Source category: **completed/closed/released**; controller `app/Http/Controllers/ClientMedicalController.php:614-648`; `resolution_notes`.
3. Invoke only the owning control for `POST clients/{client}/medical/medications/{medication}/administrations` (`clients.medical.medications.administrations.store`, action `storeAdministration`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientMedicalController.php:392-612`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT clients/{client}/medical/medications/{medication}/stock` (`clients.medical.medications.stock.update`, action `updateMedicationStock`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientMedicalController.php:274-390`; `on_hand`.
5. Invoke only the owning control for `POST operations/clients/{client}/medical/medications/{medication}/administrations` (`operations.clients.medical.medications.administrations.store`, action `storeAdministration`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientMedicalController.php:392-612`; no exact validation fields extracted.
6. Invoke only the owning control for `PUT operations/clients/{client}/medical/medications/{medication}/stock` (`operations.clients.medical.medications.stock.update`, action `updateMedicationStock`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientMedicalController.php:274-390`; `on_hand`.

## Source-applicable states and transitions

- **completed/closed/released** is applicable only to `closeControlledDiscrepancy` / `ROUTE-0171` at `app/Http/Controllers/ClientMedicalController.php:614`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeAdministration` / `ROUTE-0178` at `app/Http/Controllers/ClientMedicalController.php:392`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateMedicationStock` / `ROUTE-0179` at `app/Http/Controllers/ClientMedicalController.php:274`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeAdministration` / `ROUTE-2021` at `app/Http/Controllers/ClientMedicalController.php:392`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateMedicationStock` / `ROUTE-2022` at `app/Http/Controllers/ClientMedicalController.php:274`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0171` / `closeControlledDiscrepancy`: fields `resolution_notes`; success app/Http/Controllers/ClientMedicalController.php:627 `return back()->with('success', 'Discrepancy already closed.');`; app/Http/Controllers/ClientMedicalController.php:647 `return back()->with('success', 'Discrepancy closed.');`.
- `ROUTE-0178` / `storeAdministration`: success app/Http/Controllers/ClientMedicalController.php:437 `return back()->with('success', 'Already saved — no changes needed.');`; app/Http/Controllers/ClientMedicalController.php:606 `return back()->with('success', 'Medication administration recorded.');`; failure app/Http/Controllers/ClientMedicalController.php:442 `return back()->withInput()->withErrors([`.
- `ROUTE-0179` / `updateMedicationStock`: fields `on_hand`; success app/Http/Controllers/ClientMedicalController.php:384 `return back()->with('success', 'Medication stock updated successfully.');`.
- `ROUTE-2021` / `storeAdministration`: success app/Http/Controllers/ClientMedicalController.php:437 `return back()->with('success', 'Already saved — no changes needed.');`; app/Http/Controllers/ClientMedicalController.php:606 `return back()->with('success', 'Medication administration recorded.');`; failure app/Http/Controllers/ClientMedicalController.php:442 `return back()->withInput()->withErrors([`.
- `ROUTE-2022` / `updateMedicationStock`: fields `on_hand`; success app/Http/Controllers/ClientMedicalController.php:384 `return back()->with('success', 'Medication stock updated successfully.');`.

## Failure and recovery paths

- `storeAdministration`: app/Http/Controllers/ClientMedicalController.php:442 `return back()->withInput()->withErrors([`.
- `storeAdministration`: app/Http/Controllers/ClientMedicalController.php:442 `return back()->withInput()->withErrors([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ClientMedicalController.php:634 `$discrepancy->save();`; app/Http/Controllers/ClientMedicalController.php:336 `$stock->save();`; app/Http/Controllers/ClientMedicalController.php:338 `ClientControlledDrugEntry::create([`; app/Http/Controllers/ClientMedicalController.php:357 `$discrepancy = ClientControlledDrugDiscrepancy::create([`; responses app/Http/Controllers/ClientMedicalController.php:627 `return back()->with('success', 'Discrepancy already closed.');`; app/Http/Controllers/ClientMedicalController.php:647 `return back()->with('success', 'Discrepancy closed.');`; app/Http/Controllers/ClientMedicalController.php:434 `return response()->json($cached);`; app/Http/Controllers/ClientMedicalController.php:437 `return back()->with('success', 'Already saved — no changes needed.');`; app/Http/Controllers/ClientMedicalController.php:442 `return back()->withInput()->withErrors([`; app/Http/Controllers/ClientMedicalController.php:449 `return back()->withInput()->with('error', 'Please provide the PRN indication (reason) for as-needed medication.');`; app/Http/Controllers/ClientMedicalController.php:464 `return back()->withInput()->with('error', 'Please provide a reason when administering outside the scheduled time window.');`; app/Http/Controllers/ClientMedicalController.php:475 `return back()->withInput()->with('error', 'A witness is required when administering a controlled drug.');`; app/Http/Controllers/ClientMedicalController.php:478 `return back()->withInput()->with('error', 'The witness must be a different user.');`; app/Http/Controllers/ClientMedicalController.php:483 `return back()->withInput()->with('error', 'Selected witness is not authorised to witness controlled drug actions.');`; app/Http/Controllers/ClientMedicalController.php:509 `return response()->json($payload, 409);`; app/Http/Controllers/ClientMedicalController.php:512 `return back()->withInput()->with('error', $payload['error']);`; app/Http/Controllers/ClientMedicalController.php:528 `return response()->json(`; app/Http/Controllers/ClientMedicalController.php:540 `return back()->withInput()->with('error', $result['error'] ?? 'Failed to record administration.');`; app/Http/Controllers/ClientMedicalController.php:603 `return response()->json($payload);`; app/Http/Controllers/ClientMedicalController.php:606 `return back()->with('success', 'Medication administration recorded.');`; app/Http/Controllers/ClientMedicalController.php:610 `return back()->withInput()->with('error', 'Failed to record administration: '.$e->getMessage());`; app/Http/Controllers/ClientMedicalController.php:316 `return back()->withInput()->with('error', 'There is an open controlled-drug discrepancy. Further stock edits are blocked unless you have override permission.');`; app/Http/Controllers/ClientMedicalController.php:319 `return back()->withInput()->with('error', 'A witness is required when updating controlled drug stock.');`; app/Http/Controllers/ClientMedicalController.php:322 `return back()->withInput()->with('error', 'The witness must be a different user.');`; app/Http/Controllers/ClientMedicalController.php:325 `return back()->withInput()->with('error', 'Please provide a reason for the controlled drug stock update.');`; app/Http/Controllers/ClientMedicalController.php:330 `return back()->withInput()->with('error', 'Selected witness is not authorised to witness controlled drug actions.');`; app/Http/Controllers/ClientMedicalController.php:384 `return back()->with('success', 'Medication stock updated successfully.');`; app/Http/Controllers/ClientMedicalController.php:388 `return back()->withInput()->with('error', 'Failed to update medication stock: '.$e->getMessage());`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST clients/{client}/medical/controlled-discrepancies/{discrepancy}/close` — `clients.medical.controlled_discrepancies.close` — `App\Http\Controllers\ClientMedicalController@closeControlledDiscrepancy` — `app/Http/Controllers/ClientMedicalController.php:614` — middleware `web, auth, permission:medications.controlled.record|clients.update`
- `POST clients/{client}/medical/medications/{medication}/administrations` — `clients.medical.medications.administrations.store` — `App\Http\Controllers\ClientMedicalController@storeAdministration` — `app/Http/Controllers/ClientMedicalController.php:392` — middleware `web, auth, permission:medications.administer.record|clients.update|medications.orders.manage`
- `PUT clients/{client}/medical/medications/{medication}/stock` — `clients.medical.medications.stock.update` — `App\Http\Controllers\ClientMedicalController@updateMedicationStock` — `app/Http/Controllers/ClientMedicalController.php:274` — middleware `web, auth, permission:medications.stock.update|medications.controlled.record|clients.update`
- `POST operations/clients/{client}/medical/medications/{medication}/administrations` — `operations.clients.medical.medications.administrations.store` — `App\Http\Controllers\ClientMedicalController@storeAdministration` — `app/Http/Controllers/ClientMedicalController.php:392` — middleware `web, auth, permission:medications.administer.record|clients.update|medications.orders.manage`
- `PUT operations/clients/{client}/medical/medications/{medication}/stock` — `operations.clients.medical.medications.stock.update` — `App\Http\Controllers\ClientMedicalController@updateMedicationStock` — `app/Http/Controllers/ClientMedicalController.php:274` — middleware `web, auth, permission:medications.stock.update|medications.controlled.record|clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ClientMedicalController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
