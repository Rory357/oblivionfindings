#!/usr/bin/env python3
"""Integrate only independently reviewed RUN-113 route-action owners.

Twenty-three OWNER_ROUTE_ACTION decisions add one bounded route-source owner
and one controller-action bridge each. The reviewed create-route redirect is
preserved as a non-owner alias. Seven page callsites remain context only: three
were already owned and four still require separate page review. No downstream
credit is created.
"""

from __future__ import annotations

import hashlib
import json
import os
import subprocess
from collections import Counter
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_PATH = AUDIT_DIR / "evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json"

AUDIT_HEAD = "11691941ebe683a58be84380336e435fe77d31de"
AUDIT_TREE = "8db4068c70c1e4003b68142d0b9f635211cdd631"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
COHORT_GENERATOR_SHA256 = "9403a58b2949123daaf1b23fb1db7ea5060c81e595f725dbda2701fff680083f"
REVIEW_MATERIALIZER_SHA256 = "eacc817d792aee56692012851d9860b2718cb75536203dc9258b838323361238"

INPUT_PATHS = {
    "matrix": AUDIT_DIR / "03-feature-to-benchmark-matrix.csv",
    "baseline": AUDIT_DIR / "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json",
    "wave11_cohort": AUDIT_DIR / "evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json",
    "wave11_overlay": AUDIT_DIR / "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json",
    "wave11_review": AUDIT_DIR / "evidence/source/raw-run-092r-independent-reviewed-static-source-ownership-overlay-wave-11.json",
    "wave12_overlay": AUDIT_DIR / "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json",
    "wave12_review": AUDIT_DIR / "evidence/source/raw-run-098r-independent-reviewed-route-controller-only-ownership-overlay-wave-12.json",
    "wave13_overlay": AUDIT_DIR / "evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json",
    "wave13_review": AUDIT_DIR / "evidence/source/raw-run-102r-independent-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json",
    "wave14_overlay": AUDIT_DIR / "evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json",
    "wave14_review": AUDIT_DIR / "evidence/source/raw-run-106r-independent-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json",
    "wave15_overlay": AUDIT_DIR / "evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json",
    "wave15_review": AUDIT_DIR / "evidence/source/raw-run-110r-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json",
    "queue": AUDIT_DIR / "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json",
    "cohort": AUDIT_DIR / "evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json",
    "review": AUDIT_DIR / "evidence/source/raw-run-113r-independent-outcome-neutral-name-only-route-action-review-wave-16.json",
}

EXPECTED_INPUT_SHA256 = {
    "matrix": "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390",
    "baseline": "bfec52a9bed7176045457e8920bdc7b65e1c1d5c5eeb28a266f49c12acf69bcf",
    "wave11_cohort": "7b1fea57104e9f4bfea7ee450ef535ac57e7fa69bc978212b4eafb0fe1ed0cae",
    "wave11_overlay": "5390e55d9e47d1845afa8b3848bdab7687747cbb62c946dd4d66d3c435e8de0b",
    "wave11_review": "1111d30aa24935116c37f27bead824ca1bcca7444157e456d959e821af00669a",
    "wave12_overlay": "7f76c9cfeae906a64c92013fbf9a12cf180d39a315bfa13e778b6332365cdd2a",
    "wave12_review": "b7ef9888eca1f8ab47653b19be44d9de385f2132148dfed38b5d8d5018b1903b",
    "wave13_overlay": "cf68900351832c790ec53b4996daaf640c604c498f902c85dec681113bf492dd",
    "wave13_review": "f88c3ce6ae7b82ca316c656787547bdd9e6a4cd40469b16d44a6e84f99d14902",
    "wave14_overlay": "9c1600d2365c3527e185f313b7cb1586705f39cefb15d5d212dc708b45b630ea",
    "wave14_review": "4a3252a37d03a609cdf69a4f0a56b41e120d3ba2314dede88317de9c50bfd9e4",
    "wave15_overlay": "9375fb374b589c5068334795fa03d80a7ba1fd35695808f99c9e2591e886efca",
    "wave15_review": "e9b076e790e5346f99665f8f99ee609b4c7b7bac4767e416abc73a57f7dfd867",
    "queue": "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    "cohort": "0849ca5c10e31a4f3e5133a521a570d60b004e723fb17a7f2e812e485f345461",
    "review": "b52872c02b2a1b41861d9eb735eb363fd06cd1af645e1e6c0965b1b042333a83",
}

EXPECTED_IDENTITY = {
    "owner_candidate_id_list_sha256": "1676edabc83874068cbbf5e63f242ff2b0f702786282eae4d0d8ba05c4aa3780",
    "owner_route_record_id_list_sha256": "905cdad34ee4531053c2b3e6adc0e31b6b20e98e832858756c01a3fd4664bcf4",
    "owner_route_source_key_list_sha256": "4939aa6bf225ad8680d4f1cf0a1878526dfb6e7675f66cd8067b9c28d2929fd0",
    "owner_source_record_key_list_sha256": "625e7413390fb79e41cb6addd3dc2e2a2f02c45d9d83750543b2503cace748a7",
    "owner_feature_id_list_sha256": "4abb110025b65d51eae2fa1162ad677a2e5b06320000f7815a76c2a09553b2e6",
    "owner_action_key_list_sha256": "513d5e4873caa8523c68ba1bd1bcd860c5ec961655f2d99cde9a3b679abb668c",
    "owner_bridge_key_list_sha256": "7453ecf2b61c593cd9e159f00cc153e6b17d8a736cf4f0d4f33ce9e670cb04bb",
    "owner_candidate_record_sha256_list_sha256": "fbd5d26943e167bf6525e94be4a691d7bd396d340b9b52844c6e8430385c9b9c",
    "owner_decision_record_sha256_list_sha256": "17931ade5ebfa96d0b47acfd29ee87d98377a2b3ac03e3f2f71d2661cd99a320",
    "owner_queue_id_list_sha256": "bce40fd9f3f77e4b2284d5b5c7e3b89d21a0291e29f4c66692f215ae37492eb8",
    "owner_queue_key_list_sha256": "694b894d42b43a9197df789e0897a7fa171d38cdfd7baa1fefa7929b702d58f3",
    "alias_candidate_id_list_sha256": "de56bb26f294b35be2b7e78b9bd873745e0e2a53be3413f2d0f218aec03910c0",
    "alias_route_record_id_list_sha256": "50b23e2ce181f90d42387cf20807a27cef534a0479886ad149d7b682a17d7d4e",
    "alias_candidate_record_sha256_list_sha256": "6c0511051135c85c413d722883b23d5081ac7973c4c45c36c49ae093093d0746",
    "alias_decision_record_sha256_list_sha256": "a8e36e0f542a8405749145495db1bd4f96a4c4678abd2487e50adc1b134812c4",
    "alias_queue_id_list_sha256": "aef4c8ea3911eecdb0bb56c0ecfc58b209dc1405e19530dfff4a96c17290e050",
    "alias_queue_key_list_sha256": "33bf203d6c78623044d6cca65b2f3a4ab181be86ab5aa88fb4b27065480146ee",
    "new_union_feature_id_list_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    "new_page_feature_id_list_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    "new_route_feature_id_list_sha256": "4abb110025b65d51eae2fa1162ad677a2e5b06320000f7815a76c2a09553b2e6",
    "combined_source_record_key_list_sha256": "f66e087c555ebed998698e9e7b795569b3bc50afcd0adf37864ee691c2cfbfaf",
    "combined_source_record_id_list_sha256": "e4129ae94ee34fe7bb9f2d4e5bdaad4c46f9e0869668afb38d665765664a4d7c",
    "combined_feature_id_list_sha256": "874e55340e6abb600c50a64a147b81b9b5467587ee77f9e45eace4eb85ac55ca",
    "combined_route_feature_id_list_sha256": "6c0ffc59f73a61e7b77bec89ae124c518e17230783bfae9f16398eec5f18e5dd",
    "combined_page_feature_id_list_sha256": "2fa6a11a5c66a016366db84ce89931db906b26cc911dfa88490cb6561ff261e8",
    "combined_route_page_overlap_feature_id_list_sha256": "caab1e6ecfbe02867b431cc3c788b649326aabacff7d04effa861ca4b0f86859",
    "combined_bridge_key_list_sha256": "36f36764510d9a809d074588a916e90c3f5d6f51b0d255f2790376b190db3098",
    "new_reviewed_queue_key_list_sha256": "82c669035537d18a36460e991ade5bc46a035d8bd10057b8b95d59b58c5527be",
    "prior_reviewed_queue_key_list_sha256": "90bd21301e6ed473654cd8c966456fded20f238de2f004bdc74dd456bec6aa0e",
    "combined_reviewed_queue_key_list_sha256": "096273b239b8625bc4cffa0537f956773f99039e2b3e7672662a5213b44264e2",
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
    completed = subprocess.run(
        ["git", *args], cwd=REPO, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True
    )
    return completed.stdout.strip()


def canonical_queue_key(surface: str, source_record_id: str) -> str:
    if surface == "ROUTE_SOURCE_RECORD":
        return f"route|{source_record_id}"
    assert surface == "PAGE_ROOT_SOURCE_RECORD", surface
    return f"page|{source_record_id}"


def assert_workspace_and_inputs(cohort: dict[str, Any], review: dict[str, Any]) -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == AUDIT_HEAD
    assert git("rev-parse", "HEAD^{tree}") == AUDIT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("status", "--porcelain", "--", "app", "routes", "resources/js") == ""
    for name, path in INPUT_PATHS.items():
        assert path.is_file(), path
        actual = sha256_file(path)
        assert actual == EXPECTED_INPUT_SHA256[name], (name, actual, EXPECTED_INPUT_SHA256[name])
    assert sha256_file(AUDIT_DIR / cohort["pins"]["generator"]) == COHORT_GENERATOR_SHA256
    assert sha256_file(AUDIT_DIR / review["pins"]["materializer"]) == REVIEW_MATERIALIZER_SHA256


def build_overlay_source_records(
    candidates: list[dict[str, Any]], decisions: dict[str, dict[str, Any]]
) -> list[dict[str, Any]]:
    records: list[dict[str, Any]] = []
    for candidate in candidates:
        candidate_id = candidate["candidate_id"]
        decision = decisions[candidate_id]
        assert decision["outcome"] == "OWNER_ROUTE_ACTION"
        assert decision["route_ownership_authorized"] is True
        source = candidate["route_source"]
        feature = candidate["feature_identity_projection"]
        suffix = candidate_id.rsplit("-", 1)[-1]
        row = {
            "overlay_mapping_id": f"RUN114-ROUTE-{suffix}",
            "candidate_id": candidate_id,
            "candidate_record_sha256": candidate["candidate_record_sha256"],
            "decision_record_sha256": decision["decision_record_sha256"],
            "surface": "ROUTE_SOURCE_RECORD",
            "source_record_id": source["route_record_id"],
            "source_record_key": f"route|{source['route_record_id']}|{candidate['candidate_feature_id']}",
            "feature_id": candidate["candidate_feature_id"],
            "feature_class": feature["feature_class"],
            "module": feature["module"],
            "user_job": feature["user_job"],
            "source": source,
            "review_outcome": "OWNER_ROUTE_ACTION",
            "review_rationale": decision["rationale"],
            "static_source_feature_ownership_credit": True,
            "credit_boundary": {
                "page_ownership": False,
                "frontend_caller_ownership": False,
                "framework_route_reachability": False,
                "navigation": False,
                "site_authorization_correctness": False,
                "permission_correctness": False,
                "privacy_correctness": False,
                "direct_object_correctness": False,
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
        }
        row["overlay_row_sha256"] = canonical_json_sha256(row)
        records.append(row)
    return sorted(records, key=lambda row: row["source_record_id"])


def build_action_bridges(
    candidates: list[dict[str, Any]], decisions: dict[str, dict[str, Any]]
) -> list[dict[str, Any]]:
    bridges: list[dict[str, Any]] = []
    for candidate in candidates:
        decision = decisions[candidate["candidate_id"]]
        assert decision["controller_action_bridge_authorized"] is True
        action = candidate["controller_action"]
        primary = action["primary_method_slice"]
        suffix = candidate["candidate_id"].rsplit("-", 1)[-1]
        bridge = {
            "bridge_id": f"RUN114-BRIDGE-{suffix}",
            "candidate_id": candidate["candidate_id"],
            "candidate_record_sha256": candidate["candidate_record_sha256"],
            "feature_id": candidate["candidate_feature_id"],
            "route_record_id": candidate["route_source"]["route_record_id"],
            "controller_fqcn": action["controller_fqcn"],
            "controller_file": primary["source_file"],
            "controller_file_sha256": primary["source_file_sha256"],
            "controller_file_blob_id": primary["source_file_blob_id"],
            "method": primary["method"],
            "definition_anchor": primary["definition_anchor"],
            "method_review_slice_sha256": primary["review_slice"]["text_sha256"],
            "review_outcome": "OWNER_ROUTE_ACTION",
            "static_controller_action_bridge_credit": True,
            "page_ownership_credit": False,
            "runtime_credit": False,
            "application_browser_credit": False,
            "executed_test_credit": False,
            "completion_credit": False,
        }
        bridge["bridge_row_sha256"] = canonical_json_sha256(bridge)
        bridges.append(bridge)
    return sorted(bridges, key=lambda row: row["bridge_id"])


def build_non_owner_outcomes(
    candidates: list[dict[str, Any]], decisions: dict[str, dict[str, Any]]
) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    for candidate in candidates:
        decision = decisions[candidate["candidate_id"]]
        assert decision["outcome"] == "ALIAS_OR_REDIRECT"
        suffix = candidate["candidate_id"].rsplit("-", 1)[-1]
        row = {
            "reviewed_non_owner_id": f"RUN114-NONOWNER-{suffix}",
            "candidate_id": candidate["candidate_id"],
            "candidate_record_sha256": candidate["candidate_record_sha256"],
            "decision_record_sha256": decision["decision_record_sha256"],
            "outcome": decision["outcome"],
            "route_record_id": candidate["route_source"]["route_record_id"],
            "route_source_key": candidate["route_source"]["source_key"],
            "feature_id": candidate["candidate_feature_id"],
            "rationale": decision["rationale"],
            "route_ownership_credit": False,
            "controller_action_bridge_credit": False,
            "page_ownership_credit": False,
            "downstream_credit": False,
            "completion_credit": False,
        }
        row["reviewed_non_owner_row_sha256"] = canonical_json_sha256(row)
        rows.append(row)
    return sorted(rows, key=lambda row: row["reviewed_non_owner_id"])


def build() -> dict[str, Any]:
    data = {name: load_json(path) for name, path in INPUT_PATHS.items() if name != "matrix"}
    baseline = data["baseline"]
    wave11_cohort = data["wave11_cohort"]
    wave11 = data["wave11_overlay"]
    wave12 = data["wave12_overlay"]
    wave13 = data["wave13_overlay"]
    wave14 = data["wave14_overlay"]
    wave15 = data["wave15_overlay"]
    queue = data["queue"]
    cohort = data["cohort"]
    review = data["review"]
    assert_workspace_and_inputs(cohort, review)

    for review_name in ("wave11_review", "wave12_review", "wave13_review", "wave14_review", "wave15_review"):
        prior_review = data[review_name]
        assert prior_review["decision"]["verdict"].startswith("GO")
        assert prior_review["decision"].get("mechanical_discrepancies", 0) == 0
    assert baseline["record_set"]["count"] == len(baseline["records"]) == 530
    assert wave11["combined_counts"]["source_owner_records"] == 548
    assert wave12["combined_counts"]["source_owner_records"] == 571
    assert wave13["combined_counts"]["source_owner_records"] == 592
    assert wave14["combined_counts"]["source_owner_records"] == 612
    assert wave15["combined_counts"] == {
        **wave15["combined_counts"],
        "source_owner_records": 614,
        "route_owner_records": 265,
        "page_owner_records": 349,
        "distinct_feature_ids": 256,
        "distinct_H_feature_ids": 234,
        "distinct_D_feature_ids": 22,
        "route_distinct_feature_ids": 59,
        "page_distinct_feature_ids": 242,
        "route_page_feature_overlap": 45,
        "static_controller_action_bridges": 53,
    }
    assert data["wave15_review"]["decision"]["reporting_authorized"] is True

    decision_summary = review["decision"]
    assert decision_summary["verdict"] == "GO_23_EXPLICIT_OWNER_ROUTE_ACTION_1_EXPLICIT_ALIAS_OR_REDIRECT"
    assert decision_summary["mechanical_discrepancies"] == 0
    assert decision_summary["reviewed_route_actions"] == 24
    assert decision_summary["owner_route_actions"] == 23
    assert decision_summary["alias_or_redirect"] == 1
    assert decision_summary["static_route_owner_records_authorized"] == 23
    assert decision_summary["static_controller_action_bridges_authorized"] == 23
    assert decision_summary["static_page_owner_records_authorized"] == 0
    assert decision_summary["owner_only_overlay_authorized"] is True
    assert decision_summary["matrix_mutation_authorized"] is False
    assert decision_summary["downstream_credit_authorized"] is False
    assert decision_summary["gate_4_complete"] is False

    candidates = sorted(cohort["records"], key=lambda row: row["candidate_id"])
    decisions = {row["candidate_id"]: row for row in review["action_decisions"]}
    assert len(candidates) == len(decisions) == 24
    assert {row["candidate_id"] for row in candidates} == set(decisions)
    queue_by_id = {row["queue_id"]: row for row in queue["records"]}
    queue_by_key = {row["canonical_key"]: row for row in queue["records"]}
    assert len(queue_by_id) == len(queue_by_key) == 507
    for queue_row in queue["records"]:
        without_digest = {key: value for key, value in queue_row.items() if key != "queue_record_sha256"}
        assert queue_row["queue_record_sha256"] == canonical_json_sha256(without_digest)

    for candidate in candidates:
        candidate_without_digest = {key: value for key, value in candidate.items() if key != "candidate_record_sha256"}
        assert candidate["candidate_record_sha256"] == canonical_json_sha256(candidate_without_digest)
        decision = decisions[candidate["candidate_id"]]
        decision_without_digest = {key: value for key, value in decision.items() if key != "decision_record_sha256"}
        assert decision["decision_record_sha256"] == canonical_json_sha256(decision_without_digest)
        assert decision["candidate_record_sha256"] == candidate["candidate_record_sha256"]
        assert decision["partition_id"] == candidate["review_partition"]
        assert decision["queue_id"] == candidate["queue_id"]
        assert decision["route_record_id"] == candidate["route_source"]["route_record_id"]
        assert decision["candidate_feature_id"] == candidate["candidate_feature_id"]
        assert decision["outcome"] in cohort["fresh_review_contract"]["allowed_outcomes"]
        assert candidate["name_only_identity"]["relation_comparison"] == "NAME_ONLY"
        assert candidate["name_only_identity"]["backend_candidate_count"] == 0
        assert candidate["name_only_identity"]["backend_candidate_absence_is_not_negative_proof"] is True
        assert candidate["fresh_review_state"]["status"] == "PENDING"
        assert all(value is False for value in candidate["collision_checks"].values())
        queue_row = queue_by_id[candidate["queue_id"]]
        assert queue_row["canonical_key"] == candidate["queue_canonical_key"]
        assert queue_row["surface"] == "ROUTE_SOURCE_RECORD"
        assert queue_row["source_record_id"] == candidate["route_source"]["route_record_id"]
        assert queue_row["candidate_feature_id"] == candidate["candidate_feature_id"]
        assert queue_row["queue_record_sha256"] == candidate["evidence_digests"]["queue_record_sha256"]
        assert decision["page_ownership_authorized"] is False
        assert decision["site_permission_privacy_direct_object_lifecycle_correctness_authorized"] is False

    owner_candidates = [row for row in candidates if decisions[row["candidate_id"]]["outcome"] == "OWNER_ROUTE_ACTION"]
    non_owner_candidates = [row for row in candidates if decisions[row["candidate_id"]]["outcome"] != "OWNER_ROUTE_ACTION"]
    assert len(owner_candidates) == 23
    assert [row["candidate_id"] for row in non_owner_candidates] == ["RUN113-NAME-ONLY-ROUTE-ACTION-03"]
    assert all(decisions[row["candidate_id"]]["route_ownership_authorized"] is True for row in owner_candidates)
    assert all(decisions[row["candidate_id"]]["controller_action_bridge_authorized"] is True for row in owner_candidates)
    alias_decision = decisions[non_owner_candidates[0]["candidate_id"]]
    assert alias_decision["outcome"] == "ALIAS_OR_REDIRECT"
    assert alias_decision["route_ownership_authorized"] is False
    assert alias_decision["controller_action_bridge_authorized"] is False

    page_callsites = [
        callsite
        for candidate in candidates
        for callsite in candidate["controller_action"]["literal_inertia_page_callsites"]
    ]
    assert len(page_callsites) == 7
    assert Counter(row["current_page_status"] for row in page_callsites) == {
        "CURRENT_STATIC_SOURCE_OWNER": 3,
        "UNOWNED_CONTEXT_REQUIRES_SEPARATE_PAGE_REVIEW": 4,
    }
    assert sum(row["current_static_source_owner"] is True for row in page_callsites) == 3
    assert all(row["page_ownership_credit_from_this_cohort"] is False for row in page_callsites)
    assert review["page_context_boundary"] == {
        "literal_callsites": 7,
        "currently_owned_page_callsites": 3,
        "current_page_evidence_gap_callsites": 4,
        "page_ownership_authorized": 0,
        "rule": "Owned pages remain observation only; four Respite page gaps remain gaps and cannot inherit route ownership.",
    }

    overlay_records = build_overlay_source_records(owner_candidates, decisions)
    action_bridges = build_action_bridges(owner_candidates, decisions)
    non_owner_outcomes = build_non_owner_outcomes(non_owner_candidates, decisions)
    assert len(overlay_records) == len(action_bridges) == 23
    assert len(non_owner_outcomes) == 1
    assert all(row["candidate_id"] != "RUN113-NAME-ONLY-ROUTE-ACTION-03" for row in overlay_records + action_bridges)
    for row in overlay_records:
        without_digest = {key: value for key, value in row.items() if key != "overlay_row_sha256"}
        assert row["overlay_row_sha256"] == canonical_json_sha256(without_digest)
    for row in action_bridges:
        without_digest = {key: value for key, value in row.items() if key != "bridge_row_sha256"}
        assert row["bridge_row_sha256"] == canonical_json_sha256(without_digest)
    for row in non_owner_outcomes:
        without_digest = {key: value for key, value in row.items() if key != "reviewed_non_owner_row_sha256"}
        assert row["reviewed_non_owner_row_sha256"] == canonical_json_sha256(without_digest)

    prior_records = (
        baseline["records"]
        + wave11["overlay_source_records"]
        + wave12["overlay_source_records"]
        + wave13["overlay_source_records"]
        + wave14["overlay_source_records"]
        + wave15["overlay_source_records"]
    )
    prior_keys = {row["source_record_key"] for row in prior_records}
    prior_route_ids = {row["source_record_id"] for row in prior_records if row["surface"] == "ROUTE_SOURCE_RECORD"}
    new_keys = {row["source_record_key"] for row in overlay_records}
    new_route_ids = {row["source_record_id"] for row in overlay_records}
    alias_route_ids = {row["route_record_id"] for row in non_owner_outcomes}
    assert len(prior_records) == len(prior_keys) == 614
    assert len(prior_route_ids) == 265
    assert len(new_keys) == len(new_route_ids) == 23
    assert not (prior_keys & new_keys)
    assert not (prior_route_ids & new_route_ids)
    assert not (alias_route_ids & prior_route_ids)
    assert not (alias_route_ids & new_route_ids)
    combined_records = prior_records + overlay_records
    combined_keys = {row["source_record_key"] for row in combined_records}
    combined_ids = {row["source_record_id"] for row in combined_records}
    assert len(combined_records) == len(combined_keys) == len(combined_ids) == 637

    prior_bridges = (
        wave11["static_controller_action_bridges"]
        + wave12["new_static_controller_action_bridges"]
        + wave13["new_static_controller_action_bridges"]
    )
    prior_bridge_keys = {
        (row["controller_file"], row["method"], row["feature_id"])
        for row in prior_bridges
    }
    new_bridge_keys = {
        (row["controller_file"], row["method"], row["feature_id"])
        for row in action_bridges
    }
    assert len(prior_bridges) == len(prior_bridge_keys) == 53
    assert len(action_bridges) == len(new_bridge_keys) == 23
    assert not (prior_bridge_keys & new_bridge_keys)
    combined_bridge_keys = prior_bridge_keys | new_bridge_keys
    assert len(combined_bridge_keys) == 76

    combined_feature_ids = {row["feature_id"] for row in combined_records}
    route_feature_ids = {row["feature_id"] for row in combined_records if row["surface"] == "ROUTE_SOURCE_RECORD"}
    page_feature_ids = {row["feature_id"] for row in combined_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"}
    overlap_feature_ids = route_feature_ids & page_feature_ids
    feature_class_by_id: dict[str, str] = {}
    for row in combined_records:
        feature_class_by_id.setdefault(row["feature_id"], row["feature_class"])
        assert feature_class_by_id[row["feature_id"]] == row["feature_class"]
    class_counts = Counter(feature_class_by_id.values())
    assert len(combined_feature_ids) == 256
    assert class_counts == {"H": 234, "D": 22}
    assert len(route_feature_ids) == 61
    assert len(page_feature_ids) == 242
    assert len(overlap_feature_ids) == 47

    accepted_feature_ids = {row["candidate_feature_id"] for row in owner_candidates}
    prior_feature_ids = {row["feature_id"] for row in prior_records}
    prior_route_feature_ids = {row["feature_id"] for row in prior_records if row["surface"] == "ROUTE_SOURCE_RECORD"}
    prior_page_feature_ids = {row["feature_id"] for row in prior_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"}
    new_feature_ids = accepted_feature_ids - prior_feature_ids
    new_route_feature_ids = accepted_feature_ids - prior_route_feature_ids
    new_page_feature_ids = accepted_feature_ids - prior_page_feature_ids
    assert accepted_feature_ids == {"CAP-FLEET-INCIDENT-RECORD", "CAP-RESP-HANDOVER-NOTES"}
    assert new_feature_ids == set()
    assert new_route_feature_ids == accepted_feature_ids
    assert new_page_feature_ids == set()

    wave11_queue_keys: set[str] = set()
    for chain in wave11_cohort["records"]:
        for surface, source, id_field in (
            ("ROUTE_SOURCE_RECORD", chain["route_source"], "route_record_id"),
            ("PAGE_ROOT_SOURCE_RECORD", chain["page_source"], "page_record_id"),
        ):
            key = canonical_queue_key(surface, source[id_field])
            if key in queue_by_key:
                wave11_queue_keys.add(key)
    wave12_queue_keys = {
        canonical_queue_key(row["surface"], row["source_record_id"])
        for row in wave12["overlay_source_records"]
    }
    wave13_queue_keys = {
        canonical_queue_key(row["surface"], row["source_record_id"])
        for row in wave13["overlay_source_records"]
    } | {f"route|{row['route_record_id']}" for row in wave13["reviewed_non_owner_outcomes"]}
    wave15_queue_keys = {row["queue_canonical_key"] for row in wave15["new_reviewed_queue_outcomes"]}
    assert len(wave11_queue_keys) == 12
    assert len(wave12_queue_keys) == 23
    assert len(wave13_queue_keys) == 24
    assert len(wave15_queue_keys) == 1
    prior_reviewed_queue_keys = wave11_queue_keys | wave12_queue_keys | wave13_queue_keys | wave15_queue_keys
    new_reviewed_queue_keys = {row["queue_canonical_key"] for row in candidates}
    assert len(prior_reviewed_queue_keys) == 60
    assert len(new_reviewed_queue_keys) == 24
    assert prior_reviewed_queue_keys <= set(queue_by_key)
    assert new_reviewed_queue_keys <= set(queue_by_key)
    assert not (prior_reviewed_queue_keys & new_reviewed_queue_keys)
    combined_reviewed_queue_keys = prior_reviewed_queue_keys | new_reviewed_queue_keys
    assert len(combined_reviewed_queue_keys) == 84

    computed_identity = {
        "owner_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in owner_candidates]),
        "owner_route_record_id_list_sha256": canonical_list_sha256(new_route_ids),
        "owner_route_source_key_list_sha256": canonical_list_sha256([row["route_source"]["source_key"] for row in owner_candidates]),
        "owner_source_record_key_list_sha256": canonical_list_sha256(new_keys),
        "owner_feature_id_list_sha256": canonical_list_sha256(accepted_feature_ids),
        "owner_action_key_list_sha256": canonical_list_sha256([row["action_key"] for row in owner_candidates]),
        "owner_bridge_key_list_sha256": canonical_list_sha256([
            f"{row['controller_action']['primary_method_slice']['source_file']}|{row['controller_action']['primary_method_slice']['method']}|{row['candidate_feature_id']}"
            for row in owner_candidates
        ]),
        "owner_candidate_record_sha256_list_sha256": canonical_list_sha256([row["candidate_record_sha256"] for row in owner_candidates]),
        "owner_decision_record_sha256_list_sha256": canonical_list_sha256([decisions[row["candidate_id"]]["decision_record_sha256"] for row in owner_candidates]),
        "owner_queue_id_list_sha256": canonical_list_sha256([row["queue_id"] for row in owner_candidates]),
        "owner_queue_key_list_sha256": canonical_list_sha256([row["queue_canonical_key"] for row in owner_candidates]),
        "alias_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in non_owner_candidates]),
        "alias_route_record_id_list_sha256": canonical_list_sha256(alias_route_ids),
        "alias_candidate_record_sha256_list_sha256": canonical_list_sha256([row["candidate_record_sha256"] for row in non_owner_candidates]),
        "alias_decision_record_sha256_list_sha256": canonical_list_sha256([decisions[row["candidate_id"]]["decision_record_sha256"] for row in non_owner_candidates]),
        "alias_queue_id_list_sha256": canonical_list_sha256([row["queue_id"] for row in non_owner_candidates]),
        "alias_queue_key_list_sha256": canonical_list_sha256([row["queue_canonical_key"] for row in non_owner_candidates]),
        "new_union_feature_id_list_sha256": canonical_list_sha256(new_feature_ids),
        "new_page_feature_id_list_sha256": canonical_list_sha256(new_page_feature_ids),
        "new_route_feature_id_list_sha256": canonical_list_sha256(new_route_feature_ids),
        "combined_source_record_key_list_sha256": canonical_list_sha256(combined_keys),
        "combined_source_record_id_list_sha256": canonical_list_sha256(combined_ids),
        "combined_feature_id_list_sha256": canonical_list_sha256(combined_feature_ids),
        "combined_route_feature_id_list_sha256": canonical_list_sha256(route_feature_ids),
        "combined_page_feature_id_list_sha256": canonical_list_sha256(page_feature_ids),
        "combined_route_page_overlap_feature_id_list_sha256": canonical_list_sha256(overlap_feature_ids),
        "combined_bridge_key_list_sha256": canonical_list_sha256({"|".join(key) for key in combined_bridge_keys}),
        "new_reviewed_queue_key_list_sha256": canonical_list_sha256(new_reviewed_queue_keys),
        "prior_reviewed_queue_key_list_sha256": canonical_list_sha256(prior_reviewed_queue_keys),
        "combined_reviewed_queue_key_list_sha256": canonical_list_sha256(combined_reviewed_queue_keys),
    }
    assert computed_identity == EXPECTED_IDENTITY

    ownership_percent = (Decimal(637) * Decimal(100) / Decimal(3929)).quantize(
        Decimal("0.000001"), rounding=ROUND_HALF_UP
    )
    assert str(ownership_percent) == "16.212777"
    assert review["reviewed_projection_if_integrated"] == {
        "O": 23,
        "S": 0,
        "A": 1,
        "D": 0,
        "E": 0,
        "source_owner_records": 637,
        "route_owner_records": 288,
        "page_owner_records": 349,
        "source_residual_records": 3292,
        "distinct_feature_ids": 256,
        "static_controller_action_bridges": 76,
        "bounded_ownership_percent": "16.212777",
        "queue_records": 507,
        "reviewed_queue_surfaces": 84,
        "owned_queue_surfaces": 77,
        "shared_queue_surfaces": 3,
        "alias_queue_surfaces": 4,
        "pending_unreviewed": 423,
        "without_ownership": 430,
    }

    identity = {
        **computed_identity,
        "new_overlay_source_records_sha256": canonical_json_sha256(overlay_records),
        "new_overlay_row_sha256_list_sha256": canonical_list_sha256([row["overlay_row_sha256"] for row in overlay_records]),
        "new_action_bridges_sha256": canonical_json_sha256(action_bridges),
        "new_action_bridge_row_sha256_list_sha256": canonical_list_sha256([row["bridge_row_sha256"] for row in action_bridges]),
        "reviewed_non_owner_outcomes_sha256": canonical_json_sha256(non_owner_outcomes),
        "reviewed_non_owner_row_sha256_list_sha256": canonical_list_sha256([row["reviewed_non_owner_row_sha256"] for row in non_owner_outcomes]),
        "reviewed_decision_record_sha256_list_sha256": review["verified_global_identity"]["decision_record_sha256_list_sha256"],
        "reviewed_decisions_sha256": review["verified_global_identity"]["reviewed_decisions_sha256"],
    }

    return {
        "schema_version": "1.0.0",
        "run_id": "RUN-114-REVIEWED-OUTCOME-NEUTRAL-NAME-ONLY-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-16",
        "status": "TWENTY_THREE_REVIEWED_ROUTE_ACTION_OWNERS_INTEGRATED_ONE_ALIAS_PRESERVED_NAME_ONLY_REVIEW_BOUNDED_STATIC_ONLY",
        "generated_on": "2026-08-26",
        "pins": {
            "checkpoint_commit": AUDIT_HEAD,
            "checkpoint_tree": AUDIT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "app_tree": APP_TREE,
            "routes_tree": ROUTES_TREE,
            "resources_js_tree": RESOURCES_JS_TREE,
            "resources_js_pages_tree": RESOURCES_JS_PAGES_TREE,
            "matrix_sha256": EXPECTED_INPUT_SHA256["matrix"],
            "generator": Path(__file__).relative_to(AUDIT_DIR).as_posix(),
            "generator_sha256": sha256_file(Path(__file__)),
            "cohort_generator": cohort["pins"]["generator"],
            "cohort_generator_sha256": COHORT_GENERATOR_SHA256,
            "review_materializer": review["pins"]["materializer"],
            "review_materializer_sha256": REVIEW_MATERIALIZER_SHA256,
            "inputs": {INPUT_PATHS[name].relative_to(AUDIT_DIR).as_posix(): digest for name, digest in EXPECTED_INPUT_SHA256.items()},
        },
        "architecture_rule": "Oblivion Findings is one operating organisation with multiple Sites. Bounded static route/action ownership does not establish Site access, roles or permissions, canonical ownership, direct-object concealment, privacy, lifecycle, concurrency, runtime, or release readiness.",
        "name_only_provenance": {
            "identity_relation": "NAME_ONLY",
            "name_only_alone_authorizes_ownership": False,
            "exact_method_resolution_alone_authorizes_ownership": False,
            "ownership_basis": "FRESH_EXACT_CONTROLLER_ACTION_SEMANTIC_REVIEW",
            "backend_candidate_absence_is_negative_proof": False,
        },
        "baseline": {
            "run_id": wave15["run_id"],
            "review_run_id": data["wave15_review"]["run_id"],
            "source_owner_records": 614,
            "route_owner_records": 265,
            "page_owner_records": 349,
            "distinct_feature_ids": 256,
            "static_controller_action_bridges": 53,
            "ledger_sha256": EXPECTED_INPUT_SHA256["wave15_overlay"],
            "review_sha256": EXPECTED_INPUT_SHA256["wave15_review"],
        },
        "reviewed_overlay": {
            "producer_run_id": cohort["run_id"],
            "review_run_id": review["run_id"],
            "reviewed_route_actions": 24,
            "owner_route_actions": 23,
            "shared_relations": 0,
            "alias_or_redirect": 1,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 0,
            "accepted_source_owner_records": 23,
            "accepted_route_owner_records": 23,
            "accepted_page_owner_records": 0,
            "accepted_controller_action_bridges": 23,
            "accepted_distinct_feature_ids": 2,
            "new_distinct_feature_ids": 0,
            "new_route_feature_ids": sorted(new_route_feature_ids),
            "new_page_feature_ids": [],
            "reviewed_non_owner_records_preserved": 1,
            "page_ownership_inherited": False,
            "cohort_sha256": EXPECTED_INPUT_SHA256["cohort"],
            "review_sha256": EXPECTED_INPUT_SHA256["review"],
        },
        "combined_counts": {
            "source_owner_records": 637,
            "route_owner_records": 288,
            "page_owner_records": 349,
            "distinct_feature_ids": 256,
            "distinct_H_feature_ids": 234,
            "distinct_D_feature_ids": 22,
            "route_distinct_feature_ids": 61,
            "page_distinct_feature_ids": 242,
            "route_page_feature_overlap": 47,
            "static_controller_action_bridges": 76,
            "bounded_static_source_denominator": 3929,
            "bounded_static_source_ownership_percent": "16.212777",
            "bounded_static_source_residual_records": 3292,
            "residual_explicit_unmapped_routes": 2921,
            "semantic_shared_routes": 5,
            "reviewed_alias_routes": 4,
            "reviewed_dead_routes": 0,
            "evidence_gap_routes_tagged_within_residual": 0,
            "residual_unadjudicated_page_roots": 353,
            "semantic_shared_page_roots": 9,
            "reviewed_alias_page_roots": 0,
            "reviewed_dead_page_roots": 0,
            "evidence_gap_page_roots_tagged_within_residual": 1,
        },
        "queue_accounting": {
            "direct_exact_queue_records": 507,
            "reviewed_queue_surface_rows": 84,
            "owner_queue_surface_rows": 77,
            "shared_queue_surface_rows": 3,
            "alias_queue_surface_rows": 4,
            "dead_queue_surface_rows": 0,
            "evidence_gap_queue_surface_rows": 0,
            "pending_unreviewed_queue_surface_rows": 423,
            "queue_surfaces_without_ownership": 430,
            "new_reviewed_route_surface_rows": 24,
            "new_owner_route_surface_rows": 23,
            "new_alias_route_surface_rows": 1,
            "wholesale_queue_ownership_authorized": False,
        },
        "page_context_boundary": review["page_context_boundary"],
        "overlay_source_records": overlay_records,
        "new_static_controller_action_bridges": action_bridges,
        "reviewed_non_owner_outcomes": non_owner_outcomes,
        "identity": identity,
        "outcome_conservation": {
            "reviewed_outcomes_equation": "24 = 23 owner + 0 shared + 1 alias + 0 dead + 0 evidence gap",
            "bounded_source_equation": "3929 = 637 owner + 3292 non-owner residual",
            "owner_surface_equation": "637 = 288 route + 349 page",
            "feature_union_equation": "256 = 61 route + 242 page - 47 overlap",
            "route_universe_equation": "3218 = 288 owner + 5 shared + 4 alias + 0 dead + 2921 residual",
            "page_universe_equation": "711 = 349 owner + 9 shared + 0 alias + 0 dead + 353 residual",
            "evidence_gap_is_tagged_within_353_page_residual": True,
            "queue_equation": "507 = 84 reviewed + 423 pending",
            "reviewed_queue_equation": "84 = 77 owner + 3 shared + 4 alias + 0 dead + 0 evidence gap",
            "queue_without_ownership_equation": "430 = 423 pending + 3 shared + 4 alias",
        },
        "denominator_boundary": {
            "run_077_bounded_static_records": 3929,
            "framework_expanded_route_page_denominator": None,
            "page_denominator_711_roots_vs_963_page_tree_files_resolved": False,
            "complete_route_page_feature_crosswalk": False,
            "gate_4_complete": False,
        },
        "credit_boundary": {
            "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_23_RECORDS": True,
            "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_23_ACTIONS": True,
            "REVIEWED_ALIAS_OR_REDIRECT_FOR_1_RECORD": True,
            "static_page_feature_ownership": False,
            "frontend_caller_ownership": False,
            "matrix_mutation": False,
            "wholesale_507_queue_ownership": False,
            "complete_route_page_feature_crosswalk": False,
            "framework_route_reachability": False,
            "navigation": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "privacy_correctness": False,
            "direct_object_correctness": False,
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
            "application_source_changed": False,
            "matrix_changed": False,
            "runtime_or_external_system_changed": False,
            "audit_artifacts_only": True,
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/integrate-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json",
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
        "source_owner_records": payload["combined_counts"]["source_owner_records"],
        "route_owner_records": payload["combined_counts"]["route_owner_records"],
        "page_owner_records": payload["combined_counts"]["page_owner_records"],
        "controller_action_bridges": payload["combined_counts"]["static_controller_action_bridges"],
        "reviewed_aliases": payload["combined_counts"]["reviewed_alias_routes"],
        "pending_queue_surfaces": payload["queue_accounting"]["pending_unreviewed_queue_surface_rows"],
        "gate_4_complete": payload["denominator_boundary"]["gate_4_complete"],
    }, indent=2))


if __name__ == "__main__":
    main()
