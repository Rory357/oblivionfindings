from __future__ import annotations

import ast
import hashlib
import json
import math
import os
import subprocess
from pathlib import Path
from typing import Any


SCRIPT_PATH = Path(__file__).resolve()
AUDIT_DIR = SCRIPT_PATH.parent.parent
REPO_ROOT = next(parent for parent in SCRIPT_PATH.parents if (parent / ".git").exists())
AUDIT_PREFIX = AUDIT_DIR.relative_to(REPO_ROOT).as_posix() + "/"
SCRIPT_REL = SCRIPT_PATH.relative_to(AUDIT_DIR).as_posix()
OUTPUT_REL = "evidence/source/current-run-181-reviewed-fleet-trip-index-route-action-reporting-wave-34.json"
OUTPUT_PATH = AUDIT_DIR / OUTPUT_REL

REPORTING_INPUT_COMMIT = "673d2aadd477e6fa265e62aacad19273cb21122a"
REPORTING_INPUT_TREE = "54e63a9339746e399b4ab57958c3650b08cb66e3"
REPORTING_INPUT_PARENT = "e6dd903e2374ebccbd34adf1c2c483905643ae36"
APPLICATION_COMMIT = "f40e3d63ea99d774265ff9f2eefef8176ab0cbc7"
APPLICATION_TREE = "880721d56b7d379abf9628abb22a5a9b9445194b"
ORIGIN_MAIN_OBSERVED_WITHOUT_REFETCH = "c39b076547056b1e158c604957a04bd8b75b0f29"
GOVERNING_PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
CONTINUATION_REQUEST_SHA256 = "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d"
RUN_178_DASHBOARD_SHA256 = "70472c39504600f8c0b26b9ce05eb0f3e5903f1c6e9445163dba0581a2382600"

REPORTING_SURFACES = (
    "00-executive-summary.md",
    "01-repository-module-map.md",
    "13-unresolved-questions-and-evidence-gaps.md",
    "findings.json",
    "generators/build-current-audit-dashboard.py",
)

SEVEN_PATH_SCOPE = (*REPORTING_SURFACES, SCRIPT_REL, OUTPUT_REL)

BASELINE_SHA256 = {
    "00-executive-summary.md": "c9693530540827ee0ec5739e9e67cdbd573ea7db2b861ce93965fb3a68f798d4",
    "01-repository-module-map.md": "daa25f18cbbb5c5a93ed073e4f7b980a2de888dbeb233d30999e47d33f7e9e67",
    "13-unresolved-questions-and-evidence-gaps.md": "f66b493d4a8357571f55d1f6835d79482f9bd263c1f94d1c075f9eb1d8d0c96c",
    "findings.json": "43f583acac13c4e60fdd942e0f5ab0ee188dd9a5905371fedc18f6fddee833a0",
    "generators/build-current-audit-dashboard.py": "2e23eb4838a7ab4ddac1576c750fa56dcfd4b3828217bad003bf7aadec73dd96",
}

CURRENT_SHA256 = {
    "00-executive-summary.md": "919afc23d9675a0201016a294a7c4c144fa23a7821d1941e39ca9b287685680d",
    "01-repository-module-map.md": "0362a4715ca2f63ab2d57ea238a921f87d39a052e34c12d102accced91625883",
    "13-unresolved-questions-and-evidence-gaps.md": "bf542800f5a706846a402754ea2a3a899d8a957b28973543c449903d0f0991ac",
    "findings.json": "55337abfc8f2fe9fde863715e3d77649ec6dd195008281944881b02e00bb54e1",
    "generators/build-current-audit-dashboard.py": "d4e8efd2aa9e80ad26389bdb0e6f21faefb02c53face26d9fb9a6119e673dc26",
}

ARTIFACT_PINS = {
    "generators/materialize-run-178-audit-dashboard-verification-wave-33.py": (
        "ffedf87ea3cae8b74cd280f676f3fb671e9a2885dad0a3ef8564d0ed21f8d53d",
        "96757955c4f5fe42dd97e8dfc568a43775b1b138",
        57542,
        1552,
    ),
    "evidence/browser/current-audit-dashboard-verification-run-178-wave-33.json": (
        "9a41983d86fa3fbe054d1ddb848a2ab4027284aa78210b78937d9728f7fbdaf2",
        "cf9e07288abd4db365706feda4754b6a07d491a2",
        28507,
        701,
    ),
    "generators/build-outcome-neutral-fleet-trip-index-route-action-cohort-wave-34.py": (
        "61c895a305f743f102765c9f86d38843c3ce61bcc1a8684a672aa2d7cd6ee157",
        "506a7007c8d7b8e719b1bfa904a880a2885fe8c1",
        34815,
        704,
    ),
    "evidence/source/root-run-179-outcome-neutral-fleet-trip-index-route-action-cohort-wave-34.json": (
        "5505cf17bb68d3e534116ea9d33e501e0222714b6e3779d0ec6b70f819cc3b0a",
        "ea3a958c125038a95c8d98370328a263d2a2c151",
        54801,
        688,
    ),
    "generators/materialize-independent-outcome-neutral-fleet-trip-index-route-action-review-wave-34.py": (
        "80cf0e6febabee80b1fa99f3f296cabade8959bd5a4fcd72983af19d335332cd",
        "3004a455a14736f2641e7f71c506181a0b02d967",
        49740,
        999,
    ),
    "evidence/source/raw-run-179r-independent-outcome-neutral-fleet-trip-index-route-action-review-wave-34.json": (
        "67c5b09cbb26c95042bd7ba487c2a2c92a75d14363952ca35e9b72ee55e36d62",
        "7a1d16ff8ee0f0fe78aeac742322bee0c8c6e8ec",
        40255,
        822,
    ),
    "generators/integrate-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-wave-34.py": (
        "cdbeeae65d0d5d928d6356de7c2433d437b6f2bae9fd80bb7a942b97d41f6594",
        "f3bd1cae87ff0b9f74bd1be8d5e963db91cd0813",
        36675,
        650,
    ),
    "evidence/source/current-run-180-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-wave-34.json": (
        "49b0bd12abbd4dd2b9ce0dbe9b6fd60ab79eea92861f6339407fbd05f0b7c925",
        "b9d3d623d22e7ee8cad21fca62d703cd5881b0a9",
        46534,
        883,
    ),
    "generators/materialize-independent-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-review-wave-34.py": (
        "854a673d362f3c3cf70f53e1083ae9daf6d977f0411aa1444a6d8309e1a086bb",
        "090e769fc3b483c5d82047390b58916ef43ef182",
        53404,
        895,
    ),
    "evidence/source/current-run-180r-independent-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-review-wave-34.json": (
        "c6038caa557277124cb58056a2882ce41d1f2ee402f91effb0e6bfab6fe95d96",
        "4ee55ff346533b39848a69c8fe62347b4519d8ca",
        33597,
        418,
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
    "source_owner_records": 666,
    "route_owner_records": 309,
    "page_owner_records": 357,
    "distinct_feature_ids": 256,
    "distinct_H_feature_ids": 234,
    "distinct_D_feature_ids": 22,
    "route_distinct_feature_ids": 64,
    "page_distinct_feature_ids": 242,
    "route_page_feature_overlap": 50,
    "static_controller_action_bridges": 97,
    "bounded_static_source_denominator": 3929,
    "bounded_static_source_ownership_percent": "16.950878",
    "bounded_static_source_residual_records": 3263,
    "residual_explicit_unmapped_routes": 2892,
}

QUEUE_COUNTS = {
    "direct_exact_queue_records": 507,
    "reviewed_queue_surface_rows": 120,
    "owner_queue_surface_rows": 98,
    "shared_queue_surface_rows": 10,
    "alias_queue_surface_rows": 5,
    "dead_queue_surface_rows": 0,
    "evidence_gap_queue_surface_rows": 7,
    "pending_unreviewed_queue_surface_rows": 387,
    "queue_surfaces_without_ownership": 409,
}

QUEUE_BOUNDARY = {
    "preceding_index_83_not_recredited": True,
    "selected_index_84_integrated": True,
    "next_unresolved_index": 85,
    "next_unresolved_queue_id": "RUN090-ROUTE-0086",
    "next_unresolved_route_record_id": "RUN077-ROUTE-0694",
    "next_unresolved_route_name": "fleet-assets.trips.playback",
    "next_unresolved_action_expression": "[FleetTripController::class, 'show']",
    "next_unresolved_queue_record_sha256": "f9df043e4557240020de213961c847fb56b8cd0e2d9b9144ec0b7a877ff84943",
    "reviewed_key_count": 120,
    "reviewed_key_list_sha256": "5dbcecd3986300fe255fdb75efe6013c07f3adc4071745ebebf0c4a525ee99c9",
    "reviewed_key_list_canonical_json_sha256": "738c7836dd770e12d67de62d4f28441825814d619bb641e070e25468786fb75e",
}


def duplicate_rejecting_pairs(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    value: dict[str, Any] = {}
    for key, item in pairs:
        assert key not in value, f"Duplicate JSON key: {key}"
        value[key] = item
    return value


def assert_finite(value: Any, label: str = "root") -> None:
    if isinstance(value, float):
        assert math.isfinite(value), f"Non-finite number: {label}"
    elif isinstance(value, dict):
        for key, item in value.items():
            assert_finite(item, f"{label}.{key}")
    elif isinstance(value, list):
        for index, item in enumerate(value):
            assert_finite(item, f"{label}[{index}]")


def strict_json_bytes(raw: bytes, label: str) -> dict[str, Any]:
    assert not raw.startswith(b"\xef\xbb\xbf"), f"BOM not allowed: {label}"
    assert b"\r" not in raw, f"CR not allowed: {label}"
    assert raw.endswith(b"\n"), f"Final LF required: {label}"
    for line_number, line in enumerate(raw.splitlines(), start=1):
        assert line == line.rstrip(b" \t"), f"Trailing whitespace: {label}:{line_number}"
    value = json.loads(
        raw.decode("utf-8"),
        object_pairs_hook=duplicate_rejecting_pairs,
        parse_constant=lambda token: (_ for _ in ()).throw(ValueError(f"Non-finite token: {token}")),
    )
    assert isinstance(value, dict), f"JSON object required: {label}"
    assert_finite(value, label)
    expected = (json.dumps(value, ensure_ascii=False, indent=2, allow_nan=False) + "\n").encode("utf-8")
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
    raw = json.dumps(
        value,
        ensure_ascii=False,
        sort_keys=True,
        separators=(",", ":"),
        allow_nan=False,
    ).encode("utf-8")
    return sha256_bytes(raw)


def metrics(relative: str) -> dict[str, Any]:
    raw = (AUDIT_DIR / relative).read_bytes()
    assert not raw.startswith(b"\xef\xbb\xbf"), relative
    assert b"\r" not in raw, relative
    assert raw.endswith(b"\n"), relative
    for line_number, line in enumerate(raw.splitlines(), start=1):
        assert line == line.rstrip(b" \t"), f"Trailing whitespace: {relative}:{line_number}"
    return {
        "path": relative,
        "sha256": sha256_bytes(raw),
        "git_blob_id": git("hash-object", "--", f"{AUDIT_PREFIX}{relative}"),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


def verify_nested_self_seal(payload: dict[str, Any], expected: str) -> None:
    without_seal = dict(payload)
    seal = without_seal.pop("self_seal")
    assert seal == {
        "algorithm": "sha256-canonical-json-with-self-seal-omitted",
        "sha256": expected,
    }
    assert canonical_sha256(without_seal) == expected


def verify_receipt_self_seal(payload: dict[str, Any], expected: str) -> None:
    without_seal = dict(payload)
    seal = without_seal.pop("receipt_self_seal_sha256")
    assert seal == expected
    assert canonical_sha256(without_seal) == expected


assert git("rev-parse", "HEAD") == REPORTING_INPUT_COMMIT
assert git("show", "-s", "--format=%T", "HEAD") == REPORTING_INPUT_TREE
assert git("rev-parse", "HEAD^") == REPORTING_INPUT_PARENT
assert git("branch", "--show-current") == "main"
assert git("rev-parse", "main") == REPORTING_INPUT_COMMIT
assert git("rev-parse", "origin/main") == ORIGIN_MAIN_OBSERVED_WITHOUT_REFETCH
assert git("rev-list", "--left-right", "--count", "origin/main...HEAD") == "0\t16"
assert git("diff", "--cached", "--name-only") == ""

expected_dirty_without_receipt = sorted(
    [f" M {AUDIT_PREFIX}{path}" for path in REPORTING_SURFACES]
    + [f"?? {AUDIT_PREFIX}{SCRIPT_REL}"]
)
expected_dirty_with_receipt = sorted(
    expected_dirty_without_receipt + [f"?? {AUDIT_PREFIX}{OUTPUT_REL}"]
)
dirty = sorted(
    line
    for line in git("status", "--porcelain=v1", "--untracked-files=all").splitlines()
    if line
)
assert dirty in (expected_dirty_without_receipt, expected_dirty_with_receipt), dirty
assert sorted(git("diff", "--name-only").splitlines()) == sorted(
    f"{AUDIT_PREFIX}{path}" for path in REPORTING_SURFACES
)
assert git("diff", "--check") == ""

for forbidden in ("__pycache__", ".pytest_cache", ".mypy_cache", ".ruff_cache"):
    assert not any(path.is_dir() for path in AUDIT_DIR.rglob(forbidden)), forbidden
assert not any(path.is_file() for path in AUDIT_DIR.rglob("*.tmp"))
assert not any(path.is_file() and ".tmp-run181" in path.name for path in AUDIT_DIR.rglob("*"))

for relative, expected_sha256 in BASELINE_SHA256.items():
    assert sha256_bytes(git_show_bytes(REPORTING_INPUT_COMMIT, relative)) == expected_sha256, relative
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
assert sha256_file("audit-dashboard.html") == RUN_178_DASHBOARD_SHA256

run_178 = read_json("evidence/browser/current-audit-dashboard-verification-run-178-wave-33.json")
run_179 = read_json("evidence/source/root-run-179-outcome-neutral-fleet-trip-index-route-action-cohort-wave-34.json")
run_179r = read_json("evidence/source/raw-run-179r-independent-outcome-neutral-fleet-trip-index-route-action-review-wave-34.json")
run_180 = read_json("evidence/source/current-run-180-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-wave-34.json")
run_180r = read_json("evidence/source/current-run-180r-independent-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-review-wave-34.json")

assert run_178["run_id"] == "RUN-178-AUDIT-DASHBOARD-VERIFICATION-WAVE-33"
assert run_178["pins"]["governing_prompt_sha256"] == GOVERNING_PROMPT_SHA256
assert run_178["pins"]["continuation_request_sha256"] == CONTINUATION_REQUEST_SHA256
assert run_178["pins"]["continuation_request_is_not_governing_prompt"] is True
assert run_178["pins"]["run_178_dashboard"]["sha256"] == RUN_178_DASHBOARD_SHA256
assert run_178["verification"]["viewports_verified"] == 4
assert run_178["verification"]["visible_static_checks_passed"] == 97
assert run_178["verification"]["navigation_clicks_passed"] == 10
assert run_178["verification"]["post_materialization_local_resources"] == "443/443"
verify_receipt_self_seal(run_178, "1a8cc7a24d2fd7d1df8e63dfae87f475e1013275e77bd5e94a1ab8389502b7a8")

assert run_179["run_id"] == "RUN-179-OUTCOME-NEUTRAL-FLEET-TRIP-INDEX-ROUTE-ACTION-COHORT-WAVE-34"
assert run_179["selection_contract"]["selected_queue_indices_zero_based"] == [84]
assert run_179["selection_contract"]["ownership_decisions_authored"] == 0
verify_nested_self_seal(run_179, "2fb26afd47c818fe5654fdc685af9a87e40624ad44e205914cca85298593bfc2")

assert run_179r["run_id"] == "RUN-179R-INDEPENDENT-OUTCOME-NEUTRAL-FLEET-TRIP-INDEX-ROUTE-ACTION-REVIEW-WAVE-34"
assert run_179r["decision"] == "GO"
assert run_179r["review_chronology"][3]["reported_outcome"] == "OWNER_ROUTE_ACTION"
assert run_179r["review_chronology"][4]["reported_outcome"] == "EVIDENCE_GAP"
assert [row["outcome"] for row in run_179r["independent_semantic_tiebreak_reviews"]] == [
    "OWNER_ROUTE_ACTION",
    "OWNER_ROUTE_ACTION",
]
assert run_179r["excluded_material_boundary"]["preliminary_shared_judgments_invalidated"] == 2
assert run_179r["excluded_material_boundary"]["feature_identity_imported"] is False
assert run_179r["excluded_material_boundary"]["semantic_vote_imported"] is False
assert run_179r["action_decision"]["outcome"] == "OWNER_ROUTE_ACTION"
assert run_179r["action_decision"]["decision_record_sha256"] == "e3530def5fb093b5b2169659d32b3251a6726d493257602c8138d3a38bc050d3"
verify_nested_self_seal(run_179r, "75589c560904f51656af7038037e988ae169b181ddc480b95d5fca35cdbec14b")

assert run_180["run_id"] == "RUN-180-REVIEWED-OUTCOME-NEUTRAL-FLEET-TRIP-INDEX-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-34"
assert {key: run_180["combined_counts"][key] for key in REPORTING_COUNTS} == REPORTING_COUNTS
assert {key: run_180["queue_accounting"][key] for key in QUEUE_COUNTS} == QUEUE_COUNTS
assert {key: run_180["queue_boundary"][key] for key in QUEUE_BOUNDARY} == QUEUE_BOUNDARY
assert run_180["reviewed_overlay"]["accepted_route_owner_records"] == 1
assert run_180["reviewed_overlay"]["accepted_page_owner_records"] == 0
assert run_180["reviewed_overlay"]["accepted_controller_action_bridges"] == 1
assert run_180["reviewed_overlay"]["new_distinct_feature_ids"] == 0
assert run_180["reviewer_lineage_and_dissent_preservation"]["original_strict_current_split_preserved"] is True
assert run_180["reviewer_lineage_and_dissent_preservation"]["original_dissenting_outcome"] == "EVIDENCE_GAP"
assert run_180["reviewer_lineage_and_dissent_preservation"]["excluded_older_bundle_identity_or_credit_imported"] is False
assert {key for key, value in run_180["credit_boundary"].items() if value} == {
    "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD",
    "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION",
}
verify_nested_self_seal(run_180, "181a94c9b53b7f78e3d29f5833b42bbe0e87fcfb899c2af0b465aec9a09339cf")

assert run_180r["run_id"] == "RUN-180R-INDEPENDENT-REVIEWED-OUTCOME-NEUTRAL-FLEET-TRIP-INDEX-ROUTE-ACTION-OWNERSHIP-OVERLAY-REVIEW-WAVE-34"
assert run_180r["decision"]["independent_reviews"] == 3
assert run_180r["decision"]["discrepancies"] == 0
assert all(row["reporting_authorization_individually_granted"] is False for row in run_180r["independent_review_records"])
assert run_180r["synthesis_review"]["reporting_materialization_authorized"] is True
assert run_180r["reporting_materialization_authorized"] is True
assert run_180r["decision"]["decision_record_sha256"] == "71721ada412876cd9dcd45240136b2e1f6e6987b6736e68dde25a360de126bfc"
assert {key for key, value in run_180r["credit_boundary"].items() if value} == {
    "INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING",
}
verify_nested_self_seal(run_180r, "29cb4151d350613bdf8dc48603228347852bc5aef8d25ddbfd3f1adcb6ae5f2c")
for payload in (run_178, run_179, run_179r, run_180, run_180r):
    assert all(value is False for value in payload["completion_boundary"].values())

baseline_findings = strict_json_bytes(
    git_show_bytes(REPORTING_INPUT_COMMIT, "findings.json"),
    f"{REPORTING_INPUT_COMMIT}:findings.json",
)
findings = read_json("findings.json")
assert baseline_findings["records"] == findings["records"]
assert len(findings["records"]) == len({row["id"] for row in findings["records"]}) == 13
assert canonical_sha256(baseline_findings["records"]) == canonical_sha256(findings["records"])
assert findings["historical_run_170_static_source_feature_ownership"] == baseline_findings["current_static_source_feature_ownership"]
assert findings["historical_run_170_outcome_neutral_fleet_vehicle_alerts_config_route_action_ownership_review"] == baseline_findings["current_outcome_neutral_fleet_vehicle_alerts_config_route_action_ownership_review"]
assert "current_outcome_neutral_fleet_vehicle_alerts_config_route_action_ownership_review" not in findings
assert findings["current_provisional_source_observations"] == baseline_findings["current_provisional_source_observations"]
assert findings["historical_run_153_provisional_source_observations"] == baseline_findings["historical_run_153_provisional_source_observations"]

counts = findings["counts"]
expected_count_projection = {
    "static_source_feature_ownership_records": 666,
    "static_source_feature_ownership_route_records": 309,
    "static_source_feature_ownership_page_records": 357,
    "static_source_feature_ownership_distinct_feature_ids": 256,
    "static_source_feature_ownership_distinct_H_feature_ids": 234,
    "static_source_feature_ownership_distinct_D_feature_ids": 22,
    "static_source_feature_ownership_route_distinct_feature_ids": 64,
    "static_source_feature_ownership_page_distinct_feature_ids": 242,
    "static_source_feature_ownership_route_page_feature_overlap": 50,
    "static_controller_action_bridges": 97,
    "bounded_static_source_ownership_percent": "16.950878",
    "bounded_static_source_residual_records": 3263,
    "bounded_static_source_residual_route_records": 2892,
    "direct_exact_queue_records": 507,
    "direct_exact_queue_reviewed": 120,
    "direct_exact_queue_owned": 98,
    "direct_exact_queue_shared": 10,
    "direct_exact_queue_alias": 5,
    "direct_exact_queue_dead_or_noncanonical": 0,
    "direct_exact_queue_evidence_gap": 7,
    "direct_exact_queue_pending_unreviewed": 387,
    "direct_exact_queue_without_ownership": 409,
}
assert {key: counts[key] for key in expected_count_projection} == expected_count_projection
assert counts["retained_claim_records"] == 13
assert counts["provisional_source_claims"] == counts["provisional_P1"] == 8
assert counts["historical_already_fixed"] == 2
assert counts["historical_remediated"] == 3
assert counts["bounded_disposition_tests_passed"] == 88
assert counts["bounded_disposition_assertions"] == 1764
assert counts["final_P0"] == counts["final_P1"] == 0
assert counts["benchmark_mapped"] == 2
assert counts["final_no_match"] == 0
assert counts["benchmark_unresolved"] == 338

current_ownership = findings["current_static_source_feature_ownership"]
assert current_ownership["run_id"] == run_180["run_id"]
assert {key: current_ownership["combined_counts"][key] for key in REPORTING_COUNTS} == REPORTING_COUNTS
assert {key: current_ownership["queue_accounting"][key] for key in QUEUE_COUNTS} == QUEUE_COUNTS
assert {key: current_ownership["queue_boundary"][key] for key in QUEUE_BOUNDARY} == QUEUE_BOUNDARY
assert current_ownership["reviewed_overlay"] == {
    "queue_index_zero_based": 84,
    "queue_id": "RUN090-ROUTE-0085",
    "route_record_id": "RUN077-ROUTE-0693",
    "route_name": "fleet-assets.trips.index",
    "controller_action": "VehicleController::trips",
    "feature_id": "CAP-FLEET-VEHICLE-REGISTER",
    "accepted_source_owner_records": 1,
    "accepted_route_owner_records": 1,
    "accepted_page_owner_records": 0,
    "accepted_controller_action_bridges": 1,
    "new_distinct_feature_ids": 0,
}
assert current_ownership["reviewer_lineage_and_dissent_preservation"]["preliminary_shared_judgments_invalidated"] == 2
assert current_ownership["reviewer_lineage_and_dissent_preservation"]["strict_current_split"] == [
    "OWNER_ROUTE_ACTION",
    "EVIDENCE_GAP",
]
assert current_ownership["reviewer_lineage_and_dissent_preservation"]["fresh_strict_current_tiebreak_outcomes"] == [
    "OWNER_ROUTE_ACTION",
    "OWNER_ROUTE_ACTION",
]
assert {key for key, value in current_ownership["credit_boundary"].items() if value} == {
    "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD",
    "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION",
}

current_review = findings["current_outcome_neutral_fleet_trip_index_route_action_ownership_review"]
assert current_review["run_id"] == run_180r["run_id"]
assert current_review["decision"]["verdict"] == "GO"
assert current_review["decision"]["independent_reviews"] == 3
assert current_review["decision"]["individually_reporting_authorized_reviews"] == 0
assert current_review["decision"]["discrepancies"] == 0
assert current_review["decision"]["complete_synthesis_reporting_materialization_authorized"] is True
assert {key for key, value in current_review["credit_boundary"].items() if value} == {
    "INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING",
}
assert findings["current_direct_exact_route_page_review_queue"]["reviewed_queue_surfaces"] == 120
assert findings["current_direct_exact_route_page_review_queue"]["pending_unreviewed"] == 387
assert findings["current_direct_exact_route_page_review_queue"]["without_ownership"] == 409
assert findings["current_audit_artifact_verification_history"]["run_178"]["dashboard_sha256"] == RUN_178_DASHBOARD_SHA256
assert findings["current_audit_artifact_verification_history"]["run_178"]["superseded_by_run_181_dashboard"] is True
assert all(row["completion_credit"] is False for row in findings["records"])
assert all(all(value is False for value in row["credit"].values()) for row in findings["records"])

required_report_phrases = {
    "00-executive-summary.md": (
        "666 records = 309 routes + 357 pages",
        "RUN-178–181 Fleet trip-index route/action ownership checkpoint",
        "120 reviewed / 387 pending",
        "RUN-181 alone advances",
        "fresh RUN-182 verification",
    ),
    "01-repository-module-map.md": (
        "RUN-113–181 reviewed route/action",
        "666 source owners (309 route + 357 page)",
        "index 84 is integrated",
        "fleet-assets.trips.playback",
    ),
    "13-unresolved-questions-and-evidence-gaps.md": (
        "RUN-180/R preserve 309 bounded route-owner records",
        "RUN-180/R preserve 357 bounded page owners",
        "RUN-181 alone",
        "RUN090-ROUTE-0086",
    ),
}
for relative, required in required_report_phrases.items():
    text = (AUDIT_DIR / relative).read_text(encoding="utf-8")
    for phrase in required:
        assert phrase in text, (relative, phrase)
reporting_text = "\n".join((AUDIT_DIR / path).read_text(encoding="utf-8") for path in REPORTING_SURFACES)
for prohibited in (
    "The current bounded checkpoint is **665 records",
    "queue index 84 remains pending fresh semantic review",
    "RUN-181 supplies correctness credit",
    "RUN-181 completes Gate 4",
):
    assert prohibited not in reporting_text, prohibited

builder_path = AUDIT_DIR / "generators/build-current-audit-dashboard.py"
builder_source = builder_path.read_text(encoding="utf-8")
ast.parse(builder_source, filename=str(builder_path))
compile(builder_source, str(builder_path), "exec")
for required in (
    'read_json_strict("evidence/source/current-run-181-reviewed-fleet-trip-index-route-action-reporting-wave-34.json")',
    "RUN-181-REVIEWED-FLEET-TRIP-INDEX-ROUTE-ACTION-REPORTING-WAVE-34",
    "run_181_template_rewrites",
    "Fresh RUN-182 audit-dashboard verification required",
    "tmp-run182-dashboard",
):
    assert required in builder_source, required

reporting_manifest = [metrics(path) for path in REPORTING_SURFACES]
receipt: dict[str, Any] = {
    "schema_version": "run-181-reviewed-fleet-trip-index-route-action-reporting-wave-34-v1",
    "run_id": "RUN-181-REVIEWED-FLEET-TRIP-INDEX-ROUTE-ACTION-REPORTING-WAVE-34",
    "status": "FLEET_TRIP_INDEX_REVIEWED_OWNERSHIP_REPORTING_MATERIALIZED_DASHBOARD_RUN182_REQUIRED_ZERO_CORRECTNESS_FINDING_PUBLICATION_OR_COMPLETION_CREDIT",
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
        "origin_main_observed_without_refetch": ORIGIN_MAIN_OBSERVED_WITHOUT_REFETCH,
        "governing_prompt_sha256": GOVERNING_PROMPT_SHA256,
        "continuation_request_sha256": CONTINUATION_REQUEST_SHA256,
        "continuation_request_is_not_governing_prompt": True,
        "run_178_materializer_sha256": ARTIFACT_PINS["generators/materialize-run-178-audit-dashboard-verification-wave-33.py"][0],
        "run_178_receipt_sha256": ARTIFACT_PINS["evidence/browser/current-audit-dashboard-verification-run-178-wave-33.json"][0],
        "run_179_generator_sha256": ARTIFACT_PINS["generators/build-outcome-neutral-fleet-trip-index-route-action-cohort-wave-34.py"][0],
        "run_179_receipt_sha256": ARTIFACT_PINS["evidence/source/root-run-179-outcome-neutral-fleet-trip-index-route-action-cohort-wave-34.json"][0],
        "run_179r_materializer_sha256": ARTIFACT_PINS["generators/materialize-independent-outcome-neutral-fleet-trip-index-route-action-review-wave-34.py"][0],
        "run_179r_receipt_sha256": ARTIFACT_PINS["evidence/source/raw-run-179r-independent-outcome-neutral-fleet-trip-index-route-action-review-wave-34.json"][0],
        "run_180_generator_sha256": ARTIFACT_PINS["generators/integrate-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-wave-34.py"][0],
        "run_180_receipt_sha256": ARTIFACT_PINS["evidence/source/current-run-180-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-wave-34.json"][0],
        "run_180r_materializer_sha256": ARTIFACT_PINS["generators/materialize-independent-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-review-wave-34.py"][0],
        "run_180r_receipt_sha256": ARTIFACT_PINS["evidence/source/current-run-180r-independent-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-review-wave-34.json"][0],
        "reporting_materializer": metrics(SCRIPT_REL),
        "baseline_findings_sha256": BASELINE_SHA256["findings.json"],
        "current_findings": metrics("findings.json"),
        "dashboard_generator": metrics("generators/build-current-audit-dashboard.py"),
        "unchanged_run_178_dashboard": metrics("audit-dashboard.html"),
    },
    "lineage_roles": {
        "run_178": "verifies only the exact now-superseded RUN-177 audit dashboard",
        "run_179": "selects one outcome-neutral current-source queue row and authors no ownership decision",
        "run_179r": "preserves contaminated-preliminary exclusion and strict-current dissent, then accepts two fresh OWNER tiebreaks for later integration",
        "run_180": "integrates exactly one route owner and one controller-action bridge while preserving dissent",
        "run_180r": "records three sealed GO lanes; each lane withholds reporting permission and only the complete synthesis authorizes reporting",
        "run_181": "alone changes live ownership and queue reporting surfaces",
        "run_182": "required fresh dashboard rebuild and four-viewport exact-artifact verification",
    },
    "reporting_snapshot": {
        "combined_counts": REPORTING_COUNTS,
        "queue_accounting": QUEUE_COUNTS,
    },
    "queue_boundary": QUEUE_BOUNDARY,
    "review_chronology_boundary": {
        "preliminary_shared_judgments_invalidated": 2,
        "excluded_material": "docs/audits/oblivion-oss-comprehensive-audit-2026-08-12",
        "excluded_material_identity_mapping_or_credit_imported": False,
        "strict_current_split": ["OWNER_ROUTE_ACTION", "EVIDENCE_GAP"],
        "integration_stopped_before_bounded_expansion": True,
        "fresh_tiebreak_outcomes": ["OWNER_ROUTE_ACTION", "OWNER_ROUTE_ACTION"],
        "original_dissent_preserved": True,
    },
    "provisional_source_observations": {
        "count": findings["current_provisional_source_observations"]["observation_count"],
        "observation_ids": [
            row["observation_id"]
            for row in findings["current_provisional_source_observations"]["observations"]
        ],
        "status": "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING",
        "correctness_credit_authorized": False,
        "final_finding_credit_authorized": False,
        "preserved_byte_for_parsed_value_from_reporting_input": True,
    },
    "findings_boundary": {
        "retained_claim_records": 13,
        "current_provisional": 8,
        "historical_already_fixed": 2,
        "historical_remediated": 3,
        "final_P0": 0,
        "final_P1": 0,
    },
    "bounded_execution_boundary": {
        "unique_tests": 88,
        "unique_assertions": 1764,
        "changed_by_run_181": False,
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
        "dashboard_sha256_unchanged": RUN_178_DASHBOARD_SHA256,
        "findings_records_unchanged": True,
        "findings_records_canonical_sha256": canonical_sha256(findings["records"]),
        "run_169_provisional_observations_unchanged": True,
        "historical_run_170_ownership_and_review_archived_without_value_change": True,
        "application_routes_resources_tests_database_unchanged": True,
        "seven_path_scope": list(SEVEN_PATH_SCOPE),
    },
    "dashboard_forward_gate": {
        "required_run": "RUN-182",
        "dashboard_html_changed_by_run_181": False,
        "unchanged_dashboard_sha256": RUN_178_DASHBOARD_SHA256,
        "fresh_four_viewport_verification_required": True,
        "required_viewports": ["1440x900", "1280x800", "1024x768", "390x844"],
        "future_receipt_link_is_unhashed_to_avoid_cycle": True,
    },
    "publication_boundary": {
        "baseline_released_clean_before_reporting_writes": True,
        "origin_main_observation_refetched_for_run_181": False,
        "run_181_commit_or_push_performed_by_materializer": False,
        "application_fix_merged_or_pushed": False,
        "release_authorized": False,
        "publication_authorized_or_performed": False,
    },
    "noninheritance_boundary": {
        "page_or_new_feature_ownership": False,
        "adjacent_playback_route_ownership": False,
        "consumer_caller_service_model_helper_or_test_ownership": False,
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
        "site_privacy_or_direct_object": False,
        "application_source_or_tests": False,
        "runtime": False,
        "application_browser": False,
        "benchmark_mapping": False,
        "final_no_match_or_NCM": False,
        "final_finding": False,
        "release": False,
        "publication": False,
        "feature_completion": False,
        "gate_4": False,
        "audit_complete": False,
    },
    "underlying_run_180_credit_boundary": {
        "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD": True,
        "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION": True,
        "all_other_credit": False,
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
output_bytes = (
    json.dumps(receipt, ensure_ascii=False, indent=2, allow_nan=False) + "\n"
).encode("utf-8")

OUTPUT_PATH.parent.mkdir(parents=True, exist_ok=True)
temporary_path = OUTPUT_PATH.with_name(f".{OUTPUT_PATH.name}.tmp-run181")
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
    "dashboard_sha256_unchanged": RUN_178_DASHBOARD_SHA256,
}, ensure_ascii=False, sort_keys=True))
