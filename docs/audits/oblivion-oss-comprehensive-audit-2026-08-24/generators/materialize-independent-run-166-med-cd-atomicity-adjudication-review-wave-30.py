#!/usr/bin/env python3
"""Materialize RUN166R independent exact-artifact review.

This verifies committed RUN165/RUN166 bytes and records the two returned
post-commit reviews.  It does not rerun tests, touch MySQL, mutate application
source, start a browser, or change the live findings register.
"""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import subprocess
from typing import Any


ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24"
AUDIT_PREFIX = AUDIT.relative_to(ROOT).as_posix() + "/"

RUN_ID = "RUN-166R-INDEPENDENT-MED-CD-ATOMICITY-01-ADJUDICATION-REVIEW-WAVE-30"
STATUS = "GO_EXACT_RUN166_ARTIFACT_REVIEW_RETIREMENT_REPORTING_AUTHORIZED_ZERO_DOWNSTREAM_CREDIT"
GENERATED_AT = "2026-08-30T06:25:00+12:00"
MATERIALIZER = "generators/materialize-independent-run-166-med-cd-atomicity-adjudication-review-wave-30.py"
OUTPUT = "evidence/runtime/current-run-166r-independent-med-cd-atomicity-adjudication-review-wave-30.json"
TEMPORARY_HARNESS = "tests/Feature/Emar/ControlledDrugAtomicityConcurrencyTest.php"

REPOSITORY_COMMIT = "bbd9b05b03da6d98deed033471412a05cc31d6d7"
REPOSITORY_TREE = "f5e2f69d3ab02c42583daef8eb62f8732a12a584"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
HISTORICAL_RECORD_SHA256 = "9ba4f430ee59efea414b42a8633c1c969a2fd4428fbf3fef173fb5548cc8e7f1"

RUN165_GENERATOR = "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-run-165-med-cd-atomicity-current-source-review-wave-30.py"
RUN165_RECEIPT = "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/current-run-165-med-cd-atomicity-current-source-review-wave-30.json"
RUN166_GENERATOR = "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-run-166-med-cd-atomicity-already-fixed-adjudication-wave-30.py"
RUN166_RECEIPT = "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/runtime/current-run-166-med-cd-atomicity-already-fixed-adjudication-wave-30.json"
HARNESS_SNAPSHOT = "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/runtime/harnesses/run-166-controlled-drug-atomicity-concurrency-test.php.txt"

PRODUCER_PINS = {
    RUN165_GENERATOR: ("ccc8d49b793ca1980272f93ca77c870e29de72256100d4a5595cc114a4d010ea", "a8fec73f84b8e6c1208647839bdc47904db6758a", 22581, 419),
    RUN165_RECEIPT: ("83257b5689f69885be2ed53bee8c0250b62d0e159f5b71dc0382282bb12a81c0", "07c446cbf89cf5fdcc435e2fa814e48a69a2e925", 13240, 288),
    RUN166_GENERATOR: ("662a8629a6ffda759e1d471b56230d1944ab2663f6f292cdf74604c22342a845", "34963e6c93d1fd3a975432c84bd8975a187efc19", 30738, 579),
    RUN166_RECEIPT: ("c334495fa7cf7303ae70dccff475b7a9583b927bf06ee822f1a13bd347a84a46", "bed3743d2191c86d4526236b78bea896a0b60574", 21939, 485),
    HARNESS_SNAPSHOT: ("49bbc43ca9caa470e10992751f3e2b7080cde6cf6ff554994ce85e0956b5d807", "f87f011bd6441f3cafcfc1528378e21f180d6570", 31845, 715),
}

EXPECTED_RUN165_SELF_SEAL = "8de90b3d923add5d4e1601561c9abb2b19e39b68aff79d74072b1edac1031212"
EXPECTED_RUN165_REVIEW_SET = "e49d6d215a653ef3c012f7158ae29449a503ca63cc3b4dca9aa1bf75276bfbcf"
EXPECTED_RUN166_SELF_SEAL = "11f66f0c27fe4143a94451f140a8ae3a293617a6d1357f9180540a0641e05fea"
EXPECTED_RUN166_REVIEW_SET = "46a44a32b21524199d3efea01f14928eae652bb2da8b17958e9e54e3f978ad49"
EXPECTED_SUPPORTING_COMMAND = "ee6f49b82e62a6287e9836b1e45cbde238368952e7b07708bc9bb0822b116bd1"
EXPECTED_CLAIM_COMMAND_SET = "7287b9ae99974799267a5a990b50abc327bfafe61d5a232aee619c6fc3526704"
EXPECTED_INVALID_ATTEMPT_SET = "6dffece3562104fae84322ffd32c9d0d4ba0114729e815882175360e5e0364e5"
EXPECTED_RACE_OUTCOME_SET = "f5580fb4206c91d85d7fc052a935405e7fbdced52a8f3e75f02ecc7196a38dc7"

MATERIALIZER_REPOSITORY_PATH = f"{AUDIT_PREFIX}{MATERIALIZER}"
OUTPUT_REPOSITORY_PATH = f"{AUDIT_PREFIX}{OUTPUT}"
EXPECTED_DIRTY_PATHS = {MATERIALIZER_REPOSITORY_PATH, OUTPUT_REPOSITORY_PATH, TEMPORARY_HARNESS}


def run_git(*args: str, check: bool = True) -> subprocess.CompletedProcess[bytes]:
    return subprocess.run(["git", *args], cwd=ROOT, check=check, capture_output=True)


def git(*args: str) -> str:
    return run_git(*args).stdout.decode("utf-8").rstrip("\r\n")


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def canonical_sha256(value: Any) -> str:
    return sha256_bytes(json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8"))


def strict_json(relative: str) -> dict[str, Any]:
    path = ROOT / relative

    def hook(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        value: dict[str, Any] = {}
        for key, item in pairs:
            assert key not in value, (relative, key)
            value[key] = item
        return value

    payload = path.read_bytes()
    assert payload.endswith(b"\n") and b"\r\n" not in payload
    assert not payload.startswith(b"\xef\xbb\xbf")
    assert all(line.rstrip(b" \t") == line for line in payload.splitlines())
    value = json.loads(payload.decode("utf-8"), object_pairs_hook=hook)
    assert isinstance(value, dict)
    return value


def file_record(relative: str) -> dict[str, Any]:
    payload = (ROOT / relative).read_bytes()
    assert payload.endswith(b"\n") and b"\r\n" not in payload
    assert not payload.startswith(b"\xef\xbb\xbf")
    return {
        "sha256": sha256_bytes(payload),
        "blob_id": git("hash-object", "--", relative),
        "bytes": len(payload),
        "lines": len(payload.decode("utf-8").splitlines()),
    }


def status_path(row: str) -> str:
    assert len(row) >= 4, row
    path = row[3:]
    if " -> " in path:
        path = path.split(" -> ", 1)[1]
    return path.replace("\\", "/")


def validate_repository_boundary() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == REPOSITORY_COMMIT
    assert git("log", "-1", "--format=%T") == REPOSITORY_TREE
    assert git("rev-parse", "main") == REPOSITORY_COMMIT
    assert git("rev-parse", "origin/main") == REPOSITORY_COMMIT
    rows = [row for row in git("status", "--porcelain=v1", "--untracked-files=all").splitlines() if row]
    for row in rows:
        assert status_path(row) in EXPECTED_DIRTY_PATHS, row
    for path in git("diff", "--name-only", "HEAD", "--").splitlines():
        assert path.replace("\\", "/") in EXPECTED_DIRTY_PATHS, path
    for path in git("diff", "--cached", "--name-only", "HEAD", "--").splitlines():
        assert path.replace("\\", "/") in EXPECTED_DIRTY_PATHS, path
    diff_check = run_git("diff", "--check", "HEAD", "--", check=False)
    assert diff_check.returncode == 0 and diff_check.stdout == b"" and diff_check.stderr == b""


def validate_seal(payload: dict[str, Any], expected: str) -> None:
    seal = payload["receipt_self_seal_sha256"]
    assert seal == expected
    without_seal = dict(payload)
    del without_seal["receipt_self_seal_sha256"]
    assert seal == canonical_sha256(without_seal)


def validate_producers() -> tuple[dict[str, dict[str, Any]], dict[str, Any], dict[str, Any]]:
    records: dict[str, dict[str, Any]] = {}
    for relative, expected in PRODUCER_PINS.items():
        record = file_record(relative)
        assert tuple(record[key] for key in ("sha256", "blob_id", "bytes", "lines")) == expected, relative
        assert git("rev-parse", f"HEAD:{relative}") == record["blob_id"]
        records[relative] = record

    run165 = strict_json(RUN165_RECEIPT)
    validate_seal(run165, EXPECTED_RUN165_SELF_SEAL)
    assert run165["review_process"]["review_set_sha256"] == EXPECTED_RUN165_REVIEW_SET
    assert canonical_sha256(run165["review_process"]["reviewers"]) == EXPECTED_RUN165_REVIEW_SET
    assert [key for key, value in run165["credit_boundary"].items() if value] == ["independent_current_source_review"]

    run166 = strict_json(RUN166_RECEIPT)
    validate_seal(run166, EXPECTED_RUN166_SELF_SEAL)
    assert run166["review_process"]["review_set_sha256"] == EXPECTED_RUN166_REVIEW_SET
    assert canonical_sha256(run166["review_process"]["reviewers"]) == EXPECTED_RUN166_REVIEW_SET
    assert run166["runtime_execution"]["supporting_command_sha256"] == EXPECTED_SUPPORTING_COMMAND
    assert run166["runtime_execution"]["claim_specific_command_set_sha256"] == EXPECTED_CLAIM_COMMAND_SET
    assert run166["runtime_execution"]["invalid_attempt_set_sha256"] == EXPECTED_INVALID_ATTEMPT_SET
    assert run166["runtime_execution"]["race_outcome_set_sha256"] == EXPECTED_RACE_OUTCOME_SET
    assert canonical_sha256(run166["runtime_execution"]["supporting_governance_command"]) == EXPECTED_SUPPORTING_COMMAND
    assert canonical_sha256(run166["runtime_execution"]["claim_specific_commands"]) == EXPECTED_CLAIM_COMMAND_SET
    assert canonical_sha256(run166["runtime_execution"]["invalid_noncredit_attempts"]) == EXPECTED_INVALID_ATTEMPT_SET
    assert canonical_sha256(run166["runtime_execution"]["exact_race_outcomes"]) == EXPECTED_RACE_OUTCOME_SET
    assert run166["runtime_execution"]["claim_specific_totals"]["test_functions_passed"] == 3
    assert run166["runtime_execution"]["claim_specific_totals"]["assertions_across_command_outputs"] == 146
    assert run166["runtime_execution"]["claim_specific_totals"]["race_subscenarios"] == 3
    assert run166["runtime_execution"]["cleanup"]["matching_schema_count"] == 0
    assert run166["runtime_execution"]["cleanup"]["owned_php_processes"] == 0
    assert run166["runtime_execution"]["cleanup"]["owned_php_listeners"] == 0
    assert run166["runtime_execution"]["cleanup"]["owned_barrier_files"] == 0
    assert run166["runtime_execution"]["facet_evidence"]["transient_mysql_deadlock_retry"] == "NOT_FORCED_OR_EXECUTED_NO_CREDIT"
    assert [key for key, value in run166["credit_boundary"].items() if value] == [
        "historical_condition_source_confirmed",
        "current_source_already_fixed_adjudication",
        "bounded_med_cd_atomicity_runtime_execution",
        "provisional_claim_retirement_authorized",
    ]
    assert all(value is False for value in run166["completion_boundary"].values())

    findings = strict_json(f"{AUDIT_PREFIX}findings.json")
    target = next(row for row in findings["records"] if row["id"] == "MED-CD-ATOMICITY-01")
    assert canonical_sha256(target) == HISTORICAL_RECORD_SHA256
    assert target["record_status"] == "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING"
    assert findings["counts"]["provisional_source_claims"] == 10
    assert findings["counts"]["historical_already_fixed"] == 1
    return records, run165, run166


def reviewer_record(lane: str, role: str, checks: list[str], limitations: list[str]) -> dict[str, Any]:
    value: dict[str, Any] = {
        "reviewer_lane": lane,
        "role": role,
        "verdict": "GO_RETIREMENT_REPORTING_ONLY",
        "reviewed_commit": REPOSITORY_COMMIT,
        "read_only": True,
        "tests_database_browser_reexecuted": False,
        "writes": False,
        "checks": checks,
        "limitations": limitations,
    }
    value["root_materialized_record_sha256"] = canonical_sha256(value)
    return value


def build_receipt(records: dict[str, dict[str, Any]], run165: dict[str, Any], run166: dict[str, Any]) -> dict[str, Any]:
    reviewers = [
        reviewer_record(
            "/root/run163_artifact_review",
            "post-commit strict artifact, seal, arithmetic, cleanup, credit, and reporting-delta reviewer",
            ["all five producer artifact hashes/blobs/bytes/lines", "strict JSON and all receipt/review/command/outcome seals", "3 tests and 146 assertions with invalid/supporting exclusions", "zero schema/process/listener/barrier cleanup", "exact four positive RUN166 credits", "10 to 9 provisional and 1 to 2 historical already-fixed delta"],
            ["manual controlled-entry clause only", "all other live denominators unchanged", "no runtime reexecution or downstream credit"],
        ),
        reviewer_record(
            "/root/run165_race_harness_critic",
            "post-commit runtime attribution, harness, concurrency, rollback, fractional, and residual-scope reviewer",
            ["committed harness equals executed bytes", "rollback 1/10", "fractional failure/resubmission/replay 1/17", "three synchronized races 1/119", "invalid and supporting commands excluded", "cleanup complete"],
            ["deadlock retry not forced", "no stress schedule", "balance, destruction, delivery, adjustment, loss, and sibling writers unadjudicated"],
        ),
    ]
    materializer_record = file_record(MATERIALIZER_REPOSITORY_PATH)
    receipt: dict[str, Any] = {
        "schema_version": "run-166r-independent-med-cd-atomicity-adjudication-review-wave-30-v1",
        "run_id": RUN_ID,
        "status": STATUS,
        "generated_at": GENERATED_AT,
        "architecture_boundary": {
            "operating_organisations": 1,
            "multiple_Sites": True,
            "tenant_authorization": False,
            "authorization_boundary": "approved Site access, exact roles and permissions, canonical ownership, direct-object denial, witness qualification, and privacy",
        },
        "pins": {
            "governing_prompt_sha256": PROMPT_SHA256,
            "repository_commit": REPOSITORY_COMMIT,
            "repository_tree": REPOSITORY_TREE,
            "main_commit": REPOSITORY_COMMIT,
            "origin_main_commit": REPOSITORY_COMMIT,
            "historical_med_cd_atomicity_record_canonical_sha256": HISTORICAL_RECORD_SHA256,
            "review_materializer": {"path": MATERIALIZER, **materializer_record},
            "producer_artifacts": records,
            "run165_self_seal_sha256": run165["receipt_self_seal_sha256"],
            "run166_self_seal_sha256": run166["receipt_self_seal_sha256"],
        },
        "review_process": {
            "reviewers": reviewers,
            "reviewer_lanes": 2,
            "coordinated_not_blind": True,
            "root_materialized_returned_reviews": True,
            "root_was_sole_writer_and_integrator": True,
            "tests_database_browser_reexecuted": False,
        },
        "decision": {
            "verdict": "GO",
            "blocking_discrepancies": 0,
            "retirement_reporting_authorized": True,
            "finding_id": "MED-CD-ATOMICITY-01",
            "authorized_reporting_status": "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING",
            "authorized_scope": "manual POST /emar/controlled/entries register and stock atomicity only",
            "authorized_live_count_delta": {
                "retained_claim_records": 0,
                "provisional_source_claims": -1,
                "provisional_P1": -1,
                "historical_already_fixed": 1,
                "historical_remediated": 0,
                "final_P0": 0,
                "final_P1": 0,
                "benchmark_mapped": 0,
                "final_no_match_or_NCM": 0,
                "benchmark_unresolved": 0,
            },
            "required_post_reporting_counts": {
                "retained_claim_records": 12,
                "provisional_source_claims": 9,
                "provisional_P1": 9,
                "historical_already_fixed": 2,
                "historical_remediated": 1,
                "final_P0": 0,
                "final_P1": 0,
                "benchmark_mapped": 2,
                "final_no_match": 0,
                "benchmark_unresolved": 338,
            },
        },
        "residual_scope_boundary": {
            "store_balance_check": "UNADJUDICATED_NO_TRANSFER",
            "destruction_relationship_checks": "UNADJUDICATED_NO_TRANSFER",
            "sibling_controlled_drug_writers": "UNADJUDICATED_NO_TRANSFER",
            "transient_mysql_deadlock_retry": "NOT_FORCED_NO_TRANSFER",
            "repeated_schedule_or_stress": "NOT_EXECUTED_NO_TRANSFER",
            "supporting_command_overlap": "NO_ADDITIONAL_DENOMINATOR_CREDIT",
            "rollback_test_balance_check_half": "NO_BALANCE_CHECK_CREDIT",
        },
        "write_boundary": {
            "observed_changed_paths": sorted(EXPECTED_DIRTY_PATHS),
            "wrote_files": [OUTPUT],
            "materializer_runtime_writes_only_review_receipt": True,
            "application_files_written": [],
        },
        "credit_boundary": {
            "independent_exact_artifact_review_for_retirement_reporting": True,
            "application_remediation_reexecution": False,
            "runtime_reexecution": False,
            "application_source_or_test_change": False,
            "application_browser": False,
            "benchmark_mapping": False,
            "final_no_match_or_NCM": False,
            "final_finding": False,
            "pass": False,
            "release": False,
            "completion": False,
            "audit_complete": False,
        },
        "completion_boundary": {
            "all_340_benchmark_targets_resolved": False,
            "all_required_browser_cells_observed": False,
            "all_task_scripts_executed": False,
            "all_eight_passes_complete": False,
            "gate_4_complete": False,
            "audit_complete": False,
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
    }
    receipt["review_process"]["review_set_sha256"] = canonical_sha256(reviewers)
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    return receipt


def validate_review(receipt: dict[str, Any]) -> None:
    assert receipt["decision"]["verdict"] == "GO"
    assert receipt["decision"]["blocking_discrepancies"] == 0
    assert receipt["decision"]["retirement_reporting_authorized"] is True
    assert receipt["decision"]["required_post_reporting_counts"]["provisional_source_claims"] == 9
    assert receipt["decision"]["required_post_reporting_counts"]["historical_already_fixed"] == 2
    assert [key for key, value in receipt["credit_boundary"].items() if value] == ["independent_exact_artifact_review_for_retirement_reporting"]
    assert all(value is False for value in receipt["completion_boundary"].values())
    assert canonical_sha256(receipt["review_process"]["reviewers"]) == receipt["review_process"]["review_set_sha256"]
    seal = receipt["receipt_self_seal_sha256"]
    without_seal = dict(receipt)
    del without_seal["receipt_self_seal_sha256"]
    assert seal == canonical_sha256(without_seal)


def main() -> None:
    validate_repository_boundary()
    records, run165, run166 = validate_producers()
    receipt = build_receipt(records, run165, run166)
    validate_review(receipt)
    output_path = AUDIT / OUTPUT
    encoded = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    output_path.parent.mkdir(parents=True, exist_ok=True)
    temporary = output_path.with_name(f".{output_path.name}.tmp-run166r")
    assert not temporary.exists()
    try:
        temporary.write_bytes(encoded)
        assert temporary.read_bytes() == encoded
        temporary.replace(output_path)
    finally:
        if temporary.exists():
            temporary.unlink()
    assert output_path.read_bytes() == encoded
    reloaded = strict_json(OUTPUT_REPOSITORY_PATH)
    assert reloaded == receipt
    validate_review(reloaded)
    print(json.dumps({
        "run_id": RUN_ID,
        "status": STATUS,
        "output": OUTPUT,
        "sha256": sha256_bytes(encoded),
        "receipt_self_seal_sha256": receipt["receipt_self_seal_sha256"],
        "verdict": "GO",
        "retirement_reporting_authorized": True,
        "audit_complete": False,
    }, sort_keys=True))


if __name__ == "__main__":
    main()
