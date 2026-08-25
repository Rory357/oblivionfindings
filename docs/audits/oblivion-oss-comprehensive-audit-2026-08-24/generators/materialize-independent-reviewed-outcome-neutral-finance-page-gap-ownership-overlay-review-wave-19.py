#!/usr/bin/env python3
"""Materialize independent final-byte and boundary review of RUN-126.

This receipt verifies the four-page owner-only overlay, its Manual-Journal
semantic correction, and cumulative bounded accounting. It adds no ownership
or downstream readiness credit itself.
"""

from __future__ import annotations

import hashlib
import json
import subprocess
from collections import Counter
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_PATH = AUDIT_DIR / "evidence/source/raw-run-126r-independent-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json"

AUDIT_HEAD = "5c5f582840660f3303d5d9e29fc73b912dbd8dee"
AUDIT_TREE = "de206125abe8f7e096e0fa98b5d8e8dab97c9acc"
INTEGRATION_CHECKPOINT = "dcd5c9185729f3b824125220bad3c0f2b3688116"
INTEGRATION_CHECKPOINT_TREE = "c06e43135e99bc2ee1b49ca86c98295dc8645e05"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
INTEGRATION_GENERATOR_SHA256 = "36f3afd3a3bf9cf1b20789b4a6ca762ad55409d769870f19ff100466d1c6fccc"

INPUT_SHA256 = {
    "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json": "bfec52a9bed7176045457e8920bdc7b65e1c1d5c5eeb28a266f49c12acf69bcf",
    "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json": "5390e55d9e47d1845afa8b3848bdab7687747cbb62c946dd4d66d3c435e8de0b",
    "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json": "7f76c9cfeae906a64c92013fbf9a12cf180d39a315bfa13e778b6332365cdd2a",
    "evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json": "cf68900351832c790ec53b4996daaf640c604c498f902c85dec681113bf492dd",
    "evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json": "9c1600d2365c3527e185f313b7cb1586705f39cefb15d5d212dc708b45b630ea",
    "evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json": "9375fb374b589c5068334795fa03d80a7ba1fd35695808f99c9e2591e886efca",
    "evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json": "cbeb46be682ca0c9ca54012e2c27cf548b54e9cbb75b050fcd61691ca43aaef2",
    "evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json": "33dd2f8ffa2b35c6651a6bf18923872e70ba5c2f91fce8b7222666bb8a91fc8b",
    "evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json": "d7aee21e7c4230b44707a22b7fa93478a84e9a5b4775ecd25aaffede764855ca",
    "evidence/source/raw-run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json": "2130e3801b6ac163580bc56f23d6647136c83fdadc8ea65804b1559d36b29484",
    "evidence/source/root-run-125-outcome-neutral-finance-page-gap-cohort-wave-19.json": "7d0df6edfacb63a9a7ab64140d47b2570a617db0147e4b0be6d5317fe38e3d92",
    "evidence/source/raw-run-125r-independent-outcome-neutral-finance-page-gap-review-wave-19.json": "b26d70eeee965d7dcbbf8e3e439f54bd35b5ab7fa1dfbf7a26c278cc59bb6c73",
    "evidence/source/current-run-126-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json": "15ab65b479daa7e7c3f2f3fbd979a13ead87dfbedf31c163a27b5eb809b12f10",
}

BASELINE_RELATIVE = "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json"
OVERLAY_RELATIVES = [
    "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json",
    "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json",
    "evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json",
    "evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json",
    "evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json",
    "evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json",
    "evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json",
    "evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json",
]
CURRENT_BASELINE_RELATIVE = OVERLAY_RELATIVES[-1]
CURRENT_BASELINE_REVIEW_RELATIVE = "evidence/source/raw-run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json"
COHORT_RELATIVE = "evidence/source/root-run-125-outcome-neutral-finance-page-gap-cohort-wave-19.json"
SEMANTIC_REVIEW_RELATIVE = "evidence/source/raw-run-125r-independent-outcome-neutral-finance-page-gap-review-wave-19.json"
INTEGRATION_RELATIVE = "evidence/source/current-run-126-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json"


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def canonical_json_sha256(value: Any) -> str:
    raw = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(raw).hexdigest()


def canonical_list_sha256(values: list[str] | set[str]) -> str:
    return canonical_json_sha256(sorted(values))


def load(relative: str) -> dict[str, Any]:
    value = json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))
    assert isinstance(value, dict)
    return value


def git(*args: str) -> str:
    return subprocess.run(["git", *args], cwd=REPO, check=True, capture_output=True, text=True, encoding="utf-8").stdout.strip()


def assert_workspace_and_inputs(integration: dict[str, Any]) -> None:
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
    for relative, digest in INPUT_SHA256.items():
        assert sha256_file(AUDIT_DIR / relative) == digest, relative
    generator = AUDIT_DIR / integration["pins"]["generator"]
    assert sha256_file(generator) == INTEGRATION_GENERATOR_SHA256
    assert integration["pins"]["generator_sha256"] == INTEGRATION_GENERATOR_SHA256
    assert integration["pins"]["checkpoint_commit"] == INTEGRATION_CHECKPOINT
    assert integration["pins"]["checkpoint_tree"] == INTEGRATION_CHECKPOINT_TREE


def build() -> dict[str, Any]:
    baseline = load(BASELINE_RELATIVE)
    overlays = [load(relative) for relative in OVERLAY_RELATIVES]
    current_baseline = overlays[-1]
    current_baseline_review = load(CURRENT_BASELINE_REVIEW_RELATIVE)
    cohort = load(COHORT_RELATIVE)
    semantic_review = load(SEMANTIC_REVIEW_RELATIVE)
    integration = load(INTEGRATION_RELATIVE)
    assert_workspace_and_inputs(integration)

    assert current_baseline_review["decision"]["verdict"] == "GO"
    assert semantic_review["decision"]["verdict"] == "GO_4_EXPLICIT_OWNER_PAGE"
    assert integration["status"] == "FOUR_REVIEWED_FINANCE_PAGE_OWNERS_INTEGRATED_BOUNDED_STATIC_ONLY"
    reviewed = integration["reviewed_overlay"]
    assert reviewed["reviewed_pages"] == reviewed["owner_pages"] == reviewed["accepted_page_owner_records"] == 4
    assert reviewed["accepted_route_owner_records"] == 0
    assert reviewed["accepted_controller_action_bridges"] == 0
    assert reviewed["direct_queue_rows_reconciled"] == 0
    assert reviewed["journal_route_gap_resolved"] is False
    assert reviewed["matrix_mutation"] is False

    candidates = {row["candidate_id"]: row for row in cohort["records"]}
    decisions = {row["candidate_id"]: row for row in semantic_review["page_decisions"]}
    rows = integration["overlay_source_records"]
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
        assert row["source"] == candidate["page_source"]
        assert row["feature_id"] == candidate["candidate_feature_id"] == decision["candidate_feature_id"]
        assert row["parent_candidate_id"] == candidate["reviewed_parent_action_provenance"]["parent_candidate_id"]
        assert row["parent_route_record_id"] == candidate["reviewed_parent_action_provenance"]["route_record_id"]
        assert row["parent_outcome"] == candidate["reviewed_parent_action_provenance"]["parent_outcome"]
        assert row["render_source_anchor"] == decision["render_source_anchor"]
        assert row["static_source_feature_ownership_credit"] is True
        assert not any(row["credit_boundary"].values())

    assert Counter(row["feature_id"] for row in rows) == {
        "CAP-FIN-CHART-OF-ACCOUNTS": 3,
        "CAP-FIN-MANUAL-JOURNAL-LIFECYCLE": 1,
    }
    journal = next(row for row in rows if row["feature_id"] == "CAP-FIN-MANUAL-JOURNAL-LIFECYCLE")
    non_owner = {row["candidate_id"]: row for row in current_baseline["reviewed_non_owner_outcomes"]}
    assert journal["parent_outcome"] == "EVIDENCE_GAP"
    assert journal["parent_projected_feature_id"] == "CAP-FIN-CHART-OF-ACCOUNTS"
    assert journal["semantic_feature_differs_from_parent_projection"] is True
    assert non_owner[journal["parent_candidate_id"]]["outcome"] == "EVIDENCE_GAP"

    prior_records = list(baseline["records"])
    for overlay in overlays:
        prior_records.extend(overlay["overlay_source_records"])
    combined = prior_records + rows
    prior_ids = {row["source_record_id"] for row in prior_records}
    prior_keys = {row["source_record_key"] for row in prior_records}
    ids = {row["source_record_id"] for row in combined}
    keys = {row["source_record_key"] for row in combined}
    route_rows = [row for row in combined if row["surface"] == "ROUTE_SOURCE_RECORD"]
    page_rows = [row for row in combined if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"]
    assert len(prior_records) == len(prior_ids) == len(prior_keys) == 648
    assert len(combined) == len(ids) == len(keys) == 652
    assert len(route_rows) == 295
    assert len(page_rows) == 357
    feature_classes: dict[str, str] = {}
    for row in combined:
        feature_classes.setdefault(row["feature_id"], row["feature_class"])
        assert feature_classes[row["feature_id"]] == row["feature_class"]
    assert len(feature_classes) == 256
    assert Counter(feature_classes.values()) == {"H": 234, "D": 22}
    route_features = {row["feature_id"] for row in route_rows}
    page_features = {row["feature_id"] for row in page_rows}
    assert [len(route_features), len(page_features), len(route_features & page_features)] == [62, 242, 48]

    counts = integration["combined_counts"]
    assert counts["source_owner_records"] == 652
    assert counts["route_owner_records"] == 295
    assert counts["page_owner_records"] == 357
    assert counts["bounded_static_source_residual_records"] == 3277
    assert counts["bounded_static_source_ownership_percent"] == "16.594553"
    assert counts["residual_explicit_unmapped_routes"] == 2906
    assert counts["semantic_shared_routes"] == 12
    assert counts["reviewed_alias_routes"] == 5
    assert counts["evidence_gap_routes_tagged_within_residual"] == 7
    assert counts["residual_unadjudicated_page_roots"] == 345
    assert counts["semantic_shared_page_roots"] == 9
    assert counts["evidence_gap_page_roots_tagged_within_residual"] == 1
    assert integration["queue_accounting"] == {
        "direct_exact_queue_records": 507,
        "reviewed_queue_surface_rows": 106,
        "owner_queue_surface_rows": 84,
        "shared_queue_surface_rows": 10,
        "alias_queue_surface_rows": 5,
        "dead_queue_surface_rows": 0,
        "evidence_gap_queue_surface_rows": 7,
        "pending_unreviewed_queue_surface_rows": 401,
        "queue_surfaces_without_ownership": 423,
        "new_reviewed_route_surface_rows": 0,
        "new_owner_route_surface_rows": 0,
        "new_shared_route_surface_rows": 0,
        "new_alias_route_surface_rows": 0,
        "new_evidence_gap_route_surface_rows": 0,
        "wholesale_queue_ownership_authorized": False,
        "new_reviewed_page_surface_rows": 0,
        "new_owner_page_surface_rows": 0,
    }
    assert integration["new_static_controller_action_bridges"] == []
    assert integration["reviewed_non_owner_outcomes"] == current_baseline["reviewed_non_owner_outcomes"]
    assert integration["credit_boundary"]["STATIC_PAGE_FEATURE_OWNERSHIP_FOR_4_RECORDS"] is True
    assert not any(value for key, value in integration["credit_boundary"].items() if key != "STATIC_PAGE_FEATURE_OWNERSHIP_FOR_4_RECORDS")
    assert integration["audit_completion_test_met"] is False

    independent_identity = {
        "combined_source_record_key_list_sha256": canonical_list_sha256(keys),
        "combined_source_record_id_list_sha256": canonical_list_sha256(ids),
        "combined_feature_id_list_sha256": canonical_list_sha256(set(feature_classes)),
        "combined_route_feature_id_list_sha256": canonical_list_sha256(route_features),
        "combined_page_feature_id_list_sha256": canonical_list_sha256(page_features),
        "combined_route_page_overlap_feature_id_list_sha256": canonical_list_sha256(route_features & page_features),
        "overlay_row_sha256_list_sha256": canonical_list_sha256([row["overlay_row_sha256"] for row in rows]),
        "overlay_records_sha256": canonical_json_sha256(rows),
        "preserved_non_owner_records_sha256": canonical_json_sha256(integration["reviewed_non_owner_outcomes"]),
    }
    key_map = {
        "overlay_row_sha256_list_sha256": "new_overlay_row_sha256_list_sha256",
        "overlay_records_sha256": "new_overlay_source_records_sha256",
    }
    for key, value in independent_identity.items():
        if key == "preserved_non_owner_records_sha256":
            continue
        assert integration["identity"][key_map.get(key, key)] == value

    return {
        "schema_version": "1.0.0",
        "run_id": "RUN-126R-INDEPENDENT-REVIEWED-OUTCOME-NEUTRAL-FINANCE-PAGE-GAP-OWNERSHIP-OVERLAY-WAVE-19",
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
            "integration_generator": integration["pins"]["generator"],
            "integration_generator_sha256": INTEGRATION_GENERATOR_SHA256,
            "integration_output": INTEGRATION_RELATIVE,
            "integration_output_sha256": INPUT_SHA256[INTEGRATION_RELATIVE],
            "materializer": Path(__file__).relative_to(AUDIT_DIR).as_posix(),
            "materializer_sha256": sha256_file(Path(__file__)),
            "inputs": INPUT_SHA256,
        },
        "reviewers": [
            {"task_path": "/root/run125_accounts_create", "verdict": "GO_CREATE_ROW_IDENTITY_ACCOUNTING_AND_BOUNDARIES", "wrote_files": False},
            {"task_path": "/root/run125_accounts_show_edit", "verdict": "GO_SHOW_EDIT_ROWS_AND_21_IDENTITIES", "wrote_files": False},
            {"task_path": "/root/run125_journal_reporting", "verdict": "GO_JOURNAL_REPAIR_ACCOUNTING_AND_ZERO_CREDIT", "wrote_files": False},
        ],
        "decision": {
            "verdict": "GO",
            "mechanical_discrepancies": 0,
            "semantic_discrepancies": 0,
            "source_owner_records": 652,
            "route_owner_records": 295,
            "page_owner_records": 357,
            "static_controller_action_bridges": 83,
            "direct_exact_queue_reviewed": 106,
            "direct_exact_queue_pending": 401,
            "owner_overlay_records_verified": 4,
            "journal_parent_route_gap_preserved": True,
            "matrix_mutation_authorized": False,
            "reporting_promotion_authorized": True,
            "gate_4_complete": False,
        },
        "independent_identity": independent_identity,
        "conservation": integration["outcome_conservation"],
        "credit_boundary": {
            "INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING": True,
            "new_source_ownership": False,
            "new_route_ownership": False,
            "new_controller_action_bridge": False,
            "direct_exact_queue_review": False,
            "journal_route_gap_resolved": False,
            "matrix_mutation": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "privacy_correctness": False,
            "direct_object_correctness": False,
            "ledger_or_lifecycle_correctness": False,
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
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-independent-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-review-wave-19.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/raw-run-126r-independent-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json",
        ],
    }


def main() -> None:
    payload = build()
    encoded = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    if not OUTPUT_PATH.exists() or OUTPUT_PATH.read_bytes() != encoded:
        OUTPUT_PATH.write_bytes(encoded)
    print(json.dumps({
        "status": payload["status"],
        "output": OUTPUT_PATH.relative_to(AUDIT_DIR).as_posix(),
        "sha256": hashlib.sha256(encoded).hexdigest(),
        "review_verdict": payload["decision"]["verdict"],
        "source_owner_records": payload["decision"]["source_owner_records"],
        "reporting_promotion_authorized": payload["decision"]["reporting_promotion_authorized"],
        "audit_complete": False,
    }, indent=2))


if __name__ == "__main__":
    main()
