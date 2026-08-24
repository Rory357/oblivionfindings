# CAP-MED-EMAR-CONTROLLED-DRUGS: Controlled-drug ledger balances discrepancies and destruction

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.orders.manage`
- Owning module: eMAR and medications
- Legacy family: `MED-EMAR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `emar/controlled` (`emar.controlled`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.orders.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.view`, `permission:medications.orders.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD emar/controlled` (`emar.controlled`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD emar/destructions` (`emar.destructions`, action `destructions`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Emar/EmarController.php:2749-2830`.
3. Invoke only the owning control for `POST emar/controlled/balance-check` (`emar.controlled.balance_check.store`, action `storeBalanceCheck`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/EmarController.php:4691-4847`; `client_id`, `medication_name`, `on_hand_before`, `on_hand_after`, `expected_balance`, `actual_balance`, `witnessed_by`, `discrepancy_notes`, `client_request_uuid`, `captured_offline_at`, `origin_device_id`, `queued_offline`.
4. Invoke only the owning control for `POST emar/controlled/discrepancies/{discrepancy}/resolve` (`emar.controlled.discrepancies.resolve`, action `resolveDiscrepancy`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Emar/EmarController.php:4849-4873`; `resolution_notes`, `resolution_action`.
5. Invoke only the owning control for `POST emar/controlled/entries` (`emar.controlled.entries.store`, action `storeCDEntry`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/EmarController.php:4545-4689`; `client_id`, `medication_name`, `entry_type`, `quantity`, `unit`, `on_hand_before`, `on_hand_after`, `balance_before`, `balance_after`, `witnessed_by`, `batch_number`, `expiry_date`, `cd_schedule`, `notes`, `client_request_uuid`, `captured_offline_at`, `origin_device_id`, `queued_offline`.
6. Invoke only the owning control for `POST emar/destructions` (`emar.destructions.store`, action `storeDestruction`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/EmarController.php:3835-3934`; `client_id`, `client_medication_id`, `site_id`, `medication_name`, `form`, `strength`, `quantity`, `unit`, `batch_number`, `expiry_date`, `reason`, `disposal_method`, `is_controlled_drug`, `controlled_drug_class`, `witness_1_id`, `witness_2_id`, `authorised_by_name`, `authorised_by_registration`, `notes`.
7. Invoke only the owning control for `POST emar/destructions/{destruction}/void` (`emar.destructions.void`, action `voidDestruction`). Source category: **mutation outcome source gap (voidDestruction)**; controller `app/Http/Controllers/Emar/EmarController.php:4963-4980`; `void_reason`.

## Source-applicable states and transitions

- **information presented** is applicable only to `controlled` / `ROUTE-0351` at `app/Http/Controllers/Emar/EmarController.php:1452`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeBalanceCheck` / `ROUTE-0352` at `app/Http/Controllers/Emar/EmarController.php:4691`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `resolveDiscrepancy` / `ROUTE-0353` at `app/Http/Controllers/Emar/EmarController.php:4849`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeCDEntry` / `ROUTE-0354` at `app/Http/Controllers/Emar/EmarController.php:4545`; it is not runtime-observed.
- **information presented** is applicable only to `destructions` / `ROUTE-0362` at `app/Http/Controllers/Emar/EmarController.php:2749`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeDestruction` / `ROUTE-0363` at `app/Http/Controllers/Emar/EmarController.php:3835`; it is not runtime-observed.
- **mutation outcome source gap (voidDestruction)** is applicable only to `voidDestruction` / `ROUTE-0364` at `app/Http/Controllers/Emar/EmarController.php:4963`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/emar/ControlledDrugs.tsx`, `resources/js/pages/emar/Destructions.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0352` / `storeBalanceCheck`: fields `client_id`, `medication_name`, `on_hand_before`, `on_hand_after`, `expected_balance`, `actual_balance`, `witnessed_by`, `discrepancy_notes`, `client_request_uuid`, `captured_offline_at`, `origin_device_id`, `queued_offline`; success app/Http/Controllers/Emar/EmarController.php:4846 `return redirect()->back()->with('success', 'Controlled drug balance check recorded.');`.
- `ROUTE-0353` / `resolveDiscrepancy`: fields `resolution_notes`, `resolution_action`.
- `ROUTE-0354` / `storeCDEntry`: fields `client_id`, `medication_name`, `entry_type`, `quantity`, `unit`, `on_hand_before`, `on_hand_after`, `balance_before`, `balance_after`, `witnessed_by`, `batch_number`, `expiry_date`, `cd_schedule`, `notes`, `client_request_uuid`, `captured_offline_at`, `origin_device_id`, `queued_offline`; success app/Http/Controllers/Emar/EmarController.php:4688 `return redirect()->back()->with('success', 'Controlled drug entry recorded.');`; failure app/Http/Controllers/Emar/EmarController.php:4596 `throw ValidationException::withMessages([`.
- `ROUTE-0363` / `storeDestruction`: fields `client_id`, `client_medication_id`, `site_id`, `medication_name`, `form`, `strength`, `quantity`, `unit`, `batch_number`, `expiry_date`, `reason`, `disposal_method`, `is_controlled_drug`, `controlled_drug_class`, `witness_1_id`, `witness_2_id`, `authorised_by_name`, `authorised_by_registration`, `notes`; failure app/Http/Controllers/Emar/EmarController.php:3870 `return redirect()->back()->withErrors(['witness_1_id' => 'Witness must be a different person from the person destroying the medication.']);`; app/Http/Controllers/Emar/EmarController.php:3874 `return redirect()->back()->withErrors(['witness_2_id' => 'The second witness must be a different person from the person destroying the medication.']);`; app/Http/Controllers/Emar/EmarController.php:3877 `return redirect()->back()->withErrors(['witness_2_id' => 'The second witness must be a different person from the first witness.']);`; app/Http/Controllers/Emar/EmarController.php:3899 `throw ValidationException::withMessages([`.
- `ROUTE-0364` / `voidDestruction`: fields `void_reason`; success app/Http/Controllers/Emar/EmarController.php:4979 `return redirect()->back()->with('success', 'Destruction record voided.');`; failure app/Http/Controllers/Emar/EmarController.php:4970 `return redirect()->back()->withErrors(['void_reason' => 'This destruction record has already been voided.']);`.

## Failure and recovery paths

- `storeCDEntry`: app/Http/Controllers/Emar/EmarController.php:4596 `throw ValidationException::withMessages([`.
- `storeDestruction`: app/Http/Controllers/Emar/EmarController.php:3870 `return redirect()->back()->withErrors(['witness_1_id' => 'Witness must be a different person from the person destroying the medication.']);`; app/Http/Controllers/Emar/EmarController.php:3874 `return redirect()->back()->withErrors(['witness_2_id' => 'The second witness must be a different person from the person destroying the medication.']);`; app/Http/Controllers/Emar/EmarController.php:3877 `return redirect()->back()->withErrors(['witness_2_id' => 'The second witness must be a different person from the first witness.']);`; app/Http/Controllers/Emar/EmarController.php:3899 `throw ValidationException::withMessages([`.
- `voidDestruction`: app/Http/Controllers/Emar/EmarController.php:4970 `return redirect()->back()->withErrors(['void_reason' => 'This destruction record has already been voided.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Emar/EmarController.php:4742 `$entry = ClientControlledDrugEntry::create([`; app/Http/Controllers/Emar/EmarController.php:4759 `$stock = $medication->stock ?? $medication->stock()->create([`; app/Http/Controllers/Emar/EmarController.php:4763 `$stock->update([`; app/Http/Controllers/Emar/EmarController.php:4770 `$discrepancy = ClientControlledDrugDiscrepancy::create([`; app/Http/Controllers/Emar/EmarController.php:4805 `$discrepancy->forceFill(['incident_id' => $incident->id])->save();`; app/Http/Controllers/Emar/EmarController.php:4856 `$discrepancy->update([`; app/Http/Controllers/Emar/EmarController.php:4617 `$entry = ClientControlledDrugEntry::create([`; app/Http/Controllers/Emar/EmarController.php:4636 `$stock = $medication->stock ?? $medication->stock()->create([`; app/Http/Controllers/Emar/EmarController.php:4640 `$stock->update([`; app/Http/Controllers/Emar/EmarController.php:4651 `$medication->forceFill(['cd_schedule' => (int) $validated['cd_schedule']])->save();`; app/Http/Controllers/Emar/EmarController.php:3885 `MedicationDestruction::create($validated);`; app/Http/Controllers/Emar/EmarController.php:3906 `$stock->save();`; app/Http/Controllers/Emar/EmarController.php:3912 `ClientControlledDrugEntry::create([`; app/Http/Controllers/Emar/EmarController.php:4973 `$destruction->update([`; responses app/Http/Controllers/Emar/EmarController.php:1558 `return Inertia::render('emar/ControlledDrugs', [`; app/Http/Controllers/Emar/EmarController.php:1564 `return [`; app/Http/Controllers/Emar/EmarController.php:4714 `return response()->json($cached);`; app/Http/Controllers/Emar/EmarController.php:4729 `return response()->json(`; app/Http/Controllers/Emar/EmarController.php:4833 `return response()->json(`; app/Http/Controllers/Emar/EmarController.php:4846 `return redirect()->back()->with('success', 'Controlled drug balance check recorded.');`; app/Http/Controllers/Emar/EmarController.php:4872 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:4574 `return response()->json($cached);`; app/Http/Controllers/Emar/EmarController.php:4608 `return response()->json(`; app/Http/Controllers/Emar/EmarController.php:4675 `return response()->json(`; app/Http/Controllers/Emar/EmarController.php:4688 `return redirect()->back()->with('success', 'Controlled drug entry recorded.');`; app/Http/Controllers/Emar/EmarController.php:2781 `return Inertia::render('emar/Destructions', [`; app/Http/Controllers/Emar/EmarController.php:3870 `return redirect()->back()->withErrors(['witness_1_id' => 'Witness must be a different person from the person destroying the medication.']);`; app/Http/Controllers/Emar/EmarController.php:3874 `return redirect()->back()->withErrors(['witness_2_id' => 'The second witness must be a different person from the person destroying the medication.']);`; app/Http/Controllers/Emar/EmarController.php:3877 `return redirect()->back()->withErrors(['witness_2_id' => 'The second witness must be a different person from the first witness.']);`; app/Http/Controllers/Emar/EmarController.php:3933 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:4970 `return redirect()->back()->withErrors(['void_reason' => 'This destruction record has already been voided.']);`; app/Http/Controllers/Emar/EmarController.php:4979 `return redirect()->back()->with('success', 'Destruction record voided.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD emar/controlled` — `emar.controlled` — `App\Http\Controllers\Emar\EmarController@controlled` — `app/Http/Controllers/Emar/EmarController.php:1452` — middleware `web, auth, permission:medications.view`
- `POST emar/controlled/balance-check` — `emar.controlled.balance_check.store` — `App\Http\Controllers\Emar\EmarController@storeBalanceCheck` — `app/Http/Controllers/Emar/EmarController.php:4691` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/controlled/discrepancies/{discrepancy}/resolve` — `emar.controlled.discrepancies.resolve` — `App\Http\Controllers\Emar\EmarController@resolveDiscrepancy` — `app/Http/Controllers/Emar/EmarController.php:4849` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/controlled/entries` — `emar.controlled.entries.store` — `App\Http\Controllers\Emar\EmarController@storeCDEntry` — `app/Http/Controllers/Emar/EmarController.php:4545` — middleware `web, auth, permission:medications.orders.manage`
- `GET|HEAD emar/destructions` — `emar.destructions` — `App\Http\Controllers\Emar\EmarController@destructions` — `app/Http/Controllers/Emar/EmarController.php:2749` — middleware `web, auth, permission:medications.view`
- `POST emar/destructions` — `emar.destructions.store` — `App\Http\Controllers\Emar\EmarController@storeDestruction` — `app/Http/Controllers/Emar/EmarController.php:3835` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/destructions/{destruction}/void` — `emar.destructions.void` — `App\Http\Controllers\Emar\EmarController@voidDestruction` — `app/Http/Controllers/Emar/EmarController.php:4963` — middleware `web, auth, permission:medications.orders.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Emar/EmarController.php`.
- Exact render/action page relationships: `resources/js/pages/emar/ControlledDrugs.tsx`, `resources/js/pages/emar/Destructions.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
