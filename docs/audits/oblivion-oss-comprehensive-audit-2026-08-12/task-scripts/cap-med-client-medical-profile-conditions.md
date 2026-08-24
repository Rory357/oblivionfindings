# CAP-MED-CLIENT-MEDICAL-PROFILE-CONDITIONS: Client medical profile and condition management

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clients.viewAny|clients.viewAssigned`, `permission:clients.update`
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

- Actor satisfying exact route middleware `auth`, `permission:clients.viewAny|clients.viewAssigned`, `permission:clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:clients.viewAny|clients.viewAssigned`, `permission:clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD clients/{client}/medical` (`clients.medical.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/clients/{client}/medical` (`operations.clients.medical.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ClientMedicalController.php:82-87`.
3. Invoke only the owning control for `POST clients/{client}/medical/conditions` (`clients.medical.conditions.store`, action `storeCondition`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientMedicalController.php:672-715`; `label`.
4. Invoke only the owning control for `DELETE clients/{client}/medical/conditions/{condition}` (`clients.medical.conditions.destroy`, action `destroyCondition`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ClientMedicalController.php:745-763`; no exact validation fields extracted.
5. Invoke only the owning control for `PUT clients/{client}/medical/conditions/{condition}` (`clients.medical.conditions.update`, action `updateCondition`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientMedicalController.php:717-743`; `label`.
6. Invoke only the owning control for `PUT clients/{client}/medical/profile` (`clients.medical.profile.update`, action `updateProfile`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientMedicalController.php:89-125`; `medical_history`.
7. Invoke only the owning control for `POST operations/clients/{client}/medical/conditions` (`operations.clients.medical.conditions.store`, action `storeCondition`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientMedicalController.php:672-715`; `label`.
8. Invoke only the owning control for `DELETE operations/clients/{client}/medical/conditions/{condition}` (`operations.clients.medical.conditions.destroy`, action `destroyCondition`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ClientMedicalController.php:745-763`; no exact validation fields extracted.
9. Invoke only the owning control for `PUT operations/clients/{client}/medical/conditions/{condition}` (`operations.clients.medical.conditions.update`, action `updateCondition`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientMedicalController.php:717-743`; `label`.
10. Invoke only the owning control for `PUT operations/clients/{client}/medical/profile` (`operations.clients.medical.profile.update`, action `updateProfile`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientMedicalController.php:89-125`; `medical_history`.

## Source-applicable states and transitions

- **information presented** is applicable only to `show` / `ROUTE-0167` at `app/Http/Controllers/ClientMedicalController.php:82`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeCondition` / `ROUTE-0168` at `app/Http/Controllers/ClientMedicalController.php:672`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyCondition` / `ROUTE-0169` at `app/Http/Controllers/ClientMedicalController.php:745`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateCondition` / `ROUTE-0170` at `app/Http/Controllers/ClientMedicalController.php:717`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateProfile` / `ROUTE-0180` at `app/Http/Controllers/ClientMedicalController.php:89`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2011` at `app/Http/Controllers/ClientMedicalController.php:82`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeCondition` / `ROUTE-2012` at `app/Http/Controllers/ClientMedicalController.php:672`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyCondition` / `ROUTE-2013` at `app/Http/Controllers/ClientMedicalController.php:745`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateCondition` / `ROUTE-2014` at `app/Http/Controllers/ClientMedicalController.php:717`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateProfile` / `ROUTE-2023` at `app/Http/Controllers/ClientMedicalController.php:89`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0168` / `storeCondition`: fields `label`; success app/Http/Controllers/ClientMedicalController.php:709 `return back()->with('success', 'Condition added successfully.');`.
- `ROUTE-0169` / `destroyCondition`: success app/Http/Controllers/ClientMedicalController.php:757 `return back()->with('success', 'Condition removed successfully.');`.
- `ROUTE-0170` / `updateCondition`: fields `label`; success app/Http/Controllers/ClientMedicalController.php:737 `return back()->with('success', 'Condition updated successfully.');`.
- `ROUTE-0180` / `updateProfile`: fields `medical_history`; success app/Http/Controllers/ClientMedicalController.php:124 `return back()->with('success', 'Medical profile saved successfully.');`.
- `ROUTE-2012` / `storeCondition`: fields `label`; success app/Http/Controllers/ClientMedicalController.php:709 `return back()->with('success', 'Condition added successfully.');`.
- `ROUTE-2013` / `destroyCondition`: success app/Http/Controllers/ClientMedicalController.php:757 `return back()->with('success', 'Condition removed successfully.');`.
- `ROUTE-2014` / `updateCondition`: fields `label`; success app/Http/Controllers/ClientMedicalController.php:737 `return back()->with('success', 'Condition updated successfully.');`.
- `ROUTE-2023` / `updateProfile`: fields `medical_history`; success app/Http/Controllers/ClientMedicalController.php:124 `return back()->with('success', 'Medical profile saved successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ClientMedicalController.php:686 `$c->save();`; app/Http/Controllers/ClientMedicalController.php:751 `$condition->delete();`; app/Http/Controllers/ClientMedicalController.php:730 `$condition->save();`; app/Http/Controllers/ClientMedicalController.php:117 `$profile->save();`; responses app/Http/Controllers/ClientMedicalController.php:86 `return redirect()->to(EmarUrl::medications($client));`; app/Http/Controllers/ClientMedicalController.php:709 `return back()->with('success', 'Condition added successfully.');`; app/Http/Controllers/ClientMedicalController.php:713 `return back()->withInput()->with('error', 'Failed to add condition: '.$e->getMessage());`; app/Http/Controllers/ClientMedicalController.php:757 `return back()->with('success', 'Condition removed successfully.');`; app/Http/Controllers/ClientMedicalController.php:761 `return back()->with('error', 'Failed to remove condition: '.$e->getMessage());`; app/Http/Controllers/ClientMedicalController.php:737 `return back()->with('success', 'Condition updated successfully.');`; app/Http/Controllers/ClientMedicalController.php:741 `return back()->withInput()->with('error', 'Failed to update condition: '.$e->getMessage());`; app/Http/Controllers/ClientMedicalController.php:124 `return back()->with('success', 'Medical profile saved successfully.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD clients/{client}/medical` — `clients.medical.show` — `App\Http\Controllers\ClientMedicalController@show` — `app/Http/Controllers/ClientMedicalController.php:82` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned`
- `POST clients/{client}/medical/conditions` — `clients.medical.conditions.store` — `App\Http\Controllers\ClientMedicalController@storeCondition` — `app/Http/Controllers/ClientMedicalController.php:672` — middleware `web, auth, permission:clients.update`
- `DELETE clients/{client}/medical/conditions/{condition}` — `clients.medical.conditions.destroy` — `App\Http\Controllers\ClientMedicalController@destroyCondition` — `app/Http/Controllers/ClientMedicalController.php:745` — middleware `web, auth, permission:clients.update`
- `PUT clients/{client}/medical/conditions/{condition}` — `clients.medical.conditions.update` — `App\Http\Controllers\ClientMedicalController@updateCondition` — `app/Http/Controllers/ClientMedicalController.php:717` — middleware `web, auth, permission:clients.update`
- `PUT clients/{client}/medical/profile` — `clients.medical.profile.update` — `App\Http\Controllers\ClientMedicalController@updateProfile` — `app/Http/Controllers/ClientMedicalController.php:89` — middleware `web, auth, permission:clients.update`
- `GET|HEAD operations/clients/{client}/medical` — `operations.clients.medical.show` — `App\Http\Controllers\ClientMedicalController@show` — `app/Http/Controllers/ClientMedicalController.php:82` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned`
- `POST operations/clients/{client}/medical/conditions` — `operations.clients.medical.conditions.store` — `App\Http\Controllers\ClientMedicalController@storeCondition` — `app/Http/Controllers/ClientMedicalController.php:672` — middleware `web, auth, permission:clients.update`
- `DELETE operations/clients/{client}/medical/conditions/{condition}` — `operations.clients.medical.conditions.destroy` — `App\Http\Controllers\ClientMedicalController@destroyCondition` — `app/Http/Controllers/ClientMedicalController.php:745` — middleware `web, auth, permission:clients.update`
- `PUT operations/clients/{client}/medical/conditions/{condition}` — `operations.clients.medical.conditions.update` — `App\Http\Controllers\ClientMedicalController@updateCondition` — `app/Http/Controllers/ClientMedicalController.php:717` — middleware `web, auth, permission:clients.update`
- `PUT operations/clients/{client}/medical/profile` — `operations.clients.medical.profile.update` — `App\Http\Controllers\ClientMedicalController@updateProfile` — `app/Http/Controllers/ClientMedicalController.php:89` — middleware `web, auth, permission:clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ClientMedicalController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
