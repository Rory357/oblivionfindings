from __future__ import annotations

import ast
import hashlib
import json
import os
import subprocess
from pathlib import Path
from typing import Any


SCRIPT_PATH = Path(__file__).resolve()
AUDIT_DIR = SCRIPT_PATH.parent.parent
REPO_ROOT = next(parent for parent in SCRIPT_PATH.parents if (parent / ".git").exists())
AUDIT_PREFIX = AUDIT_DIR.relative_to(REPO_ROOT).as_posix() + "/"
SCRIPT_REL = SCRIPT_PATH.relative_to(AUDIT_DIR).as_posix()
OUTPUT_REL = "evidence/source/current-run-171-reviewed-fleet-vehicle-alerts-config-route-action-reporting-wave-31.json"
OUTPUT_PATH = AUDIT_DIR / OUTPUT_REL
RUN_172_SCRIPT_REL = "generators/materialize-run-172-audit-dashboard-verification-wave-31.py"

REPORTING_INPUT_COMMIT = "ca1c53bc3062a6fe81f2855716de13636d59ac0c"
REPORTING_INPUT_TREE = "f29a9fce70f5c6ed9b251560fda58be976b062df"
REPORTING_INPUT_PARENT = "2084ca83fe8d18f145197867d3bbf73b731800c7"
APPLICATION_COMMIT = "e488bd3edcda0f154f87e8bbed972f14db409b82"
APPLICATION_TREE = "9e93b8aea4f4b907cde3dc59dd0520fba5bd7080"
RUN_168_DASHBOARD_SHA256 = "80360ae152642e4f7c0c90b18c42e76fb156bf8cd34eb9df17b358170cc71b89"
RUN_172_DRAFT_SHA256 = "1f2bd52237f28cb11f79e4fa65d1f0a82889fd313fbee08d4e222816a7147139"

REPORTING_SURFACES = (
    "00-executive-summary.md",
    "01-repository-module-map.md",
    "13-unresolved-questions-and-evidence-gaps.md",
    "findings.json",
    "generators/build-current-audit-dashboard.py",
)

BASELINE_SHA256 = {
    "00-executive-summary.md": "cbbc7e42a6eb4fbf780e3bcbf7a46fcff825397dae03f2e79f21eea1a5dc95dc",
    "01-repository-module-map.md": "3cb552fcb443067f9108f448a5ec568000dfc4f262f612b8eb5c7838ba178d17",
    "13-unresolved-questions-and-evidence-gaps.md": "a002104faf2d8c4c5528a1ffb02c939d09bf29d15f9fa5c56a3196c99b4ee782",
    "findings.json": "46145cd8813d6974f3c9069266e0dd11ce3e17f69d20ba3d389f218d452ffe39",
    "generators/build-current-audit-dashboard.py": "772abb4ed6d7c16af18dd65e94338c1090d9174fcbe733343da41e5017e31357",
}

CURRENT_SHA256 = {
    "00-executive-summary.md": "59843dee06ee879751432a2b8b4629998c8a5fcc6d710461301b4f474844b0de",
    "01-repository-module-map.md": "246f505781b1a6f4c674e4a0be537139f0d5ee9282bb04a482712ce353f2850a",
    "13-unresolved-questions-and-evidence-gaps.md": "f077b74b4d8cc9d01859e8a10792d48a1d22135a3c0210ac45091aac6858426d",
    "findings.json": "52c92b9ff84f7769062b7eeeb9d0ca4d9190f3d8e57a6d7bcbeea2155f6cffee",
    "generators/build-current-audit-dashboard.py": "f171941c116af15547aecb678e6ebd442d1681c91662645ed1cf7cc2d7f8bbfc",
}

ARTIFACT_PINS = {
    "generators/materialize-run-168-audit-dashboard-verification-wave-30.py": (
        "27f77f21f1aaac195ae0ca901b4f207129fb20666fe5b8e4eccea1d2e2cf56db",
        "66815faeb87e8491ae023145944b19c3528dfece",
        34966,
        739,
    ),
    "evidence/browser/current-audit-dashboard-verification-run-168-wave-30.json": (
        "95f5eff21563ff010cd49f2ff6cf958825f1d1f7717066ed571e9e078dea4998",
        "fa475913e5337f4bb8bbb8eedf7e7aba8041e5fd",
        18321,
        460,
    ),
    "generators/build-outcome-neutral-fleet-vehicle-alerts-config-route-action-cohort-wave-31.py": (
        "ffb1dba865a50f3cdcbf4e3ce285482e062bb023145089353a68f705d0646c7e",
        "0199cbc6044817f4484fa7ce4824d0dcff1bd9cf",
        18240,
        227,
    ),
    "evidence/source/root-run-169-outcome-neutral-fleet-vehicle-alerts-config-route-action-cohort-wave-31.json": (
        "2fc20f6e528adae64979a763e6f28dd86018c2ecd87bbb0b651ddf6eee158fb2",
        "e7e08a7a0232f9691fc48bb46f84770f9bb595dc",
        21103,
        396,
    ),
    "generators/materialize-independent-outcome-neutral-fleet-vehicle-alerts-config-route-action-review-wave-31.py": (
        "6cdceb0f2b25a33fba8675f614f61a2aa5692dfb0a02768887755dd8fdfa4687",
        "f848b03deca71e3bf3d5a2041227e06d2a83fb02",
        27917,
        397,
    ),
    "evidence/source/raw-run-169r-independent-outcome-neutral-fleet-vehicle-alerts-config-route-action-review-wave-31.json": (
        "698257a0e6543d685397977d658d9681281ce6634f709ced73939c09e76f02bc",
        "7a6f7d4a6967462e27f1811ba3d64d4aaae1422b",
        23685,
        371,
    ),
    "generators/integrate-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.py": (
        "c732926f3112c987fbaaf3f398bc18b3d25027c7f1495c38016237a5cb6f28a3",
        "2603b130a0a674e6803413583c95b51bc3f83545",
        28451,
        434,
    ),
    "evidence/source/current-run-170-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.json": (
        "c739a36e1975b60d42988be3de36b9fe1ea88cf942752c90112f40ebaa04cd8d",
        "8cff90e1e86e5752cbfc3e59d03ccc5423e23ed6",
        35732,
        548,
    ),
    "generators/materialize-independent-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-review-wave-31.py": (
        "752d64a1fa5ef6260feff84db3698b87a34170dd4fd6afbad6f2f54f1f1a814e",
        "f7122a4eb39e2d92aa3786334fe9377a1cb1e325",
        25325,
        307,
    ),
    "evidence/source/current-run-170r-independent-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-review-wave-31.json": (
        "62474100b0c2f027fa0c15f2bb841f08ad3de058da67725a931fcafec17dd139",
        "fbcccd7e19ea57db52a1d6ca462aa107107159d1",
        27956,
        380,
    ),
}

PRESERVED_PATHS = (
    "02-eight-pass-coverage-ledger.csv",
    "03-feature-to-benchmark-matrix.csv",
    "04-workflow-usability-scorecard.csv",
    "05-browser-visual-coverage-matrix.csv",
    "06-open-source-benchmark-register.csv",
    "07-module-findings.md",
    "08-cross-module-journeys.md",
    "09-ui-ux-accessibility-visual-consistency.md",
    "10-architecture-data-integration-security.md",
    "11-prioritised-roadmap.md",
    "12-native-build-and-do-not-copy-register.md",
    "audit-dashboard.html",
    "inventory.json",
)

REPORTING_COUNTS = {
    "source_owner_records": 665,
    "route_owner_records": 308,
    "page_owner_records": 357,
    "distinct_feature_ids": 256,
    "distinct_H_feature_ids": 234,
    "distinct_D_feature_ids": 22,
    "route_distinct_feature_ids": 64,
    "page_distinct_feature_ids": 242,
    "route_page_feature_overlap": 50,
    "static_controller_action_bridges": 96,
    "bounded_static_source_denominator": 3929,
    "bounded_static_source_ownership_percent": "16.925426",
    "bounded_static_source_residual_records": 3264,
    "residual_explicit_unmapped_routes": 2893,
}

QUEUE_COUNTS = {
    "direct_exact_queue_records": 507,
    "reviewed_queue_surface_rows": 119,
    "owner_queue_surface_rows": 97,
    "shared_queue_surface_rows": 10,
    "alias_queue_surface_rows": 5,
    "dead_queue_surface_rows": 0,
    "evidence_gap_queue_surface_rows": 7,
    "pending_unreviewed_queue_surface_rows": 388,
    "queue_surfaces_without_ownership": 410,
}

QUEUE_BOUNDARY = {
    "selected_index_83_integrated": True,
    "next_unresolved_index": 84,
    "next_unresolved_queue_id": "RUN090-ROUTE-0085",
    "next_unresolved_route_record_id": "RUN077-ROUTE-0693",
    "next_unresolved_route_name": "fleet-assets.trips.index",
    "next_unresolved_action_expression": "[VehicleController::class, 'trips']",
    "next_unresolved_queue_record_sha256": "928eeec741742f8329dd7e191a71f2d5249775b6de64e6a698a72836345ca011",
    "reviewed_key_count": 119,
    "reviewed_key_list_sha256": "acfca5e54d64c54334dbd94b30104244b3d2d6722a5426439aec7a8aa62d3ab5",
    "reviewed_key_list_canonical_json_sha256": "e85b37e5410c1cc861f9116061e88fb82fdb854e5dc94e56eefe1947b3a7b510",
}


def duplicate_rejecting_pairs(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    value: dict[str, Any] = {}
    for key, item in pairs:
        assert key not in value, f"Duplicate JSON key: {key}"
        value[key] = item
    return value


def strict_json_bytes(raw: bytes, label: str) -> dict[str, Any]:
    assert not raw.startswith(b"\xef\xbb\xbf"), f"BOM not allowed: {label}"
    assert b"\r" not in raw, f"CR not allowed: {label}"
    assert raw.endswith(b"\n"), f"Final LF required: {label}"
    for line_number, line in enumerate(raw.splitlines(), start=1):
        assert line == line.rstrip(b" \t"), f"Trailing whitespace: {label}:{line_number}"
    value = json.loads(raw.decode("utf-8"), object_pairs_hook=duplicate_rejecting_pairs)
    assert isinstance(value, dict), f"JSON object required: {label}"
    expected = (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    assert raw == expected, f"Exact pretty-JSON round trip failed: {label}"
    return value


def read_json(relative: str) -> dict[str, Any]:
    return strict_json_bytes((AUDIT_DIR / relative).read_bytes(), relative)


def git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args],
        cwd=REPO_ROOT,
        check=True,
        capture_output=True,
        text=True,
        encoding="utf-8",
    )
    return result.stdout.rstrip()


def git_show_bytes(commit: str, relative: str) -> bytes:
    repository_relative = f"{AUDIT_PREFIX}{relative}"
    return subprocess.run(
        ["git", "show", f"{commit}:{repository_relative}"],
        cwd=REPO_ROOT,
        check=True,
        capture_output=True,
    ).stdout


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(relative: str) -> str:
    return sha256_bytes((AUDIT_DIR / relative).read_bytes())


def canonical_sha256(value: Any) -> str:
    raw = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return sha256_bytes(raw)


def metrics(relative: str) -> dict[str, Any]:
    raw = (AUDIT_DIR / relative).read_bytes()
    assert not raw.startswith(b"\xef\xbb\xbf"), relative
    assert b"\r" not in raw, relative
    assert raw.endswith(b"\n"), relative
    for line_number, line in enumerate(raw.splitlines(), start=1):
        assert line == line.rstrip(b" \t"), f"Trailing whitespace: {relative}:{line_number}"
    repository_relative = f"{AUDIT_PREFIX}{relative}"
    return {
        "path": relative,
        "sha256": sha256_bytes(raw),
        "git_blob_id": git("hash-object", "--", repository_relative),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


def verify_receipt_self_seal(payload: dict[str, Any], expected: str) -> None:
    without_seal = dict(payload)
    seal = without_seal.pop("receipt_self_seal_sha256")
    assert canonical_sha256(without_seal) == seal == expected


def verify_nested_self_seal(payload: dict[str, Any], expected: str) -> None:
    without_seal = dict(payload)
    seal = without_seal.pop("self_seal")
    assert seal == {
        "algorithm": "sha256-canonical-json-with-self-seal-omitted",
        "sha256": expected,
    }
    assert canonical_sha256(without_seal) == expected


assert git("rev-parse", "HEAD") == REPORTING_INPUT_COMMIT
assert git("show", "-s", "--format=%T", "HEAD") == REPORTING_INPUT_TREE
assert git("rev-parse", "HEAD^") == REPORTING_INPUT_PARENT
assert git("branch", "--show-current") == "main"
assert git("rev-parse", "main") == REPORTING_INPUT_COMMIT
assert git("rev-parse", "origin/main") == REPORTING_INPUT_COMMIT
assert git("diff", "--cached", "--name-only") == ""

expected_dirty_without_receipt = sorted(
    [f" M {AUDIT_PREFIX}{path}" for path in REPORTING_SURFACES]
    + [f"?? {AUDIT_PREFIX}{SCRIPT_REL}", f"?? {AUDIT_PREFIX}{RUN_172_SCRIPT_REL}"]
)
expected_dirty_with_receipt = sorted(expected_dirty_without_receipt + [f"?? {AUDIT_PREFIX}{OUTPUT_REL}"])
dirty = sorted(
    line
    for line in git("status", "--porcelain=v1", "--untracked-files=all").splitlines()
    if line
)
assert dirty in (expected_dirty_without_receipt, expected_dirty_with_receipt), dirty
assert git("diff", "--check") == ""
assert sha256_file(RUN_172_SCRIPT_REL) == RUN_172_DRAFT_SHA256

for relative, expected_sha256 in BASELINE_SHA256.items():
    raw = git_show_bytes(REPORTING_INPUT_COMMIT, relative)
    assert sha256_bytes(raw) == expected_sha256, relative
for relative, expected_sha256 in CURRENT_SHA256.items():
    assert sha256_file(relative) == expected_sha256, relative
for relative, (expected_sha256, expected_blob, expected_bytes, expected_lines) in ARTIFACT_PINS.items():
    actual = metrics(relative)
    assert actual["sha256"] == expected_sha256, relative
    assert actual["git_blob_id"] == expected_blob, relative
    assert actual["bytes"] == expected_bytes, relative
    assert actual["lines"] == expected_lines, relative

preserved_manifest = []
for relative in PRESERVED_PATHS:
    current = (AUDIT_DIR / relative).read_bytes()
    assert current == git_show_bytes(REPORTING_INPUT_COMMIT, relative), relative
    preserved_manifest.append(metrics(relative))
assert sha256_file("audit-dashboard.html") == RUN_168_DASHBOARD_SHA256

run_168 = read_json("evidence/browser/current-audit-dashboard-verification-run-168-wave-30.json")
run_169 = read_json("evidence/source/root-run-169-outcome-neutral-fleet-vehicle-alerts-config-route-action-cohort-wave-31.json")
run_169r = read_json("evidence/source/raw-run-169r-independent-outcome-neutral-fleet-vehicle-alerts-config-route-action-review-wave-31.json")
run_170 = read_json("evidence/source/current-run-170-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.json")
run_170r = read_json("evidence/source/current-run-170r-independent-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-review-wave-31.json")

assert run_168["run_id"] == "RUN-168-AUDIT-DASHBOARD-VERIFICATION-WAVE-30"
assert run_168["verification"]["viewports_verified"] == run_168["verification"]["viewports_required"] == 4
assert run_168["verification"]["visible_static_checks_passed"] == 39
assert run_168["verification"]["navigation_clicks_required"] == 10
assert run_168["verification"]["navigation_clicks_passed"] == 10
assert len(run_168["verification"]["navigation_results"]) == 10
assert all(row["pass"] for row in run_168["verification"]["navigation_results"])
assert run_168["verification"]["post_materialization_local_resources"] == "414/414"
assert run_168["pins"]["run_168_dashboard"]["sha256"] == RUN_168_DASHBOARD_SHA256
assert {key for key, value in run_168["credit_boundary"].items() if value} == {
    "audit_dashboard_run_168_builder_idempotence_correction",
    "exact_audit_dashboard_artifact",
}
verify_receipt_self_seal(run_168, "252e6317bffc66e7b07c98f10dd2968c73c4f95317b3dd6d45d5a9c4f9285726")

assert run_169["run_id"] == "RUN-169-OUTCOME-NEUTRAL-FLEET-VEHICLE-ALERTS-CONFIG-ROUTE-ACTION-COHORT-WAVE-31"
assert run_169["pins"]["application_commit"] == APPLICATION_COMMIT
assert run_169["pins"]["application_tree"] == APPLICATION_TREE
assert run_169["selection_contract"]["selected_queue_indices_zero_based"] == [83]
assert run_169["selection_contract"]["ownership_decisions_authored"] == 0
verify_nested_self_seal(run_169, "f36a58efc9e6e6c129d795c645bdc6ebb294a63ccbfcdc69ab832a9e5709b6aa")

assert run_169r["run_id"] == "RUN-169R-INDEPENDENT-OUTCOME-NEUTRAL-FLEET-VEHICLE-ALERTS-CONFIG-ROUTE-ACTION-REVIEW-WAVE-31"
assert run_169r["decision"] == "GO"
assert len(run_169r["provisional_assurance_observations"]) == 3
assert {key for key, value in run_169r["credit_boundary"].items() if value} == {
    "bounded_overlay_integration_authorized_later_only",
    "reviewed_static_controller_action_bridge_for_1_action",
    "reviewed_static_route_feature_ownership_for_1_record",
}
verify_nested_self_seal(run_169r, "cf470be7cdd7a8da9aec761907f114d5f675bba67bbaf4e649bbe1c2a80892ed")

assert run_170["run_id"] == "RUN-170-REVIEWED-OUTCOME-NEUTRAL-FLEET-VEHICLE-ALERTS-CONFIG-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-31"
assert {key: run_170["combined_counts"][key] for key in REPORTING_COUNTS} == REPORTING_COUNTS
assert {key: run_170["queue_accounting"][key] for key in QUEUE_COUNTS} == QUEUE_COUNTS
assert {key: run_170["queue_boundary"][key] for key in QUEUE_BOUNDARY} == QUEUE_BOUNDARY
assert run_170["reviewed_overlay"]["accepted_route_owner_records"] == 1
assert run_170["reviewed_overlay"]["accepted_page_owner_records"] == 0
assert run_170["reviewed_overlay"]["accepted_controller_action_bridges"] == 1
assert run_170["reviewed_overlay"]["new_distinct_feature_ids"] == 0
assert run_170["provisional_assurance_observation_preservation"]["observation_count"] == 3
assert {key for key, value in run_170["credit_boundary"].items() if value} == {
    "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION",
    "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD",
}
verify_nested_self_seal(run_170, "da13b662f3ad154256bb6b1aa59861148fafe83bbdfb394df9efd1b2b77aefa1")

assert run_170r["run_id"] == "RUN-170R-INDEPENDENT-REVIEWED-OUTCOME-NEUTRAL-FLEET-VEHICLE-ALERTS-CONFIG-ROUTE-ACTION-OWNERSHIP-OVERLAY-REVIEW-WAVE-31"
assert run_170r["decision"]["verdict"] == "GO"
assert run_170r["decision"]["independent_reviews"] == 3
assert run_170r["decision"]["discrepancies"] == 0
assert run_170r["decision"]["reporting_materialization_authorized"] is True
assert run_170r["reporting_materialization_authorized"] is True
assert {key for key, value in run_170r["credit_boundary"].items() if value} == {
    "INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"
}
verify_nested_self_seal(run_170r, "1093221990cba9752cb48ea45343eb3f29a90c2507d120c9c6d08b8181c8edb8")
for payload in (run_168, run_169, run_169r, run_170, run_170r):
    assert all(value is False for value in payload["completion_boundary"].values())

baseline_findings_raw = git_show_bytes(REPORTING_INPUT_COMMIT, "findings.json")
baseline_findings = strict_json_bytes(baseline_findings_raw, f"{REPORTING_INPUT_COMMIT}:findings.json")
findings = read_json("findings.json")
assert baseline_findings["records"] == findings["records"]
assert len(findings["records"]) == len({row["id"] for row in findings["records"]}) == 12
assert findings["historical_run_153_static_source_feature_ownership"] == baseline_findings["current_static_source_feature_ownership"]
assert findings["historical_run_153_provisional_source_observations"] == baseline_findings["current_provisional_source_observations"]
assert findings["historical_run_153_outcome_neutral_fleet_vehicle_register_index_route_action_ownership_review"] == baseline_findings["current_outcome_neutral_fleet_vehicle_register_index_route_action_ownership_review"]
assert "current_outcome_neutral_fleet_vehicle_register_index_route_action_ownership_review" not in findings

counts = findings["counts"]
expected_count_projection = {
    "static_source_feature_ownership_records": 665,
    "static_source_feature_ownership_route_records": 308,
    "static_source_feature_ownership_page_records": 357,
    "static_source_feature_ownership_distinct_feature_ids": 256,
    "static_source_feature_ownership_distinct_H_feature_ids": 234,
    "static_source_feature_ownership_distinct_D_feature_ids": 22,
    "static_source_feature_ownership_route_distinct_feature_ids": 64,
    "static_source_feature_ownership_page_distinct_feature_ids": 242,
    "static_source_feature_ownership_route_page_feature_overlap": 50,
    "static_controller_action_bridges": 96,
    "bounded_static_source_ownership_percent": "16.925426",
    "bounded_static_source_residual_records": 3264,
    "bounded_static_source_residual_route_records": 2893,
    "direct_exact_queue_records": 507,
    "direct_exact_queue_reviewed": 119,
    "direct_exact_queue_owned": 97,
    "direct_exact_queue_shared": 10,
    "direct_exact_queue_alias": 5,
    "direct_exact_queue_dead_or_noncanonical": 0,
    "direct_exact_queue_evidence_gap": 7,
    "direct_exact_queue_pending_unreviewed": 388,
    "direct_exact_queue_without_ownership": 410,
}
assert {key: counts[key] for key in expected_count_projection} == expected_count_projection
assert counts["retained_claim_records"] == 12
assert counts["provisional_source_claims"] == counts["provisional_P1"] == 9
assert counts["historical_already_fixed"] == 2
assert counts["historical_remediated"] == 1
assert counts["final_P0"] == counts["final_P1"] == 0
assert counts["benchmark_mapped"] == 2
assert counts["final_no_match"] == 0
assert counts["benchmark_unresolved"] == 338

current_ownership = findings["current_static_source_feature_ownership"]
assert {key: current_ownership["combined_counts"][key] for key in REPORTING_COUNTS} == REPORTING_COUNTS
assert {key: current_ownership["queue_accounting"][key] for key in QUEUE_COUNTS} == QUEUE_COUNTS
assert {key: current_ownership["queue_boundary"][key] for key in QUEUE_BOUNDARY} == QUEUE_BOUNDARY
assert current_ownership["reviewed_overlay"] == {
    "queue_index_zero_based": 83,
    "queue_id": "RUN090-ROUTE-0084",
    "route_record_id": "RUN077-ROUTE-0692",
    "route_name": "fleet-assets.vehicles.alerts-config",
    "controller_action": "VehicleController::alertsConfig",
    "feature_id": "CAP-FLEET-VEHICLE-REGISTER",
    "accepted_source_owner_records": 1,
    "accepted_route_owner_records": 1,
    "accepted_page_owner_records": 0,
    "accepted_controller_action_bridges": 1,
    "new_distinct_feature_ids": 0,
}
assert {key for key, value in current_ownership["credit_boundary"].items() if value} == {
    "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION",
    "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD",
}
current_observations = findings["current_provisional_source_observations"]
assert current_observations["observation_count"] == 3
assert current_observations["observations"] == run_170["provisional_assurance_observation_preservation"]["observations"]
assert current_observations["correctness_credit_authorized"] is False
assert current_observations["final_finding_credit_authorized"] is False
current_review = findings["current_outcome_neutral_fleet_vehicle_alerts_config_route_action_ownership_review"]
assert current_review["decision"]["verdict"] == "GO"
assert current_review["decision"]["independent_reviews"] == 3
assert current_review["decision"]["discrepancies"] == 0
assert {key for key, value in current_review["credit_boundary"].items() if value} == {
    "INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"
}
assert findings["current_direct_exact_route_page_review_queue"]["reviewed_queue_surfaces"] == 119
assert findings["current_direct_exact_route_page_review_queue"]["pending_unreviewed"] == 388
assert findings["current_direct_exact_route_page_review_queue"]["without_ownership"] == 410
assert findings["current_audit_artifact_verification_history"]["run_168"]["dashboard_sha256"] == RUN_168_DASHBOARD_SHA256
assert findings["current_audit_artifact_verification_history"]["run_168"]["superseded_by_run_171_dashboard"] is True
assert all(row["completion_credit"] is False for row in findings["records"])
assert all(all(value is False for value in row["credit"].values()) for row in findings["records"])

required_report_phrases = {
    "00-executive-summary.md": (
        "665 records = 308 routes + 357 pages",
        "RUN-168–171 Fleet alerts-config ownership checkpoint",
        "119 reviewed / 388 pending",
        "RUN-171 alone reports",
        "fresh RUN-172 verification",
    ),
    "01-repository-module-map.md": (
        "RUN-113–171 reviewed route/action",
        "665 source owners (308 route + 357 page)",
        "queue index 83",
        "fleet-assets.trips.index",
    ),
    "13-unresolved-questions-and-evidence-gaps.md": (
        "RUN-170/R preserve 308 bounded route-owner records",
        "RUN-170/R preserve 357 bounded page owners",
        "RUN-171 alone",
        "RUN090-ROUTE-0085",
    ),
}
for relative, required in required_report_phrases.items():
    text = (AUDIT_DIR / relative).read_text(encoding="utf-8")
    for phrase in required:
        assert phrase in text, (relative, phrase)
reporting_text = "\n".join((AUDIT_DIR / path).read_text(encoding="utf-8") for path in REPORTING_SURFACES)
for prohibited in (
    "The current bounded checkpoint is **664 records",
    "queue index 83 remains unresolved",
    "RUN-171 supplies correctness credit",
    "RUN-171 completes Gate 4",
):
    assert prohibited not in reporting_text, prohibited

builder_path = AUDIT_DIR / "generators/build-current-audit-dashboard.py"
builder_source = builder_path.read_text(encoding="utf-8")
ast.parse(builder_source, filename=str(builder_path))
compile(builder_source, str(builder_path), "exec")
for required in (
    'read_json_strict("evidence/source/current-run-171-reviewed-fleet-vehicle-alerts-config-route-action-reporting-wave-31.json")',
    "RUN-171-REVIEWED-FLEET-VEHICLE-ALERTS-CONFIG-ROUTE-ACTION-REPORTING-WAVE-31",
    "audit_dashboard_run_168_builder_idempotence_correction",
    "run_171_template_rewrites",
    "Fresh RUN-172 audit-dashboard verification required",
    "tmp-run171-dashboard",
):
    assert required in builder_source, required

reporting_manifest = [metrics(path) for path in REPORTING_SURFACES]
receipt: dict[str, Any] = {
    "schema_version": "run-171-reviewed-fleet-vehicle-alerts-config-route-action-reporting-wave-31-v1",
    "run_id": "RUN-171-REVIEWED-FLEET-VEHICLE-ALERTS-CONFIG-ROUTE-ACTION-REPORTING-WAVE-31",
    "status": "FLEET_VEHICLE_ALERTS_CONFIG_REVIEWED_OWNERSHIP_REPORTING_MATERIALIZED_DASHBOARD_RUN172_REQUIRED_ZERO_CORRECTNESS_OR_COMPLETION_CREDIT",
    "materialized_on": "2026-08-30",
    "architecture_rule": {
        "operating_organisations": 1,
        "multiple_sites": True,
        "multi_tenant": False,
        "authorization_boundary": "Site access, exact action permissions, canonical ownership, privacy, and direct-object denial",
    },
    "pins": {
        "reporting_input_commit": REPORTING_INPUT_COMMIT,
        "reporting_input_tree": REPORTING_INPUT_TREE,
        "reporting_input_parent": REPORTING_INPUT_PARENT,
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
        "run_168_materializer_sha256": ARTIFACT_PINS["generators/materialize-run-168-audit-dashboard-verification-wave-30.py"][0],
        "run_168_receipt_sha256": ARTIFACT_PINS["evidence/browser/current-audit-dashboard-verification-run-168-wave-30.json"][0],
        "run_169_generator_sha256": ARTIFACT_PINS["generators/build-outcome-neutral-fleet-vehicle-alerts-config-route-action-cohort-wave-31.py"][0],
        "run_169_receipt_sha256": ARTIFACT_PINS["evidence/source/root-run-169-outcome-neutral-fleet-vehicle-alerts-config-route-action-cohort-wave-31.json"][0],
        "run_169r_materializer_sha256": ARTIFACT_PINS["generators/materialize-independent-outcome-neutral-fleet-vehicle-alerts-config-route-action-review-wave-31.py"][0],
        "run_169r_receipt_sha256": ARTIFACT_PINS["evidence/source/raw-run-169r-independent-outcome-neutral-fleet-vehicle-alerts-config-route-action-review-wave-31.json"][0],
        "run_170_generator_sha256": ARTIFACT_PINS["generators/integrate-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.py"][0],
        "run_170_receipt_sha256": ARTIFACT_PINS["evidence/source/current-run-170-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.json"][0],
        "run_170r_materializer_sha256": ARTIFACT_PINS["generators/materialize-independent-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-review-wave-31.py"][0],
        "run_170r_receipt_sha256": ARTIFACT_PINS["evidence/source/current-run-170r-independent-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-review-wave-31.json"][0],
        "reporting_materializer": metrics(SCRIPT_REL),
        "run_172_unexecuted_draft": metrics(RUN_172_SCRIPT_REL),
        "baseline_findings_sha256": BASELINE_SHA256["findings.json"],
        "current_findings": metrics("findings.json"),
        "dashboard_generator": metrics("generators/build-current-audit-dashboard.py"),
        "unchanged_run_168_dashboard": metrics("audit-dashboard.html"),
    },
    "lineage_roles": {
        "run_168": "verifies only the exact now-superseded RUN-167 audit dashboard",
        "run_169": "selects one outcome-neutral queue row and authors no ownership decision",
        "run_169r": "independently authorizes one bounded static route owner and bridge for later integration",
        "run_170": "integrates exactly one route owner and one controller-action bridge",
        "run_170r": "records three sealed GO reviews and authorizes reporting only",
        "run_171": "alone changes live ownership and queue reporting surfaces",
        "run_172": "required fresh dashboard rebuild and four-viewport exact-artifact verification",
    },
    "reporting_snapshot": {
        "combined_counts": REPORTING_COUNTS,
        "queue_accounting": QUEUE_COUNTS,
    },
    "queue_boundary": QUEUE_BOUNDARY,
    "provisional_source_observations": {
        "count": 3,
        "observation_ids": [row["observation_id"] for row in current_observations["observations"]],
        "status": "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING",
        "correctness_credit_authorized": False,
        "final_finding_credit_authorized": False,
        "separate_from_retained_finding_records": True,
    },
    "findings_boundary": {
        "retained_claim_records": 12,
        "current_provisional": 9,
        "historical_already_fixed": 2,
        "historical_remediated": 1,
        "final_P0": 0,
        "final_P1": 0,
    },
    "benchmark_boundary": {
        "mapped": 2,
        "final_no_match_or_NCM": 0,
        "unresolved": 338,
    },
    "reporting_manifest": reporting_manifest,
    "preservation_boundary": {
        "paths": preserved_manifest,
        "all_preserved_byte_identical_to_reporting_input_commit": True,
        "dashboard_sha256_unchanged": RUN_168_DASHBOARD_SHA256,
        "findings_records_unchanged": True,
        "application_routes_resources_tests_database_unchanged": True,
        "run_172_draft_unexecuted_and_preserved_sha256": RUN_172_DRAFT_SHA256,
    },
    "dashboard_forward_gate": {
        "required_run": "RUN-172",
        "dashboard_html_changed_by_run_171": False,
        "unchanged_dashboard_sha256": RUN_168_DASHBOARD_SHA256,
        "fresh_four_viewport_verification_required": True,
        "required_viewports": ["1440x900", "1280x800", "1024x768", "390x844"],
        "future_receipt_link_is_unhashed_to_avoid_cycle": True,
    },
    "publication_boundary": {
        "run_171_commit_or_push_performed_by_materializer": False,
        "application_fix_merged_or_pushed": False,
        "safe_alert_remediation_files_owned_by_separate_task": True,
    },
    "noninheritance_boundary": {
        "page_or_new_feature_ownership": False,
        "consumer_caller_service_or_model_ownership": False,
        "framework_route_reachability_or_selected_get_execution": False,
        "approved_site_permission_privacy_or_direct_object_correctness": False,
        "application_source_tests_runtime_or_browser": False,
        "benchmark_or_final_no_match_NCM": False,
        "final_finding": False,
        "pass_feature_gate_4_or_audit_completion": False,
    },
    "credit_boundary": {
        "live_static_ownership_and_queue_reporting": True,
        "new_static_ownership": False,
        "correctness": False,
        "application_source_or_tests": False,
        "runtime": False,
        "application_browser": False,
        "benchmark_mapping": False,
        "final_no_match_or_NCM": False,
        "final_finding": False,
        "feature_completion": False,
        "gate_4": False,
        "audit_complete": False,
    },
    "completion_boundary": {
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
    },
    "artifact_completion_test_met": True,
    "audit_completion_test_met": False,
    "wrote_files": [OUTPUT_REL],
}
receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
output_bytes = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")

OUTPUT_PATH.parent.mkdir(parents=True, exist_ok=True)
temporary_path = OUTPUT_PATH.with_name(f".{OUTPUT_PATH.name}.tmp-run171")
assert not temporary_path.exists(), f"Refusing stale temp file: {temporary_path}"
try:
    with temporary_path.open("xb") as handle:
        handle.write(output_bytes)
        handle.flush()
        os.fsync(handle.fileno())
    assert temporary_path.read_bytes() == output_bytes
    os.replace(temporary_path, OUTPUT_PATH)
finally:
    if temporary_path.exists():
        temporary_path.unlink()

assert OUTPUT_PATH.read_bytes() == output_bytes
written = strict_json_bytes(OUTPUT_PATH.read_bytes(), OUTPUT_REL)
without_seal = dict(written)
written_seal = without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(without_seal) == written_seal
print(json.dumps({
    "run_id": written["run_id"],
    "status": written["status"],
    "receipt_sha256": sha256_bytes(output_bytes),
    "receipt_self_seal_sha256": written_seal,
    "reporting_surfaces": len(REPORTING_SURFACES),
    "dashboard_sha256_unchanged": RUN_168_DASHBOARD_SHA256,
}, ensure_ascii=False, sort_keys=True))
