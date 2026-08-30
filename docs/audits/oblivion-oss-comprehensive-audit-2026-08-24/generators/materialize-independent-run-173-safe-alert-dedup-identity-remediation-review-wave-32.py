#!/usr/bin/env python3
"""Materialize the independent exact-artifact review of RUN173.

The review validates frozen RUN173 producer bytes and writes only RUN173R. It
does not rerun PHP or tests, touch a database, start a browser, mutate product
source, publish commits, or change the live finding register.
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
SCRIPT_REL = SCRIPT.relative_to(AUDIT).as_posix()
OUTPUT_REL = "evidence/runtime/current-run-173r-independent-safe-alert-dedup-identity-remediation-review-wave-32.json"
OUTPUT = AUDIT / OUTPUT_REL
PRODUCER_REL = "generators/materialize-run-173-safe-alert-dedup-identity-remediation-wave-32.py"
RECEIPT_REL = "evidence/runtime/current-run-173-safe-alert-dedup-identity-remediation-wave-32.json"

RUN_ID = "RUN-173R-INDEPENDENT-SAFE-ALERT-DEDUP-IDENTITY-01-REMEDIATION-REVIEW-WAVE-32"
STATUS = (
    "GO_EXACT_RUN173_ARTIFACT_REVIEW_HISTORICAL_REMEDIATED_REPORTING_"
    "AUTHORIZED_ZERO_PUBLICATION_FINAL_FINDING_OR_COMPLETION_CREDIT"
)

EXPECTED_PRODUCER = {
    "sha256": "132cb9ad5be6ca420070d67014ab3f8b625c0924022976ab3d2f0262c1ae55ae",
    "git_blob_id": "087f12a17ffec68c8f99f993935a79ab79f731ce",
    "bytes": 19802,
    "lines": 471,
}
EXPECTED_RECEIPT = {
    "sha256": "49a4fa5ad4fefa1c72e449b69150fe05de06e8f9d0055b47e93a0a3061b66e45",
    "git_blob_id": "c10689138deeaad19d3901b53b203a49d7cf9e53",
    "bytes": 11958,
    "lines": 279,
    "receipt_self_seal_sha256": "76e16c3a5ae8fe397eb980b648ebe072d280ec36b626eca6c3fb5123c9b47a7a",
}
BASE = "e488bd3edcda0f154f87e8bbed972f14db409b82"
BASE_TREE = "9e93b8aea4f4b907cde3dc59dd0520fba5bd7080"
FIX = "dc04067e304adebb47335d4f65e8c61061ec6e29"
FIX_TREE = "15a2e4b47788e9f2779030ec6d4d9ca7c1022727"
AUDIT_RELEASE = "c39b076547056b1e158c604957a04bd8b75b0f29"
AUDIT_RELEASE_TREE = "9ba0e09593c890908bdd8a9f3f1cc1f7b9fddeda"
MERGE = "705db2dc3ba05a8fdf647cd28bdc9c226a694068"
MERGE_TREE = "59b4fc58567f64bc80ff3d2e47b52860ce44cb02"
SAFE_BASELINE_RECORD_SHA256 = "360386fe1222c75437c2f6140f0860679f67c63f4fe1e95fe5e8bdcc985030a8"


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def canonical_sha256(value: Any) -> str:
    return sha256(
        json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
    )


def reject_duplicates(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    value: dict[str, Any] = {}
    for key, item in pairs:
        assert key not in value, f"duplicate JSON key: {key}"
        value[key] = item
    return value


def strict_text(raw: bytes, label: str) -> None:
    assert not raw.startswith(b"\xef\xbb\xbf"), f"BOM not allowed: {label}"
    assert b"\r" not in raw, f"CR not allowed: {label}"
    assert raw.endswith(b"\n"), f"final LF required: {label}"
    for number, line in enumerate(raw.splitlines(), start=1):
        assert line == line.rstrip(b" \t"), f"trailing whitespace: {label}:{number}"


def strict_json(relative: str) -> dict[str, Any]:
    raw = (AUDIT / relative).read_bytes()
    strict_text(raw, relative)
    value = json.loads(raw.decode("utf-8"), object_pairs_hook=reject_duplicates)
    assert isinstance(value, dict)
    expected = (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    assert raw == expected, f"pretty JSON round-trip failed: {relative}"
    return value


def file_record(relative: str) -> dict[str, Any]:
    raw = (AUDIT / relative).read_bytes()
    strict_text(raw, relative)
    return {
        "path": relative,
        "sha256": sha256(raw),
        "git_blob_id": hashlib.sha1(
            f"blob {len(raw)}\0".encode("ascii") + raw
        ).hexdigest(),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


def git(*args: str) -> str:
    return subprocess.run(
        ["git", *args],
        cwd=ROOT,
        check=True,
        capture_output=True,
        text=True,
        encoding="utf-8",
    ).stdout.rstrip()


def validate_producer() -> dict[str, Any]:
    producer_file = file_record(PRODUCER_REL)
    receipt_file = file_record(RECEIPT_REL)
    assert {key: producer_file[key] for key in EXPECTED_PRODUCER} == EXPECTED_PRODUCER
    assert {
        key: receipt_file[key] for key in ("sha256", "git_blob_id", "bytes", "lines")
    } == {
        key: EXPECTED_RECEIPT[key] for key in ("sha256", "git_blob_id", "bytes", "lines")
    }

    producer = strict_json(RECEIPT_REL)
    copy = dict(producer)
    seal = copy.pop("receipt_self_seal_sha256")
    assert seal == EXPECTED_RECEIPT["receipt_self_seal_sha256"]
    assert seal == canonical_sha256(copy)
    assert producer["run_id"] == "RUN-173-SAFE-ALERT-DEDUP-IDENTITY-01-REMEDIATION-WAVE-32"
    pins = producer["pins"]
    assert pins["application_baseline_commit"] == BASE
    assert pins["application_baseline_tree"] == BASE_TREE
    assert pins["fix_commit"] == FIX
    assert pins["fix_tree"] == FIX_TREE
    assert pins["clean_audit_release_commit"] == AUDIT_RELEASE
    assert pins["clean_audit_release_tree"] == AUDIT_RELEASE_TREE
    assert pins["local_main_merge_commit"] == MERGE
    assert pins["local_main_tree"] == MERGE_TREE
    assert pins["merge_parents"] == [AUDIT_RELEASE, FIX]
    assert pins["origin_main_observed"] == AUDIT_RELEASE
    assert pins["local_main_ahead"] == 2 and pins["local_main_behind"] == 0
    assert pins["application_remote_publication_observed"] is False
    assert pins["publication_authorized"] is False
    assert producer["issue_first_disposition"]["finding_id"] == "SAFE-ALERT-DEDUP-IDENTITY-01"
    assert producer["issue_first_disposition"]["verdict"] == (
        "REPRODUCED_AND_REMEDIATED_LOCAL_MAIN_NOT_PUBLISHED"
    )
    runtime = producer["delegated_runtime_execution"]
    assert runtime["post_merge_green_focused"]["tests"] == 5
    assert runtime["post_merge_green_focused"]["assertions"] == 60
    assert runtime["post_merge_green_focused"]["unique_bounded_disposition_denominator_credit"] is True
    assert runtime["focused_replay_aggregated_more_than_once"] is False
    assert runtime["supporting_control_room_bridge_suite"]["added_to_bounded_disposition_denominator"] is False
    assert runtime["adjacent_hs_event_safeguarding_filter"]["added_to_bounded_disposition_denominator"] is False
    assert runtime["terminal_transition_fixture_debt"]["safe_remediation_credit"] is False
    assert runtime["full_suite_or_coverage_credit"] is False
    assert producer["independent_static_review"]["verdict"] == "GO"
    assert producer["independent_static_review"]["retirement_reporting_authorized"] is False
    assert producer["credit_boundary"]["application_commit_published"] is False
    assert producer["credit_boundary"]["finding_retirement_reporting"] is False
    assert producer["credit_boundary"]["final_finding"] is False
    assert producer["credit_boundary"]["completion"] is False
    assert all(value is False for value in producer["completion_boundary"].values())
    return producer


def validate_live_register_before_reporting() -> None:
    findings = strict_json("findings.json")
    records = {record["id"]: record for record in findings["records"]}
    assert len(records) == len(findings["records"]) == 12
    assert canonical_sha256(records["SAFE-ALERT-DEDUP-IDENTITY-01"]) == SAFE_BASELINE_RECORD_SHA256
    assert records["SAFE-ALERT-DEDUP-IDENTITY-01"]["record_status"] == (
        "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING"
    )
    assert findings["counts"]["retained_claim_records"] == 12
    assert findings["counts"]["provisional_source_claims"] == 9
    assert findings["counts"]["historical_already_fixed"] == 2
    assert findings["counts"]["historical_remediated"] == 1
    assert findings["counts"]["bounded_disposition_tests_passed"] == 78
    assert findings["counts"]["bounded_disposition_assertions"] == 1529
    assert findings["counts"]["final_P0"] == findings["counts"]["final_P1"] == 0


def build_receipt(producer: dict[str, Any]) -> dict[str, Any]:
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
    receipt: dict[str, Any] = {
        "schema_version": "run-173r-independent-safe-alert-dedup-identity-remediation-review-wave-32-v1",
        "run_id": RUN_ID,
        "status": STATUS,
        "materialized_on": "2026-08-30",
        "architecture_boundary": producer["architecture_boundary"],
        "pins": {
            "application_baseline_commit": BASE,
            "application_baseline_tree": BASE_TREE,
            "fix_commit": FIX,
            "fix_tree": FIX_TREE,
            "clean_audit_release_commit": AUDIT_RELEASE,
            "clean_audit_release_tree": AUDIT_RELEASE_TREE,
            "local_main_merge_commit": MERGE,
            "local_main_tree": MERGE_TREE,
            "origin_main_observed": AUDIT_RELEASE,
            "application_published": False,
            "producer_generator": {"path": PRODUCER_REL, **EXPECTED_PRODUCER},
            "producer_receipt": {"path": RECEIPT_REL, **EXPECTED_RECEIPT},
            "review_materializer": file_record(SCRIPT_REL),
            "baseline_safe_record_canonical_sha256": SAFE_BASELINE_RECORD_SHA256,
        },
        "review": {
            "reviewer_lane": "/root/run173_artifact_review",
            "review_materialized_by_root": True,
            "reviewer_executed_php_tests_browser_or_database": False,
            "reviewer_wrote_files": False,
            "checks": {
                "strict_json_zero_duplicate_keys": True,
                "lf_no_bom_no_trailing_whitespace": True,
                "pretty_json_round_trip": True,
                "canonical_self_seal": True,
                "commit_tree_parent_subject_patch_id": True,
                "two_path_order_status_numstat_blob_sha_bytes_lines": True,
                "audit_only_18_path_advance_and_transferred_byte_equality": True,
                "origin_tracking_tip_local_divergence_and_nonpublication": True,
                "baseline_defect_30_minute_contract_and_31_minute_lifecycle": True,
                "runtime_arithmetic_replay_support_and_fixture_exclusions": True,
                "cleanup_boundary": True,
                "credit_and_noninheritance_boundaries": True,
            },
            "discrepancies": [],
        },
        "decision": {
            "verdict": "GO",
            "blocking_discrepancies": 0,
            "retirement_reporting_authorized": True,
            "authorized_finding_id": "SAFE-ALERT-DEDUP-IDENTITY-01",
            "authorized_reporting_status": (
                "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
            ),
            "authorized_live_count_delta": {
                "retained_claim_records": 0,
                "current_provisional_source_claims": -1,
                "historical_already_fixed_records": 0,
                "historical_remediated_records": 1,
                "final_P0": 0,
                "final_P1": 0,
                "benchmark_mapped": 0,
                "final_no_match_or_NCM": 0,
                "benchmark_unresolved": 0,
            },
            "authorized_resulting_lineage": {
                "retained_claim_records": 12,
                "current_provisional_source_claims": 8,
                "historical_already_fixed_records": 2,
                "historical_remediated_records": 2,
                "final_P0": 0,
                "final_P1": 0,
            },
            "authorized_unique_bounded_disposition_increment": {
                "tests": 5,
                "assertions": 60,
                "resulting_tests": 83,
                "resulting_assertions": 1589,
                "isolated_replay_counted_again": False,
                "supporting_or_adjacent_runs_counted": False,
                "red_or_terminal_failures_counted": False,
            },
            "live_reporting_changed_by_run_173r": False,
            "run_174_required": True,
            "run_175_fresh_dashboard_verification_required": True,
        },
        "credit_boundary": {
            "independent_exact_artifact_review_for_retirement_reporting": True,
            "application_remediation_reexecution": False,
            "runtime_reexecution": False,
            "application_publication": False,
            "live_reporting": False,
            "application_browser": False,
            "benchmark_mapping": False,
            "final_no_match_or_NCM": False,
            "final_finding": False,
            "pass": False,
            "release": False,
            "completion": False,
            "audit_complete": False,
        },
        "completion_boundary": completion,
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [f"{PREFIX}/{SCRIPT_REL}", f"{PREFIX}/{OUTPUT_REL}"],
    }
    assert [key for key, value in receipt["credit_boundary"].items() if value] == [
        "independent_exact_artifact_review_for_retirement_reporting"
    ]
    assert all(value is False for value in completion.values())
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    return receipt


def validate_review(receipt: dict[str, Any]) -> None:
    copy = dict(receipt)
    seal = copy.pop("receipt_self_seal_sha256")
    assert seal == canonical_sha256(copy)
    assert receipt["decision"]["verdict"] == "GO"
    assert receipt["decision"]["blocking_discrepancies"] == 0
    assert receipt["decision"]["retirement_reporting_authorized"] is True
    assert receipt["pins"]["application_published"] is False
    assert receipt["credit_boundary"]["application_publication"] is False
    assert receipt["credit_boundary"]["live_reporting"] is False
    assert all(value is False for value in receipt["completion_boundary"].values())


def main() -> None:
    producer = validate_producer()
    validate_live_register_before_reporting()
    receipt = build_receipt(producer)
    validate_review(receipt)
    encoded = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_bytes(encoded)
    assert OUTPUT.read_bytes() == encoded
    reloaded = strict_json(OUTPUT_REL)
    assert reloaded == receipt
    validate_review(reloaded)
    print(json.dumps({
        "run_id": RUN_ID,
        "status": STATUS,
        "materializer_sha256": file_record(SCRIPT_REL)["sha256"],
        "receipt_sha256": sha256(encoded),
        "receipt_self_seal_sha256": receipt["receipt_self_seal_sha256"],
        "verdict": "GO",
        "retirement_reporting_authorized": True,
        "authorized_result": "12 = 8 provisional + 2 already-fixed + 2 remediated",
        "application_published": False,
        "audit_complete": False,
    }, indent=2))


if __name__ == "__main__":
    main()
