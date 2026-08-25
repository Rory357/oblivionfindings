#!/usr/bin/env python3
"""Integrate the independently reviewed RUN-117 respite handover pages.

Exactly four OWNER_PAGE decisions add bounded page-source ownership for the
already represented respite handover feature. The page roots are outside the
RUN-090 direct-exact queue, so queue and controller-action bridge accounting
remain unchanged. No downstream readiness or completion credit is created.
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
OUTPUT_PATH = AUDIT_DIR / "evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json"

AUDIT_HEAD = "92da2701eae4b2472c84a1c04324eb3ff74d015f"
AUDIT_TREE = "9c005e8d4dd486d2b62f6bfab23405ccf21908b8"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
COHORT_GENERATOR_SHA256 = "85068c7a0170e155b3f5e41b87c91d27c7a45f3e2a117ea2444af91eb45a4374"
REVIEW_MATERIALIZER_SHA256 = "717803e612e94ccc0af3e356050a7e72353d2fc7b31dfd2ab00e30b51af8e11f"

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
    "wave15_overlay": AUDIT_DIR / "evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json",
    "wave15_review": AUDIT_DIR / "evidence/source/raw-run-110r-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json",
    "wave16_overlay": AUDIT_DIR / "evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json",
    "wave16_review": AUDIT_DIR / "evidence/source/raw-run-114r-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json",
    "queue": AUDIT_DIR / "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json",
    "cohort": AUDIT_DIR / "evidence/source/root-run-117-outcome-neutral-respite-handover-page-gap-cohort-wave-17.json",
    "review": AUDIT_DIR / "evidence/source/raw-run-117r-independent-outcome-neutral-respite-handover-page-gap-review-wave-17.json",
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
    "wave15_overlay": "9375fb374b589c5068334795fa03d80a7ba1fd35695808f99c9e2591e886efca",
    "wave15_review": "e9b076e790e5346f99665f8f99ee609b4c7b7bac4767e416abc73a57f7dfd867",
    "wave16_overlay": "cbeb46be682ca0c9ca54012e2c27cf548b54e9cbb75b050fcd61691ca43aaef2",
    "wave16_review": "f52ace52820c43ad5043139e18f1d71cf4be904091fbc02e83e045465ded62f2",
    "queue": "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    "cohort": "e468e7e7736e49eea629b4faec1fdce94d7de30eee478b08c81b90793622bd2e",
    "review": "264236eccceb279522fb784a7c27db2ecc8fd0434e4e5668c33fbe263f1cbc9b",
}

EXPECTED_IDENTITY = {
    "owner_candidate_id_list_sha256": "1eff900dc4de7a14153f6aad8bfbde3e13a41e7890867a7a951473a1c258fe19",
    "owner_page_record_id_list_sha256": "a71054d3753e542d05b84cd0e645c7521ffd367e08fd419d4c4be4c6bae44367",
    "owner_page_feature_key_list_sha256": "86e1d57b727184388309159271320032722b515a1c78b91e8d36a0d8a75a3777",
    "owner_candidate_record_sha256_list_sha256": "bae799445a849f2b9808e92bf509aa6508283692d3e9d7af2241229e653735a0",
    "owner_decision_record_sha256_list_sha256": "9116e1ca579ca84b37f6e223029070e63df86f79371a478f0e7daa26b6d0a15e",
    "owner_feature_id_list_sha256": "ce49c9b20b8f0187c051994174adef6458fb14d9bd9de94ce2e94d6286590a99",
    "new_union_feature_id_list_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    "new_page_feature_id_list_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    "new_source_record_key_list_sha256": "87636dfc7d8bca2686b36c20c7df0d9b06d510c9f21624a06d48b4d5db4a9b49",
    "combined_source_record_key_list_sha256": "dc1bbda6f820ec7e833dc74eb8db5eae90f30868a102c3e9661de6f813674d15",
    "combined_feature_id_list_sha256": "874e55340e6abb600c50a64a147b81b9b5467587ee77f9e45eace4eb85ac55ca",
    "combined_route_feature_id_list_sha256": "6c0ffc59f73a61e7b77bec89ae124c518e17230783bfae9f16398eec5f18e5dd",
    "combined_page_feature_id_list_sha256": "2fa6a11a5c66a016366db84ce89931db906b26cc911dfa88490cb6561ff261e8",
    "combined_route_page_overlap_feature_id_list_sha256": "caab1e6ecfbe02867b431cc3c788b649326aabacff7d04effa861ca4b0f86859",
    "parent_candidate_id_list_sha256": "a9be1de656193791a684757962dcf28328effdf849dc9a65c30568499cbc36bf",
    "parent_route_record_id_list_sha256": "44291f8df934c92a041ad03984f66ca0d36b51c8d4e4c52cc050eaefb57b3c5a",
    "parent_queue_id_list_sha256": "97aeb1d938ef1c82ac189766193fb5fc3d1bf4dedd9d9c21851b2bc8917b98ca",
    "render_anchor_list_sha256": "f8b116d62c35a13923c053a7db4104fccffe8a5e96ea1dddac472cf76076cd67",
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
    cohort = data["cohort"]
    review = data["review"]
    assert sha256_file(AUDIT_DIR / cohort["pins"]["generator"]) == COHORT_GENERATOR_SHA256
    assert sha256_file(AUDIT_DIR / review["pins"]["materializer"]) == REVIEW_MATERIALIZER_SHA256


def build_overlay_source_records(
    candidates: list[dict[str, Any]], decisions: dict[str, dict[str, Any]]
) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    for index, candidate in enumerate(candidates, start=1):
        decision = decisions[candidate["candidate_id"]]
        page = candidate["page_source"]
        feature = candidate["feature_identity_projection"]
        parent = candidate["reviewed_parent_action_provenance"]
        row = {
            "overlay_mapping_id": f"RUN118-PAGE-{index:02d}",
            "candidate_id": candidate["candidate_id"],
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
            "parent_candidate_id": parent["parent_candidate_id"],
            "parent_route_record_id": parent["route_record_id"],
            "parent_queue_id": parent["queue_id"],
            "render_source_anchor": parent["selected_render_callsite"]["source_anchor"],
            "review_outcome": "OWNER_PAGE",
            "review_rationale": decision["rationale"],
            "static_readiness_risks": decision["static_readiness_risks"],
            "static_source_feature_ownership_credit": True,
            "credit_boundary": {
                "route_ownership": False,
                "controller_action_bridge": False,
                "direct_exact_queue_review": False,
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


def build() -> dict[str, Any]:
    data = {name: load_json(path) for name, path in INPUT_PATHS.items() if name != "matrix"}
    assert_workspace_and_inputs(data)
    baseline = data["baseline"]
    wave11 = data["wave11_overlay"]
    wave12 = data["wave12_overlay"]
    wave13 = data["wave13_overlay"]
    wave14 = data["wave14_overlay"]
    wave15 = data["wave15_overlay"]
    wave16 = data["wave16_overlay"]
    queue = data["queue"]
    cohort = data["cohort"]
    review = data["review"]

    assert baseline["record_set"]["count"] == len(baseline["records"]) == 530
    assert [wave11["combined_counts"]["source_owner_records"], wave12["combined_counts"]["source_owner_records"], wave13["combined_counts"]["source_owner_records"], wave14["combined_counts"]["source_owner_records"], wave15["combined_counts"]["source_owner_records"], wave16["combined_counts"]["source_owner_records"]] == [548, 571, 592, 612, 614, 637]
    assert wave16["combined_counts"]["route_owner_records"] == 288
    assert wave16["combined_counts"]["page_owner_records"] == 349
    assert wave16["combined_counts"]["static_controller_action_bridges"] == 76
    assert review["decision"]["verdict"] == "GO_4_EXPLICIT_OWNER_PAGE"
    assert review["decision"]["static_page_owner_records_authorized"] == 4
    assert review["decision"]["static_route_owner_records_authorized"] == 0
    assert review["decision"]["static_controller_action_bridges_authorized"] == 0
    assert review["decision"]["owner_only_overlay_authorized"] is True
    assert review["decision"]["matrix_mutation_authorized"] is False
    assert review["decision"]["gate_4_complete"] is False

    candidates = sorted(cohort["records"], key=lambda row: row["candidate_id"])
    decisions = {row["candidate_id"]: row for row in review["page_decisions"]}
    assert len(candidates) == len(decisions) == 4
    assert {row["candidate_id"] for row in candidates} == set(decisions)
    for candidate in candidates:
        decision = decisions[candidate["candidate_id"]]
        without_digest = {key: value for key, value in decision.items() if key != "decision_record_sha256"}
        assert decision["decision_record_sha256"] == canonical_json_sha256(without_digest)
        assert decision["outcome"] == "OWNER_PAGE"
        assert decision["page_ownership_authorized"] is True
        assert decision["route_ownership_authorized"] is False
        assert decision["controller_action_bridge_authorized"] is False
        assert decision["candidate_record_sha256"] == candidate["candidate_record_sha256"]
        assert decision["page_record_id"] == candidate["page_source"]["page_record_id"]
        assert decision["page_file"] == candidate["page_source"]["page_file"]
        assert decision["render_source_anchor"] == candidate["reviewed_parent_action_provenance"]["selected_render_callsite"]["source_anchor"]
        assert candidate["collision_checks"]["direct_queue_overlap"] is False

    overlay_records = build_overlay_source_records(candidates, decisions)
    assert len(overlay_records) == 4
    assert all(row["surface"] == "PAGE_ROOT_SOURCE_RECORD" for row in overlay_records)

    prior_records = baseline["records"] + wave11["overlay_source_records"] + wave12["overlay_source_records"] + wave13["overlay_source_records"] + wave14["overlay_source_records"] + wave15["overlay_source_records"] + wave16["overlay_source_records"]
    prior_keys = {row["source_record_key"] for row in prior_records}
    prior_ids = {row["source_record_id"] for row in prior_records}
    prior_page_ids = {row["source_record_id"] for row in prior_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"}
    new_keys = {row["source_record_key"] for row in overlay_records}
    new_ids = {row["source_record_id"] for row in overlay_records}
    assert len(prior_records) == len(prior_keys) == len(prior_ids) == 637
    assert len(prior_page_ids) == 349
    assert len(new_keys) == len(new_ids) == 4
    assert not (prior_keys & new_keys)
    assert not (prior_ids & new_ids)

    parent_candidate_ids = {row["parent_candidate_id"] for row in overlay_records}
    parent_route_ids = {row["parent_route_record_id"] for row in overlay_records}
    parent_queue_ids = {row["parent_queue_id"] for row in overlay_records}
    wave16_owner_candidate_ids = {row["candidate_id"] for row in wave16["overlay_source_records"]}
    wave16_owner_route_ids = {row["source_record_id"] for row in wave16["overlay_source_records"]}
    wave16_bridge_route_ids = {row["route_record_id"] for row in wave16["new_static_controller_action_bridges"]}
    assert parent_candidate_ids <= wave16_owner_candidate_ids
    assert parent_route_ids <= wave16_owner_route_ids
    assert parent_route_ids <= wave16_bridge_route_ids

    queue_keys = {row["canonical_key"] for row in queue["records"]}
    queue_ids = {row["queue_id"] for row in queue["records"]}
    assert len(queue["records"]) == len(queue_keys) == len(queue_ids) == 507
    assert parent_queue_ids <= queue_ids
    assert not ({f"page|{page_id}" for page_id in new_ids} & queue_keys)

    combined_records = prior_records + overlay_records
    combined_keys = {row["source_record_key"] for row in combined_records}
    combined_ids = {row["source_record_id"] for row in combined_records}
    combined_feature_ids = {row["feature_id"] for row in combined_records}
    route_feature_ids = {row["feature_id"] for row in combined_records if row["surface"] == "ROUTE_SOURCE_RECORD"}
    page_feature_ids = {row["feature_id"] for row in combined_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"}
    overlap_feature_ids = route_feature_ids & page_feature_ids
    assert len(combined_records) == len(combined_keys) == len(combined_ids) == 641
    assert len(combined_feature_ids) == 256
    assert len(route_feature_ids) == 61
    assert len(page_feature_ids) == 242
    assert len(overlap_feature_ids) == 47
    class_by_feature: dict[str, str] = {}
    for row in combined_records:
        class_by_feature.setdefault(row["feature_id"], row["feature_class"])
        assert class_by_feature[row["feature_id"]] == row["feature_class"]
    assert Counter(class_by_feature.values()) == {"H": 234, "D": 22}

    owner_feature_ids = {row["feature_id"] for row in overlay_records}
    prior_feature_ids = {row["feature_id"] for row in prior_records}
    prior_page_feature_ids = {row["feature_id"] for row in prior_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"}
    assert owner_feature_ids == {"CAP-RESP-HANDOVER-NOTES"}
    new_union_feature_ids = owner_feature_ids - prior_feature_ids
    new_page_feature_ids = owner_feature_ids - prior_page_feature_ids
    assert new_union_feature_ids == set()
    assert new_page_feature_ids == set()

    computed_identity = {
        "owner_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in candidates]),
        "owner_page_record_id_list_sha256": canonical_list_sha256(new_ids),
        "owner_page_feature_key_list_sha256": canonical_list_sha256([row["page_feature_key"] for row in candidates]),
        "owner_candidate_record_sha256_list_sha256": canonical_list_sha256([row["candidate_record_sha256"] for row in candidates]),
        "owner_decision_record_sha256_list_sha256": canonical_list_sha256([decisions[row["candidate_id"]]["decision_record_sha256"] for row in candidates]),
        "owner_feature_id_list_sha256": canonical_list_sha256(owner_feature_ids),
        "new_union_feature_id_list_sha256": canonical_list_sha256(new_union_feature_ids),
        "new_page_feature_id_list_sha256": canonical_list_sha256(new_page_feature_ids),
        "new_source_record_key_list_sha256": canonical_list_sha256(new_keys),
        "combined_source_record_key_list_sha256": canonical_list_sha256(combined_keys),
        "combined_feature_id_list_sha256": canonical_list_sha256(combined_feature_ids),
        "combined_route_feature_id_list_sha256": canonical_list_sha256(route_feature_ids),
        "combined_page_feature_id_list_sha256": canonical_list_sha256(page_feature_ids),
        "combined_route_page_overlap_feature_id_list_sha256": canonical_list_sha256(overlap_feature_ids),
        "parent_candidate_id_list_sha256": canonical_list_sha256(parent_candidate_ids),
        "parent_route_record_id_list_sha256": canonical_list_sha256(parent_route_ids),
        "parent_queue_id_list_sha256": canonical_list_sha256(parent_queue_ids),
        "render_anchor_list_sha256": canonical_list_sha256([row["render_source_anchor"] for row in overlay_records]),
    }
    assert computed_identity == EXPECTED_IDENTITY, {
        key: {"actual": computed_identity.get(key), "expected": EXPECTED_IDENTITY.get(key)}
        for key in sorted(set(computed_identity) | set(EXPECTED_IDENTITY))
        if computed_identity.get(key) != EXPECTED_IDENTITY.get(key)
    }

    ownership_percent = (Decimal(641) * Decimal(100) / Decimal(3929)).quantize(Decimal("0.000001"), rounding=ROUND_HALF_UP)
    assert str(ownership_percent) == "16.314584"

    identity = {
        **computed_identity,
        "combined_source_record_id_list_sha256": canonical_list_sha256(combined_ids),
        "new_overlay_source_records_sha256": canonical_json_sha256(overlay_records),
        "new_overlay_row_sha256_list_sha256": canonical_list_sha256([row["overlay_row_sha256"] for row in overlay_records]),
    }

    return {
        "schema_version": "1.0.0",
        "run_id": "RUN-118-REVIEWED-OUTCOME-NEUTRAL-RESPITE-HANDOVER-PAGE-GAP-OWNERSHIP-OVERLAY-WAVE-17",
        "status": "FOUR_REVIEWED_RESPITE_HANDOVER_PAGE_OWNERS_INTEGRATED_BOUNDED_STATIC_ONLY",
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
        "architecture_rule": "Oblivion Findings is one operating organisation with multiple Sites. Bounded static page ownership does not establish Site access, roles or permissions, canonical record ownership, direct-object concealment, privacy, lifecycle, runtime, or release readiness.",
        "baseline": {
            "run_id": wave16["run_id"],
            "review_run_id": data["wave16_review"]["run_id"],
            "source_owner_records": 637,
            "route_owner_records": 288,
            "page_owner_records": 349,
            "distinct_feature_ids": 256,
            "static_controller_action_bridges": 76,
            "ledger_sha256": EXPECTED_INPUT_SHA256["wave16_overlay"],
            "review_sha256": EXPECTED_INPUT_SHA256["wave16_review"],
        },
        "reviewed_overlay": {
            "producer_run_id": cohort["run_id"],
            "review_run_id": review["run_id"],
            "reviewed_pages": 4,
            "owner_pages": 4,
            "shared_relations": 0,
            "alias_or_redirect": 0,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 0,
            "accepted_source_owner_records": 4,
            "accepted_route_owner_records": 0,
            "accepted_page_owner_records": 4,
            "accepted_controller_action_bridges": 0,
            "accepted_distinct_feature_ids": 1,
            "new_distinct_feature_ids": 0,
            "new_feature_ids": [],
            "new_page_feature_ids": [],
            "direct_queue_rows_reconciled": 0,
            "route_ownership_inherited": False,
            "cohort_sha256": EXPECTED_INPUT_SHA256["cohort"],
            "review_sha256": EXPECTED_INPUT_SHA256["review"],
        },
        "combined_counts": {
            "source_owner_records": 641,
            "route_owner_records": 288,
            "page_owner_records": 353,
            "distinct_feature_ids": 256,
            "distinct_H_feature_ids": 234,
            "distinct_D_feature_ids": 22,
            "route_distinct_feature_ids": 61,
            "page_distinct_feature_ids": 242,
            "route_page_feature_overlap": 47,
            "static_controller_action_bridges": 76,
            "bounded_static_source_denominator": 3929,
            "bounded_static_source_ownership_percent": "16.314584",
            "bounded_static_source_residual_records": 3288,
            "residual_explicit_unmapped_routes": 2921,
            "semantic_shared_routes": 5,
            "reviewed_alias_routes": 4,
            "reviewed_dead_routes": 0,
            "evidence_gap_routes_tagged_within_residual": 0,
            "residual_unadjudicated_page_roots": 349,
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
            "new_reviewed_page_surface_rows": 0,
            "new_owner_page_surface_rows": 0,
            "wholesale_queue_ownership_authorized": False,
        },
        "overlay_source_records": overlay_records,
        "new_static_controller_action_bridges": [],
        "reviewed_non_owner_outcomes": [],
        "identity": identity,
        "outcome_conservation": {
            **review["outcome_conservation"],
            "bounded_source_equation": "3929 = 641 owner + 3288 non-owner residual",
            "owner_surface_equation": "641 = 288 route + 353 page",
            "route_universe_equation": "3218 = 288 owner + 5 shared + 4 alias + 0 dead + 2921 residual",
            "page_universe_equation": "711 = 353 owner + 9 shared + 0 alias + 0 dead + 349 residual",
            "evidence_gap_is_tagged_within_349_page_residual": True,
            "queue_equation": "507 = 84 reviewed + 423 pending",
            "reviewed_queue_equation": "84 = 77 owner + 3 shared + 4 alias + 0 dead + 0 evidence gap",
        },
        "denominator_boundary": {
            "run_077_bounded_static_records": 3929,
            "framework_expanded_route_page_denominator": None,
            "page_denominator_711_roots_vs_963_page_tree_files_resolved": False,
            "complete_route_page_feature_crosswalk": False,
            "gate_4_complete": False,
        },
        "credit_boundary": {
            "STATIC_PAGE_FEATURE_OWNERSHIP_FOR_4_RECORDS": True,
            "static_route_feature_ownership_added": False,
            "static_controller_action_bridge_added": False,
            "direct_exact_queue_review_added": False,
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
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/integrate-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json",
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
        "pending_queue_surfaces": payload["queue_accounting"]["pending_unreviewed_queue_surface_rows"],
        "audit_complete": False,
    }, indent=2))


if __name__ == "__main__":
    main()
