#!/usr/bin/env python3
"""Materialize the bounded RUN-199 Shift-task recipient reporting receipt."""

from __future__ import annotations

import hashlib
import json
import os
import subprocess
from pathlib import Path
from typing import Any


if not __debug__:
    raise RuntimeError("RUN-199 refuses optimized Python because validation must remain active")


ROOT = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
REL_AUDIT = AUDIT_DIR.relative_to(ROOT).as_posix()
OUTPUT_REL = (
    f"{REL_AUDIT}/evidence/source/"
    "current-run-199-shift-task-due-recipient-revalidation-remediation-reporting-wave-40.json"
)
OUTPUT_PATH = ROOT / OUTPUT_REL
MATERIALIZER_REL = (
    f"{REL_AUDIT}/generators/"
    "materialize-run-199-shift-task-due-recipient-revalidation-remediation-reporting-wave-40.py"
)
MATERIALIZER_PATH = ROOT / MATERIALIZER_REL
BUILDER_REL = f"{REL_AUDIT}/generators/build-current-audit-dashboard.py"
DASHBOARD_REL = f"{REL_AUDIT}/audit-dashboard.html"
FINDINGS_REL = f"{REL_AUDIT}/findings.json"
HANDOFF_REL = (
    f"{REL_AUDIT}/evidence/source/"
    "current-run-199-shift-task-due-recipient-revalidation-coordination-handoff-wave-40.json"
)
RUN_198_MATERIALIZER_REL = (
    f"{REL_AUDIT}/generators/materialize-run-198-audit-dashboard-verification-wave-39.py"
)
RUN_198_RECEIPT_REL = (
    f"{REL_AUDIT}/evidence/browser/current-audit-dashboard-verification-run-198-wave-39.json"
)

REPORTING_INPUT_COMMIT = "e2593cbdd0791aca2ea7b1e9b254d07bf7f8e84f"
REPORTING_INPUT_TREE = "071edd9408f27206bc6962157e4a84c30590f701"
REPORTING_INPUT_PARENTS = [
    "ca3425103d6d75dc728418464d03e7e72983925b",
    "6186176d30a9b4061f859eef8d069e8739ef3d88",
]
REPORTING_INPUT_SUBJECT = "merge: revalidate shift task recipients"
ADVANCED_MAIN_COMMIT = "ca3425103d6d75dc728418464d03e7e72983925b"
ADVANCED_MAIN_TREE = "662962768c24c0c0eb2231dcd42caf49cfe9c910"
APPLICATION_BASELINE_COMMIT = "47a6d231c52a78c9f0f606e41d4492d754771027"
APPLICATION_BASELINE_TREE = "c1e262a50c67797b819d3f1085ece2782b41237e"
SEALED_FIX_COMMIT = "6186176d30a9b4061f859eef8d069e8739ef3d88"
SEALED_FIX_TREE = "a089d5212e9674bb0e7915c96806867e52d1015f"
STABLE_PATCH_ID = "af8be2614ff89b34632299424cdd28e011ee1d84"
ORIGIN_MAIN_OBSERVED = "c39b076547056b1e158c604957a04bd8b75b0f29"
RUN_198_DASHBOARD_SHA256 = "4432da4fecc7c9afa0096b46c3568249fccdaa8f0b987bfef4bc1eb07e24bd3a"
RUN_198_RECEIPT_SHA256 = "7585c000789063b598aa67e944584592a6e36f259484745817c7ece8c0739d52"
RUN_198_RECEIPT_SELF_SEAL = "215f6fa5e14afd42f2263df2327f8d79c146005292c68471e10f5b0f06aa26f0"
RUN_198_MATERIALIZER_SHA256 = "2298b162517329c736b66d01c6f2326ba6a71092c0fec1126731b42e9fb4a66c"
HANDOFF_SHA256 = "344875fbcbcd92b9d739071065aa130ba78be3003d69e17fba0e3c486005c3a8"
HANDOFF_SELF_SEAL = "d693fc1367ec2b44304354b2d2b709b5ea7ee8840fc5f0d0d711936526b9e47e"
HANDOFF_BYTES = 7457
HANDOFF_LINES = 157
PRE_RUN_199_FINDINGS_SHA256 = "2ef3e5a31844b09006d2727cc2bd8709b69679c96ad5a1d1cc3284ade7109065"

FINDING_ID = "SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01"
EXACT_APPLICATION_TEST_BLOBS = {
    "app/Jobs/ShiftTaskDueJob.php": "959da2db7bed1f295de19123a87f082dfee91b6c",
    "app/Notifications/ShiftTaskDueNotification.php": "bd2f10f497afbe0fcdfdcfb9142f78167f07340d",
    "tests/Feature/ShiftTaskDueJobTest.php": "f50251d48acd0393d382be8a01f1fa11adc1e444",
    "tests/Feature/PushNotificationsWebPushTest.php": "0e7d650735e7208d39ed6e015c52f649e6f2f71e",
}

HUMAN_DOCS = [
    "00-executive-summary.md",
    "01-repository-module-map.md",
    "07-module-findings.md",
    "11-prioritised-roadmap.md",
    "12-native-build-and-do-not-copy-register.md",
    "13-unresolved-questions-and-evidence-gaps.md",
]
OWNED_RELATIVE_PATHS = [
    *(f"{REL_AUDIT}/{name}" for name in HUMAN_DOCS),
    FINDINGS_REL,
    BUILDER_REL,
    HANDOFF_REL,
    MATERIALIZER_REL,
    OUTPUT_REL,
]
EXPECTED_DIRTY_STATES = {
    **{f"{REL_AUDIT}/{name}": " M" for name in HUMAN_DOCS},
    FINDINGS_REL: " M",
    BUILDER_REL: " M",
    HANDOFF_REL: "??",
    MATERIALIZER_REL: "??",
}


def fail(message: str) -> None:
    raise RuntimeError(message)


def git_text(*args: str) -> str:
    completed = subprocess.run(
        ["git", *args],
        cwd=ROOT,
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        encoding="utf-8",
    )
    return completed.stdout.rstrip()


def git_bytes(*args: str) -> bytes:
    completed = subprocess.run(
        ["git", *args],
        cwd=ROOT,
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    return completed.stdout


def stable_patch_id(commit: str) -> str:
    patch = subprocess.run(
        ["git", "show", "--pretty=format:", "--binary", commit],
        cwd=ROOT,
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    identified = subprocess.run(
        ["git", "patch-id", "--stable"],
        cwd=ROOT,
        check=True,
        input=patch.stdout,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    fields = identified.stdout.decode("ascii").split()
    if len(fields) < 2:
        fail("Unable to derive stable patch ID for the sealed fix")
    return fields[0]


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def sha256_file(relative: str) -> str:
    return sha256_bytes((ROOT / relative).read_bytes())


def reject_constant(value: str) -> None:
    fail(f"Non-finite JSON constant rejected: {value}")


def reject_duplicate_pairs(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            fail(f"Duplicate JSON key rejected: {key}")
        result[key] = value
    return result


def decode_json_strict(payload: bytes, label: str) -> dict[str, Any]:
    try:
        value = json.loads(
            payload.decode("utf-8"),
            object_pairs_hook=reject_duplicate_pairs,
            parse_constant=reject_constant,
        )
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        fail(f"Strict JSON parse failed for {label}: {error}")
    if not isinstance(value, dict):
        fail(f"Expected a JSON object for {label}")
    return value


def read_json_strict(relative: str) -> dict[str, Any]:
    return decode_json_strict((ROOT / relative).read_bytes(), relative)


def canonical_sha256(payload: dict[str, Any]) -> str:
    return sha256_bytes(
        json.dumps(
            payload,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        ).encode("utf-8")
    )


def validate_self_seal(payload: dict[str, Any], expected: str, label: str) -> None:
    unsealed = dict(payload)
    observed = unsealed.pop("receipt_self_seal_sha256", None)
    if observed != expected or canonical_sha256(unsealed) != expected:
        fail(f"{label} self-seal mismatch")


def parse_status() -> dict[str, str]:
    rows: dict[str, str] = {}
    for line in git_text("status", "--porcelain=v1", "--untracked-files=all").splitlines():
        if not line:
            continue
        state = line[:2]
        path = line[3:].replace("\\", "/")
        if " -> " in path:
            fail(f"Unexpected rename in RUN-199 boundary: {line}")
        if path in rows:
            fail(f"Duplicate worktree-status path in RUN-199 boundary: {path}")
        rows[path] = state
    return rows


def parse_name_status(older: str, newer: str) -> dict[str, str]:
    rows: dict[str, str] = {}
    for line in git_text("diff", "--name-status", older, newer).splitlines():
        fields = line.split("\t")
        if len(fields) != 2 or fields[0] not in {"A", "M", "D"}:
            fail(f"Unexpected lineage diff row: {line}")
        state, path = fields
        if path in rows:
            fail(f"Duplicate lineage path: {path}")
        rows[path] = state
    return rows


def file_metadata(relative: str) -> dict[str, Any]:
    payload = (ROOT / relative).read_bytes()
    return {
        "path": relative.removeprefix(f"{REL_AUDIT}/"),
        "sha256": sha256_bytes(payload),
        "bytes": len(payload),
        "lines": len(payload.splitlines()),
    }


if git_text("rev-parse", "HEAD") != REPORTING_INPUT_COMMIT:
    fail("RUN-199 must execute at the exact Shift-task remediation merge")
if git_text("show", "-s", "--format=%T", "HEAD") != REPORTING_INPUT_TREE:
    fail("RUN-199 reporting input tree mismatch")
if git_text("show", "-s", "--format=%P", "HEAD").split() != REPORTING_INPUT_PARENTS:
    fail("RUN-199 merge-parent lineage mismatch")
if git_text("show", "-s", "--format=%s", "HEAD") != REPORTING_INPUT_SUBJECT:
    fail("RUN-199 merge subject mismatch")
if git_text("rev-parse", "origin/main") != ORIGIN_MAIN_OBSERVED:
    fail("RUN-199 observed origin/main moved")
if git_text("show", "-s", "--format=%T", ADVANCED_MAIN_COMMIT) != ADVANCED_MAIN_TREE:
    fail("RUN-198 advanced-main tree mismatch")
if git_text("show", "-s", "--format=%T", APPLICATION_BASELINE_COMMIT) != APPLICATION_BASELINE_TREE:
    fail("Shift-task baseline tree mismatch")
if git_text("show", "-s", "--format=%T", SEALED_FIX_COMMIT) != SEALED_FIX_TREE:
    fail("Shift-task sealed-fix tree mismatch")
if git_text("show", "-s", "--format=%P", SEALED_FIX_COMMIT).split() != [APPLICATION_BASELINE_COMMIT]:
    fail("Shift-task sealed-fix parent mismatch")
if stable_patch_id(SEALED_FIX_COMMIT) != STABLE_PATCH_ID:
    fail("Shift-task stable patch ID mismatch")
if git_text("diff", "--check"):
    fail("git diff --check reported an error")

expected_lineage_delta = {path: "M" for path in EXACT_APPLICATION_TEST_BLOBS}
if parse_name_status(ADVANCED_MAIN_COMMIT, REPORTING_INPUT_COMMIT) != expected_lineage_delta:
    fail("Shift-task first-parent merge delta is not the exact four-path boundary")
if parse_name_status(APPLICATION_BASELINE_COMMIT, SEALED_FIX_COMMIT) != expected_lineage_delta:
    fail("Shift-task sealed-fix delta is not the exact four-path boundary")
for path, expected_blob in EXACT_APPLICATION_TEST_BLOBS.items():
    if git_text("rev-parse", f"{REPORTING_INPUT_COMMIT}:{path}") != expected_blob:
        fail(f"Shift-task merge blob mismatch: {path}")
    if git_text("rev-parse", f"{SEALED_FIX_COMMIT}:{path}") != expected_blob:
        fail(f"Shift-task sealed-fix blob mismatch: {path}")

dirty = parse_status()
expected_before = dict(EXPECTED_DIRTY_STATES)
expected_after = {**EXPECTED_DIRTY_STATES, OUTPUT_REL: "??"}
if dirty not in (expected_before, expected_after):
    fail(f"RUN-199 dirty boundary mismatch: {dirty}")
if DASHBOARD_REL in dirty:
    fail("RUN-199 must preserve audit-dashboard.html byte-for-byte")

expected_source_hashes = {
    RUN_198_MATERIALIZER_REL: RUN_198_MATERIALIZER_SHA256,
    RUN_198_RECEIPT_REL: RUN_198_RECEIPT_SHA256,
    HANDOFF_REL: HANDOFF_SHA256,
}
for relative, expected in expected_source_hashes.items():
    if sha256_file(relative) != expected:
        fail(f"Pinned RUN-199 input hash mismatch: {relative}")
handoff_bytes = (ROOT / HANDOFF_REL).read_bytes()
if len(handoff_bytes) != HANDOFF_BYTES or len(handoff_bytes.splitlines()) != HANDOFF_LINES:
    fail("RUN-199 coordination-handoff size/line pin mismatch")

run_198 = read_json_strict(RUN_198_RECEIPT_REL)
handoff = read_json_strict(HANDOFF_REL)
validate_self_seal(run_198, RUN_198_RECEIPT_SELF_SEAL, "RUN-198 receipt")
validate_self_seal(handoff, HANDOFF_SELF_SEAL, "RUN-199 coordination handoff")

if run_198["schema_version"] != "run-198-audit-dashboard-verification-wave-39-v1":
    fail("RUN-198 receipt schema mismatch")
if run_198["pins"]["final_run_198_dashboard"]["sha256"] != RUN_198_DASHBOARD_SHA256:
    fail("RUN-198 dashboard pin mismatch")
if run_198["pins"]["final_run_198_dashboard"]["bytes"] != 339486:
    fail("RUN-198 dashboard byte-size pin mismatch")
if run_198["pins"]["final_run_198_dashboard"]["lines"] != 78:
    fail("RUN-198 dashboard line-count pin mismatch")
if run_198["reported_snapshot"]["finding_lineage"] != {
    "records": 17,
    "provisional": 8,
    "historical_already_fixed": 2,
    "historical_remediated": 7,
    "bounded_tests": 176,
    "bounded_assertions": 2641,
    "final_P0": 0,
    "final_P1": 0,
}:
    fail("RUN-198 reported finding lineage mismatch")
viewports = run_198["current_browser_verification"]["viewports"]
if set(viewports) != {"1440x900", "1280x800", "1024x768", "390x844"}:
    fail("RUN-198 viewport set mismatch")
if not all(
    row["visible_text_passed"] == row["visible_text_total"] == 48
    and row["page_horizontal_overflow"] is False
    and row["table_containment_failures"] == 0
    for row in viewports.values()
):
    fail("RUN-198 viewport proof mismatch")
navigation = run_198["current_browser_verification"]["navigation"]
if len(navigation) != 10 or not all(
    row["target_exists"] and row["target_visible"] and row["observed_hash"] == row["expected_hash"]
    for row in navigation
):
    fail("RUN-198 navigation proof mismatch")
if run_198["html_graph"]["unique_local_resources"] != 499:
    fail("RUN-198 local-resource denominator mismatch")
if run_198["html_graph"]["existing_unique_local_resources"] != 499:
    fail("RUN-198 local-resource verification mismatch")
if run_198["html_graph"]["anchor_element_count"] != 969:
    fail("RUN-198 anchor denominator mismatch")
if run_198["html_graph"]["authored_id_count"] != 10:
    fail("RUN-198 authored-ID denominator mismatch")
if run_198["html_graph"]["duplicate_id_count"] != 0:
    fail("RUN-198 duplicate authored IDs were not zero")
if run_198["http_head_verification"] != {
    "expected_unique_resources": 499,
    "verified_count": 499,
    "failure_count": 0,
    "complete": True,
}:
    fail("RUN-198 HTTP resource proof mismatch")
if run_198["current_browser_verification"]["console"]["warning_or_error_logs"] != []:
    fail("RUN-198 browser console was not clean")
if run_198["current_browser_verification"]["console"]["messages"] != []:
    fail("RUN-198 browser console messages were not empty")
if run_198["current_browser_verification"]["console"]["page_errors"] != []:
    fail("RUN-198 browser page errors were not empty")
browser_artifact = run_198["current_browser_verification"]["artifact"]
if browser_artifact["http_status"] != 200:
    fail("RUN-198 dashboard browser fetch was not HTTP 200")
if browser_artifact["response_content_length"] != 339486:
    fail("RUN-198 dashboard browser response-size mismatch")
if browser_artifact["browser_fetched_sha256"] != RUN_198_DASHBOARD_SHA256:
    fail("RUN-198 browser-fetched dashboard hash mismatch")
visual_checks = run_198["current_browser_verification"]["visual_checks"]
if visual_checks["desktop_result"] != "GO_NO_CLIPPING_OR_OVERLAP":
    fail("RUN-198 desktop visual check mismatch")
if visual_checks["mobile_result"] != "GO_NO_CLIPPING_OR_OVERLAP":
    fail("RUN-198 mobile visual check mismatch")
if not visual_checks["mobile_navigation_horizontally_scrollable_at_390x844"]:
    fail("RUN-198 mobile navigation scrollability proof is missing")
deliverable = run_198["current_browser_verification"]["deliverable"]
if not deliverable["dashboard_tab_marked_deliverable"]:
    fail("RUN-198 dashboard tab was not marked deliverable")
if not deliverable["current_exact_dashboard_tab_retained"]:
    fail("RUN-198 exact dashboard tab was not retained")
if not deliverable["browser_viewport_override_reset"]:
    fail("RUN-198 browser viewport override was not reset")
if not run_198["server_cleanup"]["complete"]:
    fail("RUN-198 temporary browser server cleanup was incomplete")
if run_198["server_cleanup"]["listeners_after_cleanup"] != 0:
    fail("RUN-198 temporary browser server listener remained")
if run_198["server_cleanup"]["exact_server_pid_present_after_cleanup"]:
    fail("RUN-198 temporary browser server PID remained")
if run_198["server_cleanup"]["matching_loopback_processes_after_cleanup"] != 0:
    fail("RUN-198 matching loopback server process remained")
if not run_198["finalization_state"]["browser_complete"]:
    fail("RUN-198 browser proof was not finalized")
if not run_198["finalization_state"]["resource_complete"]:
    fail("RUN-198 resource proof was not finalized")
if not run_198["artifact_completion_test_met"] or run_198["audit_completion_test_met"]:
    fail("RUN-198 artifact/audit completion boundary mismatch")

if handoff["schema_version"] != "oblivion_shift_task_due_recipient_revalidation_coordination_handoff_v1":
    fail("Shift-task coordination-handoff schema mismatch")
if handoff["evidence_kind"] != "COORDINATION_HANDOFF_TRANSCRIPTION_NOT_ORIGINAL_RUNTIME_RECEIPT":
    fail("Shift-task coordination-handoff evidence kind mismatch")
if handoff["status"] != "SEALED_DELEGATED_EVIDENCE_FOR_BOUNDED_REPORTING_ONLY":
    fail("Shift-task coordination-handoff status mismatch")
if handoff["source"] != {
    "source_thread_id": "01a04fe4-0a67-7912-bae7-e133b73475fd",
    "source_task_title": "Continue OSS audit fixes",
    "transcription_basis": "User-visible codex_delegation coordination handoffs plus Git-verifiable lineage and one independent read-only source/reporting review in the audit task.",
    "original_issue_specific_runtime_receipt_present": False,
    "original_issue_specific_independent_review_receipt_present": False,
    "run_199_reexecuted_application_tests": False,
    "run_199_claims_original_runtime_authorship": False,
}:
    fail("Shift-task coordination-handoff source boundary mismatch")
if handoff["finding"] != {
    "id": FINDING_ID,
    "record_status": "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING",
    "feature_id": None,
    "candidate_feature_id": None,
    "related_feature_ids": [],
    "feature_identity_status": "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW",
    "priority": "P1",
    "priority_boundary": "HISTORICAL_REMEDIATED_P1_NOT_CURRENT_PROVISIONAL_OR_FINAL_PRIORITY_COUNT",
}:
    fail("Shift-task coordination-handoff finding identity mismatch")
expected_handoff_pins = {
    "application_baseline_commit": APPLICATION_BASELINE_COMMIT,
    "application_baseline_tree": APPLICATION_BASELINE_TREE,
    "advanced_main_commit": ADVANCED_MAIN_COMMIT,
    "advanced_main_tree": ADVANCED_MAIN_TREE,
    "sealed_fix_commit": SEALED_FIX_COMMIT,
    "sealed_fix_tree": SEALED_FIX_TREE,
    "stable_patch_id": STABLE_PATCH_ID,
    "local_main_merge_commit": REPORTING_INPUT_COMMIT,
    "local_main_merge_tree": REPORTING_INPUT_TREE,
    "local_main_merge_parents": REPORTING_INPUT_PARENTS,
    "origin_main_observed": ORIGIN_MAIN_OBSERVED,
}
if handoff["pins"] != expected_handoff_pins:
    fail("Shift-task coordination-handoff lineage mismatch")
handoff_blobs = {
    row["path"]: row["merge_blob"] for row in handoff["exact_application_test_paths"]
}
if len(handoff["exact_application_test_paths"]) != 4 or handoff_blobs != EXACT_APPLICATION_TEST_BLOBS:
    fail("Shift-task coordination-handoff path/blob boundary mismatch")
if handoff["remediated_contract"]["ineligible_boundary"] != (
    "At scheduler time, ineligible recipients leave reminder_sent_at null and receive neither "
    "notification nor Facility signal."
):
    fail("Shift-task scheduler-time denial boundary mismatch")
if handoff["remediated_contract"]["queued_delivery_boundary"] != (
    "Reload and revalidate current task, Shift, recipient, assignment, completion, due state and "
    "approved Site access before channel selection and before each channel exposes content. "
    "Queue-time denial suppresses delivery only; it does not clear an already-claimed marker or "
    "retract an already-emitted Facility signal."
):
    fail("Shift-task queue-time denial boundary mismatch")
if {
    key: handoff["reproduction"][key]
    for key in ("failed", "passed", "pending", "assertions", "exit_code")
} != {"failed": 1, "passed": 3, "pending": 1, "assertions": 14, "exit_code": 1}:
    fail("Shift-task red reproduction accounting mismatch")
if handoff["verification"]["intermediate_tests"] != 5:
    fail("Shift-task intermediate test accounting mismatch")
if handoff["verification"]["intermediate_assertions"] != 30:
    fail("Shift-task intermediate assertion accounting mismatch")
if handoff["verification"]["isolated_final"]["tests"] != 9:
    fail("Shift-task isolated-final test accounting mismatch")
if handoff["verification"]["isolated_final"]["assertions"] != 50:
    fail("Shift-task isolated-final assertion accounting mismatch")
if handoff["verification"]["post_merge"]["tests"] != 9:
    fail("Shift-task post-merge test accounting mismatch")
if handoff["verification"]["post_merge"]["assertions"] != 50:
    fail("Shift-task post-merge assertion accounting mismatch")
if not handoff["verification"]["first_parent_delta_exact"]:
    fail("Shift-task handoff did not attest an exact first-parent delta")
if not handoff["verification"]["merge_blobs_byte_identical_to_sealed_fix"]:
    fail("Shift-task handoff did not attest merge-blob identity")
if handoff["independent_review"]["final_status"] != "GO":
    fail("Shift-task independent review did not return GO")
if handoff["independent_review"]["remaining_actionable_findings"] != 0:
    fail("Shift-task independent review retained actionable findings")
if handoff["cleanup"]["global_php_pest_process_count"] != 0:
    fail("Shift-task cleanup retained PHP/Pest processes")
if handoff["cleanup"]["numeric_pid_suffixed_schema_count"] != 0:
    fail("Shift-task cleanup retained numeric PID schemas")
if handoff["cleanup"]["push_performed"]:
    fail("Shift-task handoff unexpectedly reports a push")
if any(handoff["noninheritance"].values()):
    fail("Shift-task handoff unexpectedly grants noninherited credit")
if handoff["bounded_accounting"] != {
    "previous_unique_tests": 176,
    "previous_unique_assertions": 2641,
    "credited_increment_tests": 9,
    "credited_increment_assertions": 50,
    "current_unique_tests": 185,
    "current_unique_assertions": 2691,
    "exclusions": [
        "Red 1 failed plus 3 passed plus 1 pending and 14 assertions.",
        "Intermediate 5 tests and 30 assertions plus cache proofs.",
        "Isolated final 9 tests and 50 assertions as a duplicate replay.",
        "Any second count of the post-merge 9 tests and 50 assertions.",
    ],
}:
    fail("Shift-task coordination-handoff bounded accounting mismatch")
if handoff["completion_credit"]:
    fail("Shift-task coordination handoff must retain zero completion credit")

committed_findings_bytes = git_bytes("show", f"{REPORTING_INPUT_COMMIT}:{FINDINGS_REL}")
if sha256_bytes(committed_findings_bytes) != PRE_RUN_199_FINDINGS_SHA256:
    fail("Pre-RUN-199 committed findings pin mismatch")
committed_findings = decode_json_strict(committed_findings_bytes, "committed pre-RUN-199 findings")
findings = read_json_strict(FINDINGS_REL)
if len(committed_findings["records"]) != 17:
    fail("Pre-RUN-199 findings register did not contain exactly 17 records")
if findings["records"][:17] != committed_findings["records"]:
    fail("RUN-199 changed one or more of the first 17 retained finding records")
if findings["audit_status"] != "EIGHT_PROVISIONAL_TWO_HISTORICAL_ALREADY_FIXED_EIGHT_HISTORICAL_REMEDIATED_ZERO_FINAL_FINDING_CREDIT":
    fail("RUN-199 findings status mismatch")
expected_counts = {
    "retained_claim_records": 18,
    "provisional_source_claims": 8,
    "historical_already_fixed": 2,
    "historical_remediated": 8,
    "bounded_disposition_tests_passed": 185,
    "bounded_disposition_assertions": 2691,
    "final_P0": 0,
    "final_P1": 0,
}
if {key: findings["counts"][key] for key in expected_counts} != expected_counts:
    fail("RUN-199 findings count mismatch")
if findings["counts"]["shift_task_due_recipient_revalidation_focused_tests"] != 9:
    fail("RUN-199 Shift-task focused-test count mismatch")
if findings["counts"]["shift_task_due_recipient_revalidation_focused_assertions"] != 50:
    fail("RUN-199 Shift-task focused-assertion count mismatch")
if findings["counts"]["shift_task_due_recipient_revalidation_baseline_failed"] != 1:
    fail("RUN-199 Shift-task red-failure count mismatch")
if findings["counts"]["shift_task_due_recipient_revalidation_baseline_passed"] != 3:
    fail("RUN-199 Shift-task red-pass count mismatch")
if findings["counts"]["shift_task_due_recipient_revalidation_baseline_pending"] != 1:
    fail("RUN-199 Shift-task red-pending count mismatch")
if findings["counts"]["shift_task_due_recipient_revalidation_baseline_assertions"] != 14:
    fail("RUN-199 Shift-task red-assertion count mismatch")

record_ids = [row["id"] for row in findings["records"]]
if len(record_ids) != len(set(record_ids)) or len(record_ids) != 18:
    fail("RUN-199 retained finding identities are not exactly 18 unique values")
if record_ids[-1] != FINDING_ID:
    fail("RUN-199 did not append the Shift-task record as the eighteenth identity")
shift_task = findings["records"][-1]
if shift_task["record_status"] != "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING":
    fail("Shift-task record status mismatch")
if shift_task["feature_id"] is not None or shift_task["candidate_feature_id"] is not None:
    fail("Shift-task feature identity must remain unassigned")
if shift_task["related_feature_ids"] != []:
    fail("Shift-task related feature IDs must remain empty")
if shift_task["feature_identity_status"] != "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW":
    fail("Shift-task feature identity status mismatch")
adjudication = shift_task["current_adjudication"]
expected_adjudication_pins = {
    "application_baseline_commit": APPLICATION_BASELINE_COMMIT,
    "application_baseline_tree": APPLICATION_BASELINE_TREE,
    "audit_release_commit": ADVANCED_MAIN_COMMIT,
    "audit_release_tree": ADVANCED_MAIN_TREE,
    "fix_commit": SEALED_FIX_COMMIT,
    "fix_tree": SEALED_FIX_TREE,
    "stable_patch_id": STABLE_PATCH_ID,
    "application_commit": REPORTING_INPUT_COMMIT,
    "repository_tree": REPORTING_INPUT_TREE,
    "current_local_main_commit_at_run_199": REPORTING_INPUT_COMMIT,
    "current_local_main_tree_at_run_199": REPORTING_INPUT_TREE,
    "origin_main_observed": ORIGIN_MAIN_OBSERVED,
}
if {key: adjudication[key] for key in expected_adjudication_pins} != expected_adjudication_pins:
    fail("Shift-task findings lineage mismatch")
expected_receipt_reference = OUTPUT_REL.removeprefix(f"{REL_AUDIT}/")
if adjudication["run_199_reporting_receipt"] != expected_receipt_reference:
    fail("Shift-task adjudication points to the wrong RUN-199 receipt")
if shift_task["evidence"]["reporting_receipt"] != expected_receipt_reference:
    fail("Shift-task evidence points to the wrong RUN-199 receipt")
if not shift_task["evidence"]["delegated_not_reexecuted_by_run_199"]:
    fail("Shift-task delegated evidence boundary is missing")
if shift_task["evidence"]["tests_executed"] != 9 or shift_task["evidence"]["assertions"] != 50:
    fail("Shift-task bounded evidence count mismatch")
if shift_task["backend_anchor"]["claim_anchors"] != list(EXACT_APPLICATION_TEST_BLOBS):
    fail("Shift-task exact application/test anchors mismatch")
if any(shift_task["credit"].values()) or shift_task["completion_credit"]:
    fail("Shift-task downstream credit must remain false")
if findings["reconciliation"]["records_without_primary_or_candidate_feature_id"] != [
    "MON-METRIC-REPLAY-DEDUPE-01",
    "SUMMARY-TIMELINE-SITE-PRIVACY-01",
    FINDING_ID,
]:
    fail("Feature-unassigned record reconciliation mismatch")

required_phrases = {
    "00-executive-summary.md": [
        "RUN-198–199 Shift-task due recipient-revalidation checkpoint",
        "18 = 8 provisional + 2 historical already fixed + 8 historical remediated",
        "185 tests / 2,691 assertions",
        "RUN-200",
    ],
    "01-repository-module-map.md": [
        "RUN-199 Shift-task due recipient-revalidation record and module boundary",
        "18 = 8 + 2 + 8",
        "185/2,691",
    ],
    "07-module-findings.md": [
        "SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01 — feature unassigned — historical remediated",
        "RUN-199 retained Shift-task due recipient-revalidation record",
        "185/2,691",
    ],
    "11-prioritised-roadmap.md": [
        "RUN-199 Shift-task due recipient-revalidation reporting priority",
        "18 = 8 + 2 + 8",
        "185/2,691",
    ],
    "12-native-build-and-do-not-copy-register.md": [
        "RUN-199 native Shift-task due recipient-revalidation boundary",
        FINDING_ID,
        "RUN-200",
    ],
    "13-unresolved-questions-and-evidence-gaps.md": [
        "RUN-199 Shift-task due recipient-revalidation evidence gaps",
        "185/2,691",
        "RUN-200",
    ],
}
for name, phrases in required_phrases.items():
    text = (AUDIT_DIR / name).read_text(encoding="utf-8")
    missing = [phrase for phrase in phrases if phrase not in text]
    if missing:
        fail(f"Missing RUN-199 phrase(s) in {name}: {missing}")

builder_text = (ROOT / BUILDER_REL).read_text(encoding="utf-8")
compile(builder_text, BUILDER_REL, "exec")
for phrase in (
    "run_199_template_rewrites = [",
    FINDING_ID,
    HANDOFF_REL.removeprefix(f"{REL_AUDIT}/"),
    OUTPUT_REL.removeprefix(f"{REL_AUDIT}/"),
    "Fresh RUN-200 audit-dashboard verification required",
    "materialize-run-200-audit-dashboard-verification-wave-40.py",
    ".tmp-run200-dashboard",
    "run_198_dashboard_payload",
    "run_199_reporting",
):
    if phrase not in builder_text:
        fail(f"Builder is missing RUN-199/RUN-200 boundary: {phrase}")

dashboard_bytes = (ROOT / DASHBOARD_REL).read_bytes()
if sha256_bytes(dashboard_bytes) != RUN_198_DASHBOARD_SHA256:
    fail("RUN-199 changed the verified RUN-198 dashboard bytes")
if git_bytes("show", f"{REPORTING_INPUT_COMMIT}:{DASHBOARD_REL}") != dashboard_bytes:
    fail("RUN-199 dashboard differs from the committed reporting input")

run_198_completion_gates = run_198["completion_gates"]
run_198_completion_boundary = run_198["completion_boundary"]
if len(run_198_completion_gates) != 26 or any(row["complete"] for row in run_198_completion_gates):
    fail("RUN-198 completion gates were not 26 false values")
if set(run_198_completion_boundary) != {row["name"] for row in run_198_completion_gates}:
    fail("RUN-198 completion gate and boundary names diverge")
if any(run_198_completion_boundary.values()):
    fail("RUN-198 completion boundary unexpectedly contains a true value")
completion_gates = [
    {
        "name": row["name"],
        "complete": False,
        "reason": "RUN199 reports one bounded historical remediation record only",
    }
    for row in run_198_completion_gates
]
completion_boundary = {row["name"]: False for row in completion_gates}

source_files = {name: file_metadata(f"{REL_AUDIT}/{name}") for name in HUMAN_DOCS}
source_files["findings.json"] = file_metadata(FINDINGS_REL)
source_files["generators/build-current-audit-dashboard.py"] = file_metadata(BUILDER_REL)
source_files[HANDOFF_REL.removeprefix(f"{REL_AUDIT}/")] = file_metadata(HANDOFF_REL)

receipt: dict[str, Any] = {
    "schema_version": "run-199-shift-task-due-recipient-revalidation-remediation-reporting-wave-40-v1",
    "run_id": "RUN-199-SHIFT-TASK-DUE-RECIPIENT-REVALIDATION-01-REMEDIATION-REPORTING-WAVE-40",
    "status": "SHIFT_TASK_DUE_RECIPIENT_REVALIDATION_HISTORICAL_REMEDIATION_REPORTING_MATERIALIZED_DASHBOARD_RUN200_REQUIRED_ZERO_STATIC_PUBLICATION_FINAL_FINDING_OR_COMPLETION_CREDIT",
    "evidence_date": "2026-09-01",
    "scope": {
        "finding_id": FINDING_ID,
        "type": "AUDIT_REPORTING_ONLY",
        "architecture": "SINGLE_ORGANISATION_MULTI_SITE",
        "application_or_test_source_mutated_by_run_199": False,
        "runtime_database_browser_or_build_executed_by_run_199": False,
        "dashboard_html_mutated_by_run_199": False,
        "delegated_runtime_or_review_authorship_claimed_by_run_199": False,
    },
    "pins": {
        "reporting_input_commit": REPORTING_INPUT_COMMIT,
        "reporting_input_tree": REPORTING_INPUT_TREE,
        "reporting_input_parents": REPORTING_INPUT_PARENTS,
        "reporting_input_subject": REPORTING_INPUT_SUBJECT,
        "origin_main_observed": ORIGIN_MAIN_OBSERVED,
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
            "stable_patch_id": STABLE_PATCH_ID,
        },
        "exact_application_test_blobs": EXACT_APPLICATION_TEST_BLOBS,
        "run_198_dashboard": {
            "path": "audit-dashboard.html",
            "sha256": RUN_198_DASHBOARD_SHA256,
        },
        "run_198_materializer": {
            "path": RUN_198_MATERIALIZER_REL.removeprefix(f"{REL_AUDIT}/"),
            "sha256": RUN_198_MATERIALIZER_SHA256,
        },
        "run_198_receipt": {
            "path": RUN_198_RECEIPT_REL.removeprefix(f"{REL_AUDIT}/"),
            "sha256": RUN_198_RECEIPT_SHA256,
            "receipt_self_seal_sha256": RUN_198_RECEIPT_SELF_SEAL,
        },
        "coordination_handoff": {
            "path": HANDOFF_REL.removeprefix(f"{REL_AUDIT}/"),
            "sha256": HANDOFF_SHA256,
            "bytes": HANDOFF_BYTES,
            "lines": HANDOFF_LINES,
            "receipt_self_seal_sha256": HANDOFF_SELF_SEAL,
        },
        "pre_run_199_findings_sha256": PRE_RUN_199_FINDINGS_SHA256,
        "reporting_sources": source_files,
    },
    "reporting_transition": {
        "finding_id": FINDING_ID,
        "counts_before": {
            "retained_claim_records": 17,
            "provisional_source_claims": 8,
            "historical_already_fixed": 2,
            "historical_remediated": 7,
            "final_P0": 0,
            "final_P1": 0,
        },
        "counts_after": {
            "retained_claim_records": 18,
            "provisional_source_claims": 8,
            "historical_already_fixed": 2,
            "historical_remediated": 8,
            "final_P0": 0,
            "final_P1": 0,
        },
        "feature_id": None,
        "candidate_feature_id": None,
        "feature_identity_status": "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW",
        "static_ownership_or_queue_advance": False,
    },
    "bounded_execution_accounting": {
        "prior_unique_total": {"tests": 176, "assertions": 2641},
        "credited_increment": {"tests": 9, "assertions": 50},
        "unique_total": {"tests": 185, "assertions": 2691},
        "post_merge_execution_counted_once": True,
        "delegated_coordination_evidence_not_reexecuted_by_run_199": True,
        "excluded": {
            "red_reproduction": {"failed": 1, "passed": 3, "pending": 1, "assertions": 14},
            "intermediate": {"tests": 5, "assertions": 30, "cache_proofs_excluded": True},
            "isolated_final_replay": {"tests": 9, "assertions": 50},
            "duplicate_post_merge_count": {"tests": 9, "assertions": 50},
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
        "benchmark": {"mapped": 2, "targets": 340, "final_no_match_or_NCM": 0, "unresolved": 338},
        "final_priority": {"P0": 0, "P1": 0},
        "completion_gates_true": 0,
    },
    "dashboard_forward_gate": {
        "required_run": "RUN-200",
        "dashboard_html_changed_by_run_199": False,
        "preserved_run_198_dashboard_sha256": RUN_198_DASHBOARD_SHA256,
        "generator": "generators/materialize-run-200-audit-dashboard-verification-wave-40.py",
        "receipt": "evidence/browser/current-audit-dashboard-verification-run-200-wave-40.json",
        "fresh_four_viewport_navigation_resource_console_verification_required": True,
        "forward_paths_intentionally_unhashed": True,
    },
    "evidence_quality_boundary": {
        "coordination_handoff_transcription_only": True,
        "original_issue_specific_runtime_receipt_present": False,
        "original_issue_specific_independent_review_receipt_present": False,
        "run_199_reexecuted_application_tests": False,
        "run_199_claims_original_runtime_or_review_authorship": False,
        "git_lineage_and_exact_blobs_reverified": True,
        "delegated_evidence_is_bounded_not_promoted_to_browser_release_or_completion": True,
        "scheduler_time_denial_leaves_marker_null_and_emits_no_signal": True,
        "queue_time_denial_suppresses_delivery_only": True,
        "queue_time_denial_does_not_clear_marker_or_retract_signal": True,
    },
    "worktree_attestation": {
        "owned_paths": [path.removeprefix(f"{REL_AUDIT}/") for path in OWNED_RELATIVE_PATHS],
        "path_count": 11,
        "accepted_dirty_states_before_write": {
            "initial_materialization": expected_before,
            "deterministic_rerun": expected_after,
        },
        "application_or_test_dirt": [],
        "dashboard_html_dirty": False,
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
        "application_source_or_test_change_by_run_199": False,
        "application_runtime_reexecution_by_run_199": False,
        "application_browser": False,
        "responsive_application_or_visual_credit": False,
        "audit_dashboard_build_execution_by_run_199": False,
        "static_route_or_page_feature_ownership": False,
        "static_controller_action_bridge": False,
        "queue_advance": False,
        "facility_signal_or_transactional_outbox_correctness": False,
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
        "origin_main": ORIGIN_MAIN_OBSERVED,
        "push_performed": False,
        "publication_claimed": False,
    },
}
receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
output_bytes = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")

temporary_path = OUTPUT_PATH.with_name(f".{OUTPUT_PATH.name}.tmp-run199-reporting")
if temporary_path.exists():
    fail(f"Refusing stale RUN-199 temp file: {temporary_path}")
try:
    with temporary_path.open("xb") as handle:
        handle.write(output_bytes)
        handle.flush()
        os.fsync(handle.fileno())
    if temporary_path.read_bytes() != output_bytes:
        fail("RUN-199 temp-file readback mismatch")
    os.replace(temporary_path, OUTPUT_PATH)
finally:
    if temporary_path.exists():
        temporary_path.unlink()

if OUTPUT_PATH.read_bytes() != output_bytes:
    fail("RUN-199 output readback mismatch")
if parse_status() != expected_after:
    fail("RUN-199 post-write dirty boundary mismatch")

print(
    json.dumps(
        {
            "run_id": receipt["run_id"],
            "output": OUTPUT_REL,
            "sha256": sha256_bytes(output_bytes),
            "receipt_self_seal_sha256": receipt["receipt_self_seal_sha256"],
            "path_count": 11,
            "dashboard_preserved_sha256": RUN_198_DASHBOARD_SHA256,
            "next_gate": "RUN-200",
        },
        sort_keys=True,
    )
)
