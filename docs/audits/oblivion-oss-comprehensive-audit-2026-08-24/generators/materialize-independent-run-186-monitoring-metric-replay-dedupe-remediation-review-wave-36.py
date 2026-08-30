#!/usr/bin/env python3
"""Independently review bounded RUN186 metric-replay remediation evidence.

This reviewer performs deterministic source/artifact checks only. It does not
run PHP, Pest, a database, or a browser; alter application or live-reporting
files; publish commits; or grant static ownership, deployment, release, final
finding, module, Gate 4, or audit-completion credit.
"""
from __future__ import annotations

import hashlib
import json
import subprocess
from collections import Counter
from pathlib import Path
from typing import Any


SCRIPT = Path(__file__).resolve()
AUDIT = SCRIPT.parent.parent
ROOT = next(parent for parent in SCRIPT.parents if (parent / ".git").exists())
PREFIX = AUDIT.relative_to(ROOT).as_posix()
SCRIPT_REL = SCRIPT.relative_to(AUDIT).as_posix()
OUTPUT_REL = (
    "evidence/runtime/current-run-186r-independent-monitoring-metric-replay-"
    "dedupe-remediation-review-wave-36.json"
)
OUTPUT = AUDIT / OUTPUT_REL
PRODUCER_SCRIPT_REL = (
    "generators/materialize-run-186-monitoring-metric-replay-dedupe-"
    "remediation-wave-36.py"
)
PRODUCER_OUTPUT_REL = (
    "evidence/runtime/current-run-186-monitoring-metric-replay-dedupe-"
    "remediation-wave-36.json"
)

RUN_ID = (
    "RUN-186R-INDEPENDENT-MON-METRIC-REPLAY-DEDUPE-01-"
    "REMEDIATION-REVIEW-WAVE-36"
)
STATUS = (
    "INDEPENDENT_EXACT_ARTIFACT_REVIEW_GO_RUN_187_BOUNDED_HISTORICAL_"
    "REMEDIATED_REPORTING_AUTHORIZED_ZERO_OTHER_CREDIT"
)
PRODUCER_RUN_ID = "RUN-186-MON-METRIC-REPLAY-DEDUPE-01-REMEDIATION-WAVE-36"
FINDING_ID = "MON-METRIC-REPLAY-DEDUPE-01"
RECORD_STATUS = (
    "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
)
FEATURE_IDENTITY_STATUS = "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW"

BASE = "a900f078c9c05f587f6f7884f5fe715076891416"
BASE_TREE = "852126934a18a1364244a35f7789263779e47485"
INITIAL_FIX = "f521bc0b87222e56b4822e7cb9c935486e279e76"
INITIAL_FIX_TREE = "7a1862f2aab2844ca568061d3f9ee78201026cbd"
ADVANCED_MAIN = "badd86d566f3354e455b92f12ab683ce6d29c965"
ADVANCED_MAIN_TREE = "cdeba8f19c278fcaf11c6dd0b26ff7814bc1aed9"
INITIAL_MERGE = "778c00a5d09511aee1a836a689d7bb1b56ce4ff6"
INITIAL_MERGE_TREE = "e66c50a3b514967eec70e4774a312bff376bb66a"
CORRECTIVE_FIX = "c82f57779baf623c4e94ac4619b11c1b675d0230"
CORRECTIVE_FIX_TREE = "095cd7b1940988be334979af22008c635fdcaf58"
CORRECTIVE_MERGE = "18652d545c788f1dcdbe57662e5b1e5472d6cae7"
CORRECTIVE_MERGE_TREE = "095cd7b1940988be334979af22008c635fdcaf58"
DNS_FIX = "d5efdf7782a7cd81f78bc282d684e884db001b6c"
DNS_FIX_TREE = "15d2d624429b914047a423c36c26b5744fcd5048"
CURRENT_MAIN = "f938c6d989f5fef052f08b9f1012116fb5cf2f69"
CURRENT_MAIN_TREE = "70b2339300278bc0c20e32ed091f74b442bea76d"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"
INITIAL_PATCH_ID = "16e3886ad0985b4af853d34ede90e2b5e273af51"
CORRECTIVE_PATCH_ID = "18c4df4897f2562e5c797f7de4fb075b607de24b"
DNS_PATCH_ID = "c3902efb3266fa859487de20265f315c6f9401ed"

EXPECTED_PRODUCER_SCRIPT_SHA256 = (
    "983b003dc149c966cdea9c59dd3cd4a766f4a5f0382e881f90b9d0cde9b86cee"
)
EXPECTED_PRODUCER_RECEIPT_SHA256 = (
    "bf2cd03ca2ab7aeb6a9d1093b3c08aba5a1bc29342cc4fda6fa57ef286c2f1e5"
)
EXPECTED_PRODUCER_SELF_SEAL = (
    "9d21a45a215a9d48a82d093817aba6807ef6ed73b130894ac385a41e18e527ff"
)

INITIAL_PATHS = [
    "app/Domain/Monitoring/Data/ObservationInput.php",
    "app/Domain/Monitoring/Models/MetricPointReceipt.php",
    "app/Domain/Monitoring/Models/MonitorObservation.php",
    "app/Domain/Monitoring/Services/MetricIngestService.php",
    "app/Domain/Monitoring/Services/MonitorCheckRunner.php",
    "app/Domain/Monitoring/Services/MonitoringObservationIngestor.php",
    "database/migrations/2026_08_30_000100_govern_monitoring_metric_projection_replays.php",
    "tests/Feature/Monitoring/MetricRetentionTest.php",
    "tests/Feature/Monitoring/RunMonitorCheckTest.php",
]
INITIAL_NAME_STATUS = [
    f"M\t{INITIAL_PATHS[0]}",
    f"A\t{INITIAL_PATHS[1]}",
    f"M\t{INITIAL_PATHS[2]}",
    f"M\t{INITIAL_PATHS[3]}",
    f"M\t{INITIAL_PATHS[4]}",
    f"M\t{INITIAL_PATHS[5]}",
    f"A\t{INITIAL_PATHS[6]}",
    f"M\t{INITIAL_PATHS[7]}",
    f"M\t{INITIAL_PATHS[8]}",
]
INITIAL_NUMSTAT = [
    f"15\t0\t{INITIAL_PATHS[0]}",
    f"41\t0\t{INITIAL_PATHS[1]}",
    f"44\t4\t{INITIAL_PATHS[2]}",
    f"109\t67\t{INITIAL_PATHS[3]}",
    f"6\t7\t{INITIAL_PATHS[4]}",
    f"132\t10\t{INITIAL_PATHS[5]}",
    f"493\t0\t{INITIAL_PATHS[6]}",
    f"776\t4\t{INITIAL_PATHS[7]}",
    f"119\t0\t{INITIAL_PATHS[8]}",
]

CORRECTIVE_PATHS = [
    "app/Domain/Monitoring/Models/MetricCurrentSummary.php",
    "app/Domain/Monitoring/Models/MetricPointReceipt.php",
    "app/Domain/Monitoring/Models/MetricSeries.php",
    "app/Domain/Monitoring/Services/MetricIngestService.php",
    "database/migrations/2026_08_30_000100_govern_monitoring_metric_projection_replays.php",
    "database/migrations/2026_08_30_000110_govern_monitoring_metric_projection_cutover.php",
    "tests/Feature/Monitoring/MetricRetentionTest.php",
]
CORRECTIVE_NAME_STATUS = [
    f"M\t{CORRECTIVE_PATHS[0]}",
    f"M\t{CORRECTIVE_PATHS[1]}",
    f"M\t{CORRECTIVE_PATHS[2]}",
    f"M\t{CORRECTIVE_PATHS[3]}",
    f"M\t{CORRECTIVE_PATHS[4]}",
    f"A\t{CORRECTIVE_PATHS[5]}",
    f"M\t{CORRECTIVE_PATHS[6]}",
]
CORRECTIVE_NUMSTAT = [
    f"2\t0\t{CORRECTIVE_PATHS[0]}",
    f"2\t0\t{CORRECTIVE_PATHS[1]}",
    f"2\t0\t{CORRECTIVE_PATHS[2]}",
    f"24\t2\t{CORRECTIVE_PATHS[3]}",
    f"173\t18\t{CORRECTIVE_PATHS[4]}",
    f"134\t0\t{CORRECTIVE_PATHS[5]}",
    f"708\t45\t{CORRECTIVE_PATHS[6]}",
]
DNS_PATHS = [
    "app/Domain/Monitoring/Transports/NativeDnsTransport.php",
    "tests/Unit/Monitoring/NativeDnsTransportTest.php",
]
DNS_NAME_STATUS = [f"M\t{DNS_PATHS[0]}", f"A\t{DNS_PATHS[1]}"]
DNS_NUMSTAT = [f"278\t57\t{DNS_PATHS[0]}", f"430\t0\t{DNS_PATHS[1]}"]
FINAL_ISSUE_PATHS = list(dict.fromkeys(INITIAL_PATHS + CORRECTIVE_PATHS))

PRODUCER_SCRIPT = f"{PREFIX}/{PRODUCER_SCRIPT_REL}"
PRODUCER_OUTPUT = f"{PREFIX}/{PRODUCER_OUTPUT_REL}"
REVIEW_SCRIPT = f"{PREFIX}/{SCRIPT_REL}"
REVIEW_OUTPUT = f"{PREFIX}/{OUTPUT_REL}"
FINDINGS = f"{PREFIX}/findings.json"
EXPECTED_DIRTY = {
    PRODUCER_SCRIPT,
    PRODUCER_OUTPUT,
    REVIEW_SCRIPT,
    REVIEW_OUTPUT,
}


def git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args],
        cwd=ROOT,
        check=True,
        capture_output=True,
        text=True,
        encoding="utf-8",
    )
    return result.stdout.rstrip()


def git_bytes(revision: str, relative: str) -> bytes:
    return subprocess.run(
        ["git", "show", f"{revision}:{relative}"],
        cwd=ROOT,
        check=True,
        capture_output=True,
    ).stdout


def command_succeeds(*args: str) -> bool:
    return subprocess.run(["git", *args], cwd=ROOT, capture_output=True).returncode == 0


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def canonical_sha256(value: Any) -> str:
    return sha256(
        json.dumps(
            value,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        ).encode("utf-8")
    )


def strict_text(raw: bytes, label: str) -> None:
    assert not raw.startswith(b"\xef\xbb\xbf"), f"BOM not allowed: {label}"
    assert b"\r" not in raw, f"CR not allowed: {label}"
    assert raw.endswith(b"\n"), f"final LF required: {label}"
    for number, line in enumerate(raw.splitlines(), start=1):
        assert line == line.rstrip(b" \t"), f"trailing whitespace: {label}:{number}"


def strict_json(raw: bytes, label: str) -> dict[str, Any]:
    strict_text(raw, label)

    def no_duplicates(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, f"duplicate JSON key in {label}: {key}"
            result[key] = value
        return result

    value = json.loads(raw.decode("utf-8"), object_pairs_hook=no_duplicates)
    assert isinstance(value, dict)
    assert (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8") == raw
    return value


def file_record(relative: str, revision: str | None = None) -> dict[str, Any]:
    raw = git_bytes(revision, relative) if revision else (ROOT / relative).read_bytes()
    strict_text(raw, f"{revision or 'working'}:{relative}")
    return {
        "path": relative,
        "sha256": sha256(raw),
        "git_blob_id": (
            git("rev-parse", f"{revision}:{relative}")
            if revision
            else git("hash-object", "--", relative)
        ),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


def revision_records(paths: list[str], revision: str) -> list[dict[str, Any]]:
    return [file_record(path, revision) for path in paths]


def commit_metadata(revision: str) -> dict[str, Any]:
    fields = git("show", "-s", "--format=%H%x00%T%x00%P%x00%s", revision).split("\x00")
    assert len(fields) == 4
    return {
        "commit": fields[0],
        "tree": fields[1],
        "parents": fields[2].split() if fields[2] else [],
        "subject": fields[3],
    }


def stable_patch_id(before: str, after: str) -> str:
    diff = subprocess.run(
        ["git", "diff", before, after],
        cwd=ROOT,
        check=True,
        capture_output=True,
    ).stdout
    result = subprocess.run(
        ["git", "patch-id", "--stable"],
        cwd=ROOT,
        check=True,
        input=diff,
        capture_output=True,
    ).stdout.decode("ascii")
    return result.split()[0]


def status_lines() -> list[str]:
    return [line for line in git("status", "--porcelain").splitlines() if line]


def validate_repository() -> dict[str, Any]:
    expected_commits = {
        BASE: (BASE_TREE, ["70ffe18e8ffdb49ce5aa4e7c20eab0076c10c289", "2de69d8649786bab2742cd731cb9097af148b2f8"], "merge: ignore late monitoring state projections"),
        INITIAL_FIX: (INITIAL_FIX_TREE, [BASE], "fix(monitoring): deduplicate metric projection replays"),
        ADVANCED_MAIN: (ADVANCED_MAIN_TREE, ["15b2c988f4bb7f737727cc777ab32ad771c4be06"], "audit: seal RUN185 dashboard verification"),
        INITIAL_MERGE: (INITIAL_MERGE_TREE, [ADVANCED_MAIN, INITIAL_FIX], "Merge commit 'f521bc0b87222e56b4822e7cb9c935486e279e76'"),
        CORRECTIVE_FIX: (CORRECTIVE_FIX_TREE, [INITIAL_MERGE], "fix(monitoring): harden metric replay cutover"),
        CORRECTIVE_MERGE: (CORRECTIVE_MERGE_TREE, [INITIAL_MERGE, CORRECTIVE_FIX], "merge: harden metric replay cutover"),
        DNS_FIX: (DNS_FIX_TREE, [INITIAL_MERGE], "fix(monitoring): bind DNS responses to exact questions"),
        CURRENT_MAIN: (CURRENT_MAIN_TREE, [CORRECTIVE_MERGE, DNS_FIX], "merge: bind monitoring DNS responses"),
    }
    for revision, (tree, parents, subject) in expected_commits.items():
        metadata = commit_metadata(revision)
        assert metadata == {
            "commit": revision,
            "tree": tree,
            "parents": parents,
            "subject": subject,
        }

    assert git("rev-parse", "HEAD") == CURRENT_MAIN
    assert git("rev-parse", "HEAD^{tree}") == CURRENT_MAIN_TREE
    assert git("rev-parse", "origin/main") == ORIGIN_MAIN
    assert git("rev-list", "--left-right", "--count", "origin/main...main") == "0\t38"
    assert command_succeeds("merge-base", "--is-ancestor", BASE, INITIAL_FIX)
    assert command_succeeds("merge-base", "--is-ancestor", ADVANCED_MAIN, CURRENT_MAIN)
    assert command_succeeds("merge-base", "--is-ancestor", INITIAL_FIX, CURRENT_MAIN)
    assert command_succeeds("merge-base", "--is-ancestor", CORRECTIVE_FIX, CURRENT_MAIN)
    assert command_succeeds("merge-base", "--is-ancestor", DNS_FIX, CURRENT_MAIN)
    assert stable_patch_id(BASE, INITIAL_FIX) == INITIAL_PATCH_ID
    assert stable_patch_id(INITIAL_MERGE, CORRECTIVE_FIX) == CORRECTIVE_PATCH_ID
    assert stable_patch_id(INITIAL_MERGE, DNS_FIX) == DNS_PATCH_ID
    assert stable_patch_id(CORRECTIVE_MERGE, CURRENT_MAIN) == DNS_PATCH_ID

    assert git("diff", "--name-status", BASE, INITIAL_FIX).splitlines() == INITIAL_NAME_STATUS
    assert git("diff", "--numstat", BASE, INITIAL_FIX).splitlines() == INITIAL_NUMSTAT
    assert git("diff", "--name-status", ADVANCED_MAIN, INITIAL_MERGE).splitlines() == INITIAL_NAME_STATUS
    assert command_succeeds("diff", "--quiet", INITIAL_FIX, INITIAL_MERGE, "--", *INITIAL_PATHS)

    assert git("diff", "--name-status", INITIAL_MERGE, CORRECTIVE_FIX).splitlines() == CORRECTIVE_NAME_STATUS
    assert git("diff", "--numstat", INITIAL_MERGE, CORRECTIVE_FIX).splitlines() == CORRECTIVE_NUMSTAT
    assert git("diff", "--name-status", INITIAL_MERGE, CORRECTIVE_MERGE).splitlines() == CORRECTIVE_NAME_STATUS
    assert command_succeeds("diff", "--quiet", CORRECTIVE_FIX, CORRECTIVE_MERGE, "--", *CORRECTIVE_PATHS)

    assert git("diff", "--name-status", INITIAL_MERGE, DNS_FIX).splitlines() == DNS_NAME_STATUS
    assert git("diff", "--numstat", INITIAL_MERGE, DNS_FIX).splitlines() == DNS_NUMSTAT
    assert git("diff", "--name-status", CORRECTIVE_MERGE, CURRENT_MAIN).splitlines() == DNS_NAME_STATUS
    assert command_succeeds("diff", "--quiet", CORRECTIVE_MERGE, CURRENT_MAIN, "--", *FINAL_ISSUE_PATHS)
    assert git("diff", "--check") == ""

    dirty = {line[3:] for line in status_lines()}
    assert {PRODUCER_SCRIPT, PRODUCER_OUTPUT, REVIEW_SCRIPT}.issubset(dirty)
    assert dirty.issubset(EXPECTED_DIRTY), sorted(dirty)

    return {
        "initial_fix_records": revision_records(INITIAL_PATHS, INITIAL_FIX),
        "initial_merge_records": revision_records(INITIAL_PATHS, INITIAL_MERGE),
        "corrective_fix_records": revision_records(CORRECTIVE_PATHS, CORRECTIVE_FIX),
        "corrective_merge_records": revision_records(CORRECTIVE_PATHS, CORRECTIVE_MERGE),
        "current_final_issue_records": revision_records(FINAL_ISSUE_PATHS, CURRENT_MAIN),
        "current_disjoint_dns_records": revision_records(DNS_PATHS, CURRENT_MAIN),
    }


def validate_findings() -> dict[str, Any]:
    raw = git_bytes(CURRENT_MAIN, FINDINGS)
    findings = strict_json(raw, f"{CURRENT_MAIN}:{FINDINGS}")
    records = findings["records"]
    ids = [record["id"] for record in records]
    statuses = Counter(record["record_status"] for record in records)
    counts = findings["counts"]
    assert len(records) == len(ids) == len(set(ids)) == 14
    assert FINDING_ID not in ids
    assert statuses == {
        "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING": 8,
        "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING": 2,
        "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING": 4,
    }
    assert counts["retained_claim_records"] == 14
    assert counts["provisional_source_claims"] == 8
    assert counts["historical_already_fixed"] == 2
    assert counts["historical_remediated"] == 4
    assert counts["bounded_disposition_tests_passed"] == 99
    assert counts["bounded_disposition_assertions"] == 1931
    assert counts["static_source_feature_ownership_records"] == 666
    assert counts["static_source_feature_ownership_route_records"] == 309
    assert counts["static_source_feature_ownership_page_records"] == 357
    assert counts["static_controller_action_bridges"] == 97
    assert counts["direct_exact_queue_records"] == 507
    assert counts["direct_exact_queue_reviewed"] == 120
    assert counts["direct_exact_queue_pending_unreviewed"] == 387
    assert counts["direct_exact_queue_owned"] == 98
    assert counts["direct_exact_queue_without_ownership"] == 409
    assert counts["benchmark_mapped"] == 2
    assert counts["final_no_match"] == 0
    assert counts["benchmark_unresolved"] == 338
    return file_record(FINDINGS, CURRENT_MAIN)


def validate_producer(repository: dict[str, Any]) -> dict[str, Any]:
    script_record = file_record(PRODUCER_SCRIPT)
    assert script_record["sha256"] == EXPECTED_PRODUCER_SCRIPT_SHA256
    raw = (ROOT / PRODUCER_OUTPUT).read_bytes()
    assert sha256(raw) == EXPECTED_PRODUCER_RECEIPT_SHA256
    producer = strict_json(raw, PRODUCER_OUTPUT)
    copy = dict(producer)
    seal = copy.pop("receipt_self_seal_sha256")
    assert seal == EXPECTED_PRODUCER_SELF_SEAL == canonical_sha256(copy)

    assert producer["run_id"] == PRODUCER_RUN_ID
    assert producer["materialized_on"] == "2026-08-31"
    assert producer["pins"]["materializer"] == script_record
    assert producer["pins"]["application_baseline_commit"] == BASE
    assert producer["pins"]["application_baseline_tree"] == BASE_TREE
    assert producer["pins"]["initial_fix_commit"] == INITIAL_FIX
    assert producer["pins"]["initial_fix_tree"] == INITIAL_FIX_TREE
    assert producer["pins"]["clean_advanced_audit_main_commit"] == ADVANCED_MAIN
    assert producer["pins"]["clean_advanced_audit_main_tree"] == ADVANCED_MAIN_TREE
    assert producer["pins"]["initial_merge_commit"] == INITIAL_MERGE
    assert producer["pins"]["initial_merge_tree"] == INITIAL_MERGE_TREE
    assert producer["pins"]["corrective_fix_commit"] == CORRECTIVE_FIX
    assert producer["pins"]["corrective_fix_tree"] == CORRECTIVE_FIX_TREE
    assert producer["pins"]["corrective_merge_commit"] == CORRECTIVE_MERGE
    assert producer["pins"]["corrective_merge_tree"] == CORRECTIVE_MERGE_TREE
    assert producer["pins"]["current_local_main_commit"] == CURRENT_MAIN
    assert producer["pins"]["current_local_main_tree"] == CURRENT_MAIN_TREE
    assert producer["pins"]["origin_main_observed"] == ORIGIN_MAIN
    assert producer["pins"]["initial_stable_patch_id"] == INITIAL_PATCH_ID
    assert producer["pins"]["corrective_stable_patch_id"] == CORRECTIVE_PATCH_ID
    assert producer["pins"]["disjoint_dns_stable_patch_id"] == DNS_PATCH_ID
    assert producer["pins"]["local_main_ahead"] == 38
    assert producer["pins"]["local_main_behind"] == 0
    assert producer["pins"]["application_remote_publication_observed"] is False
    assert producer["pins"]["publication_authorized"] is False
    for key in (
        "initial_fix_records",
        "initial_merge_records",
        "corrective_fix_records",
        "corrective_merge_records",
        "current_final_issue_records",
        "current_disjoint_dns_records",
    ):
        assert producer["pins"][key] == repository[key]
    assert producer["pins"]["initial_name_status"] == INITIAL_NAME_STATUS
    assert producer["pins"]["initial_numstat"] == INITIAL_NUMSTAT
    assert producer["pins"]["corrective_name_status"] == CORRECTIVE_NAME_STATUS
    assert producer["pins"]["corrective_numstat"] == CORRECTIVE_NUMSTAT
    assert producer["pins"]["disjoint_dns_name_status"] == DNS_NAME_STATUS
    assert producer["pins"]["disjoint_dns_numstat"] == DNS_NUMSTAT

    issue = producer["issue_first_disposition"]
    assert issue["finding_id"] == FINDING_ID
    assert issue["record_status"] == RECORD_STATUS
    assert issue["candidate_feature_id"] is None
    assert issue["feature_identity_status"] == FEATURE_IDENTITY_STATUS
    assert issue["initial_exclusive_paths"] == INITIAL_PATHS
    assert issue["corrective_exclusive_paths"] == CORRECTIVE_PATHS
    assert issue["final_issue_path_union"] == FINAL_ISSUE_PATHS
    red = issue["root_post_merge_red_reproduction"]
    assert red["commit"] == INITIAL_MERGE
    assert red["temporary_change_reverted"] is True
    assert (red["tests"], red["failed"], red["passed"], red["assertions_reported"], red["exit_code"]) == (1, 1, 0, 0, 1)
    assert red["credit"] == "REPRODUCTION_ONLY_ZERO_PASSING_DENOMINATOR_CREDIT"

    initial = producer["initial_remediation_and_no_go"]
    assert initial["initial_changed_paths"] == 9
    assert initial["isolated_green_superseded"]["denominator_credit"] is False
    assert initial["post_merge_green_superseded"]["denominator_credit"] is False
    assert initial["post_merge_independent_disposition"] == "NO_GO"
    assert initial["initial_green_contributes_current_reporting_denominator"] is False

    corrective = producer["corrective_remediation"]
    assert corrective["changed_paths"] == 7
    assert corrective["already_applied_upgrade_migration_added"] == "2026_08_30_000110_govern_monitoring_metric_projection_cutover.php"
    assert corrective["poisoned_pre_f521_subsecond_bridge_fails_closed"] is True
    assert corrective["deployment_prerequisite"] == [
        "quiesce old monitoring workers",
        "reconcile pending or incoherent rows",
        "apply migration 000110",
        "start new workers only after cutover reconciliation",
    ]
    assert corrective["deployment_prerequisite_verified_in_production"] is False
    assert corrective["production_migration_or_release_credit"] is False

    runtime = producer["delegated_runtime_execution"]
    assert runtime["run_186_producer_executed_php_or_tests"] is False
    assert runtime["initial_green_runs"]["later_no_go"] is True
    assert runtime["initial_green_runs"]["denominator_credit"] is False
    assert runtime["root_red_reproduction"]["denominator_credit"] is False
    assert all(row["denominator_credit"] is False for row in runtime["initial_corrective_subsets_later_no_go"])
    assert runtime["first_corrective_full_green_later_no_go"]["denominator_credit"] is False
    assert runtime["stopped_option_a_target"]["denominator_credit"] is False
    assert runtime["final_corrected_targeted_support"]["denominator_credit"] is False
    assert runtime["final_corrected_isolated_full_focused"] == {
        "scope": "MetricRetentionTest plus RunMonitorCheckTest",
        "tests": 56,
        "assertions": 472,
        "duration_seconds": 154.66,
        "exit_code": 0,
        "denominator_credit": False,
    }
    assert runtime["final_corrected_post_merge_full_focused"] == {
        "scope": "MetricRetentionTest plus RunMonitorCheckTest",
        "tests": 56,
        "assertions": 472,
        "duration_seconds": 162.46,
        "exit_code": 0,
        "unique_bounded_disposition_denominator_credit": True,
    }
    assert runtime["unique_bounded_accounting"] == {
        "prior": {"tests": 99, "assertions": 1931},
        "increment": {"tests": 56, "assertions": 472},
        "proposed_after_run_186r_go": {"tests": 155, "assertions": 2403},
    }
    assert runtime["full_suite_or_coverage_credit"] is False

    review = producer["independent_review"]
    assert review["accepted_option"] == "A_CANONICAL_WHOLE_SECOND_MIXED_WORKER_BRIDGE"
    assert review["final_corrective_review_verdict"] == "GO"
    assert review["reviewers_executed_tests"] is False
    assert review["new_record_reporting_authorized"] is False
    assert review["run_186r_still_required"] is True

    cleanup = producer["cleanup_evidence"]
    assert cleanup["post_merge_global_php_or_pest_process_count"] == 0
    assert cleanup["numeric_pid_test_schema_count"] == 0
    assert cleanup["corrective_upgrade_schema_count"] == 0
    assert cleanup["exclusive_corrective_path_ownership_released"] is True
    assert cleanup["serialized_runtime_lane_released"] is True

    ownership = producer["static_ownership_boundary"]
    assert ownership == {
        "owner_records": 666,
        "route_owners": 309,
        "page_owners": 357,
        "action_bridges": 97,
        "queue_total": 507,
        "queue_reviewed": 120,
        "queue_pending": 387,
        "queue_owned": 98,
        "queue_without_ownership": 409,
        "next_zero_based_index": 85,
        "candidate_feature_id": None,
        "feature_identity_status": FEATURE_IDENTITY_STATUS,
        "correctness_does_not_adjudicate_static_ownership": True,
        "route_or_page_owner_authorized": False,
        "controller_action_bridge_authorized": False,
        "queue_advance_authorized": False,
        "fresh_outcome_neutral_semantic_review_required_later": True,
    }
    assert producer["benchmark_boundary"] == {
        "mapped": 2,
        "total": 340,
        "final_no_match_or_ncm": 0,
        "unresolved": 338,
        "changed_by_run_186": False,
    }
    dns = producer["current_main_disjoint_dns_noninheritance"]
    assert dns["finding_id"] == "MON-DNS-RESPONSE-BINDING-01"
    assert dns["paths"] == DNS_PATHS
    assert all(value is False for key, value in dns.items() if key.endswith("_in_run_186"))
    assert all(value is False for value in producer["noninheritance_boundary"].values())

    reporting = producer["reporting_boundary"]
    assert reporting["current_findings_snapshot"]["retained_record_count"] == 14
    assert reporting["current_findings_snapshot"]["monitoring_metric_replay_dedupe_record_present"] is False
    assert reporting["pending_candidate_feature_id"] is None
    assert reporting["pending_feature_identity_status"] == FEATURE_IDENTITY_STATUS
    assert reporting["proposed_after_independent_run_186r_go"] == {
        "retained_claim_records": 15,
        "current_provisional_source_claims": 8,
        "historical_already_fixed_records": 2,
        "historical_remediated_records": 5,
        "bounded_disposition_tests_passed": 155,
        "bounded_disposition_assertions": 2403,
        "final_P0": 0,
        "final_P1": 0,
    }
    assert reporting["independent_review_authorized"] is False
    assert reporting["run_186_changes_live_reporting"] is False
    assert reporting["run_186r_required"] is True
    assert reporting["run_187_reporting_required_after_go"] is True
    assert reporting["run_188_fresh_dashboard_verification_required_after_reporting"] is True

    assert [key for key, value in producer["credit_boundary"].items() if value] == [
        "historical_condition_confirmed",
        "current_defect_reproduced",
        "application_remediation",
        "corrective_application_remediation_after_no_go",
        "bounded_runtime",
        "bounded_metric_projection_replay_correctness",
        "application_commit_integrated_local_main",
    ]
    assert len(producer["completion_gates"]) == 26
    assert [row["gate"] for row in producer["completion_gates"]] == list(range(1, 27))
    assert all(row["complete"] is False for row in producer["completion_gates"])
    assert len(producer["completion_boundary"]) == 26
    assert all(value is False for value in producer["completion_boundary"].values())
    assert producer["artifact_completion_test_met"] is True
    assert producer["audit_completion_test_met"] is False
    return producer


def build_receipt(
    producer: dict[str, Any],
    findings_record: dict[str, Any],
) -> dict[str, Any]:
    completion_gates = producer["completion_gates"]
    completion_boundary = producer["completion_boundary"]
    review_checks = {
        "strict_json_zero_duplicate_keys": True,
        "lf_no_bom_no_trailing_whitespace": True,
        "pretty_json_round_trip": True,
        "canonical_self_seal": True,
        "prompt_hash_lineage_and_single_tenant_multi_site_boundary": True,
        "initial_base_fix_advanced_merge_metadata_and_patch_id": True,
        "initial_nine_path_status_numstat_blob_sha_bytes_lines": True,
        "corrective_fix_merge_metadata_patch_id_and_seven_path_delta": True,
        "current_main_dns_only_advance_and_issue_path_blob_stability": True,
        "root_red_reproduction_zero_passing_denominator_credit": True,
        "initial_and_intermediate_no_go_execution_zero_credit": True,
        "final_corrected_post_merge_56_472_counted_once": True,
        "current_register_14_records_and_monitoring_record_absent": True,
        "authorized_15_8_2_5_and_155_2403_arithmetic": True,
        "null_feature_identity_and_zero_static_ownership_credit": True,
        "option_a_writer_quiescence_and_reconciliation_boundary": True,
        "dns_facility_deployment_publication_and_completion_noninheritance": True,
        "cleanup_and_ownership_release_boundary": True,
        "all_26_completion_gates_false": True,
        "four_path_dirty_allowlist": True,
    }
    credit = {
        "independent_exact_artifact_review_for_new_historical_remediated_reporting": True,
        "application_remediation_reexecution": False,
        "runtime_reexecution": False,
        "application_publication": False,
        "live_reporting": False,
        "canonical_or_candidate_feature_identity": False,
        "static_route_or_page_feature_ownership": False,
        "static_controller_action_bridge": False,
        "queue_advance": False,
        "application_browser": False,
        "benchmark_mapping": False,
        "final_no_match_or_ncm": False,
        "migration_deployment": False,
        "dns_or_facility_finding_reporting": False,
        "final_finding": False,
        "pass": False,
        "release": False,
        "completion": False,
        "audit_complete": False,
    }
    receipt: dict[str, Any] = {
        "schema_version": (
            "run-186r-independent-monitoring-metric-replay-dedupe-"
            "remediation-review-wave-36-v1"
        ),
        "run_id": RUN_ID,
        "status": STATUS,
        "materialized_on": "2026-08-31",
        "architecture_boundary": producer["architecture_boundary"],
        "prompt_lineage": producer["prompt_lineage"],
        "pins": {
            "application_baseline_commit": BASE,
            "application_baseline_tree": BASE_TREE,
            "initial_fix_commit": INITIAL_FIX,
            "initial_fix_tree": INITIAL_FIX_TREE,
            "initial_merge_commit": INITIAL_MERGE,
            "initial_merge_tree": INITIAL_MERGE_TREE,
            "corrective_fix_commit": CORRECTIVE_FIX,
            "corrective_fix_tree": CORRECTIVE_FIX_TREE,
            "corrective_merge_commit": CORRECTIVE_MERGE,
            "corrective_merge_tree": CORRECTIVE_MERGE_TREE,
            "current_local_main_commit": CURRENT_MAIN,
            "current_local_main_tree": CURRENT_MAIN_TREE,
            "origin_main_observed": ORIGIN_MAIN,
            "initial_stable_patch_id": INITIAL_PATCH_ID,
            "corrective_stable_patch_id": CORRECTIVE_PATCH_ID,
            "disjoint_dns_stable_patch_id": DNS_PATCH_ID,
            "application_published": False,
            "producer_materializer": file_record(PRODUCER_SCRIPT),
            "producer_receipt": {
                **file_record(PRODUCER_OUTPUT),
                "receipt_self_seal_sha256": EXPECTED_PRODUCER_SELF_SEAL,
            },
            "review_materializer": file_record(REVIEW_SCRIPT),
            "current_findings_before_run_187": findings_record,
            "dirty_allowlist": sorted(EXPECTED_DIRTY),
        },
        "review": {
            "reviewer_lane": "/root/run186_metric_artifact_review",
            "independent_from_application_remediation_and_producer_lanes": True,
            "reviewer_executed_php_tests_database_browser_or_network": False,
            "reviewer_wrote_application_live_reporting_or_html_files": False,
            "checks": review_checks,
            "discrepancies": [],
        },
        "decision": {
            "verdict": "GO",
            "blocking_discrepancies": 0,
            "new_historical_remediated_record_reporting_authorized": True,
            "authorized_live_reporting_run": "RUN-187",
            "authorized_finding_id": FINDING_ID,
            "authorized_feature_id": None,
            "authorized_candidate_feature_id": None,
            "authorized_feature_identity_status": FEATURE_IDENTITY_STATUS,
            "authorized_reporting_status": RECORD_STATUS,
            "authorized_live_count_delta": {
                "retained_claim_records": 1,
                "current_provisional_source_claims": 0,
                "historical_already_fixed_records": 0,
                "historical_remediated_records": 1,
                "bounded_disposition_tests_passed": 56,
                "bounded_disposition_assertions": 472,
                "final_P0": 0,
                "final_P1": 0,
                "benchmark_mapped": 0,
                "final_no_match_or_ncm": 0,
                "benchmark_unresolved": 0,
                "static_owner_records": 0,
                "static_route_owners": 0,
                "static_page_owners": 0,
                "static_controller_action_bridges": 0,
                "direct_exact_queue_reviewed": 0,
            },
            "authorized_resulting_lineage": {
                "retained_claim_records": 15,
                "current_provisional_source_claims": 8,
                "historical_already_fixed_records": 2,
                "historical_remediated_records": 5,
                "final_P0": 0,
                "final_P1": 0,
            },
            "authorized_unique_bounded_disposition_increment": {
                "prior_tests": 99,
                "prior_assertions": 1931,
                "tests": 56,
                "assertions": 472,
                "resulting_tests": 155,
                "resulting_assertions": 2403,
                "final_corrected_post_merge_counted_once": True,
                "root_red_reproduction_counted": False,
                "initial_isolated_or_post_merge_green_counted": False,
                "intermediate_corrective_or_stopped_runs_counted": False,
                "final_corrected_isolated_replay_counted": False,
                "dns_63_186_counted": False,
                "facility_execution_counted": False,
            },
            "static_ownership_remains_pending": {
                "owner_records": 666,
                "route_owners": 309,
                "page_owners": 357,
                "action_bridges": 97,
                "queue_total": 507,
                "queue_reviewed": 120,
                "queue_pending": 387,
                "queue_owned": 98,
                "queue_without_ownership": 409,
                "next_zero_based_index": 85,
                "feature_id": None,
                "candidate_feature_id": None,
                "feature_identity_status": FEATURE_IDENTITY_STATUS,
                "route_or_page_owner_authorized": False,
                "controller_action_bridge_authorized": False,
                "queue_advance_authorized": False,
            },
            "option_a_deployment_boundary": {
                "accepted_option": "A_CANONICAL_WHOLE_SECOND_MIXED_WORKER_BRIDGE",
                "prerequisites": producer["corrective_remediation"]["deployment_prerequisite"],
                "poisoned_subsecond_evidence_requires_operator_reconciliation": True,
                "verified_in_production": False,
                "migration_deployment_credit": False,
            },
            "live_reporting_changed_by_run_186r": False,
            "run_187_required": True,
            "run_188_fresh_dashboard_verification_required": True,
        },
        "credit_boundary": credit,
        "completion_gates": completion_gates,
        "completion_boundary": completion_boundary,
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [REVIEW_SCRIPT, REVIEW_OUTPUT],
    }
    assert [key for key, value in credit.items() if value] == [
        "independent_exact_artifact_review_for_new_historical_remediated_reporting"
    ]
    assert all(review_checks.values())
    assert len(completion_gates) == 26
    assert all(row["complete"] is False for row in completion_gates)
    assert len(completion_boundary) == 26
    assert all(value is False for value in completion_boundary.values())
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    return receipt


def validate_review(receipt: dict[str, Any]) -> None:
    copy = dict(receipt)
    seal = copy.pop("receipt_self_seal_sha256")
    assert seal == canonical_sha256(copy)
    assert receipt["decision"]["verdict"] == "GO"
    assert receipt["decision"]["blocking_discrepancies"] == 0
    assert receipt["decision"]["authorized_feature_id"] is None
    assert receipt["decision"]["authorized_candidate_feature_id"] is None
    assert receipt["decision"]["authorized_feature_identity_status"] == FEATURE_IDENTITY_STATUS
    assert receipt["decision"]["authorized_resulting_lineage"] == {
        "retained_claim_records": 15,
        "current_provisional_source_claims": 8,
        "historical_already_fixed_records": 2,
        "historical_remediated_records": 5,
        "final_P0": 0,
        "final_P1": 0,
    }
    increment = receipt["decision"]["authorized_unique_bounded_disposition_increment"]
    assert (increment["prior_tests"], increment["prior_assertions"]) == (99, 1931)
    assert (increment["tests"], increment["assertions"]) == (56, 472)
    assert (increment["resulting_tests"], increment["resulting_assertions"]) == (155, 2403)
    assert increment["final_corrected_post_merge_counted_once"] is True
    for key, value in increment.items():
        if key.endswith("_counted") and key != "final_corrected_post_merge_counted_once":
            assert value is False
    assert receipt["decision"]["option_a_deployment_boundary"]["verified_in_production"] is False
    assert receipt["decision"]["option_a_deployment_boundary"]["migration_deployment_credit"] is False
    assert [key for key, value in receipt["credit_boundary"].items() if value] == [
        "independent_exact_artifact_review_for_new_historical_remediated_reporting"
    ]
    assert len(receipt["completion_gates"]) == 26
    assert all(row["complete"] is False for row in receipt["completion_gates"])
    assert all(value is False for value in receipt["completion_boundary"].values())
    assert receipt["artifact_completion_test_met"] is True
    assert receipt["audit_completion_test_met"] is False


def main() -> None:
    repository = validate_repository()
    findings_record = validate_findings()
    producer = validate_producer(repository)
    receipt = build_receipt(producer, findings_record)
    validate_review(receipt)
    encoded = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_bytes(encoded)
    assert OUTPUT.read_bytes() == encoded
    reloaded = strict_json(OUTPUT.read_bytes(), REVIEW_OUTPUT)
    assert reloaded == receipt
    validate_review(reloaded)
    assert {line[3:] for line in status_lines()} == EXPECTED_DIRTY
    print(
        json.dumps(
            {
                "run_id": RUN_ID,
                "status": STATUS,
                "materializer_sha256": file_record(REVIEW_SCRIPT)["sha256"],
                "receipt_sha256": sha256(encoded),
                "receipt_self_seal_sha256": receipt["receipt_self_seal_sha256"],
                "verdict": "GO",
                "run_187_historical_remediated_reporting_authorized": True,
                "authorized_result": "15 = 8 provisional + 2 already-fixed + 5 remediated",
                "authorized_unique_bounded_total": "155/2403",
                "feature_id": None,
                "candidate_feature_id": None,
                "static_ownership_adjudicated": False,
                "application_published": False,
                "all_26_completion_gates_complete": False,
                "audit_complete": False,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
