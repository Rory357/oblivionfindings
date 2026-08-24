#!/usr/bin/env python3
"""Integrate the bounded RUN-047 official-upstream facet refinements."""

from __future__ import annotations

import hashlib
import json
from collections import Counter
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
EVIDENCE_DIR = AUDIT_DIR / "evidence" / "benchmark"
GENERATED_AT = "2026-08-25T00:07:00+12:00"
CANONICAL_MATRIX_SHA256 = "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4"
GOVERNING_PROMPT = Path(
    "C:/Users/steph/Downloads/oblivion-open-source-benchmark-and-8-pass-audit-prompt.md"
)
GOVERNING_PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
GOVERNING_PROMPT_BYTES = 88305
PARENT_RUN_046_SHA256 = "648fd95c9291a094a60bf1dfb007e1da9f58eb9b9889ffaad4fa5d542ecbf1f4"

RAW_PARTITIONS = [
    {
        "partition_id": "RUN-047-P1",
        "path": "raw-run-047-upstream-facet-refinement-clinical-incident-wave-02.json",
        "sha256": "517be316ad4ac47bc029799736ce7f648d06ff39b7cbefd360685bf6240a99c8",
        "responsible_agent_identity": "/root/run047_upstream_clinical_incident",
        "facets": 6,
    },
    {
        "partition_id": "RUN-047-P2",
        "path": "raw-run-047-upstream-facet-refinement-composites-wave-02.json",
        "sha256": "978fad489512c4498140bdec02bda59a36b9e0cefcb073316c59a3be6efcac99",
        "responsible_agent_identity": "/root/run047_upstream_composites",
        "facets": 18,
    },
]

EXPECTED_FACETS = {
    "CAP-CLIN-OBSERVATION-REGISTER-RECORD": {
        "create_register",
        "amendment",
        "care_context_template_hierarchy_filtering",
    },
    "CAP-CLIN-EVENT-REGISTER-RECORD": {"initial_event_record"},
    "CAP-INC-INCIDENT-REVIEW-CLOSURE": {"incident_review", "incident_closure"},
    "CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE": {
        "create",
        "view",
        "update",
        "set_active",
        "rehire",
        "invite",
        "longitudinal_history",
    },
    "CAP-MED-CD-REGISTER-BALANCE": {
        "view",
        "register_movement",
        "balance_check",
        "discrepancy_resolution",
        "destruction_projection",
        "witness_authority",
    },
    "CAP-FIN-ALLOCATION-MATCH-HISTORY": {
        "allocation_history_review",
        "suggested_match_review",
        "confirm",
        "reject",
        "settlement_replay_and_provenance",
    },
}

CANDIDATE_STATUSES = {
    "CANDIDATE",
    "EXACT_CANDIDATE_FOR_LATER_CLEAN_COMPARISON_ONLY",
}
BOUNDED_STATUSES = {
    "NO_CANDIDATE_BOUNDED_REJECTION",
    "BOUNDED_NO_CANDIDATE_NOT_FINAL_NO_MATCH",
}


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def sha256_json(value: object) -> str:
    payload = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def load_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def assert_credit_zero(value: object, path: str = "root") -> None:
    if isinstance(value, dict):
        for key, child in value.items():
            child_path = f"{path}.{key}"
            if key.endswith("_credit"):
                assert child in (False, 0), (child_path, child)
            assert_credit_zero(child, child_path)
    elif isinstance(value, list):
        for index, child in enumerate(value):
            assert_credit_zero(child, f"{path}[{index}]")


assert sha256_file(AUDIT_DIR / "03-feature-to-benchmark-matrix.csv") == CANONICAL_MATRIX_SHA256
assert GOVERNING_PROMPT.is_file()
assert GOVERNING_PROMPT.stat().st_size == GOVERNING_PROMPT_BYTES
assert sha256_file(GOVERNING_PROMPT) == GOVERNING_PROMPT_SHA256
assert sha256_file(EVIDENCE_DIR / "current-target-neutral-comparison-wave-01.json") == PARENT_RUN_046_SHA256

partitions: list[tuple[dict, dict]] = []
for expected in RAW_PARTITIONS:
    path = EVIDENCE_DIR / expected["path"]
    assert path.is_file()
    assert sha256_file(path) == expected["sha256"]
    payload = load_json(path)
    assert payload["schema_version"] == 1
    assert payload["run_id"] == expected["partition_id"]
    assert payload["responsible_agent_identity"] == expected["responsible_agent_identity"]
    assert len(payload["facets"]) == expected["facets"]
    assert_credit_zero(payload)
    partitions.append((expected, payload))

p1 = partitions[0][1]
p2 = partitions[1][1]
assert p1["input_boundary"]["application_source_inspected"] is False
assert p1["input_boundary"]["current_product_evidence_inspected"] is False
assert p1["input_boundary"]["prohibited_runs_inspected"] is False
assert "No Oblivion application source" in p2["input_boundary"]["prohibited_internal_inputs_attestation"]

expected_pairs = {
    (feature_id, facet_key)
    for feature_id, facet_keys in EXPECTED_FACETS.items()
    for facet_key in facet_keys
}
facets: list[dict] = []
observed_pairs: set[tuple[str, str]] = set()
for expected, payload in partitions:
    for raw_facet in payload["facets"]:
        pair = (raw_facet["feature_id"], raw_facet["facet_key"])
        assert pair in expected_pairs, pair
        assert pair not in observed_pairs, pair
        assert raw_facet["candidate_status"] in CANDIDATE_STATUSES | BOUNDED_STATUSES
        assert raw_facet["anchors"]
        assert raw_facet["observed_behaviors"]
        assert raw_facet["gaps"]
        observed_pairs.add(pair)
        facet = dict(raw_facet)
        facet["source_partition"] = expected["partition_id"]
        facet["facet_record_sha256"] = sha256_json(raw_facet)
        facets.append(facet)

assert observed_pairs == expected_pairs
facets.sort(key=lambda row: (row["feature_id"], row["facet_key"]))
status_counts = Counter(row["candidate_status"] for row in facets)
candidate_count = sum(status_counts[status] for status in CANDIDATE_STATUSES)
bounded_count = sum(status_counts[status] for status in BOUNDED_STATUSES)
assert candidate_count == 12
assert bounded_count == 12

by_feature = {
    feature_id: {
        "facets": len(EXPECTED_FACETS[feature_id]),
        "candidate_locators": sum(
            1
            for row in facets
            if row["feature_id"] == feature_id and row["candidate_status"] in CANDIDATE_STATUSES
        ),
        "bounded_no_candidate_not_final_no_match": sum(
            1
            for row in facets
            if row["feature_id"] == feature_id and row["candidate_status"] in BOUNDED_STATUSES
        ),
    }
    for feature_id in sorted(EXPECTED_FACETS)
}

integrated = {
    "schema_version": 1,
    "run_id": "RUN-047",
    "generated_at": GENERATED_AT,
    "role": "ROOT_UPSTREAM_FACET_REFINEMENT_INTEGRATOR",
    "responsible_agent_identity": "/root",
    "status": "TWENTY_FOUR_OFFICIAL_UPSTREAM_FACET_PACKETS_INTEGRATED_NEUTRAL_AND_COMPARISON_PENDING_CREDIT_ZERO",
    "governing_pins": {
        "prompt_path": str(GOVERNING_PROMPT),
        "prompt_sha256": GOVERNING_PROMPT_SHA256,
        "prompt_bytes": GOVERNING_PROMPT_BYTES,
        "canonical_matrix_sha256": CANONICAL_MATRIX_SHA256,
        "parent_run_046_sha256": PARENT_RUN_046_SHA256,
    },
    "inputs": {"raw_partitions": RAW_PARTITIONS},
    "stage_boundary": {
        "official_upstream_observation": "COMPLETE_FOR_24_BOUNDED_FACETS",
        "blind_neutral_requirements": "PENDING_SEPARATE_AGENT_B_STAGE",
        "current_product_facet_packets": "PENDING_SEPARATE_CURRENT_SOURCE_STAGE",
        "clean_current_neutral_comparison": "PENDING",
        "independent_adjudication": "PENDING",
        "formal_edges": 0,
    },
    "facets": facets,
    "counts": {
        "feature_ids": len(EXPECTED_FACETS),
        "facets": len(facets),
        "candidate_locators_for_later_clean_comparison": candidate_count,
        "bounded_no_candidate_not_final_no_match": bounded_count,
        "by_feature": by_feature,
        "neutral_requirements": 0,
        "current_product_comparisons": 0,
        "independent_adjudications": 0,
        "formal_edges": 0,
        "promoted_feature_mappings_or_final_no_matches": 0,
    },
    "credit_boundary": {
        "neutral_requirement_credit": False,
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
    "canonical_matrix_disposition": {
        "status": "UNCHANGED_GUARDED_UPSTREAM_OVERLAY_ONLY",
        "sha256": CANONICAL_MATRIX_SHA256,
        "promoted_feature_mappings_or_final_no_matches": 0,
    },
    "external_mutations_attestation": "NONE_READ_ONLY_OFFICIAL_SOURCE_REVIEW_AND_LOCAL_AUDIT_INTEGRATION",
}
assert_credit_zero(integrated)

agent_register = {
    "schema_version": 1,
    "run_id": "RUN-047",
    "generated_at": GENERATED_AT,
    "status": "THREE_RESPONSIBLE_RECORDS_COMPLETE_STAGE_BOUNDARY_PRESERVED_CREDIT_ZERO",
    "inputs": {"raw_partitions": RAW_PARTITIONS},
    "agents": [
        {
            "responsible_agent_identity": "/root/run047_upstream_clinical_incident",
            "role": "OFFICIAL_UPSTREAM_OBSERVER_AGENT_A_PARTITION_1",
            "scope": "Clinical observation, clinical event, and incident refinement; 6 facets.",
            "input_boundary": "Governing prompt, frozen matrix rows, and RUN-039 upstream-only evidence; current product withheld.",
            "completion_test": "Six unique exact feature/facet packets with immutable official-source anchors and zero credit.",
            "unresolved_gaps": "Neutralization, current-source comparison, independent adjudication, and every downstream credit remain pending.",
        },
        {
            "responsible_agent_identity": "/root/run047_upstream_composites",
            "role": "OFFICIAL_UPSTREAM_OBSERVER_AGENT_A_PARTITION_2",
            "scope": "HR, controlled-drug, and finance refinement; 18 facets.",
            "input_boundary": "Governing prompt, frozen matrix rows, and RUN-039 upstream-only evidence; current product withheld.",
            "completion_test": "Eighteen unique exact feature/facet packets with immutable official-source anchors and zero credit.",
            "unresolved_gaps": "Neutralization, current-source comparison, independent adjudication, and every downstream credit remain pending.",
        },
        {
            "responsible_agent_identity": "/root",
            "role": "RUN-047_DETERMINISTIC_INTEGRATOR",
            "scope": "Hash-bind, validate, merge, and count the two disjoint RUN-047 partitions without changing the canonical matrix.",
            "input_boundary": "Raw RUN-047 partitions plus immutable prompt, matrix, and parent RUN-046 pins.",
            "completion_test": "24/24 expected pairs, 12 candidate locators, 12 bounded no-candidates, zero formal edges, and zero credit.",
            "unresolved_gaps": "Agent B neutral requirements and later clean comparison/adjudication are not represented by RUN-047.",
        },
    ],
    "validation": {
        "expected_feature_facet_pairs": len(expected_pairs),
        "observed_feature_facet_pairs": len(observed_pairs),
        "duplicate_pairs": 0,
        "candidate_locators": candidate_count,
        "bounded_no_candidates": bounded_count,
        "stage_conflicts": 0,
        "all_downstream_credits_false": True,
    },
    "credit_boundary": integrated["credit_boundary"],
    "external_mutations_attestation": "NONE_READ_ONLY_OFFICIAL_SOURCE_REVIEW_AND_LOCAL_AUDIT_INTEGRATION",
}
assert_credit_zero(agent_register)

write_json(EVIDENCE_DIR / "current-upstream-facet-refinement-wave-02.json", integrated)
write_json(EVIDENCE_DIR / "current-upstream-facet-refinement-agent-register.json", agent_register)
