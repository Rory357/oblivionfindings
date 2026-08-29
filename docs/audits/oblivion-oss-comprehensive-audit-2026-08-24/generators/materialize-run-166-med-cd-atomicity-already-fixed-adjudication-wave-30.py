#!/usr/bin/env python3
"""Materialize the bounded RUN166 manual controlled-entry atomicity receipt.

The receipt consumes sealed RUN165 source evidence, preserves the exact
temporary concurrency harness as immutable audit evidence, and records only
the attributable MySQL executions observed by root.  It does not change
application source or the live findings register; RUN166R remains mandatory.
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

RUN_ID = "RUN-166-MED-CD-ATOMICITY-01-ALREADY-FIXED-ADJUDICATION-WAVE-30"
STATUS = (
    "ALREADY_FIXED_BOUNDED_MANUAL_CD_ENTRY_ATOMICITY_SOURCE_RUNTIME_GO_"
    "RUN166R_REQUIRED_ZERO_FINAL_FINDING_OR_COMPLETION_CREDIT"
)
GENERATED_AT = "2026-08-30T05:55:00+12:00"
MATERIALIZER = "generators/materialize-run-166-med-cd-atomicity-already-fixed-adjudication-wave-30.py"
OUTPUT = "evidence/runtime/current-run-166-med-cd-atomicity-already-fixed-adjudication-wave-30.json"
SNAPSHOT = "evidence/runtime/harnesses/run-166-controlled-drug-atomicity-concurrency-test.php.txt"
TEMPORARY_HARNESS = "tests/Feature/Emar/ControlledDrugAtomicityConcurrencyTest.php"

REPOSITORY_COMMIT = "8285322232709542e29dc830fecdc4a446269bdf"
REPOSITORY_TREE = "8c2bd679dfa6297854ec8acaf11914b4d10b7cdd"
REVIEWED_SOURCE_CHECKPOINT = "cf0090ec97242776eea30a2875756446f42862f9"
REVIEWED_SOURCE_TREE = "b1c932d1c5c19e9e2ea655da5964dd1c5e9c41f3"
EFFECTIVE_APPLICATION_COMMIT = "0b1920dade9251d617f3cb0b69da5c0202b5a6bf"
EFFECTIVE_APPLICATION_TREE = "7b2b5688c90e4da28725e70e38e50fd445f1b4c4"
HISTORICAL_APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
HISTORICAL_RECORD_SHA256 = "9ba4f430ee59efea414b42a8633c1c969a2fd4428fbf3fef173fb5548cc8e7f1"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
HARNESS_PIN = ("49bbc43ca9caa470e10992751f3e2b7080cde6cf6ff554994ce85e0956b5d807", "f87f011bd6441f3cafcfc1528378e21f180d6570", 31845, 715)

RUN165_GENERATOR = "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-run-165-med-cd-atomicity-current-source-review-wave-30.py"
RUN165_RECEIPT = "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/current-run-165-med-cd-atomicity-current-source-review-wave-30.json"
RUN165_PINS = {
    RUN165_GENERATOR: ("ccc8d49b793ca1980272f93ca77c870e29de72256100d4a5595cc114a4d010ea", "a8fec73f84b8e6c1208647839bdc47904db6758a", 22581, 419),
    RUN165_RECEIPT: ("83257b5689f69885be2ed53bee8c0250b62d0e159f5b71dc0382282bb12a81c0", "07c446cbf89cf5fdcc435e2fa814e48a69a2e925", 13240, 288),
}

SOURCE_PINS = {
    "routes/emar.php": ("369d592aa532a988018d7b48f78d97f41500836762a662f8b714838b7dfeb8c9", "f7ea398d5cbfdeaadd7fdab41f417e26ac170ff7", 27108, 456),
    "app/Http/Controllers/Emar/EmarController.php": ("91047e8fa6068860fd133abf228fe0d3092d1c4a4296e58736cfd430686866d6", "c347d69a71b8bdf657129faba8df06d4212968b6", 434064, 8921),
    "app/Services/Medication/MedicationGovernanceScopeService.php": ("ef1733ea67ec8e1b9ece980151555120396145257552691f67909995e4089849", "434cd58b66d6948dc1897a285d41d21ff9059c5e", 64408, 1678),
    "app/Http/Controllers/Concerns/HandlesMedicationSync.php": ("eb67f2f33d8c9358a37697f1db0c62e4b4f50388391481300890ee9093659e95", "d31f44b6498a4229414bd7f9b1d81c25a23cb89e", 7742, 241),
    "app/Models/ClientControlledDrugEntry.php": ("fff5a875dacd533a95b7cd7de9b70a0d637348bed4f177fb47f06120d99584a7", "d769023bc5caad9d126dbdfaa90165873ea53891", 1690, 76),
    "app/Models/ClientMedicationStock.php": ("ebd9831f8ebbffb2627588340e0bec602b9eb26de1c65cd0ab6a5b3d18884e1e", "a390a99acae25df8f063dd228e657a89a66c6951", 3523, 122),
    "app/Models/MedicationIdempotencyResult.php": ("c6af19f2e84b7d34e8ed22255187bcb54bfecddd33049fae968acb6982178cc9", "00ea562298357f812374c0bb44235d193cf4f2eb", 638, 32),
    "app/Support/Medication/MedicationStockQuantity.php": ("c170d064db8a2d8a00d6153fed0a7b674025df4b009cca942cf01190fa6665cf", "fa304ee1e35e09cc242c17de93b4ca3c8f571090", 4817, 136),
    "app/Services/AuditLogger.php": ("21976b9ab5e8265f844159800639d3d4a73b7821fafffcab6ac79b1d1e59b690", "e4140c7f8c1cae42bfaa21fa865d42f115e590fa", 2880, 77),
    "database/migrations/2026_08_14_230100_create_medication_idempotency_results.php": ("ffd71b636bd8f03a67e4fe083ef6ad7be8d4e1cf54a098167f00ea1484e876fd", "8a7c252b4fe12635262eb128ce0e995d43c7dce4", 1346, 40),
    "database/migrations/2026_08_20_000100_preserve_fractional_controlled_drug_balances.php": ("7cfd7c48c433e7b79b48cf840105bf78172931182e6b77ae58c5e73b0c4394b8", "74df70065c9028f3eaaa1cd1789507b24316f1cc", 3861, 87),
    "database/migrations/2026_04_05_700000_add_stock_non_negative_constraint.php": ("e844dbdd2b1f0095463c8b32d3fa6245866a2b3cd076bcbd3330e2014d3c5ecc", "4003c9dda1a0b51f01bb3a98c24a548f78338068", 532, 23),
    "tests/Feature/Emar/ControlledDrugsTest.php": ("4f0007b36820a82b8fac71911a603a3af33fbb2ca5b479e07376f360c09cfbd5", "d88a108ffa72e58215a3584d42d71e115e7aba27", 58169, 1370),
    "tests/Feature/Emar/MedicationGovernanceAuthorizationTest.php": ("7367b2b06dde1eb4b8d7d772c30315f7b5ebd52f595f1d31f2df017e1a13f635", "b9f3a8589296e9760df7758d9c3c131ae358f353", 108478, 2463),
    "tests/Feature/Emar/MedicationWitnessLockOrderTest.php": ("2f9ea584fd55854c2a9de5513d928aaa5e36cb5605e651a9dd89bbbaea2fd53a", "cee64ed07f4653d73e305129461e7c4487a35324", 10549, 291),
}

MATERIALIZER_REPOSITORY_PATH = f"{AUDIT_PREFIX}{MATERIALIZER}"
OUTPUT_REPOSITORY_PATH = f"{AUDIT_PREFIX}{OUTPUT}"
SNAPSHOT_REPOSITORY_PATH = f"{AUDIT_PREFIX}{SNAPSHOT}"
EXPECTED_DIRTY_PATHS = {
    MATERIALIZER_REPOSITORY_PATH,
    OUTPUT_REPOSITORY_PATH,
    SNAPSHOT_REPOSITORY_PATH,
    TEMPORARY_HARNESS,
}


def run_git(*args: str, check: bool = True) -> subprocess.CompletedProcess[bytes]:
    return subprocess.run(["git", *args], cwd=ROOT, check=check, capture_output=True)


def git(*args: str) -> str:
    return run_git(*args).stdout.decode("utf-8").rstrip("\r\n")


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def canonical_sha256(value: Any) -> str:
    return sha256_bytes(json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8"))


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
    assert git("log", "-1", "--format=%T", REVIEWED_SOURCE_CHECKPOINT) == REVIEWED_SOURCE_TREE
    assert git("log", "-1", "--format=%T", EFFECTIVE_APPLICATION_COMMIT) == EFFECTIVE_APPLICATION_TREE
    assert run_git("merge-base", "--is-ancestor", EFFECTIVE_APPLICATION_COMMIT, "HEAD", check=False).returncode == 0
    assert all(
        path.replace("\\", "/").startswith(AUDIT_PREFIX)
        for path in git("diff", "--name-only", REVIEWED_SOURCE_CHECKPOINT, "HEAD", "--").splitlines()
    )
    rows = [row for row in git("status", "--porcelain=v1", "--untracked-files=all").splitlines() if row]
    for row in rows:
        assert status_path(row) in EXPECTED_DIRTY_PATHS, row
    for path in git("diff", "--name-only", "HEAD", "--").splitlines():
        assert path.replace("\\", "/") in EXPECTED_DIRTY_PATHS, path
    for path in git("diff", "--cached", "--name-only", "HEAD", "--").splitlines():
        assert path.replace("\\", "/") in EXPECTED_DIRTY_PATHS, path
    diff_check = run_git("diff", "--check", "HEAD", "--", check=False)
    assert diff_check.returncode == 0 and diff_check.stdout == b"" and diff_check.stderr == b""


def validate_lineage() -> dict[str, dict[str, Any]]:
    records: dict[str, dict[str, Any]] = {}
    for relative, expected in RUN165_PINS.items():
        record = file_record(relative)
        assert tuple(record[key] for key in ("sha256", "blob_id", "bytes", "lines")) == expected, relative
        assert git("rev-parse", f"HEAD:{relative}") == record["blob_id"]
        records[relative] = record
    run165 = strict_json(ROOT / RUN165_RECEIPT)
    assert run165["run_id"] == "RUN-165-MED-CD-ATOMICITY-01-CURRENT-SOURCE-REVIEW-WAVE-30"
    assert run165["status"] == "CURRENT_MAIN_STRUCTURAL_ATOMICITY_GO_ALREADY_FIXED_CANDIDATE_EXACT_RUNTIME_GATE_REQUIRED_ZERO_FINDING_OR_COMPLETION_CREDIT"
    assert [key for key, value in run165["credit_boundary"].items() if value] == ["independent_current_source_review"]
    seal = run165["receipt_self_seal_sha256"]
    without_seal = dict(run165)
    del without_seal["receipt_self_seal_sha256"]
    assert seal == canonical_sha256(without_seal)
    return records


def validate_sources() -> dict[str, dict[str, Any]]:
    records: dict[str, dict[str, Any]] = {}
    for relative, expected in SOURCE_PINS.items():
        record = file_record(relative)
        assert tuple(record[key] for key in ("sha256", "blob_id", "bytes", "lines")) == expected, relative
        assert git("rev-parse", f"HEAD:{relative}") == record["blob_id"]
        records[relative] = record
    findings = strict_json(AUDIT / "findings.json")
    target = next(row for row in findings["records"] if row["id"] == "MED-CD-ATOMICITY-01")
    assert target["record_status"] == "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING"
    assert canonical_sha256(target) == HISTORICAL_RECORD_SHA256
    return records


def materialize_harness_snapshot() -> dict[str, Any]:
    source_path = ROOT / TEMPORARY_HARNESS
    snapshot_path = AUDIT / SNAPSHOT
    if source_path.exists():
        source_payload = source_path.read_bytes()
        source_record = file_record(TEMPORARY_HARNESS)
        assert tuple(source_record[key] for key in ("sha256", "blob_id", "bytes", "lines")) == HARNESS_PIN
    else:
        assert snapshot_path.exists()
        source_payload = snapshot_path.read_bytes()
    assert sha256_bytes(source_payload) == HARNESS_PIN[0]
    assert len(source_payload) == HARNESS_PIN[2]
    assert len(source_payload.decode("utf-8").splitlines()) == HARNESS_PIN[3]
    snapshot_path.parent.mkdir(parents=True, exist_ok=True)
    if snapshot_path.exists():
        assert snapshot_path.read_bytes() == source_payload
    else:
        temporary = snapshot_path.with_name(f".{snapshot_path.name}.tmp-run166-harness")
        assert not temporary.exists()
        try:
            temporary.write_bytes(source_payload)
            assert temporary.read_bytes() == source_payload
            temporary.replace(snapshot_path)
        finally:
            if temporary.exists():
                temporary.unlink()
    snapshot_record = file_record(SNAPSHOT_REPOSITORY_PATH)
    assert tuple(snapshot_record[key] for key in ("sha256", "blob_id", "bytes", "lines")) == HARNESS_PIN
    return snapshot_record


def command(command_text: str, tests: int, assertions: int, pest_seconds: str, wall_seconds: str, classification: str) -> dict[str, Any]:
    return {
        "command": command_text,
        "exit_code": 0,
        "tests_passed": tests,
        "tests_failed": 0,
        "assertions": assertions,
        "pest_duration_seconds": pest_seconds,
        "wall_duration_seconds": wall_seconds,
        "classification": classification,
    }


def build_receipt(lineage: dict[str, dict[str, Any]], sources: dict[str, dict[str, Any]], snapshot: dict[str, Any]) -> dict[str, Any]:
    supporting = command(
        "php artisan test --compact --stop-on-failure tests/Feature/Emar/MedicationGovernanceAuthorizationTest.php tests/Feature/Emar/MedicationWitnessLockOrderTest.php",
        43,
        716,
        "210.53",
        "230.78",
        "SUPPORTING_GOVERNANCE_EXECUTION_OVERLAPS_PRIOR_DENOMINATORS_NOT_ADDED_TO_CUMULATIVE_TOTAL",
    )
    rollback = command(
        "php artisan test --compact --stop-on-failure tests/Feature/Emar/ControlledDrugsTest.php --filter=test_controlled_offline_entry_and_balance_audit_failures_roll_back_receipts_stock_and_replay_bindings",
        1,
        10,
        "159.80",
        "182.23",
        "FRESH_FORCED_AUDIT_FAILURE_ROLLBACK_PASS_MIXED_ENTRY_AND_UNCREDITED_BALANCE_CHECK_ASSERTIONS",
    )
    fractional = command(
        "php artisan test --compact --stop-on-failure tests/Feature/Emar/MedicationGovernanceAuthorizationTest.php --filter=test_fractional_controlled_entry_is_lossless_across_failure_retry_and_replay",
        1,
        17,
        "150.36",
        "170.90",
        "FRESH_MANUAL_ENTRY_FRACTIONAL_FAILURE_REQUEST_RETRY_AND_DURABLE_REPLAY_PASS",
    )
    race = command(
        "php artisan test --compact --stop-on-failure tests/Feature/Emar/ControlledDrugAtomicityConcurrencyTest.php",
        1,
        119,
        "165.03",
        "186.15",
        "FRESH_EXACT_HARNESS_ONE_TEST_CONTAINING_THREE_SYNCHRONIZED_RACE_SUBSCENARIOS_PASS",
    )
    credited_commands = [rollback, fractional, race]
    invalid_attempts = [
        {
            "attempt": "combined focused filter",
            "exit_code": 255,
            "classification": "INVALID_WINDOWS_BATCH_FILTER_PARSE_PEST_NOT_STARTED_ZERO_PRODUCT_CREDIT",
            "diagnostic": "pipe in the combined filter was interpreted by the php.bat command wrapper",
        },
        {
            "attempt": "exploratory direct-controller race",
            "exit_code": 0,
            "observed_tests": 1,
            "observed_assertions": 30,
            "classification": "INVALID_INSUFFICIENT_HTTP_KERNEL_ATTRIBUTION_AND_INVARIANTS_ZERO_PRODUCT_CREDIT",
        },
        {
            "attempt": "full-kernel information-schema lock observation",
            "classification": "INVALID_HARNESS_LOCK_ATTRIBUTION_TIMEOUT_ZERO_PRODUCT_CREDIT",
        },
        {
            "attempt": "pre-execution hook before assertion correction",
            "classification": "INVALID_TEST_ASSERTION_API_ERROR_ZERO_PRODUCT_CREDIT",
            "diagnostic": "nonexistent assertSameCanonicalizing call after race progression",
        },
        {
            "attempt": "raw-binding callback with integer coercion",
            "observed_http_statuses": [500, 500],
            "classification": "INVALID_HARNESS_INSTRUMENTATION_CARBON_TO_INT_ZERO_PRODUCT_CREDIT",
            "diagnostic": "beforeExecuting receives raw Carbon bindings before Laravel binding preparation",
        },
        {
            "attempt": "cleanup query through absent configured base",
            "exit_code": 255,
            "classification": "CLEANUP_DIAGNOSTIC_CONFIGURED_BASE_ABSENCE_CONFIRMED_QUERY_RETRIED_THROUGH_INFORMATION_SCHEMA",
            "diagnostic": "SQLSTATE 1049 unknown database oa166_atomicity",
        },
    ]
    race_outcomes = [
        {
            "scenario": "same UUID and same payload",
            "worker_processes_distinct": True,
            "mysql_connection_ids_distinct": True,
            "both_reached_client_lock_barrier": True,
            "pre_release_effect_counts": {"entries": 0, "stock_changes": 0, "audit_logs": 0, "durable_replays": 0},
            "responses": ["processed", "duplicate"],
            "persisted_entry_ids_equal": True,
            "final_effect": {"entries": 1, "stock_on_hand": "9.00", "audit_logs": 1, "durable_replays": 1},
        },
        {
            "scenario": "same UUID and materially different movement payload",
            "worker_processes_distinct": True,
            "mysql_connection_ids_distinct": True,
            "both_reached_client_lock_barrier": True,
            "pre_release_effect_counts": {"entries": 0, "stock_changes": 0, "audit_logs": 0, "durable_replays": 0},
            "responses": ["processed", "409 exact request fingerprint conflict"],
            "persisted_entry_and_stock_follow_winner": True,
            "final_effect": {"entries": 1, "audit_logs": 1, "durable_replays": 1},
        },
        {
            "scenario": "different UUIDs and identical stale before-balance",
            "worker_processes_distinct": True,
            "mysql_connection_ids_distinct": True,
            "both_reached_client_lock_barrier": True,
            "pre_release_effect_counts": {"entries": 0, "stock_changes": 0, "audit_logs": 0, "durable_replays": 0},
            "responses": ["processed", "409 exact stale stock conflict"],
            "winner_and_loser_uuid_correlation_proved": True,
            "loser_durable_replay_absent": True,
            "final_effect": {"entries": 1, "stock_on_hand": "9.00", "audit_logs": 1, "durable_replays": 1},
        },
    ]
    reviewers = [
        {
            "reviewer_lane": "/root/run163_artifact_review",
            "role": "adversarial source, harness-attribution, runtime-outcome, and credit-boundary reviewer",
            "verdict": "GO_ALREADY_FIXED_FOR_BOUNDED_MANUAL_CONTROLLED_ENTRY_REGISTER_STOCK_CLAIM_ONLY",
            "reviewed_harness_sha256": HARNESS_PIN[0],
            "reviewed_corrected_execution": {"test_functions": 1, "race_subscenarios": 3, "assertions": 119, "exit_code": 0},
            "independent_reexecution": False,
            "application_writes": False,
            "limitations": ["no balance-check or destruction transfer", "no forced transient-deadlock retry", "no browser, benchmark, Pass, release, or completion credit"],
        },
        {
            "reviewer_lane": "/root/run165_race_harness_critic",
            "role": "two-process HTTP-kernel race, lock-barrier, persistence-correlation, and instrumentation critic",
            "verdict": "GO_HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING",
            "reviewed_harness_sha256": HARNESS_PIN[0],
            "reviewed_corrected_execution": {"test_functions": 1, "race_subscenarios": 3, "assertions": 119, "exit_code": 0},
            "independent_reexecution": False,
            "application_writes": False,
            "limitations": ["one synchronized MySQL run is not stress evidence", "rollback and fractional cases are separately attributable", "transient-deadlock retry remains source-configured only"],
        },
    ]
    for reviewer_record in reviewers:
        reviewer_record["root_materialized_record_sha256"] = canonical_sha256(reviewer_record)
    materializer_record = file_record(MATERIALIZER_REPOSITORY_PATH)
    payload: dict[str, Any] = {
        "schema_version": "run-166-med-cd-atomicity-already-fixed-adjudication-wave-30-v1",
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
            "reviewed_source_checkpoint": REVIEWED_SOURCE_CHECKPOINT,
            "reviewed_source_tree": REVIEWED_SOURCE_TREE,
            "effective_application_commit": EFFECTIVE_APPLICATION_COMMIT,
            "effective_application_tree": EFFECTIVE_APPLICATION_TREE,
            "historical_audited_application_commit": HISTORICAL_APPLICATION_COMMIT,
            "historical_med_cd_atomicity_record_canonical_sha256": HISTORICAL_RECORD_SHA256,
            "materializer": {"path": MATERIALIZER, **materializer_record},
            "run165_lineage": lineage,
            "source_and_test_files": sources,
            "immutable_harness_snapshot": {"path": SNAPSHOT, **snapshot},
            "temporary_harness_source": {"path": TEMPORARY_HARNESS, "present_and_matched_when_run166_sealed": True, **dict(zip(("sha256", "blob_id", "bytes", "lines"), HARNESS_PIN))},
        },
        "historical_and_current_disposition": {
            "finding_id": "MED-CD-ATOMICITY-01",
            "feature_id": "CAP-MED-CD-REGISTER-BALANCE",
            "bounded_clause": "manual POST /emar/controlled/entries register and stock atomicity",
            "historical_condition_at_a049": "entry and stock update did not share one encompassing transaction and replay was cache-only",
            "current_condition": "encompassing retried transaction with Client-before-medication locks, locked stock reconciliation, strict audit, and durable replay",
            "verdict": "ALREADY_FIXED",
            "current_final_finding": False,
            "new_finding_required": False,
            "application_remediation_required": False,
            "application_source_or_product_test_changed_by_run166": False,
            "record_action_after_run166r_go": "PRESERVE_IDENTITY_AND_RECLASSIFY_BOUNDED_MANUAL_CLAUSE_AS_HISTORICAL_ALREADY_FIXED",
        },
        "review_process": {
            "reviewers": reviewers,
            "reviewer_lanes": 2,
            "coordinated_not_blind": True,
            "independent_reexecution_by_reviewers": False,
            "root_materialized_returned_reviews": True,
            "root_was_sole_runtime_executor_writer_and_integrator": True,
        },
        "runtime_execution": {
            "sanitized_environment": {
                "APP_ENV": "testing",
                "DB_CONNECTION": "mysql",
                "race_DB_DATABASE_base": "oa165_atomicity",
                "focused_DB_DATABASE_base": "oa166_atomicity",
                "CACHE_STORE": "array",
                "SESSION_DRIVER": "array",
                "QUEUE_CONNECTION": "sync",
                "MAIL_MAILER": "array",
                "credentials_recorded": False,
            },
            "supporting_governance_command": supporting,
            "claim_specific_commands": credited_commands,
            "claim_specific_totals": {
                "commands": 3,
                "test_functions_passed": 3,
                "race_subscenarios": 3,
                "assertions_across_command_outputs": 146,
                "failed_tests": 0,
                "arithmetic": "1+1+1 test functions; 10+17+119 assertions",
                "aggregation_boundary": "reported separately; supporting command overlaps prior denominators and rollback test includes an uncredited balance-check half",
            },
            "invalid_noncredit_attempts": invalid_attempts,
            "exact_race_outcomes": race_outcomes,
            "race_attribution": {
                "real_http_kernel": True,
                "authenticated_distinct_workers": True,
                "distinct_os_processes": True,
                "distinct_mysql_connection_ids": True,
                "exact_database_asserted": "oa165_atomicity effective per-process schema",
                "client_for_update_barrier_observed": True,
                "all_workers_and_barriers_released": True,
            },
            "facet_evidence": {
                "encompassing_transaction": "CURRENT_SOURCE_AND_FORCED_FAILURE_RUNTIME_PROVED",
                "parent_first_lock_order": "CURRENT_SOURCE_PLUS_OBSERVED_CLIENT_LOCK_BARRIER_PROVED",
                "audit_failure_rollback": "FRESH_EXECUTED_PASS",
                "fractional_precision_across_failure_and_resubmission": "FRESH_EXECUTED_PASS",
                "request_retry_and_durable_replay": "FRESH_EXECUTED_PASS",
                "operation_level_concurrency": "FRESH_EXECUTED_THREE_SYNCHRONIZED_SUBSCENARIOS_PASS",
                "transaction_attempt_count": "CURRENT_SOURCE_DB_TRANSACTION_THREE_ATTEMPTS",
                "transient_mysql_deadlock_retry": "NOT_FORCED_OR_EXECUTED_NO_CREDIT",
            },
            "cleanup": {
                "information_schema_query": "SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'oa165_atomicity%' OR schema_name LIKE 'oa166_atomicity%' ORDER BY schema_name",
                "matching_schemas": [],
                "matching_schema_count": 0,
                "configured_oa166_base_connection_result": "SQLSTATE_1049_UNKNOWN_DATABASE_CONFIRMS_BASE_ABSENT",
                "owned_php_processes": 0,
                "owned_php_listeners": 0,
                "owned_barrier_files": 0,
                "cleanup_complete": True,
            },
            "browser_executed": False,
        },
        "compound_record_boundary": {
            "manual_store_cd_entry_register_stock_atomicity_adjudicated": True,
            "store_balance_check_adjudicated": False,
            "destruction_relationship_checks_adjudicated": False,
            "delivery_stock_adjustment_loss_report_or_sibling_writer_adjudicated": False,
            "transient_deadlock_retry_adjudicated": False,
            "stress_or_repeated_schedule_evidence": False,
            "residual_scope_must_remain_explicit_after_reporting": True,
        },
        "independent_review_gate": {
            "required_run": "RUN166R",
            "reporting_authorized_before_run166r": False,
            "live_findings_register_changed_by_run166": False,
        },
        "write_boundary": {
            "observed_changed_paths": sorted(EXPECTED_DIRTY_PATHS),
            "wrote_files": [SNAPSHOT, OUTPUT],
            "materializer_runtime_writes_only_harness_snapshot_and_receipt": True,
            "materializer_did_not_write_itself": True,
            "materializer_did_not_write_temporary_harness": True,
            "application_files_written": [],
        },
        "credit_boundary": {
            "historical_condition_source_confirmed": True,
            "current_source_already_fixed_adjudication": True,
            "bounded_med_cd_atomicity_runtime_execution": True,
            "provisional_claim_retirement_authorized": True,
            "application_remediation": False,
            "application_source_change": False,
            "product_test_integration": False,
            "final_finding": False,
            "final_P1": False,
            "benchmark_mapping": False,
            "final_no_match_or_NCM": False,
            "application_browser": False,
            "ease": False,
            "full_feature_or_module": False,
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
    }
    payload["runtime_execution"]["supporting_command_sha256"] = canonical_sha256(supporting)
    payload["runtime_execution"]["claim_specific_command_set_sha256"] = canonical_sha256(credited_commands)
    payload["runtime_execution"]["invalid_attempt_set_sha256"] = canonical_sha256(invalid_attempts)
    payload["runtime_execution"]["race_outcome_set_sha256"] = canonical_sha256(race_outcomes)
    payload["review_process"]["review_set_sha256"] = canonical_sha256(reviewers)
    payload["artifact_completion_test_met"] = True
    payload["audit_completion_test_met"] = False
    payload["receipt_self_seal_sha256"] = canonical_sha256(payload)
    return payload


def validate_receipt(payload: dict[str, Any]) -> None:
    assert [key for key, value in payload["credit_boundary"].items() if value] == [
        "historical_condition_source_confirmed",
        "current_source_already_fixed_adjudication",
        "bounded_med_cd_atomicity_runtime_execution",
        "provisional_claim_retirement_authorized",
    ]
    assert payload["runtime_execution"]["claim_specific_totals"]["assertions_across_command_outputs"] == 10 + 17 + 119
    assert len(payload["runtime_execution"]["exact_race_outcomes"]) == 3
    assert payload["runtime_execution"]["cleanup"]["matching_schema_count"] == 0
    assert payload["independent_review_gate"]["reporting_authorized_before_run166r"] is False
    seal = payload["receipt_self_seal_sha256"]
    without_seal = dict(payload)
    del without_seal["receipt_self_seal_sha256"]
    assert seal == canonical_sha256(without_seal)


def main() -> None:
    validate_repository_boundary()
    lineage = validate_lineage()
    sources = validate_sources()
    snapshot = materialize_harness_snapshot()
    payload = build_receipt(lineage, sources, snapshot)
    validate_receipt(payload)
    output_path = AUDIT / OUTPUT
    output_bytes = (json.dumps(payload, indent=2, ensure_ascii=False) + "\n").encode("utf-8")
    output_path.parent.mkdir(parents=True, exist_ok=True)
    temporary = output_path.with_name(f".{output_path.name}.tmp-run166")
    assert not temporary.exists()
    try:
        temporary.write_bytes(output_bytes)
        assert temporary.read_bytes() == output_bytes
        temporary.replace(output_path)
    finally:
        if temporary.exists():
            temporary.unlink()
    assert output_path.read_bytes() == output_bytes
    written = strict_json(output_path)
    assert written == payload
    validate_receipt(written)
    print(json.dumps({
        "run_id": RUN_ID,
        "status": STATUS,
        "output": OUTPUT,
        "sha256": sha256_bytes(output_bytes),
        "receipt_self_seal_sha256": payload["receipt_self_seal_sha256"],
        "claim_specific_test_functions": 3,
        "race_subscenarios": 3,
        "assertions": 146,
        "audit_complete": False,
    }, sort_keys=True))


if __name__ == "__main__":
    main()
