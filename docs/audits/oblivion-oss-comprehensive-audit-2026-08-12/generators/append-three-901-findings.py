#!/usr/bin/env python3
"""Idempotently add the three source-backed 901-register findings.

Audit-artifact generator only. It does not touch application code, data,
configuration, routes, tests, browser state, deployment state, or Git history.
"""

from __future__ import annotations

import json
import os
import hashlib
from collections import Counter
from pathlib import Path


GENERATOR_DIR = Path(__file__).resolve().parent
AUDIT = GENERATOR_DIR.parent
SOURCE = AUDIT / "evidence" / "source"
FINDINGS = AUDIT / "findings.json"
MANIFEST = SOURCE / "working-capability-manifest-901.json"
COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
NEW_IDS = {
    "AUTH-EMAIL-VERIFY-CONTRACT-01",
    "HR-COMPLIANCE-EXPORT-PERMISSION-01",
    "HR-COMPLIANCE-RENEWALS-DISCLOSURE-01",
    "VIS-SYSTEM-USERS-COUNT-01",
}

# Source-proven, exact-current finding links recovered after the 901 manifest
# route/backend enrichment.  These links add accountability to an existing
# finding; they do not assert runtime reproduction or finding closure.
EXACT_FINDING_LINK_WAVE = {
    "INCIDENT-RECOVERY-01": ["CAP-INC-INCIDENT-AUTHOR"],
    "PRIV-DSR-01": ["CAP-PRIV-DSR-EXPORT-PACKAGE-GENERATION"],
    "PRIV-STATEMENT-01": ["CAP-PUB-PRIVACY-STATEMENT"],
    "VIS-DEPLOYED-DRIFT-01": ["CAP-CR-ALERT-TRIAGE"],
    "VIS-MOBILE-NAV-01": ["CAP-PLAT-STAFF-DASHBOARD"],
    "VIS-RESPONSIVE-OVERFLOW-01": ["CAP-HS-CORRECTIVE-ACTION-DELIVERY-CLOSURE"],
    "ARCH-P0-A": [
        "CAP-CR-ALERT-COLLABORATION",
        "CAP-CR-ALERT-TASKS",
        "CAP-CR-ALERT-TIME-TRACKING",
        "CAP-CR-ALERT-WATCHERS",
        "CAP-CR-ESCALATION-LIFECYCLE",
        "CAP-CR-EVIDENCE-PACK-ASSEMBLY",
        "CAP-CR-PLAYBOOK-RUN",
    ],
    "CATER-SCOPE-003": ["CAP-SITE-MEAL-PLANNING"],
    "CATER-STOCK-002": ["CAP-SITE-MEAL-SERVICE-COMPLETION"],
    "CLIN-SCHEDULE-01": ["CAP-CLIN-FRONTLINE-OBSERVATION-RECORDING"],
    "CLIN-SITE-01": [
        "CAP-CLIN-BEHAVIOUR-REGISTER",
        "CAP-CLIN-EVENT-REGISTER-RECORD",
        "CAP-CLIN-EVENT-REVIEW-ESCALATION-CLOSURE",
        "CAP-CLIN-OBSERVATION-REGISTER-RECORD",
    ],
    "CTRL-RBAC-001": ["CAP-CR-ALERT-TRIAGE"],
    "FIN-DONOR-FUND-01": ["CAP-FIN-DONOR-FUND-TRANSACTIONS"],
    "FLEET-MED-WITNESS-01": [
        "CAP-FLEET-RESIDENT-TRANSPORT-LIFECYCLE",
        "CAP-FLEET-RESIDENT-TRANSPORT-MEDICATION-CUSTODY",
    ],
    "FLEET-TRANSPORT-01": [
        "CAP-FLEET-RESIDENT-TRANSPORT-LIFECYCLE",
        "CAP-FLEET-RESIDENT-TRANSPORT-MEDICATION-CUSTODY",
    ],
    "HS-ASSURANCE-01": ["CAP-HS-INVESTIGATION-REVIEW-COMPLETION"],
    "HS-CLOSE-01": ["CAP-HS-EVENT-LIFECYCLE"],
    "HS-NOTIFIABLE-01": ["CAP-INC-INCIDENT-AUTHOR"],
    "HS-SITE-01": [
        "CAP-HS-BEHAVIOUR-SUPPORT-PLANS",
        "CAP-HS-COMMAND-CENTRE",
        "CAP-HS-EVENT-LIFECYCLE",
        "CAP-HS-EVENT-WORKSAFE-NOTIFICATION",
        "CAP-HS-INJURY-CAPACITY-LIFECYCLE",
        "CAP-HS-RESTRAINT-ATTACHMENT-DOWNLOAD",
        "CAP-HS-RESTRAINT-EVENTS",
        "CAP-HS-RESTRAINT-REGISTER-EXPORT",
    ],
    "INTEG-WEBHOOK-001": ["CAP-PLAT-WEBHOOK-RECEIVER"],
    "MED-COMP-01": ["CAP-MED-STAFF-COMPETENCY-REGISTER"],
    "MED-OVERRIDE-01": ["CAP-MED-API-ADMINISTRATION-RECORD-CORRECT"],
    "MED-RBAC-01": [
        "CAP-MED-CD-DISCREPANCY-CLOSURE",
        "CAP-MED-CD-REGISTER-BALANCE",
        "CAP-MED-DESTRUCTION-REGISTER",
        "CAP-MED-PHARMACY-ORDER-LIFECYCLE",
        "CAP-MED-STOCK-CONTROL",
    ],
    "MED-SCOPE-01": [
        "CAP-MED-GUIDED-ROUND-EXECUTION",
        "CAP-MED-MEDICATION-ORDER-LIFECYCLE",
        "CAP-MED-PRESCRIPTION-LIFECYCLE",
        "CAP-MED-WORKER-PRN-ADMINISTRATION",
        "CAP-MED-WORKER-PRN-EFFECTIVENESS",
        "CAP-MED-WORKER-SCHEDULED-DOSE-RECORD",
    ],
    "MED-VERIFY-01": [
        "CAP-MED-MEDICATION-ORDER-LIFECYCLE",
        "CAP-MED-MEDICATION-ORDER-VERIFICATION",
    ],
    "RESP-EVIDENCE-01": [
        "CAP-RESP-EVIDENCE-PACK-DOWNLOAD",
        "CAP-RESP-EVIDENCE-PACK-LIFECYCLE",
    ],
    "RESP-SCOPE-01": [
        "CAP-RESP-DAILY-NOTE-AUTHOR",
        "CAP-RESP-EVIDENCE-PACK-DOWNLOAD",
        "CAP-RESP-EVIDENCE-PACK-LIFECYCLE",
        "CAP-RESP-STAY-CLINICAL-RECONCILIATION",
    ],
    "RESP-STATE-01": [
        "CAP-RESP-BOOKING-LIFECYCLE",
        "CAP-RESP-BOOKING-REQUEST-APPROVAL",
        "CAP-RESP-BOOKING-REQUEST-INTAKE",
        "CAP-RESP-STAY-LIFECYCLE",
    ],
    "RETENTION-EXEC-01": [
        "CAP-PRIV-RETENTION-DELETION-EXECUTION",
        "CAP-PRIV-RETENTION-POLICY-REGISTER",
    ],
    "SAFE-NESTED-01": [
        "CAP-INC-SAFEGUARDING-ACTION-PLAN",
        "CAP-INC-SAFEGUARDING-EXTERNAL-REPORT",
        "CAP-INC-SAFEGUARDING-INVESTIGATION",
    ],
    "SAFE-SENSITIVITY-01": ["CAP-INC-SAFEGUARDING-TRIAGE-OWNERSHIP"],
    "SAFE-TERMINAL-SYNC-01": [
        "CAP-CR-ALERT-RESPONSE-CLOSURE",
        "CAP-HS-EVENT-LIFECYCLE",
        "CAP-INC-SAFEGUARDING-STATUS-CLOSURE",
    ],
    "SEC-UNIFI-TLS-01": ["CAP-SITE-SITE-INTEGRATION-SYNC-OVERRIDES"],
    "TASK-RBAC-001": ["CAP-DAY-ALL-TASKS-WORKBENCH", "CAP-DAY-TASK-REPORT"],
    "TASK-WATCH-002": ["CAP-DAY-ALL-TASKS-WORKBENCH"],
    "WF-HR-PROFILE-SITE-PRIVACY": [
        "CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE",
        "CAP-HR-HR-API-EMPLOYEES",
    ],
}
EXACT_FINDING_LINK_WAVE_UPSTREAM_SHA256 = (
    "cfad25b70451490a806b4ba4178b8512f4e697340f4c2e4830734aa7674a6f2f"
)
EXACT_FINDING_LINK_WAVE_DECISION_ID = "exact-current-901-source-intersection-wave-2026-08-13"


def base(
    *, finding_id: str, feature_ids: list[str], module: str, submodule: str,
    actor: str, priority: str = "P1", effort: str = "S",
) -> dict:
    return {
        "id": finding_id,
        "feature_ids": feature_ids,
        "passes": ["P1", "P2", "P5", "P7", "P8"],
        "module": module,
        "submodule": submodule,
        "actor_and_job": actor,
        "route_url": {"summary": "Static source intersection.", "route_names": [], "route_paths": []},
        "frontend_anchor": {"summary": "No runtime UI claim.", "page_files": [], "audited_commit": COMMIT},
        "visual_context": {
            "visual_id": "None assigned", "classification": "Source-inferred",
            "role": "Representative role unavailable", "site_scope": "Single tenant; role/site/ownership dependent",
            "viewport": "Not safely reproduced", "state": "Static source trace",
            "pattern_type": "source finding", "component_anchor": "See source anchors",
            "screenshot_reference": "None—no screenshot is claimed",
            "internal_baseline": "Canonical native authorization and workflow conventions",
        },
        "pattern_implementation": "Static source behavior only; no deployed or rendered defect is claimed.",
        "backend_anchors": [],
        "current_behavior": "See source evidence.",
        "current_workflow": {
            "summary": "Source-reviewed; runtime unexecuted.",
            "failure_sequence": "Conditional source-inferred sequence; not reproduced.",
            "boundary": "Role, permission, ownership, direct-object and privacy boundary.",
            "completion_evidence": "Static source trace only.",
        },
        "ease_evidence": {
            "validation_status": "Blocked—source finding retained; representative-role runtime and usability validation unexecuted",
            "evidence_basis": "Source-observed behavior with bounded inference",
            "current_scores": {
                "discoverability": 0, "comprehension": 0, "learnability": 0, "efficiency": 0,
                "error_prevention": 1, "recovery": 0, "accessibility": 0,
                "safety_and_trust": 1, "consistency": 1, "cross_module_continuity": 1,
            },
            "friction": {
                "completion_time": "Not measured", "step_count": "Not measured",
                "required_field_count": "Not measured", "decision_count": "Owner decision required",
                "context_switches": "Not measured", "dead_ends": "Runtime unknown",
                "recovery_path": "Fail closed without partial disclosure or mutation; retain a safe retry or review path.",
            },
            "target_scores": {"all_dimensions": 4, "safety_critical_error_prevention_and_trust": 5},
            "independent_review": "Representative-role execution remains required.",
        },
        "evidence": {
            "anchors": [], "existing_tests": [], "tests_executed": False,
            "browser_claim_limit": "No runtime response, disclosure, denial or rendered defect is claimed.",
        },
        "problem_root_cause": "See source-backed finding detail.",
        "impact": "Conditional impact; runtime exploitability or failure remains unverified.",
        "benchmark": {
            "selected": "No credible match", "url_and_sha": "", "verified_behavior": "",
            "outcome": "Documented no credible match", "no_match_evidence": "Target-specific adjudication is retained in the 901 benchmark evidence.",
        },
        "neutral_requirements": "Use one explicit, testable native contract for the affected actor, state and completion boundary.",
        "better_oblivion_design": "Use canonical native policy/workflow ownership and one fail-closed decision.",
        "target_ease": {
            "scores": {"all_dimensions": 4, "safety_critical_error_prevention_and_trust": 5},
            "measurable_outcome": "Allowed actors complete safely; denied actors receive no partial disclosure or effect.",
        },
        "cross_module_effects": "Preserve canonical owners and avoid competing authorization or state sources.",
        "rbac_privacy": "Single tenant with multiple sites; enforce role, permission, ownership, direct-object denial and minimum-necessary disclosure.",
        "priority": priority,
        "effort": effort,
        "dependencies_sequence": "Product/domain owner decision, authorization design, isolated tests, then representative-role validation.",
        "proposed_owner": "Product Owner, Authorization Platform Owner, Security and Privacy",
        "confidence": "High for static source observation; runtime unexecuted",
        "source_boundary": "Source review only. No deployed exploit, legal conclusion or runtime completion is claimed.",
        "interim_safeguard": "Limit access to explicitly approved roles until the boundary is decided and tested.",
        "acceptance_criteria": [],
        "missing_tests": [],
        "validation_plan": [],
        "official_sources": [],
        "statement_types": {
            "source": "Current behavior and anchors are source-observed at the audited commit.",
            "official_source": "No legal, regulatory or standards-conformance conclusion is asserted.",
            "inference": "Risk or failure sequence is conditional and was not reproduced at runtime.",
            "specialist_decision": "Named owners must decide the intended policy and exceptions.",
        },
        "official_source_proposition_keys": [],
        "feature_link_reconciliation": {
            "method": "Exact current 901-manifest route/page/source intersection.",
            "projection_status": "exact current working-manifest links; runtime validation blocked",
            "legacy_feature_ids": [], "decisions": [], "uncertainties": [],
        },
    }


email = base(
    finding_id="AUTH-EMAIL-VERIFY-CONTRACT-01",
    feature_ids=["CAP-AUTH-EMAIL-VERIFICATION-LIFECYCLE"],
    module="Authentication and identity",
    submodule="Email-verification lifecycle contract",
    actor="A newly registered or still-unverified approved user verifies mailbox control before accessing verified routes.",
)
email.update({
    "route_url": {
        "summary": "Exact current-manifest prompt, resend and signed-verification lifecycle.",
        "route_names": ["verification.notice", "verification.send", "verification.verify"],
        "route_paths": ["email/verify", "email/verification-notification", "email/verify/{id}/{hash}"],
    },
    "frontend_anchor": {
        "summary": "Verification prompt and authenticated resend action.",
        "page_files": ["resources/js/pages/auth/verify-email.tsx:10-42"], "audited_commit": COMMIT,
    },
    "backend_anchors": [
        "app/Models/User.php:17-26,96-124", "config/fortify.php:133-155",
        "vendor/laravel/framework/src/Illuminate/Auth/Middleware/EnsureEmailIsVerified.php:31-41",
        "vendor/laravel/framework/src/Illuminate/Auth/Listeners/SendEmailVerificationNotification.php:16-20",
        "vendor/laravel/fortify/routes/routes.php:82-97",
        "vendor/laravel/fortify/src/Http/Controllers/EmailVerificationNotificationController.php:19-29",
        "vendor/laravel/fortify/src/Http/Controllers/VerifyEmailController.php:18-28",
    ],
    "current_behavior": "App\\Models\\User inherits verification methods through Illuminate\\Foundation\\Auth\\User but does not implement Illuminate\\Contracts\\Auth\\MustVerifyEmail. The Registered listener and verified middleware both condition behavior on that contract; direct Fortify prompt, resend and signed-verify controllers remain source-reachable through inherited methods.",
    "current_workflow": {
        "summary": "Static source and test-source review prove the model/contract mismatch; tests and a representative account were not executed.",
        "failure_sequence": "The standard listener need not send the initial message and verified middleware need not challenge an unverified App User because both contract conditions are false; direct prompt/resend/verify remains available.",
        "boundary": "Mailbox-control assurance, account identity and protected-route enforcement.",
        "completion_evidence": "Source trace only; no notification, login or protected-route request was executed.",
    },
    "problem_root_cause": "A contract-sensitive framework lifecycle is enabled without making the authenticatable user model satisfy the MustVerifyEmail contract.",
    "impact": "If verification is intended as an access control, approved users could reach verified routes without mailbox proof and may not receive the automatic initial message; runtime exploitability is unverified.",
    "benchmark": {
        "selected": "Keycloak", "url_and_sha": "https://github.com/keycloak/keycloak@7c34342861092b8b8ce8b48dbc0f99ca3c3e541d",
        "verified_behavior": "Pinned required-action and token-handler loci establish a coherent verification lifecycle.",
        "outcome": "Native benchmark", "no_match_evidence": "",
    },
    "neutral_requirements": "Make verification policy internally consistent across automatic notification, prompt/resend, signed completion and protected-route enforcement.",
    "better_oblivion_design": "Use one account-assurance contract that sends or queues the initial notification, supports throttled retry and denies protected work until completion.",
    "cross_module_effects": "Align registration, approval, password setup/reset, email change, profile and two-factor policy around one canonical assurance state.",
    "proposed_owner": "Authentication and Identity Owner, Security",
    "interim_safeguard": "Until aligned and tested, treat approval and strong authentication as the effective access gates and manually confirm mailbox control before operational access.",
    "acceptance_criteria": [
        "Document which account types require verification and every explicit exception.",
        "A newly registered required account receives the initial notification or a visible retryable pending state.",
        "An authenticated unverified account is denied every verified route.",
        "Prompt, throttled resend, valid/invalid/expired link and already-verified idempotency work safely.",
    ],
    "missing_tests": [
        "Registered event notifies App User", "Unverified App User denied a representative verified route",
        "Verified App User allowed", "Email-change and password setup/reset convergence",
    ],
    "validation_plan": [
        "Architecture test for the selected verification contract", "Feature tests for automatic notification and resend",
        "Feature tests for unverified denial and verified allowance", "Signed-link edge-case tests",
        "Representative approved-account browser execution in an isolated environment",
    ],
    "statement_types": {
        "source": "App User lacks MustVerifyEmail while the framework listener and middleware explicitly test that interface; direct controllers use inherited methods.",
        "official_source": "No legal or standards conclusion is asserted.",
        "inference": "Automatic notification and verified-route enforcement may be bypassed; no deployed journey was executed.",
        "specialist_decision": "Security and Identity owners must decide which accounts require verification and any exceptions.",
    },
    "feature_link_reconciliation": {
        "method": "Exact ROUTE-0324/0325/0326 and PAGE-0014 intersection with the coherent current lifecycle.",
        "projection_status": "one exact current link; runtime validation blocked",
        "legacy_feature_ids": ["PLAT-EMAIL-VERIFICATION-NOTIFICATION", "PLAT-EMAIL-VERIFICATION-PROMPT", "PLAT-VERIFY-EMAIL"],
        "decisions": [{
            "method": "coherent lifecycle absorption", "feature_ids": ["CAP-AUTH-EMAIL-VERIFICATION-LIFECYCLE"],
            "route_hits": [
                {"route_id": "ROUTE-0324", "route_name": "verification.send", "route_path": "email/verification-notification"},
                {"route_id": "ROUTE-0325", "route_name": "verification.notice", "route_path": "email/verify"},
                {"route_id": "ROUTE-0326", "route_name": "verification.verify", "route_path": "email/verify/{id}/{hash}"},
            ],
        }],
        "uncertainties": [{"reason": "Notification delivery and middleware behavior were not executed."}],
    },
})


permission = base(
    finding_id="HR-COMPLIANCE-EXPORT-PERMISSION-01",
    feature_ids=["CAP-FLEET-DRIVER-ELIGIBILITY-REGISTER-EXPORT", "CAP-HR-VETTING-CHECK-REGISTER-EXPORT", "HR-COMPLIANCE-EXPORT"],
    module="Human resources / Fleet", submodule="Compliance-register export permission envelope",
    actor="A vetting-register or driver-register viewer exports the register they are already authorised to view.",
)
permission.update({
    "route_url": {
        "summary": "Shared route dispatches staff, vetting and driver datasets; entry pages have independent gates.",
        "route_names": ["hr.compliance.export"],
        "route_paths": ["hr/compliance/export?dataset=staff", "hr/compliance/export?dataset=vetting", "hr/compliance/export?dataset=drivers"],
    },
    "frontend_anchor": {
        "summary": "Vetting and driver pages render unconditional Export controls.",
        "page_files": [
            "resources/js/pages/hr/vetting/index.tsx:121-146", "resources/js/pages/hr/drivers/index.tsx:123-147",
            "resources/js/pages/hr/compliance/index.tsx:355-357", "resources/js/pages/hr/compliance/components/compliance-hub-header.tsx:66-76",
        ], "audited_commit": COMMIT,
    },
    "backend_anchors": ["routes/hr.php:281-288,328-343,350-360", "app/Http/Controllers/Hr/ComplianceExportController.php:22-58"],
    "current_behavior": "The shared export route requires hr.compliance.view, then the controller additionally requires hr.vetting.view or hr.driver.view for those branches. Vetting and driver pages require only their specific view permission and render Export unconditionally, so a specific-only viewer can see an action that the outer route denies.",
    "current_workflow": {
        "summary": "Static source proves the route/page permission conjunction; deployed role bundles and runtime 403 were not established.",
        "failure_sequence": "A specific-only viewer opens an authorised register, selects Export and is denied by the unrelated outer hr.compliance.view gate.",
        "boundary": "Role capability, action discoverability, least privilege and recovery.",
        "completion_evidence": "Source trace only; no representative permission combination or CSV request was executed.",
    },
    "problem_root_cause": "A shared transport inherited the staff-compliance gate while dataset pages and controller branches use separate permissions, and the UI does not calculate the conjunction.",
    "impact": "Legitimate viewers can encounter a visible but unusable action, encouraging unnecessary privilege expansion or support workarounds; no disclosure is claimed.",
    "neutral_requirements": "Align page admission, action visibility, route middleware and controller authorization around one documented effective permission for each dataset.",
    "better_oblivion_design": "Use dataset-specific native export policies and derive each Export affordance from the same decision.",
    "cross_module_effects": "Preserve HR ownership of staff/vetting data, Fleet ownership of driver eligibility and one canonical decision per dataset.",
    "proposed_owner": "HR Product Owner, Fleet Product Owner, Authorization Platform Owner",
    "interim_safeguard": "Do not grant broad compliance access merely to make a specific export work; document the current conjunction for support staff.",
    "acceptance_criteria": [
        "Document actor and permission for all three exports.", "Specific viewers export their own dataset without unrelated privilege.",
        "Actors lacking a dataset permission cannot see an active control or retrieve it directly.",
        "UI visibility and route/controller authorization use the same decision.",
    ],
    "missing_tests": [
        "Vetting-only actor export", "Driver-only actor export", "Specific actor denied unrelated datasets",
        "Compliance-only actor denied vetting/drivers", "UI control visibility matrix",
    ],
    "validation_plan": [
        "Authorization matrix unit tests", "Synthetic specific-only role feature tests",
        "Architecture test for route/policy convergence", "Representative-role browser test", "CSV schema and minimum-data assertions",
    ],
    "statement_types": {
        "source": "Vetting/driver pages use specific permissions, their controls are unconditional, and the shared route additionally requires hr.compliance.view.",
        "official_source": "No legal or standards conclusion is asserted.",
        "inference": "A specific-only viewer would receive a 403; deployed bundles and runtime behavior were not executed.",
        "specialist_decision": "HR, Fleet and Authorization owners must decide the intended export permissions.",
    },
    "feature_link_reconciliation": {
        "method": "Exact ROUTE-1364 branch predicates and current page ownership.",
        "projection_status": "three exact current links; runtime role validation blocked",
        "legacy_feature_ids": ["HR-COMPLIANCE-EXPORT"],
        "decisions": [{
            "method": "shared route with target-specific dataset predicates",
            "feature_ids": ["HR-COMPLIANCE-EXPORT", "CAP-HR-VETTING-CHECK-REGISTER-EXPORT", "CAP-FLEET-DRIVER-ELIGIBILITY-REGISTER-EXPORT"],
            "route_hits": [
                {"feature_id": "HR-COMPLIANCE-EXPORT", "route_id": "ROUTE-1364", "predicate": "dataset=staff"},
                {"feature_id": "CAP-HR-VETTING-CHECK-REGISTER-EXPORT", "route_id": "ROUTE-1364", "predicate": "dataset=vetting"},
                {"feature_id": "CAP-FLEET-DRIVER-ELIGIBILITY-REGISTER-EXPORT", "route_id": "ROUTE-1364", "predicate": "dataset=drivers"},
            ],
        }],
        "uncertainties": [{"reason": "Deployed roles may bundle permissions; no representative request was executed."}],
    },
})


renewals = base(
    finding_id="HR-COMPLIANCE-RENEWALS-DISCLOSURE-01",
    feature_ids=["CAP-FLEET-DRIVER-ELIGIBILITY-REGISTER-EXPORT", "HR-COMPLIANCE-EXPORT"],
    module="Human resources / Fleet", submodule="Inactive renewals-export disclosure boundary",
    actor="An HR compliance viewer requests the inactive renewals CSV branch of the shared export endpoint.",
)
renewals.update({
    "route_url": {
        "summary": "Controller accepts renewals selector; no active frontend/test activator found.",
        "route_names": ["hr.compliance.export"], "route_paths": ["hr/compliance/export?dataset=renewals"],
    },
    "frontend_anchor": {"summary": "No dataset=renewals activator found.", "page_files": [], "audited_commit": COMMIT},
    "backend_anchors": ["routes/hr.php:281-288", "app/Http/Controllers/Hr/ComplianceExportController.php:22-58,133-157", "evidence/source/capability-denominator-901-adjudication.json"],
    "current_behavior": "dataset=renewals is accepted, defaults to hr.compliance.view, and streams both HrStaffComplianceStatus and HrDriverEligibility renewal rows. dataset=drivers requires hr.driver.view, but renewals does not. No active client activator or branch test was found.",
    "current_workflow": {
        "summary": "Static source proves a weaker permission and mixed output. The inactive branch is excluded from the capability denominator but remains request-addressable in source.",
        "failure_sequence": "A compliance-only actor manually selects renewals and the source path prepares driver identity, licence class, expiry and status without the driver-view check; no response was executed.",
        "boundary": "HR/Fleet data ownership, minimum-necessary disclosure and inactive-route reachability.",
        "completion_evidence": "Source trace and activator search only.",
    },
    "problem_root_cause": "A dormant mixed-domain branch falls through to the staff-compliance permission instead of enforcing every emitted data class's permission.",
    "impact": "If requestable by a compliance-only actor, driver renewal fields could be disclosed without driver-view permission; exploitability is unverified.",
    "neutral_requirements": "Every export selector must declare emitted models and enforce the complete permission envelope before headers, filenames or rows are emitted.",
    "better_oblivion_design": "Remove the unsupported selector or model it with explicit HR/Fleet ownership, actor, schema and fail-closed authorization.",
    "cross_module_effects": "Preserve separate HR compliance and Fleet driver-eligibility authority despite a shared date horizon.",
    "proposed_owner": "HR Product Owner, Fleet Product Owner, Authorization Platform Owner, Security and Privacy",
    "interim_safeguard": "Do not publish the renewals selector and treat broad compliance access as sensitive until isolated validation.",
    "acceptance_criteria": [
        "Decide whether renewals is supported or removed.", "If retained, document the actor and complete HR/Fleet permission envelope.",
        "Authorize before opening the stream or emitting any response metadata.",
        "A compliance-only actor receives 403/404 and no partial CSV.",
    ],
    "missing_tests": [
        "Renewals with compliance but no driver permission", "Renewals with complete intended permission",
        "No partial output on denial", "Unknown selector fail-closed", "Emitted-model/permission architecture test",
    ],
    "validation_plan": [
        "Architecture map of selectors to models and permissions", "Synthetic compliance-only role feature test",
        "Authorized mixed-domain test if retained", "CSV and denial assertions", "Repository-wide activator or removal proof",
    ],
    "statement_types": {
        "source": "renewals is accepted, defaults to hr.compliance.view and streams staff and driver renewal rows; no activator/test was found.",
        "official_source": "No legal or standards conclusion is asserted.",
        "inference": "A compliance-only actor could receive driver fields; no request was executed.",
        "specialist_decision": "HR, Fleet, Security, Privacy and Authorization owners must decide support and permissions.",
    },
    "feature_link_reconciliation": {
        "method": "ROUTE-1364 excluded parameter-branch intersection; accepted emitted-data owners linked for accountability.",
        "projection_status": "two related current links; excluded branch has zero completion credit; runtime blocked",
        "legacy_feature_ids": ["HR-COMPLIANCE-EXPORT", "DEAD-HR-COMPLIANCE-RENEWALS-EXPORT-BRANCH"],
        "decisions": [{
            "method": "excluded inactive branch with emitted-data accountability",
            "feature_ids": ["HR-COMPLIANCE-EXPORT", "CAP-FLEET-DRIVER-ELIGIBILITY-REGISTER-EXPORT"],
            "route_hits": [{"route_id": "ROUTE-1364", "predicate": "dataset=renewals", "evidence": "mixed HR/Fleet rows under default compliance permission"}],
        }],
        "uncertainties": [
            {"reason": "No accepted branch capability because no active activator/test was found."},
            {"reason": "Runtime requestability, grants and response were not executed."},
        ],
    },
})


user_counts = base(
    finding_id="VIS-SYSTEM-USERS-COUNT-01",
    feature_ids=["CAP-SET-USER-ACCOUNT-LIFECYCLE"],
    module="Settings and system access",
    submodule="System Users filtered-state summary counts",
    actor="An authorised system administrator filters the user register by role and relies on the summary counts to understand the account population.",
    priority="P2",
)
user_counts.update({
    "passes": ["P1", "P4", "P7", "P8"],
    "route_url": {
        "summary": "Browser-observed on the exact System Users index with the Clinical Lead role filter.",
        "route_names": ["system.users.index"],
        "route_paths": ["system/users?role=77"],
    },
    "frontend_anchor": {
        "summary": "PAGE-0862 renders one server stats object directly in PageHero and through animated OpsStatCard/StatTile instances.",
        "page_files": [
            "resources/js/pages/settings/users/index.tsx:145-180,245-303",
            "resources/js/components/ops-stat-card.tsx:45-67",
            "resources/js/components/page/stat-tile.tsx:113-136,169-209",
            "resources/js/components/page/page-hero-stats.tsx:48-102",
        ],
        "audited_commit": COMMIT,
    },
    "visual_context": {
        "visual_id": "None assigned—observation not screenshot-persisted",
        "classification": "Observed",
        "role": "Signed-in System Users administrator",
        "site_scope": "System-wide account administration",
        "viewport": "Connected Chrome viewport; exact dimensions not retained",
        "state": "Clinical Lead role filter with zero matching users",
        "pattern_type": "contradictory animated KPI counts",
        "component_anchor": "PAGE-0862 System Users PageHero and OpsStatCard row",
        "screenshot_reference": "None—capture timed out; no screenshot is claimed",
        "internal_baseline": "The PageHero direct rendering of the same non-negative server stats",
    },
    "pattern_implementation": "The live page displayed correct positive PageHero values while the lower shared StatTile animation displayed proportional negative values. The count-up progress has an upper clamp but no lower clamp.",
    "backend_anchors": [
        "app/Http/Controllers/System/UsersController.php:25-57,98-145",
        "resources/js/pages/settings/users/index.tsx:145-180,245-303",
        "resources/js/components/ops-stat-card.tsx:45-67",
        "resources/js/components/page/stat-tile.tsx:113-136,169-209",
    ],
    "current_behavior": "On /system/users?role=77, the register returned no Clinical Lead users. The PageHero showed positive global totals (83 total, 78 active, 50 staff), while the lower cards showed impossible negative values (-4, -4 and -2). Source passes the same non-negative stats object to both surfaces; PageHero renders directly, while StatTile computes progress with Math.min(..., 1) without clamping the lower bound.",
    "current_workflow": {
        "summary": "The empty role-filter result was coherent, but duplicated summaries contradicted each other with impossible animated negatives.",
        "failure_sequence": "Open System Users, select Clinical Lead, observe no matching rows, then read positive values in the hero and negative values in the lower cards.",
        "boundary": "Administrative comprehension, reporting integrity and trust; no authorization or data mutation failure is claimed.",
        "completion_evidence": "Current connected-Chrome observation plus audited-source trace; screenshot capture timed out and persistence duration was not measured.",
    },
    "problem_root_cause": "The shared count-up animation caps progress at one but does not clamp it at zero, allowing a callback timestamp earlier than the captured start time to produce a negative eased multiplier.",
    "impact": "Administrators can see impossible user, active-account and staff counts during filtering, undermining trust in access oversight. No incorrect server count or persisted corruption is claimed.",
    "benchmark": {
        "selected": "Benchmark unproved", "url_and_sha": "", "verified_behavior": "",
        "outcome": "Unproved—no completion credit", "no_match_evidence": "CAP-SET-USER-ACCOUNT-LIFECYCLE remains benchmark-unproved; this is not an NCM conclusion.",
    },
    "neutral_requirements": "Administrative counts remain non-negative, deterministic and mutually consistent across duplicated summaries during first render, filtering, reduced motion and slow-frame timing.",
    "better_oblivion_design": "Use one canonical stats payload with static rendering or a count-up progress value clamped to the closed interval zero through one.",
    "cross_module_effects": "StatTile is shared, so other KPI tiles may be exposed to the same arithmetic path; no other page is claimed affected without browser evidence.",
    "rbac_privacy": "No permission change is required. Use synthetic accounts and redact user identity in visual evidence.",
    "proposed_owner": "Design System Owner and Settings/System Access Owner",
    "confidence": "High for the observed contradiction and proportional source mechanism; exact timing remains unproved",
    "source_boundary": "Browser and source audit only. No screenshot is claimed, and no server-count, authorization or data-corruption defect is inferred.",
    "interim_safeguard": "Use the static PageHero totals and refresh if a negative animated card appears.",
    "acceptance_criteria": [
        "Count-up progress is clamped to zero through one for every frame.",
        "Hero and lower cards remain identical and non-negative from first paint through completion.",
        "A zero-result role filter never produces a negative KPI.",
        "Reduced-motion rendering shows final values immediately.",
    ],
    "missing_tests": [
        "StatTile callback timestamp earlier than start", "Lower and upper progress clamping",
        "System Users duplicated-summary consistency", "Zero-result role filter non-negative values",
    ],
    "validation_plan": [
        "Unit-test negative, zero, mid-range and over-duration progress",
        "Component-test controlled requestAnimationFrame timestamps",
        "Browser-test the zero-result role filter at all required viewports and retain a screenshot",
    ],
    "statement_types": {
        "source": "The controller supplies non-negative counts to both surfaces; PageHero renders directly and StatTile animates without a lower progress clamp.",
        "official_source": "No legal, security or standards-conformance conclusion is asserted.",
        "inference": "The proportional negative values and formula identify negative animation progress as the likely cause; deterministic reproduction remains outstanding.",
        "specialist_decision": "The Design System owner must define whether administrative KPIs animate and how first-frame/reduced-motion behavior works.",
    },
    "feature_link_reconciliation": {
        "method": "Exact current ROUTE-2959/PAGE-0862 intersection with System Users account lifecycle.",
        "projection_status": "one exact current link; browser-observed symptom; screenshot and deterministic reproduction pending",
        "legacy_feature_ids": ["SET-USERS", "CAP-SET-USERS-ACCOUNT-LIFECYCLE"],
        "decisions": [{
            "method": "exact routed page and controller intersection",
            "feature_ids": ["CAP-SET-USER-ACCOUNT-LIFECYCLE"],
            "route_hits": [{"route_id": "ROUTE-2959", "route_name": "system.users.index", "route_path": "system/users", "page_id": "PAGE-0862"}],
        }],
        "uncertainties": [
            {"reason": "Screenshot capture timed out."},
            {"reason": "Duration and repeatability were not measured."},
        ],
    },
})


data = json.loads(FINDINGS.read_text(encoding="utf-8-sig"))
rows = [row for row in data["findings"] if row.get("id") not in NEW_IDS]

# Keep the retained finding-level execution boundary aligned with the current
# 901-register human denominator.  These are status labels only: no task,
# browser flow, usability score, test, or runtime result is promoted here.
for row in rows:
    ease = row.get("ease_evidence")
    if not isinstance(ease, dict):
        continue
    status = ease.get("validation_status")
    if not isinstance(status, str):
        continue
    status = status.replace("0/784", "0/788")
    status = status.replace(
        "the broader 894-target finding-link rebuild remains incomplete",
        "the 901-target literal finding-link reconciliation remains partial",
    )
    if status.startswith("Blocked—finding retained from the superseded feature projection;"):
        status = (
            "Blocked—source finding retained; exact current feature linkage may remain partial, "
            "and representative-role execution plus independent ten-dimension validation are "
            "unperformed (0/788 human tasks executed)"
        )
    ease["validation_status"] = status

rows.extend([email, permission, renewals, user_counts])
ids = [row["id"] for row in rows]
if len(ids) != len(set(ids)):
    raise RuntimeError("Finding IDs are not unique")

manifest_ids = {row["working_key"] for row in json.loads(MANIFEST.read_text(encoding="utf-8"))["targets"]}
for row in (email, permission, renewals, user_counts):
    if not set(row["feature_ids"]) <= manifest_ids:
        raise RuntimeError(f"Unknown final feature ID in {row['id']}")

# Integrate only the exact links independently established from the current
# target route/backend envelopes.  Retain legacy/projection IDs as historical
# lineage, but never count them as literal current-manifest links.
finding_by_id = {row["id"]: row for row in rows}
if set(EXACT_FINDING_LINK_WAVE) - set(finding_by_id):
    raise RuntimeError(
        "Finding-link wave contains unknown finding IDs: "
        + ", ".join(sorted(set(EXACT_FINDING_LINK_WAVE) - set(finding_by_id)))
    )
wave_targets = {
    target
    for targets in EXACT_FINDING_LINK_WAVE.values()
    for target in targets
}
if wave_targets - manifest_ids:
    raise RuntimeError(
        "Finding-link wave contains unknown current target IDs: "
        + ", ".join(sorted(wave_targets - manifest_ids))
    )

wave_lines = [
    f"{finding_id}|{';'.join(sorted(set(targets)))}"
    for finding_id, targets in sorted(EXACT_FINDING_LINK_WAVE.items())
]
wave_generator_sha256 = hashlib.sha256("\n".join(wave_lines).encode("utf-8")).hexdigest()
for finding_id, targets in EXACT_FINDING_LINK_WAVE.items():
    row = finding_by_id[finding_id]
    exact_targets = sorted(set(targets))
    row["feature_ids"] = sorted(set(row.get("feature_ids", [])) | set(exact_targets))
    reconciliation = row.setdefault("feature_link_reconciliation", {})
    decisions = [
        decision
        for decision in reconciliation.setdefault("decisions", [])
        if decision.get("legacy_family_id") != EXACT_FINDING_LINK_WAVE_DECISION_ID
    ]
    decisions.append({
        "legacy_family_id": EXACT_FINDING_LINK_WAVE_DECISION_ID,
        "method": "source-proven exact current target route/backend intersection",
        "feature_ids": exact_targets,
        "source_anchors": list(row.get("backend_anchors", [])),
        "evidence": (
            "Read-only static reconciliation validated 137 target-route evidence pairs "
            "with zero mismatches across the 30-finding wave."
        ),
        "upstream_map_sha256": EXACT_FINDING_LINK_WAVE_UPSTREAM_SHA256,
        "generator_map_sha256": wave_generator_sha256,
    })
    reconciliation["decisions"] = decisions
    reconciliation["projection_status"] = (
        "literal_current_manifest_link_present; runtime_and_full_finding_adjudication_blocked"
    )

# The one remaining P0/P1 link gap is deliberately explicit.  Its only source
# owner is SignalProcessingService; the superficially similar alert target owns
# unrelated AlertController routes and is not a defensible substitute.
signal = finding_by_id["CTRL-SIGNAL-002"]
signal_reconciliation = signal.setdefault("feature_link_reconciliation", {})
signal_uncertainties = [
    uncertainty
    for uncertainty in signal_reconciliation.setdefault("uncertainties", [])
    if uncertainty.get("reason_code") != "no_exact_901_signal_service_owner"
]
signal_uncertainties.append({
    "reason_code": "no_exact_901_signal_service_owner",
    "reason": (
        "SignalProcessingService has no exact current 901 target owner; "
        "CAP-CR-ALERT-SENSOR-VALIDATION owns unrelated AlertController routes, "
        "so no literal current ID is assigned."
    ),
})
signal_reconciliation["uncertainties"] = signal_uncertainties

# The historical projection status is not a current denominator statement.
# Preserve whether a literal current ID exists while keeping runtime and
# target-specific finding validation explicitly blocked.
for row in rows:
    reconciliation = row.get("feature_link_reconciliation")
    if not isinstance(reconciliation, dict):
        continue
    if reconciliation.get("projection_status") != "provisional denominator blocked":
        continue
    has_current_id = any(feature in manifest_ids for feature in row.get("feature_ids", []))
    reconciliation["projection_status"] = (
        "literal_current_manifest_link_present; runtime_and_full_finding_adjudication_blocked"
        if has_current_id
        else "legacy_or_projection_links_only; exact_current_manifest_link_unproved"
    )

priority = Counter(row["priority"] for row in rows)
exact_links = [(row["id"], feature) for row in rows for feature in row.get("feature_ids", []) if feature in manifest_ids]
exact_finding_ids = {finding_id for finding_id, _ in exact_links}
p0_p1 = [row for row in rows if row["priority"] in {"P0", "P1"}]
p0_p1_exact = {row["id"] for row in p0_p1} & exact_finding_ids
uncertain = sum(bool(row.get("feature_link_reconciliation", {}).get("uncertainties")) for row in rows)
route_groups = sum(
    bool(decision.get("route_hits"))
    for row in rows
    for decision in row.get("feature_link_reconciliation", {}).get("decisions", [])
)
page_groups = sum(
    bool(decision.get("page_hits"))
    for row in rows
    for decision in row.get("feature_link_reconciliation", {}).get("decisions", [])
)

data["audit_status"] = (
    "Blocked—not comprehensive or complete. The corrected 901-target register is current (788H/111D/2M). "
    "All 3,024 routes and 962 pages have accepted-target or excluded-surface static dispositions; accepted IDs map to 2,985 routes and 935 pages. "
    "Benchmark/NCM completion credit is 302/901, visual final-ID linkage is 7,785/8,753, material-state linkage is 3,749/4,312, "
    f"and {len(rows)} source-backed findings are retained. Only {len(p0_p1_exact)}/{len(p0_p1)} P0/P1 findings contain a literal current-manifest ID; runtime remains unexecuted."
)
data["statement"] = "Full schema for every retained finding. The 901-row stable-ID manifest is current; static evidence, inference, official-source propositions and owner decisions remain separated. Runtime, representative-role and usability completion are not claimed."
data["counts"] = {
    "P0": priority.get("P0", 0), "P1": priority.get("P1", 0), "P2": priority.get("P2", 0), "P3": priority.get("P3", 0),
    "feature_link_reconciliation": {
        "projection_status": "901_current_literal_link_reconciliation_partial_not_runtime_validation",
        "working_accepted_capabilities": 901, "working_human_capabilities": 788,
        "earlier_894_derivation_superseded": True,
        "working_manifest": "evidence/source/working-capability-manifest-901.json",
        "working_manifest_sha256": "5b477cc3fa5e5343b223b7ba559919f708f945426f193dbb0510245771148900",
        "working_manifest_unique_stable_ids": 901,
        "stable_id_provenance": {"exact_current": 881, "source_stable": 5, "audit_assigned": 15},
        "route_enrichment": {"targets": 901, "relations": 3065, "unique_routes": 2985, "excluded_surface_relations": 39, "static_disposition_total": 3024},
        "page_enrichment": {"targets": 756, "relations": 1507, "unique_pages": 935, "excluded_surface_relations": 27, "static_disposition_total": 962},
        "backend_enrichment": {"targets": 728, "relations": 825, "unique_anchors": 466},
        "benchmark_mapping": {"eligible": 302, "verified_benchmark": 218, "documented_no_credible_match": 84, "completion_unproved": 599},
        "visual_linkage": {"assigned": 7785, "rows": 8753, "unresolved": 968, "unique_working_ids": 742},
        "material_state_linkage": {"assigned": 3749, "rows": 4312, "unresolved": 563, "unique_working_ids": 688},
        "final_feature_link_coverage_established": False,
        "findings": len(rows), "total_links": sum(len(row.get("feature_ids", [])) for row in rows),
        "literal_exact_current_links": len(exact_links), "literal_exact_current_targets": len({feature for _, feature in exact_links}),
        "findings_with_literal_exact_current_id": len(exact_finding_ids),
        "p0_p1_with_literal_exact_current_id": len(p0_p1_exact),
        "p0_p1_without_literal_exact_current_id": len(p0_p1) - len(p0_p1_exact),
        "findings_with_uncertainty": uncertain,
        "findings_without_literal_exact_current_id": len(rows) - len(exact_finding_ids),
        "route_intersection_groups": route_groups, "unique_page_intersection_groups": page_groups,
    },
}
data["findings"] = rows

required = set(rows[0])
for row in rows:
    missing = required - set(row)
    if missing:
        raise RuntimeError(f"{row['id']} missing schema keys: {sorted(missing)}")

temp = FINDINGS.with_suffix(".json.tmp")
temp.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
json.loads(temp.read_text(encoding="utf-8"))
os.replace(temp, FINDINGS)
print(json.dumps({"findings": len(rows), "priorities": dict(priority), "exact_links": len(exact_links), "p0_p1_exact": len(p0_p1_exact), "p0_p1": len(p0_p1)}, indent=2))
