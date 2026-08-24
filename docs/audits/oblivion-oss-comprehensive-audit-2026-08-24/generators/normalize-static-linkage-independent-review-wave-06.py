#!/usr/bin/env python3
"""Normalize cyclic independent review of the RUN-074 static-linkage producers."""

from __future__ import annotations

import hashlib
import json
import re
import subprocess
from collections import Counter
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
SENTINEL = "NOT_ESTABLISHED_CURRENT_AUDIT"
PRODUCER_PATH = "evidence/source/current-static-linkage-review-wave-06.json"
RAW_REVIEWS = {
    "RUN-075A": {
        "reviewed_partition_id": "RUN-074B",
        "path": "evidence/source/raw-run-075a-independent-static-linkage-review-wave-06.json",
    },
    "RUN-075B": {
        "reviewed_partition_id": "RUN-074C",
        "path": "evidence/source/raw-run-075b-independent-static-linkage-review-wave-06.json",
    },
    "RUN-075C": {
        "reviewed_partition_id": "RUN-074A",
        "path": "evidence/source/raw-run-075c-independent-static-linkage-review-wave-06.json",
    },
}
OUTPUT = "evidence/source/current-static-linkage-independent-review-wave-06.json"
ANCHOR_RE = re.compile(
    r"^(?P<path>(?:app|routes|resources/js|tests|database|bootstrap)/[^:]+):"
    r"(?P<start>\d+)(?:-(?P<end>\d+))?$"
)
EXPECTED_PREFIX = {
    "route_paths": ("routes/",),
    "page_files": ("resources/js/pages/",),
    "backend_anchors": ("app/", "routes/"),
    "test_anchors": ("tests/",),
}


def read_json(relative: str):
    return json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))


def sha256(relative: str) -> str:
    return hashlib.sha256((AUDIT_DIR / relative).read_bytes()).hexdigest()


line_count_cache: dict[str, int] = {}


def validate_anchor(anchor: str) -> str:
    match = ANCHOR_RE.fullmatch(anchor)
    assert match, anchor
    path = match.group("path")
    start = int(match.group("start"))
    end = int(match.group("end") or start)
    assert 1 <= start <= end, anchor
    if path not in line_count_cache:
        result = subprocess.run(
            ["git", "show", f"{APPLICATION_COMMIT}:{path}"],
            cwd=AUDIT_DIR,
            check=False,
            capture_output=True,
        )
        assert result.returncode == 0, path
        line_count_cache[path] = len(result.stdout.decode("utf-8", errors="replace").splitlines())
    assert end <= line_count_cache[path], (anchor, line_count_cache[path])
    return path


def validate_decision(field: str, decision: dict) -> dict:
    assert decision["status"] in {"ESTABLISHED", "RETAIN_NOT_ESTABLISHED"}
    assert isinstance(decision["rationale"], str) and decision["rationale"].strip()
    assert isinstance(decision["bounded_search"], list)
    assert all(isinstance(item, str) and item.strip() for item in decision["bounded_search"])
    anchors = decision["anchors"]
    assert isinstance(anchors, list)
    assert all(isinstance(anchor, str) and anchor.strip() for anchor in anchors)
    assert len(anchors) == len(set(anchors))
    anchor_paths = [validate_anchor(anchor) for anchor in anchors]
    if decision["status"] == "ESTABLISHED":
        assert isinstance(decision["value"], str)
        assert decision["value"].strip() and decision["value"] != SENTINEL
        assert anchors
        if field in EXPECTED_PREFIX:
            assert all(path.startswith(EXPECTED_PREFIX[field]) for path in anchor_paths), (field, anchor_paths)
            assert decision["value"] == "; ".join(anchors)
        else:
            assert field == "route_names"
            assert all(path.startswith("routes/") for path in anchor_paths)
    else:
        assert decision["value"] in {None, SENTINEL}
        assert decision["bounded_search"]
    return {
        "status": decision["status"],
        "value": decision["value"],
        "anchors": anchors,
        "rationale": decision["rationale"],
        "bounded_search": decision["bounded_search"],
    }


producer_sha = sha256(PRODUCER_PATH)
producer = read_json(PRODUCER_PATH)
assert producer["run_id"] == "RUN-074-STATIC-LINKAGE-NORMALIZATION"
assert producer["pins"]["application_commit"] == APPLICATION_COMMIT
assert producer["counts"]["targets"] == 288
producer_by_partition: dict[str, list[dict]] = {
    partition_id: sorted(
        [row for row in producer["records"] if row["partition_id"] == partition_id],
        key=lambda row: row["matrix_ordinal"],
    )
    for partition_id in ("RUN-074A", "RUN-074B", "RUN-074C")
}
assert {key: len(value) for key, value in producer_by_partition.items()} == {
    "RUN-074A": 96,
    "RUN-074B": 97,
    "RUN-074C": 95,
}

normalized_records: list[dict] = []
review_hashes: dict[str, str] = {PRODUCER_PATH: producer_sha}
reviewer_counts: dict[str, dict] = {}
all_final_anchor_occurrences: list[str] = []

for review_id, assignment in RAW_REVIEWS.items():
    raw_path = assignment["path"]
    reviewed_partition_id = assignment["reviewed_partition_id"]
    raw = read_json(raw_path)
    review_hashes[raw_path] = sha256(raw_path)
    assert raw["run_id"] == review_id
    assert raw["review_id"] == review_id
    assert raw["reviewed_partition_id"] == reviewed_partition_id
    assert raw["pins"]["application_commit"] == APPLICATION_COMMIT
    assert raw["pins"]["normalized_producer_sha256"] == producer_sha

    expected_rows = producer_by_partition[reviewed_partition_id]
    expected_by_id = {row["feature_id"]: row for row in expected_rows}
    records = raw["records"]
    assert len(records) == len(expected_rows)
    assert len({row["feature_id"] for row in records}) == len(records)
    assert {row["feature_id"] for row in records} == set(expected_by_id)

    dispositions: Counter[str] = Counter()
    for record in records:
        expected = expected_by_id[record["feature_id"]]
        assert record["matrix_ordinal"] == expected["matrix_ordinal"]
        field_reviews = record["field_reviews"]
        assert set(field_reviews) == set(expected["decisions"])
        normalized_field_reviews: dict[str, dict] = {}

        for field, review in field_reviews.items():
            disposition = review["disposition"]
            assert disposition in {"ACCEPT", "CORRECT", "REJECT"}
            assert isinstance(review["rationale"], str) and review["rationale"].strip()
            assert isinstance(review["verification"], list) and review["verification"]
            assert all(isinstance(item, str) and item.strip() for item in review["verification"])
            correction = review.get("correction")
            producer_decision = {
                key: expected["decisions"][field][key]
                for key in ("status", "value", "anchors", "rationale", "bounded_search")
            }

            if disposition == "ACCEPT":
                assert correction is None
                final_decision = validate_decision(field, producer_decision)
            elif disposition == "CORRECT":
                assert isinstance(correction, dict)
                final_decision = validate_decision(field, correction)
            else:
                assert correction is None
                final_decision = {
                    "status": "RETAIN_NOT_ESTABLISHED",
                    "value": SENTINEL,
                    "anchors": [],
                    "rationale": "Independent review rejected the producer decision: " + review["rationale"],
                    "bounded_search": list(review["verification"]),
                }

            all_final_anchor_occurrences.extend(final_decision["anchors"])
            dispositions[disposition] += 1
            normalized_field_reviews[field] = {
                "producer_decision": producer_decision,
                "review_disposition": disposition,
                "review_rationale": review["rationale"],
                "verification": review["verification"],
                "final_decision": final_decision,
                "review_credit": True,
                "matrix_credit": False,
            }

        normalized_records.append(
            {
                "review_id": review_id,
                "reviewed_partition_id": reviewed_partition_id,
                "matrix_ordinal": expected["matrix_ordinal"],
                "feature_id": expected["feature_id"],
                "module": expected["module"],
                "submodule": expected["submodule"],
                "original_missing_fields": expected["original_missing_fields"],
                "field_reviews": normalized_field_reviews,
            }
        )

    reviewer_counts[review_id] = {
        "reviewed_partition_id": reviewed_partition_id,
        "targets": len(records),
        "field_decisions": sum(dispositions.values()),
        "dispositions": dict(sorted(dispositions.items())),
    }

assert len(normalized_records) == 288
assert len({row["feature_id"] for row in normalized_records}) == 288
normalized_records.sort(key=lambda row: row["matrix_ordinal"])
aggregate_dispositions = Counter(
    review["review_disposition"]
    for record in normalized_records
    for review in record["field_reviews"].values()
)
final_statuses = Counter(
    review["final_decision"]["status"]
    for record in normalized_records
    for review in record["field_reviews"].values()
)

payload = {
    "schema_version": 1,
    "run_id": "RUN-075-STATIC-LINKAGE-INDEPENDENT-REVIEW",
    "status": "CYCLIC_INDEPENDENT_SOURCE_REVIEW_NORMALIZED_PENDING_MATRIX_INTEGRATION_ZERO_DOWNSTREAM_CREDIT",
    "generated_on": "2026-08-25",
    "pins": {
        "application_commit": APPLICATION_COMMIT,
        "application_tree": "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1",
        "audit_checkpoint_parent": "0d5a05e30878d4c24cb7b83c27e63e8c09b498a3",
        "normalized_producer_sha256": producer_sha,
        "base_matrix_sha256": producer["pins"]["matrix_sha256"],
    },
    "cyclic_independence": {
        "RUN-075A": "reviews RUN-074B, not its own RUN-074A production partition",
        "RUN-075B": "reviews RUN-074C, not its own RUN-074B production partition",
        "RUN-075C": "reviews RUN-074A, not its own RUN-074C production partition",
    },
    "counts": {
        "targets": len(normalized_records),
        "reviewers": reviewer_counts,
        "field_decisions": sum(aggregate_dispositions.values()),
        "review_dispositions": dict(sorted(aggregate_dispositions.items())),
        "final_statuses": dict(sorted(final_statuses.items())),
        "final_anchor_occurrences": len(all_final_anchor_occurrences),
        "final_unique_anchors": len(set(all_final_anchor_occurrences)),
        "final_anchor_paths": len({ANCHOR_RE.fullmatch(anchor).group("path") for anchor in all_final_anchor_occurrences}),
        "invalid_final_anchors": 0,
        "matrix_rows_changed": 0,
    },
    "inputs": review_hashes,
    "records": normalized_records,
    "review_gate": {
        "all_producer_targets_reviewed_by_a_different_agent": True,
        "all_producer_field_decisions_reviewed": True,
        "mechanical_anchor_validation_passed": True,
        "matrix_integration_completed": False,
    },
    "credit_boundary": {
        "independent_static_source_review": True,
        "matrix_static_linkage": False,
        "framework_route_reachability": False,
        "route_page_to_feature_gate": False,
        "runtime": False,
        "browser": False,
        "executed_test": False,
        "benchmark_mapping": False,
        "ease": False,
        "pass": False,
        "completion": False,
    },
    "attestation": "Root-only deterministic normalization of cyclic read-only independent reviews. Application source, runtime, browser, tests, build, database, network and benchmark evidence were not changed or credited.",
}

(AUDIT_DIR / OUTPUT).write_text(
    json.dumps(payload, indent=2, ensure_ascii=False) + "\n",
    encoding="utf-8",
    newline="\n",
)
