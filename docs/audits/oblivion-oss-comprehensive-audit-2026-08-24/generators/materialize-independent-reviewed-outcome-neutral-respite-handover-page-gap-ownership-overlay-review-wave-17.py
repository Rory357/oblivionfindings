#!/usr/bin/env python3
"""Materialize the independent final-byte and boundary review of RUN-118.

This receipt verifies the exact four-page owner-only overlay and its bounded
accounting. It adds no ownership itself and grants no runtime, browser, test,
benchmark, readiness, finding, Pass, or audit-completion credit.
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
OUTPUT_PATH = AUDIT_DIR / "evidence/source/raw-run-118r-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json"

AUDIT_HEAD = "0963bbdbf626b1b684389cffbb28725b09fe9e2f"
AUDIT_TREE = "c47976cd6f1a83ee5267c26e66a354c0d8df4513"
INTEGRATION_CHECKPOINT = "92da2701eae4b2472c84a1c04324eb3ff74d015f"
INTEGRATION_CHECKPOINT_TREE = "9c005e8d4dd486d2b62f6bfab23405ccf21908b8"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
INTEGRATION_GENERATOR_SHA256 = "990d6bbf4879cbaf10e6b4031f640be6bcef346b7e9685e3d3c7da2d846271fb"

INPUT_PATHS = {
    "baseline": AUDIT_DIR / "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json",
    "wave11": AUDIT_DIR / "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json",
    "wave12": AUDIT_DIR / "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json",
    "wave13": AUDIT_DIR / "evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json",
    "wave14": AUDIT_DIR / "evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json",
    "wave15": AUDIT_DIR / "evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json",
    "wave16": AUDIT_DIR / "evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json",
    "cohort": AUDIT_DIR / "evidence/source/root-run-117-outcome-neutral-respite-handover-page-gap-cohort-wave-17.json",
    "semantic_review": AUDIT_DIR / "evidence/source/raw-run-117r-independent-outcome-neutral-respite-handover-page-gap-review-wave-17.json",
    "overlay": AUDIT_DIR / "evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json",
}

EXPECTED_INPUT_SHA256 = {
    "baseline": "bfec52a9bed7176045457e8920bdc7b65e1c1d5c5eeb28a266f49c12acf69bcf",
    "wave11": "5390e55d9e47d1845afa8b3848bdab7687747cbb62c946dd4d66d3c435e8de0b",
    "wave12": "7f76c9cfeae906a64c92013fbf9a12cf180d39a315bfa13e778b6332365cdd2a",
    "wave13": "cf68900351832c790ec53b4996daaf640c604c498f902c85dec681113bf492dd",
    "wave14": "9c1600d2365c3527e185f313b7cb1586705f39cefb15d5d212dc708b45b630ea",
    "wave15": "9375fb374b589c5068334795fa03d80a7ba1fd35695808f99c9e2591e886efca",
    "wave16": "cbeb46be682ca0c9ca54012e2c27cf548b54e9cbb75b050fcd61691ca43aaef2",
    "cohort": "e468e7e7736e49eea629b4faec1fdce94d7de30eee478b08c81b90793622bd2e",
    "semantic_review": "264236eccceb279522fb784a7c27db2ecc8fd0434e4e5668c33fbe263f1cbc9b",
    "overlay": "33dd2f8ffa2b35c6651a6bf18923872e70ba5c2f91fce8b7222666bb8a91fc8b",
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
    assert git("rev-parse", "HEAD^") == INTEGRATION_CHECKPOINT
    assert git("rev-parse", f"{INTEGRATION_CHECKPOINT}^{{tree}}") == INTEGRATION_CHECKPOINT_TREE
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
    overlay = data["overlay"]
    generator = AUDIT_DIR / overlay["pins"]["generator"]
    assert sha256_file(generator) == INTEGRATION_GENERATOR_SHA256
    assert overlay["pins"]["generator_sha256"] == INTEGRATION_GENERATOR_SHA256
    assert overlay["pins"]["checkpoint_commit"] == INTEGRATION_CHECKPOINT
    assert overlay["pins"]["checkpoint_tree"] == INTEGRATION_CHECKPOINT_TREE


def build() -> dict[str, Any]:
    data = {name: load_json(path) for name, path in INPUT_PATHS.items()}
    assert_workspace_and_inputs(data)
    cohort = data["cohort"]
    semantic_review = data["semantic_review"]
    overlay = data["overlay"]

    assert overlay["status"] == "FOUR_REVIEWED_RESPITE_HANDOVER_PAGE_OWNERS_INTEGRATED_BOUNDED_STATIC_ONLY"
    assert overlay["reviewed_overlay"]["reviewed_pages"] == 4
    assert overlay["reviewed_overlay"]["owner_pages"] == 4
    assert overlay["reviewed_overlay"]["accepted_page_owner_records"] == 4
    assert overlay["reviewed_overlay"]["accepted_route_owner_records"] == 0
    assert overlay["reviewed_overlay"]["accepted_controller_action_bridges"] == 0
    assert overlay["reviewed_overlay"]["direct_queue_rows_reconciled"] == 0
    assert semantic_review["decision"]["verdict"] == "GO_4_EXPLICIT_OWNER_PAGE"

    candidates = {row["candidate_id"]: row for row in cohort["records"]}
    decisions = {row["candidate_id"]: row for row in semantic_review["page_decisions"]}
    rows = overlay["overlay_source_records"]
    assert len(candidates) == len(decisions) == len(rows) == 4
    assert {row["candidate_id"] for row in rows} == set(candidates) == set(decisions)
    for row in rows:
        without_digest = {key: value for key, value in row.items() if key != "overlay_row_sha256"}
        assert row["overlay_row_sha256"] == canonical_json_sha256(without_digest)
        candidate = candidates[row["candidate_id"]]
        decision = decisions[row["candidate_id"]]
        decision_without_digest = {key: value for key, value in decision.items() if key != "decision_record_sha256"}
        assert decision["decision_record_sha256"] == canonical_json_sha256(decision_without_digest)
        assert decision["outcome"] == "OWNER_PAGE"
        assert row["candidate_record_sha256"] == candidate["candidate_record_sha256"]
        assert row["decision_record_sha256"] == decision["decision_record_sha256"]
        assert row["source_record_id"] == candidate["page_source"]["page_record_id"]
        assert row["source"]["page_file"] == candidate["page_source"]["page_file"]
        assert row["feature_id"] == candidate["candidate_feature_id"] == "CAP-RESP-HANDOVER-NOTES"
        assert row["parent_candidate_id"] == candidate["reviewed_parent_action_provenance"]["parent_candidate_id"]
        assert row["parent_route_record_id"] == candidate["reviewed_parent_action_provenance"]["route_record_id"]
        assert row["render_source_anchor"] == decision["render_source_anchor"]
        assert row["static_source_feature_ownership_credit"] is True
        assert not any(value for value in row["credit_boundary"].values())

    prior_records = data["baseline"]["records"] + data["wave11"]["overlay_source_records"] + data["wave12"]["overlay_source_records"] + data["wave13"]["overlay_source_records"] + data["wave14"]["overlay_source_records"] + data["wave15"]["overlay_source_records"] + data["wave16"]["overlay_source_records"]
    combined = prior_records + rows
    keys = {row["source_record_key"] for row in combined}
    ids = {row["source_record_id"] for row in combined}
    route_rows = [row for row in combined if row["surface"] == "ROUTE_SOURCE_RECORD"]
    page_rows = [row for row in combined if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"]
    assert len(prior_records) == 637
    assert len(combined) == len(keys) == len(ids) == 641
    assert len(route_rows) == 288
    assert len(page_rows) == 353
    feature_classes: dict[str, str] = {}
    for row in combined:
        feature_classes.setdefault(row["feature_id"], row["feature_class"])
        assert feature_classes[row["feature_id"]] == row["feature_class"]
    assert len(feature_classes) == 256
    assert Counter(feature_classes.values()) == {"H": 234, "D": 22}
    route_features = {row["feature_id"] for row in route_rows}
    page_features = {row["feature_id"] for row in page_rows}
    assert [len(route_features), len(page_features), len(route_features & page_features)] == [61, 242, 47]

    counts = overlay["combined_counts"]
    assert counts["source_owner_records"] == 641
    assert counts["route_owner_records"] == 288
    assert counts["page_owner_records"] == 353
    assert counts["bounded_static_source_residual_records"] == 3288
    assert counts["bounded_static_source_ownership_percent"] == "16.314584"
    assert counts["residual_unadjudicated_page_roots"] == 349
    assert counts["semantic_shared_page_roots"] == 9
    assert counts["evidence_gap_page_roots_tagged_within_residual"] == 1
    queue = overlay["queue_accounting"]
    assert queue == {
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
    }
    assert overlay["new_static_controller_action_bridges"] == []
    assert overlay["reviewed_non_owner_outcomes"] == []
    assert overlay["credit_boundary"]["STATIC_PAGE_FEATURE_OWNERSHIP_FOR_4_RECORDS"] is True
    assert not any(value for key, value in overlay["credit_boundary"].items() if key != "STATIC_PAGE_FEATURE_OWNERSHIP_FOR_4_RECORDS")
    assert overlay["audit_completion_test_met"] is False

    independent_identity = {
        "combined_source_record_key_list_sha256": canonical_list_sha256(keys),
        "combined_source_record_id_list_sha256": canonical_list_sha256(ids),
        "combined_feature_id_list_sha256": canonical_list_sha256(set(feature_classes)),
        "combined_route_feature_id_list_sha256": canonical_list_sha256(route_features),
        "combined_page_feature_id_list_sha256": canonical_list_sha256(page_features),
        "combined_route_page_overlap_feature_id_list_sha256": canonical_list_sha256(route_features & page_features),
        "overlay_row_sha256_list_sha256": canonical_list_sha256([row["overlay_row_sha256"] for row in rows]),
        "overlay_records_sha256": canonical_json_sha256(rows),
    }
    for key, value in independent_identity.items():
        overlay_key = "new_overlay_row_sha256_list_sha256" if key == "overlay_row_sha256_list_sha256" else "new_overlay_source_records_sha256" if key == "overlay_records_sha256" else key
        assert overlay["identity"][overlay_key] == value

    return {
        "schema_version": "1.0.0",
        "run_id": "RUN-118R-INDEPENDENT-REVIEWED-OUTCOME-NEUTRAL-RESPITE-HANDOVER-PAGE-GAP-OWNERSHIP-OVERLAY-WAVE-17",
        "status": "GO_FINAL_BYTES_IDENTITY_ACCOUNTING_AND_BOUNDARIES",
        "generated_on": "2026-08-26",
        "pins": {
            "review_checkpoint_commit": AUDIT_HEAD,
            "review_checkpoint_tree": AUDIT_TREE,
            "integration_checkpoint_commit": INTEGRATION_CHECKPOINT,
            "integration_checkpoint_tree": INTEGRATION_CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "app_tree": APP_TREE,
            "routes_tree": ROUTES_TREE,
            "resources_js_tree": RESOURCES_JS_TREE,
            "resources_js_pages_tree": RESOURCES_JS_PAGES_TREE,
            "integration_generator": overlay["pins"]["generator"],
            "integration_generator_sha256": INTEGRATION_GENERATOR_SHA256,
            "integration_output": INPUT_PATHS["overlay"].relative_to(AUDIT_DIR).as_posix(),
            "integration_output_sha256": EXPECTED_INPUT_SHA256["overlay"],
            "materializer": Path(__file__).relative_to(AUDIT_DIR).as_posix(),
            "materializer_sha256": sha256_file(Path(__file__)),
            "inputs": {INPUT_PATHS[name].relative_to(AUDIT_DIR).as_posix(): digest for name, digest in EXPECTED_INPUT_SHA256.items()},
        },
        "reviewers": [
            {"task_path": "/root/run118_verify_final", "verdict": "GO_BYTE_IDENTICAL_REBUILD_AND_EXACT_JOINS", "wrote_files": False},
            {"task_path": "/root/run118_identity_review", "verdict": "GO_641_RECORD_LEDGER_IDENTITY", "wrote_files": False},
            {"task_path": "/root/run117r_verify", "verdict": "GO_ACCOUNTING_AND_ZERO_CREDIT_BOUNDARIES", "wrote_files": False},
        ],
        "decision": {
            "verdict": "GO",
            "mechanical_discrepancies": 0,
            "semantic_discrepancies": 0,
            "source_owner_records": 641,
            "route_owner_records": 288,
            "page_owner_records": 353,
            "static_controller_action_bridges": 76,
            "direct_exact_queue_reviewed": 84,
            "direct_exact_queue_pending": 423,
            "owner_overlay_records_verified": 4,
            "reporting_promotion_authorized": True,
            "matrix_mutation_authorized": False,
            "gate_4_complete": False,
        },
        "independent_identity": independent_identity,
        "conservation": overlay["outcome_conservation"],
        "credit_boundary": {
            "INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING": True,
            "new_source_ownership": False,
            "new_route_ownership": False,
            "new_controller_action_bridge": False,
            "direct_exact_queue_review": False,
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
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-review-wave-17.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/raw-run-118r-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json",
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
        "review_verdict": payload["decision"]["verdict"],
        "source_owner_records": payload["decision"]["source_owner_records"],
        "reporting_promotion_authorized": payload["decision"]["reporting_promotion_authorized"],
        "audit_complete": False,
    }, indent=2))


if __name__ == "__main__":
    main()
