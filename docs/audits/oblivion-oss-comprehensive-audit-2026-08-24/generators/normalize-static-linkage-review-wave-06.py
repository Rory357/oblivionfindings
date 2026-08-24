#!/usr/bin/env python3
"""Normalize disjoint RUN-074 producer reviews without changing the matrix."""

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
SCOPED_FIELDS = ("route_paths", "page_files", "backend_anchors", "test_anchors")
OPTIONAL_FIELD = "route_names"
PARTITION_MANIFEST = "evidence/source/root-run-074-static-linkage-gap-partitions-wave-06.json"
PARTITION_MANIFEST_SHA256 = "819a31557af4599387e4d226f228e4d94f80883c477ffd3375cc230f538990ab"
RAW_INPUTS = {
    "RUN-074A": "evidence/source/raw-run-074a-static-linkage-review-wave-06.json",
    "RUN-074B": "evidence/source/raw-run-074b-static-linkage-review-wave-06.json",
    "RUN-074C": "evidence/source/raw-run-074c-static-linkage-review-wave-06.json",
}
OUTPUT = "evidence/source/current-static-linkage-review-wave-06.json"
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


assert sha256(PARTITION_MANIFEST) == PARTITION_MANIFEST_SHA256
manifest = read_json(PARTITION_MANIFEST)
assert manifest["counts"]["gap_targets"] == 288
manifest_partitions = {row["partition_id"]: row for row in manifest["partitions"]}
assert set(manifest_partitions) == set(RAW_INPUTS)

normalized_records: list[dict] = []
input_hashes: dict[str, str] = {PARTITION_MANIFEST: PARTITION_MANIFEST_SHA256}
partition_counts: dict[str, dict] = {}
all_anchor_occurrences: list[str] = []

for partition_id, raw_path in RAW_INPUTS.items():
    raw = read_json(raw_path)
    input_hashes[raw_path] = sha256(raw_path)
    partition = manifest_partitions[partition_id]
    expected_by_id = {row["feature_id"]: row for row in partition["targets"]}
    assert raw["run_id"] == partition_id
    assert raw["partition_id"] == partition_id
    assert raw["pins"]["application_commit"] == APPLICATION_COMMIT
    assert raw["pins"]["partition_manifest_sha256"] == PARTITION_MANIFEST_SHA256
    records = raw["records"]
    assert len(records) == partition["target_count"]
    assert len({row["feature_id"] for row in records}) == len(records)
    assert {row["feature_id"] for row in records} == set(expected_by_id)

    field_statuses: Counter[tuple[str, str]] = Counter()
    for record in records:
        expected = expected_by_id[record["feature_id"]]
        assert record["matrix_ordinal"] == expected["matrix_ordinal"]
        if "module" in record:
            assert record["module"] == expected["module"]
        if "submodule" in record:
            assert record["submodule"] == expected["submodule"]
        assert record["original_missing_fields"] == expected["missing_fields"]
        decisions = dict(record["decisions"])
        raw_route_names = record.get("route_names")
        if raw_route_names is not None:
            assert OPTIONAL_FIELD not in decisions
            assert isinstance(raw_route_names, list) and raw_route_names
            assert all(isinstance(name, str) and name.strip() for name in raw_route_names)
            assert len(raw_route_names) == len(set(raw_route_names))
            assert "route_paths" in decisions
            assert decisions["route_paths"]["status"] == "ESTABLISHED"
            route_anchors = [
                anchor
                for anchor in decisions["route_paths"]["anchors"]
                if anchor.startswith("routes/")
            ]
            assert route_anchors
            decisions[OPTIONAL_FIELD] = {
                "status": "ESTABLISHED",
                "value": "; ".join(raw_route_names),
                "anchors": route_anchors,
                "rationale": "Producer supplied exact route names from the same reviewed pinned route declarations.",
                "bounded_search": [],
            }
        assert set(expected["missing_fields"]).issubset(decisions)
        assert set(decisions).issubset(set(expected["missing_fields"]) | {OPTIONAL_FIELD})
        if OPTIONAL_FIELD in decisions:
            assert expected["original_values"][OPTIONAL_FIELD] == SENTINEL

        normalized_decisions: dict[str, dict] = {}
        for field, decision in decisions.items():
            status = decision["status"]
            assert status in {"ESTABLISHED", "RETAIN_NOT_ESTABLISHED"}
            assert isinstance(decision["rationale"], str) and decision["rationale"].strip()
            assert isinstance(decision["bounded_search"], list)
            assert all(isinstance(item, str) and item.strip() for item in decision["bounded_search"])
            anchors = decision["anchors"]
            assert isinstance(anchors, list)
            assert all(isinstance(anchor, str) and anchor.strip() for anchor in anchors)
            assert len(anchors) == len(set(anchors))
            if status == "ESTABLISHED":
                assert isinstance(decision["value"], str)
                assert decision["value"].strip() and decision["value"] != SENTINEL
                assert anchors
                anchor_paths = [validate_anchor(anchor) for anchor in anchors]
                if field in EXPECTED_PREFIX:
                    scoped_anchors = [
                        anchor
                        for anchor, path in zip(anchors, anchor_paths, strict=True)
                        if path.startswith(EXPECTED_PREFIX[field])
                    ]
                    supporting_anchors = [
                        anchor
                        for anchor, path in zip(anchors, anchor_paths, strict=True)
                        if not path.startswith(EXPECTED_PREFIX[field])
                    ]
                    assert scoped_anchors, (field, anchor_paths)
                    normalized_value = "; ".join(scoped_anchors)
                else:
                    assert field == OPTIONAL_FIELD
                    assert all(path.startswith("routes/") for path in anchor_paths)
                    scoped_anchors = anchors
                    supporting_anchors = []
                    normalized_value = decision["value"]
            else:
                assert decision["value"] in {None, SENTINEL}
                assert decision["bounded_search"]
                anchor_paths = [validate_anchor(anchor) for anchor in anchors]
                scoped_anchors = anchors
                supporting_anchors = []
                normalized_value = SENTINEL
            all_anchor_occurrences.extend(anchors)
            field_statuses[(field, status)] += 1
            normalized_decisions[field] = {
                "status": status,
                "value": normalized_value,
                "anchors": scoped_anchors,
                "supporting_anchors": supporting_anchors,
                "producer_value": decision["value"],
                "rationale": decision["rationale"],
                "bounded_search": decision["bounded_search"],
                "producer_credit": False,
            }

        normalized_records.append(
            {
                "partition_id": partition_id,
                "matrix_ordinal": record["matrix_ordinal"],
                "feature_id": record["feature_id"],
                "module": expected["module"],
                "submodule": expected["submodule"],
                "original_missing_fields": record["original_missing_fields"],
                "decisions": normalized_decisions,
            }
        )

    partition_counts[partition_id] = {
        "targets": len(records),
        "field_statuses": {
            f"{field}:{status}": count
            for (field, status), count in sorted(field_statuses.items())
        },
    }

assert len(normalized_records) == 288
assert len({row["feature_id"] for row in normalized_records}) == 288
normalized_records.sort(key=lambda row: row["matrix_ordinal"])

aggregate_statuses = Counter(
    (field, decision["status"])
    for record in normalized_records
    for field, decision in record["decisions"].items()
)
payload = {
    "schema_version": 1,
    "run_id": "RUN-074-STATIC-LINKAGE-NORMALIZATION",
    "status": "PRODUCER_SOURCE_RECONSTRUCTION_NORMALIZED_PENDING_INDEPENDENT_REVIEW_ZERO_CREDIT",
    "generated_on": "2026-08-25",
    "pins": {
        "application_commit": APPLICATION_COMMIT,
        "application_tree": "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1",
        "audit_checkpoint_parent": "0d5a05e30878d4c24cb7b83c27e63e8c09b498a3",
        "matrix_sha256": manifest["pins"]["matrix_sha256"],
        "partition_manifest_sha256": PARTITION_MANIFEST_SHA256,
    },
    "counts": {
        "targets": len(normalized_records),
        "partitions": partition_counts,
        "field_statuses": {
            f"{field}:{status}": count
            for (field, status), count in sorted(aggregate_statuses.items())
        },
        "anchor_occurrences": len(all_anchor_occurrences),
        "unique_anchors": len(set(all_anchor_occurrences)),
        "anchor_paths": len({ANCHOR_RE.fullmatch(anchor).group("path") for anchor in all_anchor_occurrences}),
        "invalid_anchors": 0,
        "matrix_rows_changed": 0,
    },
    "inputs": input_hashes,
    "records": normalized_records,
    "review_gate": {
        "producer_records_normalized": True,
        "independent_semantic_review": False,
        "matrix_integration_authorized": False,
    },
    "credit_boundary": {
        "route_page_backend_test_credit": False,
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
    "attestation": "Root-only deterministic normalization of read-only producer returns. No canonical matrix or application, runtime, browser, tests, build, database, network or VCS state was changed by this generator.",
}

(AUDIT_DIR / OUTPUT).write_text(
    json.dumps(payload, indent=2, ensure_ascii=False) + "\n",
    encoding="utf-8",
    newline="\n",
)
