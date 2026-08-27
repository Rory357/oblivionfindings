from __future__ import annotations

import hashlib
import json
import runpy
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
MATRIX_PATH = AUDIT_DIR / "03-feature-to-benchmark-matrix.csv"
REGISTER_PATH = AUDIT_DIR / "06-open-source-benchmark-register.csv"
PLAN_GENERATOR = AUDIT_DIR / "generators/materialize-run-145-corrected-integration-plan-wave-24.py"
PLAN_PATH = AUDIT_DIR / "evidence/benchmark/sealed-run-145-corrected-matrix-register-integration-input-wave-24.json"
OUTPUT = AUDIT_DIR / "evidence/benchmark/current-run-145-finance-invoice-fx-benchmark-mapping-wave-24.json"

OLD_MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
OLD_REGISTER_SHA256 = "cc493cd1807e62a9ffa27192c658400e697391b7a0baa3f0014628145c6b7b91"
NEW_MATRIX_SHA256 = "3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0"
NEW_REGISTER_SHA256 = "5a3898855f1ffffb1a493496a57f6532bdc464552e4577c9e7c97f83c9793884"
PLAN_SHA256 = "42abc1082642c9db66cb8bf0fd16b3bc9ddd5bdb7891b7c7830a7f09b8796468"
D_SHA256 = "f7149cc02849befa03013148e72e53b92048a53eac685de92018c46ea6f3f71d"
P8_SHA256 = "810b8bb2fe1ba94c265b51eaf2056acc6385fe503049a6ee40301e44c8ef3a14"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def exact_hash(path: Path, expected: str) -> bytes:
    data = path.read_bytes()
    assert sha256(data) == expected, f"{path}: hash mismatch"
    return data


matrix_before = MATRIX_PATH.read_bytes()
register_before = REGISTER_PATH.read_bytes()
matrix_before_hash = sha256(matrix_before)
register_before_hash = sha256(register_before)
allowed_states = {
    (OLD_MATRIX_SHA256, OLD_REGISTER_SHA256),
    (NEW_MATRIX_SHA256, NEW_REGISTER_SHA256),
}
assert (matrix_before_hash, register_before_hash) in allowed_states, "Refusing mixed, stale, or unexpected matrix/register state"

plan_data = exact_hash(PLAN_PATH, PLAN_SHA256)
plan = json.loads(plan_data)
assert plan["proposed_outputs"]["matrix"]["sha256"] == NEW_MATRIX_SHA256
assert plan["proposed_outputs"]["benchmark_register"]["sha256"] == NEW_REGISTER_SHA256
assert plan["application_status"] == "NOT_APPLIED_REQUIRES_FRESH_INDEPENDENT_REVIEW_GO"

if matrix_before_hash == OLD_MATRIX_SHA256:
    namespace = runpy.run_path(str(PLAN_GENERATOR))
    matrix_after_data = namespace["matrix_after_data"]
    register_after_data = namespace["register_after_data"]
    assert len(matrix_after_data) == 557989 and sha256(matrix_after_data) == NEW_MATRIX_SHA256
    assert len(register_after_data) == 350420 and sha256(register_after_data) == NEW_REGISTER_SHA256
    MATRIX_PATH.write_bytes(matrix_after_data)
    REGISTER_PATH.write_bytes(register_after_data)

matrix_after = exact_hash(MATRIX_PATH, NEW_MATRIX_SHA256)
register_after = exact_hash(REGISTER_PATH, NEW_REGISTER_SHA256)
assert len(matrix_after) == 557989
assert len(register_after) == 350420

script_path = Path(__file__).resolve()
script_data = script_path.read_bytes()
receipt = {
    "schema_version": "current_run_145_finance_invoice_fx_benchmark_mapping_wave_24_v1",
    "run_id": "RUN-145-FINANCE-INVOICE-FX-BENCHMARK-MAPPING-WAVE-24",
    "status": "TWO_INDEPENDENTLY_ADJUDICATED_STATIC_BENCHMARK_MAPPINGS_INTEGRATED",
    "generated_on": "2026-08-26",
    "application_source_pin": {"commit": APPLICATION_COMMIT, "tree": APPLICATION_TREE, "application_files_changed": 0},
    "inputs": {
        "pre_integration_matrix": {"path": "03-feature-to-benchmark-matrix.csv", "bytes": 555852, "sha256": OLD_MATRIX_SHA256},
        "pre_integration_benchmark_register": {"path": "06-open-source-benchmark-register.csv", "bytes": 343076, "sha256": OLD_REGISTER_SHA256},
        "agent_d_decision": {"path": "evidence/benchmark/raw-run-145-agent-d-finance-invoice-fx-adjudication-wave-24.json", "bytes": 29300, "sha256": D_SHA256},
        "pass_8_correction": {"path": "evidence/benchmark/raw-run-145-p8-adversarial-integration-review-wave-24.json", "bytes": 4213, "sha256": P8_SHA256},
        "corrected_integration_plan": {"path": "evidence/benchmark/sealed-run-145-corrected-matrix-register-integration-input-wave-24.json", "bytes": len(plan_data), "sha256": PLAN_SHA256},
    },
    "outputs": {
        "matrix": {"path": "03-feature-to-benchmark-matrix.csv", "bytes": len(matrix_after), "sha256": NEW_MATRIX_SHA256, "rows": 340, "changed_rows": 2, "changed_cells": 18, "unaffected_rows": 338},
        "benchmark_register": {"path": "06-open-source-benchmark-register.csv", "bytes": len(register_after), "sha256": NEW_REGISTER_SHA256, "rows": 98, "changed_rows": 2, "changed_cells": 30, "unaffected_rows": 96},
    },
    "integrated_targets": [
        {"feature_id": "CAP-FIN-FX-REVALUATION", "selected_native_benchmarks": ["frappe/erpnext@b24c9eba551905e256e336ff170a91a92d197a2f"]},
        {"feature_id": "CAP-FIN-BILLING-INVOICE-LIFECYCLE", "selected_native_benchmarks": ["frappe/erpnext@b24c9eba551905e256e336ff170a91a92d197a2f", "Dolibarr/dolibarr@769c7db907099643558e77d7002c109cfda919e5"], "adjacent_not_selected": ["bigcapitalhq/bigcapital@41033239e0f93e4fc6cf1832743ae6bdbab25306"]},
    ],
    "counts": {"benchmark_mappings": 2, "final_no_matches_or_NCMs": 0, "unresolved_targets": 338, "project_rows_with_current_target_mapping_credit": 2},
    "invariants": {
        "BigCapital_register_row_unchanged": True,
        "matrix_and_register_headers_and_row_order_preserved": True,
        "pass_8_matrix_format_corrections_applied": True,
        "pass_8_missing_source_loci_appended_once": True,
        "one_operating_organisation_multiple_Sites": True,
        "tenant_concepts_added": False,
        "source_assets_wording_layout_schema_or_implementation_copied": False,
        "NCM_authorized": False,
        "runtime_browser_application_executed_test_pass_release_ease_completion_final_finding_or_audit_completion_credit": 0,
    },
    "reporting_status": "CURRENT_MATRIX_AND_REGISTER_INTEGRATED_REPORTING_AND_DASHBOARD_REFRESH_PENDING_RUN_146",
    "generator": {"path": str(script_path.relative_to(AUDIT_DIR)).replace("\\", "/"), "bytes": len(script_data), "sha256": sha256(script_data)},
}

output_bytes = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
if OUTPUT.exists():
    assert OUTPUT.read_bytes() == output_bytes, f"Refusing to overwrite different bytes: {OUTPUT}"
else:
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_bytes(output_bytes)

print(f"{MATRIX_PATH.relative_to(AUDIT_DIR)}\t{len(matrix_after)}\t{NEW_MATRIX_SHA256}")
print(f"{REGISTER_PATH.relative_to(AUDIT_DIR)}\t{len(register_after)}\t{NEW_REGISTER_SHA256}")
print(f"{OUTPUT.relative_to(AUDIT_DIR)}\t{len(output_bytes)}\t{sha256(output_bytes)}")
