from __future__ import annotations

import hashlib
import json
import os
import subprocess
from pathlib import Path
from typing import Any


assert __debug__, "RUN194 materialization refuses optimized Python"

AUDIT_DIR = Path(__file__).resolve().parents[1]
REPO_ROOT = AUDIT_DIR.parents[2]
AUDIT_PREFIX = "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/"

RUN_ID = "RUN-194-FLEET-FUEL-INDEX-SITE-PRIVACY-01-REMEDIATION-REPORTING-WAVE-38"
STATUS = (
    "FLEET_FUEL_INDEX_SITE_PRIVACY_HISTORICAL_REMEDIATION_REPORTING_MATERIALIZED_"
    "DASHBOARD_RUN195_REQUIRED_ZERO_STATIC_PUBLICATION_FINAL_FINDING_OR_COMPLETION_CREDIT"
)
HEAD = "39a5d97d7d0ff9ea03070e90193581479f423022"
HEAD_TREE = "90b9adba1261fb1ec30d9fe4b13daaf5149fc1dc"
HEAD_PARENT = "04c32c36fdda6ce60ce281c06ad68aaa78527422"
HEAD_SUBJECT = "audit: record RUN193 Fleet fuel privacy remediation"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"

BASELINE_FINDINGS_SHA256 = "91ccad95997c802f56c68a3cfc2678ae2364e7bad47c3f11ecaa55f4fc3e4843"
BASELINE_RECORD_LIST_SHA256 = "c323094ec2ec143ed1a037f6b3c96f4e796cf2dd7bf9bf407d6a049e2c98ec33"
FUEL_RECORD_SHA256 = "33246189216812faae29a004f6de1f4f80f9839d2c0ce2126e0ba2577afe8de4"
FROZEN_DASHBOARD_SHA256 = "8d19569e7bfb256edeecdc754e2bc47e2ddad3ecd8de099e3bb0dad9b50e313b"

SCRIPT_REL = "generators/materialize-run-194-fleet-fuel-index-site-privacy-remediation-reporting-wave-38.py"
OUTPUT_REL = "evidence/source/current-run-194-fleet-fuel-index-site-privacy-remediation-reporting-wave-38.json"
FINDINGS_REL = "findings.json"
BUILDER_REL = "generators/build-current-audit-dashboard.py"
DASHBOARD_REL = "audit-dashboard.html"

HUMAN_SURFACES = [
    "00-executive-summary.md",
    "01-repository-module-map.md",
    "07-module-findings.md",
    "11-prioritised-roadmap.md",
    "12-native-build-and-do-not-copy-register.md",
    "13-unresolved-questions-and-evidence-gaps.md",
]
REPORTING_SURFACES = [*HUMAN_SURFACES, FINDINGS_REL, BUILDER_REL]
EXACT_DIRTY_ALLOWLIST = {*REPORTING_SURFACES, SCRIPT_REL, OUTPUT_REL}

RUN_192_GENERATOR_REL = "generators/materialize-run-192-audit-dashboard-verification-wave-37.py"
RUN_192_REL = "evidence/browser/current-audit-dashboard-verification-run-192-wave-37.json"
RUN_193_GENERATOR_REL = "generators/materialize-run-193-fleet-fuel-index-site-privacy-remediation-wave-38.py"
RUN_193_REL = "evidence/runtime/current-run-193-fleet-fuel-index-site-privacy-remediation-wave-38.json"
RUN_193R_GENERATOR_REL = "generators/materialize-independent-run-193-fleet-fuel-index-site-privacy-remediation-review-wave-38.py"
RUN_193R_REL = "evidence/runtime/current-run-193r-independent-fleet-fuel-index-site-privacy-remediation-review-wave-38.json"

EXPECTED_HASHES = {
    RUN_192_GENERATOR_REL: "899eb63bf6416801187334c12af8c781029c457e0f7e2cbbaf75604991fbe14f",
    RUN_192_REL: "cc95b38ece501bea317e78ef7769a7813e3a5a6c2041330e249fd196974cbb88",
    RUN_193_GENERATOR_REL: "105632bc2c4e50de3e8cfdd55fb25810fbbe5307537bd90b0e153b25f7c4e319",
    RUN_193_REL: "1396205a5f63d4571b0e5b738f00f3a7cadc8ab93499a012e0e0f827b70b495f",
    RUN_193R_GENERATOR_REL: "953d8d68aa2a869fe5c82f495de60b351c49b53f846955167f7f1434964541ac",
    RUN_193R_REL: "87a1157f26bbfaf062ec22bceb616bf4f54c72f908cddfd68c2b59db91cbbb41",
}

EXPECTED_COMPLETION_GATE_NAMES = (
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
)


def git(*args: str, text: bool = True) -> str | bytes:
    completed = subprocess.run(
        ["git", *args],
        cwd=REPO_ROOT,
        check=True,
        capture_output=True,
        text=text,
    )
    return completed.stdout


def duplicate_key_guard(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise AssertionError(f"Duplicate JSON key: {key}")
        result[key] = value
    return result


def assert_text_bytes(payload: bytes, label: str) -> None:
    assert not payload.startswith(b"\xef\xbb\xbf"), f"UTF-8 BOM not allowed: {label}"
    assert b"\r" not in payload, f"CR byte not allowed: {label}"
    assert payload.endswith(b"\n"), f"Final LF required: {label}"
    for index, line in enumerate(payload.splitlines(), start=1):
        assert line.rstrip(b" \t") == line, f"Trailing whitespace: {label}:{index}"


def parse_json_strict(payload: bytes, label: str, *, require_pretty: bool = True) -> dict[str, Any]:
    assert_text_bytes(payload, label)
    parsed = json.loads(payload.decode("utf-8"), object_pairs_hook=duplicate_key_guard)
    assert isinstance(parsed, dict), f"Top-level JSON object required: {label}"
    if require_pretty:
        expected = (json.dumps(parsed, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
        assert payload == expected, f"Exact pretty JSON round trip failed: {label}"
    return parsed


def read_json_strict(relative: str) -> dict[str, Any]:
    return parse_json_strict((AUDIT_DIR / relative).read_bytes(), relative)


def read_text_strict(relative: str) -> str:
    payload = (AUDIT_DIR / relative).read_bytes()
    assert_text_bytes(payload, relative)
    return payload.decode("utf-8")


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def sha256_file(relative: str) -> str:
    return sha256_bytes((AUDIT_DIR / relative).read_bytes())


def canonical_sha256(value: Any) -> str:
    payload = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return sha256_bytes(payload)


def git_blob_id(payload: bytes) -> str:
    header = f"blob {len(payload)}\0".encode("ascii")
    return hashlib.sha1(header + payload).hexdigest()


def file_record(relative: str) -> dict[str, Any]:
    payload = (AUDIT_DIR / relative).read_bytes()
    return {
        "path": relative,
        "sha256": sha256_bytes(payload),
        "git_blob_id": git_blob_id(payload),
        "bytes": len(payload),
        "lines": payload.count(b"\n"),
    }


def git_file_at_head(relative: str) -> bytes:
    return git("show", f"{HEAD}:{AUDIT_PREFIX}{relative}", text=False)  # type: ignore[return-value]


def status_paths() -> tuple[set[str], list[str]]:
    lines = str(git("status", "--porcelain=v1", "--untracked-files=all")).splitlines()
    paths: set[str] = set()
    statuses: list[str] = []
    for line in lines:
        assert len(line) >= 4, line
        status = line[:2]
        raw_path = line[3:]
        if " -> " in raw_path:
            raw_path = raw_path.split(" -> ", 1)[1]
        raw_path = raw_path.strip('"').replace("\\", "/")
        assert raw_path.startswith(AUDIT_PREFIX), f"Unexpected dirty path outside audit: {raw_path}"
        relative = raw_path[len(AUDIT_PREFIX):]
        paths.add(relative)
        statuses.append(f"{status} {relative}")
    return paths, sorted(statuses)


def assert_self_seal(receipt: dict[str, Any], expected: str) -> None:
    payload = dict(receipt)
    observed = payload.pop("receipt_self_seal_sha256")
    assert observed == expected
    assert canonical_sha256(payload) == expected


def completion_gates() -> dict[str, bool]:
    return {name: False for name in EXPECTED_COMPLETION_GATE_NAMES}


def main() -> None:
    head_lines = str(git("show", "-s", "--format=%H%n%T%n%P%n%s", "HEAD")).splitlines()
    assert head_lines == [HEAD, HEAD_TREE, HEAD_PARENT, HEAD_SUBJECT]
    assert str(git("rev-parse", "origin/main")).strip() == ORIGIN_MAIN
    assert str(git("rev-list", "--left-right", "--count", "origin/main...HEAD")).strip() == "0\t77"
    assert str(git("diff", "--cached", "--name-only")).strip() == ""
    assert str(git("diff", "--check")).strip() == ""

    expected_head_delta = {
        "A\tdocs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/runtime/current-run-193-fleet-fuel-index-site-privacy-remediation-wave-38.json",
        "A\tdocs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/runtime/current-run-193r-independent-fleet-fuel-index-site-privacy-remediation-review-wave-38.json",
        "A\tdocs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-independent-run-193-fleet-fuel-index-site-privacy-remediation-review-wave-38.py",
        "A\tdocs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-run-193-fleet-fuel-index-site-privacy-remediation-wave-38.py",
    }
    observed_head_delta = set(str(git("diff-tree", "--no-commit-id", "--name-status", "-r", "HEAD")).splitlines())
    assert observed_head_delta == expected_head_delta

    dirty_before, status_before = status_paths()
    expected_before = EXACT_DIRTY_ALLOWLIST if (AUDIT_DIR / OUTPUT_REL).exists() else EXACT_DIRTY_ALLOWLIST - {OUTPUT_REL}
    assert dirty_before == expected_before, (sorted(dirty_before), sorted(expected_before))
    assert all(status.startswith((" M ", "?? ")) for status in status_before)

    dashboard_before = sha256_file(DASHBOARD_REL)
    assert dashboard_before == FROZEN_DASHBOARD_SHA256
    assert file_record(DASHBOARD_REL) == {
        "path": DASHBOARD_REL,
        "sha256": FROZEN_DASHBOARD_SHA256,
        "git_blob_id": "fb0ba424878117bf1362aea77c892a00fda32b95",
        "bytes": 317284,
        "lines": 78,
    }

    for relative in [*HUMAN_SURFACES, FINDINGS_REL, BUILDER_REL, SCRIPT_REL]:
        read_text_strict(relative)

    baseline_payload = git_file_at_head(FINDINGS_REL)
    assert sha256_bytes(baseline_payload) == BASELINE_FINDINGS_SHA256
    baseline = parse_json_strict(baseline_payload, f"{HEAD}:{FINDINGS_REL}")
    findings = read_json_strict(FINDINGS_REL)
    assert len(baseline["records"]) == 15
    assert canonical_sha256(baseline["records"]) == BASELINE_RECORD_LIST_SHA256
    assert findings["records"][:15] == baseline["records"]
    assert canonical_sha256(findings["records"][:15]) == BASELINE_RECORD_LIST_SHA256
    assert len(findings["records"]) == 16
    fuel = findings["records"][-1]
    assert canonical_sha256(fuel) == FUEL_RECORD_SHA256

    mutable_top_level = {
        "audit_status",
        "generated_on",
        "pins",
        "denominators",
        "counts",
        "credit_boundary",
        "reconciliation",
        "records",
        "current_audit_artifact_verification_history",
    }
    for key, value in baseline.items():
        if key not in mutable_top_level:
            assert findings[key] == value, f"Unexpected protected findings subtree change: {key}"
    for key, value in baseline["pins"].items():
        assert findings["pins"][key] == value, f"Existing findings pin changed: {key}"

    assert findings["audit_status"] == "EIGHT_PROVISIONAL_TWO_HISTORICAL_ALREADY_FIXED_SIX_HISTORICAL_REMEDIATED_ZERO_FINAL_FINDING_CREDIT"
    assert findings["generated_on"] == "2026-09-01"
    assert findings["denominators"]["current_retained_claim_records"] == 16
    assert findings["denominators"]["current_provisional_source_claims"] == 8
    assert findings["denominators"]["historical_already_fixed_records"] == 2
    assert findings["denominators"]["historical_remediated_records"] == 6

    counts = findings["counts"]
    assert counts["retained_claim_records"] == 16
    assert counts["provisional_source_claims"] == 8
    assert counts["historical_already_fixed"] == 2
    assert counts["historical_remediated"] == 6
    assert counts["bounded_disposition_tests_passed"] == 161
    assert counts["bounded_disposition_assertions"] == 2609
    assert counts["fleet_fuel_index_site_privacy_focused_tests"] == 6
    assert counts["fleet_fuel_index_site_privacy_focused_assertions"] == 206
    assert counts["fleet_fuel_index_site_privacy_supporting_tests"] == 20
    assert counts["fleet_fuel_index_site_privacy_supporting_assertions"] == 215
    assert counts["fleet_fuel_index_site_privacy_baseline_failed"] == 6
    assert counts["fleet_fuel_index_site_privacy_baseline_passed"] == 0
    assert counts["fleet_fuel_index_site_privacy_baseline_assertions"] == 65
    assert "unique 6/206 post-merge focused component" in counts["bounded_disposition_sum_basis"]
    assert "Fuel red 6-failed/65-assertion reproduction" in counts["bounded_disposition_sum_basis"]
    assert "isolated Fuel 6/206 replay" in counts["bounded_disposition_sum_basis"]
    assert "supporting Fuel 20/215 regressions" in counts["bounded_disposition_sum_basis"]
    assert "any second count from combined 26/421" in counts["bounded_disposition_sum_basis"]
    assert counts["final_P0"] == counts["final_P1"] == 0

    static_counts = {
        "owners": counts["static_source_feature_ownership_records"],
        "routes": counts["static_source_feature_ownership_route_records"],
        "pages": counts["static_source_feature_ownership_page_records"],
        "controller_action_bridges": counts["static_controller_action_bridges"],
    }
    assert static_counts == {"owners": 667, "routes": 310, "pages": 357, "controller_action_bridges": 98}
    assert counts["direct_exact_queue_records"] == 507
    assert counts["direct_exact_queue_reviewed"] == 121
    assert counts["direct_exact_queue_pending_unreviewed"] == 386
    assert counts["direct_exact_queue_owned"] == 99
    assert counts["direct_exact_queue_without_ownership"] == 408
    assert findings["current_static_source_feature_ownership"]["queue_boundary"]["next_unresolved_index"] == 86
    assert findings["current_static_source_feature_ownership"] == baseline["current_static_source_feature_ownership"]
    assert findings["current_benchmark_mapping"] == baseline["current_benchmark_mapping"]
    assert counts["benchmark_mapped"] == 2
    assert findings["denominators"]["canonical_features"] == 340
    assert counts["final_no_match"] == 0
    assert counts["benchmark_unresolved"] == 338

    reconciliation = findings["reconciliation"]
    assert reconciliation["retained_record_count"] == 16
    assert reconciliation["current_provisional_count"] == 8
    assert reconciliation["historical_already_fixed_count"] == 2
    assert reconciliation["historical_remediated_count"] == 6
    assert reconciliation["every_non_null_primary_feature_id_in_canonical_matrix"] is True
    assert reconciliation["records_without_primary_or_candidate_feature_id"] == ["MON-METRIC-REPLAY-DEDUPE-01"]

    assert fuel["id"] == "FLEET-FUEL-INDEX-SITE-PRIVACY-01"
    assert fuel["record_status"] == "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
    assert fuel["feature_id"] == fuel["candidate_feature_id"] == "CAP-FLEET-VEHICLE-REGISTER"
    assert fuel["feature_identity_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
    assert fuel["feature_id_role"] == "CANDIDATE_REPORTING_ASSOCIATION_ONLY_ZERO_STATIC_OWNERSHIP_CREDIT"
    assert fuel["route_url"]["queue_id"] == "RUN090-ROUTE-0088"
    assert fuel["route_url"]["route_record_id"] == "RUN077-ROUTE-0696"
    assert fuel["route_url"]["route_names"] == "fleet-assets.fuel.index"
    assert fuel["route_url"]["controller_action"] == "VehicleController::fuel"
    assert fuel["route_url"]["ownership_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
    assert fuel["current_adjudication"]["application_commit"] == HEAD_PARENT
    assert fuel["current_adjudication"]["repository_tree"] == "6f85ddc1f4e8551c99528cc0c872b37da6c7763a"
    assert fuel["current_adjudication"]["published_to_origin_main"] is False
    assert fuel["current_adjudication"]["independent_visible_row_logger_site_redaction_inherited"] is False
    assert fuel["evidence"]["tests_executed"] == 6
    assert fuel["evidence"]["assertions"] == 206
    assert fuel["evidence"]["supporting_tests"] == 20
    assert fuel["evidence"]["supporting_assertions"] == 215
    assert fuel["evidence"]["baseline_failed_cases"] == 6
    assert fuel["evidence"]["baseline_passed_cases"] == 0
    assert fuel["evidence"]["baseline_assertions"] == 65
    assert "rolling 30-day entry count" in fuel["current_behaviour"]["summary"]
    assert "month-to-date fuel totals" in fuel["current_behaviour"]["summary"]
    assert fuel["completion_credit"] is False
    assert all(value is False for value in fuel["credit"].values())
    assert all(row["completion_credit"] is False for row in findings["records"])
    assert all(all(value is False for value in row["credit"].values()) for row in findings["records"])

    history = findings["current_audit_artifact_verification_history"]["run_192"]
    assert history == {
        "run_id": "RUN-192-AUDIT-DASHBOARD-VERIFICATION-WAVE-37",
        "receipt_sha256": EXPECTED_HASHES[RUN_192_REL],
        "receipt_self_seal_sha256": "a112198be2915cfc8a88b31b38a9cb33c90ad407b6da83f85fac5deae6727995",
        "dashboard_sha256": FROZEN_DASHBOARD_SHA256,
        "status": "VERIFIED_EXACT_ARTIFACT_ONLY",
        "viewports": "4/4",
        "visible_boundary_checks": "30/30 per viewport",
        "navigation": "10/10",
        "unique_local_resources": "476/476",
        "anchor_elements": "893/893",
        "application_browser_credit": False,
        "publication_credit": False,
        "audit_complete": False,
        "superseded_by_run_194_reporting_sources": True,
        "run_195_dashboard_verification_required": True,
    }

    assert all(sha256_file(path) == digest for path, digest in EXPECTED_HASHES.items())
    run_192 = read_json_strict(RUN_192_REL)
    run_193 = read_json_strict(RUN_193_REL)
    run_193r = read_json_strict(RUN_193R_REL)
    assert_self_seal(run_192, "a112198be2915cfc8a88b31b38a9cb33c90ad407b6da83f85fac5deae6727995")
    assert_self_seal(run_193, "762bbfbba5fd76fb284ee36fb9854004c224512671acd4b144adaa24f41973c4")
    assert_self_seal(run_193r, "df7f90f33692f0ff81a143bb3406238d8a2831caad47b295b7a1b784863a06e2")

    assert run_192["run_id"] == "RUN-192-AUDIT-DASHBOARD-VERIFICATION-WAVE-37"
    assert run_192["status"] == "VERIFIED_EXACT_ARTIFACT_ONLY"
    assert run_192["pins"]["final_run_192_dashboard"]["sha256"] == FROZEN_DASHBOARD_SHA256
    assert run_192["browser_verification"]["viewports_verified"] == 4
    assert run_192["browser_verification"]["expected_viewports"] == 4
    assert all(
        viewport["visible_text_passed"] == viewport["visible_text_total"] == 30
        for viewport in run_192["browser_verification"]["viewports"].values()
    )
    assert run_192["browser_verification"]["navigation_passed"] == 10
    assert run_192["browser_verification"]["navigation_total"] == 10
    assert run_192["browser_verification"]["browser_warning_or_error_count"] == 0
    assert run_192["browser_verification"]["browser_finalization_complete"] is True
    assert run_192["browser_verification"]["live_application_browser"] is False
    assert run_192["html_and_resource_graph"]["unique_local_resources"] == 476
    assert run_192["html_and_resource_graph"]["existing_unique_local_resources"] == 476
    assert run_192["html_and_resource_graph"]["anchor_element_count"] == 893
    assert run_192["html_and_resource_graph"]["hash_mismatches"] == []
    assert run_192["final_http_head_verification"]["verified_count"] == 476
    assert run_192["final_http_head_verification"]["failure_count"] == 0
    assert run_192["final_http_head_verification"]["complete"] is True
    assert run_192["root_browser_resource_cleanup"]["listeners_after_cleanup"] == 0
    assert run_192["root_browser_resource_cleanup"]["exact_pid_present_after_cleanup"] is False
    assert run_192["root_browser_resource_cleanup"]["matching_loopback_server_processes_after_cleanup"] == 0
    assert run_192["root_browser_resource_cleanup"]["cleanup_finalized"] is True
    assert [row["gate"] for row in run_192["completion_gates"]] == list(range(1, 27))
    assert not any(row["complete"] for row in run_192["completion_gates"])
    assert {key for key, value in run_192["credit_boundary"].items() if value} == {"exact_audit_dashboard_artifact"}

    assert run_193["run_id"] == "RUN-193-FLEET-FUEL-INDEX-SITE-PRIVACY-01-REMEDIATION-WAVE-38"
    assert run_193["pins"]["application_baseline_commit"] == "df65322f8eb7d7d0f1623c4bcb8cc8c87573b71d"
    assert run_193["pins"]["fix_commit"] == "2ec4b70e379c6f8cf38c1cb67f5d676fea52cf75"
    assert run_193["pins"]["local_main_merge_commit"] == HEAD_PARENT
    assert run_193["pins"]["stable_patch_id"] == "636771c0b1d9cbe50b2204febaa41679d340aba9"
    assert run_193["delegated_runtime_execution"]["baseline_original_red"]["failed"] == 6
    assert run_193["delegated_runtime_execution"]["baseline_original_red"]["passed"] == 0
    assert run_193["delegated_runtime_execution"]["baseline_original_red"]["assertions_reported"] == 65
    assert run_193["delegated_runtime_execution"]["isolated_green_focused"]["tests"] == 6
    assert run_193["delegated_runtime_execution"]["isolated_green_focused"]["assertions"] == 206
    assert run_193["delegated_runtime_execution"]["isolated_green_focused"]["added_to_bounded_disposition_denominator"] is False
    assert run_193["delegated_runtime_execution"]["isolated_supporting_vehicle_controller_regressions"]["tests"] == 20
    assert run_193["delegated_runtime_execution"]["isolated_supporting_vehicle_controller_regressions"]["assertions"] == 215
    assert run_193["delegated_runtime_execution"]["isolated_supporting_vehicle_controller_regressions"]["added_to_bounded_disposition_denominator"] is False
    post_merge = run_193["delegated_runtime_execution"]["post_merge_authoritative_three_file_context"]
    assert post_merge["tests"] == 26 and post_merge["assertions"] == 421
    assert post_merge["focused_component"]["tests"] == 6
    assert post_merge["focused_component"]["assertions"] == 206
    assert post_merge["focused_component"]["unique_bounded_disposition_denominator_credit_after_run_193r_go"] is True
    assert run_193["delegated_runtime_execution"]["unique_bounded_accounting"] == {
        "prior": {"tests": 155, "assertions": 2403},
        "increment_after_run_193r_go": {"tests": 6, "assertions": 206},
        "proposed_after_run_194_reporting": {"tests": 161, "assertions": 2609},
    }
    assert len(run_193["pins"]["advanced_main_disjoint_records"]) == 24
    assert all(value is False for value in run_193["noninheritance_boundary"].values())
    assert len(run_193["completion_gates"]) == 26
    assert set(run_193["completion_gates"]) == set(EXPECTED_COMPLETION_GATE_NAMES)
    assert not any(run_193["completion_gates"].values())

    assert run_193r["run_id"] == "RUN-193R-INDEPENDENT-FLEET-FUEL-INDEX-SITE-PRIVACY-01-REMEDIATION-REVIEW-WAVE-38"
    assert run_193r["review"]["independent_exact_artifact_reviewers"] == 3
    assert run_193r["review"]["all_reviewers_read_only"] is True
    assert all(run_193r["review"]["checks"].values())
    assert run_193r["review"]["discrepancies"] == []
    assert run_193r["decision"]["verdict"] == "GO"
    assert run_193r["decision"]["blocking_discrepancies"] == 0
    assert run_193r["decision"]["new_historical_remediated_record_reporting_authorized"] is True
    assert run_193r["decision"]["authorized_live_reporting_run"] == "RUN-194"
    assert run_193r["decision"]["authorized_finding_id"] == "FLEET-FUEL-INDEX-SITE-PRIVACY-01"
    assert run_193r["decision"]["authorized_resulting_lineage"] == {
        "retained_claim_records": 16,
        "current_provisional_source_claims": 8,
        "historical_already_fixed_records": 2,
        "historical_remediated_records": 6,
        "final_P0": 0,
        "final_P1": 0,
    }
    assert run_193r["decision"]["static_ownership_remains_pending"]["next_zero_based_index"] == 86
    assert run_193r["decision"]["static_ownership_remains_pending"]["finding_candidate_zero_based_index"] == 87
    assert run_193r["decision"]["run_195_fresh_dashboard_verification_required"] is True
    assert {key for key, value in run_193r["credit_boundary"].items() if value} == {
        "independent_exact_artifact_review_for_new_historical_remediated_reporting"
    }
    assert len(run_193r["completion_gates"]) == 26
    assert set(run_193r["completion_gates"]) == set(EXPECTED_COMPLETION_GATE_NAMES)
    assert not any(run_193r["completion_gates"].values())

    required_surface_snippets = [
        "FLEET-FUEL-INDEX-SITE-PRIVACY-01",
        "RUN-194",
        "RUN-195",
    ]
    for relative in HUMAN_SURFACES:
        text = read_text_strict(relative)
        assert all(snippet in text for snippet in required_surface_snippets), relative
        assert (
            "161/2,609" in text
            or "161 / 2,609" in text
            or "161 tests / 2,609 assertions" in text
        ), relative
    assert "month-to-date fuel totals" in read_text_strict("00-executive-summary.md")
    assert "rolling 30-day entry count" in read_text_strict("01-repository-module-map.md")
    assert "16 retained identities = 8 current provisional P1 + 2 historical already fixed + 6 historical remediated" in read_text_strict("07-module-findings.md")
    assert "2/340 mapped, 0 final no-match/NCM, 338 unresolved" in read_text_strict("12-native-build-and-do-not-copy-register.md")
    assert "RUN-194 preserves the RUN-192 HTML pending RUN-195" in read_text_strict("13-unresolved-questions-and-evidence-gaps.md")

    builder = read_text_strict(BUILDER_REL)
    compile(builder, BUILDER_REL, "exec")
    builder_required = [
        'dashboard_run_192 = read_json_strict("evidence/browser/current-audit-dashboard-verification-run-192-wave-37.json")',
        'run_193_remediation = read_json_strict("evidence/runtime/current-run-193-fleet-fuel-index-site-privacy-remediation-wave-38.json")',
        'run_193r_review = read_json_strict("evidence/runtime/current-run-193r-independent-fleet-fuel-index-site-privacy-remediation-review-wave-38.json")',
        'run_194_reporting = read_json_strict("evidence/source/current-run-194-fleet-fuel-index-site-privacy-remediation-reporting-wave-38.json")',
        'finding_claim_labels["FLEET-FUEL-INDEX-SITE-PRIVACY-01"] = fleet_fuel_finding["impact"]',
        '"Fresh RUN-195 audit-dashboard verification required"',
        "generators/materialize-run-195-audit-dashboard-verification-wave-38.py",
        "evidence/browser/current-audit-dashboard-verification-run-195-wave-38.json",
        ".tmp-run195-dashboard",
    ]
    assert all(snippet in builder for snippet in builder_required)

    gates = completion_gates()
    assert len(gates) == 26 and not any(gates.values())
    reporting_records = [file_record(path) for path in REPORTING_SURFACES]
    payload: dict[str, Any] = {
        "schema_version": "run-194-fleet-fuel-index-site-privacy-remediation-reporting-wave-38-v1",
        "run_id": RUN_ID,
        "status": STATUS,
        "materialized_on": "2026-09-01",
        "architecture_rule": "Single operating organisation across multiple Sites; approved Site access, exact permissions, canonical Asset provenance, direct-object denial, and privacy are the boundaries; no tenant boundary is introduced.",
        "pins": {
            "reporting_input_commit": HEAD,
            "reporting_input_tree": HEAD_TREE,
            "reporting_input_parent": HEAD_PARENT,
            "reporting_input_subject": HEAD_SUBJECT,
            "origin_main_observed": ORIGIN_MAIN,
            "local_main_ahead": 77,
            "local_main_behind": 0,
            "reporting_materializer": file_record(SCRIPT_REL),
            "baseline_findings": {
                "path": FINDINGS_REL,
                "sha256": BASELINE_FINDINGS_SHA256,
                "ordered_record_list_sha256": BASELINE_RECORD_LIST_SHA256,
                "records": 15,
            },
            "updated_findings": file_record(FINDINGS_REL),
            "new_fleet_fuel_record_canonical_sha256": FUEL_RECORD_SHA256,
            "dashboard_builder": file_record(BUILDER_REL),
            "frozen_run_192_dashboard": file_record(DASHBOARD_REL),
            "run_192_generator": file_record(RUN_192_GENERATOR_REL),
            "run_192_receipt": file_record(RUN_192_REL),
            "run_192_receipt_self_seal_sha256": "a112198be2915cfc8a88b31b38a9cb33c90ad407b6da83f85fac5deae6727995",
            "run_193_generator": file_record(RUN_193_GENERATOR_REL),
            "run_193_receipt": file_record(RUN_193_REL),
            "run_193_receipt_self_seal_sha256": "762bbfbba5fd76fb284ee36fb9854004c224512671acd4b144adaa24f41973c4",
            "run_193r_generator": file_record(RUN_193R_GENERATOR_REL),
            "run_193r_receipt": file_record(RUN_193R_REL),
            "run_193r_receipt_self_seal_sha256": "df7f90f33692f0ff81a143bb3406238d8a2831caad47b295b7a1b784863a06e2",
            "application_lineage": {
                "baseline_commit": "df65322f8eb7d7d0f1623c4bcb8cc8c87573b71d",
                "baseline_tree": "0bd43711942416069675075ce3d515b92b9eaf7d",
                "fix_commit": "2ec4b70e379c6f8cf38c1cb67f5d676fea52cf75",
                "fix_tree": "b6e17efbf1b92b4a12bc01c55e8f245b2e206922",
                "clean_audit_release_commit": "9019b44cb1017931fd0491a90f96ac32a6c4420c",
                "clean_audit_release_tree": "81a4a14e31c88c9731f24a6addee85377ac54256",
                "local_main_merge_commit": HEAD_PARENT,
                "local_main_tree": "6f85ddc1f4e8551c99528cc0c872b37da6c7763a",
                "stable_patch_id": "636771c0b1d9cbe50b2204febaa41679d340aba9",
                "origin_main_observed": ORIGIN_MAIN,
                "published": False,
            },
            "exact_application_test_paths": [
                "app/Http/Controllers/FleetAssets/VehicleController.php",
                "tests/Feature/FleetAssets/FleetFuelIndexSitePrivacyTest.php",
            ],
            "advanced_main_disjoint_path_count": 24,
            "reporting_records": reporting_records,
        },
        "lineage_roles": {
            "run_192": "EXACT_SUPERSEDED_RUN191_AUDIT_ARTIFACT_VERIFICATION_ONLY",
            "run_193": "REPRODUCTION_REMEDIATION_LOCAL_INTEGRATION_AND_BOUNDED_EXECUTION",
            "run_193r": "INDEPENDENT_EXACT_ARTIFACT_GO_AUTHORIZING_ONE_REPORTING_RECORD",
            "run_194": "LIVE_REPORTING_ONLY_WITH_FROZEN_HTML",
            "run_195": "REQUIRED_FRESH_DASHBOARD_REBUILD_AND_VERIFICATION",
        },
        "reporting_transition": {
            "finding_id": "FLEET-FUEL-INDEX-SITE-PRIVACY-01",
            "authorized_by_run_193r": True,
            "transition": "ABSENT_TO_HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING",
            "candidate_feature_id": "CAP-FLEET-VEHICLE-REGISTER",
            "feature_identity_status": "PENDING_FRESH_SEMANTIC_REVIEW",
            "static_ownership_credit": False,
            "unchanged_prefix_records": 15,
            "unchanged_prefix_canonical_sha256": BASELINE_RECORD_LIST_SHA256,
            "new_record_canonical_sha256": FUEL_RECORD_SHA256,
            "counts_before": {
                "retained_claim_records": 15,
                "provisional_source_claims": 8,
                "historical_already_fixed": 2,
                "historical_remediated": 5,
                "final_P0": 0,
                "final_P1": 0,
            },
            "counts_after": {
                "retained_claim_records": 16,
                "provisional_source_claims": 8,
                "historical_already_fixed": 2,
                "historical_remediated": 6,
                "final_P0": 0,
                "final_P1": 0,
            },
        },
        "bounded_execution_accounting": {
            "prior": {"tests": 155, "assertions": 2403},
            "counted_once": {"tests": 6, "assertions": 206, "source": "post_merge_focused_component"},
            "unique_total": {"tests": 161, "assertions": 2609},
            "red_reproduction": {"failed": 6, "passed": 0, "assertions": 65, "denominator_credit": False},
            "isolated_replay": {"tests": 6, "assertions": 206, "denominator_credit": False},
            "supporting_regressions": {"tests": 20, "assertions": 215, "denominator_credit": False},
            "combined_post_merge": {"tests": 26, "assertions": 421, "second_credit": False},
        },
        "run_192_history": {
            "viewports": "4/4",
            "named_visible_checks_per_viewport": "30/30",
            "navigation": "10/10",
            "resources": "476/476",
            "anchors": "893/893",
            "console_page_warning_errors": 0,
            "credit": "EXACT_SUPERSEDED_RUN191_AUDIT_ARTIFACT_ONLY",
        },
        "reporting_manifest": {
            "human_surfaces": HUMAN_SURFACES,
            "structured_register": FINDINGS_REL,
            "dashboard_builder": BUILDER_REL,
            "dashboard_html": DASHBOARD_REL,
        },
        "preservation_boundary": {
            "dashboard_sha256": FROZEN_DASHBOARD_SHA256,
            "dashboard_html_changed": False,
            "dashboard_builder_executed_by_run_194": False,
            "static_ownership": static_counts,
            "queue": {
                "total": 507,
                "reviewed": 121,
                "pending": 386,
                "owned": 99,
                "without_ownership": 408,
                "next_zero_based_index": 86,
                "next_route": "fleet-assets.trips.playback.data",
                "advanced": False,
            },
            "fuel_candidate": {
                "zero_based_index": 87,
                "queue_id": "RUN090-ROUTE-0088",
                "route_record_id": "RUN077-ROUTE-0696",
                "route_name": "fleet-assets.fuel.index",
                "controller_action": "VehicleController::fuel",
                "ownership_status": "PENDING_FRESH_SEMANTIC_REVIEW",
            },
            "benchmark": {"mapped": 2, "total": 340, "final_no_match_or_NCM": 0, "unresolved": 338},
            "P0": 0,
            "P1": 0,
        },
        "reporting_input_noninheritance": {
            "advanced_main_disjoint_path_count": 24,
            "advanced_paths_recredited": False,
            "calendar_hazard_checklist_or_other_job_correctness_inherited": False,
            "audit_dashboard_application_credit_inherited": False,
        },
        "publication_boundary": {
            "origin_main_observed": ORIGIN_MAIN,
            "application_merge_local_only": True,
            "application_published": False,
            "reporting_published": False,
            "push_authorized": False,
        },
        "dashboard_forward_gate": {
            "required_run": "RUN-195",
            "generator": "generators/materialize-run-195-audit-dashboard-verification-wave-38.py",
            "receipt": "evidence/browser/current-audit-dashboard-verification-run-195-wave-38.json",
            "dashboard_html_changed_by_run_194": False,
            "fresh_four_viewport_navigation_resource_console_verification_required": True,
            "forward_paths_intentionally_unhashed": True,
        },
        "reporting_transaction": {
            "exact_ten_path_allowlist": sorted(EXACT_DIRTY_ALLOWLIST),
            "reporting_surfaces": REPORTING_SURFACES,
            "new_generator": SCRIPT_REL,
            "new_receipt": OUTPUT_REL,
            "materializer_wrote_only": [OUTPUT_REL],
            "strict_utf8_lf_no_trailing_whitespace_validated": True,
            "strict_duplicate_key_pretty_json_validated": True,
            "dashboard_preserved_byte_for_byte": True,
        },
        "noninheritance_boundary": {
            "red_failures_or_assertions_recredited": False,
            "isolated_green_replay_recredited": False,
            "supporting_regressions_recredited": False,
            "combined_26_421_second_credit": False,
            "static_route_feature_ownership": False,
            "static_controller_action_bridge": False,
            "static_page_or_frontend_ownership": False,
            "queue_matrix_or_feature_union_change": False,
            "independent_visible_row_logger_site_authorization": False,
            "write_path_or_adjacent_route_correctness": False,
            "application_browser_or_ease": False,
            "benchmark_mapping_or_final_no_match_NCM": False,
            "full_suite_coverage_feature_module_pass_or_release": False,
            "publication_final_finding_completion_or_audit_completion": False,
        },
        "credit_boundary": {
            "live_findings_register_and_reporting_status": True,
            "application_source_or_test_change": False,
            "application_runtime_reexecution": False,
            "static_route_or_page_feature_ownership": False,
            "static_controller_action_bridge": False,
            "queue_advance": False,
            "application_browser": False,
            "benchmark_mapping": False,
            "final_no_match_or_NCM": False,
            "ease": False,
            "full_suite_or_coverage": False,
            "pass": False,
            "release": False,
            "publication": False,
            "final_finding": False,
            "feature_or_module_completion": False,
            "gate_4": False,
            "audit_complete": False,
        },
        "completion_gates": gates,
        "completion_boundary": dict(gates),
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [OUTPUT_REL],
    }
    assert {key for key, value in payload["credit_boundary"].items() if value} == {
        "live_findings_register_and_reporting_status"
    }
    assert not any(payload["completion_gates"].values())
    assert not any(payload["completion_boundary"].values())

    payload["receipt_self_seal_sha256"] = canonical_sha256(payload)
    output_bytes = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    output_path = AUDIT_DIR / OUTPUT_REL
    output_path.parent.mkdir(parents=True, exist_ok=True)
    if output_path.exists():
        assert output_path.read_bytes() == output_bytes, "Existing RUN194 receipt differs from deterministic payload"
    else:
        temporary_path = output_path.with_name(f".{output_path.name}.tmp-run194")
        assert not temporary_path.exists(), f"Refusing stale temp output: {temporary_path}"
        try:
            with temporary_path.open("xb") as handle:
                handle.write(output_bytes)
                handle.flush()
                os.fsync(handle.fileno())
            assert temporary_path.read_bytes() == output_bytes
            os.replace(temporary_path, output_path)
        finally:
            if temporary_path.exists():
                temporary_path.unlink()

    parsed_output = read_json_strict(OUTPUT_REL)
    output_without_seal = dict(parsed_output)
    observed_seal = output_without_seal.pop("receipt_self_seal_sha256")
    assert observed_seal == canonical_sha256(output_without_seal)
    assert parsed_output == payload

    dashboard_after = sha256_file(DASHBOARD_REL)
    assert dashboard_after == dashboard_before == FROZEN_DASHBOARD_SHA256
    assert str(git("diff", "--cached", "--name-only")).strip() == ""
    assert str(git("diff", "--check")).strip() == ""
    dirty_after, status_after = status_paths()
    assert dirty_after == EXACT_DIRTY_ALLOWLIST
    assert all(status.startswith((" M ", "?? ")) for status in status_after)

    print(json.dumps({
        "run_id": RUN_ID,
        "receipt_sha256": sha256_file(OUTPUT_REL),
        "receipt_self_seal_sha256": observed_seal,
        "dashboard_sha256_before": dashboard_before,
        "dashboard_sha256_after": dashboard_after,
        "dirty_paths": sorted(dirty_after),
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
