#!/usr/bin/env python3
"""Build the current audit progress dashboard from normalized evidence JSON."""

from __future__ import annotations

import csv
import hashlib
import html
import json
import os
import subprocess
from collections import Counter
from pathlib import Path
from string import Template


if not __debug__:
    raise SystemExit("Refusing optimized Python: dashboard validation requires assertions")


AUDIT_DIR = Path(__file__).resolve().parents[1]
REPO_ROOT = AUDIT_DIR.parents[2]
HISTORICAL_RUN_080_MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
HISTORICAL_RUN_070_REGISTER_SHA256 = "cc493cd1807e62a9ffa27192c658400e697391b7a0baa3f0014628145c6b7b91"
CURRENT_RUN_145_MATRIX_SHA256 = "3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0"
CURRENT_RUN_145_REGISTER_SHA256 = "5a3898855f1ffffb1a493496a57f6532bdc464552e4577c9e7c97f83c9793884"
CURRENT_RUN_145_RECEIPT_SHA256 = "8306a8aefe0a490ebf206d0c4716d92930326988f19e0ed495a3c2d0002c7cf9"
CURRENT_RUN_156R_COMMIT = "81abe37faa126f98ce47c7ca90cf569fe9c43c0d"
RUN_167_REPORTING_COMMIT = "66fa21bfa3a59205fec9a8a756dc211a8510e419"
RUN_168_VERIFIED_DASHBOARD_COMMIT = "e488bd3edcda0f154f87e8bbed972f14db409b82"
RUN_184_REPORTING_COMMIT = "15b2c988f4bb7f737727cc777ab32ad771c4be06"
CURRENT_RUN_146_MUTATED_PATHS = {
    "00-executive-summary.md",
    "03-feature-to-benchmark-matrix.csv",
    "06-open-source-benchmark-register.csv",
    "07-module-findings.md",
    "12-native-build-and-do-not-copy-register.md",
    "13-unresolved-questions-and-evidence-gaps.md",
    "findings.json",
}


def read_json(relative: str) -> dict:
    return json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))


def read_json_strict(relative: str) -> dict:
    def reject_duplicate_keys(pairs: list[tuple[str, object]]) -> dict:
        value = {}
        for key, item in pairs:
            assert key not in value, (relative, key)
            value[key] = item
        return value

    value = json.loads(
        (AUDIT_DIR / relative).read_text(encoding="utf-8"),
        object_pairs_hook=reject_duplicate_keys,
    )
    assert isinstance(value, dict)
    return value


def sha256_file(relative: str) -> str:
    path = AUDIT_DIR / relative
    assert path.is_file()
    return hashlib.sha256(path.read_bytes()).hexdigest()


def git_blob_id(relative: str) -> str:
    payload = (AUDIT_DIR / relative).read_bytes()
    return git_blob_id_bytes(payload)


def git_blob_id_bytes(payload: bytes) -> str:
    return hashlib.sha1(
        f"blob {len(payload)}\0".encode("ascii") + payload
    ).hexdigest()


def git_file_at_commit(commit: str, relative: str) -> bytes:
    repo_relative = (AUDIT_DIR / relative).relative_to(REPO_ROOT).as_posix()
    result = subprocess.run(
        ["git", "show", f"{commit}:{repo_relative}"],
        cwd=REPO_ROOT,
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    return result.stdout


def text_file_metrics(relative: str) -> tuple[int, int]:
    payload = (AUDIT_DIR / relative).read_bytes()
    assert payload.endswith(b"\n") and b"\r\n" not in payload
    assert not payload.startswith(b"\xef\xbb\xbf")
    return len(payload), payload.count(b"\n")


def canonical_sha256(value: object) -> str:
    return hashlib.sha256(
        json.dumps(
            value, ensure_ascii=False, sort_keys=True, separators=(",", ":")
        ).encode("utf-8")
    ).hexdigest()


wave1 = read_json("evidence/source/current-feature-discovery-wave-01.json")
wave2 = read_json("evidence/source/current-feature-discovery-wave-02.json")
wave3 = read_json("evidence/source/current-feature-discovery-wave-03.json")
semantic = read_json("evidence/source/current-static-semantic-census.json")
benchmark = read_json("evidence/benchmark/current-benchmark-wave-01.json")
pages = read_json("evidence/source/current-page-adjudication-wave-01.json")
route_gap = read_json("evidence/source/current-route-navigation-gap-wave-01.json")
visual = read_json("evidence/source/current-visual-static-census-wave-01.json")
visual_matrix = read_json("evidence/source/current-visual-matrix-materialization-wave-01.json")
backend = read_json("evidence/source/current-backend-data-test-census-wave-01.json")
runtime = read_json("evidence/runtime/current-runtime-safety-assessment.json")
deployment = read_json("evidence/browser/deployed-build-identity-assessment.json")
deployed_selected = read_json("evidence/browser/current-deployed-selected-feature-observation-wave-03.json")
deployed_selected_review = read_json("evidence/browser/raw-run-060r-s-independent-browser-normalization-adjudication-wave-03.json")
canonical = read_json("evidence/source/current-canonical-feature-identity-wave-01.json")
identity_agents = read_json("evidence/source/current-canonical-identity-agent-register.json")
project_triage = read_json("evidence/benchmark/current-upstream-project-triage-wave-01.json")
project_triage_agents = read_json("evidence/benchmark/current-upstream-project-triage-agent-register.json")
partial_resolution = read_json("evidence/benchmark/current-upstream-partial-resolution-wave-01.json")
partial_resolution_agents = read_json("evidence/benchmark/current-upstream-partial-resolution-agent-register.json")
target_comparison = read_json("evidence/benchmark/current-target-neutral-comparison-wave-01.json")
target_comparison_agents = read_json("evidence/benchmark/current-target-neutral-comparison-agent-register.json")
upstream_facet_refinement = read_json("evidence/benchmark/current-upstream-facet-refinement-wave-02.json")
upstream_facet_refinement_agents = read_json("evidence/benchmark/current-upstream-facet-refinement-agent-register.json")
facet_refinement = read_json("evidence/benchmark/current-facet-neutral-comparison-wave-02.json")
facet_refinement_agents = read_json("evidence/benchmark/current-facet-neutral-comparison-agent-register.json")
formal_upstream = read_json("evidence/benchmark/current-formal-upstream-triage-wave-03.json")
formal_upstream_agents = read_json("evidence/benchmark/current-formal-upstream-triage-agent-register.json")
completion_accounting = read_json("evidence/source/raw-run-071a-completion-gate-accounting-wave-04.json")
downstream_readiness = read_json("evidence/benchmark/raw-run-071b-downstream-mapping-readiness-wave-04.json")
usability_gap_selector = read_json("evidence/browser/raw-run-071c-usability-visual-gap-selector-wave-04.json")
frontline_auth_block = read_json("evidence/browser/root-run-072-authentication-blocked-frontline-slice-wave-04.json")
usability_contract = read_json("evidence/source/raw-run-072-usability-materialization-contract-wave-01.json")
usability_materialization = read_json("evidence/source/current-usability-task-script-materialization-wave-01.json")
usability_review = read_json("evidence/source/raw-run-072-usability-independent-review-wave-01.json")
route_page_slice = read_json("evidence/source/raw-run-072-current-source-route-page-ownership-slice-wave-04.json")
incident_agent_a = read_json("evidence/benchmark/raw-run-072-agent-a-incident-observed-behavior-wave-04.json")
incident_agent_b_input = read_json("evidence/benchmark/sealed-run-072-agent-b-input-wave-04.json")
incident_agent_b = read_json("evidence/benchmark/raw-run-072-agent-b-neutral-incident-requirements-wave-04.json")
incident_agent_c_input = read_json("evidence/benchmark/sealed-run-072-agent-c-incident-comparison-input-wave-04.json")
incident_agent_c = read_json("evidence/benchmark/raw-run-072-agent-c-incident-current-comparison-wave-04.json")
incident_agent_d_input = read_json("evidence/benchmark/sealed-run-072-agent-d-incident-adjudication-input-wave-04.json")
incident_agent_d = read_json("evidence/benchmark/raw-run-072-agent-d-incident-adjudication-wave-04.json")
artifact_contract = read_json("evidence/source/raw-run-073a-required-artifact-contract-wave-05.json")
journey_evidence = read_json("evidence/source/raw-run-073b-cross-module-journeys-wave-05.json")
journey_review = read_json("evidence/source/raw-run-073d-independent-journey-review-wave-05.json")
architecture_evidence = read_json("evidence/source/root-run-073c-architecture-data-integration-security-wave-05.json")
reporting_materialization = read_json("evidence/source/current-required-reporting-materialization-wave-05.json")
architecture_review = read_json("evidence/source/raw-run-073e-independent-architecture-review-wave-05.json")
reporting_review = read_json("evidence/source/raw-run-073f-independent-reporting-materialization-review-wave-05.json")
static_linkage_producer = read_json("evidence/source/current-static-linkage-review-wave-06.json")
static_linkage_review = read_json("evidence/source/current-static-linkage-independent-review-wave-06.json")
static_linkage_integration = read_json("evidence/source/current-static-linkage-integration-wave-06.json")
static_linkage_reporting = read_json("evidence/source/current-static-linkage-reporting-materialization-wave-06.json")
route_page_manifest = read_json("evidence/source/root-run-077-route-page-universe-manifest-wave-07.json")
route_page_producer = read_json("evidence/source/current-route-page-classification-wave-07.json")
route_page_review = read_json("evidence/source/current-route-page-independent-review-wave-07.json")
route_page_integration = read_json("evidence/source/current-route-page-static-linkage-integration-wave-07.json")
route_page_reporting = read_json("evidence/source/current-route-page-reporting-materialization-wave-07.json")
route_page_candidate = read_json("evidence/source/root-run-082-exact-owner-containment-candidate-manifest-wave-08.json")
route_page_candidate_review = read_json("evidence/source/raw-run-082r-independent-exact-owner-containment-review-wave-08.json")
route_page_candidate_reporting = read_json("evidence/source/current-route-page-candidate-reporting-materialization-wave-08.json")
dashboard_run_083 = read_json("evidence/browser/current-audit-dashboard-verification-run-083-wave-08.json")
designated_app_preflight = read_json("evidence/browser/current-designated-application-access-preflight-run-084-wave-09.json")
page_graph = read_json("evidence/source/root-run-084-full-inertia-page-graph-wave-09.json")
page_graph_review = read_json("evidence/source/raw-run-084r-independent-full-inertia-page-graph-review-wave-09.json")
backend_semantic = read_json("evidence/source/root-run-084b-backend-semantic-classification-wave-09.json")
backend_semantic_review = read_json("evidence/source/raw-run-084br-independent-backend-semantic-classification-review-wave-09.json")
static_source_ownership = read_json("evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json")
static_source_ownership_review = read_json("evidence/source/raw-run-086r-independent-reviewed-static-route-page-feature-ownership-wave-10.json")
current_designated_app_preflight = read_json("evidence/browser/current-designated-application-access-preflight-run-089-wave-11.json")
direct_exact_review_queue = read_json("evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json")
closed_chain_cohort = read_json("evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json")
closed_chain_review = read_json("evidence/source/raw-run-091r-independent-closed-route-action-page-chain-review-wave-11.json")
reviewed_owner_overlay = read_json("evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json")
reviewed_owner_overlay_review = read_json("evidence/source/raw-run-092r-independent-reviewed-static-source-ownership-overlay-wave-11.json")
route_controller_cohort = read_json("evidence/source/root-run-097-route-controller-only-candidate-cohort-wave-12.json")
route_controller_review = read_json("evidence/source/raw-run-097r-independent-route-controller-only-review-wave-12.json")
reviewed_route_controller_overlay = read_json("evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json")
reviewed_route_controller_overlay_review = read_json("evidence/source/raw-run-098r-independent-reviewed-route-controller-only-ownership-overlay-wave-12.json")
outcome_neutral_cohort = read_json("evidence/source/root-run-101-outcome-neutral-route-action-cohort-wave-13.json")
outcome_neutral_review = read_json("evidence/source/raw-run-101r-independent-outcome-neutral-route-action-review-wave-13.json")
reviewed_outcome_neutral_overlay = read_json("evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json")
reviewed_outcome_neutral_overlay_review = read_json("evidence/source/raw-run-102r-independent-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json")
page_render_owner_cohort = read_json("evidence/source/root-run-109-outcome-neutral-page-render-owner-tail-cohort-wave-15.json")
page_render_owner_review = read_json("evidence/source/raw-run-109r-independent-outcome-neutral-page-render-owner-tail-review-wave-15.json")
reviewed_page_owner_overlay = read_json("evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json")
reviewed_page_owner_overlay_review = read_json("evidence/source/raw-run-110r-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json")
name_only_route_action_cohort = read_json("evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json")
name_only_route_action_review = read_json("evidence/source/raw-run-113r-independent-outcome-neutral-name-only-route-action-review-wave-16.json")
reviewed_name_only_route_action_overlay = read_json("evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json")
reviewed_name_only_route_action_overlay_review = read_json("evidence/source/raw-run-114r-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json")
respite_handover_page_gap_cohort = read_json("evidence/source/root-run-117-outcome-neutral-respite-handover-page-gap-cohort-wave-17.json")
respite_handover_page_gap_review = read_json("evidence/source/raw-run-117r-independent-outcome-neutral-respite-handover-page-gap-review-wave-17.json")
reviewed_respite_handover_page_overlay = read_json("evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json")
reviewed_respite_handover_page_overlay_review = read_json("evidence/source/raw-run-118r-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json")
finance_chart_route_action_cohort = read_json("evidence/source/root-run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18.json")
finance_chart_route_action_review = read_json("evidence/source/raw-run-121r-independent-outcome-neutral-finance-chart-route-action-review-wave-18.json")
reviewed_finance_chart_route_action_overlay = read_json("evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json")
reviewed_finance_chart_route_action_overlay_review = read_json("evidence/source/raw-run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json")
finance_page_gap_cohort = read_json("evidence/source/root-run-125-outcome-neutral-finance-page-gap-cohort-wave-19.json")
finance_page_gap_review = read_json("evidence/source/raw-run-125r-independent-outcome-neutral-finance-page-gap-review-wave-19.json")
reviewed_finance_page_gap_overlay = read_json("evidence/source/current-run-126-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json")
reviewed_finance_page_gap_overlay_review = read_json("evidence/source/raw-run-126r-independent-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json")
finance_fx_revaluation_cohort = read_json("evidence/source/root-run-129-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.json")
finance_fx_revaluation_review = read_json("evidence/source/raw-run-129r-independent-outcome-neutral-finance-fx-revaluation-route-action-review-wave-20.json")
reviewed_finance_fx_revaluation_overlay = read_json("evidence/source/current-run-130-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json")
reviewed_finance_fx_revaluation_overlay_review = read_json("evidence/source/raw-run-130r-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json")
finance_accounting_integration_cohort = read_json("evidence/source/root-run-133-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.json")
finance_accounting_integration_review = read_json("evidence/source/raw-run-133r-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21.json")
reviewed_finance_accounting_integration_overlay = read_json("evidence/source/current-run-134-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json")
reviewed_finance_accounting_integration_overlay_review = read_json("evidence/source/raw-run-134r-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json")
finance_invoice_index_cohort = read_json("evidence/source/root-run-137-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.json")
finance_invoice_index_review = read_json("evidence/source/raw-run-137r-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.json")
reviewed_finance_invoice_index_overlay = read_json("evidence/source/current-run-138-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json")
reviewed_finance_invoice_index_overlay_review = read_json("evidence/source/raw-run-138r-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json")
finance_site_portfolio_overview_cohort = read_json("evidence/source/root-run-141-outcome-neutral-finance-site-portfolio-overview-route-action-cohort-wave-23.json")
finance_site_portfolio_overview_review = read_json("evidence/source/raw-run-141r-independent-outcome-neutral-finance-site-portfolio-overview-route-action-review-wave-23.json")
reviewed_finance_site_portfolio_overview_overlay = read_json("evidence/source/current-run-142-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-wave-23.json")
reviewed_finance_site_portfolio_overview_overlay_review = read_json("evidence/source/raw-run-142r-independent-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-wave-23.json")
dashboard_run_144 = read_json("evidence/browser/current-audit-dashboard-verification-run-144-wave-23.json")
run_145_mapping = read_json("evidence/benchmark/current-run-145-finance-invoice-fx-benchmark-mapping-wave-24.json")
run_146_reporting = read_json("evidence/source/current-run-146-finance-benchmark-reporting-wave-24.json")
dashboard_run_147 = read_json("evidence/browser/current-audit-dashboard-verification-run-147-wave-24.json")
fleet_daily_check_cohort = read_json("evidence/source/root-run-148-outcome-neutral-fleet-daily-vehicle-check-store-route-action-cohort-wave-25.json")
fleet_daily_check_review = read_json("evidence/source/raw-run-148r-independent-outcome-neutral-fleet-daily-vehicle-check-store-route-action-review-wave-25.json")
reviewed_fleet_daily_check_overlay = read_json("evidence/source/current-run-149-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.json")
reviewed_fleet_daily_check_overlay_review = read_json("evidence/source/current-run-149r-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25.json")
run_150_reporting = read_json("evidence/source/current-run-150-reviewed-fleet-daily-vehicle-check-store-route-action-reporting-wave-25.json")
dashboard_run_151 = read_json("evidence/browser/current-audit-dashboard-verification-run-151-wave-25.json")
fleet_vehicle_register_cohort = read_json("evidence/source/root-run-152-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26.json")
fleet_vehicle_register_review = read_json("evidence/source/raw-run-152r-independent-outcome-neutral-fleet-vehicle-register-index-route-action-review-wave-26.json")
reviewed_fleet_vehicle_register_overlay = read_json("evidence/source/current-run-153-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.json")
reviewed_fleet_vehicle_register_overlay_review = read_json("evidence/source/current-run-153r-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.json")
run_154_reporting = read_json("evidence/source/current-run-154-reviewed-fleet-vehicle-register-index-route-action-reporting-wave-26.json")
dashboard_run_155 = read_json_strict("evidence/browser/current-audit-dashboard-verification-run-155-wave-26.json")
run_156_source_receipt = read_json_strict("evidence/source/current-run-156-medication-governance-source-main-receipt-wave-27.json")
run_156r_source_review = read_json_strict("evidence/source/current-run-156r-independent-medication-governance-source-main-receipt-review-wave-27.json")
dashboard_run_158 = read_json_strict("evidence/browser/current-audit-dashboard-verification-run-158-wave-27.json")
run_159_adjudication = read_json_strict("evidence/runtime/current-run-159-med-rbac-already-fixed-adjudication-wave-28.json")
run_159r_review = read_json_strict("evidence/runtime/current-run-159r-independent-med-rbac-adjudication-review-wave-28.json")
dashboard_run_161 = read_json_strict("evidence/browser/current-audit-dashboard-verification-run-161-wave-28.json")
run_162_remediation = read_json_strict("evidence/runtime/current-run-162-med-cd-scope-remediation-wave-29.json")
run_162r_review = read_json_strict("evidence/runtime/current-run-162r-independent-med-cd-scope-remediation-review-wave-29.json")
run_163_reporting = read_json_strict("evidence/source/current-run-163-med-cd-scope-remediation-reporting-wave-29.json")
dashboard_run_164 = read_json_strict("evidence/browser/current-audit-dashboard-verification-run-164-wave-29.json")
run_165_source_review = read_json_strict("evidence/source/current-run-165-med-cd-atomicity-current-source-review-wave-30.json")
run_166_adjudication = read_json_strict("evidence/runtime/current-run-166-med-cd-atomicity-already-fixed-adjudication-wave-30.json")
run_166r_review = read_json_strict("evidence/runtime/current-run-166r-independent-med-cd-atomicity-adjudication-review-wave-30.json")
run_167_reporting = read_json_strict("evidence/source/current-run-167-med-cd-atomicity-reporting-wave-30.json")
dashboard_run_168 = read_json_strict("evidence/browser/current-audit-dashboard-verification-run-168-wave-30.json")
fleet_vehicle_alerts_cohort = read_json_strict("evidence/source/root-run-169-outcome-neutral-fleet-vehicle-alerts-config-route-action-cohort-wave-31.json")
fleet_vehicle_alerts_review = read_json_strict("evidence/source/raw-run-169r-independent-outcome-neutral-fleet-vehicle-alerts-config-route-action-review-wave-31.json")
reviewed_fleet_vehicle_alerts_overlay = read_json_strict("evidence/source/current-run-170-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.json")
reviewed_fleet_vehicle_alerts_overlay_review = read_json_strict("evidence/source/current-run-170r-independent-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-review-wave-31.json")
run_171_reporting = read_json_strict("evidence/source/current-run-171-reviewed-fleet-vehicle-alerts-config-route-action-reporting-wave-31.json")
dashboard_run_172 = read_json_strict("evidence/browser/current-audit-dashboard-verification-run-172-wave-31.json")
run_173_remediation = read_json_strict("evidence/runtime/current-run-173-safe-alert-dedup-identity-remediation-wave-32.json")
run_173r_review = read_json_strict("evidence/runtime/current-run-173r-independent-safe-alert-dedup-identity-remediation-review-wave-32.json")
run_174_reporting = read_json_strict("evidence/source/current-run-174-safe-alert-dedup-identity-remediation-reporting-wave-32.json")
dashboard_run_175 = read_json_strict("evidence/browser/current-audit-dashboard-verification-run-175-wave-32.json")
run_176_remediation = read_json_strict("evidence/runtime/current-run-176-fleet-trip-index-site-privacy-remediation-wave-33.json")
run_176r_review = read_json_strict("evidence/runtime/current-run-176r-independent-fleet-trip-index-site-privacy-remediation-review-wave-33.json")
run_177_reporting = read_json_strict("evidence/source/current-run-177-fleet-trip-index-site-privacy-remediation-reporting-wave-33.json")
dashboard_run_178 = read_json_strict("evidence/browser/current-audit-dashboard-verification-run-178-wave-33.json")
fleet_trip_index_cohort = read_json_strict("evidence/source/root-run-179-outcome-neutral-fleet-trip-index-route-action-cohort-wave-34.json")
fleet_trip_index_review = read_json_strict("evidence/source/raw-run-179r-independent-outcome-neutral-fleet-trip-index-route-action-review-wave-34.json")
reviewed_fleet_trip_index_overlay = read_json_strict("evidence/source/current-run-180-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-wave-34.json")
reviewed_fleet_trip_index_overlay_review = read_json_strict("evidence/source/current-run-180r-independent-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-review-wave-34.json")
run_181_reporting = read_json_strict("evidence/source/current-run-181-reviewed-fleet-trip-index-route-action-reporting-wave-34.json")
dashboard_run_182 = read_json_strict("evidence/browser/current-audit-dashboard-verification-run-182-wave-34.json")
run_183_remediation = read_json_strict("evidence/runtime/current-run-183-fleet-trip-playback-site-privacy-remediation-wave-35.json")
run_183r_review = read_json_strict("evidence/runtime/current-run-183r-independent-fleet-trip-playback-site-privacy-remediation-review-wave-35.json")
run_184_reporting = read_json_strict("evidence/source/current-run-184-fleet-trip-playback-site-privacy-remediation-reporting-wave-35.json")
dashboard_run_185 = read_json_strict("evidence/browser/current-audit-dashboard-verification-run-185-wave-35.json")
run_186_remediation = read_json_strict("evidence/runtime/current-run-186-monitoring-metric-replay-dedupe-remediation-wave-36.json")
run_186r_review = read_json_strict("evidence/runtime/current-run-186r-independent-monitoring-metric-replay-dedupe-remediation-review-wave-36.json")
run_187_reporting = read_json_strict("evidence/source/current-run-187-monitoring-metric-replay-dedupe-remediation-reporting-wave-36.json")
dashboard_run_188 = read_json_strict("evidence/browser/current-audit-dashboard-verification-run-188-wave-36.json")
reviewed_fleet_trip_playback_overlay = read_json_strict("evidence/source/current-run-190-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-wave-37.json")
reviewed_fleet_trip_playback_overlay_review = read_json_strict("evidence/source/current-run-190r-independent-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-review-wave-37.json")
run_191_reporting = read_json_strict("evidence/source/current-run-191-reviewed-fleet-trip-playback-route-action-reporting-wave-37.json")
dashboard_run_192 = read_json_strict("evidence/browser/current-audit-dashboard-verification-run-192-wave-37.json")
run_193_remediation = read_json_strict("evidence/runtime/current-run-193-fleet-fuel-index-site-privacy-remediation-wave-38.json")
run_193r_review = read_json_strict("evidence/runtime/current-run-193r-independent-fleet-fuel-index-site-privacy-remediation-review-wave-38.json")
run_194_reporting = read_json_strict("evidence/source/current-run-194-fleet-fuel-index-site-privacy-remediation-reporting-wave-38.json")
dashboard_run_195 = read_json_strict("evidence/browser/current-audit-dashboard-verification-run-195-wave-38.json")
run_196_remediation = read_json_strict("evidence/runtime/current-run-196-summary-timeline-site-privacy-remediation-wave-39.json")
run_196r_review = read_json_strict("evidence/runtime/current-run-196r-independent-summary-timeline-site-privacy-remediation-review-wave-39.json")
run_197_reporting = read_json_strict("evidence/source/current-run-197-summary-timeline-site-privacy-remediation-reporting-wave-39.json")
dashboard_run_198 = read_json_strict("evidence/browser/current-audit-dashboard-verification-run-198-wave-39.json")
run_199_coordination_handoff = read_json_strict("evidence/source/current-run-199-shift-task-due-recipient-revalidation-coordination-handoff-wave-40.json")
run_199_reporting = read_json_strict("evidence/source/current-run-199-shift-task-due-recipient-revalidation-remediation-reporting-wave-40.json")
dashboard_run_200 = read_json_strict("evidence/browser/current-audit-dashboard-verification-run-200-wave-40.json")
run_201_coordination_handoff = read_json_strict("evidence/source/current-run-201-elig-shift-notification-site-privacy-coordination-handoff-wave-41.json")
run_201_reporting = read_json_strict("evidence/source/current-run-201-elig-shift-notification-site-privacy-remediation-reporting-wave-41.json")
dashboard_run_202 = read_json_strict("evidence/browser/current-audit-dashboard-verification-run-202-wave-41.json")
run_203_coordination_handoff = read_json_strict("evidence/source/current-run-203-fleet-trip-playback-data-point-eligibility-coordination-handoff-wave-42.json")
run_203_reporting = read_json_strict("evidence/source/current-run-203-fleet-trip-playback-data-point-eligibility-remediation-reporting-wave-42.json")
findings_register = read_json_strict("findings.json")
assert sha256_file("evidence/source/current-canonical-feature-identity-wave-01.json") == "f4feae2598622afe346b1163fed2bb842305a8d973a89ec890c02746d99b5999"
assert sha256_file("evidence/source/current-canonical-identity-agent-register.json") == "21ebd8b004b5ade11aa01281958cda2be2ca966d1fb7c46576e039fab5f47baf"
assert static_linkage_integration["matrix"]["updated_sha256"] == route_page_manifest["pins"]["inputs"]["03-feature-to-benchmark-matrix.csv"]
assert route_page_integration["matrix"]["updated_sha256"] == HISTORICAL_RUN_080_MATRIX_SHA256
assert sha256_file("03-feature-to-benchmark-matrix.csv") == CURRENT_RUN_145_MATRIX_SHA256
assert sha256_file("evidence/benchmark/current-upstream-project-triage-wave-01.json") == "ea0bb6bde44aa8f227d6e4133788e8fcb08c3069e2aecab4e0bc194cee2f3651"
assert sha256_file("evidence/benchmark/current-upstream-project-triage-agent-register.json") == "686ae0f32abe1d890ed89228c46bbb8eb0a28b4ff16f91dad31d0b2e34f44811"
assert sha256_file("evidence/benchmark/current-upstream-partial-resolution-wave-01.json") == "6c7c5eb6532a4ec4bcc45ebcb4f2cfd9ff7af6156c68064db58ce5dfeeb2e305"
assert sha256_file("evidence/benchmark/current-upstream-partial-resolution-agent-register.json") == "80144bcc32db3d8830e2247d2fe803c2e259900165ae9b7eaf2228838d230071"
assert sha256_file("evidence/benchmark/current-target-neutral-comparison-wave-01.json") == "648fd95c9291a094a60bf1dfb007e1da9f58eb9b9889ffaad4fa5d542ecbf1f4"
assert sha256_file("evidence/benchmark/current-target-neutral-comparison-agent-register.json") == "dfd29c6896e2401234726a6be4bb98685d19b1ed9bcdd145751aa51dcba23104"
assert sha256_file("evidence/benchmark/current-upstream-facet-refinement-wave-02.json") == "d41cc046de9b7580e937ca1b9d1df7f9237947dcaed5bf0f95772b667d8d9f3e"
assert sha256_file("evidence/benchmark/current-upstream-facet-refinement-agent-register.json") == "bc8265ee896a04335b9c36a077f09bb7c0d7d8e5892200542601f682f05e076b"
assert sha256_file("evidence/benchmark/raw-run-053-agent-a-blind-observed-behaviour-packets-wave-02.json") == "835ec0755a5a4b5543f317969e9ba557bc156b137083df925f05d4b753ccde6b"
assert sha256_file("evidence/benchmark/root-run-053-agent-a-source-atom-crosswalk-wave-02.json") == "78f1e5c77c3dda80048a7b2d6d03859bc22bfe01afba5547ae20ffe797839dfe"
assert sha256_file("evidence/benchmark/raw-run-054-fresh-agent-b-neutral-requirements-wave-02.json") == "7e1e2203dd5af9852f69b1ff5ad05a5d031e4d8d12096ee39055129954f01a68"
assert sha256_file("evidence/benchmark/raw-run-054-agent-b-input-boundary-correction-wave-02.json") == "1f197b817e5e184efbcf4e7549664008aa3fe331d1b4d4ab5470a3a214364b8b"
assert sha256_file("evidence/benchmark/raw-run-055-agent-c-comparison-input-wave-02.json") == "5422d3b25795189be5fd5070bb18d3e82af17b718fd821f735b5bc0d9c9a57e7"
assert sha256_file("evidence/benchmark/raw-run-055-fresh-agent-c-current-comparison-wave-02.json") == "666c6de668cc8f1db3661e55da309034992d1e27d904c740159bc4f3039fb275"
assert sha256_file("evidence/benchmark/raw-run-056-independent-adjudicator-input-wave-02.json") == "b611f1e513f1eb8153a6832d179e4708c6b17ae2e326abe6c74e5a0b8746cbc7"
assert sha256_file("evidence/benchmark/raw-run-056-fresh-independent-corrected-chain-adjudication-wave-02.json") == "02d858172bebe4a98de407be10001bc3db8b8da7c55197153a4c4604f3728bf5"
assert sha256_file("evidence/benchmark/current-facet-neutral-comparison-wave-02.json") == "c672dfa178ef5e4f8412e3492506d13ecb702e0dd05826b669476c613b89fb0e"
assert sha256_file("evidence/benchmark/current-facet-neutral-comparison-agent-register.json") == "d1f322e926b3d4b16a9c162d5e8a66f4f8c02a3db5a5b09f2e5ad751ad2a64af"
assert sha256_file("06-open-source-benchmark-register.csv") == CURRENT_RUN_145_REGISTER_SHA256
assert sha256_file("evidence/benchmark/current-run-145-finance-invoice-fx-benchmark-mapping-wave-24.json") == CURRENT_RUN_145_RECEIPT_SHA256
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-144-wave-23.json") == "fd21527929483cca88e03af8a8ff2f5e8095c5af280fc27546486e3ddc6dd7f5"
assert sha256_file("evidence/source/current-run-146-finance-benchmark-reporting-wave-24.json") == "50953b6281cf198f6dc6ff56027d0eebe7e78697781d459dd620ed9bb2b1277e"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-147-wave-24.json") == "36e0595b3e90f439770c9e8aadbb01555591c79e38ffac54d3cfd6dc3b892cc0"
assert sha256_file("evidence/source/root-run-148-outcome-neutral-fleet-daily-vehicle-check-store-route-action-cohort-wave-25.json") == "621c1794a73e232b6fc9ff8d2b81ac9ae31ea2ccfe9f038ae77afe332b3ab28d"
assert sha256_file("evidence/source/raw-run-148r-independent-outcome-neutral-fleet-daily-vehicle-check-store-route-action-review-wave-25.json") == "6720a7570f7f0547fca222758c0632cb7514d953a20605e7c00d6ce88efc18b2"
assert sha256_file("evidence/source/current-run-149-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.json") == "12a52c434ecd18a5c6a644378070aa5ab046f5e7080726b983ded8d9c7377a55"
assert sha256_file("evidence/source/current-run-149r-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25.json") == "545694fc1b7bd5f4af244617fb421ece1265fe6e6f2cad2ca834115e7a9e75a2"
assert sha256_file("evidence/source/current-run-150-reviewed-fleet-daily-vehicle-check-store-route-action-reporting-wave-25.json") == "f5fd2fd59e8cdf26e30343774c7e76ede235a64cc1f6bb447b9867df2c5f30b2"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-151-wave-25.json") == "15b4ef5de5fc9029af9ff74dcb02dd1e52177695fd367ea9347c3a8b3c9f20c0"
assert sha256_file("evidence/source/root-run-152-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26.json") == "5e987d8727896183aadf30b9000ed56b318e2f4c8935b6d77e3600999105eac4"
assert sha256_file("evidence/source/raw-run-152r-independent-outcome-neutral-fleet-vehicle-register-index-route-action-review-wave-26.json") == "43697db4e3a5743d6dc9b47a3e80c6ec5c528dba17c2e99a4a13f95933c899d8"
assert sha256_file("evidence/source/current-run-153-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.json") == "9b7e382f83787d807de8d752ecb3e6524280c707899aba78d47082765272e815"
assert sha256_file("evidence/source/current-run-153r-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.json") == "7f1da8394a8054f01f34fb943a3fba6601bf70ea06d69cf97033f2208edf4461"
assert sha256_file("generators/materialize-run-168-audit-dashboard-verification-wave-30.py") == "27f77f21f1aaac195ae0ca901b4f207129fb20666fe5b8e4eccea1d2e2cf56db"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-168-wave-30.json") == "95f5eff21563ff010cd49f2ff6cf958825f1d1f7717066ed571e9e078dea4998"
assert sha256_file("generators/build-outcome-neutral-fleet-vehicle-alerts-config-route-action-cohort-wave-31.py") == "ffb1dba865a50f3cdcbf4e3ce285482e062bb023145089353a68f705d0646c7e"
assert sha256_file("evidence/source/root-run-169-outcome-neutral-fleet-vehicle-alerts-config-route-action-cohort-wave-31.json") == "2fc20f6e528adae64979a763e6f28dd86018c2ecd87bbb0b651ddf6eee158fb2"
assert sha256_file("generators/materialize-independent-outcome-neutral-fleet-vehicle-alerts-config-route-action-review-wave-31.py") == "6cdceb0f2b25a33fba8675f614f61a2aa5692dfb0a02768887755dd8fdfa4687"
assert sha256_file("evidence/source/raw-run-169r-independent-outcome-neutral-fleet-vehicle-alerts-config-route-action-review-wave-31.json") == "698257a0e6543d685397977d658d9681281ce6634f709ced73939c09e76f02bc"
assert sha256_file("generators/integrate-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.py") == "c732926f3112c987fbaaf3f398bc18b3d25027c7f1495c38016237a5cb6f28a3"
assert sha256_file("evidence/source/current-run-170-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.json") == "c739a36e1975b60d42988be3de36b9fe1ea88cf942752c90112f40ebaa04cd8d"
assert sha256_file("generators/materialize-independent-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-review-wave-31.py") == "752d64a1fa5ef6260feff84db3698b87a34170dd4fd6afbad6f2f54f1f1a814e"
assert sha256_file("evidence/source/current-run-170r-independent-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-review-wave-31.json") == "62474100b0c2f027fa0c15f2bb841f08ad3de058da67725a931fcafec17dd139"
current_run_155_156r_artifact_pins = {
    "generators/materialize-run-155-audit-dashboard-verification-wave-26.py": (
        "1f2bd52237f28cb11f79e4fa65d1f0a82889fd313fbee08d4e222816a7147139",
        "8b9604e3c316be98c33bc0e1d97e2aea4f0fba9c",
        23854,
        366,
    ),
    "evidence/browser/current-audit-dashboard-verification-run-155-wave-26.json": (
        "576605975af18a35be413e48e4da042e6bae706fc2438c9e7cfa89b5c9394fe3",
        "1f8e21521f4247f39cd23dff258909e4bbcc96ce",
        17688,
        429,
    ),
    "generators/materialize-run-156-medication-governance-source-main-receipt-wave-27.py": (
        "e611f494567ce966e5c678a9579bb26278da0a87d814b649ccf973b102bcd4ea",
        "0caeb16bf63e0d6b4cd084c539a6d74c303d6cfb",
        35600,
        779,
    ),
    "evidence/source/current-run-156-medication-governance-source-main-receipt-wave-27.json": (
        "56094f7e83acf8000d0b680d751cc3d27e8627916eef45173002b43207091e76",
        "38e69aa0897cc8b8f7d55363f5bc1ed491411095",
        16444,
        330,
    ),
    "generators/materialize-independent-medication-governance-source-main-receipt-review-wave-27.py": (
        "fc2498be1f1e6539c1dcb898e424c47599588388522e8496f82ef70f3754b915",
        "451638c20d15424ac1d49cffbf3814c6696b0a2c",
        25584,
        607,
    ),
    "evidence/source/current-run-156r-independent-medication-governance-source-main-receipt-review-wave-27.json": (
        "01945390f1d2c8a70dfcef6ea7327aa9f63c84f543dec5a6d8c67c7625dd032a",
        "1fe1f8d9d59a8729cb9c19f71a33b48d59df1e99",
        13268,
        277,
    ),
}
for path, (expected_sha256, expected_blob, expected_bytes, expected_lines) in current_run_155_156r_artifact_pins.items():
    assert sha256_file(path) == expected_sha256
    assert git_blob_id(path) == expected_blob
    assert text_file_metrics(path) == (expected_bytes, expected_lines)

for receipt in (dashboard_run_155, run_156_source_receipt, run_156r_source_review):
    assert receipt["architecture_rule"]["operating_organisations"] == 1
    assert receipt["architecture_rule"]["multiple_sites"] is True
    assert receipt["architecture_rule"]["multi_tenant"] is False

current_run_158_159r_artifact_pins = {
    "generators/materialize-run-158-audit-dashboard-verification-wave-27.py": (
        "e5d2bb3dd0a0cfd3db1f24ea859813c107b10767cf4e22f12aa8842d37103e49",
        "09cb906d08310313db61a2fef8c194bbf3a62f47",
        38017,
        977,
    ),
    "evidence/browser/current-audit-dashboard-verification-run-158-wave-27.json": (
        "4b3cf785c5d9f4f0f0263b90ddc722818d1d8fdb4e9bf89bd44f1fec117752fb",
        "3268066ca3204e9c9d3233c2497ce88183b54d85",
        19841,
        527,
    ),
    "generators/materialize-run-159-med-rbac-already-fixed-adjudication-wave-28.py": (
        "cfd37697847c57a5e8116adb5836945daf21208fb00d0885abf7f3d594379ae7",
        "3f0965f58ea4855f76288d662616b0ad6b7d9964",
        23846,
        472,
    ),
    "evidence/runtime/current-run-159-med-rbac-already-fixed-adjudication-wave-28.json": (
        "bc666ded05774b03b849743436cec47cbdb260c8ab763cf502e71c804af7fd8e",
        "116664410ebeb4fa97ed93e7badbd7537c9a4b5d",
        17319,
        379,
    ),
    "generators/materialize-independent-run-159-med-rbac-adjudication-review-wave-28.py": (
        "bc1ef82dfe6459b726acf2567d6d976dbefb8cf869441e32eb0cb02c626a6b5e",
        "1d028d1f90876453e88d578ee9b70b06cc2fd311",
        10808,
        249,
    ),
    "evidence/runtime/current-run-159r-independent-med-rbac-adjudication-review-wave-28.json": (
        "be0651adf9edfbf7694ac535908cf43a5631675bcf6d5264add68fe947437d18",
        "531218a89947b42bf9137a0d588d29c617ee96f0",
        3368,
        78,
    ),
}
for path, (expected_sha256, expected_blob, expected_bytes, expected_lines) in current_run_158_159r_artifact_pins.items():
    assert sha256_file(path) == expected_sha256
    assert git_blob_id(path) == expected_blob
    assert text_file_metrics(path) == (expected_bytes, expected_lines)

assert dashboard_run_158["schema_version"] == "run-158-audit-dashboard-verification-wave-27-v1"
assert dashboard_run_158["run_id"] == "RUN-158-AUDIT-DASHBOARD-VERIFICATION-WAVE-27"
assert dashboard_run_158["status"] == "AUDIT_ARTIFACT_RESPONSIVE_LINK_CONSOLE_VERIFICATION_GO_ZERO_APPLICATION_CREDIT"
assert dashboard_run_158["pins"]["checkpoint_commit"] == "a8d397c91d50021015165f5d625b455a8a58c5f0"
run_158_verification = dashboard_run_158["verification"]
assert run_158_verification["viewports_verified"] == run_158_verification["viewports_required"] == 4
assert run_158_verification["navigation_targets"] == "10/10"
assert run_158_verification["post_materialization_local_resources"] == "387/387"
assert run_158_verification["duplicate_authored_ids_observed"] == 0
assert run_158_verification["console_warnings"] == run_158_verification["console_errors"] == run_158_verification["page_errors"] == 0
assert run_158_verification["exact_visible_static_boundary_check_count"] == 50
assert all(run_158_verification["exact_visible_static_boundary_checks"].values())
assert {key for key, value in dashboard_run_158["credit_boundary"].items() if value} == {"exact_audit_dashboard_artifact"}
assert all(value is False for value in dashboard_run_158["completion_boundary"].values())

assert run_159_adjudication["schema_version"] == "run-159-med-rbac-already-fixed-adjudication-wave-28-v1"
assert run_159_adjudication["run_id"] == "RUN-159-MED-RBAC-01-ALREADY-FIXED-ADJUDICATION-WAVE-28"
assert run_159_adjudication["status"] == "ALREADY_FIXED_UNANIMOUS_CURRENT_SOURCE_REVIEW_AND_BOUNDED_MYSQL_TESTS_HISTORICAL_CLAIM_RETIREMENT_AUTHORIZED_ZERO_FINAL_FINDING_OR_COMPLETION_CREDIT"
assert run_159_adjudication["pins"]["application_commit"] == "4f57ad4202df90ded375961437879822a908627b"
assert run_159_adjudication["pins"]["application_tree"] == "ee79b8d2733d09da2fd97992ac2a04e862159505"
run_159_review_process = run_159_adjudication["review_process"]
assert run_159_review_process["independent_read_only_lanes"] == 3
assert run_159_review_process["unanimous_verdict"] == "ALREADY_FIXED"
assert all(row["verdict"] == "ALREADY_FIXED" for row in run_159_review_process["reviewers"])
run_159_disposition = run_159_adjudication["historical_and_current_disposition"]
assert run_159_disposition["finding_id"] == "MED-RBAC-01"
assert run_159_disposition["current_orders_manage_only_bypass_reproduced"] is False
assert run_159_disposition["current_final_finding"] is False
assert run_159_disposition["application_remediation_required"] is False
assert run_159_disposition["record_action_authorized"] == "RETIRE_PROVISIONAL_CURRENT_SOURCE_CLAIM_PRESERVE_HISTORICAL_IDENTITY"
run_159_runtime = run_159_adjudication["runtime_execution"]
assert run_159_runtime["totals"] == {
    "commands": 3,
    "tests_passed": 73,
    "tests_failed": 0,
    "assertions": 1481,
    "duration_seconds": "450.72",
}
assert run_159_runtime["database"]["post_run_effective_schema_prefix_match_count"] == 0
assert run_159_runtime["database"]["post_run_configured_base_present"] == 0
assert run_159_runtime["database"]["all_run159_schema_residue_absent"] is True
assert run_159_runtime["post_cleanup_php_processes"] == run_159_runtime["post_cleanup_php_listeners"] == 0
assert run_159_runtime["browser_executed"] is False
assert {key for key, value in run_159_adjudication["credit_boundary"].items() if value} == {
    "historical_condition_source_confirmed",
    "current_source_already_fixed_adjudication",
    "bounded_med_rbac_test_execution",
    "provisional_current_source_claim_retirement_authorized",
}
assert all(value is False for value in run_159_adjudication["completion_boundary"].values())
assert run_159_adjudication["artifact_completion_test_met"] is True
assert run_159_adjudication["audit_completion_test_met"] is False

assert run_159r_review["schema_version"] == "run-159r-independent-med-rbac-adjudication-review-wave-28-v1"
assert run_159r_review["run_id"] == "RUN-159R-INDEPENDENT-MED-RBAC-01-ADJUDICATION-RECEIPT-REVIEW-WAVE-28"
assert run_159r_review["status"] == "GO_EXACT_RUN159_ARTIFACT_REVIEW_RETIREMENT_REPORTING_AUTHORIZED_ZERO_DOWNSTREAM_CREDIT"
assert run_159r_review["decision"]["verdict"] == "GO"
assert run_159r_review["decision"]["blocking_discrepancies"] == 0
assert run_159r_review["decision"]["retirement_reporting_authorized"] is True
assert run_159r_review["pins"]["producer_generator"]["sha256"] == current_run_158_159r_artifact_pins["generators/materialize-run-159-med-rbac-already-fixed-adjudication-wave-28.py"][0]
assert run_159r_review["pins"]["producer_receipt"]["sha256"] == current_run_158_159r_artifact_pins["evidence/runtime/current-run-159-med-rbac-already-fixed-adjudication-wave-28.json"][0]
assert {key for key, value in run_159r_review["credit_boundary"].items() if value} == {"independent_exact_artifact_review_for_retirement_reporting"}
assert run_159r_review["artifact_completion_test_met"] is True
assert run_159r_review["audit_completion_test_met"] is False

current_run_161_162r_artifact_pins = {
    "generators/materialize-run-161-audit-dashboard-verification-wave-28.py": (
        "a0970afe9672e878f5a813e59e9d51ee0c95c6e953c4fe3bd8a175e85e6209b9",
        "14b4664df7577ee94aa19ebcb2cc2d79d67ba75c",
        58488,
        1397,
    ),
    "evidence/browser/current-audit-dashboard-verification-run-161-wave-28.json": (
        "dc62fe1a6242dc42e0f9f75b278a0fbf042a667279ca3a4fdabb279d361613e3",
        "19565e42e8cf348bc00f356649162d4d97292a65",
        30252,
        759,
    ),
    "generators/materialize-run-162-med-cd-scope-remediation-wave-29.py": (
        "d305638441b8ff366fa5fbc5a00bcc2b81658bf2611a5633ad79fdb4b63f5fb4",
        "4798f25dd906fe0a25cf35fbbbd97ea17ba71255",
        19724,
        410,
    ),
    "evidence/runtime/current-run-162-med-cd-scope-remediation-wave-29.json": (
        "21564caa435927d89d994a091383409e627c44170304f6ff2a5d5c897c858958",
        "d1c0f2d16d8899ad80ffdb5d0261003a760e1147",
        14584,
        308,
    ),
    "generators/materialize-independent-run-162-med-cd-scope-remediation-review-wave-29.py": (
        "c5278e5b80cd4c8c3c159a8ce3e6ae98788ad2dc9d9a1087820f910c7b203ab2",
        "ee0c65c9759adddd322d7614e28c8a73b89a05e0",
        10486,
        240,
    ),
    "evidence/runtime/current-run-162r-independent-med-cd-scope-remediation-review-wave-29.json": (
        "7a1decaccfde2246163daef3dbec285b6a5a1a5019d2411615cc7e003660ff78",
        "a4c704186080cf077a2ed4631aa55020af74f41c",
        4374,
        105,
    ),
}
for path, (expected_sha256, expected_blob, expected_bytes, expected_lines) in current_run_161_162r_artifact_pins.items():
    assert sha256_file(path) == expected_sha256
    assert git_blob_id(path) == expected_blob
    assert text_file_metrics(path) == (expected_bytes, expected_lines)

assert dashboard_run_161["schema_version"] == "run-161-audit-dashboard-verification-wave-28-v1"
assert dashboard_run_161["run_id"] == "RUN-161-AUDIT-DASHBOARD-VERIFICATION-WAVE-28"
assert dashboard_run_161["status"] == "AUDIT_REPORTING_ATTRIBUTION_CORRECTED_EXACT_ARTIFACT_VERIFIED_ZERO_APPLICATION_CREDIT"
assert dashboard_run_161["pins"]["checkpoint_commit"] == "1ff92f28ffbb939d48d300cffbc8f33ab4489d93"
assert dashboard_run_161["pins"]["checkpoint_tree"] == "b035b9ba02155e5e33e0cdcaab342dd21a2a961e"
run_161_verification = dashboard_run_161["verification"]
assert run_161_verification["viewports_verified"] == run_161_verification["viewports_required"] == 4
assert run_161_verification["navigation_targets"] == "10/10"
assert run_161_verification["post_materialization_local_resources"] == "395/395"
assert run_161_verification["exact_visible_static_boundary_check_count"] == 63
assert all(run_161_verification["exact_visible_static_boundary_checks"].values())
assert run_161_verification["console_warnings"] == run_161_verification["console_errors"] == run_161_verification["page_errors"] == 0
assert {key for key, value in dashboard_run_161["credit_boundary"].items() if value} == {
    "audit_reporting_attribution_correction",
    "exact_audit_dashboard_artifact",
}
assert all(value is False for value in dashboard_run_161["completion_boundary"].values())

assert run_162_remediation["schema_version"] == "run-162-med-cd-scope-remediation-wave-29-v1"
assert run_162_remediation["run_id"] == "RUN-162-MED-CD-SCOPE-01-REMEDIATION-WAVE-29"
assert run_162_remediation["status"] == "REPRODUCED_CURRENT_SCOPE_DEFECTS_REMEDIATED_PUBLISHED_AND_BOUNDED_VERIFIED_REPORTING_NOT_YET_AUTHORIZED_ZERO_FINAL_FINDING_OR_COMPLETION_CREDIT"
run_162_pins = run_162_remediation["pins"]
assert run_162_pins["application_commit"] == "0b1920dade9251d617f3cb0b69da5c0202b5a6bf"
assert run_162_pins["repository_tree_at_application_commit"] == "7b2b5688c90e4da28725e70e38e50fd445f1b4c4"
assert run_162_pins["stable_patch_id"] == "09bc6f401235fa70b5f9da90aef226b2b7aa2d73"
assert run_162_pins["effective_application_base_commit"] == run_159_adjudication["pins"]["application_commit"]
assert run_162_pins["effective_application_base_tree"] == run_159_adjudication["pins"]["application_tree"]
assert run_162_pins["application_remote_publication_observed"]["published_commit"] == run_162_pins["application_commit"]
assert run_162_pins["application_remote_publication_observed"]["remote_observed_tip"] == run_162_pins["application_commit"]
assert run_162_pins["application_remote_publication_observed"]["force_push"] is False
assert run_162_remediation["issue_first_disposition"]["finding_id"] == "MED-CD-SCOPE-01"
assert run_162_remediation["issue_first_disposition"]["current_main_genuine_related_defects_reproduced_before_fix"] == 5
assert run_162_remediation["issue_first_disposition"]["verdict"] == "REPRODUCED_AND_REMEDIATED_CURRENT_MAIN"
run_162_runtime = run_162_remediation["runtime_execution"]
assert run_162_runtime["advanced_main_focused_command"]["exit_code"] == 0
assert run_162_runtime["advanced_main_focused_command"]["tests"] == 5
assert run_162_runtime["advanced_main_focused_command"]["assertions"] == 48
assert run_162_runtime["broader_bounded_execution"]["directly_related_controller_and_command_tests_passed"] == 102
assert run_162_runtime["broader_bounded_execution"]["combined_passed"] == 108
assert run_162_runtime["broader_bounded_execution"]["combined_assertions"] == 1454
assert run_162_runtime["broader_bounded_execution"]["combined_failed"] == 2
assert run_162_runtime["broader_bounded_execution"]["baseline_replay_at_base_commit"]["classification"] == "BASE_REPRODUCED_FAILURES_NOT_ATTRIBUTED_TO_RUN162_FULL_SUITE_GREEN_FALSE"
assert run_162_runtime["broader_bounded_execution"]["full_suite_or_coverage_credit"] is False
assert run_162_remediation["cleanup_evidence"]["matching_schema_count"] == 0
assert run_162_remediation["cleanup_evidence"]["owned_php_process_count"] == run_162_remediation["cleanup_evidence"]["owned_listener_count"] == 0
assert run_162_remediation["independent_static_reviews"]["reviewer_count"] == 3
assert run_162_remediation["independent_static_reviews"]["unanimous_verdict"] == "GO"
assert all(row["verdict"] == "GO" for row in run_162_remediation["independent_static_reviews"]["reviewers"])
assert run_162_remediation["independent_static_reviews"]["exact_receipt_review_completed"] is False
assert run_162_remediation["independent_static_reviews"]["retirement_reporting_authorized"] is False
assert {key for key, value in run_162_remediation["credit_boundary"].items() if value} == {
    "historical_condition_confirmed",
    "current_related_defects_reproduced",
    "application_remediation",
    "bounded_runtime",
    "application_commit_integrated",
    "application_commit_published",
}
run_162_payload_without_seal = dict(run_162_remediation)
run_162_seal = run_162_payload_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_162_payload_without_seal) == run_162_seal
assert all(value is False for value in run_162_remediation["completion_boundary"].values())

assert run_162r_review["schema_version"] == "run-162r-independent-med-cd-scope-remediation-review-wave-29-v1"
assert run_162r_review["run_id"] == "RUN-162R-INDEPENDENT-MED-CD-SCOPE-01-REMEDIATION-RECEIPT-REVIEW-WAVE-29"
assert run_162r_review["status"] == "GO_EXACT_RUN162_ARTIFACT_REVIEW_RETIREMENT_REPORTING_AUTHORIZED_ZERO_DOWNSTREAM_CREDIT"
assert run_162r_review["decision"]["verdict"] == "GO"
assert run_162r_review["decision"]["blocking_discrepancies"] == 0
assert run_162r_review["decision"]["retirement_reporting_authorized"] is True
assert run_162r_review["decision"]["authorized_reporting_status"] == "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
assert run_162r_review["decision"]["authorized_live_count_delta"] == {
    "retained_claim_records": 0,
    "current_provisional_source_claims": -1,
    "historical_already_fixed_records": 0,
    "historical_remediated_records": 1,
    "final_P0": 0,
    "final_P1": 0,
    "benchmark_mapped": 0,
    "final_no_match_or_NCM": 0,
    "benchmark_unresolved": 0,
}
assert run_162r_review["pins"]["producer_receipt"]["sha256"] == current_run_161_162r_artifact_pins["evidence/runtime/current-run-162-med-cd-scope-remediation-wave-29.json"][0]
assert {key for key, value in run_162r_review["credit_boundary"].items() if value} == {"independent_exact_artifact_review_for_retirement_reporting"}
run_162r_payload_without_seal = dict(run_162r_review)
run_162r_seal = run_162r_payload_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_162r_payload_without_seal) == run_162r_seal
assert all(value is False for value in run_162r_review["completion_boundary"].values())

current_run_164_166r_artifact_pins = {
    "generators/materialize-run-164-audit-dashboard-verification-wave-29.py": (
        "5f9de09fed4dec440095497d21ebaa3e4ae91279899ca8d2e6bd0a7a0019e3ca",
        "a875985399e04edbbf175e106204d96d52f9edff",
        41854,
        954,
    ),
    "evidence/browser/current-audit-dashboard-verification-run-164-wave-29.json": (
        "d343a1fad55788da33be0745471258acbe9a5ca01739b03205a194fa279ed45b",
        "026c943bbe286b228e092a0ef0702b52d92827de",
        22929,
        542,
    ),
    "generators/materialize-run-165-med-cd-atomicity-current-source-review-wave-30.py": (
        "ccc8d49b793ca1980272f93ca77c870e29de72256100d4a5595cc114a4d010ea",
        "a8fec73f84b8e6c1208647839bdc47904db6758a",
        22581,
        419,
    ),
    "evidence/source/current-run-165-med-cd-atomicity-current-source-review-wave-30.json": (
        "83257b5689f69885be2ed53bee8c0250b62d0e159f5b71dc0382282bb12a81c0",
        "07c446cbf89cf5fdcc435e2fa814e48a69a2e925",
        13240,
        288,
    ),
    "generators/materialize-run-166-med-cd-atomicity-already-fixed-adjudication-wave-30.py": (
        "662a8629a6ffda759e1d471b56230d1944ab2663f6f292cdf74604c22342a845",
        "34963e6c93d1fd3a975432c84bd8975a187efc19",
        30738,
        579,
    ),
    "evidence/runtime/current-run-166-med-cd-atomicity-already-fixed-adjudication-wave-30.json": (
        "c334495fa7cf7303ae70dccff475b7a9583b927bf06ee822f1a13bd347a84a46",
        "bed3743d2191c86d4526236b78bea896a0b60574",
        21939,
        485,
    ),
    "evidence/runtime/harnesses/run-166-controlled-drug-atomicity-concurrency-test.php.txt": (
        "49bbc43ca9caa470e10992751f3e2b7080cde6cf6ff554994ce85e0956b5d807",
        "f87f011bd6441f3cafcfc1528378e21f180d6570",
        31845,
        715,
    ),
    "generators/materialize-independent-run-166-med-cd-atomicity-adjudication-review-wave-30.py": (
        "c68a95e98c277eaab39c046aa0972c905e22ad4809108112194fdc84202989b1",
        "11c0becd82ef53e2819bdf60085c3e8eecb90829",
        19652,
        381,
    ),
    "evidence/runtime/current-run-166r-independent-med-cd-atomicity-adjudication-review-wave-30.json": (
        "fa899d56b13b584ccd103996c6a46407b105f968b0d769eae5be69a5f47b7d21",
        "b0c2c1d95ab0d5fa68285d80bae337912c2659f1",
        9134,
        195,
    ),
}
for path, (expected_sha256, expected_blob, expected_bytes, expected_lines) in current_run_164_166r_artifact_pins.items():
    assert sha256_file(path) == expected_sha256
    assert git_blob_id(path) == expected_blob
    assert text_file_metrics(path) == (expected_bytes, expected_lines)

assert dashboard_run_164["schema_version"] == "run-164-audit-dashboard-verification-wave-29-v1"
assert dashboard_run_164["run_id"] == "RUN-164-AUDIT-DASHBOARD-VERIFICATION-WAVE-29"
assert dashboard_run_164["status"] == "AUDIT_DASHBOARD_MOBILE_OVERFLOW_AND_SEMANTIC_BOUNDARIES_CORRECTED_EXACT_ARTIFACT_RESPONSIVE_LINK_CONSOLE_VERIFICATION_GO_ZERO_APPLICATION_OR_COMPLETION_CREDIT"
run_164_verification = dashboard_run_164["verification"]
assert run_164_verification["viewports_verified"] == run_164_verification["viewports_required"] == 4
assert run_164_verification["navigation_clicks_passed"] == run_164_verification["navigation_clicks_required"] == 10
assert run_164_verification["post_materialization_local_resources"] == "403/403"
assert run_164_verification["visible_static_checks_passed"] == run_164_verification["visible_static_checks_required"] == 33
assert run_164_verification["page_overflow_zero_at_all_final_viewports"] is True
assert run_164_verification["duplicate_authored_ids"] == []
assert run_164_verification["console_warning_entries"] == run_164_verification["console_error_entries"] == run_164_verification["uncaught_page_error_entries"] == 0
assert {key for key, value in dashboard_run_164["credit_boundary"].items() if value} == {
    "audit_dashboard_run_164_corrections",
    "exact_audit_dashboard_artifact",
}
run_164_payload_without_seal = dict(dashboard_run_164)
run_164_seal = run_164_payload_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_164_payload_without_seal) == run_164_seal == "0405b5be4c38f75b803da2776b975e816fa27cabe2483c2cafd5c6a04ce55c74"
assert all(value is False for value in dashboard_run_164["completion_boundary"].values())

assert run_165_source_review["schema_version"] == "run-165-med-cd-atomicity-current-source-review-wave-30-v1"
assert run_165_source_review["run_id"] == "RUN-165-MED-CD-ATOMICITY-01-CURRENT-SOURCE-REVIEW-WAVE-30"
assert run_165_source_review["status"] == "CURRENT_MAIN_STRUCTURAL_ATOMICITY_GO_ALREADY_FIXED_CANDIDATE_EXACT_RUNTIME_GATE_REQUIRED_ZERO_FINDING_OR_COMPLETION_CREDIT"
assert run_165_source_review["pins"]["reviewed_source_checkpoint"] == "cf0090ec97242776eea30a2875756446f42862f9"
assert run_165_source_review["pins"]["reviewed_source_tree"] == "b1c932d1c5c19e9e2ea655da5964dd1c5e9c41f3"
assert run_165_source_review["compound_record_boundary"]["bounded_clause_adjudicated_here"] == "manual storeCDEntry register/stock atomicity only"
assert run_165_source_review["current_source_condition"]["source_disposition"] == "ALREADY_FIXED_CANDIDATE_RUNTIME_GATE_REQUIRED"
assert {key for key, value in run_165_source_review["credit_boundary"].items() if value} == {"independent_current_source_review"}
run_165_payload_without_seal = dict(run_165_source_review)
run_165_seal = run_165_payload_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_165_payload_without_seal) == run_165_seal == "8de90b3d923add5d4e1601561c9abb2b19e39b68aff79d74072b1edac1031212"
assert all(value is False for value in run_165_source_review["completion_boundary"].values())

assert run_166_adjudication["schema_version"] == "run-166-med-cd-atomicity-already-fixed-adjudication-wave-30-v1"
assert run_166_adjudication["run_id"] == "RUN-166-MED-CD-ATOMICITY-01-ALREADY-FIXED-ADJUDICATION-WAVE-30"
assert run_166_adjudication["status"] == "ALREADY_FIXED_BOUNDED_MANUAL_CD_ENTRY_ATOMICITY_SOURCE_RUNTIME_GO_RUN166R_REQUIRED_ZERO_FINAL_FINDING_OR_COMPLETION_CREDIT"
assert run_166_adjudication["historical_and_current_disposition"]["verdict"] == "ALREADY_FIXED"
assert run_166_adjudication["historical_and_current_disposition"]["bounded_clause"] == "manual POST /emar/controlled/entries register and stock atomicity"
run_166_runtime = run_166_adjudication["runtime_execution"]
run_166_claim_totals = run_166_runtime["claim_specific_totals"]
assert run_166_claim_totals["test_functions_passed"] == 3
assert run_166_claim_totals["assertions_across_command_outputs"] == 146
assert run_166_claim_totals["race_subscenarios"] == 3
assert run_166_runtime["supporting_governance_command"]["tests_passed"] == 43
assert run_166_runtime["supporting_governance_command"]["assertions"] == 716
assert run_166_runtime["cleanup"]["matching_schema_count"] == 0
assert run_166_runtime["cleanup"]["owned_php_processes"] == run_166_runtime["cleanup"]["owned_php_listeners"] == run_166_runtime["cleanup"]["owned_barrier_files"] == 0
assert run_166_adjudication["compound_record_boundary"] == {
    "manual_store_cd_entry_register_stock_atomicity_adjudicated": True,
    "store_balance_check_adjudicated": False,
    "destruction_relationship_checks_adjudicated": False,
    "delivery_stock_adjustment_loss_report_or_sibling_writer_adjudicated": False,
    "transient_deadlock_retry_adjudicated": False,
    "stress_or_repeated_schedule_evidence": False,
    "residual_scope_must_remain_explicit_after_reporting": True,
}
assert {key for key, value in run_166_adjudication["credit_boundary"].items() if value} == {
    "historical_condition_source_confirmed",
    "current_source_already_fixed_adjudication",
    "bounded_med_cd_atomicity_runtime_execution",
    "provisional_claim_retirement_authorized",
}
run_166_payload_without_seal = dict(run_166_adjudication)
run_166_seal = run_166_payload_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_166_payload_without_seal) == run_166_seal == "11f66f0c27fe4143a94451f140a8ae3a293617a6d1357f9180540a0641e05fea"
assert all(value is False for value in run_166_adjudication["completion_boundary"].values())

assert run_166r_review["schema_version"] == "run-166r-independent-med-cd-atomicity-adjudication-review-wave-30-v1"
assert run_166r_review["run_id"] == "RUN-166R-INDEPENDENT-MED-CD-ATOMICITY-01-ADJUDICATION-REVIEW-WAVE-30"
assert run_166r_review["status"] == "GO_EXACT_RUN166_ARTIFACT_REVIEW_RETIREMENT_REPORTING_AUTHORIZED_ZERO_DOWNSTREAM_CREDIT"
assert run_166r_review["decision"]["verdict"] == "GO"
assert run_166r_review["decision"]["blocking_discrepancies"] == 0
assert run_166r_review["decision"]["retirement_reporting_authorized"] is True
assert run_166r_review["decision"]["authorized_reporting_status"] == "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING"
assert run_166r_review["decision"]["required_post_reporting_counts"]["provisional_source_claims"] == 9
assert run_166r_review["decision"]["required_post_reporting_counts"]["historical_already_fixed"] == 2
assert {key for key, value in run_166r_review["credit_boundary"].items() if value} == {"independent_exact_artifact_review_for_retirement_reporting"}
run_166r_payload_without_seal = dict(run_166r_review)
run_166r_seal = run_166r_payload_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_166r_payload_without_seal) == run_166r_seal == "4f4b301aaec50ad2c716cafd1c5c6516aab5a63561bb00746f91af6bd555ab67"
assert all(value is False for value in run_166r_review["completion_boundary"].values())

assert dashboard_run_155["schema_version"] == "run-155-audit-dashboard-verification-wave-26-v1"
assert dashboard_run_155["run_id"] == "RUN-155-AUDIT-DASHBOARD-VERIFICATION-WAVE-26"
assert dashboard_run_155["status"] == "AUDIT_ARTIFACT_RESPONSIVE_LINK_CONSOLE_VERIFICATION_GO_ZERO_APPLICATION_CREDIT"
assert dashboard_run_155["pins"]["checkpoint_commit"] == "df0f3758131433b91da7c6d3cfb485c3d917d7ef"
assert dashboard_run_155["pins"]["receipt_materializer_sha256"] == current_run_155_156r_artifact_pins["generators/materialize-run-155-audit-dashboard-verification-wave-26.py"][0]
assert dashboard_run_155["pins"]["receipt_materializer_blob_id"] == current_run_155_156r_artifact_pins["generators/materialize-run-155-audit-dashboard-verification-wave-26.py"][1]
assert dashboard_run_155["lineage"]["required_artifacts"] == dashboard_run_155["lineage"]["verified_artifacts"] == 12
assert dashboard_run_155["lineage"]["all_current_hashes_match"] is True
assert dashboard_run_155["lineage"]["all_links_and_hashes_visible"] is True
run_155_verification = dashboard_run_155["verification"]
assert run_155_verification["viewports_verified"] == run_155_verification["viewports_required"] == 4
assert run_155_verification["navigation_targets"] == "10/10"
assert run_155_verification["unique_local_links"] == 379
assert run_155_verification["local_link_failures"] == []
assert run_155_verification["anchors"] == "10/10"
assert run_155_verification["duplicate_authored_ids_observed"] == 0
assert run_155_verification["console_warnings"] == run_155_verification["console_errors"] == run_155_verification["page_errors"] == 0
assert run_155_verification["exact_visible_static_boundary_check_count"] == 38
assert all(run_155_verification["exact_visible_static_boundary_checks"].values())
assert {key for key, value in dashboard_run_155["credit_boundary"].items() if value} == {"exact_audit_dashboard_artifact"}
assert all(value is False for value in dashboard_run_155["completion_boundary"].values())
assert dashboard_run_155["artifact_completion_test_met"] is True
assert dashboard_run_155["audit_completion_test_met"] is False

assert run_156_source_receipt["schema_version"] == "run-156-medication-governance-source-main-receipt-wave-27-v1"
assert run_156_source_receipt["run_id"] == "RUN-156-MEDICATION-GOVERNANCE-SOURCE-MAIN-RECEIPT-WAVE-27"
assert run_156_source_receipt["status"] == "TWO_CHECKPOINT_MEDICATION_SOURCE_INTEGRATION_RECEIPT_358_OF_359_MERGE_PATHS_EFFECTIVE_ONE_SUPERSEDED_ZERO_OUTCOME_CREDIT"
run_156_pins = run_156_source_receipt["pins"]
assert run_156_pins["receipt_checkpoint_commit"] == "86b232cb14967c63ff345ac5208ec6d4c379f24f"
assert run_156_pins["historical_merge_commit"] == "cd5d34e6b8aa7e494808745041ec1dfa187dc101"
assert run_156_pins["effective_application_commit"] == "c5c0ad0903d2e2e2229d5d0090fc0a69a2206f0f"
assert run_156_pins["materializer_sha256"] == current_run_155_156r_artifact_pins["generators/materialize-run-156-medication-governance-source-main-receipt-wave-27.py"][0]
assert run_156_pins["materializer_blob_id"] == current_run_155_156r_artifact_pins["generators/materialize-run-156-medication-governance-source-main-receipt-wave-27.py"][1]
assert run_156_pins["run_155_dashboard_materializer"]["sha256"] == current_run_155_156r_artifact_pins["generators/materialize-run-155-audit-dashboard-verification-wave-26.py"][0]
assert run_156_pins["run_155_dashboard_receipt"]["sha256"] == current_run_155_156r_artifact_pins["evidence/browser/current-audit-dashboard-verification-run-155-wave-26.json"][0]
run_156_payload = run_156_source_receipt["historical_merge_payload"]["first_parent_payload"]
assert (run_156_payload["paths"], run_156_payload["added_paths"], run_156_payload["modified_paths"]) == (359, 87, 272)
assert (run_156_payload["lines_added"], run_156_payload["lines_deleted"]) == (76238, 9031)
run_156_effective = run_156_source_receipt["effective_application_checkpoint"]
assert (run_156_effective["historical_merge_payload_blobs_unchanged"], run_156_effective["historical_merge_payload_blobs_superseded"]) == (358, 1)
assert run_156_effective["superseded_merge_payload_paths"] == ["resources/js/pages/my-day/index.tsx"]
assert run_156_effective["effective_payload_path_blob_manifest_sha256"] == "fb0dfc61a391d93887a880426a26cf02f5cc8617396077870ec1456fe6216234"
run_156_my_day = run_156_source_receipt["post_merge_my_day_delta"]
assert (run_156_my_day["path_count"], run_156_my_day["modified_paths"], run_156_my_day["lines_added"], run_156_my_day["lines_deleted"]) == (3, 3, 38, 23)
assert run_156_my_day["transition_manifest_sha256"] == "2577f6f8dec59baa120230aa4a8d5884e0cd01f752b744e54c360118ddbda2cc"
run_156_later = run_156_source_receipt["later_audit_only_lineage"]
assert run_156_later["commits_after_effective_application_checkpoint"] == 3
assert [row["commit"] for row in run_156_later["ordered_commits"]] == [
    "3e9407f9fac197d3ed075782187c35ee11db4d2e",
    "df0f3758131433b91da7c6d3cfb485c3d917d7ef",
    "86b232cb14967c63ff345ac5208ec6d4c379f24f",
]
assert all(row["audit_root_only"] is True for row in run_156_later["ordered_commits"])
assert run_156_later["cumulative_changed_paths"] == 12
assert run_156_later["non_audit_tracked_entries"] == 12784
assert run_156_later["non_audit_tree_manifest_sha256"] == "016f4f12e8482ec11fcfdcaaec793417df35463deb90ee49d0c806e7ca7a0ea2"
assert run_156_later["effective_and_receipt_checkpoint_non_audit_manifests_equal"] is True
run_156_references = run_156_source_receipt["provisional_finding_reference_boundary"]
expected_medication_record_hashes = {
    "MED-RBAC-01": "aa35c543ac25d15d074b344abd6ce8750975717f6c6e229d36986256c5a301ea",
    "MED-CD-SCOPE-01": "dd86bf94f3b4d894e95c56c95a9409ce803b8d82d108cdd3c42f3343e348cd21",
    "MED-CD-ATOMICITY-01": "9ba4f430ee59efea414b42a8633c1c969a2fd4428fbf3fef173fb5548cc8e7f1",
}
assert run_156_references["reference_count"] == 3
assert run_156_references["historical_audited_application_pin"] == "a0493442b9e392d324055c35bf25b69421dc2d35"
assert {row["id"]: row["canonical_record_sha256"] for row in run_156_references["records"]} == expected_medication_record_hashes
assert all(row["record_status"] == "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING" and row["reference_only"] is True and row["promoted_or_rebased_by_run_156"] is False and row["final_finding_credit"] is False and row["completion_credit"] is False for row in run_156_references["records"])
run_156_origin = run_156_source_receipt["local_only_origin_attestation"]
assert run_156_origin["scope_wording"] == "unfetched local remote-tracking observation only; no current remote state, publication, or push is verified"
assert run_156_origin["observed_local_remote_tracking_ref_sha"] == "20ad5cef0aacb3d055e685d2f8b7b583cb8d78f4"
assert (run_156_origin["local_ahead"], run_156_origin["local_behind"]) == (179, 0)
assert run_156_origin["fetch_performed"] is run_156_origin["remote_currency_verified"] is run_156_origin["publication_or_push_verified"] is False
assert {key for key, value in run_156_source_receipt["credit_boundary"].items() if value} == {"GIT_SOURCE_INTEGRATION_RECEIPT"}
assert all(value is False for value in run_156_source_receipt["completion_boundary"].values())
assert run_156_source_receipt["artifact_completion_test_met"] is True
assert run_156_source_receipt["audit_completion_test_met"] is False

assert run_156r_source_review["schema_version"] == "run-156r-independent-medication-governance-source-main-receipt-review-wave-27-v1"
assert run_156r_source_review["run_id"] == "RUN-156R-INDEPENDENT-MEDICATION-GOVERNANCE-SOURCE-MAIN-RECEIPT-REVIEW-WAVE-27"
assert run_156r_source_review["status"] == "GO_THREE_PART_TWO_CHECKPOINT_SOURCE_RECEIPT_REVIEW_REPORTING_ONLY_ZERO_REMEDIATION_OR_DOWNSTREAM_CREDIT"
run_156r_pins = run_156r_source_review["pins"]
assert run_156r_pins["review_checkpoint_commit"] == "33ee55b84944fab3e52eee3c3e303c4c30eb4a44"
assert run_156r_pins["producer_generation_checkpoint_commit"] == "86b232cb14967c63ff345ac5208ec6d4c379f24f"
assert run_156r_pins["producer_generator"] == {
    "path": "generators/materialize-run-156-medication-governance-source-main-receipt-wave-27.py",
    "sha256": "e611f494567ce966e5c678a9579bb26278da0a87d814b649ccf973b102bcd4ea",
    "blob_id": "0caeb16bf63e0d6b4cd084c539a6d74c303d6cfb",
    "bytes": 35600,
    "lines": 779,
}
assert run_156r_pins["producer_receipt"] == {
    "path": "evidence/source/current-run-156-medication-governance-source-main-receipt-wave-27.json",
    "sha256": "56094f7e83acf8000d0b680d751cc3d27e8627916eef45173002b43207091e76",
    "blob_id": "38e69aa0897cc8b8f7d55363f5bc1ed491411095",
    "bytes": 16444,
    "lines": 330,
}
assert run_156r_pins["review_materializer_sha256"] == current_run_155_156r_artifact_pins["generators/materialize-independent-medication-governance-source-main-receipt-review-wave-27.py"][0]
assert run_156r_pins["review_materializer_blob_id"] == current_run_155_156r_artifact_pins["generators/materialize-independent-medication-governance-source-main-receipt-review-wave-27.py"][1]
assert run_156r_source_review["review_process_disclosure"] == {
    "review_record_materializer": "/root",
    "review_records_materialized_by_one_generator": True,
    "reviewer_evidence_lanes": ["/root/run154_builder_review", "/root", "/root/run154_surface_review"],
    "cross_reviewer_coordination_occurred": True,
    "blind_or_isolated_reviews_claimed": False,
    "independence_boundary": "DISTINCT_READ_ONLY_REVIEW_LANES_WITH_COORDINATION_DISCLOSED",
}
assert run_156r_source_review["decision"] == {
    "verdict": "GO",
    "independent_reviews": 3,
    "distinct_reviewer_lanes": 3,
    "blind_or_isolated_reviews": False,
    "discrepancies": 0,
    "reporting_materialization_authorized": True,
    "gate_4_complete": False,
    "audit_complete": False,
}
assert len(run_156r_source_review["review_records"]) == 3
assert {row["reviewer_lane"] for row in run_156r_source_review["review_records"]} == {"/root/run154_builder_review", "/root", "/root/run154_surface_review"}
for row in run_156r_source_review["review_records"]:
    unsigned = {key: value for key, value in row.items() if key != "seal_sha256"}
    assert row["seal_sha256"] == canonical_sha256(unsigned)
    assert row["review_evidence_collection_performed_without_writes"] is True
    assert row["cross_reviewer_coordination_disclosed"] is True
    assert row["record_materialized_by"] == "/root"
    assert row["independence_boundary"] == "DISTINCT_REVIEW_LANE_NOT_BLIND_OR_ISOLATED"
    assert row["verdict"] == "GO" and row["discrepancies"] == 0
assert {key for key, value in run_156r_source_review["credit_boundary"].items() if value} == {"INDEPENDENT_SOURCE_RECEIPT_REVIEW_FOR_REPORTING"}
assert all(value is False for value in run_156r_source_review["completion_boundary"].values())
assert run_156r_source_review["artifact_completion_test_met"] is True
assert run_156r_source_review["audit_completion_test_met"] is False
assert sha256_file("evidence/browser/deployed-selected-feature-observation-wave-03.json") == "b559b2662f6148f31871f57a0aa15e26ac05a7abb235b781f5ac2fd5e99ef290"
assert sha256_file("evidence/browser/raw-run-059b-independent-unknown-build-browser-review-wave-03.json") == "b2eae3ba63ef9f8a39ab9a0a96fd7f6c265bde859a6c0ec4d162f7f9b752f1e0"
assert sha256_file("generators/integrate-deployed-selected-feature-observation-wave-03.py") == "ca190ab113ab5a18e31fe0f533f2ae536410d0663932bd615be384b5ae0c87e3"
assert sha256_file("evidence/browser/current-deployed-selected-feature-observation-wave-03.json") == "e9c95d695212875a756e704ec0754b0d6998476f9e34d6dce166f8e520027fc3"
assert sha256_file("evidence/browser/raw-run-060r-s-independent-browser-normalization-adjudication-wave-03.json") == "32c3aa6deb03e4da94c2dc09b9662fd0f33bea775a9d8e2a93ae4fa6fda49e16"
assert sha256_file("evidence/source/raw-run-071a-completion-gate-accounting-wave-04.json") == "6f481a03a2ebba8fcfeaef15735b37d3137a14bd90977db8f8c566ed0ff9fa7d"
assert sha256_file("evidence/benchmark/raw-run-071b-downstream-mapping-readiness-wave-04.json") == "e737cd336f12e32c4cb0304a8da3b5746dcebf93875ebb9b3376f380f5002832"
assert sha256_file("evidence/browser/raw-run-071c-usability-visual-gap-selector-wave-04.json") == "4579ecef6607ca2ddb74af4c54085f04f7d132ee216cd7910b39bf8379e434dc"
assert sha256_file("evidence/browser/root-run-072-authentication-blocked-frontline-slice-wave-04.json") == "1bbba21a0f700ee490042dba30dc1234b99952d217e3471d199813b5766d0731"
assert sha256_file("evidence/source/raw-run-072-usability-materialization-contract-wave-01.json") == "dd55a43c25f0300947b48146887869c69652fe61ae0c8a1cf823b810e312b8aa"
assert sha256_file("generators/materialize-source-bound-usability-wave-01.py") == "b73e2f85b5571f3bc74fb6cf00d4d2c8c04246fcdcd258388c2b965be2334483"
assert sha256_file("04-workflow-usability-scorecard.csv") == "ea6879340229541c198b5ac654bde6d26d38eaefdd29ff66e1026263f9546faa"
assert sha256_file("evidence/source/current-usability-task-script-materialization-wave-01.json") == "ad0747f9128b2e92da31c1cff5b1acc5de0a6e1687b2879cf9ddc63b74ba68f7"
assert sha256_file("evidence/source/raw-run-072-usability-independent-review-wave-01.json") == "d3af5436e4170a67396a3cb6c919d8b970775a2811a7328685931cbe7dbd5854"
assert sha256_file("evidence/source/raw-run-072-current-source-route-page-ownership-slice-wave-04.json") == "ce1e69b31b331bcbabd7ce583be4f6ca1d936b597854823ec9620cd722d5ecf9"
assert sha256_file("evidence/benchmark/raw-run-072-agent-a-incident-observed-behavior-wave-04.json") == "c8b513225613053253207d457a0556e9888510950ec53534d8d23c85ec51e8b1"
assert sha256_file("evidence/benchmark/sealed-run-072-agent-b-input-wave-04.json") == "9036d2e1faf9c53b727665cdfa23e3c79ef736249550768806830b7b4999b4b5"
assert sha256_file("evidence/benchmark/raw-run-072-agent-b-neutral-incident-requirements-wave-04.json") == "425f9c38320e37915e5ceff33a4f65b8d96b8183cb6e2b70955e07b1145e8c97"
assert sha256_file("evidence/benchmark/sealed-run-072-agent-c-incident-comparison-input-wave-04.json") == "8090a913c2ddda885d4175bb44fb8c49b8bb997af4bd97b97e4bb124990371e3"
assert sha256_file("evidence/benchmark/raw-run-072-agent-c-incident-current-comparison-wave-04.json") == "948da9127b609ea3737a9eeeaa90c5e5c2e053dff9e6a2845cc4633363dee4eb"
assert sha256_file("evidence/benchmark/sealed-run-072-agent-d-incident-adjudication-input-wave-04.json") == "6e53ebdadf67af0ff68152c6c488740a80422cf9e2222acce4d518cc2e53e8c8"
assert sha256_file("evidence/benchmark/raw-run-072-agent-d-incident-adjudication-wave-04.json") == "e70433e5130a7ffb914a3f96c86f943511489d757452a2b83c0a2f7f7530fb6d"
assert artifact_contract["run_id"] == "RUN-073A"
assert len(artifact_contract["completion_gates"]) == 26
assert journey_evidence["counts"] == {
    "journeys": 8,
    "handoffs": 44,
    "PROVEN": 27,
    "PARTIAL": 8,
    "NOT_ESTABLISHED": 9,
    "provisional_source_candidates": 12,
}
assert journey_review["run_id"] == "RUN-073D"
assert journey_review["input"]["sha256"] == sha256_file("evidence/source/raw-run-073b-cross-module-journeys-wave-05.json")
assert journey_review["validated_totals"]["fresh_independent_source_reviews"] == 8
assert journey_review["validated_totals"]["prompt_grade_completed_journeys"] == 0
assert architecture_evidence["run_id"] == "RUN-073C"
assert architecture_evidence["counts"]["entity_families"] == 13
assert architecture_evidence["counts"]["technical_concerns"] == 17
assert architecture_evidence["counts"]["provisional_claims"] == 9
assert architecture_evidence["counts"]["provisional_P1"] == 7
assert architecture_evidence["counts"]["provisional_P2"] == 2
assert architecture_evidence["counts"]["not_established_items"] == 10
assert architecture_evidence["counts"]["final_findings"] == 0
assert architecture_evidence["counts"]["runtime_confirmed_findings"] == 0
assert reporting_materialization["run_id"] == "RUN-073-REPORTING-MATERIALIZATION"
assert reporting_materialization["counts"]["source_reconstructed_journeys"] == 8
assert reporting_materialization["counts"]["fresh_independent_source_reviewed_journeys"] == 8
assert reporting_materialization["counts"]["prompt_grade_completed_journeys"] == 0
assert reporting_materialization["counts"]["canonical_entity_families"] == 13
assert reporting_materialization["counts"]["technical_concerns"] == 17
assert reporting_materialization["counts"]["architecture_provisional_claims"] == 9
assert reporting_materialization["counts"]["architecture_not_established_items"] == 10
assert all(reporting_materialization["credit_boundary"][key] is False for key in ("final_finding", "browser", "ease", "benchmark_mapping", "final_no_match", "pass", "completion"))
assert sha256_file("evidence/source/current-required-reporting-materialization-wave-05.json") == "5fc76430b6a33f76182a470dfce774415c1d0618df14c6685c66105eef026c51"
assert reporting_materialization["inputs"]["03-feature-to-benchmark-matrix.csv"] == "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4"
assert architecture_review["run_id"] == "RUN-073E"
assert architecture_review["status"] == "INDEPENDENT_ARCHITECTURE_SOURCE_AND_REPORT_REVIEW_GO"
assert all(sha256_file(path) == digest for path, digest in architecture_review["inputs"].items())
assert architecture_review["anchor_validation"] == {
    "serialized_anchor_occurrences": 75,
    "distinct_anchor_ranges": 70,
    "distinct_application_paths": 47,
    "missing_or_invalid": 0,
}
assert reporting_review["run_id"] == "RUN-073F"
assert reporting_review["status"] == "INDEPENDENT_REPORTING_MATERIALIZATION_REVIEW_GO"
assert sha256_file("evidence/source/raw-run-073f-independent-reporting-materialization-review-wave-05.json") == "a97d3b9810dd6298b3c46bfd40b6dd23ed1e747be43be097006bc19750fb9f5d"
assert reporting_review["checks"]["materialization_inputs_matching"] == "18/18"
assert reporting_review["checks"]["materialization_outputs_matching"] == "7/7"
assert reporting_review["checks"]["hash_mismatches"] == 0

assert static_linkage_producer["run_id"] == "RUN-074-STATIC-LINKAGE-NORMALIZATION"
assert static_linkage_producer["status"] == "PRODUCER_SOURCE_RECONSTRUCTION_NORMALIZED_PENDING_INDEPENDENT_REVIEW_ZERO_CREDIT"
assert static_linkage_producer["counts"]["targets"] == 288
assert static_linkage_producer["counts"]["invalid_anchors"] == 0
assert sum(len(row["original_missing_fields"]) for row in static_linkage_producer["records"]) == 503
assert set(static_linkage_producer["inputs"]) == {
    "evidence/source/root-run-074-static-linkage-gap-partitions-wave-06.json",
    "evidence/source/raw-run-074a-static-linkage-review-wave-06.json",
    "evidence/source/raw-run-074b-static-linkage-review-wave-06.json",
    "evidence/source/raw-run-074c-static-linkage-review-wave-06.json",
}
assert all(sha256_file(path) == digest for path, digest in static_linkage_producer["inputs"].items())
assert static_linkage_producer["pins"]["partition_manifest_sha256"] == static_linkage_producer["inputs"]["evidence/source/root-run-074-static-linkage-gap-partitions-wave-06.json"]
assert static_linkage_review["run_id"] == "RUN-075-STATIC-LINKAGE-INDEPENDENT-REVIEW"
assert static_linkage_review["status"] == "CYCLIC_INDEPENDENT_SOURCE_REVIEW_NORMALIZED_PENDING_MATRIX_INTEGRATION_ZERO_DOWNSTREAM_CREDIT"
assert static_linkage_review["counts"]["targets"] == 288
assert static_linkage_review["counts"]["invalid_final_anchors"] == 0
assert static_linkage_review["pins"]["normalized_producer_sha256"] == sha256_file("evidence/source/current-static-linkage-review-wave-06.json")
assert set(static_linkage_review["inputs"]) == {
    "evidence/source/current-static-linkage-review-wave-06.json",
    "evidence/source/raw-run-075a-independent-static-linkage-review-wave-06.json",
    "evidence/source/raw-run-075b-independent-static-linkage-review-wave-06.json",
    "evidence/source/raw-run-075c-independent-static-linkage-review-wave-06.json",
}
assert all(sha256_file(path) == digest for path, digest in static_linkage_review["inputs"].items())
assert static_linkage_integration["run_id"] == "RUN-076-STATIC-LINKAGE-INTEGRATION"
assert static_linkage_integration["status"] == "INDEPENDENTLY_REVIEWED_FEATURE_SIDE_STATIC_LINKAGE_INTEGRATED_ZERO_DOWNSTREAM_CREDIT"
assert static_linkage_integration["pins"]["base_matrix_sha256"] == "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4"
assert static_linkage_integration["pins"]["independent_review_sha256"] == sha256_file("evidence/source/current-static-linkage-independent-review-wave-06.json")
assert static_linkage_integration["matrix"]["updated_sha256"] == "00085d407433307e7f6798c0e8e04629b1746d4bfb1e18024c51ead1dc4f7afd"
assert static_linkage_integration["matrix"]["base_immutable_projection_sha256"] == static_linkage_integration["matrix"]["updated_immutable_projection_sha256"]
assert static_linkage_integration["matrix"]["base_benchmark_and_credit_projection_sha256"] == static_linkage_integration["matrix"]["updated_benchmark_and_credit_projection_sha256"]
assert all(static_linkage_integration["counts"][key] == 0 for key in ("benchmark_mapping_credit", "runtime_credit", "browser_credit", "executed_test_credit", "pass_credit", "completion_credit"))
assert static_linkage_reporting["run_id"] == "RUN-076-STATIC-LINKAGE-REPORTING-MATERIALIZATION"
assert static_linkage_reporting["status"] == "CURRENT_STATIC_LINKAGE_REPORTING_REFRESHED_ZERO_DOWNSTREAM_CREDIT"
assert static_linkage_reporting["pins"]["updated_matrix_sha256"] == static_linkage_integration["matrix"]["updated_sha256"]
for linkage_evidence in (
    static_linkage_producer,
    static_linkage_review,
    static_linkage_integration,
    static_linkage_reporting,
):
    assert linkage_evidence["pins"]["application_commit"] == canonical["source_pin"]["application_commit"]
    assert linkage_evidence["pins"]["application_tree"] == canonical["source_pin"]["application_tree"]
assert static_linkage_producer["pins"]["matrix_sha256"] == "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4"
assert static_linkage_review["pins"]["base_matrix_sha256"] == "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4"
assert static_linkage_reporting["pins"]["normalized_producer_sha256"] == sha256_file("evidence/source/current-static-linkage-review-wave-06.json")
assert static_linkage_reporting["pins"]["independent_review_sha256"] == sha256_file("evidence/source/current-static-linkage-independent-review-wave-06.json")
assert static_linkage_reporting["pins"]["integration_sha256"] == sha256_file("evidence/source/current-static-linkage-integration-wave-06.json")
expected_static_linkage_history = {
    "evidence/source/current-required-reporting-materialization-wave-05.json": "5fc76430b6a33f76182a470dfce774415c1d0618df14c6685c66105eef026c51",
    "evidence/source/raw-run-073f-independent-reporting-materialization-review-wave-05.json": "a97d3b9810dd6298b3c46bfd40b6dd23ed1e747be43be097006bc19750fb9f5d",
    "evidence/source/current-run-073-checkpoint-validation-wave-05.json": "827829d1b48ef804b81a42ade614f8f09de6bab11d39e732d5ed20bc4b8fbc1f",
    "evidence/browser/current-audit-dashboard-verification-run-073-wave-05.json": "814a53449a96abb7a821711ab33a7111d83e64b897ea3cb42ded2448178efdf7",
    "generators/materialize-required-reporting-wave-05.py": "2275a5ef8159a43b4106f13259452dbf376ef566c75de8ffb6550f2b397fd170",
}
assert static_linkage_reporting["history"] == {
    path: {"sha256": digest, "rewritten": False}
    for path, digest in expected_static_linkage_history.items()
}
assert all(sha256_file(path) == digest for path, digest in expected_static_linkage_history.items())
expected_static_linkage_reporting_inputs = (
    set(reporting_materialization["inputs"])
    - {
        "04-workflow-usability-scorecard.csv",
        "evidence/source/current-usability-task-script-materialization-wave-01.json",
    }
) | {
    "evidence/source/current-static-linkage-review-wave-06.json",
    "evidence/source/current-static-linkage-independent-review-wave-06.json",
    "evidence/source/current-static-linkage-integration-wave-06.json",
}
assert set(static_linkage_reporting["inputs"]) == expected_static_linkage_reporting_inputs
assert all(
    sha256_file(path) == digest
    for path, digest in static_linkage_reporting["inputs"].items()
    if path not in CURRENT_RUN_146_MUTATED_PATHS
)
assert set(static_linkage_reporting["generators"]) == {
    "generators/materialize-required-reporting-staged-wave-06.py",
    "generators/materialize-static-linkage-reporting-wave-06.py",
}
assert all(sha256_file(path) == digest for path, digest in static_linkage_reporting["generators"].items())
assert set(static_linkage_reporting["outputs"]) == {
    "00-executive-summary.md",
    "01-repository-module-map.md",
    "07-module-findings.md",
    "08-cross-module-journeys.md",
    "09-ui-ux-accessibility-visual-consistency.md",
    "10-architecture-data-integration-security.md",
    "11-prioritised-roadmap.md",
    "12-native-build-and-do-not-copy-register.md",
    "13-unresolved-questions-and-evidence-gaps.md",
    "findings.json",
}
assert static_linkage_reporting["credit_boundary"] == {
    "artifact_presence": False,
    "final_finding": False,
    "application_browser": False,
    "runtime": False,
    "executed_tests": False,
    "ease": False,
    "benchmark_mapping": False,
    "final_no_match": False,
    "pass": False,
    "completion": False,
    "audit_complete": False,
}
assert static_linkage_reporting["evidence_boundary"] == {
    "feature_side_static_linkage_cells_integrated": True,
    "framework_route_reachability": False,
    "complete_route_page_universe_mapping": False,
    "run_072_locator_refresh": False,
}

assert sha256_file("evidence/source/current-static-linkage-reporting-materialization-wave-06.json") == "04d5fd61048c2c877f6bdba3785fa46365ba85464649eb1b83779cc0daf39906"
assert route_page_manifest["run_id"] == "RUN-077-ROUTE-PAGE-UNIVERSE-MANIFEST"
assert route_page_manifest["pins"]["application_commit"] == canonical["source_pin"]["application_commit"]
assert route_page_manifest["pins"]["application_tree"] == canonical["source_pin"]["application_tree"]
assert route_page_manifest["counts"]["primary_route_facade_callsites"] == 3217
assert route_page_manifest["counts"]["route_like_sentinels_outside_primary_denominator"] == 1
assert route_page_manifest["counts"]["static_route_like_review_rows"] == 3218
assert route_page_manifest["counts"]["fluent_name_callsites"] == 3245
assert route_page_manifest["counts"]["page_roots"] == 711
assert route_page_producer["run_id"] == "RUN-078-ROUTE-PAGE-CLASSIFICATION-NORMALIZATION"
assert route_page_producer["pins"]["manifest_sha256"] == sha256_file("evidence/source/root-run-077-route-page-universe-manifest-wave-07.json")
assert route_page_producer["counts"]["route_decisions"] == 3218
assert route_page_producer["counts"]["name_decisions"] == 3245
assert route_page_producer["counts"]["page_decisions"] == 711
assert route_page_review["run_id"] == "RUN-079-ROUTE-PAGE-INDEPENDENT-REVIEW-NORMALIZATION"
assert route_page_review["status"] == "THREE_PART_CYCLIC_INDEPENDENT_REVIEW_GO_ZERO_DOWNSTREAM_CREDIT"
assert route_page_review["pins"]["normalized_producer_sha256"] == sha256_file("evidence/source/current-route-page-classification-wave-07.json")
assert route_page_review["counts"]["go_reviews"] == 3
assert route_page_review["counts"]["invalid_decisions"] == 0
assert route_page_review["counts"]["review_artifacts_wrote_files"] == 0
assert route_page_review["review_gate"]["independent_cyclic_review_complete"] is True
assert route_page_review["review_gate"]["static_matrix_field_integration_authorized"] is True
assert route_page_review["review_gate"]["other_downstream_integration_authorized"] is False
assert route_page_integration["run_id"] == "RUN-080-ROUTE-PAGE-STATIC-LINKAGE-INTEGRATION"
assert route_page_integration["status"] == "INDEPENDENTLY_REVIEWED_ROUTE_PAGE_STATIC_LINKAGE_INTEGRATED_ZERO_DOWNSTREAM_CREDIT"
assert route_page_integration["pins"]["normalized_producer_sha256"] == sha256_file("evidence/source/current-route-page-classification-wave-07.json")
assert route_page_integration["pins"]["independent_review_sha256"] == sha256_file("evidence/source/current-route-page-independent-review-wave-07.json")
assert route_page_integration["matrix"]["base_sha256"] == static_linkage_integration["matrix"]["updated_sha256"]
assert route_page_integration["matrix"]["updated_sha256"] == HISTORICAL_RUN_080_MATRIX_SHA256
assert route_page_integration["matrix"]["base_immutable_projection_sha256"] == route_page_integration["matrix"]["updated_immutable_projection_sha256"]
assert route_page_integration["matrix"]["base_benchmark_and_credit_projection_sha256"] == route_page_integration["matrix"]["updated_benchmark_and_credit_projection_sha256"]
assert route_page_integration["counts"]["matrix_rows_changed"] == 79
assert route_page_integration["counts"]["matrix_field_changes"] == 80
assert route_page_integration["counts"]["field_changes"] == {"page_files": 2, "route_names": 78}
assert all(route_page_integration["counts"][key] == 0 for key in ("benchmark_mapping_credit", "runtime_credit", "browser_credit", "executed_test_credit", "pass_credit", "completion_credit"))
assert route_page_reporting["run_id"] == "RUN-081-ROUTE-PAGE-REPORTING-MATERIALIZATION"
assert route_page_reporting["status"] == "CURRENT_ROUTE_PAGE_REPORTING_REFRESHED_ZERO_DOWNSTREAM_CREDIT"
assert route_page_reporting["pins"]["manifest_sha256"] == sha256_file("evidence/source/root-run-077-route-page-universe-manifest-wave-07.json")
assert route_page_reporting["pins"]["normalized_producer_sha256"] == sha256_file("evidence/source/current-route-page-classification-wave-07.json")
assert route_page_reporting["pins"]["independent_review_sha256"] == sha256_file("evidence/source/current-route-page-independent-review-wave-07.json")
assert route_page_reporting["pins"]["integration_sha256"] == sha256_file("evidence/source/current-route-page-static-linkage-integration-wave-07.json")
assert route_page_reporting["pins"]["updated_matrix_sha256"] == HISTORICAL_RUN_080_MATRIX_SHA256
assert all(sha256_file(path) == digest for path, digest in route_page_reporting["inputs"].items() if path not in CURRENT_RUN_146_MUTATED_PATHS)
assert all(sha256_file(path) == row["sha256"] for path, row in route_page_reporting["artifact_register"].items() if path not in CURRENT_RUN_146_MUTATED_PATHS)
assert all(sha256_file(path) == digest for path, digest in route_page_reporting["generator"].items())
assert sha256_file("evidence/source/current-route-page-reporting-materialization-wave-07.json") == "d075bc06da962d932351cb653f3a34dd88cbfc6272488fe06bc26ab61c80e55a"
assert all(value is False for value in route_page_reporting["credit_boundary"].values())

assert route_page_candidate["run_id"] == "RUN-082-EXACT-OWNER-CONTAINMENT-CANDIDATE-CENSUS"
assert route_page_candidate["status"] == "STATIC_CANDIDATE_RELATIONS_MATERIALIZED_PENDING_INDEPENDENT_REVIEW_ZERO_CREDIT"
assert route_page_candidate["pins"]["checkpoint_commit"] == "35a5228b26c54684718495c33281b24c0992de02"
assert route_page_candidate["pins"]["checkpoint_tree"] == "8ba4e28575cdb53682824a9ae604c718646d8a18"
assert route_page_candidate["pins"]["application_commit"] == canonical["source_pin"]["application_commit"]
assert route_page_candidate["pins"]["application_tree"] == canonical["source_pin"]["application_tree"]
assert route_page_candidate["pins"]["matrix_sha256"] == HISTORICAL_RUN_080_MATRIX_SHA256
assert route_page_candidate["pins"]["generator_sha256"] == sha256_file(route_page_candidate["pins"]["generator"])
assert sha256_file("evidence/source/root-run-082-exact-owner-containment-candidate-manifest-wave-08.json") == "3c6ad4df13a09ae8ff8f19aee09a1907f9754a1877806659676c6c2898652f85"
assert route_page_candidate["counts"]["unresolved_route_like_records"] == 3003
assert route_page_candidate["counts"]["route_exact_class_method_arrays_resolved"] == 2879
assert route_page_candidate["counts"]["route_non_exact_class_method_array_records"] == 124
assert route_page_candidate["counts"]["page_evidence_gap_records"] == 393
assert route_page_candidate["counts"]["static_route_files_represented"] == 38
assert route_page_candidate["counts"]["final_feature_mappings"] == 0
assert route_page_candidate["counts"]["framework_routes_executed"] == 0
assert {key: row["count"] for key, row in route_page_candidate["route_static_candidate_census"]["exact_route_name_cardinalities"].items()} == {"zero": 2430, "one": 527, "many": 46}
assert {key: row["count"] for key, row in route_page_candidate["route_static_candidate_census"]["controller_method_containment_cardinalities_resolved_2879"].items()} == {"zero": 2214, "one": 610, "many": 55}
assert {key: row["count"] for key, row in route_page_candidate["page_static_candidate_census"]["render_owner_containment_cardinalities"].items()} == {"zero": 348, "one": 43, "many": 2}
assert route_page_candidate["static_route_registration_closure"]["counts"] == {
    "route_files_in_manifest": 38,
    "direct_bootstrap_surfaces": 5,
    "web_required_surfaces": 33,
    "represented_route_files": 38,
    "missing_route_files": 0,
    "extra_route_files": 0,
    "framework_route_tables_executed": 0,
}
assert route_page_candidate["static_route_registration_closure"]["framework_route_reachability"] == "NOT_EXECUTED"
assert route_page_candidate["review_contract"]["review_status"] == "PENDING"
assert not any(route_page_candidate["completion_boundary"].values())
assert not any(route_page_candidate["credit_boundary"].values())

assert route_page_candidate_review["run_id"] == "RUN-082R-INDEPENDENT-EXACT-OWNER-CONTAINMENT-REVIEW"
assert route_page_candidate_review["status"] == "GO_STATIC_CANDIDATE_CENSUS_REVIEWED_ZERO_DOWNSTREAM_CREDIT"
assert sha256_file("evidence/source/raw-run-082r-independent-exact-owner-containment-review-wave-08.json") == "a6a4f886ca209bc41ffa86afec37f6bddaf062ac80a6b375391adeea20e1c396"
assert route_page_candidate_review["pins"]["run_082_output_sha256"] == "3c6ad4df13a09ae8ff8f19aee09a1907f9754a1877806659676c6c2898652f85"
assert route_page_candidate_review["pins"]["run_082_generator_sha256"] == route_page_candidate["pins"]["generator_sha256"]
assert route_page_candidate_review["verdict"] == {
    "decision": "GO",
    "scope": "STATIC_CANDIDATE_RELATIONS_AND_STATIC_ROUTE_REGISTRATION_CLOSURE_ONLY",
    "feature_mapping_authorized": False,
    "matrix_mutation_authorized": False,
    "downstream_integration_authorized": False,
}
assert route_page_candidate_review["checks"]["review_discrepancies"] == 0
assert route_page_candidate_review["mutation_attestation"]["review_execution_wrote_files"] is False
assert not any(route_page_candidate_review["credit_boundary"].values())

assert route_page_candidate_reporting["run_id"] == "RUN-083-ROUTE-PAGE-CANDIDATE-REPORTING-MATERIALIZATION"
assert route_page_candidate_reporting["status"] == "CURRENT_CANDIDATE_CENSUS_REPORTING_REFRESHED_ZERO_DOWNSTREAM_CREDIT"
assert route_page_candidate_reporting["pins"]["checkpoint_commit"] == route_page_candidate["pins"]["checkpoint_commit"]
assert route_page_candidate_reporting["pins"]["checkpoint_tree"] == route_page_candidate["pins"]["checkpoint_tree"]
assert route_page_candidate_reporting["pins"]["candidate_census_sha256"] == sha256_file("evidence/source/root-run-082-exact-owner-containment-candidate-manifest-wave-08.json")
assert route_page_candidate_reporting["pins"]["candidate_independent_review_sha256"] == sha256_file("evidence/source/raw-run-082r-independent-exact-owner-containment-review-wave-08.json")
assert route_page_candidate_reporting["pins"]["matrix_sha256"] == HISTORICAL_RUN_080_MATRIX_SHA256
assert sha256_file("evidence/source/current-route-page-candidate-reporting-materialization-wave-08.json") == "cac8c8d96d8d4efdf1091344a0defe0539ffa772657e4f7b301638387c377193"
assert sha256_file("generators/materialize-route-page-candidate-reporting-wave-08.py") == "3bb43f2cb852a5cc6656682f78605470b1d1af5f1268b9780cc5a76701d856f1"
assert all(sha256_file(path) == digest for path, digest in route_page_candidate_reporting["inputs"].items() if path not in CURRENT_RUN_146_MUTATED_PATHS)
assert all(sha256_file(path) == row["sha256"] for path, row in route_page_candidate_reporting["artifact_register"].items() if path not in CURRENT_RUN_146_MUTATED_PATHS)
assert all(sha256_file(path) == digest for path, digest in route_page_candidate_reporting["generator"].items())
assert route_page_candidate_reporting["outputs"] == {
    "00-executive-summary.md": "613e5a75e40bfb4dc2102765cbd50bf9848c2e6245a4499e98682e5ab6537c64",
    "01-repository-module-map.md": "cc269f4ea038eb5a8003a3f30f7e77030ee1598c921268a1c4e0eab16cfb2dd4",
    "07-module-findings.md": "5a8de7d5c9e181d8da0425e7f040e8744dd85cbfda16573ef824ce3219f85712",
    "08-cross-module-journeys.md": "ef4471ba75ac9080e4565989e4b038bf7d0ad306cad1984019882457517c853c",
    "09-ui-ux-accessibility-visual-consistency.md": "b91ce38abc9b5babb9e590641bd7b9bdd7efe6338f4b697060de1f9714b59983",
    "10-architecture-data-integration-security.md": "ca5667b1c042024f32f320254baf063dd4bcd2c4b12972cf2aac29c02d782b22",
    "11-prioritised-roadmap.md": "e5c2f41bf98d3415de97d18d853f1d7c351b337ba544fbf8c81330ec63dcf02d",
    "12-native-build-and-do-not-copy-register.md": "44ae85422a6863d4804fec7d495107b9bdc937257f023767fb306ccd755e137a",
    "13-unresolved-questions-and-evidence-gaps.md": "6308efcb77fe878df84121657164267a0c91354f7a23c88ae9088ec5f76e70dc",
    "findings.json": "90eb05b92bc94cba7414aa09445f32ea757d0bc59713652b287fb309df85f5b4",
}
assert route_page_candidate_reporting["matrix_validation"]["before_sha256"] == route_page_candidate_reporting["matrix_validation"]["after_sha256"]
assert route_page_candidate_reporting["matrix_validation"]["rows_changed"] == 0
assert route_page_candidate_reporting["matrix_validation"]["cells_changed"] == 0
assert not any(route_page_candidate_reporting["credit_boundary"].values())

assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-083-wave-08.json") == "e0f7f40b3d49492368ff930d163f8d677bb52f93b848a09126ab12b97a9572ef"
assert dashboard_run_083["run_id"] == "RUN-083-AUDIT-DASHBOARD-VERIFICATION"
assert dashboard_run_083["status"] == "AUDIT_ARTIFACT_RESPONSIVE_LINK_CONSOLE_VERIFICATION_GO_ZERO_APPLICATION_CREDIT"
assert dashboard_run_083["pins"]["dashboard_html_sha256"] == "fb58b937c99542e48f3f449c293720a8805b5a7484d0c8b86057b08c5edbb8e3"
assert dashboard_run_083["pins"]["dashboard_generator_sha256"] == "665a3b5d367e777683681fa84d262131a1effdbdf13f14624b0379700670c91a"

assert sha256_file("evidence/browser/current-designated-application-access-preflight-run-084-wave-09.json") == "5422f40ee5663b3d4765f81d8b298e6338f6d0bcb763c761e97b06afa0a5effd"
assert designated_app_preflight["run_id"] == "RUN-084-DESIGNATED-APPLICATION-ACCESS-PREFLIGHT"
assert designated_app_preflight["status"] == "BLOCKED_AUTHENTICATION_AND_BUILD_ATTRIBUTION_CONTINUE_STATIC_AUDIT"
assert designated_app_preflight["access_preflight"]["current_browser_session_authenticated"] is False
assert designated_app_preflight["safe_browser_observations"][1]["page_level_horizontal_overflow"] is False
assert designated_app_preflight["safe_browser_observations"][1]["console_warning_count"] == 0
assert designated_app_preflight["safe_browser_observations"][1]["console_error_count"] == 0
assert designated_app_preflight["mutation_attestation"]["application_or_external_state_changed"] is False
assert not any(
    designated_app_preflight["credit_boundary"][key]
    for key in (
        "non_production_environment_proof",
        "deployed_build_attribution",
        "signed_in_application_browser",
        "representative_role_or_site_coverage",
        "route_or_feature_browser_coverage",
        "responsive_family_or_journey_coverage",
        "workflow_or_ease_credit",
        "runtime_credit",
        "test_credit",
        "pass_credit",
        "completion_credit",
        "audit_complete",
    )
)

assert sha256_file("generators/build-full-inertia-page-graph-wave-09.py") == "6917252a65c09cb894c0d275d00a770b0f451cb6ff26dc78fbfd2661d81c52e6"
assert sha256_file("evidence/source/root-run-084-full-inertia-page-graph-wave-09.json") == "f3856a7a86cd236684e223713a99dd64b18df692338e5d7aba688701b7c438f9"
assert sha256_file("evidence/source/raw-run-084r-independent-full-inertia-page-graph-review-wave-09.json") == "036394a207f6f31c336f748bae9daed75d86549529de538510374149d56f506e"
assert page_graph["run_id"] == "RUN-084-PAGE-GRAPH"
assert page_graph["pins"]["generator_sha256"] == sha256_file("generators/build-full-inertia-page-graph-wave-09.py")
assert page_graph_review["status"] == "GO_STATIC_FULL_PAGE_TREE_CANDIDATE_CENSUS_ZERO_DOWNSTREAM_CREDIT"
assert page_graph_review["decision"]["discrepancies"] == 0
assert page_graph["denominators"]["page_tree_files"] == 1058
assert page_graph["denominators"]["production_non_test_tsx"] == 963
assert page_graph["denominators"]["production_partition_sum"] == 963
assert page_graph["denominators"]["literal_rendered_page_roots"] == 711
assert page_graph["denominators"]["imported_support_components"] == 227
assert page_graph["denominators"]["adjudicated_unrendered_unimported_non_roots"] == 25
assert page_graph["classification"]["production_non_test_tsx_classified"] == 963
assert all(
    page_graph_review["credit_boundary"][key] is False
    for key in (
        "feature_mapping_credit",
        "framework_route_credit",
        "build_credit",
        "application_browser_credit",
        "runtime_credit",
        "executed_test_credit",
        "usability_credit",
        "pass_credit",
        "completion_credit",
        "audit_complete",
    )
)

assert sha256_file("generators/build-run-084b-backend-semantic-classification-wave-09.py") == "6996e2e9ac957af2af921346cab07edbd797c7077014ddd2e1d39272141f4fc4"
assert sha256_file("evidence/source/root-run-084b-backend-semantic-classification-wave-09.json") == "ff1bf008d6dd9d5d478b14328415a4c8187b6e09fa9e2ef57bea8daeec7de879"
assert backend_semantic["run_id"] == "RUN-084B"
assert backend_semantic["pins"]["generator_sha256"] == sha256_file("generators/build-run-084b-backend-semantic-classification-wave-09.py")
assert backend_semantic["denominators"]["total_role_rows"] == 1789
assert backend_semantic["denominators"]["unique_source_paths"] == 1755
assert backend_semantic["denominators"]["async_role_rows"] == 197
assert backend_semantic["denominators"]["async_unique_paths"] == 189
assert backend_semantic["classification"]["whole_file_semantically_reviewed"] == 0
assert backend_semantic_review["status"] == "GO_STATIC_BACKEND_CANDIDATE_CLASSIFICATION_ZERO_DOWNSTREAM_CREDIT"
assert backend_semantic_review["decision"]["discrepancies"] == 0
assert backend_semantic_review["pins"]["producer_sha256"] == sha256_file("evidence/source/root-run-084b-backend-semantic-classification-wave-09.json")
assert backend_semantic_review["pins"]["generator_sha256"] == sha256_file("generators/build-run-084b-backend-semantic-classification-wave-09.py")
assert all(
    backend_semantic_review["credit_boundary"][key] is False
    for key in (
        "whole_file_semantic_review_credit",
        "feature_mapping_credit",
        "framework_reachability_credit",
        "runtime_credit",
        "database_credit",
        "executed_test_credit",
        "application_browser_credit",
        "benchmark_credit",
        "ease_credit",
        "pass_credit",
        "final_finding_credit",
        "completion_credit",
        "audit_complete",
    )
)

assert sha256_file("generators/build-reviewed-static-route-page-feature-ownership-wave-10.py") == "d6933ea5d4078cc8de459552d43a471d57974cb271977654fdb4d8866b387567"
assert sha256_file("evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json") == "bfec52a9bed7176045457e8920bdc7b65e1c1d5c5eeb28a266f49c12acf69bcf"
assert sha256_file("evidence/source/raw-run-086r-independent-reviewed-static-route-page-feature-ownership-wave-10.json") == "56c4832af941353aaf230ca17c792ea7191c6aebfc05bc1c511a757d5998d699"
assert static_source_ownership["record_set"]["count"] == 530
assert static_source_ownership["counts"]["selected"]["route_records"] == 212
assert static_source_ownership["counts"]["selected"]["page_records"] == 318
assert static_source_ownership["counts"]["selected"]["distinct_feature_ids"] == 235
assert static_source_ownership["counts"]["source_universe"]["denominator_complete"] is False
assert static_source_ownership_review["decision"]["verdict"] == "GO"
assert static_source_ownership_review["decision"]["discrepancies"] == 0
assert static_source_ownership_review["decision"]["static_source_feature_ownership_authorized"] is True
assert static_source_ownership_review["decision"]["gate_4_complete"] is False
assert static_source_ownership_review["credit_boundary"]["STATIC_SOURCE_FEATURE_OWNERSHIP"] is True
assert all(
    static_source_ownership_review["credit_boundary"][key] is False
    for key in (
        "feature_mapping_complete",
        "framework_route_reachability",
        "runtime",
        "database",
        "build",
        "application_browser",
        "executed_tests",
        "benchmark_mapping",
        "ease",
        "pass",
        "final_finding",
        "completion",
        "audit_complete",
    )
)

assert sha256_file("evidence/browser/current-designated-application-access-preflight-run-089-wave-11.json") == "d9c47be6e4f7f2c1f179548321d674c104d56b94f6920d8c9256b0727369e3f8"
assert sha256_file("generators/build-direct-exact-route-page-review-queue-wave-11.py") == "73b12d328cfee86631670b0b6b6a9bb6e7cc4ee45380af1136d361584f6d241d"
assert sha256_file("evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json") == "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5"
assert sha256_file("generators/build-closed-route-action-page-chain-cohort-wave-11.py") == "68c47e238fa0ab11971b867bebe56ca8b5ffe93429ef0d2a026881d55f29d9a9"
assert sha256_file("evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json") == "7b1fea57104e9f4bfea7ee450ef535ac57e7fa69bc978212b4eafb0fe1ed0cae"
assert sha256_file("evidence/source/raw-run-091r-independent-closed-route-action-page-chain-review-wave-11.json") == "fb88ca666bc9f91298ab33fefa1dadbb39a4a612215fca814932f59bfc2f199b"
assert sha256_file("generators/integrate-reviewed-static-source-ownership-overlay-wave-11.py") == "100921c48ea9588af96ec47231055b6ce15877f30a38dc479cf15ff1ef7be1f3"
assert sha256_file("evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json") == "5390e55d9e47d1845afa8b3848bdab7687747cbb62c946dd4d66d3c435e8de0b"
assert sha256_file("evidence/source/raw-run-092r-independent-reviewed-static-source-ownership-overlay-wave-11.json") == "1111d30aa24935116c37f27bead824ca1bcca7444157e456d959e821af00669a"
assert current_designated_app_preflight["run_id"] == "RUN-089-DESIGNATED-APPLICATION-ACCESS-PREFLIGHT"
assert current_designated_app_preflight["access_preflight"]["current_browser_session_authenticated"] is False
assert current_designated_app_preflight["mutation_attestation"]["application_or_external_state_changed"] is False
assert direct_exact_review_queue["record_set"]["count"] == 507
assert closed_chain_cohort["counts"]["chains"] == 11
assert closed_chain_review["decision"]["owner_chains"] == 9
assert closed_chain_review["decision"]["shared_relation_chains"] == 2
assert reviewed_owner_overlay["combined_counts"]["source_owner_records"] == 548
assert reviewed_owner_overlay["combined_counts"]["route_owner_records"] == 221
assert reviewed_owner_overlay["combined_counts"]["page_owner_records"] == 327
assert reviewed_owner_overlay["combined_counts"]["distinct_feature_ids"] == 239
assert reviewed_owner_overlay["combined_counts"]["static_controller_action_bridges"] == 9
assert reviewed_owner_overlay["combined_counts"]["bounded_static_source_residual_records"] == 3381
assert reviewed_owner_overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 495
assert reviewed_owner_overlay["queue_accounting"]["queue_surfaces_without_ownership"] == 497
assert reviewed_owner_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_owner_overlay_review["decision"]["mechanical_discrepancies"] == 0
assert reviewed_owner_overlay_review["decision"]["semantic_boundary_discrepancies"] == 0
assert reviewed_owner_overlay_review["decision"]["gate_4_complete"] is False
assert reviewed_owner_overlay["credit_boundary"]["two_shared_relations_as_one_to_one_ownership"] is False
assert reviewed_owner_overlay["credit_boundary"]["complete_route_page_feature_crosswalk"] is False
assert all(
    reviewed_owner_overlay["credit_boundary"][key] is False
    for key in (
        "framework_route_reachability", "navigation", "runtime", "database", "build",
        "application_browser", "executed_tests", "benchmark", "ease", "pass",
        "final_finding", "completion", "audit_complete",
    )
)


assert sha256_file("generators/build-route-controller-only-candidate-cohort-wave-12.py") == "b2214935c7a00a1f231d2949b6a5b8a481b654a6c6e16bae016c841c21c9c2f1"
assert sha256_file("evidence/source/root-run-097-route-controller-only-candidate-cohort-wave-12.json") == "69981d1bc22d76b8f17834040272260d9b33c151535a3ff2ef17ae4643923933"
assert sha256_file("evidence/source/raw-run-097r-independent-route-controller-only-review-wave-12.json") == "125c36710cff83750e3bc2e443955f34b5c019f60b36b874790fce9de9774f0a"
assert sha256_file("generators/integrate-reviewed-route-controller-only-ownership-overlay-wave-12.py") == "b2c3dd9b12f6cbe27f7114d9ed8164600fb05c36b4751f9e9384d9fd33ce0fdf"
assert sha256_file("evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json") == "7f76c9cfeae906a64c92013fbf9a12cf180d39a315bfa13e778b6332365cdd2a"
assert sha256_file("evidence/source/raw-run-098r-independent-reviewed-route-controller-only-ownership-overlay-wave-12.json") == "b7ef9888eca1f8ab47653b19be44d9de385f2132148dfed38b5d8d5018b1903b"
assert route_controller_cohort["counts"]["candidate_route_actions"] == 23
assert route_controller_cohort["counts"]["candidate_page_records"] == 0
assert route_controller_review["decision"]["owner_route_actions"] == 23
assert route_controller_review["decision"]["static_page_owner_records_authorized"] == 0
assert reviewed_route_controller_overlay["combined_counts"]["source_owner_records"] == 571
assert reviewed_route_controller_overlay["combined_counts"]["route_owner_records"] == 244
assert reviewed_route_controller_overlay["combined_counts"]["page_owner_records"] == 327
assert reviewed_route_controller_overlay["combined_counts"]["distinct_feature_ids"] == 246
assert reviewed_route_controller_overlay["combined_counts"]["distinct_H_feature_ids"] == 226
assert reviewed_route_controller_overlay["combined_counts"]["distinct_D_feature_ids"] == 20
assert reviewed_route_controller_overlay["combined_counts"]["route_distinct_feature_ids"] == 56
assert reviewed_route_controller_overlay["combined_counts"]["page_distinct_feature_ids"] == 234
assert reviewed_route_controller_overlay["combined_counts"]["route_page_feature_overlap"] == 44
assert reviewed_route_controller_overlay["combined_counts"]["static_controller_action_bridges"] == 32
assert reviewed_route_controller_overlay["combined_counts"]["bounded_static_source_residual_records"] == 3358
assert reviewed_route_controller_overlay["queue_accounting"]["reviewed_queue_surface_rows"] == 35
assert reviewed_route_controller_overlay["queue_accounting"]["owner_queue_surface_rows"] == 33
assert reviewed_route_controller_overlay["queue_accounting"]["shared_queue_surface_rows"] == 2
assert reviewed_route_controller_overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 472
assert reviewed_route_controller_overlay["queue_accounting"]["queue_surfaces_without_ownership"] == 474
assert reviewed_route_controller_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_route_controller_overlay_review["decision"]["mechanical_discrepancies"] == 0
assert reviewed_route_controller_overlay_review["decision"]["semantic_boundary_discrepancies"] == 0
assert reviewed_route_controller_overlay_review["decision"]["page_owner_records_authorized"] == 0
assert reviewed_route_controller_overlay_review["decision"]["gate_4_complete"] is False
assert reviewed_route_controller_overlay["credit_boundary"]["static_page_feature_ownership"] is False
assert all(
    reviewed_route_controller_overlay["credit_boundary"][key] is False
    for key in (
        "complete_route_page_feature_crosswalk", "framework_route_reachability", "navigation",
        "site_authorization_correctness", "permission_correctness", "privacy_correctness",
        "lifecycle_correctness", "runtime", "database", "build", "application_browser",
        "executed_tests", "benchmark", "ease", "pass", "final_finding", "completion",
        "audit_complete",
    )
)


assert sha256_file("generators/build-route-controller-only-candidate-cohort-wave-12.py") == "b2214935c7a00a1f231d2949b6a5b8a481b654a6c6e16bae016c841c21c9c2f1"
assert sha256_file("evidence/source/root-run-097-route-controller-only-candidate-cohort-wave-12.json") == "69981d1bc22d76b8f17834040272260d9b33c151535a3ff2ef17ae4643923933"
assert sha256_file("evidence/source/raw-run-097r-independent-route-controller-only-review-wave-12.json") == "125c36710cff83750e3bc2e443955f34b5c019f60b36b874790fce9de9774f0a"
assert sha256_file("generators/integrate-reviewed-route-controller-only-ownership-overlay-wave-12.py") == "b2c3dd9b12f6cbe27f7114d9ed8164600fb05c36b4751f9e9384d9fd33ce0fdf"
assert sha256_file("evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json") == "7f76c9cfeae906a64c92013fbf9a12cf180d39a315bfa13e778b6332365cdd2a"
assert sha256_file("evidence/source/raw-run-098r-independent-reviewed-route-controller-only-ownership-overlay-wave-12.json") == "b7ef9888eca1f8ab47653b19be44d9de385f2132148dfed38b5d8d5018b1903b"
assert route_controller_cohort["counts"]["candidate_route_actions"] == 23
assert route_controller_cohort["counts"]["candidate_page_records"] == 0
assert route_controller_review["decision"]["owner_route_actions"] == 23
assert route_controller_review["decision"]["static_page_owner_records_authorized"] == 0
assert reviewed_route_controller_overlay["combined_counts"]["source_owner_records"] == 571
assert reviewed_route_controller_overlay["combined_counts"]["route_owner_records"] == 244
assert reviewed_route_controller_overlay["combined_counts"]["page_owner_records"] == 327
assert reviewed_route_controller_overlay["combined_counts"]["distinct_feature_ids"] == 246
assert reviewed_route_controller_overlay["combined_counts"]["distinct_H_feature_ids"] == 226
assert reviewed_route_controller_overlay["combined_counts"]["distinct_D_feature_ids"] == 20
assert reviewed_route_controller_overlay["combined_counts"]["route_distinct_feature_ids"] == 56
assert reviewed_route_controller_overlay["combined_counts"]["page_distinct_feature_ids"] == 234
assert reviewed_route_controller_overlay["combined_counts"]["route_page_feature_overlap"] == 44
assert reviewed_route_controller_overlay["combined_counts"]["static_controller_action_bridges"] == 32
assert reviewed_route_controller_overlay["combined_counts"]["bounded_static_source_residual_records"] == 3358
assert reviewed_route_controller_overlay["queue_accounting"]["reviewed_queue_surface_rows"] == 35
assert reviewed_route_controller_overlay["queue_accounting"]["owner_queue_surface_rows"] == 33
assert reviewed_route_controller_overlay["queue_accounting"]["shared_queue_surface_rows"] == 2
assert reviewed_route_controller_overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 472
assert reviewed_route_controller_overlay["queue_accounting"]["queue_surfaces_without_ownership"] == 474
assert reviewed_route_controller_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_route_controller_overlay_review["decision"]["mechanical_discrepancies"] == 0
assert reviewed_route_controller_overlay_review["decision"]["semantic_boundary_discrepancies"] == 0
assert reviewed_route_controller_overlay_review["decision"]["page_owner_records_authorized"] == 0
assert reviewed_route_controller_overlay_review["decision"]["gate_4_complete"] is False
assert reviewed_route_controller_overlay["architecture_rule"] == (
    "Oblivion Findings is one operating organisation with multiple Sites. Bounded static route/action "
    "ownership does not establish permission, Site/privacy/lifecycle correctness, runtime behaviour, "
    "or release readiness."
)
assert reviewed_route_controller_overlay["credit_boundary"]["static_page_feature_ownership"] is False
assert reviewed_route_controller_overlay["credit_boundary"]["wholesale_507_queue_ownership"] is False
assert all(
    reviewed_route_controller_overlay["credit_boundary"][key] is False
    for key in (
        "complete_route_page_feature_crosswalk", "framework_route_reachability", "navigation",
        "site_authorization_correctness", "permission_correctness", "privacy_correctness",
        "lifecycle_correctness", "runtime", "database", "build", "application_browser",
        "executed_tests", "benchmark", "ease", "pass", "final_finding", "completion",
        "audit_complete",
    )
)


assert sha256_file("generators/build-outcome-neutral-route-action-cohort-wave-13.py") == "f3ada90da486ba700d21596fb765ab10f661c343944899551006d5db5b9e7a0f"
assert sha256_file("evidence/source/root-run-101-outcome-neutral-route-action-cohort-wave-13.json") == "3a8f4c3f11668406f34db7e50ae561fe1c6516e7002eb7e8271851e62c3ff655"
assert sha256_file("generators/materialize-independent-outcome-neutral-route-action-review-wave-13.py") == "e43c20cb44521a7a6613f7e2b204dd8364142990ccb4d1df16931d922c5f04c2"
assert sha256_file("evidence/source/raw-run-101r-independent-outcome-neutral-route-action-review-wave-13.json") == "518321096f6a483321e3ad129f730db4b628cb70a74e1dbec4149b08c9b09eba"
assert sha256_file("generators/integrate-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.py") == "648f5bc57cde303568c99a6f9acaf608023a0ef6e17a891eb478553f85b7a9ce"
assert sha256_file("evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json") == "cf68900351832c790ec53b4996daaf640c604c498f902c85dec681113bf492dd"
assert sha256_file("generators/materialize-independent-reviewed-outcome-neutral-route-action-ownership-overlay-review-wave-13.py") == "5dad0d4308c4a129ee1d5b4d41f581231031872fe3d586f7e346039a8c4ae8e9"
assert sha256_file("evidence/source/raw-run-102r-independent-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json") == "f88c3ce6ae7b82ca316c656787547bdd9e6a4cd40469b16d44a6e84f99d14902"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-100-wave-12.json") == "65c6852f6c39927142aaf0244347cbf6924a086db61eaa6a02938fe59966ab1c"
assert outcome_neutral_cohort["counts"]["candidate_route_actions"] == 24
assert outcome_neutral_cohort["counts"]["candidate_page_records"] == 0
assert outcome_neutral_review["decision"]["owner_route_actions"] == 21
assert outcome_neutral_review["decision"]["alias_or_redirect"] == 3
assert outcome_neutral_review["decision"]["static_page_owner_records_authorized"] == 0
assert reviewed_outcome_neutral_overlay["combined_counts"] == reviewed_outcome_neutral_overlay_review["verified_combined_counts"]
assert reviewed_outcome_neutral_overlay["queue_accounting"] == reviewed_outcome_neutral_overlay_review["verified_queue_accounting"]
assert reviewed_outcome_neutral_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_outcome_neutral_overlay_review["decision"]["mechanical_discrepancies"] == 0
assert reviewed_outcome_neutral_overlay_review["decision"]["semantic_boundary_discrepancies"] == 0
assert reviewed_outcome_neutral_overlay_review["decision"]["wording_discrepancies_remaining"] == 0
assert reviewed_outcome_neutral_overlay_review["decision"]["page_owner_records_authorized"] == 0
assert reviewed_outcome_neutral_overlay_review["decision"]["gate_4_complete"] is False
assert reviewed_outcome_neutral_overlay["combined_counts"]["source_owner_records"] == 592
assert reviewed_outcome_neutral_overlay["combined_counts"]["route_owner_records"] == 265
assert reviewed_outcome_neutral_overlay["combined_counts"]["page_owner_records"] == 327
assert reviewed_outcome_neutral_overlay["combined_counts"]["distinct_feature_ids"] == 249
assert reviewed_outcome_neutral_overlay["combined_counts"]["distinct_H_feature_ids"] == 229
assert reviewed_outcome_neutral_overlay["combined_counts"]["distinct_D_feature_ids"] == 20
assert reviewed_outcome_neutral_overlay["combined_counts"]["route_distinct_feature_ids"] == 59
assert reviewed_outcome_neutral_overlay["combined_counts"]["page_distinct_feature_ids"] == 234
assert reviewed_outcome_neutral_overlay["combined_counts"]["route_page_feature_overlap"] == 44
assert reviewed_outcome_neutral_overlay["combined_counts"]["static_controller_action_bridges"] == 53
assert reviewed_outcome_neutral_overlay["combined_counts"]["bounded_static_source_residual_records"] == 3337
assert reviewed_outcome_neutral_overlay["combined_counts"]["residual_explicit_unmapped_routes"] == 2945
assert reviewed_outcome_neutral_overlay["combined_counts"]["reviewed_alias_routes"] == 3
assert reviewed_outcome_neutral_overlay["queue_accounting"]["reviewed_queue_surface_rows"] == 59
assert reviewed_outcome_neutral_overlay["queue_accounting"]["owner_queue_surface_rows"] == 54
assert reviewed_outcome_neutral_overlay["queue_accounting"]["shared_queue_surface_rows"] == 2
assert reviewed_outcome_neutral_overlay["queue_accounting"]["alias_queue_surface_rows"] == 3
assert reviewed_outcome_neutral_overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 448
assert reviewed_outcome_neutral_overlay["queue_accounting"]["queue_surfaces_without_ownership"] == 453
assert 3929 == 592 + 3337
assert 592 == 265 + 327
assert 3218 == 265 + 5 + 3 + 2945
assert 711 == 327 + 382 + 2
assert 249 == 59 + 234 - 44
assert 507 == 59 + 448
assert 59 == 54 + 2 + 3
assert all(
    reviewed_outcome_neutral_overlay["credit_boundary"][key] is False
    for key in (
        "static_page_feature_ownership", "frontend_caller_ownership",
        "complete_route_page_feature_crosswalk", "framework_route_reachability", "navigation",
        "site_authorization_correctness", "permission_correctness", "privacy_correctness",
        "direct_object_correctness", "lifecycle_correctness", "runtime", "database", "build",
        "application_browser", "executed_tests", "benchmark", "ease", "pass", "final_finding",
        "completion", "audit_complete",
    )
)

assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-104-wave-13.json") == "3caf6c0970c4ea5c276b51b558d5d736c45576c503049625968e35325148009e"
assert sha256_file("generators/build-outcome-neutral-page-render-owner-cohort-wave-14.py") == "564c37de4525a4587c99d455fa08c6a4a4557441551c6ac5628bd8ae7ca1d31a"
assert sha256_file("evidence/source/root-run-105-outcome-neutral-page-render-owner-cohort-wave-14.json") == "4d6868c06a4c94c708e0934682e0c9724b71fc104c3751d02d0acfd3a95370bc"
assert sha256_file("generators/materialize-independent-outcome-neutral-page-render-owner-review-wave-14.py") == "b1acf84553f91fd6ce71d200126f34ee2c31a622c488d85d490ffd0a536da360"
assert sha256_file("evidence/source/raw-run-105r-independent-outcome-neutral-page-render-owner-review-wave-14.json") == "764a0d086b206112d7c6b93f3d1fa733d3c3ca865a5f4ba3887d082deed1f907"
assert sha256_file("generators/integrate-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.py") == "5ec50d2740496c793997a6a5e5434bf3623fb11dbbac9d46a147551e762b2a54"
assert sha256_file("evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json") == "9c1600d2365c3527e185f313b7cb1586705f39cefb15d5d212dc708b45b630ea"
assert sha256_file("generators/materialize-independent-reviewed-outcome-neutral-page-render-owner-ownership-overlay-review-wave-14.py") == "d6cf49d445fabafc615bc3a3cb836da537a2f5939962bb49deda213d2de4db74"
assert sha256_file("evidence/source/raw-run-106r-independent-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json") == "4a3252a37d03a609cdf69a4f0a56b41e120d3ba2314dede88317de9c50bfd9e4"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-108-wave-14.json") == "1ec434d0a30703a50da0d3def477fdeb4f671f0e03b0a85326f238b89d428f79"
assert sha256_file("generators/build-outcome-neutral-page-render-owner-tail-cohort-wave-15.py") == "1005eaad8d3bcecf99f04b40f912e5181f28e33ef5acb044c27ba0201d0c8e0c"
assert sha256_file("evidence/source/root-run-109-outcome-neutral-page-render-owner-tail-cohort-wave-15.json") == "9019306fc317374b673d76fc6023efc11deb1f7f83be67d0df72d196cd076187"
assert sha256_file("generators/materialize-independent-outcome-neutral-page-render-owner-tail-review-wave-15.py") == "afd6646d04d53f8585eb2dbbeb706fbf5db24a0ccaa404d1d9042ff0773cf184"
assert sha256_file("evidence/source/raw-run-109r-independent-outcome-neutral-page-render-owner-tail-review-wave-15.json") == "2d0110c3b44a3e226549d2f9bc3b4fed76d7fed2e70094c04ccf7c3c0c7c94f0"
assert sha256_file("generators/integrate-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.py") == "8f57e6b888652f67edcea2671239a5403f15e9d144fc369eb2791e2bbd41f9d7"
assert sha256_file("evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json") == "9375fb374b589c5068334795fa03d80a7ba1fd35695808f99c9e2591e886efca"
assert sha256_file("generators/materialize-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-review-wave-15.py") == "534c7e8658729637cdfcb8a87d68782a0cd9da04e6b034c624fcc0d1886c9f88"
assert sha256_file("evidence/source/raw-run-110r-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json") == "e9b076e790e5346f99665f8f99ee609b4c7b7bac4767e416abc73a57f7dfd867"
assert sha256_file("generators/materialize-run-111-reviewed-page-render-owner-tail-reporting-wave-15.py") == "69a47a2c4b85034113cd798c59f558f359065f0237f6fbca1e7d7f9c34a3449a"
assert sha256_file("evidence/source/current-run-111-reviewed-page-render-owner-tail-reporting-wave-15.json") == "ba53c4686450ced0ebbfb56f5637f5631a4cd5aca42610c91adbb5e95139c48b"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-112-wave-15.json") == "5ff6ac0d5905707016b9de4771b572155293d91cbac70a6130a55a3663cb4d8d"
assert sha256_file("generators/build-outcome-neutral-name-only-route-action-cohort-wave-16.py") == "9403a58b2949123daaf1b23fb1db7ea5060c81e595f725dbda2701fff680083f"
assert sha256_file("evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json") == "0849ca5c10e31a4f3e5133a521a570d60b004e723fb17a7f2e812e485f345461"
assert sha256_file("generators/materialize-independent-outcome-neutral-name-only-route-action-review-wave-16.py") == "eacc817d792aee56692012851d9860b2718cb75536203dc9258b838323361238"
assert sha256_file("evidence/source/raw-run-113r-independent-outcome-neutral-name-only-route-action-review-wave-16.json") == "b52872c02b2a1b41861d9eb735eb363fd06cd1af645e1e6c0965b1b042333a83"
assert sha256_file("generators/integrate-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.py") == "6cc7f8b3238bd985d3051a6dec969bc46dfcdfd2e6e790e8276a36be285df6e4"
assert sha256_file("evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json") == "cbeb46be682ca0c9ca54012e2c27cf548b54e9cbb75b050fcd61691ca43aaef2"
assert sha256_file("generators/materialize-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-review-wave-16.py") == "5aa088c66db35c18eee9ee12f31b434d40675d3a61392d49d933bad366ddb52f"
assert sha256_file("evidence/source/raw-run-114r-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json") == "f52ace52820c43ad5043139e18f1d71cf4be904091fbc02e83e045465ded62f2"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-116-wave-16.json") == "90ec8ab20cb9bf8d1e1509db614f941ad5337033973d754445ab6c88b2f13bf8"
assert sha256_file("generators/build-outcome-neutral-respite-handover-page-gap-cohort-wave-17.py") == "85068c7a0170e155b3f5e41b87c91d27c7a45f3e2a117ea2444af91eb45a4374"
assert sha256_file("evidence/source/root-run-117-outcome-neutral-respite-handover-page-gap-cohort-wave-17.json") == "e468e7e7736e49eea629b4faec1fdce94d7de30eee478b08c81b90793622bd2e"
assert sha256_file("generators/materialize-independent-outcome-neutral-respite-handover-page-gap-review-wave-17.py") == "717803e612e94ccc0af3e356050a7e72353d2fc7b31dfd2ab00e30b51af8e11f"
assert sha256_file("evidence/source/raw-run-117r-independent-outcome-neutral-respite-handover-page-gap-review-wave-17.json") == "264236eccceb279522fb784a7c27db2ecc8fd0434e4e5668c33fbe263f1cbc9b"
assert sha256_file("generators/integrate-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.py") == "990d6bbf4879cbaf10e6b4031f640be6bcef346b7e9685e3d3c7da2d846271fb"
assert sha256_file("evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json") == "33dd2f8ffa2b35c6651a6bf18923872e70ba5c2f91fce8b7222666bb8a91fc8b"
assert sha256_file("generators/materialize-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-review-wave-17.py") == "9c8a23e77504ebef3d648b5bd0e894d4c95a8065f04748091b9f33e4aec4fa88"
assert sha256_file("evidence/source/raw-run-118r-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json") == "043d57357e3ff1ede8f0effacdb71e4d802d98d53d555ab39316bce33fe06a2d"
assert sha256_file("generators/materialize-run-119-reviewed-respite-handover-page-gap-reporting-wave-17.py") == "83a827a1ea1f6d9fc8f485dcaf2cd8b6c644a37d6b56ee74f53597edea66be2e"
assert sha256_file("evidence/source/current-run-119-reviewed-respite-handover-page-gap-reporting-wave-17.json") == "d2f80b1649fd4f8eaf965986eaf5b85dc4c906364271dbbd6513fe68c315b694"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-120-wave-17.json") == "0e3ed652833d0e78b3bca85a78cb23f69ddf511e4d1f32f3a8c0bf8dcf20482c"
assert sha256_file("generators/build-outcome-neutral-finance-chart-route-action-cohort-wave-18.py") == "c7795bee971e051873e3953eb4e1bb7c62eb372b6890149700d0c401d64305dd"
assert sha256_file("evidence/source/root-run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18.json") == "cfe0e3635e5e86bf8e7e2f65d2094743738bfa5edc36e361ecf5eb14986f316e"
assert sha256_file("generators/materialize-independent-outcome-neutral-finance-chart-route-action-review-wave-18.py") == "539b48b7aa2859a4b290d63c8d80e5fdcf685a5cb569e37b75499e31dd8d5187"
assert sha256_file("evidence/source/raw-run-121r-independent-outcome-neutral-finance-chart-route-action-review-wave-18.json") == "f70ddd2ddc7ac0c734f4b48bdd19cd2733c3572d038b1dfa1aa185591e567e5f"
assert sha256_file("generators/integrate-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.py") == "04e28529615267699a2c8e844cf074057e18a9019fc511ed65f7c0203dead390"
assert sha256_file("evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json") == "d7aee21e7c4230b44707a22b7fa93478a84e9a5b4775ecd25aaffede764855ca"
assert sha256_file("generators/materialize-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-review-wave-18.py") == "4a080c77dc869fffa53daab937ea03d06ee14d8e11dc941e9d54a7f36b26b315"
assert sha256_file("evidence/source/raw-run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json") == "2130e3801b6ac163580bc56f23d6647136c83fdadc8ea65804b1559d36b29484"
assert sha256_file("generators/materialize-run-123-reviewed-finance-chart-route-action-reporting-wave-18.py") == "4057dcf7fc745219a6fe4a47da141723503e8f6984ded0f36ee243a487946b03"
assert sha256_file("evidence/source/current-run-123-reviewed-finance-chart-route-action-reporting-wave-18.json") == "ffa7c751fb6a87ed358f015d13a28f10a7e5404f3a9569c40dee1e74e25e98b2"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-124-wave-18.json") == "9eedea2c5d051693a3657614c2f4ce4a5d7afca03aa7e0330dfe254b714b0283"
assert sha256_file("generators/build-outcome-neutral-finance-page-gap-cohort-wave-19.py") == "e27ba0b1c7cc4e0fdeeea67272efe628700e9b70dffdc9ef3210b449c7d2ca84"
assert sha256_file("evidence/source/root-run-125-outcome-neutral-finance-page-gap-cohort-wave-19.json") == "7d0df6edfacb63a9a7ab64140d47b2570a617db0147e4b0be6d5317fe38e3d92"
assert sha256_file("generators/materialize-independent-outcome-neutral-finance-page-gap-review-wave-19.py") == "4ea69659b9994458ad9993a3af65092362ceaf2c67af672b3ce962b40c60ef98"
assert sha256_file("evidence/source/raw-run-125r-independent-outcome-neutral-finance-page-gap-review-wave-19.json") == "b26d70eeee965d7dcbbf8e3e439f54bd35b5ab7fa1dfbf7a26c278cc59bb6c73"
assert sha256_file("generators/integrate-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.py") == "36f3afd3a3bf9cf1b20789b4a6ca762ad55409d769870f19ff100466d1c6fccc"
assert sha256_file("evidence/source/current-run-126-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json") == "15ab65b479daa7e7c3f2f3fbd979a13ead87dfbedf31c163a27b5eb809b12f10"
assert sha256_file("generators/materialize-independent-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-review-wave-19.py") == "c58b9a84e00577bf2891f7ea1136e3f26ad0a5efcc9abca5a38152e419420720"
assert sha256_file("evidence/source/raw-run-126r-independent-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json") == "78d969e823885ed7a12a3b6c4e3b2856e91823588e4f51f9dbeefb12f5d22be2"
assert sha256_file("generators/materialize-run-127-reviewed-finance-page-gap-reporting-wave-19.py") == "a4e1d40d0b3db61b7333c198da54f81423447d8a2b36811fa04cd99f29b9736b"
assert page_render_owner_cohort["counts"]["selected_page_candidates"] == 6
assert page_render_owner_cohort["counts"]["page_ownership_credit_awarded"] == 0
assert page_render_owner_cohort["counts"]["selected_pending_direct_queue_page_records"] == 1
assert page_render_owner_review["decision"]["verdict"] == "GO_2_EXPLICIT_OWNER_PAGE_4_SHARED_RELATION"
assert page_render_owner_review["decision"]["owner_pages"] == 2
assert page_render_owner_review["decision"]["shared_relations"] == 4
assert page_render_owner_review["decision"]["evidence_gaps"] == 0
assert page_render_owner_review["decision"]["direct_queue_reviewed_shared_records_authorized"] == 1
assert reviewed_page_owner_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_page_owner_overlay_review["decision"]["mechanical_discrepancies"] == 0
assert reviewed_page_owner_overlay_review["decision"]["semantic_boundary_discrepancies"] == 0
assert reviewed_page_owner_overlay_review["decision"]["queue_accounting_discrepancies"] == 0
assert reviewed_page_owner_overlay_review["decision"]["reporting_authorized"] is True
assert reviewed_page_owner_overlay["combined_counts"] == reviewed_page_owner_overlay_review["verified_counts"]
assert reviewed_page_owner_overlay["queue_accounting"] == reviewed_page_owner_overlay_review["verified_queue_accounting"]
assert reviewed_page_owner_overlay["combined_counts"]["source_owner_records"] == 614
assert reviewed_page_owner_overlay["combined_counts"]["route_owner_records"] == 265
assert reviewed_page_owner_overlay["combined_counts"]["page_owner_records"] == 349
assert reviewed_page_owner_overlay["combined_counts"]["distinct_feature_ids"] == 256
assert reviewed_page_owner_overlay["combined_counts"]["distinct_H_feature_ids"] == 234
assert reviewed_page_owner_overlay["combined_counts"]["distinct_D_feature_ids"] == 22
assert reviewed_page_owner_overlay["combined_counts"]["route_distinct_feature_ids"] == 59
assert reviewed_page_owner_overlay["combined_counts"]["page_distinct_feature_ids"] == 242
assert reviewed_page_owner_overlay["combined_counts"]["route_page_feature_overlap"] == 45
assert reviewed_page_owner_overlay["combined_counts"]["static_controller_action_bridges"] == 53
assert reviewed_page_owner_overlay["combined_counts"]["bounded_static_source_residual_records"] == 3315
assert reviewed_page_owner_overlay["combined_counts"]["residual_unadjudicated_page_roots"] == 353
assert reviewed_page_owner_overlay["combined_counts"]["semantic_shared_page_roots"] == 9
assert reviewed_page_owner_overlay["combined_counts"]["evidence_gap_page_roots_tagged_within_residual"] == 1
assert reviewed_page_owner_overlay["queue_accounting"]["reviewed_queue_surface_rows"] == 60
assert reviewed_page_owner_overlay["queue_accounting"]["owner_queue_surface_rows"] == 54
assert reviewed_page_owner_overlay["queue_accounting"]["shared_queue_surface_rows"] == 3
assert reviewed_page_owner_overlay["queue_accounting"]["alias_queue_surface_rows"] == 3
assert reviewed_page_owner_overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 447
assert reviewed_page_owner_overlay["queue_accounting"]["queue_surfaces_without_ownership"] == 453
assert 3929 == 614 + 3315
assert 614 == 265 + 349
assert 3218 == 265 + 5 + 3 + 2945
assert 711 == 349 + 9 + 353
assert 256 == 59 + 242 - 45
assert 507 == 60 + 447
assert 60 == 54 + 3 + 3
assert 453 == 447 + 3 + 3
assert reviewed_page_owner_overlay["credit_boundary"]["STATIC_PAGE_FEATURE_OWNERSHIP_FOR_2_RECORDS"] is True
assert reviewed_page_owner_overlay["credit_boundary"]["REVIEWED_SHARED_RELATION_FOR_4_RECORDS"] is True
assert reviewed_page_owner_overlay["credit_boundary"]["DIRECT_QUEUE_REVIEWED_SHARED_FOR_1_RECORD"] is True
assert all(
    reviewed_page_owner_overlay["credit_boundary"][key] is False
    for key in (
        "static_route_feature_ownership_added", "static_controller_action_bridge_added",
        "matrix_mutation", "wholesale_507_queue_ownership",
        "complete_route_page_feature_crosswalk", "framework_route_reachability", "navigation",
        "site_authorization_correctness", "permission_correctness", "privacy_correctness",
        "direct_object_correctness", "lifecycle_correctness", "runtime", "database", "build",
        "application_browser", "executed_tests", "benchmark", "ease", "pass", "final_finding",
        "completion", "audit_complete",
    )
)


assert name_only_route_action_cohort["counts"]["candidate_route_actions"] == 24
assert name_only_route_action_cohort["counts"]["candidate_route_records"] == 24
assert name_only_route_action_cohort["counts"]["candidate_controller_action_bridges"] == 24
assert name_only_route_action_cohort["counts"]["candidate_page_records"] == 0
assert name_only_route_action_cohort["counts"]["distinct_feature_ids"] == 2
assert name_only_route_action_cohort["counts"]["distinct_feature_ids_not_in_current_owner_set"] == 0
assert (name_only_route_action_cohort["counts"]["literal_page_callsites"], name_only_route_action_cohort["counts"]["literal_page_callsites_currently_owned"], name_only_route_action_cohort["counts"]["literal_page_callsites_current_evidence_gap"]) == (7, 3, 4)
assert name_only_route_action_cohort["counts"]["ownership_credit_awarded"] == 0
assert name_only_route_action_cohort["counts"]["page_ownership_credit_awarded"] == 0
assert name_only_route_action_review["decision"]["verdict"] == "GO_23_EXPLICIT_OWNER_ROUTE_ACTION_1_EXPLICIT_ALIAS_OR_REDIRECT"
assert (name_only_route_action_review["decision"]["reviewed_route_actions"], name_only_route_action_review["decision"]["owner_route_actions"], name_only_route_action_review["decision"]["shared_relations"], name_only_route_action_review["decision"]["alias_or_redirect"], name_only_route_action_review["decision"]["dead_or_noncanonical"], name_only_route_action_review["decision"]["evidence_gaps"]) == (24, 23, 0, 1, 0, 0)
assert (name_only_route_action_review["decision"]["static_route_owner_records_authorized"], name_only_route_action_review["decision"]["static_controller_action_bridges_authorized"], name_only_route_action_review["decision"]["static_page_owner_records_authorized"]) == (23, 23, 0)
assert name_only_route_action_review["decision"]["gate_4_complete"] is False
assert reviewed_name_only_route_action_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_name_only_route_action_overlay_review["decision"]["mechanical_checks_reported"] == 269
assert all(reviewed_name_only_route_action_overlay_review["decision"][key] == 0 for key in ("mechanical_discrepancies", "semantic_boundary_discrepancies", "arithmetic_or_conservation_discrepancies", "wording_discrepancies_remaining"))
assert reviewed_name_only_route_action_overlay_review["decision"]["reporting_materialization_authorized"] is True
assert reviewed_name_only_route_action_overlay_review["decision"]["gate_4_complete"] is False
assert reviewed_name_only_route_action_overlay["combined_counts"] == reviewed_name_only_route_action_overlay_review["verified_combined_counts"]
assert reviewed_name_only_route_action_overlay["queue_accounting"] == reviewed_name_only_route_action_overlay_review["verified_queue_accounting"]
assert reviewed_name_only_route_action_overlay["outcome_conservation"] == reviewed_name_only_route_action_overlay_review["verified_conservation"]
assert reviewed_name_only_route_action_overlay["identity"] == reviewed_name_only_route_action_overlay_review["verified_identity"]
assert len(reviewed_name_only_route_action_overlay["identity"]) == len(reviewed_name_only_route_action_overlay_review["verified_identity"]) == 38
assert (len(reviewed_name_only_route_action_overlay["overlay_source_records"]), len(reviewed_name_only_route_action_overlay["new_static_controller_action_bridges"]), len(reviewed_name_only_route_action_overlay["reviewed_non_owner_outcomes"])) == (23, 23, 1)
counts = reviewed_name_only_route_action_overlay["combined_counts"]
queue = reviewed_name_only_route_action_overlay["queue_accounting"]
assert (counts["source_owner_records"], counts["route_owner_records"], counts["page_owner_records"]) == (637, 288, 349)
assert (counts["distinct_feature_ids"], counts["distinct_H_feature_ids"], counts["distinct_D_feature_ids"]) == (256, 234, 22)
assert (counts["route_distinct_feature_ids"], counts["page_distinct_feature_ids"], counts["route_page_feature_overlap"]) == (61, 242, 47)
assert (counts["static_controller_action_bridges"], counts["bounded_static_source_residual_records"]) == (76, 3292)
assert (counts["residual_explicit_unmapped_routes"], counts["semantic_shared_routes"], counts["reviewed_alias_routes"]) == (2921, 5, 4)
assert (counts["residual_unadjudicated_page_roots"], counts["semantic_shared_page_roots"], counts["evidence_gap_page_roots_tagged_within_residual"]) == (353, 9, 1)
assert (queue["direct_exact_queue_records"], queue["reviewed_queue_surface_rows"], queue["owner_queue_surface_rows"], queue["shared_queue_surface_rows"], queue["alias_queue_surface_rows"], queue["pending_unreviewed_queue_surface_rows"], queue["queue_surfaces_without_ownership"]) == (507, 84, 77, 3, 4, 423, 430)
assert 3929 == 637 + 3292
assert 637 == 288 + 349
assert 3218 == 288 + 5 + 4 + 2921
assert 711 == 349 + 9 + 353
assert 256 == 61 + 242 - 47
assert 256 == 234 + 22
assert 76 == 53 + 23
assert 507 == 84 + 423
assert 84 == 77 + 3 + 4
assert 430 == 423 + 3 + 4
assert reviewed_name_only_route_action_overlay["page_context_boundary"] == {"literal_callsites": 7, "currently_owned_page_callsites": 3, "current_page_evidence_gap_callsites": 4, "page_ownership_authorized": 0, "rule": "Owned pages remain observation only; four Respite page gaps remain gaps and cannot inherit route ownership."}
for key in ("STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_23_RECORDS", "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_23_ACTIONS", "REVIEWED_ALIAS_OR_REDIRECT_FOR_1_RECORD"):
    assert reviewed_name_only_route_action_overlay["credit_boundary"][key] is True
assert all(reviewed_name_only_route_action_overlay["credit_boundary"][key] is False for key in ("static_page_feature_ownership", "frontend_caller_ownership", "matrix_mutation", "wholesale_507_queue_ownership", "complete_route_page_feature_crosswalk", "framework_route_reachability", "navigation", "site_authorization_correctness", "permission_correctness", "privacy_correctness", "direct_object_correctness", "lifecycle_correctness", "concurrency_correctness", "runtime", "database", "build", "application_browser", "executed_tests", "benchmark", "ease", "pass", "final_finding", "completion", "audit_complete"))

assert respite_handover_page_gap_cohort["counts"]["candidate_page_records"] == 4
assert respite_handover_page_gap_cohort["counts"]["ownership_credit_awarded"] == 0
assert respite_handover_page_gap_review["decision"]["verdict"] == "GO_4_EXPLICIT_OWNER_PAGE"
assert respite_handover_page_gap_review["decision"]["owner_pages"] == 4
assert respite_handover_page_gap_review["decision"]["static_page_owner_records_authorized"] == 4
assert reviewed_respite_handover_page_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_respite_handover_page_overlay_review["decision"]["reporting_promotion_authorized"] is True
assert reviewed_respite_handover_page_overlay_review["decision"]["gate_4_complete"] is False
assert len(reviewed_respite_handover_page_overlay["overlay_source_records"]) == 4
assert reviewed_respite_handover_page_overlay["new_static_controller_action_bridges"] == []
counts = reviewed_respite_handover_page_overlay["combined_counts"]
queue = reviewed_respite_handover_page_overlay["queue_accounting"]
assert (counts["source_owner_records"], counts["route_owner_records"], counts["page_owner_records"]) == (641, 288, 353)
assert (counts["distinct_feature_ids"], counts["distinct_H_feature_ids"], counts["distinct_D_feature_ids"]) == (256, 234, 22)
assert (counts["route_distinct_feature_ids"], counts["page_distinct_feature_ids"], counts["route_page_feature_overlap"]) == (61, 242, 47)
assert (counts["static_controller_action_bridges"], counts["bounded_static_source_residual_records"]) == (76, 3288)
assert (counts["residual_explicit_unmapped_routes"], counts["semantic_shared_routes"], counts["reviewed_alias_routes"]) == (2921, 5, 4)
assert (counts["residual_unadjudicated_page_roots"], counts["semantic_shared_page_roots"], counts["evidence_gap_page_roots_tagged_within_residual"]) == (349, 9, 1)
assert (queue["direct_exact_queue_records"], queue["reviewed_queue_surface_rows"], queue["owner_queue_surface_rows"], queue["shared_queue_surface_rows"], queue["alias_queue_surface_rows"], queue["pending_unreviewed_queue_surface_rows"], queue["queue_surfaces_without_ownership"]) == (507, 84, 77, 3, 4, 423, 430)
assert 3929 == 641 + 3288
assert 641 == 288 + 353
assert 711 == 353 + 9 + 349
assert reviewed_respite_handover_page_overlay["credit_boundary"]["STATIC_PAGE_FEATURE_OWNERSHIP_FOR_4_RECORDS"] is True
assert all(reviewed_respite_handover_page_overlay["credit_boundary"][key] is False for key in ("static_route_feature_ownership_added", "static_controller_action_bridge_added", "direct_exact_queue_review_added", "matrix_mutation", "wholesale_507_queue_ownership", "complete_route_page_feature_crosswalk", "framework_route_reachability", "navigation", "site_authorization_correctness", "permission_correctness", "privacy_correctness", "direct_object_correctness", "lifecycle_correctness", "runtime", "database", "build", "application_browser", "executed_tests", "benchmark", "ease", "pass", "final_finding", "completion", "audit_complete"))

assert finance_chart_route_action_cohort["counts"]["selected_pending_queue_surfaces"] == 22
assert finance_chart_route_action_cohort["counts"]["ownership_credit_awarded"] == 0
assert finance_chart_route_action_review["decision"]["verdict"] == "GO_7_EXPLICIT_OWNER_ROUTE_ACTION_7_SHARED_1_ALIAS_7_EVIDENCE_GAP"
assert (finance_chart_route_action_review["decision"]["owner_route_actions"], finance_chart_route_action_review["decision"]["shared_relations"], finance_chart_route_action_review["decision"]["alias_or_redirect"], finance_chart_route_action_review["decision"]["dead_or_noncanonical"], finance_chart_route_action_review["decision"]["evidence_gaps"]) == (7, 7, 1, 0, 7)
assert reviewed_finance_chart_route_action_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_finance_chart_route_action_overlay_review["decision"]["reporting_materialization_authorized"] is True
assert reviewed_finance_chart_route_action_overlay_review["decision"]["gate_4_complete"] is False
assert len(reviewed_finance_chart_route_action_overlay["overlay_source_records"]) == 7
assert len(reviewed_finance_chart_route_action_overlay["new_static_controller_action_bridges"]) == 7
assert len(reviewed_finance_chart_route_action_overlay["reviewed_non_owner_outcomes"]) == 15
finance_counts = reviewed_finance_chart_route_action_overlay["combined_counts"]
finance_queue = reviewed_finance_chart_route_action_overlay["queue_accounting"]
assert (finance_counts["source_owner_records"], finance_counts["route_owner_records"], finance_counts["page_owner_records"]) == (648, 295, 353)
assert (finance_counts["distinct_feature_ids"], finance_counts["distinct_H_feature_ids"], finance_counts["distinct_D_feature_ids"]) == (256, 234, 22)
assert (finance_counts["route_distinct_feature_ids"], finance_counts["page_distinct_feature_ids"], finance_counts["route_page_feature_overlap"]) == (62, 242, 48)
assert (finance_counts["static_controller_action_bridges"], finance_counts["bounded_static_source_residual_records"]) == (83, 3281)
assert (finance_counts["residual_explicit_unmapped_routes"], finance_counts["semantic_shared_routes"], finance_counts["reviewed_alias_routes"], finance_counts["evidence_gap_routes_tagged_within_residual"]) == (2906, 12, 5, 7)
assert (finance_counts["residual_unadjudicated_page_roots"], finance_counts["semantic_shared_page_roots"], finance_counts["evidence_gap_page_roots_tagged_within_residual"]) == (349, 9, 1)
assert (finance_queue["direct_exact_queue_records"], finance_queue["reviewed_queue_surface_rows"], finance_queue["owner_queue_surface_rows"], finance_queue["shared_queue_surface_rows"], finance_queue["alias_queue_surface_rows"], finance_queue["dead_queue_surface_rows"], finance_queue["evidence_gap_queue_surface_rows"], finance_queue["pending_unreviewed_queue_surface_rows"], finance_queue["queue_surfaces_without_ownership"]) == (507, 106, 84, 10, 5, 0, 7, 401, 423)
assert 3929 == 648 + 3281
assert 648 == 295 + 353
assert 3218 == 295 + 12 + 5 + 2906
assert 711 == 353 + 9 + 349
assert reviewed_finance_chart_route_action_overlay["page_context_boundary"] == {"literal_callsites": 6, "currently_owned_page_callsites": 2, "unowned_page_callsites": 4, "page_ownership_authorized": 0, "rule": "Page callsites remain context only and require separate outcome-neutral page review where still unowned."}
assert reviewed_finance_chart_route_action_overlay["credit_boundary"]["STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_7_RECORDS"] is True
assert reviewed_finance_chart_route_action_overlay_review["credit_boundary"]["INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"] is True
assert all(reviewed_finance_chart_route_action_overlay_review["credit_boundary"][key] is False for key in reviewed_finance_chart_route_action_overlay_review["credit_boundary"] if key != "INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING")

assert finance_page_gap_cohort["counts"]["candidate_page_records"] == 4
assert finance_page_gap_cohort["counts"]["ownership_credit_awarded"] == 0
assert finance_page_gap_review["decision"]["verdict"] == "GO_4_EXPLICIT_OWNER_PAGE"
assert finance_page_gap_review["decision"]["owner_pages"] == 4
assert (finance_page_gap_review["decision"]["chart_of_accounts_owner_pages"], finance_page_gap_review["decision"]["manual_journal_owner_pages"]) == (3, 1)
assert reviewed_finance_page_gap_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_finance_page_gap_overlay_review["decision"]["reporting_promotion_authorized"] is True
assert reviewed_finance_page_gap_overlay_review["decision"]["gate_4_complete"] is False
assert len(reviewed_finance_page_gap_overlay["overlay_source_records"]) == 4
assert reviewed_finance_page_gap_overlay["new_static_controller_action_bridges"] == []
assert len(reviewed_finance_page_gap_overlay["reviewed_non_owner_outcomes"]) == 15
page_finance_counts = reviewed_finance_page_gap_overlay["combined_counts"]
page_finance_queue = reviewed_finance_page_gap_overlay["queue_accounting"]
assert (page_finance_counts["source_owner_records"], page_finance_counts["route_owner_records"], page_finance_counts["page_owner_records"]) == (652, 295, 357)
assert (page_finance_counts["distinct_feature_ids"], page_finance_counts["distinct_H_feature_ids"], page_finance_counts["distinct_D_feature_ids"]) == (256, 234, 22)
assert (page_finance_counts["route_distinct_feature_ids"], page_finance_counts["page_distinct_feature_ids"], page_finance_counts["route_page_feature_overlap"]) == (62, 242, 48)
assert (page_finance_counts["static_controller_action_bridges"], page_finance_counts["bounded_static_source_residual_records"]) == (83, 3277)
assert page_finance_counts["bounded_static_source_ownership_percent"] == "16.594553"
assert (page_finance_counts["residual_explicit_unmapped_routes"], page_finance_counts["semantic_shared_routes"], page_finance_counts["reviewed_alias_routes"], page_finance_counts["evidence_gap_routes_tagged_within_residual"]) == (2906, 12, 5, 7)
assert (page_finance_counts["residual_unadjudicated_page_roots"], page_finance_counts["semantic_shared_page_roots"], page_finance_counts["evidence_gap_page_roots_tagged_within_residual"]) == (345, 9, 1)
assert (page_finance_queue["direct_exact_queue_records"], page_finance_queue["reviewed_queue_surface_rows"], page_finance_queue["owner_queue_surface_rows"], page_finance_queue["shared_queue_surface_rows"], page_finance_queue["alias_queue_surface_rows"], page_finance_queue["dead_queue_surface_rows"], page_finance_queue["evidence_gap_queue_surface_rows"], page_finance_queue["pending_unreviewed_queue_surface_rows"], page_finance_queue["queue_surfaces_without_ownership"]) == (507, 106, 84, 10, 5, 0, 7, 401, 423)
assert all(page_finance_queue[key] == 0 for key in ("new_reviewed_route_surface_rows", "new_owner_route_surface_rows", "new_shared_route_surface_rows", "new_alias_route_surface_rows", "new_evidence_gap_route_surface_rows", "new_reviewed_page_surface_rows", "new_owner_page_surface_rows"))
assert reviewed_finance_page_gap_overlay["page_context_boundary"]["remaining_unowned_from_run_121_context"] == 0
assert reviewed_finance_page_gap_overlay["page_context_boundary"]["journal_page_feature_repaired"] is True
assert reviewed_finance_page_gap_overlay["page_context_boundary"]["journal_parent_route_evidence_gap_preserved"] is True
assert reviewed_finance_page_gap_overlay["credit_boundary"]["STATIC_PAGE_FEATURE_OWNERSHIP_FOR_4_RECORDS"] is True
assert reviewed_finance_page_gap_overlay_review["credit_boundary"]["INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"] is True
assert all(reviewed_finance_page_gap_overlay_review["credit_boundary"][key] is False for key in reviewed_finance_page_gap_overlay_review["credit_boundary"] if key != "INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING")
assert 3929 == 652 + 3277
assert 652 == 295 + 357
assert 3218 == 295 + 12 + 5 + 2906
assert 711 == 357 + 9 + 345

assert sha256_file("generators/materialize-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.py") == "1fef8770c67099440468c12a2bd310f202f6d42c58d67e6586ce63cb49194e4f"

assert sha256_file("evidence/source/current-run-127-reviewed-finance-page-gap-reporting-wave-19.json") == "9db62d439c45af768a7d1cd919251488a8c877fc20f59de27ec88e153588c040"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-128-wave-19.json") == "c6d92421fa9e51ae875067de414fb9c38e52708cd6293fae42dc82a5bb2bd9bc"
assert sha256_file("generators/build-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.py") == "2e23ca7736f0e21460f130a6fafc89a68f228b6f8a52137a2209795d500b0982"
assert sha256_file("evidence/source/root-run-129-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.json") == "6d1efad203c368986ada06746f4314382b2dee4d214b25799dc531c02608180c"
assert sha256_file("generators/materialize-independent-outcome-neutral-finance-fx-revaluation-route-action-review-wave-20.py") == "c77ac164b6869bca82d929df623a19dd40f0c72fa593d7fb805c72c9ece8d60b"
assert sha256_file("evidence/source/raw-run-129r-independent-outcome-neutral-finance-fx-revaluation-route-action-review-wave-20.json") == "9eb86243c72c7aa0c0f1cf6d250b7ad4184c2e0602c8217b7f3c0e70dcded67a"
assert sha256_file("generators/integrate-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.py") == "3bdde28921e35b6a8ec45610af9a52cb55c0d37bdb2de179a2fa9eeecfe976e1"
assert sha256_file("evidence/source/current-run-130-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json") == "f32b3d997a9e7dd932e041f5acf30dea02ee5b62fee3b0901cfbe5cc59f2ed0a"
assert sha256_file("generators/materialize-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-review-wave-20.py") == "b5c879aa46805cdd699c0a39db8c6a1281af634838d772c8f164a8a48df326f3"
assert sha256_file("evidence/source/raw-run-130r-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json") == "4f7e5d74ce3711ce5ff00ac2a499ddde125115b1537a0de2e17375792f3d8590"
assert finance_fx_revaluation_cohort["counts"]["candidate_route_actions"] == 2
assert finance_fx_revaluation_cohort["counts"]["ownership_credit_awarded"] == 0
assert finance_fx_revaluation_review["decision"]["verdict"] == "GO_2_EXPLICIT_OWNER_ROUTE_ACTION"
assert finance_fx_revaluation_review["decision"]["current_overlay_credit_awarded"] is False
assert reviewed_finance_fx_revaluation_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_finance_fx_revaluation_overlay_review["decision"]["reporting_materialization_authorized"] is True
assert reviewed_finance_fx_revaluation_overlay_review["decision"]["gate_4_complete"] is False
assert reviewed_finance_fx_revaluation_overlay_review["verified_identity"] == reviewed_finance_fx_revaluation_overlay["identity"]
assert len(reviewed_finance_fx_revaluation_overlay_review["verified_identity"]) == 40
assert len(reviewed_finance_fx_revaluation_overlay["overlay_source_records"]) == 2
assert len(reviewed_finance_fx_revaluation_overlay["new_static_controller_action_bridges"]) == 2
assert reviewed_finance_fx_revaluation_overlay["reviewed_non_owner_outcomes"] == []
assert reviewed_finance_fx_revaluation_overlay["page_context_boundary"] == {
    "literal_inertia_page_callsites": 0,
    "existing_caller_pages": 2,
    "new_page_owner_records": 0,
    "page_ownership_inherited": False,
    "rule": "Index and Create remain already-owned caller context and receive no new page credit.",
}
fx_counts = reviewed_finance_fx_revaluation_overlay["combined_counts"]
fx_queue = reviewed_finance_fx_revaluation_overlay["queue_accounting"]
assert (fx_counts["source_owner_records"], fx_counts["route_owner_records"], fx_counts["page_owner_records"]) == (654, 297, 357)
assert (fx_counts["distinct_feature_ids"], fx_counts["distinct_H_feature_ids"], fx_counts["distinct_D_feature_ids"]) == (256, 234, 22)
assert (fx_counts["route_distinct_feature_ids"], fx_counts["page_distinct_feature_ids"], fx_counts["route_page_feature_overlap"]) == (62, 242, 48)
assert (fx_counts["static_controller_action_bridges"], fx_counts["bounded_static_source_residual_records"]) == (85, 3275)
assert fx_counts["bounded_static_source_ownership_percent"] == "16.645457"
assert (fx_counts["residual_explicit_unmapped_routes"], fx_counts["semantic_shared_routes"], fx_counts["reviewed_alias_routes"], fx_counts["evidence_gap_routes_tagged_within_residual"]) == (2904, 12, 5, 7)
assert (fx_counts["residual_unadjudicated_page_roots"], fx_counts["semantic_shared_page_roots"], fx_counts["evidence_gap_page_roots_tagged_within_residual"]) == (345, 9, 1)
assert (fx_queue["direct_exact_queue_records"], fx_queue["reviewed_queue_surface_rows"], fx_queue["owner_queue_surface_rows"], fx_queue["shared_queue_surface_rows"], fx_queue["alias_queue_surface_rows"], fx_queue["dead_queue_surface_rows"], fx_queue["evidence_gap_queue_surface_rows"], fx_queue["pending_unreviewed_queue_surface_rows"], fx_queue["queue_surfaces_without_ownership"]) == (507, 108, 86, 10, 5, 0, 7, 399, 421)
assert (fx_queue["new_reviewed_route_surface_rows"], fx_queue["new_owner_route_surface_rows"]) == (2, 2)
assert reviewed_finance_fx_revaluation_overlay["source_packet_expansion_preservation"]["total_disclosed_expansion_entries"] == 16
assert reviewed_finance_fx_revaluation_overlay["assurance_findings_preservation"]["total_findings"] == 15
assert reviewed_finance_fx_revaluation_overlay["projection_reconciliation"]["run129r_projection_credit_awarded"] is False
assert reviewed_finance_fx_revaluation_overlay["projection_reconciliation"]["run130_current_static_overlay_credit_applied"] is True
assert {key for key, value in reviewed_finance_fx_revaluation_overlay["credit_boundary"].items() if value} == {"STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_2_RECORDS", "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_2_ACTIONS"}
assert {key for key, value in reviewed_finance_fx_revaluation_overlay_review["credit_boundary"].items() if value} == {"INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"}
assert 3929 == 654 + 3275
assert 654 == 297 + 357
assert 3218 == 297 + 12 + 5 + 2904
assert 711 == 357 + 9 + 345

assert sha256_file("generators/materialize-run-135-reviewed-finance-accounting-integration-route-action-reporting-wave-21.py") == "8f0590834cc8d1f64bc0ac2cd1bc53f88ab1a3b161147863f3def389777dddad"
assert sha256_file("evidence/source/current-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.json") == "191d428161b0f96758bf4ca32d968d87cd9efb1e0a4e9fdd26741f8952063099"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-132-wave-20.json") == "c6b8991bd63628bc9dc34bd458067cd89cb612cbb8096f2c9f5fa7792d5c3014"
assert sha256_file("generators/build-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.py") == "476966a02322f59f385fb59dc9a55a3774e868e512cb58d5f0606698cbfd08af"
assert sha256_file("evidence/source/root-run-133-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.json") == "58d87fa101e4e1b51d232baf80e1a2e3ef75dad89f37dc0dcd615c2f16e29ce4"
assert sha256_file("generators/materialize-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21.py") == "f878b16d485ff802d9ca5fd51bbd82628d37efed3151eaadcc72ed777ad5783d"
assert sha256_file("evidence/source/raw-run-133r-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21.json") == "7b56e738132dad35a0273b764d7f5e401219d6d52394306b41d2afac3a821420"
assert sha256_file("generators/integrate-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.py") == "dec764ee611f3dd3bcc21484a04aab1773332dfec1e6cfec547f7abb4f2c56db"
assert sha256_file("evidence/source/current-run-134-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json") == "e82514d96ac01db1cba72e9a469b2bb9c15404d2c42ff124c816e38b086bb669"
assert sha256_file("generators/materialize-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-review-wave-21.py") == "773bdf925ae3ae4a3d4aafc3767b50347fca8fcfa4e761337a4f5584aecd78c3"
assert sha256_file("evidence/source/raw-run-134r-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json") == "da3107cdcbb4ab286c208f85d994676d00f933d4002a966fb89773f8ef0857d3"
assert finance_accounting_integration_cohort["counts"]["candidate_route_actions"] == 6
assert finance_accounting_integration_cohort["counts"]["ownership_credit_awarded"] == 0
assert finance_accounting_integration_review["decision"]["verdict"] == "GO_6_EXPLICIT_OWNER_ROUTE_ACTION"
assert finance_accounting_integration_review["decision"]["current_overlay_credit_awarded"] is False
assert reviewed_finance_accounting_integration_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_finance_accounting_integration_overlay_review["decision"]["reporting_materialization_authorized"] is True
assert reviewed_finance_accounting_integration_overlay_review["decision"]["gate_4_complete"] is False
assert reviewed_finance_accounting_integration_overlay_review["verified_identity"] == reviewed_finance_accounting_integration_overlay["identity"]
assert len(reviewed_finance_accounting_integration_overlay_review["verified_identity"]) == 40
assert len(reviewed_finance_accounting_integration_overlay["overlay_source_records"]) == 6
assert len(reviewed_finance_accounting_integration_overlay["new_static_controller_action_bridges"]) == 6
assert reviewed_finance_accounting_integration_overlay["reviewed_non_owner_outcomes"] == []
assert reviewed_finance_accounting_integration_overlay["page_context_boundary"] == {
    "literal_inertia_page_callsites": 1,
    "existing_caller_or_render_pages": 2,
    "selected_frontend_literal_caller_contexts": 5,
    "selected_routes_without_literal_caller_in_frozen_pages": 1,
    "existing_index_page_record_id": "PAGE-ROOT-679D3E7F4B5402CB",
    "existing_index_page_feature_id": "CAP-FIN-ACCOUNTING-INTEGRATION-CONFIGURATION",
    "existing_mapping_page_record_id": "PAGE-ROOT-BA2E4950746EAF10",
    "existing_mapping_page_feature_id": "CAP-FIN-XERO-ACCOUNTING-SYNC",
    "new_page_owner_records": 0,
    "page_ownership_inherited": False,
    "page_ownership_reassigned": False,
    "rule": "Index and Mapping remain existing page-owner context; no caller or render transfers page ownership.",
}
accounting_counts = reviewed_finance_accounting_integration_overlay["combined_counts"]
accounting_queue = reviewed_finance_accounting_integration_overlay["queue_accounting"]
assert (accounting_counts["source_owner_records"], accounting_counts["route_owner_records"], accounting_counts["page_owner_records"]) == (660, 303, 357)
assert (accounting_counts["distinct_feature_ids"], accounting_counts["distinct_H_feature_ids"], accounting_counts["distinct_D_feature_ids"]) == (256, 234, 22)
assert (accounting_counts["route_distinct_feature_ids"], accounting_counts["page_distinct_feature_ids"], accounting_counts["route_page_feature_overlap"]) == (62, 242, 48)
assert (accounting_counts["static_controller_action_bridges"], accounting_counts["bounded_static_source_residual_records"]) == (91, 3269)
assert accounting_counts["bounded_static_source_ownership_percent"] == "16.798167"
assert (accounting_counts["residual_explicit_unmapped_routes"], accounting_counts["semantic_shared_routes"], accounting_counts["reviewed_alias_routes"], accounting_counts["evidence_gap_routes_tagged_within_residual"]) == (2898, 12, 5, 7)
assert (accounting_counts["residual_unadjudicated_page_roots"], accounting_counts["semantic_shared_page_roots"], accounting_counts["evidence_gap_page_roots_tagged_within_residual"]) == (345, 9, 1)
assert (accounting_queue["direct_exact_queue_records"], accounting_queue["reviewed_queue_surface_rows"], accounting_queue["owner_queue_surface_rows"], accounting_queue["shared_queue_surface_rows"], accounting_queue["alias_queue_surface_rows"], accounting_queue["dead_queue_surface_rows"], accounting_queue["evidence_gap_queue_surface_rows"], accounting_queue["pending_unreviewed_queue_surface_rows"], accounting_queue["queue_surfaces_without_ownership"]) == (507, 114, 92, 10, 5, 0, 7, 393, 415)
assert (accounting_queue["new_reviewed_route_surface_rows"], accounting_queue["new_owner_route_surface_rows"]) == (6, 6)
assert reviewed_finance_accounting_integration_overlay["source_packet_expansion_preservation"]["total_disclosed_expansion_entries"] == 6
assert reviewed_finance_accounting_integration_overlay["source_packet_expansion_preservation"]["widened_existing_packet_files"] == 2
assert reviewed_finance_accounting_integration_overlay["source_packet_expansion_preservation"]["newly_followed_files"] == 4
assert reviewed_finance_accounting_integration_overlay["assurance_findings_preservation"]["candidate_findings"] == 15
assert reviewed_finance_accounting_integration_overlay["assurance_findings_preservation"]["shared_findings"] == 7
assert reviewed_finance_accounting_integration_overlay["assurance_findings_preservation"]["total_findings"] == 22
assert reviewed_finance_accounting_integration_overlay["projection_reconciliation"]["run133r_projection_credit_awarded"] is False
assert reviewed_finance_accounting_integration_overlay["projection_reconciliation"]["run134_current_static_overlay_credit_applied"] is True
assert reviewed_finance_accounting_integration_overlay["noninheritance_boundary"]["already_reviewed_index_route_record_id"] == "RUN077-ROUTE-0592"
assert reviewed_finance_accounting_integration_overlay["noninheritance_boundary"]["excluded_backend_only_sync_route_record_id"] == "RUN077-ROUTE-0595"
assert reviewed_finance_accounting_integration_overlay["noninheritance_boundary"]["excluded_backend_only_sync_selected"] is False
assert {key for key, value in reviewed_finance_accounting_integration_overlay["credit_boundary"].items() if value} == {"STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_6_RECORDS", "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_6_ACTIONS"}
assert {key for key, value in reviewed_finance_accounting_integration_overlay_review["credit_boundary"].items() if value} == {"INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"}
assert sha256_file("generators/materialize-run-135-reviewed-finance-accounting-integration-route-action-reporting-wave-21.py") == "8f0590834cc8d1f64bc0ac2cd1bc53f88ab1a3b161147863f3def389777dddad"
assert sha256_file("evidence/source/current-run-135-reviewed-finance-accounting-integration-route-action-reporting-wave-21.json") == "af70461527e7b22855b0a7917121112ca973fe4e88450b6b87ef0b5ae39d99da"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-136-wave-21.json") == "24838333225819640bc767d7f5149aaaadcfa11377e4035e985af314fc549d1e"
assert sha256_file("generators/build-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.py") == "93766689117c88173a08f8548a04d7e62f00eadf71fb7fefa302936e540c9bd9"
assert sha256_file("evidence/source/root-run-137-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.json") == "e2a6a346365ada6013b82f4e29aa955ffcedf7f3b53ab88279c700407d3012bc"
assert sha256_file("generators/materialize-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.py") == "4a4eb33dd34832b2182bfe27bf13f90f3a30e7406b74552e82dda2f0c73b99c5"
assert sha256_file("evidence/source/raw-run-137r-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.json") == "a3659294a8d2f9c203968a885da7b48f928d5341dbcb2b177eb85b40a058411f"
assert sha256_file("generators/integrate-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.py") == "76f9f34a249b901e4448166155eb0e5a314390bebfc90c6d28f5df08c1cb6baf"
assert sha256_file("evidence/source/current-run-138-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json") == "005a55c952ec3f3b2a5bac9f3c99000fa4eae65a488764dfd1f4662063431701"
assert sha256_file("generators/materialize-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-review-wave-22.py") == "867cf33924bcaf7cf34fa5d22c0a99a920d75f2255ef437adeca1e0a9734af3f"
assert sha256_file("evidence/source/raw-run-138r-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json") == "befb7b4463b588d7ebbb9e42c6c7b34bf02de78b962628f0c75f423c2b7b5e31"
assert sha256_file("generators/materialize-run-139-reviewed-finance-invoice-index-route-action-reporting-wave-22.py") == "407ae22c49bd2333d0c9b36d33ee962af5db578adf316acd53cb9deabf0c5541"
assert finance_invoice_index_cohort["counts"]["candidate_route_actions"] == 1
assert finance_invoice_index_cohort["counts"]["ownership_credit_awarded"] == 0
assert finance_invoice_index_review["decision"]["verdict"] == "GO_ACCEPT_1_OWNER_ROUTE_ACTION_FOR_BOUNDED_LATER_INTEGRATION"
assert finance_invoice_index_review["decision"]["current_overlay_credit_awarded"] is False
assert reviewed_finance_invoice_index_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_finance_invoice_index_overlay_review["decision"]["reporting_materialization_authorized"] is True
assert reviewed_finance_invoice_index_overlay_review["decision"]["gate_4_complete"] is False
assert reviewed_finance_invoice_index_overlay_review["verified_identity"] == reviewed_finance_invoice_index_overlay["identity"]
assert len(reviewed_finance_invoice_index_overlay_review["verified_identity"]) == 41
assert len(reviewed_finance_invoice_index_overlay["overlay_source_records"]) == 1
assert len(reviewed_finance_invoice_index_overlay["new_static_controller_action_bridges"]) == 1
assert reviewed_finance_invoice_index_overlay["reviewed_non_owner_outcomes"] == []
invoice_counts = reviewed_finance_invoice_index_overlay["combined_counts"]
invoice_queue = reviewed_finance_invoice_index_overlay["queue_accounting"]
assert (invoice_counts["source_owner_records"], invoice_counts["route_owner_records"], invoice_counts["page_owner_records"]) == (661, 304, 357)
assert (invoice_counts["distinct_feature_ids"], invoice_counts["distinct_H_feature_ids"], invoice_counts["distinct_D_feature_ids"]) == (256, 234, 22)
assert (invoice_counts["route_distinct_feature_ids"], invoice_counts["page_distinct_feature_ids"], invoice_counts["route_page_feature_overlap"]) == (63, 242, 49)
assert (invoice_counts["static_controller_action_bridges"], invoice_counts["bounded_static_source_residual_records"]) == (92, 3268)
assert invoice_counts["bounded_static_source_ownership_percent"] == "16.823619"
assert (invoice_counts["residual_explicit_unmapped_routes"], invoice_counts["semantic_shared_routes"], invoice_counts["reviewed_alias_routes"], invoice_counts["evidence_gap_routes_tagged_within_residual"]) == (2897, 12, 5, 7)
assert (invoice_counts["residual_unadjudicated_page_roots"], invoice_counts["semantic_shared_page_roots"], invoice_counts["evidence_gap_page_roots_tagged_within_residual"]) == (345, 9, 1)
assert (invoice_queue["direct_exact_queue_records"], invoice_queue["reviewed_queue_surface_rows"], invoice_queue["owner_queue_surface_rows"], invoice_queue["shared_queue_surface_rows"], invoice_queue["alias_queue_surface_rows"], invoice_queue["dead_queue_surface_rows"], invoice_queue["evidence_gap_queue_surface_rows"], invoice_queue["pending_unreviewed_queue_surface_rows"], invoice_queue["queue_surfaces_without_ownership"]) == (507, 115, 93, 10, 5, 0, 7, 392, 414)
assert reviewed_finance_invoice_index_overlay["source_packet_expansion_preservation"]["total_disclosed_expansion_entries"] == 12
assert reviewed_finance_invoice_index_overlay["source_packet_expansion_preservation"]["widened_existing_packet_files"] == 7
assert reviewed_finance_invoice_index_overlay["source_packet_expansion_preservation"]["newly_followed_files"] == 5
assert reviewed_finance_invoice_index_overlay["assurance_findings_preservation"]["candidate_findings"] == 6
assert reviewed_finance_invoice_index_overlay["assurance_findings_preservation"]["shared_findings"] == 3
assert reviewed_finance_invoice_index_overlay["assurance_findings_preservation"]["total_findings"] == 9
assert reviewed_finance_invoice_index_overlay["page_context_boundary"]["existing_page_owner_context_rows"] == 2
assert reviewed_finance_invoice_index_overlay["page_context_boundary"]["new_page_owner_records"] == 0
assert reviewed_finance_invoice_index_overlay["page_context_boundary"]["page_ownership_inherited"] is False
assert reviewed_finance_invoice_index_overlay["page_context_boundary"]["page_ownership_reassigned"] is False
assert reviewed_finance_invoice_index_overlay["noninheritance_boundary"]["next_queue_index_zero_based"] == 78
assert reviewed_finance_invoice_index_overlay["noninheritance_boundary"]["next_boundary_selected_or_credited"] is False
assert {key for key, value in reviewed_finance_invoice_index_overlay["credit_boundary"].items() if value} == {"STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD", "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION"}
assert {key for key, value in reviewed_finance_invoice_index_overlay_review["credit_boundary"].items() if value} == {"INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"}
assert 3929 == 661 + 3268
assert 661 == 304 + 357
assert 3218 == 304 + 12 + 5 + 2897
assert 711 == 357 + 9 + 345
assert sha256_file("evidence/source/current-run-139-reviewed-finance-invoice-index-route-action-reporting-wave-22.json") == "bdc0b866db9409220bcac7bf66075e8cf89460fb40d61021fe7c98a705597231"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-140-wave-22.json") == "1cae6bb23a9ede9bcda9cd975de07476516eeb18d6746f1aacf2653ecfe0c74f"
assert sha256_file("generators/build-outcome-neutral-finance-site-portfolio-overview-route-action-cohort-wave-23.py") == "d3cfd34687ba6c6a9b6afecfe9bfc02d2b700b15de881c1ef651877c486fd6a0"
assert sha256_file("evidence/source/root-run-141-outcome-neutral-finance-site-portfolio-overview-route-action-cohort-wave-23.json") == "9062d90b961e496b0bf5ad48fc3f930a8161394fb8d2b9b88ad298807bd90fc3"
assert sha256_file("generators/materialize-independent-outcome-neutral-finance-site-portfolio-overview-route-action-review-wave-23.py") == "41c2b9855fd8a0f6510dbf9e5fcd61c27be4de7178fef7a6a77dbf5b52ffb699"
assert sha256_file("evidence/source/raw-run-141r-independent-outcome-neutral-finance-site-portfolio-overview-route-action-review-wave-23.json") == "02f78f9e6305c783fd13b790f5d0e044e437bfc2d6853eeb49ec5a65cdd8fd8b"
assert sha256_file("generators/integrate-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-wave-23.py") == "c7c7baeb6f34542911370092ea88e7620142be3863742f73ca1434b91f02f005"
assert sha256_file("evidence/source/current-run-142-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-wave-23.json") == "2d5228394090bcdad9ebfc3976be87260bf1dc5d110a8297974b00d908b63cdb"
assert sha256_file("generators/materialize-independent-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-review-wave-23.py") == "0e9e9ecfef802d17e56bff1282d460fccf91c10525902f4aa6993c4a5f4679f2"
assert sha256_file("evidence/source/raw-run-142r-independent-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-wave-23.json") == "005cbe019f16d7705f7d632b97a8f2629bf7c5653ba3ff9b30c50bd10e2a44df"
assert finance_site_portfolio_overview_cohort["counts"]["candidate_route_actions"] == 1
assert finance_site_portfolio_overview_cohort["counts"]["ownership_credit_awarded"] == 0
assert finance_site_portfolio_overview_review["decision"]["verdict"] == "GO_ACCEPT_1_OWNER_ROUTE_ACTION_FOR_BOUNDED_LATER_INTEGRATION"
assert finance_site_portfolio_overview_review["decision"]["current_overlay_credit_awarded"] is False
assert reviewed_finance_site_portfolio_overview_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_finance_site_portfolio_overview_overlay_review["decision"]["reporting_materialization_authorized"] is True
assert reviewed_finance_site_portfolio_overview_overlay_review["decision"]["gate_4_complete"] is False
assert reviewed_finance_site_portfolio_overview_overlay_review["verified_identity"] == reviewed_finance_site_portfolio_overview_overlay["identity"]
assert len(reviewed_finance_site_portfolio_overview_overlay_review["verified_identity"]) == 91
assert len(reviewed_finance_site_portfolio_overview_overlay_review["verified_counts"]) == 35
assert len(reviewed_finance_site_portfolio_overview_overlay["overlay_source_records"]) == 1
assert len(reviewed_finance_site_portfolio_overview_overlay["new_static_controller_action_bridges"]) == 1
assert reviewed_finance_site_portfolio_overview_overlay["reviewed_non_owner_outcomes"] == []
site_counts = reviewed_finance_site_portfolio_overview_overlay["combined_counts"]
site_queue = reviewed_finance_site_portfolio_overview_overlay["queue_accounting"]
assert (site_counts["source_owner_records"], site_counts["route_owner_records"], site_counts["page_owner_records"]) == (662, 305, 357)
assert (site_counts["distinct_feature_ids"], site_counts["distinct_H_feature_ids"], site_counts["distinct_D_feature_ids"]) == (256, 234, 22)
assert (site_counts["route_distinct_feature_ids"], site_counts["page_distinct_feature_ids"], site_counts["route_page_feature_overlap"]) == (64, 242, 50)
assert (site_counts["static_controller_action_bridges"], site_counts["bounded_static_source_residual_records"]) == (93, 3267)
assert site_counts["bounded_static_source_ownership_percent"] == "16.849071"
assert (site_counts["residual_explicit_unmapped_routes"], site_counts["semantic_shared_routes"], site_counts["reviewed_alias_routes"], site_counts["evidence_gap_routes_tagged_within_residual"]) == (2896, 12, 5, 7)
assert (site_counts["residual_unadjudicated_page_roots"], site_counts["semantic_shared_page_roots"], site_counts["evidence_gap_page_roots_tagged_within_residual"]) == (345, 9, 1)
assert (site_queue["direct_exact_queue_records"], site_queue["reviewed_queue_surface_rows"], site_queue["owner_queue_surface_rows"], site_queue["shared_queue_surface_rows"], site_queue["alias_queue_surface_rows"], site_queue["dead_queue_surface_rows"], site_queue["evidence_gap_queue_surface_rows"], site_queue["pending_unreviewed_queue_surface_rows"], site_queue["queue_surfaces_without_ownership"]) == (507, 116, 94, 10, 5, 0, 7, 391, 413)
site_page = reviewed_finance_site_portfolio_overview_overlay["page_context_boundary"]
assert site_page["selected_action_evidence"]["route_record_id"] == "RUN077-ROUTE-0669"
assert site_page["selected_action_evidence"]["returns_json_response"] is True
assert site_page["selected_action_evidence"]["literal_inertia_page_callsite_count"] == 0
assert site_page["existing_page_owner_context"]["source_record_id"] == "PAGE-ROOT-FC2C5F5706FD9066"
assert site_page["existing_page_owner_context"]["owner_row_id"] == "RUN086-PAGE-MAP-0313"
assert site_page["separate_page_route_sibling"]["queue_id"] == "RUN090-ROUTE-0041"
assert site_page["separate_page_route_sibling"]["route_record_id"] == "RUN077-ROUTE-0418"
assert len(site_page["page_path_caller_contexts"]) == 3
assert site_page["selected_api_exact_frontend_caller_occurrences"] == 0
assert site_page["excluded_immediate_raw_neighbor"]["queue_index_zero_based"] == 79
assert site_page["excluded_immediate_raw_neighbor"]["queue_id"] == "RUN090-ROUTE-0080"
assert site_page["excluded_immediate_raw_neighbor"]["route_record_id"] == "RUN077-ROUTE-0688"
assert site_page["next_pending_boundary"]["queue_index_zero_based"] == 80
assert site_page["next_pending_boundary"]["queue_id"] == "RUN090-ROUTE-0081"
assert site_page["next_pending_boundary"]["route_record_id"] == "RUN077-ROUTE-0689"
site_noninheritance = reviewed_finance_site_portfolio_overview_overlay["noninheritance_boundary"]
assert site_noninheritance["page_owner_not_inherited_or_recredited"] is True
assert site_noninheritance["sibling_route_not_inherited"] is True
assert site_noninheritance["callers_not_inherited"] is True
assert site_noninheritance["neighbor_index_79_not_recredited"] is True
assert site_noninheritance["next_index_80_not_selected_or_credited"] is True
assert site_noninheritance["current_overlay_correctness_and_downstream_credit"] is False
assert len(reviewed_finance_site_portfolio_overview_overlay["source_packet_expansion_preservation"]["expanded_files"]) == 24
assert sum(row["original_packet_present"] for row in reviewed_finance_site_portfolio_overview_overlay["source_packet_expansion_preservation"]["expanded_files"]) == 6
assert len(reviewed_finance_site_portfolio_overview_overlay["source_packet_expansion_preservation"]["locus_corrections"]) == 1
assert len(reviewed_finance_site_portfolio_overview_overlay["assurance_findings_preservation"]["reconciliation"]["input_rows"]) == 17
assert len(reviewed_finance_site_portfolio_overview_overlay["assurance_findings_preservation"]["action_findings"]) == 9
assert len(reviewed_finance_site_portfolio_overview_overlay["assurance_findings_preservation"]["shared_findings"]) == 3
assert reviewed_finance_site_portfolio_overview_overlay["lineage_correction"]["corrected_declared_input_count"] == 26
assert {key for key, value in reviewed_finance_site_portfolio_overview_overlay["credit_boundary"].items() if value} == {"STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD", "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION"}
assert {key for key, value in reviewed_finance_site_portfolio_overview_overlay_review["credit_boundary"].items() if value} == {"INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"}
assert 3929 == 662 + 3267
assert 662 == 305 + 357
assert 256 == 64 + 242 - 50
assert 3218 == 305 + 12 + 5 + 2896
assert 711 == 357 + 9 + 345
assert 507 == 116 + 391
assert 116 == 94 + 10 + 5 + 0 + 7
assert 413 == 391 + 10 + 5 + 0 + 7
fleet_counts = reviewed_fleet_daily_check_overlay["combined_counts"]
fleet_queue = reviewed_fleet_daily_check_overlay["queue_accounting"]
assert (fleet_counts["source_owner_records"], fleet_counts["route_owner_records"], fleet_counts["page_owner_records"], fleet_counts["static_controller_action_bridges"]) == (663, 306, 357, 94)
assert (fleet_counts["distinct_feature_ids"], fleet_counts["distinct_H_feature_ids"], fleet_counts["distinct_D_feature_ids"]) == (256, 234, 22)
assert (fleet_counts["route_distinct_feature_ids"], fleet_counts["page_distinct_feature_ids"], fleet_counts["route_page_feature_overlap"]) == (64, 242, 50)
assert fleet_counts["bounded_static_source_ownership_percent"] == "16.874523"
assert (fleet_counts["bounded_static_source_residual_records"], fleet_counts["residual_explicit_unmapped_routes"]) == (3266, 2895)
assert (fleet_queue["reviewed_queue_surface_rows"], fleet_queue["pending_unreviewed_queue_surface_rows"], fleet_queue["owner_queue_surface_rows"], fleet_queue["queue_surfaces_without_ownership"]) == (117, 390, 95, 412)
assert 3929 == 663 + 3266 and 663 == 306 + 357 and 256 == 64 + 242 - 50
assert 3218 == 306 + 12 + 5 + 2895 and 711 == 357 + 9 + 345
assert 507 == 117 + 390 and 117 == 95 + 10 + 5 + 0 + 7 and 412 == 390 + 10 + 5 + 0 + 7
assert reviewed_fleet_daily_check_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_fleet_daily_check_overlay_review["decision"]["reporting_materialization_authorized"] is True
assert reviewed_fleet_daily_check_overlay_review["decision"]["gate_4_complete"] is False
assert reviewed_fleet_daily_check_overlay["noninheritance_boundary"] == {"preceding_index_79_owner_not_inherited_or_recredited": True, "page_owner_not_inherited_or_recredited": True, "frontend_caller_not_inherited_or_recredited": True, "next_index_81_not_selected_or_credited": True, "current_overlay_correctness_and_downstream_credit": False}
fleet_observations = reviewed_fleet_daily_check_overlay["provisional_assurance_observation_preservation"]
assert fleet_observations["observation_count"] == 4
assert all(row["status"] == "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING" for row in fleet_observations["observations"])
assert all(not row["correctness_credit_authorized"] and not row["final_finding_credit_authorized"] for row in fleet_observations["observations"])
assert len(reviewed_fleet_daily_check_overlay["source_packet_expansion_preservation"]["ownership_material_expansion"]) == 0
assert len(reviewed_fleet_daily_check_overlay["source_packet_expansion_preservation"]["correctness_only_expanded_files"]) == 4
fleet_disclosure = reviewed_fleet_daily_check_overlay["reviewer_lineage"]["nonblinding_disclosure_preserved"]
assert fleet_disclosure == {"review_a_blinded": False, "review_a_prior_outcome_visible_in_team_status": False, "review_b_blinded": False, "review_b_prior_outcome_visible_in_team_status": True, "reviewers_consulted_each_other": False, "both_completed_independent_evidence_traces": True}
assert run_150_reporting["outputs"] == {
    "00-executive-summary.md": {"bytes": 102408, "sha256": "c8b1885704825e69112af6f7830d01be2c34b9e9d97754e0c0986112d0488eb2"},
    "01-repository-module-map.md": {"bytes": 29677, "sha256": "3512e7dde9f08ce6cbc59a10c3e5c97c30c053704f87ebe4c3fb10cab7ea4c3a"},
    "13-unresolved-questions-and-evidence-gaps.md": {"bytes": 26253, "sha256": "753210c92a2438975b9aadff3d3ae72ea336a8e870b3faa7f204cd9bddcb7efa"},
    "findings.json": {"bytes": 426548, "sha256": "43b499788ed5a185a3466198bd707ec11526c0009c91513159c0c18f441dd2f3"},
    "generators/build-current-audit-dashboard.py": {"bytes": 332610, "sha256": "a2ae48cbb59ff5c16c56f805095dbda4829575d0f1e7063c76b098b9753fe284"},
}
# RUN-150 output hashes close that immutable historical receipt; RUN-154
# intentionally advances the live reporting surfaces and never compares them.
assert dashboard_run_151["verification"]["viewports_verified"] == 4
assert dashboard_run_151["verification"]["navigation_targets"] == "10/10"
assert dashboard_run_151["verification"]["unique_local_links"] == 367
assert len(dashboard_run_151["verification"]["exact_visible_static_boundary_checks"]) == 42
vehicle_counts = reviewed_fleet_vehicle_register_overlay["combined_counts"]
vehicle_queue = reviewed_fleet_vehicle_register_overlay["queue_accounting"]
assert (vehicle_counts["source_owner_records"], vehicle_counts["route_owner_records"], vehicle_counts["page_owner_records"], vehicle_counts["static_controller_action_bridges"]) == (664, 307, 357, 95)
assert (vehicle_counts["distinct_feature_ids"], vehicle_counts["distinct_H_feature_ids"], vehicle_counts["distinct_D_feature_ids"]) == (256, 234, 22)
assert (vehicle_counts["route_distinct_feature_ids"], vehicle_counts["page_distinct_feature_ids"], vehicle_counts["route_page_feature_overlap"]) == (64, 242, 50)
assert (vehicle_counts["bounded_static_source_residual_records"], vehicle_counts["residual_explicit_unmapped_routes"], vehicle_counts["bounded_static_source_ownership_percent"]) == (3265, 2894, "16.899975")
assert (vehicle_queue["reviewed_queue_surface_rows"], vehicle_queue["pending_unreviewed_queue_surface_rows"], vehicle_queue["owner_queue_surface_rows"], vehicle_queue["queue_surfaces_without_ownership"]) == (118, 389, 96, 411)
assert 3929 == 664 + 3265 and 664 == 307 + 357 and 256 == 64 + 242 - 50
assert 3218 == 307 + 12 + 5 + 0 + 2894 and 711 == 357 + 9 + 0 + 0 + 345
assert 507 == 118 + 389 and 118 == 96 + 10 + 5 + 0 + 7 and 411 == 389 + 10 + 5 + 0 + 7
assert reviewed_fleet_vehicle_register_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_fleet_vehicle_register_overlay_review["decision"]["independent_reviews"] == 3
assert reviewed_fleet_vehicle_register_overlay_review["decision"]["discrepancies"] == 0
assert reviewed_fleet_vehicle_register_overlay_review["decision"]["reporting_materialization_authorized"] is True
assert reviewed_fleet_vehicle_register_overlay_review["decision"]["gate_4_complete"] is False
vehicle_observations = reviewed_fleet_vehicle_register_overlay["provisional_assurance_observation_preservation"]
assert vehicle_observations["observation_count"] == 6
assert all(row["status"] == "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING" and not row["correctness_credit_authorized"] and not row["final_finding_credit_authorized"] for row in vehicle_observations["observations"])
assert reviewed_fleet_vehicle_register_overlay["noninheritance_boundary"]["page_owner_not_inherited_or_recredited"] is True
assert reviewed_fleet_vehicle_register_overlay["noninheritance_boundary"]["historical_sentinel_preserved_not_rewritten_or_credited"] is True
assert reviewed_fleet_vehicle_register_overlay["noninheritance_boundary"]["neighbor_identity_or_outcome_not_inherited"] is True
assert reviewed_fleet_vehicle_register_overlay["queue_boundary"]["selected_index_81_integrated"] is True
assert reviewed_fleet_vehicle_register_overlay["queue_boundary"]["index_82_reviewed_context_not_recredited"] is True
assert reviewed_fleet_vehicle_register_overlay["queue_boundary"]["next_unresolved_index"] == 83
reviewed_fleet_daily_check_overlay = reviewed_fleet_vehicle_register_overlay
fleet_observations = vehicle_observations

assert dashboard_run_168["run_id"] == "RUN-168-AUDIT-DASHBOARD-VERIFICATION-WAVE-30"
assert dashboard_run_168["verification"]["viewports_verified"] == 4
assert dashboard_run_168["verification"]["navigation_clicks_required"] == 10
assert dashboard_run_168["verification"]["navigation_clicks_passed"] == 10
assert len(dashboard_run_168["verification"]["navigation_results"]) == 10
assert all(row["pass"] for row in dashboard_run_168["verification"]["navigation_results"])
assert dashboard_run_168["verification"]["visible_static_checks_passed"] == 39
assert dashboard_run_168["verification"]["post_materialization_local_resources"] == "414/414"
assert {key for key, value in dashboard_run_168["credit_boundary"].items() if value} == {
    "audit_dashboard_run_168_builder_idempotence_correction",
    "exact_audit_dashboard_artifact",
}
run_168_payload_without_seal = dict(dashboard_run_168)
run_168_seal = run_168_payload_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_168_payload_without_seal) == run_168_seal == "252e6317bffc66e7b07c98f10dd2968c73c4f95317b3dd6d45d5a9c4f9285726"
run_168_dashboard_pin = dashboard_run_168["pins"]["run_168_dashboard"]
run_168_dashboard_payload = git_file_at_commit(
    RUN_168_VERIFIED_DASHBOARD_COMMIT,
    run_168_dashboard_pin["path"],
)
assert hashlib.sha256(run_168_dashboard_payload).hexdigest() == run_168_dashboard_pin["sha256"] == "80360ae152642e4f7c0c90b18c42e76fb156bf8cd34eb9df17b358170cc71b89"
assert git_blob_id_bytes(run_168_dashboard_payload) == run_168_dashboard_pin["git_blob_id"]
assert (len(run_168_dashboard_payload), run_168_dashboard_payload.count(b"\n")) == (
    run_168_dashboard_pin["bytes"],
    run_168_dashboard_pin["lines"],
)

assert fleet_vehicle_alerts_cohort["run_id"] == "RUN-169-OUTCOME-NEUTRAL-FLEET-VEHICLE-ALERTS-CONFIG-ROUTE-ACTION-COHORT-WAVE-31"
assert fleet_vehicle_alerts_cohort["status"] == "OUTCOME_NEUTRAL_CURRENT_PIN_SOURCE_PACKET_READY_INDEPENDENT_REVIEW_REQUIRED_ZERO_OWNERSHIP_OR_CORRECTNESS_CREDIT"
assert fleet_vehicle_alerts_review["run_id"] == "RUN-169R-INDEPENDENT-OUTCOME-NEUTRAL-FLEET-VEHICLE-ALERTS-CONFIG-ROUTE-ACTION-REVIEW-WAVE-31"
assert fleet_vehicle_alerts_review["decision"] == "GO"
assert reviewed_fleet_vehicle_alerts_overlay["run_id"] == "RUN-170-REVIEWED-OUTCOME-NEUTRAL-FLEET-VEHICLE-ALERTS-CONFIG-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-31"
assert reviewed_fleet_vehicle_alerts_overlay_review["run_id"] == "RUN-170R-INDEPENDENT-REVIEWED-OUTCOME-NEUTRAL-FLEET-VEHICLE-ALERTS-CONFIG-ROUTE-ACTION-OWNERSHIP-OVERLAY-REVIEW-WAVE-31"

alerts_counts = reviewed_fleet_vehicle_alerts_overlay["combined_counts"]
alerts_queue = reviewed_fleet_vehicle_alerts_overlay["queue_accounting"]
assert (
    alerts_counts["source_owner_records"],
    alerts_counts["route_owner_records"],
    alerts_counts["page_owner_records"],
    alerts_counts["static_controller_action_bridges"],
) == (665, 308, 357, 96)
assert (
    alerts_counts["distinct_feature_ids"],
    alerts_counts["distinct_H_feature_ids"],
    alerts_counts["distinct_D_feature_ids"],
) == (256, 234, 22)
assert (
    alerts_counts["route_distinct_feature_ids"],
    alerts_counts["page_distinct_feature_ids"],
    alerts_counts["route_page_feature_overlap"],
) == (64, 242, 50)
assert alerts_counts["bounded_static_source_ownership_percent"] == "16.925426"
assert (
    alerts_counts["bounded_static_source_residual_records"],
    alerts_counts["residual_explicit_unmapped_routes"],
) == (3264, 2893)
assert (
    alerts_queue["reviewed_queue_surface_rows"],
    alerts_queue["pending_unreviewed_queue_surface_rows"],
    alerts_queue["owner_queue_surface_rows"],
    alerts_queue["queue_surfaces_without_ownership"],
) == (119, 388, 97, 410)
assert 3929 == 665 + 3264 and 665 == 308 + 357 and 256 == 64 + 242 - 50
assert 3218 == 308 + 12 + 5 + 0 + 2893 and 711 == 357 + 9 + 0 + 0 + 345
assert 507 == 119 + 388 and 119 == 97 + 10 + 5 + 0 + 7 and 410 == 388 + 10 + 5 + 0 + 7

alerts_boundary = reviewed_fleet_vehicle_alerts_overlay["queue_boundary"]
assert alerts_boundary["selected_index_83_integrated"] is True
assert (
    alerts_boundary["next_unresolved_index"],
    alerts_boundary["next_unresolved_queue_id"],
    alerts_boundary["next_unresolved_route_record_id"],
    alerts_boundary["next_unresolved_route_name"],
    alerts_boundary["next_unresolved_action_expression"],
) == (
    84,
    "RUN090-ROUTE-0085",
    "RUN077-ROUTE-0693",
    "fleet-assets.trips.index",
    "[VehicleController::class, 'trips']",
)
assert alerts_boundary["next_unresolved_queue_record_sha256"] == "928eeec741742f8329dd7e191a71f2d5249775b6de64e6a698a72836345ca011"
assert alerts_boundary["reviewed_key_list_sha256"] == "acfca5e54d64c54334dbd94b30104244b3d2d6722a5426439aec7a8aa62d3ab5"
assert alerts_boundary["reviewed_key_list_canonical_json_sha256"] == "e85b37e5410c1cc861f9116061e88fb82fdb854e5dc94e56eefe1947b3a7b510"

alerts_observations = reviewed_fleet_vehicle_alerts_overlay["provisional_assurance_observation_preservation"]
assert alerts_observations["observation_count"] == len(alerts_observations["observations"]) == 3
assert all(
    row["status"] == "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING"
    and not row["correctness_credit_authorized"]
    and not row["final_finding_credit_authorized"]
    for row in alerts_observations["observations"]
)
assert {key for key, value in reviewed_fleet_vehicle_alerts_overlay["credit_boundary"].items() if value} == {
    "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD",
    "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION",
}
assert reviewed_fleet_vehicle_alerts_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_fleet_vehicle_alerts_overlay_review["decision"]["independent_reviews"] == 3
assert reviewed_fleet_vehicle_alerts_overlay_review["decision"]["discrepancies"] == 0
assert reviewed_fleet_vehicle_alerts_overlay_review["decision"]["reporting_materialization_authorized"] is True
assert reviewed_fleet_vehicle_alerts_overlay_review["decision"]["gate_4_complete"] is False
assert {key for key, value in reviewed_fleet_vehicle_alerts_overlay_review["credit_boundary"].items() if value} == {
    "INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"
}
reviewed_fleet_daily_check_overlay = reviewed_fleet_vehicle_alerts_overlay
fleet_observations = alerts_observations
assert 3929 == 660 + 3269
assert 660 == 303 + 357
assert 3218 == 303 + 12 + 5 + 2898
assert 711 == 357 + 9 + 345

candidates = wave1["candidates"] + wave2["candidates"] + wave3["candidates"]
candidate_ids = [row["candidate_id"] for row in candidates]
assert len(candidates) == 186
assert len(set(candidate_ids)) == 186

targets = canonical["targets"]
target_ids = [row["feature_id"] for row in targets]
class_counts = Counter(row["feature_class"] for row in targets)
with (AUDIT_DIR / "03-feature-to-benchmark-matrix.csv").open(encoding="utf-8-sig", newline="") as handle:
    live_matrix_rows = list(csv.DictReader(handle))
assert len(live_matrix_rows) == 340
assert [row["feature_id"] for row in live_matrix_rows] == target_ids
assert Counter(row["feature_class"] for row in live_matrix_rows) == {"H": 300, "D": 40}
live_mapping_rows = [row for row in live_matrix_rows if row["benchmark_mapping_credit"] == "true"]
live_unresolved_rows = [row for row in live_matrix_rows if row["benchmark_mapping_credit"] == "false"]
assert sorted(row["feature_id"] for row in live_mapping_rows) == [
    "CAP-FIN-BILLING-INVOICE-LIFECYCLE",
    "CAP-FIN-FX-REVALUATION",
]
assert len(live_mapping_rows) == 2
assert len(live_unresolved_rows) == 338
assert Counter(row["no_match_evidence"] for row in live_matrix_rows) == {
    "NOT_DOCUMENTED_CURRENT_AUDIT": 338,
    "NCM_NOT_AUTHORIZED_NO_TARGET_SPECIFIC_CATALOGUE_COMPLETE_SEARCH": 2,
}
invoice_mapping = next(row for row in live_mapping_rows if row["feature_id"] == "CAP-FIN-BILLING-INVOICE-LIFECYCLE")
fx_mapping = next(row for row in live_mapping_rows if row["feature_id"] == "CAP-FIN-FX-REVALUATION")
assert invoice_mapping["selected_open_source_benchmark"] == "frappe/erpnext; Dolibarr/dolibarr"
assert fx_mapping["selected_open_source_benchmark"] == "frappe/erpnext"
with (AUDIT_DIR / "06-open-source-benchmark-register.csv").open(encoding="utf-8-sig", newline="") as handle:
    live_register_rows = list(csv.DictReader(handle))
assert len(live_register_rows) == 98
assert sorted(
    row["project"] for row in live_register_rows
    if row["current_target_specific_mapping_credit"] == "true"
) == ["Dolibarr/dolibarr", "frappe/erpnext"]
assert next(
    row for row in live_register_rows if row["project"] == "bigcapitalhq/bigcapital"
)["current_target_specific_mapping_credit"] == "false"
assert run_145_mapping["status"] == "TWO_INDEPENDENTLY_ADJUDICATED_STATIC_BENCHMARK_MAPPINGS_INTEGRATED"
assert run_145_mapping["outputs"]["matrix"]["sha256"] == CURRENT_RUN_145_MATRIX_SHA256
assert run_145_mapping["outputs"]["benchmark_register"]["sha256"] == CURRENT_RUN_145_REGISTER_SHA256
assert run_145_mapping["counts"] == {
    "benchmark_mappings": 2,
    "final_no_matches_or_NCMs": 0,
    "unresolved_targets": 338,
    "project_rows_with_current_target_mapping_credit": 2,
}
assert run_145_mapping["invariants"]["BigCapital_register_row_unchanged"] is True
assert run_145_mapping["invariants"]["NCM_authorized"] is False
assert dashboard_run_144["status"] == "AUDIT_ARTIFACT_RESPONSIVE_LINK_CONSOLE_VERIFICATION_GO_ZERO_APPLICATION_CREDIT"
assert dashboard_run_144["verification"]["viewports_verified"] == 4
assert len(dashboard_run_144["verification"]["exact_visible_boundary_checks"]) == 23
assert all(dashboard_run_144["verification"]["exact_visible_boundary_checks"].values())
assert dashboard_run_144["verification"]["navigation_targets"] == "10/10"
assert run_146_reporting["status"] == "REPORTING_MATERIALIZED_EXACT_TWO_STATIC_BENCHMARK_MAPPINGS_ZERO_OTHER_CREDIT"
assert run_146_reporting["pins"]["matrix_sha256"] == CURRENT_RUN_145_MATRIX_SHA256
assert run_146_reporting["pins"]["benchmark_register_sha256"] == CURRENT_RUN_145_REGISTER_SHA256
assert run_146_reporting["counts"]["benchmark_mapped"] == 2
assert run_146_reporting["counts"]["final_no_matches_or_NCMs"] == 0
assert run_146_reporting["counts"]["unresolved"] == 338
assert run_146_reporting["baseline_report_sha256"]["00-executive-summary.md"] == "45f92926a50814f57f64fba1d72ff87ebe700df3ddfdab41192f9c5997f66468"
assert run_146_reporting["outputs"]["00-executive-summary.md"]["sha256"] == "616fd626dc1292896955e657812404ccbdbb4e425b736f68b9ccb8f87e63d8ab"
assert run_146_reporting["outputs"]["13-unresolved-questions-and-evidence-gaps.md"]["sha256"] == "ada6ad349bb29d9168b7e93e5fc7d494d8701254b8fe10faa2df28afb0725965"
assert run_146_reporting["outputs"]["findings.json"]["sha256"] == "9848a8edd8c7fa56cc753a77746f66434912ac0bafe42110f999457a7c43da5c"
# RUN-146 output hashes close that immutable historical receipt. They must not
# be compared to live reporting surfaces intentionally advanced by RUN-150.
linkage_sentinel = "NOT_ESTABLISHED_CURRENT_AUDIT"
live_gap_ids = {
    field: sorted(row["feature_id"] for row in live_matrix_rows if row[field] == linkage_sentinel)
    for field in ("route_names", "route_paths", "page_files", "backend_anchors", "test_anchors")
}
live_both_gap_ids = sorted(set(live_gap_ids["route_paths"]) & set(live_gap_ids["page_files"]))
assert live_gap_ids["route_paths"] == route_page_integration["remaining_gaps"]["route_paths"]
assert live_gap_ids["route_names"] == route_page_integration["remaining_gaps"]["route_names"]
assert live_gap_ids["page_files"] == route_page_integration["remaining_gaps"]["page_files"]
assert live_gap_ids["backend_anchors"] == route_page_integration["remaining_gaps"]["backend_anchors"]
assert live_gap_ids["test_anchors"] == route_page_integration["remaining_gaps"]["test_anchors"]
assert live_both_gap_ids == route_page_integration["remaining_gaps"]["both_route_and_page"]
assert len(live_gap_ids["route_paths"]) == route_page_integration["counts"]["remaining_missing_route_paths"]
assert len(live_gap_ids["route_names"]) == route_page_integration["counts"]["remaining_missing_route_names"]
assert len(live_gap_ids["page_files"]) == route_page_integration["counts"]["remaining_missing_page_files"]
assert len(live_both_gap_ids) == route_page_integration["counts"]["remaining_missing_both_route_and_page"]
assert len(live_gap_ids["backend_anchors"]) == route_page_integration["counts"]["remaining_missing_backend_anchors"]
assert len(live_gap_ids["test_anchors"]) == route_page_integration["counts"]["remaining_missing_test_anchors"]
assert canonical["run_id"] == "RUN-030"
assert identity_agents["run_id"] == "RUN-030"
assert identity_agents["status"] == "CANONICAL_IDENTITY_INDEPENDENCE_AND_INTEGRATION_REGISTER_COMPLETE"
assert canonical["generated_at"] == identity_agents["generated_at"]
assert len(targets) == len(set(target_ids)) == 340
assert class_counts == {"H": 300, "D": 40}
assert canonical["counts"]["source_candidates"] == 186
assert canonical["counts"]["mapped_sources"] == 185
assert canonical["counts"]["excluded_sources"] == 1
assert canonical["counts"]["layer_a_edges"] == 362
assert canonical["counts"]["layer_a_targets"] == 338
assert canonical["counts"]["layer_b_catalog_relations"] == 14
assert canonical["counts"]["layer_b_catalog_targets"] == 9
assert canonical["counts"]["layer_b_new_targets"] == 2
assert canonical["counts"]["canonical_targets"] == 340
assert canonical["counts"]["classes"] == {"H": 300, "D": 40, "M": 0}
assert identity_agents["agreement"]["independent_reconstructions"] == 3
assert identity_agents["agreement"]["remaining_identity_conflicts"] == 0
assert identity_agents["agreement"]["canonical_targets"] == 340
assert identity_agents["agreement"]["classes"] == {"H": 300, "D": 40, "M": 0}
assert canonical["static_evidence_gaps"]["targets_missing_route_anchor"] == 120
assert canonical["static_evidence_gaps"]["targets_missing_page_anchor"] == 226
assert canonical["static_evidence_gaps"]["targets_missing_both_route_and_page_anchor"] == 116
assert canonical["completion_gate"]["canonical_static_identity_frozen"] is True
for credit in (
    "runtime_credit", "browser_credit", "test_execution_credit", "benchmark_credit",
    "ease_credit", "release_credit", "completion_credit",
):
    assert canonical["completion_gate"][credit] == 0
assert canonical["completion_gate"]["audit_complete"] is False
assert all(
    target[credit] == 0
    for target in targets
    for credit in (
        "runtime_credit", "browser_credit", "test_execution_credit",
        "benchmark_credit", "ease_credit", "completion_credit",
    )
)
assert runtime["pins"]["application_commit"] == canonical["source_pin"]["application_commit"]
assert runtime["pins"]["application_tree"] == canonical["source_pin"]["application_tree"]
assert runtime["sanitized_environment_observations"]["vendor_autoload_exists"] is False
assert runtime["setup_boundary"]["setup_executed"] is False
assert runtime["gate_result"]["framework_route_denominator_established"] is False
assert runtime["gate_result"]["current_schema_snapshot_established"] is False
assert runtime["gate_result"]["tests_executed"] == 0
assert runtime["gate_result"]["runtime_credit"] == 0
assert deployment["pins"]["application_commit"] == canonical["source_pin"]["application_commit"]
assert deployment["pins"]["application_tree"] == canonical["source_pin"]["application_tree"]
assert deployment["identity_assessment"]["deployed_commit_or_tree_proven"] is False
assert deployment["gate_result"]["deployment_identity_established"] is False
assert deployment["gate_result"]["current_source_application_pages_observed"] == 0
assert deployment["gate_result"]["current_source_routes_observed"] == 0
assert deployment["gate_result"]["current_source_workflows_executed"] == 0
assert deployment["gate_result"]["browser_credit"] == 0
assert deployed_selected["run_id"] == "RUN-060"
assert deployed_selected["status"] == "UNKNOWN_BUILD_SELECTED_FEATURE_OBSERVATION_NORMALIZED_ZERO_CURRENT_SOURCE_CREDIT"
assert deployed_selected["audit_application_pin"] == {
    "application_commit": canonical["source_pin"]["application_commit"],
    "application_tree": canonical["source_pin"]["application_tree"],
}
assert deployed_selected["deployed_identity"] == {
    "deployed_application_commit": None,
    "deployed_application_tree": None,
    "reproducible_build_marker": None,
    "attribution_status": "UNPROVEN",
}
assert deployed_selected["actor_and_fixture_boundary"] == {
    "actor_role": "UNKNOWN",
    "approved_site_context": "UNKNOWN",
    "fixture_safety_context": "UNKNOWN",
    "environment_classification": "UNKNOWN",
    "signed_in_session_observed": True,
}
deployed_selected_counts = deployed_selected["counts"]
assert deployed_selected_counts == {
    "selected_feature_ids": 6,
    "selected_routes": 6,
    "prompt_dimensions_sampled_on_unknown_build": 4,
    "unknown_build_route_viewport_cells": 24,
    "unknown_build_overlay_families": 5,
    "unknown_build_provisional_candidates": 2,
    "current_build_required_viewports_credited": 0,
    "current_source_routes": 0,
    "current_source_route_viewport_cells": 0,
    "current_source_overlay_families": 0,
    "meaningful_mutations": 0,
    "forms_submitted": 0,
    "records_changed": 0,
    "screenshots_retained": 0,
    "console_warnings_or_errors": 0,
}
deployed_selected_cells = deployed_selected["route_viewport_observations"]
assert len(deployed_selected["selected_features"]) == 6
assert len(deployed_selected_cells) == 24
assert len({row["cell_id"] for row in deployed_selected_cells}) == 24
assert {(row["width"], row["height"]) for row in deployed_selected_cells} == {
    (1440, 900), (1280, 800), (1024, 768), (390, 844),
}
assert all(row["attribution"] == "DEPLOYED_UNKNOWN_BUILD_ONLY" for row in deployed_selected_cells)
assert all(row["current_source_credit"] is False for row in deployed_selected_cells)
assert len(deployed_selected["overlay_observations"]) == 5
assert {
    row["candidate_id"] for row in deployed_selected["provisional_unknown_build_candidates"]
} == {
    "VIS-UNKNOWN-BUILD-FOCUS-RESTORE-01",
    "VIS-UNKNOWN-BUILD-HR-ESCAPE-01",
}
assert deployed_selected["credit_boundary"]["observation_record_accepted"] is True
assert all(
    value is False
    for key, value in deployed_selected["credit_boundary"].items()
    if key != "observation_record_accepted"
)
assert deployed_selected_review["corrected_review"]["verdict"] == "ACCEPT_CORRECTED"
assert deployed_selected_review["corrected_review"]["remaining_issues"] == []
assert deployed_selected_review["corrected_review"]["generator"]["sha256"] == "ca190ab113ab5a18e31fe0f533f2ae536410d0663932bd615be384b5ae0c87e3"
assert deployed_selected_review["corrected_review"]["output"]["sha256"] == "e9c95d695212875a756e704ec0754b0d6998476f9e34d6dce166f8e520027fc3"
assert deployed_selected_review["credit_boundary"]["unknown_build_observation_accepted"] is True
assert all(
    value is False
    for key, value in deployed_selected_review["credit_boundary"].items()
    if key != "unknown_build_observation_accepted"
)
benchmark_register = benchmark["project_register_current_audit"]
assert benchmark_register["current_upstream_full_triage_unique_repository_completions"] == 0
assert benchmark_register["current_upstream_full_triage_prompt_occurrence_completions"] == 0
assert benchmark_register["current_project_triage_completion_credit"] == 0
for credit in (
    "upstream_full_triage_credit", "exact_behaviour_credit", "edition_boundary_credit",
    "root_licence_confirmation_credit", "maintenance_quality_credit",
    "selection_or_outcome_credit", "feature_mapping_credit", "benchmark_completion_credit",
):
    assert benchmark_register["metadata_credit_boundary"][credit] == 0
assert benchmark["current_feature_gate"]["verified_benchmark_or_documented_no_credible_match"] == 0
assert project_triage["run_id"] == "RUN-034"
assert project_triage_agents["run_id"] == "RUN-034"
assert project_triage["generated_at"] == project_triage_agents["generated_at"]
assert project_triage["project_universe"]["repositories"] == 95
assert project_triage["project_universe"]["prompt_occurrences"] == 98
assert project_triage["counts"]["observer_records"] == 95
triage_projects = project_triage["projects"]
assert len(triage_projects) == 95
assert len({row["canonical_repository"] for row in triage_projects}) == 95
assert sum(row["prompt_occurrence_count"] for row in triage_projects) == 98
assert project_triage["counts"]["reported_statuses"] == {
    "COMPLETE_OBSERVER_REPORTED": 79,
    "PARTIAL_BLOCKED_REPORTED": 16,
}
assert project_triage["counts"]["observer_statuses"] == {
    "COMPLETE_OBSERVER_TRIAGE": 79,
    "PARTIAL_BLOCKED": 16,
}
assert project_triage["counts"]["metadata_head_relationships"] == {
    "DIFFERENT_LATER_OBSERVATION_NO_ANCESTRY_INFERENCE": 17,
    "SAME_AS_BASELINE": 78,
}
assert Counter(row["computed_observer_triage_status"] for row in triage_projects) == {
    "COMPLETE_OBSERVER_TRIAGE": 79,
    "PARTIAL_BLOCKED": 16,
}
assert Counter(row["metadata_head_relationship"] for row in triage_projects) == {
    "DIFFERENT_LATER_OBSERVATION_NO_ANCESTRY_INFERENCE": 17,
    "SAME_AS_BASELINE": 78,
}
assert all(
    row["canonical_target_mapping_status"] == "NOT_PERFORMED_OBSERVER_ONLY"
    and row["target_specific_mapping_credit"] is False
    and row["benchmark_credit"] is False
    and row["benchmark_completion_credit"] is False
    and row["completion_credit"] is False
    for row in triage_projects
)
assert project_triage["counts"]["formal_upstream_full_triage_credit"] == 0
assert project_triage["counts"]["canonical_targets"] == len(targets) == 340
assert project_triage["counts"]["feature_benchmark_mappings_or_final_no_matches"] == 0
assert project_triage_agents["agreement"]["disjoint_union_repositories"] == 95
assert project_triage_agents["agreement"]["prompt_occurrence_weight"] == 98
assert project_triage_agents["agreement"]["remaining_partition_collisions"] == 0
assert project_triage_agents["agreement"]["project_universe_sha256"] == project_triage["project_universe"]["sha256"]
assert all(
    row["external_mutations_attestation"] == "NOT_RECORDED_IN_RAW_ARTIFACT"
    for row in project_triage_agents["agents"]
)
for evidence in (project_triage, project_triage_agents):
    assert evidence["credit_boundary"]["observer_project_evidence_materialized"] == 95
    for credit in (
        "upstream_full_triage_credit", "neutral_requirement_credit",
        "current_product_comparison_credit", "target_specific_mapping_credit",
        "benchmark_completion_credit", "runtime_credit", "browser_credit",
        "ease_credit", "release_credit", "completion_credit",
    ):
        assert evidence["credit_boundary"][credit] == 0
    assert evidence["credit_boundary"]["audit_complete"] is False

expected_triage_raw = {
    "evidence/benchmark/raw-run-031-upstream-project-triage-partition-01.json": "e6b8e4c9ef95f546397ea0a4e640840503d24fd42aac09abe688f952313032be",
    "evidence/benchmark/raw-run-032-upstream-project-triage-partition-02.json": "dc6006728e0ebbaf40febb3139619e9e01f9b6e8a5d46ef12f0667a885f9cc49",
    "evidence/benchmark/raw-run-033-upstream-project-triage-partition-03.json": "f58f77c34136c4dfdc93e83aaa741a79ca067c709bbe8d0b15e646bfc7ab3a16",
}
triage_raw_partitions = project_triage["inputs"]["raw_partitions"]
assert {row["raw_file"]: row["raw_sha256"] for row in triage_raw_partitions} == expected_triage_raw
assert {row["raw_file"]: row["raw_sha256"] for row in project_triage_agents["agents"]} == expected_triage_raw
assert [row["run_id"] for row in triage_raw_partitions] == ["RUN-031", "RUN-032", "RUN-033"]
assert sum(row["repository_count"] for row in triage_raw_partitions) == 95
assert sum(row["occurrence_weight"] for row in triage_raw_partitions) == 98
assert all(sha256_file(path) == expected_sha256 for path, expected_sha256 in expected_triage_raw.items())
assert partial_resolution["run_id"] == "RUN-038"
assert partial_resolution_agents["run_id"] == "RUN-038"
assert partial_resolution["generated_at"] == partial_resolution_agents["generated_at"]
assert partial_resolution["inputs"]["base_wave_sha256"] == sha256_file(
    "evidence/benchmark/current-upstream-project-triage-wave-01.json"
)
assert partial_resolution["inputs"]["canonical_matrix_guard_sha256"] == "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4"
assert partial_resolution["counts"]["base_partial_records"] == 16
assert partial_resolution["counts"]["reviewed_partial_records"] == 16
assert partial_resolution["counts"]["resolution_decisions"] == {
    "RESOLVED_OBSERVER_EVIDENCE": 9,
    "RETAIN_PARTIAL": 7,
}
assert partial_resolution["counts"]["effective_observer_statuses"] == {
    "COMPLETE_OBSERVER_TRIAGE": 88,
    "PARTIAL_BLOCKED": 7,
}
resolution_records = partial_resolution["records"]
assert len(resolution_records) == 16
assert [row["ordinal"] for row in resolution_records] == [
    3, 4, 19, 20, 22, 23, 39, 46, 48, 58, 71, 74, 76, 83, 85, 90,
]
assert Counter(row["resolution_status"] for row in resolution_records) == {
    "RESOLVED_OBSERVER_EVIDENCE": 9,
    "RETAIN_PARTIAL": 7,
}
assert all(
    row[credit] is False
    for row in resolution_records
    for credit in (
        "target_specific_mapping_credit", "benchmark_credit", "completion_credit",
        "neutral_requirement_credit", "current_product_comparison_credit",
        "final_no_match_credit", "benchmark_completion_credit",
    )
)
assert partial_resolution_agents["agreement"]["disjoint_partial_records_reviewed"] == 16
assert partial_resolution_agents["agreement"]["remaining_unreviewed_run_034_partial_records"] == 0
expected_resolution_raw = {
    "evidence/benchmark/raw-run-035-upstream-partial-resolution-partition-01.json": "baaf5056749c26c8f4a251c63738200006f895de6244a87cd72db184f9417ad6",
    "evidence/benchmark/raw-run-036-upstream-partial-resolution-partition-02.json": "f74676e9520fe1eeaf68abf3a9c1a62ad9532b0142858ac7b575d4c403c351c8",
    "evidence/benchmark/raw-run-037-upstream-partial-resolution-partition-03.json": "c29546b9373ac6354cfe1379648f1afee70fa0fb30aab5a5692df63ff5ac1724",
}
assert {
    row["raw_file"]: row["raw_sha256"]
    for row in partial_resolution["inputs"]["raw_partitions"]
} == expected_resolution_raw
assert {
    row["raw_file"]: row["raw_sha256"]
    for row in partial_resolution_agents["agents"]
} == expected_resolution_raw
assert all(
    sha256_file(path) == expected_sha256
    for path, expected_sha256 in expected_resolution_raw.items()
)
assert partial_resolution["credit_boundary"]["partial_records_reviewed"] == 16
for evidence in (partial_resolution, partial_resolution_agents):
    for credit in (
        "upstream_full_triage_credit", "neutral_requirement_credit",
        "current_product_comparison_credit", "target_specific_mapping_credit",
        "benchmark_completion_credit", "runtime_credit", "browser_credit",
        "ease_credit", "release_credit", "completion_credit",
    ):
        assert evidence["credit_boundary"][credit] == 0
    assert evidence["credit_boundary"]["audit_complete"] is False
assert target_comparison["run_id"] == "RUN-046"
assert target_comparison_agents["run_id"] == "RUN-046"
assert target_comparison["generated_at"] == target_comparison_agents["generated_at"]
assert target_comparison["source_pin"]["application_commit"] == canonical["source_pin"]["application_commit"]
assert target_comparison["source_pin"]["application_tree"] == canonical["source_pin"]["application_tree"]
assert target_comparison["source_pin"]["canonical_identity_sha256"] == sha256_file(
    "evidence/source/current-canonical-feature-identity-wave-01.json"
)
assert target_comparison["source_pin"]["canonical_matrix_guard_sha256"] == "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4"
assert target_comparison["stage_lineage"] == {
    "status": "PASS",
    "upstream_identity_withheld_from_agent_b": True,
    "current_source_withheld_from_agent_b": True,
    "upstream_identity_withheld_from_agent_c": True,
    "scope_gate_hash_bound": True,
    "post_reconciliation_independent_adjudication": True,
    "source_anchor_validation_pass": True,
    "required_facet_crosswalk_pass": True,
}
target_counts = target_comparison["counts"]
assert target_counts == {
    "wave_targets": 6,
    "candidate_locator_packets": 5,
    "bounded_no_candidate_packets": 1,
    "current_source_packets": 6,
    "scope_go_bounded": 3,
    "scope_deferred_composites": 3,
    "blind_neutral_requirement_packets": 3,
    "clean_current_comparisons": 3,
    "facet_reconciliation_targets": 3,
    "facet_reconciliation_facets": 18,
    "required_facet_crosswalk_rows": 18,
    "unmapped_required_facets": 0,
    "source_anchor_occurrences_validated": 210,
    "independent_no_go_verdicts": 6,
    "formal_edges": 0,
    "canonical_targets": 340,
    "promoted_feature_mappings_or_final_no_matches": 0,
}
target_records = target_comparison["records"]
assert len(target_records) == len({row["feature_id"] for row in target_records}) == 6
assert all(row["feature_id"] in target_ids for row in target_records)
assert all(
    row[credit] is False
    for row in target_records
    for credit in (
        "target_specific_mapping_credit", "benchmark_credit", "final_no_match_credit",
        "runtime_credit", "test_execution_credit", "completion_credit",
    )
)
assert all(row["formal_edge_count"] == 0 for row in target_records)
assert target_comparison["canonical_matrix_disposition"]["status"] == "UNCHANGED_GUARDED_OVERLAY_ONLY"
assert target_comparison["canonical_matrix_disposition"]["sha256"] == "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4"
assert target_comparison_agents["agreement"] == {
    "wave_targets": 6,
    "scope_go_equals_neutralized_equals_compared": 3,
    "scope_deferred_equals_facet_overlay_targets": 3,
    "facet_count": 18,
    "required_facet_crosswalk_count": 18,
    "unmapped_required_facets": 0,
    "stage_lineage_status": "PASS",
    "source_anchor_validation_status": "PASS",
    "source_anchor_occurrences_checked": 210,
    "post_reconciliation_independent_adjudication": True,
    "independent_no_go_verdicts": 6,
    "formal_edges": 0,
    "remaining_stage_conflicts": 0,
}
assert target_comparison_agents["governing_prompt"] == {
    "path": "C:/Users/steph/Downloads/oblivion-open-source-benchmark-and-8-pass-audit-prompt.md",
    "sha256": "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f",
    "bytes": 88305,
}
required_stage_provenance = {
    "responsible_agent_identity", "repository_commit_scope", "architecture_rule",
    "scope", "pass_lens", "evidence_schema_and_count", "no_write_rule",
    "completion_test", "unresolved_gaps",
}
assert len(target_comparison_agents["agents"]) == 7
assert all(
    required_stage_provenance.issubset(row)
    and all(isinstance(row[field], str) and row[field].strip() for field in required_stage_provenance)
    for row in target_comparison_agents["agents"]
)
assert required_stage_provenance.issubset(target_comparison_agents["root_integrator"])
expected_target_raw = {
    "evidence/benchmark/raw-run-039-target-upstream-behaviour-wave-01.json": "1675bc983ab446b94ed0b175791f81c2c3548a90a3d004e8fb5d1ecde8bfd3db",
    "evidence/benchmark/raw-run-040-current-product-source-packets-wave-01.json": "4d9ea76a79e023e69c996ae8685f19651a0e309a1ffb217a889d7058f6505602",
    "evidence/benchmark/raw-run-041-current-source-red-team-wave-01.json": "aef801877a0392df9a84ee77b8a4c573170de68f6841162aea61b655c057ed7c",
    "evidence/benchmark/raw-run-042-neutral-requirements-wave-01.json": "e17b9aed0268ca22e2a13c843791d3ca65ce818607a9a10a40429b232beab995",
    "evidence/benchmark/raw-run-043-current-neutral-comparison-wave-01.json": "fd4cb0fca2ca444f36235dab554cf60c9d33e2be6b4cd79e4f4262ad316c2e7c",
    "evidence/benchmark/raw-run-044-current-source-facet-reconciliation-wave-01.json": "b63de4b47c8703de72550c6c94db9bbe86e5c4aca5bdd891bf42e9f3f1114457",
    "evidence/benchmark/raw-run-045-wave-01-independent-adjudication.json": "734a0fd3f6c8468a237e0d29af902ef6d622dc4dd2c17981ec4a19f2e9723664",
}
assert {
    row["file"]: row["sha256"]
    for row in target_comparison["inputs"]["raw_runs"]
} == expected_target_raw
assert {
    row["raw_file"]: row["raw_sha256"]
    for row in target_comparison_agents["agents"]
} == expected_target_raw
assert all(
    sha256_file(path) == expected_sha256
    for path, expected_sha256 in expected_target_raw.items()
)
for evidence in (target_comparison, target_comparison_agents):
    for credit in (
        "target_specific_mapping_credit", "benchmark_credit", "final_no_match_credit",
        "runtime_credit", "browser_credit", "test_execution_credit", "ease_credit",
        "release_credit", "completion_credit",
    ):
        assert evidence["credit_boundary"][credit] == 0
    assert evidence["credit_boundary"]["audit_complete"] is False
assert upstream_facet_refinement["run_id"] == "RUN-047"
assert upstream_facet_refinement_agents["run_id"] == "RUN-047"
assert upstream_facet_refinement["counts"]["feature_ids"] == 6
assert upstream_facet_refinement["counts"]["facets"] == 24
assert upstream_facet_refinement["counts"]["candidate_locators_for_later_clean_comparison"] == 12
assert upstream_facet_refinement["counts"]["bounded_no_candidate_not_final_no_match"] == 12
assert upstream_facet_refinement_agents["validation"] == {
    "expected_feature_facet_pairs": 24,
    "observed_feature_facet_pairs": 24,
    "duplicate_pairs": 0,
    "candidate_locators": 12,
    "bounded_no_candidates": 12,
    "stage_conflicts": 0,
    "all_downstream_credits_false": True,
}
assert facet_refinement["run_id"] == "RUN-057"
assert facet_refinement_agents["run_id"] == "RUN-057"
assert facet_refinement["generated_at"] == facet_refinement_agents["generated_at"]
assert facet_refinement["stage_lineage"]["status"] == "PASS_CORRECTED_CLEAN_SPEC_CHAIN_STATIC_ONLY_ZERO_CREDIT"
assert facet_refinement["stage_lineage"]["run_053_identity_stripped_agent_a_packets_complete"] is True
assert facet_refinement["stage_lineage"]["run_054_fresh_agent_b_neutralization_complete"] is True
assert facet_refinement["stage_lineage"]["run_055_fresh_agent_c_same_packet_static_comparison_complete"] is True
assert facet_refinement["stage_lineage"]["run_056_fresh_agent_d_independent_adjudication_complete"] is True
assert facet_refinement["stage_lineage"]["run_056_bounded_semantic_corrections"] == 1
assert facet_refinement["stage_lineage"]["formal_edge_eligibility"] is False
facet_counts = facet_refinement["counts"]
assert facet_counts["canonical_targets"] == 340
assert facet_counts["wave_features"] == 6
assert facet_counts["selected_facet_packets"] == 24
assert facet_counts["candidate_locators"] == 12
assert facet_counts["bounded_no_candidates_not_final_no_match"] == 12
assert facet_counts["agent_a_exact_packets"] == 8
assert facet_counts["agent_a_partial_adjacent_packets"] == 4
assert facet_counts["agent_a_insufficient_adjacent_packets"] == 12
assert facet_counts["agent_b_benchmark_derived_packets"] == 8
assert facet_counts["agent_b_bounded_adjacent_packets"] == 4
assert facet_counts["agent_b_no_exact_requirement_packets"] == 12
assert facet_counts["neutral_atoms"] == 252
assert facet_counts["neutral_consumed_atoms"] == 165
assert facet_counts["neutral_retained_unknown_atoms"] == 87
assert facet_counts["specification_units"] == 24
assert facet_counts["acceptance_outcomes"] == 58
assert facet_counts["explicit_unknowns_preserved"] == 85
assert facet_counts["source_anchor_occurrences"] == 155
assert facet_counts["source_unique_anchor_strings"] == 148
assert facet_counts["source_anchor_paths"] == 84
assert facet_counts["fresh_c_lens_ratings"] == 144
assert facet_counts["fresh_d_review_decision_counts"]["total_reviews"] == 226
assert facet_counts["fresh_d_review_decision_counts"]["total_accept"] == 225
assert facet_counts["fresh_d_review_decision_counts"]["total_correct"] == 1
assert facet_counts["fresh_d_review_decision_counts"]["total_reject"] == 0
assert facet_counts["fresh_d_corrected_outcome_ids"] == ["AO-A53-024-01"]
assert facet_counts["fresh_d_lineage_pass_rows"] == 24
assert facet_counts["fresh_d_lineage_pass_features"] == 6
assert facet_counts["adjacent_packets_non_promotable"] == 16
assert facet_counts["formal_edges"] == 0
assert facet_counts["final_no_matches"] == 0
assert facet_counts["credited_targets"] == 0
assert facet_counts["promoted_feature_mappings_or_final_no_matches"] == 0
assert len(facet_refinement["records"]) == 24
assert len({row["opaque_id"] for row in facet_refinement["records"]}) == 24
assert len({(row["feature_id"], row["facet_key"]) for row in facet_refinement["records"]}) == 24
assert facet_refinement_agents["agreement"] == {
    "selected_feature_facet_packets": 24,
    "duplicate_or_missing_opaque_ids": 0,
    "source_anchor_occurrences_checked": 155,
    "source_unique_anchor_strings_checked": 148,
    "source_anchor_paths_checked": 84,
    "neutral_atoms_checked": 252,
    "neutral_atoms_consumed": 165,
    "neutral_atoms_retained_unknown": 87,
    "fresh_c_lens_ratings_checked": 144,
    "fresh_d_semantic_reviews_checked": 226,
    "fresh_d_bounded_corrections": 1,
    "fresh_d_corrected_outcome_ids": ["AO-A53-024-01"],
    "lineage_status": "PASS_CORRECTED_CLEAN_SPEC_CHAIN_STATIC_ONLY",
    "formal_edges": 0,
    "final_no_matches": 0,
    "canonical_matrix_sha256": "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4",
    "canonical_mapping_credit_fraction": "0/340",
    "all_credits_false": True,
}
assert facet_refinement["canonical_matrix_disposition"]["status"] == "UNCHANGED_CORRECTED_CHAIN_DIAGNOSTIC_ONLY"
assert facet_refinement["canonical_matrix_disposition"]["sha256"] == static_linkage_integration["matrix"]["base_sha256"]
for evidence in (upstream_facet_refinement, upstream_facet_refinement_agents):
    assert all(value is False for key, value in evidence["credit_boundary"].items() if key != "audit_complete")
    assert evidence["credit_boundary"]["audit_complete"] is False
for evidence in (facet_refinement, facet_refinement_agents):
    assert evidence["credit_boundary"]["credited_targets"] == 0
    assert evidence["credit_boundary"]["credited_rows"] == 0
    assert evidence["credit_boundary"]["formal_edges"] == 0
    assert evidence["credit_boundary"]["final_no_matches"] == 0
    assert all(
        value is False
        for key, value in evidence["credit_boundary"].items()
        if key.endswith("_credit") or key == "audit_complete"
    )
assert formal_upstream["run_id"] == "RUN-070"
assert formal_upstream_agents["run_id"] == "RUN-070"
assert formal_upstream["status"] == "9_FORMAL_UPSTREAM_PROJECT_RECORDS_12_FORMAL_FACETS_ACCEPTED_ZERO_TARGET_EDGE_ZERO_DOWNSTREAM_CREDIT"
assert formal_upstream["governing_pins"]["application_commit"] == canonical["source_pin"]["application_commit"]
assert formal_upstream["governing_pins"]["application_tree"] == canonical["source_pin"]["application_tree"]
assert formal_upstream["governing_pins"]["architecture"] == "single operating organisation across multiple Sites"
formal_counts = formal_upstream["counts"]
assert formal_counts == {
    "NCM": 0,
    "final_no_matches": 0,
    "formal_facets_accepted": 12,
    "formal_projects_accepted": 9,
    "formal_target_edges": 0,
    "matrix_rows_changed": 0,
    "register_rows_changed": 0,
}
assert formal_upstream["denominator_reconciliation"]["wave_initial_project_records"] == 18
assert formal_upstream["denominator_reconciliation"]["wave_unique_repositories"] == 18
assert formal_upstream["denominator_reconciliation"]["wave_prompt_repositories"] == 17
assert formal_upstream["denominator_reconciliation"]["wave_historical_extras"] == 1
assert formal_upstream["denominator_reconciliation"]["wave_prompt_occurrence_weight"] == 18
assert formal_upstream["denominator_reconciliation"]["accepted_prompt_repositories"] == 9
assert formal_upstream["denominator_reconciliation"]["accepted_prompt_occurrence_weight"] == 10
assert formal_upstream["target_inventory"]["target_count"] == 6
assert formal_upstream["target_inventory"]["initial_facet_aspect_subrecords"] == 29
assert formal_upstream["matrix_immutability"]["credited_rows"] == "0/340"
assert formal_upstream["matrix_immutability"]["after_sha256"] == static_linkage_integration["matrix"]["base_sha256"]
assert formal_upstream["register_immutability"]["after_sha256"] == HISTORICAL_RUN_070_REGISTER_SHA256
assert formal_upstream_agents["counts"]["formal_projects_accepted"] == 9
assert formal_upstream_agents["counts"]["formal_facets_accepted"] == 12
assert formal_upstream_agents["counts"]["formal_edges"] == 0
assert formal_upstream_agents["counts"]["final_no_matches"] == 0
assert formal_upstream["credit_boundary"]["formal_upstream_project_record_acceptance"] is True
assert formal_upstream_agents["credit_boundary"]["formal_upstream_project_record_acceptance"] is True
assert formal_upstream["credit_boundary"]["formal_upstream_facet_record_acceptance"] is True
assert formal_upstream_agents["credit_boundary"]["formal_upstream_facet_record_acceptance"] is True
assert all(
    value is False
    for key, value in formal_upstream["credit_boundary"].items()
    if key not in {"formal_upstream_project_record_acceptance", "formal_upstream_facet_record_acceptance"}
)
assert all(
    value is False
    for key, value in formal_upstream_agents["credit_boundary"].items()
    if key not in {"formal_upstream_project_record_acceptance", "formal_upstream_facet_record_acceptance"}
)

formal_manifest_paths = []
for row in formal_upstream["input_manifest"]:
    manifest_path = Path(row["path"])
    assert not manifest_path.is_absolute()
    assert ".." not in manifest_path.parts
    resolved_manifest_path = (AUDIT_DIR / manifest_path).resolve()
    assert resolved_manifest_path.is_relative_to(AUDIT_DIR.resolve())
    assert resolved_manifest_path.is_file()
    assert sha256_file(row["path"]) == row["sha256"]
    formal_manifest_paths.append(row["path"])
assert len(formal_manifest_paths) == len(set(formal_manifest_paths))

formal_integrator_sha256 = sha256_file("generators/integrate-formal-upstream-triage-wave-03.py")
formal_current_sha256 = sha256_file("evidence/benchmark/current-formal-upstream-triage-wave-03.json")
formal_agents_sha256 = sha256_file("evidence/benchmark/current-formal-upstream-triage-agent-register.json")
assert formal_integrator_sha256 == "a59537e78aede3e2543d56a08cc14be01fde5c116ba56ccfa947820eb2a5469e"
assert formal_current_sha256 == "97a3aa18ca21544728b668733f7e0db5a5164f37afc1a7e2df358f7aef4fd277"
assert formal_agents_sha256 == "6c1ac20161f965fde680ff26d9363d79e0a257b3d3af6604be567156f155ce33"
formal_evidence_links = "".join(
    f'<li><a href="{html.escape(row["path"], quote=True)}">'
    f'{html.escape(row["role"].replace("_", " ").title())}</a> '
    f'<code>{html.escape(row["sha256"])}</code></li>'
    for row in formal_upstream["input_manifest"]
)
formal_evidence_links += (
    '<li><a href="generators/integrate-formal-upstream-triage-wave-03.py">'
    f'{html.escape(formal_upstream["run_id"])} deterministic formal-upstream integrator</a> '
    f'<code>{formal_integrator_sha256}</code></li>'
    '<li><a href="evidence/benchmark/current-formal-upstream-triage-wave-03.json">'
    f'{html.escape(formal_upstream["run_id"])} normalized formal-upstream triage</a> '
    f'<code>{formal_current_sha256}</code></li>'
    '<li><a href="evidence/benchmark/current-formal-upstream-triage-agent-register.json">'
    f'{html.escape(formal_upstream["run_id"])} formal-upstream agent register</a> '
    f'<code>{formal_agents_sha256}</code></li>'
)
checkpoint_evidence = [
    ("RUN-071A completion-gate accounting", "evidence/source/raw-run-071a-completion-gate-accounting-wave-04.json"),
    ("RUN-071B downstream mapping readiness", "evidence/benchmark/raw-run-071b-downstream-mapping-readiness-wave-04.json"),
    ("RUN-071C usability and visual gap selection", "evidence/browser/raw-run-071c-usability-visual-gap-selector-wave-04.json"),
    ("RUN-072 usability materialization contract", "evidence/source/raw-run-072-usability-materialization-contract-wave-01.json"),
    ("RUN-072 deterministic usability materializer", "generators/materialize-source-bound-usability-wave-01.py"),
    ("RUN-072 300-row usability scorecard", "04-workflow-usability-scorecard.csv"),
    ("RUN-072 usability materialization evidence", "evidence/source/current-usability-task-script-materialization-wave-01.json"),
    ("RUN-072 independent materialization review", "evidence/source/raw-run-072-usability-independent-review-wave-01.json"),
    ("RUN-072 three-target route/page slice", "evidence/source/raw-run-072-current-source-route-page-ownership-slice-wave-04.json"),
    ("RUN-072 expired-auth browser blocker", "evidence/browser/root-run-072-authentication-blocked-frontline-slice-wave-04.json"),
    ("RUN-072 incident Agent A observations", "evidence/benchmark/raw-run-072-agent-a-incident-observed-behavior-wave-04.json"),
    ("RUN-072 sealed Agent B input", "evidence/benchmark/sealed-run-072-agent-b-input-wave-04.json"),
    ("RUN-072 incident Agent B neutral requirements", "evidence/benchmark/raw-run-072-agent-b-neutral-incident-requirements-wave-04.json"),
    ("RUN-072 sealed Agent C input", "evidence/benchmark/sealed-run-072-agent-c-incident-comparison-input-wave-04.json"),
    ("RUN-072 incident Agent C comparison", "evidence/benchmark/raw-run-072-agent-c-incident-current-comparison-wave-04.json"),
    ("RUN-072 sealed Agent D input", "evidence/benchmark/sealed-run-072-agent-d-incident-adjudication-input-wave-04.json"),
    ("RUN-072 incident Agent D adjudication", "evidence/benchmark/raw-run-072-agent-d-incident-adjudication-wave-04.json"),
    ("RUN-073A required-artifact contract", "evidence/source/raw-run-073a-required-artifact-contract-wave-05.json"),
    ("RUN-073B eight source-reconstructed journeys", "evidence/source/raw-run-073b-cross-module-journeys-wave-05.json"),
    ("RUN-073C architecture normalization", "evidence/source/root-run-073c-architecture-data-integration-security-wave-05.json"),
    ("RUN-073D independent journey source review", "evidence/source/raw-run-073d-independent-journey-review-wave-05.json"),
    ("RUN-073E independent architecture review", "evidence/source/raw-run-073e-independent-architecture-review-wave-05.json"),
    ("RUN-073F independent reporting materialization review", "evidence/source/raw-run-073f-independent-reporting-materialization-review-wave-05.json"),
    ("RUN-073 deterministic reporting materializer", "generators/materialize-required-reporting-wave-05.py"),
    ("Historical RUN-073 reporting materialization evidence", "evidence/source/current-required-reporting-materialization-wave-05.json"),
    ("RUN-074 static-linkage partition manifest", "evidence/source/root-run-074-static-linkage-gap-partitions-wave-06.json"),
    ("RUN-074A raw static-linkage producer review", "evidence/source/raw-run-074a-static-linkage-review-wave-06.json"),
    ("RUN-074B raw static-linkage producer review", "evidence/source/raw-run-074b-static-linkage-review-wave-06.json"),
    ("RUN-074C raw static-linkage producer review", "evidence/source/raw-run-074c-static-linkage-review-wave-06.json"),
    ("RUN-074 normalized static-linkage producer evidence", "evidence/source/current-static-linkage-review-wave-06.json"),
    ("RUN-075A independent review of RUN-074B", "evidence/source/raw-run-075a-independent-static-linkage-review-wave-06.json"),
    ("RUN-075B independent review of RUN-074C", "evidence/source/raw-run-075b-independent-static-linkage-review-wave-06.json"),
    ("RUN-075C independent review of RUN-074A", "evidence/source/raw-run-075c-independent-static-linkage-review-wave-06.json"),
    ("RUN-075 normalized cyclic independent review", "evidence/source/current-static-linkage-independent-review-wave-06.json"),
    ("RUN-076 static-linkage integration", "evidence/source/current-static-linkage-integration-wave-06.json"),
    ("RUN-076 reporting refresh generator", "generators/materialize-static-linkage-reporting-wave-06.py"),
    ("RUN-076 reporting refresh evidence", "evidence/source/current-static-linkage-reporting-materialization-wave-06.json"),
    ("Current report 07 module findings", "07-module-findings.md"),
    ("Current report 08 cross-module journeys", "08-cross-module-journeys.md"),
    ("Current report 09 UI/UX/accessibility", "09-ui-ux-accessibility-visual-consistency.md"),
    ("Current report 10 architecture/data/integration/security", "10-architecture-data-integration-security.md"),
    ("Current report 11 prioritised roadmap", "11-prioritised-roadmap.md"),
    ("Current report 12 native-build register", "12-native-build-and-do-not-copy-register.md"),
    ("Current mixed-status findings register", "findings.json"),
]
checkpoint_paths = {path for _, path in checkpoint_evidence}
for path, artifact in route_page_reporting["artifact_register"].items():
    if path not in checkpoint_paths:
        checkpoint_evidence.append((
            f"RUN-077–081 {artifact['role'].replace('_', ' ')}: {Path(path).name}",
            path,
        ))
        checkpoint_paths.add(path)
for label, path in (
    ("RUN-081 deterministic reporting materializer", "generators/materialize-route-page-reporting-wave-07.py"),
    ("RUN-081 reporting/hash receipt", "evidence/source/current-route-page-reporting-materialization-wave-07.json"),
):
    if path not in checkpoint_paths:
        checkpoint_evidence.append((label, path))
        checkpoint_paths.add(path)
for path, artifact in route_page_candidate_reporting["artifact_register"].items():
    if path not in checkpoint_paths:
        checkpoint_evidence.append((
            f"RUN-082–083 {artifact['role'].replace('_', ' ')}: {Path(path).name}",
            path,
        ))
        checkpoint_paths.add(path)
for label, path in (
    ("RUN-083 deterministic candidate-reporting materializer", "generators/materialize-route-page-candidate-reporting-wave-08.py"),
    ("RUN-083 candidate-reporting/hash receipt", "evidence/source/current-route-page-candidate-reporting-materialization-wave-08.json"),
):
    if path not in checkpoint_paths:
        checkpoint_evidence.append((label, path))
        checkpoint_paths.add(path)
for label, path in (
    ("RUN-083 verified superseded dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-083-wave-08.json"),
    ("RUN-084 designated-application signed-out preflight", "evidence/browser/current-designated-application-access-preflight-run-084-wave-09.json"),
    ("RUN-084 deterministic full page-tree graph generator", "generators/build-full-inertia-page-graph-wave-09.py"),
    ("RUN-084 full page-tree graph candidate census", "evidence/source/root-run-084-full-inertia-page-graph-wave-09.json"),
    ("RUN-084R independent full page-tree graph review", "evidence/source/raw-run-084r-independent-full-inertia-page-graph-review-wave-09.json"),
    ("RUN-084B deterministic backend role-ledger generator", "generators/build-run-084b-backend-semantic-classification-wave-09.py"),
    ("RUN-084B backend semantic candidate ledger", "evidence/source/root-run-084b-backend-semantic-classification-wave-09.json"),
    ("RUN-084BR independent backend semantic-ledger review", "evidence/source/raw-run-084br-independent-backend-semantic-classification-review-wave-09.json"),
    ("RUN-085 deterministic reporting materializer", "generators/materialize-run-085-reporting-wave-09.py"),
    ("RUN-085 reporting/hash receipt", "evidence/source/current-run-085-reporting-materialization-wave-09.json"),
    ("RUN-086 deterministic static source ownership generator", "generators/build-reviewed-static-route-page-feature-ownership-wave-10.py"),
    ("RUN-086 bounded static source ownership ledger", "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json"),
    ("RUN-086R three-part independent ownership review", "evidence/source/raw-run-086r-independent-reviewed-static-route-page-feature-ownership-wave-10.json"),
    ("RUN-087 deterministic ownership reporting materializer", "generators/materialize-run-087-static-source-feature-ownership-reporting.py"),
    ("RUN-087 ownership reporting/hash receipt", "evidence/source/current-run-087-static-source-feature-ownership-reporting.json"),
    ("RUN-088 verified superseded dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-088-wave-10.json"),
    ("RUN-089 current designated-application signed-out preflight", "evidence/browser/current-designated-application-access-preflight-run-089-wave-11.json"),
    ("RUN-090 direct-exact review-queue generator", "generators/build-direct-exact-route-page-review-queue-wave-11.py"),
    ("RUN-090 507-row zero-credit direct-exact queue", "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json"),
    ("RUN-091 closed-chain cohort generator", "generators/build-closed-route-action-page-chain-cohort-wave-11.py"),
    ("RUN-091 11-chain review cohort", "evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json"),
    ("RUN-091R three-part 9-owner / 2-shared review", "evidence/source/raw-run-091r-independent-closed-route-action-page-chain-review-wave-11.json"),
    ("RUN-092 reviewed-owner overlay generator", "generators/integrate-reviewed-static-source-ownership-overlay-wave-11.py"),
    ("RUN-092 18-row / 9-bridge ownership overlay", "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json"),
    ("RUN-092R two-part independent overlay review", "evidence/source/raw-run-092r-independent-reviewed-static-source-ownership-overlay-wave-11.json"),
    ("RUN-093 reviewed-owner reporting materializer", "generators/materialize-run-093-reviewed-owner-chain-reporting-wave-11.py"),
    ("RUN-093 reviewed-owner reporting/hash receipt", "evidence/source/current-run-093-reviewed-owner-chain-reporting-wave-11.json"),
    ("RUN-097 route/controller-only cohort generator", "generators/build-route-controller-only-candidate-cohort-wave-12.py"),
    ("RUN-097 23-row route/controller-only cohort", "evidence/source/root-run-097-route-controller-only-candidate-cohort-wave-12.json"),
    ("RUN-097R three-part 23-owner route/action review", "evidence/source/raw-run-097r-independent-route-controller-only-review-wave-12.json"),
    ("RUN-098 reviewed route/action overlay generator", "generators/integrate-reviewed-route-controller-only-ownership-overlay-wave-12.py"),
    ("RUN-098 23-route / 23-bridge ownership overlay", "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json"),
    ("RUN-098R independent overlay review", "evidence/source/raw-run-098r-independent-reviewed-route-controller-only-ownership-overlay-wave-12.json"),
    ("RUN-099 route/action reporting materializer", "generators/materialize-run-099-reviewed-route-controller-only-reporting-wave-12.py"),
    ("RUN-099 route/action reporting/hash receipt", "evidence/source/current-run-099-reviewed-route-controller-only-reporting-wave-12.json"),
    ("RUN-100 verified superseded dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-100-wave-12.json"),
    ("RUN-101 outcome-neutral cohort generator", "generators/build-outcome-neutral-route-action-cohort-wave-13.py"),
    ("RUN-101 24-row outcome-neutral route/action cohort", "evidence/source/root-run-101-outcome-neutral-route-action-cohort-wave-13.json"),
    ("RUN-101R independent 21-owner / 3-alias review materializer", "generators/materialize-independent-outcome-neutral-route-action-review-wave-13.py"),
    ("RUN-101R independent 21-owner / 3-alias review", "evidence/source/raw-run-101r-independent-outcome-neutral-route-action-review-wave-13.json"),
    ("RUN-102 owner-only overlay generator", "generators/integrate-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.py"),
    ("RUN-102 21-route / 21-bridge overlay with 3 aliases", "evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json"),
    ("RUN-102R independent overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-route-action-ownership-overlay-review-wave-13.py"),
    ("RUN-102R independent final-byte overlay review", "evidence/source/raw-run-102r-independent-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json"),
    ("RUN-103 outcome-neutral reporting materializer", "generators/materialize-run-103-reviewed-outcome-neutral-route-action-reporting-wave-13.py"),
    ("RUN-103 outcome-neutral reporting/hash receipt", "evidence/source/current-run-103-reviewed-outcome-neutral-route-action-reporting-wave-13.json"),
    ("RUN-104 verified superseded dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-104-wave-13.json"),
    ("RUN-105 page render-owner cohort generator", "generators/build-outcome-neutral-page-render-owner-cohort-wave-14.py"),
    ("RUN-105 24-row outcome-neutral page cohort", "evidence/source/root-run-105-outcome-neutral-page-render-owner-cohort-wave-14.json"),
    ("RUN-105R independent 20-owner / 3-shared / 1-gap review materializer", "generators/materialize-independent-outcome-neutral-page-render-owner-review-wave-14.py"),
    ("RUN-105R independent page semantic review", "evidence/source/raw-run-105r-independent-outcome-neutral-page-render-owner-review-wave-14.json"),
    ("RUN-106 page owner-only overlay generator", "generators/integrate-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.py"),
    ("RUN-106 20-page owner overlay with four preserved non-owners", "evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json"),
    ("RUN-106R independent overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-page-render-owner-ownership-overlay-review-wave-14.py"),
    ("RUN-106R independent final-byte and boundary review", "evidence/source/raw-run-106r-independent-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json"),
    ("RUN-107 page-owner reporting materializer", "generators/materialize-run-107-reviewed-page-render-owner-reporting-wave-14.py"),
    ("RUN-107 page-owner reporting/hash receipt", "evidence/source/current-run-107-reviewed-page-render-owner-reporting-wave-14.json"),
    ("RUN-108 verified superseded dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-108-wave-14.json"),
    ("RUN-109 page render-owner tail cohort generator", "generators/build-outcome-neutral-page-render-owner-tail-cohort-wave-15.py"),
    ("RUN-109 six-page outcome-neutral tail cohort", "evidence/source/root-run-109-outcome-neutral-page-render-owner-tail-cohort-wave-15.json"),
    ("RUN-109R independent 2-owner / 4-shared review materializer", "generators/materialize-independent-outcome-neutral-page-render-owner-tail-review-wave-15.py"),
    ("RUN-109R independent page-tail semantic review", "evidence/source/raw-run-109r-independent-outcome-neutral-page-render-owner-tail-review-wave-15.json"),
    ("RUN-110 page-tail owner-only overlay generator", "generators/integrate-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.py"),
    ("RUN-110 two-page owner overlay with four shared non-owners", "evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json"),
    ("RUN-110R independent overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-review-wave-15.py"),
    ("RUN-110R independent final-byte queue and boundary review", "evidence/source/raw-run-110r-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json"),
    ("RUN-111 page-tail reporting materializer", "generators/materialize-run-111-reviewed-page-render-owner-tail-reporting-wave-15.py"),
    ("RUN-111 page-tail reporting/hash receipt", "evidence/source/current-run-111-reviewed-page-render-owner-tail-reporting-wave-15.json"),
    ("RUN-112 verified superseded dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-112-wave-15.json"),
    ("RUN-113 name-only route/action cohort generator", "generators/build-outcome-neutral-name-only-route-action-cohort-wave-16.py"),
    ("RUN-113 24-row name-only route/action cohort", "evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json"),
    ("RUN-113R independent 23-owner / 1-alias review materializer", "generators/materialize-independent-outcome-neutral-name-only-route-action-review-wave-16.py"),
    ("RUN-113R independent name-only route/action review", "evidence/source/raw-run-113r-independent-outcome-neutral-name-only-route-action-review-wave-16.json"),
    ("RUN-114 owner-only route/action overlay generator", "generators/integrate-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.py"),
    ("RUN-114 23-route owner overlay with one alias non-owner", "evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json"),
    ("RUN-114R independent overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-review-wave-16.py"),
    ("RUN-114R independent final-byte identity queue and boundary review", "evidence/source/raw-run-114r-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json"),
    ("RUN-115 name-only route/action reporting materializer", "generators/materialize-run-115-reviewed-name-only-route-action-reporting-wave-16.py"),
    ("RUN-115 name-only route/action reporting/hash receipt", "evidence/source/current-run-115-reviewed-name-only-route-action-reporting-wave-16.json"),
    ("RUN-116 verified superseded dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-116-wave-16.json"),
    ("RUN-117 respite handover page-gap cohort generator", "generators/build-outcome-neutral-respite-handover-page-gap-cohort-wave-17.py"),
    ("RUN-117 four-page outcome-neutral cohort", "evidence/source/root-run-117-outcome-neutral-respite-handover-page-gap-cohort-wave-17.json"),
    ("RUN-117R page semantic-review materializer", "generators/materialize-independent-outcome-neutral-respite-handover-page-gap-review-wave-17.py"),
    ("RUN-117R four-owner page semantic review", "evidence/source/raw-run-117r-independent-outcome-neutral-respite-handover-page-gap-review-wave-17.json"),
    ("RUN-118 page owner-only overlay generator", "generators/integrate-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.py"),
    ("RUN-118 four-page owner-only overlay", "evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json"),
    ("RUN-118R independent overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-review-wave-17.py"),
    ("RUN-118R final-byte identity and boundary review", "evidence/source/raw-run-118r-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json"),
    ("RUN-119 respite handover page-gap reporting materializer", "generators/materialize-run-119-reviewed-respite-handover-page-gap-reporting-wave-17.py"),
    ("RUN-119 respite handover page-gap reporting/hash receipt", "evidence/source/current-run-119-reviewed-respite-handover-page-gap-reporting-wave-17.json"),
    ("RUN-120 verified superseded dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-120-wave-17.json"),
    ("RUN-121 Finance chart route/action cohort generator", "generators/build-outcome-neutral-finance-chart-route-action-cohort-wave-18.py"),
    ("RUN-121 22-route Finance outcome-neutral cohort", "evidence/source/root-run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18.json"),
    ("RUN-121R Finance semantic-review materializer", "generators/materialize-independent-outcome-neutral-finance-chart-route-action-review-wave-18.py"),
    ("RUN-121R 7-owner 7-shared 1-alias 7-gap review", "evidence/source/raw-run-121r-independent-outcome-neutral-finance-chart-route-action-review-wave-18.json"),
    ("RUN-122 Finance route owner-only overlay generator", "generators/integrate-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.py"),
    ("RUN-122 seven-route owner overlay with 15 non-owners", "evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json"),
    ("RUN-122R independent Finance overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-review-wave-18.py"),
    ("RUN-122R final-byte identity accounting and boundary review", "evidence/source/raw-run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json"),
    ("RUN-123 Finance route/action reporting materializer", "generators/materialize-run-123-reviewed-finance-chart-route-action-reporting-wave-18.py"),
    ("RUN-123 Finance route/action reporting/hash receipt", "evidence/source/current-run-123-reviewed-finance-chart-route-action-reporting-wave-18.json"),
    ("RUN-124 verified superseded dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-124-wave-18.json"),
    ("RUN-125 Finance page-gap cohort generator", "generators/build-outcome-neutral-finance-page-gap-cohort-wave-19.py"),
    ("RUN-125 four-page outcome-neutral cohort", "evidence/source/root-run-125-outcome-neutral-finance-page-gap-cohort-wave-19.json"),
    ("RUN-125R Finance page semantic-review materializer", "generators/materialize-independent-outcome-neutral-finance-page-gap-review-wave-19.py"),
    ("RUN-125R four-owner Finance page review", "evidence/source/raw-run-125r-independent-outcome-neutral-finance-page-gap-review-wave-19.json"),
    ("RUN-126 Finance page owner-only overlay generator", "generators/integrate-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.py"),
    ("RUN-126 four-page owner overlay", "evidence/source/current-run-126-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json"),
    ("RUN-126R independent Finance page overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-review-wave-19.py"),
    ("RUN-126R final-byte identity accounting and boundary review", "evidence/source/raw-run-126r-independent-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json"),
    ("RUN-127 Finance page-gap reporting materializer", "generators/materialize-run-127-reviewed-finance-page-gap-reporting-wave-19.py"),
    ("RUN-127 Finance page-gap reporting/hash receipt", "evidence/source/current-run-127-reviewed-finance-page-gap-reporting-wave-19.json"),
    ("RUN-128 verified superseded dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-128-wave-19.json"),
    ("RUN-129 FX revaluation route/action cohort generator", "generators/build-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.py"),
    ("RUN-129 two-action FX cohort", "evidence/source/root-run-129-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.json"),
    ("RUN-129R FX semantic-review materializer", "generators/materialize-independent-outcome-neutral-finance-fx-revaluation-route-action-review-wave-20.py"),
    ("RUN-129R two-owner FX action review", "evidence/source/raw-run-129r-independent-outcome-neutral-finance-fx-revaluation-route-action-review-wave-20.json"),
    ("RUN-130 FX route/action overlay generator", "generators/integrate-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.py"),
    ("RUN-130 two-route two-bridge FX overlay", "evidence/source/current-run-130-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json"),
    ("RUN-130R independent FX overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-review-wave-20.py"),
    ("RUN-130R final-byte identity accounting and boundary review", "evidence/source/raw-run-130r-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json"),
    ("RUN-131 FX route/action reporting materializer", "generators/materialize-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.py"),
    ("RUN-131 FX route/action reporting/hash receipt", "evidence/source/current-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.json"),
    ("RUN-132 verified superseded dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-132-wave-20.json"),
    ("RUN-133 accounting-integration route/action cohort generator", "generators/build-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.py"),
    ("RUN-133 six-action accounting-integration cohort", "evidence/source/root-run-133-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.json"),
    ("RUN-133R accounting-integration semantic-review materializer", "generators/materialize-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21.py"),
    ("RUN-133R six-owner accounting-integration action review", "evidence/source/raw-run-133r-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21.json"),
    ("RUN-134 accounting-integration route/action overlay generator", "generators/integrate-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.py"),
    ("RUN-134 six-route six-bridge accounting-integration overlay", "evidence/source/current-run-134-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json"),
    ("RUN-134R independent accounting-integration overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-review-wave-21.py"),
    ("RUN-134R final-byte identity accounting and boundary review", "evidence/source/raw-run-134r-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json"),
    ("RUN-135 accounting-integration reporting materializer", "generators/materialize-run-135-reviewed-finance-accounting-integration-route-action-reporting-wave-21.py"),
    ("RUN-135 accounting-integration reporting/hash receipt", "evidence/source/current-run-135-reviewed-finance-accounting-integration-route-action-reporting-wave-21.json"),
    ("RUN-136 verified superseded dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-136-wave-21.json"),
    ("RUN-137 invoice-index route/action cohort generator", "generators/build-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.py"),
    ("RUN-137 one-action invoice-index cohort", "evidence/source/root-run-137-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.json"),
    ("RUN-137R invoice-index semantic-review materializer", "generators/materialize-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.py"),
    ("RUN-137R one-owner invoice-index action review", "evidence/source/raw-run-137r-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.json"),
    ("RUN-138 invoice-index route/action overlay generator", "generators/integrate-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.py"),
    ("RUN-138 one-route one-bridge invoice-index overlay", "evidence/source/current-run-138-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json"),
    ("RUN-138R independent invoice-index overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-review-wave-22.py"),
    ("RUN-138R final-byte identity accounting and boundary review", "evidence/source/raw-run-138r-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json"),
    ("RUN-139 invoice-index reporting materializer", "generators/materialize-run-139-reviewed-finance-invoice-index-route-action-reporting-wave-22.py"),
    ("RUN-139 invoice-index reporting/hash receipt", "evidence/source/current-run-139-reviewed-finance-invoice-index-route-action-reporting-wave-22.json"),
    ("RUN-140 verified superseded RUN-139 dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-140-wave-22.json"),
    ("RUN-141 Site-portfolio API route/action cohort generator", "generators/build-outcome-neutral-finance-site-portfolio-overview-route-action-cohort-wave-23.py"),
    ("RUN-141 one-action Site-portfolio API cohort", "evidence/source/root-run-141-outcome-neutral-finance-site-portfolio-overview-route-action-cohort-wave-23.json"),
    ("RUN-141R Site-portfolio API semantic-review materializer", "generators/materialize-independent-outcome-neutral-finance-site-portfolio-overview-route-action-review-wave-23.py"),
    ("RUN-141R one-owner Site-portfolio API action review", "evidence/source/raw-run-141r-independent-outcome-neutral-finance-site-portfolio-overview-route-action-review-wave-23.json"),
    ("RUN-142 Site-portfolio API route/action overlay generator", "generators/integrate-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-wave-23.py"),
    ("RUN-142 one-route one-bridge Site-portfolio API overlay", "evidence/source/current-run-142-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-wave-23.json"),
    ("RUN-142R independent Site-portfolio API overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-review-wave-23.py"),
    ("RUN-142R corrected final-byte identity accounting and boundary review", "evidence/source/raw-run-142r-independent-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-wave-23.json"),
    ("RUN-143 Site-portfolio API reporting materializer", "generators/materialize-run-143-reviewed-finance-site-portfolio-overview-route-action-reporting-wave-23.py"),
    ("RUN-143 Site-portfolio API reporting/hash receipt", "evidence/source/current-run-143-reviewed-finance-site-portfolio-overview-route-action-reporting-wave-23.json"),
    ("RUN-144 verified RUN-143 dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-144-wave-23.json"),
    ("RUN-144 dashboard-verification receipt materializer", "generators/materialize-run-144-audit-dashboard-verification-wave-23.py"),
    ("RUN-145 Agent A observation materializer", "generators/materialize-run-145-agent-a-finance-invoice-fx-observed-behavior-wave-24.py"),
    ("RUN-145 Agent A observations", "evidence/benchmark/raw-run-145-agent-a-finance-invoice-fx-observed-behavior-wave-24.json"),
    ("RUN-145 sealed identity-stripped Agent B input", "evidence/benchmark/sealed-run-145-agent-b-finance-invoice-fx-input-wave-24.json"),
    ("RUN-145 Agent B neutral-requirements materializer", "generators/materialize-run-145-agent-b-finance-invoice-fx-neutral-requirements-wave-24.py"),
    ("RUN-145 Agent B neutral requirements", "evidence/benchmark/raw-run-145-agent-b-finance-invoice-fx-neutral-requirements-wave-24.json"),
    ("RUN-145 sealed Agent C input", "evidence/benchmark/sealed-run-145-agent-c-finance-invoice-fx-current-comparison-input-wave-24.json"),
    ("RUN-145 Agent C FX comparison materializer", "generators/materialize-run-145-agent-c-fx-current-comparison-wave-24.py"),
    ("RUN-145 Agent C FX current comparison", "evidence/benchmark/raw-run-145-agent-c-fx-current-comparison-wave-24.json"),
    ("RUN-145 Agent C invoice comparison materializer", "generators/materialize-run-145-agent-c-invoice-current-comparison-wave-24.py"),
    ("RUN-145 Agent C invoice current comparison", "evidence/benchmark/raw-run-145-agent-c-invoice-current-comparison-wave-24.json"),
    ("RUN-145 sealed Agent D input materializer", "generators/materialize-run-145-agent-d-finance-invoice-fx-adjudication-input-wave-24.py"),
    ("RUN-145 sealed Agent D input", "evidence/benchmark/sealed-run-145-agent-d-finance-invoice-fx-adjudication-input-wave-24.json"),
    ("RUN-145 Agent D adjudication materializer", "generators/materialize-run-145-agent-d-finance-invoice-fx-adjudication-wave-24.py"),
    ("RUN-145 Agent D adjudication", "evidence/benchmark/raw-run-145-agent-d-finance-invoice-fx-adjudication-wave-24.json"),
    ("RUN-145 Pass-8 review materializer", "generators/materialize-run-145-p8-adversarial-integration-review-wave-24.py"),
    ("RUN-145 Pass-8 adversarial review", "evidence/benchmark/raw-run-145-p8-adversarial-integration-review-wave-24.json"),
    ("RUN-145 corrected integration-plan materializer", "generators/materialize-run-145-corrected-integration-plan-wave-24.py"),
    ("RUN-145 sealed corrected integration plan", "evidence/benchmark/sealed-run-145-corrected-matrix-register-integration-input-wave-24.json"),
    ("RUN-145 bounded matrix/register apply generator", "generators/apply-run-145-corrected-finance-benchmark-mapping-wave-24.py"),
    ("RUN-145 two-target benchmark-mapping receipt", "evidence/benchmark/current-run-145-finance-invoice-fx-benchmark-mapping-wave-24.json"),
    ("RUN-146 finance benchmark reporting materializer", "generators/materialize-run-146-finance-benchmark-reporting-wave-24.py"),
    ("RUN-146 finance benchmark reporting receipt", "evidence/source/current-run-146-finance-benchmark-reporting-wave-24.json"),
    ("RUN-146 audit-dashboard benchmark refresh materializer", "generators/materialize-run-146-audit-dashboard-benchmark-refresh-wave-24.py"),
    ("RUN-147 verified superseded RUN-146 dashboard receipt materializer", "generators/materialize-run-147-audit-dashboard-verification-wave-24.py"),
    ("RUN-147 verified superseded RUN-146 dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-147-wave-24.json"),
    ("RUN-148 Fleet daily-check cohort generator", "generators/build-outcome-neutral-fleet-daily-vehicle-check-store-route-action-cohort-wave-25.py"),
    ("RUN-148 Fleet daily-check cohort", "evidence/source/root-run-148-outcome-neutral-fleet-daily-vehicle-check-store-route-action-cohort-wave-25.json"),
    ("RUN-148R Fleet daily-check candidate-review materializer", "generators/materialize-independent-outcome-neutral-fleet-daily-vehicle-check-store-route-action-review-wave-25.py"),
    ("RUN-148R Fleet daily-check candidate review", "evidence/source/raw-run-148r-independent-outcome-neutral-fleet-daily-vehicle-check-store-route-action-review-wave-25.json"),
    ("RUN-149 Fleet daily-check overlay generator", "generators/integrate-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.py"),
    ("RUN-149 Fleet daily-check one-route one-bridge overlay", "evidence/source/current-run-149-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.json"),
    ("RUN-149R Fleet daily-check overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25.py"),
    ("RUN-149R Fleet daily-check overlay review", "evidence/source/current-run-149r-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25.json"),
    ("RUN-150 Fleet daily-check reporting materializer", "generators/materialize-run-150-reviewed-fleet-daily-vehicle-check-store-route-action-reporting-wave-25.py"),
    ("RUN-150 Fleet daily-check reporting receipt", "evidence/source/current-run-150-reviewed-fleet-daily-vehicle-check-store-route-action-reporting-wave-25.json"),
    ("RUN-151 superseded RUN-150 dashboard verification materializer", "generators/materialize-run-151-audit-dashboard-verification-wave-25.py"),
    ("RUN-151 superseded RUN-150 dashboard verification", "evidence/browser/current-audit-dashboard-verification-run-151-wave-25.json"),
    ("RUN-152 Fleet vehicle-register cohort generator", "generators/build-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26.py"),
    ("RUN-152 Fleet vehicle-register cohort", "evidence/source/root-run-152-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26.json"),
    ("RUN-152R Fleet vehicle-register review materializer", "generators/materialize-independent-outcome-neutral-fleet-vehicle-register-index-route-action-review-wave-26.py"),
    ("RUN-152R Fleet vehicle-register review", "evidence/source/raw-run-152r-independent-outcome-neutral-fleet-vehicle-register-index-route-action-review-wave-26.json"),
    ("RUN-153 Fleet vehicle-register overlay generator", "generators/integrate-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.py"),
    ("RUN-153 Fleet vehicle-register one-route one-bridge overlay", "evidence/source/current-run-153-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.json"),
    ("RUN-153R Fleet vehicle-register overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.py"),
    ("RUN-153R Fleet vehicle-register overlay review", "evidence/source/current-run-153r-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.json"),
    ("RUN-154 Fleet vehicle-register reporting materializer", "generators/materialize-run-154-reviewed-fleet-vehicle-register-index-route-action-reporting-wave-26.py"),
    ("RUN-154 Fleet vehicle-register reporting receipt", "evidence/source/current-run-154-reviewed-fleet-vehicle-register-index-route-action-reporting-wave-26.json"),
    ("RUN-155 audit-dashboard verification materializer", "generators/materialize-run-155-audit-dashboard-verification-wave-26.py"),
    ("RUN-155 exact RUN-154 audit-dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-155-wave-26.json"),
    ("RUN-156 medication-governance source-main receipt materializer", "generators/materialize-run-156-medication-governance-source-main-receipt-wave-27.py"),
    ("RUN-156 medication-governance two-checkpoint source-main receipt", "evidence/source/current-run-156-medication-governance-source-main-receipt-wave-27.json"),
    ("RUN-156R independent source-main receipt review materializer", "generators/materialize-independent-medication-governance-source-main-receipt-review-wave-27.py"),
    ("RUN-156R independent source-main receipt review", "evidence/source/current-run-156r-independent-medication-governance-source-main-receipt-review-wave-27.json"),
    ("RUN-157 medication-governance source-main receipt reporting materializer", "generators/materialize-run-157-reviewed-medication-governance-source-main-receipt-reporting-wave-27.py"),
    ("RUN-157 medication-governance source-main receipt reporting receipt", "evidence/source/current-run-157-reviewed-medication-governance-source-main-receipt-reporting-wave-27.json"),
    ("RUN-158 exact RUN-157 audit-dashboard verification materializer", "generators/materialize-run-158-audit-dashboard-verification-wave-27.py"),
    ("RUN-158 exact RUN-157 audit-dashboard verification", "evidence/browser/current-audit-dashboard-verification-run-158-wave-27.json"),
    ("RUN-159 MED-RBAC already-fixed adjudication materializer", "generators/materialize-run-159-med-rbac-already-fixed-adjudication-wave-28.py"),
    ("RUN-159 MED-RBAC already-fixed adjudication", "evidence/runtime/current-run-159-med-rbac-already-fixed-adjudication-wave-28.json"),
    ("RUN-159R independent exact-adjudication review materializer", "generators/materialize-independent-run-159-med-rbac-adjudication-review-wave-28.py"),
    ("RUN-159R independent exact-adjudication review", "evidence/runtime/current-run-159r-independent-med-rbac-adjudication-review-wave-28.json"),
    ("RUN-160 MED-RBAC already-fixed reporting materializer", "generators/materialize-run-160-med-rbac-already-fixed-reporting-wave-28.py"),
    ("RUN-160 MED-RBAC already-fixed reporting receipt", "evidence/source/current-run-160-med-rbac-already-fixed-reporting-wave-28.json"),
    ("RUN-161 exact RUN-160 audit-dashboard verification materializer", "generators/materialize-run-161-audit-dashboard-verification-wave-28.py"),
    ("RUN-161 exact RUN-160 audit-dashboard verification", "evidence/browser/current-audit-dashboard-verification-run-161-wave-28.json"),
    ("RUN-162 MED-CD-SCOPE remediation materializer", "generators/materialize-run-162-med-cd-scope-remediation-wave-29.py"),
    ("RUN-162 MED-CD-SCOPE remediation receipt", "evidence/runtime/current-run-162-med-cd-scope-remediation-wave-29.json"),
    ("RUN-162R independent remediation-review materializer", "generators/materialize-independent-run-162-med-cd-scope-remediation-review-wave-29.py"),
    ("RUN-162R independent remediation review", "evidence/runtime/current-run-162r-independent-med-cd-scope-remediation-review-wave-29.json"),
    ("RUN-163 MED-CD-SCOPE remediation-reporting materializer", "generators/materialize-run-163-med-cd-scope-remediation-reporting-wave-29.py"),
    ("RUN-163 MED-CD-SCOPE remediation-reporting receipt", "evidence/source/current-run-163-med-cd-scope-remediation-reporting-wave-29.json"),
    ("RUN-164 exact RUN-163 audit-dashboard verification materializer", "generators/materialize-run-164-audit-dashboard-verification-wave-29.py"),
    ("RUN-164 exact RUN-163 audit-dashboard verification", "evidence/browser/current-audit-dashboard-verification-run-164-wave-29.json"),
    ("RUN-165 MED-CD-ATOMICITY current-source review materializer", "generators/materialize-run-165-med-cd-atomicity-current-source-review-wave-30.py"),
    ("RUN-165 MED-CD-ATOMICITY current-source review", "evidence/source/current-run-165-med-cd-atomicity-current-source-review-wave-30.json"),
    ("RUN-166 MED-CD-ATOMICITY already-fixed adjudication materializer", "generators/materialize-run-166-med-cd-atomicity-already-fixed-adjudication-wave-30.py"),
    ("RUN-166 MED-CD-ATOMICITY already-fixed adjudication", "evidence/runtime/current-run-166-med-cd-atomicity-already-fixed-adjudication-wave-30.json"),
    ("RUN-166 MED-CD-ATOMICITY immutable concurrency harness", "evidence/runtime/harnesses/run-166-controlled-drug-atomicity-concurrency-test.php.txt"),
    ("RUN-166R independent exact-adjudication review materializer", "generators/materialize-independent-run-166-med-cd-atomicity-adjudication-review-wave-30.py"),
    ("RUN-166R independent exact-adjudication review", "evidence/runtime/current-run-166r-independent-med-cd-atomicity-adjudication-review-wave-30.json"),
    ("RUN-167 MED-CD-ATOMICITY already-fixed reporting materializer", "generators/materialize-run-167-med-cd-atomicity-reporting-wave-30.py"),
    ("RUN-167 MED-CD-ATOMICITY already-fixed reporting receipt", "evidence/source/current-run-167-med-cd-atomicity-reporting-wave-30.json"),
    ("RUN-168 exact RUN-167 audit-dashboard verification materializer", "generators/materialize-run-168-audit-dashboard-verification-wave-30.py"),
    ("RUN-168 exact RUN-167 audit-dashboard verification", "evidence/browser/current-audit-dashboard-verification-run-168-wave-30.json"),
    ("RUN-169 Fleet vehicle alerts-config cohort generator", "generators/build-outcome-neutral-fleet-vehicle-alerts-config-route-action-cohort-wave-31.py"),
    ("RUN-169 Fleet vehicle alerts-config cohort", "evidence/source/root-run-169-outcome-neutral-fleet-vehicle-alerts-config-route-action-cohort-wave-31.json"),
    ("RUN-169R Fleet vehicle alerts-config review materializer", "generators/materialize-independent-outcome-neutral-fleet-vehicle-alerts-config-route-action-review-wave-31.py"),
    ("RUN-169R Fleet vehicle alerts-config review", "evidence/source/raw-run-169r-independent-outcome-neutral-fleet-vehicle-alerts-config-route-action-review-wave-31.json"),
    ("RUN-170 Fleet vehicle alerts-config overlay generator", "generators/integrate-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.py"),
    ("RUN-170 Fleet vehicle alerts-config one-route one-bridge overlay", "evidence/source/current-run-170-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.json"),
    ("RUN-170R Fleet vehicle alerts-config overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-review-wave-31.py"),
    ("RUN-170R Fleet vehicle alerts-config overlay review", "evidence/source/current-run-170r-independent-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-review-wave-31.json"),
    ("RUN-171 Fleet vehicle alerts-config reporting materializer", "generators/materialize-run-171-reviewed-fleet-vehicle-alerts-config-route-action-reporting-wave-31.py"),
    ("RUN-171 Fleet vehicle alerts-config reporting receipt", "evidence/source/current-run-171-reviewed-fleet-vehicle-alerts-config-route-action-reporting-wave-31.json"),
    ("RUN-172 exact RUN-171 audit-dashboard verification materializer", "generators/materialize-run-172-audit-dashboard-verification-wave-31.py"),
    ("RUN-172 exact RUN-171 audit-dashboard verification", "evidence/browser/current-audit-dashboard-verification-run-172-wave-31.json"),
    ("RUN-173 SAFE alert dedup identity remediation materializer", "generators/materialize-run-173-safe-alert-dedup-identity-remediation-wave-32.py"),
    ("RUN-173 SAFE alert dedup identity remediation receipt", "evidence/runtime/current-run-173-safe-alert-dedup-identity-remediation-wave-32.json"),
    ("RUN-173R independent SAFE remediation-review materializer", "generators/materialize-independent-run-173-safe-alert-dedup-identity-remediation-review-wave-32.py"),
    ("RUN-173R independent SAFE remediation review", "evidence/runtime/current-run-173r-independent-safe-alert-dedup-identity-remediation-review-wave-32.json"),
    ("RUN-174 SAFE alert dedup remediation-reporting materializer", "generators/materialize-run-174-safe-alert-dedup-identity-remediation-reporting-wave-32.py"),
    ("RUN-174 SAFE alert dedup remediation-reporting receipt", "evidence/source/current-run-174-safe-alert-dedup-identity-remediation-reporting-wave-32.json"),
    ("RUN-175 exact RUN-174 audit-dashboard verification materializer", "generators/materialize-run-175-audit-dashboard-verification-wave-32.py"),
    ("RUN-175 exact RUN-174 audit-dashboard verification", "evidence/browser/current-audit-dashboard-verification-run-175-wave-32.json"),
    ("RUN-176 Fleet trip-index Site-privacy remediation materializer", "generators/materialize-run-176-fleet-trip-index-site-privacy-remediation-wave-33.py"),
    ("RUN-176 Fleet trip-index Site-privacy remediation receipt", "evidence/runtime/current-run-176-fleet-trip-index-site-privacy-remediation-wave-33.json"),
    ("RUN-176R independent Fleet remediation-review materializer", "generators/materialize-independent-run-176-fleet-trip-index-site-privacy-remediation-review-wave-33.py"),
    ("RUN-176R independent Fleet remediation review", "evidence/runtime/current-run-176r-independent-fleet-trip-index-site-privacy-remediation-review-wave-33.json"),
    ("RUN-177 Fleet trip-index Site-privacy remediation-reporting materializer", "generators/materialize-run-177-fleet-trip-index-site-privacy-remediation-reporting-wave-33.py"),
    ("RUN-177 Fleet trip-index Site-privacy remediation-reporting receipt", "evidence/source/current-run-177-fleet-trip-index-site-privacy-remediation-reporting-wave-33.json"),
    ("RUN-178 exact RUN-177 audit-dashboard verification materializer", "generators/materialize-run-178-audit-dashboard-verification-wave-33.py"),
    ("RUN-178 exact RUN-177 audit-dashboard verification", "evidence/browser/current-audit-dashboard-verification-run-178-wave-33.json"),
    ("RUN-179 Fleet trip-index route/action cohort generator", "generators/build-outcome-neutral-fleet-trip-index-route-action-cohort-wave-34.py"),
    ("RUN-179 Fleet trip-index route/action cohort", "evidence/source/root-run-179-outcome-neutral-fleet-trip-index-route-action-cohort-wave-34.json"),
    ("RUN-179R Fleet trip-index route/action review materializer", "generators/materialize-independent-outcome-neutral-fleet-trip-index-route-action-review-wave-34.py"),
    ("RUN-179R Fleet trip-index route/action review", "evidence/source/raw-run-179r-independent-outcome-neutral-fleet-trip-index-route-action-review-wave-34.json"),
    ("RUN-180 Fleet trip-index route/action overlay generator", "generators/integrate-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-wave-34.py"),
    ("RUN-180 Fleet trip-index one-route one-bridge overlay", "evidence/source/current-run-180-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-wave-34.json"),
    ("RUN-180R Fleet trip-index overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-review-wave-34.py"),
    ("RUN-180R Fleet trip-index overlay review", "evidence/source/current-run-180r-independent-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-review-wave-34.json"),
    ("RUN-181 Fleet trip-index route/action reporting materializer", "generators/materialize-run-181-reviewed-fleet-trip-index-route-action-reporting-wave-34.py"),
    ("RUN-181 Fleet trip-index route/action reporting receipt", "evidence/source/current-run-181-reviewed-fleet-trip-index-route-action-reporting-wave-34.json"),
    ("RUN-182 exact RUN-181 audit-dashboard verification materializer", "generators/materialize-run-182-audit-dashboard-verification-wave-34.py"),
    ("RUN-182 exact RUN-181 audit-dashboard verification", "evidence/browser/current-audit-dashboard-verification-run-182-wave-34.json"),
    ("RUN-183 Fleet trip-playback Site-privacy remediation materializer", "generators/materialize-run-183-fleet-trip-playback-site-privacy-remediation-wave-35.py"),
    ("RUN-183 Fleet trip-playback Site-privacy remediation receipt", "evidence/runtime/current-run-183-fleet-trip-playback-site-privacy-remediation-wave-35.json"),
    ("RUN-183R independent Fleet trip-playback remediation-review materializer", "generators/materialize-independent-run-183-fleet-trip-playback-site-privacy-remediation-review-wave-35.py"),
    ("RUN-183R independent Fleet trip-playback remediation review", "evidence/runtime/current-run-183r-independent-fleet-trip-playback-site-privacy-remediation-review-wave-35.json"),
    ("RUN-184 Fleet trip-playback Site-privacy remediation-reporting materializer", "generators/materialize-run-184-fleet-trip-playback-site-privacy-remediation-reporting-wave-35.py"),
    ("RUN-184 Fleet trip-playback Site-privacy remediation-reporting receipt", "evidence/source/current-run-184-fleet-trip-playback-site-privacy-remediation-reporting-wave-35.json"),
    ("RUN-185 exact RUN-184 audit-dashboard verification materializer", "generators/materialize-run-185-audit-dashboard-verification-wave-35.py"),
    ("RUN-185 exact RUN-184 audit-dashboard verification", "evidence/browser/current-audit-dashboard-verification-run-185-wave-35.json"),
    ("RUN-186 Monitoring metric replay remediation materializer", "generators/materialize-run-186-monitoring-metric-replay-dedupe-remediation-wave-36.py"),
    ("RUN-186 Monitoring metric replay remediation receipt", "evidence/runtime/current-run-186-monitoring-metric-replay-dedupe-remediation-wave-36.json"),
    ("RUN-186R independent Monitoring metric replay remediation-review materializer", "generators/materialize-independent-run-186-monitoring-metric-replay-dedupe-remediation-review-wave-36.py"),
    ("RUN-186R independent Monitoring metric replay remediation review", "evidence/runtime/current-run-186r-independent-monitoring-metric-replay-dedupe-remediation-review-wave-36.json"),
    ("RUN-187 Monitoring metric replay remediation-reporting materializer", "generators/materialize-run-187-monitoring-metric-replay-dedupe-remediation-reporting-wave-36.py"),
    ("RUN-187 Monitoring metric replay remediation-reporting receipt", "evidence/source/current-run-187-monitoring-metric-replay-dedupe-remediation-reporting-wave-36.json"),
    ("RUN-188 exact RUN-187 audit-dashboard verification materializer", "generators/materialize-run-188-audit-dashboard-verification-wave-36.py"),
    ("RUN-188 exact RUN-187 audit-dashboard verification", "evidence/browser/current-audit-dashboard-verification-run-188-wave-36.json"),
    ("RUN-189 Fleet playback route/action cohort generator", "generators/build-outcome-neutral-fleet-trip-playback-route-action-cohort-wave-37.py"),
    ("RUN-189 Fleet playback route/action cohort", "evidence/source/root-run-189-outcome-neutral-fleet-trip-playback-route-action-cohort-wave-37.json"),
    ("RUN-189R Fleet playback route/action review materializer", "generators/materialize-independent-outcome-neutral-fleet-trip-playback-route-action-review-wave-37.py"),
    ("RUN-189R Fleet playback route/action review", "evidence/source/raw-run-189r-independent-outcome-neutral-fleet-trip-playback-route-action-review-wave-37.json"),
    ("RUN-190 Fleet playback route/action overlay generator", "generators/integrate-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-wave-37.py"),
    ("RUN-190 Fleet playback route/action ownership overlay", "evidence/source/current-run-190-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-wave-37.json"),
    ("RUN-190R Fleet playback route/action overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-review-wave-37.py"),
    ("RUN-190R Fleet playback route/action overlay review", "evidence/source/current-run-190r-independent-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-review-wave-37.json"),
    ("RUN-191 Fleet playback route/action reporting materializer", "generators/materialize-run-191-reviewed-fleet-trip-playback-route-action-reporting-wave-37.py"),
    ("RUN-191 Fleet playback route/action reporting receipt", "evidence/source/current-run-191-reviewed-fleet-trip-playback-route-action-reporting-wave-37.json"),
    ("RUN-192 exact RUN-191 audit-dashboard verification materializer", "generators/materialize-run-192-audit-dashboard-verification-wave-37.py"),
    ("RUN-192 exact RUN-191 audit-dashboard verification", "evidence/browser/current-audit-dashboard-verification-run-192-wave-37.json"),
    ("RUN-193 Fleet Fuel index Site-privacy remediation materializer", "generators/materialize-run-193-fleet-fuel-index-site-privacy-remediation-wave-38.py"),
    ("RUN-193 Fleet Fuel index Site-privacy remediation receipt", "evidence/runtime/current-run-193-fleet-fuel-index-site-privacy-remediation-wave-38.json"),
    ("RUN-193R independent Fleet Fuel remediation-review materializer", "generators/materialize-independent-run-193-fleet-fuel-index-site-privacy-remediation-review-wave-38.py"),
    ("RUN-193R independent Fleet Fuel remediation review", "evidence/runtime/current-run-193r-independent-fleet-fuel-index-site-privacy-remediation-review-wave-38.json"),
    ("RUN-194 Fleet Fuel remediation-reporting materializer", "generators/materialize-run-194-fleet-fuel-index-site-privacy-remediation-reporting-wave-38.py"),
    ("RUN-194 Fleet Fuel remediation-reporting receipt", "evidence/source/current-run-194-fleet-fuel-index-site-privacy-remediation-reporting-wave-38.json"),
    ("RUN-195 exact RUN-194 audit-dashboard verification materializer", "generators/materialize-run-195-audit-dashboard-verification-wave-38.py"),
    ("RUN-195 exact RUN-194 audit-dashboard verification", "evidence/browser/current-audit-dashboard-verification-run-195-wave-38.json"),
    ("RUN-196 Summary/timeline Site-privacy remediation materializer", "generators/materialize-run-196-summary-timeline-site-privacy-remediation-wave-39.py"),
    ("RUN-196 Summary/timeline Site-privacy remediation receipt", "evidence/runtime/current-run-196-summary-timeline-site-privacy-remediation-wave-39.json"),
    ("RUN-196R independent Summary/timeline remediation-review materializer", "generators/materialize-independent-run-196-summary-timeline-site-privacy-remediation-review-wave-39.py"),
    ("RUN-196R independent Summary/timeline remediation review", "evidence/runtime/current-run-196r-independent-summary-timeline-site-privacy-remediation-review-wave-39.json"),
    ("RUN-197 Summary/timeline remediation-reporting materializer", "generators/materialize-run-197-summary-timeline-site-privacy-remediation-reporting-wave-39.py"),
    ("RUN-197 Summary/timeline remediation-reporting receipt", "evidence/source/current-run-197-summary-timeline-site-privacy-remediation-reporting-wave-39.json"),
    ("RUN-198 exact RUN-197 audit-dashboard verification materializer", "generators/materialize-run-198-audit-dashboard-verification-wave-39.py"),
    ("RUN-198 exact RUN-197 audit-dashboard verification", "evidence/browser/current-audit-dashboard-verification-run-198-wave-39.json"),
    ("RUN-199 Shift-task coordination-handoff transcription", "evidence/source/current-run-199-shift-task-due-recipient-revalidation-coordination-handoff-wave-40.json"),
    ("RUN-199 Shift-task remediation-reporting materializer", "generators/materialize-run-199-shift-task-due-recipient-revalidation-remediation-reporting-wave-40.py"),
    ("RUN-199 Shift-task remediation-reporting receipt", "evidence/source/current-run-199-shift-task-due-recipient-revalidation-remediation-reporting-wave-40.json"),
    ("RUN-200 exact RUN-199 audit-dashboard verification materializer", "generators/materialize-run-200-audit-dashboard-verification-wave-40.py"),
    ("RUN-200 exact RUN-199 audit-dashboard verification", "evidence/browser/current-audit-dashboard-verification-run-200-wave-40.json"),
    ("RUN-201 Shift eligibility-alert coordination-handoff transcription", "evidence/source/current-run-201-elig-shift-notification-site-privacy-coordination-handoff-wave-41.json"),
    ("RUN-201 Shift eligibility-alert remediation-reporting materializer", "generators/materialize-run-201-elig-shift-notification-site-privacy-remediation-reporting-wave-41.py"),
    ("RUN-201 Shift eligibility-alert remediation-reporting receipt", "evidence/source/current-run-201-elig-shift-notification-site-privacy-remediation-reporting-wave-41.json"),
    ("RUN-202 exact RUN-201 audit-dashboard verification materializer", "generators/materialize-run-202-audit-dashboard-verification-wave-41.py"),
    ("RUN-202 exact RUN-201 audit-dashboard verification", "evidence/browser/current-audit-dashboard-verification-run-202-wave-41.json"),
    ("RUN-203 Fleet playback data-point eligibility coordination-handoff transcription", "evidence/source/current-run-203-fleet-trip-playback-data-point-eligibility-coordination-handoff-wave-42.json"),
    ("RUN-203 Fleet playback data-point eligibility remediation-reporting materializer", "generators/materialize-run-203-fleet-trip-playback-data-point-eligibility-remediation-reporting-wave-42.py"),
    ("RUN-203 Fleet playback data-point eligibility remediation-reporting receipt", "evidence/source/current-run-203-fleet-trip-playback-data-point-eligibility-remediation-reporting-wave-42.json"),
):
    if path not in checkpoint_paths:
        checkpoint_evidence.append((label, path))
        checkpoint_paths.add(path)
checkpoint_evidence_links = "".join(
    f'<li><a href="{html.escape(path, quote=True)}">{html.escape(label)}</a> '
    f'<code>{sha256_file(path)}</code></li>'
    for label, path in checkpoint_evidence
)
checkpoint_evidence_links += (
    '<li><a href="task-scripts/">RUN-072 task-script directory (300 files)</a> '
    f'<code>{html.escape(usability_materialization["outputs"]["task_scripts"]["bundle_sha256"])}</code></li>'
    '<li><a href="generators/materialize-run-204-audit-dashboard-verification-wave-42.py">RUN-204 audit-dashboard verification materializer</a> '
    '<span>forward generator; fresh exact-artifact verification required</span></li>'
    '<li><a href="evidence/browser/current-audit-dashboard-verification-run-204-wave-42.json">RUN-204 audit-dashboard verification receipt</a> '
    '<span>forward receipt; intentionally unhashed to avoid an evidence cycle</span></li>'
)
start_ready_ids = "<br>".join(
    html.escape(row["feature_id"])
    for row in downstream_readiness["start_ready_targets"]
)
assert completion_accounting["run_id"] == "RUN-071A"
assert len(completion_accounting["completion_gates"]) == 26
assert [row["gate"] for row in completion_accounting["completion_gates"]] == list(range(1, 27))
assert len({row["name"] for row in completion_accounting["completion_gates"]}) == 26
assert completion_accounting["completion_gates"][5]["status"] == "ZERO"
assert completion_accounting["completion_gates"][25]["status"] == "OPEN_UNTIL_FINALIZATION"
assert completion_accounting["required_artifact_snapshot"]["present"] == 9
assert completion_accounting["required_artifact_snapshot"]["required"] == 18
assert downstream_readiness["counts"]["accepted_projects"] == 9
assert downstream_readiness["counts"]["accepted_facets"] == 12
assert downstream_readiness["counts"]["start_ready_targets"] == 3
assert downstream_readiness["counts"]["mapping_ready_targets"] == 0
assert downstream_readiness["counts"]["credit_ready_targets"] == 0
assert {
    row["feature_id"] for row in downstream_readiness["start_ready_targets"]
} == {
    "CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE",
    "CAP-FIN-ALLOCATION-MATCH-HISTORY",
    "CAP-INC-INCIDENT-REVIEW-CLOSURE",
}
assert downstream_readiness["counts"]["formal_target_edges"] == 0
assert downstream_readiness["counts"]["final_no_matches"] == 0
assert downstream_readiness["counts"]["ncm"] == 0
assert usability_gap_selector["usability_counts"]["human_targets"] == 300
assert usability_gap_selector["usability_counts"]["current_task_scripts"] == 0
assert usability_contract["run_id"] == "RUN-072-USABILITY-CONTRACT"
assert frontline_auth_block["counts"]["selected_routes"] == 3
assert frontline_auth_block["status"] == "AUTHENTICATION_EXPIRED_ZERO_ROUTE_CELLS_ZERO_CREDIT"
assert frontline_auth_block["selected_routes"] == ["/my-day", "/attendance", "/operations/timesheets"]
assert frontline_auth_block["counts"]["routes_attempted_before_fail_closed"] == 1
assert frontline_auth_block["counts"]["authenticated_routes"] == 0
assert frontline_auth_block["counts"]["completed_base_route_viewport_cells"] == 0
assert frontline_auth_block["counts"]["completed_pre_submit_state_cells"] == 0
assert frontline_auth_block["counts"]["screenshots"] == 0
assert frontline_auth_block["counts"]["forms_submitted"] == 0
assert frontline_auth_block["counts"]["records_changed"] == 0
assert frontline_auth_block["counts"]["meaningful_mutations"] == 0
assert frontline_auth_block["counts"]["credentials_entered"] == 0
assert len(frontline_auth_block["attempts"]) == 2
assert all(row["final_path"] == "/login" for row in frontline_auth_block["attempts"])
assert all(row["access_outcome"] == "AUTHENTICATION_REQUIRED" for row in frontline_auth_block["attempts"])
assert {
    frontline_auth_block["deployed_build_identity"],
    frontline_auth_block["environment_identity"],
    frontline_auth_block["actor_role_identity"],
    frontline_auth_block["site_scope_identity"],
    frontline_auth_block["fixture_safety_identity"],
} == {"UNKNOWN"}
assert all(value == 0 for value in frontline_auth_block["credit_boundary"].values())
assert usability_materialization["counts"]["H_features"] == 300
assert usability_materialization["counts"]["scorecard_columns"] == 78
assert usability_materialization["counts"]["scorecard_rows"] == 300
assert usability_materialization["counts"]["task_script_files"] == 300
assert usability_materialization["counts"]["validated_task_scripts"] == 0
assert usability_materialization["counts"]["representative_role_tasks_executed"] == 0
assert usability_materialization["counts"]["current_ease_scores_measured"] == 0
assert usability_materialization["counts"]["target_ease_scores_measured"] == 0
assert usability_materialization["counts"]["independent_reviews_completed"] == 0
assert all(
    usability_materialization["sentinels"][key] == "NOT_MEASURED"
    for key in (
        "current_dimension_scores",
        "target_dimension_scores",
        "friction_measurements",
        "task_success",
    )
)
assert usability_materialization["completion_gate"]["ease_credit"] == 0
assert usability_materialization["completion_gate"]["completion_credit"] == 0
assert usability_review["status"] == "GO_AFTER_BOUNDED_INPUT_PIN_CORRECTION_ZERO_EASE_OR_COMPLETION_CREDIT"
assert usability_review["correction_review"]["residual_defects"] == 0
assert route_page_slice["counts"]["targets"] == 3
assert route_page_slice["counts"]["primary_routes"] == 16
assert route_page_slice["counts"]["runtime_routes_executed"] == 0
assert route_page_slice["counts"]["browser_routes_completed"] == 0
assert route_page_slice["counts"]["target_edges_awarded"] == 0
assert route_page_slice["counts"]["completion_credit_awarded"] == 0
assert {row["feature_id"] for row in route_page_slice["targets"]} == {
    "CAP-DAY-MY-DAY-WORKSPACE",
    "CAP-OPS-ATTENDANCE-CLOCK-SESSION",
    "CAP-OPS-TIMESHEET-AUTHOR-SUBMIT",
}
assert incident_agent_a["counts"]["packets"] == 2
assert incident_agent_a["counts"]["observations"] == 48
assert incident_agent_b_input["run_id"] == "RUN-072-B-INPUT"
assert incident_agent_b["counts"]["requirements"] == {
    "MUST": 14, "SHOULD": 0, "NOT_ESTABLISHED": 25, "total": 39,
}
assert incident_agent_c_input["run_id"] == "RUN-072-C-INPUT"
assert len(incident_agent_c["comparisons"]) == 39
assert incident_agent_c["counts"]["outcomes"] == {
    "MET": 5, "PARTIAL": 3, "GAP": 0, "CONTRADICTED": 0, "NOT_COMPARABLE": 31,
}
assert incident_agent_d_input["counts"]["agent_c_comparisons"] == 39
assert incident_agent_d_input["counts"]["credit_awards_before_adjudication"] == 0
assert incident_agent_d["comparison_verification"]["rows_reviewed"] == 39
assert incident_agent_d["comparison_verification"]["corrections"] == []
assert incident_agent_d["status"] == "COMPLETE_ZERO_CREDIT"
assert incident_agent_d["input_verification"]["sha256"] == sha256_file(
    "evidence/benchmark/sealed-run-072-agent-d-incident-adjudication-input-wave-04.json"
)
assert incident_agent_d["adjudication"]["review_facet"]["disposition"] == "NO_GO"
assert incident_agent_d["adjudication"]["closure_facet"]["disposition"] == "NO_GO"
assert incident_agent_d["adjudication"]["combined_target"]["disposition"] == "NO_GO"
assert all(value is False for value in incident_agent_d["canonical_changes"].values())
assert incident_agent_d["credit"]["final_no_match"] is False
assert incident_agent_d["credit"]["final_no_match_reason"] == (
    "The sealed comparison was not an exhaustive target-specific search."
)
assert not any(
    value is True
    for key, value in incident_agent_d["credit"].items()
    if key != "final_no_match_reason"
)
assert visual_matrix["matrix"]["rows"] == 2812
assert visual_matrix["credit_boundary"]["browser"] == 0

module_labels = sorted({row["module"] for row in targets})
assert len(module_labels) == 29
historical_discovery_findings = wave1["provisional_findings"] + wave2["provisional_findings"]
assert len(historical_discovery_findings) == 12
historical_discovery_claims = {row["finding_id"]: row["source_claim"] for row in historical_discovery_findings}
assert len(historical_discovery_claims) == 12

assert findings_register["schema_version"] == "oblivion_audit_findings_v2_mixed_current_status"
assert findings_register["audit_status"] == "EIGHT_PROVISIONAL_TWO_HISTORICAL_ALREADY_FIXED_TEN_HISTORICAL_REMEDIATED_ZERO_FINAL_FINDING_CREDIT"
assert findings_register["generated_on"] == "2026-09-01"
assert findings_register["architecture_rule"] == "One operating organisation across multiple Sites; Site access, exact action permissions, ownership, consent and privacy are the boundaries."
findings_pins = findings_register["pins"]
assert findings_pins["governing_prompt_sha256"] == "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
assert findings_pins["audit_checkpoint_parent"] == "35a5228b26c54684718495c33281b24c0992de02"
assert findings_pins["med_cd_atomicity_adjudicated_application_commit"] == run_165_source_review["pins"]["reviewed_source_checkpoint"]
assert findings_pins["med_cd_atomicity_adjudicated_application_tree"] == run_165_source_review["pins"]["reviewed_source_tree"]
assert findings_pins["run_165_med_cd_atomicity_source_review_sha256"] == current_run_164_166r_artifact_pins["evidence/source/current-run-165-med-cd-atomicity-current-source-review-wave-30.json"][0]
assert findings_pins["run_166_med_cd_atomicity_adjudication_sha256"] == current_run_164_166r_artifact_pins["evidence/runtime/current-run-166-med-cd-atomicity-already-fixed-adjudication-wave-30.json"][0]
assert findings_pins["run_166_atomicity_harness_snapshot_sha256"] == current_run_164_166r_artifact_pins["evidence/runtime/harnesses/run-166-controlled-drug-atomicity-concurrency-test.php.txt"][0]
assert findings_pins["run_166r_independent_artifact_review_sha256"] == current_run_164_166r_artifact_pins["evidence/runtime/current-run-166r-independent-med-cd-atomicity-adjudication-review-wave-30.json"][0]
assert findings_pins["run_166_repository_commit"] == "bbd9b05b03da6d98deed033471412a05cc31d6d7"
assert findings_pins["run_166_repository_tree"] == "f5e2f69d3ab02c42583daef8eb62f8732a12a584"
assert findings_pins["safe_alert_dedup_baseline_application_commit"] == "e488bd3edcda0f154f87e8bbed972f14db409b82"
assert findings_pins["safe_alert_dedup_baseline_application_tree"] == "9e93b8aea4f4b907cde3dc59dd0520fba5bd7080"
assert findings_pins["safe_alert_dedup_fix_commit"] == "dc04067e304adebb47335d4f65e8c61061ec6e29"
assert findings_pins["safe_alert_dedup_fix_tree"] == "15a2e4b47788e9f2779030ec6d4d9ca7c1022727"
assert findings_pins["safe_alert_dedup_local_main_merge_commit"] == "705db2dc3ba05a8fdf647cd28bdc9c226a694068"
assert findings_pins["safe_alert_dedup_local_main_tree"] == "59b4fc58567f64bc80ff3d2e47b52860ce44cb02"
assert findings_pins["safe_alert_dedup_origin_main_observed"] == "c39b076547056b1e158c604957a04bd8b75b0f29"
assert findings_pins["run_173_safe_alert_dedup_remediation_sha256"] == "49a4fa5ad4fefa1c72e449b69150fe05de06e8f9d0055b47e93a0a3061b66e45"
assert findings_pins["run_173r_independent_artifact_review_sha256"] == "9a19e5ccb15d955db8bf1bcd80b40a6f89306bc9945625d275f3d6f4c543e652"
assert findings_pins["fleet_trip_index_site_privacy_baseline_commit"] == "13a7f37da9c966fa531f20e82b1bb9eac814e041"
assert findings_pins["fleet_trip_index_site_privacy_baseline_tree"] == "e952efb7d0b1446d2c6b67bbd28339bd906d1b38"
assert findings_pins["fleet_trip_index_site_privacy_fix_commit"] == "790bc11e3fb2b17a0eb8ba96e2cdea87ba8175b5"
assert findings_pins["fleet_trip_index_site_privacy_fix_tree"] == "657abb07867068865f935008c2c43dea38c867c8"
assert findings_pins["fleet_trip_index_site_privacy_local_main_merge_commit"] == "c643c9e5eecf3b4272f55ec6d5aab4b99c3e300d"
assert findings_pins["fleet_trip_index_site_privacy_local_main_tree"] == "657abb07867068865f935008c2c43dea38c867c8"
assert findings_pins["fleet_trip_index_site_privacy_origin_main_observed"] == "c39b076547056b1e158c604957a04bd8b75b0f29"
assert findings_pins["run_176_fleet_trip_index_site_privacy_remediation_sha256"] == "6e9fa6d855e6ec168d4c651921702dab8872810ddd89f6ba3cd353bf49e0c87c"
assert findings_pins["run_176r_independent_artifact_review_sha256"] == "f1f7369306235ad7d5f318b512dca94e853d96e182ff5c63ddc509534fa545c1"
assert findings_pins["fleet_trip_playback_site_privacy_baseline_commit"] == "db4196ccb3a8d9f6bcb33fb40680527d09c02dac"
assert findings_pins["fleet_trip_playback_site_privacy_baseline_tree"] == "68052b68b070dff799d5be1d5515ec0b8472207f"
assert findings_pins["fleet_trip_playback_site_privacy_fix_commit"] == "93e576978efae4a0112a95ed406c312f6bcadeb5"
assert findings_pins["fleet_trip_playback_site_privacy_fix_tree"] == "f265c8476773aaceecbfe90680e59b5f4c74b205"
assert findings_pins["fleet_trip_playback_site_privacy_advanced_main_commit"] == "0537f0f0eacafbeaf635ced4883a8bdf8e49d3f6"
assert findings_pins["fleet_trip_playback_site_privacy_advanced_main_tree"] == "5eb8c401847f2da101922aef6c100b8e03d30b9d"
assert findings_pins["fleet_trip_playback_site_privacy_local_main_merge_commit"] == "4038cf7fe5a789ca64e436300f2cf4b94ac16db4"
assert findings_pins["fleet_trip_playback_site_privacy_local_main_tree"] == "b9757ccb9010564b8512c0ed47abfc553f38b697"
assert findings_pins["fleet_trip_playback_site_privacy_stable_patch_id"] == "12c306d28e54ff88432d18b271706473ee793871"
assert findings_pins["fleet_trip_playback_site_privacy_origin_main_observed"] == "c39b076547056b1e158c604957a04bd8b75b0f29"
assert findings_pins["run_183_fleet_trip_playback_site_privacy_remediation_sha256"] == "7bb1b1013cf67344c48e5a8b6e551bf3c769695e0384c2b333fb47286e53310a"
assert findings_pins["run_183r_independent_artifact_review_sha256"] == "170245898590f6429a171bbd8a41455f096b5b43340b840294735fdbc5522640"
assert findings_pins["fleet_fuel_index_site_privacy_baseline_commit"] == "df65322f8eb7d7d0f1623c4bcb8cc8c87573b71d"
assert findings_pins["fleet_fuel_index_site_privacy_baseline_tree"] == "0bd43711942416069675075ce3d515b92b9eaf7d"
assert findings_pins["fleet_fuel_index_site_privacy_fix_commit"] == "2ec4b70e379c6f8cf38c1cb67f5d676fea52cf75"
assert findings_pins["fleet_fuel_index_site_privacy_fix_tree"] == "b6e17efbf1b92b4a12bc01c55e8f245b2e206922"
assert findings_pins["fleet_fuel_index_site_privacy_advanced_main_commit"] == "9019b44cb1017931fd0491a90f96ac32a6c4420c"
assert findings_pins["fleet_fuel_index_site_privacy_advanced_main_tree"] == "81a4a14e31c88c9731f24a6addee85377ac54256"
assert findings_pins["fleet_fuel_index_site_privacy_local_main_merge_commit"] == "04c32c36fdda6ce60ce281c06ad68aaa78527422"
assert findings_pins["fleet_fuel_index_site_privacy_local_main_tree"] == "6f85ddc1f4e8551c99528cc0c872b37da6c7763a"
assert findings_pins["fleet_fuel_index_site_privacy_stable_patch_id"] == "636771c0b1d9cbe50b2204febaa41679d340aba9"
assert findings_pins["fleet_fuel_index_site_privacy_origin_main_observed"] == "c39b076547056b1e158c604957a04bd8b75b0f29"
assert findings_pins["run_192_dashboard_verification_materializer_sha256"] == "899eb63bf6416801187334c12af8c781029c457e0f7e2cbbaf75604991fbe14f"
assert findings_pins["run_192_dashboard_verification_sha256"] == "cc95b38ece501bea317e78ef7769a7813e3a5a6c2041330e249fd196974cbb88"
assert findings_pins["run_192_dashboard_verification_self_seal_sha256"] == "a112198be2915cfc8a88b31b38a9cb33c90ad407b6da83f85fac5deae6727995"
assert findings_pins["run_192_verified_dashboard_sha256"] == "8d19569e7bfb256edeecdc754e2bc47e2ddad3ecd8de099e3bb0dad9b50e313b"
assert findings_pins["run_193_fleet_fuel_index_site_privacy_remediation_generator_sha256"] == "105632bc2c4e50de3e8cfdd55fb25810fbbe5307537bd90b0e153b25f7c4e319"
assert findings_pins["run_193_fleet_fuel_index_site_privacy_remediation_sha256"] == "1396205a5f63d4571b0e5b738f00f3a7cadc8ab93499a012e0e0f827b70b495f"
assert findings_pins["run_193r_independent_artifact_review_materializer_sha256"] == "953d8d68aa2a869fe5c82f495de60b351c49b53f846955167f7f1434964541ac"
assert findings_pins["run_193r_independent_artifact_review_sha256"] == "87a1157f26bbfaf062ec22bceb616bf4f54c72f908cddfd68c2b59db91cbbb41"
assert findings_pins["monitoring_metric_replay_application_baseline_commit"] == "a900f078c9c05f587f6f7884f5fe715076891416"
assert findings_pins["monitoring_metric_replay_initial_fix_commit"] == "f521bc0b87222e56b4822e7cb9c935486e279e76"
assert findings_pins["monitoring_metric_replay_initial_merge_commit"] == "778c00a5d09511aee1a836a689d7bb1b56ce4ff6"
assert findings_pins["monitoring_metric_replay_corrective_fix_commit"] == "c82f57779baf623c4e94ac4619b11c1b675d0230"
assert findings_pins["monitoring_metric_replay_corrective_merge_commit"] == "18652d545c788f1dcdbe57662e5b1e5472d6cae7"
assert findings_pins["monitoring_metric_replay_corrective_merge_tree"] == "095cd7b1940988be334979af22008c635fdcaf58"
assert findings_pins["monitoring_metric_replay_current_local_main_commit"] == "f938c6d989f5fef052f08b9f1012116fb5cf2f69"
assert findings_pins["monitoring_metric_replay_current_local_main_tree"] == "70b2339300278bc0c20e32ed091f74b442bea76d"
assert findings_pins["run_185_dashboard_verification_sha256"] == "e6965bba3f25b80e6ce70aa3656802956bed935d79aaf46576e1420f0c65e07c"
assert findings_pins["run_186_monitoring_metric_replay_dedupe_remediation_sha256"] == "bf2cd03ca2ab7aeb6a9d1093b3c08aba5a1bc29342cc4fda6fa57ef286c2f1e5"
assert findings_pins["run_186r_independent_artifact_review_sha256"] == "035271d7bfcd4256a59f01e9953f9cd8074466c0389f74ce82325a46ee6a6af7"
assert findings_pins["summary_timeline_site_privacy_baseline_commit"] == "39a5d97d7d0ff9ea03070e90193581479f423022"
assert findings_pins["summary_timeline_site_privacy_baseline_tree"] == "90b9adba1261fb1ec30d9fe4b13daaf5149fc1dc"
assert findings_pins["summary_timeline_site_privacy_audit_release_commit"] == "4c47d2eeed0b1006c11166da8ab8b0747d7554b7"
assert findings_pins["summary_timeline_site_privacy_audit_release_tree"] == "67d02dab74cdb608a019432bcb032520cd02db3e"
assert findings_pins["summary_timeline_site_privacy_fix_commit"] == "31a9edfbab32a19062ccf15e123cd0b0923b7dc3"
assert findings_pins["summary_timeline_site_privacy_fix_tree"] == "5e8e8f5e560b5ff2d157902808e2c0b5e17952f5"
assert findings_pins["summary_timeline_site_privacy_local_main_merge_commit"] == "5c8a1357f830d0b8a8c14924016d89df52ab9e86"
assert findings_pins["summary_timeline_site_privacy_local_main_tree"] == "974af4e10eea90e9e9254d509443b49cf0052931"
assert findings_pins["summary_timeline_site_privacy_current_main_commit"] == "44ab5e270aecd961e2e75abcdbe4d2cb1effa3df"
assert findings_pins["summary_timeline_site_privacy_current_main_tree"] == "cae56eafa2c63af68e099995b08b3c926575373b"
assert findings_pins["summary_timeline_site_privacy_origin_main_observed"] == "c39b076547056b1e158c604957a04bd8b75b0f29"
assert findings_pins["run_196_summary_timeline_site_privacy_remediation_generator_sha256"] == "e8c45110a983d2d210501024d89d6f9b968103141b86feb174c5641757dd5555"
assert findings_pins["run_196_summary_timeline_site_privacy_remediation_sha256"] == "96c275826a695a4b41b98891bd6560e6592be415c43fa360f1730c0c7fe9013a"
assert findings_pins["run_196r_independent_artifact_review_materializer_sha256"] == "0c4fb643e608fa73fdc6118a7b83d1024123cd7857b84c36a136b51b3244edc8"
assert findings_pins["run_196r_independent_artifact_review_sha256"] == "a53d2b279cf1becff1e7b851d522a43fb2cacfc05f5099250da910c9d3fbe151"
assert findings_pins["shift_task_due_recipient_revalidation_baseline_commit"] == "47a6d231c52a78c9f0f606e41d4492d754771027"
assert findings_pins["shift_task_due_recipient_revalidation_baseline_tree"] == "c1e262a50c67797b819d3f1085ece2782b41237e"
assert findings_pins["shift_task_due_recipient_revalidation_audit_release_commit"] == "ca3425103d6d75dc728418464d03e7e72983925b"
assert findings_pins["shift_task_due_recipient_revalidation_audit_release_tree"] == "662962768c24c0c0eb2231dcd42caf49cfe9c910"
assert findings_pins["shift_task_due_recipient_revalidation_fix_commit"] == "6186176d30a9b4061f859eef8d069e8739ef3d88"
assert findings_pins["shift_task_due_recipient_revalidation_fix_tree"] == "a089d5212e9674bb0e7915c96806867e52d1015f"
assert findings_pins["shift_task_due_recipient_revalidation_local_main_merge_commit"] == "e2593cbdd0791aca2ea7b1e9b254d07bf7f8e84f"
assert findings_pins["shift_task_due_recipient_revalidation_local_main_tree"] == "071edd9408f27206bc6962157e4a84c30590f701"
assert findings_pins["shift_task_due_recipient_revalidation_stable_patch_id"] == "af8be2614ff89b34632299424cdd28e011ee1d84"
assert findings_pins["shift_task_due_recipient_revalidation_origin_main_observed"] == "c39b076547056b1e158c604957a04bd8b75b0f29"
assert findings_pins["run_200_dashboard_verification_materializer_sha256"] == "023d06929555d20dbc242bb998e05ee7fc60c0917d0e62b39ab56356a74de578"
assert findings_pins["run_200_dashboard_verification_sha256"] == "59b80aa14c8841f412d9b76003cc8f2dcd135634cd9394a43523bad31f62c520"
assert findings_pins["run_200_dashboard_verification_self_seal_sha256"] == "493b62087f2df1f2ff776f68c162fceb38ab69763a0b2554ba0148dd6c58d216"
assert findings_pins["run_200_verified_dashboard_sha256"] == "f643ca1ec1716cfb2b32864aba1a97e8d69c3e726453707a3ce71e76b3c43205"
assert findings_pins["elig_shift_notification_site_privacy_baseline_commit"] == "f7c6f9ee476534cbbc13042b68d5388e0681b535"
assert findings_pins["elig_shift_notification_site_privacy_baseline_tree"] == "33f69dc0848cca66ad317e42ba8a61eba46ac1e4"
assert findings_pins["elig_shift_notification_site_privacy_audit_release_commit"] == "9c01f5a4f57f96722015278d1df3c3bd111aa95c"
assert findings_pins["elig_shift_notification_site_privacy_audit_release_tree"] == "c9b0f223e5c63870cc5c04708babece98c00435f"
assert findings_pins["elig_shift_notification_site_privacy_fix_commit"] == "95fb2677a417c69c2008fefcc0cf9404984c9b54"
assert findings_pins["elig_shift_notification_site_privacy_fix_tree"] == "412d3dc3ff3f9fd864b626b565ce419372cd2ee2"
assert findings_pins["elig_shift_notification_site_privacy_local_main_merge_commit"] == "1382dd4a48b35d9f9155c2dd501a8a3f4f60d47c"
assert findings_pins["elig_shift_notification_site_privacy_local_main_tree"] == "50ba282b5ded0d8d0d4f9fb19bf8e79f3ce96014"
assert findings_pins["elig_shift_notification_site_privacy_stable_patch_id"] == "1381114bba1a102630a020211a07b303a1d6240d"
assert findings_pins["elig_shift_notification_site_privacy_origin_main_observed"] == "c39b076547056b1e158c604957a04bd8b75b0f29"
assert findings_pins["run_202_dashboard_verification_materializer_sha256"] == "05685136cf43f637e0835c8f8301f270c60466fce79868ffb033922095333355"
assert findings_pins["run_202_dashboard_verification_sha256"] == "b63ed9585a03cc852d0f772be42de303f0866c73e80cc8522e8de0d328887471"
assert findings_pins["run_202_dashboard_verification_self_seal_sha256"] == "a4d296e2a3f779bfa2c7cf34233958a37dc74bb5f6e4f7d78a867d6cb12dc3b8"
assert findings_pins["run_202_verified_dashboard_sha256"] == "1876db1ff590c86fb30cefb74368b0241c72d9b75966fcbf1a36d6b1096b30e3"
assert findings_pins["fleet_trip_playback_data_point_eligibility_baseline_commit"] == "9c01f5a4f57f96722015278d1df3c3bd111aa95c"
assert findings_pins["fleet_trip_playback_data_point_eligibility_baseline_tree"] == "c9b0f223e5c63870cc5c04708babece98c00435f"
assert findings_pins["fleet_trip_playback_data_point_eligibility_audit_release_commit"] == "b61a2abd48a3d80ef91f6edcdf51d3ad253715e6"
assert findings_pins["fleet_trip_playback_data_point_eligibility_audit_release_tree"] == "1d8dd6ca99282df8d8f72f21eba6807a1e8f8b4b"
assert findings_pins["fleet_trip_playback_data_point_eligibility_fix_commit"] == "9c40c51a26048b00d035bf13745a20385794d86b"
assert findings_pins["fleet_trip_playback_data_point_eligibility_fix_tree"] == "319ec45b5939900c1f00be447ab28486caa821ea"
assert findings_pins["fleet_trip_playback_data_point_eligibility_local_main_merge_commit"] == "ba39cbc36694164ca9e0f232efd2de00013191b5"
assert findings_pins["fleet_trip_playback_data_point_eligibility_local_main_tree"] == "1b384bc15377dbf1e2410580681cd46613ab9ef6"
assert findings_pins["fleet_trip_playback_data_point_eligibility_stable_patch_id"] == "93126baf39d11dc22f1fc3f1d990fa1d376222b6"
assert findings_pins["fleet_trip_playback_data_point_eligibility_origin_main_observed"] == "c39b076547056b1e158c604957a04bd8b75b0f29"
live_findings = findings_register["records"]
assert len(live_findings) == findings_register["counts"]["retained_claim_records"] == 20
assert {row["id"] for row in live_findings} == set(historical_discovery_claims) | {
    "FLEET-TRIP-INDEX-SITE-PRIVACY-01",
    "FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01",
    "FLEET-FUEL-INDEX-SITE-PRIVACY-01",
    "MON-METRIC-REPLAY-DEDUPE-01",
    "SUMMARY-TIMELINE-SITE-PRIVACY-01",
    "SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01",
    "ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01",
    "FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01",
}
provisional_findings = [
    row for row in live_findings
    if row["record_status"] == "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING"
]
historical_fixed_findings = [
    row for row in live_findings
    if row["record_status"] == "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING"
]
historical_remediated_findings = [
    row for row in live_findings
    if row["record_status"] == "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
]
assert len(provisional_findings) == findings_register["counts"]["provisional_source_claims"] == 8
assert len(historical_fixed_findings) == findings_register["counts"]["historical_already_fixed"] == 2
assert len(historical_remediated_findings) == findings_register["counts"]["historical_remediated"] == 10
historical_fixed_by_id = {row["id"]: row for row in historical_fixed_findings}
assert set(historical_fixed_by_id) == {"MED-RBAC-01", "MED-CD-ATOMICITY-01"}
assert historical_fixed_by_id["MED-RBAC-01"]["current_adjudication"]["verdict"] == "ALREADY_FIXED"
assert historical_fixed_by_id["MED-RBAC-01"]["current_adjudication"]["separate_med_cd_scope_or_atomicity_inherited"] is False
atomicity_finding = historical_fixed_by_id["MED-CD-ATOMICITY-01"]
assert atomicity_finding["historical_provenance"]["canonical_pre_adjudication_record_sha256"] == "9ba4f430ee59efea414b42a8633c1c969a2fd4428fbf3fef173fb5548cc8e7f1"
assert atomicity_finding["current_adjudication"]["verdict"] == "ALREADY_FIXED"
assert atomicity_finding["current_adjudication"]["scope"] == "manual POST /emar/controlled/entries register and stock atomicity only"
assert atomicity_finding["current_adjudication"]["application_commit"] == run_165_source_review["pins"]["reviewed_source_checkpoint"]
assert atomicity_finding["current_adjudication"]["application_tree"] == run_165_source_review["pins"]["reviewed_source_tree"]
assert atomicity_finding["current_adjudication"]["effective_application_commit"] == run_166_adjudication["pins"]["effective_application_commit"]
assert atomicity_finding["current_adjudication"]["effective_application_tree"] == run_166_adjudication["pins"]["effective_application_tree"]
assert atomicity_finding["current_adjudication"]["run_165_source_review_sha256"] == current_run_164_166r_artifact_pins["evidence/source/current-run-165-med-cd-atomicity-current-source-review-wave-30.json"][0]
assert atomicity_finding["current_adjudication"]["run_166_receipt_sha256"] == current_run_164_166r_artifact_pins["evidence/runtime/current-run-166-med-cd-atomicity-already-fixed-adjudication-wave-30.json"][0]
assert atomicity_finding["current_adjudication"]["run_166r_review_sha256"] == current_run_164_166r_artifact_pins["evidence/runtime/current-run-166r-independent-med-cd-atomicity-adjudication-review-wave-30.json"][0]
assert atomicity_finding["current_adjudication"]["immutable_harness_snapshot_sha256"] == current_run_164_166r_artifact_pins["evidence/runtime/harnesses/run-166-controlled-drug-atomicity-concurrency-test.php.txt"][0]
assert atomicity_finding["current_adjudication"]["application_remediation_required"] is False
assert atomicity_finding["current_adjudication"]["application_source_changed_by_adjudication"] is False
assert atomicity_finding["current_adjudication"]["product_test_integrated_by_adjudication"] is False
assert atomicity_finding["current_adjudication"]["residual_compound_scope_inherited"] is False
atomicity_residual = atomicity_finding["residual_unadjudicated_scope"]
assert atomicity_residual["manual_store_cd_entry_register_stock_atomicity_adjudicated"] is True
assert all(
    atomicity_residual[key] is False
    for key in (
        "store_balance_check_adjudicated",
        "destruction_relationship_checks_adjudicated",
        "delivery_stock_adjustment_loss_report_or_sibling_writer_adjudicated",
        "forced_transient_deadlock_retry_adjudicated",
        "stress_or_repeated_schedule_adjudicated",
        "supporting_43_test_716_assertion_overlap_grants_denominator_credit",
        "rollback_test_balance_check_half_grants_balance_check_credit",
    )
)
assert atomicity_residual["must_remain_explicit_without_inherited_credit"] is True
historical_remediated_by_id = {row["id"]: row for row in historical_remediated_findings}
assert set(historical_remediated_by_id) == {
    "MED-CD-SCOPE-01",
    "SAFE-ALERT-DEDUP-IDENTITY-01",
    "FLEET-TRIP-INDEX-SITE-PRIVACY-01",
    "FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01",
    "FLEET-FUEL-INDEX-SITE-PRIVACY-01",
    "MON-METRIC-REPLAY-DEDUPE-01",
    "SUMMARY-TIMELINE-SITE-PRIVACY-01",
    "SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01",
    "ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01",
    "FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01",
}
scope_finding = historical_remediated_by_id["MED-CD-SCOPE-01"]
assert scope_finding["current_adjudication"]["verdict"] == "REPRODUCED_AND_REMEDIATED"
assert scope_finding["current_adjudication"]["application_commit"] == run_162_pins["application_commit"]
assert scope_finding["current_adjudication"]["repository_tree"] == run_162_pins["repository_tree_at_application_commit"]
assert scope_finding["current_adjudication"]["separate_med_cd_atomicity_inherited"] is False
safe_finding = historical_remediated_by_id["SAFE-ALERT-DEDUP-IDENTITY-01"]
assert safe_finding["historical_provenance"]["canonical_pre_adjudication_record_sha256"] == "360386fe1222c75437c2f6140f0860679f67c63f4fe1e95fe5e8bdcc985030a8"
assert safe_finding["current_adjudication"]["verdict"] == "REPRODUCED_AND_REMEDIATED_LOCAL_MAIN_NOT_PUBLISHED"
assert safe_finding["current_adjudication"]["application_commit"] == "705db2dc3ba05a8fdf647cd28bdc9c226a694068"
assert safe_finding["current_adjudication"]["repository_tree"] == "59b4fc58567f64bc80ff3d2e47b52860ce44cb02"
assert safe_finding["current_adjudication"]["origin_main_observed"] == "c39b076547056b1e158c604957a04bd8b75b0f29"
assert safe_finding["current_adjudication"]["published_to_origin_main"] is False
assert safe_finding["current_adjudication"]["publication_authorized"] is False
assert all(
    safe_finding["current_adjudication"][key] is False
    for key in (
        "safe_intake_canonical_scope_inherited",
        "safe_projection_durability_inherited",
        "timeless_retry_inherited",
        "terminal_transition_fixture_debt_inherited",
        "broader_safeguarding_correctness_inherited",
    )
)
fleet_finding = historical_remediated_by_id["FLEET-TRIP-INDEX-SITE-PRIVACY-01"]
assert fleet_finding["feature_id"] == fleet_finding["candidate_feature_id"] == "CAP-FLEET-VEHICLE-REGISTER"
assert fleet_finding["feature_identity_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
assert fleet_finding["feature_id_role"] == "CANDIDATE_REPORTING_ASSOCIATION_ONLY_ZERO_STATIC_OWNERSHIP_CREDIT"
assert fleet_finding["current_adjudication"]["verdict"] == "REPRODUCED_AND_REMEDIATED_LOCAL_MAIN_NOT_PUBLISHED"
assert fleet_finding["current_adjudication"]["application_commit"] == "c643c9e5eecf3b4272f55ec6d5aab4b99c3e300d"
assert fleet_finding["current_adjudication"]["repository_tree"] == "657abb07867068865f935008c2c43dea38c867c8"
assert fleet_finding["current_adjudication"]["published_to_origin_main"] is False
assert fleet_finding["route_url"]["ownership_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
assert fleet_finding["evidence"]["tests_executed"] == 5
assert fleet_finding["evidence"]["assertions"] == 175
fleet_playback_finding = historical_remediated_by_id["FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01"]
assert fleet_playback_finding["feature_id"] == fleet_playback_finding["candidate_feature_id"] == "CAP-FLEET-VEHICLE-REGISTER"
assert fleet_playback_finding["feature_identity_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
assert fleet_playback_finding["feature_id_role"] == "CANDIDATE_REPORTING_ASSOCIATION_ONLY_ZERO_STATIC_OWNERSHIP_CREDIT"
assert fleet_playback_finding["current_adjudication"]["verdict"] == "REPRODUCED_AND_REMEDIATED_LOCAL_MAIN_NOT_PUBLISHED"
assert fleet_playback_finding["current_adjudication"]["application_commit"] == "4038cf7fe5a789ca64e436300f2cf4b94ac16db4"
assert fleet_playback_finding["current_adjudication"]["repository_tree"] == "b9757ccb9010564b8512c0ed47abfc553f38b697"
assert fleet_playback_finding["current_adjudication"]["published_to_origin_main"] is False
assert fleet_playback_finding["route_url"]["ownership_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
assert fleet_playback_finding["evidence"]["tests_executed"] == 11
assert fleet_playback_finding["evidence"]["assertions"] == 167
fleet_fuel_finding = historical_remediated_by_id["FLEET-FUEL-INDEX-SITE-PRIVACY-01"]
assert fleet_fuel_finding["feature_id"] == fleet_fuel_finding["candidate_feature_id"] == "CAP-FLEET-VEHICLE-REGISTER"
assert fleet_fuel_finding["feature_identity_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
assert fleet_fuel_finding["feature_id_role"] == "CANDIDATE_REPORTING_ASSOCIATION_ONLY_ZERO_STATIC_OWNERSHIP_CREDIT"
assert fleet_fuel_finding["current_adjudication"]["verdict"] == "REPRODUCED_AND_REMEDIATED_LOCAL_MAIN_NOT_PUBLISHED"
assert fleet_fuel_finding["current_adjudication"]["application_commit"] == "04c32c36fdda6ce60ce281c06ad68aaa78527422"
assert fleet_fuel_finding["current_adjudication"]["repository_tree"] == "6f85ddc1f4e8551c99528cc0c872b37da6c7763a"
assert fleet_fuel_finding["current_adjudication"]["published_to_origin_main"] is False
assert fleet_fuel_finding["route_url"]["ownership_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
assert fleet_fuel_finding["route_url"]["queue_id"] == "RUN090-ROUTE-0088"
assert fleet_fuel_finding["route_url"]["route_record_id"] == "RUN077-ROUTE-0696"
assert fleet_fuel_finding["evidence"]["tests_executed"] == 6
assert fleet_fuel_finding["evidence"]["assertions"] == 206
assert fleet_fuel_finding["evidence"]["supporting_tests"] == 20
assert fleet_fuel_finding["evidence"]["supporting_assertions"] == 215
assert fleet_fuel_finding["current_adjudication"]["independent_visible_row_logger_site_redaction_inherited"] is False
metric_finding = historical_remediated_by_id["MON-METRIC-REPLAY-DEDUPE-01"]
assert metric_finding["feature_id"] is None
assert metric_finding["candidate_feature_id"] is None
assert metric_finding["related_feature_ids"] == []
assert metric_finding["feature_identity_status"] == "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW"
assert metric_finding["feature_id_role"] == "NO_CANONICAL_OR_CANDIDATE_FEATURE_ASSOCIATION_ZERO_STATIC_OWNERSHIP_CREDIT"
assert metric_finding["route_url"]["ownership_status"] == "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW"
assert metric_finding["current_adjudication"]["verdict"] == "REPRODUCED_INITIAL_FIX_POST_MERGE_NO_GO_CORRECTED_AND_REMEDIATED_LOCAL_MAIN_NOT_PUBLISHED"
assert metric_finding["current_adjudication"]["application_commit"] == "18652d545c788f1dcdbe57662e5b1e5472d6cae7"
assert metric_finding["current_adjudication"]["repository_tree"] == "095cd7b1940988be334979af22008c635fdcaf58"
assert metric_finding["current_adjudication"]["current_local_main_commit"] == "f938c6d989f5fef052f08b9f1012116fb5cf2f69"
assert metric_finding["evidence"]["tests_executed"] == 56
assert metric_finding["evidence"]["assertions"] == 472
assert metric_finding["evidence"]["initial_superseded_tests"] == 49
assert metric_finding["evidence"]["initial_superseded_assertions"] == 392
assert metric_finding["option_a_deployment_boundary"]["prerequisites_in_order"] == [
    "quiesce old monitoring workers",
    "reconcile pending or incoherent rows",
    "apply migration 000110",
    "start new workers only after cutover reconciliation",
]
assert metric_finding["option_a_deployment_boundary"]["poisoned_subsecond_evidence_requires_operator_reconciliation"] is True
assert metric_finding["option_a_deployment_boundary"]["verified_in_production"] is False
assert metric_finding["option_a_deployment_boundary"]["migration_deployment_credit"] is False
assert metric_finding["option_a_deployment_boundary"]["release_or_publication_credit"] is False
summary_finding = historical_remediated_by_id["SUMMARY-TIMELINE-SITE-PRIVACY-01"]
assert summary_finding["feature_id"] is None
assert summary_finding["candidate_feature_id"] is None
assert summary_finding["related_feature_ids"] == []
assert summary_finding["feature_identity_status"] == "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW"
assert summary_finding["feature_id_role"] == "NO_CANONICAL_OR_CANDIDATE_FEATURE_ASSOCIATION_ZERO_STATIC_OWNERSHIP_CREDIT"
assert summary_finding["route_url"]["ownership_status"] == "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW"
assert summary_finding["current_adjudication"]["verdict"] == "REPRODUCED_AND_REMEDIATED_LOCAL_MAIN_NOT_PUBLISHED"
assert summary_finding["current_adjudication"]["application_commit"] == "5c8a1357f830d0b8a8c14924016d89df52ab9e86"
assert summary_finding["current_adjudication"]["repository_tree"] == "974af4e10eea90e9e9254d509443b49cf0052931"
assert summary_finding["evidence"]["tests_executed"] == 15
assert summary_finding["evidence"]["assertions"] == 32
assert summary_finding["evidence"]["supporting_tests"] == 2
assert summary_finding["evidence"]["supporting_assertions"] == 238
assert summary_finding["evidence"]["shared_post_merge_tests"] == 40
assert summary_finding["evidence"]["shared_post_merge_assertions"] == 438
assert summary_finding["evidence"]["baseline_failed_cases"] == 1
assert summary_finding["evidence"]["baseline_passed_cases"] == 5
assert summary_finding["evidence"]["baseline_assertions"] == 9
assert all(
    summary_finding["current_adjudication"][key] is False
    for key in (
        "static_route_or_page_feature_ownership_inherited",
        "static_controller_action_bridge_inherited",
        "queue_advance_inherited",
        "my_day_remediation_or_runtime_inherited",
        "adjacent_summary_timeline_surface_correctness_inherited",
        "broader_staff_or_client_authorization_inherited",
        "published_to_origin_main",
        "publication_authorized",
    )
)
shift_task_finding = historical_remediated_by_id["SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01"]
assert shift_task_finding["feature_id"] is None
assert shift_task_finding["candidate_feature_id"] is None
assert shift_task_finding["related_feature_ids"] == []
assert shift_task_finding["feature_identity_status"] == "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW"
assert shift_task_finding["feature_id_role"] == "NO_CANONICAL_OR_CANDIDATE_FEATURE_ASSOCIATION_ZERO_STATIC_OWNERSHIP_CREDIT"
assert shift_task_finding["route_url"]["ownership_status"] == "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW"
assert shift_task_finding["current_adjudication"]["verdict"] == "REPRODUCED_AND_REMEDIATED_LOCAL_MAIN_NOT_PUBLISHED"
assert shift_task_finding["current_adjudication"]["application_commit"] == "e2593cbdd0791aca2ea7b1e9b254d07bf7f8e84f"
assert shift_task_finding["current_adjudication"]["repository_tree"] == "071edd9408f27206bc6962157e4a84c30590f701"
assert shift_task_finding["current_adjudication"]["coordination_handoff_transcription"] == "evidence/source/current-run-199-shift-task-due-recipient-revalidation-coordination-handoff-wave-40.json"
assert shift_task_finding["evidence"]["coordination_handoff_transcription"] == "evidence/source/current-run-199-shift-task-due-recipient-revalidation-coordination-handoff-wave-40.json"
assert shift_task_finding["evidence"]["delegated_not_reexecuted_by_run_199"] is True
assert shift_task_finding["evidence"]["test_commands_executed"] is None
assert shift_task_finding["evidence"]["test_command_text"] is None
assert shift_task_finding["evidence"]["tests_executed"] == 9
assert shift_task_finding["evidence"]["assertions"] == 50
assert shift_task_finding["evidence"]["baseline_failed_cases"] == 1
assert shift_task_finding["evidence"]["baseline_passed_cases"] == 3
assert shift_task_finding["evidence"]["baseline_pending_cases"] == 1
assert shift_task_finding["evidence"]["baseline_assertions"] == 14
assert "scheduler-time denial" in shift_task_finding["acceptance_criteria"]["given_when_then"]
assert "queue-time denial" in shift_task_finding["acceptance_criteria"]["given_when_then"]
assert "ended" not in shift_task_finding["acceptance_criteria"]["given_when_then"]
assert all(
    shift_task_finding["current_adjudication"][key] is False
    for key in (
        "static_route_or_page_feature_ownership_inherited",
        "static_controller_action_bridge_inherited",
        "queue_advance_inherited",
        "my_day_or_control_room_credit_inherited",
        "post_alert_same_site_reassignment_reconciliation_inherited",
        "replacement_assignee_rerouting_inherited",
        "external_delivery_exactly_once_inherited",
        "transactional_outbox_inherited",
        "broader_push_or_access_service_correctness_inherited",
        "published_to_origin_main",
        "publication_authorized",
    )
)
elig_shift_finding = historical_remediated_by_id["ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01"]
assert elig_shift_finding["feature_id"] is None
assert elig_shift_finding["candidate_feature_id"] is None
assert elig_shift_finding["related_feature_ids"] == []
assert elig_shift_finding["feature_identity_status"] == "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW"
assert elig_shift_finding["feature_id_role"] == "NO_CANONICAL_OR_CANDIDATE_FEATURE_ASSOCIATION_ZERO_STATIC_OWNERSHIP_CREDIT"
assert elig_shift_finding["route_url"]["ownership_status"] == "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW"
assert elig_shift_finding["current_adjudication"]["verdict"] == "REPRODUCED_AND_REMEDIATED_LOCAL_MAIN_NOT_PUBLISHED"
assert elig_shift_finding["current_adjudication"]["application_commit"] == "1382dd4a48b35d9f9155c2dd501a8a3f4f60d47c"
assert elig_shift_finding["current_adjudication"]["repository_tree"] == "50ba282b5ded0d8d0d4f9fb19bf8e79f3ce96014"
assert elig_shift_finding["current_adjudication"]["coordination_handoff_transcription"] == "evidence/source/current-run-201-elig-shift-notification-site-privacy-coordination-handoff-wave-41.json"
assert elig_shift_finding["evidence"]["coordination_handoff_transcription"] == "evidence/source/current-run-201-elig-shift-notification-site-privacy-coordination-handoff-wave-41.json"
assert elig_shift_finding["evidence"]["delegated_not_reexecuted_by_run_201"] is True
assert elig_shift_finding["evidence"]["test_commands_executed"] is None
assert elig_shift_finding["evidence"]["test_command_text"] is None
assert elig_shift_finding["evidence"]["tests_executed"] == 13
assert elig_shift_finding["evidence"]["assertions"] == 25
assert elig_shift_finding["evidence"]["baseline_failed_cases"] == 1
assert elig_shift_finding["evidence"]["baseline_passed_cases"] == 0
assert elig_shift_finding["evidence"]["baseline_pending_cases"] == 4
assert elig_shift_finding["evidence"]["baseline_assertions"] == 1
assert elig_shift_finding["evidence"]["intermediate_no_go_tests"] == 12
assert elig_shift_finding["evidence"]["intermediate_no_go_assertions"] == 23
assert "canonical current Shift" in elig_shift_finding["acceptance_criteria"]["given_when_then"]
assert "remote" in elig_shift_finding["acceptance_criteria"]["given_when_then"]
assert all(
    elig_shift_finding["current_adjudication"][key] is False
    for key in (
        "static_route_or_page_feature_ownership_inherited",
        "static_controller_action_bridge_inherited",
        "queue_advance_inherited",
        "shift_signal_emission_or_idempotency_correctness_inherited",
        "eligibility_rule_correctness_inherited",
        "user_site_access_service_correctness_inherited",
        "notification_transport_or_outbox_correctness_inherited",
        "broader_roster_shift_or_hr_privacy_inherited",
        "published_to_origin_main",
        "publication_authorized",
    )
)
fleet_playback_data_finding = historical_remediated_by_id["FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01"]
assert fleet_playback_data_finding["feature_id"] == "CAP-FLEET-VEHICLE-REGISTER"
assert fleet_playback_data_finding["candidate_feature_id"] == "CAP-FLEET-VEHICLE-REGISTER"
assert fleet_playback_data_finding["related_feature_ids"] == []
assert fleet_playback_data_finding["feature_identity_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
assert fleet_playback_data_finding["feature_id_role"] == "CANDIDATE_REPORTING_ASSOCIATION_ONLY_ZERO_STATIC_OWNERSHIP_CREDIT"
assert fleet_playback_data_finding["route_url"]["ownership_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
assert fleet_playback_data_finding["route_url"]["route_names"] == "fleet-assets.trips.playback.data"
assert fleet_playback_data_finding["current_adjudication"]["verdict"] == "REPRODUCED_AND_REMEDIATED_LOCAL_MAIN_NOT_PUBLISHED"
assert fleet_playback_data_finding["current_adjudication"]["application_commit"] == "ba39cbc36694164ca9e0f232efd2de00013191b5"
assert fleet_playback_data_finding["current_adjudication"]["repository_tree"] == "1b384bc15377dbf1e2410580681cd46613ab9ef6"
assert fleet_playback_data_finding["current_adjudication"]["coordination_handoff_transcription"] == "evidence/source/current-run-203-fleet-trip-playback-data-point-eligibility-coordination-handoff-wave-42.json"
assert fleet_playback_data_finding["evidence"]["coordination_handoff_transcription"] == "evidence/source/current-run-203-fleet-trip-playback-data-point-eligibility-coordination-handoff-wave-42.json"
assert fleet_playback_data_finding["evidence"]["delegated_not_reexecuted_by_run_203"] is True
assert fleet_playback_data_finding["evidence"]["test_commands_executed"] is None
assert fleet_playback_data_finding["evidence"]["test_command_text"] is None
assert fleet_playback_data_finding["evidence"]["tests_executed"] == 27
assert fleet_playback_data_finding["evidence"]["assertions"] == 213
assert fleet_playback_data_finding["evidence"]["credited_component_tests"] == 1
assert fleet_playback_data_finding["evidence"]["credited_component_assertions"] == 6
assert fleet_playback_data_finding["evidence"]["baseline_failed_cases"] == 1
assert fleet_playback_data_finding["evidence"]["baseline_passed_cases"] == 0
assert fleet_playback_data_finding["evidence"]["baseline_pending_cases"] == 0
assert fleet_playback_data_finding["evidence"]["baseline_assertions"] == 3
assert "coordinate-incomplete" in fleet_playback_data_finding["acceptance_criteria"]["given_when_then"]
assert "2,000" in fleet_playback_data_finding["acceptance_criteria"]["given_when_then"]
assert all(
    fleet_playback_data_finding["current_adjudication"][key] is False
    for key in (
        "static_route_or_page_feature_ownership_inherited",
        "static_controller_action_bridge_inherited",
        "queue_advance_inherited",
        "prior_playback_privacy_credit_inherited",
        "fleet_management_correctness_inherited",
        "permission_site_or_direct_object_credit_inherited",
        "telemetry_ingest_lifecycle_or_range_credit_inherited",
        "map_frontend_or_adjacent_fleet_credit_inherited",
        "published_to_origin_main",
        "publication_authorized",
    )
)
assert all(row["completion_credit"] is False for row in live_findings)
assert all(all(value is False for value in row["credit"].values()) for row in live_findings)
assert findings_register["counts"]["bounded_disposition_tests_passed"] == 199
assert findings_register["counts"]["bounded_disposition_assertions"] == 2722
assert "5/175 post-merge focused FLEET-TRIP-INDEX-SITE-PRIVACY" in findings_register["counts"]["bounded_disposition_sum_basis"]
assert "11/167 post-merge focused FLEET-TRIP-PLAYBACK-SITE-PRIVACY" in findings_register["counts"]["bounded_disposition_sum_basis"]
assert "only the final post-corrective-merge 56/472 MON-METRIC-REPLAY-DEDUPE execution" in findings_register["counts"]["bounded_disposition_sum_basis"]
assert "initial Monitoring 49/392 isolated and post-merge runs later adjudicated NO-GO" in findings_register["counts"]["bounded_disposition_sum_basis"]
assert findings_register["counts"]["med_rbac_bounded_tests"] == 73
assert findings_register["counts"]["med_rbac_bounded_test_assertions"] == 1481
assert findings_register["counts"]["med_cd_scope_focused_tests"] == 5
assert findings_register["counts"]["med_cd_scope_focused_test_assertions"] == 48
assert findings_register["counts"]["med_cd_atomicity_claim_specific_test_functions"] == 3
assert findings_register["counts"]["med_cd_atomicity_claim_specific_assertions"] == 146
assert findings_register["counts"]["med_cd_atomicity_race_subscenarios"] == 3
assert findings_register["counts"]["med_cd_atomicity_supporting_tests"] == 43
assert findings_register["counts"]["med_cd_atomicity_supporting_assertions"] == 716
assert findings_register["counts"]["safe_alert_dedup_focused_tests"] == 5
assert findings_register["counts"]["safe_alert_dedup_focused_assertions"] == 60
assert findings_register["counts"]["safe_alert_dedup_supporting_control_room_bridge_tests"] == 28
assert findings_register["counts"]["safe_alert_dedup_supporting_control_room_bridge_assertions"] == 73
assert findings_register["counts"]["safe_alert_dedup_supporting_hs_event_tests"] == 3
assert findings_register["counts"]["safe_alert_dedup_supporting_hs_event_assertions"] == 5
assert findings_register["counts"]["safe_alert_dedup_terminal_fixture_failures"] == 6
assert findings_register["counts"]["fleet_trip_index_site_privacy_focused_tests"] == 5
assert findings_register["counts"]["fleet_trip_index_site_privacy_focused_assertions"] == 175
assert findings_register["counts"]["fleet_trip_index_site_privacy_supporting_tests"] == 4
assert findings_register["counts"]["fleet_trip_index_site_privacy_supporting_assertions"] == 35
assert findings_register["counts"]["fleet_trip_playback_site_privacy_focused_tests"] == 11
assert findings_register["counts"]["fleet_trip_playback_site_privacy_focused_assertions"] == 167
assert findings_register["counts"]["fleet_trip_playback_site_privacy_supporting_tests"] == 20
assert findings_register["counts"]["fleet_trip_playback_site_privacy_supporting_assertions"] == 215
assert findings_register["counts"]["fleet_trip_playback_site_privacy_baseline_failed"] == 3
assert findings_register["counts"]["fleet_trip_playback_site_privacy_baseline_passed"] == 2
assert findings_register["counts"]["fleet_trip_playback_site_privacy_baseline_assertions"] == 30
assert findings_register["counts"]["monitoring_metric_replay_dedupe_focused_tests"] == 56
assert findings_register["counts"]["monitoring_metric_replay_dedupe_focused_assertions"] == 472
assert findings_register["counts"]["monitoring_metric_replay_initial_superseded_tests"] == 49
assert findings_register["counts"]["monitoring_metric_replay_initial_superseded_assertions"] == 392
assert findings_register["counts"]["monitoring_metric_replay_initial_post_merge_verdict"] == "NO_GO_ZERO_DENOMINATOR_CREDIT"
assert findings_register["counts"]["monitoring_metric_replay_option_a_deployment_verified"] is False
assert findings_register["counts"]["fleet_fuel_index_site_privacy_focused_tests"] == 6
assert findings_register["counts"]["fleet_fuel_index_site_privacy_focused_assertions"] == 206
assert findings_register["counts"]["fleet_fuel_index_site_privacy_supporting_tests"] == 20
assert findings_register["counts"]["fleet_fuel_index_site_privacy_supporting_assertions"] == 215
assert findings_register["counts"]["fleet_fuel_index_site_privacy_baseline_failed"] == 6
assert findings_register["counts"]["fleet_fuel_index_site_privacy_baseline_passed"] == 0
assert findings_register["counts"]["fleet_fuel_index_site_privacy_baseline_assertions"] == 65
assert "unique 6/206 post-merge focused component" in findings_register["counts"]["bounded_disposition_sum_basis"]
assert "any second count from combined 26/421" in findings_register["counts"]["bounded_disposition_sum_basis"]
assert findings_register["counts"]["summary_timeline_site_privacy_focused_tests"] == 15
assert findings_register["counts"]["summary_timeline_site_privacy_focused_assertions"] == 32
assert findings_register["counts"]["summary_timeline_site_privacy_supporting_tests"] == 2
assert findings_register["counts"]["summary_timeline_site_privacy_supporting_assertions"] == 238
assert findings_register["counts"]["summary_timeline_site_privacy_baseline_failed"] == 1
assert findings_register["counts"]["summary_timeline_site_privacy_baseline_passed"] == 5
assert findings_register["counts"]["summary_timeline_site_privacy_baseline_assertions"] == 9
assert findings_register["counts"]["summary_timeline_site_privacy_shared_post_merge_tests"] == 40
assert findings_register["counts"]["summary_timeline_site_privacy_shared_post_merge_assertions"] == 438
assert "unique isolated focused 15/32 SUMMARY-TIMELINE-SITE-PRIVACY execution" in findings_register["counts"]["bounded_disposition_sum_basis"]
assert findings_register["counts"]["shift_task_due_recipient_revalidation_focused_tests"] == 9
assert findings_register["counts"]["shift_task_due_recipient_revalidation_focused_assertions"] == 50
assert findings_register["counts"]["shift_task_due_recipient_revalidation_baseline_failed"] == 1
assert findings_register["counts"]["shift_task_due_recipient_revalidation_baseline_passed"] == 3
assert findings_register["counts"]["shift_task_due_recipient_revalidation_baseline_pending"] == 1
assert findings_register["counts"]["shift_task_due_recipient_revalidation_baseline_assertions"] == 14
assert findings_register["counts"]["shift_task_due_recipient_revalidation_intermediate_tests"] == 5
assert findings_register["counts"]["shift_task_due_recipient_revalidation_intermediate_assertions"] == 30
assert findings_register["counts"]["shift_task_due_recipient_revalidation_isolated_replay_tests"] == 9
assert findings_register["counts"]["shift_task_due_recipient_revalidation_isolated_replay_assertions"] == 50
assert "one post-merge 9/50 SHIFT-TASK-DUE-RECIPIENT-REVALIDATION execution" in findings_register["counts"]["bounded_disposition_sum_basis"]
assert "Only the unique post-merge focused 9/50 execution counts once" in findings_register["counts"]["shift_task_due_recipient_revalidation_aggregation_basis"]
assert findings_register["counts"]["elig_shift_notification_site_privacy_focused_tests"] == 13
assert findings_register["counts"]["elig_shift_notification_site_privacy_focused_assertions"] == 25
assert findings_register["counts"]["elig_shift_notification_site_privacy_baseline_failed"] == 1
assert findings_register["counts"]["elig_shift_notification_site_privacy_baseline_passed"] == 0
assert findings_register["counts"]["elig_shift_notification_site_privacy_baseline_pending"] == 4
assert findings_register["counts"]["elig_shift_notification_site_privacy_baseline_assertions"] == 1
assert findings_register["counts"]["elig_shift_notification_site_privacy_intermediate_no_go_tests"] == 12
assert findings_register["counts"]["elig_shift_notification_site_privacy_intermediate_no_go_assertions"] == 23
assert findings_register["counts"]["elig_shift_notification_site_privacy_isolated_replay_tests"] == 13
assert findings_register["counts"]["elig_shift_notification_site_privacy_isolated_replay_assertions"] == 25
assert "one post-merge 13/25 ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY execution" in findings_register["counts"]["bounded_disposition_sum_basis"]
assert "Only the unique post-merge focused 13/25 execution counts once" in findings_register["counts"]["elig_shift_notification_site_privacy_aggregation_basis"]
assert findings_register["counts"]["fleet_trip_playback_data_point_eligibility_credited_tests"] == 1
assert findings_register["counts"]["fleet_trip_playback_data_point_eligibility_credited_assertions"] == 6
assert findings_register["counts"]["fleet_trip_playback_data_point_eligibility_baseline_failed"] == 1
assert findings_register["counts"]["fleet_trip_playback_data_point_eligibility_baseline_assertions"] == 3
assert findings_register["counts"]["fleet_trip_playback_data_point_eligibility_isolated_focused_tests"] == 1
assert findings_register["counts"]["fleet_trip_playback_data_point_eligibility_isolated_focused_assertions"] == 6
assert findings_register["counts"]["fleet_trip_playback_data_point_eligibility_isolated_combined_tests"] == 27
assert findings_register["counts"]["fleet_trip_playback_data_point_eligibility_isolated_combined_assertions"] == 213
assert findings_register["counts"]["fleet_trip_playback_data_point_eligibility_post_merge_combined_tests"] == 27
assert findings_register["counts"]["fleet_trip_playback_data_point_eligibility_post_merge_combined_assertions"] == 213
assert "only the new post-merge 1/6 FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY component" in findings_register["counts"]["bounded_disposition_sum_basis"]
assert "Only the new post-merge regression component 1/6 counts once" in findings_register["counts"]["fleet_trip_playback_data_point_eligibility_aggregation_basis"]
assert findings_register["counts"]["final_P0"] == findings_register["counts"]["final_P1"] == 0
assert findings_register["denominators"]["current_retained_claim_records"] == 20
assert findings_register["denominators"]["historical_remediated_records"] == 10
assert findings_register["reconciliation"]["retained_record_count"] == 20
assert findings_register["reconciliation"]["current_provisional_count"] == 8
assert findings_register["reconciliation"]["historical_already_fixed_count"] == 2
assert findings_register["reconciliation"]["historical_remediated_count"] == 10
assert findings_register["reconciliation"]["every_non_null_primary_feature_id_in_canonical_matrix"] is True
assert findings_register["reconciliation"]["records_without_primary_or_candidate_feature_id"] == [
    "MON-METRIC-REPLAY-DEDUPE-01",
    "SUMMARY-TIMELINE-SITE-PRIVACY-01",
    "SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01",
    "ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01",
]

assert run_163_reporting["schema_version"] == "run-163-med-cd-scope-remediation-reporting-wave-29-v1"
assert run_163_reporting["run_id"] == "RUN-163-MED-CD-SCOPE-01-REMEDIATION-REPORTING-WAVE-29"
assert run_163_reporting["status"] == "MED_CD_SCOPE_HISTORICAL_REMEDIATION_REPORTING_MATERIALIZED_DASHBOARD_RUN164_REQUIRED_ZERO_FINAL_FINDING_OR_COMPLETION_CREDIT"
assert run_163_reporting["pins"]["reporting_input_commit"] == "adc80d437781bc5f2f4a3f072e86b51fb10a1c7d"
assert run_163_reporting["pins"]["reporting_input_tree"] == "8519736eab3386008563bd5fc7786941ab2d21f2"
assert run_163_reporting["pins"]["application_commit"] == run_162_pins["application_commit"]
assert run_163_reporting["pins"]["application_tree"] == run_162_pins["repository_tree_at_application_commit"]
assert run_163_reporting["pins"]["reporting_materializer"]["sha256"] == sha256_file("generators/materialize-run-163-med-cd-scope-remediation-reporting-wave-29.py")
run_163_builder_pin = run_163_reporting["pins"]["dashboard_generator"]
run_163_builder_bytes = git_file_at_commit(
    "1cdec6bd48b096c0569f0e85d8e0e8f444b61062",
    run_163_builder_pin["path"],
)
assert hashlib.sha256(run_163_builder_bytes).hexdigest() == run_163_builder_pin["sha256"]
assert git_blob_id_bytes(run_163_builder_bytes) == run_163_builder_pin["git_blob_id"]
assert len(run_163_builder_bytes) == run_163_builder_pin["bytes"]
assert run_163_builder_bytes.count(b"\n") == run_163_builder_pin["lines"]
assert run_163_reporting["pins"]["unchanged_run_161_dashboard"]["sha256"] == dashboard_run_161["pins"]["dashboard_html"]["sha256"]
run_163_transition = run_163_reporting["reporting_transition"]
assert run_163_transition["finding_id"] == "MED-CD-SCOPE-01"
assert run_163_transition["authorized_by_run_162r"] is True
assert run_163_transition["status_before"] == "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING"
assert run_163_transition["status_after"] == "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
assert run_163_transition["counts_before"] == {
    "retained_claim_records": 12,
    "provisional_source_claims": 11,
    "historical_already_fixed": 1,
    "historical_remediated": 0,
    "final_P0": 0,
    "final_P1": 0,
}
assert run_163_transition["counts_after"] == {
    "retained_claim_records": 12,
    "provisional_source_claims": 10,
    "historical_already_fixed": 1,
    "historical_remediated": 1,
    "final_P0": 0,
    "final_P1": 0,
}
assert run_163_reporting["dashboard_forward_gate"]["required_run"] == "RUN-164"
assert run_163_reporting["dashboard_forward_gate"]["dashboard_html_changed_by_run_163"] is False
assert run_163_reporting["dashboard_forward_gate"]["fresh_four_viewport_verification_required"] is True
assert {key for key, value in run_163_reporting["credit_boundary"].items() if value} == {"live_findings_register_and_reporting_status"}
run_163_payload_without_seal = dict(run_163_reporting)
run_163_seal = run_163_payload_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_163_payload_without_seal) == run_163_seal
assert all(value is False for value in run_163_reporting["completion_boundary"].values())

assert run_167_reporting["schema_version"] == "run-167-med-cd-atomicity-reporting-wave-30-v1"
assert run_167_reporting["run_id"] == "RUN-167-MED-CD-ATOMICITY-01-ALREADY-FIXED-REPORTING-WAVE-30"
assert run_167_reporting["status"] == "MED_CD_ATOMICITY_HISTORICAL_ALREADY_FIXED_REPORTING_MATERIALIZED_DASHBOARD_RUN168_REQUIRED_ZERO_FINAL_FINDING_OR_COMPLETION_CREDIT"
assert run_167_reporting["pins"]["reporting_input_commit"] == "47242053b960ae4af6c669ad24fa013497df0ae8"
assert run_167_reporting["pins"]["reporting_input_tree"] == "65fe18971ff4e5b0475186b99001d96a22ae178b"
assert run_167_reporting["pins"]["reporting_input_parent"] == "bbd9b05b03da6d98deed033471412a05cc31d6d7"
assert run_167_reporting["pins"]["reviewed_source_commit"] == run_165_source_review["pins"]["reviewed_source_checkpoint"]
assert run_167_reporting["pins"]["reviewed_source_tree"] == run_165_source_review["pins"]["reviewed_source_tree"]
run_167_materializer_pin = run_167_reporting["pins"]["reporting_materializer"]
assert run_167_materializer_pin["path"] == "generators/materialize-run-167-med-cd-atomicity-reporting-wave-30.py"
run_167_materializer_payload = git_file_at_commit(RUN_167_REPORTING_COMMIT, run_167_materializer_pin["path"])
assert run_167_materializer_pin["sha256"] == hashlib.sha256(run_167_materializer_payload).hexdigest()
assert run_167_materializer_pin["git_blob_id"] == git_blob_id_bytes(run_167_materializer_payload)
assert (run_167_materializer_pin["bytes"], run_167_materializer_pin["lines"]) == (len(run_167_materializer_payload), run_167_materializer_payload.count(b"\n"))
run_167_builder_pin = run_167_reporting["pins"]["dashboard_generator"]
assert run_167_builder_pin["path"] == "generators/build-current-audit-dashboard.py"
run_167_builder_payload = git_file_at_commit(RUN_167_REPORTING_COMMIT, run_167_builder_pin["path"])
assert run_167_builder_pin["sha256"] == hashlib.sha256(run_167_builder_payload).hexdigest()
assert run_167_builder_pin["git_blob_id"] == git_blob_id_bytes(run_167_builder_payload)
assert (run_167_builder_pin["bytes"], run_167_builder_pin["lines"]) == (len(run_167_builder_payload), run_167_builder_payload.count(b"\n"))
run_167_dashboard_pin = run_167_reporting["pins"]["unchanged_run_164_dashboard"]
assert run_167_dashboard_pin["path"] == "audit-dashboard.html"
assert run_167_dashboard_pin["sha256"] == "04fe2430810557f4fe61630f877efc7f827f6bcb1e265ac470ffd2bf277bcbbd"
run_167_dashboard_payload = git_file_at_commit(RUN_167_REPORTING_COMMIT, run_167_dashboard_pin["path"])
assert run_167_dashboard_pin["sha256"] == hashlib.sha256(run_167_dashboard_payload).hexdigest()
assert run_167_dashboard_pin["git_blob_id"] == git_blob_id_bytes(run_167_dashboard_payload)
assert (run_167_dashboard_pin["bytes"], run_167_dashboard_pin["lines"]) == (len(run_167_dashboard_payload), run_167_dashboard_payload.count(b"\n"))
run_167_transition = run_167_reporting["reporting_transition"]
assert run_167_transition["finding_id"] == "MED-CD-ATOMICITY-01"
assert run_167_transition["authorized_by_run_166r"] is True
assert run_167_transition["authorized_scope"] == "manual POST /emar/controlled/entries register and stock atomicity only"
assert run_167_transition["status_before"] == "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING"
assert run_167_transition["status_after"] == "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING"
assert run_167_transition["counts_before"] == {
    "retained_claim_records": 12,
    "provisional_source_claims": 10,
    "historical_already_fixed": 1,
    "historical_remediated": 1,
    "final_P0": 0,
    "final_P1": 0,
}
assert run_167_transition["counts_after"] == {
    "retained_claim_records": 12,
    "provisional_source_claims": 9,
    "historical_already_fixed": 2,
    "historical_remediated": 1,
    "final_P0": 0,
    "final_P1": 0,
}
assert run_167_transition["claim_specific_runtime_denominator"] == {
    "test_functions": 3,
    "assertions": 146,
    "race_subscenarios": 3,
    "supporting_tests_not_aggregated": 43,
    "supporting_assertions_not_aggregated": 716,
    "added_to_existing_78_test_1529_assertion_total": False,
}
assert run_167_reporting["dashboard_forward_gate"]["required_run"] == "RUN-168"
assert run_167_reporting["dashboard_forward_gate"]["dashboard_html_changed_by_run_167"] is False
assert run_167_reporting["dashboard_forward_gate"]["fresh_four_viewport_verification_required"] is True
assert {key for key, value in run_167_reporting["credit_boundary"].items() if value} == {"live_findings_register_and_reporting_status"}
run_167_payload_without_seal = dict(run_167_reporting)
run_167_seal = run_167_payload_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_167_payload_without_seal) == run_167_seal
assert all(value is False for value in run_167_reporting["completion_boundary"].values())

assert run_171_reporting["schema_version"] == "run-171-reviewed-fleet-vehicle-alerts-config-route-action-reporting-wave-31-v1"
assert run_171_reporting["run_id"] == "RUN-171-REVIEWED-FLEET-VEHICLE-ALERTS-CONFIG-ROUTE-ACTION-REPORTING-WAVE-31"
assert run_171_reporting["status"] == "FLEET_VEHICLE_ALERTS_CONFIG_REVIEWED_OWNERSHIP_REPORTING_MATERIALIZED_DASHBOARD_RUN172_REQUIRED_ZERO_CORRECTNESS_OR_COMPLETION_CREDIT"
assert run_171_reporting["pins"]["reporting_input_commit"] == "ca1c53bc3062a6fe81f2855716de13636d59ac0c"
assert run_171_reporting["pins"]["reporting_input_tree"] == "f29a9fce70f5c6ed9b251560fda58be976b062df"
assert run_171_reporting["pins"]["reporting_input_parent"] == "2084ca83fe8d18f145197867d3bbf73b731800c7"
assert run_171_reporting["pins"]["application_commit"] == "e488bd3edcda0f154f87e8bbed972f14db409b82"
assert run_171_reporting["pins"]["application_tree"] == "9e93b8aea4f4b907cde3dc59dd0520fba5bd7080"
assert run_171_reporting["reporting_snapshot"]["combined_counts"] == {
    "source_owner_records": 665,
    "route_owner_records": 308,
    "page_owner_records": 357,
    "distinct_feature_ids": 256,
    "distinct_H_feature_ids": 234,
    "distinct_D_feature_ids": 22,
    "route_distinct_feature_ids": 64,
    "page_distinct_feature_ids": 242,
    "route_page_feature_overlap": 50,
    "static_controller_action_bridges": 96,
    "bounded_static_source_denominator": 3929,
    "bounded_static_source_ownership_percent": "16.925426",
    "bounded_static_source_residual_records": 3264,
    "residual_explicit_unmapped_routes": 2893,
}
assert run_171_reporting["reporting_snapshot"]["queue_accounting"] == {
    "direct_exact_queue_records": 507,
    "reviewed_queue_surface_rows": 119,
    "owner_queue_surface_rows": 97,
    "shared_queue_surface_rows": 10,
    "alias_queue_surface_rows": 5,
    "dead_queue_surface_rows": 0,
    "evidence_gap_queue_surface_rows": 7,
    "pending_unreviewed_queue_surface_rows": 388,
    "queue_surfaces_without_ownership": 410,
}
assert run_171_reporting["queue_boundary"]["selected_index_83_integrated"] is True
assert run_171_reporting["queue_boundary"]["next_unresolved_index"] == 84
assert run_171_reporting["queue_boundary"]["next_unresolved_queue_id"] == "RUN090-ROUTE-0085"
assert run_171_reporting["queue_boundary"]["next_unresolved_route_record_id"] == "RUN077-ROUTE-0693"
assert run_171_reporting["queue_boundary"]["next_unresolved_route_name"] == "fleet-assets.trips.index"
assert run_171_reporting["queue_boundary"]["next_unresolved_action_expression"] == "[VehicleController::class, 'trips']"
assert run_171_reporting["provisional_source_observations"]["count"] == 3
assert run_171_reporting["findings_boundary"] == {
    "retained_claim_records": 12,
    "current_provisional": 9,
    "historical_already_fixed": 2,
    "historical_remediated": 1,
    "final_P0": 0,
    "final_P1": 0,
}
assert run_171_reporting["benchmark_boundary"] == {
    "mapped": 2,
    "final_no_match_or_NCM": 0,
    "unresolved": 338,
}
assert run_171_reporting["dashboard_forward_gate"]["required_run"] == "RUN-172"
assert run_171_reporting["dashboard_forward_gate"]["dashboard_html_changed_by_run_171"] is False
assert run_171_reporting["dashboard_forward_gate"]["unchanged_dashboard_sha256"] == "80360ae152642e4f7c0c90b18c42e76fb156bf8cd34eb9df17b358170cc71b89"
assert {key for key, value in run_171_reporting["credit_boundary"].items() if value} == {
    "live_static_ownership_and_queue_reporting"
}
run_171_payload_without_seal = dict(run_171_reporting)
run_171_seal = run_171_payload_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_171_payload_without_seal) == run_171_seal
assert all(value is False for value in run_171_reporting["completion_boundary"].values())

assert dashboard_run_172["schema_version"] == "run-172-audit-dashboard-verification-wave-31-v1"
assert dashboard_run_172["run_id"] == "RUN-172-AUDIT-DASHBOARD-VERIFICATION-WAVE-31"
assert dashboard_run_172["pins"]["run_172_dashboard"]["sha256"] == "79bb5c671606ca6f596bba6d9a0649ceed9acc549ec57174c6a1102ea22d3f47"
assert dashboard_run_172["verification"]["viewports_verified"] == 4
assert dashboard_run_172["verification"]["navigation_clicks_passed"] == 10
assert dashboard_run_172["verification"]["visible_static_checks_passed"] == 55
assert dashboard_run_172["verification"]["unique_local_resources"] == 426
assert dashboard_run_172["reported_finding_boundary"]["current_provisional_source_claims"] == 9
assert dashboard_run_172["reported_finding_boundary"]["historical_already_fixed_records"] == 2
assert dashboard_run_172["reported_finding_boundary"]["historical_remediated_records"] == 1
assert dashboard_run_172["reported_finding_boundary"]["existing_bounded_disposition_denominator"] == "78 tests / 1,529 assertions"
dashboard_run_172_without_seal = dict(dashboard_run_172)
dashboard_run_172_seal = dashboard_run_172_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(dashboard_run_172_without_seal) == dashboard_run_172_seal
assert all(value is False for value in dashboard_run_172["completion_boundary"].values())
run_172_dashboard_pin = dashboard_run_172["pins"]["run_172_dashboard"]
run_172_dashboard_payload = git_file_at_commit(
    "c39b076547056b1e158c604957a04bd8b75b0f29",
    run_172_dashboard_pin["path"],
)
assert hashlib.sha256(run_172_dashboard_payload).hexdigest() == run_172_dashboard_pin["sha256"]
assert git_blob_id_bytes(run_172_dashboard_payload) == run_172_dashboard_pin["git_blob_id"]
assert (len(run_172_dashboard_payload), run_172_dashboard_payload.count(b"\n")) == (
    run_172_dashboard_pin["bytes"],
    run_172_dashboard_pin["lines"],
)

assert sha256_file("evidence/runtime/current-run-173-safe-alert-dedup-identity-remediation-wave-32.json") == "49a4fa5ad4fefa1c72e449b69150fe05de06e8f9d0055b47e93a0a3061b66e45"
assert sha256_file("evidence/runtime/current-run-173r-independent-safe-alert-dedup-identity-remediation-review-wave-32.json") == "9a19e5ccb15d955db8bf1bcd80b40a6f89306bc9945625d275f3d6f4c543e652"
assert run_173_remediation["pins"]["local_main_merge_commit"] == "705db2dc3ba05a8fdf647cd28bdc9c226a694068"
assert run_173_remediation["pins"]["origin_main_observed"] == "c39b076547056b1e158c604957a04bd8b75b0f29"
assert run_173_remediation["pins"]["application_remote_publication_observed"] is False
assert run_173_remediation["pins"]["publication_authorized"] is False
assert run_173_remediation["delegated_runtime_execution"]["post_merge_green_focused"]["tests"] == 5
assert run_173_remediation["delegated_runtime_execution"]["post_merge_green_focused"]["assertions"] == 60
assert run_173_remediation["delegated_runtime_execution"]["focused_replay_aggregated_more_than_once"] is False
assert run_173_remediation["delegated_runtime_execution"]["supporting_control_room_bridge_suite"]["added_to_bounded_disposition_denominator"] is False
assert run_173_remediation["delegated_runtime_execution"]["adjacent_hs_event_safeguarding_filter"]["added_to_bounded_disposition_denominator"] is False
assert run_173_remediation["delegated_runtime_execution"]["terminal_transition_fixture_debt"]["failures"] == 6
assert run_173_remediation["delegated_runtime_execution"]["terminal_transition_fixture_debt"]["safe_remediation_credit"] is False
run_173_without_seal = dict(run_173_remediation)
run_173_seal = run_173_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_173_without_seal) == run_173_seal
assert run_173r_review["decision"]["verdict"] == "GO"
assert run_173r_review["decision"]["blocking_discrepancies"] == 0
assert run_173r_review["decision"]["retirement_reporting_authorized"] is True
assert run_173r_review["decision"]["authorized_resulting_lineage"] == {
    "retained_claim_records": 12,
    "current_provisional_source_claims": 8,
    "historical_already_fixed_records": 2,
    "historical_remediated_records": 2,
    "final_P0": 0,
    "final_P1": 0,
}
assert run_173r_review["decision"]["authorized_unique_bounded_disposition_increment"] == {
    "tests": 5,
    "assertions": 60,
    "resulting_tests": 83,
    "resulting_assertions": 1589,
    "isolated_replay_counted_again": False,
    "supporting_or_adjacent_runs_counted": False,
    "red_or_terminal_failures_counted": False,
}
run_173r_without_seal = dict(run_173r_review)
run_173r_seal = run_173r_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_173r_without_seal) == run_173r_seal

assert run_174_reporting["schema_version"] == "run-174-safe-alert-dedup-identity-remediation-reporting-wave-32-v1"
assert run_174_reporting["run_id"] == "RUN-174-SAFE-ALERT-DEDUP-IDENTITY-01-REMEDIATION-REPORTING-WAVE-32"
assert run_174_reporting["reporting_transition"]["finding_id"] == "SAFE-ALERT-DEDUP-IDENTITY-01"
assert run_174_reporting["reporting_transition"]["authorized_by_run_173r"] is True
assert run_174_reporting["reporting_transition"]["status_after"] == "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
assert run_174_reporting["reporting_transition"]["counts_after"] == {
    "retained_claim_records": 12,
    "provisional_source_claims": 8,
    "historical_already_fixed": 2,
    "historical_remediated": 2,
    "final_P0": 0,
    "final_P1": 0,
}
assert run_174_reporting["bounded_execution_accounting"]["unique_total"] == {
    "tests": 83,
    "assertions": 1589,
}
assert run_174_reporting["publication_boundary"]["origin_main"] == "c39b076547056b1e158c604957a04bd8b75b0f29"
assert run_174_reporting["publication_boundary"]["safe_application_published"] is False
assert run_174_reporting["publication_boundary"]["run_173_to_174_published"] is False
assert run_174_reporting["dashboard_forward_gate"]["required_run"] == "RUN-175"
assert run_174_reporting["dashboard_forward_gate"]["dashboard_html_changed_by_run_174"] is False
assert run_174_reporting["dashboard_forward_gate"]["unchanged_dashboard_sha256"] == "79bb5c671606ca6f596bba6d9a0649ceed9acc549ec57174c6a1102ea22d3f47"
assert {key for key, value in run_174_reporting["credit_boundary"].items() if value} == {
    "live_findings_register_and_reporting_status"
}
run_174_without_seal = dict(run_174_reporting)
run_174_seal = run_174_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_174_without_seal) == run_174_seal
assert all(value is False for value in run_174_reporting["completion_boundary"].values())

assert sha256_file(
    "evidence/browser/current-audit-dashboard-verification-run-175-wave-32.json"
) == "6ef4d3f7e1018c0e137ee485007847b1149bff9f5cf94feefebaf3eeebc09de6"
assert dashboard_run_175["schema_version"] == "run-175-audit-dashboard-verification-wave-32-v1"
assert dashboard_run_175["run_id"] == "RUN-175-AUDIT-DASHBOARD-VERIFICATION-WAVE-32"
assert dashboard_run_175["verification"]["viewports_verified"] == 4
assert dashboard_run_175["verification"]["visible_static_checks_passed"] == 79
assert dashboard_run_175["verification"]["navigation_clicks_passed"] == 10
assert dashboard_run_175["verification"]["unique_local_resources"] == 435
run_175_dashboard_pin = dashboard_run_175["pins"]["run_175_dashboard"]
assert run_175_dashboard_pin == {
    "path": "audit-dashboard.html",
    "sha256": "8586a2cb3cc6c248788ea71ecc20c2e0c4785fd5a7a5a00fa11d2ee48f48490c",
    "git_blob_id": "1c1c521b674bcc12b5227aff1418a49ba0ace06a",
    "bytes": 280930,
    "lines": 78,
}
run_175_dashboard_payload = git_file_at_commit(
    "13a7f37da9c966fa531f20e82b1bb9eac814e041",
    run_175_dashboard_pin["path"],
)
assert hashlib.sha256(run_175_dashboard_payload).hexdigest() == run_175_dashboard_pin["sha256"]
assert git_blob_id_bytes(run_175_dashboard_payload) == run_175_dashboard_pin["git_blob_id"]
assert (len(run_175_dashboard_payload), run_175_dashboard_payload.count(b"\n")) == (
    run_175_dashboard_pin["bytes"],
    run_175_dashboard_pin["lines"],
)
dashboard_run_175_without_seal = dict(dashboard_run_175)
dashboard_run_175_seal = dashboard_run_175_without_seal.pop("receipt_self_seal_sha256")
assert dashboard_run_175_seal == "8ae9223300ef5851bd432da467a5ae7dba2e0460ff69291188039da0dfad7ae4"
assert canonical_sha256(dashboard_run_175_without_seal) == dashboard_run_175_seal
assert {
    key for key, value in dashboard_run_175["credit_boundary"].items() if value
} == {
    "audit_dashboard_run_175_med_pin_correction",
    "exact_audit_dashboard_artifact",
}
assert dashboard_run_175["artifact_completion_test_met"] is True
assert dashboard_run_175["audit_completion_test_met"] is False
assert all(value is False for value in dashboard_run_175["completion_boundary"].values())

assert sha256_file(
    "evidence/runtime/current-run-176-fleet-trip-index-site-privacy-remediation-wave-33.json"
) == "6e9fa6d855e6ec168d4c651921702dab8872810ddd89f6ba3cd353bf49e0c87c"
assert run_176_remediation["schema_version"] == "run-176-fleet-trip-index-site-privacy-remediation-wave-33-v1"
assert run_176_remediation["run_id"] == "RUN-176-FLEET-TRIP-INDEX-SITE-PRIVACY-01-REMEDIATION-WAVE-33"
assert run_176_remediation["pins"]["application_baseline_commit"] == "13a7f37da9c966fa531f20e82b1bb9eac814e041"
assert run_176_remediation["pins"]["fix_commit"] == "790bc11e3fb2b17a0eb8ba96e2cdea87ba8175b5"
assert run_176_remediation["pins"]["local_main_merge_commit"] == "c643c9e5eecf3b4272f55ec6d5aab4b99c3e300d"
assert run_176_remediation["pins"]["origin_main_observed"] == "c39b076547056b1e158c604957a04bd8b75b0f29"
assert run_176_remediation["pins"]["application_remote_publication_observed"] is False
assert run_176_remediation["delegated_runtime_execution"]["post_merge_green_focused"] == {
    "tests": 5,
    "assertions": 175,
    "duration_seconds": 170.48,
    "unique_bounded_disposition_denominator_credit": True,
}
assert run_176_remediation["delegated_runtime_execution"]["unique_bounded_accounting"]["resulting"] == {
    "tests": 88,
    "assertions": 1764,
}
assert run_176_remediation["static_ownership_boundary"]["ownership_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
assert run_176_remediation["static_ownership_boundary"]["next_zero_based_index"] == 84
assert {
    key: run_176_remediation["static_ownership_boundary"][key]
    for key in (
        "owner_records",
        "route_owners",
        "page_owners",
        "action_bridges",
        "queue_reviewed",
        "queue_pending",
        "queue_owned",
        "queue_without_ownership",
        "next_queue_id",
        "next_route_record_id",
    )
} == {
    "owner_records": 665,
    "route_owners": 308,
    "page_owners": 357,
    "action_bridges": 96,
    "queue_reviewed": 119,
    "queue_pending": 388,
    "queue_owned": 97,
    "queue_without_ownership": 410,
    "next_queue_id": "RUN090-ROUTE-0085",
    "next_route_record_id": "RUN077-ROUTE-0693",
}
assert run_176_remediation["benchmark_boundary"] == {
    "mapped": 2,
    "total": 340,
    "final_no_match_or_NCM": 0,
    "unresolved": 338,
    "changed_by_run_176": False,
}
assert {
    key for key, value in run_176_remediation["credit_boundary"].items() if value
} == {
    "historical_condition_confirmed",
    "current_defect_reproduced",
    "application_remediation",
    "bounded_runtime",
    "bounded_selected_get_and_csv_execution",
    "bounded_site_privacy_correctness",
    "application_commit_integrated_local_main",
}
run_176_without_seal = dict(run_176_remediation)
run_176_seal = run_176_without_seal.pop("receipt_self_seal_sha256")
assert run_176_seal == "541e2cc0c0a167b48cfac6e96ab2286d9898cb737dec2eb115b41d56e74b9617"
assert canonical_sha256(run_176_without_seal) == run_176_seal
assert run_176_remediation["artifact_completion_test_met"] is True
assert run_176_remediation["audit_completion_test_met"] is False
assert all(value is False for value in run_176_remediation["completion_boundary"].values())

assert sha256_file(
    "evidence/runtime/current-run-176r-independent-fleet-trip-index-site-privacy-remediation-review-wave-33.json"
) == "f1f7369306235ad7d5f318b512dca94e853d96e182ff5c63ddc509534fa545c1"
assert run_176r_review["schema_version"] == "run-176r-independent-fleet-trip-index-site-privacy-remediation-review-wave-33-v1"
assert run_176r_review["run_id"] == "RUN-176R-INDEPENDENT-FLEET-TRIP-INDEX-SITE-PRIVACY-01-REMEDIATION-REVIEW-WAVE-33"
assert run_176r_review["decision"]["verdict"] == "GO"
assert run_176r_review["decision"]["blocking_discrepancies"] == 0
assert run_176r_review["decision"]["new_historical_remediated_record_reporting_authorized"] is True
assert run_176r_review["decision"]["authorized_live_reporting_run"] == "RUN-177"
assert run_176r_review["decision"]["authorized_resulting_lineage"] == {
    "retained_claim_records": 13,
    "current_provisional_source_claims": 8,
    "historical_already_fixed_records": 2,
    "historical_remediated_records": 3,
    "final_P0": 0,
    "final_P1": 0,
}
assert run_176r_review["decision"]["authorized_unique_bounded_disposition_increment"] == {
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
assert run_176r_review["decision"]["static_ownership_remains_pending"] == {
    "ownership_status": "PENDING_FRESH_SEMANTIC_REVIEW",
    "next_zero_based_index": 84,
    "next_queue_id": "RUN090-ROUTE-0085",
    "next_route_record_id": "RUN077-ROUTE-0693",
    "route_owner_authorized": False,
    "controller_action_bridge_authorized": False,
    "queue_advance_authorized": False,
}
assert {
    key for key, value in run_176r_review["credit_boundary"].items() if value
} == {
    "independent_exact_artifact_review_for_new_historical_remediated_reporting"
}
run_176r_without_seal = dict(run_176r_review)
run_176r_seal = run_176r_without_seal.pop("receipt_self_seal_sha256")
assert run_176r_seal == "a596b81ded6db13d312bdcbf52deb7a3e088f8404d81c774b3f5910c86140f49"
assert canonical_sha256(run_176r_without_seal) == run_176r_seal
assert run_176r_review["artifact_completion_test_met"] is True
assert run_176r_review["audit_completion_test_met"] is False
assert all(value is False for value in run_176r_review["completion_boundary"].values())

assert run_177_reporting["schema_version"] == "run-177-fleet-trip-index-site-privacy-remediation-reporting-wave-33-v1"
assert run_177_reporting["run_id"] == "RUN-177-FLEET-TRIP-INDEX-SITE-PRIVACY-01-REMEDIATION-REPORTING-WAVE-33"
assert run_177_reporting["reporting_transition"]["finding_id"] == "FLEET-TRIP-INDEX-SITE-PRIVACY-01"
assert run_177_reporting["reporting_transition"]["feature_identity_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
assert run_177_reporting["reporting_transition"]["candidate_feature_id"] == "CAP-FLEET-VEHICLE-REGISTER"
assert run_177_reporting["reporting_transition"]["authorized_by_run_176r"] is True
assert run_177_reporting["reporting_transition"]["transition_kind"] == "NEW_HISTORICAL_REMEDIATED_RECORD"
assert run_177_reporting["reporting_transition"]["status_before"] == "ABSENT"
assert run_177_reporting["reporting_transition"]["status_after"] == "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
assert run_177_reporting["reporting_transition"]["counts_after"] == {
    "retained_claim_records": 13,
    "provisional_source_claims": 8,
    "historical_already_fixed": 2,
    "historical_remediated": 3,
    "final_P0": 0,
    "final_P1": 0,
}
assert run_177_reporting["reporting_transition"]["new_target_record_canonical_sha256"] == "98c82f01cf8348fc4b60a4c17feea675182dc287e4c7907174b13d44af331fab"
assert run_177_reporting["reporting_transition"]["counts_before"] == {
    "retained_claim_records": 12,
    "provisional_source_claims": 8,
    "historical_already_fixed": 2,
    "historical_remediated": 2,
    "final_P0": 0,
    "final_P1": 0,
}
assert run_177_reporting["reporting_transition"]["unchanged_preexisting_record_count"] == 12
assert run_177_reporting["bounded_execution_accounting"]["unique_total"] == {
    "tests": 88,
    "assertions": 1764,
}
assert run_177_reporting["preservation_boundary"]["dashboard_byte_identical_to_reporting_input"] is True
assert run_177_reporting["preservation_boundary"]["dashboard_sha256"] == run_175_dashboard_pin["sha256"]
assert run_177_reporting["pins"]["unchanged_run_175_dashboard"] == run_175_dashboard_pin
assert run_177_reporting["preservation_boundary"]["static_ownership"] == {
    "owners": 665,
    "routes": 308,
    "pages": 357,
    "controller_action_bridges": 96,
    "ownership_status": "PENDING_FRESH_SEMANTIC_REVIEW",
}
assert run_177_reporting["preservation_boundary"]["queue"] == {
    "next_zero_based_index": 84,
    "next_queue_id": "RUN090-ROUTE-0085",
    "next_route_record_id": "RUN077-ROUTE-0693",
    "reviewed": 119,
    "pending": 388,
    "owned": 97,
    "without_ownership": 410,
    "advanced_by_run_177": False,
}
assert run_177_reporting["preservation_boundary"]["benchmark"] == {
    "mapped": 2,
    "total": 340,
    "final_no_match_or_NCM": 0,
    "unresolved": 338,
}
assert run_177_reporting["publication_boundary"]["fleet_application_published"] is False
assert run_177_reporting["publication_boundary"]["run_176_to_177_published"] is False
assert run_177_reporting["publication_boundary"]["publication_authorized"] is False
assert run_177_reporting["dashboard_forward_gate"]["required_run"] == "RUN-178"
assert run_177_reporting["dashboard_forward_gate"]["dashboard_html_changed_by_run_177"] is False
assert run_177_reporting["dashboard_forward_gate"]["unchanged_dashboard_sha256"] == run_175_dashboard_pin["sha256"]
assert run_177_reporting["dashboard_forward_gate"]["fresh_rebuild_required"] is True
assert run_177_reporting["dashboard_forward_gate"]["fresh_verification_required"] is True
assert run_177_reporting["dashboard_forward_gate"]["future_receipt_link_is_unhashed_to_avoid_cycle"] is True
assert {key for key, value in run_177_reporting["credit_boundary"].items() if value} == {
    "live_findings_register_and_reporting_status"
}
run_177_without_seal = dict(run_177_reporting)
run_177_seal = run_177_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_177_without_seal) == run_177_seal
assert run_177_reporting["artifact_completion_test_met"] is True
assert run_177_reporting["audit_completion_test_met"] is False
assert all(value is False for value in run_177_reporting["completion_boundary"].values())

assert sha256_file("generators/materialize-run-178-audit-dashboard-verification-wave-33.py") == "ffedf87ea3cae8b74cd280f676f3fb671e9a2885dad0a3ef8564d0ed21f8d53d"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-178-wave-33.json") == "9a41983d86fa3fbe054d1ddb848a2ab4027284aa78210b78937d9728f7fbdaf2"
assert dashboard_run_178["run_id"] == "RUN-178-AUDIT-DASHBOARD-VERIFICATION-WAVE-33"
assert dashboard_run_178["pins"]["run_178_dashboard"]["sha256"] == "70472c39504600f8c0b26b9ce05eb0f3e5903f1c6e9445163dba0581a2382600"
assert dashboard_run_178["verification"]["viewports_verified"] == 4
assert dashboard_run_178["verification"]["visible_static_checks_passed"] == 97
assert dashboard_run_178["verification"]["navigation_clicks_passed"] == 10
assert dashboard_run_178["verification"]["post_materialization_local_resources"] == "443/443"
assert {key for key, value in dashboard_run_178["credit_boundary"].items() if value} == {
    "exact_audit_dashboard_artifact",
}
run_178_dashboard_payload = git_file_at_commit(
    "0975bf1cd3355da1f30e84056ae53107bd9b5bfc",
    "audit-dashboard.html",
)
assert hashlib.sha256(run_178_dashboard_payload).hexdigest() == "70472c39504600f8c0b26b9ce05eb0f3e5903f1c6e9445163dba0581a2382600"
run_182_dashboard_payload = git_file_at_commit(
    "db4196ccb3a8d9f6bcb33fb40680527d09c02dac",
    "audit-dashboard.html",
)
assert hashlib.sha256(run_182_dashboard_payload).hexdigest() == "8779848cc1a95ef82f2c5eba1a542e5a4784559f75ef20e9eb06696abde56457"
run_185_dashboard_payload = git_file_at_commit(
    "badd86d566f3354e455b92f12ab683ce6d29c965",
    "audit-dashboard.html",
)
assert hashlib.sha256(run_185_dashboard_payload).hexdigest() == "3c339da7e05349a7bd5cbed9ec4986e3b4871eb04d497d26078e92268a67e5e7"
run_188_dashboard_payload = git_file_at_commit(
    "10943780e7abea7a9d3b155bcd20154daf9bcc2d",
    "audit-dashboard.html",
)
assert hashlib.sha256(run_188_dashboard_payload).hexdigest() == "3d65bd82b8bc0f650158c4587f9618a03079f75d51e83496dc7d71addf257d79"
run_192_dashboard_payload = git_file_at_commit(
    "c35fcbe0445b39ea51ade9e5861172f188ff822d",
    "audit-dashboard.html",
)
assert hashlib.sha256(run_192_dashboard_payload).hexdigest() == "8d19569e7bfb256edeecdc754e2bc47e2ddad3ecd8de099e3bb0dad9b50e313b"
run_195_dashboard_payload = git_file_at_commit(
    "4c47d2eeed0b1006c11166da8ab8b0747d7554b7",
    "audit-dashboard.html",
)
assert hashlib.sha256(run_195_dashboard_payload).hexdigest() == "9a87dc70a7b190347ca7029c12abf8e025e4c722a6256502ba8c1c9af542f3b9"
run_198_dashboard_payload = git_file_at_commit(
    "ca3425103d6d75dc728418464d03e7e72983925b",
    "audit-dashboard.html",
)
assert hashlib.sha256(run_198_dashboard_payload).hexdigest() == "4432da4fecc7c9afa0096b46c3568249fccdaa8f0b987bfef4bc1eb07e24bd3a"
run_200_dashboard_payload = git_file_at_commit(
    "9c01f5a4f57f96722015278d1df3c3bd111aa95c",
    "audit-dashboard.html",
)
assert hashlib.sha256(run_200_dashboard_payload).hexdigest() == "f643ca1ec1716cfb2b32864aba1a97e8d69c3e726453707a3ce71e76b3c43205"
run_202_dashboard_payload = git_file_at_commit(
    "b61a2abd48a3d80ef91f6edcdf51d3ad253715e6",
    "audit-dashboard.html",
)
assert hashlib.sha256(run_202_dashboard_payload).hexdigest() == "1876db1ff590c86fb30cefb74368b0241c72d9b75966fcbf1a36d6b1096b30e3"

assert sha256_file("generators/build-outcome-neutral-fleet-trip-index-route-action-cohort-wave-34.py") == "61c895a305f743f102765c9f86d38843c3ce61bcc1a8684a672aa2d7cd6ee157"
assert sha256_file("evidence/source/root-run-179-outcome-neutral-fleet-trip-index-route-action-cohort-wave-34.json") == "5505cf17bb68d3e534116ea9d33e501e0222714b6e3779d0ec6b70f819cc3b0a"
assert fleet_trip_index_cohort["run_id"] == "RUN-179-OUTCOME-NEUTRAL-FLEET-TRIP-INDEX-ROUTE-ACTION-COHORT-WAVE-34"
assert fleet_trip_index_cohort["selection_contract"]["selected_queue_indices_zero_based"] == [84]
assert fleet_trip_index_cohort["selection_contract"]["ownership_decisions_authored"] == 0

assert sha256_file("generators/materialize-independent-outcome-neutral-fleet-trip-index-route-action-review-wave-34.py") == "80cf0e6febabee80b1fa99f3f296cabade8959bd5a4fcd72983af19d335332cd"
assert sha256_file("evidence/source/raw-run-179r-independent-outcome-neutral-fleet-trip-index-route-action-review-wave-34.json") == "67c5b09cbb26c95042bd7ba487c2a2c92a75d14363952ca35e9b72ee55e36d62"
assert fleet_trip_index_review["run_id"] == "RUN-179R-INDEPENDENT-OUTCOME-NEUTRAL-FLEET-TRIP-INDEX-ROUTE-ACTION-REVIEW-WAVE-34"
assert fleet_trip_index_review["decision"] == "GO"
assert fleet_trip_index_review["action_decision"]["outcome"] == "OWNER_ROUTE_ACTION"
assert fleet_trip_index_review["review_chronology"][3]["reported_outcome"] == "OWNER_ROUTE_ACTION"
assert fleet_trip_index_review["review_chronology"][4]["reported_outcome"] == "EVIDENCE_GAP"
assert [row["outcome"] for row in fleet_trip_index_review["independent_semantic_tiebreak_reviews"]] == [
    "OWNER_ROUTE_ACTION",
    "OWNER_ROUTE_ACTION",
]
assert fleet_trip_index_review["excluded_material_boundary"]["feature_identity_imported"] is False
assert fleet_trip_index_review["excluded_material_boundary"]["benchmark_or_mapping_credit_imported"] is False
assert fleet_trip_index_review["excluded_material_boundary"]["semantic_vote_imported"] is False

assert sha256_file("generators/integrate-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-wave-34.py") == "cdbeeae65d0d5d928d6356de7c2433d437b6f2bae9fd80bb7a942b97d41f6594"
assert sha256_file("evidence/source/current-run-180-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-wave-34.json") == "49b0bd12abbd4dd2b9ce0dbe9b6fd60ab79eea92861f6339407fbd05f0b7c925"
assert reviewed_fleet_trip_index_overlay["run_id"] == "RUN-180-REVIEWED-OUTCOME-NEUTRAL-FLEET-TRIP-INDEX-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-34"
assert reviewed_fleet_trip_index_overlay["combined_counts"]["source_owner_records"] == 666
assert reviewed_fleet_trip_index_overlay["combined_counts"]["route_owner_records"] == 309
assert reviewed_fleet_trip_index_overlay["combined_counts"]["page_owner_records"] == 357
assert reviewed_fleet_trip_index_overlay["combined_counts"]["static_controller_action_bridges"] == 97
assert reviewed_fleet_trip_index_overlay["queue_accounting"]["reviewed_queue_surface_rows"] == 120
assert reviewed_fleet_trip_index_overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 387
assert reviewed_fleet_trip_index_overlay["queue_boundary"]["next_unresolved_index"] == 85
assert {key for key, value in reviewed_fleet_trip_index_overlay["credit_boundary"].items() if value} == {
    "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD",
    "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION",
}

assert sha256_file("generators/materialize-independent-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-review-wave-34.py") == "854a673d362f3c3cf70f53e1083ae9daf6d977f0411aa1444a6d8309e1a086bb"
assert sha256_file("evidence/source/current-run-180r-independent-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-review-wave-34.json") == "c6038caa557277124cb58056a2882ce41d1f2ee402f91effb0e6bfab6fe95d96"
assert reviewed_fleet_trip_index_overlay_review["run_id"] == "RUN-180R-INDEPENDENT-REVIEWED-OUTCOME-NEUTRAL-FLEET-TRIP-INDEX-ROUTE-ACTION-OWNERSHIP-OVERLAY-REVIEW-WAVE-34"
assert reviewed_fleet_trip_index_overlay_review["decision"]["independent_reviews"] == 3
assert reviewed_fleet_trip_index_overlay_review["decision"]["discrepancies"] == 0
assert reviewed_fleet_trip_index_overlay_review["synthesis_review"]["reporting_materialization_authorized"] is True
assert all(row["reporting_authorization_individually_granted"] is False for row in reviewed_fleet_trip_index_overlay_review["independent_review_records"])
assert {key for key, value in reviewed_fleet_trip_index_overlay_review["credit_boundary"].items() if value} == {
    "INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING",
}

assert run_181_reporting["run_id"] == "RUN-181-REVIEWED-FLEET-TRIP-INDEX-ROUTE-ACTION-REPORTING-WAVE-34"
assert run_181_reporting["reporting_snapshot"]["combined_counts"]["source_owner_records"] == 666
assert run_181_reporting["reporting_snapshot"]["queue_accounting"]["reviewed_queue_surface_rows"] == 120
assert run_181_reporting["queue_boundary"]["next_unresolved_index"] == 85
assert run_181_reporting["preservation_boundary"]["dashboard_sha256_unchanged"] == "70472c39504600f8c0b26b9ce05eb0f3e5903f1c6e9445163dba0581a2382600"
assert run_181_reporting["findings_boundary"] == {
    "retained_claim_records": 13,
    "current_provisional": 8,
    "historical_already_fixed": 2,
    "historical_remediated": 3,
    "final_P0": 0,
    "final_P1": 0,
}
assert {key for key, value in run_181_reporting["credit_boundary"].items() if value} == {
    "live_static_ownership_and_queue_reporting",
}
assert all(value is False for value in run_181_reporting["completion_boundary"].values())

assert dashboard_run_182["run_id"] == "RUN-182-AUDIT-DASHBOARD-VERIFICATION-WAVE-34"
assert dashboard_run_182["pins"]["run_182_dashboard"]["sha256"] == "8779848cc1a95ef82f2c5eba1a542e5a4784559f75ef20e9eb06696abde56457"
assert dashboard_run_182["verification"]["viewports_verified"] == 4
assert dashboard_run_182["verification"]["navigation_clicks_passed"] == 10
assert dashboard_run_182["verification"]["anchor_elements"] == 852
assert dashboard_run_182["verification"]["unique_local_resources"] == 455
assert dashboard_run_182["verification"]["visible_static_checks_required"] == 105
assert dashboard_run_182["verification"]["visible_static_checks_passed"] == 105
assert dashboard_run_182["artifact_completion_test_met"] is True
assert dashboard_run_182["audit_completion_test_met"] is False
assert sha256_file("generators/materialize-run-182-audit-dashboard-verification-wave-34.py") == "db48bbd257b34984e31c7a5bb01237bdc7d6036a474ab5e272fdddb643535d03"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-182-wave-34.json") == "d3dc3ef6e842f0b5df74b27948ac6ef8abfda205516f6ac9b6a5d9c9858cd81e"
dashboard_run_182_without_seal = dict(dashboard_run_182)
dashboard_run_182_seal = dashboard_run_182_without_seal.pop("receipt_self_seal_sha256")
assert dashboard_run_182_seal == "25b0fe690c4dc3d3bdb480d64915be1a3ac99ea5234daaa8f499e6a04cf4533d"
assert canonical_sha256(dashboard_run_182_without_seal) == dashboard_run_182_seal
assert {key for key, value in dashboard_run_182["credit_boundary"].items() if value} == {
    "exact_audit_dashboard_artifact",
}
assert all(value is False for value in dashboard_run_182["completion_boundary"].values())
run_182_history = findings_register["current_audit_artifact_verification_history"]["run_182"]
assert run_182_history["run_id"] == dashboard_run_182["run_id"]
assert run_182_history["receipt_sha256"] == "d3dc3ef6e842f0b5df74b27948ac6ef8abfda205516f6ac9b6a5d9c9858cd81e"
assert run_182_history["dashboard_sha256"] == "8779848cc1a95ef82f2c5eba1a542e5a4784559f75ef20e9eb06696abde56457"
assert run_182_history["viewports"] == "4/4"
assert run_182_history["navigation"] == "10/10"
assert run_182_history["unique_local_resources"] == "455/455"
assert run_182_history["visible_boundary_checks"] == "105/105"
assert run_182_history["anchor_elements"] == "852/852"
assert run_182_history["application_browser_credit"] is False
assert run_182_history["publication_credit"] is False
assert run_182_history["audit_complete"] is False
assert run_182_history["superseded_by_run_184_reporting_sources"] is True
assert run_182_history["run_185_dashboard_verification_required"] is True

assert run_183_remediation["run_id"] == "RUN-183-FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01-REMEDIATION-WAVE-35"
assert sha256_file("generators/materialize-run-183-fleet-trip-playback-site-privacy-remediation-wave-35.py") == "602964ec765cc9bd71d7b6fed103bdbd1b4b5543c0843f2c2dcdb2a960779f8e"
assert sha256_file("evidence/runtime/current-run-183-fleet-trip-playback-site-privacy-remediation-wave-35.json") == "7bb1b1013cf67344c48e5a8b6e551bf3c769695e0384c2b333fb47286e53310a"
assert run_183_remediation["pins"]["application_baseline_commit"] == "db4196ccb3a8d9f6bcb33fb40680527d09c02dac"
assert run_183_remediation["pins"]["fix_commit"] == "93e576978efae4a0112a95ed406c312f6bcadeb5"
assert run_183_remediation["pins"]["clean_advanced_main_commit"] == "0537f0f0eacafbeaf635ced4883a8bdf8e49d3f6"
assert run_183_remediation["pins"]["local_main_merge_commit"] == "4038cf7fe5a789ca64e436300f2cf4b94ac16db4"
assert run_183_remediation["pins"]["stable_patch_id"] == "12c306d28e54ff88432d18b271706473ee793871"
assert run_183_remediation["delegated_runtime_execution"]["baseline_red"] == {
    "tests": 5,
    "failed": 3,
    "passed": 2,
    "assertions_reported": 30,
    "duration_seconds": 160.09,
    "exit_code": 1,
    "credit": "REPRODUCTION_ONLY_ZERO_PASSING_DENOMINATOR_CREDIT",
}
assert run_183_remediation["delegated_runtime_execution"]["isolated_green_focused"]["tests"] == 11
assert run_183_remediation["delegated_runtime_execution"]["isolated_green_focused"]["assertions"] == 167
assert run_183_remediation["delegated_runtime_execution"]["isolated_supporting_fleet_regressions"]["tests"] == 20
assert run_183_remediation["delegated_runtime_execution"]["isolated_supporting_fleet_regressions"]["assertions"] == 215
assert run_183_remediation["delegated_runtime_execution"]["post_merge_green_focused"]["tests"] == 11
assert run_183_remediation["delegated_runtime_execution"]["post_merge_green_focused"]["assertions"] == 167
assert run_183_remediation["delegated_runtime_execution"]["unique_bounded_accounting"]["resulting"] == {
    "tests": 99,
    "assertions": 1931,
}
assert all(value is False for value in run_183_remediation["completion_boundary"].values())
assert len(run_183_remediation["completion_gates"]) == 26
assert [gate["gate"] for gate in run_183_remediation["completion_gates"]] == list(range(1, 27))
assert not any(gate["complete"] for gate in run_183_remediation["completion_gates"])
run_183_without_seal = dict(run_183_remediation)
run_183_seal = run_183_without_seal.pop("receipt_self_seal_sha256")
assert run_183_seal == "839e8d47700afedd2ec277695bbe492bd13433492ce0ff724c753988b5ce039a"
assert canonical_sha256(run_183_without_seal) == run_183_seal

assert run_183r_review["run_id"] == "RUN-183R-INDEPENDENT-FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01-REMEDIATION-REVIEW-WAVE-35"
assert sha256_file("generators/materialize-independent-run-183-fleet-trip-playback-site-privacy-remediation-review-wave-35.py") == "171836a13c108c3176e8ddc1fa62dbc86503d6e459e43bce3eb9a1d369ece61a"
assert sha256_file("evidence/runtime/current-run-183r-independent-fleet-trip-playback-site-privacy-remediation-review-wave-35.json") == "170245898590f6429a171bbd8a41455f096b5b43340b840294735fdbc5522640"
assert run_183r_review["decision"]["verdict"] == "GO"
assert run_183r_review["decision"]["blocking_discrepancies"] == 0
assert run_183r_review["decision"]["new_historical_remediated_record_reporting_authorized"] is True
assert run_183r_review["decision"]["authorized_live_reporting_run"] == "RUN-184"
assert run_183r_review["decision"]["authorized_resulting_lineage"] == {
    "retained_claim_records": 14,
    "current_provisional_source_claims": 8,
    "historical_already_fixed_records": 2,
    "historical_remediated_records": 4,
    "final_P0": 0,
    "final_P1": 0,
}
assert run_183r_review["decision"]["static_ownership_remains_pending"]["next_zero_based_index"] == 85
assert all(value is False for value in run_183r_review["completion_boundary"].values())
assert len(run_183r_review["completion_gates"]) == 26
assert [gate["gate"] for gate in run_183r_review["completion_gates"]] == list(range(1, 27))
assert not any(gate["complete"] for gate in run_183r_review["completion_gates"])
run_183r_without_seal = dict(run_183r_review)
run_183r_seal = run_183r_without_seal.pop("receipt_self_seal_sha256")
assert run_183r_seal == "a639be1048e97e5907509b571ed92dd4a2513a22dab5b16c188b3c5e82a1b68c"
assert canonical_sha256(run_183r_without_seal) == run_183r_seal

assert run_184_reporting["run_id"] == "RUN-184-FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01-REMEDIATION-REPORTING-WAVE-35"
assert sha256_file("generators/materialize-run-184-fleet-trip-playback-site-privacy-remediation-reporting-wave-35.py") == run_184_reporting["pins"]["reporting_materializer"]["sha256"]
run_184_builder_payload = git_file_at_commit(
    RUN_184_REPORTING_COMMIT,
    "generators/build-current-audit-dashboard.py",
)
assert hashlib.sha256(run_184_builder_payload).hexdigest() == run_184_reporting["pins"]["dashboard_builder"]["sha256"]
assert run_184_reporting["reporting_transition"]["finding_id"] == "FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01"
assert run_184_reporting["reporting_transition"]["counts_after"] == {
    "retained_claim_records": 14,
    "provisional_source_claims": 8,
    "historical_already_fixed": 2,
    "historical_remediated": 4,
    "final_P0": 0,
    "final_P1": 0,
}
assert run_184_reporting["bounded_execution_accounting"]["unique_total"] == {
    "tests": 99,
    "assertions": 1931,
}
assert run_184_reporting["preservation_boundary"]["dashboard_sha256"] == "8779848cc1a95ef82f2c5eba1a542e5a4784559f75ef20e9eb06696abde56457"
assert run_184_reporting["preservation_boundary"]["static_ownership"] == {
    "owners": 666,
    "routes": 309,
    "pages": 357,
    "controller_action_bridges": 97,
    "ownership_status": "PENDING_FRESH_SEMANTIC_REVIEW",
}
assert run_184_reporting["preservation_boundary"]["queue"] == {
    "next_zero_based_index": 85,
    "next_queue_id": "RUN090-ROUTE-0086",
    "next_route_record_id": "RUN077-ROUTE-0694",
    "reviewed": 120,
    "pending": 387,
    "owned": 98,
    "without_ownership": 409,
    "advanced_by_run_184": False,
}
assert run_184_reporting["preservation_boundary"]["benchmark"] == {
    "mapped": 2,
    "total": 340,
    "final_no_match_or_NCM": 0,
    "unresolved": 338,
}
assert {key for key, value in run_184_reporting["credit_boundary"].items() if value} == {
    "live_findings_register_and_reporting_status",
}
assert all(value is False for value in run_184_reporting["completion_boundary"].values())
assert len(run_184_reporting["completion_gates"]) == 26
assert not any(run_184_reporting["completion_gates"].values())
run_184_without_seal = dict(run_184_reporting)
run_184_seal = run_184_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_184_without_seal) == run_184_seal

assert sha256_file("generators/materialize-run-185-audit-dashboard-verification-wave-35.py") == "d974f3329db546b5b7a3f1e2325ecb9f9eba6db6fee3e695aca7a7ff668ab18f"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-185-wave-35.json") == "e6965bba3f25b80e6ce70aa3656802956bed935d79aaf46576e1420f0c65e07c"
assert dashboard_run_185["run_id"] == "RUN-185-AUDIT-DASHBOARD-VERIFICATION-WAVE-35"
assert dashboard_run_185["pins"]["run_185_final_dashboard"]["sha256"] == "3c339da7e05349a7bd5cbed9ec4986e3b4871eb04d497d26078e92268a67e5e7"
assert dashboard_run_185["verification"]["viewports_verified"] == 4
assert dashboard_run_185["verification"]["visible_static_checks_required"] == 117
assert dashboard_run_185["verification"]["visible_static_checks_passed"] == 117
assert dashboard_run_185["verification"]["navigation_clicks_passed"] == 10
assert dashboard_run_185["verification"]["unique_local_resources"] == 463
assert dashboard_run_185["verification"]["post_materialization_filesystem_resources"] == "463/463"
assert dashboard_run_185["verification"]["post_materialization_http_head_resources"] == "463/463"
assert dashboard_run_185["verification"]["anchor_elements"] == 868
dashboard_run_185_without_seal = dict(dashboard_run_185)
dashboard_run_185_seal = dashboard_run_185_without_seal.pop("receipt_self_seal_sha256")
assert dashboard_run_185_seal == "d49f27d1ed2f7f4f53c366d711653bbbb0fc541ba2d7c08f2780d4319193e776"
assert canonical_sha256(dashboard_run_185_without_seal) == dashboard_run_185_seal
assert {key for key, value in dashboard_run_185["credit_boundary"].items() if value} == {"exact_audit_dashboard_artifact"}
run_185_history = findings_register["current_audit_artifact_verification_history"]["run_185"]
assert run_185_history["run_id"] == dashboard_run_185["run_id"]
assert run_185_history["receipt_sha256"] == "e6965bba3f25b80e6ce70aa3656802956bed935d79aaf46576e1420f0c65e07c"
assert run_185_history["receipt_self_seal_sha256"] == dashboard_run_185_seal
assert run_185_history["dashboard_sha256"] == "3c339da7e05349a7bd5cbed9ec4986e3b4871eb04d497d26078e92268a67e5e7"
assert run_185_history["viewports"] == "4/4"
assert run_185_history["visible_boundary_checks"] == "117/117"
assert run_185_history["navigation"] == "10/10"
assert run_185_history["unique_local_resources"] == "463/463"
assert run_185_history["anchor_elements"] == "868/868"
assert run_185_history["superseded_by_run_187_reporting_sources"] is True
assert run_185_history["run_188_dashboard_verification_required"] is True
assert run_185_history["application_browser_credit"] is False
assert run_185_history["publication_credit"] is False
assert run_185_history["audit_complete"] is False

assert sha256_file("generators/materialize-run-186-monitoring-metric-replay-dedupe-remediation-wave-36.py") == "983b003dc149c966cdea9c59dd3cd4a766f4a5f0382e881f90b9d0cde9b86cee"
assert sha256_file("evidence/runtime/current-run-186-monitoring-metric-replay-dedupe-remediation-wave-36.json") == "bf2cd03ca2ab7aeb6a9d1093b3c08aba5a1bc29342cc4fda6fa57ef286c2f1e5"
assert run_186_remediation["run_id"] == "RUN-186-MON-METRIC-REPLAY-DEDUPE-01-REMEDIATION-WAVE-36"
assert run_186_remediation["pins"]["application_baseline_commit"] == "a900f078c9c05f587f6f7884f5fe715076891416"
assert run_186_remediation["pins"]["initial_fix_commit"] == "f521bc0b87222e56b4822e7cb9c935486e279e76"
assert run_186_remediation["pins"]["initial_merge_commit"] == "778c00a5d09511aee1a836a689d7bb1b56ce4ff6"
assert run_186_remediation["pins"]["corrective_fix_commit"] == "c82f57779baf623c4e94ac4619b11c1b675d0230"
assert run_186_remediation["pins"]["corrective_merge_commit"] == "18652d545c788f1dcdbe57662e5b1e5472d6cae7"
assert run_186_remediation["pins"]["corrective_merge_tree"] == "095cd7b1940988be334979af22008c635fdcaf58"
assert run_186_remediation["pins"]["current_local_main_commit"] == "f938c6d989f5fef052f08b9f1012116fb5cf2f69"
assert run_186_remediation["pins"]["current_local_main_tree"] == "70b2339300278bc0c20e32ed091f74b442bea76d"
assert run_186_remediation["initial_remediation_and_no_go"]["post_merge_independent_disposition"] == "NO_GO"
assert run_186_remediation["initial_remediation_and_no_go"]["isolated_green_superseded"]["tests"] == 49
assert run_186_remediation["initial_remediation_and_no_go"]["isolated_green_superseded"]["assertions"] == 392
assert run_186_remediation["initial_remediation_and_no_go"]["initial_green_contributes_current_reporting_denominator"] is False
metric_runtime = run_186_remediation["delegated_runtime_execution"]
assert metric_runtime["final_corrected_post_merge_full_focused"]["tests"] == 56
assert metric_runtime["final_corrected_post_merge_full_focused"]["assertions"] == 472
assert metric_runtime["final_corrected_post_merge_full_focused"]["unique_bounded_disposition_denominator_credit"] is True
assert metric_runtime["final_corrected_isolated_full_focused"]["denominator_credit"] is False
assert metric_runtime["unique_bounded_accounting"] == {
    "prior": {"tests": 99, "assertions": 1931},
    "increment": {"tests": 56, "assertions": 472},
    "proposed_after_run_186r_go": {"tests": 155, "assertions": 2403},
}
assert run_186_remediation["corrective_remediation"]["deployment_prerequisite"] == [
    "quiesce old monitoring workers",
    "reconcile pending or incoherent rows",
    "apply migration 000110",
    "start new workers only after cutover reconciliation",
]
assert run_186_remediation["corrective_remediation"]["poisoned_pre_f521_subsecond_bridge_fails_closed"] is True
assert run_186_remediation["corrective_remediation"]["deployment_prerequisite_verified_in_production"] is False
assert len(run_186_remediation["completion_gates"]) == 26
assert not any(gate["complete"] for gate in run_186_remediation["completion_gates"])
run_186_without_seal = dict(run_186_remediation)
run_186_seal = run_186_without_seal.pop("receipt_self_seal_sha256")
assert run_186_seal == "9d21a45a215a9d48a82d093817aba6807ef6ed73b130894ac385a41e18e527ff"
assert canonical_sha256(run_186_without_seal) == run_186_seal

assert sha256_file("generators/materialize-independent-run-186-monitoring-metric-replay-dedupe-remediation-review-wave-36.py") == "e081b3807c81f8f8d9c6982faddf63b548db650437cde13ff424730181361026"
assert sha256_file("evidence/runtime/current-run-186r-independent-monitoring-metric-replay-dedupe-remediation-review-wave-36.json") == "035271d7bfcd4256a59f01e9953f9cd8074466c0389f74ce82325a46ee6a6af7"
assert run_186r_review["run_id"] == "RUN-186R-INDEPENDENT-MON-METRIC-REPLAY-DEDUPE-01-REMEDIATION-REVIEW-WAVE-36"
metric_review_decision = run_186r_review["decision"]
assert metric_review_decision["verdict"] == "GO"
assert metric_review_decision["blocking_discrepancies"] == 0
assert metric_review_decision["new_historical_remediated_record_reporting_authorized"] is True
assert metric_review_decision["authorized_live_reporting_run"] == "RUN-187"
assert metric_review_decision["authorized_finding_id"] == "MON-METRIC-REPLAY-DEDUPE-01"
assert metric_review_decision["authorized_feature_id"] is None
assert metric_review_decision["authorized_candidate_feature_id"] is None
assert metric_review_decision["authorized_feature_identity_status"] == "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW"
assert metric_review_decision["authorized_resulting_lineage"] == {
    "retained_claim_records": 15,
    "current_provisional_source_claims": 8,
    "historical_already_fixed_records": 2,
    "historical_remediated_records": 5,
    "final_P0": 0,
    "final_P1": 0,
}
assert metric_review_decision["authorized_unique_bounded_disposition_increment"]["resulting_tests"] == 155
assert metric_review_decision["authorized_unique_bounded_disposition_increment"]["resulting_assertions"] == 2403
assert metric_review_decision["static_ownership_remains_pending"]["owner_records"] == 666
assert metric_review_decision["static_ownership_remains_pending"]["route_owners"] == 309
assert metric_review_decision["static_ownership_remains_pending"]["page_owners"] == 357
assert metric_review_decision["static_ownership_remains_pending"]["action_bridges"] == 97
assert metric_review_decision["static_ownership_remains_pending"]["queue_reviewed"] == 120
assert metric_review_decision["static_ownership_remains_pending"]["queue_pending"] == 387
assert metric_review_decision["static_ownership_remains_pending"]["queue_owned"] == 98
assert metric_review_decision["static_ownership_remains_pending"]["queue_without_ownership"] == 409
assert metric_review_decision["static_ownership_remains_pending"]["next_zero_based_index"] == 85
assert metric_review_decision["option_a_deployment_boundary"]["prerequisites"] == metric_finding["option_a_deployment_boundary"]["prerequisites_in_order"]
assert metric_review_decision["option_a_deployment_boundary"]["poisoned_subsecond_evidence_requires_operator_reconciliation"] is True
assert metric_review_decision["option_a_deployment_boundary"]["verified_in_production"] is False
assert len(run_186r_review["completion_gates"]) == 26
assert not any(gate["complete"] for gate in run_186r_review["completion_gates"])
run_186r_without_seal = dict(run_186r_review)
run_186r_seal = run_186r_without_seal.pop("receipt_self_seal_sha256")
assert run_186r_seal == "0176cbf5e4756c4da3cfbcd91728db53756b7cd755a4db68dc8dade59daeff56"
assert canonical_sha256(run_186r_without_seal) == run_186r_seal

assert run_187_reporting["run_id"] == "RUN-187-MON-METRIC-REPLAY-DEDUPE-01-REMEDIATION-REPORTING-WAVE-36"
assert run_187_reporting["reporting_transition"]["finding_id"] == "MON-METRIC-REPLAY-DEDUPE-01"
assert run_187_reporting["reporting_transition"]["feature_id"] is None
assert run_187_reporting["reporting_transition"]["candidate_feature_id"] is None
assert run_187_reporting["reporting_transition"]["feature_identity_status"] == "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW"
assert run_187_reporting["reporting_transition"]["counts_after"] == {
    "retained_claim_records": 15,
    "provisional_source_claims": 8,
    "historical_already_fixed": 2,
    "historical_remediated": 5,
    "final_P0": 0,
    "final_P1": 0,
}
assert run_187_reporting["bounded_execution_accounting"]["unique_total"] == {"tests": 155, "assertions": 2403}
assert run_187_reporting["bounded_execution_accounting"]["counted_once"] == {"tests": 56, "assertions": 472}
assert run_187_reporting["preservation_boundary"]["dashboard_sha256"] == "3c339da7e05349a7bd5cbed9ec4986e3b4871eb04d497d26078e92268a67e5e7"
assert run_187_reporting["preservation_boundary"]["dashboard_html_changed"] is False
assert run_187_reporting["dashboard_forward_gate"]["required_run"] == "RUN-188"
assert run_187_reporting["option_a_deployment_boundary"]["prerequisites_in_order"] == metric_finding["option_a_deployment_boundary"]["prerequisites_in_order"]
assert {key for key, value in run_187_reporting["credit_boundary"].items() if value} == {"live_findings_register_and_reporting_status"}
assert len(run_187_reporting["completion_gates"]) == 26
assert not any(run_187_reporting["completion_gates"].values())
assert all(value is False for value in run_187_reporting["completion_boundary"].values())
run_187_without_seal = dict(run_187_reporting)
run_187_seal = run_187_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_187_without_seal) == run_187_seal

assert sha256_file("generators/materialize-run-188-audit-dashboard-verification-wave-36.py") == "863328dbaeff2f039ba19f5f33d4109468a6b15ababfaeadcf4fd016f91e77a9"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-188-wave-36.json") == "80e54a76673af5aa8fc00e0738c7e7ee219f17d6bb22d2646e37c1cbd2081a56"
assert dashboard_run_188["run_id"] == "RUN-188-AUDIT-DASHBOARD-VERIFICATION-WAVE-36"
assert dashboard_run_188["verification"]["viewports_verified"] == 4
assert dashboard_run_188["verification"]["visible_static_checks_passed"] == 152
assert dashboard_run_188["verification"]["navigation_clicks_passed"] == 10
assert dashboard_run_188["verification"]["post_materialization_filesystem_resources"] == "471/471"
assert dashboard_run_188["verification"]["anchor_elements"] == 888
assert dashboard_run_188["pins"]["run_188_final_dashboard"]["sha256"] == "3d65bd82b8bc0f650158c4587f9618a03079f75d51e83496dc7d71addf257d79"
run_188_without_seal = dict(dashboard_run_188)
run_188_seal = run_188_without_seal.pop("receipt_self_seal_sha256")
assert run_188_seal == "a3feac39603045a78926b38393c9109afdd13b53b6a6338c1d53ef84f7bdc243"
assert canonical_sha256(run_188_without_seal) == run_188_seal

assert sha256_file("generators/integrate-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-wave-37.py") == "0115154b4472f96977f0d82c286943af7b687240cd23f997d2d5e0a590e18599"
assert sha256_file("evidence/source/current-run-190-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-wave-37.json") == "88494bb887c78f488df3915c86a8ad47b2176da469aedda3803151b8edd4a708"
assert reviewed_fleet_trip_playback_overlay["run_id"] == "RUN-190-REVIEWED-OUTCOME-NEUTRAL-FLEET-TRIP-PLAYBACK-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-37"
assert reviewed_fleet_trip_playback_overlay["reviewed_overlay"]["accepted_route_owner_records"] == 1
assert reviewed_fleet_trip_playback_overlay["reviewed_overlay"]["accepted_page_owner_records"] == 0
assert reviewed_fleet_trip_playback_overlay["reviewed_overlay"]["accepted_controller_action_bridges"] == 1
assert reviewed_fleet_trip_playback_overlay["combined_counts"]["source_owner_records"] == 667
assert reviewed_fleet_trip_playback_overlay["combined_counts"]["route_owner_records"] == 310
assert reviewed_fleet_trip_playback_overlay["combined_counts"]["page_owner_records"] == 357
assert reviewed_fleet_trip_playback_overlay["combined_counts"]["static_controller_action_bridges"] == 98
assert reviewed_fleet_trip_playback_overlay["queue_accounting"]["reviewed_queue_surface_rows"] == 121
assert reviewed_fleet_trip_playback_overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 386
assert reviewed_fleet_trip_playback_overlay["queue_accounting"]["reviewed_owner_route_rows"] == 99
assert reviewed_fleet_trip_playback_overlay["queue_accounting"]["queue_surfaces_without_ownership"] == 408
assert reviewed_fleet_trip_playback_overlay["queue_boundary"]["next_unresolved_index"] == 86
assert reviewed_fleet_trip_playback_overlay["queue_boundary"]["next_unresolved_queue_id"] == "RUN090-ROUTE-0087"
assert reviewed_fleet_trip_playback_overlay["queue_boundary"]["next_unresolved_route_record_id"] == "RUN077-ROUTE-0695"
assert reviewed_fleet_trip_playback_overlay["queue_boundary"]["next_unresolved_route_name"] == "fleet-assets.trips.playback.data"
assert reviewed_fleet_trip_playback_overlay["queue_boundary"]["next_unresolved_action_expression"] == "[FleetTripController::class, 'playback']"
assert reviewed_fleet_trip_playback_overlay["queue_boundary"]["next_unresolved_queue_record_sha256"] == "ed12617b478e0a22014fb6c81402e5cf79aa574720e8ef8e2ce93f198a099893"
assert reviewed_fleet_trip_playback_overlay["overlay_source_records"][0]["overlay_row_sha256"] == "31a2f128dacd47d73377db8422e2d89448909d9f4d98fe8089fa0522cb0ddfb2"
assert reviewed_fleet_trip_playback_overlay["new_static_controller_action_bridges"][0]["bridge_row_sha256"] == "a8934922a42c1270c62276c2dc345066372a8ea73fa3ca0875cd3c75020fc5c9"
assert {key for key, value in reviewed_fleet_trip_playback_overlay["credit_boundary"].items() if value} == {
    "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD",
    "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION",
}
run_190_without_seal = dict(reviewed_fleet_trip_playback_overlay)
run_190_seal = run_190_without_seal.pop("self_seal")
assert run_190_seal["sha256"] == "16cbb874448ec053f976594cfe031ed1834601d66c8b1ffe7bb79a06336d4142"
assert canonical_sha256(run_190_without_seal) == run_190_seal["sha256"]

assert sha256_file("generators/materialize-independent-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-review-wave-37.py") == "ec87f7eb11ab139278e7247880ae8a4adb8546cc55ce8ed76d2c2ea79603f132"
assert sha256_file("evidence/source/current-run-190r-independent-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-review-wave-37.json") == "36376b3e40a2611cf814c2b034ecf0157f5fdae480d7e893a8aa6992286b3b3b"
assert reviewed_fleet_trip_playback_overlay_review["run_id"] == "RUN-190R-INDEPENDENT-REVIEWED-OUTCOME-NEUTRAL-FLEET-TRIP-PLAYBACK-ROUTE-ACTION-OWNERSHIP-OVERLAY-REVIEW-WAVE-37"
assert reviewed_fleet_trip_playback_overlay_review["decision"]["independent_reviews"] == 2
assert reviewed_fleet_trip_playback_overlay_review["decision"]["discrepancies"] == 0
assert reviewed_fleet_trip_playback_overlay_review["decision"]["reporting_materialization_authorized"] is True
assert {key for key, value in reviewed_fleet_trip_playback_overlay_review["credit_boundary"].items() if value} == {
    "INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING",
}
run_190r_without_seal = dict(reviewed_fleet_trip_playback_overlay_review)
run_190r_seal = run_190r_without_seal.pop("self_seal")
assert run_190r_seal["sha256"] == "1a9767955433c71d958c21557e3084ecb5b66a3b7e190324d4fd387f5267e503"
assert canonical_sha256(run_190r_without_seal) == run_190r_seal["sha256"]

assert run_191_reporting["run_id"] == "RUN-191-REVIEWED-FLEET-TRIP-PLAYBACK-ROUTE-ACTION-REPORTING-WAVE-37"
assert run_191_reporting["reporting_snapshot"]["combined_counts"]["source_owner_records"] == 667
assert run_191_reporting["reporting_snapshot"]["queue_accounting"]["reviewed_queue_surface_rows"] == 121
assert run_191_reporting["dashboard_forward_gate"]["required_run"] == "RUN-192"
assert run_191_reporting["dashboard_forward_gate"]["dashboard_html_changed_by_run_191"] is False
assert {key for key, value in run_191_reporting["credit_boundary"].items() if value} == {
    "live_findings_register_and_reporting_status",
}
assert len(run_191_reporting["completion_gates"]) == 26
assert not any(run_191_reporting["completion_gates"].values())
assert all(value is False for value in run_191_reporting["completion_boundary"].values())
run_191_without_seal = dict(run_191_reporting)
run_191_seal = run_191_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_191_without_seal) == run_191_seal

assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-192-wave-37.json") == "cc95b38ece501bea317e78ef7769a7813e3a5a6c2041330e249fd196974cbb88"
assert dashboard_run_192["run_id"] == "RUN-192-AUDIT-DASHBOARD-VERIFICATION-WAVE-37"
assert dashboard_run_192["status"] == "VERIFIED_EXACT_ARTIFACT_ONLY"
assert dashboard_run_192["pins"]["final_run_192_dashboard"]["sha256"] == "8d19569e7bfb256edeecdc754e2bc47e2ddad3ecd8de099e3bb0dad9b50e313b"
assert dashboard_run_192["pins"]["final_run_192_builder"]["sha256"] == "e2b5c461cd9f22e0dba35d3555788534a3d244ea40a3c3424d1b80b003a6c242"
assert dashboard_run_192["browser_verification"]["viewports_verified"] == 4
assert dashboard_run_192["browser_verification"]["expected_viewports"] == 4
assert all(
    viewport["visible_text_passed"] == viewport["visible_text_total"] == 30
    for viewport in dashboard_run_192["browser_verification"]["viewports"].values()
)
assert dashboard_run_192["browser_verification"]["navigation_passed"] == 10
assert dashboard_run_192["browser_verification"]["navigation_total"] == 10
assert dashboard_run_192["browser_verification"]["browser_finalization_complete"] is True
assert dashboard_run_192["browser_verification"]["live_application_browser"] is False
assert dashboard_run_192["html_and_resource_graph"]["unique_local_resources"] == 476
assert dashboard_run_192["html_and_resource_graph"]["existing_unique_local_resources"] == 476
assert dashboard_run_192["html_and_resource_graph"]["anchor_element_count"] == 893
assert dashboard_run_192["html_and_resource_graph"]["duplicate_id_count"] == 0
assert dashboard_run_192["html_and_resource_graph"]["hash_mismatches"] == []
assert dashboard_run_192["final_http_head_verification"] == {
    "expected_unique_local_resources": 476,
    "verified_count": 476,
    "failure_count": 0,
    "complete": True,
}
assert dashboard_run_192["browser_verification"]["browser_warning_or_error_count"] == 0
assert dashboard_run_192["schema_version"] == "oblivion-audit-dashboard-verification-v1"
cleanup_192 = dashboard_run_192["root_browser_resource_cleanup"]
assert cleanup_192["listeners_after_cleanup"] == 0
assert cleanup_192["exact_pid_present_after_cleanup"] is False
assert cleanup_192["matching_loopback_server_processes_after_cleanup"] == 0
assert cleanup_192["cleanup_finalized"] is True
assert dashboard_run_192["artifact_completion_test_met"] is True
assert dashboard_run_192["audit_completion_test_met"] is False
canonical_completion_gate_names = {row["name"] for row in dashboard_run_192["completion_gates"]}
assert canonical_completion_gate_names == set(dashboard_run_192["completion_boundary"])
assert len(dashboard_run_192["completion_gates"]) == 26
assert [row["gate"] for row in dashboard_run_192["completion_gates"]] == list(range(1, 27))
assert not any(row["complete"] for row in dashboard_run_192["completion_gates"])
assert all(value is False for value in dashboard_run_192["completion_boundary"].values())
assert {key for key, value in dashboard_run_192["credit_boundary"].items() if value} == {
    "exact_audit_dashboard_artifact",
}
run_192_without_seal = dict(dashboard_run_192)
run_192_seal = run_192_without_seal.pop("receipt_self_seal_sha256")
assert run_192_seal == "a112198be2915cfc8a88b31b38a9cb33c90ad407b6da83f85fac5deae6727995"
assert canonical_sha256(run_192_without_seal) == run_192_seal

assert sha256_file("evidence/runtime/current-run-193-fleet-fuel-index-site-privacy-remediation-wave-38.json") == "1396205a5f63d4571b0e5b738f00f3a7cadc8ab93499a012e0e0f827b70b495f"
assert run_193_remediation["run_id"] == "RUN-193-FLEET-FUEL-INDEX-SITE-PRIVACY-01-REMEDIATION-WAVE-38"
assert run_193_remediation["schema_version"] == "run-193-fleet-fuel-index-site-privacy-remediation-wave-38-v1"
assert run_193_remediation["status"] == "CURRENT_FLEET_FUEL_INDEX_SITE_PRIVACY_DEFECT_REPRODUCED_REMEDIATED_LOCALLY_INTEGRATED_BOUNDED_VERIFIED_NOT_PUBLISHED_REPORTING_NOT_YET_AUTHORIZED_ZERO_STATIC_OWNERSHIP_FINAL_FINDING_OR_COMPLETION_CREDIT"
assert run_193_remediation["pins"]["application_baseline_commit"] == "df65322f8eb7d7d0f1623c4bcb8cc8c87573b71d"
assert run_193_remediation["pins"]["fix_commit"] == "2ec4b70e379c6f8cf38c1cb67f5d676fea52cf75"
assert run_193_remediation["pins"]["local_main_merge_commit"] == "04c32c36fdda6ce60ce281c06ad68aaa78527422"
assert run_193_remediation["pins"]["stable_patch_id"] == "636771c0b1d9cbe50b2204febaa41679d340aba9"
assert run_193_remediation["delegated_runtime_execution"]["baseline_original_red"] == {
    "cases": 6,
    "failed": 6,
    "passed": 0,
    "assertions_reported": 65,
    "duration_seconds": 152.37,
    "exit_code": 1,
    "credit": "REPRODUCTION_ONLY_ZERO_PASSING_DENOMINATOR_CREDIT",
}
assert run_193_remediation["delegated_runtime_execution"]["post_merge_authoritative_three_file_context"]["focused_component"]["tests"] == 6
assert run_193_remediation["delegated_runtime_execution"]["post_merge_authoritative_three_file_context"]["focused_component"]["assertions"] == 206
assert run_193_remediation["delegated_runtime_execution"]["isolated_supporting_vehicle_controller_regressions"]["tests"] == 20
assert run_193_remediation["delegated_runtime_execution"]["isolated_supporting_vehicle_controller_regressions"]["assertions"] == 215
assert run_193_remediation["static_ownership_boundary"]["next_zero_based_index"] == 86
assert run_193_remediation["static_ownership_boundary"]["finding_candidate_zero_based_index"] == 87
assert run_193_remediation["static_ownership_boundary"]["ownership_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
assert set(run_193_remediation["completion_gates"]) == canonical_completion_gate_names
assert set(run_193_remediation["completion_boundary"]) == canonical_completion_gate_names
assert len(run_193_remediation["completion_gates"]) == 26
assert not any(run_193_remediation["completion_gates"].values())
assert all(value is False for value in run_193_remediation["completion_boundary"].values())
assert {key for key, value in run_193_remediation["credit_boundary"].items() if value} == {
    "historical_condition_confirmed",
    "current_defect_reproduced",
    "application_remediation",
    "bounded_runtime",
    "bounded_selected_get_and_csv_execution",
    "bounded_site_privacy_correctness",
    "application_commit_integrated_local_main",
}
assert run_193_remediation["artifact_completion_test_met"] is True
assert run_193_remediation["audit_completion_test_met"] is False
run_193_without_seal = dict(run_193_remediation)
run_193_seal = run_193_without_seal.pop("receipt_self_seal_sha256")
assert run_193_seal == "762bbfbba5fd76fb284ee36fb9854004c224512671acd4b144adaa24f41973c4"
assert canonical_sha256(run_193_without_seal) == run_193_seal

assert sha256_file("evidence/runtime/current-run-193r-independent-fleet-fuel-index-site-privacy-remediation-review-wave-38.json") == "87a1157f26bbfaf062ec22bceb616bf4f54c72f908cddfd68c2b59db91cbbb41"
assert run_193r_review["run_id"] == "RUN-193R-INDEPENDENT-FLEET-FUEL-INDEX-SITE-PRIVACY-01-REMEDIATION-REVIEW-WAVE-38"
assert run_193r_review["schema_version"] == "run-193r-independent-fleet-fuel-index-site-privacy-remediation-review-wave-38-v1"
assert run_193r_review["status"] == "GO_EXACT_RUN193_ARTIFACT_REVIEW_NEW_HISTORICAL_REMEDIATED_REPORTING_AUTHORIZED_ZERO_STATIC_OWNERSHIP_PUBLICATION_FINAL_FINDING_OR_COMPLETION_CREDIT"
assert run_193r_review["review"]["independent_exact_artifact_reviewers"] == 3
assert run_193r_review["review"]["all_reviewers_read_only"] is True
assert all(run_193r_review["review"]["checks"].values())
assert run_193r_review["review"]["discrepancies"] == []
assert run_193r_review["decision"]["verdict"] == "GO"
assert run_193r_review["decision"]["blocking_discrepancies"] == 0
assert run_193r_review["decision"]["new_historical_remediated_record_reporting_authorized"] is True
assert run_193r_review["decision"]["authorized_live_reporting_run"] == "RUN-194"
assert run_193r_review["decision"]["authorized_finding_id"] == "FLEET-FUEL-INDEX-SITE-PRIVACY-01"
assert run_193r_review["decision"]["run_195_fresh_dashboard_verification_required"] is True
assert run_193r_review["decision"]["authorized_unique_bounded_disposition_increment"]["resulting_tests"] == 161
assert run_193r_review["decision"]["authorized_unique_bounded_disposition_increment"]["resulting_assertions"] == 2609
assert run_193r_review["decision"]["static_ownership_remains_pending"]["next_zero_based_index"] == 86
assert run_193r_review["decision"]["static_ownership_remains_pending"]["finding_candidate_zero_based_index"] == 87
assert set(run_193r_review["completion_gates"]) == canonical_completion_gate_names
assert set(run_193r_review["completion_boundary"]) == canonical_completion_gate_names
assert len(run_193r_review["completion_gates"]) == 26
assert not any(run_193r_review["completion_gates"].values())
assert all(value is False for value in run_193r_review["completion_boundary"].values())
assert {key for key, value in run_193r_review["credit_boundary"].items() if value} == {
    "independent_exact_artifact_review_for_new_historical_remediated_reporting",
}
assert run_193r_review["artifact_completion_test_met"] is True
assert run_193r_review["audit_completion_test_met"] is False
run_193r_without_seal = dict(run_193r_review)
run_193r_seal = run_193r_without_seal.pop("receipt_self_seal_sha256")
assert run_193r_seal == "df7f90f33692f0ff81a143bb3406238d8a2831caad47b295b7a1b784863a06e2"
assert canonical_sha256(run_193r_without_seal) == run_193r_seal

assert run_194_reporting["run_id"] == "RUN-194-FLEET-FUEL-INDEX-SITE-PRIVACY-01-REMEDIATION-REPORTING-WAVE-38"
assert run_194_reporting["schema_version"] == "run-194-fleet-fuel-index-site-privacy-remediation-reporting-wave-38-v1"
assert run_194_reporting["status"] == "FLEET_FUEL_INDEX_SITE_PRIVACY_HISTORICAL_REMEDIATION_REPORTING_MATERIALIZED_DASHBOARD_RUN195_REQUIRED_ZERO_STATIC_PUBLICATION_FINAL_FINDING_OR_COMPLETION_CREDIT"
assert run_194_reporting["reporting_transition"]["finding_id"] == "FLEET-FUEL-INDEX-SITE-PRIVACY-01"
assert run_194_reporting["reporting_transition"]["counts_after"]["retained_claim_records"] == 16
assert run_194_reporting["reporting_transition"]["counts_after"]["historical_remediated"] == 6
assert run_194_reporting["bounded_execution_accounting"]["unique_total"] == {"tests": 161, "assertions": 2609}
assert run_194_reporting["preservation_boundary"]["static_ownership"] == {
    "owners": 667,
    "routes": 310,
    "pages": 357,
    "controller_action_bridges": 98,
}
assert run_194_reporting["preservation_boundary"]["queue"]["next_zero_based_index"] == 86
assert run_194_reporting["dashboard_forward_gate"]["required_run"] == "RUN-195"
assert run_194_reporting["dashboard_forward_gate"]["dashboard_html_changed_by_run_194"] is False
assert run_194_reporting["dashboard_forward_gate"]["generator"] == "generators/materialize-run-195-audit-dashboard-verification-wave-38.py"
assert run_194_reporting["dashboard_forward_gate"]["receipt"] == "evidence/browser/current-audit-dashboard-verification-run-195-wave-38.json"
assert run_194_reporting["dashboard_forward_gate"]["fresh_four_viewport_navigation_resource_console_verification_required"] is True
assert run_194_reporting["dashboard_forward_gate"]["forward_paths_intentionally_unhashed"] is True
assert {key for key, value in run_194_reporting["credit_boundary"].items() if value} == {
    "live_findings_register_and_reporting_status",
}
assert set(run_194_reporting["completion_gates"]) == canonical_completion_gate_names
assert set(run_194_reporting["completion_boundary"]) == canonical_completion_gate_names
assert len(run_194_reporting["completion_gates"]) == 26
assert not any(run_194_reporting["completion_gates"].values())
assert all(value is False for value in run_194_reporting["completion_boundary"].values())
assert run_194_reporting["artifact_completion_test_met"] is True
assert run_194_reporting["audit_completion_test_met"] is False
run_194_without_seal = dict(run_194_reporting)
run_194_seal = run_194_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_194_without_seal) == run_194_seal

assert sha256_file("generators/materialize-run-195-audit-dashboard-verification-wave-38.py") == "349576404fe3ff96f1ceeeb9f7fa85887150246fc73bba3d9b48549415509c8d"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-195-wave-38.json") == "455ee26c87ec6f07eca687eb1e40d2049c01513002732d08f74696b3dd617456"
assert dashboard_run_195["run_id"] == "RUN-195-AUDIT-DASHBOARD-VERIFICATION-WAVE-38"
assert dashboard_run_195["schema_version"] == "run-195-audit-dashboard-verification-wave-38-v1"
assert dashboard_run_195["pins"]["final_run_195_builder"]["sha256"] == "44fe804d6671672fbe0c2cc73d2f0917f4042c466901419f9b76d89ecbdfd5a4"
assert dashboard_run_195["pins"]["final_run_195_dashboard"]["sha256"] == "9a87dc70a7b190347ca7029c12abf8e025e4c722a6256502ba8c1c9af542f3b9"
assert dashboard_run_195["reported_snapshot"]["finding_lineage"] == {
    "records": 16,
    "provisional": 8,
    "historical_already_fixed": 2,
    "historical_remediated": 6,
    "bounded_tests": 161,
    "bounded_assertions": 2609,
    "final_P0": 0,
    "final_P1": 0,
}
assert len(dashboard_run_195["current_browser_verification"]["viewports"]) == 4
assert all(
    row["visible_text_passed"] == row["visible_text_total"] == 39
    and row["page_horizontal_overflow"] is False
    and row["table_containment_failures"] == 0
    for row in dashboard_run_195["current_browser_verification"]["viewports"].values()
)
assert len(dashboard_run_195["current_browser_verification"]["navigation"]) == 10
assert all(row["target_exists"] and row["target_visible"] for row in dashboard_run_195["current_browser_verification"]["navigation"])
assert dashboard_run_195["html_graph"]["unique_local_resources"] == 491
assert dashboard_run_195["html_graph"]["existing_unique_local_resources"] == 491
assert dashboard_run_195["html_graph"]["anchor_element_count"] == 944
assert dashboard_run_195["html_graph"]["duplicate_id_count"] == 0
assert dashboard_run_195["html_graph"]["hash_mismatches"] == []
assert dashboard_run_195["finalization_state"]["cleanup_complete"] is True
assert {key for key, value in dashboard_run_195["credit_boundary"].items() if value} == {
    "exact_run_195_dashboard_artifact_verification",
}
assert len(dashboard_run_195["completion_gates"]) == 26
assert not any(row["complete"] for row in dashboard_run_195["completion_gates"])
run_195_without_seal = dict(dashboard_run_195)
run_195_seal = run_195_without_seal.pop("receipt_self_seal_sha256")
assert run_195_seal == "a3dc0871156ba4c6376a92a4cacab8b8697fa0efcd49dea42d212533aff6b284"
assert canonical_sha256(run_195_without_seal) == run_195_seal

assert sha256_file("generators/materialize-run-196-summary-timeline-site-privacy-remediation-wave-39.py") == "e8c45110a983d2d210501024d89d6f9b968103141b86feb174c5641757dd5555"
assert sha256_file("evidence/runtime/current-run-196-summary-timeline-site-privacy-remediation-wave-39.json") == "96c275826a695a4b41b98891bd6560e6592be415c43fa360f1730c0c7fe9013a"
assert run_196_remediation["run_id"] == "RUN-196-SUMMARY-TIMELINE-SITE-PRIVACY-01-REMEDIATION-WAVE-39"
assert run_196_remediation["status"] == "CURRENT_SUMMARY_TIMELINE_SITE_PRIVACY_DEFECT_REPRODUCED_REMEDIATED_LOCALLY_INTEGRATED_BOUNDED_VERIFIED_NOT_PUBLISHED_REPORTING_NOT_YET_AUTHORIZED_ZERO_STATIC_OWNERSHIP_FINAL_FINDING_OR_COMPLETION_CREDIT"
assert run_196_remediation["lineage"]["baseline"]["commit"] == "39a5d97d7d0ff9ea03070e90193581479f423022"
assert run_196_remediation["lineage"]["sealed_fix"]["commit"] == "31a9edfbab32a19062ccf15e123cd0b0923b7dc3"
assert run_196_remediation["lineage"]["effective_merge"]["commit"] == "5c8a1357f830d0b8a8c14924016d89df52ab9e86"
assert run_196_remediation["lineage"]["current_main"]["commit"] == "44ab5e270aecd961e2e75abcdbe4d2cb1effa3df"
assert run_196_remediation["finding"]["feature_identity"] == {
    "feature_id": None,
    "candidate_feature_id": None,
    "status": "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW",
    "static_ownership_credit": False,
}
assert run_196_remediation["finding"]["red_reproduction"]["failed"] == 1
assert run_196_remediation["finding"]["red_reproduction"]["passed"] == 5
assert run_196_remediation["finding"]["red_reproduction"]["assertions"] == 9
assert run_196_remediation["finding"]["zero_credit_runs"][0]["assertions"] == 0
assert run_196_remediation["finding"]["isolated_focused_verification"]["passed"] == 15
assert run_196_remediation["finding"]["isolated_focused_verification"]["assertions"] == 32
assert run_196_remediation["finding"]["isolated_focused_verification"]["eligible_for_bounded_aggregate"] is True
assert run_196_remediation["finding"]["isolated_supporting_compatibility"]["passed"] == 2
assert run_196_remediation["finding"]["isolated_supporting_compatibility"]["assertions"] == 238
assert run_196_remediation["finding"]["isolated_supporting_compatibility"]["eligible_for_bounded_aggregate"] is False
assert run_196_remediation["post_merge_shared_support"]["passed"] == 40
assert run_196_remediation["post_merge_shared_support"]["assertions"] == 438
assert run_196_remediation["post_merge_shared_support"]["shared_denominator_not_split_or_recredited"] is True
assert {key for key, value in run_196_remediation["credit_boundary"].items() if value} == {
    "exact_remediation_source_credit",
    "exact_focused_test_execution_credit",
    "local_merge_credit",
    "cleanup_credit",
    "excluded_surfaces",
}
assert len(run_196_remediation["completion_gates"]) == 26
assert not any(row["complete"] for row in run_196_remediation["completion_gates"])
assert {row["name"] for row in run_196_remediation["completion_gates"]} == set(run_196_remediation["completion_boundary"])
run_196_without_seal = dict(run_196_remediation)
run_196_seal = run_196_without_seal.pop("receipt_self_seal_sha256")
assert run_196_seal == "325269d2a0721c620c9a588da65c016b2355f8c5fb51e6ec112156888483609c"
assert canonical_sha256(run_196_without_seal) == run_196_seal

assert sha256_file("generators/materialize-independent-run-196-summary-timeline-site-privacy-remediation-review-wave-39.py") == "0c4fb643e608fa73fdc6118a7b83d1024123cd7857b84c36a136b51b3244edc8"
assert sha256_file("evidence/runtime/current-run-196r-independent-summary-timeline-site-privacy-remediation-review-wave-39.json") == "a53d2b279cf1becff1e7b851d522a43fb2cacfc05f5099250da910c9d3fbe151"
assert run_196r_review["run_id"] == "RUN-196R-INDEPENDENT-SUMMARY-TIMELINE-SITE-PRIVACY-01-REMEDIATION-REVIEW-WAVE-39"
assert run_196r_review["decision"]["verdict"] == "GO"
assert run_196r_review["review"]["independent_reviewers"] == 3
assert run_196r_review["review"]["all_final_reviewers_read_only"] is True
assert len(run_196r_review["review"]["resolved_no_go_findings"]) == 2
assert run_196r_review["review"]["discrepancies"] == []
assert run_196r_review["decision"]["authorized_finding_id"] == "SUMMARY-TIMELINE-SITE-PRIVACY-01"
assert run_196r_review["decision"]["authorized_feature_id"] is None
assert run_196r_review["decision"]["authorized_candidate_feature_id"] is None
assert run_196r_review["decision"]["authorized_resulting_lineage"] == {
    "retained_claim_records": 17,
    "current_provisional_source_claims": 8,
    "historical_already_fixed_records": 2,
    "historical_remediated_records": 7,
    "bounded_disposition_tests_passed": 176,
    "bounded_disposition_assertions": 2641,
    "final_P0": 0,
    "final_P1": 0,
}
assert run_196r_review["decision"]["run197_required"] is True
assert run_196r_review["decision"]["run198_fresh_dashboard_verification_required"] is True
assert {key for key, value in run_196r_review["credit_boundary"].items() if value} == {
    "independent_exact_artifact_review_for_reporting_authorization",
}
assert len(run_196r_review["completion_gates"]) == 26
assert not any(row["complete"] for row in run_196r_review["completion_gates"])
assert {row["name"] for row in run_196r_review["completion_gates"]} == set(run_196r_review["completion_boundary"])
run_196r_without_seal = dict(run_196r_review)
run_196r_seal = run_196r_without_seal.pop("receipt_self_seal_sha256")
assert run_196r_seal == "9eefa1031060434a0ee027b5a22d4a3a399ef6472220b5e8628808bf2eb375da"
assert canonical_sha256(run_196r_without_seal) == run_196r_seal

assert run_197_reporting["run_id"] == "RUN-197-SUMMARY-TIMELINE-SITE-PRIVACY-01-REMEDIATION-REPORTING-WAVE-39"
assert run_197_reporting["reporting_transition"]["finding_id"] == "SUMMARY-TIMELINE-SITE-PRIVACY-01"
assert run_197_reporting["reporting_transition"]["counts_after"] == {
    "retained_claim_records": 17,
    "provisional_source_claims": 8,
    "historical_already_fixed": 2,
    "historical_remediated": 7,
    "final_P0": 0,
    "final_P1": 0,
}
assert run_197_reporting["bounded_execution_accounting"]["unique_total"] == {"tests": 176, "assertions": 2641}
assert run_197_reporting["dashboard_forward_gate"]["required_run"] == "RUN-198"
assert run_197_reporting["dashboard_forward_gate"]["dashboard_html_changed_by_run_197"] is False
assert run_197_reporting["dashboard_forward_gate"]["preserved_run_195_dashboard_sha256"] == "9a87dc70a7b190347ca7029c12abf8e025e4c722a6256502ba8c1c9af542f3b9"
assert {key for key, value in run_197_reporting["credit_boundary"].items() if value} == {
    "live_findings_register_and_reporting_status",
}
assert len(run_197_reporting["completion_gates"]) == 26
assert not any(row["complete"] for row in run_197_reporting["completion_gates"])
assert {row["name"] for row in run_197_reporting["completion_gates"]} == set(run_197_reporting["completion_boundary"])
run_197_without_seal = dict(run_197_reporting)
run_197_seal = run_197_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_197_without_seal) == run_197_seal

assert sha256_file("generators/materialize-run-198-audit-dashboard-verification-wave-39.py") == "2298b162517329c736b66d01c6f2326ba6a71092c0fec1126731b42e9fb4a66c"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-198-wave-39.json") == "7585c000789063b598aa67e944584592a6e36f259484745817c7ece8c0739d52"
assert dashboard_run_198["run_id"] == "RUN-198-AUDIT-DASHBOARD-VERIFICATION-WAVE-39"
assert dashboard_run_198["pins"]["final_run_198_dashboard"]["sha256"] == "4432da4fecc7c9afa0096b46c3568249fccdaa8f0b987bfef4bc1eb07e24bd3a"
assert dashboard_run_198["pins"]["final_run_198_dashboard"]["bytes"] == 339486
assert dashboard_run_198["reported_snapshot"]["finding_lineage"] == {
    "records": 17,
    "provisional": 8,
    "historical_already_fixed": 2,
    "historical_remediated": 7,
    "bounded_tests": 176,
    "bounded_assertions": 2641,
    "final_P0": 0,
    "final_P1": 0,
}
assert set(dashboard_run_198["current_browser_verification"]["viewports"]) == {
    "1440x900", "1280x800", "1024x768", "390x844",
}
assert all(
    row["visible_text_passed"] == row["visible_text_total"] == 48
    and row["page_horizontal_overflow"] is False
    and row["table_containment_failures"] == 0
    for row in dashboard_run_198["current_browser_verification"]["viewports"].values()
)
assert len(dashboard_run_198["current_browser_verification"]["navigation"]) == 10
assert dashboard_run_198["http_head_verification"] == {
    "expected_unique_resources": 499,
    "verified_count": 499,
    "failure_count": 0,
    "complete": True,
}
assert dashboard_run_198["current_browser_verification"]["console"]["messages"] == []
assert dashboard_run_198["current_browser_verification"]["console"]["page_errors"] == []
assert len(dashboard_run_198["completion_gates"]) == 26
assert not any(row["complete"] for row in dashboard_run_198["completion_gates"])
run_198_without_seal = dict(dashboard_run_198)
run_198_seal = run_198_without_seal.pop("receipt_self_seal_sha256")
assert run_198_seal == "215f6fa5e14afd42f2263df2327f8d79c146005292c68471e10f5b0f06aa26f0"
assert canonical_sha256(run_198_without_seal) == run_198_seal

assert sha256_file("evidence/source/current-run-199-shift-task-due-recipient-revalidation-coordination-handoff-wave-40.json") == "344875fbcbcd92b9d739071065aa130ba78be3003d69e17fba0e3c486005c3a8"
assert run_199_coordination_handoff["schema_version"] == "oblivion_shift_task_due_recipient_revalidation_coordination_handoff_v1"
assert run_199_coordination_handoff["evidence_kind"] == "COORDINATION_HANDOFF_TRANSCRIPTION_NOT_ORIGINAL_RUNTIME_RECEIPT"
assert run_199_coordination_handoff["status"] == "SEALED_DELEGATED_EVIDENCE_FOR_BOUNDED_REPORTING_ONLY"
assert run_199_coordination_handoff["source"]["original_issue_specific_runtime_receipt_present"] is False
assert run_199_coordination_handoff["source"]["original_issue_specific_independent_review_receipt_present"] is False
assert run_199_coordination_handoff["source"]["run_199_reexecuted_application_tests"] is False
assert run_199_coordination_handoff["source"]["run_199_claims_original_runtime_authorship"] is False
assert run_199_coordination_handoff["finding"]["id"] == "SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01"
assert run_199_coordination_handoff["bounded_accounting"] == {
    "previous_unique_tests": 176,
    "previous_unique_assertions": 2641,
    "credited_increment_tests": 9,
    "credited_increment_assertions": 50,
    "current_unique_tests": 185,
    "current_unique_assertions": 2691,
    "exclusions": [
        "Red 1 failed plus 3 passed plus 1 pending and 14 assertions.",
        "Intermediate 5 tests and 30 assertions plus cache proofs.",
        "Isolated final 9 tests and 50 assertions as a duplicate replay.",
        "Any second count of the post-merge 9 tests and 50 assertions.",
    ],
}
assert not any(run_199_coordination_handoff["noninheritance"].values())
assert run_199_coordination_handoff["completion_credit"] is False
run_199_handoff_without_seal = dict(run_199_coordination_handoff)
run_199_handoff_seal = run_199_handoff_without_seal.pop("receipt_self_seal_sha256")
assert run_199_handoff_seal == "d693fc1367ec2b44304354b2d2b709b5ea7ee8840fc5f0d0d711936526b9e47e"
assert canonical_sha256(run_199_handoff_without_seal) == run_199_handoff_seal

assert sha256_file("generators/materialize-run-199-shift-task-due-recipient-revalidation-remediation-reporting-wave-40.py") == "951d6473a65ccb0d2f550bac5274fefb5d2123ed2df7c63eb4762f05a9790e41"
assert run_199_reporting["run_id"] == "RUN-199-SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01-REMEDIATION-REPORTING-WAVE-40"
assert run_199_reporting["reporting_transition"]["finding_id"] == "SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01"
assert run_199_reporting["reporting_transition"]["counts_after"] == {
    "retained_claim_records": 18,
    "provisional_source_claims": 8,
    "historical_already_fixed": 2,
    "historical_remediated": 8,
    "final_P0": 0,
    "final_P1": 0,
}
assert run_199_reporting["bounded_execution_accounting"]["unique_total"] == {
    "tests": 185,
    "assertions": 2691,
}
assert run_199_reporting["dashboard_forward_gate"]["required_run"] == "RUN-200"
assert run_199_reporting["dashboard_forward_gate"]["dashboard_html_changed_by_run_199"] is False
assert run_199_reporting["dashboard_forward_gate"]["preserved_run_198_dashboard_sha256"] == "4432da4fecc7c9afa0096b46c3568249fccdaa8f0b987bfef4bc1eb07e24bd3a"
assert {key for key, value in run_199_reporting["credit_boundary"].items() if value} == {
    "live_findings_register_and_reporting_status",
}
assert len(run_199_reporting["completion_gates"]) == 26
assert not any(row["complete"] for row in run_199_reporting["completion_gates"])
assert {row["name"] for row in run_199_reporting["completion_gates"]} == set(run_199_reporting["completion_boundary"])
run_199_without_seal = dict(run_199_reporting)
run_199_seal = run_199_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_199_without_seal) == run_199_seal

assert sha256_file("generators/materialize-run-200-audit-dashboard-verification-wave-40.py") == "023d06929555d20dbc242bb998e05ee7fc60c0917d0e62b39ab56356a74de578"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-200-wave-40.json") == "59b80aa14c8841f412d9b76003cc8f2dcd135634cd9394a43523bad31f62c520"
assert dashboard_run_200["schema_version"] == "run-200-audit-dashboard-verification-wave-40-v1"
assert dashboard_run_200["run_id"] == "RUN-200-AUDIT-DASHBOARD-VERIFICATION-WAVE-40"
assert dashboard_run_200["pins"]["final_run_200_dashboard"]["sha256"] == "f643ca1ec1716cfb2b32864aba1a97e8d69c3e726453707a3ce71e76b3c43205"
assert set(dashboard_run_200["current_browser_verification"]["viewports"]) == {
    "1440x900", "1280x800", "1024x768", "390x844",
}
assert all(
    row["visible_text_passed"] == row["visible_text_total"] == 48
    and row["page_horizontal_overflow"] is False
    and row["table_containment_failures"] == 0
    for row in dashboard_run_200["current_browser_verification"]["viewports"].values()
)
assert len(dashboard_run_200["current_browser_verification"]["navigation"]) == 10
assert dashboard_run_200["http_head_verification"]["expected_unique_resources"] == 504
assert dashboard_run_200["http_head_verification"]["verified_count"] == 504
assert dashboard_run_200["http_head_verification"]["failure_count"] == 0
assert len(dashboard_run_200["completion_gates"]) == 26
assert not any(row["complete"] for row in dashboard_run_200["completion_gates"])
run_200_without_seal = dict(dashboard_run_200)
run_200_seal = run_200_without_seal.pop("receipt_self_seal_sha256")
assert run_200_seal == "493b62087f2df1f2ff776f68c162fceb38ab69763a0b2554ba0148dd6c58d216"
assert canonical_sha256(run_200_without_seal) == run_200_seal

assert sha256_file("evidence/source/current-run-201-elig-shift-notification-site-privacy-coordination-handoff-wave-41.json") == "f17c4c8d91dd040fb0b142196f65fa2c7657160bfc232404d9b6fe629bd156b7"
assert sha256_file("generators/materialize-run-201-elig-shift-notification-site-privacy-remediation-reporting-wave-41.py") == "d358b4c091f57568a3516538e5e052085f824e671f6bf7141540e958154dcb04"
assert run_201_coordination_handoff["schema_version"] == "oblivion_elig_shift_notification_site_privacy_coordination_handoff_v1"
assert run_201_coordination_handoff["evidence_kind"] == "COORDINATION_HANDOFF_TRANSCRIPTION_NOT_ORIGINAL_RUNTIME_RECEIPT"
assert run_201_coordination_handoff["status"] == "SEALED_DELEGATED_EVIDENCE_FOR_BOUNDED_REPORTING_ONLY"
assert run_201_coordination_handoff["finding"]["id"] == "ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01"
assert run_201_coordination_handoff["bounded_accounting"]["current_unique_tests"] == 198
assert run_201_coordination_handoff["bounded_accounting"]["current_unique_assertions"] == 2716
assert not any(run_201_coordination_handoff["noninheritance"].values())
assert run_201_coordination_handoff["completion_credit"] is False
run_201_handoff_without_seal = dict(run_201_coordination_handoff)
run_201_handoff_seal = run_201_handoff_without_seal.pop("receipt_self_seal_sha256")
assert run_201_handoff_seal == "225a2548c1f2d0120e3edd5ef26feb02ad8616085a36aa2d502e81700e0da587"
assert canonical_sha256(run_201_handoff_without_seal) == run_201_handoff_seal

assert run_201_reporting["run_id"] == "RUN-201-ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01-REMEDIATION-REPORTING-WAVE-41"
assert run_201_reporting["reporting_transition"]["finding_id"] == "ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01"
assert run_201_reporting["reporting_transition"]["counts_after"] == {
    "retained_claim_records": 19,
    "provisional_source_claims": 8,
    "historical_already_fixed": 2,
    "historical_remediated": 9,
    "final_P0": 0,
    "final_P1": 0,
}
assert run_201_reporting["bounded_execution_accounting"]["unique_total"] == {
    "tests": 198,
    "assertions": 2716,
}
assert run_201_reporting["dashboard_forward_gate"]["required_run"] == "RUN-202"
assert run_201_reporting["dashboard_forward_gate"]["dashboard_html_changed_by_run_201"] is False
assert run_201_reporting["dashboard_forward_gate"]["preserved_run_200_dashboard_sha256"] == "f643ca1ec1716cfb2b32864aba1a97e8d69c3e726453707a3ce71e76b3c43205"
assert {key for key, value in run_201_reporting["credit_boundary"].items() if value} == {
    "live_findings_register_and_reporting_status",
}
assert len(run_201_reporting["completion_gates"]) == 26
assert not any(row["complete"] for row in run_201_reporting["completion_gates"])
run_201_without_seal = dict(run_201_reporting)
run_201_seal = run_201_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_201_without_seal) == run_201_seal

assert sha256_file("generators/materialize-run-202-audit-dashboard-verification-wave-41.py") == "05685136cf43f637e0835c8f8301f270c60466fce79868ffb033922095333355"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-202-wave-41.json") == "b63ed9585a03cc852d0f772be42de303f0866c73e80cc8522e8de0d328887471"
assert dashboard_run_202["schema_version"] == "run-202-audit-dashboard-verification-wave-41-v1"
assert dashboard_run_202["run_id"] == "RUN-202-AUDIT-DASHBOARD-VERIFICATION-WAVE-41"
assert dashboard_run_202["pins"]["final_run_202_dashboard"] == {
    "path": "audit-dashboard.html",
    "sha256": "1876db1ff590c86fb30cefb74368b0241c72d9b75966fcbf1a36d6b1096b30e3",
    "git_blob_id": "03442cdb7ec6e17ae55b61494932171bff1e33f4",
    "bytes": 350017,
    "lines": 78,
}
assert set(dashboard_run_202["current_browser_verification"]["viewports"]) == {
    "1440x900", "1280x800", "1024x768", "390x844",
}
assert all(
    row["visible_text_passed"] == row["visible_text_total"] == 48
    and row["page_horizontal_overflow"] is False
    and row["table_containment_failures"] == 0
    for row in dashboard_run_202["current_browser_verification"]["viewports"].values()
)
assert len(dashboard_run_202["current_browser_verification"]["navigation"]) == 10
assert dashboard_run_202["http_head_verification"] == {
    "expected_unique_resources": 509,
    "verified_count": 509,
    "failure_count": 0,
    "complete": True,
}
assert {key for key, value in dashboard_run_202["credit_boundary"].items() if value} == {
    "exact_run_202_dashboard_artifact_verification",
}
assert len(dashboard_run_202["completion_gates"]) == 26
assert not any(row["complete"] for row in dashboard_run_202["completion_gates"])
run_202_without_seal = dict(dashboard_run_202)
run_202_seal = run_202_without_seal.pop("receipt_self_seal_sha256")
assert run_202_seal == "a4d296e2a3f779bfa2c7cf34233958a37dc74bb5f6e4f7d78a867d6cb12dc3b8"
assert canonical_sha256(run_202_without_seal) == run_202_seal

assert sha256_file("evidence/source/current-run-203-fleet-trip-playback-data-point-eligibility-coordination-handoff-wave-42.json") == "ef75a5c6392225fb5c50d3f2964f4cc9d4bf2eda6646b4cdf65968c674d762cd"
assert run_203_coordination_handoff["schema_version"] == "oblivion_fleet_trip_playback_data_point_eligibility_coordination_handoff_v1"
assert run_203_coordination_handoff["evidence_kind"] == "COORDINATION_HANDOFF_TRANSCRIPTION_NOT_ORIGINAL_RUNTIME_RECEIPT"
assert run_203_coordination_handoff["status"] == "SEALED_DELEGATED_EVIDENCE_FOR_BOUNDED_REPORTING_ONLY"
assert run_203_coordination_handoff["finding"]["id"] == "FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01"
assert run_203_coordination_handoff["source"]["original_issue_specific_runtime_receipt_present"] is False
assert run_203_coordination_handoff["source"]["original_issue_specific_independent_review_receipt_present"] is False
assert run_203_coordination_handoff["source"]["run_203_reexecuted_application_tests"] is False
assert run_203_coordination_handoff["source"]["run_203_claims_original_runtime_authorship"] is False
assert run_203_coordination_handoff["bounded_accounting"] == {
    "previous_unique_tests": 198,
    "previous_unique_assertions": 2716,
    "credited_increment_tests": 1,
    "credited_increment_assertions": 6,
    "current_unique_tests": 199,
    "current_unique_assertions": 2722,
    "exclusions": [
        "Valid red reproduction: 1 failed test and 3 assertions.",
        "Environment-invalid shared-vendor/classmap red attempt in full.",
        "Isolated focused 1 test and 6 assertions as a duplicate replay.",
        "Isolated combined 27 tests and 213 assertions as replay and support evidence.",
        "The previously credited playback 11 tests and 167 assertions inside the post-merge combined run.",
        "The unchanged FleetManagement 15 tests and 40 assertions inside the post-merge combined run.",
        "Any second count of the post-merge combined 27 tests and 213 assertions.",
    ],
}
assert run_203_coordination_handoff["invalid_reproduction_attempt"]["credit"] is False
assert not any(run_203_coordination_handoff["noninheritance"].values())
assert run_203_coordination_handoff["completion_credit"] is False
run_203_handoff_without_seal = dict(run_203_coordination_handoff)
run_203_handoff_seal = run_203_handoff_without_seal.pop("receipt_self_seal_sha256")
assert run_203_handoff_seal == "a4b9ca491ffd65a11551bb850fd067a45980c8b1fa9084623a56e081e833acbd"
assert canonical_sha256(run_203_handoff_without_seal) == run_203_handoff_seal

assert sha256_file("generators/materialize-run-203-fleet-trip-playback-data-point-eligibility-remediation-reporting-wave-42.py") == "bae4f9a4584cc528a09e4375c0f5aca57dbe2e225c49adb14d0e3ae89b10ba9c"
assert run_203_reporting["schema_version"] == "run-203-fleet-trip-playback-data-point-eligibility-remediation-reporting-wave-42-v1"
assert run_203_reporting["run_id"] == "RUN-203-FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01-REMEDIATION-REPORTING-WAVE-42"
assert run_203_reporting["status"] == (
    "FLEET_TRIP_PLAYBACK_DATA_POINT_ELIGIBILITY_HISTORICAL_REMEDIATION_REPORTING_"
    "MATERIALIZED_DASHBOARD_RUN204_REQUIRED_ZERO_STATIC_PUBLICATION_FINAL_FINDING_"
    "OR_COMPLETION_CREDIT"
)
assert run_203_reporting["scope"]["finding_id"] == "FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01"
assert run_203_reporting["pins"]["coordination_handoff"]["sha256"] == "ef75a5c6392225fb5c50d3f2964f4cc9d4bf2eda6646b4cdf65968c674d762cd"
assert run_203_reporting["pins"]["coordination_handoff"]["git_blob_id"] == "7035edf7f20c04d35b7cffd9e967c857fd1ceff0"
assert run_203_reporting["pins"]["coordination_handoff"]["receipt_self_seal_sha256"] == run_203_handoff_seal
assert run_203_reporting["materializer"]["sha256"] == sha256_file("generators/materialize-run-203-fleet-trip-playback-data-point-eligibility-remediation-reporting-wave-42.py")
run_203_builder_payload = git_file_at_commit(
    "09524394fc488e83a960d6c655b6f13095bf86eb",
    "generators/build-current-audit-dashboard.py",
)
assert hashlib.sha256(run_203_builder_payload).hexdigest() == "981030fb81e9ac769f617517702b19f3169865bb535faf9053e873f70ade7ca9"
assert run_203_reporting["pins"]["reporting_sources"]["generators/build-current-audit-dashboard.py"]["sha256"] == "981030fb81e9ac769f617517702b19f3169865bb535faf9053e873f70ade7ca9"
assert run_203_reporting["reporting_transition"]["counts_after"] == {
    "retained_claim_records": 20,
    "provisional_source_claims": 8,
    "historical_already_fixed": 2,
    "historical_remediated": 10,
    "final_P0": 0,
    "final_P1": 0,
}
assert run_203_reporting["reporting_transition"]["static_ownership_or_queue_advance"] is False
assert run_203_reporting["bounded_execution_accounting"]["unique_total"] == {
    "tests": 199,
    "assertions": 2722,
}
assert run_203_reporting["bounded_execution_accounting"]["credited_increment"] == {
    "tests": 1,
    "assertions": 6,
}
assert run_203_reporting["preservation_boundary"]["queue"]["next_zero_based_index"] == 86
assert run_203_reporting["preservation_boundary"]["queue"]["next_route_name"] == "fleet-assets.trips.playback.data"
assert run_203_reporting["dashboard_forward_gate"]["required_run"] == "RUN-204"
assert run_203_reporting["dashboard_forward_gate"]["dashboard_html_changed_by_run_203"] is False
assert run_203_reporting["dashboard_forward_gate"]["preserved_run_202_dashboard_sha256"] == "1876db1ff590c86fb30cefb74368b0241c72d9b75966fcbf1a36d6b1096b30e3"
assert {key for key, value in run_203_reporting["credit_boundary"].items() if value} == {
    "live_findings_register_and_reporting_status",
}
assert len(run_203_reporting["completion_gates"]) == 26
assert not any(row["complete"] for row in run_203_reporting["completion_gates"])
run_203_without_seal = dict(run_203_reporting)
run_203_seal = run_203_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_203_without_seal) == run_203_seal

reviewed_fleet_daily_check_overlay = findings_register["current_static_source_feature_ownership"]

required_artifacts = [
    "00-executive-summary.md", "01-repository-module-map.md",
    "02-eight-pass-coverage-ledger.csv", "03-feature-to-benchmark-matrix.csv",
    "04-workflow-usability-scorecard.csv", "05-browser-visual-coverage-matrix.csv",
    "06-open-source-benchmark-register.csv", "07-module-findings.md",
    "08-cross-module-journeys.md", "09-ui-ux-accessibility-visual-consistency.md",
    "10-architecture-data-integration-security.md", "11-prioritised-roadmap.md",
    "12-native-build-and-do-not-copy-register.md",
    "13-unresolved-questions-and-evidence-gaps.md", "findings.json",
    "inventory.json", "task-scripts", "evidence",
]
required_directories = {"task-scripts", "evidence"}


def required_artifact_present(path: str) -> bool:
    target = AUDIT_DIR / path
    return target.is_dir() if path in required_directories else target.is_file()


required_present = [
    path
    for path in required_artifacts
    if required_artifact_present(path)
]
required_missing = [
    path
    for path in required_artifacts
    if not required_artifact_present(path)
]
assert len(required_artifacts) == 18
assert len(required_present) == 18
assert required_missing == []

atomicity_row_status = (
    "historical issue · already fixed on current main only for the bounded manual-entry register/stock clause "
    "· residual compound scope unadjudicated · not a final finding"
)
safe_row_status = (
    "historical issue · remediated on local main · not published to origin/main · "
    "30-minute dedup contract and +31-minute lifecycle preserved · not a final finding"
)
fleet_row_status = (
    "historical issue · remediated on local main · not published to origin/main · "
    "candidate feature association only · index 84 static route owner and action bridge integrated "
    "separately by RUN-180/R–181 · zero remediation or correctness inheritance · "
    "not a final finding"
)
fleet_playback_row_status = (
    "historical issue · remediated on local main · not published to origin/main · "
    "page/data Site privacy only · candidate feature association only · selected playback/show "
    "route owner and action bridge integrated separately by RUN-190/R · sibling playback.data "
    "at index 86 remains pending · zero remediation or correctness inheritance · not a final finding"
)
fleet_fuel_row_status = (
    "historical issue · remediated on local main · not published to origin/main · "
    "selected GET index/CSV Site privacy and row-scoped attached identity only · candidate feature "
    "association only · index 87 static route owner and action bridge remain pending behind index 86 "
    "playback.data · zero static ownership, adjacent-route, or independent logger-Site inheritance · "
    "not a final finding"
)
summary_row_status = (
    "historical issue · remediated on local main · not published to origin/main · "
    "shared-Site staff Summary/timeline authorization and queued requester revalidation only · "
    "feature unassigned · zero static ownership, adjacent-surface, My Day, browser, benchmark, "
    "publication, or completion inheritance · not a final finding"
)
shift_task_row_status = (
    "historical issue · remediated on local main · not published to origin/main · "
    "scheduler-time and queued-delivery recipient revalidation only · feature unassigned · "
    "delegated coordination transcription, not an original runtime receipt · zero static ownership, "
    "browser, benchmark, publication, or completion inheritance · not a final finding"
)
elig_shift_row_status = (
    "historical issue · remediated on local main · not published to origin/main · "
    "current approved canonical-Shift-Site eligibility-alert recipients and one canonical current Shift payload snapshot only · "
    "feature unassigned · delegated coordination transcription, not an original runtime receipt · zero static ownership, "
    "browser, benchmark, publication, or completion inheritance · not a final finding"
)
fleet_playback_data_row_status = (
    "historical issue · remediated on local main · not published to origin/main · "
    "coordinate-complete playback rows before ordering and the 2,000-point cap only · "
    "candidate feature association only · playback.data index 86 remains pending fresh semantic review · "
    "delegated coordination transcription, not an original runtime receipt · zero static ownership, queue, "
    "prior privacy, telemetry lifecycle/range, map/frontend, adjacent Fleet, browser, benchmark, publication, "
    "or completion inheritance · not a final finding"
)
finding_claim_labels = dict(historical_discovery_claims)
finding_claim_labels["FLEET-TRIP-INDEX-SITE-PRIVACY-01"] = fleet_finding["impact"]
finding_claim_labels["FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01"] = fleet_playback_finding["impact"]
finding_claim_labels["FLEET-FUEL-INDEX-SITE-PRIVACY-01"] = fleet_fuel_finding["impact"]
finding_claim_labels["MON-METRIC-REPLAY-DEDUPE-01"] = metric_finding["impact"]
finding_claim_labels["SUMMARY-TIMELINE-SITE-PRIVACY-01"] = summary_finding["impact"]
finding_claim_labels["SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01"] = shift_task_finding["impact"]
finding_claim_labels["ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01"] = elig_shift_finding["impact"]
finding_claim_labels["FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01"] = fleet_playback_data_finding["impact"]
assert set(finding_claim_labels) == {row["id"] for row in live_findings}
finding_rows = "".join(
    "<tr><td class=\"mono\">{}</td><td>{}</td><td class=\"partial\">{}</td></tr>".format(
        html.escape(row["id"]),
        html.escape(finding_claim_labels[row["id"]]),
        (
            atomicity_row_status
            if row["id"] == "MED-CD-ATOMICITY-01"
            else (
                safe_row_status
                if row["id"] == "SAFE-ALERT-DEDUP-IDENTITY-01"
                else (
                    fleet_row_status
                    if row["id"] == "FLEET-TRIP-INDEX-SITE-PRIVACY-01"
                    else (
                        fleet_playback_row_status
                        if row["id"] == "FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01"
                        else (
                            fleet_fuel_row_status
                            if row["id"] == "FLEET-FUEL-INDEX-SITE-PRIVACY-01"
                            else (
                                summary_row_status
                                if row["id"] == "SUMMARY-TIMELINE-SITE-PRIVACY-01"
                                else (
                                    shift_task_row_status
                                    if row["id"] == "SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01"
                                    else (
                                        elig_shift_row_status
                                        if row["id"] == "ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01"
                                        else (
                                            fleet_playback_data_row_status
                                            if row["id"] == "FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01"
                                            else (
                                                "historical issue · already fixed on current main · not a final finding"
                                                if row["record_status"] == "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING"
                                                else (
                                                    "historical issue · remediated on current main · not a final finding"
                                                    if row["record_status"] == "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
                                                    else "current provisional P1 · independent review pending"
                                                )
                                            )
                                        )
                                    )
                                )
                            )
                        )
                    )
                )
            )
        ),
    )
    for row in live_findings
)
expected_atomicity_row = (
    '<tr><td class="mono">MED-CD-ATOMICITY-01</td><td>'
    f'{html.escape(historical_discovery_claims["MED-CD-ATOMICITY-01"])}</td>'
    f'<td class="partial">{atomicity_row_status}</td></tr>'
)
assert expected_atomicity_row in finding_rows
assert finding_rows.count(atomicity_row_status) == 1
assert finding_rows.count(safe_row_status) == 1
assert finding_rows.count(fleet_row_status) == 1
assert finding_rows.count(fleet_playback_row_status) == 1
assert finding_rows.count(fleet_fuel_row_status) == 1
assert finding_rows.count(summary_row_status) == 1
assert finding_rows.count(shift_task_row_status) == 1
assert finding_rows.count(elig_shift_row_status) == 1
assert finding_rows.count(fleet_playback_data_row_status) == 1
expected_metric_row = (
    '<tr><td class="mono">MON-METRIC-REPLAY-DEDUPE-01</td><td>'
    f'{html.escape(metric_finding["impact"])}</td>'
    '<td class="partial">historical issue · remediated on current main · not a final finding</td></tr>'
)
assert expected_metric_row in finding_rows

architecture_rows = "".join(
    "<tr><td class=\"mono\">{}</td><td>{}</td><td>{}</td><td class=\"partial\">source-only; promotion gate open</td></tr>".format(
        html.escape(row["id"]),
        html.escape(row["severity"]),
        html.escape(row["title"]),
    )
    for row in architecture_evidence["provisional_claims"]
)

module_rows = "".join(
    "<tr><td>{}</td><td>{}</td><td>{}</td><td>{}</td><td>{}</td></tr>".format(
        html.escape(module),
        sum(1 for row in targets if row["module"] == module and row["feature_class"] == "H"),
        sum(1 for row in targets if row["module"] == module and row["feature_class"] == "D"),
        sum(1 for row in targets if row["module"] == module and row["feature_class"] == "M"),
        sum(1 for row in targets if row["module"] == module),
    )
    for module in module_labels
)


TEMPLATE = Template(r"""<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="icon" href="data:,">
  <title>Oblivion Findings current-source audit</title>
  <style>
    :root{color-scheme:light;--ink:#172033;--muted:#5f6b7d;--line:#dce2ec;--panel:#fff;--bg:#f4f6fb;--brand:#5b55f6;--warn:#a04800;--warnbg:#fff2db;--shadow:0 8px 24px rgba(27,35,58,.07)}
    *{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}a{color:#413ad8;text-decoration-thickness:1px;text-underline-offset:3px}a:focus-visible{outline:3px solid #8d88ff;outline-offset:3px;border-radius:4px}
    header{background:linear-gradient(135deg,#1c2140 0%,#3f399f 100%);color:#fff;padding:28px max(20px,calc((100vw - 1180px)/2)) 32px}.eyebrow{font-size:.78rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#cbc9ff}.hero{display:flex;gap:24px;align-items:end;justify-content:space-between}.hero h1{font-size:clamp(1.8rem,4vw,3rem);line-height:1.08;margin:7px 0 8px;max-width:820px}.hero p{margin:0;color:#e5e4ff;max-width:780px}.badge{display:inline-flex;white-space:nowrap;align-items:center;border:1px solid #f2c675;background:#3e2b18;color:#ffe5b5;border-radius:999px;padding:9px 13px;font-weight:800}
    nav{background:#fff;border-bottom:1px solid var(--line);position:sticky;top:0;z-index:4;overflow:auto}nav div{max-width:1180px;margin:auto;display:flex;gap:20px;padding:11px 20px;white-space:nowrap}nav a{color:#39445a;font-weight:700;text-decoration:none}main{max-width:1180px;margin:0 auto;padding:24px 20px 64px}.notice{background:var(--warnbg);border-left:5px solid #e58d22;padding:14px 16px;border-radius:10px;margin-bottom:22px;color:#633000}
    .cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.card,.panel{background:var(--panel);border:1px solid var(--line);box-shadow:var(--shadow);border-radius:14px}.card{padding:17px}.card strong{display:block;font-size:1.65rem;line-height:1.15}.card span{display:block;color:var(--muted);margin-top:5px}.card small{display:block;margin-top:9px;color:#717d90}.panel{min-width:0;padding:20px;margin-top:20px}.panel h2{font-size:1.25rem;margin:0 0 5px}.panel>p{color:var(--muted);margin:0 0 16px}.table-wrap{max-width:100%;overflow-x:auto;border:1px solid var(--line);border-radius:10px}table{width:100%;border-collapse:collapse;min-width:680px}th,td{text-align:left;padding:11px 12px;border-bottom:1px solid var(--line);vertical-align:top}th{background:#f7f8fc;color:#414d62;font-size:.82rem}tr:last-child td{border-bottom:0}.zero{color:#a03920;font-weight:800}.partial{color:var(--warn);font-weight:800}.split{display:grid;grid-template-columns:1.15fr .85fr;gap:20px}.split>*{min-width:0}.list{margin:0;padding-left:20px}.list li,.list code{overflow-wrap:anywhere}.list li+li{margin-top:8px}.mono{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:.88em;overflow-wrap:anywhere}.footer{color:var(--muted);font-size:.88rem;margin-top:24px;overflow-wrap:anywhere}
    @media(max-width:900px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.split{grid-template-columns:1fr}.hero{align-items:flex-start;flex-direction:column}.badge{align-self:flex-start}}@media(max-width:520px){header{padding:22px 16px 26px}main{padding:18px 14px 48px}.cards{grid-template-columns:1fr 1fr;gap:10px}.card{padding:14px}.card strong{font-size:1.35rem}.panel{padding:16px}.notice,.panel>p{overflow-wrap:anywhere}.badge{white-space:normal}nav div{padding-inline:16px}}
  </style>
</head>
<body>
  <header><div class="eyebrow">Oblivion Findings · comprehensive audit restart</div><div class="hero"><div><h1>Fresh current-source audit</h1><p>The frozen census and discovery baseline remains pinned to <span class="mono">a0493442b9e3</span>; the latest bounded MED-RBAC adjudication is pinned to <span class="mono">$application_short</span>. Historical percentages remain provenance only.</p></div><div class="badge">IN PROGRESS · NOT COMPREHENSIVE</div></div></header>
  <nav aria-label="Audit sections"><div><a href="#progress">Progress</a><a href="#checkpoint">RUN-160</a><a href="#pages">Pages</a><a href="#static-census">Static census</a><a href="#runtime">Runtime gates</a><a href="#benchmarks">Benchmarks</a><a href="#modules">Modules</a><a href="#findings">Finding status</a><a href="#architecture">Architecture</a><a href="#gaps">Gaps</a></div></nav>
  <main>
    <div class="notice" role="status"><strong>No completion claim:</strong> RUN-030 freezes 340 current-source static canonical targets (300 H · 40 D · 0 M). RUN-034–038 retain 88 complete observer-only and 7 partial project records without formal triage credit. RUN-039–046 approve 0 formal edges for the first six-target overlay. RUN-047–052 remain an immutable historical diagnostic checkpoint with a missing clean Agent A-to-B handoff. RUN-053–057 reconstruct that handoff through 24 selected facet packets (8 exact · 4 partial-adjacent · 12 insufficient-adjacent), 252 blind atoms (165 consumed · 87 retained unknown), 144 fresh-C lens ratings, and 226 fresh-D reviews. D accepts 225 reviews and makes one bounded correction to AO-A53-024-01; it creates 0 formal edges and 0 final no-matches. RUN-058-BROWSER–060 preserve a read-only signed-in observation of $unknown_build_routes selected routes, $unknown_build_cells route/viewport cells, and $unknown_build_overlays overlay families on an unattributed deployed build; $unknown_build_candidates provisional candidates remain unknown-build only. Formal upstream RUN-058A–070 preserves $formal_attempts initial project records across $formal_targets selected targets and $formal_subrecords initial facet/aspect records; independent controls accept $formal_accepted formal project records and $formal_facets bounded facet records, while all target edges and final no-matches remain zero. RUN-077–080 record $route_primary_calls primary route-facade callsites plus one separate route-like sentinel, $route_name_calls fluent-name callsites, and $route_page_roots page roots; three cyclic independent reviews are GO, but $route_unmapped route-like rows and $page_evidence_gaps page roots retain explicit evidence gaps. RUN-080 changes only $route_page_rows_changed rows / $route_page_field_changes route-name or page-file fields. RUN-082 adds candidate relations only: route names $candidate_name_one single · $candidate_name_many multiple · $candidate_name_zero none; exact controller-method containment $candidate_backend_one single · $candidate_backend_many multiple · $candidate_backend_zero none across $candidate_exact_actions resolved actions, with $candidate_non_exact_actions non-exact retained; page render-owner containment $candidate_page_one single · $candidate_page_many multiple · $candidate_page_zero none. RUN-082R independently reproduced the candidate census and static registration closure with zero discrepancies and returned GO limited to candidate-only static evidence; feature mapping, matrix mutation, and downstream integration remain unauthorized. RUN-084/R then independently close the $full_page_tree_files-file page-tree structural ledger ($full_page_production = $full_page_roots + $full_page_support + $full_page_nonroots), and RUN-084B/BR independently close the $backend_role_rows-row backend structural ledger while whole-file semantic review stays 0. RUN-089 confirms the controlled application tab is still signed out and build-unattributed. RUN-091/R and RUN-092/R remain the historical nine-chain overlay. RUN-097/R–100 remain the historical 23-owner route/action checkpoint and its exact superseded dashboard verification. RUN-101/R–140 remain historical route/action, page-owner, reporting, and exact dashboard checkpoints. RUN-141/R review finance.api.sites.overview as one explicit JSON route/action owner; RUN-153/R establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges. Exactly one fleet-assets.vehicles.index route owner and one bridge are added; existing page-owner and sentinel context are not inherited or recredited, index 82 is context only, and index 83 remains unresolved. Route/page/overlap sets are $route_feature_ids/$page_feature_ids/$route_page_overlap; six provisional source observations remain separate from the 12 provisional findings and retain zero correctness or final-finding credit. $static_residual records remain and Gate 4 is open. Oblivion Findings remains one operating organisation across multiple Sites; Site access, roles/permissions, canonical ownership, direct-object denial, privacy, query, projection, period, allocation provenance or reversal, utility true-up sign, response minimization, lifecycle, concurrency, and event or downstream durability correctness remain separate unproved gates. RUN-145 changes exactly two benchmark rows / 18 cells after the historical RUN-080 linkage checkpoint: the live matrix is <span class="mono">$live_matrix_short</span>, target-specific mapping is $benchmark_mapped/340, final no-match/NCM is $final_no_matches/340, and $benchmark_unresolved targets remain unresolved. RUN-155 verifies only the exact now-superseded RUN-154 dashboard. RUN-156/R record and independently review bounded two-checkpoint medication-governance Git/source provenance; RUN-157 reports that receipt without changing any current count. Current-source framework reachability, medication correctness, runtime, browser, build, rendered visual, executed-test, test-coverage, ease, release, Pass, final-finding, feature-completion, remote-currency, publication, and audit-completion credit remain zero.</div>
    <div class="notice" role="status"><strong>RUN-071–157 current reporting checkpoint:</strong> all 26 completion gates are reconciled. RUN-071's 9/18 and RUN-072's 11/18 are historical snapshots; RUN-073 has all 18 prompt-required files/directories present, including <span class="mono">evidence/</span> and excluding this generated dashboard. Presence is not completion. RUN-072 retains 300/300 source-bound H contracts pinned to the historical base matrix, with 0 validated tasks and every current/target measurement <span class="mono">NOT_MEASURED</span>; their copied locators were not silently refreshed. RUN-073 adds 8 independently source-reviewed journeys and separately reviewed architecture evidence. RUN-074–076 reconstruct feature-side linkage. RUN-077–079 materialize and independently review the exhaustive committed static route/name/page universe; RUN-080 integrates 78 route-name and 2 page-file fields; RUN-081 refreshes reports and hashes. RUN-082 materializes static candidate relations and 38/38 route-file source registration closure; RUN-082R independently reproduces them with zero discrepancies and GO limited to candidate-only static evidence; RUN-083 refreshes five reports while preserving five byte-identical and its exact dashboard receives an artifact-only GO receipt. RUN-084/R independently enumerate and review $full_page_tree_files physical page-tree files, including the production partition $full_page_production = $full_page_roots roots + $full_page_support imported supports + $full_page_nonroots adjudicated non-roots. RUN-084B/BR independently enumerate and structurally review $backend_role_rows backend role rows across $backend_unique_paths unique source paths; all remain <span class="mono">Evidence gap</span> with 0 whole-file semantic reviews. The current designated-application preflight is signed out and build-unattributed, so it adds no application-browser credit. RUN-082 historically changed 0 rows / 0 cells at its candidate-only checkpoint; the current matrix retains $route_gap_count route-path, $route_name_gap_count route-name, $page_gap_count page-file, $both_gap_count combined route/page, $backend_gap_count backend-anchor, and $test_gap_count test-anchor gaps. Missing dependencies, build provenance, database isolation, and attributable authenticated access keep framework/runtime/build/test/browser lanes NO-GO and not executed. RUN-090 freezes $queue_records candidate rows without wholesale ownership. RUN-091/R–092/R remain the historical nine-owner/two-shared overlay. RUN-097/R–100 remain the historical 23-owner checkpoint and dashboard receipt. RUN-101/R–112 remain historical route/action and page-owner checkpoints with exact dashboard receipts. RUN-113/R–140 preserve historical route/action and page-owner checkpoints with exact dashboard receipts. RUN-141/R–142/R add one finance.api.sites.overview JSON route owner and one bridge, inherit or recredit no page, sibling, caller, reviewed-neighbor, or next-row ownership, and add zero union or matrix credit; $queue_reviewed queue rows are reviewed, $queue_pending remain pending, and $queue_without_owner remain without ownership. RUN-143/R–147 preserve the Site-portfolio, benchmark, reporting, and exact-dashboard checkpoints. RUN-151 preserves the exact superseded dashboard receipt; RUN-152/R review one Fleet vehicle-register index owner; RUN-153/R add exactly one route owner and one bridge with six provisional-not-final observations and every correctness boundary false; RUN-154 materializes the Fleet reporting, RUN-155 verifies that exact now-superseded dashboard, RUN-156/R record and independently review a two-checkpoint medication-governance Git/source receipt through coordinated, explicitly non-blind/non-isolated lanes, and RUN-157 materializes current reporting without changing Fleet, queue, benchmark, finding, or completion counts. The framework-expanded denominator, residual ownership, full route/page/backend crosswalk, and 338 benchmark targets remain open. Medication correctness, runtime, application-browser, executed-test, test-coverage, ease, release, Pass, final-finding, feature-completion, remote-currency, publication, and audit-completion credit remain zero.</div>
    <section id="progress" class="cards" aria-label="Current audit progress">
      <div class="card"><strong>8,454</strong><span>tracked source paths</span><small>committed-tree census</small></div><div class="card"><strong>$route_calls</strong><span>static route callsites</span><small>not runtime routes</small></div><div class="card"><strong>$page_root_count</strong><span>static Inertia page roots</span><small>$resolver_count paths partitioned; prompt gate open</small></div><div class="card"><strong>$canonical_count</strong><span>canonical static targets</span><small>$h_count H · $d_count D · $m_count M</small></div>
      <div class="card"><strong>$mapped_sources / $source_count</strong><span>discovery sources mapped</span><small>one bounded source excluded</small></div><div class="card"><strong class="partial">$finding_count</strong><span>current provisional P1 claims</span><small>$historical_fixed_count retained historical already-fixed · none final</small></div><div class="card"><strong>$bounded_tests</strong><span>bounded MED-RBAC tests</span><small>$bounded_assertions assertions · no full-suite or coverage credit</small></div><div class="card"><strong class="zero">0</strong><span>current-source browser routes</span><small>$unknown_build_routes unknown-build routes observed; attribution unproved</small></div>
    </section>
    <section id="checkpoint" class="panel">
      <h2>RUN-071–160 completion-gate checkpoint</h2>
      <p>The 26 literal completion gates were reconciled before RUN-072 added source-bound usability and incident-chain evidence, RUN-073 materialized reporting paths and source synthesis, RUN-074–076 reconstructed bounded feature-side linkage, RUN-077–081 materialized, independently reviewed, integrated, and reported the exhaustive committed static route/name/page universe, RUN-082/R added an independently reviewed candidate-only relation census, RUN-083 refreshed and verified the audit dashboard, and RUN-084/R/B/BR added independently reviewed page-tree and backend structural ledgers plus a signed-out designated-application preflight, RUN-086/R add the initial independently reviewed bounded ownership, RUN-089 repeats the signed-out preflight, RUN-090–092/R queue, review, integrate, and independently verify nine closed chains while retaining two shared, RUN-097/R–112 preserve the historical route/action and page-owner checkpoints with dashboard verification, RUN-113/R–140 preserve historical reviewed route/action and page-owner checkpoints with exact dashboard receipts; RUN-141/R–151 preserve the Site-portfolio, benchmark, reporting, and exact-dashboard checkpoints. RUN-152/R independently review one fleet-assets.vehicles.index route/action candidate; both reviewers are non-blinded, reviewer A had prior team-status visibility, reviewer B had prior self-assessment visibility, neither consulted the other, and both completed independent evidence traces. RUN-153/R add one route owner and one bridge, preserve six provisional-not-final observations separately from 12 provisional findings, preserve page/sentinel/neighbor noninheritance, and keep every correctness boundary and Gate 4 false. RUN-154 refreshes the Fleet reporting; RUN-155 verifies only that exact now-superseded dashboard. RUN-156/R record and independently review a two-checkpoint medication-governance Git/source receipt through three coordinated, explicitly non-blind/non-isolated lanes; RUN-157 reports only that bounded receipt class while every Fleet, queue, benchmark, finding, and completion count remains unchanged. Static relation, structural classification, registration, public/login, Git/source-receipt, or audit-dashboard artifacts are not measured task, medication correctness, framework reachability, attributable application browser, runtime, Pass, final-finding, feature-completion, or completion evidence; only the exact two RUN-145 static benchmark mappings receive mapping credit.</p>
      <div class="table-wrap"><table><thead><tr><th>Gate slice</th><th>Current denominator</th><th>Evidence boundary</th></tr></thead><tbody>
        <tr><td>Required reporting paths present</td><td><strong>$required_present / 18</strong></td><td class="partial">$required_missing absent · file/directory presence only · dashboard excluded · no completion credit</td></tr>
        <tr><td>H-feature task contracts</td><td><strong>$task_contracts / 300</strong></td><td class="partial">historical RUN-072 source-bound snapshot at the base matrix · copied linkage locators not refreshed · $validated_tasks representative-role validations · all 3,000 current and 3,000 target cells are <span class="mono">NOT_MEASURED</span> sentinels, not numeric zero · 0 independent ease reviews/credit</td></tr>
        <tr><td>Materialization review</td><td><strong>GO after bounded correction</strong></td><td class="partial">independent materialization review after governing-prompt and root-instruction input pins were added; 0 representative-role validation</td></tr>
        <tr><td>Three-target route/page ownership slice</td><td><strong>$route_slice_targets targets · $route_slice_routes primary routes</strong></td><td class="partial">static source only · 0 framework route executions</td></tr>
        <tr><td>Current-build frontline browser slice</td><td><strong class="zero">0 / 3 authenticated routes</strong></td><td class="zero">only <span class="mono">/my-day</span> attempted; both contexts ended at <span class="mono">/login</span>; no credentials, mutations, screenshots, base cells, or pre-submit cells</td></tr>
        <tr><td>Incident clean chain</td><td><strong>2 packets · 48 observations · 39 requirements</strong></td><td class="partial">5 MET · 3 PARTIAL · 31 NOT_COMPARABLE; review, closure, and combined target all NO-GO for this candidate edge only</td></tr>
        <tr><td>Cross-module journeys</td><td><strong>8 journeys · 44 handoffs</strong></td><td class="partial">27 PROVEN · 8 PARTIAL · 9 NOT_ESTABLISHED · 8/8 independent source reviews · 0/8 prompt-grade executions</td></tr>
        <tr><td>Architecture/data/integration/security</td><td><strong>13 entity families · 17 concerns</strong></td><td class="partial">9 provisional source claims (7 P1 · 2 P2) · 10 NOT_ESTABLISHED · 0 final/runtime findings</td></tr>
        <tr><td>RUN-076 static-linkage integration</td><td><strong>$static_rows_changed rows · $static_field_changes fields</strong></td><td class="partial">only route names/paths, page files, backend anchors, and static test locators · immutable and benchmark/credit projections unchanged</td></tr>
        <tr><td>RUN-077–079 route/page universe and review</td><td><strong>$route_like_rows route-like · $route_name_calls names · $route_page_roots pages</strong></td><td class="partial">3 cyclic GO reviews · $route_unmapped explicit unmapped routes · $page_evidence_gaps page evidence gaps · zero downstream credit</td></tr>
        <tr><td>RUN-080 route/page linkage integration</td><td><strong>$route_page_rows_changed rows · $route_page_field_changes fields</strong></td><td class="partial">$route_name_established route names · $page_file_established page files · immutable and benchmark/credit projections unchanged</td></tr>
        <tr><td>RUN-082 candidate relation census</td><td><strong>$candidate_route_rows routes · $candidate_page_rows pages</strong></td><td class="partial">names $candidate_name_one/$candidate_name_many/$candidate_name_zero single/multiple/none · controller $candidate_backend_one/$candidate_backend_many/$candidate_backend_zero · render owner $candidate_page_one/$candidate_page_many/$candidate_page_zero · RUN-082R GO candidate-only · 0 discrepancies · no mapping/matrix/downstream authority</td></tr>
        <tr><td>RUN-082 static registration / execution preflight</td><td><strong>$candidate_registered_routes / $route_file_count route files</strong></td><td class="zero">$candidate_direct_routes bootstrap + $candidate_web_routes web requires · 0 framework route tables · runtime/build/tests/application browser NO-GO and not executed</td></tr>
        <tr><td>RUN-083 reporting refresh</td><td><strong>5 changed · 5 byte-preserved reports</strong></td><td class="partial">matrix 0 rows / 0 cells changed · all downstream credit zero</td></tr>
        <tr><td>RUN-083 audit-dashboard verification</td><td><strong>4 / 4 viewports · 172 / 172 links</strong></td><td class="partial">exact superseded dashboard artifact GO · 0 application-browser or downstream credit</td></tr>
        <tr><td>RUN-084/R full page-tree graph</td><td><strong>$full_page_tree_files files · $full_page_production production TSX</strong></td><td class="partial">$full_page_roots roots + $full_page_support imported supports + $full_page_nonroots adjudicated non-roots · independent GO · 0 feature mappings/runtime/browser</td></tr>
        <tr><td>RUN-084B/BR backend role ledger</td><td><strong>$backend_role_rows rows · $backend_unique_paths paths</strong></td><td class="partial">$backend_models models · $backend_policies policies · $backend_services services · $backend_async_rows overlapping async roles · independent GO structural only · 0 whole-file semantic review</td></tr>
        <tr><td>RUN-084 historical designated application preflight</td><td><strong>public + signed-out login only</strong></td><td class="zero">1280×720 · no page overflow or console warnings/errors · no credentials/forms/screenshots · build and non-production identity unproved · 0 application-browser credit</td></tr>
        <tr><td>RUN-085 reporting refresh</td><td><strong>current page/backend/preflight boundaries</strong></td><td class="partial">audit-only materialization · matrix and preserved reports byte-identical · fresh dashboard receipt linked separately</td></tr><tr><td>RUN-086/R static source feature ownership</td><td><strong>530 records · 212 routes + 318 pages · 235 FEATURE-IDs</strong></td><td class="partial">bounded source ownership only · Gate 4 incomplete · 0 framework/runtime/browser/test/benchmark/Pass/completion credit</td></tr><tr><td>RUN-087 reporting refresh</td><td><strong>initial bounded ownership reported</strong></td><td class="partial">audit-only materialization · matrix and preserved reports byte-identical</td></tr><tr><td>RUN-089 application preflight</td><td><strong>public + signed-out login only</strong></td><td class="zero">earlier login absent in controlled tab · no credentials/forms/private records/screenshots · build and non-production identity unproved</td></tr><tr><td>RUN-090 direct-exact review queue</td><td><strong>$queue_records candidate surfaces</strong></td><td class="partial">queue itself grants no wholesale ownership · current overlay: $queue_owner owned · $queue_shared shared · $queue_alias alias · $queue_pending unreviewed · $queue_without_owner without ownership</td></tr><tr><td>RUN-091/R → 092/R closed-chain overlay</td><td><strong>9 owner chains · 2 shared · 18 owner rows · 9 action bridges</strong></td><td class="partial">548 cumulative owners · 221 routes + 327 pages · 239 FEATURE-IDs · historical bounded checkpoint</td></tr><tr><td>RUN-093 reporting refresh</td><td><strong>historical reviewed overlay reported</strong></td><td class="partial">audit-only materialization · superseded dashboard separately verified by RUN-094</td></tr><tr><td>RUN-097/R → 098/R historical route/action overlay</td><td><strong>23 owner route/actions · 23 route rows · 23 action bridges · 0 page rows</strong></td><td class="partial">571 cumulative owners · 244 routes + 327 pages · 246 FEATURE-IDs · historical bounded checkpoint</td></tr><tr><td>RUN-099 / RUN-100 historical reporting and dashboard</td><td><strong>route/action overlay reported and exact dashboard verified</strong></td><td class="partial">audit-only history · no verification metrics transfer</td></tr><tr><td>RUN-101/R → 102/R historical outcome-neutral overlay</td><td><strong>24 reviewed · 21 owner route/actions · 3 aliases · 21 route rows · 21 action bridges · 0 page rows</strong></td><td class="partial">592 cumulative owners · 265 routes + 327 pages · 249 FEATURE-IDs · historical bounded checkpoint</td></tr><tr><td>RUN-103 / RUN-104 historical reporting and dashboard</td><td><strong>route/action overlay reported and exact dashboard verified</strong></td><td class="partial">audit-only history · no verification metrics transfer</td></tr><tr><td>RUN-105/R → 106/R historical page render-owner overlay</td><td><strong>24 reviewed · 20 owner pages · 3 shared · 1 evidence gap · 20 page rows · 0 route/bridge rows</strong></td><td class="partial">612 cumulative owners · 265 routes + 347 pages · 256 FEATURE-IDs · historical bounded checkpoint</td></tr><tr><td>RUN-107 / RUN-108 historical reporting and dashboard</td><td><strong>page-owner overlay reported and exact dashboard verified</strong></td><td class="partial">audit-only history · no verification metrics transfer</td></tr><tr><td>RUN-109/R → 112 historical page render-owner tail</td><td><strong>6 reviewed · 2 owner pages · 4 shared · 0 evidence gap · 2 page rows · 0 route/bridge rows · 1 queue-shared row</strong></td><td class="partial">614 cumulative owners · 265 routes + 349 pages · 256 FEATURE-IDs · exact superseded dashboard verified</td></tr><tr><td>RUN-113/R → 116 historical name-only route/action overlay</td><td><strong>$route_wave_reviewed reviewed = $route_review_owner owner + $route_review_alias alias + $route_review_shared shared + $route_review_dead dead + $route_review_gap gap · 23 route rows · 23 action bridges · 0 page rows</strong></td><td class="partial">637 cumulative owners · 288 routes + 349 pages · 256 FEATURE-IDs · exact superseded dashboard verified</td></tr><tr><td>RUN-115 / RUN-116 historical reporting and dashboard</td><td><strong>name-only route/action overlay reported and exact dashboard verified</strong></td><td class="partial">audit-only history · no verification metrics transfer</td></tr><tr><td>RUN-117/R → 120 historical Respite handover page overlay</td><td><strong>$respite_page_wave_reviewed reviewed = $respite_page_review_owner owner pages · 4 page rows · 0 route/bridge/queue rows</strong></td><td class="partial">641 cumulative owners · exact superseded dashboard verified</td></tr><tr><td>RUN-121/R → 124 historical Finance route/action overlay</td><td><strong>$finance_wave_reviewed reviewed · 7 owner + 7 shared + 1 alias + 7 gap · 7 route rows · 7 bridges</strong></td><td class="partial">648 cumulative owners · exact superseded dashboard verified</td></tr><tr><td>RUN-125/R → 128 historical Finance page-gap overlay</td><td><strong>$finance_page_wave_reviewed reviewed = $finance_page_review_owner owner pages · 4 page rows · 0 route/bridge/queue rows</strong></td><td class="partial">652 cumulative owners · exact superseded dashboard verified</td></tr><tr><td>RUN-129/R → 132 historical FX revaluation route/action overlay</td><td><strong>$finance_fx_wave_reviewed reviewed = $finance_fx_review_owner owner actions · 2 route rows · 2 bridges · 0 page rows</strong></td><td class="partial">654 cumulative owners · exact superseded dashboard verified</td></tr><tr><td>RUN-133/R → 136 historical accounting-integration route/action overlay</td><td><strong>$finance_accounting_wave_reviewed reviewed = $finance_accounting_review_owner owner actions · 6 route rows · 6 bridges · 0 page rows</strong></td><td class="partial">660 cumulative owners · exact superseded dashboard verified</td></tr><tr><td>RUN-137/R → 140 historical invoice-index route/action overlay</td><td><strong>1 reviewed owner action · 1 route row · 1 bridge · 0 page rows</strong></td><td class="partial">661 cumulative owners · exact superseded dashboard verified</td></tr><tr><td>RUN-141/R → 142/R historical Site-portfolio API route/action overlay</td><td><strong>$finance_site_wave_reviewed reviewed = $finance_site_review_owner owner action · 1 route row · 1 bridge · 0 page rows</strong></td><td class="partial">662 historical cumulative owners · exact bounded checkpoint</td></tr><tr><td>RUN-143 reporting refresh</td><td><strong>Site-portfolio API route/action overlay reported</strong></td><td class="partial">audit-only historical checkpoint · matrix then byte-identical</td></tr><tr><td>RUN-144 audit-dashboard verification</td><td><strong>4/4 required viewports · 23/23 visible checks · 10/10 navigation</strong></td><td class="partial">exact superseded dashboard artifact only · zero application credit</td></tr><tr><td>RUN-145 Finance benchmark chain</td><td><strong>$benchmark_mapped/340 mapped · $final_no_matches/340 NCM · $benchmark_unresolved unresolved</strong></td><td class="partial">two exact static target mappings only · matrix $live_matrix_short · register $live_register_short</td></tr><tr><td>RUN-146 reporting/dashboard refresh</td><td><strong>matrix, register, reports and evidence reconciled</strong></td><td class="partial">historical reporting checkpoint · zero application credit</td></tr><tr><td>RUN-147 dashboard verification</td><td><strong>exact RUN-146 dashboard verified</strong></td><td class="partial">superseded audit artifact only · zero application credit</td></tr><tr><td>RUN-151 dashboard verification</td><td><strong>exact RUN-150 dashboard verified at 4/4 viewports</strong></td><td class="partial">superseded audit artifact only · zero application credit</td></tr><tr><td>RUN-152/R candidate review</td><td><strong>1 Fleet vehicle-register index candidate · 6 provisional observations</strong></td><td class="partial">provisional-not-final · no ownership or correctness credit</td></tr><tr><td>RUN-153/R current Fleet vehicle-register overlay</td><td><strong>664 owners · 307 routes + 357 pages · 95 bridges</strong></td><td class="partial">118 reviewed / 389 pending · 96 owners / 411 without ownership · Gate 4 false</td></tr><tr><td>RUN-154 Fleet reporting refresh</td><td><strong>16.899975% bounded ownership · 2/340 mapped · 338 unresolved</strong></td><td class="partial">historical reporting checkpoint · exact dashboard subsequently verified by RUN-155</td></tr><tr><td>RUN-155 exact audit-dashboard verification</td><td><strong>4/4 viewports · 38/38 visible checks · 10/10 navigation · 379/379 unique local links</strong></td><td class="partial">exact now-superseded RUN-154 dashboard only · zero console/page errors · zero application credit</td></tr><tr><td>RUN-156/R medication-governance source receipt</td><td><strong>359 historical payload paths · 358 unchanged effective blobs + 1 superseded · 3 coordinated review lanes</strong></td><td class="partial">local Git/source provenance and reporting authorization only · provisional medication records and all outcome/publication credit remain zero</td></tr><tr><td>RUN-157 current reporting refresh</td><td><strong>664 owners · 307 routes + 357 pages · 95 bridges · 2/340 mapped · 338 unresolved</strong></td><td class="partial">counts unchanged · fresh RUN-158 dashboard verification required · Gate 4 and audit completion false</td></tr></tbody></table></div>
	      <ul class="list">$checkpoint_evidence_links</ul>
	    </section>
	    <section class="panel"><h2>RUN-158–160 current adjudication checkpoint</h2><div class="table-wrap"><table><thead><tr><th>Run</th><th>Observed evidence</th><th>Boundary</th></tr></thead><tbody><tr><td>RUN-158 exact dashboard verification</td><td><strong>4/4 viewports · 50/50 visible checks · 10/10 navigation · 387/387 local resources</strong></td><td class="partial">exact superseded RUN-157 dashboard only · zero application credit</td></tr><tr><td>RUN-159 MED-RBAC adjudication</td><td><strong>3 independent current-source ALREADY_FIXED reviews · $bounded_tests tests · $bounded_assertions assertions</strong></td><td class="partial">MED-RBAC-only ALREADY_FIXED disposition established · no application change · no scope/atomicity inheritance · no final finding or completion credit</td></tr><tr><td>RUN-159R exact receipt review</td><td><strong>exact corrected receipt GO</strong></td><td class="partial">retirement reporting authorized · live-register reconciliation belongs to RUN-160 · zero application or downstream credit</td></tr><tr><td>RUN-160 live reporting</td><td><strong>$finding_count current provisional P1 + $historical_fixed_count historical already-fixed · 12 retained identities</strong></td><td class="partial">MED-RBAC-01 reclassified from current provisional to retained historical already-fixed · Gate 4 and audit completion false · exact regenerated dashboard requires RUN-161 verification</td></tr></tbody></table></div></section>
	    <section id="pages" class="panel"><h2>Current static Inertia page partition</h2><p>RUN-084 enumerates all $full_page_tree_files physical files under the pinned <span class="mono">resources/js/pages</span> tree and RUN-084R independently reproduces every path, blob, content hash, row identity, partition, and import-graph boundary with zero discrepancies. The production TSX partition is exactly $full_page_production = $full_page_roots literal rendered roots + $full_page_support imported support components + $full_page_nonroots adjudicated unrendered/unimported non-roots. This supersedes older wording that called the 25 non-roots “resolver-imported.” RUN-082R's separate $candidate_page_rows evidence-gap relation census remains candidate-only. Neither structural GO establishes canonical FEATURE-ID ownership, framework reachability, build resolution, or rendered browser behavior.</p><div class="table-wrap"><table><thead><tr><th>Partition</th><th>Count</th><th>Current static identity</th></tr></thead><tbody><tr><td>Physical page-tree files</td><td>$full_page_tree_files</td><td class="partial">complete pinned Git path/blob/content census</td></tr><tr><td>TSX / TS files</td><td>$full_page_tsx / $full_page_ts</td><td class="partial">$full_page_excluded_tsx excluded test/spec/story TSX · $full_page_ts_helpers production TS helpers · $full_page_ts_tests TS tests</td></tr><tr><td>Existing literal backend render roots</td><td>$full_page_roots</td><td class="partial">static file-backed roots; $page_reviewed reviewed + $page_evidence_gaps evidence-gap prompt classifications</td></tr><tr><td>RUN-082 render-owner candidates</td><td>$candidate_page_one single · $candidate_page_many multiple · $candidate_page_zero none</td><td class="partial">exact line containment only · RUN-082R GO candidate-only · 0 mappings</td></tr><tr><td>Imported support components</td><td>$full_page_support</td><td class="partial">all directly imported from production TSX; support-owner relations remain candidates</td></tr><tr><td>Adjudicated unrendered/unimported non-roots</td><td>$full_page_nonroots</td><td class="partial">10 Redirect/legacy + 10 Duplicate + 3 Dead/unreachable + 2 Out of product scope</td></tr><tr><td>Missing backend render literals</td><td>$missing_target_count</td><td class="zero">retired/unrouted liabilities; zero page credit</td></tr><tr><td><strong>Production TSX partitioned</strong></td><td><strong>$full_page_production</strong></td><td class="partial">$full_page_production/$full_page_production static structural classification · 0 feature mappings/runtime/build/browser</td></tr></tbody></table></div></section>
    <div class="split"><section class="panel"><h2>Evidence waves represented</h2><p>RUN-001 through RUN-157 are represented by audit artifacts. RUN-145 grants exactly two target-specific static benchmark-mapping credits; no represented wave grants current-source application runtime, signed-in browser, executed-test, ease, release, Pass, final-finding, feature-completion, or audit-completion credit.</p><ul class="list"><li>RUN-001–016: census, discovery, page/static visual, and benchmark-metadata foundations</li><li>RUN-017–022: frontline/platform identity adjudication and blocked-owner reconciliation</li><li>RUN-023–025: cross-scope and remaining-owner arbitration</li><li>RUN-026–030: report/medical ownership, denominator integration, red team, and frozen 340-target identity</li><li>RUN-031–038: complete observer-only project materialization and blocker review; 88 complete observer-only · 7 partial</li><li>RUN-039–046: first six-target comparison overlay; 6 NO-GO · 0 formal edges · unchanged 0/340</li><li>RUN-047–052: historical 24-packet diagnostic checkpoint; mechanically complete but missing the clean Agent A-to-B derivation</li><li>RUN-053–054: identity-stripped Agent A packets, 252-atom crosswalk, fresh Agent B neutralization, and provenance-only correction</li><li>RUN-055: sealed fresh Agent C comparison; 24 rows · 144 lenses · 24 units · 58 outcomes · 85 unknowns preserved</li><li>RUN-056: sealed fresh Agent D adjudication; 226 reviews · 225 accepted · 1 bounded correction · 0 rejected</li><li>RUN-057: deterministic corrected-chain integration; 0 formal edges · 0 final no-matches · unchanged 0/340</li><li>RUN-058-BROWSER–060: $unknown_build_routes routes · $unknown_build_viewports viewports · $unknown_build_cells cells · $unknown_build_overlays overlays · $unknown_build_candidates provisional candidates · 0 current-source credit</li><li>RUN-058A–070 formal upstream: $formal_attempts project records · $formal_prompt_repos prompt repositories · $formal_historical historical extra · $formal_targets targets · $formal_subrecords initial facet/aspect records · $formal_accepted project records accepted · $formal_facets facet records accepted</li><li>RUN-071: 26 literal completion gates and downstream/usability/visual readiness reconciled; historical pre-materialization snapshot 9/18 required deliverables</li><li>RUN-072: historical 11/18 snapshot · 300/300 source-bound H contracts · 0 validated tasks · all current/target scores <span class="mono">NOT_MEASURED</span> · 3-target static slice · expired-auth 0 cells · incident A/B/C/D candidate chain with Agent D NO-GO and zero edge/final-no-match/NCM credit</li><li>RUN-073: 18/18 required paths present · reports 07–12 and findings materialized source-only · 8/8 independently source-reviewed journeys with 27/8/9 handoffs and 0 prompt-grade executions · 13 entity families · 17 concerns · 9 provisional architecture claims · 10 explicit unknowns · 0 final/runtime findings</li><li>RUN-074–076: $static_reviewed_targets gap targets · $static_original_missing_cells original missing scoped cells · cyclic independent review · $static_rows_changed rows / $static_field_changes permitted linkage cells changed · immutable and benchmark/credit projections unchanged · 0 downstream credit</li><li>RUN-077: $route_primary_calls primary route-facade callsites + 1 separate route-like sentinel · $route_name_calls fluent names · $route_page_roots page roots · exact three-part manifest</li><li>RUN-078–079: $route_like_rows route-like, $route_name_calls name, and $route_page_roots page decision records · 3 cyclic independent GO reviews · 0 invalid decisions · 0 reviewer writes</li><li>RUN-080–081: $route_page_rows_changed rows / $route_page_field_changes route-name/page-file fields integrated · current reports and artifact hashes refreshed · immutable and benchmark/credit projections unchanged · 0 downstream credit</li><li>RUN-082: $candidate_route_rows retained route-like rows · $candidate_page_rows page evidence-gap rows · route-name, exact controller-method, and render-owner static candidate relations · $candidate_registered_routes/$route_file_count route-file registration closure · RUN-082R GO candidate-only · 0 discrepancies · no mapping/matrix/downstream authority · runtime/build/tests/application browser NO-GO · 0 mappings</li><li>RUN-083: five reports refreshed · five reports byte-preserved · matrix 0 rows / 0 cells changed · zero downstream credit</li><li>RUN-083 dashboard: 4/4 viewports · 172/172 local links · 10/10 anchors · zero duplicate IDs or console warnings/errors · artifact-only GO</li><li>RUN-084/R: $full_page_tree_files physical page-tree files · $full_page_production = $full_page_roots roots + $full_page_support imported supports + $full_page_nonroots adjudicated non-roots · independent GO structural/candidate evidence only</li><li>RUN-084B/BR: $backend_role_rows backend role rows · $backend_unique_paths unique paths · $backend_async_rows async role rows / $backend_async_paths paths · independent GO structural only · 0 whole-file semantic reviews</li><li>RUN-084 historical designated application: public home + signed-out login only · no credentials/forms/private records/screenshots · build identity unproved · 0 application-browser credit</li><li>RUN-085: deterministic reporting refresh and fresh audit-dashboard verification · matrix and all downstream credit unchanged</li><li>RUN-086/R: 530 independently reviewed bounded static source-owner records · 212 routes + 318 pages · 235 FEATURE-IDs · Gate 4 incomplete</li><li>RUN-087: deterministic initial bounded-ownership reporting refresh · downstream boundaries unchanged</li><li>RUN-089: current public/login signed-out preflight · no signed-in or build-attributed application credit</li><li>RUN-090: $queue_records-row direct-exact review queue · zero wholesale ownership</li><li>RUN-091/R: 11 closed chains reviewed · 9 owner · 2 shared</li><li>RUN-092/R: 18 owner rows + 9 action bridges integrated · one independent mechanical reconstruction + one semantic-boundary review · 548 cumulative owner records</li><li>RUN-093: deterministic reviewed-overlay reporting refresh · matrix and every execution/benchmark/Pass/finding/completion boundary unchanged</li><li>RUN-097/R: historical 23 route/controller-only owners · 0 page credit</li><li>RUN-098/R: historical 23 route rows + 23 action bridges · 571 cumulative owner records</li><li>RUN-099–100: historical reporting refresh and exact superseded dashboard verification</li><li>RUN-101/R: historical 24 route candidates · 21 owners · 3 aliases · 0 page credit</li><li>RUN-102/R: historical 21 route rows + 21 action bridges · 592 cumulative owner records</li><li>RUN-103–104: historical reporting refresh and exact superseded dashboard verification</li><li>RUN-105/R–108: historical 24-page review · 20 owners · 3 shared · 1 gap · reporting and exact superseded dashboard verification</li><li>RUN-109/R–112: historical 6-page tail · 2 owners · 4 shared · reporting and exact superseded dashboard verification</li><li>RUN-113/R–116: historical 24 name-only route actions · 23 owners · one alias · 23 route rows and bridges · reporting and exact superseded dashboard verification</li><li>RUN-117/R–120: historical four-page Respite handover review, integration, reporting, and exact superseded dashboard receipt</li><li>RUN-121/R–132: historical Finance route/action and page-owner review, integration, reporting, and exact superseded dashboard receipts</li><li>RUN-133/R–136: historical accounting-integration review, integration, reporting, and exact superseded dashboard verification · six route rows and six bridges · zero correctness credit</li><li>RUN-137/R–140: historical invoice-index review, integration, reporting, and exact superseded dashboard verification · one route row and one bridge · zero correctness credit</li><li>RUN-141/R: one finance.api.sites.overview JSON action · one explicit route/action owner · 24 expansion files, 17 assurance mappings, nine action and three shared findings retained without correctness credit</li><li>RUN-142/R: one route row and one bridge integrated and independently verified · zero page/sibling/caller/neighbor/next-row inheritance · $static_owner_records cumulative owner records</li><li>RUN-143: deterministic Site-portfolio API reporting refresh · historical matrix then byte-identical · every correctness/execution boundary unchanged</li><li>RUN-144: exact RUN-143 audit-dashboard artifact verified at 4/4 required viewports · 23/23 visible checks · 10/10 navigation · zero application credit</li><li>RUN-145: fresh Agent A → B → C → independent Agent D plus Pass-8 correction/review · exactly two Finance target mappings · 0 final no-match/NCM · 338 unresolved · BigCapital adjacent-only</li><li>RUN-146: deterministic current reporting and dashboard refresh · current matrix $live_matrix_short · register $live_register_short · receipt $run145_receipt_short · every non-mapping credit zero</li><li>RUN-147: exact RUN-146 audit-dashboard artifact verification · zero application credit</li><li>RUN-148/R–150: historical Fleet daily-check ownership and reporting checkpoint</li><li>RUN-151: exact RUN-150 dashboard verified at 4/4 viewports · zero application credit</li><li>RUN-152/R: one fleet-assets.vehicles.index candidate independently reviewed · six provisional source observations only</li><li>RUN-153/R: one route owner + one bridge · 664/307/357/95 · 118 reviewed / 389 pending · page/sentinel/neighbor noninheritance · all correctness and Gate 4 credit false</li><li>RUN-154: deterministic Fleet reporting · benchmark 2/340 · 338 unresolved</li><li>RUN-155: exact RUN-154 dashboard verified at 4/4 viewports · 38/38 visible checks · 10/10 navigation · 379/379 unique local links · zero application credit</li><li>RUN-156/R: two-checkpoint medication-governance Git/source receipt and coordinated, explicitly non-blind/non-isolated independent review · reporting only · zero discrepancies · zero outcome credit</li><li>RUN-157: deterministic current reporting with Fleet, queue, benchmark, finding, and completion counts unchanged · fresh RUN-158 dashboard verification required</li></ul></section><section class="panel"><h2>Execution credit</h2><p>Static, observer, source-comparison, formal-upstream triage records, and unknown-build deployed observations are not attributable current-source runtime evidence.</p><ul class="list"><li><span class="zero">0</span> framework route executions</li><li><span class="zero">0</span> current application tests</li><li><span class="zero">0</span> rendered current-build visual instances</li><li><span class="zero">0</span> current-build application browser routes</li><li><span class="partial">$benchmark_mapped</span> target-specific static benchmark mappings promoted</li><li><span class="zero">0</span> completed Pass 1–8 modules</li></ul></section></div>
    <section id="static-census" class="panel"><h2>Expanded static coverage wave</h2><p>RUN-030 freezes canonical static identity; RUN-077–084B add exhaustive committed static route/name/page, full page-tree, and backend structural evidence; RUN-086/R establish the initial bounded ownership, RUN-090–092/R add the independently reviewed closed-chain overlay, RUN-097/R–140 preserve historical route/action and page-owner checkpoints with exact dashboard receipts; RUN-141/R–151 preserve the Site-portfolio, benchmark, reporting, and exact-dashboard checkpoints; RUN-152/R–153/R add one independently reviewed fleet-assets.vehicles.index route owner and one bridge, preserve six provisional-not-final observations separately from 12 provisional findings, preserve page/sentinel/neighbor noninheritance, and keep all correctness boundaries and Gate 4 false; RUN-154 refreshes Fleet reporting and RUN-155 verifies only that exact now-superseded dashboard. RUN-156/R establish and independently review bounded two-checkpoint medication-governance Git/source provenance; RUN-157 reports that receipt without changing the 664/307/357/95 ownership and bridge counts, 118/389 queue accounting, 2/340 mappings, 0/340 final no-match/NCM, 338 unresolved targets, or 12 provisional findings. Rendered coverage, schema truth, runtime, the other $benchmark_unresolved benchmark targets, ease, release, and completion gates remain open.</p><div class="table-wrap"><table><thead><tr><th>Static universe</th><th>Denominator</th><th>Current boundary</th></tr></thead><tbody><tr><td>Discovery sources / Layer-A edges</td><td>$mapped_sources of $source_count / $layer_a_edges</td><td class="partial">one bounded source excluded; $layer_a_targets Layer-A targets</td></tr><tr><td>Canonical targets</td><td>$canonical_count / $h_count H · $d_count D · $m_count M</td><td class="partial">static identity frozen; no downstream credit</td></tr><tr><td>Remaining route-path / route-name / page-file gaps</td><td>$route_gap_count / $route_name_gap_count / $page_gap_count</td><td class="partial">RUN-080 matrix sentinels; full mapping/reachability open</td></tr><tr><td>Remaining combined route/page / backend / static test gaps</td><td>$both_gap_count / $backend_gap_count / $test_gap_count</td><td class="partial">static owners/locators only; tests unexecuted</td></tr><tr><td>Primary route-facade / separate route-like sentinel</td><td>$route_primary_calls / 1</td><td class="partial">$route_like_rows review rows; no framework expansion</td></tr><tr><td>RUN-078 baseline route decision classes</td><td>$route_owner owner · $route_shared shared · $route_alias alias · $route_unmapped explicit unmapped</td><td class="partial">cyclic independent static review; 0 feature mappings</td></tr><tr><td>RUN-082 exact route-name candidates</td><td>$candidate_name_one single · $candidate_name_many multiple · $candidate_name_zero none</td><td class="partial">static relation only · RUN-082R GO candidate-only · 0 mappings</td></tr><tr><td>RUN-082 exact controller-method candidates</td><td>$candidate_backend_one single · $candidate_backend_many multiple · $candidate_backend_zero none</td><td class="partial">$candidate_exact_actions resolvable arrays · $candidate_non_exact_actions non-exact retained · 0 mappings</td></tr><tr><td>RUN-082 page render-owner candidates</td><td>$candidate_page_one single · $candidate_page_many multiple · $candidate_page_zero none</td><td class="partial">$candidate_page_rows evidence-gap rows · no ownership/render credit</td></tr><tr><td>RUN-082 static route-file registration</td><td>$candidate_registered_routes / $route_file_count</td><td class="partial">$candidate_direct_routes bootstrap + $candidate_web_routes web requires · 0 framework route tables</td></tr><tr><td>Fluent-name callsites</td><td>$route_name_calls</td><td class="partial">static name decisions; framework reachability unproved</td></tr><tr><td>RUN-079 baseline page-root prompt status</td><td>$page_reviewed reviewed · $page_evidence_gaps evidence gap</td><td class="partial">$route_page_roots roots total; 0 rendered</td></tr><tr><td>RUN-092/R historical bounded ownership</td><td>548 records · 221 route + 327 page · 239 FEATURE-IDs · 9 action bridges</td><td class="partial">13.947569% · 3,381 residual · historical bounded checkpoint</td></tr><tr><td>RUN-098/R historical bounded route/action ownership</td><td>571 records · 244 route + 327 page · 246 FEATURE-IDs · 32 action bridges</td><td class="partial">14.532960% · 3,358 residual · historical bounded checkpoint</td></tr><tr><td>RUN-102/R historical outcome-neutral route/action ownership</td><td>592 records · 265 route + 327 page · 249 FEATURE-IDs · 53 action bridges</td><td class="partial">15.067447% · 3,337 residual · historical bounded checkpoint</td></tr><tr><td>RUN-106/R historical outcome-neutral page ownership</td><td>612 records · 265 route + 347 page · 256 FEATURE-IDs · 53 action bridges</td><td class="partial">15.576483% · 3,317 residual · historical bounded checkpoint</td></tr><tr><td>RUN-110/R historical outcome-neutral page-tail ownership</td><td>614 records · 265 route + 349 page · 256 FEATURE-IDs · 53 action bridges</td><td class="partial">15.627386% · 3,315 residual · historical bounded checkpoint · exact RUN-112 dashboard verification</td></tr><tr><td>RUN-153/R current Fleet vehicle-register index route/action ownership</td><td>$static_owner_records = $static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs = $static_owner_h_features H + $static_owner_d_features D · $static_action_bridges action bridges</td><td class="partial">$ownership_percent% of bounded 3,929 · $static_residual residual · features $route_feature_ids route / $page_feature_ids page / $route_page_overlap overlap · routes 3,218 = $static_owner_routes owner + $route_shared_current shared + $route_alias_current alias + $route_residual residual with $finance_site_route_gap tagged gaps · pages 711 = $static_owner_pages owner + $page_shared shared + $page_residual residual with $page_gap tagged gap · Fleet vehicle-register wave 1 reviewed = 1 owner · 1 route row + 1 bridge · 0 page rows · page owner and historical sentinel not recredited · index 82 context only · index 83 unresolved · 6 provisional source observations separate from 12 provisional findings · both reviewers non-blinded with disclosed prior visibility · neither consulted the other · zero correctness and final-finding credit · Gate 4 incomplete · ownership/linkage fields unchanged by RUN-145 benchmark-only mapping</td></tr><tr><td>RUN-090 direct-exact queue</td><td>$queue_records total = $queue_reviewed reviewed + $queue_pending pending · reviewed = $queue_owner owned + $queue_shared shared + $queue_alias alias + $queue_gap gap · $queue_without_owner without ownership</td><td class="partial">candidate prioritisation only · queue itself grants no wholesale ownership</td></tr><tr><td>Named navigation/tab source files</td><td>$nav_file_count</td><td class="partial">definitions, not runtime-visible links</td></tr><tr><td>Hero definitions / instances</td><td>$hero_definitions / $hero_instances</td><td class="partial">static AST; 0 rendered</td></tr><tr><td>Overlay definitions / instances</td><td>$overlay_definitions / $overlay_instances</td><td class="partial">static AST; 0 rendered</td></tr><tr><td>Declarative / direct / named triggers</td><td>$declarative_triggers / $direct_triggers / $named_triggers</td><td class="partial">row-level static locators; 0 interactions</td></tr><tr><td>Required visual matrix rows</td><td>$visual_matrix_rows</td><td class="partial">49 columns complete; every row browser-blocked</td></tr><tr><td>RUN-084B models / policies / service role rows</td><td>$backend_models / $backend_policies / $backend_services</td><td class="partial">independently reproduced structural ledger · every row Evidence gap · 0 whole-file semantic reviews</td></tr><tr><td>RUN-084B jobs / events / listeners / outbox</td><td>$jobs / $events / $listeners / 45</td><td class="partial">$backend_async_rows overlapping async role rows over $backend_async_paths paths · no queue/runtime execution</td></tr><tr><td>Migrations / PHP test files</td><td>$migrations / $php_test_files</td><td class="partial">history and file locators; 0 database/test execution; unreproducible lexical-case count omitted</td></tr></tbody></table></div></section>
    <section id="runtime" class="panel"><h2>Runtime and deployed-build identity gates</h2><p>RUN-159 establishes bounded current-source MED-RBAC test execution only. It does not establish full-suite, coverage, application-browser, build, ease, release, Pass, or completion credit.</p><div class="table-wrap"><table><thead><tr><th>Gate</th><th>Observed fact</th><th>Result</th></tr></thead><tbody><tr><td>RUN-082 historical execution preflight</td><td>At that checkpoint, <span class="mono">vendor/autoload.php</span> and route cache were absent; build provenance and database isolation were not established</td><td class="partial">immutable historical NO-GO; not a current dependency-state claim</td></tr><tr><td>RUN-159 bounded MED-RBAC execution</td><td>Task-local ignored Composer dependencies hydrated; three commands passed $bounded_tests tests / $bounded_assertions assertions on disposable per-process MySQL schemas; configured base and matching effective schemas absent after cleanup</td><td class="partial">bounded MED-RBAC ALREADY_FIXED evidence only · no full-suite or coverage credit</td></tr><tr><td>RUN-089 designated-application preflight</td><td>Public home and signed-out login only; the earlier user login did not persist in the controlled tab; no credentials, submissions, private records, screenshots, environment marker, or build attribution</td><td class="zero">Signed-in application, role/Site, route/workflow, responsive-family, Pass, and completion credit all zero</td></tr><tr><td>Local PHP/runtime</td><td>PHP $php_version; test-oriented settings; task-local ignored <span class="mono">vendor/</span> hydrated for RUN-159</td><td class="partial">Laravel test harness booted for the bounded slice only</td></tr><tr><td>Repository setup</td><td>Composer hydration ran without tracked-file changes; forced migration, frontend build, and device configuration did not run</td><td class="partial">dependency setup is not application or completion credit</td></tr><tr><td>Signed-in deployment</td><td>Inertia component <span class="mono">$deployed_component</span> and deployed assets recorded read-only</td><td class="zero">No authoritative commit/tree marker</td></tr><tr><td>Selected unknown-build sample</td><td>$unknown_build_routes routes across $unknown_build_viewports prompt viewports · $unknown_build_cells cells · $unknown_build_overlays pre-submit overlays · $unknown_build_candidates provisional candidates</td><td class="zero">Accepted as unknown-build observation only; 0 current-source credit</td></tr><tr><td>RUN-072 expired-auth attempt</td><td>3 routes selected; only <span class="mono">/my-day</span> attempted; both contexts ended at <span class="mono">/login</span>; 0 authenticated/base/pre-submit cells, credentials, mutations, or screenshots</td><td class="zero">Build, environment, role, Site, and fixture identities all UNKNOWN; fail-closed with zero credit</td></tr><tr><td>Local build manifest</td><td>Present locally but not tracked at the application source pin</td><td class="zero">Cannot identify the deployed build</td></tr></tbody></table></div></section>
    <section id="benchmarks" class="panel"><h2>Current benchmark wave</h2><p>The prompt denominator remains 98 URL occurrences / 95 unique repositories. RUN-047–052 are preserved as a historical defective checkpoint. RUN-053–057 supply a corrected diagnostic clean-spec chain for 24 selected packets. RUN-058A–070 preserves $formal_attempts initial upstream project records across the same six selected targets. Independent exact-hash FTC review accepts $formal_accepted bounded project records across incident and HR/finance plus $formal_facets bounded HR/finance facet records; medication/clinical remains NO-GO. Formal project/facet-record acceptance is not by itself project or facet selection, a target mapping, or an exhaustive final no-match. RUN-145 separately maps exactly two Finance targets through a fresh clean-room chain; final no-match/NCM remains 0/340 and $benchmark_unresolved targets remain open.</p><div class="table-wrap"><table><thead><tr><th>Evidence slice</th><th>Count</th><th>Current credit</th></tr></thead><tbody><tr><td>Prompt-listed URL occurrences</td><td>$prompt_occurrences</td><td class="partial">$prompt_unique unique repositories; three repeated</td></tr><tr><td>Physical carry-forward register rows</td><td>$register_physical</td><td class="partial">95 exact prompt repos + $extra_rows historical extras</td></tr><tr><td>Official GitHub metadata prerequisite</td><td>$metadata_unique / $prompt_unique</td><td class="partial">$metadata_occurrences / $prompt_occurrences weighted entries; metadata only</td></tr><tr><td>Observer project triage</td><td>$triage_observer_unique / $prompt_unique</td><td class="partial">$triage_observer_occurrences / $prompt_occurrences weighted entries; $triage_complete complete observer-only · $triage_partial partial</td></tr><tr><td>Partial-record review</td><td>$partial_reviewed / 16</td><td class="partial">$partial_resolved resolved observer-only · $partial_retained retained partial; zero downstream credit</td></tr><tr><td>Formal full project triage</td><td>$formal_accepted / $prompt_unique</td><td class="partial">$formal_accepted_weight / $prompt_occurrences weighted entries; project records only</td></tr><tr><td>Formal upstream wave-03 attempts</td><td>$formal_attempts unique records</td><td class="partial">$formal_prompt_repos prompt repositories · $formal_historical historical extra · occurrence weight $formal_weight</td></tr><tr><td>Independently accepted formal records</td><td>$formal_accepted / $formal_attempts</td><td class="partial">$formal_targets targets inspected · $formal_subrecords initial facet/aspect records · $formal_facets bounded facet records accepted · 0 edges · 0 final no-matches</td></tr><tr><td>First six-target overlay</td><td>$target_wave_targets / $canonical_count</td><td class="partial">$target_candidate_packets candidate locators · $target_no_candidate_packets bounded no-candidate; overlay only</td></tr><tr><td>Historical RUN-047–052 diagnostic</td><td>$facet_wave_facets packets / $facet_wave_features features</td><td class="partial">superseded for corrected comparison; retained as provenance only</td></tr><tr><td>Corrected Agent A packets</td><td>$facet_exact exact / $facet_partial partial / $facet_insufficient insufficient</td><td class="partial">all adjacent packets remain non-promotable</td></tr><tr><td>Corrected Agent B atom lineage</td><td class="partial">$facet_atoms total / $facet_consumed consumed / $facet_unknown_atoms unknown</td><td class="partial">$facet_units units · $facet_outcomes outcomes · zero neutral credit</td></tr><tr><td>Fresh Agent C comparison</td><td>$facet_ratings lenses / $facet_unknowns unknowns</td><td class="partial">same-packet citations only; static comparison credit zero</td></tr><tr><td>Pinned source evidence</td><td>$facet_anchors occurrences / $facet_unique_anchors unique / $facet_anchor_files paths</td><td class="partial">mechanically validated; no mapping credit</td></tr><tr><td>Fresh Agent D adjudication</td><td>$facet_d_reviews reviews / $facet_d_corrections correction</td><td class="partial">24 row lineages pass; AO-A53-024-01 corrected to partial</td></tr><tr><td>Current target-specific mappings / final no-matches</td><td>$benchmark_mapped / $final_no_matches</td><td class="partial">$benchmark_mapped / 340 static mapping-only · $final_no_matches / 340 final no-match/NCM · $benchmark_unresolved unresolved</td></tr></tbody></table></div></section>
    <section class="panel"><h2>RUN-071B downstream readiness and RUN-072 incident disposition</h2><p>Three exact target IDs were start-ready for a fresh clean-specification chain; none was mapping-ready or credit-ready. The completed incident chain closes only this candidate edge with Agent D NO-GO. It is not an exhaustive final no-match or NCM.</p><div class="table-wrap"><table><thead><tr><th>Start-ready target IDs</th><th>Readiness</th><th>Current disposition</th></tr></thead><tbody><tr><td class="mono">$start_ready_ids</td><td class="partial">3 start-ready · 0 mapping-ready · 0 credit-ready</td><td class="zero">Incident candidate NO-GO · 0 edges · 0 final no-matches · 0 NCM</td></tr></tbody></table></div></section>
    <section id="modules" class="panel"><h2>Canonical static feature modules</h2><p>$module_count module labels across $canonical_count canonical static targets. Module completion credit remains zero.</p><div class="table-wrap"><table><thead><tr><th>Module label</th><th>H</th><th>D</th><th>M</th><th>Total</th></tr></thead><tbody>$module_rows</tbody></table></div></section>
    <section id="findings" class="panel"><h2>Current finding status</h2><p>The register retains 12 historical claim identities: $finding_count remain current provisional P1 claims and $historical_fixed_count is a historical issue already fixed on current main. None is a final finding or closed completion gate.</p><div class="table-wrap"><table><thead><tr><th>ID</th><th>Static concern</th><th>Status</th></tr></thead><tbody>$finding_rows</tbody></table></div></section>
    <section id="architecture" class="panel"><h2>Separate provisional architecture source claims</h2><p>Nine independently source-reviewed candidates (7 P1 · 2 P2) remain outside the 12-row discovery finding table. They have 0 final/runtime finding credit.</p><div class="table-wrap"><table><thead><tr><th>ID</th><th>Priority</th><th>Narrow source condition</th><th>Status</th></tr></thead><tbody>$architecture_rows</tbody></table></div></section>
    <section id="gaps" class="panel"><h2>Literal completion gates still open</h2><div class="split"><ul class="list"><li>RUN-153/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges while adding one fleet-assets.vehicles.index owner and one bridge, preserving six provisional-not-final observations separately from the 12 provisional findings, inheriting or recrediting no page-owner, historical-sentinel, neighbor, or index-82 context and leaving index 83 unresolved, and adding zero feature-union, matrix, correctness, or final-finding credit; complete the framework-expanded canonical route/page denominator, $static_residual non-owner records including $route_shared_current shared routes, $route_alias_current alias routes, and $route_residual residual routes plus $page_shared shared pages and $page_gap tagged gap within $page_residual residual pages, the full crosswalk, and route reachability before Gate 4 can close</li><li>RUN-080 retained matrix gaps: $route_gap_count route path, $route_name_gap_count route names, $page_gap_count page files, $both_gap_count combined route/page, $backend_gap_count backend anchors, and $test_gap_count static test anchors</li><li>RUN-082R independently reviewed the candidate-relation reconstruction; separately adjudicate canonical ownership across $candidate_route_rows route-like and $candidate_page_rows page evidence-gap rows without promoting names, containment, presence, or overlap as mapping</li><li>Complete route/page-to-feature mapping, framework reachability, and backend/data/test ownership</li><li>Adjudicate the reviewed 1,058-file page-tree graph without inheriting FEATURE-IDs through support imports; preserve $full_page_production = $full_page_roots + $full_page_support + $full_page_nonroots</li><li>Complete semantic review of all $backend_role_rows backend role rows across $backend_unique_paths paths; whole-file semantic review remains 0</li><li>Complete exact clean-room target mapping or catalogue-complete final no-match/NCM adjudication for the remaining $benchmark_unresolved frozen targets</li><li>Refresh RUN-072 base-matrix locator snapshots separately, then representative-role validation of all 300 contracts plus ten current and ten target dimension measurements per H target: 0 validated · 0 measured · 0 independent ease reviews</li></ul><ul class="list"><li>8/8 journeys are source reconstructed and independently source-reviewed; prompt-grade runtime/browser execution and all four viewport lanes remain 0/8</li><li>Rendered hero, overlay, trigger, and material-state coverage from the completed static matrix</li><li>Build-attributed independent resampling of both unknown-build provisional candidates</li><li>Safe current-build application browser/runtime lanes; RUN-089 remains signed out and build-unattributed, so signed-in application coverage remains 0</li><li>Every module through Passes 1–8</li><li>Fresh Pass 8, final artifact freeze, reconciliation, and no-live-agent gate</li></ul></div></section>
    <section class="panel"><h2>RUN-152R–154 Fleet vehicle-register ownership and provisional source observations</h2><p><span class="mono">fleet-assets.vehicles.index</span> / <span class="mono">RUN077-ROUTE-0690</span> / <span class="mono">VehicleController::index</span> is one bounded static route/action owner for <span class="mono">CAP-FLEET-VEHICLE-REGISTER</span>. RUN-153 adds one route row and one bridge, zero page rows, and no new FEATURE-ID. Existing page-owner and historical-sentinel context are not recredited; index 82 is context only and index 83 remains unresolved.</p><p>Both reviewers completed independent evidence traces and neither is represented as blinded. Reviewer A had prior team-status visibility; reviewer B had prior self-assessment visibility; neither consulted the other. The six observations below authorize no correctness or final-finding credit and remain separate from the 12 retained claim identities ($finding_count current provisional + $historical_fixed_count historical already-fixed).</p><ul class="list">$fleet_observation_items</ul></section>
    <section class="panel"><h2>RUN-155–160 medication-governance provenance and MED-RBAC adjudication</h2><p>RUN-158 verifies only the exact now-superseded RUN-157 audit-dashboard artifact: 4/4 required viewports, 50/50 visible boundary checks, 10/10 navigation targets, 387/387 local resources, and zero console warnings, console errors, or page errors. None of that proof transfers to the RUN-160 dashboard or the application.</p><p>RUN-156 separates the 359-path historical first-parent merge payload at <span class="mono">cd5d34e6b8aa7e494808745041ec1dfa187dc101</span> (87 added · 272 modified) from effective application checkpoint <span class="mono">c5c0ad0903d2e2e2229d5d0090fc0a69a2206f0f</span>: 358 payload blobs remain unchanged and exactly <span class="mono">resources/js/pages/my-day/index.tsx</span> is superseded. The complete post-merge My Day delta is three modified paths; the three later commits through <span class="mono">86b232cb14967c63ff345ac5208ec6d4c379f24f</span> are audit-root-only and preserve the 12,784-entry non-audit manifest. RUN-156R at <span class="mono">$run156r_commit</span> remains coordinated, explicitly non-blind/non-isolated source-receipt review history only.</p><ul class="list">$medication_record_items</ul><p>RUN-159 establishes the <span class="mono">MED-RBAC-01</span> ALREADY_FIXED disposition, and RUN-159R independently authorizes retirement reporting. RUN-160 alone reclassifies <span class="mono">MED-RBAC-01</span> from current provisional to retained historical already-fixed. At application commit <span class="mono">4f57ad4202df90ded375961437879822a908627b</span>, three independent read-only source lanes unanimously returned <span class="mono">ALREADY_FIXED</span>; $bounded_tests bounded tests / $bounded_assertions assertions passed on disposable per-process MySQL schemas, and the exact corrected receipt received independent GO. The historical broad <span class="mono">medications.orders.manage</span> exposure remains preserved as provenance, while current controlled and stock mutations require exact capabilities. No application source changed, no current bypass or final finding was established, and configured/effective RUN-159 databases plus PHP processes/listeners were absent after cleanup.</p><p><span class="mono">MED-CD-SCOPE-01</span> and <span class="mono">MED-CD-ATOMICITY-01</span> remain separate current provisional claims: they inherit no source, runtime, browser, closure, or completion credit from MED-RBAC. RUN-160 reports 12 retained identities = $finding_count current provisional P1 + $historical_fixed_count historical already-fixed, with $benchmark_mapped/340 mappings, $final_no_matches/340 final no-match/NCM, $benchmark_unresolved unresolved targets, Gate 4 false, and fresh RUN-161 audit-dashboard verification required.</p></section>
    <section class="panel"><h2>RUN-145 current benchmark mapping</h2><p>Exactly two Finance targets have independently adjudicated static mapping credit. Current state is $benchmark_mapped/340 static mapping-only, $final_no_matches/340 final no-match/NCM, and $benchmark_unresolved unresolved. The current matrix is <span class="mono">$live_matrix_sha256</span>, the current register is <span class="mono">$live_register_sha256</span>, and the mapping receipt is <span class="mono">$run145_receipt_sha256</span>.</p><ul class="list"><li><strong>Selected:</strong> <span class="mono">CAP-FIN-FX-REVALUATION</span> maps to <span class="mono">frappe/erpnext@b24c9eba551905e256e336ff170a91a92d197a2f</span>.</li><li><strong>Selected:</strong> <span class="mono">CAP-FIN-BILLING-INVOICE-LIFECYCLE</span> maps to <span class="mono">frappe/erpnext@b24c9eba551905e256e336ff170a91a92d197a2f</span> and <span class="mono">Dolibarr/dolibarr@769c7db907099643558e77d7002c109cfda919e5</span>.</li><li><strong>BigCapital boundary:</strong> <span class="mono">bigcapitalhq/bigcapital@41033239e0f93e4fc6cf1832743ae6bdbab25306</span> remains adjacent-only and unselected for <span class="mono">CAP-FIN-BILLING-INVOICE-LIFECYCLE</span>; its register row remains unchanged and receives zero mapping credit.</li><li><a href="evidence/benchmark/current-run-145-finance-invoice-fx-benchmark-mapping-wave-24.json">RUN-145 mapping receipt</a></li><li><a href="evidence/source/current-run-146-finance-benchmark-reporting-wave-24.json">RUN-146 reporting receipt</a></li><li><a href="03-feature-to-benchmark-matrix.csv">Current 340-row matrix</a></li><li><a href="06-open-source-benchmark-register.csv">Current 98-row project register</a></li></ul></section>
    <section class="panel"><h2>Formal upstream evidence</h2><p>Every materialized producer, reviewer, correction, provenance, feasibility, checklist, and integration artifact is linked with its sealed SHA-256. Bounded project/facet-record acceptance remains separate from project/facet selection, comparison, mapping, NCM, and completion credit.</p><ul class="list">$formal_evidence_links</ul></section>
    <section class="panel"><h2>Prior audit-dashboard verification</h2><p>RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, RUN-094, RUN-100, RUN-104, RUN-108, RUN-112, RUN-116, RUN-120, RUN-124, RUN-128, RUN-132, RUN-136, RUN-140, RUN-144, RUN-147, RUN-151, and RUN-155 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-157 dashboard.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-formal-upstream-wave-03.json">Superseded RUN-070 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-072-wave-04.json">Superseded RUN-072 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-073-wave-05.json">Superseded RUN-073 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-076-wave-06.json">Superseded RUN-076 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-081-wave-07.json">Superseded RUN-081 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-083-wave-08.json">Superseded RUN-083 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-085-wave-09.json">Superseded RUN-085 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-088-wave-10.json">Superseded RUN-088 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-094-wave-11.json">Superseded RUN-094 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-100-wave-12.json">Superseded RUN-100 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-104-wave-13.json">Superseded RUN-104 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-108-wave-14.json">Superseded RUN-108 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-112-wave-15.json">Superseded RUN-112 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-116-wave-16.json">Superseded RUN-116 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-120-wave-17.json">Superseded RUN-120 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-124-wave-18.json">Superseded RUN-124 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-128-wave-19.json">Superseded RUN-128 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-132-wave-20.json">Superseded RUN-132 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-136-wave-21.json">Superseded RUN-136 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-140-wave-22.json">Superseded RUN-140 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-144-wave-23.json">Superseded RUN-144 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-147-wave-24.json">Superseded RUN-147 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-151-wave-25.json">Superseded RUN-151 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-155-wave-26.json">Superseded RUN-155 verification GO</a></li></ul></section>
    <section class="panel"><h2>Fresh RUN-161 audit-reporting correction and dashboard verification required</h2><p>The exact RUN-161-corrected reporting dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844. RUN-161 corrects attribution wording only across the executive summary, repository module map, live findings register, dashboard builder, and dashboard: RUN-159 establishes the ALREADY_FIXED disposition, RUN-159R independently authorizes retirement reporting, and RUN-160 alone performs the live-register reconciliation. The linked RUN-161 receipt must record page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 664/307/357 ownership, 95 bridges, 118/389 queue accounting, 12 retained claim identities split into $finding_count current provisional P1 and $historical_fixed_count historical already-fixed, RUN-159's $bounded_tests tests / $bounded_assertions assertions and ALREADY_FIXED evidence, RUN-159R retirement-reporting authorization, RUN-160's exact MED-RBAC-only current-to-historical reconciliation, no scope/atomicity inheritance, current $benchmark_mapped/340 benchmark mapping, $final_no_matches/340 final no-match/NCM, $benchmark_unresolved unresolved targets, one operating organisation across multiple Sites, Gate 4 open, and every non-bounded runtime, browser, final-finding, release, Pass, feature-completion, and audit-complete zero-credit boundary. It verifies the corrected audit artifact only and grants no application-browser, responsive-application, visual, workflow, release, Pass, feature-completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-161-wave-28.json">RUN-161 corrected reporting and responsive audit-dashboard verification receipt</a> (forward reference until materialized)</li></ul></section>
    <section class="panel"><h2>RUN-071–160 evidence lineage</h2><p>Every current raw, generated, reviewed, and integrated RUN-077–159/R source/reporting/runtime/benchmark artifact is linked with its exact SHA-256; RUN-160 is the current reporting generator execution. Earlier receipts remain immutable history; the task-script directory link carries the historical RUN-072 300-file bundle hash.</p><ul class="list">$checkpoint_evidence_links</ul></section>
    <section class="panel"><h2>Evidence files</h2><ul class="list"><li><a href="00-executive-summary.md">Executive summary</a></li><li><a href="01-repository-module-map.md">Current repository and page map</a></li><li><a href="02-repository-module-map-wave-02.md">Wave 02 module map</a></li><li><a href="02-eight-pass-coverage-ledger.csv">Provisional eight-pass route-file ledger</a></li><li><a href="03-feature-to-benchmark-matrix.csv">340-row canonical static feature matrix</a></li><li><a href="05-browser-visual-coverage-matrix.csv">2,812-row static visual matrix</a></li><li><a href="06-open-source-benchmark-register.csv">Current carry-forward benchmark register</a></li><li><a href="evidence/source/current-canonical-feature-identity-wave-01.json">RUN-030 canonical feature identity</a></li><li><a href="evidence/source/current-canonical-identity-agent-register.json">RUN-030 identity agent register</a></li><li><a href="evidence/source/current-static-semantic-census.json">Initial semantic census JSON</a></li><li><a href="evidence/source/current-route-navigation-gap-wave-01.json">Route/navigation reconciliation</a></li><li><a href="evidence/source/current-visual-static-census-wave-01.json">Visual static census</a></li><li><a href="evidence/source/current-visual-matrix-materialization-wave-01.json">Visual matrix materialization evidence</a></li><li><a href="evidence/source/current-visual-matrix-agent-register.json">Visual matrix agent register</a></li><li><a href="evidence/source/current-backend-data-test-census-wave-01.json">Backend/data/test census</a></li><li><a href="evidence/source/current-static-coverage-agent-register.json">Static coverage agent register</a></li><li><a href="evidence/source/current-page-adjudication-wave-01.json">Page adjudication evidence</a></li><li><a href="evidence/source/current-page-agent-register.json">Page agent register</a></li><li><a href="evidence/source/current-feature-discovery-wave-01.json">Feature wave 01 JSON</a></li><li><a href="evidence/source/current-feature-discovery-wave-02.json">Feature wave 02 JSON</a></li><li><a href="evidence/source/current-feature-discovery-wave-03.json">Feature wave 03 gap additions</a></li><li><a href="evidence/benchmark/current-benchmark-wave-01.json">Benchmark wave evidence</a></li><li><a href="evidence/benchmark/current-benchmark-agent-register.json">Benchmark agent register</a></li><li><a href="evidence/benchmark/current-benchmark-metadata-agent-register.json">Benchmark metadata agent register</a></li><li><a href="evidence/benchmark/current-github-project-metadata-snapshot.json">Official GitHub metadata snapshot</a></li><li><a href="evidence/benchmark/current-prompt-project-denominator-reconciliation.json">Prompt project denominator reconciliation</a></li><li><a href="evidence/benchmark/current-upstream-project-triage-wave-01.json">RUN-034 upstream observer triage</a></li><li><a href="evidence/benchmark/current-upstream-project-triage-agent-register.json">RUN-034 upstream triage agent register</a></li><li><a href="evidence/benchmark/current-upstream-partial-resolution-wave-01.json">RUN-038 partial-record review</a></li><li><a href="evidence/benchmark/current-upstream-partial-resolution-agent-register.json">RUN-038 partial-review agent register</a></li><li><a href="evidence/runtime/current-runtime-safety-assessment.json">Runtime safety assessment</a></li><li><a href="evidence/browser/deployed-build-identity-assessment.json">Deployed build identity assessment</a></li><li><a href="evidence/browser/deployed-selected-feature-observation-wave-03.json">RUN-058-BROWSER sealed unknown-build observation</a></li><li><a href="evidence/browser/raw-run-059b-independent-unknown-build-browser-review-wave-03.json">RUN-059B independent observation review</a></li><li><a href="evidence/browser/current-deployed-selected-feature-observation-wave-03.json">RUN-060 normalized unknown-build observation</a></li><li><a href="evidence/browser/raw-run-060r-s-independent-browser-normalization-adjudication-wave-03.json">RUN-060R/S normalization adjudication</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-unknown-build-browser-wave-03.json">RUN-060 responsive audit-dashboard verification</a></li><li><a href="13-unresolved-questions-and-evidence-gaps.md">Unresolved evidence gaps</a></li></ul></section>
    <section class="panel"><h2>RUN-058-BROWSER–060 unknown-build browser evidence</h2><p>The signed-in sample is preserved as an independently accepted observation of an unattributed deployed build only. It records $unknown_build_routes selected routes, $unknown_build_viewports prompt dimensions, $unknown_build_cells route/viewport cells, $unknown_build_overlays pre-submit overlays, and $unknown_build_candidates provisional unknown-build candidates. No forms were submitted, no records changed, no screenshots retained, and no current-source browser, responsive, visual, workflow, finding, ease, release, Pass, or completion credit is awarded.</p><ul class="list"><li><a href="evidence/browser/deployed-selected-feature-observation-wave-03.json">RUN-058-BROWSER sealed raw observation</a></li><li><a href="evidence/browser/raw-run-059b-independent-unknown-build-browser-review-wave-03.json">RUN-059B independent review</a></li><li><a href="generators/integrate-deployed-selected-feature-observation-wave-03.py">RUN-060 deterministic normalizer</a></li><li><a href="evidence/browser/current-deployed-selected-feature-observation-wave-03.json">RUN-060 normalized observation</a></li><li><a href="evidence/browser/raw-run-060r-s-independent-browser-normalization-adjudication-wave-03.json">RUN-060R/S independent lineage adjudication</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-unknown-build-browser-wave-03.json">RUN-060 responsive audit-dashboard verification</a></li></ul></section>
    <section class="panel"><h2>RUN-039–046 target comparison evidence</h2><p>Clean-stage packets and deterministic integration for the first six-target wave; all formal edges and downstream credits remain zero.</p><ul class="list"><li><a href="evidence/benchmark/raw-run-039-target-upstream-behaviour-wave-01.json">RUN-039 upstream behaviour locators</a></li><li><a href="evidence/benchmark/raw-run-040-current-product-source-packets-wave-01.json">RUN-040 current-source packets</a></li><li><a href="evidence/benchmark/raw-run-041-current-source-red-team-wave-01.json">RUN-041 scope red-team</a></li><li><a href="evidence/benchmark/raw-run-042-neutral-requirements-wave-01.json">RUN-042 blind neutral requirements</a></li><li><a href="evidence/benchmark/raw-run-043-current-neutral-comparison-wave-01.json">RUN-043 clean current-neutral comparisons</a></li><li><a href="evidence/benchmark/raw-run-044-current-source-facet-reconciliation-wave-01.json">RUN-044 composite facet reconciliation</a></li><li><a href="evidence/benchmark/raw-run-045-wave-01-independent-adjudication.json">RUN-045 independent adjudication</a></li><li><a href="evidence/benchmark/current-target-neutral-comparison-wave-01.json">RUN-046 integrated comparison overlay</a></li><li><a href="evidence/benchmark/current-target-neutral-comparison-agent-register.json">RUN-046 comparison agent register</a></li></ul></section>
    <section class="panel"><h2>RUN-047–052 historical diagnostic evidence</h2><p>The former 24-packet chain is retained only as an immutable zero-credit checkpoint. Its missing sanitized Agent A-to-B derivation is historical and its RUN-048/RUN-050/RUN-051 payloads are prohibited as corrected comparison evidence.</p><ul class="list"><li><a href="evidence/benchmark/raw-run-047-upstream-facet-refinement-clinical-incident-wave-02.json">RUN-047 clinical and incident upstream packets</a></li><li><a href="evidence/benchmark/raw-run-047-upstream-facet-refinement-composites-wave-02.json">RUN-047 HR, medication, and finance upstream packets</a></li><li><a href="evidence/benchmark/current-upstream-facet-refinement-wave-02.json">RUN-047 integrated upstream refinement</a></li><li><a href="evidence/benchmark/current-upstream-facet-refinement-agent-register.json">RUN-047 upstream agent register</a></li><li><a href="evidence/benchmark/raw-run-048-blind-neutral-facet-requirements-wave-02.json">RUN-048 historical source-independent requirements</a></li><li><a href="evidence/benchmark/raw-run-049-current-source-facet-refinement-wave-02.json">RUN-049 pinned current-source packets</a></li><li><a href="evidence/benchmark/raw-run-050-clean-facet-comparison-reconciled-wave-02.json">RUN-050 historical reconciled comparison</a></li><li><a href="evidence/benchmark/raw-run-051-independent-facet-adjudication-wave-02.json">RUN-051 historical independent adjudication</a></li></ul></section>
    <section class="panel"><h2>RUN-053–057 corrected clean-spec evidence</h2><p>Fresh A/B/C/D stages reconstruct the required clean handoff for 24 selected packets. Fresh D validates all 226 reviews, corrects one outcome from met to partial, and preserves zero formal edges, zero final no-matches, and 0/340 credit.</p><ul class="list"><li><a href="evidence/benchmark/raw-run-053-agent-a-blind-observed-behaviour-packets-wave-02.json">RUN-053 identity-stripped Agent A packets</a></li><li><a href="evidence/benchmark/root-run-053-agent-a-source-atom-crosswalk-wave-02.json">RUN-053 root-held atom and identity crosswalk</a></li><li><a href="evidence/benchmark/raw-run-054-fresh-agent-b-neutral-requirements-wave-02.json">RUN-054 fresh Agent B neutral requirements</a></li><li><a href="evidence/benchmark/raw-run-054-agent-b-input-boundary-correction-wave-02.json">RUN-054 provenance-only boundary correction</a></li><li><a href="evidence/benchmark/raw-run-055-agent-c-comparison-input-wave-02.json">RUN-055 sealed Agent C input</a></li><li><a href="evidence/benchmark/raw-run-055-fresh-agent-c-current-comparison-wave-02.json">RUN-055 fresh Agent C comparison</a></li><li><a href="evidence/benchmark/raw-run-056-independent-adjudicator-input-wave-02.json">RUN-056 sealed Agent D input</a></li><li><a href="evidence/benchmark/raw-run-056-fresh-independent-corrected-chain-adjudication-wave-02.json">RUN-056 fresh independent adjudication</a></li><li><a href="evidence/benchmark/current-facet-neutral-comparison-wave-02.json">RUN-057 integrated corrected-chain overlay</a></li><li><a href="evidence/benchmark/current-facet-neutral-comparison-agent-register.json">RUN-057 corrected-chain agent register</a></li></ul></section>
    <p class="footer">Generated deterministically from independently reviewed static, Git/source, bounded MED-RBAC runtime, and exact-artifact evidence through RUN-159R, reported in RUN-160, and attribution-corrected in RUN-161. Exactly two matrix rows have static benchmark-mapping credit; RUN-159 establishes only the bounded already-fixed MED-RBAC disposition and $bounded_tests tests / $bounded_assertions assertions. For MED-RBAC-01 in this bounded adjudication wave, no application remediation was required or performed. That statement grants no disposition or remediation credit to any other finding. All application-browser, full-suite/coverage, ease, release, Pass, final-finding, feature-completion, and audit-completion boundaries remain open.</p>
  </main>
</body>
</html>
""")


fleet_observation_items = "".join(
    f'<li><strong>{html.escape(row["observation_id"])}</strong>: {html.escape(row["observation"])} <span class="mono">PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING</span></li>'
    for row in fleet_observations["observations"]
)
live_findings_by_id = {row["id"]: row for row in live_findings}
medication_record_items = "".join(
    '<li><strong>{}</strong> · canonical historical record <span class="mono">{}</span> · historical application pin <span class="mono">{}</span> · {} · zero final-finding/completion credit</li>'.format(
        html.escape(row["id"]),
        html.escape(row["canonical_record_sha256"]),
        html.escape(row["audited_application_commit"]),
        (
            "historical issue already fixed on current main; bounded manual-entry MED-CD-ATOMICITY adjudication only; residual compound scope unadjudicated"
            if row["id"] == "MED-CD-ATOMICITY-01"
            else (
                "historical issue remediated on local main but not published to origin/main; SAFE concern-identity-only bounded remediation"
                if row["id"] == "SAFE-ALERT-DEDUP-IDENTITY-01"
                else (
                    "historical issue already fixed on current main; MED-RBAC-only bounded adjudication"
                    if live_findings_by_id[row["id"]]["record_status"] == "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING"
                    else (
                        "historical issue remediated on current main; MED-CD-SCOPE-only bounded remediation"
                        if live_findings_by_id[row["id"]]["record_status"] == "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
                        else "current reference-only provisional claim; no disposition inherited from any adjudicated record"
                    )
                )
            )
        ),
    )
    for row in run_156_references["records"]
)

# The dashboard template preserves long historical checkpoint prose. Apply a
# small, asserted set of current-state rewrites rather than mutating immutable
# historical receipts or discovery packets.
current_reporting_template_rewrites = [
    (
        "RUN-071–157 current reporting checkpoint:",
        "RUN-071–160 current reporting checkpoint:",
    ),
    (
        "six provisional source observations remain separate from the 12 provisional findings",
        "six provisional source observations remain separate from the 12 retained claim records ($finding_count current provisional + $historical_fixed_count historical already-fixed)",
    ),
    (
        "RUN-156/R record and independently review bounded two-checkpoint medication-governance Git/source provenance; RUN-157 reports that receipt without changing any current count. Current-source framework reachability, medication correctness, runtime, browser, build, rendered visual, executed-test, test-coverage, ease, release, Pass, final-finding, feature-completion, remote-currency, publication, and audit-completion credit remain zero.",
        "RUN-156/R record and independently review bounded two-checkpoint medication-governance Git/source provenance; RUN-157 reports that receipt; RUN-158 verifies that exact superseded dashboard; RUN-159 establishes the bounded MED-RBAC ALREADY_FIXED disposition after three unanimous current-source reviews, $bounded_tests tests / $bounded_assertions assertions, and an exact-artifact GO; RUN-159R independently authorizes retirement reporting; RUN-160 reclassifies MED-RBAC-01 from current provisional to retained historical already-fixed and reports the live $finding_count + $historical_fixed_count split. Current-source framework reachability beyond the bounded MED-RBAC slice, application browser, build, rendered visual, full-suite coverage, ease, release, Pass, final-finding, feature-completion, remote-currency, publication, and audit-completion remain open.",
    ),
    (
        "Missing dependencies, build provenance, database isolation, and attributable authenticated access keep framework/runtime/build/test/browser lanes NO-GO and not executed.",
        "Build provenance and attributable authenticated access keep build/application-browser lanes NO-GO; RUN-159 independently establishes only bounded MED-RBAC MySQL test execution.",
    ),
    (
        "RUN-157 materializes current reporting without changing Fleet, queue, benchmark, finding, or completion counts.",
        "RUN-157 materializes its historical reporting checkpoint; RUN-158 verifies that exact dashboard; RUN-159 establishes the MED-RBAC-01 ALREADY_FIXED disposition after bounded source/runtime evidence; RUN-159R independently authorizes retirement reporting; RUN-160 alone reconciles the live finding register without changing Fleet ownership, direct-exact review-queue, benchmark, or completion counts.",
    ),
    (
        "Medication correctness, runtime, application-browser, executed-test, test-coverage, ease, release, Pass, final-finding, feature-completion, remote-currency, publication, and audit-completion credit remain zero.",
        "Medication correctness outside the bounded MED-RBAC already-fixed disposition, application-browser, full-suite/coverage, ease, release, Pass, final-finding, feature-completion, remote-currency, publication, and audit-completion credit remain zero.",
    ),
    (
        "preserve six provisional-not-final observations separately from 12 provisional findings",
        "preserve six provisional-not-final observations separately from 12 retained claim identities ($finding_count current provisional + $historical_fixed_count historical already-fixed)",
    ),
    (
        "RUN-157 reports only that bounded receipt class while every Fleet, queue, benchmark, finding, and completion count remains unchanged. Static relation, structural classification, registration, public/login, Git/source-receipt, or audit-dashboard artifacts are not measured task, medication correctness, framework reachability, attributable application browser, runtime, Pass, final-finding, feature-completion, or completion evidence; only the exact two RUN-145 static benchmark mappings receive mapping credit.",
        "RUN-157 reports only its bounded receipt class; RUN-158 verifies the exact superseded artifact; RUN-159 establishes MED-RBAC-only already-fixed source and bounded-runtime evidence; RUN-159R independently reviews the corrected exact artifact and authorizes retirement reporting; RUN-160 alone changes the live finding queue from 12 provisional to $finding_count provisional plus $historical_fixed_count historical already-fixed while every Fleet ownership, direct-exact review-queue, benchmark, final-finding, and completion count remains unchanged. Static relation, structural classification, registration, public/login, Git/source-receipt, bounded MED-RBAC runtime, or audit-dashboard artifacts are not full-feature, attributable application-browser, Pass, final-finding, feature-completion, or completion evidence; only the exact two RUN-145 static benchmark mappings receive mapping credit.",
    ),
    (
        "RUN-001 through RUN-157 are represented by audit artifacts.",
        "RUN-001 through RUN-160 are represented by audit artifacts.",
    ),
    (
        "<li>RUN-157: deterministic current reporting with Fleet, queue, benchmark, finding, and completion counts unchanged · fresh RUN-158 dashboard verification required</li>",
        "<li>RUN-157: historical deterministic reporting checkpoint</li><li>RUN-158: exact RUN-157 dashboard verified at 4/4 viewports · 50/50 visible checks · 10/10 navigation · 387/387 local resources · zero application credit</li><li>RUN-159: three unanimous current-source ALREADY_FIXED reviews · $bounded_tests tests / $bounded_assertions assertions · MED-RBAC-only disposition established</li><li>RUN-159R: exact corrected receipt GO · retirement-reporting authorization · zero live-register or downstream credit</li><li>RUN-160: live register reconciled MED-RBAC-01 from current provisional to retained historical already-fixed · $finding_count current provisional P1 + $historical_fixed_count historical already-fixed · fresh RUN-161 dashboard verification required</li>",
    ),
    (
        "<li><span class=\"zero\">0</span> current application tests</li>",
        "<li><span class=\"partial\">$bounded_tests</span> bounded MED-RBAC tests / $bounded_assertions assertions; no full-suite or coverage credit</li>",
    ),
    (
        "RUN-151, and RUN-155 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-157 dashboard.",
        "RUN-151, RUN-155, and RUN-158 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-160 dashboard.",
    ),
    (
        "<li><a href=\"evidence/browser/current-audit-dashboard-verification-run-155-wave-26.json\">Superseded RUN-155 verification GO</a></li></ul>",
        "<li><a href=\"evidence/browser/current-audit-dashboard-verification-run-155-wave-26.json\">Superseded RUN-155 verification GO</a></li><li><a href=\"evidence/browser/current-audit-dashboard-verification-run-158-wave-27.json\">Superseded RUN-158 verification GO</a></li></ul>",
    ),
    (
        "preserving six provisional-not-final observations separately from the 12 provisional findings",
        "preserving six provisional-not-final observations separately from the 12 retained claim identities ($finding_count current provisional + $historical_fixed_count historical already-fixed)",
    ),
    (
        "6 provisional source observations separate from 12 provisional findings",
        "6 provisional source observations separate from 12 retained claim identities ($finding_count current provisional + $historical_fixed_count historical already-fixed)",
    ),
]
current_template_text = TEMPLATE.template
template_rewrite_expected_counts = {
    "preserve six provisional-not-final observations separately from 12 provisional findings": 2,
}
for old, new in current_reporting_template_rewrites:
    expected_count = template_rewrite_expected_counts.get(old, 1)
    assert current_template_text.count(old) == expected_count, f"Expected {expected_count} current-reporting template rewrite target(s): {old}"
    current_template_text = current_template_text.replace(old, new)

run_163_template_rewrites = [
    (
        "the latest bounded MED-RBAC adjudication is pinned to <span class=\"mono\">$application_short</span>",
        "the MED-RBAC already-fixed adjudication is pinned to <span class=\"mono\">$med_rbac_application_short</span>, while the latest bounded MED-CD-SCOPE remediation is pinned to application <span class=\"mono\">$application_short</span> and full repository tree <span class=\"mono\">$application_tree_short</span>",
    ),
    ('href="#checkpoint">RUN-160</a>', 'href="#checkpoint">RUN-163</a>'),
    ("RUN-071–160 current reporting checkpoint:", "RUN-071–163 current reporting checkpoint:"),
    ("RUN-071–160 completion-gate checkpoint", "RUN-071–163 completion-gate checkpoint"),
    (
        "RUN-160 reclassifies MED-RBAC-01 from current provisional to retained historical already-fixed and reports the live $finding_count + $historical_fixed_count split.",
        "RUN-160 reclassifies MED-RBAC-01 from current provisional to retained historical already-fixed; RUN-161 verifies that exact dashboard; RUN-162 establishes MED-CD-SCOPE reproduction, narrow remediation, bounded runtime, integration, and application-commit publication; RUN-162R independently authorizes retirement reporting; RUN-163 alone reclassifies MED-CD-SCOPE-01 from current provisional to retained historical remediated and reports the live $finding_count + $historical_fixed_count + $historical_remediated_count split.",
    ),
    (
        "Current-source framework reachability beyond the bounded MED-RBAC slice, application browser, build, rendered visual, full-suite coverage, ease, release, Pass, final-finding, feature-completion, remote-currency, publication, and audit-completion remain open.",
        "Current-source framework reachability beyond the bounded MED-RBAC and MED-CD-SCOPE slices, application browser, rendered visual, full-suite coverage, ease, release, Pass, final-finding, feature-completion, broader remote-currency, audit-artifact publication, and audit-completion remain open.",
    ),
    (
        "Build provenance and attributable authenticated access keep build/application-browser lanes NO-GO; RUN-159 independently establishes only bounded MED-RBAC MySQL test execution.",
        "Attributable authenticated access keeps the application-browser lane NO-GO; RUN-159 establishes only bounded MED-RBAC MySQL execution, and RUN-162 separately establishes the bounded MED-CD-SCOPE remediation/runtime/integration/publication slice at its exact application commit and tree.",
    ),
    (
        "RUN-157 materializes its historical reporting checkpoint; RUN-158 verifies that exact dashboard; RUN-159 establishes the MED-RBAC-01 ALREADY_FIXED disposition after bounded source/runtime evidence; RUN-159R independently authorizes retirement reporting; RUN-160 alone reconciles the live finding register without changing Fleet ownership, direct-exact review-queue, benchmark, or completion counts.",
        "RUN-157 materializes its historical reporting checkpoint; RUN-158 verifies that exact dashboard; RUN-159 establishes the MED-RBAC-01 ALREADY_FIXED disposition after bounded source/runtime evidence; RUN-159R independently authorizes retirement reporting; RUN-160 alone reconciles that live status; RUN-161 verifies the exact RUN-160 dashboard; RUN-162 establishes MED-CD-SCOPE-01 reproduction/remediation/runtime/integration/application-commit publication; RUN-162R independently authorizes its retirement reporting; RUN-163 alone reconciles the MED-CD-SCOPE live status without changing Fleet ownership, direct-exact review-queue, benchmark, or completion counts.",
    ),
    (
        "Medication correctness outside the bounded MED-RBAC already-fixed disposition, application-browser, full-suite/coverage, ease, release, Pass, final-finding, feature-completion, remote-currency, publication, and audit-completion credit remain zero.",
        "Medication correctness outside the bounded MED-RBAC already-fixed and MED-CD-SCOPE remediated dispositions, application-browser, full-suite/coverage, ease, release, Pass, final-finding, feature-completion, broader remote-currency, audit-artifact publication, and audit-completion credit remain zero.",
    ),
    (
        "RUN-160 alone changes the live finding queue from 12 provisional to $finding_count provisional plus $historical_fixed_count historical already-fixed while every Fleet ownership, direct-exact review-queue, benchmark, final-finding, and completion count remains unchanged.",
        "RUN-160 alone changes MED-RBAC in the live queue; RUN-161 verifies its dashboard; RUN-162 establishes only MED-CD-SCOPE reproduction/remediation/runtime/integration/application-commit publication; RUN-162R alone authorizes retirement reporting; RUN-163 alone changes the MED-CD-SCOPE live status to $historical_remediated_count historical remediated, leaving $finding_count provisional plus $historical_fixed_count historical already-fixed while every Fleet ownership, direct-exact review-queue, benchmark, final-finding, and completion count remains unchanged.",
    ),
    (
        "bounded MED-RBAC runtime, or audit-dashboard artifacts are not full-feature",
        "bounded claim-specific runtime/remediation, or audit-dashboard artifacts are not full-feature",
    ),
    ("RUN-001 through RUN-160 are represented by audit artifacts.", "RUN-001 through RUN-163 are represented by audit artifacts."),
    (
        "RUN-145 grants exactly two target-specific static benchmark-mapping credits; no represented wave grants current-source application runtime, signed-in browser, executed-test, ease, release, Pass, final-finding, feature-completion, or audit-completion credit.",
        "RUN-145 grants exactly two target-specific static benchmark-mapping credits. Apart from the separately bounded RUN-159 MED-RBAC and RUN-162 MED-CD-SCOPE executions, no represented wave grants broader or full-suite application runtime or coverage; no represented wave grants signed-in application-browser, ease, release, Pass, final-finding, feature-completion, or audit-completion credit.",
    ),
    (
        "<li>RUN-160: live register reconciled MED-RBAC-01 from current provisional to retained historical already-fixed · $finding_count current provisional P1 + $historical_fixed_count historical already-fixed · fresh RUN-161 dashboard verification required</li>",
        "<li>RUN-160: live register reconciled MED-RBAC-01 from current provisional to retained historical already-fixed</li><li>RUN-161: exact RUN-160 dashboard verified at 4/4 viewports · 63/63 visible checks · 10/10 navigation · 395/395 local resources · zero application credit</li><li>RUN-162: five MED-CD-SCOPE defects reproduced and narrowly remediated · $med_cd_tests focused tests / $med_cd_assertions assertions · application <span class=\"mono\">$application_short</span> and tree <span class=\"mono\">$application_tree_short</span> integrated · application commit published · full-suite green false</li><li>RUN-162R: exact remediation receipt GO · retirement-reporting authorization · zero live-register or downstream credit</li><li>RUN-163: live register reconciled MED-CD-SCOPE-01 from current provisional to retained historical remediated · $finding_count current provisional P1 + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated · fresh RUN-164 dashboard verification required</li>",
    ),
    (
        "<li><span class=\"partial\">$bounded_tests</span> bounded MED-RBAC tests / $bounded_assertions assertions; no full-suite or coverage credit</li>",
        "<li><span class=\"partial\">$med_rbac_tests</span> bounded MED-RBAC tests / $med_rbac_assertions assertions</li><li><span class=\"partial\">$med_cd_tests</span> focused MED-CD-SCOPE tests / $med_cd_assertions assertions; separate denominators · no full-suite or coverage credit</li>",
    ),
    (
        "RUN-151, RUN-155, and RUN-158 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-160 dashboard.",
        "RUN-151, RUN-155, RUN-158, and RUN-161 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-163 dashboard.",
    ),
    (
        "<li><a href=\"evidence/browser/current-audit-dashboard-verification-run-158-wave-27.json\">Superseded RUN-158 verification GO</a></li></ul>",
        "<li><a href=\"evidence/browser/current-audit-dashboard-verification-run-158-wave-27.json\">Superseded RUN-158 verification GO</a></li><li><a href=\"evidence/browser/current-audit-dashboard-verification-run-161-wave-28.json\">Superseded RUN-161 verification GO</a></li></ul>",
    ),
    (
        "$finding_count current provisional + $historical_fixed_count historical already-fixed",
        "$finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated",
    ),
    (
        "<div class=\"card\"><strong class=\"partial\">$finding_count</strong><span>current provisional P1 claims</span><small>$historical_fixed_count retained historical already-fixed · none final</small></div><div class=\"card\"><strong>$bounded_tests</strong><span>bounded MED-RBAC tests</span><small>$bounded_assertions assertions · no full-suite or coverage credit</small></div>",
        "<div class=\"card\"><strong class=\"partial\">$finding_count</strong><span>current provisional P1 claims</span><small>$historical_fixed_count historical already-fixed · $historical_remediated_count historical remediated · none final</small></div><div class=\"card\"><strong>$med_cd_tests</strong><span>focused MED-CD-SCOPE tests</span><small>$med_cd_assertions assertions · separate RUN-159 MED-RBAC $med_rbac_tests/$med_rbac_assertions · no full-suite credit</small></div>",
    ),
    (
        "RUN-158–160 current adjudication checkpoint",
        "RUN-161–163 current remediation and reporting checkpoint",
    ),
    (
        "<tr><td>RUN-160 live reporting</td><td><strong>$finding_count current provisional P1 + $historical_fixed_count historical already-fixed · 12 retained identities</strong></td><td class=\"partial\">MED-RBAC-01 reclassified from current provisional to retained historical already-fixed · Gate 4 and audit completion false · exact regenerated dashboard requires RUN-161 verification</td></tr>",
        "<tr><td>RUN-160 MED-RBAC live reporting</td><td><strong>11 current provisional P1 + 1 historical already-fixed · 12 retained identities</strong></td><td class=\"partial\">MED-RBAC-01 reclassified; exact dashboard later verified by RUN-161</td></tr><tr><td>RUN-161 exact dashboard verification</td><td><strong>4/4 viewports · 63/63 visible checks · 10/10 navigation · 395/395 local resources</strong></td><td class=\"partial\">exact superseded RUN-160 audit artifact only · zero application credit</td></tr><tr><td>RUN-162 MED-CD-SCOPE remediation</td><td><strong>5 defects · $med_cd_tests focused tests / $med_cd_assertions assertions · $med_cd_related_tests related controller/command tests pass</strong></td><td class=\"partial\">reproduction/remediation/runtime/integration/application-commit publication only · two broader INR failures reproduce at base · full-suite green false</td></tr><tr><td>RUN-162R exact receipt review</td><td><strong>exact receipt GO · zero discrepancies</strong></td><td class=\"partial\">retirement reporting authorized · live-register reconciliation belongs to RUN-163</td></tr><tr><td>RUN-163 live reporting</td><td><strong>$finding_count current provisional P1 + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated · 12 retained identities</strong></td><td class=\"partial\">MED-CD-SCOPE-01 reclassified · Gate 4 and audit completion false · exact regenerated dashboard requires RUN-164 verification</td></tr>",
    ),
    (
        "The register retains 12 historical claim identities: $finding_count remain current provisional P1 claims and $historical_fixed_count is a historical issue already fixed on current main. None is a final finding or closed completion gate.",
        "The register retains 12 historical claim identities: $finding_count remain current provisional P1 claims, $historical_fixed_count is historical already fixed on current main, and $historical_remediated_count is historical remediated on current main. None is a final finding or closed completion gate.",
    ),
    (
        "RUN-159 establishes bounded current-source MED-RBAC test execution only. It does not establish full-suite, coverage, application-browser, build, ease, release, Pass, or completion credit.",
        "RUN-159 establishes bounded current-source MED-RBAC test execution; RUN-162 separately establishes focused MED-CD-SCOPE remediation execution. Neither establishes full-suite, coverage, application-browser, ease, release, Pass, or completion credit.",
    ),
    (
        "</tr><tr><td>RUN-089 designated-application preflight</td>",
        "</tr><tr><td>RUN-162 focused MED-CD-SCOPE execution</td><td>$med_cd_tests tests / $med_cd_assertions assertions passed on advanced main; $med_cd_related_tests related controller/command tests passed in the overlapping broader lane; its 2 INR failures reproduced at base and are not attributed to RUN-162</td><td class=\"partial\">bounded MED-CD-SCOPE remediation evidence only · full-suite green false · no coverage credit</td></tr><tr><td>RUN-089 designated-application preflight</td>",
    ),
    ("task-local ignored <span class=\"mono\">vendor/</span> hydrated for RUN-159", "task-local ignored <span class=\"mono\">vendor/</span> hydrated for RUN-159 and RUN-162 bounded lanes"),
    ("RUN-155–160 medication-governance provenance and MED-RBAC adjudication", "RUN-155–163 medication-governance provenance, adjudication, and remediation"),
    (
        "RUN-158 verifies only the exact now-superseded RUN-157 audit-dashboard artifact: 4/4 required viewports, 50/50 visible boundary checks, 10/10 navigation targets, 387/387 local resources, and zero console warnings, console errors, or page errors. None of that proof transfers to the RUN-160 dashboard or the application.",
        "RUN-158 verifies only the exact RUN-157 dashboard; RUN-161 separately verifies only the exact now-superseded RUN-160 dashboard at 4/4 required viewports with 63/63 visible boundary checks, 10/10 navigation targets, 395/395 local resources, and zero console warnings, console errors, or page errors. Neither receipt transfers to the RUN-163 dashboard or the application.",
    ),
    (
        "<span class=\"mono\">MED-CD-SCOPE-01</span> and <span class=\"mono\">MED-CD-ATOMICITY-01</span> remain separate current provisional claims: they inherit no source, runtime, browser, closure, or completion credit from MED-RBAC. RUN-160 reports 12 retained identities = $finding_count current provisional P1 + $historical_fixed_count historical already-fixed, with $benchmark_mapped/340 mappings, $final_no_matches/340 final no-match/NCM, $benchmark_unresolved unresolved targets, Gate 4 false, and fresh RUN-161 audit-dashboard verification required.",
        "RUN-162 establishes <span class=\"mono\">MED-CD-SCOPE-01</span> reproduction, narrow remediation, focused runtime, integration, and application-commit publication at <span class=\"mono\">$application_short</span> / tree <span class=\"mono\">$application_tree_short</span>; RUN-162R independently authorizes retirement reporting; RUN-163 alone records it as historical remediated. <span class=\"mono\">MED-CD-ATOMICITY-01</span> remains current provisional and inherits no transaction, retry, rollback, lock-order, fractional-value, operation-level concurrency, browser, benchmark, final-finding, Pass, or completion credit. RUN-163 reports 12 retained identities = $finding_count current provisional P1 + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated, with $benchmark_mapped/340 mappings, $final_no_matches/340 final no-match/NCM, $benchmark_unresolved unresolved targets, Gate 4 false, and fresh RUN-164 audit-dashboard verification required.",
    ),
    ("Fresh RUN-161 audit-reporting correction and dashboard verification required", "Fresh RUN-164 audit-dashboard verification required"),
    (
        "The exact RUN-161-corrected reporting dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844.",
        "The exact RUN-163 reporting dashboard must be checked in RUN-164 at 1440×900, 1280×800, 1024×768, and 390×844.",
    ),
    (
        "RUN-161 corrects attribution wording only across the executive summary, repository module map, live findings register, dashboard builder, and dashboard: RUN-159 establishes the ALREADY_FIXED disposition, RUN-159R independently authorizes retirement reporting, and RUN-160 alone performs the live-register reconciliation.",
        "RUN-162 establishes the MED-CD-SCOPE remediation/runtime/integration/application-commit publication facts, RUN-162R independently authorizes retirement reporting, and RUN-163 alone performs the live-register/reporting reconciliation; none of those supplies audit-dashboard verification for the new HTML.",
    ),
    (
        "The linked RUN-161 receipt must record",
        "The linked RUN-164 receipt must record",
    ),
    (
        "12 retained claim identities split into $finding_count current provisional P1 and $historical_fixed_count historical already-fixed, RUN-159's",
        "12 retained claim identities split into $finding_count current provisional P1, $historical_fixed_count historical already-fixed, and $historical_remediated_count historical remediated, RUN-159's",
    ),
    (
        "RUN-159's $bounded_tests tests / $bounded_assertions assertions and ALREADY_FIXED evidence, RUN-159R retirement-reporting authorization, RUN-160's exact MED-RBAC-only current-to-historical reconciliation, no scope/atomicity inheritance",
        "RUN-159's $med_rbac_tests tests / $med_rbac_assertions assertions and ALREADY_FIXED evidence, RUN-159R retirement-reporting authorization, RUN-160's exact MED-RBAC-only reconciliation, RUN-162's $med_cd_tests focused tests / $med_cd_assertions assertions and exact application/tree pins, RUN-162R retirement-reporting authorization, RUN-163's exact MED-CD-SCOPE-only reconciliation, atomicity noninheritance",
    ),
    (
        "It verifies the corrected audit artifact only",
        "It verifies the RUN-163 audit artifact only",
    ),
    (
        '<a href="evidence/browser/current-audit-dashboard-verification-run-161-wave-28.json">RUN-161 corrected reporting and responsive audit-dashboard verification receipt</a> (forward reference until materialized)',
        '<a href="evidence/browser/current-audit-dashboard-verification-run-164-wave-29.json">RUN-164 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)',
    ),
    ("RUN-071–160 evidence lineage", "RUN-071–163 evidence lineage"),
    (
        "Every current raw, generated, reviewed, and integrated RUN-077–159/R source/reporting/runtime/benchmark artifact is linked with its exact SHA-256; RUN-160 is the current reporting generator execution.",
        "Every current raw, generated, reviewed, and integrated RUN-077–162/R source/reporting/runtime/benchmark/remediation artifact is linked with its exact SHA-256; RUN-163 is the current reporting generator execution.",
    ),
    (
        "Generated deterministically from independently reviewed static, Git/source, bounded MED-RBAC runtime, and exact-artifact evidence through RUN-159R, reported in RUN-160, and attribution-corrected in RUN-161. Exactly two matrix rows have static benchmark-mapping credit; RUN-159 establishes only the bounded already-fixed MED-RBAC disposition and $bounded_tests tests / $bounded_assertions assertions. For MED-RBAC-01 in this bounded adjudication wave, no application remediation was required or performed. That statement grants no disposition or remediation credit to any other finding.",
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, and exact-artifact evidence through RUN-162R, reported in RUN-163, with fresh RUN-164 dashboard verification still required. Exactly two matrix rows have static benchmark-mapping credit. RUN-159 separately establishes the bounded already-fixed MED-RBAC disposition and $med_rbac_tests tests / $med_rbac_assertions assertions; RUN-162 separately establishes the bounded remediated MED-CD-SCOPE disposition and $med_cd_tests focused tests / $med_cd_assertions assertions. The denominators are not one execution, and neither grants any disposition or remediation credit to MED-CD-ATOMICITY-01 or another finding.",
    ),
]
run_163_rewrite_expected_counts = {
    "$finding_count current provisional + $historical_fixed_count historical already-fixed": 6,
}
for old, new in run_163_template_rewrites:
    expected_count = run_163_rewrite_expected_counts.get(old, 1)
    assert current_template_text.count(old) == expected_count, f"Expected {expected_count} RUN-163 template rewrite target(s): {old}"
    current_template_text = current_template_text.replace(old, new)

run_167_template_rewrites = [
    (
        "the MED-RBAC already-fixed adjudication is pinned to <span class=\"mono\">$med_rbac_application_short</span>, while the latest bounded MED-CD-SCOPE remediation is pinned to application <span class=\"mono\">$application_short</span> and full repository tree <span class=\"mono\">$application_tree_short</span>",
        "the MED-RBAC already-fixed adjudication is pinned to <span class=\"mono\">$med_rbac_application_short</span>, the bounded MED-CD-SCOPE remediation to application <span class=\"mono\">$application_short</span> and tree <span class=\"mono\">$application_tree_short</span>, and the bounded manual-entry MED-CD-ATOMICITY source adjudication to <span class=\"mono\">$atomicity_application_short</span>",
    ),
    ('href="#checkpoint">RUN-163</a>', 'href="#checkpoint">RUN-167</a>'),
    ("RUN-071–163 current reporting checkpoint:", "RUN-071–167 current reporting checkpoint:"),
    ("RUN-071–163 completion-gate checkpoint", "RUN-071–167 completion-gate checkpoint"),
    (
        "RUN-160 reclassifies MED-RBAC-01 from current provisional to retained historical already-fixed; RUN-161 verifies that exact dashboard; RUN-162 establishes MED-CD-SCOPE reproduction, narrow remediation, bounded runtime, integration, and application-commit publication; RUN-162R independently authorizes retirement reporting; RUN-163 alone reclassifies MED-CD-SCOPE-01 from current provisional to retained historical remediated and reports the live $finding_count + $historical_fixed_count + $historical_remediated_count split.",
        "RUN-160 reclassifies MED-RBAC-01; RUN-161 verifies that dashboard; RUN-162/R establish and authorize MED-CD-SCOPE remediation reporting; RUN-163 reclassifies MED-CD-SCOPE-01; RUN-164 verifies that dashboard; RUN-165 establishes the manual-entry atomicity source candidate; RUN-166 establishes its bounded ALREADY_FIXED source/runtime disposition; RUN-166R independently authorizes retirement reporting; RUN-167 alone reclassifies the bounded manual-entry MED-CD-ATOMICITY-01 clause and reports the live $finding_count + $historical_fixed_count + $historical_remediated_count split.",
    ),
    (
        "Current-source framework reachability beyond the bounded MED-RBAC and MED-CD-SCOPE slices, application browser, rendered visual, full-suite coverage, ease, release, Pass, final-finding, feature-completion, broader remote-currency, audit-artifact publication, and audit-completion remain open.",
        "Current-source framework reachability beyond the bounded MED-RBAC, MED-CD-SCOPE, and manual-entry MED-CD-ATOMICITY slices; residual atomicity compound scope; application browser; rendered visual; full-suite coverage; ease; release; Pass; final-finding; feature-completion; broader remote-currency; audit-artifact publication; and audit-completion remain open.",
    ),
    (
        "Attributable authenticated access keeps the application-browser lane NO-GO; RUN-159 establishes only bounded MED-RBAC MySQL execution, and RUN-162 separately establishes the bounded MED-CD-SCOPE remediation/runtime/integration/publication slice at its exact application commit and tree.",
        "Attributable authenticated access keeps the application-browser lane NO-GO; RUN-159 establishes bounded MED-RBAC MySQL execution, RUN-162 separately establishes bounded MED-CD-SCOPE remediation/runtime/integration/publication, and RUN-166 separately establishes bounded manual-entry MED-CD-ATOMICITY execution without application or product-test change.",
    ),
    (
        "RUN-157 materializes its historical reporting checkpoint; RUN-158 verifies that exact dashboard; RUN-159 establishes the MED-RBAC-01 ALREADY_FIXED disposition after bounded source/runtime evidence; RUN-159R independently authorizes retirement reporting; RUN-160 alone reconciles that live status; RUN-161 verifies the exact RUN-160 dashboard; RUN-162 establishes MED-CD-SCOPE-01 reproduction/remediation/runtime/integration/application-commit publication; RUN-162R independently authorizes its retirement reporting; RUN-163 alone reconciles the MED-CD-SCOPE live status without changing Fleet ownership, direct-exact review-queue, benchmark, or completion counts.",
        "RUN-157 materializes historical reporting; RUN-158 verifies that dashboard; RUN-159 establishes and RUN-159R authorizes MED-RBAC already-fixed reporting; RUN-160 reconciles that live status; RUN-161 verifies that dashboard; RUN-162 establishes and RUN-162R authorizes MED-CD-SCOPE remediation reporting; RUN-163 reconciles that live status; RUN-164 verifies that dashboard; RUN-165 establishes source-only manual-entry atomicity review; RUN-166 establishes its bounded ALREADY_FIXED runtime disposition without remediation; RUN-166R independently authorizes retirement reporting; RUN-167 alone reconciles the bounded atomicity live status without changing Fleet ownership, direct-exact review-queue, benchmark, final-finding, or completion counts.",
    ),
    (
        "Medication correctness outside the bounded MED-RBAC already-fixed and MED-CD-SCOPE remediated dispositions, application-browser, full-suite/coverage, ease, release, Pass, final-finding, feature-completion, broader remote-currency, audit-artifact publication, and audit-completion credit remain zero.",
        "Medication correctness outside the bounded MED-RBAC already-fixed, MED-CD-SCOPE remediated, and manual-entry MED-CD-ATOMICITY already-fixed dispositions—including balance-check, destruction, sibling-writer, forced-deadlock-retry, and stress scope—remains unadjudicated or zero-credit; application-browser, full-suite/coverage, ease, release, Pass, final-finding, feature-completion, broader remote-currency, audit-artifact publication, and audit-completion credit remain zero.",
    ),
    (
        "RUN-160 alone changes MED-RBAC in the live queue; RUN-161 verifies its dashboard; RUN-162 establishes only MED-CD-SCOPE reproduction/remediation/runtime/integration/application-commit publication; RUN-162R alone authorizes retirement reporting; RUN-163 alone changes the MED-CD-SCOPE live status to $historical_remediated_count historical remediated, leaving $finding_count provisional plus $historical_fixed_count historical already-fixed while every Fleet ownership, direct-exact review-queue, benchmark, final-finding, and completion count remains unchanged.",
        "RUN-160 alone changes MED-RBAC in the live queue; RUN-161 verifies its dashboard; RUN-162/R establish and authorize MED-CD-SCOPE remediation reporting; RUN-163 changes that live status; RUN-164 verifies its dashboard; RUN-165/166 establish source and bounded runtime for the manual-entry atomicity ALREADY_FIXED disposition; RUN-166R authorizes reporting; RUN-167 alone changes the bounded atomicity live status, leaving $finding_count provisional plus $historical_fixed_count historical already-fixed plus $historical_remediated_count historical remediated while every Fleet ownership, direct-exact review-queue, benchmark, final-finding, and completion count remains unchanged.",
    ),
    ("RUN-001 through RUN-163 are represented by audit artifacts.", "RUN-001 through RUN-167 are represented by audit artifacts."),
    (
        "RUN-145 grants exactly two target-specific static benchmark-mapping credits. Apart from the separately bounded RUN-159 MED-RBAC and RUN-162 MED-CD-SCOPE executions, no represented wave grants broader or full-suite application runtime or coverage; no represented wave grants signed-in application-browser, ease, release, Pass, final-finding, feature-completion, or audit-completion credit.",
        "RUN-145 grants exactly two target-specific static benchmark-mapping credits. Apart from separately bounded RUN-159 MED-RBAC, RUN-162 MED-CD-SCOPE, and RUN-166 manual-entry MED-CD-ATOMICITY executions, no represented wave grants broader or full-suite application runtime or coverage; no represented wave grants signed-in application-browser, ease, release, Pass, final-finding, feature-completion, or audit-completion credit.",
    ),
    (
        "<li>RUN-160: live register reconciled MED-RBAC-01 from current provisional to retained historical already-fixed</li><li>RUN-161: exact RUN-160 dashboard verified at 4/4 viewports · 63/63 visible checks · 10/10 navigation · 395/395 local resources · zero application credit</li><li>RUN-162: five MED-CD-SCOPE defects reproduced and narrowly remediated · $med_cd_tests focused tests / $med_cd_assertions assertions · application <span class=\"mono\">$application_short</span> and tree <span class=\"mono\">$application_tree_short</span> integrated · application commit published · full-suite green false</li><li>RUN-162R: exact remediation receipt GO · retirement-reporting authorization · zero live-register or downstream credit</li><li>RUN-163: live register reconciled MED-CD-SCOPE-01 from current provisional to retained historical remediated · $finding_count current provisional P1 + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated · fresh RUN-164 dashboard verification required</li>",
        "<li>RUN-160: live register reconciled MED-RBAC-01 from current provisional to retained historical already-fixed</li><li>RUN-161: exact RUN-160 dashboard verified at 4/4 viewports · 63/63 visible checks · 10/10 navigation · 395/395 local resources · zero application credit</li><li>RUN-162: five MED-CD-SCOPE defects reproduced and narrowly remediated · $med_cd_tests focused tests / $med_cd_assertions assertions · application <span class=\"mono\">$application_short</span> and tree <span class=\"mono\">$application_tree_short</span> integrated · application commit published · full-suite green false</li><li>RUN-162R: exact remediation receipt GO · retirement-reporting authorization · zero live-register or downstream credit</li><li>RUN-163: live register reconciled MED-CD-SCOPE-01 from current provisional to retained historical remediated</li><li>RUN-164: exact RUN-163 dashboard verified at 4/4 viewports · 33/33 visible checks · 10/10 navigation · 403/403 local resources · zero application credit</li><li>RUN-165: source-only bounded manual-entry atomicity already-fixed candidate · zero runtime outcome</li><li>RUN-166: bounded manual-entry ALREADY_FIXED adjudication · $atomicity_tests test functions / $atomicity_assertions assertions / $atomicity_races synchronized races · no application or product-test change</li><li>RUN-166R: exact receipt and harness GO · retirement-reporting authorization only</li><li>RUN-167: live register reconciled bounded MED-CD-ATOMICITY-01 from current provisional to retained historical already-fixed · $finding_count current provisional P1 + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated · fresh RUN-168 dashboard verification required</li>",
    ),
    (
        "<li><span class=\"partial\">$med_rbac_tests</span> bounded MED-RBAC tests / $med_rbac_assertions assertions</li><li><span class=\"partial\">$med_cd_tests</span> focused MED-CD-SCOPE tests / $med_cd_assertions assertions; separate denominators · no full-suite or coverage credit</li>",
        "<li><span class=\"partial\">$med_rbac_tests</span> bounded MED-RBAC tests / $med_rbac_assertions assertions</li><li><span class=\"partial\">$med_cd_tests</span> focused MED-CD-SCOPE tests / $med_cd_assertions assertions</li><li><span class=\"partial\">$atomicity_tests</span> MED-CD-ATOMICITY test functions / $atomicity_assertions assertions / $atomicity_races synchronized races; separately reported, not added to 78/1,529 · supporting $atomicity_supporting_tests/$atomicity_supporting_assertions overlaps · no full-suite or coverage credit</li>",
    ),
    (
        "RUN-151, RUN-155, RUN-158, and RUN-161 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-163 dashboard.",
        "RUN-151, RUN-155, RUN-158, RUN-161, and RUN-164 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-167 dashboard.",
    ),
    (
        "<li><a href=\"evidence/browser/current-audit-dashboard-verification-run-158-wave-27.json\">Superseded RUN-158 verification GO</a></li><li><a href=\"evidence/browser/current-audit-dashboard-verification-run-161-wave-28.json\">Superseded RUN-161 verification GO</a></li></ul>",
        "<li><a href=\"evidence/browser/current-audit-dashboard-verification-run-158-wave-27.json\">Superseded RUN-158 verification GO</a></li><li><a href=\"evidence/browser/current-audit-dashboard-verification-run-161-wave-28.json\">Superseded RUN-161 verification GO</a></li><li><a href=\"evidence/browser/current-audit-dashboard-verification-run-164-wave-29.json\">Superseded RUN-164 verification GO</a></li></ul>",
    ),
    (
        "<div class=\"card\"><strong class=\"partial\">$finding_count</strong><span>current provisional P1 claims</span><small>$historical_fixed_count historical already-fixed · $historical_remediated_count historical remediated · none final</small></div><div class=\"card\"><strong>$med_cd_tests</strong><span>focused MED-CD-SCOPE tests</span><small>$med_cd_assertions assertions · separate RUN-159 MED-RBAC $med_rbac_tests/$med_rbac_assertions · no full-suite credit</small></div>",
        "<div class=\"card\"><strong class=\"partial\">$finding_count</strong><span>current provisional P1 claims</span><small>$historical_fixed_count historical already-fixed · $historical_remediated_count historical remediated · none final</small></div><div class=\"card\"><strong>$atomicity_tests</strong><span>MED-CD-ATOMICITY test functions</span><small>$atomicity_assertions assertions · $atomicity_races synchronized races · separate from existing 78/1,529</small></div>",
    ),
    ("RUN-161–163 current remediation and reporting checkpoint", "RUN-164–167 current atomicity adjudication and reporting checkpoint"),
    (
        "<tr><td>RUN-160 MED-RBAC live reporting</td><td><strong>11 current provisional P1 + 1 historical already-fixed · 12 retained identities</strong></td><td class=\"partial\">MED-RBAC-01 reclassified; exact dashboard later verified by RUN-161</td></tr><tr><td>RUN-161 exact dashboard verification</td><td><strong>4/4 viewports · 63/63 visible checks · 10/10 navigation · 395/395 local resources</strong></td><td class=\"partial\">exact superseded RUN-160 audit artifact only · zero application credit</td></tr><tr><td>RUN-162 MED-CD-SCOPE remediation</td><td><strong>5 defects · $med_cd_tests focused tests / $med_cd_assertions assertions · $med_cd_related_tests related controller/command tests pass</strong></td><td class=\"partial\">reproduction/remediation/runtime/integration/application-commit publication only · two broader INR failures reproduce at base · full-suite green false</td></tr><tr><td>RUN-162R exact receipt review</td><td><strong>exact receipt GO · zero discrepancies</strong></td><td class=\"partial\">retirement reporting authorized · live-register reconciliation belongs to RUN-163</td></tr><tr><td>RUN-163 live reporting</td><td><strong>$finding_count current provisional P1 + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated · 12 retained identities</strong></td><td class=\"partial\">MED-CD-SCOPE-01 reclassified · Gate 4 and audit completion false · exact regenerated dashboard requires RUN-164 verification</td></tr>",
        "<tr><td>RUN-160 MED-RBAC live reporting</td><td><strong>11 current provisional P1 + 1 historical already-fixed · 12 retained identities</strong></td><td class=\"partial\">MED-RBAC-01 reclassified; exact dashboard later verified by RUN-161</td></tr><tr><td>RUN-161 exact dashboard verification</td><td><strong>4/4 viewports · 63/63 visible checks · 10/10 navigation · 395/395 local resources</strong></td><td class=\"partial\">exact superseded RUN-160 audit artifact only · zero application credit</td></tr><tr><td>RUN-162 MED-CD-SCOPE remediation</td><td><strong>5 defects · $med_cd_tests focused tests / $med_cd_assertions assertions · $med_cd_related_tests related controller/command tests pass</strong></td><td class=\"partial\">reproduction/remediation/runtime/integration/application-commit publication only · two broader INR failures reproduce at base · full-suite green false</td></tr><tr><td>RUN-162R exact receipt review</td><td><strong>exact receipt GO · zero discrepancies</strong></td><td class=\"partial\">retirement reporting authorized · live-register reconciliation belongs to RUN-163</td></tr><tr><td>RUN-163 live reporting</td><td><strong>10 current provisional P1 + 1 historical already-fixed + 1 historical remediated · 12 retained identities</strong></td><td class=\"partial\">MED-CD-SCOPE-01 reclassified; exact dashboard later verified by RUN-164</td></tr><tr><td>RUN-164 exact dashboard verification</td><td><strong>4/4 viewports · 33/33 visible checks · 10/10 navigation · 403/403 local resources</strong></td><td class=\"partial\">exact superseded RUN-163 audit artifact only · zero application credit</td></tr><tr><td>RUN-165 source review</td><td><strong>manual entry source-only ALREADY_FIXED candidate</strong></td><td class=\"partial\">runtime outcome not selected</td></tr><tr><td>RUN-166 bounded atomicity adjudication</td><td><strong>$atomicity_tests test functions / $atomicity_assertions assertions / $atomicity_races synchronized races</strong></td><td class=\"partial\">manual entry only · supporting $atomicity_supporting_tests/$atomicity_supporting_assertions not aggregated · no app/test change</td></tr><tr><td>RUN-166R exact artifact review</td><td><strong>exact producer/receipt/harness GO · zero discrepancies</strong></td><td class=\"partial\">retirement reporting authorized · live reconciliation belongs to RUN-167</td></tr><tr><td>RUN-167 live reporting</td><td><strong>$finding_count current provisional P1 + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated · 12 retained identities</strong></td><td class=\"partial\">bounded MED-CD-ATOMICITY-01 reclassified · Gate 4 and audit completion false · exact regenerated dashboard requires RUN-168 verification</td></tr>",
    ),
    (
        "The register retains 12 historical claim identities: $finding_count remain current provisional P1 claims, $historical_fixed_count is historical already fixed on current main, and $historical_remediated_count is historical remediated on current main. None is a final finding or closed completion gate.",
        "The register retains 12 historical claim identities: $finding_count remain current provisional P1 claims, $historical_fixed_count are historical already-fixed records on current main, and $historical_remediated_count is historical remediated on current main. None is a final finding or closed completion gate.",
    ),
    (
        "RUN-159 establishes bounded current-source MED-RBAC test execution; RUN-162 separately establishes focused MED-CD-SCOPE remediation execution. Neither establishes full-suite, coverage, application-browser, ease, release, Pass, or completion credit.",
        "RUN-159 establishes bounded MED-RBAC execution; RUN-162 separately establishes focused MED-CD-SCOPE remediation execution; RUN-166 separately establishes bounded manual-entry MED-CD-ATOMICITY execution. The denominators remain distinct, and none establishes full-suite, coverage, application-browser, ease, release, Pass, or completion credit.",
    ),
    (
        "</tr><tr><td>RUN-162 focused MED-CD-SCOPE execution</td><td>$med_cd_tests tests / $med_cd_assertions assertions passed on advanced main; $med_cd_related_tests related controller/command tests passed in the overlapping broader lane; its 2 INR failures reproduced at base and are not attributed to RUN-162</td><td class=\"partial\">bounded MED-CD-SCOPE remediation evidence only · full-suite green false · no coverage credit</td></tr><tr><td>RUN-089 designated-application preflight</td>",
        "</tr><tr><td>RUN-162 focused MED-CD-SCOPE execution</td><td>$med_cd_tests tests / $med_cd_assertions assertions passed on advanced main; $med_cd_related_tests related controller/command tests passed in the overlapping broader lane; its 2 INR failures reproduced at base and are not attributed to RUN-162</td><td class=\"partial\">bounded MED-CD-SCOPE remediation evidence only · full-suite green false · no coverage credit</td></tr><tr><td>RUN-166 bounded manual-entry atomicity execution</td><td>$atomicity_tests claim-specific test functions / $atomicity_assertions assertions / $atomicity_races synchronized two-process races; supporting $atomicity_supporting_tests tests / $atomicity_supporting_assertions assertions overlap prior denominators</td><td class=\"partial\">reported separately from 78/1,529 · no balance-check, destruction, sibling-writer, forced-deadlock, stress, browser, or full-suite credit</td></tr><tr><td>RUN-089 designated-application preflight</td>",
    ),
    ("task-local ignored <span class=\"mono\">vendor/</span> hydrated for RUN-159 and RUN-162 bounded lanes", "task-local ignored <span class=\"mono\">vendor/</span> hydrated for RUN-159, RUN-162, and RUN-166 bounded lanes"),
    ("RUN-155–163 medication-governance provenance, adjudication, and remediation", "RUN-155–167 medication-governance provenance, adjudication, remediation, and reporting"),
    (
        "RUN-158 verifies only the exact RUN-157 dashboard; RUN-161 separately verifies only the exact now-superseded RUN-160 dashboard at 4/4 required viewports with 63/63 visible boundary checks, 10/10 navigation targets, 395/395 local resources, and zero console warnings, console errors, or page errors. Neither receipt transfers to the RUN-163 dashboard or the application.",
        "RUN-158 verifies only the exact RUN-157 dashboard; RUN-161 verifies only the exact RUN-160 dashboard; RUN-164 separately verifies only the exact now-superseded RUN-163 dashboard at 4/4 viewports with 33/33 visible checks, 10/10 navigation targets, 403/403 local resources, and zero overflow, duplicate authored IDs, console warnings/errors, or page errors. None transfers to the RUN-167 dashboard or the application.",
    ),
    (
        "RUN-162 establishes <span class=\"mono\">MED-CD-SCOPE-01</span> reproduction, narrow remediation, focused runtime, integration, and application-commit publication at <span class=\"mono\">$application_short</span> / tree <span class=\"mono\">$application_tree_short</span>; RUN-162R independently authorizes retirement reporting; RUN-163 alone records it as historical remediated. <span class=\"mono\">MED-CD-ATOMICITY-01</span> remains current provisional and inherits no transaction, retry, rollback, lock-order, fractional-value, operation-level concurrency, browser, benchmark, final-finding, Pass, or completion credit. RUN-163 reports 12 retained identities = $finding_count current provisional P1 + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated, with $benchmark_mapped/340 mappings, $final_no_matches/340 final no-match/NCM, $benchmark_unresolved unresolved targets, Gate 4 false, and fresh RUN-164 audit-dashboard verification required.",
        "RUN-162/R establish and authorize <span class=\"mono\">MED-CD-SCOPE-01</span> remediation reporting; RUN-163 records it as historical remediated. RUN-165/166 establish source and bounded runtime for only the manual <span class=\"mono\">POST /emar/controlled/entries</span> register/stock clause of <span class=\"mono\">MED-CD-ATOMICITY-01</span>; RUN-166R independently authorizes retirement reporting; RUN-167 alone records that bounded clause as historical already fixed. Its balance-check, destruction, delivery/adjustment/loss and sibling-writer, forced transient-deadlock retry, and stress/repeated-schedule scope remains unadjudicated. RUN-167 reports 12 retained identities = $finding_count current provisional P1 + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated, with separate $atomicity_tests/$atomicity_assertions/$atomicity_races atomicity evidence, $benchmark_mapped/340 mappings, $final_no_matches/340 final no-match/NCM, $benchmark_unresolved unresolved targets, Gate 4 false, and fresh RUN-168 audit-dashboard verification required.",
    ),
    ("Fresh RUN-164 audit-dashboard verification required", "Fresh RUN-168 audit-dashboard verification required"),
    (
        "The exact RUN-163 reporting dashboard must be checked in RUN-164 at 1440×900, 1280×800, 1024×768, and 390×844.",
        "The exact RUN-167 reporting dashboard must be checked in RUN-168 at 1440×900, 1280×800, 1024×768, and 390×844.",
    ),
    (
        "RUN-162 establishes the MED-CD-SCOPE remediation/runtime/integration/application-commit publication facts, RUN-162R independently authorizes retirement reporting, and RUN-163 alone performs the live-register/reporting reconciliation; none of those supplies audit-dashboard verification for the new HTML.",
        "RUN-165 establishes source-only manual-entry atomicity review, RUN-166 establishes its bounded ALREADY_FIXED source/runtime disposition without application or product-test change, RUN-166R independently authorizes retirement reporting, and RUN-167 alone performs the live-register/reporting reconciliation; none supplies audit-dashboard verification for the new HTML.",
    ),
    ("The linked RUN-164 receipt must record", "The linked RUN-168 receipt must record"),
    (
        "RUN-159's $med_rbac_tests tests / $med_rbac_assertions assertions and ALREADY_FIXED evidence, RUN-159R retirement-reporting authorization, RUN-160's exact MED-RBAC-only reconciliation, RUN-162's $med_cd_tests focused tests / $med_cd_assertions assertions and exact application/tree pins, RUN-162R retirement-reporting authorization, RUN-163's exact MED-CD-SCOPE-only reconciliation, atomicity noninheritance",
        "RUN-159's $med_rbac_tests/$med_rbac_assertions MED-RBAC evidence, RUN-162's $med_cd_tests/$med_cd_assertions MED-CD-SCOPE evidence, RUN-164's exact superseded dashboard verification, RUN-165 source-only atomicity evidence, RUN-166's separately reported $atomicity_tests test functions / $atomicity_assertions assertions / $atomicity_races synchronized races plus nonaggregated $atomicity_supporting_tests/$atomicity_supporting_assertions support, RUN-166R retirement-reporting authorization, RUN-167's exact bounded atomicity reconciliation, and residual compound-scope noninheritance",
    ),
    ("It verifies the RUN-163 audit artifact only", "It verifies the RUN-167 audit artifact only"),
    (
        '<a href="evidence/browser/current-audit-dashboard-verification-run-164-wave-29.json">RUN-164 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)',
        '<a href="evidence/browser/current-audit-dashboard-verification-run-168-wave-30.json">RUN-168 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)',
    ),
    ("RUN-071–163 evidence lineage", "RUN-071–167 evidence lineage"),
    (
        "Every current raw, generated, reviewed, and integrated RUN-077–162/R source/reporting/runtime/benchmark/remediation artifact is linked with its exact SHA-256; RUN-163 is the current reporting generator execution.",
        "Every current raw, generated, reviewed, and integrated RUN-077–166R source/reporting/runtime/benchmark/remediation artifact is linked with its exact SHA-256; RUN-167 is the current reporting generator execution.",
    ),
    (
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, and exact-artifact evidence through RUN-162R, reported in RUN-163, with fresh RUN-164 dashboard verification still required. Exactly two matrix rows have static benchmark-mapping credit. RUN-159 separately establishes the bounded already-fixed MED-RBAC disposition and $med_rbac_tests tests / $med_rbac_assertions assertions; RUN-162 separately establishes the bounded remediated MED-CD-SCOPE disposition and $med_cd_tests focused tests / $med_cd_assertions assertions. The denominators are not one execution, and neither grants any disposition or remediation credit to MED-CD-ATOMICITY-01 or another finding.",
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, and exact-artifact evidence through RUN-166R, reported in RUN-167, with fresh RUN-168 dashboard verification required. Exactly two matrix rows have static benchmark-mapping credit. RUN-159 separately establishes bounded MED-RBAC $med_rbac_tests/$med_rbac_assertions evidence; RUN-162 separately establishes bounded remediated MED-CD-SCOPE $med_cd_tests/$med_cd_assertions evidence; RUN-166 separately establishes bounded manual-entry MED-CD-ATOMICITY $atomicity_tests/$atomicity_assertions/$atomicity_races evidence. The atomicity denominator is not added to the existing 78/1,529 total, and no lane grants inherited balance-check, destruction, sibling-writer, forced-deadlock, stress, browser, benchmark, final-finding, Pass, release, feature-completion, or audit-completion credit.",
    ),
]
for old, new in run_167_template_rewrites:
    assert current_template_text.count(old) == 1, f"Expected one RUN-167 template rewrite target: {old}"
    current_template_text = current_template_text.replace(old, new)

run_171_template_rewrites = [
    ('href="#checkpoint">RUN-167</a>', 'href="#checkpoint">RUN-171</a>'),
    ("RUN-071–167 current reporting checkpoint:", "RUN-071–171 current reporting checkpoint:"),
    ("RUN-071–167 completion-gate checkpoint", "RUN-071–171 completion-gate checkpoint"),
    (
        "RUN-160 reclassifies MED-RBAC-01; RUN-161 verifies that dashboard; RUN-162/R establish and authorize MED-CD-SCOPE remediation reporting; RUN-163 reclassifies MED-CD-SCOPE-01; RUN-164 verifies that dashboard; RUN-165 establishes the manual-entry atomicity source candidate; RUN-166 establishes its bounded ALREADY_FIXED source/runtime disposition; RUN-166R independently authorizes retirement reporting; RUN-167 alone reclassifies the bounded manual-entry MED-CD-ATOMICITY-01 clause and reports the live $finding_count + $historical_fixed_count + $historical_remediated_count split.",
        "RUN-160 reclassifies MED-RBAC-01; RUN-161 verifies that dashboard; RUN-162/R establish and authorize MED-CD-SCOPE remediation reporting; RUN-163 reclassifies MED-CD-SCOPE-01; RUN-164 verifies that dashboard; RUN-165–167 establish and report the bounded manual-entry MED-CD-ATOMICITY-01 disposition; RUN-168 verifies that dashboard; RUN-169/R independently review the Fleet alerts-config route/action owner; RUN-170 integrates exactly one route owner and bridge; RUN-170R independently authorizes reporting; RUN-171 alone reports the live 665-owner ledger while retaining the then-current 9 provisional + 2 historical already-fixed + 1 historical remediated finding split.",
    ),
    (
        "RUN-157 materializes historical reporting; RUN-158 verifies that dashboard; RUN-159 establishes and RUN-159R authorizes MED-RBAC already-fixed reporting; RUN-160 reconciles that live status; RUN-161 verifies that dashboard; RUN-162 establishes and RUN-162R authorizes MED-CD-SCOPE remediation reporting; RUN-163 reconciles that live status; RUN-164 verifies that dashboard; RUN-165 establishes source-only manual-entry atomicity review; RUN-166 establishes its bounded ALREADY_FIXED runtime disposition without remediation; RUN-166R independently authorizes retirement reporting; RUN-167 alone reconciles the bounded atomicity live status without changing Fleet ownership, direct-exact review-queue, benchmark, final-finding, or completion counts.",
        "RUN-157 materializes historical reporting; RUN-158 verifies that dashboard; RUN-159–167 establish and report the three bounded medication dispositions; RUN-168 verifies only the exact RUN-167 dashboard; RUN-169/R independently review queue index 83 as one Fleet alerts-config route/action owner after one corrected architecture locus; RUN-170 integrates exactly one route owner and bridge; RUN-170R returns three sealed GO reviews with zero discrepancies; RUN-171 alone advances Fleet ownership and queue reporting without changing findings, benchmark, correctness, runtime, application-browser, final-finding, or completion state.",
    ),
    (
        "RUN-160 alone changes MED-RBAC in the live queue; RUN-161 verifies its dashboard; RUN-162/R establish and authorize MED-CD-SCOPE remediation reporting; RUN-163 changes that live status; RUN-164 verifies its dashboard; RUN-165/166 establish source and bounded runtime for the manual-entry atomicity ALREADY_FIXED disposition; RUN-166R authorizes reporting; RUN-167 alone changes the bounded atomicity live status, leaving $finding_count provisional plus $historical_fixed_count historical already-fixed plus $historical_remediated_count historical remediated while every Fleet ownership, direct-exact review-queue, benchmark, final-finding, and completion count remains unchanged.",
        "RUN-160 alone changes MED-RBAC in the live queue; RUN-163 and RUN-167 separately report the two later bounded medication dispositions. RUN-168 verifies the exact RUN-167 dashboard. RUN-169/R establish one Fleet alerts-config route/action owner candidate, RUN-170 integrates one route owner and bridge, RUN-170R authorizes reporting only, and RUN-171 alone advances the live ledger to 665 owners, 308 routes, 357 pages, 96 bridges, 119 reviewed, and 388 pending while findings remain at the RUN-171 checkpoint of 9 provisional plus 2 historical already-fixed plus 1 historical remediated; benchmark, final-finding, Gate 4, and completion state remain unchanged.",
    ),
    ("RUN-001 through RUN-167 are represented by audit artifacts.", "RUN-001 through RUN-171 are represented by audit artifacts."),
    (
        "<li>RUN-167: live register reconciled bounded MED-CD-ATOMICITY-01 from current provisional to retained historical already-fixed · $finding_count current provisional P1 + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated · fresh RUN-168 dashboard verification required</li>",
        "<li>RUN-167: live register reconciled bounded MED-CD-ATOMICITY-01 from current provisional to retained historical already-fixed · 9 current provisional P1 + 2 historical already-fixed + 1 historical remediated</li><li>RUN-168: exact RUN-167 dashboard verified at 4/4 viewports · 39/39 visible checks · 10/10 navigation · 414/414 local resources · zero application credit</li><li>RUN-169/R: queue index 83 Fleet alerts-config candidate independently reviewed OWNER after one corrected architecture locus · three provisional-not-final observations · zero current or downstream credit</li><li>RUN-170: exactly one route owner and one action bridge integrated · zero page or new FEATURE-ID</li><li>RUN-170R: three sealed post-commit GO reviews · zero discrepancies · reporting authorization only</li><li>RUN-171: live static ledger reported as 665 owners / 308 routes / 357 pages / 96 bridges · 119 reviewed / 388 pending / 410 without ownership · finding checkpoint 9 provisional + 2 already-fixed + 1 remediated · fresh RUN-172 dashboard verification required</li>",
    ),
    (
        "RUN-151, RUN-155, RUN-158, RUN-161, and RUN-164 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-167 dashboard.",
        "RUN-151, RUN-155, RUN-158, RUN-161, RUN-164, and RUN-168 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-171 dashboard.",
    ),
    (
        "<li><a href=\"evidence/browser/current-audit-dashboard-verification-run-158-wave-27.json\">Superseded RUN-158 verification GO</a></li><li><a href=\"evidence/browser/current-audit-dashboard-verification-run-161-wave-28.json\">Superseded RUN-161 verification GO</a></li><li><a href=\"evidence/browser/current-audit-dashboard-verification-run-164-wave-29.json\">Superseded RUN-164 verification GO</a></li></ul>",
        "<li><a href=\"evidence/browser/current-audit-dashboard-verification-run-158-wave-27.json\">Superseded RUN-158 verification GO</a></li><li><a href=\"evidence/browser/current-audit-dashboard-verification-run-161-wave-28.json\">Superseded RUN-161 verification GO</a></li><li><a href=\"evidence/browser/current-audit-dashboard-verification-run-164-wave-29.json\">Superseded RUN-164 verification GO</a></li><li><a href=\"evidence/browser/current-audit-dashboard-verification-run-168-wave-30.json\">Superseded RUN-168 verification GO</a></li></ul>",
    ),
    ("RUN-164–167 current atomicity adjudication and reporting checkpoint", "RUN-168–171 current Fleet alerts-config ownership and reporting checkpoint"),
    (
        "<tr><td>RUN-167 live reporting</td><td><strong>$finding_count current provisional P1 + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated · 12 retained identities</strong></td><td class=\"partial\">bounded MED-CD-ATOMICITY-01 reclassified · Gate 4 and audit completion false · exact regenerated dashboard requires RUN-168 verification</td></tr>",
        "<tr><td>RUN-167 live reporting</td><td><strong>9 current provisional P1 + 2 historical already-fixed + 1 historical remediated · 12 retained identities</strong></td><td class=\"partial\">bounded MED-CD-ATOMICITY-01 reclassified; exact dashboard later verified by RUN-168</td></tr><tr><td>RUN-168 exact dashboard verification</td><td><strong>4/4 viewports · 39/39 visible checks · 10/10 navigation · 414/414 local resources</strong></td><td class=\"partial\">exact superseded RUN-167 audit artifact only · zero application credit</td></tr><tr><td>RUN-169/R Fleet alerts-config review</td><td><strong>index 83 · RUN090-ROUTE-0084 / RUN077-ROUTE-0692 · OWNER after corrected 1–21 locus</strong></td><td class=\"partial\">one route/action candidate · three provisional-not-final observations · no current or downstream credit</td></tr><tr><td>RUN-170/R Fleet alerts-config overlay</td><td><strong>665 owners · 308 routes + 357 pages · 96 bridges</strong></td><td class=\"partial\">one route + one bridge · zero page/new-feature/correctness credit · three sealed GO reviews</td></tr><tr><td>RUN-171 live reporting</td><td><strong>119 reviewed / 388 pending · 97 owned / 410 without ownership · 9 provisional + 2 already-fixed + 1 remediated</strong></td><td class=\"partial\">index 83 integrated · next index 84 RUN090-ROUTE-0085 / RUN077-ROUTE-0693 · fleet-assets.trips.index · Gate 4 false · fresh RUN-172 required</td></tr>",
    ),
    (
        "RUN-152R–154 Fleet vehicle-register ownership and provisional source observations",
        "RUN-169R–171 Fleet vehicle alerts-config ownership and provisional source observations",
    ),
    (
        "<span class=\"mono\">fleet-assets.vehicles.index</span> / <span class=\"mono\">RUN077-ROUTE-0690</span> / <span class=\"mono\">VehicleController::index</span> is one bounded static route/action owner for <span class=\"mono\">CAP-FLEET-VEHICLE-REGISTER</span>. RUN-153 adds one route row and one bridge, zero page rows, and no new FEATURE-ID. Existing page-owner and historical-sentinel context are not recredited; index 82 is context only and index 83 remains unresolved.",
        "<span class=\"mono\">fleet-assets.vehicles.alerts-config</span> / <span class=\"mono\">RUN077-ROUTE-0692</span> / <span class=\"mono\">VehicleController::alertsConfig</span> is one bounded static route/action owner for <span class=\"mono\">CAP-FLEET-VEHICLE-REGISTER</span>. RUN-170 adds one route row and one bridge, zero page rows, and no new FEATURE-ID. Consumer, caller, service, model, page-owner, neighbor, correctness, and downstream context are not inherited; index 83 is integrated and index 84 remains unresolved.",
    ),
    (
        "Both reviewers completed independent evidence traces and neither is represented as blinded. Reviewer A had prior team-status visibility; reviewer B had prior self-assessment visibility; neither consulted the other. The six observations below authorize no correctness or final-finding credit and remain separate from the 12 retained claim identities ($finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated).",
        "The initial exact-artifact review withheld integration for one out-of-range architecture locus; the corrected 1–21 locus received GO. RUN-170R then records three sealed post-commit GO reviews with zero discrepancies. The three observations below authorize no correctness or final-finding credit and remain separate from the historical RUN-171 checkpoint of 12 retained claim identities (9 current provisional + 2 historical already-fixed + 1 historical remediated).",
    ),
    ("Fresh RUN-168 audit-dashboard verification required", "Fresh RUN-172 audit-dashboard verification required"),
    (
        "The exact RUN-167 reporting dashboard must be checked in RUN-168 at 1440×900, 1280×800, 1024×768, and 390×844.",
        "The exact RUN-171 reporting dashboard must be checked in RUN-172 at 1440×900, 1280×800, 1024×768, and 390×844.",
    ),
    (
        "RUN-165 establishes source-only manual-entry atomicity review, RUN-166 establishes its bounded ALREADY_FIXED source/runtime disposition without application or product-test change, RUN-166R independently authorizes retirement reporting, and RUN-167 alone performs the live-register/reporting reconciliation; none supplies audit-dashboard verification for the new HTML.",
        "RUN-168 verifies only the superseded RUN-167 HTML; RUN-169/R establish one Fleet alerts-config route/action candidate, RUN-170 integrates the bounded owner/bridge, RUN-170R independently authorizes reporting, and RUN-171 alone performs live ownership/queue reporting; none supplies audit-dashboard verification for the new HTML.",
    ),
    ("The linked RUN-168 receipt must record", "The linked RUN-172 receipt must record"),
    ("It verifies the RUN-167 audit artifact only", "It verifies the RUN-171 audit artifact only"),
    (
        '<a href="evidence/browser/current-audit-dashboard-verification-run-168-wave-30.json">RUN-168 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)',
        '<a href="evidence/browser/current-audit-dashboard-verification-run-172-wave-31.json">RUN-172 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)',
    ),
    ("RUN-071–167 evidence lineage", "RUN-071–171 evidence lineage"),
    (
        "Every current raw, generated, reviewed, and integrated RUN-077–166R source/reporting/runtime/benchmark/remediation artifact is linked with its exact SHA-256; RUN-167 is the current reporting generator execution.",
        "Every current raw, generated, reviewed, and integrated RUN-077–170R source/reporting/runtime/benchmark/remediation/ownership artifact is linked with its exact SHA-256; RUN-171 is the current reporting generator execution.",
    ),
    (
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, and exact-artifact evidence through RUN-166R, reported in RUN-167, with fresh RUN-168 dashboard verification required. Exactly two matrix rows have static benchmark-mapping credit. RUN-159 separately establishes bounded MED-RBAC $med_rbac_tests/$med_rbac_assertions evidence; RUN-162 separately establishes bounded remediated MED-CD-SCOPE $med_cd_tests/$med_cd_assertions evidence; RUN-166 separately establishes bounded manual-entry MED-CD-ATOMICITY $atomicity_tests/$atomicity_assertions/$atomicity_races evidence. The atomicity denominator is not added to the existing 78/1,529 total, and no lane grants inherited balance-check, destruction, sibling-writer, forced-deadlock, stress, browser, benchmark, final-finding, Pass, release, feature-completion, or audit-completion credit.",
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, exact-artifact, and bounded Fleet ownership evidence through RUN-170R, reported in RUN-171, with fresh RUN-172 dashboard verification required. Exactly two matrix rows have static benchmark-mapping credit. RUN-159, RUN-162, and RUN-166 retain their separate medication evidence boundaries; RUN-169/R and RUN-170/R add only one Fleet alerts-config static route owner and bridge. No lane grants inherited page, consumer, caller, service, model, correctness, selected-GET execution, browser, benchmark-final, final-finding, Pass, release, feature-completion, Gate 4, or audit-completion credit.",
    ),
]
for old, new in run_171_template_rewrites:
    assert current_template_text.count(old) == 1, f"Expected one RUN-171 template rewrite target: {old}"
    current_template_text = current_template_text.replace(old, new)

run_172_semantic_attribution_rewrites = [
    (
        "RUN-141/R review finance.api.sites.overview as one explicit JSON route/action owner; RUN-153/R establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges. Exactly one fleet-assets.vehicles.index route owner and one bridge are added; existing page-owner and sentinel context are not inherited or recredited, index 82 is context only, and index 83 remains unresolved. Route/page/overlap sets are $route_feature_ids/$page_feature_ids/$route_page_overlap; six provisional source observations remain separate from the 12 retained claim records ($finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated) and retain zero correctness or final-finding credit. $static_residual records remain and Gate 4 is open.",
        "RUN-141/R review finance.api.sites.overview as one explicit JSON route/action owner; RUN-153/R establish the historical 664 bounded source-owner records (307 routes + 357 pages) across 256 FEATURE-IDs plus 95 action bridges. Exactly one fleet-assets.vehicles.index route owner and one bridge were added; existing page-owner and sentinel context were not inherited or recredited, index 82 was context only, and index 83 remained unresolved. Six RUN-152R observations remained separate from the 12 then-provisional claim identities and retained zero correctness or final-finding credit. RUN-170/R later establish the historical 665 bounded source-owner records (308 routes + 357 pages) across 256 FEATURE-IDs plus 96 action bridges through exactly one fleet-assets.vehicles.alerts-config route owner and one bridge; index 83 was integrated and index 84 fleet-assets.trips.index was next. Three RUN-169R observations remained provisional-not-final and separate from the 12 retained claim records. At RUN-174 those records split into 8 current provisional + 2 historical already-fixed + 2 historical remediated. RUN-190/R later establish the current $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges through exactly one fleet-assets.trips.playback / FleetTripController::show route owner and bridge; index 84 is not recredited, index 85 fleet-assets.trips.playback is integrated, and index 86 fleet-assets.trips.playback.data is next. The current RUN-191 register retains $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated. Current route/page/overlap sets are $route_feature_ids/$page_feature_ids/$route_page_overlap; $static_residual records remain and Gate 4 is open.",
    ),
    (
        "RUN-141/R–142/R add one finance.api.sites.overview JSON route owner and one bridge, inherit or recredit no page, sibling, caller, reviewed-neighbor, or next-row ownership, and add zero union or matrix credit; $queue_reviewed queue rows are reviewed, $queue_pending remain pending, and $queue_without_owner remain without ownership.",
        "RUN-141/R–142/R add one finance.api.sites.overview JSON route owner and one bridge, inherit or recredit no page, sibling, caller, reviewed-neighbor, or next-row ownership, and add zero union or matrix credit; at that historical checkpoint 116 queue rows were reviewed, 391 remained pending, and 413 remained without ownership. RUN-170/R historical queue accounting is 119 reviewed, 388 pending, and 410 without ownership. RUN-190/R current queue accounting is $queue_reviewed reviewed, $queue_pending pending, and $queue_without_owner without ownership.",
    ),
    (
        "RUN-153/R add one route owner and one bridge, preserve six provisional-not-final observations separately from 12 retained claim identities ($finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated), preserve page/sentinel/neighbor noninheritance, and keep every correctness boundary and Gate 4 false. RUN-154 refreshes the Fleet reporting;",
        "RUN-153/R add one route owner and one bridge, preserve six provisional-not-final observations separately from the 12 then-provisional claim identities, preserve page/sentinel/neighbor noninheritance, and keep every correctness boundary and Gate 4 false. At the RUN-167 checkpoint those 12 identities split 9 current provisional + 2 historical already-fixed + 1 historical remediated. RUN-154 refreshes the Fleet reporting;",
    ),
    (
        "RUN-152/R–153/R add one independently reviewed fleet-assets.vehicles.index route owner and one bridge, preserve six provisional-not-final observations separately from 12 retained claim identities ($finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated), preserve page/sentinel/neighbor noninheritance, and keep all correctness boundaries and Gate 4 false;",
        "RUN-152/R–153/R add one independently reviewed fleet-assets.vehicles.index route owner and one bridge, preserve six provisional-not-final observations separately from the 12 then-provisional claim identities, preserve page/sentinel/neighbor noninheritance, and keep all correctness boundaries and Gate 4 false; at the RUN-167 checkpoint those identities split 9 current provisional + 2 historical already-fixed + 1 historical remediated;",
    ),
    ("RUN-153/R current Fleet vehicle-register overlay", "RUN-153/R historical Fleet vehicle-register overlay"),
    ("RUN-157 current reporting refresh", "RUN-157 historical reporting refresh"),
    ("fresh RUN-158 dashboard verification required", "subsequently verified by RUN-158"),
    ("<tr><td>RUN-090 direct-exact queue</td>", "<tr><td>RUN-090 frozen denominator / RUN-170R current accounting</td>"),
    (
        "<li>RUN-142/R: one route row and one bridge integrated and independently verified · zero page/sibling/caller/neighbor/next-row inheritance · $static_owner_records cumulative owner records</li>",
        "<li>RUN-142/R: one route row and one bridge integrated and independently verified · zero page/sibling/caller/neighbor/next-row inheritance · 662 historical cumulative owner records</li>",
    ),
    (
        "<tr><td>RUN-153/R current Fleet vehicle-register index route/action ownership</td><td>$static_owner_records = $static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs = $static_owner_h_features H + $static_owner_d_features D · $static_action_bridges action bridges</td><td class=\"partial\">$ownership_percent% of bounded 3,929 · $static_residual residual · features $route_feature_ids route / $page_feature_ids page / $route_page_overlap overlap · routes 3,218 = $static_owner_routes owner + $route_shared_current shared + $route_alias_current alias + $route_residual residual with $finance_site_route_gap tagged gaps · pages 711 = $static_owner_pages owner + $page_shared shared + $page_residual residual with $page_gap tagged gap · Fleet vehicle-register wave 1 reviewed = 1 owner · 1 route row + 1 bridge · 0 page rows · page owner and historical sentinel not recredited · index 82 context only · index 83 unresolved · 6 provisional source observations separate from 12 retained claim identities ($finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated) · both reviewers non-blinded with disclosed prior visibility · neither consulted the other · zero correctness and final-finding credit · Gate 4 incomplete · ownership/linkage fields unchanged by RUN-145 benchmark-only mapping</td></tr>",
        "<tr><td>RUN-170/R current Fleet alerts-config route/action ownership</td><td>$static_owner_records = $static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs = $static_owner_h_features H + $static_owner_d_features D · $static_action_bridges action bridges</td><td class=\"partial\">$ownership_percent% of bounded 3,929 · $static_residual residual · features $route_feature_ids route / $page_feature_ids page / $route_page_overlap overlap · routes 3,218 = $static_owner_routes owner + $route_shared_current shared + $route_alias_current alias + $route_residual residual with $finance_site_route_gap tagged gaps · pages 711 = $static_owner_pages owner + $page_shared shared + $page_residual residual with $page_gap tagged gap · Fleet alerts-config wave 31 reviewed = 1 owner · 1 route row + 1 bridge · 0 page rows · consumer, caller, service, model, page-owner, neighbor, correctness, and downstream context not inherited · index 83 integrated · index 84 fleet-assets.trips.index unresolved · 3 provisional source observations separate from the current RUN-174 split of 12 retained claim identities ($finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated) · corrected 1–21 architecture locus independently reviewed · three sealed post-commit GO reviews · zero correctness and final-finding credit · Gate 4 incomplete · ownership/linkage fields unchanged by RUN-145 benchmark-only mapping</td></tr>",
    ),
    (
        "<li>RUN-153/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges while adding one fleet-assets.vehicles.index owner and one bridge, preserving six provisional-not-final observations separately from the 12 retained claim identities ($finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated), inheriting or recrediting no page-owner, historical-sentinel, neighbor, or index-82 context and leaving index 83 unresolved, and adding zero feature-union, matrix, correctness, or final-finding credit; complete the framework-expanded canonical route/page denominator, $static_residual non-owner records including $route_shared_current shared routes, $route_alias_current alias routes, and $route_residual residual routes plus $page_shared shared pages and $page_gap tagged gap within $page_residual residual pages, the full crosswalk, and route reachability before Gate 4 can close</li>",
        "<li>RUN-169/R–170/R establish the current $static_owner_records bounded source-owner records and $static_action_bridges action bridges while adding one fleet-assets.vehicles.alerts-config owner and one bridge, preserving three provisional-not-final observations separately from the current RUN-174 split of 12 retained claim identities ($finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated), inheriting or recrediting no consumer, caller, service, model, page-owner, neighbor, correctness, or downstream context, integrating index 83 and leaving index 84 fleet-assets.trips.index unresolved, and adding zero feature-union, matrix, correctness, or final-finding credit; complete the framework-expanded canonical route/page denominator, $static_residual non-owner records including $route_shared_current shared routes, $route_alias_current alias routes, and $route_residual residual routes plus $page_shared shared pages and $page_gap tagged gap within $page_residual residual pages, the full crosswalk, and route reachability before Gate 4 can close</li>",
    ),
    (
        "RUN-167 reports 12 retained identities = $finding_count current provisional P1 + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated, with separate $atomicity_tests/$atomicity_assertions/$atomicity_races atomicity evidence, $benchmark_mapped/340 mappings, $final_no_matches/340 final no-match/NCM, $benchmark_unresolved unresolved targets, Gate 4 false, and fresh RUN-168 audit-dashboard verification required.",
        "RUN-167 reports 12 retained identities = 9 current provisional P1 + 2 historical already-fixed + 1 historical remediated, with separate $atomicity_tests/$atomicity_assertions/$atomicity_races atomicity evidence, $benchmark_mapped/340 mappings, $final_no_matches/340 final no-match/NCM, $benchmark_unresolved unresolved targets, Gate 4 false, and RUN-168 subsequently verified the exact RUN-167 dashboard.",
    ),
    (
        "visible 664/307/357 ownership, 95 bridges, 118/389 queue accounting",
        "visible 665/308/357 ownership, 96 bridges, 119/388 queue accounting, 97 owned/410 without ownership",
    ),
]
for old, new in run_172_semantic_attribution_rewrites:
    assert current_template_text.count(old) == 1, f"Expected one RUN-172 semantic attribution target: {old}"
    current_template_text = current_template_text.replace(old, new)

run_174_template_rewrites = [
    (
        "the MED-RBAC already-fixed adjudication is pinned to <span class=\"mono\">$med_rbac_application_short</span>, the bounded MED-CD-SCOPE remediation to application <span class=\"mono\">$application_short</span> and tree <span class=\"mono\">$application_tree_short</span>, and the bounded manual-entry MED-CD-ATOMICITY source adjudication to <span class=\"mono\">$atomicity_application_short</span>",
        "the MED-RBAC already-fixed adjudication remains pinned to <span class=\"mono\">$med_rbac_application_short</span>, the bounded MED-CD-SCOPE remediation to application <span class=\"mono\">$application_short</span> and tree <span class=\"mono\">$application_tree_short</span>, and the bounded manual-entry MED-CD-ATOMICITY source adjudication to <span class=\"mono\">$atomicity_application_short</span>, while SAFE concern-identity remediation is integrated only on local main <span class=\"mono\">$safe_merge_short</span> and remains unpublished from origin/main <span class=\"mono\">$safe_origin_short</span>",
    ),
    ('href="#checkpoint">RUN-171</a>', 'href="#checkpoint">RUN-174</a>'),
    ("RUN-071–171 current reporting checkpoint:", "RUN-071–174 current reporting checkpoint:"),
    ("RUN-071–171 completion-gate checkpoint", "RUN-071–174 completion-gate checkpoint"),
    (
        "RUN-160 reclassifies MED-RBAC-01; RUN-161 verifies that dashboard; RUN-162/R establish and authorize MED-CD-SCOPE remediation reporting; RUN-163 reclassifies MED-CD-SCOPE-01; RUN-164 verifies that dashboard; RUN-165–167 establish and report the bounded manual-entry MED-CD-ATOMICITY-01 disposition; RUN-168 verifies that dashboard; RUN-169/R independently review the Fleet alerts-config route/action owner; RUN-170 integrates exactly one route owner and bridge; RUN-170R independently authorizes reporting; RUN-171 alone reports the live 665-owner ledger while retaining the then-current 9 provisional + 2 historical already-fixed + 1 historical remediated finding split.",
        "RUN-160–171 preserve the independently reviewed medication and Fleet checkpoints, ending at the RUN-171 split of 9 provisional + 2 historical already-fixed + 1 historical remediated; RUN-172 verifies that exact dashboard. RUN-173 records the bounded SAFE-ALERT-DEDUP-IDENTITY-01 reproduction, remediation, local-main integration, delegated execution, and nonpublication; RUN-173R independently returns GO and authorizes retirement reporting; RUN-174 alone changes the live register to $finding_count provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated while leaving Fleet, benchmark, final-finding, Gate 4, and completion state unchanged.",
    ),
    (
        "RUN-160 alone changes MED-RBAC in the live queue; RUN-163 and RUN-167 separately report the two later bounded medication dispositions. RUN-168 verifies the exact RUN-167 dashboard. RUN-169/R establish one Fleet alerts-config route/action owner candidate, RUN-170 integrates one route owner and bridge, RUN-170R authorizes reporting only, and RUN-171 alone advances the live ledger to 665 owners, 308 routes, 357 pages, 96 bridges, 119 reviewed, and 388 pending while findings remain at the RUN-171 checkpoint of 9 provisional plus 2 historical already-fixed plus 1 historical remediated; benchmark, final-finding, Gate 4, and completion state remain unchanged.",
        "RUN-160, RUN-163, and RUN-167 separately report the three bounded medication dispositions; RUN-168 verifies that dashboard. RUN-169/R–171 establish and report one Fleet alerts-config route/action owner, producing 665 owners, 308 routes, 357 pages, 96 bridges, 119 reviewed, and 388 pending at the historical RUN-171 finding checkpoint of 9 provisional + 2 already-fixed + 1 remediated; RUN-172 verifies that exact dashboard. RUN-173/R establish and independently approve only the bounded SAFE remediation and local integration; RUN-174 changes the current finding split to $finding_count provisional + $historical_fixed_count already-fixed + $historical_remediated_count remediated. Benchmark, final-finding, Gate 4, and completion state remain unchanged.",
    ),
    (
        "RUN-157 materializes historical reporting; RUN-158 verifies that dashboard; RUN-159–167 establish and report the three bounded medication dispositions; RUN-168 verifies only the exact RUN-167 dashboard; RUN-169/R independently review queue index 83 as one Fleet alerts-config route/action owner after one corrected architecture locus; RUN-170 integrates exactly one route owner and bridge; RUN-170R returns three sealed GO reviews with zero discrepancies; RUN-171 alone advances Fleet ownership and queue reporting without changing findings, benchmark, correctness, runtime, application-browser, final-finding, or completion state.",
        "RUN-157–167 establish and report the three bounded medication dispositions; RUN-168 verifies only the exact RUN-167 dashboard. RUN-169/R–171 independently review, integrate, and report queue index 83 as one Fleet alerts-config route/action owner without changing finding or completion state; RUN-172 verifies that exact dashboard. RUN-173 establishes the bounded SAFE concern-identity reproduction, narrow two-path remediation, post-merge execution, local-main integration, and nonpublication; RUN-173R returns independent GO and authorizes retirement reporting only; RUN-174 alone reconciles the live register to $finding_count provisional + $historical_fixed_count already-fixed + $historical_remediated_count remediated and $unique_bounded_tests/$unique_bounded_assertions uniquely counted tests/assertions while preserving the RUN-172-verified HTML for RUN-175 regeneration. Fleet, benchmark, final-finding, Gate 4, release, and completion state remain unchanged.",
    ),
    (
        "RUN-145 grants exactly two target-specific static benchmark-mapping credits. Apart from separately bounded RUN-159 MED-RBAC, RUN-162 MED-CD-SCOPE, and RUN-166 manual-entry MED-CD-ATOMICITY executions, no represented wave grants broader or full-suite application runtime or coverage; no represented wave grants signed-in application-browser, ease, release, Pass, final-finding, feature-completion, or audit-completion credit.",
        "RUN-145 grants exactly two target-specific static benchmark-mapping credits. Apart from separately bounded RUN-159 MED-RBAC, RUN-162 MED-CD-SCOPE, RUN-166 manual-entry MED-CD-ATOMICITY, and RUN-173 post-merge SAFE focused executions, no represented wave grants broader or full-suite application runtime or coverage; only MED-RBAC, MED-CD-SCOPE, and post-merge SAFE contribute once to the current $unique_bounded_tests/$unique_bounded_assertions total. No represented wave grants signed-in application-browser, ease, release, Pass, publication, final-finding, feature-completion, or audit-completion credit.",
    ),
    ("RUN-001 through RUN-171 are represented by audit artifacts.", "RUN-001 through RUN-174 are represented by audit artifacts."),
    (
        "<li>RUN-171: live static ledger reported as 665 owners / 308 routes / 357 pages / 96 bridges · 119 reviewed / 388 pending / 410 without ownership · finding checkpoint 9 provisional + 2 already-fixed + 1 remediated · fresh RUN-172 dashboard verification required</li>",
        "<li>RUN-171: live static ledger reported as 665 owners / 308 routes / 357 pages / 96 bridges · 119 reviewed / 388 pending / 410 without ownership · finding checkpoint 9 provisional + 2 already-fixed + 1 remediated</li><li>RUN-172: exact RUN-171 dashboard verified at 4/4 viewports · 55/55 visible checks · 10/10 navigation · 426/426 local resources · zero application credit</li><li>RUN-173: SAFE concern-identity defect reproduced and remediated in exactly two transferred paths · post-merge $safe_tests/$safe_assertions uniquely counted · local main only · not published</li><li>RUN-173R: exact remediation artifacts independently reviewed GO · retirement reporting authorization only</li><li>RUN-174: SAFE record reclassified in place · $finding_count provisional + $historical_fixed_count already-fixed + $historical_remediated_count remediated · $unique_bounded_tests/$unique_bounded_assertions unique bounded disposition total · dashboard HTML frozen pending RUN-175</li>",
    ),
    (
        "RUN-151, RUN-155, RUN-158, RUN-161, RUN-164, and RUN-168 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-171 dashboard.",
        "RUN-151, RUN-155, RUN-158, RUN-161, RUN-164, RUN-168, and RUN-172 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-174 reporting sources or the RUN-175 dashboard that will be generated from them.",
    ),
    (
        "<li><a href=\"evidence/browser/current-audit-dashboard-verification-run-158-wave-27.json\">Superseded RUN-158 verification GO</a></li><li><a href=\"evidence/browser/current-audit-dashboard-verification-run-161-wave-28.json\">Superseded RUN-161 verification GO</a></li><li><a href=\"evidence/browser/current-audit-dashboard-verification-run-164-wave-29.json\">Superseded RUN-164 verification GO</a></li><li><a href=\"evidence/browser/current-audit-dashboard-verification-run-168-wave-30.json\">Superseded RUN-168 verification GO</a></li></ul>",
        "<li><a href=\"evidence/browser/current-audit-dashboard-verification-run-158-wave-27.json\">Superseded RUN-158 verification GO</a></li><li><a href=\"evidence/browser/current-audit-dashboard-verification-run-161-wave-28.json\">Superseded RUN-161 verification GO</a></li><li><a href=\"evidence/browser/current-audit-dashboard-verification-run-164-wave-29.json\">Superseded RUN-164 verification GO</a></li><li><a href=\"evidence/browser/current-audit-dashboard-verification-run-168-wave-30.json\">Superseded RUN-168 verification GO</a></li><li><a href=\"evidence/browser/current-audit-dashboard-verification-run-172-wave-31.json\">Superseded RUN-172 verification GO</a></li></ul>",
    ),
    ("RUN-168–171 current Fleet alerts-config ownership and reporting checkpoint", "RUN-172–174 SAFE alert dedup remediation and reporting checkpoint"),
    (
        "<tr><td>RUN-171 live reporting</td><td><strong>119 reviewed / 388 pending · 97 owned / 410 without ownership · 9 provisional + 2 already-fixed + 1 remediated</strong></td><td class=\"partial\">index 83 integrated · next index 84 RUN090-ROUTE-0085 / RUN077-ROUTE-0693 · fleet-assets.trips.index · Gate 4 false · fresh RUN-172 required</td></tr>",
        "<tr><td>RUN-171 live reporting</td><td><strong>119 reviewed / 388 pending · 97 owned / 410 without ownership · 9 provisional + 2 already-fixed + 1 remediated</strong></td><td class=\"partial\">index 83 integrated · next index 84 RUN090-ROUTE-0085 / RUN077-ROUTE-0693 · exact dashboard later verified by RUN-172</td></tr><tr><td>RUN-172 exact dashboard verification</td><td><strong>4/4 viewports · 55/55 visible checks · 10/10 navigation · 426/426 local resources</strong></td><td class=\"partial\">exact superseded RUN-171 audit artifact only · zero application credit</td></tr><tr><td>RUN-173/R SAFE remediation and review</td><td><strong>red $safe_red_failed failed + $safe_red_warning_pass warning-pass / $safe_red_assertions assertions · post-merge $safe_tests passed / $safe_assertions assertions · exact two-path merge $safe_merge_short</strong></td><td class=\"partial\">bounded concern identity and observer custody only · independent GO · local main not published</td></tr><tr><td>RUN-174 live reporting</td><td><strong>$finding_count current provisional P1 + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated · $unique_bounded_tests tests / $unique_bounded_assertions assertions</strong></td><td class=\"partial\">SAFE reclassified in place · zero final finding · dashboard HTML unchanged · fresh RUN-175 required</td></tr>",
    ),
    (
        "<li><span class=\"partial\">$med_rbac_tests</span> bounded MED-RBAC tests / $med_rbac_assertions assertions</li><li><span class=\"partial\">$med_cd_tests</span> focused MED-CD-SCOPE tests / $med_cd_assertions assertions</li><li><span class=\"partial\">$atomicity_tests</span> MED-CD-ATOMICITY test functions / $atomicity_assertions assertions / $atomicity_races synchronized races; separately reported, not added to 78/1,529 · supporting $atomicity_supporting_tests/$atomicity_supporting_assertions overlaps · no full-suite or coverage credit</li>",
        "<li><span class=\"partial\">$med_rbac_tests</span> bounded MED-RBAC tests / $med_rbac_assertions assertions</li><li><span class=\"partial\">$med_cd_tests</span> focused MED-CD-SCOPE tests / $med_cd_assertions assertions</li><li><span class=\"partial\">$safe_tests</span> post-merge SAFE alert-dedup tests / $safe_assertions assertions; counted once in the current $unique_bounded_tests/$unique_bounded_assertions unique bounded total</li><li><span class=\"partial\">$atomicity_tests</span> MED-CD-ATOMICITY test functions / $atomicity_assertions assertions / $atomicity_races synchronized races; separately reported, excluded from both the historical 78/1,529 and current $unique_bounded_tests/$unique_bounded_assertions totals · supporting $atomicity_supporting_tests/$atomicity_supporting_assertions overlaps · no full-suite or coverage credit</li>",
    ),
    (
        "<div class=\"card\"><strong class=\"partial\">$finding_count</strong><span>current provisional P1 claims</span><small>$historical_fixed_count historical already-fixed · $historical_remediated_count historical remediated · none final</small></div><div class=\"card\"><strong>$atomicity_tests</strong><span>MED-CD-ATOMICITY test functions</span><small>$atomicity_assertions assertions · $atomicity_races synchronized races · separate from existing 78/1,529</small></div>",
        "<div class=\"card\"><strong class=\"partial\">$finding_count</strong><span>current provisional P1 claims</span><small>$historical_fixed_count historical already-fixed · $historical_remediated_count historical remediated · none final</small></div><div class=\"card\"><strong>$unique_bounded_tests / $unique_bounded_assertions</strong><span>unique bounded disposition evidence</span><small>$med_rbac_tests/$med_rbac_assertions MED-RBAC + $med_cd_tests/$med_cd_assertions MED-CD-SCOPE + $safe_tests/$safe_assertions SAFE; atomicity and support remain separate</small></div>",
    ),
    (
        "The register retains 12 historical claim identities: $finding_count remain current provisional P1 claims, $historical_fixed_count are historical already-fixed records on current main, and $historical_remediated_count is historical remediated on current main. None is a final finding or closed completion gate.",
        "The register retains 12 historical claim identities: $finding_count remain current provisional P1 claims, $historical_fixed_count are historical already-fixed records on current main, and $historical_remediated_count are historical remediated records. SAFE-ALERT-DEDUP-IDENTITY-01 is remediated on local main only and is not published to origin/main. None is a final finding or closed completion gate.",
    ),
    (
        "RUN-159 establishes bounded MED-RBAC execution; RUN-162 separately establishes focused MED-CD-SCOPE remediation execution; RUN-166 separately establishes bounded manual-entry MED-CD-ATOMICITY execution. The denominators remain distinct, and none establishes full-suite, coverage, application-browser, ease, release, Pass, or completion credit.",
        "RUN-159 establishes bounded MED-RBAC execution; RUN-162 separately establishes focused MED-CD-SCOPE remediation execution; RUN-166 separately establishes bounded manual-entry MED-CD-ATOMICITY execution; RUN-173 adds one post-merge $safe_tests/$safe_assertions SAFE focused execution to the unique bounded total. Replays, supporting suites, adjacent filters, red failures, terminal-fixture failures, and atomicity remain separate or excluded, and none establishes full-suite, coverage, application-browser, ease, release, Pass, publication, or completion credit.",
    ),
    (
        "</tr><tr><td>RUN-162 focused MED-CD-SCOPE execution</td><td>$med_cd_tests tests / $med_cd_assertions assertions passed on advanced main; $med_cd_related_tests related controller/command tests passed in the overlapping broader lane; its 2 INR failures reproduced at base and are not attributed to RUN-162</td><td class=\"partial\">bounded MED-CD-SCOPE remediation evidence only · full-suite green false · no coverage credit</td></tr><tr><td>RUN-166 bounded manual-entry atomicity execution</td><td>$atomicity_tests claim-specific test functions / $atomicity_assertions assertions / $atomicity_races synchronized two-process races; supporting $atomicity_supporting_tests tests / $atomicity_supporting_assertions assertions overlap prior denominators</td><td class=\"partial\">reported separately from 78/1,529 · no balance-check, destruction, sibling-writer, forced-deadlock, stress, browser, or full-suite credit</td></tr><tr><td>RUN-089 designated-application preflight</td>",
        "</tr><tr><td>RUN-162 focused MED-CD-SCOPE execution</td><td>$med_cd_tests tests / $med_cd_assertions assertions passed on advanced main; $med_cd_related_tests related controller/command tests passed in the overlapping broader lane; its 2 INR failures reproduced at base and are not attributed to RUN-162</td><td class=\"partial\">bounded MED-CD-SCOPE remediation evidence only · full-suite green false · no coverage credit</td></tr><tr><td>RUN-166 bounded manual-entry atomicity execution</td><td>$atomicity_tests claim-specific test functions / $atomicity_assertions assertions / $atomicity_races synchronized two-process races; supporting $atomicity_supporting_tests tests / $atomicity_supporting_assertions assertions overlap prior denominators</td><td class=\"partial\">reported separately from the historical 78/1,529 and current $unique_bounded_tests/$unique_bounded_assertions · no balance-check, destruction, sibling-writer, forced-deadlock, stress, browser, or full-suite credit</td></tr><tr><td>RUN-173 SAFE alert-dedup execution</td><td>$safe_tests post-merge focused tests / $safe_assertions assertions; supporting $safe_bridge_tests/$safe_bridge_assertions bridge and $safe_hs_tests/$safe_hs_assertions HsEvent evidence reported separately; $safe_terminal_failures terminal-fixture failures occurred before bridge/dedup execution</td><td class=\"partial\">bounded concern identity and observer custody only · red and isolated-green replay not re-aggregated · supporting/adjacent/terminal evidence excluded · full-suite green false</td></tr><tr><td>RUN-089 designated-application preflight</td>",
    ),
    ("RUN-155–167 medication-governance provenance, adjudication, remediation, and reporting", "RUN-155–174 bounded disposition provenance, remediation, and reporting"),
    (
        "RUN-158 verifies only the exact RUN-157 dashboard; RUN-161 verifies only the exact RUN-160 dashboard; RUN-164 separately verifies only the exact now-superseded RUN-163 dashboard at 4/4 viewports with 33/33 visible checks, 10/10 navigation targets, 403/403 local resources, and zero overflow, duplicate authored IDs, console warnings/errors, or page errors. None transfers to the RUN-167 dashboard or the application.",
        "RUN-158, RUN-161, RUN-164, RUN-168, and RUN-172 each verify only their exact now-superseded reporting dashboards. RUN-172 records 4/4 viewports, 55/55 visible checks, 10/10 navigation targets, 426/426 local resources, and zero overflow, duplicate authored IDs, console warnings/errors, or page errors for the exact RUN-171 HTML. None transfers to the RUN-175 dashboard or the application.",
    ),
    (
        "RUN-167 reports 12 retained identities = 9 current provisional P1 + 2 historical already-fixed + 1 historical remediated, with separate $atomicity_tests/$atomicity_assertions/$atomicity_races atomicity evidence, $benchmark_mapped/340 mappings, $final_no_matches/340 final no-match/NCM, $benchmark_unresolved unresolved targets, Gate 4 false, and RUN-168 subsequently verified the exact RUN-167 dashboard.",
        "RUN-167 reports its historical 12-identity split of 9 current provisional P1 + 2 historical already-fixed + 1 historical remediated, with separate $atomicity_tests/$atomicity_assertions/$atomicity_races atomicity evidence; RUN-168 verifies that exact dashboard. RUN-173/R establish and independently authorize reporting for <span class=\"mono\">SAFE-ALERT-DEDUP-IDENTITY-01</span>: concern ID now precedes client, asset, and null fallback inside the unchanged 30-minute dedup window, so distinct same-client, same-Site personless, and cross-Site personless concerns stay separate; a same-concern retry at +5 minutes stays idempotent; observer custody stays concern-owned; and the accepted +31-minute lifecycle remains unchanged. The isolated red run was $safe_red_failed failed + $safe_red_warning_pass warning-pass / $safe_red_assertions assertions; only the post-merge $safe_tests/$safe_assertions is counted once. Supporting $safe_bridge_tests/$safe_bridge_assertions and $safe_hs_tests/$safe_hs_assertions are separate, and $safe_terminal_failures pre-bridge terminal-fixture failures are excluded. RUN-174 records the current $finding_count + $historical_fixed_count + $historical_remediated_count split and $unique_bounded_tests/$unique_bounded_assertions unique bounded total. Baseline <span class=\"mono\">$safe_baseline_short</span>, fix <span class=\"mono\">$safe_fix_short</span>, local merge <span class=\"mono\">$safe_merge_short</span>, and regression-test SHA-256 <span class=\"mono\">$safe_test_sha256</span> are pinned; the merge is not published to origin/main <span class=\"mono\">$safe_origin_short</span>. Timeless retry, terminal fixture repair, unused escalation semantics, broader safeguarding, browser, benchmark, final-finding, release, Pass, and completion credit are not inherited.",
    ),
    ("Fresh RUN-172 audit-dashboard verification required", "Fresh RUN-175 audit-dashboard verification required"),
    (
        "The exact RUN-171 reporting dashboard must be checked in RUN-172 at 1440×900, 1280×800, 1024×768, and 390×844.",
        "The exact RUN-174 reporting dashboard must be generated and checked in RUN-175 at 1440×900, 1280×800, 1024×768, and 390×844.",
    ),
    (
        "RUN-168 verifies only the superseded RUN-167 HTML; RUN-169/R establish one Fleet alerts-config route/action candidate, RUN-170 integrates the bounded owner/bridge, RUN-170R independently authorizes reporting, and RUN-171 alone performs live ownership/queue reporting; none supplies audit-dashboard verification for the new HTML.",
        "RUN-172 verifies only the superseded RUN-171 HTML; RUN-173 establishes the bounded SAFE remediation and local integration, RUN-173R independently authorizes retirement reporting, and RUN-174 alone changes the live register and reporting sources while preserving the verified RUN-171 HTML byte-for-byte. None supplies audit-dashboard verification for the new RUN-175 HTML.",
    ),
    ("The linked RUN-172 receipt must record", "The linked RUN-175 receipt must record"),
    (
        "RUN-159's $med_rbac_tests/$med_rbac_assertions MED-RBAC evidence, RUN-162's $med_cd_tests/$med_cd_assertions MED-CD-SCOPE evidence, RUN-164's exact superseded dashboard verification, RUN-165 source-only atomicity evidence, RUN-166's separately reported $atomicity_tests test functions / $atomicity_assertions assertions / $atomicity_races synchronized races plus nonaggregated $atomicity_supporting_tests/$atomicity_supporting_assertions support, RUN-166R retirement-reporting authorization, RUN-167's exact bounded atomicity reconciliation, and residual compound-scope noninheritance",
        "RUN-159's $med_rbac_tests/$med_rbac_assertions MED-RBAC evidence, RUN-162's $med_cd_tests/$med_cd_assertions MED-CD-SCOPE evidence, RUN-166's separately reported $atomicity_tests/$atomicity_assertions/$atomicity_races atomicity evidence plus nonaggregated $atomicity_supporting_tests/$atomicity_supporting_assertions support, RUN-172's exact historical dashboard verification, RUN-173's post-merge SAFE $safe_tests/$safe_assertions counted once into $unique_bounded_tests/$unique_bounded_assertions, its red $safe_red_failed-failed + $safe_red_warning_pass-warning-pass/$safe_red_assertions reproduction, excluded isolated-green replay, supporting $safe_bridge_tests/$safe_bridge_assertions bridge and $safe_hs_tests/$safe_hs_assertions HsEvent evidence, $safe_terminal_failures excluded terminal-fixture failures, local-main nonpublication, unchanged 30-minute dedup/+5-minute retry/+31-minute lifecycle contract, and explicit noninheritance for timeless retry, terminal repair, unused escalation semantics, and broader safeguarding correctness",
    ),
    ("It verifies the RUN-171 audit artifact only", "It verifies the RUN-174 audit artifact only"),
    (
        '<a href="evidence/browser/current-audit-dashboard-verification-run-172-wave-31.json">RUN-172 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)',
        '<a href="evidence/browser/current-audit-dashboard-verification-run-175-wave-32.json">RUN-175 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)',
    ),
    ("RUN-071–171 evidence lineage", "RUN-071–174 evidence lineage"),
    (
        "Every current raw, generated, reviewed, and integrated RUN-077–170R source/reporting/runtime/benchmark/remediation/ownership artifact is linked with its exact SHA-256; RUN-171 is the current reporting generator execution.",
        "Every current raw, generated, reviewed, and integrated RUN-077–173R source/reporting/runtime/benchmark/remediation/ownership artifact is linked with its exact SHA-256; RUN-174 is the current reporting generator execution and RUN-175 remains the fresh exact-dashboard gate.",
    ),
    (
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, exact-artifact, and bounded Fleet ownership evidence through RUN-170R, reported in RUN-171, with fresh RUN-172 dashboard verification required. Exactly two matrix rows have static benchmark-mapping credit. RUN-159, RUN-162, and RUN-166 retain their separate medication evidence boundaries; RUN-169/R and RUN-170/R add only one Fleet alerts-config static route owner and bridge. No lane grants inherited page, consumer, caller, service, model, correctness, selected-GET execution, browser, benchmark-final, final-finding, Pass, release, feature-completion, Gate 4, or audit-completion credit.",
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, exact-artifact, and bounded Fleet ownership evidence through RUN-173R, reported in RUN-174, with fresh RUN-175 dashboard verification required. Exactly two matrix rows have static benchmark-mapping credit. RUN-159, RUN-162, RUN-166, and RUN-173 retain separate evidence boundaries; only MED-RBAC $med_rbac_tests/$med_rbac_assertions, MED-CD-SCOPE $med_cd_tests/$med_cd_assertions, and the post-merge SAFE $safe_tests/$safe_assertions contribute once to the current $unique_bounded_tests/$unique_bounded_assertions unique bounded total. SAFE remains local-main-only and unpublished. No lane grants inherited timeless retry, terminal-fixture repair, unused escalation semantics, broader safeguarding, page, consumer, caller, service, model, selected-GET execution, browser, benchmark-final, final-finding, Pass, release, feature-completion, Gate 4, or audit-completion credit.",
    ),
]
for old, new in run_174_template_rewrites:
    assert current_template_text.count(old) == 1, f"Expected one RUN-174 template rewrite target: {old}"
    current_template_text = current_template_text.replace(old, new)

run_177_template_rewrites = [
    ('href="#checkpoint">RUN-174</a>', 'href="#checkpoint">RUN-177</a>'),
    ("RUN-071–174 current reporting checkpoint:", "RUN-071–177 current reporting checkpoint:"),
    ("RUN-071–174 completion-gate checkpoint", "RUN-071–177 completion-gate checkpoint"),
    (
        "At RUN-174 those records split into 8 current provisional + 2 historical already-fixed + 2 historical remediated.",
        "At RUN-174 those records split into 8 current provisional + 2 historical already-fixed + 2 historical remediated. The current RUN-177 register retains 13 identities: $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated; FLEET-TRIP-INDEX-SITE-PRIVACY-01 is a new historical-remediated record with candidate-only feature association and zero static-ownership credit.",
    ),
    (
        "RUN-145 grants exactly two target-specific static benchmark-mapping credits. Apart from separately bounded RUN-159 MED-RBAC, RUN-162 MED-CD-SCOPE, RUN-166 manual-entry MED-CD-ATOMICITY, and RUN-173 post-merge SAFE focused executions, no represented wave grants broader or full-suite application runtime or coverage; only MED-RBAC, MED-CD-SCOPE, and post-merge SAFE contribute once to the current $unique_bounded_tests/$unique_bounded_assertions total. No represented wave grants signed-in application-browser, ease, release, Pass, publication, final-finding, feature-completion, or audit-completion credit.",
        "RUN-145 grants exactly two target-specific static benchmark-mapping credits. Apart from separately bounded RUN-159 MED-RBAC, RUN-162 MED-CD-SCOPE, RUN-166 manual-entry MED-CD-ATOMICITY, RUN-173 post-merge SAFE, and RUN-176 post-merge Fleet focused executions, no represented wave grants broader or full-suite application runtime or coverage; only MED-RBAC, MED-CD-SCOPE, post-merge SAFE, and post-merge Fleet contribute once to the current $unique_bounded_tests/$unique_bounded_assertions total. No represented wave grants signed-in application-browser, ease, release, Pass, publication, final-finding, feature-completion, or audit-completion credit.",
    ),
    ("RUN-001 through RUN-174 are represented by audit artifacts.", "RUN-001 through RUN-177 are represented by audit artifacts."),
    (
        "<li>RUN-174: SAFE record reclassified in place · $finding_count provisional + $historical_fixed_count already-fixed + $historical_remediated_count remediated · $unique_bounded_tests/$unique_bounded_assertions unique bounded disposition total · dashboard HTML frozen pending RUN-175</li>",
        "<li>RUN-174: SAFE record reclassified in place · historical 8 provisional + 2 already-fixed + 2 remediated · historical 83/1,589 unique bounded total</li><li>RUN-175: exact RUN-174 dashboard verified at 4/4 viewports · 79/79 visible checks · 10/10 navigation · 435/435 local resources · zero application credit</li><li>RUN-176: Fleet trip-index Site-privacy defect reproduced and remediated in exactly two transferred paths · post-merge $fleet_tests/$fleet_assertions uniquely counted · local main only · not published</li><li>RUN-176R: exact remediation artifacts independently reviewed GO · one new historical-remediated record authorized</li><li>RUN-177: Fleet trip privacy historical-remediated record added · $finding_count provisional + $historical_fixed_count already-fixed + $historical_remediated_count remediated · $unique_bounded_tests/$unique_bounded_assertions unique bounded disposition total · dashboard HTML frozen pending RUN-178</li>",
    ),
    (
        "RUN-151, RUN-155, RUN-158, RUN-161, RUN-164, RUN-168, and RUN-172 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-174 reporting sources or the RUN-175 dashboard that will be generated from them.",
        "RUN-151, RUN-155, RUN-158, RUN-161, RUN-164, RUN-168, RUN-172, and RUN-175 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-177 reporting sources or the RUN-178 dashboard that will be generated from them.",
    ),
    ("RUN-172–174 SAFE alert dedup remediation and reporting checkpoint", "RUN-175–177 Fleet trip index Site privacy remediation checkpoint"),
    (
        "<tr><td>RUN-174 live reporting</td><td><strong>$finding_count current provisional P1 + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated · $unique_bounded_tests tests / $unique_bounded_assertions assertions</strong></td><td class=\"partial\">SAFE reclassified in place · zero final finding · dashboard HTML unchanged · fresh RUN-175 required</td></tr>",
        "<tr><td>RUN-174 live reporting</td><td><strong>8 current provisional P1 + 2 historical already-fixed + 2 historical remediated · 83 tests / 1,589 assertions</strong></td><td class=\"partial\">SAFE reclassified in place · exact dashboard later verified by RUN-175</td></tr><tr><td>RUN-175 exact dashboard verification</td><td><strong>4/4 viewports · 79/79 visible checks · 10/10 navigation · 435/435 local resources</strong></td><td class=\"partial\">exact superseded RUN-174 audit artifact only · zero application credit</td></tr><tr><td>RUN-176/R Fleet remediation and review</td><td><strong>root red $fleet_root_red_failed failed / $fleet_root_red_assertions assertions · expanded red $fleet_expanded_red_failed failed / $fleet_expanded_red_assertions assertions · post-merge $fleet_tests passed / $fleet_assertions assertions · baseline $fleet_baseline_short · fix $fleet_fix_short · exact two-path merge $fleet_merge_short</strong></td><td class=\"partial\">selected GET/CSV Site privacy only · independent GO · local main not published · static ownership pending</td></tr><tr><td>RUN-177 live reporting</td><td><strong>$finding_count current provisional P1 + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated · $unique_bounded_tests tests / $unique_bounded_assertions assertions</strong></td><td class=\"partial\">one new Fleet historical-remediated record · zero final finding · dashboard HTML unchanged · fresh RUN-178 required</td></tr>",
    ),
    (
        "<li><span class=\"partial\">$med_rbac_tests</span> bounded MED-RBAC tests / $med_rbac_assertions assertions</li><li><span class=\"partial\">$med_cd_tests</span> focused MED-CD-SCOPE tests / $med_cd_assertions assertions</li><li><span class=\"partial\">$safe_tests</span> post-merge SAFE alert-dedup tests / $safe_assertions assertions; counted once in the current $unique_bounded_tests/$unique_bounded_assertions unique bounded total</li><li><span class=\"partial\">$atomicity_tests</span> MED-CD-ATOMICITY test functions / $atomicity_assertions assertions / $atomicity_races synchronized races; separately reported, excluded from both the historical 78/1,529 and current $unique_bounded_tests/$unique_bounded_assertions totals · supporting $atomicity_supporting_tests/$atomicity_supporting_assertions overlaps · no full-suite or coverage credit</li>",
        "<li><span class=\"partial\">$med_rbac_tests</span> bounded MED-RBAC tests / $med_rbac_assertions assertions</li><li><span class=\"partial\">$med_cd_tests</span> focused MED-CD-SCOPE tests / $med_cd_assertions assertions</li><li><span class=\"partial\">$safe_tests</span> post-merge SAFE alert-dedup tests / $safe_assertions assertions; counted once</li><li><span class=\"partial\">$fleet_tests</span> post-merge Fleet trip-index tests / $fleet_assertions assertions; counted once in the current $unique_bounded_tests/$unique_bounded_assertions unique bounded total</li><li><span class=\"partial\">$atomicity_tests</span> MED-CD-ATOMICITY test functions / $atomicity_assertions assertions / $atomicity_races synchronized races; separately reported and excluded · supporting $atomicity_supporting_tests/$atomicity_supporting_assertions overlaps · no full-suite or coverage credit</li>",
    ),
    (
        "<div class=\"card\"><strong class=\"partial\">$finding_count</strong><span>current provisional P1 claims</span><small>$historical_fixed_count historical already-fixed · $historical_remediated_count historical remediated · none final</small></div><div class=\"card\"><strong>$unique_bounded_tests / $unique_bounded_assertions</strong><span>unique bounded disposition evidence</span><small>$med_rbac_tests/$med_rbac_assertions MED-RBAC + $med_cd_tests/$med_cd_assertions MED-CD-SCOPE + $safe_tests/$safe_assertions SAFE; atomicity and support remain separate</small></div>",
        "<div class=\"card\"><strong class=\"partial\">$finding_count</strong><span>current provisional P1 claims</span><small>$historical_fixed_count historical already-fixed · $historical_remediated_count historical remediated · 13 retained · none final</small></div><div class=\"card\"><strong>$unique_bounded_tests / $unique_bounded_assertions</strong><span>unique bounded disposition evidence</span><small>$med_rbac_tests/$med_rbac_assertions MED-RBAC + $med_cd_tests/$med_cd_assertions MED-CD-SCOPE + $safe_tests/$safe_assertions SAFE + $fleet_tests/$fleet_assertions Fleet; atomicity and support remain separate</small></div>",
    ),
    (
        "The register retains 12 historical claim identities: $finding_count remain current provisional P1 claims, $historical_fixed_count are historical already-fixed records on current main, and $historical_remediated_count are historical remediated records. SAFE-ALERT-DEDUP-IDENTITY-01 is remediated on local main only and is not published to origin/main. None is a final finding or closed completion gate.",
        "The register retains 13 historical claim identities: $finding_count remain current provisional P1 claims, $historical_fixed_count are historical already-fixed records on current main, and $historical_remediated_count are historical remediated records. SAFE-ALERT-DEDUP-IDENTITY-01 and FLEET-TRIP-INDEX-SITE-PRIVACY-01 are remediated on local main only and are not published to origin/main; the Fleet CAP association remains candidate-only and route ownership is PENDING_FRESH_SEMANTIC_REVIEW. None is a final finding or closed completion gate.",
    ),
    (
        "RUN-159 establishes bounded MED-RBAC execution; RUN-162 separately establishes focused MED-CD-SCOPE remediation execution; RUN-166 separately establishes bounded manual-entry MED-CD-ATOMICITY execution; RUN-173 adds one post-merge $safe_tests/$safe_assertions SAFE focused execution to the unique bounded total. Replays, supporting suites, adjacent filters, red failures, terminal-fixture failures, and atomicity remain separate or excluded, and none establishes full-suite, coverage, application-browser, ease, release, Pass, publication, or completion credit.",
        "RUN-159 establishes bounded MED-RBAC execution; RUN-162 separately establishes focused MED-CD-SCOPE remediation execution; RUN-166 separately establishes bounded manual-entry MED-CD-ATOMICITY execution; RUN-173 adds one post-merge $safe_tests/$safe_assertions SAFE focused execution and RUN-176 adds one post-merge $fleet_tests/$fleet_assertions Fleet focused execution to the unique bounded total. Replays, supporting suites, adjacent filters, red failures, terminal-fixture failures, and atomicity remain separate or excluded, and none establishes full-suite, coverage, application-browser, ease, release, Pass, publication, or completion credit.",
    ),
    (
        "</tr><tr><td>RUN-173 SAFE alert-dedup execution</td><td>$safe_tests post-merge focused tests / $safe_assertions assertions; supporting $safe_bridge_tests/$safe_bridge_assertions bridge and $safe_hs_tests/$safe_hs_assertions HsEvent evidence reported separately; $safe_terminal_failures terminal-fixture failures occurred before bridge/dedup execution</td><td class=\"partial\">bounded concern identity and observer custody only · red and isolated-green replay not re-aggregated · supporting/adjacent/terminal evidence excluded · full-suite green false</td></tr><tr><td>RUN-089 designated-application preflight</td>",
        "</tr><tr><td>RUN-173 SAFE alert-dedup execution</td><td>$safe_tests post-merge focused tests / $safe_assertions assertions; supporting $safe_bridge_tests/$safe_bridge_assertions bridge and $safe_hs_tests/$safe_hs_assertions HsEvent evidence reported separately; $safe_terminal_failures terminal-fixture failures occurred before bridge/dedup execution</td><td class=\"partial\">bounded concern identity and observer custody only · red and isolated-green replay not re-aggregated · supporting/adjacent/terminal evidence excluded · full-suite green false</td></tr><tr><td>RUN-176 Fleet trip-index Site-privacy execution</td><td>$fleet_tests post-merge focused tests / $fleet_assertions assertions; supporting $fleet_supporting_tests/$fleet_supporting_assertions VehicleController regressions reported separately; root and expanded red executions and isolated green replay excluded</td><td class=\"partial\">selected GET/CSV rows, filters, aggregates, provenance, archived Sites, and nested identity only · static ownership PENDING_FRESH_SEMANTIC_REVIEW · full-suite green unproved</td></tr><tr><td>RUN-089 designated-application preflight</td>",
    ),
    ("RUN-155–174 bounded disposition provenance, remediation, and reporting", "RUN-155–177 bounded disposition provenance, remediation, and reporting"),
    ("Fresh RUN-175 audit-dashboard verification required", "Fresh RUN-178 audit-dashboard verification required"),
    (
        "The exact RUN-174 reporting dashboard must be generated and checked in RUN-175 at 1440×900, 1280×800, 1024×768, and 390×844.",
        "The exact RUN-177 reporting dashboard must be generated and checked in RUN-178 at 1440×900, 1280×800, 1024×768, and 390×844.",
    ),
    (
        "RUN-172 verifies only the superseded RUN-171 HTML; RUN-173 establishes the bounded SAFE remediation and local integration, RUN-173R independently authorizes retirement reporting, and RUN-174 alone changes the live register and reporting sources while preserving the verified RUN-171 HTML byte-for-byte. None supplies audit-dashboard verification for the new RUN-175 HTML.",
        "RUN-175 verifies only the superseded RUN-174 HTML; RUN-176 establishes the bounded Fleet remediation and local integration, RUN-176R independently authorizes one new historical-remediated record, and RUN-177 alone changes the live register and reporting sources while preserving the verified RUN-175 HTML byte-for-byte. None supplies audit-dashboard verification for the new RUN-178 HTML.",
    ),
    ("The linked RUN-175 receipt must record", "The linked RUN-178 receipt must record"),
    ("It verifies the RUN-174 audit artifact only", "It verifies the RUN-177 audit artifact only"),
    (
        '<a href="evidence/browser/current-audit-dashboard-verification-run-175-wave-32.json">RUN-175 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)',
        '<a href="evidence/browser/current-audit-dashboard-verification-run-178-wave-33.json">RUN-178 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)',
    ),
    ("RUN-071–174 evidence lineage", "RUN-071–177 evidence lineage"),
    (
        "Every current raw, generated, reviewed, and integrated RUN-077–173R source/reporting/runtime/benchmark/remediation/ownership artifact is linked with its exact SHA-256; RUN-174 is the current reporting generator execution and RUN-175 remains the fresh exact-dashboard gate.",
        "Every current raw, generated, reviewed, and integrated RUN-077–176R source/reporting/runtime/benchmark/remediation/ownership artifact is linked with its exact SHA-256; RUN-177 is the current reporting generator execution and RUN-178 remains the fresh exact-dashboard gate.",
    ),
    (
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, exact-artifact, and bounded Fleet ownership evidence through RUN-173R, reported in RUN-174, with fresh RUN-175 dashboard verification required. Exactly two matrix rows have static benchmark-mapping credit. RUN-159, RUN-162, RUN-166, and RUN-173 retain separate evidence boundaries; only MED-RBAC $med_rbac_tests/$med_rbac_assertions, MED-CD-SCOPE $med_cd_tests/$med_cd_assertions, and the post-merge SAFE $safe_tests/$safe_assertions contribute once to the current $unique_bounded_tests/$unique_bounded_assertions unique bounded total. SAFE remains local-main-only and unpublished. No lane grants inherited timeless retry, terminal-fixture repair, unused escalation semantics, broader safeguarding, page, consumer, caller, service, model, selected-GET execution, browser, benchmark-final, final-finding, Pass, release, feature-completion, Gate 4, or audit-completion credit.",
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, exact-artifact, and bounded Fleet evidence through RUN-176R, reported in RUN-177, with fresh RUN-178 dashboard verification required. Exactly two matrix rows have static benchmark-mapping credit. RUN-159, RUN-162, RUN-166, RUN-173, and RUN-176 retain separate evidence boundaries; only MED-RBAC $med_rbac_tests/$med_rbac_assertions, MED-CD-SCOPE $med_cd_tests/$med_cd_assertions, post-merge SAFE $safe_tests/$safe_assertions, and post-merge Fleet $fleet_tests/$fleet_assertions contribute once to the current $unique_bounded_tests/$unique_bounded_assertions unique bounded total. SAFE and Fleet remain local-main-only and unpublished. Fleet route ownership remains PENDING_FRESH_SEMANTIC_REVIEW. No lane grants inherited broader Fleet or safeguarding correctness, page, consumer, caller, service, model, adjacent-route execution, browser, benchmark-final, final-finding, Pass, release, feature-completion, Gate 4, or audit-completion credit.",
    ),
    (
        "RUN-159's $med_rbac_tests/$med_rbac_assertions MED-RBAC evidence, RUN-162's $med_cd_tests/$med_cd_assertions MED-CD-SCOPE evidence, RUN-166's separately reported $atomicity_tests/$atomicity_assertions/$atomicity_races atomicity evidence plus nonaggregated $atomicity_supporting_tests/$atomicity_supporting_assertions support, RUN-172's exact historical dashboard verification, RUN-173's post-merge SAFE $safe_tests/$safe_assertions counted once into $unique_bounded_tests/$unique_bounded_assertions, its red $safe_red_failed-failed + $safe_red_warning_pass-warning-pass/$safe_red_assertions reproduction, excluded isolated-green replay, supporting $safe_bridge_tests/$safe_bridge_assertions bridge and $safe_hs_tests/$safe_hs_assertions HsEvent evidence, $safe_terminal_failures excluded terminal-fixture failures, local-main nonpublication, unchanged 30-minute dedup/+5-minute retry/+31-minute lifecycle contract, and explicit noninheritance for timeless retry, terminal repair, unused escalation semantics, and broader safeguarding correctness",
        "RUN-159's $med_rbac_tests/$med_rbac_assertions MED-RBAC evidence, RUN-162's $med_cd_tests/$med_cd_assertions MED-CD-SCOPE evidence, RUN-166's separately reported $atomicity_tests/$atomicity_assertions/$atomicity_races atomicity evidence plus nonaggregated $atomicity_supporting_tests/$atomicity_supporting_assertions support, RUN-175's exact historical dashboard verification, RUN-173's post-merge SAFE $safe_tests/$safe_assertions counted once, its red $safe_red_failed-failed + $safe_red_warning_pass-warning-pass/$safe_red_assertions reproduction, excluded isolated-green replay, supporting $safe_bridge_tests/$safe_bridge_assertions bridge and $safe_hs_tests/$safe_hs_assertions HsEvent evidence, $safe_terminal_failures excluded terminal-fixture failures, unchanged 30-minute dedup/+5-minute retry/+31-minute lifecycle contract, RUN-176's post-merge Fleet $fleet_tests/$fleet_assertions counted once into $unique_bounded_tests/$unique_bounded_assertions, root red $fleet_root_red_failed/$fleet_root_red_assertions and expanded red $fleet_expanded_red_failed/$fleet_expanded_red_assertions reproduction, excluded isolated-green replay, supporting $fleet_supporting_tests/$fleet_supporting_assertions VehicleController evidence, local-main nonpublication, candidate-only CAP-FLEET-VEHICLE-REGISTER association, route ownership PENDING_FRESH_SEMANTIC_REVIEW, and explicit noninheritance for broader Fleet correctness, adjacent routes, browser, benchmark, release, and completion",
    ),
    (
        "current RUN-174 split of 12 retained claim identities",
        "current RUN-177 split of 13 retained claim identities",
    ),
    (
        "12 retained claim identities split into $finding_count current provisional P1, $historical_fixed_count historical already-fixed, and $historical_remediated_count historical remediated",
        "13 retained claim identities split into $finding_count current provisional P1, $historical_fixed_count historical already-fixed, and $historical_remediated_count historical remediated",
    ),
]
run_177_rewrite_expected_counts = {
    "current RUN-174 split of 12 retained claim identities": 2,
}
for old, new in run_177_template_rewrites:
    expected_count = run_177_rewrite_expected_counts.get(old, 1)
    assert current_template_text.count(old) == expected_count, (
        f"Expected {expected_count} RUN-177 template rewrite target(s): {old}"
    )
    current_template_text = current_template_text.replace(old, new)

run_181_template_rewrites = [
    ('<a href="#checkpoint">RUN-177</a>', '<a href="#checkpoint">RUN-181</a>'),
    ("RUN-001 through RUN-177 are represented by audit artifacts.", "RUN-001 through RUN-181 are represented by audit artifacts."),
    (
        "<li>RUN-177: Fleet trip privacy historical-remediated record added · $finding_count provisional + $historical_fixed_count already-fixed + $historical_remediated_count remediated · $unique_bounded_tests/$unique_bounded_assertions unique bounded disposition total · dashboard HTML frozen pending RUN-178</li>",
        "<li>RUN-177: Fleet trip privacy historical-remediated record added · $finding_count provisional + $historical_fixed_count already-fixed + $historical_remediated_count remediated · $unique_bounded_tests/$unique_bounded_assertions unique bounded disposition total</li><li>RUN-178: exact RUN-177 dashboard verified at 4/4 viewports · 97/97 visible checks · 10/10 navigation · 443/443 local resources · zero application credit</li><li>RUN-179/R: index 84 Fleet trip-index review preserves two invalidated older-bundle SHARED judgments, the strict-current OWNER/EVIDENCE_GAP split, and two fresh OWNER tiebreaks · no older-bundle identity or credit imported</li><li>RUN-180: exactly one fleet-assets.trips.index route owner and VehicleController::trips bridge integrated · zero page or new-feature credit</li><li>RUN-180R: three sealed GO lanes · zero discrepancies · each lane withholds reporting permission · complete synthesis authorizes reporting only</li><li>RUN-181: live static ledger reported as $static_owner_records owners / $static_owner_routes routes / $static_owner_pages pages / $static_action_bridges bridges · $queue_reviewed reviewed / $queue_pending pending / $queue_without_owner without ownership · dashboard HTML frozen pending RUN-182</li>",
    ),
    ("RUN-175–177 Fleet trip index Site privacy remediation checkpoint", "RUN-178–181 Fleet trip-index route/action ownership checkpoint"),
    (
        "<tr><td>RUN-177 live reporting</td><td><strong>$finding_count current provisional P1 + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated · $unique_bounded_tests tests / $unique_bounded_assertions assertions</strong></td><td class=\"partial\">one new Fleet historical-remediated record · zero final finding · dashboard HTML unchanged · fresh RUN-178 required</td></tr>",
        "<tr><td>RUN-177 live reporting</td><td><strong>$finding_count current provisional P1 + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated · $unique_bounded_tests tests / $unique_bounded_assertions assertions</strong></td><td class=\"partial\">one new Fleet historical-remediated record · exact dashboard later verified by RUN-178</td></tr><tr><td>RUN-178 exact dashboard verification</td><td><strong>4/4 viewports · 97/97 visible checks · 10/10 navigation · 443/443 local resources</strong></td><td class=\"partial\">exact superseded RUN-177 audit artifact only · zero application credit</td></tr><tr><td>RUN-179/R route/action review</td><td><strong>strict-current OWNER/EVIDENCE_GAP split · two fresh OWNER tiebreaks · older 2026-08-12 bundle excluded</strong></td><td class=\"partial\">dissent and invalidated preliminary judgments preserved · static identity only</td></tr><tr><td>RUN-180/R overlay and review</td><td><strong>one route owner + one action bridge · three sealed GO lanes · zero discrepancies</strong></td><td class=\"partial\">no page/new feature/correctness/runtime/downstream credit · synthesis-only reporting permission</td></tr><tr><td>RUN-181 live reporting</td><td><strong>$static_owner_records owners / $static_owner_routes routes / $static_owner_pages pages / $static_action_bridges bridges · $queue_reviewed reviewed / $queue_pending pending / $queue_without_owner without ownership</strong></td><td class=\"partial\">index 84 integrated · next index 85 RUN090-ROUTE-0086 / RUN077-ROUTE-0694 · dashboard HTML unchanged · fresh RUN-182 required</td></tr>",
    ),
    ("Fresh RUN-178 audit-dashboard verification required", "Fresh RUN-182 audit-dashboard verification required"),
    (
        "The exact RUN-177 reporting dashboard must be generated and checked in RUN-178 at 1440×900, 1280×800, 1024×768, and 390×844.",
        "The exact RUN-181 reporting dashboard must be generated and checked in RUN-182 at 1440×900, 1280×800, 1024×768, and 390×844.",
    ),
    (
        "RUN-175 verifies only the superseded RUN-174 HTML; RUN-176 establishes the bounded Fleet remediation and local integration, RUN-176R independently authorizes one new historical-remediated record, and RUN-177 alone changes the live register and reporting sources while preserving the verified RUN-175 HTML byte-for-byte. None supplies audit-dashboard verification for the new RUN-178 HTML.",
        "RUN-178 verifies only the superseded RUN-177 HTML; RUN-179/R establish one Fleet trip-index route/action decision while preserving dissent and excluding the older bundle, RUN-180 integrates one owner/bridge, RUN-180R's complete synthesis authorizes reporting only, and RUN-181 alone changes live ownership/queue reporting while preserving the verified RUN-178 HTML byte-for-byte. None supplies audit-dashboard verification for the new RUN-182 HTML.",
    ),
    ("The linked RUN-178 receipt must record", "The linked RUN-182 receipt must record"),
    ("It verifies the RUN-177 audit artifact only", "It verifies the RUN-181 audit artifact only"),
    (
        '<a href="evidence/browser/current-audit-dashboard-verification-run-178-wave-33.json">RUN-178 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)',
        '<a href="evidence/browser/current-audit-dashboard-verification-run-182-wave-34.json">RUN-182 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)',
    ),
    ("RUN-071–177 evidence lineage", "RUN-071–181 evidence lineage"),
    (
        "Every current raw, generated, reviewed, and integrated RUN-077–176R source/reporting/runtime/benchmark/remediation/ownership artifact is linked with its exact SHA-256; RUN-177 is the current reporting generator execution and RUN-178 remains the fresh exact-dashboard gate.",
        "Every current raw, generated, reviewed, and integrated RUN-077–180R source/reporting/runtime/benchmark/remediation/ownership artifact is linked with its exact SHA-256; RUN-181 is the current reporting generator execution and RUN-182 remains the fresh exact-dashboard gate.",
    ),
    (
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, exact-artifact, and bounded Fleet evidence through RUN-176R, reported in RUN-177, with fresh RUN-178 dashboard verification required. Exactly two matrix rows have static benchmark-mapping credit. RUN-159, RUN-162, RUN-166, RUN-173, and RUN-176 retain separate evidence boundaries; only MED-RBAC $med_rbac_tests/$med_rbac_assertions, MED-CD-SCOPE $med_cd_tests/$med_cd_assertions, post-merge SAFE $safe_tests/$safe_assertions, and post-merge Fleet $fleet_tests/$fleet_assertions contribute once to the current $unique_bounded_tests/$unique_bounded_assertions unique bounded total. SAFE and Fleet remain local-main-only and unpublished. Fleet route ownership remains PENDING_FRESH_SEMANTIC_REVIEW. No lane grants inherited broader Fleet or safeguarding correctness, page, consumer, caller, service, model, adjacent-route execution, browser, benchmark-final, final-finding, Pass, release, feature-completion, Gate 4, or audit-completion credit.",
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, exact-artifact, and bounded Fleet evidence through RUN-180R, reported in RUN-181, with fresh RUN-182 dashboard verification required. Exactly two matrix rows have static benchmark-mapping credit. RUN-159, RUN-162, RUN-166, RUN-173, and RUN-176 retain separate evidence boundaries; only MED-RBAC $med_rbac_tests/$med_rbac_assertions, MED-CD-SCOPE $med_cd_tests/$med_cd_assertions, post-merge SAFE $safe_tests/$safe_assertions, and post-merge Fleet $fleet_tests/$fleet_assertions contribute once to the unchanged $unique_bounded_tests/$unique_bounded_assertions unique bounded total. RUN-179/R–181 add only one Fleet trip-index static route owner and bridge while preserving dissent, excluding the older bundle, and adding zero finding or correctness credit. SAFE and Fleet remediation remain local-main-only and unpublished. No lane grants inherited playback, broader Fleet or safeguarding correctness, page, consumer, caller, service, model, adjacent-route execution, runtime, browser, benchmark-final, final-finding, Pass, release, publication, feature-completion, Gate 4, or audit-completion credit.",
    ),
    ("RUN-170/R current Fleet alerts-config route/action ownership", "RUN-180/R current Fleet trip-index route/action ownership"),
    ("RUN-090 frozen denominator / RUN-170R current accounting", "RUN-090 frozen denominator / RUN-180R current accounting"),
    ("visible 665/308/357 ownership, 96 bridges, 119/388 queue accounting, 97 owned/410 without ownership", "visible 666/309/357 ownership, 97 bridges, 120/387 queue accounting, 98 owned/409 without ownership"),
]
for old, new in run_181_template_rewrites:
    assert current_template_text.count(old) == 1, f"Expected one RUN-181 template rewrite target: {old}"
    current_template_text = current_template_text.replace(old, new)

run_184_template_rewrites = [
    ('<a href="#checkpoint">RUN-181</a>', '<a href="#checkpoint">RUN-184</a>'),
    ("RUN-001 through RUN-181 are represented by audit artifacts.", "RUN-001 through RUN-184 are represented by audit artifacts."),
    ("RUN-071–177 current reporting checkpoint:", "RUN-071–184 current reporting checkpoint:"),
    ("RUN-071–177 completion-gate checkpoint", "RUN-071–184 completion-gate checkpoint"),
    (
        "RUN-174 alone changes the live register to $finding_count provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated",
        "RUN-174 alone changed the live register to its historical 8 provisional + 2 historical already-fixed + 2 historical remediated",
    ),
    (
        "RUN-174 changes the current finding split to $finding_count provisional + $historical_fixed_count already-fixed + $historical_remediated_count remediated.",
        "RUN-174 changed its historical finding split to 8 provisional + 2 already-fixed + 2 remediated.",
    ),
    (
        "RUN-174 alone reconciles the live register to $finding_count provisional + $historical_fixed_count already-fixed + $historical_remediated_count remediated and $unique_bounded_tests/$unique_bounded_assertions uniquely counted tests/assertions",
        "RUN-174 alone reconciled its historical live register to 8 provisional + 2 already-fixed + 2 remediated and 83/1,589 uniquely counted tests/assertions",
    ),
    (
        "RUN-174 records the current $finding_count + $historical_fixed_count + $historical_remediated_count split and $unique_bounded_tests/$unique_bounded_assertions unique bounded total.",
        "RUN-174 recorded its historical 8 + 2 + 2 split and 83/1,589 unique bounded total.",
    ),
    (
        "Fleet alerts-config wave 31 reviewed = 1 owner · 1 route row + 1 bridge · 0 page rows · consumer, caller, service, model, page-owner, neighbor, correctness, and downstream context not inherited · index 83 integrated · index 84 fleet-assets.trips.index unresolved",
        "Fleet trip-index wave 34 integrated = 1 owner · 1 route row + 1 bridge · 0 page rows · historical index 83 alerts-config owner not recredited · page, sibling, caller, neighbor, correctness, and downstream context not inherited · index 84 integrated · index 85 fleet-assets.trips.playback pending with RUN090-ROUTE-0086 / RUN077-ROUTE-0694 · RUN-179/R dissent and invalidated preliminary judgments preserved · RUN-180R three sealed GO lanes with zero discrepancies",
    ),
    (
        "RUN-169/R–170/R establish the current $static_owner_records bounded source-owner records and $static_action_bridges action bridges while adding one fleet-assets.vehicles.alerts-config owner and one bridge",
        "RUN-169/R–170/R historically add one fleet-assets.vehicles.alerts-config owner and bridge, and RUN-179/R–181 later add one fleet-assets.trips.index owner and bridge to the current $static_owner_records bounded source-owner records and $static_action_bridges action bridges",
    ),
    (
        "integrating index 83 and leaving index 84 fleet-assets.trips.index unresolved",
        "integrating indexes 83 and 84 while leaving index 85 fleet-assets.trips.playback pending with RUN090-ROUTE-0086 / RUN077-ROUTE-0694",
    ),
    (
        "RUN-145 grants exactly two target-specific static benchmark-mapping credits. Apart from separately bounded RUN-159 MED-RBAC, RUN-162 MED-CD-SCOPE, RUN-166 manual-entry MED-CD-ATOMICITY, RUN-173 post-merge SAFE, and RUN-176 post-merge Fleet focused executions, no represented wave grants broader or full-suite application runtime or coverage; only MED-RBAC, MED-CD-SCOPE, post-merge SAFE, and post-merge Fleet contribute once to the current $unique_bounded_tests/$unique_bounded_assertions total.",
        "RUN-145 grants exactly two target-specific static benchmark-mapping credits. Apart from separately bounded RUN-159 MED-RBAC, RUN-162 MED-CD-SCOPE, RUN-166 manual-entry MED-CD-ATOMICITY, RUN-173 post-merge SAFE, RUN-176 post-merge Fleet trip-index, and RUN-183 post-merge Fleet playback focused executions, no represented wave grants broader or full-suite application runtime or coverage; only MED-RBAC, MED-CD-SCOPE, post-merge SAFE, post-merge Fleet trip-index, and post-merge Fleet playback contribute once to the current $unique_bounded_tests/$unique_bounded_assertions total.",
    ),
    (
        "candidate-only CAP-FLEET-VEHICLE-REGISTER association, route ownership PENDING_FRESH_SEMANTIC_REVIEW, and explicit noninheritance for broader Fleet correctness",
        "candidate-only CAP-FLEET-VEHICLE-REGISTER association, index 84 route owner and action bridge integrated separately by RUN-180/R–181 with zero correctness inheritance, and explicit noninheritance for broader Fleet correctness",
    ),
    (
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, exact-artifact, and bounded Fleet evidence through RUN-180R, reported in RUN-181, with fresh RUN-182 dashboard verification required. Exactly two matrix rows have static benchmark-mapping credit. RUN-159, RUN-162, RUN-166, RUN-173, and RUN-176 retain separate evidence boundaries; only MED-RBAC $med_rbac_tests/$med_rbac_assertions, MED-CD-SCOPE $med_cd_tests/$med_cd_assertions, post-merge SAFE $safe_tests/$safe_assertions, and post-merge Fleet $fleet_tests/$fleet_assertions contribute once to the unchanged $unique_bounded_tests/$unique_bounded_assertions unique bounded total. RUN-179/R–181 add only one Fleet trip-index static route owner and bridge while preserving dissent, excluding the older bundle, and adding zero finding or correctness credit. SAFE and Fleet remediation remain local-main-only and unpublished. No lane grants inherited playback, broader Fleet or safeguarding correctness, page, consumer, caller, service, model, adjacent-route execution, runtime, browser, benchmark-final, final-finding, Pass, release, publication, feature-completion, Gate 4, or audit-completion credit.",
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, exact-artifact, and bounded Fleet evidence through RUN-183R, reported in RUN-184, with fresh RUN-185 dashboard verification required. Exactly two matrix rows have static benchmark-mapping credit. RUN-159, RUN-162, RUN-166, RUN-173, RUN-176, and RUN-183 retain separate evidence boundaries; only MED-RBAC $med_rbac_tests/$med_rbac_assertions, MED-CD-SCOPE $med_cd_tests/$med_cd_assertions, post-merge SAFE $safe_tests/$safe_assertions, post-merge Fleet trip-index $fleet_tests/$fleet_assertions, and post-merge Fleet playback $fleet_playback_tests/$fleet_playback_assertions contribute once to the current $unique_bounded_tests/$unique_bounded_assertions unique bounded total. RUN-179/R–181 add only one Fleet trip-index static route owner and bridge while preserving dissent, excluding the older bundle, and adding zero finding or correctness credit; RUN-183/R add page/data Site-privacy remediation evidence only and no static ownership credit. SAFE and both Fleet remediations remain local-main-only and unpublished. No lane grants inherited broader Fleet or safeguarding correctness, page, consumer, caller, service, model, adjacent-route execution, runtime, signed-in application-browser, benchmark-final, final-finding, Pass, release, publication, feature-completion, Gate 4, or audit-completion credit.",
    ),
    (
        "RUN-177: Fleet trip privacy historical-remediated record added · $finding_count provisional + $historical_fixed_count already-fixed + $historical_remediated_count remediated · $unique_bounded_tests/$unique_bounded_assertions unique bounded disposition total",
        "RUN-177: Fleet trip privacy historical-remediated record added · historical 8 provisional + 2 already-fixed + 3 remediated · RUN-176's post-merge Fleet 5/175 counted once into 88/1,764 · 88/1,764 unique bounded disposition total",
    ),
    (
        "<tr><td>RUN-177 live reporting</td><td><strong>$finding_count current provisional P1 + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated · $unique_bounded_tests tests / $unique_bounded_assertions assertions</strong>",
        "<tr><td>RUN-177 live reporting</td><td><strong>historical 8 current provisional P1 + 2 historical already-fixed + 3 historical remediated · historical total 88 / 1,764</strong>",
    ),
    (
        "<li>RUN-181: live static ledger reported as $static_owner_records owners / $static_owner_routes routes / $static_owner_pages pages / $static_action_bridges bridges · $queue_reviewed reviewed / $queue_pending pending / $queue_without_owner without ownership · dashboard HTML frozen pending RUN-182</li>",
        "<li>RUN-181: live static ledger reported as 666 owners / 309 routes / 357 pages / 97 bridges · 120 reviewed / 387 pending / 409 without ownership</li><li>RUN-182: exact RUN-181 dashboard verified at 4/4 viewports · 105/105 visible checks · 10/10 navigation · 455/455 unique local resources · 852 anchors · zero application credit</li><li>RUN-183: Fleet trip-playback page/data Site-privacy defect reproduced and remediated in exactly two transferred paths · post-merge $fleet_playback_tests/$fleet_playback_assertions uniquely counted · local main only · not published</li><li>RUN-183R: exact remediation artifacts independently reviewed GO · one new historical-remediated record authorized</li><li>RUN-184: Fleet trip-playback historical-remediated record added · $finding_count provisional + $historical_fixed_count already-fixed + $historical_remediated_count remediated · $unique_bounded_tests/$unique_bounded_assertions unique bounded disposition total · dashboard HTML frozen pending RUN-185</li>",
    ),
    ("RUN-178–181 Fleet trip-index route/action ownership checkpoint", "RUN-182–184 Fleet trip playback Site privacy remediation checkpoint"),
    (
        "<tr><td>RUN-181 live reporting</td><td><strong>$static_owner_records owners / $static_owner_routes routes / $static_owner_pages pages / $static_action_bridges bridges · $queue_reviewed reviewed / $queue_pending pending / $queue_without_owner without ownership</strong></td><td class=\"partial\">index 84 integrated · next index 85 RUN090-ROUTE-0086 / RUN077-ROUTE-0694 · dashboard HTML unchanged · fresh RUN-182 required</td></tr>",
        "<tr><td>RUN-181 live reporting</td><td><strong>666 owners / 309 routes / 357 pages / 97 bridges · 120 reviewed / 387 pending / 409 without ownership</strong></td><td class=\"partial\">index 84 integrated · next index 85 RUN090-ROUTE-0086 / RUN077-ROUTE-0694 · exact dashboard later verified by RUN-182</td></tr><tr><td>RUN-182 exact dashboard verification</td><td><strong>4/4 viewports · 105/105 visible checks · 10/10 navigation · 455/455 unique local resources · 852 anchors</strong></td><td class=\"partial\">exact superseded RUN-181 audit artifact only · zero application credit</td></tr><tr><td>RUN-183/R Fleet playback remediation and review</td><td><strong>baseline $fleet_playback_red_failed failed + $fleet_playback_red_passed passed / $fleet_playback_red_assertions assertions · post-merge $fleet_playback_tests passed / $fleet_playback_assertions assertions · supporting $fleet_playback_supporting_tests/$fleet_playback_supporting_assertions separate · baseline $fleet_playback_baseline_short · fix $fleet_playback_fix_short · exact two-path merge $fleet_playback_merge_short</strong></td><td class=\"partial\">page/data Site privacy only · independent GO · local main not published · index 85 ownership pending</td></tr><tr><td>RUN-184 live reporting</td><td><strong>$finding_count current provisional P1 + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated · $unique_bounded_tests tests / $unique_bounded_assertions assertions</strong></td><td class=\"partial\">one new Fleet playback historical-remediated record · zero final finding · dashboard HTML unchanged · fresh RUN-185 required</td></tr>",
    ),
    ("Fresh RUN-182 audit-dashboard verification required", "Fresh RUN-185 audit-dashboard verification required"),
    (
        "The exact RUN-181 reporting dashboard must be generated and checked in RUN-182 at 1440×900, 1280×800, 1024×768, and 390×844.",
        "The exact RUN-184 reporting dashboard must be generated and checked in RUN-185 at 1440×900, 1280×800, 1024×768, and 390×844.",
    ),
    (
        "RUN-178 verifies only the superseded RUN-177 HTML; RUN-179/R establish one Fleet trip-index route/action decision while preserving dissent and excluding the older bundle, RUN-180 integrates one owner/bridge, RUN-180R's complete synthesis authorizes reporting only, and RUN-181 alone changes live ownership/queue reporting while preserving the verified RUN-178 HTML byte-for-byte. None supplies audit-dashboard verification for the new RUN-182 HTML.",
        "RUN-182 verifies only the superseded RUN-181 HTML; RUN-183 establishes the bounded Fleet playback page/data remediation and local integration, RUN-183R independently authorizes one new historical-remediated record, and RUN-184 alone changes the live register and reporting sources while preserving the verified RUN-182 HTML byte-for-byte. None supplies audit-dashboard verification for the new RUN-185 HTML.",
    ),
    ("The linked RUN-182 receipt must record", "The linked RUN-185 receipt must record"),
    ("It verifies the RUN-181 audit artifact only", "It verifies the RUN-184 audit artifact only"),
    (
        '<a href="evidence/browser/current-audit-dashboard-verification-run-182-wave-34.json">RUN-182 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)',
        '<a href="evidence/browser/current-audit-dashboard-verification-run-185-wave-35.json">RUN-185 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)',
    ),
    ("RUN-071–181 evidence lineage", "RUN-071–184 evidence lineage"),
    (
        "Every current raw, generated, reviewed, and integrated RUN-077–180R source/reporting/runtime/benchmark/remediation/ownership artifact is linked with its exact SHA-256; RUN-181 is the current reporting generator execution and RUN-182 remains the fresh exact-dashboard gate.",
        "Every current raw, generated, reviewed, and integrated RUN-077–183R source/reporting/runtime/benchmark/remediation/ownership artifact is linked with its exact SHA-256; RUN-184 is the current reporting generator execution and RUN-185 remains the fresh exact-dashboard gate.",
    ),
    (
        "The current RUN-177 register retains 13 identities: $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated; FLEET-TRIP-INDEX-SITE-PRIVACY-01 is a new historical-remediated record with candidate-only feature association and zero static-ownership credit.",
        "The current RUN-184 register retains 14 identities: $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated; FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01 is the new historical-remediated record with candidate-only feature association and zero static-ownership credit.",
    ),
    (
        "<div class=\"card\"><strong class=\"partial\">$finding_count</strong><span>current provisional P1 claims</span><small>$historical_fixed_count historical already-fixed · $historical_remediated_count historical remediated · 13 retained · none final</small></div><div class=\"card\"><strong>$unique_bounded_tests / $unique_bounded_assertions</strong><span>unique bounded disposition evidence</span><small>$med_rbac_tests/$med_rbac_assertions MED-RBAC + $med_cd_tests/$med_cd_assertions MED-CD-SCOPE + $safe_tests/$safe_assertions SAFE + $fleet_tests/$fleet_assertions Fleet; atomicity and support remain separate</small></div>",
        "<div class=\"card\"><strong class=\"partial\">$finding_count</strong><span>current provisional P1 claims</span><small>$historical_fixed_count historical already-fixed · $historical_remediated_count historical remediated · 14 retained · none final</small></div><div class=\"card\"><strong>$unique_bounded_tests / $unique_bounded_assertions</strong><span>unique bounded disposition evidence</span><small>$med_rbac_tests/$med_rbac_assertions MED-RBAC + $med_cd_tests/$med_cd_assertions MED-CD-SCOPE + $safe_tests/$safe_assertions SAFE + $fleet_tests/$fleet_assertions Fleet index + $fleet_playback_tests/$fleet_playback_assertions Fleet playback; atomicity and support remain separate</small></div>",
    ),
    (
        "The register retains 13 historical claim identities: $finding_count remain current provisional P1 claims, $historical_fixed_count are historical already-fixed records on current main, and $historical_remediated_count are historical remediated records. SAFE-ALERT-DEDUP-IDENTITY-01 and FLEET-TRIP-INDEX-SITE-PRIVACY-01 are remediated on local main only and are not published to origin/main; the Fleet CAP association remains candidate-only and route ownership is PENDING_FRESH_SEMANTIC_REVIEW. None is a final finding or closed completion gate.",
        "The register retains 14 historical claim identities: $finding_count remain current provisional P1 claims, $historical_fixed_count are historical already-fixed records on current main, and $historical_remediated_count are historical remediated records. SAFE-ALERT-DEDUP-IDENTITY-01, FLEET-TRIP-INDEX-SITE-PRIVACY-01, and FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01 are remediated on local main only and are not published to origin/main; the playback CAP association remains candidate-only and index 85 ownership is PENDING_FRESH_SEMANTIC_REVIEW. None is a final finding or closed completion gate.",
    ),
    (
        "RUN-159 establishes bounded MED-RBAC execution; RUN-162 separately establishes focused MED-CD-SCOPE remediation execution; RUN-166 separately establishes bounded manual-entry MED-CD-ATOMICITY execution; RUN-173 adds one post-merge $safe_tests/$safe_assertions SAFE focused execution and RUN-176 adds one post-merge $fleet_tests/$fleet_assertions Fleet focused execution to the unique bounded total. Replays, supporting suites, adjacent filters, red failures, terminal-fixture failures, and atomicity remain separate or excluded, and none establishes full-suite, coverage, application-browser, ease, release, Pass, publication, or completion credit.",
        "RUN-159 establishes bounded MED-RBAC execution; RUN-162 separately establishes focused MED-CD-SCOPE remediation execution; RUN-166 separately establishes bounded manual-entry MED-CD-ATOMICITY execution; RUN-173 adds one post-merge $safe_tests/$safe_assertions SAFE focused execution, RUN-176 adds one post-merge $fleet_tests/$fleet_assertions Fleet index execution, and RUN-183 adds one post-merge $fleet_playback_tests/$fleet_playback_assertions Fleet playback execution to the unique bounded total. Replays, supporting suites, adjacent filters, red failures, terminal-fixture failures, and atomicity remain separate or excluded, and none establishes full-suite, coverage, application-browser, ease, release, Pass, publication, or completion credit.",
    ),
    (
        "</tr><tr><td>RUN-176 Fleet trip-index Site-privacy execution</td><td>$fleet_tests post-merge focused tests / $fleet_assertions assertions; supporting $fleet_supporting_tests/$fleet_supporting_assertions VehicleController regressions reported separately; root and expanded red executions and isolated green replay excluded</td><td class=\"partial\">selected GET/CSV rows, filters, aggregates, provenance, archived Sites, and nested identity only · static ownership PENDING_FRESH_SEMANTIC_REVIEW · full-suite green unproved</td></tr><tr><td>RUN-089 designated-application preflight</td>",
        "</tr><tr><td>RUN-176 Fleet trip-index Site-privacy execution</td><td>$fleet_tests post-merge focused tests / $fleet_assertions assertions; supporting $fleet_supporting_tests/$fleet_supporting_assertions VehicleController regressions reported separately; root and expanded red executions and isolated green replay excluded</td><td class=\"partial\">selected GET/CSV rows, filters, aggregates, provenance, archived Sites, and nested identity only · static ownership separate</td></tr><tr><td>RUN-183 Fleet trip-playback Site-privacy execution</td><td>$fleet_playback_tests post-merge focused tests / $fleet_playback_assertions assertions; baseline $fleet_playback_red_failed failed + $fleet_playback_red_passed passed / $fleet_playback_red_assertions assertions, isolated replay, and supporting $fleet_playback_supporting_tests/$fleet_playback_supporting_assertions regressions reported separately</td><td class=\"partial\">page/data approved-Site, provenance, concealment, driver privacy, and telemetry filtering only · index 85 ownership PENDING_FRESH_SEMANTIC_REVIEW · full-suite green unproved</td></tr><tr><td>RUN-089 designated-application preflight</td>",
    ),
    ("RUN-155–177 bounded disposition provenance, remediation, and reporting", "RUN-155–184 bounded disposition provenance, remediation, and reporting"),
    ("current RUN-177 split of 13 retained claim identities", "current RUN-184 split of 14 retained claim identities"),
    (
        "13 retained claim identities split into $finding_count current provisional P1, $historical_fixed_count historical already-fixed, and $historical_remediated_count historical remediated",
        "14 retained claim identities split into $finding_count current provisional P1, $historical_fixed_count historical already-fixed, and $historical_remediated_count historical remediated",
    ),
]
run_184_rewrite_expected_counts = {
    "current RUN-177 split of 13 retained claim identities": 2,
}
for old, new in run_184_template_rewrites:
    expected_count = run_184_rewrite_expected_counts.get(old, 1)
    assert current_template_text.count(old) == expected_count, (
        f"Expected {expected_count} RUN-184 template rewrite target(s): {old}"
    )
    current_template_text = current_template_text.replace(old, new)

run_187_template_rewrites = [
    ('<a href="#checkpoint">RUN-184</a>', '<a href="#checkpoint">RUN-187</a>'),
    ("RUN-001 through RUN-184 are represented by audit artifacts.", "RUN-001 through RUN-187 are represented by audit artifacts."),
    ("RUN-071–184 current reporting checkpoint", "RUN-071–187 current reporting checkpoint"),
    ("RUN-071–184 completion-gate checkpoint", "RUN-071–187 completion-gate checkpoint"),
    (
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, exact-artifact, and bounded Fleet evidence through RUN-183R, reported in RUN-184, with fresh RUN-185 dashboard verification required.",
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, exact-artifact, bounded Fleet evidence, RUN-185 exact-dashboard verification, and RUN-186/R Monitoring metric-replay evidence, reported in RUN-187, with fresh RUN-188 dashboard verification required.",
    ),
    (
        "RUN-145 grants exactly two target-specific static benchmark-mapping credits. Apart from separately bounded RUN-159 MED-RBAC, RUN-162 MED-CD-SCOPE, RUN-166 manual-entry MED-CD-ATOMICITY, RUN-173 post-merge SAFE, RUN-176 post-merge Fleet trip-index, and RUN-183 post-merge Fleet playback focused executions, no represented wave grants broader or full-suite application runtime or coverage; only MED-RBAC, MED-CD-SCOPE, post-merge SAFE, post-merge Fleet trip-index, and post-merge Fleet playback contribute once to the current $unique_bounded_tests/$unique_bounded_assertions total.",
        "RUN-145 grants exactly two target-specific static benchmark-mapping credits. Apart from separately bounded RUN-159 MED-RBAC, RUN-162 MED-CD-SCOPE, RUN-166 manual-entry MED-CD-ATOMICITY, RUN-173 post-merge SAFE, RUN-176 post-merge Fleet trip-index, RUN-183 post-merge Fleet playback, and RUN-186 final post-corrective-merge Monitoring metric-replay focused executions, no represented wave grants broader or full-suite application runtime or coverage; only MED-RBAC, MED-CD-SCOPE, post-merge SAFE, post-merge Fleet trip-index, post-merge Fleet playback, and the final post-corrective-merge Monitoring $metric_tests/$metric_assertions contribute once to the current $unique_bounded_tests/$unique_bounded_assertions total. The initial Monitoring $metric_initial_tests/$metric_initial_assertions and all Monitoring replays/subsets are excluded.",
    ),
    (
        "Exactly two matrix rows have static benchmark-mapping credit. RUN-159, RUN-162, RUN-166, RUN-173, RUN-176, and RUN-183 retain separate evidence boundaries; only MED-RBAC $med_rbac_tests/$med_rbac_assertions, MED-CD-SCOPE $med_cd_tests/$med_cd_assertions, post-merge SAFE $safe_tests/$safe_assertions, post-merge Fleet trip-index $fleet_tests/$fleet_assertions, and post-merge Fleet playback $fleet_playback_tests/$fleet_playback_assertions contribute once to the current $unique_bounded_tests/$unique_bounded_assertions unique bounded total.",
        "Exactly two matrix rows have static benchmark-mapping credit. RUN-159, RUN-162, RUN-166, RUN-173, RUN-176, RUN-183, and RUN-186/R retain separate evidence boundaries; only MED-RBAC $med_rbac_tests/$med_rbac_assertions, MED-CD-SCOPE $med_cd_tests/$med_cd_assertions, post-merge SAFE $safe_tests/$safe_assertions, post-merge Fleet trip-index $fleet_tests/$fleet_assertions, post-merge Fleet playback $fleet_playback_tests/$fleet_playback_assertions, and final post-corrective-merge Monitoring $metric_tests/$metric_assertions contribute once to the current $unique_bounded_tests/$unique_bounded_assertions unique bounded total. The initial Monitoring $metric_initial_tests/$metric_initial_assertions and every Monitoring replay/subset remain excluded.",
    ),
    (
        "RUN-159 establishes bounded MED-RBAC execution; RUN-162 separately establishes focused MED-CD-SCOPE remediation execution; RUN-166 separately establishes bounded manual-entry MED-CD-ATOMICITY execution; RUN-173 adds one post-merge $safe_tests/$safe_assertions SAFE focused execution, RUN-176 adds one post-merge $fleet_tests/$fleet_assertions Fleet index execution, and RUN-183 adds one post-merge $fleet_playback_tests/$fleet_playback_assertions Fleet playback execution to the unique bounded total. Replays, supporting suites, adjacent filters, red failures, terminal-fixture failures, and atomicity remain separate or excluded, and none establishes full-suite, coverage, application-browser, ease, release, Pass, publication, or completion credit.",
        "RUN-159 establishes bounded MED-RBAC execution; RUN-162 separately establishes focused MED-CD-SCOPE remediation execution; RUN-166 separately establishes bounded manual-entry MED-CD-ATOMICITY execution; RUN-173 adds one post-merge $safe_tests/$safe_assertions SAFE focused execution, RUN-176 adds one post-merge $fleet_tests/$fleet_assertions Fleet index execution, RUN-183 adds one post-merge $fleet_playback_tests/$fleet_playback_assertions Fleet playback execution, and RUN-186 adds only the final post-corrective-merge $metric_tests/$metric_assertions Monitoring metric-replay execution to the unique bounded total. The initial Monitoring $metric_initial_tests/$metric_initial_assertions, all Monitoring replays/subsets, supporting suites, adjacent filters, red failures, terminal-fixture failures, and atomicity remain separate or excluded, and none establishes full-suite, coverage, application-browser, ease, release, Pass, publication, or completion credit.",
    ),
    (
        "RUN-184: Fleet trip-playback historical-remediated record added · $finding_count provisional + $historical_fixed_count already-fixed + $historical_remediated_count remediated · $unique_bounded_tests/$unique_bounded_assertions unique bounded disposition total",
        "RUN-184: Fleet trip-playback historical-remediated record added · 8 provisional + 2 already-fixed + 4 remediated · 99/1,931 unique bounded disposition total",
    ),
    (
        "<tr><td>RUN-184 live reporting</td><td><strong>$finding_count current provisional P1 + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated · $unique_bounded_tests tests / $unique_bounded_assertions assertions</strong>",
        "<tr><td>RUN-184 live reporting</td><td><strong>8 current provisional P1 + 2 historical already-fixed + 4 historical remediated · 99 tests / 1,931 assertions</strong>",
    ),
    (
        "dashboard HTML frozen pending RUN-185</li>",
        "dashboard HTML later verified by RUN-185</li><li>RUN-185: exact RUN-184 dashboard verified at 4/4 viewports · 117/117 visible checks · 10/10 navigation · 463/463 resources · 868 anchors · 14 retained claim identities split into 8 current provisional P1, 2 historical already-fixed, and 4 historical remediated · zero application credit</li><li>RUN-186: MON-METRIC-REPLAY-DEDUPE-01 initial remediation later adjudicated NO-GO and corrective remediation integrated · lineage $metric_baseline_short → $metric_initial_fix_short → $metric_initial_merge_short → $metric_corrective_fix_short → $metric_corrective_merge_short; current local main $metric_current_main_short · only final post-corrective-merge $metric_tests/$metric_assertions counted once · initial $metric_initial_tests/$metric_initial_assertions and all replays/subsets/DNS/Facility excluded</li><li>RUN-186R: exact artifacts independently reviewed GO · null feature and candidate IDs · zero static ownership · option-A prerequisite: quiesce old monitoring workers; reconcile pending or incoherent rows; apply migration 000110; start new workers only after cutover reconciliation; poisoned subsecond evidence requires operator reconciliation · RUN-187 reporting authorized</li><li>RUN-187: Monitoring metric replay historical-remediated record added · $finding_count provisional + $historical_fixed_count already-fixed + $historical_remediated_count remediated · $unique_bounded_tests/$unique_bounded_assertions unique bounded disposition total · dashboard HTML frozen pending RUN-188</li>",
    ),
    (
        "RUN-182–184 Fleet trip playback Site privacy remediation checkpoint",
        "RUN-185–187 Monitoring metric replay remediation reporting checkpoint",
    ),
    (
        "fresh RUN-185 required</td></tr>",
        "exact dashboard later verified by RUN-185</td></tr><tr><td>RUN-185 exact dashboard verification</td><td><strong>4/4 viewports · 117/117 visible checks · 10/10 navigation · 463/463 resources · 868 anchors</strong></td><td class=\"partial\">exact superseded RUN-184 audit artifact only · zero application credit</td></tr><tr><td>RUN-186/R Monitoring metric replay remediation</td><td><strong>initial $metric_initial_tests/$metric_initial_assertions later NO-GO · final post-corrective-merge $metric_tests/$metric_assertions counted once · corrective merge $metric_corrective_merge_short</strong></td><td class=\"partial\">null feature identity · zero static ownership · option-A deployment prerequisite unverified · poisoned subsecond evidence needs operator reconciliation</td></tr><tr><td>RUN-187 live reporting</td><td><strong>$finding_count current provisional P1 + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated · $unique_bounded_tests tests / $unique_bounded_assertions assertions</strong></td><td class=\"partial\">one Monitoring historical-remediated record · zero final finding · dashboard HTML unchanged · fresh RUN-188 required</td></tr>",
    ),
    ("Fresh RUN-185 audit-dashboard verification required", "Fresh RUN-188 audit-dashboard verification required"),
    (
        "The exact RUN-184 reporting dashboard must be generated and checked in RUN-185 at 1440×900, 1280×800, 1024×768, and 390×844.",
        "The exact RUN-187 reporting dashboard must be generated and checked in RUN-188 at 1440×900, 1280×800, 1024×768, and 390×844.",
    ),
    ("The linked RUN-185 receipt must record", "The linked RUN-188 receipt must record"),
    ("It verifies the RUN-184 audit artifact only", "It verifies the RUN-187 audit artifact only"),
    (
        "RUN-184 is the current reporting generator execution and RUN-185 remains the fresh exact-dashboard gate.",
        "RUN-185 verifies only the superseded RUN-184 artifact; RUN-186/R establish the bounded Monitoring remediation/review evidence; RUN-187 is the current reporting generator execution and RUN-188 remains the fresh exact-dashboard gate.",
    ),
    (
        "RUN-182 verifies only the superseded RUN-181 HTML; RUN-183 establishes the bounded Fleet playback page/data remediation and local integration, RUN-183R independently authorizes one new historical-remediated record, and RUN-184 alone changes the live register and reporting sources while preserving the verified RUN-182 HTML byte-for-byte. None supplies audit-dashboard verification for the new RUN-185 HTML.",
        "RUN-182 verifies only the superseded RUN-181 HTML; RUN-183 establishes the bounded Fleet playback page/data remediation and local integration, RUN-183R independently authorizes one new historical-remediated record, and RUN-184 changes that live register while preserving the verified RUN-182 HTML byte-for-byte. RUN-185 then verifies only the superseded RUN-184 HTML; RUN-186 establishes the bounded corrective Monitoring metric-replay remediation and RUN-186R independently authorizes one new historical-remediated record; RUN-187 alone changes the live register and reporting sources while preserving the verified RUN-185 HTML byte-for-byte. None supplies audit-dashboard verification for the new RUN-188 HTML.",
    ),
    (
        '<a href="evidence/browser/current-audit-dashboard-verification-run-185-wave-35.json">RUN-185 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)',
        '<a href="evidence/browser/current-audit-dashboard-verification-run-185-wave-35.json">RUN-185 responsive audit-dashboard verification receipt</a> (verified and hashed in the current evidence list) · <a href="evidence/runtime/current-run-186-monitoring-metric-replay-dedupe-remediation-wave-36.json">RUN-186 Monitoring metric-replay remediation receipt</a> (verified and hashed in the current evidence list) · <a href="evidence/runtime/current-run-186r-independent-monitoring-metric-replay-dedupe-remediation-review-wave-36.json">RUN-186R independent Monitoring metric-replay review receipt</a> (verified and hashed in the current evidence list) · <a href="evidence/source/current-run-187-monitoring-metric-replay-dedupe-remediation-reporting-wave-36.json">RUN-187 Monitoring metric-replay reporting receipt</a> (verified and hashed in the current evidence list) · <a href="evidence/browser/current-audit-dashboard-verification-run-188-wave-36.json">RUN-188 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)',
    ),
    ("RUN-071–184 evidence lineage", "RUN-071–187 evidence lineage"),
    (
        "Every current raw, generated, reviewed, and integrated RUN-077–183R source/reporting/runtime/benchmark/remediation/ownership artifact is linked with its exact SHA-256;",
        "Every current raw, generated, reviewed, and integrated RUN-077–187 source/reporting/runtime/benchmark/remediation/ownership artifact is linked with its exact SHA-256;",
    ),
    (
        "SAFE-ALERT-DEDUP-IDENTITY-01, FLEET-TRIP-INDEX-SITE-PRIVACY-01, and FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01 are remediated on local main only and are not published to origin/main; the playback CAP association remains candidate-only and index 85 ownership is PENDING_FRESH_SEMANTIC_REVIEW. None is a final finding or closed completion gate.",
        "SAFE-ALERT-DEDUP-IDENTITY-01, FLEET-TRIP-INDEX-SITE-PRIVACY-01, and FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01 are remediated on local main only and are not published to origin/main; the playback CAP association remains candidate-only and index 85 ownership is PENDING_FRESH_SEMANTIC_REVIEW. MON-METRIC-REPLAY-DEDUPE-01 is also remediated on local main only and unpublished, has null feature and candidate IDs with UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW identity and zero static ownership, and retains the unverified option-A deployment prerequisite plus poisoned-subsecond operator-reconciliation boundary. None is a final finding or closed completion gate.",
    ),
    (
        "</tr><tr><td>RUN-183 Fleet trip-playback Site-privacy execution</td><td>$fleet_playback_tests post-merge focused tests / $fleet_playback_assertions assertions; baseline $fleet_playback_red_failed failed + $fleet_playback_red_passed passed / $fleet_playback_red_assertions assertions, isolated replay, and supporting $fleet_playback_supporting_tests/$fleet_playback_supporting_assertions regressions reported separately</td><td class=\"partial\">page/data approved-Site, provenance, concealment, driver privacy, and telemetry filtering only · index 85 ownership PENDING_FRESH_SEMANTIC_REVIEW · full-suite green unproved</td></tr><tr><td>RUN-089 designated-application preflight</td>",
        "</tr><tr><td>RUN-183 Fleet trip-playback Site-privacy execution</td><td>$fleet_playback_tests post-merge focused tests / $fleet_playback_assertions assertions; baseline $fleet_playback_red_failed failed + $fleet_playback_red_passed passed / $fleet_playback_red_assertions assertions, isolated replay, and supporting $fleet_playback_supporting_tests/$fleet_playback_supporting_assertions regressions reported separately</td><td class=\"partial\">page/data approved-Site, provenance, concealment, driver privacy, and telemetry filtering only · index 85 ownership PENDING_FRESH_SEMANTIC_REVIEW · full-suite green unproved</td></tr><tr><td>RUN-186 corrected Monitoring metric-replay execution</td><td>only final post-corrective-merge $metric_tests tests / $metric_assertions assertions counted once; initial $metric_initial_tests/$metric_initial_assertions, final isolated replay, red reproduction, intermediate subsets, stopped run, DNS, Facility, and overlapping support excluded</td><td class=\"partial\">null feature and candidate IDs · zero static ownership · option-A deployment prerequisite unverified · poisoned subsecond evidence requires operator reconciliation · no deployment, publication, full-suite, or completion credit</td></tr><tr><td>RUN-089 designated-application preflight</td>",
    ),
    (
        "The current RUN-184 register retains 14 identities: $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated; FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01 is the new historical-remediated record with candidate-only feature association and zero static-ownership credit.",
        "The current RUN-187 register retains 15 identities: $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated; MON-METRIC-REPLAY-DEDUPE-01 is the new historical-remediated record with null feature and candidate IDs, UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW identity, and zero static-ownership credit.",
    ),
    (
        "$historical_fixed_count historical already-fixed · $historical_remediated_count historical remediated · 14 retained · none final",
        "$historical_fixed_count historical already-fixed · $historical_remediated_count historical remediated · 15 retained · none final",
    ),
    (
        "$fleet_playback_tests/$fleet_playback_assertions Fleet playback; atomicity and support remain separate",
        "$fleet_playback_tests/$fleet_playback_assertions Fleet playback + $metric_tests/$metric_assertions final corrected Monitoring replay; initial $metric_initial_tests/$metric_initial_assertions and all other replay/support remain separate",
    ),
    (
        "The register retains 14 historical claim identities: $finding_count remain current provisional P1 claims, $historical_fixed_count are historical already-fixed records on current main, and $historical_remediated_count are historical remediated records.",
        "The register retains 15 claim identities: $finding_count remain current provisional P1 claims, $historical_fixed_count are historical already-fixed records on current main, and $historical_remediated_count are historical remediated records. MON-METRIC-REPLAY-DEDUPE-01 has null feature and candidate IDs, UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW identity, and zero static ownership.",
    ),
    ("RUN-155–184 bounded disposition provenance, remediation, and reporting", "RUN-155–187 bounded disposition provenance, remediation, and reporting"),
    ("current RUN-184 split of 14 retained claim identities", "current RUN-187 split of 15 retained claim identities"),
    (
        "14 retained claim identities split into $finding_count current provisional P1, $historical_fixed_count historical already-fixed, and $historical_remediated_count historical remediated",
        "15 retained claim identities split into $finding_count current provisional P1, $historical_fixed_count historical already-fixed, and $historical_remediated_count historical remediated",
    ),
]
for old, new in run_187_template_rewrites:
    assert old in current_template_text, f"Expected RUN-187 template rewrite target: {old}"
    current_template_text = current_template_text.replace(old, new)

run_191_template_rewrites = [
    ('<a href="#checkpoint">RUN-187</a>', '<a href="#checkpoint">RUN-191</a>'),
    (
        "RUN-185–187 Monitoring metric replay remediation reporting checkpoint",
        "RUN-188–191 Fleet playback route/action ownership reporting checkpoint",
    ),
    (
        "dashboard HTML frozen pending RUN-188</li>",
        "dashboard HTML later verified by RUN-188</li><li>RUN-188: exact RUN-187 dashboard verified at 4/4 viewports · 152/152 visible checks · 10/10 navigation · 471/471 resources · 888 anchors · zero application credit</li><li>RUN-189/R: index 85 fleet-assets.trips.playback / FleetTripController::show independently reviewed OWNER twice · no ownership materialized by the packet or review</li><li>RUN-190: exactly one playback/show route owner and controller-action bridge integrated · zero sibling data-route, page, remediation, correctness, runtime, benchmark, finding, or completion credit</li><li>RUN-190R: two sealed post-commit GO reviews · reporting only · next index 86 RUN090-ROUTE-0087 / RUN077-ROUTE-0695 · fleet-assets.trips.playback.data / FleetTripController::playback</li><li>RUN-191: live static ledger reported at $static_owner_records owners / $static_action_bridges bridges and $queue_reviewed reviewed / $queue_pending pending · dashboard HTML frozen pending RUN-192</li>",
    ),
    (
        "The current RUN-187 register retains 15 identities:",
        "The current RUN-191 register retains 15 identities:",
    ),
    (
        "current RUN-187 split of 15 retained claim identities",
        "current RUN-191 split of 15 retained claim identities",
    ),
    (
        "RUN-155–187 bounded disposition provenance, remediation, and reporting",
        "RUN-155–191 bounded disposition provenance, remediation, and reporting",
    ),
    (
        "index 85 ownership PENDING_FRESH_SEMANTIC_REVIEW · full-suite green unproved",
        "selected playback/show route owner and bridge integrated separately by RUN-190 · sibling playback.data at index 86 pending · full-suite green unproved",
    ),
    (
        "the playback CAP association remains candidate-only and index 85 ownership is PENDING_FRESH_SEMANTIC_REVIEW.",
        "the playback finding CAP association remains candidate-only; RUN-190 separately integrates only the selected playback/show route owner and bridge, while index 86 playback.data remains pending.",
    ),
    (
        "RUN-071–187 current reporting checkpoint",
        "RUN-071–191 current reporting checkpoint",
    ),
    (
        "RUN-071–187 completion-gate checkpoint",
        "RUN-071–191 completion-gate checkpoint",
    ),
    (
        "RUN-071–187 evidence lineage",
        "RUN-071–191 evidence lineage",
    ),
    (
        "Every current raw, generated, reviewed, and integrated RUN-077–187",
        "Every current raw, generated, reviewed, and integrated RUN-077–191",
    ),
    (
        "RUN-180/R current Fleet trip-index route/action ownership",
        "RUN-190/R current Fleet playback/show route/action ownership",
    ),
    (
        "RUN-090 frozen denominator / RUN-180R current accounting",
        "RUN-090 frozen denominator / RUN-190R current accounting",
    ),
    (
        "visible 666/309/357 ownership, 97 bridges, 120/387 queue accounting, 98 owned/409 without ownership",
        "visible 667/310/357 ownership, 98 bridges, 121/386 queue accounting, 99 owned/408 without ownership",
    ),
    (
        "Fleet trip-index wave 34 integrated = 1 owner · 1 route row + 1 bridge · 0 page rows · historical index 83 alerts-config owner not recredited · page, sibling, caller, neighbor, correctness, and downstream context not inherited · index 84 integrated · index 85 fleet-assets.trips.playback pending with RUN090-ROUTE-0086 / RUN077-ROUTE-0694 · RUN-179/R dissent and invalidated preliminary judgments preserved · RUN-180R three sealed GO lanes with zero discrepancies",
        "Fleet playback/show wave 37 integrated = 1 owner · 1 route row + 1 bridge · 0 page rows · historical indexes 83 alerts-config and 84 trip-index not recredited · sibling playback.data, page, remediation, correctness, runtime, benchmark, finding, and completion context not inherited · index 85 integrated · index 86 fleet-assets.trips.playback.data pending with RUN090-ROUTE-0087 / RUN077-ROUTE-0695 · RUN-189/R two independent OWNER reviews · RUN-190R two sealed post-commit GO reviews",
    ),
    (
        "RUN-169/R–170/R historically add one fleet-assets.vehicles.alerts-config owner and bridge, and RUN-179/R–181 later add one fleet-assets.trips.index owner and bridge to the current $static_owner_records bounded source-owner records and $static_action_bridges action bridges",
        "RUN-169/R–170/R historically add one fleet-assets.vehicles.alerts-config owner and bridge, RUN-179/R–181 later add one fleet-assets.trips.index owner and bridge, and RUN-189/R–190R add one fleet-assets.trips.playback / FleetTripController::show owner and bridge to the current $static_owner_records bounded source-owner records and $static_action_bridges action bridges",
    ),
    (
        "integrating indexes 83 and 84 while leaving index 85 fleet-assets.trips.playback pending with RUN090-ROUTE-0086 / RUN077-ROUTE-0694",
        "integrating indexes 83, 84, and 85 while leaving index 86 fleet-assets.trips.playback.data pending with RUN090-ROUTE-0087 / RUN077-ROUTE-0695",
    ),
    (
        "Fresh RUN-188 audit-dashboard verification required",
        "Fresh RUN-192 audit-dashboard verification required",
    ),
    (
        "The exact RUN-187 reporting dashboard must be generated and checked in RUN-188",
        "The exact RUN-191 reporting dashboard must be generated and checked in RUN-192",
    ),
    (
        "RUN-187 is the current reporting generator execution and RUN-188 remains the fresh exact-dashboard gate.",
        "RUN-188 verifies only the superseded RUN-187 dashboard; RUN-189/R establish the bounded playback/show decision without materializing ownership, RUN-190 integrates exactly one route owner and bridge, RUN-190R authorizes reporting only, RUN-191 is the current reporting transaction, and RUN-192 remains the fresh exact-dashboard gate.",
    ),
    (
        '<a href="evidence/browser/current-audit-dashboard-verification-run-188-wave-36.json">RUN-188 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)',
        '<a href="evidence/browser/current-audit-dashboard-verification-run-188-wave-36.json">RUN-188 responsive audit-dashboard verification receipt</a> (verified and hashed in the current evidence list) · <a href="evidence/source/current-run-190-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-wave-37.json">RUN-190 Fleet playback/show ownership-overlay receipt</a> (verified and hashed in the current evidence list) · <a href="evidence/source/current-run-190r-independent-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-review-wave-37.json">RUN-190R independent overlay-review receipt</a> (verified and hashed in the current evidence list) · <a href="evidence/source/current-run-191-reviewed-fleet-trip-playback-route-action-reporting-wave-37.json">RUN-191 reporting receipt</a> (verified and hashed in the current evidence list) · <a href="generators/materialize-run-192-audit-dashboard-verification-wave-37.py">RUN-192 audit-dashboard verification materializer</a> (forward reference; intentionally unhashed) · <a href="evidence/browser/current-audit-dashboard-verification-run-192-wave-37.json">RUN-192 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)',
    ),
]
for old, new in run_191_template_rewrites:
    assert old in current_template_text, f"Expected RUN-191 template rewrite target: {old}"
    current_template_text = current_template_text.replace(old, new)

run_194_template_rewrites = [
    ('<a href="#checkpoint">RUN-191</a>', '<a href="#checkpoint">RUN-194</a>'),
    (
        "RUN-188–191 Fleet playback route/action ownership reporting checkpoint",
        "RUN-192–194 Fleet Fuel index Site-privacy remediation reporting checkpoint",
    ),
    (
        "RUN-191: live static ledger reported at $static_owner_records owners / $static_action_bridges bridges and $queue_reviewed reviewed / $queue_pending pending · dashboard HTML frozen pending RUN-192</li>",
        "RUN-191: live static ledger reported at $static_owner_records owners / $static_action_bridges bridges and $queue_reviewed reviewed / $queue_pending pending · dashboard HTML later verified by RUN-192</li><li>RUN-192: exact RUN-191 dashboard verified at 4/4 viewports · 30/30 named visible checks per viewport · 10/10 navigation · 476/476 resources · 893 anchors · zero application credit</li><li>RUN-193: Fleet Fuel index/CSV Site-privacy defect reproduced and remediated in exactly two transferred paths · unique post-merge focused $fleet_fuel_tests/$fleet_fuel_assertions counted once · local main only · not published</li><li>RUN-193R: exact remediation artifacts independently reviewed GO · one new historical-remediated record authorized · zero static ownership or queue advance</li><li>RUN-194: Fuel historical-remediated record added · $finding_count provisional + $historical_fixed_count already-fixed + $historical_remediated_count remediated · $unique_bounded_tests/$unique_bounded_assertions unique bounded disposition total · dashboard HTML frozen pending RUN-195</li>",
    ),
    (
        "The current RUN-191 register retains 15 identities: $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated; MON-METRIC-REPLAY-DEDUPE-01 is the new historical-remediated record with null feature and candidate IDs, UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW identity, and zero static-ownership credit.",
        "The current RUN-194 register retains 16 identities: $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated; FLEET-FUEL-INDEX-SITE-PRIVACY-01 is the new historical-remediated record with candidate-only CAP-FLEET-VEHICLE-REGISTER association, pending index-87 ownership behind index 86 playback.data, and zero static-ownership credit.",
    ),
    (
        "The current RUN-191 register retains $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated.",
        "The current RUN-194 register retains $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated. FLEET-FUEL-INDEX-SITE-PRIVACY-01 adds only bounded selected Fuel GET-index/CSV Site-privacy remediation reporting; Fuel remains candidate index 87 with zero owner, bridge, page, or queue credit, while playback.data index 86 remains next.",
    ),
    (
        "$historical_fixed_count historical already-fixed · $historical_remediated_count historical remediated · 15 retained · none final",
        "$historical_fixed_count historical already-fixed · $historical_remediated_count historical remediated · 16 retained · none final",
    ),
    (
        "$fleet_playback_tests/$fleet_playback_assertions Fleet playback + $metric_tests/$metric_assertions final corrected Monitoring replay; initial $metric_initial_tests/$metric_initial_assertions and all other replay/support remain separate",
        "$fleet_playback_tests/$fleet_playback_assertions Fleet playback + $metric_tests/$metric_assertions final corrected Monitoring replay + $fleet_fuel_tests/$fleet_fuel_assertions Fleet Fuel; initial $metric_initial_tests/$metric_initial_assertions, Fuel red/replay/support, and all other replay/support remain separate",
    ),
    (
        "The register retains 15 claim identities: $finding_count remain current provisional P1 claims, $historical_fixed_count are historical already-fixed records on current main, and $historical_remediated_count are historical remediated records. MON-METRIC-REPLAY-DEDUPE-01 has null feature and candidate IDs, UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW identity, and zero static ownership.",
        "The register retains 16 claim identities: $finding_count remain current provisional P1 claims, $historical_fixed_count are historical already-fixed records on current main, and $historical_remediated_count are historical remediated records. MON-METRIC-REPLAY-DEDUPE-01 retains null feature and candidate IDs and zero static ownership. FLEET-FUEL-INDEX-SITE-PRIVACY-01 is candidate-only for CAP-FLEET-VEHICLE-REGISTER; row-attached logger identity follows foreign-row concealment only, with no independent logger-Site authorization for an otherwise visible row.",
    ),
    (
        "15 retained claim identities split into $finding_count current provisional P1, $historical_fixed_count historical already-fixed, and $historical_remediated_count historical remediated",
        "16 retained claim identities split into $finding_count current provisional P1, $historical_fixed_count historical already-fixed, and $historical_remediated_count historical remediated",
    ),
    (
        "RUN-155–191 bounded disposition provenance, remediation, and reporting",
        "RUN-155–194 bounded disposition provenance, remediation, and reporting",
    ),
    (
        "RUN-145 grants exactly two target-specific static benchmark-mapping credits. Apart from separately bounded RUN-159 MED-RBAC, RUN-162 MED-CD-SCOPE, RUN-166 manual-entry MED-CD-ATOMICITY, RUN-173 post-merge SAFE, RUN-176 post-merge Fleet trip-index, RUN-183 post-merge Fleet playback, and RUN-186 final post-corrective-merge Monitoring metric-replay focused executions, no represented wave grants broader or full-suite application runtime or coverage; only MED-RBAC, MED-CD-SCOPE, post-merge SAFE, post-merge Fleet trip-index, post-merge Fleet playback, and the final post-corrective-merge Monitoring $metric_tests/$metric_assertions contribute once to the current $unique_bounded_tests/$unique_bounded_assertions total. The initial Monitoring $metric_initial_tests/$metric_initial_assertions and all Monitoring replays/subsets are excluded.",
        "RUN-145 grants exactly two target-specific static benchmark-mapping credits. Apart from separately bounded RUN-159 MED-RBAC, RUN-162 MED-CD-SCOPE, RUN-166 manual-entry MED-CD-ATOMICITY, RUN-173 post-merge SAFE, RUN-176 post-merge Fleet trip-index, RUN-183 post-merge Fleet playback, RUN-186 final post-corrective-merge Monitoring metric-replay, and RUN-193 post-merge Fleet Fuel focused executions, no represented wave grants broader or full-suite application runtime or coverage; only MED-RBAC, MED-CD-SCOPE, post-merge SAFE, post-merge Fleet trip-index, post-merge Fleet playback, final post-corrective-merge Monitoring $metric_tests/$metric_assertions, and the unique post-merge Fuel $fleet_fuel_tests/$fleet_fuel_assertions component contribute once to the current $unique_bounded_tests/$unique_bounded_assertions total. The initial Monitoring $metric_initial_tests/$metric_initial_assertions, Fuel red $fleet_fuel_red_failed/$fleet_fuel_red_assertions, isolated Fuel replay, supporting $fleet_fuel_supporting_tests/$fleet_fuel_supporting_assertions, and any second count from combined 26/421 are excluded.",
    ),
    (
        "Exactly two matrix rows have static benchmark-mapping credit. RUN-159, RUN-162, RUN-166, RUN-173, RUN-176, RUN-183, and RUN-186/R retain separate evidence boundaries; only MED-RBAC $med_rbac_tests/$med_rbac_assertions, MED-CD-SCOPE $med_cd_tests/$med_cd_assertions, post-merge SAFE $safe_tests/$safe_assertions, post-merge Fleet trip-index $fleet_tests/$fleet_assertions, post-merge Fleet playback $fleet_playback_tests/$fleet_playback_assertions, and final post-corrective-merge Monitoring $metric_tests/$metric_assertions contribute once to the current $unique_bounded_tests/$unique_bounded_assertions unique bounded total. The initial Monitoring $metric_initial_tests/$metric_initial_assertions and every Monitoring replay/subset remain excluded.",
        "Exactly two matrix rows have static benchmark-mapping credit. RUN-159, RUN-162, RUN-166, RUN-173, RUN-176, RUN-183, RUN-186/R, and RUN-193/R retain separate evidence boundaries; only MED-RBAC $med_rbac_tests/$med_rbac_assertions, MED-CD-SCOPE $med_cd_tests/$med_cd_assertions, post-merge SAFE $safe_tests/$safe_assertions, post-merge Fleet trip-index $fleet_tests/$fleet_assertions, post-merge Fleet playback $fleet_playback_tests/$fleet_playback_assertions, final post-corrective-merge Monitoring $metric_tests/$metric_assertions, and the unique post-merge Fuel $fleet_fuel_tests/$fleet_fuel_assertions component contribute once to the current $unique_bounded_tests/$unique_bounded_assertions unique bounded total. The initial Monitoring $metric_initial_tests/$metric_initial_assertions, Fuel red/replay/support, and every other replay/subset remain excluded.",
    ),
    (
        "RUN-159 establishes bounded MED-RBAC execution; RUN-162 separately establishes focused MED-CD-SCOPE remediation execution; RUN-166 separately establishes bounded manual-entry MED-CD-ATOMICITY execution; RUN-173 adds one post-merge $safe_tests/$safe_assertions SAFE focused execution, RUN-176 adds one post-merge $fleet_tests/$fleet_assertions Fleet index execution, RUN-183 adds one post-merge $fleet_playback_tests/$fleet_playback_assertions Fleet playback execution, and RUN-186 adds only the final post-corrective-merge $metric_tests/$metric_assertions Monitoring metric-replay execution to the unique bounded total. The initial Monitoring $metric_initial_tests/$metric_initial_assertions, all Monitoring replays/subsets, supporting suites, adjacent filters, red failures, terminal-fixture failures, and atomicity remain separate or excluded, and none establishes full-suite, coverage, application-browser, ease, release, Pass, publication, or completion credit.",
        "RUN-159 establishes bounded MED-RBAC execution; RUN-162 separately establishes focused MED-CD-SCOPE remediation execution; RUN-166 separately establishes bounded manual-entry MED-CD-ATOMICITY execution; RUN-173 adds one post-merge $safe_tests/$safe_assertions SAFE focused execution, RUN-176 adds one post-merge $fleet_tests/$fleet_assertions Fleet index execution, RUN-183 adds one post-merge $fleet_playback_tests/$fleet_playback_assertions Fleet playback execution, RUN-186 adds only the final post-corrective-merge $metric_tests/$metric_assertions Monitoring metric-replay execution, and RUN-193 adds only the unique post-merge $fleet_fuel_tests/$fleet_fuel_assertions Fuel component to the unique bounded total. The initial Monitoring $metric_initial_tests/$metric_initial_assertions, all Monitoring replays/subsets, Fuel red/replay/support/combined-overlap, supporting suites, adjacent filters, red failures, terminal-fixture failures, and atomicity remain separate or excluded, and none establishes full-suite, coverage, application-browser, ease, release, Pass, publication, or completion credit.",
    ),
    (
        "</tr><tr><td>RUN-186 corrected Monitoring metric-replay execution</td><td>only final post-corrective-merge $metric_tests tests / $metric_assertions assertions counted once; initial $metric_initial_tests/$metric_initial_assertions, final isolated replay, red reproduction, intermediate subsets, stopped run, DNS, Facility, and overlapping support excluded</td><td class=\"partial\">null feature and candidate IDs · zero static ownership · option-A deployment prerequisite unverified · poisoned subsecond evidence requires operator reconciliation · no deployment, publication, full-suite, or completion credit</td></tr><tr><td>RUN-089 designated-application preflight</td>",
        "</tr><tr><td>RUN-186 corrected Monitoring metric-replay execution</td><td>only final post-corrective-merge $metric_tests tests / $metric_assertions assertions counted once; initial $metric_initial_tests/$metric_initial_assertions, final isolated replay, red reproduction, intermediate subsets, stopped run, DNS, Facility, and overlapping support excluded</td><td class=\"partial\">null feature and candidate IDs · zero static ownership · option-A deployment prerequisite unverified · poisoned subsecond evidence requires operator reconciliation · no deployment, publication, full-suite, or completion credit</td></tr><tr><td>RUN-193 Fleet Fuel index Site-privacy execution</td><td>$fleet_fuel_tests post-merge focused tests / $fleet_fuel_assertions assertions counted once; baseline $fleet_fuel_red_failed failed + $fleet_fuel_red_passed passed / $fleet_fuel_red_assertions assertions, isolated $fleet_fuel_tests/$fleet_fuel_assertions replay, supporting $fleet_fuel_supporting_tests/$fleet_fuel_supporting_assertions regressions, and any second count from combined 26/421 excluded</td><td class=\"partial\">selected GET index/CSV rows, filters, month-to-date totals, rolling 30-day entry count, efficiency inputs, provenance, archived Sites, and row-scoped attached identity only · candidate index 87 pending behind index 86 · baseline $fleet_fuel_baseline_short · fix $fleet_fuel_fix_short · local merge $fleet_fuel_merge_short · origin/main $fleet_fuel_origin_short unchanged · full-suite green unproved</td></tr><tr><td>RUN-089 designated-application preflight</td>",
    ),
    (
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, exact-artifact, bounded Fleet evidence, RUN-185 exact-dashboard verification, and RUN-186/R Monitoring metric-replay evidence, reported in RUN-187, with fresh RUN-188 dashboard verification required.",
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, exact-artifact, bounded Fleet evidence, RUN-192 exact-dashboard verification, and RUN-193/R Fleet Fuel evidence, reported in RUN-194, with fresh RUN-195 dashboard verification required.",
    ),
    ("RUN-001 through RUN-187 are represented by audit artifacts.", "RUN-001 through RUN-194 are represented by audit artifacts."),
    ("RUN-071–191 current reporting checkpoint", "RUN-071–194 current reporting checkpoint"),
    ("RUN-071–191 completion-gate checkpoint", "RUN-071–194 completion-gate checkpoint"),
    ("RUN-071–191 evidence lineage", "RUN-071–194 evidence lineage"),
    (
        "Every current raw, generated, reviewed, and integrated RUN-077–191",
        "Every current raw, generated, reviewed, and integrated RUN-077–194",
    ),
    ("current RUN-191 split of 15 retained claim identities", "current RUN-194 split of 16 retained claim identities"),
    ("Fresh RUN-192 audit-dashboard verification required", "Fresh RUN-195 audit-dashboard verification required"),
    (
        "The exact RUN-191 reporting dashboard must be generated and checked in RUN-192",
        "The exact RUN-194 reporting dashboard must be generated and checked in RUN-195",
    ),
    (
        "RUN-188 verifies only the superseded RUN-187 dashboard; RUN-189/R establish the bounded playback/show decision without materializing ownership, RUN-190 integrates exactly one route owner and bridge, RUN-190R authorizes reporting only, RUN-191 is the current reporting transaction, and RUN-192 remains the fresh exact-dashboard gate.",
        "RUN-188 verifies only the superseded RUN-187 dashboard; RUN-189/R–191 establish and report only the playback/show owner and bridge. RUN-192 verifies the exact RUN-191 dashboard; RUN-193 reproduces and locally integrates the bounded Fuel index/CSV Site-privacy remediation, RUN-193R authorizes one historical-remediated record, RUN-194 changes only live reporting while preserving the RUN-192 HTML, and RUN-195 remains the fresh exact-dashboard gate.",
    ),
    (
        '<a href="evidence/browser/current-audit-dashboard-verification-run-188-wave-36.json">RUN-188 responsive audit-dashboard verification receipt</a> (verified and hashed in the current evidence list) · <a href="evidence/source/current-run-190-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-wave-37.json">RUN-190 Fleet playback/show ownership-overlay receipt</a> (verified and hashed in the current evidence list) · <a href="evidence/source/current-run-190r-independent-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-review-wave-37.json">RUN-190R independent overlay-review receipt</a> (verified and hashed in the current evidence list) · <a href="evidence/source/current-run-191-reviewed-fleet-trip-playback-route-action-reporting-wave-37.json">RUN-191 reporting receipt</a> (verified and hashed in the current evidence list) · <a href="generators/materialize-run-192-audit-dashboard-verification-wave-37.py">RUN-192 audit-dashboard verification materializer</a> (forward reference; intentionally unhashed) · <a href="evidence/browser/current-audit-dashboard-verification-run-192-wave-37.json">RUN-192 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)',
        '<a href="evidence/browser/current-audit-dashboard-verification-run-188-wave-36.json">RUN-188 responsive audit-dashboard verification receipt</a> (verified and hashed in the current evidence list) · <a href="evidence/source/current-run-190-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-wave-37.json">RUN-190 Fleet playback/show ownership-overlay receipt</a> (verified and hashed in the current evidence list) · <a href="evidence/source/current-run-190r-independent-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-review-wave-37.json">RUN-190R independent overlay-review receipt</a> (verified and hashed in the current evidence list) · <a href="evidence/source/current-run-191-reviewed-fleet-trip-playback-route-action-reporting-wave-37.json">RUN-191 reporting receipt</a> (verified and hashed in the current evidence list) · <a href="generators/materialize-run-192-audit-dashboard-verification-wave-37.py">RUN-192 audit-dashboard verification materializer</a> (verified and hashed in the current evidence list) · <a href="evidence/browser/current-audit-dashboard-verification-run-192-wave-37.json">RUN-192 responsive audit-dashboard verification receipt</a> (verified and hashed in the current evidence list) · <a href="evidence/runtime/current-run-193-fleet-fuel-index-site-privacy-remediation-wave-38.json">RUN-193 Fleet Fuel remediation receipt</a> (verified and hashed in the current evidence list) · <a href="evidence/runtime/current-run-193r-independent-fleet-fuel-index-site-privacy-remediation-review-wave-38.json">RUN-193R independent Fuel review receipt</a> (verified and hashed in the current evidence list) · <a href="evidence/source/current-run-194-fleet-fuel-index-site-privacy-remediation-reporting-wave-38.json">RUN-194 reporting receipt</a> (verified and hashed in the current evidence list) · <a href="generators/materialize-run-195-audit-dashboard-verification-wave-38.py">RUN-195 audit-dashboard verification materializer</a> (forward reference; intentionally unhashed) · <a href="evidence/browser/current-audit-dashboard-verification-run-195-wave-38.json">RUN-195 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)',
    ),
    (
        "RUN-151, RUN-155, RUN-158, RUN-161, RUN-164, RUN-168, RUN-172, and RUN-175 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-177 reporting sources or the RUN-178 dashboard that will be generated from them.",
        "RUN-151, RUN-155, RUN-158, RUN-161, RUN-164, RUN-168, RUN-172, RUN-175, RUN-178, RUN-182, RUN-185, RUN-188, and RUN-192 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-194 reporting sources or the RUN-195 dashboard generated from them.",
    ),
    (
        '<li><a href="evidence/browser/current-audit-dashboard-verification-run-172-wave-31.json">Superseded RUN-172 verification GO</a></li></ul>',
        '<li><a href="evidence/browser/current-audit-dashboard-verification-run-172-wave-31.json">Superseded RUN-172 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-175-wave-32.json">Superseded RUN-175 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-178-wave-33.json">Superseded RUN-178 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-182-wave-34.json">Superseded RUN-182 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-185-wave-35.json">Superseded RUN-185 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-188-wave-36.json">Superseded RUN-188 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-192-wave-37.json">Superseded RUN-192 verification GO</a></li></ul>',
    ),
]
run_194_rewrite_expected_counts = {
    "current RUN-191 split of 15 retained claim identities": 2,
}
for old, new in run_194_template_rewrites:
    expected_count = run_194_rewrite_expected_counts.get(old, 1)
    assert current_template_text.count(old) == expected_count, (
        f"Expected {expected_count} RUN-194 template rewrite target(s): {old}"
    )
    current_template_text = current_template_text.replace(old, new)

fresh_run_195_section_start = (
    '<section class="panel"><h2>Fresh RUN-195 audit-dashboard verification required</h2><p>'
)
fresh_run_195_section_end = '</p><ul class="list">'
assert current_template_text.count(fresh_run_195_section_start) == 1
fresh_run_195_start_index = current_template_text.index(fresh_run_195_section_start)
fresh_run_195_body_start = fresh_run_195_start_index + len(fresh_run_195_section_start)
fresh_run_195_body_end = current_template_text.index(
    fresh_run_195_section_end,
    fresh_run_195_body_start,
)
fresh_run_195_body = (
    "The exact RUN-194 reporting dashboard must be generated and checked in RUN-195 at 1440×900, 1280×800, 1024×768, and 390×844. "
    "RUN-188 verifies only the superseded RUN-187 HTML; RUN-189/R–191 establish and report only the playback/show owner and bridge. "
    "RUN-192 verifies the exact RUN-191 HTML; RUN-193 reproduces and locally integrates the bounded Fleet Fuel index/CSV Site-privacy remediation, RUN-193R authorizes one historical-remediated record, and RUN-194 changes only live reporting while preserving the verified RUN-192 HTML byte-for-byte. "
    "None supplies audit-dashboard verification for the new RUN-195 HTML. "
    "The linked RUN-195 receipt must record page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 667/310/357 ownership, 98 bridges, 121/386 queue accounting, 99 owned/408 without ownership, 16 retained claim identities split into 8 current provisional P1, 2 historical already-fixed, and 6 historical remediated, 161/2,609 uniquely counted bounded tests/assertions, exact separation of RUN-159 MED-RBAC 73/1,481, RUN-162 MED-CD-SCOPE 5/48, RUN-173 SAFE 5/60, RUN-176 Fleet trip-index 5/175, RUN-183 Fleet playback 11/167, final corrected RUN-186 Monitoring metric replay 56/472, and RUN-193 Fleet Fuel 6/206, with all red/replay/support/combined overlaps excluded, current 2/340 benchmark mapping, 0/340 final no-match/NCM, 338 unresolved targets, one operating organisation across multiple Sites, Gate 4 open, and every non-bounded runtime, application-browser, final-finding, release, Pass, feature-completion, and audit-complete zero-credit boundary. "
    "It verifies the RUN-194 audit artifact only and grants no application-browser, responsive-application, visual, workflow, release, Pass, feature-completion, or audit-complete credit."
)
current_template_text = (
    current_template_text[:fresh_run_195_body_start]
    + fresh_run_195_body
    + current_template_text[fresh_run_195_body_end:]
)

run_197_template_rewrites = [
    ('<a href="#checkpoint">RUN-194</a>', '<a href="#checkpoint">RUN-197</a>'),
    (
        "RUN-192–194 Fleet Fuel index Site-privacy remediation reporting checkpoint",
        "RUN-195–197 Summary/timeline Site-privacy remediation reporting checkpoint",
    ),
    (
        "RUN-194: Fuel historical-remediated record added · $finding_count provisional + $historical_fixed_count already-fixed + $historical_remediated_count remediated · $unique_bounded_tests/$unique_bounded_assertions unique bounded disposition total · dashboard HTML frozen pending RUN-195</li>",
        "RUN-194: Fuel historical-remediated record added · historical 8 provisional + 2 already-fixed + 6 remediated · historical 161/2,609 unique bounded disposition total · dashboard HTML later verified by RUN-195</li><li>RUN-195: exact RUN-194 dashboard verified at 4/4 viewports · 39/39 named visible checks per viewport · 10/10 navigation · 491/491 resources · 944 anchors · zero application credit</li><li>RUN-196: Summary/timeline Site-privacy defect reproduced and remediated in exactly four transferred paths · unique focused $summary_tests/$summary_assertions counted once · local main only · not published</li><li>RUN-196R: exact remediation artifacts independently reviewed GO by three read-only reviewers · one feature-unassigned historical-remediated record authorized · zero static ownership or queue advance</li><li>RUN-197: Summary/timeline historical-remediated record added · $finding_count provisional + $historical_fixed_count already-fixed + $historical_remediated_count remediated · $unique_bounded_tests/$unique_bounded_assertions unique bounded disposition total · dashboard HTML frozen pending RUN-198</li>",
    ),
    (
        "The current RUN-194 register retains 16 identities: $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated; FLEET-FUEL-INDEX-SITE-PRIVACY-01 is the new historical-remediated record with candidate-only CAP-FLEET-VEHICLE-REGISTER association, pending index-87 ownership behind index 86 playback.data, and zero static-ownership credit.",
        "The current RUN-197 register retains 17 identities: $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated; SUMMARY-TIMELINE-SITE-PRIVACY-01 is the new historical-remediated record with null feature and candidate IDs, UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW identity, and zero static-ownership credit.",
    ),
    (
        "The current RUN-194 register retains $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated. FLEET-FUEL-INDEX-SITE-PRIVACY-01 adds only bounded selected Fuel GET-index/CSV Site-privacy remediation reporting; Fuel remains candidate index 87 with zero owner, bridge, page, or queue credit, while playback.data index 86 remains next.",
        "The current RUN-197 register retains $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated. SUMMARY-TIMELINE-SITE-PRIVACY-01 adds only bounded shared-Site staff Summary/timeline authorization and queued requester-revalidation reporting; feature identity remains unassigned with zero owner, bridge, page, or queue credit, while playback.data index 86 remains next.",
    ),
    (
        "$historical_fixed_count historical already-fixed · $historical_remediated_count historical remediated · 16 retained · none final",
        "$historical_fixed_count historical already-fixed · $historical_remediated_count historical remediated · 17 retained · none final",
    ),
    (
        "$fleet_playback_tests/$fleet_playback_assertions Fleet playback + $metric_tests/$metric_assertions final corrected Monitoring replay + $fleet_fuel_tests/$fleet_fuel_assertions Fleet Fuel; initial $metric_initial_tests/$metric_initial_assertions, Fuel red/replay/support, and all other replay/support remain separate",
        "$fleet_playback_tests/$fleet_playback_assertions Fleet playback + $metric_tests/$metric_assertions final corrected Monitoring replay + $fleet_fuel_tests/$fleet_fuel_assertions Fleet Fuel + $summary_tests/$summary_assertions Summary/timeline; initial $metric_initial_tests/$metric_initial_assertions, Fuel red/replay/support, Summary red/zero-assertion/$summary_supporting_tests/$summary_supporting_assertions support/$summary_shared_tests/$summary_shared_assertions shared execution, and all other replay/support remain separate",
    ),
    (
        "The register retains 16 claim identities: $finding_count remain current provisional P1 claims, $historical_fixed_count are historical already-fixed records on current main, and $historical_remediated_count are historical remediated records. MON-METRIC-REPLAY-DEDUPE-01 retains null feature and candidate IDs and zero static ownership. FLEET-FUEL-INDEX-SITE-PRIVACY-01 is candidate-only for CAP-FLEET-VEHICLE-REGISTER; row-attached logger identity follows foreign-row concealment only, with no independent logger-Site authorization for an otherwise visible row.",
        "The register retains 17 claim identities: $finding_count remain current provisional P1 claims, $historical_fixed_count are historical already-fixed records on current main, and $historical_remediated_count are historical remediated records. MON-METRIC-REPLAY-DEDUPE-01 and SUMMARY-TIMELINE-SITE-PRIVACY-01 retain null feature and candidate IDs and zero static ownership. FLEET-FUEL-INDEX-SITE-PRIVACY-01 remains candidate-only for CAP-FLEET-VEHICLE-REGISTER; row-attached logger identity follows foreign-row concealment only, with no independent logger-Site authorization for an otherwise visible row.",
    ),
    (
        "16 retained claim identities split into 8 current provisional P1, 2 historical already-fixed, and 6 historical remediated",
        "17 retained claim identities split into 8 current provisional P1, 2 historical already-fixed, and 7 historical remediated",
    ),
    (
        "RUN-155–194 bounded disposition provenance, remediation, and reporting",
        "RUN-155–197 bounded disposition provenance, remediation, and reporting",
    ),
    (
        "RUN-001 through RUN-194 are represented by audit artifacts.",
        "RUN-001 through RUN-197 are represented by audit artifacts.",
    ),
    ("RUN-071–194 current reporting checkpoint", "RUN-071–197 current reporting checkpoint"),
    ("RUN-071–194 completion-gate checkpoint", "RUN-071–197 completion-gate checkpoint"),
    ("RUN-071–194 evidence lineage", "RUN-071–197 evidence lineage"),
    (
        "Every current raw, generated, reviewed, and integrated RUN-077–194",
        "Every current raw, generated, reviewed, and integrated RUN-077–197",
    ),
    ("current RUN-194 split of 16 retained claim identities", "current RUN-197 split of 17 retained claim identities"),
    ("Fresh RUN-195 audit-dashboard verification required", "Fresh RUN-198 audit-dashboard verification required"),
    (
        "The exact RUN-194 reporting dashboard must be generated and checked in RUN-195",
        "The exact RUN-197 reporting dashboard must be generated and checked in RUN-198",
    ),
    (
        "RUN-188 verifies only the superseded RUN-187 dashboard; RUN-189/R–191 establish and report only the playback/show owner and bridge. RUN-192 verifies the exact RUN-191 dashboard; RUN-193 reproduces and locally integrates the bounded Fuel index/CSV Site-privacy remediation, RUN-193R authorizes one historical-remediated record, RUN-194 changes only live reporting while preserving the RUN-192 HTML, and RUN-195 remains the fresh exact-dashboard gate.",
        "RUN-188 verifies only the superseded RUN-187 dashboard; RUN-189/R–191 establish and report only the playback/show owner and bridge. RUN-192 verifies the exact RUN-191 dashboard; RUN-193 reproduces and locally integrates the bounded Fuel index/CSV Site-privacy remediation, RUN-193R authorizes one historical-remediated record, and RUN-194 changes only live reporting while preserving the RUN-192 HTML. RUN-195 verifies only the superseded RUN-194 dashboard; RUN-196/R establish and independently review the bounded Summary/timeline Site-privacy remediation, RUN-197 is the current reporting transaction, and RUN-198 remains the fresh exact-dashboard gate.",
    ),
    (
        "RUN-151, RUN-155, RUN-158, RUN-161, RUN-164, RUN-168, RUN-172, RUN-175, RUN-178, RUN-182, RUN-185, RUN-188, and RUN-192 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-194 reporting sources or the RUN-195 dashboard generated from them.",
        "RUN-151, RUN-155, RUN-158, RUN-161, RUN-164, RUN-168, RUN-172, RUN-175, RUN-178, RUN-182, RUN-185, RUN-188, RUN-192, and RUN-195 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-197 reporting sources or the RUN-198 dashboard generated from them.",
    ),
    (
        "RUN-188 verifies only the superseded RUN-187 HTML; RUN-189/R–191 establish and report only the playback/show owner and bridge. RUN-192 verifies the exact RUN-191 HTML; RUN-193 reproduces and locally integrates the bounded Fleet Fuel index/CSV Site-privacy remediation, RUN-193R authorizes one historical-remediated record, and RUN-194 changes only live reporting while preserving the verified RUN-192 HTML byte-for-byte. None supplies audit-dashboard verification for the new RUN-195 HTML.",
        "RUN-192 verifies only the superseded RUN-191 HTML; RUN-193/R establish the bounded Fleet Fuel remediation and RUN-194 reports it. RUN-195 verifies the exact RUN-194 HTML; RUN-196 reproduces and locally integrates the bounded Summary/timeline Site-privacy remediation, RUN-196R authorizes one feature-unassigned historical-remediated record, and RUN-197 changes only live reporting while preserving the verified RUN-195 HTML byte-for-byte. None supplies audit-dashboard verification for the new RUN-198 HTML.",
    ),
    (
        "It verifies the RUN-194 audit artifact only",
        "It verifies the RUN-197 audit artifact only",
    ),
    (
        "RUN-195 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)",
        "RUN-195 responsive audit-dashboard verification receipt</a> (verified and hashed in the current evidence list) · <a href=\"evidence/runtime/current-run-196-summary-timeline-site-privacy-remediation-wave-39.json\">RUN-196 Summary/timeline remediation receipt</a> (verified and hashed in the current evidence list) · <a href=\"evidence/runtime/current-run-196r-independent-summary-timeline-site-privacy-remediation-review-wave-39.json\">RUN-196R independent Summary/timeline review receipt</a> (verified and hashed in the current evidence list) · <a href=\"evidence/source/current-run-197-summary-timeline-site-privacy-remediation-reporting-wave-39.json\">RUN-197 reporting receipt</a> (verified and hashed in the current evidence list) · <a href=\"evidence/browser/current-audit-dashboard-verification-run-198-wave-39.json\">RUN-198 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)",
    ),
    (
        "RUN-195 audit-dashboard verification materializer</a> (forward reference; intentionally unhashed)",
        "RUN-195 audit-dashboard verification materializer</a> (verified and hashed in the current evidence list) · <a href=\"generators/materialize-run-196-summary-timeline-site-privacy-remediation-wave-39.py\">RUN-196 Summary/timeline remediation materializer</a> (verified and hashed in the current evidence list) · <a href=\"generators/materialize-independent-run-196-summary-timeline-site-privacy-remediation-review-wave-39.py\">RUN-196R independent Summary/timeline review materializer</a> (verified and hashed in the current evidence list) · <a href=\"generators/materialize-run-197-summary-timeline-site-privacy-remediation-reporting-wave-39.py\">RUN-197 reporting materializer</a> (verified and hashed in the current evidence list) · <a href=\"generators/materialize-run-198-audit-dashboard-verification-wave-39.py\">RUN-198 audit-dashboard verification materializer</a> (forward reference; intentionally unhashed)",
    ),
    (
        '<li><a href="evidence/browser/current-audit-dashboard-verification-run-192-wave-37.json">Superseded RUN-192 verification GO</a></li></ul>',
        '<li><a href="evidence/browser/current-audit-dashboard-verification-run-192-wave-37.json">Superseded RUN-192 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-195-wave-38.json">Superseded RUN-195 verification GO</a></li></ul>',
    ),
    (
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, exact-artifact, bounded Fleet evidence, RUN-192 exact-dashboard verification, and RUN-193/R Fleet Fuel evidence, reported in RUN-194, with fresh RUN-195 dashboard verification required.",
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, exact-artifact, bounded Fleet evidence, RUN-195 exact-dashboard verification, and RUN-196/R Summary/timeline evidence, reported in RUN-197, with fresh RUN-198 dashboard verification required.",
    ),
    (
        "RUN-159, RUN-162, RUN-166, RUN-173, RUN-176, RUN-183, RUN-186/R, and RUN-193/R retain separate evidence boundaries; only MED-RBAC $med_rbac_tests/$med_rbac_assertions, MED-CD-SCOPE $med_cd_tests/$med_cd_assertions, post-merge SAFE $safe_tests/$safe_assertions, post-merge Fleet trip-index $fleet_tests/$fleet_assertions, post-merge Fleet playback $fleet_playback_tests/$fleet_playback_assertions, final post-corrective-merge Monitoring $metric_tests/$metric_assertions, and the unique post-merge Fuel $fleet_fuel_tests/$fleet_fuel_assertions component contribute once to the current $unique_bounded_tests/$unique_bounded_assertions unique bounded total. The initial Monitoring $metric_initial_tests/$metric_initial_assertions, Fuel red/replay/support, and every other replay/subset remain excluded.",
        "RUN-159, RUN-162, RUN-166, RUN-173, RUN-176, RUN-183, RUN-186/R, RUN-193/R, and RUN-196/R retain separate evidence boundaries; only MED-RBAC $med_rbac_tests/$med_rbac_assertions, MED-CD-SCOPE $med_cd_tests/$med_cd_assertions, post-merge SAFE $safe_tests/$safe_assertions, post-merge Fleet trip-index $fleet_tests/$fleet_assertions, post-merge Fleet playback $fleet_playback_tests/$fleet_playback_assertions, final post-corrective-merge Monitoring $metric_tests/$metric_assertions, unique post-merge Fuel $fleet_fuel_tests/$fleet_fuel_assertions, and focused Summary/timeline $summary_tests/$summary_assertions contribute once to the current $unique_bounded_tests/$unique_bounded_assertions unique bounded total. The initial Monitoring $metric_initial_tests/$metric_initial_assertions, Fuel red/replay/support, Summary red/zero-assertion/$summary_supporting_tests/$summary_supporting_assertions support/$summary_shared_tests/$summary_shared_assertions shared execution, and every other replay/subset remain excluded.",
    ),
    (
        "</tr><tr><td>RUN-193 Fleet Fuel index Site-privacy execution</td><td>$fleet_fuel_tests post-merge focused tests / $fleet_fuel_assertions assertions counted once; baseline $fleet_fuel_red_failed failed + $fleet_fuel_red_passed passed / $fleet_fuel_red_assertions assertions, isolated $fleet_fuel_tests/$fleet_fuel_assertions replay, supporting $fleet_fuel_supporting_tests/$fleet_fuel_supporting_assertions regressions, and any second count from combined 26/421 excluded</td><td class=\"partial\">selected GET index/CSV rows, filters, month-to-date totals, rolling 30-day entry count, efficiency inputs, provenance, archived Sites, and row-scoped attached identity only · candidate index 87 pending behind index 86 · baseline $fleet_fuel_baseline_short · fix $fleet_fuel_fix_short · local merge $fleet_fuel_merge_short · origin/main $fleet_fuel_origin_short unchanged · full-suite green unproved</td></tr><tr><td>RUN-089 designated-application preflight</td>",
        "</tr><tr><td>RUN-193 Fleet Fuel index Site-privacy execution</td><td>$fleet_fuel_tests post-merge focused tests / $fleet_fuel_assertions assertions counted once; baseline $fleet_fuel_red_failed failed + $fleet_fuel_red_passed passed / $fleet_fuel_red_assertions assertions, isolated $fleet_fuel_tests/$fleet_fuel_assertions replay, supporting $fleet_fuel_supporting_tests/$fleet_fuel_supporting_assertions regressions, and any second count from combined 26/421 excluded</td><td class=\"partial\">selected GET index/CSV rows, filters, month-to-date totals, rolling 30-day entry count, efficiency inputs, provenance, archived Sites, and row-scoped attached identity only · candidate index 87 pending behind index 86 · baseline $fleet_fuel_baseline_short · fix $fleet_fuel_fix_short · local merge $fleet_fuel_merge_short · origin/main $fleet_fuel_origin_short unchanged · full-suite green unproved</td></tr><tr><td>RUN-196 Summary/timeline Site-privacy execution</td><td>$summary_tests focused tests / $summary_assertions assertions counted once; baseline $summary_red_failed failed + $summary_red_passed passed / $summary_red_assertions assertions, zero-assertion vendor-junction attempt, eMAR $summary_supporting_tests/$summary_supporting_assertions support, shared post-merge $summary_shared_tests/$summary_shared_assertions, and wrong-path pre-test invocation excluded</td><td class=\"partial\">self and existing actions preserved · other-staff reads require active shared-Site scope unless hr.employees.viewAllSites · client Gate unchanged · queued requester revalidated before protected reads/writes · feature unassigned · baseline $summary_baseline_short · fix $summary_fix_short · local merge $summary_merge_short · current main $summary_current_main_short · origin/main $summary_origin_short unchanged</td></tr><tr><td>RUN-089 designated-application preflight</td>",
    ),
]
run_197_rewrite_expected_counts = {
    "current RUN-194 split of 16 retained claim identities": 2,
}
for old, new in run_197_template_rewrites:
    expected_count = run_197_rewrite_expected_counts.get(old, 1)
    assert current_template_text.count(old) == expected_count, (
        f"Expected {expected_count} RUN-197 template rewrite target(s): {old}"
    )
    current_template_text = current_template_text.replace(old, new)

fresh_run_198_section_start = (
    '<section class="panel"><h2>Fresh RUN-198 audit-dashboard verification required</h2><p>'
)
fresh_run_198_section_end = '</p><ul class="list">'
assert current_template_text.count(fresh_run_198_section_start) == 1
fresh_run_198_start_index = current_template_text.index(fresh_run_198_section_start)
fresh_run_198_body_start = fresh_run_198_start_index + len(fresh_run_198_section_start)
fresh_run_198_body_end = current_template_text.index(
    fresh_run_198_section_end,
    fresh_run_198_body_start,
)
fresh_run_198_body = (
    "The exact RUN-197 reporting dashboard must be generated and checked in RUN-198 at 1440×900, 1280×800, 1024×768, and 390×844. "
    "RUN-195 verifies only the superseded RUN-194 HTML; RUN-196 reproduces and locally integrates the bounded Summary/timeline Site-privacy remediation, RUN-196R authorizes one feature-unassigned historical-remediated record, and RUN-197 changes only live reporting while preserving the verified RUN-195 HTML byte-for-byte. "
    "None supplies audit-dashboard verification for the new RUN-198 HTML. "
    "The linked RUN-198 receipt must record page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 667/310/357 ownership, 98 bridges, 121/386 queue accounting, 99 owned/408 without ownership, 17 retained claim identities split into 8 current provisional P1, 2 historical already-fixed, and 7 historical remediated, 176/2,641 uniquely counted bounded tests/assertions, focused Summary/timeline 15/32 counted once, all Summary red/zero-assertion/2/238 support/40/438 shared executions excluded, current 2/340 benchmark mapping, 0/340 final no-match/NCM, 338 unresolved targets, one operating organisation across multiple Sites, Gate 4 open, and every non-bounded runtime, application-browser, final-finding, release, Pass, feature-completion, and audit-complete zero-credit boundary. "
    "It verifies the RUN-197 audit artifact only and grants no application-browser, responsive-application, visual, workflow, release, Pass, feature-completion, or audit-complete credit."
)
current_template_text = (
    current_template_text[:fresh_run_198_body_start]
    + fresh_run_198_body
    + current_template_text[fresh_run_198_body_end:]
)

run_199_template_rewrites = [
    ('<a href="#checkpoint">RUN-197</a>', '<a href="#checkpoint">RUN-199</a>'),
    (
        "RUN-195–197 Summary/timeline Site-privacy remediation reporting checkpoint",
        "RUN-198–199 Shift-task due recipient-revalidation checkpoint",
    ),
    (
        "RUN-197: Summary/timeline historical-remediated record added · $finding_count provisional + $historical_fixed_count already-fixed + $historical_remediated_count remediated · $unique_bounded_tests/$unique_bounded_assertions unique bounded disposition total · dashboard HTML frozen pending RUN-198</li>",
        "RUN-197: Summary/timeline historical-remediated record added · historical 8 provisional + 2 already-fixed + 7 remediated · historical 176/2,641 unique bounded disposition total · dashboard HTML later verified by RUN-198</li><li>RUN-198: exact RUN-197 dashboard verified at 4/4 viewports · 48/48 named visible checks per viewport · 10/10 navigation · 499/499 resources · 969 anchors · zero application credit</li><li>RUN-199: Shift-task due recipient-revalidation reproduced and remediated in exactly four paths · one post-merge $shift_task_tests/$shift_task_assertions execution counted once · delegated coordination transcription, not an original runtime receipt · $finding_count provisional + $historical_fixed_count already-fixed + $historical_remediated_count remediated · $unique_bounded_tests/$unique_bounded_assertions unique bounded disposition total · dashboard HTML frozen pending RUN-200</li>",
    ),
    (
        "The current RUN-197 register retains 17 identities: $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated; SUMMARY-TIMELINE-SITE-PRIVACY-01 is the new historical-remediated record with null feature and candidate IDs, UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW identity, and zero static-ownership credit.",
        "The current RUN-199 register retains 18 identities: $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated; SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01 is the new historical-remediated record with null feature and candidate IDs, UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW identity, and zero static-ownership credit.",
    ),
    (
        "The current RUN-197 register retains $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated. SUMMARY-TIMELINE-SITE-PRIVACY-01 adds only bounded shared-Site staff Summary/timeline authorization and queued requester-revalidation reporting; feature identity remains unassigned with zero owner, bridge, page, or queue credit, while playback.data index 86 remains next.",
        "The current RUN-199 register retains $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated. SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01 adds only bounded scheduler-time and queued-delivery recipient revalidation reporting; feature identity remains unassigned with zero owner, bridge, page, or queue credit, while playback.data index 86 remains next.",
    ),
    (
        "$historical_fixed_count historical already-fixed · $historical_remediated_count historical remediated · 17 retained · none final",
        "$historical_fixed_count historical already-fixed · $historical_remediated_count historical remediated · 18 retained · none final",
    ),
    (
        "$fleet_playback_tests/$fleet_playback_assertions Fleet playback + $metric_tests/$metric_assertions final corrected Monitoring replay + $fleet_fuel_tests/$fleet_fuel_assertions Fleet Fuel + $summary_tests/$summary_assertions Summary/timeline; initial $metric_initial_tests/$metric_initial_assertions, Fuel red/replay/support, Summary red/zero-assertion/$summary_supporting_tests/$summary_supporting_assertions support/$summary_shared_tests/$summary_shared_assertions shared execution, and all other replay/support remain separate",
        "$fleet_playback_tests/$fleet_playback_assertions Fleet playback + $metric_tests/$metric_assertions final corrected Monitoring replay + $fleet_fuel_tests/$fleet_fuel_assertions Fleet Fuel + $summary_tests/$summary_assertions Summary/timeline + $shift_task_tests/$shift_task_assertions Shift-task; initial $metric_initial_tests/$metric_initial_assertions, Fuel red/replay/support, Summary red/zero-assertion/$summary_supporting_tests/$summary_supporting_assertions support/$summary_shared_tests/$summary_shared_assertions shared execution, Shift-task red/intermediate/cache/isolated replay/duplicate post-merge execution, and all other replay/support remain separate",
    ),
    (
        "The register retains 17 claim identities: $finding_count remain current provisional P1 claims, $historical_fixed_count are historical already-fixed records on current main, and $historical_remediated_count are historical remediated records. MON-METRIC-REPLAY-DEDUPE-01 and SUMMARY-TIMELINE-SITE-PRIVACY-01 retain null feature and candidate IDs and zero static ownership. FLEET-FUEL-INDEX-SITE-PRIVACY-01 remains candidate-only for CAP-FLEET-VEHICLE-REGISTER; row-attached logger identity follows foreign-row concealment only, with no independent logger-Site authorization for an otherwise visible row.",
        "The register retains 18 claim identities: $finding_count remain current provisional P1 claims, $historical_fixed_count are historical already-fixed records on current main, and $historical_remediated_count are historical remediated records. MON-METRIC-REPLAY-DEDUPE-01, SUMMARY-TIMELINE-SITE-PRIVACY-01, and SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01 retain null feature and candidate IDs and zero static ownership. FLEET-FUEL-INDEX-SITE-PRIVACY-01 remains candidate-only for CAP-FLEET-VEHICLE-REGISTER; row-attached logger identity follows foreign-row concealment only, with no independent logger-Site authorization for an otherwise visible row.",
    ),
    (
        "17 retained claim identities split into 8 current provisional P1, 2 historical already-fixed, and 7 historical remediated",
        "18 retained claim identities split into 8 current provisional P1, 2 historical already-fixed, and 8 historical remediated",
    ),
    ("RUN-155–197 bounded disposition provenance, remediation, and reporting", "RUN-155–199 bounded disposition provenance, remediation, and reporting"),
    ("RUN-001 through RUN-197 are represented by audit artifacts.", "RUN-001 through RUN-199 are represented by audit artifacts."),
    ("RUN-071–197 current reporting checkpoint", "RUN-071–199 current reporting checkpoint"),
    ("RUN-071–197 completion-gate checkpoint", "RUN-071–199 completion-gate checkpoint"),
    ("RUN-071–197 evidence lineage", "RUN-071–199 evidence lineage"),
    ("Every current raw, generated, reviewed, and integrated RUN-077–197", "Every current raw, generated, reviewed, and integrated RUN-077–199"),
    ("current RUN-197 split of 17 retained claim identities", "current RUN-199 split of 18 retained claim identities"),
    ("Fresh RUN-198 audit-dashboard verification required", "Fresh RUN-200 audit-dashboard verification required"),
    ("The exact RUN-197 reporting dashboard must be generated and checked in RUN-198", "The exact RUN-199 reporting dashboard must be generated and checked in RUN-200"),
    (
        "RUN-188 verifies only the superseded RUN-187 dashboard; RUN-189/R–191 establish and report only the playback/show owner and bridge. RUN-192 verifies the exact RUN-191 dashboard; RUN-193 reproduces and locally integrates the bounded Fuel index/CSV Site-privacy remediation, RUN-193R authorizes one historical-remediated record, and RUN-194 changes only live reporting while preserving the RUN-192 HTML. RUN-195 verifies only the superseded RUN-194 dashboard; RUN-196/R establish and independently review the bounded Summary/timeline Site-privacy remediation, RUN-197 is the current reporting transaction, and RUN-198 remains the fresh exact-dashboard gate.",
        "RUN-192 verifies only the superseded RUN-191 dashboard; RUN-193/R–194 establish and report the bounded Fuel remediation. RUN-195 verifies the exact RUN-194 dashboard; RUN-196/R–197 establish and report the bounded Summary/timeline remediation. RUN-198 verifies the exact RUN-197 dashboard; RUN-199 is the current Shift-task due recipient-revalidation reporting transaction, and RUN-200 remains the fresh exact-dashboard gate.",
    ),
    (
        "RUN-151, RUN-155, RUN-158, RUN-161, RUN-164, RUN-168, RUN-172, RUN-175, RUN-178, RUN-182, RUN-185, RUN-188, RUN-192, and RUN-195 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-197 reporting sources or the RUN-198 dashboard generated from them.",
        "RUN-151, RUN-155, RUN-158, RUN-161, RUN-164, RUN-168, RUN-172, RUN-175, RUN-178, RUN-182, RUN-185, RUN-188, RUN-192, RUN-195, and RUN-198 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-199 reporting sources or the RUN-200 dashboard generated from them.",
    ),
    ("It verifies the RUN-197 audit artifact only", "It verifies the RUN-199 audit artifact only"),
    (
        "RUN-195 responsive audit-dashboard verification receipt</a> (verified and hashed in the current evidence list) · <a href=\"evidence/runtime/current-run-196-summary-timeline-site-privacy-remediation-wave-39.json\">RUN-196 Summary/timeline remediation receipt</a> (verified and hashed in the current evidence list) · <a href=\"evidence/runtime/current-run-196r-independent-summary-timeline-site-privacy-remediation-review-wave-39.json\">RUN-196R independent Summary/timeline review receipt</a> (verified and hashed in the current evidence list) · <a href=\"evidence/source/current-run-197-summary-timeline-site-privacy-remediation-reporting-wave-39.json\">RUN-197 reporting receipt</a> (verified and hashed in the current evidence list) · <a href=\"evidence/browser/current-audit-dashboard-verification-run-198-wave-39.json\">RUN-198 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)",
        "RUN-195 responsive audit-dashboard verification receipt</a> (verified and hashed in the current evidence list) · <a href=\"evidence/runtime/current-run-196-summary-timeline-site-privacy-remediation-wave-39.json\">RUN-196 Summary/timeline remediation receipt</a> (verified and hashed in the current evidence list) · <a href=\"evidence/runtime/current-run-196r-independent-summary-timeline-site-privacy-remediation-review-wave-39.json\">RUN-196R independent Summary/timeline review receipt</a> (verified and hashed in the current evidence list) · <a href=\"evidence/source/current-run-197-summary-timeline-site-privacy-remediation-reporting-wave-39.json\">RUN-197 reporting receipt</a> (verified and hashed in the current evidence list) · <a href=\"evidence/browser/current-audit-dashboard-verification-run-198-wave-39.json\">RUN-198 responsive audit-dashboard verification receipt</a> (verified and hashed in the current evidence list) · <a href=\"evidence/source/current-run-199-shift-task-due-recipient-revalidation-coordination-handoff-wave-40.json\">RUN-199 Shift-task coordination-handoff transcription</a> (verified and hashed; not an original runtime receipt) · <a href=\"evidence/source/current-run-199-shift-task-due-recipient-revalidation-remediation-reporting-wave-40.json\">RUN-199 reporting receipt</a> (verified and hashed in the current evidence list) · <a href=\"evidence/browser/current-audit-dashboard-verification-run-200-wave-40.json\">RUN-200 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)",
    ),
    (
        "RUN-195 audit-dashboard verification materializer</a> (verified and hashed in the current evidence list) · <a href=\"generators/materialize-run-196-summary-timeline-site-privacy-remediation-wave-39.py\">RUN-196 Summary/timeline remediation materializer</a> (verified and hashed in the current evidence list) · <a href=\"generators/materialize-independent-run-196-summary-timeline-site-privacy-remediation-review-wave-39.py\">RUN-196R independent Summary/timeline review materializer</a> (verified and hashed in the current evidence list) · <a href=\"generators/materialize-run-197-summary-timeline-site-privacy-remediation-reporting-wave-39.py\">RUN-197 reporting materializer</a> (verified and hashed in the current evidence list) · <a href=\"generators/materialize-run-198-audit-dashboard-verification-wave-39.py\">RUN-198 audit-dashboard verification materializer</a> (forward reference; intentionally unhashed)",
        "RUN-195 audit-dashboard verification materializer</a> (verified and hashed in the current evidence list) · <a href=\"generators/materialize-run-196-summary-timeline-site-privacy-remediation-wave-39.py\">RUN-196 Summary/timeline remediation materializer</a> (verified and hashed in the current evidence list) · <a href=\"generators/materialize-independent-run-196-summary-timeline-site-privacy-remediation-review-wave-39.py\">RUN-196R independent Summary/timeline review materializer</a> (verified and hashed in the current evidence list) · <a href=\"generators/materialize-run-197-summary-timeline-site-privacy-remediation-reporting-wave-39.py\">RUN-197 reporting materializer</a> (verified and hashed in the current evidence list) · <a href=\"generators/materialize-run-198-audit-dashboard-verification-wave-39.py\">RUN-198 audit-dashboard verification materializer</a> (verified and hashed in the current evidence list) · <a href=\"generators/materialize-run-199-shift-task-due-recipient-revalidation-remediation-reporting-wave-40.py\">RUN-199 reporting materializer</a> (verified and hashed in the current evidence list) · <a href=\"generators/materialize-run-200-audit-dashboard-verification-wave-40.py\">RUN-200 audit-dashboard verification materializer</a> (forward reference; intentionally unhashed)",
    ),
    (
        '<li><a href="evidence/browser/current-audit-dashboard-verification-run-192-wave-37.json">Superseded RUN-192 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-195-wave-38.json">Superseded RUN-195 verification GO</a></li></ul>',
        '<li><a href="evidence/browser/current-audit-dashboard-verification-run-192-wave-37.json">Superseded RUN-192 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-195-wave-38.json">Superseded RUN-195 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-198-wave-39.json">Superseded RUN-198 verification GO</a></li></ul>',
    ),
    (
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, exact-artifact, bounded Fleet evidence, RUN-195 exact-dashboard verification, and RUN-196/R Summary/timeline evidence, reported in RUN-197, with fresh RUN-198 dashboard verification required.",
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, exact-artifact, bounded Fleet evidence, RUN-198 exact-dashboard verification, and bounded delegated Shift-task coordination evidence reported in RUN-199, with fresh RUN-200 dashboard verification required.",
    ),
    (
        "RUN-159, RUN-162, RUN-166, RUN-173, RUN-176, RUN-183, RUN-186/R, RUN-193/R, and RUN-196/R retain separate evidence boundaries; only MED-RBAC $med_rbac_tests/$med_rbac_assertions, MED-CD-SCOPE $med_cd_tests/$med_cd_assertions, post-merge SAFE $safe_tests/$safe_assertions, post-merge Fleet trip-index $fleet_tests/$fleet_assertions, post-merge Fleet playback $fleet_playback_tests/$fleet_playback_assertions, final post-corrective-merge Monitoring $metric_tests/$metric_assertions, unique post-merge Fuel $fleet_fuel_tests/$fleet_fuel_assertions, and focused Summary/timeline $summary_tests/$summary_assertions contribute once to the current $unique_bounded_tests/$unique_bounded_assertions unique bounded total. The initial Monitoring $metric_initial_tests/$metric_initial_assertions, Fuel red/replay/support, Summary red/zero-assertion/$summary_supporting_tests/$summary_supporting_assertions support/$summary_shared_tests/$summary_shared_assertions shared execution, and every other replay/subset remain excluded.",
        "RUN-159, RUN-162, RUN-166, RUN-173, RUN-176, RUN-183, RUN-186/R, RUN-193/R, RUN-196/R, and RUN-199 retain separate evidence boundaries; only MED-RBAC $med_rbac_tests/$med_rbac_assertions, MED-CD-SCOPE $med_cd_tests/$med_cd_assertions, post-merge SAFE $safe_tests/$safe_assertions, post-merge Fleet trip-index $fleet_tests/$fleet_assertions, post-merge Fleet playback $fleet_playback_tests/$fleet_playback_assertions, final post-corrective-merge Monitoring $metric_tests/$metric_assertions, unique post-merge Fuel $fleet_fuel_tests/$fleet_fuel_assertions, focused Summary/timeline $summary_tests/$summary_assertions, and one post-merge Shift-task $shift_task_tests/$shift_task_assertions execution contribute once to the current $unique_bounded_tests/$unique_bounded_assertions unique bounded total. The initial Monitoring $metric_initial_tests/$metric_initial_assertions, Fuel red/replay/support, Summary red/zero-assertion/$summary_supporting_tests/$summary_supporting_assertions support/$summary_shared_tests/$summary_shared_assertions shared execution, Shift-task red $shift_task_red_failed-failed/$shift_task_red_passed-passed/$shift_task_red_pending-pending/$shift_task_red_assertions-assertion reproduction, intermediate $shift_task_intermediate_tests/$shift_task_intermediate_assertions and cache proofs, isolated final $shift_task_tests/$shift_task_assertions replay, any duplicate post-merge count, and every other replay/subset remain excluded.",
    ),
    (
        "</tr><tr><td>RUN-196 Summary/timeline Site-privacy execution</td><td>$summary_tests focused tests / $summary_assertions assertions counted once; baseline $summary_red_failed failed + $summary_red_passed passed / $summary_red_assertions assertions, zero-assertion vendor-junction attempt, eMAR $summary_supporting_tests/$summary_supporting_assertions support, shared post-merge $summary_shared_tests/$summary_shared_assertions, and wrong-path pre-test invocation excluded</td><td class=\"partial\">self and existing actions preserved · other-staff reads require active shared-Site scope unless hr.employees.viewAllSites · client Gate unchanged · queued requester revalidated before protected reads/writes · feature unassigned · baseline $summary_baseline_short · fix $summary_fix_short · local merge $summary_merge_short · current main $summary_current_main_short · origin/main $summary_origin_short unchanged</td></tr><tr><td>RUN-089 designated-application preflight</td>",
        "</tr><tr><td>RUN-196 Summary/timeline Site-privacy execution</td><td>$summary_tests focused tests / $summary_assertions assertions counted once; baseline $summary_red_failed failed + $summary_red_passed passed / $summary_red_assertions assertions, zero-assertion vendor-junction attempt, eMAR $summary_supporting_tests/$summary_supporting_assertions support, shared post-merge $summary_shared_tests/$summary_shared_assertions, and wrong-path pre-test invocation excluded</td><td class=\"partial\">self and existing actions preserved · other-staff reads require active shared-Site scope unless hr.employees.viewAllSites · client Gate unchanged · queued requester revalidated before protected reads/writes · feature unassigned · baseline $summary_baseline_short · fix $summary_fix_short · local merge $summary_merge_short · current main $summary_current_main_short · origin/main $summary_origin_short unchanged</td></tr><tr><td>RUN-199 Shift-task due recipient-revalidation execution</td><td>$shift_task_tests post-merge focused tests / $shift_task_assertions assertions counted once; red $shift_task_red_failed failed + $shift_task_red_passed passed + $shift_task_red_pending pending / $shift_task_red_assertions assertions, intermediate $shift_task_intermediate_tests/$shift_task_intermediate_assertions and cache proofs, isolated final $shift_task_tests/$shift_task_assertions replay, and duplicate post-merge counting excluded</td><td class=\"partial\">scheduler-time denial leaves marker null and emits neither notification nor Facility signal · queue-time denial suppresses delivery without clearing a claimed marker or retracting a signal · feature unassigned · delegated coordination transcription, not an original runtime receipt · baseline $shift_task_baseline_short · fix $shift_task_fix_short · local merge $shift_task_merge_short · origin/main $shift_task_origin_short unchanged</td></tr><tr><td>RUN-089 designated-application preflight</td>",
    ),
]
run_199_rewrite_expected_counts = {
    "current RUN-197 split of 17 retained claim identities": 2,
}
for old, new in run_199_template_rewrites:
    expected_count = run_199_rewrite_expected_counts.get(old, 1)
    assert current_template_text.count(old) == expected_count, (
        f"Expected {expected_count} RUN-199 template rewrite target(s): {old}"
    )
    current_template_text = current_template_text.replace(old, new)

fresh_run_200_section_start = (
    '<section class="panel"><h2>Fresh RUN-200 audit-dashboard verification required</h2><p>'
)
fresh_run_200_section_end = '</p><ul class="list">'
assert current_template_text.count(fresh_run_200_section_start) == 1
fresh_run_200_start_index = current_template_text.index(fresh_run_200_section_start)
fresh_run_200_body_start = fresh_run_200_start_index + len(fresh_run_200_section_start)
fresh_run_200_body_end = current_template_text.index(
    fresh_run_200_section_end,
    fresh_run_200_body_start,
)
fresh_run_200_body = (
    "The exact RUN-199 reporting dashboard must be generated and checked in RUN-200 at 1440×900, 1280×800, 1024×768, and 390×844. "
    "RUN-198 verifies only the superseded RUN-197 HTML; SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01 was reproduced and locally integrated in exactly four paths, and RUN-199 changes only live reporting while preserving the verified RUN-198 HTML byte-for-byte. "
    "None supplies audit-dashboard verification for the new RUN-200 HTML. "
    "The linked RUN-200 receipt must record page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 667/310/357 ownership, 98 bridges, 121/386 queue accounting, 99 owned/408 without ownership, 18 retained claim identities split into 8 current provisional P1, 2 historical already-fixed, and 8 historical remediated, 185/2,691 uniquely counted bounded tests/assertions, post-merge Shift-task 9/50 counted once, all Shift-task red/intermediate/cache/isolated-replay/duplicate-postmerge executions excluded, current 2/340 benchmark mapping, 0/340 final no-match/NCM, 338 unresolved targets, one operating organisation across multiple Sites, Gate 4 open, and every non-bounded runtime, application-browser, final-finding, release, Pass, feature-completion, and audit-complete zero-credit boundary. "
    "It verifies the RUN-199 audit artifact only and grants no application-browser, responsive-application, visual, workflow, release, Pass, feature-completion, or audit-complete credit."
)
current_template_text = (
    current_template_text[:fresh_run_200_body_start]
    + fresh_run_200_body
    + current_template_text[fresh_run_200_body_end:]
)

run_201_template_rewrites = [
    ('<a href="#checkpoint">RUN-199</a>', '<a href="#checkpoint">RUN-201</a>'),
    (
        "RUN-198–199 Shift-task due recipient-revalidation checkpoint",
        "RUN-200–201 Shift eligibility-alert recipient Site-privacy checkpoint",
    ),
    (
        "RUN-199: Shift-task due recipient-revalidation reproduced and remediated in exactly four paths · one post-merge $shift_task_tests/$shift_task_assertions execution counted once · delegated coordination transcription, not an original runtime receipt · $finding_count provisional + $historical_fixed_count already-fixed + $historical_remediated_count remediated · $unique_bounded_tests/$unique_bounded_assertions unique bounded disposition total · dashboard HTML frozen pending RUN-200</li>",
        "RUN-199: Shift-task due recipient-revalidation reproduced and remediated in exactly four paths · historical 8 provisional + 2 already-fixed + 8 remediated · historical 185/2,691 unique bounded disposition total · dashboard HTML later verified by RUN-200</li><li>RUN-200: exact RUN-199 dashboard verified at 4/4 viewports · 48/48 named visible checks per viewport · 10/10 navigation · 504/504 resources · zero application credit</li><li>RUN-201: Shift eligibility-alert recipient Site privacy reproduced and remediated in exactly four paths · one post-merge $elig_shift_tests/$elig_shift_assertions execution counted once · delegated coordination transcription, not an original runtime receipt · $finding_count provisional + $historical_fixed_count already-fixed + $historical_remediated_count remediated · $unique_bounded_tests/$unique_bounded_assertions unique bounded disposition total · dashboard HTML frozen pending RUN-202</li>",
    ),
    (
        "The current RUN-199 register retains 18 identities: $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated; SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01 is the new historical-remediated record with null feature and candidate IDs, UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW identity, and zero static-ownership credit.",
        "The current RUN-201 register retains 19 identities: $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated; ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01 is the new historical-remediated record with null feature and candidate IDs, UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW identity, and zero static-ownership credit.",
    ),
    (
        "The current RUN-199 register retains $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated. SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01 adds only bounded scheduler-time and queued-delivery recipient revalidation reporting; feature identity remains unassigned with zero owner, bridge, page, or queue credit, while playback.data index 86 remains next.",
        "The current RUN-201 register retains $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated. ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01 adds only bounded current approved canonical-Shift-Site recipient and one canonical current Shift payload-snapshot reporting; feature identity remains unassigned with zero owner, bridge, page, or queue credit, while playback.data index 86 remains next.",
    ),
    (
        "$historical_fixed_count historical already-fixed · $historical_remediated_count historical remediated · 18 retained · none final",
        "$historical_fixed_count historical already-fixed · $historical_remediated_count historical remediated · 19 retained · none final",
    ),
    (
        "$fleet_playback_tests/$fleet_playback_assertions Fleet playback + $metric_tests/$metric_assertions final corrected Monitoring replay + $fleet_fuel_tests/$fleet_fuel_assertions Fleet Fuel + $summary_tests/$summary_assertions Summary/timeline + $shift_task_tests/$shift_task_assertions Shift-task; initial $metric_initial_tests/$metric_initial_assertions, Fuel red/replay/support, Summary red/zero-assertion/$summary_supporting_tests/$summary_supporting_assertions support/$summary_shared_tests/$summary_shared_assertions shared execution, Shift-task red/intermediate/cache/isolated replay/duplicate post-merge execution, and all other replay/support remain separate",
        "$fleet_playback_tests/$fleet_playback_assertions Fleet playback + $metric_tests/$metric_assertions final corrected Monitoring replay + $fleet_fuel_tests/$fleet_fuel_assertions Fleet Fuel + $summary_tests/$summary_assertions Summary/timeline + $shift_task_tests/$shift_task_assertions Shift-task + $elig_shift_tests/$elig_shift_assertions eligibility-alert Site privacy; initial $metric_initial_tests/$metric_initial_assertions, Fuel red/replay/support, Summary red/zero-assertion/$summary_supporting_tests/$summary_supporting_assertions support/$summary_shared_tests/$summary_shared_assertions shared execution, Shift-task red/intermediate/cache/isolated replay/duplicate post-merge execution, eligibility-alert red/intermediate-NO-GO/isolated replay/duplicate post-merge execution, and all other replay/support remain separate",
    ),
    (
        "The register retains 18 claim identities: $finding_count remain current provisional P1 claims, $historical_fixed_count are historical already-fixed records on current main, and $historical_remediated_count are historical remediated records. MON-METRIC-REPLAY-DEDUPE-01, SUMMARY-TIMELINE-SITE-PRIVACY-01, and SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01 retain null feature and candidate IDs and zero static ownership. FLEET-FUEL-INDEX-SITE-PRIVACY-01 remains candidate-only for CAP-FLEET-VEHICLE-REGISTER; row-attached logger identity follows foreign-row concealment only, with no independent logger-Site authorization for an otherwise visible row.",
        "The register retains 19 claim identities: $finding_count remain current provisional P1 claims, $historical_fixed_count are historical already-fixed records on current main, and $historical_remediated_count are historical remediated records. MON-METRIC-REPLAY-DEDUPE-01, SUMMARY-TIMELINE-SITE-PRIVACY-01, SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01, and ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01 retain null feature and candidate IDs and zero static ownership. FLEET-FUEL-INDEX-SITE-PRIVACY-01 remains candidate-only for CAP-FLEET-VEHICLE-REGISTER; row-attached logger identity follows foreign-row concealment only, with no independent logger-Site authorization for an otherwise visible row.",
    ),
    (
        "18 retained claim identities split into 8 current provisional P1, 2 historical already-fixed, and 8 historical remediated",
        "19 retained claim identities split into 8 current provisional P1, 2 historical already-fixed, and 9 historical remediated",
    ),
    ("RUN-155–199 bounded disposition provenance, remediation, and reporting", "RUN-155–201 bounded disposition provenance, remediation, and reporting"),
    ("RUN-001 through RUN-199 are represented by audit artifacts.", "RUN-001 through RUN-201 are represented by audit artifacts."),
    ("RUN-071–199 current reporting checkpoint", "RUN-071–201 current reporting checkpoint"),
    ("RUN-071–199 completion-gate checkpoint", "RUN-071–201 completion-gate checkpoint"),
    ("RUN-071–199 evidence lineage", "RUN-071–201 evidence lineage"),
    ("Every current raw, generated, reviewed, and integrated RUN-077–199", "Every current raw, generated, reviewed, and integrated RUN-077–201"),
    ("current RUN-199 split of 18 retained claim identities", "current RUN-201 split of 19 retained claim identities"),
    ("Fresh RUN-200 audit-dashboard verification required", "Fresh RUN-202 audit-dashboard verification required"),
    ("The exact RUN-199 reporting dashboard must be generated and checked in RUN-200", "The exact RUN-201 reporting dashboard must be generated and checked in RUN-202"),
    (
        "RUN-192 verifies only the superseded RUN-191 dashboard; RUN-193/R–194 establish and report the bounded Fuel remediation. RUN-195 verifies the exact RUN-194 dashboard; RUN-196/R–197 establish and report the bounded Summary/timeline remediation. RUN-198 verifies the exact RUN-197 dashboard; RUN-199 is the current Shift-task due recipient-revalidation reporting transaction, and RUN-200 remains the fresh exact-dashboard gate.",
        "RUN-195 verifies the exact RUN-194 dashboard; RUN-196/R–197 establish and report the bounded Summary/timeline remediation. RUN-198 verifies the exact RUN-197 dashboard; RUN-199 establishes and reports the bounded Shift-task remediation. RUN-200 verifies the exact RUN-199 dashboard; RUN-201 is the current Shift eligibility-alert Site-privacy reporting transaction, and RUN-202 remains the fresh exact-dashboard gate.",
    ),
    (
        "RUN-151, RUN-155, RUN-158, RUN-161, RUN-164, RUN-168, RUN-172, RUN-175, RUN-178, RUN-182, RUN-185, RUN-188, RUN-192, RUN-195, and RUN-198 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-199 reporting sources or the RUN-200 dashboard generated from them.",
        "RUN-151, RUN-155, RUN-158, RUN-161, RUN-164, RUN-168, RUN-172, RUN-175, RUN-178, RUN-182, RUN-185, RUN-188, RUN-192, RUN-195, RUN-198, and RUN-200 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-201 reporting sources or the RUN-202 dashboard generated from them.",
    ),
    ("It verifies the RUN-199 audit artifact only", "It verifies the RUN-201 audit artifact only"),
    (
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, exact-artifact, bounded Fleet evidence, RUN-198 exact-dashboard verification, and bounded delegated Shift-task coordination evidence reported in RUN-199, with fresh RUN-200 dashboard verification required.",
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, exact-artifact, bounded Fleet evidence, RUN-200 exact-dashboard verification, and bounded delegated Shift eligibility-alert coordination evidence reported in RUN-201, with fresh RUN-202 dashboard verification required.",
    ),
    (
        "RUN-159, RUN-162, RUN-166, RUN-173, RUN-176, RUN-183, RUN-186/R, RUN-193/R, RUN-196/R, and RUN-199 retain separate evidence boundaries; only MED-RBAC $med_rbac_tests/$med_rbac_assertions, MED-CD-SCOPE $med_cd_tests/$med_cd_assertions, post-merge SAFE $safe_tests/$safe_assertions, post-merge Fleet trip-index $fleet_tests/$fleet_assertions, post-merge Fleet playback $fleet_playback_tests/$fleet_playback_assertions, final post-corrective-merge Monitoring $metric_tests/$metric_assertions, unique post-merge Fuel $fleet_fuel_tests/$fleet_fuel_assertions, focused Summary/timeline $summary_tests/$summary_assertions, and one post-merge Shift-task $shift_task_tests/$shift_task_assertions execution contribute once to the current $unique_bounded_tests/$unique_bounded_assertions unique bounded total. The initial Monitoring $metric_initial_tests/$metric_initial_assertions, Fuel red/replay/support, Summary red/zero-assertion/$summary_supporting_tests/$summary_supporting_assertions support/$summary_shared_tests/$summary_shared_assertions shared execution, Shift-task red $shift_task_red_failed-failed/$shift_task_red_passed-passed/$shift_task_red_pending-pending/$shift_task_red_assertions-assertion reproduction, intermediate $shift_task_intermediate_tests/$shift_task_intermediate_assertions and cache proofs, isolated final $shift_task_tests/$shift_task_assertions replay, any duplicate post-merge count, and every other replay/subset remain excluded.",
        "RUN-159, RUN-162, RUN-166, RUN-173, RUN-176, RUN-183, RUN-186/R, RUN-193/R, RUN-196/R, RUN-199, and RUN-201 retain separate evidence boundaries; only MED-RBAC $med_rbac_tests/$med_rbac_assertions, MED-CD-SCOPE $med_cd_tests/$med_cd_assertions, post-merge SAFE $safe_tests/$safe_assertions, post-merge Fleet trip-index $fleet_tests/$fleet_assertions, post-merge Fleet playback $fleet_playback_tests/$fleet_playback_assertions, final post-corrective-merge Monitoring $metric_tests/$metric_assertions, unique post-merge Fuel $fleet_fuel_tests/$fleet_fuel_assertions, focused Summary/timeline $summary_tests/$summary_assertions, one post-merge Shift-task $shift_task_tests/$shift_task_assertions, and one post-merge eligibility-alert $elig_shift_tests/$elig_shift_assertions execution contribute once to the current $unique_bounded_tests/$unique_bounded_assertions unique bounded total. The initial Monitoring $metric_initial_tests/$metric_initial_assertions, Fuel red/replay/support, Summary red/zero-assertion/$summary_supporting_tests/$summary_supporting_assertions support/$summary_shared_tests/$summary_shared_assertions shared execution, Shift-task red/intermediate/cache/isolated replay/duplicate post-merge execution, eligibility-alert red $elig_shift_red_failed-failed/$elig_shift_red_passed-passed/$elig_shift_red_pending-pending/$elig_shift_red_assertions-assertion reproduction, intermediate reviewer-NO-GO $elig_shift_intermediate_tests/$elig_shift_intermediate_assertions, isolated final $elig_shift_tests/$elig_shift_assertions replay, any duplicate post-merge count, and every other replay/subset remain excluded.",
    ),
    (
        "</tr><tr><td>RUN-199 Shift-task due recipient-revalidation execution</td><td>$shift_task_tests post-merge focused tests / $shift_task_assertions assertions counted once; red $shift_task_red_failed failed + $shift_task_red_passed passed + $shift_task_red_pending pending / $shift_task_red_assertions assertions, intermediate $shift_task_intermediate_tests/$shift_task_intermediate_assertions and cache proofs, isolated final $shift_task_tests/$shift_task_assertions replay, and duplicate post-merge counting excluded</td><td class=\"partial\">scheduler-time denial leaves marker null and emits neither notification nor Facility signal · queue-time denial suppresses delivery without clearing a claimed marker or retracting a signal · feature unassigned · delegated coordination transcription, not an original runtime receipt · baseline $shift_task_baseline_short · fix $shift_task_fix_short · local merge $shift_task_merge_short · origin/main $shift_task_origin_short unchanged</td></tr><tr><td>RUN-089 designated-application preflight</td>",
        "</tr><tr><td>RUN-199 Shift-task due recipient-revalidation execution</td><td>$shift_task_tests post-merge focused tests / $shift_task_assertions assertions counted once; red $shift_task_red_failed failed + $shift_task_red_passed passed + $shift_task_red_pending pending / $shift_task_red_assertions assertions, intermediate $shift_task_intermediate_tests/$shift_task_intermediate_assertions and cache proofs, isolated final $shift_task_tests/$shift_task_assertions replay, and duplicate post-merge counting excluded</td><td class=\"partial\">scheduler-time denial leaves marker null and emits neither notification nor Facility signal · queue-time denial suppresses delivery without clearing a claimed marker or retracting a signal · feature unassigned · delegated coordination transcription, not an original runtime receipt · baseline $shift_task_baseline_short · fix $shift_task_fix_short · local merge $shift_task_merge_short · origin/main $shift_task_origin_short unchanged</td></tr><tr><td>RUN-201 Shift eligibility-alert Site-privacy execution</td><td>$elig_shift_tests post-merge focused tests / $elig_shift_assertions assertions counted once; red $elig_shift_red_failed failed + $elig_shift_red_passed passed + $elig_shift_red_pending pending / $elig_shift_red_assertions assertion, intermediate reviewer-NO-GO $elig_shift_intermediate_tests/$elig_shift_intermediate_assertions, isolated final $elig_shift_tests/$elig_shift_assertions replay, and duplicate post-merge counting excluded</td><td class=\"partial\">current active/non-ended employee and approved canonical-Shift-Site recipient · deterministic fallback continuation · one canonical current Shift authorization/payload snapshot · feature unassigned · delegated coordination transcription, not an original runtime receipt · baseline $elig_shift_baseline_short · fix $elig_shift_fix_short · local merge $elig_shift_merge_short · origin/main $elig_shift_origin_short unchanged</td></tr><tr><td>RUN-089 designated-application preflight</td>",
    ),
]
run_201_rewrite_expected_counts = {
    "current RUN-199 split of 18 retained claim identities": 2,
}
for old, new in run_201_template_rewrites:
    expected_count = run_201_rewrite_expected_counts.get(old, 1)
    assert current_template_text.count(old) == expected_count, (
        f"Expected {expected_count} RUN-201 template rewrite target(s): {old}"
    )
    current_template_text = current_template_text.replace(old, new)

fresh_run_202_section_start = (
    '<section class="panel"><h2>Fresh RUN-202 audit-dashboard verification required</h2><p>'
)
fresh_run_202_section_end = '</p><ul class="list">'
assert current_template_text.count(fresh_run_202_section_start) == 1
fresh_run_202_start_index = current_template_text.index(fresh_run_202_section_start)
fresh_run_202_body_start = fresh_run_202_start_index + len(fresh_run_202_section_start)
fresh_run_202_body_end = current_template_text.index(
    fresh_run_202_section_end,
    fresh_run_202_body_start,
)
fresh_run_202_body = (
    "The exact RUN-201 reporting dashboard must be generated and checked in RUN-202 at 1440×900, 1280×800, 1024×768, and 390×844. "
    "RUN-200 verifies only the superseded RUN-199 HTML; ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01 was reproduced and locally integrated in exactly four paths, and RUN-201 changes only live reporting while preserving the verified RUN-200 HTML byte-for-byte. "
    "None supplies audit-dashboard verification for the new RUN-202 HTML. "
    "The linked RUN-202 receipt must record page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 667/310/357 ownership, 98 bridges, 121/386 queue accounting, 99 owned/408 without ownership, 19 retained claim identities split into 8 current provisional P1, 2 historical already-fixed, and 9 historical remediated, 198/2,716 uniquely counted bounded tests/assertions, post-merge eligibility-alert 13/25 counted once, all eligibility-alert red/intermediate-NO-GO/isolated-replay/duplicate-postmerge executions excluded, current 2/340 benchmark mapping, 0/340 final no-match/NCM, 338 unresolved targets, one operating organisation across multiple Sites, Gate 4 open, and every non-bounded runtime, application-browser, final-finding, release, Pass, feature-completion, and audit-complete zero-credit boundary. "
    "It verifies the RUN-201 audit artifact only and grants no application-browser, responsive-application, visual, workflow, release, Pass, feature-completion, or audit-complete credit."
)
current_template_text = (
    current_template_text[:fresh_run_202_body_start]
    + fresh_run_202_body
    + current_template_text[fresh_run_202_body_end:]
)

run_203_template_rewrites = [
    ('<a href="#checkpoint">RUN-201</a>', '<a href="#checkpoint">RUN-203</a>'),
    (
        "RUN-200–201 Shift eligibility-alert recipient Site-privacy checkpoint",
        "RUN-202–203 Fleet playback data-point eligibility checkpoint",
    ),
    (
        "RUN-199: Shift-task due recipient-revalidation reproduced and remediated in exactly four paths · historical 8 provisional + 2 already-fixed + 8 remediated · historical 185/2,691 unique bounded disposition total · dashboard HTML later verified by RUN-200</li><li>RUN-200: exact RUN-199 dashboard verified at 4/4 viewports · 48/48 named visible checks per viewport · 10/10 navigation · 504/504 resources · zero application credit</li><li>RUN-201: Shift eligibility-alert recipient Site privacy reproduced and remediated in exactly four paths · one post-merge $elig_shift_tests/$elig_shift_assertions execution counted once · delegated coordination transcription, not an original runtime receipt · $finding_count provisional + $historical_fixed_count already-fixed + $historical_remediated_count remediated · $unique_bounded_tests/$unique_bounded_assertions unique bounded disposition total · dashboard HTML frozen pending RUN-202</li>",
        "RUN-199: Shift-task due recipient-revalidation reproduced and remediated in exactly four paths · historical 8 provisional + 2 already-fixed + 8 remediated · historical 185/2,691 unique bounded disposition total · dashboard HTML later verified by RUN-200</li><li>RUN-200: exact RUN-199 dashboard verified at 4/4 viewports · 48/48 named visible checks per viewport · 10/10 navigation · 504/504 resources · zero application credit</li><li>RUN-201: Shift eligibility-alert recipient Site privacy reproduced and remediated in exactly four paths · historical 8 provisional + 2 already-fixed + 9 remediated · historical 198/2,716 unique bounded disposition total · dashboard HTML later verified by RUN-202</li><li>RUN-202: exact RUN-201 dashboard verified at 4/4 viewports · 48/48 named visible checks per viewport · 10/10 navigation · 509/509 resources · zero application credit</li><li>RUN-203: Fleet playback data-point eligibility reproduced and remediated in exactly two paths · only the new post-merge $fleet_playback_data_tests/$fleet_playback_data_assertions component counted once · delegated coordination transcription, not an original runtime receipt · $finding_count provisional + $historical_fixed_count already-fixed + $historical_remediated_count remediated · $unique_bounded_tests/$unique_bounded_assertions unique bounded disposition total · dashboard HTML frozen pending RUN-204</li>",
    ),
    (
        "The current RUN-201 register retains 19 identities: $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated; ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01 is the new historical-remediated record with null feature and candidate IDs, UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW identity, and zero static-ownership credit.",
        "The current RUN-203 register retains 20 identities: $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated; FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01 is the new historical-remediated record with candidate/reporting association only to CAP-FLEET-VEHICLE-REGISTER, PENDING_FRESH_SEMANTIC_REVIEW identity, and zero static-ownership credit.",
    ),
    (
        "The current RUN-201 register retains $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated. ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01 adds only bounded current approved canonical-Shift-Site recipient and one canonical current Shift payload-snapshot reporting; feature identity remains unassigned with zero owner, bridge, page, or queue credit, while playback.data index 86 remains next.",
        "The current RUN-203 register retains $finding_count current provisional + $historical_fixed_count historical already-fixed + $historical_remediated_count historical remediated. FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01 adds only bounded coordinate-complete row eligibility before ordering and the 2,000-point cap; its CAP-FLEET-VEHICLE-REGISTER association is candidate/reporting-only with zero owner, bridge, page, queue, or prior-playback-privacy credit, and playback.data index 86 remains pending fresh semantic review.",
    ),
    (
        "$historical_fixed_count historical already-fixed · $historical_remediated_count historical remediated · 19 retained · none final",
        "$historical_fixed_count historical already-fixed · $historical_remediated_count historical remediated · 20 retained · none final",
    ),
    (
        "$fleet_playback_tests/$fleet_playback_assertions Fleet playback + $metric_tests/$metric_assertions final corrected Monitoring replay + $fleet_fuel_tests/$fleet_fuel_assertions Fleet Fuel + $summary_tests/$summary_assertions Summary/timeline + $shift_task_tests/$shift_task_assertions Shift-task + $elig_shift_tests/$elig_shift_assertions eligibility-alert Site privacy; initial $metric_initial_tests/$metric_initial_assertions, Fuel red/replay/support, Summary red/zero-assertion/$summary_supporting_tests/$summary_supporting_assertions support/$summary_shared_tests/$summary_shared_assertions shared execution, Shift-task red/intermediate/cache/isolated replay/duplicate post-merge execution, eligibility-alert red/intermediate-NO-GO/isolated replay/duplicate post-merge execution, and all other replay/support remain separate",
        "$fleet_playback_tests/$fleet_playback_assertions Fleet playback + $metric_tests/$metric_assertions final corrected Monitoring replay + $fleet_fuel_tests/$fleet_fuel_assertions Fleet Fuel + $summary_tests/$summary_assertions Summary/timeline + $shift_task_tests/$shift_task_assertions Shift-task + $elig_shift_tests/$elig_shift_assertions eligibility-alert Site privacy + $fleet_playback_data_tests/$fleet_playback_data_assertions Fleet playback data-point eligibility; initial $metric_initial_tests/$metric_initial_assertions, Fuel red/replay/support, Summary red/zero-assertion/$summary_supporting_tests/$summary_supporting_assertions support/$summary_shared_tests/$summary_shared_assertions shared execution, Shift-task and eligibility-alert red/intermediate/replay/duplicate post-merge execution, playback-data red/environment-invalid/isolated/combined/prior-playback/FleetManagement/duplicate post-merge execution, and all other replay/support remain separate",
    ),
    (
        "The register retains 19 claim identities: $finding_count remain current provisional P1 claims, $historical_fixed_count are historical already-fixed records on current main, and $historical_remediated_count are historical remediated records. MON-METRIC-REPLAY-DEDUPE-01, SUMMARY-TIMELINE-SITE-PRIVACY-01, SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01, and ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01 retain null feature and candidate IDs and zero static ownership. FLEET-FUEL-INDEX-SITE-PRIVACY-01 remains candidate-only for CAP-FLEET-VEHICLE-REGISTER; row-attached logger identity follows foreign-row concealment only, with no independent logger-Site authorization for an otherwise visible row.",
        "The register retains 20 claim identities: $finding_count remain current provisional P1 claims, $historical_fixed_count are historical already-fixed records on current main, and $historical_remediated_count are historical remediated records. MON-METRIC-REPLAY-DEDUPE-01, SUMMARY-TIMELINE-SITE-PRIVACY-01, SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01, and ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01 retain null feature and candidate IDs and zero static ownership. FLEET-FUEL-INDEX-SITE-PRIVACY-01 and FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01 remain candidate-only for CAP-FLEET-VEHICLE-REGISTER; the new playback-data record grants no static owner, bridge, queue, prior privacy, telemetry lifecycle/range, map/frontend, or adjacent Fleet credit.",
    ),
    (
        "19 retained claim identities split into 8 current provisional P1, 2 historical already-fixed, and 9 historical remediated",
        "20 retained claim identities split into 8 current provisional P1, 2 historical already-fixed, and 10 historical remediated",
    ),
    ("RUN-155–201 bounded disposition provenance, remediation, and reporting", "RUN-155–203 bounded disposition provenance, remediation, and reporting"),
    ("RUN-001 through RUN-201 are represented by audit artifacts.", "RUN-001 through RUN-203 are represented by audit artifacts."),
    ("RUN-071–201 current reporting checkpoint", "RUN-071–203 current reporting checkpoint"),
    ("RUN-071–201 completion-gate checkpoint", "RUN-071–203 completion-gate checkpoint"),
    ("RUN-071–201 evidence lineage", "RUN-071–203 evidence lineage"),
    ("Every current raw, generated, reviewed, and integrated RUN-077–201", "Every current raw, generated, reviewed, and integrated RUN-077–203"),
    ("current RUN-201 split of 19 retained claim identities", "current RUN-203 split of 20 retained claim identities"),
    ("Fresh RUN-202 audit-dashboard verification required", "Fresh RUN-204 audit-dashboard verification required"),
    (
        "RUN-195 verifies the exact RUN-194 dashboard; RUN-196/R–197 establish and report the bounded Summary/timeline remediation. RUN-198 verifies the exact RUN-197 dashboard; RUN-199 establishes and reports the bounded Shift-task remediation. RUN-200 verifies the exact RUN-199 dashboard; RUN-201 is the current Shift eligibility-alert Site-privacy reporting transaction, and RUN-202 remains the fresh exact-dashboard gate.",
        "RUN-198 verifies the exact RUN-197 dashboard; RUN-199 establishes and reports the bounded Shift-task remediation. RUN-200 verifies the exact RUN-199 dashboard; RUN-201 establishes and reports the bounded Shift eligibility-alert Site-privacy remediation. RUN-202 verifies the exact RUN-201 dashboard; RUN-203 is the current Fleet playback data-point eligibility reporting transaction, and RUN-204 remains the fresh exact-dashboard gate.",
    ),
    (
        "RUN-151, RUN-155, RUN-158, RUN-161, RUN-164, RUN-168, RUN-172, RUN-175, RUN-178, RUN-182, RUN-185, RUN-188, RUN-192, RUN-195, RUN-198, and RUN-200 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-201 reporting sources or the RUN-202 dashboard generated from them.",
        "RUN-151, RUN-155, RUN-158, RUN-161, RUN-164, RUN-168, RUN-172, RUN-175, RUN-178, RUN-182, RUN-185, RUN-188, RUN-192, RUN-195, RUN-198, RUN-200, and RUN-202 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-203 reporting sources or the RUN-204 dashboard generated from them.",
    ),
    ("It verifies the RUN-201 audit artifact only", "It verifies the RUN-203 audit artifact only"),
    (
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, exact-artifact, bounded Fleet evidence, RUN-200 exact-dashboard verification, and bounded delegated Shift eligibility-alert coordination evidence reported in RUN-201, with fresh RUN-202 dashboard verification required.",
        "Generated deterministically from independently reviewed static, Git/source, claim-specific runtime/remediation, exact-artifact, bounded Fleet evidence, RUN-202 exact-dashboard verification, and bounded delegated Fleet playback data-point eligibility coordination evidence reported in RUN-203, with fresh RUN-204 dashboard verification required.",
    ),
    (
        "RUN-145 grants exactly two target-specific static benchmark-mapping credits. Apart from separately bounded RUN-159 MED-RBAC, RUN-162 MED-CD-SCOPE, RUN-166 manual-entry MED-CD-ATOMICITY, RUN-173 post-merge SAFE, RUN-176 post-merge Fleet trip-index, RUN-183 post-merge Fleet playback, RUN-186 final post-corrective-merge Monitoring metric-replay, and RUN-193 post-merge Fleet Fuel focused executions, no represented wave grants broader or full-suite application runtime or coverage; only MED-RBAC, MED-CD-SCOPE, post-merge SAFE, post-merge Fleet trip-index, post-merge Fleet playback, final post-corrective-merge Monitoring $metric_tests/$metric_assertions, and the unique post-merge Fuel $fleet_fuel_tests/$fleet_fuel_assertions component contribute once to the current $unique_bounded_tests/$unique_bounded_assertions total. The initial Monitoring $metric_initial_tests/$metric_initial_assertions, Fuel red $fleet_fuel_red_failed/$fleet_fuel_red_assertions, isolated Fuel replay, supporting $fleet_fuel_supporting_tests/$fleet_fuel_supporting_assertions, and any second count from combined 26/421 are excluded.",
        "RUN-145 grants exactly two target-specific static benchmark-mapping credits. Apart from separately bounded RUN-159 MED-RBAC, RUN-162 MED-CD-SCOPE, RUN-166 manual-entry MED-CD-ATOMICITY, RUN-173 post-merge SAFE, RUN-176 post-merge Fleet trip-index, RUN-183 post-merge Fleet playback, RUN-186 final post-corrective-merge Monitoring metric-replay, RUN-193 post-merge Fleet Fuel, RUN-196 focused Summary/timeline, RUN-199 post-merge Shift-task, RUN-201 post-merge eligibility-alert, and RUN-203 post-merge Fleet playback data-point eligibility executions, no represented wave grants broader or full-suite application runtime or coverage; only MED-RBAC, MED-CD-SCOPE, post-merge SAFE, post-merge Fleet trip-index, post-merge Fleet playback, final post-corrective-merge Monitoring $metric_tests/$metric_assertions, the unique post-merge Fuel $fleet_fuel_tests/$fleet_fuel_assertions component, focused Summary/timeline $summary_tests/$summary_assertions, one post-merge Shift-task $shift_task_tests/$shift_task_assertions, one post-merge eligibility-alert $elig_shift_tests/$elig_shift_assertions, and only the new post-merge Fleet playback data-point eligibility $fleet_playback_data_tests/$fleet_playback_data_assertions component contribute once to the current $unique_bounded_tests/$unique_bounded_assertions total. Every red, environment-invalid, replay, supporting, overlapping, or duplicate post-merge execution remains excluded.",
    ),
    (
        "RUN-159 establishes bounded MED-RBAC execution; RUN-162 separately establishes focused MED-CD-SCOPE remediation execution; RUN-166 separately establishes bounded manual-entry MED-CD-ATOMICITY execution; RUN-173 adds one post-merge $safe_tests/$safe_assertions SAFE focused execution, RUN-176 adds one post-merge $fleet_tests/$fleet_assertions Fleet index execution, RUN-183 adds one post-merge $fleet_playback_tests/$fleet_playback_assertions Fleet playback execution, RUN-186 adds only the final post-corrective-merge $metric_tests/$metric_assertions Monitoring metric-replay execution, and RUN-193 adds only the unique post-merge $fleet_fuel_tests/$fleet_fuel_assertions Fuel component to the unique bounded total. The initial Monitoring $metric_initial_tests/$metric_initial_assertions, all Monitoring replays/subsets, Fuel red/replay/support/combined-overlap, supporting suites, adjacent filters, red failures, terminal-fixture failures, and atomicity remain separate or excluded, and none establishes full-suite, coverage, application-browser, ease, release, Pass, publication, or completion credit.",
        "RUN-159 establishes bounded MED-RBAC execution; RUN-162 separately establishes focused MED-CD-SCOPE remediation execution; RUN-166 separately establishes bounded manual-entry MED-CD-ATOMICITY execution; RUN-173 adds one post-merge $safe_tests/$safe_assertions SAFE focused execution, RUN-176 adds one post-merge $fleet_tests/$fleet_assertions Fleet index execution, RUN-183 adds one post-merge $fleet_playback_tests/$fleet_playback_assertions Fleet playback execution, RUN-186 adds only the final post-corrective-merge $metric_tests/$metric_assertions Monitoring metric-replay execution, RUN-193 adds only the unique post-merge $fleet_fuel_tests/$fleet_fuel_assertions Fuel component, RUN-196 adds focused Summary/timeline $summary_tests/$summary_assertions, RUN-199 adds one post-merge Shift-task $shift_task_tests/$shift_task_assertions, RUN-201 adds one post-merge eligibility-alert $elig_shift_tests/$elig_shift_assertions, and RUN-203 adds only the new post-merge Fleet playback data-point eligibility $fleet_playback_data_tests/$fleet_playback_data_assertions component to the unique bounded total. Every red, environment-invalid, replay, supporting, overlapping, or duplicate post-merge execution remains separate or excluded, and none establishes full-suite, coverage, application-browser, ease, release, Pass, publication, or completion credit.",
    ),
    (
        "RUN-159, RUN-162, RUN-166, RUN-173, RUN-176, RUN-183, RUN-186/R, RUN-193/R, RUN-196/R, RUN-199, and RUN-201 retain separate evidence boundaries; only MED-RBAC $med_rbac_tests/$med_rbac_assertions, MED-CD-SCOPE $med_cd_tests/$med_cd_assertions, post-merge SAFE $safe_tests/$safe_assertions, post-merge Fleet trip-index $fleet_tests/$fleet_assertions, post-merge Fleet playback $fleet_playback_tests/$fleet_playback_assertions, final post-corrective-merge Monitoring $metric_tests/$metric_assertions, unique post-merge Fuel $fleet_fuel_tests/$fleet_fuel_assertions, focused Summary/timeline $summary_tests/$summary_assertions, one post-merge Shift-task $shift_task_tests/$shift_task_assertions, and one post-merge eligibility-alert $elig_shift_tests/$elig_shift_assertions execution contribute once to the current $unique_bounded_tests/$unique_bounded_assertions unique bounded total. The initial Monitoring $metric_initial_tests/$metric_initial_assertions, Fuel red/replay/support, Summary red/zero-assertion/$summary_supporting_tests/$summary_supporting_assertions support/$summary_shared_tests/$summary_shared_assertions shared execution, Shift-task red/intermediate/cache/isolated replay/duplicate post-merge execution, eligibility-alert red $elig_shift_red_failed-failed/$elig_shift_red_passed-passed/$elig_shift_red_pending-pending/$elig_shift_red_assertions-assertion reproduction, intermediate reviewer-NO-GO $elig_shift_intermediate_tests/$elig_shift_intermediate_assertions, isolated final $elig_shift_tests/$elig_shift_assertions replay, any duplicate post-merge count, and every other replay/subset remain excluded.",
        "RUN-159, RUN-162, RUN-166, RUN-173, RUN-176, RUN-183, RUN-186/R, RUN-193/R, RUN-196/R, RUN-199, RUN-201, and RUN-203 retain separate evidence boundaries; only MED-RBAC $med_rbac_tests/$med_rbac_assertions, MED-CD-SCOPE $med_cd_tests/$med_cd_assertions, post-merge SAFE $safe_tests/$safe_assertions, post-merge Fleet trip-index $fleet_tests/$fleet_assertions, post-merge Fleet playback $fleet_playback_tests/$fleet_playback_assertions, final post-corrective-merge Monitoring $metric_tests/$metric_assertions, unique post-merge Fuel $fleet_fuel_tests/$fleet_fuel_assertions, focused Summary/timeline $summary_tests/$summary_assertions, one post-merge Shift-task $shift_task_tests/$shift_task_assertions, one post-merge eligibility-alert $elig_shift_tests/$elig_shift_assertions, and only the new post-merge Fleet playback data-point eligibility $fleet_playback_data_tests/$fleet_playback_data_assertions component contribute once to the current $unique_bounded_tests/$unique_bounded_assertions unique bounded total. The initial Monitoring $metric_initial_tests/$metric_initial_assertions, Fuel red/replay/support, Summary red/zero-assertion/$summary_supporting_tests/$summary_supporting_assertions support/$summary_shared_tests/$summary_shared_assertions shared execution, Shift-task and eligibility-alert red/intermediate/replay/duplicate post-merge execution, playback-data red $fleet_playback_data_red_failed-failed/$fleet_playback_data_red_passed-passed/$fleet_playback_data_red_pending-pending/$fleet_playback_data_red_assertions-assertion reproduction, environment-invalid shared-vendor/classmap attempt, isolated focused $fleet_playback_data_isolated_tests/$fleet_playback_data_isolated_assertions, combined $fleet_playback_data_combined_tests/$fleet_playback_data_combined_assertions, prior playback $fleet_playback_data_prior_tests/$fleet_playback_data_prior_assertions, unchanged FleetManagement $fleet_playback_data_supporting_tests/$fleet_playback_data_supporting_assertions, any duplicate post-merge count, and every other replay/subset remain excluded.",
    ),
    (
        "</tr><tr><td>RUN-201 Shift eligibility-alert Site-privacy execution</td><td>$elig_shift_tests post-merge focused tests / $elig_shift_assertions assertions counted once; red $elig_shift_red_failed failed + $elig_shift_red_passed passed + $elig_shift_red_pending pending / $elig_shift_red_assertions assertion, intermediate reviewer-NO-GO $elig_shift_intermediate_tests/$elig_shift_intermediate_assertions, isolated final $elig_shift_tests/$elig_shift_assertions replay, and duplicate post-merge counting excluded</td><td class=\"partial\">current active/non-ended employee and approved canonical-Shift-Site recipient · deterministic fallback continuation · one canonical current Shift authorization/payload snapshot · feature unassigned · delegated coordination transcription, not an original runtime receipt · baseline $elig_shift_baseline_short · fix $elig_shift_fix_short · local merge $elig_shift_merge_short · origin/main $elig_shift_origin_short unchanged</td></tr><tr><td>RUN-089 designated-application preflight</td>",
        "</tr><tr><td>RUN-201 Shift eligibility-alert Site-privacy execution</td><td>$elig_shift_tests post-merge focused tests / $elig_shift_assertions assertions counted once; red $elig_shift_red_failed failed + $elig_shift_red_passed passed + $elig_shift_red_pending pending / $elig_shift_red_assertions assertion, intermediate reviewer-NO-GO $elig_shift_intermediate_tests/$elig_shift_intermediate_assertions, isolated final $elig_shift_tests/$elig_shift_assertions replay, and duplicate post-merge counting excluded</td><td class=\"partial\">current active/non-ended employee and approved canonical-Shift-Site recipient · deterministic fallback continuation · one canonical current Shift authorization/payload snapshot · feature unassigned · delegated coordination transcription, not an original runtime receipt · baseline $elig_shift_baseline_short · fix $elig_shift_fix_short · local merge $elig_shift_merge_short · origin/main $elig_shift_origin_short unchanged</td></tr><tr><td>RUN-203 Fleet playback data-point eligibility execution</td><td>only the new post-merge $fleet_playback_data_tests/$fleet_playback_data_assertions regression component counted once inside $fleet_playback_data_combined_tests/$fleet_playback_data_combined_assertions; valid red $fleet_playback_data_red_failed failed + $fleet_playback_data_red_passed passed + $fleet_playback_data_red_pending pending / $fleet_playback_data_red_assertions assertions, environment-invalid shared-vendor/classmap attempt, isolated $fleet_playback_data_isolated_tests/$fleet_playback_data_isolated_assertions, prior playback $fleet_playback_data_prior_tests/$fleet_playback_data_prior_assertions, unchanged FleetManagement $fleet_playback_data_supporting_tests/$fleet_playback_data_supporting_assertions, and duplicate combined counting excluded</td><td class=\"partial\">coordinate-complete rows before ordering and the 2,000-point cap only · candidate/reporting association to CAP-FLEET-VEHICLE-REGISTER · index 86 remains pending fresh semantic review · delegated coordination transcription, not an original runtime receipt · baseline $fleet_playback_data_baseline_short · fix $fleet_playback_data_fix_short · local merge $fleet_playback_data_merge_short · origin/main $fleet_playback_data_origin_short unchanged</td></tr><tr><td>RUN-089 designated-application preflight</td>",
    ),
]
run_203_rewrite_expected_counts = {
    "current RUN-201 split of 19 retained claim identities": 2,
}
for old, new in run_203_template_rewrites:
    expected_count = run_203_rewrite_expected_counts.get(old, 1)
    assert current_template_text.count(old) == expected_count, (
        f"Expected {expected_count} RUN-203 template rewrite target(s): {old}"
    )
    current_template_text = current_template_text.replace(old, new)

fresh_run_204_section_start = (
    '<section class="panel"><h2>Fresh RUN-204 audit-dashboard verification required</h2><p>'
)
fresh_run_204_section_end = '</p><ul class="list">'
assert current_template_text.count(fresh_run_204_section_start) == 1
fresh_run_204_start_index = current_template_text.index(fresh_run_204_section_start)
fresh_run_204_body_start = fresh_run_204_start_index + len(fresh_run_204_section_start)
fresh_run_204_body_end = current_template_text.index(
    fresh_run_204_section_end,
    fresh_run_204_body_start,
)
fresh_run_204_body = (
    "The exact RUN-203 reporting dashboard must be generated and checked in RUN-204 at 1440×900, 1280×800, 1024×768, and 390×844. "
    "RUN-202 verifies only the superseded RUN-201 HTML at 4/4 viewports, 48/48 named visible checks per viewport, 10/10 navigation targets, and 509/509 local resources; FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01 was reproduced and locally integrated in exactly two paths, and RUN-203 changes only live reporting while preserving the verified RUN-202 HTML byte-for-byte. "
    "None supplies audit-dashboard verification for the new RUN-204 HTML. "
    "The linked RUN-204 receipt must record page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 667/310/357 ownership, 98 bridges, 121/386 queue accounting, 99 owned/408 without ownership, 20 retained claim identities split into 8 current provisional P1, 2 historical already-fixed, and 10 historical remediated, 199/2,722 uniquely counted bounded tests/assertions, only the new post-merge Fleet playback data-point eligibility 1/6 component counted once, all playback-data red/environment-invalid/isolated/combined/prior-playback/FleetManagement/duplicate-postmerge executions excluded, current 2/340 benchmark mapping, 0/340 final no-match/NCM, 338 unresolved targets, one operating organisation across multiple Sites, Gate 4 open, and every non-bounded runtime, application-browser, final-finding, release, Pass, feature-completion, and audit-complete zero-credit boundary. "
    "It verifies the RUN-203 audit artifact only and grants no application-browser, responsive-application, visual, workflow, release, Pass, feature-completion, or audit-complete credit."
)
current_template_text = (
    current_template_text[:fresh_run_204_body_start]
    + fresh_run_204_body
    + current_template_text[fresh_run_204_body_end:]
)
TEMPLATE = Template(current_template_text)


dashboard = TEMPLATE.substitute(
    fleet_observation_items=fleet_observation_items,
    medication_record_items=medication_record_items,
    run156r_commit=CURRENT_RUN_156R_COMMIT,
    med_rbac_application_short=run_159_adjudication["pins"]["application_commit"][:12],
    application_short=run_162_pins["application_commit"][:12],
    application_tree_short=run_162_pins["repository_tree_at_application_commit"][:12],
    atomicity_application_short=run_165_source_review["pins"]["reviewed_source_checkpoint"][:12],
    route_calls=f"{semantic['routes']['static_route_declaration_callsites']:,}",
    resolver_count=f"{semantic['inertia_pages']['resolver_non_test_tsx']:,}",
    page_root_count=f"{pages['recommended_denominator']['value']:,}",
    support_count=f"{pages['reproduction']['resolver_partition']['unrendered_imported']:,}",
    legacy_count=pages["candidate_class_counts"]["alias/generated/legacy"],
    dead_demo_count=pages["candidate_class_counts"]["dead/unreachable candidate"] + pages["candidate_class_counts"]["test/demo/story"],
    missing_target_count=len(pages["missing_render_target_adjudication"]),
    canonical_count=f"{len(targets):,}",
    source_count=canonical["counts"]["source_candidates"],
    mapped_sources=canonical["counts"]["mapped_sources"],
    layer_a_edges=canonical["counts"]["layer_a_edges"],
    layer_a_targets=canonical["counts"]["layer_a_targets"],
    h_count=class_counts["H"],
    d_count=class_counts["D"],
    m_count=canonical["counts"]["classes"]["M"],
    required_present=len(required_present),
    required_missing=len(required_missing),
    task_contracts=usability_materialization["counts"]["task_script_files"],
    validated_tasks=usability_materialization["counts"]["validated_task_scripts"],
    route_slice_targets=route_page_slice["counts"]["targets"],
    route_slice_routes=route_page_slice["counts"]["primary_routes"],
    route_gap_count=len(live_gap_ids["route_paths"]),
    route_name_gap_count=len(live_gap_ids["route_names"]),
    page_gap_count=len(live_gap_ids["page_files"]),
    both_gap_count=len(live_both_gap_ids),
    backend_gap_count=len(live_gap_ids["backend_anchors"]),
    test_gap_count=len(live_gap_ids["test_anchors"]),
    static_reviewed_targets=static_linkage_integration["counts"]["reviewed_gap_targets"],
    static_original_missing_cells=sum(len(row["original_missing_fields"]) for row in static_linkage_producer["records"]),
    static_rows_changed=static_linkage_integration["counts"]["matrix_rows_changed"],
    static_field_changes=static_linkage_integration["counts"]["matrix_field_changes"],
    updated_matrix_short=static_linkage_integration["matrix"]["updated_sha256"][:16],
    route_primary_calls=f"{route_page_manifest['counts']['primary_route_facade_callsites']:,}",
    route_like_rows=f"{route_page_manifest['counts']['static_route_like_review_rows']:,}",
    route_name_calls=f"{route_page_manifest['counts']['fluent_name_callsites']:,}",
    route_page_roots=f"{route_page_manifest['counts']['page_roots']:,}",
    route_owner=route_page_review["counts"]["route_classifications"]["OWNER"],
    route_shared=route_page_review["counts"]["route_classifications"]["SHARED_RELATION"],
    route_alias=route_page_review["counts"]["route_classifications"]["ALIAS_OR_REDIRECT"],
    route_unmapped=f"{route_page_review['counts']['route_classifications']['EXPLICIT_UNMAPPED_SENTINEL']:,}",
    page_reviewed=route_page_review["counts"]["page_prompt_classifications"]["Reviewed"],
    page_evidence_gaps=route_page_review["counts"]["page_prompt_classifications"]["Evidence gap"],
    route_page_rows_changed=route_page_integration["counts"]["matrix_rows_changed"],
    route_page_field_changes=route_page_integration["counts"]["matrix_field_changes"],
    route_name_established=route_page_integration["counts"]["field_changes"]["route_names"],
    page_file_established=route_page_integration["counts"]["field_changes"]["page_files"],
    route_page_matrix_short=route_page_integration["matrix"]["updated_sha256"][:16],
    live_matrix_short=CURRENT_RUN_145_MATRIX_SHA256[:16],
    live_register_short=CURRENT_RUN_145_REGISTER_SHA256[:16],
    run145_receipt_short=CURRENT_RUN_145_RECEIPT_SHA256[:16],
    live_matrix_sha256=CURRENT_RUN_145_MATRIX_SHA256,
    live_register_sha256=CURRENT_RUN_145_REGISTER_SHA256,
    run145_receipt_sha256=CURRENT_RUN_145_RECEIPT_SHA256,
    benchmark_mapped=len(live_mapping_rows),
    benchmark_unresolved=len(live_unresolved_rows),
    final_no_matches=0,
    candidate_route_rows=f"{route_page_candidate['counts']['unresolved_route_like_records']:,}",
    candidate_page_rows=route_page_candidate["counts"]["page_evidence_gap_records"],
    candidate_exact_actions=f"{route_page_candidate['counts']['route_exact_class_method_arrays_resolved']:,}",
    candidate_non_exact_actions=route_page_candidate["counts"]["route_non_exact_class_method_array_records"],
    candidate_name_one=route_page_candidate["route_static_candidate_census"]["exact_route_name_cardinalities"]["one"]["count"],
    candidate_name_many=route_page_candidate["route_static_candidate_census"]["exact_route_name_cardinalities"]["many"]["count"],
    candidate_name_zero=f"{route_page_candidate['route_static_candidate_census']['exact_route_name_cardinalities']['zero']['count']:,}",
    candidate_backend_one=route_page_candidate["route_static_candidate_census"]["controller_method_containment_cardinalities_resolved_2879"]["one"]["count"],
    candidate_backend_many=route_page_candidate["route_static_candidate_census"]["controller_method_containment_cardinalities_resolved_2879"]["many"]["count"],
    candidate_backend_zero=f"{route_page_candidate['route_static_candidate_census']['controller_method_containment_cardinalities_resolved_2879']['zero']['count']:,}",
    candidate_page_one=route_page_candidate["page_static_candidate_census"]["render_owner_containment_cardinalities"]["one"]["count"],
    candidate_page_many=route_page_candidate["page_static_candidate_census"]["render_owner_containment_cardinalities"]["many"]["count"],
    candidate_page_zero=route_page_candidate["page_static_candidate_census"]["render_owner_containment_cardinalities"]["zero"]["count"],
    candidate_registered_routes=route_page_candidate["static_route_registration_closure"]["counts"]["represented_route_files"],
    candidate_direct_routes=route_page_candidate["static_route_registration_closure"]["counts"]["direct_bootstrap_surfaces"],
    candidate_web_routes=route_page_candidate["static_route_registration_closure"]["counts"]["web_required_surfaces"],
    full_page_tree_files=f"{page_graph['denominators']['page_tree_files']:,}",
    full_page_tsx=f"{page_graph['denominators']['tsx_files']:,}",
    full_page_ts=page_graph["denominators"]["ts_support_or_test_files"],
    full_page_production=page_graph["denominators"]["production_non_test_tsx"],
    full_page_excluded_tsx=page_graph["denominators"]["excluded_test_spec_story_tsx"],
    full_page_ts_helpers=page_graph["denominators"]["production_ts_helpers"],
    full_page_ts_tests=page_graph["denominators"]["test_spec_ts_files"],
    full_page_roots=page_graph["denominators"]["literal_rendered_page_roots"],
    full_page_support=page_graph["denominators"]["imported_support_components"],
    full_page_nonroots=page_graph["denominators"]["adjudicated_unrendered_unimported_non_roots"],
    backend_role_rows=f"{backend_semantic['denominators']['total_role_rows']:,}",
    backend_unique_paths=f"{backend_semantic['denominators']['unique_source_paths']:,}",
    backend_models=backend_semantic["denominators"]["models"],
    backend_policies=backend_semantic["denominators"]["policies"],
    backend_services=backend_semantic["denominators"]["services"],
    backend_async_rows=backend_semantic["denominators"]["async_role_rows"],
    backend_async_paths=backend_semantic["denominators"]["async_unique_paths"],
    static_owner_records=reviewed_fleet_daily_check_overlay["combined_counts"]["source_owner_records"],
    static_owner_routes=reviewed_fleet_daily_check_overlay["combined_counts"]["route_owner_records"],
    static_owner_pages=reviewed_fleet_daily_check_overlay["combined_counts"]["page_owner_records"],
    static_owner_features=reviewed_fleet_daily_check_overlay["combined_counts"]["distinct_feature_ids"],
    static_owner_h_features=reviewed_fleet_daily_check_overlay["combined_counts"]["distinct_H_feature_ids"],
    static_owner_d_features=reviewed_fleet_daily_check_overlay["combined_counts"]["distinct_D_feature_ids"],
    route_feature_ids=reviewed_fleet_daily_check_overlay["combined_counts"]["route_distinct_feature_ids"],
    page_feature_ids=reviewed_fleet_daily_check_overlay["combined_counts"]["page_distinct_feature_ids"],
    route_page_overlap=reviewed_fleet_daily_check_overlay["combined_counts"]["route_page_feature_overlap"],
    static_action_bridges=reviewed_fleet_daily_check_overlay["combined_counts"]["static_controller_action_bridges"],
    static_residual=f"{reviewed_fleet_daily_check_overlay['combined_counts']['bounded_static_source_residual_records']:,}",
    ownership_percent=reviewed_fleet_daily_check_overlay["combined_counts"]["bounded_static_source_ownership_percent"],
    route_residual=f"{reviewed_fleet_daily_check_overlay['combined_counts']['residual_explicit_unmapped_routes']:,}",
    route_shared_current=reviewed_fleet_daily_check_overlay["combined_counts"]["semantic_shared_routes"],
    route_alias_current=reviewed_fleet_daily_check_overlay["combined_counts"]["reviewed_alias_routes"],
    page_shared=reviewed_fleet_daily_check_overlay["combined_counts"]["semantic_shared_page_roots"],
    page_residual=reviewed_fleet_daily_check_overlay["combined_counts"]["residual_unadjudicated_page_roots"],
    page_gap=reviewed_fleet_daily_check_overlay["combined_counts"]["evidence_gap_page_roots_tagged_within_residual"],
    page_wave_reviewed=reviewed_page_owner_overlay["reviewed_overlay"]["reviewed_pages"],
    page_review_owner=reviewed_page_owner_overlay["reviewed_overlay"]["owner_pages"],
    page_review_shared=reviewed_page_owner_overlay["reviewed_overlay"]["shared_relations"],
    page_review_gap=reviewed_page_owner_overlay["reviewed_overlay"]["evidence_gaps"],
    route_wave_reviewed=reviewed_name_only_route_action_overlay["reviewed_overlay"]["reviewed_route_actions"],
    route_review_owner=reviewed_name_only_route_action_overlay["reviewed_overlay"]["owner_route_actions"],
    route_review_shared=reviewed_name_only_route_action_overlay["reviewed_overlay"]["shared_relations"],
    route_review_alias=reviewed_name_only_route_action_overlay["reviewed_overlay"]["alias_or_redirect"],
    route_review_dead=reviewed_name_only_route_action_overlay["reviewed_overlay"]["dead_or_noncanonical"],
    route_review_gap=reviewed_name_only_route_action_overlay["reviewed_overlay"]["evidence_gaps"],
    page_context_calls=reviewed_name_only_route_action_overlay["page_context_boundary"]["literal_callsites"],
    page_context_owned=reviewed_name_only_route_action_overlay["page_context_boundary"]["currently_owned_page_callsites"],
    page_context_gaps=reviewed_name_only_route_action_overlay["page_context_boundary"]["current_page_evidence_gap_callsites"],
    page_context_authorized=reviewed_name_only_route_action_overlay["page_context_boundary"]["page_ownership_authorized"],
    respite_page_wave_reviewed=reviewed_respite_handover_page_overlay["reviewed_overlay"]["reviewed_pages"],
    respite_page_review_owner=reviewed_respite_handover_page_overlay["reviewed_overlay"]["owner_pages"],
    finance_page_wave_reviewed=reviewed_finance_page_gap_overlay["reviewed_overlay"]["reviewed_pages"],
    finance_page_review_owner=reviewed_finance_page_gap_overlay["reviewed_overlay"]["owner_pages"],
    finance_fx_wave_reviewed=reviewed_finance_fx_revaluation_overlay["reviewed_overlay"]["reviewed_route_actions"],
    finance_fx_review_owner=reviewed_finance_fx_revaluation_overlay["reviewed_overlay"]["owner_route_actions"],
    finance_fx_page_calls=reviewed_finance_fx_revaluation_overlay["page_context_boundary"]["literal_inertia_page_callsites"],
    finance_fx_existing_caller_pages=reviewed_finance_fx_revaluation_overlay["page_context_boundary"]["existing_caller_pages"],
    finance_fx_page_owners_added=reviewed_finance_fx_revaluation_overlay["page_context_boundary"]["new_page_owner_records"],
    finance_fx_page_inherited=reviewed_finance_fx_revaluation_overlay["page_context_boundary"]["page_ownership_inherited"],
    finance_fx_route_gap=reviewed_fleet_daily_check_overlay["combined_counts"]["evidence_gap_routes_tagged_within_residual"],
    finance_accounting_wave_reviewed=reviewed_finance_accounting_integration_overlay["reviewed_overlay"]["reviewed_route_actions"],
    finance_accounting_review_owner=reviewed_finance_accounting_integration_overlay["reviewed_overlay"]["owner_route_actions"],
    finance_accounting_page_calls=reviewed_finance_accounting_integration_overlay["page_context_boundary"]["literal_inertia_page_callsites"],
    finance_accounting_existing_pages=reviewed_finance_accounting_integration_overlay["page_context_boundary"]["existing_caller_or_render_pages"],
    finance_accounting_frontend_contexts=reviewed_finance_accounting_integration_overlay["page_context_boundary"]["selected_frontend_literal_caller_contexts"],
    finance_accounting_no_literal_callers=reviewed_finance_accounting_integration_overlay["page_context_boundary"]["selected_routes_without_literal_caller_in_frozen_pages"],
    finance_accounting_page_owners_added=reviewed_finance_accounting_integration_overlay["page_context_boundary"]["new_page_owner_records"],
    finance_accounting_page_inherited=reviewed_finance_accounting_integration_overlay["page_context_boundary"]["page_ownership_inherited"],
    finance_accounting_page_reassigned=reviewed_finance_accounting_integration_overlay["page_context_boundary"]["page_ownership_reassigned"],
    finance_accounting_route_gap=reviewed_fleet_daily_check_overlay["combined_counts"]["evidence_gap_routes_tagged_within_residual"],
    finance_site_wave_reviewed=reviewed_finance_site_portfolio_overview_overlay["reviewed_overlay"]["reviewed_route_actions"],
    finance_site_review_owner=reviewed_finance_site_portfolio_overview_overlay["reviewed_overlay"]["owner_route_actions"],
    finance_site_literal_page_calls=reviewed_finance_site_portfolio_overview_overlay["page_context_boundary"]["selected_action_evidence"]["literal_inertia_page_callsite_count"],
    finance_site_existing_page_owners=1 if reviewed_finance_site_portfolio_overview_overlay["page_context_boundary"]["existing_page_owner_context"] else 0,
    finance_site_sibling_routes=1 if reviewed_finance_site_portfolio_overview_overlay["page_context_boundary"]["separate_page_route_sibling"] else 0,
    finance_site_page_callers=len(reviewed_finance_site_portfolio_overview_overlay["page_context_boundary"]["page_path_caller_contexts"]),
    finance_site_exact_frontend_callers=reviewed_finance_site_portfolio_overview_overlay["page_context_boundary"]["selected_api_exact_frontend_caller_occurrences"],
    finance_site_page_owners_added=reviewed_finance_site_portfolio_overview_overlay["reviewed_overlay"]["accepted_page_owner_records"],
    finance_site_excluded_neighbor=reviewed_finance_site_portfolio_overview_overlay["page_context_boundary"]["excluded_immediate_raw_neighbor"]["queue_index_zero_based"],
    finance_site_next_pending=reviewed_finance_site_portfolio_overview_overlay["page_context_boundary"]["next_pending_boundary"]["queue_index_zero_based"],
    finance_site_route_gap=reviewed_fleet_daily_check_overlay["combined_counts"]["evidence_gap_routes_tagged_within_residual"],
    finance_wave_reviewed=reviewed_finance_chart_route_action_overlay["reviewed_overlay"]["reviewed_route_actions"],
    finance_review_owner=reviewed_finance_chart_route_action_overlay["reviewed_overlay"]["owner_route_actions"],
    finance_review_shared=reviewed_finance_chart_route_action_overlay["reviewed_overlay"]["shared_relations"],
    finance_review_alias=reviewed_finance_chart_route_action_overlay["reviewed_overlay"]["alias_or_redirect"],
    finance_review_dead=reviewed_finance_chart_route_action_overlay["reviewed_overlay"]["dead_or_noncanonical"],
    finance_review_gap=reviewed_finance_chart_route_action_overlay["reviewed_overlay"]["evidence_gaps"],
    finance_page_calls=reviewed_finance_page_gap_overlay["page_context_boundary"]["run_121_literal_page_callsites"],
    finance_page_owned=reviewed_finance_page_gap_overlay["page_context_boundary"]["already_owned_before_run_125"],
    finance_page_unowned=reviewed_finance_page_gap_overlay["page_context_boundary"]["remaining_unowned_from_run_121_context"],
    finance_page_authorized=reviewed_finance_page_gap_overlay["page_context_boundary"]["new_page_owner_records"],
    queue_gap=reviewed_fleet_daily_check_overlay["queue_accounting"]["evidence_gap_queue_surface_rows"],
    queue_records=reviewed_fleet_daily_check_overlay["queue_accounting"]["direct_exact_queue_records"],
    queue_reviewed=reviewed_fleet_daily_check_overlay["queue_accounting"]["reviewed_queue_surface_rows"],
    queue_pending=reviewed_fleet_daily_check_overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"],
    queue_without_owner=reviewed_fleet_daily_check_overlay["queue_accounting"]["queue_surfaces_without_ownership"],
    queue_owner=reviewed_fleet_daily_check_overlay["queue_accounting"]["owner_queue_surface_rows"],
    queue_shared=reviewed_fleet_daily_check_overlay["queue_accounting"]["shared_queue_surface_rows"],
    queue_alias=reviewed_fleet_daily_check_overlay["queue_accounting"]["alias_queue_surface_rows"],
    finding_count=len(provisional_findings),
    historical_fixed_count=len(historical_fixed_findings),
    historical_remediated_count=len(historical_remediated_findings),
    bounded_tests=run_159_runtime["totals"]["tests_passed"],
    bounded_assertions=f"{run_159_runtime['totals']['assertions']:,}",
    med_rbac_tests=run_159_runtime["totals"]["tests_passed"],
    med_rbac_assertions=f"{run_159_runtime['totals']['assertions']:,}",
    med_cd_tests=run_162_runtime["advanced_main_focused_command"]["tests"],
    med_cd_assertions=run_162_runtime["advanced_main_focused_command"]["assertions"],
    med_cd_related_tests=run_162_runtime["broader_bounded_execution"]["directly_related_controller_and_command_tests_passed"],
    med_cd_broader_passed=run_162_runtime["broader_bounded_execution"]["combined_passed"],
    med_cd_broader_assertions=f"{run_162_runtime['broader_bounded_execution']['combined_assertions']:,}",
    med_cd_broader_failed=run_162_runtime["broader_bounded_execution"]["combined_failed"],
    atomicity_tests=run_166_claim_totals["test_functions_passed"],
    atomicity_assertions=run_166_claim_totals["assertions_across_command_outputs"],
    atomicity_races=run_166_claim_totals["race_subscenarios"],
    atomicity_supporting_tests=run_166_runtime["supporting_governance_command"]["tests_passed"],
    atomicity_supporting_assertions=run_166_runtime["supporting_governance_command"]["assertions"],
    unique_bounded_tests=findings_register["counts"]["bounded_disposition_tests_passed"],
    unique_bounded_assertions=f"{findings_register['counts']['bounded_disposition_assertions']:,}",
    safe_red_failed=run_173_remediation["delegated_runtime_execution"]["isolated_red_execution"]["failed"],
    safe_red_warning_pass=run_173_remediation["delegated_runtime_execution"]["isolated_red_execution"]["warning_pass"],
    safe_red_assertions=run_173_remediation["delegated_runtime_execution"]["isolated_red_execution"]["assertions_reported"],
    safe_tests=run_173_remediation["delegated_runtime_execution"]["post_merge_green_focused"]["tests"],
    safe_assertions=run_173_remediation["delegated_runtime_execution"]["post_merge_green_focused"]["assertions"],
    safe_bridge_tests=run_173_remediation["delegated_runtime_execution"]["supporting_control_room_bridge_suite"]["tests"],
    safe_bridge_assertions=run_173_remediation["delegated_runtime_execution"]["supporting_control_room_bridge_suite"]["assertions"],
    safe_hs_tests=run_173_remediation["delegated_runtime_execution"]["adjacent_hs_event_safeguarding_filter"]["tests_passed"],
    safe_hs_assertions=run_173_remediation["delegated_runtime_execution"]["adjacent_hs_event_safeguarding_filter"]["assertions"],
    safe_terminal_failures=run_173_remediation["delegated_runtime_execution"]["terminal_transition_fixture_debt"]["failures"],
    safe_baseline_short=run_173_remediation["pins"]["application_baseline_commit"][:12],
    safe_fix_short=run_173_remediation["pins"]["fix_commit"][:12],
    safe_merge_short=run_173_remediation["pins"]["local_main_merge_commit"][:12],
    safe_origin_short=run_173_remediation["pins"]["origin_main_observed"][:12],
    safe_test_sha256=run_173_remediation["pins"]["merged_source_and_regression_test"][1]["sha256"],
    fleet_root_red_failed=run_176_remediation["delegated_runtime_execution"]["root_audit_lane_initial_red"]["failed"],
    fleet_root_red_assertions=run_176_remediation["delegated_runtime_execution"]["root_audit_lane_initial_red"]["assertions_reported"],
    fleet_expanded_red_failed=run_176_remediation["delegated_runtime_execution"]["baseline_expanded_red"]["failed"],
    fleet_expanded_red_assertions=run_176_remediation["delegated_runtime_execution"]["baseline_expanded_red"]["assertions_reported"],
    fleet_tests=run_176_remediation["delegated_runtime_execution"]["post_merge_green_focused"]["tests"],
    fleet_assertions=run_176_remediation["delegated_runtime_execution"]["post_merge_green_focused"]["assertions"],
    fleet_supporting_tests=run_176_remediation["delegated_runtime_execution"]["post_merge_supporting_vehicle_controller_regressions"]["tests"],
    fleet_supporting_assertions=run_176_remediation["delegated_runtime_execution"]["post_merge_supporting_vehicle_controller_regressions"]["assertions"],
    fleet_baseline_short=run_176_remediation["pins"]["application_baseline_commit"][:12],
    fleet_fix_short=run_176_remediation["pins"]["fix_commit"][:12],
    fleet_merge_short=run_176_remediation["pins"]["local_main_merge_commit"][:12],
    fleet_origin_short=run_176_remediation["pins"]["origin_main_observed"][:12],
    fleet_playback_red_failed=run_183_remediation["delegated_runtime_execution"]["baseline_red"]["failed"],
    fleet_playback_red_passed=run_183_remediation["delegated_runtime_execution"]["baseline_red"]["passed"],
    fleet_playback_red_assertions=run_183_remediation["delegated_runtime_execution"]["baseline_red"]["assertions_reported"],
    fleet_playback_tests=run_183_remediation["delegated_runtime_execution"]["post_merge_green_focused"]["tests"],
    fleet_playback_assertions=run_183_remediation["delegated_runtime_execution"]["post_merge_green_focused"]["assertions"],
    fleet_playback_supporting_tests=run_183_remediation["delegated_runtime_execution"]["isolated_supporting_fleet_regressions"]["tests"],
    fleet_playback_supporting_assertions=run_183_remediation["delegated_runtime_execution"]["isolated_supporting_fleet_regressions"]["assertions"],
    fleet_playback_baseline_short=run_183_remediation["pins"]["application_baseline_commit"][:12],
    fleet_playback_fix_short=run_183_remediation["pins"]["fix_commit"][:12],
    fleet_playback_merge_short=run_183_remediation["pins"]["local_main_merge_commit"][:12],
    fleet_fuel_red_failed=run_193_remediation["delegated_runtime_execution"]["baseline_original_red"]["failed"],
    fleet_fuel_red_passed=run_193_remediation["delegated_runtime_execution"]["baseline_original_red"]["passed"],
    fleet_fuel_red_assertions=run_193_remediation["delegated_runtime_execution"]["baseline_original_red"]["assertions_reported"],
    fleet_fuel_tests=run_193_remediation["delegated_runtime_execution"]["post_merge_authoritative_three_file_context"]["focused_component"]["tests"],
    fleet_fuel_assertions=run_193_remediation["delegated_runtime_execution"]["post_merge_authoritative_three_file_context"]["focused_component"]["assertions"],
    fleet_fuel_supporting_tests=run_193_remediation["delegated_runtime_execution"]["isolated_supporting_vehicle_controller_regressions"]["tests"],
    fleet_fuel_supporting_assertions=run_193_remediation["delegated_runtime_execution"]["isolated_supporting_vehicle_controller_regressions"]["assertions"],
    fleet_fuel_baseline_short=run_193_remediation["pins"]["application_baseline_commit"][:12],
    fleet_fuel_fix_short=run_193_remediation["pins"]["fix_commit"][:12],
    fleet_fuel_merge_short=run_193_remediation["pins"]["local_main_merge_commit"][:12],
    fleet_fuel_origin_short=run_193_remediation["pins"]["origin_main_observed"][:12],
    summary_red_failed=run_196_remediation["finding"]["red_reproduction"]["failed"],
    summary_red_passed=run_196_remediation["finding"]["red_reproduction"]["passed"],
    summary_red_assertions=run_196_remediation["finding"]["red_reproduction"]["assertions"],
    summary_tests=run_196_remediation["finding"]["isolated_focused_verification"]["passed"],
    summary_assertions=run_196_remediation["finding"]["isolated_focused_verification"]["assertions"],
    summary_supporting_tests=run_196_remediation["finding"]["isolated_supporting_compatibility"]["passed"],
    summary_supporting_assertions=run_196_remediation["finding"]["isolated_supporting_compatibility"]["assertions"],
    summary_shared_tests=run_196_remediation["post_merge_shared_support"]["passed"],
    summary_shared_assertions=run_196_remediation["post_merge_shared_support"]["assertions"],
    summary_baseline_short=run_196_remediation["lineage"]["baseline"]["commit"][:12],
    summary_fix_short=run_196_remediation["lineage"]["sealed_fix"]["commit"][:12],
    summary_merge_short=run_196_remediation["lineage"]["effective_merge"]["commit"][:12],
    summary_current_main_short=run_196_remediation["lineage"]["current_main"]["commit"][:12],
    summary_origin_short=findings_pins["summary_timeline_site_privacy_origin_main_observed"][:12],
    shift_task_red_failed=run_199_coordination_handoff["reproduction"]["failed"],
    shift_task_red_passed=run_199_coordination_handoff["reproduction"]["passed"],
    shift_task_red_pending=run_199_coordination_handoff["reproduction"]["pending"],
    shift_task_red_assertions=run_199_coordination_handoff["reproduction"]["assertions"],
    shift_task_intermediate_tests=run_199_coordination_handoff["verification"]["intermediate_tests"],
    shift_task_intermediate_assertions=run_199_coordination_handoff["verification"]["intermediate_assertions"],
    shift_task_tests=run_199_coordination_handoff["verification"]["post_merge"]["tests"],
    shift_task_assertions=run_199_coordination_handoff["verification"]["post_merge"]["assertions"],
    shift_task_baseline_short=run_199_coordination_handoff["pins"]["application_baseline_commit"][:12],
    shift_task_fix_short=run_199_coordination_handoff["pins"]["sealed_fix_commit"][:12],
    shift_task_merge_short=run_199_coordination_handoff["pins"]["local_main_merge_commit"][:12],
    shift_task_origin_short=run_199_coordination_handoff["pins"]["origin_main_observed"][:12],
    elig_shift_red_failed=run_201_coordination_handoff["reproduction"]["failed"],
    elig_shift_red_passed=run_201_coordination_handoff["reproduction"]["passed"],
    elig_shift_red_pending=run_201_coordination_handoff["reproduction"]["pending"],
    elig_shift_red_assertions=run_201_coordination_handoff["reproduction"]["assertions"],
    elig_shift_intermediate_tests=run_201_coordination_handoff["verification"]["intermediate"]["tests"],
    elig_shift_intermediate_assertions=run_201_coordination_handoff["verification"]["intermediate"]["assertions"],
    elig_shift_tests=run_201_coordination_handoff["verification"]["post_merge"]["tests"],
    elig_shift_assertions=run_201_coordination_handoff["verification"]["post_merge"]["assertions"],
    elig_shift_baseline_short=run_201_coordination_handoff["pins"]["application_baseline_commit"][:12],
    elig_shift_fix_short=run_201_coordination_handoff["pins"]["sealed_fix_commit"][:12],
    elig_shift_merge_short=run_201_coordination_handoff["pins"]["local_main_merge_commit"][:12],
    elig_shift_origin_short=run_201_coordination_handoff["pins"]["origin_main_observed"][:12],
    fleet_playback_data_red_failed=run_203_coordination_handoff["reproduction"]["failed"],
    fleet_playback_data_red_passed=run_203_coordination_handoff["reproduction"]["passed"],
    fleet_playback_data_red_pending=run_203_coordination_handoff["reproduction"]["pending"],
    fleet_playback_data_red_assertions=run_203_coordination_handoff["reproduction"]["assertions"],
    fleet_playback_data_isolated_tests=run_203_coordination_handoff["verification"]["isolated_focused"]["tests"],
    fleet_playback_data_isolated_assertions=run_203_coordination_handoff["verification"]["isolated_focused"]["assertions"],
    fleet_playback_data_combined_tests=run_203_coordination_handoff["verification"]["post_merge_combined"]["tests"],
    fleet_playback_data_combined_assertions=run_203_coordination_handoff["verification"]["post_merge_combined"]["assertions"],
    fleet_playback_data_tests=run_203_coordination_handoff["verification"]["post_merge_combined"]["credited_component"]["tests"],
    fleet_playback_data_assertions=run_203_coordination_handoff["verification"]["post_merge_combined"]["credited_component"]["assertions"],
    fleet_playback_data_prior_tests=run_203_coordination_handoff["verification"]["post_merge_combined"]["already_credited_playback_component"]["tests"],
    fleet_playback_data_prior_assertions=run_203_coordination_handoff["verification"]["post_merge_combined"]["already_credited_playback_component"]["assertions"],
    fleet_playback_data_supporting_tests=run_203_coordination_handoff["verification"]["post_merge_combined"]["unchanged_fleet_management_component"]["tests"],
    fleet_playback_data_supporting_assertions=run_203_coordination_handoff["verification"]["post_merge_combined"]["unchanged_fleet_management_component"]["assertions"],
    fleet_playback_data_baseline_short=run_203_coordination_handoff["pins"]["application_baseline_commit"][:12],
    fleet_playback_data_fix_short=run_203_coordination_handoff["pins"]["sealed_fix_commit"][:12],
    fleet_playback_data_merge_short=run_203_coordination_handoff["pins"]["local_main_merge_commit"][:12],
    fleet_playback_data_origin_short=run_203_coordination_handoff["pins"]["origin_main_observed"][:12],
    metric_tests=metric_finding["evidence"]["tests_executed"],
    metric_assertions=metric_finding["evidence"]["assertions"],
    metric_initial_tests=metric_finding["evidence"]["initial_superseded_tests"],
    metric_initial_assertions=metric_finding["evidence"]["initial_superseded_assertions"],
    metric_baseline_short=metric_finding["current_adjudication"]["application_baseline_commit"][:12],
    metric_initial_fix_short=metric_finding["current_adjudication"]["initial_fix_commit"][:12],
    metric_initial_merge_short=metric_finding["current_adjudication"]["initial_merge_commit"][:12],
    metric_corrective_fix_short=metric_finding["current_adjudication"]["corrective_fix_commit"][:12],
    metric_corrective_merge_short=metric_finding["current_adjudication"]["application_commit"][:12],
    metric_current_main_short=metric_finding["current_adjudication"]["current_local_main_commit"][:12],
    module_count=len(module_labels),
    module_rows=module_rows,
    finding_rows=finding_rows,
    architecture_rows=architecture_rows,
    register_physical=benchmark["project_register_current_audit"]["physical_unique_rows"],
    prompt_occurrences=benchmark["project_register_current_audit"]["prompt_listed_url_occurrences"],
    prompt_unique=benchmark["project_register_current_audit"]["prompt_unique_repositories"],
    extra_rows=benchmark["project_register_current_audit"]["historical_extra_rows_structurally_validated_local_only"],
    metadata_unique=benchmark["project_register_current_audit"]["current_official_github_metadata_unique_repository_coverage"],
    metadata_occurrences=benchmark["project_register_current_audit"]["current_official_github_metadata_prompt_occurrence_coverage"],
    triage_observer_unique=project_triage["project_universe"]["repositories"],
    triage_observer_occurrences=project_triage["project_universe"]["prompt_occurrences"],
    triage_complete=partial_resolution["counts"]["effective_observer_statuses"]["COMPLETE_OBSERVER_TRIAGE"],
    triage_partial=partial_resolution["counts"]["effective_observer_statuses"]["PARTIAL_BLOCKED"],
    partial_reviewed=partial_resolution["counts"]["reviewed_partial_records"],
    partial_resolved=partial_resolution["counts"]["resolution_decisions"]["RESOLVED_OBSERVER_EVIDENCE"],
    partial_retained=partial_resolution["counts"]["resolution_decisions"]["RETAIN_PARTIAL"],
    triage_same_head=project_triage["counts"]["metadata_head_relationships"]["SAME_AS_BASELINE"],
    triage_different_head=project_triage["counts"]["metadata_head_relationships"]["DIFFERENT_LATER_OBSERVATION_NO_ANCESTRY_INFERENCE"],
    triage_unique=benchmark["project_register_current_audit"]["current_upstream_full_triage_unique_repository_completions"],
    triage_occurrences=benchmark["project_register_current_audit"]["current_upstream_full_triage_prompt_occurrence_completions"],
    formal_attempts=formal_upstream["denominator_reconciliation"]["wave_unique_repositories"],
    formal_prompt_repos=formal_upstream["denominator_reconciliation"]["wave_prompt_repositories"],
    formal_historical=formal_upstream["denominator_reconciliation"]["wave_historical_extras"],
    formal_weight=formal_upstream["denominator_reconciliation"]["wave_prompt_occurrence_weight"],
    formal_targets=formal_upstream["target_inventory"]["target_count"],
    formal_subrecords=formal_upstream["target_inventory"]["initial_facet_aspect_subrecords"],
    formal_accepted=formal_upstream["counts"]["formal_projects_accepted"],
    formal_facets=formal_upstream["counts"]["formal_facets_accepted"],
    formal_accepted_weight=formal_upstream["denominator_reconciliation"]["accepted_prompt_occurrence_weight"],
    formal_evidence_links=formal_evidence_links,
    checkpoint_evidence_links=checkpoint_evidence_links,
    start_ready_ids=start_ready_ids,
    observer_records=benchmark["observer"]["mapping_records"],
    observer_unique=benchmark["observer"]["unique_current_candidates"],
    neutralizer_count=len(benchmark["neutralizer"]["adjudications"]),
    comparator_count=len(benchmark["native_comparator"]["comparison_packets"]),
    target_wave_targets=target_counts["wave_targets"],
    target_candidate_packets=target_counts["candidate_locator_packets"],
    target_no_candidate_packets=target_counts["bounded_no_candidate_packets"],
    target_compared=target_counts["clean_current_comparisons"],
    target_deferred=target_counts["scope_deferred_composites"],
    target_facet_targets=target_counts["facet_reconciliation_targets"],
    target_facets=target_counts["facet_reconciliation_facets"],
    target_no_go=target_counts["independent_no_go_verdicts"],
    target_edges=target_counts["formal_edges"],
    facet_wave_facets=facet_counts["selected_facet_packets"],
    facet_wave_features=facet_counts["wave_features"],
    facet_candidates=facet_counts["candidate_locators"],
    facet_no_candidates=facet_counts["bounded_no_candidates_not_final_no_match"],
    facet_exact=facet_counts["agent_a_exact_packets"],
    facet_partial=facet_counts["agent_a_partial_adjacent_packets"],
    facet_insufficient=facet_counts["agent_a_insufficient_adjacent_packets"],
    facet_atoms=facet_counts["neutral_atoms"],
    facet_consumed=facet_counts["neutral_consumed_atoms"],
    facet_unknown_atoms=facet_counts["neutral_retained_unknown_atoms"],
    facet_units=facet_counts["specification_units"],
    facet_outcomes=facet_counts["acceptance_outcomes"],
    facet_unknowns=facet_counts["explicit_unknowns_preserved"],
    facet_anchors=facet_counts["source_anchor_occurrences"],
    facet_unique_anchors=facet_counts["source_unique_anchor_strings"],
    facet_anchor_files=facet_counts["source_anchor_paths"],
    facet_ratings=facet_counts["fresh_c_lens_ratings"],
    facet_d_reviews=facet_counts["fresh_d_review_decision_counts"]["total_reviews"],
    facet_d_corrections=facet_counts["fresh_d_review_decision_counts"]["total_correct"],
    facet_edges=facet_counts["formal_edges"],
    facet_final_no_matches=facet_counts["final_no_matches"],
    promoted_count=facet_counts["promoted_feature_mappings_or_final_no_matches"],
    php_version=runtime["sanitized_environment_observations"]["php_version"],
    deployed_component=deployment["deployed_observation"]["inertia_component"],
    unknown_build_routes=deployed_selected_counts["selected_routes"],
    unknown_build_viewports=deployed_selected_counts["prompt_dimensions_sampled_on_unknown_build"],
    unknown_build_cells=deployed_selected_counts["unknown_build_route_viewport_cells"],
    unknown_build_overlays=deployed_selected_counts["unknown_build_overlay_families"],
    unknown_build_candidates=deployed_selected_counts["unknown_build_provisional_candidates"],
    route_file_count=route_gap["route_denominator"]["route_php_files"],
    nav_file_count=route_gap["navigation_denominator"]["named_navigation_tab_source_files"],
    hero_definitions=f"{visual['heroes']['definitions']:,}",
    hero_instances=f"{visual['heroes']['instances']:,}",
    overlay_definitions=f"{visual['overlays']['definitions']:,}",
    overlay_instances=f"{visual['overlays']['instances']:,}",
    declarative_triggers=f"{visual['triggers']['declarative_primitive_tags']:,}",
    direct_triggers=f"{visual['triggers']['direct_inline_opening_handler_sites']:,}",
    named_triggers=f"{visual['triggers']['local_named_handler_reference_sites']:,}",
    visual_matrix_rows=f"{visual_matrix['matrix']['rows']:,}",
    models=f"{backend['module_arithmetic']['models']['total']:,}",
    policies=f"{backend['module_arithmetic']['policies']['total']:,}",
    services=f"{backend['module_arithmetic']['service_entry_union']['total']:,}",
    jobs=f"{backend['module_arithmetic']['jobs']['total']:,}",
    events=f"{backend['module_arithmetic']['events']['total']:,}",
    listeners=f"{backend['module_arithmetic']['listeners']['total']:,}",
    migrations=f"{backend['migration_filename_primary_mapping']['total']:,}",
    php_test_files=f"{architecture_evidence['static_census']['php_test_files']:,}",
)

current_visible_boundaries = [
    '<a href="#checkpoint">RUN-203</a>',
    '<a href="#findings">Finding status</a>',
    "667 = 310 route + 357 page",
    "121 reviewed / 386 pending",
    "reviewed = 99 owned + 10 shared + 5 alias + 7 gap",
    "16.976330%",
    "3,262 records remain",
    "RUN-202–203 Fleet playback data-point eligibility checkpoint",
    "fleet-assets.vehicles.alerts-config",
    "RUN090-ROUTE-0084 / RUN077-ROUTE-0692",
    "VehicleController::alertsConfig",
    "CAP-FLEET-VEHICLE-REGISTER",
    "index 84 is not recredited",
    "index 85 fleet-assets.trips.playback is integrated",
    "next index 86 RUN090-ROUTE-0087 / RUN077-ROUTE-0695",
    "fleet-assets.trips.index",
    "fleet-assets.trips.playback",
    "fleet-assets.trips.playback.data",
    "three provisional-not-final observations",
    "RUN-168: exact RUN-167 dashboard verified at 4/4 viewports",
    "RUN-169/R: queue index 83 Fleet alerts-config candidate independently reviewed OWNER",
    "RUN-170: exactly one route owner and one action bridge integrated",
    "RUN-170R: three sealed post-commit GO reviews",
    "RUN-171: live static ledger reported",
    "RUN-172: exact RUN-171 dashboard verified at 4/4 viewports",
    "RUN-173: SAFE concern-identity defect reproduced and remediated in exactly two transferred paths",
    "RUN-173R: exact remediation artifacts independently reviewed GO",
    "RUN-174: SAFE record reclassified in place",
    "RUN-175: exact RUN-174 dashboard verified at 4/4 viewports",
    "RUN-176: Fleet trip-index Site-privacy defect reproduced and remediated in exactly two transferred paths",
    "RUN-176R: exact remediation artifacts independently reviewed GO",
    "RUN-177: Fleet trip privacy historical-remediated record added",
    "RUN-178: exact RUN-177 dashboard verified at 4/4 viewports",
    "RUN-179/R: index 84 Fleet trip-index review preserves two invalidated older-bundle SHARED judgments",
    "RUN-180: exactly one fleet-assets.trips.index route owner and VehicleController::trips bridge integrated",
    "RUN-180R: three sealed GO lanes",
    "complete synthesis authorizes reporting only",
    "RUN-181: live static ledger reported",
    "RUN-182: exact RUN-181 dashboard verified at 4/4 viewports · 105/105 visible checks · 10/10 navigation · 455/455 unique local resources · 852 anchors",
    "RUN-183: Fleet trip-playback page/data Site-privacy defect reproduced and remediated in exactly two transferred paths",
    "RUN-183R: exact remediation artifacts independently reviewed GO",
    "RUN-184: Fleet trip-playback historical-remediated record added",
    "RUN-185: exact RUN-184 dashboard verified at 4/4 viewports",
    "117/117 visible checks",
    "463/463 resources",
    "868 anchors",
    "RUN-186: MON-METRIC-REPLAY-DEDUPE-01 initial remediation later adjudicated NO-GO and corrective remediation integrated",
    "only final post-corrective-merge 56/472 counted once",
    "initial 49/392 and all replays/subsets/DNS/Facility excluded",
    "RUN-186R: exact artifacts independently reviewed GO",
    "null feature and candidate IDs",
    "zero static ownership",
    "RUN-187: Monitoring metric replay historical-remediated record added",
    "RUN-188: exact RUN-187 dashboard verified at 4/4 viewports",
    "152/152 visible checks",
    "471/471 resources",
    "888 anchors",
    "RUN-189/R: index 85 fleet-assets.trips.playback / FleetTripController::show independently reviewed OWNER twice",
    "RUN-190: exactly one playback/show route owner and controller-action bridge integrated",
    "RUN-190R: two sealed post-commit GO reviews",
    "RUN-191: live static ledger reported",
    "RUN-192: exact RUN-191 dashboard verified at 4/4 viewports",
    "30/30 named visible checks per viewport",
    "10/10 navigation · 476/476 resources · 893 anchors",
    "RUN-193: Fleet Fuel index/CSV Site-privacy defect reproduced and remediated in exactly two transferred paths",
    "RUN-193R: exact remediation artifacts independently reviewed GO",
    "RUN-194: Fuel historical-remediated record added",
    "RUN-195: exact RUN-194 dashboard verified at 4/4 viewports",
    "39/39 named visible checks per viewport",
    "10/10 navigation · 491/491 resources · 944 anchors",
    "RUN-196: Summary/timeline Site-privacy defect reproduced and remediated in exactly four transferred paths",
    "RUN-196R: exact remediation artifacts independently reviewed GO by three read-only reviewers",
    "RUN-197: Summary/timeline historical-remediated record added",
    "RUN-198: exact RUN-197 dashboard verified at 4/4 viewports",
    "48/48 named visible checks per viewport",
    "10/10 navigation · 499/499 resources · 969 anchors",
    "RUN-199: Shift-task due recipient-revalidation reproduced and remediated in exactly four paths",
    "RUN-202: exact RUN-201 dashboard verified at 4/4 viewports",
    "RUN-203: Fleet playback data-point eligibility reproduced and remediated in exactly two paths",
    "20 retained claim identities split into 8 current provisional P1, 2 historical already-fixed, and 10 historical remediated",
    "199 / 2,722",
    "199/2,722 unique bounded disposition total",
    "RUN-071–203 current reporting checkpoint",
    "RUN-071–203 completion-gate checkpoint",
    "RUN-071–203 evidence lineage",
    "Every current raw, generated, reviewed, and integrated RUN-077–203",
    "RUN-186 corrected Monitoring metric-replay execution",
    "final post-corrective-merge Monitoring 56/472",
    "initial Monitoring 49/392",
    "RUN-188 responsive audit-dashboard verification receipt</a> (verified and hashed in the current evidence list)",
    "RUN-190 Fleet playback/show ownership-overlay receipt",
    "RUN-190R independent overlay-review receipt",
    "RUN-191 reporting receipt",
    "RUN-192 responsive audit-dashboard verification receipt</a> (verified and hashed in the current evidence list)",
    "RUN-193 Fleet Fuel remediation receipt",
    "RUN-193R independent Fuel review receipt",
    "RUN-194 reporting receipt",
    "RUN-195 responsive audit-dashboard verification receipt</a> (verified and hashed in the current evidence list)",
    "RUN-196 Summary/timeline remediation receipt",
    "RUN-196R independent Summary/timeline review receipt",
    "RUN-197 reporting receipt",
    "RUN-198 responsive audit-dashboard verification receipt</a> (verified and hashed in the current evidence list)",
    "RUN-199 Shift-task coordination-handoff transcription",
    "RUN-199 reporting materializer",
    "RUN-199 reporting receipt",
    "RUN-200 exact RUN-199 audit-dashboard verification",
    "RUN-201 Shift eligibility-alert coordination-handoff transcription",
    "RUN-201 Shift eligibility-alert remediation-reporting materializer",
    "RUN-201 Shift eligibility-alert remediation-reporting receipt",
    "RUN-202 exact RUN-201 audit-dashboard verification materializer",
    "RUN-202 exact RUN-201 audit-dashboard verification",
    "RUN-203 Fleet playback data-point eligibility coordination-handoff transcription",
    "RUN-203 Fleet playback data-point eligibility remediation-reporting materializer",
    "RUN-203 Fleet playback data-point eligibility remediation-reporting receipt",
    "dashboard HTML frozen pending RUN-204",
    "MON-METRIC-REPLAY-DEDUPE-01",
    "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW",
    "MON-METRIC-REPLAY-DEDUPE-01 is also remediated on local main only and unpublished",
    "quiesce old monitoring workers",
    "reconcile pending or incoherent rows",
    "apply migration 000110",
    "start new workers only after cutover reconciliation",
    "poisoned subsecond evidence requires operator reconciliation",
    "a900f078c9c0",
    "f521bc0b8722",
    "778c00a5d095",
    "c82f57779baf",
    "18652d545c78",
    "f938c6d989f5",
    "9 current provisional P1 + 2 historical already-fixed + 1 historical remediated",
    "8 current provisional P1 + 2 historical already-fixed + 2 historical remediated",
    "14 retained claim identities split into 8 current provisional P1, 2 historical already-fixed, and 4 historical remediated",
    "RUN-176's post-merge Fleet 5/175 counted once into 88/1,764",
    "88 / 1,764",
    "83/1,589 unique bounded total",
    "88/1,764 unique bounded disposition total",
    "99/1,931 unique bounded disposition total",
    "SAFE-ALERT-DEDUP-IDENTITY-01",
    "post-merge SAFE alert-dedup tests / 60 assertions",
    "4 failed + 1 warning-pass / 10 assertions",
    "supporting 28/73 bridge and 3/5 HsEvent evidence reported separately",
    "6 terminal-fixture failures occurred before bridge/dedup execution",
    "30-minute dedup window",
    "+5 minutes stays idempotent",
    "+31-minute lifecycle remains unchanged",
    "e488bd3edcda",
    "dc04067e304a",
    "705db2dc3ba0",
    "c39b07654705",
    "a8d813f1878c6a720f5308f28e5a591f90097961444876f93fcfe5a9262e909a",
    "not published to origin/main",
    "FLEET-TRIP-INDEX-SITE-PRIVACY-01",
    "candidate feature association only",
    "index 84 static route owner and action bridge integrated separately by RUN-180/R–181",
    "selected playback/show route owner and bridge integrated separately by RUN-190",
    "sibling playback.data at index 86 pending",
    "root red 2 failed / 19 assertions",
    "expanded red 5 failed / 55 assertions",
    "post-merge Fleet trip-index tests / 175 assertions",
    "supporting 4/35 VehicleController regressions reported separately",
    "FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01",
    "page/data Site privacy only",
    "baseline 3 failed + 2 passed / 30 assertions",
    "RUN-183 adds one post-merge 11/167 Fleet playback execution",
    "supporting 20/215",
    "FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01",
    "RUN-203 Fleet playback data-point eligibility execution",
    "coordinate-complete rows before ordering and the 2,000-point cap only",
    "candidate/reporting association to CAP-FLEET-VEHICLE-REGISTER",
    "index 86 remains pending fresh semantic review",
    "only the new post-merge 1/6 regression component counted once",
    "environment-invalid shared-vendor/classmap attempt",
    "prior playback 11/167",
    "unchanged FleetManagement 15/40",
    "baseline 9c01f5a4f57f · fix 9c40c51a2604 · local merge ba39cbc36694 · origin/main c39b07654705 unchanged",
    "FLEET-FUEL-INDEX-SITE-PRIVACY-01",
    "selected GET index/CSV rows",
    "month-to-date totals",
    "rolling 30-day entry count",
    "row-scoped attached identity only",
    "baseline 6 failed + 0 passed / 65 assertions",
    "unique post-merge focused 6/206",
    "supporting 20/215 regressions",
    "candidate index 87 pending behind index 86",
    "baseline df65322f8eb7 · fix 2ec4b70e379c · local merge 04c32c36fdda · origin/main c39b07654705 unchanged",
    "SUMMARY-TIMELINE-SITE-PRIVACY-01",
    "RUN-196 Summary/timeline Site-privacy execution",
    "feature unassigned",
    "other-staff reads require active shared-Site scope unless hr.employees.viewAllSites",
    "baseline 39a5d97d7d0f · fix 31a9edfbab32 · local merge 5c8a1357f830 · current main 44ab5e270aec · origin/main c39b07654705 unchanged",
    "15 focused tests / 32 assertions counted once",
    "eMAR 2/238 support",
    "shared post-merge 40/438",
    "SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01",
    "RUN-199 Shift-task due recipient-revalidation execution",
    "scheduler-time denial leaves marker null and emits neither notification nor Facility signal",
    "queue-time denial suppresses delivery without clearing a claimed marker or retracting a signal",
    "one post-merge eligibility-alert 13/25",
    "delegated coordination transcription, not an original runtime receipt",
    "baseline 47a6d231c52a · fix 6186176d30a9 · local merge e2593cbdd079 · origin/main c39b07654705 unchanged",
    "14 retained",
    "13a7f37da9c9",
    "790bc11e3fb2",
    "c643c9e5eecf",
    "3 claim-specific test functions / 146 assertions / 3 synchronized two-process races",
    "supporting 43/716 overlaps",
    "73 bounded tests / 1,481 assertions",
    "5 focused tests / 48 assertions",
    "3 independent current-source ALREADY_FIXED reviews",
    "historical issue · already fixed on current main · not a final finding",
    "historical issue · already fixed on current main only for the bounded manual-entry register/stock clause · residual compound scope unadjudicated · not a final finding",
    "historical issue · remediated on current main · not a final finding",
    "historical issue · remediated on local main · not published to origin/main · 30-minute dedup contract and +31-minute lifecycle preserved · not a final finding",
    "MED-CD-SCOPE-01",
    "MED-CD-ATOMICITY-01",
    "RUN-166 separately establishes bounded manual-entry MED-CD-ATOMICITY execution without application or product-test change",
    "balance-check, destruction, delivery/adjustment/loss and sibling-writer, forced transient-deadlock retry, and stress/repeated-schedule scope remains unadjudicated",
    "two broader INR failures reproduce at base",
    "full-suite green false",
    "Fresh RUN-204 audit-dashboard verification required",
    "cf0090ec9724",
    "0b1920dade92",
    "7b2b5688c90e",
    "2/340 mappings",
    "0/340 final no-match/NCM",
    "338 unresolved targets",
    "one operating organisation across multiple Sites",
    "Gate 4 and audit completion false",
    "RUN-153/R establish the historical 664 bounded source-owner records (307 routes + 357 pages)",
    "at that historical checkpoint 116 queue rows were reviewed, 391 remained pending, and 413 remained without ownership",
    "662 historical cumulative owner records",
    "RUN-190/R current Fleet playback/show route/action ownership",
    "RUN-090 frozen denominator / RUN-190R current accounting",
    "index 84 is not recredited, index 85 fleet-assets.trips.playback is integrated, and index 86 fleet-assets.trips.playback.data is next",
    "RUN-168 verifies that exact dashboard",
    "RUN-202 verifies only the superseded RUN-201 HTML",
    "exact dashboard later verified by RUN-185",
    "visible 667/310/357 ownership, 98 bridges, 121/386 queue accounting, 99 owned/408 without ownership",
    "None supplies audit-dashboard verification for the new RUN-204 HTML.",
    "The linked RUN-204 receipt must record",
    "It verifies the RUN-203 audit artifact only",
    "RUN-188 exact RUN-187 audit-dashboard verification materializer",
    "RUN-194 Fleet Fuel remediation-reporting receipt",
    "RUN-198 audit-dashboard verification materializer",
]
missing_current_visible_boundaries = [
    boundary for boundary in current_visible_boundaries if boundary not in dashboard
]
assert not missing_current_visible_boundaries, missing_current_visible_boundaries
assert '<a href="#checkpoint">RUN-191</a>' not in dashboard
assert '<a href="#checkpoint">RUN-201</a>' not in dashboard
for stale_current in (
    "RUN-071–191 current reporting checkpoint",
    "RUN-071–191 completion-gate checkpoint",
    "RUN-071–191 evidence lineage",
    "Every current raw, generated, reviewed, and integrated RUN-077–191",
    "current RUN-191 split of 15 retained claim identities",
    "Fresh RUN-192 audit-dashboard verification required",
    "current RUN-177 reporting sources or the RUN-178 dashboard",
    "new RUN-188 HTML",
    "The linked RUN-188 receipt must record",
    "It verifies the RUN-187 audit artifact only",
    '<a href="generators/materialize-run-188-audit-dashboard-verification-wave-36.py">RUN-188 audit-dashboard verification materializer</a> <span>forward generator',
    '<a href="evidence/browser/current-audit-dashboard-verification-run-188-wave-36.json">RUN-188 audit-dashboard verification receipt</a> <span>forward receipt',
    "current RUN-194 reporting sources or the RUN-195 dashboard generated from them",
    "RUN-195 remains the fresh exact-dashboard gate.",
    '<a href="#checkpoint">RUN-197</a>',
    "current RUN-197 split of 17 retained claim identities",
    "Fresh RUN-198 audit-dashboard verification required",
    "current RUN-197 reporting sources or the RUN-198 dashboard generated from them",
    "None supplies audit-dashboard verification for the new RUN-198 HTML.",
    "The linked RUN-198 receipt must record",
    "It verifies the RUN-197 audit artifact only",
    '<a href="generators/materialize-run-198-audit-dashboard-verification-wave-39.py">RUN-198 audit-dashboard verification materializer</a> <span>forward generator',
    '<a href="evidence/browser/current-audit-dashboard-verification-run-198-wave-39.json">RUN-198 audit-dashboard verification receipt</a> <span>forward receipt',
    "RUN-071–201 current reporting checkpoint",
    "RUN-071–201 completion-gate checkpoint",
    "RUN-071–201 evidence lineage",
    "Every current raw, generated, reviewed, and integrated RUN-077–201",
    "current RUN-201 split of 19 retained claim identities",
    "Fresh RUN-202 audit-dashboard verification required",
    "current RUN-201 reporting sources or the RUN-202 dashboard generated from them",
    "None supplies audit-dashboard verification for the new RUN-202 HTML.",
    "The linked RUN-202 receipt must record",
    "It verifies the RUN-201 audit artifact only",
    '<a href="generators/materialize-run-202-audit-dashboard-verification-wave-41.py">RUN-202 audit-dashboard verification materializer</a> <span>forward generator',
    '<a href="evidence/browser/current-audit-dashboard-verification-run-202-wave-41.json">RUN-202 audit-dashboard verification receipt</a> <span>forward receipt',
):
    assert stale_current not in dashboard
assert "0 current application tests" not in dashboard
assert "vendor absent; setup not run" not in dashboard
assert "Fresh RUN-158 audit-dashboard verification required" not in dashboard
assert "RUN-071–157 current reporting checkpoint" not in dashboard
assert '<a href="#findings">Provisional findings</a>' not in dashboard
assert "RUN-159/R" not in dashboard
assert "11 current provisional P1 + 1 historical already-fixed" in dashboard  # preserved historical RUN-160 checkpoint only
assert "10 current provisional P1 + 1 historical already-fixed + 1 historical remediated" in dashboard  # preserved historical RUN-163 checkpoint only
assert "Fresh RUN-161 audit-reporting correction" not in dashboard
assert "Fresh RUN-164 audit-dashboard verification required" not in dashboard
assert "Fresh RUN-168 audit-dashboard verification required" not in dashboard
assert "Fresh RUN-172 audit-dashboard verification required" not in dashboard
assert '<a href="#checkpoint">RUN-167</a>' not in dashboard
assert '<a href="#checkpoint">RUN-171</a>' not in dashboard
assert '<a href="#checkpoint">RUN-174</a>' not in dashboard
assert '<a href="#checkpoint">RUN-177</a>' not in dashboard
assert '<a href="#checkpoint">RUN-181</a>' not in dashboard
assert "RUN-071–171 current reporting checkpoint" not in dashboard
assert "RUN-071–174 current reporting checkpoint" not in dashboard
assert "RUN-158–160 current adjudication checkpoint" not in dashboard
assert "RUN-161–163 current remediation and reporting checkpoint" not in dashboard
assert "MED-CD-SCOPE-01 and MED-CD-ATOMICITY-01 remain separate current provisional claims" not in dashboard
assert "MED-CD-ATOMICITY-01</span> remains current provisional" not in dashboard
assert "RUN-160 reports 12 retained identities" not in dashboard
assert "10 current provisional P1 and 1 historical already-fixed, RUN-159" not in dashboard
assert "latest bounded MED-RBAC adjudication" not in dashboard
assert "RUN-153/R establish 665 bounded source-owner records" not in dashboard
assert "RUN-153/R current Fleet vehicle-register" not in dashboard
assert "RUN-157 current reporting refresh" not in dashboard
assert "<tr><td>RUN-090 direct-exact queue</td>" not in dashboard
assert "fresh RUN-158 dashboard verification required" not in dashboard
assert "RUN-142/R: one route row and one bridge integrated and independently verified · zero page/sibling/caller/neighbor/next-row inheritance · 665 cumulative owner records" not in dashboard
assert "RUN-153/R establish 665 bounded source-owner records and 96 action bridges" not in dashboard
assert "RUN-170/R later establish the current 667" not in dashboard
assert "RUN-170/R current queue accounting is 121" not in dashboard
assert "RUN-170/R Fleet alerts-config overlay</td><td><strong>667 owners" not in dashboard
assert "RUN-171 live reporting</td><td><strong>121 reviewed" not in dashboard
assert (
    "RUN-181: live static ledger reported as 666 owners / 309 routes / 357 pages / "
    "97 bridges · 120 reviewed / 387 pending / 409 without ownership"
    in dashboard
)
assert (
    "<tr><td>RUN-181 live reporting</td><td><strong>666 owners / 309 routes / "
    "357 pages / 97 bridges · 120 reviewed / 387 pending / 409 without ownership"
    in dashboard
)
assert "RUN-181: live static ledger reported as 667 owners" not in dashboard
assert "<tr><td>RUN-181 live reporting</td><td><strong>667 owners" not in dashboard
assert "RUN-190/R current Fleet playback/show route/action ownership</td><td>667 = 310 route + 357 page · 256 FEATURE-IDs = 234 H + 22 D · 98 action bridges</td><td class=\"partial\">16.976330% of bounded 3,929 · 3,262 residual · features 64 route / 242 page / 50 overlap · routes 3,218 = 310 owner + 12 shared + 5 alias + 2,891 residual with 7 tagged gaps · pages 711 = 357 owner + 9 shared + 345 residual with 1 tagged gap · Fleet trip-index wave 34" not in dashboard
assert "integrating indexes 83 and 84 while leaving index 85 fleet-assets.trips.playback pending" not in dashboard
assert "candidate feature association and index 85 ownership PENDING_FRESH_SEMANTIC_REVIEW" not in dashboard
assert "and fresh RUN-168 audit-dashboard verification required." not in dashboard
assert "and fresh RUN-172 dashboard verification required." not in dashboard
assert "Fresh RUN-175 audit-dashboard verification required" not in dashboard
assert "Fresh RUN-178 audit-dashboard verification required" not in dashboard
assert "Fresh RUN-182 audit-dashboard verification required" not in dashboard
assert "RUN-071–184 current reporting checkpoint" not in dashboard
assert "RUN-071–184 completion-gate checkpoint" not in dashboard
assert "RUN-071–184 evidence lineage" not in dashboard
assert "None supplies audit-dashboard verification for the new RUN-185 HTML." not in dashboard
assert (
    "RUN-185 responsive audit-dashboard verification receipt</a> (forward reference until materialized; intentionally unhashed)"
    not in dashboard
)
assert "Every current raw, generated, reviewed, and integrated RUN-077–183R" not in dashboard
assert (
    "12 retained claim identities (8 current provisional + 2 historical already-fixed + 4 historical remediated)"
    not in dashboard
)
assert "visible 664/307/357 ownership, 95 bridges, 118/389 queue accounting" not in dashboard
for stale_attribution in (
    "RUN-159/R retire only historical MED-RBAC",
    "historical MED-RBAC identity retired from current provisional queue",
    "MED-RBAC-only retirement",
    "RUN-159/R retire only",
    "bounded MED-RBAC retirement evidence",
    "exact MED-RBAC-only retirement",
    "RUN-159/R authorize MED-RBAC retirement after bounded source/runtime evidence",
    "RUN-159/R authorize MED-RBAC retirement",
    "RUN-162/R retires MED-CD-SCOPE-01",
    "RUN-162/R closes MED-CD-SCOPE-01",
    "RUN-162/R remediates MED-CD-SCOPE-01",
    "MED-CD-SCOPE-01 closed",
    "MED-CD-SCOPE-01 final finding",
    "no represented wave grants current-source application runtime, signed-in browser, executed-test",
):
    assert stale_attribution not in dashboard

output_path = AUDIT_DIR / "audit-dashboard.html"
output_bytes = (dashboard.rstrip() + "\n").encode("utf-8")
existing_output_bytes = output_path.read_bytes()
assert (
    existing_output_bytes in (
        run_185_dashboard_payload,
        run_188_dashboard_payload,
        run_192_dashboard_payload,
        run_195_dashboard_payload,
        run_198_dashboard_payload,
        run_200_dashboard_payload,
        run_202_dashboard_payload,
        output_bytes,
    )
    or hashlib.sha256(existing_output_bytes).hexdigest()
    in {
        "decae30e09dae8f239ebb00ecb758bdf290edd5d4ca8f69904686c8f7a67d5c2",
        "5548a5cb461c2c13ef237a2c622db597289c6bfc9f14bb992a3c89a498a4666a",
        "966c93ed940d2fb58e4510e65442b10faab2ea5d966e66abb4acc2695fb1a091",
    }
)
temporary_path = output_path.with_name(f".{output_path.name}.tmp-run204-dashboard")
assert not temporary_path.exists(), f"Refusing to overwrite stale dashboard temp: {temporary_path}"
try:
    with temporary_path.open("xb") as handle:
        handle.write(output_bytes)
        handle.flush()
        os.fsync(handle.fileno())
    assert temporary_path.read_bytes() == output_bytes
    os.replace(temporary_path, output_path)
finally:
    if temporary_path.exists():
        temporary_path.unlink()
assert output_path.read_bytes() == output_bytes
