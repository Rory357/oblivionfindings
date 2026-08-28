#!/usr/bin/env python3
"""Independently review the immutable RUN153 vehicle-index overlay."""
from __future__ import annotations

import ast
import hashlib
import json
from pathlib import Path
import subprocess
from typing import Any


ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24"
AUDIT_REL = AUDIT.relative_to(ROOT).as_posix()
PRODUCER_GENERATOR = AUDIT / "generators/integrate-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.py"
PRODUCER_OUTPUT = AUDIT / "evidence/source/current-run-153-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.json"
OUTPUT = AUDIT / "evidence/source/current-run-153r-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.json"

PRODUCER_COMMIT = "8c179fe14840ff1093c455bfb943f3c9ba60e59b"
PRODUCER_TREE = "2ad599448435f2eacdabc0cc0ee2b95d98dfe817"
PRODUCER_PARENT = "12ac4a435deceb364ad0f23e97fad0677dfa1d1c"
PRODUCER_PARENT_TREE = "dfead8751310ceed65566e8c1148cfd1061056fd"
REVIEW_CHECKPOINT = "c5c0ad0903d2e2e2229d5d0090fc0a69a2206f0f"
REVIEW_CHECKPOINT_TREE = "4d5bb5f8106e49568fd7a9d2a067f46505c29ea5"
GENERATOR_SHA256 = "00b90c5932614eaf67cbca29c860924fad67190605bbf476fdc285174831ea83"
GENERATOR_BLOB_ID = "c46a48c87203410951715006c8253c84851e9d76"
OUTPUT_SHA256 = "9b7e382f83787d807de8d752ecb3e6524280c707899aba78d47082765272e815"
OUTPUT_BLOB_ID = "818b891cff9965193c60d83d0580c21a48d1a682"
INPUT_MAP_SHA256 = "6c44f09f8e9fb21427bd1957907901c22557a6e6ce1f6925979a62b942378172"

SECTION_SHA256 = {
    "combined_counts": "3062c4b905dac9ef15c9cbafa9604a3ae243f8e1ac05aeb94bc64537c3981425",
    "queue_accounting": "1c2bcdade0c93af0bccdc40f0855e9e8d288022aa962e80181d079ddea52c336",
    "identity": "ad29336ca5fe61c84ab136d5a12dc7c98f63d88fdd76db931050986187f51145",
    "reviewer_lineage": "622aade4f51b3831c832da567e9ee88eaaaf4df5f748daef91a7e5d6c4ccde11",
    "source_packet_boundary": "0cb962e7ccf02609a8f2e359db9a29706f9b3f0d7093a271c779c8009df8c31e",
    "provisional_assurance_observation_preservation": "71d442e8d5eb4faa38b3a2e165802e912c255d536e61daa95d83f8b566098da1",
    "queue_boundary": "036cbbc845ce6e0fd673b8f178bce7f3a7aabc494fa414de52a1d572fd8ad7b9",
    "noninheritance_boundary": "79a83eb10baf60cbeb17e547154f4bad15336ed5be1788002405e628ca82f131",
    "overlay_source_records": "5d7d88fb45e1bd416b0f78032b74c5ffc470e42cc4431f75e230fdebb4925624",
    "new_static_controller_action_bridges": "3a9909de6aefe0cecaf00eddf41309da8c2932989507b92f6a3ee050ec9db4a9",
    "outcome_conservation": "172435d4a482f70df780df96814b2a02b4a4774bdc5ef0c964a62094f95b46a4",
    "credit_boundary": "585143d2fabdfeed27a3023aabbe9c07799c8d38d7d68bffaf8d7d44515dacc8",
}

EXPECTED_COUNTS = {
    "source_owner_records": 664,
    "route_owner_records": 307,
    "page_owner_records": 357,
    "distinct_feature_ids": 256,
    "distinct_H_feature_ids": 234,
    "distinct_D_feature_ids": 22,
    "route_distinct_feature_ids": 64,
    "page_distinct_feature_ids": 242,
    "route_page_feature_overlap": 50,
    "static_controller_action_bridges": 95,
    "bounded_static_source_denominator": 3929,
    "bounded_static_source_ownership_percent": "16.899975",
    "bounded_static_source_residual_records": 3265,
    "residual_explicit_unmapped_routes": 2894,
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
    "reviewed_queue_surface_rows": 118,
    "owner_queue_surface_rows": 96,
    "shared_queue_surface_rows": 10,
    "alias_queue_surface_rows": 5,
    "dead_queue_surface_rows": 0,
    "evidence_gap_queue_surface_rows": 7,
    "pending_unreviewed_queue_surface_rows": 389,
    "queue_surfaces_without_ownership": 411,
    "new_reviewed_route_surface_rows": 1,
    "new_owner_route_surface_rows": 1,
}


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def canonical_sha256(value: Any) -> str:
    return sha256(
        json.dumps(
            value, ensure_ascii=False, sort_keys=True, separators=(",", ":")
        ).encode("utf-8")
    )


def strict_object(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    assert len(pairs) == len({key for key, _ in pairs}), "duplicate JSON key"
    return dict(pairs)


def strict_json(raw: bytes) -> dict[str, Any]:
    value = json.loads(raw, object_pairs_hook=strict_object)
    assert isinstance(value, dict)
    return value


def git(*args: str) -> str:
    return subprocess.run(
        ["git", *args], cwd=ROOT, check=True, text=True, capture_output=True
    ).stdout.rstrip("\r\n")


def sealed(record: dict[str, Any]) -> dict[str, Any]:
    return {"record": record, "record_sha256": canonical_sha256(record)}


def assert_self_seal(record: dict[str, Any], field: str) -> None:
    assert record[field] == canonical_sha256(
        {key: value for key, value in record.items() if key != field}
    )


def replay_producer_in_memory() -> bytes:
    source = PRODUCER_GENERATOR.read_text(encoding="utf-8")
    marker = '\nif __name__ == "__main__":\n    main()\n'
    assert marker in source
    namespace: dict[str, Any] = {
        "__name__": "run153_in_memory_replay",
        "__file__": str(PRODUCER_GENERATOR),
    }
    exec(compile(source.replace(marker, "\n"), str(PRODUCER_GENERATOR), "exec"), namespace)

    captured: dict[str, bytes] = {}
    real_run = subprocess.run
    real_write_text = Path.write_text
    real_read_text = Path.read_text
    real_read_bytes = Path.read_bytes

    def replay_run(args, *positional, **kwargs):
        command = list(args)
        fake: bytes | None = None
        if command == ["git", "rev-parse", "HEAD"]:
            fake = (PRODUCER_PARENT + "\n").encode()
        elif command == ["git", "rev-parse", "HEAD^{tree}"]:
            fake = (PRODUCER_PARENT_TREE + "\n").encode()
        elif command == ["git", "branch", "--show-current"]:
            fake = b"main\n"
        elif command == [
            "git", "status", "--porcelain", "--", "app", "routes",
            "resources/js", "tests", "database",
        ]:
            fake = b""
        elif command == ["git", "status", "--porcelain"]:
            fake = (
                f"?? {PRODUCER_GENERATOR.relative_to(ROOT).as_posix()}\n"
                f"?? {PRODUCER_OUTPUT.relative_to(ROOT).as_posix()}\n"
            ).encode()
        if fake is not None:
            return subprocess.CompletedProcess(args, 0, stdout=fake, stderr=b"")
        return real_run(args, *positional, **kwargs)

    def memory_write_text(path: Path, data: str, *args, **kwargs) -> int:
        assert path.resolve() == PRODUCER_OUTPUT.resolve()
        captured["output"] = data.encode(kwargs.get("encoding") or "utf-8")
        return len(data)

    def memory_read_text(path: Path, *args, **kwargs) -> str:
        if path.resolve() == PRODUCER_OUTPUT.resolve() and "output" in captured:
            return captured["output"].decode(kwargs.get("encoding") or "utf-8")
        return real_read_text(path, *args, **kwargs)

    def memory_read_bytes(path: Path) -> bytes:
        if path.resolve() == PRODUCER_OUTPUT.resolve() and "output" in captured:
            return captured["output"]
        return real_read_bytes(path)

    subprocess.run = replay_run
    Path.write_text = memory_write_text
    Path.read_text = memory_read_text
    Path.read_bytes = memory_read_bytes
    try:
        namespace["main"]()
    finally:
        subprocess.run = real_run
        Path.write_text = real_write_text
        Path.read_text = real_read_text
        Path.read_bytes = real_read_bytes
    assert "output" in captured
    return captured["output"]


def main() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == REVIEW_CHECKPOINT
    assert git("show", "-s", "--format=%T", "HEAD") == REVIEW_CHECKPOINT_TREE
    subprocess.run(
        ["git", "merge-base", "--is-ancestor", PRODUCER_COMMIT, REVIEW_CHECKPOINT],
        cwd=ROOT,
        check=True,
    )
    assert not git("diff", "--name-only", f"{PRODUCER_COMMIT}..{REVIEW_CHECKPOINT}", "--", AUDIT_REL)

    generator_raw = PRODUCER_GENERATOR.read_bytes()
    producer_raw = PRODUCER_OUTPUT.read_bytes()
    assert sha256(generator_raw) == GENERATOR_SHA256
    assert sha256(producer_raw) == OUTPUT_SHA256
    assert len(generator_raw) == 23969
    assert len(generator_raw.decode("utf-8").splitlines()) == 218
    assert len(producer_raw) == 35844
    assert len(producer_raw.decode("utf-8").splitlines()) == 614
    for raw in (generator_raw, producer_raw):
        assert raw.endswith(b"\n") and b"\r\n" not in raw
        assert not raw.startswith(b"\xef\xbb\xbf")

    producer_generator_rel = PRODUCER_GENERATOR.relative_to(ROOT).as_posix()
    producer_output_rel = PRODUCER_OUTPUT.relative_to(ROOT).as_posix()
    assert git("show", "-s", "--format=%T", PRODUCER_COMMIT) == PRODUCER_TREE
    assert git("rev-parse", f"{PRODUCER_COMMIT}^") == PRODUCER_PARENT
    assert git("show", "-s", "--format=%T", PRODUCER_PARENT) == PRODUCER_PARENT_TREE
    assert git("rev-parse", f"{PRODUCER_COMMIT}:{producer_generator_rel}") == GENERATOR_BLOB_ID
    assert git("rev-parse", f"{PRODUCER_COMMIT}:{producer_output_rel}") == OUTPUT_BLOB_ID
    assert git("rev-parse", f"HEAD:{producer_generator_rel}") == GENERATOR_BLOB_ID
    assert git("rev-parse", f"HEAD:{producer_output_rel}") == OUTPUT_BLOB_ID
    expected_diff = sorted([f"A\t{producer_generator_rel}", f"A\t{producer_output_rel}"])
    diff_rows = sorted(
        git("show", "--format=", "--name-status", "--no-renames", PRODUCER_COMMIT).splitlines()
    )
    assert diff_rows == expected_diff

    producer = strict_json(producer_raw)
    assert producer["pins"]["checkpoint_commit"] == PRODUCER_PARENT
    assert producer["pins"]["checkpoint_tree"] == PRODUCER_PARENT_TREE
    assert producer["pins"]["generator_sha256"] == GENERATOR_SHA256
    assert producer["pins"]["generator_blob_id"] == GENERATOR_BLOB_ID
    assert len(producer["pins"]["inputs"]) == 10
    assert canonical_sha256(producer["pins"]["inputs"]) == INPUT_MAP_SHA256
    for relative_path, expected_sha in producer["pins"]["inputs"].items():
        assert sha256((AUDIT / relative_path).read_bytes()) == expected_sha, relative_path
    for key, expected_sha in SECTION_SHA256.items():
        assert canonical_sha256(producer[key]) == expected_sha, key

    assert producer["combined_counts"] == EXPECTED_COUNTS
    assert producer["queue_accounting"] == EXPECTED_QUEUE
    counts = producer["combined_counts"]
    queue = producer["queue_accounting"]
    assert counts["source_owner_records"] == counts["route_owner_records"] + counts["page_owner_records"]
    assert counts["bounded_static_source_denominator"] == counts["source_owner_records"] + counts["bounded_static_source_residual_records"]
    assert counts["distinct_feature_ids"] == counts["route_distinct_feature_ids"] + counts["page_distinct_feature_ids"] - counts["route_page_feature_overlap"]
    assert queue["direct_exact_queue_records"] == queue["reviewed_queue_surface_rows"] + queue["pending_unreviewed_queue_surface_rows"]
    assert queue["reviewed_queue_surface_rows"] == sum(
        queue[key] for key in (
            "owner_queue_surface_rows", "shared_queue_surface_rows",
            "alias_queue_surface_rows", "dead_queue_surface_rows",
            "evidence_gap_queue_surface_rows",
        )
    )
    assert queue["queue_surfaces_without_ownership"] == queue["pending_unreviewed_queue_surface_rows"] + queue["shared_queue_surface_rows"] + queue["alias_queue_surface_rows"] + queue["dead_queue_surface_rows"] + queue["evidence_gap_queue_surface_rows"]

    lineage = producer["reviewer_lineage"]
    reviewers = lineage["independent_candidate_reviews"]
    assert len(reviewers) == 2
    for review in reviewers:
        assert_self_seal(review, "review_record_sha256")
    assert_self_seal(lineage["synthesis_review"], "synthesis_record_sha256")
    assert_self_seal(lineage["action_decision"], "decision_record_sha256")
    assert [review["review_record_sha256"] for review in reviewers] == [
        "6b32e5f6b388858216d94eb23933e6fee9faf509a2373730b4db84d160650292",
        "37126f001e5dd1c0a5a8a6b8a854d74309e1df3fb776da63afe0d7ac78d3cae7",
    ]
    assert lineage["synthesis_review"]["synthesis_record_sha256"] == "79658aa2ed0f4c9ebeaaf6277a25117caa36f4617818c49fa7bdcf562c3e8e70"
    assert lineage["action_decision"]["decision_record_sha256"] == "5608a702268ab10f5bf74de2f75cdf4c19a91785d1790f908441c11730bc47ed"

    observations = producer["provisional_assurance_observation_preservation"]
    assert observations["observation_count"] == len(observations["observations"]) == 6
    for observation in observations["observations"]:
        assert observation["status"] == "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING"
        assert observation["correctness_credit_authorized"] is False
        assert observation["final_finding_credit_authorized"] is False
        assert_self_seal(observation, "observation_record_sha256")
    assert len(producer["overlay_source_records"]) == 1
    assert len(producer["new_static_controller_action_bridges"]) == 1
    assert_self_seal(producer["overlay_source_records"][0], "overlay_row_sha256")
    assert_self_seal(producer["new_static_controller_action_bridges"][0], "bridge_row_sha256")
    assert producer["overlay_source_records"][0]["overlay_row_sha256"] == "24f25dc0239b4d6c19b98a0be9bb59229652d7f6c5611164a460cfe468aeefc8"
    assert producer["new_static_controller_action_bridges"][0]["bridge_row_sha256"] == "3fdac0897d7565ad7d7f6e99174565e67253cd3e92c91493b3b47565d555dcb3"

    boundary = producer["queue_boundary"]
    assert boundary["preceding_index_80_not_recredited"] is True
    assert boundary["index_82_reviewed_context_not_recredited"] is True
    assert boundary["selected_index_81_integrated"] is True
    assert (boundary["next_unresolved_index"], boundary["next_unresolved_queue_id"]) == (83, "RUN090-ROUTE-0084")
    noninheritance = producer["noninheritance_boundary"]
    assert noninheritance["page_owner_not_inherited_or_recredited"] is True
    assert noninheritance["historical_sentinel_preserved_not_rewritten_or_credited"] is True
    assert noninheritance["neighbor_identity_or_outcome_not_inherited"] is True
    assert "one operating organisation" in producer["architecture_rule"].lower()
    assert "approved Sites" in producer["architecture_rule"]
    positive_producer_credit = [
        key for key, value in producer["credit_boundary"].items() if value
    ]
    assert positive_producer_credit == [
        "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD",
        "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION",
    ]
    assert producer["audit_completion_test_met"] is False

    replayed = replay_producer_in_memory()
    assert replayed == producer_raw
    assert sha256(replayed) == OUTPUT_SHA256
    ast.parse(generator_raw.decode("utf-8"))
    ast.parse(Path(__file__).read_text(encoding="utf-8"))

    reviews = [
        sealed({
            "review_id": "RUN153R-INDEPENDENT-MECHANICAL-PROVENANCE-REPLAY-REVIEW",
            "reviewer_task_path": "/root/fleet_reporting_pattern_research",
            "review_scope": "HISTORICAL_COMMIT_PROVENANCE_FORMAT_INPUTS_AND_IN_MEMORY_REPLAY",
            "reviewed_on": "2026-08-29", "verdict": "GO", "confidence": "HIGH",
            "producer_commit": PRODUCER_COMMIT, "producer_tree": PRODUCER_TREE,
            "producer_parent": PRODUCER_PARENT, "producer_parent_tree": PRODUCER_PARENT_TREE,
            "review_checkpoint_commit": REVIEW_CHECKPOINT,
            "producer_is_review_checkpoint_ancestor": True,
            "audit_subtree_committed_drift_since_producer": 0,
            "commit_diff": {"files": 2, "name_status": diff_rows, "audit_artifacts_only": True},
            "generator": {"sha256": GENERATOR_SHA256, "blob_id": GENERATOR_BLOB_ID, "bytes": 23969, "lines": 218},
            "output": {"sha256": OUTPUT_SHA256, "blob_id": OUTPUT_BLOB_ID, "bytes": 35844, "lines": 614, "strict_json": True, "lf_no_bom_terminal_lf": True},
            "inputs": {"count": 10, "map_sha256": INPUT_MAP_SHA256, "all_current_sha256s_match": True},
            "replay": {"mode": "IN_MEMORY_WRITE_INTERCEPT", "byte_identical": True, "sha256": sha256(replayed)},
            "reviewer_wrote_files": False, "wrote_files": [],
        }),
        sealed({
            "review_id": "RUN153R-INDEPENDENT-LINEAGE-IDENTITY-SEAL-REVIEW",
            "reviewer_task_path": "/root/run153_lineage_review",
            "review_scope": "RUN152_RUN152R_LINEAGE_IDENTITY_AND_ALL_EMBEDDED_SEALS",
            "reviewed_on": "2026-08-29", "verdict": "GO", "confidence": "HIGH",
            "producer_commit": PRODUCER_COMMIT,
            "candidate_record_sha256": "08f334132340f905b012aea8f45be46ca2248e83c7eb05ecd1247e4d47e50321",
            "independent_review_record_sha256s": [item["review_record_sha256"] for item in reviewers],
            "synthesis_record_sha256": lineage["synthesis_review"]["synthesis_record_sha256"],
            "decision_record_sha256": lineage["action_decision"]["decision_record_sha256"],
            "identity_sha256": SECTION_SHA256["identity"],
            "reviewer_lineage_sha256": SECTION_SHA256["reviewer_lineage"],
            "source_packet_boundary_sha256": SECTION_SHA256["source_packet_boundary"],
            "all_embedded_self_seals_valid": True,
            "nonblinding_disclosure_preserved_by_exact_reviewer_records": True,
            "reviewer_wrote_files": False, "wrote_files": [],
        }),
        sealed({
            "review_id": "RUN153R-INDEPENDENT-ACCOUNTING-SITE-NONINHERITANCE-REVIEW",
            "reviewer_task_path": "/root/run153r_pattern_research",
            "review_scope": "COUNT_QUEUE_SITE_BOUNDARY_NONINHERITANCE_AND_CREDIT_PARTITION",
            "reviewed_on": "2026-08-29", "verdict": "GO", "confidence": "HIGH",
            "producer_commit": PRODUCER_COMMIT,
            "verified_totals": {"source_owners": 664, "route_owners": 307, "page_owners": 357, "controller_action_bridges": 95, "reviewed_queue_rows": 118, "pending_queue_rows": 389, "queue_rows_without_ownership": 411},
            "combined_counts_sha256": SECTION_SHA256["combined_counts"],
            "queue_accounting_sha256": SECTION_SHA256["queue_accounting"],
            "queue_boundary_sha256": SECTION_SHA256["queue_boundary"],
            "noninheritance_sha256": SECTION_SHA256["noninheritance_boundary"],
            "provisional_observations": 6,
            "single_organisation_multi_site_boundary_preserved": True,
            "producer_positive_credit_keys": positive_producer_credit,
            "review_adds_no_source_owner_bridge_page_correctness_or_downstream_credit": True,
            "reviewer_wrote_files": False, "wrote_files": [],
        }),
    ]
    assert len({review["record_sha256"] for review in reviews}) == 3
    assert len({review["record"]["reviewer_task_path"] for review in reviews}) == 3

    synthesis = {
        "review_id": "RUN153R-THREE-PART-POST-COMMIT-SYNTHESIS",
        "reviewer_task_path": "/root",
        "verdict": "GO",
        "accepted_record_sha256s": [review["record_sha256"] for review in reviews],
        "independently_sealed_review_records": True,
        "distinct_reviewer_task_paths": True,
        "blinded_reviews": False,
        "nonblinding_disclosure_preserved": True,
        "independent_evidence_recomputation": True,
        "discrepancies": 0,
        "reporting_materialization_authorized": True,
        "provisional_observations_may_be_reported_as_provisional_only": True,
        "new_ownership_or_bridge_credit": False,
        "page_site_permission_privacy_direct_object_or_correctness_credit": False,
        "runtime_browser_test_benchmark_final_ncm_or_completion_credit": False,
        "reviewer_wrote_files": False,
    }
    synthesis["synthesis_record_sha256"] = canonical_sha256(synthesis)

    false_credit = {key: False for key in (
        "new_source_ownership", "new_route_ownership", "new_page_ownership",
        "new_controller_action_bridge", "direct_exact_queue_review",
        "current_overlay_ownership_credit", "prior_or_neighbor_context_inherited_or_recredited",
        "frontend_caller_ownership", "next_queue_record_selected_or_credited",
        "complete_route_page_feature_crosswalk", "framework_route_reachability",
        "canonical_object_ownership_correctness", "approved_site_scope_correctness",
        "permission_correctness", "privacy_correctness", "direct_object_correctness",
        "query_projection_correctness", "runtime", "database", "build",
        "application_browser", "responsive_application", "visual_application_workflow",
        "executed_tests", "application_source_mutation", "matrix_mutation",
        "benchmark", "final_no_match_or_NCM", "ease", "release", "pass",
        "final_finding", "feature_completion", "completion", "gate_4", "audit_complete",
    )}
    credit_boundary = {"INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING": True, **false_credit}
    materializer_rel = Path(__file__).resolve().relative_to(ROOT).as_posix()
    output_rel = OUTPUT.relative_to(ROOT).as_posix()
    payload = {
        "schema_version": "run-153r-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26-v1",
        "run_id": "RUN-153R-INDEPENDENT-REVIEWED-OUTCOME-NEUTRAL-FLEET-VEHICLE-REGISTER-INDEX-ROUTE-ACTION-OWNERSHIP-OVERLAY-REVIEW-WAVE-26",
        "status": "GO_THREE_PART_POST_COMMIT_REVIEW_COMPLETE_REPORTING_ONLY_ZERO_NEW_OR_DOWNSTREAM_CREDIT",
        "reviewed_on": "2026-08-29",
        "pins": {
            "review_checkpoint_commit": REVIEW_CHECKPOINT,
            "review_checkpoint_tree": REVIEW_CHECKPOINT_TREE,
            "producer_commit": PRODUCER_COMMIT,
            "producer_tree": PRODUCER_TREE,
            "producer_parent": PRODUCER_PARENT,
            "producer_internal_checkpoint_tree": PRODUCER_PARENT_TREE,
            "producer_is_review_checkpoint_ancestor": True,
            "producer_generator": producer_generator_rel,
            "producer_generator_sha256": GENERATOR_SHA256,
            "producer_generator_blob_id": GENERATOR_BLOB_ID,
            "producer": producer_output_rel,
            "producer_sha256": OUTPUT_SHA256,
            "producer_blob_id": OUTPUT_BLOB_ID,
            "materializer": materializer_rel,
            "materializer_sha256": sha256(Path(__file__).read_bytes()),
            "inputs": producer["pins"]["inputs"],
            "input_map_sha256": INPUT_MAP_SHA256,
        },
        "architecture_rule": producer["architecture_rule"],
        "decision": {"verdict": "GO", "independent_reviews": 3, "independently_sealed_review_records": True, "discrepancies": 0, "reporting_materialization_authorized": True, "provisional_source_observation_reporting_authorized": True, "gate_4_complete": False},
        "review_records": reviews,
        "synthesis_review": synthesis,
        "verified_counts": counts,
        "verified_queue_accounting": queue,
        "verified_identity": producer["identity"],
        "verified_reviewer_lineage": lineage,
        "verified_source_packet_boundary": producer["source_packet_boundary"],
        "verified_provisional_assurance_observations": observations,
        "verified_queue_boundary": boundary,
        "verified_noninheritance": noninheritance,
        "verified_outcome_conservation": producer["outcome_conservation"],
        "verified_producer_credit_partition": {"positive_credit_keys": positive_producer_credit, "credit_boundary": producer["credit_boundary"], "review_does_not_recredit_producer_outcomes": True},
        "credit_boundary": credit_boundary,
        "mutation_attestation": {"application_source_changed": False, "test_files_changed": False, "matrix_changed": False, "reports_changed": False, "dashboard_generator_changed": False, "dashboard_html_changed": False, "runtime_or_external_system_changed": False, "audit_artifacts_only": True, "whole_repository_status_scope_asserted": True, "independent_review_evidence_writes": 0, "expected_status_paths": [materializer_rel, output_rel]},
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [materializer_rel, output_rel],
    }
    assert [key for key, value in payload["credit_boundary"].items() if value] == ["INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"]
    encoded = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    assert encoded.endswith(b"\n") and b"\r\n" not in encoded
    assert not encoded.startswith(b"\xef\xbb\xbf")
    assert all(line.rstrip(b" \t") == line for line in encoded.splitlines())
    OUTPUT.write_bytes(encoded)
    assert OUTPUT.read_bytes() == encoded
    assert strict_json(encoded) == payload
    expected_status = {f"?? {materializer_rel}", f"?? {output_rel}"}
    assert set(git("status", "--short").splitlines()) == expected_status
    assert not list(AUDIT.rglob("__pycache__"))
    print(json.dumps({"status": payload["status"], "materializer_sha256": payload["pins"]["materializer_sha256"], "receipt_sha256": sha256(encoded), "review_record_sha256s": [review["record_sha256"] for review in reviews]}, indent=2))


if __name__ == "__main__":
    main()
