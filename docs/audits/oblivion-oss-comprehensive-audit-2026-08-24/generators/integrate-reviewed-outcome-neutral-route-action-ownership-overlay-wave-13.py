#!/usr/bin/env python3
"""Integrate only independently reviewed RUN-101 route-action owners.

Twenty-one OWNER_ROUTE_ACTION decisions add one bounded route-source owner and
one controller-action bridge each. Three reviewed redirects are preserved as
non-owner outcomes. No page, runtime, browser, benchmark, Pass, finding, or
completion credit is created.
"""

from __future__ import annotations

import hashlib
import json
import os
import subprocess
from collections import Counter
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_PATH = AUDIT_DIR / "evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json"

AUDIT_HEAD = "a6e6add624a42cd49715709ea310a8484c4903b6"
AUDIT_TREE = "59a7684269e46592de73d95540c6d7fa5fd18c2c"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
COHORT_GENERATOR_SHA256 = "f3ada90da486ba700d21596fb765ab10f661c343944899551006d5db5b9e7a0f"
REVIEW_MATERIALIZER_SHA256 = "e43c20cb44521a7a6613f7e2b204dd8364142990ccb4d1df16931d922c5f04c2"

INPUT_PATHS = {
    "matrix": AUDIT_DIR / "03-feature-to-benchmark-matrix.csv",
    "baseline": AUDIT_DIR / "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json",
    "wave11_overlay": AUDIT_DIR / "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json",
    "wave11_overlay_review": AUDIT_DIR / "evidence/source/raw-run-092r-independent-reviewed-static-source-ownership-overlay-wave-11.json",
    "queue": AUDIT_DIR / "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json",
    "wave12_overlay": AUDIT_DIR / "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json",
    "wave12_overlay_review": AUDIT_DIR / "evidence/source/raw-run-098r-independent-reviewed-route-controller-only-ownership-overlay-wave-12.json",
    "cohort": AUDIT_DIR / "evidence/source/root-run-101-outcome-neutral-route-action-cohort-wave-13.json",
    "cohort_review": AUDIT_DIR / "evidence/source/raw-run-101r-independent-outcome-neutral-route-action-review-wave-13.json",
}

EXPECTED_INPUT_SHA256 = {
    "matrix": "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390",
    "baseline": "bfec52a9bed7176045457e8920bdc7b65e1c1d5c5eeb28a266f49c12acf69bcf",
    "wave11_overlay": "5390e55d9e47d1845afa8b3848bdab7687747cbb62c946dd4d66d3c435e8de0b",
    "wave11_overlay_review": "1111d30aa24935116c37f27bead824ca1bcca7444157e456d959e821af00669a",
    "queue": "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    "wave12_overlay": "7f76c9cfeae906a64c92013fbf9a12cf180d39a315bfa13e778b6332365cdd2a",
    "wave12_overlay_review": "b7ef9888eca1f8ab47653b19be44d9de385f2132148dfed38b5d8d5018b1903b",
    "cohort": "3a8f4c3f11668406f34db7e50ae561fe1c6516e7002eb7e8271851e62c3ff655",
    "cohort_review": "518321096f6a483321e3ad129f730db4b628cb70a74e1dbec4149b08c9b09eba",
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
        assert sha256_file(path) == EXPECTED_INPUT_SHA256[name], (name, sha256_file(path))
    cohort_generator = AUDIT_DIR / cohort["pins"]["generator"]
    review_materializer = AUDIT_DIR / review["pins"]["materializer"]
    assert sha256_file(cohort_generator) == COHORT_GENERATOR_SHA256
    assert sha256_file(review_materializer) == REVIEW_MATERIALIZER_SHA256


def build_overlay_source_records(
    candidates: list[dict[str, Any]], decisions: dict[str, dict[str, Any]]
) -> list[dict[str, Any]]:
    records: list[dict[str, Any]] = []
    for candidate in candidates:
        candidate_id = candidate["candidate_id"]
        decision = decisions[candidate_id]
        assert decision["outcome"] == "OWNER_ROUTE_ACTION"
        source = candidate["route_source"]
        feature = candidate["feature_identity_projection"]
        suffix = candidate_id.rsplit("-", 1)[-1]
        row = {
            "overlay_mapping_id": f"RUN102-ROUTE-{suffix}",
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


def build_action_bridges(candidates: list[dict[str, Any]]) -> list[dict[str, Any]]:
    bridges: list[dict[str, Any]] = []
    for candidate in candidates:
        action = candidate["controller_action"]
        primary = action["primary_method_slice"]
        suffix = candidate["candidate_id"].rsplit("-", 1)[-1]
        bridge = {
            "bridge_id": f"RUN102-BRIDGE-{suffix}",
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
        assert decision["outcome"] != "OWNER_ROUTE_ACTION"
        suffix = candidate["candidate_id"].rsplit("-", 1)[-1]
        row = {
            "reviewed_non_owner_id": f"RUN102-NONOWNER-{suffix}",
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
    baseline = load_json(INPUT_PATHS["baseline"])
    wave11 = load_json(INPUT_PATHS["wave11_overlay"])
    wave11_review = load_json(INPUT_PATHS["wave11_overlay_review"])
    queue = load_json(INPUT_PATHS["queue"])
    wave12 = load_json(INPUT_PATHS["wave12_overlay"])
    wave12_review = load_json(INPUT_PATHS["wave12_overlay_review"])
    cohort = load_json(INPUT_PATHS["cohort"])
    review = load_json(INPUT_PATHS["cohort_review"])
    assert_workspace_and_inputs(cohort, review)

    assert baseline["record_set"]["count"] == 530
    assert len(baseline["records"]) == 530
    assert len(wave11["overlay_source_records"]) == 18
    assert len(wave11["static_controller_action_bridges"]) == 9
    assert wave11["combined_counts"]["source_owner_records"] == 548
    assert wave11_review["decision"]["verdict"] == "GO"
    assert wave12["combined_counts"]["source_owner_records"] == 571
    assert wave12["combined_counts"]["route_owner_records"] == 244
    assert wave12["combined_counts"]["page_owner_records"] == 327
    assert wave12["combined_counts"]["distinct_feature_ids"] == 246
    assert wave12["combined_counts"]["static_controller_action_bridges"] == 32
    assert len(wave12["overlay_source_records"]) == 23
    assert len(wave12["new_static_controller_action_bridges"]) == 23
    assert wave12_review["decision"]["verdict"] == "GO"
    assert wave12_review["decision"]["mechanical_discrepancies"] == 0
    assert review["decision"]["verdict"] == "GO_21_EXPLICIT_OWNER_ROUTE_ACTION_3_EXPLICIT_ALIAS_OR_REDIRECT"
    assert review["decision"]["mechanical_discrepancies"] == 0
    assert review["decision"]["owner_route_actions"] == 21
    assert review["decision"]["alias_or_redirect"] == 3
    assert review["decision"]["static_route_owner_records_authorized"] == 21
    assert review["decision"]["static_controller_action_bridges_authorized"] == 21
    assert review["decision"]["static_page_owner_records_authorized"] == 0
    assert review["decision"]["owner_only_overlay_authorized"] is True
    assert review["decision"]["matrix_mutation_authorized"] is False
    assert review["decision"]["gate_4_complete"] is False
    assert review["credit_boundary"]["STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_21_RECORDS"] is True
    assert review["credit_boundary"]["STATIC_PAGE_FEATURE_OWNERSHIP"] is False

    candidates = sorted(cohort["records"], key=lambda row: row["candidate_id"])
    decisions = {row["candidate_id"]: row for row in review["action_decisions"]}
    assert len(candidates) == len(decisions) == 24
    assert {row["candidate_id"] for row in candidates} == set(decisions)
    for candidate in candidates:
        decision = decisions[candidate["candidate_id"]]
        assert decision["candidate_record_sha256"] == candidate["candidate_record_sha256"]
        assert decision["partition_id"] == candidate["review_partition"]
        assert decision["queue_id"] == candidate["queue_id"]
        assert decision["route_record_id"] == candidate["route_source"]["route_record_id"]
        assert decision["candidate_feature_id"] == candidate["candidate_feature_id"]
        assert decision["outcome"] in cohort["fresh_review_contract"]["allowed_outcomes"]

    owner_candidates = [row for row in candidates if decisions[row["candidate_id"]]["outcome"] == "OWNER_ROUTE_ACTION"]
    non_owner_candidates = [row for row in candidates if decisions[row["candidate_id"]]["outcome"] != "OWNER_ROUTE_ACTION"]
    assert len(owner_candidates) == 21
    assert len(non_owner_candidates) == 3
    assert {decisions[row["candidate_id"]]["outcome"] for row in non_owner_candidates} == {"ALIAS_OR_REDIRECT"}

    overlay_records = build_overlay_source_records(owner_candidates, decisions)
    action_bridges = build_action_bridges(owner_candidates)
    non_owner_outcomes = build_non_owner_outcomes(non_owner_candidates, decisions)
    assert len(overlay_records) == len(action_bridges) == 21
    assert len(non_owner_outcomes) == 3

    prior_records = baseline["records"] + wave11["overlay_source_records"] + wave12["overlay_source_records"]
    prior_keys = {row["source_record_key"] for row in prior_records}
    new_keys = {row["source_record_key"] for row in overlay_records}
    assert len(prior_records) == len(prior_keys) == 571
    assert len(new_keys) == 21
    assert not (prior_keys & new_keys)
    combined_records = prior_records + overlay_records
    combined_keys = {row["source_record_key"] for row in combined_records}
    assert len(combined_records) == len(combined_keys) == 592

    prior_bridges = wave11["static_controller_action_bridges"] + wave12["new_static_controller_action_bridges"]
    prior_bridge_keys = {(row["controller_file"], row["method"], row["feature_id"]) for row in prior_bridges}
    new_bridge_keys = {(row["controller_file"], row["method"], row["feature_id"]) for row in action_bridges}
    assert len(prior_bridges) == len(prior_bridge_keys) == 32
    assert len(new_bridge_keys) == 21
    assert not (prior_bridge_keys & new_bridge_keys)

    combined_feature_ids = {row["feature_id"] for row in combined_records}
    route_feature_ids = {row["feature_id"] for row in combined_records if row["surface"] == "ROUTE_SOURCE_RECORD"}
    page_feature_ids = {row["feature_id"] for row in combined_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"}
    overlap_feature_ids = route_feature_ids & page_feature_ids
    feature_class_by_id: dict[str, str] = {}
    for row in combined_records:
        feature_class_by_id.setdefault(row["feature_id"], row["feature_class"])
        assert feature_class_by_id[row["feature_id"]] == row["feature_class"]
    class_counts = Counter(feature_class_by_id.values())
    assert len(combined_feature_ids) == 249
    assert class_counts == {"H": 229, "D": 20}
    assert len(route_feature_ids) == 59
    assert len(page_feature_ids) == 234
    assert len(overlap_feature_ids) == 44

    accepted_feature_ids = {row["candidate_feature_id"] for row in owner_candidates}
    prior_feature_ids = {row["feature_id"] for row in prior_records}
    new_feature_ids = accepted_feature_ids - prior_feature_ids
    assert len(accepted_feature_ids) == 6
    assert new_feature_ids == {
        "CAP-CATER-RECIPE-LIBRARY",
        "CAP-CATER-PRODUCT-CATALOG",
        "CAP-CATER-DIETARY-TAG-LIBRARY",
    }

    owner_candidate_id_sha256 = canonical_list_sha256([row["candidate_id"] for row in owner_candidates])
    owner_route_id_sha256 = canonical_list_sha256([row["route_source"]["route_record_id"] for row in owner_candidates])
    owner_action_key_sha256 = canonical_list_sha256([row["action_key"] for row in owner_candidates])
    owner_candidate_record_sha256 = canonical_list_sha256([row["candidate_record_sha256"] for row in owner_candidates])
    owner_bridge_key_sha256 = canonical_list_sha256([
        f"{row['controller_action']['primary_method_slice']['source_file']}|{row['controller_action']['primary_method_slice']['method']}|{row['candidate_feature_id']}"
        for row in owner_candidates
    ])
    owner_source_record_key_sha256 = canonical_list_sha256(new_keys)
    alias_candidate_id_sha256 = canonical_list_sha256([row["candidate_id"] for row in non_owner_candidates])
    alias_route_id_sha256 = canonical_list_sha256([row["route_source"]["route_record_id"] for row in non_owner_candidates])
    assert owner_candidate_id_sha256 == "a90b9a3dbe12d0a7a4edb18cd398c242359204b0f4de2212660d6052de738554"
    assert owner_route_id_sha256 == "6400beeb5265c1bac61153d16b7dafd440b301e3360dc10c4f932109ea1a1c07"
    assert owner_action_key_sha256 == "86aa28b5fcba0442215c7545cdb8765bf82244e4dcccfddee32975d921a659f2"
    assert owner_candidate_record_sha256 == "6b624c238d17025d580b21679290b109b7f1ea98861f3b319934aca4ff143783"
    assert owner_bridge_key_sha256 == "ebebe790bd0454899564499709f039c850767433cfc142a18877a88aa99c93ec"
    assert owner_source_record_key_sha256 == "8d77b9a7d8f887fdecbd75057effecab01a0226dad00fc39789c98a9d9896f00"
    assert alias_candidate_id_sha256 == "d39699a352f8427b4bf6887373ff740b3a15c60d38e6b9422de8326877b2d29c"
    assert alias_route_id_sha256 == "b58ef45f4ebc2531daa22b4b7303fcacba9851f23e836b01f30977733f7049cd"

    queue_keys = {row["canonical_key"] for row in queue["records"]}
    cohort_queue_keys = {row["queue_canonical_key"] for row in candidates}
    assert len(queue_keys) == 507
    assert len(cohort_queue_keys) == 24
    assert cohort_queue_keys <= queue_keys
    assert cohort["counts"]["queue_pending_before"] == 472
    assert cohort["counts"]["queue_unselected_pending"] == 448
    assert wave12["queue_accounting"]["reviewed_queue_surface_rows"] == 35
    assert wave12["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 472

    identity = {
        "owner_candidate_id_list_sha256": owner_candidate_id_sha256,
        "owner_route_record_id_list_sha256": owner_route_id_sha256,
        "owner_action_key_list_sha256": owner_action_key_sha256,
        "owner_candidate_record_sha256_list_sha256": owner_candidate_record_sha256,
        "owner_bridge_key_list_sha256": owner_bridge_key_sha256,
        "owner_source_record_key_list_sha256": owner_source_record_key_sha256,
        "alias_candidate_id_list_sha256": alias_candidate_id_sha256,
        "alias_route_record_id_list_sha256": alias_route_id_sha256,
        "new_route_record_id_list_sha256": canonical_list_sha256([row["source_record_id"] for row in overlay_records]),
        "new_feature_id_list_sha256": canonical_list_sha256({row["feature_id"] for row in overlay_records}),
        "new_source_record_key_list_sha256": canonical_list_sha256(new_keys),
        "new_overlay_source_records_sha256": canonical_json_sha256(overlay_records),
        "new_overlay_row_sha256_list_sha256": canonical_list_sha256([row["overlay_row_sha256"] for row in overlay_records]),
        "new_action_bridges_sha256": canonical_json_sha256(action_bridges),
        "new_action_bridge_row_sha256_list_sha256": canonical_list_sha256([row["bridge_row_sha256"] for row in action_bridges]),
        "reviewed_non_owner_outcomes_sha256": canonical_json_sha256(non_owner_outcomes),
        "reviewed_non_owner_row_sha256_list_sha256": canonical_list_sha256([row["reviewed_non_owner_row_sha256"] for row in non_owner_outcomes]),
        "combined_source_record_key_list_sha256": canonical_list_sha256(combined_keys),
        "combined_feature_id_list_sha256": canonical_list_sha256(combined_feature_ids),
        "combined_route_feature_id_list_sha256": canonical_list_sha256(route_feature_ids),
        "combined_page_feature_id_list_sha256": canonical_list_sha256(page_feature_ids),
        "combined_route_page_overlap_feature_id_list_sha256": canonical_list_sha256(overlap_feature_ids),
    }

    return {
        "schema_version": "1.0.0",
        "run_id": "RUN-102-REVIEWED-OUTCOME-NEUTRAL-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-13",
        "status": "TWENTY_ONE_REVIEWED_ROUTE_ACTION_OWNERS_INTEGRATED_THREE_ALIASES_PRESERVED_BOUNDED_STATIC_ONLY",
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
            "inputs": {
                INPUT_PATHS[name].relative_to(AUDIT_DIR).as_posix(): digest
                for name, digest in EXPECTED_INPUT_SHA256.items()
            },
        },
        "architecture_rule": "Oblivion Findings is one operating organisation with multiple Sites. Bounded static route/action ownership does not establish permission, Site/privacy/direct-object/lifecycle correctness, runtime behaviour, or release readiness.",
        "baseline": {
            "run_id": wave12["run_id"],
            "review_run_id": wave12_review["run_id"],
            "source_owner_records": 571,
            "route_owner_records": 244,
            "page_owner_records": 327,
            "distinct_feature_ids": 246,
            "static_controller_action_bridges": 32,
            "ledger_sha256": EXPECTED_INPUT_SHA256["wave12_overlay"],
            "review_sha256": EXPECTED_INPUT_SHA256["wave12_overlay_review"],
        },
        "reviewed_overlay": {
            "producer_run_id": cohort["run_id"],
            "review_run_id": review["run_id"],
            "reviewed_route_actions": 24,
            "owner_route_actions": 21,
            "shared_relations": 0,
            "alias_or_redirect": 3,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 0,
            "accepted_source_owner_records": 21,
            "accepted_route_owner_records": 21,
            "accepted_page_owner_records": 0,
            "accepted_controller_action_bridges": 21,
            "accepted_distinct_feature_ids": len(accepted_feature_ids),
            "new_distinct_feature_ids": len(new_feature_ids),
            "new_feature_ids": sorted(new_feature_ids),
            "reviewed_non_owner_records_preserved": 3,
            "page_ownership_inherited": False,
            "cohort_sha256": EXPECTED_INPUT_SHA256["cohort"],
            "review_sha256": EXPECTED_INPUT_SHA256["cohort_review"],
        },
        "combined_counts": {
            "source_owner_records": 592,
            "route_owner_records": 265,
            "page_owner_records": 327,
            "distinct_feature_ids": 249,
            "distinct_H_feature_ids": 229,
            "distinct_D_feature_ids": 20,
            "route_distinct_feature_ids": 59,
            "page_distinct_feature_ids": 234,
            "route_page_feature_overlap": 44,
            "static_controller_action_bridges": 53,
            "bounded_static_source_denominator": 3929,
            "bounded_static_source_ownership_percent": "15.067447",
            "bounded_static_source_residual_records": 3337,
            "residual_explicit_unmapped_routes": 2945,
            "semantic_shared_routes": 5,
            "reviewed_alias_routes": 3,
            "reviewed_dead_routes": 0,
            "evidence_gap_routes_tagged_within_residual": 0,
            "residual_unadjudicated_page_roots": 382,
            "semantic_shared_page_roots": 2,
        },
        "queue_accounting": {
            "direct_exact_queue_records": 507,
            "reviewed_queue_surface_rows": 59,
            "owner_queue_surface_rows": 54,
            "shared_queue_surface_rows": 2,
            "alias_queue_surface_rows": 3,
            "dead_queue_surface_rows": 0,
            "evidence_gap_queue_surface_rows": 0,
            "pending_unreviewed_queue_surface_rows": 448,
            "queue_surfaces_without_ownership": 453,
            "new_reviewed_route_surface_rows": 24,
            "new_owner_route_surface_rows": 21,
            "new_alias_route_surface_rows": 3,
            "wholesale_queue_ownership_authorized": False,
        },
        "overlay_source_records": overlay_records,
        "new_static_controller_action_bridges": action_bridges,
        "reviewed_non_owner_outcomes": non_owner_outcomes,
        "identity": identity,
        "outcome_conservation": review["outcome_conservation"],
        "denominator_boundary": {
            "run_077_bounded_static_records": 3929,
            "framework_expanded_route_page_denominator": None,
            "page_denominator_711_roots_vs_963_page_tree_files_resolved": False,
            "complete_route_page_feature_crosswalk": False,
            "gate_4_complete": False,
        },
        "credit_boundary": {
            "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_21_RECORDS": True,
            "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_21_ACTIONS": True,
            "REVIEWED_ALIAS_OR_REDIRECT_FOR_3_RECORDS": True,
            "static_page_feature_ownership": False,
            "frontend_caller_ownership": False,
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
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/integrate-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json",
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
        "gate_4_complete": payload["denominator_boundary"]["gate_4_complete"],
    }, indent=2))


if __name__ == "__main__":
    main()
