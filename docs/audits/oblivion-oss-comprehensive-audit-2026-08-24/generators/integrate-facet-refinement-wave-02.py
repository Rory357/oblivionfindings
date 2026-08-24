#!/usr/bin/env python3
"""Integrate RUN-047 through RUN-051 as the bounded 24-facet wave.

This generator is intentionally conservative. It validates the complete static
evidence chain and its pinned source anchors, but records a lineage NO-GO because
RUN-048 was authored as source-independent normative requirements rather than as
Agent B neutralization of identity-stripped RUN-047 observations. Consequently
RUN-052 promotes no formal edge and leaves the frozen 0/340 matrix unchanged.
"""

from __future__ import annotations

import csv
import hashlib
import json
import re
import subprocess
from collections import Counter
from pathlib import Path
from typing import Any


AUDIT_DIR = Path(__file__).resolve().parents[1]
REPO_DIR = AUDIT_DIR.parents[2]
EVIDENCE_DIR = AUDIT_DIR / "evidence" / "benchmark"
GENERATED_AT = "2026-08-25T01:45:00+12:00"

APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
CANONICAL_IDENTITY_SHA256 = "f4feae2598622afe346b1163fed2bb842305a8d973a89ec890c02746d99b5999"
CANONICAL_MATRIX_SHA256 = "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4"
PARENT_RUN_046_SHA256 = "648fd95c9291a094a60bf1dfb007e1da9f58eb9b9889ffaad4fa5d542ecbf1f4"
GOVERNING_PROMPT = Path(
    "C:/Users/steph/Downloads/oblivion-open-source-benchmark-and-8-pass-audit-prompt.md"
)
GOVERNING_PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
GOVERNING_PROMPT_BYTES = 88305

LINEAGE_NO_GO = "NO_GO_AGENT_A_TO_B_SANITIZED_BEHAVIOUR_PACKET_MISSING"

INPUTS = [
    {
        "run_id": "RUN-047-P1",
        "path": "raw-run-047-upstream-facet-refinement-clinical-incident-wave-02.json",
        "sha256": "517be316ad4ac47bc029799736ce7f648d06ff39b7cbefd360685bf6240a99c8",
    },
    {
        "run_id": "RUN-047-P2",
        "path": "raw-run-047-upstream-facet-refinement-composites-wave-02.json",
        "sha256": "978fad489512c4498140bdec02bda59a36b9e0cefcb073316c59a3be6efcac99",
    },
    {
        "run_id": "RUN-047",
        "path": "current-upstream-facet-refinement-wave-02.json",
        "sha256": "d41cc046de9b7580e937ca1b9d1df7f9237947dcaed5bf0f95772b667d8d9f3e",
    },
    {
        "run_id": "RUN-047-REGISTER",
        "path": "current-upstream-facet-refinement-agent-register.json",
        "sha256": "bc8265ee896a04335b9c36a077f09bb7c0d7d8e5892200542601f682f05e076b",
    },
    {
        "run_id": "RUN-048",
        "path": "raw-run-048-blind-neutral-facet-requirements-wave-02.json",
        "sha256": "fa9b793ad851d90a1871b0ce7de76906026144fce3e54ff8f502352f72584bd6",
    },
    {
        "run_id": "RUN-049",
        "path": "raw-run-049-current-source-facet-refinement-wave-02.json",
        "sha256": "2ed6b9bae1270a4c00b3b427daa077c145cfb370aa26cc4d12e4a3e68acc765a",
    },
    {
        "run_id": "RUN-050-P1",
        "path": "raw-run-050-clean-facet-comparison-clinical-incident-hr-wave-02.json",
        "sha256": "49dc874e7b66fa40701e96e8fd08dc9bb20c6bf7b72850220068591f69e03586",
    },
    {
        "run_id": "RUN-050-P2",
        "path": "raw-run-050-clean-facet-comparison-medication-finance-wave-02.json",
        "sha256": "82e89513aa387301dccede26b833dc472cc30c8950bf410b0d285b8caece6b73",
    },
    {
        "run_id": "RUN-050-X",
        "path": "raw-run-050-clean-facet-comparison-full-crosscheck-wave-02.json",
        "sha256": "64a5777dfd17345969a22ad63d03348089dd699147e3448e750425f795258b4f",
    },
    {
        "run_id": "RUN-050-T",
        "path": "raw-run-050-witness-authority-tiebreak-wave-02.json",
        "sha256": "748e9dafa9177b8eb71425db7cf2701174a93edb28e0e283344a07be69b3d747",
    },
    {
        "run_id": "RUN-050",
        "path": "raw-run-050-clean-facet-comparison-reconciled-wave-02.json",
        "sha256": "fa5248dd88b3a43334e9f181bba2f8459274c2c404d984189c39a54d74988159",
    },
    {
        "run_id": "RUN-051",
        "path": "raw-run-051-independent-facet-adjudication-wave-02.json",
        "sha256": "983ef988f61523d7b76e7e6c91141cd1321fb61082990e9b54481ea5a2080acb",
    },
]

EXPECTED_FACETS = {
    "CAP-CLIN-OBSERVATION-REGISTER-RECORD": [
        "create_register",
        "amendment",
        "care_context_template_hierarchy_filtering",
    ],
    "CAP-CLIN-EVENT-REGISTER-RECORD": ["initial_event_record"],
    "CAP-INC-INCIDENT-REVIEW-CLOSURE": ["incident_review", "incident_closure"],
    "CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE": [
        "create",
        "view",
        "update",
        "set_active",
        "rehire",
        "invite",
        "longitudinal_history",
    ],
    "CAP-MED-CD-REGISTER-BALANCE": [
        "view",
        "register_movement",
        "balance_check",
        "discrepancy_resolution",
        "destruction_projection",
        "witness_authority",
    ],
    "CAP-FIN-ALLOCATION-MATCH-HISTORY": [
        "allocation_history_review",
        "suggested_match_review",
        "confirm",
        "reject",
        "settlement_replay_and_provenance",
    ],
}

LENSES = [
    "authorization_scope",
    "state_read_projection",
    "integrity_audit_provenance",
    "replay_concurrency",
    "privacy_direct_object",
    "collision_exclusions",
]
ALLOWED_RATINGS = {"MET", "STRONGER", "GAP", "CONTRADICTED", "NOT_APPLICABLE"}
EXPECTED_PAIRS = {
    (feature_id, facet_key)
    for feature_id, facet_keys in EXPECTED_FACETS.items()
    for facet_key in facet_keys
}


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def stable_hash(value: object) -> str:
    payload = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def load_json(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def assert_credit_zero(value: object, path: str = "root") -> None:
    if isinstance(value, dict):
        for key, child in value.items():
            child_path = f"{path}.{key}"
            if (
                key.endswith("_credit")
                or key in {"credit", "all_credits", "downstream_credit", "audit_complete"}
            ):
                assert child in (False, 0, "false"), (child_path, child)
            if key in {"credited_facets", "formal_edges", "formal_edge_count"}:
                assert child == 0, (child_path, child)
            assert_credit_zero(child, child_path)
    elif isinstance(value, list):
        for index, child in enumerate(value):
            assert_credit_zero(child, f"{path}[{index}]")


def indexed_facets(rows: list[dict[str, Any]]) -> dict[tuple[str, str], dict[str, Any]]:
    indexed = {(row["feature_id"], row["facet_key"]): row for row in rows}
    assert len(indexed) == len(rows)
    return indexed


def p2_facets(payload: dict[str, Any]) -> list[dict[str, Any]]:
    return [
        {"feature_id": feature["feature_id"], **facet}
        for feature in payload["features"]
        for facet in feature["facets"]
    ]


def pinned_file_line_count(relative_path: str, cache: dict[str, int]) -> int:
    if relative_path not in cache:
        raw = subprocess.check_output(
            ["git", "show", f"{APPLICATION_COMMIT}:{relative_path}"],
            cwd=REPO_DIR,
        )
        cache[relative_path] = len(raw.decode("utf-8").splitlines())
    return cache[relative_path]


def validate_source_anchor(anchor: str, cache: dict[str, int]) -> None:
    match = re.fullmatch(r"(.+?):(\d+(?:-\d+)?(?:,\d+(?:-\d+)?)*)", anchor)
    assert match, anchor
    relative_path, line_spec = match.groups()
    line_numbers = [int(value) for value in re.findall(r"\d+", line_spec)]
    assert min(line_numbers) >= 1
    assert max(line_numbers) <= pinned_file_line_count(relative_path, cache), anchor


assert len(EXPECTED_FACETS) == 6
assert len(EXPECTED_PAIRS) == 24
assert sha256_file(AUDIT_DIR / "03-feature-to-benchmark-matrix.csv") == CANONICAL_MATRIX_SHA256
assert sha256_file(
    AUDIT_DIR / "evidence" / "source" / "current-canonical-feature-identity-wave-01.json"
) == CANONICAL_IDENTITY_SHA256
assert sha256_file(EVIDENCE_DIR / "current-target-neutral-comparison-wave-01.json") == PARENT_RUN_046_SHA256
assert GOVERNING_PROMPT.is_file()
assert GOVERNING_PROMPT.stat().st_size == GOVERNING_PROMPT_BYTES
assert sha256_file(GOVERNING_PROMPT) == GOVERNING_PROMPT_SHA256
resolved_tree = subprocess.check_output(
    ["git", "rev-parse", f"{APPLICATION_COMMIT}^{{tree}}"],
    cwd=REPO_DIR,
    text=True,
).strip()
assert resolved_tree == APPLICATION_TREE

payloads: dict[str, dict[str, Any]] = {}
for contract in INPUTS:
    path = EVIDENCE_DIR / contract["path"]
    assert path.is_file(), path
    assert sha256_file(path) == contract["sha256"], contract["run_id"]
    payload = load_json(path)
    assert payload["schema_version"] == 1
    if contract["run_id"] == "RUN-047-REGISTER":
        assert payload["run_id"] == "RUN-047"
    else:
        assert payload["run_id"] == contract["run_id"]
    assert_credit_zero(payload, contract["run_id"])
    payloads[contract["run_id"]] = payload

run47 = payloads["RUN-047"]
run47_register = payloads["RUN-047-REGISTER"]
run48 = payloads["RUN-048"]
run49 = payloads["RUN-049"]
run50_p1 = payloads["RUN-050-P1"]
run50_p2 = payloads["RUN-050-P2"]
run50_x = payloads["RUN-050-X"]
run50_t = payloads["RUN-050-T"]
run50 = payloads["RUN-050"]
run51 = payloads["RUN-051"]

assert run47["governing_pins"] == {
    "prompt_path": str(GOVERNING_PROMPT),
    "prompt_sha256": GOVERNING_PROMPT_SHA256,
    "prompt_bytes": GOVERNING_PROMPT_BYTES,
    "canonical_matrix_sha256": CANONICAL_MATRIX_SHA256,
    "parent_run_046_sha256": PARENT_RUN_046_SHA256,
}
assert run47["counts"]["feature_ids"] == 6
assert run47["counts"]["facets"] == 24
assert run47["counts"]["candidate_locators_for_later_clean_comparison"] == 12
assert run47["counts"]["bounded_no_candidate_not_final_no_match"] == 12
assert run47_register["validation"] == {
    "expected_feature_facet_pairs": 24,
    "observed_feature_facet_pairs": 24,
    "duplicate_pairs": 0,
    "candidate_locators": 12,
    "bounded_no_candidates": 12,
    "stage_conflicts": 0,
    "all_downstream_credits_false": True,
}

upstream = indexed_facets(run47["facets"])
neutral = indexed_facets(run48["facets"])
current = indexed_facets(run49["facets"])
comparison = indexed_facets(run50["facets"])
crosscheck = indexed_facets(run50_x["facets"])
partition_1 = indexed_facets(run50_p1["facets"])
partition_2 = indexed_facets(p2_facets(run50_p2))
assert set(upstream) == set(neutral) == set(current) == set(comparison) == set(crosscheck) == EXPECTED_PAIRS
assert set(partition_1).isdisjoint(partition_2)
assert set(partition_1) | set(partition_2) == EXPECTED_PAIRS
assert len(partition_1) == 13
assert len(partition_2) == 11

assert run48["input_boundary"]["mode"] == "STRICT_BLIND_SOURCE_INDEPENDENT"
assert run48["input_boundary"]["upstream_evidence_access"] is False
assert run48["input_boundary"]["audit_evidence_access"] is False
assert run48["counts"] == {"features": 6, "facets": 24}
assert run48["architecture"]["organization_model"] == "One operating organization across multiple Sites."

assert run49["input_boundary"]["application_commit"] == APPLICATION_COMMIT
assert run49["input_boundary"]["application_tree"] == APPLICATION_TREE
assert run49["input_boundary"]["frozen_matrix"]["sha256"] == CANONICAL_MATRIX_SHA256
assert run49["source_anchor_validation"]["all_anchors_exist_and_ranges_are_in_bounds"] is True
assert run49["counts"]["facet_count"] == 24
assert run49["counts"]["source_anchor_occurrences"] == 155
anchor_occurrences = [anchor for row in run49["facets"] for anchor in row["anchors"]]
assert len(anchor_occurrences) == 155
anchor_cache: dict[str, int] = {}
for source_anchor in anchor_occurrences:
    validate_source_anchor(source_anchor, anchor_cache)
assert len(anchor_cache) == 84

assert run50["validation"]["all_valid"] is True
assert run50["validation"]["pair_integrity"] == {
    "run_048_pairs": 24,
    "run_049_pairs": 24,
    "p1_pairs": 13,
    "p2_pairs": 11,
    "partition_overlap": 0,
    "partition_union_pairs": 24,
    "full_pairs": 24,
    "exact_matched_pairs": 24,
    "missing_pairs": 0,
    "extra_pairs": 0,
}
assert run50["validation"]["disagreement_integrity"] == {
    "rating_disagreements": 29,
    "facet_verdict_disagreements": 3,
    "post_reconciliation_tiebreak_changes": 2,
}
assert run50["counts"]["features"] == 6
assert run50["counts"]["facets"] == 24
assert run50["counts"]["dimension_ratings"] == 144
assert run50["counts"]["dimension_rating_counts"] == {
    "MET": 20,
    "STRONGER": 0,
    "GAP": 60,
    "CONTRADICTED": 64,
    "NOT_APPLICABLE": 0,
}
assert run50["counts"]["facet_verdict_counts"] == {
    "MET": 0,
    "STRONGER": 0,
    "GAP": 5,
    "CONTRADICTED": 19,
    "NOT_APPLICABLE": 0,
}
assert run50["counts"]["feature_verdict_counts"] == {
    "MET": 0,
    "STRONGER": 0,
    "GAP": 0,
    "CONTRADICTED": 6,
    "NOT_APPLICABLE": 0,
}
ratings = [
    rating
    for row in run50["facets"]
    for rating in row["ratings"].values()
]
assert len(ratings) == 144
assert all(list(row["ratings"]) == LENSES for row in run50["facets"])
assert set(ratings).issubset(ALLOWED_RATINGS)
assert Counter(ratings) == Counter(run50["counts"]["dimension_rating_counts"])
assert run50_t["pair"] == {
    "feature_id": "CAP-MED-CD-REGISTER-BALANCE",
    "facet_key": "witness_authority",
    "pair_key": "CAP-MED-CD-REGISTER-BALANCE|witness_authority",
}

# RUN-051 is the independent gate. Its schema is deliberately asserted rather
# than inferred: any adjudicator rewrite must be consciously re-integrated.
assert run51["role"] == "INDEPENDENT_POST_RECONCILIATION_FACET_ADJUDICATOR_AGENT_D"
assert run51["status"] == "NO_GO_PROMPT_LINEAGE_FAILURE_ZERO_EDGES_ZERO_CREDIT"
assert run51["governing_inputs"]["prompt"]["sha256"] == GOVERNING_PROMPT_SHA256
assert run51["governing_inputs"]["prompt"]["bytes"] == GOVERNING_PROMPT_BYTES
assert run51["governing_inputs"]["frozen_matrix"]["sha256"] == CANONICAL_MATRIX_SHA256
assert run51["governing_inputs"]["parent_run_046"]["sha256"] == PARENT_RUN_046_SHA256
assert run51["governing_inputs"]["application_git_object"] == {
    "commit": APPLICATION_COMMIT,
    "tree": APPLICATION_TREE,
    "read_mode": "PINNED_GIT_OBJECT_ONLY",
}
embedded_run51_hashes = {
    "run_047_raw_clinical_incident": INPUTS[0]["sha256"],
    "run_047_raw_composites": INPUTS[1]["sha256"],
    "run_047_integrated": INPUTS[2]["sha256"],
    "run_047_agent_register": INPUTS[3]["sha256"],
    "run_048": INPUTS[4]["sha256"],
    "run_049": INPUTS[5]["sha256"],
    "run_050_partition_1": INPUTS[6]["sha256"],
    "run_050_partition_2": INPUTS[7]["sha256"],
    "run_050_full_crosscheck": INPUTS[8]["sha256"],
    "run_050_tiebreak": INPUTS[9]["sha256"],
    "run_050_reconciled": INPUTS[10]["sha256"],
}
assert {
    key: run51["governing_inputs"][key]["sha256"]
    for key in embedded_run51_hashes
} == embedded_run51_hashes
assert run51["validation"]["all_named_file_hashes_match"] is True
lineage_validation = run51["validation"]["identity_blind_stage_boundary"]
assert lineage_validation["agent_a_current_product_withheld"] is True
assert lineage_validation["agent_b_upstream_identity_withheld"] is True
assert lineage_validation["agent_c_upstream_identity_withheld"] is True
assert lineage_validation["agent_c_inputs_limited_to_run_048_and_run_049"] is True
assert lineage_validation[
    "prompt_required_agent_a_to_agent_b_sanitized_behaviour_derivation"
] is False
assert "unlinked specification" in lineage_validation["determinative_failure"]
assert run51["validation"]["run_049_source_anchors"] == {
    "occurrences": 155,
    "unique_anchor_strings": 148,
    "unique_git_object_paths": 84,
    "malformed": 0,
    "missing_paths": 0,
    "out_of_bounds_ranges": 0,
    "commit_type": "commit",
    "resolved_tree_matches": True,
}
comparison_validation = run51["validation"]["run_050_pair_and_arithmetic"]
assert comparison_validation["run_048_pairs"] == 24
assert comparison_validation["run_049_pairs"] == 24
assert comparison_validation["partition_1_pairs"] == 13
assert comparison_validation["partition_2_pairs"] == 11
assert comparison_validation["partition_overlap"] == 0
assert comparison_validation["full_crosscheck_pairs"] == 24
assert comparison_validation["reconciled_pairs"] == 24
assert comparison_validation["dimension_ratings"] == 144
assert comparison_validation["rating_disagreements_partition_vs_full"] == 29
assert comparison_validation["facet_verdict_disagreements_partition_vs_full"] == 3
assert comparison_validation["disagreement_resolutions_complete"] is True
assert comparison_validation["post_reconciliation_tiebreak_changes"] == 2
assert comparison_validation["arithmetic_valid"] is True
assert run51["rating_dimension_order"] == LENSES
assert run51["rating_review"]["accounting"] == {
    "total_reconciled_cells": 144,
    "accepted_as_stated": 126,
    "corrected": 12,
    "invalid_due_to_neutral_target_drift": 6,
    "accounted_cells": 144,
}
assert run51["rating_review"]["independent_valid_cell_counts_after_corrections"] == {
    "cells": 138,
    "MET": 19,
    "STRONGER": 0,
    "GAP": 66,
    "CONTRADICTED": 53,
    "NOT_APPLICABLE": 0,
}
assert run51["rating_review"]["independent_facet_verdict_counts"] == {
    "GAP": 8,
    "CONTRADICTED": 15,
    "INVALID_NEUTRAL_TARGET": 1,
}
assert run51["formal_edge_count"] == 0
assert run51["final_no_match_count"] == 0
adjudication = indexed_facets(run51["facets"])
assert set(adjudication) == EXPECTED_PAIRS
assert all(row["formal_disposition"] == "NO_GO" for row in adjudication.values())
assert all(len(row["reconciled"]) == 6 for row in adjudication.values())
assert sum(row["independent"] == "ACCEPT_VECTOR" for row in adjudication.values()) == 15
assert sum(row["independent"] == "INVALID_NEUTRAL_TARGET" for row in adjudication.values()) == 1
assert sum(isinstance(row["independent"], list) for row in adjudication.values()) == 8
assert len(run51["feature_verdicts"]) == 6
assert {row["feature_id"] for row in run51["feature_verdicts"]} == set(EXPECTED_FACETS)
assert all(row["independent_formal_verdict"] == "NO_GO" for row in run51["feature_verdicts"])
assert all(row["formal_edges"] == 0 for row in run51["feature_verdicts"])

with (AUDIT_DIR / "03-feature-to-benchmark-matrix.csv").open(
    encoding="utf-8", newline=""
) as handle:
    matrix_rows = list(csv.DictReader(handle))
matrix_by_id = {row["feature_id"]: row for row in matrix_rows}
assert len(matrix_rows) == len(matrix_by_id) == 340
assert all(row["benchmark_mapping_credit"] == "false" for row in matrix_rows)
assert all(matrix_by_id[feature_id]["benchmark_mapping_credit"] == "false" for feature_id in EXPECTED_FACETS)

records: list[dict[str, Any]] = []
for feature_id in EXPECTED_FACETS:
    for facet_key in EXPECTED_FACETS[feature_id]:
        pair = (feature_id, facet_key)
        record = {
            "feature_id": feature_id,
            "facet_key": facet_key,
            "upstream_observation": upstream[pair],
            "blind_source_independent_requirement": neutral[pair],
            "current_source_packet": current[pair],
            "clean_current_comparison": comparison[pair],
            "independent_adjudication": adjudication[pair],
            "lineage_status": LINEAGE_NO_GO,
            "formal_edge_count": 0,
            "target_specific_mapping_credit": False,
            "benchmark_credit": False,
            "final_no_match_credit": False,
            "runtime_credit": False,
            "browser_credit": False,
            "test_execution_credit": False,
            "ease_credit": False,
            "release_credit": False,
            "completion_credit": False,
        }
        record["integrated_record_sha256"] = stable_hash(record)
        records.append(record)

credit_boundary = {
    "upstream_observation_packets_materialized": 24,
    "source_independent_requirement_packets_materialized": 24,
    "current_source_packets_materialized": 24,
    "clean_comparison_ratings_materialized": 144,
    "independent_adjudications_materialized": 24,
    "target_specific_mapping_credit": 0,
    "benchmark_credit": 0,
    "final_no_match_credit": 0,
    "runtime_credit": 0,
    "browser_credit": 0,
    "test_execution_credit": 0,
    "ease_credit": 0,
    "release_credit": 0,
    "completion_credit": 0,
    "audit_complete": False,
}

output = {
    "schema_version": 1,
    "run_id": "RUN-052",
    "generated_at": GENERATED_AT,
    "role": "ROOT_FACET_WAVE_DETERMINISTIC_INTEGRATOR",
    "responsible_agent_identity": "/root/run052_integration",
    "status": "TWENTY_FOUR_FACET_WAVE_INTEGRATED_LINEAGE_NO_GO_ZERO_FORMAL_EDGES",
    "governing_pins": {
        "prompt_path": str(GOVERNING_PROMPT),
        "prompt_sha256": GOVERNING_PROMPT_SHA256,
        "prompt_bytes": GOVERNING_PROMPT_BYTES,
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
        "canonical_identity_sha256": CANONICAL_IDENTITY_SHA256,
        "canonical_matrix_sha256": CANONICAL_MATRIX_SHA256,
        "parent_run_046_sha256": PARENT_RUN_046_SHA256,
    },
    "inputs": INPUTS,
    "stage_lineage": {
        "status": LINEAGE_NO_GO,
        "agent_a_official_upstream_observation_complete": True,
        "agent_b_source_independent_normative_requirements_complete": True,
        "agent_a_sanitized_behaviour_forwarded_to_agent_b": False,
        "agent_b_neutralization_derived_from_agent_a_sanitized_behaviour": False,
        "current_source_withheld_from_agent_b": True,
        "clean_current_comparison_mechanically_complete": True,
        "independent_adjudication_complete": True,
        "formal_edge_eligibility": False,
        "next_action": "Create identity-stripped sanitized RUN-047 behavior packets, provide only those packets to a fresh Agent B, then rerun clean comparison and independent adjudication before reconsidering any formal edge.",
    },
    "counts": {
        "canonical_targets": 340,
        "wave_features": 6,
        "wave_facets": 24,
        "candidate_locators": 12,
        "bounded_no_candidates_not_final_no_match": 12,
        "source_anchor_occurrences_validated": 155,
        "source_anchor_files_validated": 84,
        "comparison_dimension_ratings": 144,
        "comparison_dimension_rating_counts": run50["counts"]["dimension_rating_counts"],
        "comparison_facet_verdict_counts": run50["counts"]["facet_verdict_counts"],
        "comparison_feature_verdict_counts": run50["counts"]["feature_verdict_counts"],
        "independent_no_go_verdicts": 6,
        "formal_edges": 0,
        "promoted_feature_mappings_or_final_no_matches": 0,
    },
    "records": records,
    "canonical_matrix_disposition": {
        "status": "UNCHANGED_LINEAGE_NO_GO_OVERLAY_ONLY",
        "sha256": CANONICAL_MATRIX_SHA256,
        "credited_feature_rows": 0,
        "total_feature_rows": 340,
        "promoted_feature_mappings_or_final_no_matches": 0,
        "reason": "RUN-051 found the Agent A to Agent B sanitized-observation derivation missing; mechanically valid facet evidence cannot be promoted across that lineage break.",
    },
    "credit_boundary": credit_boundary,
    "external_mutations_attestation": "NONE_STATIC_AUDIT_EVIDENCE_INTEGRATION_ONLY",
}
assert_credit_zero(output)

agent_register = {
    "schema_version": 1,
    "run_id": "RUN-052",
    "generated_at": GENERATED_AT,
    "status": "FIVE_STAGE_FACET_REGISTER_COMPLETE_LINEAGE_NO_GO_ZERO_CREDIT",
    "governing_pins": output["governing_pins"],
    "inputs": INPUTS,
    "agents": [
        {
            "stage": "A_OFFICIAL_UPSTREAM_OBSERVATION",
            "raw_runs": ["RUN-047-P1", "RUN-047-P2"],
            "responsible_agent_identity": "/root/run047_upstream_clinical_incident and /root/run047_upstream_composites",
            "scope": "24 exact feature/facet upstream observations: 12 candidates and 12 bounded no-candidates.",
            "boundary": "Official upstream evidence only; current Oblivion source withheld.",
            "status": "PASS_STATIC_OBSERVATION_ONLY",
            "credit": False,
        },
        {
            "stage": "B_BLIND_NEUTRAL_REQUIREMENTS",
            "raw_run": "RUN-048",
            "responsible_agent_identity": run48["responsible_agent_identity"],
            "scope": "24 source-independent normative facet requirement packets.",
            "boundary": "No filesystem, audit, upstream, current-product, benchmark-code, or web evidence was received.",
            "status": LINEAGE_NO_GO,
            "reason": "The packets are source-independent requirements, not neutral rewrites derived from sanitized RUN-047 behavior as required by the Agent A to Agent B clean specification boundary.",
            "next_action": output["stage_lineage"]["next_action"],
            "credit": False,
        },
        {
            "stage": "C_PINNED_CURRENT_SOURCE_AND_CLEAN_COMPARISON",
            "raw_runs": ["RUN-049", "RUN-050-P1", "RUN-050-P2", "RUN-050-X", "RUN-050-T", "RUN-050"],
            "responsible_agent_identity": f"{run49['responsible_agent_identity']}; {run50['responsible_agent_identity']}",
            "scope": "24 pinned source packets, 155 validated anchors, and 144 reconciled six-lens ratings.",
            "boundary": "Pinned static Git-object source and named comparison packets only; no application runtime, tests, browser, database, or source mutation.",
            "status": "PASS_MECHANICAL_STATIC_COMPARISON_ONLY",
            "credit": False,
        },
        {
            "stage": "D_INDEPENDENT_ADJUDICATION",
            "raw_run": "RUN-051",
            "responsible_agent_identity": run51["responsible_agent_identity"],
            "scope": "Independent lineage, pair, anchor, comparison, disagreement, and zero-credit review of all 24 facets.",
            "boundary": "Static audit and pinned source evidence only; no runtime, tests, browser, database, web, or application mutation.",
            "status": LINEAGE_NO_GO,
            "credit": False,
        },
        {
            "stage": "ROOT_DETERMINISTIC_INTEGRATION",
            "raw_run": "RUN-052",
            "responsible_agent_identity": "/root/run052_integration",
            "scope": "Hash-bind RUN-047 through RUN-051, validate all 24 pairs, 155 pinned anchors, 144 ratings, six independent NO-GOs, and the unchanged 340-row matrix.",
            "boundary": "Audit artifacts only; zero application, runtime, database, browser-application, test, upstream, or external mutation.",
            "status": "PASS_INTEGRATION_WITH_LINEAGE_NO_GO_PRESERVED",
            "credit": False,
        },
    ],
    "agreement": {
        "expected_feature_facet_pairs": 24,
        "observed_feature_facet_pairs_each_stage": 24,
        "duplicate_or_missing_pairs": 0,
        "source_anchor_validation_status": "PASS",
        "source_anchor_occurrences_checked": 155,
        "source_anchor_files_checked": 84,
        "comparison_dimension_ratings_checked": 144,
        "comparison_arithmetic_status": "PASS",
        "lineage_status": LINEAGE_NO_GO,
        "independent_no_go_verdicts": 6,
        "formal_edges": 0,
        "canonical_matrix_sha256": CANONICAL_MATRIX_SHA256,
        "canonical_mapping_credit_fraction": "0/340",
        "all_credits_false": True,
    },
    "credit_boundary": credit_boundary,
    "external_mutations_attestation": "NONE_STATIC_AUDIT_EVIDENCE_INTEGRATION_ONLY",
}
assert_credit_zero(agent_register)

write_json(EVIDENCE_DIR / "current-facet-neutral-comparison-wave-02.json", output)
write_json(
    EVIDENCE_DIR / "current-facet-neutral-comparison-agent-register.json",
    agent_register,
)
