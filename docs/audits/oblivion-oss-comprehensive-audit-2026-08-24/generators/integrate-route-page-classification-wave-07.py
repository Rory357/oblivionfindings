#!/usr/bin/env python3
"""Integrate independently reviewed RUN-078 static linkage decisions.

The integration is deliberately narrow: exact declared route-name literals and
two page-file decisions may change. Route/page classification records remain
separate source evidence and do not become feature-mapping or execution credit.
"""

from __future__ import annotations

import csv
import hashlib
import io
import json
import os
import subprocess
from collections import Counter
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
REPO_DIR = AUDIT_DIR.parents[2]
AUDIT_RELATIVE = AUDIT_DIR.relative_to(REPO_DIR).as_posix()

CHECKPOINT_COMMIT = "87826adc6fb8c9f0b1ca5ea99dcdc06e32bbd6d0"
CHECKPOINT_TREE = "d1eb36fabc0f5150c81f2140e834347dca87dd25"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
BASE_MATRIX_SHA256 = "00085d407433307e7f6798c0e8e04629b1746d4bfb1e18024c51ead1dc4f7afd"
EXPECTED_UPDATED_MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
PRODUCER_SHA256 = "7b534fea15e6c4ec98fbb2cb0c761e26116b2abee500c39e599b0e779729df97"
REVIEW_SHA256 = "3910255856a757e612b6f6d75522fe394ac19e4e011836c1aedbcfd29eb344be"
BASE_IMMUTABLE_PROJECTION_SHA256 = "cbf86c337280142c7264a10214baaaedb6e13266c57dde159c93fd70315f238f"
BASE_BENCHMARK_AND_CREDIT_PROJECTION_SHA256 = "49f1d00007550a582f5cbdb93726d74fc08d11eb8c98f659f88cbeff7572e945"

MATRIX_PATH = "03-feature-to-benchmark-matrix.csv"
PRODUCER_PATH = "evidence/source/current-route-page-classification-wave-07.json"
REVIEW_PATH = "evidence/source/current-route-page-independent-review-wave-07.json"
OUTPUT_PATH = "evidence/source/current-route-page-static-linkage-integration-wave-07.json"
SENTINEL = "NOT_ESTABLISHED_CURRENT_AUDIT"
GENERATED_ON = "2026-08-25T13:00:00+12:00"

ALLOWED_FIELDS = {"route_names", "route_paths", "page_files", "backend_anchors", "test_anchors"}
RESIDUAL_FIELDS = ("route_paths", "page_files", "backend_anchors", "test_anchors")
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
EXPECTED_CREDIT_BOUNDARY = {
    "route_or_page_presence_as_feature_mapping": False,
    "candidate_overlap_as_feature_mapping": False,
    "framework_route_reachability": False,
    "runtime": False,
    "build": False,
    "application_browser": False,
    "executed_tests": False,
    "benchmark_mapping": False,
    "ease": False,
    "release": False,
    "pass": False,
    "completion": False,
    "audit_complete": False,
}
EXPECTED_ESTABLISHED_RESIDUALS = {
    ("CAP-HR-SCHEDULED-REPORT-EXECUTION", "page_files"): "resources/js/pages/hr/reports/index.tsx",
    ("CAP-INT-INBOUND-PROVIDER-WEBHOOK", "page_files"): "NOT_APPLICABLE",
}


def sha256_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def sha256_file(relative: str) -> str:
    return sha256_bytes((AUDIT_DIR / relative).read_bytes())


def read_json(relative: str) -> dict:
    return json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))


def projection_sha256(rows: list[dict[str, str]], columns: list[str]) -> str:
    projection = {
        "columns": columns,
        "rows": [[row[column] for column in columns] for row in rows],
    }
    return sha256_bytes(
        json.dumps(projection, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    )


def list_sha256(values: list[str]) -> str:
    return sha256_bytes(("\n".join(values) + "\n").encode("utf-8"))


def parse_matrix(value: bytes) -> tuple[list[str], list[dict[str, str]]]:
    assert value.endswith(b"\n") and b"\r\n" not in value
    reader = csv.DictReader(io.StringIO(value.decode("utf-8-sig"), newline=""))
    fieldnames = list(reader.fieldnames or [])
    assert fieldnames and ALLOWED_FIELDS.issubset(fieldnames)
    assert set(BENCHMARK_AND_CREDIT_FIELDS).issubset(fieldnames)
    rows = list(reader)
    assert len(rows) == 340
    assert len({row["feature_id"] for row in rows}) == 340
    assert Counter(row["feature_class"] for row in rows) == {"H": 300, "D": 40}
    assert all(row["benchmark_mapping_credit"] == "false" for row in rows)
    return fieldnames, rows


def render_matrix(fieldnames: list[str], rows: list[dict[str, str]]) -> bytes:
    buffer = io.StringIO(newline="")
    writer = csv.DictWriter(buffer, fieldnames=fieldnames, lineterminator="\n")
    writer.writeheader()
    writer.writerows(rows)
    return buffer.getvalue().encode("utf-8")


def git_bytes(*args: str) -> bytes:
    result = subprocess.run(
        ["git", *args],
        cwd=REPO_DIR,
        check=True,
        capture_output=True,
    )
    return result.stdout


def write_temp(path: Path, value: bytes, label: str) -> Path:
    temp = path.with_name(f".{path.name}.run080-{os.getpid()}-{label}.tmp")
    assert not temp.exists(), f"Refusing to overwrite stale transaction file: {temp}"
    with temp.open("xb") as handle:
        handle.write(value)
        handle.flush()
        os.fsync(handle.fileno())
    return temp


def restore_file(path: Path, previous: bytes | None, label: str) -> None:
    if previous is None:
        if path.exists():
            path.unlink()
        return
    restore = write_temp(path, previous, f"rollback-{label}")
    os.replace(restore, path)


def publish_pair_transactionally(
    matrix_path: Path,
    matrix_bytes: bytes,
    receipt_path: Path,
    receipt_bytes: bytes,
) -> None:
    old_matrix = matrix_path.read_bytes()
    old_receipt = receipt_path.read_bytes() if receipt_path.exists() else None
    if old_matrix == matrix_bytes and old_receipt == receipt_bytes:
        return

    matrix_temp: Path | None = None
    receipt_temp: Path | None = None
    publication_started = False
    try:
        matrix_temp = write_temp(matrix_path, matrix_bytes, "matrix")
        receipt_temp = write_temp(receipt_path, receipt_bytes, "receipt")
        os.replace(matrix_temp, matrix_path)
        publication_started = True
        os.replace(receipt_temp, receipt_path)
        assert matrix_path.read_bytes() == matrix_bytes
        assert receipt_path.read_bytes() == receipt_bytes
    except BaseException:
        if publication_started:
            restore_file(matrix_path, old_matrix, "matrix")
            restore_file(receipt_path, old_receipt, "receipt")
        raise
    finally:
        for temp in (matrix_temp, receipt_temp):
            if temp is not None and temp.exists():
                temp.unlink()


def validate_review(review: dict, review_sha256: str) -> None:
    assert review_sha256 == REVIEW_SHA256
    assert review["schema_version"] == 1
    assert review["run_id"] == "RUN-079-ROUTE-PAGE-INDEPENDENT-REVIEW-NORMALIZATION"
    assert review["status"] == "THREE_PART_CYCLIC_INDEPENDENT_REVIEW_GO_ZERO_DOWNSTREAM_CREDIT"
    assert review["pins"]["checkpoint_commit"] == CHECKPOINT_COMMIT
    assert review["pins"]["checkpoint_tree"] == CHECKPOINT_TREE
    assert review["pins"]["application_commit"] == APPLICATION_COMMIT
    assert review["pins"]["application_tree"] == APPLICATION_TREE
    assert review["pins"]["normalized_producer_sha256"] == PRODUCER_SHA256
    assert review["counts"]["review_artifacts"] == 3
    assert review["counts"]["go_reviews"] == 3
    assert review["counts"]["route_decisions_reviewed"] == 3218
    assert review["counts"]["name_decisions_reviewed"] == 3245
    assert review["counts"]["page_decisions_reviewed"] == 711
    assert review["counts"]["residual_scoped_decisions_reviewed"] == 12
    assert review["counts"]["residual_scoped_cells_reviewed"] == 15
    assert review["counts"]["route_name_gap_decisions_reviewed"] == 244
    assert review["counts"]["invalid_decisions"] == 0
    assert review["counts"]["review_artifacts_wrote_files"] == 0
    assert review["review_gate"] == {
        "all_manifest_producer_and_review_hashes_exact": True,
        "all_producer_pins_exact": True,
        "cyclic_mapping_a_to_b_b_to_c_c_to_a_exact": True,
        "all_three_review_statuses_go": True,
        "all_review_wrote_files_false": True,
        "all_review_write_scopes_empty": True,
        "all_invalid_decision_arrays_empty": True,
        "all_assigned_decisions_reviewed": True,
        "exact_counts_and_pins_match": True,
        "all_credit_boundaries_false": True,
        "independent_cyclic_review_complete": True,
        "static_matrix_field_integration_authorized": True,
        "other_downstream_integration_authorized": False,
    }
    assert review["credit_boundary"] == EXPECTED_CREDIT_BOUNDARY
    assert all(value is False for value in review["completion_boundary"].values())


def main() -> None:
    branch = git_bytes("branch", "--show-current").decode("utf-8").strip()
    head = git_bytes("rev-parse", "HEAD").decode("utf-8").strip()
    tree = git_bytes("rev-parse", "HEAD^{tree}").decode("utf-8").strip()
    assert branch == "main"
    assert head == CHECKPOINT_COMMIT
    assert tree == CHECKPOINT_TREE

    base_bytes = git_bytes(
        "show",
        f"{CHECKPOINT_COMMIT}:{AUDIT_RELATIVE}/{MATRIX_PATH}",
    )
    assert sha256_bytes(base_bytes) == BASE_MATRIX_SHA256
    fieldnames, base_rows = parse_matrix(base_bytes)
    base_by_id = {row["feature_id"]: row for row in base_rows}
    base_ordinal = {row["feature_id"]: index for index, row in enumerate(base_rows, start=1)}

    producer_sha256 = sha256_file(PRODUCER_PATH)
    assert producer_sha256 == PRODUCER_SHA256
    producer = read_json(PRODUCER_PATH)
    assert producer["run_id"] == "RUN-078-ROUTE-PAGE-CLASSIFICATION-NORMALIZATION"
    assert producer["pins"]["checkpoint_commit"] == CHECKPOINT_COMMIT
    assert producer["pins"]["checkpoint_tree"] == CHECKPOINT_TREE
    assert producer["pins"]["application_commit"] == APPLICATION_COMMIT
    assert producer["pins"]["application_tree"] == APPLICATION_TREE
    assert producer["counts"]["route_name_gap_decisions"] == 244
    assert producer["counts"]["route_name_gap_statuses"] == {
        "ESTABLISHED": 78,
        "RETAIN_NOT_ESTABLISHED": 166,
    }
    assert producer["counts"]["residual_scoped_decisions"] == 12
    assert producer["counts"]["residual_field_statuses"] == {
        "ESTABLISHED": 2,
        "RETAIN_NOT_ESTABLISHED": 13,
    }
    assert producer["review_gate"]["integration_authorized"] is False
    assert producer["credit_boundary"] == EXPECTED_CREDIT_BOUNDARY

    review_sha256 = sha256_file(REVIEW_PATH)
    review = read_json(REVIEW_PATH)
    validate_review(review, review_sha256)

    updated_rows = [dict(row) for row in base_rows]
    updated_by_id = {row["feature_id"]: row for row in updated_rows}
    field_changes: Counter[str] = Counter()
    field_statuses: Counter[tuple[str, str]] = Counter()
    route_name_records: list[dict] = []
    residual_records: list[dict] = []

    expected_route_name_ids = {
        row["feature_id"] for row in base_rows if row["route_names"] == SENTINEL
    }
    route_name_decisions = producer["route_name_gap_decisions"]
    assert len(route_name_decisions) == 244
    assert {record["feature_id"] for record in route_name_decisions} == expected_route_name_ids

    for record in route_name_decisions:
        feature_id = record["feature_id"]
        decision = record["route_name_decision"]
        status = decision["status"]
        value = decision["value"]
        assert status in {"ESTABLISHED", "RETAIN_NOT_ESTABLISHED"}
        assert base_by_id[feature_id]["route_names"] == SENTINEL
        if status == "ESTABLISHED":
            assert isinstance(value, str) and value.strip() and value != SENTINEL
            updated_by_id[feature_id]["route_names"] = value
        else:
            assert value == SENTINEL
        changed = updated_by_id[feature_id]["route_names"] != SENTINEL
        if changed:
            field_changes["route_names"] += 1
        field_statuses[("route_names", status)] += 1
        route_name_records.append(
            {
                "matrix_ordinal": base_ordinal[feature_id],
                "partition_id": record["partition_id"],
                "feature_id": feature_id,
                "status": status,
                "value": updated_by_id[feature_id]["route_names"],
                "changed": changed,
                "source_anchors": record["source_anchors"],
                "name_semantics": "DECLARED_LITERAL_ONLY_NO_GROUP_PREFIX_PROPAGATION_OR_EFFECTIVE_RUNTIME_NAME_CLAIM",
            }
        )

    expected_residual_ids = {
        row["feature_id"]
        for row in base_rows
        if any(row[field] == SENTINEL for field in RESIDUAL_FIELDS)
    }
    residual_decisions = producer["residual_scoped_decisions"]
    assert len(residual_decisions) == 12
    assert {record["feature_id"] for record in residual_decisions} == expected_residual_ids
    established_residuals: dict[tuple[str, str], str] = {}
    residual_cells = 0

    for record in residual_decisions:
        feature_id = record["feature_id"]
        expected_fields = {
            field for field in RESIDUAL_FIELDS if base_by_id[feature_id][field] == SENTINEL
        }
        decisions = record["missing_field_decisions"]
        assert set(decisions) == expected_fields
        integrated_fields: dict[str, dict] = {}
        for field in RESIDUAL_FIELDS:
            if field not in decisions:
                continue
            residual_cells += 1
            decision = decisions[field]
            status = decision["status"]
            value = decision["value"]
            assert status in {"ESTABLISHED", "RETAIN_NOT_ESTABLISHED"}
            if status == "ESTABLISHED":
                assert isinstance(value, str) and value.strip() and value != SENTINEL
                established_residuals[(feature_id, field)] = value
                updated_by_id[feature_id][field] = value
            else:
                assert value == SENTINEL
            changed = updated_by_id[feature_id][field] != SENTINEL
            if changed:
                field_changes[field] += 1
            field_statuses[(field, status)] += 1
            integrated_fields[field] = {
                "status": status,
                "value": updated_by_id[feature_id][field],
                "changed": changed,
            }
        residual_records.append(
            {
                "matrix_ordinal": base_ordinal[feature_id],
                "partition_id": record["partition_id"],
                "feature_id": feature_id,
                "integrated_fields": integrated_fields,
                "source_anchors": record["source_anchors"],
            }
        )

    assert residual_cells == 15
    assert established_residuals == EXPECTED_ESTABLISHED_RESIDUALS
    assert field_changes == {"page_files": 2, "route_names": 78}
    assert field_statuses == {
        ("page_files", "ESTABLISHED"): 2,
        ("page_files", "RETAIN_NOT_ESTABLISHED"): 4,
        ("route_names", "ESTABLISHED"): 78,
        ("route_names", "RETAIN_NOT_ESTABLISHED"): 166,
        ("route_paths", "RETAIN_NOT_ESTABLISHED"): 1,
        ("test_anchors", "RETAIN_NOT_ESTABLISHED"): 8,
    }

    assert all(
        base[column] == updated[column]
        for base, updated in zip(base_rows, updated_rows, strict=True)
        for column in fieldnames
        if column not in ALLOWED_FIELDS
    )
    immutable_columns = [column for column in fieldnames if column not in ALLOWED_FIELDS]
    base_immutable_projection = projection_sha256(base_rows, immutable_columns)
    updated_immutable_projection = projection_sha256(updated_rows, immutable_columns)
    assert base_immutable_projection == BASE_IMMUTABLE_PROJECTION_SHA256
    assert updated_immutable_projection == base_immutable_projection
    base_benchmark_projection = projection_sha256(base_rows, list(BENCHMARK_AND_CREDIT_FIELDS))
    updated_benchmark_projection = projection_sha256(updated_rows, list(BENCHMARK_AND_CREDIT_FIELDS))
    assert base_benchmark_projection == BASE_BENCHMARK_AND_CREDIT_PROJECTION_SHA256
    assert updated_benchmark_projection == base_benchmark_projection
    assert [row["feature_id"] for row in updated_rows] == [row["feature_id"] for row in base_rows]
    assert Counter(row["feature_class"] for row in updated_rows) == {"H": 300, "D": 40}
    assert all(row["benchmark_mapping_credit"] == "false" for row in updated_rows)

    updated_matrix_bytes = render_matrix(fieldnames, updated_rows)
    updated_matrix_sha256 = sha256_bytes(updated_matrix_bytes)
    if EXPECTED_UPDATED_MATRIX_SHA256 != "PENDING_FIRST_DETERMINISTIC_RUN":
        assert updated_matrix_sha256 == EXPECTED_UPDATED_MATRIX_SHA256
    current_matrix_sha256 = sha256_file(MATRIX_PATH)
    assert current_matrix_sha256 in {BASE_MATRIX_SHA256, updated_matrix_sha256}, (
        "Refusing to overwrite a matrix outside the pinned base/idempotent output boundary",
        current_matrix_sha256,
    )

    def gap_ids(field: str) -> list[str]:
        return sorted(row["feature_id"] for row in updated_rows if row[field] == SENTINEL)

    route_gap_ids = gap_ids("route_paths")
    route_name_gap_ids = gap_ids("route_names")
    page_gap_ids = gap_ids("page_files")
    backend_gap_ids = gap_ids("backend_anchors")
    test_gap_ids = gap_ids("test_anchors")
    both_gap_ids = sorted(set(route_gap_ids) & set(page_gap_ids))
    any_residual_gap_ids = sorted(
        row["feature_id"]
        for row in updated_rows
        if any(row[field] == SENTINEL for field in RESIDUAL_FIELDS)
    )
    changed_feature_ids = [
        updated["feature_id"]
        for base, updated in zip(base_rows, updated_rows, strict=True)
        if base != updated
    ]
    assert len(changed_feature_ids) == 79
    assert len(route_gap_ids) == 1
    assert len(route_name_gap_ids) == 166
    assert len(page_gap_ids) == 4
    assert len(both_gap_ids) == 1
    assert not backend_gap_ids
    assert len(test_gap_ids) == 8
    assert len(any_residual_gap_ids) == 10

    route_name_records.sort(key=lambda record: record["matrix_ordinal"])
    residual_records.sort(key=lambda record: record["matrix_ordinal"])
    generator_sha256 = sha256_bytes(Path(__file__).read_bytes())
    payload = {
        "schema_version": 1,
        "run_id": "RUN-080-ROUTE-PAGE-STATIC-LINKAGE-INTEGRATION",
        "status": "INDEPENDENTLY_REVIEWED_ROUTE_PAGE_STATIC_LINKAGE_INTEGRATED_ZERO_DOWNSTREAM_CREDIT",
        "generated_on": GENERATED_ON,
        "pins": {
            "checkpoint_commit": CHECKPOINT_COMMIT,
            "checkpoint_tree": CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "base_matrix_sha256": BASE_MATRIX_SHA256,
            "normalized_producer_path": PRODUCER_PATH,
            "normalized_producer_sha256": producer_sha256,
            "independent_review_path": REVIEW_PATH,
            "independent_review_sha256": review_sha256,
            "generator": "generators/integrate-route-page-classification-wave-07.py",
            "generator_sha256": generator_sha256,
        },
        "counts": {
            "canonical_targets": len(updated_rows),
            "canonical_feature_classes": {"H": 300, "D": 40, "M": 0},
            "reviewed_route_name_gap_targets": len(route_name_records),
            "reviewed_residual_targets": len(residual_records),
            "reviewed_residual_cells": residual_cells,
            "matrix_rows_changed": len(changed_feature_ids),
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
            "targets_with_any_remaining_scoped_gap": len(any_residual_gap_ids),
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
            "updated_sha256": updated_matrix_sha256,
            "base_feature_id_order_sha256": list_sha256([row["feature_id"] for row in base_rows]),
            "updated_feature_id_order_sha256": list_sha256([row["feature_id"] for row in updated_rows]),
            "base_immutable_projection_sha256": base_immutable_projection,
            "updated_immutable_projection_sha256": updated_immutable_projection,
            "immutable_columns": immutable_columns,
            "base_benchmark_and_credit_projection_sha256": base_benchmark_projection,
            "updated_benchmark_and_credit_projection_sha256": updated_benchmark_projection,
            "benchmark_and_credit_columns": list(BENCHMARK_AND_CREDIT_FIELDS),
            "row_order_preserved": True,
            "feature_id_denominator_preserved": True,
            "only_permitted_cells_changed": True,
        },
        "remaining_gaps": {
            "route_paths": route_gap_ids,
            "route_names": route_name_gap_ids,
            "page_files": page_gap_ids,
            "both_route_and_page": both_gap_ids,
            "backend_anchors": backend_gap_ids,
            "test_anchors": test_gap_ids,
            "any_residual_field": any_residual_gap_ids,
        },
        "changed_feature_ids": changed_feature_ids,
        "route_name_records": route_name_records,
        "residual_records": residual_records,
        "review_gate": {
            "normalized_producer_exact_hash_valid": True,
            "three_part_cyclic_independent_review_go": True,
            "integration_authorized": True,
            "only_reviewed_established_cells_integrated": True,
        },
        "completion_boundary": {
            "reviewed_static_matrix_cells_integrated": True,
            "route_callsites_classified": False,
            "page_prompts_classified": False,
            "all_routes_expanded_and_mapped_to_feature_ids": False,
            "all_page_roots_mapped_to_feature_ids": False,
            "framework_route_reachability": False,
            "runtime": False,
            "application_browser": False,
            "executed_tests": False,
            "benchmark_mapping": False,
            "final_no_match": False,
            "ease": False,
            "pass_1_to_8": False,
            "completion": False,
            "audit_complete": False,
        },
        "credit_boundary": EXPECTED_CREDIT_BOUNDARY,
        "audit_completion_credit": False,
        "transaction": {
            "publication": "two-file temp-write, fsync, replace, verify, and rollback-on-failure",
            "idempotent_second_run": True,
            "fixed_generated_on": GENERATED_ON,
        },
        "attestation": "Root-only deterministic RUN-080 integration from the exact RUN-077 checkpoint matrix, exact RUN-078 normalized producer, and RUN-079 three-part cyclic GO. Exactly 78 declared-literal route_names and two page_files cells change. All other route/page classifications remain separate evidence; no group-prefix expansion, feature mapping, runtime, browser, executed-test, benchmark, ease, release, Pass, completion, or audit-complete credit is awarded.",
    }
    receipt_bytes = (
        json.dumps(payload, indent=2, ensure_ascii=False) + "\n"
    ).encode("utf-8")
    publish_pair_transactionally(
        AUDIT_DIR / MATRIX_PATH,
        updated_matrix_bytes,
        AUDIT_DIR / OUTPUT_PATH,
        receipt_bytes,
    )


if __name__ == "__main__":
    main()
