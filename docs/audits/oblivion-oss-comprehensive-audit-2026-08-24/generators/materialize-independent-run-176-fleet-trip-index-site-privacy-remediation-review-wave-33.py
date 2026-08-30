#!/usr/bin/env python3
"""Materialize the independent exact-artifact review of RUN176.

The review validates frozen RUN176 producer and receipt bytes and writes only
RUN176R. It does not rerun PHP or tests, touch a database, start a browser,
mutate product or live-reporting source, adjudicate route/action ownership,
publish commits, or change the live finding register.
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
    "current-run-176r-independent-fleet-trip-index-site-privacy-remediation-review-wave-33.json"
)
OUTPUT = AUDIT / OUTPUT_REL
PRODUCER_REL = (
    "generators/"
    "materialize-run-176-fleet-trip-index-site-privacy-remediation-wave-33.py"
)
RECEIPT_REL = (
    "evidence/runtime/"
    "current-run-176-fleet-trip-index-site-privacy-remediation-wave-33.json"
)

RUN_ID = (
    "RUN-176R-INDEPENDENT-FLEET-TRIP-INDEX-SITE-PRIVACY-01-"
    "REMEDIATION-REVIEW-WAVE-33"
)
STATUS = (
    "GO_EXACT_RUN176_ARTIFACT_REVIEW_NEW_HISTORICAL_REMEDIATED_REPORTING_"
    "AUTHORIZED_ZERO_STATIC_OWNERSHIP_PUBLICATION_FINAL_FINDING_OR_"
    "COMPLETION_CREDIT"
)

EXPECTED_PRODUCER = {
    "sha256": "26386c9394da1ce73274e8d9a4c19e45fd5bad7409d37770401eb0b491a6f9ba",
    "git_blob_id": "ccecc5b7fab536143bc94a8698fba23e9c60678e",
    "bytes": 33454,
    "lines": 814,
}
EXPECTED_RECEIPT = {
    "sha256": "6e9fa6d855e6ec168d4c651921702dab8872810ddd89f6ba3cd353bf49e0c87c",
    "git_blob_id": "f1f96f69ea70d9962342cd1f64ea532f81a00eab",
    "bytes": 17327,
    "lines": 414,
    "receipt_self_seal_sha256": (
        "541e2cc0c0a167b48cfac6e96ab2286d9898cb737dec2eb115b41d56e74b9617"
    ),
}

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
CANDIDATE_FEATURE_ID = "CAP-FLEET-VEHICLE-REGISTER"
QUEUE_ID = "RUN090-ROUTE-0085"
ROUTE_RECORD_ID = "RUN077-ROUTE-0693"
ROUTE_NAME = "fleet-assets.trips.index"
ACTION = "VehicleController::trips"

CONTROLLER = "app/Http/Controllers/FleetAssets/VehicleController.php"
TEST = "tests/Feature/FleetAssets/FleetTripIndexSitePrivacyTest.php"
ROUTE_FILE = "routes/fleet-assets.php"
FINDINGS_REPO = f"{PREFIX}/findings.json"
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
EXPECTED_ROUTE_FILE = {
    "path": ROUTE_FILE,
    "sha256": "4be79ba4a0957f81f3e99de8eea7f29a398f8a115957bd44af06dbbf78fe2c4c",
    "git_blob_id": "f0b2b8c199ada1d8ef8bdb41c99bfc2ac02f93d2",
    "bytes": 28332,
    "lines": 351,
}
EXPECTED_FINDINGS = {
    "path": FINDINGS_REPO,
    "sha256": "32675839fb79d66d49d93a97be66f2805d854231c6ca8c513d336941c6291b0e",
    "git_blob_id": "ee6d5ac14e7b492b612ef5a84d7c6e199760507d",
    "bytes": 561735,
    "lines": 10073,
}
EXPECTED_DIRTY = sorted(
    [
        f"{PREFIX}/{PRODUCER_REL}",
        f"{PREFIX}/{RECEIPT_REL}",
        f"{PREFIX}/{SCRIPT_REL}",
        f"{PREFIX}/{OUTPUT_REL}",
    ]
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


def reject_duplicates(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    value: dict[str, Any] = {}
    for key, item in pairs:
        assert key not in value, f"duplicate JSON key: {key}"
        value[key] = item
    return value


def strict_text(raw: bytes, label: str) -> None:
    assert not raw.startswith(b"\xef\xbb\xbf"), f"BOM not allowed: {label}"
    assert b"\r" not in raw, f"CR not allowed: {label}"
    assert raw.endswith(b"\n"), f"final LF required: {label}"
    for number, line in enumerate(raw.splitlines(), start=1):
        assert line == line.rstrip(b" \t"), f"trailing whitespace: {label}:{number}"


def strict_json(relative: str) -> dict[str, Any]:
    raw = (AUDIT / relative).read_bytes()
    strict_text(raw, relative)
    value = json.loads(raw.decode("utf-8"), object_pairs_hook=reject_duplicates)
    assert isinstance(value, dict)
    expected = (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    assert raw == expected, f"pretty JSON round-trip failed: {relative}"
    return value


def file_record(relative: str) -> dict[str, Any]:
    raw = (AUDIT / relative).read_bytes()
    strict_text(raw, relative)
    return {
        "path": relative,
        "sha256": sha256(raw),
        "git_blob_id": hashlib.sha1(
            f"blob {len(raw)}\0".encode("ascii") + raw
        ).hexdigest(),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


def git(*args: str) -> str:
    return subprocess.run(
        ["git", *args],
        cwd=ROOT,
        check=True,
        capture_output=True,
        text=True,
        encoding="utf-8",
    ).stdout.rstrip()


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


def git_file_record(relative: str, revision: str) -> dict[str, Any]:
    raw = git_bytes(revision, relative)
    strict_text(raw, f"{revision}:{relative}")
    record: dict[str, Any] = {
        "path": relative,
        "sha256": sha256(raw),
        "git_blob_id": git("rev-parse", f"{revision}:{relative}"),
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
    before_output = [path for path in EXPECTED_DIRTY if path != f"{PREFIX}/{OUTPUT_REL}"]
    assert dirty in (before_output, EXPECTED_DIRTY), dirty
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
    assert git_bytes(BASE, FINDINGS_REPO) == git_bytes(MERGE, FINDINGS_REPO)
    assert git_bytes(BASE, ROUTE_FILE) == git_bytes(MERGE, ROUTE_FILE)
    assert (ROOT / CONTROLLER).read_bytes() == git_bytes(MERGE, CONTROLLER)
    assert (ROOT / TEST).read_bytes() == git_bytes(MERGE, TEST)
    assert (ROOT / FINDINGS_REPO).read_bytes() == git_bytes(MERGE, FINDINGS_REPO)
    assert (ROOT / ROUTE_FILE).read_bytes() == git_bytes(MERGE, ROUTE_FILE)

    patch = subprocess.run(
        ["git", "patch-id", "--stable"],
        cwd=ROOT,
        input=subprocess.run(
            ["git", "diff", BASE, FIX],
            cwd=ROOT,
            check=True,
            capture_output=True,
        ).stdout,
        check=True,
        capture_output=True,
    ).stdout.decode("ascii").split()[0]
    assert patch == PATCH_ID

    assert git_file_record(CONTROLLER, BASE) == EXPECTED_BASE_CONTROLLER
    assert git_file_record(CONTROLLER, FIX) == EXPECTED_FIXED_CONTROLLER
    assert git_file_record(TEST, FIX) == EXPECTED_FIXED_TEST
    assert git_file_record(CONTROLLER, MERGE) == EXPECTED_FIXED_CONTROLLER
    assert git_file_record(TEST, MERGE) == EXPECTED_FIXED_TEST
    assert git_file_record(ROUTE_FILE, MERGE) == EXPECTED_ROUTE_FILE

    return {
        "stable_patch_id": patch,
        "dirty_allowlist": EXPECTED_DIRTY,
        "dirty_before_output": before_output,
    }


def validate_producer() -> dict[str, Any]:
    producer_file = file_record(PRODUCER_REL)
    receipt_file = file_record(RECEIPT_REL)
    assert {key: producer_file[key] for key in EXPECTED_PRODUCER} == EXPECTED_PRODUCER
    assert {
        key: receipt_file[key] for key in ("sha256", "git_blob_id", "bytes", "lines")
    } == {
        key: EXPECTED_RECEIPT[key] for key in ("sha256", "git_blob_id", "bytes", "lines")
    }

    producer = strict_json(RECEIPT_REL)
    copy = dict(producer)
    seal = copy.pop("receipt_self_seal_sha256")
    assert seal == EXPECTED_RECEIPT["receipt_self_seal_sha256"]
    assert seal == canonical_sha256(copy)
    assert producer["run_id"] == (
        "RUN-176-FLEET-TRIP-INDEX-SITE-PRIVACY-01-REMEDIATION-WAVE-33"
    )

    pins = producer["pins"]
    assert pins["application_baseline_commit"] == BASE
    assert pins["application_baseline_tree"] == BASE_TREE
    assert pins["fix_commit"] == FIX
    assert pins["fix_tree"] == FIX_TREE
    assert pins["fix_parent"] == BASE
    assert pins["stable_patch_id"] == PATCH_ID
    assert pins["clean_audit_release_commit"] == AUDIT_RELEASE
    assert pins["clean_audit_release_tree"] == AUDIT_RELEASE_TREE
    assert pins["local_main_merge_commit"] == MERGE
    assert pins["local_main_tree"] == MERGE_TREE
    assert pins["merge_parents"] == [AUDIT_RELEASE, FIX]
    assert pins["audit_advance_from_baseline"] == {
        "path_count": 0,
        "clean_audit_release_equals_application_baseline": True,
        "transferred_paths_unchanged": True,
    }
    assert pins["origin_main_observed"] == ORIGIN_MAIN
    assert pins["local_main_ahead"] == 7 and pins["local_main_behind"] == 0
    assert pins["application_remote_publication_observed"] is False
    assert pins["publication_authorized"] is False
    assert pins["materializer"] == {
        "path": f"{PREFIX}/{PRODUCER_REL}",
        **EXPECTED_PRODUCER,
    }
    assert pins["baseline_controller"] == EXPECTED_BASE_CONTROLLER
    assert pins["fix_source_and_regression_test"] == [
        EXPECTED_FIXED_CONTROLLER,
        EXPECTED_FIXED_TEST,
    ]
    assert pins["merged_source_and_regression_test"] == [
        EXPECTED_FIXED_CONTROLLER,
        EXPECTED_FIXED_TEST,
    ]
    assert pins["current_route_source"] == EXPECTED_ROUTE_FILE
    assert pins["current_findings_before_run_176"] == EXPECTED_FINDINGS

    disposition = producer["issue_first_disposition"]
    assert disposition["finding_id"] == FINDING_ID
    assert disposition["candidate_feature_id"] == CANDIDATE_FEATURE_ID
    assert disposition["feature_identity_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
    assert disposition["selected_route"] == {
        "queue_id": QUEUE_ID,
        "route_record_id": ROUTE_RECORD_ID,
        "route_name": ROUTE_NAME,
        "controller_action": ACTION,
    }
    assert disposition["verdict"] == (
        "REPRODUCED_AND_REMEDIATED_LOCAL_MAIN_NOT_PUBLISHED"
    )
    assert disposition["new_discovery_stopped_after_confirmation"] is True
    assert disposition["exclusive_remediation_paths"] == list(CHANGED_PATHS)
    red = disposition["red_baseline"]
    assert red["audit_lane_initial_two_case_reproduction"] == {
        "cases": 2,
        "failed": 2,
        "assertions_reported": 19,
        "duration_seconds": 147.33,
        "exit_code": 1,
    }
    assert red["transferred_recreated_two_case_reproduction"] == {
        "cases": 2,
        "failed": 2,
        "assertions_reported": 19,
        "duration_seconds": 213.08,
        "exit_code": 1,
    }
    assert red["expanded_permanent_five_case_reproduction"] == {
        "cases": 5,
        "failed": 5,
        "assertions_reported": 55,
        "duration_seconds": 159.09,
        "exit_code": 1,
    }
    assert red["passing_denominator_credit"] == 0

    runtime = producer["delegated_runtime_execution"]
    assert runtime["execution_owners"] == {
        "initial_two_case_red": "root audit lane",
        "recreated_red_expanded_red_isolated_and_post_merge": (
            "separate Continue OSS audit fixes task"
        ),
    }
    assert runtime["run_176_producer_executed_tests"] is False
    assert runtime["root_post_merge_reran_tests_for_run_176"] is False
    assert runtime["root_audit_lane_initial_red"] == {
        "cases": 2,
        "failed": 2,
        "assertions_reported": 19,
        "duration_seconds": 147.33,
        "exit_code": 1,
        "added_to_bounded_disposition_denominator": False,
    }
    assert runtime["baseline_original_red"]["credit"] == (
        "REPRODUCTION_ONLY_ZERO_PASSING_DENOMINATOR_CREDIT"
    )
    assert runtime["baseline_expanded_red"]["credit"] == (
        "REPRODUCTION_ONLY_ZERO_PASSING_DENOMINATOR_CREDIT"
    )
    assert runtime["isolated_green_focused"] == {
        "tests": 5,
        "assertions": 175,
        "duration_seconds": 149.95,
        "added_to_bounded_disposition_denominator": False,
    }
    assert runtime["isolated_supporting_vehicle_controller_regressions"] == {
        "tests": 4,
        "assertions": 35,
        "duration_seconds": 149.38,
        "reported_separately": True,
        "added_to_bounded_disposition_denominator": False,
    }
    assert runtime["post_merge_green_focused"] == {
        "tests": 5,
        "assertions": 175,
        "duration_seconds": 170.48,
        "unique_bounded_disposition_denominator_credit": True,
    }
    supporting = runtime["post_merge_supporting_vehicle_controller_regressions"]
    assert supporting["tests"] == 4 and supporting["assertions"] == 35
    assert supporting["duration_seconds"] == 174.28
    assert supporting["added_to_bounded_disposition_denominator"] is False
    assert len(runtime["focused_cases"]) == 5
    assert runtime["focused_replay_aggregated_more_than_once"] is False
    assert runtime["unique_bounded_accounting"] == {
        "prior": {"tests": 83, "assertions": 1589},
        "increment": {"tests": 5, "assertions": 175},
        "resulting": {"tests": 88, "assertions": 1764},
    }
    assert runtime["full_suite_or_coverage_credit"] is False

    review = producer["independent_static_review"]
    assert review["reported_delegated_read_only_post_diff_reviews"] == 3
    assert review["root_read_only_post_merge_assurance_reviews"] == 1
    assert review["total_read_only_reviews"] == 4
    assert review["verdict"] == "GO" and review["findings"] == 0
    assert review["exact_run_176_receipt_review_completed"] is False
    assert review["new_record_reporting_authorized"] is False
    assert review["run_176r_still_required"] is True

    cleanup = producer["cleanup_evidence"]
    assert cleanup["numeric_pid_test_schema_count"] == 0
    assert cleanup["audit_owned_php_or_php_cgi_process_count"] == 0
    assert cleanup["global_php_or_php_cgi_process_count_asserted"] is False
    assert cleanup["unrelated_board_pack_php_processes_observed_and_left_untouched"] is True

    ownership = producer["static_ownership_boundary"]
    assert ownership["owner_records"] == 665
    assert ownership["route_owners"] == 308
    assert ownership["page_owners"] == 357
    assert ownership["action_bridges"] == 96
    assert ownership["queue_total"] == 507
    assert ownership["queue_reviewed"] == 119
    assert ownership["queue_pending"] == 388
    assert ownership["queue_owned"] == 97
    assert ownership["queue_without_ownership"] == 410
    assert ownership["next_zero_based_index"] == 84
    assert ownership["next_queue_id"] == QUEUE_ID
    assert ownership["next_route_record_id"] == ROUTE_RECORD_ID
    assert ownership["next_route_name"] == ROUTE_NAME
    assert ownership["candidate_feature_id"] == CANDIDATE_FEATURE_ID
    assert ownership["ownership_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
    assert ownership["correctness_does_not_adjudicate_static_ownership"] is True
    assert ownership["fresh_outcome_neutral_ownership_review_required_later"] is True

    reporting = producer["reporting_boundary"]
    assert reporting["current_findings_snapshot"] == {
        "retained_record_count": 12,
        "current_provisional_count": 8,
        "historical_already_fixed_count": 2,
        "historical_remediated_count": 2,
        "fleet_trip_index_site_privacy_record_present": False,
    }
    assert reporting["pending_new_record_id"] == FINDING_ID
    assert reporting["pending_reporting_delta"] == {
        "retained_claim_records": 1,
        "current_provisional_source_claims": 0,
        "historical_already_fixed_records": 0,
        "historical_remediated_records": 1,
        "final_P0": 0,
        "final_P1": 0,
    }
    assert reporting["proposed_after_independent_exact_artifact_review"] == {
        "retained_claim_records": 13,
        "current_provisional_source_claims": 8,
        "historical_already_fixed_records": 2,
        "historical_remediated_records": 3,
        "final_P0": 0,
        "final_P1": 0,
    }
    assert reporting["independent_review_authorized"] is False
    assert reporting["run_176_changes_live_reporting"] is False
    assert reporting["run_176r_required"] is True
    assert reporting["run_177_reporting_required_after_go"] is True
    assert reporting["run_178_fresh_dashboard_verification_required_after_reporting"] is True

    assert all(value is False for value in producer["noninheritance_boundary"].values())
    assert [
        key for key, value in producer["credit_boundary"].items() if value
    ] == [
        "historical_condition_confirmed",
        "current_defect_reproduced",
        "application_remediation",
        "bounded_runtime",
        "bounded_selected_get_and_csv_execution",
        "bounded_site_privacy_correctness",
        "application_commit_integrated_local_main",
    ]
    assert all(value is False for value in producer["completion_boundary"].values())
    assert producer["artifact_completion_test_met"] is True
    assert producer["audit_completion_test_met"] is False
    return producer


def validate_live_register_before_reporting() -> dict[str, Any]:
    findings = strict_json("findings.json")
    records = findings["records"]
    record_ids = [record["id"] for record in records]
    statuses = Counter(record["record_status"] for record in records)
    assert len(records) == len(record_ids) == len(set(record_ids)) == 12
    assert FINDING_ID not in record_ids
    assert statuses == {
        "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING": 8,
        "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING": 2,
        "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING": 2,
    }

    counts = findings["counts"]
    assert counts["retained_claim_records"] == 12
    assert counts["provisional_source_claims"] == 8
    assert counts["historical_already_fixed"] == 2
    assert counts["historical_remediated"] == 2
    assert counts["bounded_disposition_tests_passed"] == 83
    assert counts["bounded_disposition_assertions"] == 1589
    assert counts["final_P0"] == counts["final_P1"] == 0
    assert counts["static_source_feature_ownership_records"] == 665
    assert counts["static_source_feature_ownership_route_records"] == 308
    assert counts["static_source_feature_ownership_page_records"] == 357
    assert counts["static_controller_action_bridges"] == 96
    assert counts["direct_exact_queue_records"] == 507
    assert counts["direct_exact_queue_reviewed"] == 119
    assert counts["direct_exact_queue_pending_unreviewed"] == 388
    assert counts["direct_exact_queue_owned"] == 97
    assert counts["direct_exact_queue_without_ownership"] == 410
    assert counts["benchmark_mapped"] == 2
    assert counts["final_no_match"] == 0
    assert counts["benchmark_unresolved"] == 338

    reconciliation = findings["reconciliation"]
    assert reconciliation["retained_record_ids_unique"] is True
    assert reconciliation["current_provisional_ids_unique"] is True
    assert reconciliation["retained_record_count"] == 12
    assert reconciliation["current_provisional_count"] == 8
    assert reconciliation["historical_already_fixed_count"] == 2
    assert reconciliation["historical_remediated_count"] == 2
    assert reconciliation["final_ids_cross_file_reconciled"] is False

    record = file_record("findings.json")
    assert record == {
        "path": "findings.json",
        "sha256": EXPECTED_FINDINGS["sha256"],
        "git_blob_id": EXPECTED_FINDINGS["git_blob_id"],
        "bytes": EXPECTED_FINDINGS["bytes"],
        "lines": EXPECTED_FINDINGS["lines"],
    }
    return record


def build_receipt(
    producer: dict[str, Any],
    repository: dict[str, Any],
    findings_record: dict[str, Any],
) -> dict[str, Any]:
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
    receipt: dict[str, Any] = {
        "schema_version": (
            "run-176r-independent-fleet-trip-index-site-privacy-remediation-"
            "review-wave-33-v1"
        ),
        "run_id": RUN_ID,
        "status": STATUS,
        "materialized_on": "2026-08-30",
        "architecture_boundary": producer["architecture_boundary"],
        "pins": {
            "application_baseline_commit": BASE,
            "application_baseline_tree": BASE_TREE,
            "fix_commit": FIX,
            "fix_tree": FIX_TREE,
            "clean_audit_release_commit": AUDIT_RELEASE,
            "clean_audit_release_tree": AUDIT_RELEASE_TREE,
            "local_main_merge_commit": MERGE,
            "local_main_tree": MERGE_TREE,
            "origin_main_observed": ORIGIN_MAIN,
            "stable_patch_id": repository["stable_patch_id"],
            "application_published": False,
            "producer_generator": {"path": PRODUCER_REL, **EXPECTED_PRODUCER},
            "producer_receipt": {"path": RECEIPT_REL, **EXPECTED_RECEIPT},
            "review_materializer": file_record(SCRIPT_REL),
            "current_findings_before_run_177": findings_record,
            "dirty_allowlist": repository["dirty_allowlist"],
        },
        "review": {
            "reviewer_lane": "/root/run176_producer_review",
            "independent_pre_execution_review_wrote_files": False,
            "review_materializer_implemented_after_go": True,
            "review_materialized_by_root": True,
            "reviewer_executed_php_tests_browser_or_database": False,
            "reviewer_wrote_application_or_live_reporting_files": False,
            "checks": {
                "strict_json_zero_duplicate_keys": True,
                "lf_no_bom_no_trailing_whitespace": True,
                "pretty_json_round_trip": True,
                "canonical_self_seal": True,
                "commit_tree_parent_subject_patch_id": True,
                "two_path_order_status_numstat_blob_sha_bytes_lines": True,
                "zero_path_audit_advance_and_transferred_byte_equality": True,
                "origin_tracking_tip_local_divergence_and_nonpublication": True,
                "root_and_delegated_red_execution_distinguished": True,
                "runtime_arithmetic_replay_and_supporting_exclusions": True,
                "current_register_12_records_and_fleet_record_absent": True,
                "cleanup_and_unrelated_process_boundary": True,
                "static_ownership_index_84_and_candidate_boundary": True,
                "credit_noninheritance_and_completion_boundaries": True,
                "four_path_dirty_allowlist": True,
            },
            "discrepancies": [],
        },
        "decision": {
            "verdict": "GO",
            "blocking_discrepancies": 0,
            "new_historical_remediated_record_reporting_authorized": True,
            "authorized_live_reporting_run": "RUN-177",
            "authorized_finding_id": FINDING_ID,
            "authorized_candidate_feature_id": CANDIDATE_FEATURE_ID,
            "authorized_reporting_status": (
                "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
            ),
            "authorized_live_count_delta": {
                "retained_claim_records": 1,
                "current_provisional_source_claims": 0,
                "historical_already_fixed_records": 0,
                "historical_remediated_records": 1,
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
                "retained_claim_records": 13,
                "current_provisional_source_claims": 8,
                "historical_already_fixed_records": 2,
                "historical_remediated_records": 3,
                "final_P0": 0,
                "final_P1": 0,
            },
            "authorized_unique_bounded_disposition_increment": {
                "prior_tests": 83,
                "prior_assertions": 1589,
                "tests": 5,
                "assertions": 175,
                "resulting_tests": 88,
                "resulting_assertions": 1764,
                "post_merge_focused_counted_once": True,
                "root_or_delegated_red_counted": False,
                "isolated_replay_counted_again": False,
                "supporting_runs_counted": False,
            },
            "static_ownership_remains_pending": {
                "ownership_status": "PENDING_FRESH_SEMANTIC_REVIEW",
                "next_zero_based_index": 84,
                "next_queue_id": QUEUE_ID,
                "next_route_record_id": ROUTE_RECORD_ID,
                "route_owner_authorized": False,
                "controller_action_bridge_authorized": False,
                "queue_advance_authorized": False,
            },
            "live_reporting_changed_by_run_176r": False,
            "run_177_required": True,
            "run_178_fresh_dashboard_verification_required": True,
        },
        "credit_boundary": {
            "independent_exact_artifact_review_for_new_historical_remediated_reporting": True,
            "application_remediation_reexecution": False,
            "runtime_reexecution": False,
            "application_publication": False,
            "live_reporting": False,
            "static_route_feature_ownership": False,
            "static_controller_action_bridge": False,
            "queue_advance": False,
            "application_browser": False,
            "benchmark_mapping": False,
            "final_no_match_or_NCM": False,
            "final_finding": False,
            "pass": False,
            "release": False,
            "completion": False,
            "audit_complete": False,
        },
        "completion_boundary": completion,
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [f"{PREFIX}/{SCRIPT_REL}", f"{PREFIX}/{OUTPUT_REL}"],
    }
    assert [key for key, value in receipt["credit_boundary"].items() if value] == [
        "independent_exact_artifact_review_for_new_historical_remediated_reporting"
    ]
    assert all(value is False for value in completion.values())
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    return receipt


def validate_review(receipt: dict[str, Any]) -> None:
    copy = dict(receipt)
    seal = copy.pop("receipt_self_seal_sha256")
    assert seal == canonical_sha256(copy)
    assert receipt["decision"]["verdict"] == "GO"
    assert receipt["decision"]["blocking_discrepancies"] == 0
    assert receipt["decision"][
        "new_historical_remediated_record_reporting_authorized"
    ] is True
    assert receipt["decision"]["authorized_live_reporting_run"] == "RUN-177"
    assert receipt["decision"]["authorized_resulting_lineage"] == {
        "retained_claim_records": 13,
        "current_provisional_source_claims": 8,
        "historical_already_fixed_records": 2,
        "historical_remediated_records": 3,
        "final_P0": 0,
        "final_P1": 0,
    }
    assert receipt["decision"]["authorized_unique_bounded_disposition_increment"] == {
        "prior_tests": 83,
        "prior_assertions": 1589,
        "tests": 5,
        "assertions": 175,
        "resulting_tests": 88,
        "resulting_assertions": 1764,
        "post_merge_focused_counted_once": True,
        "root_or_delegated_red_counted": False,
        "isolated_replay_counted_again": False,
        "supporting_runs_counted": False,
    }
    pending = receipt["decision"]["static_ownership_remains_pending"]
    assert pending["ownership_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
    assert pending["next_zero_based_index"] == 84
    assert pending["route_owner_authorized"] is False
    assert pending["controller_action_bridge_authorized"] is False
    assert pending["queue_advance_authorized"] is False
    assert receipt["pins"]["application_published"] is False
    assert receipt["credit_boundary"]["application_publication"] is False
    assert receipt["credit_boundary"]["live_reporting"] is False
    assert receipt["credit_boundary"]["static_route_feature_ownership"] is False
    assert all(value is False for value in receipt["completion_boundary"].values())


def main() -> None:
    repository = validate_repository()
    producer = validate_producer()
    findings_record = validate_live_register_before_reporting()
    receipt = build_receipt(producer, repository, findings_record)
    validate_review(receipt)
    encoded = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_bytes(encoded)
    assert OUTPUT.read_bytes() == encoded
    reloaded = strict_json(OUTPUT_REL)
    assert reloaded == receipt
    validate_review(reloaded)
    print(
        json.dumps(
            {
                "run_id": RUN_ID,
                "status": STATUS,
                "materializer_sha256": file_record(SCRIPT_REL)["sha256"],
                "receipt_sha256": sha256(encoded),
                "receipt_self_seal_sha256": receipt["receipt_self_seal_sha256"],
                "verdict": "GO",
                "run_177_new_historical_remediated_reporting_authorized": True,
                "authorized_result": "13 = 8 provisional + 2 already-fixed + 3 remediated",
                "authorized_unique_bounded_total": "88/1764",
                "static_ownership_adjudicated": False,
                "application_published": False,
                "audit_complete": False,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
