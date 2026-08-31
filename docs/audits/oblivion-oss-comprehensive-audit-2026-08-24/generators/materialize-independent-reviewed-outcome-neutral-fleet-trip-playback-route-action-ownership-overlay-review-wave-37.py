#!/usr/bin/env python3
"""Materialize the independent two-lane post-commit review of RUN190."""
from __future__ import annotations

import ast
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
from typing import Any


sys.dont_write_bytecode = True
REPO = Path(__file__).resolve().parents[4]
AUDIT = Path(__file__).resolve().parents[1]
PREFIX = AUDIT.relative_to(REPO).as_posix()

GENERATOR = "generators/materialize-independent-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-review-wave-37.py"
OUTPUT = "evidence/source/current-run-190r-independent-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-review-wave-37.json"
PRODUCER_GENERATOR = "generators/integrate-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-wave-37.py"
PRODUCER = "evidence/source/current-run-190-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-wave-37.json"

HEAD = "8b4e5acbbc75db6ea2b966b0cd8d82beff2b4213"
TREE = "9f91a6044b58354340385cd2120b4bfc10fda62a"
PARENT = "e32dcf40d2c4e648464d73524704c3025ffe0651"
PARENT_TREE = "d3bd0be17614790e2bf971d848ff6228b067b173"
SUBJECT = "audit: integrate RUN190 playback ownership"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"

PRODUCER_GENERATOR_SHA = "0115154b4472f96977f0d82c286943af7b687240cd23f997d2d5e0a590e18599"
PRODUCER_GENERATOR_BLOB = "2dc37fc5126f484254b1bb03b7c35be357772cdb"
PRODUCER_SHA = "88494bb887c78f488df3915c86a8ad47b2176da469aedda3803151b8edd4a708"
PRODUCER_BLOB = "c4da9be0db2e179bbdaacb19a814165d4ff28f0c"
PRODUCER_SELF_SEAL = "16cbb874448ec053f976594cfe031ed1834601d66c8b1ffe7bb79a06336d4142"
INPUT_MAP_SEAL = "fc9d3dc1cb8677cd1d3b027d8342263ec06bac843d8a16b0ce90c99aa4e0864c"
ROW_SEAL = "31a2f128dacd47d73377db8422e2d89448909d9f4d98fe8089fa0522cb0ddfb2"
BRIDGE_SEAL = "a8934922a42c1270c62276c2dc345066372a8ea73fa3ca0875cd3c75020fc5c9"
SEMANTIC_REVIEW_SEALS = [
    "0247add20542aa4578ca20747796e01d6528b0b7b01fb93486ccba09253d728d",
    "469fa03b2a408f95aca7636fae7a4ab0a16ff53cf38c69db7584a6e9d164cd17",
]
FINAL_ARTIFACT_REVIEW_SEALS = [
    "d67eef3feefadecaaef269d22398879dc04cb550d275fa0a2c8d7a7b8520bf0b",
    "2b117150aa4b3152a0f576536210e082f15f91414f1ede48dc8d5c5804167009",
]
SEMANTIC_CONSENSUS_SEAL = "d9f89cb1c19d9894150d1df63fdd85482cdcf0a66fb2fe37297f2757aa810227"
RUN190_DECISION_SEAL = "b3456ba2d566a616dbd314c50c4b9a4f8ed019193ecb60076e668cc046d27a7f"
PRIOR_SOURCE_IDENTITY = "1648a470ca0293c4c065b30925b8eda5a9f78d35fa64935e644a3354e17cdbba"
COMBINED_SOURCE_IDENTITY = "2e5f82279c71860a6fc2576859fb4351a6e3fbd3010f7c9f2fe598b48facf5a6"
PRIOR_BRIDGE_IDENTITY = "6ab1b8c1045ac6c159ba4aa5856ac58e648263a530f4f7c3031e4eed5d84fa32"
COMBINED_BRIDGE_IDENTITY = "354fd9239de4233eff3e1b20b7df5c2c519e11c8a90b88490cab9513e9f1b42c"
PRIOR_REVIEWED_IDENTITY = "5dbcecd3986300fe255fdb75efe6013c07f3adc4071745ebebf0c4a525ee99c9"
COMBINED_REVIEWED_IDENTITY = "2329f613c5310950191a5206fd764a78afc9e6f5bf0d502d0a65751a580f1393"
PRIOR_REVIEWED_CANONICAL = "738c7836dd770e12d67de62d4f28441825814d619bb641e070e25468786fb75e"
COMBINED_REVIEWED_CANONICAL = "5d45c0c6b47770e42d68f6d1ee44c82774346b5d8909648c85ce74b793c02c8e"

GOVERNING_PROMPT = {
    "path": r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md",
    "sha256": "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f",
    "role": "GOVERNING_AUDIT_PROMPT",
}
CONTINUATION_REQUEST = {
    "path": r"C:\Users\steph\.codex\attachments\8b35b9fe-b295-4a84-bdf9-a8afb05b2daa\pasted-text-1.txt",
    "sha256": "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d",
    "role": "CONTINUATION_REQUEST_ONLY",
    "is_governing_prompt": False,
}

LANE_A = """GO — zero discrepancies.

- Commit pin: `8b4e5acbbc75db6ea2b966b0cd8d82beff2b4213`
- Tree: `9f91a6044b58354340385cd2120b4bfc10fda62a`
- Sole parent: `e32dcf40d2c4e648464d73524704c3025ffe0651`
- Scope: exactly the two reviewed RUN190 files, both new additions.
- Generator: SHA-256 `0115154b4472f96977f0d82c286943af7b687240cd23f997d2d5e0a590e18599`; blob `2dc37fc5126f484254b1bb03b7c35be357772cdb`
- Receipt: SHA-256 `88494bb887c78f488df3915c86a8ad47b2176da469aedda3803151b8edd4a708`; blob `c4da9be0db2e179bbdaacb19a814165d4ff28f0c`
- Receipt self-seal: `16cbb874448ec053f976594cfe031ed1834601d66c8b1ffe7bb79a06336d4142`
- Row seal: `31a2f128dacd47d73377db8422e2d89448909d9f4d98fe8089fa0522cb0ddfb2`
- Bridge seal: `a8934922a42c1270c62276c2dc345066372a8ea73fa3ca0875cd3c75020fc5c9`
- Final-review seals: `d67eef3feefadecaaef269d22398879dc04cb550d275fa0a2c8d7a7b8520bf0b`, `2b117150aa4b3152a0f576536210e082f15f91414f1ede48dc8d5c5804167009`

All 18 input hashes/blobs, lineage self-seals, semantic/consensus/decision seals, queue-row seals, and eight prior/combined identity hashes match. The worktree and index are clean."""

LANE_B = """GO — RUN190 post-commit accounting is exact.

- HEAD: `8b4e5acbbc75db6ea2b966b0cd8d82beff2b4213`
- Tree: `9f91a6044b58354340385cd2120b4bfc10fda62a`
- Sole parent: `e32dcf40d2c4e648464d73524704c3025ffe0651`
- Worktree: clean.
- Commit scope: exactly the RUN190 generator and receipt; both tracked blobs match working bytes.
- Receipt SHA-256 remains `88494bb887c78f488df3915c86a8ad47b2176da469aedda3803151b8edd4a708`; self-seal recomputes exactly to `16cbb874448ec053f976594cfe031ed1834601d66c8b1ffe7bb79a06336d4142`.
- All 18 sealed input hashes/blobs and the input-map hash match.

Independent ledger reconstruction:

- Owners: `666 → 667`
- Route owners: `309 → 310`
- Page owners: unchanged at `357`
- Controller-action bridges: `97 → 98`
- Combined source identity: `2e5f82279c71860a6fc2576859fb4351a6e3fbd3010f7c9f2fe598b48facf5a6`
- Combined bridge identity: `354fd9239de4233eff3e1b20b7df5c2c519e11c8a90b88490cab9513e9f1b42c`

Exactly two static credits were added:

- Route owner: `RUN077-ROUTE-0694` → `CAP-FLEET-VEHICLE-REGISTER`
- Action bridge: `app/Http/Controllers/Fleet/FleetTripController.php|show|CAP-FLEET-VEHICLE-REGISTER`

Both identities were absent from the prior ledger; no page, sibling route or non-owner row was added.

Queue reconstruction:

- Reviewed: `120 → 121`
- Pending: `387 → 386`
- Reviewed-key identity: `2329f613c5310950191a5206fd764a78afc9e6f5bf0d502d0a65751a580f1393`
- Next cursor: index `86`, `RUN090-ROUTE-0087`, `RUN077-ROUTE-0695`, `fleet-assets.trips.playback.data` → `FleetTripController::playback`

All accounting equations balance. Every page, sibling, caller, redirect, service/helper/test, remediation, correctness, Site/privacy, runtime, browser, benchmark, publication, finding, completion, Gate 4 and audit-completion boundary remains false or explicitly non-inherited. Only artifact-local completion is true.

Exact discrepancies: none. No generators, application code, tests, browser or database work was executed, and no other agents were consulted."""


def run(*args: str) -> bytes:
    return subprocess.run(args, cwd=REPO, check=True, capture_output=True).stdout


def git(*args: str) -> str:
    return run("git", *args).decode("utf-8").rstrip("\r\n")


def digest(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def canonical(value: Any) -> str:
    return digest(json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode())


def strict_json(relative: str, pretty: bool = True) -> dict[str, Any]:
    def hook(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, (relative, key)
            result[key] = value
        return result

    def reject_constant(value: str) -> None:
        raise ValueError(f"non-finite JSON constant in {relative}: {value}")

    raw = (AUDIT / relative).read_bytes()
    assert not raw.startswith(b"\xef\xbb\xbf") and b"\r\n" not in raw and raw.endswith(b"\n")
    value = json.loads(raw, object_pairs_hook=hook, parse_constant=reject_constant)
    assert isinstance(value, dict)
    if pretty:
        assert (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode() == raw
    return value


def sealed(record: dict[str, Any], field: str) -> dict[str, Any]:
    record[field] = canonical(record)
    return record


def verify_seal(record: dict[str, Any], field: str, expected: str) -> None:
    raw = record[field]
    actual = raw["sha256"] if isinstance(raw, dict) else raw
    assert actual == expected
    assert actual == canonical({key: value for key, value in record.items() if key != field})


def artifact(relative: str) -> dict[str, Any]:
    raw = (AUDIT / relative).read_bytes()
    committed = run("git", "show", f"{HEAD}:{PREFIX}/{relative}")
    assert raw == committed
    return {
        "path": f"{PREFIX}/{relative}",
        "sha256": digest(raw),
        "blob_id": git("rev-parse", f"{HEAD}:{PREFIX}/{relative}"),
        "bytes": len(raw),
        "lines": len(raw.splitlines()),
    }


def review_record(identifier: str, role: str, task_path: str, payload: str, dimensions: list[str]) -> dict[str, Any]:
    raw = payload.encode("utf-8")
    return sealed({
        "review_id": identifier,
        "reviewer_role": role,
        "reviewer_task_path": task_path,
        "independent_from_producer": True,
        "independent_from_other_review_lane": True,
        "blinded_review": False,
        "nonblinding_reason": "The committed RUN190 producer artifact and bounded audit context were visible; no blindness is claimed.",
        "delivery_channel": "collaboration_final_answer",
        "raw_payload": payload,
        "raw_payload_sha256": digest(raw),
        "raw_payload_bytes": len(raw),
        "raw_payload_lines": len(payload.splitlines()),
        "verbatim_payload_retained": True,
        "review_method": "READ_ONLY_INDEPENDENT_COMMITTED_ARTIFACT_REVIEW_NO_APPLICATION_EXECUTION",
        "verified_dimensions": dimensions,
        "verdict": "GO",
        "discrepancies": 0,
        "reviewer_wrote_files": False,
        "reviewer_executed_generator_application_tests_browser_or_database": False,
        "reporting_authorization_individually_granted": False,
    }, "review_record_sha256")


def validate_producer() -> dict[str, Any]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == HEAD
    assert git("rev-parse", "HEAD^{tree}") == TREE
    assert git("rev-parse", "HEAD^") == PARENT
    assert git("rev-parse", f"{PARENT}^{{tree}}") == PARENT_TREE
    assert git("show", "-s", "--format=%s", HEAD) == SUBJECT
    assert git("rev-parse", "origin/main") == ORIGIN_MAIN
    assert git("rev-list", "--left-right", "--count", f"origin/main...{HEAD}").split() == ["0", "51"]

    expected_paths = {
        f"{PREFIX}/{PRODUCER_GENERATOR}": ("A", "853", "0"),
        f"{PREFIX}/{PRODUCER}": ("A", "985", "0"),
    }
    names = [line.split("\t") for line in git("diff-tree", "--no-commit-id", "--name-status", "-r", HEAD).splitlines()]
    assert {parts[1]: parts[0] for parts in names} == {path: values[0] for path, values in expected_paths.items()}
    numstat = [line.split("\t") for line in git("diff-tree", "--no-commit-id", "--numstat", "-r", HEAD).splitlines()]
    assert {parts[2]: (parts[0], parts[1]) for parts in numstat} == {path: values[1:] for path, values in expected_paths.items()}

    generator = artifact(PRODUCER_GENERATOR)
    producer_artifact = artifact(PRODUCER)
    assert generator == {
        "path": f"{PREFIX}/{PRODUCER_GENERATOR}", "sha256": PRODUCER_GENERATOR_SHA,
        "blob_id": PRODUCER_GENERATOR_BLOB, "bytes": 41260, "lines": 853,
    }
    assert producer_artifact == {
        "path": f"{PREFIX}/{PRODUCER}", "sha256": PRODUCER_SHA,
        "blob_id": PRODUCER_BLOB, "bytes": 68142, "lines": 985,
    }
    source = (AUDIT / PRODUCER_GENERATOR).read_text(encoding="utf-8")
    tree = ast.parse(source)
    compile(tree, PRODUCER_GENERATOR, "exec")

    producer = strict_json(PRODUCER)
    verify_seal(producer, "self_seal", PRODUCER_SELF_SEAL)
    assert producer["pins"]["generator"] == generator
    assert producer["pins"]["input_map_sha256"] == canonical(producer["pins"]["inputs"]) == INPUT_MAP_SEAL
    assert set(producer["pins"]["inputs"]) == set(producer["pins"]["input_blobs"])
    assert len(producer["pins"]["inputs"]) == 18
    for relative, expected in producer["pins"]["inputs"].items():
        raw = (AUDIT / relative).read_bytes()
        assert digest(raw) == expected
        assert git("rev-parse", f"{HEAD}:{PREFIX}/{relative}") == producer["pins"]["input_blobs"][relative]

    assert producer["pins"]["governing_prompt"] == GOVERNING_PROMPT
    assert producer["pins"]["continuation_request"] == CONTINUATION_REQUEST
    assert digest(Path(GOVERNING_PROMPT["path"]).read_bytes()) == GOVERNING_PROMPT["sha256"]
    assert digest(Path(CONTINUATION_REQUEST["path"]).read_bytes()) == CONTINUATION_REQUEST["sha256"]
    assert GOVERNING_PROMPT["sha256"] != CONTINUATION_REQUEST["sha256"]

    lineage = producer["reviewer_lineage"]
    semantic = lineage["accepted_semantic_reviews"]
    final_reviews = lineage["accepted_final_exact_artifact_reviews"]
    assert [item["review_record_sha256"] for item in semantic] == SEMANTIC_REVIEW_SEALS
    assert [item["review_record_sha256"] for item in final_reviews] == FINAL_ARTIFACT_REVIEW_SEALS
    for item in semantic + final_reviews:
        assert item["review_record_sha256"] == canonical({key: value for key, value in item.items() if key != "review_record_sha256"})
    consensus = lineage["semantic_consensus"]
    assert consensus["synthesis_record_sha256"] == SEMANTIC_CONSENSUS_SEAL
    assert consensus["synthesis_record_sha256"] == canonical({key: value for key, value in consensus.items() if key != "synthesis_record_sha256"})
    assert consensus["outcomes"] == ["OWNER_ROUTE_ACTION", "OWNER_ROUTE_ACTION"]
    assert consensus["dissent_present"] is False
    assert lineage["run190_computed_decision_sha256"] == RUN190_DECISION_SEAL
    assert lineage["final_artifact_discrepancies"] == 0

    row = producer["overlay_source_records"][0]
    bridge = producer["new_static_controller_action_bridges"][0]
    verify_seal(row, "overlay_row_sha256", ROW_SEAL)
    verify_seal(bridge, "bridge_row_sha256", BRIDGE_SEAL)
    assert row["source_record_key"] == "route|RUN077-ROUTE-0694|CAP-FLEET-VEHICLE-REGISTER"
    assert row["review_outcome"] == "OWNER_ROUTE_ACTION"
    assert row["sibling_data_route_ownership_credit"] is False
    assert (bridge["controller_file"], bridge["method"], bridge["feature_id"]) == (
        "app/Http/Controllers/Fleet/FleetTripController.php", "show", "CAP-FLEET-VEHICLE-REGISTER",
    )

    assert producer["combined_counts"] == {
        "source_denominator": 3929, "source_owner_records": 667, "route_owner_records": 310,
        "page_owner_records": 357, "static_controller_action_bridges": 98,
        "source_owner_percent": "16.976330", "source_residual_records": 3262,
        "route_denominator": 3218, "residual_explicit_unmapped_routes": 2891,
        "page_denominator": 711, "residual_explicit_unmapped_pages": 345,
        "distinct_feature_ids": 256, "distinct_route_feature_ids": 64,
        "distinct_page_feature_ids": 242, "route_page_feature_overlap": 50,
    }
    assert producer["queue_accounting"] == {
        "queue_surface_rows": 507, "reviewed_queue_surface_rows": 121,
        "pending_unreviewed_queue_surface_rows": 386, "reviewed_owner_route_rows": 99,
        "reviewed_shared_relation_rows": 10, "reviewed_alias_rows": 5,
        "reviewed_dead_or_retired_rows": 0, "reviewed_evidence_gap_rows": 7,
        "queue_surfaces_without_ownership": 408, "new_reviewed_route_surface_rows": 1,
        "new_owner_route_surface_rows": 1,
    }
    boundary = producer["queue_boundary"]
    assert (
        boundary["next_unresolved_index"], boundary["next_unresolved_queue_id"],
        boundary["next_unresolved_route_record_id"], boundary["next_unresolved_route_name"],
        boundary["next_unresolved_action_expression"], boundary["next_unresolved_queue_record_sha256"],
    ) == (
        86, "RUN090-ROUTE-0087", "RUN077-ROUTE-0695", "fleet-assets.trips.playback.data",
        "[FleetTripController::class, 'playback']", "ed12617b478e0a22014fb6c81402e5cf79aa574720e8ef8e2ce93f198a099893",
    )

    identity = producer["identity"]
    assert identity["prior_source_record_key_list_sha256"] == PRIOR_SOURCE_IDENTITY
    assert identity["combined_source_record_key_list_sha256"] == COMBINED_SOURCE_IDENTITY
    assert identity["prior_bridge_key_list_sha256"] == PRIOR_BRIDGE_IDENTITY
    assert identity["combined_bridge_key_list_sha256"] == COMBINED_BRIDGE_IDENTITY
    assert identity["prior_reviewed_queue_key_list_sha256"] == PRIOR_REVIEWED_IDENTITY
    assert identity["combined_reviewed_queue_key_list_sha256"] == COMBINED_REVIEWED_IDENTITY
    assert identity["canonical_json_reviewed_key_hashes"] == {
        "prior": PRIOR_REVIEWED_CANONICAL, "combined": COMBINED_REVIEWED_CANONICAL,
    }
    assert identity["accepted_semantic_review_record_sha256s"] == SEMANTIC_REVIEW_SEALS
    assert identity["accepted_final_exact_artifact_review_record_sha256s"] == FINAL_ARTIFACT_REVIEW_SEALS
    assert identity["semantic_consensus_record_sha256"] == SEMANTIC_CONSENSUS_SEAL
    assert identity["run190_computed_decision_sha256"] == RUN190_DECISION_SEAL

    assert {key for key, value in producer["credit_boundary"].items() if value} == {
        "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD", "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION",
    }
    assert producer["noninheritance_boundary"]["sibling_playback_data_route_identity_or_outcome_inherited"] is False
    assert producer["noninheritance_boundary"]["page_caller_redirect_service_model_helper_or_test_inherited_or_recredited"] is False
    assert producer["remediation_and_history_noninheritance"]["static_route_ownership_inherited_from_remediation"] is False
    assert producer["remediation_and_history_noninheritance"]["correctness_inherited_from_static_identity"] is False
    assert producer["mutation_attestation"]["run190_producer_scope_contains_only_generator_and_receipt"] is True
    assert producer["artifact_completion_test_met"] is True
    assert producer["audit_completion_test_met"] is False
    assert producer["completion_boundary"]["gate_4_complete"] is False
    assert producer["completion_boundary"]["audit_complete"] is False
    return producer


def main() -> None:
    producer = validate_producer()
    reviews = [
        review_record(
            "RUN190R-INDEPENDENT-REVIEW-A",
            "exact commit artifact lineage and seal integrity reviewer",
            "/root/run189_semantic_a",
            LANE_A,
            [
                "commit_tree_parent_subject_and_exact_two_path_scope",
                "producer_generator_and_receipt_bytes_blobs_and_format",
                "all_input_sha_blob_pairs_and_prompt_provenance",
                "lineage_semantic_consensus_decision_row_and_bridge_seals",
                "prior_and_combined_identity_seals",
                "clean_worktree_and_execution_boundary",
            ],
        ),
        review_record(
            "RUN190R-INDEPENDENT-REVIEW-B",
            "independent accounting cursor credit and noninheritance reviewer",
            "/root/run189_semantic_b",
            LANE_B,
            [
                "owner_route_page_and_bridge_accounting",
                "source_and_bridge_identity_reconstruction",
                "queue_accounting_and_next_cursor",
                "selected_route_action_only_credit",
                "sibling_page_caller_service_test_and_remediation_noninheritance",
                "correctness_runtime_benchmark_publication_and_completion_boundaries",
            ],
        ),
    ]
    assert len({item["reviewer_task_path"] for item in reviews}) == 2
    assert all(item["verdict"] == "GO" and item["discrepancies"] == 0 for item in reviews)
    assert all(item["reporting_authorization_individually_granted"] is False for item in reviews)

    synthesis = sealed({
        "synthesis_id": "RUN190R-TWO-LANE-POST-COMMIT-SYNTHESIS",
        "accepted_review_ids": [item["review_id"] for item in reviews],
        "accepted_review_record_sha256s": [item["review_record_sha256"] for item in reviews],
        "independent_reviews": 2,
        "all_exact_lane_payloads_sealed_before_synthesis": True,
        "discrepancies": 0,
        "artifact_lineage_and_seal_integrity_go": True,
        "accounting_cursor_credit_and_noninheritance_go": True,
        "producer_commit_exact_two_path_scope": True,
        "reporting_materialization_authorized": True,
        "new_current_or_downstream_credit_authorized": False,
        "correctness_runtime_benchmark_finding_or_completion_credit_authorized": False,
        "release_or_publication_authorized": False,
        "gate_4_complete": False,
        "audit_complete": False,
    }, "synthesis_record_sha256")
    decision = sealed({
        "decision_id": "RUN190R-POST-COMMIT-REVIEW-DECISION",
        "verdict": "GO_TWO_LANE_POST_COMMIT_REVIEW_COMPLETE_REPORTING_ONLY_ZERO_NEW_OR_DOWNSTREAM_CREDIT",
        "accepted_review_record_sha256s": synthesis["accepted_review_record_sha256s"],
        "synthesis_record_sha256": synthesis["synthesis_record_sha256"],
        "independent_reviews": 2,
        "independently_sealed_review_records": True,
        "discrepancies": 0,
        "reporting_materialization_authorized": True,
        "new_source_ownership_credit": False,
        "new_route_ownership_credit": False,
        "new_page_ownership_credit": False,
        "new_controller_action_bridge_credit": False,
        "current_overlay_ownership_credit": False,
        "correctness_or_downstream_credit": False,
        "release_authorized": False,
        "publication_authorized": False,
        "gate_4_complete": False,
        "audit_complete": False,
    }, "decision_record_sha256")

    false_credit = {key: False for key in (
        "new_source_ownership", "new_route_ownership", "new_page_ownership", "new_controller_action_bridge",
        "current_overlay_ownership_credit", "downstream_ownership_credit", "adjacent_route_ownership",
        "frontend_caller_ownership", "service_model_helper_caller_or_test_ownership",
        "complete_route_page_feature_crosswalk", "framework_route_reachability",
        "canonical_object_ownership_correctness", "approved_site_scope_correctness",
        "permission_correctness", "privacy_correctness", "direct_object_concealment_correctness",
        "query_projection_correctness", "runtime", "database", "build", "application_browser",
        "responsive_application", "executed_tests", "benchmark", "final_no_match_or_NCM", "ease",
        "pass", "final_finding", "feature_completion", "completion", "gate_4", "release",
        "publication", "audit_complete",
    )}
    payload: dict[str, Any] = {
        "schema_version": "run-190r-independent-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-review-wave-37-v1",
        "run_id": "RUN-190R-INDEPENDENT-REVIEWED-OUTCOME-NEUTRAL-FLEET-TRIP-PLAYBACK-ROUTE-ACTION-OWNERSHIP-OVERLAY-REVIEW-WAVE-37",
        "status": "GO_TWO_LANE_POST_COMMIT_REVIEW_COMPLETE_REPORTING_ONLY_ZERO_NEW_OR_DOWNSTREAM_CREDIT",
        "reviewed_on": "2026-08-31",
        "pins": {
            "producer_commit": HEAD,
            "producer_tree": TREE,
            "producer_parent": PARENT,
            "producer_parent_tree": PARENT_TREE,
            "producer_subject": SUBJECT,
            "origin_main_observed_without_refetch": ORIGIN_MAIN,
            "governing_prompt": GOVERNING_PROMPT,
            "continuation_request": CONTINUATION_REQUEST,
            "producer_generator": artifact(PRODUCER_GENERATOR),
            "producer": artifact(PRODUCER),
            "producer_self_seal_sha256": PRODUCER_SELF_SEAL,
            "producer_input_map_sha256": INPUT_MAP_SEAL,
            "semantic_review_record_sha256s": SEMANTIC_REVIEW_SEALS,
            "final_exact_artifact_review_record_sha256s": FINAL_ARTIFACT_REVIEW_SEALS,
            "semantic_consensus_record_sha256": SEMANTIC_CONSENSUS_SEAL,
            "run190_computed_decision_sha256": RUN190_DECISION_SEAL,
            "overlay_row_sha256": ROW_SEAL,
            "bridge_row_sha256": BRIDGE_SEAL,
            "materializer": {
                "path": f"{PREFIX}/{GENERATOR}",
                "sha256": digest((AUDIT / GENERATOR).read_bytes()),
                "blob_id": git("hash-object", "--", str(AUDIT / GENERATOR)),
                "bytes": (AUDIT / GENERATOR).stat().st_size,
                "lines": len((AUDIT / GENERATOR).read_bytes().splitlines()),
            },
        },
        "architecture_rule": producer["architecture_rule"],
        "methods": {
            "independent_reviews": 2,
            "synthesizers": 1,
            "committed_artifact_only": True,
            "producer_generator_executed_by_reviewers": False,
            "application_executed": False,
            "tests_executed": False,
            "database_used": False,
            "build_used": False,
            "browser_used": False,
            "external_system_used": False,
        },
        "producer_scope": {
            "changed_paths": [f"{PREFIX}/{PRODUCER_GENERATOR}", f"{PREFIX}/{PRODUCER}"],
            "changed_path_count": 2,
            "added_lines": 1838,
            "deleted_lines": 0,
            "generator_numstat": "853/0",
            "receipt_numstat": "985/0",
            "working_copies_match_committed_blobs": True,
            "application_tests_routes_and_resources_unchanged_by_run190": True,
        },
        "independent_review_records": reviews,
        "synthesis_review": synthesis,
        "decision": decision,
        "producer_snapshot": {
            "run_id": producer["run_id"],
            "status": producer["status"],
            "combined_counts": producer["combined_counts"],
            "queue_accounting": producer["queue_accounting"],
            "queue_boundary": producer["queue_boundary"],
            "identity": producer["identity"],
            "outcome_conservation": producer["outcome_conservation"],
            "overlay_row_sha256": ROW_SEAL,
            "bridge_row_sha256": BRIDGE_SEAL,
            "sibling_playback_data_route_identity_or_outcome_inherited": False,
        },
        "publication_boundary": {
            "local_main_equals_producer_commit": True,
            "origin_main_equals_producer_commit": False,
            "origin_main_observed_without_refetch": ORIGIN_MAIN,
            "local_main_ahead_of_origin_main_by_commits": 51,
            "local_main_behind_origin_main_by_commits": 0,
            "remote_refetch_for_run190r_performed": False,
            "push_performed": False,
            "release_authorized": False,
            "publication_authorized_or_performed": False,
            "local_remote_tracking_alignment_claimed": False,
        },
        "credit_boundary": {"INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING": True, **false_credit},
        "completion_boundary": producer["completion_boundary"],
        "artifact_completion_test_met": True,
        "artifact_completion_scope": "THIS_EXACT_TWO_FILE_RUN190R_REVIEW_ARTIFACT_ONLY",
        "reporting_materialization_authorized": True,
        "audit_completion_test_met": False,
        "mutation_attestation": {
            "application_source_changed": False,
            "test_files_changed": False,
            "matrix_or_reporting_changed": False,
            "runtime_browser_or_external_system_changed": False,
            "audit_artifacts_only": True,
            "run190r_scope_contains_only_materializer_and_receipt": True,
        },
        "wrote_files": [f"{PREFIX}/{GENERATOR}", f"{PREFIX}/{OUTPUT}"],
    }
    assert {key for key, value in payload["credit_boundary"].items() if value} == {"INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"}
    payload["self_seal"] = {"algorithm": "sha256-canonical-json-with-self-seal-omitted", "sha256": canonical(payload)}

    target = AUDIT / OUTPUT
    temporary = target.with_name(target.name + ".tmp")
    assert not temporary.exists()
    raw = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    temporary.write_bytes(raw)
    os.replace(temporary, target)
    parsed = strict_json(OUTPUT)
    seal = parsed.pop("self_seal")
    assert seal["sha256"] == canonical(parsed)
    assert target.read_bytes() == raw and not temporary.exists()
    assert not git("status", "--porcelain", "--untracked-files=no")
    expected_untracked = {f"{PREFIX}/{GENERATOR}", f"{PREFIX}/{OUTPUT}"}
    actual_untracked = {line[3:] for line in git("status", "--porcelain").splitlines() if line.startswith("?? ")}
    assert actual_untracked == expected_untracked, (actual_untracked, expected_untracked)
    assert not list(AUDIT.rglob("__pycache__"))
    assert not list(AUDIT.rglob(".pytest_cache"))
    assert not list(AUDIT.rglob(".mypy_cache"))
    assert not list(AUDIT.rglob(".ruff_cache"))
    assert not list(AUDIT.rglob("*.tmp"))
    print(json.dumps({
        "status": payload["status"],
        "materializer_sha256": payload["pins"]["materializer"]["sha256"],
        "receipt_sha256": digest(target.read_bytes()),
        "lane_payloads": [{
            "review_id": item["review_id"],
            "raw_payload_sha256": item["raw_payload_sha256"],
            "raw_payload_bytes": item["raw_payload_bytes"],
            "raw_payload_lines": item["raw_payload_lines"],
            "review_record_sha256": item["review_record_sha256"],
        } for item in reviews],
        "synthesis_record_sha256": synthesis["synthesis_record_sha256"],
        "decision_record_sha256": decision["decision_record_sha256"],
        "self_seal": payload["self_seal"]["sha256"],
        "receipt_bytes": target.stat().st_size,
        "receipt_lines": len(target.read_bytes().splitlines()),
        "reporting_materialization_authorized": True,
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
