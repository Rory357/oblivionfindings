#!/usr/bin/env python3
"""Materialize bounded RUN177 Fleet trip-index remediation reporting.

This producer validates an already-authored live reporting transition and the
dashboard builder as source only. It never executes the dashboard builder,
changes the frozen RUN175 HTML, runs application code or tests, opens a
browser, touches a database, or publishes Git commits.
"""
from __future__ import annotations

import ast
import hashlib
import json
import os
import subprocess
from pathlib import Path
from typing import Any


SCRIPT = Path(__file__).resolve()
AUDIT = SCRIPT.parent.parent
ROOT = next(parent for parent in SCRIPT.parents if (parent / ".git").exists())
PREFIX = AUDIT.relative_to(ROOT).as_posix()
SCRIPT_REL = SCRIPT.relative_to(AUDIT).as_posix()
OUTPUT_REL = (
    "evidence/source/current-run-177-fleet-trip-index-site-privacy-remediation-"
    "reporting-wave-33.json"
)
OUTPUT = AUDIT / OUTPUT_REL

RUN_ID = (
    "RUN-177-FLEET-TRIP-INDEX-SITE-PRIVACY-01-REMEDIATION-REPORTING-WAVE-33"
)
STATUS = (
    "FLEET_TRIP_INDEX_SITE_PRIVACY_HISTORICAL_REMEDIATED_RECORD_REPORTING_"
    "MATERIALIZED_LOCAL_MAIN_NOT_PUBLISHED_STATIC_OWNERSHIP_PENDING_DASHBOARD_"
    "RUN178_REQUIRED_ZERO_FINAL_FINDING_OR_COMPLETION_CREDIT"
)
REPORTING_INPUT = "167ae89131d9fe2aa7a2636e5d20796002ca7c03"
REPORTING_INPUT_TREE = "96c892f41d42b7e46b2825c1022032800238c0fc"
REPORTING_INPUT_PARENTS = [
    "8a4f653830a2f3a3f8f5e90cfd94d63635b6124c",
    "45b5cbd6ef198ac03f8664276097ae0ece4aa14c",
]
AUDIT_RELEASE = REPORTING_INPUT_PARENTS[0]
AUDIT_RELEASE_TREE = "bff7174e2689b9f7cc2d132caa255ef0cc1f39c9"
BOARD_FIX = REPORTING_INPUT_PARENTS[1]
BOARD_FIX_TREE = "5680582dcd0c2187264b2226a0622fb57c94f535"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"

FLEET_BASE = "13a7f37da9c966fa531f20e82b1bb9eac814e041"
FLEET_BASE_TREE = "e952efb7d0b1446d2c6b67bbd28339bd906d1b38"
FLEET_FIX = "790bc11e3fb2b17a0eb8ba96e2cdea87ba8175b5"
FLEET_FIX_TREE = "657abb07867068865f935008c2c43dea38c867c8"
FLEET_MERGE = "c643c9e5eecf3b4272f55ec6d5aab4b99c3e300d"
FLEET_MERGE_TREE = FLEET_FIX_TREE
FLEET_PATCH_ID = "a602e6dfa300cad25462998039558b03536e6c0c"

RUN_175_DASHBOARD_SHA256 = (
    "8586a2cb3cc6c248788ea71ecc20c2e0c4785fd5a7a5a00fa11d2ee48f48490c"
)
RUN_175_GENERATOR_SHA256 = (
    "bbe96e058059c2fde5bed93fc6fe214b05ce65bd8d4a9417a9a769618bfcbe87"
)
RUN_175_RECEIPT_SHA256 = (
    "6ef4d3f7e1018c0e137ee485007847b1149bff9f5cf94feefebaf3eeebc09de6"
)
RUN_175_RECEIPT_SEAL = (
    "8ae9223300ef5851bd432da467a5ae7dba2e0460ff69291188039da0dfad7ae4"
)
RUN_176_GENERATOR_SHA256 = (
    "26386c9394da1ce73274e8d9a4c19e45fd5bad7409d37770401eb0b491a6f9ba"
)
RUN_176_RECEIPT_SHA256 = (
    "6e9fa6d855e6ec168d4c651921702dab8872810ddd89f6ba3cd353bf49e0c87c"
)
RUN_176_RECEIPT_SEAL = (
    "541e2cc0c0a167b48cfac6e96ab2286d9898cb737dec2eb115b41d56e74b9617"
)
RUN_176R_GENERATOR_SHA256 = (
    "15ecb5bb1982c8647f30da697da35593deef6575b3c19806920b7596be2834c3"
)
RUN_176R_RECEIPT_SHA256 = (
    "f1f7369306235ad7d5f318b512dca94e853d96e182ff5c63ddc509534fa545c1"
)
RUN_176R_RECEIPT_SEAL = (
    "a596b81ded6db13d312bdcbf52deb7a3e088f8404d81c774b3f5910c86140f49"
)
BASELINE_FINDINGS_SHA256 = (
    "32675839fb79d66d49d93a97be66f2805d854231c6ca8c513d336941c6291b0e"
)

REPORTING_SURFACES = [
    "00-executive-summary.md",
    "01-repository-module-map.md",
    "07-module-findings.md",
    "11-prioritised-roadmap.md",
    "12-native-build-and-do-not-copy-register.md",
    "13-unresolved-questions-and-evidence-gaps.md",
    "findings.json",
    "generators/build-current-audit-dashboard.py",
]
PROSE_SURFACES = REPORTING_SURFACES[:6]

RUN_175_GENERATOR_REL = (
    "generators/materialize-run-175-audit-dashboard-verification-wave-32.py"
)
RUN_175_RECEIPT_REL = (
    "evidence/browser/current-audit-dashboard-verification-run-175-wave-32.json"
)
RUN_176_GENERATOR_REL = (
    "generators/materialize-run-176-fleet-trip-index-site-privacy-remediation-"
    "wave-33.py"
)
RUN_176_RECEIPT_REL = (
    "evidence/runtime/current-run-176-fleet-trip-index-site-privacy-remediation-"
    "wave-33.json"
)
RUN_176R_GENERATOR_REL = (
    "generators/materialize-independent-run-176-fleet-trip-index-site-privacy-"
    "remediation-review-wave-33.py"
)
RUN_176R_RECEIPT_REL = (
    "evidence/runtime/current-run-176r-independent-fleet-trip-index-site-privacy-"
    "remediation-review-wave-33.json"
)

FLEET_APPLICATION_PATHS = [
    "app/Http/Controllers/FleetAssets/VehicleController.php",
    "tests/Feature/FleetAssets/FleetTripIndexSitePrivacyTest.php",
]
BOARD_APPLICATION_PATHS = [
    "app/Domain/Governance/Http/Controllers/BoardPackController.php",
    "app/Domain/Governance/Http/Controllers/GovernanceAuditLogController.php",
    "app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php",
    "app/Domain/Governance/Http/Controllers/ReportController.php",
    "app/Domain/Governance/Jobs/SendBoardPackNotification.php",
    "app/Domain/Governance/Jobs/SendPreReadReminders.php",
    "app/Domain/Governance/Models/BoardMember.php",
    "app/Domain/Governance/Models/BoardPack.php",
    "app/Domain/Governance/Models/GovernanceMeeting.php",
    "app/Domain/Governance/Notifications/BoardPackPublishedNotification.php",
    "app/Domain/Governance/Notifications/PreReadReminderNotification.php",
    "app/Domain/Governance/Services/BoardPackAccessService.php",
    "app/Domain/Governance/Services/BoardPackBuilderService.php",
    "app/Domain/Governance/Services/GovernanceAuditService.php",
    "app/Domain/Governance/Services/GovernanceWorkflowService.php",
    "app/Domain/Governance/Support/GovernancePresenter.php",
    "app/Http/Controllers/AuditLogController.php",
    "app/Http/Controllers/CombinedReportController.php",
    "app/Http/Controllers/ModuleReportController.php",
    "app/Http/Controllers/MyTasksController.php",
    "app/Http/Controllers/NotificationInboxController.php",
    "app/Http/Controllers/Portal/PortalNotificationController.php",
    "app/Http/Controllers/ReportsController.php",
    "app/Http/Controllers/Settings/AuditLogSettingsController.php",
    "app/Http/Middleware/HandleInertiaRequests.php",
    "app/Services/Audit/AuditLogViewService.php",
    "app/Services/Compliance/ComplianceMetricsService.php",
    "app/Support/SafeOperationalData.php",
    "resources/js/pages/Governance/Meetings/Show.tsx",
    "resources/js/pages/Governance/Packs/Show.tsx",
    "routes/portal.php",
    "tests/Feature/Governance/GovernanceBoardPacksTest.php",
]
PRESERVED_APPLICATION_PATHS = FLEET_APPLICATION_PATHS + BOARD_APPLICATION_PATHS

EXPECTED_BASELINE_RECORD_HASHES = {
    "MED-RBAC-01": "3aeac2fd6d69cc84cae814773912eea1bcc9417c3daedb8f08d1ac7d959069cb",
    "MED-CD-SCOPE-01": "c6839938c8c645e59715ce7184e4a833fe516f7403ff1acd896b76a066b48037",
    "MED-CD-ATOMICITY-01": "ebc201ff9af763264c037389ad51a71e07a5e82ad5aa72661fbd40a0dc370ee6",
    "GOV-EXECUTIVE-VISIBILITY-01": "316f7b85d61e16da4eeeb17c6a5b50a8ccdacbe4c443ec86370226268af4d175",
    "GOV-BOARD-PACK-VISIBILITY-01": "78292106d28b8ee8bf8e050aa89741d79b54522cff844a1b482c4b556c5c4c3f",
    "GOV-RESOLUTION-QUORUM-01": "eaf59bfe06b52f012c1a82bbb9a63139208f9840af7a84a26545bca8c81b30dd",
    "HS-REGISTER-SITE-SCOPE-01": "369da912ef9004ea3a7696280dcdf04051e6dca14087f0c6b185986ef1b9ec02",
    "PRIV-REPORT-DOMAIN-RBAC-01": "d0c2d60c324469933b989e4dfc1060c395521a9132c95b5939a231f3a34a2ac5",
    "SAFE-INTAKE-CANONICAL-SCOPE-01": "57e33e6c75f33ff2449e5504a7ee8fd6c3e22588d7eb373c2b36bdc5765ee42b",
    "SAFE-ALERT-DEDUP-IDENTITY-01": "d74de781f6a9723a96f9de5305917e262d3a1a7c972a4ebc0557b6d768d70859",
    "SAFE-PROJECTION-DURABILITY-01": "6476e684b7ad18453a7dda24545353aefc5816eea537e4b0124df7c09bc71f1e",
    "SET-API-WEBHOOK-DESTINATION-01": "ad3ad1b1ca4f26020ee468f544506f2aa5c0fb2228ff5b908d1815680da12474",
}

EXPECTED_FLEET_RECORD = json.loads(
    r"""{
  "id": "FLEET-TRIP-INDEX-SITE-PRIVACY-01",
  "record_status": "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING",
  "feature_id": "CAP-FLEET-VEHICLE-REGISTER",
  "candidate_feature_id": "CAP-FLEET-VEHICLE-REGISTER",
  "feature_identity_status": "PENDING_FRESH_SEMANTIC_REVIEW",
  "feature_id_role": "CANDIDATE_REPORTING_ASSOCIATION_ONLY_ZERO_STATIC_OWNERSHIP_CREDIT",
  "related_feature_ids": [],
  "historical_provenance": {
    "application_commit": "13a7f37da9c966fa531f20e82b1bb9eac814e041",
    "classification": "REAL_HISTORICAL_FLEET_TRIP_INDEX_SITE_PRIVACY_CONDITION",
    "historical_risk": "Foreign-Site trip rows, nested driver identity, CSV data, and aggregate projections could be disclosed or influenced through the trip index; foreign or missing vehicle filters were not consistently concealed.",
    "preserved_identity_only": true,
    "new_record_authorized_by_run_176r": true,
    "record_present_before_run_177": false,
    "pre_run_177_register_sha256": "32675839fb79d66d49d93a97be66f2805d854231c6ca8c513d336941c6291b0e"
  },
  "current_adjudication": {
    "application_baseline_commit": "13a7f37da9c966fa531f20e82b1bb9eac814e041",
    "application_baseline_tree": "e952efb7d0b1446d2c6b67bbd28339bd906d1b38",
    "fix_commit": "790bc11e3fb2b17a0eb8ba96e2cdea87ba8175b5",
    "fix_tree": "657abb07867068865f935008c2c43dea38c867c8",
    "application_commit": "c643c9e5eecf3b4272f55ec6d5aab4b99c3e300d",
    "repository_tree": "657abb07867068865f935008c2c43dea38c867c8",
    "origin_main_observed": "c39b076547056b1e158c604957a04bd8b75b0f29",
    "stable_patch_id": "a602e6dfa300cad25462998039558b03536e6c0c",
    "verdict": "REPRODUCED_AND_REMEDIATED_LOCAL_MAIN_NOT_PUBLISHED",
    "scope": "Selected GET /fleet-assets/trips index and CSV branch only, including approved-Site vehicle visibility, canonical direct/home/client Site provenance, foreign-or-missing vehicle concealment, archived-Site exclusion, and nested driver redaction.",
    "run_176_receipt": "evidence/runtime/current-run-176-fleet-trip-index-site-privacy-remediation-wave-33.json",
    "run_176_receipt_sha256": "6e9fa6d855e6ec168d4c651921702dab8872810ddd89f6ba3cd353bf49e0c87c",
    "run_176r_review": "evidence/runtime/current-run-176r-independent-fleet-trip-index-site-privacy-remediation-review-wave-33.json",
    "run_176r_review_sha256": "f1f7369306235ad7d5f318b512dca94e853d96e182ff5c63ddc509534fa545c1",
    "application_remediation_required": true,
    "application_source_changed": true,
    "integrated_to_main": true,
    "published_to_origin_main": false,
    "publication_authorized": false,
    "static_route_feature_ownership_inherited": false,
    "static_controller_action_bridge_inherited": false,
    "static_page_or_frontend_ownership_inherited": false,
    "queue_advance_inherited": false,
    "adjacent_fleet_route_correctness_inherited": false,
    "broader_fleet_correctness_inherited": false
  },
  "passes": [
    "P2",
    "P5",
    "P6",
    "P7"
  ],
  "module_submodule": {
    "module": "Fleet & Assets",
    "submodule": "SELECTED_TRIP_INDEX_SITE_PRIVACY_BOUNDARY",
    "submodule_status": "CANDIDATE_REPORTING_ASSOCIATION_ONLY_ZERO_STATIC_OWNERSHIP_CREDIT"
  },
  "actor_and_job": {
    "actor": "Approved-Site fleet trip viewer",
    "secondary_actors": "Actor with fleet.manage, foreign-Site and archived-Site records, canonical direct/home/client Site provenance, and nested driver identities in bounded tests",
    "job": "Review and export vehicle trip history without receiving trip or identity data outside approved operational Sites",
    "status": "BOUNDED_TEST_ACTORS_VALIDATED_NOT_REPRESENTATIVE_SIGNED_IN_BROWSER"
  },
  "route_url": {
    "route_names": "fleet-assets.trips.index",
    "route_paths": "routes/fleet-assets.php:54",
    "raw_route_path_field": "routes/fleet-assets.php:54",
    "queue_id": "RUN090-ROUTE-0085",
    "route_record_id": "RUN077-ROUTE-0693",
    "controller_action": "VehicleController::trips",
    "ownership_status": "PENDING_FRESH_SEMANTIC_REVIEW",
    "status": "BOUNDED_CURRENT_SOURCE_AND_RUNTIME_SCOPE_ONLY"
  },
  "frontend_anchor": {
    "page_files": "resources/js/pages/fleet-assets/trips/index.tsx",
    "raw_page_file_field": "resources/js/pages/fleet-assets/trips/index.tsx",
    "application_commit": "13a7f37da9c966fa531f20e82b1bb9eac814e041",
    "status": "SOURCE_LOCATOR_ONLY_ZERO_STATIC_PAGE_OWNERSHIP_CREDIT"
  },
  "visual_context": {
    "visual_id": null,
    "role": null,
    "site_scope": null,
    "viewport": null,
    "ui_state": null,
    "pattern_type": null,
    "screenshot_reference": null,
    "status": "BLOCKED_NOT_OBSERVED_OR_LINKED_CURRENT_AUDIT"
  },
  "pattern_implementation": {
    "shared_component_or_variant": null,
    "overlay_trigger": null,
    "internal_baseline": null,
    "status": "NOT_ADJUDICATED_FOR_THIS_RETAINED_HISTORICAL_RECORD"
  },
  "backend_anchor": {
    "matrix_anchors": "app/Http/Controllers/FleetAssets/VehicleController.php:566-822; app/Http/Controllers/FleetAssets/VehicleController.php:1133-1238",
    "claim_anchors": [
      "app/Http/Controllers/FleetAssets/VehicleController.php:566-822",
      "app/Http/Controllers/FleetAssets/VehicleController.php:1133-1238",
      "tests/Feature/FleetAssets/FleetTripIndexSitePrivacyTest.php:148-511"
    ],
    "controller_request_service_model_policy_job_event_migration": "BOUNDED_CONTROLLER_QUERY_PROJECTION_AND_REGRESSION_REVIEW",
    "application_commit": "c643c9e5eecf3b4272f55ec6d5aab4b99c3e300d"
  },
  "current_behaviour": {
    "classification": "REMEDIATED_LOCAL_MAIN_BOUNDED_VERIFIED_NOT_PUBLISHED",
    "summary": "The trip index now derives a visible operational-vehicle universe from the actor's accessible Sites, constrains rows, CSV, filters, summaries, charts, and hero projections to that universe, and redacts driver identity outside the same historical Site boundary. fleet.manage retains operational-Site visibility, archived-Site trips remain concealed, and foreign or missing vehicle filters return 404.",
    "runtime_observed": true,
    "runtime_scope": "One unique post-merge focused execution passed 5 tests / 175 assertions. The isolated 5/175 replay is not counted again; both 4/35 VehicleController regression executions are supporting only; all red executions receive reproduction-only credit."
  },
  "current_workflow": {
    "matrix_summary": "Bounded controller remediation constrains the selected GET and CSV read paths to one canonical visible-vehicle universe while preserving fleet.manage access to operational Sites.",
    "entry_prerequisites_steps_decisions_states_failure_recovery_handoff_completion": "BOUNDED_APPROVED_SITE_STANDARD_VIEWER_MANAGER_CSV_FILTER_AND_PROVENANCE_PATHS_EXECUTED; REPRESENTATIVE_BROWSER_EASE_AND_BROADER_FLEET_WORKFLOW_NOT_EXECUTED"
  },
  "ease_evidence": {
    "status": "NOT_MEASURED",
    "ten_dimensions": null,
    "completion_time": null,
    "step_count": null,
    "field_count": null,
    "decision_count": null,
    "context_switches": null,
    "uncertainty_points": null,
    "task_script": "task-scripts/cap-fleet-vehicle-register.md"
  },
  "evidence": {
    "current_remediation_receipt": "evidence/runtime/current-run-176-fleet-trip-index-site-privacy-remediation-wave-33.json",
    "independent_artifact_review": "evidence/runtime/current-run-176r-independent-fleet-trip-index-site-privacy-remediation-review-wave-33.json",
    "claim_anchors": [
      "routes/fleet-assets.php:54",
      "app/Http/Controllers/FleetAssets/VehicleController.php:566-822",
      "app/Http/Controllers/FleetAssets/VehicleController.php:1133-1238",
      "tests/Feature/FleetAssets/FleetTripIndexSitePrivacyTest.php:148-511"
    ],
    "test_commands_executed": 1,
    "tests_executed": 5,
    "assertions": 175,
    "supporting_tests": 4,
    "supporting_assertions": 35,
    "root_initial_red_failed_cases": 2,
    "delegated_recreated_red_failed_cases": 2,
    "delegated_expanded_red_failed_cases": 5,
    "browser_cells": 0,
    "database_checks": 1,
    "database_cleanup": "numeric PID test schemas absent; zero audit-owned PHP or php-cgi processes; isolated worktree and branch removed; unrelated Board Pack processes were preserved"
  },
  "problem_root_cause": "The trip index built rows, export, filters, and aggregate projections from an unscoped trip/vehicle universe, while nested driver identity was not constrained to the same historical Site boundary.",
  "impact": "Before remediation, foreign-Site trip and nested driver data could appear directly or through CSV, filters, summaries, charts, and hero aggregates; foreign or missing vehicle filters were not concealed consistently.",
  "benchmark": {
    "official_repository": null,
    "inspected_ref": null,
    "verified_behaviour": null,
    "no_credible_match_evidence": null,
    "status": "NOT_MAPPED_AND_NO_FINAL_NO_MATCH_CURRENT_AUDIT"
  },
  "benchmark_outcome": "NOT_ADJUDICATED_CURRENT_AUDIT",
  "neutral_requirements": [
    "Preserve the legitimate trip-review job while enforcing approved operational-Site visibility.",
    "Use canonical direct, home, and client Site provenance consistently for rows, nested identities, exports, filters, and aggregates.",
    "Return direct-object concealment for foreign or missing vehicle filters without copying third-party wording, assets, source, or layout."
  ],
  "better_oblivion_design": {
    "proposal": "Derive one visible operational-vehicle universe and reuse it for trip rows, nested identities, CSV, filters, summaries, charts, and hero projections.",
    "status": "IMPLEMENTED_NATIVELY_WITHOUT_BENCHMARK_OR_DESIGN_CREDIT"
  },
  "target_ease": {
    "scores": null,
    "measurable_reduction": null,
    "status": "NOT_MEASURED"
  },
  "cross_module_effects": [],
  "rbac_privacy": "One operating organisation across multiple Sites; approved Site access, exact action permissions, canonical Asset provenance, direct-object denial, and privacy are the boundaries.",
  "priority": "P1",
  "priority_status": "HISTORICAL_REMEDIATED_P1_NOT_CURRENT_PROVISIONAL_OR_FINAL_PRIORITY_COUNT",
  "effort": {
    "size": null,
    "assumptions": null,
    "status": "NOT_ESTIMATED"
  },
  "dependencies_sequence": [
    "Completed: unchanged-source two-case and expanded five-case reproductions failed at the 13a7 baseline",
    "Completed: narrow controller and permanent regression-test remediation integrated to local main at c643c9e5eecf3b4272f55ec6d5aab4b99c3e300d without publication",
    "Completed: unique post-merge 5 tests / 175 assertions plus supporting 4/35 regressions and four read-only reviews",
    "Retained non-inherited gaps: static route-feature ownership at queue index 84, adjacent Fleet routes, representative browser, benchmark, release, and completion"
  ],
  "confidence": {
    "level": "HIGH_FOR_BOUNDED_FLEET_TRIP_INDEX_SITE_PRIVACY_REMEDIATION_DISPOSITION",
    "evidence_gap": "Static route-feature ownership, controller-action bridging, representative signed-in application browser, full-suite/coverage, ease, target-specific benchmark, adjacent Fleet routes, and broader Fleet correctness remain unexecuted or unadjudicated."
  },
  "source_boundary": "Native Oblivion analysis only; no third-party source, assets, wording, or distinctive layout may be copied.",
  "interim_safeguard": "When reviewing or exporting trips, verify the selected vehicle, row, nested driver, and aggregate all remain inside approved operational Sites.",
  "acceptance_criteria": {
    "given_when_then": "Given an ordinary user with approved Site access, a fleet.manage user, visible, foreign, missing, and archived vehicles, canonical direct/home/client provenance, and visible or inaccessible historical drivers, when the trip index or CSV branch is requested, then rows, filters, summaries, day counts, top vehicles, distance trend, hero, and nested identity use one visible operational-vehicle universe; foreign or missing vehicle filters return 404; archived Sites remain concealed; and broader Fleet routes receive no inherited credit.",
    "status": "BOUNDED_MET_FOR_HISTORICAL_CLAIM_REMEDIATION_NOT_FINAL_FINDING_OR_FEATURE_COMPLETION"
  },
  "validation_plan": {
    "required": "Retain focused approved-Site, CSV, filter-concealment, fleet.manage operational-Site, archived-Site, nested-driver, and canonical direct/home/client provenance regression coverage; keep adjacent Fleet routes separate.",
    "unit": "NOT_SEPARATELY_REQUIRED_FOR_THIS_REMEDIATION",
    "feature": "EXECUTED_5_TESTS_175_ASSERTIONS_ON_LOCAL_MAIN",
    "architecture": "STATIC_MERGE_GO_PLUS_EXACT_ARTIFACT_GO",
    "e2e": "BLOCKED",
    "visual_accessibility": "BLOCKED",
    "performance_concurrency": "NOT_EXECUTED_OR_CREDITED",
    "representative_user": "NOT_EXECUTED"
  },
  "finalization_blockers": [],
  "independent_review": {
    "status": "COMPLETED_FOUR_STATIC_GO_REVIEWS_PLUS_EXACT_ARTIFACT_GO",
    "reviewer": "three delegated post-diff reviews; /root/run176_postmerge_assurance; /root/run176_producer_review",
    "disagreements": "None. Reviews preserved canonical Site provenance, direct-object concealment, supporting-test separation, nonpublication, pending static ownership, and every stated noninheritance boundary.",
    "reconciliation": "RUN-176 establishes reproduction, remediation, bounded delegated runtime, local integration, and nonpublication. RUN-176R alone authorizes one new historical-remediated record; RUN-177 alone changes the live register."
  },
  "unresolved_fields": [
    "static feature owner and controller-action bridge for queue index 84",
    "VISUAL-ID, role, Site, viewport, state, pattern baseline, and screenshot",
    "observed workflow and ten-dimension current/target ease measures",
    "target-specific benchmark or exhaustive final no-match",
    "full-suite coverage, representative signed-in application browser, adjacent Fleet routes, and broader Fleet correctness"
  ],
  "completion_credit": false,
  "credit": {
    "final_finding": false,
    "p0_p1_schema_gate": false,
    "benchmark": false,
    "browser": false,
    "ease": false,
    "pass": false,
    "completion": false
  }
}"""
)
EXPECTED_FLEET_RECORD_SHA256 = (
    "98c82f01cf8348fc4b60a4c17feea675182dc287e4c7907174b13d44af331fab"
)


def duplicate_rejecting_pairs(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        assert key not in result, f"Duplicate JSON key: {key}"
        result[key] = value
    return result


def strict_text(raw: bytes, label: str) -> None:
    assert not raw.startswith(b"\xef\xbb\xbf"), f"BOM not allowed: {label}"
    assert b"\r" not in raw, f"CR not allowed: {label}"
    assert raw.endswith(b"\n"), f"Final LF required: {label}"
    for number, line in enumerate(raw.splitlines(), start=1):
        assert line == line.rstrip(b" \t"), f"Trailing whitespace: {label}:{number}"


def strict_json_bytes(raw: bytes, label: str) -> dict[str, Any]:
    strict_text(raw, label)
    value = json.loads(
        raw.decode("utf-8"), object_pairs_hook=duplicate_rejecting_pairs
    )
    assert isinstance(value, dict), f"JSON object required: {label}"
    expected = (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode(
        "utf-8"
    )
    assert raw == expected, f"Exact pretty-JSON round trip failed: {label}"
    return value


def read_json(relative: str) -> dict[str, Any]:
    return strict_json_bytes((AUDIT / relative).read_bytes(), relative)


def git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args],
        cwd=ROOT,
        check=True,
        capture_output=True,
        text=True,
        encoding="utf-8",
    )
    return result.stdout.rstrip()


def git_bytes(revision: str, repository_relative: str) -> bytes:
    return subprocess.run(
        ["git", "show", f"{revision}:{repository_relative}"],
        cwd=ROOT,
        check=True,
        capture_output=True,
    ).stdout


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def canonical_sha256(value: Any) -> str:
    return sha256(
        json.dumps(
            value,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        ).encode("utf-8")
    )


def file_record(relative: str) -> dict[str, Any]:
    raw = (AUDIT / relative).read_bytes()
    strict_text(raw, relative)
    return {
        "path": relative,
        "sha256": sha256(raw),
        "git_blob_id": git("hash-object", "--", f"{PREFIX}/{relative}"),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


def canonical_record_hashes(payload: dict[str, Any]) -> dict[str, str]:
    return {row["id"]: canonical_sha256(row) for row in payload["records"]}


def verify_self_seal(payload: dict[str, Any], expected: str) -> None:
    without_seal = dict(payload)
    actual = without_seal.pop("receipt_self_seal_sha256")
    assert actual == expected
    assert canonical_sha256(without_seal) == expected


def validate_repository() -> None:
    assert git("rev-parse", "HEAD") == REPORTING_INPUT
    assert git("rev-parse", "main") == REPORTING_INPUT
    assert git("show", "-s", "--format=%T", "HEAD") == REPORTING_INPUT_TREE
    assert git("show", "-s", "--format=%P", "HEAD").split() == REPORTING_INPUT_PARENTS
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "origin/main") == ORIGIN_MAIN
    assert git("rev-list", "--left-right", "--count", "origin/main...main") == "0\t10"
    assert git("diff", "--cached", "--name-only") == ""

    expected_without_receipt = sorted(
        [f" M {PREFIX}/{path}" for path in REPORTING_SURFACES]
        + [f"?? {PREFIX}/{SCRIPT_REL}"]
    )
    expected_with_receipt = sorted(
        expected_without_receipt + [f"?? {PREFIX}/{OUTPUT_REL}"]
    )
    dirty = sorted(
        line
        for line in git(
            "status", "--porcelain=v1", "--untracked-files=all"
        ).splitlines()
        if line
    )
    assert dirty in (expected_without_receipt, expected_with_receipt), dirty
    assert git("diff", "--check") == ""
    assert sorted(git("diff", "--name-only", "HEAD").splitlines()) == sorted(
        f"{PREFIX}/{path}" for path in REPORTING_SURFACES
    )
    assert git(
        "diff", "--name-only", "HEAD", "--", *PRESERVED_APPLICATION_PATHS
    ) == ""

    dashboard_relative = f"{PREFIX}/audit-dashboard.html"
    current_dashboard = (AUDIT / "audit-dashboard.html").read_bytes()
    assert current_dashboard == git_bytes(REPORTING_INPUT, dashboard_relative)
    assert sha256(current_dashboard) == RUN_175_DASHBOARD_SHA256


def validate_findings() -> tuple[dict[str, str], bytes]:
    relative = f"{PREFIX}/findings.json"
    baseline_raw = git_bytes(REPORTING_INPUT, relative)
    assert sha256(baseline_raw) == BASELINE_FINDINGS_SHA256
    baseline = strict_json_bytes(baseline_raw, f"{REPORTING_INPUT}:findings.json")
    findings = read_json("findings.json")
    baseline_hashes = canonical_record_hashes(baseline)
    current_hashes = canonical_record_hashes(findings)

    assert baseline["audit_status"] == (
        "EIGHT_PROVISIONAL_TWO_HISTORICAL_ALREADY_FIXED_TWO_HISTORICAL_"
        "REMEDIATED_ZERO_FINAL_FINDING_CREDIT"
    )
    assert findings["audit_status"] == (
        "EIGHT_PROVISIONAL_TWO_HISTORICAL_ALREADY_FIXED_THREE_HISTORICAL_"
        "REMEDIATED_ZERO_FINAL_FINDING_CREDIT"
    )
    assert baseline_hashes == EXPECTED_BASELINE_RECORD_HASHES
    assert set(current_hashes) == {
        *EXPECTED_BASELINE_RECORD_HASHES,
        "FLEET-TRIP-INDEX-SITE-PRIVACY-01",
    }
    for finding_id, expected in EXPECTED_BASELINE_RECORD_HASHES.items():
        assert current_hashes[finding_id] == expected
    records = {row["id"]: row for row in findings["records"]}
    fleet = records["FLEET-TRIP-INDEX-SITE-PRIVACY-01"]
    assert fleet == EXPECTED_FLEET_RECORD
    assert canonical_sha256(fleet) == EXPECTED_FLEET_RECORD_SHA256

    counts = findings["counts"]
    assert {
        key: counts[key]
        for key in (
            "retained_claim_records",
            "provisional_source_claims",
            "provisional_P1",
            "historical_already_fixed",
            "historical_remediated",
            "bounded_disposition_tests_passed",
            "bounded_disposition_assertions",
            "final_P0",
            "final_P1",
            "complete_prompt_finding_schema",
            "browser_observed",
            "benchmark_mapped",
            "final_no_match",
            "benchmark_unresolved",
            "static_source_feature_ownership_records",
            "static_source_feature_ownership_route_records",
            "static_source_feature_ownership_page_records",
            "static_source_feature_ownership_distinct_feature_ids",
            "static_source_feature_ownership_distinct_H_feature_ids",
            "static_source_feature_ownership_distinct_D_feature_ids",
            "static_controller_action_bridges",
            "bounded_static_source_ownership_percent",
            "bounded_static_source_residual_records",
            "direct_exact_queue_records",
            "direct_exact_queue_reviewed",
            "direct_exact_queue_pending_unreviewed",
            "direct_exact_queue_owned",
            "direct_exact_queue_shared",
            "direct_exact_queue_alias",
            "direct_exact_queue_dead_or_noncanonical",
            "direct_exact_queue_evidence_gap",
            "direct_exact_queue_without_ownership",
        )
    } == {
        "retained_claim_records": 13,
        "provisional_source_claims": 8,
        "provisional_P1": 8,
        "historical_already_fixed": 2,
        "historical_remediated": 3,
        "bounded_disposition_tests_passed": 88,
        "bounded_disposition_assertions": 1764,
        "final_P0": 0,
        "final_P1": 0,
        "complete_prompt_finding_schema": 0,
        "browser_observed": 0,
        "benchmark_mapped": 2,
        "final_no_match": 0,
        "benchmark_unresolved": 338,
        "static_source_feature_ownership_records": 665,
        "static_source_feature_ownership_route_records": 308,
        "static_source_feature_ownership_page_records": 357,
        "static_source_feature_ownership_distinct_feature_ids": 256,
        "static_source_feature_ownership_distinct_H_feature_ids": 234,
        "static_source_feature_ownership_distinct_D_feature_ids": 22,
        "static_controller_action_bridges": 96,
        "bounded_static_source_ownership_percent": "16.925426",
        "bounded_static_source_residual_records": 3264,
        "direct_exact_queue_records": 507,
        "direct_exact_queue_reviewed": 119,
        "direct_exact_queue_pending_unreviewed": 388,
        "direct_exact_queue_owned": 97,
        "direct_exact_queue_shared": 10,
        "direct_exact_queue_alias": 5,
        "direct_exact_queue_dead_or_noncanonical": 0,
        "direct_exact_queue_evidence_gap": 7,
        "direct_exact_queue_without_ownership": 410,
    }
    assert counts["fleet_trip_index_site_privacy_focused_tests"] == 5
    assert counts["fleet_trip_index_site_privacy_focused_assertions"] == 175
    assert counts["fleet_trip_index_site_privacy_supporting_tests"] == 4
    assert counts["fleet_trip_index_site_privacy_supporting_assertions"] == 35
    basis = counts["bounded_disposition_sum_basis"]
    assert "5/175 post-merge focused FLEET-TRIP-INDEX-SITE-PRIVACY" in basis
    for excluded in (
        "root and delegated Fleet red execution",
        "isolated 5/175 Fleet replay",
        "4/35 VehicleController regressions",
    ):
        assert excluded in basis

    assert len(records) == 13
    assert sum(
        row["record_status"] == "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING"
        for row in records.values()
    ) == 8
    assert sum(
        row["record_status"]
        == "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING"
        for row in records.values()
    ) == 2
    assert sum(
        row["record_status"]
        == "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
        for row in records.values()
    ) == 3
    assert all(row["completion_credit"] is False for row in records.values())
    assert all(
        all(value is False for value in row["credit"].values())
        for row in records.values()
    )

    pins = findings["pins"]
    assert pins["fleet_trip_index_site_privacy_baseline_commit"] == FLEET_BASE
    assert pins["fleet_trip_index_site_privacy_baseline_tree"] == FLEET_BASE_TREE
    assert pins["fleet_trip_index_site_privacy_fix_commit"] == FLEET_FIX
    assert pins["fleet_trip_index_site_privacy_fix_tree"] == FLEET_FIX_TREE
    assert pins["fleet_trip_index_site_privacy_local_main_merge_commit"] == FLEET_MERGE
    assert pins["fleet_trip_index_site_privacy_local_main_tree"] == FLEET_MERGE_TREE
    assert pins["fleet_trip_index_site_privacy_origin_main_observed"] == ORIGIN_MAIN
    assert pins["run_176_fleet_trip_index_site_privacy_remediation_sha256"] == (
        RUN_176_RECEIPT_SHA256
    )
    assert pins["run_176r_independent_artifact_review_sha256"] == (
        RUN_176R_RECEIPT_SHA256
    )
    assert pins["current_matrix_sha256"] == (
        "3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0"
    )
    assert pins["current_benchmark_register_sha256"] == (
        "5a3898855f1ffffb1a493496a57f6532bdc464552e4577c9e7c97f83c9793884"
    )

    reconciliation = findings["reconciliation"]
    assert reconciliation["retained_record_count"] == 13
    assert reconciliation["current_provisional_count"] == 8
    assert reconciliation["historical_already_fixed_count"] == 2
    assert reconciliation["historical_remediated_count"] == 3
    assert reconciliation["final_ids_cross_file_reconciled"] is False
    reason = reconciliation["reason"]
    assert "FLEET-TRIP-INDEX-SITE-PRIVACY-01" in reason
    assert "PENDING_FRESH_SEMANTIC_REVIEW" in reason
    assert "queue index 84" in reason
    assert "not published" in reason
    return current_hashes, baseline_raw


def validate_run_175_lineage() -> None:
    assert file_record(RUN_175_GENERATOR_REL)["sha256"] == RUN_175_GENERATOR_SHA256
    assert file_record(RUN_175_RECEIPT_REL)["sha256"] == RUN_175_RECEIPT_SHA256
    receipt = read_json(RUN_175_RECEIPT_REL)
    verify_self_seal(receipt, RUN_175_RECEIPT_SEAL)
    assert receipt["run_id"] == "RUN-175-AUDIT-DASHBOARD-VERIFICATION-WAVE-32"
    assert receipt["pins"]["run_175_dashboard"]["sha256"] == RUN_175_DASHBOARD_SHA256
    verification = receipt["verification"]
    assert verification["viewports_verified"] == 4
    assert verification["screens_visually_go"] is True
    assert verification["navigation_clicks_required"] == 10
    assert verification["navigation_clicks_passed"] == 10
    assert verification["console_error_entries"] == 0
    assert verification["uncaught_page_error_entries"] == 0
    assert verification["missing_hash_targets"] == []
    assert verification["post_materialization_local_resource_failures"] == []
    assert verification["hash_bearing_link_failures"] == []
    assert {
        key for key, value in receipt["credit_boundary"].items() if value
    } == {"audit_dashboard_run_175_med_pin_correction", "exact_audit_dashboard_artifact"}
    assert receipt["artifact_completion_test_met"] is True
    assert receipt["audit_completion_test_met"] is False


def validate_run_176_lineage() -> tuple[dict[str, Any], dict[str, Any]]:
    assert file_record(RUN_176_GENERATOR_REL)["sha256"] == RUN_176_GENERATOR_SHA256
    assert file_record(RUN_176_RECEIPT_REL)["sha256"] == RUN_176_RECEIPT_SHA256
    assert file_record(RUN_176R_GENERATOR_REL)["sha256"] == RUN_176R_GENERATOR_SHA256
    assert file_record(RUN_176R_RECEIPT_REL)["sha256"] == RUN_176R_RECEIPT_SHA256
    run_176 = read_json(RUN_176_RECEIPT_REL)
    run_176r = read_json(RUN_176R_RECEIPT_REL)
    verify_self_seal(run_176, RUN_176_RECEIPT_SEAL)
    verify_self_seal(run_176r, RUN_176R_RECEIPT_SEAL)

    assert run_176["run_id"] == (
        "RUN-176-FLEET-TRIP-INDEX-SITE-PRIVACY-01-REMEDIATION-WAVE-33"
    )
    pins = run_176["pins"]
    assert {
        "application_baseline_commit": pins["application_baseline_commit"],
        "application_baseline_tree": pins["application_baseline_tree"],
        "fix_commit": pins["fix_commit"],
        "fix_tree": pins["fix_tree"],
        "local_main_merge_commit": pins["local_main_merge_commit"],
        "local_main_tree": pins["local_main_tree"],
        "origin_main_observed": pins["origin_main_observed"],
        "stable_patch_id": pins["stable_patch_id"],
    } == {
        "application_baseline_commit": FLEET_BASE,
        "application_baseline_tree": FLEET_BASE_TREE,
        "fix_commit": FLEET_FIX,
        "fix_tree": FLEET_FIX_TREE,
        "local_main_merge_commit": FLEET_MERGE,
        "local_main_tree": FLEET_MERGE_TREE,
        "origin_main_observed": ORIGIN_MAIN,
        "stable_patch_id": FLEET_PATCH_ID,
    }
    issue = run_176["issue_first_disposition"]
    assert issue["finding_id"] == "FLEET-TRIP-INDEX-SITE-PRIVACY-01"
    assert issue["candidate_feature_id"] == "CAP-FLEET-VEHICLE-REGISTER"
    assert issue["feature_identity_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
    assert issue["verdict"] == "REPRODUCED_AND_REMEDIATED_LOCAL_MAIN_NOT_PUBLISHED"
    accounting = run_176["delegated_runtime_execution"]["unique_bounded_accounting"]
    assert accounting == {
        "prior": {"tests": 83, "assertions": 1589},
        "increment": {"tests": 5, "assertions": 175},
        "resulting": {"tests": 88, "assertions": 1764},
    }
    ownership = run_176["static_ownership_boundary"]
    assert {
        "owner_records": ownership["owner_records"],
        "route_owners": ownership["route_owners"],
        "page_owners": ownership["page_owners"],
        "action_bridges": ownership["action_bridges"],
        "queue_reviewed": ownership["queue_reviewed"],
        "queue_pending": ownership["queue_pending"],
        "queue_owned": ownership["queue_owned"],
        "queue_without_ownership": ownership["queue_without_ownership"],
        "next_zero_based_index": ownership["next_zero_based_index"],
        "next_queue_id": ownership["next_queue_id"],
        "next_route_record_id": ownership["next_route_record_id"],
        "ownership_status": ownership["ownership_status"],
    } == {
        "owner_records": 665,
        "route_owners": 308,
        "page_owners": 357,
        "action_bridges": 96,
        "queue_reviewed": 119,
        "queue_pending": 388,
        "queue_owned": 97,
        "queue_without_ownership": 410,
        "next_zero_based_index": 84,
        "next_queue_id": "RUN090-ROUTE-0085",
        "next_route_record_id": "RUN077-ROUTE-0693",
        "ownership_status": "PENDING_FRESH_SEMANTIC_REVIEW",
    }
    assert run_176["benchmark_boundary"] == {
        "mapped": 2,
        "total": 340,
        "final_no_match_or_NCM": 0,
        "unresolved": 338,
        "changed_by_run_176": False,
    }

    assert run_176r["run_id"] == (
        "RUN-176R-INDEPENDENT-FLEET-TRIP-INDEX-SITE-PRIVACY-01-REMEDIATION-"
        "REVIEW-WAVE-33"
    )
    decision = run_176r["decision"]
    assert decision["verdict"] == "GO"
    assert decision["blocking_discrepancies"] == 0
    assert decision["new_historical_remediated_record_reporting_authorized"] is True
    assert decision["authorized_live_reporting_run"] == "RUN-177"
    assert decision["authorized_finding_id"] == "FLEET-TRIP-INDEX-SITE-PRIVACY-01"
    assert decision["authorized_candidate_feature_id"] == "CAP-FLEET-VEHICLE-REGISTER"
    assert decision["authorized_resulting_lineage"] == {
        "retained_claim_records": 13,
        "current_provisional_source_claims": 8,
        "historical_already_fixed_records": 2,
        "historical_remediated_records": 3,
        "final_P0": 0,
        "final_P1": 0,
    }
    assert decision["authorized_unique_bounded_disposition_increment"] == {
        "prior_tests": 83,
        "prior_assertions": 1589,
        "tests": 5,
        "assertions": 175,
        "resulting_tests": 88,
        "resulting_assertions": 1764,
        "post_merge_focused_counted_once": True,
        "root_or_delegated_red_counted": False,
        "isolated_replay_counted_again": False,
        "supporting_runs_counted": False,
    }
    assert decision["static_ownership_remains_pending"] == {
        "ownership_status": "PENDING_FRESH_SEMANTIC_REVIEW",
        "next_zero_based_index": 84,
        "next_queue_id": "RUN090-ROUTE-0085",
        "next_route_record_id": "RUN077-ROUTE-0693",
        "route_owner_authorized": False,
        "controller_action_bridge_authorized": False,
        "queue_advance_authorized": False,
    }
    assert decision["live_reporting_changed_by_run_176r"] is False
    assert decision["run_177_required"] is True
    assert decision["run_178_fresh_dashboard_verification_required"] is True
    assert {
        key for key, value in run_176r["credit_boundary"].items() if value
    } == {"independent_exact_artifact_review_for_new_historical_remediated_reporting"}
    assert all(value is False for value in run_176r["completion_boundary"].values())
    return run_176, run_176r


def validate_reporting_text_and_builder() -> list[dict[str, Any]]:
    required = {
        "00-executive-summary.md": (
            "RUN-176–177 Fleet trip index Site privacy remediation checkpoint",
            "13 retained identities",
            "88 tests / 1,764 assertions",
            "PENDING_FRESH_SEMANTIC_REVIEW",
            "local and not published",
            "fresh RUN-178 verification",
        ),
        "01-repository-module-map.md": (
            "FLEET-TRIP-INDEX-SITE-PRIVACY-01",
            "current 88/1,764 non-overlapping bounded-disposition total",
            "13 retained identities",
            "8 current provisional P1 + 2 historical already-fixed + 3 historical remediated",
            "queue index 84",
            "requires fresh RUN-178 verification",
        ),
        "07-module-findings.md": (
            "8 current provisional P1 claims + 2 historical already-fixed records + 3 historical remediated records",
            "### FLEET-TRIP-INDEX-SITE-PRIVACY-01",
            "unique post-merge local-main run passes 5 tests / 175 assertions",
            "RUN-176R authorizes one new historical-remediated record; RUN-177 alone changes the live register",
            "PENDING_FRESH_SEMANTIC_REVIEW",
            "publication and release remain false",
        ),
        "11-prioritised-roadmap.md": (
            "13 retained records",
            "FLEET-TRIP-INDEX-SITE-PRIVACY-01",
            "one unique post-merge 5-test / 175-assertion execution",
            "RUN-176R independently returns GO and authorizes RUN-177 reporting",
            "queue index 84 remains pending",
            "application and audit publication",
        ),
        "12-native-build-and-do-not-copy-register.md": (
            "FLEET-TRIP-INDEX-SITE-PRIVACY-01",
            "1 bounded native remediation; 0 benchmark-derived design credit",
            "historical issue remediated on local main",
            "PENDING_FRESH_SEMANTIC_REVIEW",
            "noninheritance and nonpublication boundaries",
            "For the 8 active claims",
        ),
        "13-unresolved-questions-and-evidence-gaps.md": (
            "RUN-177 changes reporting sources and the dashboard builder while preserving the RUN-175 HTML bytes",
            "88 / 1,764",
            "13 retained records: 8 current provisional + 2 historical already-fixed + 3 historical remediated",
            "queue index 84 remains PENDING_FRESH_SEMANTIC_REVIEW",
            "The Fleet merge is not published",
            "fresh RUN-178",
        ),
    }
    for relative, phrases in required.items():
        raw = (AUDIT / relative).read_bytes()
        strict_text(raw, relative)
        text = raw.decode("utf-8")
        for phrase in phrases:
            assert phrase in text, f"Missing reporting phrase in {relative}: {phrase}"

    combined = "\n".join(
        (AUDIT / relative).read_text(encoding="utf-8") for relative in PROSE_SURFACES
    )
    for prohibited in (
        "RUN-176/R closes FLEET-TRIP-INDEX-SITE-PRIVACY-01",
        "RUN-176/R retires FLEET-TRIP-INDEX-SITE-PRIVACY-01",
        "FLEET-TRIP-INDEX-SITE-PRIVACY-01 final finding",
        "Fleet application merge is published",
        "Fleet remediation completes Fleet Assets",
        "queue index 84 is owned",
        "CAP-FLEET-VEHICLE-REGISTER owns fleet-assets.trips.index",
    ):
        assert prohibited not in combined

    builder_path = AUDIT / "generators/build-current-audit-dashboard.py"
    builder_raw = builder_path.read_bytes()
    strict_text(builder_raw, "generators/build-current-audit-dashboard.py")
    builder = builder_raw.decode("utf-8")
    ast.parse(builder, filename=str(builder_path))
    assert (
        'run_177_reporting = read_json_strict("evidence/source/current-run-177-'
        'fleet-trip-index-site-privacy-remediation-reporting-wave-33.json")'
    ) in builder
    assert "run_177_template_rewrites" in builder
    assert "RUN-177: Fleet trip privacy historical-remediated record added" in builder
    assert "Fresh RUN-178 audit-dashboard verification required" in builder
    assert "materialize-run-178-audit-dashboard-verification-wave-33.py" in builder
    assert "current-audit-dashboard-verification-run-178-wave-33.json" in builder
    assert (
        'read_json_strict("evidence/browser/current-audit-dashboard-verification-run-'
        '178-wave-33.json")'
    ) not in builder
    return [file_record(path) for path in REPORTING_SURFACES]


def build_receipt(
    current_hashes: dict[str, str],
    baseline_findings_raw: bytes,
    reporting_manifest: list[dict[str, Any]],
) -> dict[str, Any]:
    completion = {
        "framework_route_reachability_complete": False,
        "semantic_assurance_complete": False,
        "execution_complete": False,
        "coverage_complete": False,
        "benchmark_complete": False,
        "pass_8_complete": False,
        "final_reconciliation_complete": False,
        "no_live_agent_gate_complete": False,
        "full_crosswalk_complete": False,
        "gate_4_complete": False,
        "audit_complete": False,
    }
    credit = {
        "live_findings_register_and_reporting_status": True,
        "application_source_or_tests": False,
        "application_runtime_reexecution": False,
        "application_browser": False,
        "static_route_feature_ownership": False,
        "static_controller_action_bridge": False,
        "queue_advance": False,
        "benchmark_mapping": False,
        "final_no_match_or_NCM": False,
        "ease": False,
        "pass": False,
        "release": False,
        "publication": False,
        "final_finding": False,
        "completion": False,
        "audit_complete": False,
    }
    receipt: dict[str, Any] = {
        "schema_version": (
            "run-177-fleet-trip-index-site-privacy-remediation-reporting-wave-33-v1"
        ),
        "run_id": RUN_ID,
        "status": STATUS,
        "materialized_on": "2026-08-30",
        "architecture_rule": {
            "operating_organisations": 1,
            "multiple_sites": True,
            "multi_tenant": False,
            "authorization_boundary": (
                "approved Site access, exact roles and permissions, canonical Asset "
                "provenance, direct-object denial, and privacy"
            ),
        },
        "pins": {
            "reporting_input_commit": REPORTING_INPUT,
            "reporting_input_tree": REPORTING_INPUT_TREE,
            "reporting_input_parents": REPORTING_INPUT_PARENTS,
            "audit_release_commit": AUDIT_RELEASE,
            "audit_release_tree": AUDIT_RELEASE_TREE,
            "board_fix_commit": BOARD_FIX,
            "board_fix_tree": BOARD_FIX_TREE,
            "origin_main_observed": ORIGIN_MAIN,
            "local_main_ahead": 10,
            "local_main_behind": 0,
            "fleet_application_baseline_commit": FLEET_BASE,
            "fleet_application_baseline_tree": FLEET_BASE_TREE,
            "fleet_fix_commit": FLEET_FIX,
            "fleet_fix_tree": FLEET_FIX_TREE,
            "fleet_local_main_merge_commit": FLEET_MERGE,
            "fleet_local_main_merge_tree": FLEET_MERGE_TREE,
            "fleet_stable_patch_id": FLEET_PATCH_ID,
            "run_175_generator": file_record(RUN_175_GENERATOR_REL),
            "run_175_receipt": {
                **file_record(RUN_175_RECEIPT_REL),
                "receipt_self_seal_sha256": RUN_175_RECEIPT_SEAL,
            },
            "run_176_generator": file_record(RUN_176_GENERATOR_REL),
            "run_176_receipt": {
                **file_record(RUN_176_RECEIPT_REL),
                "receipt_self_seal_sha256": RUN_176_RECEIPT_SEAL,
            },
            "run_176r_generator": file_record(RUN_176R_GENERATOR_REL),
            "run_176r_receipt": {
                **file_record(RUN_176R_RECEIPT_REL),
                "receipt_self_seal_sha256": RUN_176R_RECEIPT_SEAL,
            },
            "reporting_materializer": file_record(SCRIPT_REL),
            "baseline_findings": {
                "sha256": sha256(baseline_findings_raw),
                "record_count": 12,
            },
            "current_findings": file_record("findings.json"),
            "current_fleet_record_canonical_sha256": current_hashes[
                "FLEET-TRIP-INDEX-SITE-PRIVACY-01"
            ],
            "dashboard_builder": file_record(
                "generators/build-current-audit-dashboard.py"
            ),
            "unchanged_run_175_dashboard": file_record("audit-dashboard.html"),
        },
        "lineage_roles": {
            "run_175": "verifies only the exact now-superseded RUN-174 dashboard",
            "run_176": (
                "establishes Fleet reproduction, narrow remediation, bounded runtime, "
                "local-main integration, nonpublication, and zero static ownership"
            ),
            "run_176r": "independently authorizes one new historical record only",
            "run_177": "alone adds that record and changes live reporting",
            "run_178": "required fresh dashboard rebuild and verification",
        },
        "reporting_transition": {
            "finding_id": "FLEET-TRIP-INDEX-SITE-PRIVACY-01",
            "candidate_feature_id": "CAP-FLEET-VEHICLE-REGISTER",
            "feature_identity_status": "PENDING_FRESH_SEMANTIC_REVIEW",
            "authorized_by_run_176r": True,
            "transition_kind": "NEW_HISTORICAL_REMEDIATED_RECORD",
            "status_before": "ABSENT",
            "status_after": (
                "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
            ),
            "counts_before": {
                "retained_claim_records": 12,
                "provisional_source_claims": 8,
                "historical_already_fixed": 2,
                "historical_remediated": 2,
                "final_P0": 0,
                "final_P1": 0,
            },
            "counts_after": {
                "retained_claim_records": 13,
                "provisional_source_claims": 8,
                "historical_already_fixed": 2,
                "historical_remediated": 3,
                "final_P0": 0,
                "final_P1": 0,
            },
            "new_target_record_canonical_sha256": (
                EXPECTED_FLEET_RECORD_SHA256
            ),
            "unchanged_preexisting_record_count": 12,
            "unchanged_preexisting_record_hashes": {
                finding_id: current_hashes[finding_id]
                for finding_id in sorted(EXPECTED_BASELINE_RECORD_HASHES)
            },
            "reporting_surface_paths": REPORTING_SURFACES,
        },
        "bounded_execution_accounting": {
            "prior_unique_total": {"tests": 83, "assertions": 1589},
            "run_176_post_merge_unique_increment": {
                "tests": 5,
                "assertions": 175,
                "counted_once": True,
            },
            "unique_total": {"tests": 88, "assertions": 1764},
            "excluded_from_unique_total": {
                "root_initial_red": {
                    "failed": 2,
                    "assertions_reported": 19,
                },
                "delegated_recreated_red": {
                    "failed": 2,
                    "assertions_reported": 19,
                },
                "delegated_expanded_red": {
                    "failed": 5,
                    "assertions_reported": 55,
                },
                "isolated_green_replay": {"tests": 5, "assertions": 175},
                "supporting_vehicle_controller_regressions": {
                    "tests": 4,
                    "assertions": 35,
                },
            },
        },
        "reporting_manifest": reporting_manifest,
        "preservation_boundary": {
            "exact_modified_reporting_surface_count": 8,
            "all_other_tracked_and_untracked_paths_untouched": True,
            "fleet_application_paths_unchanged_by_run_177": True,
            "board_application_paths_unchanged_by_run_177": True,
            "dashboard_byte_identical_to_reporting_input": True,
            "dashboard_sha256": RUN_175_DASHBOARD_SHA256,
            "static_ownership": {
                "owners": 665,
                "routes": 308,
                "pages": 357,
                "controller_action_bridges": 96,
                "ownership_status": "PENDING_FRESH_SEMANTIC_REVIEW",
            },
            "queue": {
                "next_zero_based_index": 84,
                "next_queue_id": "RUN090-ROUTE-0085",
                "next_route_record_id": "RUN077-ROUTE-0693",
                "reviewed": 119,
                "pending": 388,
                "owned": 97,
                "without_ownership": 410,
                "advanced_by_run_177": False,
            },
            "benchmark": {
                "mapped": 2,
                "total": 340,
                "final_no_match_or_NCM": 0,
                "unresolved": 338,
            },
        },
        "publication_boundary": {
            "origin_main": ORIGIN_MAIN,
            "fleet_application_published": False,
            "run_176_to_177_published": False,
            "publication_authorized": False,
            "materializer_performed_push_or_publication": False,
        },
        "dashboard_forward_gate": {
            "required_run": "RUN-178",
            "dashboard_html_changed_by_run_177": False,
            "unchanged_dashboard_sha256": RUN_175_DASHBOARD_SHA256,
            "fresh_rebuild_required": True,
            "fresh_verification_required": True,
            "future_receipt_link_is_unhashed_to_avoid_cycle": True,
        },
        "noninheritance_boundary": {
            "root_or_delegated_red_recredited": False,
            "isolated_green_replay_recredited": False,
            "supporting_regressions_recredited": False,
            "static_route_feature_ownership": False,
            "static_controller_action_bridge": False,
            "static_page_or_frontend_ownership": False,
            "queue_advance": False,
            "playback_toggle_personal_fuel_or_adjacent_route_correctness": False,
            "security_devices_or_user_site_access_service_correctness": False,
            "broader_fleet_permission_privacy_or_direct_object_correctness": False,
            "application_browser_or_ease": False,
            "benchmark_mapping_or_final_no_match_NCM": False,
            "final_finding_or_feature_module_pass_release_completion": False,
        },
        "credit_boundary": credit,
        "completion_boundary": completion,
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [OUTPUT_REL],
    }
    assert {key for key, value in credit.items() if value} == {
        "live_findings_register_and_reporting_status"
    }
    assert all(value is False for value in completion.values())
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    return receipt


def write_receipt(receipt: dict[str, Any]) -> bytes:
    output_bytes = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode(
        "utf-8"
    )
    temporary = OUTPUT.with_name(f".{OUTPUT.name}.tmp-run177")
    assert not temporary.exists(), f"Refusing stale temporary file: {temporary}"
    try:
        with temporary.open("xb") as handle:
            handle.write(output_bytes)
            handle.flush()
            os.fsync(handle.fileno())
        assert temporary.read_bytes() == output_bytes
        os.replace(temporary, OUTPUT)
    finally:
        if temporary.exists():
            temporary.unlink()
    assert OUTPUT.read_bytes() == output_bytes
    written = strict_json_bytes(output_bytes, OUTPUT_REL)
    without_seal = dict(written)
    seal = without_seal.pop("receipt_self_seal_sha256")
    assert canonical_sha256(without_seal) == seal
    return output_bytes


def main() -> None:
    validate_repository()
    current_hashes, baseline_raw = validate_findings()
    validate_run_175_lineage()
    validate_run_176_lineage()
    reporting_manifest = validate_reporting_text_and_builder()
    receipt = build_receipt(current_hashes, baseline_raw, reporting_manifest)
    output_bytes = write_receipt(receipt)
    assert reporting_manifest == [file_record(path) for path in REPORTING_SURFACES]
    validate_repository()
    print(
        json.dumps(
            {
                "run_id": RUN_ID,
                "status": STATUS,
                "materializer_sha256": file_record(SCRIPT_REL)["sha256"],
                "receipt_sha256": sha256(output_bytes),
                "receipt_self_seal_sha256": receipt["receipt_self_seal_sha256"],
                "reporting_surfaces": len(REPORTING_SURFACES),
                "lineage": "13=8+2+3",
                "unique_bounded_execution": "88/1764",
                "static_ownership": "PENDING_FRESH_SEMANTIC_REVIEW",
                "dashboard_sha256_unchanged": RUN_175_DASHBOARD_SHA256,
                "published": False,
                "audit_complete": False,
            },
            ensure_ascii=False,
            sort_keys=True,
        )
    )


if __name__ == "__main__":
    main()
