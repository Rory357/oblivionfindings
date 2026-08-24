# Current-source module and capability map — discovery wave 02

Status: **PARTIAL — grouped source discovery, not a canonical feature denominator**

Application source: `a0493442b9e392d324055c35bf25b69421dc2d35`

RUN-004 through RUN-006 add 110 grouped candidates: 91 H, 18 D, and one bounded-negative M candidate. Combined with wave 01, the current discovery register contains 172 rows: 145 H, 26 D, and one M. These are discovery rows only.

| Module family | H | D | M | Total |
|---|---:|---:|---:|---:|
| Finance | 12 | 2 | 0 | 14 |
| Governance | 16 | 3 | 0 | 19 |
| Health & Safety | 16 | 1 | 0 | 17 |
| Privacy | 6 | 3 | 0 | 9 |
| Safeguarding | 6 | 0 | 0 | 6 |
| Complaints & Feedback | 3 | 0 | 1 | 4 |
| Sites & Locations | 5 | 1 | 0 | 6 |
| Fleet & Assets | 7 | 1 | 0 | 8 |
| Security Devices | 7 | 1 | 0 | 8 |
| IT & Support | 4 | 1 | 0 | 5 |
| Integrations | 0 | 3 | 0 | 3 |
| Control Room | 5 | 1 | 0 | 6 |
| Public & Settings Platform | 4 | 1 | 0 | 5 |


## Candidate register

| # | ID | Module | Class | User job | Source owner |
|---:|---|---|:---:|---|---|
| 1 | `CAP-FIN-DASHBOARD-INSIGHTS` | Finance | H | Review organisation, Site, and Client financial position and obligations | FinancialInsightsScopeResolver and finance dashboard aggregators |
| 2 | `CAP-FIN-LEDGER-CHART-PERIODS` | Finance | H | Maintain ledger charts, periods, centres, streams, and currencies | ChartOfAccountsService and ledger administration controllers |
| 3 | `CAP-FIN-JOURNAL-POST-REVERSE-RECUR` | Finance | H | Draft, post, reverse, and review manual or recurring journals | JournalPostingService and RecurringJournalService |
| 4 | `CAP-FIN-AP-VENDOR-PO-BILL-CREDIT` | Finance | H | Manage suppliers, purchase orders, bills, credit notes, and approvals | AccountsPayableService and AP controllers |
| 5 | `CAP-FIN-PAYMENT-RUN-SETTLEMENT` | Finance | H | Prepare, approve, export, settle, and reconcile supplier payment runs | PaymentRunService and PaymentSettlementRecorder |
| 6 | `CAP-FIN-AR-QUOTE-INVOICE-BILLING` | Finance | H | Manage quotes, billing entries, invoices, statements, and receipts | QuoteLifecycleService and AccountsReceivableService |
| 7 | `CAP-FIN-ALLOCATION-MATCH-HISTORY` | Finance | H | Review allocation history and confirm or reject payment matches | PaymentMatchingService and settlement owners |
| 8 | `CAP-FIN-BANK-FEED-RECON-EFTPOS` | Finance | H | Import bank activity, reconcile statements, and settle EFTPOS batches | BankReconciliationService, BankFeedService, and EftposReconciliationService |
| 9 | `CAP-FIN-GST-IRD-COMPLIANCE` | Finance | H | Prepare, file, and amend GST and IRD obligations | GstReturnService, IrdFilingService, and NzComplianceService |
| 10 | `CAP-FIN-FIXED-ASSET-LIFECYCLE` | Finance | H | Register, capitalise, depreciate, and dispose of fixed assets | FixedAssetService |
| 11 | `CAP-FIN-CLIENT-PETTY-DONOR-FUNDS` | Finance | H | Govern Client money, petty cash, and restricted donor funds | ClientFundTransactionService, PettyCashService, and DonorFundService |
| 12 | `CAP-FIN-BUDGET-ACTUAL-FORECAST` | Finance | H | Compare budgets to actuals and create cash-flow forecasts | BudgetActualsService, BudgetVarianceService, and CashFlowForecastService |
| 13 | `CAP-FIN-REPORT-AUDIT-EXPORT` | Finance | D | Produce financial statements, list exports, and encrypted audit packs | FinancialReportService and AuditExportService |
| 14 | `CAP-FIN-ACCOUNTING-SYNC-FX-CONSOLIDATION` | Finance | D | Configure accounting sync, FX revaluation, and supported consolidation | Accounting providers, FxRevaluationService, ConsolidationService, and IntercompanyService |
| 15 | `CAP-GOV-DASHBOARD-REPORT-EVIDENCE` | Governance | D | Review board, committee, risk, compliance, and evidence-pack reporting | DashboardAggregatorService, ReportController, and AuditEvidencePackService |
| 16 | `CAP-GOV-MEETING-AGENDA-MINUTES-ATTENDANCE` | Governance | H | Schedule meetings and manage agendas, attendance, minutes, and status | GovernanceMeetingController and governance workflow services |
| 17 | `CAP-GOV-BOARD-PACK-DISTRIBUTION` | Governance | H | Generate, distribute, read, and download board packs | BoardPackBuilderService and BoardPackController |
| 18 | `CAP-GOV-RESOLUTION-VOTE-QUORUM` | Governance | H | Draft resolutions, declare conflicts, vote, enforce quorum, and close outcomes | VotingService and ResolutionController |
| 19 | `CAP-GOV-BOARD-MEMBER-INTEREST-EVALUATION` | Governance | H | Administer board membership, interests, and evaluations | Board member, interest, and evaluation controllers |
| 20 | `CAP-GOV-RISK-REGISTER-TREATMENT` | Governance | H | Score risks, add treatments, accept, and close records | RiskRegisterController and RiskScoringService |
| 21 | `CAP-GOV-COMPLIANCE-OBLIGATION-EVIDENCE` | Governance | H | Manage compliance obligations, evidence, and notifiable records | ComplianceController and ComplianceEngineService |
| 22 | `CAP-GOV-POLICY-VERSION-ATTESTATION` | Governance | H | Draft, approve, version, and attest governance policies | GovernancePolicyController and policy |
| 23 | `CAP-GOV-ACTION-ITEM-WORKFLOW` | Governance | H | Create, progress, block, complete, and escalate governance actions | ActionItemController and GovernanceWorkflowService |
| 24 | `CAP-GOV-STRATEGY-PLAN-GOALS` | Governance | H | Create strategic plans, goals, versions, and approvals | StrategicPlanController and policy |
| 25 | `CAP-GOV-BUDGET-ALLOCATIONS-ADJUSTMENTS` | Governance | H | Manage governance budgets, allocations, actuals, and approvals | BudgetController and GovernanceNestedMutationService |
| 26 | `CAP-GOV-CEO-BOARD-REPORT` | Governance | H | Draft, submit, present, and evidence CEO board reports | CeoBoardReportController and policy |
| 27 | `CAP-GOV-SPEND-APPROVAL` | Governance | H | Request, decide, and evidence board or committee spend approval | SpendApprovalCommandService |
| 28 | `CAP-GOV-PERFORMANCE-REVIEW` | Governance | H | Run board and CEO performance reviews and approvals | PerformanceReviewService and controller |
| 29 | `CAP-GOV-DOCUMENT-LIBRARY` | Governance | H | Store, browse, download, and remove governance documents | GovernanceDocumentController and policy |
| 30 | `CAP-GOV-CLINICAL-INDICATOR-SNAPSHOT` | Governance | H | Review clinical governance indicators and record snapshots | ClinicalGovernanceController and automation service |
| 31 | `CAP-GOV-TE-TIRITI-OBLIGATION` | Governance | H | Record and maintain Te Tiriti governance obligations | TeTiritiController |
| 32 | `CAP-GOV-AUDIT-LOG-EXPORT` | Governance | D | Review and export cross-module governance audit evidence | GovernanceAuditLogController and GovernanceAuditService |
| 33 | `CAP-GOV-SETTINGS-CONTROL` | Governance | D | Configure governance escalation, spend, and workflow settings | GovernanceSettingController |
| 34 | `CAP-HS-DASHBOARD-ANALYTICS` | Health & Safety | H | Review Site-scoped safety KPIs, trends, and attention worklists | HealthSafetyDashboardController and analytics services |
| 35 | `CAP-HS-EVENT-REGISTER-HANDOVER` | Health & Safety | H | Find safety events and accept incident handover | HsEventController and HsEventService |
| 36 | `CAP-HS-WORKSAFE-DECISION-NOTIFY-PRESERVE` | Health & Safety | H | Record notifiable decisions, WorkSafe notice, and Site preservation | HsEventService and HsWorksafeDecisionController |
| 37 | `CAP-HS-INVESTIGATION-ASSURANCE` | Health & Safety | H | Investigate, review, rework, and independently approve completion | HsInvestigationService |
| 38 | `CAP-HS-CORRECTIVE-ACTION-EVIDENCE` | Health & Safety | H | Create, complete, verify, close, and evidence corrective actions | HsCorrectiveActionService |
| 39 | `CAP-HS-EVENT-CLOSURE-EXCEPTIONS` | Health & Safety | H | Close safety events or approve narrow independent exceptions | HsEventClosureService |
| 40 | `CAP-HS-RISK-ASSESSMENT-LIFECYCLE` | Health & Safety | H | Create, review, activate, supersede, and archive risk assessments | HsRiskAssessmentController and service |
| 41 | `CAP-HS-GOVERNANCE-REPORTS-EXPORT` | Health & Safety | D | Produce board, WorkSafe, investigation, action, and risk evidence views | HsGovernanceReportController and export services |
| 42 | `CAP-HS-FIRST-AID-REGISTER` | Health & Safety | H | Record first aid, follow-up, evidence, and linked incidents | FirstAidController |
| 43 | `CAP-HS-RESTRAINT-REGISTER` | Health & Safety | H | Record restraint, support-plan, review, and linked evidence | RestraintController |
| 44 | `CAP-HS-SAFE-WORK-PROCEDURES` | Health & Safety | H | Draft, approve, acknowledge, version, and archive procedures | SafeWorkProcedureController |
| 45 | `CAP-HS-WORKER-PARTICIPATION` | Health & Safety | H | Manage representatives, committees, meetings, and consultation evidence | WorkerParticipationController |
| 46 | `CAP-HS-HAZARDOUS-SUBSTANCES-SDS` | Health & Safety | H | Maintain substances, SDS history, storage, exposure, and escalation | HazardousSubstanceController |
| 47 | `CAP-HS-EMERGENCY-DRILLS` | Health & Safety | H | Schedule, execute, evidence, and review emergency drills | EmergencyDrillController and DrillComplianceService |
| 48 | `CAP-HS-WORKPLACE-INJURY-RTW` | Health & Safety | H | Record injury and govern return-to-work plans and capacity | ReturnToWorkController and WorkplaceInjuryJourneyService |
| 49 | `CAP-HS-LONE-WORKER-SAFETY` | Health & Safety | H | Monitor lone-worker sessions, check-ins, location, and alerts | LoneWorkerController and LoneWorkerSignalService |
| 50 | `CAP-HS-PPE-REGISTER` | Health & Safety | H | Manage PPE inventory, allocations, inspection, and disposal evidence | PpeController |
| 51 | `CAP-PRIV-DASHBOARD-WORKLIST` | Privacy | H | Review privacy requests, breaches, holds, retention, and deadlines | PrivacyDashboardController |
| 52 | `CAP-PRIV-DSR-LIFECYCLE` | Privacy | H | Intake, verify, assign, extend, complete, or refuse a DSR | DataSubjectRequestLifecycleService |
| 53 | `CAP-PRIV-DSR-DATA-EXPORT` | Privacy | D | Produce a governed linked-subject privacy export | DataSubjectRequestController export |
| 54 | `CAP-PRIV-RETENTION-POLICY-EXECUTION` | Privacy | H | Define, preview, approve, execute, and evidence retention | RetentionExecutionService and RetentionOwnerRegistry |
| 55 | `CAP-PRIV-LEGAL-HOLD` | Privacy | H | Create, review, and release legal holds | LegalHoldController and retention enforcement |
| 56 | `CAP-PRIV-BREACH-LIFECYCLE` | Privacy | H | Report, investigate, notify, and resolve privacy breaches | DataBreachController |
| 57 | `CAP-PRIV-PIA-LIFECYCLE` | Privacy | H | Create, assess, review, and approve privacy impact assessments | DPIAController |
| 58 | `CAP-PRIV-EVIDENCE-ATTACHMENTS` | Privacy | D | Upload, download, and remove private privacy evidence | PrivacyAttachmentController |
| 59 | `CAP-PRIV-COMPLIANCE-REPORT-EXPORT` | Privacy | D | View and export cross-domain privacy compliance reports | PrivacyReportController |
| 60 | `CAP-SAFE-CONCERN-INTAKE-TRIAGE` | Safeguarding | H | Raise, assign, and triage a safeguarding concern | SafeguardingConcernController and lifecycle owners |
| 61 | `CAP-SAFE-SENSITIVITY-DECLASSIFICATION` | Safeguarding | H | Restrict, preview, request, and decide governed declassification | SafeguardingSensitivityService |
| 62 | `CAP-SAFE-INVESTIGATION-RISK` | Safeguarding | H | Open investigations and record safeguarding risk | Safeguarding investigation and risk controllers |
| 63 | `CAP-SAFE-EXTERNAL-REPORT` | Safeguarding | H | Record reports to external safeguarding authorities | SafeguardingExternalReportController |
| 64 | `CAP-SAFE-EVIDENCE-ACTION-PLAN` | Safeguarding | H | Manage need-to-know evidence and protective action plans | Safeguarding attachment and action-plan controllers |
| 65 | `CAP-SAFE-TERMINAL-PROJECTION` | Safeguarding | H | Complete a safeguarding terminal transition across linked owners | SafeguardingTerminalTransitionService |
| 66 | `CAP-COMPLAINT-SITE-FEEDBACK` | Complaints & Feedback | H | Record and respond to Site complaints or feedback | SiteComplianceController |
| 67 | `CAP-COMPLAINT-RESPITE-STAY` | Complaints & Feedback | H | Record respite complaints and HDC escalation state | RespiteStayController and evidence-pack owner |
| 68 | `CAP-COMPLAINT-HR-CASEWORK` | Complaints & Feedback | H | Manage confidential grievance and complaint casework | HrCaseController and HrCaseAccessService |
| 69 | `CAP-WHISTLE-PROTECTED-DISCLOSURE` | Complaints & Feedback | M | Govern confidential protected disclosures and anti-retaliation evidence | No dedicated current-source owner found |
| 70 | `CAP-SITE-PROFILE-LIFECYCLE` | Sites & Locations | H | Create, edit, archive, and restore an operational Site | SiteController, SiteProfileData, and UserSiteAccessService |
| 71 | `CAP-SITE-CALENDAR-RESOURCE-SCHEDULING` | Sites & Locations | H | Schedule, approve, and maintain Site events and resources | SiteCalendarController and SiteCalendarService |
| 72 | `CAP-SITE-PLAN-ROOM-HARDWARE` | Sites & Locations | H | Maintain Site plans, rooms, zones, hardware, and emergency layout | SiteTypePlanService and SitePhysicalRoomService |
| 73 | `CAP-SITE-CHECKLIST-HAZARD-COMPLIANCE` | Sites & Locations | H | Complete Site checklists, hazards, inspections, and compliance evidence | SiteChecklistRunExecutionService and compliance owners |
| 74 | `CAP-SITE-VAULT-VENDOR-LEDGER` | Sites & Locations | H | Manage Site vendors, credentials, contacts, and ledger entries | SiteCredentialController and HouseLedgerService |
| 75 | `CAP-SITE-REPORTING-EXPORT` | Sites & Locations | D | Review and export Site, facility, checklist, and vendor reports | SiteReportingController |
| 76 | `CAP-FLEET-ASSET-VEHICLE-REGISTER` | Fleet & Assets | H | Maintain asset and vehicle registers with custody provenance | Asset and Vehicle controllers with AssetMutationIntegrityService |
| 77 | `CAP-FLEET-VEHICLE-BOOKING` | Fleet & Assets | H | Book, approve, check out, return, reject, or cancel a vehicle | VehicleBookingController and VehicleBookingAccessService |
| 78 | `CAP-FLEET-MAINTENANCE-COMPLIANCE` | Fleet & Assets | H | Run checks, inspections, maintenance, and work orders | WorkOrderController and fleet maintenance owners |
| 79 | `CAP-FLEET-KEY-HANDOVER` | Fleet & Assets | H | Transfer keys and create, accept, or dispute handover | KeyController and HandoverController |
| 80 | `CAP-FLEET-RESIDENT-TRANSPORT` | Fleet & Assets | H | Run resident transport and govern medication custody in transit | ResidentTransportJourneyService and scope |
| 81 | `CAP-FLEET-TRACKING-GEOFENCE-REALTIME` | Fleet & Assets | H | View consented resident tracking, geofences, and panic state | ResidentTrackingController and realtime authorization owners |
| 82 | `CAP-FLEET-INCIDENT-OUTING-MILEAGE` | Fleet & Assets | H | Record fleet incidents, outings, return, and mileage outcomes | Fleet incident, outing, and mileage controllers |
| 83 | `CAP-FLEET-REPORTING-EXPORT` | Fleet & Assets | D | Review and export fleet trips, maintenance, cost, and access reports | FleetAssets ReportController |
| 84 | `CAP-SEC-DEVICE-REGISTRY-CUSTODY` | Security Devices | H | Register, assign, release, link, and decommission devices | DeviceRegistryService and SecurityDevicesAccessService |
| 85 | `CAP-SEC-GROUP-TOPOLOGY` | Security Devices | H | Build device groups, rules, links, and topology | DeviceGroupController and relationship services |
| 86 | `CAP-SEC-GOVERNED-COMMAND` | Security Devices | H | Request, approve, dispatch, and reconcile device commands | DeviceManagementAuthorizationService and GovernedCommandDispatchService |
| 87 | `CAP-SEC-ACCESS-CONTROL` | Security Devices | H | Version access schedules and issue or revoke credentials | AccessControlLifecycleService |
| 88 | `CAP-SEC-MONITORING-POLICY` | Security Devices | H | Author monitors, coverage, maintenance, and retention policies | NativeMonitoringDefinitionService and monitoring policy owners |
| 89 | `CAP-SEC-DISCOVERY-COLLECTOR` | Security Devices | H | Define discovery scope and govern Site-scoped collectors | Discovery services and MonitoringCollectorLifecycleController |
| 90 | `CAP-SEC-PROVIDER-INTEGRATIONS` | Security Devices | H | Configure UniFi, Queclink, and Milesight provider workflows | Integration controllers and IntegrationSecretManager |
| 91 | `CAP-SEC-REPORTING-EXPORT` | Security Devices | D | Export visible device, event, and maintenance registers | SecurityDevices ReportsController |
| 92 | `CAP-IT-SELF-SERVICE-TICKET-KB` | IT & Support | H | Raise and track owned support work and use published knowledge | ItTicketController, ItWorkAccessService, and policy |
| 93 | `CAP-IT-AGENT-TICKET-WORKFLOW` | IT & Support | H | Triage, assign, merge, approve, and complete authorised IT work | ItTicketTriageService and work-task services |
| 94 | `CAP-IT-PROVISIONING-LIFECYCLE` | IT & Support | H | Create, approve, fulfil, fail, or cancel provisioning requests | ItProvisioningRequestLifecycleService |
| 95 | `CAP-IT-CHANGE-PROBLEM-MAJOR-INCIDENT` | IT & Support | H | Manage service setup, changes, problems, and major incidents | IT service, change, problem, and major-incident services |
| 96 | `CAP-IT-REPORTING-EXPORT` | IT & Support | D | Review and export scoped IT and reliability reports | ItReportsController |
| 97 | `CAP-INT-INBOUND-PROVIDER-WEBHOOK` | Integrations | D | Receive signed provider events and project them idempotently | WebhookReceiverController and provider binding/projector services |
| 98 | `CAP-INT-SITE-PROVIDER-SYNC-SECRETS` | Integrations | D | Configure Site provider connections, secrets, mappings, and sync | SiteIntegrationController and IntegrationSecretManager |
| 99 | `CAP-INT-ADMIN-CONNECTIONS` | Integrations | D | Manage API keys, outbound webhooks, calendars, and mailboxes | ApiSettingsController and integration settings owners |
| 100 | `CAP-CR-ALERT-WORKLIST-LIFECYCLE` | Control Room | H | Inspect, acknowledge, triage, resolve, close, or reopen alerts | ControlRoomAlertAccessService and lifecycle service |
| 101 | `CAP-CR-TASK-ESCALATION-MY-QUEUE` | Control Room | H | Work follow-ups, alert tasks, escalations, and H&S transfer | Control Room task/escalation owners |
| 102 | `CAP-CR-SHIFT-HANDOVER` | Control Room | H | Prepare, hand over, accept, and acknowledge complete shift state | ControlRoomHandoverScopeService and shift handover service |
| 103 | `CAP-CR-COMMUNICATION-COLLABORATION` | Control Room | H | Broadcast, message, discuss, watch, and record time | ControlRoomMessagingController and collaboration owners |
| 104 | `CAP-CR-EVIDENCE-REPORT-SLA` | Control Room | D | Build evidence packs and export scoped SLA reports | ControlRoomEvidenceController and ControlRoomReportService |
| 105 | `CAP-CR-DEVICE-MAP-PLAYBOOK-SETTINGS` | Control Room | H | Use live maps and manage playbooks, signals, queues, and recovery | Device visibility, playbook, and settings owners |
| 106 | `CAP-SET-PERSONAL-ACCOUNT-SECURITY` | Public & Settings Platform | H | Maintain own profile, password, preferences, and two-factor setup | Profile, password, appearance, and two-factor controllers |
| 107 | `CAP-SET-ACCESS-ROLE-USER-SSO` | Public & Settings Platform | H | Administer roles, users, sessions, membership, and SSO mappings | Access, roles, users, and SSO controllers |
| 108 | `CAP-SET-ORG-NOTIFICATION-CONFIG` | Public & Settings Platform | H | Configure terminology, notifications, email, security, and modules | Dedicated settings controllers |
| 109 | `CAP-SET-DATA-PRIVACY-AUDIT` | Public & Settings Platform | D | Configure data governance and review or export audit history | DataSettingsController and AuditLogSettingsController |
| 110 | `CAP-PUB-MARKETING-CONTACT-CAREERS` | Public & Settings Platform | H | Read public pages and submit contact or token-bound career forms | Public routes, ContactController, and careers controllers |


## Provisional source findings

Nine new P1 source claims were retained for independent adjudication: three Governance, five Health & Safety/Privacy/Safeguarding, and one outbound integration destination claim. None is a final finding, verified exploit, remediated issue, or closed gate.

## Evidence boundary

- Static production and test anchors are locators; tests were not run.
- Single-tenant/multi-Site conclusions use approved Site scope, exact action capability, canonical ownership, concealed direct IDs, and privacy—not tenant isolation.
- Current grouped rows receive no benchmark mapping, task/ease, journey, viewport, runtime, release, or Pass 1–8 completion credit.
