#!/usr/bin/env python3
"""Materialize the bounded RUN-197 Summary/timeline reporting receipt."""

from __future__ import annotations

import hashlib
import json
import os
import subprocess
from pathlib import Path
from typing import Any


if not __debug__:
    raise RuntimeError("RUN-197 refuses optimized Python because validation must remain active")


ROOT = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
REL_AUDIT = AUDIT_DIR.relative_to(ROOT).as_posix()
OUTPUT_REL = (
    f"{REL_AUDIT}/evidence/source/"
    "current-run-197-summary-timeline-site-privacy-remediation-reporting-wave-39.json"
)
OUTPUT_PATH = ROOT / OUTPUT_REL
MATERIALIZER_REL = (
    f"{REL_AUDIT}/generators/"
    "materialize-run-197-summary-timeline-site-privacy-remediation-reporting-wave-39.py"
)
MATERIALIZER_PATH = ROOT / MATERIALIZER_REL
BUILDER_REL = f"{REL_AUDIT}/generators/build-current-audit-dashboard.py"
DASHBOARD_REL = f"{REL_AUDIT}/audit-dashboard.html"
FINDINGS_REL = f"{REL_AUDIT}/findings.json"

REPORTING_INPUT_COMMIT = "c797f6b64314c9f507e10844f7dd61a85faace47"
REPORTING_INPUT_TREE = "07f43a679faf5ca0452b8f6c32c175ee07e7538a"
ORIGIN_MAIN_OBSERVED = "c39b076547056b1e158c604957a04bd8b75b0f29"
RUN_195_DASHBOARD_SHA256 = "9a87dc70a7b190347ca7029c12abf8e025e4c722a6256502ba8c1c9af542f3b9"

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
    MATERIALIZER_REL,
    OUTPUT_REL,
]
EXPECTED_DIRTY_STATES = {
    **{f"{REL_AUDIT}/{name}": " M" for name in HUMAN_DOCS},
    FINDINGS_REL: " M",
    BUILDER_REL: " M",
    MATERIALIZER_REL: "??",
}

RUN_195_REL = f"{REL_AUDIT}/evidence/browser/current-audit-dashboard-verification-run-195-wave-38.json"
RUN_196_REL = f"{REL_AUDIT}/evidence/runtime/current-run-196-summary-timeline-site-privacy-remediation-wave-39.json"
RUN_196R_REL = f"{REL_AUDIT}/evidence/runtime/current-run-196r-independent-summary-timeline-site-privacy-remediation-review-wave-39.json"

EXPECTED_SOURCE_HASHES = {
    f"{REL_AUDIT}/generators/materialize-run-195-audit-dashboard-verification-wave-38.py": "349576404fe3ff96f1ceeeb9f7fa85887150246fc73bba3d9b48549415509c8d",
    RUN_195_REL: "455ee26c87ec6f07eca687eb1e40d2049c01513002732d08f74696b3dd617456",
    f"{REL_AUDIT}/generators/materialize-run-196-summary-timeline-site-privacy-remediation-wave-39.py": "e8c45110a983d2d210501024d89d6f9b968103141b86feb174c5641757dd5555",
    RUN_196_REL: "96c275826a695a4b41b98891bd6560e6592be415c43fa360f1730c0c7fe9013a",
    f"{REL_AUDIT}/generators/materialize-independent-run-196-summary-timeline-site-privacy-remediation-review-wave-39.py": "0c4fb643e608fa73fdc6118a7b83d1024123cd7857b84c36a136b51b3244edc8",
    RUN_196R_REL: "a53d2b279cf1becff1e7b851d522a43fb2cacfc05f5099250da910c9d3fbe151",
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


def read_json_strict(relative: str) -> dict[str, Any]:
    return json.loads(
        (ROOT / relative).read_text(encoding="utf-8"),
        object_pairs_hook=reject_duplicate_pairs,
        parse_constant=reject_constant,
    )


def canonical_sha256(payload: dict[str, Any]) -> str:
    return sha256_bytes(
        json.dumps(
            payload,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        ).encode("utf-8")
    )


def validate_self_seal(payload: dict[str, Any], expected: str) -> None:
    unsealed = dict(payload)
    observed = unsealed.pop("receipt_self_seal_sha256")
    if observed != expected or canonical_sha256(unsealed) != expected:
        fail("Source receipt self-seal mismatch")


def parse_status() -> dict[str, str]:
    rows: dict[str, str] = {}
    for line in git_text("status", "--porcelain=v1", "--untracked-files=all").splitlines():
        if not line:
            continue
        state = line[:2]
        path = line[3:].replace("\\", "/")
        if " -> " in path:
            fail(f"Unexpected rename in RUN-197 boundary: {line}")
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
    fail("RUN-197 must execute at the exact sealed RUN-196R reporting input commit")
if git_text("show", "-s", "--format=%T", "HEAD") != REPORTING_INPUT_TREE:
    fail("RUN-197 reporting input tree mismatch")
if git_text("diff", "--check"):
    fail("git diff --check reported an error")

dirty = parse_status()
expected_before = dict(EXPECTED_DIRTY_STATES)
expected_after = {**EXPECTED_DIRTY_STATES, OUTPUT_REL: "??"}
if dirty not in (expected_before, expected_after):
    fail(f"RUN-197 dirty boundary mismatch: {dirty}")
if DASHBOARD_REL in dirty:
    fail("RUN-197 must preserve audit-dashboard.html byte-for-byte")

for relative, expected in EXPECTED_SOURCE_HASHES.items():
    if sha256_file(relative) != expected:
        fail(f"Pinned source hash mismatch: {relative}")

run_195 = read_json_strict(RUN_195_REL)
run_196 = read_json_strict(RUN_196_REL)
run_196r = read_json_strict(RUN_196R_REL)
validate_self_seal(run_195, "a3dc0871156ba4c6376a92a4cacab8b8697fa0efcd49dea42d212533aff6b284")
validate_self_seal(run_196, "325269d2a0721c620c9a588da65c016b2355f8c5fb51e6ec112156888483609c")
validate_self_seal(run_196r, "9eefa1031060434a0ee027b5a22d4a3a399ef6472220b5e8628808bf2eb375da")

if run_195["pins"]["final_run_195_dashboard"]["sha256"] != RUN_195_DASHBOARD_SHA256:
    fail("RUN-195 dashboard pin mismatch")
if len(run_195["current_browser_verification"]["viewports"]) != 4:
    fail("RUN-195 viewport denominator mismatch")
if not all(
    row["visible_text_passed"] == row["visible_text_total"] == 39
    and row["page_horizontal_overflow"] is False
    and row["table_containment_failures"] == 0
    for row in run_195["current_browser_verification"]["viewports"].values()
):
    fail("RUN-195 viewport proof mismatch")
if len(run_195["current_browser_verification"]["navigation"]) != 10:
    fail("RUN-195 navigation proof mismatch")
if run_195["html_graph"]["unique_local_resources"] != 491:
    fail("RUN-195 resource denominator mismatch")
if run_195["html_graph"]["anchor_element_count"] != 944:
    fail("RUN-195 anchor denominator mismatch")

if run_196["finding"]["finding_id"] != "SUMMARY-TIMELINE-SITE-PRIVACY-01":
    fail("RUN-196 finding identity mismatch")
if run_196["lineage"]["baseline"]["commit"] != "39a5d97d7d0ff9ea03070e90193581479f423022":
    fail("RUN-196 baseline mismatch")
if run_196["lineage"]["sealed_fix"]["commit"] != "31a9edfbab32a19062ccf15e123cd0b0923b7dc3":
    fail("RUN-196 fix mismatch")
if run_196["lineage"]["effective_merge"]["commit"] != "5c8a1357f830d0b8a8c14924016d89df52ab9e86":
    fail("RUN-196 merge mismatch")
if run_196["finding"]["red_reproduction"]["failed"] != 1:
    fail("RUN-196 red reproduction mismatch")
if run_196["finding"]["isolated_focused_verification"]["passed"] != 15:
    fail("RUN-196 focused test mismatch")
if run_196["finding"]["isolated_focused_verification"]["assertions"] != 32:
    fail("RUN-196 focused assertion mismatch")
if run_196["finding"]["isolated_supporting_compatibility"]["eligible_for_bounded_aggregate"]:
    fail("RUN-196 support must not receive bounded aggregate credit")
if run_196["post_merge_shared_support"]["eligible_for_bounded_aggregate"]:
    fail("RUN-196 shared execution must not receive bounded aggregate credit")

decision = run_196r["decision"]
if decision["verdict"] != "GO" or decision["blocking_discrepancies"] != 0:
    fail("RUN-196R did not authorize reporting")
if decision["authorized_live_reporting_run"] != "RUN-197":
    fail("RUN-196R authorized the wrong reporting run")
if decision["authorized_finding_id"] != "SUMMARY-TIMELINE-SITE-PRIVACY-01":
    fail("RUN-196R authorized the wrong finding")
if decision["authorized_feature_id"] is not None or decision["authorized_candidate_feature_id"] is not None:
    fail("RUN-196R feature identity must remain unassigned")
if decision["authorized_resulting_lineage"] != {
    "retained_claim_records": 17,
    "current_provisional_source_claims": 8,
    "historical_already_fixed_records": 2,
    "historical_remediated_records": 7,
    "bounded_disposition_tests_passed": 176,
    "bounded_disposition_assertions": 2641,
    "final_P0": 0,
    "final_P1": 0,
}:
    fail("RUN-196R authorized lineage mismatch")
if not decision["run198_fresh_dashboard_verification_required"]:
    fail("RUN-196R must retain the RUN-198 forward gate")

findings = read_json_strict(FINDINGS_REL)
if findings["audit_status"] != "EIGHT_PROVISIONAL_TWO_HISTORICAL_ALREADY_FIXED_SEVEN_HISTORICAL_REMEDIATED_ZERO_FINAL_FINDING_CREDIT":
    fail("RUN-197 findings status mismatch")
if {
    key: findings["counts"][key]
    for key in (
        "retained_claim_records",
        "provisional_source_claims",
        "historical_already_fixed",
        "historical_remediated",
        "bounded_disposition_tests_passed",
        "bounded_disposition_assertions",
        "final_P0",
        "final_P1",
    )
} != {
    "retained_claim_records": 17,
    "provisional_source_claims": 8,
    "historical_already_fixed": 2,
    "historical_remediated": 7,
    "bounded_disposition_tests_passed": 176,
    "bounded_disposition_assertions": 2641,
    "final_P0": 0,
    "final_P1": 0,
}:
    fail("RUN-197 findings count mismatch")

records = {row["id"]: row for row in findings["records"]}
if len(records) != 17 or "SUMMARY-TIMELINE-SITE-PRIVACY-01" not in records:
    fail("RUN-197 retained-record set mismatch")
summary = records["SUMMARY-TIMELINE-SITE-PRIVACY-01"]
if summary["record_status"] != "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING":
    fail("Summary record status mismatch")
if summary["feature_id"] is not None or summary["candidate_feature_id"] is not None:
    fail("Summary feature identity must remain unassigned")
if summary["related_feature_ids"] != []:
    fail("Summary related feature IDs must remain empty")
if summary["feature_identity_status"] != "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW":
    fail("Summary feature identity status mismatch")
if summary["current_adjudication"]["application_commit"] != "5c8a1357f830d0b8a8c14924016d89df52ab9e86":
    fail("Summary merge pin mismatch")
if summary["evidence"]["tests_executed"] != 15 or summary["evidence"]["assertions"] != 32:
    fail("Summary bounded execution mismatch")
if any(summary["credit"].values()) or summary["completion_credit"]:
    fail("Summary downstream credit must remain false")
if findings["reconciliation"]["records_without_primary_or_candidate_feature_id"] != [
    "MON-METRIC-REPLAY-DEDUPE-01",
    "SUMMARY-TIMELINE-SITE-PRIVACY-01",
]:
    fail("Feature-unassigned record reconciliation mismatch")

required_phrases = {
    "00-executive-summary.md": [
        "RUN-195–197 Summary/timeline Site privacy remediation checkpoint",
        "17 = 8 provisional + 2 historical already fixed + 7 historical remediated",
        "176 tests / 2,641 assertions",
        "RUN-198",
    ],
    "01-repository-module-map.md": [
        "RUN-197 Summary/timeline record and module boundary",
        "17 = 8 + 2 + 7",
        "176/2,641",
    ],
    "07-module-findings.md": [
        "SUMMARY-TIMELINE-SITE-PRIVACY-01 — feature unassigned — historical remediated",
        "RUN-197 retained Summary/timeline remediation record",
        "176/2,641",
    ],
    "11-prioritised-roadmap.md": [
        "RUN-197 Summary/timeline remediation reporting priority",
        "17 = 8 + 2 + 7",
        "176/2,641",
    ],
    "12-native-build-and-do-not-copy-register.md": [
        "RUN-197 native Summary/timeline remediation boundary",
        "SUMMARY-TIMELINE-SITE-PRIVACY-01",
        "RUN-198",
    ],
    "13-unresolved-questions-and-evidence-gaps.md": [
        "RUN-197 Summary/timeline evidence gaps",
        "176/2,641",
        "RUN-198",
    ],
}
for name, phrases in required_phrases.items():
    text = (AUDIT_DIR / name).read_text(encoding="utf-8")
    missing = [phrase for phrase in phrases if phrase not in text]
    if missing:
        fail(f"Missing RUN-197 phrase(s) in {name}: {missing}")

builder_text = (ROOT / BUILDER_REL).read_text(encoding="utf-8")
compile(builder_text, BUILDER_REL, "exec")
for phrase in (
    "run_197_template_rewrites = [",
    '<a href="#checkpoint">RUN-197</a>',
    "RUN-195–197 Summary/timeline Site-privacy remediation reporting checkpoint",
    "SUMMARY-TIMELINE-SITE-PRIVACY-01",
    "Fresh RUN-198 audit-dashboard verification required",
    "materialize-run-198-audit-dashboard-verification-wave-39.py",
    ".tmp-run198-dashboard",
    "run_195_dashboard_payload",
    "run_197_reporting",
):
    if phrase not in builder_text:
        fail(f"Builder is missing RUN-197/RUN-198 boundary: {phrase}")

dashboard_bytes = (ROOT / DASHBOARD_REL).read_bytes()
if sha256_bytes(dashboard_bytes) != RUN_195_DASHBOARD_SHA256:
    fail("RUN-197 changed the verified RUN-195 dashboard bytes")
if git_bytes("show", f"HEAD:{DASHBOARD_REL}") != dashboard_bytes:
    fail("RUN-197 dashboard differs from the committed reporting input")

completion_gates = [dict(row) for row in run_196r["completion_gates"]]
completion_boundary = dict(run_196r["completion_boundary"])
if len(completion_gates) != 26 or any(row["complete"] for row in completion_gates):
    fail("Completion gates must remain 26 false values")
if {row["name"] for row in completion_gates} != set(completion_boundary):
    fail("Completion gate and boundary maps diverge")

source_files = {
    name: file_metadata(f"{REL_AUDIT}/{name}")
    for name in HUMAN_DOCS
}
source_files["findings.json"] = file_metadata(FINDINGS_REL)
source_files["generators/build-current-audit-dashboard.py"] = file_metadata(BUILDER_REL)

receipt: dict[str, Any] = {
    "schema_version": "run-197-summary-timeline-site-privacy-remediation-reporting-wave-39-v1",
    "run_id": "RUN-197-SUMMARY-TIMELINE-SITE-PRIVACY-01-REMEDIATION-REPORTING-WAVE-39",
    "status": "SUMMARY_TIMELINE_SITE_PRIVACY_HISTORICAL_REMEDIATION_REPORTING_MATERIALIZED_DASHBOARD_RUN198_REQUIRED_ZERO_STATIC_PUBLICATION_FINAL_FINDING_OR_COMPLETION_CREDIT",
    "evidence_date": "2026-09-01",
    "scope": {
        "finding_id": "SUMMARY-TIMELINE-SITE-PRIVACY-01",
        "type": "AUDIT_REPORTING_ONLY",
        "architecture": "SINGLE_ORGANISATION_MULTI_SITE",
        "application_or_test_source_mutated": False,
        "runtime_database_browser_or_build_executed": False,
        "dashboard_html_mutated": False,
    },
    "pins": {
        "reporting_input_commit": REPORTING_INPUT_COMMIT,
        "reporting_input_tree": REPORTING_INPUT_TREE,
        "origin_main_observed": ORIGIN_MAIN_OBSERVED,
        "run_195_dashboard": {
            "path": "audit-dashboard.html",
            "sha256": RUN_195_DASHBOARD_SHA256,
        },
        "run_195_receipt": {"path": RUN_195_REL.removeprefix(f"{REL_AUDIT}/"), "sha256": EXPECTED_SOURCE_HASHES[RUN_195_REL]},
        "run_196_receipt": {"path": RUN_196_REL.removeprefix(f"{REL_AUDIT}/"), "sha256": EXPECTED_SOURCE_HASHES[RUN_196_REL]},
        "run_196r_receipt": {"path": RUN_196R_REL.removeprefix(f"{REL_AUDIT}/"), "sha256": EXPECTED_SOURCE_HASHES[RUN_196R_REL]},
        "reporting_sources": source_files,
    },
    "reporting_transition": {
        "finding_id": "SUMMARY-TIMELINE-SITE-PRIVACY-01",
        "counts_before": {
            "retained_claim_records": 16,
            "provisional_source_claims": 8,
            "historical_already_fixed": 2,
            "historical_remediated": 6,
            "final_P0": 0,
            "final_P1": 0,
        },
        "counts_after": {
            "retained_claim_records": 17,
            "provisional_source_claims": 8,
            "historical_already_fixed": 2,
            "historical_remediated": 7,
            "final_P0": 0,
            "final_P1": 0,
        },
        "feature_id": None,
        "candidate_feature_id": None,
        "feature_identity_status": "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW",
        "static_ownership_or_queue_advance": False,
    },
    "bounded_execution_accounting": {
        "prior_unique_total": {"tests": 161, "assertions": 2609},
        "credited_increment": {"tests": 15, "assertions": 32},
        "unique_total": {"tests": 176, "assertions": 2641},
        "focused_execution_counted_once": True,
        "excluded": {
            "red_reproduction": {"failed": 1, "passed": 5, "assertions": 9},
            "vendor_junction_assertions": 0,
            "emar_support": {"tests": 2, "assertions": 238},
            "shared_post_merge": {"tests": 40, "assertions": 438},
        },
    },
    "preservation_boundary": {
        "static_ownership": {"owners": 667, "routes": 310, "pages": 357, "controller_action_bridges": 98},
        "queue": {"total": 507, "reviewed": 121, "pending": 386, "owned": 99, "without_ownership": 408, "next_zero_based_index": 86},
        "benchmark": {"mapped": 2, "targets": 340, "final_no_match_or_NCM": 0, "unresolved": 338},
        "final_priority": {"P0": 0, "P1": 0},
        "completion_gates_true": 0,
    },
    "dashboard_forward_gate": {
        "required_run": "RUN-198",
        "dashboard_html_changed_by_run_197": False,
        "preserved_run_195_dashboard_sha256": RUN_195_DASHBOARD_SHA256,
        "generator": "generators/materialize-run-198-audit-dashboard-verification-wave-39.py",
        "receipt": "evidence/browser/current-audit-dashboard-verification-run-198-wave-39.json",
        "fresh_four_viewport_navigation_resource_console_verification_required": True,
        "forward_paths_intentionally_unhashed": True,
    },
    "worktree_attestation": {
        "owned_paths": [path.removeprefix(f"{REL_AUDIT}/") for path in OWNED_RELATIVE_PATHS],
        "path_count": 10,
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
    },
    "credit_boundary": {
        "live_findings_register_and_reporting_status": True,
        "application_source_or_test_change": False,
        "application_runtime_reexecution": False,
        "application_browser": False,
        "static_route_or_page_feature_ownership": False,
        "static_controller_action_bridge": False,
        "queue_advance": False,
        "benchmark_mapping": False,
        "final_no_match_or_NCM": False,
        "ease": False,
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

temporary_path = OUTPUT_PATH.with_name(f".{OUTPUT_PATH.name}.tmp-run197-reporting")
if temporary_path.exists():
    fail(f"Refusing stale RUN-197 temp file: {temporary_path}")
try:
    with temporary_path.open("xb") as handle:
        handle.write(output_bytes)
        handle.flush()
        os.fsync(handle.fileno())
    if temporary_path.read_bytes() != output_bytes:
        fail("RUN-197 temp-file readback mismatch")
    os.replace(temporary_path, OUTPUT_PATH)
finally:
    if temporary_path.exists():
        temporary_path.unlink()

if OUTPUT_PATH.read_bytes() != output_bytes:
    fail("RUN-197 output readback mismatch")
if parse_status() != expected_after:
    fail("RUN-197 post-write dirty boundary mismatch")

print(
    json.dumps(
        {
            "run_id": receipt["run_id"],
            "output": OUTPUT_REL,
            "sha256": sha256_bytes(output_bytes),
            "receipt_self_seal_sha256": receipt["receipt_self_seal_sha256"],
            "path_count": 10,
            "dashboard_preserved_sha256": RUN_195_DASHBOARD_SHA256,
            "next_gate": "RUN-198",
        },
        sort_keys=True,
    )
)
