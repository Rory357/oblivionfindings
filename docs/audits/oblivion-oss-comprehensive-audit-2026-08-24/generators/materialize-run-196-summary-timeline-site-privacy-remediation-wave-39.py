#!/usr/bin/env python3
"""Materialize bounded RUN196 Summary/Timeline Site-privacy remediation evidence.

The producer records already-completed red, isolated-green, review, merge, and
post-merge evidence. It runs no PHP, database, browser, build, queue, external
integration, or publication action and grants no reporting/final-finding,
benchmark, static-ownership, browser, release, or completion credit.
"""
from __future__ import annotations

import hashlib
import json
import math
import os
import subprocess
from pathlib import Path
from typing import Any


SCRIPT = Path(__file__).resolve()
AUDIT = SCRIPT.parent.parent
ROOT = next(parent for parent in SCRIPT.parents if (parent / ".git").exists())
PREFIX = AUDIT.relative_to(ROOT).as_posix()
SCRIPT_REL = SCRIPT.relative_to(AUDIT).as_posix()
OUTPUT_REL = (
    "evidence/runtime/"
    "current-run-196-summary-timeline-site-privacy-remediation-wave-39.json"
)
OUTPUT = AUDIT / OUTPUT_REL

RUN_ID = "RUN-196-SUMMARY-TIMELINE-SITE-PRIVACY-01-REMEDIATION-WAVE-39"
STATUS = (
    "CURRENT_SUMMARY_TIMELINE_SITE_PRIVACY_DEFECT_REPRODUCED_REMEDIATED_"
    "LOCALLY_INTEGRATED_BOUNDED_VERIFIED_NOT_PUBLISHED_REPORTING_NOT_YET_"
    "AUTHORIZED_ZERO_STATIC_OWNERSHIP_FINAL_FINDING_OR_COMPLETION_CREDIT"
)
GOVERNING_PROMPT_SHA256 = (
    "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
)
CONTINUATION_PROMPT_SHA256 = (
    "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d"
)

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

RUN195_REL = (
    "evidence/browser/"
    "current-audit-dashboard-verification-run-195-wave-38.json"
)
RUN195_SHA256 = (
    "455ee26c87ec6f07eca687eb1e40d2049c01513002732d08f74696b3dd617456"
)
RUN195_SELF_SEAL = (
    "a3dc0871156ba4c6376a92a4cacab8b8697fa0efcd49dea42d212533aff6b284"
)

FINDING_ID = "SUMMARY-TIMELINE-SITE-PRIVACY-01"
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
SUPPORTING_TEST = (
    "tests/Feature/Emar/MedicationTimelineProjectionVisibilityTest.php"
)
SHARED_POST_MERGE_TESTS = [
    CHANGED_PATHS[3],
    SUPPORTING_TEST,
    ADVANCED_MY_DAY_PATHS[3],
    "tests/Unit/ControlRoom/ControlRoomAlertLifecycleServiceTest.php",
]
STALE_HANDOFF_TEST_PATH = (
    "tests/Feature/ControlRoom/ControlRoomAlertLifecycleServiceTest.php"
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


def strict_json(path: Path) -> dict[str, Any]:
    raw = path.read_bytes()
    assert not raw.startswith(b"\xef\xbb\xbf")
    assert b"\r" not in raw
    assert raw.endswith(b"\n")
    for number, line in enumerate(raw.splitlines(), start=1):
        assert line == line.rstrip(b" \t"), f"trailing whitespace: {path}:{number}"

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
    assert (
        json.dumps(value, ensure_ascii=False, indent=2, allow_nan=False) + "\n"
    ).encode("utf-8") == raw
    return value


def verify_self_seal(value: dict[str, Any], expected: str) -> None:
    without_seal = dict(value)
    observed = without_seal.pop("receipt_self_seal_sha256")
    assert observed == expected
    assert canonical_sha256(without_seal) == expected


def text_record(relative: str, raw: bytes, revision: str | None = None) -> dict[str, Any]:
    assert b"\x00" not in raw
    result: dict[str, Any] = {
        "path": relative,
        "sha256": sha256(raw),
        "bytes": len(raw),
        "lines": len(raw.splitlines()),
    }
    if revision is not None:
        result["git_blob_id"] = git("rev-parse", f"{revision}:{relative}")
    return result


def revision_file_record(revision: str, relative: str) -> dict[str, Any]:
    return text_record(relative, git_bytes(revision, relative), revision)


def current_file_record(relative: str) -> dict[str, Any]:
    return text_record(relative, (ROOT / relative).read_bytes())


def commit_record(commit: str, expected_tree: str) -> dict[str, Any]:
    assert git("cat-file", "-t", commit) == "commit"
    assert git("rev-parse", f"{commit}^{{tree}}") == expected_tree
    return {
        "commit": commit,
        "tree": expected_tree,
        "parents": git("show", "-s", "--format=%P", commit).split(),
        "subject": git("show", "-s", "--format=%s", commit),
    }


def changed_paths(parent: str, commit: str) -> list[dict[str, str]]:
    raw = git("diff", "--name-status", parent, commit)
    return [
        {"status": line.split("\t", 1)[0], "path": line.split("\t", 1)[1]}
        for line in raw.splitlines()
        if line
    ]


def diff_record(parent: str, commit: str, relative: str) -> dict[str, Any]:
    numstat = git("diff", "--numstat", parent, commit, "--", relative)
    assert numstat
    added, deleted, observed = numstat.split("\t")
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


def assert_clean_except_receipt() -> list[dict[str, str]]:
    expected_paths = {
        f"{PREFIX}/{SCRIPT_REL}",
        f"{PREFIX}/{OUTPUT_REL}",
    }
    observed = porcelain_paths()
    observed_paths = {item["path"] for item in observed}
    assert f"{PREFIX}/{SCRIPT_REL}" in observed_paths
    assert observed_paths <= expected_paths, observed
    assert all(item["state"] == "??" for item in observed), observed
    assert subprocess.run(
        ["git", "diff", "--cached", "--quiet"], cwd=ROOT
    ).returncode == 0
    assert subprocess.run(
        ["git", "diff", "--check"], cwd=ROOT
    ).returncode == 0
    return observed


def validate_repository() -> dict[str, Any]:
    if not __debug__:
        raise RuntimeError("assertions must be enabled")
    assert git("rev-parse", "HEAD") == CURRENT_MAIN
    assert git("rev-parse", "HEAD^{tree}") == CURRENT_TREE
    assert git("rev-parse", "origin/main") == ORIGIN_MAIN
    assert git("rev-list", "--left-right", "--count", "origin/main...main") == "0\t83"
    assert_clean_except_receipt()

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

    expected_fix_paths = [{"status": "M", "path": path} for path in CHANGED_PATHS]
    expected_advanced_paths = [
        {"status": "M", "path": path} for path in ADVANCED_MY_DAY_PATHS
    ]
    assert changed_paths(BASE, FIX) == expected_fix_paths
    assert changed_paths(AUDIT_RELEASE, MERGE) == expected_fix_paths
    assert changed_paths(MERGE, CURRENT_MAIN) == expected_advanced_paths
    assert subprocess.run(
        ["git", "diff", "--quiet", BASE, AUDIT_RELEASE, "--", *CHANGED_PATHS],
        cwd=ROOT,
    ).returncode == 0

    for path in CHANGED_PATHS:
        fixed_blob = git("rev-parse", f"{FIX}:{path}")
        assert fixed_blob == git("rev-parse", f"{MERGE}:{path}")
        assert fixed_blob == git("rev-parse", f"{CURRENT_MAIN}:{path}")
    assert not (ROOT / STALE_HANDOFF_TEST_PATH).exists()
    assert all((ROOT / path).is_file() for path in SHARED_POST_MERGE_TESTS)

    run195_path = AUDIT / RUN195_REL
    assert sha256(run195_path.read_bytes()) == RUN195_SHA256
    run195 = strict_json(run195_path)
    verify_self_seal(run195, RUN195_SELF_SEAL)
    assert run195["artifact_completion_test_met"] is True
    assert run195["audit_completion_test_met"] is False
    return {
        "owned_worktree_boundary": [
            {"state": "??", "path": f"{PREFIX}/{OUTPUT_REL}"},
            {"state": "??", "path": f"{PREFIX}/{SCRIPT_REL}"},
        ],
        "baseline": baseline,
        "audit_release": audit_release,
        "sealed_fix": fix,
        "effective_merge": merge,
        "current_main": current,
        "advanced_unrelated_paths": ADVANCED_MY_DAY_PATHS,
        "advanced_unrelated_paths_credit_inherited": False,
        "run195": {
            "path": RUN195_REL,
            "sha256": RUN195_SHA256,
            "receipt_self_seal_sha256": RUN195_SELF_SEAL,
        },
    }


def file_lineage() -> list[dict[str, Any]]:
    return [
        {
            "path": path,
            "baseline": revision_file_record(BASE, path),
            "sealed_fix": revision_file_record(FIX, path),
            "effective_merge": revision_file_record(MERGE, path),
            "current_main": revision_file_record(CURRENT_MAIN, path),
            "fix_diff": diff_record(BASE, FIX, path),
            "merge_blob_identical_to_fix": git("rev-parse", f"{FIX}:{path}")
            == git("rev-parse", f"{MERGE}:{path}"),
            "current_blob_identical_to_fix": git(
                "rev-parse", f"{CURRENT_MAIN}:{path}"
            )
            == git("rev-parse", f"{FIX}:{path}"),
        }
        for path in CHANGED_PATHS
    ]


def build_receipt(repository: dict[str, Any]) -> dict[str, Any]:
    completion_gates = [
        {"name": name, "complete": False} for name in COMPLETION_GATE_NAMES
    ]
    receipt: dict[str, Any] = {
        "schema_version": 1,
        "run_id": RUN_ID,
        "status": STATUS,
        "evidence_date": "2026-09-01",
        "scope": {
            "finding_id": FINDING_ID,
            "type": "bounded_remediation_execution_and_local_integration_receipt",
            "architecture": "single operating organisation across multiple Sites",
            "authorization_boundary": [
                "current approved Site access",
                "exact roles and permissions",
                "canonical ownership",
                "direct-object denial",
                "privacy",
            ],
            "tenant_design_introduced": False,
            "application_source_mutated_by_this_materializer": False,
            "runtime_executed_by_this_materializer": False,
            "browser_executed_by_this_materializer": False,
            "reporting_not_yet_materialized": True,
        },
        "governing_inputs": {
            "governing_prompt_sha256": GOVERNING_PROMPT_SHA256,
            "continuation_prompt_sha256": CONTINUATION_PROMPT_SHA256,
            "explicit_issue_first_override_applied": True,
        },
        "lineage": repository,
        "finding": {
            "finding_id": FINDING_ID,
            "feature_identity": {
                "feature_id": None,
                "candidate_feature_id": None,
                "status": "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW",
                "static_ownership_credit": False,
            },
            "capability_boundary": (
                "staff summary/timeline reads and queued generation Site authorization"
            ),
            "current_defect": (
                "Action permission without current approved shared-Site access allowed "
                "another staff member's summary/timeline reads, while generation used "
                "legacy organization comparison and queued work did not revalidate the "
                "requester before protected event reads or summary writes."
            ),
            "expected_contract": [
                "self-read and existing action compatibility remain",
                "other-staff access requires canonical active shared-Site scope",
                "only hr.employees.viewAllSites bypasses staff Site scope",
                "client Gate behavior remains unchanged",
                "queued generation revalidates before protected reads and writes",
            ],
            "red_reproduction": {
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
            },
            "zero_credit_runs": [
                {
                    "reason": "vendor junction resolved Composer application classes into primary",
                    "phase": "before Laravel bootstrap",
                    "assertions": 0,
                }
            ],
            "changed_paths": CHANGED_PATHS,
            "file_lineage": file_lineage(),
            "isolated_focused_verification": {
                "command": (
                    "php artisan test tests/Feature/Security/"
                    "SummaryRagTimelineAuthorizationTest.php --colors=never"
                ),
                "exit_code": 0,
                "passed": 15,
                "assertions": 32,
                "duration_seconds": 147.90,
                "eligible_for_bounded_aggregate": True,
            },
            "isolated_supporting_compatibility": {
                "command": (
                    "php artisan test tests/Feature/Emar/"
                    "MedicationTimelineProjectionVisibilityTest.php --colors=never"
                ),
                "exit_code": 0,
                "passed": 2,
                "assertions": 238,
                "duration_seconds": 149.20,
                "eligible_for_bounded_aggregate": False,
                "reason": "supporting compatibility execution is separately reported and not recredited",
            },
            "independent_source_and_test_review": {
                "disposition": "GO",
                "reviewed_boundaries": [
                    "self-read and action compatibility",
                    "other-staff canonical active Site scope",
                    "global-permission exception",
                    "unchanged client Gate",
                    "queue authorization before read/write",
                    "unique staff-profile relationship",
                ],
            },
        },
        "post_merge_shared_support": {
            "command": "php artisan test "
            + " ".join(SHARED_POST_MERGE_TESTS)
            + " --colors=never",
            "tests": SHARED_POST_MERGE_TESTS,
            "exit_code": 0,
            "passed": 40,
            "assertions": 438,
            "duration_seconds": 193.70,
            "eligible_for_bounded_aggregate": False,
            "shared_denominator_not_split_or_recredited": True,
            "checks": {
                "php_syntax_paths_passed": 8,
                "php_syntax_paths_total": 8,
                "scoped_pint_passed": True,
                "git_diff_check_passed": True,
                "first_parent_delta_exact": True,
                "merge_blobs_identical_to_sealed_fix": True,
            },
            "zero_credit_runs": [
                {
                    "reason": "wrong Medication test directory",
                    "phase": "before test execution",
                    "tests_executed": 0,
                }
            ],
        },
        "handoff_path_adjudication": {
            "stale_message_path": STALE_HANDOFF_TEST_PATH,
            "stale_path_exists": False,
            "canonical_repository_path": SHARED_POST_MERGE_TESTS[3],
            "canonical_path_exists": True,
            "credit_uses_canonical_path_only": True,
        },
        "cleanup_and_release": {
            "at_fix_lane_release": {
                "global_php_pest_process_count": 0,
                "numeric_pid_suffixed_schema_count": 0,
                "primary_status_empty": True,
                "origin_main_behind": 0,
                "origin_main_ahead": 83,
                "push_performed": False,
            },
            "removed_clean_worktree": "C:/w/summary-timeline-site-privacy-01",
            "retained_recovery_branch": "codex/summary-timeline-site-privacy-01",
            "all_four_paths_released": True,
            "serialized_runtime_lane_released": True,
        },
        "credit_boundary": {
            "exact_remediation_source_credit": True,
            "exact_focused_test_execution_credit": True,
            "local_merge_credit": True,
            "cleanup_credit": True,
            "application_browser_credit": False,
            "representative_user_credit": False,
            "benchmark_or_comparator_credit": False,
            "final_ncm_credit": False,
            "static_route_action_ownership_credit": False,
            "static_page_or_frontend_ownership_credit": False,
            "queue_advance_credit": False,
            "adjacent_surface_credit": False,
            "feature_or_module_completion_credit": False,
            "release_or_deployment_credit": False,
            "publication_credit": False,
            "final_finding_reporting_credit": False,
            "gate_4_credit": False,
            "audit_completion_credit": False,
            "my_day_fix_or_runtime_credit": False,
            "excluded_surfaces": [
                "other timeline-event correctness or event-level Site filtering",
                "client policy correctness beyond preservation",
                "routes, frontend, models, schema, access service, and provider behavior",
                "other summary or timeline surfaces",
                "My Day and Control Room remediation",
                "tenant or organization design",
            ],
        },
        "materializer": current_file_record(f"{PREFIX}/{SCRIPT_REL}"),
        "completion_gates": completion_gates,
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
    }
    receipt["completion_boundary"] = {
        item["name"]: item["complete"] for item in completion_gates
    }
    assert len(receipt["completion_boundary"]) == 26
    assert all(value is False for value in receipt["completion_boundary"].values())
    return receipt


def validate_receipt(receipt: dict[str, Any]) -> None:
    assert_finite(receipt)
    assert receipt["run_id"] == RUN_ID
    assert receipt["finding"]["finding_id"] == FINDING_ID
    assert receipt["finding"]["isolated_focused_verification"]["passed"] == 15
    assert receipt["finding"]["isolated_focused_verification"]["assertions"] == 32
    assert receipt["finding"]["isolated_supporting_compatibility"][
        "eligible_for_bounded_aggregate"
    ] is False
    assert receipt["post_merge_shared_support"][
        "shared_denominator_not_split_or_recredited"
    ] is True
    assert receipt["handoff_path_adjudication"]["stale_path_exists"] is False
    assert receipt["credit_boundary"]["final_finding_reporting_credit"] is False
    assert receipt["credit_boundary"]["my_day_fix_or_runtime_credit"] is False
    assert receipt["artifact_completion_test_met"] is True
    assert receipt["audit_completion_test_met"] is False
    assert all(value is False for value in receipt["completion_boundary"].values())


def write_receipt(receipt: dict[str, Any]) -> None:
    validate_receipt(receipt)
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    raw = (
        json.dumps(receipt, ensure_ascii=False, indent=2, allow_nan=False) + "\n"
    ).encode("utf-8")
    temporary = OUTPUT.with_name(f".{OUTPUT.name}.tmp-run196")
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
    verify_self_seal(observed, receipt["receipt_self_seal_sha256"])
    assert sha256(OUTPUT.read_bytes()) == sha256(raw)
    observed_status = assert_clean_except_receipt()
    assert {item["path"] for item in observed_status} == {
        f"{PREFIX}/{SCRIPT_REL}",
        f"{PREFIX}/{OUTPUT_REL}",
    }
    print(
        json.dumps(
            {
                "run_id": RUN_ID,
                "output": OUTPUT_REL,
                "sha256": sha256(raw),
                "receipt_self_seal_sha256": receipt[
                    "receipt_self_seal_sha256"
                ],
                "artifact_completion_test_met": receipt[
                    "artifact_completion_test_met"
                ],
            },
            sort_keys=True,
        )
    )


def main() -> None:
    repository = validate_repository()
    receipt = build_receipt(repository)
    write_receipt(receipt)


if __name__ == "__main__":
    main()
