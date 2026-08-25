#!/usr/bin/env python3
"""Integrate the independently reviewed RUN-105 page-owner decisions.

Exactly twenty OWNER_PAGE decisions add bounded page-source ownership. Three
shared relations and one evidence gap remain reviewed non-owners. Route and
controller-action-bridge counts are unchanged, and no downstream credit is
created.
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
OUTPUT_PATH = AUDIT_DIR / "evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json"

AUDIT_HEAD = "cb0672cb2979f9b52425cd0dbddb41065746539d"
AUDIT_TREE = "f59fabed37d0efe3f86c6829622d6bab2f7488fb"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
COHORT_GENERATOR_SHA256 = "564c37de4525a4587c99d455fa08c6a4a4557441551c6ac5628bd8ae7ca1d31a"
REVIEW_MATERIALIZER_SHA256 = "b1acf84553f91fd6ce71d200126f34ee2c31a622c488d85d490ffd0a536da360"

INPUT_PATHS = {
    "matrix": AUDIT_DIR / "03-feature-to-benchmark-matrix.csv",
    "baseline": AUDIT_DIR / "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json",
    "wave11_overlay": AUDIT_DIR / "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json",
    "wave11_review": AUDIT_DIR / "evidence/source/raw-run-092r-independent-reviewed-static-source-ownership-overlay-wave-11.json",
    "wave12_overlay": AUDIT_DIR / "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json",
    "wave12_review": AUDIT_DIR / "evidence/source/raw-run-098r-independent-reviewed-route-controller-only-ownership-overlay-wave-12.json",
    "wave13_overlay": AUDIT_DIR / "evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json",
    "wave13_review": AUDIT_DIR / "evidence/source/raw-run-102r-independent-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json",
    "queue": AUDIT_DIR / "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json",
    "cohort": AUDIT_DIR / "evidence/source/root-run-105-outcome-neutral-page-render-owner-cohort-wave-14.json",
    "review": AUDIT_DIR / "evidence/source/raw-run-105r-independent-outcome-neutral-page-render-owner-review-wave-14.json",
}
EXPECTED_INPUT_SHA256 = {
    "matrix": "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390",
    "baseline": "bfec52a9bed7176045457e8920bdc7b65e1c1d5c5eeb28a266f49c12acf69bcf",
    "wave11_overlay": "5390e55d9e47d1845afa8b3848bdab7687747cbb62c946dd4d66d3c435e8de0b",
    "wave11_review": "1111d30aa24935116c37f27bead824ca1bcca7444157e456d959e821af00669a",
    "wave12_overlay": "7f76c9cfeae906a64c92013fbf9a12cf180d39a315bfa13e778b6332365cdd2a",
    "wave12_review": "b7ef9888eca1f8ab47653b19be44d9de385f2132148dfed38b5d8d5018b1903b",
    "wave13_overlay": "cf68900351832c790ec53b4996daaf640c604c498f902c85dec681113bf492dd",
    "wave13_review": "f88c3ce6ae7b82ca316c656787547bdd9e6a4cd40469b16d44a6e84f99d14902",
    "queue": "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    "cohort": "4d6868c06a4c94c708e0934682e0c9724b71fc104c3751d02d0acfd3a95370bc",
    "review": "764a0d086b206112d7c6b93f3d1fa733d3c3ca865a5f4ba3887d082deed1f907",
}

EXPECTED_IDENTITY = {
    "owner_candidate_id_list_sha256": "018c0b6fb615f9fc5bad443471e0dbeb1da3b24c9d98b37727f2f96ead10c00c",
    "owner_page_record_id_list_sha256": "11ca27250dff6b2ef40e3f7c38ad83e5ca27dc0e7425f960d7d003790c211fc3",
    "owner_page_feature_key_list_sha256": "e859c610f927e7cd2b6d2c2df80b5df966e054deff6f33603d19235342e35fda",
    "owner_candidate_record_sha256_list_sha256": "71580cac00d1dedaf9aa5495bdf020cfde1b13b81d9caadd0115bbea889e351b",
    "shared_candidate_id_list_sha256": "0aa4fde52375eb95117d17fd8c816d4f2551eb3977d4f7011c0417ff1ce60ece",
    "evidence_gap_candidate_id_list_sha256": "9cd514876fb2eb15560617823066171f47b706f44d5e467ebc2425694794c0e3",
    "owner_feature_id_list_sha256": "f793318d5020c50ea6d8de1e16195f637eb9604c14d842ba6d497a04b5596c72",
    "new_union_feature_id_list_sha256": "a897de8f4d0be9cda0b584b19f0123a625eafcc5ef963ea71dee0d55f6ad7f2c",
    "new_page_feature_id_list_sha256": "da9fbcfa450d25d99ce57b6c51df51940b38a6ed70f963759a1f2eae8fda3e36",
    "new_source_record_key_list_sha256": "35c82750321065fd6b92b585cfa42ae47454953214def585e16e61913148c544",
    "combined_source_record_key_list_sha256": "e6055f4a37e5bfbe76af0f04648ac346e853a0c21f95df9566766c7ee2392a89",
    "combined_feature_id_list_sha256": "874e55340e6abb600c50a64a147b81b9b5467587ee77f9e45eace4eb85ac55ca",
    "combined_route_feature_id_list_sha256": "708ff274b05d54bcade71bc295d5051ab281faa58a839732a240b46d63398563",
    "combined_page_feature_id_list_sha256": "2fa6a11a5c66a016366db84ce89931db906b26cc911dfa88490cb6561ff261e8",
    "combined_route_page_overlap_feature_id_list_sha256": "cc78ed378f40b72e2dc6639251d963ceba2367c95e918362f93ec8c7fad54434",
    "shared_page_record_id_list_sha256": "00ca84aca93b07e6288e973891d52cd966d0ec9f79b09991e8cfedcae35827c1",
    "evidence_gap_page_record_id_list_sha256": "038f53033ab8a22ff77fca9a93cc73e65558cca5c237fb6b199af312fd83e89f",
    "non_owner_page_record_id_list_sha256": "328f182e67fdba6175c860aac3ed9f85bc61de6a61679fb447fc9f588d628d7b",
}

EXPECTED_NEW_FEATURE_IDS = {
    "CAP-FLEET-REPORTING-EXPORT",
    "CAP-GOV-ACTION-ITEM-WORKFLOW",
    "CAP-HR-CANDIDATE-APPLICATION-LIFECYCLE",
    "CAP-MED-PHARMACY-ACTIONS",
    "CAP-OPS-ROSTER-PLANNING-WORKSPACE",
    "CAP-RESP-STAY-LIFECYCLE",
    "CAP-SITE-REPORTING-EXPORT",
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
        assert sha256_file(path) == EXPECTED_INPUT_SHA256[name], (name, sha256_file(path), EXPECTED_INPUT_SHA256[name])
    assert sha256_file(AUDIT_DIR / cohort["pins"]["generator"]) == COHORT_GENERATOR_SHA256
    assert sha256_file(AUDIT_DIR / review["pins"]["materializer"]) == REVIEW_MATERIALIZER_SHA256


def build_overlay_source_records(
    owner_candidates: list[dict[str, Any]], decisions: dict[str, dict[str, Any]]
) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    for candidate in owner_candidates:
        candidate_id = candidate["candidate_id"]
        decision = decisions[candidate_id]
        page = candidate["page_source"]
        feature = candidate["feature_identity_projection"]
        row = {
            "overlay_mapping_id": f"RUN106-PAGE-{candidate_id.rsplit('-', 1)[-1]}",
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
        row = {
            "reviewed_non_owner_id": f"RUN106-NONOWNER-{candidate['candidate_id'].rsplit('-', 1)[-1]}",
            "candidate_id": candidate["candidate_id"],
            "candidate_record_sha256": candidate["candidate_record_sha256"],
            "decision_record_sha256": decision["decision_record_sha256"],
            "outcome": decision["outcome"],
            "page_record_id": page["page_record_id"],
            "page_file": page["page_file"],
            "page_source_key": candidate["page_feature_key"],
            "feature_id": candidate["candidate_feature_id"],
            "rationale": decision["rationale"],
            "review_discrepancies": decision["review_discrepancies"],
            "page_ownership_credit": False,
            "route_ownership_credit": False,
            "controller_action_bridge_credit": False,
            "downstream_credit": False,
            "completion_credit": False,
            "evidence_gap_tagged_within_page_residual": decision["outcome"] == "EVIDENCE_GAP",
        }
        row["reviewed_non_owner_row_sha256"] = canonical_json_sha256(row)
        rows.append(row)
    return sorted(rows, key=lambda row: row["reviewed_non_owner_id"])


def build() -> dict[str, Any]:
    baseline = load_json(INPUT_PATHS["baseline"])
    wave11 = load_json(INPUT_PATHS["wave11_overlay"])
    wave11_review = load_json(INPUT_PATHS["wave11_review"])
    wave12 = load_json(INPUT_PATHS["wave12_overlay"])
    wave12_review = load_json(INPUT_PATHS["wave12_review"])
    wave13 = load_json(INPUT_PATHS["wave13_overlay"])
    wave13_review = load_json(INPUT_PATHS["wave13_review"])
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
    assert wave13["combined_counts"]["route_owner_records"] == 265
    assert wave13["combined_counts"]["page_owner_records"] == 327
    assert wave13["combined_counts"]["static_controller_action_bridges"] == 53
    assert wave13_review["decision"]["verdict"] == "GO"
    assert review["decision"]["verdict"] == "GO_20_EXPLICIT_OWNER_PAGE_3_SHARED_RELATION_1_EVIDENCE_GAP"
    assert review["decision"]["mechanical_discrepancies"] == 0
    assert review["decision"]["static_page_owner_records_authorized"] == 20
    assert review["decision"]["static_route_owner_records_authorized"] == 0
    assert review["decision"]["static_controller_action_bridges_authorized"] == 0
    assert review["decision"]["owner_only_overlay_authorized"] is True
    assert review["decision"]["matrix_mutation_authorized"] is False
    assert review["decision"]["gate_4_complete"] is False

    candidates = sorted(cohort["records"], key=lambda row: row["candidate_id"])
    decisions = {row["candidate_id"]: row for row in review["page_decisions"]}
    assert len(candidates) == len(decisions) == 24
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

    owner_candidates = [row for row in candidates if decisions[row["candidate_id"]]["outcome"] == "OWNER_PAGE"]
    non_owner_candidates = [row for row in candidates if decisions[row["candidate_id"]]["outcome"] != "OWNER_PAGE"]
    assert len(owner_candidates) == 20 and len(non_owner_candidates) == 4
    assert Counter(decisions[row["candidate_id"]]["outcome"] for row in non_owner_candidates) == {
        "SHARED_RELATION": 3, "EVIDENCE_GAP": 1
    }

    overlay_records = build_overlay_source_records(owner_candidates, decisions)
    non_owner_outcomes = build_non_owner_outcomes(non_owner_candidates, decisions)
    assert len(overlay_records) == 20
    assert all(row["surface"] == "PAGE_ROOT_SOURCE_RECORD" for row in overlay_records)
    assert len(non_owner_outcomes) == 4

    prior_records = baseline["records"] + wave11["overlay_source_records"] + wave12["overlay_source_records"] + wave13["overlay_source_records"]
    prior_keys = {row["source_record_key"] for row in prior_records}
    prior_page_ids = {row["source_record_id"] for row in prior_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"}
    new_keys = {row["source_record_key"] for row in overlay_records}
    new_page_ids = {row["source_record_id"] for row in overlay_records}
    assert len(prior_records) == len(prior_keys) == 592
    assert len(new_keys) == len(new_page_ids) == 20
    assert not (prior_keys & new_keys)
    assert not (prior_page_ids & new_page_ids)
    assert not ({row["page_record_id"] for row in non_owner_outcomes} & new_page_ids)
    assert not ({row["page_record_id"] for row in non_owner_outcomes} & prior_page_ids)
    assert all(not any(row["collision_checks"].values()) for row in candidates)

    queue_keys = {row["canonical_key"] for row in queue["records"]}
    cohort_page_queue_keys = {f"page|{row['page_source']['page_record_id']}" for row in candidates}
    assert len(queue_keys) == 507
    assert not (queue_keys & cohort_page_queue_keys)
    assert wave13["queue_accounting"] == {
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
    }

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
    assert len(combined_records) == len(combined_keys) == 612
    assert len(combined_feature_ids) == 256
    assert class_counts == {"H": 234, "D": 22}
    assert len(route_feature_ids) == 59
    assert len(page_feature_ids) == 242
    assert len(overlap_feature_ids) == 45

    accepted_feature_ids = {row["candidate_feature_id"] for row in owner_candidates}
    prior_feature_ids = {row["feature_id"] for row in prior_records}
    new_feature_ids = accepted_feature_ids - prior_feature_ids
    new_page_feature_ids = accepted_feature_ids - {row["feature_id"] for row in prior_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"}
    assert len(accepted_feature_ids) == 15
    assert new_feature_ids == EXPECTED_NEW_FEATURE_IDS
    assert len(new_page_feature_ids) == 8
    assert (route_feature_ids & page_feature_ids) - ({row["feature_id"] for row in prior_records if row["surface"] == "ROUTE_SOURCE_RECORD"} & {row["feature_id"] for row in prior_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"}) == {"CAP-DAY-ALL-TASKS-WORKBENCH"}

    computed_identity = {
        "owner_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in owner_candidates]),
        "owner_page_record_id_list_sha256": canonical_list_sha256(new_page_ids),
        "owner_page_feature_key_list_sha256": canonical_list_sha256([row["page_feature_key"] for row in owner_candidates]),
        "owner_candidate_record_sha256_list_sha256": canonical_list_sha256([row["candidate_record_sha256"] for row in owner_candidates]),
        "shared_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in non_owner_outcomes if row["outcome"] == "SHARED_RELATION"]),
        "evidence_gap_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in non_owner_outcomes if row["outcome"] == "EVIDENCE_GAP"]),
        "owner_feature_id_list_sha256": canonical_list_sha256(accepted_feature_ids),
        "new_union_feature_id_list_sha256": canonical_list_sha256(new_feature_ids),
        "new_page_feature_id_list_sha256": canonical_list_sha256(new_page_feature_ids),
        "new_source_record_key_list_sha256": canonical_list_sha256(new_keys),
        "combined_source_record_key_list_sha256": canonical_list_sha256(combined_keys),
        "combined_feature_id_list_sha256": canonical_list_sha256(combined_feature_ids),
        "combined_route_feature_id_list_sha256": canonical_list_sha256(route_feature_ids),
        "combined_page_feature_id_list_sha256": canonical_list_sha256(page_feature_ids),
        "combined_route_page_overlap_feature_id_list_sha256": canonical_list_sha256(overlap_feature_ids),
        "shared_page_record_id_list_sha256": canonical_list_sha256([row["page_record_id"] for row in non_owner_outcomes if row["outcome"] == "SHARED_RELATION"]),
        "evidence_gap_page_record_id_list_sha256": canonical_list_sha256([row["page_record_id"] for row in non_owner_outcomes if row["outcome"] == "EVIDENCE_GAP"]),
        "non_owner_page_record_id_list_sha256": canonical_list_sha256([row["page_record_id"] for row in non_owner_outcomes]),
    }
    assert computed_identity == EXPECTED_IDENTITY

    ownership_percent = (Decimal(612) * Decimal(100) / Decimal(3929)).quantize(Decimal("0.000001"), rounding=ROUND_HALF_UP)
    assert str(ownership_percent) == "15.576483"

    identity = {
        **computed_identity,
        "new_overlay_source_records_sha256": canonical_json_sha256(overlay_records),
        "new_overlay_row_sha256_list_sha256": canonical_list_sha256([row["overlay_row_sha256"] for row in overlay_records]),
        "reviewed_non_owner_outcomes_sha256": canonical_json_sha256(non_owner_outcomes),
        "reviewed_non_owner_row_sha256_list_sha256": canonical_list_sha256([row["reviewed_non_owner_row_sha256"] for row in non_owner_outcomes]),
        "owner_decision_record_sha256_list_sha256": canonical_list_sha256([decisions[row["candidate_id"]]["decision_record_sha256"] for row in owner_candidates]),
    }

    return {
        "schema_version": "1.0.0",
        "run_id": "RUN-106-REVIEWED-OUTCOME-NEUTRAL-PAGE-RENDER-OWNER-OWNERSHIP-OVERLAY-WAVE-14",
        "status": "TWENTY_REVIEWED_PAGE_OWNERS_INTEGRATED_THREE_SHARED_ONE_GAP_PRESERVED_BOUNDED_STATIC_ONLY",
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
        "architecture_rule": "Oblivion Findings is one operating organisation with multiple Sites. Bounded static page ownership does not establish Site access, roles or permissions, canonical ownership, direct-object concealment, privacy, lifecycle, runtime, or release readiness.",
        "baseline": {
            "run_id": wave13["run_id"],
            "review_run_id": wave13_review["run_id"],
            "source_owner_records": 592,
            "route_owner_records": 265,
            "page_owner_records": 327,
            "distinct_feature_ids": 249,
            "static_controller_action_bridges": 53,
            "ledger_sha256": EXPECTED_INPUT_SHA256["wave13_overlay"],
            "review_sha256": EXPECTED_INPUT_SHA256["wave13_review"],
        },
        "reviewed_overlay": {
            "producer_run_id": cohort["run_id"],
            "review_run_id": review["run_id"],
            "reviewed_pages": 24,
            "owner_pages": 20,
            "shared_relations": 3,
            "alias_or_redirect": 0,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 1,
            "accepted_source_owner_records": 20,
            "accepted_route_owner_records": 0,
            "accepted_page_owner_records": 20,
            "accepted_controller_action_bridges": 0,
            "accepted_distinct_feature_ids": 15,
            "new_distinct_feature_ids": 7,
            "new_feature_ids": sorted(new_feature_ids),
            "new_page_feature_ids": sorted(new_page_feature_ids),
            "reviewed_non_owner_records_preserved": 4,
            "route_ownership_inherited": False,
            "cohort_sha256": EXPECTED_INPUT_SHA256["cohort"],
            "review_sha256": EXPECTED_INPUT_SHA256["review"],
        },
        "combined_counts": {
            "source_owner_records": 612,
            "route_owner_records": 265,
            "page_owner_records": 347,
            "distinct_feature_ids": 256,
            "distinct_H_feature_ids": 234,
            "distinct_D_feature_ids": 22,
            "route_distinct_feature_ids": 59,
            "page_distinct_feature_ids": 242,
            "route_page_feature_overlap": 45,
            "static_controller_action_bridges": 53,
            "bounded_static_source_denominator": 3929,
            "bounded_static_source_ownership_percent": "15.576483",
            "bounded_static_source_residual_records": 3317,
            "residual_explicit_unmapped_routes": 2945,
            "semantic_shared_routes": 5,
            "reviewed_alias_routes": 3,
            "reviewed_dead_routes": 0,
            "evidence_gap_routes_tagged_within_residual": 0,
            "residual_unadjudicated_page_roots": 359,
            "semantic_shared_page_roots": 5,
            "reviewed_alias_page_roots": 0,
            "reviewed_dead_page_roots": 0,
            "evidence_gap_page_roots_tagged_within_residual": 1,
        },
        "queue_accounting": wave13["queue_accounting"],
        "overlay_source_records": overlay_records,
        "new_static_controller_action_bridges": [],
        "reviewed_non_owner_outcomes": non_owner_outcomes,
        "identity": identity,
        "outcome_conservation": {
            **review["outcome_conservation"],
            "bounded_source_equation": "3929 = 612 owner + 3317 non-owner residual",
            "owner_surface_equation": "612 = 265 route + 347 page",
            "route_universe_equation": "3218 = 265 owner + 5 shared + 3 alias + 0 dead + 2945 residual",
            "page_universe_equation": "711 = 347 owner + 5 shared + 0 alias + 0 dead + 359 residual",
            "evidence_gap_is_tagged_within_359_page_residual": True,
            "queue_equation": "507 = 59 reviewed + 448 pending",
        },
        "denominator_boundary": {
            "run_077_bounded_static_records": 3929,
            "framework_expanded_route_page_denominator": None,
            "page_denominator_711_roots_vs_963_page_tree_files_resolved": False,
            "complete_route_page_feature_crosswalk": False,
            "gate_4_complete": False,
        },
        "credit_boundary": {
            "STATIC_PAGE_FEATURE_OWNERSHIP_FOR_20_RECORDS": True,
            "REVIEWED_SHARED_RELATION_FOR_3_RECORDS": True,
            "REVIEWED_EVIDENCE_GAP_FOR_1_RECORD": True,
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
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/integrate-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json",
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
        "distinct_feature_ids": payload["combined_counts"]["distinct_feature_ids"],
        "shared_pages": payload["combined_counts"]["semantic_shared_page_roots"],
        "evidence_gap_pages": payload["combined_counts"]["evidence_gap_page_roots_tagged_within_residual"],
        "gate_4_complete": payload["denominator_boundary"]["gate_4_complete"],
    }, indent=2))


if __name__ == "__main__":
    main()
