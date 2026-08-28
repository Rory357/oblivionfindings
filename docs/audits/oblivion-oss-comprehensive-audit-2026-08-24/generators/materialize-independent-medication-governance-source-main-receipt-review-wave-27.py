#!/usr/bin/env python3
"""Independently review the sealed RUN156 medication source receipt.

The review is limited to local Git/source provenance and receipt construction.
It grants no remediation, finding, execution, remote-currency, or completion
credit.
"""
from __future__ import annotations

from collections import Counter
import hashlib
import json
from pathlib import Path
import subprocess
from typing import Any
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24"
PREFIX = AUDIT.relative_to(ROOT).as_posix()
AUDIT_PREFIX = f"{PREFIX}/"

RUN_ID = "RUN-156R-INDEPENDENT-MEDICATION-GOVERNANCE-SOURCE-MAIN-RECEIPT-REVIEW-WAVE-27"
MATERIALIZER = "generators/materialize-independent-medication-governance-source-main-receipt-review-wave-27.py"
OUTPUT = "evidence/source/current-run-156r-independent-medication-governance-source-main-receipt-review-wave-27.json"
PRODUCER_GENERATOR = "generators/materialize-run-156-medication-governance-source-main-receipt-wave-27.py"
PRODUCER_RECEIPT = "evidence/source/current-run-156-medication-governance-source-main-receipt-wave-27.json"

PRODUCER_COMMIT = "33ee55b84944fab3e52eee3c3e303c4c30eb4a44"
PRODUCER_TREE = "b0559420ba2e9ebe4146cb1bbf796aa60bd79dfc"
PRODUCER_PARENT = "86b232cb14967c63ff345ac5208ec6d4c379f24f"
PRODUCER_PARENT_TREE = "5444cf4131451642b0d2d144f28f5c04dffa7445"
PRODUCER_GENERATOR_SHA256 = "e611f494567ce966e5c678a9579bb26278da0a87d814b649ccf973b102bcd4ea"
PRODUCER_GENERATOR_BLOB = "0caeb16bf63e0d6b4cd084c539a6d74c303d6cfb"
PRODUCER_GENERATOR_BYTES = 35600
PRODUCER_GENERATOR_LINES = 779
PRODUCER_RECEIPT_SHA256 = "56094f7e83acf8000d0b680d751cc3d27e8627916eef45173002b43207091e76"
PRODUCER_RECEIPT_BLOB = "38e69aa0897cc8b8f7d55363f5bc1ed491411095"
PRODUCER_RECEIPT_BYTES = 16444
PRODUCER_RECEIPT_LINES = 330
PRODUCER_NAME_STATUS_SHA256 = "e2cbd043b8f19ac5f337e77a604f04fd6b2cda82f3539d5c970a5ad73960985b"
PRODUCER_PATH_LIST_SHA256 = "24ffb750f01566ad590db79ad810ae30689e9ffb6fc23c5c55d75f02b944d7b1"
PRODUCER_NUMSTAT_SHA256 = "254f0815696ba1b40f1df468f750ea0e4e80fc356f9b7d020f5f1fa78cbe1abb"

HISTORICAL_MERGE = "cd5d34e6b8aa7e494808745041ec1dfa187dc101"
MERGE_PARENT_1 = "64d2a0814d571f583a1dda0dcf53554a8992d4b5"
EFFECTIVE_APPLICATION = "c5c0ad0903d2e2e2229d5d0090fc0a69a2206f0f"
OBSERVED_LOCAL_ORIGIN_MAIN = "20ad5cef0aacb3d055e685d2f8b7b583cb8d78f4"
NON_AUDIT_MANIFEST_SHA256 = "016f4f12e8482ec11fcfdcaaec793417df35463deb90ee49d0c806e7ca7a0ea2"
EFFECTIVE_PAYLOAD_PATH_BLOB_SHA256 = "fb0dfc61a391d93887a880426a26cf02f5cc8617396077870ec1456fe6216234"
MY_DAY_TRANSITION_SHA256 = "2577f6f8dec59baa120230aa4a8d5884e0cd01f752b744e54c360118ddbda2cc"
MED_RECORD_HASHES = {
    "MED-RBAC-01": "aa35c543ac25d15d074b344abd6ce8750975717f6c6e229d36986256c5a301ea",
    "MED-CD-SCOPE-01": "dd86bf94f3b4d894e95c56c95a9409ce803b8d82d108cdd3c42f3343e348cd21",
    "MED-CD-ATOMICITY-01": "9ba4f430ee59efea414b42a8633c1c969a2fd4428fbf3fef173fb5548cc8e7f1",
}


def run_git(*args: str, check: bool = True) -> subprocess.CompletedProcess[bytes]:
    return subprocess.run(
        ["git", *args], cwd=ROOT, check=check, capture_output=True
    )


def git_bytes(*args: str) -> bytes:
    return run_git(*args).stdout


def git(*args: str) -> str:
    return git_bytes(*args).decode("utf-8").rstrip("\r\n")


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def file_sha256(relative: str) -> str:
    return sha256_bytes((AUDIT / relative).read_bytes())


def working_blob(relative: str) -> str:
    return git("hash-object", "--", str(AUDIT / relative))


def strict_json(relative: str) -> dict[str, Any]:
    def hook(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, (relative, key)
            result[key] = value
        return result

    value = json.loads(
        (AUDIT / relative).read_text(encoding="utf-8"), object_pairs_hook=hook
    )
    assert isinstance(value, dict)
    return value


def canonical_sha256(value: Any) -> str:
    return sha256_bytes(
        json.dumps(
            value, ensure_ascii=False, sort_keys=True, separators=(",", ":")
        ).encode("utf-8")
    )


def status_lines() -> set[str]:
    return {
        line.lstrip()
        for line in git("status", "--porcelain=v1", "--untracked-files=all").splitlines()
        if line.strip()
    }


def assert_text_file(relative: str, expected_bytes: int, expected_lines: int) -> None:
    payload = (AUDIT / relative).read_bytes()
    assert len(payload) == expected_bytes
    assert payload.count(b"\n") == expected_lines
    assert payload.endswith(b"\n") and b"\r\n" not in payload
    assert not payload.startswith(b"\xef\xbb\xbf")


def producer_commit_review() -> dict[str, Any]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == PRODUCER_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == PRODUCER_TREE
    assert git("rev-parse", "HEAD^") == PRODUCER_PARENT
    assert git("rev-parse", "HEAD^^{tree}") == PRODUCER_PARENT_TREE
    assert git("show", "-s", "--format=%s", PRODUCER_COMMIT) == "docs(audit): receipt medication governance source"

    expected_paths = {
        f"{PREFIX}/{PRODUCER_GENERATOR}",
        f"{PREFIX}/{PRODUCER_RECEIPT}",
    }
    name_status = git_bytes("diff", "--name-status", PRODUCER_PARENT, PRODUCER_COMMIT)
    name_only = git_bytes("diff", "--name-only", PRODUCER_PARENT, PRODUCER_COMMIT)
    numstat = git_bytes("diff", "--numstat", PRODUCER_PARENT, PRODUCER_COMMIT)
    assert sha256_bytes(name_status) == PRODUCER_NAME_STATUS_SHA256
    assert sha256_bytes(name_only) == PRODUCER_PATH_LIST_SHA256
    assert sha256_bytes(numstat) == PRODUCER_NUMSTAT_SHA256
    status_rows = name_status.decode("utf-8").splitlines()
    assert len(status_rows) == 2
    assert all(row.startswith("A\t") for row in status_rows)
    assert {row.split("\t", 1)[1] for row in status_rows} == expected_paths
    stats = [row.split("\t") for row in numstat.decode("utf-8").splitlines()]
    assert sum(int(row[0]) for row in stats) == 1109
    assert sum(int(row[1]) for row in stats) == 0

    assert file_sha256(PRODUCER_GENERATOR) == PRODUCER_GENERATOR_SHA256
    assert working_blob(PRODUCER_GENERATOR) == PRODUCER_GENERATOR_BLOB
    assert file_sha256(PRODUCER_RECEIPT) == PRODUCER_RECEIPT_SHA256
    assert working_blob(PRODUCER_RECEIPT) == PRODUCER_RECEIPT_BLOB
    assert_text_file(
        PRODUCER_GENERATOR, PRODUCER_GENERATOR_BYTES, PRODUCER_GENERATOR_LINES
    )
    assert_text_file(PRODUCER_RECEIPT, PRODUCER_RECEIPT_BYTES, PRODUCER_RECEIPT_LINES)
    return {
        "commit": PRODUCER_COMMIT,
        "tree": PRODUCER_TREE,
        "parent": PRODUCER_PARENT,
        "parent_tree": PRODUCER_PARENT_TREE,
        "subject": "docs(audit): receipt medication governance source",
        "changed_paths": 2,
        "added_paths": 2,
        "lines_added": 1109,
        "lines_deleted": 0,
        "name_status_sha256": PRODUCER_NAME_STATUS_SHA256,
        "path_list_sha256": PRODUCER_PATH_LIST_SHA256,
        "numstat_sha256": PRODUCER_NUMSTAT_SHA256,
        "generator": {
            "path": PRODUCER_GENERATOR,
            "sha256": PRODUCER_GENERATOR_SHA256,
            "blob_id": PRODUCER_GENERATOR_BLOB,
            "bytes": PRODUCER_GENERATOR_BYTES,
            "lines": PRODUCER_GENERATOR_LINES,
        },
        "receipt": {
            "path": PRODUCER_RECEIPT,
            "sha256": PRODUCER_RECEIPT_SHA256,
            "blob_id": PRODUCER_RECEIPT_BLOB,
            "bytes": PRODUCER_RECEIPT_BYTES,
            "lines": PRODUCER_RECEIPT_LINES,
        },
        "exact_two_added_audit_files": True,
    }


def replay_producer_in_memory() -> dict[str, Any]:
    producer_path = AUDIT / PRODUCER_GENERATOR
    namespace: dict[str, Any] = {
        "__name__": "run156_sealed_replay",
        "__file__": str(producer_path),
    }
    exec(
        compile(
            producer_path.read_text(encoding="utf-8"),
            str(producer_path),
            "exec",
        ),
        namespace,
    )
    original_git = namespace["git"]

    def sealed_git(*args: str) -> str:
        if args == ("rev-parse", "HEAD"):
            return PRODUCER_PARENT
        if args == ("rev-parse", "HEAD^{tree}"):
            return PRODUCER_PARENT_TREE
        return original_git(*args)

    namespace["git"] = sealed_git
    captured: dict[str, str] = {}

    def no_mkdir(_self: Path, *args: Any, **kwargs: Any) -> None:
        return None

    def capture_write(self: Path, data: str, *args: Any, **kwargs: Any) -> int:
        assert self == AUDIT / PRODUCER_RECEIPT
        assert not captured
        captured["data"] = data
        return len(data)

    with patch.object(Path, "mkdir", no_mkdir), patch.object(
        Path, "write_text", capture_write
    ):
        namespace["write_receipt"]()

    replayed = captured["data"].encode("utf-8")
    committed = (AUDIT / PRODUCER_RECEIPT).read_bytes()
    assert replayed == committed
    assert len(replayed) == PRODUCER_RECEIPT_BYTES
    assert sha256_bytes(replayed) == PRODUCER_RECEIPT_SHA256
    return {
        "method": "IN_MEMORY_WRITE_INTERCEPT_AT_SEALED_PARENT_CHECKPOINT",
        "sealed_checkpoint_commit": PRODUCER_PARENT,
        "sealed_checkpoint_tree": PRODUCER_PARENT_TREE,
        "direct_current_head_execution_expected": False,
        "direct_current_head_execution_guard_reason": "Producer intentionally requires HEAD at its sealed parent and its two output paths untracked.",
        "filesystem_write_performed": False,
        "byte_identical": True,
        "bytes": len(replayed),
        "sha256": sha256_bytes(replayed),
    }


def topology_review(producer: dict[str, Any]) -> dict[str, Any]:
    historical = producer["historical_merge_payload"]
    first_parent_payload = historical["first_parent_payload"]
    effective = producer["effective_application_checkpoint"]
    delta = producer["post_merge_my_day_delta"]
    later = producer["later_audit_only_lineage"]
    assert first_parent_payload["paths"] == 359
    assert (first_parent_payload["added_paths"], first_parent_payload["modified_paths"]) == (87, 272)
    assert (first_parent_payload["lines_added"], first_parent_payload["lines_deleted"]) == (76238, 9031)
    assert effective["historical_merge_payload_blobs_unchanged"] == 358
    assert effective["historical_merge_payload_blobs_superseded"] == 1
    assert effective["superseded_merge_payload_paths"] == [
        "resources/js/pages/my-day/index.tsx"
    ]
    assert effective["effective_payload_path_blob_manifest_sha256"] == EFFECTIVE_PAYLOAD_PATH_BLOB_SHA256
    assert delta["path_count"] == 3
    assert (delta["lines_added"], delta["lines_deleted"]) == (38, 23)
    assert delta["transition_manifest_sha256"] == MY_DAY_TRANSITION_SHA256
    assert later["commits_after_effective_application_checkpoint"] == 3
    assert later["cumulative_changed_paths"] == 12
    assert later["all_later_paths_inside_exact_audit_root"] is True
    assert later["non_audit_tracked_entries"] == 12784
    assert later["non_audit_tree_manifest_sha256"] == NON_AUDIT_MANIFEST_SHA256
    assert later["effective_and_receipt_checkpoint_non_audit_manifests_equal"] is True

    merge_paths = git("diff", "--name-only", MERGE_PARENT_1, HISTORICAL_MERGE).splitlines()
    assert len(merge_paths) == 359
    unchanged: list[str] = []
    superseded: list[str] = []
    for path in merge_paths:
        old_blob = git("rev-parse", f"{HISTORICAL_MERGE}:{path}")
        new_blob = git("rev-parse", f"{EFFECTIVE_APPLICATION}:{path}")
        (unchanged if old_blob == new_blob else superseded).append(path)
    assert len(unchanged) == 358
    assert superseded == ["resources/js/pages/my-day/index.tsx"]
    assert all(
        path.startswith(AUDIT_PREFIX)
        for path in git(
            "diff", "--name-only", EFFECTIVE_APPLICATION, PRODUCER_PARENT
        ).splitlines()
    )
    return {
        "historical_merge_payload_paths": 359,
        "historical_merge_added_paths": 87,
        "historical_merge_modified_paths": 272,
        "historical_merge_lines_added": 76238,
        "historical_merge_lines_deleted": 9031,
        "effective_unchanged_merge_payload_blobs": 358,
        "effective_superseded_merge_payload_blobs": 1,
        "sole_superseded_path": "resources/js/pages/my-day/index.tsx",
        "post_merge_my_day_changed_paths": 3,
        "post_merge_my_day_lines_added": 38,
        "post_merge_my_day_lines_deleted": 23,
        "effective_payload_path_blob_manifest_sha256": EFFECTIVE_PAYLOAD_PATH_BLOB_SHA256,
        "post_merge_my_day_transition_manifest_sha256": MY_DAY_TRANSITION_SHA256,
        "later_audit_only_commits": 3,
        "later_audit_only_changed_paths": 12,
        "non_audit_tracked_entries": 12784,
        "non_audit_tree_manifest_sha256": NON_AUDIT_MANIFEST_SHA256,
        "effective_non_audit_source_stable_through_receipt_checkpoint": True,
        "historical_merge_tree_presented_as_effective_tree": False,
        "all_359_merge_payload_blobs_claimed_current": False,
    }


def semantic_review(producer: dict[str, Any]) -> dict[str, Any]:
    references = producer["provisional_finding_reference_boundary"]
    assert references["reference_count"] == 3
    assert references["historical_audited_application_pin"] == "a0493442b9e392d324055c35bf25b69421dc2d35"
    assert references["historical_audited_application_pin_preserved"] is True
    assert references["finding_register_mutated_by_run_156"] is False
    assert references["finding_or_priority_promotion_authorized"] is False
    records = references["records"]
    assert {row["id"] for row in records} == set(MED_RECORD_HASHES)
    assert {row["id"]: row["canonical_record_sha256"] for row in records} == MED_RECORD_HASHES
    for row in records:
        assert row["record_status"] == "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING"
        assert row["audited_application_commit"] == "a0493442b9e392d324055c35bf25b69421dc2d35"
        assert row["reference_only"] is True
        assert row["promoted_or_rebased_by_run_156"] is False
        assert row["final_finding_credit"] is False
        assert row["completion_credit"] is False

    origin = producer["local_only_origin_attestation"]
    assert origin["boundary"] == "LOCAL_REMOTE_TRACKING_OBSERVATION_ONLY"
    assert origin["observed_local_remote_tracking_ref_sha"] == OBSERVED_LOCAL_ORIGIN_MAIN
    assert (origin["local_ahead"], origin["local_behind"]) == (179, 0)
    assert origin["fetch_performed"] is False
    assert origin["remote_currency_verified"] is False
    assert origin["publication_or_push_verified"] is False
    assert [key for key, value in producer["credit_boundary"].items() if value] == [
        "GIT_SOURCE_INTEGRATION_RECEIPT"
    ]
    assert all(value is False for value in producer["completion_boundary"].values())

    current_behind, current_ahead = (
        int(value)
        for value in git(
            "rev-list", "--left-right", "--count", "refs/remotes/origin/main...HEAD"
        ).split()
    )
    assert (current_ahead, current_behind) == (180, 0)
    return {
        "provisional_record_count": 3,
        "canonical_record_sha256": MED_RECORD_HASHES,
        "audited_application_reference_commit": "a0493442b9e392d324055c35bf25b69421dc2d35",
        "all_records_reference_only_provisional": True,
        "all_record_credit_fields_false": True,
        "producer_origin_boundary": "LOCAL_REMOTE_TRACKING_OBSERVATION_ONLY",
        "producer_origin_fetch_performed": False,
        "producer_remote_currency_verified": False,
        "producer_publication_or_push_verified": False,
        "producer_checkpoint_local_ahead": 179,
        "producer_checkpoint_local_behind": 0,
        "review_checkpoint_local_ahead": current_ahead,
        "review_checkpoint_local_behind": current_behind,
        "one_ahead_delta_is_the_committed_run_156_receipt": True,
        "literal_complete_in_merge_subject_is_vcs_metadata_only": True,
        "remediation_or_finding_outcome_inherited": False,
        "artifact_completion_is_receipt_artifact_only": True,
        "audit_completion_credit": False,
    }


def make_review_record(
    review_id: str,
    reviewer_lane: str,
    review_type: str,
    evidence: dict[str, Any],
) -> dict[str, Any]:
    record = {
        "review_id": review_id,
        "reviewer_lane": reviewer_lane,
        "review_type": review_type,
        "review_evidence_collection_performed_without_writes": True,
        "cross_reviewer_coordination_disclosed": True,
        "record_materialized_by": "/root",
        "independence_boundary": "DISTINCT_REVIEW_LANE_NOT_BLIND_OR_ISOLATED",
        "verdict": "GO",
        "discrepancies": 0,
        "evidence": evidence,
    }
    record["seal_sha256"] = canonical_sha256(record)
    return record


def write_receipt() -> None:
    producer_commit = producer_commit_review()
    producer = strict_json(PRODUCER_RECEIPT)
    assert producer["pins"]["materializer_sha256"] == PRODUCER_GENERATOR_SHA256
    assert producer["pins"]["materializer_blob_id"] == PRODUCER_GENERATOR_BLOB
    replay = replay_producer_in_memory()
    topology = topology_review(producer)
    semantic = semantic_review(producer)
    reviews = [
        make_review_record(
            "RUN156R-REVIEW-MECHANICAL-PROVENANCE",
            "/root/run154_builder_review",
            "MECHANICAL_PROVENANCE_AND_IN_MEMORY_REPLAY",
            {"producer_commit": producer_commit, "sealed_replay": replay},
        ),
        make_review_record(
            "RUN156R-REVIEW-TOPOLOGY-BLOB",
            "/root",
            "TWO_CHECKPOINT_TOPOLOGY_AND_BLOB_RECONSTRUCTION",
            topology,
        ),
        make_review_record(
            "RUN156R-REVIEW-SEMANTIC-BOUNDARY",
            "/root/run154_surface_review",
            "PROVISIONAL_ORIGIN_AND_ZERO_CREDIT_BOUNDARY",
            semantic,
        ),
    ]
    assert len({row["reviewer_lane"] for row in reviews}) == 3
    assert all(row["verdict"] == "GO" and row["discrepancies"] == 0 for row in reviews)

    false_credit = (
        "git_source_integration_receipt_recredit",
        "application_source_mutation",
        "remediation_or_defect_closure",
        "finding",
        "final_finding",
        "priority_promotion",
        "runtime",
        "database",
        "build",
        "application_browser",
        "responsive_application",
        "visual_or_workflow",
        "executed_tests",
        "test_coverage",
        "coverage_completion",
        "benchmark_mapping",
        "final_no_match_or_NCM",
        "origin_currency_correctness",
        "origin_currency_coverage",
        "remote_currency",
        "publication_or_push",
        "ease",
        "release",
        "pass",
        "feature_completion",
        "completion",
        "audit_complete",
    )
    receipt = {
        "schema_version": "run-156r-independent-medication-governance-source-main-receipt-review-wave-27-v1",
        "run_id": RUN_ID,
        "generated_on": "2026-08-29",
        "status": "GO_THREE_PART_TWO_CHECKPOINT_SOURCE_RECEIPT_REVIEW_REPORTING_ONLY_ZERO_REMEDIATION_OR_DOWNSTREAM_CREDIT",
        "architecture_rule": {
            "operating_organisations": 1,
            "multiple_sites": True,
            "multi_tenant": False,
            "authorization_boundary": "APPROVED_SITES_ROLES_PERMISSIONS_CANONICAL_OWNERSHIP_DIRECT_OBJECT_CONCEALMENT_PRIVACY",
        },
        "scope": "Three distinct read-only review lanes checked the committed RUN156 local Git/source receipt; the reviews were coordinated rather than blind or isolated, and no medication finding or remediation outcome is selected.",
        "pins": {
            "review_checkpoint_commit": PRODUCER_COMMIT,
            "review_checkpoint_tree": PRODUCER_TREE,
            "producer_generation_checkpoint_commit": PRODUCER_PARENT,
            "producer_generation_checkpoint_tree": PRODUCER_PARENT_TREE,
            "producer_generator": producer_commit["generator"],
            "producer_receipt": producer_commit["receipt"],
            "review_materializer": MATERIALIZER,
            "review_materializer_sha256": file_sha256(MATERIALIZER),
            "review_materializer_blob_id": working_blob(MATERIALIZER),
        },
        "producer_commit_review": producer_commit,
        "review_process_disclosure": {
            "review_record_materializer": "/root",
            "review_records_materialized_by_one_generator": True,
            "reviewer_evidence_lanes": [
                "/root/run154_builder_review",
                "/root",
                "/root/run154_surface_review",
            ],
            "cross_reviewer_coordination_occurred": True,
            "blind_or_isolated_reviews_claimed": False,
            "independence_boundary": "DISTINCT_READ_ONLY_REVIEW_LANES_WITH_COORDINATION_DISCLOSED",
        },
        "review_records": reviews,
        "decision": {
            "verdict": "GO",
            "independent_reviews": 3,
            "distinct_reviewer_lanes": 3,
            "blind_or_isolated_reviews": False,
            "discrepancies": 0,
            "reporting_materialization_authorized": True,
            "gate_4_complete": False,
            "audit_complete": False,
        },
        "noninheritance_boundary": {
            "historical_merge_completion_subject_inherited_as_outcome": False,
            "all_359_merge_payload_blobs_claimed_current": False,
            "my_day_fix_recredited_as_medication_remediation": False,
            "provisional_records_promoted": False,
            "producer_artifact_completion_inherited_as_audit_completion": False,
            "producer_local_origin_observation_inherited_as_remote_currency": False,
        },
        "mutation_attestation": {
            "run_156r_writes_only_generator_and_receipt": True,
            "producer_files_changed": False,
            "application_source_changed": False,
            "tests_or_coverage_executed": False,
            "browser_or_runtime_started": False,
            "database_or_external_system_changed": False,
            "findings_reports_dashboard_matrix_or_register_changed": False,
        },
        "credit_boundary": {
            "INDEPENDENT_SOURCE_RECEIPT_REVIEW_FOR_REPORTING": True,
            **{key: False for key in false_credit},
        },
        "completion_boundary": {
            key: False
            for key in (
                "semantic_assurance_complete",
                "execution_complete",
                "coverage_complete",
                "benchmark_complete",
                "pass_8_complete",
                "final_reconciliation_complete",
                "no_live_agent_gate_complete",
                "gate_4_complete",
                "audit_complete",
            )
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [f"{PREFIX}/{MATERIALIZER}", f"{PREFIX}/{OUTPUT}"],
    }
    output = AUDIT / OUTPUT
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(
        json.dumps(receipt, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def validate_output() -> dict[str, Any]:
    receipt = strict_json(OUTPUT)
    assert receipt["run_id"] == RUN_ID
    assert receipt["pins"]["review_materializer_sha256"] == file_sha256(MATERIALIZER)
    assert receipt["pins"]["review_materializer_blob_id"] == working_blob(MATERIALIZER)
    assert receipt["decision"] == {
        "verdict": "GO",
        "independent_reviews": 3,
        "distinct_reviewer_lanes": 3,
        "blind_or_isolated_reviews": False,
        "discrepancies": 0,
        "reporting_materialization_authorized": True,
        "gate_4_complete": False,
        "audit_complete": False,
    }
    assert len({row["reviewer_lane"] for row in receipt["review_records"]}) == 3
    for row in receipt["review_records"]:
        seal = row.pop("seal_sha256")
        assert seal == canonical_sha256(row)
        row["seal_sha256"] = seal
    assert [key for key, value in receipt["credit_boundary"].items() if value] == [
        "INDEPENDENT_SOURCE_RECEIPT_REVIEW_FOR_REPORTING"
    ]
    assert all(value is False for value in receipt["completion_boundary"].values())
    for relative in (MATERIALIZER, OUTPUT):
        payload = (AUDIT / relative).read_bytes()
        assert payload.endswith(b"\n") and b"\r\n" not in payload
        assert not payload.startswith(b"\xef\xbb\xbf")
    expected = {f"?? {PREFIX}/{MATERIALIZER}", f"?? {PREFIX}/{OUTPUT}"}
    assert status_lines() == expected
    assert not list(AUDIT.rglob("__pycache__"))
    return receipt


def main() -> None:
    current = status_lines()
    allowed = {f"?? {PREFIX}/{MATERIALIZER}", f"?? {PREFIX}/{OUTPUT}"}
    assert current <= allowed
    write_receipt()
    receipt = validate_output()
    print(
        json.dumps(
            {
                "status": receipt["status"],
                "materializer_sha256": file_sha256(MATERIALIZER),
                "receipt_sha256": file_sha256(OUTPUT),
                "independent_reviews": 3,
                "discrepancies": 0,
                "reporting_materialization_authorized": True,
                "gate_4_complete": False,
                "audit_complete": False,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
