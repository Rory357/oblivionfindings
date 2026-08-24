#!/usr/bin/env python3
"""Idempotently maintain the source-backed 902-register findings.

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
MANIFEST = SOURCE / "working-capability-manifest-902.json"
COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
NEW_IDS = {
    "AUTH-EMAIL-VERIFY-CONTRACT-01",
    "HR-COMPLIANCE-EXPORT-PERMISSION-01",
    "HR-COMPLIANCE-RENEWALS-DISCLOSURE-01",
    "VIS-SYSTEM-USERS-COUNT-01",
    "VIS-MY-DAY-HEADER-OVERFLOW-01",
    "HR-STAFF-CREATION-PATH-01",
    "SAFE-EVID-01",
    "FIN-PAYMENT-ALLOCATION-01",
    "FIN-EFTPOS-SETTLEMENT-01",
    "MED-ORDER-ERASURE-01",
    "PRIV-DSR-LIFECYCLE-01",
    "GOV-RESOLUTION-QUORUM-01",
    "MED-CD-VOID-REVERSAL-01",
    "MED-ADMIN-CORRECTION-API-BYPASS-01",
    "MED-ERROR-LIFECYCLE-TERMINAL-BYPASS-01",
    "FIN-FIXED-ASSET-DEPRECIATION-01",
    "PRIV-EVID-01",
}

# Source-proven, exact-current finding links recovered through the 902 manifest
# route/backend enrichment.  These links add accountability to an existing
# finding; they do not assert runtime reproduction or finding closure.
EXACT_FINDING_LINK_WAVE = {
    "CTRL-SIGNAL-002": ["CAP-CR-SIGNAL-TO-ALERT-PIPELINE"],
    "INCIDENT-RECOVERY-01": ["CAP-INC-INCIDENT-AUTHOR"],
    "PRIV-DSR-01": ["CAP-PRIV-DSR-EXPORT-PACKAGE-GENERATION"],
    "PRIV-DSR-LIFECYCLE-01": [
        "CAP-PRIV-DSR-FULFILLMENT-DECISION",
        "CAP-PRIV-DSR-IDENTITY-DEADLINE",
        "CAP-PRIV-DSR-INTAKE-CASE-MANAGEMENT",
    ],
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
    "FIN-PAYMENT-ALLOCATION-01": ["FIN-BILL", "FIN-PAYMENT-ALLOCATION"],
    "FIN-EFTPOS-SETTLEMENT-01": ["CAP-FIN-EFTPOS-BATCHES", "CAP-FIN-EFTPOS-TERMINALS"],
    "FIN-FIXED-ASSET-DEPRECIATION-01": ["CAP-ASSET-FIXED-ASSET-DEPRECIATION"],
    "GOV-RESOLUTION-QUORUM-01": [
        "CAP-GOV-RESOLUTION-FINALIZATION",
        "CAP-GOV-RESOLUTION-VOTING-CONFLICTS",
    ],
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
    "MED-ORDER-ERASURE-01": ["CAP-MED-MEDICATION-ORDER-LIFECYCLE"],
    "MED-CD-VOID-REVERSAL-01": [
        "CAP-MED-CD-REGISTER-BALANCE",
        "CAP-MED-DESTRUCTION-REGISTER",
    ],
    "MED-ADMIN-CORRECTION-API-BYPASS-01": ["CAP-MED-API-ADMINISTRATION-RECORD-CORRECT"],
    "MED-ERROR-LIFECYCLE-TERMINAL-BYPASS-01": [
        "CAP-MED-ERROR-REPORT-EVIDENCE",
        "CAP-MED-ERROR-REVIEW-CLOSURE",
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
    "PRIV-EVID-01": ["CAP-PRIV-SHARED-ATTACHMENT-SERVICE"],
    "SAFE-NESTED-01": [
        "CAP-INC-SAFEGUARDING-ACTION-PLAN",
        "CAP-INC-SAFEGUARDING-EXTERNAL-REPORT",
        "CAP-INC-SAFEGUARDING-INVESTIGATION",
    ],
    "SAFE-SENSITIVITY-01": ["CAP-INC-SAFEGUARDING-TRIAGE-OWNERSHIP"],
    "SAFE-EVID-01": [
        "CAP-INC-SAFEGUARDING-EVIDENCE-DOWNLOAD",
        "CAP-INC-SAFEGUARDING-EVIDENCE-MANAGEMENT",
    ],
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
EXACT_FINDING_LINK_WAVE_DECISION_ID = "exact-current-902-source-intersection-wave-2026-08-13"


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
        "method": "Exact current 902-manifest route/page/source intersection.",
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


my_day_overflow = base(
    finding_id="VIS-MY-DAY-HEADER-OVERFLOW-01",
    feature_ids=["CAP-DAY-MY-DAY-WORKSPACE"],
    module="Frontline and My Day",
    submodule="My Day responsive StaffHeader actions",
    actor="A worker opens My Day at the required 390 x 844 narrow viewport and needs the incident, live-status and notification controls to remain reachable without sideways panning.",
    priority="P2",
    effort="S",
)
my_day_overflow.update({
    "passes": ["P1", "P4", "P7", "P8"],
    "route_url": {
        "summary": "Browser-observed on the exact signed-in My Day index.",
        "route_names": ["my-day"],
        "route_paths": ["my-day"],
    },
    "frontend_anchor": {
        "summary": "ROUTE-1884/PAGE-0552 supplies report-incident, live-status and notification controls to the shared StaffHeader.",
        "page_files": [
            "resources/js/components/staff-header.tsx:154-172",
            "resources/js/pages/my-day/index.tsx:730-785",
            "resources/js/pages/my-day/index.tsx:788-795",
        ],
        "audited_commit": COMMIT,
    },
    "visual_context": {
        "visual_id": "VIS-001204 / BVIS-0010",
        "classification": "Observed",
        "role": "Demo Administrator",
        "site_scope": "No explicit site selector was visible in the captured state",
        "viewport": "390x844; document client width 373px; scroll width 457px",
        "state": "Initial My Day view before any domain action",
        "pattern_type": "responsive header overflow",
        "component_anchor": "resources/js/components/staff-header.tsx:168-172",
        "screenshot_reference": "evidence/browser/BVIS-0010-my-day-390x844-header-overflow-cropped.png",
        "internal_baseline": "The required narrow viewport should keep the page within the document width and preserve all primary header actions.",
    },
    "pattern_implementation": "StaffHeader keeps the action, live-status and notification controls in one ml-auto flex shrink-0 group with no narrow breakpoint wrapping, collapsing or prioritisation.",
    "backend_anchors": [
        "inventory.json:ROUTE-1884",
        "resources/js/components/staff-header.tsx:154-172",
        "resources/js/pages/my-day/index.tsx:730-795",
    ],
    "current_behavior": "At an actual 390x844 Chrome viewport, the document client width was 373px and scroll width was 457px: 84px of horizontal overflow. The notification control was partly off-screen and the document exposed sideways scrolling.",
    "current_workflow": {
        "summary": "The signed-in My Day page loads, but its shared header cannot fit the title plus three right-side controls at the required narrow viewport.",
        "failure_sequence": "Open /my-day at 390x844, observe the report-incident and live controls consume the remaining width, then see the notification bell clipped beyond the right edge and the page scroll horizontally.",
        "boundary": "Narrow-viewport reachability and accessibility; no domain mutation or authorization failure is claimed.",
        "completion_evidence": "Exact viewport measurement, DOM inspection, source trace and retained cropped screenshot.",
    },
    "ease_evidence": {
        "validation_status": "Observed at the required 390x844 viewport; representative Support Worker repetition and independent visual resampling remain unexecuted",
        "evidence_basis": "Browser measurement plus exact current source anchors",
        "current_scores": {
            "discoverability": 2, "comprehension": 3, "learnability": 2, "efficiency": 1,
            "error_prevention": 2, "recovery": 1, "accessibility": 1,
            "safety_and_trust": 2, "consistency": 1, "cross_module_continuity": 1
        },
        "friction": {
            "completion_time": "Not measured", "step_count": "Horizontal pan required to reach the far control",
            "required_field_count": "None", "decision_count": "None",
            "context_switches": "One viewport-level sideways navigation burden", "dead_ends": "Notification control is partly clipped",
            "recovery_path": "Use a wider viewport or zoom out; neither is an acceptable product recovery path."
        },
        "target_scores": {"all_dimensions": 4, "safety_critical_error_prevention_and_trust": 5},
        "independent_review": "Repeat with a Support Worker and verify the shared header at every required viewport.",
    },
    "evidence": {
        "anchors": [
            "evidence/browser/BVIS-0010-my-day-390x844-header-overflow-cropped.png",
            "evidence/browser/BVIS-0010-my-day-390x844-header-overflow-cropped.json",
            "05-browser-visual-coverage-matrix.csv:VIS-001204",
            "resources/js/components/staff-header.tsx:168-172",
        ],
        "existing_tests": [],
        "tests_executed": False,
        "browser_claim_limit": "Observed only for Demo Administrator on /my-day at 390x844; other roles and StaffHeader consumers are not claimed affected without repetition.",
    },
    "problem_root_cause": "The right-side StaffHeader group is shrink-0 and contains three controls, while the title area and shared header padding remain present. No narrow-width rule wraps, collapses or prioritises those controls.",
    "impact": "A narrow-screen worker can lose immediate access to notifications and must pan sideways on a primary frontline workspace, increasing missed-action and navigation risk.",
    "benchmark": {
        "selected": "Benchmark unproved", "url_and_sha": "", "verified_behavior": "",
        "outcome": "Unproved—no completion credit", "no_match_evidence": "CAP-DAY-MY-DAY-WORKSPACE remains benchmark-unproved; this finding is current product evidence, not an NCM conclusion."
    },
    "neutral_requirements": "At every required viewport the My Day document remains within its client width and all header actions remain reachable, labelled and keyboard ordered without horizontal page scrolling.",
    "better_oblivion_design": "At the narrow breakpoint, retain the primary safety action and move secondary live/notification controls into a labelled compact group or wrap them without obscuring the page title.",
    "cross_module_effects": "StaffHeader is shared, so the design-system owner should enumerate consumers with similarly dense action sets; only My Day is currently browser-proved.",
    "rbac_privacy": "No permission change is required. Preserve the same role and site visibility while changing only responsive presentation.",
    "proposed_owner": "Frontline/My Day Owner and Design System Owner",
    "confidence": "High for the exact My Day overflow and source mechanism; scope across other roles and pages remains unproved",
    "source_boundary": "Browser and source evidence only. No domain mutation, security exploit or all-role generalisation is claimed.",
    "interim_safeguard": "Use a wider viewport for My Day until the shared header is repaired and verified.",
    "acceptance_criteria": [
        "At 390x844, document scroll width is no greater than document client width.",
        "Report incident, live status and notifications remain visible and keyboard reachable.",
        "The title and date remain readable without overlap or clipping.",
        "The same checks pass at 1024x768, 1280x720 and 1440x900.",
    ],
    "missing_tests": [
        "My Day StaffHeader at 390x844",
        "Dense StaffHeader action wrapping/collapse",
        "Keyboard order and notification reachability at narrow width",
        "Document horizontal-overflow assertion across all required viewports",
    ],
    "validation_plan": [
        "Component-test StaffHeader with title, action, live indicator and notification bell at narrow width",
        "Browser-test /my-day at all four required viewports with Support Worker and Administrator",
        "Assert document scrollWidth <= clientWidth and retain screenshots plus focus-order evidence",
    ],
    "statement_types": {
        "source": "The shared header uses an unwrapping shrink-0 right-side group and My Day supplies three controls.",
        "official_source": "No legal, clinical or standards-conformance conclusion is asserted.",
        "inference": "Other StaffHeader consumers may be exposed only if they supply similarly dense controls; that broader scope is not yet browser-proved.",
        "specialist_decision": "The design-system and frontline owners must decide the narrow-width control priority and grouping."
    },
    "feature_link_reconciliation": {
        "method": "Exact current ROUTE-1884/PAGE-0552 and My Day source intersection.",
        "projection_status": "one exact current link; browser-observed at 390x844",
        "legacy_feature_ids": ["DAY-MY-DAY"],
        "decisions": [{
            "method": "exact routed page and source intersection",
            "feature_ids": ["CAP-DAY-MY-DAY-WORKSPACE"],
            "route_hits": [{"route_id": "ROUTE-1884", "route_name": "my-day", "route_path": "my-day", "page_id": "PAGE-0552"}]
        }],
        "uncertainties": [{"reason": "Support Worker repetition and other StaffHeader consumers remain unverified."}],
    },
})


staff_creation_path = base(
    finding_id="HR-STAFF-CREATION-PATH-01",
    feature_ids=["CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE", "CAP-SET-USER-ACCOUNT-LIFECYCLE"],
    module="Human resources and system access",
    submodule="Supported staff-account creation convergence",
    actor="An authorised administrator creates a staff identity with the dedicated Clinical Lead role through a supported product workflow.",
    priority="P1",
    effort="M",
)
staff_creation_path.update({
    "passes": ["P1", "P2", "P4", "P5", "P7", "P8"],
    "route_url": {
        "summary": "Two supported creation entry points were inspected: HR People for staff and System Users for account administration.",
        "route_names": ["hr.people.index", "hr.people.store", "system.users.create", "system.users.store"],
        "route_paths": ["hr/people", "hr/people", "system/users/create", "system/users"],
    },
    "frontend_anchor": {
        "summary": "The local HR People dialog accepts an optional site and the local System Users page contains a Staff branch, while the deployed pages expose a different, non-convergent workflow.",
        "page_files": [
            "resources/js/pages/hr/employees/index.tsx:342-386,471-506",
            "resources/js/components/hr/add-employee-dialog.tsx:128-162,193-205,396-412",
            "resources/js/pages/system/users/Create.tsx:57-98,124-209,272-290",
        ],
        "audited_commit": COMMIT,
    },
    "visual_context": {
        "visual_id": "BVIS-0011 plus the earlier signed-in HR People creation trace",
        "classification": "Observed",
        "role": "Demo Administrator",
        "site_scope": "System-wide account administration; the attempted HR profile needed a primary site choice",
        "viewport": "1280x720 for BVIS-0011; earlier HR People attempt viewport was not retained",
        "state": "No entered values on System Users; HR People wizard stopped before submission",
        "pattern_type": "cross-workflow creation dead end and deployed/source drift",
        "component_anchor": "PAGE-0448 AddEmployeeDialog and PAGE-0954 CreateUser",
        "screenshot_reference": "evidence/browser/BVIS-0011-system-users-staff-path-blocked.png",
        "internal_baseline": "One supported staff creation path must expose the required role and usable site context, then create one coherent user and HR profile.",
    },
    "pattern_implementation": "The deployed System Users page routes staff creation to HR People and exposes only Client and Next of Kin. The deployed HR People wizard reported no sites and required a Primary site, although the Sites workspace visibly listed 20 active test sites. The audited local source instead queries all sites, labels Primary site optional, and contains a Staff branch on System Users, demonstrating an unresolved deployed/source contract divergence.",
    "backend_anchors": [
        "app/Http/Controllers/Hr/EmployeeProfileController.php:178-190,332,509,578",
        "app/Http/Controllers/System/UsersController.php:create|store",
        "app/Http/Requests/Hr/StoreEmployeeProfileRequest.php:43-45",
        "resources/js/components/hr/add-employee-dialog.tsx:128-162,396-412",
        "resources/js/pages/system/users/Create.tsx:57-98,124-209,272-290",
    ],
    "current_behavior": "The signed-in HR People employee wizard could not submit because its required Primary site selector had no options, despite the Sites workspace showing 20 active test sites. The signed-in System Users create page then provided no Staff type and explicitly directed staff creation back to HR People. No account or HR profile was created. Current local source conflicts with both deployed observations: it queries all sites, treats primary_site_id as nullable, labels the field optional, and includes a Staff branch in CreateUser.",
    "current_workflow": {
        "summary": "Both supported UI entry points converge on a dead end for a new staff identity in the deployed test application.",
        "failure_sequence": "Open HR People, start Add employee, reach a required empty Primary site selector and disabled completion; open System Users Create, find only Client and Next of Kin and an instruction to return to HR People.",
        "boundary": "Staff identity/profile creation, role assignment, site context, deployment provenance and representative-role audit access.",
        "completion_evidence": "Two signed-in read-only browser observations, retained BVIS-0011 screenshot/sidecar, the earlier structured HR creation-attempt log, and exact local source anchors.",
    },
    "ease_evidence": {
        "validation_status": "Observed dead end for Demo Administrator; task completion is zero because submission was safely withheld and unavailable",
        "evidence_basis": "Two supported deployed workflows plus exact audited-source comparison",
        "current_scores": {
            "discoverability": 3, "comprehension": 2, "learnability": 2, "efficiency": 1,
            "error_prevention": 2, "recovery": 1, "accessibility": 2,
            "safety_and_trust": 1, "consistency": 1, "cross_module_continuity": 1,
        },
        "friction": {
            "completion_time": "Not completable", "step_count": "Two workflows inspected; neither supplied a completion path",
            "required_field_count": "Primary site appeared required in the deployed HR wizard but had zero choices",
            "decision_count": "Choose a site and role, but the site decision was impossible",
            "context_switches": "One forced switch from System Users back to HR People",
            "dead_ends": "Empty required site picker and no alternative Staff type",
            "recovery_path": "None in the supported UI; direct database or handcrafted-request bypass was deliberately refused.",
        },
        "target_scores": {"all_dimensions": 4, "safety_critical_error_prevention_and_trust": 5},
        "independent_review": "Repeat after deployment/source reconciliation with a resettable synthetic user and verify all role/site/direct-object boundaries.",
    },
    "evidence": {
        "anchors": [
            "evidence/browser/BVIS-0011-system-users-staff-path-blocked.png",
            "evidence/browser/BVIS-0011-system-users-staff-path-blocked.json",
            "evidence/source/browser-clinical-lead-account-creation-attempt.json",
            "resources/js/components/hr/add-employee-dialog.tsx:128-162,396-412",
            "resources/js/pages/system/users/Create.tsx:57-98,124-209,272-290",
        ],
        "existing_tests": [],
        "tests_executed": False,
        "browser_claim_limit": "Observed only for Demo Administrator in the connected deployed test application. No handcrafted request, database write, credential disclosure or successful staff creation is claimed.",
    },
    "problem_root_cause": "The deployed staff-creation contract is internally inconsistent and does not match the audited local source: HR People receives or renders no usable site options while requiring a site, and the deployed System Users page suppresses its source-present Staff branch and redirects back to that blocked flow. The exact deployment/build or payload cause is not established.",
    "impact": "Administrators cannot create a missing staff actor through a supported UI. This blocks onboarding and prevents complete representative Clinical/Medication Lead audit coverage; forcing a backend bypass would also skip the product's intended profile, role, site and audit controls.",
    "benchmark": {
        "selected": "Benchmark unproved",
        "url_and_sha": "",
        "verified_behavior": "",
        "outcome": "Unproved—no completion credit",
        "no_match_evidence": "The two owning targets retain their current benchmark outcomes; this finding is direct Oblivion workflow evidence, not a new benchmark or NCM conclusion.",
    },
    "neutral_requirements": "Provide one supported, source-consistent staff-creation journey that exposes current sites and roles, makes optionality identical in UI and validation, creates the user/profile atomically, records provenance, and provides explicit safe failure/retry without requiring an out-of-band bypass.",
    "better_oblivion_design": "Keep HR People as the canonical staff/profile workflow and make System Users link to it with preserved intent. Load site choices from the same authorised site source, make optionality and validation agree, show role/site review before submission, and confirm the resulting account/profile/audit record together.",
    "cross_module_effects": "HR profile, system identity, roles/permissions, site assignment, onboarding, invitations, audit logs and all representative-role browser validation depend on this convergence.",
    "rbac_privacy": "Retain settings.access.manage and staff.create gates, enforce allowed-site/direct-object constraints when sites are scoped, do not expose unrelated staff/site data, and never permit a direct-request path to bypass the canonical HR profile contract.",
    "proposed_owner": "HR People Owner, Identity and Access Owner, Deployment/Release Owner and Authorization Platform Owner",
    "confidence": "High for both deployed dead ends and the local-source contradiction; medium for the underlying deployment or payload cause",
    "source_boundary": "Original native repair only. No third-party code, wording, assets or layout is proposed or copied.",
    "interim_safeguard": "Do not create staff through direct database or handcrafted-request bypasses. Record the missing actor as unavailable until the supported workflow and deployed build are reconciled.",
    "acceptance_criteria": [
        "Given an authorised administrator and at least one visible active site, HR People shows the same authorised sites in Add employee.",
        "Primary-site optionality is identical in the rendered label, client completion rules and server validation.",
        "The Clinical Lead role can be selected and one synthetic user plus HR profile is created atomically with site and audit provenance.",
        "System Users either exposes the same supported Staff path or links to HR People without creating a loop or losing intent.",
        "A stale deployed bundle or payload mismatch is detected by build/provenance and workflow-contract checks before release.",
        "Denied or invalid requests create neither a partial user nor a partial HR profile and disclose no extra site data.",
    ],
    "missing_tests": [
        "HR People index returns authorised site options to the Add employee dialog",
        "Nullable primary-site client/server contract",
        "System Users Staff path or canonical redirect convergence",
        "Atomic user/profile/role/site creation and rollback",
        "Deployed asset/source fingerprint and UI-contract smoke test",
        "Clinical Lead representative-role creation in a resettable synthetic environment",
    ],
    "validation_plan": [
        "Feature-test both create entry points with administrator, site-scoped manager and denied actor",
        "Component-test empty, one-site and multi-site Add employee states",
        "Browser-complete the synthetic Clinical Lead task at all required viewports without database bypass",
        "Assert created user, profile, roles, site links and audit event, then remove the fixture through the supported workflow",
        "Compare deployed asset fingerprint and rendered controls to the audited commit before claiming runtime coverage",
    ],
    "statement_types": {
        "source": "The audited source queries sites, accepts nullable primary_site_id and contains a Staff branch on CreateUser.",
        "official_source": "No legal, clinical or standards-conformance conclusion is asserted.",
        "inference": "A stale deployed bundle or inconsistent payload is likely, but the exact deployment cause is not proven.",
        "specialist_decision": "HR, Identity, Authorization and Release owners must select and verify the one canonical staff-creation contract.",
    },
    "feature_link_reconciliation": {
        "method": "Exact current HR People lifecycle and System Users account-lifecycle route/page intersection.",
        "projection_status": "two exact current links; deployed browser-observed dead end; runtime completion blocked",
        "legacy_feature_ids": ["HR-EMPLOYEE-PROFILE", "HR-STAFF", "SET-USERS"],
        "decisions": [{
            "method": "exact routed page and cross-workflow ownership",
            "feature_ids": ["CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE", "CAP-SET-USER-ACCOUNT-LIFECYCLE"],
            "route_hits": [
                {"route_id": "ROUTE-1598", "route_name": "hr.people.index", "route_path": "hr/people", "page_id": "PAGE-0448"},
                {"route_id": "ROUTE-1599", "route_name": "hr.people.store", "route_path": "hr/people", "page_id": "PAGE-0448"},
                {"route_id": "ROUTE-2969", "route_name": "system.users.create", "route_path": "system/users/create", "page_id": "PAGE-0954"},
            ],
        }],
        "uncertainties": [
            {"reason": "The exact deployed build SHA and why its controls differ from local source remain unestablished."},
            {"reason": "No account was created, so atomic persistence and final role/site permissions remain unexecuted."},
        ],
    },
})

safe_evidence = base(
    finding_id="SAFE-EVID-01",
    feature_ids=[
        "CAP-INC-SAFEGUARDING-EVIDENCE-DOWNLOAD",
        "CAP-INC-SAFEGUARDING-EVIDENCE-MANAGEMENT",
    ],
    module="Safeguarding and incident safety",
    submodule="Safeguarding evidence retention and revocation",
    actor="A Site-authorised safeguarding worker manages retained evidence for a concern without being able to erase the original proof.",
    priority="P1",
    effort="M",
)
safe_evidence.update({
    "route_url": {
        "summary": "The authenticated safeguarding attachment routes expose store, download and destructive delete actions.",
        "route_names": [
            "safeguarding.attachments.store",
            "safeguarding.attachments.download",
            "safeguarding.attachments.destroy",
        ],
        "route_paths": [
            "safeguarding/{concern}/attachments",
            "safeguarding/{concern}/attachments/{attachment}/download",
            "safeguarding/{concern}/attachments/{attachment}",
        ],
    },
    "frontend_anchor": {
        "summary": "The current attachment panel offers an ordinary destructive removal action; no redesign is required to replace it with governed revocation.",
        "page_files": [],
        "audited_commit": COMMIT,
    },
    "backend_anchors": [
        "routes/safeguarding.php: safeguarding.attachments.destroy",
        "app/Http/Controllers/SafeguardingAttachmentController.php: destroy",
        "app/Policies/SafeguardingConcernPolicy.php: update",
        "tests/Feature/Safeguarding/SafeguardingAttachmentTest.php: attachment deletion expectation",
    ],
    "current_behavior": (
        "A Site-authorised actor who can update the concern can invoke the normal attachment DELETE endpoint. "
        "The controller deletes the private object and then soft-deletes its metadata; there is no immutable digest, "
        "append-only revocation decision, distinct approver or terminal-lifecycle retention gate."
    ),
    "current_workflow": {
        "summary": "Ordinary concern-update authority owns an irreversible evidence deletion.",
        "failure_sequence": (
            "Authorised worker opens a concern, invokes attachment delete, the private object is physically removed, "
            "and the row is soft-deleted without preserving a governed revocation decision or verifiable original."
        ),
        "boundary": "Parent concern Site/sensitivity authorization exists, but evidence retention and revocation authority are not separate.",
        "completion_evidence": "Static controller, policy and existing-test trace only; no destructive runtime action was executed.",
    },
    "evidence": {
        "anchors": [
            "app/Http/Controllers/SafeguardingAttachmentController.php: destroy deletes storage before metadata",
            "app/Policies/SafeguardingConcernPolicy.php: ordinary update/assignment authority",
            "tests/Feature/Safeguarding/SafeguardingAttachmentTest.php: existing test accepts removed object and soft-deleted row",
        ],
        "existing_tests": ["tests/Feature/Safeguarding/SafeguardingAttachmentTest.php"],
        "tests_executed": False,
        "browser_claim_limit": "No browser flow or destructive request was executed; the finding is source-observed on current main.",
    },
    "problem_root_cause": (
        "Evidence removal is modelled as ordinary CRUD under concern-update authority instead of an immutable evidence "
        "aggregate with append-only, independently approved revocation."
    ),
    "impact": (
        "Material safeguarding evidence can be permanently erased by an ordinary workflow actor, leaving neither a "
        "verifiable original nor a durable, independently approved reason for withholding it."
    ),
    "neutral_requirements": (
        "Preserve the original private object and immutable capture provenance; use a reasoned append-only revocation or "
        "quarantine decision with distinct requester and approver; fail closed when integrity no longer matches."
    ),
    "better_oblivion_design": (
        "Keep the established attachment panel and replace delete with a governed revoke/approve flow backed by an "
        "immutable evidence owner, digest verification, retention-safe foreign keys and database mutation guards."
    ),
    "cross_module_effects": (
        "Safeguarding disclosure, legal retention, incident review and H&S assurance must continue to reference the same "
        "retained evidence identity even when normal download visibility is revoked."
    ),
    "dependencies_sequence": (
        "Define dedicated revoke/approve permissions and retention semantics; add immutable provenance/history schema and "
        "conservative backfill; update the existing panel action; then run Site/direct-object, mutation, concurrency and integrity tests."
    ),
    "proposed_owner": "Safeguarding Product Owner, Privacy/Records Owner and Backend Assurance",
    "interim_safeguard": "Restrict attachment deletion authority operationally and preserve storage backups until the governed revocation path is verified.",
    "acceptance_criteria": [
        "Ordinary safeguarding.update or assignment authority cannot erase an attachment object or row, including after concern closure.",
        "Wrong-Site and mismatched nested identifiers return the same generic 404 with no file, row or history effect.",
        "Revocation requires a reason and a distinct authorised approver; duplicate or concurrent decisions create one immutable outcome.",
        "Direct Eloquent update, soft delete, hard delete and raw MySQL provenance mutation are rejected.",
        "Valid evidence downloads only when its digest matches; revoked evidence is withheld normally while retained for authorised disclosure.",
    ],
    "missing_tests": [
        "Two-Site nested direct-object denial with no side effects",
        "Distinct requester/approver and self-approval denial",
        "Concurrent duplicate revocation decision",
        "ORM and raw-MySQL immutable provenance guards",
        "Digest-valid download, mismatch failure and revoked authorised-disclosure path",
        "Conservative present-object and missing-legacy-object backfill",
    ],
    "validation_plan": [
        "Run one focused real-MySQL safeguarding evidence tree in an isolated disposable schema.",
        "Prove wrong-Site/nonexistent equivalence, mutation rollback, replay/concurrency and cleanup.",
        "Run scoped frontend lint/types/client/SSR gates if the existing attachment panel changes.",
    ],
    "feature_link_reconciliation": {
        "method": "Exact current safeguarding evidence download/management targets and attachment route/controller ownership.",
        "projection_status": "two exact current working-manifest links; runtime validation blocked",
        "legacy_feature_ids": ["INC-SAFEGUARDING-ATTACHMENT"],
        "decisions": [{
            "method": "exact safeguarding attachment route and controller ownership",
            "feature_ids": [
                "CAP-INC-SAFEGUARDING-EVIDENCE-DOWNLOAD",
                "CAP-INC-SAFEGUARDING-EVIDENCE-MANAGEMENT",
            ],
        }],
        "uncertainties": [],
    },
    "remediation": {
        "status": "in_progress",
        "task_id": "019ffe6d-a904-7990-bfd8-43184f5e5555",
        "started_at": "2026-08-14T16:09:00+12:00",
        "note": (
            "A SOL 5.6 high isolated task is implementing immutable evidence provenance and governed revocation while "
            "preserving the existing attachment UI/UX. Heavy PHP/Pest verification remains centrally serialized."
        ),
    },
})

payment_allocation = base(
    finding_id="FIN-PAYMENT-ALLOCATION-01",
    feature_ids=["FIN-BILL", "FIN-PAYMENT-ALLOCATION"],
    module="Finance",
    submodule="Payment allocation and payable settlement integrity",
    actor="A Finance worker records a receipt or payable settlement through the canonical evidence-backed workflow.",
    priority="P1",
    effort="S",
)
payment_allocation.update({
    "route_url": {
        "summary": "The authenticated generic payment-allocation POST is separately reachable from canonical receivable and payable settlement workflows.",
        "route_names": ["finance.payment-allocations.store", "finance.receivables.allocate"],
        "route_paths": ["finance/payment-allocations", "finance/receivables/allocate"],
    },
    "frontend_anchor": {
        "summary": "The payment-allocation page is history/filter-only and does not require the unsafe generic write endpoint.",
        "page_files": ["resources/js/pages/finance/payment-allocations/Index.tsx"],
        "audited_commit": COMMIT,
    },
    "backend_anchors": [
        "routes/finance.php: finance.payment-allocations store route",
        "app/Domain/Finance/Http/Controllers/PaymentAllocationController.php: store",
        "app/Domain/Finance/Services/AccountsPayableService.php: recordPayment",
        "app/Domain/Finance/Services/AccountsReceivableService.php: canonical receipt allocation",
        "app/Domain/Finance/Services/PaymentMatchingService.php: locked match settlement",
        "database/migrations/2026_03_28_002100_create_fin_payment_allocations_table.php",
    ],
    "current_behavior": (
        "The generic allocation POST is granted to finance.ar.manage but accepts an independently supplied allocation type, "
        "target type, target ID and amount. It can create a journal-less allocation and increment an unscoped AP bill's paid "
        "total. The payable service does not reload and lock the bill, require a payable state, or cap the amount to balance due."
    ),
    "current_workflow": {
        "summary": "A duplicate generic write bypasses the canonical receipt, matching and payment-run aggregates.",
        "failure_sequence": (
            "An AR-authorised caller posts a forged AP target and amount; the controller creates an allocation without a journal "
            "or payment source, then increments the bill and can overpay or falsely mark it paid."
        ),
        "boundary": "AR permission, canonical Site ownership, AP settlement authority, journal provenance and bill locking are not joined in the generic path.",
        "completion_evidence": "Static route/controller/service/schema/UI trace only; no payment or database mutation was executed.",
    },
    "evidence": {
        "anchors": [
            "routes/finance.php: POST /finance/payment-allocations under finance.ar.manage",
            "PaymentAllocationController::store accepts forgeable type/target/id/amount and creates a nullable-journal allocation",
            "AccountsPayableService::recordPayment lacks locked due/state guards",
            "payment-allocations/Index.tsx is read-only history/filter composition",
            "AccountsReceivableService and PaymentMatchingService already own locked, journal-linked canonical settlement",
        ],
        "existing_tests": [],
        "tests_executed": False,
        "browser_claim_limit": "No browser or payment mutation was executed; route reachability and UI non-use are source-observed.",
    },
    "problem_root_cause": (
        "A legacy generic payment-allocation writer competes with canonical AR receipt, matching and payment-run aggregates and "
        "permits nullable provenance rather than deriving settlement from the locked financial owner."
    ),
    "impact": (
        "A permitted caller can falsely settle or overpay AP/AR records without balanced journal evidence, causing cash, payable, "
        "aged-report and audit records to disagree."
    ),
    "neutral_requirements": (
        "Every allocation must derive from one authorised canonical payment owner, lock and revalidate the target and balance, "
        "remain within the amount due, and persist exactly one traceable journal/source effect."
    ),
    "better_oblivion_design": (
        "Retire the unused generic POST while retaining the existing history UI. Keep manual AR receipts in the canonical "
        "receivables flow and AP settlement in payment matching/runs, with one locked payable guard."
    ),
    "dependencies_sequence": (
        "Remove the generic writer; harden AccountsPayableService locking/state/due validation; inventory journal-less historical "
        "allocations for review without rewriting them; then run Site, journal, replay and concurrency tests."
    ),
    "proposed_owner": "Finance Product Owner, Finance Backend Owner and Financial Control",
    "interim_safeguard": "Restrict the generic payment-allocation POST and review allocations lacking both journal and source provenance.",
    "acceptance_criteria": [
        "A forged AR-manager request to the retired endpoint creates no allocation, bill change or journal.",
        "A valid partial AR receipt creates exactly one allocation linked to one balanced receipt journal.",
        "An excess second payment fails atomically with no allocation, journal or paid-total change.",
        "Concurrent or replayed match/payment-run attempts against one bill produce one settlement effect.",
        "Wrong-Site direct IDs are generically denied; a separately explicit all-Sites authority succeeds through canonical policy.",
    ],
    "missing_tests": [
        "Retired generic POST no-effect contract",
        "Canonical AR receipt journal linkage",
        "Locked AP amount-due and payable-state guard",
        "Concurrent/replayed settlement exactly-once proof",
        "Two-Site direct-object denial and explicit global positive",
        "Legacy journal-less allocation inventory without silent rewrite",
    ],
    "validation_plan": [
        "Run one focused real-MySQL payment-allocation/payable-settlement tree in a disposable schema.",
        "Prove allocation, bill and journal rollback on denial/failure and exact cleanup.",
        "Run proportional receipt, matching, payment-run and Finance reporting regressions.",
    ],
    "feature_link_reconciliation": {
        "method": "Exact current bill and payment-allocation targets with route/controller/service ownership.",
        "projection_status": "two exact current working-manifest links; runtime validation blocked",
        "legacy_feature_ids": ["FIN-PAYMENT-ALLOCATION", "FIN-BILL"],
        "decisions": [{
            "method": "exact Finance payment-allocation and payable-settlement source ownership",
            "feature_ids": ["FIN-BILL", "FIN-PAYMENT-ALLOCATION"],
        }],
        "uncertainties": [],
    },
    "remediation": {
        "status": "in_progress",
        "task_id": "019ffe75-e877-7101-a1f9-02d27192bcfa",
        "started_at": "2026-08-14T16:22:00+12:00",
        "note": (
            "A SOL 5.6 high isolated remediation task is retiring the unsafe generic writer and hardening the canonical "
            "payable settlement aggregate while preserving the payment-allocation history UI. Heavy PHP/Pest remains serialized."
        ),
    },
})


eftpos_settlement = base(
    finding_id="FIN-EFTPOS-SETTLEMENT-01",
    feature_ids=["CAP-FIN-EFTPOS-BATCHES", "CAP-FIN-EFTPOS-TERMINALS"],
    module="Finance",
    submodule="EFTPOS import, bank-transaction claim and settlement journal integrity",
    actor="A Finance worker imports and reconciles one terminal settlement against its canonical bank transaction.",
    priority="P1",
    effort="L",
)
eftpos_settlement.update({
    "route_url": {
        "summary": "The existing terminal and batch routes expose import, detail and reconciliation under Finance bank-management authority.",
        "route_names": [
            "finance.eftpos.batches",
            "finance.eftpos.batches.import",
            "finance.eftpos.batches.reconcile",
            "finance.eftpos.batches.show",
            "finance.eftpos.terminals",
        ],
        "route_paths": [
            "finance/eftpos/batches",
            "finance/eftpos/batches/import",
            "finance/eftpos/batches/{batch}/reconcile",
            "finance/eftpos/batches/{batch}",
            "finance/eftpos/terminals",
        ],
    },
    "frontend_anchor": {
        "summary": "The established EFTPOS terminal/batch workflow is retained; the defect is in import identity, claim ownership and settlement persistence.",
        "page_files": ["resources/js/pages/finance/eftpos/BatchDetail.tsx", "resources/js/pages/finance/eftpos/Batches.tsx"],
        "audited_commit": COMMIT,
    },
    "backend_anchors": [
        "app/Domain/Finance/Services/EftposReconciliationService.php:26-52,90-151,232-253",
        "app/Domain/Finance/Http/Controllers/EftposController.php:194-220",
        "app/Domain/Finance/Models/FinEftposTerminal.php:19-43",
        "database/migrations/2026_03_28_005500_create_fin_eftpos_batches_table.php:13-34",
        "database/migrations/2026_03_28_001000_create_fin_journals_table.php:22-41",
        "routes/finance.php:709-744",
    ],
    "current_behavior": (
        "Every import creates a new batch without a provider batch identity or payload fingerprint. Reconciliation locks only the "
        "selected batch; the supplied bank transaction is neither locked nor reserved, and the journal replay lookup is scoped "
        "only to that batch. A second imported batch can therefore claim the same economic deposit and post another journal."
    ),
    "current_workflow": {
        "summary": "The same stored batch is replay-safe, but repeated provider input and cross-batch bank claims are not one aggregate.",
        "failure_sequence": (
            "A provider resend or operator retry creates B1 and B2 for one settlement; B1 posts J1 against bank transaction BT; "
            "B2 then claims the same unlocked BT and posts J2 because source uniqueness is only per batch."
        ),
        "boundary": "Provider import identity, terminal/bank-account ownership, bank-transaction reservation, Site access and immutable correction lineage.",
        "completion_evidence": "Two independent current-source reviews; no import, bank claim, journal posting or browser mutation was executed.",
    },
    "evidence": {
        "anchors": [
            "EftposReconciliationService::importBatch always creates a row without stable provider identity",
            "EftposReconciliationService::reconcile locks one batch but globally loads the bank transaction without reservation",
            "fin_eftpos_batches.bank_transaction_id is nullable and non-unique",
            "Existing settlement test proves only same-batch retry idempotence",
        ],
        "existing_tests": ["tests/Feature/Finance/EftposSettlementJournalPostingTest.php"],
        "tests_executed": False,
        "browser_claim_limit": "No browser, provider import, bank transaction or journal mutation was executed; reachability and failure are source-observed/inferred.",
    },
    "problem_root_cause": (
        "EFTPOS import identity, bank-transaction claim and journal posting are three loosely related writes instead of one "
        "locked, database-enforced settlement aggregate."
    ),
    "impact": "One real EFTPOS deposit can be represented by multiple batches and balanced journals, overstating bank and understating clearing.",
    "neutral_requirements": (
        "Identify one provider settlement immutably, reserve one compatible bank transaction atomically, post one journal effect, "
        "and express corrections as linked reversal/replacement evidence."
    ),
    "better_oblivion_design": (
        "Keep the existing EFTPOS screens and service, but add provider fingerprint uniqueness, stable lock order, one active bank-transaction "
        "claim, terminal-to-Site ownership and append-only correction lineage through the existing reversal service."
    ),
    "cross_module_effects": "Coordinate with bank reconciliation, payment matching and journal reversal so one bank transaction cannot be claimed by competing aggregates.",
    "dependencies_sequence": "Define provider identity and terminal Site ownership; inventory duplicate legacy claims; add conservative DB invariants; then harden import/reconcile and run concurrency tests.",
    "proposed_owner": "Finance Banking Product Owner, Finance Backend Owner and Financial Control",
    "interim_safeguard": "Operationally deduplicate provider files and independently review every EFTPOS batch-to-bank claim before reconciliation.",
    "acceptance_criteria": [
        "The same provider settlement imported twice produces one economic batch and one eventual journal.",
        "Two batches cannot reserve or post against one bank transaction, including concurrent manual/automatic attempts.",
        "Another bank account or an existing reconciliation/payment-match claim is rejected atomically.",
        "Wrong-Site terminal and batch IDs are generically concealed; an explicitly named all-Sites Finance authority is separately positive.",
        "Correction retains the original claim and creates a linked reversal/replacement without overwriting posted provenance.",
    ],
    "missing_tests": [
        "Duplicate provider import identity",
        "Two-batch one-bank-transaction concurrency",
        "Other-account and competing-aggregate claim denial",
        "Same-batch replay and rollback",
        "Correction reversal/replacement lineage",
        "Two-Site direct-object denial and explicit global positive",
    ],
    "validation_plan": [
        "Run one focused real-MySQL EFTPOS settlement tree in a disposable schema.",
        "Prove duplicate/import/claim/replay/concurrency/rollback invariants and exact schema/process cleanup.",
        "Run proportional bank-reconciliation, payment-matching and journal-reversal regressions plus unchanged frontend gates.",
    ],
    "feature_link_reconciliation": {
        "method": "Exact current EFTPOS batch and terminal route/controller/service ownership.",
        "projection_status": "two exact current working-manifest links; runtime validation blocked",
        "legacy_feature_ids": ["CAP-FIN-EFTPOS-BATCHES", "CAP-FIN-EFTPOS-TERMINALS"],
        "decisions": [{
            "method": "exact EFTPOS terminal/batch route and service ownership",
            "feature_ids": ["CAP-FIN-EFTPOS-BATCHES", "CAP-FIN-EFTPOS-TERMINALS"],
        }],
        "uncertainties": [],
    },
    "remediation": {
        "status": "in_progress",
        "task_id": "client-new-thread:49464f40-59e4-4906-b745-a4248323f1bd",
        "started_at": "2026-08-14T17:02:36+12:00",
        "note": "A SOL 5.6 high isolated remediation task is hardening the existing EFTPOS settlement aggregate; heavy PHP/Pest remains centrally serialized.",
    },
})


med_order_erasure = base(
    finding_id="MED-ORDER-ERASURE-01",
    feature_ids=["CAP-MED-MEDICATION-ORDER-LIFECYCLE"],
    module="Medication",
    submodule="Medication-order discontinuation and historical evidence retention",
    actor="A Site-authorised worker discontinues a medication order while preserving its administration and clinical history.",
    priority="P1",
    effort="M",
)
med_order_erasure.update({
    "route_url": {
        "summary": "Two profile DELETE routes bypass the established eMAR discontinuation action.",
        "route_names": [
            "clients.medical.medications.destroy",
            "operations.clients.medical.medications.destroy",
            "emar.medications.discontinue",
        ],
        "route_paths": [
            "clients/{client}/medical/medications/{medication}",
            "operations/clients/{client}/medical/medications/{medication}",
            "emar/medications/{medication}/discontinue",
        ],
    },
    "frontend_anchor": {
        "summary": "Both profile variants expose one-click Remove while the existing eMAR dialog already requires a discontinuation reason.",
        "page_files": [
            "resources/js/pages/clients/medical.tsx",
            "resources/js/pages/operations/clients/medical.tsx",
            "resources/js/pages/emar/_dialogs.tsx",
        ],
        "audited_commit": COMMIT,
    },
    "backend_anchors": [
        "app/Http/Controllers/ClientMedicalController.php:773-818",
        "app/Http/Controllers/Emar/EmarController.php:4781-4814",
        "app/Models/ClientMedication.php:176-195,244-265,469-490",
        "app/Models/ClientMedicationAdministration.php:83-86",
        "app/Policies/ClientMedicationPolicy.php:30-34",
        "app/Services/Medication/MedicationScopeDecisionService.php:171-212,365-401",
    ],
    "current_behavior": (
        "A correctly Site-scoped clients.update actor can delete a medication from either profile. The controller locks and scopes "
        "the record, then soft-deletes it without a reason, ceased state, actor/time or version evidence, bypassing the eMAR "
        "discontinuation lifecycle. Historical administrations remain physically present but normal parent relations hide the soft-deleted order."
    ),
    "current_workflow": {
        "summary": "Authorization scope is present, but the authorised state transition erases the order from normal and historical projections.",
        "failure_sequence": (
            "A worker clicks Remove on an active, verified or administered order; deleted_at is set without cessation provenance; "
            "the order vanishes from current/audit queries and retained administrations render Unknown/N/A or disappear from relation filters."
        ),
        "boundary": "Medication-order lifecycle authority, required reason, immutable cessation evidence and historical parent visibility.",
        "completion_evidence": "Independent current-source review; no medication deletion, administration, browser action or database mutation was executed.",
    },
    "evidence": {
        "anchors": [
            "ClientMedicalController::destroyMedication calls delete after canonical scope resolution",
            "ClientMedication uses SoftDeletes and normal current/active queries exclude deleted orders",
            "eMAR discontinuation already requires reason and records ceased state",
            "ClientMedicationAdministration::medication lacks withTrashed historical resolution",
            "Existing MedicationControllerTest expects successful soft deletion",
        ],
        "existing_tests": ["tests/Feature/MedicationControllerTest.php"],
        "tests_executed": False,
        "browser_claim_limit": "No profile/eMAR browser action or medication mutation was executed; UI and lifecycle differences are source-observed.",
    },
    "problem_root_cause": "A legacy generic delete action competes with the canonical medication-order discontinuation lifecycle and ordinary relations conceal retained clinical history.",
    "impact": "An authorised profile editor can remove an order from active and historical context without cessation reason/provenance, obscuring prior medication administrations and controlled evidence.",
    "neutral_requirements": "Discontinue, never erase, an evidence-bearing medication order; require reason/actor/time and immutable version evidence while preserving historic parent context.",
    "better_oblivion_design": "Reuse one transactional eMAR discontinuation service from both profile surfaces, retain the order, and narrowly resolve legacy soft-deleted parents only in historical evidence projections.",
    "cross_module_effects": "Administration history, MAR exports, controlled-drug/stock records, audits and client profiles must reference the same retained order identity.",
    "dependencies_sequence": "Centralize the existing discontinuation write; remove DELETE reachability; repair narrow historic relations; then run Site/capability, rollback and concurrency tests.",
    "proposed_owner": "Medication Product Owner, Clinical Safety Owner and Medication Backend Owner",
    "interim_safeguard": "Operationally prohibit profile Remove and use the existing eMAR Discontinue action with a documented reason.",
    "acceptance_criteria": [
        "Both profile surfaces require the canonical discontinuation action and cannot soft-delete an order.",
        "Discontinuation records one immutable reason, actor, time and version while deleted_at remains null.",
        "Prior administrations and other evidence retain medication name/dose context, including explicitly labelled legacy removals.",
        "Wrong client/Site/assignment identifiers are concealed; explicit global and valid break-glass positives are separately evidenced.",
        "Concurrent/replayed discontinuation and administration races yield one terminal provenance and no post-cease dose.",
    ],
    "missing_tests": [
        "Profile DELETE absent/denied and shared discontinuation positive",
        "Permission, Site, assignment, global and break-glass matrix",
        "Historical administration/export relation with legacy soft-deleted parent",
        "Concurrent discontinue replay and administration race",
        "Strict audit/version failure rollback",
        "Profile browser reason-required and ceased-history state",
    ],
    "validation_plan": [
        "Run one focused real-MySQL medication-order lifecycle tree in a disposable schema.",
        "Prove reason/version/audit atomicity, history retention, scope, replay and two-process race behavior.",
        "Run proportional eMAR/profile frontend tests, types, client/SSR and read-only browser verification if UI wiring changes.",
    ],
    "feature_link_reconciliation": {
        "method": "Exact current medication-order lifecycle target with profile DELETE and eMAR discontinuation route ownership.",
        "projection_status": "one exact current working-manifest link; runtime validation blocked",
        "legacy_feature_ids": ["CAP-MED-MEDICATION-ORDER-LIFECYCLE"],
        "decisions": [{
            "method": "exact medication order controller/model lifecycle ownership",
            "feature_ids": ["CAP-MED-MEDICATION-ORDER-LIFECYCLE"],
        }],
        "uncertainties": [],
    },
    "remediation": {
        "status": "in_progress",
        "task_id": "client-new-thread:a11736d9-3580-47cb-84d1-feb345c00836",
        "started_at": "2026-08-14T17:02:36+12:00",
        "note": "A SOL 5.6 high isolated remediation task is replacing medication deletion with the canonical discontinuation/evidence lifecycle; heavy PHP/Pest remains serialized.",
    },
})


dsr_lifecycle = base(
    finding_id="PRIV-DSR-LIFECYCLE-01",
    feature_ids=[
        "CAP-PRIV-DSR-FULFILLMENT-DECISION",
        "CAP-PRIV-DSR-IDENTITY-DEADLINE",
        "CAP-PRIV-DSR-INTAKE-CASE-MANAGEMENT",
    ],
    module="Privacy",
    submodule="Data-subject-request lifecycle integrity and terminal provenance",
    actor="A Privacy processor verifies, assigns, completes, refuses or withdraws a data-subject request through one authoritative lifecycle.",
    priority="P1",
    effort="M",
)
dsr_lifecycle.update({
    "route_url": {
        "summary": "The generic update route and dedicated verify/complete/refuse commands all mutate one DSR without a shared transition owner.",
        "route_names": [
            "privacy.requests.update",
            "privacy.requests.verify",
            "privacy.requests.complete",
            "privacy.requests.refuse",
        ],
        "route_paths": [
            "privacy/requests/{dsRequest}",
            "privacy/requests/{dsRequest}/verify",
            "privacy/requests/{dsRequest}/complete",
            "privacy/requests/{dsRequest}/refuse",
        ],
    },
    "frontend_anchor": {
        "summary": "The current command UI posts dedicated actions, but presentation guidance does not constrain direct server requests or races.",
        "page_files": ["resources/js/pages/privacy/requests/Show.tsx", "resources/js/pages/privacy/requests/Index.tsx"],
        "audited_commit": COMMIT,
    },
    "backend_anchors": [
        "app/Http/Controllers/Privacy/DataSubjectRequestController.php:140-179,209-245",
        "app/Policies/DataSubjectRequestPolicy.php:25-28",
        "routes/privacy.php:33-47",
        "database/migrations/2026_03_28_005900_create_privacy_tables.php:80-125",
        "app/Services/AuditLogger.php:14-24",
        "tests/Feature/PrivacyControllerTest.php:2137-2150",
    ],
    "current_behavior": (
        "The generic PUT accepts any allowed enum status, including completed, rejected and withdrawn, and persists it directly. "
        "Dedicated verify, complete and refuse actions also write without a shared state guard, transaction or row lock. Terminal "
        "records can therefore reopen or race into status/provenance combinations that no command validly produced."
    ),
    "current_workflow": {
        "summary": "Permissions exist, but assignment/edit and lifecycle commands are not separated by one locked transition aggregate.",
        "failure_sequence": (
            "A processor PUTs completed before identity verification, or reopens a completed request to in_progress; concurrent "
            "complete/refuse/verify requests then last-write a status while retaining incompatible completion or refusal evidence."
        ),
        "boundary": "Processor authority, verified prerequisites, terminal immutability, action-specific actor/time/reason evidence and concurrency.",
        "completion_evidence": "Independent current/frozen source review; no DSR mutation, database race or browser action was executed.",
    },
    "evidence": {
        "anchors": [
            "DataSubjectRequestController::update validates every enum status and directly updates the model",
            "verify/complete/refuse have no state prerequisite, transaction or lockForUpdate",
            "schema allows terminal status without matching terminal actor/time/reason provenance",
            "existing PrivacyControllerTest explicitly accepts generic valid-status updates",
            "AuditLogger::log swallows audit-write failure and is not lifecycle atomicity",
        ],
        "existing_tests": [
            "tests/Feature/PrivacyControllerTest.php",
            "tests/e2e/privacy-dsr-and-breach-lifecycle.spec.ts",
        ],
        "tests_executed": False,
        "browser_claim_limit": "No DSR browser action or lifecycle mutation was executed; UI guidance and server gaps are source-observed.",
    },
    "problem_root_cause": "Generic edit, assignment and terminal lifecycle mutations compete without one transactional transition matrix and strict audit owner.",
    "impact": "A privacy case can be declared complete/refused without required evidence, reopened after terminal completion, or retain contradictory actor/time/reason provenance after a race.",
    "neutral_requirements": "Separate editable assignment fields from lifecycle commands; lock and revalidate each transition; keep terminal states immutable and provenance consistent with the winning action.",
    "better_oblivion_design": "Keep the current routes/UI but delegate all lifecycle commands to one small DSR service with explicit transitions, row locks and fail-closed action audit evidence.",
    "cross_module_effects": "DSR export packaging, deadlines, notifications, audit reporting and retention must project the same authoritative lifecycle state.",
    "dependencies_sequence": "Confirm withdrawal/refusal provenance policy; add conservative fields/constraints; centralize commands; reject generic status writes; then run MySQL race/replay/rollback tests.",
    "proposed_owner": "Privacy Product Owner, Privacy Officer and Backend Assurance",
    "interim_safeguard": "Restrict processors to dedicated commands and independently review terminal DSR rows for missing or contradictory provenance.",
    "acceptance_criteria": [
        "Generic PUT cannot write lifecycle status or completion fields and retains valid assignment behavior.",
        "Only explicit valid transitions succeed; unverified terminal actions and terminal reopening are denied without mutation.",
        "Concurrent conflicting commands yield one terminal winner whose state, actor/time/reason and audit evidence agree.",
        "Replay is idempotent or conflicts without overwriting original terminal provenance.",
        "Strict audit failure rolls back the lifecycle mutation and creates no apparent successful command.",
    ],
    "missing_tests": [
        "Direct PUT lifecycle-field rejection",
        "Verified valid sequence and unverified terminal denial",
        "Terminal replay/reopen/cross-terminal conflict",
        "Two-process complete-vs-refuse/verify race",
        "Strict audit failure rollback",
        "Assignment retention and explicit authority/direct-object matrix",
    ],
    "validation_plan": [
        "Run one focused real-MySQL DSR lifecycle tree in a disposable schema.",
        "Prove transition, terminal, provenance, replay, race and audit-failure invariants with exact cleanup.",
        "Run proportional DSR export/deadline/notification regressions and unchanged frontend gates.",
    ],
    "feature_link_reconciliation": {
        "method": "Exact current DSR intake, identity/deadline and fulfilment-decision target ownership.",
        "projection_status": "three exact current working-manifest links; runtime validation blocked",
        "legacy_feature_ids": [
            "CAP-PRIV-DSR-FULFILLMENT-DECISION",
            "CAP-PRIV-DSR-IDENTITY-DEADLINE",
            "CAP-PRIV-DSR-INTAKE-CASE-MANAGEMENT",
        ],
        "decisions": [{
            "method": "exact DSR route/controller lifecycle ownership",
            "feature_ids": [
                "CAP-PRIV-DSR-FULFILLMENT-DECISION",
                "CAP-PRIV-DSR-IDENTITY-DEADLINE",
                "CAP-PRIV-DSR-INTAKE-CASE-MANAGEMENT",
            ],
        }],
        "uncertainties": [],
    },
    "remediation": {
        "status": "in_progress",
        "task_id": "client-new-thread:cb8d6722-c867-4b03-85e5-8c364775846b",
        "started_at": "2026-08-14T17:10:00+12:00",
        "note": "A SOL 5.6 high isolated remediation task is centralizing the DSR transition aggregate while preserving the existing command UI; heavy PHP/Pest remains serialized.",
    },
})


gov_resolution_quorum = base(
    finding_id="GOV-RESOLUTION-QUORUM-01",
    feature_ids=[
        "CAP-GOV-RESOLUTION-FINALIZATION",
        "CAP-GOV-RESOLUTION-VOTING-CONFLICTS",
    ],
    module="Governance",
    submodule="Resolution quorum and vote-finalization integrity",
    actor="A meeting chair or secretary opens, conducts and closes a meeting-bound resolution vote under the recorded attendance and conflict rules.",
    priority="P1",
    effort="M",
)
gov_resolution_quorum.update({
    "route_url": {
        "summary": "The dedicated open, vote, conflict, close and finalize routes all reach the resolution workflow, but closing does not enforce meeting quorum or serialize with vote writes.",
        "route_names": [
            "governance.resolutions.open",
            "governance.resolutions.vote",
            "governance.resolutions.conflict.declare",
            "governance.resolutions.close",
            "governance.resolutions.finalize",
        ],
        "route_paths": [
            "governance/resolutions/{resolution}/open",
            "governance/resolutions/{resolution}/vote",
            "governance/resolutions/{resolution}/conflict",
            "governance/resolutions/{resolution}/close",
            "governance/resolutions/{resolution}/finalize",
        ],
    },
    "frontend_anchor": {
        "summary": "The existing resolution page exposes the close action without showing or enforcing an authoritative quorum snapshot.",
        "page_files": ["resources/js/pages/Governance/Resolutions/Show.tsx"],
        "audited_commit": COMMIT,
    },
    "backend_anchors": [
        "app/Domain/Governance/Services/VotingService.php:54,106-170,224",
        "app/Domain/Governance/Http/Controllers/ResolutionController.php:75,139,207-215",
        "app/Domain/Governance/Models/Resolution.php:131-186",
        "app/Domain/Governance/Models/GovernanceMeeting.php:186",
        "app/Domain/Governance/Policies/ResolutionPolicy.php:74",
        "resources/js/pages/Governance/Resolutions/Show.tsx:476",
    ],
    "current_behavior": (
        "Closing voting checks only that the resolution is open, then derives the outcome from votes without locking the "
        "parent meeting, attendance denominator or resolution against concurrent voting. The available meeting-quorum helper "
        "is presentation-oriented and is not a close precondition, so a meeting-bound resolution can close below quorum or "
        "race a final vote into a result that has no immutable attendance/vote snapshot."
    ),
    "current_workflow": {
        "summary": "Role/policy checks exist, but the terminal close command does not own quorum, attendance and vote finalization as one locked aggregate.",
        "failure_sequence": (
            "A chair closes a meeting-bound resolution while attendance is below quorum, or a vote arrives concurrently with "
            "close; the persisted result reflects whichever reads/writes win rather than one recorded close snapshot."
        ),
        "boundary": "Meeting-bound quorum, eligible denominator, conflicts, vote set, terminal result, replay and concurrent close/vote.",
        "completion_evidence": "Independent current/frozen source adjudication; no governance mutation, MySQL race or browser action was executed.",
    },
    "evidence": {
        "anchors": [
            "VotingService close path checks open state but not meeting quorum",
            "the close path does not lock the resolution, meeting attendance or vote set",
            "GovernanceMeeting quorum calculation is not a terminal workflow guard",
            "the resolution page presents close as an unconditional available command",
            "action-item generation is schema-incompatible and must not be credited as a reliable committed side effect",
        ],
        "existing_tests": [
            "tests/Feature/Governance/ResolutionVotingTest.php",
            "tests/Feature/Governance/ResolutionControllerTest.php",
        ],
        "tests_executed": False,
        "browser_claim_limit": "No vote/close browser command or runtime quorum path was executed; the finding is source-observed.",
    },
    "problem_root_cause": "Resolution finalization is not a single locked domain command that binds meeting quorum, eligible attendance, conflicts, votes and the terminal outcome.",
    "impact": "A governance resolution can be finalized without quorum or with a race-dependent vote tally, weakening decision validity and the evidential value of the recorded outcome.",
    "neutral_requirements": "For meeting-bound resolutions, require and snapshot quorum before close; lock and revalidate the aggregate; retain eligible denominator, attendance, conflicts, votes and outcome; make replay idempotent and reject late votes.",
    "better_oblivion_design": "Keep the current routes and page, but make VotingService the canonical transactional close owner with an immutable close snapshot and an explicit documented rule for standalone resolutions.",
    "cross_module_effects": "Meeting minutes, resolution evidence, action items, compliance reporting and audit exports must project the same authoritative finalization snapshot.",
    "dependencies_sequence": "Decide standalone-resolution quorum semantics; define snapshot fields/constraints; centralize locked close/vote transitions; repair action-item compatibility separately; then run MySQL race and browser checks.",
    "proposed_owner": "Governance Product Owner, Board Secretary and Backend Assurance",
    "interim_safeguard": "Require manual board-secretary confirmation of attendance/quorum and independently review recently closed meeting-bound resolutions before relying on them.",
    "acceptance_criteria": [
        "A meeting-bound resolution cannot close below the configured quorum and denial creates no terminal side effect.",
        "Close stores one immutable eligible-denominator, attendance, conflict, vote and outcome snapshot.",
        "Concurrent vote-versus-close produces one serialized outcome; late votes and duplicate terminal commands cannot rewrite it.",
        "Standalone resolutions follow one explicit, tested quorum/finalization policy rather than inheriting meeting assumptions accidentally.",
        "Action-item creation is schema-compatible and atomic with the intended close contract, or is explicitly decoupled with durable recovery evidence.",
    ],
    "missing_tests": [
        "Meeting-bound below-quorum close denial and exact-threshold positive",
        "Conflict-adjusted eligible denominator and immutable close snapshot",
        "Two-process final-vote-versus-close race",
        "Duplicate close and late-vote replay",
        "Standalone-resolution policy",
        "Action-item rollback/recovery and read-only browser quorum state",
    ],
    "validation_plan": [
        "Run one focused real-MySQL resolution-voting tree in a disposable schema.",
        "Prove quorum, snapshot, replay, race and action-item atomicity with exact cleanup.",
        "Run proportional governance page tests, types/builds and read-only browser verification if UI state changes.",
    ],
    "feature_link_reconciliation": {
        "method": "Exact current resolution voting/conflict and finalization route/controller ownership.",
        "projection_status": "two exact current working-manifest links; runtime validation blocked",
        "legacy_feature_ids": [
            "CAP-GOV-RESOLUTION-FINALIZATION",
            "CAP-GOV-RESOLUTION-VOTING-CONFLICTS",
        ],
        "decisions": [{
            "method": "exact resolution voting and finalization route/service ownership",
            "feature_ids": [
                "CAP-GOV-RESOLUTION-FINALIZATION",
                "CAP-GOV-RESOLUTION-VOTING-CONFLICTS",
            ],
        }],
        "uncertainties": ["Standalone-resolution quorum semantics require an explicit product decision."],
    },
    "remediation": {
        "status": "in_progress",
        "task_id": "client-new-thread:878b5988-3745-47c0-8e2b-cf451d401f3e",
        "started_at": "2026-08-14T17:13:00+12:00",
        "note": "A SOL 5.6 high isolated remediation task is centralizing quorum-safe resolution finalization while preserving the current governance UI; heavy PHP/Pest remains serialized.",
    },
})


med_error_terminal_bypass = base(
    finding_id="MED-ERROR-LIFECYCLE-TERMINAL-BYPASS-01",
    feature_ids=["CAP-MED-ERROR-REPORT-EVIDENCE", "CAP-MED-ERROR-REVIEW-CLOSURE"],
    module="Medication",
    submodule="Medication-error review, terminal evidence and Control Room signal coherence",
    actor="A Site-authorised medication reviewer investigates and resolves an error through the evidence-bearing lifecycle.",
    priority="P1",
    effort="M",
)
med_error_terminal_bypass.update({
    "route_url": {
        "summary": "Generic update and review routes can write terminal medication-error status outside the dedicated resolve/close lifecycle.",
        "route_names": ["emar.errors.update", "emar.errors.review", "emar.errors.resolve", "emar.errors.close"],
        "route_paths": [
            "emar/errors/{error}",
            "emar/errors/{error}/review",
            "emar/errors/{error}/resolve",
            "emar/errors/{error}/close",
        ],
    },
    "frontend_anchor": {
        "summary": "The existing Review dialog offers Mark resolved without the outcome/preventive-action fields collected by the canonical Resolve dialog.",
        "page_files": ["resources/js/pages/emar/_error-dialogs.tsx"],
        "audited_commit": COMMIT,
    },
    "backend_anchors": [
        "app/Http/Controllers/Emar/MedicationErrorController.php:246-329",
        "app/Services/MedicationIncidentIntegrationService.php:895",
        "app/Models/MedicationError.php:91",
        "app/Services/Tasks/Providers/MedicationErrorProvider.php:44",
        "resources/js/pages/emar/_error-dialogs.tsx:406,490,529",
    ],
    "current_behavior": (
        "The dedicated resolve path requires outcome/preventive actions and resolves the linked Control Room alert, and close requires an already-resolved error. "
        "However generic update accepts terminal status directly and review can mark resolved with notes only; neither path applies the terminal evidence/signal contract."
    ),
    "current_workflow": {
        "summary": "An otherwise authorised same-Site reviewer can hide an error from open worklists while its required operational signal remains live.",
        "failure_sequence": (
            "A major/critical medication error creates an operational signal; Review posts status=resolved without outcome/preventive actions; "
            "the error leaves eMAR/My Day open worklists but its Control Room alert remains unresolved. Generic update can also write closed or reclassify severity without signal coherence."
        ),
        "boundary": "Lifecycle-only terminal writers, required resolution evidence, close attribution, signal creation/resolution, concurrency and audit rollback.",
        "completion_evidence": "Terra xhigh current-source audit; no medication error, signal, browser action or database mutation was executed.",
    },
    "evidence": {
        "anchors": [
            "resolve requires evidence and calls the integration service to resolve the linked alert",
            "close requires resolved state and records close attribution",
            "update accepts terminal statuses and saves directly",
            "review accepts resolved and saves without canonical evidence or signal resolution",
            "open worklists include only reported/investigating",
        ],
        "existing_tests": ["tests/Feature/Emar/MedicationErrorsTest.php"],
        "tests_executed": False,
        "browser_claim_limit": "No Review, Resolve, Close or generic update command was executed; the bypass is source-observed.",
    },
    "problem_root_cause": "Multiple controller writers own medication-error status while only the dedicated resolve/close path owns terminal evidence and Control Room signal consequences.",
    "impact": "A serious medication error can disappear from operational worklists without resolution evidence while the linked safety alert remains active, producing contradictory safety state and audit evidence.",
    "neutral_requirements": "Make one locked lifecycle service the sole terminal writer; remove status from generic update/review; require evidence; keep severity and signal consequences coherent; preserve immutable attribution and rollback.",
    "better_oblivion_design": "Keep the current Review, Resolve and Close composition, but limit Review to non-terminal states and delegate resolution/closure/severity transitions to one transactional aggregate.",
    "cross_module_effects": "eMAR/My Day worklists, Control Room alerts, medication-error history, audits and reporting must project the same terminal state and evidence.",
    "dependencies_sequence": "Define allowed non-terminal review transitions; centralize locked lifecycle and signal effects; remove generic terminal writes/UI option; identify legacy terminal rows for review; run MySQL races.",
    "proposed_owner": "Medication Safety Owner, Control Room Owner and Medication Backend Owner",
    "interim_safeguard": "Do not use Review Mark resolved or generic status update; require the existing Resolve and Close actions and reconcile linked alerts manually.",
    "acceptance_criteria": [
        "Generic update and Review cannot write resolved/closed or bypass required evidence.",
        "Canonical Resolve atomically stores outcome/preventive actions and resolves the linked Control Room alert.",
        "Canonical Close remains blocked until resolved and records immutable close attribution.",
        "Severity reclassification and signal creation remain coherent and rollback together on failure.",
        "Concurrent review/resolve/close yields one valid terminal result and no hidden open alert.",
    ],
    "missing_tests": [
        "Resolved/closed denial through generic update and Review",
        "Canonical evidence-required Resolve and alert resolution",
        "Close prerequisite and immutable attribution",
        "Severity promotion and signal creation coherence",
        "Concurrent review/resolve/close and forced signal-failure rollback",
        "Mobile/desktop Review options and worklist visibility after canonical resolve",
    ],
    "validation_plan": [
        "Run one focused real-MySQL medication-error lifecycle and Control Room signal tree.",
        "Prove terminal/evidence/signal atomicity, replay, concurrency, rollback and Site authority.",
        "Run proportional Review/Resolve UI tests, types/builds and read-only browser verification.",
    ],
    "feature_link_reconciliation": {
        "method": "Exact current route ownership: update maps to report/evidence; review/resolve/close map to error-review closure.",
        "projection_status": "two exact current working-manifest links; runtime validation blocked",
        "legacy_feature_ids": ["CAP-MED-ERROR-REPORT-EVIDENCE", "CAP-MED-ERROR-REVIEW-CLOSURE"],
        "decisions": [{
            "method": "exact medication-error route/controller lifecycle ownership",
            "feature_ids": ["CAP-MED-ERROR-REPORT-EVIDENCE", "CAP-MED-ERROR-REVIEW-CLOSURE"],
        }],
        "uncertainties": [],
    },
})


fixed_asset_depreciation = base(
    finding_id="FIN-FIXED-ASSET-DEPRECIATION-01",
    feature_ids=["CAP-ASSET-FIXED-ASSET-DEPRECIATION"],
    module="Finance",
    submodule="Fixed-asset monthly depreciation execution and journal lineage",
    actor="A finance manager or scheduled monthly job posts depreciation for active fixed assets.",
    priority="P1",
    effort="M",
)
fixed_asset_depreciation.update({
    "route_url": {
        "summary": "The direct depreciation command and scheduled job can repeat the same asset/month without a durable execution claim.",
        "route_names": ["finance.fixed-assets.run-depreciation"],
        "route_paths": ["finance/fixed-assets/run-depreciation"],
    },
    "frontend_anchor": {
        "summary": "The existing fixed-asset workflow is retained; no rendered or deployed UI defect is claimed.",
        "page_files": [],
        "audited_commit": COMMIT,
    },
    "backend_anchors": [
        "routes/finance.php:544",
        "app/Domain/Finance/Http/Controllers/FixedAssetController.php:306",
        "app/Domain/Finance/Services/FixedAssetService.php:89,124,162",
        "database/migrations/2026_03_28_002700_create_fin_fixed_asset_depreciations_table.php:13",
        "app/Domain/Finance/Jobs/RunDepreciationJob.php:15",
        "tests/Feature/Finance/FinanceOperationsScheduleTest.php:19",
    ],
    "current_behavior": (
        "The manager action accepts a date and the monthly job invokes the same service. For every active asset the service "
        "posts a depreciation journal, creates a depreciation row and increases accumulated depreciation, but neither the "
        "service nor schema claims a unique asset/month execution. The existing index is non-unique and the queued job is not unique."
    ),
    "current_workflow": {
        "summary": "A repeat manager request, another date in the same month, or a duplicated/retried job can post the same economic depreciation more than once.",
        "failure_sequence": (
            "An asset has remaining depreciable value; a manager or job runs August depreciation; the service posts one "
            "journal and row; the same month is invoked again before the asset is fully depreciated; a second balanced but "
            "economically duplicate journal and depreciation row overstate expense and accumulated depreciation."
        ),
        "boundary": "Per-asset monthly execution identity, row locking, journal lineage, replay, concurrency, correction and rollback.",
        "completion_evidence": "Terra xhigh current-source audit; no depreciation command, queue job, journal post or database race was executed.",
    },
    "evidence": {
        "anchors": [
            "the direct manager route accepts repeated execution",
            "the service has no existing-period guard before posting",
            "every pass posts a journal and increments accumulated depreciation",
            "the depreciation table has only a non-unique asset/date index",
            "the monthly job has no unique-job execution contract",
        ],
        "existing_tests": ["tests/Feature/Finance/FinanceOperationsScheduleTest.php"],
        "tests_executed": False,
        "browser_claim_limit": "No fixed-asset browser action or runtime accounting result was executed; the duplicate-period path is source-observed.",
    },
    "problem_root_cause": "Fixed-asset depreciation lacks one durable, unique per-asset accounting-period execution aggregate spanning claim, journal, row and asset balance.",
    "impact": "A repeated manual or scheduled execution can overstate depreciation expense and accumulated depreciation while each individual journal remains balanced.",
    "neutral_requirements": "Normalize the accounting period, claim it uniquely per asset under lock, link the journal to the claim, make retries return the existing result, and correct posted runs only through append-only reversal lineage.",
    "better_oblivion_design": "Keep the current route and scheduled job, but make FinFixedAssetDepreciation the canonical per-asset-period execution record with one transactional posting owner.",
    "cross_module_effects": "Fixed-asset register, general ledger, depreciation expense, accumulated depreciation, reporting and audit exports must project one monthly execution and any linked correction.",
    "dependencies_sequence": "Define period normalization and legacy duplicate review; add unique lineage; centralize locked claim/post/update; add reversal semantics; then run MySQL replay/concurrency/failure tests.",
    "proposed_owner": "Finance Product Owner, Fixed Assets Accountant and Finance Backend Assurance",
    "interim_safeguard": "Run depreciation once per controlled month and reconcile depreciation rows/journals for duplicate asset-month entries before relying on the register.",
    "acceptance_criteria": [
        "Two requests for one asset/month create one depreciation row, one journal and one accumulated-depreciation increment.",
        "Two independent workers racing the same asset/month converge on one committed execution.",
        "Different months remain distinct, while different dates within one normalized month reuse the same execution.",
        "Posting failure rolls back the period claim, journal, depreciation row and asset balance together.",
        "A correction preserves the original execution and appends linked reversal evidence rather than deleting or overwriting it.",
    ],
    "missing_tests": [
        "Duplicate manager request and same-month different-date replay",
        "Independent-process asset/month race",
        "Different-month positive",
        "Injected journal/posting failure rollback",
        "Legacy duplicate preflight and append-only correction lineage",
        "Scheduled-job retry and direct-route parity",
    ],
    "validation_plan": [
        "Run one focused real-MySQL fixed-asset depreciation tree in a disposable schema.",
        "Prove normalized-period uniqueness, replay, independent concurrency, rollback and journal/register projection.",
        "Run proportional finance reporting/export checks and unchanged frontend gates only if presentation changes.",
    ],
    "feature_link_reconciliation": {
        "method": "Exact current run-depreciation route/controller/service ownership.",
        "projection_status": "one exact current working-manifest link; runtime validation blocked",
        "legacy_feature_ids": ["CAP-ASSET-FIXED-ASSET-DEPRECIATION"],
        "decisions": [{
            "method": "exact fixed-asset depreciation route and posting-service ownership",
            "feature_ids": ["CAP-ASSET-FIXED-ASSET-DEPRECIATION"],
        }],
        "uncertainties": [],
    },
})


med_admin_correction_api = base(
    finding_id="MED-ADMIN-CORRECTION-API-BYPASS-01",
    feature_ids=["CAP-MED-API-ADMINISTRATION-RECORD-CORRECT"],
    module="Medication",
    submodule="Administration-correction API request and independent review lifecycle",
    actor="A Site-authorised medication worker requests correction of an administration and an independent authorised worker decides it.",
    priority="P1",
    effort="M",
)
med_admin_correction_api.update({
    "route_url": {
        "summary": "The administration-correction API writes a cloned record outside the established pending-correction decision lifecycle.",
        "route_names": ["api.medications.administrations.correct"],
        "route_paths": ["api/medications/clients/{client}/mar/administrations/{administration}/corrections"],
    },
    "frontend_anchor": {
        "summary": "No new surface is required; the existing correction worklist/decision flow is the authoritative review path.",
        "page_files": ["resources/js/pages/emar/corrections/index.tsx"],
        "audited_commit": COMMIT,
    },
    "backend_anchors": [
        "routes/api_medications.php:63-65",
        "app/Http/Controllers/Api/MedicationsApiController.php:913-998",
        "app/Http/Controllers/MedicationAdministrationCorrectionController.php:14-29,94-105",
        "app/Services/Medication/EnhancedMarService.php",
    ],
    "current_behavior": (
        "ROUTE-0027 accepts clients.update as correction authority and clones an administration with the requested effective status, "
        "but does not set correction_status=pending or enter the independent correction worklist. The row is unreviewable while history/report projections can still surface it."
    ),
    "current_workflow": {
        "summary": "The API correction path bypasses the canonical pending-request and independent-decision workflow.",
        "failure_sequence": (
            "A clients.update actor posts a correction, including status given; the API writes a clone outside pending review; "
            "approval/rejection cannot consume it, while downstream medication history can treat it as an effective administration."
        ),
        "boundary": "Explicit correction authority, canonical client/Site/assignment binding, pending request, independent decision, duplicate concurrency and effective projections.",
        "completion_evidence": "Independent current-source audit; no API correction, stock effect, review decision, report or database race was executed.",
    },
    "evidence": {
        "anchors": [
            "API correctAdministration clones and saves without correction_status=pending",
            "HTML correction request explicitly creates pending",
            "decision endpoints and worklist accept only pending correction rows",
            "API middleware/controller permit clients.update without dedicated correction authority",
            "history/report paths do not consistently exclude unapproved corrections",
        ],
        "existing_tests": ["tests/Feature/Api/MedicationsApiTest.php", "tests/Feature/Emar/MedicationAdministrationCorrectionTest.php"],
        "tests_executed": False,
        "browser_claim_limit": "No API request or correction decision was executed; the bypass is source-observed.",
    },
    "problem_root_cause": "API and HTML correction creation are separate implementations, and the API treats a cloned effective administration as correction completion rather than a pending request.",
    "impact": "An actor without explicit correction authority can create an unreviewable medication-history row that bypasses independent review and may appear effective in MAR/report evidence.",
    "neutral_requirements": "Use one locked correction-request owner, explicit correction permission and canonical scope; create pending only; keep decision independent; never repeat stock/CD effects; exclude non-approved corrections from effective projections.",
    "better_oblivion_design": "Retain API and HTML response contracts but delegate both to one correction-request service and the existing independent decision workflow.",
    "cross_module_effects": "MAR history, medication reports, stock/CD projections, alerts, audit exports and correction worklists must project the same approved correction state.",
    "dependencies_sequence": "Confirm role provisioning for medications.administer.correct; centralize scope/locking and pending creation; constrain duplicate requests; then repair effective projections and run MySQL concurrency tests.",
    "proposed_owner": "Medication Product Owner, Clinical Safety Owner and Medication API Owner",
    "interim_safeguard": "Disable ROUTE-0027 for clients.update-only callers and require the existing correction worklist until the shared request service is deployed.",
    "acceptance_criteria": [
        "clients.update alone cannot create a correction; explicit correction authority and canonical Site/assignment scope are required.",
        "API and HTML create the same append-only pending request linked to the unchanged original administration.",
        "Only a distinct authorised actor can approve; originator self-approval and forged client/administration pairing are denied.",
        "Correction request creation never repeats stock or controlled-drug effects and concurrent requests yield at most one pending row.",
        "Pending and rejected corrections are excluded from effective MAR/history/report outcomes.",
    ],
    "missing_tests": [
        "clients.update-only denial and explicit correction-authority positive",
        "API pending creation and independent approval/self-approval denial",
        "Forged client/administration pairing concealment",
        "No stock/CD effect when requested status is given",
        "Concurrent duplicate pending request",
        "Pending/rejected MAR, history and report exclusion",
    ],
    "validation_plan": [
        "Run one focused real-MySQL medication-correction tree in a disposable schema.",
        "Prove permission, scope, pending/decision, replay, concurrency, rollback and projection invariants.",
        "Run proportional API, eMAR history/report and unchanged frontend gates.",
    ],
    "feature_link_reconciliation": {
        "method": "Exact ROUTE-0027 API administration-correction target ownership; distinct from MED-OVERRIDE-01 ROUTE-0028.",
        "projection_status": "one exact current working-manifest link; runtime validation blocked",
        "legacy_feature_ids": ["CAP-MED-API-ADMINISTRATION-RECORD-CORRECT"],
        "decisions": [{
            "method": "exact administration-correction API route/controller ownership",
            "feature_ids": ["CAP-MED-API-ADMINISTRATION-RECORD-CORRECT"],
        }],
        "uncertainties": [],
    },
    "remediation": {
        "status": "in_progress",
        "task_id": "019ffeca-b995-70d3-a6e8-5967fa8e4a5c",
        "started_at": "2026-08-14T17:42:00+12:00",
        "note": "A SOL 5.6 high isolated remediation task is converging API/HTML correction requests on the pending independent-review lifecycle; heavy PHP/Pest remains serialized.",
    },
})


med_cd_void_reversal = base(
    finding_id="MED-CD-VOID-REVERSAL-01",
    feature_ids=["CAP-MED-CD-REGISTER-BALANCE", "CAP-MED-DESTRUCTION-REGISTER"],
    module="Medication",
    submodule="Controlled-drug destruction void and append-only balance reversal",
    actor="An authorised medication-governance worker voids an erroneous destruction record without rewriting the original controlled-drug evidence.",
    priority="P1",
    effort="M",
)
med_cd_void_reversal.update({
    "route_url": {
        "summary": "The destruction void route annotates the register row after creation has already changed stock and the controlled-drug ledger.",
        "route_names": ["emar.destructions.store", "emar.destructions.void"],
        "route_paths": ["emar/destructions", "emar/destructions/{destruction}/void"],
    },
    "frontend_anchor": {
        "summary": "The existing destruction register exposes the native void action; no parallel correction surface is required.",
        "page_files": ["resources/js/pages/emar/destructions/index.tsx"],
        "audited_commit": COMMIT,
    },
    "backend_anchors": [
        "routes/emar.php:216-218",
        "app/Http/Controllers/Emar/EmarController.php:4061-4105,5238-5254",
        "app/Models/MedicationDestruction.php:90-96",
        "database/migrations/*medication_destructions*",
        "database/migrations/*controlled_drug*",
    ],
    "current_behavior": (
        "Creating a controlled-drug destruction decrements stock and appends a disposal movement, but voiding the register row only records void metadata. "
        "The schema has no immutable link from the destruction to its original disposal movement and no unique compensating reversal lineage."
    ),
    "current_workflow": {
        "summary": "The register can mark a destruction void while authoritative stock and controlled-drug balances retain the original disposal effect.",
        "failure_sequence": (
            "A duplicate or incorrectly recorded destruction changes stock and the controlled-drug ledger, then an authorised worker voids it. "
            "The row is excluded from verified projections but no linked compensating movement restores the erroneous balance effect."
        ),
        "boundary": "Erroneous-record void versus physical destruction, immutable original evidence, exact reversal lineage, replay and concurrent void.",
        "completion_evidence": "Independent current-source review; no destruction, void, stock mutation, report or database race was executed.",
    },
    "evidence": {
        "anchors": [
            "creation decrements stock and appends a controlled-drug disposal entry",
            "void updates only voided_at, void_reason and voided_by_id",
            "verified scope excludes voided rows without restoring stock or ledger balance",
            "no destruction-to-ledger or reversal-of-entry uniqueness is present",
        ],
        "existing_tests": ["tests/Feature/Emar/ControlledDrugsTest.php"],
        "tests_executed": False,
        "browser_claim_limit": "No destruction or void control was activated; the balance/provenance defect is source-observed.",
    },
    "problem_root_cause": "Void is register metadata rather than one locked append-only reversal of the exact original stock and controlled-drug movement.",
    "impact": "An erroneous destruction can disappear from the verified register while stock and controlled-drug balances remain understated.",
    "neutral_requirements": "Preserve the original destruction and disposal movement; classify whether physical destruction occurred; for an erroneous record append exactly one linked compensating movement under locks and strict audit.",
    "better_oblivion_design": "Keep the existing void workflow, but delegate it to the canonical medication-governance service with immutable original-movement linkage and a unique reversal key.",
    "cross_module_effects": "Stock projections, controlled-drug balance, destruction register, audit exports, PDFs and incident evidence must agree on one original/reversal pair.",
    "dependencies_sequence": "Define physical-versus-recording-error semantics; add reversible lineage constraints; fail closed on ambiguous legacy rows; centralize locked void; run MySQL race and projection tests.",
    "proposed_owner": "Medication Product Owner, Controlled Drugs Officer and Medication Backend Owner",
    "interim_safeguard": "Do not use Void as balance correction; require pharmacy reconciliation and a documented compensating entry for any erroneous destruction record.",
    "acceptance_criteria": [
        "An erroneous-record void appends one linked stock/CD compensating movement and preserves the original evidence.",
        "A physical destruction cannot be represented as returned stock merely by voiding its record.",
        "Replay and concurrent void yield one reversal lineage and cannot double-restore stock.",
        "Wrong-Site/direct-object attempts are concealed while the existing explicit global positive is separately proved.",
        "Audit, stock, ledger and register changes are atomic and report/PDF projections show the immutable pair.",
    ],
    "missing_tests": [
        "Erroneous-record reversal and physical-destruction non-reversal",
        "Double-void replay and independent-process concurrent void",
        "Wrong-Site/direct-ID and explicit-global authority matrix",
        "Injected audit/ledger/stock failure rollback",
        "Register balance, report and PDF projection of original/reversal pair",
        "Migration up/down and legacy unlinked fail-closed review",
    ],
    "validation_plan": [
        "Run one focused real-MySQL controlled-drug destruction tree in a disposable schema.",
        "Prove exact lineage, balance, replay, concurrency, rollback and Site authority with exact cleanup.",
        "Run proportional register/report/PDF and unchanged eMAR frontend gates.",
    ],
    "feature_link_reconciliation": {
        "method": "Exact destruction-register and controlled-drug-balance ownership; distinct integrity follow-on to MED-RBAC-01.",
        "projection_status": "two exact current working-manifest links; runtime validation blocked",
        "legacy_feature_ids": ["CAP-MED-CD-REGISTER-BALANCE", "CAP-MED-DESTRUCTION-REGISTER"],
        "decisions": [{
            "method": "exact destruction create/void and controlled-drug balance ownership",
            "feature_ids": ["CAP-MED-CD-REGISTER-BALANCE", "CAP-MED-DESTRUCTION-REGISTER"],
        }],
        "uncertainties": ["Legacy rows without provable disposal linkage remain review-required rather than receiving inferred reversals."],
    },
    "remediation": {
        "status": "in_progress",
        "task_id": "019ffec5-82a3-7ec2-8c0f-717bef05e42e",
        "started_at": "2026-08-14T17:35:00+12:00",
        "note": "A SOL 5.6 high isolated remediation task is adding append-only destruction reversal integrity; heavy PHP/Pest remains serialized.",
    },
})


privacy_evidence = base(
    finding_id="PRIV-EVID-01",
    feature_ids=["CAP-PRIV-SHARED-ATTACHMENT-SERVICE"],
    module="Privacy",
    submodule="Shared privacy-evidence retention, revocation and governed destruction",
    actor="An authorised Privacy worker removes or supersedes case evidence while retaining its immutable history until governed destruction.",
    priority="P1",
    effort="M",
)
privacy_evidence.update({
    "route_url": {
        "summary": "The shared privacy attachment destroy route physically deletes private evidence through an ordinary management action.",
        "route_names": ["privacy.attachments.store", "privacy.attachments.destroy", "privacy.attachments.download"],
        "route_paths": ["privacy/attachments", "privacy/attachments/{attachment}", "privacy/attachments/{attachment}/download"],
    },
    "frontend_anchor": {
        "summary": "The shared privacy attachment pane exposes a one-click Remove control across privacy case types.",
        "page_files": ["resources/js/components/privacy/privacy-attachments-pane.tsx"],
        "audited_commit": COMMIT,
    },
    "backend_anchors": [
        "routes/privacy.php:20",
        "app/Http/Controllers/PrivacyAttachmentController.php:97-126",
        "app/Models/PrivacyAttachment.php:19-75",
        "database/migrations/2026_06_20_100001_create_privacy_attachments_table.php:20-50",
        "app/Models/DataSubjectRequest.php:149",
        "app/Http/Controllers/PrivacyDashboardController.php:350",
    ],
    "current_behavior": (
        "After a generic per-domain permission check, ordinary Remove deletes the private-storage object and soft-deletes the row. "
        "It does not lock and authorize the concrete parent, retain an immutable digest/original, capture reason or destruction authority, or append revocation evidence."
    ),
    "current_workflow": {
        "summary": "Soft deletion hides the metadata after the binary has already been irreversibly removed.",
        "failure_sequence": (
            "Identity, response, breach, DPIA, hold or retention evidence is uploaded, then a domain writer selects or guesses its ID and invokes Remove. "
            "The parent relation omits the row and the bytes cannot be recovered; storage/DB partial failure has no reconciliation boundary."
        ),
        "boundary": "Concrete privacy parent/type authority, terminal and legal-hold state, immutable evidence, revocation versus governed destruction and storage/DB recovery.",
        "completion_evidence": "Independent current-source review; no attachment, storage object, case, database or browser action was mutated.",
    },
    "evidence": {
        "anchors": [
            "destroy physically deletes the private object before soft-deleting metadata",
            "model/schema contain no digest, revocation, destruction authority or deletion provenance",
            "existing test positively asserts file removal during destroy",
            "DSR model identifies attachments as identity-verification and response-pack evidence",
            "ordinary relations omit the soft-deleted row",
        ],
        "existing_tests": ["tests/Feature/Privacy/PrivacyAttachmentTest.php"],
        "tests_executed": False,
        "browser_claim_limit": "No Remove control was activated and no storage object changed; the evidence-loss path is source-observed.",
    },
    "problem_root_cause": "The shared attachment service models normal removal as physical destruction rather than append-only revocation governed by parent state and retention.",
    "impact": "Privacy identity, response, breach or hold evidence can disappear irreversibly through an ordinary UI action.",
    "neutral_requirements": "Authorize the concrete parent/type; preserve immutable digest and bytes; append revocation/supersession evidence; require governed destruction authority; reconcile storage and DB failures visibly.",
    "better_oblivion_design": "Retain the pane/routes and explicit-global privacy policy, but route attachment lifecycle through one owner that revokes normally and delegates actual deletion to governed retention.",
    "cross_module_effects": "DSR export, breach investigation, DPIA, legal hold, privacy dashboard, retention execution and audit exports must project the same immutable attachment history.",
    "dependencies_sequence": "Inventory parent/type rules; add digest/revocation/destruction provenance and reconciliation state; centralize store/revoke; integrate governed retention destruction; run MySQL/storage failure tests.",
    "proposed_owner": "Privacy Product Owner, Privacy Officer and Evidence/Storage Backend Owner",
    "interim_safeguard": "Disable ordinary Remove for privacy evidence and require documented retention-owner review before physical deletion.",
    "acceptance_criteria": [
        "Normal removal preserves the original private bytes and appends one immutable revocation/supersession event.",
        "The service authorizes the concrete DSR, breach, DPIA, legal-hold or retention parent and denies wrong-domain/forged IDs.",
        "Terminal, refused and held evidence cannot be physically destroyed without governed retention authority and decision provenance.",
        "Concurrent/replayed revocation converges to one history entry and never loses the object.",
        "Storage, audit or DB failure yields an active durable record or visible reconciliation item, never silent loss.",
    ],
    "missing_tests": [
        "Parent/type matrix across DSR, breach, DPIA, legal hold and retention",
        "Wrong-domain/forged ID denial and explicit-global privacy positive",
        "Terminal/refused DSR and active/released-hold retention behavior",
        "Digest/original-byte retention and revocation history",
        "Concurrent/replayed revoke and strict audit rollback",
        "Storage/DB partial-failure reconciliation and governed destruction",
    ],
    "validation_plan": [
        "Run one focused real-MySQL privacy attachment lifecycle tree with isolated private storage.",
        "Prove parent authority, retention, digest, replay, concurrency, audit and storage reconciliation with exact cleanup.",
        "Run proportional DSR/breach/hold/export regressions and unchanged pane frontend gates.",
    ],
    "feature_link_reconciliation": {
        "method": "Exact shared privacy attachment service target and store/destroy/download ownership.",
        "projection_status": "one exact current working-manifest link; runtime validation blocked",
        "legacy_feature_ids": ["CAP-PRIV-SHARED-ATTACHMENT-SERVICE"],
        "decisions": [{
            "method": "exact shared privacy attachment controller/model/storage ownership",
            "feature_ids": ["CAP-PRIV-SHARED-ATTACHMENT-SERVICE"],
        }],
        "uncertainties": ["The product's explicit-global privacy authority is preserved; no Site scope is inferred."],
    },
    "remediation": {
        "status": "in_progress",
        "task_id": "019ffec5-fb69-7e41-be38-5242abd60ef2",
        "started_at": "2026-08-14T17:37:00+12:00",
        "note": "A SOL 5.6 high isolated remediation task is replacing ordinary evidence deletion with governed revocation and retention; heavy PHP/Pest remains serialized.",
    },
})


data = json.loads(FINDINGS.read_text(encoding="utf-8-sig"))
rows = [row for row in data["findings"] if row.get("id") not in NEW_IDS]

# Keep the retained finding-level execution boundary aligned with the current
# 902-register human denominator.  These are status labels only: no task,
# browser flow, usability score, test, or runtime result is promoted here.
for row in rows:
    ease = row.get("ease_evidence")
    if not isinstance(ease, dict):
        continue
    status = ease.get("validation_status")
    if not isinstance(status, str):
        continue
    status = status.replace("0/784", "0/788")
    for stale_link_status in (
        "the broader 894-target finding-link rebuild remains incomplete",
        "the 901-target literal finding-link reconciliation remains partial",
        "the 902-target literal finding-link reconciliation remains partial",
    ):
        status = status.replace(
            stale_link_status,
            "the 902-target literal finding link is reconciled",
        )
    if status.startswith("Blocked—finding retained from the superseded feature projection;"):
        status = (
            "Blocked—source finding retained; exact current feature linkage may remain partial, "
            "and representative-role execution plus independent ten-dimension validation are "
            "unperformed (0/788 human tasks executed)"
        )
    ease["validation_status"] = status

rows.extend([
    email, permission, renewals, user_counts, my_day_overflow, staff_creation_path,
    safe_evidence, payment_allocation, eftpos_settlement, med_order_erasure, dsr_lifecycle,
    gov_resolution_quorum, med_error_terminal_bypass, med_admin_correction_api,
    fixed_asset_depreciation, med_cd_void_reversal, privacy_evidence,
])
ids = [row["id"] for row in rows]
if len(ids) != len(set(ids)):
    raise RuntimeError("Finding IDs are not unique")

manifest_ids = {row["working_key"] for row in json.loads(MANIFEST.read_text(encoding="utf-8"))["targets"]}
for row in (
    email, permission, renewals, user_counts, my_day_overflow, staff_creation_path,
    safe_evidence, payment_allocation, eftpos_settlement, med_order_erasure, dsr_lifecycle,
    gov_resolution_quorum, med_error_terminal_bypass, med_admin_correction_api,
    fixed_asset_depreciation, med_cd_void_reversal, privacy_evidence,
):
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
        if decision.get("legacy_family_id") not in {
            "exact-current-901-source-intersection-wave-2026-08-13",
            EXACT_FINDING_LINK_WAVE_DECISION_ID,
        }
    ]
    decisions.append({
        "legacy_family_id": EXACT_FINDING_LINK_WAVE_DECISION_ID,
        "method": "source-proven exact current target route/backend intersection",
        "feature_ids": exact_targets,
        "source_anchors": list(row.get("backend_anchors", [])),
        "evidence": (
            "Read-only static reconciliation retained the validated upstream 30-finding "
            "route intersection and seven final source-owner decisions without claiming runtime proof."
        ),
        "upstream_map_sha256": EXACT_FINDING_LINK_WAVE_UPSTREAM_SHA256,
        "generator_map_sha256": wave_generator_sha256,
    })
    reconciliation["decisions"] = decisions
    reconciliation["projection_status"] = (
        "literal_current_manifest_link_present; runtime_and_full_finding_adjudication_blocked"
    )

# The final source-owner adjudication resolved CTRL-SIGNAL-002 to the distinct
# backend-only machine target. Remove the superseded no-owner uncertainty while
# retaining all unrelated finding uncertainty.
signal = finding_by_id["CTRL-SIGNAL-002"]
signal_reconciliation = signal.setdefault("feature_link_reconciliation", {})
signal_reconciliation["uncertainties"] = [
    uncertainty
    for uncertainty in signal_reconciliation.setdefault("uncertainties", [])
    if uncertainty.get("reason_code") not in {
        "no_exact_901_signal_service_owner",
        "no_exact_902_signal_service_owner",
    }
]

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

# Newly discovered current findings may be appended by a read-only audit wave
# before their isolated remediation task has written a tracking object. Keep
# the common finding schema complete without changing the source evidence.
for row in rows:
    if "remediation" in row:
        continue
    if row.get("id") == "CONSENT-CAPACITY-01":
        row["remediation"] = {
            "status": "in_progress",
            "task_id": "019ffe54-61d7-7033-b3b3-f8ecea811b45",
            "started_at": "2026-08-14T15:28:00+12:00",
            "note": (
                "A SOL 5.6 high isolated remediation task is implementing decision-specific capacity and "
                "substitute-authority evidence while preserving the existing consent UI/UX. Heavy PHP/Pest "
                "verification remains centrally serialized."
            ),
        }
    else:
        row["remediation"] = {
            "status": "open",
            "note": "No isolated remediation task is recorded for this retained finding.",
        }

# Keep actively published-but-unmerged remediations visible without awarding
# verified completion or altering the immutable audit baseline.
finding_by_id["MED-RBAC-01"]["remediation"] = {
    "status": "in_progress",
    "task_id": "019ffdc6-df5e-7771-b246-2d159fe62147",
    "started_at": "2026-08-14T12:45:00+12:00",
    "note": (
        "Isolated branch codex/med-rbac-01 at 9899d614 is behavior/Pint green and pushed. "
        "Independent release review and coordinator merge remain required; no verified-completion credit is awarded."
    ),
}
finding_by_id["SITE-CHECK-002"]["remediation"] = {
    "status": "fixed_pending_verification",
    "task_id": "019ffdc7-1322-73c0-b13f-f7dff72711fa",
    "started_at": "2026-08-14T12:45:00+12:00",
    "note": (
        "Merged and pushed to main at 9b420035 after independent Terra xhigh review accepted the canonical ownership, "
        "direct-object concealment, required-response and explicit typed-attestation corrections. The final proportional "
        "MySQL tree passed 36 tests/356 assertions, scoped PHP syntax and Pint passed, and exact schema/process cleanup "
        "was proven. This remains fixed-pending-verification; no deployed acceptance or immutable baseline completion credit is claimed."
    ),
}
finding_by_id["VIS-EMAR-CLINICAL-LEAD-MOBILE-OVERFLOW-01"]["remediation"] = {
    "status": "fixed_pending_verification",
    "task_id": "019ffe46-f6af-7652-a951-cc056af7826e",
    "started_at": "2026-08-14T15:30:00+12:00",
    "note": (
        "Merged and pushed to main at 5ca734706 after independent Terra xhigh review accepted the exact two-file responsive fix. "
        "Focused Vitest, TypeScript, lint/format, client and SSR builds passed; exact-worktree populated 320x844 and 360x844 browser evidence showed no overflow, all named controls in bounds/focusable and zero console warnings. "
        "This remains fixed-pending-verification and does not change immutable audited-baseline completion credit."
    ),
}
finding_by_id["SAFE-NESTED-01"]["remediation"] = {
    "status": "fixed_pending_verification",
    "task_id": "019ffa4f-6daa-74d1-b7ba-7f380308bc66",
    "started_at": "2026-08-13T20:53:52+12:00",
    "completed_at": "2026-08-13T22:03:00+12:00",
    "branch": "codex/safe-nested-01",
    "commit": "c5a10cdb8f64290aa130cc95eb69172017fa8ace",
    "merged_to_main": True,
    "note": (
        "Merged and pushed to main; the current-main child-controller, route and focused regression blobs retain the "
        "parent-child resolver correction, and the combined integration regression previously passed 88 tests/709 assertions. "
        "Independent current audit reconciliation found that representative-role task execution, denial/recovery browser states "
        "and viewport/accessibility evidence remain unperformed under this audit's completion contract. The remediation therefore "
        "remains fixed-pending-verification and receives no runtime or immutable-baseline completion credit."
    ),
}

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
    "Blocked—not comprehensive or complete. The corrected 902-target register is current (788H/111D/3M). "
    "All 3,024 routes and 962 pages have accepted-target or excluded-surface static dispositions; accepted IDs map to 2,985 routes and 945 pages. "
    "Benchmark/NCM completion credit is 450/902, visual final-ID linkage is 8,153/8,753, material-state linkage is 3,935/4,312, "
    f"and {len(rows)} source-backed findings are retained. Only {len(p0_p1_exact)}/{len(p0_p1)} P0/P1 findings contain a literal current-manifest ID; runtime remains unexecuted."
)
data["statement"] = "Full schema for every retained finding. The 902-row stable-ID manifest is current; static evidence, inference, official-source propositions and owner decisions remain separated. Runtime, representative-role and usability completion are not claimed."
data["counts"] = {
    "P0": priority.get("P0", 0), "P1": priority.get("P1", 0), "P2": priority.get("P2", 0), "P3": priority.get("P3", 0),
    "feature_link_reconciliation": {
        "projection_status": "902_current_literal_link_reconciliation_partial_not_runtime_validation",
        "working_accepted_capabilities": 902, "working_human_capabilities": 788,
        "earlier_894_derivation_superseded": True,
        "working_manifest": "evidence/source/working-capability-manifest-902.json",
        "working_manifest_sha256": "ded38bc3672bf51cb48a02a576cc36ca83d01af6a982dbd19c118ff50edf59b9",
        "working_manifest_unique_stable_ids": 902,
        "stable_id_provenance": {"exact_current": 881, "source_stable": 5, "audit_assigned": 16},
        "route_enrichment": {"targets": 901, "relations": 3065, "unique_routes": 2985, "excluded_surface_relations": 39, "static_disposition_total": 3024},
        "page_enrichment": {"targets": 756, "relations": 1526, "unique_pages": 945, "excluded_surface_relations": 17, "static_disposition_total": 962},
        "backend_enrichment": {"targets": 729, "relations": 828, "unique_anchors": 469},
        "benchmark_mapping": {"eligible": 450, "verified_benchmark": 361, "documented_no_credible_match": 89, "completion_unproved": 452},
        "visual_linkage": {"assigned": 8153, "rows": 8753, "unresolved": 600, "unique_working_ids": 771},
        "material_state_linkage": {"assigned": 3935, "rows": 4312, "unresolved": 377, "unique_working_ids": 713},
        "final_feature_link_coverage_established": len(exact_finding_ids) == len(rows),
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
