#!/usr/bin/env python3
"""Integrate the independently reviewed RUN-109 page-tail decisions.

Exactly two OWNER_PAGE decisions add bounded page-source ownership. Four
shared relations remain reviewed non-owners. One of those shared relations
reconciles its exact pending RUN-090 queue row as reviewed shared, without
ownership. Route and controller-action-bridge counts are unchanged, and no
downstream credit is created.
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
OUTPUT_PATH = AUDIT_DIR / "evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json"

AUDIT_HEAD = "0f6b39812b0e9185cc305159af3e98b897abe50d"
AUDIT_TREE = "83997aae157327d8502e67f8ddf5803e1f92e917"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
COHORT_GENERATOR_SHA256 = "1005eaad8d3bcecf99f04b40f912e5181f28e33ef5acb044c27ba0201d0c8e0c"
REVIEW_MATERIALIZER_SHA256 = "afd6646d04d53f8585eb2dbbeb706fbf5db24a0ccaa404d1d9042ff0773cf184"

INPUT_PATHS = {
    "matrix": AUDIT_DIR / "03-feature-to-benchmark-matrix.csv",
    "baseline": AUDIT_DIR / "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json",
    "wave11_overlay": AUDIT_DIR / "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json",
    "wave11_review": AUDIT_DIR / "evidence/source/raw-run-092r-independent-reviewed-static-source-ownership-overlay-wave-11.json",
    "wave11_cohort": AUDIT_DIR / "evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json",
    "wave12_overlay": AUDIT_DIR / "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json",
    "wave12_review": AUDIT_DIR / "evidence/source/raw-run-098r-independent-reviewed-route-controller-only-ownership-overlay-wave-12.json",
    "wave13_overlay": AUDIT_DIR / "evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json",
    "wave13_review": AUDIT_DIR / "evidence/source/raw-run-102r-independent-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json",
    "wave14_overlay": AUDIT_DIR / "evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json",
    "wave14_review": AUDIT_DIR / "evidence/source/raw-run-106r-independent-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json",
    "queue": AUDIT_DIR / "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json",
    "cohort": AUDIT_DIR / "evidence/source/root-run-109-outcome-neutral-page-render-owner-tail-cohort-wave-15.json",
    "review": AUDIT_DIR / "evidence/source/raw-run-109r-independent-outcome-neutral-page-render-owner-tail-review-wave-15.json",
}

EXPECTED_INPUT_SHA256 = {
    "matrix": "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390",
    "baseline": "bfec52a9bed7176045457e8920bdc7b65e1c1d5c5eeb28a266f49c12acf69bcf",
    "wave11_overlay": "5390e55d9e47d1845afa8b3848bdab7687747cbb62c946dd4d66d3c435e8de0b",
    "wave11_review": "1111d30aa24935116c37f27bead824ca1bcca7444157e456d959e821af00669a",
    "wave11_cohort": "7b1fea57104e9f4bfea7ee450ef535ac57e7fa69bc978212b4eafb0fe1ed0cae",
    "wave12_overlay": "7f76c9cfeae906a64c92013fbf9a12cf180d39a315bfa13e778b6332365cdd2a",
    "wave12_review": "b7ef9888eca1f8ab47653b19be44d9de385f2132148dfed38b5d8d5018b1903b",
    "wave13_overlay": "cf68900351832c790ec53b4996daaf640c604c498f902c85dec681113bf492dd",
    "wave13_review": "f88c3ce6ae7b82ca316c656787547bdd9e6a4cd40469b16d44a6e84f99d14902",
    "wave14_overlay": "9c1600d2365c3527e185f313b7cb1586705f39cefb15d5d212dc708b45b630ea",
    "wave14_review": "4a3252a37d03a609cdf69a4f0a56b41e120d3ba2314dede88317de9c50bfd9e4",
    "queue": "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    "cohort": "9019306fc317374b673d76fc6023efc11deb1f7f83be67d0df72d196cd076187",
    "review": "2d0110c3b44a3e226549d2f9bc3b4fed76d7fed2e70094c04ccf7c3c0c7c94f0",
}

EXPECTED_IDENTITY = {
    "owner_candidate_id_list_sha256": "763c57367d09dbc7d86a1a8f00035fd2ba7d8e51587a501eaf3da72cc5c9dcbb",
    "owner_page_record_id_list_sha256": "d74371bd2e6213746595b83158be499546810abfe91de37774e2a0f78f63f35e",
    "owner_page_feature_key_list_sha256": "53106a4a9e6de69ca33dc331b745ed87c533f98b2b5d68e606eedaab2c431bef",
    "owner_candidate_record_sha256_list_sha256": "bdc8dd5240b457d5b80682553a5838fd9bf1d487510abc8e7d9245ef7cd3af55",
    "shared_candidate_id_list_sha256": "f0fbbf90d349cf0e31bdc73c14e84302a9ce541914d47f629062a3d1097d34f1",
    "owner_feature_id_list_sha256": "2ff177a827e5dc3ce2bf6e9642e2674d4e9a8b21f65ccef07813b737669939b8",
    "new_union_feature_id_list_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    "new_page_feature_id_list_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    "new_source_record_key_list_sha256": "b05a9d7a52ca03134316b2b8ed33ca13eb7aa3b059549aafd790a79ddd902294",
    "combined_source_record_key_list_sha256": "bda095823ceb1a74d1592eadee4e5be788791154d01b97dc1b9c1bf2241ed94c",
    "combined_feature_id_list_sha256": "874e55340e6abb600c50a64a147b81b9b5467587ee77f9e45eace4eb85ac55ca",
    "combined_route_feature_id_list_sha256": "708ff274b05d54bcade71bc295d5051ab281faa58a839732a240b46d63398563",
    "combined_page_feature_id_list_sha256": "2fa6a11a5c66a016366db84ce89931db906b26cc911dfa88490cb6561ff261e8",
    "combined_route_page_overlap_feature_id_list_sha256": "cc78ed378f40b72e2dc6639251d963ceba2367c95e918362f93ec8c7fad54434",
    "shared_page_record_id_list_sha256": "f3b1599d0a6335c5753b067e9406df5a36c8deb3a1740f55802f398ccf2ad23f",
    "non_owner_page_record_id_list_sha256": "f3b1599d0a6335c5753b067e9406df5a36c8deb3a1740f55802f398ccf2ad23f",
    "owner_decision_record_sha256_list_sha256": "04b5e5d6f8a956a928d8b47deecb31bd9b4f2ace26a106c040da9c53000d4114",
    "shared_decision_record_sha256_list_sha256": "d39809d83ca872ad50e0d8575f2096daa8fb4598d3015db52202723d2bcf787a",
    "new_reviewed_queue_key_list_sha256": "594d5aa64f8569cfdbd1ea36e9e0c645a2d50f89641f1f4c61e1f190fffe1648",
    "combined_reviewed_queue_key_list_sha256": "90bd21301e6ed473654cd8c966456fded20f238de2f004bdc74dd456bec6aa0e",
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
    owner_candidates: list[dict[str, Any]], decisions: dict[str, dict[str, Any]]
) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    for candidate in owner_candidates:
        candidate_id = candidate["candidate_id"]
        suffix = candidate_id.rsplit("-", 1)[-1]
        decision = decisions[candidate_id]
        page = candidate["page_source"]
        feature = candidate["feature_identity_projection"]
        row = {
            "overlay_mapping_id": f"RUN110-PAGE-{suffix}",
            "candidate_id": candidate_id,
            "candidate_record_sha256": candidate["candidate_record_sha256"],
            "decision_record_sha256": decision["decision_record_sha256"],
            "surface": "PAGE_ROOT_SOURCE_RECORD",
            "source_record_id": page["page_record_id"],
            "source_record_key": f"page|{page['page_record_id']}|{candidate['candidate_feature_id']}",
            "feature_id": candidate["candidate_feature_id"],
            "feature_class": feature["feature_class"],
            "module": feature["module"],
            "user_job": feature["user_job"],
            "source": page,
            "render_source_anchor": candidate["render_owner"]["selected_render_callsite"]["source_anchor"],
            "review_outcome": "OWNER_PAGE",
            "review_rationale": decision["rationale"],
            "static_source_feature_ownership_credit": True,
            "credit_boundary": {
                "route_ownership": False,
                "controller_action_bridge": False,
                "framework_route_reachability": False,
                "site_authorization_correctness": False,
                "permission_correctness": False,
                "direct_object_concealment": False,
                "privacy_correctness": False,
                "lifecycle_correctness": False,
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
        rows.append(row)
    return sorted(rows, key=lambda row: row["source_record_id"])


def build_non_owner_outcomes(
    candidates: list[dict[str, Any]], decisions: dict[str, dict[str, Any]]
) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    for candidate in candidates:
        decision = decisions[candidate["candidate_id"]]
        page = candidate["page_source"]
        queue_context = candidate["direct_queue_context"]
        row = {
            "reviewed_non_owner_id": f"RUN110-NONOWNER-{candidate['candidate_id'].rsplit('-', 1)[-1]}",
            "candidate_id": candidate["candidate_id"],
            "candidate_record_sha256": candidate["candidate_record_sha256"],
            "decision_record_sha256": decision["decision_record_sha256"],
            "outcome": decision["outcome"],
            "page_record_id": page["page_record_id"],
            "page_file": page["page_file"],
            "page_source_key": candidate["page_feature_key"],
            "feature_id": candidate["candidate_feature_id"],
            "canonical_feature_ids": decision["canonical_feature_ids"],
            "rationale": decision["rationale"],
            "review_discrepancies": decision["review_discrepancies"],
            "direct_queue_context": queue_context,
            "direct_queue_review_integrated": decision["direct_queue_review_authorized"],
            "page_ownership_credit": False,
            "route_ownership_credit": False,
            "controller_action_bridge_credit": False,
            "downstream_credit": False,
            "completion_credit": False,
            "evidence_gap_tagged_within_page_residual": False,
        }
        row["reviewed_non_owner_row_sha256"] = canonical_json_sha256(row)
        rows.append(row)
    return sorted(rows, key=lambda row: row["reviewed_non_owner_id"])


def build_reviewed_queue_outcomes(
    candidates: list[dict[str, Any]], decisions: dict[str, dict[str, Any]], queue_by_id: dict[str, dict[str, Any]]
) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    for candidate in candidates:
        decision = decisions[candidate["candidate_id"]]
        if not decision["direct_queue_review_authorized"]:
            continue
        context = candidate["direct_queue_context"]
        queue_row = queue_by_id[context["queue_id"]]
        assert decision["outcome"] == "SHARED_RELATION"
        assert queue_row["queue_record_sha256"] == context["queue_record_sha256"]
        assert queue_row["surface"] == context["surface"]
        assert queue_row["source_record_id"] == candidate["page_source"]["page_record_id"]
        assert queue_row["candidate_feature_id"] == candidate["candidate_feature_id"]
        row = {
            "reviewed_queue_outcome_id": "RUN110-QUEUE-SHARED-01",
            "queue_id": queue_row["queue_id"],
            "queue_canonical_key": queue_row["canonical_key"],
            "queue_record_sha256": queue_row["queue_record_sha256"],
            "candidate_id": candidate["candidate_id"],
            "candidate_record_sha256": candidate["candidate_record_sha256"],
            "decision_record_sha256": decision["decision_record_sha256"],
            "outcome": "SHARED_RELATION",
            "feature_id": candidate["candidate_feature_id"],
            "canonical_feature_ids": decision["canonical_feature_ids"],
            "review_status_before": context["review_status_before"],
            "review_status_after": "REVIEWED_SHARED_RELATION",
            "static_source_feature_ownership_credit": False,
            "downstream_credit": False,
            "completion_credit": False,
        }
        row["reviewed_queue_outcome_row_sha256"] = canonical_json_sha256(row)
        rows.append(row)
    return rows


def build() -> dict[str, Any]:
    baseline = load_json(INPUT_PATHS["baseline"])
    wave11 = load_json(INPUT_PATHS["wave11_overlay"])
    wave11_review = load_json(INPUT_PATHS["wave11_review"])
    wave11_cohort = load_json(INPUT_PATHS["wave11_cohort"])
    wave12 = load_json(INPUT_PATHS["wave12_overlay"])
    wave12_review = load_json(INPUT_PATHS["wave12_review"])
    wave13 = load_json(INPUT_PATHS["wave13_overlay"])
    wave13_review = load_json(INPUT_PATHS["wave13_review"])
    wave14 = load_json(INPUT_PATHS["wave14_overlay"])
    wave14_review = load_json(INPUT_PATHS["wave14_review"])
    queue = load_json(INPUT_PATHS["queue"])
    cohort = load_json(INPUT_PATHS["cohort"])
    review = load_json(INPUT_PATHS["review"])
    assert_workspace_and_inputs(cohort, review)

    assert baseline["record_set"]["count"] == len(baseline["records"]) == 530
    assert wave11["combined_counts"]["source_owner_records"] == 548
    assert wave11_review["decision"]["verdict"] == "GO"
    assert wave12["combined_counts"]["source_owner_records"] == 571
    assert wave12_review["decision"]["verdict"] == "GO"
    assert wave13["combined_counts"]["source_owner_records"] == 592
    assert wave13_review["decision"]["verdict"] == "GO"
    assert wave14["combined_counts"]["source_owner_records"] == 612
    assert wave14["combined_counts"]["route_owner_records"] == 265
    assert wave14["combined_counts"]["page_owner_records"] == 347
    assert wave14["combined_counts"]["static_controller_action_bridges"] == 53
    assert wave14_review["decision"]["verdict"] == "GO"
    assert review["decision"]["verdict"] == "GO_2_EXPLICIT_OWNER_PAGE_4_SHARED_RELATION"
    assert review["decision"]["mechanical_discrepancies"] == 0
    assert review["decision"]["static_page_owner_records_authorized"] == 2
    assert review["decision"]["static_route_owner_records_authorized"] == 0
    assert review["decision"]["static_controller_action_bridges_authorized"] == 0
    assert review["decision"]["direct_queue_reviewed_shared_records_authorized"] == 1
    assert review["decision"]["owner_only_overlay_authorized"] is True
    assert review["decision"]["direct_queue_review_overlay_authorized"] is True
    assert review["decision"]["matrix_mutation_authorized"] is False
    assert review["decision"]["gate_4_complete"] is False

    candidates = sorted(cohort["records"], key=lambda row: row["candidate_id"])
    decisions = {row["candidate_id"]: row for row in review["page_decisions"]}
    assert len(candidates) == len(decisions) == 6
    assert {row["candidate_id"] for row in candidates} == set(decisions)
    for candidate in candidates:
        decision = decisions[candidate["candidate_id"]]
        decision_without_digest = {key: value for key, value in decision.items() if key != "decision_record_sha256"}
        assert decision["decision_record_sha256"] == canonical_json_sha256(decision_without_digest)
        assert decision["candidate_record_sha256"] == candidate["candidate_record_sha256"]
        assert decision["partition_id"] == candidate["review_partition"]
        assert decision["page_feature_key"] == candidate["page_feature_key"]
        assert decision["page_record_id"] == candidate["page_source"]["page_record_id"]
        assert decision["page_file"] == candidate["page_source"]["page_file"]
        assert decision["candidate_feature_id"] == candidate["candidate_feature_id"]
        assert decision["render_source_anchor"] == candidate["render_owner"]["selected_render_callsite"]["source_anchor"]
        collisions = candidate["collision_checks"]
        assert collisions["prior_review_page_collision"] is False
        assert collisions["current_owner_page_collision"] is False
        assert collisions["conflicting_candidate_lane"] is False
        assert collisions["unreconciled_direct_queue_collision"] is False
        queue_overlap = candidate["candidate_id"] == "RUN109-PAGE-TAIL-03"
        assert collisions["direct_queue_pending_overlap_present"] is queue_overlap
        assert collisions["direct_queue_pending_overlap_reconciled"] is queue_overlap

    owner_candidates = [row for row in candidates if decisions[row["candidate_id"]]["outcome"] == "OWNER_PAGE"]
    non_owner_candidates = [row for row in candidates if decisions[row["candidate_id"]]["outcome"] != "OWNER_PAGE"]
    assert [row["candidate_id"] for row in owner_candidates] == ["RUN109-PAGE-TAIL-01", "RUN109-PAGE-TAIL-04"]
    assert [row["candidate_id"] for row in non_owner_candidates] == [
        "RUN109-PAGE-TAIL-02", "RUN109-PAGE-TAIL-03", "RUN109-PAGE-TAIL-05", "RUN109-PAGE-TAIL-06"
    ]
    assert Counter(decisions[row["candidate_id"]]["outcome"] for row in non_owner_candidates) == {"SHARED_RELATION": 4}

    queue_by_id = {row["queue_id"]: row for row in queue["records"]}
    queue_by_key = {row["canonical_key"]: row for row in queue["records"]}
    assert len(queue_by_id) == len(queue_by_key) == 507
    for queue_row in queue["records"]:
        without_digest = {key: value for key, value in queue_row.items() if key != "queue_record_sha256"}
        assert queue_row["queue_record_sha256"] == canonical_json_sha256(without_digest)

    overlay_records = build_overlay_source_records(owner_candidates, decisions)
    non_owner_outcomes = build_non_owner_outcomes(non_owner_candidates, decisions)
    reviewed_queue_outcomes = build_reviewed_queue_outcomes(candidates, decisions, queue_by_id)
    assert len(overlay_records) == 2
    assert len(non_owner_outcomes) == 4
    assert len(reviewed_queue_outcomes) == 1
    assert all(row["surface"] == "PAGE_ROOT_SOURCE_RECORD" for row in overlay_records)

    prior_records = (
        baseline["records"]
        + wave11["overlay_source_records"]
        + wave12["overlay_source_records"]
        + wave13["overlay_source_records"]
        + wave14["overlay_source_records"]
    )
    prior_keys = {row["source_record_key"] for row in prior_records}
    prior_page_ids = {row["source_record_id"] for row in prior_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"}
    new_keys = {row["source_record_key"] for row in overlay_records}
    new_page_ids = {row["source_record_id"] for row in overlay_records}
    non_owner_page_ids = {row["page_record_id"] for row in non_owner_outcomes}
    assert len(prior_records) == len(prior_keys) == 612
    assert len(new_keys) == len(new_page_ids) == 2
    assert not (prior_keys & new_keys)
    assert not (prior_page_ids & new_page_ids)
    assert not (non_owner_page_ids & new_page_ids)
    assert not (non_owner_page_ids & prior_page_ids)

    combined_records = prior_records + overlay_records
    combined_keys = {row["source_record_key"] for row in combined_records}
    combined_feature_ids = {row["feature_id"] for row in combined_records}
    route_feature_ids = {row["feature_id"] for row in combined_records if row["surface"] == "ROUTE_SOURCE_RECORD"}
    page_feature_ids = {row["feature_id"] for row in combined_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"}
    overlap_feature_ids = route_feature_ids & page_feature_ids
    feature_class_by_id: dict[str, str] = {}
    for row in combined_records:
        feature_class_by_id.setdefault(row["feature_id"], row["feature_class"])
        assert feature_class_by_id[row["feature_id"]] == row["feature_class"]
    class_counts = Counter(feature_class_by_id.values())
    assert len(combined_records) == len(combined_keys) == 614
    assert len(combined_feature_ids) == 256
    assert class_counts == {"H": 234, "D": 22}
    assert len(route_feature_ids) == 59
    assert len(page_feature_ids) == 242
    assert len(overlap_feature_ids) == 45

    accepted_feature_ids = {row["candidate_feature_id"] for row in owner_candidates}
    prior_feature_ids = {row["feature_id"] for row in prior_records}
    prior_page_feature_ids = {row["feature_id"] for row in prior_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"}
    new_feature_ids = accepted_feature_ids - prior_feature_ids
    new_page_feature_ids = accepted_feature_ids - prior_page_feature_ids
    assert accepted_feature_ids == {"CAP-CLIN-PROTOCOL-LIFECYCLE", "CAP-FLEET-REPORTING-EXPORT"}
    assert new_feature_ids == set()
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
    assert len(wave11_queue_keys) == 12
    assert len(wave12_queue_keys) == 23 and wave12_queue_keys <= set(queue_by_key)
    assert len(wave13_queue_keys) == 24 and wave13_queue_keys <= set(queue_by_key)
    assert not (wave11_queue_keys & wave12_queue_keys)
    assert not (wave11_queue_keys & wave13_queue_keys)
    assert not (wave12_queue_keys & wave13_queue_keys)
    prior_reviewed_queue_keys = wave11_queue_keys | wave12_queue_keys | wave13_queue_keys
    new_reviewed_queue_keys = {row["queue_canonical_key"] for row in reviewed_queue_outcomes}
    assert len(prior_reviewed_queue_keys) == 59
    assert new_reviewed_queue_keys == {"page|PAGE-ROOT-D25DE8AB268739E6"}
    assert new_reviewed_queue_keys <= set(queue_by_key)
    assert not (prior_reviewed_queue_keys & new_reviewed_queue_keys)
    combined_reviewed_queue_keys = prior_reviewed_queue_keys | new_reviewed_queue_keys
    assert len(combined_reviewed_queue_keys) == 60
    assert wave14["queue_accounting"]["reviewed_queue_surface_rows"] == 59
    assert wave14["queue_accounting"]["owner_queue_surface_rows"] == 54
    assert wave14["queue_accounting"]["shared_queue_surface_rows"] == 2
    assert wave14["queue_accounting"]["alias_queue_surface_rows"] == 3
    assert wave14["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 448
    assert wave14["queue_accounting"]["queue_surfaces_without_ownership"] == 453

    computed_identity = {
        "owner_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in owner_candidates]),
        "owner_page_record_id_list_sha256": canonical_list_sha256(new_page_ids),
        "owner_page_feature_key_list_sha256": canonical_list_sha256([row["page_feature_key"] for row in owner_candidates]),
        "owner_candidate_record_sha256_list_sha256": canonical_list_sha256([row["candidate_record_sha256"] for row in owner_candidates]),
        "shared_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in non_owner_candidates]),
        "owner_feature_id_list_sha256": canonical_list_sha256(accepted_feature_ids),
        "new_union_feature_id_list_sha256": canonical_list_sha256(new_feature_ids),
        "new_page_feature_id_list_sha256": canonical_list_sha256(new_page_feature_ids),
        "new_source_record_key_list_sha256": canonical_list_sha256(new_keys),
        "combined_source_record_key_list_sha256": canonical_list_sha256(combined_keys),
        "combined_feature_id_list_sha256": canonical_list_sha256(combined_feature_ids),
        "combined_route_feature_id_list_sha256": canonical_list_sha256(route_feature_ids),
        "combined_page_feature_id_list_sha256": canonical_list_sha256(page_feature_ids),
        "combined_route_page_overlap_feature_id_list_sha256": canonical_list_sha256(overlap_feature_ids),
        "shared_page_record_id_list_sha256": canonical_list_sha256(non_owner_page_ids),
        "non_owner_page_record_id_list_sha256": canonical_list_sha256(non_owner_page_ids),
        "owner_decision_record_sha256_list_sha256": canonical_list_sha256([decisions[row["candidate_id"]]["decision_record_sha256"] for row in owner_candidates]),
        "shared_decision_record_sha256_list_sha256": canonical_list_sha256([decisions[row["candidate_id"]]["decision_record_sha256"] for row in non_owner_candidates]),
        "new_reviewed_queue_key_list_sha256": canonical_list_sha256(new_reviewed_queue_keys),
        "combined_reviewed_queue_key_list_sha256": canonical_list_sha256(combined_reviewed_queue_keys),
    }
    assert computed_identity == EXPECTED_IDENTITY

    ownership_percent = (Decimal(614) * Decimal(100) / Decimal(3929)).quantize(
        Decimal("0.000001"), rounding=ROUND_HALF_UP
    )
    assert str(ownership_percent) == "15.627386"

    identity = {
        **computed_identity,
        "new_overlay_source_records_sha256": canonical_json_sha256(overlay_records),
        "new_overlay_row_sha256_list_sha256": canonical_list_sha256([row["overlay_row_sha256"] for row in overlay_records]),
        "reviewed_non_owner_outcomes_sha256": canonical_json_sha256(non_owner_outcomes),
        "reviewed_non_owner_row_sha256_list_sha256": canonical_list_sha256([row["reviewed_non_owner_row_sha256"] for row in non_owner_outcomes]),
        "reviewed_queue_outcomes_sha256": canonical_json_sha256(reviewed_queue_outcomes),
        "reviewed_queue_outcome_row_sha256_list_sha256": canonical_list_sha256([row["reviewed_queue_outcome_row_sha256"] for row in reviewed_queue_outcomes]),
    }

    return {
        "schema_version": "1.0.0",
        "run_id": "RUN-110-REVIEWED-OUTCOME-NEUTRAL-PAGE-RENDER-OWNER-TAIL-OWNERSHIP-OVERLAY-WAVE-15",
        "status": "TWO_REVIEWED_PAGE_OWNERS_INTEGRATED_FOUR_SHARED_PRESERVED_ONE_QUEUE_SHARED_RECONCILED_BOUNDED_STATIC_ONLY",
        "generated_on": "2026-08-25",
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
        "architecture_rule": "Oblivion Findings is one operating organisation with multiple Sites. Bounded static page ownership and reviewed queue accounting do not establish Site access, roles or permissions, canonical ownership, direct-object concealment, privacy, lifecycle, runtime, or release readiness.",
        "baseline": {
            "run_id": wave14["run_id"],
            "review_run_id": wave14_review["run_id"],
            "source_owner_records": 612,
            "route_owner_records": 265,
            "page_owner_records": 347,
            "distinct_feature_ids": 256,
            "static_controller_action_bridges": 53,
            "ledger_sha256": EXPECTED_INPUT_SHA256["wave14_overlay"],
            "review_sha256": EXPECTED_INPUT_SHA256["wave14_review"],
        },
        "reviewed_overlay": {
            "producer_run_id": cohort["run_id"],
            "review_run_id": review["run_id"],
            "reviewed_pages": 6,
            "owner_pages": 2,
            "shared_relations": 4,
            "alias_or_redirect": 0,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 0,
            "accepted_source_owner_records": 2,
            "accepted_route_owner_records": 0,
            "accepted_page_owner_records": 2,
            "accepted_controller_action_bridges": 0,
            "accepted_distinct_feature_ids": 2,
            "new_distinct_feature_ids": 0,
            "new_feature_ids": [],
            "new_page_feature_ids": [],
            "reviewed_non_owner_records_preserved": 4,
            "reviewed_queue_shared_records": 1,
            "route_ownership_inherited": False,
            "cohort_sha256": EXPECTED_INPUT_SHA256["cohort"],
            "review_sha256": EXPECTED_INPUT_SHA256["review"],
        },
        "combined_counts": {
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
            "bounded_static_source_denominator": 3929,
            "bounded_static_source_ownership_percent": "15.627386",
            "bounded_static_source_residual_records": 3315,
            "residual_explicit_unmapped_routes": 2945,
            "semantic_shared_routes": 5,
            "reviewed_alias_routes": 3,
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
            "reviewed_queue_surface_rows": 60,
            "owner_queue_surface_rows": 54,
            "shared_queue_surface_rows": 3,
            "alias_queue_surface_rows": 3,
            "dead_queue_surface_rows": 0,
            "evidence_gap_queue_surface_rows": 0,
            "pending_unreviewed_queue_surface_rows": 447,
            "queue_surfaces_without_ownership": 453,
            "new_reviewed_page_surface_rows": 1,
            "new_owner_page_surface_rows": 0,
            "new_shared_page_surface_rows": 1,
            "wholesale_queue_ownership_authorized": False,
        },
        "overlay_source_records": overlay_records,
        "new_static_controller_action_bridges": [],
        "reviewed_non_owner_outcomes": non_owner_outcomes,
        "new_reviewed_queue_outcomes": reviewed_queue_outcomes,
        "identity": identity,
        "outcome_conservation": {
            **review["outcome_conservation"],
            "bounded_source_equation": "3929 = 614 owner + 3315 non-owner residual",
            "owner_surface_equation": "614 = 265 route + 349 page",
            "route_universe_equation": "3218 = 265 owner + 5 shared + 3 alias + 0 dead + 2945 residual",
            "page_universe_equation": "711 = 349 owner + 9 shared + 0 alias + 0 dead + 353 residual",
            "evidence_gap_is_tagged_within_353_page_residual": True,
            "queue_equation": "507 = 60 reviewed + 447 pending",
            "reviewed_queue_equation": "60 = 54 owner + 3 shared + 3 alias + 0 dead + 0 evidence gap",
        },
        "denominator_boundary": {
            "run_077_bounded_static_records": 3929,
            "framework_expanded_route_page_denominator": None,
            "page_denominator_711_roots_vs_963_page_tree_files_resolved": False,
            "complete_route_page_feature_crosswalk": False,
            "gate_4_complete": False,
        },
        "credit_boundary": {
            "STATIC_PAGE_FEATURE_OWNERSHIP_FOR_2_RECORDS": True,
            "REVIEWED_SHARED_RELATION_FOR_4_RECORDS": True,
            "DIRECT_QUEUE_REVIEWED_SHARED_FOR_1_RECORD": True,
            "static_route_feature_ownership_added": False,
            "static_controller_action_bridge_added": False,
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
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/integrate-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json",
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
        "page_owner_records": payload["combined_counts"]["page_owner_records"],
        "reviewed_queue_surface_rows": payload["queue_accounting"]["reviewed_queue_surface_rows"],
        "pending_unreviewed_queue_surface_rows": payload["queue_accounting"]["pending_unreviewed_queue_surface_rows"],
        "audit_complete": False,
    }, indent=2))


if __name__ == "__main__":
    main()
