#!/usr/bin/env python3
"""Assemble the clean RUN-055 Agent C input after fresh Agent B completes."""

from __future__ import annotations

import csv
import hashlib
import json
import subprocess
from pathlib import Path
from typing import Any


AUDIT_DIR = Path(__file__).resolve().parents[1]
REPO_DIR = AUDIT_DIR.parents[2]
EVIDENCE_DIR = AUDIT_DIR / "evidence" / "benchmark"
OUTPUT = EVIDENCE_DIR / "raw-run-055-agent-c-comparison-input-wave-02.json"
GENERATED_AT = "2026-08-25T02:45:00+12:00"

APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
CANONICAL_MATRIX_SHA256 = "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4"
AGENT_A_SHA256 = "835ec0755a5a4b5543f317969e9ba557bc156b137083df925f05d4b753ccde6b"
AGENT_A_PACKET_SET_SHA256 = "066d6b5c095ffdf66579da90f30f186dcb839666c19ff5c894bf1c5c26a3078c"
AGENT_A_CROSSWALK_SHA256 = "78f1e5c77c3dda80048a7b2d6d03859bc22bfe01afba5547ae20ffe797839dfe"
AGENT_B_SHA256 = "7e1e2203dd5af9852f69b1ff5ad05a5d031e4d8d12096ee39055129954f01a68"
AGENT_B_CORRECTION_SHA256 = "1f197b817e5e184efbcf4e7549664008aa3fe331d1b4d4ab5470a3a214364b8b"
CURRENT_SOURCE_SHA256 = "2ed6b9bae1270a4c00b3b427daa077c145cfb370aa26cc4d12e4a3e68acc765a"

INPUTS = {
    "agent_a": "raw-run-053-agent-a-blind-observed-behaviour-packets-wave-02.json",
    "agent_a_crosswalk": "root-run-053-agent-a-source-atom-crosswalk-wave-02.json",
    "agent_b": "raw-run-054-fresh-agent-b-neutral-requirements-wave-02.json",
    "agent_b_correction": "raw-run-054-agent-b-input-boundary-correction-wave-02.json",
    "current_source": "raw-run-049-current-source-facet-refinement-wave-02.json",
}

FACET_JOBS = {
    ("CAP-CLIN-OBSERVATION-REGISTER-RECORD", "create_register"): "Create the initial clinical observation entry in the canonical observation-record workflow.",
    ("CAP-CLIN-OBSERVATION-REGISTER-RECORD", "amendment"): "Amend an existing clinical observation while preserving the meaning of current and earlier records.",
    ("CAP-CLIN-OBSERVATION-REGISTER-RECORD", "care_context_template_hierarchy_filtering"): "Resolve and present clinical observation records in the applicable care context and observed hierarchy.",
    ("CAP-CLIN-EVENT-REGISTER-RECORD", "initial_event_record"): "Record the initial clinical event.",
    ("CAP-INC-INCIDENT-REVIEW-CLOSURE", "incident_review"): "Review an incident under the canonical incident journey rules.",
    ("CAP-INC-INCIDENT-REVIEW-CLOSURE", "incident_closure"): "Close an incident under the canonical incident journey rules.",
    ("CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE", "create"): "Create an employee profile through the canonical identity seam.",
    ("CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE", "view"): "View an employee profile through the canonical identity seam.",
    ("CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE", "update"): "Update an employee profile through the canonical identity seam.",
    ("CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE", "set_active"): "Change an employee profile's active state through the canonical lifecycle seam.",
    ("CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE", "rehire"): "Rehire an employee through the canonical lifecycle seam.",
    ("CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE", "invite"): "Invite an employee through the canonical identity seam.",
    ("CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE", "longitudinal_history"): "Review longitudinal employee-profile lifecycle history.",
    ("CAP-MED-CD-REGISTER-BALANCE", "view"): "View the controlled-medication register and balance.",
    ("CAP-MED-CD-REGISTER-BALANCE", "register_movement"): "Record a controlled-medication register movement.",
    ("CAP-MED-CD-REGISTER-BALANCE", "balance_check"): "Perform a controlled-medication register balance check.",
    ("CAP-MED-CD-REGISTER-BALANCE", "discrepancy_resolution"): "Resolve a controlled-medication register discrepancy.",
    ("CAP-MED-CD-REGISTER-BALANCE", "destruction_projection"): "Project medication destruction into the controlled-medication register and balance.",
    ("CAP-MED-CD-REGISTER-BALANCE", "witness_authority"): "Apply accountable witness authority to controlled-medication register actions.",
    ("CAP-FIN-ALLOCATION-MATCH-HISTORY", "allocation_history_review"): "Review payment-allocation history.",
    ("CAP-FIN-ALLOCATION-MATCH-HISTORY", "suggested_match_review"): "Review a suggested payment match.",
    ("CAP-FIN-ALLOCATION-MATCH-HISTORY", "confirm"): "Confirm a payment match and its allocation effect.",
    ("CAP-FIN-ALLOCATION-MATCH-HISTORY", "reject"): "Reject a suggested payment match.",
    ("CAP-FIN-ALLOCATION-MATCH-HISTORY", "settlement_replay_and_provenance"): "Preserve settlement replay semantics and allocation provenance.",
}


def load_json(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def stable_hash(value: object) -> str:
    payload = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def write_json(path: Path, value: object) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )


assert sha256_file(AUDIT_DIR / "03-feature-to-benchmark-matrix.csv") == CANONICAL_MATRIX_SHA256
assert sha256_file(EVIDENCE_DIR / INPUTS["agent_a"]) == AGENT_A_SHA256
assert sha256_file(EVIDENCE_DIR / INPUTS["agent_a_crosswalk"]) == AGENT_A_CROSSWALK_SHA256
assert sha256_file(EVIDENCE_DIR / INPUTS["agent_b"]) == AGENT_B_SHA256
assert sha256_file(EVIDENCE_DIR / INPUTS["agent_b_correction"]) == AGENT_B_CORRECTION_SHA256
assert sha256_file(EVIDENCE_DIR / INPUTS["current_source"]) == CURRENT_SOURCE_SHA256

resolved_tree = subprocess.check_output(
    ["git", "rev-parse", f"{APPLICATION_COMMIT}^{{tree}}"],
    cwd=REPO_DIR,
    text=True,
).strip()
assert resolved_tree == APPLICATION_TREE

changed_since_pin = subprocess.check_output(
    ["git", "diff", "--name-only", f"{APPLICATION_COMMIT}..HEAD"],
    cwd=REPO_DIR,
    text=True,
).splitlines()
assert not [path for path in changed_since_pin if not path.startswith("docs/")]

agent_a = load_json(EVIDENCE_DIR / INPUTS["agent_a"])
crosswalk = load_json(EVIDENCE_DIR / INPUTS["agent_a_crosswalk"])
agent_b = load_json(EVIDENCE_DIR / INPUTS["agent_b"])
agent_b_correction = load_json(EVIDENCE_DIR / INPUTS["agent_b_correction"])
current_source = load_json(EVIDENCE_DIR / INPUTS["current_source"])

assert len(agent_a["packets"]) == 24
assert len(crosswalk["packets"]) == 24
assert len(agent_b["rows"]) == 24
assert len(current_source["facets"]) == 24
agent_a_rows = {row["blind_packet_id"]: row for row in agent_a["packets"]}
crosswalk_rows = {row["blind_packet_id"]: row for row in crosswalk["packets"]}
agent_b_rows = {row["opaque_id"]: row for row in agent_b["rows"]}
current_rows = {
    (row["feature_id"], row["facet_key"]): row
    for row in current_source["facets"]
}
expected_ids = {f"A53-{index:03d}" for index in range(1, 25)}
assert len(agent_a_rows) == len(crosswalk_rows) == len(agent_b_rows) == len(current_rows) == 24
assert set(agent_a_rows) == set(crosswalk_rows) == set(agent_b_rows) == expected_ids
assert set(current_rows) == set(FACET_JOBS)
crosswalk_pairs = [
    (row["source_feature_id"], row["source_facet_key"])
    for row in crosswalk["packets"]
]
assert len(crosswalk_pairs) == len(set(crosswalk_pairs)) == 24
assert set(crosswalk_pairs) == set(FACET_JOBS)

assert agent_b["parent_agent_a_packet"]["file_sha256"] == AGENT_A_SHA256
assert agent_b["parent_agent_a_packet"]["packet_set_sha256"] == AGENT_A_PACKET_SET_SHA256
assert agent_b["input_packet_set_sha256"]["value"] == AGENT_A_PACKET_SET_SHA256
assert agent_b["validation_summary"]["packet_count"] == 24
assert agent_b["validation_summary"]["derivation_class_counts"] == {
    "BENCHMARK_DERIVED": 8,
    "BOUNDED_ADJACENT_PRINCIPLE": 4,
    "NO_EXACT_BENCHMARK_REQUIREMENT": 12,
}
assert agent_b["validation_summary"]["atom_totals"] == {
    "total": 252,
    "consumed": 165,
    "retained_unknown": 87,
    "excluded": 0,
}
assert agent_b["validation_summary"]["duplicate_opaque_ids"] == []
assert agent_b["validation_summary"]["missing_opaque_ids"] == []
assert agent_b["validation_summary"]["credit_totals"] == {"true": 0, "false": 24}
assert all(row["benchmark_credit"] is False for row in agent_b["rows"])

assert agent_b_correction["correction_id"] == "RUN-054-INPUT-BOUNDARY-CORRECTION"
assert agent_b_correction["signed_by"] == agent_b["responsible_agent_identity"]
assert agent_b_correction["correction_scope"] == "PROVENANCE_WORDING_ONLY"
assert agent_b_correction["corrected_input_boundary"]["members_actually_read"] == ["packets"]
assert agent_b_correction["corrected_input_boundary"]["original_orchestrator_task_contract_used"] is True
assert agent_b_correction["corrected_input_boundary"]["additional_files_or_inputs_accessed"] is False
assert agent_b_correction["existing_payload"]["canonical_sha256"] == agent_b["agent_return_integrity"]["canonical_payload_sha256"]
assert agent_b_correction["existing_payload"]["row_count"] == 24
assert agent_b_correction["existing_payload"]["byte_unchanged"] is True
assert agent_b_correction["existing_payload"]["semantically_unchanged"] is True
assert agent_b_correction["existing_payload"]["neutral_rows_changed"] is False

matrix_rows = {
    row["feature_id"]: row
    for row in csv.DictReader((AUDIT_DIR / "03-feature-to-benchmark-matrix.csv").open(encoding="utf-8", newline=""))
    if row["feature_id"] in {feature_id for feature_id, _ in FACET_JOBS}
}
assert len(matrix_rows) == 6

comparison_packets: list[dict[str, Any]] = []
for opaque_id in sorted(expected_ids):
    binding = crosswalk_rows[opaque_id]
    pair = (binding["source_feature_id"], binding["source_facet_key"])
    neutral = agent_b_rows[opaque_id]
    source = current_rows[pair]
    assert binding["facet_record_sha256"]
    assert source["credit"] is False
    assert source["completion_credit"] is False
    assert neutral["benchmark_credit"] is False
    comparison_packets.append(
        {
            "opaque_id": opaque_id,
            "target_contract": {
                "feature_id": pair[0],
                "facet_key": pair[1],
                "canonical_feature_user_job": matrix_rows[pair[0]]["user_job"],
                "facet_user_job": FACET_JOBS[pair],
                "feature_class": matrix_rows[pair[0]]["feature_class"],
                "origin": "Frozen current canonical feature identity plus the bounded wave-02 facet identity; no upstream project identity is present.",
            },
            "neutral_specification": neutral,
            "current_source_evidence": {
                "evidence_type": "STATIC_SOURCE_ONLY",
                "application_commit": APPLICATION_COMMIT,
                "application_tree": APPLICATION_TREE,
                "packet": source,
                "not_evidenced_by_this_packet": [
                    "executed tests",
                    "framework runtime behavior",
                    "rendered application-browser behavior",
                    "ease of use",
                    "release or production behavior",
                    "benchmark equivalence or selection",
                    "pass or audit completion",
                ],
            },
            "comparison_eligibility": {
                "exact_neutral_requirement": neutral["derivation_class"] == "BENCHMARK_DERIVED",
                "adjacent_only_non_promotable": neutral["derivation_class"] != "BENCHMARK_DERIVED",
                "formal_credit_before_independent_adjudication": False,
            },
        }
    )

assert len(comparison_packets) == 24
assert sum(len(row["current_source_evidence"]["packet"]["anchors"]) for row in comparison_packets) == 155

output = {
    "schema_version": 1,
    "run_id": "RUN-055-INPUT",
    "generated_at": GENERATED_AT,
    "role": "FRESH_AGENT_C_CLEAN_COMPARISON_INPUT",
    "responsible_orchestrator_identity": "/root",
    "status": "TWENTY_FOUR_CLEAN_COMPARISON_PACKETS_READY_ZERO_CREDIT",
    "governing_boundary": {
        "clean_specification_sequence": "Agent A observed behavior -> fresh identity-blind Agent B neutral specification -> fresh Agent C current-source comparison -> independent reattachment and adjudication.",
        "single_tenant_multi_site_rule": "Assess Site access, roles and permissions, canonical ownership, direct-object concealment, and privacy boundaries; do not use tenant-isolation semantics.",
    },
    "input_integrity": {
        "agent_a_file_sha256": AGENT_A_SHA256,
        "agent_a_packet_set_sha256": AGENT_A_PACKET_SET_SHA256,
        "agent_a_crosswalk_sha256_withheld_from_agent_b": AGENT_A_CROSSWALK_SHA256,
        "agent_b_file_sha256": AGENT_B_SHA256,
        "agent_b_input_boundary_correction_sha256": AGENT_B_CORRECTION_SHA256,
        "current_source_file_sha256": CURRENT_SOURCE_SHA256,
        "canonical_matrix_sha256": CANONICAL_MATRIX_SHA256,
        "comparison_packet_set_sha256": stable_hash(comparison_packets),
    },
    "application_pin": {
        "commit": APPLICATION_COMMIT,
        "tree": APPLICATION_TREE,
        "read_mode": "PINNED_STATIC_SOURCE_EVIDENCE_ONLY",
        "non_documentation_changes_from_pin_to_current_head": 0,
        "validated_anchor_occurrences": 155,
        "validated_unique_anchor_strings": 148,
        "validated_source_paths": 84,
    },
    "agent_c_input_boundary": {
        "allowed_input": "This file only.",
        "upstream_identity_withheld": [
            "upstream repository and project identity",
            "upstream URL, path, ref, commit, licence, and edition",
            "upstream implementation identifiers and copied wording",
            "RUN-047 source-identity evidence and the RUN-053 source crosswalk",
            "prior RUN-050 comparisons and RUN-051/RUN-052 verdicts",
        ],
        "prohibited_actions": [
            "repository or external-system mutation",
            "internet, runtime, browser, test, build, or database execution",
            "requirements invention or upstream-identity inference",
            "benchmark selection, mapping, final-no-match, or completion credit",
        ],
    },
    "agent_b_provenance_correction": {
        "signed_by": agent_b_correction["signed_by"],
        "scope": agent_b_correction["correction_scope"],
        "members_actually_read": agent_b_correction["corrected_input_boundary"]["members_actually_read"],
        "attempted_absent_member": agent_b_correction["corrected_input_boundary"]["attempted_absent_member"],
        "original_orchestrator_task_contract_used": True,
        "neutral_payload_changed": False,
    },
    "required_output_contract": {
        "rows": 24,
        "six_lenses": [
            "authorization_scope",
            "state_read_projection",
            "integrity_audit_provenance",
            "replay_concurrency",
            "privacy_direct_object",
            "collision_exclusions",
        ],
        "allowed_evidence_ratings": [
            "EVIDENCED_MET",
            "EVIDENCED_PARTIAL",
            "EVIDENCED_GAP",
            "EVIDENCED_CONTRADICTED",
            "NOT_EVIDENCED",
            "NOT_APPLICABLE",
        ],
        "rules": [
            "Rate only what the pinned static packet supports and cite its anchors.",
            "Compare every neutral specification unit and acceptance outcome.",
            "Keep explicit unknowns unknown; do not convert them into requirements.",
            "Keep the four partial and twelve insufficient packets non-promotable.",
            "Record target or object-boundary drift explicitly.",
            "Grant zero benchmark, mapping, runtime, browser, test, ease, release, pass, and completion credit.",
        ],
    },
    "comparison_packets": comparison_packets,
    "counts": {
        "packets": 24,
        "exact_neutral_packets": 8,
        "partial_adjacent_packets": 4,
        "insufficient_adjacent_packets": 12,
        "current_source_anchor_occurrences": 155,
        "formal_edges": 0,
        "final_no_matches": 0,
    },
    "credit_boundary": {
        "current_product_comparison_credit": False,
        "target_specific_mapping_credit": False,
        "benchmark_credit": False,
        "final_no_match_credit": False,
        "runtime_credit": False,
        "browser_credit": False,
        "test_execution_credit": False,
        "ease_credit": False,
        "release_credit": False,
        "completion_credit": False,
        "audit_complete": False,
    },
    "external_mutations_attestation": "Only the orchestrator assembled this audit input from committed or root-materialized read-only evidence; no application or external system was mutated.",
}

write_json(OUTPUT, output)
