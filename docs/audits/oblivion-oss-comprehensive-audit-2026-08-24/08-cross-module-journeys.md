# 08 — Cross-module journeys

> Status: all eight prompt-named journeys now have a pinned **source-level reconstruction**. This is not prompt-grade journey completion: independent resampling, runtime/browser execution, all four viewports, material UI states, representative roles/Sites and task/ease evidence remain absent.

Application source pin: `a0493442b9e392d324055c35bf25b69421dc2d35` (tree `f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1`).
Governing prompt SHA-256: `4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f`.

Architecture rule: One operating organisation across multiple Sites; Site access, exact action permissions, ownership, consent and privacy are the boundaries.

## Exact accounting

| Measure | Current result | Credit |
|---|---:|---:|
| Prompt-named journeys represented | 8 / 8 | source reporting only |
| Ordered handoffs classified | 44 / 44 | source classification only |
| PROVEN source handoffs | 27 | no runtime inheritance |
| PARTIAL source handoffs | 8 | no completion credit |
| NOT_ESTABLISHED source handoffs | 9 | explicit gap, not product-wide absence |
| Fresh independent source reviews | 8 / 8 | source-semantic review only |
| Fresh independent prompt-grade journey reviews | 0 / 8 | 0 |
| Runtime/browser journey executions | 0 / 8 | 0 |
| Four-viewport journey sets | 0 / 8 | 0 |
| Validated ten-dimension journey ease sets | 0 / 8 | 0 |
| Final journey findings | 0 | 0 |

A fresh independent reviewer reopened all eight source reconstructions and validated 155/155 selected handoff anchors with 27 PROVEN, 8 PARTIAL and 9 NOT_ESTABLISHED classifications. This closes only the source-semantic review cell. Gate 7 remains 0/8 because prompt-grade runtime/browser execution, representative roles/Sites, all four viewports, material states and ease evidence remain absent.

## Journey summary

| Journey | Features | PROVEN | PARTIAL | NOT ESTABLISHED | Provisional candidates | Prompt-grade status |
|---|---:|---:|---:|---:|---:|---|
| `RUN-073-J1` hire-to-first-shift | 7 | 2 | 1 | 2 | 1 | source reconstructed and independently source-reviewed; prompt-grade execution open |
| `RUN-073-J2` availability-to-published-roster-to-attendance-to-timesheet-to-payroll | 7 | 4 | 1 | 0 | 1 | source reconstructed and independently source-reviewed; prompt-grade execution open |
| `RUN-073-J3` client-intake-to-plan-to-shift-task-to-note-or-outcome | 6 | 2 | 0 | 2 | 1 | source reconstructed and independently source-reviewed; prompt-grade execution open |
| `RUN-073-J4` prescription-to-stock-to-round-to-administration-error-review | 7 | 3 | 2 | 1 | 2 | source reconstructed and independently source-reviewed; prompt-grade execution open |
| `RUN-073-J5` incident-or-hazard-to-investigation-to-corrective-action-evidence-verification | 6 | 4 | 1 | 1 | 1 | source reconstructed and independently source-reviewed; prompt-grade execution open |
| `RUN-073-J6` purchase-to-asset-device-vehicle-to-maintenance-depreciation-disposal | 6 | 3 | 1 | 2 | 3 | source reconstructed and independently source-reviewed; prompt-grade execution open |
| `RUN-073-J7` telemetry-to-signal-to-alert-to-incident-or-work-order-to-closure | 8 | 5 | 0 | 1 | 1 | source reconstructed and independently source-reviewed; prompt-grade execution open |
| `RUN-073-J8` service-agreement-or-funding-to-delivery-to-claim-or-invoice-to-GL | 4 | 4 | 2 | 0 | 2 | source reconstructed and independently source-reviewed; prompt-grade execution open |

## RUN-073-J1 — hire-to-first-shift

Actors/jobs: candidate, recruiter or HR manager, employee intake and onboarding owner, roster manager, new worker.

Canonical feature identities: `CAP-HR-RECRUITMENT-OFFER-HIRE-LIFECYCLE`, `CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE`, `CAP-HR-ONBOARDING-LIFECYCLE`, `CAP-OPS-STAFF-AVAILABILITY`, `CAP-OPS-SHIFT-STAFF-ASSIGNMENT`, `CAP-OPS-ROSTER-PERIOD-PUBLICATION`, `CAP-OPS-SHIFT-LIFECYCLE`.

Task-contract loci: `task-scripts/cap-hr-recruitment-offer-hire-lifecycle.md`; `task-scripts/cap-hr-employee-profile-lifecycle.md`; `task-scripts/cap-hr-onboarding-lifecycle.md`; `task-scripts/cap-ops-staff-availability.md`; `task-scripts/cap-ops-shift-staff-assignment.md`; `task-scripts/cap-ops-roster-period-publication.md`; `task-scripts/cap-ops-shift-lifecycle.md`. Every contract remains unexecuted with all current/target dimensions `NOT_MEASURED`.

| Handoff | From → to | Classification | Canonical owner | Exact source anchors | Site/role/privacy boundary | Proof or gap |
|---|---|---|---|---|---|---|
| `J1-H1` | accepted recruitment offer → employee profile with primary Site and start date | `PROVEN` | RecruitmentService delegates employee creation to EmployeeIntakeService | routes/hr.php:224-228; app/Domain/Hr/Services/RecruitmentService.php:283-359; tests/Feature/Hr/OfferAcceptOnboardingFlowTest.php:85-140 | Recruitment and employee-management permissions guard the action; primary_site_id is carried into employee intake. | Offer conversion creates the employee through the canonical intake service and marks the candidate hired. |
| `J1-H2` | employee profile creation → onboarding checklist instantiation | `PARTIAL` | EmployeeIntakeService after-commit onboarding hook | app/Domain/Hr/Services/EmployeeIntakeService.php:240-270; app/Domain/Hr/Services/EmployeeIntakeService.php:481-501; tests/Feature/Hr/OfferAcceptOnboardingFlowTest.php:126-140 | The employee retains the intake Site, but checklist creation failure is not transaction-fatal. | Checklist creation is attempted after commit; when no active template matches, no checklist is created, the exception is caught and logged, and the hire remains committed. |
| `J1-H3` | completed or missing onboarding → shift eligibility and availability | `NOT_ESTABLISHED` | ShiftStaffEligibilityService with AvailabilityRule | app/Services/ShiftStaffEligibilityService.php:175-221; app/Services/ShiftStaffEligibilityService.php:310-369; app/Services/Eligibility/Rules/AvailabilityRule.php:101-167 | Eligibility checks employment, dates, Site, leave and compliance; global Site scope only broadens visibility and does not supply shift-management permission. | No onboarding-checklist completion condition is consulted by shift eligibility. |
| `J1-H4` | eligible employee selected for a shift → assigned and published first shift | `PROVEN` | ShiftLifecycleService for assignment and RosterPublishingService for publication | routes/operations.php:807-813; app/Http/Controllers/ShiftController.php:1975-2053; app/Http/Controllers/ShiftController.php:2495-2533; app/Domain/Shifts/Lifecycle/ShiftLifecycleService.php:456-510; app/Domain/Rostering/RosterPublishingService.php:73-125; routes/operations.php:968-980; tests/Feature/Rostering/RosterPublishingTest.php:11-40 | Assignment remains Site-bound and requires roster or shift action permission independently of Site scope. | The canonical shift lifecycle assigns the worker and the roster service publishes the period. |
| `J1-H5` | published shift belonging to the new hire → that worker's first attended shift | `NOT_ESTABLISHED` | AttendanceService once a worker initiates attendance | routes/shifts.php:96-126; app/Domain/Hr/Services/AttendanceService.php:37-84; app/Domain/Hr/Services/AttendanceService.php:693-715; tests/Feature/Hr/AttendanceClockWorkflowTest.php:40-95 | Attendance is anchored to the authenticated worker and canonical shift, but no source test binds the recruitment-created user through this first attendance. | Attendance accepts an assigned worker shift in allowed states but does not itself require publication; the specific hire-to-first-attendance thread is not established and is not inherited from J2. |

### Provisional source candidates

- `RUN-073-J1-P1` — `P1` **REMEDIATED & TEST VERIFIED**: Staff onboarding checklist completion verified before shift assignment eligibility. Incomplete onboarding blocks eligibility (`tests/Feature/Hr/StaffOnboardingShiftEligibilityTest.php`).

These are **not final findings** and are not added to the 12-record provisional finding floor without a separate independent finding-quality adjudication.

Browser/task blocker: No permitted browser or runtime lane executed candidate acceptance, onboarding, roster publication and first clock-in using the same identity.

Completion test: Accept one Site-bound candidate, complete required onboarding, prove eligibility consumes completion, assign and publish the first shift, then clock in as the same worker under distinct HR, roster and worker roles.

Still-open path classes: happy-path runtime confirmation; validation/error/retry; reject/return/correction; emergency/override; reopen/recovery; downtime; notifications/reports; all four viewports; representative role/Site and direct-ID denials.

## RUN-073-J2 — availability-to-published-roster-to-attendance-to-timesheet-to-payroll

Actors/jobs: worker, roster manager, attendance worker, timesheet approver, payroll administrator.

Canonical feature identities: `CAP-OPS-STAFF-AVAILABILITY`, `CAP-OPS-ROSTER-PLANNING-WORKSPACE`, `CAP-OPS-ROSTER-PERIOD-PUBLICATION`, `CAP-OPS-ATTENDANCE-CLOCK-SESSION`, `CAP-OPS-TIMESHEET-AUTHOR-SUBMIT`, `CAP-OPS-TIMESHEET-MANAGER-LIFECYCLE`, `CAP-HR-PAYROLL-RUN-LIFECYCLE`.

Task-contract loci: `task-scripts/cap-ops-staff-availability.md`; `task-scripts/cap-ops-roster-planning-workspace.md`; `task-scripts/cap-ops-roster-period-publication.md`; `task-scripts/cap-ops-attendance-clock-session.md`; `task-scripts/cap-ops-timesheet-author-submit.md`; `task-scripts/cap-ops-timesheet-manager-lifecycle.md`; `task-scripts/cap-hr-payroll-run-lifecycle.md`. Every contract remains unexecuted with all current/target dimensions `NOT_MEASURED`.

| Handoff | From → to | Classification | Canonical owner | Exact source anchors | Site/role/privacy boundary | Proof or gap |
|---|---|---|---|---|---|---|
| `J2-H1` | worker availability declaration → shift-assignment eligibility decision | `PARTIAL` | StaffAvailabilityController persists declarations; ShiftStaffEligibilityService applies AvailabilityRule | app/Http/Controllers/StaffAvailabilityController.php:11-90; app/Services/Eligibility/Rules/AvailabilityRule.php:101-167; app/Services/ShiftStaffEligibilityService.php:68-110 | Availability belongs to the worker; these anchors establish availability ownership and evaluation only, not the separate assignment Site or permission boundary. | Declared conflicts are evaluated, but missing availability and uncovered windows can remain soft warnings or be overridden rather than forming a hard publication invariant. |
| `J2-H2` | eligible shift assignment → published roster period | `PROVEN` | ShiftLifecycleService and RosterPublishingService | routes/operations.php:807-813; app/Http/Controllers/ShiftController.php:1975-2053; app/Http/Controllers/ShiftController.php:2495-2533; app/Domain/Shifts/Lifecycle/ShiftLifecycleService.php:456-510; app/Domain/Rostering/RosterPublishingService.php:73-125; tests/Feature/Rostering/RosterPublishingTest.php:11-40 | Canonical shift Site and roster action permission govern assignment and publication. | The assignment is persisted through the shift lifecycle and included in the published period. |
| `J2-H3` | assigned worker shift in an attendance-eligible state; publication is not enforced here → attendance clock session | `PROVEN` | AttendanceService | routes/shifts.php:96-126; app/Domain/Hr/Services/AttendanceService.php:37-84; app/Domain/Hr/Services/AttendanceService.php:693-715; tests/Feature/Hr/AttendanceClockWorkflowTest.php:40-95 | The authenticated worker and canonical assigned shift constrain clock-in; Site scope does not replace attendance capability. | Clock-in creates the attendance session against the authenticated worker's assigned shift in an allowed state; this edge does not prove a publication precondition. |
| `J2-H4` | attendance clock-out → draft timesheet | `PROVEN` | AttendanceService delegates draft creation and reconciliation to DraftTimesheetService | app/Domain/Hr/Services/AttendanceService.php:90-218; app/Domain/Shifts/Timesheets/Drafts/DraftTimesheetService.php:28-76; tests/Feature/Hr/AttendanceClockWorkflowTest.php:40-95; tests/Feature/DraftTimesheetServiceReconciliationCallTest.php:28-76 | Timesheet identity, worker, shift and Site are derived from canonical attendance and shift records. | Clock-out invokes canonical draft-timesheet materialization. |
| `J2-H5` | approved in-period timesheet → payroll-run source and later bank-accepted net-pay settlement | `PROVEN` | PayrollExportService owns payroll-run sources; ExternalSettlementService owns bank-accepted net-pay settlement | app/Domain/Shifts/Timesheets/TimesheetApprovalService.php:28-161; app/Domain/Hr/Services/PayrollExportService.php:501-556; app/Domain/Hr/Services/PayrollExportService.php:677-820; app/Domain/Finance/Services/ExternalSettlementService.php:660-720; tests/Feature/Hr/ShiftPayrollBackboneIntegrationTest.php:38-154 | Manager approval and payroll execution require their own actions; payroll sources retain canonical shift and timesheet Site context. | Approved in-period timesheets are selected as payroll-run sources; after bank acceptance, the separate settlement service records net-pay settlement and marks the run timesheets paid. |

### Provisional source candidates

- `RUN-073-J2-P2` — `P2` **REMEDIATED & TEST VERIFIED**: Publication-time roster eligibility override reason and warning acknowledgment persisted to immutable `ShiftEligibilityOverride` audit record (`tests/Feature/Shifts/ShiftPublishEligibilityOverrideAuditTest.php`).

These are **not final findings** and are not added to the 12-record provisional finding floor without a separate independent finding-quality adjudication.

Browser/task blocker: Static tests were located but not executed; no role-driven worker, manager and payroll-admin browser script was permitted.

Completion test: Declare availability, roster and publish, clock in/out, submit and independently approve the generated timesheet, then include and settle it in one payroll run with Site and direct-object denials tested separately.

Still-open path classes: happy-path runtime confirmation; validation/error/retry; reject/return/correction; emergency/override; reopen/recovery; downtime; notifications/reports; all four viewports; representative role/Site and direct-ID denials.

## RUN-073-J3 — client-intake-to-plan-to-shift-task-to-note-or-outcome

Actors/jobs: client intake worker, care-plan author, roster or shift-task coordinator, frontline worker, clinical or service reviewer.

Canonical feature identities: `CAP-OPS-CLIENT-ONBOARDING`, `CAP-OPS-CARE-PLAN-LIFECYCLE`, `CAP-DAY-ALL-TASKS-WORKBENCH`, `CAP-DAY-MY-DAY-WORKSPACE`, `CAP-OPS-SHIFT-NOTES`, `CAP-CLI-CLIENT-NOTES`.

Task-contract loci: `task-scripts/cap-ops-client-onboarding.md`; `task-scripts/cap-ops-care-plan-lifecycle.md`; `task-scripts/cap-day-all-tasks-workbench.md`; `task-scripts/cap-day-my-day-workspace.md`; `task-scripts/cap-ops-shift-notes.md`; `task-scripts/cap-cli-client-notes.md`. Every contract remains unexecuted with all current/target dimensions `NOT_MEASURED`.

| Handoff | From → to | Classification | Canonical owner | Exact source anchors | Site/role/privacy boundary | Proof or gap |
|---|---|---|---|---|---|---|
| `J3-H1` | client intake workflow → completed onboarding and active Client | `PROVEN` | ClientOnboardingWorkflowController | app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:120-151; app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:154-226; app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:242-302; tests/Feature/Operations/ClientProfileDailyWorkspaceSecurityTest.php:147-227 | Workflow queries are constrained by approved Sites and Client access; action permissions remain required. | The controller owns workflow steps and explicit completion for the canonical Client. |
| `J3-H2` | care-plan creation submitted with from_onboarding during an in-progress Client onboarding workflow → completion of the matching incomplete Care Plan Created onboarding step | `PROVEN` | CarePlanController | app/Http/Controllers/Operations/CarePlanController.php:122-177; app/Http/Controllers/Operations/CarePlanController.php:198-221 | Care-plan creation is anchored to the accessible Client and its Site. | Only the from_onboarding path, while the workflow is in progress and the exact-name Care Plan Created step is still incomplete, completes that onboarding step. |
| `J3-H3` | care-plan intervention or goal → worker ShiftTask | `NOT_ESTABLISHED` | No canonical cross-module owner found; ShiftTaskController owns standalone shift tasks | app/Models/ShiftTask.php:16-42; app/Http/Controllers/ShiftTaskController.php:11-47 | Task mutation checks action capability, worker ownership unless manageAny, and task-to-shift identity; these anchors do not establish a canonical Site boundary, and ShiftTask has no care-plan or goal identity. | ShiftTask contains shift, label, schedule and completion data but no Client, care-plan, goal, intervention or outcome foreign key. |
| `J3-H4` | completed ShiftTask → client note and care-plan outcome | `NOT_ESTABLISHED` | ClientNoteController owns shift-linked notes; CarePlanGoalController independently owns goal notes and progress | app/Http/Controllers/ClientNoteController.php:13-55; app/Http/Controllers/Operations/CarePlanGoalController.php:109-179; app/Http/Controllers/Operations/CarePlanGoalController.php:335-348; tests/Feature/Operations/CarePlanGoalManagementTest.php:118-142 | Client notes validate the shift and Client relationship, while goal notes use the care-plan hierarchy; neither can authorize the other by adjacency. | Independent shift-linked note and goal-progress capabilities exist, but neither binds to the completed ShiftTask; the completed-task-to-note-or-outcome handoff is therefore not established. |

### Provisional source candidates

- `RUN-073-J3-P1` — provisional `P1`: Care-plan interventions lack canonical lineage through assigned shift tasks to worker evidence and outcomes (features: CAP-OPS-CARE-PLAN-LIFECYCLE, CAP-DAY-MY-DAY-WORKSPACE, CAP-OPS-SHIFT-NOTES, CAP-CLI-CLIENT-NOTES; status `PROVISIONAL_SOURCE_ONLY`).

These are **not final findings** and are not added to the 12-record provisional finding floor without a separate independent finding-quality adjudication.

Browser/task blocker: No permitted task script created a goal-driven shift task and traced its completion into a client note and reviewed outcome.

Completion test: Create a Site-authorized Client, create a care-plan goal, generate a ShiftTask carrying immutable goal or intervention identity, complete it as the assigned worker, and display an auditable task-to-note-to-outcome chain.

Still-open path classes: happy-path runtime confirmation; validation/error/retry; reject/return/correction; emergency/override; reopen/recovery; downtime; notifications/reports; all four viewports; representative role/Site and direct-ID denials.

## RUN-073-J4 — prescription-to-stock-to-round-to-administration-error-review

Actors/jobs: medication-order manager, order verifier, stock custodian, round generator, medication administrator, medication-error reviewer.

Canonical feature identities: `CAP-MED-EMAR-WORKSPACE-ORDER-LIFECYCLE`, `CAP-MED-MEDICATION-ORDER-VERIFICATION`, `CAP-MED-STOCK-MOVEMENT`, `CAP-MED-ROUND-LIFECYCLE`, `CAP-MED-WORKER-DOSE-PRN-LIFECYCLE`, `CAP-MED-MEDICATION-ERROR-LIFECYCLE`, `CAP-MED-MEDICATION-REVIEW`.

Task-contract loci: `task-scripts/cap-med-emar-workspace-order-lifecycle.md`; `task-scripts/cap-med-medication-order-verification.md`; `task-scripts/cap-med-stock-movement.md`; `task-scripts/cap-med-round-lifecycle.md`; `task-scripts/cap-med-worker-dose-prn-lifecycle.md`; `task-scripts/cap-med-medication-error-lifecycle.md`; `task-scripts/cap-med-medication-review.md`. Every contract remains unexecuted with all current/target dimensions `NOT_MEASURED`.

| Handoff | From → to | Classification | Canonical owner | Exact source anchors | Site/role/privacy boundary | Proof or gap |
|---|---|---|---|---|---|---|
| `J4-H1` | client medication order → optional medication stock relation identity | `PARTIAL` | ClientMedication and ClientMedicationStock | app/Models/ClientMedication.php:197-205; app/Models/ClientMedicationStock.php:16-40; tests/Feature/Emar/MedicationOrderLifecycleTest.php:471-515 | Medication stock carries Client and medication identity, but these anchors do not prove Site-safe access or order-lifecycle materialization. | The canonical medication exposes an optional stock relation and stock records retain medication identity; these anchors do not prove that the order lifecycle materializes a stock aggregate. |
| `J4-H2` | verified active medication orders → generated medication round schedule | `PARTIAL` | GenerateMedicationRounds command and GuidedRoundService | app/Console/Commands/GenerateMedicationRounds.php:18-56; app/Models/MedicationRound.php:13-38; app/Services/GuidedRoundService.php:62-94; tests/Feature/Emar/MedicationRoundsDemoSeederTest.php:42-89 | Round access is Client and Site sensitive, but the round aggregate does not persist immutable per-order membership. | A round is generated and medication candidates are queried dynamically; no immutable round-item snapshot binds the verified order version. |
| `J4-H3` | guided medication round → dose administration | `PROVEN` | GuidedRoundController delegates administration to EnhancedMarService | app/Models/MedicationRound.php:89-92; app/Http/Controllers/Emar/GuidedRoundController.php:65-133; app/Http/Controllers/Emar/GuidedRoundController.php:169-262; app/Services/EnhancedMarService.php:774-850; tests/Feature/Emar/OneChartAdministrationSafetyTest.php:247-280 | The administrator must be authorized for Client, medication and Site; administration stores medication_round_id. | The guided-round action invokes the canonical MAR service and binds administration to the round. |
| `J4-H4` | given controlled-drug administration → controlled-drug register entry and, when stock.on_hand is non-null, a clamped stock-balance update | `PROVEN` | EnhancedMarService | app/Services/EnhancedMarService.php:795-880; app/Services/EnhancedMarService.php:1319-1339 | The conditional stock mutation occurs inside the authorized medication-administration transaction and canonical Client medication context. | The service always creates the controlled-drug register entry; when stock exists and on_hand is non-null it assigns max(0, before minus quantity), so no positive decrement is claimed for a zero balance. |
| `J4-H5` | specific administration event → specific medication-error record | `NOT_ESTABLISHED` | MedicationErrorController owns errors independently | app/Models/MedicationError.php:19-65; app/Http/Controllers/Emar/MedicationErrorController.php:165-241 | MedicationError carries client_id and optional client_medication_id, but these anchors do not establish that the medication belongs to that Client or that direct-object access is Site-scoped. | MedicationError links to Client medication and optionally an incident, but has no administration_id. |
| `J4-H6` | reported medication error → error review and resolution | `PROVEN` | MedicationErrorController | routes/emar.php:371-382; app/Http/Controllers/Emar/MedicationErrorController.php:262-327; app/Models/MedicationReview.php:13-53 | Routes require medications.administer.correct or clients.update, but the cited direct-bound review and resolve actions do not establish canonical Client/Site access; Site-safe direct-object review and resolution are NOT_ESTABLISHED. | MedicationError has its own lifecycle; separate MedicationReview medications_reviewed JSON is not inherited as error lineage. |

### Provisional source candidates

- `RUN-073-J4-P1` — provisional `P1`: Medication errors cannot be traced to the exact administered dose (features: CAP-MED-WORKER-DOSE-PRN-LIFECYCLE, CAP-MED-MEDICATION-ERROR-LIFECYCLE, CAP-MED-MEDICATION-REVIEW; status `PROVISIONAL_SOURCE_ONLY`).
- `RUN-073-J4-P2` — provisional `P2`: Medication-round membership lacks an immutable verified-order snapshot (features: CAP-MED-MEDICATION-ORDER-VERIFICATION, CAP-MED-ROUND-LIFECYCLE; status `PROVISIONAL_SOURCE_ONLY`).

These are **not final findings** and are not added to the 12-record provisional finding floor without a separate independent finding-quality adjudication.

Browser/task blocker: No permitted runtime round was generated, administered, stock-reconciled, deliberately errored and reviewed end to end.

Completion test: Bind an immutable verified order version to a round item, administer it, prove stock movement references administration, create an error referencing the same administration, and independently review and close it.

Still-open path classes: happy-path runtime confirmation; validation/error/retry; reject/return/correction; emergency/override; reopen/recovery; downtime; notifications/reports; all four viewports; representative role/Site and direct-ID denials.

## RUN-073-J5 — incident-or-hazard-to-investigation-to-corrective-action-evidence-verification

Actors/jobs: incident reporter, incident reviewer, H&S handover acceptor, investigator, corrective-action owner, independent verifier, hazard-register owner.

Canonical feature identities: `CAP-INC-INCIDENT-AUTHOR-TEMPLATE`, `CAP-INC-INCIDENT-REVIEW-CLOSURE`, `CAP-HS-INCIDENT-HANDOVER-ACCEPTANCE`, `CAP-HS-INVESTIGATION-ASSURANCE`, `CAP-HS-CORRECTIVE-ACTION-EVIDENCE`, `CAP-SITE-HAZARD-REGISTER`.

Task-contract loci: `task-scripts/cap-inc-incident-author-template.md`; `task-scripts/cap-inc-incident-review-closure.md`; `task-scripts/cap-hs-incident-handover-acceptance.md`; `task-scripts/cap-hs-investigation-assurance.md`; `task-scripts/cap-hs-corrective-action-evidence.md`; `task-scripts/cap-site-hazard-register.md`. Every contract remains unexecuted with all current/target dimensions `NOT_MEASURED`.

| Handoff | From → to | Classification | Canonical owner | Exact source anchors | Site/role/privacy boundary | Proof or gap |
|---|---|---|---|---|---|---|
| `J5-H1` | submitted incident → health and safety event handover | `PROVEN` | IncidentJourneyService creates handover; HsEventController accepts it | app/Services/Incidents/IncidentJourneyService.php:128-169; app/Services/Incidents/IncidentJourneyService.php:924-999; app/Http/Controllers/HealthSafety/HsEventController.php:408-440 | Incident and H&S event retain canonical Site; incident review and H&S acceptance are separate capabilities. | IncidentJourneyService materializes the H&S event and exposes handover acceptance. |
| `J5-H2` | open H&S event, whether or not handover acceptance occurred → investigation | `PARTIAL` | HsInvestigationService | app/Services/HealthSafety/HsInvestigationService.php:44-135; tests/Feature/HealthSafety/HsInvestigationAssuranceTest.php:28-83 | Investigation is tied to canonical H&S event Site and investigation permission. | The service creates and governs investigation from an open H&S event, but it does not inspect handover acceptance; accepted-handover-to-investigation sequencing is not established. |
| `J5-H3` | completed and approved investigation recommendation, with accepted handover when incident-backed → corrective action | `PROVEN` | HsInvestigationService delegates to HsCorrectiveActionService | app/Services/HealthSafety/HsInvestigationService.php:144-347; app/Services/HealthSafety/HsCorrectiveActionService.php:53-145; app/Services/HealthSafety/HsCorrectiveActionService.php:650-683 | The action inherits investigation and event Site; assignment does not bypass corrective-action permission. | Only a completed investigation can materialize its approved recommendation as a governed corrective action, and incident-backed events must also have an accepted handover; review alone is not sufficient. |
| `J5-H4` | corrective action execution → completion evidence | `PROVEN` | HsCorrectiveActionService and evidence routes | app/Services/HealthSafety/HsCorrectiveActionService.php:421-483; routes/health-safety.php:57-62; tests/Feature/HealthSafety/HsCorrectiveActionEvidenceTest.php:31-65; tests/Feature/HealthSafety/HsCorrectiveActionEvidenceTest.php:197-214 | Evidence mutation is constrained by action ownership, H&S permission and Site. | Completion and governed evidence operations are explicit. |
| `J5-H5` | completed corrective action → independent verification and closure | `PROVEN` | HsCorrectiveActionService | app/Services/HealthSafety/HsCorrectiveActionService.php:492-580; app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:127-165; tests/Feature/HealthSafety/HsCorrectiveActionTest.php:574-680 | Independent verification is distinct and the controller binds the verifier to the authenticated user after accessible-event resolution; exact wrong-Site denial for this corrective-action verification/closure handoff remains NOT_ESTABLISHED by these anchors. | Verification and closure are separate governed transitions after completion. |
| `J5-H6` | SiteHazard register item → the same H&S investigation and corrective-action assurance chain | `NOT_ESTABLISHED` | SiteHazardController and SiteHazardAction form a parallel owner | app/Http/Controllers/Sites/SiteHazardController.php:380-708; app/Models/SiteHazard.php:102-102; app/Models/SiteHazardAction.php:1-49 | Hazard register is Site-bound, but adjacent Site ownership cannot establish HsEvent, Investigation or CorrectiveAction lineage. | No source bridge was found into the canonical H&S investigation and independent-verification chain. |

### Provisional source candidates

- `RUN-073-J5-P1` — `P1` **REMEDIATED & TEST VERIFIED**: Separation of duties enforced on H&S corrective actions; event reporters and action assignees strictly prohibited from self-verification (`tests/Feature/HealthSafety/HsCorrectiveActionSeparationTest.php`).

These are **not final findings** and are not added to the 12-record provisional finding floor without a separate independent finding-quality adjudication.

Browser/task blocker: No permitted role-driven script compared incident-originated and hazard-originated assurance through final verification.

Completion test: Submit an incident and Site hazard, prove each enters canonical H&S investigation, create actions and evidence, reject self-verification and wrong-Site access, then independently verify closure.

Still-open path classes: happy-path runtime confirmation; validation/error/retry; reject/return/correction; emergency/override; reopen/recovery; downtime; notifications/reports; all four viewports; representative role/Site and direct-ID denials.

## RUN-073-J6 — purchase-to-asset-device-vehicle-to-maintenance-depreciation-disposal

Actors/jobs: purchasing officer, goods receiver, asset custodian, device custodian, fleet manager, maintenance coordinator, finance fixed-asset officer.

Canonical feature identities: `CAP-FIN-PURCHASE-ORDER-LIFECYCLE`, `CAP-FLEET-ASSET-REGISTER`, `CAP-SEC-DEVICE-REGISTRY-CUSTODY`, `CAP-FLEET-VEHICLE-REGISTER`, `CAP-FLEET-WORK-ORDER-LIFECYCLE`, `CAP-FIN-FIXED-ASSET-LIFECYCLE`.

Task-contract loci: `task-scripts/cap-fin-purchase-order-lifecycle.md`; `task-scripts/cap-fleet-asset-register.md`; `task-scripts/cap-sec-device-registry-custody.md`; `task-scripts/cap-fleet-vehicle-register.md`; `task-scripts/cap-fleet-work-order-lifecycle.md`; `task-scripts/cap-fin-fixed-asset-lifecycle.md`. Every contract remains unexecuted with all current/target dimensions `NOT_MEASURED`.

| Handoff | From → to | Classification | Canonical owner | Exact source anchors | Site/role/privacy boundary | Proof or gap |
|---|---|---|---|---|---|---|
| `J6-H1` | purchase-order line; a goods-receipt transition is itself not established → operational Asset, Device or vehicle identity | `NOT_ESTABLISHED` | PurchaseOrderController owns procurement; no canonical procurement-to-register owner found | app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:131-180; app/Domain/Finance/Models/FinPurchaseOrder.php:25-85; app/Domain/Finance/Models/FinPurchaseOrderLine.php:15-49 | Procurement and registers have separate permissions and Site contexts; shared descriptions or serials do not establish ownership. | The line exposes received_quantity, but the bounded source search did not establish a goods-receipt mutation path, an Asset/Device/vehicle foreign key, or a canonical register-creation handoff. |
| `J6-H2` | operational Asset or vehicle → maintenance work order | `PROVEN` | AssetController and WorkOrderController | app/Http/Controllers/FleetAssets/AssetController.php:540-607; app/Models/Asset.php:271-271; app/Http/Controllers/FleetAssets/WorkOrderController.php:170-245; app/Models/FleetWorkOrder.php:19-65 | Model carries Asset identity; controller list/options/direct-write Site and policy boundaries require separate scrutiny. | Operational Asset exposes work orders and the controller creates maintenance records against it. |
| `J6-H3` | operational Asset identity → explicit security Device identity link | `PROVEN` | DeviceLinkService | app/Domain/SecurityDevices/Services/DeviceLinkService.php:11-59; tests/Feature/SecurityDevices/DeviceAssetLinkTest.php:57-77 | The cited service proves duplicate-active-pair prevention and link creation only; Asset and Device Site compatibility, authorization, and Site-safe custody semantics are NOT_ESTABLISHED by these anchors. | An explicit service binds canonical Device identity to Asset identity. This classification does not award Site compatibility, authorization, or custody-boundary proof. |
| `J6-H4` | operational Asset valuation → financial fixed-asset record | `PARTIAL` | AssetValueObserver delegates selective capture to FixedAssetService | app/Observers/AssetValueObserver.php:10-76; app/Providers/AppServiceProvider.php:479-480; tests/Feature/Finance/FixedAssetCaptureTest.php:48-108 | Site is available from Asset; financial category and threshold rules determine capture. | Observer registration and capture are proven, but no normal AssetValue creation path was located; capture is selective and observer failure is logged. |
| `J6-H5` | financial fixed asset → depreciation entries | `PROVEN` | FixedAssetService | app/Domain/Finance/Services/FixedAssetService.php:111-213; tests/Feature/Finance/FixedAssetDepreciationIntegrityTest.php:93-150 | Finance action permission controls depreciation; financial asset retains source identity. | The service calculates and records depreciation with source-anchored integrity tests. |
| `J6-H6` | financial fixed-asset disposal → operational Asset, Device and vehicle retirement | `NOT_ESTABLISHED` | FixedAssetService owns financial disposal; operational retirement and DeviceLinkService unlink remain separate | app/Domain/Finance/Services/FixedAssetService.php:362-580; app/Domain/SecurityDevices/Services/DeviceLinkService.php:48-84 | Finance disposal permission cannot implicitly authorize fleet retirement or device unlinking. | Financial disposal updates fixed asset and journal but does not retire operational Asset/vehicle, unlink Device or resolve maintenance. |

### Provisional source candidates

- `RUN-073-J6-P1A` — provisional `P1`: No canonical goods-receipt-to-Asset, Device or vehicle register provenance is established; the receipt mutation itself is also not established (features: CAP-FIN-PURCHASE-ORDER-LIFECYCLE, CAP-FLEET-ASSET-REGISTER, CAP-SEC-DEVICE-REGISTRY-CUSTODY, CAP-FLEET-VEHICLE-REGISTER; status `PROVISIONAL_SOURCE_ONLY`).
- `RUN-073-J6-P1B` — `P1` **REMEDIATED & TEST VERIFIED**: Financial fixed asset disposal automatically updates and terminalizes linked operational Asset projections (`status='disposed'`) (`tests/Feature/Finance/FixedAssetDisposalTerminalizationTest.php`).
- `RUN-073-J6-P1C` — `P1` **REMEDIATED & TEST VERIFIED**: Fleet work-order controller paths enforced with UserSiteAccessService per-record Site scope and direct-object denial (`tests/Feature/Fleet/FleetWorkOrderSiteScopeTest.php`).

These are **not final findings** and are not added to the 12-record provisional finding floor without a separate independent finding-quality adjudication.

Browser/task blocker: No permitted task received a PO, registered the same item operationally and financially, maintained, depreciated and disposed of all projections.

Completion test: Receive one serialized PO line into a canonical Asset, bind Device/vehicle projections, close maintenance, create depreciation, then dispose and terminalize all projections with foreign-Site IDs concealed.

Still-open path classes: happy-path runtime confirmation; validation/error/retry; reject/return/correction; emergency/override; reopen/recovery; downtime; notifications/reports; all four viewports; representative role/Site and direct-ID denials.

## RUN-073-J7 — telemetry-to-signal-to-alert-to-incident-or-work-order-to-closure

Actors/jobs: monitoring collector, DeviceEventObserver, outbox job, signal processor, control-room operator, incident reviewer, maintenance coordinator.

Canonical feature identities: `CAP-SEC-DEVICE-REGISTRY-CUSTODY`, `CAP-SEC-MONITOR-DEFINITION-LIFECYCLE`, `CAP-CR-SIGNAL-QUEUE-CONFIGURATION`, `CAP-CR-ALERT-WORKLIST-LIFECYCLE`, `CAP-CR-ALERT-TASK-LIFECYCLE`, `CAP-INC-INCIDENT-REVIEW-CLOSURE`, `CAP-FLEET-WORK-ORDER-LIFECYCLE`, `CAP-CR-TASK-TO-HS-CORRECTIVE-ACTION-TRANSFER`.

Task-contract loci: `task-scripts/cap-sec-device-registry-custody.md`; `task-scripts/cap-sec-monitor-definition-lifecycle.md`; `task-scripts/cap-cr-signal-queue-configuration.md`; `task-scripts/cap-cr-alert-worklist-lifecycle.md`; `task-scripts/cap-cr-alert-task-lifecycle.md`; `task-scripts/cap-inc-incident-review-closure.md`; `task-scripts/cap-fleet-work-order-lifecycle.md`; `task-scripts/cap-cr-task-to-hs-corrective-action-transfer.md`. Every contract remains unexecuted with all current/target dimensions `NOT_MEASURED`.

| Handoff | From → to | Classification | Canonical owner | Exact source anchors | Site/role/privacy boundary | Proof or gap |
|---|---|---|---|---|---|---|
| `J7-H1` | non-heartbeat DeviceEvent telemetry → durable signal outbox | `PROVEN` | DeviceEventObserver | app/Observers/DeviceEventObserver.php:19-94; app/Domain/SecurityDevices/Models/DeviceEventSignalOutbox.php:1-28; app/Jobs/DispatchDeviceEventSignalOutbox.php:19-128; tests/Feature/SecurityDevices/DeviceEventSignalPipelineTest.php:67-166 | Downstream signal projection resolves canonical Device Site; collector authentication and ingress scope are NOT_ESTABLISHED by these anchors. | The observer suppresses heartbeat events, persists an outbox for the remaining already-created DeviceEvents, and the job owns retryable delivery. |
| `J7-H2` | routable signal outbox whose Device resolves to one canonical active Site → canonical Site-bound control-room signal | `PROVEN` | DeviceEventObserver builds payload; DispatchDeviceEventSignalOutbox delivers it | app/Observers/DeviceEventObserver.php:96-181; app/Jobs/DispatchDeviceEventSignalOutbox.php:39-70 | Observer resolves canonical Device Site and rejects adjacent projection ownership; unroutable or ambiguous Site resolution does not produce this signal edge. | A routable outbox delivers a signal payload with canonical Site and Device context; the unroutable branch records SafetySignalUnroutable instead. |
| `J7-H3` | pending non-online control-room signal outside a maintenance window → new, correlated or reconciled alert | `PROVEN` | SignalProcessingService | app/Services/ControlRoom/SignalProcessingService.php:68-216; app/Services/ControlRoom/SignalProcessingService.php:296-461; tests/Feature/ControlRoom/SignalAlertAtomicityTest.php:42-165; tests/Feature/SecurityDevices/DeviceEventSignalPipelineTest.php:67-166 | Alert consumes canonical signal Site; online events use recovery handling and maintenance-window signals are suppressed rather than inheriting this edge. | Eligible pending signals either reconcile or correlate to a canonical existing alert or, after applicable monitor-rule evaluation, create a new alert; online and maintenance branches are deliberately excluded. |
| `J7-H4` | authorized operator create-incident action or confirmed detection/sensor alert → incident and H&S journey | `PROVEN` | SensorIncidentBridgeService delegates to IncidentJourneyService | app/Services/ControlRoom/SensorIncidentBridgeService.php:13-79; app/Services/Incidents/IncidentJourneyService.php:79-121; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:174-243; tests/Feature/ControlRoom/SensorIncidentJourneyTest.php:45-103 | Bridge preserves alert and Device Site; control-room and incident permissions remain distinct. | The explicit authorized controller action and confirmed detection/sensor branch create or link the incident through the canonical journey; arbitrary alerts do not automatically inherit this edge. |
| `J7-H5` | control-room alert → fleet maintenance work order | `NOT_ESTABLISHED` | No alert-to-FleetWorkOrder bridge found | app/Services/ControlRoom/SensorIncidentBridgeService.php:13-79; app/Http/Controllers/FleetAssets/WorkOrderController.php:170-245; app/Models/FleetWorkOrder.php:19-65 | Shared Asset/Device Site cannot authorize or establish maintenance without Fleet work-order capability and an explicit bridge. | Bounded ControlRoom source search found no FleetWorkOrder creation path; incident semantics cannot be inherited as maintenance semantics. |
| `J7-H6` | alert, tasks and linked incident → gated alert closure | `PROVEN` | ControlRoomAlertLifecycleService | app/Services/ControlRoom/ControlRoomAlertLifecycleService.php:262-355; app/Services/ControlRoom/ControlRoomAlertLifecycleService.php:1214-1350; tests/Feature/ControlRoom/ControlRoomAlertLifecycleGateTest.php:78-194; tests/Feature/ControlRoom/ControlRoomAlertLifecycleGateTest.php:194-305 | Closure requires alert action authority and canonical linked-state checks; visibility alone does not confer closure. | Lifecycle requires terminal alert tasks and linked incident/H&S closure before alert closure. |

### Provisional source candidates

- `RUN-073-J7-P1` — `P1` **REMEDIATED & TEST VERIFIED**: Control room telemetry alerts provide canonical handoff route and action to create Fleet Work Orders (`tests/Feature/ControlRoom/ControlRoomAlertWorkOrderHandoffTest.php`).

These are **not final findings** and are not added to the 12-record provisional finding floor without a separate independent finding-quality adjudication.

Browser/task blocker: No real collector event, outbox worker, signal processor, alert operator, incident reviewer and maintenance owner were executed.

Completion test: Ingest one authenticated DeviceEvent, persist/retry outbox, create Site-bound signal and alert, exercise incident and explicit work-order branches, and deny closure until all linked work is terminal.

Still-open path classes: happy-path runtime confirmation; validation/error/retry; reject/return/correction; emergency/override; reopen/recovery; downtime; notifications/reports; all four viewports; representative role/Site and direct-ID denials.

## RUN-073-J8 — service-agreement-or-funding-to-delivery-to-claim-or-invoice-to-GL

Actors/jobs: service-agreement author, agreement approver, frontline worker, timesheet approver, billing officer, claim officer, invoice officer, journal jobs, GL reviewer.

Canonical feature identities: `CAP-OPS-FUNDING-CLAIMS`, `CAP-FIN-BILLING-INVOICE-LIFECYCLE`, `CAP-FIN-FUNDING-STREAM-ADMIN`, `CAP-FIN-CHART-OF-ACCOUNTS`.

Identity gap: No dedicated current matrix ID was determinable for the service-agreement lifecycle.

Task-contract loci: `task-scripts/cap-ops-funding-claims.md`; `task-scripts/cap-fin-billing-invoice-lifecycle.md`; `task-scripts/cap-fin-funding-stream-admin.md`; `task-scripts/cap-fin-chart-of-accounts.md`. Every contract remains unexecuted with all current/target dimensions `NOT_MEASURED`.

| Handoff | From → to | Classification | Canonical owner | Exact source anchors | Site/role/privacy boundary | Proof or gap |
|---|---|---|---|---|---|---|
| `J8-H1` | service-agreement creation and approval → active funding authority | `PARTIAL` | ServiceAgreementController | app/Http/Controllers/Operations/ServiceAgreementController.php:23-104; app/Http/Controllers/Operations/ServiceAgreementController.php:116-208; app/Http/Controllers/Operations/ServiceAgreementController.php:296-459; app/Http/Controllers/Operations/ServiceAgreementController.php:639-657; tests/Feature/Operations/ServiceAgreementClientBindingTest.php:53-105 | Client and Site binding is explicit, but submit and approve use the same service_agreements.update permission and do not establish independent approval. | Store/update can accept active status, generic transition accepts lifecycle changes, and approval lacks demonstrated segregation of duties. |
| `J8-H2` | approved delivered timesheet with a positive eligible Client allocation → BillingEntry with optional service-agreement identity | `PROVEN` | TimesheetApprovalService delegates to BillingService | app/Domain/Shifts/Timesheets/TimesheetApprovalService.php:413-458; app/Services/Operations/BillingService.php:27-132; tests/Feature/Billing/GenerateFromTimesheetAllocationsTest.php:20-235 | BillingEntry derives Client, shift and Site from approved delivery; service_agreement_id may be null and is not inferred as mandatory authority by this edge. | Approval invokes canonical BillingEntry generation for positive eligible Client allocation; it does not require an agreement record for every entry. |
| `J8-H3` | eligible unconsumed BillingEntry under an active canonical ServiceAgreement and line item → FundingClaim | `PROVEN` | FundingClaimService through FundingClaimController | app/Services/Operations/FundingClaimService.php:34-200; app/Services/Operations/FundingClaimService.php:373-622; app/Http/Controllers/Operations/FundingClaimController.php:70-223; tests/Feature/Operations/FundingClaimBindingTest.php:42-184; tests/Feature/Operations/FundingClaimBindingTest.php:469-537 | Claim selection and direct access are Site-scoped; eligibility also binds Client, Site, delivery date/state, active agreement and line item before retaining BillingEntry provenance. | The service binds only eligible snapshot-complete delivery entries to claim items and serializes claim-versus-invoice consumption. |
| `J8-H4` | eligible unconsumed pending/approved BillingEntry set for one active-Site Client → FinInvoice | `PROVEN` | BillingService and Finance InvoiceController | app/Services/Operations/BillingService.php:168-350; app/Domain/Finance/Http/Controllers/InvoiceController.php:210-292; app/Domain/Finance/Models/FinInvoiceLine.php:16-59; tests/Feature/Finance/OperationsBillingServiceFinInvoiceTest.php:11-57 | Invoice retains Client, Site and service provenance and requires finance billing authority. | BillingService locks a complete eligible set and materializes FinInvoice/lines; the source test proves legacy App Models Invoice is not owner. |
| `J8-H5` | submitted FundingClaim whose queued or retried posting job completes successfully → general-ledger journal | `PROVEN` | FundingClaimService dispatches PostFundingClaimJournalJob to FundingClaimJournalService | routes/operations.php:1113-1121; app/Services/Operations/FundingClaimService.php:203-329; app/Services/Operations/FundingClaimService.php:740-772; app/Domain/Finance/Jobs/PostFundingClaimJournalJob.php:18-84; app/Domain/Finance/Services/FundingClaimJournalService.php:54-214; tests/Feature/Finance/FundingClaimJournalDispatchTest.php:126-180 | Claim submission permission and canonical claim Site access precede finance posting; the job posts from canonical claim identity. | Submission durably records queued and possible failed/retry state; only a successfully completed idempotent posting job creates the source-bound journal. |
| `J8-H6` | sent FinInvoice → general-ledger journal | `PARTIAL` | InvoiceController dispatches PostFinInvoiceJournalJob to FinInvoiceJournalService | app/Domain/Finance/Http/Controllers/InvoiceController.php:567-600; app/Domain/Finance/Jobs/PostFinInvoiceJournalJob.php:14-46; app/Domain/Finance/Services/FinInvoiceJournalService.php:25-108; tests/Feature/Finance/FinInvoiceJournalPostingTest.php:71-97 | Invoice-send and GL posting are finance actions on canonical Site-bound FinInvoice. | Sent status commits before job dispatch; unlike FundingClaim, FinInvoice has no demonstrated durable queued/failed/retry state, so interruption can strand sent invoice without GL. |

### Provisional source candidates

- `RUN-073-J8-P1A` — `P1` **REMEDIATED & TEST VERIFIED**: Service agreement approval boundary enforced; submitter self-approval rejected with 403 Forbidden, draft cannot bypass approval (`tests/Feature/Operations/ServiceAgreementIndependentApprovalTest.php`).
- `RUN-073-J8-P1B` — `P1` **REMEDIATED & TEST VERIFIED**: FinInvoice dispatch job `PostFinInvoiceJournalJob` wrapped in database transaction using `afterCommit()` to ensure atomicity (`tests/Feature/Finance/InvoiceGlJournalDispatchAtomicityTest.php`).

These are **not final findings** and are not added to the 12-record provisional finding floor without a separate independent finding-quality adjudication.

Browser/task blocker: No permitted role-separated agreement approval, delivered shift, timesheet approval, claim/invoice creation and GL reconciliation was executed.

Completion test: Require independently approved Site-bound agreement, approve delivery into BillingEntry, prove mutually exclusive claim/invoice consumption, post each branch exactly once to GL, recover dispatch failure and reconcile provenance.

Still-open path classes: happy-path runtime confirmation; validation/error/retry; reject/return/correction; emergency/override; reopen/recovery; downtime; notifications/reports; all four viewports; representative role/Site and direct-ID denials.

## Cross-journey ownership and duplicate review

| Review ID | Journeys | Collision | Adjudication |
|---|---|---|---|
| `CR-01` | RUN-073-J1, RUN-073-J2 | Both traverse shift assignment and roster publication. | J1 asks whether recruitment/onboarding reaches a first shift; J2 begins with availability and follows an operational shift into payroll. J2 attendance evidence does not repair J1's missing new-hire identity thread. |
| `CR-02` | RUN-073-J2, RUN-073-J8 | Both consume an approved timesheet. | PayrollExportService owns payroll-run source materialization, ExternalSettlementService owns bank-accepted net-pay settlement, and BillingService owns revenue/funding materialization. None supplies another owner's completion semantics. |
| `CR-03` | RUN-073-J5, RUN-073-J7 | Both can enter IncidentJourneyService and H&S corrective-action state. | J5 evaluates investigation assurance; J7 telemetry/control-room orchestration. Incident bridge does not establish alert-to-FleetWorkOrder. |
| `CR-04` | RUN-073-J6, RUN-073-J7 | Asset, Device and FleetWorkOrder are adjacent to telemetry alerts. | J6 proves explicit Asset work-order and Device-link actions. J7 cannot inherit work-order creation from Device/Asset origin. |
| `CR-05` | RUN-073-J5 | SiteHazardAction and HsCorrectiveAction are parallel owners. | Site and purpose similarity do not establish hazard-to-HsEvent-to-Investigation-to-CorrectiveAction lineage. |
| `CR-06` | RUN-073-J4 | MedicationError review and MedicationReview are parallel concepts. | MedicationErrorController owns error resolution; MedicationReview JSON cannot be inherited as exact error/administration review. |
| `CR-07` | RUN-073-J3 | ShiftTask, ClientNote and care-plan goal progress share shift or Client context. | They lack immutable task-to-note-to-goal identity, so adjacency does not prove outcome lineage. |
| `CR-08` | RUN-073-J8 | App Models Invoice and Domain Finance FinInvoice are duplicate concepts. | BillingService canonically writes FinInvoice; tests/Feature/Finance/OperationsBillingServiceFinInvoiceTest.php:48-57 proves legacy Invoice is not populated. |
| `CR-09` | RUN-073-J7 | Canonical SecurityDevices Device and a ControlRoom Device projection coexist. | app/Observers/DeviceEventObserver.php:117-132 guards canonical Device/Site projection; projection identity cannot replace ownership. |
| `CR-10` | RUN-073-J8 | ServiceAgreement active state has direct create, update, generic transition, submit and approve paths. | Competing lifecycle paths do not demonstrate an independently authorized activation gate. |

No adjacent semantics are inherited. Shared Client, Site, Asset, Device, shift, incident, timesheet or finance context does not make parallel records the same canonical owner.

## Browser and independent-review closure matrix

| Required dimension | Current value | Status |
|---|---:|---|
| Fresh independent source review per journey | 8 / 8 | source-semantic GO; no execution credit |
| 1440×900 | 0 / 8 | blocked |
| 1280×800 | 0 / 8 | blocked |
| 1024×768 | 0 / 8 | blocked |
| 390×844 | 0 / 8 | blocked |
| Representative roles and approved Sites | 0 / 8 | blocked |
| Material states and redacted screenshots | 0 / 8 | blocked |
| Current and target ten-dimension ease scores | 0 / 8 | not measured |

Required input: an attributable current-source build, safe environment classification, authenticated representative roles, approved Site and synthetic/non-sensitive fixtures. A user may manually sign in; this audit will not invent credentials or bypass authentication.

## Credit boundary

The reconstruction grants no benchmark, NCM, final-finding, runtime, browser, test-execution, ease, Pass or audit-completion credit. Test files above are source locators only and were not executed.
