# Consolidated eMAR failure repair manifest

Backend evidence is frozen at `92466adc2dd321db5c3514ffa738db4a379a334b`: 1,911 cases, 1,518 passed, **393 failures in 78 files**. This manifest covers every file in the corrected console failure JSON; it does not infer coverage from the incomplete JUnit output (1,238 serialized cases, 283 detailed failures). No tests were run for this triage.

Evidence: Main integration `storage/logs/emar-backend-console-failures.json`, SHA-256 `E8AB681FFBDC8A6B5614ECA4862EEA12778B2AA5170077BF9A317A07666C4C52`; `storage/logs/emar-consolidated-backend.log`, SHA-256 `6C5D62F029A1B089E63A487CCD0D0E35DE42A86AB1C9CB7475DDF681B2B2F21F`. The corrected JSON recovers all test classes from their failure headers; there are no blank or shared-TestCase attributions. Counts below are failing cases per file, not mutually exclusive root-cause totals.

## Priorities and ownership

1. **P09 report/audit contract owner:** largest clean contract repair group. Old GET audit/report endpoints now redirect, exports use canonical POST transports, and report permission checks are exact. Update requests, component/prop names, and role fixtures to the approved contracts. Preserve direct-object denial, exact export permissions, date/person scope, and clinical contents. A redirect alone is not proof that the destination denies an unauthorized user; check the canonical destination explicitly.
2. **P02 fixture owner (assigned):** all 33 `PersonMedicationCommandsTest` / `PersonMedicationRecordTest` clock failures are New Zealand-frozen fixtures where UTC is expected. Main integrated `5cd993c27`; P02 also owns `ClientMedicationDayTest`, `OrderAllergyEndpointTest`, and new historical regressions. The day-history NULL classification failure requires checking the snapshot producer before calling it solely a fixture problem.
3. **P01/P04/P05 canonical fixture owners:** many 404 and validation failures stop before the behavior under test. Use classified, approved medication orders, canonical person ownership, valid dose slots, and current command payloads, then retain the safety/authorization assertion. A 404 is not automatically evidence that held source must be integrated. Legacy writers deliberately stopped mutating governed orders; move tests to the canonical workflow and keep equivalent immutable history/audit assertions.
4. **P07 accepted controlled read-model owner:** investigate `ControlledProductPayload.php:126`, which calls quantity conversion with a NULL entry value and causes a confirmed 500. Fix or explicitly represent missing evidence without manufacturing a zero balance. Controlled register/write fixtures also need canonical IDs, replay/scan fields and witness requirements. Split accepted reader/fixture work from held schema, dose-evidence linking, witness identity and roster changes.
5. **P11 alerts/Settings owner:** confirmed `NotificationService.php:363` assumes an Eloquent collection and calls `loadMissing` on a support collection when an emergency grant is ended by someone else. Settings page-level `can` replaces shared `can.medications`, causing two navigation payload failures; preserve both scopes. Investigate the notification routing and the 115-versus-110 query budget without dropping recipients or increasing the ceiling blindly.
6. **P05 migration/review fixture owner:** compare freshly loaded before/after records rather than a newly created model's partial raw attributes against a complete database row. Compare all persisted fields, including JSON semantics, rather than removing fields. Separately investigate the backfill retry that leaves cadence override `2` instead of NULL; do not classify that semantic failure as snapshot formatting.
7. **P01/Tasks integration owner:** reconcile the six new providers in the architecture/scope matrices with explicit per-provider visibility and denial expectations. Investigate worker/My Day query regressions and calendar obligations after canonical fixture repair. Preserve budget and access guarantees.
8. **P08a clinical workflow owner:** PRN effect history, review/support/follow-up records, and remaining literal payload contracts. An illegal `not_given` enum fixture needs the appropriate canonical terminal status while preserving the intended clinical situation.

Main already owns stock validation (`prohibited_with` unsupported by the installed validator; seven stock-pack failures) and the adjacent inline MAR Blade directives that fail compilation. Main integrated `358d71bb5` after the frozen run; these remain recorded below pending Main verification. Security already owns actual single-event export scope, the downtime canonical allergy digest, existing paper mutation lock authority, and any confirmed Settings controlled-name disclosure. Route/fixture repairs must not weaken those assertions or duplicate those source repairs.

## Holds

Keep `277d20ba1b` dose-evidence schema/linking, witness identity and staffed-roster changes held. The unknown `client_medication_administration_id` query in `ControlledRegisterService.php:529` overlaps that held proposal; report it to Main rather than importing the migration or changing the authority boundary. Keep `d32897b78` queued/pending-paper/emergency grant authority held. P06 receive/house-lead role grants and P01 unassigned-round recording authority also remain held. Fixture repairs may establish already-approved authority; they may not add a role grant or bypass a held policy.

## Complete backend file ledger

Classes: **C** obsolete canonical route/UI/command contract; **F** invalid or incomplete fixture, UTC/time/schema/snapshot issue; **S** confirmed source defect; **S?** source investigation needed after fixture/contract preconditions; **H** direct overlap with held work. Mixed labels deliberately retain multiple causes in one file. File paths are relative to the repository; `E/` means `tests/Feature/Emar/`.

| File | Failures | Class | Recommended owner | Root cause / constraint |
| --- | ---: | --- | --- | --- |
| E/AuditOmissionsTest.php | 4 | C | P09 | Old audit GET returns 302; use canonical audit payload. |
| E/AuditTrailTest.php | 10 | C | P09 | Old audit route and reader contract. |
| E/ClientMedicationDayTest.php | 1 | F/S? | P02 assigned | Historical replacement snapshot has NULL controlled classification; inspect producer and fixture. |
| E/ClientMedicationReportExportTest.php | 2 | S | Main assigned | MAR Blade parse error, already patched after freeze. |
| E/CompetencyRestrictionRulesTest.php | 1 | F/S? | P01 safety | Qualified/restricted cosigner assertion not reached or not enforced; preserve qualification rule. |
| E/ControlledDoseOverrideTest.php | 4 | C/F/H | P07 + Main hold | Incomplete dose/order fixture; explicit witness requirement; one missing dose-link schema column overlaps held 277. |
| E/ControlledDrugsTest.php | 25 | C/F/S | P07 | UUID/replay/scan/witness contract, old reader props; confirmed NULL quantity reader 500. |
| E/ControlledMedicationReportAuthorizationTest.php | 4 | C/F | P09 | Old middleware, GET export, generic rather than exact report permission. |
| E/ControlledProductTest.php | 2 | F/S? | P07 reader fixtures | Duplicate ledger number fixture; raw snapshot equality needs fresh-before comparison. |
| E/DestructionsTest.php | 16 | C/F | P07 | Required canonical fields, UUIDs, witness fields and retired read route expectations. |
| E/EmarListPersonScopeTest.php | 3 | C/F/S? | P01 + P09 | Old audit route; controlled person list fixture/projection needs valid approved orders. |
| E/EmarReportsTest.php | 5 | C/F | P09 | Generic permission fixture and old component. |
| E/EmergencyAccessLifecycleTest.php | 2 | S/F | P11 notification | Support collection loadMissing error; separate fixture missing reason. No grant-policy change. |
| E/EnhancedMarReplayBindingTest.php | 1 | F | P01 | 404 before replay binding; classified approved order fixture. |
| E/ForgottenWitnessPinTest.php | 17 | C/F/S? | P07 witness fixtures | Mostly 404 before behavior; canonical fixture first, keep PIN/witness safety assertion. No held identity/roster port. |
| E/GuidedRoundOfflineReplayTest.php | 1 | F | P01 | 404 before intended 422 replay assertion. |
| E/HandoverFirstSaveConcurrencyTest.php | 1 | F/S? | P01 handover | Subprocess aborts 404 before concurrency target; establish canonical authority first. |
| E/HandoverMedicationLensTest.php | 5 | C/F | P01 handover | Three 404 writes and changed required payload preconditions. |
| E/MedicationAdministrationOwnershipTest.php | 7 | C/F/S? | P01 authorization | Permission/validation precedence and legacy redirects; retain foreign-person denial. |
| E/MedicationAlertRoutingTest.php | 1 | F/S? | P11 | Expected notification absent; inspect current lifecycle and recipient fixture. |
| E/MedicationAlertSettingsTest.php | 3 | C/S? | P11 | Two configuration ordering expectations; query count 115 versus ceiling 110. |
| E/MedicationAlertsTest.php | 2 | C/F/S? | P11 | Canonical notification URL/content and released alert offer/lifecycle. |
| E/MedicationControlledOrderMutationAuthorizationTest.php | 2 | C | P04 | Retired writer no longer changes governed order; assert canonical denial/history instead. |
| E/MedicationDowntimeTest.php | 5 | F/S?/H | Security assigned + P08a fixture | Hidden evidence expectation, allergy digest and paper-lock authority are Security-owned; one non-given fixture missing. Pending-paper authority stays held. |
| E/MedicationErrorsTest.php | 1 | F | P08a | Fixture tries deleting retained medicine; use discontinued order. |
| E/MedicationFollowupWorkflowTest.php | 1 | F | P08a | Invalid not_given database enum; model intended non-given terminal status. |
| E/MedicationGovernanceAuditTest.php | 2 | C/F | P07 governance | Required outcome/canonical fields fail before audit/foreign-object target. |
| E/MedicationGovernanceAuthorizationTest.php | 31 | C/F/S? | P07 + P09; Security review | UUID/expected-entry/scan preconditions and old middleware; one foreign-object expected 404 got 200 requires source investigation, never blanket contract relabel. |
| E/MedicationGovernanceReaderSurfaceTest.php | 5 | C/F | P09 | Canonical readers, report middleware, exact permission and redirect denial. |
| E/MedicationGovernanceResidualSurfaceTest.php | 4 | C | P09 | Retired reader redirects and export transport middleware. |
| E/MedicationIntegrityAuditTest.php | 3 | C/F | P07 governance | Canonical fixture, quantity and writer payload preconditions. |
| E/MedicationNzCalendarTest.php | 3 | C/F/S? | P04 + P01 calendar | Old added-medicine event expectations versus proposed/checked order lifecycle; preserve NZ date behavior. |
| E/MedicationOrderLifecycleTest.php | 2 | C | P04 | Retired method and canonical redirect status. |
| E/MedicationOrdersWorkflowTest.php | 7 | F/C/S? | P04 | Missing factory/reconciliation fixture, duplicate version uniqueness, signoff authority and last-given projection. |
| E/MedicationOrderVerificationTest.php | 10 | C/F | P04 | Legacy verification redirects and unclassified/unapproved order preconditions. |
| E/MedicationPrescriberOrderAuditVisibilityTest.php | 1 | C | P09 | Old audit route 302. |
| E/MedicationRbacAuthorizationTest.php | 6 | C/F/S? | P07 | Old gates/action source strings and command preconditions; preserve site/object denial and witness evidence. |
| E/MedicationRecoveryIntegrationRegressionTest.php | 5 | F/S?/H | P01/P07 + Main hold | Witness eligibility/classification preconditions, raw correction snapshots, non-given approval; no held witness identity changes. |
| E/MedicationReportCanonicalScopeTest.php | 2 | F/S? | P09 + Security assigned | Exact report permission fixture; actual single-event error scope is Security-owned. |
| E/MedicationReviewCadenceMigrationTest.php | 6 | F/S? | P05 migration | Five raw database snapshot/JSON format mismatches; separately investigate cadence retry override not cleared. |
| E/MedicationReviewWorkflowTest.php | 7 | F/C/S? | P05 | Fresh-before immutable snapshots, booking/version scope fixtures and old audit route. |
| E/MedicationRoundsDemoSeederTest.php | 2 | F/S? | P04 demo fixtures | Seeder creates no eligible canonical approved/classified order records. |
| E/MedicationScopeAuthorizationTest.php | 3 | C/F | P01 authorization | Legacy redirect/preflight and write 404; preserve foreign-object denial. |
| E/MedicationScopeDecisionLockOrderTest.php | 1 | C | P01 locks | Canonical medication row adds lock; assert the complete required order, do not remove lock. |
| E/MedicationsDatabaseTest.php | 1 | C/F | P04 | Retired writer validation expectation. |
| E/MedicationSecondPersonTest.php | 2 | C/F/S? | P07 witness fixtures | Witness credential/qualification expectation; establish eligible actor and canonical command. |
| E/MedicationSupportWorkflowTest.php | 4 | F/C | P08a | Raw snapshot, duplicate generated dose slot, required reached_client/current fields. |
| E/MedicineRuleSettingsTest.php | 1 | C/F/S? | P11 + Security if disclosure | Rule content/scope expectation needs semantic inspection; controlled-name disclosure remains Security-owned. |
| E/OneChartAdministrationSafetyTest.php | 2 | F/C | P01 + P04 | Unapproved dose fixture and deliberately retired direct order mutation. |
| E/OneChartGovernanceWorkflowTest.php | 3 | C/F | P05 | Canonical fixture/permissions and retired direct review mutation. |
| E/OrderAllergyEndpointTest.php | 1 | C/F | P02 assigned | Canonical combined health-profile allergy evidence adds Penicillin; preserve source coverage. |
| E/P01MedsTodayBoardTest.php | 1 | F | P01 | 404 before forbidden action assertion; valid canonical order required. |
| E/P01RecordingContractTest.php | 6 | C/F/S? | P01 | Canonical payload binding and approved fixture before literal validation assertions. |
| E/P11EventIntegrationTest.php | 1 | F | P11 | MySQL JSON key ordering; compare complete decoded event details. |
| E/P11NavigationPayloadTest.php | 2 | S | P11 Settings | Page can payload shadows shared can.medications navigation flags. |
| E/PersonMedicationCommandsTest.php | 20 | F | P02 assigned | NZ freeze versus expected UTC; Main integrated clock fix after frozen run. |
| E/PersonMedicationRecordTest.php | 13 | F | P02 assigned | Same UTC fixture repair. |
| E/PrescriptionsPageTest.php | 12 | C/F | P04 | Old component/props/capabilities and order lifecycle fixture expectations. |
| E/PrnRecordsHistoryTest.php | 1 | F/S? | P08a | Idempotent effect history differs; preserve actor/time/effect evidence and retry guarantee. |
| E/ReviewsTest.php | 8 | C/F | P05 | Canonical component, reason/review fields and permission/redirect expectations. |
| E/RoundDoseSlotsTest.php | 2 | C/F/S? | P01 calendar + P09 | Old report props and canonical active-order/date projection. |
| E/RoundsPagePayloadTest.php | 1 | C | P01 | Canonical round-sheet query transport changes source expectation. |
| E/StockManagementTest.php | 6 | C/F/S? | P06 | Receipt copy/fixture, controlled entry projection and payload preconditions; no receive-role grant. |
| E/StockPacksWorkflowTest.php | 11 | S/C/F/S? | Main assigned + P06 | Seven unsupported validation-rule failures; four remaining scan/payload/count/expiry cases. |
| E/TeamLeadMedicationBaselineTest.php | 1 | H/C? | Main role boundary | Role-key baseline differs; review approved grants explicitly, do not bless held receive/house-lead grant. |
| E/UnifiedMedicationReportsTest.php | 3 | S?/F | Security assigned + P09 fixture | Multi-person ledger export and single-event scope Security-owned; missing changed_by version fixture separate. |
| E/WorkerMedsRecordDoseTest.php | 12 | C/F/S? | P01 | Canonical order/action/witness/reason payload preconditions; preserve replay and foreign-person denial. |
| E/WorkerMedsTodayPayloadTest.php | 1 | S? | P01 performance | Queries 5 versus expected 2; inspect canonical scoped projections, do not increase ceiling without evidence. |
| tests/Feature/FleetAssets/ResidentTransportMedicationTransitTest.php | 1 | C/F | P06 transport | Required packing attestation/current payload; preserve authorized transport fixture. |
| tests/Feature/MedicationControllerTest.php | 32 | C/F | P04/P07/P09 | Broad old-reader/direct-writer/success expectations; split canonical routes, order writes and controlled commands. |
| tests/Feature/MedicationsApiControllerTest.php | 1 | F | P01 | Unclassified/unapproved legacy order returns 404 before intended 422. |
| tests/Feature/MedicationsSafetyOverrideAuthorizationTest.php | 4 | F/S? | P01 safety | 404 before override targets; establish approved scoped order, keep override denial. |
| tests/Feature/MyDayMedicationActionTest.php | 2 | F | P01 | Canonical medication/action fixture 404. |
| tests/Feature/MyDayMedicationsDuePayloadTest.php | 1 | S? | P01 performance | Queries 2 versus expected 1; investigate scoped provider batching. |
| tests/Feature/Sites/Calendar/MedicationCalendarObligationTest.php | 2 | F/S? | P01 calendar | Empty obligation projection; establish checked/classified active order before source diagnosis. |
| tests/Feature/Tasks/TaskProviderAuthorizationArchitectureTest.php | 1 | C | Tasks integration | Six new providers absent from matrix; add explicit authorization expectations. |
| tests/Feature/Tasks/TaskProviderRowScopeTest.php | 2 | C/F/S? | Tasks integration | New-provider scope matrix and visibility/dedup count; preserve foreign-person/site denial. |
| tests/Unit/MedicationSafetyServiceTest.php | 6 | F | P01 unit harness | Laravel config requested in non-bootstrapped unit test; boot the test harness, retain production clock logic. |

## UI contract candidate (assigned to this chat)

Main's `a413e576f` UI run: 56 suites passed, three failed; 393 cases passed, five failed. Evidence is `storage/logs/emar-consolidated-ui.json`. New header/mobile/Settings renderer regressions passed. These are separate from the 393 backend failures above.

| File under resources/js/pages/emar/ | Failures | Class | Repair requirement |
| --- | ---: | --- | --- |
| controlled-dialog-replay-contracts.test.ts | 2 | C | Follow guided delegation into RecordDoseDialog; retain hundredth-unit validation and stable UUID retries across every recording surface. |
| medication-governance-request-contracts.test.ts | 1 | C | Inspect the canonical classification policy for controlled syringe-driver witness requirements; retain positive and negative policy guarantees. |
| prescription-governance-contracts.test.ts | 2 | C | Follow moved MedicationOrdersController transitions; preserve server-bound classification/covert overlap and fatal audit failures. |

Prepare only equivalent contract assertions on the latest Main base. Any Safety overview contract adjustment must track the accepted header/reader behavior. Main owns all heavy test and production-build verification. Classification here is triage, not a claim that every candidate repair has passed verification.

Candidate prepared on verified Main base `d51d5e9dad5c75709fa9df358be037cb0232fffd`, branch `codex/emar-ui-contracts-latest-20261004`. It changes only the three UI test files above: guided quantity/retry checks follow the shared recorder, witness checks cover authoritative true/false policy flags and explicit-order fallback, and covert checks follow the canonical scoped transactional workflow with fatal audits. Existing clinical and authorization assertions remain. No Safety overview test failed in the supplied run, and no additional adjustment was needed for its accepted header change. Scoped ESLint, Prettier and whitespace checks pass; UI/backend execution is queued for Main only.

## P11 backend repair candidates prepared on 4 October 2026

P11 used a fresh branch at 50ee59735aa91d6ef2229304839effd9a771040a and mapped all eleven frozen failures across EmergencyAccessLifecycleTest (2), MedicationAlertRoutingTest (1), MedicationAlertSettingsTest (3), MedicationAlertsTest (2), P11EventIntegrationTest (1) and P11NavigationPayloadTest (2). Details, constraints and the exact Main-owned verification command are in [P11 backend repairs](../emar-p11-completion/backend-regressions.md). The historical 393 failures / 78 files counts above are unchanged.

Prepared source: f65be7c3a38617cad2add3538be35235bc5508d8 normalises explicit notification recipients while retaining role/user preferences; b053f76e271b749bc8299acf87f6e0888a663ab8 scopes page permissions to settingsCan and removes five duplicate Settings reads. The companion alert/test commit includes released report fixtures, missing preview coverage and complete strict JSON object comparisons. Navigation and site/auditor denials remain; no role/grant authority, held expiry behavior, MedicineRuleSettingsTest, downtime or export classes were changed.

PHP/TSX syntax and whitespace checks pass. No backend/frontend suite, build, install or heavy queue was launched. Main must verify current routing and both unchanged query ceilings; the budget assertion now supplies SQL frequency counts if it still fails. This is candidate repair status, not a claim that the eleven cases passed.

## Correction — 4 October, 05:28 NZDT

The P11 page-can shadowing hypothesis above was disproved: shared navigation is auth.can.medications. Renaming settingsCan clarifies ownership; current permission fixtures and duplicate Settings reads were separately repaired. Both unchanged query ceilings and navigation cases subsequently passed. See CURRENT-STATUS.md for authoritative latest results; original393/78 is the historical baseline, not the remaining failure count.
