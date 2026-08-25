#!/usr/bin/env python3
"""Build the current audit progress dashboard from normalized evidence JSON."""

from __future__ import annotations

import csv
import hashlib
import html
import json
import os
from collections import Counter
from pathlib import Path
from string import Template


AUDIT_DIR = Path(__file__).resolve().parents[1]


def read_json(relative: str) -> dict:
    return json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))


def sha256_file(relative: str) -> str:
    path = AUDIT_DIR / relative
    assert path.is_file()
    return hashlib.sha256(path.read_bytes()).hexdigest()


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
assert sha256_file("evidence/source/current-canonical-feature-identity-wave-01.json") == "f4feae2598622afe346b1163fed2bb842305a8d973a89ec890c02746d99b5999"
assert sha256_file("evidence/source/current-canonical-identity-agent-register.json") == "21ebd8b004b5ade11aa01281958cda2be2ca966d1fb7c46576e039fab5f47baf"
assert static_linkage_integration["matrix"]["updated_sha256"] == route_page_manifest["pins"]["inputs"]["03-feature-to-benchmark-matrix.csv"]
assert sha256_file("03-feature-to-benchmark-matrix.csv") == route_page_integration["matrix"]["updated_sha256"]
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
assert sha256_file("06-open-source-benchmark-register.csv") == "cc493cd1807e62a9ffa27192c658400e697391b7a0baa3f0014628145c6b7b91"
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
    if path != "03-feature-to-benchmark-matrix.csv"
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
assert route_page_integration["matrix"]["updated_sha256"] == sha256_file("03-feature-to-benchmark-matrix.csv")
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
assert route_page_reporting["pins"]["updated_matrix_sha256"] == sha256_file("03-feature-to-benchmark-matrix.csv")
assert all(sha256_file(path) == digest for path, digest in route_page_reporting["inputs"].items())
assert all(sha256_file(path) == row["sha256"] for path, row in route_page_reporting["artifact_register"].items())
assert all(sha256_file(path) == digest for path, digest in route_page_reporting["generator"].items())
assert sha256_file("evidence/source/current-route-page-reporting-materialization-wave-07.json") == "d075bc06da962d932351cb653f3a34dd88cbfc6272488fe06bc26ab61c80e55a"
assert all(value is False for value in route_page_reporting["credit_boundary"].values())

assert route_page_candidate["run_id"] == "RUN-082-EXACT-OWNER-CONTAINMENT-CANDIDATE-CENSUS"
assert route_page_candidate["status"] == "STATIC_CANDIDATE_RELATIONS_MATERIALIZED_PENDING_INDEPENDENT_REVIEW_ZERO_CREDIT"
assert route_page_candidate["pins"]["checkpoint_commit"] == "35a5228b26c54684718495c33281b24c0992de02"
assert route_page_candidate["pins"]["checkpoint_tree"] == "8ba4e28575cdb53682824a9ae604c718646d8a18"
assert route_page_candidate["pins"]["application_commit"] == canonical["source_pin"]["application_commit"]
assert route_page_candidate["pins"]["application_tree"] == canonical["source_pin"]["application_tree"]
assert route_page_candidate["pins"]["matrix_sha256"] == sha256_file("03-feature-to-benchmark-matrix.csv")
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
assert route_page_candidate_reporting["pins"]["matrix_sha256"] == sha256_file("03-feature-to-benchmark-matrix.csv")
assert sha256_file("evidence/source/current-route-page-candidate-reporting-materialization-wave-08.json") == "cac8c8d96d8d4efdf1091344a0defe0539ffa772657e4f7b301638387c377193"
assert sha256_file("generators/materialize-route-page-candidate-reporting-wave-08.py") == "3bb43f2cb852a5cc6656682f78605470b1d1af5f1268b9780cc5a76701d856f1"
assert all(sha256_file(path) == digest for path, digest in route_page_candidate_reporting["inputs"].items())
assert all(sha256_file(path) == row["sha256"] for path, row in route_page_candidate_reporting["artifact_register"].items())
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
assert formal_upstream["register_immutability"]["after_sha256"] == sha256_file("06-open-source-benchmark-register.csv")
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
    ("Current provisional findings contract", "findings.json"),
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
findings = wave1["provisional_findings"] + wave2["provisional_findings"]
assert len(findings) == 12

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

finding_rows = "".join(
    "<tr><td class=\"mono\">{}</td><td>{}</td><td class=\"partial\">independent review pending</td></tr>".format(
        html.escape(row["finding_id"]),
        html.escape(row["source_claim"]),
    )
    for row in findings
)

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
    .cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.card,.panel{background:var(--panel);border:1px solid var(--line);box-shadow:var(--shadow);border-radius:14px}.card{padding:17px}.card strong{display:block;font-size:1.65rem;line-height:1.15}.card span{display:block;color:var(--muted);margin-top:5px}.card small{display:block;margin-top:9px;color:#717d90}.panel{min-width:0;padding:20px;margin-top:20px}.panel h2{font-size:1.25rem;margin:0 0 5px}.panel>p{color:var(--muted);margin:0 0 16px}.table-wrap{max-width:100%;overflow-x:auto;border:1px solid var(--line);border-radius:10px}table{width:100%;border-collapse:collapse;min-width:680px}th,td{text-align:left;padding:11px 12px;border-bottom:1px solid var(--line);vertical-align:top}th{background:#f7f8fc;color:#414d62;font-size:.82rem}tr:last-child td{border-bottom:0}.zero{color:#a03920;font-weight:800}.partial{color:var(--warn);font-weight:800}.split{display:grid;grid-template-columns:1.15fr .85fr;gap:20px}.split>*{min-width:0}.list{margin:0;padding-left:20px}.list li,.list code{overflow-wrap:anywhere}.list li+li{margin-top:8px}.mono{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:.88em;overflow-wrap:anywhere}.footer{color:var(--muted);font-size:.88rem;margin-top:24px}
    @media(max-width:900px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.split{grid-template-columns:1fr}.hero{align-items:flex-start;flex-direction:column}.badge{align-self:flex-start}}@media(max-width:520px){header{padding:22px 16px 26px}main{padding:18px 14px 48px}.cards{grid-template-columns:1fr 1fr;gap:10px}.card{padding:14px}.card strong{font-size:1.35rem}.panel{padding:16px}.badge{white-space:normal}nav div{padding-inline:16px}}
  </style>
</head>
<body>
  <header><div class="eyebrow">Oblivion Findings · comprehensive audit restart</div><div class="hero"><div><h1>Fresh current-source audit</h1><p>Evidence is pinned to application commit <span class="mono">$application_short</span>. Historical percentages remain provenance only.</p></div><div class="badge">IN PROGRESS · NOT COMPREHENSIVE</div></div></header>
  <nav aria-label="Audit sections"><div><a href="#progress">Progress</a><a href="#checkpoint">RUN-123</a><a href="#pages">Pages</a><a href="#static-census">Static census</a><a href="#runtime">Runtime gates</a><a href="#benchmarks">Benchmarks</a><a href="#modules">Modules</a><a href="#findings">Provisional findings</a><a href="#architecture">Architecture</a><a href="#gaps">Gaps</a></div></nav>
  <main>
    <div class="notice" role="status"><strong>No completion claim:</strong> RUN-030 freezes 340 current-source static canonical targets (300 H · 40 D · 0 M). RUN-034–038 retain 88 complete observer-only and 7 partial project records without formal triage credit. RUN-039–046 approve 0 formal edges for the first six-target overlay. RUN-047–052 remain an immutable historical diagnostic checkpoint with a missing clean Agent A-to-B handoff. RUN-053–057 reconstruct that handoff through 24 selected facet packets (8 exact · 4 partial-adjacent · 12 insufficient-adjacent), 252 blind atoms (165 consumed · 87 retained unknown), 144 fresh-C lens ratings, and 226 fresh-D reviews. D accepts 225 reviews and makes one bounded correction to AO-A53-024-01; it creates 0 formal edges and 0 final no-matches. RUN-058-BROWSER–060 preserve a read-only signed-in observation of $unknown_build_routes selected routes, $unknown_build_cells route/viewport cells, and $unknown_build_overlays overlay families on an unattributed deployed build; $unknown_build_candidates provisional candidates remain unknown-build only. Formal upstream RUN-058A–070 preserves $formal_attempts initial project records across $formal_targets selected targets and $formal_subrecords initial facet/aspect records; independent controls accept $formal_accepted formal project records and $formal_facets bounded facet records, while all target edges and final no-matches remain zero. RUN-077–080 record $route_primary_calls primary route-facade callsites plus one separate route-like sentinel, $route_name_calls fluent-name callsites, and $route_page_roots page roots; three cyclic independent reviews are GO, but $route_unmapped route-like rows and $page_evidence_gaps page roots retain explicit evidence gaps. RUN-080 changes only $route_page_rows_changed rows / $route_page_field_changes route-name or page-file fields. RUN-082 adds candidate relations only: route names $candidate_name_one single · $candidate_name_many multiple · $candidate_name_zero none; exact controller-method containment $candidate_backend_one single · $candidate_backend_many multiple · $candidate_backend_zero none across $candidate_exact_actions resolved actions, with $candidate_non_exact_actions non-exact retained; page render-owner containment $candidate_page_one single · $candidate_page_many multiple · $candidate_page_zero none. RUN-082R independently reproduced the candidate census and static registration closure with zero discrepancies and returned GO limited to candidate-only static evidence; feature mapping, matrix mutation, and downstream integration remain unauthorized. RUN-084/R then independently close the $full_page_tree_files-file page-tree structural ledger ($full_page_production = $full_page_roots + $full_page_support + $full_page_nonroots), and RUN-084B/BR independently close the $backend_role_rows-row backend structural ledger while whole-file semantic review stays 0. RUN-089 confirms the controlled application tab is still signed out and build-unattributed. RUN-091/R and RUN-092/R remain the historical nine-chain overlay. RUN-097/R–100 remain the historical 23-owner route/action checkpoint and its exact superseded dashboard verification. RUN-101/R–120 remain historical route/action, page-owner, reporting, and exact dashboard checkpoints. RUN-121/R review $finance_wave_reviewed Finance route actions as $finance_review_owner owners, $finance_review_shared shared, $finance_review_alias alias, $finance_review_dead dead, and $finance_review_gap evidence gaps; RUN-122/R establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges. Only seven account actions add route ownership; all 15 non-owner outcomes and zero page credit are preserved. $static_residual records remain and Gate 4 is open. Oblivion Findings remains one operating organisation across multiple Sites; Site access, roles/permissions, canonical ownership, direct-object denial, privacy, and lifecycle correctness remain separate unproved gates. The live matrix is unchanged at <span class="mono">$route_page_matrix_short</span>, mapping remains 0/340, and current-source framework reachability, runtime, browser, build, rendered visual, executed-test, benchmark, ease, release, Pass, and audit-completion credit remain zero.</div>
    <div class="notice" role="status"><strong>RUN-071–123 current reporting checkpoint:</strong> all 26 completion gates are reconciled. RUN-071's 9/18 and RUN-072's 11/18 are historical snapshots; RUN-073 has all 18 prompt-required files/directories present, including <span class="mono">evidence/</span> and excluding this generated dashboard. Presence is not completion. RUN-072 retains 300/300 source-bound H contracts pinned to the historical base matrix, with 0 validated tasks and every current/target measurement <span class="mono">NOT_MEASURED</span>; their copied locators were not silently refreshed. RUN-073 adds 8 independently source-reviewed journeys and separately reviewed architecture evidence. RUN-074–076 reconstruct feature-side linkage. RUN-077–079 materialize and independently review the exhaustive committed static route/name/page universe; RUN-080 integrates 78 route-name and 2 page-file fields; RUN-081 refreshes reports and hashes. RUN-082 materializes static candidate relations and 38/38 route-file source registration closure; RUN-082R independently reproduces them with zero discrepancies and GO limited to candidate-only static evidence; RUN-083 refreshes five reports while preserving five byte-identical and its exact dashboard receives an artifact-only GO receipt. RUN-084/R independently enumerate and review $full_page_tree_files physical page-tree files, including the production partition $full_page_production = $full_page_roots roots + $full_page_support imported supports + $full_page_nonroots adjudicated non-roots. RUN-084B/BR independently enumerate and structurally review $backend_role_rows backend role rows across $backend_unique_paths unique source paths; all remain <span class="mono">Evidence gap</span> with 0 whole-file semantic reviews. The current designated-application preflight is signed out and build-unattributed, so it adds no application-browser credit. The matrix changes 0 rows / 0 cells and retains $route_gap_count route-path, $route_name_gap_count route-name, $page_gap_count page-file, $both_gap_count combined route/page, $backend_gap_count backend-anchor, and $test_gap_count test-anchor gaps. Missing dependencies, build provenance, database isolation, and attributable authenticated access keep framework/runtime/build/test/browser lanes NO-GO and not executed. RUN-090 freezes $queue_records candidate rows without wholesale ownership. RUN-091/R–092/R remain the historical nine-owner/two-shared overlay. RUN-097/R–100 remain the historical 23-owner checkpoint and dashboard receipt. RUN-101/R–112 remain historical route/action and page-owner checkpoints with exact dashboard receipts. RUN-113/R–120 preserve the historical name-only route/action and Respite page-owner checkpoints with exact dashboard receipts. RUN-121/R–122/R add $finance_review_owner Finance route owners and seven bridges, preserve $finance_review_shared shared, $finance_review_alias alias, and $finance_review_gap gap outcomes, and add zero page owners; $queue_reviewed queue rows are reviewed, $queue_pending remain pending, and $queue_without_owner remain without ownership. RUN-123 reports only that bounded delta. The framework-expanded denominator, residual ownership, and full route/page/backend crosswalk remain open. Every execution, benchmark, Pass, finding, and completion credit remains zero.</div>
    <section id="progress" class="cards" aria-label="Current audit progress">
      <div class="card"><strong>8,454</strong><span>tracked source paths</span><small>committed-tree census</small></div><div class="card"><strong>$route_calls</strong><span>static route callsites</span><small>not runtime routes</small></div><div class="card"><strong>$page_root_count</strong><span>static Inertia page roots</span><small>$resolver_count paths partitioned; prompt gate open</small></div><div class="card"><strong>$canonical_count</strong><span>canonical static targets</span><small>$h_count H · $d_count D · $m_count M</small></div>
      <div class="card"><strong>$mapped_sources / $source_count</strong><span>discovery sources mapped</span><small>one bounded source excluded</small></div><div class="card"><strong class="partial">$finding_count</strong><span>provisional P1 claims</span><small>none final</small></div><div class="card"><strong class="zero">0</strong><span>current runtime tests</span><small>vendor absent; setup not run</small></div><div class="card"><strong class="zero">0</strong><span>current-source browser routes</span><small>$unknown_build_routes unknown-build routes observed; attribution unproved</small></div>
    </section>
    <section id="checkpoint" class="panel">
      <h2>RUN-071–123 completion-gate checkpoint</h2>
      <p>The 26 literal completion gates were reconciled before RUN-072 added source-bound usability and incident-chain evidence, RUN-073 materialized reporting paths and source synthesis, RUN-074–076 reconstructed bounded feature-side linkage, RUN-077–081 materialized, independently reviewed, integrated, and reported the exhaustive committed static route/name/page universe, RUN-082/R added an independently reviewed candidate-only relation census, RUN-083 refreshed and verified the audit dashboard, and RUN-084/R/B/BR added independently reviewed page-tree and backend structural ledgers plus a signed-out designated-application preflight, RUN-086/R add the initial independently reviewed bounded ownership, RUN-089 repeats the signed-out preflight, RUN-090–092/R queue, review, integrate, and independently verify nine closed chains while retaining two shared, RUN-097/R–112 preserve the historical route/action and page-owner checkpoints with dashboard verification, RUN-113/R–120 preserve historical reviewed route/action and page-owner checkpoints with exact dashboard receipts; RUN-121/R–122/R independently review, integrate, and verify seven Finance route owners plus seven bridges while preserving 15 non-owner outcomes and zero page credit, and RUN-123 refreshes current reporting. Static relation, structural classification, registration, public/login, or audit-dashboard artifacts are not measured task, framework reachability, attributable application browser, runtime, mapping, Pass, final-finding, or completion evidence.</p>
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
        <tr><td>RUN-085 reporting refresh</td><td><strong>current page/backend/preflight boundaries</strong></td><td class="partial">audit-only materialization · matrix and preserved reports byte-identical · fresh dashboard receipt linked separately</td></tr><tr><td>RUN-086/R static source feature ownership</td><td><strong>530 records · 212 routes + 318 pages · 235 FEATURE-IDs</strong></td><td class="partial">bounded source ownership only · Gate 4 incomplete · 0 framework/runtime/browser/test/benchmark/Pass/completion credit</td></tr><tr><td>RUN-087 reporting refresh</td><td><strong>initial bounded ownership reported</strong></td><td class="partial">audit-only materialization · matrix and preserved reports byte-identical</td></tr><tr><td>RUN-089 application preflight</td><td><strong>public + signed-out login only</strong></td><td class="zero">earlier login absent in controlled tab · no credentials/forms/private records/screenshots · build and non-production identity unproved</td></tr><tr><td>RUN-090 direct-exact review queue</td><td><strong>$queue_records candidate surfaces</strong></td><td class="partial">queue itself grants no wholesale ownership · current overlay: $queue_owner owned · $queue_shared shared · $queue_alias alias · $queue_pending unreviewed · $queue_without_owner without ownership</td></tr><tr><td>RUN-091/R → 092/R closed-chain overlay</td><td><strong>9 owner chains · 2 shared · 18 owner rows · 9 action bridges</strong></td><td class="partial">548 cumulative owners · 221 routes + 327 pages · 239 FEATURE-IDs · historical bounded checkpoint</td></tr><tr><td>RUN-093 reporting refresh</td><td><strong>historical reviewed overlay reported</strong></td><td class="partial">audit-only materialization · superseded dashboard separately verified by RUN-094</td></tr><tr><td>RUN-097/R → 098/R historical route/action overlay</td><td><strong>23 owner route/actions · 23 route rows · 23 action bridges · 0 page rows</strong></td><td class="partial">571 cumulative owners · 244 routes + 327 pages · 246 FEATURE-IDs · historical bounded checkpoint</td></tr><tr><td>RUN-099 / RUN-100 historical reporting and dashboard</td><td><strong>route/action overlay reported and exact dashboard verified</strong></td><td class="partial">audit-only history · no verification metrics transfer</td></tr><tr><td>RUN-101/R → 102/R historical outcome-neutral overlay</td><td><strong>24 reviewed · 21 owner route/actions · 3 aliases · 21 route rows · 21 action bridges · 0 page rows</strong></td><td class="partial">592 cumulative owners · 265 routes + 327 pages · 249 FEATURE-IDs · historical bounded checkpoint</td></tr><tr><td>RUN-103 / RUN-104 historical reporting and dashboard</td><td><strong>route/action overlay reported and exact dashboard verified</strong></td><td class="partial">audit-only history · no verification metrics transfer</td></tr><tr><td>RUN-105/R → 106/R historical page render-owner overlay</td><td><strong>24 reviewed · 20 owner pages · 3 shared · 1 evidence gap · 20 page rows · 0 route/bridge rows</strong></td><td class="partial">612 cumulative owners · 265 routes + 347 pages · 256 FEATURE-IDs · historical bounded checkpoint</td></tr><tr><td>RUN-107 / RUN-108 historical reporting and dashboard</td><td><strong>page-owner overlay reported and exact dashboard verified</strong></td><td class="partial">audit-only history · no verification metrics transfer</td></tr><tr><td>RUN-109/R → 112 historical page render-owner tail</td><td><strong>6 reviewed · 2 owner pages · 4 shared · 0 evidence gap · 2 page rows · 0 route/bridge rows · 1 queue-shared row</strong></td><td class="partial">614 cumulative owners · 265 routes + 349 pages · 256 FEATURE-IDs · exact superseded dashboard verified</td></tr><tr><td>RUN-113/R → 116 historical name-only route/action overlay</td><td><strong>$route_wave_reviewed reviewed = $route_review_owner owner + $route_review_alias alias + $route_review_shared shared + $route_review_dead dead + $route_review_gap gap · 23 route rows · 23 action bridges · 0 page rows</strong></td><td class="partial">637 cumulative owners · 288 routes + 349 pages · 256 FEATURE-IDs · exact superseded dashboard verified</td></tr><tr><td>RUN-115 / RUN-116 historical reporting and dashboard</td><td><strong>name-only route/action overlay reported and exact dashboard verified</strong></td><td class="partial">audit-only history · no verification metrics transfer</td></tr><tr><td>RUN-117/R → 120 historical Respite handover page overlay</td><td><strong>$respite_page_wave_reviewed reviewed = $respite_page_review_owner owner pages · 4 page rows · 0 route/bridge/queue rows</strong></td><td class="partial">641 cumulative owners · exact superseded dashboard verified</td></tr><tr><td>RUN-121/R → 122/R current Finance route/action overlay</td><td><strong>$finance_wave_reviewed reviewed = $finance_review_owner owner + $finance_review_shared shared + $finance_review_alias alias + $finance_review_dead dead + $finance_review_gap gap · 7 route rows · 7 bridges · 0 page rows</strong></td><td class="partial">$static_owner_records cumulative owners · $static_owner_routes routes + $static_owner_pages pages · Gate 4 incomplete</td></tr><tr><td>RUN-123 reporting refresh</td><td><strong>Finance route/action overlay reported</strong></td><td class="partial">audit-only materialization · matrix byte-identical · fresh RUN-124 verification required</td></tr>
        <tr><td>Benchmark mapping/register credit</td><td><strong class="zero">0</strong></td><td class="zero">0 target edges · 0 final no-matches · 0 NCM · 0 / 340 mapped</td></tr>
      </tbody></table></div>
      <ul class="list">$checkpoint_evidence_links</ul>
    </section>
    <section id="pages" class="panel"><h2>Current static Inertia page partition</h2><p>RUN-084 enumerates all $full_page_tree_files physical files under the pinned <span class="mono">resources/js/pages</span> tree and RUN-084R independently reproduces every path, blob, content hash, row identity, partition, and import-graph boundary with zero discrepancies. The production TSX partition is exactly $full_page_production = $full_page_roots literal rendered roots + $full_page_support imported support components + $full_page_nonroots adjudicated unrendered/unimported non-roots. This supersedes older wording that called the 25 non-roots “resolver-imported.” RUN-082R's separate $candidate_page_rows evidence-gap relation census remains candidate-only. Neither structural GO establishes canonical FEATURE-ID ownership, framework reachability, build resolution, or rendered browser behavior.</p><div class="table-wrap"><table><thead><tr><th>Partition</th><th>Count</th><th>Current static identity</th></tr></thead><tbody><tr><td>Physical page-tree files</td><td>$full_page_tree_files</td><td class="partial">complete pinned Git path/blob/content census</td></tr><tr><td>TSX / TS files</td><td>$full_page_tsx / $full_page_ts</td><td class="partial">$full_page_excluded_tsx excluded test/spec/story TSX · $full_page_ts_helpers production TS helpers · $full_page_ts_tests TS tests</td></tr><tr><td>Existing literal backend render roots</td><td>$full_page_roots</td><td class="partial">static file-backed roots; $page_reviewed reviewed + $page_evidence_gaps evidence-gap prompt classifications</td></tr><tr><td>RUN-082 render-owner candidates</td><td>$candidate_page_one single · $candidate_page_many multiple · $candidate_page_zero none</td><td class="partial">exact line containment only · RUN-082R GO candidate-only · 0 mappings</td></tr><tr><td>Imported support components</td><td>$full_page_support</td><td class="partial">all directly imported from production TSX; support-owner relations remain candidates</td></tr><tr><td>Adjudicated unrendered/unimported non-roots</td><td>$full_page_nonroots</td><td class="partial">10 Redirect/legacy + 10 Duplicate + 3 Dead/unreachable + 2 Out of product scope</td></tr><tr><td>Missing backend render literals</td><td>$missing_target_count</td><td class="zero">retired/unrouted liabilities; zero page credit</td></tr><tr><td><strong>Production TSX partitioned</strong></td><td><strong>$full_page_production</strong></td><td class="partial">$full_page_production/$full_page_production static structural classification · 0 feature mappings/runtime/build/browser</td></tr></tbody></table></div></section>
    <div class="split"><section class="panel"><h2>Evidence waves represented</h2><p>RUN-001 through RUN-123 are represented by audit artifacts; none grants current-source application runtime, signed-in browser, executed-test, benchmark-mapping, or completion credit.</p><ul class="list"><li>RUN-001–016: census, discovery, page/static visual, and benchmark-metadata foundations</li><li>RUN-017–022: frontline/platform identity adjudication and blocked-owner reconciliation</li><li>RUN-023–025: cross-scope and remaining-owner arbitration</li><li>RUN-026–030: report/medical ownership, denominator integration, red team, and frozen 340-target identity</li><li>RUN-031–038: complete observer-only project materialization and blocker review; 88 complete observer-only · 7 partial</li><li>RUN-039–046: first six-target comparison overlay; 6 NO-GO · 0 formal edges · unchanged 0/340</li><li>RUN-047–052: historical 24-packet diagnostic checkpoint; mechanically complete but missing the clean Agent A-to-B derivation</li><li>RUN-053–054: identity-stripped Agent A packets, 252-atom crosswalk, fresh Agent B neutralization, and provenance-only correction</li><li>RUN-055: sealed fresh Agent C comparison; 24 rows · 144 lenses · 24 units · 58 outcomes · 85 unknowns preserved</li><li>RUN-056: sealed fresh Agent D adjudication; 226 reviews · 225 accepted · 1 bounded correction · 0 rejected</li><li>RUN-057: deterministic corrected-chain integration; 0 formal edges · 0 final no-matches · unchanged 0/340</li><li>RUN-058-BROWSER–060: $unknown_build_routes routes · $unknown_build_viewports viewports · $unknown_build_cells cells · $unknown_build_overlays overlays · $unknown_build_candidates provisional candidates · 0 current-source credit</li><li>RUN-058A–070 formal upstream: $formal_attempts project records · $formal_prompt_repos prompt repositories · $formal_historical historical extra · $formal_targets targets · $formal_subrecords initial facet/aspect records · $formal_accepted project records accepted · $formal_facets facet records accepted</li><li>RUN-071: 26 literal completion gates and downstream/usability/visual readiness reconciled; historical pre-materialization snapshot 9/18 required deliverables</li><li>RUN-072: historical 11/18 snapshot · 300/300 source-bound H contracts · 0 validated tasks · all current/target scores <span class="mono">NOT_MEASURED</span> · 3-target static slice · expired-auth 0 cells · incident A/B/C/D candidate chain with Agent D NO-GO and zero edge/final-no-match/NCM credit</li><li>RUN-073: 18/18 required paths present · reports 07–12 and findings materialized source-only · 8/8 independently source-reviewed journeys with 27/8/9 handoffs and 0 prompt-grade executions · 13 entity families · 17 concerns · 9 provisional architecture claims · 10 explicit unknowns · 0 final/runtime findings</li><li>RUN-074–076: $static_reviewed_targets gap targets · $static_original_missing_cells original missing scoped cells · cyclic independent review · $static_rows_changed rows / $static_field_changes permitted linkage cells changed · immutable and benchmark/credit projections unchanged · 0 downstream credit</li><li>RUN-077: $route_primary_calls primary route-facade callsites + 1 separate route-like sentinel · $route_name_calls fluent names · $route_page_roots page roots · exact three-part manifest</li><li>RUN-078–079: $route_like_rows route-like, $route_name_calls name, and $route_page_roots page decision records · 3 cyclic independent GO reviews · 0 invalid decisions · 0 reviewer writes</li><li>RUN-080–081: $route_page_rows_changed rows / $route_page_field_changes route-name/page-file fields integrated · current reports and artifact hashes refreshed · immutable and benchmark/credit projections unchanged · 0 downstream credit</li><li>RUN-082: $candidate_route_rows retained route-like rows · $candidate_page_rows page evidence-gap rows · route-name, exact controller-method, and render-owner static candidate relations · $candidate_registered_routes/$route_file_count route-file registration closure · RUN-082R GO candidate-only · 0 discrepancies · no mapping/matrix/downstream authority · runtime/build/tests/application browser NO-GO · 0 mappings</li><li>RUN-083: five reports refreshed · five reports byte-preserved · matrix 0 rows / 0 cells changed · zero downstream credit</li><li>RUN-083 dashboard: 4/4 viewports · 172/172 local links · 10/10 anchors · zero duplicate IDs or console warnings/errors · artifact-only GO</li><li>RUN-084/R: $full_page_tree_files physical page-tree files · $full_page_production = $full_page_roots roots + $full_page_support imported supports + $full_page_nonroots adjudicated non-roots · independent GO structural/candidate evidence only</li><li>RUN-084B/BR: $backend_role_rows backend role rows · $backend_unique_paths unique paths · $backend_async_rows async role rows / $backend_async_paths paths · independent GO structural only · 0 whole-file semantic reviews</li><li>RUN-084 historical designated application: public home + signed-out login only · no credentials/forms/private records/screenshots · build identity unproved · 0 application-browser credit</li><li>RUN-085: deterministic reporting refresh and fresh audit-dashboard verification · matrix and all downstream credit unchanged</li><li>RUN-086/R: 530 independently reviewed bounded static source-owner records · 212 routes + 318 pages · 235 FEATURE-IDs · Gate 4 incomplete</li><li>RUN-087: deterministic initial bounded-ownership reporting refresh · downstream boundaries unchanged</li><li>RUN-089: current public/login signed-out preflight · no signed-in or build-attributed application credit</li><li>RUN-090: $queue_records-row direct-exact review queue · zero wholesale ownership</li><li>RUN-091/R: 11 closed chains reviewed · 9 owner · 2 shared</li><li>RUN-092/R: 18 owner rows + 9 action bridges integrated · one independent mechanical reconstruction + one semantic-boundary review · 548 cumulative owner records</li><li>RUN-093: deterministic reviewed-overlay reporting refresh · matrix and every execution/benchmark/Pass/finding/completion boundary unchanged</li><li>RUN-097/R: historical 23 route/controller-only owners · 0 page credit</li><li>RUN-098/R: historical 23 route rows + 23 action bridges · 571 cumulative owner records</li><li>RUN-099–100: historical reporting refresh and exact superseded dashboard verification</li><li>RUN-101/R: historical 24 route candidates · 21 owners · 3 aliases · 0 page credit</li><li>RUN-102/R: historical 21 route rows + 21 action bridges · 592 cumulative owner records</li><li>RUN-103–104: historical reporting refresh and exact superseded dashboard verification</li><li>RUN-105/R–108: historical 24-page review · 20 owners · 3 shared · 1 gap · reporting and exact superseded dashboard verification</li><li>RUN-109/R–112: historical 6-page tail · 2 owners · 4 shared · reporting and exact superseded dashboard verification</li><li>RUN-113/R–116: historical 24 name-only route actions · 23 owners · one alias · 23 route rows and bridges · reporting and exact superseded dashboard verification</li><li>RUN-117/R–120: historical four-page Respite handover review, integration, reporting, and exact superseded dashboard receipt</li><li>RUN-121/R: $finance_wave_reviewed Finance route actions · $finance_review_owner owners · $finance_review_shared shared · $finance_review_alias alias · $finance_review_gap gaps · zero page credit</li><li>RUN-122/R: seven route rows and bridges integrated and independently verified · 15 non-owner outcomes preserved · $static_owner_records cumulative owner records</li><li>RUN-123: deterministic Finance reporting refresh · matrix and every Site/permission/privacy/direct-object/ledger/lifecycle/concurrency/execution/benchmark/Pass/finding/completion boundary unchanged</li></ul></section><section class="panel"><h2>Execution credit</h2><p>Static, observer, source-comparison, formal-upstream triage records, and unknown-build deployed observations are not attributable current-source runtime evidence.</p><ul class="list"><li><span class="zero">0</span> framework route executions</li><li><span class="zero">0</span> current application tests</li><li><span class="zero">0</span> rendered current-build visual instances</li><li><span class="zero">0</span> current-build application browser routes</li><li><span class="zero">0</span> benchmark mappings promoted</li><li><span class="zero">0</span> completed Pass 1–8 modules</li></ul></section></div>
    <section id="static-census" class="panel"><h2>Expanded static coverage wave</h2><p>RUN-030 freezes canonical static identity; RUN-077–084B add exhaustive committed static route/name/page, full page-tree, and backend structural evidence; RUN-086/R establish the initial bounded ownership, RUN-090–092/R add the independently reviewed closed-chain overlay, RUN-097/R–112 preserve the historical route/action and page-owner checkpoints, RUN-113/R–120 preserve historical reviewed route/action and page-owner checkpoints with exact dashboard receipts; RUN-121/R–122/R add seven independently reviewed Finance route owners and seven bridges while preserving 15 non-owner outcomes and zero page credit, and RUN-123 refreshes reporting. Rendered coverage, schema truth, runtime, benchmark, ease, release, and completion gates remain open.</p><div class="table-wrap"><table><thead><tr><th>Static universe</th><th>Denominator</th><th>Current boundary</th></tr></thead><tbody><tr><td>Discovery sources / Layer-A edges</td><td>$mapped_sources of $source_count / $layer_a_edges</td><td class="partial">one bounded source excluded; $layer_a_targets Layer-A targets</td></tr><tr><td>Canonical targets</td><td>$canonical_count / $h_count H · $d_count D · $m_count M</td><td class="partial">static identity frozen; no downstream credit</td></tr><tr><td>Remaining route-path / route-name / page-file gaps</td><td>$route_gap_count / $route_name_gap_count / $page_gap_count</td><td class="partial">RUN-080 matrix sentinels; full mapping/reachability open</td></tr><tr><td>Remaining combined route/page / backend / static test gaps</td><td>$both_gap_count / $backend_gap_count / $test_gap_count</td><td class="partial">static owners/locators only; tests unexecuted</td></tr><tr><td>Primary route-facade / separate route-like sentinel</td><td>$route_primary_calls / 1</td><td class="partial">$route_like_rows review rows; no framework expansion</td></tr><tr><td>RUN-078 baseline route decision classes</td><td>$route_owner owner · $route_shared shared · $route_alias alias · $route_unmapped explicit unmapped</td><td class="partial">cyclic independent static review; 0 feature mappings</td></tr><tr><td>RUN-082 exact route-name candidates</td><td>$candidate_name_one single · $candidate_name_many multiple · $candidate_name_zero none</td><td class="partial">static relation only · RUN-082R GO candidate-only · 0 mappings</td></tr><tr><td>RUN-082 exact controller-method candidates</td><td>$candidate_backend_one single · $candidate_backend_many multiple · $candidate_backend_zero none</td><td class="partial">$candidate_exact_actions resolvable arrays · $candidate_non_exact_actions non-exact retained · 0 mappings</td></tr><tr><td>RUN-082 page render-owner candidates</td><td>$candidate_page_one single · $candidate_page_many multiple · $candidate_page_zero none</td><td class="partial">$candidate_page_rows evidence-gap rows · no ownership/render credit</td></tr><tr><td>RUN-082 static route-file registration</td><td>$candidate_registered_routes / $route_file_count</td><td class="partial">$candidate_direct_routes bootstrap + $candidate_web_routes web requires · 0 framework route tables</td></tr><tr><td>Fluent-name callsites</td><td>$route_name_calls</td><td class="partial">static name decisions; framework reachability unproved</td></tr><tr><td>RUN-079 baseline page-root prompt status</td><td>$page_reviewed reviewed · $page_evidence_gaps evidence gap</td><td class="partial">$route_page_roots roots total; 0 rendered</td></tr><tr><td>RUN-092/R historical bounded ownership</td><td>548 records · 221 route + 327 page · 239 FEATURE-IDs · 9 action bridges</td><td class="partial">13.947569% · 3,381 residual · historical bounded checkpoint</td></tr><tr><td>RUN-098/R historical bounded route/action ownership</td><td>571 records · 244 route + 327 page · 246 FEATURE-IDs · 32 action bridges</td><td class="partial">14.532960% · 3,358 residual · historical bounded checkpoint</td></tr><tr><td>RUN-102/R historical outcome-neutral route/action ownership</td><td>592 records · 265 route + 327 page · 249 FEATURE-IDs · 53 action bridges</td><td class="partial">15.067447% · 3,337 residual · historical bounded checkpoint</td></tr><tr><td>RUN-106/R historical outcome-neutral page ownership</td><td>612 records · 265 route + 347 page · 256 FEATURE-IDs · 53 action bridges</td><td class="partial">15.576483% · 3,317 residual · historical bounded checkpoint</td></tr><tr><td>RUN-110/R historical outcome-neutral page-tail ownership</td><td>614 records · 265 route + 349 page · 256 FEATURE-IDs · 53 action bridges</td><td class="partial">15.627386% · 3,315 residual · historical bounded checkpoint · exact RUN-112 dashboard verification</td></tr><tr><td>RUN-122/R current Finance route/action ownership</td><td>$static_owner_records = $static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs = $static_owner_h_features H + $static_owner_d_features D · $static_action_bridges action bridges</td><td class="partial">$ownership_percent% of bounded 3,929 · $static_residual residual · features $route_feature_ids route / $page_feature_ids page / $route_page_overlap overlap · routes 3,218 = $static_owner_routes owner + $route_shared_current shared + $route_alias_current alias + $route_residual residual with $finance_review_gap tagged gaps · pages 711 = $static_owner_pages owner + $page_shared shared + $page_residual residual with $page_gap tagged gap · Finance page calls $finance_page_calls = $finance_page_owned already-owned + $finance_page_unowned unowned + $finance_page_authorized page credit · Gate 4 incomplete · matrix unchanged</td></tr><tr><td>RUN-090 direct-exact queue</td><td>$queue_records total = $queue_reviewed reviewed + $queue_pending pending · reviewed = $queue_owner owned + $queue_shared shared + $queue_alias alias + $queue_gap gap · $queue_without_owner without ownership</td><td class="partial">candidate prioritisation only · queue itself grants no wholesale ownership</td></tr><tr><td>Named navigation/tab source files</td><td>$nav_file_count</td><td class="partial">definitions, not runtime-visible links</td></tr><tr><td>Hero definitions / instances</td><td>$hero_definitions / $hero_instances</td><td class="partial">static AST; 0 rendered</td></tr><tr><td>Overlay definitions / instances</td><td>$overlay_definitions / $overlay_instances</td><td class="partial">static AST; 0 rendered</td></tr><tr><td>Declarative / direct / named triggers</td><td>$declarative_triggers / $direct_triggers / $named_triggers</td><td class="partial">row-level static locators; 0 interactions</td></tr><tr><td>Required visual matrix rows</td><td>$visual_matrix_rows</td><td class="partial">49 columns complete; every row browser-blocked</td></tr><tr><td>RUN-084B models / policies / service role rows</td><td>$backend_models / $backend_policies / $backend_services</td><td class="partial">independently reproduced structural ledger · every row Evidence gap · 0 whole-file semantic reviews</td></tr><tr><td>RUN-084B jobs / events / listeners / outbox</td><td>$jobs / $events / $listeners / 45</td><td class="partial">$backend_async_rows overlapping async role rows over $backend_async_paths paths · no queue/runtime execution</td></tr><tr><td>Migrations / PHP test files</td><td>$migrations / $php_test_files</td><td class="partial">history and file locators; 0 database/test execution; unreproducible lexical-case count omitted</td></tr></tbody></table></div></section>
    <section id="runtime" class="panel"><h2>Runtime and deployed-build identity gates</h2><p>These checks record whether execution is safe and attributable. None grants current-source application execution credit.</p><div class="table-wrap"><table><thead><tr><th>Gate</th><th>Observed fact</th><th>Result</th></tr></thead><tbody><tr><td>RUN-082 static registration closure</td><td>$candidate_registered_routes/$route_file_count route files: $candidate_direct_routes direct bootstrap + $candidate_web_routes web requires</td><td class="partial">source registration complete; framework route table not executed</td></tr><tr><td>RUN-082 execution preflight</td><td><span class="mono">vendor/autoload.php</span> and route cache absent; pinned Node/Wayfinder dependencies and build provenance absent; database identifiers not isolated; deployed build unattributed</td><td class="zero">framework runtime, build, tests, and application browser NO-GO / not executed</td></tr><tr><td>RUN-089 designated-application preflight</td><td>Public home and signed-out login only; the earlier user login did not persist in the controlled tab; no credentials, submissions, private records, screenshots, environment marker, or build attribution</td><td class="zero">Signed-in application, role/Site, route/workflow, responsive-family, runtime, test, Pass, and completion credit all zero</td></tr><tr><td>Local PHP/runtime</td><td>PHP $php_version; test-oriented settings; <span class="mono">vendor/autoload.php</span> absent</td><td class="zero">Laravel not booted; 0 runtime tests</td></tr><tr><td>Repository setup</td><td>Combined setup includes dependencies, forced migration, frontend build, and device configuration</td><td class="zero">State-changing setup not run</td></tr><tr><td>Signed-in deployment</td><td>Inertia component <span class="mono">$deployed_component</span> and deployed assets recorded read-only</td><td class="zero">No authoritative commit/tree marker</td></tr><tr><td>Selected unknown-build sample</td><td>$unknown_build_routes routes across $unknown_build_viewports prompt viewports · $unknown_build_cells cells · $unknown_build_overlays pre-submit overlays · $unknown_build_candidates provisional candidates</td><td class="zero">Accepted as unknown-build observation only; 0 current-source credit</td></tr><tr><td>RUN-072 expired-auth attempt</td><td>3 routes selected; only <span class="mono">/my-day</span> attempted; both contexts ended at <span class="mono">/login</span>; 0 authenticated/base/pre-submit cells, credentials, mutations, or screenshots</td><td class="zero">Build, environment, role, Site, and fixture identities all UNKNOWN; fail-closed with zero credit</td></tr><tr><td>Local build manifest</td><td>Present locally but not tracked at the application source pin</td><td class="zero">Cannot identify the deployed build</td></tr></tbody></table></div></section>
    <section id="benchmarks" class="panel"><h2>Current benchmark wave</h2><p>The prompt denominator remains 98 URL occurrences / 95 unique repositories. RUN-047–052 are preserved as a historical defective checkpoint. RUN-053–057 supply a corrected diagnostic clean-spec chain for 24 selected packets. RUN-058A–070 preserves $formal_attempts initial upstream project records across the same six selected targets. Independent exact-hash FTC review accepts $formal_accepted bounded project records across incident and HR/finance plus $formal_facets bounded HR/finance facet records; medication/clinical remains NO-GO. Formal project/facet-record acceptance is not project or facet selection, a target mapping, or an exhaustive final no-match. All 340 target mappings or final no-matches remain open.</p><div class="table-wrap"><table><thead><tr><th>Evidence slice</th><th>Count</th><th>Current credit</th></tr></thead><tbody><tr><td>Prompt-listed URL occurrences</td><td>$prompt_occurrences</td><td class="partial">$prompt_unique unique repositories; three repeated</td></tr><tr><td>Physical carry-forward register rows</td><td>$register_physical</td><td class="partial">95 exact prompt repos + $extra_rows historical extras</td></tr><tr><td>Official GitHub metadata prerequisite</td><td>$metadata_unique / $prompt_unique</td><td class="partial">$metadata_occurrences / $prompt_occurrences weighted entries; metadata only</td></tr><tr><td>Observer project triage</td><td>$triage_observer_unique / $prompt_unique</td><td class="partial">$triage_observer_occurrences / $prompt_occurrences weighted entries; $triage_complete complete observer-only · $triage_partial partial</td></tr><tr><td>Partial-record review</td><td>$partial_reviewed / 16</td><td class="partial">$partial_resolved resolved observer-only · $partial_retained retained partial; zero downstream credit</td></tr><tr><td>Formal full project triage</td><td>$formal_accepted / $prompt_unique</td><td class="partial">$formal_accepted_weight / $prompt_occurrences weighted entries; project records only</td></tr><tr><td>Formal upstream wave-03 attempts</td><td>$formal_attempts unique records</td><td class="partial">$formal_prompt_repos prompt repositories · $formal_historical historical extra · occurrence weight $formal_weight</td></tr><tr><td>Independently accepted formal records</td><td>$formal_accepted / $formal_attempts</td><td class="partial">$formal_targets targets inspected · $formal_subrecords initial facet/aspect records · $formal_facets bounded facet records accepted · 0 edges · 0 final no-matches</td></tr><tr><td>First six-target overlay</td><td>$target_wave_targets / $canonical_count</td><td class="partial">$target_candidate_packets candidate locators · $target_no_candidate_packets bounded no-candidate; overlay only</td></tr><tr><td>Historical RUN-047–052 diagnostic</td><td>$facet_wave_facets packets / $facet_wave_features features</td><td class="partial">superseded for corrected comparison; retained as provenance only</td></tr><tr><td>Corrected Agent A packets</td><td>$facet_exact exact / $facet_partial partial / $facet_insufficient insufficient</td><td class="partial">all adjacent packets remain non-promotable</td></tr><tr><td>Corrected Agent B atom lineage</td><td class="partial">$facet_atoms total / $facet_consumed consumed / $facet_unknown_atoms unknown</td><td class="partial">$facet_units units · $facet_outcomes outcomes · zero neutral credit</td></tr><tr><td>Fresh Agent C comparison</td><td>$facet_ratings lenses / $facet_unknowns unknowns</td><td class="partial">same-packet citations only; static comparison credit zero</td></tr><tr><td>Pinned source evidence</td><td>$facet_anchors occurrences / $facet_unique_anchors unique / $facet_anchor_files paths</td><td class="partial">mechanically validated; no mapping credit</td></tr><tr><td>Fresh Agent D adjudication</td><td>$facet_d_reviews reviews / $facet_d_corrections correction</td><td class="partial">24 row lineages pass; AO-A53-024-01 corrected to partial</td></tr><tr><td>Promoted feature mappings or final no-matches</td><td>$promoted_count</td><td class="zero">$facet_edges formal edges · $facet_final_no_matches final no-matches · 0 / 340 credited</td></tr></tbody></table></div></section>
    <section class="panel"><h2>RUN-071B downstream readiness and RUN-072 incident disposition</h2><p>Three exact target IDs were start-ready for a fresh clean-specification chain; none was mapping-ready or credit-ready. The completed incident chain closes only this candidate edge with Agent D NO-GO. It is not an exhaustive final no-match or NCM.</p><div class="table-wrap"><table><thead><tr><th>Start-ready target IDs</th><th>Readiness</th><th>Current disposition</th></tr></thead><tbody><tr><td class="mono">$start_ready_ids</td><td class="partial">3 start-ready · 0 mapping-ready · 0 credit-ready</td><td class="zero">Incident candidate NO-GO · 0 edges · 0 final no-matches · 0 NCM</td></tr></tbody></table></div></section>
    <section id="modules" class="panel"><h2>Canonical static feature modules</h2><p>$module_count module labels across $canonical_count canonical static targets. Module completion credit remains zero.</p><div class="table-wrap"><table><thead><tr><th>Module label</th><th>H</th><th>D</th><th>M</th><th>Total</th></tr></thead><tbody>$module_rows</tbody></table></div></section>
    <section id="findings" class="panel"><h2>Provisional current-source P1 claims</h2><p>None is a final finding, verified exploit, remediated issue, or closed gate.</p><div class="table-wrap"><table><thead><tr><th>ID</th><th>Static concern</th><th>Status</th></tr></thead><tbody>$finding_rows</tbody></table></div></section>
    <section id="architecture" class="panel"><h2>Separate provisional architecture source claims</h2><p>Nine independently source-reviewed candidates (7 P1 · 2 P2) remain outside the 12-row discovery finding table. They have 0 final/runtime finding credit.</p><div class="table-wrap"><table><thead><tr><th>ID</th><th>Priority</th><th>Narrow source condition</th><th>Status</th></tr></thead><tbody>$architecture_rows</tbody></table></div></section>
    <section id="gaps" class="panel"><h2>Literal completion gates still open</h2><div class="split"><ul class="list"><li>RUN-122/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges while adding seven Finance route owners and seven bridges, preserving $finance_review_shared shared, $finance_review_alias alias, and $finance_review_gap gap outcomes, and adding zero page owners; complete the framework-expanded canonical route/page denominator, $static_residual non-owner records including $route_shared_current shared routes, $route_alias_current alias routes, and $route_residual residual routes plus $page_shared shared pages and $page_gap tagged gap within $page_residual residual pages, the full crosswalk, and route reachability before Gate 4 can close</li><li>RUN-080 retained matrix gaps: $route_gap_count route path, $route_name_gap_count route names, $page_gap_count page files, $both_gap_count combined route/page, $backend_gap_count backend anchors, and $test_gap_count static test anchors</li><li>RUN-082R independently reviewed the candidate-relation reconstruction; separately adjudicate canonical ownership across $candidate_route_rows route-like and $candidate_page_rows page evidence-gap rows without promoting names, containment, presence, or overlap as mapping</li><li>Complete route/page-to-feature mapping, framework reachability, and backend/data/test ownership</li><li>Adjudicate the reviewed 1,058-file page-tree graph without inheriting FEATURE-IDs through support imports; preserve $full_page_production = $full_page_roots + $full_page_support + $full_page_nonroots</li><li>Complete semantic review of all $backend_role_rows backend role rows across $backend_unique_paths paths; whole-file semantic review remains 0</li><li>Full current project behaviour/licence/edition triage and one final mapping or documented no-match per frozen feature</li><li>Refresh RUN-072 base-matrix locator snapshots separately, then representative-role validation of all 300 contracts plus ten current and ten target dimension measurements per H target: 0 validated · 0 measured · 0 independent ease reviews</li></ul><ul class="list"><li>8/8 journeys are source reconstructed and independently source-reviewed; prompt-grade runtime/browser execution and all four viewport lanes remain 0/8</li><li>Rendered hero, overlay, trigger, and material-state coverage from the completed static matrix</li><li>Build-attributed independent resampling of both unknown-build provisional candidates</li><li>Safe current-build application browser/runtime lanes; RUN-089 remains signed out and build-unattributed, so signed-in application coverage remains 0</li><li>Every module through Passes 1–8</li><li>Fresh Pass 8, final artifact freeze, reconciliation, and no-live-agent gate</li></ul></div></section>
    <section class="panel"><h2>Formal upstream evidence</h2><p>Every materialized producer, reviewer, correction, provenance, feasibility, checklist, and integration artifact is linked with its sealed SHA-256. Bounded project/facet-record acceptance remains separate from project/facet selection, comparison, mapping, NCM, and completion credit.</p><ul class="list">$formal_evidence_links</ul></section>
    <section class="panel"><h2>Prior audit-dashboard verification</h2><p>RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, RUN-094, RUN-100, RUN-104, RUN-108, RUN-112, RUN-116, and RUN-120 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-123.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-formal-upstream-wave-03.json">Superseded RUN-070 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-072-wave-04.json">Superseded RUN-072 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-073-wave-05.json">Superseded RUN-073 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-076-wave-06.json">Superseded RUN-076 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-081-wave-07.json">Superseded RUN-081 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-083-wave-08.json">Superseded RUN-083 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-085-wave-09.json">Superseded RUN-085 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-088-wave-10.json">Superseded RUN-088 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-094-wave-11.json">Superseded RUN-094 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-100-wave-12.json">Superseded RUN-100 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-104-wave-13.json">Superseded RUN-104 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-108-wave-14.json">Superseded RUN-108 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-112-wave-15.json">Superseded RUN-112 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-116-wave-16.json">Superseded RUN-116 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-120-wave-17.json">Superseded RUN-120 verification GO</a></li></ul></section>
    <section class="panel"><h2>Fresh RUN-124 audit-dashboard verification</h2><p>The exact regenerated RUN-123 dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-124 receipt must record page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 648/295/353 ownership, 7/7/1/0/7 Finance outcomes, 62/242/48 route/page/overlap feature sets, 83 bridges, route 3,218=295+12+5+2,906 with seven tagged gaps, page 711=353+9+349 with one tagged gap, queue 507=106+401 with 106=84+10+5+7 and 423 without ownership, 3,281 residual records, one operating organisation across multiple Sites, Gate 4 open, mapping 0/340, and all zero-credit boundaries. It verifies the audit artifact only and grants no application-browser, responsive-application, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-124-wave-18.json">RUN-124 responsive audit-dashboard verification receipt</a></li></ul></section>
    <section class="panel"><h2>RUN-071–123 evidence lineage</h2><p>Every current raw, generated, reviewed, and integrated RUN-077–123 source/reporting artifact is linked with its exact SHA-256. Earlier receipts remain immutable history; the task-script directory link carries the historical RUN-072 300-file bundle hash.</p><ul class="list">$checkpoint_evidence_links</ul></section>
    <section class="panel"><h2>Evidence files</h2><ul class="list"><li><a href="00-executive-summary.md">Executive summary</a></li><li><a href="01-repository-module-map.md">Current repository and page map</a></li><li><a href="02-repository-module-map-wave-02.md">Wave 02 module map</a></li><li><a href="02-eight-pass-coverage-ledger.csv">Provisional eight-pass route-file ledger</a></li><li><a href="03-feature-to-benchmark-matrix.csv">340-row canonical static feature matrix</a></li><li><a href="05-browser-visual-coverage-matrix.csv">2,812-row static visual matrix</a></li><li><a href="06-open-source-benchmark-register.csv">Current carry-forward benchmark register</a></li><li><a href="evidence/source/current-canonical-feature-identity-wave-01.json">RUN-030 canonical feature identity</a></li><li><a href="evidence/source/current-canonical-identity-agent-register.json">RUN-030 identity agent register</a></li><li><a href="evidence/source/current-static-semantic-census.json">Initial semantic census JSON</a></li><li><a href="evidence/source/current-route-navigation-gap-wave-01.json">Route/navigation reconciliation</a></li><li><a href="evidence/source/current-visual-static-census-wave-01.json">Visual static census</a></li><li><a href="evidence/source/current-visual-matrix-materialization-wave-01.json">Visual matrix materialization evidence</a></li><li><a href="evidence/source/current-visual-matrix-agent-register.json">Visual matrix agent register</a></li><li><a href="evidence/source/current-backend-data-test-census-wave-01.json">Backend/data/test census</a></li><li><a href="evidence/source/current-static-coverage-agent-register.json">Static coverage agent register</a></li><li><a href="evidence/source/current-page-adjudication-wave-01.json">Page adjudication evidence</a></li><li><a href="evidence/source/current-page-agent-register.json">Page agent register</a></li><li><a href="evidence/source/current-feature-discovery-wave-01.json">Feature wave 01 JSON</a></li><li><a href="evidence/source/current-feature-discovery-wave-02.json">Feature wave 02 JSON</a></li><li><a href="evidence/source/current-feature-discovery-wave-03.json">Feature wave 03 gap additions</a></li><li><a href="evidence/benchmark/current-benchmark-wave-01.json">Benchmark wave evidence</a></li><li><a href="evidence/benchmark/current-benchmark-agent-register.json">Benchmark agent register</a></li><li><a href="evidence/benchmark/current-benchmark-metadata-agent-register.json">Benchmark metadata agent register</a></li><li><a href="evidence/benchmark/current-github-project-metadata-snapshot.json">Official GitHub metadata snapshot</a></li><li><a href="evidence/benchmark/current-prompt-project-denominator-reconciliation.json">Prompt project denominator reconciliation</a></li><li><a href="evidence/benchmark/current-upstream-project-triage-wave-01.json">RUN-034 upstream observer triage</a></li><li><a href="evidence/benchmark/current-upstream-project-triage-agent-register.json">RUN-034 upstream triage agent register</a></li><li><a href="evidence/benchmark/current-upstream-partial-resolution-wave-01.json">RUN-038 partial-record review</a></li><li><a href="evidence/benchmark/current-upstream-partial-resolution-agent-register.json">RUN-038 partial-review agent register</a></li><li><a href="evidence/runtime/current-runtime-safety-assessment.json">Runtime safety assessment</a></li><li><a href="evidence/browser/deployed-build-identity-assessment.json">Deployed build identity assessment</a></li><li><a href="evidence/browser/deployed-selected-feature-observation-wave-03.json">RUN-058-BROWSER sealed unknown-build observation</a></li><li><a href="evidence/browser/raw-run-059b-independent-unknown-build-browser-review-wave-03.json">RUN-059B independent observation review</a></li><li><a href="evidence/browser/current-deployed-selected-feature-observation-wave-03.json">RUN-060 normalized unknown-build observation</a></li><li><a href="evidence/browser/raw-run-060r-s-independent-browser-normalization-adjudication-wave-03.json">RUN-060R/S normalization adjudication</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-unknown-build-browser-wave-03.json">RUN-060 responsive audit-dashboard verification</a></li><li><a href="13-unresolved-questions-and-evidence-gaps.md">Unresolved evidence gaps</a></li></ul></section>
    <section class="panel"><h2>RUN-058-BROWSER–060 unknown-build browser evidence</h2><p>The signed-in sample is preserved as an independently accepted observation of an unattributed deployed build only. It records $unknown_build_routes selected routes, $unknown_build_viewports prompt dimensions, $unknown_build_cells route/viewport cells, $unknown_build_overlays pre-submit overlays, and $unknown_build_candidates provisional unknown-build candidates. No forms were submitted, no records changed, no screenshots retained, and no current-source browser, responsive, visual, workflow, finding, ease, release, Pass, or completion credit is awarded.</p><ul class="list"><li><a href="evidence/browser/deployed-selected-feature-observation-wave-03.json">RUN-058-BROWSER sealed raw observation</a></li><li><a href="evidence/browser/raw-run-059b-independent-unknown-build-browser-review-wave-03.json">RUN-059B independent review</a></li><li><a href="generators/integrate-deployed-selected-feature-observation-wave-03.py">RUN-060 deterministic normalizer</a></li><li><a href="evidence/browser/current-deployed-selected-feature-observation-wave-03.json">RUN-060 normalized observation</a></li><li><a href="evidence/browser/raw-run-060r-s-independent-browser-normalization-adjudication-wave-03.json">RUN-060R/S independent lineage adjudication</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-unknown-build-browser-wave-03.json">RUN-060 responsive audit-dashboard verification</a></li></ul></section>
    <section class="panel"><h2>RUN-039–046 target comparison evidence</h2><p>Clean-stage packets and deterministic integration for the first six-target wave; all formal edges and downstream credits remain zero.</p><ul class="list"><li><a href="evidence/benchmark/raw-run-039-target-upstream-behaviour-wave-01.json">RUN-039 upstream behaviour locators</a></li><li><a href="evidence/benchmark/raw-run-040-current-product-source-packets-wave-01.json">RUN-040 current-source packets</a></li><li><a href="evidence/benchmark/raw-run-041-current-source-red-team-wave-01.json">RUN-041 scope red-team</a></li><li><a href="evidence/benchmark/raw-run-042-neutral-requirements-wave-01.json">RUN-042 blind neutral requirements</a></li><li><a href="evidence/benchmark/raw-run-043-current-neutral-comparison-wave-01.json">RUN-043 clean current-neutral comparisons</a></li><li><a href="evidence/benchmark/raw-run-044-current-source-facet-reconciliation-wave-01.json">RUN-044 composite facet reconciliation</a></li><li><a href="evidence/benchmark/raw-run-045-wave-01-independent-adjudication.json">RUN-045 independent adjudication</a></li><li><a href="evidence/benchmark/current-target-neutral-comparison-wave-01.json">RUN-046 integrated comparison overlay</a></li><li><a href="evidence/benchmark/current-target-neutral-comparison-agent-register.json">RUN-046 comparison agent register</a></li></ul></section>
    <section class="panel"><h2>RUN-047–052 historical diagnostic evidence</h2><p>The former 24-packet chain is retained only as an immutable zero-credit checkpoint. Its missing sanitized Agent A-to-B derivation is historical and its RUN-048/RUN-050/RUN-051 payloads are prohibited as corrected comparison evidence.</p><ul class="list"><li><a href="evidence/benchmark/raw-run-047-upstream-facet-refinement-clinical-incident-wave-02.json">RUN-047 clinical and incident upstream packets</a></li><li><a href="evidence/benchmark/raw-run-047-upstream-facet-refinement-composites-wave-02.json">RUN-047 HR, medication, and finance upstream packets</a></li><li><a href="evidence/benchmark/current-upstream-facet-refinement-wave-02.json">RUN-047 integrated upstream refinement</a></li><li><a href="evidence/benchmark/current-upstream-facet-refinement-agent-register.json">RUN-047 upstream agent register</a></li><li><a href="evidence/benchmark/raw-run-048-blind-neutral-facet-requirements-wave-02.json">RUN-048 historical source-independent requirements</a></li><li><a href="evidence/benchmark/raw-run-049-current-source-facet-refinement-wave-02.json">RUN-049 pinned current-source packets</a></li><li><a href="evidence/benchmark/raw-run-050-clean-facet-comparison-reconciled-wave-02.json">RUN-050 historical reconciled comparison</a></li><li><a href="evidence/benchmark/raw-run-051-independent-facet-adjudication-wave-02.json">RUN-051 historical independent adjudication</a></li></ul></section>
    <section class="panel"><h2>RUN-053–057 corrected clean-spec evidence</h2><p>Fresh A/B/C/D stages reconstruct the required clean handoff for 24 selected packets. Fresh D validates all 226 reviews, corrects one outcome from met to partial, and preserves zero formal edges, zero final no-matches, and 0/340 credit.</p><ul class="list"><li><a href="evidence/benchmark/raw-run-053-agent-a-blind-observed-behaviour-packets-wave-02.json">RUN-053 identity-stripped Agent A packets</a></li><li><a href="evidence/benchmark/root-run-053-agent-a-source-atom-crosswalk-wave-02.json">RUN-053 root-held atom and identity crosswalk</a></li><li><a href="evidence/benchmark/raw-run-054-fresh-agent-b-neutral-requirements-wave-02.json">RUN-054 fresh Agent B neutral requirements</a></li><li><a href="evidence/benchmark/raw-run-054-agent-b-input-boundary-correction-wave-02.json">RUN-054 provenance-only boundary correction</a></li><li><a href="evidence/benchmark/raw-run-055-agent-c-comparison-input-wave-02.json">RUN-055 sealed Agent C input</a></li><li><a href="evidence/benchmark/raw-run-055-fresh-agent-c-current-comparison-wave-02.json">RUN-055 fresh Agent C comparison</a></li><li><a href="evidence/benchmark/raw-run-056-independent-adjudicator-input-wave-02.json">RUN-056 sealed Agent D input</a></li><li><a href="evidence/benchmark/raw-run-056-fresh-independent-corrected-chain-adjudication-wave-02.json">RUN-056 fresh independent adjudication</a></li><li><a href="evidence/benchmark/current-facet-neutral-comparison-wave-02.json">RUN-057 integrated corrected-chain overlay</a></li><li><a href="evidence/benchmark/current-facet-neutral-comparison-agent-register.json">RUN-057 corrected-chain agent register</a></li></ul></section>
    <p class="footer">Generated deterministically from independently reviewed static evidence through RUN-122/R and reported in RUN-123. The matrix is unchanged; audit artifacts only and no application remediation is authorised.</p>
  </main>
</body>
</html>
""")


dashboard = TEMPLATE.substitute(
    application_short=canonical["source_pin"]["application_commit"][:12],
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
    static_owner_records=reviewed_finance_chart_route_action_overlay["combined_counts"]["source_owner_records"],
    static_owner_routes=reviewed_finance_chart_route_action_overlay["combined_counts"]["route_owner_records"],
    static_owner_pages=reviewed_finance_chart_route_action_overlay["combined_counts"]["page_owner_records"],
    static_owner_features=reviewed_finance_chart_route_action_overlay["combined_counts"]["distinct_feature_ids"],
    static_owner_h_features=reviewed_finance_chart_route_action_overlay["combined_counts"]["distinct_H_feature_ids"],
    static_owner_d_features=reviewed_finance_chart_route_action_overlay["combined_counts"]["distinct_D_feature_ids"],
    route_feature_ids=reviewed_finance_chart_route_action_overlay["combined_counts"]["route_distinct_feature_ids"],
    page_feature_ids=reviewed_finance_chart_route_action_overlay["combined_counts"]["page_distinct_feature_ids"],
    route_page_overlap=reviewed_finance_chart_route_action_overlay["combined_counts"]["route_page_feature_overlap"],
    static_action_bridges=reviewed_finance_chart_route_action_overlay["combined_counts"]["static_controller_action_bridges"],
    static_residual=f"{reviewed_finance_chart_route_action_overlay['combined_counts']['bounded_static_source_residual_records']:,}",
    ownership_percent=reviewed_finance_chart_route_action_overlay["combined_counts"]["bounded_static_source_ownership_percent"],
    route_residual=f"{reviewed_finance_chart_route_action_overlay['combined_counts']['residual_explicit_unmapped_routes']:,}",
    route_shared_current=reviewed_finance_chart_route_action_overlay["combined_counts"]["semantic_shared_routes"],
    route_alias_current=reviewed_finance_chart_route_action_overlay["combined_counts"]["reviewed_alias_routes"],
    page_shared=reviewed_finance_chart_route_action_overlay["combined_counts"]["semantic_shared_page_roots"],
    page_residual=reviewed_finance_chart_route_action_overlay["combined_counts"]["residual_unadjudicated_page_roots"],
    page_gap=reviewed_finance_chart_route_action_overlay["combined_counts"]["evidence_gap_page_roots_tagged_within_residual"],
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
    finance_wave_reviewed=reviewed_finance_chart_route_action_overlay["reviewed_overlay"]["reviewed_route_actions"],
    finance_review_owner=reviewed_finance_chart_route_action_overlay["reviewed_overlay"]["owner_route_actions"],
    finance_review_shared=reviewed_finance_chart_route_action_overlay["reviewed_overlay"]["shared_relations"],
    finance_review_alias=reviewed_finance_chart_route_action_overlay["reviewed_overlay"]["alias_or_redirect"],
    finance_review_dead=reviewed_finance_chart_route_action_overlay["reviewed_overlay"]["dead_or_noncanonical"],
    finance_review_gap=reviewed_finance_chart_route_action_overlay["reviewed_overlay"]["evidence_gaps"],
    finance_page_calls=reviewed_finance_chart_route_action_overlay["page_context_boundary"]["literal_callsites"],
    finance_page_owned=reviewed_finance_chart_route_action_overlay["page_context_boundary"]["currently_owned_page_callsites"],
    finance_page_unowned=reviewed_finance_chart_route_action_overlay["page_context_boundary"]["unowned_page_callsites"],
    finance_page_authorized=reviewed_finance_chart_route_action_overlay["page_context_boundary"]["page_ownership_authorized"],
    queue_gap=reviewed_finance_chart_route_action_overlay["queue_accounting"]["evidence_gap_queue_surface_rows"],
    queue_records=reviewed_finance_chart_route_action_overlay["queue_accounting"]["direct_exact_queue_records"],
    queue_reviewed=reviewed_finance_chart_route_action_overlay["queue_accounting"]["reviewed_queue_surface_rows"],
    queue_pending=reviewed_finance_chart_route_action_overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"],
    queue_without_owner=reviewed_finance_chart_route_action_overlay["queue_accounting"]["queue_surfaces_without_ownership"],
    queue_owner=reviewed_finance_chart_route_action_overlay["queue_accounting"]["owner_queue_surface_rows"],
    queue_shared=reviewed_finance_chart_route_action_overlay["queue_accounting"]["shared_queue_surface_rows"],
    queue_alias=reviewed_finance_chart_route_action_overlay["queue_accounting"]["alias_queue_surface_rows"],
    finding_count=len(findings),
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

output_path = AUDIT_DIR / "audit-dashboard.html"
output_bytes = (dashboard.rstrip() + "\n").encode("utf-8")
temporary_path = output_path.with_name(f".{output_path.name}.tmp-run123-dashboard")
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
