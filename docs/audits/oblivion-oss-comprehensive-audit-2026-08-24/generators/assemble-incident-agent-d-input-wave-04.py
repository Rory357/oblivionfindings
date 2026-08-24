from __future__ import annotations

import hashlib
import json
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
SPECS = {
    "agent_a": (
        "evidence/benchmark/raw-run-072-agent-a-incident-observed-behavior-wave-04.json",
        "c8b513225613053253207d457a0556e9888510950ec53534d8d23c85ec51e8b1",
    ),
    "agent_b": (
        "evidence/benchmark/raw-run-072-agent-b-neutral-incident-requirements-wave-04.json",
        "425f9c38320e37915e5ceff33a4f65b8d96b8183cb6e2b70955e07b1145e8c97",
    ),
    "agent_c_input": (
        "evidence/benchmark/sealed-run-072-agent-c-incident-comparison-input-wave-04.json",
        "8090a913c2ddda885d4175bb44fb8c49b8bb997af4bd97b97e4bb124990371e3",
    ),
    "agent_c": (
        "evidence/benchmark/raw-run-072-agent-c-incident-current-comparison-wave-04.json",
        "948da9127b609ea3737a9eeeaa90c5e5c2e053dff9e6a2845cc4633363dee4eb",
    ),
}
OUTPUT = AUDIT_DIR / "evidence/benchmark/sealed-run-072-agent-d-incident-adjudication-input-wave-04.json"


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


inputs = {}
manifest = []
for role, (relative_path, expected_hash) in SPECS.items():
    path = AUDIT_DIR / relative_path
    data = path.read_bytes()
    assert sha256(data) == expected_hash
    inputs[role] = json.loads(data)
    manifest.append({"role": role, "path": relative_path, "bytes": len(data), "sha256": expected_hash})

agent_a = inputs["agent_a"]
agent_b = inputs["agent_b"]
agent_c_input = inputs["agent_c_input"]
agent_c = inputs["agent_c"]
assert agent_a["counts"]["packets"] == 2
assert agent_a["counts"]["observations"] == 48
assert agent_b["counts"]["requirements"] == {"MUST": 14, "SHOULD": 0, "NOT_ESTABLISHED": 25, "total": 39}
assert len(agent_c_input["current_source_facets"]) == 2
assert len(agent_c["comparisons"]) == 39
assert agent_c["counts"]["outcomes"] == {"MET": 5, "PARTIAL": 3, "GAP": 0, "CONTRADICTED": 0, "NOT_COMPARABLE": 31}
assert {row["id"] for row in agent_b["must_requirements"] + agent_b["not_established_requirements"]} == {
    row["requirement_id"] for row in agent_c["comparisons"]
}
assert all(packet["reattachment_appendix"]["accepted_record"]["id"] == "PRJ-INC-BEACONHS" for packet in agent_a["packets"])
assert all(facet["feature_id"] == "CAP-INC-INCIDENT-REVIEW-CLOSURE" for facet in agent_c_input["current_source_facets"])

payload = {
    "schema_version": "sealed_run_072_agent_d_incident_adjudication_input_wave_04_v1",
    "run_id": "RUN-072-D-INPUT",
    "status": "COMPLETE_A_B_C_CHAIN_WITH_IDENTITY_REATTACHED_FOR_FRESH_ADJUDICATION",
    "input_manifest": manifest,
    "application_source_pin": agent_c_input["application_source_pin"],
    "canonical_target": {
        "feature_id": "CAP-INC-INCIDENT-REVIEW-CLOSURE",
        "feature_class": "H",
        "module": "Incidents",
        "user_job": "Review and close an incident under incident journey rules",
        "matrix_sha256": "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4",
        "current_mapping_credit": False,
    },
    "agent_a_observed_behavior_and_reattachment": agent_a,
    "agent_b_neutral_specification": agent_b,
    "pinned_current_source_facets": agent_c_input["current_source_facets"],
    "agent_c_comparison": agent_c,
    "adjudication_contract": {
        "review_packet_and_closure_packet_must_be_adjudicated_separately": True,
        "accepted_project_record_is_a_locator_not_a_mapping": True,
        "accepted_claim_or_aspect_hash_is_not_a_target_edge": True,
        "upstream_unknowns_must_not_become_product_gaps": True,
        "current_product_behavior_must_not_be_inherited_into_upstream": True,
        "adjacent_or_collision_semantics_must_not_be_inherited": True,
        "static_source_is_not_runtime_or_browser_proof": True,
        "allowed_facet_dispositions": ["GO", "NO_GO", "DEFER"],
        "allowed_target_dispositions": ["GO", "NO_GO", "DEFER"],
        "mapping_credit_requires_explicit_fresh_GO_and_full_lineage": True,
        "final_no_match_or_NCM_requires_exhaustive_target_specific_search": True,
    },
    "required_output": {
        "input_hash_and_count_checks": True,
        "agent_independence_and_boundary_checks": True,
        "all_39_comparison_rows_reviewed": True,
        "review_facet_disposition": True,
        "closure_facet_disposition": True,
        "target_disposition": True,
        "collision_and_unknown_preservation": True,
        "matrix_and_register_change_decision": True,
        "credit_decision_for_every_credit_class": True,
    },
    "prohibited_inputs": [
        "RUN-047 through RUN-057 historical diagnostic comparisons",
        "unrelated upstream projects or current-source facets",
        "working-tree application source",
        "browser, network, runtime, test, build, or database evidence",
    ],
    "counts": {
        "agent_a_packets": 2,
        "agent_a_observations": 48,
        "neutral_requirements": 39,
        "current_source_facets": 2,
        "agent_c_comparisons": 39,
        "upstream_direct_project_records": 1,
        "canonical_targets": 1,
        "old_comparisons": 0,
        "credit_awards_before_adjudication": 0,
    },
    "attestation": {
        "identity_reattached_only_after_agent_c_returned": True,
        "agent_b_and_agent_c_clean_boundaries_preserved": True,
        "every_input_represented_once_by_role": True,
        "zero_preaccepted_mapping_or_credit": True,
    },
}
output_bytes = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
if OUTPUT.exists():
    assert OUTPUT.read_bytes() == output_bytes, f"Refusing to overwrite different bytes: {OUTPUT}"
else:
    OUTPUT.write_bytes(output_bytes)
assert json.loads(OUTPUT.read_bytes()) == payload
print(f"{OUTPUT.relative_to(AUDIT_DIR)}\t{len(output_bytes)}\t{sha256(output_bytes)}")
