#!/usr/bin/env python3
"""Materialize the bounded RUN176 Fleet trip-index Site-privacy receipt.

This producer records already-completed baseline, isolated, and post-merge
execution. It does not run PHP, touch a database, start a browser, mutate
application source, publish commits, change the live finding register, or
adjudicate static route/action ownership.
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
    "evidence/runtime/"
    "current-run-176-fleet-trip-index-site-privacy-remediation-wave-33.json"
)
OUTPUT = AUDIT / OUTPUT_REL

RUN_ID = "RUN-176-FLEET-TRIP-INDEX-SITE-PRIVACY-01-REMEDIATION-WAVE-33"
STATUS = (
    "CURRENT_FLEET_TRIP_INDEX_SITE_PRIVACY_DEFECT_REPRODUCED_REMEDIATED_"
    "LOCALLY_INTEGRATED_BOUNDED_VERIFIED_NOT_PUBLISHED_REPORTING_NOT_YET_"
    "AUTHORIZED_ZERO_STATIC_OWNERSHIP_FINAL_FINDING_OR_COMPLETION_CREDIT"
)
GOVERNING_PROMPT_SHA256 = (
    "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
)
CONTINUATION_PROMPT_SHA256 = (
    "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d"
)

BASE = "13a7f37da9c966fa531f20e82b1bb9eac814e041"
BASE_TREE = "e952efb7d0b1446d2c6b67bbd28339bd906d1b38"
FIX = "790bc11e3fb2b17a0eb8ba96e2cdea87ba8175b5"
FIX_TREE = "657abb07867068865f935008c2c43dea38c867c8"
AUDIT_RELEASE = BASE
AUDIT_RELEASE_TREE = BASE_TREE
MERGE = "c643c9e5eecf3b4272f55ec6d5aab4b99c3e300d"
MERGE_TREE = FIX_TREE
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"
PATCH_ID = "a602e6dfa300cad25462998039558b03536e6c0c"

FINDING_ID = "FLEET-TRIP-INDEX-SITE-PRIVACY-01"
FEATURE_ID = "CAP-FLEET-VEHICLE-REGISTER"
QUEUE_ID = "RUN090-ROUTE-0085"
ROUTE_RECORD_ID = "RUN077-ROUTE-0693"
ROUTE_NAME = "fleet-assets.trips.index"
ACTION = "VehicleController::trips"

CONTROLLER = "app/Http/Controllers/FleetAssets/VehicleController.php"
TEST = "tests/Feature/FleetAssets/FleetTripIndexSitePrivacyTest.php"
ROUTE_FILE = "routes/fleet-assets.php"
FINDINGS = f"{PREFIX}/findings.json"
CHANGED_PATHS = {CONTROLLER: (196, 71), TEST: (521, 0)}

EXPECTED_BASE_CONTROLLER = {
    "path": CONTROLLER,
    "sha256": "478a1a0a33868536fc3b2baf5db2d06732ddb5fa16094997a5128dc2267b5239",
    "git_blob_id": "49897a4f91a2484c0633c6a575f554ed725acb1f",
    "bytes": 49203,
    "lines": 1138,
}
EXPECTED_FIXED_CONTROLLER = {
    "path": CONTROLLER,
    "sha256": "ba1ecc7e876c352a78122a3b292648d834ed40c8e09ab8893b9d9150e5614c49",
    "git_blob_id": "c6b75b8f33bb7774f74d27a4bc01bf07766c3212",
    "bytes": 54669,
    "lines": 1263,
    "insertions": 196,
    "deletions": 71,
}
EXPECTED_FIXED_TEST = {
    "path": TEST,
    "sha256": "932a35eaca38ec714874f05aa875388b67ccad3105b39b83c5df85510c27b9ce",
    "git_blob_id": "d58b141ac5e7c837a3bf27d5fa4494716849f60b",
    "bytes": 22167,
    "lines": 521,
    "insertions": 521,
    "deletions": 0,
}
EXPECTED_CURRENT_ROUTE_FILE = {
    "path": ROUTE_FILE,
    "sha256": "4be79ba4a0957f81f3e99de8eea7f29a398f8a115957bd44af06dbbf78fe2c4c",
    "git_blob_id": "f0b2b8c199ada1d8ef8bdb41c99bfc2ac02f93d2",
    "bytes": 28332,
    "lines": 351,
}
EXPECTED_CURRENT_FINDINGS = {
    "path": FINDINGS,
    "sha256": "32675839fb79d66d49d93a97be66f2805d854231c6ca8c513d336941c6291b0e",
    "git_blob_id": "ee6d5ac14e7b492b612ef5a84d7c6e199760507d",
    "bytes": 561735,
    "lines": 10073,
}
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


def git_object_exists(specification: str) -> bool:
    return (
        subprocess.run(
            ["git", "cat-file", "-e", specification],
            cwd=ROOT,
            capture_output=True,
        ).returncode
        == 0
    )


def git_is_ancestor(ancestor: str, descendant: str) -> bool:
    return (
        subprocess.run(
            ["git", "merge-base", "--is-ancestor", ancestor, descendant],
            cwd=ROOT,
            capture_output=True,
        ).returncode
        == 0
    )


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


def validate_findings_snapshot() -> dict[str, Any]:
    raw = git_bytes(MERGE, FINDINGS)
    strict_text(raw, f"{MERGE}:{FINDINGS}")
    findings = json.loads(raw.decode("utf-8"))
    records = findings["records"]
    statuses = Counter(record["record_status"] for record in records)
    record_ids = [record["id"] for record in records]
    reconciliation = findings["reconciliation"]

    assert len(records) == 12
    assert len(record_ids) == len(set(record_ids))
    assert FINDING_ID not in record_ids
    assert statuses == {
        "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING": 8,
        "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING": 2,
        "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING": 2,
    }
    assert reconciliation["retained_record_count"] == 12
    assert reconciliation["current_provisional_count"] == 8
    assert reconciliation["historical_already_fixed_count"] == 2
    assert reconciliation["historical_remediated_count"] == 2
    assert reconciliation["retained_record_ids_unique"] is True
    assert reconciliation["current_provisional_ids_unique"] is True
    assert reconciliation["final_ids_cross_file_reconciled"] is False

    return {
        "retained_record_count": 12,
        "current_provisional_count": 8,
        "historical_already_fixed_count": 2,
        "historical_remediated_count": 2,
        "fleet_trip_index_site_privacy_record_present": False,
    }


def validate_repository() -> dict[str, Any]:
    assert git("rev-parse", "HEAD") == MERGE
    assert git("rev-parse", "main") == MERGE
    assert git("rev-parse", "HEAD^{tree}") == MERGE_TREE
    assert git("show", "-s", "--format=%P", MERGE) == f"{AUDIT_RELEASE} {FIX}"
    assert git("show", "-s", "--format=%s", MERGE) == (
        "merge: fix fleet trip index site privacy"
    )
    assert git("rev-parse", f"{FIX}^") == BASE
    assert git("rev-parse", f"{BASE}^{{tree}}") == BASE_TREE
    assert git("rev-parse", f"{FIX}^{{tree}}") == FIX_TREE
    assert git("rev-parse", f"{AUDIT_RELEASE}^{{tree}}") == AUDIT_RELEASE_TREE
    assert git("show", "-s", "--format=%s", FIX) == (
        "fix(fleet): scope trip index to visible sites"
    )
    assert AUDIT_RELEASE == BASE
    assert AUDIT_RELEASE_TREE == BASE_TREE
    assert git("rev-parse", "origin/main") == ORIGIN_MAIN
    assert git("rev-list", "--left-right", "--count", "origin/main...main") == "0\t7"
    assert not git_is_ancestor(FIX, ORIGIN_MAIN)
    assert not git_is_ancestor(MERGE, ORIGIN_MAIN)
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
        f"M\t{CONTROLLER}",
        f"A\t{TEST}",
    ]
    assert git("diff", "--numstat", AUDIT_RELEASE, MERGE).splitlines() == [
        f"196\t71\t{CONTROLLER}",
        f"521\t0\t{TEST}",
    ]
    assert git_bytes(FIX, CONTROLLER) == git_bytes(MERGE, CONTROLLER)
    assert git_bytes(FIX, TEST) == git_bytes(MERGE, TEST)
    assert git_bytes(BASE, CONTROLLER) != git_bytes(FIX, CONTROLLER)
    assert not git_object_exists(f"{BASE}:{TEST}")
    assert git_bytes(BASE, FINDINGS) == git_bytes(MERGE, FINDINGS)
    assert git_bytes(BASE, ROUTE_FILE) == git_bytes(MERGE, ROUTE_FILE)
    assert (ROOT / CONTROLLER).read_bytes() == git_bytes(MERGE, CONTROLLER)
    assert (ROOT / TEST).read_bytes() == git_bytes(MERGE, TEST)
    assert (ROOT / FINDINGS).read_bytes() == git_bytes(MERGE, FINDINGS)
    assert (ROOT / ROUTE_FILE).read_bytes() == git_bytes(MERGE, ROUTE_FILE)

    audit_advance = git("diff", "--name-only", BASE, AUDIT_RELEASE).splitlines()
    assert audit_advance == []

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

    baseline_controller = file_record(CONTROLLER, BASE)
    fixed_controller = file_record(CONTROLLER, FIX)
    fixed_test = file_record(TEST, FIX)
    merged_controller = file_record(CONTROLLER, MERGE)
    merged_test = file_record(TEST, MERGE)
    current_route_file = file_record(ROUTE_FILE, MERGE)
    current_findings = file_record(FINDINGS, MERGE)
    assert baseline_controller == EXPECTED_BASE_CONTROLLER
    assert fixed_controller == EXPECTED_FIXED_CONTROLLER
    assert fixed_test == EXPECTED_FIXED_TEST
    assert merged_controller == EXPECTED_FIXED_CONTROLLER
    assert merged_test == EXPECTED_FIXED_TEST
    assert current_route_file == EXPECTED_CURRENT_ROUTE_FILE
    assert current_findings == EXPECTED_CURRENT_FINDINGS

    controller_text = git_bytes(MERGE, CONTROLLER).decode("utf-8")
    route_text = git_bytes(MERGE, ROUTE_FILE).decode("utf-8")
    assert controller_text.count("public function trips(Request $request)") == 1
    assert (
        controller_text[: controller_text.index("public function trips(Request $request)")]
        .count("\n")
        + 1
        == 566
    )
    route_statement = (
        "Route::get('/trips', [VehicleController::class, 'trips'])"
        "->name('fleet-assets.trips.index');"
    )
    assert route_text.count(route_statement) == 1
    assert route_text[: route_text.index(route_statement)].count("\n") + 1 == 54

    return {
        "audit_advance_path_count": 0,
        "clean_audit_release_equals_application_baseline": True,
        "stable_patch_id": patch,
        "baseline_controller": baseline_controller,
        "fixed_controller": fixed_controller,
        "fixed_test": fixed_test,
        "merged_controller": merged_controller,
        "merged_test": merged_test,
        "current_route_file": current_route_file,
        "current_findings": current_findings,
        "findings_snapshot": validate_findings_snapshot(),
    }


def build_receipt(repository: dict[str, Any]) -> dict[str, Any]:
    focused_cases = [
        (
            "approved-Site viewer receives only visible trip rows, nested identities, "
            "filter vehicles, summaries, day counts, top vehicles, distance trend, and hero"
        ),
        "CSV export excludes hidden-Site rows and hidden nested driver identity",
        (
            "vehicle_id and legacy asset_id filters accept visible vehicles, give vehicle_id "
            "precedence, and conceal foreign or missing vehicles with 404"
        ),
        (
            "fleet.manage sees operational Sites but excludes archived-Site trips and "
            "archived or inaccessible driver identity"
        ),
        (
            "trip visibility follows canonical direct site, home site, and client-site "
            "provenance without accepting conflicting provenance"
        ),
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
    noninheritance = {
        "isolated_green_replay_recredited": False,
        "supporting_vehicle_controller_regressions_recredited": False,
        "red_failures_or_assertions_recredited": False,
        "static_route_feature_ownership": False,
        "static_controller_action_bridge": False,
        "static_page_or_frontend_ownership": False,
        "queue_matrix_or_feature_union_change": False,
        "playback_toggle_personal_fuel_or_adjacent_route_correctness": False,
        "security_devices_or_user_site_access_service_correctness": False,
        "broader_fleet_permission_privacy_or_direct_object_correctness": False,
        "application_browser_or_ease": False,
        "benchmark_mapping_or_final_no_match_NCM": False,
        "full_suite_coverage_feature_module_pass_or_release": False,
        "publication_final_finding_completion_or_audit_completion": False,
    }
    credit = {
        "historical_condition_confirmed": True,
        "current_defect_reproduced": True,
        "application_remediation": True,
        "bounded_runtime": True,
        "bounded_selected_get_and_csv_execution": True,
        "bounded_site_privacy_correctness": True,
        "application_commit_integrated_local_main": True,
        "application_commit_published": False,
        "new_historical_remediated_record_reporting": False,
        "static_route_feature_ownership": False,
        "static_controller_action_bridge": False,
        "framework_route_reachability_complete": False,
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
        "schema_version": (
            "run-176-fleet-trip-index-site-privacy-remediation-wave-33-v1"
        ),
        "run_id": RUN_ID,
        "status": STATUS,
        "materialized_on": "2026-08-30",
        "architecture_boundary": (
            "One operating organisation across multiple Sites; approved Site access, exact "
            "roles and permissions, canonical Asset provenance, direct-object denial, and "
            "privacy are the boundaries. Site is provenance, not a tenant boundary."
        ),
        "pins": {
            "governing_prompt_sha256": GOVERNING_PROMPT_SHA256,
            "continuation_prompt_sha256": CONTINUATION_PROMPT_SHA256,
            "application_baseline_commit": BASE,
            "application_baseline_tree": BASE_TREE,
            "fix_commit": FIX,
            "fix_tree": FIX_TREE,
            "fix_parent": BASE,
            "fix_commit_subject": "fix(fleet): scope trip index to visible sites",
            "stable_patch_id": repository["stable_patch_id"],
            "clean_audit_release_commit": AUDIT_RELEASE,
            "clean_audit_release_tree": AUDIT_RELEASE_TREE,
            "local_main_merge_commit": MERGE,
            "local_main_tree": MERGE_TREE,
            "merge_parents": [AUDIT_RELEASE, FIX],
            "merge_subject": "merge: fix fleet trip index site privacy",
            "audit_advance_from_baseline": {
                "path_count": repository["audit_advance_path_count"],
                "clean_audit_release_equals_application_baseline": repository[
                    "clean_audit_release_equals_application_baseline"
                ],
                "transferred_paths_unchanged": True,
            },
            "origin_main_observed": ORIGIN_MAIN,
            "local_main_ahead": 7,
            "local_main_behind": 0,
            "application_remote_publication_observed": False,
            "publication_authorized": False,
            "materializer": file_record(f"{PREFIX}/{SCRIPT_REL}"),
            "baseline_controller": repository["baseline_controller"],
            "fix_source_and_regression_test": [
                repository["fixed_controller"],
                repository["fixed_test"],
            ],
            "merged_source_and_regression_test": [
                repository["merged_controller"],
                repository["merged_test"],
            ],
            "current_route_source": repository["current_route_file"],
            "current_findings_before_run_176": repository["current_findings"],
        },
        "issue_first_disposition": {
            "finding_id": FINDING_ID,
            "candidate_feature_id": FEATURE_ID,
            "feature_identity_status": "PENDING_FRESH_SEMANTIC_REVIEW",
            "selected_route": {
                "queue_id": QUEUE_ID,
                "route_record_id": ROUTE_RECORD_ID,
                "route_name": ROUTE_NAME,
                "controller_action": ACTION,
            },
            "verdict": "REPRODUCED_AND_REMEDIATED_LOCAL_MAIN_NOT_PUBLISHED",
            "new_discovery_stopped_after_confirmation": True,
            "exclusive_remediation_paths": list(CHANGED_PATHS),
            "red_baseline": {
                "commit": BASE,
                "audit_lane_initial_two_case_reproduction": {
                    "cases": 2,
                    "failed": 2,
                    "assertions_reported": 19,
                    "duration_seconds": 147.33,
                    "exit_code": 1,
                },
                "transferred_recreated_two_case_reproduction": {
                    "cases": 2,
                    "failed": 2,
                    "assertions_reported": 19,
                    "duration_seconds": 213.08,
                    "exit_code": 1,
                },
                "expanded_permanent_five_case_reproduction": {
                    "cases": 5,
                    "failed": 5,
                    "assertions_reported": 55,
                    "duration_seconds": 159.09,
                    "exit_code": 1,
                },
                "passing_denominator_credit": 0,
                "observations": [
                    "foreign-Site trip rows and nested identity were visible",
                    "foreign-Site trip data influenced CSV export and aggregate projections",
                    "foreign and missing vehicle filters were not concealed consistently",
                    "canonical direct, home, client, archived-Site, and driver provenance boundaries were not enforced consistently",
                ],
            },
        },
        "remediation": {
            "summary": (
                "The trip index now derives a visible operational-vehicle universe from the "
                "actor's accessible Sites, constrains rows, CSV, filters, summaries, charts, "
                "and hero projections to that universe, and redacts driver identity outside "
                "the same historical Site boundary."
            ),
            "production_files": 1,
            "regression_test_files": 1,
            "changed_paths": 2,
            "insertions": 717,
            "deletions": 71,
            "selected_get_route_and_csv_branch_only": True,
            "ordinary_viewer_approved_site_scope": True,
            "fleet_manage_operational_site_bypass_preserved": True,
            "archived_site_concealment": True,
            "foreign_or_missing_vehicle_filter_concealment_404": True,
            "nested_driver_identity_redaction": True,
            "canonical_direct_home_and_client_site_provenance": True,
            "rows_filters_summaries_charts_and_hero_use_consistent_visible_universe": True,
            "route_declarations_changed": False,
            "pages_components_copy_or_layout_changed": False,
            "security_devices_or_user_site_access_service_changed": False,
            "third_party_source_assets_wording_or_layout_copied": False,
            "single_tenant_multi_site_boundary_preserved": True,
        },
        "delegated_runtime_execution": {
            "execution_owners": {
                "initial_two_case_red": "root audit lane",
                "recreated_red_expanded_red_isolated_and_post_merge": (
                    "separate Continue OSS audit fixes task"
                ),
            },
            "run_176_producer_executed_tests": False,
            "root_post_merge_reran_tests_for_run_176": False,
            "root_audit_lane_initial_red": {
                "cases": 2,
                "failed": 2,
                "assertions_reported": 19,
                "duration_seconds": 147.33,
                "exit_code": 1,
                "added_to_bounded_disposition_denominator": False,
            },
            "baseline_original_red": {
                "cases": 2,
                "failed": 2,
                "assertions_reported": 19,
                "duration_seconds": 213.08,
                "exit_code": 1,
                "credit": "REPRODUCTION_ONLY_ZERO_PASSING_DENOMINATOR_CREDIT",
            },
            "baseline_expanded_red": {
                "cases": 5,
                "failed": 5,
                "assertions_reported": 55,
                "duration_seconds": 159.09,
                "exit_code": 1,
                "credit": "REPRODUCTION_ONLY_ZERO_PASSING_DENOMINATOR_CREDIT",
            },
            "isolated_green_focused": {
                "tests": 5,
                "assertions": 175,
                "duration_seconds": 149.95,
                "added_to_bounded_disposition_denominator": False,
            },
            "isolated_supporting_vehicle_controller_regressions": {
                "tests": 4,
                "assertions": 35,
                "duration_seconds": 149.38,
                "reported_separately": True,
                "added_to_bounded_disposition_denominator": False,
            },
            "post_merge_green_focused": {
                "tests": 5,
                "assertions": 175,
                "duration_seconds": 170.48,
                "unique_bounded_disposition_denominator_credit": True,
            },
            "post_merge_supporting_vehicle_controller_regressions": {
                "tests": 4,
                "assertions": 35,
                "duration_seconds": 174.28,
                "reported_separately": True,
                "added_to_bounded_disposition_denominator": False,
                "scope": (
                    "VehiclePageContractTest plus the existing Fleet write-route "
                    "concealment regression"
                ),
            },
            "focused_cases": focused_cases,
            "focused_replay_aggregated_more_than_once": False,
            "unique_bounded_accounting": {
                "prior": {"tests": 83, "assertions": 1589},
                "increment": {"tests": 5, "assertions": 175},
                "resulting": {"tests": 88, "assertions": 1764},
            },
            "syntax": {
                "isolated_files_passed": 2,
                "post_merge_files_passed": 2,
                "result": "PASS",
            },
            "pint": {"isolated": "PASS", "post_merge": "PASS"},
            "git_diff_and_check": "PASS",
            "full_suite_or_coverage_credit": False,
        },
        "independent_static_review": {
            "reported_delegated_read_only_post_diff_reviews": 3,
            "root_read_only_post_merge_assurance_reviews": 1,
            "total_read_only_reviews": 4,
            "root_review_lane": "/root/run176_postmerge_assurance",
            "verdict": "GO",
            "findings": 0,
            "reviewers_executed_tests": False,
            "reviewers_wrote_files": False,
            "exact_merge_commit_tree_parents_and_two_path_delta_verified": True,
            "bounded_site_privacy_contract_verified": True,
            "exact_run_176_receipt_review_completed": False,
            "new_record_reporting_authorized": False,
            "run_176r_still_required": True,
        },
        "cleanup_evidence": {
            "isolated_worktree": "C:/w/fleet-trip",
            "isolated_worktree_removed": True,
            "isolated_branch": "codex/fleet-trip-index-site-privacy-01",
            "isolated_branch_deleted": True,
            "numeric_pid_test_schema_count": 0,
            "audit_owned_php_or_php_cgi_process_count": 0,
            "global_php_or_php_cgi_process_count_asserted": False,
            "unrelated_board_pack_php_processes_observed_and_left_untouched": True,
            "primary_main_clean_before_audit_writes": True,
        },
        "static_ownership_boundary": {
            "owner_records": 665,
            "route_owners": 308,
            "page_owners": 357,
            "action_bridges": 96,
            "queue_total": 507,
            "queue_reviewed": 119,
            "queue_pending": 388,
            "queue_owned": 97,
            "queue_without_ownership": 410,
            "next_zero_based_index": 84,
            "next_queue_id": QUEUE_ID,
            "next_route_record_id": ROUTE_RECORD_ID,
            "next_route_name": ROUTE_NAME,
            "candidate_feature_id": FEATURE_ID,
            "ownership_status": "PENDING_FRESH_SEMANTIC_REVIEW",
            "historical_queue_controller_sha256": (
                "478a1a0a33868536fc3b2baf5db2d06732ddb5fa16094997a5128dc2267b5239"
            ),
            "historical_queue_controller_definition_line": 562,
            "current_controller_sha256": (
                "ba1ecc7e876c352a78122a3b292648d834ed40c8e09ab8893b9d9150e5614c49"
            ),
            "current_controller_definition_line": 566,
            "correctness_does_not_adjudicate_static_ownership": True,
            "fresh_outcome_neutral_ownership_review_required_later": True,
        },
        "benchmark_boundary": {
            "mapped": 2,
            "total": 340,
            "final_no_match_or_NCM": 0,
            "unresolved": 338,
            "changed_by_run_176": False,
        },
        "noninheritance_boundary": noninheritance,
        "reporting_boundary": {
            "current_findings_snapshot": repository["findings_snapshot"],
            "current_retained_identity_count": 12,
            "current_split": (
                "8 provisional + 2 historical already-fixed + 2 historical remediated"
            ),
            "pending_new_record_id": FINDING_ID,
            "pending_reporting_delta": {
                "retained_claim_records": 1,
                "current_provisional_source_claims": 0,
                "historical_already_fixed_records": 0,
                "historical_remediated_records": 1,
                "final_P0": 0,
                "final_P1": 0,
            },
            "proposed_after_independent_exact_artifact_review": {
                "retained_claim_records": 13,
                "current_provisional_source_claims": 8,
                "historical_already_fixed_records": 2,
                "historical_remediated_records": 3,
                "final_P0": 0,
                "final_P1": 0,
            },
            "independent_review_authorized": False,
            "run_176_changes_live_reporting": False,
            "run_176r_required": True,
            "run_177_reporting_required_after_go": True,
            "run_178_fresh_dashboard_verification_required_after_reporting": True,
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
        "bounded_selected_get_and_csv_execution",
        "bounded_site_privacy_correctness",
        "application_commit_integrated_local_main",
    ]
    assert all(value is False for value in noninheritance.values())
    assert all(value is False for value in completion.values())
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    return receipt


def validate(receipt: dict[str, Any]) -> None:
    copy = dict(receipt)
    seal = copy.pop("receipt_self_seal_sha256")
    assert seal == canonical_sha256(copy)
    assert receipt["pins"]["application_remote_publication_observed"] is False
    assert receipt["pins"]["publication_authorized"] is False
    assert receipt["independent_static_review"]["new_record_reporting_authorized"] is False
    assert receipt["independent_static_review"]["total_read_only_reviews"] == 4
    assert receipt["issue_first_disposition"]["candidate_feature_id"] == FEATURE_ID
    assert receipt["issue_first_disposition"]["feature_identity_status"] == (
        "PENDING_FRESH_SEMANTIC_REVIEW"
    )
    assert receipt["delegated_runtime_execution"][
        "focused_replay_aggregated_more_than_once"
    ] is False
    assert receipt["delegated_runtime_execution"]["root_audit_lane_initial_red"][
        "added_to_bounded_disposition_denominator"
    ] is False
    assert receipt["delegated_runtime_execution"]["isolated_green_focused"][
        "added_to_bounded_disposition_denominator"
    ] is False
    assert receipt["delegated_runtime_execution"]["post_merge_green_focused"][
        "unique_bounded_disposition_denominator_credit"
    ] is True
    assert receipt["delegated_runtime_execution"]["unique_bounded_accounting"] == {
        "prior": {"tests": 83, "assertions": 1589},
        "increment": {"tests": 5, "assertions": 175},
        "resulting": {"tests": 88, "assertions": 1764},
    }
    assert receipt["static_ownership_boundary"]["ownership_status"] == (
        "PENDING_FRESH_SEMANTIC_REVIEW"
    )
    assert receipt["reporting_boundary"]["independent_review_authorized"] is False
    assert receipt["reporting_boundary"][
        "proposed_after_independent_exact_artifact_review"
    ] == {
        "retained_claim_records": 13,
        "current_provisional_source_claims": 8,
        "historical_already_fixed_records": 2,
        "historical_remediated_records": 3,
        "final_P0": 0,
        "final_P1": 0,
    }
    assert all(value is False for value in receipt["noninheritance_boundary"].values())
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
    print(
        json.dumps(
            {
                "run_id": RUN_ID,
                "status": STATUS,
                "materializer_sha256": file_record(f"{PREFIX}/{SCRIPT_REL}")["sha256"],
                "receipt_sha256": sha256(encoded),
                "receipt_self_seal_sha256": receipt["receipt_self_seal_sha256"],
                "application_commit_integrated_local_main": True,
                "application_commit_published": False,
                "focused_unique_credit": "5/175",
                "resulting_unique_total": "88/1764",
                "pending_reporting_lineage": "13 = 8 + 2 + 3",
                "new_record_reporting_authorized": False,
                "static_ownership_adjudicated": False,
                "audit_complete": False,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
