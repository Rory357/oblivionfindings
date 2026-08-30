#!/usr/bin/env python3
"""Materialize the bounded RUN174 SAFE remediation-reporting receipt.

This producer validates the already-authored live reporting transition and the
dashboard builder as source only. It never executes the dashboard builder,
changes the frozen HTML, runs application code or tests, opens a browser,
touches a database, or publishes Git commits.
"""
from __future__ import annotations

import ast
import hashlib
import json
import os
import subprocess
from pathlib import Path
from typing import Any


SCRIPT = Path(__file__).resolve()
AUDIT = SCRIPT.parent.parent
ROOT = next(parent for parent in SCRIPT.parents if (parent / ".git").exists())
PREFIX = AUDIT.relative_to(ROOT).as_posix()
SCRIPT_REL = SCRIPT.relative_to(AUDIT).as_posix()
OUTPUT_REL = (
    "evidence/source/current-run-174-safe-alert-dedup-identity-remediation-"
    "reporting-wave-32.json"
)
OUTPUT = AUDIT / OUTPUT_REL

RUN_ID = "RUN-174-SAFE-ALERT-DEDUP-IDENTITY-01-REMEDIATION-REPORTING-WAVE-32"
STATUS = (
    "SAFE_ALERT_DEDUP_IDENTITY_HISTORICAL_REMEDIATION_REPORTING_MATERIALIZED_"
    "LOCAL_MAIN_NOT_PUBLISHED_DASHBOARD_RUN175_REQUIRED_ZERO_FINAL_FINDING_OR_"
    "COMPLETION_CREDIT"
)
REPORTING_INPUT = "b8bb062320733a1dd6721a54f20d7eef4d914cae"
REPORTING_INPUT_TREE = "6187c60394a58780700305ec67e158eaf0231c4c"
REPORTING_INPUT_PARENT = "705db2dc3ba05a8fdf647cd28bdc9c226a694068"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"
SAFE_BASE = "e488bd3edcda0f154f87e8bbed972f14db409b82"
SAFE_BASE_TREE = "9e93b8aea4f4b907cde3dc59dd0520fba5bd7080"
SAFE_FIX = "dc04067e304adebb47335d4f65e8c61061ec6e29"
SAFE_FIX_TREE = "15a2e4b47788e9f2779030ec6d4d9ca7c1022727"
SAFE_MERGE = REPORTING_INPUT_PARENT
SAFE_MERGE_TREE = "59b4fc58567f64bc80ff3d2e47b52860ce44cb02"
RUN_172_DASHBOARD_SHA256 = (
    "79bb5c671606ca6f596bba6d9a0649ceed9acc549ec57174c6a1102ea22d3f47"
)
RUN_173_RECEIPT_SHA256 = (
    "49a4fa5ad4fefa1c72e449b69150fe05de06e8f9d0055b47e93a0a3061b66e45"
)
RUN_173_RECEIPT_SEAL = (
    "76e16c3a5ae8fe397eb980b648ebe072d280ec36b626eca6c3fb5123c9b47a7a"
)
RUN_173R_RECEIPT_SHA256 = (
    "9a19e5ccb15d955db8bf1bcd80b40a6f89306bc9945625d275f3d6f4c543e652"
)
RUN_173R_RECEIPT_SEAL = (
    "3658e39dd04f1b4707279397085a750121d670bff2153484af9992acdda30929"
)
RUN_173_GENERATOR_SHA256 = (
    "132cb9ad5be6ca420070d67014ab3f8b625c0924022976ab3d2f0262c1ae55ae"
)
RUN_173R_GENERATOR_SHA256 = (
    "9f1e6509ede706dfae3be647db42bded7164a16ced96cec20b4a290c50528b91"
)
BASELINE_SAFE_RECORD_SHA256 = (
    "360386fe1222c75437c2f6140f0860679f67c63f4fe1e95fe5e8bdcc985030a8"
)
CURRENT_SAFE_RECORD_SHA256 = (
    "d74de781f6a9723a96f9de5305917e262d3a1a7c972a4ebc0557b6d768d70859"
)

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
PROSE_SURFACES = REPORTING_SURFACES[:6]
RUN_173_GENERATOR_REL = (
    "generators/materialize-run-173-safe-alert-dedup-identity-remediation-wave-32.py"
)
RUN_173_RECEIPT_REL = (
    "evidence/runtime/current-run-173-safe-alert-dedup-identity-remediation-wave-32.json"
)
RUN_173R_GENERATOR_REL = (
    "generators/materialize-independent-run-173-safe-alert-dedup-identity-"
    "remediation-review-wave-32.py"
)
RUN_173R_RECEIPT_REL = (
    "evidence/runtime/current-run-173r-independent-safe-alert-dedup-identity-"
    "remediation-review-wave-32.json"
)

EXPECTED_UNCHANGED_RECORD_HASHES = {
    "MED-RBAC-01": "3aeac2fd6d69cc84cae814773912eea1bcc9417c3daedb8f08d1ac7d959069cb",
    "MED-CD-SCOPE-01": "c6839938c8c645e59715ce7184e4a833fe516f7403ff1acd896b76a066b48037",
    "MED-CD-ATOMICITY-01": "ebc201ff9af763264c037389ad51a71e07a5e82ad5aa72661fbd40a0dc370ee6",
    "GOV-EXECUTIVE-VISIBILITY-01": "316f7b85d61e16da4eeeb17c6a5b50a8ccdacbe4c443ec86370226268af4d175",
    "GOV-BOARD-PACK-VISIBILITY-01": "78292106d28b8ee8bf8e050aa89741d79b54522cff844a1b482c4b556c5c4c3f",
    "GOV-RESOLUTION-QUORUM-01": "eaf59bfe06b52f012c1a82bbb9a63139208f9840af7a84a26545bca8c81b30dd",
    "HS-REGISTER-SITE-SCOPE-01": "369da912ef9004ea3a7696280dcdf04051e6dca14087f0c6b185986ef1b9ec02",
    "PRIV-REPORT-DOMAIN-RBAC-01": "d0c2d60c324469933b989e4dfc1060c395521a9132c95b5939a231f3a34a2ac5",
    "SAFE-INTAKE-CANONICAL-SCOPE-01": "57e33e6c75f33ff2449e5504a7ee8fd6c3e22588d7eb373c2b36bdc5765ee42b",
    "SAFE-PROJECTION-DURABILITY-01": "6476e684b7ad18453a7dda24545353aefc5816eea537e4b0124df7c09bc71f1e",
    "SET-API-WEBHOOK-DESTINATION-01": "ad3ad1b1ca4f26020ee468f544506f2aa5c0fb2228ff5b908d1815680da12474",
}


def duplicate_rejecting_pairs(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        assert key not in result, f"Duplicate JSON key: {key}"
        result[key] = value
    return result


def strict_text(raw: bytes, label: str) -> None:
    assert not raw.startswith(b"\xef\xbb\xbf"), f"BOM not allowed: {label}"
    assert b"\r" not in raw, f"CR not allowed: {label}"
    assert raw.endswith(b"\n"), f"Final LF required: {label}"
    for number, line in enumerate(raw.splitlines(), start=1):
        assert line == line.rstrip(b" \t"), f"Trailing whitespace: {label}:{number}"


def strict_json_bytes(raw: bytes, label: str) -> dict[str, Any]:
    strict_text(raw, label)
    value = json.loads(
        raw.decode("utf-8"), object_pairs_hook=duplicate_rejecting_pairs
    )
    assert isinstance(value, dict), f"JSON object required: {label}"
    expected = (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode(
        "utf-8"
    )
    assert raw == expected, f"Exact pretty-JSON round trip failed: {label}"
    return value


def read_json(relative: str) -> dict[str, Any]:
    return strict_json_bytes((AUDIT / relative).read_bytes(), relative)


def git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args],
        cwd=ROOT,
        check=True,
        capture_output=True,
        text=True,
        encoding="utf-8",
    )
    return result.stdout.rstrip()


def git_bytes(revision: str, repository_relative: str) -> bytes:
    return subprocess.run(
        ["git", "show", f"{revision}:{repository_relative}"],
        cwd=ROOT,
        check=True,
        capture_output=True,
    ).stdout


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def canonical_sha256(value: Any) -> str:
    return sha256(
        json.dumps(
            value,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        ).encode("utf-8")
    )


def file_record(relative: str) -> dict[str, Any]:
    raw = (AUDIT / relative).read_bytes()
    strict_text(raw, relative)
    return {
        "path": relative,
        "sha256": sha256(raw),
        "git_blob_id": git("hash-object", "--", f"{PREFIX}/{relative}"),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


def canonical_record_hashes(payload: dict[str, Any]) -> dict[str, str]:
    return {row["id"]: canonical_sha256(row) for row in payload["records"]}


def verify_self_seal(payload: dict[str, Any], expected: str) -> None:
    without_seal = dict(payload)
    actual = without_seal.pop("receipt_self_seal_sha256")
    assert actual == expected
    assert canonical_sha256(without_seal) == expected


def validate_repository() -> None:
    assert git("rev-parse", "HEAD") == REPORTING_INPUT
    assert git("rev-parse", "main") == REPORTING_INPUT
    assert git("show", "-s", "--format=%T", "HEAD") == REPORTING_INPUT_TREE
    assert git("rev-parse", "HEAD^") == REPORTING_INPUT_PARENT
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "origin/main") == ORIGIN_MAIN
    assert git("rev-list", "--left-right", "--count", "origin/main...main") == "0\t3"
    assert git("diff", "--cached", "--name-only") == ""

    expected_without_receipt = sorted(
        [f" M {PREFIX}/{path}" for path in REPORTING_SURFACES]
        + [f"?? {PREFIX}/{SCRIPT_REL}"]
    )
    expected_with_receipt = sorted(
        expected_without_receipt + [f"?? {PREFIX}/{OUTPUT_REL}"]
    )
    dirty = sorted(
        line
        for line in git(
            "status", "--porcelain=v1", "--untracked-files=all"
        ).splitlines()
        if line
    )
    assert dirty in (expected_without_receipt, expected_with_receipt), dirty
    assert git("diff", "--check") == ""
    assert sorted(git("diff", "--name-only", "HEAD").splitlines()) == sorted(
        f"{PREFIX}/{path}" for path in REPORTING_SURFACES
    )

    dashboard_relative = f"{PREFIX}/audit-dashboard.html"
    current_dashboard = (AUDIT / "audit-dashboard.html").read_bytes()
    assert current_dashboard == git_bytes(REPORTING_INPUT, dashboard_relative)
    assert sha256(current_dashboard) == RUN_172_DASHBOARD_SHA256


def validate_findings() -> tuple[dict[str, Any], dict[str, str], bytes]:
    relative = f"{PREFIX}/findings.json"
    baseline_raw = git_bytes(REPORTING_INPUT, relative)
    baseline = strict_json_bytes(baseline_raw, f"{REPORTING_INPUT}:findings.json")
    findings = read_json("findings.json")
    baseline_hashes = canonical_record_hashes(baseline)
    current_hashes = canonical_record_hashes(findings)

    assert baseline["audit_status"] == (
        "NINE_PROVISIONAL_TWO_HISTORICAL_ALREADY_FIXED_ONE_HISTORICAL_"
        "REMEDIATED_ZERO_FINAL_FINDING_CREDIT"
    )
    assert findings["audit_status"] == (
        "EIGHT_PROVISIONAL_TWO_HISTORICAL_ALREADY_FIXED_TWO_HISTORICAL_"
        "REMEDIATED_ZERO_FINAL_FINDING_CREDIT"
    )
    assert set(baseline_hashes) == set(current_hashes)
    assert len(current_hashes) == 12
    assert baseline_hashes["SAFE-ALERT-DEDUP-IDENTITY-01"] == (
        BASELINE_SAFE_RECORD_SHA256
    )
    assert current_hashes["SAFE-ALERT-DEDUP-IDENTITY-01"] == (
        CURRENT_SAFE_RECORD_SHA256
    )
    for finding_id, expected in EXPECTED_UNCHANGED_RECORD_HASHES.items():
        assert baseline_hashes[finding_id] == current_hashes[finding_id] == expected

    counts = findings["counts"]
    assert {
        key: counts[key]
        for key in (
            "retained_claim_records",
            "provisional_source_claims",
            "provisional_P1",
            "historical_already_fixed",
            "historical_remediated",
            "bounded_disposition_tests_passed",
            "bounded_disposition_assertions",
            "final_P0",
            "final_P1",
            "benchmark_mapped",
            "final_no_match",
            "benchmark_unresolved",
        )
    } == {
        "retained_claim_records": 12,
        "provisional_source_claims": 8,
        "provisional_P1": 8,
        "historical_already_fixed": 2,
        "historical_remediated": 2,
        "bounded_disposition_tests_passed": 83,
        "bounded_disposition_assertions": 1589,
        "final_P0": 0,
        "final_P1": 0,
        "benchmark_mapped": 2,
        "final_no_match": 0,
        "benchmark_unresolved": 338,
    }
    assert {
        key: counts[key]
        for key in (
            "med_rbac_bounded_tests",
            "med_rbac_bounded_test_assertions",
            "med_cd_scope_focused_tests",
            "med_cd_scope_focused_test_assertions",
            "safe_alert_dedup_focused_tests",
            "safe_alert_dedup_focused_assertions",
            "safe_alert_dedup_supporting_control_room_bridge_tests",
            "safe_alert_dedup_supporting_control_room_bridge_assertions",
            "safe_alert_dedup_supporting_hs_event_tests",
            "safe_alert_dedup_supporting_hs_event_assertions",
            "safe_alert_dedup_terminal_fixture_failures",
        )
    } == {
        "med_rbac_bounded_tests": 73,
        "med_rbac_bounded_test_assertions": 1481,
        "med_cd_scope_focused_tests": 5,
        "med_cd_scope_focused_test_assertions": 48,
        "safe_alert_dedup_focused_tests": 5,
        "safe_alert_dedup_focused_assertions": 60,
        "safe_alert_dedup_supporting_control_room_bridge_tests": 28,
        "safe_alert_dedup_supporting_control_room_bridge_assertions": 73,
        "safe_alert_dedup_supporting_hs_event_tests": 3,
        "safe_alert_dedup_supporting_hs_event_assertions": 5,
        "safe_alert_dedup_terminal_fixture_failures": 6,
    }
    assert "73/1481 MED-RBAC" in counts["bounded_disposition_sum_basis"]
    assert "5/48 focused MED-CD-SCOPE" in counts["bounded_disposition_sum_basis"]
    assert "5/60 post-merge focused SAFE-ALERT-DEDUP-IDENTITY" in counts[
        "bounded_disposition_sum_basis"
    ]
    for excluded in (
        "SAFE red and isolated-green replay execution",
        "28/73 ControlRoomBridgeWiringTest",
        "3/5 HsEvent safeguarding filter",
        "six pre-bridge terminal fixture failures",
    ):
        assert excluded in counts["bounded_disposition_sum_basis"]

    records = {row["id"]: row for row in findings["records"]}
    assert len(records) == 12
    assert sum(
        row["record_status"] == "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING"
        for row in records.values()
    ) == 8
    assert sum(
        row["record_status"]
        == "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING"
        for row in records.values()
    ) == 2
    assert sum(
        row["record_status"]
        == "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
        for row in records.values()
    ) == 2

    safe = records["SAFE-ALERT-DEDUP-IDENTITY-01"]
    assert safe["record_status"] == (
        "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
    )
    assert safe["historical_provenance"][
        "canonical_pre_adjudication_record_sha256"
    ] == BASELINE_SAFE_RECORD_SHA256
    adjudication = safe["current_adjudication"]
    assert {
        key: adjudication[key]
        for key in (
            "application_baseline_commit",
            "application_baseline_tree",
            "fix_commit",
            "fix_tree",
            "application_commit",
            "repository_tree",
            "origin_main_observed",
            "verdict",
        )
    } == {
        "application_baseline_commit": SAFE_BASE,
        "application_baseline_tree": SAFE_BASE_TREE,
        "fix_commit": SAFE_FIX,
        "fix_tree": SAFE_FIX_TREE,
        "application_commit": SAFE_MERGE,
        "repository_tree": SAFE_MERGE_TREE,
        "origin_main_observed": ORIGIN_MAIN,
        "verdict": "REPRODUCED_AND_REMEDIATED_LOCAL_MAIN_NOT_PUBLISHED",
    }
    assert adjudication["integrated_to_main"] is True
    assert adjudication["published_to_origin_main"] is False
    assert adjudication["publication_authorized"] is False
    for key in (
        "safe_intake_canonical_scope_inherited",
        "safe_projection_durability_inherited",
        "timeless_retry_inherited",
        "terminal_transition_fixture_debt_inherited",
        "broader_safeguarding_correctness_inherited",
    ):
        assert adjudication[key] is False
    assert safe["evidence"]["tests_executed"] == 5
    assert safe["evidence"]["assertions"] == 60
    assert safe["evidence"]["supporting_tests"] == 31
    assert safe["evidence"]["supporting_assertions"] == 78
    assert safe["evidence"]["excluded_terminal_fixture_failures"] == 6
    assert safe["current_behaviour"]["runtime_observed"] is True
    assert "unchanged 30-minute" in safe["current_behaviour"]["summary"]
    assert "five-minute retry" in safe["current_behaviour"]["summary"]
    assert "31-minute lifecycle" in safe["current_behaviour"]["summary"]
    assert safe["completion_credit"] is False
    assert all(value is False for value in safe["credit"].values())
    assert all(row["completion_credit"] is False for row in records.values())
    assert all(all(value is False for value in row["credit"].values()) for row in records.values())

    pins = findings["pins"]
    assert pins["safe_alert_dedup_baseline_application_commit"] == SAFE_BASE
    assert pins["safe_alert_dedup_baseline_application_tree"] == SAFE_BASE_TREE
    assert pins["safe_alert_dedup_fix_commit"] == SAFE_FIX
    assert pins["safe_alert_dedup_fix_tree"] == SAFE_FIX_TREE
    assert pins["safe_alert_dedup_local_main_merge_commit"] == SAFE_MERGE
    assert pins["safe_alert_dedup_local_main_tree"] == SAFE_MERGE_TREE
    assert pins["safe_alert_dedup_origin_main_observed"] == ORIGIN_MAIN
    assert pins["run_173_safe_alert_dedup_remediation_sha256"] == (
        RUN_173_RECEIPT_SHA256
    )
    assert pins["run_173r_independent_artifact_review_sha256"] == (
        RUN_173R_RECEIPT_SHA256
    )
    assert findings["reconciliation"] == {
        **findings["reconciliation"],
        "retained_record_count": 12,
        "current_provisional_count": 8,
        "historical_already_fixed_count": 2,
        "historical_remediated_count": 2,
        "final_ids_cross_file_reconciled": False,
    }
    return findings, current_hashes, baseline_raw


def validate_run_173_lineage() -> tuple[dict[str, Any], dict[str, Any]]:
    assert sha256((AUDIT / RUN_173_GENERATOR_REL).read_bytes()) == (
        RUN_173_GENERATOR_SHA256
    )
    assert sha256((AUDIT / RUN_173R_GENERATOR_REL).read_bytes()) == (
        RUN_173R_GENERATOR_SHA256
    )
    assert sha256((AUDIT / RUN_173_RECEIPT_REL).read_bytes()) == (
        RUN_173_RECEIPT_SHA256
    )
    assert sha256((AUDIT / RUN_173R_RECEIPT_REL).read_bytes()) == (
        RUN_173R_RECEIPT_SHA256
    )
    run_173 = read_json(RUN_173_RECEIPT_REL)
    run_173r = read_json(RUN_173R_RECEIPT_REL)
    verify_self_seal(run_173, RUN_173_RECEIPT_SEAL)
    verify_self_seal(run_173r, RUN_173R_RECEIPT_SEAL)

    assert run_173["run_id"] == (
        "RUN-173-SAFE-ALERT-DEDUP-IDENTITY-01-REMEDIATION-WAVE-32"
    )
    assert run_173["pins"]["application_baseline_commit"] == SAFE_BASE
    assert run_173["pins"]["fix_commit"] == SAFE_FIX
    assert run_173["pins"]["local_main_merge_commit"] == SAFE_MERGE
    assert run_173["pins"]["origin_main_observed"] == ORIGIN_MAIN
    assert run_173["pins"]["application_remote_publication_observed"] is False
    assert run_173["pins"]["publication_authorized"] is False
    red = run_173["issue_first_disposition"]["red_baseline"]
    assert {key: red[key] for key in ("failed", "warning_pass", "assertions_reported")} == {
        "failed": 4,
        "warning_pass": 1,
        "assertions_reported": 10,
    }
    execution = run_173["delegated_runtime_execution"]
    assert execution["post_merge_green_focused"]["tests"] == 5
    assert execution["post_merge_green_focused"]["assertions"] == 60
    assert execution["post_merge_green_focused"][
        "unique_bounded_disposition_denominator_credit"
    ] is True
    assert execution["focused_replay_aggregated_more_than_once"] is False
    assert execution["supporting_control_room_bridge_suite"]["tests"] == 28
    assert execution["supporting_control_room_bridge_suite"]["assertions"] == 73
    assert execution["supporting_control_room_bridge_suite"][
        "added_to_bounded_disposition_denominator"
    ] is False
    assert execution["adjacent_hs_event_safeguarding_filter"]["tests_passed"] == 3
    assert execution["adjacent_hs_event_safeguarding_filter"]["assertions"] == 5
    assert execution["adjacent_hs_event_safeguarding_filter"][
        "added_to_bounded_disposition_denominator"
    ] is False
    assert execution["terminal_transition_fixture_debt"]["failures"] == 6
    assert execution["terminal_transition_fixture_debt"]["safe_remediation_credit"] is False
    assert {key for key, value in run_173["credit_boundary"].items() if value} == {
        "historical_condition_confirmed",
        "current_defect_reproduced",
        "application_remediation",
        "bounded_runtime",
        "application_commit_integrated_local_main",
    }
    assert all(value is False for value in run_173["completion_boundary"].values())

    assert run_173r["run_id"] == (
        "RUN-173R-INDEPENDENT-SAFE-ALERT-DEDUP-IDENTITY-01-REMEDIATION-"
        "REVIEW-WAVE-32"
    )
    assert run_173r["pins"]["baseline_safe_record_canonical_sha256"] == (
        BASELINE_SAFE_RECORD_SHA256
    )
    decision = run_173r["decision"]
    assert decision["verdict"] == "GO"
    assert decision["blocking_discrepancies"] == 0
    assert decision["retirement_reporting_authorized"] is True
    assert decision["authorized_finding_id"] == "SAFE-ALERT-DEDUP-IDENTITY-01"
    assert decision["authorized_reporting_status"] == (
        "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
    )
    assert decision["authorized_resulting_lineage"] == {
        "retained_claim_records": 12,
        "current_provisional_source_claims": 8,
        "historical_already_fixed_records": 2,
        "historical_remediated_records": 2,
        "final_P0": 0,
        "final_P1": 0,
    }
    assert decision["authorized_unique_bounded_disposition_increment"] == {
        "tests": 5,
        "assertions": 60,
        "resulting_tests": 83,
        "resulting_assertions": 1589,
        "isolated_replay_counted_again": False,
        "supporting_or_adjacent_runs_counted": False,
        "red_or_terminal_failures_counted": False,
    }
    assert decision["live_reporting_changed_by_run_173r"] is False
    assert decision["run_174_required"] is True
    assert decision["run_175_fresh_dashboard_verification_required"] is True
    assert {key for key, value in run_173r["credit_boundary"].items() if value} == {
        "independent_exact_artifact_review_for_retirement_reporting"
    }
    assert all(value is False for value in run_173r["completion_boundary"].values())
    return run_173, run_173r


def validate_reporting_text_and_builder() -> list[dict[str, Any]]:
    required = {
        "00-executive-summary.md": (
            "## RUN-173–174 SAFE alert dedup identity remediation checkpoint",
            "RUN-174 therefore preserves all 12 identities",
            "83 tests / 1,589 assertions",
            "the SAFE merge and RUN-173/R reporting evidence are local and not published",
            "fresh RUN-175 verification",
        ),
        "01-repository-module-map.md": (
            "RUN-173 then records the bounded `SAFE-ALERT-DEDUP-IDENTITY-01`",
            "current 83/1,589 non-overlapping bounded-disposition total",
            "RUN-174 preserves all 12 identities",
            "8 current provisional P1 + 2 historical already-fixed + 2 historical remediated",
            "requires fresh RUN-175 verification",
        ),
        "07-module-findings.md": (
            "8 current provisional P1 claims + 2 historical already-fixed records + 2 historical remediated records",
            "### SAFE-ALERT-DEDUP-IDENTITY-01",
            "The unique post-merge local-main run passes 5 tests / 60 assertions",
            "RUN-173R authorizes only the historical-remediated reporting transition; RUN-174 alone changes the live record",
            "publication and release remain false",
        ),
        "11-prioritised-roadmap.md": (
            "8 current provisional claims",
            "`SAFE-ALERT-DEDUP-IDENTITY-01` is retained as a historical remediated P1 issue identity",
            "one unique post-merge 5-test / 60-assertion execution",
            "RUN-173R independently returns GO and authorizes RUN-174 reporting",
            "application and audit publication",
        ),
        "12-native-build-and-do-not-copy-register.md": (
            "`SAFE-ALERT-DEDUP-IDENTITY-01`",
            "1 bounded native remediation; 0 benchmark-derived design credit",
            "historical issue remediated on local main",
            "noninheritance, and nonpublication boundaries",
            "For the 8 active claims",
        ),
        "13-unresolved-questions-and-evidence-gaps.md": (
            "RUN-174 changes reporting sources and the dashboard builder while preserving the RUN-172 HTML bytes",
            "83 / 1,589",
            "RUN-174 alone changes the live split to 8 current provisional + 2 historical already-fixed + 2 historical remediated",
            "The SAFE merge is not published",
            "fresh RUN-175",
        ),
    }
    for relative, phrases in required.items():
        raw = (AUDIT / relative).read_bytes()
        strict_text(raw, relative)
        text = raw.decode("utf-8")
        for phrase in phrases:
            assert phrase in text, f"Missing reporting phrase in {relative}: {phrase}"

    combined = "\n".join(
        (AUDIT / relative).read_text(encoding="utf-8") for relative in PROSE_SURFACES
    )
    for prohibited in (
        "RUN-173/R closes SAFE-ALERT-DEDUP-IDENTITY-01",
        "RUN-173/R retires SAFE-ALERT-DEDUP-IDENTITY-01",
        "SAFE-ALERT-DEDUP-IDENTITY-01 final finding",
        "SAFE application merge is published",
        "SAFE remediation completes safeguarding",
    ):
        assert prohibited not in combined

    builder_path = AUDIT / "generators/build-current-audit-dashboard.py"
    builder_raw = builder_path.read_bytes()
    strict_text(builder_raw, "generators/build-current-audit-dashboard.py")
    builder = builder_raw.decode("utf-8")
    ast.parse(builder, filename=str(builder_path))
    assert (
        'run_174_reporting = read_json_strict("evidence/source/current-run-174-safe-alert-'
        'dedup-identity-remediation-reporting-wave-32.json")'
    ) in builder
    assert "run_174_template_rewrites" in builder
    assert "RUN-174: SAFE record reclassified in place" in builder
    assert "Fresh RUN-175 audit-dashboard verification required" in builder
    assert "materialize-run-175-audit-dashboard-verification-wave-32.py" in builder
    assert "current-audit-dashboard-verification-run-175-wave-32.json" in builder
    assert (
        'read_json_strict("evidence/browser/current-audit-dashboard-verification-run-175-'
        'wave-32.json")'
    ) not in builder
    return [file_record(path) for path in REPORTING_SURFACES]


def build_receipt(
    current_hashes: dict[str, str],
    baseline_findings_raw: bytes,
    reporting_manifest: list[dict[str, Any]],
) -> dict[str, Any]:
    completion = {
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
    }
    credit = {
        "live_findings_register_and_reporting_status": True,
        "application_source_or_tests": False,
        "application_runtime_reexecution": False,
        "application_browser": False,
        "benchmark_mapping": False,
        "final_no_match_or_NCM": False,
        "ease": False,
        "pass": False,
        "release": False,
        "publication": False,
        "final_finding": False,
        "completion": False,
        "audit_complete": False,
    }
    receipt: dict[str, Any] = {
        "schema_version": (
            "run-174-safe-alert-dedup-identity-remediation-reporting-wave-32-v1"
        ),
        "run_id": RUN_ID,
        "status": STATUS,
        "materialized_on": "2026-08-30",
        "architecture_rule": {
            "operating_organisations": 1,
            "multiple_sites": True,
            "multi_tenant": False,
            "authorization_boundary": (
                "Site access, exact roles and permissions, canonical ownership, "
                "direct-object denial, privacy, consent, and concern identity"
            ),
        },
        "pins": {
            "reporting_input_commit": REPORTING_INPUT,
            "reporting_input_tree": REPORTING_INPUT_TREE,
            "reporting_input_parent": REPORTING_INPUT_PARENT,
            "origin_main_observed": ORIGIN_MAIN,
            "local_main_ahead": 3,
            "local_main_behind": 0,
            "safe_application_baseline_commit": SAFE_BASE,
            "safe_application_baseline_tree": SAFE_BASE_TREE,
            "safe_fix_commit": SAFE_FIX,
            "safe_fix_tree": SAFE_FIX_TREE,
            "safe_local_main_merge_commit": SAFE_MERGE,
            "safe_local_main_merge_tree": SAFE_MERGE_TREE,
            "run_173_generator": file_record(RUN_173_GENERATOR_REL),
            "run_173_receipt": {
                **file_record(RUN_173_RECEIPT_REL),
                "receipt_self_seal_sha256": RUN_173_RECEIPT_SEAL,
            },
            "run_173r_generator": file_record(RUN_173R_GENERATOR_REL),
            "run_173r_receipt": {
                **file_record(RUN_173R_RECEIPT_REL),
                "receipt_self_seal_sha256": RUN_173R_RECEIPT_SEAL,
            },
            "reporting_materializer": file_record(SCRIPT_REL),
            "baseline_findings": {
                "sha256": sha256(baseline_findings_raw),
                "safe_record_canonical_sha256": BASELINE_SAFE_RECORD_SHA256,
            },
            "current_findings": file_record("findings.json"),
            "dashboard_builder": file_record(
                "generators/build-current-audit-dashboard.py"
            ),
            "unchanged_run_172_dashboard": file_record("audit-dashboard.html"),
        },
        "lineage_roles": {
            "run_172": "verifies only the exact now-superseded RUN-171 dashboard",
            "run_173": (
                "establishes SAFE reproduction, narrow remediation, bounded runtime, "
                "local-main integration, and nonpublication"
            ),
            "run_173r": "independently authorizes bounded retirement reporting only",
            "run_174": "alone changes the live finding register and reporting status",
            "run_175": "required fresh dashboard rebuild and four-viewport verification",
        },
        "reporting_transition": {
            "finding_id": "SAFE-ALERT-DEDUP-IDENTITY-01",
            "authorized_by_run_173r": True,
            "status_before": "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING",
            "status_after": (
                "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
            ),
            "counts_before": {
                "retained_claim_records": 12,
                "provisional_source_claims": 9,
                "historical_already_fixed": 2,
                "historical_remediated": 1,
                "final_P0": 0,
                "final_P1": 0,
            },
            "counts_after": {
                "retained_claim_records": 12,
                "provisional_source_claims": 8,
                "historical_already_fixed": 2,
                "historical_remediated": 2,
                "final_P0": 0,
                "final_P1": 0,
            },
            "baseline_target_record_canonical_sha256": (
                BASELINE_SAFE_RECORD_SHA256
            ),
            "current_target_record_canonical_sha256": (
                CURRENT_SAFE_RECORD_SHA256
            ),
            "unchanged_non_target_record_count": 11,
            "unchanged_non_target_record_hashes": {
                finding_id: current_hashes[finding_id]
                for finding_id in sorted(EXPECTED_UNCHANGED_RECORD_HASHES)
            },
            "reporting_surface_paths": REPORTING_SURFACES,
        },
        "bounded_execution_accounting": {
            "prior_unique_total": {"tests": 78, "assertions": 1529},
            "run_173_post_merge_unique_increment": {
                "tests": 5,
                "assertions": 60,
                "counted_once": True,
            },
            "unique_total": {"tests": 83, "assertions": 1589},
            "excluded_from_unique_total": {
                "safe_red": {
                    "failed": 4,
                    "warning_pass": 1,
                    "assertions_reported": 10,
                },
                "isolated_green_replay": {"tests": 5, "assertions": 60},
                "supporting_control_room_bridge": {"tests": 28, "assertions": 73},
                "adjacent_hs_event_safeguarding": {"tests": 3, "assertions": 5},
                "pre_bridge_terminal_fixture_failures": 6,
                "med_cd_atomicity_and_overlapping_support": True,
            },
        },
        "reporting_manifest": reporting_manifest,
        "preservation_boundary": {
            "exact_modified_reporting_surface_count": 8,
            "all_other_tracked_and_untracked_paths_untouched": True,
            "dashboard_byte_identical_to_reporting_input": True,
            "dashboard_sha256": RUN_172_DASHBOARD_SHA256,
            "ownership_counts": {
                "owners": 665,
                "routes": 308,
                "pages": 357,
                "controller_action_bridges": 96,
            },
            "queue_counts": {
                "reviewed": 119,
                "pending": 388,
                "owned": 97,
                "without_ownership": 410,
            },
            "benchmark_counts": {
                "mapped": 2,
                "total": 340,
                "final_no_match_or_NCM": 0,
                "unresolved": 338,
            },
        },
        "publication_boundary": {
            "origin_main": ORIGIN_MAIN,
            "safe_application_published": False,
            "run_173_to_174_published": False,
            "publication_authorized": False,
            "materializer_performed_push_or_publication": False,
        },
        "dashboard_forward_gate": {
            "required_run": "RUN-175",
            "dashboard_html_changed_by_run_174": False,
            "unchanged_dashboard_sha256": RUN_172_DASHBOARD_SHA256,
            "fresh_rebuild_required": True,
            "fresh_four_viewport_verification_required": True,
            "required_viewports": ["1440x900", "1280x800", "1024x768", "390x844"],
            "future_receipt_link_is_unhashed_to_avoid_cycle": True,
        },
        "noninheritance_boundary": {
            "isolated_green_replay_recredited": False,
            "supporting_or_adjacent_runs_recredited": False,
            "terminal_transition_fixture_debt": False,
            "timeless_retry": False,
            "within_window_escalation_semantics": False,
            "unused_escalation_parameter": False,
            "broader_safeguarding_correctness": False,
            "application_browser_or_ease": False,
            "benchmark_mapping_or_final_no_match_NCM": False,
            "final_finding_or_feature_module_pass_release_completion": False,
        },
        "credit_boundary": credit,
        "completion_boundary": completion,
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [OUTPUT_REL],
    }
    assert {key for key, value in credit.items() if value} == {
        "live_findings_register_and_reporting_status"
    }
    assert all(value is False for value in completion.values())
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    return receipt


def write_receipt(receipt: dict[str, Any]) -> bytes:
    output_bytes = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode(
        "utf-8"
    )
    temporary = OUTPUT.with_name(f".{OUTPUT.name}.tmp-run174")
    assert not temporary.exists(), f"Refusing stale temporary file: {temporary}"
    try:
        with temporary.open("xb") as handle:
            handle.write(output_bytes)
            handle.flush()
            os.fsync(handle.fileno())
        assert temporary.read_bytes() == output_bytes
        os.replace(temporary, OUTPUT)
    finally:
        if temporary.exists():
            temporary.unlink()
    assert OUTPUT.read_bytes() == output_bytes
    written = strict_json_bytes(output_bytes, OUTPUT_REL)
    without_seal = dict(written)
    seal = without_seal.pop("receipt_self_seal_sha256")
    assert canonical_sha256(without_seal) == seal
    return output_bytes


def main() -> None:
    validate_repository()
    _, current_hashes, baseline_raw = validate_findings()
    validate_run_173_lineage()
    reporting_manifest = validate_reporting_text_and_builder()
    receipt = build_receipt(current_hashes, baseline_raw, reporting_manifest)
    output_bytes = write_receipt(receipt)
    assert reporting_manifest == [file_record(path) for path in REPORTING_SURFACES]
    validate_repository()
    print(
        json.dumps(
            {
                "run_id": RUN_ID,
                "status": STATUS,
                "materializer_sha256": file_record(SCRIPT_REL)["sha256"],
                "receipt_sha256": sha256(output_bytes),
                "receipt_self_seal_sha256": receipt["receipt_self_seal_sha256"],
                "reporting_surfaces": len(REPORTING_SURFACES),
                "lineage": "12=8+2+2",
                "unique_bounded_execution": "83/1589",
                "dashboard_sha256_unchanged": RUN_172_DASHBOARD_SHA256,
                "published": False,
                "audit_complete": False,
            },
            ensure_ascii=False,
            sort_keys=True,
        )
    )


if __name__ == "__main__":
    main()
