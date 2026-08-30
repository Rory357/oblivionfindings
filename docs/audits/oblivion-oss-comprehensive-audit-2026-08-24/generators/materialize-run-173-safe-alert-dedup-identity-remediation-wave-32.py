#!/usr/bin/env python3
"""Materialize the bounded RUN173 safeguarding alert remediation receipt.

This producer records already-completed isolated and post-merge execution. It
does not run PHP, touch a database, start a browser, mutate application source,
publish commits, or change the live finding register.
"""
from __future__ import annotations

import hashlib
import json
import subprocess
from pathlib import Path
from typing import Any


SCRIPT = Path(__file__).resolve()
AUDIT = SCRIPT.parent.parent
ROOT = next(parent for parent in SCRIPT.parents if (parent / ".git").exists())
PREFIX = AUDIT.relative_to(ROOT).as_posix()
SCRIPT_REL = SCRIPT.relative_to(AUDIT).as_posix()
OUTPUT_REL = "evidence/runtime/current-run-173-safe-alert-dedup-identity-remediation-wave-32.json"
OUTPUT = AUDIT / OUTPUT_REL

RUN_ID = "RUN-173-SAFE-ALERT-DEDUP-IDENTITY-01-REMEDIATION-WAVE-32"
STATUS = (
    "CURRENT_SAFE_ALERT_DEDUP_IDENTITY_DEFECT_REPRODUCED_REMEDIATED_"
    "LOCALLY_INTEGRATED_BOUNDED_VERIFIED_NOT_PUBLISHED_REPORTING_NOT_YET_"
    "AUTHORIZED_ZERO_FINAL_FINDING_OR_COMPLETION_CREDIT"
)
GOVERNING_PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
CONTINUATION_PROMPT_SHA256 = "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d"

BASE = "e488bd3edcda0f154f87e8bbed972f14db409b82"
BASE_TREE = "9e93b8aea4f4b907cde3dc59dd0520fba5bd7080"
FIX = "dc04067e304adebb47335d4f65e8c61061ec6e29"
FIX_TREE = "15a2e4b47788e9f2779030ec6d4d9ca7c1022727"
AUDIT_RELEASE = "c39b076547056b1e158c604957a04bd8b75b0f29"
AUDIT_RELEASE_TREE = "9ba0e09593c890908bdd8a9f3f1cc1f7b9fddeda"
MERGE = "705db2dc3ba05a8fdf647cd28bdc9c226a694068"
MERGE_TREE = "59b4fc58567f64bc80ff3d2e47b52860ce44cb02"
PATCH_ID = "af3daf2bf52f9f8865a139df36375819416ce370"

SERVICE = "app/Services/ControlRoom/ComprehensiveAlertBridgeService.php"
TEST = "tests/Feature/ControlRoom/SafeguardingAlertDedupIdentityReproductionTest.php"
CHANGED_PATHS = {SERVICE: (4, 0), TEST: (277, 0)}
EXPECTED_DIRTY = sorted(
    [
        f"{PREFIX}/{SCRIPT_REL}",
        f"{PREFIX}/{OUTPUT_REL}",
    ]
)


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


def file_record(relative: str, revision: str | None = None) -> dict[str, Any]:
    raw = git_bytes(revision, relative) if revision else (ROOT / relative).read_bytes()
    strict_text(raw, f"{revision or 'working'}:{relative}")
    record: dict[str, Any] = {
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
    if relative in CHANGED_PATHS and revision in (FIX, MERGE):
        record["insertions"], record["deletions"] = CHANGED_PATHS[relative]
    return record


def validate_repository() -> dict[str, Any]:
    assert git("rev-parse", "HEAD") == MERGE
    assert git("rev-parse", "main") == MERGE
    assert git("rev-parse", "HEAD^{tree}") == MERGE_TREE
    assert git("show", "-s", "--format=%P", MERGE) == f"{AUDIT_RELEASE} {FIX}"
    assert git("show", "-s", "--format=%s", MERGE) == "Merge SAFE-ALERT-DEDUP-IDENTITY-01"
    assert git("rev-parse", f"{FIX}^") == BASE
    assert git("rev-parse", f"{BASE}^{{tree}}") == BASE_TREE
    assert git("rev-parse", f"{FIX}^{{tree}}") == FIX_TREE
    assert git("rev-parse", f"{AUDIT_RELEASE}^{{tree}}") == AUDIT_RELEASE_TREE
    assert git("show", "-s", "--format=%s", FIX) == "Fix safeguarding alert dedup identity"
    assert git("rev-parse", "origin/main") == AUDIT_RELEASE
    assert git("rev-list", "--left-right", "--count", "origin/main...main") == "0\t2"
    assert git("diff", "--cached", "--name-only") == ""

    dirty = sorted(
        line[3:]
        for line in git("status", "--porcelain=v1", "--untracked-files=all").splitlines()
        if line
    )
    assert dirty in ([f"{PREFIX}/{SCRIPT_REL}"], EXPECTED_DIRTY), dirty
    assert git("diff", "--check") == ""

    first_parent_names = git("diff", "--name-only", AUDIT_RELEASE, MERGE).splitlines()
    fix_names = git("diff", "--name-only", BASE, FIX).splitlines()
    assert first_parent_names == list(CHANGED_PATHS)
    assert fix_names == list(CHANGED_PATHS)
    assert git("diff", "--name-status", AUDIT_RELEASE, MERGE).splitlines() == [
        f"M\t{SERVICE}",
        f"A\t{TEST}",
    ]
    assert git("diff", "--numstat", AUDIT_RELEASE, MERGE).splitlines() == [
        f"4\t0\t{SERVICE}",
        f"277\t0\t{TEST}",
    ]
    assert git_bytes(FIX, SERVICE) == git_bytes(MERGE, SERVICE)
    assert git_bytes(FIX, TEST) == git_bytes(MERGE, TEST)
    assert git_bytes(BASE, SERVICE) == git_bytes(AUDIT_RELEASE, SERVICE)
    assert git_bytes(BASE, SERVICE) != git_bytes(FIX, SERVICE)
    assert subprocess.run(
        ["git", "cat-file", "-e", f"{BASE}:{TEST}"],
        cwd=ROOT,
        capture_output=True,
    ).returncode != 0

    audit_advance = git("diff", "--name-only", BASE, AUDIT_RELEASE).splitlines()
    assert len(audit_advance) == 18
    assert all(path.startswith(f"{PREFIX}/") for path in audit_advance)

    patch = subprocess.run(
        ["git", "patch-id", "--stable"],
        cwd=ROOT,
        input=subprocess.run(
            ["git", "diff", BASE, FIX], cwd=ROOT, check=True, capture_output=True
        ).stdout,
        check=True,
        capture_output=True,
    ).stdout.decode("ascii").split()[0]
    assert patch == PATCH_ID
    return {
        "audit_advance_path_count": len(audit_advance),
        "audit_advance_audit_root_only": True,
        "stable_patch_id": patch,
    }


def build_receipt(repository: dict[str, Any]) -> dict[str, Any]:
    focused_cases = [
        "distinct same-client concerns each create their own alert",
        "distinct same-Site personless concerns each create their own alert",
        "retrying the same concern after five minutes remains idempotent",
        "distinct personless concerns do not collapse across Sites",
        "observer links each concern HsEvent only to its own alert and custody remains unchanged on retry",
    ]
    completion = {
        "framework_route_reachability_complete": False,
        "semantic_assurance_complete": False,
        "execution_complete": False,
        "coverage_complete": False,
        "benchmark_complete": False,
        "pass_8_complete": False,
        "final_reconciliation_complete": False,
        "no_live_agent_gate_complete": False,
        "full_crosswalk_complete": False,
        "gate_4_complete": False,
        "audit_complete": False,
    }
    credit = {
        "historical_condition_confirmed": True,
        "current_defect_reproduced": True,
        "application_remediation": True,
        "bounded_runtime": True,
        "application_commit_integrated_local_main": True,
        "application_commit_published": False,
        "finding_retirement_reporting": False,
        "terminal_fixture_debt": False,
        "timeless_retry": False,
        "within_window_escalation": False,
        "unused_escalation_parameter": False,
        "application_browser": False,
        "benchmark_mapping": False,
        "final_no_match_or_NCM": False,
        "ease": False,
        "full_feature_or_module": False,
        "release": False,
        "final_finding": False,
        "completion": False,
        "audit_complete": False,
    }
    receipt: dict[str, Any] = {
        "schema_version": "run-173-safe-alert-dedup-identity-remediation-wave-32-v1",
        "run_id": RUN_ID,
        "status": STATUS,
        "materialized_on": "2026-08-30",
        "architecture_boundary": (
            "One operating organisation across multiple Sites; Site access, exact roles and "
            "permissions, canonical ownership, direct-object denial, privacy, consent, and "
            "concern identity are the boundaries. Site and client are provenance, not tenant "
            "or concern identity."
        ),
        "pins": {
            "governing_prompt_sha256": GOVERNING_PROMPT_SHA256,
            "continuation_prompt_sha256": CONTINUATION_PROMPT_SHA256,
            "application_baseline_commit": BASE,
            "application_baseline_tree": BASE_TREE,
            "fix_commit": FIX,
            "fix_tree": FIX_TREE,
            "fix_parent": BASE,
            "fix_commit_subject": "Fix safeguarding alert dedup identity",
            "stable_patch_id": repository["stable_patch_id"],
            "clean_audit_release_commit": AUDIT_RELEASE,
            "clean_audit_release_tree": AUDIT_RELEASE_TREE,
            "local_main_merge_commit": MERGE,
            "local_main_tree": MERGE_TREE,
            "merge_parents": [AUDIT_RELEASE, FIX],
            "merge_subject": "Merge SAFE-ALERT-DEDUP-IDENTITY-01",
            "audit_advance_from_baseline": {
                "path_count": repository["audit_advance_path_count"],
                "audit_root_only": repository["audit_advance_audit_root_only"],
                "transferred_paths_unchanged": True,
            },
            "origin_main_observed": AUDIT_RELEASE,
            "local_main_ahead": 2,
            "local_main_behind": 0,
            "application_remote_publication_observed": False,
            "publication_authorized": False,
            "materializer": file_record(f"{PREFIX}/{SCRIPT_REL}"),
            "baseline_service": file_record(SERVICE, BASE),
            "fix_source_and_regression_test": [
                file_record(SERVICE, FIX),
                file_record(TEST, FIX),
            ],
            "merged_source_and_regression_test": [
                file_record(SERVICE, MERGE),
                file_record(TEST, MERGE),
            ],
        },
        "issue_first_disposition": {
            "finding_id": "SAFE-ALERT-DEDUP-IDENTITY-01",
            "feature_id": "CAP-CR-ALERT-WORKLIST-LIFECYCLE",
            "related_feature_ids": [
                "CAP-SAFE-CONCERN-INTAKE-TRIAGE",
                "CAP-SAFE-TERMINAL-PROJECTION",
            ],
            "verdict": "REPRODUCED_AND_REMEDIATED_LOCAL_MAIN_NOT_PUBLISHED",
            "new_discovery_stopped_after_confirmation": True,
            "exclusive_remediation_paths": list(CHANGED_PATHS),
            "red_baseline": {
                "commit": BASE,
                "focused_cases": 5,
                "failed": 4,
                "warning_pass": 1,
                "assertions_reported": 10,
                "passing_denominator_credit": 0,
                "observations": [
                    "distinct same-client safeguarding concern suppressed",
                    "distinct same-Site personless safeguarding concern suppressed",
                    "distinct cross-Site personless safeguarding concern suppressed",
                    "observer custody could not retain two concern-owned alert chains",
                ],
                "already_preserved_contract": (
                    "The same-concern retry case was the warning-pass; it did not prove "
                    "the four missing distinct-concern boundaries."
                ),
            },
        },
        "remediation": {
            "summary": (
                "Within the existing 30-minute source/type dedup window, safeguard alerts "
                "key to context.concern_id before client, asset, or null-entity fallback."
            ),
            "production_files": 1,
            "regression_test_files": 1,
            "changed_paths": 2,
            "insertions": 281,
            "deletions": 0,
            "same_concern_within_30_minutes_idempotent": True,
            "distinct_concern_ids_separate_regardless_of_client_site_or_personless_state": True,
            "site_and_client_preserved_as_provenance": True,
            "existing_global_30_minute_window_preserved": True,
            "existing_31_minute_critical_escalation_lifecycle_preserved": True,
            "timeless_identity_introduced": False,
            "routes_pages_components_copy_or_layout_changed": False,
            "third_party_source_assets_wording_or_layout_copied": False,
            "single_tenant_multi_site_boundary_preserved": True,
        },
        "delegated_runtime_execution": {
            "execution_owner": "separate Continue OSS audit fixes task",
            "root_reran_tests_for_run_173": False,
            "isolated_red_execution": {
                "failed": 4,
                "warning_pass": 1,
                "assertions_reported": 10,
                "credit": "REPRODUCTION_ONLY_ZERO_PASSING_DENOMINATOR_CREDIT",
            },
            "isolated_green_focused": {
                "tests": 5,
                "assertions": 60,
                "duration_seconds": 168.87,
            },
            "post_merge_green_focused": {
                "tests": 5,
                "assertions": 60,
                "duration_seconds": 154.93,
                "unique_bounded_disposition_denominator_credit": True,
            },
            "focused_cases": focused_cases,
            "focused_replay_aggregated_more_than_once": False,
            "supporting_control_room_bridge_suite": {
                "tests": 28,
                "assertions": 73,
                "duration_seconds": 148.09,
                "reported_separately": True,
                "added_to_bounded_disposition_denominator": False,
            },
            "adjacent_hs_event_safeguarding_filter": {
                "tests_passed": 3,
                "assertions": 5,
                "reported_separately": True,
                "added_to_bounded_disposition_denominator": False,
            },
            "terminal_transition_fixture_debt": {
                "failures": 6,
                "classification": "PRE_EXISTING_TEST_FIXTURE_DEBT_BEFORE_BRIDGE_OR_DEDUP_EXECUTION",
                "missing_fixture_fields": [
                    "worksafe_decision_tree_version",
                    "worksafe_source_effective_date",
                ],
                "safe_remediation_credit": False,
                "full_suite_green": False,
            },
            "syntax": {"files_passed": 2, "result": "PASS"},
            "pint": {"result": "PASS"},
            "full_suite_or_coverage_credit": False,
        },
        "independent_static_review": {
            "lane": "/root/safe_merge_review",
            "verdict": "GO",
            "findings": 0,
            "executed_tests": False,
            "wrote_files": False,
            "exact_merge_commit_tree_parents_and_two_path_delta_verified": True,
            "baseline_defect_and_30_minute_contract_verified": True,
            "observer_custody_and_31_minute_lifecycle_verified": True,
            "exact_run_173_receipt_review_completed": False,
            "retirement_reporting_authorized": False,
        },
        "cleanup_evidence": {
            "isolated_worktree": "C:/w/safe-alert",
            "isolated_worktree_removed": True,
            "isolated_branch": "codex/safe-alert-dedup-identity-01",
            "isolated_branch_deleted": True,
            "numeric_pid_test_schema_count": 0,
            "php_or_php_cgi_process_count": 0,
            "main_clean_before_audit_writes": True,
        },
        "noninheritance_boundary": {
            "terminal_transition_fixture_debt_recredited": False,
            "timeless_retry_credit": False,
            "within_window_escalation_semantics_credit": False,
            "unused_escalation_parameter_credit": False,
            "broader_safeguarding_correctness_credit": False,
            "application_browser_or_ease_credit": False,
            "benchmark_mapping_or_NCM_changed": False,
            "ownership_inventory_queue_or_matrix_changed": False,
            "full_feature_module_pass_release_or_completion_credit": False,
        },
        "reporting_boundary": {
            "current_retained_identity_count": 12,
            "current_split": "9 provisional + 2 historical already-fixed + 1 historical remediated",
            "authorized_after_independent_exact_artifact_review": (
                "8 provisional + 2 historical already-fixed + 2 historical remediated"
            ),
            "run_173_changes_live_reporting": False,
            "run_174_required": True,
            "run_175_fresh_dashboard_verification_required_after_reporting": True,
        },
        "credit_boundary": credit,
        "completion_boundary": completion,
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [f"{PREFIX}/{SCRIPT_REL}", f"{PREFIX}/{OUTPUT_REL}"],
    }
    assert [key for key, value in credit.items() if value] == [
        "historical_condition_confirmed",
        "current_defect_reproduced",
        "application_remediation",
        "bounded_runtime",
        "application_commit_integrated_local_main",
    ]
    assert all(value is False for value in completion.values())
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    return receipt


def validate(receipt: dict[str, Any]) -> None:
    copy = dict(receipt)
    seal = copy.pop("receipt_self_seal_sha256")
    assert seal == canonical_sha256(copy)
    assert receipt["pins"]["application_remote_publication_observed"] is False
    assert receipt["pins"]["publication_authorized"] is False
    assert receipt["independent_static_review"]["retirement_reporting_authorized"] is False
    assert receipt["delegated_runtime_execution"]["focused_replay_aggregated_more_than_once"] is False
    assert receipt["delegated_runtime_execution"]["terminal_transition_fixture_debt"]["safe_remediation_credit"] is False
    assert all(value is False for value in receipt["completion_boundary"].values())


def main() -> None:
    repository = validate_repository()
    receipt = build_receipt(repository)
    validate(receipt)
    encoded = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_bytes(encoded)
    assert OUTPUT.read_bytes() == encoded
    reloaded = json.loads(OUTPUT.read_text(encoding="utf-8"))
    assert reloaded == receipt
    validate(reloaded)
    print(json.dumps({
        "run_id": RUN_ID,
        "status": STATUS,
        "materializer_sha256": file_record(f"{PREFIX}/{SCRIPT_REL}")["sha256"],
        "receipt_sha256": sha256(encoded),
        "receipt_self_seal_sha256": receipt["receipt_self_seal_sha256"],
        "application_commit_integrated_local_main": True,
        "application_commit_published": False,
        "focused_unique_credit": "5/60",
        "retirement_reporting_authorized": False,
        "audit_complete": False,
    }, indent=2))


if __name__ == "__main__":
    main()
