from __future__ import annotations

import hashlib
import json
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
INPUT = AUDIT_DIR / "evidence/benchmark/sealed-run-145-agent-d-finance-invoice-fx-adjudication-input-wave-24.json"
OUTPUT = AUDIT_DIR / "evidence/benchmark/raw-run-145-agent-d-finance-invoice-fx-adjudication-wave-24.json"
EXPECTED_INPUT_SHA256 = "4f114bde56c58248e25bfc1053f120483e32976cb4ca06f1a7f9488b9bcd7381"


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def evidence(packet: str, observations: list[str], capability: str, row_ids: list[str], run_id: str) -> dict[str, object]:
    return {
        "agent_a_packet_id": packet,
        "agent_a_observation_ids": observations,
        "agent_b_capability_id": capability,
        "agent_b_requirement_ids": row_ids,
        "agent_c_run_id": run_id,
        "agent_c_comparison_row_ids": row_ids,
    }


def change(operation: str, value: str) -> dict[str, str]:
    key = "exact_suffix" if operation == "append_once" else "exact_value"
    return {"operation": operation, key: value}


input_bytes = INPUT.read_bytes()
assert len(input_bytes) == 164153
assert sha256(input_bytes) == EXPECTED_INPUT_SHA256
sealed_input = json.loads(input_bytes)
assert sealed_input["counts"]["canonical_targets"] == 2
assert sealed_input["counts"]["candidate_target_pairs"] == 4
assert sealed_input["counts"]["credit_awards_in_input"] == 0

fx_rows = [*(f"FX-M{i:02d}" for i in range(1, 16)), "FX-N01", "FX-N02", *(f"FX-AC{i:02d}" for i in range(1, 14))]
i1_rows = [*(f"I1-M{i:02d}" for i in range(1, 7)), "I1-N01", *(f"I1-AC{i:02d}" for i in range(1, 6))]
i2_rows = [*(f"I2-M{i:02d}" for i in range(1, 7)), "I2-N01", "I2-N02", *(f"I2-AC{i:02d}" for i in range(1, 6))]
ia_rows = [*(f"IA-M{i:02d}" for i in range(1, 7)), "IA-N01", "IA-N02", *(f"IA-AC{i:02d}" for i in range(1, 7))]
assert len(fx_rows) == 30 and len(i1_rows) == 12 and len(i2_rows) == 13 and len(ia_rows) == 14

candidate_decisions = [
    {
        "feature_id": "CAP-FIN-FX-REVALUATION",
        "project": "frappe/erpnext@v16.33.0#b24c9eba551905e256e336ff170a91a92d197a2f",
        "relationship": "direct",
        "outcome": "Native benchmark",
        "reason": "The pinned public ERPNext source establishes a directly relevant FX-revaluation workflow covering input context and date, eligible foreign-currency balances, calculations, validation, journal creation and linkage, status checks, and bounded reversal handling. Agent C found only 5 MET rows against 18 PARTIAL and 5 GAP rows, so Oblivion is not demonstrably Already better.",
        "exact_A_B_C_evidence_ids": evidence("OBS-FX-145-A", [*(f"FX{i:02d}" for i in range(1, 17))], "NEUTRAL-FX-145", fx_rows, "RUN-145-C-FX-CURRENT-COMPARISON-WAVE-24"),
        "limitations": ["Upstream organisational ledger context does not establish approved-Site access, canonical ownership, direct-object concealment, privacy, or Oblivion authorization.", "Final posting orchestration, rate provenance, retry idempotency, concurrency, approval separation, durable recovery, accessibility, and complete authorization remain unestablished.", "Static source behavior comparison only; no equivalence or copying permission."],
        "selection_eligible": True,
    },
    {
        "feature_id": "CAP-FIN-BILLING-INVOICE-LIFECYCLE",
        "project": "frappe/erpnext@v16.33.0#b24c9eba551905e256e336ff170a91a92d197a2f",
        "relationship": "direct",
        "outcome": "Native benchmark",
        "reason": "The source directly covers draft creation, submission, explicit receivable states, approval and accounting effects, payment capture and requests, after-commit duplicate-suppressed handoff, and cancellation reversal. Every direct MUST comparison in Agent C is only PARTIAL, so the candidate supplies credible behavior against material current gaps.",
        "exact_A_B_C_evidence_ids": evidence("OBS-INV-145-A1", [f"I{i}" for i in range(101, 108)], "NEUTRAL-INVOICE-145-A1", i1_rows, "RUN-145-C-INVOICE-CURRENT-COMPARISON-WAVE-24"),
        "limitations": ["State names are behavior references, not asserted equivalents to Oblivion states.", "Stock effects apply only if Oblivion invoices genuinely carry stock.", "Approved-Site access, privacy, direct-object concealment, accessibility, and supported-living terminology are not established.", "Static behavior comparison only; no implementation inheritance."],
        "selection_eligible": True,
    },
    {
        "feature_id": "CAP-FIN-BILLING-INVOICE-LIFECYCLE",
        "project": "Dolibarr/dolibarr@24.0.0#769c7db907099643558e77d7002c109cfda919e5",
        "relationship": "direct",
        "outcome": "Native benchmark",
        "reason": "Dolibarr provides a second direct and complementary invoice comparator: draft, validated, closed, and abandoned states; paid closure; unpaid abandonment; distinct paid-invoice credit-note correction; successful-delivery audit; and payment distribution. Agent C found only draft creation MET, with important GAP or PARTIAL outcomes for closure, correction, delivery audit, and multi-invoice payment.",
        "exact_A_B_C_evidence_ids": evidence("OBS-INV-145-A2", [f"I{i}" for i in range(201, 208)], "NEUTRAL-INVOICE-145-A2", i2_rows, "RUN-145-C-INVOICE-CURRENT-COMPARISON-WAVE-24"),
        "limitations": ["Abandonment, cancellation, credit-note correction, and Oblivion lifecycle states are not equivalent by default.", "Site access, privacy, direct-object concealment, retry behavior, and accessibility parity are unestablished.", "Static behavior comparison only; no code, UI, schema, wording, or layout may be copied."],
        "selection_eligible": True,
    },
    {
        "feature_id": "CAP-FIN-BILLING-INVOICE-LIFECYCLE",
        "project": "bigcapitalhq/bigcapital@v0.25.33#41033239e0f93e4fc6cf1832743ae6bdbab25306",
        "relationship": "adjacent_only_not_exact_match",
        "outcome": "Separate future decision",
        "reason": "The evidence credibly covers action guards, atomic invoice-and-journal creation, transport-aware delivery, duplicate-delivery protection, payment allocation, and guarded deletion. IA07, IA-N01, and Agent C explicitly establish that this slice does not prove the submitted-invoice cancellation or reversal lifecycle required by the exact target. Current contradictions and gaps do not permit promotion of adjacent evidence.",
        "exact_A_B_C_evidence_ids": evidence("OBS-INV-145-ADJ", [f"IA{i:02d}" for i in range(1, 9)], "NEUTRAL-INVOICE-145-ADJACENT", ia_rows, "RUN-145-C-INVOICE-CURRENT-COMPARISON-WAVE-24"),
        "limitations": ["Adjacent deletion and write-off behavior is not invoice cancellation or reversal.", "Upstream multi-organisation abstractions are non-transferable.", "This evidence must remain separate from both the exact invoice target and BigCapital's existing bank-matching register scope."],
        "selection_eligible": False,
    },
]

target_decisions = [
    {
        "feature_id": "CAP-FIN-FX-REVALUATION",
        "selected_benchmark_or_none": ["frappe/erpnext@v16.33.0#b24c9eba551905e256e336ff170a91a92d197a2f"],
        "decision": "SELECT_NATIVE_BENCHMARK",
        "reason": "The complete A/B/C chain supports a direct behavior comparator and exposes material static gaps without asserting product equivalence.",
        "matrix_mutation_authorized": True,
        "exact_matrix_field_values": {
            "benchmark_candidates": "frappe/erpnext@v16.33.0#b24c9eba551905e256e336ff170a91a92d197a2f",
            "selected_open_source_benchmark": "frappe/erpnext",
            "benchmark_url_and_sha": "https://github.com/frappe/erpnext @ v16.33.0 # b24c9eba551905e256e336ff170a91a92d197a2f",
            "verified_behaviour": "Pinned static source establishes ledger-context and date input, foreign-currency balance-sheet eligibility and exclusions, row-level exchange-rate and gain-or-loss calculation, residual-balance handling, validation and configured-account safeguards, linked balanced multi-currency journals, posted-result checks, and guarded reversal creation.",
            "neutral_requirements_extracted": "Require canonical ledger context and date before retrieval; distinct journal-write authority; eligible foreign-currency balance-sheet exposure selection; complete rate and gain-or-loss values; residual clearing; bounded rounding allowance; removal and rejection of zero-gain work; no-work feedback; configured gain-or-loss account; attributable balanced linked journals; navigable results; independently reported agreement and reversal state; and duplicate-safe draft reversal handling.",
            "no_match_evidence": "NCM_NOT_AUTHORIZED_NO_TARGET_SPECIFIC_CATALOGUE_COMPLETE_SEARCH",
            "benchmark_mapping_credit": "true",
            "completion_status": "INCOMPLETE_CANONICAL_STATIC_IDENTITY_PLUS_BENCHMARK_MAPPING_ONLY",
            "evidence_limit": "Static canonical identity plus pinned static-source benchmark mapping only; no runtime, browser, executed-test, pass, release, ease, audit-completion, or feature-completion credit.",
        },
        "mapping_credit_authorized": True,
        "preserve_all_unlisted_matrix_fields": True,
    },
    {
        "feature_id": "CAP-FIN-BILLING-INVOICE-LIFECYCLE",
        "selected_benchmark_or_none": ["frappe/erpnext@v16.33.0#b24c9eba551905e256e336ff170a91a92d197a2f", "Dolibarr/dolibarr@24.0.0#769c7db907099643558e77d7002c109cfda919e5"],
        "decision": "SELECT_COMPLEMENTARY_NATIVE_BENCHMARKS",
        "reason": "The two direct candidates provide complementary source-behavior comparisons for creation, issue or validation, sending, settlement, cancellation, reversal, and correction. BigCapital remains adjacent and is excluded from selection.",
        "matrix_mutation_authorized": True,
        "exact_matrix_field_values": {
            "benchmark_candidates": "frappe/erpnext@v16.33.0#b24c9eba551905e256e336ff170a91a92d197a2f | Dolibarr/dolibarr@24.0.0#769c7db907099643558e77d7002c109cfda919e5 | bigcapitalhq/bigcapital@v0.25.33#41033239e0f93e4fc6cf1832743ae6bdbab25306 [ADJACENT_ONLY_NOT_SELECTION_ELIGIBLE]",
            "selected_open_source_benchmark": "frappe/erpnext | Dolibarr/dolibarr",
            "benchmark_url_and_sha": "https://github.com/frappe/erpnext @ v16.33.0 # b24c9eba551905e256e336ff170a91a92d197a2f | https://github.com/Dolibarr/dolibarr @ 24.0.0 # 769c7db907099643558e77d7002c109cfda919e5",
            "verified_behaviour": "ERPNext establishes Draft creation, submission, explicit outstanding and settlement states, approval and accounting effects, payment capture and request actions, after-commit duplicate-suppressed handoff, and cancellation reversal. Dolibarr establishes Draft, Validated, Closed and Abandoned states, paid closure, unpaid abandonment, a distinct paid-invoice credit-note correction path, successful-delivery audit, and payment distribution across invoices.",
            "neutral_requirements_extracted": "Provide an explicit invoice lifecycle from Draft through issue or validation, outstanding and partial or full settlement, overdue handling, cancellation, and governed correction; apply approval and accounting controls; distinguish payment capture from payment request; commit delivery intent before duplicate-safe handoff; audit successful delivery; reverse applicable effects on cancellation; keep paid correction separate; and support auditable payment allocation and finalisation.",
            "no_match_evidence": "NCM_NOT_AUTHORIZED_NO_TARGET_SPECIFIC_CATALOGUE_COMPLETE_SEARCH",
            "benchmark_mapping_credit": "true",
            "completion_status": "INCOMPLETE_CANONICAL_STATIC_IDENTITY_PLUS_BENCHMARK_MAPPING_ONLY",
            "evidence_limit": "Static canonical identity plus two pinned direct static-source benchmark mappings only. BigCapital remains adjacent. No runtime, browser, executed-test, pass, release, ease, audit-completion, or feature-completion credit.",
        },
        "mapping_credit_authorized": True,
        "preserve_all_unlisted_matrix_fields": True,
    },
]

erp_changes = {
    "inspected_ref": change("replace", "v16.33.0"),
    "commit_sha": change("replace", "b24c9eba551905e256e336ff170a91a92d197a2f"),
    "inspected_date": change("replace", "2026-08-26"),
    "root_licence": change("replace", "Root license.txt contains GPLv3 text; -only or -or-later semantics are not inferred from that root file alone."),
    "release_activity_signal": change("append_once", " Prior catalogue and wave-4 evidence retained; supplemental wave-24 target-specific release v16.33.0 was published_at=2026-08-25T17:04:34Z and inspected at b24c9eba551905e256e336ff170a91a92d197a2f."),
    "edition_boundary": change("append_once", " Wave-24 boundary: [CAP-FIN-FX-REVALUATION] and [CAP-FIN-BILLING-INVOICE-LIFECYCLE] credit only the public ERPNext repository at v16.33.0#b24c9eba551905e256e336ff170a91a92d197a2f; Frappe Cloud, private extensions, payment providers, and uncited Frappe Framework behavior are excluded."),
    "exact_behaviour_screen_workflow_inspected": change("append_once", " Wave-24: [CAP-FIN-FX-REVALUATION] The pinned exchange-rate revaluation source establishes context/date input, eligible foreign-currency balance-sheet accounts, row calculations, validation, configured-account checks, linked balanced multi-currency journals, result checks, and guarded reversal creation. Primary source: https://github.com/frappe/erpnext/blob/b24c9eba551905e256e336ff170a91a92d197a2f/erpnext/accounts/doctype/exchange_rate_revaluation/exchange_rate_revaluation.py#L42-L639. [CAP-FIN-BILLING-INVOICE-LIFECYCLE] The pinned sales-invoice sources establish Draft creation, submission, explicit settlement states, accounting effects, payment and payment-request actions, after-commit duplicate-suppressed handoff, and cancellation reversal. Primary sources: https://github.com/frappe/erpnext/blob/b24c9eba551905e256e336ff170a91a92d197a2f/erpnext/accounts/doctype/sales_invoice/sales_invoice.py#L207-L220; https://github.com/frappe/erpnext/blob/b24c9eba551905e256e336ff170a91a92d197a2f/erpnext/accounts/doctype/sales_invoice/sales_invoice.py#L469-L645; https://github.com/frappe/erpnext/blob/b24c9eba551905e256e336ff170a91a92d197a2f/erpnext/accounts/doctype/sales_invoice/sales_invoice.js#L97-L145; https://github.com/frappe/erpnext/blob/b24c9eba551905e256e336ff170a91a92d197a2f/erpnext/accounts/doctype/payment_request/payment_request.py#L438-L465."),
    "related_feature_ids": change("replace", "ASSET-FIXED-ASSET | CAP-FIN-ACCOUNTS-RECEIVABLE-AGING | CAP-FIN-BILLING-INVOICE-LIFECYCLE | CAP-FIN-FX-REVALUATION | CAP-FIN-PURCHASE-ORDER-LIFECYCLE | CAP-FIN-QUOTE-LIFECYCLE | FIN-ACCOUNTING-INTEGRATION | FIN-ACCOUNTS-RECEIVABLE | FIN-BILL | FIN-BILLING | FIN-CHART-OF-ACCOUNTS | FIN-CREDIT-NOTE | FIN-FISCAL-PERIOD | FIN-INVOICE | FIN-LEDGER | FIN-PURCHASE-ORDER | FIN-TAX"),
    "strengths": change("append_once", " Wave-24: [CAP-FIN-FX-REVALUATION] complete source-level calculation, validation, journal-link and reversal comparison. [CAP-FIN-BILLING-INVOICE-LIFECYCLE] explicit submission, settlement-state, payment-request and cancellation-reversal comparison."),
    "limitations": change("append_once", " Wave-24 limits: [CAP-FIN-FX-REVALUATION] organisational ledger context is not approved-Site scope; final orchestration, rate provenance, retry idempotency, concurrency, approval separation, durable recovery, accessibility and full authorization remain unestablished. [CAP-FIN-BILLING-INVOICE-LIFECYCLE] state labels are not equivalents; stock behavior applies only where Oblivion genuinely has stock-bearing invoices; Site, privacy, direct-object, accessibility and supported-living parity are unestablished."),
    "neutral_requirements_extracted": change("append_once", " Wave-24: [CAP-FIN-FX-REVALUATION] context/date validation, distinct journal authority, canonical exposure selection, rate and gain-or-loss calculations, residual handling, materiality and account safeguards, linked balanced journals, result reporting and duplicate-safe reversal. [CAP-FIN-BILLING-INVOICE-LIFECYCLE] explicit Draft-to-issue lifecycle, settlement states, approval/accounting effects, payment capture and request, committed duplicate-safe delivery handoff, and effect-reversing cancellation."),
    "security_or_operational_caveat": change("append_once", " Wave-24 target boundary: upstream Company or organisation concepts do not establish Oblivion Site access, exact permissions, canonical ownership, concealment or privacy. Behavior comparison only; no source, asset, wording, layout, schema or implementation may be copied."),
    "reason_selected_or_excluded": change("append_once", " Wave-24: Selected as a direct Native benchmark for CAP-FIN-FX-REVALUATION and as one of two complementary direct Native benchmarks for CAP-FIN-BILLING-INVOICE-LIFECYCLE; no sibling or module-wide inheritance."),
    "current_target_specific_mapping_credit": change("replace", "true"),
    "current_evidence_limit": change("replace", "Historical metadata, RUN-031 observer triage and wave-4 scopes remain independently bounded. Wave-24 target-specific mapping credit applies only to CAP-FIN-FX-REVALUATION and CAP-FIN-BILLING-INVOICE-LIFECYCLE at v16.33.0#b24c9eba551905e256e336ff170a91a92d197a2f. Static behavior comparison only; no equivalence, copying, runtime, browser, test, pass, release, audit-completion or feature-completion credit."),
}

dol_changes = {
    "inspected_ref": change("replace", "24.0.0"),
    "commit_sha": change("replace", "769c7db907099643558e77d7002c109cfda919e5"),
    "inspected_date": change("replace", "2026-08-26"),
    "root_licence": change("replace", "Pinned root COPYING contains GNU General Public License version 3 text; inspected source headers separately state GPL-3.0-or-later; the root file alone does not establish an SPDX suffix."),
    "release_activity_signal": change("append_once", " Prior catalogue evidence retained; supplemental wave-24 target-specific release 24.0.0 was published_at=2026-08-20T19:49:15Z and inspected at 769c7db907099643558e77d7002c109cfda919e5."),
    "edition_boundary": change("append_once", " Wave-24 boundary: [CAP-FIN-BILLING-INVOICE-LIFECYCLE] credits only the public Dolibarr core repository at 24.0.0#769c7db907099643558e77d7002c109cfda919e5; DoliStore modules, hosted services, and external payment providers are excluded."),
    "exact_behaviour_screen_workflow_inspected": change("append_once", " Wave-24: [CAP-FIN-BILLING-INVOICE-LIFECYCLE] Customer invoices have Draft, Validated, Closed and Abandoned states; paid invoices close; unpaid invoices may be abandoned; paid-invoice correction follows a separate credit-note path; successful delivery increments a send counter and emits an event; and payment creation distributes amounts and may close paid invoices. Primary sources: https://github.com/Dolibarr/dolibarr/blob/769c7db907099643558e77d7002c109cfda919e5/htdocs/compta/facture/class/facture.class.php#L408-L513; https://github.com/Dolibarr/dolibarr/blob/769c7db907099643558e77d7002c109cfda919e5/htdocs/compta/facture/class/facture.class.php#L3357-L3534; https://github.com/Dolibarr/dolibarr/blob/769c7db907099643558e77d7002c109cfda919e5/htdocs/compta/facture/class/facture.class.php#L5360-L5386; https://github.com/Dolibarr/dolibarr/blob/769c7db907099643558e77d7002c109cfda919e5/htdocs/compta/facture/card.php#L3644-L3658; https://github.com/Dolibarr/dolibarr/blob/769c7db907099643558e77d7002c109cfda919e5/htdocs/compta/paiement.php#L230-L312."),
    "related_feature_ids": change("replace", "CAP-FIN-BILLING-INVOICE-LIFECYCLE | FIN-QUOTE"),
    "strengths": change("append_once", " Wave-24: [CAP-FIN-BILLING-INVOICE-LIFECYCLE] explicit lifecycle states, successful-delivery audit, payment allocation, and a distinct paid-invoice correction path."),
    "limitations": change("append_once", " Wave-24 limit: abandonment, cancellation, credit-note correction and Oblivion lifecycle states are not equivalent; Site, privacy, direct-object, retry and accessibility parity are unestablished."),
    "neutral_requirements_extracted": change("append_once", " Wave-24: [CAP-FIN-BILLING-INVOICE-LIFECYCLE] preserve distinct Draft, validated, settled and cancelled conditions; explicitly finalise paid invoices; keep unpaid cancellation separate from paid correction; audit successful delivery; and distribute and finalise payment allocations."),
    "security_or_operational_caveat": change("append_once", " Wave-24 target boundary: upstream organisational behavior does not establish Oblivion Site access, exact permissions, canonical ownership, concealment or privacy. Behavior comparison only; no source, asset, wording, layout, schema or implementation may be copied."),
    "reason_selected_or_excluded": change("append_once", " Wave-24: Selected as one of two complementary direct Native benchmarks for CAP-FIN-BILLING-INVOICE-LIFECYCLE; no quote, sibling or module-wide inheritance."),
    "current_target_specific_mapping_credit": change("replace", "true"),
    "current_evidence_limit": change("replace", "Historical metadata, RUN-031 observer triage and quote scope remain independently bounded. Wave-24 target-specific mapping credit applies only to CAP-FIN-BILLING-INVOICE-LIFECYCLE at 24.0.0#769c7db907099643558e77d7002c109cfda919e5. Static behavior comparison only; no equivalence, copying, runtime, browser, test, pass, release, audit-completion or feature-completion credit."),
}

register_mutations = [
    {"project": "frappe/erpnext", "mutation_authorized": True, "exact_changed_field_values": erp_changes, "preserved_existing_scope": "Preserve every existing non-Wave-24 behavior, feature ID, source URL, RUN-031 field, wave-4 field, metadata snapshot, and credit boundary not explicitly changed above.", "collision_handling": "Update the single canonical frappe/erpnext row; do not create a duplicate. The top-level inspected snapshot advances to Wave-24, while prior pins remain preserved in their source URLs and run-specific fields. Append feature-labelled Wave-24 text exactly once and grant no sibling or module-wide inheritance."},
    {"project": "Dolibarr/dolibarr", "mutation_authorized": True, "exact_changed_field_values": dol_changes, "preserved_existing_scope": "Preserve the existing FIN-QUOTE evidence, source URL, RUN-031 fields, metadata snapshot, and all unlisted credit boundaries.", "collision_handling": "Update the single canonical Dolibarr/dolibarr row; do not create a duplicate. Advance the top-level inspected snapshot while preserving the older quote pin in its existing source URL and run-specific fields. Append the invoice section exactly once and grant no quote or module-wide inheritance."},
    {"project": "bigcapitalhq/bigcapital", "mutation_authorized": False, "exact_changed_field_values": {}, "preserved_existing_scope": "Preserve the existing bank-matching and reconciliation feature IDs, evidence, benchmark outcome, pins, and current_target_specific_mapping_credit=false.", "collision_handling": "NO_MUTATION. The project-level Native benchmark outcome belongs to its existing bank scope and must not be treated as invoice-lifecycle selection. Do not add CAP-FIN-BILLING-INVOICE-LIFECYCLE, do not merge IA evidence into selected invoice behavior, and do not create a duplicate row."},
]

decision = {
    "schema_version": "raw_run_145_agent_d_finance_invoice_fx_adjudication_wave_24_v1",
    "run_id": "RUN-145-D-FINANCE-INVOICE-FX-ADJUDICATION-WAVE-24",
    "status": "INDEPENDENT_ADJUDICATION_GO_FOR_TWO_BOUNDED_MAPPING_MUTATIONS",
    "generated_on": "2026-08-26",
    "agent": {"agent_id": "/root/run145_agent_d_adjudicator", "role": "fresh_independent_adjudicator", "fresh_context": True},
    "input_verification": {"path": "evidence/benchmark/sealed-run-145-agent-d-finance-invoice-fx-adjudication-input-wave-24.json", "bytes": len(input_bytes), "sha256": EXPECTED_INPUT_SHA256, "identity_match": True},
    "candidate_decisions": candidate_decisions,
    "target_decisions": target_decisions,
    "register_mutations": register_mutations,
    "credit_boundary": {"NCM_authorized": False, "benchmark_mapping_authorized": True, "mapping_credit_target_count": 2, "completion_credit_authorized": False, "runtime_credit_authorized": False, "browser_credit_authorized": False, "executed_test_or_pass_credit_authorized": False, "release_credit_authorized": False, "audit_completion_credit_authorized": False, "application_changes_authorized": False, "writes_performed_by_agent_d": False},
    "overall_integration_decision": "GO",
    "overall_reason": "The sealed identity matches, all four candidate-target pairs have contract-valid outcomes, both exact targets have independently supported Native benchmark selections, BigCapital remains strictly adjacent, NCM remains forbidden, and root can apply only the exact bounded matrix and register mutations above.",
    "attestation": {"only_sealed_input_read": True, "all_four_candidate_target_pairs_decided": True, "allowed_outcome_vocabulary_only": True, "adjacent_boundary_preserved": True, "NCM_forbidden": True, "zero_runtime_browser_test_pass_release_completion_or_audit_completion_credit": True, "filesystem_writes_by_agent_d": 0, "root_is_sole_audit_writer": True},
}

assert len(candidate_decisions) == 4
assert sum(item["outcome"] == "Native benchmark" for item in candidate_decisions) == 3
assert sum(item["outcome"] == "Separate future decision" for item in candidate_decisions) == 1
assert len(target_decisions) == 2 and all(item["mapping_credit_authorized"] for item in target_decisions)
assert [item["project"] for item in register_mutations if item["mutation_authorized"]] == ["frappe/erpnext", "Dolibarr/dolibarr"]

output_bytes = (json.dumps(decision, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
if OUTPUT.exists():
    assert OUTPUT.read_bytes() == output_bytes, f"Refusing to overwrite different bytes: {OUTPUT}"
else:
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_bytes(output_bytes)

print(f"{OUTPUT.relative_to(AUDIT_DIR)}\t{len(output_bytes)}\t{sha256(output_bytes)}")
