from __future__ import annotations

import csv
import hashlib
import io
import json
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
MATRIX_PATH = AUDIT_DIR / "03-feature-to-benchmark-matrix.csv"
REGISTER_PATH = AUDIT_DIR / "06-open-source-benchmark-register.csv"
D_PATH = AUDIT_DIR / "evidence/benchmark/raw-run-145-agent-d-finance-invoice-fx-adjudication-wave-24.json"
P8_PATH = AUDIT_DIR / "evidence/benchmark/raw-run-145-p8-adversarial-integration-review-wave-24.json"
OUTPUT = AUDIT_DIR / "evidence/benchmark/sealed-run-145-corrected-matrix-register-integration-input-wave-24.json"

EXPECTED_MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
EXPECTED_REGISTER_SHA256 = "cc493cd1807e62a9ffa27192c658400e697391b7a0baa3f0014628145c6b7b91"
EXPECTED_D_SHA256 = "f7149cc02849befa03013148e72e53b92048a53eac685de92018c46ea6f3f71d"
EXPECTED_P8_SHA256 = "810b8bb2fe1ba94c265b51eaf2056acc6385fe503049a6ee40301e44c8ef3a14"
TARGET_IDS = ["CAP-FIN-BILLING-INVOICE-LIFECYCLE", "CAP-FIN-FX-REVALUATION"]
PROJECT_IDS = ["frappe/erpnext", "Dolibarr/dolibarr", "bigcapitalhq/bigcapital"]
MATRIX_MUTABLE_FIELDS = {
    "benchmark_candidates",
    "selected_open_source_benchmark",
    "benchmark_url_and_sha",
    "verified_behaviour",
    "neutral_requirements_extracted",
    "no_match_evidence",
    "benchmark_mapping_credit",
    "completion_status",
    "evidence_limit",
}


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def seal(path: Path, expected: str) -> tuple[dict[str, object], bytes]:
    data = path.read_bytes()
    assert sha256(data) == expected, f"{path}: hash mismatch"
    return ({"path": str(path.relative_to(AUDIT_DIR)).replace("\\", "/"), "bytes": len(data), "sha256": expected}, data)


def parse_csv(data: bytes) -> tuple[list[str], list[dict[str, str]]]:
    text = data.decode("utf-8")
    reader = csv.DictReader(io.StringIO(text, newline=""))
    assert reader.fieldnames is not None
    return list(reader.fieldnames), list(reader)


def csv_bytes(fieldnames: list[str], rows: list[dict[str, str]]) -> bytes:
    stream = io.StringIO(newline="")
    writer = csv.DictWriter(stream, fieldnames=fieldnames, extrasaction="raise", lineterminator="\n", quoting=csv.QUOTE_MINIMAL)
    writer.writeheader()
    writer.writerows(rows)
    return stream.getvalue().encode("utf-8")


def one(rows: list[dict[str, str]], key: str, value: str) -> dict[str, str]:
    matches = [row for row in rows if row[key] == value]
    assert len(matches) == 1, f"Expected one {key}={value}"
    return matches[0]


def apply_change(row: dict[str, str], field: str, operation: dict[str, str]) -> None:
    assert field in row
    if operation["operation"] == "replace":
        row[field] = operation["exact_value"]
        return
    assert operation["operation"] == "append_once"
    suffix = operation["exact_suffix"]
    if row[field].endswith(suffix):
        return
    assert suffix not in row[field]
    row[field] += suffix


matrix_seal, matrix_data = seal(MATRIX_PATH, EXPECTED_MATRIX_SHA256)
register_seal, register_data = seal(REGISTER_PATH, EXPECTED_REGISTER_SHA256)
d_seal, d_data = seal(D_PATH, EXPECTED_D_SHA256)
p8_seal, p8_data = seal(P8_PATH, EXPECTED_P8_SHA256)
d_decision = json.loads(d_data)
p8_review = json.loads(p8_data)
assert d_decision["overall_integration_decision"] == "GO"
assert p8_review["verdict"] == "NO_GO_PACKET_EXACT_CORRECTABLE_WITHOUT_REOPENING_SUBSTANTIVE_ADJUDICATION"
assert p8_review["passing_checks"]["substantive_adjudication"] == "GO"

matrix_fields, matrix_rows = parse_csv(matrix_data)
register_fields, register_rows = parse_csv(register_data)
assert csv_bytes(matrix_fields, matrix_rows) == matrix_data
assert csv_bytes(register_fields, register_rows) == register_data
assert len(matrix_rows) == 340 and len({row["feature_id"] for row in matrix_rows}) == 340
assert len(register_rows) == 98 and len({row["project"] for row in register_rows}) == 98

matrix_before = [dict(row) for row in matrix_rows]
register_before = [dict(row) for row in register_rows]
target_decisions = {item["feature_id"]: item for item in d_decision["target_decisions"]}
format_overrides = p8_review["blocking_corrections"]["matrix_format_overrides"]
assert set(target_decisions) == set(TARGET_IDS)
assert set(format_overrides) == set(TARGET_IDS)
for target_id in TARGET_IDS:
    target = one(matrix_rows, "feature_id", target_id)
    decision = target_decisions[target_id]
    assert decision["matrix_mutation_authorized"] is True
    assert decision["mapping_credit_authorized"] is True
    assert set(decision["exact_matrix_field_values"]) == MATRIX_MUTABLE_FIELDS
    for field, value in decision["exact_matrix_field_values"].items():
        target[field] = value
    for field, value in format_overrides[target_id].items():
        assert field in {"benchmark_candidates", "selected_open_source_benchmark", "benchmark_url_and_sha"}
        target[field] = value

register_mutations = {item["project"]: item for item in d_decision["register_mutations"]}
assert set(register_mutations) == set(PROJECT_IDS)
big_before = dict(one(register_rows, "project", "bigcapitalhq/bigcapital"))
for project in ("frappe/erpnext", "Dolibarr/dolibarr"):
    mutation = register_mutations[project]
    assert mutation["mutation_authorized"] is True
    target = one(register_rows, "project", project)
    for field, operation in mutation["exact_changed_field_values"].items():
        apply_change(target, field, operation)
trace_additions = p8_review["blocking_corrections"]["register_traceability_additions"]
assert set(trace_additions) == {"frappe/erpnext", "Dolibarr/dolibarr"}
for project, addition in trace_additions.items():
    assert addition["field"] == "exact_behaviour_screen_workflow_inspected"
    assert addition["operation"] == "append_once_after_agent_d_suffix"
    apply_change(one(register_rows, "project", project), addition["field"], {"operation": "append_once", "exact_suffix": addition["exact_suffix"]})
assert register_mutations["bigcapitalhq/bigcapital"]["mutation_authorized"] is False
assert one(register_rows, "project", "bigcapitalhq/bigcapital") == big_before

matrix_after_data = csv_bytes(matrix_fields, matrix_rows)
register_after_data = csv_bytes(register_fields, register_rows)


def delta(before: list[dict[str, str]], after: list[dict[str, str]], key: str) -> tuple[list[str], int, list[dict[str, object]]]:
    before_by = {row[key]: row for row in before}
    after_by = {row[key]: row for row in after}
    assert before_by.keys() == after_by.keys()
    changed_keys: list[str] = []
    changed_cells = 0
    rows: list[dict[str, object]] = []
    for identifier in before_by:
        changes = {
            field: {"before": before_by[identifier][field], "after": after_by[identifier][field]}
            for field in before_by[identifier]
            if before_by[identifier][field] != after_by[identifier][field]
        }
        if changes:
            changed_keys.append(identifier)
            changed_cells += len(changes)
            rows.append({key: identifier, "changed_fields": changes})
    return changed_keys, changed_cells, rows


matrix_changed_keys, matrix_changed_cells, matrix_delta = delta(matrix_before, matrix_rows, "feature_id")
register_changed_keys, register_changed_cells, register_delta = delta(register_before, register_rows, "project")
assert set(matrix_changed_keys) == set(TARGET_IDS)
assert matrix_changed_cells == 18
assert set(register_changed_keys) == {"frappe/erpnext", "Dolibarr/dolibarr"}
assert register_changed_cells == 30
assert sum(row["benchmark_mapping_credit"].lower() == "true" for row in matrix_rows) == 2
assert all(one(matrix_rows, "feature_id", target_id)["no_match_evidence"] == "NCM_NOT_AUTHORIZED_NO_TARGET_SPECIFIC_CATALOGUE_COMPLETE_SEARCH" for target_id in TARGET_IDS)
assert sum(row["current_target_specific_mapping_credit"].lower() == "true" for row in register_rows) == 2
assert one(register_rows, "project", "bigcapitalhq/bigcapital") == big_before
assert all(one(matrix_rows, "feature_id", target_id)["completion_status"] == "INCOMPLETE_CANONICAL_STATIC_IDENTITY_PLUS_BENCHMARK_MAPPING_ONLY" for target_id in TARGET_IDS)

plan = {
    "schema_version": "sealed_run_145_corrected_matrix_register_integration_input_wave_24_v1",
    "run_id": "RUN-145-CORRECTED-MATRIX-REGISTER-INTEGRATION-INPUT-WAVE-24",
    "status": "CORRECTED_POST_MUTATION_BYTES_COMPUTED_NOT_APPLIED_ZERO_ADDITIONAL_CREDIT",
    "generated_on": "2026-08-26",
    "inputs": {"matrix": matrix_seal, "benchmark_register": register_seal, "agent_d_decision": d_seal, "pass_8_correction": p8_seal},
    "proposed_outputs": {
        "matrix": {"path": "03-feature-to-benchmark-matrix.csv", "bytes": len(matrix_after_data), "sha256": sha256(matrix_after_data), "rows": 340, "changed_rows": len(matrix_changed_keys), "changed_cells": matrix_changed_cells},
        "benchmark_register": {"path": "06-open-source-benchmark-register.csv", "bytes": len(register_after_data), "sha256": sha256(register_after_data), "rows": 98, "changed_rows": len(register_changed_keys), "changed_cells": register_changed_cells},
    },
    "matrix_delta": matrix_delta,
    "benchmark_register_delta": register_delta,
    "post_mutation_counts": {"benchmark_mappings": 2, "final_no_matches_or_NCMs": 0, "unresolved_targets": 338, "project_rows_with_current_target_mapping_credit": 2},
    "invariants": {
        "matrix_unaffected_rows_logically_identical": 338,
        "register_unaffected_rows_logically_identical": 96,
        "BigCapital_row_unchanged": True,
        "matrix_header_and_row_order_preserved": True,
        "register_header_and_row_order_preserved": True,
        "utf8_no_BOM_LF_final_newline_csv_quote_minimal": True,
        "NCM_not_authorized": True,
        "application_source_changes": 0,
        "runtime_browser_test_pass_release_completion_final_finding_or_audit_completion_credit": 0,
    },
    "application_status": "NOT_APPLIED_REQUIRES_FRESH_INDEPENDENT_REVIEW_GO",
}

output_bytes = (json.dumps(plan, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
if OUTPUT.exists():
    assert OUTPUT.read_bytes() == output_bytes, f"Refusing to overwrite different bytes: {OUTPUT}"
else:
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_bytes(output_bytes)

print(f"proposed matrix\t{len(matrix_after_data)}\t{sha256(matrix_after_data)}")
print(f"proposed register\t{len(register_after_data)}\t{sha256(register_after_data)}")
print(f"{OUTPUT.relative_to(AUDIT_DIR)}\t{len(output_bytes)}\t{sha256(output_bytes)}")
