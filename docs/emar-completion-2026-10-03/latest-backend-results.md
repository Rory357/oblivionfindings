# Latest backend regression ledger

These are latest results per originally failing file, from overlapping follow-up batches. They are not a new complete-suite run and must not be added to earlier totals.

| File | Latest batch | Cases | Failures/errors |
| --- | --- | ---: | ---: |
| tests/Feature/Emar/MedicationAdministrationOwnershipTest.php | seventh-repair | 22 | 0 |
| tests/Feature/Emar/MedicationAlertSettingsTest.php | fourth-repair | 22 | 0 |
| tests/Feature/Emar/MedicationAlertsTest.php | fourth-repair | 41 | 0 |
| tests/Feature/Emar/EmarListPersonScopeTest.php | eighth-repair | 8 | 0 |
| tests/Feature/Emar/MedicationRbacAuthorizationTest.php | eighth-repair | 28 | 1 |
| tests/Feature/Emar/EmergencyAccessLifecycleTest.php | fourth-repair | 22 | 0 |
| tests/Feature/Emar/MedicationOrderVerificationTest.php | fifth-repair | 16 | 0 |
| tests/Feature/Emar/TeamLeadMedicationBaselineTest.php | eighth-repair | 4 | 0 |
| tests/Feature/Emar/GuidedRoundOfflineReplayTest.php | sixth-repair | 10 | 0 |
| tests/Feature/Emar/ForgottenWitnessPinTest.php | seventh-repair | 20 | 17 |
| tests/Feature/Emar/MedicationAlertRoutingTest.php | fifth-repair | 11 | 0 |
| tests/Feature/MedicationsApiControllerTest.php | sixth-repair | 15 | 0 |
| tests/Feature/Emar/ControlledDoseOverrideTest.php | eighth-repair | 15 | 2 |
| tests/Feature/Emar/MedicationScopeAuthorizationTest.php | seventh-repair | 11 | 0 |
| tests/Feature/Emar/MedicationReportCanonicalScopeTest.php | seventh-repair | 7 | 0 |
| tests/Feature/MedicationControllerTest.php | seventh-repair | 167 | 0 |
| tests/Feature/Emar/ClientMedicationDayTest.php | second-repair | 15 | 0 |
| tests/Feature/Emar/P01RecordingContractTest.php | sixth-repair | 29 | 0 |
| tests/Feature/Emar/MedicineRuleSettingsTest.php | first-repair | 7 | 0 |
| tests/Feature/Emar/MedicationErrorsTest.php | third-repair | 31 | 0 |
| tests/Feature/Emar/RoundsPagePayloadTest.php | sixth-repair | 6 | 0 |
| tests/Feature/Emar/PrnRecordsHistoryTest.php | third-repair | 4 | 0 |
| tests/Feature/Emar/PersonMedicationRecordTest.php | first-repair | 13 | 0 |
| tests/Feature/Emar/P01MedsTodayBoardTest.php | eighth-repair | 13 | 0 |
| tests/Feature/MyDayMedicationActionTest.php | sixth-repair | 14 | 0 |
| tests/Feature/Emar/MedicationSupportWorkflowTest.php | fourth-repair | 28 | 0 |
| tests/Feature/MedicationsSafetyOverrideAuthorizationTest.php | sixth-repair | 9 | 0 |
| tests/Feature/Emar/PersonMedicationCommandsTest.php | second-repair | 20 | 0 |
| tests/Feature/Emar/ReviewsTest.php | second-repair | 10 | 0 |
| tests/Feature/Emar/MedicationDowntimeTest.php | second-repair | 49 | 0 |
| tests/Feature/Emar/UnifiedMedicationReportsTest.php | sixth-repair | 20 | 0 |
| tests/Feature/Emar/OneChartAdministrationSafetyTest.php | seventh-repair | 7 | 0 |
| tests/Feature/Emar/MedicationGovernanceReaderSurfaceTest.php | sixth-repair | 7 | 0 |
| tests/Feature/Emar/ClientMedicationReportExportTest.php | first-repair | 3 | 0 |
| tests/Feature/Emar/ControlledProductTest.php | eighth-repair | 31 | 0 |
| tests/Feature/Emar/OneChartGovernanceWorkflowTest.php | second-repair | 6 | 0 |
| tests/Feature/Emar/MedicationScopeDecisionLockOrderTest.php | sixth-repair | 6 | 0 |
| tests/Unit/MedicationSafetyServiceTest.php | second-repair | 9 | 0 |
| tests/Feature/Emar/MedicationFollowupWorkflowTest.php | third-repair | 45 | 0 |
| tests/Feature/Emar/MedicationsDatabaseTest.php | fifth-repair | 3 | 0 |
| tests/Feature/Emar/RoundDoseSlotsTest.php | sixth-repair | 7 | 0 |
| tests/Feature/Emar/MedicationOrderLifecycleTest.php | eighth-repair | 17 | 0 |
| tests/Feature/Emar/P11EventIntegrationTest.php | fourth-repair | 5 | 0 |
| tests/Feature/MyDayMedicationsDuePayloadTest.php | eighth-repair | 7 | 0 |
| tests/Feature/Tasks/TaskProviderRowScopeTest.php | ninth-repair | 5 | 0 |
| tests/Feature/Emar/MedicationReviewCadenceMigrationTest.php | second-repair | 8 | 0 |
| tests/Feature/Emar/MedicationRecoveryIntegrationRegressionTest.php | eighth-repair | 8 | 0 |
| tests/Feature/Emar/P11NavigationPayloadTest.php | fifth-repair | 4 | 0 |
| tests/Feature/Emar/AuditTrailTest.php | eighth-repair | 12 | 0 |
| tests/Feature/Emar/MedicationControlledOrderMutationAuthorizationTest.php | fifth-repair | 3 | 0 |
| tests/Feature/Emar/EnhancedMarReplayBindingTest.php | sixth-repair | 12 | 0 |
| tests/Feature/Emar/MedicationSecondPersonTest.php | seventh-repair | 11 | 0 |
| tests/Feature/Sites/Calendar/MedicationCalendarObligationTest.php | sixth-repair | 4 | 0 |
| tests/Feature/Tasks/TaskProviderAuthorizationArchitectureTest.php | sixth-repair | 5 | 0 |
| tests/Feature/Emar/HandoverFirstSaveConcurrencyTest.php | sixth-repair | 1 | 0 |
| tests/Feature/FleetAssets/ResidentTransportMedicationTransitTest.php | eighth-repair | 9 | 0 |
| tests/Feature/Emar/MedicationReviewWorkflowTest.php | second-repair | 34 | 0 |
| tests/Feature/Emar/MedicationGovernanceAuditTest.php | seventh-repair | 6 | 0 |
| tests/Feature/Emar/MedicationIntegrityAuditTest.php | seventh-repair | 8 | 0 |
| tests/Feature/Emar/DestructionsTest.php | eighth-repair | 16 | 14 |
| tests/Feature/Emar/WorkerMedsTodayPayloadTest.php | ninth-repair | 3 | 0 |
| tests/Feature/Emar/PrescriptionsPageTest.php | seventh-repair | 23 | 0 |
| tests/Feature/Emar/MedicationGovernanceResidualSurfaceTest.php | fifth-repair | 4 | 0 |
| tests/Feature/Emar/WorkerMedsRecordDoseTest.php | eighth-repair | 30 | 0 |
| tests/Feature/Emar/MedicationNzCalendarTest.php | fifth-repair | 55 | 0 |
| tests/Feature/Emar/ControlledMedicationReportAuthorizationTest.php | fifth-repair | 5 | 0 |
| tests/Feature/Emar/MedicationGovernanceAuthorizationTest.php | ninth-repair | 41 | 6 |
| tests/Feature/Emar/EmarReportsTest.php | fifth-repair | 5 | 0 |
| tests/Feature/Emar/ControlledDrugsTest.php | ninth-repair | 27 | 16 |
| tests/Feature/Emar/MedicationPrescriberOrderAuditVisibilityTest.php | fifth-repair | 1 | 0 |
| tests/Feature/Emar/CompetencyRestrictionRulesTest.php | sixth-repair | 7 | 0 |
| tests/Feature/Emar/MedicationRoundsDemoSeederTest.php | fifth-repair | 3 | 0 |
| tests/Feature/Emar/StockPacksWorkflowTest.php | first-repair | 27 | 0 |
| tests/Feature/Emar/OrderAllergyEndpointTest.php | first-repair | 2 | 0 |
| tests/Feature/Emar/AuditOmissionsTest.php | sixth-repair | 5 | 0 |
| tests/Feature/Emar/StockManagementTest.php | seventh-repair | 25 | 6 |
| tests/Feature/Emar/HandoverMedicationLensTest.php | sixth-repair | 22 | 0 |
| tests/Feature/Emar/MedicationOrdersWorkflowTest.php | third-repair | 31 | 0 |

## Remaining cases

Each case below remains unresolved at its recorded revision. See CURRENT-STATUS.md and p07-seventh-residual-dispositions.md for approval holds and clinical-contract limitations. No case is silently skipped.

### tests/Feature/Emar/ControlledDoseOverrideTest.php

- Active dose override never bypasses witnessed register counts — eighth-repair.
- Override dose signoff requires a post dose participant count and closes source once — eighth-repair.

### tests/Feature/Emar/ControlledDrugsTest.php

- Cd witness confirms with their witness pin not their login password — ninth-repair.
- Manual controlled entry rejects incomplete or contradictory offline provenance — ninth-repair.
- Manual controlled balance check rejects incomplete or contradictory offline provenance — ninth-repair.
- Manual controlled entry and balance accept online idempotency uuids without provenance — ninth-repair.
- Controlled inertia forms redirect while json replays return sync payloads — ninth-repair.
- Controlled entry and balance check replays remain durable after pruning — ninth-repair.
- Controlled offline entry and balance audit failures roll back receipts stock and replay bindings — ninth-repair.
- Page serves brand colour — ninth-repair.
- Loss report captures accountable officer and regulator — ninth-repair.
- Loss report rejects incomplete or contradictory offline provenance — ninth-repair.
- Loss report replay is bound to authority target and report semantics — ninth-repair.
- Loss report and durable replay result commit atomically — ninth-repair.
- Loss report audit failure rolls back report incident and replay binding — ninth-repair.
- Controlled loss mutations require canonical local ownership — ninth-repair.
- Overdue cd check command raises then balance check resolves alert — ninth-repair.
- Cd entry classifies schedule on medication — ninth-repair.

### tests/Feature/Emar/DestructionsTest.php

- Void marks record voided not deleted — eighth-repair.
- Void is administrative only and records explicit reconciliation provenance — eighth-repair.
- Void requires a reason — eighth-repair.
- Destruction rejects incomplete or contradictory offline provenance — eighth-repair.
- Destruction replay is single effect and changed payload conflicts — eighth-repair.
- Destruction replay rechecks current witness authority before returning success — eighth-repair.
- Destruction replay key is durable and conflicts when the target changes — eighth-repair.
- New destruction requires a canonical link and conceals site mismatch before clinical validation — eighth-repair.
- Ordinary destruction locks current site witness and records truthful method — eighth-repair.
- Void uses immutable classification and retains soft deleted medication link — eighth-repair.
- Record only actor cannot probe store or void a controlled destruction — eighth-repair.
- Non controlled destruction conceals noncanonical witness before insert — eighth-repair.
- Page serves brand colour and payload — eighth-repair.
- Payload carries detail fields for voided cd record — eighth-repair.

### tests/Feature/Emar/ForgottenWitnessPinTest.php

- A named colleague confirms in their own login and replay does not duplicate — seventh-repair.
- A dispute flags one review on the original dose — seventh-repair.
- Expiry at the exact deadline is durable and idempotent — seventh-repair.
- A late answer commits expiry before returning the expired result — seventh-repair.
- Fallback cannot bypass locked reset expired unset or actor attempt budgets — seventh-repair.
- Only the nominee can answer and current person and staff authority still apply — seventh-repair.
- A revoked permission or departed employee cannot confirm — seventh-repair.
- As needed recording keeps the fallback field — seventh-repair.
- A missing protected pin key is not a fallback or an attempt — seventh-repair.
- Audit chain failure rolls back the dose nomination receipt bell and followup — seventh-repair.
- Expiry resolves the canonical workflow for a deleted person without exposing it — seventh-repair.
- The source http consumer only answers in the named colleagues login — seventh-repair.
- Source http reads and answers recheck current person and witness authority — seventh-repair.
- The scheduled expiry command runs at the exact deadline and replays safely — seventh-repair.
- Terminal attestation preserves an existing clinical review with data set "confirmed" — seventh-repair.
- Terminal attestation preserves an existing clinical review with data set "disputed" — seventh-repair.
- Terminal attestation preserves an existing clinical review with data set "expired" — seventh-repair.

### tests/Feature/Emar/MedicationGovernanceAuthorizationTest.php

- Explicit global site role still requires and honours each exact capability — ninth-repair.
- Controlled entry replay is bound to the canonical action payload — ninth-repair.
- Controlled balance check replay is bound to the canonical action payload — ninth-repair.
- Controlled mutations bind to the locked canonical medication identity — ninth-repair.
- Stock movement requires a complete valid transition and constrained initialization — ninth-repair.
- Balance check requires existing locked stock and canonical medication — ninth-repair.

### tests/Feature/Emar/MedicationRbacAuthorizationTest.php

- Manual controlled record replays recheck witness and canonical binding — eighth-repair.

### tests/Feature/Emar/StockManagementTest.php

- Controlled pharmacy delivery is atomic fractional and replay safe — seventh-repair.
- Controlled pharmacy delivery retains its replay binding indefinitely — seventh-repair.
- Controlled pharmacy delivery replay rechecks current witness authority — seventh-repair.
- Controlled pharmacy delivery rejects forged medication and incomplete or stale balance without effects — seventh-repair.
- Controlled pharmacy delivery audit failure rolls back order stock register and replay result — seventh-repair.
- A controlled delivery keeps the earlier batch and expiry — seventh-repair.

