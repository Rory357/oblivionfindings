# Current-source module and capability map — discovery wave 01

Status: **PARTIAL — grouped source discovery, not a canonical feature denominator**

Application source: `a0493442b9e392d324055c35bf25b69421dc2d35`
Evidence integration input: `c08b216e92f4689e277db4052c138a549bf86e8c`

## Result

Three formal read-only assignments returned 62 grouped user-observable candidates across eight module families: 54 human-interaction candidates and eight document/export/API candidates. No machine-only candidate was proposed in this wave. These rows are a discovery register only; the denominator remains open until all modules, routes, pages, backend owners, visual states, and collisions are adjudicated.

| Module family | H | D | Total | Current source boundary |
|---|---:|---:|---:|---|
| Clients | 7 | 1 | 8 | Single tenant, multiple Sites: client visibility and mutation require action capability, approved-Site scope, canonical client ownership, and concealed foreign direct IDs. |
| Care & Clinical | 7 | 1 | 8 | Clinical write ownership is service-led; dashboard and care lenses do not by themselves prove authority to record, review, schedule, or close clinical evidence. |
| eMAR | 8 | 2 | 10 | Site scope broadening never replaces the medication action or witness capability. Client, order, medication, stock, witness, and Site relationships require canonical validation before side effects. |
| Incidents & Safeguarding | 5 | 2 | 7 | Incident and safeguarding evidence is sensitivity-scoped. Terminal transitions and cross-module signals require canonical owners and cannot be inferred from a page or route alone. |
| HR | 8 | 1 | 9 | HR records remain single-application records with Site privacy. Staff creation, disclosure, export, attendance, and webhook egress each retain their own action authority. |
| Workforce | 8 | 0 | 8 | Shift, attendance, roster, availability, assignment, and timesheet transitions have distinct canonical owners; foreign-Site object IDs must be concealed before workflow side effects. |
| Frontline Workspaces | 5 | 0 | 5 | Aggregated frontline surfaces are lenses over canonical modules. A task row or dashboard card must not silently become the mutation owner or leak inaccessible linked records. |
| Operations | 6 | 1 | 7 | Operations dashboards and reports remain read lenses. Care plans, client onboarding, handovers, claims, and calendar sync retain separate permissions, Site scope, and canonical owners. |


## Candidate register

| # | ID | Module | Class | User job | Canonical owner |
|---:|---|---|:---:|---|---|
| 1 | `CAP-OPS-CLIENT-RECORD-LIFECYCLE` | Clients | H | Create, view, update, and archive an authorised client record | ClientController and client lifecycle services |
| 2 | `CAP-CLI-CLIENT-DOCUMENT-STAFF-LIBRARY` | Clients | H | Manage the authorised client's staff document library | Client document controllers and storage services |
| 3 | `CAP-MED-CLIENT-MEDICAL-PROFILE` | Clients | H | Maintain the authorised client's medical profile | Client medical controller and medication scope services |
| 4 | `CAP-CLI-CLIENT-SUPPORT-ASSESSMENT-RISK` | Clients | H | Record support assessments and risks | Client assessment and risk owners |
| 5 | `CAP-CLI-CLIENT-ASSIGNMENT-NOTES` | Clients | H | Manage client assignments and scoped notes | Client assignment owners |
| 6 | `OPS-CLIENT-CONSENT` | Clients | H | Request, decide, validate, and evidence consent | ConsentRequestService and ConsentDecisionEvidenceService |
| 7 | `CAP-OPS-CARE-PLAN-LIFECYCLE` | Clients | H | Create, review, sign off, and close a care plan | Operations CarePlanService and attestation owner |
| 8 | `CAP-CLI-CLIENT-DOCUMENT-AUDIT-EXPORT` | Clients | D | Export authorised client document and audit evidence | Client document export owner |
| 9 | `CAP-CLIN-MODULE-DASHBOARD` | Care & Clinical | H | Review the clinical module dashboard for approved Sites | HealthClinicalDashboardController |
| 10 | `CAP-CLIN-OBSERVATION-REGISTER-RECORD` | Care & Clinical | H | Record and review a clinical observation | ClinicalObservationService |
| 11 | `CAP-CLIN-EVENT-REGISTER-RECORD` | Care & Clinical | H | Record a clinical event | ClinicalEventService |
| 12 | `CAP-CLIN-EVENT-REVIEW-ESCALATION-CLOSURE` | Care & Clinical | H | Review, escalate, and close a clinical event | Clinical event lifecycle owner |
| 13 | `CAP-CLIN-BEHAVIOUR-AND-MONITORING` | Care & Clinical | H | Record behaviour and monitoring evidence | Clinical recording services |
| 14 | `CAP-CLIN-ASSESSMENT-PROTOCOL-LIFECYCLE` | Care & Clinical | H | Create and run an assessment protocol | Clinical protocol services |
| 15 | `CAP-CLIN-TRENDS-SUMMARY-CARE-LENS` | Care & Clinical | H | Review trends and care-summary lenses | Clinical dashboard presenters |
| 16 | `CAP-CLIN-RECORD-WIZARD-CONTEXT-API` | Care & Clinical | D | Load governed context for a clinical record wizard | Clinical context API controllers |
| 17 | `CAP-MED-WORKER-TODAY-WORKLIST` | eMAR | H | Review today's authorised medication work | MyDayMedicationsController and medication scope owner |
| 18 | `CAP-MED-WORKER-DOSE-PRN-LIFECYCLE` | eMAR | H | Record scheduled and PRN dose outcomes | Medication administration lifecycle owner |
| 19 | `CAP-MED-EMAR-WORKSPACE-ORDER-LIFECYCLE` | eMAR | H | Manage medication orders in the eMAR workspace | EmarController and medication order services |
| 20 | `CAP-MED-MEDICATION-ORDER-VERIFICATION` | eMAR | H | Verify a medication order before use | Medication order verification owner |
| 21 | `CAP-MED-REVIEW-COMPETENCY-ROUND-SELFADMIN` | eMAR | H | Manage reviews, competency, rounds, and self-administration | Medication governance services |
| 22 | `CAP-MED-CD-REGISTER-BALANCE` | eMAR | H | Maintain the controlled-drug register and balance | Controlled-drug register owner |
| 23 | `CAP-MED-DESTRUCTION-STOCK-PHARMACY` | eMAR | H | Record destruction, stock movement, and pharmacy actions | Medication stock and destruction owners |
| 24 | `CAP-MED-HANDOVER-BREAKGLASS-CORRECTION-ERROR` | eMAR | H | Handle medication handover, emergency access, correction, and error | Medication governance lifecycle owners |
| 25 | `CAP-MED-REPORT-PDF-AUDIT-EXPORTS` | eMAR | D | Generate authorised medication reports and audit exports | Medication reporting owner |
| 26 | `CAP-MED-API-CURRENT-SURFACES` | eMAR | D | Use current governed medication API surfaces | Medication API controllers |
| 27 | `CAP-INC-INCIDENT-AUTHOR-TEMPLATE` | Incidents & Safeguarding | H | Author an incident from an authorised template | IncidentController and template owner |
| 28 | `CAP-INC-INCIDENT-EVIDENCE-FOLLOWUP` | Incidents & Safeguarding | H | Add incident evidence and follow-up actions | Incident evidence and journey owners |
| 29 | `CAP-INC-INCIDENT-REVIEW-CLOSURE` | Incidents & Safeguarding | H | Review and close an incident | IncidentController and IncidentJourney |
| 30 | `CAP-INC-SAFEGUARDING-INTAKE-TRIAGE-SENSITIVITY` | Incidents & Safeguarding | H | Intake and triage a sensitivity-scoped safeguarding concern | Safeguarding intake owner |
| 31 | `CAP-INC-SAFEGUARDING-INVESTIGATION-TERMINAL` | Incidents & Safeguarding | H | Investigate and complete a safeguarding terminal transition | SafeguardingTerminalTransitionService |
| 32 | `CAP-INC-EVIDENCE-DOWNLOADS` | Incidents & Safeguarding | D | Download authorised incident or safeguarding evidence | Evidence download controllers |
| 33 | `CAP-INC-REPORT-AUDIT-EXPORTS` | Incidents & Safeguarding | D | Export authorised incident and safeguarding audit evidence | Incident reporting owners |
| 34 | `CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE` | HR | H | Manage an employee profile through its lifecycle | EmployeeProfileController and canonical HR identity owners |
| 35 | `CAP-HR-RECRUITMENT-CANDIDATE-HIRE` | HR | H | Recruit, assess, and hire a candidate | Recruitment and candidate controllers |
| 36 | `CAP-HR-ONBOARDING-OFFBOARDING` | HR | H | Onboard and offboard staff | Onboarding and offboarding controllers |
| 37 | `CAP-HR-COMPLIANCE-VETTING-TRAINING` | HR | H | Manage compliance, vetting, competency, and training | HR compliance and training owners |
| 38 | `CAP-HR-LEAVE-TIME-PAYROLL` | HR | H | Manage leave, time, and payroll preparation | HR leave, time, and payroll owners |
| 39 | `CAP-HR-DOCUMENT-POLICY-SIGNATURE` | HR | H | Publish documents and policies and obtain signatures | HR document, policy, and signature owners |
| 40 | `CAP-HR-PERFORMANCE-PEOPLE-CASEWORK` | HR | H | Manage performance and people casework | HR performance and casework owners |
| 41 | `CAP-HR-REPORTING-EXPORT` | HR | D | Run governed HR reports and exports | HR report and export controllers |
| 42 | `CAP-HR-WEBHOOK-DELIVERY` | HR | H | Configure and deliver governed HR webhooks | HrWebhookService and delivery job |
| 43 | `CAP-OPS-SHIFT-LIFECYCLE` | Workforce | H | Create, publish, start, and close a shift | ShiftLifecycleService |
| 44 | `CAP-OPS-SHIFT-STAFFING-COVER` | Workforce | H | Assign staff and arrange shift cover | Shift staffing and coverage services |
| 45 | `CAP-OPS-ROSTER-PLAN-PUBLISH` | Workforce | H | Plan, validate, and publish a roster | RosterPublishingService |
| 46 | `CAP-OPS-ATTENDANCE-CLOCK-SESSION` | Workforce | H | Clock attendance and end a governed session | AttendanceService |
| 47 | `CAP-OPS-TIMESHEET-AUTHOR-SUBMIT` | Workforce | H | Author and submit a timesheet | DraftTimesheetService |
| 48 | `CAP-OPS-TIMESHEET-MANAGER-PAYROLL` | Workforce | H | Approve, amend, and prepare timesheets for payroll | TimesheetApprovalService and operations reconciliation |
| 49 | `CAP-OPS-STAFF-AVAILABILITY-TIME-OFF` | Workforce | H | Manage staff availability and time off | Availability and leave owners |
| 50 | `CAP-HR-STAFF-ASSIGNMENT-CREDENTIAL` | Workforce | H | Assign staff using governed credential and eligibility evidence | ShiftStaffEligibilityService and HR assignment owners |
| 51 | `CAP-DAY-MY-DAY-WORKSPACE` | Frontline Workspaces | H | Use the personalised My Day workspace | My Day presenters with canonical action owners |
| 52 | `CAP-DAY-MY-ROSTER` | Frontline Workspaces | H | Review the worker's roster | Roster read lens |
| 53 | `CAP-DAY-MY-CALENDAR` | Frontline Workspaces | H | Review the worker's calendar | Calendar read lens |
| 54 | `CAP-DAY-ALL-TASKS-WORKBENCH` | Frontline Workspaces | H | Work authorised tasks across modules | Task providers and canonical linked-record owners |
| 55 | `CAP-DAY-TASK-REPORT` | Frontline Workspaces | H | Report task status without leaking linked records | Task reporting owner |
| 56 | `CAP-OPS-DASHBOARD-ACTIVITY` | Operations | H | Review operations dashboard activity | Operations dashboard presenters |
| 57 | `CAP-OPS-CARE-PLAN-REVIEW-SIGNOFF` | Operations | H | Review and sign off a care plan | CarePlanService and CarePlanAttestationService |
| 58 | `CAP-OPS-CLIENT-ONBOARDING` | Operations | H | Run governed client onboarding | Client onboarding owner |
| 59 | `CAP-OPS-HANDOVER-SHIFT-NOTES` | Operations | H | Record handover and shift notes | ShiftHandoverService and shift-note owner |
| 60 | `CAP-OPS-FUNDING-CLAIMS` | Operations | H | Prepare and manage governed funding claims | Operations funding owner |
| 61 | `CAP-OPS-REPORTING-EXPORT` | Operations | D | Run governed operations reports and exports | Operations reporting owners |
| 62 | `CAP-OPS-CALENDAR-SYNC` | Operations | H | Synchronise authorised operational calendar obligations | Calendar sync owner |

## Static Inertia page-root denominator

RUN-010 completed the manual source adjudication that was open after the first semantic wave. All 963 non-test TSX paths in the committed Inertia resolver are now partitioned for static render/import identity:

| Partition | Count | Current source classification |
|---|---:|---|
| Existing literal backend render roots | 711 | Current static file-backed Inertia page roots |
| Unrendered but imported | 227 | Support/component paths, not independent page roots |
| Unrendered and unimported: alias/generated/legacy | 20 | Superseded by canonical routes, pages, redirects, or embedded surfaces |
| Unrendered and unimported: dead/unreachable | 3 | No importer, route, or backend render owner found |
| Unrendered and unimported: test/demo/story | 2 | Debug/starter surfaces, not current page roots |
| **Resolver total** | **963** | **963/963 partitioned for static render/import identity** |

The separate backend scan contains 11 literal render names with no file: four are in retired unreachable methods and seven are in unrouted stubs. They are backend liabilities with zero page-root or runtime credit; they do not enlarge the 711-file denominator.

This denominator is limited to committed file-backed Inertia roots at the application pin. Final prompt classification of the 711 roots, framework-expanded route reachability, build resolution, browser observation, deployment identity, release status, and route/page-to-`FEATURE-ID` coverage remain open. The 25 candidate rows additionally carry the prompt taxonomy (`Redirect/legacy`, `Duplicate`, `Dead/unreachable`, or `Out of product scope`) in the structured evidence.

## RUN-012 through RUN-014 static coverage reconciliation

All 38 route PHP files are now classified at source level: 21 represented by the earlier grouped candidates, five compatibility/alias route files, six composite route files, and six missing or partially represented route families. The route scan contains 3,217 static `Route::…` callsites and 3,245 fluent `->name()` callsites. These are separate locators, not a framework-expanded route denominator. The named navigation/tab universe contains 162 production source files; 33 persistent or canonical registries contain 121 groups/sets and 492 declared items/tabs.

Fourteen owner-backed additions close the named route/navigation family gaps at discovery level: admin and Today dashboards, the personal notification inbox, Catering/meal planning, the Compliance exception command centre, two Portal families, four Respite families, Roadmap, cross-module Reporting, and the internal quality checklist. They add 12 H, one D, and one M row, taking the provisional discovery floor from 172 to **186 rows: 157 H, 27 D, and two M**. This total is not frozen because eight identical anchor groups cover 62 earlier rows and multiple semantic collisions still require split/collapse adjudication.

The expanded production frontend census covers 1,761 JS/TS files and establishes 57 hero definitions / 659 instances, 473 overlay definitions / 1,211 instances, and 115 declarative trigger tags. The separate backend/data/test census establishes 561 controller paths, 735 service entries, 782 model paths, 75 policy paths, 126 job paths, 14 events, 12 listeners, 29 observers, 978 migrations, and 1,381 PHP test files / 9,895 lexical cases. These are source denominators only. No rendered instance, route execution, schema state, test outcome, queue behavior, role/Site behavior, or completion credit follows.

The required `02-eight-pass-coverage-ledger.csv` now has one provisional row per route file. It makes Pass gaps explicit but is not yet the canonical module/submodule ledger; every row remains incomplete across the eight-pass gate.


## Provisional P1 source claims

`MED-RBAC-01`, `MED-CD-SCOPE-01`, and `MED-CD-ATOMICITY-01` require independent current-source review before they can become final findings. They also require the appropriate runtime, role/Site, failure, and concurrency gates before closure or production-readiness can be claimed.

## Evidence boundary

- Static routes, controllers, services, pages, migrations, and test files are locators, not executed proof.
- The single-tenant, multi-Site architecture is assessed through approved Site scope, exact action capability, canonical ownership, direct-object concealment, and privacy boundaries.
- No benchmark mapping, task/ease score, journey, responsive viewport, runtime test, or all-eight-pass credit is awarded here.
- The historical 904-feature register remains a crosswalk only. Nothing in this wave promotes its denominator or numerator.
