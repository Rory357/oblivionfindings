# P07 sixth-batch fixture follow-up — 4 October 2026

Base: `e734a69b61d96436d06fed8577e8b5cffb32d8cc`, Main's sixth-batch checkout. Prepared on `codex/emar-p07-sixth-repair-20261004`; the earlier candidate branch is preserved. Main reports 450 passed / 25 failed. P07 read `storage/app/emar-sixth-failures.json` without launching another suite.

## Diagnosed failures

The supplied JSON contains ten P07 `MedicationControllerTest` failures: two controlled administrations, six guided counts and two discrepancy resolutions. All returned 404. They share the `createWitness` fixture, whose assessment sets `can_witness_controlled` but leaves `controlled_drugs` false. The existing `ControlledMedicationTransportWitnessService::isValidWitnessAssessment` rejects that incomplete endorsement before checking the PIN. The self-witness and ineligible-witness negative cases already reach their intended rejection and remain unchanged.

The candidate adds a uniquely named `p07ControlledWitness` helper. It retains the existing role, permission, site, assignment, employment, assessment, PIN and active shift fixture, then explicitly supplies the existing controlled competency endorsement and unrestricted assessment fields. Only P07's eight directly affected methods and its resolution fixture use it. The shared `createWitness`, `qualifyControlledWitness`, setup, P09 readers, Main's break-glass/lifecycle methods and production witness service are unchanged.

`OneChartAdministrationSafetyTest::test_witness_must_authenticate_before_controlled_drug_is_recorded` already has an eligible controlled witness. Its valid-PIN request reaches the stock writer and returns 422 because it supplies only a dose label in mg while its stock unit is tablets and the order has no comparable numeric dose amount/unit. `RecordingContractEnforcer::controlledStockUse` requires an explicit stock quantity in that case. All three requests now state `quantity_administered = 1`; missing/wrong PIN requests must still fail before any stock or ledger write. The accepted request must record the authenticated witness, reduce stock from 10 to 9 and create one register entry. No default quantity or production guard was added.

This is fixture repair based on current source and the supplied failures, not a new passing-test claim. PHP 8.4 syntax and scoped whitespace checks passed. Main owns the next runtime check; P07 ran no suite, build, install, browser or queue.

## Earlier candidate status

- `bc03e0c35a2a7b076b036db1ba822f3703c198cb`: canonical governance/audit fixtures, four test files; Main reports integrated.
- `9af304b972d5fb0ec939f7a491c7821ea2279af7`: witness/recovery/ownership fixtures plus one Fleet transit method, six test files; Main reports integrated.
- **Held, not applied by Main:** `1033a1f0dd08d0e811160ff9331a57402fe91c95`. Main's automatic approval review rejected the combined controlled/destruction test rewrite and production changes: substantial production changes, about 1,600 deleted test lines, potentially weakened or misleading coverage, retained unresolved assertions and insufficiently narrow review. Do not retry this commit or an equivalent change through another path. It is preserved only on `codex/emar-p07-governance-repair-20261004` for inspection.

The prior residual ledger is in `p07-bounded-governance-repairs.md` within the held `1033a1f0d`; its former ready/cherry-pick status is superseded by this hold. This new branch contains only the narrow sixth-batch fixtures and does not reapply that source group or test rewrite.

Main identified a concrete coverage gap: the proposed replacement for `ControlledDrugsTest::test_date_param_scopes_movements_window` creates one old row without testing the requested date filter. Keep the original test and flag the unresolved date-filter contract. Main may independently review a separate, smaller reader/privacy candidate with additive focused tests and no replacement of `ControlledDrugsTest` or `DestructionsTest`; P07 has not applied or prepared an equivalent retry here.

## Precise remaining source and hold boundaries

- The unknown signoff query `ControlledRegisterService::override` / `client_medication_administration_id` and `ControlledDoseOverrideTest::test_override_dose_signoff_requires_a_post_dose_participant_count_and_closes_source_once` still overlap held `277d20ba1b` schema/linking. No migration or query bypass.
- Current-witness-before-cache replay remains unchanged. Targets include `DestructionsTest::test_destruction_replay_rechecks_current_witness_authority_before_returning_success`, the governance entry/count payload replay cases and RBAC manual controlled replay.
- Destruction administrative void versus current stock reversal remains asserted in `DestructionsTest::test_void_is_administrative_only_and_records_explicit_reconciliation_provenance`; no stock algorithm change.
- Ordinary destruction, immutable historical classification maintenance and parent/site concealment before clinical validation remain for contract/source review: `test_ordinary_destruction_locks_current_site_witness_and_records_truthful_method`, `test_void_uses_immutable_classification_and_retains_soft_deleted_medication_link`, and `test_new_destruction_requires_a_canonical_link_and_conceals_site_mismatch_before_clinical_validation`.
- `ControlledDrugsTest::test_overdue_cd_check_command_raises_then_balance_check_resolves_alert` still targets count-driven alert resolution and configured cadence rather than the command's fixed day cutoff; coordinate with P11.
- Raw `1` versus `'1.00'` fingerprint differences and legacy ignored parent/name fields remain unresolved. P09's reader/sidebar/export bodies remain with P09; an ignored legacy `client_id` filter alone does not prove a foreign row leak.
- Controlled receipt/pack adapters, receive/house-lead grants, held witness identity/roster scope, waste/PRN dose links, queued/pending-paper/emergency authority (`d32897b78`) and deleted/superseded order recording remain untouched. `StockManagementTest`'s six controlled delivery failures and the Team Lead role baseline are outside these candidates.

No production file or role grant changed in this sixth-batch follow-up. The single-organisation, approved-site and canonical person ownership boundaries remain intact.
