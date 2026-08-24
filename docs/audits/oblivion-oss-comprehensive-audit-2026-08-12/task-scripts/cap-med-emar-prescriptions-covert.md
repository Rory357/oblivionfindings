# CAP-MED-EMAR-PRESCRIPTIONS-COVERT: Prescription countersigning and covert-authorisation lifecycle

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.orders.manage`
- Owning module: eMAR and medications
- Legacy family: `MED-EMAR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `emar/prescriptions` (`emar.prescriptions`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.orders.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.view`, `permission:medications.orders.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD emar/prescriptions` (`emar.prescriptions`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST emar/prescriptions` (`emar.prescriptions.store`, action `storePrescription`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/EmarController.php:2925-2969`; `client_id`, `client_medication_id`, `order_type`, `prescriber_name`, `prescriber_registration`, `prescriber_type`, `medication_name`, `dose`, `route`, `frequency`, `instructions`, `indication`, `clinical_notes`, `order_date`, `effective_date`, `expiry_date`, `read_back_confirmed`, `read_back_witnessed_by`.
3. Invoke only the owning control for `DELETE emar/prescriptions/{order}` (`emar.prescriptions.destroy`, action `destroyPrescription`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Emar/EmarController.php:3037-3042`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT emar/prescriptions/{order}` (`emar.prescriptions.update`, action `updatePrescription`). Source category: **updated/revised**; controller `app/Http/Controllers/Emar/EmarController.php:2998-3016`; `client_medication_id`, `pharmacy_notes`, `pharmacy_name`, `batch_number`, `batch_expiry`, `dispensed_by`, `dispensed_at`, `clinical_notes`, `instructions`.
5. Invoke only the owning control for `POST emar/prescriptions/{order}/countersign` (`emar.prescriptions.countersign`, action `countersignPrescription`). Source category: **mutation outcome source gap (countersignPrescription)**; controller `app/Http/Controllers/Emar/EmarController.php:3018-3035`; `countersign_method`.
6. Invoke only the owning control for `POST emar/prescriptions/covert` (`emar.covert.store`, action `storeCovert`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/EmarController.php:3046-3067`; `client_id`, `client_medication_id`, `authorised_by_name`, `authorised_by_registration`, `clinical_justification`, `legal_basis`, `administration_method`, `pharmacist_advice`, `authorised_date`, `review_date`.
7. Invoke only the owning control for `POST emar/prescriptions/covert/{authorisation}/revoke` (`emar.covert.revoke`, action `revokeCovert`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Emar/EmarController.php:3069-3074`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `prescriptions` / `ROUTE-0395` at `app/Http/Controllers/Emar/EmarController.php:2166`; it is not runtime-observed.
- **created/recorded** is applicable only to `storePrescription` / `ROUTE-0396` at `app/Http/Controllers/Emar/EmarController.php:2925`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyPrescription` / `ROUTE-0397` at `app/Http/Controllers/Emar/EmarController.php:3037`; it is not runtime-observed.
- **updated/revised** is applicable only to `updatePrescription` / `ROUTE-0398` at `app/Http/Controllers/Emar/EmarController.php:2998`; it is not runtime-observed.
- **mutation outcome source gap (countersignPrescription)** is applicable only to `countersignPrescription` / `ROUTE-0399` at `app/Http/Controllers/Emar/EmarController.php:3018`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeCovert` / `ROUTE-0400` at `app/Http/Controllers/Emar/EmarController.php:3046`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `revokeCovert` / `ROUTE-0401` at `app/Http/Controllers/Emar/EmarController.php:3069`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/emar/Prescriptions.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0396` / `storePrescription`: fields `client_id`, `client_medication_id`, `order_type`, `prescriber_name`, `prescriber_registration`, `prescriber_type`, `medication_name`, `dose`, `route`, `frequency`, `instructions`, `indication`, `clinical_notes`, `order_date`, `effective_date`, `expiry_date`, `read_back_confirmed`, `read_back_witnessed_by`.
- `ROUTE-0398` / `updatePrescription`: fields `client_medication_id`, `pharmacy_notes`, `pharmacy_name`, `batch_number`, `batch_expiry`, `dispensed_by`, `dispensed_at`, `clinical_notes`, `instructions`.
- `ROUTE-0399` / `countersignPrescription`: fields `countersign_method`.
- `ROUTE-0400` / `storeCovert`: fields `client_id`, `client_medication_id`, `authorised_by_name`, `authorised_by_registration`, `clinical_justification`, `legal_basis`, `administration_method`, `pharmacist_advice`, `authorised_date`, `review_date`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Emar/EmarController.php:2960 `$order = MedicationPrescriberOrder::create($validated);`; app/Http/Controllers/Emar/EmarController.php:3039 `$order->update(['status' => 'cancelled']);`; app/Http/Controllers/Emar/EmarController.php:3013 `$order->update($validated);`; app/Http/Controllers/Emar/EmarController.php:3024 `$order->update([`; app/Http/Controllers/Emar/EmarController.php:3064 `MedicationCovertAuthorisation::create($validated);`; app/Http/Controllers/Emar/EmarController.php:3071 `$authorisation->update(['status' => 'revoked']);`; responses app/Http/Controllers/Emar/EmarController.php:2180 `return [`; app/Http/Controllers/Emar/EmarController.php:2226 `return [`; app/Http/Controllers/Emar/EmarController.php:2246 `return Inertia::render('emar/Prescriptions', [`; app/Http/Controllers/Emar/EmarController.php:2968 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:3041 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:3015 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:3034 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:3066 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:3073 `return redirect()->back();`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD emar/prescriptions` — `emar.prescriptions` — `App\Http\Controllers\Emar\EmarController@prescriptions` — `app/Http/Controllers/Emar/EmarController.php:2166` — middleware `web, auth, permission:medications.view`
- `POST emar/prescriptions` — `emar.prescriptions.store` — `App\Http\Controllers\Emar\EmarController@storePrescription` — `app/Http/Controllers/Emar/EmarController.php:2925` — middleware `web, auth, permission:medications.orders.manage`
- `DELETE emar/prescriptions/{order}` — `emar.prescriptions.destroy` — `App\Http\Controllers\Emar\EmarController@destroyPrescription` — `app/Http/Controllers/Emar/EmarController.php:3037` — middleware `web, auth, permission:medications.orders.manage`
- `PUT emar/prescriptions/{order}` — `emar.prescriptions.update` — `App\Http\Controllers\Emar\EmarController@updatePrescription` — `app/Http/Controllers/Emar/EmarController.php:2998` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/prescriptions/{order}/countersign` — `emar.prescriptions.countersign` — `App\Http\Controllers\Emar\EmarController@countersignPrescription` — `app/Http/Controllers/Emar/EmarController.php:3018` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/prescriptions/covert` — `emar.covert.store` — `App\Http\Controllers\Emar\EmarController@storeCovert` — `app/Http/Controllers/Emar/EmarController.php:3046` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/prescriptions/covert/{authorisation}/revoke` — `emar.covert.revoke` — `App\Http\Controllers\Emar\EmarController@revokeCovert` — `app/Http/Controllers/Emar/EmarController.php:3069` — middleware `web, auth, permission:medications.orders.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Emar/EmarController.php`.
- Exact render/action page relationships: `resources/js/pages/emar/Prescriptions.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
