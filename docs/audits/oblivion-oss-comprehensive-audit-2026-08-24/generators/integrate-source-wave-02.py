#!/usr/bin/env python3
"""Normalize formal current-source discovery assignments RUN-004 through RUN-006.

The script is deterministic, static, and audit-directory-only.  It does not
import or execute Oblivion Findings application code.
"""

from __future__ import annotations

import hashlib
import json
from collections import Counter
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
GENERATED_AT = "2026-08-24T16:20:11+12:00"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
AUDIT_INPUT_COMMIT = "5ff93c3719fbc8870d23111f0fc9811b16eec06a"


def write_json(relative: str, payload: object) -> None:
    path = AUDIT_DIR / relative
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")


def write_text(relative: str, payload: str) -> None:
    path = AUDIT_DIR / relative
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(payload.rstrip() + "\n", encoding="utf-8", newline="\n")


def digest(payload: object) -> str:
    raw = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    return hashlib.sha256(raw).hexdigest()


MODULE_BOUNDARIES = {
    "Finance": "Exact finance action authority remains distinct from approved-Site and Client/object scope. Global finance scope broadens visibility only; it never replaces posting, approval, bank, tax, or export authority.",
    "Governance": "Meeting, committee, distribution, voting, conflict, quorum, approval, and document audiences are separate object boundaries even for holders of broad governance view permissions.",
    "Health & Safety": "Generic hazards authority must be combined with approved-Site scope and the exact lifecycle action. Health, location, worker, Client, and evidence records require minimum-necessary disclosure.",
    "Privacy": "Privacy permissions are explicit application-wide action authorities, but viewRequests does not silently replace breach, retention, legal-hold, PIA, attachment, or export authority.",
    "Safeguarding": "Need-to-know sensitivity, reporter/assignee ownership, exact action capability, approved Site, canonical person/incident provenance, and concealed direct IDs are independent gates.",
    "Complaints & Feedback": "Complaint and feedback records inherit their canonical Site, stay, Client, staff, and confidentiality owners; a generic case or feedback permission is not a whistleblowing control.",
    "Sites & Locations": "Every Site object, picker, count, download, and mutation requires exact action authority plus approved-Site scope and canonical parent binding.",
    "Fleet & Assets": "Global Site scope broadens visibility only; asset, vehicle, booking, custody, Client, driver, witness, and transport relationships retain exact action and canonical ownership checks.",
    "Security Devices": "Device visibility follows approved Site and current or historical custody. Command, telemetry, access, provider, and report permissions remain action-specific and privacy-sensitive.",
    "IT & Support": "Self-service ownership and agent action authority remain distinct. Child work, attachments, queues, teams, linked records, and reports inherit canonical Site and parent visibility.",
    "Integrations": "Inbound signatures and canonical provider bindings and outbound destination safety are distinct controls. An integrations permission does not authorize arbitrary network traversal.",
    "Control Room": "Alert action authority, canonical Site precedence, source-record visibility, nested-parent binding, minimum-necessary audiences, and handover completeness remain separate.",
    "Public & Settings Platform": "Personal settings are self-owned; organisation administration is explicit. Public tokens/forms must not disclose internal Site, staff, Client, or secret state.",
}


# assignment, module, class, id, user job, canonical owner, production anchor, test anchor
ROWS = [
    ("RUN-004", "Finance", "H", "CAP-FIN-DASHBOARD-INSIGHTS", "Review organisation, Site, and Client financial position and obligations", "FinancialInsightsScopeResolver and finance dashboard aggregators", "routes/finance.php:69-108", "tests/Feature/Finance/FinancialInsightsObjectScopeTest.php:128"),
    ("RUN-004", "Finance", "H", "CAP-FIN-LEDGER-CHART-PERIODS", "Maintain ledger charts, periods, centres, streams, and currencies", "ChartOfAccountsService and ledger administration controllers", "routes/finance.php:134-205", "tests/Feature/Finance/FinanceChartVerificationTest.php:1"),
    ("RUN-004", "Finance", "H", "CAP-FIN-JOURNAL-POST-REVERSE-RECUR", "Draft, post, reverse, and review manual or recurring journals", "JournalPostingService and RecurringJournalService", "app/Domain/Finance/Services/JournalPostingService.php:21-272", "tests/Feature/Finance/JournalPostingReversalInvariantTest.php:81"),
    ("RUN-004", "Finance", "H", "CAP-FIN-AP-VENDOR-PO-BILL-CREDIT", "Manage suppliers, purchase orders, bills, credit notes, and approvals", "AccountsPayableService and AP controllers", "app/Domain/Finance/Services/AccountsPayableService.php:116-801", "tests/Feature/Finance/BillSpendApprovalGateTest.php:156"),
    ("RUN-004", "Finance", "H", "CAP-FIN-PAYMENT-RUN-SETTLEMENT", "Prepare, approve, export, settle, and reconcile supplier payment runs", "PaymentRunService and PaymentSettlementRecorder", "routes/finance.php:304-336", "tests/Feature/Finance/BillAndPaymentRunJournalPostingTest.php:184"),
    ("RUN-004", "Finance", "H", "CAP-FIN-AR-QUOTE-INVOICE-BILLING", "Manage quotes, billing entries, invoices, statements, and receipts", "QuoteLifecycleService and AccountsReceivableService", "routes/finance.php:347-415", "tests/Feature/Finance/QuoteConversionIntegrityTest.php:92"),
    ("RUN-004", "Finance", "H", "CAP-FIN-ALLOCATION-MATCH-HISTORY", "Review allocation history and confirm or reject payment matches", "PaymentMatchingService and settlement owners", "app/Domain/Finance/Services/PaymentMatchingService.php:300-421", "tests/Feature/Finance/PaymentAllocationIntegrityTest.php:360"),
    ("RUN-004", "Finance", "H", "CAP-FIN-BANK-FEED-RECON-EFTPOS", "Import bank activity, reconcile statements, and settle EFTPOS batches", "BankReconciliationService, BankFeedService, and EftposReconciliationService", "app/Domain/Finance/Services/BankReconciliationService.php:34-629", "tests/Feature/Finance/BankReconciliationAggregateTest.php:117"),
    ("RUN-004", "Finance", "H", "CAP-FIN-GST-IRD-COMPLIANCE", "Prepare, file, and amend GST and IRD obligations", "GstReturnService, IrdFilingService, and NzComplianceService", "routes/finance.php:530-547", "tests/Feature/Finance/IrdFilingCanonicalAccessTest.php:72"),
    ("RUN-004", "Finance", "H", "CAP-FIN-FIXED-ASSET-LIFECYCLE", "Register, capitalise, depreciate, and dispose of fixed assets", "FixedAssetService", "app/Domain/Finance/Services/FixedAssetService.php:26-362", "tests/Feature/Finance/FixedAssetDisposalIntegrityTest.php:60"),
    ("RUN-004", "Finance", "H", "CAP-FIN-CLIENT-PETTY-DONOR-FUNDS", "Govern Client money, petty cash, and restricted donor funds", "ClientFundTransactionService, PettyCashService, and DonorFundService", "app/Domain/Finance/Services/ClientFundTransactionService.php:144-304", "tests/Feature/Finance/ClientFundGovernanceTest.php:100"),
    ("RUN-004", "Finance", "H", "CAP-FIN-BUDGET-ACTUAL-FORECAST", "Compare budgets to actuals and create cash-flow forecasts", "BudgetActualsService, BudgetVarianceService, and CashFlowForecastService", "routes/finance.php:628-640", "tests/Feature/Finance/BudgetActualsLiveGlTest.php:16"),
    ("RUN-004", "Finance", "D", "CAP-FIN-REPORT-AUDIT-EXPORT", "Produce financial statements, list exports, and encrypted audit packs", "FinancialReportService and AuditExportService", "app/Domain/Finance/Services/AuditExportService.php:27-146", "tests/Feature/Finance/AuditExportSecurityTest.php:8"),
    ("RUN-004", "Finance", "D", "CAP-FIN-ACCOUNTING-SYNC-FX-CONSOLIDATION", "Configure accounting sync, FX revaluation, and supported consolidation", "Accounting providers, FxRevaluationService, ConsolidationService, and IntercompanyService", "app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php:27-319", "tests/Feature/Finance/ConsolidationQuarantineTest.php:17"),
    ("RUN-004", "Governance", "D", "CAP-GOV-DASHBOARD-REPORT-EVIDENCE", "Review board, committee, risk, compliance, and evidence-pack reporting", "DashboardAggregatorService, ReportController, and AuditEvidencePackService", "app/Domain/Governance/Http/Controllers/ReportController.php:27-183", "tests/Feature/Governance/GovernanceReportsTest.php:20"),
    ("RUN-004", "Governance", "H", "CAP-GOV-MEETING-AGENDA-MINUTES-ATTENDANCE", "Schedule meetings and manage agendas, attendance, minutes, and status", "GovernanceMeetingController and governance workflow services", "app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:41-152", "tests/Feature/Governance/GovernanceNestedBindingIntegrityTest.php:39"),
    ("RUN-004", "Governance", "H", "CAP-GOV-BOARD-PACK-DISTRIBUTION", "Generate, distribute, read, and download board packs", "BoardPackBuilderService and BoardPackController", "app/Domain/Governance/Http/Controllers/BoardPackController.php:23-90", "tests/Feature/Governance/GovernanceBoardPacksTest.php:24"),
    ("RUN-004", "Governance", "H", "CAP-GOV-RESOLUTION-VOTE-QUORUM", "Draft resolutions, declare conflicts, vote, enforce quorum, and close outcomes", "VotingService and ResolutionController", "app/Domain/Governance/Services/VotingService.php:29-187", "tests/Feature/Governance/GovernanceResolutionsTest.php:39"),
    ("RUN-004", "Governance", "H", "CAP-GOV-BOARD-MEMBER-INTEREST-EVALUATION", "Administer board membership, interests, and evaluations", "Board member, interest, and evaluation controllers", "app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:14-173", "tests/Feature/Governance/GovernanceBoardMemberSelfServiceTest.php:22"),
    ("RUN-004", "Governance", "H", "CAP-GOV-RISK-REGISTER-TREATMENT", "Score risks, add treatments, accept, and close records", "RiskRegisterController and RiskScoringService", "app/Domain/Governance/Http/Controllers/RiskRegisterController.php:31-277", "tests/Feature/Governance/GovernanceRiskRegisterTest.php:23"),
    ("RUN-004", "Governance", "H", "CAP-GOV-COMPLIANCE-OBLIGATION-EVIDENCE", "Manage compliance obligations, evidence, and notifiable records", "ComplianceController and ComplianceEngineService", "app/Domain/Governance/Services/ComplianceEngineService.php:67-203", "tests/Feature/Governance/GovernanceComplianceTest.php:26"),
    ("RUN-004", "Governance", "H", "CAP-GOV-POLICY-VERSION-ATTESTATION", "Draft, approve, version, and attest governance policies", "GovernancePolicyController and policy", "app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:15-258", "tests/Browser/Governance/GovernancePoliciesTest.php:6"),
    ("RUN-004", "Governance", "H", "CAP-GOV-ACTION-ITEM-WORKFLOW", "Create, progress, block, complete, and escalate governance actions", "ActionItemController and GovernanceWorkflowService", "app/Domain/Governance/Http/Controllers/ActionItemController.php:13-122", "tests/Feature/Governance/GovernanceActionItemsTest.php:20"),
    ("RUN-004", "Governance", "H", "CAP-GOV-STRATEGY-PLAN-GOALS", "Create strategic plans, goals, versions, and approvals", "StrategicPlanController and policy", "app/Domain/Governance/Http/Controllers/StrategicPlanController.php:19-167", "tests/Feature/Governance/GovernanceStrategyTest.php:20"),
    ("RUN-004", "Governance", "H", "CAP-GOV-BUDGET-ALLOCATIONS-ADJUSTMENTS", "Manage governance budgets, allocations, actuals, and approvals", "BudgetController and GovernanceNestedMutationService", "app/Domain/Governance/Services/GovernanceNestedMutationService.php:143-406", "tests/Feature/Governance/GovernanceNestedBindingIntegrityTest.php:81"),
    ("RUN-004", "Governance", "H", "CAP-GOV-CEO-BOARD-REPORT", "Draft, submit, present, and evidence CEO board reports", "CeoBoardReportController and policy", "app/Domain/Governance/Http/Controllers/CeoBoardReportController.php:18-206", "tests/Browser/Governance/GovernanceMiscTest.php:116"),
    ("RUN-004", "Governance", "H", "CAP-GOV-SPEND-APPROVAL", "Request, decide, and evidence board or committee spend approval", "SpendApprovalCommandService", "app/Domain/Governance/Services/SpendApprovalCommandService.php:109-345", "tests/Feature/Governance/SpendApprovalAuthorityTest.php:53"),
    ("RUN-004", "Governance", "H", "CAP-GOV-PERFORMANCE-REVIEW", "Run board and CEO performance reviews and approvals", "PerformanceReviewService and controller", "app/Domain/Governance/Services/PerformanceReviewService.php:22-373", "tests/Feature/Governance/GovernancePerformanceReviewTest.php:21"),
    ("RUN-004", "Governance", "H", "CAP-GOV-DOCUMENT-LIBRARY", "Store, browse, download, and remove governance documents", "GovernanceDocumentController and policy", "app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php:12-112", "tests/Browser/Governance/GovernanceMiscTest.php:6"),
    ("RUN-004", "Governance", "H", "CAP-GOV-CLINICAL-INDICATOR-SNAPSHOT", "Review clinical governance indicators and record snapshots", "ClinicalGovernanceController and automation service", "app/Domain/Governance/Http/Controllers/ClinicalGovernanceController.php:20-88", "tests/Feature/Governance/ClinicalGovernanceAutomationTest.php:37"),
    ("RUN-004", "Governance", "H", "CAP-GOV-TE-TIRITI-OBLIGATION", "Record and maintain Te Tiriti governance obligations", "TeTiritiController", "app/Domain/Governance/Http/Controllers/TeTiritiController.php:12-73", "tests/Browser/Governance/GovernanceMiscTest.php:146"),
    ("RUN-004", "Governance", "D", "CAP-GOV-AUDIT-LOG-EXPORT", "Review and export cross-module governance audit evidence", "GovernanceAuditLogController and GovernanceAuditService", "app/Domain/Governance/Http/Controllers/GovernanceAuditLogController.php:21-70", "tests/Feature/Governance/GovernanceSpendApprovalsTest.php:231"),
    ("RUN-004", "Governance", "D", "CAP-GOV-SETTINGS-CONTROL", "Configure governance escalation, spend, and workflow settings", "GovernanceSettingController", "app/Domain/Governance/Http/Controllers/GovernanceSettingController.php:90-109", "tests/Feature/Governance/GovernanceSpendApprovalsTest.php:214"),

    ("RUN-005", "Health & Safety", "H", "CAP-HS-DASHBOARD-ANALYTICS", "Review Site-scoped safety KPIs, trends, and attention worklists", "HealthSafetyDashboardController and analytics services", "app/Http/Controllers/HealthSafety/HealthSafetyDashboardController.php:52-67", "tests/Feature/HealthSafety/HealthSafetyDashboardControllerTest.php:43-84"),
    ("RUN-005", "Health & Safety", "H", "CAP-HS-EVENT-REGISTER-HANDOVER", "Find safety events and accept incident handover", "HsEventController and HsEventService", "app/Services/HealthSafety/HsEventService.php:269-322", "tests/Feature/HealthSafety/HsHandoverAcceptanceTest.php:37-94"),
    ("RUN-005", "Health & Safety", "H", "CAP-HS-WORKSAFE-DECISION-NOTIFY-PRESERVE", "Record notifiable decisions, WorkSafe notice, and Site preservation", "HsEventService and HsWorksafeDecisionController", "app/Services/HealthSafety/HsEventService.php:322-663", "tests/Feature/HealthSafety/HsEventWorksafeTest.php:197-256"),
    ("RUN-005", "Health & Safety", "H", "CAP-HS-INVESTIGATION-ASSURANCE", "Investigate, review, rework, and independently approve completion", "HsInvestigationService", "app/Services/HealthSafety/HsInvestigationService.php:185-332", "tests/Feature/HealthSafety/HsInvestigationAssuranceTest.php:28-83"),
    ("RUN-005", "Health & Safety", "H", "CAP-HS-CORRECTIVE-ACTION-EVIDENCE", "Create, complete, verify, close, and evidence corrective actions", "HsCorrectiveActionService", "app/Services/HealthSafety/HsCorrectiveActionService.php:53-592", "tests/Feature/HealthSafety/HsCorrectiveActionEvidenceTest.php:31-99"),
    ("RUN-005", "Health & Safety", "H", "CAP-HS-EVENT-CLOSURE-EXCEPTIONS", "Close safety events or approve narrow independent exceptions", "HsEventClosureService", "app/Services/HealthSafety/HsEventClosureService.php:251-590", "tests/Feature/HealthSafety/HsEventClosureTest.php:41-128"),
    ("RUN-005", "Health & Safety", "H", "CAP-HS-RISK-ASSESSMENT-LIFECYCLE", "Create, review, activate, supersede, and archive risk assessments", "HsRiskAssessmentController and service", "app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:140-313", "tests/Feature/HealthSafety/HsRiskAssessmentSiteAccessTest.php:30-128"),
    ("RUN-005", "Health & Safety", "D", "CAP-HS-GOVERNANCE-REPORTS-EXPORT", "Produce board, WorkSafe, investigation, action, and risk evidence views", "HsGovernanceReportController and export services", "app/Http/Controllers/HealthSafety/HsGovernanceReportController.php:25-100", "tests/Feature/HealthSafety/HsGovernanceTest.php:220-315"),
    ("RUN-005", "Health & Safety", "H", "CAP-HS-FIRST-AID-REGISTER", "Record first aid, follow-up, evidence, and linked incidents", "FirstAidController", "app/Http/Controllers/HealthSafety/FirstAidController.php:55-115", "tests/Feature/HealthSafety/FirstAidControllerTest.php:84-148"),
    ("RUN-005", "Health & Safety", "H", "CAP-HS-RESTRAINT-REGISTER", "Record restraint, support-plan, review, and linked evidence", "RestraintController", "app/Http/Controllers/HealthSafety/RestraintController.php:448-883", "tests/Feature/HealthSafety/RestraintRegisterTest.php:151-238"),
    ("RUN-005", "Health & Safety", "H", "CAP-HS-SAFE-WORK-PROCEDURES", "Draft, approve, acknowledge, version, and archive procedures", "SafeWorkProcedureController", "app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:143-338", "tests/Feature/HealthSafety/SafeWorkProcedureTest.php:118-208"),
    ("RUN-005", "Health & Safety", "H", "CAP-HS-WORKER-PARTICIPATION", "Manage representatives, committees, meetings, and consultation evidence", "WorkerParticipationController", "app/Http/Controllers/HealthSafety/WorkerParticipationController.php:63-147", "tests/Feature/HealthSafety/WorkerParticipationTest.php:149-254"),
    ("RUN-005", "Health & Safety", "H", "CAP-HS-HAZARDOUS-SUBSTANCES-SDS", "Maintain substances, SDS history, storage, exposure, and escalation", "HazardousSubstanceController", "app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:211-435", "tests/Feature/HealthSafety/HazardousSubstanceControllerTest.php:138-220"),
    ("RUN-005", "Health & Safety", "H", "CAP-HS-EMERGENCY-DRILLS", "Schedule, execute, evidence, and review emergency drills", "EmergencyDrillController and DrillComplianceService", "app/Http/Controllers/HealthSafety/EmergencyDrillController.php:49-170", "tests/Feature/HealthSafety/EmergencyDrillTest.php:103-216"),
    ("RUN-005", "Health & Safety", "H", "CAP-HS-WORKPLACE-INJURY-RTW", "Record injury and govern return-to-work plans and capacity", "ReturnToWorkController and WorkplaceInjuryJourneyService", "app/Http/Controllers/HealthSafety/ReturnToWorkController.php:485-837", "tests/Feature/HealthSafety/InjuriesControllerTest.php:150-259"),
    ("RUN-005", "Health & Safety", "H", "CAP-HS-LONE-WORKER-SAFETY", "Monitor lone-worker sessions, check-ins, location, and alerts", "LoneWorkerController and LoneWorkerSignalService", "app/Http/Controllers/HealthSafety/LoneWorkerController.php:227-778", "tests/Feature/HealthSafety/LoneWorkerSiteAccessTest.php:30-158"),
    ("RUN-005", "Health & Safety", "H", "CAP-HS-PPE-REGISTER", "Manage PPE inventory, allocations, inspection, and disposal evidence", "PpeController", "app/Http/Controllers/HealthSafety/PpeController.php:37-112", "tests/Feature/HealthSafety/PpeRegisterTest.php:179-267"),
    ("RUN-005", "Privacy", "H", "CAP-PRIV-DASHBOARD-WORKLIST", "Review privacy requests, breaches, holds, retention, and deadlines", "PrivacyDashboardController", "app/Http/Controllers/PrivacyDashboardController.php:35-67", "tests/Feature/PrivacyControllerTest.php:1754-1769"),
    ("RUN-005", "Privacy", "H", "CAP-PRIV-DSR-LIFECYCLE", "Intake, verify, assign, extend, complete, or refuse a DSR", "DataSubjectRequestLifecycleService", "app/Domain/Privacy/Services/DataSubjectRequestLifecycleService.php:74-284", "tests/Feature/Privacy/DataSubjectRequestLifecycleTest.php:59-103"),
    ("RUN-005", "Privacy", "D", "CAP-PRIV-DSR-DATA-EXPORT", "Produce a governed linked-subject privacy export", "DataSubjectRequestController export", "app/Http/Controllers/DataSubjectRequestController.php:274-397", "tests/Feature/PrivacyControllerTest.php:2574-2655"),
    ("RUN-005", "Privacy", "H", "CAP-PRIV-RETENTION-POLICY-EXECUTION", "Define, preview, approve, execute, and evidence retention", "RetentionExecutionService and RetentionOwnerRegistry", "app/Domain/Privacy/Retention/RetentionExecutionService.php:30-125", "tests/Feature/Privacy/EnforceDataRetentionJobTest.php:65-159"),
    ("RUN-005", "Privacy", "H", "CAP-PRIV-LEGAL-HOLD", "Create, review, and release legal holds", "LegalHoldController and retention enforcement", "app/Http/Controllers/LegalHoldController.php:17-152", "tests/Feature/PrivacyControllerTest.php:1142-1215"),
    ("RUN-005", "Privacy", "H", "CAP-PRIV-BREACH-LIFECYCLE", "Report, investigate, notify, and resolve privacy breaches", "DataBreachController", "app/Http/Controllers/DataBreachController.php:76-238", "tests/Feature/PrivacyControllerTest.php:1014-1028"),
    ("RUN-005", "Privacy", "H", "CAP-PRIV-PIA-LIFECYCLE", "Create, assess, review, and approve privacy impact assessments", "DPIAController", "app/Http/Controllers/DPIAController.php:68-188", "tests/Feature/PrivacyControllerTest.php:1280-1372"),
    ("RUN-005", "Privacy", "D", "CAP-PRIV-EVIDENCE-ATTACHMENTS", "Upload, download, and remove private privacy evidence", "PrivacyAttachmentController", "app/Http/Controllers/PrivacyAttachmentController.php:31-109", "tests/Feature/Privacy/PrivacyAttachmentTest.php:44-120"),
    ("RUN-005", "Privacy", "D", "CAP-PRIV-COMPLIANCE-REPORT-EXPORT", "View and export cross-domain privacy compliance reports", "PrivacyReportController", "app/Http/Controllers/PrivacyReportController.php:21-94", "tests/Feature/PrivacyControllerTest.php:1858-1924"),
    ("RUN-005", "Safeguarding", "H", "CAP-SAFE-CONCERN-INTAKE-TRIAGE", "Raise, assign, and triage a safeguarding concern", "SafeguardingConcernController and lifecycle owners", "app/Http/Controllers/SafeguardingConcernController.php:213-252", "tests/Feature/SafeguardingConcernControllerTest.php:228-301"),
    ("RUN-005", "Safeguarding", "H", "CAP-SAFE-SENSITIVITY-DECLASSIFICATION", "Restrict, preview, request, and decide governed declassification", "SafeguardingSensitivityService", "app/Services/Safeguarding/SafeguardingSensitivityService.php:35-287", "tests/Feature/Safeguarding/SafeguardingSensitivityGovernanceTest.php:113-235"),
    ("RUN-005", "Safeguarding", "H", "CAP-SAFE-INVESTIGATION-RISK", "Open investigations and record safeguarding risk", "Safeguarding investigation and risk controllers", "app/Http/Controllers/SafeguardingInvestigationController.php:15-62", "tests/Feature/Safeguarding/SafeguardingSubRecordTest.php:51-99"),
    ("RUN-005", "Safeguarding", "H", "CAP-SAFE-EXTERNAL-REPORT", "Record reports to external safeguarding authorities", "SafeguardingExternalReportController", "app/Http/Controllers/SafeguardingExternalReportController.php:16-62", "tests/Feature/Safeguarding/SafeguardingSubRecordTest.php:104-120"),
    ("RUN-005", "Safeguarding", "H", "CAP-SAFE-EVIDENCE-ACTION-PLAN", "Manage need-to-know evidence and protective action plans", "Safeguarding attachment and action-plan controllers", "app/Http/Controllers/SafeguardingAttachmentController.php:21-75", "tests/Feature/Safeguarding/SafeguardingNestedAuthorizationTest.php:94-175"),
    ("RUN-005", "Safeguarding", "H", "CAP-SAFE-TERMINAL-PROJECTION", "Complete a safeguarding terminal transition across linked owners", "SafeguardingTerminalTransitionService", "app/Services/Safeguarding/SafeguardingTerminalTransitionService.php:45-225", "tests/Feature/Safeguarding/SafeguardingTerminalTransitionTest.php:37-168"),
    ("RUN-005", "Complaints & Feedback", "H", "CAP-COMPLAINT-SITE-FEEDBACK", "Record and respond to Site complaints or feedback", "SiteComplianceController", "app/Http/Controllers/Sites/SiteComplianceController.php:415-548", "tests/Feature/Sites/SiteComplianceWorkflowTest.php:287-359"),
    ("RUN-005", "Complaints & Feedback", "H", "CAP-COMPLAINT-RESPITE-STAY", "Record respite complaints and HDC escalation state", "RespiteStayController and evidence-pack owner", "app/Http/Controllers/Respite/RespiteStayController.php:544-574", "tests/Feature/Respite/RespiteNzWorkflowCompletionTest.php:319-370"),
    ("RUN-005", "Complaints & Feedback", "H", "CAP-COMPLAINT-HR-CASEWORK", "Manage confidential grievance and complaint casework", "HrCaseController and HrCaseAccessService", "app/Http/Controllers/Hr/HrCaseController.php:359-375", "tests/Feature/Hr/AuditFixPayrollCasesTest.php:238-257"),
    ("RUN-005", "Complaints & Feedback", "M", "CAP-WHISTLE-PROTECTED-DISCLOSURE", "Govern confidential protected disclosures and anti-retaliation evidence", "No dedicated current-source owner found", "app/Http/Controllers/Hr/HrCaseController.php:36-42", "tests/Feature/Hr/HrCasesPageContractTest.php:66-129"),

    ("RUN-006", "Sites & Locations", "H", "CAP-SITE-PROFILE-LIFECYCLE", "Create, edit, archive, and restore an operational Site", "SiteController, SiteProfileData, and UserSiteAccessService", "app/Services/Sites/SiteProfileData.php:30-102", "tests/Feature/Sites/SiteProfileAuthorizationTest.php:72-145"),
    ("RUN-006", "Sites & Locations", "H", "CAP-SITE-CALENDAR-RESOURCE-SCHEDULING", "Schedule, approve, and maintain Site events and resources", "SiteCalendarController and SiteCalendarService", "app/Services/Sites/SiteCalendarService.php:14-179", "tests/Feature/Sites/Calendar/SiteCalendarWorkflowTest.php:97-250"),
    ("RUN-006", "Sites & Locations", "H", "CAP-SITE-PLAN-ROOM-HARDWARE", "Maintain Site plans, rooms, zones, hardware, and emergency layout", "SiteTypePlanService and SitePhysicalRoomService", "app/Services/Sites/SitePhysicalRoomService.php:28-448", "tests/Feature/Sites/SitePhysicalRoomBridgeTest.php:48-221"),
    ("RUN-006", "Sites & Locations", "H", "CAP-SITE-CHECKLIST-HAZARD-COMPLIANCE", "Complete Site checklists, hazards, inspections, and compliance evidence", "SiteChecklistRunExecutionService and compliance owners", "app/Services/Sites/SiteChecklistRunExecutionService.php:37-200", "tests/Feature/Checklists/ChecklistRunOwnershipTest.php:231-448"),
    ("RUN-006", "Sites & Locations", "H", "CAP-SITE-VAULT-VENDOR-LEDGER", "Manage Site vendors, credentials, contacts, and ledger entries", "SiteCredentialController and HouseLedgerService", "app/Http/Controllers/Sites/SiteCredentialController.php:38-469", "tests/Feature/Sites/SiteAccessVaultCanonicalAccessTest.php:63-166"),
    ("RUN-006", "Sites & Locations", "D", "CAP-SITE-REPORTING-EXPORT", "Review and export Site, facility, checklist, and vendor reports", "SiteReportingController", "app/Http/Controllers/Sites/SiteReportingController.php:153-432", "tests/Feature/SitesModuleIntegrationTest.php:435"),
    ("RUN-006", "Fleet & Assets", "H", "CAP-FLEET-ASSET-VEHICLE-REGISTER", "Maintain asset and vehicle registers with custody provenance", "Asset and Vehicle controllers with AssetMutationIntegrityService", "app/Services/Assets/AssetMutationIntegrityService.php:14-61", "tests/Feature/FleetAssets/AssetMutationBoundaryTest.php:49-342"),
    ("RUN-006", "Fleet & Assets", "H", "CAP-FLEET-VEHICLE-BOOKING", "Book, approve, check out, return, reject, or cancel a vehicle", "VehicleBookingController and VehicleBookingAccessService", "app/Services/Fleet/VehicleBookingAccessService.php:30-127", "tests/Feature/FleetAssets/VehicleBookingSitePrivacyTest.php:139-353"),
    ("RUN-006", "Fleet & Assets", "H", "CAP-FLEET-MAINTENANCE-COMPLIANCE", "Run checks, inspections, maintenance, and work orders", "WorkOrderController and fleet maintenance owners", "app/Http/Controllers/FleetAssets/WorkOrderController.php:170-248", "tests/Feature/FleetAssets/FleetMaintenanceWiringTest.php:39-113"),
    ("RUN-006", "Fleet & Assets", "H", "CAP-FLEET-KEY-HANDOVER", "Transfer keys and create, accept, or dispute handover", "KeyController and HandoverController", "app/Http/Controllers/FleetAssets/HandoverController.php:195-506", "tests/Feature/FleetAssets/FleetHandoverMutationIntegrityTest.php:36-124"),
    ("RUN-006", "Fleet & Assets", "H", "CAP-FLEET-RESIDENT-TRANSPORT", "Run resident transport and govern medication custody in transit", "ResidentTransportJourneyService and scope", "app/Services/Fleet/ResidentTransportJourneyService.php:47-431", "tests/Feature/FleetAssets/ResidentTransportJourneySecurityTest.php:146-832"),
    ("RUN-006", "Fleet & Assets", "H", "CAP-FLEET-TRACKING-GEOFENCE-REALTIME", "View consented resident tracking, geofences, and panic state", "ResidentTrackingController and realtime authorization owners", "app/Http/Controllers/FleetAssets/ResidentTrackingController.php:575-986", "tests/Feature/FleetAssets/FleetRealtimePrivacyTest.php:40-154"),
    ("RUN-006", "Fleet & Assets", "H", "CAP-FLEET-INCIDENT-OUTING-MILEAGE", "Record fleet incidents, outings, return, and mileage outcomes", "Fleet incident, outing, and mileage controllers", "routes/fleet-assets.php:274-330", "tests/Feature/FleetAssets/FleetIncidentTest.php:55-140"),
    ("RUN-006", "Fleet & Assets", "D", "CAP-FLEET-REPORTING-EXPORT", "Review and export fleet trips, maintenance, cost, and access reports", "FleetAssets ReportController", "app/Http/Controllers/FleetAssets/ReportController.php:24-648", "tests/Unit/FleetAssets/FleetReportPeriodTest.php:19-46"),
    ("RUN-006", "Security Devices", "H", "CAP-SEC-DEVICE-REGISTRY-CUSTODY", "Register, assign, release, link, and decommission devices", "DeviceRegistryService and SecurityDevicesAccessService", "app/Domain/SecurityDevices/Services/SecurityDevicesAccessService.php:35-1272", "tests/Feature/SecurityDevices/DeviceCustodyAuthorizationTest.php:33-137"),
    ("RUN-006", "Security Devices", "H", "CAP-SEC-GROUP-TOPOLOGY", "Build device groups, rules, links, and topology", "DeviceGroupController and relationship services", "app/Domain/SecurityDevices/Services/DeviceGroupAutoRuleService.php:50-214", "tests/Feature/SecurityDevices/DeviceGroupControllerTest.php:110-160"),
    ("RUN-006", "Security Devices", "H", "CAP-SEC-GOVERNED-COMMAND", "Request, approve, dispatch, and reconcile device commands", "DeviceManagementAuthorizationService and GovernedCommandDispatchService", "app/Domain/SecurityDevices/Management/Services/GovernedCommandDispatchService.php:48-508", "tests/Feature/SecurityDevices/DeviceManagementAuthorizationTest.php:65-266"),
    ("RUN-006", "Security Devices", "H", "CAP-SEC-ACCESS-CONTROL", "Version access schedules and issue or revoke credentials", "AccessControlLifecycleService", "app/Domain/SecurityDevices/AccessControl/Services/AccessControlLifecycleService.php:26-378", "tests/Feature/SecurityDevices/AccessControlWorkflowTest.php:144-451"),
    ("RUN-006", "Security Devices", "H", "CAP-SEC-MONITORING-POLICY", "Author monitors, coverage, maintenance, and retention policies", "NativeMonitoringDefinitionService and monitoring policy owners", "app/Domain/Monitoring/Services/NativeMonitoringDefinitionService.php:86-282", "tests/Feature/Monitoring/MonitoringPolicyAuthoringServiceTest.php:27-312"),
    ("RUN-006", "Security Devices", "H", "CAP-SEC-DISCOVERY-COLLECTOR", "Define discovery scope and govern Site-scoped collectors", "Discovery services and MonitoringCollectorLifecycleController", "app/Domain/Monitoring/Services/NativeMonitoringDefinitionService.php:240-282", "tests/Feature/Monitoring/DiscoveryRunTest.php:135-482"),
    ("RUN-006", "Security Devices", "H", "CAP-SEC-PROVIDER-INTEGRATIONS", "Configure UniFi, Queclink, and Milesight provider workflows", "Integration controllers and IntegrationSecretManager", "app/Services/Integration/IntegrationSecretManager.php:41-600", "tests/Feature/Integrations/UnifiTransportSecurityTest.php:51-166"),
    ("RUN-006", "Security Devices", "D", "CAP-SEC-REPORTING-EXPORT", "Export visible device, event, and maintenance registers", "SecurityDevices ReportsController", "app/Domain/SecurityDevices/Http/Controllers/ReportsController.php:65-237", "tests/Feature/SecurityDevices/ReportsExportTest.php:47-190"),
    ("RUN-006", "IT & Support", "H", "CAP-IT-SELF-SERVICE-TICKET-KB", "Raise and track owned support work and use published knowledge", "ItTicketController, ItWorkAccessService, and policy", "app/Domain/It/Services/ItWorkAccessService.php:78-356", "tests/Feature/It/ItTicketAuthzTest.php:37-172"),
    ("RUN-006", "IT & Support", "H", "CAP-IT-AGENT-TICKET-WORKFLOW", "Triage, assign, merge, approve, and complete authorised IT work", "ItTicketTriageService and work-task services", "app/Domain/It/Services/ItTicketTriageService.php:51-397", "tests/Feature/It/ItWorkAccessControllerTest.php:113-409"),
    ("RUN-006", "IT & Support", "H", "CAP-IT-PROVISIONING-LIFECYCLE", "Create, approve, fulfil, fail, or cancel provisioning requests", "ItProvisioningRequestLifecycleService", "app/Domain/It/Services/ItProvisioningRequestLifecycleService.php:32-392", "tests/Feature/It/ItProvisioningTest.php:150-248"),
    ("RUN-006", "IT & Support", "H", "CAP-IT-CHANGE-PROBLEM-MAJOR-INCIDENT", "Manage service setup, changes, problems, and major incidents", "IT service, change, problem, and major-incident services", "app/Domain/It/Services/ItMajorIncidentService.php:33-397", "tests/Feature/It/ItWorkAccessControllerTest.php:441-477"),
    ("RUN-006", "IT & Support", "D", "CAP-IT-REPORTING-EXPORT", "Review and export scoped IT and reliability reports", "ItReportsController", "app/Http/Controllers/It/ItReportsController.php:40-712", "tests/Feature/It/ItReportsTest.php:93-266"),
    ("RUN-006", "Integrations", "D", "CAP-INT-INBOUND-PROVIDER-WEBHOOK", "Receive signed provider events and project them idempotently", "WebhookReceiverController and provider binding/projector services", "app/Http/Controllers/Api/WebhookReceiverController.php:41-222", "tests/Feature/Integrations/WebhookReceiverTest.php:51-298"),
    ("RUN-006", "Integrations", "D", "CAP-INT-SITE-PROVIDER-SYNC-SECRETS", "Configure Site provider connections, secrets, mappings, and sync", "SiteIntegrationController and IntegrationSecretManager", "app/Http/Controllers/Sites/SiteIntegrationController.php:94-603", "tests/Feature/Sites/SiteIntegrationMutationSafetyTest.php:74-189"),
    ("RUN-006", "Integrations", "D", "CAP-INT-ADMIN-CONNECTIONS", "Manage API keys, outbound webhooks, calendars, and mailboxes", "ApiSettingsController and integration settings owners", "routes/settings.php:201-219", "tests/Browser/Settings/ApiSettingsInteractionTest.php:22-116"),
    ("RUN-006", "Control Room", "H", "CAP-CR-ALERT-WORKLIST-LIFECYCLE", "Inspect, acknowledge, triage, resolve, close, or reopen alerts", "ControlRoomAlertAccessService and lifecycle service", "app/Services/ControlRoom/ControlRoomAlertLifecycleService.php:45-777", "tests/Feature/ControlRoom/ControlRoomAlertViewScopeTest.php:29-188"),
    ("RUN-006", "Control Room", "H", "CAP-CR-TASK-ESCALATION-MY-QUEUE", "Work follow-ups, alert tasks, escalations, and H&S transfer", "Control Room task/escalation owners", "routes/control-room.php:143-180", "tests/Feature/ControlRoom/ControlRoomNestedRecordAuthorizationTest.php:63-216"),
    ("RUN-006", "Control Room", "H", "CAP-CR-SHIFT-HANDOVER", "Prepare, hand over, accept, and acknowledge complete shift state", "ControlRoomHandoverScopeService and shift handover service", "app/Services/ControlRoom/ControlRoomHandoverScopeService.php:65-547", "tests/Feature/ControlRoom/ControlRoomShiftHandoverScopeTest.php:57-487"),
    ("RUN-006", "Control Room", "H", "CAP-CR-COMMUNICATION-COLLABORATION", "Broadcast, message, discuss, watch, and record time", "ControlRoomMessagingController and collaboration owners", "app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php:24-304", "tests/Feature/ControlRoom/ControlRoomMessagingAuthorizationTest.php:110-307"),
    ("RUN-006", "Control Room", "D", "CAP-CR-EVIDENCE-REPORT-SLA", "Build evidence packs and export scoped SLA reports", "ControlRoomEvidenceController and ControlRoomReportService", "app/Services/ControlRoom/ControlRoomReportService.php:38-641", "tests/Feature/ControlRoom/ControlRoomReportScopeTest.php:40-143"),
    ("RUN-006", "Control Room", "H", "CAP-CR-DEVICE-MAP-PLAYBOOK-SETTINGS", "Use live maps and manage playbooks, signals, queues, and recovery", "Device visibility, playbook, and settings owners", "routes/control-room.php:329-408", "tests/Feature/ControlRoom/ControlRoomOperationalSurfaceSiteIsolationTest.php:275-534"),
    ("RUN-006", "Public & Settings Platform", "H", "CAP-SET-PERSONAL-ACCOUNT-SECURITY", "Maintain own profile, password, preferences, and two-factor setup", "Profile, password, appearance, and two-factor controllers", "routes/settings.php:33-60", "tests/Feature/Settings/ProfileUpdateTest.php:9-117"),
    ("RUN-006", "Public & Settings Platform", "H", "CAP-SET-ACCESS-ROLE-USER-SSO", "Administer roles, users, sessions, membership, and SSO mappings", "Access, roles, users, and SSO controllers", "app/Http/Controllers/Settings/AccessController.php:53-271", "tests/Feature/System/AccessControlControllerTest.php:31-111"),
    ("RUN-006", "Public & Settings Platform", "H", "CAP-SET-ORG-NOTIFICATION-CONFIG", "Configure terminology, notifications, email, security, and modules", "Dedicated settings controllers", "app/Http/Controllers/Settings/NotificationPreferencesController.php:15-238", "tests/Feature/Settings/SecurityPolicyEnforcementTest.php:14-80"),
    ("RUN-006", "Public & Settings Platform", "D", "CAP-SET-DATA-PRIVACY-AUDIT", "Configure data governance and review or export audit history", "DataSettingsController and AuditLogSettingsController", "app/Http/Controllers/Settings/AuditLogSettingsController.php:14-118", "tests/Browser/Settings/AuditLogsInteractionTest.php:42-94"),
    ("RUN-006", "Public & Settings Platform", "H", "CAP-PUB-MARKETING-CONTACT-CAREERS", "Read public pages and submit contact or token-bound career forms", "Public routes, ContactController, and careers controllers", "routes/web.php:68-124", "tests/Feature/Hr/CareersPortalTest.php:15-143"),
]


def candidates() -> list[dict[str, object]]:
    result = []
    for ordinal, (assignment, module, feature_class, candidate_id, job, owner, production, test) in enumerate(ROWS, start=1):
        result.append(
            {
                "ordinal": ordinal,
                "assignment_id": assignment,
                "candidate_id": candidate_id,
                "module": module,
                "feature_class": feature_class,
                "user_job": job,
                "canonical_owner": owner,
                "production_anchors": [production],
                "representative_test_anchors": [test],
                "site_role_privacy_note": MODULE_BOUNDARIES[module],
                "adjudication_status": "GROUPED_DISCOVERY_CANDIDATE_NOT_FINAL_DENOMINATOR",
                "evidence_limit": "Static source and test-presence evidence only; no runtime, test execution, browser, benchmark, ease, release, or completion credit.",
            }
        )
    return result


CANDIDATES = candidates()
CLASS_COUNTS = Counter(row["feature_class"] for row in CANDIDATES)
MODULE_COUNTS = Counter(row["module"] for row in CANDIDATES)
ASSIGNMENT_COUNTS = Counter(row["assignment_id"] for row in CANDIDATES)

assert len(CANDIDATES) == 110
assert CLASS_COUNTS == Counter({"H": 91, "D": 18, "M": 1})
assert ASSIGNMENT_COUNTS == Counter({"RUN-004": 33, "RUN-005": 36, "RUN-006": 41})
assert len({row["candidate_id"] for row in CANDIDATES}) == 110


PROVISIONAL_FINDINGS = [
    {
        "finding_id": "GOV-EXECUTIVE-VISIBILITY-01",
        "provisional_severity": "P1",
        "source_claim": "Meeting and resolution index, calendar, and direct reads do not visibly enforce executive-session or committee visibility for broad governance viewers.",
        "anchors": ["app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:41-152", "app/Domain/Governance/Http/Controllers/ResolutionController.php:26-84"],
        "required_gate": "Independent policy review and negative direct-ID, calendar, committee, executive-session, picker, and attachment tests.",
    },
    {
        "finding_id": "GOV-BOARD-PACK-VISIBILITY-01",
        "provisional_severity": "P1",
        "source_claim": "Board-pack list, show, manifest, attachment metadata, and read tracking appear broader than the explicit recipient distribution boundary.",
        "anchors": ["app/Domain/Governance/Http/Controllers/BoardPackController.php:23-90", "app/Domain/Governance/Http/Controllers/BoardPackController.php:194-247"],
        "required_gate": "Independent policy review and recipient/non-recipient negative tests including executive packs and supplementary attachments.",
    },
    {
        "finding_id": "GOV-RESOLUTION-QUORUM-01",
        "provisional_severity": "P1",
        "source_claim": "Resolution eligibility, conflicts, quorum, final vote, and close outcome are not visibly bound to one canonical locked decision snapshot.",
        "anchors": ["app/Domain/Governance/Services/VotingService.php:29-187", "app/Domain/Governance/Models/Resolution.php:186-212"],
        "required_gate": "Independent design review plus sequential and concurrent eligibility, conflict, quorum, vote-close, replay, and immutable-evidence tests.",
    },
    {
        "finding_id": "HS-REGISTER-SITE-SCOPE-01",
        "provisional_severity": "P1",
        "source_claim": "First aid, worker participation, hazardous substances, emergency drills, and PPE appear to use optional Site filters rather than approved-Site scope on all list, picker, direct-object, and write paths.",
        "anchors": ["app/Http/Controllers/HealthSafety/FirstAidController.php:55-85", "app/Http/Controllers/HealthSafety/WorkerParticipationController.php:63-147", "app/Http/Controllers/HealthSafety/PpeController.php:37-112"],
        "required_gate": "Independent per-controller review and representative foreign-Site list, picker, direct-ID, export, and write denial tests.",
    },
    {
        "finding_id": "PRIV-REPORT-DOMAIN-RBAC-01",
        "provisional_severity": "P1",
        "source_claim": "Privacy reports appear to expose breach, retention, legal-hold, and PIA aggregates and exports with privacy.viewRequests rather than their distinct domain permissions.",
        "anchors": ["routes/privacy.php:145-154", "app/Http/Controllers/PrivacyReportController.php:21-174"],
        "required_gate": "Independent field-flow review and a per-report/per-export capability denial matrix.",
    },
    {
        "finding_id": "SAFE-INTAKE-CANONICAL-SCOPE-01",
        "provisional_severity": "P1",
        "source_claim": "Safeguarding intake and update appear to trust submitted Site, person, and incident identifiers without canonical reconciliation before downstream projection.",
        "anchors": ["app/Http/Controllers/SafeguardingConcernController.php:217-252", "app/Http/Controllers/SafeguardingConcernController.php:1090-1127"],
        "required_gate": "Confirm reporter product policy and run adversarial foreign-Site, person, incident, update, and projection tests.",
    },
    {
        "finding_id": "SAFE-ALERT-DEDUP-IDENTITY-01",
        "provisional_severity": "P1",
        "source_claim": "Control Room alert deduplication appears able to collapse distinct safeguarding concerns because concern identity is not part of the generic deduplication key.",
        "anchors": ["app/Services/ControlRoom/ComprehensiveAlertBridgeService.php:116-136", "app/Services/ControlRoom/ComprehensiveAlertBridgeService.php:249-301"],
        "required_gate": "Create distinct same-client and personless concerns within the window and prove distinct concern-owned alerts and custody chains.",
    },
    {
        "finding_id": "SAFE-PROJECTION-DURABILITY-01",
        "provisional_severity": "P1",
        "source_claim": "Safeguarding intake projections are after-commit catch-and-log operations, while the inspected recovery owner does not visibly include safeguarding sources.",
        "anchors": ["app/Observers/SafeguardingConcernObserver.php:19-35", "app/Observers/SafeguardingConcernObserver.php:166-249", "app/Services/ControlRoom/SafetySignalDeliveryRecoveryService.php:26-75"],
        "required_gate": "Inject H&S and Control Room projection failures, verify durable retry/reconciliation ownership, and assert idempotent recovery.",
    },
    {
        "finding_id": "SET-API-WEBHOOK-DESTINATION-01",
        "provisional_severity": "P1",
        "source_claim": "API webhook testing appears to issue server-side POST, HEAD, and GET requests to administrator-supplied URLs without the repository-native public-address and redirect destination policy.",
        "anchors": ["app/Http/Controllers/Settings/ApiSettingsController.php:132-211", "app/Http/Controllers/Settings/ApiSettingsController.php:321-360", "app/Domain/Hr/Services/HrWebhookDestinationPolicy.php:59-151"],
        "required_gate": "Independent security review plus authorized loopback, private, reserved, metadata, redirect, DNS-rebinding, and egress-control tests.",
    },
]

for finding in PROVISIONAL_FINDINGS:
    finding["status"] = "PROVISIONAL_REQUIRES_INDEPENDENT_CURRENT_SOURCE_AND_MATCHING_RUNTIME_REVIEW"


FEATURE_PAYLOAD = {
    "schema_version": 1,
    "status": "DISCOVERY_WAVE_02_PARTIAL_NOT_CANONICAL",
    "generated_at": GENERATED_AT,
    "source": {
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
        "audit_input_commit": AUDIT_INPUT_COMMIT,
        "non_audit_product_diff_from_application_commit": 0,
    },
    "candidate_count": len(CANDIDATES),
    "class_counts": dict(sorted(CLASS_COUNTS.items())),
    "module_counts": dict(sorted(MODULE_COUNTS.items())),
    "assignment_counts": dict(sorted(ASSIGNMENT_COUNTS.items())),
    "cumulative_discovery_counts": {
        "candidate_count": 172,
        "class_counts": {"H": 145, "D": 26, "M": 1},
        "rule": "Wave 01 plus Wave 02 grouped rows; still not a frozen canonical feature denominator.",
    },
    "candidates": CANDIDATES,
    "provisional_findings": PROVISIONAL_FINDINGS,
    "limits": [
        "Wave 02 adds 110 grouped candidates; it does not freeze a feature denominator.",
        "The one M row is a bounded negative protected-disclosure search, not proof that no external process exists.",
        "The nine P1 claims remain provisional until independent review and their exact Site, role, direct-object, failure, transaction, or concurrency gates.",
        "No runtime, test execution, browser, benchmark, task/ease, release, remediation, closure, or all-pass credit is awarded.",
    ],
}


ASSIGNMENTS = [
    {
        "assignment_id": "RUN-004",
        "scope": "Finance and Governance grouped capability discovery",
        "candidate_count": 33,
        "class_counts": {"H": 28, "D": 5, "M": 0},
        "evidence_count": 99,
        "provisional_findings": 3,
        "observed_head": "c08b216e92f4689e277db4052c138a549bf86e8c",
        "completion_test_met": True,
        "wrote_files": False,
        "runtime_gates": None,
        "root_reconciliation": "Route and source ownership are locators only; unsupported consolidation remains quarantined; three governance privacy/quorum claims are provisional.",
    },
    {
        "assignment_id": "RUN-005",
        "scope": "Health and Safety, Privacy, Safeguarding, and Complaints grouped capability discovery",
        "candidate_count": 36,
        "class_counts": {"H": 31, "D": 4, "M": 1},
        "evidence_count": 108,
        "provisional_findings": 5,
        "observed_head": "c08b216e92f4689e277db4052c138a549bf86e8c",
        "completion_test_met": True,
        "wrote_files": False,
        "runtime_gates": None,
        "root_reconciliation": "Five Site/RBAC/provenance/deduplication/durability claims remain provisional; protected-disclosure is a bounded negative search, not a final absence claim.",
    },
    {
        "assignment_id": "RUN-006",
        "scope": "Sites, Fleet, Security Devices, IT, Integrations, Control Room, and Public/Settings grouped capability discovery",
        "candidate_count": 41,
        "class_counts": {"H": 32, "D": 9, "M": 0},
        "evidence_count": 129,
        "provisional_findings": 1,
        "observed_head": "c08b216e92f4689e277db4052c138a549bf86e8c",
        "completion_test_met": True,
        "wrote_files": False,
        "runtime_gates": None,
        "root_reconciliation": "Provider and settings routes do not prove destination safety; the outbound webhook claim is provisional and no network request was executed.",
    },
]

for assignment in ASSIGNMENTS:
    assignment["normalized_payload_sha256"] = digest(assignment)


AGENT_PAYLOAD = {
    "schema_version": 1,
    "status": "FORMAL_SOURCE_WAVE_02_RECONCILED_AUDIT_INCOMPLETE",
    "generated_at": GENERATED_AT,
    "application_commit": APPLICATION_COMMIT,
    "writer_boundary": "Only the root orchestrator wrote audit artifacts; RUN-004 through RUN-006 returned evidence in messages and reported wrote_files=false.",
    "wave_formal_assignments_eligible": 3,
    "cumulative_formal_assignments_eligible": 6,
    "literal_prompt_minimum": 8,
    "literal_prompt_minimum_met": False,
    "planned_formal_assignments_target": 11,
    "planned_target_met": False,
    "all_returned": True,
    "all_completion_tests_met": True,
    "all_reported_no_writes": True,
    "assignment_returns": ASSIGNMENTS,
    "finalization_gate": False,
}


MODULE_MAP = f"""# Current-source module and capability map — discovery wave 02

Status: **PARTIAL — grouped source discovery, not a canonical feature denominator**

Application source: `{APPLICATION_COMMIT}`

RUN-004 through RUN-006 add 110 grouped candidates: 91 H, 18 D, and one bounded-negative M candidate. Combined with wave 01, the current discovery register contains 172 rows: 145 H, 26 D, and one M. These are discovery rows only.

| Module family | H | D | M | Total |
|---|---:|---:|---:|---:|
"""

for module in MODULE_BOUNDARIES:
    rows = [row for row in CANDIDATES if row["module"] == module]
    counts = Counter(row["feature_class"] for row in rows)
    MODULE_MAP += f"| {module} | {counts.get('H', 0)} | {counts.get('D', 0)} | {counts.get('M', 0)} | {len(rows)} |\n"

MODULE_MAP += """

## Candidate register

| # | ID | Module | Class | User job | Source owner |
|---:|---|---|:---:|---|---|
"""

for row in CANDIDATES:
    MODULE_MAP += f"| {row['ordinal']} | `{row['candidate_id']}` | {row['module']} | {row['feature_class']} | {row['user_job']} | {row['canonical_owner']} |\n"

MODULE_MAP += """

## Provisional source findings

Nine new P1 source claims were retained for independent adjudication: three Governance, five Health & Safety/Privacy/Safeguarding, and one outbound integration destination claim. None is a final finding, verified exploit, remediated issue, or closed gate.

## Evidence boundary

- Static production and test anchors are locators; tests were not run.
- Single-tenant/multi-Site conclusions use approved Site scope, exact action capability, canonical ownership, concealed direct IDs, and privacy—not tenant isolation.
- Current grouped rows receive no benchmark mapping, task/ease, journey, viewport, runtime, release, or Pass 1–8 completion credit.
"""


def main() -> None:
    write_json("evidence/source/current-feature-discovery-wave-02.json", FEATURE_PAYLOAD)
    write_json("evidence/source/formal-source-wave-02-agent-register.json", AGENT_PAYLOAD)
    write_text("02-repository-module-map-wave-02.md", MODULE_MAP)


if __name__ == "__main__":
    main()
