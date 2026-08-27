from __future__ import annotations

import hashlib
import json
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
INPUT = AUDIT_DIR / "evidence/benchmark/raw-run-145-agent-d-finance-invoice-fx-adjudication-wave-24.json"
OUTPUT = AUDIT_DIR / "evidence/benchmark/raw-run-145-p8-adversarial-integration-review-wave-24.json"
EXPECTED_INPUT_SHA256 = "f7149cc02849befa03013148e72e53b92048a53eac685de92018c46ea6f3f71d"


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


input_bytes = INPUT.read_bytes()
assert len(input_bytes) == 29300
assert sha256(input_bytes) == EXPECTED_INPUT_SHA256
decision = json.loads(input_bytes)
assert decision["overall_integration_decision"] == "GO"
assert decision["credit_boundary"]["mapping_credit_target_count"] == 2
assert decision["credit_boundary"]["NCM_authorized"] is False

review = {
    "schema_version": "raw_run_145_p8_adversarial_integration_review_wave_24_v1",
    "run_id": "RUN-145-P8-ADVERSARIAL-INTEGRATION-REVIEW-WAVE-24",
    "status": "NO_GO_FOR_PACKET_EXACT_INTEGRATION_BOUNDED_CORRECTIONS_REQUIRED",
    "generated_on": "2026-08-26",
    "agent": {"agent_id": "/root/run145_p8_adversarial", "role": "fresh_pass_8_adversarial_reviewer", "fresh_context": True},
    "input_verification": {"path": "evidence/benchmark/raw-run-145-agent-d-finance-invoice-fx-adjudication-wave-24.json", "bytes": len(input_bytes), "sha256": EXPECTED_INPUT_SHA256, "exact_match": True},
    "passing_checks": {
        "sealed_agent_d_input_exact": True,
        "all_4_candidate_pairs_use_allowed_outcomes": True,
        "all_A_packet_and_observation_ids_exact": True,
        "all_B_capability_and_row_ids_exact": True,
        "all_C_run_and_row_ids_exact": True,
        "two_direct_target_selections_supported": True,
        "two_mapping_credits_supported": True,
        "NCM_forbidden_and_zero": True,
        "BigCapital_no_mutation_and_adjacent_only": True,
        "single_organisation_multiple_Sites_boundary_preserved": True,
        "no_copy_boundary_preserved": True,
        "runtime_browser_test_pass_release_completion_and_audit_completion_credit_zero": True,
        "substantive_adjudication": "GO",
    },
    "blocking_corrections": {
        "matrix_format_overrides": {
            "CAP-FIN-FX-REVALUATION": {
                "benchmark_candidates": "frappe/erpnext",
                "selected_open_source_benchmark": "frappe/erpnext",
                "benchmark_url_and_sha": "https://github.com/frappe/erpnext@b24c9eba551905e256e336ff170a91a92d197a2f",
            },
            "CAP-FIN-BILLING-INVOICE-LIFECYCLE": {
                "benchmark_candidates": "frappe/erpnext; Dolibarr/dolibarr; bigcapitalhq/bigcapital [ADJACENT_ONLY_NOT_SELECTION_ELIGIBLE]",
                "selected_open_source_benchmark": "frappe/erpnext; Dolibarr/dolibarr",
                "benchmark_url_and_sha": "https://github.com/frappe/erpnext@b24c9eba551905e256e336ff170a91a92d197a2f; https://github.com/Dolibarr/dolibarr@769c7db907099643558e77d7002c109cfda919e5",
            },
        },
        "matrix_format_reason": "The live 340-row matrix uses semicolon-space lists, project-only candidate and selection names, and immutable URL@commit strings. D's project@tag#commit identity remains preserved in the adjudication evidence but must not replace the live matrix convention.",
        "register_traceability_additions": {
            "frappe/erpnext": {
                "field": "exact_behaviour_screen_workflow_inspected",
                "operation": "append_once_after_agent_d_suffix",
                "exact_suffix": " Additional pinned status source required by I101, I102, and I106: https://github.com/frappe/erpnext/blob/b24c9eba551905e256e336ff170a91a92d197a2f/erpnext/accounts/doctype/sales_invoice/sales_invoice.py#L2271-L2312.",
            },
            "Dolibarr/dolibarr": {
                "field": "exact_behaviour_screen_workflow_inspected",
                "operation": "append_once_after_agent_d_suffix",
                "exact_suffix": " Additional pinned successful-delivery counter and event source required by I205: https://github.com/Dolibarr/dolibarr/blob/769c7db907099643558e77d7002c109cfda919e5/htdocs/core/actions_sendmails.inc.php#L444-L503.",
            },
        },
        "traceability_reason": "Agent-D register prose omitted one immutable source locus required by each cited neutral lineage; both additions are already registered and pinned in Agent-A evidence.",
    },
    "integration_contract_after_correction": {
        "apply_agent_d_values_except_exact_overrides_above": True,
        "append_both_traceability_additions_exactly_once": True,
        "preserve_all_unlisted_matrix_and_register_fields": True,
        "matrix_rows_changed": 2,
        "register_rows_changed": 2,
        "mapping_credit_after_integration": 2,
        "NCM_after_integration": 0,
        "unresolved_targets_after_integration": 338,
        "BigCapital_row_byte_logically_unchanged": True,
        "application_source_changes_allowed": False,
        "all_non_mapping_credit_remains_zero": True,
        "fresh_independent_corrected_integration_review_required": True,
    },
    "verdict": "NO_GO_PACKET_EXACT_CORRECTABLE_WITHOUT_REOPENING_SUBSTANTIVE_ADJUDICATION",
    "writes_by_reviewer": 0,
}

output_bytes = (json.dumps(review, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
if OUTPUT.exists():
    assert OUTPUT.read_bytes() == output_bytes, f"Refusing to overwrite different bytes: {OUTPUT}"
else:
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_bytes(output_bytes)

print(f"{OUTPUT.relative_to(AUDIT_DIR)}\t{len(output_bytes)}\t{sha256(output_bytes)}")
