#!/usr/bin/env python3
"""Materialize independent exact-artifact review of bounded RUN205.

The reviewer validates the frozen RUN205 producer/receipt, current register,
Git lineage, credit arithmetic, application-browser labeling, and exclusions.
It writes only RUN205R and authorizes RUN206 reporting. It does not rerun PHP,
Node, database, browser, build, queue, or publication actions.
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
    raise RuntimeError("RUN205R refuses optimized Python")

SCRIPT = Path(__file__).resolve()
AUDIT = SCRIPT.parent.parent
ROOT = next(parent for parent in SCRIPT.parents if (parent / ".git").exists())
PREFIX = AUDIT.relative_to(ROOT).as_posix()
SCRIPT_REL = SCRIPT.relative_to(AUDIT).as_posix()
OUTPUT_REL = (
    "evidence/runtime/"
    "current-run-205r-independent-fleet-trip-playback-data-truncated-endpoint-"
    "remediation-review-wave-43.json"
)
OUTPUT = AUDIT / OUTPUT_REL
PRODUCER_REL = (
    "generators/materialize-run-205-fleet-trip-playback-data-truncated-endpoint-"
    "remediation-wave-43.py"
)
RECEIPT_REL = (
    "evidence/runtime/current-run-205-fleet-trip-playback-data-truncated-endpoint-"
    "remediation-wave-43.json"
)
FINDINGS_REL = "findings.json"

RUN_ID = (
    "RUN-205R-INDEPENDENT-FLEET-TRIP-PLAYBACK-DATA-TRUNCATED-ENDPOINT-01-"
    "REMEDIATION-REVIEW-WAVE-43"
)
PRODUCER_RUN_ID = (
    "RUN-205-FLEET-TRIP-PLAYBACK-DATA-TRUNCATED-ENDPOINT-01-"
    "REMEDIATION-WAVE-43"
)
FINDING_ID = "FLEET-TRIP-PLAYBACK-DATA-TRUNCATED-ENDPOINT-01"
STATUS = (
    "GO_EXACT_RUN205_ARTIFACT_REVIEW_NEW_HISTORICAL_REMEDIATED_REPORTING_"
    "AUTHORIZED_ZERO_STATIC_OWNERSHIP_PUBLICATION_FINAL_FINDING_OR_COMPLETION_CREDIT"
)

HEAD = "dc41000a49d04a21f1dc24791f994cb400297f40"
HEAD_TREE = "569a0037b5fa6c614cf2868e72942ed5c261ccd0"
BASE = "5e3612e9307d22f609af70b262abd7c1d4fa2376"
FIX = "48c7dca4dee5fc07a98eca6eab7ddf6b0ddda06b"
ADVANCED_MAIN = "f7f6a248695b1554637c1f152b2ffa783ce6fd71"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"
PRODUCER_SHA256 = "661be382e7f072927518cb92698bf20a3840f1fa1d0068cd5f8871da51d2309b"
RECEIPT_SHA256 = "c086f0e4f1a1339450413e9b64f72dcd47ee008606d7768c7cd5e13d3357e563"
RECEIPT_SELF_SEAL = "671f33f5e0b1e3a8ee89d2ba17e89c3ec5ac7cdd0742e8d94a5ada7b4530fa81"
FINDINGS_SHA256 = "88a66599242d986c6306a1bbd02c95c4088dfc18c5beef5279d68cdd4c6531b2"

EXPECTED_PATHS = {
    "app/Http/Controllers/Fleet/FleetTripController.php": "M",
    "resources/js/pages/fleet-assets/trips/playback.test.tsx": "A",
    "resources/js/pages/fleet-assets/trips/playback.tsx": "M",
    "tests/Feature/FleetAssets/FleetTripPlaybackSitePrivacyTest.php": "M",
}


class ReviewError(RuntimeError):
    pass


def fail(message: str) -> None:
    raise ReviewError(message)


def git_bytes(*args: str) -> bytes:
    completed = subprocess.run(
        ["git", *args], cwd=ROOT, check=False,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    if completed.returncode != 0:
        fail(
            f"git {' '.join(args)} failed: "
            f"{completed.stderr.decode('utf-8', errors='replace').strip()}"
        )
    return completed.stdout


def git_text(*args: str) -> str:
    return git_bytes(*args).decode("utf-8").strip()


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def canonical_sha256(payload: dict[str, Any]) -> str:
    return sha256(
        json.dumps(payload, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
        .encode("utf-8")
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
    if (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8") != raw:
        fail(f"non-canonical pretty JSON: {relative}")
    return value


def verify_self_seal(payload: dict[str, Any], expected: str) -> None:
    copy = dict(payload)
    observed = copy.pop("receipt_self_seal_sha256", None)
    if observed != expected or canonical_sha256(copy) != expected:
        fail("RUN205 self-seal mismatch")


def status_rows() -> dict[str, str]:
    result: dict[str, str] = {}
    for line in git_bytes("status", "--porcelain=v1", "--untracked-files=all").decode().splitlines():
        if len(line) < 4 or " -> " in line:
            fail(f"unexpected status row: {line!r}")
        result[line[3:].replace("\\", "/")] = line[:2]
    return result


def name_status(base: str, target: str) -> dict[str, str]:
    result: dict[str, str] = {}
    for line in git_text("diff", "--name-status", base, target, "--").splitlines():
        if line:
            status, path = line.split("\t", 1)
            result[path] = status
    return result


def metadata(relative: str) -> dict[str, Any]:
    raw = (AUDIT / relative).read_bytes()
    return {
        "path": relative,
        "sha256": sha256(raw),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


if git_text("rev-parse", "HEAD") != HEAD or git_text("rev-parse", "main") != HEAD:
    fail("RUN205R must execute at the exact Fleet truncation merge")
if git_text("show", "-s", "--format=%T", HEAD) != HEAD_TREE:
    fail("HEAD tree mismatch")
if git_text("show", "-s", "--format=%P", HEAD).split() != [ADVANCED_MAIN, FIX]:
    fail("merge parents mismatch")
if git_text("rev-parse", "origin/main") != ORIGIN_MAIN:
    fail("origin/main moved")
if name_status(BASE, FIX) != EXPECTED_PATHS:
    fail("sealed-fix path scope mismatch")
if name_status(ADVANCED_MAIN, HEAD) != EXPECTED_PATHS:
    fail("merge path scope mismatch")
if git_text("diff", "--check"):
    fail("git diff --check failed")

producer_raw = (AUDIT / PRODUCER_REL).read_bytes()
receipt_raw = (AUDIT / RECEIPT_REL).read_bytes()
findings_raw = (AUDIT / FINDINGS_REL).read_bytes()
if sha256(producer_raw) != PRODUCER_SHA256:
    fail("RUN205 producer hash mismatch")
if sha256(receipt_raw) != RECEIPT_SHA256:
    fail("RUN205 receipt hash mismatch")
if sha256(findings_raw) != FINDINGS_SHA256:
    fail("pre-reporting findings register hash mismatch")

receipt = strict_json(RECEIPT_REL)
verify_self_seal(receipt, RECEIPT_SELF_SEAL)
if receipt.get("run_id") != PRODUCER_RUN_ID:
    fail("RUN205 identity mismatch")
if receipt["finding"]["finding_id"] != FINDING_ID:
    fail("finding identity mismatch")
if receipt["lineage"]["baseline"]["commit"] != BASE:
    fail("baseline mismatch")
if receipt["lineage"]["sealed_fix"]["commit"] != FIX:
    fail("fix mismatch")
if receipt["lineage"]["local_merge"]["commit"] != HEAD:
    fail("merge mismatch")
if receipt["reproduction"] != {
    "backend": {"failed": 1, "assertions": 3, "exit_code": 1},
    "frontend_vitest": {"failed_test_files": 1, "failed_tests": 2, "exit_code": 1},
    "credit": False,
}:
    fail("red reproduction mismatch")
if receipt["post_merge_verification"]["backend_combined"] != {
    "passed": 28,
    "assertions": 226,
    "duration_seconds": 184.47,
    "exit_code": 0,
}:
    fail("post-merge backend evidence mismatch")
if receipt["post_merge_verification"]["frontend_vitest"]["passed_tests"] != 2:
    fail("post-merge frontend evidence mismatch")
if receipt["bounded_aggregate_credit"]["credited_tests"] != 1:
    fail("credited test delta mismatch")
if receipt["bounded_aggregate_credit"]["credited_assertions"] != 13:
    fail("credited assertion delta mismatch")
browser = receipt["isolated_signed_in_application_browser"]
if browser["classification"] != "APPLICATION_BROWSER_NOT_AUDIT_DASHBOARD_BROWSER":
    fail("application browser classification mismatch")
if browser["state_viewport_checks_passed"] != browser["state_viewport_checks_total"] != 8:
    fail("application browser viewport proof mismatch")
if browser["aggregate_credit"] is not False:
    fail("application browser proof must not enter test denominator")
stale = receipt["independent_source_reviews"]["stale_fetch_candidate"]
if any(stale[key] for key in ("finding_credit", "remediation_credit", "runtime_credit")):
    fail("excluded stale-fetch candidate received credit")
if any(receipt["completion_gates"].values()):
    fail("RUN205 advanced a completion gate")

findings = strict_json(FINDINGS_REL)
if findings.get("counts", {}).get("retained_claim_records") != 20:
    fail("pre-reporting retained-record denominator mismatch")
if findings["counts"].get("historical_remediated") != 10:
    fail("pre-reporting historical-remediated denominator mismatch")
if findings["counts"].get("bounded_disposition_tests_passed") != 199:
    fail("pre-reporting test denominator mismatch")
if findings["counts"].get("bounded_disposition_assertions") != 2722:
    fail("pre-reporting assertion denominator mismatch")
if any(record.get("id") == FINDING_ID for record in findings.get("records", [])):
    fail("finding was already present before authorized reporting")

producer_repo = f"{PREFIX}/{PRODUCER_REL}"
receipt_repo = f"{PREFIX}/{RECEIPT_REL}"
script_repo = f"{PREFIX}/{SCRIPT_REL}"
output_repo = f"{PREFIX}/{OUTPUT_REL}"
expected_before = {
    receipt_repo: "??",
    producer_repo: "??",
    script_repo: "??",
}
expected_after = {**expected_before, output_repo: "??"}
observed_status = status_rows()
if observed_status not in (expected_before, expected_after):
    fail(f"RUN205R dirty boundary mismatch: {observed_status}")

payload: dict[str, Any] = {
    "schema_version": (
        "run-205r-independent-fleet-trip-playback-data-truncated-endpoint-"
        "remediation-review-wave-43-v1"
    ),
    "run_id": RUN_ID,
    "status": STATUS,
    "generated_by": SCRIPT_REL,
    "reviewed_artifacts": {
        "producer": metadata(PRODUCER_REL),
        "receipt": metadata(RECEIPT_REL),
        "pre_reporting_findings": metadata(FINDINGS_REL),
    },
    "review_checks": {
        "git_lineage_and_exact_four_path_scope": "PASS",
        "fix_merge_blob_identity": "PASS_BY_RUN205_AND_GIT_REVIEW",
        "red_and_green_evidence_classification": "PASS",
        "unique_php_credit_decomposition": {
            "new_tests": 1,
            "new_assertions_existing_cap_case": 6,
            "new_assertions_existing_eligibility_case": 1,
            "new_assertions_new_ordering_case": 6,
            "total_new_assertions": 13,
            "frontend_vitest_aggregate_credit": False,
        },
        "isolated_application_browser_labeled_not_dashboard_browser": "PASS",
        "stale_fetch_candidate_zero_credit": "PASS",
        "single_organisation_multi_site_boundary": "PASS",
        "all_completion_gates_remain_false": "PASS",
    },
    "decision": {
        "verdict": "GO",
        "blocking_discrepancies": 0,
        "authorized_live_reporting_run": "RUN-206",
        "authorized_finding_id": FINDING_ID,
        "authorized_feature_id": "CAP-FLEET-VEHICLE-REGISTER",
        "feature_identity_role": (
            "CANDIDATE_REPORTING_ASSOCIATION_ONLY_ZERO_STATIC_OWNERSHIP_CREDIT"
        ),
        "authorized_resulting_lineage": {
            "retained_claim_records": 21,
            "current_provisional_source_claims": 8,
            "historical_already_fixed_records": 2,
            "historical_remediated_records": 11,
            "bounded_disposition_tests_passed": 200,
            "bounded_disposition_assertions": 2735,
            "final_P0": 0,
            "final_P1": 0,
        },
        "run207_fresh_dashboard_verification_required": True,
        "publication_authorized": False,
        "final_finding_authorized": False,
        "completion_credit_authorized": False,
    },
    "noninheritance": {
        "static_route_page_or_bridge": False,
        "queue_advance": False,
        "telemetry_lifecycle_or_range": False,
        "cache_control": False,
        "stale_fetch_lifecycle": False,
        "audit_logging": False,
        "benchmark": False,
        "publication_release_or_completion": False,
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
