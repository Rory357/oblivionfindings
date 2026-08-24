# CAP-MED-EMAR-SELF-ADMINISTRATION: Self-administration plans

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.orders.manage`
- Owning module: eMAR and medications
- Legacy family: `MED-EMAR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `emar/self-admin` (`emar.self_admin`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.orders.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.view`, `permission:medications.orders.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD emar/self-admin` (`emar.self_admin`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST emar/self-admin` (`emar.self_admin.store`, action `storeSelfAdmin`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/EmarController.php:3706-3762`; `client_id`, `wishes_to_self_administer`, `people_involved`, `cognitive_capacity`, `physical_dexterity`, `vision_ability`, `swallowing_ability`, `understanding_score`, `can_identify_medications`, `can_read_labels`, `can_open_packaging`, `can_manage_timing`, `can_store_safely`, `willing_to_self_admin`, `risk_factors`, `support_needed`, `support_adjustments`, `safe_storage_notes`, `storage_location`, `assessor_notes`, `reassessment_date`, `reassessment_interval_months`, `reassessment_trigger`, `supersedes_id`.
3. Invoke only the owning control for `DELETE emar/self-admin/{assessment}` (`emar.self_admin.destroy`, action `destroySelfAdmin`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Emar/EmarController.php:3824-3831`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT emar/self-admin/{assessment}` (`emar.self_admin.update`, action `updateSelfAdmin`). Source category: **updated/revised**; controller `app/Http/Controllers/Emar/EmarController.php:3764-3822`; `wishes_to_self_administer`, `people_involved`, `cognitive_capacity`, `physical_dexterity`, `vision_ability`, `swallowing_ability`, `understanding_score`, `can_identify_medications`, `can_read_labels`, `can_open_packaging`, `can_manage_timing`, `can_store_safely`, `willing_to_self_admin`, `risk_factors`, `support_needed`, `support_adjustments`, `safe_storage_notes`, `storage_location`, `assessor_notes`, `reassessment_date`, `reassessment_interval_months`, `reassessment_trigger`, `med_scope`, `ordering_responsibility`, `agreement_responsibilities`, `sign_agreement`.

## Source-applicable states and transitions

- **information presented** is applicable only to `selfAdmin` / `ROUTE-0428` at `app/Http/Controllers/Emar/EmarController.php:2624`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeSelfAdmin` / `ROUTE-0429` at `app/Http/Controllers/Emar/EmarController.php:3706`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroySelfAdmin` / `ROUTE-0430` at `app/Http/Controllers/Emar/EmarController.php:3824`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateSelfAdmin` / `ROUTE-0431` at `app/Http/Controllers/Emar/EmarController.php:3764`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/emar/SelfAdmin.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0429` / `storeSelfAdmin`: fields `client_id`, `wishes_to_self_administer`, `people_involved`, `cognitive_capacity`, `physical_dexterity`, `vision_ability`, `swallowing_ability`, `understanding_score`, `can_identify_medications`, `can_read_labels`, `can_open_packaging`, `can_manage_timing`, `can_store_safely`, `willing_to_self_admin`, `risk_factors`, `support_needed`, `support_adjustments`, `safe_storage_notes`, `storage_location`, `assessor_notes`, `reassessment_date`, `reassessment_interval_months`, `reassessment_trigger`, `supersedes_id`.
- `ROUTE-0431` / `updateSelfAdmin`: fields `wishes_to_self_administer`, `people_involved`, `cognitive_capacity`, `physical_dexterity`, `vision_ability`, `swallowing_ability`, `understanding_score`, `can_identify_medications`, `can_read_labels`, `can_open_packaging`, `can_manage_timing`, `can_store_safely`, `willing_to_self_admin`, `risk_factors`, `support_needed`, `support_adjustments`, `safe_storage_notes`, `storage_location`, `assessor_notes`, `reassessment_date`, `reassessment_interval_months`, `reassessment_trigger`, `med_scope`, `ordering_responsibility`, `agreement_responsibilities`, `sign_agreement`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Emar/EmarController.php:3759 `MedicationSelfAdminAssessment::create($validated);`; app/Http/Controllers/Emar/EmarController.php:3828 `$assessment->delete();`; app/Http/Controllers/Emar/EmarController.php:3819 `$assessment->update($validated);`; responses app/Http/Controllers/Emar/EmarController.php:2665 `return $events;`; app/Http/Controllers/Emar/EmarController.php:2670 `return Inertia::render('emar/SelfAdmin', [`; app/Http/Controllers/Emar/EmarController.php:3761 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:3830 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:3821 `return redirect()->back();`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD emar/self-admin` — `emar.self_admin` — `App\Http\Controllers\Emar\EmarController@selfAdmin` — `app/Http/Controllers/Emar/EmarController.php:2624` — middleware `web, auth, permission:medications.view`
- `POST emar/self-admin` — `emar.self_admin.store` — `App\Http\Controllers\Emar\EmarController@storeSelfAdmin` — `app/Http/Controllers/Emar/EmarController.php:3706` — middleware `web, auth, permission:medications.orders.manage`
- `DELETE emar/self-admin/{assessment}` — `emar.self_admin.destroy` — `App\Http\Controllers\Emar\EmarController@destroySelfAdmin` — `app/Http/Controllers/Emar/EmarController.php:3824` — middleware `web, auth, permission:medications.orders.manage`
- `PUT emar/self-admin/{assessment}` — `emar.self_admin.update` — `App\Http\Controllers\Emar\EmarController@updateSelfAdmin` — `app/Http/Controllers/Emar/EmarController.php:3764` — middleware `web, auth, permission:medications.orders.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Emar/EmarController.php`.
- Exact render/action page relationships: `resources/js/pages/emar/SelfAdmin.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
