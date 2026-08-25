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
assert all(sha256_file(path) == digest for path, digest in route_page_reporting["outputs"].items())
assert all(value is False for value in route_page_reporting["credit_boundary"].values())

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
  <title>Oblivion Findings current-source audit</title>
  <style>
    :root{color-scheme:light;--ink:#172033;--muted:#5f6b7d;--line:#dce2ec;--panel:#fff;--bg:#f4f6fb;--brand:#5b55f6;--warn:#a04800;--warnbg:#fff2db;--shadow:0 8px 24px rgba(27,35,58,.07)}
    *{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}a{color:#413ad8;text-decoration-thickness:1px;text-underline-offset:3px}a:focus-visible{outline:3px solid #8d88ff;outline-offset:3px;border-radius:4px}
    header{background:linear-gradient(135deg,#1c2140 0%,#3f399f 100%);color:#fff;padding:28px max(20px,calc((100vw - 1180px)/2)) 32px}.eyebrow{font-size:.78rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#cbc9ff}.hero{display:flex;gap:24px;align-items:end;justify-content:space-between}.hero h1{font-size:clamp(1.8rem,4vw,3rem);line-height:1.08;margin:7px 0 8px;max-width:820px}.hero p{margin:0;color:#e5e4ff;max-width:780px}.badge{display:inline-flex;white-space:nowrap;align-items:center;border:1px solid #f2c675;background:#3e2b18;color:#ffe5b5;border-radius:999px;padding:9px 13px;font-weight:800}
    nav{background:#fff;border-bottom:1px solid var(--line);position:sticky;top:0;z-index:4;overflow:auto}nav div{max-width:1180px;margin:auto;display:flex;gap:20px;padding:11px 20px;white-space:nowrap}nav a{color:#39445a;font-weight:700;text-decoration:none}main{max-width:1180px;margin:0 auto;padding:24px 20px 64px}.notice{background:var(--warnbg);border-left:5px solid #e58d22;padding:14px 16px;border-radius:10px;margin-bottom:22px;color:#633000}
    .cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.card,.panel{background:var(--panel);border:1px solid var(--line);box-shadow:var(--shadow);border-radius:14px}.card{padding:17px}.card strong{display:block;font-size:1.65rem;line-height:1.15}.card span{display:block;color:var(--muted);margin-top:5px}.card small{display:block;margin-top:9px;color:#717d90}.panel{min-width:0;padding:20px;margin-top:20px}.panel h2{font-size:1.25rem;margin:0 0 5px}.panel>p{color:var(--muted);margin:0 0 16px}.table-wrap{max-width:100%;overflow-x:auto;border:1px solid var(--line);border-radius:10px}table{width:100%;border-collapse:collapse;min-width:680px}th,td{text-align:left;padding:11px 12px;border-bottom:1px solid var(--line);vertical-align:top}th{background:#f7f8fc;color:#414d62;font-size:.82rem}tr:last-child td{border-bottom:0}.zero{color:#a03920;font-weight:800}.partial{color:var(--warn);font-weight:800}.split{display:grid;grid-template-columns:1.15fr .85fr;gap:20px}.split>*{min-width:0}.list{margin:0;padding-left:20px}.list code{overflow-wrap:anywhere}.list li+li{margin-top:8px}.mono{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:.88em;overflow-wrap:anywhere}.footer{color:var(--muted);font-size:.88rem;margin-top:24px}
    @media(max-width:900px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.split{grid-template-columns:1fr}.hero{align-items:flex-start;flex-direction:column}.badge{align-self:flex-start}}@media(max-width:520px){header{padding:22px 16px 26px}main{padding:18px 14px 48px}.cards{grid-template-columns:1fr 1fr;gap:10px}.card{padding:14px}.card strong{font-size:1.35rem}.panel{padding:16px}.badge{white-space:normal}nav div{padding-inline:16px}}
  </style>
</head>
<body>
  <header><div class="eyebrow">Oblivion Findings · comprehensive audit restart</div><div class="hero"><div><h1>Fresh current-source audit</h1><p>Evidence is pinned to application commit <span class="mono">$application_short</span>. Historical percentages remain provenance only.</p></div><div class="badge">IN PROGRESS · NOT COMPREHENSIVE</div></div></header>
  <nav aria-label="Audit sections"><div><a href="#progress">Progress</a><a href="#checkpoint">RUN-081</a><a href="#pages">Pages</a><a href="#static-census">Static census</a><a href="#runtime">Runtime gates</a><a href="#benchmarks">Benchmarks</a><a href="#modules">Modules</a><a href="#findings">Provisional findings</a><a href="#architecture">Architecture</a><a href="#gaps">Gaps</a></div></nav>
  <main>
    <div class="notice" role="status"><strong>No completion claim:</strong> RUN-030 freezes 340 current-source static canonical targets (300 H · 40 D · 0 M). RUN-034–038 retain 88 complete observer-only and 7 partial project records without formal triage credit. RUN-039–046 approve 0 formal edges for the first six-target overlay. RUN-047–052 remain an immutable historical diagnostic checkpoint with a missing clean Agent A-to-B handoff. RUN-053–057 reconstruct that handoff through 24 selected facet packets (8 exact · 4 partial-adjacent · 12 insufficient-adjacent), 252 blind atoms (165 consumed · 87 retained unknown), 144 fresh-C lens ratings, and 226 fresh-D reviews. D accepts 225 reviews and makes one bounded correction to AO-A53-024-01; it creates 0 formal edges and 0 final no-matches. RUN-058-BROWSER–060 preserve a read-only signed-in observation of $unknown_build_routes selected routes, $unknown_build_cells route/viewport cells, and $unknown_build_overlays overlay families on an unattributed deployed build; $unknown_build_candidates provisional candidates remain unknown-build only. Formal upstream RUN-058A–070 preserves $formal_attempts initial project records across $formal_targets selected targets and $formal_subrecords initial facet/aspect records; independent controls accept $formal_accepted formal project records and $formal_facets bounded facet records, while all target edges and final no-matches remain zero. RUN-077–080 now record $route_primary_calls primary route-facade callsites plus one separate route-like sentinel, $route_name_calls fluent-name callsites, and $route_page_roots page roots; three cyclic independent reviews are GO, but $route_unmapped route-like rows and $page_evidence_gaps page roots retain explicit evidence gaps. RUN-080 changes only $route_page_rows_changed rows / $route_page_field_changes route-name or page-file fields; immutable and benchmark/credit projections remain equal, the live matrix is <span class="mono">$route_page_matrix_short</span>, mapping remains 0/340, and current-source runtime, browser, rendered visual, executed-test, benchmark, ease, release, Pass, and audit-completion credit remain zero.</div>
    <div class="notice" role="status"><strong>RUN-071–081 current reporting checkpoint:</strong> all 26 completion gates are reconciled. RUN-071's 9/18 and RUN-072's 11/18 are historical snapshots; RUN-073 has all 18 prompt-required files/directories present, including <span class="mono">evidence/</span> and excluding this generated dashboard. Presence is not completion. RUN-072 retains 300/300 source-bound H contracts pinned to the historical base matrix, with 0 validated tasks and every current/target measurement <span class="mono">NOT_MEASURED</span>; their copied locators were not silently refreshed. RUN-073 adds 8 independently source-reviewed journeys and separately reviewed architecture evidence. RUN-074–076 reconstruct feature-side linkage. RUN-077–079 materialize and independently review the exhaustive committed static route/name/page universe; RUN-080 integrates 78 route-name and 2 page-file fields; RUN-081 refreshes reports and hashes. The matrix retains $route_gap_count route-path, $route_name_gap_count route-name, $page_gap_count page-file, $both_gap_count combined route/page, $backend_gap_count backend-anchor, and $test_gap_count test-anchor gaps. Full route/page-to-feature mapping and framework reachability remain open. Prompt-grade journey execution, final/runtime architecture findings, mapping, final-no-match, NCM, runtime, application browser, executed tests, ease, Pass, and completion credit remain zero.</div>
    <section id="progress" class="cards" aria-label="Current audit progress">
      <div class="card"><strong>8,454</strong><span>tracked source paths</span><small>committed-tree census</small></div><div class="card"><strong>$route_calls</strong><span>static route callsites</span><small>not runtime routes</small></div><div class="card"><strong>$page_root_count</strong><span>static Inertia page roots</span><small>$resolver_count paths partitioned; prompt gate open</small></div><div class="card"><strong>$canonical_count</strong><span>canonical static targets</span><small>$h_count H · $d_count D · $m_count M</small></div>
      <div class="card"><strong>$mapped_sources / $source_count</strong><span>discovery sources mapped</span><small>one bounded source excluded</small></div><div class="card"><strong class="partial">$finding_count</strong><span>provisional P1 claims</span><small>none final</small></div><div class="card"><strong class="zero">0</strong><span>current runtime tests</span><small>vendor absent; setup not run</small></div><div class="card"><strong class="zero">0</strong><span>current-source browser routes</span><small>$unknown_build_routes unknown-build routes observed; attribution unproved</small></div>
    </section>
    <section id="checkpoint" class="panel">
      <h2>RUN-071–081 completion-gate checkpoint</h2>
      <p>The 26 literal completion gates were reconciled before RUN-072 added source-bound usability and incident-chain evidence, RUN-073 materialized reporting paths and source synthesis, RUN-074–076 reconstructed bounded feature-side linkage, and RUN-077–081 materialized, independently reviewed, integrated, and reported the exhaustive committed static route/name/page universe. Static artifacts are not measured task, runtime, browser, mapping, Pass, final-finding, or completion evidence.</p>
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
        <tr><td>Benchmark mapping/register credit</td><td><strong class="zero">0</strong></td><td class="zero">0 target edges · 0 final no-matches · 0 NCM · 0 / 340 mapped</td></tr>
      </tbody></table></div>
      <ul class="list">$checkpoint_evidence_links</ul>
    </section>
    <section id="pages" class="panel"><h2>Current static Inertia page partition</h2><p>RUN-010 partitioned every resolver TSX path for render/import identity. RUN-077–079 then materialized a decision record and independent review for all $route_page_roots file-backed page roots. This is static triage, not final prompt classification, runtime reachability, build resolution, or rendered browser evidence.</p><div class="table-wrap"><table><thead><tr><th>Partition</th><th>Count</th><th>Current static identity</th></tr></thead><tbody><tr><td>Existing literal backend render roots</td><td>$page_root_count</td><td class="partial">static file-backed page roots</td></tr><tr><td>RUN-079 reviewed prompt status</td><td>$page_reviewed</td><td class="partial">reviewed static owner/status only</td></tr><tr><td>RUN-079 retained prompt evidence gap</td><td>$page_evidence_gaps</td><td class="zero">exact ownership evidence still open</td></tr><tr><td>Unrendered but imported</td><td>$support_count</td><td class="partial">support/components, not page roots</td></tr><tr><td>Unrendered/unimported aliases or legacy</td><td>$legacy_count</td><td class="partial">10 Redirect/legacy + 10 Duplicate</td></tr><tr><td>Unrendered/unimported dead or demo</td><td>$dead_demo_count</td><td class="partial">3 Dead/unreachable + 2 Out of product scope</td></tr><tr><td>Missing backend render literals</td><td>$missing_target_count</td><td class="zero">retired/unrouted liabilities; zero page credit</td></tr><tr><td><strong>Resolver TSX partitioned</strong></td><td><strong>$resolver_count</strong></td><td class="partial">963/963 static render/import identity; prompt gate open</td></tr></tbody></table></div></section>
    <div class="split"><section class="panel"><h2>Evidence waves represented</h2><p>RUN-001 through RUN-081 are represented by audit artifacts; none grants current-source application runtime, browser, executed-test, benchmark-mapping, or completion credit.</p><ul class="list"><li>RUN-001–016: census, discovery, page/static visual, and benchmark-metadata foundations</li><li>RUN-017–022: frontline/platform identity adjudication and blocked-owner reconciliation</li><li>RUN-023–025: cross-scope and remaining-owner arbitration</li><li>RUN-026–030: report/medical ownership, denominator integration, red team, and frozen 340-target identity</li><li>RUN-031–038: complete observer-only project materialization and blocker review; 88 complete observer-only · 7 partial</li><li>RUN-039–046: first six-target comparison overlay; 6 NO-GO · 0 formal edges · unchanged 0/340</li><li>RUN-047–052: historical 24-packet diagnostic checkpoint; mechanically complete but missing the clean Agent A-to-B derivation</li><li>RUN-053–054: identity-stripped Agent A packets, 252-atom crosswalk, fresh Agent B neutralization, and provenance-only correction</li><li>RUN-055: sealed fresh Agent C comparison; 24 rows · 144 lenses · 24 units · 58 outcomes · 85 unknowns preserved</li><li>RUN-056: sealed fresh Agent D adjudication; 226 reviews · 225 accepted · 1 bounded correction · 0 rejected</li><li>RUN-057: deterministic corrected-chain integration; 0 formal edges · 0 final no-matches · unchanged 0/340</li><li>RUN-058-BROWSER–060: $unknown_build_routes routes · $unknown_build_viewports viewports · $unknown_build_cells cells · $unknown_build_overlays overlays · $unknown_build_candidates provisional candidates · 0 current-source credit</li><li>RUN-058A–070 formal upstream: $formal_attempts project records · $formal_prompt_repos prompt repositories · $formal_historical historical extra · $formal_targets targets · $formal_subrecords initial facet/aspect records · $formal_accepted project records accepted · $formal_facets facet records accepted</li><li>RUN-071: 26 literal completion gates and downstream/usability/visual readiness reconciled; historical pre-materialization snapshot 9/18 required deliverables</li><li>RUN-072: historical 11/18 snapshot · 300/300 source-bound H contracts · 0 validated tasks · all current/target scores <span class="mono">NOT_MEASURED</span> · 3-target static slice · expired-auth 0 cells · incident A/B/C/D candidate chain with Agent D NO-GO and zero edge/final-no-match/NCM credit</li><li>RUN-073: 18/18 required paths present · reports 07–12 and findings materialized source-only · 8/8 independently source-reviewed journeys with 27/8/9 handoffs and 0 prompt-grade executions · 13 entity families · 17 concerns · 9 provisional architecture claims · 10 explicit unknowns · 0 final/runtime findings</li><li>RUN-074–076: $static_reviewed_targets gap targets · $static_original_missing_cells original missing scoped cells · cyclic independent review · $static_rows_changed rows / $static_field_changes permitted linkage cells changed · immutable and benchmark/credit projections unchanged · 0 downstream credit</li><li>RUN-077: $route_primary_calls primary route-facade callsites + 1 separate route-like sentinel · $route_name_calls fluent names · $route_page_roots page roots · exact three-part manifest</li><li>RUN-078–079: $route_like_rows route-like, $route_name_calls name, and $route_page_roots page decision records · 3 cyclic independent GO reviews · 0 invalid decisions · 0 reviewer writes</li><li>RUN-080–081: $route_page_rows_changed rows / $route_page_field_changes route-name/page-file fields integrated · current reports and artifact hashes refreshed · immutable and benchmark/credit projections unchanged · 0 downstream credit</li></ul></section><section class="panel"><h2>Execution credit</h2><p>Static, observer, source-comparison, formal-upstream triage records, and unknown-build deployed observations are not attributable current-source runtime evidence.</p><ul class="list"><li><span class="zero">0</span> framework route executions</li><li><span class="zero">0</span> current application tests</li><li><span class="zero">0</span> rendered current-build visual instances</li><li><span class="zero">0</span> current-build application browser routes</li><li><span class="zero">0</span> benchmark mappings promoted</li><li><span class="zero">0</span> completed Pass 1–8 modules</li></ul></section></div>
    <section id="static-census" class="panel"><h2>Expanded static coverage wave</h2><p>RUN-030 freezes canonical static identity; RUN-077–081 add exhaustive committed static route/name/page decision evidence. Rendered coverage, schema truth, runtime, benchmark, ease, release, and completion gates remain open.</p><div class="table-wrap"><table><thead><tr><th>Static universe</th><th>Denominator</th><th>Current boundary</th></tr></thead><tbody><tr><td>Discovery sources / Layer-A edges</td><td>$mapped_sources of $source_count / $layer_a_edges</td><td class="partial">one bounded source excluded; $layer_a_targets Layer-A targets</td></tr><tr><td>Canonical targets</td><td>$canonical_count / $h_count H · $d_count D · $m_count M</td><td class="partial">static identity frozen; no downstream credit</td></tr><tr><td>Remaining route-path / route-name / page-file gaps</td><td>$route_gap_count / $route_name_gap_count / $page_gap_count</td><td class="partial">RUN-080 matrix sentinels; full mapping/reachability open</td></tr><tr><td>Remaining combined route/page / backend / static test gaps</td><td>$both_gap_count / $backend_gap_count / $test_gap_count</td><td class="partial">static owners/locators only; tests unexecuted</td></tr><tr><td>Primary route-facade / separate route-like sentinel</td><td>$route_primary_calls / 1</td><td class="partial">$route_like_rows review rows; no framework expansion</td></tr><tr><td>Route decision classes</td><td>$route_owner owner · $route_shared shared · $route_alias alias · $route_unmapped explicit unmapped</td><td class="partial">cyclic independent static review; 0 feature mappings</td></tr><tr><td>Fluent-name callsites</td><td>$route_name_calls</td><td class="partial">static name decisions; framework reachability unproved</td></tr><tr><td>Page-root prompt status</td><td>$page_reviewed reviewed · $page_evidence_gaps evidence gap</td><td class="partial">$route_page_roots roots total; 0 rendered</td></tr><tr><td>Named navigation/tab source files</td><td>$nav_file_count</td><td class="partial">definitions, not runtime-visible links</td></tr><tr><td>Hero definitions / instances</td><td>$hero_definitions / $hero_instances</td><td class="partial">static AST; 0 rendered</td></tr><tr><td>Overlay definitions / instances</td><td>$overlay_definitions / $overlay_instances</td><td class="partial">static AST; 0 rendered</td></tr><tr><td>Declarative / direct / named triggers</td><td>$declarative_triggers / $direct_triggers / $named_triggers</td><td class="partial">row-level static locators; 0 interactions</td></tr><tr><td>Required visual matrix rows</td><td>$visual_matrix_rows</td><td class="partial">49 columns complete; every row browser-blocked</td></tr><tr><td>Models / policies / service entries</td><td>$models / $policies / $services</td><td class="partial">directory/declaration census, not ownership completion</td></tr><tr><td>Jobs / events / listeners</td><td>$jobs / $events / $listeners</td><td class="partial">static owners, no queue execution</td></tr><tr><td>Migrations / PHP test files</td><td>$migrations / $php_test_files</td><td class="partial">history and file locators; 0 database/test execution; unreproducible lexical-case count omitted</td></tr></tbody></table></div></section>
    <section id="runtime" class="panel"><h2>Runtime and deployed-build identity gates</h2><p>These checks record whether execution is safe and attributable. None grants current-source application execution credit.</p><div class="table-wrap"><table><thead><tr><th>Gate</th><th>Observed fact</th><th>Result</th></tr></thead><tbody><tr><td>Local PHP/runtime</td><td>PHP $php_version; test-oriented settings; <span class="mono">vendor/autoload.php</span> absent</td><td class="zero">Laravel not booted; 0 runtime tests</td></tr><tr><td>Repository setup</td><td>Combined setup includes dependencies, forced migration, frontend build, and device configuration</td><td class="zero">State-changing setup not run</td></tr><tr><td>Signed-in deployment</td><td>Inertia component <span class="mono">$deployed_component</span> and deployed assets recorded read-only</td><td class="zero">No authoritative commit/tree marker</td></tr><tr><td>Selected unknown-build sample</td><td>$unknown_build_routes routes across $unknown_build_viewports prompt viewports · $unknown_build_cells cells · $unknown_build_overlays pre-submit overlays · $unknown_build_candidates provisional candidates</td><td class="zero">Accepted as unknown-build observation only; 0 current-source credit</td></tr><tr><td>RUN-072 expired-auth attempt</td><td>3 routes selected; only <span class="mono">/my-day</span> attempted; both contexts ended at <span class="mono">/login</span>; 0 authenticated/base/pre-submit cells, credentials, mutations, or screenshots</td><td class="zero">Build, environment, role, Site, and fixture identities all UNKNOWN; fail-closed with zero credit</td></tr><tr><td>Local build manifest</td><td>Present locally but not tracked at the application source pin</td><td class="zero">Cannot identify the deployed build</td></tr></tbody></table></div></section>
    <section id="benchmarks" class="panel"><h2>Current benchmark wave</h2><p>The prompt denominator remains 98 URL occurrences / 95 unique repositories. RUN-047–052 are preserved as a historical defective checkpoint. RUN-053–057 supply a corrected diagnostic clean-spec chain for 24 selected packets. RUN-058A–070 preserves $formal_attempts initial upstream project records across the same six selected targets. Independent exact-hash FTC review accepts $formal_accepted bounded project records across incident and HR/finance plus $formal_facets bounded HR/finance facet records; medication/clinical remains NO-GO. Formal project/facet-record acceptance is not project or facet selection, a target mapping, or an exhaustive final no-match. All 340 target mappings or final no-matches remain open.</p><div class="table-wrap"><table><thead><tr><th>Evidence slice</th><th>Count</th><th>Current credit</th></tr></thead><tbody><tr><td>Prompt-listed URL occurrences</td><td>$prompt_occurrences</td><td class="partial">$prompt_unique unique repositories; three repeated</td></tr><tr><td>Physical carry-forward register rows</td><td>$register_physical</td><td class="partial">95 exact prompt repos + $extra_rows historical extras</td></tr><tr><td>Official GitHub metadata prerequisite</td><td>$metadata_unique / $prompt_unique</td><td class="partial">$metadata_occurrences / $prompt_occurrences weighted entries; metadata only</td></tr><tr><td>Observer project triage</td><td>$triage_observer_unique / $prompt_unique</td><td class="partial">$triage_observer_occurrences / $prompt_occurrences weighted entries; $triage_complete complete observer-only · $triage_partial partial</td></tr><tr><td>Partial-record review</td><td>$partial_reviewed / 16</td><td class="partial">$partial_resolved resolved observer-only · $partial_retained retained partial; zero downstream credit</td></tr><tr><td>Formal full project triage</td><td>$formal_accepted / $prompt_unique</td><td class="partial">$formal_accepted_weight / $prompt_occurrences weighted entries; project records only</td></tr><tr><td>Formal upstream wave-03 attempts</td><td>$formal_attempts unique records</td><td class="partial">$formal_prompt_repos prompt repositories · $formal_historical historical extra · occurrence weight $formal_weight</td></tr><tr><td>Independently accepted formal records</td><td>$formal_accepted / $formal_attempts</td><td class="partial">$formal_targets targets inspected · $formal_subrecords initial facet/aspect records · $formal_facets bounded facet records accepted · 0 edges · 0 final no-matches</td></tr><tr><td>First six-target overlay</td><td>$target_wave_targets / $canonical_count</td><td class="partial">$target_candidate_packets candidate locators · $target_no_candidate_packets bounded no-candidate; overlay only</td></tr><tr><td>Historical RUN-047–052 diagnostic</td><td>$facet_wave_facets packets / $facet_wave_features features</td><td class="partial">superseded for corrected comparison; retained as provenance only</td></tr><tr><td>Corrected Agent A packets</td><td>$facet_exact exact / $facet_partial partial / $facet_insufficient insufficient</td><td class="partial">all adjacent packets remain non-promotable</td></tr><tr><td>Corrected Agent B atom lineage</td><td class="partial">$facet_atoms total / $facet_consumed consumed / $facet_unknown_atoms unknown</td><td class="partial">$facet_units units · $facet_outcomes outcomes · zero neutral credit</td></tr><tr><td>Fresh Agent C comparison</td><td>$facet_ratings lenses / $facet_unknowns unknowns</td><td class="partial">same-packet citations only; static comparison credit zero</td></tr><tr><td>Pinned source evidence</td><td>$facet_anchors occurrences / $facet_unique_anchors unique / $facet_anchor_files paths</td><td class="partial">mechanically validated; no mapping credit</td></tr><tr><td>Fresh Agent D adjudication</td><td>$facet_d_reviews reviews / $facet_d_corrections correction</td><td class="partial">24 row lineages pass; AO-A53-024-01 corrected to partial</td></tr><tr><td>Promoted feature mappings or final no-matches</td><td>$promoted_count</td><td class="zero">$facet_edges formal edges · $facet_final_no_matches final no-matches · 0 / 340 credited</td></tr></tbody></table></div></section>
    <section class="panel"><h2>RUN-071B downstream readiness and RUN-072 incident disposition</h2><p>Three exact target IDs were start-ready for a fresh clean-specification chain; none was mapping-ready or credit-ready. The completed incident chain closes only this candidate edge with Agent D NO-GO. It is not an exhaustive final no-match or NCM.</p><div class="table-wrap"><table><thead><tr><th>Start-ready target IDs</th><th>Readiness</th><th>Current disposition</th></tr></thead><tbody><tr><td class="mono">$start_ready_ids</td><td class="partial">3 start-ready · 0 mapping-ready · 0 credit-ready</td><td class="zero">Incident candidate NO-GO · 0 edges · 0 final no-matches · 0 NCM</td></tr></tbody></table></div></section>
    <section id="modules" class="panel"><h2>Canonical static feature modules</h2><p>$module_count module labels across $canonical_count canonical static targets. Module completion credit remains zero.</p><div class="table-wrap"><table><thead><tr><th>Module label</th><th>H</th><th>D</th><th>M</th><th>Total</th></tr></thead><tbody>$module_rows</tbody></table></div></section>
    <section id="findings" class="panel"><h2>Provisional current-source P1 claims</h2><p>None is a final finding, verified exploit, remediated issue, or closed gate.</p><div class="table-wrap"><table><thead><tr><th>ID</th><th>Static concern</th><th>Status</th></tr></thead><tbody>$finding_rows</tbody></table></div></section>
    <section id="architecture" class="panel"><h2>Separate provisional architecture source claims</h2><p>Nine independently source-reviewed candidates (7 P1 · 2 P2) remain outside the 12-row discovery finding table. They have 0 final/runtime finding credit.</p><div class="table-wrap"><table><thead><tr><th>ID</th><th>Priority</th><th>Narrow source condition</th><th>Status</th></tr></thead><tbody>$architecture_rows</tbody></table></div></section>
    <section id="gaps" class="panel"><h2>Literal completion gates still open</h2><div class="split"><ul class="list"><li>Framework route reachability and route/page-to-feature mapping</li><li>RUN-080 retained matrix gaps: $route_gap_count route path, $route_name_gap_count route names, $page_gap_count page files, $both_gap_count combined route/page, $backend_gap_count backend anchors, and $test_gap_count static test anchors</li><li>Resolve $route_unmapped explicit unmapped route-like rows and $page_evidence_gaps page-root evidence gaps without promoting presence or overlap as mapping</li><li>Complete route/page-to-feature mapping, framework reachability, and backend/data/test ownership</li><li>Full current project behaviour/licence/edition triage and one final mapping or documented no-match per frozen feature</li><li>Refresh RUN-072 base-matrix locator snapshots separately, then representative-role validation of all 300 contracts plus ten current and ten target dimension measurements per H target: 0 validated · 0 measured · 0 independent ease reviews</li></ul><ul class="list"><li>8/8 journeys are source reconstructed and independently source-reviewed; prompt-grade runtime/browser execution and all four viewport lanes remain 0/8</li><li>Rendered hero, overlay, trigger, and material-state coverage from the completed static matrix</li><li>Build-attributed independent resampling of both unknown-build provisional candidates</li><li>Safe current-build application browser/runtime lanes</li><li>Every module through Passes 1–8</li><li>Fresh Pass 8, final artifact freeze, reconciliation, and no-live-agent gate</li></ul></div></section>
    <section class="panel"><h2>Formal upstream evidence</h2><p>Every materialized producer, reviewer, correction, provenance, feasibility, checklist, and integration artifact is linked with its sealed SHA-256. Bounded project/facet-record acceptance remains separate from project/facet selection, comparison, mapping, NCM, and completion credit.</p><ul class="list">$formal_evidence_links</ul></section>
    <section class="panel"><h2>Prior audit-dashboard verification</h2><p>RUN-070, RUN-072, RUN-073, and RUN-076 responsive verification are immutable history for superseded HTML; no prior viewport, overflow, navigation, table, link, or console proof transfers to RUN-081.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-formal-upstream-wave-03.json">Superseded RUN-070 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-072-wave-04.json">Superseded RUN-072 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-073-wave-05.json">Superseded RUN-073 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-076-wave-06.json">Superseded RUN-076 verification</a></li></ul></section>
    <section class="panel"><h2>Fresh RUN-081 audit-dashboard verification</h2><p>The exact generated dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844 after publication. The linked receipt records page overflow, bounded mobile table scrolling, navigation, local links, console output, and the exact dashboard/generator hashes. This can verify the audit artifact only and grants no application-browser, responsive, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-081-wave-07.json">RUN-081 responsive audit-dashboard verification receipt</a></li></ul></section>
    <section class="panel"><h2>RUN-071–081 evidence lineage</h2><p>Every current raw, generated, reviewed, and integrated RUN-077–081 artifact is linked with its exact SHA-256. Earlier receipts remain immutable history; the task-script directory link carries the historical RUN-072 300-file bundle hash.</p><ul class="list">$checkpoint_evidence_links</ul></section>
    <section class="panel"><h2>Evidence files</h2><ul class="list"><li><a href="00-executive-summary.md">Executive summary</a></li><li><a href="01-repository-module-map.md">Current repository and page map</a></li><li><a href="02-repository-module-map-wave-02.md">Wave 02 module map</a></li><li><a href="02-eight-pass-coverage-ledger.csv">Provisional eight-pass route-file ledger</a></li><li><a href="03-feature-to-benchmark-matrix.csv">340-row canonical static feature matrix</a></li><li><a href="05-browser-visual-coverage-matrix.csv">2,812-row static visual matrix</a></li><li><a href="06-open-source-benchmark-register.csv">Current carry-forward benchmark register</a></li><li><a href="evidence/source/current-canonical-feature-identity-wave-01.json">RUN-030 canonical feature identity</a></li><li><a href="evidence/source/current-canonical-identity-agent-register.json">RUN-030 identity agent register</a></li><li><a href="evidence/source/current-static-semantic-census.json">Initial semantic census JSON</a></li><li><a href="evidence/source/current-route-navigation-gap-wave-01.json">Route/navigation reconciliation</a></li><li><a href="evidence/source/current-visual-static-census-wave-01.json">Visual static census</a></li><li><a href="evidence/source/current-visual-matrix-materialization-wave-01.json">Visual matrix materialization evidence</a></li><li><a href="evidence/source/current-visual-matrix-agent-register.json">Visual matrix agent register</a></li><li><a href="evidence/source/current-backend-data-test-census-wave-01.json">Backend/data/test census</a></li><li><a href="evidence/source/current-static-coverage-agent-register.json">Static coverage agent register</a></li><li><a href="evidence/source/current-page-adjudication-wave-01.json">Page adjudication evidence</a></li><li><a href="evidence/source/current-page-agent-register.json">Page agent register</a></li><li><a href="evidence/source/current-feature-discovery-wave-01.json">Feature wave 01 JSON</a></li><li><a href="evidence/source/current-feature-discovery-wave-02.json">Feature wave 02 JSON</a></li><li><a href="evidence/source/current-feature-discovery-wave-03.json">Feature wave 03 gap additions</a></li><li><a href="evidence/benchmark/current-benchmark-wave-01.json">Benchmark wave evidence</a></li><li><a href="evidence/benchmark/current-benchmark-agent-register.json">Benchmark agent register</a></li><li><a href="evidence/benchmark/current-benchmark-metadata-agent-register.json">Benchmark metadata agent register</a></li><li><a href="evidence/benchmark/current-github-project-metadata-snapshot.json">Official GitHub metadata snapshot</a></li><li><a href="evidence/benchmark/current-prompt-project-denominator-reconciliation.json">Prompt project denominator reconciliation</a></li><li><a href="evidence/benchmark/current-upstream-project-triage-wave-01.json">RUN-034 upstream observer triage</a></li><li><a href="evidence/benchmark/current-upstream-project-triage-agent-register.json">RUN-034 upstream triage agent register</a></li><li><a href="evidence/benchmark/current-upstream-partial-resolution-wave-01.json">RUN-038 partial-record review</a></li><li><a href="evidence/benchmark/current-upstream-partial-resolution-agent-register.json">RUN-038 partial-review agent register</a></li><li><a href="evidence/runtime/current-runtime-safety-assessment.json">Runtime safety assessment</a></li><li><a href="evidence/browser/deployed-build-identity-assessment.json">Deployed build identity assessment</a></li><li><a href="evidence/browser/deployed-selected-feature-observation-wave-03.json">RUN-058-BROWSER sealed unknown-build observation</a></li><li><a href="evidence/browser/raw-run-059b-independent-unknown-build-browser-review-wave-03.json">RUN-059B independent observation review</a></li><li><a href="evidence/browser/current-deployed-selected-feature-observation-wave-03.json">RUN-060 normalized unknown-build observation</a></li><li><a href="evidence/browser/raw-run-060r-s-independent-browser-normalization-adjudication-wave-03.json">RUN-060R/S normalization adjudication</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-unknown-build-browser-wave-03.json">RUN-060 responsive audit-dashboard verification</a></li><li><a href="13-unresolved-questions-and-evidence-gaps.md">Unresolved evidence gaps</a></li></ul></section>
    <section class="panel"><h2>RUN-058-BROWSER–060 unknown-build browser evidence</h2><p>The signed-in sample is preserved as an independently accepted observation of an unattributed deployed build only. It records $unknown_build_routes selected routes, $unknown_build_viewports prompt dimensions, $unknown_build_cells route/viewport cells, $unknown_build_overlays pre-submit overlays, and $unknown_build_candidates provisional unknown-build candidates. No forms were submitted, no records changed, no screenshots retained, and no current-source browser, responsive, visual, workflow, finding, ease, release, Pass, or completion credit is awarded.</p><ul class="list"><li><a href="evidence/browser/deployed-selected-feature-observation-wave-03.json">RUN-058-BROWSER sealed raw observation</a></li><li><a href="evidence/browser/raw-run-059b-independent-unknown-build-browser-review-wave-03.json">RUN-059B independent review</a></li><li><a href="generators/integrate-deployed-selected-feature-observation-wave-03.py">RUN-060 deterministic normalizer</a></li><li><a href="evidence/browser/current-deployed-selected-feature-observation-wave-03.json">RUN-060 normalized observation</a></li><li><a href="evidence/browser/raw-run-060r-s-independent-browser-normalization-adjudication-wave-03.json">RUN-060R/S independent lineage adjudication</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-unknown-build-browser-wave-03.json">RUN-060 responsive audit-dashboard verification</a></li></ul></section>
    <section class="panel"><h2>RUN-039–046 target comparison evidence</h2><p>Clean-stage packets and deterministic integration for the first six-target wave; all formal edges and downstream credits remain zero.</p><ul class="list"><li><a href="evidence/benchmark/raw-run-039-target-upstream-behaviour-wave-01.json">RUN-039 upstream behaviour locators</a></li><li><a href="evidence/benchmark/raw-run-040-current-product-source-packets-wave-01.json">RUN-040 current-source packets</a></li><li><a href="evidence/benchmark/raw-run-041-current-source-red-team-wave-01.json">RUN-041 scope red-team</a></li><li><a href="evidence/benchmark/raw-run-042-neutral-requirements-wave-01.json">RUN-042 blind neutral requirements</a></li><li><a href="evidence/benchmark/raw-run-043-current-neutral-comparison-wave-01.json">RUN-043 clean current-neutral comparisons</a></li><li><a href="evidence/benchmark/raw-run-044-current-source-facet-reconciliation-wave-01.json">RUN-044 composite facet reconciliation</a></li><li><a href="evidence/benchmark/raw-run-045-wave-01-independent-adjudication.json">RUN-045 independent adjudication</a></li><li><a href="evidence/benchmark/current-target-neutral-comparison-wave-01.json">RUN-046 integrated comparison overlay</a></li><li><a href="evidence/benchmark/current-target-neutral-comparison-agent-register.json">RUN-046 comparison agent register</a></li></ul></section>
    <section class="panel"><h2>RUN-047–052 historical diagnostic evidence</h2><p>The former 24-packet chain is retained only as an immutable zero-credit checkpoint. Its missing sanitized Agent A-to-B derivation is historical and its RUN-048/RUN-050/RUN-051 payloads are prohibited as corrected comparison evidence.</p><ul class="list"><li><a href="evidence/benchmark/raw-run-047-upstream-facet-refinement-clinical-incident-wave-02.json">RUN-047 clinical and incident upstream packets</a></li><li><a href="evidence/benchmark/raw-run-047-upstream-facet-refinement-composites-wave-02.json">RUN-047 HR, medication, and finance upstream packets</a></li><li><a href="evidence/benchmark/current-upstream-facet-refinement-wave-02.json">RUN-047 integrated upstream refinement</a></li><li><a href="evidence/benchmark/current-upstream-facet-refinement-agent-register.json">RUN-047 upstream agent register</a></li><li><a href="evidence/benchmark/raw-run-048-blind-neutral-facet-requirements-wave-02.json">RUN-048 historical source-independent requirements</a></li><li><a href="evidence/benchmark/raw-run-049-current-source-facet-refinement-wave-02.json">RUN-049 pinned current-source packets</a></li><li><a href="evidence/benchmark/raw-run-050-clean-facet-comparison-reconciled-wave-02.json">RUN-050 historical reconciled comparison</a></li><li><a href="evidence/benchmark/raw-run-051-independent-facet-adjudication-wave-02.json">RUN-051 historical independent adjudication</a></li></ul></section>
    <section class="panel"><h2>RUN-053–057 corrected clean-spec evidence</h2><p>Fresh A/B/C/D stages reconstruct the required clean handoff for 24 selected packets. Fresh D validates all 226 reviews, corrects one outcome from met to partial, and preserves zero formal edges, zero final no-matches, and 0/340 credit.</p><ul class="list"><li><a href="evidence/benchmark/raw-run-053-agent-a-blind-observed-behaviour-packets-wave-02.json">RUN-053 identity-stripped Agent A packets</a></li><li><a href="evidence/benchmark/root-run-053-agent-a-source-atom-crosswalk-wave-02.json">RUN-053 root-held atom and identity crosswalk</a></li><li><a href="evidence/benchmark/raw-run-054-fresh-agent-b-neutral-requirements-wave-02.json">RUN-054 fresh Agent B neutral requirements</a></li><li><a href="evidence/benchmark/raw-run-054-agent-b-input-boundary-correction-wave-02.json">RUN-054 provenance-only boundary correction</a></li><li><a href="evidence/benchmark/raw-run-055-agent-c-comparison-input-wave-02.json">RUN-055 sealed Agent C input</a></li><li><a href="evidence/benchmark/raw-run-055-fresh-agent-c-current-comparison-wave-02.json">RUN-055 fresh Agent C comparison</a></li><li><a href="evidence/benchmark/raw-run-056-independent-adjudicator-input-wave-02.json">RUN-056 sealed Agent D input</a></li><li><a href="evidence/benchmark/raw-run-056-fresh-independent-corrected-chain-adjudication-wave-02.json">RUN-056 fresh independent adjudication</a></li><li><a href="evidence/benchmark/current-facet-neutral-comparison-wave-02.json">RUN-057 integrated corrected-chain overlay</a></li><li><a href="evidence/benchmark/current-facet-neutral-comparison-agent-register.json">RUN-057 corrected-chain agent register</a></li></ul></section>
    <p class="footer">Generated deterministically from evidence integrated through RUN-080 and reported in RUN-081. Audit artifacts only; no application remediation is authorised.</p>
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
temporary_path = output_path.with_name(f".{output_path.name}.tmp-run081-dashboard")
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
