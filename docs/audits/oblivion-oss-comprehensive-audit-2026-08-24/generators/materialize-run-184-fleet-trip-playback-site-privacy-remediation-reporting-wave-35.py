#!/usr/bin/env python3
"""Validate and seal the RUN-184 live reporting transition.

This producer changes no application source, executes no application tests, and
does not build the dashboard. It validates the already-authored reporting
surfaces, preserves the verified RUN-182 HTML, and writes one self-sealed JSON
receipt.
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

HEAD = "a900f078c9c05f587f6f7884f5fe715076891416"
HEAD_TREE = "852126934a18a1364244a35f7789263779e47485"
AUDIT_PARENT = "70ffe18e8ffdb49ce5aa4e7c20eab0076c10c289"
AUDIT_PARENT_TREE = "3c7c26443c9ccefbb8b081bd7a2ae623c0d9cefd"
MONITORING_FIX = "2de69d8649786bab2742cd731cb9097af148b2f8"
MONITORING_FIX_TREE = "e203b7398a7851a0dfc02fdd03b5b5ee207f9b31"

FLEET_BASE = "db4196ccb3a8d9f6bcb33fb40680527d09c02dac"
FLEET_BASE_TREE = "68052b68b070dff799d5be1d5515ec0b8472207f"
FLEET_FIX = "93e576978efae4a0112a95ed406c312f6bcadeb5"
FLEET_FIX_TREE = "f265c8476773aaceecbfe90680e59b5f4c74b205"
ADVANCED_MAIN = "0537f0f0eacafbeaf635ced4883a8bdf8e49d3f6"
ADVANCED_MAIN_TREE = "5eb8c401847f2da101922aef6c100b8e03d30b9d"
FLEET_MERGE = "4038cf7fe5a789ca64e436300f2cf4b94ac16db4"
FLEET_MERGE_TREE = "b9757ccb9010564b8512c0ed47abfc553f38b697"
STABLE_PATCH_ID = "12c306d28e54ff88432d18b271706473ee793871"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"

FROZEN_HTML_SHA256 = "8779848cc1a95ef82f2c5eba1a542e5a4784559f75ef20e9eb06696abde56457"
BASELINE_FINDINGS_SHA256 = "55337abfc8f2fe9fde863715e3d77649ec6dd195008281944881b02e00bb54e1"
BASELINE_RECORD_LIST_CANONICAL_SHA256 = "26676e9acf7966ffc4e25d33aaa2b2fc3112dfbc8eb5e32d83922f59dea39505"
FLEET_INDEX_RECORD_CANONICAL_SHA256 = "98c82f01cf8348fc4b60a4c17feea675182dc287e4c7907174b13d44af331fab"
PLAYBACK_RECORD_CANONICAL_SHA256 = "3caae13be65e418a9e96685df0c9391a7e660ea0271b8cb1e9f120550a8d5957"

RUN_183_GENERATOR = "generators/materialize-run-183-fleet-trip-playback-site-privacy-remediation-wave-35.py"
RUN_183_RECEIPT = "evidence/runtime/current-run-183-fleet-trip-playback-site-privacy-remediation-wave-35.json"
RUN_183R_GENERATOR = "generators/materialize-independent-run-183-fleet-trip-playback-site-privacy-remediation-review-wave-35.py"
RUN_183R_RECEIPT = "evidence/runtime/current-run-183r-independent-fleet-trip-playback-site-privacy-remediation-review-wave-35.json"
RUN_183_GENERATOR_SHA256 = "602964ec765cc9bd71d7b6fed103bdbd1b4b5543c0843f2c2dcdb2a960779f8e"
RUN_183_RECEIPT_SHA256 = "7bb1b1013cf67344c48e5a8b6e551bf3c769695e0384c2b333fb47286e53310a"
RUN_183_RECEIPT_SEAL = "839e8d47700afedd2ec277695bbe492bd13433492ce0ff724c753988b5ce039a"
RUN_183R_GENERATOR_SHA256 = "171836a13c108c3176e8ddc1fa62dbc86503d6e459e43bce3eb9a1d369ece61a"
RUN_183R_RECEIPT_SHA256 = "170245898590f6429a171bbd8a41455f096b5b43340b840294735fdbc5522640"
RUN_183R_RECEIPT_SEAL = "a639be1048e97e5907509b571ed92dd4a2513a22dab5b16c188b3c5e82a1b68c"

SCRIPT_REL = "generators/materialize-run-184-fleet-trip-playback-site-privacy-remediation-reporting-wave-35.py"
OUTPUT_REL = "evidence/source/current-run-184-fleet-trip-playback-site-privacy-remediation-reporting-wave-35.json"
REPORTING_SURFACES = [
    "00-executive-summary.md",
    "01-repository-module-map.md",
    "07-module-findings.md",
    "11-prioritised-roadmap.md",
    "12-native-build-and-do-not-copy-register.md",
    "13-unresolved-questions-and-evidence-gaps.md",
    "findings.json",
    "generators/build-current-audit-dashboard.py",
]
ALLOWED_DIRTY = sorted(
    [f" M {PREFIX}/{path}" for path in REPORTING_SURFACES]
    + [f"?? {PREFIX}/{SCRIPT_REL}"]
)
MONITORING_PATHS = [
    "app/Domain/Monitoring/Services/MonitoringObservationIngestor.php",
    "tests/Feature/Monitoring/DependencySuppressionTest.php",
    "tests/Feature/Monitoring/MonitoringObservationIngestorTest.php",
]


def run(*args: str, input_bytes: bytes | None = None) -> bytes:
    return subprocess.run(
        args,
        cwd=ROOT,
        input=input_bytes,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=True,
    ).stdout


def git(*args: str) -> str:
    return run("git", *args).decode("utf-8").strip()


def strict_json(raw: bytes, label: str) -> dict[str, Any]:
    assert raw.startswith(b"{") and raw.endswith(b"\n"), label
    assert b"\r" not in raw and b"\xef\xbb\xbf" not in raw, label

    def reject(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, f"duplicate key in {label}: {key}"
            result[key] = value
        return result

    value = json.loads(raw.decode("utf-8"), object_pairs_hook=reject)
    assert isinstance(value, dict), label
    return value


def read_json(relative: str) -> dict[str, Any]:
    return strict_json((AUDIT / relative).read_bytes(), relative)


def git_json(revision: str, relative: str) -> tuple[dict[str, Any], bytes]:
    raw = run("git", "show", f"{revision}:{PREFIX}/{relative}")
    return strict_json(raw, f"{revision}:{relative}"), raw


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def canonical_bytes(value: Any) -> bytes:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")


def canonical_sha256(value: Any) -> str:
    return sha256(canonical_bytes(value))


def file_record(relative: str) -> dict[str, Any]:
    raw = (AUDIT / relative).read_bytes()
    assert b"\r" not in raw and b"\xef\xbb\xbf" not in raw, relative
    return {
        "path": relative,
        "sha256": sha256(raw),
        "git_blob_id": run("git", "hash-object", "--stdin", input_bytes=raw).decode().strip(),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


def verify_self_seal(payload: dict[str, Any], expected: str) -> None:
    body = dict(payload)
    actual = body.pop("receipt_self_seal_sha256")
    assert actual == expected
    assert canonical_sha256(body) == expected


def validate_repository() -> None:
    assert git("rev-parse", "HEAD") == HEAD
    assert git("show", "-s", "--format=%T", "HEAD") == HEAD_TREE
    assert git("show", "-s", "--format=%P", "HEAD").split() == [AUDIT_PARENT, MONITORING_FIX]
    assert git("show", "-s", "--format=%T", AUDIT_PARENT) == AUDIT_PARENT_TREE
    assert git("show", "-s", "--format=%T", MONITORING_FIX) == MONITORING_FIX_TREE
    dirty = sorted(
        line
        for line in run("git", "status", "--porcelain=v1").decode("utf-8").splitlines()
        if line
    )
    expected_with_output = sorted(ALLOWED_DIRTY + [f"?? {PREFIX}/{OUTPUT_REL}"])
    assert dirty in (ALLOWED_DIRTY, expected_with_output), dirty
    assert git("diff", "--check") == ""
    assert git("diff", "--name-only", f"{AUDIT_PARENT}..{HEAD}").splitlines() == MONITORING_PATHS
    assert git("diff", "--name-only", f"{ADVANCED_MAIN}..{FLEET_MERGE}").splitlines() == [
        "app/Http/Controllers/Fleet/FleetTripController.php",
        "tests/Feature/FleetAssets/FleetTripPlaybackSitePrivacyTest.php",
    ]
    assert git("show", "-s", "--format=%P", FLEET_MERGE).split() == [ADVANCED_MAIN, FLEET_FIX]
    assert git("show", "-s", "--format=%T", FLEET_BASE) == FLEET_BASE_TREE
    assert git("show", "-s", "--format=%T", FLEET_FIX) == FLEET_FIX_TREE
    assert git("show", "-s", "--format=%T", ADVANCED_MAIN) == ADVANCED_MAIN_TREE
    assert git("show", "-s", "--format=%T", FLEET_MERGE) == FLEET_MERGE_TREE
    assert sha256((AUDIT / "audit-dashboard.html").read_bytes()) == FROZEN_HTML_SHA256


def validate_lineage() -> tuple[dict[str, Any], dict[str, Any]]:
    assert file_record(RUN_183_GENERATOR)["sha256"] == RUN_183_GENERATOR_SHA256
    assert file_record(RUN_183_RECEIPT)["sha256"] == RUN_183_RECEIPT_SHA256
    assert file_record(RUN_183R_GENERATOR)["sha256"] == RUN_183R_GENERATOR_SHA256
    assert file_record(RUN_183R_RECEIPT)["sha256"] == RUN_183R_RECEIPT_SHA256
    remediation = read_json(RUN_183_RECEIPT)
    review = read_json(RUN_183R_RECEIPT)
    verify_self_seal(remediation, RUN_183_RECEIPT_SEAL)
    verify_self_seal(review, RUN_183R_RECEIPT_SEAL)
    assert remediation["run_id"] == "RUN-183-FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01-REMEDIATION-WAVE-35"
    assert review["run_id"] == "RUN-183R-INDEPENDENT-FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01-REMEDIATION-REVIEW-WAVE-35"
    assert remediation["delegated_runtime_execution"]["baseline_red"]["failed"] == 3
    assert remediation["delegated_runtime_execution"]["baseline_red"]["passed"] == 2
    assert remediation["delegated_runtime_execution"]["baseline_red"]["assertions_reported"] == 30
    assert remediation["delegated_runtime_execution"]["post_merge_green_focused"]["tests"] == 11
    assert remediation["delegated_runtime_execution"]["post_merge_green_focused"]["assertions"] == 167
    assert remediation["delegated_runtime_execution"]["isolated_green_focused"]["added_to_bounded_disposition_denominator"] is False
    assert remediation["delegated_runtime_execution"]["isolated_supporting_fleet_regressions"]["tests"] == 20
    assert remediation["delegated_runtime_execution"]["isolated_supporting_fleet_regressions"]["assertions"] == 215
    assert review["decision"]["verdict"] == "GO"
    assert review["decision"]["blocking_discrepancies"] == 0
    assert review["decision"]["new_historical_remediated_record_reporting_authorized"] is True
    assert review["decision"]["authorized_live_reporting_run"] == "RUN-184"
    assert review["decision"]["authorized_resulting_lineage"] == {
        "retained_claim_records": 14,
        "current_provisional_source_claims": 8,
        "historical_already_fixed_records": 2,
        "historical_remediated_records": 4,
        "final_P0": 0,
        "final_P1": 0,
    }
    assert review["decision"]["static_ownership_remains_pending"]["next_zero_based_index"] == 85
    assert all(value is False for value in remediation["completion_boundary"].values())
    assert all(value is False for value in review["completion_boundary"].values())
    return remediation, review


def validate_findings() -> tuple[dict[str, Any], dict[str, Any], dict[str, str]]:
    baseline, baseline_raw = git_json(HEAD, "findings.json")
    current = read_json("findings.json")
    assert sha256(baseline_raw) == BASELINE_FINDINGS_SHA256
    assert len(baseline["records"]) == 13
    assert canonical_sha256(baseline["records"]) == BASELINE_RECORD_LIST_CANONICAL_SHA256
    assert current["records"][:13] == baseline["records"]
    assert [row["id"] for row in current["records"][:13]] == [row["id"] for row in baseline["records"]]
    baseline_hashes = {row["id"]: canonical_sha256(row) for row in baseline["records"]}
    assert baseline_hashes["FLEET-TRIP-INDEX-SITE-PRIVACY-01"] == FLEET_INDEX_RECORD_CANONICAL_SHA256
    assert len(current["records"]) == 14
    playback = current["records"][-1]
    assert playback["id"] == "FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01"
    assert canonical_sha256(playback) == PLAYBACK_RECORD_CANONICAL_SHA256
    assert playback["record_status"] == "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
    assert playback["candidate_feature_id"] == "CAP-FLEET-VEHICLE-REGISTER"
    assert playback["feature_identity_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
    assert playback["current_adjudication"]["application_commit"] == FLEET_MERGE
    assert playback["current_adjudication"]["stable_patch_id"] == STABLE_PATCH_ID
    expected_claim_anchors = [
        "routes/fleet-assets.php:55-56",
        "app/Http/Controllers/Fleet/FleetTripController.php:24-119",
        "app/Http/Controllers/Fleet/FleetTripController.php:178-280",
        "tests/Feature/FleetAssets/FleetTripPlaybackSitePrivacyTest.php:141-550",
    ]
    assert playback["backend_anchor"]["claim_anchors"] == expected_claim_anchors
    assert playback["evidence"]["claim_anchors"] == expected_claim_anchors
    assert playback["route_url"]["ownership_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
    assert playback["route_url"]["supporting_data_route_static_ownership_adjudicated"] is False
    for key in (
        "static_route_feature_ownership_inherited",
        "static_controller_action_bridge_inherited",
        "static_playback_data_route_ownership_inherited",
        "static_page_or_frontend_ownership_inherited",
        "queue_advance_inherited",
        "route_frontend_or_write_path_correctness_inherited",
        "telemetry_lifecycle_correctness_inherited",
        "adjacent_fleet_route_correctness_inherited",
        "broader_fleet_correctness_inherited",
    ):
        assert playback["current_adjudication"][key] is False
    assert playback["evidence"]["tests_executed"] == 11
    assert playback["evidence"]["assertions"] == 167
    assert playback["evidence"]["supporting_tests"] == 20
    assert playback["evidence"]["supporting_assertions"] == 215
    assert playback["completion_credit"] is False
    assert all(value is False for value in playback["credit"].values())
    assert current["counts"]["retained_claim_records"] == 14
    assert current["counts"]["provisional_source_claims"] == 8
    assert current["counts"]["historical_already_fixed"] == 2
    assert current["counts"]["historical_remediated"] == 4
    assert current["counts"]["bounded_disposition_tests_passed"] == 99
    assert current["counts"]["bounded_disposition_assertions"] == 1931
    assert current["counts"]["med_cd_atomicity_aggregation_basis"] == (
        "reported separately from the then-current 78/1529 at RUN-167, remained excluded from the "
        "historical RUN-177 88/1764 total, and remains excluded from the current RUN-184 99/1931 "
        "total because the supporting command overlaps prior denominators and the 10-assertion "
        "rollback test includes an uncredited balance-check half"
    )
    assert current["counts"]["final_P0"] == current["counts"]["final_P1"] == 0
    assert current["counts"]["static_source_feature_ownership_records"] == 666
    assert current["counts"]["static_source_feature_ownership_route_records"] == 309
    assert current["counts"]["static_source_feature_ownership_page_records"] == 357
    assert current["counts"]["static_controller_action_bridges"] == 97
    assert current["counts"]["direct_exact_queue_reviewed"] == 120
    assert current["counts"]["direct_exact_queue_pending_unreviewed"] == 387
    assert current["counts"]["direct_exact_queue_owned"] == 98
    assert current["counts"]["direct_exact_queue_without_ownership"] == 409
    assert current["counts"]["benchmark_mapped"] == 2
    assert current["counts"]["final_no_match"] == 0
    assert current["counts"]["benchmark_unresolved"] == 338
    run_182_history = current["current_audit_artifact_verification_history"]["run_182"]
    assert run_182_history == {
        "run_id": "RUN-182-AUDIT-DASHBOARD-VERIFICATION-WAVE-34",
        "receipt_sha256": "d3dc3ef6e842f0b5df74b27948ac6ef8abfda205516f6ac9b6a5d9c9858cd81e",
        "dashboard_sha256": FROZEN_HTML_SHA256,
        "status": "AUDIT_DASHBOARD_RUN181_EXACT_ARTIFACT_RESPONSIVE_LINK_CONSOLE_VERIFICATION_GO_LOCAL_ONLY_ZERO_APPLICATION_PUBLICATION_FINAL_FINDING_GATE4_OR_AUDIT_COMPLETION_CREDIT",
        "viewports": "4/4",
        "navigation": "10/10",
        "unique_local_resources": "455/455",
        "visible_boundary_checks": "105/105",
        "anchor_elements": "852/852",
        "application_browser_credit": False,
        "publication_credit": False,
        "audit_complete": False,
        "superseded_by_run_184_reporting_sources": True,
        "run_185_dashboard_verification_required": True,
    }
    assert all(row["completion_credit"] is False for row in current["records"])
    assert all(all(value is False for value in row["credit"].values()) for row in current["records"])
    return baseline, current, baseline_hashes


def validate_reporting_surfaces() -> list[dict[str, Any]]:
    required = {
        "00-executive-summary.md": [
            "RUN-182–184 Fleet trip playback Site privacy remediation checkpoint",
            "105/105 visible checks",
            "455/455 unique non-fragment local resources",
            "14 retained identities = 8 current provisional P1 claims + 2 historical already-fixed records + 4 historical remediated records",
            "99 tests / 1,931 assertions",
        ],
        "01-repository-module-map.md": [
            "current 99/1,931 non-overlapping bounded-disposition total",
            "queue index 85 stay `PENDING_FRESH_SEMANTIC_REVIEW`",
            "zero inherited RUN-184 credit",
        ],
        "07-module-findings.md": [
            "14 retained claim records",
            "FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01 — CAP-FLEET-VEHICLE-REGISTER candidate association — historical remediated",
            "unique post-merge local-main run passes 11 tests / 167 assertions",
            "current RUN-184 bounded-disposition total is 99/1,931",
            "RUN-180/R–181 integrate index 84",
        ],
        "11-prioritised-roadmap.md": [
            "14 retained records",
            "`FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01` is retained as a historical remediated P1 issue identity",
            "fresh RUN-185 dashboard verification is required",
        ],
        "12-native-build-and-do-not-copy-register.md": [
            "`FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01`",
            "index 84 static ownership integrated separately by RUN-180/R–181",
            "index 85 ownership `PENDING_FRESH_SEMANTIC_REVIEW`",
            "no source/assets/wording/layout copied",
        ],
        "13-unresolved-questions-and-evidence-gaps.md": [
            "current non-overlapping arithmetic of 99 / 1,931",
            "RUN-183 proves only the selected playback page/data",
            "FLEET-TRIP-INDEX-SITE-PRIVACY-01` and `FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01` are separately discovered",
            "RUN-001 through RUN-184 represented",
            "all 26 completion gates remain unchanged or false",
        ],
        "generators/build-current-audit-dashboard.py": [
            "RUN-184-FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01-REMEDIATION-REPORTING-WAVE-35",
            "RUN-182–184 Fleet trip playback Site privacy remediation checkpoint",
            "FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01",
            "generators/materialize-run-182-audit-dashboard-verification-wave-34.py",
            "evidence/runtime/current-run-183-fleet-trip-playback-site-privacy-remediation-wave-35.json",
            "evidence/runtime/current-run-183r-independent-fleet-trip-playback-site-privacy-remediation-review-wave-35.json",
            "evidence/source/current-run-184-fleet-trip-playback-site-privacy-remediation-reporting-wave-35.json",
            "generators/materialize-run-185-audit-dashboard-verification-wave-35.py",
            "evidence/browser/current-audit-dashboard-verification-run-185-wave-35.json",
            "index 84 static route owner and action bridge integrated separately by RUN-180/R–181",
            "index 85 ownership PENDING_FRESH_SEMANTIC_REVIEW",
            "RUN-182: exact RUN-181 dashboard verified at 4/4 viewports · 105/105 visible checks · 10/10 navigation · 455/455 unique local resources · 852 anchors",
            "RUN-182 exact dashboard verification</td><td><strong>4/4 viewports · 105/105 visible checks · 10/10 navigation · 455/455 unique local resources · 852 anchors",
            "reported separately from the historical 78/1,529 and current 99/1,931",
        ],
    }
    for relative, snippets in required.items():
        text = (AUDIT / relative).read_text(encoding="utf-8")
        for snippet in snippets:
            assert snippet in text, f"missing from {relative}: {snippet}"
    assert "index 84 ownership `PENDING_FRESH_SEMANTIC_REVIEW`" not in (
        AUDIT / "12-native-build-and-do-not-copy-register.md"
    ).read_text(encoding="utf-8")
    return [file_record(path) for path in REPORTING_SURFACES]


def completion_boundary() -> dict[str, bool]:
    keys = [
        "routes_classified", "inertia_pages_classified", "features_in_canonical_register",
        "routes_and_pages_mapped_to_feature_id", "features_with_verified_benchmark_or_final_ncm",
        "human_features_with_task_script_and_ten_scores", "common_and_safety_journeys_cross_reviewed",
        "hero_banner_instances_classified", "overlay_implementations_and_triggers_classified",
        "safe_routes_observed_at_desktop", "selected_families_and_journeys_all_viewports",
        "required_visual_states_classified", "material_visual_finding_families_resampled",
        "models_classified", "policies_classified", "service_domain_entries_classified",
        "critical_async_owners_classified", "modules_with_all_eight_passes",
        "prompt_benchmark_projects_formally_triaged", "p0_p1_complete_finding_fields",
        "redesigns_neutral_native_no_copy", "ease_4_5_claims_independently_reviewed",
        "browser_claims_labeled", "visual_inconsistencies_complete_context",
        "official_source_inference_specialist_split", "all_agents_returned_reconciled_represented_none_live",
    ]
    assert len(keys) == 26
    return {key: False for key in keys}


def build_receipt(
    baseline: dict[str, Any],
    current: dict[str, Any],
    baseline_hashes: dict[str, str],
    manifest: list[dict[str, Any]],
) -> dict[str, Any]:
    playback = current["records"][-1]
    receipt: dict[str, Any] = {
        "schema_version": "run-184-fleet-trip-playback-site-privacy-remediation-reporting-wave-35-v1",
        "run_id": "RUN-184-FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01-REMEDIATION-REPORTING-WAVE-35",
        "status": "FLEET_TRIP_PLAYBACK_SITE_PRIVACY_HISTORICAL_REMEDIATED_RECORD_REPORTING_MATERIALIZED_LOCAL_MAIN_NOT_PUBLISHED_STATIC_OWNERSHIP_PENDING_DASHBOARD_RUN185_REQUIRED_ZERO_FINAL_FINDING_OR_COMPLETION_CREDIT",
        "materialized_on": "2026-08-30",
        "architecture_rule": {
            "operating_organisations": 1,
            "multiple_sites": True,
            "multi_tenant": False,
            "authorization_boundary": "approved Site access, exact roles and permissions, canonical Asset provenance, direct-object denial, consent, and privacy",
        },
        "pins": {
            "reporting_input_commit": HEAD,
            "reporting_input_tree": HEAD_TREE,
            "reporting_input_parents": [AUDIT_PARENT, MONITORING_FIX],
            "audit_release_commit": AUDIT_PARENT,
            "audit_release_tree": AUDIT_PARENT_TREE,
            "disjoint_monitoring_fix_commit": MONITORING_FIX,
            "disjoint_monitoring_fix_tree": MONITORING_FIX_TREE,
            "origin_main_observed": ORIGIN_MAIN,
            "fleet_application_baseline_commit": FLEET_BASE,
            "fleet_application_baseline_tree": FLEET_BASE_TREE,
            "fleet_fix_commit": FLEET_FIX,
            "fleet_fix_tree": FLEET_FIX_TREE,
            "fleet_advanced_main_commit": ADVANCED_MAIN,
            "fleet_advanced_main_tree": ADVANCED_MAIN_TREE,
            "fleet_local_main_merge_commit": FLEET_MERGE,
            "fleet_local_main_merge_tree": FLEET_MERGE_TREE,
            "fleet_stable_patch_id": STABLE_PATCH_ID,
            "run_183_generator": file_record(RUN_183_GENERATOR),
            "run_183_receipt": {**file_record(RUN_183_RECEIPT), "receipt_self_seal_sha256": RUN_183_RECEIPT_SEAL},
            "run_183r_generator": file_record(RUN_183R_GENERATOR),
            "run_183r_receipt": {**file_record(RUN_183R_RECEIPT), "receipt_self_seal_sha256": RUN_183R_RECEIPT_SEAL},
            "reporting_materializer": file_record(SCRIPT_REL),
            "baseline_findings": {
                "sha256": BASELINE_FINDINGS_SHA256,
                "record_list_canonical_sha256": BASELINE_RECORD_LIST_CANONICAL_SHA256,
                "record_count": len(baseline["records"]),
            },
            "current_findings": file_record("findings.json"),
            "current_playback_record_canonical_sha256": canonical_sha256(playback),
            "dashboard_builder": file_record("generators/build-current-audit-dashboard.py"),
            "unchanged_run_182_dashboard": file_record("audit-dashboard.html"),
        },
        "lineage_roles": {
            "run_182": "verifies only the exact now-superseded RUN-181 audit dashboard",
            "run_183": "establishes playback reproduction, narrow remediation, bounded runtime, local-main integration, nonpublication, and zero static ownership",
            "run_183r": "independently authorizes one new historical-remediated record only",
            "run_184": "alone adds that record and changes live reporting",
            "run_185": "required fresh dashboard rebuild and verification",
        },
        "reporting_transition": {
            "finding_id": "FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01",
            "candidate_feature_id": "CAP-FLEET-VEHICLE-REGISTER",
            "feature_identity_status": "PENDING_FRESH_SEMANTIC_REVIEW",
            "authorized_by_run_183r": True,
            "transition_kind": "NEW_HISTORICAL_REMEDIATED_RECORD",
            "status_before": "ABSENT",
            "status_after": "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING",
            "counts_before": {
                "retained_claim_records": 13, "provisional_source_claims": 8,
                "historical_already_fixed": 2, "historical_remediated": 3,
                "final_P0": 0, "final_P1": 0,
            },
            "counts_after": {
                "retained_claim_records": 14, "provisional_source_claims": 8,
                "historical_already_fixed": 2, "historical_remediated": 4,
                "final_P0": 0, "final_P1": 0,
            },
            "new_target_record_canonical_sha256": canonical_sha256(playback),
            "unchanged_preexisting_record_count": 13,
            "unchanged_preexisting_record_hashes": baseline_hashes,
            "reporting_surface_paths": REPORTING_SURFACES,
        },
        "bounded_execution_accounting": {
            "prior_unique_total": {"tests": 88, "assertions": 1764},
            "run_183_post_merge_unique_increment": {"tests": 11, "assertions": 167, "counted_once": True},
            "unique_total": {"tests": 99, "assertions": 1931},
            "excluded_from_unique_total": {
                "baseline_red": {"failed": 3, "passed": 2, "assertions_reported": 30},
                "isolated_green_replay": {"tests": 11, "assertions": 167},
                "supporting_fleet_regressions": {"tests": 20, "assertions": 215},
            },
        },
        "reporting_manifest": manifest,
        "preservation_boundary": {
            "exact_modified_reporting_surface_count": 8,
            "exact_run_184_dirty_path_count": 10,
            "all_other_paths_untouched": True,
            "dashboard_byte_identical_to_reporting_input": True,
            "dashboard_sha256": FROZEN_HTML_SHA256,
            "static_ownership": {
                "owners": 666, "routes": 309, "pages": 357,
                "controller_action_bridges": 97,
                "ownership_status": "PENDING_FRESH_SEMANTIC_REVIEW",
            },
            "queue": {
                "next_zero_based_index": 85,
                "next_queue_id": "RUN090-ROUTE-0086",
                "next_route_record_id": "RUN077-ROUTE-0694",
                "reviewed": 120, "pending": 387, "owned": 98,
                "without_ownership": 409, "advanced_by_run_184": False,
            },
            "benchmark": {"mapped": 2, "total": 340, "final_no_match_or_NCM": 0, "unresolved": 338},
        },
        "reporting_input_noninheritance": {
            "disjoint_monitoring_paths": MONITORING_PATHS,
            "monitoring_reporting_credit": False,
            "monitoring_execution_credit": False,
            "monitoring_finding_credit": False,
            "monitoring_completion_credit": False,
        },
        "publication_boundary": {
            "origin_main": ORIGIN_MAIN,
            "fleet_application_published": False,
            "run_183_to_184_published": False,
            "publication_authorized": False,
            "materializer_performed_push_or_publication": False,
        },
        "dashboard_forward_gate": {
            "required_run": "RUN-185",
            "dashboard_html_changed_by_run_184": False,
            "unchanged_dashboard_sha256": FROZEN_HTML_SHA256,
            "fresh_rebuild_required": True,
            "fresh_verification_required": True,
        },
        "noninheritance_boundary": {
            "baseline_red_recredited": False,
            "isolated_green_replay_recredited": False,
            "supporting_regressions_recredited": False,
            "static_route_feature_ownership": False,
            "static_controller_action_bridge": False,
            "supporting_data_route_ownership": False,
            "static_page_or_frontend_ownership": False,
            "queue_advance": False,
            "route_frontend_write_or_telemetry_lifecycle_correctness": False,
            "adjacent_or_broader_fleet_correctness": False,
            "application_browser_or_ease": False,
            "benchmark_mapping_or_final_no_match_NCM": False,
            "final_finding_or_feature_module_pass_release_completion": False,
        },
        "credit_boundary": {
            "live_findings_register_and_reporting_status": True,
            "application_source_or_tests": False,
            "application_runtime_reexecution": False,
            "application_browser": False,
            "static_route_feature_ownership": False,
            "static_controller_action_bridge": False,
            "supporting_data_route_or_page_ownership": False,
            "queue_advance": False,
            "benchmark_mapping": False,
            "final_no_match_or_NCM": False,
            "ease": False, "pass": False, "release": False,
            "publication": False, "final_finding": False,
            "completion": False, "audit_complete": False,
        },
        "completion_gates": completion_boundary(),
        "completion_boundary": completion_boundary(),
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [OUTPUT_REL],
    }
    assert len(receipt["completion_gates"]) == 26
    assert not any(receipt["completion_gates"].values())
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    return receipt


def main() -> None:
    validate_repository()
    validate_lineage()
    baseline, current, baseline_hashes = validate_findings()
    manifest = validate_reporting_surfaces()
    receipt = build_receipt(baseline, current, baseline_hashes, manifest)
    output = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    path = AUDIT / OUTPUT_REL
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(output)
    assert path.read_bytes() == output
    verify_self_seal(read_json(OUTPUT_REL), receipt["receipt_self_seal_sha256"])
    validate_repository()
    print(json.dumps({
        "status": "GO",
        "materializer_sha256": file_record(SCRIPT_REL)["sha256"],
        "receipt_sha256": sha256(output),
        "receipt_self_seal_sha256": receipt["receipt_self_seal_sha256"],
        "dashboard_sha256_unchanged": FROZEN_HTML_SHA256,
        "reporting_paths": len(REPORTING_SURFACES),
        "exact_dirty_paths": 10,
    }, sort_keys=True))


if __name__ == "__main__":
    main()
