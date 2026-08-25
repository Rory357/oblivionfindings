#!/usr/bin/env python3
"""Integrate only independently reviewed RUN-121 Finance route owners.

Seven OWNER_ROUTE_ACTION decisions add bounded route-source ownership and one
controller-action bridge each. Seven shared relations, one redirect alias, and
seven evidence gaps remain reviewed non-owner outcomes. Six page callsites stay
context only; four unowned pages still require separate page review. No
correctness, runtime, benchmark, pass, or completion credit is created.
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
OUTPUT_PATH = AUDIT_DIR / "evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json"

AUDIT_HEAD = "141668c7734191ce9c9cc1b6506d97c958d5e43b"
AUDIT_TREE = "86a062d9a7d913f17af1c7b5397150c2c5757bb7"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
COHORT_GENERATOR_SHA256 = "c7795bee971e051873e3953eb4e1bb7c62eb372b6890149700d0c401d64305dd"
REVIEW_MATERIALIZER_SHA256 = "539b48b7aa2859a4b290d63c8d80e5fdcf685a5cb569e37b75499e31dd8d5187"

INPUT_PATHS = {
    "matrix": AUDIT_DIR / "03-feature-to-benchmark-matrix.csv",
    "baseline": AUDIT_DIR / "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json",
    "wave11_cohort": AUDIT_DIR / "evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json",
    "wave11": AUDIT_DIR / "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json",
    "wave12": AUDIT_DIR / "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json",
    "wave13": AUDIT_DIR / "evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json",
    "wave14": AUDIT_DIR / "evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json",
    "wave15": AUDIT_DIR / "evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json",
    "wave16_cohort": AUDIT_DIR / "evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json",
    "wave16": AUDIT_DIR / "evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json",
    "wave17": AUDIT_DIR / "evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json",
    "wave17_review": AUDIT_DIR / "evidence/source/raw-run-118r-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json",
    "queue": AUDIT_DIR / "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json",
    "cohort": AUDIT_DIR / "evidence/source/root-run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18.json",
    "review": AUDIT_DIR / "evidence/source/raw-run-121r-independent-outcome-neutral-finance-chart-route-action-review-wave-18.json",
}

EXPECTED_INPUT_SHA256 = {
    "matrix": "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390",
    "baseline": "bfec52a9bed7176045457e8920bdc7b65e1c1d5c5eeb28a266f49c12acf69bcf",
    "wave11_cohort": "7b1fea57104e9f4bfea7ee450ef535ac57e7fa69bc978212b4eafb0fe1ed0cae",
    "wave11": "5390e55d9e47d1845afa8b3848bdab7687747cbb62c946dd4d66d3c435e8de0b",
    "wave12": "7f76c9cfeae906a64c92013fbf9a12cf180d39a315bfa13e778b6332365cdd2a",
    "wave13": "cf68900351832c790ec53b4996daaf640c604c498f902c85dec681113bf492dd",
    "wave14": "9c1600d2365c3527e185f313b7cb1586705f39cefb15d5d212dc708b45b630ea",
    "wave15": "9375fb374b589c5068334795fa03d80a7ba1fd35695808f99c9e2591e886efca",
    "wave16_cohort": "0849ca5c10e31a4f3e5133a521a570d60b004e723fb17a7f2e812e485f345461",
    "wave16": "cbeb46be682ca0c9ca54012e2c27cf548b54e9cbb75b050fcd61691ca43aaef2",
    "wave17": "33dd2f8ffa2b35c6651a6bf18923872e70ba5c2f91fce8b7222666bb8a91fc8b",
    "wave17_review": "043d57357e3ff1ede8f0effacdb71e4d802d98d53d555ab39316bce33fe06a2d",
    "queue": "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    "cohort": "cfe0e3635e5e86bf8e7e2f65d2094743738bfa5edc36e361ecf5eb14986f316e",
    "review": "f70ddd2ddc7ac0c734f4b48bdd19cd2733c3572d038b1dfa1aa185591e567e5f",
}

EXPECTED_IDENTITY = {
    "alias_route_record_id_list_sha256": "7375244863fd376702f50d317f29c3e588ab741a4924317b58b86f85c95ca42c",
    "combined_bridge_key_list_sha256": "c8bc97e2afe9f849a3bcf81f271e0e43066266a0bd0c65770846ccc36cb88f61",
    "combined_feature_id_list_sha256": "874e55340e6abb600c50a64a147b81b9b5467587ee77f9e45eace4eb85ac55ca",
    "combined_page_feature_id_list_sha256": "2fa6a11a5c66a016366db84ce89931db906b26cc911dfa88490cb6561ff261e8",
    "combined_reviewed_queue_key_list_sha256": "c1e9746f10ea6ea866b9cb78619f71f5e37533984bda904ea112b0a66a195a9c",
    "combined_route_feature_id_list_sha256": "89c69d9c74178944f705a60d004f01e4e5922d06a929b0dfba2342aafd6a3b7a",
    "combined_route_page_overlap_feature_id_list_sha256": "00b1e6141c19311c68d7f8bcd154d971f2099fa768993cca4845baadd02aa217",
    "combined_source_record_id_list_sha256": "87de82d3b26880fcc2b8a94ea4deba7fce2e1dd74c454e529207dc55670bd6c3",
    "combined_source_record_key_list_sha256": "2d8a7d222aff1d38eb56fb73615cc5b9ef5186605233c93aa9204141c08f816c",
    "evidence_gap_route_record_id_list_sha256": "03e106bf8198f640c91187964934ccc0bb106919828f31ac88dd2ab3147c2978",
    "new_page_feature_id_list_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    "new_reviewed_queue_key_list_sha256": "30772a30ac24b953a432c0a26f983f0238e182b5168922ef60266523ecf38a1e",
    "new_route_feature_id_list_sha256": "d90bcde4f20f6c0f5059f655a839f425e9807120fe7d109a454918a35301c1d7",
    "new_union_feature_id_list_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    "non_owner_candidate_id_list_sha256": "c5127b7e41780d311fa2e74fab2f4723bd888e218ad1e8dd0f157071e22bcb0d",
    "owner_action_key_list_sha256": "f817c00d88486e2b07ee6ee1027941b7919e74274a4f920990855529d0c81c15",
    "owner_bridge_key_list_sha256": "e602e3c09dacf6754a55555e8b147e1b57f0d207ebba250d8a7899a5eb3b4ade",
    "owner_candidate_id_list_sha256": "ed77d635a2c72a2b7144eba5ca40416cec4ebffe85dc8ac5fb1a148a293cb946",
    "owner_candidate_record_sha256_list_sha256": "920a4270885198d41ca9182db7ff88b809e3bebf70a9f3560a1298a772fd4080",
    "owner_decision_record_sha256_list_sha256": "e3c21db4579e4581aef1d1d339188a8a157e2dc05c829185d9af378336582565",
    "owner_queue_id_list_sha256": "da5e433e03b9ba9c3f01eabdffe9667bf36d36c65b64e3717efeec3ed4594b54",
    "owner_queue_key_list_sha256": "dc78a223b5e42a8be432f4a006875dedb0e6c88a4a729d0426f485348fb11863",
    "owner_route_record_id_list_sha256": "c48e1794568f053ec8aded97bdefcdc69ac6a9b4996db11372aceaab10152496",
    "owner_source_record_key_list_sha256": "915eafa4c2ff158389e0a0c56f1a19bb598f625c17b373835737e71e183f6640",
    "prior_reviewed_queue_key_list_sha256": "096273b239b8625bc4cffa0537f956773f99039e2b3e7672662a5213b44264e2",
    "shared_route_record_id_list_sha256": "9e8e44a0d63a92ef29abd082b9717268470781c484fe8913739c67f4ad2bd812",
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


def assert_workspace_and_inputs(data: dict[str, dict[str, Any]]) -> None:
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
    assert sha256_file(AUDIT_DIR / data["cohort"]["pins"]["generator"]) == COHORT_GENERATOR_SHA256
    assert sha256_file(AUDIT_DIR / data["review"]["pins"]["materializer"]) == REVIEW_MATERIALIZER_SHA256


def build_overlay_source_records(
    candidates: list[dict[str, Any]], decisions: dict[str, dict[str, Any]]
) -> list[dict[str, Any]]:
    records: list[dict[str, Any]] = []
    for candidate in candidates:
        decision = decisions[candidate["candidate_id"]]
        assert decision["outcome"] == "OWNER_ROUTE_ACTION"
        source = candidate["route_source"]
        feature = candidate["feature_identity_projection"]
        suffix = candidate["candidate_id"].rsplit("-", 1)[-1]
        row = {
            "overlay_mapping_id": f"RUN122-ROUTE-{suffix}",
            "candidate_id": candidate["candidate_id"],
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
            "bridge_id": f"RUN122-BRIDGE-{suffix}",
            "candidate_id": candidate["candidate_id"],
            "candidate_record_sha256": candidate["candidate_record_sha256"],
            "decision_record_sha256": decision["decision_record_sha256"],
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
        assert decision["outcome"] in {"SHARED_RELATION", "ALIAS_OR_REDIRECT", "EVIDENCE_GAP"}
        suffix = candidate["candidate_id"].rsplit("-", 1)[-1]
        row = {
            "reviewed_non_owner_id": f"RUN122-NONOWNER-{suffix}",
            "candidate_id": candidate["candidate_id"],
            "candidate_record_sha256": candidate["candidate_record_sha256"],
            "decision_record_sha256": decision["decision_record_sha256"],
            "outcome": decision["outcome"],
            "route_record_id": candidate["route_source"]["route_record_id"],
            "route_source_key": candidate["route_source"]["source_key"],
            "queue_id": candidate["queue_id"],
            "queue_canonical_key": candidate["queue_canonical_key"],
            "feature_id": candidate["candidate_feature_id"],
            "source_loci": decision["source_loci"],
            "material_dependencies": decision["material_dependencies"],
            "rationale": decision["rationale"],
            "review_discrepancies": decision["review_discrepancies"],
            "route_ownership_credit": False,
            "controller_action_bridge_credit": False,
            "page_ownership_credit": False,
            "correctness_credit": False,
            "runtime_credit": False,
            "benchmark_credit": False,
            "downstream_credit": False,
            "completion_credit": False,
        }
        row["reviewed_non_owner_row_sha256"] = canonical_json_sha256(row)
        rows.append(row)
    return sorted(rows, key=lambda row: row["reviewed_non_owner_id"])


def build() -> dict[str, Any]:
    data = {name: load_json(path) for name, path in INPUT_PATHS.items() if name != "matrix"}
    assert_workspace_and_inputs(data)
    baseline = data["baseline"]
    wave11 = data["wave11"]
    wave12 = data["wave12"]
    wave13 = data["wave13"]
    wave14 = data["wave14"]
    wave15 = data["wave15"]
    wave16 = data["wave16"]
    wave17 = data["wave17"]
    queue = data["queue"]
    cohort = data["cohort"]
    review = data["review"]

    assert data["wave17_review"]["decision"]["verdict"] == "GO"
    assert data["wave17_review"]["decision"]["mechanical_discrepancies"] == 0
    assert data["wave17_review"]["decision"]["semantic_discrepancies"] == 0
    assert data["wave17_review"]["decision"]["reporting_promotion_authorized"] is True
    assert baseline["record_set"]["count"] == len(baseline["records"]) == 530
    assert [
        wave11["combined_counts"]["source_owner_records"],
        wave12["combined_counts"]["source_owner_records"],
        wave13["combined_counts"]["source_owner_records"],
        wave14["combined_counts"]["source_owner_records"],
        wave15["combined_counts"]["source_owner_records"],
        wave16["combined_counts"]["source_owner_records"],
        wave17["combined_counts"]["source_owner_records"],
    ] == [548, 571, 592, 612, 614, 637, 641]
    assert wave17["combined_counts"]["route_owner_records"] == 288
    assert wave17["combined_counts"]["page_owner_records"] == 353
    assert wave17["combined_counts"]["static_controller_action_bridges"] == 76
    assert wave17["queue_accounting"]["reviewed_queue_surface_rows"] == 84
    assert wave17["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 423

    decision_summary = review["decision"]
    assert decision_summary == {
        **decision_summary,
        "verdict": "GO_7_EXPLICIT_OWNER_ROUTE_ACTION_7_SHARED_1_ALIAS_7_EVIDENCE_GAP",
        "mechanical_discrepancies": 0,
        "reviewed_route_actions": 22,
        "owner_route_actions": 7,
        "shared_relations": 7,
        "alias_or_redirect": 1,
        "dead_or_noncanonical": 0,
        "evidence_gaps": 7,
        "static_route_owner_records_authorized": 7,
        "static_controller_action_bridges_authorized": 7,
        "static_page_owner_records_authorized": 0,
        "bounded_overlay_authorized": True,
        "non_owner_outcomes_preserved": True,
        "complete_route_page_feature_crosswalk_authorized": False,
        "matrix_mutation_authorized": False,
        "downstream_credit_authorized": False,
        "gate_4_complete": False,
    }

    candidates = sorted(cohort["records"], key=lambda row: row["candidate_id"])
    decisions = {row["candidate_id"]: row for row in review["action_decisions"]}
    assert len(candidates) == len(decisions) == 22
    assert {row["candidate_id"] for row in candidates} == set(decisions)
    queue_by_id = {row["queue_id"]: row for row in queue["records"]}
    queue_by_key = {row["canonical_key"]: row for row in queue["records"]}
    assert len(queue_by_id) == len(queue_by_key) == 507

    for candidate in candidates:
        candidate_body = {key: value for key, value in candidate.items() if key != "candidate_record_sha256"}
        assert candidate["candidate_record_sha256"] == canonical_json_sha256(candidate_body)
        decision = decisions[candidate["candidate_id"]]
        decision_body = {key: value for key, value in decision.items() if key != "decision_record_sha256"}
        assert decision["decision_record_sha256"] == canonical_json_sha256(decision_body)
        assert decision["candidate_record_sha256"] == candidate["candidate_record_sha256"]
        assert decision["partition_id"] == candidate["review_partition"]
        assert decision["queue_id"] == candidate["queue_id"]
        assert decision["route_record_id"] == candidate["route_source"]["route_record_id"]
        assert decision["candidate_feature_id"] == candidate["candidate_feature_id"]
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
        assert decision["site_permission_privacy_direct_object_ledger_lifecycle_correctness_authorized"] is False
        assert decision["runtime_test_benchmark_ease_pass_completion_authorized"] is False

    by_outcome = {
        outcome: [row for row in candidates if decisions[row["candidate_id"]]["outcome"] == outcome]
        for outcome in ("OWNER_ROUTE_ACTION", "SHARED_RELATION", "ALIAS_OR_REDIRECT", "DEAD_OR_NONCANONICAL", "EVIDENCE_GAP")
    }
    assert {key: len(value) for key, value in by_outcome.items()} == {
        "OWNER_ROUTE_ACTION": 7,
        "SHARED_RELATION": 7,
        "ALIAS_OR_REDIRECT": 1,
        "DEAD_OR_NONCANONICAL": 0,
        "EVIDENCE_GAP": 7,
    }
    owners = by_outcome["OWNER_ROUTE_ACTION"]
    nonowners = [row for outcome, rows in by_outcome.items() if outcome != "OWNER_ROUTE_ACTION" for row in rows]
    assert all(decisions[row["candidate_id"]]["route_ownership_authorized"] is True for row in owners)
    assert all(decisions[row["candidate_id"]]["controller_action_bridge_authorized"] is True for row in owners)
    assert all(decisions[row["candidate_id"]]["route_ownership_authorized"] is False for row in nonowners)
    assert all(decisions[row["candidate_id"]]["controller_action_bridge_authorized"] is False for row in nonowners)

    page_callsites = [
        callsite
        for candidate in candidates
        for callsite in candidate["controller_action"]["literal_inertia_page_callsites"]
    ]
    assert len(page_callsites) == 6
    assert Counter(row["current_page_status"] for row in page_callsites) == {
        "CURRENT_STATIC_SOURCE_OWNER": 2,
        "UNOWNED_CONTEXT_REQUIRES_SEPARATE_PAGE_REVIEW": 4,
    }
    assert all(row["page_ownership_credit_from_this_cohort"] is False for row in page_callsites)
    assert review["page_context_boundary"] == {
        "literal_callsites": 6,
        "currently_owned_page_callsites": 2,
        "unowned_page_callsites": 4,
        "page_ownership_authorized": 0,
        "rule": "Page callsites remain context only and require separate outcome-neutral page review where still unowned.",
    }

    overlay_records = build_overlay_source_records(owners, decisions)
    action_bridges = build_action_bridges(owners, decisions)
    non_owner_outcomes = build_non_owner_outcomes(nonowners, decisions)
    assert len(overlay_records) == len(action_bridges) == 7
    assert len(non_owner_outcomes) == 15

    prior_records = (
        baseline["records"]
        + wave11["overlay_source_records"]
        + wave12["overlay_source_records"]
        + wave13["overlay_source_records"]
        + wave14["overlay_source_records"]
        + wave15["overlay_source_records"]
        + wave16["overlay_source_records"]
        + wave17["overlay_source_records"]
    )
    prior_keys = {row["source_record_key"] for row in prior_records}
    prior_ids = {row["source_record_id"] for row in prior_records}
    prior_route_ids = {row["source_record_id"] for row in prior_records if row["surface"] == "ROUTE_SOURCE_RECORD"}
    new_keys = {row["source_record_key"] for row in overlay_records}
    new_route_ids = {row["source_record_id"] for row in overlay_records}
    reviewed_non_owner_route_ids = {row["route_record_id"] for row in non_owner_outcomes}
    assert len(prior_records) == len(prior_keys) == len(prior_ids) == 641
    assert len(prior_route_ids) == 288
    assert len(new_keys) == len(new_route_ids) == 7
    assert len(reviewed_non_owner_route_ids) == 15
    assert not (prior_keys & new_keys)
    assert not (prior_route_ids & new_route_ids)
    assert not (prior_route_ids & reviewed_non_owner_route_ids)
    assert not (new_route_ids & reviewed_non_owner_route_ids)
    combined_records = prior_records + overlay_records
    combined_keys = {row["source_record_key"] for row in combined_records}
    combined_ids = {row["source_record_id"] for row in combined_records}
    assert len(combined_records) == len(combined_keys) == len(combined_ids) == 648

    prior_bridges = (
        wave11["static_controller_action_bridges"]
        + wave12["new_static_controller_action_bridges"]
        + wave13["new_static_controller_action_bridges"]
        + wave16["new_static_controller_action_bridges"]
    )
    prior_bridge_keys = {(row["controller_file"], row["method"], row["feature_id"]) for row in prior_bridges}
    new_bridge_keys = {(row["controller_file"], row["method"], row["feature_id"]) for row in action_bridges}
    assert len(prior_bridges) == len(prior_bridge_keys) == 76
    assert len(action_bridges) == len(new_bridge_keys) == 7
    assert not (prior_bridge_keys & new_bridge_keys)
    combined_bridge_keys = prior_bridge_keys | new_bridge_keys
    assert len(combined_bridge_keys) == 83

    combined_feature_ids = {row["feature_id"] for row in combined_records}
    route_feature_ids = {row["feature_id"] for row in combined_records if row["surface"] == "ROUTE_SOURCE_RECORD"}
    page_feature_ids = {row["feature_id"] for row in combined_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"}
    overlap_feature_ids = route_feature_ids & page_feature_ids
    feature_class_by_id: dict[str, str] = {}
    for row in combined_records:
        feature_class_by_id.setdefault(row["feature_id"], row["feature_class"])
        assert feature_class_by_id[row["feature_id"]] == row["feature_class"]
    assert len(combined_feature_ids) == 256
    assert Counter(feature_class_by_id.values()) == {"H": 234, "D": 22}
    assert len(route_feature_ids) == 62
    assert len(page_feature_ids) == 242
    assert len(overlap_feature_ids) == 48
    accepted_feature_ids = {row["candidate_feature_id"] for row in owners}
    prior_feature_ids = {row["feature_id"] for row in prior_records}
    prior_route_feature_ids = {row["feature_id"] for row in prior_records if row["surface"] == "ROUTE_SOURCE_RECORD"}
    prior_page_feature_ids = {row["feature_id"] for row in prior_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"}
    new_feature_ids = accepted_feature_ids - prior_feature_ids
    new_route_feature_ids = accepted_feature_ids - prior_route_feature_ids
    new_page_feature_ids = accepted_feature_ids - prior_page_feature_ids
    assert accepted_feature_ids == {"CAP-FIN-CHART-OF-ACCOUNTS"}
    assert new_feature_ids == set()
    assert new_route_feature_ids == accepted_feature_ids
    assert new_page_feature_ids == set()

    wave11_queue_keys: set[str] = set()
    for chain in data["wave11_cohort"]["records"]:
        for surface, source, id_field in (
            ("ROUTE_SOURCE_RECORD", chain["route_source"], "route_record_id"),
            ("PAGE_ROOT_SOURCE_RECORD", chain["page_source"], "page_record_id"),
        ):
            key = canonical_queue_key(surface, source[id_field])
            if key in queue_by_key:
                wave11_queue_keys.add(key)
    wave12_queue_keys = {canonical_queue_key(row["surface"], row["source_record_id"]) for row in wave12["overlay_source_records"]}
    wave13_queue_keys = {
        canonical_queue_key(row["surface"], row["source_record_id"]) for row in wave13["overlay_source_records"]
    } | {f"route|{row['route_record_id']}" for row in wave13["reviewed_non_owner_outcomes"]}
    wave15_queue_keys = {row["queue_canonical_key"] for row in wave15["new_reviewed_queue_outcomes"]}
    wave16_queue_keys = {row["queue_canonical_key"] for row in data["wave16_cohort"]["records"]}
    prior_reviewed_queue_keys = wave11_queue_keys | wave12_queue_keys | wave13_queue_keys | wave15_queue_keys | wave16_queue_keys
    new_reviewed_queue_keys = {row["queue_canonical_key"] for row in candidates}
    assert [len(wave11_queue_keys), len(wave12_queue_keys), len(wave13_queue_keys), len(wave15_queue_keys), len(wave16_queue_keys)] == [12, 23, 24, 1, 24]
    assert len(prior_reviewed_queue_keys) == 84
    assert len(new_reviewed_queue_keys) == 22
    assert prior_reviewed_queue_keys <= set(queue_by_key)
    assert new_reviewed_queue_keys <= set(queue_by_key)
    assert not (prior_reviewed_queue_keys & new_reviewed_queue_keys)
    combined_reviewed_queue_keys = prior_reviewed_queue_keys | new_reviewed_queue_keys
    assert len(combined_reviewed_queue_keys) == 106

    outcome_route_ids = {
        outcome: {row["route_source"]["route_record_id"] for row in rows}
        for outcome, rows in by_outcome.items()
    }
    computed_identity = {
        "owner_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in owners]),
        "owner_route_record_id_list_sha256": canonical_list_sha256(new_route_ids),
        "owner_source_record_key_list_sha256": canonical_list_sha256(new_keys),
        "owner_action_key_list_sha256": canonical_list_sha256([row["action_key"] for row in owners]),
        "owner_bridge_key_list_sha256": canonical_list_sha256(["|".join(key) for key in new_bridge_keys]),
        "owner_candidate_record_sha256_list_sha256": canonical_list_sha256([row["candidate_record_sha256"] for row in owners]),
        "owner_decision_record_sha256_list_sha256": canonical_list_sha256([decisions[row["candidate_id"]]["decision_record_sha256"] for row in owners]),
        "owner_queue_id_list_sha256": canonical_list_sha256([row["queue_id"] for row in owners]),
        "owner_queue_key_list_sha256": canonical_list_sha256([row["queue_canonical_key"] for row in owners]),
        "shared_route_record_id_list_sha256": canonical_list_sha256(outcome_route_ids["SHARED_RELATION"]),
        "alias_route_record_id_list_sha256": canonical_list_sha256(outcome_route_ids["ALIAS_OR_REDIRECT"]),
        "evidence_gap_route_record_id_list_sha256": canonical_list_sha256(outcome_route_ids["EVIDENCE_GAP"]),
        "non_owner_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in nonowners]),
        "new_union_feature_id_list_sha256": canonical_list_sha256(new_feature_ids),
        "new_route_feature_id_list_sha256": canonical_list_sha256(new_route_feature_ids),
        "new_page_feature_id_list_sha256": canonical_list_sha256(new_page_feature_ids),
        "combined_source_record_key_list_sha256": canonical_list_sha256(combined_keys),
        "combined_source_record_id_list_sha256": canonical_list_sha256(combined_ids),
        "combined_feature_id_list_sha256": canonical_list_sha256(combined_feature_ids),
        "combined_route_feature_id_list_sha256": canonical_list_sha256(route_feature_ids),
        "combined_page_feature_id_list_sha256": canonical_list_sha256(page_feature_ids),
        "combined_route_page_overlap_feature_id_list_sha256": canonical_list_sha256(overlap_feature_ids),
        "combined_bridge_key_list_sha256": canonical_list_sha256(["|".join(key) for key in combined_bridge_keys]),
        "new_reviewed_queue_key_list_sha256": canonical_list_sha256(new_reviewed_queue_keys),
        "prior_reviewed_queue_key_list_sha256": canonical_list_sha256(prior_reviewed_queue_keys),
        "combined_reviewed_queue_key_list_sha256": canonical_list_sha256(combined_reviewed_queue_keys),
    }
    if EXPECTED_IDENTITY:
        assert computed_identity == EXPECTED_IDENTITY

    ownership_percent = (Decimal(648) * Decimal(100) / Decimal(3929)).quantize(
        Decimal("0.000001"), rounding=ROUND_HALF_UP
    )
    assert str(ownership_percent) == "16.492746"
    assert review["reviewed_projection_if_integrated"] == {
        "O": 7,
        "S": 7,
        "A": 1,
        "D": 0,
        "E": 7,
        "source_owner_records": 648,
        "route_owner_records": 295,
        "page_owner_records": 353,
        "source_residual_records": 3281,
        "distinct_feature_ids": 256,
        "static_controller_action_bridges": 83,
        "bounded_ownership_percent": "16.492746",
        "queue_records": 507,
        "reviewed_queue_surfaces": 106,
        "owned_queue_surfaces": 84,
        "shared_queue_surfaces": 10,
        "alias_queue_surfaces": 5,
        "dead_queue_surfaces": 0,
        "evidence_gap_queue_surfaces": 7,
        "pending_unreviewed": 401,
        "without_ownership": 423,
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

    payload = {
        "schema_version": "1.0.0",
        "run_id": "RUN-122-REVIEWED-OUTCOME-NEUTRAL-FINANCE-CHART-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-18",
        "status": "SEVEN_REVIEWED_FINANCE_CHART_ROUTE_ACTION_OWNERS_INTEGRATED_SEVEN_SHARED_ONE_ALIAS_SEVEN_GAPS_PRESERVED_STATIC_ONLY",
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
        "architecture_rule": "Oblivion Findings is one operating organisation with multiple Sites. Bounded static Finance route/action ownership does not establish Site access, roles or permissions, canonical ownership, direct-object concealment, privacy, ledger or lifecycle correctness, concurrency, runtime, or release readiness.",
        "name_only_provenance": {
            "identity_relation": "NAME_ONLY",
            "name_only_alone_authorizes_ownership": False,
            "exact_method_resolution_alone_authorizes_ownership": False,
            "ownership_basis": "FRESH_EXACT_CONTROLLER_ACTION_SEMANTIC_REVIEW",
            "backend_candidate_absence_is_negative_proof": False,
        },
        "baseline": {
            "run_id": wave17["run_id"],
            "review_run_id": data["wave17_review"]["run_id"],
            "source_owner_records": 641,
            "route_owner_records": 288,
            "page_owner_records": 353,
            "distinct_feature_ids": 256,
            "static_controller_action_bridges": 76,
            "ledger_sha256": EXPECTED_INPUT_SHA256["wave17"],
            "review_sha256": EXPECTED_INPUT_SHA256["wave17_review"],
        },
        "reviewed_overlay": {
            "producer_run_id": cohort["run_id"],
            "review_run_id": review["run_id"],
            "reviewed_route_actions": 22,
            "owner_route_actions": 7,
            "shared_relations": 7,
            "alias_or_redirect": 1,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 7,
            "accepted_source_owner_records": 7,
            "accepted_route_owner_records": 7,
            "accepted_page_owner_records": 0,
            "accepted_controller_action_bridges": 7,
            "accepted_distinct_feature_ids": 1,
            "new_distinct_feature_ids": 0,
            "new_route_feature_ids": sorted(new_route_feature_ids),
            "new_page_feature_ids": [],
            "reviewed_non_owner_records_preserved": 15,
            "page_ownership_inherited": False,
            "cohort_sha256": EXPECTED_INPUT_SHA256["cohort"],
            "review_sha256": EXPECTED_INPUT_SHA256["review"],
        },
        "identity_reconciliation": review["identity_reconciliation"],
        "combined_counts": {
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
        },
        "queue_accounting": {
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
        },
        "page_context_boundary": review["page_context_boundary"],
        "overlay_source_records": overlay_records,
        "new_static_controller_action_bridges": action_bridges,
        "reviewed_non_owner_outcomes": non_owner_outcomes,
        "identity": identity,
        "outcome_conservation": {
            "reviewed_outcomes_equation": "22 = 7 owner + 7 shared + 1 alias + 0 dead + 7 evidence gap",
            "bounded_source_equation": "3929 = 648 owner + 3281 non-owner residual",
            "owner_surface_equation": "648 = 295 route + 353 page",
            "feature_union_equation": "256 = 62 route + 242 page - 48 overlap",
            "route_universe_equation": "3218 = 295 owner + 12 shared + 5 alias + 0 dead + 2906 residual",
            "evidence_gap_is_tagged_within_2906_route_residual": True,
            "page_universe_equation": "711 = 353 owner + 9 shared + 0 alias + 0 dead + 349 residual",
            "evidence_gap_is_tagged_within_349_page_residual": True,
            "queue_equation": "507 = 106 reviewed + 401 pending",
            "reviewed_queue_equation": "106 = 84 owner + 10 shared + 5 alias + 0 dead + 7 evidence gap",
            "queue_without_ownership_equation": "423 = 401 pending + 10 shared + 5 alias + 0 dead + 7 evidence gap",
        },
        "denominator_boundary": {
            "run_077_bounded_static_records": 3929,
            "framework_expanded_route_page_denominator": None,
            "page_denominator_711_roots_vs_963_page_tree_files_resolved": False,
            "complete_route_page_feature_crosswalk": False,
            "gate_4_complete": False,
        },
        "credit_boundary": {
            "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_7_RECORDS": True,
            "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_7_ACTIONS": True,
            "REVIEWED_SHARED_RELATION_FOR_7_RECORDS": True,
            "REVIEWED_ALIAS_OR_REDIRECT_FOR_1_RECORD": True,
            "REVIEWED_EVIDENCE_GAP_FOR_7_RECORDS": True,
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
            "application_source_changed": False,
            "matrix_changed": False,
            "runtime_or_external_system_changed": False,
            "audit_artifacts_only": True,
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/integrate-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json",
        ],
    }
    payload["identity_discovery"] = computed_identity
    return payload


def main() -> None:
    payload = build()
    if os.environ.get("RUN122_IDENTITY_DISCOVERY") == "1":
        print(json.dumps(payload["identity_discovery"], indent=2, sort_keys=True))
        return
    assert EXPECTED_IDENTITY, "EXPECTED_IDENTITY must be frozen before writing the artifact"
    payload.pop("identity_discovery")
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
        "reviewed_queue_surfaces": payload["queue_accounting"]["reviewed_queue_surface_rows"],
        "pending_queue_surfaces": payload["queue_accounting"]["pending_unreviewed_queue_surface_rows"],
        "gate_4_complete": payload["denominator_boundary"]["gate_4_complete"],
    }, indent=2))


if __name__ == "__main__":
    main()
