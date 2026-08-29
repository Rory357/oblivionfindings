#!/usr/bin/env python3
"""Materialize the independent exact-artifact review of RUN159."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import subprocess
from typing import Any


ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24"
PREFIX = AUDIT.relative_to(ROOT).as_posix()

RUN_ID = "RUN-159R-INDEPENDENT-MED-RBAC-01-ADJUDICATION-RECEIPT-REVIEW-WAVE-28"
STATUS = "GO_EXACT_RUN159_ARTIFACT_REVIEW_RETIREMENT_REPORTING_AUTHORIZED_ZERO_DOWNSTREAM_CREDIT"
APPLICATION_COMMIT = "4f57ad4202df90ded375961437879822a908627b"
APPLICATION_TREE = "ee79b8d2733d09da2fd97992ac2a04e862159505"
PRODUCER_GENERATOR = "generators/materialize-run-159-med-rbac-already-fixed-adjudication-wave-28.py"
PRODUCER_RECEIPT = "evidence/runtime/current-run-159-med-rbac-already-fixed-adjudication-wave-28.json"
PRODUCER_GENERATOR_SHA256 = "cfd37697847c57a5e8116adb5836945daf21208fb00d0885abf7f3d594379ae7"
PRODUCER_RECEIPT_SHA256 = "bc666ded05774b03b849743436cec47cbdb260c8ab763cf502e71c804af7fd8e"
MATERIALIZER = "generators/materialize-independent-run-159-med-rbac-adjudication-review-wave-28.py"
OUTPUT = "evidence/runtime/current-run-159r-independent-med-rbac-adjudication-review-wave-28.json"


def run_git(*args: str, check: bool = True) -> subprocess.CompletedProcess[bytes]:
    return subprocess.run(["git", *args], cwd=ROOT, check=check, capture_output=True)


def git(*args: str) -> str:
    return run_git(*args).stdout.decode("utf-8").rstrip("\r\n")


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def canonical_sha256(value: Any) -> str:
    return sha256_bytes(
        json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
    )


def strict_json(path: Path) -> dict[str, Any]:
    def hook(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        value: dict[str, Any] = {}
        for key, item in pairs:
            assert key not in value, (path, key)
            value[key] = item
        return value

    result = json.loads(path.read_bytes(), object_pairs_hook=hook)
    assert isinstance(result, dict)
    return result


def file_record(relative: str) -> dict[str, Any]:
    payload = (AUDIT / relative).read_bytes()
    assert payload.endswith(b"\n") and b"\r\n" not in payload
    assert not payload.startswith(b"\xef\xbb\xbf")
    return {
        "path": relative,
        "sha256": sha256_bytes(payload),
        "blob_id": git("hash-object", "--", str(AUDIT / relative)),
        "bytes": len(payload),
        "lines": len(payload.decode("utf-8").splitlines()),
    }


def expected_status(include_output: bool) -> set[str]:
    rows = {
        f"?? {PREFIX}/{PRODUCER_GENERATOR}",
        f"?? {PREFIX}/{PRODUCER_RECEIPT}",
        f"?? {PREFIX}/{MATERIALIZER}",
    }
    if include_output:
        rows.add(f"?? {PREFIX}/{OUTPUT}")
    return rows


def validate_boundary() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == APPLICATION_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == APPLICATION_TREE
    assert git("rev-parse", "main") == APPLICATION_COMMIT
    assert git("rev-parse", "origin/main") == APPLICATION_COMMIT
    assert set(git("status", "--porcelain=v1", "--untracked-files=all").splitlines()) in (
        expected_status(False), expected_status(True)
    )
    assert git("diff", "--name-only", "HEAD", "--") == ""
    assert git("diff", "--cached", "--name-only", "HEAD", "--") == ""


def materialize() -> dict[str, Any]:
    generator = file_record(PRODUCER_GENERATOR)
    receipt_record = file_record(PRODUCER_RECEIPT)
    assert generator["sha256"] == PRODUCER_GENERATOR_SHA256
    assert receipt_record["sha256"] == PRODUCER_RECEIPT_SHA256

    receipt = strict_json(AUDIT / PRODUCER_RECEIPT)
    assert receipt["run_id"] == "RUN-159-MED-RBAC-01-ALREADY-FIXED-ADJUDICATION-WAVE-28"
    assert receipt["status"] == (
        "ALREADY_FIXED_UNANIMOUS_CURRENT_SOURCE_REVIEW_AND_BOUNDED_MYSQL_TESTS_"
        "HISTORICAL_CLAIM_RETIREMENT_AUTHORIZED_ZERO_FINAL_FINDING_OR_COMPLETION_CREDIT"
    )
    assert receipt["pins"]["application_commit"] == APPLICATION_COMMIT
    assert receipt["pins"]["application_tree"] == APPLICATION_TREE
    assert receipt["review_process"]["independent_read_only_lanes"] == 3
    assert receipt["review_process"]["unanimous_verdict"] == "ALREADY_FIXED"
    for row in receipt["review_process"]["reviewers"]:
        unsigned = {key: value for key, value in row.items() if key != "root_materialized_record_sha256"}
        assert row["root_materialized_record_sha256"] == canonical_sha256(unsigned)
    assert receipt["review_process"]["review_set_sha256"] == canonical_sha256(
        receipt["review_process"]["reviewers"]
    )
    commands = receipt["runtime_execution"]["commands"]
    assert receipt["runtime_execution"]["command_set_sha256"] == canonical_sha256(commands)
    assert [row["tests_passed"] for row in commands] == [43, 28, 2]
    assert [row["assertions"] for row in commands] == [786, 655, 40]
    assert receipt["runtime_execution"]["totals"] == {
        "commands": 3,
        "tests_passed": 73,
        "tests_failed": 0,
        "assertions": 1481,
        "duration_seconds": "450.72",
    }
    database = receipt["runtime_execution"]["database"]
    assert database["configured_base_post_explicit_drop_present"] == 0
    assert database["post_run_effective_schema_prefix_match_count"] == 0
    assert database["post_run_configured_base_present"] == 0
    assert database["all_run159_schema_residue_absent"] is True
    assert receipt["historical_and_current_disposition"]["record_action_authorized"] == (
        "RETIRE_PROVISIONAL_CURRENT_SOURCE_CLAIM_PRESERVE_HISTORICAL_IDENTITY"
    )
    assert receipt["historical_and_current_disposition"]["current_final_finding"] is False
    assert receipt["historical_and_current_disposition"]["application_source_changed_by_run_159"] is False
    assert receipt["bounded_acceptance"]["operation_level_concurrent_same_UUID_race"] == "NOT_EXECUTED_OR_CREDITED"
    assert receipt["bounded_acceptance"]["representative_signed_in_application_browser"] == "NOT_EXECUTED_OR_CREDITED"
    assert [key for key, value in receipt["credit_boundary"].items() if value] == [
        "historical_condition_source_confirmed",
        "current_source_already_fixed_adjudication",
        "bounded_med_rbac_test_execution",
        "provisional_current_source_claim_retirement_authorized",
    ]
    assert all(value is False for value in receipt["completion_boundary"].values())
    assert receipt["artifact_completion_test_met"] is True
    assert receipt["audit_completion_test_met"] is False

    review: dict[str, Any] = {
        "reviewer_lane": "/root/run159_receipt_reviewer",
        "review_record_materialized_by": "/root",
        "review_scope": "EXACT_CURRENT_RUN159_GENERATOR_RECEIPT_SOURCE_PINS_ARITHMETIC_DATABASE_CLEANUP_OUTCOME_AND_CREDIT_BOUNDARY",
        "review_process": {
            "read_only": True,
            "writes": False,
            "tests_executed": False,
            "browser_executed": False,
            "database_operations_executed": False,
            "blind_or_isolated_review_claimed": False,
            "root_shared_current_hash_and_correction_context": True,
            "reviewer_found_and_required_effective_schema_cleanup_disclosure_correction": True,
            "corrected_bytes_reopened_before_final_verdict": True,
        },
        "checks": {
            "prompt_commit_tree_main_origin_and_source_test_pins": "PASS",
            "historical_record_and_ancestry_boundaries": "PASS",
            "reviewer_record_review_set_and_command_set_hashes": "PASS",
            "test_and_duration_arithmetic": "PASS_73_TESTS_1481_ASSERTIONS_450_72_SECONDS",
            "configured_base_and_effective_process_schema_cleanup": "PASS_ZERO_RESIDUE",
            "historical_defect_and_current_exact_capability_disposition": "PASS_ALREADY_FIXED",
            "separate_med_cd_concurrency_browser_and_downstream_noninheritance": "PASS",
            "single_organisation_multiple_Sites_wording": "PASS",
        },
        "verdict": "GO",
        "blocking_discrepancies": [],
        "reporting_authorized": True,
    }
    review["root_materialized_review_record_sha256"] = canonical_sha256(review)

    return {
        "schema_version": "run-159r-independent-med-rbac-adjudication-review-wave-28-v1",
        "run_id": RUN_ID,
        "status": STATUS,
        "generated_at": "2026-08-29T18:45:00+12:00",
        "pins": {
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "producer_generator": generator,
            "producer_receipt": receipt_record,
        },
        "review": review,
        "decision": {
            "verdict": "GO",
            "blocking_discrepancies": 0,
            "retirement_reporting_authorized": True,
            "application_remediation_authorized": False,
            "final_finding_authorized": False,
            "gate_4_complete": False,
            "audit_complete": False,
        },
        "credit_boundary": {
            "independent_exact_artifact_review_for_retirement_reporting": True,
            "application_remediation": False,
            "runtime_reexecution": False,
            "application_browser": False,
            "benchmark_mapping": False,
            "final_no_match_or_NCM": False,
            "final_finding": False,
            "pass": False,
            "release": False,
            "completion": False,
            "audit_complete": False,
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
    }


def main() -> None:
    validate_boundary()
    payload = materialize()
    output_path = AUDIT / OUTPUT
    output_bytes = (json.dumps(payload, indent=2, ensure_ascii=False) + "\n").encode("utf-8")
    temporary = output_path.with_name(f".{output_path.name}.tmp-run159r")
    assert not temporary.exists()
    try:
        temporary.write_bytes(output_bytes)
        assert temporary.read_bytes() == output_bytes
        temporary.replace(output_path)
    finally:
        if temporary.exists():
            temporary.unlink()
    assert output_path.read_bytes() == output_bytes
    assert strict_json(output_path) == payload
    assert set(git("status", "--porcelain=v1", "--untracked-files=all").splitlines()) == expected_status(True)
    print(json.dumps({
        "run_id": RUN_ID,
        "status": STATUS,
        "output": OUTPUT,
        "sha256": sha256_bytes(output_bytes),
        "verdict": "GO",
        "audit_complete": False,
    }, sort_keys=True))


if __name__ == "__main__":
    main()
