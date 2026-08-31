#!/usr/bin/env python3
"""Materialize the bounded RUN193 Fleet fuel-index Site-privacy receipt.

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
    "current-run-193-fleet-fuel-index-site-privacy-remediation-wave-38.json"
)
OUTPUT = AUDIT / OUTPUT_REL

RUN_ID = "RUN-193-FLEET-FUEL-INDEX-SITE-PRIVACY-01-REMEDIATION-WAVE-38"
STATUS = (
    "CURRENT_FLEET_FUEL_INDEX_SITE_PRIVACY_DEFECT_REPRODUCED_REMEDIATED_"
    "LOCALLY_INTEGRATED_BOUNDED_VERIFIED_NOT_PUBLISHED_REPORTING_NOT_YET_"
    "AUTHORIZED_ZERO_STATIC_OWNERSHIP_FINAL_FINDING_OR_COMPLETION_CREDIT"
)
GOVERNING_PROMPT_SHA256 = (
    "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
)
CONTINUATION_PROMPT_SHA256 = (
    "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d"
)

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
FEATURE_ID = "CAP-FLEET-VEHICLE-REGISTER"
QUEUE_ID = "RUN090-ROUTE-0088"
ROUTE_RECORD_ID = "RUN077-ROUTE-0696"
ROUTE_NAME = "fleet-assets.fuel.index"
ACTION = "VehicleController::fuel"

CONTROLLER = "app/Http/Controllers/FleetAssets/VehicleController.php"
TEST = "tests/Feature/FleetAssets/FleetFuelIndexSitePrivacyTest.php"
ROUTE_FILE = "routes/fleet-assets.php"
FINDINGS = f"{PREFIX}/findings.json"
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
EXPECTED_CURRENT_ROUTE_FILE = {
    "path": ROUTE_FILE,
    "sha256": "4be79ba4a0957f81f3e99de8eea7f29a398f8a115957bd44af06dbbf78fe2c4c",
    "git_blob_id": "f0b2b8c199ada1d8ef8bdb41c99bfc2ac02f93d2",
    "bytes": 28332,
    "lines": 351,
}
EXPECTED_CURRENT_FINDINGS = {
    "path": FINDINGS,
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


def strict_json_loads(text: str) -> Any:
    def reject_duplicates(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            if key in result:
                raise ValueError(f"duplicate JSON key: {key}")
            result[key] = value
        return result

    return json.loads(text, object_pairs_hook=reject_duplicates)


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
    findings = strict_json_loads(raw.decode("utf-8"))
    records = findings["records"]
    statuses = Counter(record["record_status"] for record in records)
    record_ids = [record["id"] for record in records]
    reconciliation = findings["reconciliation"]

    assert len(records) == 15
    assert len(record_ids) == len(set(record_ids))
    assert FINDING_ID not in record_ids
    assert statuses == {
        "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING": 8,
        "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING": 2,
        "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING": 5,
    }
    assert reconciliation["retained_record_count"] == 15
    assert reconciliation["current_provisional_count"] == 8
    assert reconciliation["historical_already_fixed_count"] == 2
    assert reconciliation["historical_remediated_count"] == 5
    assert reconciliation["retained_record_ids_unique"] is True
    assert reconciliation["current_provisional_ids_unique"] is True
    assert reconciliation["final_ids_cross_file_reconciled"] is False

    return {
        "retained_record_count": 15,
        "current_provisional_count": 8,
        "historical_already_fixed_count": 2,
        "historical_remediated_count": 5,
        "fleet_fuel_index_site_privacy_record_present": False,
    }


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
        f"25\t8\t{CONTROLLER}",
        f"516\t0\t{TEST}",
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
    assert audit_advance == EXPECTED_ADVANCED_PATHS
    assert git("diff", "--name-only", BASE, AUDIT_RELEASE, "--", CONTROLLER, TEST) == ""

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
    supporting_tests = [file_record(path, MERGE) for path in SUPPORTING_TESTS]
    advanced_main_records = [
        file_record(path, AUDIT_RELEASE) for path in EXPECTED_ADVANCED_PATHS
    ]
    assert baseline_controller == EXPECTED_BASE_CONTROLLER
    assert fixed_controller == EXPECTED_FIXED_CONTROLLER
    assert fixed_test == EXPECTED_FIXED_TEST
    assert merged_controller == EXPECTED_FIXED_CONTROLLER
    assert merged_test == EXPECTED_FIXED_TEST
    assert current_route_file == EXPECTED_CURRENT_ROUTE_FILE
    assert current_findings == EXPECTED_CURRENT_FINDINGS
    assert supporting_tests == EXPECTED_SUPPORTING_TESTS, (
        supporting_tests,
        EXPECTED_SUPPORTING_TESTS,
    )

    controller_text = git_bytes(MERGE, CONTROLLER).decode("utf-8")
    route_text = git_bytes(MERGE, ROUTE_FILE).decode("utf-8")
    assert controller_text.count("public function fuel(Request $request)") == 1
    assert (
        controller_text[: controller_text.index("public function fuel(Request $request)")]
        .count("\n")
        + 1
        == 824
    )
    route_statement = (
        "Route::get('/fuel', [VehicleController::class, 'fuel'])"
        "->name('fleet-assets.fuel.index');"
    )
    assert route_text.count(route_statement) == 1
    assert route_text[: route_text.index(route_statement)].count("\n") + 1 == 57

    return {
        "audit_advance_path_count": len(audit_advance),
        "clean_audit_release_equals_application_baseline": False,
        "stable_patch_id": patch,
        "baseline_controller": baseline_controller,
        "fixed_controller": fixed_controller,
        "fixed_test": fixed_test,
        "merged_controller": merged_controller,
        "merged_test": merged_test,
        "current_route_file": current_route_file,
        "current_findings": current_findings,
        "supporting_tests": supporting_tests,
        "advanced_main_records": advanced_main_records,
        "findings_snapshot": validate_findings_snapshot(),
    }


def build_receipt(repository: dict[str, Any]) -> dict[str, Any]:
    focused_cases = [
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
    completion = {name: False for name in COMPLETION_GATE_NAMES}
    noninheritance = {
        "isolated_green_replay_recredited": False,
        "supporting_vehicle_controller_regressions_recredited": False,
        "red_failures_or_assertions_recredited": False,
        "static_route_feature_ownership": False,
        "static_controller_action_bridge": False,
        "static_page_or_frontend_ownership": False,
        "queue_matrix_or_feature_union_change": False,
        "trip_playback_toggle_personal_fuel_store_or_adjacent_route_correctness": False,
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
            "run-193-fleet-fuel-index-site-privacy-remediation-wave-38-v1"
        ),
        "run_id": RUN_ID,
        "status": STATUS,
        "materialized_on": "2026-09-01",
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
            "fix_commit_subject": "fix(fleet): scope fuel index to visible sites",
            "stable_patch_id": repository["stable_patch_id"],
            "clean_audit_release_commit": AUDIT_RELEASE,
            "clean_audit_release_tree": AUDIT_RELEASE_TREE,
            "local_main_merge_commit": MERGE,
            "local_main_tree": MERGE_TREE,
            "merge_parents": [AUDIT_RELEASE, FIX],
            "merge_subject": "merge: scope Fleet fuel index to approved Sites",
            "audit_advance_from_baseline": {
                "path_count": repository["audit_advance_path_count"],
                "clean_audit_release_equals_application_baseline": repository[
                    "clean_audit_release_equals_application_baseline"
                ],
                "transferred_paths_unchanged": True,
            },
            "origin_main_observed": ORIGIN_MAIN,
            "local_main_ahead": 76,
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
            "current_findings_before_run_193": repository["current_findings"],
            "supporting_regression_tests": repository["supporting_tests"],
            "advanced_main_disjoint_records": repository["advanced_main_records"],
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
                "permanent_six_case_reproduction": {
                    "cases": 6,
                    "failed": 6,
                    "assertions_reported": 65,
                    "duration_seconds": 152.37,
                    "exit_code": 1,
                },
                "passing_denominator_credit": 0,
                "observations": [
                    "foreign-Site fuel rows and nested logger identity were visible",
                    "foreign fuel data influenced CSV, monthly hero, summary, and efficiency projections",
                    "foreign and missing vehicle filters were not concealed consistently",
                    "canonical direct, home, client, archived-Site, and current-user boundaries were not enforced consistently",
                ],
            },
        },
        "remediation": {
            "summary": (
                "The fuel index now derives one visible operational-vehicle universe from "
                "the actor's current approved Sites and applies it consistently to rows, "
                "nested logger identity, CSV, filters, monthly hero metrics, summaries, and "
                "trip-derived efficiency projections."
            ),
            "production_files": 1,
            "regression_test_files": 1,
            "changed_paths": 2,
            "insertions": 541,
            "deletions": 8,
            "selected_get_route_and_csv_branch_only": True,
            "ordinary_viewer_approved_site_scope": True,
            "fleet_manage_operational_site_bypass_preserved": True,
            "archived_site_concealment": True,
            "foreign_or_missing_vehicle_filter_concealment_404": True,
            "nested_logger_identity_redaction": True,
            "canonical_direct_home_and_client_site_provenance": True,
            "rows_filters_summary_efficiency_and_hero_use_consistent_visible_universe": True,
            "route_declarations_changed": False,
            "pages_components_copy_or_layout_changed": False,
            "security_devices_or_user_site_access_service_changed": False,
            "third_party_source_assets_wording_or_layout_copied": False,
            "single_tenant_multi_site_boundary_preserved": True,
        },
        "delegated_runtime_execution": {
            "execution_owners": {
                "red_isolated_and_post_merge": "separate Continue OSS audit fixes task",
            },
            "run_193_producer_executed_tests": False,
            "root_post_merge_reran_tests_for_run_193": False,
            "baseline_original_red": {
                "cases": 6,
                "failed": 6,
                "passed": 0,
                "assertions_reported": 65,
                "duration_seconds": 152.37,
                "exit_code": 1,
                "credit": "REPRODUCTION_ONLY_ZERO_PASSING_DENOMINATOR_CREDIT",
            },
            "isolated_green_focused": {
                "tests": 6,
                "assertions": 206,
                "duration_seconds": 147.44,
                "exit_code": 0,
                "added_to_bounded_disposition_denominator": False,
            },
            "isolated_supporting_vehicle_controller_regressions": {
                "tests": 20,
                "assertions": 215,
                "duration_seconds": 156.15,
                "exit_code": 0,
                "reported_separately": True,
                "added_to_bounded_disposition_denominator": False,
                "scope": "FleetTripIndexSitePrivacyTest plus FleetManagementTest",
            },
            "post_merge_authoritative_three_file_context": {
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
            },
            "focused_cases": focused_cases,
            "focused_replay_aggregated_more_than_once": False,
            "unique_bounded_accounting": {
                "prior": {"tests": 155, "assertions": 2403},
                "increment_after_run_193r_go": {"tests": 6, "assertions": 206},
                "proposed_after_run_194_reporting": {"tests": 161, "assertions": 2609},
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
            "reported_delegated_read_only_post_diff_reviews": 2,
            "total_read_only_reviews": 2,
            "verdict": "GO",
            "findings": 0,
            "reviewers_executed_tests": False,
            "reviewers_wrote_files": False,
            "exact_merge_commit_tree_parents_and_two_path_delta_verified": True,
            "bounded_site_privacy_contract_verified": True,
            "exact_run_193_receipt_review_completed": False,
            "new_record_reporting_authorized": False,
            "run_193r_still_required": True,
        },
        "cleanup_evidence": {
            "isolated_worktree": "C:/w/fleet-fuel-index-site-privacy-01",
            "isolated_worktree_removed": True,
            "isolated_branch": "codex/fleet-fuel-index-site-privacy-01-20260831",
            "isolated_branch_deleted": False,
            "recovery_branch_retained": True,
            "numeric_pid_test_schema_count": 0,
            "audit_owned_php_or_php_cgi_process_count": 0,
            "global_php_or_php_cgi_process_count_asserted": True,
            "primary_main_clean_before_audit_writes": True,
        },
        "static_ownership_boundary": {
            "owner_records": 667,
            "route_owners": 310,
            "page_owners": 357,
            "action_bridges": 98,
            "queue_total": 507,
            "queue_reviewed": 121,
            "queue_pending": 386,
            "queue_owned": 99,
            "queue_without_ownership": 408,
            "next_zero_based_index": 86,
            "next_queue_id": "RUN090-ROUTE-0087",
            "next_route_record_id": "RUN077-ROUTE-0695",
            "next_route_name": "fleet-assets.trips.playback.data",
            "finding_candidate_zero_based_index": 87,
            "finding_candidate_queue_id": QUEUE_ID,
            "finding_candidate_route_record_id": ROUTE_RECORD_ID,
            "finding_candidate_route_name": ROUTE_NAME,
            "candidate_feature_id": FEATURE_ID,
            "ownership_status": "PENDING_FRESH_SEMANTIC_REVIEW",
            "current_controller_sha256": (
                "c8547f3bb83e0d26a4c7ed7be7030ebf4e906f0d42a1d5bb11699399b8eb3f0f"
            ),
            "current_controller_definition_line": 824,
            "correctness_does_not_adjudicate_static_ownership": True,
            "fresh_outcome_neutral_ownership_review_required_later": True,
        },
        "benchmark_boundary": {
            "mapped": 2,
            "total": 340,
            "final_no_match_or_NCM": 0,
            "unresolved": 338,
            "changed_by_run_193": False,
        },
        "noninheritance_boundary": noninheritance,
        "reporting_boundary": {
            "current_findings_snapshot": repository["findings_snapshot"],
            "current_retained_identity_count": 15,
            "current_split": (
                "8 provisional + 2 historical already-fixed + 5 historical remediated"
            ),
            "current_bounded_disposition": {"tests": 155, "assertions": 2403},
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
                "retained_claim_records": 16,
                "current_provisional_source_claims": 8,
                "historical_already_fixed_records": 2,
                "historical_remediated_records": 6,
                "bounded_disposition_tests": 161,
                "bounded_disposition_assertions": 2609,
                "final_P0": 0,
                "final_P1": 0,
            },
            "independent_review_authorized": False,
            "run_193_changes_live_reporting": False,
            "run_193r_required": True,
            "run_194_reporting_required_after_go": True,
            "run_195_fresh_dashboard_verification_required_after_reporting": True,
        },
        "credit_boundary": credit,
        "completion_gates": completion,
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
    assert receipt["independent_static_review"]["total_read_only_reviews"] == 2
    assert receipt["issue_first_disposition"]["candidate_feature_id"] == FEATURE_ID
    assert receipt["issue_first_disposition"]["feature_identity_status"] == (
        "PENDING_FRESH_SEMANTIC_REVIEW"
    )
    assert receipt["delegated_runtime_execution"][
        "focused_replay_aggregated_more_than_once"
    ] is False
    assert receipt["delegated_runtime_execution"]["baseline_original_red"][
        "credit"
    ] == "REPRODUCTION_ONLY_ZERO_PASSING_DENOMINATOR_CREDIT"
    assert receipt["delegated_runtime_execution"]["isolated_green_focused"][
        "added_to_bounded_disposition_denominator"
    ] is False
    assert receipt["delegated_runtime_execution"][
        "post_merge_authoritative_three_file_context"
    ]["focused_component"][
        "unique_bounded_disposition_denominator_credit_after_run_193r_go"
    ] is True
    assert receipt["delegated_runtime_execution"]["unique_bounded_accounting"] == {
        "prior": {"tests": 155, "assertions": 2403},
        "increment_after_run_193r_go": {"tests": 6, "assertions": 206},
        "proposed_after_run_194_reporting": {"tests": 161, "assertions": 2609},
    }
    assert receipt["static_ownership_boundary"]["ownership_status"] == (
        "PENDING_FRESH_SEMANTIC_REVIEW"
    )
    assert receipt["reporting_boundary"]["independent_review_authorized"] is False
    assert receipt["reporting_boundary"][
        "proposed_after_independent_exact_artifact_review"
    ] == {
        "retained_claim_records": 16,
        "current_provisional_source_claims": 8,
        "historical_already_fixed_records": 2,
        "historical_remediated_records": 6,
        "bounded_disposition_tests": 161,
        "bounded_disposition_assertions": 2609,
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
    reloaded = strict_json_loads(OUTPUT.read_text(encoding="utf-8"))
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
                "focused_unique_credit_after_run_193r_go": "6/206",
                "proposed_reporting_total": "161/2609",
                "pending_reporting_lineage": "16 = 8 + 2 + 6",
                "new_record_reporting_authorized": False,
                "static_ownership_adjudicated": False,
                "audit_complete": False,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    if not __debug__:
        raise RuntimeError("RUN193 materialization requires assertions; do not use python -O")
    main()
