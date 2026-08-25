#!/usr/bin/env python3
"""Materialize the independent final-byte and boundary review of RUN-106."""

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
GENERATOR_PATH = AUDIT_DIR / "generators/integrate-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.py"
OVERLAY_PATH = AUDIT_DIR / "evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json"
COHORT_PATH = AUDIT_DIR / "evidence/source/root-run-105-outcome-neutral-page-render-owner-cohort-wave-14.json"
REVIEW_PATH = AUDIT_DIR / "evidence/source/raw-run-105r-independent-outcome-neutral-page-render-owner-review-wave-14.json"
OUTPUT_PATH = AUDIT_DIR / "evidence/source/raw-run-106r-independent-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json"

AUDIT_HEAD = "cb0672cb2979f9b52425cd0dbddb41065746539d"
AUDIT_TREE = "f59fabed37d0efe3f86c6829622d6bab2f7488fb"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
GENERATOR_SHA256 = "5ec50d2740496c793997a6a5e5434bf3623fb11dbbac9d46a147551e762b2a54"
OVERLAY_SHA256 = "9c1600d2365c3527e185f313b7cb1586705f39cefb15d5d212dc708b45b630ea"
COHORT_SHA256 = "4d6868c06a4c94c708e0934682e0c9724b71fc104c3751d02d0acfd3a95370bc"
REVIEW_SHA256 = "764a0d086b206112d7c6b93f3d1fa733d3c3ca865a5f4ba3887d082deed1f907"


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


def assert_workspace_and_files() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == AUDIT_HEAD
    assert git("rev-parse", "HEAD^{tree}") == AUDIT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("status", "--porcelain", "--", "app", "routes", "resources/js") == ""
    assert sha256_file(GENERATOR_PATH) == GENERATOR_SHA256
    assert sha256_file(OVERLAY_PATH) == OVERLAY_SHA256
    assert sha256_file(COHORT_PATH) == COHORT_SHA256
    assert sha256_file(REVIEW_PATH) == REVIEW_SHA256


def build() -> dict[str, Any]:
    assert_workspace_and_files()
    overlay = load_json(OVERLAY_PATH)
    cohort = load_json(COHORT_PATH)
    review = load_json(REVIEW_PATH)

    generator_namespace = runpy.run_path(str(GENERATOR_PATH))
    rebuilt = generator_namespace["build"]()
    rebuilt_bytes = (json.dumps(rebuilt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    assert rebuilt_bytes == OVERLAY_PATH.read_bytes()
    assert sha256_bytes(rebuilt_bytes) == OVERLAY_SHA256

    assert overlay["run_id"] == "RUN-106-REVIEWED-OUTCOME-NEUTRAL-PAGE-RENDER-OWNER-OWNERSHIP-OVERLAY-WAVE-14"
    assert overlay["status"] == "TWENTY_REVIEWED_PAGE_OWNERS_INTEGRATED_THREE_SHARED_ONE_GAP_PRESERVED_BOUNDED_STATIC_ONLY"
    assert overlay["pins"]["generator_sha256"] == GENERATOR_SHA256
    assert overlay["pins"]["inputs"][COHORT_PATH.relative_to(AUDIT_DIR).as_posix()] == COHORT_SHA256
    assert overlay["pins"]["inputs"][REVIEW_PATH.relative_to(AUDIT_DIR).as_posix()] == REVIEW_SHA256
    assert review["decision"]["mechanical_discrepancies"] == 0

    candidates = {row["candidate_id"]: row for row in cohort["records"]}
    decisions = {row["candidate_id"]: row for row in review["page_decisions"]}
    owner_rows = overlay["overlay_source_records"]
    non_owner_rows = overlay["reviewed_non_owner_outcomes"]
    assert len(candidates) == len(decisions) == 24
    assert len(owner_rows) == 20
    assert len(non_owner_rows) == 4
    assert overlay["new_static_controller_action_bridges"] == []
    assert {row["candidate_id"] for row in owner_rows} == {
        candidate_id for candidate_id, decision in decisions.items() if decision["outcome"] == "OWNER_PAGE"
    }
    assert {row["candidate_id"] for row in non_owner_rows} == {
        candidate_id for candidate_id, decision in decisions.items() if decision["outcome"] != "OWNER_PAGE"
    }
    assert Counter(row["outcome"] for row in non_owner_rows) == {"SHARED_RELATION": 3, "EVIDENCE_GAP": 1}

    owner_keys: set[str] = set()
    owner_page_ids: set[str] = set()
    for row in owner_rows:
        row_without_digest = {key: value for key, value in row.items() if key != "overlay_row_sha256"}
        assert row["overlay_row_sha256"] == canonical_json_sha256(row_without_digest)
        candidate = candidates[row["candidate_id"]]
        decision = decisions[row["candidate_id"]]
        assert row["surface"] == "PAGE_ROOT_SOURCE_RECORD"
        assert row["source_record_id"] == candidate["page_source"]["page_record_id"]
        assert row["source_record_key"] == f"page|{row['source_record_id']}|{row['feature_id']}"
        assert row["feature_id"] == candidate["candidate_feature_id"]
        assert row["candidate_record_sha256"] == candidate["candidate_record_sha256"]
        assert row["decision_record_sha256"] == decision["decision_record_sha256"]
        assert row["review_outcome"] == decision["outcome"] == "OWNER_PAGE"
        assert row["static_source_feature_ownership_credit"] is True
        assert not any(row["credit_boundary"].values())
        owner_keys.add(row["source_record_key"])
        owner_page_ids.add(row["source_record_id"])
    assert len(owner_keys) == len(owner_page_ids) == 20

    for row in non_owner_rows:
        row_without_digest = {key: value for key, value in row.items() if key != "reviewed_non_owner_row_sha256"}
        assert row["reviewed_non_owner_row_sha256"] == canonical_json_sha256(row_without_digest)
        decision = decisions[row["candidate_id"]]
        assert row["outcome"] == decision["outcome"]
        assert row["page_ownership_credit"] is False
        assert row["route_ownership_credit"] is False
        assert row["controller_action_bridge_credit"] is False
        assert row["downstream_credit"] is False
        assert row["completion_credit"] is False
    gap = [row for row in non_owner_rows if row["outcome"] == "EVIDENCE_GAP"]
    assert len(gap) == 1
    assert gap[0]["candidate_id"] == "RUN105-PAGE-RENDER-04"
    assert gap[0]["evidence_gap_tagged_within_page_residual"] is True

    counts = overlay["combined_counts"]
    assert counts == {
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
    }
    assert 3929 == 612 + 3317
    assert 612 == 265 + 347
    assert 3218 == 265 + 5 + 3 + 2945
    assert 711 == 347 + 5 + 359
    assert counts["evidence_gap_page_roots_tagged_within_residual"] == 1

    queue = overlay["queue_accounting"]
    assert queue["direct_exact_queue_records"] == 507
    assert queue["reviewed_queue_surface_rows"] == 59
    assert queue["owner_queue_surface_rows"] == 54
    assert queue["shared_queue_surface_rows"] == 2
    assert queue["alias_queue_surface_rows"] == 3
    assert queue["pending_unreviewed_queue_surface_rows"] == 448
    assert queue["queue_surfaces_without_ownership"] == 453
    assert 507 == 59 + 448
    assert 59 == 54 + 2 + 3
    assert 453 == 448 + 2 + 3

    assert overlay["identity"]["new_overlay_source_records_sha256"] == canonical_json_sha256(owner_rows)
    assert overlay["identity"]["new_overlay_row_sha256_list_sha256"] == canonical_list_sha256([row["overlay_row_sha256"] for row in owner_rows])
    assert overlay["identity"]["reviewed_non_owner_outcomes_sha256"] == canonical_json_sha256(non_owner_rows)
    assert overlay["identity"]["reviewed_non_owner_row_sha256_list_sha256"] == canonical_list_sha256([row["reviewed_non_owner_row_sha256"] for row in non_owner_rows])

    credit = overlay["credit_boundary"]
    assert credit["STATIC_PAGE_FEATURE_OWNERSHIP_FOR_20_RECORDS"] is True
    assert credit["REVIEWED_SHARED_RELATION_FOR_3_RECORDS"] is True
    assert credit["REVIEWED_EVIDENCE_GAP_FOR_1_RECORD"] is True
    assert all(
        not value
        for key, value in credit.items()
        if key not in {
            "STATIC_PAGE_FEATURE_OWNERSHIP_FOR_20_RECORDS",
            "REVIEWED_SHARED_RELATION_FOR_3_RECORDS",
            "REVIEWED_EVIDENCE_GAP_FOR_1_RECORD",
        }
    )
    assert overlay["denominator_boundary"]["gate_4_complete"] is False
    assert overlay["audit_completion_test_met"] is False

    return {
        "schema_version": "1.0.0",
        "run_id": "RUN-106R-INDEPENDENT-REVIEWED-OUTCOME-NEUTRAL-PAGE-RENDER-OWNER-OWNERSHIP-OVERLAY-WAVE-14",
        "status": "INDEPENDENT_FINAL_BYTE_AND_BOUNDARY_REVIEW_GO_ZERO_DISCREPANCIES",
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
            "overlay_generator": GENERATOR_PATH.relative_to(AUDIT_DIR).as_posix(),
            "overlay_generator_sha256": GENERATOR_SHA256,
            "overlay": OVERLAY_PATH.relative_to(AUDIT_DIR).as_posix(),
            "overlay_sha256": OVERLAY_SHA256,
            "cohort": COHORT_PATH.relative_to(AUDIT_DIR).as_posix(),
            "cohort_sha256": COHORT_SHA256,
            "review": REVIEW_PATH.relative_to(AUDIT_DIR).as_posix(),
            "review_sha256": REVIEW_SHA256,
            "materializer": Path(__file__).relative_to(AUDIT_DIR).as_posix(),
            "materializer_sha256": sha256_file(Path(__file__)),
        },
        "independent_reviewers": [
            {
                "task_path": "/root/run104_receipt_verify",
                "review_type": "FINAL_BYTE_MECHANICAL_IDENTITY_AND_CONSERVATION",
                "checks": 1351,
                "verdict": "GO_ZERO_DISCREPANCIES",
                "wrote_files": False,
            },
            {
                "task_path": "/root/wave13_checkpoint_hygiene",
                "review_type": "SEMANTIC_BOUNDARY_AND_ZERO_CREDIT",
                "checks": None,
                "verdict": "GO_ZERO_DISCREPANCIES",
                "wrote_files": False,
            },
        ],
        "decision": {
            "verdict": "GO",
            "mechanical_discrepancies": 0,
            "semantic_boundary_discrepancies": 0,
            "wording_discrepancies": 0,
            "arithmetic_discrepancies": 0,
            "owner_page_records_verified": 20,
            "shared_page_records_verified": 3,
            "evidence_gap_page_records_verified": 1,
            "static_page_owner_records_authorized_for_reporting": 20,
            "static_route_owner_records_authorized_for_reporting": 0,
            "static_controller_action_bridges_authorized_for_reporting": 0,
            "reporting_authorized": True,
            "matrix_mutation_authorized": False,
            "gate_4_complete": False,
        },
        "verified_counts": counts,
        "verified_queue_accounting": queue,
        "verified_outcome_conservation": overlay["outcome_conservation"],
        "verified_identity": overlay["identity"],
        "credit_boundary": {
            "STATIC_PAGE_FEATURE_OWNERSHIP_FOR_20_RECORDS": True,
            "REVIEWED_SHARED_RELATION_FOR_3_RECORDS": True,
            "REVIEWED_EVIDENCE_GAP_FOR_1_RECORD": True,
            "reporting_materialization": True,
            "matrix_mutation": False,
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
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-independent-reviewed-outcome-neutral-page-render-owner-ownership-overlay-review-wave-14.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/raw-run-106r-independent-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json",
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
        "verdict": payload["decision"]["verdict"],
        "mechanical_discrepancies": payload["decision"]["mechanical_discrepancies"],
        "semantic_boundary_discrepancies": payload["decision"]["semantic_boundary_discrepancies"],
        "reporting_authorized": payload["decision"]["reporting_authorized"],
    }, indent=2))


if __name__ == "__main__":
    main()
