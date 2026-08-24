#!/usr/bin/env python3
"""Integrate independently reviewed feature-side static linkage into the matrix."""

from __future__ import annotations

import csv
import hashlib
import io
import json
import subprocess
from collections import Counter
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
REPO_DIR = AUDIT_DIR.parents[2]
AUDIT_RELATIVE = AUDIT_DIR.relative_to(REPO_DIR).as_posix()
BASE_CHECKPOINT = "0d5a05e30878d4c24cb7b83c27e63e8c09b498a3"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
BASE_MATRIX_SHA256 = "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4"
SENTINEL = "NOT_ESTABLISHED_CURRENT_AUDIT"
MATRIX_PATH = "03-feature-to-benchmark-matrix.csv"
REVIEW_PATH = "evidence/source/current-static-linkage-independent-review-wave-06.json"
OUTPUT = "evidence/source/current-static-linkage-integration-wave-06.json"
ALLOWED_FIELDS = {"route_names", "route_paths", "page_files", "backend_anchors", "test_anchors"}
SCOPED_FIELDS = ("route_paths", "page_files", "backend_anchors", "test_anchors")
BENCHMARK_AND_CREDIT_FIELDS = (
    "benchmark_candidates",
    "selected_open_source_benchmark",
    "benchmark_url_and_sha",
    "verified_behaviour",
    "neutral_requirements_extracted",
    "no_match_evidence",
    "current_ease_score",
    "target_ease_score",
    "P1",
    "P2",
    "P3",
    "P4",
    "P5",
    "P6",
    "P7",
    "P8",
    "finding_ids",
    "confidence",
    "feature_class",
    "feature_identity_status",
    "benchmark_mapping_credit",
    "completion_status",
    "evidence_limit",
)


def sha256_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def sha256_file(relative: str) -> str:
    return sha256_bytes((AUDIT_DIR / relative).read_bytes())


def projection_sha256(rows: list[dict[str, str]], columns: list[str]) -> str:
    value = {
        "columns": columns,
        "rows": [[row[column] for column in columns] for row in rows],
    }
    return sha256_bytes(
        json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    )


base_result = subprocess.run(
    ["git", "show", f"{BASE_CHECKPOINT}:{AUDIT_RELATIVE}/{MATRIX_PATH}"],
    cwd=REPO_DIR,
    check=True,
    capture_output=True,
)
base_bytes = base_result.stdout
assert sha256_bytes(base_bytes) == BASE_MATRIX_SHA256
base_text = base_bytes.decode("utf-8-sig")
reader = csv.DictReader(io.StringIO(base_text, newline=""))
fieldnames = reader.fieldnames
assert fieldnames is not None
assert ALLOWED_FIELDS.issubset(fieldnames)
assert set(BENCHMARK_AND_CREDIT_FIELDS).issubset(fieldnames)
base_rows = list(reader)
assert len(base_rows) == 340
assert len({row["feature_id"] for row in base_rows}) == 340
assert Counter(row["feature_class"] for row in base_rows) == {"H": 300, "D": 40}

review_sha = sha256_file(REVIEW_PATH)
review = json.loads((AUDIT_DIR / REVIEW_PATH).read_text(encoding="utf-8"))
assert review["run_id"] == "RUN-075-STATIC-LINKAGE-INDEPENDENT-REVIEW"
assert review["pins"]["application_commit"] == APPLICATION_COMMIT
assert review["pins"]["base_matrix_sha256"] == BASE_MATRIX_SHA256
assert review["counts"]["targets"] == 288
assert review["counts"]["invalid_final_anchors"] == 0
assert review["review_gate"] == {
    "all_producer_targets_reviewed_by_a_different_agent": True,
    "all_producer_field_decisions_reviewed": True,
    "mechanical_anchor_validation_passed": True,
    "matrix_integration_completed": False,
}

review_by_id = {row["feature_id"]: row for row in review["records"]}
assert len(review_by_id) == 288
base_by_id = {row["feature_id"]: row for row in base_rows}
base_ordinal_by_id = {
    row["feature_id"]: ordinal
    for ordinal, row in enumerate(base_rows, start=1)
}
assert set(review_by_id).issubset(base_by_id)

updated_rows = [dict(row) for row in base_rows]
updated_by_id = {row["feature_id"]: row for row in updated_rows}
field_statuses: Counter[tuple[str, str]] = Counter()
field_changes: Counter[str] = Counter()
integration_records: list[dict] = []

for feature_id, reviewed in review_by_id.items():
    base_row = base_by_id[feature_id]
    updated_row = updated_by_id[feature_id]
    assert reviewed["matrix_ordinal"] == base_ordinal_by_id[feature_id]
    original_missing = set(reviewed["original_missing_fields"])
    assert original_missing.issubset(SCOPED_FIELDS)
    assert original_missing.issubset(reviewed["field_reviews"])
    assert set(reviewed["field_reviews"]).issubset(original_missing | {"route_names"})
    integrated_fields: dict[str, dict] = {}

    for field, field_review in reviewed["field_reviews"].items():
        final = field_review["final_decision"]
        assert final["status"] in {"ESTABLISHED", "RETAIN_NOT_ESTABLISHED"}
        assert base_row[field] == SENTINEL
        if final["status"] == "ESTABLISHED":
            assert isinstance(final["value"], str)
            assert final["value"].strip() and final["value"] != SENTINEL
            updated_row[field] = final["value"]
        else:
            assert final["value"] in {None, SENTINEL}
            updated_row[field] = SENTINEL
        changed = updated_row[field] != base_row[field]
        if changed:
            field_changes[field] += 1
        field_statuses[(field, final["status"])] += 1
        integrated_fields[field] = {
            "review_disposition": field_review["review_disposition"],
            "final_status": final["status"],
            "value": updated_row[field],
            "changed": changed,
        }

    integration_records.append(
        {
            "matrix_ordinal": reviewed["matrix_ordinal"],
            "feature_id": feature_id,
            "module": reviewed["module"],
            "integrated_fields": integrated_fields,
        }
    )

assert all(
    base_row[column] == updated_row[column]
    for base_row, updated_row in zip(base_rows, updated_rows, strict=True)
    for column in fieldnames
    if column not in ALLOWED_FIELDS
)
immutable_columns = [column for column in fieldnames if column not in ALLOWED_FIELDS]
base_immutable_projection = projection_sha256(base_rows, immutable_columns)
updated_immutable_projection = projection_sha256(updated_rows, immutable_columns)
assert base_immutable_projection == updated_immutable_projection
base_benchmark_projection = projection_sha256(base_rows, list(BENCHMARK_AND_CREDIT_FIELDS))
updated_benchmark_projection = projection_sha256(updated_rows, list(BENCHMARK_AND_CREDIT_FIELDS))
assert base_benchmark_projection == updated_benchmark_projection
assert all(row["benchmark_mapping_credit"] == "false" for row in updated_rows)

matrix_buffer = io.StringIO(newline="")
writer = csv.DictWriter(matrix_buffer, fieldnames=fieldnames, lineterminator="\n")
writer.writeheader()
writer.writerows(updated_rows)
updated_matrix_bytes = matrix_buffer.getvalue().encode("utf-8")
updated_matrix_sha = sha256_bytes(updated_matrix_bytes)
assert updated_matrix_sha != BASE_MATRIX_SHA256
current_matrix_bytes = (AUDIT_DIR / MATRIX_PATH).read_bytes()
current_matrix_sha = sha256_bytes(current_matrix_bytes)
assert current_matrix_sha in {BASE_MATRIX_SHA256, updated_matrix_sha}, (
    "Refusing to overwrite a matrix outside the pinned base/idempotent output boundary",
    current_matrix_sha,
)
if current_matrix_sha != updated_matrix_sha:
    (AUDIT_DIR / MATRIX_PATH).write_bytes(updated_matrix_bytes)


def gap_ids(field: str) -> list[str]:
    return sorted(row["feature_id"] for row in updated_rows if row[field] == SENTINEL)


route_gap_ids = gap_ids("route_paths")
route_name_gap_ids = gap_ids("route_names")
page_gap_ids = gap_ids("page_files")
backend_gap_ids = gap_ids("backend_anchors")
test_gap_ids = gap_ids("test_anchors")
both_gap_ids = sorted(set(route_gap_ids) & set(page_gap_ids))
any_scoped_gap_ids = sorted(
    row["feature_id"]
    for row in updated_rows
    if any(row[field] == SENTINEL for field in SCOPED_FIELDS)
)
changed_rows = [
    updated["feature_id"]
    for base, updated in zip(base_rows, updated_rows, strict=True)
    if base != updated
]
integration_records.sort(key=lambda row: row["matrix_ordinal"])

payload = {
    "schema_version": 1,
    "run_id": "RUN-076-STATIC-LINKAGE-INTEGRATION",
    "status": "INDEPENDENTLY_REVIEWED_FEATURE_SIDE_STATIC_LINKAGE_INTEGRATED_ZERO_DOWNSTREAM_CREDIT",
    "generated_on": "2026-08-25",
    "pins": {
        "application_commit": APPLICATION_COMMIT,
        "application_tree": "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1",
        "audit_checkpoint_parent": BASE_CHECKPOINT,
        "base_matrix_sha256": BASE_MATRIX_SHA256,
        "independent_review_sha256": review_sha,
    },
    "counts": {
        "canonical_targets": len(updated_rows),
        "reviewed_gap_targets": len(integration_records),
        "matrix_rows_changed": len(changed_rows),
        "matrix_field_changes": sum(field_changes.values()),
        "field_changes": dict(sorted(field_changes.items())),
        "field_final_statuses": {
            f"{field}:{status}": count
            for (field, status), count in sorted(field_statuses.items())
        },
        "remaining_missing_route_paths": len(route_gap_ids),
        "remaining_missing_route_names": len(route_name_gap_ids),
        "remaining_missing_page_files": len(page_gap_ids),
        "remaining_missing_both_route_and_page": len(both_gap_ids),
        "remaining_missing_backend_anchors": len(backend_gap_ids),
        "remaining_missing_test_anchors": len(test_gap_ids),
        "targets_with_any_remaining_scoped_gap": len(any_scoped_gap_ids),
        "benchmark_mapping_credit": 0,
        "runtime_credit": 0,
        "browser_credit": 0,
        "executed_test_credit": 0,
        "pass_credit": 0,
        "completion_credit": 0,
    },
    "matrix": {
        "path": MATRIX_PATH,
        "base_sha256": BASE_MATRIX_SHA256,
        "updated_sha256": updated_matrix_sha,
        "base_immutable_projection_sha256": base_immutable_projection,
        "updated_immutable_projection_sha256": updated_immutable_projection,
        "immutable_columns": immutable_columns,
        "base_benchmark_and_credit_projection_sha256": base_benchmark_projection,
        "updated_benchmark_and_credit_projection_sha256": updated_benchmark_projection,
        "benchmark_and_credit_columns": list(BENCHMARK_AND_CREDIT_FIELDS),
        "row_order_preserved": True,
        "feature_id_denominator_preserved": True,
    },
    "remaining_gaps": {
        "route_paths": route_gap_ids,
        "route_names": route_name_gap_ids,
        "page_files": page_gap_ids,
        "both_route_and_page": both_gap_ids,
        "backend_anchors": backend_gap_ids,
        "test_anchors": test_gap_ids,
        "any_scoped_field": any_scoped_gap_ids,
    },
    "changed_feature_ids": changed_rows,
    "records": integration_records,
    "completion_boundary": {
        "feature_side_static_linkage_cells_integrated": True,
        "all_routes_expanded_and_mapped_to_feature_ids": False,
        "all_page_roots_mapped_to_feature_ids": False,
        "framework_route_reachability": False,
        "runtime": False,
        "application_browser": False,
        "executed_tests": False,
        "benchmark_mapping": False,
        "ease": False,
        "pass_1_to_8": False,
        "audit_complete": False,
    },
    "attestation": "Root-only deterministic integration from the committed RUN-073 base matrix and cyclic independent review. Only route_names, route_paths, page_files, backend_anchors and test_anchors may change. Test locators are static and unexecuted; route/page universe coverage remains open.",
}

(AUDIT_DIR / OUTPUT).write_text(
    json.dumps(payload, indent=2, ensure_ascii=False) + "\n",
    encoding="utf-8",
    newline="\n",
)
