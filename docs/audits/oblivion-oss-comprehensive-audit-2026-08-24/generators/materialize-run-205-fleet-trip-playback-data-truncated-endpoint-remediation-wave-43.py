#!/usr/bin/env python3
"""Materialize bounded RUN205 Fleet playback truncation remediation evidence.

This producer records already-completed reproduction, isolated verification,
application-browser, review, merge, and post-merge evidence. It runs no PHP,
Node, database, browser, build, queue, external integration, or publication
action. Static ownership, benchmark, final-finding, release, and completion
credit remain excluded.
"""

from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
from typing import Any


if sys.flags.optimize != 0:
    raise RuntimeError("RUN205 refuses optimized Python")

SCRIPT = Path(__file__).resolve()
AUDIT = SCRIPT.parent.parent
ROOT = next(parent for parent in SCRIPT.parents if (parent / ".git").exists())
PREFIX = AUDIT.relative_to(ROOT).as_posix()
SCRIPT_REL = SCRIPT.relative_to(AUDIT).as_posix()
OUTPUT_REL = (
    "evidence/runtime/"
    "current-run-205-fleet-trip-playback-data-truncated-endpoint-remediation-wave-43.json"
)
OUTPUT = AUDIT / OUTPUT_REL

RUN_ID = "RUN-205-FLEET-TRIP-PLAYBACK-DATA-TRUNCATED-ENDPOINT-01-REMEDIATION-WAVE-43"
FINDING_ID = "FLEET-TRIP-PLAYBACK-DATA-TRUNCATED-ENDPOINT-01"
STATUS = (
    "CURRENT_FLEET_PLAYBACK_TRUNCATION_DEFECT_REPRODUCED_REMEDIATED_"
    "LOCALLY_INTEGRATED_BOUNDED_VERIFIED_NOT_PUBLISHED_REPORTING_NOT_YET_"
    "AUTHORIZED_ZERO_STATIC_OWNERSHIP_FINAL_FINDING_OR_COMPLETION_CREDIT"
)
GOVERNING_PROMPT_SHA256 = (
    "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
)

BASE = "5e3612e9307d22f609af70b262abd7c1d4fa2376"
BASE_TREE = "8ac706605916464597abe593720dd6fca48b002b"
ADVANCED_MAIN = "f7f6a248695b1554637c1f152b2ffa783ce6fd71"
ADVANCED_MAIN_TREE = "98bab0538cc17560782d354665a5873117d99ad6"
FIX = "48c7dca4dee5fc07a98eca6eab7ddf6b0ddda06b"
FIX_TREE = "fb688fc46b8454196e0ac112bc51d7e54eec986c"
FIX_SUBJECT = "fix(fleet): disclose truncated playback routes"
FIX_STABLE_PATCH_ID = "f87bf0b95c3289d2d800e5915226cc8440a3720e"
MERGE = "dc41000a49d04a21f1dc24791f994cb400297f40"
MERGE_TREE = "569a0037b5fa6c614cf2868e72942ed5c261ccd0"
MERGE_SUBJECT = "merge: disclose truncated Fleet playback routes"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"

RUN204_REL = "evidence/browser/current-audit-dashboard-verification-run-204-wave-42.json"
RUN204_SHA256 = "93e295a8eaaf2ed09d5950ab6079a1f028fea894fb607f98d6329c4dbe001bc7"
RUN204_SELF_SEAL = "f1d19715698e7a901471be2f2e18e5f51a3dd2c592f381d78a2e5168d7e10a4c"
RUN204_DASHBOARD_SHA256 = (
    "4017139aba80c74c16a7e7c0ce8c8fa6f765e85ca9f761a90cfaf2b99bf18682"
)

CHANGED_PATHS = {
    "app/Http/Controllers/Fleet/FleetTripController.php": "M",
    "resources/js/pages/fleet-assets/trips/playback.test.tsx": "A",
    "resources/js/pages/fleet-assets/trips/playback.tsx": "M",
    "tests/Feature/FleetAssets/FleetTripPlaybackSitePrivacyTest.php": "M",
}
EXPECTED_BLOBS = {
    "app/Http/Controllers/Fleet/FleetTripController.php": (
        "873b2364933b60d0d00d65a7cb6ef457d9d9f4dc"
    ),
    "resources/js/pages/fleet-assets/trips/playback.test.tsx": (
        "6034c38c57f61de6aed6f1651b8d91372bfd18a6"
    ),
    "resources/js/pages/fleet-assets/trips/playback.tsx": (
        "61fd4c4cbeae1ba95b0812299e268a9d02b4f7b9"
    ),
    "tests/Feature/FleetAssets/FleetTripPlaybackSitePrivacyTest.php": (
        "f4eda653154cfd26c9a853b72268fad1ede1b111"
    ),
}

COMPLETION_GATES = [
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


class MaterializationError(RuntimeError):
    pass


def fail(message: str) -> None:
    raise MaterializationError(message)


def git_bytes(*args: str) -> bytes:
    completed = subprocess.run(
        ["git", *args],
        cwd=ROOT,
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


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def canonical_sha256(payload: dict[str, Any]) -> str:
    return sha256(
        json.dumps(
            payload,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        ).encode("utf-8")
    )


def reject_duplicates(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            fail(f"duplicate JSON key: {key}")
        result[key] = value
    return result


def strict_json(relative: str) -> dict[str, Any]:
    raw = (AUDIT / relative).read_bytes()
    if raw.startswith(b"\xef\xbb\xbf") or b"\r" in raw or not raw.endswith(b"\n"):
        fail(f"non-canonical JSON text: {relative}")
    value = json.loads(
        raw.decode("utf-8"),
        object_pairs_hook=reject_duplicates,
        parse_constant=lambda token: fail(f"non-finite JSON token: {token}"),
    )
    if not isinstance(value, dict):
        fail(f"JSON root is not an object: {relative}")
    expected = (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    if raw != expected:
        fail(f"JSON is not canonical pretty text: {relative}")
    return value


def verify_self_seal(payload: dict[str, Any], expected: str) -> None:
    copy = dict(payload)
    observed = copy.pop("receipt_self_seal_sha256", None)
    if observed != expected or canonical_sha256(copy) != expected:
        fail("prior receipt self-seal mismatch")


def name_status(base: str, target: str) -> dict[str, str]:
    result: dict[str, str] = {}
    for line in git_text("diff", "--name-status", base, target, "--").splitlines():
        if not line:
            continue
        status, path = line.split("\t", 1)
        result[path] = status
    return result


def stable_patch_id(commit: str) -> str:
    show = subprocess.Popen(
        ["git", "show", "--format=email", "--no-ext-diff", "--binary", commit],
        cwd=ROOT,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    patch = subprocess.run(
        ["git", "patch-id", "--stable"],
        cwd=ROOT,
        stdin=show.stdout,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    assert show.stdout is not None
    show.stdout.close()
    show_error = show.stderr.read().decode("utf-8", errors="replace")
    if show.wait() != 0 or patch.returncode != 0:
        fail(f"stable patch-id failed: {show_error} {patch.stderr!r}")
    fields = patch.stdout.decode("utf-8").split()
    if len(fields) != 2 or fields[1] != commit:
        fail("unexpected stable patch-id output")
    return fields[0]


def status_rows() -> dict[str, str]:
    result: dict[str, str] = {}
    raw = git_bytes("status", "--porcelain=v1", "--untracked-files=all")
    for line in raw.decode("utf-8").splitlines():
        if len(line) < 4 or " -> " in line:
            fail(f"unexpected status row: {line!r}")
        result[line[3:].replace("\\", "/")] = line[:2]
    return result


def file_record(relative: str) -> dict[str, Any]:
    raw = (ROOT / relative).read_bytes()
    return {
        "path": relative.removeprefix(f"{PREFIX}/"),
        "sha256": sha256(raw),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


if git_text("rev-parse", "HEAD") != MERGE or git_text("rev-parse", "main") != MERGE:
    fail("RUN205 must execute on the exact Fleet truncation merge")
if git_text("show", "-s", "--format=%T", MERGE) != MERGE_TREE:
    fail("merge tree mismatch")
if git_text("show", "-s", "--format=%P", MERGE).split() != [ADVANCED_MAIN, FIX]:
    fail("merge parent mismatch")
if git_text("show", "-s", "--format=%s", MERGE) != MERGE_SUBJECT:
    fail("merge subject mismatch")
for commit, tree, label in (
    (BASE, BASE_TREE, "baseline"),
    (ADVANCED_MAIN, ADVANCED_MAIN_TREE, "advanced main"),
    (FIX, FIX_TREE, "sealed fix"),
):
    if git_text("show", "-s", "--format=%T", commit) != tree:
        fail(f"{label} tree mismatch")
if git_text("show", "-s", "--format=%P", FIX) != BASE:
    fail("sealed-fix parent mismatch")
if git_text("show", "-s", "--format=%s", FIX) != FIX_SUBJECT:
    fail("sealed-fix subject mismatch")
if stable_patch_id(FIX) != FIX_STABLE_PATCH_ID:
    fail("sealed-fix stable patch-id mismatch")
if name_status(BASE, FIX) != CHANGED_PATHS:
    fail("sealed-fix path boundary mismatch")
if name_status(ADVANCED_MAIN, MERGE) != CHANGED_PATHS:
    fail("merge first-parent path boundary mismatch")
for path, expected in EXPECTED_BLOBS.items():
    if git_text("rev-parse", f"{FIX}:{path}") != expected:
        fail(f"fix blob mismatch: {path}")
    if git_text("rev-parse", f"{MERGE}:{path}") != expected:
        fail(f"merge blob mismatch: {path}")
if git_text("rev-parse", "origin/main") != ORIGIN_MAIN:
    fail("origin/main moved from the observed nonpublication pin")
if git_text("rev-list", "--left-right", "--count", "origin/main...HEAD").split() != ["0", "102"]:
    fail("origin/main comparison mismatch")
if git_text("diff", "--check"):
    fail("git diff --check failed")

script_repo_rel = f"{PREFIX}/{SCRIPT_REL}"
output_repo_rel = f"{PREFIX}/{OUTPUT_REL}"
expected_before = {script_repo_rel: "??"}
expected_after = {script_repo_rel: "??", output_repo_rel: "??"}
if status_rows() not in (expected_before, expected_after):
    fail(f"RUN205 dirty boundary mismatch: {status_rows()}")

run204_raw = (AUDIT / RUN204_REL).read_bytes()
if sha256(run204_raw) != RUN204_SHA256:
    fail("RUN204 receipt hash mismatch")
run204 = strict_json(RUN204_REL)
verify_self_seal(run204, RUN204_SELF_SEAL)
if run204.get("run_id") != "RUN-204-AUDIT-DASHBOARD-VERIFICATION-WAVE-42":
    fail("RUN204 identity mismatch")
dashboard = (AUDIT / "audit-dashboard.html").read_bytes()
if sha256(dashboard) != RUN204_DASHBOARD_SHA256:
    fail("RUN204 dashboard pin mismatch")

payload: dict[str, Any] = {
    "schema_version": "run-205-fleet-trip-playback-data-truncated-endpoint-remediation-wave-43-v1",
    "run_id": RUN_ID,
    "status": STATUS,
    "generated_by": SCRIPT_REL,
    "governing_prompt_sha256": GOVERNING_PROMPT_SHA256,
    "finding": {
        "finding_id": FINDING_ID,
        "priority": "P1",
        "record_status_authorized_after_independent_review": (
            "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
        ),
        "historical_risk": (
            "The endpoint returned the first 2,000 eligible chronological points without "
            "disclosing that later points existed, while the playback UI treated the final "
            "returned point as the trip endpoint."
        ),
        "remediated_contract": [
            "Apply existing eligibility predicates before deterministic occurred_at,id ordering.",
            "Inspect at most 2,001 eligible rows and return the first 2,000 with an always-present strict truncated boolean.",
            "When truncated is true, show an incomplete-route warning and hide endpoint markers; preserve endpoint markers when false.",
        ],
        "exact_changed_paths": [
            {"status": CHANGED_PATHS[path], "path": path, "blob": EXPECTED_BLOBS[path]}
            for path in CHANGED_PATHS
        ],
    },
    "lineage": {
        "baseline": {"commit": BASE, "tree": BASE_TREE},
        "advanced_main_before_merge": {
            "commit": ADVANCED_MAIN,
            "tree": ADVANCED_MAIN_TREE,
        },
        "sealed_fix": {
            "commit": FIX,
            "tree": FIX_TREE,
            "subject": FIX_SUBJECT,
            "stable_patch_id": FIX_STABLE_PATCH_ID,
        },
        "local_merge": {
            "commit": MERGE,
            "tree": MERGE_TREE,
            "parents": [ADVANCED_MAIN, FIX],
            "subject": MERGE_SUBJECT,
        },
        "origin_main_observed": ORIGIN_MAIN,
        "published": False,
    },
    "reproduction": {
        "backend": {"failed": 1, "assertions": 3, "exit_code": 1},
        "frontend_vitest": {"failed_test_files": 1, "failed_tests": 2, "exit_code": 1},
        "credit": False,
    },
    "isolated_verification": {
        "frontend_vitest": {"passed_test_files": 1, "passed_tests": 2, "exit_code": 0},
        "backend_combined": {
            "test_files": [
                "tests/Feature/FleetAssets/FleetTripPlaybackSitePrivacyTest.php",
                "tests/Feature/Fleet/FleetManagementTest.php",
            ],
            "passed": 28,
            "assertions": 226,
            "duration_seconds": 181.65,
            "exit_code": 0,
        },
        "static_and_build": {
            "php_syntax": "PASS",
            "pint": "PASS",
            "eslint_max_warnings_zero": "PASS",
            "prettier": "PASS",
            "typescript": "PASS",
            "production_build": "PASS_3M25S",
            "diff_check": "PASS",
        },
        "aggregate_credit": False,
    },
    "isolated_signed_in_application_browser": {
        "classification": "APPLICATION_BROWSER_NOT_AUDIT_DASHBOARD_BROWSER",
        "states": ["complete", "truncated"],
        "viewports": ["1440x900", "1280x800", "1024x768", "390x844"],
        "state_viewport_checks_passed": 8,
        "state_viewport_checks_total": 8,
        "complete_contract": {
            "truncated": False,
            "endpoint_markers": 2,
        },
        "truncated_contract": {
            "points": 2000,
            "truncated": True,
            "warning_visible": True,
            "endpoint_markers": 0,
        },
        "console_errors": 0,
        "page_errors": 0,
        "horizontal_overflow_failures": 0,
        "visual_inspection": "GO_DESKTOP_AND_MOBILE",
        "aggregate_credit": False,
    },
    "independent_source_reviews": {
        "reviewers": 3,
        "verdict": "GO",
        "blocking_findings": 0,
        "stale_fetch_candidate": {
            "id": "FLEET-TRIP-PLAYBACK-FETCH-STALE-RESPONSE-01",
            "status": "SEPARATE_PROVISIONAL_CANDIDATE_NOT_ADJUDICATED",
            "finding_credit": False,
            "remediation_credit": False,
            "runtime_credit": False,
        },
    },
    "post_merge_verification": {
        "backend_combined": {
            "passed": 28,
            "assertions": 226,
            "duration_seconds": 184.47,
            "exit_code": 0,
        },
        "frontend_vitest": {
            "passed_test_files": 1,
            "passed_tests": 2,
            "duration_seconds": 2.25,
            "exit_code": 0,
        },
        "invalid_preliminary_frontend_attempts": {
            "count": 3,
            "reason": "primary ignored dependency-tree resolution stopped before tests",
            "credit": False,
        },
        "temporary_lockfile_identical_dependency_junctions_removed": True,
    },
    "bounded_aggregate_credit": {
        "credited_tests": 1,
        "credited_assertions": 13,
        "basis": (
            "Only the net-new post-merge PHP component counts once: one new deterministic "
            "equal-time ID-order test plus thirteen new assertions across that test and the "
            "existing cap/eligibility cases. Red runs, isolated replay, the prior 27/213 PHP "
            "component, frontend Vitest, application-browser proof, build, and duplicate "
            "post-merge totals remain separate and uncredited."
        ),
    },
    "cleanup": {
        "main_status_clean_after_merge": True,
        "php_pest_processes": 0,
        "owned_browser_test_node_processes": 0,
        "loopback_port_8765_free": True,
        "numeric_pid_suffixed_test_schemas": 0,
        "browser_test_schema_present": False,
        "isolated_worktree_removed": True,
        "recovery_branch_retained": True,
    },
    "credit_boundaries": {
        "static_route_or_page_ownership": False,
        "controller_action_bridge": False,
        "queue_advance": False,
        "feature_identity_assignment": False,
        "telemetry_lifecycle_or_range": False,
        "cache_control": False,
        "stale_fetch_lifecycle": False,
        "audit_logging": False,
        "benchmark": False,
        "publication_or_release": False,
        "final_finding": False,
        "module_or_audit_completion": False,
    },
    "prior_dashboard_pin": {
        "receipt": file_record(f"{PREFIX}/{RUN204_REL}"),
        "receipt_self_seal_sha256": RUN204_SELF_SEAL,
        "dashboard_sha256": RUN204_DASHBOARD_SHA256,
    },
    "completion_gates": {name: False for name in COMPLETION_GATES},
    "next_gate": {
        "run": "RUN-205R",
        "purpose": "Independent exact-artifact review before live reporting",
    },
}
payload["receipt_self_seal_sha256"] = canonical_sha256(payload)

OUTPUT.parent.mkdir(parents=True, exist_ok=True)
temporary = OUTPUT.with_suffix(OUTPUT.suffix + ".tmp")
temporary.write_text(
    json.dumps(payload, ensure_ascii=False, indent=2) + "\n",
    encoding="utf-8",
    newline="\n",
)
os.replace(temporary, OUTPUT)
print(json.dumps({"written": OUTPUT_REL, "run_id": RUN_ID}, sort_keys=True))
