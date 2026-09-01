#!/usr/bin/env python3
"""Materialize the bounded RUN-201 remediation-reporting receipt.

This program is intentionally reporting-only. It validates the exact integrated
ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01 lineage, the prepared reporting
transaction, and the still-frozen RUN-200 dashboard. It writes only the RUN-201
source receipt and forward-gates a separate RUN-202 dashboard build/browser run.
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
    "current-run-201-elig-shift-notification-site-privacy-coordination-handoff-wave-41.json"
)
MATERIALIZER_REL = (
    f"{REL_AUDIT}/generators/"
    "materialize-run-201-elig-shift-notification-site-privacy-remediation-reporting-wave-41.py"
)
OUTPUT_REL = (
    f"{REL_AUDIT}/evidence/source/"
    "current-run-201-elig-shift-notification-site-privacy-remediation-reporting-wave-41.json"
)
RUN_200_MATERIALIZER_REL = (
    f"{REL_AUDIT}/generators/materialize-run-200-audit-dashboard-verification-wave-40.py"
)
RUN_200_RECEIPT_REL = (
    f"{REL_AUDIT}/evidence/browser/current-audit-dashboard-verification-run-200-wave-40.json"
)

REPORTING_INPUT_COMMIT = "1382dd4a48b35d9f9155c2dd501a8a3f4f60d47c"
REPORTING_INPUT_TREE = "50ba282b5ded0d8d0d4f9fb19bf8e79f3ce96014"
REPORTING_INPUT_PARENTS = [
    "9c01f5a4f57f96722015278d1df3c3bd111aa95c",
    "95fb2677a417c69c2008fefcc0cf9404984c9b54",
]
REPORTING_INPUT_SUBJECT = "merge: scope shift eligibility alerts to Sites"

APPLICATION_BASELINE_COMMIT = "f7c6f9ee476534cbbc13042b68d5388e0681b535"
APPLICATION_BASELINE_TREE = "33f69dc0848cca66ad317e42ba8a61eba46ac1e4"
ADVANCED_MAIN_COMMIT = "9c01f5a4f57f96722015278d1df3c3bd111aa95c"
ADVANCED_MAIN_TREE = "c9b0f223e5c63870cc5c04708babece98c00435f"
SEALED_FIX_COMMIT = "95fb2677a417c69c2008fefcc0cf9404984c9b54"
SEALED_FIX_TREE = "412d3dc3ff3f9fd864b626b565ce419372cd2ee2"
SEALED_FIX_SUBJECT = "fix(eligibility): scope shift alerts to Sites"
SEALED_FIX_STABLE_PATCH_ID = "1381114bba1a102630a020211a07b303a1d6240d"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"

EXACT_APPLICATION_TEST_BLOBS = {
    "app/Jobs/EscalateUnresolvedEligibilityJob.php": "360d1fafa858341f1ffbc0ceecf64fda449fc5ec",
    "app/Jobs/RecalculateFutureShiftEligibility.php": "5bb72f86adb6165bf43b4f489f59e61bef3eb5bf",
    "tests/Feature/Rostering/ShiftEligibilityNotificationRecipientPrivacyTest.php": "056116c106f00740d1d51bb125ad924b98ebe225",
    "tests/Unit/Jobs/EscalateUnresolvedEligibilityJobTest.php": "4e30b5ed794b7260255a045c74a02f18619679da",
}
EXACT_FIRST_PARENT_STATUS = {
    "app/Jobs/EscalateUnresolvedEligibilityJob.php": "M",
    "app/Jobs/RecalculateFutureShiftEligibility.php": "M",
    "tests/Feature/Rostering/ShiftEligibilityNotificationRecipientPrivacyTest.php": "A",
    "tests/Unit/Jobs/EscalateUnresolvedEligibilityJobTest.php": "M",
}

PRE_RUN_201_FINDINGS_SHA256 = "3578c7a31f6592ee548f50d4afa130febf34d1dbaa0de448fb1cdc6c2600af0c"
RUN_200_DASHBOARD_SHA256 = "f643ca1ec1716cfb2b32864aba1a97e8d69c3e726453707a3ce71e76b3c43205"
RUN_200_DASHBOARD_BYTES = 345157
RUN_200_DASHBOARD_LINES = 78
RUN_200_MATERIALIZER_SHA256 = "023d06929555d20dbc242bb998e05ee7fc60c0917d0e62b39ab56356a74de578"
RUN_200_RECEIPT_SHA256 = "59b80aa14c8841f412d9b76003cc8f2dcd135634cd9394a43523bad31f62c520"
RUN_200_RECEIPT_SELF_SEAL = "493b62087f2df1f2ff776f68c162fceb38ab69763a0b2554ba0148dd6c58d216"
HANDOFF_SHA256 = "f17c4c8d91dd040fb0b142196f65fa2c7657160bfc232404d9b6fe629bd156b7"
HANDOFF_BYTES = 7628
HANDOFF_LINES = 160
HANDOFF_SELF_SEAL = "225a2548c1f2d0120e3edd5ef26feb02ad8616085a36aa2d502e81700e0da587"

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


def strict_object(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            fail(f"Duplicate JSON key: {key}")
        result[key] = value
    return result


def decode_json_strict(data: bytes, label: str) -> dict[str, Any]:
    try:
        parsed = json.loads(data.decode("utf-8"), object_pairs_hook=strict_object)
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        fail(f"Invalid strict JSON for {label}: {exc}")
    if not isinstance(parsed, dict):
        fail(f"Expected top-level JSON object for {label}")
    return parsed


def read_json_strict(relative_path: str) -> dict[str, Any]:
    return decode_json_strict((REPO_ROOT / relative_path).read_bytes(), relative_path)


def canonical_json_bytes(payload: dict[str, Any]) -> bytes:
    return json.dumps(
        payload,
        ensure_ascii=False,
        sort_keys=True,
        separators=(",", ":"),
    ).encode("utf-8")


def validate_self_seal(payload: dict[str, Any], expected: str, label: str) -> None:
    candidate = dict(payload)
    actual = candidate.pop("receipt_self_seal_sha256", None)
    if actual != expected:
        fail(f"{label} self-seal field mismatch")
    if sha256_bytes(canonical_json_bytes(candidate)) != expected:
        fail(f"{label} canonical self-seal mismatch")


def file_metadata(relative_path: str) -> dict[str, Any]:
    data = (REPO_ROOT / relative_path).read_bytes()
    return {
        "path": relative_path.removeprefix(f"{REL_AUDIT}/"),
        "sha256": sha256_bytes(data),
        "bytes": len(data),
        "lines": data.count(b"\n"),
    }


def parse_status() -> dict[str, str]:
    raw = git_bytes("status", "--porcelain=v1", "--untracked-files=all", "-z")
    rows = [row for row in raw.split(b"\0") if row]
    parsed: dict[str, str] = {}
    for row in rows:
        text = row.decode("utf-8")
        if len(text) < 4 or text[2] != " ":
            fail(f"Unsupported worktree status row: {text!r}")
        state = text[:2]
        path = text[3:].replace("\\", "/")
        if "R" in state or "C" in state:
            fail(f"Rename/copy is outside RUN-201 boundary: {text!r}")
        if path in parsed:
            fail(f"Duplicate worktree-status path in RUN-201 boundary: {path}")
        parsed[path] = state
    return parsed


def parse_name_status(base: str, head: str) -> dict[str, str]:
    output = git_text("diff", "--name-status", base, head)
    if not output:
        return {}
    parsed: dict[str, str] = {}
    for line in output.splitlines():
        fields = line.split("\t")
        if len(fields) != 2 or fields[0] not in {"A", "M", "D"}:
            fail(f"Unsupported name-status row: {line!r}")
        parsed[fields[1].replace("\\", "/")] = fields[0]
    return parsed


def stable_patch_id(commit: str) -> str:
    shown = subprocess.run(
        ["git", "show", "--pretty=format:", commit],
        cwd=REPO_ROOT,
        check=False,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    if shown.returncode != 0:
        fail("Unable to read sealed-fix patch")
    patched = subprocess.run(
        ["git", "patch-id", "--stable"],
        cwd=REPO_ROOT,
        check=False,
        input=shown.stdout,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    if patched.returncode != 0 or not patched.stdout.strip():
        fail("Unable to compute sealed-fix stable patch ID")
    return patched.stdout.decode("utf-8").split()[0]


def assert_contains(text: str, markers: list[str], label: str) -> None:
    missing = [marker for marker in markers if marker not in text]
    if missing:
        fail(f"{label} missing required markers: {missing}")


if not __debug__ or sys.flags.optimize != 0:
    fail("RUN-201 refuses optimized Python because assertions must remain active")

if Path(git_text("rev-parse", "--show-toplevel")).resolve() != REPO_ROOT.resolve():
    fail("RUN-201 repository root mismatch")
if git_text("rev-parse", "HEAD") != REPORTING_INPUT_COMMIT:
    fail("RUN-201 must execute at the exact Shift-notification remediation merge")
if git_text("show", "-s", "--format=%T", "HEAD") != REPORTING_INPUT_TREE:
    fail("RUN-201 reporting input tree mismatch")
if git_text("show", "-s", "--format=%P", "HEAD").split() != REPORTING_INPUT_PARENTS:
    fail("RUN-201 merge-parent lineage mismatch")
if git_text("show", "-s", "--format=%s", "HEAD") != REPORTING_INPUT_SUBJECT:
    fail("RUN-201 merge subject mismatch")

for commit, tree, label in (
    (APPLICATION_BASELINE_COMMIT, APPLICATION_BASELINE_TREE, "application baseline"),
    (ADVANCED_MAIN_COMMIT, ADVANCED_MAIN_TREE, "advanced main"),
    (SEALED_FIX_COMMIT, SEALED_FIX_TREE, "sealed fix"),
):
    if git_text("show", "-s", "--format=%T", commit) != tree:
        fail(f"RUN-201 {label} tree mismatch")
if git_text("show", "-s", "--format=%P", SEALED_FIX_COMMIT) != APPLICATION_BASELINE_COMMIT:
    fail("RUN-201 sealed fix parent mismatch")
if git_text("show", "-s", "--format=%s", SEALED_FIX_COMMIT) != SEALED_FIX_SUBJECT:
    fail("RUN-201 sealed fix subject mismatch")
if stable_patch_id(SEALED_FIX_COMMIT) != SEALED_FIX_STABLE_PATCH_ID:
    fail("RUN-201 sealed fix stable patch ID mismatch")
if git_text("rev-parse", "origin/main") != ORIGIN_MAIN:
    fail("RUN-201 origin/main moved from the observed nonpublication boundary")
behind_ahead = [int(value) for value in git_text(
    "rev-list", "--left-right", "--count", "origin/main...HEAD"
).split()]
if behind_ahead != [0, 92]:
    fail(f"RUN-201 origin/main comparison mismatch: {behind_ahead}")

if parse_name_status(ADVANCED_MAIN_COMMIT, REPORTING_INPUT_COMMIT) != EXACT_FIRST_PARENT_STATUS:
    fail("Shift-notification first-parent merge delta is not the exact four-path boundary")
if parse_name_status(APPLICATION_BASELINE_COMMIT, SEALED_FIX_COMMIT) != EXACT_FIRST_PARENT_STATUS:
    fail("Shift-notification sealed-fix delta is not the exact four-path boundary")
for path, expected_blob in EXACT_APPLICATION_TEST_BLOBS.items():
    merge_blob = git_text("rev-parse", f"{REPORTING_INPUT_COMMIT}:{path}")
    fix_blob = git_text("rev-parse", f"{SEALED_FIX_COMMIT}:{path}")
    if merge_blob != expected_blob or fix_blob != expected_blob:
        fail(f"Shift-notification merge/fix blob mismatch: {path}")

expected_before = {
    f"{REL_AUDIT}/{path}": " M" for path in HUMAN_RELATIVE_PATHS
}
expected_before.update(
    {
        FINDINGS_REL: " M",
        BUILDER_REL: " M",
        HANDOFF_REL: "??",
        MATERIALIZER_REL: "??",
    }
)
expected_after = dict(expected_before)
expected_after[OUTPUT_REL] = "??"
dirty = parse_status()
if dirty not in (expected_before, expected_after):
    fail(f"RUN-201 dirty boundary mismatch: {dirty}")
if DASHBOARD_REL in dirty:
    fail("RUN-201 must preserve audit-dashboard.html byte-for-byte")

dashboard_bytes = (REPO_ROOT / DASHBOARD_REL).read_bytes()
if len(dashboard_bytes) != RUN_200_DASHBOARD_BYTES:
    fail("RUN-200 dashboard byte-size mismatch")
if dashboard_bytes.count(b"\n") != RUN_200_DASHBOARD_LINES:
    fail("RUN-200 dashboard line-count mismatch")
if sha256_bytes(dashboard_bytes) != RUN_200_DASHBOARD_SHA256:
    fail("RUN-201 did not preserve the exact RUN-200 dashboard")
if dashboard_bytes != git_bytes("show", f"{REPORTING_INPUT_COMMIT}:{DASHBOARD_REL}"):
    fail("Working dashboard differs from committed RUN-200 dashboard")

if sha256_file(RUN_200_MATERIALIZER_REL) != RUN_200_MATERIALIZER_SHA256:
    fail("RUN-200 dashboard materializer pin mismatch")
if sha256_file(RUN_200_RECEIPT_REL) != RUN_200_RECEIPT_SHA256:
    fail("RUN-200 dashboard receipt pin mismatch")
run_200 = read_json_strict(RUN_200_RECEIPT_REL)
validate_self_seal(run_200, RUN_200_RECEIPT_SELF_SEAL, "RUN-200 dashboard receipt")
if run_200["schema_version"] != "run-200-audit-dashboard-verification-wave-40-v1":
    fail("RUN-200 dashboard receipt schema mismatch")
if run_200["run_id"] != "RUN-200-AUDIT-DASHBOARD-VERIFICATION-WAVE-40":
    fail("RUN-200 dashboard receipt run identity mismatch")
if run_200["pins"]["final_run_200_dashboard"]["sha256"] != RUN_200_DASHBOARD_SHA256:
    fail("RUN-200 receipt dashboard SHA mismatch")
if run_200["pins"]["final_run_200_dashboard"]["bytes"] != RUN_200_DASHBOARD_BYTES:
    fail("RUN-200 receipt dashboard size mismatch")
if run_200["pins"]["final_run_200_dashboard"]["lines"] != RUN_200_DASHBOARD_LINES:
    fail("RUN-200 receipt dashboard line mismatch")
viewports = run_200["current_browser_verification"]["viewports"]
if set(viewports) != {"1440x900", "1280x800", "1024x768", "390x844"}:
    fail("RUN-200 viewport set mismatch")
for viewport, result in viewports.items():
    if result["visible_text_passed"] != 48 or result["visible_text_total"] != 48:
        fail(f"RUN-200 visible checks mismatch at {viewport}")
    if result["page_horizontal_overflow"] or result["table_containment_failures"] != 0:
        fail(f"RUN-200 visual containment mismatch at {viewport}")
browser = run_200["current_browser_verification"]
if browser["console"]["messages"] or browser["console"]["page_errors"]:
    fail("RUN-200 browser console/page errors were not empty")
if browser["console"]["warning_or_error_logs"]:
    fail("RUN-200 browser warning/error logs were not empty")
if run_200["html_graph"]["unique_local_resources"] != 504:
    fail("RUN-200 resource count mismatch")
if run_200["html_graph"]["existing_unique_local_resources"] != 504:
    fail("RUN-200 existing-resource count mismatch")
if run_200["html_graph"]["anchor_element_count"] != 985:
    fail("RUN-200 anchor count mismatch")
if run_200["html_graph"]["duplicate_id_count"] != 0:
    fail("RUN-200 duplicate authored IDs were not zero")
if run_200["finalization_state"]["navigation_verified_count"] != 10:
    fail("RUN-200 navigation verification count mismatch")
if not all(
    run_200["finalization_state"][key]
    for key in ("browser_complete", "resource_complete", "cleanup_complete")
):
    fail("RUN-200 finalization state incomplete")
if not run_200["server_cleanup"]["complete"]:
    fail("RUN-200 server cleanup incomplete")
run_200_gates = run_200["completion_gates"]
if len(run_200_gates) != 26 or any(row["complete"] for row in run_200_gates):
    fail("RUN-200 completion-gate boundary mismatch")

handoff_data = (REPO_ROOT / HANDOFF_REL).read_bytes()
if sha256_bytes(handoff_data) != HANDOFF_SHA256:
    fail("RUN-201 coordination-handoff SHA mismatch")
if len(handoff_data) != HANDOFF_BYTES or handoff_data.count(b"\n") != HANDOFF_LINES:
    fail("RUN-201 coordination-handoff size/line pin mismatch")
handoff = decode_json_strict(handoff_data, "RUN-201 coordination handoff")
validate_self_seal(handoff, HANDOFF_SELF_SEAL, "RUN-201 coordination handoff")
if handoff["schema_version"] != "oblivion_elig_shift_notification_site_privacy_coordination_handoff_v1":
    fail("Shift-notification coordination-handoff schema mismatch")
if handoff["evidence_kind"] != "COORDINATION_HANDOFF_TRANSCRIPTION_NOT_ORIGINAL_RUNTIME_RECEIPT":
    fail("Shift-notification coordination-handoff evidence-kind mismatch")
if handoff["status"] != "SEALED_DELEGATED_EVIDENCE_FOR_BOUNDED_REPORTING_ONLY":
    fail("Shift-notification coordination-handoff status mismatch")
if handoff["finding"] != {
    "id": "ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01",
    "record_status": "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING",
    "feature_id": None,
    "candidate_feature_id": None,
    "related_feature_ids": [],
    "feature_identity_status": "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW",
    "priority": "P1",
    "priority_boundary": "HISTORICAL_REMEDIATED_P1_NOT_CURRENT_PROVISIONAL_OR_FINAL_PRIORITY_COUNT",
}:
    fail("Shift-notification coordination-handoff finding identity mismatch")
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
    fail("Shift-notification coordination-handoff lineage mismatch")
handoff_blobs = {
    row["path"]: row["merge_blob"] for row in handoff["exact_application_test_paths"]
}
if len(handoff["exact_application_test_paths"]) != 4 or handoff_blobs != EXACT_APPLICATION_TEST_BLOBS:
    fail("Shift-notification coordination-handoff path/blob boundary mismatch")
if handoff["reproduction"] != {
    "command_text": None,
    "test_files": [
        "tests/Feature/Rostering/ShiftEligibilityNotificationRecipientPrivacyTest.php"
    ],
    "failed": 1,
    "passed": 0,
    "pending": 4,
    "assertions": 1,
    "duration_seconds": 147.32,
    "exit_code": 1,
    "observed_failure": "A direct manager without access to the Shift Site received protected shift eligibility notification content on unchanged baseline source.",
    "credit": "REPRODUCTION_ONLY_ZERO_BOUNDED_PASS_DENOMINATOR_CREDIT",
}:
    fail("Shift-notification red reproduction accounting mismatch")
if handoff["verification"]["intermediate"] != {
    "tests": 12,
    "assertions": 23,
    "status": "NO_GO_STALE_SHIFT_SNAPSHOT",
    "credit": False,
}:
    fail("Shift-notification intermediate NO-GO accounting mismatch")
if handoff["verification"]["isolated_final"]["tests"] != 13:
    fail("Shift-notification isolated final test count mismatch")
if handoff["verification"]["isolated_final"]["assertions"] != 25:
    fail("Shift-notification isolated final assertion count mismatch")
if handoff["verification"]["post_merge"]["tests"] != 13:
    fail("Shift-notification post-merge test count mismatch")
if handoff["verification"]["post_merge"]["assertions"] != 25:
    fail("Shift-notification post-merge assertion count mismatch")
if not handoff["verification"]["merge_blobs_byte_identical_to_sealed_fix"]:
    fail("Shift-notification handoff did not attest merge-blob identity")
if handoff["independent_review"]["final_status"] != "GO":
    fail("Shift-notification independent review was not GO")
if handoff["independent_review"]["remaining_actionable_findings"] != 0:
    fail("Shift-notification independent review retained actionable findings")
if handoff["cleanup"]["global_php_pest_process_count"] != 0:
    fail("Shift-notification cleanup retained PHP/Pest processes")
if handoff["cleanup"]["numeric_pid_suffixed_schema_count"] != 0:
    fail("Shift-notification cleanup retained numeric PID schemas")
if handoff["cleanup"]["push_performed"]:
    fail("Shift-notification handoff claims a push")
expected_accounting = {
    "previous_unique_tests": 185,
    "previous_unique_assertions": 2691,
    "credited_increment_tests": 13,
    "credited_increment_assertions": 25,
    "current_unique_tests": 198,
    "current_unique_assertions": 2716,
    "exclusions": [
        "Red 1 failed plus 0 passed plus 4 pending and 1 assertion.",
        "Intermediate 12 tests and 23 assertions adjudicated NO-GO for a stale Shift snapshot.",
        "Isolated final 13 tests and 25 assertions as a duplicate replay.",
        "Any second count of the post-merge 13 tests and 25 assertions.",
    ],
}
if handoff["bounded_accounting"] != expected_accounting:
    fail("Shift-notification coordination-handoff bounded accounting mismatch")
if any(handoff["noninheritance"].values()):
    fail("Shift-notification noninheritance boundary contains credited fields")
if handoff["completion_credit"]:
    fail("Shift-notification coordination handoff must retain zero completion credit")

committed_findings_bytes = git_bytes("show", f"{REPORTING_INPUT_COMMIT}:{FINDINGS_REL}")
if sha256_bytes(committed_findings_bytes) != PRE_RUN_201_FINDINGS_SHA256:
    fail("Pre-RUN-201 committed findings pin mismatch")
committed_findings = decode_json_strict(committed_findings_bytes, "committed pre-RUN-201 findings")
findings = read_json_strict(FINDINGS_REL)
if len(committed_findings["records"]) != 18:
    fail("Pre-RUN-201 findings register did not contain exactly 18 records")
if findings["records"][:18] != committed_findings["records"]:
    fail("RUN-201 changed one or more of the first 18 retained finding records")
if findings["audit_status"] != "EIGHT_PROVISIONAL_TWO_HISTORICAL_ALREADY_FIXED_NINE_HISTORICAL_REMEDIATED_ZERO_FINAL_FINDING_CREDIT":
    fail("RUN-201 findings status mismatch")
expected_findings_pins = {
    "run_200_dashboard_verification_materializer_sha256": RUN_200_MATERIALIZER_SHA256,
    "run_200_dashboard_verification_sha256": RUN_200_RECEIPT_SHA256,
    "run_200_dashboard_verification_self_seal_sha256": RUN_200_RECEIPT_SELF_SEAL,
    "run_200_verified_dashboard_sha256": RUN_200_DASHBOARD_SHA256,
    "elig_shift_notification_site_privacy_baseline_commit": APPLICATION_BASELINE_COMMIT,
    "elig_shift_notification_site_privacy_baseline_tree": APPLICATION_BASELINE_TREE,
    "elig_shift_notification_site_privacy_audit_release_commit": ADVANCED_MAIN_COMMIT,
    "elig_shift_notification_site_privacy_audit_release_tree": ADVANCED_MAIN_TREE,
    "elig_shift_notification_site_privacy_fix_commit": SEALED_FIX_COMMIT,
    "elig_shift_notification_site_privacy_fix_tree": SEALED_FIX_TREE,
    "elig_shift_notification_site_privacy_local_main_merge_commit": REPORTING_INPUT_COMMIT,
    "elig_shift_notification_site_privacy_local_main_tree": REPORTING_INPUT_TREE,
    "elig_shift_notification_site_privacy_stable_patch_id": SEALED_FIX_STABLE_PATCH_ID,
    "elig_shift_notification_site_privacy_origin_main_observed": ORIGIN_MAIN,
}
if {key: findings["pins"][key] for key in expected_findings_pins} != expected_findings_pins:
    fail("RUN-201 findings lineage/dashboard pins mismatch")
expected_denominators = {
    "canonical_features": 340,
    "human_features": 300,
    "system_data_features": 40,
    "canonical_submodules": None,
    "historical_discovery_claim_records": 12,
    "current_retained_claim_records": 19,
    "current_provisional_source_claims": 8,
    "historical_already_fixed_records": 2,
    "historical_remediated_records": 9,
}
if findings["denominators"] != expected_denominators:
    fail("RUN-201 findings denominator summary mismatch")
expected_counts = {
    "retained_claim_records": 19,
    "provisional_source_claims": 8,
    "historical_already_fixed": 2,
    "historical_remediated": 9,
    "bounded_disposition_tests_passed": 198,
    "bounded_disposition_assertions": 2716,
    "final_P0": 0,
    "final_P1": 0,
}
if {key: findings["counts"][key] for key in expected_counts} != expected_counts:
    fail("RUN-201 findings count mismatch")
expected_issue_counts = {
    "elig_shift_notification_site_privacy_focused_tests": 13,
    "elig_shift_notification_site_privacy_focused_assertions": 25,
    "elig_shift_notification_site_privacy_baseline_failed": 1,
    "elig_shift_notification_site_privacy_baseline_passed": 0,
    "elig_shift_notification_site_privacy_baseline_pending": 4,
    "elig_shift_notification_site_privacy_baseline_assertions": 1,
    "elig_shift_notification_site_privacy_intermediate_no_go_tests": 12,
    "elig_shift_notification_site_privacy_intermediate_no_go_assertions": 23,
    "elig_shift_notification_site_privacy_isolated_replay_tests": 13,
    "elig_shift_notification_site_privacy_isolated_replay_assertions": 25,
}
if {key: findings["counts"][key] for key in expected_issue_counts} != expected_issue_counts:
    fail("RUN-201 issue-specific findings accounting mismatch")
sum_basis = findings["counts"]["bounded_disposition_sum_basis"]
if "one post-merge 13/25 ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY execution" not in sum_basis:
    fail("RUN-201 bounded-disposition sum basis omits the credited 13/25 component")
for excluded_marker in (
    "eligibility-alert red 1-failed/0-passed/4-pending/1-assertion reproduction",
    "reviewer-NO-GO intermediate 12/23",
    "isolated final 13/25 replay",
):
    if excluded_marker not in sum_basis:
        fail(f"RUN-201 bounded-disposition exclusions omit: {excluded_marker}")

record_ids = [row["id"] for row in findings["records"]]
if len(record_ids) != len(set(record_ids)) or len(record_ids) != 19:
    fail("RUN-201 retained finding identities are not exactly 19 unique values")
if record_ids[-1] != "ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01":
    fail("RUN-201 finding was not appended as the nineteenth record")
issue = findings["records"][-1]
if issue["record_status"] != "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING":
    fail("RUN-201 finding status mismatch")
if issue["feature_id"] is not None or issue["candidate_feature_id"] is not None:
    fail("RUN-201 finding must remain feature-unassigned")
if issue["related_feature_ids"] != []:
    fail("RUN-201 finding related-feature boundary mismatch")
if issue["feature_identity_status"] != "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW":
    fail("RUN-201 finding feature-identity status mismatch")
if issue["feature_id_role"] != "NO_CANONICAL_OR_CANDIDATE_FEATURE_ASSOCIATION_ZERO_STATIC_OWNERSHIP_CREDIT":
    fail("RUN-201 finding feature-role boundary mismatch")
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
if {key: adjudication[key] for key in expected_lineage} != expected_lineage:
    fail("RUN-201 finding lineage mismatch")
if adjudication["verdict"] != "REPRODUCED_AND_REMEDIATED_LOCAL_MAIN_NOT_PUBLISHED":
    fail("RUN-201 finding verdict mismatch")
if not adjudication["application_remediation_required"]:
    fail("RUN-201 finding must retain application-remediation provenance")
if not adjudication["application_source_changed"] or not adjudication["integrated_to_main"]:
    fail("RUN-201 finding integration provenance mismatch")
for key in (
    "published_to_origin_main",
    "publication_authorized",
    "feature_identity_assigned",
    "static_route_or_page_feature_ownership_inherited",
    "static_controller_action_bridge_inherited",
    "queue_advance_inherited",
    "shift_signal_emission_or_idempotency_correctness_inherited",
    "eligibility_rule_correctness_inherited",
    "user_site_access_service_correctness_inherited",
    "notification_transport_or_outbox_correctness_inherited",
    "broader_roster_shift_or_hr_privacy_inherited",
):
    if adjudication[key]:
        fail(f"RUN-201 finding improperly inherits credit: {key}")
evidence = issue["evidence"]
if evidence["tests_executed"] != 13 or evidence["assertions"] != 25:
    fail("RUN-201 finding credited execution mismatch")
if evidence["baseline_failed_cases"] != 1 or evidence["baseline_passed_cases"] != 0:
    fail("RUN-201 finding baseline outcome mismatch")
if evidence["baseline_pending_cases"] != 4 or evidence["baseline_assertions"] != 1:
    fail("RUN-201 finding baseline pending/assertion mismatch")
if evidence["intermediate_no_go_tests"] != 12 or evidence["intermediate_no_go_assertions"] != 23:
    fail("RUN-201 finding intermediate NO-GO mismatch")
if evidence["isolated_focused_tests"] != 13 or evidence["isolated_focused_assertions"] != 25:
    fail("RUN-201 finding isolated replay mismatch")
if evidence["coordination_handoff_transcription"] != HANDOFF_REL.removeprefix(f"{REL_AUDIT}/"):
    fail("RUN-201 finding coordination-handoff path mismatch")
if evidence["reporting_receipt"] != OUTPUT_REL.removeprefix(f"{REL_AUDIT}/"):
    fail("RUN-201 finding reporting-receipt path mismatch")
if not evidence["delegated_not_reexecuted_by_run_201"]:
    fail("RUN-201 finding must state delegated execution was not re-run")
if evidence["test_commands_executed"] is not None or evidence["test_command_text"] is not None:
    fail("RUN-201 must not claim test-command authorship")
if issue["completion_credit"]:
    fail("RUN-201 finding must retain zero completion credit")
if any(issue["credit"].values()):
    fail("RUN-201 finding credit map must remain entirely false")
if issue["route_url"]["ownership_status"] != "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW":
    fail("RUN-201 finding route ownership status mismatch")
if set(issue["backend_anchor"]["claim_anchors"]) != set(EXACT_APPLICATION_TEST_BLOBS):
    fail("RUN-201 finding backend anchors do not match the four-path boundary")
if "one canonical current Shift snapshot" not in issue["acceptance_criteria"]["given_when_then"]:
    fail("RUN-201 acceptance criteria omit the one-canonical-current-Shift-snapshot boundary")
if "canonical Shift Site" not in issue["acceptance_criteria"]["given_when_then"]:
    fail("RUN-201 acceptance criteria omit the canonical Shift Site boundary")
if "remote" not in issue["acceptance_criteria"]["given_when_then"].lower():
    fail("RUN-201 acceptance criteria omit the remote-Site privacy boundary")

reconciliation = findings["reconciliation"]
if reconciliation["retained_record_count"] != 19:
    fail("RUN-201 reconciliation retained-record count mismatch")
if reconciliation["current_provisional_count"] != 8:
    fail("RUN-201 reconciliation provisional count mismatch")
if reconciliation["historical_already_fixed_count"] != 2:
    fail("RUN-201 reconciliation historical-fixed count mismatch")
if reconciliation["historical_remediated_count"] != 9:
    fail("RUN-201 reconciliation remediated count mismatch")
if reconciliation["records_without_primary_or_candidate_feature_id"] != [
    "MON-METRIC-REPLAY-DEDUPE-01",
    "SUMMARY-TIMELINE-SITE-PRIVACY-01",
    "SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01",
    "ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01",
]:
    fail("RUN-201 feature-unassigned reconciliation mismatch")
if reconciliation["final_ids_cross_file_reconciled"]:
    fail("RUN-201 must not promote final-finding reconciliation")

required_human_markers = {
    "00-executive-summary.md": [
        "## RUN-200–201 Shift eligibility-alert recipient Site-privacy checkpoint",
        "ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01",
        "19 = 8 provisional + 2 historical already fixed + 9 historical remediated",
        "198 tests / 2,716 assertions",
        "RUN-202",
    ],
    "01-repository-module-map.md": [
        "## RUN-201 Shift eligibility-alert Site-privacy record and module boundary",
        "ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01",
        "19 = 8 + 2 + 9",
        "198/2,716",
        "RUN-202",
    ],
    "07-module-findings.md": [
        "## RUN-201 retained Shift eligibility-alert recipient Site-privacy record",
        "ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01",
        "19 = 8 + 2 + 9",
        "198/2,716",
        "RUN-202",
    ],
    "11-prioritised-roadmap.md": [
        "## RUN-201 Shift eligibility-alert recipient Site-privacy reporting priority",
        "ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01",
        "19 = 8 + 2 + 9",
        "198/2,716",
        "RUN-202",
    ],
    "12-native-build-and-do-not-copy-register.md": [
        "## RUN-201 native Shift eligibility-alert Site-privacy boundary",
        "ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01",
        "13/25",
        "RUN-202",
    ],
    "13-unresolved-questions-and-evidence-gaps.md": [
        "## RUN-201 Shift eligibility-alert recipient Site-privacy evidence gaps",
        "ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01",
        "198/2,716",
        "RUN-202",
    ],
}
for relative_path, markers in required_human_markers.items():
    text = (AUDIT_DIR / relative_path).read_text(encoding="utf-8")
    assert_contains(text, markers, relative_path)

builder_text = (REPO_ROOT / BUILDER_REL).read_text(encoding="utf-8")
try:
    compile(builder_text, BUILDER_REL, "exec", dont_inherit=True, optimize=0)
except SyntaxError as exc:
    fail(f"RUN-201 builder does not compile: {exc}")
assert_contains(
    builder_text,
    [
        "run_201_coordination_handoff",
        "run_201_reporting",
        "run_201_template_rewrites = [",
        "Fresh RUN-202 audit-dashboard verification required",
        "run_200_dashboard_payload",
        ".tmp-run202-dashboard",
        "ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01",
        "EIGHT_PROVISIONAL_TWO_HISTORICAL_ALREADY_FIXED_NINE_HISTORICAL_REMEDIATED_ZERO_FINAL_FINDING_CREDIT",
        "== 19",
        "== 198",
        "== 2716",
        HANDOFF_REL.removeprefix(f"{REL_AUDIT}/"),
        MATERIALIZER_REL.removeprefix(f"{REL_AUDIT}/"),
        OUTPUT_REL.removeprefix(f"{REL_AUDIT}/"),
        "generators/materialize-run-202-audit-dashboard-verification-wave-41.py",
        "evidence/browser/current-audit-dashboard-verification-run-202-wave-41.json",
    ],
    "RUN-201 builder",
)

completion_gates = [
    {
        "name": row["name"],
        "complete": False,
        "reason": "RUN201 reports one bounded historical remediation record only",
    }
    for row in run_200_gates
]
completion_boundary = {row["name"]: False for row in completion_gates}
if len(completion_boundary) != 26:
    fail("RUN-201 completion-gate names are not exactly 26 unique values")

source_files = {
    path: file_metadata(f"{REL_AUDIT}/{path}") for path in REPORTING_SOURCE_RELATIVE_PATHS
}
receipt: dict[str, Any] = {
    "schema_version": "run-201-elig-shift-notification-site-privacy-remediation-reporting-wave-41-v1",
    "run_id": "RUN-201-ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01-REMEDIATION-REPORTING-WAVE-41",
    "status": "ELIG_SHIFT_NOTIFICATION_SITE_PRIVACY_HISTORICAL_REMEDIATION_REPORTING_MATERIALIZED_DASHBOARD_RUN202_REQUIRED_ZERO_STATIC_PUBLICATION_FINAL_FINDING_OR_COMPLETION_CREDIT",
    "evidence_date": "2026-09-01",
    "scope": {
        "finding_id": "ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01",
        "type": "AUDIT_REPORTING_ONLY",
        "architecture": "SINGLE_ORGANISATION_MULTI_SITE",
        "application_or_test_source_mutated_by_run_201": False,
        "runtime_database_browser_or_build_executed_by_run_201": False,
        "dashboard_html_mutated_by_run_201": False,
        "delegated_runtime_or_review_authorship_claimed_by_run_201": False,
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
        "run_200_dashboard": {
            "path": "audit-dashboard.html",
            "sha256": RUN_200_DASHBOARD_SHA256,
            "bytes": RUN_200_DASHBOARD_BYTES,
            "lines": RUN_200_DASHBOARD_LINES,
        },
        "run_200_materializer": file_metadata(RUN_200_MATERIALIZER_REL),
        "run_200_receipt": file_metadata(RUN_200_RECEIPT_REL),
        "run_200_receipt_self_seal_sha256": RUN_200_RECEIPT_SELF_SEAL,
        "coordination_handoff": {
            **file_metadata(HANDOFF_REL),
            "receipt_self_seal_sha256": HANDOFF_SELF_SEAL,
        },
        "pre_run_201_findings_sha256": PRE_RUN_201_FINDINGS_SHA256,
        "reporting_sources": source_files,
    },
    "reporting_transition": {
        "finding_id": "ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01",
        "counts_before": {
            "retained_claim_records": 18,
            "provisional_source_claims": 8,
            "historical_already_fixed": 2,
            "historical_remediated": 8,
            "final_P0": 0,
            "final_P1": 0,
        },
        "counts_after": {
            "retained_claim_records": 19,
            "provisional_source_claims": 8,
            "historical_already_fixed": 2,
            "historical_remediated": 9,
            "final_P0": 0,
            "final_P1": 0,
        },
        "feature_id": None,
        "candidate_feature_id": None,
        "feature_identity_status": "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW",
        "static_ownership_or_queue_advance": False,
    },
    "bounded_execution_accounting": {
        "prior_unique_total": {"tests": 185, "assertions": 2691},
        "credited_increment": {"tests": 13, "assertions": 25},
        "unique_total": {"tests": 198, "assertions": 2716},
        "post_merge_execution_counted_once": True,
        "delegated_coordination_evidence_not_reexecuted_by_run_201": True,
        "excluded": {
            "red_reproduction": {
                "failed": 1,
                "passed": 0,
                "pending": 4,
                "assertions": 1,
            },
            "intermediate_no_go": {
                "tests": 12,
                "assertions": 23,
                "reason": "stale Shift snapshot",
            },
            "isolated_final_replay": {"tests": 13, "assertions": 25},
            "duplicate_post_merge_count": {"tests": 13, "assertions": 25},
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
        "required_run": "RUN-202",
        "dashboard_html_changed_by_run_201": False,
        "preserved_run_200_dashboard_sha256": RUN_200_DASHBOARD_SHA256,
        "generator": "generators/materialize-run-202-audit-dashboard-verification-wave-41.py",
        "receipt": "evidence/browser/current-audit-dashboard-verification-run-202-wave-41.json",
        "fresh_four_viewport_navigation_resource_console_verification_required": True,
        "forward_paths_intentionally_unhashed": True,
    },
    "evidence_quality_boundary": {
        "coordination_handoff_transcription_only": True,
        "original_issue_specific_runtime_receipt_present": False,
        "original_issue_specific_independent_review_receipt_present": False,
        "run_201_reexecuted_application_tests": False,
        "run_201_claims_original_runtime_or_review_authorship": False,
        "git_lineage_and_exact_blobs_reverified": True,
        "delegated_evidence_is_bounded_not_promoted_to_browser_release_or_completion": True,
        "intermediate_12_23_is_no_go_zero_credit": True,
        "canonical_current_shift_snapshot_required_for_recipient_and_payload": True,
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
        "materializer_writes_only": [OUTPUT_REL.removeprefix(f"{REL_AUDIT}/")],
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
        "application_source_or_test_change_by_run_201": False,
        "application_runtime_reexecution_by_run_201": False,
        "application_browser": False,
        "responsive_application_or_visual_credit": False,
        "audit_dashboard_build_execution_by_run_201": False,
        "static_route_or_page_feature_ownership": False,
        "static_controller_action_bridge": False,
        "queue_advance": False,
        "shift_signal_emission_or_idempotency_correctness": False,
        "eligibility_rule_or_blocking_reason_correctness": False,
        "user_site_access_service_correctness": False,
        "provider_manager_or_admin_role_model_correctness": False,
        "notification_transport_or_external_delivery_exactly_once": False,
        "transactional_outbox_or_retry_recovery": False,
        "broader_roster_shift_or_hr_privacy": False,
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
        "local_main_ahead": 92,
        "local_main_behind": 0,
        "push_performed": False,
        "publication_claimed": False,
    },
}

receipt_without_seal = dict(receipt)
receipt["receipt_self_seal_sha256"] = sha256_bytes(canonical_json_bytes(receipt_without_seal))
output_bytes = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
output_path = REPO_ROOT / OUTPUT_REL
temporary_path = output_path.with_name(f".{output_path.name}.tmp-run201-reporting")

if output_path.exists():
    if output_path.read_bytes() != output_bytes:
        fail("Existing RUN-201 receipt is not the deterministic expected payload")
else:
    if temporary_path.exists():
        fail(f"Refusing to overwrite stale RUN-201 temp file: {temporary_path}")
    try:
        with temporary_path.open("xb") as handle:
            handle.write(output_bytes)
            handle.flush()
            os.fsync(handle.fileno())
        if temporary_path.read_bytes() != output_bytes:
            fail("RUN-201 temporary receipt bytes mismatch")
        os.replace(temporary_path, output_path)
    finally:
        if temporary_path.exists():
            temporary_path.unlink()

if output_path.read_bytes() != output_bytes:
    fail("RUN-201 post-write receipt bytes mismatch")
written = read_json_strict(OUTPUT_REL)
validate_self_seal(
    written,
    receipt["receipt_self_seal_sha256"],
    "RUN-201 remediation-reporting receipt",
)
if parse_status() != expected_after:
    fail("RUN-201 post-write dirty boundary mismatch")
if sha256_bytes((REPO_ROOT / DASHBOARD_REL).read_bytes()) != RUN_200_DASHBOARD_SHA256:
    fail("RUN-201 changed the frozen RUN-200 dashboard")

print(
    json.dumps(
        {
            "run_id": receipt["run_id"],
            "output": OUTPUT_REL,
            "sha256": sha256_bytes(output_bytes),
            "receipt_self_seal_sha256": receipt["receipt_self_seal_sha256"],
            "dashboard_preserved_sha256": RUN_200_DASHBOARD_SHA256,
            "dashboard_forward_gate": "RUN-202",
        },
        sort_keys=True,
    )
)
