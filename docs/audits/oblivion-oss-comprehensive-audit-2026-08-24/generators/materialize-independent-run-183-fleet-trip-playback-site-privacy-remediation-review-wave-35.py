#!/usr/bin/env python3
"""Independently validate RUN183 and authorize bounded RUN184 reporting.

This materializer performs Git, byte, schema, arithmetic, credit-boundary, and
live-register checks only. It does not run PHP, touch a database, start a
browser, mutate application source, change live reporting, publish commits, or
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
PRODUCER_REL = (
    "generators/"
    "materialize-run-183-fleet-trip-playback-site-privacy-remediation-wave-35.py"
)
RECEIPT_REL = (
    "evidence/runtime/"
    "current-run-183-fleet-trip-playback-site-privacy-remediation-wave-35.json"
)
OUTPUT_REL = (
    "evidence/runtime/"
    "current-run-183r-independent-fleet-trip-playback-site-privacy-"
    "remediation-review-wave-35.json"
)
OUTPUT = AUDIT / OUTPUT_REL

RUN_ID = (
    "RUN-183R-INDEPENDENT-FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01-"
    "REMEDIATION-REVIEW-WAVE-35"
)
STATUS = (
    "GO_EXACT_RUN183_ARTIFACT_REVIEW_HISTORICAL_REMEDIATED_REPORTING_"
    "AUTHORIZED_ZERO_STATIC_OWNERSHIP_PUBLICATION_FINAL_FINDING_OR_"
    "COMPLETION_CREDIT"
)
PRODUCER_STATUS = (
    "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING_"
    "BOUNDED_VERIFIED_NOT_PUBLISHED_LIVE_REPORTING_NOT_YET_AUTHORIZED_"
    "ZERO_STATIC_OWNERSHIP_OR_COMPLETION_CREDIT"
)
RECORD_STATUS = (
    "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
)

BASE = "db4196ccb3a8d9f6bcb33fb40680527d09c02dac"
BASE_TREE = "68052b68b070dff799d5be1d5515ec0b8472207f"
FIX = "93e576978efae4a0112a95ed406c312f6bcadeb5"
FIX_TREE = "f265c8476773aaceecbfe90680e59b5f4c74b205"
ADVANCED_MAIN = "0537f0f0eacafbeaf635ced4883a8bdf8e49d3f6"
ADVANCED_MAIN_TREE = "5eb8c401847f2da101922aef6c100b8e03d30b9d"
MERGE = "4038cf7fe5a789ca64e436300f2cf4b94ac16db4"
MERGE_TREE = "b9757ccb9010564b8512c0ed47abfc553f38b697"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"
PATCH_ID = "12c306d28e54ff88432d18b271706473ee793871"

FINDING_ID = "FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01"
CANDIDATE_FEATURE_ID = "CAP-FLEET-VEHICLE-REGISTER"
QUEUE_ID = "RUN090-ROUTE-0086"
ROUTE_RECORD_ID = "RUN077-ROUTE-0694"
CONTROLLER = "app/Http/Controllers/Fleet/FleetTripController.php"
TEST = "tests/Feature/FleetAssets/FleetTripPlaybackSitePrivacyTest.php"
FINDINGS_REL = "findings.json"
FINDINGS_ROOT_REL = f"{PREFIX}/{FINDINGS_REL}"
CHANGED_PATHS = [CONTROLLER, TEST]

ADVANCED_DISJOINT_PATHS = [
    "app/Http/Controllers/Settings/ApiSettingsController.php",
    "app/Services/Integration/GovernedWebhookProbeService.php",
    "app/Services/Queclink/Listener/ConnectionState.php",
    "app/Services/Queclink/Listener/FrameRouter.php",
    "app/Services/Queclink/SerialNumberAllocator.php",
    "tests/Feature/Queclink/FrameRouterTest.php",
    "tests/Feature/Settings/ApiSettingsWebhookDestinationTest.php",
    "tests/Unit/Services/Queclink/CommandBuilderTest.php",
]

EXPECTED_PRODUCER = {
    "sha256": "602964ec765cc9bd71d7b6fed103bdbd1b4b5543c0843f2c2dcdb2a960779f8e",
    "git_blob_id": "25fd246840a9ad80158790d5585169d23c278fa2",
    "bytes": 43544,
    "lines": 1035,
}
EXPECTED_RECEIPT = {
    "sha256": "7bb1b1013cf67344c48e5a8b6e551bf3c769695e0384c2b333fb47286e53310a",
    "git_blob_id": "b381cafa6c5ed2373d0859a95732955f0b4394a9",
    "bytes": 24679,
    "lines": 634,
    "receipt_self_seal_sha256": (
        "839e8d47700afedd2ec277695bbe492bd13433492ce0ff724c753988b5ce039a"
    ),
}
EXPECTED_FINDINGS = {
    "sha256": "55337abfc8f2fe9fde863715e3d77649ec6dd195008281944881b02e00bb54e1",
    "git_blob_id": "bd0a13dc86ebdc88073ee3ac999b3514ac0a0490",
    "bytes": 590974,
    "lines": 10553,
}
EXPECTED_CONTROLLER = {
    "path": CONTROLLER,
    "sha256": "4a5f448e230c79e4effcad358ef65a5ba9fa6b9774c43d2df87e3485b9b5ad63",
    "git_blob_id": "2373c95b30958399c8ed648915991c01a0fbc84c",
    "bytes": 10934,
    "lines": 281,
}
EXPECTED_TEST = {
    "path": TEST,
    "sha256": "071675ba9aec303176aa00758371cbedd966e944c172e75146743d3111f1031b",
    "git_blob_id": "68eaf494014abf68924ab47eadd4cb2e8ef12e8d",
    "bytes": 24787,
    "lines": 670,
}

EXPECTED_DIRTY_WITHOUT_OUTPUT = sorted(
    [
        f"{PREFIX}/{PRODUCER_REL}",
        f"{PREFIX}/{RECEIPT_REL}",
        f"{PREFIX}/{SCRIPT_REL}",
    ]
)
EXPECTED_DIRTY = sorted(EXPECTED_DIRTY_WITHOUT_OUTPUT + [f"{PREFIX}/{OUTPUT_REL}"])


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


def strict_json(relative: str) -> dict[str, Any]:
    raw = (AUDIT / relative).read_bytes()
    strict_text(raw, relative)

    def no_duplicates(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, f"duplicate JSON key in {relative}: {key}"
            result[key] = value
        return result

    value = json.loads(raw.decode("utf-8"), object_pairs_hook=no_duplicates)
    assert isinstance(value, dict)
    assert (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8") == raw
    return value


def audit_file_record(relative: str) -> dict[str, Any]:
    raw = (AUDIT / relative).read_bytes()
    strict_text(raw, relative)
    return {
        "path": relative,
        "sha256": sha256(raw),
        "git_blob_id": git("hash-object", "--", f"{PREFIX}/{relative}"),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


def revision_file_record(revision: str, relative: str) -> dict[str, Any]:
    raw = git_bytes(revision, relative)
    strict_text(raw, f"{revision}:{relative}")
    return {
        "path": relative,
        "sha256": sha256(raw),
        "git_blob_id": git("rev-parse", f"{revision}:{relative}"),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


def validate_repository() -> dict[str, Any]:
    assert git("rev-parse", "HEAD") == MERGE
    assert git("rev-parse", "main") == MERGE
    assert git("rev-parse", "HEAD^{tree}") == MERGE_TREE
    assert git("show", "-s", "--format=%P", MERGE) == f"{ADVANCED_MAIN} {FIX}"
    assert git("show", "-s", "--format=%s", MERGE) == (
        "merge: scope fleet trip playback to visible sites"
    )
    assert git("rev-parse", f"{FIX}^") == BASE
    assert git("rev-parse", f"{BASE}^{{tree}}") == BASE_TREE
    assert git("rev-parse", f"{FIX}^{{tree}}") == FIX_TREE
    assert git("rev-parse", f"{ADVANCED_MAIN}^{{tree}}") == ADVANCED_MAIN_TREE
    assert git("show", "-s", "--format=%s", FIX) == (
        "fix(fleet): scope trip playback to visible sites"
    )
    assert git("rev-parse", "origin/main") == ORIGIN_MAIN
    assert git("rev-list", "--left-right", "--count", "origin/main...main") == "0\t27"
    assert git("diff", "--cached", "--name-only") == ""
    assert git("diff", "--check") == ""

    dirty = sorted(
        line[3:]
        for line in git("status", "--porcelain=v1", "--untracked-files=all").splitlines()
        if line
    )
    assert dirty in (EXPECTED_DIRTY_WITHOUT_OUTPUT, EXPECTED_DIRTY), dirty

    assert git("diff", "--name-only", BASE, FIX).splitlines() == CHANGED_PATHS
    assert git("diff", "--name-only", ADVANCED_MAIN, MERGE).splitlines() == CHANGED_PATHS
    assert git("diff", "--name-status", BASE, FIX).splitlines() == [
        f"M\t{CONTROLLER}",
        f"A\t{TEST}",
    ]
    assert git("diff", "--numstat", BASE, FIX).splitlines() == [
        f"134\t12\t{CONTROLLER}",
        f"670\t0\t{TEST}",
    ]
    assert git("diff", "--name-only", BASE, ADVANCED_MAIN).splitlines() == (
        ADVANCED_DISJOINT_PATHS
    )
    assert set(ADVANCED_DISJOINT_PATHS).isdisjoint(CHANGED_PATHS)
    assert git_bytes(BASE, CONTROLLER) == git_bytes(ADVANCED_MAIN, CONTROLLER)
    assert git_bytes(FIX, CONTROLLER) == git_bytes(MERGE, CONTROLLER)
    assert git_bytes(FIX, TEST) == git_bytes(MERGE, TEST)
    assert (ROOT / CONTROLLER).read_bytes() == git_bytes(MERGE, CONTROLLER)
    assert (ROOT / TEST).read_bytes() == git_bytes(MERGE, TEST)

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

    controller = revision_file_record(MERGE, CONTROLLER)
    test = revision_file_record(MERGE, TEST)
    assert controller == EXPECTED_CONTROLLER
    assert test == EXPECTED_TEST
    assert revision_file_record(FIX, CONTROLLER) == EXPECTED_CONTROLLER
    assert revision_file_record(FIX, TEST) == EXPECTED_TEST

    producer = audit_file_record(PRODUCER_REL)
    producer_receipt = audit_file_record(RECEIPT_REL)
    assert {key: producer[key] for key in EXPECTED_PRODUCER} == EXPECTED_PRODUCER
    assert {
        key: producer_receipt[key]
        for key in ("sha256", "git_blob_id", "bytes", "lines")
    } == {
        key: EXPECTED_RECEIPT[key]
        for key in ("sha256", "git_blob_id", "bytes", "lines")
    }

    return {
        "dirty_allowlist": EXPECTED_DIRTY,
        "stable_patch_id": patch,
        "controller": controller,
        "test": test,
        "producer": producer,
        "producer_receipt": producer_receipt,
    }


def validate_producer() -> dict[str, Any]:
    producer = strict_json(RECEIPT_REL)
    copy = dict(producer)
    seal = copy.pop("receipt_self_seal_sha256")
    assert seal == EXPECTED_RECEIPT["receipt_self_seal_sha256"]
    assert seal == canonical_sha256(copy)
    assert producer["run_id"] == (
        "RUN-183-FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01-REMEDIATION-WAVE-35"
    )
    assert producer["status"] == PRODUCER_STATUS
    assert producer["pins"]["governing_prompt_sha256"] == (
        "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
    )
    assert producer["pins"]["continuation_prompt_sha256"] == (
        "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d"
    )
    assert producer["pins"]["application_baseline_commit"] == BASE
    assert producer["pins"]["application_baseline_tree"] == BASE_TREE
    assert producer["pins"]["fix_commit"] == FIX
    assert producer["pins"]["fix_tree"] == FIX_TREE
    assert producer["pins"]["clean_advanced_main_commit"] == ADVANCED_MAIN
    assert producer["pins"]["clean_advanced_main_tree"] == ADVANCED_MAIN_TREE
    assert producer["pins"]["local_main_merge_commit"] == MERGE
    assert producer["pins"]["local_main_tree"] == MERGE_TREE
    assert producer["pins"]["merge_parents"] == [ADVANCED_MAIN, FIX]
    assert producer["pins"]["stable_patch_id"] == PATCH_ID
    assert producer["pins"]["advanced_main_path_count"] == 8
    assert [
        record["path"] for record in producer["pins"]["advanced_main_disjoint_paths"]
    ] == ADVANCED_DISJOINT_PATHS
    assert producer["pins"]["materializer"] == {
        "path": f"{PREFIX}/{PRODUCER_REL}",
        **EXPECTED_PRODUCER,
    }
    assert producer["pins"]["temporary_transferred_reproduction_harness"] == {
        "path": TEST,
        "sha256": "98da796613e5bd18752ddf64c1357e6a0f0ae392ab550b8e2039c8c64c489353",
        "lines": 301,
        "git_blob_id": None,
        "status": "TEMPORARY_UNTRACKED_MESSAGE_ONLY_HANDOFF_NOT_CURRENT_TEST_BLOB",
    }

    disposition = producer["issue_first_disposition"]
    assert disposition["finding_id"] == FINDING_ID
    assert disposition["record_status"] == RECORD_STATUS
    assert disposition["candidate_feature_id"] == CANDIDATE_FEATURE_ID
    assert disposition["feature_identity_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
    assert disposition["selected_route"]["zero_based_index"] == 85
    assert disposition["selected_route"]["queue_id"] == QUEUE_ID
    assert disposition["selected_route"]["route_record_id"] == ROUTE_RECORD_ID
    assert disposition["selected_route"]["route_name"] == (
        "fleet-assets.trips.playback"
    )
    assert disposition["selected_route"]["controller_action"] == (
        "FleetTripController::show"
    )
    assert disposition["selected_route"][
        "supporting_data_route_static_ownership_adjudicated"
    ] is False
    red = disposition["red_baseline"]
    assert red["tests"] == 5
    assert red["failed"] == 3
    assert red["passed"] == 2
    assert red["assertions_reported"] == 30
    assert red["exit_code"] == 1
    assert red["passing_denominator_credit"] == 0

    runtime = producer["delegated_runtime_execution"]
    assert runtime["run_183_producer_executed_tests"] is False
    assert runtime["root_post_merge_reran_tests_for_run_183"] is False
    assert runtime["baseline_red"] == {
        "tests": 5,
        "failed": 3,
        "passed": 2,
        "assertions_reported": 30,
        "duration_seconds": 160.09,
        "exit_code": 1,
        "credit": "REPRODUCTION_ONLY_ZERO_PASSING_DENOMINATOR_CREDIT",
    }
    assert runtime["isolated_green_focused"] == {
        "tests": 11,
        "assertions": 167,
        "duration_seconds": 154.22,
        "added_to_bounded_disposition_denominator": False,
    }
    assert runtime["isolated_supporting_fleet_regressions"] == {
        "tests": 20,
        "assertions": 215,
        "duration_seconds": 176.99,
        "scope": "FleetManagementTest plus FleetTripIndexSitePrivacyTest",
        "reported_separately": True,
        "added_to_bounded_disposition_denominator": False,
    }
    assert runtime["post_merge_green_focused"] == {
        "tests": 11,
        "assertions": 167,
        "duration_seconds": 174.89,
        "unique_bounded_disposition_denominator_credit": True,
    }
    assert len(runtime["focused_cases"]) == 11
    assert runtime["focused_replay_aggregated_more_than_once"] is False
    assert runtime["unique_bounded_accounting"] == {
        "prior": {"tests": 88, "assertions": 1764},
        "increment": {"tests": 11, "assertions": 167},
        "resulting": {"tests": 99, "assertions": 1931},
    }
    assert runtime["full_suite_or_coverage_credit"] is False

    assert producer["advanced_main_noninheritance"] == {
        "path_count": 8,
        "paths": ADVANCED_DISJOINT_PATHS,
        "settings_webhook_finding_credit": False,
        "queclink_session_finding_credit": False,
        "queclink_serial_collision_finding_credit": False,
        "settings_or_queclink_runtime_credit": False,
        "transferred_fleet_paths_unchanged_on_advanced_main": True,
    }
    assert all(
        value is False for value in producer["noninheritance_boundary"].values()
    )

    ownership = producer["static_ownership_boundary"]
    assert ownership["owner_records"] == 666
    assert ownership["route_owners"] == 309
    assert ownership["page_owners"] == 357
    assert ownership["action_bridges"] == 97
    assert ownership["queue_total"] == 507
    assert ownership["queue_reviewed"] == 120
    assert ownership["queue_pending"] == 387
    assert ownership["queue_owned"] == 98
    assert ownership["queue_without_ownership"] == 409
    assert ownership["next_zero_based_index"] == 85
    assert ownership["candidate_feature_id"] == CANDIDATE_FEATURE_ID
    assert ownership["ownership_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
    assert ownership["route_owner_authorized"] is False
    assert ownership["controller_action_bridge_authorized"] is False
    assert ownership["supporting_data_route_owner_authorized"] is False
    assert ownership["queue_advance_authorized"] is False

    reporting = producer["reporting_boundary"]
    assert reporting["current_findings_snapshot"] == {
        "retained_record_count": 13,
        "current_provisional_count": 8,
        "historical_already_fixed_count": 2,
        "historical_remediated_count": 3,
        "fleet_trip_playback_site_privacy_record_present": False,
        "bounded_disposition_tests_passed": 88,
        "bounded_disposition_assertions": 1764,
    }
    assert reporting["pending_new_record_id"] == FINDING_ID
    assert reporting["pending_record_status"] == RECORD_STATUS
    assert reporting["pending_candidate_feature_id"] == CANDIDATE_FEATURE_ID
    assert reporting["pending_candidate_association_status"] == (
        "PENDING_FRESH_SEMANTIC_REVIEW"
    )
    assert reporting["proposed_after_independent_exact_artifact_review"] == {
        "retained_claim_records": 14,
        "current_provisional_source_claims": 8,
        "historical_already_fixed_records": 2,
        "historical_remediated_records": 4,
        "bounded_disposition_tests_passed": 99,
        "bounded_disposition_assertions": 1931,
        "final_P0": 0,
        "final_P1": 0,
    }
    assert reporting["independent_review_authorized"] is False
    assert reporting["run_183_changes_live_reporting"] is False
    assert reporting["run_183r_required"] is True
    assert reporting["run_184_reporting_required_after_go"] is True
    assert reporting["run_185_fresh_dashboard_verification_required_after_reporting"] is True

    assert [key for key, value in producer["credit_boundary"].items() if value] == [
        "historical_condition_confirmed",
        "current_defect_reproduced",
        "application_remediation",
        "bounded_runtime",
        "bounded_selected_page_and_json_execution",
        "bounded_site_privacy_correctness",
        "application_commit_integrated_local_main",
    ]
    assert len(producer["completion_gates"]) == 26
    assert [row["gate"] for row in producer["completion_gates"]] == list(range(1, 27))
    assert len({row["name"] for row in producer["completion_gates"]}) == 26
    assert all(row["complete"] is False for row in producer["completion_gates"])
    assert len(producer["completion_boundary"]) == 26
    assert all(value is False for value in producer["completion_boundary"].values())
    assert producer["artifact_completion_test_met"] is True
    assert producer["audit_completion_test_met"] is False
    return producer


def validate_live_register_before_reporting() -> dict[str, Any]:
    findings = strict_json(FINDINGS_REL)
    records = findings["records"]
    record_ids = [record["id"] for record in records]
    statuses = Counter(record["record_status"] for record in records)
    assert len(records) == len(record_ids) == len(set(record_ids)) == 13
    assert FINDING_ID not in record_ids
    assert statuses == {
        "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING": 8,
        "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING": 2,
        "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING": 3,
    }
    counts = findings["counts"]
    assert counts["retained_claim_records"] == 13
    assert counts["provisional_source_claims"] == 8
    assert counts["historical_already_fixed"] == 2
    assert counts["historical_remediated"] == 3
    assert counts["bounded_disposition_tests_passed"] == 88
    assert counts["bounded_disposition_assertions"] == 1764
    assert counts["static_source_feature_ownership_records"] == 666
    assert counts["static_source_feature_ownership_route_records"] == 309
    assert counts["static_source_feature_ownership_page_records"] == 357
    assert counts["static_controller_action_bridges"] == 97
    assert counts["direct_exact_queue_reviewed"] == 120
    assert counts["direct_exact_queue_pending_unreviewed"] == 387
    assert counts["benchmark_mapped"] == 2
    assert counts["final_no_match"] == 0
    assert counts["benchmark_unresolved"] == 338
    record = audit_file_record(FINDINGS_REL)
    assert {
        key: record[key]
        for key in ("sha256", "git_blob_id", "bytes", "lines")
    } == EXPECTED_FINDINGS
    return record


def build_receipt(
    producer: dict[str, Any],
    repository: dict[str, Any],
    findings_record: dict[str, Any],
) -> dict[str, Any]:
    completion_gates = producer["completion_gates"]
    completion_boundary = producer["completion_boundary"]
    credit = {
        "independent_exact_artifact_review_for_new_historical_remediated_reporting": True,
        "application_remediation_reexecution": False,
        "runtime_reexecution": False,
        "application_publication": False,
        "live_reporting": False,
        "static_route_feature_ownership": False,
        "static_controller_action_bridge": False,
        "supporting_data_route_ownership": False,
        "queue_advance": False,
        "application_browser": False,
        "benchmark_mapping": False,
        "final_no_match_or_NCM": False,
        "final_finding": False,
        "pass": False,
        "release": False,
        "completion": False,
        "audit_complete": False,
    }
    receipt: dict[str, Any] = {
        "schema_version": (
            "run-183r-independent-fleet-trip-playback-site-privacy-"
            "remediation-review-wave-35-v1"
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
            "clean_advanced_main_commit": ADVANCED_MAIN,
            "clean_advanced_main_tree": ADVANCED_MAIN_TREE,
            "local_main_merge_commit": MERGE,
            "local_main_tree": MERGE_TREE,
            "origin_main_observed": ORIGIN_MAIN,
            "stable_patch_id": repository["stable_patch_id"],
            "application_published": False,
            "producer_generator": repository["producer"],
            "producer_receipt": {
                **repository["producer_receipt"],
                "receipt_self_seal_sha256": EXPECTED_RECEIPT[
                    "receipt_self_seal_sha256"
                ],
            },
            "review_materializer": audit_file_record(SCRIPT_REL),
            "current_findings_before_run_184": findings_record,
            "dirty_allowlist": repository["dirty_allowlist"],
        },
        "review": {
            "reviewer_lane": "/root/run183_materializer_impl",
            "independent_from_application_remediation_lane": True,
            "review_materializer_implemented_after_application_fix_release": True,
            "reviewer_executed_php_tests_browser_or_database": False,
            "reviewer_wrote_application_or_live_reporting_files": False,
            "checks": {
                "strict_json_zero_duplicate_keys": True,
                "lf_no_bom_no_trailing_whitespace": True,
                "pretty_json_round_trip": True,
                "canonical_self_seal": True,
                "base_fix_advanced_merge_tree_parent_subject_patch_id": True,
                "two_path_status_numstat_blob_sha_bytes_lines": True,
                "eight_path_advanced_main_disjoint_noninheritance": True,
                "origin_tracking_tip_local_divergence_and_nonpublication": True,
                "baseline_3_failed_2_passed_30_assertions": True,
                "runtime_arithmetic_and_single_post_merge_credit": True,
                "current_register_13_records_and_fleet_record_absent": True,
                "cleanup_and_ownership_release_boundary": True,
                "static_ownership_index_85_and_candidate_boundary": True,
                "credit_noninheritance_and_all_26_completion_gates_false": True,
                "four_path_dirty_allowlist": True,
            },
            "discrepancies": [],
        },
        "decision": {
            "verdict": "GO",
            "blocking_discrepancies": 0,
            "new_historical_remediated_record_reporting_authorized": True,
            "authorized_live_reporting_run": "RUN-184",
            "authorized_finding_id": FINDING_ID,
            "authorized_candidate_feature_id": CANDIDATE_FEATURE_ID,
            "authorized_candidate_association_status": "PENDING_FRESH_SEMANTIC_REVIEW",
            "authorized_reporting_status": RECORD_STATUS,
            "authorized_live_count_delta": {
                "retained_claim_records": 1,
                "current_provisional_source_claims": 0,
                "historical_already_fixed_records": 0,
                "historical_remediated_records": 1,
                "bounded_disposition_tests_passed": 11,
                "bounded_disposition_assertions": 167,
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
                "retained_claim_records": 14,
                "current_provisional_source_claims": 8,
                "historical_already_fixed_records": 2,
                "historical_remediated_records": 4,
                "final_P0": 0,
                "final_P1": 0,
            },
            "authorized_unique_bounded_disposition_increment": {
                "prior_tests": 88,
                "prior_assertions": 1764,
                "tests": 11,
                "assertions": 167,
                "resulting_tests": 99,
                "resulting_assertions": 1931,
                "post_merge_focused_counted_once": True,
                "baseline_red_counted": False,
                "isolated_replay_counted_again": False,
                "supporting_regressions_counted": False,
                "settings_or_queclink_execution_counted": False,
            },
            "static_ownership_remains_pending": {
                "ownership_status": "PENDING_FRESH_SEMANTIC_REVIEW",
                "next_zero_based_index": 85,
                "next_queue_id": QUEUE_ID,
                "next_route_record_id": ROUTE_RECORD_ID,
                "route_owner_authorized": False,
                "controller_action_bridge_authorized": False,
                "supporting_data_route_owner_authorized": False,
                "queue_advance_authorized": False,
            },
            "live_reporting_changed_by_run_183r": False,
            "run_184_required": True,
            "run_185_fresh_dashboard_verification_required": True,
        },
        "credit_boundary": credit,
        "completion_gates": completion_gates,
        "completion_boundary": completion_boundary,
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            f"{PREFIX}/{SCRIPT_REL}",
            f"{PREFIX}/{OUTPUT_REL}",
        ],
    }
    assert [key for key, value in credit.items() if value] == [
        "independent_exact_artifact_review_for_new_historical_remediated_reporting"
    ]
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
    assert receipt["decision"][
        "new_historical_remediated_record_reporting_authorized"
    ] is True
    assert receipt["decision"]["authorized_live_reporting_run"] == "RUN-184"
    assert receipt["decision"]["authorized_reporting_status"] == RECORD_STATUS
    assert receipt["decision"]["authorized_resulting_lineage"] == {
        "retained_claim_records": 14,
        "current_provisional_source_claims": 8,
        "historical_already_fixed_records": 2,
        "historical_remediated_records": 4,
        "final_P0": 0,
        "final_P1": 0,
    }
    assert receipt["decision"]["authorized_unique_bounded_disposition_increment"] == {
        "prior_tests": 88,
        "prior_assertions": 1764,
        "tests": 11,
        "assertions": 167,
        "resulting_tests": 99,
        "resulting_assertions": 1931,
        "post_merge_focused_counted_once": True,
        "baseline_red_counted": False,
        "isolated_replay_counted_again": False,
        "supporting_regressions_counted": False,
        "settings_or_queclink_execution_counted": False,
    }
    pending = receipt["decision"]["static_ownership_remains_pending"]
    assert pending["ownership_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
    assert pending["next_zero_based_index"] == 85
    assert pending["route_owner_authorized"] is False
    assert pending["controller_action_bridge_authorized"] is False
    assert pending["supporting_data_route_owner_authorized"] is False
    assert pending["queue_advance_authorized"] is False
    assert receipt["pins"]["application_published"] is False
    assert receipt["credit_boundary"]["application_publication"] is False
    assert receipt["credit_boundary"]["live_reporting"] is False
    assert receipt["credit_boundary"]["static_route_feature_ownership"] is False
    assert len(receipt["completion_gates"]) == 26
    assert all(row["complete"] is False for row in receipt["completion_gates"])
    assert all(value is False for value in receipt["completion_boundary"].values())
    assert receipt["artifact_completion_test_met"] is True
    assert receipt["audit_completion_test_met"] is False


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
                "materializer_sha256": audit_file_record(SCRIPT_REL)["sha256"],
                "receipt_sha256": sha256(encoded),
                "receipt_self_seal_sha256": receipt["receipt_self_seal_sha256"],
                "verdict": "GO",
                "run_184_historical_remediated_reporting_authorized": True,
                "authorized_result": (
                    "14 = 8 provisional + 2 already-fixed + 4 remediated"
                ),
                "authorized_unique_bounded_total": "99/1931",
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
