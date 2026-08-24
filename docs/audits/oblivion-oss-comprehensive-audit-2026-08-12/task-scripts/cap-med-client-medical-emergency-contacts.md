# CAP-MED-CLIENT-MEDICAL-EMERGENCY-CONTACTS: Client emergency contact management

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
2. Invoke only the owning control for `POST clients/{client}/medical/emergency-contacts` (`clients.medical.emergency_contacts.store`, action `storeEmergencyContact`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientMedicalController.php:765-803`; `name`.
3. Invoke only the owning control for `DELETE clients/{client}/medical/emergency-contacts/{contact}` (`clients.medical.emergency_contacts.destroy`, action `destroyEmergencyContact`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ClientMedicalController.php:844-862`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT clients/{client}/medical/emergency-contacts/{contact}` (`clients.medical.emergency_contacts.update`, action `updateEmergencyContact`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientMedicalController.php:805-842`; `name`.
5. Invoke only the owning control for `POST operations/clients/{client}/medical/emergency-contacts` (`operations.clients.medical.emergency_contacts.store`, action `storeEmergencyContact`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientMedicalController.php:765-803`; `name`.
6. Invoke only the owning control for `DELETE operations/clients/{client}/medical/emergency-contacts/{contact}` (`operations.clients.medical.emergency_contacts.destroy`, action `destroyEmergencyContact`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ClientMedicalController.php:844-862`; no exact validation fields extracted.
7. Invoke only the owning control for `PUT operations/clients/{client}/medical/emergency-contacts/{contact}` (`operations.clients.medical.emergency_contacts.update`, action `updateEmergencyContact`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientMedicalController.php:805-842`; `name`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeEmergencyContact` / `ROUTE-0172` at `app/Http/Controllers/ClientMedicalController.php:765`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyEmergencyContact` / `ROUTE-0173` at `app/Http/Controllers/ClientMedicalController.php:844`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateEmergencyContact` / `ROUTE-0174` at `app/Http/Controllers/ClientMedicalController.php:805`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeEmergencyContact` / `ROUTE-2015` at `app/Http/Controllers/ClientMedicalController.php:765`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyEmergencyContact` / `ROUTE-2016` at `app/Http/Controllers/ClientMedicalController.php:844`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateEmergencyContact` / `ROUTE-2017` at `app/Http/Controllers/ClientMedicalController.php:805`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0172` / `storeEmergencyContact`: fields `name`; success app/Http/Controllers/ClientMedicalController.php:797 `return back()->with('success', 'Emergency contact added successfully.');`.
- `ROUTE-0173` / `destroyEmergencyContact`: success app/Http/Controllers/ClientMedicalController.php:856 `return back()->with('success', 'Emergency contact removed successfully.');`.
- `ROUTE-0174` / `updateEmergencyContact`: fields `name`; success app/Http/Controllers/ClientMedicalController.php:836 `return back()->with('success', 'Emergency contact updated successfully.');`.
- `ROUTE-2015` / `storeEmergencyContact`: fields `name`; success app/Http/Controllers/ClientMedicalController.php:797 `return back()->with('success', 'Emergency contact added successfully.');`.
- `ROUTE-2016` / `destroyEmergencyContact`: success app/Http/Controllers/ClientMedicalController.php:856 `return back()->with('success', 'Emergency contact removed successfully.');`.
- `ROUTE-2017` / `updateEmergencyContact`: fields `name`; success app/Http/Controllers/ClientMedicalController.php:836 `return back()->with('success', 'Emergency contact updated successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ClientMedicalController.php:790 `$e->save();`; app/Http/Controllers/ClientMedicalController.php:850 `$contact->delete();`; app/Http/Controllers/ClientMedicalController.php:829 `$contact->save();`; responses app/Http/Controllers/ClientMedicalController.php:797 `return back()->with('success', 'Emergency contact added successfully.');`; app/Http/Controllers/ClientMedicalController.php:801 `return back()->withInput()->with('error', 'Failed to add emergency contact: '.$e->getMessage());`; app/Http/Controllers/ClientMedicalController.php:856 `return back()->with('success', 'Emergency contact removed successfully.');`; app/Http/Controllers/ClientMedicalController.php:860 `return back()->with('error', 'Failed to remove emergency contact: '.$e->getMessage());`; app/Http/Controllers/ClientMedicalController.php:836 `return back()->with('success', 'Emergency contact updated successfully.');`; app/Http/Controllers/ClientMedicalController.php:840 `return back()->withInput()->with('error', 'Failed to update emergency contact: '.$e->getMessage());`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST clients/{client}/medical/emergency-contacts` — `clients.medical.emergency_contacts.store` — `App\Http\Controllers\ClientMedicalController@storeEmergencyContact` — `app/Http/Controllers/ClientMedicalController.php:765` — middleware `web, auth, permission:clients.update`
- `DELETE clients/{client}/medical/emergency-contacts/{contact}` — `clients.medical.emergency_contacts.destroy` — `App\Http\Controllers\ClientMedicalController@destroyEmergencyContact` — `app/Http/Controllers/ClientMedicalController.php:844` — middleware `web, auth, permission:clients.update`
- `PUT clients/{client}/medical/emergency-contacts/{contact}` — `clients.medical.emergency_contacts.update` — `App\Http\Controllers\ClientMedicalController@updateEmergencyContact` — `app/Http/Controllers/ClientMedicalController.php:805` — middleware `web, auth, permission:clients.update`
- `POST operations/clients/{client}/medical/emergency-contacts` — `operations.clients.medical.emergency_contacts.store` — `App\Http\Controllers\ClientMedicalController@storeEmergencyContact` — `app/Http/Controllers/ClientMedicalController.php:765` — middleware `web, auth, permission:clients.update`
- `DELETE operations/clients/{client}/medical/emergency-contacts/{contact}` — `operations.clients.medical.emergency_contacts.destroy` — `App\Http\Controllers\ClientMedicalController@destroyEmergencyContact` — `app/Http/Controllers/ClientMedicalController.php:844` — middleware `web, auth, permission:clients.update`
- `PUT operations/clients/{client}/medical/emergency-contacts/{contact}` — `operations.clients.medical.emergency_contacts.update` — `App\Http\Controllers\ClientMedicalController@updateEmergencyContact` — `app/Http/Controllers/ClientMedicalController.php:805` — middleware `web, auth, permission:clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ClientMedicalController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
