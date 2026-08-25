#!/usr/bin/env python3
"""Materialize the independent RUN-114 owner-only overlay review receipt."""

from __future__ import annotations

import hashlib
import json
import os
import runpy
import subprocess
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_PATH = AUDIT_DIR / "evidence/source/raw-run-114r-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json"
PRODUCER_PATH = AUDIT_DIR / "evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json"
GENERATOR_PATH = AUDIT_DIR / "generators/integrate-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.py"
COHORT_PATH = AUDIT_DIR / "evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json"
COHORT_REVIEW_PATH = AUDIT_DIR / "evidence/source/raw-run-113r-independent-outcome-neutral-name-only-route-action-review-wave-16.json"

AUDIT_HEAD = "6f4c669fd3e963bb875c42c2fc5507d25cc8520a"
AUDIT_TREE = "c21f382c158d92ea307bcf8d83ee1a0487068841"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
GENERATOR_SHA256 = "6cc7f8b3238bd985d3051a6dec969bc46dfcdfd2e6e790e8276a36be285df6e4"
PRODUCER_SHA256 = "cbeb46be682ca0c9ca54012e2c27cf548b54e9cbb75b050fcd61691ca43aaef2"
COHORT_SHA256 = "0849ca5c10e31a4f3e5133a521a570d60b004e723fb17a7f2e812e485f345461"
COHORT_REVIEW_SHA256 = "b52872c02b2a1b41861d9eb735eb363fd06cd1af645e1e6c0965b1b042333a83"
PRODUCER_CHECKPOINT_HEAD = "11691941ebe683a58be84380336e435fe77d31de"
PRODUCER_CHECKPOINT_TREE = "8db4068c70c1e4003b68142d0b9f635211cdd631"
GENERATOR_BLOB_ID = "537eff4f53560edf1d90343e7ed5e91c789beb1d"
PRODUCER_BLOB_ID = "e1561b0d7ad2744a8ac0d4ff72fd5f27ac6b6616"

EXPECTED_COUNTS = {
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
}

EXPECTED_QUEUE = {
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
    assert overlay["pins"]["checkpoint_commit"] == "11691941ebe683a58be84380336e435fe77d31de"
    assert overlay["pins"]["checkpoint_tree"] == "8db4068c70c1e4003b68142d0b9f635211cdd631"

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
    rebuilt_bytes = (json.dumps(rebuilt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    assert rebuilt_bytes == PRODUCER_PATH.read_bytes()
    assert sha256_bytes(rebuilt_bytes) == PRODUCER_SHA256

    candidates = {row["candidate_id"]: row for row in cohort["records"]}
    decisions = {row["candidate_id"]: row for row in review["action_decisions"]}
    owner_ids = {candidate_id for candidate_id, row in decisions.items() if row["outcome"] == "OWNER_ROUTE_ACTION"}
    alias_ids = {candidate_id for candidate_id, row in decisions.items() if row["outcome"] == "ALIAS_OR_REDIRECT"}
    assert owner_ids == {
        f"RUN113-NAME-ONLY-ROUTE-ACTION-{index:02d}"
        for index in range(1, 25)
        if index != 3
    }
    assert alias_ids == {"RUN113-NAME-ONLY-ROUTE-ACTION-03"}
    assert len(candidates) == len(decisions) == 24

    owner_rows = overlay["overlay_source_records"]
    bridges = overlay["new_static_controller_action_bridges"]
    aliases = overlay["reviewed_non_owner_outcomes"]
    assert len(owner_rows) == len(bridges) == 23
    assert len(aliases) == 1
    assert {row["candidate_id"] for row in owner_rows} == owner_ids
    assert {row["candidate_id"] for row in bridges} == owner_ids
    assert {row["candidate_id"] for row in aliases} == alias_ids
    for row in owner_rows:
        assert_row_digest(row, "overlay_row_sha256")
        assert row["static_source_feature_ownership_credit"] is True
        assert row["credit_boundary"]["page_ownership"] is False
        assert row["credit_boundary"]["site_authorization_correctness"] is False
        assert row["credit_boundary"]["permission_correctness"] is False
        assert row["credit_boundary"]["privacy_correctness"] is False
        assert row["credit_boundary"]["direct_object_correctness"] is False
        assert row["credit_boundary"]["lifecycle_correctness"] is False
        assert row["credit_boundary"]["concurrency_correctness"] is False
        assert row["credit_boundary"]["runtime"] is False
        assert row["credit_boundary"]["completion"] is False
    for row in bridges:
        assert_row_digest(row, "bridge_row_sha256")
        assert row["static_controller_action_bridge_credit"] is True
        assert row["page_ownership_credit"] is False
        assert row["runtime_credit"] is False
        assert row["application_browser_credit"] is False
        assert row["executed_test_credit"] is False
        assert row["completion_credit"] is False
    for row in aliases:
        assert_row_digest(row, "reviewed_non_owner_row_sha256")
        assert row["outcome"] == "ALIAS_OR_REDIRECT"
        assert row["route_ownership_credit"] is False
        assert row["controller_action_bridge_credit"] is False
        assert row["page_ownership_credit"] is False
        assert row["downstream_credit"] is False
        assert row["completion_credit"] is False

    identity = overlay["identity"]
    assert identity["owner_candidate_id_list_sha256"] == canonical_list_sha256(owner_ids)
    assert identity["alias_candidate_id_list_sha256"] == canonical_list_sha256(alias_ids)
    assert identity["new_overlay_source_records_sha256"] == canonical_json_sha256(owner_rows)
    assert identity["new_action_bridges_sha256"] == canonical_json_sha256(bridges)
    assert identity["reviewed_non_owner_outcomes_sha256"] == canonical_json_sha256(aliases)
    assert identity["new_overlay_row_sha256_list_sha256"] == canonical_list_sha256([row["overlay_row_sha256"] for row in owner_rows])
    assert identity["new_action_bridge_row_sha256_list_sha256"] == canonical_list_sha256([row["bridge_row_sha256"] for row in bridges])
    assert identity["reviewed_non_owner_row_sha256_list_sha256"] == canonical_list_sha256([row["reviewed_non_owner_row_sha256"] for row in aliases])
    assert identity["new_overlay_source_records_sha256"] == "5ff1732c4456c64ed6dbaac5ba5b36a418d58b3a8d0add681293acfcbac6164d"
    assert identity["new_action_bridges_sha256"] == "3bc76c896df61a1d83995e89fcd63c62888bd0c74a4e6b231ffffd14c7c9a73d"
    assert identity["reviewed_non_owner_outcomes_sha256"] == "c7ae94d6ebf33c42192c0cbf3f2ad62b2f50e75904bdaa3391cc2a3a3026c2fa"

    assert overlay["combined_counts"] == EXPECTED_COUNTS
    assert overlay["queue_accounting"] == EXPECTED_QUEUE
    assert 3929 == 637 + 3292
    assert 637 == 288 + 349
    assert 3218 == 288 + 5 + 4 + 2921
    assert 711 == 349 + 9 + 353
    assert 256 == 61 + 242 - 47
    assert 256 == 234 + 22
    assert 76 == 53 + 23
    assert 507 == 84 + 423
    assert 84 == 77 + 3 + 4
    assert 430 == 423 + 3 + 4
    assert overlay["page_context_boundary"] == {
        "literal_callsites": 7,
        "currently_owned_page_callsites": 3,
        "current_page_evidence_gap_callsites": 4,
        "page_ownership_authorized": 0,
        "rule": "Owned pages remain observation only; four Respite page gaps remain gaps and cannot inherit route ownership.",
    }
    assert overlay["name_only_provenance"] == {
        "identity_relation": "NAME_ONLY",
        "name_only_alone_authorizes_ownership": False,
        "exact_method_resolution_alone_authorizes_ownership": False,
        "ownership_basis": "FRESH_EXACT_CONTROLLER_ACTION_SEMANTIC_REVIEW",
        "backend_candidate_absence_is_negative_proof": False,
    }
    assert overlay["denominator_boundary"]["gate_4_complete"] is False
    assert overlay["audit_completion_test_met"] is False
    assert all(
        overlay["credit_boundary"][key] is False
        for key in (
            "static_page_feature_ownership",
            "frontend_caller_ownership",
            "framework_route_reachability",
            "site_authorization_correctness",
            "permission_correctness",
            "privacy_correctness",
            "direct_object_correctness",
            "lifecycle_correctness",
            "concurrency_correctness",
            "runtime",
            "database",
            "build",
            "application_browser",
            "executed_tests",
            "benchmark",
            "pass",
            "final_finding",
            "completion",
            "audit_complete",
        )
    )

    return {
        "schema_version": "run-114r-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16-v1",
        "run_id": "RUN-114R-INDEPENDENT-REVIEWED-OUTCOME-NEUTRAL-NAME-ONLY-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-16",
        "status": "GO_THREE_PART_OVERLAY_REVIEW_COMPLETE_23_OWNER_1_ALIAS_NAME_ONLY_BOUNDED_ROUTE_ACTION_ONLY",
        "reviewed_on": "2026-08-26",
        "decision": {
            "verdict": "GO",
            "independent_reviews": 3,
            "mechanical_checks_reported": 269,
            "mechanical_discrepancies": 0,
            "semantic_boundary_discrepancies": 0,
            "arithmetic_or_conservation_discrepancies": 0,
            "wording_discrepancies_remaining": 0,
            "route_owner_records_authorized": 23,
            "controller_action_bridges_authorized": 23,
            "reviewed_alias_records_authorized": 1,
            "page_owner_records_authorized": 0,
            "bounded_static_route_feature_ownership_authorized": True,
            "static_controller_action_bridges_authorized": True,
            "reviewed_non_owner_alias_preservation_authorized": True,
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
            "producer_generator": GENERATOR_PATH.relative_to(AUDIT_DIR).as_posix(),
            "producer_generator_sha256": GENERATOR_SHA256,
            "producer": PRODUCER_PATH.relative_to(AUDIT_DIR).as_posix(),
            "producer_sha256": PRODUCER_SHA256,
            "cohort_sha256": COHORT_SHA256,
            "cohort_review_sha256": COHORT_REVIEW_SHA256,
            "materializer": Path(__file__).relative_to(AUDIT_DIR).as_posix(),
            "materializer_sha256": sha256_file(Path(__file__)),
            "inputs": overlay["pins"]["inputs"],
        },
        "review_methods": [
            "A fresh implementation reviewer rebuilt RUN-114 byte-for-byte and independently checked all input pins, row hashes, owner and alias bindings, collision sets, arithmetic, and zero-credit boundaries.",
            "A fresh mechanical reviewer performed 269 read-only checks across candidate, decision, row, source, feature, bridge, queue, page-context, Git-tree, and credit-boundary evidence with zero discrepancies.",
            "The cohort-contract reviewer independently rebuilt the 614-record ancestry, 53-bridge ancestry, 60-key reviewed-queue ancestry, and the exact concurrency-preserving RUN-114 row hashes with zero discrepancies.",
            "All reviewers treated Oblivion Findings as one operating organisation across multiple Sites and did not infer Site, permission, privacy, direct-object, lifecycle, concurrency, runtime, test, benchmark, finding, or completion credit.",
        ],
        "reviewers": [
            {
                "review_id": "RUN114R-IMPLEMENTATION",
                "reviewer_task_path": "/root/run110r_plan",
                "verdict": "GO",
                "discrepancies": 0,
                "verified_scope": [
                    "all 16 input hashes and application-tree pins",
                    "deterministic byte-for-byte producer rebuild",
                    "23 owner rows, 23 bridges, and one alias exclusion",
                    "source, route-id, bridge, and queue collision freedom",
                    "all route, page, feature, queue, and conservation counts",
                    "all downstream credit boundaries false",
                ],
                "audit_artifact_writes": False,
            },
            {
                "review_id": "RUN114R-MECHANICAL",
                "reviewer_task_path": "/root/run111_reporting_verify",
                "verdict": "GO_269_CHECKS_ZERO_DISCREPANCIES",
                "discrepancies": 0,
                "verified_scope": [
                    "all candidate, decision, owner, bridge, and alias self-hashes",
                    "all Git, source-tree, and input pins",
                    "all 38 published identity fields",
                    "all counts, equations, page context, and queue sets",
                    "one-organisation multi-Site and zero downstream boundaries",
                ],
                "audit_artifact_writes": False,
            },
            {
                "review_id": "RUN114R-COHORT-CONTRACT",
                "reviewer_task_path": "/root/run113_route_cohort",
                "verdict": "GO",
                "discrepancies": 0,
                "verified_scope": [
                    "exact concurrency-preserving owner-row hashes",
                    "exact bridge and alias row hashes",
                    "independent owner and queue ancestry reconstruction",
                    "seven page callsites remain context only",
                    "application loci remain unchanged",
                ],
                "audit_artifact_writes": False,
                "incidental_interpreter_cache_removed": True,
            },
        ],
        "verified_combined_counts": EXPECTED_COUNTS,
        "verified_queue_accounting": EXPECTED_QUEUE,
        "verified_conservation": overlay["outcome_conservation"],
        "verified_identity": identity,
        "credit_boundary": overlay["credit_boundary"],
        "mutation_attestation": {
            "reviewers_edited_audit_artifacts": False,
            "persistent_reviewer_workspace_mutation": False,
            "receipt_materialized_by_orchestrator_from_reviewer_returns": True,
            "application_source_changed": False,
            "matrix_changed": False,
            "runtime_or_external_system_changed": False,
        },
        "attestation": "Three fresh read-only reviews reproduce the exact RUN-114 overlay with zero implementation, mechanical, semantic-boundary, wording, or arithmetic discrepancies. Exactly 23 route owners and 23 action bridges are authorized; one reviewed create redirect remains a non-owner; seven page callsites add zero page credit. Gate 4 and the comprehensive audit remain open.",
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-review-wave-16.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/raw-run-114r-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json",
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
        "aliases": payload["decision"]["reviewed_alias_records_authorized"],
        "mechanical_checks": payload["decision"]["mechanical_checks_reported"],
        "reporting_authorized": payload["decision"]["reporting_materialization_authorized"],
        "gate_4_complete": payload["decision"]["gate_4_complete"],
    }, indent=2))


if __name__ == "__main__":
    main()
