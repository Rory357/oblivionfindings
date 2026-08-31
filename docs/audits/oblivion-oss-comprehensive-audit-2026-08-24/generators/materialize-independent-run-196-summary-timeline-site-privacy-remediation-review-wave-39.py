#!/usr/bin/env python3
"""Materialize the independent exact-artifact review of RUN196.

This reviewer validates the frozen RUN196 producer and receipt plus their Git,
runtime-credit, reporting, and completion boundaries. It writes only RUN196R;
it does not rerun PHP, use a database or browser, mutate product/live reporting,
publish commits, or grant static ownership, benchmark, final-finding, release,
Gate 4, module-completion, or audit-completion credit.
"""
from __future__ import annotations

import hashlib
import json
import math
import os
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
    "evidence/runtime/"
    "current-run-196r-independent-summary-timeline-site-privacy-"
    "remediation-review-wave-39.json"
)
OUTPUT = AUDIT / OUTPUT_REL
PRODUCER_REL = (
    "generators/"
    "materialize-run-196-summary-timeline-site-privacy-remediation-wave-39.py"
)
RECEIPT_REL = (
    "evidence/runtime/"
    "current-run-196-summary-timeline-site-privacy-remediation-wave-39.json"
)
FINDINGS_REL = "findings.json"
RUN195_REL = (
    "evidence/browser/"
    "current-audit-dashboard-verification-run-195-wave-38.json"
)

RUN_ID = (
    "RUN-196R-INDEPENDENT-SUMMARY-TIMELINE-SITE-PRIVACY-01-"
    "REMEDIATION-REVIEW-WAVE-39"
)
STATUS = (
    "GO_EXACT_RUN196_ARTIFACT_REVIEW_NEW_HISTORICAL_REMEDIATED_REPORTING_"
    "AUTHORIZED_ZERO_STATIC_OWNERSHIP_PUBLICATION_FINAL_FINDING_OR_"
    "COMPLETION_CREDIT"
)
PRODUCER_RUN_ID = (
    "RUN-196-SUMMARY-TIMELINE-SITE-PRIVACY-01-REMEDIATION-WAVE-39"
)
PRODUCER_STATUS = (
    "CURRENT_SUMMARY_TIMELINE_SITE_PRIVACY_DEFECT_REPRODUCED_REMEDIATED_"
    "LOCALLY_INTEGRATED_BOUNDED_VERIFIED_NOT_PUBLISHED_REPORTING_NOT_YET_"
    "AUTHORIZED_ZERO_STATIC_OWNERSHIP_FINAL_FINDING_OR_COMPLETION_CREDIT"
)
FINDING_ID = "SUMMARY-TIMELINE-SITE-PRIVACY-01"

BASE = "39a5d97d7d0ff9ea03070e90193581479f423022"
BASE_TREE = "90b9adba1261fb1ec30d9fe4b13daaf5149fc1dc"
AUDIT_RELEASE = "4c47d2eeed0b1006c11166da8ab8b0747d7554b7"
AUDIT_RELEASE_TREE = "67d02dab74cdb608a019432bcb032520cd02db3e"
FIX = "31a9edfbab32a19062ccf15e123cd0b0923b7dc3"
FIX_TREE = "5e8e8f5e560b5ff2d157902808e2c0b5e17952f5"
MERGE = "5c8a1357f830d0b8a8c14924016d89df52ab9e86"
MERGE_TREE = "974af4e10eea90e9e9254d509443b49cf0052931"
CURRENT_MAIN = "44ab5e270aecd961e2e75abcdbe4d2cb1effa3df"
CURRENT_TREE = "cae56eafa2c63af68e099995b08b3c926575373b"
MY_DAY_FIX = "caec88e054e1dbd546b0b18a1a0df19618f8311d"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"

EXPECTED_PRODUCER = {
    "path": PRODUCER_REL,
    "sha256": "e8c45110a983d2d210501024d89d6f9b968103141b86feb174c5641757dd5555",
    "git_blob_id": "e20e14e236c6bab19c4ceeaedace7f64e5d6e03e",
    "bytes": 25092,
    "lines": 659,
}
EXPECTED_RECEIPT = {
    "path": RECEIPT_REL,
    "sha256": "96c275826a695a4b41b98891bd6560e6592be415c43fa360f1730c0c7fe9013a",
    "git_blob_id": "27a3b7967621c3238379ad1a0784cfd63161d84b",
    "bytes": 20902,
    "lines": 543,
    "receipt_self_seal_sha256": (
        "325269d2a0721c620c9a588da65c016b2355f8c5fb51e6ec112156888483609c"
    ),
}
EXPECTED_FINDINGS = {
    "path": FINDINGS_REL,
    "sha256": "268b63e20dcc40ecc0ba772e8431a9d8a35c9df9bfa98197abdfc273e972e525",
    "git_blob_id": "6735e906278726d34dcbd6aba30e5feb5f60b27f",
    "bytes": 664123,
    "lines": 11633,
}
RUN195_SHA256 = (
    "455ee26c87ec6f07eca687eb1e40d2049c01513002732d08f74696b3dd617456"
)
RUN195_SELF_SEAL = (
    "a3dc0871156ba4c6376a92a4cacab8b8697fa0efcd49dea42d212533aff6b284"
)

CHANGED_PATHS = [
    "app/Http/Controllers/SummaryController.php",
    "app/Http/Controllers/TimelineController.php",
    "app/Jobs/GenerateSummaryJob.php",
    "tests/Feature/Security/SummaryRagTimelineAuthorizationTest.php",
]
ADVANCED_MY_DAY_PATHS = [
    "app/Http/Controllers/MyDayActionsController.php",
    "app/Http/Controllers/MyTasksController.php",
    "app/Services/ControlRoom/ControlRoomAlertLifecycleService.php",
    "tests/Feature/MyDayControlRoomSlaTest.php",
]
STALE_TEST_PATH = (
    "tests/Feature/ControlRoom/ControlRoomAlertLifecycleServiceTest.php"
)
CANONICAL_TEST_PATH = (
    "tests/Unit/ControlRoom/ControlRoomAlertLifecycleServiceTest.php"
)
COMPLETION_GATE_NAMES = [
    "routes_classified",
    "inertia_pages_classified",
    "features_in_canonical_register",
    "routes_and_pages_mapped_to_feature_id",
    "features_with_verified_benchmark_or_final_ncm",
    "human_features_with_task_script_and_ten_scores",
    "common_and_safety_journeys_cross_reviewed",
    "hero_banner_instances_classified",
    "overlay_implementations_and_triggers_classified",
    "safe_routes_observed_at_desktop",
    "selected_families_and_journeys_all_viewports",
    "required_visual_states_classified",
    "material_visual_finding_families_resampled",
    "models_classified",
    "policies_classified",
    "service_domain_entries_classified",
    "critical_async_owners_classified",
    "modules_with_all_eight_passes",
    "prompt_benchmark_projects_formally_triaged",
    "p0_p1_complete_finding_fields",
    "redesigns_neutral_native_no_copy",
    "ease_4_5_claims_independently_reviewed",
    "browser_claims_labeled",
    "visual_inconsistencies_complete_context",
    "official_source_inference_specialist_split",
    "all_agents_returned_reconciled_represented_none_live",
]
OWNED_PATHS = sorted(
    [
        f"{PREFIX}/{PRODUCER_REL}",
        f"{PREFIX}/{RECEIPT_REL}",
        f"{PREFIX}/{SCRIPT_REL}",
        f"{PREFIX}/{OUTPUT_REL}",
    ]
)


def run_text(*command: str) -> str:
    return subprocess.run(
        list(command),
        cwd=ROOT,
        check=True,
        capture_output=True,
        text=True,
        encoding="utf-8",
    ).stdout.rstrip()


def run_bytes(*command: str) -> bytes:
    return subprocess.run(
        list(command), cwd=ROOT, check=True, capture_output=True
    ).stdout


def git(*args: str) -> str:
    return run_text("git", *args)


def git_bytes(revision: str, relative: str) -> bytes:
    return run_bytes("git", "show", f"{revision}:{relative}")


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def canonical_sha256(value: Any) -> str:
    return sha256(
        json.dumps(
            value,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
            allow_nan=False,
        ).encode("utf-8")
    )


def assert_finite(value: Any) -> None:
    if isinstance(value, float):
        assert math.isfinite(value)
    elif isinstance(value, dict):
        for item in value.values():
            assert_finite(item)
    elif isinstance(value, list):
        for item in value:
            assert_finite(item)


def strict_text(raw: bytes, label: str) -> None:
    assert not raw.startswith(b"\xef\xbb\xbf"), f"BOM not allowed: {label}"
    assert b"\r" not in raw, f"CR not allowed: {label}"
    assert raw.endswith(b"\n"), f"final LF required: {label}"
    for number, line in enumerate(raw.splitlines(), start=1):
        assert line == line.rstrip(b" \t"), f"trailing whitespace: {label}:{number}"


def strict_json(path: Path) -> dict[str, Any]:
    raw = path.read_bytes()
    strict_text(raw, str(path))

    def no_duplicates(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, f"duplicate JSON key: {path}:{key}"
            result[key] = value
        return result

    value = json.loads(
        raw.decode("utf-8"),
        object_pairs_hook=no_duplicates,
        parse_constant=lambda token: (_ for _ in ()).throw(
            AssertionError(f"non-finite JSON token: {token}")
        ),
    )
    assert isinstance(value, dict)
    assert_finite(value)
    expected = (
        json.dumps(value, ensure_ascii=False, indent=2, allow_nan=False) + "\n"
    ).encode("utf-8")
    assert raw == expected, f"pretty JSON round-trip failed: {path}"
    return value


def verify_self_seal(value: dict[str, Any], expected: str) -> None:
    without_seal = dict(value)
    observed = without_seal.pop("receipt_self_seal_sha256")
    assert observed == expected
    assert canonical_sha256(without_seal) == expected


def text_record(relative: str, raw: bytes) -> dict[str, Any]:
    strict_text(raw, relative)
    return {
        "path": relative,
        "sha256": sha256(raw),
        "git_blob_id": hashlib.sha1(
            f"blob {len(raw)}\0".encode("ascii") + raw
        ).hexdigest(),
        "bytes": len(raw),
        "lines": len(raw.splitlines()),
    }


def working_record(relative: str) -> dict[str, Any]:
    return text_record(relative, (AUDIT / relative).read_bytes())


def repository_record(revision: str, relative: str) -> dict[str, Any]:
    return text_record(relative, git_bytes(revision, relative))


def commit_record(commit: str, tree: str) -> dict[str, Any]:
    assert git("cat-file", "-t", commit) == "commit"
    assert git("rev-parse", f"{commit}^{{tree}}") == tree
    return {
        "commit": commit,
        "tree": tree,
        "parents": git("show", "-s", "--format=%P", commit).split(),
        "subject": git("show", "-s", "--format=%s", commit),
    }


def changed_paths(parent: str, commit: str) -> list[dict[str, str]]:
    return [
        {"status": line.split("\t", 1)[0], "path": line.split("\t", 1)[1]}
        for line in git("diff", "--name-status", parent, commit).splitlines()
        if line
    ]


def diff_record(parent: str, commit: str, relative: str) -> dict[str, Any]:
    raw = git("diff", "--numstat", parent, commit, "--", relative)
    assert raw
    added, deleted, observed = raw.split("\t")
    assert observed == relative
    binary = run_bytes("git", "diff", "--binary", parent, commit, "--", relative)
    return {
        "path": relative,
        "insertions": int(added),
        "deletions": int(deleted),
        "binary_diff_sha256": sha256(binary),
    }


def porcelain_paths() -> list[dict[str, str]]:
    raw = run_bytes("git", "status", "--porcelain=v1", "-z")
    result: list[dict[str, str]] = []
    for item in raw.split(b"\x00"):
        if not item:
            continue
        decoded = item.decode("utf-8")
        result.append(
            {"state": decoded[:2], "path": decoded[3:].replace("\\", "/")}
        )
    return sorted(result, key=lambda item: item["path"])


def validate_owned_scope(output_required: bool) -> list[dict[str, str]]:
    observed = porcelain_paths()
    observed_paths = sorted(item["path"] for item in observed)
    before_output = [
        path for path in OWNED_PATHS if path != f"{PREFIX}/{OUTPUT_REL}"
    ]
    if output_required:
        assert observed_paths == OWNED_PATHS, observed
    else:
        assert observed_paths in (before_output, OWNED_PATHS), observed
    assert all(item["state"] == "??" for item in observed), observed
    assert subprocess.run(
        ["git", "diff", "--cached", "--quiet"], cwd=ROOT
    ).returncode == 0
    assert subprocess.run(
        ["git", "diff", "--check"], cwd=ROOT
    ).returncode == 0
    return observed


def validate_repository() -> dict[str, Any]:
    assert git("rev-parse", "HEAD") == CURRENT_MAIN
    assert git("rev-parse", "main") == CURRENT_MAIN
    assert git("rev-parse", "HEAD^{tree}") == CURRENT_TREE
    assert git("rev-parse", "origin/main") == ORIGIN_MAIN
    assert git("rev-list", "--left-right", "--count", "origin/main...main") == (
        "0\t83"
    )
    validate_owned_scope(output_required=False)

    baseline = commit_record(BASE, BASE_TREE)
    audit_release = commit_record(AUDIT_RELEASE, AUDIT_RELEASE_TREE)
    fix = commit_record(FIX, FIX_TREE)
    merge = commit_record(MERGE, MERGE_TREE)
    current = commit_record(CURRENT_MAIN, CURRENT_TREE)
    assert audit_release["subject"] == "audit: verify RUN195 current dashboard"
    assert fix["parents"] == [BASE]
    assert fix["subject"] == "fix(security): scope summaries and timelines to Sites"
    assert merge["parents"] == [AUDIT_RELEASE, FIX]
    assert merge["subject"] == "merge: scope summaries and timelines to Sites"
    assert current["parents"] == [MERGE, MY_DAY_FIX]
    assert current["subject"] == "merge: scope My Day alerts to Sites"

    expected_summary = [{"status": "M", "path": path} for path in CHANGED_PATHS]
    expected_my_day = [
        {"status": "M", "path": path} for path in ADVANCED_MY_DAY_PATHS
    ]
    assert changed_paths(BASE, FIX) == expected_summary
    assert changed_paths(AUDIT_RELEASE, MERGE) == expected_summary
    assert changed_paths(MERGE, CURRENT_MAIN) == expected_my_day
    assert subprocess.run(
        ["git", "diff", "--quiet", BASE, AUDIT_RELEASE, "--", *CHANGED_PATHS],
        cwd=ROOT,
    ).returncode == 0
    for path in CHANGED_PATHS:
        fixed_blob = git("rev-parse", f"{FIX}:{path}")
        assert fixed_blob == git("rev-parse", f"{MERGE}:{path}")
        assert fixed_blob == git("rev-parse", f"{CURRENT_MAIN}:{path}")
        assert (ROOT / path).read_bytes() == git_bytes(CURRENT_MAIN, path)
    assert not (ROOT / STALE_TEST_PATH).exists()
    assert (ROOT / CANONICAL_TEST_PATH).is_file()

    run195_path = AUDIT / RUN195_REL
    assert sha256(run195_path.read_bytes()) == RUN195_SHA256
    run195 = strict_json(run195_path)
    verify_self_seal(run195, RUN195_SELF_SEAL)
    assert run195["artifact_completion_test_met"] is True
    assert run195["audit_completion_test_met"] is False
    return {
        "baseline": baseline,
        "audit_release": audit_release,
        "sealed_fix": fix,
        "effective_merge": merge,
        "current_main": current,
        "origin_main": ORIGIN_MAIN,
        "origin_main_behind": 0,
        "origin_main_ahead": 83,
        "application_published": False,
        "advanced_my_day_paths": ADVANCED_MY_DAY_PATHS,
        "advanced_my_day_credit_inherited": False,
        "run195": {
            "path": RUN195_REL,
            "sha256": RUN195_SHA256,
            "receipt_self_seal_sha256": RUN195_SELF_SEAL,
        },
        "owned_worktree_boundary": [
            {"state": "??", "path": path} for path in OWNED_PATHS
        ],
    }


def validate_producer(repository: dict[str, Any]) -> dict[str, Any]:
    assert working_record(PRODUCER_REL) == EXPECTED_PRODUCER
    receipt_record = working_record(RECEIPT_REL)
    assert receipt_record == {
        key: EXPECTED_RECEIPT[key]
        for key in ("path", "sha256", "git_blob_id", "bytes", "lines")
    }
    producer = strict_json(AUDIT / RECEIPT_REL)
    verify_self_seal(producer, EXPECTED_RECEIPT["receipt_self_seal_sha256"])
    assert producer["schema_version"] == 1
    assert producer["run_id"] == PRODUCER_RUN_ID
    assert producer["status"] == PRODUCER_STATUS
    assert producer["evidence_date"] == "2026-09-01"

    scope = producer["scope"]
    assert scope["finding_id"] == FINDING_ID
    assert scope["architecture"] == (
        "single operating organisation across multiple Sites"
    )
    assert scope["tenant_design_introduced"] is False
    assert scope["application_source_mutated_by_this_materializer"] is False
    assert scope["runtime_executed_by_this_materializer"] is False
    assert scope["browser_executed_by_this_materializer"] is False
    assert scope["reporting_not_yet_materialized"] is True
    assert producer["governing_inputs"] == {
        "governing_prompt_sha256": (
            "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
        ),
        "continuation_prompt_sha256": (
            "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d"
        ),
        "explicit_issue_first_override_applied": True,
    }

    lineage = producer["lineage"]
    assert lineage["owned_worktree_boundary"] == [
        {"state": "??", "path": f"{PREFIX}/{RECEIPT_REL}"},
        {"state": "??", "path": f"{PREFIX}/{PRODUCER_REL}"},
    ]
    for key in ("baseline", "audit_release", "sealed_fix", "effective_merge", "current_main"):
        assert lineage[key] == repository[key]
    assert lineage["advanced_unrelated_paths"] == ADVANCED_MY_DAY_PATHS
    assert lineage["advanced_unrelated_paths_credit_inherited"] is False
    assert lineage["run195"] == repository["run195"]

    finding = producer["finding"]
    assert finding["finding_id"] == FINDING_ID
    assert finding["feature_identity"] == {
        "feature_id": None,
        "candidate_feature_id": None,
        "status": "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW",
        "static_ownership_credit": False,
    }
    assert finding["red_reproduction"] == {
        "command": (
            "php artisan test tests/Feature/Security/"
            "SummaryRagTimelineAuthorizationTest.php --stop-on-failure "
            "--colors=never"
        ),
        "exit_code": 1,
        "failed": 1,
        "passed": 5,
        "assertions": 9,
        "duration_seconds": 148.82,
        "observed": "remote-Site staff summary returned 200 instead of 403",
    }
    assert finding["zero_credit_runs"] == [
        {
            "reason": (
                "vendor junction resolved Composer application classes into primary"
            ),
            "phase": "before Laravel bootstrap",
            "assertions": 0,
        }
    ]
    assert finding["changed_paths"] == CHANGED_PATHS
    assert len(finding["file_lineage"]) == 4
    for item, path in zip(finding["file_lineage"], CHANGED_PATHS, strict=True):
        assert item["path"] == path
        assert item["baseline"] == repository_record(BASE, path)
        assert item["sealed_fix"] == repository_record(FIX, path)
        assert item["effective_merge"] == repository_record(MERGE, path)
        assert item["current_main"] == repository_record(CURRENT_MAIN, path)
        assert item["fix_diff"] == diff_record(BASE, FIX, path)
        assert item["merge_blob_identical_to_fix"] is True
        assert item["current_blob_identical_to_fix"] is True
    assert finding["isolated_focused_verification"] == {
        "command": (
            "php artisan test tests/Feature/Security/"
            "SummaryRagTimelineAuthorizationTest.php --colors=never"
        ),
        "exit_code": 0,
        "passed": 15,
        "assertions": 32,
        "duration_seconds": 147.9,
        "eligible_for_bounded_aggregate": True,
    }
    assert finding["isolated_supporting_compatibility"] == {
        "command": (
            "php artisan test tests/Feature/Emar/"
            "MedicationTimelineProjectionVisibilityTest.php --colors=never"
        ),
        "exit_code": 0,
        "passed": 2,
        "assertions": 238,
        "duration_seconds": 149.2,
        "eligible_for_bounded_aggregate": False,
        "reason": (
            "supporting compatibility execution is separately reported and not recredited"
        ),
    }
    assert finding["independent_source_and_test_review"]["disposition"] == "GO"

    shared = producer["post_merge_shared_support"]
    assert shared["exit_code"] == 0
    assert shared["passed"] == 40
    assert shared["assertions"] == 438
    assert shared["duration_seconds"] == 193.7
    assert shared["eligible_for_bounded_aggregate"] is False
    assert shared["shared_denominator_not_split_or_recredited"] is True
    assert shared["zero_credit_runs"] == [
        {
            "reason": "wrong Medication test directory",
            "phase": "before test execution",
            "tests_executed": 0,
        }
    ]
    assert producer["handoff_path_adjudication"] == {
        "stale_message_path": STALE_TEST_PATH,
        "stale_path_exists": False,
        "canonical_repository_path": CANONICAL_TEST_PATH,
        "canonical_path_exists": True,
        "credit_uses_canonical_path_only": True,
    }

    cleanup = producer["cleanup_and_release"]
    assert cleanup["at_fix_lane_release"] == {
        "global_php_pest_process_count": 0,
        "numeric_pid_suffixed_schema_count": 0,
        "primary_status_empty": True,
        "origin_main_behind": 0,
        "origin_main_ahead": 83,
        "push_performed": False,
    }
    assert cleanup["removed_clean_worktree"] == (
        "C:/w/summary-timeline-site-privacy-01"
    )
    assert cleanup["retained_recovery_branch"] == (
        "codex/summary-timeline-site-privacy-01"
    )
    assert cleanup["all_four_paths_released"] is True
    assert cleanup["serialized_runtime_lane_released"] is True

    credit = producer["credit_boundary"]
    assert [key for key, value in credit.items() if value is True] == [
        "exact_remediation_source_credit",
        "exact_focused_test_execution_credit",
        "local_merge_credit",
        "cleanup_credit",
    ]
    assert credit["my_day_fix_or_runtime_credit"] is False
    assert credit["static_route_action_ownership_credit"] is False
    assert credit["static_page_or_frontend_ownership_credit"] is False
    assert credit["queue_advance_credit"] is False
    assert credit["benchmark_or_comparator_credit"] is False
    assert credit["final_ncm_credit"] is False
    assert credit["publication_credit"] is False
    assert credit["final_finding_reporting_credit"] is False
    assert credit["gate_4_credit"] is False
    assert credit["audit_completion_credit"] is False
    assert producer["materializer"] == {
        "path": f"{PREFIX}/{PRODUCER_REL}",
        **{
            key: EXPECTED_PRODUCER[key]
            for key in ("sha256", "bytes", "lines")
        },
    }
    assert [item["name"] for item in producer["completion_gates"]] == (
        COMPLETION_GATE_NAMES
    )
    assert all(item["complete"] is False for item in producer["completion_gates"])
    assert list(producer["completion_boundary"]) == COMPLETION_GATE_NAMES
    assert all(value is False for value in producer["completion_boundary"].values())
    assert producer["artifact_completion_test_met"] is True
    assert producer["audit_completion_test_met"] is False
    return producer


def validate_live_register() -> dict[str, Any]:
    findings = strict_json(AUDIT / FINDINGS_REL)
    assert working_record(FINDINGS_REL) == EXPECTED_FINDINGS
    records = findings["records"]
    ids = [record["id"] for record in records]
    statuses = Counter(record["record_status"] for record in records)
    assert len(records) == len(ids) == len(set(ids)) == 16
    assert FINDING_ID not in ids
    assert statuses == {
        "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING": 8,
        "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING": 2,
        "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING": 6,
    }
    counts = findings["counts"]
    assert counts["retained_claim_records"] == 16
    assert counts["provisional_source_claims"] == 8
    assert counts["historical_already_fixed"] == 2
    assert counts["historical_remediated"] == 6
    assert counts["bounded_disposition_tests_passed"] == 161
    assert counts["bounded_disposition_assertions"] == 2609
    assert counts["static_source_feature_ownership_records"] == 667
    assert counts["static_source_feature_ownership_route_records"] == 310
    assert counts["static_source_feature_ownership_page_records"] == 357
    assert counts["static_controller_action_bridges"] == 98
    assert counts["direct_exact_queue_records"] == 507
    assert counts["direct_exact_queue_reviewed"] == 121
    assert counts["direct_exact_queue_pending_unreviewed"] == 386
    assert counts["direct_exact_queue_owned"] == 99
    assert counts["direct_exact_queue_without_ownership"] == 408
    assert counts["benchmark_mapped"] == 2
    assert counts["final_no_match"] == 0
    assert counts["benchmark_unresolved"] == 338
    assert counts["final_P0"] == counts["final_P1"] == 0
    reconciliation = findings["reconciliation"]
    assert reconciliation["retained_record_ids_unique"] is True
    assert reconciliation["retained_record_count"] == 16
    assert reconciliation["current_provisional_count"] == 8
    assert reconciliation["historical_already_fixed_count"] == 2
    assert reconciliation["historical_remediated_count"] == 6
    assert reconciliation["final_ids_cross_file_reconciled"] is False
    return EXPECTED_FINDINGS


def build_receipt(
    repository: dict[str, Any],
    producer: dict[str, Any],
    findings_record: dict[str, Any],
) -> dict[str, Any]:
    completion = dict(producer["completion_boundary"])
    assert list(completion) == COMPLETION_GATE_NAMES
    assert len(completion) == 26
    assert all(value is False for value in completion.values())
    receipt: dict[str, Any] = {
        "schema_version": 1,
        "run_id": RUN_ID,
        "status": STATUS,
        "evidence_date": "2026-09-01",
        "scope": {
            "finding_id": FINDING_ID,
            "type": "independent_exact_artifact_review",
            "architecture": "single operating organisation across multiple Sites",
            "tenant_design_introduced": False,
            "application_or_test_source_mutated": False,
            "runtime_database_browser_or_build_executed": False,
            "live_reporting_mutated": False,
        },
        "pins": {
            **repository,
            "producer_generator": EXPECTED_PRODUCER,
            "producer_receipt": EXPECTED_RECEIPT,
            "review_materializer": working_record(SCRIPT_REL),
            "current_findings_before_run197": findings_record,
        },
        "review": {
            "reviewer_lanes": [
                "/root/run196_credit_review",
                "/root/run196_git_review",
                "/root/run196_receipt_review",
            ],
            "independent_reviewers": 3,
            "all_final_reviewers_read_only": True,
            "resolved_no_go_findings": [
                {
                    "finding": (
                        "receipt embedded the live first-run dirty list and changed bytes "
                        "when the output appeared"
                    ),
                    "resolution": (
                        "validate live status but record one static deterministic four-file "
                        "owned boundary"
                    ),
                },
                {
                    "finding": (
                        "assert-only validation could be compiled away by optimized Python"
                    ),
                    "resolution": (
                        "raise RuntimeError when __debug__ is false before validation, "
                        "receipt construction, or writing"
                    ),
                },
            ],
            "final_exact_snapshot": {
                "producer_sha256": EXPECTED_PRODUCER["sha256"],
                "receipt_sha256": EXPECTED_RECEIPT["sha256"],
                "receipt_self_seal_sha256": EXPECTED_RECEIPT[
                    "receipt_self_seal_sha256"
                ],
                "two_ordinary_runs_byte_identical": True,
                "optimized_python_failed_before_write": True,
                "optimized_python_preserved_output_hash": True,
            },
            "final_reviewer_dispositions": [
                {
                    "lane": "/root/run196_credit_review",
                    "verdict": "GO",
                    "focus": "bounded credit and completion boundaries",
                },
                {
                    "lane": "/root/run196_git_review",
                    "verdict": "GO",
                    "focus": "Git lineage, blobs, status, and nonpublication",
                },
                {
                    "lane": "/root/run196_receipt_review",
                    "verdict": "GO",
                    "focus": "receipt integrity, determinism, and fail-closed writing",
                },
            ],
            "checks": {
                "strict_json_duplicate_free_finite_and_pretty": True,
                "lf_no_bom_no_trailing_whitespace": True,
                "canonical_producer_self_seal": True,
                "producer_and_receipt_exact_hash_blob_bytes_lines": True,
                "commit_tree_parent_subject_and_four_path_lineage": True,
                "merge_and_current_blobs_equal_sealed_fix": True,
                "advanced_my_day_noninheritance": True,
                "run195_receipt_link_and_self_seal": True,
                "red_and_zero_credit_run_boundaries": True,
                "focused_15_of_32_only_aggregate_eligible": True,
                "emar_2_of_238_support_only": True,
                "shared_40_of_438_not_split_or_recredited": True,
                "canonical_unit_test_path_adjudicated": True,
                "pre_run197_register_16_and_161_of_2609": True,
                "null_feature_identity_and_zero_static_ownership": True,
                "all_26_completion_gates_false": True,
                "static_four_file_owned_boundary": True,
            },
            "discrepancies": [],
        },
        "decision": {
            "verdict": "GO",
            "blocking_discrepancies": 0,
            "new_historical_remediated_record_reporting_authorized": True,
            "authorized_live_reporting_run": "RUN-197",
            "authorized_finding_id": FINDING_ID,
            "authorized_feature_id": None,
            "authorized_candidate_feature_id": None,
            "authorized_reporting_status": (
                "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
            ),
            "authorized_live_count_delta": {
                "retained_claim_records": 1,
                "current_provisional_source_claims": 0,
                "historical_already_fixed_records": 0,
                "historical_remediated_records": 1,
                "bounded_disposition_tests_passed": 15,
                "bounded_disposition_assertions": 32,
                "final_P0": 0,
                "final_P1": 0,
                "benchmark_mapped": 0,
                "final_no_match_or_NCM": 0,
                "benchmark_unresolved": 0,
                "static_owner_records": 0,
                "static_route_owners": 0,
                "static_page_owners": 0,
                "static_controller_action_bridges": 0,
                "direct_exact_queue_reviewed": 0,
            },
            "authorized_resulting_lineage": {
                "retained_claim_records": 17,
                "current_provisional_source_claims": 8,
                "historical_already_fixed_records": 2,
                "historical_remediated_records": 7,
                "bounded_disposition_tests_passed": 176,
                "bounded_disposition_assertions": 2641,
                "final_P0": 0,
                "final_P1": 0,
            },
            "authorized_unique_bounded_disposition_increment": {
                "prior_tests": 161,
                "prior_assertions": 2609,
                "tests": 15,
                "assertions": 32,
                "resulting_tests": 176,
                "resulting_assertions": 2641,
                "focused_execution_counted_once": True,
                "red_failures_or_assertions_counted": False,
                "isolated_supporting_compatibility_counted": False,
                "shared_post_merge_execution_counted": False,
            },
            "static_ownership_remains_unassigned": {
                "feature_id": None,
                "candidate_feature_id": None,
                "status": "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW",
                "route_owner_authorized": False,
                "controller_action_bridge_authorized": False,
                "page_owner_authorized": False,
                "queue_advance_authorized": False,
            },
            "live_reporting_changed_by_run196r": False,
            "run197_required": True,
            "run198_fresh_dashboard_verification_required": True,
        },
        "credit_boundary": {
            "independent_exact_artifact_review_for_reporting_authorization": True,
            "application_remediation_reexecution": False,
            "runtime_reexecution": False,
            "application_browser": False,
            "representative_user": False,
            "live_reporting": False,
            "application_publication": False,
            "static_route_feature_ownership": False,
            "static_controller_action_bridge": False,
            "static_page_or_frontend_ownership": False,
            "queue_advance": False,
            "benchmark_mapping": False,
            "final_no_match_or_NCM": False,
            "my_day_remediation_or_runtime": False,
            "adjacent_surface": False,
            "final_finding": False,
            "pass": False,
            "release": False,
            "gate_4": False,
            "feature_or_module_completion": False,
            "audit_complete": False,
        },
        "completion_gates": [
            {"name": name, "complete": False} for name in COMPLETION_GATE_NAMES
        ],
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "completion_boundary": completion,
        "wrote_files": [
            f"{PREFIX}/{SCRIPT_REL}",
            f"{PREFIX}/{OUTPUT_REL}",
        ],
    }
    assert [
        key for key, value in receipt["credit_boundary"].items() if value
    ] == ["independent_exact_artifact_review_for_reporting_authorization"]
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    return receipt


def validate_review(receipt: dict[str, Any]) -> None:
    assert_finite(receipt)
    copy = dict(receipt)
    seal = copy.pop("receipt_self_seal_sha256")
    assert seal == canonical_sha256(copy)
    assert receipt["run_id"] == RUN_ID
    assert receipt["status"] == STATUS
    assert receipt["review"]["independent_reviewers"] == 3
    assert receipt["review"]["discrepancies"] == []
    assert receipt["decision"]["verdict"] == "GO"
    assert receipt["decision"]["blocking_discrepancies"] == 0
    assert receipt["decision"]["authorized_live_reporting_run"] == "RUN-197"
    assert receipt["decision"]["authorized_feature_id"] is None
    assert receipt["decision"]["authorized_candidate_feature_id"] is None
    assert receipt["decision"]["authorized_resulting_lineage"] == {
        "retained_claim_records": 17,
        "current_provisional_source_claims": 8,
        "historical_already_fixed_records": 2,
        "historical_remediated_records": 7,
        "bounded_disposition_tests_passed": 176,
        "bounded_disposition_assertions": 2641,
        "final_P0": 0,
        "final_P1": 0,
    }
    increment = receipt["decision"][
        "authorized_unique_bounded_disposition_increment"
    ]
    assert increment["tests"] == 15 and increment["assertions"] == 32
    assert increment["resulting_tests"] == 176
    assert increment["resulting_assertions"] == 2641
    assert increment["isolated_supporting_compatibility_counted"] is False
    assert increment["shared_post_merge_execution_counted"] is False
    assert receipt["decision"]["live_reporting_changed_by_run196r"] is False
    assert receipt["decision"]["run197_required"] is True
    assert receipt["decision"]["run198_fresh_dashboard_verification_required"] is True
    assert receipt["completion_gates"] == [
        {"name": name, "complete": False} for name in COMPLETION_GATE_NAMES
    ]
    assert list(receipt["completion_boundary"]) == COMPLETION_GATE_NAMES
    assert all(value is False for value in receipt["completion_boundary"].values())
    assert receipt["artifact_completion_test_met"] is True
    assert receipt["audit_completion_test_met"] is False


def write_receipt(receipt: dict[str, Any]) -> bytes:
    validate_review(receipt)
    raw = (
        json.dumps(receipt, ensure_ascii=False, indent=2, allow_nan=False) + "\n"
    ).encode("utf-8")
    temporary = OUTPUT.with_name(f".{OUTPUT.name}.tmp-run196r")
    assert not temporary.exists(), f"stale receipt temp: {temporary}"
    try:
        with temporary.open("xb") as handle:
            handle.write(raw)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, OUTPUT)
    finally:
        if temporary.exists():
            temporary.unlink()
    observed = strict_json(OUTPUT)
    assert observed == receipt
    validate_review(observed)
    assert OUTPUT.read_bytes() == raw
    validate_owned_scope(output_required=True)
    return raw


def main() -> None:
    if not __debug__:
        raise RuntimeError("assertions must be enabled")
    repository = validate_repository()
    producer = validate_producer(repository)
    findings_record = validate_live_register()
    receipt = build_receipt(repository, producer, findings_record)
    raw = write_receipt(receipt)
    print(
        json.dumps(
            {
                "run_id": RUN_ID,
                "status": STATUS,
                "materializer_sha256": working_record(SCRIPT_REL)["sha256"],
                "receipt_sha256": sha256(raw),
                "receipt_self_seal_sha256": receipt[
                    "receipt_self_seal_sha256"
                ],
                "verdict": "GO",
                "run197_reporting_authorized": True,
                "authorized_result": "17 = 8 provisional + 2 already-fixed + 7 remediated",
                "authorized_unique_bounded_total": "176/2641",
                "static_ownership_adjudicated": False,
                "application_published": False,
                "audit_complete": False,
            },
            sort_keys=True,
        )
    )


if __name__ == "__main__":
    main()
