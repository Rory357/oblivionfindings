#!/usr/bin/env python3
"""Materialize the independent RUN-122 Finance owner-overlay review receipt."""

from __future__ import annotations

import hashlib
import json
import os
import runpy
import subprocess
from collections import Counter
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_PATH = AUDIT_DIR / "evidence/source/raw-run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json"
PRODUCER_PATH = AUDIT_DIR / "evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json"
GENERATOR_PATH = AUDIT_DIR / "generators/integrate-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.py"
COHORT_PATH = AUDIT_DIR / "evidence/source/root-run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18.json"
COHORT_REVIEW_PATH = AUDIT_DIR / "evidence/source/raw-run-121r-independent-outcome-neutral-finance-chart-route-action-review-wave-18.json"

AUDIT_HEAD = "dc61d8753ca1d5b97c58e478ac45900cd9749b68"
AUDIT_TREE = "60bae16b7b3e370e9710810cff36926643b35757"
PRODUCER_CHECKPOINT_HEAD = "141668c7734191ce9c9cc1b6506d97c958d5e43b"
PRODUCER_CHECKPOINT_TREE = "86a062d9a7d913f17af1c7b5397150c2c5757bb7"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
GENERATOR_SHA256 = "04e28529615267699a2c8e844cf074057e18a9019fc511ed65f7c0203dead390"
PRODUCER_SHA256 = "d7aee21e7c4230b44707a22b7fa93478a84e9a5b4775ecd25aaffede764855ca"
COHORT_SHA256 = "cfe0e3635e5e86bf8e7e2f65d2094743738bfa5edc36e361ecf5eb14986f316e"
COHORT_REVIEW_SHA256 = "f70ddd2ddc7ac0c734f4b48bdd19cd2733c3572d038b1dfa1aa185591e567e5f"
GENERATOR_BLOB_ID = "7c68f437489c0b4493eb89a7301df0ba5f4ed4f4"
PRODUCER_BLOB_ID = "85804d93a561336a675a229798dfe215ea0195b4"

EXPECTED_COUNTS = {
    "source_owner_records": 648,
    "route_owner_records": 295,
    "page_owner_records": 353,
    "distinct_feature_ids": 256,
    "distinct_H_feature_ids": 234,
    "distinct_D_feature_ids": 22,
    "route_distinct_feature_ids": 62,
    "page_distinct_feature_ids": 242,
    "route_page_feature_overlap": 48,
    "static_controller_action_bridges": 83,
    "bounded_static_source_denominator": 3929,
    "bounded_static_source_ownership_percent": "16.492746",
    "bounded_static_source_residual_records": 3281,
    "residual_explicit_unmapped_routes": 2906,
    "semantic_shared_routes": 12,
    "reviewed_alias_routes": 5,
    "reviewed_dead_routes": 0,
    "evidence_gap_routes_tagged_within_residual": 7,
    "residual_unadjudicated_page_roots": 349,
    "semantic_shared_page_roots": 9,
    "reviewed_alias_page_roots": 0,
    "reviewed_dead_page_roots": 0,
    "evidence_gap_page_roots_tagged_within_residual": 1,
}

EXPECTED_QUEUE = {
    "direct_exact_queue_records": 507,
    "reviewed_queue_surface_rows": 106,
    "owner_queue_surface_rows": 84,
    "shared_queue_surface_rows": 10,
    "alias_queue_surface_rows": 5,
    "dead_queue_surface_rows": 0,
    "evidence_gap_queue_surface_rows": 7,
    "pending_unreviewed_queue_surface_rows": 401,
    "queue_surfaces_without_ownership": 423,
    "new_reviewed_route_surface_rows": 22,
    "new_owner_route_surface_rows": 7,
    "new_shared_route_surface_rows": 7,
    "new_alias_route_surface_rows": 1,
    "new_evidence_gap_route_surface_rows": 7,
    "wholesale_queue_ownership_authorized": False,
}

EXPECTED_IDENTITY = {
    "owner_candidate_id_list_sha256": "ed77d635a2c72a2b7144eba5ca40416cec4ebffe85dc8ac5fb1a148a293cb946",
    "owner_route_record_id_list_sha256": "c48e1794568f053ec8aded97bdefcdc69ac6a9b4996db11372aceaab10152496",
    "owner_source_record_key_list_sha256": "915eafa4c2ff158389e0a0c56f1a19bb598f625c17b373835737e71e183f6640",
    "owner_action_key_list_sha256": "f817c00d88486e2b07ee6ee1027941b7919e74274a4f920990855529d0c81c15",
    "owner_bridge_key_list_sha256": "e602e3c09dacf6754a55555e8b147e1b57f0d207ebba250d8a7899a5eb3b4ade",
    "owner_candidate_record_sha256_list_sha256": "920a4270885198d41ca9182db7ff88b809e3bebf70a9f3560a1298a772fd4080",
    "owner_decision_record_sha256_list_sha256": "e3c21db4579e4581aef1d1d339188a8a157e2dc05c829185d9af378336582565",
    "owner_queue_id_list_sha256": "da5e433e03b9ba9c3f01eabdffe9667bf36d36c65b64e3717efeec3ed4594b54",
    "owner_queue_key_list_sha256": "dc78a223b5e42a8be432f4a006875dedb0e6c88a4a729d0426f485348fb11863",
    "shared_route_record_id_list_sha256": "9e8e44a0d63a92ef29abd082b9717268470781c484fe8913739c67f4ad2bd812",
    "alias_route_record_id_list_sha256": "7375244863fd376702f50d317f29c3e588ab741a4924317b58b86f85c95ca42c",
    "evidence_gap_route_record_id_list_sha256": "03e106bf8198f640c91187964934ccc0bb106919828f31ac88dd2ab3147c2978",
    "non_owner_candidate_id_list_sha256": "c5127b7e41780d311fa2e74fab2f4723bd888e218ad1e8dd0f157071e22bcb0d",
    "new_union_feature_id_list_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    "new_route_feature_id_list_sha256": "d90bcde4f20f6c0f5059f655a839f425e9807120fe7d109a454918a35301c1d7",
    "new_page_feature_id_list_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    "combined_source_record_key_list_sha256": "2d8a7d222aff1d38eb56fb73615cc5b9ef5186605233c93aa9204141c08f816c",
    "combined_source_record_id_list_sha256": "87de82d3b26880fcc2b8a94ea4deba7fce2e1dd74c454e529207dc55670bd6c3",
    "combined_feature_id_list_sha256": "874e55340e6abb600c50a64a147b81b9b5467587ee77f9e45eace4eb85ac55ca",
    "combined_route_feature_id_list_sha256": "89c69d9c74178944f705a60d004f01e4e5922d06a929b0dfba2342aafd6a3b7a",
    "combined_page_feature_id_list_sha256": "2fa6a11a5c66a016366db84ce89931db906b26cc911dfa88490cb6561ff261e8",
    "combined_route_page_overlap_feature_id_list_sha256": "00b1e6141c19311c68d7f8bcd154d971f2099fa768993cca4845baadd02aa217",
    "combined_bridge_key_list_sha256": "c8bc97e2afe9f849a3bcf81f271e0e43066266a0bd0c65770846ccc36cb88f61",
    "new_reviewed_queue_key_list_sha256": "30772a30ac24b953a432c0a26f983f0238e182b5168922ef60266523ecf38a1e",
    "prior_reviewed_queue_key_list_sha256": "096273b239b8625bc4cffa0537f956773f99039e2b3e7672662a5213b44264e2",
    "combined_reviewed_queue_key_list_sha256": "c1e9746f10ea6ea866b9cb78619f71f5e37533984bda904ea112b0a66a195a9c",
    "new_overlay_source_records_sha256": "91fe36b7d658f0fff2c47443cdad0c9fb9afda131ed810e05af72247bca3675e",
    "new_overlay_row_sha256_list_sha256": "2b11eae91f0c16fc783e15e69e77c978029db274a2369b946af591dee6478842",
    "new_action_bridges_sha256": "56ea6ede49e60b3dc0c31db30d8fc5a896bf1ac3382edfad6522830ca539be04",
    "new_action_bridge_row_sha256_list_sha256": "e1ae3e693fe07218b37ee7209d8d66ca4045c56ae8011352a216724b7e7e3ac8",
    "reviewed_non_owner_outcomes_sha256": "df0e4ef75620ac15b29e9b1672aa668ca12e6943733c739860b2e5c107db666f",
    "reviewed_non_owner_row_sha256_list_sha256": "fd066b63aa880a6f1fb05214505004ea19b7d11ea7a1bbd0502e57019bf032b2",
    "reviewed_decision_record_sha256_list_sha256": "d4d906c711afc6822c0ce29093d515f2ee8775e7a55813be7f28b08680fd40e0",
    "reviewed_decisions_sha256": "47072d2b9453a8177d5ca8474121cf4df1af036b9982dc0ecc020f59175b9a1f",
}


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def canonical_json_sha256(value: Any) -> str:
    raw = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return sha256_bytes(raw.encode("utf-8"))


def canonical_list_sha256(values: list[str] | set[str]) -> str:
    return sha256_bytes("\n".join(sorted(values)).encode("utf-8"))


def load_json(path: Path) -> dict[str, Any]:
    value = json.loads(path.read_text(encoding="utf-8"))
    assert isinstance(value, dict), path
    return value


def git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args], cwd=REPO, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True
    )
    return result.stdout.strip()


def assert_row_digest(row: dict[str, Any], digest_key: str) -> None:
    without_digest = {key: value for key, value in row.items() if key != digest_key}
    assert row[digest_key] == canonical_json_sha256(without_digest)


def build() -> dict[str, Any]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == AUDIT_HEAD
    assert git("rev-parse", "HEAD^{tree}") == AUDIT_TREE
    assert git("rev-parse", "HEAD^") == PRODUCER_CHECKPOINT_HEAD
    assert git("rev-parse", "HEAD^^{tree}") == PRODUCER_CHECKPOINT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("status", "--porcelain", "--", "app", "routes", "resources/js") == ""
    assert sha256_file(GENERATOR_PATH) == GENERATOR_SHA256
    assert sha256_file(PRODUCER_PATH) == PRODUCER_SHA256
    assert sha256_file(COHORT_PATH) == COHORT_SHA256
    assert sha256_file(COHORT_REVIEW_PATH) == COHORT_REVIEW_SHA256
    committed_paths = set(git("diff-tree", "--no-commit-id", "--name-only", "-r", "HEAD").splitlines())
    assert committed_paths == {
        GENERATOR_PATH.relative_to(REPO).as_posix(),
        PRODUCER_PATH.relative_to(REPO).as_posix(),
    }
    assert git("hash-object", str(GENERATOR_PATH)) == git(
        "rev-parse", f"HEAD:{GENERATOR_PATH.relative_to(REPO).as_posix()}"
    ) == GENERATOR_BLOB_ID
    assert git("hash-object", str(PRODUCER_PATH)) == git(
        "rev-parse", f"HEAD:{PRODUCER_PATH.relative_to(REPO).as_posix()}"
    ) == PRODUCER_BLOB_ID

    overlay = load_json(PRODUCER_PATH)
    cohort = load_json(COHORT_PATH)
    review = load_json(COHORT_REVIEW_PATH)
    for relative, expected in overlay["pins"]["inputs"].items():
        input_path = AUDIT_DIR / relative
        assert input_path.is_file(), input_path
        assert sha256_file(input_path) == expected, (relative, sha256_file(input_path), expected)
    assert overlay["pins"]["generator_sha256"] == GENERATOR_SHA256
    assert overlay["pins"]["cohort_generator_sha256"] == cohort["pins"]["generator_sha256"]
    assert overlay["pins"]["review_materializer_sha256"] == review["pins"]["materializer_sha256"]
    assert overlay["pins"]["matrix_sha256"] == MATRIX_SHA256
    assert overlay["pins"]["checkpoint_commit"] == PRODUCER_CHECKPOINT_HEAD
    assert overlay["pins"]["checkpoint_tree"] == PRODUCER_CHECKPOINT_TREE

    generator_namespace = runpy.run_path(str(GENERATOR_PATH))
    producer_build = generator_namespace["build"]
    live_git = producer_build.__globals__["git"]

    def replay_producer_checkpoint_git(*args: str) -> str:
        if args == ("rev-parse", "HEAD"):
            return PRODUCER_CHECKPOINT_HEAD
        if args == ("rev-parse", "HEAD^{tree}"):
            return PRODUCER_CHECKPOINT_TREE
        return live_git(*args)

    producer_build.__globals__["git"] = replay_producer_checkpoint_git
    try:
        rebuilt = producer_build()
    finally:
        producer_build.__globals__["git"] = live_git
    assert rebuilt.pop("identity_discovery") == {
        key: EXPECTED_IDENTITY[key]
        for key in (
            "alias_route_record_id_list_sha256",
            "combined_bridge_key_list_sha256",
            "combined_feature_id_list_sha256",
            "combined_page_feature_id_list_sha256",
            "combined_reviewed_queue_key_list_sha256",
            "combined_route_feature_id_list_sha256",
            "combined_route_page_overlap_feature_id_list_sha256",
            "combined_source_record_id_list_sha256",
            "combined_source_record_key_list_sha256",
            "evidence_gap_route_record_id_list_sha256",
            "new_page_feature_id_list_sha256",
            "new_reviewed_queue_key_list_sha256",
            "new_route_feature_id_list_sha256",
            "new_union_feature_id_list_sha256",
            "non_owner_candidate_id_list_sha256",
            "owner_action_key_list_sha256",
            "owner_bridge_key_list_sha256",
            "owner_candidate_id_list_sha256",
            "owner_candidate_record_sha256_list_sha256",
            "owner_decision_record_sha256_list_sha256",
            "owner_queue_id_list_sha256",
            "owner_queue_key_list_sha256",
            "owner_route_record_id_list_sha256",
            "owner_source_record_key_list_sha256",
            "prior_reviewed_queue_key_list_sha256",
            "shared_route_record_id_list_sha256",
        )
    }
    rebuilt_bytes = (json.dumps(rebuilt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    assert rebuilt_bytes == PRODUCER_PATH.read_bytes()
    assert sha256_bytes(rebuilt_bytes) == PRODUCER_SHA256

    candidates = {row["candidate_id"]: row for row in cohort["records"]}
    decisions = {row["candidate_id"]: row for row in review["action_decisions"]}
    owner_ids = {candidate_id for candidate_id, row in decisions.items() if row["outcome"] == "OWNER_ROUTE_ACTION"}
    shared_ids = {candidate_id for candidate_id, row in decisions.items() if row["outcome"] == "SHARED_RELATION"}
    alias_ids = {candidate_id for candidate_id, row in decisions.items() if row["outcome"] == "ALIAS_OR_REDIRECT"}
    dead_ids = {candidate_id for candidate_id, row in decisions.items() if row["outcome"] == "DEAD_OR_NONCANONICAL"}
    gap_ids = {candidate_id for candidate_id, row in decisions.items() if row["outcome"] == "EVIDENCE_GAP"}
    assert len(candidates) == len(decisions) == 22
    assert owner_ids == {f"RUN121-FINANCE-CHART-ROUTE-ACTION-{index:02d}" for index in range(2, 9)}
    assert alias_ids == {"RUN121-FINANCE-CHART-ROUTE-ACTION-01"}
    assert gap_ids == {f"RUN121-FINANCE-CHART-ROUTE-ACTION-{index:02d}" for index in (9, 10, 11, 12, 14, 15, 16)}
    assert shared_ids == {f"RUN121-FINANCE-CHART-ROUTE-ACTION-{index:02d}" for index in (13, 17, 18, 19, 20, 21, 22)}
    assert dead_ids == set()

    owner_rows = overlay["overlay_source_records"]
    bridges = overlay["new_static_controller_action_bridges"]
    nonowners = overlay["reviewed_non_owner_outcomes"]
    assert len(owner_rows) == len(bridges) == 7
    assert len(nonowners) == 15
    assert {row["candidate_id"] for row in owner_rows} == owner_ids
    assert {row["candidate_id"] for row in bridges} == owner_ids
    assert {row["candidate_id"] for row in nonowners} == shared_ids | alias_ids | gap_ids
    assert Counter(row["outcome"] for row in nonowners) == {
        "SHARED_RELATION": 7,
        "ALIAS_OR_REDIRECT": 1,
        "EVIDENCE_GAP": 7,
    }

    for row in owner_rows:
        assert_row_digest(row, "overlay_row_sha256")
        candidate = candidates[row["candidate_id"]]
        decision = decisions[row["candidate_id"]]
        assert row["candidate_record_sha256"] == candidate["candidate_record_sha256"]
        assert row["decision_record_sha256"] == decision["decision_record_sha256"]
        assert row["source_record_id"] == candidate["route_source"]["route_record_id"]
        assert row["static_source_feature_ownership_credit"] is True
        assert {key for key, value in row["credit_boundary"].items() if value} == set()
    for row in bridges:
        assert_row_digest(row, "bridge_row_sha256")
        candidate = candidates[row["candidate_id"]]
        decision = decisions[row["candidate_id"]]
        assert row["candidate_record_sha256"] == candidate["candidate_record_sha256"]
        assert row["decision_record_sha256"] == decision["decision_record_sha256"]
        assert row["route_record_id"] == candidate["route_source"]["route_record_id"]
        assert row["static_controller_action_bridge_credit"] is True
        assert all(row[key] is False for key in ("page_ownership_credit", "runtime_credit", "application_browser_credit", "executed_test_credit", "completion_credit"))
    for row in nonowners:
        assert_row_digest(row, "reviewed_non_owner_row_sha256")
        candidate = candidates[row["candidate_id"]]
        decision = decisions[row["candidate_id"]]
        assert row["candidate_record_sha256"] == candidate["candidate_record_sha256"]
        assert row["decision_record_sha256"] == decision["decision_record_sha256"]
        assert row["outcome"] == decision["outcome"]
        assert row["route_record_id"] == candidate["route_source"]["route_record_id"]
        assert row["queue_id"] == candidate["queue_id"]
        assert row["source_loci"] == decision["source_loci"]
        assert row["material_dependencies"] == decision["material_dependencies"]
        assert row["review_discrepancies"] == decision["review_discrepancies"]
        assert all(
            row[key] is False
            for key in (
                "route_ownership_credit",
                "controller_action_bridge_credit",
                "page_ownership_credit",
                "correctness_credit",
                "runtime_credit",
                "benchmark_credit",
                "downstream_credit",
                "completion_credit",
            )
        )

    identity = overlay["identity"]
    assert identity == EXPECTED_IDENTITY
    assert identity["owner_candidate_id_list_sha256"] == canonical_list_sha256(owner_ids)
    assert identity["non_owner_candidate_id_list_sha256"] == canonical_list_sha256(shared_ids | alias_ids | gap_ids)
    assert identity["new_overlay_source_records_sha256"] == canonical_json_sha256(owner_rows)
    assert identity["new_action_bridges_sha256"] == canonical_json_sha256(bridges)
    assert identity["reviewed_non_owner_outcomes_sha256"] == canonical_json_sha256(nonowners)
    assert identity["new_overlay_row_sha256_list_sha256"] == canonical_list_sha256([row["overlay_row_sha256"] for row in owner_rows])
    assert identity["new_action_bridge_row_sha256_list_sha256"] == canonical_list_sha256([row["bridge_row_sha256"] for row in bridges])
    assert identity["reviewed_non_owner_row_sha256_list_sha256"] == canonical_list_sha256([row["reviewed_non_owner_row_sha256"] for row in nonowners])

    assert overlay["combined_counts"] == EXPECTED_COUNTS
    assert overlay["queue_accounting"] == EXPECTED_QUEUE
    assert 3929 == 648 + 3281
    assert 648 == 295 + 353
    assert 3218 == 295 + 12 + 5 + 2906
    assert 711 == 353 + 9 + 349
    assert 256 == 62 + 242 - 48
    assert 256 == 234 + 22
    assert 83 == 76 + 7
    assert 507 == 106 + 401
    assert 106 == 84 + 10 + 5 + 7
    assert 423 == 401 + 10 + 5 + 7
    assert overlay["page_context_boundary"] == review["page_context_boundary"]
    assert overlay["identity_reconciliation"] == review["identity_reconciliation"]
    assert overlay["combined_counts"]["evidence_gap_routes_tagged_within_residual"] == 7
    assert overlay["combined_counts"]["evidence_gap_page_roots_tagged_within_residual"] == 1
    assert overlay["denominator_boundary"]["gate_4_complete"] is False
    assert overlay["audit_completion_test_met"] is False
    allowed_true_credit = {
        "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_7_RECORDS",
        "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_7_ACTIONS",
        "REVIEWED_SHARED_RELATION_FOR_7_RECORDS",
        "REVIEWED_ALIAS_OR_REDIRECT_FOR_1_RECORD",
        "REVIEWED_EVIDENCE_GAP_FOR_7_RECORDS",
    }
    assert {key for key, value in overlay["credit_boundary"].items() if value} == allowed_true_credit
    assert overlay["mutation_attestation"] == {
        "application_source_changed": False,
        "matrix_changed": False,
        "runtime_or_external_system_changed": False,
        "audit_artifacts_only": True,
    }

    return {
        "schema_version": "run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18-v1",
        "run_id": "RUN-122R-INDEPENDENT-REVIEWED-OUTCOME-NEUTRAL-FINANCE-CHART-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-18",
        "status": "GO_THREE_PART_OVERLAY_REVIEW_COMPLETE_7_OWNER_7_SHARED_1_ALIAS_7_GAPS_BOUNDED_ROUTE_ACTION_ONLY",
        "reviewed_on": "2026-08-26",
        "decision": {
            "verdict": "GO",
            "independent_reviews": 3,
            "mechanical_discrepancies": 0,
            "semantic_or_preservation_discrepancies": 0,
            "arithmetic_or_conservation_discrepancies": 0,
            "repository_hygiene_discrepancies_remaining": 0,
            "route_owner_records_authorized": 7,
            "controller_action_bridges_authorized": 7,
            "reviewed_shared_records_authorized": 7,
            "reviewed_alias_records_authorized": 1,
            "reviewed_evidence_gap_records_authorized": 7,
            "reviewed_non_owner_records_preserved": 15,
            "page_owner_records_authorized": 0,
            "bounded_static_route_feature_ownership_authorized": True,
            "static_controller_action_bridges_authorized": True,
            "reviewed_non_owner_preservation_authorized": True,
            "static_page_feature_ownership_authorized": False,
            "wholesale_queue_ownership_authorized": False,
            "complete_route_page_feature_crosswalk_authorized": False,
            "matrix_mutation_authorized": False,
            "reporting_materialization_authorized": True,
            "downstream_credit_authorized": False,
            "gate_4_complete": False,
        },
        "pins": {
            "checkpoint_commit": AUDIT_HEAD,
            "checkpoint_tree": AUDIT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "app_tree": APP_TREE,
            "routes_tree": ROUTES_TREE,
            "resources_js_tree": RESOURCES_JS_TREE,
            "resources_js_pages_tree": RESOURCES_JS_PAGES_TREE,
            "matrix_sha256": MATRIX_SHA256,
            "producer_checkpoint_commit": PRODUCER_CHECKPOINT_HEAD,
            "producer_checkpoint_tree": PRODUCER_CHECKPOINT_TREE,
            "producer_generator": GENERATOR_PATH.relative_to(AUDIT_DIR).as_posix(),
            "producer_generator_sha256": GENERATOR_SHA256,
            "producer_generator_blob_id": GENERATOR_BLOB_ID,
            "producer": PRODUCER_PATH.relative_to(AUDIT_DIR).as_posix(),
            "producer_sha256": PRODUCER_SHA256,
            "producer_blob_id": PRODUCER_BLOB_ID,
            "cohort_sha256": COHORT_SHA256,
            "cohort_review_sha256": COHORT_REVIEW_SHA256,
            "materializer": Path(__file__).relative_to(AUDIT_DIR).as_posix(),
            "materializer_sha256": sha256_file(Path(__file__)),
            "inputs": overlay["pins"]["inputs"],
        },
        "review_methods": [
            "A fresh count reviewer rebuilt RUN-122 byte-for-byte, verified all input and Git pins, independently reconstructed the 641-plus-7 source membership, 76-plus-7 bridge membership, and every route, page, feature, queue, and residual equation.",
            "A fresh semantic-preservation reviewer verified that only candidates 02 through 08 created owner rows and bridges, while all fifteen shared, alias, and evidence-gap outcomes retained their candidate, decision, source, dependency, rationale, and discrepancy evidence.",
            "A fresh downstream reviewer verified exact deterministic bytes, reporting projections, lineage, mutation and zero-credit boundaries, then rechecked repository hygiene after the transient interpreter cache disappeared.",
            "All reviewers treated Oblivion Findings as one operating organisation across multiple Sites and inferred no Site, permission, privacy, direct-object, ledger, lifecycle, concurrency, runtime, test, benchmark, finding, or completion credit.",
        ],
        "reviewers": [
            {
                "review_id": "RUN122R-COUNTS-MECHANICAL",
                "reviewer_task_path": "/root/run119_counts_review",
                "verdict": "GO_ZERO_MECHANICAL_DISCREPANCIES",
                "discrepancies": 0,
                "verified_scope": [
                    "byte-identical producer rebuild and all input, blob, Git-tree, and self pins",
                    "641 plus 7 source records and 76 plus 7 bridge rows without collisions",
                    "all route, page, feature, queue, residual, and outcome equations",
                    "existing page evidence-gap tag and all zero-credit boundaries",
                ],
                "audit_artifact_writes": False,
            },
            {
                "review_id": "RUN122R-SEMANTIC-PRESERVATION",
                "reviewer_task_path": "/root/run119_reporting_review",
                "verdict": "GO_ZERO_SEMANTIC_OR_PRESERVATION_DISCREPANCIES",
                "discrepancies": 0,
                "verified_scope": [
                    "exact seven-owner and seven-bridge candidate membership",
                    "all fifteen non-owner outcome bindings and row hashes",
                    "identity reconciliation and six-callsite page boundary",
                    "no correctness, runtime, benchmark, pass, or completion credit",
                ],
                "audit_artifact_writes": False,
            },
            {
                "review_id": "RUN122R-DOWNSTREAM-HYGIENE",
                "reviewer_task_path": "/root/run120_static_dashboard",
                "verdict": "GO_AFTER_TRANSIENT_CACHE_CLEARED",
                "discrepancies": 0,
                "verified_scope": [
                    "deterministic generator and artifact hashes",
                    "all published identities and reporting projections",
                    "application, matrix, and mutation boundaries",
                    "clean repository with only intended files before commit",
                ],
                "audit_artifact_writes": False,
            },
        ],
        "verified_combined_counts": EXPECTED_COUNTS,
        "verified_queue_accounting": EXPECTED_QUEUE,
        "verified_conservation": overlay["outcome_conservation"],
        "verified_identity_reconciliation": overlay["identity_reconciliation"],
        "verified_identity": identity,
        "verified_producer_credit_boundary": overlay["credit_boundary"],
        "credit_boundary": {
            "INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING": True,
            "new_source_ownership": False,
            "new_route_ownership": False,
            "new_page_ownership": False,
            "new_controller_action_bridge": False,
            "new_reviewed_outcome_classification": False,
            "direct_exact_queue_review": False,
            "complete_route_page_feature_crosswalk": False,
            "matrix_mutation": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "privacy_correctness": False,
            "direct_object_correctness": False,
            "ledger_correctness": False,
            "lifecycle_correctness": False,
            "concurrency_correctness": False,
            "runtime": False,
            "database": False,
            "build": False,
            "application_browser": False,
            "executed_tests": False,
            "benchmark": False,
            "ease": False,
            "pass": False,
            "final_finding": False,
            "completion": False,
            "audit_complete": False,
        },
        "mutation_attestation": {
            "reviewers_edited_audit_artifacts": False,
            "persistent_reviewer_workspace_mutation": False,
            "receipt_materialized_by_orchestrator_from_reviewer_returns": True,
            "application_source_changed": False,
            "matrix_changed": False,
            "runtime_or_external_system_changed": False,
        },
        "attestation": "Three fresh read-only reviews reproduce the exact RUN-122 overlay with zero remaining implementation, mechanical, semantic-preservation, hygiene, or arithmetic discrepancies. Exactly seven route owners and seven action bridges are authorized; seven shared relations, one alias, and seven evidence gaps remain reviewed non-owners; six page callsites add zero page credit. Gate 4 and the comprehensive audit remain open.",
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-review-wave-18.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/raw-run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json",
        ],
    }


def main() -> None:
    payload = build()
    encoded = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    output_sha256 = sha256_bytes(encoded)
    OUTPUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    if OUTPUT_PATH.exists():
        assert OUTPUT_PATH.read_bytes() == encoded, f"Refusing to overwrite different bytes: {OUTPUT_PATH}"
    else:
        temporary = OUTPUT_PATH.with_suffix(OUTPUT_PATH.suffix + ".tmp")
        temporary.write_bytes(encoded)
        assert sha256_file(temporary) == output_sha256
        os.replace(temporary, OUTPUT_PATH)
    assert sha256_file(OUTPUT_PATH) == output_sha256
    print(json.dumps({
        "status": payload["status"],
        "output": OUTPUT_PATH.relative_to(REPO).as_posix(),
        "sha256": output_sha256,
        "owners": payload["decision"]["route_owner_records_authorized"],
        "shared": payload["decision"]["reviewed_shared_records_authorized"],
        "aliases": payload["decision"]["reviewed_alias_records_authorized"],
        "gaps": payload["decision"]["reviewed_evidence_gap_records_authorized"],
        "reporting_authorized": payload["decision"]["reporting_materialization_authorized"],
        "gate_4_complete": payload["decision"]["gate_4_complete"],
    }, indent=2))


if __name__ == "__main__":
    main()
