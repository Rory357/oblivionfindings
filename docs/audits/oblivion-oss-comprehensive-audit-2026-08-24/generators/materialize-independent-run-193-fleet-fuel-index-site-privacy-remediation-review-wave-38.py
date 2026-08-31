#!/usr/bin/env python3
"""Materialize the independent exact-artifact review of RUN193.

The review validates frozen RUN193 producer and receipt bytes and writes only
RUN193R. It does not rerun PHP or tests, touch a database, start a browser,
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
    "current-run-193r-independent-fleet-fuel-index-site-privacy-remediation-review-wave-38.json"
)
OUTPUT = AUDIT / OUTPUT_REL
PRODUCER_REL = (
    "generators/"
    "materialize-run-193-fleet-fuel-index-site-privacy-remediation-wave-38.py"
)
RECEIPT_REL = (
    "evidence/runtime/"
    "current-run-193-fleet-fuel-index-site-privacy-remediation-wave-38.json"
)

RUN_ID = (
    "RUN-193R-INDEPENDENT-FLEET-FUEL-INDEX-SITE-PRIVACY-01-"
    "REMEDIATION-REVIEW-WAVE-38"
)
STATUS = (
    "GO_EXACT_RUN193_ARTIFACT_REVIEW_NEW_HISTORICAL_REMEDIATED_REPORTING_"
    "AUTHORIZED_ZERO_STATIC_OWNERSHIP_PUBLICATION_FINAL_FINDING_OR_"
    "COMPLETION_CREDIT"
)

EXPECTED_PRODUCER = {
    "sha256": "105632bc2c4e50de3e8cfdd55fb25810fbbe5307537bd90b0e153b25f7c4e319",
    "git_blob_id": "bacd3d5003073bb58efe407388ea770429b63a95",
    "bytes": 37025,
    "lines": 884,
}
EXPECTED_RECEIPT = {
    "sha256": "1396205a5f63d4571b0e5b738f00f3a7cadc8ab93499a012e0e0f827b70b495f",
    "git_blob_id": "c7ef41d471847c1b6ad38f7c64c55fef302407a0",
    "bytes": 26715,
    "lines": 625,
    "receipt_self_seal_sha256": (
        "762bbfbba5fd76fb284ee36fb9854004c224512671acd4b144adaa24f41973c4"
    ),
}

BASE = "df65322f8eb7d7d0f1623c4bcb8cc8c87573b71d"
BASE_TREE = "0bd43711942416069675075ce3d515b92b9eaf7d"
FIX = "2ec4b70e379c6f8cf38c1cb67f5d676fea52cf75"
FIX_TREE = "b6e17efbf1b92b4a12bc01c55e8f245b2e206922"
AUDIT_RELEASE = "9019b44cb1017931fd0491a90f96ac32a6c4420c"
AUDIT_RELEASE_TREE = "81a4a14e31c88c9731f24a6addee85377ac54256"
MERGE = "04c32c36fdda6ce60ce281c06ad68aaa78527422"
MERGE_TREE = "6f85ddc1f4e8551c99528cc0c872b37da6c7763a"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"
PATCH_ID = "636771c0b1d9cbe50b2204febaa41679d340aba9"

FINDING_ID = "FLEET-FUEL-INDEX-SITE-PRIVACY-01"
CANDIDATE_FEATURE_ID = "CAP-FLEET-VEHICLE-REGISTER"
QUEUE_ID = "RUN090-ROUTE-0088"
ROUTE_RECORD_ID = "RUN077-ROUTE-0696"
ROUTE_NAME = "fleet-assets.fuel.index"
ACTION = "VehicleController::fuel"

CONTROLLER = "app/Http/Controllers/FleetAssets/VehicleController.php"
TEST = "tests/Feature/FleetAssets/FleetFuelIndexSitePrivacyTest.php"
ROUTE_FILE = "routes/fleet-assets.php"
FINDINGS_REPO = f"{PREFIX}/findings.json"
CHANGED_PATHS = {CONTROLLER: (25, 8), TEST: (516, 0)}
SUPPORTING_TESTS = [
    "tests/Feature/FleetAssets/FleetTripIndexSitePrivacyTest.php",
    "tests/Feature/Fleet/FleetManagementTest.php",
]
EXPECTED_ADVANCED_PATHS = [
    "app/Console/Commands/CheckRiskReviews.php",
    "app/Domain/Governance/Jobs/EscalateOverdueActionItems.php",
    "app/Domain/Governance/Jobs/SendComplianceReminder.php",
    "app/Domain/Governance/Jobs/SendVotingReminder.php",
    "app/Domain/Hr/Jobs/RunHrScheduledReportsJob.php",
    "app/Domain/Roadmap/Jobs/SendRoadmapDigestJob.php",
    "app/Jobs/ChecklistDueJob.php",
    "app/Jobs/HazardOverdueJob.php",
    "app/Jobs/InspectionDueJob.php",
    "app/Jobs/SendEventReminderJob.php",
    f"{PREFIX}/audit-dashboard.html",
    (
        f"{PREFIX}/evidence/browser/"
        "current-audit-dashboard-verification-run-192-wave-37.json"
    ),
    f"{PREFIX}/generators/build-current-audit-dashboard.py",
    (
        f"{PREFIX}/generators/"
        "materialize-run-192-audit-dashboard-verification-wave-37.py"
    ),
    "tests/Feature/Governance/ActionItemEscalationRecipientAuthorizationTest.php",
    "tests/Feature/Governance/ComplianceReminderQueuedRecipientAuthorizationTest.php",
    "tests/Feature/Governance/RiskReviewReminderAuthorizationTest.php",
    "tests/Feature/Governance/VotingReminderQueuedAuthorizationTest.php",
    "tests/Feature/Hr/HrScheduledReportRecipientRevalidationTest.php",
    "tests/Feature/Roadmap/RoadmapDigestRecipientAuthorizationTest.php",
    "tests/Feature/Sites/Calendar/SiteCalendarReminderJobTest.php",
    "tests/Feature/Sites/ChecklistDueJobRecipientPrivacyTest.php",
    "tests/Feature/Sites/HazardOverdueJobSitePrivacyTest.php",
    "tests/Feature/Sites/InspectionDueJobRecipientPrivacyTest.php",
]

EXPECTED_BASE_CONTROLLER = {
    "path": CONTROLLER,
    "sha256": "ba1ecc7e876c352a78122a3b292648d834ed40c8e09ab8893b9d9150e5614c49",
    "git_blob_id": "c6b75b8f33bb7774f74d27a4bc01bf07766c3212",
    "bytes": 54669,
    "lines": 1263,
}
EXPECTED_FIXED_CONTROLLER = {
    "path": CONTROLLER,
    "sha256": "c8547f3bb83e0d26a4c7ed7be7030ebf4e906f0d42a1d5bb11699399b8eb3f0f",
    "git_blob_id": "478f7c46fa39e2d0b515bd76b96ad52aa61881d8",
    "bytes": 55493,
    "lines": 1280,
    "insertions": 25,
    "deletions": 8,
}
EXPECTED_FIXED_TEST = {
    "path": TEST,
    "sha256": "1cf82ef3c86a53aaf91443c241fdc1c4c027aacb87e2004cc9e59f94121b11da",
    "git_blob_id": "54abf3786d28e38887ad95ce9a7ab3b01d868db3",
    "bytes": 20771,
    "lines": 516,
    "insertions": 516,
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
    "sha256": "91ccad95997c802f56c68a3cfc2678ae2364e7bad47c3f11ecaa55f4fc3e4843",
    "git_blob_id": "4b407f1137b121f6d5c0ad123bbd7a8fdb4223ce",
    "bytes": 643616,
    "lines": 11357,
}
EXPECTED_SUPPORTING_TESTS = [
    {
        "path": SUPPORTING_TESTS[0],
        "sha256": "932a35eaca38ec714874f05aa875388b67ccad3105b39b83c5df85510c27b9ce",
        "git_blob_id": "d58b141ac5e7c837a3bf27d5fa4494716849f60b",
        "bytes": 22167,
        "lines": 521,
    },
    {
        "path": SUPPORTING_TESTS[1],
        "sha256": "6fff4bcc546d3288d979fa641da36733d7a5d7c7a08518ac5d434faab057d0b0",
        "git_blob_id": "d5933409b4b25d175d4be2b5f61505a0ce7bf5d0",
        "bytes": 8271,
        "lines": 218,
    },
]
EXPECTED_COMPLETION_GATE_NAMES = [
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
        "merge: scope Fleet fuel index to approved Sites"
    )
    assert git("rev-parse", f"{FIX}^") == BASE
    assert git("rev-parse", f"{BASE}^{{tree}}") == BASE_TREE
    assert git("rev-parse", f"{FIX}^{{tree}}") == FIX_TREE
    assert git("rev-parse", f"{AUDIT_RELEASE}^{{tree}}") == AUDIT_RELEASE_TREE
    assert git("show", "-s", "--format=%s", FIX) == (
        "fix(fleet): scope fuel index to visible sites"
    )
    assert git("rev-parse", "origin/main") == ORIGIN_MAIN
    assert git("rev-list", "--left-right", "--count", "origin/main...main") == "0\t76"
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
        f"25\t8\t{CONTROLLER}",
        f"516\t0\t{TEST}",
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

    audit_advance = git("diff", "--name-only", BASE, AUDIT_RELEASE).splitlines()
    assert audit_advance == EXPECTED_ADVANCED_PATHS
    assert git("diff", "--name-only", BASE, AUDIT_RELEASE, "--", CONTROLLER, TEST) == ""

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
    assert git_file_record(FINDINGS_REPO, MERGE) == EXPECTED_FINDINGS
    assert [
        git_file_record(path, MERGE) for path in SUPPORTING_TESTS
    ] == EXPECTED_SUPPORTING_TESTS

    return {
        "stable_patch_id": patch,
        "audit_advance_path_count": len(audit_advance),
        "advanced_main_records": [
            git_file_record(path, AUDIT_RELEASE) for path in EXPECTED_ADVANCED_PATHS
        ],
        "dirty_allowlist": EXPECTED_DIRTY,
        "dirty_before_output": before_output,
    }


def validate_producer(repository: dict[str, Any]) -> dict[str, Any]:
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
    assert producer["schema_version"] == (
        "run-193-fleet-fuel-index-site-privacy-remediation-wave-38-v1"
    )
    assert producer["run_id"] == (
        "RUN-193-FLEET-FUEL-INDEX-SITE-PRIVACY-01-REMEDIATION-WAVE-38"
    )
    assert producer["status"] == (
        "CURRENT_FLEET_FUEL_INDEX_SITE_PRIVACY_DEFECT_REPRODUCED_REMEDIATED_"
        "LOCALLY_INTEGRATED_BOUNDED_VERIFIED_NOT_PUBLISHED_REPORTING_NOT_YET_"
        "AUTHORIZED_ZERO_STATIC_OWNERSHIP_FINAL_FINDING_OR_COMPLETION_CREDIT"
    )
    assert producer["materialized_on"] == "2026-09-01"
    assert producer["architecture_boundary"] == (
        "One operating organisation across multiple Sites; approved Site access, exact "
        "roles and permissions, canonical Asset provenance, direct-object denial, and "
        "privacy are the boundaries. Site is provenance, not a tenant boundary."
    )

    pins = producer["pins"]
    assert pins["governing_prompt_sha256"] == (
        "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
    )
    assert pins["continuation_prompt_sha256"] == (
        "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d"
    )
    assert pins["application_baseline_commit"] == BASE
    assert pins["application_baseline_tree"] == BASE_TREE
    assert pins["fix_commit"] == FIX
    assert pins["fix_tree"] == FIX_TREE
    assert pins["fix_parent"] == BASE
    assert pins["fix_commit_subject"] == (
        "fix(fleet): scope fuel index to visible sites"
    )
    assert pins["stable_patch_id"] == PATCH_ID
    assert pins["clean_audit_release_commit"] == AUDIT_RELEASE
    assert pins["clean_audit_release_tree"] == AUDIT_RELEASE_TREE
    assert pins["local_main_merge_commit"] == MERGE
    assert pins["local_main_tree"] == MERGE_TREE
    assert pins["merge_parents"] == [AUDIT_RELEASE, FIX]
    assert pins["merge_subject"] == "merge: scope Fleet fuel index to approved Sites"
    assert pins["audit_advance_from_baseline"] == {
        "path_count": 24,
        "clean_audit_release_equals_application_baseline": False,
        "transferred_paths_unchanged": True,
    }
    assert pins["origin_main_observed"] == ORIGIN_MAIN
    assert pins["local_main_ahead"] == 76 and pins["local_main_behind"] == 0
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
    assert pins["current_findings_before_run_193"] == EXPECTED_FINDINGS
    assert pins["supporting_regression_tests"] == EXPECTED_SUPPORTING_TESTS
    assert pins["advanced_main_disjoint_records"] == repository[
        "advanced_main_records"
    ]

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
    assert disposition["red_baseline"]["commit"] == BASE
    assert disposition["red_baseline"]["permanent_six_case_reproduction"] == {
        "cases": 6,
        "failed": 6,
        "assertions_reported": 65,
        "duration_seconds": 152.37,
        "exit_code": 1,
    }
    assert disposition["red_baseline"]["passing_denominator_credit"] == 0
    assert disposition["red_baseline"]["observations"] == [
        "foreign-Site fuel rows and nested logger identity were visible",
        "foreign fuel data influenced CSV, monthly hero, summary, and efficiency projections",
        "foreign and missing vehicle filters were not concealed consistently",
        "canonical direct, home, client, archived-Site, and current-user boundaries were not enforced consistently",
    ]

    remediation = producer["remediation"]
    assert remediation["summary"] == (
        "The fuel index now derives one visible operational-vehicle universe from "
        "the actor's current approved Sites and applies it consistently to rows, "
        "nested logger identity, CSV, filters, monthly hero metrics, summaries, and "
        "trip-derived efficiency projections."
    )
    assert remediation["production_files"] == 1
    assert remediation["regression_test_files"] == 1
    assert remediation["changed_paths"] == 2
    assert remediation["insertions"] == 541
    assert remediation["deletions"] == 8
    assert remediation["selected_get_route_and_csv_branch_only"] is True
    assert remediation["ordinary_viewer_approved_site_scope"] is True
    assert remediation["fleet_manage_operational_site_bypass_preserved"] is True
    assert remediation["archived_site_concealment"] is True
    assert remediation["foreign_or_missing_vehicle_filter_concealment_404"] is True
    assert remediation["nested_logger_identity_redaction"] is True
    assert remediation["canonical_direct_home_and_client_site_provenance"] is True
    assert remediation[
        "rows_filters_summary_efficiency_and_hero_use_consistent_visible_universe"
    ] is True
    assert remediation["route_declarations_changed"] is False
    assert remediation["pages_components_copy_or_layout_changed"] is False
    assert remediation["security_devices_or_user_site_access_service_changed"] is False
    assert remediation["third_party_source_assets_wording_or_layout_copied"] is False
    assert remediation["single_tenant_multi_site_boundary_preserved"] is True

    runtime = producer["delegated_runtime_execution"]
    assert runtime["execution_owners"] == {
        "red_isolated_and_post_merge": "separate Continue OSS audit fixes task"
    }
    assert runtime["run_193_producer_executed_tests"] is False
    assert runtime["root_post_merge_reran_tests_for_run_193"] is False
    assert runtime["baseline_original_red"] == {
        "cases": 6,
        "failed": 6,
        "passed": 0,
        "assertions_reported": 65,
        "duration_seconds": 152.37,
        "exit_code": 1,
        "credit": "REPRODUCTION_ONLY_ZERO_PASSING_DENOMINATOR_CREDIT",
    }
    assert runtime["isolated_green_focused"] == {
        "tests": 6,
        "assertions": 206,
        "duration_seconds": 147.44,
        "exit_code": 0,
        "added_to_bounded_disposition_denominator": False,
    }
    assert runtime["isolated_supporting_vehicle_controller_regressions"] == {
        "tests": 20,
        "assertions": 215,
        "duration_seconds": 156.15,
        "exit_code": 0,
        "reported_separately": True,
        "added_to_bounded_disposition_denominator": False,
        "scope": "FleetTripIndexSitePrivacyTest plus FleetManagementTest",
    }
    assert runtime["post_merge_authoritative_three_file_context"] == {
        "tests": 26,
        "assertions": 421,
        "duration_seconds": 196.34,
        "exit_code": 0,
        "focused_component": {
            "tests": 6,
            "assertions": 206,
            "unique_bounded_disposition_denominator_credit_after_run_193r_go": True,
        },
        "supporting_component": {
            "tests": 20,
            "assertions": 215,
            "added_to_bounded_disposition_denominator": False,
        },
    }
    assert runtime["focused_cases"] == [
        (
            "approved-Site viewer receives only visible fuel rows, nested logger identity, "
            "vehicle choices, monthly hero metrics, summary, and efficiency projections"
        ),
        "CSV export excludes foreign-Site rows, vehicle identity, logger identity, and notes",
        (
            "asset filters accept visible vehicles and conceal foreign or missing vehicles "
            "with 404"
        ),
        (
            "fuel visibility follows canonical direct, home, and client-Site provenance and "
            "fails closed for conflicts or unattributed vehicles"
        ),
        (
            "ended, inactive, missing-profile, and permission-revoked viewers receive no "
            "foreign data or are denied by the existing permission boundary"
        ),
        "fleet.manage sees operational Sites while archived-Site fuel remains concealed",
    ]
    assert runtime["focused_replay_aggregated_more_than_once"] is False
    assert runtime["unique_bounded_accounting"] == {
        "prior": {"tests": 155, "assertions": 2403},
        "increment_after_run_193r_go": {"tests": 6, "assertions": 206},
        "proposed_after_run_194_reporting": {"tests": 161, "assertions": 2609},
    }
    assert runtime["syntax"] == {
        "isolated_files_passed": 2,
        "post_merge_files_passed": 2,
        "result": "PASS",
    }
    assert runtime["pint"] == {"isolated": "PASS", "post_merge": "PASS"}
    assert runtime["git_diff_and_check"] == "PASS"
    assert runtime["full_suite_or_coverage_credit"] is False

    review = producer["independent_static_review"]
    assert review["reported_delegated_read_only_post_diff_reviews"] == 2
    assert review["total_read_only_reviews"] == 2
    assert review["verdict"] == "GO" and review["findings"] == 0
    assert review["reviewers_executed_tests"] is False
    assert review["reviewers_wrote_files"] is False
    assert review[
        "exact_merge_commit_tree_parents_and_two_path_delta_verified"
    ] is True
    assert review["bounded_site_privacy_contract_verified"] is True
    assert review["exact_run_193_receipt_review_completed"] is False
    assert review["new_record_reporting_authorized"] is False
    assert review["run_193r_still_required"] is True

    cleanup = producer["cleanup_evidence"]
    assert cleanup["isolated_worktree"] == "C:/w/fleet-fuel-index-site-privacy-01"
    assert cleanup["isolated_worktree_removed"] is True
    assert cleanup["isolated_branch"] == (
        "codex/fleet-fuel-index-site-privacy-01-20260831"
    )
    assert cleanup["isolated_branch_deleted"] is False
    assert cleanup["recovery_branch_retained"] is True
    assert cleanup["numeric_pid_test_schema_count"] == 0
    assert cleanup["audit_owned_php_or_php_cgi_process_count"] == 0
    assert cleanup["global_php_or_php_cgi_process_count_asserted"] is True
    assert cleanup["primary_main_clean_before_audit_writes"] is True

    ownership = producer["static_ownership_boundary"]
    assert ownership["owner_records"] == 667
    assert ownership["route_owners"] == 310
    assert ownership["page_owners"] == 357
    assert ownership["action_bridges"] == 98
    assert ownership["queue_total"] == 507
    assert ownership["queue_reviewed"] == 121
    assert ownership["queue_pending"] == 386
    assert ownership["queue_owned"] == 99
    assert ownership["queue_without_ownership"] == 408
    assert ownership["next_zero_based_index"] == 86
    assert ownership["next_queue_id"] == "RUN090-ROUTE-0087"
    assert ownership["next_route_record_id"] == "RUN077-ROUTE-0695"
    assert ownership["next_route_name"] == "fleet-assets.trips.playback.data"
    assert ownership["finding_candidate_zero_based_index"] == 87
    assert ownership["finding_candidate_queue_id"] == QUEUE_ID
    assert ownership["finding_candidate_route_record_id"] == ROUTE_RECORD_ID
    assert ownership["finding_candidate_route_name"] == ROUTE_NAME
    assert ownership["candidate_feature_id"] == CANDIDATE_FEATURE_ID
    assert ownership["ownership_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
    assert ownership["current_controller_sha256"] == (
        "c8547f3bb83e0d26a4c7ed7be7030ebf4e906f0d42a1d5bb11699399b8eb3f0f"
    )
    assert ownership["current_controller_definition_line"] == 824
    assert ownership["correctness_does_not_adjudicate_static_ownership"] is True
    assert ownership["fresh_outcome_neutral_ownership_review_required_later"] is True

    assert producer["benchmark_boundary"] == {
        "mapped": 2,
        "total": 340,
        "final_no_match_or_NCM": 0,
        "unresolved": 338,
        "changed_by_run_193": False,
    }
    assert set(producer["noninheritance_boundary"]) == {
        "isolated_green_replay_recredited",
        "supporting_vehicle_controller_regressions_recredited",
        "red_failures_or_assertions_recredited",
        "static_route_feature_ownership",
        "static_controller_action_bridge",
        "static_page_or_frontend_ownership",
        "queue_matrix_or_feature_union_change",
        "trip_playback_toggle_personal_fuel_store_or_adjacent_route_correctness",
        "security_devices_or_user_site_access_service_correctness",
        "broader_fleet_permission_privacy_or_direct_object_correctness",
        "application_browser_or_ease",
        "benchmark_mapping_or_final_no_match_NCM",
        "full_suite_coverage_feature_module_pass_or_release",
        "publication_final_finding_completion_or_audit_completion",
    }
    assert all(value is False for value in producer["noninheritance_boundary"].values())
    assert set(producer["credit_boundary"]) == {
        "historical_condition_confirmed",
        "current_defect_reproduced",
        "application_remediation",
        "bounded_runtime",
        "bounded_selected_get_and_csv_execution",
        "bounded_site_privacy_correctness",
        "application_commit_integrated_local_main",
        "application_commit_published",
        "new_historical_remediated_record_reporting",
        "static_route_feature_ownership",
        "static_controller_action_bridge",
        "framework_route_reachability_complete",
        "application_browser",
        "benchmark_mapping",
        "final_no_match_or_NCM",
        "ease",
        "full_feature_or_module",
        "release",
        "final_finding",
        "completion",
        "audit_complete",
    }
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

    reporting = producer["reporting_boundary"]
    assert reporting["current_findings_snapshot"] == {
        "retained_record_count": 15,
        "current_provisional_count": 8,
        "historical_already_fixed_count": 2,
        "historical_remediated_count": 5,
        "fleet_fuel_index_site_privacy_record_present": False,
    }
    assert reporting["current_retained_identity_count"] == 15
    assert reporting["current_split"] == (
        "8 provisional + 2 historical already-fixed + 5 historical remediated"
    )
    assert reporting["current_bounded_disposition"] == {
        "tests": 155,
        "assertions": 2403,
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
        "retained_claim_records": 16,
        "current_provisional_source_claims": 8,
        "historical_already_fixed_records": 2,
        "historical_remediated_records": 6,
        "bounded_disposition_tests": 161,
        "bounded_disposition_assertions": 2609,
        "final_P0": 0,
        "final_P1": 0,
    }
    assert reporting["independent_review_authorized"] is False
    assert reporting["run_193_changes_live_reporting"] is False
    assert reporting["run_193r_required"] is True
    assert reporting["run_194_reporting_required_after_go"] is True
    assert reporting["run_195_fresh_dashboard_verification_required_after_reporting"] is True

    assert producer["completion_gates"] == producer["completion_boundary"]
    assert list(producer["completion_boundary"]) == EXPECTED_COMPLETION_GATE_NAMES
    assert all(value is False for value in producer["completion_boundary"].values())
    assert producer["artifact_completion_test_met"] is True
    assert producer["audit_completion_test_met"] is False
    assert producer["wrote_files"] == [
        f"{PREFIX}/{PRODUCER_REL}",
        f"{PREFIX}/{RECEIPT_REL}",
    ]
    return producer


def validate_live_register_before_reporting() -> dict[str, Any]:
    findings = strict_json("findings.json")
    records = findings["records"]
    record_ids = [record["id"] for record in records]
    statuses = Counter(record["record_status"] for record in records)
    assert len(records) == len(record_ids) == len(set(record_ids)) == 15
    assert FINDING_ID not in record_ids
    assert statuses == {
        "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING": 8,
        "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING": 2,
        "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING": 5,
    }

    counts = findings["counts"]
    assert counts["retained_claim_records"] == 15
    assert counts["provisional_source_claims"] == 8
    assert counts["historical_already_fixed"] == 2
    assert counts["historical_remediated"] == 5
    assert counts["bounded_disposition_tests_passed"] == 155
    assert counts["bounded_disposition_assertions"] == 2403
    assert counts["final_P0"] == counts["final_P1"] == 0
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

    reconciliation = findings["reconciliation"]
    assert reconciliation["retained_record_ids_unique"] is True
    assert reconciliation["current_provisional_ids_unique"] is True
    assert reconciliation["retained_record_count"] == 15
    assert reconciliation["current_provisional_count"] == 8
    assert reconciliation["historical_already_fixed_count"] == 2
    assert reconciliation["historical_remediated_count"] == 5
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
    completion = dict(producer["completion_boundary"])
    assert producer["completion_gates"] == completion
    assert len(completion) == 26
    assert all(value is False for value in completion.values())
    receipt: dict[str, Any] = {
        "schema_version": (
            "run-193r-independent-fleet-fuel-index-site-privacy-remediation-"
            "review-wave-38-v1"
        ),
        "run_id": RUN_ID,
        "status": STATUS,
        "materialized_on": "2026-09-01",
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
            "merge_parents": [AUDIT_RELEASE, FIX],
            "origin_main_observed": ORIGIN_MAIN,
            "local_main_ahead": 76,
            "local_main_behind": 0,
            "stable_patch_id": repository["stable_patch_id"],
            "application_published": False,
            "producer_generator": {"path": PRODUCER_REL, **EXPECTED_PRODUCER},
            "producer_receipt": {"path": RECEIPT_REL, **EXPECTED_RECEIPT},
            "review_materializer": file_record(SCRIPT_REL),
            "current_findings_before_run_194": findings_record,
            "advanced_main_disjoint_path_count": repository[
                "audit_advance_path_count"
            ],
            "dirty_allowlist": repository["dirty_allowlist"],
        },
        "review": {
            "reviewer_lanes": [
                "/root/fleet_fuel_reporting_blueprint",
                "/root/run192_schema_review",
                "/root/iab_handoff_diagnosis",
            ],
            "independent_exact_artifact_reviewers": 3,
            "all_reviewers_read_only": True,
            "stale_template_existed_before_exact_review": True,
            "final_review_materializer_amended_after_no_go": True,
            "final_snapshot_reviewed_before_materialization": True,
            "review_materialized_by_root": True,
            "reviewers_executed_php_tests_browser_or_database": False,
            "reviewers_wrote_application_or_live_reporting_files": False,
            "checks": {
                "strict_json_zero_duplicate_keys": True,
                "lf_no_bom_no_trailing_whitespace": True,
                "pretty_json_round_trip": True,
                "canonical_self_seal": True,
                "commit_tree_parent_subject_patch_id": True,
                "two_path_order_status_numstat_blob_sha_bytes_lines": True,
                "advanced_main_24_path_noninheritance_and_transferred_byte_equality": True,
                "origin_tracking_tip_local_divergence_and_nonpublication": True,
                "red_runtime_replay_and_supporting_exclusions": True,
                "current_register_15_records_and_fleet_fuel_record_absent": True,
                "six_of_206_unique_increment_arithmetic": True,
                "static_ownership_index_86_and_candidate_index_87_boundary": True,
                "benchmark_credit_noninheritance": True,
                "all_26_completion_gates_false": True,
                "four_path_dirty_allowlist": True,
            },
            "discrepancies": [],
        },
        "decision": {
            "verdict": "GO",
            "blocking_discrepancies": 0,
            "new_historical_remediated_record_reporting_authorized": True,
            "authorized_live_reporting_run": "RUN-194",
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
                "bounded_disposition_tests_passed": 6,
                "bounded_disposition_assertions": 206,
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
                "retained_claim_records": 16,
                "current_provisional_source_claims": 8,
                "historical_already_fixed_records": 2,
                "historical_remediated_records": 6,
                "final_P0": 0,
                "final_P1": 0,
            },
            "authorized_unique_bounded_disposition_increment": {
                "prior_tests": 155,
                "prior_assertions": 2403,
                "tests": 6,
                "assertions": 206,
                "resulting_tests": 161,
                "resulting_assertions": 2609,
                "post_merge_focused_counted_once": True,
                "red_failures_or_assertions_counted": False,
                "isolated_replay_counted_again": False,
                "supporting_runs_counted": False,
            },
            "static_ownership_remains_pending": {
                "ownership_status": "PENDING_FRESH_SEMANTIC_REVIEW",
                "next_zero_based_index": 86,
                "next_queue_id": "RUN090-ROUTE-0087",
                "next_route_record_id": "RUN077-ROUTE-0695",
                "next_route_name": "fleet-assets.trips.playback.data",
                "finding_candidate_zero_based_index": 87,
                "finding_candidate_queue_id": QUEUE_ID,
                "finding_candidate_route_record_id": ROUTE_RECORD_ID,
                "finding_candidate_route_name": ROUTE_NAME,
                "route_owner_authorized": False,
                "controller_action_bridge_authorized": False,
                "queue_advance_authorized": False,
            },
            "live_reporting_changed_by_run_193r": False,
            "run_194_required": True,
            "run_195_fresh_dashboard_verification_required": True,
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
        "completion_gates": completion,
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
    assert receipt["decision"]["authorized_live_reporting_run"] == "RUN-194"
    assert receipt["decision"]["authorized_resulting_lineage"] == {
        "retained_claim_records": 16,
        "current_provisional_source_claims": 8,
        "historical_already_fixed_records": 2,
        "historical_remediated_records": 6,
        "final_P0": 0,
        "final_P1": 0,
    }
    assert receipt["decision"]["authorized_live_count_delta"][
        "bounded_disposition_tests_passed"
    ] == 6
    assert receipt["decision"]["authorized_live_count_delta"][
        "bounded_disposition_assertions"
    ] == 206
    assert receipt["decision"]["authorized_unique_bounded_disposition_increment"] == {
        "prior_tests": 155,
        "prior_assertions": 2403,
        "tests": 6,
        "assertions": 206,
        "resulting_tests": 161,
        "resulting_assertions": 2609,
        "post_merge_focused_counted_once": True,
        "red_failures_or_assertions_counted": False,
        "isolated_replay_counted_again": False,
        "supporting_runs_counted": False,
    }
    pending = receipt["decision"]["static_ownership_remains_pending"]
    assert pending["ownership_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
    assert pending["next_zero_based_index"] == 86
    assert pending["finding_candidate_zero_based_index"] == 87
    assert pending["route_owner_authorized"] is False
    assert pending["controller_action_bridge_authorized"] is False
    assert pending["queue_advance_authorized"] is False
    assert receipt["pins"]["advanced_main_disjoint_path_count"] == 24
    assert receipt["pins"]["application_published"] is False
    assert receipt["review"]["independent_exact_artifact_reviewers"] == 3
    assert receipt["review"]["discrepancies"] == []
    assert receipt["credit_boundary"]["application_publication"] is False
    assert receipt["credit_boundary"]["live_reporting"] is False
    assert receipt["credit_boundary"]["static_route_feature_ownership"] is False
    assert receipt["completion_gates"] == receipt["completion_boundary"]
    assert len(receipt["completion_boundary"]) == 26
    assert all(value is False for value in receipt["completion_boundary"].values())
    assert receipt["audit_completion_test_met"] is False


def main() -> None:
    repository = validate_repository()
    producer = validate_producer(repository)
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
                "run_194_new_historical_remediated_reporting_authorized": True,
                "authorized_result": "16 = 8 provisional + 2 already-fixed + 6 remediated",
                "authorized_unique_bounded_total": "161/2609",
                "static_ownership_adjudicated": False,
                "application_published": False,
                "audit_complete": False,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    if not __debug__:
        raise RuntimeError("RUN193R materialization requires assertions; do not use python -O")
    main()
