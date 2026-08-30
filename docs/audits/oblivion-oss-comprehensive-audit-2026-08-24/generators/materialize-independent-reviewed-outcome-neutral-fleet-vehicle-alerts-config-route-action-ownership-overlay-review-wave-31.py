#!/usr/bin/env python3
"""Materialize three-part post-commit review of the RUN170 ownership overlay."""
from __future__ import annotations

import ast
import hashlib
import json
from pathlib import Path
import subprocess
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT = Path(__file__).resolve().parents[1]
PREFIX = AUDIT.relative_to(REPO).as_posix()
GENERATOR = "generators/materialize-independent-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-review-wave-31.py"
OUTPUT = "evidence/source/current-run-170r-independent-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-review-wave-31.json"
PRODUCER_GENERATOR = "generators/integrate-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.py"
PRODUCER = "evidence/source/current-run-170-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.json"
HEAD = "2084ca83fe8d18f145197867d3bbf73b731800c7"
TREE = "c7da89ed1569b395f7c9124224d8f72e8a7c8e07"
PARENT = "ce81babc43c2077e573214dcb5c9e212e2d0a418"
PARENT_TREE = "5c7c4ef73d64009eac9928f5ef968b05c1e7a74d"
SUBJECT = "docs(audit): integrate vehicle alerts config ownership"
APPLICATION_COMMIT = "e488bd3edcda0f154f87e8bbed972f14db409b82"
APPLICATION_TREE = "9e93b8aea4f4b907cde3dc59dd0520fba5bd7080"
SUBTREES = {
    "app": "b9a9a672bea01473d8be96a0afb548e6291aee9c",
    "routes": "9392e22e4c472610da98977bec4e112092d223b9",
    "resources/js": "776359c5b8b06a55fcf5fe4464bc3e00d01248e5",
    "resources/js/pages": "077d40c746018b655c9b9f8c1ee3f87c2d792a8c",
    "tests": "90886d938c57ab7b45c9301514077d16e4c6b470",
}
PRODUCER_GENERATOR_SHA = "c732926f3112c987fbaaf3f398bc18b3d25027c7f1495c38016237a5cb6f28a3"
PRODUCER_GENERATOR_BLOB = "2603b130a0a674e6803413583c95b51bc3f83545"
PRODUCER_SHA = "c739a36e1975b60d42988be3de36b9fe1ea88cf942752c90112f40ebaa04cd8d"
PRODUCER_BLOB = "8cff90e1e86e5752cbfc3e59d03ccc5423e23ed6"
PRODUCER_SELF_SEAL = "da13b662f3ad154256bb6b1aa59861148fafe83bbdfb394df9efd1b2b77aefa1"
ROW_SEAL = "c03ddbb8a81c3c2801fe5e183f0c2059f1d0c7f4becc5183c7b6e557a0d3fc6f"
BRIDGE_SEAL = "243070f39f236a4cec74053e6c6fea511f6b90ded03db25552a415099e41c07d"
NEW_ROWS_SEAL = "d61d8e61a446f55b7d8d825a822ba0b124c9a4c3bbce2bff3281a59c70c25b2d"
NEW_BRIDGES_SEAL = "f7b8ab1953a947079d279a2cc1344b1cadfd38ed32d02de774b2542389c81274"
INPUT_MAP_SEAL = "def6b220f29ff5255a80d1a5810b5c552dc19010fc680f08bc6352b883ecfe23"

LANE_A = "GO — commit `2084ca83fe8d18f145197867d3bbf73b731800c7` has tree `c7da89ed1569b395f7c9124224d8f72e8a7c8e07`, sole parent `ce81babc43c2077e573214dcb5c9e212e2d0a418` (tree `5c7c4ef73d64009eac9928f5ef968b05c1e7a74d`), the exact requested subject, and exactly two additions: generator 434/0 and receipt 548/0. Generator: SHA-256 `c732926f3112c987fbaaf3f398bc18b3d25027c7f1495c38016237a5cb6f28a3`, blob `2603b130a0a674e6803413583c95b51bc3f83545`, 28,451 bytes/434 lines, UTF-8 LF/no BOM/final LF, AST PASS. Receipt: SHA-256 `c739a36e1975b60d42988be3de36b9fe1ea88cf942752c90112f40ebaa04cd8d`, blob `8cff90e1e86e5752cbfc3e59d03ccc5423e23ed6`, 35,732 bytes/548 lines, exact two-space strict JSON/no duplicate keys, with self-seal `da13b662f3ad154256bb6b1aa59861148fafe83bbdfb394df9efd1b2b77aefa1` recomputing exactly; both working copies match committed bytes. Parent, commit, and application pin `e488bd3edcda0f154f87e8bbed972f14db409b82` preserve subtrees app `b9a9a672bea01473d8be96a0afb548e6291aee9c`, routes `9392e22e4c472610da98977bec4e112092d223b9`, resources/js `776359c5b8b06a55fcf5fe4464bc3e00d01248e5`, resources/js/pages `077d40c746018b655c9b9f8c1ee3f87c2d792a8c`, and tests `90886d938c57ab7b45c9301514077d16e4c6b470`. HEAD, main, and local origin/main align at the commit; tracked/index state is clean; exactly the three later RUN170R/RUN171/RUN172 generator drafts are untracked. The reported second materialization remains byte-identity evidence only and grants no application, test, runtime, browser, benchmark, finding, Gate 4, or completion credit. Discrepancies: 0; no SAFE path inspected and no generator, test, or runtime executed."
LANE_B = "GO — independent read-only lane-B replay of committed RUN170 at `2084ca83fe8d18f145197867d3bbf73b731800c7` (tree `c7da89ed1569b395f7c9124224d8f72e8a7c8e07`, parent `ce81babc43c2077e573214dcb5c9e212e2d0a418`) found zero discrepancies: the exact two-file commit contains generator SHA/blob `c732926f3112c987fbaaf3f398bc18b3d25027c7f1495c38016237a5cb6f28a3`/`2603b130a0a674e6803413583c95b51bc3f83545` and receipt SHA/blob `c739a36e1975b60d42988be3de36b9fe1ea88cf942752c90112f40ebaa04cd8d`/`8cff90e1e86e5752cbfc3e59d03ccc5423e23ed6`; all 13 producer input SHA/blob pairs match at the commit and their map seals to `def6b220f29ff5255a80d1a5810b5c552dc19010fc680f08bc6352b883ecfe23`, with checkpoint `ce81babc…`/`5c7c4ef7…` and application `e488bd3e…`/`9e93b8ae…`; receipt self-seal `da13b662f3ad154256bb6b1aa59861148fafe83bbdfb394df9efd1b2b77aefa1`, candidate/queue seals `7d6b2bddea1f1dce45c8ba3a80feaae4e3efbe5e6b0de022b84ab172dba9a5f1`/`d29353be38d964311d6586311d654c13dc2a39da9b7bcdb8a6a75d69fa511731`, review/synthesis/decision seals `f076f2b8d5ff46345f8d38516cfadc918dea162b6edc28134ed8255040463811`/`d8f2ccbbfd94bddd3c91bd90b81f3b7908f63a0ebe793cdc11a8e34e8dec4a73`/`b8193f59677db15e688363ea92bb45cc4550bfb9a89cfd84d12fb013e329e922`, row/bridge seals `c03ddbb8a81c3c2801fe5e183f0c2059f1d0c7f4becc5183c7b6e557a0d3fc6f`/`243070f39f236a4cec74053e6c6fea511f6b90ded03db25552a415099e41c07d`, and aggregate new-row/new-bridge seals `d61d8e61a446f55b7d8d825a822ba0b124c9a4c3bbce2bff3281a59c70c25b2d`/`f7b8ab1953a947079d279a2cc1344b1cadfd38ed32d02de774b2542389c81274` all recompute exactly; Wave25+RUN149+RUN153 reconstructs `664→665` unique source owners, `307→308` routes with pages fixed at `357`, `95→96` unique bridges, and an unchanged `256`-feature union (`64` route, `242` page, `50` overlap), with source-key seal `93e9ab12…→d691bbfc9eabfa3f34f0df294c24c6890d3082b2149ed8b553cc88747e3143e5` and bridge-key seal `3ad7591c…→19ed2b2cabf56de20dc2ae10b70877536140dc76285c5c64462d71535b302498`; the queue independently reconstructs `118→119` reviewed and `96→97` OWNER (`389→388` pending, `411→410` without ownership), LF-list hashes `2b0612a3…→acfca5e54d64c54334dbd94b30104244b3d2d6722a5426439aec7a8aa62d3ab5`, canonical-JSON hashes `e598ea44…→e85b37e5410c1cc861f9116061e88fb82fdb854e5dc94e56eefe1947b3a7b510`, and next cursor index `84`, `RUN090-ROUTE-0085`/`RUN077-ROUTE-0693`, `fleet-assets.trips.index`, `VehicleController::trips`, seal `928eeec741742f8329dd7e191a71f2d5249775b6de64e6a698a72836345ca011`; `16.925426%` and all conservation equations (`3929=665+3264`, `665=308+357`, `3218=308+12+5+0+2893`, `256=64+242-50`, `507=119+388`, `119=97+10+5+0+7`, `410=388+10+5+0+7`) are exact, and credit is confined to one static route owner plus one controller-action bridge with zero page-owner, new-feature, correctness, runtime, browser, test, benchmark, finding, reporting, Gate 4, or completion credit; no files were edited, no generator/test/application runtime was executed, and no SAFE path was inspected."
LANE_C = "GO — zero discrepancies on committed `2084ca83fe8d18f145197867d3bbf73b731800c7`: the single RUN170 OWNER row and `alertsConfig` bridge derive only from the sealed RUN169R decision; historical queue-route SHA/blob and current e488 SHA/blob provenance are explicitly distinguished with the unchanged statement identified; consumer, caller, service, model, page, and neighboring outcomes are not inherited or recredited; all three observations remain provisional source observations with zero correctness/final-finding credit; the mutation attestation limits RUN170 to its generator and receipt while disclosing three later owned generator drafts; exactly `STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD` and `STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION` are true, while every correctness, Site/permission/privacy, direct-object, runtime, test, browser, benchmark, NCM, finding, feature/audit completion, Gate 4, and audit-complete credit remains false under the single-organisation multi-Site boundary; `artifact_completion_test_met=true` is bounded solely to the completed two-file audit artifact and grants no downstream completion; no SAFE evidence or paths leaked beyond the inherited negative `safe_remediation_paths_inspected=false` attestation; and RUN170 itself grants no reporting credit, so this lane-C GO is only an input to RUN170R—reporting remains unauthorized until the aggregate RUN170R review records GO."


def run(*args: str) -> bytes:
    return subprocess.run(args, cwd=REPO, check=True, capture_output=True).stdout


def git(*args: str) -> str:
    return run("git", *args).decode("utf-8").rstrip("\r\n")


def digest(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def canonical(value: Any) -> str:
    return digest(json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode())


def strict_json(relative: str, pretty: bool = False) -> dict[str, Any]:
    def hook(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, (relative, key)
            result[key] = value
        return result

    raw = (AUDIT / relative).read_bytes()
    assert not raw.startswith(b"\xef\xbb\xbf") and b"\r\n" not in raw and raw.endswith(b"\n")
    value = json.loads(raw, object_pairs_hook=hook)
    assert isinstance(value, dict)
    if pretty:
        assert (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode() == raw
    return value


def sealed(record: dict[str, Any], field: str) -> dict[str, Any]:
    record[field] = canonical(record)
    return record


def artifact(relative: str, commit: str = HEAD) -> dict[str, Any]:
    raw = (AUDIT / relative).read_bytes()
    return {
        "path": f"{PREFIX}/{relative}",
        "sha256": digest(raw),
        "blob_id": git("rev-parse", f"{commit}:{PREFIX}/{relative}"),
        "bytes": len(raw),
        "lines": len(raw.splitlines()),
    }


def review_record(identifier: str, role: str, task_path: str, payload: str, verified: list[str]) -> dict[str, Any]:
    return sealed({
        "review_id": identifier,
        "reviewer_role": role,
        "reviewer_task_path": task_path,
        "independent_from_producer": True,
        "blinded_review": False,
        "nonblinding_reason": "The committed producer artifact and current task context were visible; no blindness is claimed.",
        "delivery_channel": "collaboration_message",
        "raw_payload": payload,
        "raw_payload_sha256": digest(payload.encode()),
        "raw_payload_bytes": len(payload.encode()),
        "raw_payload_lines": len(payload.splitlines()),
        "verbatim_payload_retained": True,
        "review_method": "READ_ONLY_INDEPENDENT_COMMITTED_ARTIFACT_REVIEW_NO_EXECUTION",
        "verified_dimensions": verified,
        "verdict": "GO",
        "discrepancies": 0,
        "reviewer_wrote_files": False,
        "reviewer_executed_generator_tests_or_runtime": False,
        "safe_paths_inspected": False,
        "reporting_authorization_individually_granted": False,
    }, "review_record_sha256")


def validate_producer() -> dict[str, Any]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == HEAD and git("rev-parse", "HEAD^{tree}") == TREE
    assert git("rev-parse", "HEAD^") == PARENT and git("rev-parse", f"{PARENT}^{{tree}}") == PARENT_TREE
    assert git("show", "-s", "--format=%s", HEAD) == SUBJECT
    assert git("rev-parse", "origin/main") == HEAD
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    for path, expected in SUBTREES.items():
        assert git("rev-parse", f"{HEAD}:{path}") == expected
        assert git("rev-parse", f"{PARENT}:{path}") == expected
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{path}") == expected

    expected_paths = {
        f"{PREFIX}/{PRODUCER_GENERATOR}": ("A", "434", "0"),
        f"{PREFIX}/{PRODUCER}": ("A", "548", "0"),
    }
    names = [line.split("\t") for line in git("diff-tree", "--no-commit-id", "--name-status", "-r", HEAD).splitlines()]
    assert {parts[1]: parts[0] for parts in names} == {path: status for path, (status, _, _) in expected_paths.items()}
    numstat = [line.split("\t") for line in git("diff-tree", "--no-commit-id", "--numstat", "-r", HEAD).splitlines()]
    assert {parts[2]: (parts[0], parts[1]) for parts in numstat} == {path: (added, deleted) for path, (_, added, deleted) in expected_paths.items()}

    producer_generator = artifact(PRODUCER_GENERATOR)
    producer = artifact(PRODUCER)
    assert producer_generator == {"path": f"{PREFIX}/{PRODUCER_GENERATOR}", "sha256": PRODUCER_GENERATOR_SHA, "blob_id": PRODUCER_GENERATOR_BLOB, "bytes": 28451, "lines": 434}
    assert producer == {"path": f"{PREFIX}/{PRODUCER}", "sha256": PRODUCER_SHA, "blob_id": PRODUCER_BLOB, "bytes": 35732, "lines": 548}
    ast.parse((AUDIT / PRODUCER_GENERATOR).read_text(encoding="utf-8"))
    receipt = strict_json(PRODUCER, pretty=True)
    without_seal = {key: value for key, value in receipt.items() if key != "self_seal"}
    assert receipt["self_seal"]["sha256"] == canonical(without_seal) == PRODUCER_SELF_SEAL
    assert receipt["pins"]["input_map_sha256"] == canonical(receipt["pins"]["inputs"]) == INPUT_MAP_SEAL
    for relative, expected in receipt["pins"]["inputs"].items():
        assert digest((AUDIT / relative).read_bytes()) == expected
        assert git("rev-parse", f"{HEAD}:{PREFIX}/{relative}") == receipt["pins"]["input_blobs"][relative]

    row, bridge = receipt["overlay_source_records"][0], receipt["new_static_controller_action_bridges"][0]
    assert row["overlay_row_sha256"] == canonical({key: value for key, value in row.items() if key != "overlay_row_sha256"}) == ROW_SEAL
    assert bridge["bridge_row_sha256"] == canonical({key: value for key, value in bridge.items() if key != "bridge_row_sha256"}) == BRIDGE_SEAL
    assert receipt["identity"]["new_overlay_source_records_sha256"] == canonical([row]) == NEW_ROWS_SEAL
    assert receipt["identity"]["new_action_bridges_sha256"] == canonical([bridge]) == NEW_BRIDGES_SEAL
    assert receipt["combined_counts"] == {
        "source_owner_records": 665, "route_owner_records": 308, "page_owner_records": 357,
        "distinct_feature_ids": 256, "distinct_H_feature_ids": 234, "distinct_D_feature_ids": 22,
        "route_distinct_feature_ids": 64, "page_distinct_feature_ids": 242, "route_page_feature_overlap": 50,
        "static_controller_action_bridges": 96, "bounded_static_source_denominator": 3929,
        "bounded_static_source_ownership_percent": "16.925426", "bounded_static_source_residual_records": 3264,
        "residual_explicit_unmapped_routes": 2893, "semantic_shared_routes": 12, "reviewed_alias_routes": 5,
        "reviewed_dead_routes": 0, "evidence_gap_routes_tagged_within_residual": 7,
        "residual_unadjudicated_page_roots": 345, "semantic_shared_page_roots": 9,
        "reviewed_alias_page_roots": 0, "reviewed_dead_page_roots": 0,
        "evidence_gap_page_roots_tagged_within_residual": 1,
    }
    queue = receipt["queue_accounting"]
    assert (queue["reviewed_queue_surface_rows"], queue["owner_queue_surface_rows"], queue["pending_unreviewed_queue_surface_rows"], queue["queue_surfaces_without_ownership"]) == (119, 97, 388, 410)
    assert receipt["queue_boundary"]["next_unresolved_index"] == 84
    assert receipt["queue_boundary"]["next_unresolved_queue_record_sha256"] == "928eeec741742f8329dd7e191a71f2d5249775b6de64e6a698a72836345ca011"
    assert receipt["identity"]["combined_source_record_key_list_sha256"] == "d691bbfc9eabfa3f34f0df294c24c6890d3082b2149ed8b553cc88747e3143e5"
    assert receipt["identity"]["combined_bridge_key_list_sha256"] == "19ed2b2cabf56de20dc2ae10b70877536140dc76285c5c64462d71535b302498"
    assert receipt["identity"]["combined_reviewed_queue_key_list_sha256"] == "acfca5e54d64c54334dbd94b30104244b3d2d6722a5426439aec7a8aa62d3ab5"
    assert receipt["identity"]["canonical_json_reviewed_key_hashes"]["combined"] == "e85b37e5410c1cc861f9116061e88fb82fdb854e5dc94e56eefe1947b3a7b510"
    assert {key for key, value in receipt["credit_boundary"].items() if value} == {"STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD", "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION"}
    assert receipt["audit_completion_test_met"] is False and receipt["completion_boundary"]["audit_complete"] is False
    assert receipt["mutation_attestation"]["run170_producer_scope_contains_only_generator_and_receipt"] is True
    assert receipt["mutation_attestation"]["later_owned_generator_drafts_present_outside_run170_scope"] is True
    assert len(receipt["provisional_assurance_observation_preservation"]["observations"]) == 3
    return receipt


def main() -> None:
    producer = validate_producer()
    reviews = [
        review_record("RUN170R-INDEPENDENT-REVIEW-A", "exact commit and artifact mechanics reviewer", "/root/run167_stale_scan", LANE_A, ["commit_tree_parent_subject", "two_path_diff_and_numstat", "producer_bytes_format_ast_json_self_seal", "application_subtrees", "local_main_origin_alignment", "dirty_scope", "execution_credit_boundary"]),
        review_record("RUN170R-INDEPENDENT-REVIEW-B", "lineage accounting and identity reviewer", "/root/run167_json_review", LANE_B, ["thirteen_input_sha_blob_pairs", "producer_and_embedded_seals", "independent_state_reconstruction", "ownership_and_queue_arithmetic", "both_queue_hash_algorithms", "next_cursor", "credit_conservation"]),
        review_record("RUN170R-INDEPENDENT-REVIEW-C", "semantic credit and noninheritance reviewer", "/root/run167_builder_review", LANE_C, ["run169r_owner_derivation", "historical_current_source_provenance", "consumer_caller_service_model_neighbor_noninheritance", "provisional_observations", "mutation_scope", "single_organisation_multi_site_boundary", "reporting_gate"]),
    ]
    assert all(item["verdict"] == "GO" and item["discrepancies"] == 0 for item in reviews)
    synthesis = sealed({
        "synthesis_id": "RUN170R-THREE-PART-POST-COMMIT-SYNTHESIS",
        "accepted_review_ids": [item["review_id"] for item in reviews],
        "accepted_review_record_sha256s": [item["review_record_sha256"] for item in reviews],
        "independent_reviews": 3,
        "discrepancies": 0,
        "mechanics_go": True,
        "lineage_and_accounting_go": True,
        "semantic_credit_and_noninheritance_go": True,
        "producer_commit_exact_two_path_scope": True,
        "reporting_materialization_authorized": True,
        "new_or_current_or_downstream_credit_authorized": False,
        "correctness_runtime_benchmark_finding_or_completion_credit_authorized": False,
        "gate_4_complete": False,
    }, "synthesis_record_sha256")
    decision = sealed({
        "verdict": "GO",
        "independent_reviews": 3,
        "independently_sealed_review_records": True,
        "accepted_review_record_sha256s": synthesis["accepted_review_record_sha256s"],
        "synthesis_record_sha256": synthesis["synthesis_record_sha256"],
        "discrepancies": 0,
        "reporting_materialization_authorized": True,
        "provisional_source_observation_reporting_authorized": True,
        "new_source_ownership_credit": False,
        "new_route_ownership_credit": False,
        "new_page_ownership_credit": False,
        "new_controller_action_bridge_credit": False,
        "current_overlay_ownership_credit": False,
        "correctness_or_downstream_credit": False,
        "gate_4_complete": False,
        "audit_complete": False,
    }, "decision_record_sha256")
    false_credit = {key: False for key in (
        "new_source_ownership", "new_route_ownership", "new_page_ownership", "new_controller_action_bridge",
        "current_overlay_ownership_credit", "framework_route_reachability", "canonical_object_ownership_correctness",
        "approved_site_scope_correctness", "permission_correctness", "privacy_correctness",
        "direct_object_concealment_correctness", "query_projection_correctness", "runtime", "database",
        "build", "application_browser", "responsive_application", "executed_tests", "benchmark",
        "final_no_match_or_NCM", "ease", "release", "pass", "final_finding", "feature_completion",
        "completion", "gate_4", "audit_complete",
    )}
    payload: dict[str, Any] = {
        "schema_version": "run-170r-independent-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-review-wave-31-v1",
        "run_id": "RUN-170R-INDEPENDENT-REVIEWED-OUTCOME-NEUTRAL-FLEET-VEHICLE-ALERTS-CONFIG-ROUTE-ACTION-OWNERSHIP-OVERLAY-REVIEW-WAVE-31",
        "status": "GO_THREE_PART_POST_COMMIT_REVIEW_COMPLETE_REPORTING_ONLY_ZERO_NEW_OR_DOWNSTREAM_CREDIT",
        "reviewed_on": "2026-08-30",
        "pins": {
            "producer_commit": HEAD,
            "producer_tree": TREE,
            "producer_parent": PARENT,
            "producer_parent_tree": PARENT_TREE,
            "producer_subject": SUBJECT,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "subtrees": SUBTREES,
            "producer_generator": artifact(PRODUCER_GENERATOR),
            "producer": artifact(PRODUCER),
            "producer_self_seal_sha256": PRODUCER_SELF_SEAL,
            "materializer": f"{PREFIX}/{GENERATOR}",
            "materializer_sha256": digest((AUDIT / GENERATOR).read_bytes()),
            "materializer_blob_id": git("hash-object", "--", str(AUDIT / GENERATOR)),
            "producer_inputs": producer["pins"]["inputs"],
            "producer_input_blobs": producer["pins"]["input_blobs"],
            "producer_input_map_sha256": producer["pins"]["input_map_sha256"],
        },
        "architecture_rule": producer["architecture_rule"],
        "methods": {"independent_reviews": 3, "synthesizers": 1, "committed_artifact_only": True, "producer_generator_executed_by_reviewers": False, "application_executed": False, "tests_executed": False, "database_used": False, "build_used": False, "browser_used": False, "safe_paths_inspected": False},
        "producer_scope": {"changed_paths": [f"{PREFIX}/{PRODUCER_GENERATOR}", f"{PREFIX}/{PRODUCER}"], "changed_path_count": 2, "added_lines": 982, "deleted_lines": 0, "generator_numstat": "434/0", "receipt_numstat": "548/0", "working_copies_match_committed_blobs": True, "application_subtrees_unchanged": True},
        "independent_review_records": reviews,
        "synthesis_review": synthesis,
        "decision": decision,
        "producer_snapshot": {"status": producer["status"], "combined_counts": producer["combined_counts"], "queue_accounting": producer["queue_accounting"], "queue_boundary": producer["queue_boundary"], "identity": producer["identity"], "overlay_row_sha256": ROW_SEAL, "bridge_row_sha256": BRIDGE_SEAL, "provisional_assurance_observation_count": 3},
        "publication_boundary": {"local_main_equals_producer_commit": True, "local_origin_main_equals_producer_commit": True, "remote_refetch_after_push_performed": False, "local_remote_tracking_alignment_only": True, "application_fix_published_or_merged": False},
        "credit_boundary": {"INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING": True, **false_credit},
        "completion_boundary": producer["completion_boundary"],
        "artifact_completion_test_met": True,
        "reporting_materialization_authorized": True,
        "audit_completion_test_met": False,
        "wrote_files": [f"{PREFIX}/{GENERATOR}", f"{PREFIX}/{OUTPUT}"],
    }
    payload["self_seal"] = {"algorithm": "sha256-canonical-json-with-self-seal-omitted", "sha256": canonical(payload)}
    (AUDIT / OUTPUT).write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")
    parsed = strict_json(OUTPUT, pretty=True)
    seal = parsed.pop("self_seal")
    assert seal["sha256"] == canonical(parsed)
    assert not git("status", "--porcelain", "--untracked-files=no")
    expected_untracked = {
        f"{PREFIX}/{GENERATOR}", f"{PREFIX}/{OUTPUT}",
        f"{PREFIX}/generators/materialize-run-171-reviewed-fleet-vehicle-alerts-config-route-action-reporting-wave-31.py",
        f"{PREFIX}/generators/materialize-run-172-audit-dashboard-verification-wave-31.py",
    }
    actual_untracked = {line[3:] for line in git("status", "--porcelain").splitlines() if line.startswith("?? ")}
    assert actual_untracked == expected_untracked, (actual_untracked, expected_untracked)
    assert not list(AUDIT.rglob("__pycache__"))
    print(json.dumps({
        "status": payload["status"],
        "materializer_sha256": payload["pins"]["materializer_sha256"],
        "receipt_sha256": digest((AUDIT / OUTPUT).read_bytes()),
        "review_seals": [item["review_record_sha256"] for item in reviews],
        "synthesis_seal": synthesis["synthesis_record_sha256"],
        "decision_seal": decision["decision_record_sha256"],
        "self_seal": payload["self_seal"]["sha256"],
        "reporting_materialization_authorized": True,
    }, indent=2))


if __name__ == "__main__":
    main()
