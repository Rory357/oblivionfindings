# CAP-MED-EMAR-COMPETENCY: Medication competency records

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.orders.manage`
- Owning module: eMAR and medications
- Legacy family: `MED-EMAR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `emar/competency` (`emar.competency`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.orders.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.view`, `permission:medications.orders.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD emar/competency` (`emar.competency`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST emar/competency` (`emar.competency.store`, action `storeCompetency`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/EmarController.php:3439-3498`; `user_id`, `assessment_type`, `assessment_date`, `expiry_date`, `medication_knowledge`, `five_rights`, `safety_checks`, `documentation`, `controlled_drugs`, `prn_assessment`, `insulin_competent`, `inhaler_competent`, `topical_competent`, `covert_admin_knowledge`, `error_reporting`, `allergy_awareness`, `strengths`, `areas_for_improvement`, `action_plan`, `assessor_comments`, `observed_rounds`, `not_seen_areas`, `restricted`, `restriction_notes`, `can_administer_unsupervised`, `can_witness_controlled`, `assessor_declared`, `staff_acknowledged`.
3. Invoke only the owning control for `DELETE emar/competency/{assessment}` (`emar.competency.destroy`, action `destroyCompetency`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Emar/EmarController.php:3566-3571`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT emar/competency/{assessment}` (`emar.competency.update`, action `updateCompetency`). Source category: **updated/revised**; controller `app/Http/Controllers/Emar/EmarController.php:3500-3564`; `user_id`, `assessment_type`, `assessment_date`, `medication_knowledge`, `five_rights`, `safety_checks`, `documentation`, `controlled_drugs`, `prn_assessment`, `insulin_competent`, `inhaler_competent`, `topical_competent`, `covert_admin_knowledge`, `error_reporting`, `allergy_awareness`, `strengths`, `areas_for_improvement`, `action_plan`, `assessor_comments`, `observed_rounds`, `not_seen_areas`, `restricted`, `restriction_notes`, `expiry_date`, `can_administer_unsupervised`, `can_witness_controlled`, `assessor_declared`, `staff_acknowledged`.

## Source-applicable states and transitions

- **information presented** is applicable only to `competency` / `ROUTE-0347` at `app/Http/Controllers/Emar/EmarController.php:2261`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeCompetency` / `ROUTE-0348` at `app/Http/Controllers/Emar/EmarController.php:3439`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyCompetency` / `ROUTE-0349` at `app/Http/Controllers/Emar/EmarController.php:3566`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateCompetency` / `ROUTE-0350` at `app/Http/Controllers/Emar/EmarController.php:3500`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/emar/Competency.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0348` / `storeCompetency`: fields `user_id`, `assessment_type`, `assessment_date`, `expiry_date`, `medication_knowledge`, `five_rights`, `safety_checks`, `documentation`, `controlled_drugs`, `prn_assessment`, `insulin_competent`, `inhaler_competent`, `topical_competent`, `covert_admin_knowledge`, `error_reporting`, `allergy_awareness`, `strengths`, `areas_for_improvement`, `action_plan`, `assessor_comments`, `observed_rounds`, `not_seen_areas`, `restricted`, `restriction_notes`, `can_administer_unsupervised`, `can_witness_controlled`, `assessor_declared`, `staff_acknowledged`.
- `ROUTE-0350` / `updateCompetency`: fields `user_id`, `assessment_type`, `assessment_date`, `medication_knowledge`, `five_rights`, `safety_checks`, `documentation`, `controlled_drugs`, `prn_assessment`, `insulin_competent`, `inhaler_competent`, `topical_competent`, `covert_admin_knowledge`, `error_reporting`, `allergy_awareness`, `strengths`, `areas_for_improvement`, `action_plan`, `assessor_comments`, `observed_rounds`, `not_seen_areas`, `restricted`, `restriction_notes`, `expiry_date`, `can_administer_unsupervised`, `can_witness_controlled`, `assessor_declared`, `staff_acknowledged`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Emar/EmarController.php:3495 `MedicationCompetencyAssessment::create($validated);`; app/Http/Controllers/Emar/EmarController.php:3568 `$assessment->delete();`; app/Http/Controllers/Emar/EmarController.php:3561 `$assessment->update($validated);`; responses app/Http/Controllers/Emar/EmarController.php:2290 `return Inertia::render('emar/Competency', [`; app/Http/Controllers/Emar/EmarController.php:3497 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:3570 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:3563 `return redirect()->back();`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD emar/competency` — `emar.competency` — `App\Http\Controllers\Emar\EmarController@competency` — `app/Http/Controllers/Emar/EmarController.php:2261` — middleware `web, auth, permission:medications.view`
- `POST emar/competency` — `emar.competency.store` — `App\Http\Controllers\Emar\EmarController@storeCompetency` — `app/Http/Controllers/Emar/EmarController.php:3439` — middleware `web, auth, permission:medications.orders.manage`
- `DELETE emar/competency/{assessment}` — `emar.competency.destroy` — `App\Http\Controllers\Emar\EmarController@destroyCompetency` — `app/Http/Controllers/Emar/EmarController.php:3566` — middleware `web, auth, permission:medications.orders.manage`
- `PUT emar/competency/{assessment}` — `emar.competency.update` — `App\Http\Controllers\Emar\EmarController@updateCompetency` — `app/Http/Controllers/Emar/EmarController.php:3500` — middleware `web, auth, permission:medications.orders.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Emar/EmarController.php`.
- Exact render/action page relationships: `resources/js/pages/emar/Competency.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
