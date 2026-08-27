from __future__ import annotations

import ast
import hashlib
import json
import subprocess
from pathlib import Path


ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24"
PRODUCER_GENERATOR = AUDIT / "generators/integrate-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.py"
PRODUCER_OUTPUT = AUDIT / "evidence/source/current-run-149-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.json"
OUTPUT = AUDIT / "evidence/source/current-run-149r-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25.json"

COMMIT = "10ec4a102666fccb5c332086c9c6d29eb02a37ac"
TREE = "3ba43ca6e666e55a790ae0c6695af99630c8348b"
PARENT = "6ce3cbd5f8989baad0a691be9ca16c302458f9c4"
PRODUCER_BASE_TREE = "1866911b513814ea50c43062d0bab594ccb8b88f"
GENERATOR_SHA256 = "b5c7f04cd44ecd73dda9c7fe4a9e2e8616c68674cdc52d393ec696b06ad2327e"
GENERATOR_BLOB_ID = "e8d9d1c9889be589a22db6dfea53d3122adce247"
OUTPUT_SHA256 = "12a52c434ecd18a5c6a644378070aa5ab046f5e7080726b983ded8d9c7377a55"
OUTPUT_BLOB_ID = "c5f0a3bda99167f66650d63bfdb35e18d8ed93b5"
INPUT_MAP_SHA256 = "302b479e6fde52fee7dca5d75dd2e1d7e80e2a547e5d172dc3a85605c0ab8ebc"
IDENTITY_SHA256 = "721078958db37e287acb4c1eab366ab649b6e8c3a4ed65f3ff1a491805a8dd87"
COUNTS_SHA256 = "dfc7dcf32eeeb1cca7612c476a555bec489ac8a130415d8ea386ffedadcb93cc"
QUEUE_SHA256 = "2f02e7d745d3f7c20549570de3721893b0cc697413bf92725abc4c45ab43f3dd"
LINEAGE_SHA256 = "a80adebda35ca5b4884486191ebc531e0cc498d25e981e91a05f29323af2e2e1"
EXPANSION_SHA256 = "f68bd4621ac83e706cd65802bacba61f639e982a43ad2ac9c3ea5997bdba47ba"
OBSERVATIONS_SHA256 = "0679248a1969e4f08dd71a71ff2b41f8af17b45c525ae06af7960a9394778b58"
OVERLAY_ROWS_SHA256 = "559e00c684fd9b7aa4ef3dd7da754e8991901ca1820e837288b01b0ff03622a3"
BRIDGES_SHA256 = "b5a3216d18e420270f248a97c54410c8546fcfa12c7514afd2b696528357d9e5"

EXPECTED_COUNTS = {
    "source_owner_records": 663,
    "route_owner_records": 306,
    "page_owner_records": 357,
    "distinct_feature_ids": 256,
    "distinct_H_feature_ids": 234,
    "distinct_D_feature_ids": 22,
    "route_distinct_feature_ids": 64,
    "page_distinct_feature_ids": 242,
    "route_page_feature_overlap": 50,
    "static_controller_action_bridges": 94,
    "bounded_static_source_denominator": 3929,
    "bounded_static_source_ownership_percent": "16.874523",
    "bounded_static_source_residual_records": 3266,
    "residual_explicit_unmapped_routes": 2895,
    "semantic_shared_routes": 12,
    "reviewed_alias_routes": 5,
    "reviewed_dead_routes": 0,
    "evidence_gap_routes_tagged_within_residual": 7,
    "residual_unadjudicated_page_roots": 345,
    "semantic_shared_page_roots": 9,
    "reviewed_alias_page_roots": 0,
    "reviewed_dead_page_roots": 0,
    "evidence_gap_page_roots_tagged_within_residual": 1,
}

EXPECTED_QUEUE = {
    "direct_exact_queue_records": 507,
    "reviewed_queue_surface_rows": 117,
    "owner_queue_surface_rows": 95,
    "shared_queue_surface_rows": 10,
    "alias_queue_surface_rows": 5,
    "dead_queue_surface_rows": 0,
    "evidence_gap_queue_surface_rows": 7,
    "pending_unreviewed_queue_surface_rows": 390,
    "queue_surfaces_without_ownership": 412,
    "new_reviewed_route_surface_rows": 1,
    "new_owner_route_surface_rows": 1,
    "new_shared_route_surface_rows": 0,
    "new_alias_route_surface_rows": 0,
    "new_dead_route_surface_rows": 0,
    "new_evidence_gap_route_surface_rows": 0,
    "wholesale_queue_ownership_authorized": False,
}


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def canonical(value: object) -> bytes:
    return json.dumps(
        value, ensure_ascii=False, separators=(",", ":"), sort_keys=True
    ).encode("utf-8")


def canonical_sha256(value: object) -> str:
    return sha256(canonical(value))


def strict_object(pairs: list[tuple[str, object]]) -> dict:
    assert len(pairs) == len({key for key, _ in pairs}), "duplicate JSON key"
    return dict(pairs)


def strict_json(raw: bytes) -> dict:
    return json.loads(raw, object_pairs_hook=strict_object)


def git(*args: str) -> str:
    return subprocess.run(
        ["git", *args], cwd=ROOT, check=True, text=True, capture_output=True
    ).stdout.strip()


def sealed(record: dict) -> dict:
    return {"record": record, "record_sha256": canonical_sha256(record)}


def replay_producer_in_memory() -> bytes:
    source = PRODUCER_GENERATOR.read_text(encoding="utf-8")
    marker = '\nif __name__ == "__main__":\n    main()\n'
    assert marker in source
    source = source.replace(marker, "\n")
    namespace = {
        "__name__": "run149_in_memory_replay",
        "__file__": str(PRODUCER_GENERATOR),
    }
    exec(compile(source, str(PRODUCER_GENERATOR), "exec"), namespace)

    real_git = namespace["git"]
    captured: dict[str, bytes] = {}

    def replay_git(*args: str) -> str:
        if args == ("rev-parse", "HEAD"):
            return PARENT
        if args == ("show", "-s", "--format=%T", "HEAD"):
            return PRODUCER_BASE_TREE
        if args == ("branch", "--show-current"):
            return "main"
        return real_git(*args)

    namespace["git"] = replay_git
    real_run = subprocess.run

    def replay_run(args, *positional, **kwargs):
        if list(args) == ["git", "status", "--short"]:
            status = (
                f"?? {PRODUCER_GENERATOR.relative_to(ROOT).as_posix()}\n"
                f"?? {PRODUCER_OUTPUT.relative_to(ROOT).as_posix()}\n"
            )
            return subprocess.CompletedProcess(args, 0, stdout=status, stderr="")
        return real_run(args, *positional, **kwargs)

    real_write_bytes = Path.write_bytes
    real_read_bytes = Path.read_bytes

    def memory_write_bytes(path: Path, raw: bytes) -> int:
        assert path.resolve() == PRODUCER_OUTPUT.resolve()
        captured["output"] = bytes(raw)
        return len(raw)

    def memory_read_bytes(path: Path) -> bytes:
        if path.resolve() == PRODUCER_OUTPUT.resolve() and "output" in captured:
            return captured["output"]
        return real_read_bytes(path)

    Path.write_bytes = memory_write_bytes
    Path.read_bytes = memory_read_bytes
    subprocess.run = replay_run
    try:
        namespace["main"]()
    finally:
        Path.write_bytes = real_write_bytes
        Path.read_bytes = real_read_bytes
        subprocess.run = real_run
    assert "output" in captured
    return captured["output"]


def mechanical_review(producer: dict, replayed: bytes, diff_rows: list[str]) -> dict:
    return {
        "review_id": "RUN149R-INDEPENDENT-MECHANICAL-REPLAY-REVIEW",
        "reviewer_task_path": "/root/run149r_review_plan",
        "review_scope": "POST_COMMIT_HASH_FORMAT_REPLAY_AND_MUTATION_SCOPE",
        "reviewed_on": "2026-08-27",
        "verdict": "GO",
        "confidence": "HIGH",
        "producer_commit": COMMIT,
        "producer_tree": TREE,
        "producer_parent": PARENT,
        "commit_diff": {
            "files": 2,
            "name_status": diff_rows,
            "audit_artifacts_only": True,
        },
        "generator": {
            "sha256": GENERATOR_SHA256,
            "blob_id": GENERATOR_BLOB_ID,
            "bytes": 44026,
            "lines": 763,
        },
        "output": {
            "sha256": OUTPUT_SHA256,
            "blob_id": OUTPUT_BLOB_ID,
            "bytes": 50937,
            "lines": 790,
            "strict_json": True,
            "lf_no_bom_terminal_lf": True,
        },
        "inputs": {
            "count": 25,
            "map_sha256": INPUT_MAP_SHA256,
            "all_current_sha256s_match": True,
            "undeclared_literal_audit_reads": 0,
        },
        "replay": {
            "mode": "IN_MEMORY_WRITE_INTERCEPT",
            "byte_identical": replayed == PRODUCER_OUTPUT.read_bytes(),
            "sha256": sha256(replayed),
        },
        "producer_internal_checkpoint_is_preintegration_parent": (
            producer["pins"]["checkpoint_commit"] == PARENT
        ),
        "no_pycache": True,
        "reviewer_wrote_files": False,
        "wrote_files": [],
    }


def lineage_review(producer: dict) -> dict:
    reviewers = producer["reviewer_lineage"]["independent_candidate_reviews"]
    synthesis = producer["reviewer_lineage"]["synthesis_review"]
    decision = producer["reviewer_lineage"]["action_decision"]
    return {
        "review_id": "RUN149R-INDEPENDENT-LINEAGE-IDENTITY-REVIEW",
        "reviewer_task_path": "/root/run149_integrate",
        "review_scope": "POST_COMMIT_INPUT_CANDIDATE_REVIEW_SYNTHESIS_AND_IDENTITY_LINEAGE",
        "reviewed_on": "2026-08-27",
        "verdict": "GO",
        "confidence": "HIGH",
        "producer_commit": COMMIT,
        "producer_tree": TREE,
        "producer_parent": PARENT,
        "input_count": len(producer["pins"]["inputs"]),
        "input_map_sha256": canonical_sha256(producer["pins"]["inputs"]),
        "candidate_record_sha256": "589212109db42fd2e0b1611ea855ea76c469a492528d084949880d3601ac45b2",
        "independent_review_record_sha256s": [
            item["review_record_sha256"] for item in reviewers
        ],
        "synthesis_record_sha256": synthesis["synthesis_record_sha256"],
        "decision_record_sha256": decision["decision_record_sha256"],
        "identity_fields": len(producer["identity"]),
        "identity_sha256": canonical_sha256(producer["identity"]),
        "identity_rediscovery_equal": (
            producer["identity"] == producer["identity_discovery"]
        ),
        "reviewer_nonblinding_disclosure": producer["reviewer_lineage"][
            "nonblinding_disclosure_preserved"
        ],
        "ownership_material_expansion_required": False,
        "correctness_only_expanded_files": 4,
        "requested_but_not_fully_inspected": 4,
        "provisional_source_observations": 4,
        "provisional_observations_are_not_final_findings": True,
        "page_sibling_or_next_queue_credit_inherited": False,
        "reviewer_wrote_files": False,
        "wrote_files": [],
    }


def semantic_count_review(producer: dict) -> dict:
    positives = [
        key for key, value in producer["credit_boundary"].items() if value
    ]
    return {
        "review_id": "RUN149R-INDEPENDENT-COUNT-NONINHERITANCE-REVIEW",
        "reviewer_task_path": "/root/run149_overlay_validate",
        "review_scope": "POST_COMMIT_COUNT_QUEUE_NONINHERITANCE_AND_CREDIT_PARTITION",
        "reviewed_on": "2026-08-27",
        "verdict": "GO",
        "confidence": "HIGH",
        "producer_commit": COMMIT,
        "producer_tree": TREE,
        "producer_parent": PARENT,
        "combined_count_fields": len(producer["combined_counts"]),
        "combined_counts_sha256": canonical_sha256(producer["combined_counts"]),
        "queue_accounting_fields": len(producer["queue_accounting"]),
        "queue_accounting_sha256": canonical_sha256(producer["queue_accounting"]),
        "verified_totals": {
            "source_owners": 663,
            "route_owners": 306,
            "page_owners": 357,
            "controller_action_bridges": 94,
            "reviewed_queue_rows": 117,
            "pending_queue_rows": 390,
            "queue_rows_without_ownership": 412,
        },
        "producer_positive_credit_keys": positives,
        "new_distinct_feature_ids": 0,
        "preceding_index_79_recredited": False,
        "page_or_frontend_caller_credit": False,
        "next_index_81_selected_or_credited": False,
        "correctness_or_downstream_credit": False,
        "runtime_browser_test_benchmark_finding_or_completion_credit": False,
        "reporting_only_review": True,
        "reviewer_wrote_files": False,
        "wrote_files": [],
    }


def main() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == COMMIT
    assert git("show", "-s", "--format=%T", "HEAD") == TREE
    assert git("rev-parse", "HEAD^") == PARENT

    generator_raw = PRODUCER_GENERATOR.read_bytes()
    producer_raw = PRODUCER_OUTPUT.read_bytes()
    assert sha256(generator_raw) == GENERATOR_SHA256
    assert sha256(producer_raw) == OUTPUT_SHA256
    assert git(
        "rev-parse", f"HEAD:{PRODUCER_GENERATOR.relative_to(ROOT).as_posix()}"
    ) == GENERATOR_BLOB_ID
    assert git(
        "rev-parse", f"HEAD:{PRODUCER_OUTPUT.relative_to(ROOT).as_posix()}"
    ) == OUTPUT_BLOB_ID
    assert len(generator_raw) == 44026
    assert len(generator_raw.decode("utf-8").splitlines()) == 763
    assert len(producer_raw) == 50937
    assert len(producer_raw.decode("utf-8").splitlines()) == 790
    assert producer_raw.endswith(b"\n") and b"\r\n" not in producer_raw
    assert not producer_raw.startswith(b"\xef\xbb\xbf")
    producer = strict_json(producer_raw)

    expected_diff = sorted(
        [
            f"A\t{PRODUCER_GENERATOR.relative_to(ROOT).as_posix()}",
            f"A\t{PRODUCER_OUTPUT.relative_to(ROOT).as_posix()}",
        ]
    )
    diff_rows = sorted(
        git("show", "--format=", "--name-status", "--no-renames", COMMIT).splitlines()
    )
    assert diff_rows == expected_diff

    assert producer["pins"]["checkpoint_commit"] == PARENT
    assert producer["pins"]["checkpoint_tree"] == PRODUCER_BASE_TREE
    assert producer["pins"]["generator_sha256"] == GENERATOR_SHA256
    assert len(producer["pins"]["inputs"]) == 25
    assert canonical_sha256(producer["pins"]["inputs"]) == INPUT_MAP_SHA256
    for relative_path, expected_sha256 in producer["pins"]["inputs"].items():
        assert sha256((AUDIT / relative_path).read_bytes()) == expected_sha256
    for path_key, sha_key in (
        ("cohort_generator", "cohort_generator_sha256"),
        ("review_materializer", "review_materializer_sha256"),
    ):
        assert sha256((ROOT / producer["pins"][path_key]).read_bytes()) == producer[
            "pins"
        ][sha_key]

    assert producer["combined_counts"] == EXPECTED_COUNTS
    assert producer["queue_accounting"] == EXPECTED_QUEUE
    assert len(producer["combined_counts"]) == 23
    assert canonical_sha256(producer["combined_counts"]) == COUNTS_SHA256
    assert len(producer["queue_accounting"]) == 16
    assert canonical_sha256(producer["queue_accounting"]) == QUEUE_SHA256
    assert len(producer["identity"]) == len(producer["identity_discovery"]) == 38
    assert producer["identity"] == producer["identity_discovery"]
    assert canonical_sha256(producer["identity"]) == IDENTITY_SHA256
    assert canonical_sha256(producer["reviewer_lineage"]) == LINEAGE_SHA256
    assert canonical_sha256(producer["source_packet_expansion_preservation"]) == EXPANSION_SHA256
    assert canonical_sha256(
        producer["provisional_assurance_observation_preservation"]
    ) == OBSERVATIONS_SHA256
    assert canonical_sha256(producer["overlay_source_records"]) == OVERLAY_ROWS_SHA256
    assert canonical_sha256(
        producer["new_static_controller_action_bridges"]
    ) == BRIDGES_SHA256

    assert len(producer["overlay_source_records"]) == 1
    assert len(producer["new_static_controller_action_bridges"]) == 1
    row = producer["overlay_source_records"][0]
    bridge = producer["new_static_controller_action_bridges"][0]
    assert row["overlay_row_sha256"] == canonical_sha256(
        {key: value for key, value in row.items() if key != "overlay_row_sha256"}
    ) == "a37190965390ff28d85e99c6c0e0625dc8f4157d95df45d79a3b2337dc970785"
    assert bridge["bridge_row_sha256"] == canonical_sha256(
        {key: value for key, value in bridge.items() if key != "bridge_row_sha256"}
    ) == "6f352ecacb54d1582ef05c55e506348edf5d77628da9304cd9a1e9c35d6214a4"

    reviewers = producer["reviewer_lineage"]["independent_candidate_reviews"]
    assert len(reviewers) == 2
    assert [item["review_record_sha256"] for item in reviewers] == [
        "431d0b7fbdc7734238ca489a0ac3044fd81e40cdc6d4dcd014b653c69fb21ec1",
        "3d7a67b8f1cde6d753ae0292e5aaf6e53f9312db1a9469ded8228c728257acd7",
    ]
    for reviewer in reviewers:
        assert reviewer["review_record_sha256"] == canonical_sha256(
            {
                key: value
                for key, value in reviewer.items()
                if key != "review_record_sha256"
            }
        )
    assert [item["blinded_review"] for item in reviewers] == [False, False]
    assert [item["prior_outcome_visible_in_team_status"] for item in reviewers] == [
        False,
        True,
    ]
    assert all(item["other_candidate_reviewer_consulted"] is False for item in reviewers)
    assert all(item["independent_evidence_trace_completed"] is True for item in reviewers)
    synthesis = producer["reviewer_lineage"]["synthesis_review"]
    decision = producer["reviewer_lineage"]["action_decision"]
    assert synthesis["synthesis_record_sha256"] == canonical_sha256(
        {
            key: value
            for key, value in synthesis.items()
            if key != "synthesis_record_sha256"
        }
    ) == "c7c5dc9b5ebe2c9ee16105fa2c25b7be673fd3fb129055d87fc9f8fa93e36dbf"
    assert decision["decision_record_sha256"] == canonical_sha256(
        {
            key: value
            for key, value in decision.items()
            if key != "decision_record_sha256"
        }
    ) == "e28ab3b80b9de141bc3a958a79569f2145bff4a5da8f3bc39a24230cd7231f66"

    expansion = producer["source_packet_expansion_preservation"]
    observations = producer["provisional_assurance_observation_preservation"]
    assert expansion["ownership_material_expansion"] == []
    assert expansion["ownership_material_expansion_required"] is False
    assert len(expansion["correctness_only_expanded_files"]) == 4
    assert len(expansion["requested_but_not_fully_inspected"]) == 4
    assert expansion["correctness_only_expansion_manifest_sha256"] == (
        "22288d8ac2bd3fa97086fd495ec6773cf40a062e1dec940892060599a4e513f5"
    )
    assert expansion["expansion_authorizes_correctness_credit"] is False
    assert observations["observation_count"] == len(observations["observations"]) == 4
    for observation in observations["observations"]:
        assert observation["status"] == "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING"
        assert observation["correctness_credit_authorized"] is False
        assert observation["final_finding_credit_authorized"] is False
        assert observation["observation_record_sha256"] == canonical_sha256(
            {
                key: value
                for key, value in observation.items()
                if key != "observation_record_sha256"
            }
        )

    assert producer["noninheritance_boundary"] == {
        "preceding_index_79_owner_not_inherited_or_recredited": True,
        "page_owner_not_inherited_or_recredited": True,
        "frontend_caller_not_inherited_or_recredited": True,
        "next_index_81_not_selected_or_credited": True,
        "current_overlay_correctness_and_downstream_credit": False,
    }
    assert producer["page_sibling_and_next_boundary"]["next_pending_boundary"][
        "queue_id"
    ] == "RUN090-ROUTE-0082"
    assert producer["page_sibling_and_next_boundary"][
        "next_pending_queue_record_sha256"
    ] == "c15a3e4371f5d063066b013b824205c24d1ab6126f49aea3d266e9b897b146de"
    assert [
        key for key, value in producer["credit_boundary"].items() if value
    ] == [
        "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD",
        "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION",
    ]
    assert producer["audit_completion_test_met"] is False

    replayed = replay_producer_in_memory()
    assert replayed == producer_raw
    assert sha256(replayed) == OUTPUT_SHA256
    ast.parse(generator_raw.decode("utf-8"))
    ast.parse(Path(__file__).read_text(encoding="utf-8"))

    records = [
        sealed(mechanical_review(producer, replayed, diff_rows)),
        sealed(lineage_review(producer)),
        sealed(semantic_count_review(producer)),
    ]
    assert len({item["record_sha256"] for item in records}) == 3
    assert len({item["record"]["reviewer_task_path"] for item in records}) == 3
    synthesis_review = {
        "review_id": "RUN149R-THREE-PART-POST-COMMIT-SYNTHESIS",
        "reviewer_task_path": "/root",
        "verdict": "GO",
        "accepted_record_sha256s": [item["record_sha256"] for item in records],
        "independently_sealed_review_records": True,
        "distinct_reviewer_task_paths": True,
        "blinded_reviews": False,
        "independent_evidence_recomputation": True,
        "discrepancies": 0,
        "reporting_materialization_authorized": True,
        "provisional_observations_may_be_reported_as_provisional_only": True,
        "new_ownership_or_bridge_credit": False,
        "page_or_correctness_credit": False,
        "runtime_browser_test_benchmark_final_or_completion_credit": False,
        "reviewer_wrote_files": False,
    }
    synthesis_review["synthesis_record_sha256"] = canonical_sha256(synthesis_review)

    false_credit_keys = [
        "new_source_ownership",
        "new_route_ownership",
        "new_page_ownership",
        "new_controller_action_bridge",
        "direct_exact_queue_review",
        "current_overlay_ownership_credit",
        "prior_sibling_owner_context_inherited_or_recredited",
        "frontend_caller_ownership",
        "next_queue_record_selected_or_credited",
        "complete_route_page_feature_crosswalk",
        "framework_route_reachability",
        "matrix_mutation",
        "canonical_object_ownership_correctness",
        "site_authorization_correctness",
        "permission_correctness",
        "privacy_correctness",
        "direct_object_correctness",
        "template_authority_correctness",
        "concurrency_or_idempotency_correctness",
        "audit_or_event_durability_correctness",
        "runtime",
        "database",
        "build",
        "application_browser",
        "responsive_application",
        "visual_application_workflow",
        "executed_tests",
        "application_source_mutation",
        "benchmark",
        "ease",
        "release",
        "pass",
        "final_finding",
        "completion",
        "audit_complete",
    ]
    credit_boundary = {"INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING": True}
    credit_boundary.update({key: False for key in false_credit_keys})

    materializer_rel = Path(__file__).resolve().relative_to(ROOT).as_posix()
    output_rel = OUTPUT.relative_to(ROOT).as_posix()
    payload = {
        "schema_version": "run-149r-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25-v1",
        "run_id": "RUN-149R-INDEPENDENT-REVIEWED-OUTCOME-NEUTRAL-FLEET-DAILY-VEHICLE-CHECK-STORE-ROUTE-ACTION-OWNERSHIP-OVERLAY-REVIEW-WAVE-25",
        "status": "GO_THREE_PART_POST_COMMIT_REVIEW_COMPLETE_REPORTING_ONLY_ZERO_NEW_OR_DOWNSTREAM_CREDIT",
        "reviewed_on": "2026-08-27",
        "pins": {
            "review_checkpoint_commit": COMMIT,
            "review_checkpoint_tree": TREE,
            "producer_parent": PARENT,
            "producer_internal_checkpoint_tree": PRODUCER_BASE_TREE,
            "producer_generator": PRODUCER_GENERATOR.relative_to(ROOT).as_posix(),
            "producer_generator_sha256": GENERATOR_SHA256,
            "producer_generator_blob_id": GENERATOR_BLOB_ID,
            "producer": PRODUCER_OUTPUT.relative_to(ROOT).as_posix(),
            "producer_sha256": OUTPUT_SHA256,
            "producer_blob_id": OUTPUT_BLOB_ID,
            "materializer": materializer_rel,
            "materializer_sha256": sha256(Path(__file__).read_bytes()),
            "inputs": producer["pins"]["inputs"],
            "input_map_sha256": INPUT_MAP_SHA256,
        },
        "decision": {
            "verdict": "GO",
            "independent_reviews": 3,
            "independently_sealed_review_records": True,
            "discrepancies": 0,
            "reporting_materialization_authorized": True,
            "provisional_source_observation_reporting_authorized": True,
            "gate_4_complete": False,
        },
        "review_records": records,
        "synthesis_review": synthesis_review,
        "verified_counts": producer["combined_counts"],
        "verified_queue_accounting": producer["queue_accounting"],
        "verified_identity": producer["identity"],
        "verified_reviewer_lineage": producer["reviewer_lineage"],
        "verified_source_packet_expansion": expansion,
        "verified_provisional_assurance_observations": observations,
        "verified_noninheritance": {
            "noninheritance_boundary": producer["noninheritance_boundary"],
            "page_sibling_and_next_boundary": producer[
                "page_sibling_and_next_boundary"
            ],
            "projection_reconciliation": producer["projection_reconciliation"],
        },
        "verified_outcome_conservation": producer["outcome_conservation"],
        "verified_producer_credit_partition": {
            "positive_credit_keys": [
                "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD",
                "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION",
            ],
            "credit_boundary": producer["credit_boundary"],
            "review_does_not_recredit_producer_outcomes": True,
        },
        "credit_boundary": credit_boundary,
        "mutation_attestation": {
            "application_source_changed": False,
            "test_files_changed": False,
            "matrix_changed": False,
            "reports_changed": False,
            "dashboard_generator_changed": False,
            "dashboard_html_changed": False,
            "runtime_or_external_system_changed": False,
            "audit_artifacts_only": True,
            "whole_repository_status_scope_asserted": True,
            "independent_review_evidence_writes": 0,
            "expected_status_paths": [materializer_rel, output_rel],
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [materializer_rel, output_rel],
    }

    assert [
        key for key, value in payload["credit_boundary"].items() if value
    ] == ["INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"]
    assert payload["decision"]["reporting_materialization_authorized"] is True
    assert payload["decision"]["gate_4_complete"] is False
    assert payload["audit_completion_test_met"] is False
    encoded = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode(
        "utf-8"
    )
    assert encoded.endswith(b"\n") and b"\r\n" not in encoded
    assert not encoded.startswith(b"\xef\xbb\xbf")
    assert all(
        line.rstrip(b" \t") == line for line in encoded.splitlines()
    ), "trailing whitespace"
    OUTPUT.write_bytes(encoded)
    assert OUTPUT.read_bytes() == encoded
    assert strict_json(encoded) == payload

    expected_status = {f"?? {materializer_rel}", f"?? {output_rel}"}
    actual_status = set(git("status", "--short").splitlines())
    assert actual_status == expected_status, actual_status
    assert not list(AUDIT.rglob("__pycache__"))


if __name__ == "__main__":
    main()
