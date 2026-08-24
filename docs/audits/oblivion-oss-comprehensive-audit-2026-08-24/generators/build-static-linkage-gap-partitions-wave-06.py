#!/usr/bin/env python3
"""Freeze disjoint RUN-074 source-linkage review partitions.

This script inventories only current sentinel gaps.  It does not change the
canonical matrix or award route, page, backend, test, runtime, browser, Pass or
completion credit.
"""

from __future__ import annotations

import csv
import hashlib
import json
from collections import Counter
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
MATRIX = AUDIT_DIR / "03-feature-to-benchmark-matrix.csv"
OUTPUT = AUDIT_DIR / "evidence/source/root-run-074-static-linkage-gap-partitions-wave-06.json"
MATRIX_SHA256 = "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4"
SENTINEL = "NOT_ESTABLISHED_CURRENT_AUDIT"
FIELDS = ("route_paths", "page_files", "backend_anchors", "test_anchors")

PARTITIONS = {
    "RUN-074A": (
        "Finance", "Health & Safety", "Fleet & Assets", "Control Room",
        "Care & Clinical", "Reporting", "Platform Dashboards", "Compliance",
        "Notifications",
    ),
    "RUN-074B": (
        "Governance", "HR", "Public & Settings Platform", "Sites & Locations",
        "Portal", "Operations", "Workforce",
    ),
    "RUN-074C": (
        "eMAR", "Respite", "Security Devices", "Safeguarding", "Privacy",
        "IT & Support", "Clients", "Catering", "Integrations",
        "Frontline Workspaces", "Roadmap", "Incidents",
    ),
}
EXPECTED_PARTITION_COUNTS = {"RUN-074A": 96, "RUN-074B": 97, "RUN-074C": 95}


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


with MATRIX.open(encoding="utf-8", newline="") as handle:
    rows = list(csv.DictReader(handle))

assert sha256(MATRIX) == MATRIX_SHA256
assert len(rows) == 340
assert Counter(row["feature_class"] for row in rows) == {"H": 300, "D": 40}

module_to_partition = {
    module: partition
    for partition, modules in PARTITIONS.items()
    for module in modules
}
assert len(module_to_partition) == sum(len(modules) for modules in PARTITIONS.values())

gap_rows = [row for row in rows if any(row[field] == SENTINEL for field in FIELDS)]
assert len(gap_rows) == 288
assert Counter(field for row in gap_rows for field in FIELDS if row[field] == SENTINEL) == {
    "route_paths": 120,
    "page_files": 226,
    "backend_anchors": 8,
    "test_anchors": 149,
}
assert all(row["module"] in module_to_partition for row in gap_rows)

partition_rows: dict[str, list[dict]] = {key: [] for key in PARTITIONS}
for ordinal, row in enumerate(rows, start=1):
    if not any(row[field] == SENTINEL for field in FIELDS):
        continue
    partition = module_to_partition[row["module"]]
    partition_rows[partition].append(
        {
            "matrix_ordinal": ordinal,
            "feature_id": row["feature_id"],
            "module": row["module"],
            "submodule": row["submodule"],
            "feature_class": row["feature_class"],
            "missing_fields": [field for field in FIELDS if row[field] == SENTINEL],
            "original_values": {
                "route_names": row["route_names"],
                "route_paths": row["route_paths"],
                "page_files": row["page_files"],
                "backend_anchors": row["backend_anchors"],
                "test_anchors": row["test_anchors"],
            },
        }
    )

assert {key: len(value) for key, value in partition_rows.items()} == EXPECTED_PARTITION_COUNTS
assert len({row["feature_id"] for value in partition_rows.values() for row in value}) == 288

payload = {
    "schema_version": 1,
    "run_id": "RUN-074-STATIC-LINKAGE-PARTITIONS",
    "status": "DISJOINT_SOURCE_REVIEW_PARTITIONS_FROZEN_ZERO_CREDIT",
    "generated_on": "2026-08-25",
    "pins": {
        "application_commit": "a0493442b9e392d324055c35bf25b69421dc2d35",
        "application_tree": "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1",
        "audit_checkpoint_parent": "0d5a05e30878d4c24cb7b83c27e63e8c09b498a3",
        "matrix_sha256": MATRIX_SHA256,
    },
    "architecture_rule": "One operating organisation across multiple Sites; exact Site, action, ownership, consent and privacy boundaries must be proven independently.",
    "fields_in_scope": list(FIELDS),
    "optional_companion_field": "route_names may be supplied only when an exact reviewed route declaration establishes it",
    "counts": {
        "canonical_targets": len(rows),
        "gap_targets": len(gap_rows),
        "targets_without_any_scoped_gap": len(rows) - len(gap_rows),
        "missing_route_paths": sum(row["route_paths"] == SENTINEL for row in rows),
        "missing_page_files": sum(row["page_files"] == SENTINEL for row in rows),
        "missing_backend_anchors": sum(row["backend_anchors"] == SENTINEL for row in rows),
        "missing_test_anchors": sum(row["test_anchors"] == SENTINEL for row in rows),
        "partitions": EXPECTED_PARTITION_COUNTS,
    },
    "partitions": [
        {
            "partition_id": partition,
            "modules": list(PARTITIONS[partition]),
            "target_count": len(partition_rows[partition]),
            "targets": partition_rows[partition],
        }
        for partition in PARTITIONS
    ],
    "credit_boundary": {
        "partition_presence": True,
        "matrix_change": False,
        "route_page_backend_test_credit": False,
        "runtime": False,
        "browser": False,
        "benchmark_mapping": False,
        "ease": False,
        "pass": False,
        "completion": False,
    },
    "attestation": "Deterministic root-only partitioning of existing sentinel gaps; no application, matrix, runtime, browser, tests, build, database, network or VCS state changed by this generator.",
}

OUTPUT.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")
