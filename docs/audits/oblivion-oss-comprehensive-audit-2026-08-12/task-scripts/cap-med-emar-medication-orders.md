# CAP-MED-EMAR-MEDICATION-ORDERS: Medication orders verification discontinuation and import

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.orders.manage`, `permission:medications.orders.verify|medications.orders.manage|clients.update`
- Owning module: eMAR and medications
- Legacy family: `MED-EMAR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `emar/medications` (`emar.medications`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.orders.manage`, `permission:medications.orders.verify|medications.orders.manage|clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.view`, `permission:medications.orders.manage`, `permission:medications.orders.verify|medications.orders.manage|clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD emar/medications` (`emar.medications`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD emar/medications/{medication}/detail` (`emar.medications.detail`, action `medicationDetail`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Emar/EmarController.php:1722-1809`.
3. Invoke only the owning control for `POST emar/medications` (`emar.medications.store`, action `storeMedication`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/EmarController.php:4399-4445`; `client_id`, `medication_name`, `brand_name`, `dose`, `dose_unit`, `frequency`, `route`, `form`, `instructions`, `indication`, `is_prn`, `prn_reason`, `max_per_day`, `max_doses_per_day`, `min_hours_between_doses`, `controlled_drug`, `is_controlled_drug`, `high_risk`, `is_high_risk`, `witness_required`, `start_date`, `prescriber`, `prescriber_name`, `pharmac_therapeutic_group`, `pharmac_subgroup`.
4. Invoke only the owning control for `PUT emar/medications/{medication}` (`emar.medications.update`, action `updateMedication`). Source category: **updated/revised**; controller `app/Http/Controllers/Emar/EmarController.php:4447-4489`; `client_id`, `medication_name`, `brand_name`, `dose`, `dose_unit`, `frequency`, `route`, `form`, `instructions`, `indication`, `is_prn`, `prn_reason`, `max_per_day`, `max_doses_per_day`, `min_hours_between_doses`, `controlled_drug`, `is_controlled_drug`, `high_risk`, `is_high_risk`, `witness_required`, `start_date`, `prescriber`, `prescriber_name`, `pharmac_therapeutic_group`, `pharmac_subgroup`.
5. Invoke only the owning control for `POST emar/medications/{medication}/discontinue` (`emar.medications.discontinue`, action `discontinueMedication`). Source category: **mutation outcome source gap (discontinueMedication)**; controller `app/Http/Controllers/Emar/EmarController.php:4523-4541`; `reason`.
6. Invoke only the owning control for `POST emar/medications/{medication}/reject` (`emar.medications.reject`, action `rejectMedication`). Source category: **rejected/returned**; controller `app/Http/Controllers/Emar/EmarController.php:4505-4521`; `rejection_reason`.
7. Invoke only the owning control for `POST emar/medications/{medication}/verify` (`emar.medications.verify`, action `verifyMedication`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Emar/EmarController.php:4491-4503`; no exact validation fields extracted.
8. Invoke only the owning control for `POST emar/medications/import` (`emar.medications.import`, action `importMedications`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/EmarController.php:4984-5082`; `csv_file`.

## Source-applicable states and transitions

- **information presented** is applicable only to `medications` / `ROUTE-0384` at `app/Http/Controllers/Emar/EmarController.php:1811`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeMedication` / `ROUTE-0385` at `app/Http/Controllers/Emar/EmarController.php:4399`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateMedication` / `ROUTE-0386` at `app/Http/Controllers/Emar/EmarController.php:4447`; it is not runtime-observed.
- **information presented** is applicable only to `medicationDetail` / `ROUTE-0387` at `app/Http/Controllers/Emar/EmarController.php:1722`; it is not runtime-observed.
- **mutation outcome source gap (discontinueMedication)** is applicable only to `discontinueMedication` / `ROUTE-0388` at `app/Http/Controllers/Emar/EmarController.php:4523`; it is not runtime-observed.
- **rejected/returned** is applicable only to `rejectMedication` / `ROUTE-0389` at `app/Http/Controllers/Emar/EmarController.php:4505`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `verifyMedication` / `ROUTE-0390` at `app/Http/Controllers/Emar/EmarController.php:4491`; it is not runtime-observed.
- **created/recorded** is applicable only to `importMedications` / `ROUTE-0391` at `app/Http/Controllers/Emar/EmarController.php:4984`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/emar/Medications.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0385` / `storeMedication`: fields `client_id`, `medication_name`, `brand_name`, `dose`, `dose_unit`, `frequency`, `route`, `form`, `instructions`, `indication`, `is_prn`, `prn_reason`, `max_per_day`, `max_doses_per_day`, `min_hours_between_doses`, `controlled_drug`, `is_controlled_drug`, `high_risk`, `is_high_risk`, `witness_required`, `start_date`, `prescriber`, `prescriber_name`, `pharmac_therapeutic_group`, `pharmac_subgroup`.
- `ROUTE-0386` / `updateMedication`: fields `client_id`, `medication_name`, `brand_name`, `dose`, `dose_unit`, `frequency`, `route`, `form`, `instructions`, `indication`, `is_prn`, `prn_reason`, `max_per_day`, `max_doses_per_day`, `min_hours_between_doses`, `controlled_drug`, `is_controlled_drug`, `high_risk`, `is_high_risk`, `witness_required`, `start_date`, `prescriber`, `prescriber_name`, `pharmac_therapeutic_group`, `pharmac_subgroup`.
- `ROUTE-0388` / `discontinueMedication`: fields `reason`.
- `ROUTE-0389` / `rejectMedication`: fields `rejection_reason`; success app/Http/Controllers/Emar/EmarController.php:4520 `return redirect()->back()->with('success', 'Medication order rejected.');`.
- `ROUTE-0390` / `verifyMedication`: success app/Http/Controllers/Emar/EmarController.php:4502 `return redirect()->back()->with('success', 'Medication order verified.');`.
- `ROUTE-0391` / `importMedications`: fields `csv_file`; failure app/Http/Controllers/Emar/EmarController.php:4994 `return redirect()->back()->withErrors(['csv_file' => 'Unable to read the CSV file.']);`.

## Failure and recovery paths

- `importMedications`: app/Http/Controllers/Emar/EmarController.php:4994 `return redirect()->back()->withErrors(['csv_file' => 'Unable to read the CSV file.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Emar/EmarController.php:4431 `$medication = ClientMedication::create(array_merge(`; app/Http/Controllers/Emar/EmarController.php:4486 `$medication->update($payload);`; app/Http/Controllers/Emar/EmarController.php:4531 `$medication->update([`; app/Http/Controllers/Emar/EmarController.php:4518 `])->save();`; app/Http/Controllers/Emar/EmarController.php:4500 `])->save();`; app/Http/Controllers/Emar/EmarController.php:5060 `ClientMedication::create([`; responses app/Http/Controllers/Emar/EmarController.php:1874 `return [`; app/Http/Controllers/Emar/EmarController.php:1921 `return Inertia::render('emar/Medications', [`; app/Http/Controllers/Emar/EmarController.php:4444 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:4488 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:1753 `return [`; app/Http/Controllers/Emar/EmarController.php:1805 `return response()->json([`; app/Http/Controllers/Emar/EmarController.php:4540 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:4520 `return redirect()->back()->with('success', 'Medication order rejected.');`; app/Http/Controllers/Emar/EmarController.php:4502 `return redirect()->back()->with('success', 'Medication order verified.');`; app/Http/Controllers/Emar/EmarController.php:4994 `return redirect()->back()->withErrors(['csv_file' => 'Unable to read the CSV file.']);`; app/Http/Controllers/Emar/EmarController.php:5081 `return redirect()->back();`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD emar/medications` — `emar.medications` — `App\Http\Controllers\Emar\EmarController@medications` — `app/Http/Controllers/Emar/EmarController.php:1811` — middleware `web, auth, permission:medications.view`
- `POST emar/medications` — `emar.medications.store` — `App\Http\Controllers\Emar\EmarController@storeMedication` — `app/Http/Controllers/Emar/EmarController.php:4399` — middleware `web, auth, permission:medications.orders.manage`
- `PUT emar/medications/{medication}` — `emar.medications.update` — `App\Http\Controllers\Emar\EmarController@updateMedication` — `app/Http/Controllers/Emar/EmarController.php:4447` — middleware `web, auth, permission:medications.orders.manage`
- `GET|HEAD emar/medications/{medication}/detail` — `emar.medications.detail` — `App\Http\Controllers\Emar\EmarController@medicationDetail` — `app/Http/Controllers/Emar/EmarController.php:1722` — middleware `web, auth, permission:medications.view`
- `POST emar/medications/{medication}/discontinue` — `emar.medications.discontinue` — `App\Http\Controllers\Emar\EmarController@discontinueMedication` — `app/Http/Controllers/Emar/EmarController.php:4523` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/medications/{medication}/reject` — `emar.medications.reject` — `App\Http\Controllers\Emar\EmarController@rejectMedication` — `app/Http/Controllers/Emar/EmarController.php:4505` — middleware `web, auth, permission:medications.orders.verify|medications.orders.manage|clients.update`
- `POST emar/medications/{medication}/verify` — `emar.medications.verify` — `App\Http\Controllers\Emar\EmarController@verifyMedication` — `app/Http/Controllers/Emar/EmarController.php:4491` — middleware `web, auth, permission:medications.orders.verify|medications.orders.manage|clients.update`
- `POST emar/medications/import` — `emar.medications.import` — `App\Http\Controllers\Emar\EmarController@importMedications` — `app/Http/Controllers/Emar/EmarController.php:4984` — middleware `web, auth, permission:medications.orders.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Emar/EmarController.php`.
- Exact render/action page relationships: `resources/js/pages/emar/Medications.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
