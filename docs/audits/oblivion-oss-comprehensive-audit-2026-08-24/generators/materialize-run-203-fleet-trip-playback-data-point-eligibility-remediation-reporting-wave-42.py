#!/usr/bin/env python3
"""Materialize the bounded RUN-203 remediation-reporting receipt.

This program is intentionally reporting-only. It validates the exact integrated
FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01 lineage, the prepared reporting
transaction, and the still-frozen RUN-202 dashboard. It writes only the RUN-203
source receipt and forward-gates a separate RUN-204 dashboard build/browser run.
"""

from __future__ import annotations

import hashlib
import json
import os
import subprocess
import sys
from pathlib import Path
from typing import Any


AUDIT_DIR = Path(__file__).resolve().parent.parent
REPO_ROOT = AUDIT_DIR.parents[2]
REL_AUDIT = AUDIT_DIR.relative_to(REPO_ROOT).as_posix()

DASHBOARD_REL = f"{REL_AUDIT}/audit-dashboard.html"
FINDINGS_REL = f"{REL_AUDIT}/findings.json"
BUILDER_REL = f"{REL_AUDIT}/generators/build-current-audit-dashboard.py"
HANDOFF_REL = (
    f"{REL_AUDIT}/evidence/source/"
    "current-run-203-fleet-trip-playback-data-point-eligibility-coordination-handoff-wave-42.json"
)
MATERIALIZER_REL = (
    f"{REL_AUDIT}/generators/"
    "materialize-run-203-fleet-trip-playback-data-point-eligibility-remediation-reporting-wave-42.py"
)
OUTPUT_REL = (
    f"{REL_AUDIT}/evidence/source/"
    "current-run-203-fleet-trip-playback-data-point-eligibility-remediation-reporting-wave-42.json"
)
RUN_202_MATERIALIZER_REL = (
    f"{REL_AUDIT}/generators/materialize-run-202-audit-dashboard-verification-wave-41.py"
)
RUN_202_RECEIPT_REL = (
    f"{REL_AUDIT}/evidence/browser/current-audit-dashboard-verification-run-202-wave-41.json"
)

REPORTING_INPUT_COMMIT = "ba39cbc36694164ca9e0f232efd2de00013191b5"
REPORTING_INPUT_TREE = "1b384bc15377dbf1e2410580681cd46613ab9ef6"
REPORTING_INPUT_PARENTS = [
    "b61a2abd48a3d80ef91f6edcdf51d3ad253715e6",
    "9c40c51a26048b00d035bf13745a20385794d86b",
]
REPORTING_INPUT_SUBJECT = "merge: filter Fleet playback points before cap"

APPLICATION_BASELINE_COMMIT = "9c01f5a4f57f96722015278d1df3c3bd111aa95c"
APPLICATION_BASELINE_TREE = "c9b0f223e5c63870cc5c04708babece98c00435f"
ADVANCED_MAIN_COMMIT = "b61a2abd48a3d80ef91f6edcdf51d3ad253715e6"
ADVANCED_MAIN_TREE = "1d8dd6ca99282df8d8f72f21eba6807a1e8f8b4b"
SEALED_FIX_COMMIT = "9c40c51a26048b00d035bf13745a20385794d86b"
SEALED_FIX_TREE = "319ec45b5939900c1f00be447ab28486caa821ea"
SEALED_FIX_SUBJECT = "fix(fleet): filter playback points before cap"
SEALED_FIX_STABLE_PATCH_ID = "93126baf39d11dc22f1fc3f1d990fa1d376222b6"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"

EXACT_APPLICATION_TEST_BLOBS = {
    "app/Http/Controllers/Fleet/FleetTripController.php":
        "c8c16d928610206281720571c64ab6d5b7c7010d",
    "tests/Feature/FleetAssets/FleetTripPlaybackSitePrivacyTest.php":
        "013595266e53c0d31c8be95b3736fede630d0b3e",
}
EXACT_FIRST_PARENT_STATUS = {
    "app/Http/Controllers/Fleet/FleetTripController.php": "M",
    "tests/Feature/FleetAssets/FleetTripPlaybackSitePrivacyTest.php": "M",
}

PRE_RUN_203_FINDINGS_SHA256 = (
    "bf2e9fd34cfe4d2f5188d91ebd3431ac6d92a296035d83e05caf77cb5fee142f"
)
PRE_RUN_203_BUILDER_SHA256 = (
    "a2197052c211eccf6f00f3b43b280564f22d1b2f92491623973b3c5e49bf8767"
)
RUN_202_DASHBOARD_SHA256 = (
    "1876db1ff590c86fb30cefb74368b0241c72d9b75966fcbf1a36d6b1096b30e3"
)
RUN_202_DASHBOARD_BYTES = 350017
RUN_202_DASHBOARD_LINES = 78
RUN_202_MATERIALIZER_SHA256 = (
    "05685136cf43f637e0835c8f8301f270c60466fce79868ffb033922095333355"
)
RUN_202_MATERIALIZER_BYTES = 57239
RUN_202_MATERIALIZER_LINES = 1486
RUN_202_RECEIPT_SHA256 = (
    "b63ed9585a03cc852d0f772be42de303f0866c73e80cc8522e8de0d328887471"
)
RUN_202_RECEIPT_BYTES = 30379
RUN_202_RECEIPT_LINES = 788
RUN_202_RECEIPT_SELF_SEAL = (
    "a4d296e2a3f779bfa2c7cf34233958a37dc74bb5f6e4f7d78a867d6cb12dc3b8"
)
HANDOFF_SHA256 = (
    "ef75a5c6392225fb5c50d3f2964f4cc9d4bf2eda6646b4cdf65968c674d762cd"
)
HANDOFF_GIT_BLOB = "7035edf7f20c04d35b7cffd9e967c857fd1ceff0"
HANDOFF_BYTES = 8093
HANDOFF_LINES = 174
HANDOFF_SELF_SEAL = (
    "a4b9ca491ffd65a11551bb850fd067a45980c8b1fa9084623a56e081e833acbd"
)

HUMAN_RELATIVE_PATHS = [
    "00-executive-summary.md",
    "01-repository-module-map.md",
    "07-module-findings.md",
    "11-prioritised-roadmap.md",
    "12-native-build-and-do-not-copy-register.md",
    "13-unresolved-questions-and-evidence-gaps.md",
]
REPORTING_SOURCE_RELATIVE_PATHS = [
    *HUMAN_RELATIVE_PATHS,
    "findings.json",
    "generators/build-current-audit-dashboard.py",
    HANDOFF_REL.removeprefix(f"{REL_AUDIT}/"),
]
OWNED_RELATIVE_PATHS = [
    *REPORTING_SOURCE_RELATIVE_PATHS,
    MATERIALIZER_REL.removeprefix(f"{REL_AUDIT}/"),
    OUTPUT_REL.removeprefix(f"{REL_AUDIT}/"),
]


class MaterializationError(RuntimeError):
    pass


def fail(message: str) -> None:
    raise MaterializationError(message)


def git_bytes(*args: str) -> bytes:
    completed = subprocess.run(
        ["git", *args],
        cwd=REPO_ROOT,
        check=False,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    if completed.returncode != 0:
        fail(
            f"git {' '.join(args)} failed ({completed.returncode}): "
            f"{completed.stderr.decode('utf-8', errors='replace').strip()}"
        )
    return completed.stdout


def git_text(*args: str) -> str:
    return git_bytes(*args).decode("utf-8").strip()


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(relative_path: str) -> str:
    return sha256_bytes((REPO_ROOT / relative_path).read_bytes())


def canonical_json_bytes(payload: dict[str, Any]) -> bytes:
    return json.dumps(
        payload,
        ensure_ascii=False,
        sort_keys=True,
        separators=(",", ":"),
    ).encode("utf-8")


def reject_duplicate_keys(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            fail(f"Duplicate JSON key: {key}")
        result[key] = value
    return result


def decode_json_strict(data: bytes, label: str) -> dict[str, Any]:
    try:
        text = data.decode("utf-8")
    except UnicodeDecodeError as exc:
        fail(f"{label} is not UTF-8: {exc}")
    if text.startswith("\ufeff"):
        fail(f"{label} contains a UTF-8 BOM")
    try:
        value = json.loads(text, object_pairs_hook=reject_duplicate_keys)
    except json.JSONDecodeError as exc:
        fail(f"{label} is invalid JSON: {exc}")
    if not isinstance(value, dict):
        fail(f"{label} root is not an object")
    expected = (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    if expected != data:
        fail(f"{label} is not canonical pretty JSON with one LF terminator")
    return value


def read_json_strict(relative_path: str) -> dict[str, Any]:
    return decode_json_strict(
        (REPO_ROOT / relative_path).read_bytes(),
        relative_path,
    )


def validate_self_seal(
    payload: dict[str, Any],
    expected: str,
    label: str,
) -> None:
    candidate = dict(payload)
    actual = candidate.pop("receipt_self_seal_sha256", None)
    if actual != expected:
        fail(f"{label} self-seal field mismatch")
    if sha256_bytes(canonical_json_bytes(candidate)) != expected:
        fail(f"{label} canonical self-seal mismatch")


def parse_name_status(base: str, target: str) -> dict[str, str]:
    lines = git_text(
        "diff",
        "--name-status",
        "--find-renames=100%",
        base,
        target,
        "--",
    ).splitlines()
    result: dict[str, str] = {}
    for line in lines:
        parts = line.split("\t")
        if len(parts) != 2 or parts[0] not in {"A", "M", "D"}:
            fail(f"Unexpected name-status row: {line!r}")
        status, path = parts
        if path in result:
            fail(f"Duplicate name-status path: {path}")
        result[path] = status
    return result


def stable_patch_id(commit: str) -> str:
    show = subprocess.Popen(
        ["git", "show", "--format=email", "--no-ext-diff", "--binary", commit],
        cwd=REPO_ROOT,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    patch = subprocess.run(
        ["git", "patch-id", "--stable"],
        cwd=REPO_ROOT,
        stdin=show.stdout,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    assert show.stdout is not None
    show.stdout.close()
    show_stderr = show.stderr.read().decode("utf-8", errors="replace")
    show_return = show.wait()
    if show_return != 0:
        fail(f"git show failed for stable patch ID: {show_stderr.strip()}")
    if patch.returncode != 0:
        fail(
            "git patch-id failed: "
            + patch.stderr.decode("utf-8", errors="replace").strip()
        )
    fields = patch.stdout.decode("utf-8").strip().split()
    if len(fields) != 2 or fields[1] != commit:
        fail(f"Unexpected stable patch-id output: {fields}")
    return fields[0]


def parse_status() -> dict[str, str]:
    lines = git_bytes(
        "status",
        "--porcelain=v1",
        "--untracked-files=all",
    ).decode("utf-8").splitlines()
    result: dict[str, str] = {}
    for line in lines:
        if len(line) < 4:
            fail(f"Malformed worktree-status line: {line!r}")
        code = line[:2]
        text = line[3:]
        if " -> " in text or code[0] in "RC" or code[1] in "RC":
            fail(f"Rename/copy is outside RUN-203 boundary: {text!r}")
        path = text.replace("\\", "/")
        if path in result:
            fail(f"Duplicate worktree-status path: {path}")
        result[path] = code
    return result


def file_metadata(relative_path: str) -> dict[str, Any]:
    data = (REPO_ROOT / relative_path).read_bytes()
    return {
        "path": relative_path.removeprefix(f"{REL_AUDIT}/"),
        "sha256": sha256_bytes(data),
        "bytes": len(data),
        "lines": data.count(b"\n"),
    }


def assert_contains(text: str, markers: list[str], label: str) -> None:
    missing = [marker for marker in markers if marker not in text]
    if missing:
        fail(f"{label} is missing exact markers: {missing}")


if sys.flags.optimize != 0:
    fail("RUN-203 refuses optimized Python because assertions must remain active")
if AUDIT_DIR.name != "oblivion-oss-comprehensive-audit-2026-08-24":
    fail("RUN-203 repository root mismatch")
if git_text("rev-parse", "HEAD") != REPORTING_INPUT_COMMIT:
    fail("RUN-203 must execute at the exact Fleet playback remediation merge")
if git_text("rev-parse", "main") != REPORTING_INPUT_COMMIT:
    fail("RUN-203 main ref mismatch")
if git_text("show", "-s", "--format=%T", "HEAD") != REPORTING_INPUT_TREE:
    fail("RUN-203 reporting input tree mismatch")
if git_text("show", "-s", "--format=%P", "HEAD").split() != REPORTING_INPUT_PARENTS:
    fail("RUN-203 merge-parent lineage mismatch")
if git_text("show", "-s", "--format=%s", "HEAD") != REPORTING_INPUT_SUBJECT:
    fail("RUN-203 merge subject mismatch")

for commit, tree, label in (
    (APPLICATION_BASELINE_COMMIT, APPLICATION_BASELINE_TREE, "application baseline"),
    (ADVANCED_MAIN_COMMIT, ADVANCED_MAIN_TREE, "advanced main"),
    (SEALED_FIX_COMMIT, SEALED_FIX_TREE, "sealed fix"),
):
    if git_text("show", "-s", "--format=%T", commit) != tree:
        fail(f"RUN-203 {label} tree mismatch")
if git_text("show", "-s", "--format=%P", SEALED_FIX_COMMIT) != APPLICATION_BASELINE_COMMIT:
    fail("RUN-203 sealed fix parent mismatch")
if git_text("show", "-s", "--format=%s", SEALED_FIX_COMMIT) != SEALED_FIX_SUBJECT:
    fail("RUN-203 sealed fix subject mismatch")
if stable_patch_id(SEALED_FIX_COMMIT) != SEALED_FIX_STABLE_PATCH_ID:
    fail("RUN-203 sealed fix stable patch ID mismatch")
if git_text("rev-parse", "origin/main") != ORIGIN_MAIN:
    fail("RUN-203 origin/main moved from the observed nonpublication boundary")
if git_text("rev-list", "--left-right", "--count", "origin/main...HEAD").split() != ["0", "96"]:
    fail("RUN-203 origin/main comparison mismatch")

if parse_name_status(ADVANCED_MAIN_COMMIT, REPORTING_INPUT_COMMIT) != EXACT_FIRST_PARENT_STATUS:
    fail("Fleet playback first-parent merge delta is not the exact two-path boundary")
if parse_name_status(APPLICATION_BASELINE_COMMIT, SEALED_FIX_COMMIT) != EXACT_FIRST_PARENT_STATUS:
    fail("Fleet playback sealed-fix delta is not the exact two-path boundary")
for path, expected_blob in EXACT_APPLICATION_TEST_BLOBS.items():
    merge_blob = git_text("rev-parse", f"{REPORTING_INPUT_COMMIT}:{path}")
    fix_blob = git_text("rev-parse", f"{SEALED_FIX_COMMIT}:{path}")
    if merge_blob != expected_blob or fix_blob != expected_blob:
        fail(f"Fleet playback merge/fix blob mismatch: {path}")

expected_before = {
    **{f"{REL_AUDIT}/{path}": " M" for path in HUMAN_RELATIVE_PATHS},
    FINDINGS_REL: " M",
    BUILDER_REL: " M",
    HANDOFF_REL: "??",
    MATERIALIZER_REL: "??",
}
expected_after = dict(expected_before)
expected_after[OUTPUT_REL] = "??"
dirty = parse_status()
if dirty not in (expected_before, expected_after):
    fail(f"RUN-203 dirty boundary mismatch: {dirty}")
if DASHBOARD_REL in dirty:
    fail("RUN-203 must preserve audit-dashboard.html byte-for-byte")
if git_text("diff", "--check"):
    fail("RUN-203 prepared reporting diff fails git diff --check")

dashboard_bytes = (REPO_ROOT / DASHBOARD_REL).read_bytes()
if len(dashboard_bytes) != RUN_202_DASHBOARD_BYTES:
    fail("RUN-202 dashboard byte-size mismatch")
if dashboard_bytes.count(b"\n") != RUN_202_DASHBOARD_LINES:
    fail("RUN-202 dashboard line-count mismatch")
if sha256_bytes(dashboard_bytes) != RUN_202_DASHBOARD_SHA256:
    fail("RUN-203 did not preserve the exact RUN-202 dashboard")
if dashboard_bytes != git_bytes("show", f"{REPORTING_INPUT_COMMIT}:{DASHBOARD_REL}"):
    fail("Working dashboard differs from committed RUN-202 dashboard")

committed_builder = git_bytes("show", f"{REPORTING_INPUT_COMMIT}:{BUILDER_REL}")
if sha256_bytes(committed_builder) != PRE_RUN_203_BUILDER_SHA256:
    fail("Pre-RUN-203 committed builder pin mismatch")
if sha256_file(RUN_202_MATERIALIZER_REL) != RUN_202_MATERIALIZER_SHA256:
    fail("RUN-202 dashboard materializer pin mismatch")
run_202_materializer_data = (REPO_ROOT / RUN_202_MATERIALIZER_REL).read_bytes()
if (
    len(run_202_materializer_data) != RUN_202_MATERIALIZER_BYTES
    or run_202_materializer_data.count(b"\n") != RUN_202_MATERIALIZER_LINES
):
    fail("RUN-202 dashboard materializer size/line pin mismatch")
if sha256_file(RUN_202_RECEIPT_REL) != RUN_202_RECEIPT_SHA256:
    fail("RUN-202 dashboard receipt pin mismatch")
run_202_receipt_data = (REPO_ROOT / RUN_202_RECEIPT_REL).read_bytes()
if (
    len(run_202_receipt_data) != RUN_202_RECEIPT_BYTES
    or run_202_receipt_data.count(b"\n") != RUN_202_RECEIPT_LINES
):
    fail("RUN-202 dashboard receipt size/line pin mismatch")
run_202 = decode_json_strict(run_202_receipt_data, "RUN-202 dashboard receipt")
validate_self_seal(run_202, RUN_202_RECEIPT_SELF_SEAL, "RUN-202 dashboard receipt")
if run_202["schema_version"] != "run-202-audit-dashboard-verification-wave-41-v1":
    fail("RUN-202 dashboard receipt schema mismatch")
if run_202["run_id"] != "RUN-202-AUDIT-DASHBOARD-VERIFICATION-WAVE-41":
    fail("RUN-202 dashboard receipt run identity mismatch")
if run_202["pins"]["final_run_202_dashboard"] != {
    "path": "audit-dashboard.html",
    "sha256": RUN_202_DASHBOARD_SHA256,
    "git_blob_id": "03442cdb7ec6e17ae55b61494932171bff1e33f4",
    "bytes": RUN_202_DASHBOARD_BYTES,
    "lines": RUN_202_DASHBOARD_LINES,
}:
    fail("RUN-202 receipt dashboard record mismatch")
snapshot = run_202["reported_snapshot"]
if snapshot["finding_lineage"] != {
    "records": 19,
    "provisional": 8,
    "historical_already_fixed": 2,
    "historical_remediated": 9,
    "bounded_tests": 198,
    "bounded_assertions": 2716,
    "final_P0": 0,
    "final_P1": 0,
}:
    fail("RUN-202 reported finding snapshot mismatch")
if snapshot["static"] != {
    "source_owner_records": 667,
    "route_owner_records": 310,
    "page_owner_records": 357,
    "static_controller_action_bridges": 98,
    "bounded_source_denominator": 3929,
    "bounded_source_residual": 3262,
    "bounded_source_ownership_percent": "16.976330",
}:
    fail("RUN-202 static snapshot mismatch")
if snapshot["queue"] != {
    "reviewed": 121,
    "pending": 386,
    "owned": 99,
    "shared": 10,
    "alias": 5,
    "gap": 7,
    "without_ownership": 408,
}:
    fail("RUN-202 queue snapshot mismatch")
viewports = run_202["current_browser_verification"]["viewports"]
if set(viewports) != {"1440x900", "1280x800", "1024x768", "390x844"}:
    fail("RUN-202 viewport set mismatch")
for viewport, result in viewports.items():
    if result["visible_text_passed"] != 48 or result["visible_text_total"] != 48:
        fail(f"RUN-202 visible checks mismatch at {viewport}")
    if result["page_horizontal_overflow"] or result["table_containment_failures"] != 0:
        fail(f"RUN-202 visual containment mismatch at {viewport}")
browser = run_202["current_browser_verification"]
if browser["console"]["messages"] or browser["console"]["page_errors"]:
    fail("RUN-202 browser console/page errors were not empty")
if browser["console"]["warning_or_error_logs"]:
    fail("RUN-202 browser warning/error logs were not empty")
if run_202["html_graph"]["unique_local_resources"] != 509:
    fail("RUN-202 resource count mismatch")
if run_202["html_graph"]["existing_unique_local_resources"] != 509:
    fail("RUN-202 existing-resource count mismatch")
if run_202["html_graph"]["anchor_element_count"] != 995:
    fail("RUN-202 anchor count mismatch")
if run_202["html_graph"]["duplicate_id_count"] != 0:
    fail("RUN-202 duplicate authored IDs were not zero")
if run_202["finalization_state"]["navigation_verified_count"] != 10:
    fail("RUN-202 navigation verification count mismatch")
if not all(
    run_202["finalization_state"][key]
    for key in ("browser_complete", "resource_complete", "cleanup_complete")
):
    fail("RUN-202 finalization state incomplete")
if not run_202["server_cleanup"]["complete"]:
    fail("RUN-202 server cleanup incomplete")
run_202_gates = run_202["completion_gates"]
if len(run_202_gates) != 26 or any(row["complete"] for row in run_202_gates):
    fail("RUN-202 completion-gate boundary mismatch")

handoff_data = (REPO_ROOT / HANDOFF_REL).read_bytes()
if sha256_bytes(handoff_data) != HANDOFF_SHA256:
    fail("RUN-203 coordination-handoff SHA mismatch")
if git_text("hash-object", "--no-filters", HANDOFF_REL) != HANDOFF_GIT_BLOB:
    fail("RUN-203 coordination-handoff Git blob mismatch")
if len(handoff_data) != HANDOFF_BYTES or handoff_data.count(b"\n") != HANDOFF_LINES:
    fail("RUN-203 coordination-handoff size/line pin mismatch")
handoff = decode_json_strict(handoff_data, "RUN-203 coordination handoff")
validate_self_seal(handoff, HANDOFF_SELF_SEAL, "RUN-203 coordination handoff")
if handoff["schema_version"] != (
    "oblivion_fleet_trip_playback_data_point_eligibility_coordination_handoff_v1"
):
    fail("Fleet playback coordination-handoff schema mismatch")
if handoff["evidence_kind"] != (
    "COORDINATION_HANDOFF_TRANSCRIPTION_NOT_ORIGINAL_RUNTIME_RECEIPT"
):
    fail("Fleet playback coordination-handoff evidence-kind mismatch")
if handoff["status"] != "SEALED_DELEGATED_EVIDENCE_FOR_BOUNDED_REPORTING_ONLY":
    fail("Fleet playback coordination-handoff status mismatch")
if handoff["source"]["source_thread_id"] != "01a04fe4-0a67-7912-bae7-e133b73475fd":
    fail("Fleet playback coordination-handoff source task mismatch")
expected_handoff_finding = {
    "id": "FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01",
    "record_status":
        "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING",
    "feature_id": "CAP-FLEET-VEHICLE-REGISTER",
    "candidate_feature_id": "CAP-FLEET-VEHICLE-REGISTER",
    "related_feature_ids": [],
    "feature_identity_status": "PENDING_FRESH_SEMANTIC_REVIEW",
    "priority": "P1",
    "priority_boundary":
        "HISTORICAL_REMEDIATED_P1_NOT_CURRENT_PROVISIONAL_OR_FINAL_PRIORITY_COUNT",
}
if handoff["finding"] != expected_handoff_finding:
    fail("Fleet playback coordination-handoff finding identity mismatch")
expected_handoff_pins = {
    "application_baseline_commit": APPLICATION_BASELINE_COMMIT,
    "application_baseline_tree": APPLICATION_BASELINE_TREE,
    "advanced_main_commit": ADVANCED_MAIN_COMMIT,
    "advanced_main_tree": ADVANCED_MAIN_TREE,
    "sealed_fix_commit": SEALED_FIX_COMMIT,
    "sealed_fix_tree": SEALED_FIX_TREE,
    "stable_patch_id": SEALED_FIX_STABLE_PATCH_ID,
    "local_main_merge_commit": REPORTING_INPUT_COMMIT,
    "local_main_merge_tree": REPORTING_INPUT_TREE,
    "local_main_merge_parents": REPORTING_INPUT_PARENTS,
    "origin_main_observed": ORIGIN_MAIN,
}
if handoff["pins"] != expected_handoff_pins:
    fail("Fleet playback coordination-handoff lineage mismatch")
handoff_blobs = {
    row["path"]: row["merge_blob"] for row in handoff["exact_application_test_paths"]
}
if (
    len(handoff["exact_application_test_paths"]) != 2
    or handoff_blobs != EXACT_APPLICATION_TEST_BLOBS
):
    fail("Fleet playback coordination-handoff path/blob boundary mismatch")
if handoff["reproduction"] != {
    "command_text": None,
    "test_files": [
        "tests/Feature/FleetAssets/FleetTripPlaybackSitePrivacyTest.php"
    ],
    "failed": 1,
    "passed": 0,
    "pending": 0,
    "assertions": 3,
    "duration_seconds": 153.91,
    "exit_code": 1,
    "observed_failure": (
        "More than 2,000 earlier coordinate-incomplete trip telemetry rows occupied "
        "the chronological cap before projection, crowding a later coordinate-complete "
        "point out of playback data."
    ),
    "credit": "REPRODUCTION_ONLY_ZERO_BOUNDED_PASS_DENOMINATOR_CREDIT",
}:
    fail("Fleet playback red reproduction accounting mismatch")
if handoff["invalid_reproduction_attempt"]["classification"] != (
    "ENVIRONMENT_INVALID_SHARED_VENDOR_CLASSMAP_ZERO_EVIDENCE_CREDIT"
):
    fail("Fleet playback invalid reproduction classification mismatch")
if handoff["invalid_reproduction_attempt"]["credit"]:
    fail("Fleet playback invalid reproduction attempt received credit")
verification = handoff["verification"]
if verification["isolated_focused"] != {
    "tests": 1,
    "assertions": 6,
    "duration_seconds": 160.74,
    "exit_code": 0,
    "credit": "DUPLICATE_REPLAY_ZERO_ADDITIONAL_DENOMINATOR_CREDIT",
}:
    fail("Fleet playback isolated-focused accounting mismatch")
if verification["isolated_combined"] != {
    "tests": 27,
    "assertions": 213,
    "duration_seconds": 168.04,
    "exit_code": 0,
    "credit": "SUPPORT_AND_REPLAY_ZERO_ADDITIONAL_DENOMINATOR_CREDIT",
}:
    fail("Fleet playback isolated-combined accounting mismatch")
post_merge = verification["post_merge_combined"]
if (
    post_merge["tests"] != 27
    or post_merge["assertions"] != 213
    or post_merge["duration_seconds"] != 185.24
    or post_merge["exit_code"] != 0
):
    fail("Fleet playback post-merge accounting mismatch")
if post_merge["credited_component"] != {
    "test_method":
        "test_playback_data_caps_only_after_excluding_coordinate_less_events",
    "tests": 1,
    "assertions": 6,
    "credit": "COUNT_ONCE_IN_BOUNDED_DISPOSITION_DENOMINATOR",
}:
    fail("Fleet playback credited component mismatch")
if post_merge["already_credited_playback_component"]["credit"] != (
    "PRIOR_RUN_183_COMPONENT_ZERO_REPEAT_CREDIT"
):
    fail("Fleet playback prior component received repeat credit")
if post_merge["unchanged_fleet_management_component"]["credit"] != (
    "COMPATIBILITY_SUPPORT_ZERO_BOUNDED_DENOMINATOR_CREDIT"
):
    fail("Fleet management support component received credit")
if not verification["merge_blobs_byte_identical_to_sealed_fix"]:
    fail("Fleet playback handoff did not attest merge-blob identity")
if handoff["independent_review"]["final_status"] != "GO":
    fail("Fleet playback independent review was not GO")
if handoff["independent_review"]["remaining_actionable_findings"] != 0:
    fail("Fleet playback independent review retained actionable findings")
if handoff["cleanup"]["global_php_pest_process_count"] != 0:
    fail("Fleet playback cleanup retained PHP/Pest processes")
if handoff["cleanup"]["numeric_pid_suffixed_schema_count"] != 0:
    fail("Fleet playback cleanup retained numeric PID schemas")
if handoff["cleanup"]["push_performed"]:
    fail("Fleet playback handoff claims a push")
expected_accounting = {
    "previous_unique_tests": 198,
    "previous_unique_assertions": 2716,
    "credited_increment_tests": 1,
    "credited_increment_assertions": 6,
    "current_unique_tests": 199,
    "current_unique_assertions": 2722,
    "exclusions": [
        "Valid red reproduction: 1 failed test and 3 assertions.",
        "Environment-invalid shared-vendor/classmap red attempt in full.",
        "Isolated focused 1 test and 6 assertions as a duplicate replay.",
        "Isolated combined 27 tests and 213 assertions as replay and support evidence.",
        "The previously credited playback 11 tests and 167 assertions inside the post-merge combined run.",
        "The unchanged FleetManagement 15 tests and 40 assertions inside the post-merge combined run.",
        "Any second count of the post-merge combined 27 tests and 213 assertions.",
    ],
}
if handoff["bounded_accounting"] != expected_accounting:
    fail("Fleet playback coordination-handoff bounded accounting mismatch")
if any(handoff["noninheritance"].values()):
    fail("Fleet playback noninheritance boundary contains credited fields")
if handoff["completion_credit"]:
    fail("Fleet playback coordination handoff must retain zero completion credit")

committed_findings_bytes = git_bytes("show", f"{REPORTING_INPUT_COMMIT}:{FINDINGS_REL}")
if sha256_bytes(committed_findings_bytes) != PRE_RUN_203_FINDINGS_SHA256:
    fail("Pre-RUN-203 committed findings pin mismatch")
committed_findings = decode_json_strict(
    committed_findings_bytes,
    "committed pre-RUN-203 findings",
)
findings = read_json_strict(FINDINGS_REL)
if len(committed_findings["records"]) != 19:
    fail("Pre-RUN-203 findings register did not contain exactly 19 records")
if findings["records"][:19] != committed_findings["records"]:
    fail("RUN-203 changed one or more of the first 19 retained finding records")
if findings["audit_status"] != (
    "EIGHT_PROVISIONAL_TWO_HISTORICAL_ALREADY_FIXED_"
    "TEN_HISTORICAL_REMEDIATED_ZERO_FINAL_FINDING_CREDIT"
):
    fail("RUN-203 findings status mismatch")
expected_findings_pins = {
    "run_202_dashboard_verification_materializer_sha256":
        RUN_202_MATERIALIZER_SHA256,
    "run_202_dashboard_verification_sha256": RUN_202_RECEIPT_SHA256,
    "run_202_dashboard_verification_self_seal_sha256":
        RUN_202_RECEIPT_SELF_SEAL,
    "run_202_verified_dashboard_sha256": RUN_202_DASHBOARD_SHA256,
    "fleet_trip_playback_data_point_eligibility_baseline_commit":
        APPLICATION_BASELINE_COMMIT,
    "fleet_trip_playback_data_point_eligibility_baseline_tree":
        APPLICATION_BASELINE_TREE,
    "fleet_trip_playback_data_point_eligibility_audit_release_commit":
        ADVANCED_MAIN_COMMIT,
    "fleet_trip_playback_data_point_eligibility_audit_release_tree":
        ADVANCED_MAIN_TREE,
    "fleet_trip_playback_data_point_eligibility_fix_commit": SEALED_FIX_COMMIT,
    "fleet_trip_playback_data_point_eligibility_fix_tree": SEALED_FIX_TREE,
    "fleet_trip_playback_data_point_eligibility_local_main_merge_commit":
        REPORTING_INPUT_COMMIT,
    "fleet_trip_playback_data_point_eligibility_local_main_tree":
        REPORTING_INPUT_TREE,
    "fleet_trip_playback_data_point_eligibility_stable_patch_id":
        SEALED_FIX_STABLE_PATCH_ID,
    "fleet_trip_playback_data_point_eligibility_origin_main_observed":
        ORIGIN_MAIN,
}
if {
    key: findings["pins"][key] for key in expected_findings_pins
} != expected_findings_pins:
    fail("RUN-203 findings lineage/dashboard pins mismatch")
expected_denominators = {
    "canonical_features": 340,
    "human_features": 300,
    "system_data_features": 40,
    "canonical_submodules": None,
    "historical_discovery_claim_records": 12,
    "current_retained_claim_records": 20,
    "current_provisional_source_claims": 8,
    "historical_already_fixed_records": 2,
    "historical_remediated_records": 10,
}
if findings["denominators"] != expected_denominators:
    fail("RUN-203 findings denominator summary mismatch")
expected_counts = {
    "retained_claim_records": 20,
    "provisional_source_claims": 8,
    "historical_already_fixed": 2,
    "historical_remediated": 10,
    "bounded_disposition_tests_passed": 199,
    "bounded_disposition_assertions": 2722,
    "final_P0": 0,
    "final_P1": 0,
}
if {
    key: findings["counts"][key] for key in expected_counts
} != expected_counts:
    fail("RUN-203 findings count mismatch")
expected_issue_counts = {
    "fleet_trip_playback_data_point_eligibility_credited_tests": 1,
    "fleet_trip_playback_data_point_eligibility_credited_assertions": 6,
    "fleet_trip_playback_data_point_eligibility_baseline_failed": 1,
    "fleet_trip_playback_data_point_eligibility_baseline_assertions": 3,
    "fleet_trip_playback_data_point_eligibility_isolated_focused_tests": 1,
    "fleet_trip_playback_data_point_eligibility_isolated_focused_assertions": 6,
    "fleet_trip_playback_data_point_eligibility_isolated_combined_tests": 27,
    "fleet_trip_playback_data_point_eligibility_isolated_combined_assertions": 213,
    "fleet_trip_playback_data_point_eligibility_post_merge_combined_tests": 27,
    "fleet_trip_playback_data_point_eligibility_post_merge_combined_assertions": 213,
}
if {
    key: findings["counts"][key] for key in expected_issue_counts
} != expected_issue_counts:
    fail("RUN-203 issue-specific findings accounting mismatch")
sum_basis = findings["counts"]["bounded_disposition_sum_basis"]
assert_contains(
    sum_basis,
    [
        "only the new post-merge 1/6 "
        "FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY component",
        "playback point-eligibility valid red 1/3",
        "environment-invalid shared-vendor/classmap attempt",
        "isolated focused 1/6",
        "isolated combined 27/213",
        "prior playback 11/167",
        "unchanged FleetManagement 15/40",
        "any second count from post-merge 27/213",
    ],
    "RUN-203 bounded-disposition sum basis",
)

record_ids = [row["id"] for row in findings["records"]]
if len(record_ids) != len(set(record_ids)) or len(record_ids) != 20:
    fail("RUN-203 retained finding identities are not exactly 20 unique values")
if record_ids[-1] != "FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01":
    fail("RUN-203 finding was not appended as the twentieth record")
issue = findings["records"][-1]
if issue["record_status"] != (
    "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
):
    fail("RUN-203 finding status mismatch")
if (
    issue["feature_id"] != "CAP-FLEET-VEHICLE-REGISTER"
    or issue["candidate_feature_id"] != "CAP-FLEET-VEHICLE-REGISTER"
):
    fail("RUN-203 finding candidate feature association mismatch")
if issue["related_feature_ids"] != []:
    fail("RUN-203 finding related-feature boundary mismatch")
if issue["feature_identity_status"] != "PENDING_FRESH_SEMANTIC_REVIEW":
    fail("RUN-203 finding feature-identity status mismatch")
if issue["feature_id_role"] != (
    "CANDIDATE_REPORTING_ASSOCIATION_ONLY_ZERO_STATIC_OWNERSHIP_CREDIT"
):
    fail("RUN-203 finding feature-role boundary mismatch")
adjudication = issue["current_adjudication"]
expected_lineage = {
    "application_baseline_commit": APPLICATION_BASELINE_COMMIT,
    "application_baseline_tree": APPLICATION_BASELINE_TREE,
    "audit_release_commit": ADVANCED_MAIN_COMMIT,
    "audit_release_tree": ADVANCED_MAIN_TREE,
    "fix_commit": SEALED_FIX_COMMIT,
    "fix_tree": SEALED_FIX_TREE,
    "stable_patch_id": SEALED_FIX_STABLE_PATCH_ID,
    "application_commit": REPORTING_INPUT_COMMIT,
    "repository_tree": REPORTING_INPUT_TREE,
    "origin_main_observed": ORIGIN_MAIN,
}
if {
    key: adjudication[key] for key in expected_lineage
} != expected_lineage:
    fail("RUN-203 finding lineage mismatch")
if adjudication["verdict"] != "REPRODUCED_AND_REMEDIATED_LOCAL_MAIN_NOT_PUBLISHED":
    fail("RUN-203 finding verdict mismatch")
if not adjudication["application_remediation_required"]:
    fail("RUN-203 finding must retain application-remediation provenance")
if not adjudication["application_source_changed"] or not adjudication["integrated_to_main"]:
    fail("RUN-203 finding integration provenance mismatch")
if not adjudication["candidate_feature_association_only"]:
    fail("RUN-203 finding must retain candidate-only association")
for key in (
    "published_to_origin_main",
    "publication_authorized",
    "feature_identity_assigned",
    "static_route_or_page_feature_ownership_inherited",
    "static_controller_action_bridge_inherited",
    "queue_advance_inherited",
    "prior_playback_privacy_credit_inherited",
    "fleet_management_correctness_inherited",
    "permission_site_or_direct_object_credit_inherited",
    "telemetry_ingest_lifecycle_or_range_credit_inherited",
    "map_frontend_or_adjacent_fleet_credit_inherited",
):
    if adjudication[key]:
        fail(f"RUN-203 finding improperly inherits credit: {key}")
evidence = issue["evidence"]
if evidence["tests_executed"] != 27 or evidence["assertions"] != 213:
    fail("RUN-203 finding combined execution mismatch")
if (
    evidence["credited_component_tests"] != 1
    or evidence["credited_component_assertions"] != 6
):
    fail("RUN-203 finding credited component mismatch")
if (
    evidence["baseline_failed_cases"] != 1
    or evidence["baseline_passed_cases"] != 0
    or evidence["baseline_pending_cases"] != 0
    or evidence["baseline_assertions"] != 3
):
    fail("RUN-203 finding baseline outcome mismatch")
if (
    evidence["isolated_focused_tests"] != 1
    or evidence["isolated_focused_assertions"] != 6
    or evidence["isolated_combined_tests"] != 27
    or evidence["isolated_combined_assertions"] != 213
):
    fail("RUN-203 finding isolated replay mismatch")
if evidence["coordination_handoff_transcription"] != HANDOFF_REL.removeprefix(
    f"{REL_AUDIT}/"
):
    fail("RUN-203 finding coordination-handoff path mismatch")
if evidence["reporting_receipt"] != OUTPUT_REL.removeprefix(f"{REL_AUDIT}/"):
    fail("RUN-203 finding reporting-receipt path mismatch")
if not evidence["delegated_not_reexecuted_by_run_203"]:
    fail("RUN-203 finding must state delegated execution was not re-run")
if evidence["test_commands_executed"] is not None or evidence["test_command_text"] is not None:
    fail("RUN-203 must not claim test-command authorship")
if issue["completion_credit"]:
    fail("RUN-203 finding must retain zero completion credit")
if any(issue["credit"].values()):
    fail("RUN-203 finding credit map must remain entirely false")
route = issue["route_url"]
if (
    route["route_names"] != "fleet-assets.trips.playback.data"
    or route["ownership_status"] != "PENDING_FRESH_SEMANTIC_REVIEW"
):
    fail("RUN-203 finding route locator/ownership mismatch")
if issue["backend_anchor"]["claim_anchors"] != [
    "app/Http/Controllers/Fleet/FleetTripController.php:88-121",
    "tests/Feature/FleetAssets/FleetTripPlaybackSitePrivacyTest.php:552-600",
]:
    fail("RUN-203 finding backend anchors mismatch")
criteria = issue["acceptance_criteria"]["given_when_then"]
assert_contains(
    criteria,
    [
        "more than 2,000 earlier coordinate-incomplete telemetry rows",
        "later coordinate-complete point",
        "incomplete rows do not consume cap slots",
        "chronological response order",
    ],
    "RUN-203 acceptance criteria",
)

reconciliation = findings["reconciliation"]
if reconciliation["retained_record_count"] != 20:
    fail("RUN-203 reconciliation retained-record count mismatch")
if reconciliation["current_provisional_count"] != 8:
    fail("RUN-203 reconciliation provisional count mismatch")
if reconciliation["historical_already_fixed_count"] != 2:
    fail("RUN-203 reconciliation historical-fixed count mismatch")
if reconciliation["historical_remediated_count"] != 10:
    fail("RUN-203 reconciliation remediated count mismatch")
if reconciliation["records_without_primary_or_candidate_feature_id"] != [
    "MON-METRIC-REPLAY-DEDUPE-01",
    "SUMMARY-TIMELINE-SITE-PRIVACY-01",
    "SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01",
    "ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01",
]:
    fail("RUN-203 feature-unassigned reconciliation mismatch")
if reconciliation["final_ids_cross_file_reconciled"]:
    fail("RUN-203 must not promote final-finding reconciliation")

required_human_markers = {
    "00-executive-summary.md": [
        "## RUN-202–203 Fleet playback data-point eligibility checkpoint",
        "FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01",
        "20 = 8 provisional + 2 historical already fixed + 10 historical remediated",
        "199 tests / 2,722 assertions",
        "RUN-204",
    ],
    "01-repository-module-map.md": [
        "## RUN-203 Fleet trip playback data-point eligibility record and module boundary",
        "FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01",
        "as the tenth `HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING` record",
        "199/2,722",
        "RUN-204",
    ],
    "07-module-findings.md": [
        "## RUN-203 retained Fleet trip playback data-point eligibility record",
        "FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01",
        "20 = 8 + 2 + 10",
        "199/2,722",
        "RUN-204",
    ],
    "11-prioritised-roadmap.md": [
        "## RUN-203 Fleet playback data-point eligibility reporting priority",
        "FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01",
        "20 retained records = 8 current provisional + 2 historical already fixed + 10 historical remediated",
        "199/2,722",
        "RUN-204",
    ],
    "12-native-build-and-do-not-copy-register.md": [
        "## RUN-203 native Fleet playback point-eligibility boundary",
        "FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01",
        "new post-merge **1/6** component",
        "RUN-204",
    ],
    "13-unresolved-questions-and-evidence-gaps.md": [
        "## RUN-203 Fleet playback data-point eligibility evidence gaps",
        "FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01",
        "199/2,722",
        "RUN-204",
    ],
}
for relative_path, markers in required_human_markers.items():
    text = (AUDIT_DIR / relative_path).read_text(encoding="utf-8")
    assert_contains(text, markers, relative_path)

builder_text = (REPO_ROOT / BUILDER_REL).read_text(encoding="utf-8")
try:
    compile(builder_text, BUILDER_REL, "exec", dont_inherit=True, optimize=0)
except SyntaxError as exc:
    fail(f"RUN-203 builder does not compile: {exc}")
assert_contains(
    builder_text,
    [
        "run_203_coordination_handoff",
        "run_203_reporting",
        "Fresh RUN-204 audit-dashboard verification required",
        ".tmp-run204-dashboard",
        "FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01",
        "EIGHT_PROVISIONAL_TWO_HISTORICAL_ALREADY_FIXED_TEN_HISTORICAL_REMEDIATED_ZERO_FINAL_FINDING_CREDIT",
        "== 20",
        "== 199",
        "== 2722",
        HANDOFF_REL.removeprefix(f"{REL_AUDIT}/"),
        MATERIALIZER_REL.removeprefix(f"{REL_AUDIT}/"),
        OUTPUT_REL.removeprefix(f"{REL_AUDIT}/"),
        "generators/materialize-run-204-audit-dashboard-verification-wave-42.py",
        "evidence/browser/current-audit-dashboard-verification-run-204-wave-42.json",
    ],
    "RUN-203 builder",
)

completion_gates = [
    {
        "name": row["name"],
        "complete": False,
        "reason": "RUN203 reports one bounded historical remediation record only",
    }
    for row in run_202_gates
]
completion_boundary = {row["name"]: False for row in completion_gates}
if len(completion_boundary) != 26:
    fail("RUN-203 completion-gate names are not exactly 26 unique values")

source_files = {
    path: file_metadata(f"{REL_AUDIT}/{path}")
    for path in REPORTING_SOURCE_RELATIVE_PATHS
}
receipt: dict[str, Any] = {
    "schema_version":
        "run-203-fleet-trip-playback-data-point-eligibility-remediation-reporting-wave-42-v1",
    "run_id":
        "RUN-203-FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01-REMEDIATION-REPORTING-WAVE-42",
    "status":
        "FLEET_TRIP_PLAYBACK_DATA_POINT_ELIGIBILITY_HISTORICAL_REMEDIATION_REPORTING_"
        "MATERIALIZED_DASHBOARD_RUN204_REQUIRED_ZERO_STATIC_PUBLICATION_FINAL_FINDING_"
        "OR_COMPLETION_CREDIT",
    "evidence_date": "2026-09-01",
    "scope": {
        "finding_id": "FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01",
        "type": "AUDIT_REPORTING_ONLY",
        "architecture": "SINGLE_ORGANISATION_MULTI_SITE",
        "application_or_test_source_mutated_by_run_203": False,
        "runtime_database_browser_or_build_executed_by_run_203": False,
        "dashboard_html_mutated_by_run_203": False,
        "delegated_runtime_or_review_authorship_claimed_by_run_203": False,
    },
    "pins": {
        "reporting_input_commit": REPORTING_INPUT_COMMIT,
        "reporting_input_tree": REPORTING_INPUT_TREE,
        "reporting_input_parents": REPORTING_INPUT_PARENTS,
        "reporting_input_subject": REPORTING_INPUT_SUBJECT,
        "origin_main_observed": ORIGIN_MAIN,
        "application_baseline": {
            "commit": APPLICATION_BASELINE_COMMIT,
            "tree": APPLICATION_BASELINE_TREE,
        },
        "advanced_main_before_merge": {
            "commit": ADVANCED_MAIN_COMMIT,
            "tree": ADVANCED_MAIN_TREE,
        },
        "sealed_fix": {
            "commit": SEALED_FIX_COMMIT,
            "tree": SEALED_FIX_TREE,
            "stable_patch_id": SEALED_FIX_STABLE_PATCH_ID,
        },
        "exact_application_test_blobs": EXACT_APPLICATION_TEST_BLOBS,
        "run_202_dashboard": {
            "path": "audit-dashboard.html",
            "sha256": RUN_202_DASHBOARD_SHA256,
            "bytes": RUN_202_DASHBOARD_BYTES,
            "lines": RUN_202_DASHBOARD_LINES,
        },
        "run_202_materializer": file_metadata(RUN_202_MATERIALIZER_REL),
        "run_202_receipt": file_metadata(RUN_202_RECEIPT_REL),
        "run_202_receipt_self_seal_sha256": RUN_202_RECEIPT_SELF_SEAL,
        "coordination_handoff": {
            **file_metadata(HANDOFF_REL),
            "git_blob_id": HANDOFF_GIT_BLOB,
            "receipt_self_seal_sha256": HANDOFF_SELF_SEAL,
        },
        "pre_run_203_findings_sha256": PRE_RUN_203_FINDINGS_SHA256,
        "pre_run_203_builder_sha256": PRE_RUN_203_BUILDER_SHA256,
        "reporting_sources": source_files,
    },
    "reporting_transition": {
        "finding_id": "FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01",
        "counts_before": {
            "retained_claim_records": 19,
            "provisional_source_claims": 8,
            "historical_already_fixed": 2,
            "historical_remediated": 9,
            "final_P0": 0,
            "final_P1": 0,
        },
        "counts_after": {
            "retained_claim_records": 20,
            "provisional_source_claims": 8,
            "historical_already_fixed": 2,
            "historical_remediated": 10,
            "final_P0": 0,
            "final_P1": 0,
        },
        "feature_id": "CAP-FLEET-VEHICLE-REGISTER",
        "candidate_feature_id": "CAP-FLEET-VEHICLE-REGISTER",
        "feature_identity_status": "PENDING_FRESH_SEMANTIC_REVIEW",
        "static_ownership_or_queue_advance": False,
    },
    "bounded_execution_accounting": {
        "prior_unique_total": {"tests": 198, "assertions": 2716},
        "credited_increment": {"tests": 1, "assertions": 6},
        "unique_total": {"tests": 199, "assertions": 2722},
        "new_post_merge_component_counted_once": True,
        "delegated_coordination_evidence_not_reexecuted_by_run_203": True,
        "excluded": {
            "valid_red_reproduction": {"failed": 1, "assertions": 3},
            "environment_invalid_classmap_attempt": "ZERO_EVIDENCE_CREDIT",
            "isolated_focused_replay": {"tests": 1, "assertions": 6},
            "isolated_combined_replay_support": {"tests": 27, "assertions": 213},
            "prior_playback_component": {"tests": 11, "assertions": 167},
            "unchanged_fleet_management": {"tests": 15, "assertions": 40},
            "duplicate_post_merge_combined": {"tests": 27, "assertions": 213},
        },
    },
    "preservation_boundary": {
        "static_ownership": {
            "owners": 667,
            "routes": 310,
            "pages": 357,
            "controller_action_bridges": 98,
        },
        "queue": {
            "total": 507,
            "reviewed": 121,
            "pending": 386,
            "owned": 99,
            "without_ownership": 408,
            "next_zero_based_index": 86,
            "next_queue_id": "RUN090-ROUTE-0087",
            "next_route_record_id": "RUN077-ROUTE-0695",
            "next_route_name": "fleet-assets.trips.playback.data",
            "next_controller_action": "FleetTripController::playback",
        },
        "benchmark": {
            "mapped": 2,
            "targets": 340,
            "final_no_match_or_NCM": 0,
            "unresolved": 338,
        },
        "final_priority": {"P0": 0, "P1": 0},
        "completion_gates_true": 0,
    },
    "dashboard_forward_gate": {
        "required_run": "RUN-204",
        "dashboard_html_changed_by_run_203": False,
        "preserved_run_202_dashboard_sha256": RUN_202_DASHBOARD_SHA256,
        "generator":
            "generators/materialize-run-204-audit-dashboard-verification-wave-42.py",
        "receipt":
            "evidence/browser/current-audit-dashboard-verification-run-204-wave-42.json",
        "fresh_four_viewport_navigation_resource_console_verification_required": True,
        "forward_paths_intentionally_unhashed": True,
    },
    "evidence_quality_boundary": {
        "coordination_handoff_transcription_only": True,
        "original_issue_specific_runtime_receipt_present": False,
        "original_issue_specific_independent_review_receipt_present": False,
        "run_203_reexecuted_application_tests": False,
        "run_203_claims_original_runtime_or_review_authorship": False,
        "git_lineage_and_exact_blobs_reverified": True,
        "delegated_evidence_not_promoted_to_browser_release_or_completion": True,
        "environment_invalid_shared_vendor_classmap_attempt_zero_credit": True,
        "only_new_post_merge_one_test_six_assertion_component_counted": True,
    },
    "worktree_attestation": {
        "owned_paths": OWNED_RELATIVE_PATHS,
        "path_count": 11,
        "accepted_dirty_states_before_write": {
            "initial_materialization": expected_before,
            "deterministic_rerun": expected_after,
        },
        "application_or_test_dirt": [],
        "dashboard_html_dirty": False,
        "builder_compilation_only": True,
    },
    "materializer": file_metadata(MATERIALIZER_REL),
    "mutation_attestation": {
        "materializer_writes_only": [
            OUTPUT_REL.removeprefix(f"{REL_AUDIT}/")
        ],
        "atomic_exclusive_temp_write": True,
        "fsync_before_replace": True,
        "stale_temp_refused": True,
        "optimized_python_refused": True,
        "strict_duplicate_key_free_json": True,
        "canonical_self_seal": True,
        "deterministic_rerun_bytes": True,
    },
    "credit_boundary": {
        "live_findings_register_and_reporting_status": True,
        "application_source_or_test_change_by_run_203": False,
        "application_runtime_reexecution_by_run_203": False,
        "application_browser": False,
        "responsive_application_or_visual_credit": False,
        "audit_dashboard_build_execution_by_run_203": False,
        "static_route_or_page_feature_ownership": False,
        "static_controller_action_bridge": False,
        "queue_advance": False,
        "prior_playback_site_privacy_credit": False,
        "fleet_management_correctness": False,
        "permission_site_or_direct_object_correctness": False,
        "telemetry_ingest_lifecycle_range_or_write_correctness": False,
        "map_frontend_or_adjacent_fleet_correctness": False,
        "benchmark_mapping": False,
        "final_no_match_or_NCM": False,
        "ease": False,
        "full_suite_or_coverage": False,
        "pass": False,
        "deployment": False,
        "release": False,
        "publication": False,
        "final_finding": False,
        "feature_or_module_completion": False,
        "gate_4": False,
        "audit_complete": False,
    },
    "completion_gates": completion_gates,
    "artifact_completion_test_met": True,
    "audit_completion_test_met": False,
    "completion_boundary": completion_boundary,
    "remote_state": {
        "origin_main": ORIGIN_MAIN,
        "local_main_ahead": 96,
        "local_main_behind": 0,
        "push_performed": False,
        "publication_claimed": False,
    },
}

receipt_without_seal = dict(receipt)
receipt["receipt_self_seal_sha256"] = sha256_bytes(
    canonical_json_bytes(receipt_without_seal)
)
output_bytes = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode(
    "utf-8"
)
output_path = REPO_ROOT / OUTPUT_REL
temporary_path = output_path.with_name(f".{output_path.name}.tmp-run203-reporting")

if output_path.exists():
    if output_path.read_bytes() != output_bytes:
        fail("Existing RUN-203 receipt is not the deterministic expected payload")
else:
    if temporary_path.exists():
        fail(f"Refusing to overwrite stale RUN-203 temp file: {temporary_path}")
    try:
        with temporary_path.open("xb") as handle:
            handle.write(output_bytes)
            handle.flush()
            os.fsync(handle.fileno())
        if temporary_path.read_bytes() != output_bytes:
            fail("RUN-203 temporary receipt bytes mismatch")
        os.replace(temporary_path, output_path)
    finally:
        if temporary_path.exists():
            temporary_path.unlink()

if output_path.read_bytes() != output_bytes:
    fail("RUN-203 post-write receipt bytes mismatch")
written = read_json_strict(OUTPUT_REL)
validate_self_seal(
    written,
    receipt["receipt_self_seal_sha256"],
    "RUN-203 remediation-reporting receipt",
)
if parse_status() != expected_after:
    fail("RUN-203 post-write dirty boundary mismatch")
if sha256_bytes((REPO_ROOT / DASHBOARD_REL).read_bytes()) != RUN_202_DASHBOARD_SHA256:
    fail("RUN-203 changed the frozen RUN-202 dashboard")
if git_text("diff", "--check"):
    fail("RUN-203 post-write diff fails git diff --check")

print(
    json.dumps(
        {
            "run_id": receipt["run_id"],
            "output": OUTPUT_REL,
            "sha256": sha256_bytes(output_bytes),
            "receipt_self_seal_sha256": receipt["receipt_self_seal_sha256"],
            "dashboard_preserved_sha256": RUN_202_DASHBOARD_SHA256,
            "dashboard_forward_gate": "RUN-204",
            "status": "GO",
        },
        ensure_ascii=False,
        sort_keys=True,
    )
)
