#!/usr/bin/env python3
"""Materialize the independent exact-artifact review of RUN162.

This program verifies the already-produced RUN162 materializer and receipt and
writes only the deterministic RUN162R review receipt. It does not rerun tests,
touch a database, start a browser, mutate application source, or change the live
finding register.
"""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24"
PREFIX = AUDIT.relative_to(ROOT).as_posix()

RUN_ID = "RUN-162R-INDEPENDENT-MED-CD-SCOPE-01-REMEDIATION-RECEIPT-REVIEW-WAVE-29"
STATUS = "GO_EXACT_RUN162_ARTIFACT_REVIEW_RETIREMENT_REPORTING_AUTHORIZED_ZERO_DOWNSTREAM_CREDIT"
MATERIALIZER = "generators/materialize-independent-run-162-med-cd-scope-remediation-review-wave-29.py"
OUTPUT = "evidence/runtime/current-run-162r-independent-med-cd-scope-remediation-review-wave-29.json"
PRODUCER_GENERATOR = "generators/materialize-run-162-med-cd-scope-remediation-wave-29.py"
PRODUCER_RECEIPT = "evidence/runtime/current-run-162-med-cd-scope-remediation-wave-29.json"

EXPECTED_GENERATOR = {
    "sha256": "d305638441b8ff366fa5fbc5a00bcc2b81658bf2611a5633ad79fdb4b63f5fb4",
    "git_blob_id": "4798f25dd906fe0a25cf35fbbbd97ea17ba71255",
    "bytes": 19724,
    "lines": 410,
}
EXPECTED_RECEIPT = {
    "sha256": "21564caa435927d89d994a091383409e627c44170304f6ff2a5d5c897c858958",
    "git_blob_id": "d1c0f2d16d8899ad80ffdb5d0261003a760e1147",
    "bytes": 14584,
    "lines": 308,
    "receipt_self_seal_sha256": "273361ee1794f1224a4ba4f952890fa08ab968a48bc7c33ea57a2f41e783c612",
}


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def canonical_sha256(value: Any) -> str:
    return sha256_bytes(json.dumps(
        value, ensure_ascii=False, sort_keys=True, separators=(",", ":"),
    ).encode("utf-8"))


def strict_json(relative: str) -> dict[str, Any]:
    def reject_duplicates(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        value: dict[str, Any] = {}
        for key, item in pairs:
            assert key not in value, (relative, key)
            value[key] = item
        return value

    payload = (AUDIT / relative).read_bytes()
    assert payload.endswith(b"\n") and b"\r\n" not in payload
    assert not payload.startswith(b"\xef\xbb\xbf")
    assert all(line.rstrip(b" \t") == line for line in payload.splitlines())
    value = json.loads(payload.decode("utf-8"), object_pairs_hook=reject_duplicates)
    assert isinstance(value, dict)
    return value


def file_record(relative: str) -> dict[str, Any]:
    payload = (AUDIT / relative).read_bytes()
    assert payload.endswith(b"\n") and b"\r\n" not in payload
    return {
        "path": relative,
        "sha256": sha256_bytes(payload),
        "git_blob_id": hashlib.sha1(
            f"blob {len(payload)}\0".encode("ascii") + payload
        ).hexdigest(),
        "bytes": len(payload),
        "lines": payload.count(b"\n"),
    }


def validate_producer() -> dict[str, Any]:
    generator = file_record(PRODUCER_GENERATOR)
    receipt_record = file_record(PRODUCER_RECEIPT)
    assert {key: generator[key] for key in EXPECTED_GENERATOR} == EXPECTED_GENERATOR
    assert {
        key: receipt_record[key] for key in ("sha256", "git_blob_id", "bytes", "lines")
    } == {key: EXPECTED_RECEIPT[key] for key in ("sha256", "git_blob_id", "bytes", "lines")}

    producer = strict_json(PRODUCER_RECEIPT)
    seal = producer.pop("receipt_self_seal_sha256")
    assert seal == EXPECTED_RECEIPT["receipt_self_seal_sha256"]
    assert seal == canonical_sha256(producer)
    producer["receipt_self_seal_sha256"] = seal
    assert producer["run_id"] == "RUN-162-MED-CD-SCOPE-01-REMEDIATION-WAVE-29"
    assert producer["pins"]["application_commit"] == "0b1920dade9251d617f3cb0b69da5c0202b5a6bf"
    assert producer["pins"]["repository_tree_at_application_commit"] == "7b2b5688c90e4da28725e70e38e50fd445f1b4c4"
    assert producer["issue_first_disposition"]["verdict"] == "REPRODUCED_AND_REMEDIATED_CURRENT_MAIN"
    assert producer["runtime_execution"]["advanced_main_focused_command"]["tests"] == 5
    assert producer["runtime_execution"]["advanced_main_focused_command"]["assertions"] == 48
    assert producer["runtime_execution"]["broader_bounded_execution"]["combined_failed"] == 2
    assert producer["runtime_execution"]["broader_bounded_execution"]["full_suite_or_coverage_credit"] is False
    assert producer["cleanup_evidence"]["matching_schema_count"] == 0
    assert producer["cleanup_evidence"]["owned_php_process_count"] == 0
    assert producer["independent_static_reviews"]["unanimous_verdict"] == "GO"
    assert producer["independent_static_reviews"]["retirement_reporting_authorized"] is False
    assert producer["credit_boundary"]["finding_retirement_reporting"] is False
    assert producer["credit_boundary"]["final_finding"] is False
    assert producer["credit_boundary"]["completion"] is False
    assert all(value is False for value in producer["completion_boundary"].values())
    return producer


def build_receipt(producer: dict[str, Any]) -> dict[str, Any]:
    receipt: dict[str, Any] = {
        "schema_version": "run-162r-independent-med-cd-scope-remediation-review-wave-29-v1",
        "run_id": RUN_ID,
        "status": STATUS,
        "materialized_on": "2026-08-29",
        "pins": {
            "application_commit": producer["pins"]["application_commit"],
            "repository_tree_at_application_commit": producer["pins"]["repository_tree_at_application_commit"],
            "producer_generator": {"path": PRODUCER_GENERATOR, **EXPECTED_GENERATOR},
            "producer_receipt": {"path": PRODUCER_RECEIPT, **EXPECTED_RECEIPT},
            "review_materializer": file_record(MATERIALIZER),
        },
        "review": {
            "reviewer_lane": "/root/run162_scope_source",
            "supporting_seal_lane": "/root/run162_scope_source/run162_artifact_seal",
            "blinded_or_isolated": False,
            "root_authored_review_record": True,
            "reviewer_executed_materializer_tests_browser_or_database": False,
            "reviewer_wrote_files": False,
            "checks": {
                "strict_json_zero_duplicate_keys": True,
                "lf_no_bom_no_trailing_whitespace": True,
                "pretty_json_round_trip": True,
                "canonical_self_seal": True,
                "commit_tree_parent_subject_patch_id": True,
                "audit_only_intervening_paths": True,
                "seven_path_order_numstat_blob_sha_bytes_lines": True,
                "authoritative_remote_main_tip": True,
                "red_command_nonfabrication_disclosure": True,
                "runtime_and_base_replay_arithmetic": True,
                "cleanup_boundary": True,
                "credit_noninheritance": True,
            },
            "discrepancies": [],
        },
        "decision": {
            "verdict": "GO",
            "blocking_discrepancies": 0,
            "retirement_reporting_authorized": True,
            "authorized_reporting_status": "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING",
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
        },
        "credit_boundary": {
            "independent_exact_artifact_review_for_retirement_reporting": True,
            "application_remediation_reexecution": False,
            "runtime_reexecution": False,
            "application_browser": False,
            "benchmark_mapping": False,
            "final_no_match_or_NCM": False,
            "final_finding": False,
            "pass": False,
            "release": False,
            "completion": False,
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
        "wrote_files": [f"{PREFIX}/{MATERIALIZER}", f"{PREFIX}/{OUTPUT}"],
    }
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    return receipt


def validate_review(receipt: dict[str, Any]) -> None:
    copy = dict(receipt)
    seal = copy.pop("receipt_self_seal_sha256")
    assert seal == canonical_sha256(copy)
    assert receipt["decision"]["verdict"] == "GO"
    assert receipt["decision"]["blocking_discrepancies"] == 0
    assert receipt["decision"]["retirement_reporting_authorized"] is True
    assert [key for key, value in receipt["credit_boundary"].items() if value] == [
        "independent_exact_artifact_review_for_retirement_reporting"
    ]
    assert all(value is False for value in receipt["completion_boundary"].values())


def main() -> None:
    producer = validate_producer()
    receipt = build_receipt(producer)
    validate_review(receipt)
    encoded = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    output = AUDIT / OUTPUT
    output.write_bytes(encoded)
    assert output.read_bytes() == encoded
    reloaded = strict_json(OUTPUT)
    assert reloaded == receipt
    validate_review(reloaded)
    print(json.dumps({
        "run_id": RUN_ID,
        "status": STATUS,
        "materializer_sha256": file_record(MATERIALIZER)["sha256"],
        "receipt_sha256": sha256_bytes(encoded),
        "receipt_self_seal_sha256": receipt["receipt_self_seal_sha256"],
        "verdict": "GO",
        "retirement_reporting_authorized": True,
        "audit_complete": False,
    }, indent=2))


if __name__ == "__main__":
    main()
