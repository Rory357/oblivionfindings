#!/usr/bin/env python3
"""Materialize the independent RUN-102 owner-only overlay review receipt."""

from __future__ import annotations

import hashlib
import json
import os
import subprocess
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_PATH = AUDIT_DIR / "evidence/source/raw-run-102r-independent-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json"
PRODUCER_PATH = AUDIT_DIR / "evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json"
GENERATOR_PATH = AUDIT_DIR / "generators/integrate-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.py"
COHORT_PATH = AUDIT_DIR / "evidence/source/root-run-101-outcome-neutral-route-action-cohort-wave-13.json"
COHORT_REVIEW_PATH = AUDIT_DIR / "evidence/source/raw-run-101r-independent-outcome-neutral-route-action-review-wave-13.json"

AUDIT_HEAD = "a6e6add624a42cd49715709ea310a8484c4903b6"
AUDIT_TREE = "59a7684269e46592de73d95540c6d7fa5fd18c2c"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
GENERATOR_SHA256 = "648f5bc57cde303568c99a6f9acaf608023a0ef6e17a891eb478553f85b7a9ce"
PRODUCER_SHA256 = "cf68900351832c790ec53b4996daaf640c604c498f902c85dec681113bf492dd"
COHORT_SHA256 = "3a8f4c3f11668406f34db7e50ae561fe1c6516e7002eb7e8271851e62c3ff655"
COHORT_REVIEW_SHA256 = "518321096f6a483321e3ad129f730db4b628cb70a74e1dbec4149b08c9b09eba"


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

    candidates = {row["candidate_id"]: row for row in cohort["records"]}
    decisions = {row["candidate_id"]: row for row in review["action_decisions"]}
    owner_ids = {candidate_id for candidate_id, row in decisions.items() if row["outcome"] == "OWNER_ROUTE_ACTION"}
    alias_ids = {candidate_id for candidate_id, row in decisions.items() if row["outcome"] == "ALIAS_OR_REDIRECT"}
    assert owner_ids == {f"RUN101-ROUTE-ACTION-{index:02d}" for index in range(4, 25)}
    assert alias_ids == {f"RUN101-ROUTE-ACTION-{index:02d}" for index in range(1, 4)}
    assert len(candidates) == len(decisions) == 24

    owner_rows = overlay["overlay_source_records"]
    bridges = overlay["new_static_controller_action_bridges"]
    aliases = overlay["reviewed_non_owner_outcomes"]
    assert len(owner_rows) == len(bridges) == 21
    assert len(aliases) == 3
    assert {row["candidate_id"] for row in owner_rows} == owner_ids
    assert {row["candidate_id"] for row in bridges} == owner_ids
    assert {row["candidate_id"] for row in aliases} == alias_ids
    for row in owner_rows:
        assert_row_digest(row, "overlay_row_sha256")
        assert row["static_source_feature_ownership_credit"] is True
        assert row["credit_boundary"]["page_ownership"] is False
        assert row["credit_boundary"]["frontend_caller_ownership"] is False
        assert row["credit_boundary"]["direct_object_correctness"] is False
    for row in bridges:
        assert_row_digest(row, "bridge_row_sha256")
        assert row["static_controller_action_bridge_credit"] is True
        assert row["page_ownership_credit"] is False
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

    expected_counts = {
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
    }
    expected_queue = {
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
    assert overlay["combined_counts"] == expected_counts
    assert overlay["queue_accounting"] == expected_queue
    assert 3929 == 592 + 3337
    assert 592 == 265 + 327
    assert 3218 == 265 + 5 + 3 + 2945
    assert 711 == 327 + 382 + 2
    assert 249 == 59 + 234 - 44
    assert 249 == 229 + 20
    assert 507 == 59 + 448
    assert 59 == 54 + 2 + 3
    assert 453 == 448 + 2 + 3
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
            "runtime",
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
        "schema_version": "run-102r-independent-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13-v1",
        "run_id": "RUN-102R-INDEPENDENT-REVIEWED-OUTCOME-NEUTRAL-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-13",
        "status": "GO_THREE_PART_OVERLAY_REVIEW_COMPLETE_21_OWNER_3_ALIAS_BOUNDED_ROUTE_ACTION_ONLY",
        "reviewed_on": "2026-08-25",
        "decision": {
            "verdict": "GO",
            "independent_reviews": 3,
            "mechanical_discrepancies": 0,
            "semantic_boundary_discrepancies": 0,
            "arithmetic_or_conservation_discrepancies": 0,
            "wording_discrepancies_remaining": 0,
            "route_owner_records_authorized": 21,
            "controller_action_bridges_authorized": 21,
            "reviewed_alias_records_authorized": 3,
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
            "A fresh mechanical reviewer reconstructed the final-byte producer chain, all input and tree pins, 24 candidate and decision hashes, 21 owner rows, 21 bridges, three non-owner aliases, all aggregate identities, collision exclusions, and conservation equations.",
            "A fresh semantic credit red-team traced all decisive action classes and source files, corrected three bounded wording imprecisions plus one IT-wrapper note, and confirmed the stable regenerated bytes with no remaining discrepancy.",
            "A fresh reporting-contract reviewer independently reconciled the current counts and zero-credit boundaries and authorized reporting only after this pinned GO receipt exists.",
            "All reviewers were read-only and treated Oblivion Findings as one operating organisation across multiple Sites with Site, role/permission, canonical ownership, direct-object denial, privacy, and lifecycle correctness left as separate unproved gates.",
        ],
        "reviewers": [
            {
                "review_id": "RUN102R-MECHANICAL",
                "reviewer_task_path": "/root/run102r_overlay_mechanics",
                "verdict": "GO",
                "discrepancies": 0,
                "verified_scope": [
                    "stable final producer chain hashes",
                    "all input and application tree pins",
                    "24 candidate and decision self-hashes",
                    "21 owner rows and 21 action bridges",
                    "three aliases excluded from owner and bridge arrays",
                    "all row and aggregate identity hashes",
                    "collision exclusions and conservation equations",
                ],
                "wrote_files": False,
                "write_scope": [],
            },
            {
                "review_id": "RUN102R-SEMANTIC-CREDIT-REDTEAM",
                "reviewer_task_path": "/root/run102r_credit_redteam",
                "verdict": "GO",
                "discrepancies": 0,
                "verified_scope": [
                    "all 21 OWNER decisions and three ALIAS decisions",
                    "recipe edit and dietary-tag JSON action semantics",
                    "notification callers and no page inheritance",
                    "security export distinctions",
                    "four IT lifecycle wrappers plus two direct delegations",
                    "all downstream credit boundaries false",
                ],
                "wrote_files": False,
                "write_scope": [],
            },
            {
                "review_id": "RUN102R-REPORTING-CONTRACT",
                "reviewer_task_path": "/root/run102r_reporting_contract",
                "verdict": "GO_REPORTING_AFTER_PINNED_RUN102R_RECEIPT",
                "discrepancies": 0,
                "verified_scope": [
                    "authoritative Wave 13 counts",
                    "owner/shared/alias/pending queue arithmetic",
                    "route and page conservation",
                    "Gate 4, matrix, mapping, and completion remain open or zero",
                    "minimal deterministic RUN103 reporting contract",
                ],
                "wrote_files": False,
                "write_scope": [],
            },
        ],
        "verified_combined_counts": expected_counts,
        "verified_queue_accounting": expected_queue,
        "verified_conservation": {
            "source_owner_partition": "592 = 265 + 327",
            "bounded_source_universe": "3929 = 592 + 3337",
            "bounded_route_like_universe": "3218 = 265 + 5 shared + 3 alias + 2945 residual",
            "literal_page_root_universe": "711 = 327 + 382 + 2",
            "feature_union": "249 = 59 + 234 - 44",
            "feature_classes": "249 = 229 + 20",
            "controller_action_bridges": "53 = 32 + 21",
            "queue_review_state": "507 = 59 + 448",
            "queue_reviewed_state": "59 = 54 + 2 + 3",
            "queue_without_ownership": "453 = 448 + 2 + 3",
        },
        "verified_identity": identity,
        "credit_boundary": overlay["credit_boundary"],
        "mutation_attestation": {
            "review_execution_wrote_files": False,
            "reviewer_write_scopes_empty": True,
            "receipt_materialized_by_orchestrator_from_reviewer_returns": True,
            "application_source_changed": False,
            "matrix_changed": False,
            "runtime_or_external_system_changed": False,
        },
        "attestation": "Three fresh read-only reviews reproduce the exact final RUN-102 overlay with zero mechanical, semantic-boundary, wording, or arithmetic discrepancies. Exactly 21 route owners and 21 action bridges are authorized; three reviewed recipe redirects remain non-owners; zero page rows or downstream credit are authorized. Gate 4 and the comprehensive audit remain open.",
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-independent-reviewed-outcome-neutral-route-action-ownership-overlay-review-wave-13.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/raw-run-102r-independent-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json",
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
        "reporting_authorized": payload["decision"]["reporting_materialization_authorized"],
        "gate_4_complete": payload["decision"]["gate_4_complete"],
    }, indent=2))


if __name__ == "__main__":
    main()
