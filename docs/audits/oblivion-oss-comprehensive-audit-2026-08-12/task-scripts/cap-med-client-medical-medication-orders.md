# CAP-MED-CLIENT-MEDICAL-MEDICATION-ORDERS: Client medication order maintenance

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clients.update`
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

- Actor satisfying exact route middleware `auth`, `permission:clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD clients/{client}/medical` (`clients.medical.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST clients/{client}/medical/medications` (`clients.medical.medications.store`, action `storeMedication`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientMedicalController.php:127-212`; `name`.
3. Invoke only the owning control for `DELETE clients/{client}/medical/medications/{medication}` (`clients.medical.medications.destroy`, action `destroyMedication`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ClientMedicalController.php:650-670`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT clients/{client}/medical/medications/{medication}` (`clients.medical.medications.update`, action `updateMedication`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientMedicalController.php:214-272`; `name`.
5. Invoke only the owning control for `POST operations/clients/{client}/medical/medications` (`operations.clients.medical.medications.store`, action `storeMedication`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientMedicalController.php:127-212`; `name`.
6. Invoke only the owning control for `DELETE operations/clients/{client}/medical/medications/{medication}` (`operations.clients.medical.medications.destroy`, action `destroyMedication`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ClientMedicalController.php:650-670`; no exact validation fields extracted.
7. Invoke only the owning control for `PUT operations/clients/{client}/medical/medications/{medication}` (`operations.clients.medical.medications.update`, action `updateMedication`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientMedicalController.php:214-272`; `name`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeMedication` / `ROUTE-0175` at `app/Http/Controllers/ClientMedicalController.php:127`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyMedication` / `ROUTE-0176` at `app/Http/Controllers/ClientMedicalController.php:650`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateMedication` / `ROUTE-0177` at `app/Http/Controllers/ClientMedicalController.php:214`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeMedication` / `ROUTE-2018` at `app/Http/Controllers/ClientMedicalController.php:127`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyMedication` / `ROUTE-2019` at `app/Http/Controllers/ClientMedicalController.php:650`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateMedication` / `ROUTE-2020` at `app/Http/Controllers/ClientMedicalController.php:214`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0175` / `storeMedication`: fields `name`; success app/Http/Controllers/ClientMedicalController.php:206 `return back()->with('success', 'Medication added successfully.');`.
- `ROUTE-0176` / `destroyMedication`: success app/Http/Controllers/ClientMedicalController.php:664 `return back()->with('success', 'Medication removed successfully.');`.
- `ROUTE-0177` / `updateMedication`: fields `name`; success app/Http/Controllers/ClientMedicalController.php:266 `return back()->with('success', 'Medication updated successfully.');`.
- `ROUTE-2018` / `storeMedication`: fields `name`; success app/Http/Controllers/ClientMedicalController.php:206 `return back()->with('success', 'Medication added successfully.');`.
- `ROUTE-2019` / `destroyMedication`: success app/Http/Controllers/ClientMedicalController.php:664 `return back()->with('success', 'Medication removed successfully.');`.
- `ROUTE-2020` / `updateMedication`: fields `name`; success app/Http/Controllers/ClientMedicalController.php:266 `return back()->with('success', 'Medication updated successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ClientMedicalController.php:175 `$m->save();`; app/Http/Controllers/ClientMedicalController.php:658 `$medication->delete();`; app/Http/Controllers/ClientMedicalController.php:259 `$medication->save();`; responses app/Http/Controllers/ClientMedicalController.php:206 `return back()->with('success', 'Medication added successfully.');`; app/Http/Controllers/ClientMedicalController.php:210 `return back()->withInput()->with('error', 'Failed to add medication: '.$e->getMessage());`; app/Http/Controllers/ClientMedicalController.php:664 `return back()->with('success', 'Medication removed successfully.');`; app/Http/Controllers/ClientMedicalController.php:668 `return back()->with('error', 'Failed to remove medication: '.$e->getMessage());`; app/Http/Controllers/ClientMedicalController.php:266 `return back()->with('success', 'Medication updated successfully.');`; app/Http/Controllers/ClientMedicalController.php:270 `return back()->withInput()->with('error', 'Failed to update medication: '.$e->getMessage());`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST clients/{client}/medical/medications` — `clients.medical.medications.store` — `App\Http\Controllers\ClientMedicalController@storeMedication` — `app/Http/Controllers/ClientMedicalController.php:127` — middleware `web, auth, permission:clients.update`
- `DELETE clients/{client}/medical/medications/{medication}` — `clients.medical.medications.destroy` — `App\Http\Controllers\ClientMedicalController@destroyMedication` — `app/Http/Controllers/ClientMedicalController.php:650` — middleware `web, auth, permission:clients.update`
- `PUT clients/{client}/medical/medications/{medication}` — `clients.medical.medications.update` — `App\Http\Controllers\ClientMedicalController@updateMedication` — `app/Http/Controllers/ClientMedicalController.php:214` — middleware `web, auth, permission:clients.update`
- `POST operations/clients/{client}/medical/medications` — `operations.clients.medical.medications.store` — `App\Http\Controllers\ClientMedicalController@storeMedication` — `app/Http/Controllers/ClientMedicalController.php:127` — middleware `web, auth, permission:clients.update`
- `DELETE operations/clients/{client}/medical/medications/{medication}` — `operations.clients.medical.medications.destroy` — `App\Http\Controllers\ClientMedicalController@destroyMedication` — `app/Http/Controllers/ClientMedicalController.php:650` — middleware `web, auth, permission:clients.update`
- `PUT operations/clients/{client}/medical/medications/{medication}` — `operations.clients.medical.medications.update` — `App\Http\Controllers\ClientMedicalController@updateMedication` — `app/Http/Controllers/ClientMedicalController.php:214` — middleware `web, auth, permission:clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ClientMedicalController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
