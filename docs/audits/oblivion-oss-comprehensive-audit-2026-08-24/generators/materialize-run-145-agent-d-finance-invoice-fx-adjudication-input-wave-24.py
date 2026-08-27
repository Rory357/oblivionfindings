from __future__ import annotations

import csv
import hashlib
import json
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
BENCHMARK_DIR = AUDIT_DIR / "evidence/benchmark"
OUTPUT = BENCHMARK_DIR / "sealed-run-145-agent-d-finance-invoice-fx-adjudication-input-wave-24.json"
MATRIX_PATH = AUDIT_DIR / "03-feature-to-benchmark-matrix.csv"
REGISTER_PATH = AUDIT_DIR / "06-open-source-benchmark-register.csv"

MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
REGISTER_SHA256 = "cc493cd1807e62a9ffa27192c658400e697391b7a0baa3f0014628145c6b7b91"
COHORT_SHA256 = "f6522709277cadadabce1c01478fc7ed5f08e16cebc7fdf048a22a32149673e9"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"

INPUTS = {
    "agent_a_observed_behavior": (
        "raw-run-145-agent-a-finance-invoice-fx-observed-behavior-wave-24.json",
        "deb70284836ad6b4bba49a8f4149e85807faa7248a56909b901df233405e9f8f",
    ),
    "agent_b_neutral_requirements": (
        "raw-run-145-agent-b-finance-invoice-fx-neutral-requirements-wave-24.json",
        "8d75485a11b0c4fa0f634650062da3c54dacb31cef7f5a068477340a308cadeb",
    ),
    "agent_c_fx_current_comparison": (
        "raw-run-145-agent-c-fx-current-comparison-wave-24.json",
        "0c0c077f727e30fbf3ba08b945eb3e4f7b2c5c8c89a0ea61e3f81d69c006d0db",
    ),
    "agent_c_invoice_current_comparison": (
        "raw-run-145-agent-c-invoice-current-comparison-wave-24.json",
        "2e5f68793ba94955c51159d297ca99b807d6e40d2ae9089504cb8cf3fab91443",
    ),
}

TARGET_IDS = [
    "CAP-FIN-BILLING-INVOICE-LIFECYCLE",
    "CAP-FIN-FX-REVALUATION",
]
PROJECT_IDS = ["Dolibarr/dolibarr", "bigcapitalhq/bigcapital", "frappe/erpnext"]


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def read_sealed_json(name: str, expected: str) -> dict[str, object]:
    path = BENCHMARK_DIR / name
    data = path.read_bytes()
    assert sha256(data) == expected, f"{path}: hash mismatch"
    return {
        "path": str(path.relative_to(AUDIT_DIR)).replace("\\", "/"),
        "bytes": len(data),
        "sha256": expected,
        "content": json.loads(data),
    }


def verified_csv(path: Path, expected: str) -> tuple[dict[str, object], list[dict[str, str]]]:
    data = path.read_bytes()
    assert sha256(data) == expected
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        rows = list(csv.DictReader(handle))
    return (
        {
            "path": str(path.relative_to(AUDIT_DIR)).replace("\\", "/"),
            "bytes": len(data),
            "sha256": expected,
        },
        rows,
    )


sealed_inputs = {key: read_sealed_json(name, digest) for key, (name, digest) in INPUTS.items()}
matrix_seal, matrix_rows = verified_csv(MATRIX_PATH, MATRIX_SHA256)
register_seal, register_rows = verified_csv(REGISTER_PATH, REGISTER_SHA256)
assert len(matrix_rows) == 340
assert len({item["feature_id"] for item in matrix_rows}) == 340
current_target_rows = []
for target_id in TARGET_IDS:
    matches = [item for item in matrix_rows if item["feature_id"] == target_id]
    assert len(matches) == 1
    assert matches[0]["benchmark_mapping_credit"].lower() == "false"
    current_target_rows.append(matches[0])
current_register_rows = []
for project_id in PROJECT_IDS:
    matches = [item for item in register_rows if item["project"] == project_id]
    assert len(matches) == 1
    current_register_rows.append(matches[0])

cohort_bytes = ("\n".join(sorted(TARGET_IDS)) + "\n").encode("utf-8")
assert len(cohort_bytes) == 57
assert sha256(cohort_bytes) == COHORT_SHA256

agent_a = sealed_inputs["agent_a_observed_behavior"]["content"]
agent_b = sealed_inputs["agent_b_neutral_requirements"]["content"]
agent_c_fx = sealed_inputs["agent_c_fx_current_comparison"]["content"]
agent_c_invoice = sealed_inputs["agent_c_invoice_current_comparison"]["content"]
assert agent_a["counts"]["observations"] == 38
assert agent_b["counts"]["MUST"] == 33
assert agent_b["counts"]["NOT_ESTABLISHED"] == 7
assert agent_b["counts"]["acceptance_criteria"] == 29
assert agent_c_fx["counts"]["comparison_rows"] == 30
assert agent_c_invoice["counts"]["comparison_rows"] == 39
assert agent_c_fx["counts"]["credit_awards"] == 0
assert agent_c_invoice["counts"]["credit_awards"] == 0

payload = {
    "schema_version": "sealed_run_145_agent_d_finance_invoice_fx_adjudication_input_wave_24_v1",
    "run_id": "RUN-145-D-INPUT-FINANCE-INVOICE-FX-ADJUDICATION-WAVE-24",
    "status": "FULL_A_B_C_IDENTITY_REATTACHMENT_READY_FOR_INDEPENDENT_ADJUDICATION_ZERO_CURRENT_CREDIT",
    "generated_on": "2026-08-26",
    "application_source_pin": {"commit": APPLICATION_COMMIT, "tree": APPLICATION_TREE},
    "canonical_cohort_seal": {
        "feature_ids": TARGET_IDS,
        "canonical_bytes": "Sorted feature IDs joined by LF with terminal LF",
        "bytes": len(cohort_bytes),
        "sha256": COHORT_SHA256,
    },
    "current_catalogue_seals": {"matrix": matrix_seal, "benchmark_register": register_seal},
    "current_target_rows": current_target_rows,
    "current_register_rows_for_candidate_projects": current_register_rows,
    "candidate_scope": [
        {
            "feature_id": "CAP-FIN-FX-REVALUATION",
            "direct_candidates": ["frappe/erpnext@v16.33.0#b24c9eba551905e256e336ff170a91a92d197a2f"],
            "adjacent_candidates": [],
        },
        {
            "feature_id": "CAP-FIN-BILLING-INVOICE-LIFECYCLE",
            "direct_candidates": [
                "frappe/erpnext@v16.33.0#b24c9eba551905e256e336ff170a91a92d197a2f",
                "Dolibarr/dolibarr@24.0.0#769c7db907099643558e77d7002c109cfda919e5",
            ],
            "adjacent_candidates": ["bigcapitalhq/bigcapital@v0.25.33#41033239e0f93e4fc6cf1832743ae6bdbab25306"],
        },
    ],
    "sealed_inputs": sealed_inputs,
    "adjudication_contract": {
        "role": "Fresh independent Agent D; assess upstream identities and source-exact observed behavior against neutral requirements and current Oblivion comparison.",
        "required_candidate_outcome_vocabulary": ["Native benchmark", "Already better", "No credible match", "Separate future decision", "Reject"],
        "required_per_candidate_fields": ["feature_id", "project", "relationship", "outcome", "reason", "exact_A_B_C_evidence_ids", "limitations", "selection_eligible"],
        "required_per_target_fields": ["feature_id", "selected_benchmark_or_none", "decision", "reason", "matrix_mutation_authorized", "exact_matrix_field_values", "mapping_credit_authorized"],
        "required_register_fields_if_mutation_authorized": ["project", "exact_changed_field_values", "preserved_existing_scope", "collision_handling"],
        "selection_meaning": "A Native benchmark is a source-behavior comparison target, not product equivalence and not permission to copy source, assets, wording, layout, schema, or implementation.",
        "already_better_meaning": "Use only when current Oblivion behavior demonstrably exceeds the credible candidate for the exact target; current gaps generally preclude this conclusion.",
        "separate_future_decision_meaning": "Credible evidence is adjacent or useful but insufficient for the exact target decision.",
        "reject_meaning": "Candidate is not credible or not sufficiently target-relevant under the sealed evidence.",
        "NCM_allowed": False,
        "NCM_reason": "No target-specific catalogue-complete search exists for either feature. A candidate-level negative outcome is not a target-level NCM.",
        "mapping_credit_may_be_authorized_only_if": "One exact target has a selected Native benchmark supported by Agent-A identity/provenance, Agent-B neutral requirements, Agent-C current comparison, and this Agent-D independent decision.",
        "completion_credit_allowed": False,
        "runtime_browser_test_pass_release_or_audit_completion_credit_allowed": False,
        "application_source_or_runtime_changes_allowed": False,
        "matrix_or_register_writes_by_agent_d_allowed": False,
        "root_only_writes": True,
        "architecture": "One operating organisation across multiple Sites. Never translate upstream Company or organisation abstractions into tenant concepts. Preserve exact permissions, approved Sites, canonical ownership, direct-object concealment, privacy, and native Oblivion workflow.",
        "run071": "EXCLUDED_NO_GO_FACET_HASH_MISBINDING_NOT_PART_OF_THIS_CHAIN",
        "must_preserve_adjacent_boundary": True,
        "must_report_collisions_with_existing_register_scope": True,
        "must_not_infer_runtime_from_static_source": True,
    },
    "counts": {
        "canonical_targets": 2,
        "candidate_target_pairs": 4,
        "direct_candidate_target_pairs": 3,
        "adjacent_candidate_target_pairs": 1,
        "agent_a_observations": 38,
        "agent_b_rows": 69,
        "agent_c_rows": 69,
        "current_matrix_mapping_credit": 0,
        "current_NCM_credit": 0,
        "credit_awards_in_input": 0,
    },
}

output_bytes = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
if OUTPUT.exists():
    assert OUTPUT.read_bytes() == output_bytes, f"Refusing to overwrite different bytes: {OUTPUT}"
else:
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_bytes(output_bytes)

print(f"{OUTPUT.relative_to(AUDIT_DIR)}\t{len(output_bytes)}\t{sha256(output_bytes)}")
