#!/usr/bin/env python3
"""Build the first normalized semantic source wave for the current-source audit.

This collector is deliberately static and deterministic.  It does not import the
application, boot Laravel, execute tests, access a database, or use the network.
It writes only within this audit directory.
"""

from __future__ import annotations

import hashlib
import json
import runpy
from collections import Counter
from pathlib import Path
from string import Template


AUDIT_DIR = Path(__file__).resolve().parents[1]
GENERATED_AT = "2026-08-24T16:08:22+12:00"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
AUDIT_INPUT_COMMIT = "c08b216e92f4689e277db4052c138a549bf86e8c"


def write_json(relative: str, payload: object) -> None:
    path = AUDIT_DIR / relative
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")


def write_text(relative: str, text: str) -> None:
    path = AUDIT_DIR / relative
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(text.rstrip() + "\n", encoding="utf-8", newline="\n")


def digest(payload: object) -> str:
    encoded = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    return hashlib.sha256(encoded).hexdigest()


GROUPS = {
    "Clients": {
        "production_anchors": [
            "routes/clients.php:30-259",
            "app/Http/Controllers/ClientController.php:1",
            "app/Services/ConsentRequestService.php:1",
            "app/Services/ConsentDecisionEvidenceService.php:1",
        ],
        "test_anchors": [
            "tests/Feature/ClientControllerTest.php:1",
            "tests/Feature/Consents/ConsentRequestIntegrityTest.php:1",
        ],
        "boundary": "Single tenant, multiple Sites: client visibility and mutation require action capability, approved-Site scope, canonical client ownership, and concealed foreign direct IDs.",
    },
    "Care & Clinical": {
        "production_anchors": [
            "routes/health-clinical.php:23-114",
            "app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:1",
            "app/Domain/Clinical/Services/ClinicalObservationService.php:1",
            "app/Domain/Clinical/Services/ClinicalEventService.php:1",
        ],
        "test_anchors": [
            "tests/Feature/Domain/Clinical/HealthClinicalSiteAuthorizationTest.php:1",
            "tests/Feature/Domain/Clinical/ObservationRegisterTest.php:1",
        ],
        "boundary": "Clinical write ownership is service-led; dashboard and care lenses do not by themselves prove authority to record, review, schedule, or close clinical evidence.",
    },
    "eMAR": {
        "production_anchors": [
            "routes/emar.php:30-395",
            "app/Http/Controllers/Emar/EmarController.php:4023-4121",
            "app/Services/Medication/MedicationScopeDecisionService.php:40-221",
            "app/Services/Eligibility/Rules/MedicationCompetencyRule.php:1",
        ],
        "test_anchors": [
            "tests/Feature/Emar/MedicationScopeAuthorizationTest.php:1",
            "tests/Feature/Emar/MedicationCompetencyPolicyTest.php:1",
            "tests/Feature/Emar/ControlledDrugsTest.php:1",
        ],
        "boundary": "Site scope broadening never replaces the medication action or witness capability. Client, order, medication, stock, witness, and Site relationships require canonical validation before side effects.",
    },
    "Incidents & Safeguarding": {
        "production_anchors": [
            "routes/incidents.php:16-139",
            "routes/safeguarding.php:17-106",
            "app/Http/Controllers/IncidentController.php:1475-1790",
            "app/Services/Safeguarding/SafeguardingTerminalTransitionService.php:31-272",
        ],
        "test_anchors": [
            "tests/Feature/IncidentControllerTest.php:1",
            "tests/Architecture/IncidentAlertLifecycleOwnershipBoundaryTest.php:1",
            "tests/Architecture/SafeguardingTerminalTransitionOwnershipTest.php:1",
        ],
        "boundary": "Incident and safeguarding evidence is sensitivity-scoped. Terminal transitions and cross-module signals require canonical owners and cannot be inferred from a page or route alone.",
    },
    "HR": {
        "production_anchors": [
            "routes/hr.php:1-1420",
            "app/Http/Controllers/Hr/EmployeeProfileController.php:1",
            "app/Domain/Hr/Services/AttendanceService.php:1",
            "app/Domain/Hr/Services/HrWebhookService.php:1",
        ],
        "test_anchors": [
            "tests/Architecture/HrStaffCreationWorkflowBoundaryTest.php:1",
            "tests/Architecture/HrComplianceExportPermissionBoundaryTest.php:1",
            "tests/Architecture/HrWebhookEgressBoundaryTest.php:1",
        ],
        "boundary": "HR records remain single-application records with Site privacy. Staff creation, disclosure, export, attendance, and webhook egress each retain their own action authority.",
    },
    "Workforce": {
        "production_anchors": [
            "routes/shifts.php:1-127",
            "routes/operations.php:1-1336",
            "app/Domain/Shifts/Lifecycle/ShiftLifecycleService.php:1",
            "app/Domain/Shifts/Timesheets/TimesheetApprovalService.php:1",
        ],
        "test_anchors": [
            "tests/Feature/ShiftLifecycleHardeningTest.php:1",
            "tests/Feature/TimesheetSafetyGuardsTest.php:1",
            "tests/Unit/Rostering/EligibilityScoringStrategyTest.php:1",
        ],
        "boundary": "Shift, attendance, roster, availability, assignment, and timesheet transitions have distinct canonical owners; foreign-Site object IDs must be concealed before workflow side effects.",
    },
    "Frontline Workspaces": {
        "production_anchors": [
            "app/Http/Controllers/MyDayActionsController.php:1",
            "app/Http/Controllers/MyDayMedicationsController.php:1",
            "app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:1",
            "resources/js/hooks/use-my-day-labels.ts:1",
        ],
        "test_anchors": [
            "tests/Feature/MyDayActiveSiteTest.php:1",
            "tests/Feature/Tasks/AllTasksPermissionRecoveryTest.php:1",
            "tests/Feature/Tasks/TaskNavigationFailureIsolationTest.php:1",
        ],
        "boundary": "Aggregated frontline surfaces are lenses over canonical modules. A task row or dashboard card must not silently become the mutation owner or leak inaccessible linked records.",
    },
    "Operations": {
        "production_anchors": [
            "routes/operations.php:1-1336",
            "app/Services/Operations/CarePlanService.php:1",
            "app/Services/Operations/TimesheetReconciliationService.php:1",
            "app/Services/ShiftHandoverService.php:1",
        ],
        "test_anchors": [
            "tests/Unit/Operations/ClientSafetyPayloadTest.php:1",
            "tests/Unit/Operations/TimesheetReconciliationServiceTest.php:1",
            "tests/Unit/Services/ShiftHandoverServiceTest.php:1",
        ],
        "boundary": "Operations dashboards and reports remain read lenses. Care plans, client onboarding, handovers, claims, and calendar sync retain separate permissions, Site scope, and canonical owners.",
    },
}


ROWS = [
    ("Clients", "CAP-OPS-CLIENT-RECORD-LIFECYCLE", "H", "Create, view, update, and archive an authorised client record", "ClientController and client lifecycle services"),
    ("Clients", "CAP-CLI-CLIENT-DOCUMENT-STAFF-LIBRARY", "H", "Manage the authorised client's staff document library", "Client document controllers and storage services"),
    ("Clients", "CAP-MED-CLIENT-MEDICAL-PROFILE", "H", "Maintain the authorised client's medical profile", "Client medical controller and medication scope services"),
    ("Clients", "CAP-CLI-CLIENT-SUPPORT-ASSESSMENT-RISK", "H", "Record support assessments and risks", "Client assessment and risk owners"),
    ("Clients", "CAP-CLI-CLIENT-ASSIGNMENT-NOTES", "H", "Manage client assignments and scoped notes", "Client assignment owners"),
    ("Clients", "OPS-CLIENT-CONSENT", "H", "Request, decide, validate, and evidence consent", "ConsentRequestService and ConsentDecisionEvidenceService"),
    ("Clients", "CAP-OPS-CARE-PLAN-LIFECYCLE", "H", "Create, review, sign off, and close a care plan", "Operations CarePlanService and attestation owner"),
    ("Clients", "CAP-CLI-CLIENT-DOCUMENT-AUDIT-EXPORT", "D", "Export authorised client document and audit evidence", "Client document export owner"),
    ("Care & Clinical", "CAP-CLIN-MODULE-DASHBOARD", "H", "Review the clinical module dashboard for approved Sites", "HealthClinicalDashboardController"),
    ("Care & Clinical", "CAP-CLIN-OBSERVATION-REGISTER-RECORD", "H", "Record and review a clinical observation", "ClinicalObservationService"),
    ("Care & Clinical", "CAP-CLIN-EVENT-REGISTER-RECORD", "H", "Record a clinical event", "ClinicalEventService"),
    ("Care & Clinical", "CAP-CLIN-EVENT-REVIEW-ESCALATION-CLOSURE", "H", "Review, escalate, and close a clinical event", "Clinical event lifecycle owner"),
    ("Care & Clinical", "CAP-CLIN-BEHAVIOUR-AND-MONITORING", "H", "Record behaviour and monitoring evidence", "Clinical recording services"),
    ("Care & Clinical", "CAP-CLIN-ASSESSMENT-PROTOCOL-LIFECYCLE", "H", "Create and run an assessment protocol", "Clinical protocol services"),
    ("Care & Clinical", "CAP-CLIN-TRENDS-SUMMARY-CARE-LENS", "H", "Review trends and care-summary lenses", "Clinical dashboard presenters"),
    ("Care & Clinical", "CAP-CLIN-RECORD-WIZARD-CONTEXT-API", "D", "Load governed context for a clinical record wizard", "Clinical context API controllers"),
    ("eMAR", "CAP-MED-WORKER-TODAY-WORKLIST", "H", "Review today's authorised medication work", "MyDayMedicationsController and medication scope owner"),
    ("eMAR", "CAP-MED-WORKER-DOSE-PRN-LIFECYCLE", "H", "Record scheduled and PRN dose outcomes", "Medication administration lifecycle owner"),
    ("eMAR", "CAP-MED-EMAR-WORKSPACE-ORDER-LIFECYCLE", "H", "Manage medication orders in the eMAR workspace", "EmarController and medication order services"),
    ("eMAR", "CAP-MED-MEDICATION-ORDER-VERIFICATION", "H", "Verify a medication order before use", "Medication order verification owner"),
    ("eMAR", "CAP-MED-REVIEW-COMPETENCY-ROUND-SELFADMIN", "H", "Manage reviews, competency, rounds, and self-administration", "Medication governance services"),
    ("eMAR", "CAP-MED-CD-REGISTER-BALANCE", "H", "Maintain the controlled-drug register and balance", "Controlled-drug register owner"),
    ("eMAR", "CAP-MED-DESTRUCTION-STOCK-PHARMACY", "H", "Record destruction, stock movement, and pharmacy actions", "Medication stock and destruction owners"),
    ("eMAR", "CAP-MED-HANDOVER-BREAKGLASS-CORRECTION-ERROR", "H", "Handle medication handover, emergency access, correction, and error", "Medication governance lifecycle owners"),
    ("eMAR", "CAP-MED-REPORT-PDF-AUDIT-EXPORTS", "D", "Generate authorised medication reports and audit exports", "Medication reporting owner"),
    ("eMAR", "CAP-MED-API-CURRENT-SURFACES", "D", "Use current governed medication API surfaces", "Medication API controllers"),
    ("Incidents & Safeguarding", "CAP-INC-INCIDENT-AUTHOR-TEMPLATE", "H", "Author an incident from an authorised template", "IncidentController and template owner"),
    ("Incidents & Safeguarding", "CAP-INC-INCIDENT-EVIDENCE-FOLLOWUP", "H", "Add incident evidence and follow-up actions", "Incident evidence and journey owners"),
    ("Incidents & Safeguarding", "CAP-INC-INCIDENT-REVIEW-CLOSURE", "H", "Review and close an incident", "IncidentController and IncidentJourney"),
    ("Incidents & Safeguarding", "CAP-INC-SAFEGUARDING-INTAKE-TRIAGE-SENSITIVITY", "H", "Intake and triage a sensitivity-scoped safeguarding concern", "Safeguarding intake owner"),
    ("Incidents & Safeguarding", "CAP-INC-SAFEGUARDING-INVESTIGATION-TERMINAL", "H", "Investigate and complete a safeguarding terminal transition", "SafeguardingTerminalTransitionService"),
    ("Incidents & Safeguarding", "CAP-INC-EVIDENCE-DOWNLOADS", "D", "Download authorised incident or safeguarding evidence", "Evidence download controllers"),
    ("Incidents & Safeguarding", "CAP-INC-REPORT-AUDIT-EXPORTS", "D", "Export authorised incident and safeguarding audit evidence", "Incident reporting owners"),
    ("HR", "CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE", "H", "Manage an employee profile through its lifecycle", "EmployeeProfileController and canonical HR identity owners"),
    ("HR", "CAP-HR-RECRUITMENT-CANDIDATE-HIRE", "H", "Recruit, assess, and hire a candidate", "Recruitment and candidate controllers"),
    ("HR", "CAP-HR-ONBOARDING-OFFBOARDING", "H", "Onboard and offboard staff", "Onboarding and offboarding controllers"),
    ("HR", "CAP-HR-COMPLIANCE-VETTING-TRAINING", "H", "Manage compliance, vetting, competency, and training", "HR compliance and training owners"),
    ("HR", "CAP-HR-LEAVE-TIME-PAYROLL", "H", "Manage leave, time, and payroll preparation", "HR leave, time, and payroll owners"),
    ("HR", "CAP-HR-DOCUMENT-POLICY-SIGNATURE", "H", "Publish documents and policies and obtain signatures", "HR document, policy, and signature owners"),
    ("HR", "CAP-HR-PERFORMANCE-PEOPLE-CASEWORK", "H", "Manage performance and people casework", "HR performance and casework owners"),
    ("HR", "CAP-HR-REPORTING-EXPORT", "D", "Run governed HR reports and exports", "HR report and export controllers"),
    ("HR", "CAP-HR-WEBHOOK-DELIVERY", "H", "Configure and deliver governed HR webhooks", "HrWebhookService and delivery job"),
    ("Workforce", "CAP-OPS-SHIFT-LIFECYCLE", "H", "Create, publish, start, and close a shift", "ShiftLifecycleService"),
    ("Workforce", "CAP-OPS-SHIFT-STAFFING-COVER", "H", "Assign staff and arrange shift cover", "Shift staffing and coverage services"),
    ("Workforce", "CAP-OPS-ROSTER-PLAN-PUBLISH", "H", "Plan, validate, and publish a roster", "RosterPublishingService"),
    ("Workforce", "CAP-OPS-ATTENDANCE-CLOCK-SESSION", "H", "Clock attendance and end a governed session", "AttendanceService"),
    ("Workforce", "CAP-OPS-TIMESHEET-AUTHOR-SUBMIT", "H", "Author and submit a timesheet", "DraftTimesheetService"),
    ("Workforce", "CAP-OPS-TIMESHEET-MANAGER-PAYROLL", "H", "Approve, amend, and prepare timesheets for payroll", "TimesheetApprovalService and operations reconciliation"),
    ("Workforce", "CAP-OPS-STAFF-AVAILABILITY-TIME-OFF", "H", "Manage staff availability and time off", "Availability and leave owners"),
    ("Workforce", "CAP-HR-STAFF-ASSIGNMENT-CREDENTIAL", "H", "Assign staff using governed credential and eligibility evidence", "ShiftStaffEligibilityService and HR assignment owners"),
    ("Frontline Workspaces", "CAP-DAY-MY-DAY-WORKSPACE", "H", "Use the personalised My Day workspace", "My Day presenters with canonical action owners"),
    ("Frontline Workspaces", "CAP-DAY-MY-ROSTER", "H", "Review the worker's roster", "Roster read lens"),
    ("Frontline Workspaces", "CAP-DAY-MY-CALENDAR", "H", "Review the worker's calendar", "Calendar read lens"),
    ("Frontline Workspaces", "CAP-DAY-ALL-TASKS-WORKBENCH", "H", "Work authorised tasks across modules", "Task providers and canonical linked-record owners"),
    ("Frontline Workspaces", "CAP-DAY-TASK-REPORT", "H", "Report task status without leaking linked records", "Task reporting owner"),
    ("Operations", "CAP-OPS-DASHBOARD-ACTIVITY", "H", "Review operations dashboard activity", "Operations dashboard presenters"),
    ("Operations", "CAP-OPS-CARE-PLAN-REVIEW-SIGNOFF", "H", "Review and sign off a care plan", "CarePlanService and CarePlanAttestationService"),
    ("Operations", "CAP-OPS-CLIENT-ONBOARDING", "H", "Run governed client onboarding", "Client onboarding owner"),
    ("Operations", "CAP-OPS-HANDOVER-SHIFT-NOTES", "H", "Record handover and shift notes", "ShiftHandoverService and shift-note owner"),
    ("Operations", "CAP-OPS-FUNDING-CLAIMS", "H", "Prepare and manage governed funding claims", "Operations funding owner"),
    ("Operations", "CAP-OPS-REPORTING-EXPORT", "D", "Run governed operations reports and exports", "Operations reporting owners"),
    ("Operations", "CAP-OPS-CALENDAR-SYNC", "H", "Synchronise authorised operational calendar obligations", "Calendar sync owner"),
]


def build_candidates() -> list[dict[str, object]]:
    candidates = []
    for ordinal, (module, candidate_id, feature_class, job, owner) in enumerate(ROWS, start=1):
        group = GROUPS[module]
        candidates.append(
            {
                "ordinal": ordinal,
                "candidate_id": candidate_id,
                "module": module,
                "feature_class": feature_class,
                "user_job": job,
                "canonical_owner": owner,
                "production_anchors": group["production_anchors"],
                "representative_test_anchors": group["test_anchors"],
                "site_role_privacy_note": group["boundary"],
                "adjudication_status": "GROUPED_DISCOVERY_CANDIDATE_NOT_FINAL_DENOMINATOR",
                "evidence_limit": "Static source locators only; no route execution, test result, browser result, benchmark match, ease score, or production-readiness credit.",
            }
        )
    return candidates


CANDIDATES = build_candidates()
CLASS_COUNTS = Counter(row["feature_class"] for row in CANDIDATES)
MODULE_COUNTS = Counter(row["module"] for row in CANDIDATES)

assert len(CANDIDATES) == 62
assert CLASS_COUNTS == Counter({"H": 54, "D": 8})
assert len({row["candidate_id"] for row in CANDIDATES}) == 62


SEMANTIC = {
    "schema_version": 1,
    "status": "PARTIAL_STATIC_SEMANTIC_CENSUS_NOT_RUNTIME_DENOMINATORS",
    "generated_at": GENERATED_AT,
    "source": {
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
        "audit_input_commit": AUDIT_INPUT_COMMIT,
        "non_audit_product_diff_from_application_commit": 0,
    },
    "methods": [
        "PHP token_get_all callsite/declaration scan",
        "TypeScript AST and current Inertia resolver/import graph scan",
        "committed-path and conservative declaration reconciliation",
    ],
    "execution_credit": {
        "laravel_boot": 0,
        "runtime_routes": 0,
        "tests": 0,
        "browser_current_build": 0,
        "database": 0,
        "network": 0,
    },
    "routes": {
        "route_php_files": 38,
        "static_route_declaration_callsites": 3217,
        "fluent_name_callsites": 3245,
        "method_callsites": {
            "get": 1218,
            "post": 1364,
            "put": 295,
            "patch": 76,
            "delete": 213,
            "match": 4,
            "redirect": 41,
            "permanentRedirect": 5,
            "resource": 1,
        },
        "denominator_status": "STATIC_CALLSITE_LOCATOR_ONLY_NOT_FRAMEWORK_ROUTE_DENOMINATOR",
    },
    "inertia_pages": {
        "tree_paths": 1058,
        "tsx_paths": 1007,
        "ts_paths": 51,
        "resolver_non_test_tsx": 963,
        "backend_render_calls": 745,
        "unique_backend_render_names": 722,
        "existing_render_roots": 711,
        "missing_render_targets": [
            "hr/recruitment/jobs",
            "hr/recruitment/kits",
            "hr/training/index",
            "operations/timesheets/approvals",
            "training/competencies/index",
            "training/competencies/show",
            "training/inductions/index",
            "training/inductions/show",
            "training/records/index",
            "training/records/show",
            "training/records/user",
        ],
        "resolver_partition": {
            "matched_render_roots": 711,
            "unrendered_imported": 227,
            "unrendered_unimported": 25,
            "sum": 963,
        },
        "default_export_candidates": 801,
        "unmatched_default_export_candidates": 90,
        "support_without_default_export": 162,
        "non_test_ts_helpers": 32,
        "page_test_spec_paths": 63,
        "denominator_status": "NOT_FINAL_UNTIL_25_UNIMPORTED_CANDIDATES_ARE_MANUALLY_ADJUDICATED",
    },
    "backend_declarations": {
        "root_scope_app_http_controllers": {"paths": 436, "classes": 422, "traits": 13, "declarations": 435},
        "root_scope_app_models": {"paths": 434, "eloquent_classes": 430, "traits": 4},
        "root_scope_app_policies": {"paths": 32, "classes": 32},
        "root_scope_app_services": {"declarations": 364, "classes": 339, "interfaces": 24, "enums": 1},
        "app_domain": {"paths": 1381, "declarations": 1384, "classes": 1311, "interfaces": 38, "enums": 33, "traits": 2},
        "recursive_directory_reconciliation": {
            "controllers_directory_paths": 561,
            "controller_suffix_named_classes": 544,
            "non_suffix_base_or_traits": 17,
            "models_directory_paths": 782,
            "models_directory_named_classes": 778,
            "models_directory_traits": 4,
            "eloquent_model_candidates_app_wide": 779,
            "policies_directory_paths": 75,
            "policies_directory_classes": 75,
            "services_directory_paths": 725,
            "service_suffix_classes": 404,
            "non_class_service_contract_enum_files": 28,
        },
        "denominator_status": "SCOPES_ARE_ORTHOGONAL_AND_MUST_NOT_BE_SUMMED_OR_SUBSTITUTED_FOR_FINAL_OWNERSHIP_COUNTS",
    },
    "async": {"jobs_paths": 126, "events_paths": 14, "listeners_paths": 12, "outbox_named_candidates": 12, "observers": 29, "notifications": 176, "notification_classes": 174, "notification_interfaces": 2},
    "migrations": {"paths": 978, "schema_callsites": 4701, "create": 859, "table": 1488, "dropIfExists": 868, "hasTable": 404, "hasColumn": 1082, "unique_literal_create_table_candidates": 838, "altered_table_candidates": 322, "denominator_status": "MIGRATION_HISTORY_NOT_CURRENT_RUNTIME_SCHEMA"},
    "tests_static": {"tests_tree_paths": 1509, "php_paths": 1381, "test_php_basenames": 1357, "repo_wide_js_ts_test_spec_paths": 261, "pest_test_it_callsites": 4574, "direct_testcase_subclasses": 505, "named_test_classes": 588, "execution_credit": 0},
    "page_tsx_visual_locators": {"pagehero_jsx_sites": 497, "pagehero_files": 486, "other_hero_banner_sites": 93, "other_hero_banner_symbols": 42, "primitive_overlay_root_sites": 333, "primitive_overlay_roots": {"Dialog": 253, "AlertDialog": 42, "Popover": 33, "Sheet": 5}, "custom_overlay_usage_sites": 652, "custom_overlay_symbols": 388, "denominator_status": "PAGE_TSX_LOCATORS_ONLY; SHARED_COMPONENTS_AND_EXACT_TRIGGERS_NOT_INCLUDED"},
    "unresolved_semantic_work": [
        "Framework/provider runtime route denominator remains unexecuted.",
        "Twenty-five resolver-imported TSX candidates need manual page/support adjudication.",
        "Controller/model/policy/service/domain scopes require canonical ownership and reachability classification.",
        "Migration history is not a current schema snapshot.",
        "Static test structures do not establish passing, failure, concurrency, or coverage evidence.",
        "Visual locators require full production TSX, trigger, state, route, viewport, and browser evidence.",
    ],
}


FEATURES = {
    "schema_version": 1,
    "status": "DISCOVERY_WAVE_PARTIAL_NOT_CANONICAL",
    "generated_at": GENERATED_AT,
    "source": SEMANTIC["source"],
    "candidate_count": len(CANDIDATES),
    "class_counts": dict(sorted(CLASS_COUNTS.items())),
    "module_counts": dict(sorted(MODULE_COUNTS.items())),
    "machine_capability_candidates": 0,
    "candidates": CANDIDATES,
    "historical_crosswalk": {
        "source_contracts_present_runtime_closure_not_promoted": [
            "CONSENT-AUTH-01",
            "CONSENT-CAPACITY-01",
            "CARE-SIGNOFF-01",
            "CLIN-SCHEDULE-01",
            "MED-SCOPE-01",
            "MED-COMP-01",
            "MED-VERIFY-01",
            "INCIDENT-ALERT-LIFECYCLE-01",
            "SAFE-NESTED-01",
            "SAFE-TERMINAL-SYNC-01",
            "HR-STAFF-CREATION-PATH-01",
            "TASK-RBAC-001",
        ],
        "still_source_supported": ["MED-RBAC-01"],
        "credit_rule": "A current source contract or historical crosswalk does not establish runtime closure, current benchmark credit, or all-pass completion.",
    },
    "provisional_findings": [
        {
            "finding_id": "MED-RBAC-01",
            "provisional_severity": "P1",
            "source_claim": "Broad medications.orders.manage routing appears to reach controlled-drug, destruction, and stock operations even though dedicated controlled capabilities exist; cited controller methods do not visibly enforce the exact controlled action capability.",
            "anchors": ["routes/emar.php:30-395", "app/Http/Controllers/Emar/EmarController.php:4023-4121"],
            "status": "PROVISIONAL_REQUIRES_INDEPENDENT_CURRENT_SOURCE_REVIEW_AND_RUNTIME_GATE",
        },
        {
            "finding_id": "MED-CD-SCOPE-01",
            "provisional_severity": "P1",
            "source_claim": "Controlled-drug and destruction paths appear to accept independently supplied client, medication, Site, or witness identifiers without consistently routing every relationship through the canonical medication scope decision service before disclosure or mutation.",
            "anchors": ["app/Http/Controllers/Emar/EmarController.php:4732-4826", "app/Services/Medication/MedicationScopeDecisionService.php:374-547"],
            "status": "PROVISIONAL_REQUIRES_INDEPENDENT_CURRENT_SOURCE_REVIEW_AND_RUNTIME_GATE",
        },
        {
            "finding_id": "MED-CD-ATOMICITY-01",
            "provisional_severity": "P1",
            "source_claim": "Controlled-drug entry and stock update appear not to share one encompassing transaction with owner-first locking; destruction relationship checks appear later than the first mutation boundary.",
            "anchors": ["app/Http/Controllers/Emar/EmarController.php:4986-5314"],
            "status": "PROVISIONAL_REQUIRES_INDEPENDENT_CURRENT_SOURCE_REVIEW_AND_CONCURRENCY_GATE",
        },
    ],
    "completion_credit": False,
    "limits": [
        "This is a grouped discovery wave, not the final feature denominator.",
        "No candidate has benchmark, ease, journey, browser, runtime, test, all-pass, or production-readiness credit.",
        "Historical finding crosswalks are locators; source contracts and test files were not executed.",
        "The three provisional eMAR P1 candidates are not final findings until independently re-reviewed and reconciled.",
    ],
}


ASSIGNMENT_SUMMARIES = [
    {
        "assignment_id": "RUN-001",
        "scope": "Current semantic route, page, backend, data, test, and bounded visual source census",
        "candidate_count": None,
        "evidence_count": 43,
        "completion_test_met": True,
        "wrote_files": False,
        "runtime_gates": None,
        "return_status": "RECONCILED",
        "root_reconciliation": "Static callsites are not runtime route counts; page partitions are orthogonal; root app scopes and app/Domain scopes must not be summed or substituted.",
    },
    {
        "assignment_id": "RUN-002",
        "scope": "Clients, care and clinical, eMAR, incidents, and safeguarding grouped capability discovery",
        "candidate_count": 33,
        "class_counts": {"H": 27, "D": 6, "M": 0},
        "evidence_count": 99,
        "completion_test_met": True,
        "wrote_files": False,
        "runtime_gates": None,
        "return_status": "RECONCILED_WITH_PROVISIONAL_FINDINGS",
        "root_reconciliation": "Grouped candidates are not the canonical feature denominator; source-remediation crosswalks carry no runtime closure; three eMAR P1 claims remain provisional.",
    },
    {
        "assignment_id": "RUN-003",
        "scope": "HR, workforce, frontline workspaces, and operations grouped capability discovery",
        "candidate_count": 29,
        "class_counts": {"H": 27, "D": 2, "M": 0},
        "evidence_count": 44,
        "completion_test_met": True,
        "wrote_files": False,
        "runtime_gates": None,
        "return_status": "RECONCILED",
        "root_reconciliation": "The visible candidate register reconciles to 9 HR, 8 Workforce, 5 Frontline, and 7 Operations candidates; historical P0/P1 rows remain crosswalks unless current evidence independently supports them.",
    },
]

for assignment in ASSIGNMENT_SUMMARIES:
    assignment["normalized_payload_sha256"] = digest(assignment)

AGENT_REGISTER = {
    "schema_version": 1,
    "status": "FORMAL_SOURCE_WAVE_01_RECONCILED_AUDIT_INCOMPLETE",
    "generated_at": GENERATED_AT,
    "application_commit": APPLICATION_COMMIT,
    "writer_boundary": "Only the root orchestrator wrote audit artifacts; RUN-001 through RUN-003 returned evidence in messages and reported wrote_files=false.",
    "formal_assignments_eligible": 3,
    "literal_prompt_minimum": 8,
    "literal_prompt_minimum_met": False,
    "planned_formal_assignments_target": 11,
    "planned_target_met": False,
    "all_returned": True,
    "all_completion_tests_met": True,
    "all_reported_no_writes": True,
    "assignment_returns": ASSIGNMENT_SUMMARIES,
    "finalization_gate": False,
}


MODULE_MAP = f"""# Current-source module and capability map — discovery wave 01

Status: **PARTIAL — grouped source discovery, not a canonical feature denominator**

Application source: `{APPLICATION_COMMIT}`
Evidence integration input: `{AUDIT_INPUT_COMMIT}`

## Result

Three formal read-only assignments returned 62 grouped user-observable candidates across eight module families: 54 human-interaction candidates and eight document/export/API candidates. No machine-only candidate was proposed in this wave. These rows are a discovery register only; the denominator remains open until all modules, routes, pages, backend owners, visual states, and collisions are adjudicated.

| Module family | H | D | Total | Current source boundary |
|---|---:|---:|---:|---|
"""
for module in GROUPS:
    rows = [row for row in CANDIDATES if row["module"] == module]
    counts = Counter(row["feature_class"] for row in rows)
    MODULE_MAP += f"| {module} | {counts.get('H', 0)} | {counts.get('D', 0)} | {len(rows)} | {GROUPS[module]['boundary']} |\n"

MODULE_MAP += """

## Candidate register

| # | ID | Module | Class | User job | Canonical owner |
|---:|---|---|:---:|---|---|
"""
for row in CANDIDATES:
    MODULE_MAP += f"| {row['ordinal']} | `{row['candidate_id']}` | {row['module']} | {row['feature_class']} | {row['user_job']} | {row['canonical_owner']} |\n"

MODULE_MAP += """

## Provisional P1 source claims

`MED-RBAC-01`, `MED-CD-SCOPE-01`, and `MED-CD-ATOMICITY-01` require independent current-source review before they can become final findings. They also require the appropriate runtime, role/Site, failure, and concurrency gates before closure or production-readiness can be claimed.

## Evidence boundary

- Static routes, controllers, services, pages, migrations, and test files are locators, not executed proof.
- The single-tenant, multi-Site architecture is assessed through approved Site scope, exact action capability, canonical ownership, direct-object concealment, and privacy boundaries.
- No benchmark mapping, task/ease score, journey, responsive viewport, runtime test, or all-eight-pass credit is awarded here.
- The historical 904-feature register remains a crosswalk only. Nothing in this wave promotes its denominator or numerator.
"""


DASHBOARD_TEMPLATE = Template(r"""<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Oblivion Findings current-source audit</title>
  <style>
    :root{color-scheme:light;--ink:#172033;--muted:#5f6b7d;--line:#dce2ec;--panel:#fff;--bg:#f4f6fb;--brand:#5b55f6;--warn:#a04800;--warnbg:#fff2db;--ok:#08794f;--shadow:0 8px 24px rgba(27,35,58,.07)}
    *{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}
    a{color:#413ad8;text-decoration-thickness:1px;text-underline-offset:3px}a:focus-visible{outline:3px solid #8d88ff;outline-offset:3px;border-radius:4px}
    header{background:linear-gradient(135deg,#1c2140 0%,#3f399f 100%);color:#fff;padding:28px max(20px,calc((100vw - 1180px)/2)) 32px}
    .eyebrow{font-size:.78rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#cbc9ff}.hero{display:flex;gap:24px;align-items:end;justify-content:space-between}.hero h1{font-size:clamp(1.8rem,4vw,3rem);line-height:1.08;margin:7px 0 8px;max-width:820px}.hero p{margin:0;color:#e5e4ff;max-width:780px}.badge{display:inline-flex;white-space:nowrap;align-items:center;border:1px solid #f2c675;background:#3e2b18;color:#ffe5b5;border-radius:999px;padding:9px 13px;font-weight:800}
    nav{background:#fff;border-bottom:1px solid var(--line);position:sticky;top:0;z-index:4;overflow:auto}nav div{max-width:1180px;margin:auto;display:flex;gap:20px;padding:11px 20px;white-space:nowrap}nav a{color:#39445a;font-weight:700;text-decoration:none}
    main{max-width:1180px;margin:0 auto;padding:24px 20px 64px}.notice{background:var(--warnbg);border-left:5px solid #e58d22;padding:14px 16px;border-radius:10px;margin-bottom:22px;color:#633000}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.card,.panel{background:var(--panel);border:1px solid var(--line);box-shadow:var(--shadow);border-radius:14px}.card{padding:17px}.card strong{display:block;font-size:1.65rem;line-height:1.15}.card span{display:block;color:var(--muted);margin-top:5px}.card small{display:block;margin-top:9px;color:#717d90}.panel{min-width:0;padding:20px;margin-top:20px}.panel h2{font-size:1.25rem;margin:0 0 5px}.panel>p{color:var(--muted);margin:0 0 16px}.table-wrap{max-width:100%;overflow-x:auto;border:1px solid var(--line);border-radius:10px}table{width:100%;border-collapse:collapse;min-width:680px}th,td{text-align:left;padding:11px 12px;border-bottom:1px solid var(--line);vertical-align:top}th{background:#f7f8fc;color:#414d62;font-size:.82rem}tr:last-child td{border-bottom:0}.zero{color:#a03920;font-weight:800}.partial{color:var(--warn);font-weight:800}.good{color:var(--ok);font-weight:800}.split{display:grid;grid-template-columns:1.15fr .85fr;gap:20px}.split>*{min-width:0}.list{margin:0;padding-left:20px}.list li+li{margin-top:8px}.mono{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:.88em;overflow-wrap:anywhere}.footer{color:var(--muted);font-size:.88rem;margin-top:24px}
    @media(max-width:900px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.split{grid-template-columns:1fr}.hero{align-items:flex-start;flex-direction:column}.badge{align-self:flex-start}}
    @media(max-width:520px){header{padding:22px 16px 26px}main{padding:18px 14px 48px}.cards{grid-template-columns:1fr 1fr;gap:10px}.card{padding:14px}.card strong{font-size:1.35rem}.panel{padding:16px}.badge{white-space:normal}nav div{padding-inline:16px}}
  </style>
</head>
<body>
  <header>
    <div class="eyebrow">Oblivion Findings · comprehensive audit restart</div>
    <div class="hero"><div><h1>Fresh current-source audit</h1><p>Evidence is pinned to application commit <span class="mono">$application_short</span>. Historical audit percentages are retained only as provenance and are not promoted.</p></div><div class="badge">IN PROGRESS · NOT COMPREHENSIVE</div></div>
  </header>
  <nav aria-label="Audit sections"><div><a href="#progress">Progress</a><a href="#pages">Pages</a><a href="#backend">Backend</a><a href="#features">Features</a><a href="#findings">Provisional findings</a><a href="#gaps">Gaps</a></div></nav>
  <main>
    <div class="notice" role="status"><strong>No completion claim:</strong> current runtime routes, tests, task/ease scores, benchmark mappings, journeys, responsive families, material states, and all-eight-pass module credit remain at zero or unestablished.</div>
    <section id="progress" class="cards" aria-label="Current audit progress">
      <div class="card"><strong>8,454</strong><span>tracked source paths</span><small>committed-tree census</small></div>
      <div class="card"><strong>3,217</strong><span>static route callsites</span><small>not runtime routes</small></div>
      <div class="card"><strong>963</strong><span>resolver TSX paths</span><small>semantic page denominator open</small></div>
      <div class="card"><strong>62</strong><span>grouped feature candidates</span><small>54 H · 8 D · 0 M</small></div>
      <div class="card"><strong>3 / 11</strong><span>formal assignments</span><small>minimum not met</small></div>
      <div class="card"><strong class="zero">0</strong><span>current runtime tests</span><small>not executed</small></div>
      <div class="card"><strong class="zero">0</strong><span>current-build browser credit</span><small>deployment identity unknown</small></div>
      <div class="card"><strong class="zero">0</strong><span>all-pass modules</span><small>module denominator still open</small></div>
    </section>

    <section id="pages" class="panel"><h2>Current Inertia page partition</h2><p>The current resolver has an exact source partition, but the 25 unimported candidates still need manual adjudication.</p><div class="table-wrap"><table><thead><tr><th>Partition</th><th>Count</th><th>Credit</th></tr></thead><tbody><tr><td>Matched backend render roots</td><td>711</td><td class="partial">source locator</td></tr><tr><td>Unrendered but imported</td><td>227</td><td class="partial">requires ownership classification</td></tr><tr><td>Unrendered and unimported</td><td>25</td><td class="partial">manual adjudication required</td></tr><tr><td><strong>Resolver total</strong></td><td><strong>963</strong></td><td class="zero">not a final page denominator</td></tr></tbody></table></div></section>

    <div class="split">
      <section id="backend" class="panel"><h2>Static backend reconciliation</h2><p>Directory and declaration scopes are kept separate.</p><div class="table-wrap"><table><thead><tr><th>Family</th><th>Current source locator</th></tr></thead><tbody><tr><td>Controller-directory PHP paths</td><td>561</td></tr><tr><td>Controller-suffix named classes</td><td>544</td></tr><tr><td>Eloquent model candidates app-wide</td><td>779</td></tr><tr><td>Policy-directory classes</td><td>75</td></tr><tr><td>Service-directory paths</td><td>725</td></tr><tr><td><span class="mono">app/Domain</span> declarations</td><td>1,384</td></tr><tr><td>Migrations</td><td>978</td></tr><tr><td>Test-tree paths</td><td>1,509</td></tr></tbody></table></div></section>
      <section id="features" class="panel"><h2>Discovery wave 01</h2><p>Eight module families are partially represented.</p><ul class="list"><li>Clients: 8</li><li>Care &amp; Clinical: 8</li><li>eMAR: 10</li><li>Incidents &amp; Safeguarding: 7</li><li>HR: 9</li><li>Workforce: 8</li><li>Frontline Workspaces: 5</li><li>Operations: 7</li></ul></section>
    </div>

    <section id="findings" class="panel"><h2>Provisional current-source P1 claims</h2><p>These are not final findings. Each needs independent review and the matching runtime, role/Site, failure, or concurrency gate.</p><div class="table-wrap"><table><thead><tr><th>ID</th><th>Source concern</th><th>Status</th></tr></thead><tbody><tr><td class="mono">MED-RBAC-01</td><td>Broad medication-order authority appears to reach controlled operations despite dedicated controlled capabilities.</td><td class="partial">independent review pending</td></tr><tr><td class="mono">MED-CD-SCOPE-01</td><td>Controlled-drug and destruction relationship IDs may not all pass through canonical medication scope decisions before use.</td><td class="partial">independent review pending</td></tr><tr><td class="mono">MED-CD-ATOMICITY-01</td><td>Register, stock, destruction, and relationship checks may not share one owner-first transactional boundary.</td><td class="partial">concurrency review pending</td></tr></tbody></table></div></section>

    <section id="gaps" class="panel"><h2>Literal completion gates still open</h2><div class="split"><ul class="list"><li>Current framework/provider runtime routes</li><li>Final page and canonical feature denominators</li><li>Complete backend/data/test ownership</li><li>97-project observer/neutralizer/comparator evidence</li><li>One task script and ten ease dimensions per human feature</li></ul><ul class="list"><li>Eight cross-module journeys at required viewports</li><li>Hero, overlay, trigger, and material-state universes</li><li>Current-build browser and safe runtime lanes</li><li>Every module through Passes 1–8</li><li>Fresh Pass 8 review, freeze, reconciliation, and no-live-agent gate</li></ul></div></section>

    <section class="panel"><h2>Evidence files</h2><ul class="list"><li><a href="00-executive-summary.md">Executive summary</a></li><li><a href="01-repository-module-map.md">Module and capability map</a></li><li><a href="evidence/source/current-static-semantic-census.json">Static semantic census JSON</a></li><li><a href="evidence/source/current-feature-discovery-wave-01.json">Feature discovery JSON</a></li><li><a href="evidence/source/formal-source-wave-01-agent-register.json">Formal assignment register JSON</a></li><li><a href="13-unresolved-questions-and-evidence-gaps.md">Unresolved evidence gaps</a></li></ul></section>
    <p class="footer">Generated deterministically at $generated_at. Audit artifacts only; no application remediation is authorised by this dashboard.</p>
  </main>
</body>
</html>
""")


def main() -> None:
    write_json("evidence/source/current-static-semantic-census.json", SEMANTIC)
    write_json("evidence/source/current-feature-discovery-wave-01.json", FEATURES)
    write_json("evidence/source/formal-source-wave-01-agent-register.json", AGENT_REGISTER)
    write_text("01-repository-module-map.md", MODULE_MAP)
    write_text(
        "audit-dashboard.html",
        DASHBOARD_TEMPLATE.substitute(application_short=APPLICATION_COMMIT[:12], generated_at=GENERATED_AT),
    )
    dashboard_builder = Path(__file__).with_name("build-current-audit-dashboard.py")
    if dashboard_builder.exists():
        runpy.run_path(str(dashboard_builder), run_name="__main__")


if __name__ == "__main__":
    main()
