#!/usr/bin/env python3
"""Integrate the read-only review of the 16 RUN-034 partial observer rows.

This overlay preserves RUN-034 byte-for-byte. It records whether each explicit
observer blocker was resolved by official immutable evidence or must remain
partial. It does not neutralize requirements, compare Oblivion source, map a
target, select a benchmark, or award completion credit.
"""

from __future__ import annotations

import csv
import hashlib
import io
import json
import re
from collections import Counter
from pathlib import Path
from typing import Any


AUDIT_DIR = Path(__file__).resolve().parents[1]
GENERATED_AT = "2026-08-24T22:20:00+12:00"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
AUDIT_BASE_COMMIT = "994757baa"
BASE_WAVE = "evidence/benchmark/current-upstream-project-triage-wave-01.json"
BASE_WAVE_SHA256 = "ea0bb6bde44aa8f227d6e4133788e8fcb08c3069e2aecab4e0bc194cee2f3651"
BASE_AGENT_REGISTER = "evidence/benchmark/current-upstream-project-triage-agent-register.json"
BASE_AGENT_REGISTER_SHA256 = "686ae0f32abe1d890ed89228c46bbb8eb0a28b4ff16f91dad31d0b2e34f44811"
BASE_REGISTER_PROJECTION_SHA256 = "84ee80ec568fd55b88721e68eb5682ed1af2eab4fdbe7d7a9a6feb22e476e997"
CANONICAL_MATRIX_SHA256 = "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4"

# Hashes are filled only after the orchestrator materializes the three returned
# read-only evidence packets. The generator refuses to run with placeholders.
RAW_RUNS = {
    35: {
        "file": "evidence/benchmark/raw-run-035-upstream-partial-resolution-partition-01.json",
        "partition": 1,
        "ordinals": [3, 4, 19, 20, 22, 23],
        "sha256": "baaf5056749c26c8f4a251c63738200006f895de6244a87cd72db184f9417ad6",
    },
    36: {
        "file": "evidence/benchmark/raw-run-036-upstream-partial-resolution-partition-02.json",
        "partition": 2,
        "ordinals": [39, 46, 48, 58, 71],
        "sha256": "f74676e9520fe1eeaf68abf3a9c1a62ad9532b0142858ac7b575d4c403c351c8",
    },
    37: {
        "file": "evidence/benchmark/raw-run-037-upstream-partial-resolution-partition-03.json",
        "partition": 3,
        "ordinals": [74, 76, 83, 85, 90],
        "sha256": "c29546b9373ac6354cfe1379648f1afee70fa0fb30aab5a5692df63ff5ac1724",
    },
}

RESOLUTION_FIELDS = [
    "current_partial_resolution_run",
    "current_partial_resolution_status",
    "current_partial_resolution_original_issue_count",
    "current_partial_resolution_resolved_issue_count",
    "current_partial_resolution_residual_issue_count",
    "current_partial_resolution_root_licence_status",
    "current_partial_resolution_root_licence_spdx",
    "current_partial_resolution_edition_boundary_status",
    "current_partial_resolution_evidence_record_sha256",
    "current_effective_observer_triage_status",
    "current_partial_resolution_completion_credit",
    "current_partial_resolution_evidence_limit",
]

GITHUB_IMMUTABLE_LOCUS = re.compile(
    r"^https://github\.com/(?P<repository>[^/]+/[^/]+)/(?:blob|tree)/"
    r"(?P<commit_sha>[0-9a-f]{40})(?:/(?P<path>[^#?]+))?(?P<fragment>[#?].*)?$",
    re.IGNORECASE,
)
BLOCKED_MARKERS = ("BLOCK", "UNRESOLVED", "NOT_ESTABLISHED", "NOASSERTION")


def read_json(relative: str) -> dict[str, Any]:
    return json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))


def write_json(relative: str, payload: object) -> None:
    path = AUDIT_DIR / relative
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(payload, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def sha256_text(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def stable_hash(value: object) -> str:
    raw = json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    return sha256_text(raw)


def csv_text(fieldnames: list[str], rows: list[dict[str, object]]) -> str:
    buffer = io.StringIO(newline="")
    writer = csv.DictWriter(
        buffer,
        fieldnames=fieldnames,
        lineterminator="\n",
        quoting=csv.QUOTE_MINIMAL,
    )
    writer.writeheader()
    writer.writerows(rows)
    return buffer.getvalue()


def write_csv(relative: str, fieldnames: list[str], rows: list[dict[str, object]]) -> None:
    (AUDIT_DIR / relative).write_text(
        csv_text(fieldnames, rows),
        encoding="utf-8",
        newline="\n",
    )


def normalize_project(value: object) -> str:
    assert isinstance(value, str) and value.strip()
    return value.strip().lower()


def assert_nonempty_string(value: object) -> None:
    assert isinstance(value, str) and value.strip()


for contract in RAW_RUNS.values():
    assert not contract["sha256"].startswith("TO_FILL"), "Fill raw evidence hashes before running"

assert sha256_file(AUDIT_DIR / BASE_WAVE) == BASE_WAVE_SHA256
assert sha256_file(AUDIT_DIR / BASE_AGENT_REGISTER) == BASE_AGENT_REGISTER_SHA256
assert sha256_file(AUDIT_DIR / "03-feature-to-benchmark-matrix.csv") == CANONICAL_MATRIX_SHA256

base_wave = read_json(BASE_WAVE)
assert base_wave["run_id"] == "RUN-034"
assert base_wave["counts"]["observer_statuses"] == {
    "COMPLETE_OBSERVER_TRIAGE": 79,
    "PARTIAL_BLOCKED": 16,
}
assert base_wave["credit_boundary"]["completion_credit"] == 0

base_projects = {int(row["ordinal"]): row for row in base_wave["projects"]}
base_partial = {
    ordinal: row
    for ordinal, row in base_projects.items()
    if row["computed_observer_triage_status"] == "PARTIAL_BLOCKED"
}
expected_partial_ordinals = sorted(
    ordinal for contract in RAW_RUNS.values() for ordinal in contract["ordinals"]
)
assert sorted(base_partial) == expected_partial_ordinals
assert len(base_projects) == 95 and len(base_partial) == 16

register_path = AUDIT_DIR / "06-open-source-benchmark-register.csv"
with register_path.open(encoding="utf-8", newline="") as handle:
    reader = csv.DictReader(handle)
    register_fields = list(reader.fieldnames or [])
    register_rows = list(reader)

if RESOLUTION_FIELDS[0] in register_fields:
    resolution_start = register_fields.index(RESOLUTION_FIELDS[0])
    base_register_fields = register_fields[:resolution_start]
    assert register_fields == base_register_fields + RESOLUTION_FIELDS
else:
    base_register_fields = register_fields

base_register_projection = [
    {field: row[field] for field in base_register_fields} for row in register_rows
]
assert sha256_text(csv_text(base_register_fields, base_register_projection)) == BASE_REGISTER_PROJECTION_SHA256
assert len(register_rows) == 98

records_by_ordinal: dict[int, dict[str, Any]] = {}
partition_summaries: list[dict[str, Any]] = []

for run_number, contract in RAW_RUNS.items():
    raw_path = AUDIT_DIR / contract["file"]
    assert sha256_file(raw_path) == contract["sha256"]
    raw = read_json(contract["file"])
    assert raw["schema_version"] == 1
    assert raw["run_id"] == f"RUN-{run_number:03d}"
    assert raw["role"] == "UPSTREAM_PARTIAL_RESOLUTION_OBSERVER"
    assert raw["partition"]["number"] == contract["partition"]
    assert raw["partition"]["ordinals"] == contract["ordinals"]
    assert raw["partition"]["project_count"] == len(contract["ordinals"])
    assert raw["input_pins"]["base_wave_sha256"] == BASE_WAVE_SHA256
    assert raw["input_pins"]["canonical_matrix_sha256"] == CANONICAL_MATRIX_SHA256
    assert raw["external_mutations_attestation"] == "NONE_READ_ONLY_OFFICIAL_SOURCE_REVIEW"
    assert len(raw["results"]) == len(contract["ordinals"])

    partition_decisions: Counter[str] = Counter()
    for raw_record, ordinal in zip(raw["results"], contract["ordinals"]):
        assert raw_record["ordinal"] == ordinal
        baseline = base_partial[ordinal]
        assert normalize_project(raw_record["project"]) == normalize_project(baseline["project"])
        assert raw_record["baseline_observer_record_sha256"] == baseline["raw_record_sha256"]
        assert raw_record["baseline_inspected_ref"] == baseline["inspected_ref"]
        assert raw_record["original_issues"] == baseline["integration_completeness_issues"]

        decision = raw_record["resolution_status"]
        assert decision in {"RESOLVED_OBSERVER_EVIDENCE", "RETAIN_PARTIAL"}
        partition_decisions[decision] += 1
        assert_nonempty_string(raw_record["decision_basis"])
        assert raw_record["target_specific_mapping_credit"] is False
        assert raw_record["benchmark_credit"] is False
        assert raw_record["completion_credit"] is False

        evidence = raw_record["official_evidence"]
        assert isinstance(evidence, list) and evidence
        evidence_ids: set[str] = set()
        for item in evidence:
            assert item["source_type"] == "OFFICIAL_GITHUB_IMMUTABLE"
            assert_nonempty_string(item["evidence_id"])
            assert item["evidence_id"] not in evidence_ids
            evidence_ids.add(item["evidence_id"])
            assert_nonempty_string(item["assertion"])
            match = GITHUB_IMMUTABLE_LOCUS.fullmatch(item["url"])
            assert match, item["url"]
            assert item["commit_sha"].lower() == match.group("commit_sha").lower()
            assert len(item["commit_sha"]) == 40

        dispositions = raw_record["issue_dispositions"]
        assert [item["issue"] for item in dispositions] == raw_record["original_issues"]
        for item in dispositions:
            assert item["disposition"] in {"RESOLVED", "RETAINED"}
            assert_nonempty_string(item["rationale"])
            assert isinstance(item["evidence_refs"], list) and item["evidence_refs"]
            assert set(item["evidence_refs"]).issubset(evidence_ids)

        residual = raw_record["residual_blockers"]
        assert isinstance(residual, list)
        root_licence = raw_record["root_licence_boundary"]
        edition = raw_record["edition_boundary"]
        behaviour = raw_record["behaviour_boundary"]
        for value in (root_licence["status"], root_licence["spdx"], edition["status"], behaviour["status"]):
            assert_nonempty_string(value)
        for value in (root_licence.get("locus"), edition.get("locus"), behaviour.get("locus")):
            if value is not None:
                assert GITHUB_IMMUTABLE_LOCUS.fullmatch(value), value

        if decision == "RESOLVED_OBSERVER_EVIDENCE":
            assert not residual
            assert all(item["disposition"] == "RESOLVED" for item in dispositions)
            assert not any(marker in root_licence["status"].upper() for marker in BLOCKED_MARKERS)
            assert not any(marker in root_licence["spdx"].upper() for marker in BLOCKED_MARKERS)
            assert not any(marker in edition["status"].upper() for marker in BLOCKED_MARKERS)
            assert not any(marker in behaviour["status"].upper() for marker in BLOCKED_MARKERS)
            effective_status = "COMPLETE_OBSERVER_TRIAGE"
        else:
            assert residual
            assert any(item["disposition"] == "RETAINED" for item in dispositions)
            effective_status = "PARTIAL_BLOCKED"

        normalized = {
            **raw_record,
            "project": normalize_project(raw_record["project"]),
            "source_run": f"RUN-{run_number:03d}",
            "effective_observer_triage_status": effective_status,
            "raw_resolution_record_sha256": stable_hash(raw_record),
            "neutral_requirement_credit": False,
            "current_product_comparison_credit": False,
            "final_no_match_credit": False,
            "benchmark_completion_credit": False,
        }
        assert ordinal not in records_by_ordinal
        records_by_ordinal[ordinal] = normalized

    partition_summaries.append(
        {
            "run_id": f"RUN-{run_number:03d}",
            "raw_file": contract["file"],
            "raw_sha256": contract["sha256"],
            "partition": contract["partition"],
            "ordinals": contract["ordinals"],
            "project_count": len(contract["ordinals"]),
            "decisions": dict(sorted(partition_decisions.items())),
        }
    )

assert sorted(records_by_ordinal) == expected_partial_ordinals
records = [records_by_ordinal[ordinal] for ordinal in expected_partial_ordinals]
decision_counts = Counter(record["resolution_status"] for record in records)
effective_counts = Counter(
    records_by_ordinal.get(ordinal, {}).get(
        "effective_observer_triage_status",
        project["computed_observer_triage_status"],
    )
    for ordinal, project in base_projects.items()
)
assert sum(decision_counts.values()) == 16
assert sum(effective_counts.values()) == 95

register_by_project = {
    normalize_project(row["current_canonical_identity"]): row
    for row in register_rows
    if row["current_audit_prompt_denominator_membership"] == "IN_PROMPT_UNIQUE_95"
}
assert len(register_by_project) == 95

output_register_rows: list[dict[str, object]] = []
for source_row in register_rows:
    current = {field: source_row[field] for field in base_register_fields}
    project = normalize_project(source_row["current_canonical_identity"])
    base_project = next(
        (row for row in base_projects.values() if normalize_project(row["project"]) == project),
        None,
    )
    resolution = next(
        (row for row in records if row["project"] == project),
        None,
    )
    if resolution:
        resolved_issue_count = sum(
            item["disposition"] == "RESOLVED" for item in resolution["issue_dispositions"]
        )
        current.update(
            {
                "current_partial_resolution_run": resolution["source_run"],
                "current_partial_resolution_status": resolution["resolution_status"],
                "current_partial_resolution_original_issue_count": len(resolution["original_issues"]),
                "current_partial_resolution_resolved_issue_count": resolved_issue_count,
                "current_partial_resolution_residual_issue_count": len(resolution["residual_blockers"]),
                "current_partial_resolution_root_licence_status": resolution["root_licence_boundary"]["status"],
                "current_partial_resolution_root_licence_spdx": resolution["root_licence_boundary"]["spdx"],
                "current_partial_resolution_edition_boundary_status": resolution["edition_boundary"]["status"],
                "current_partial_resolution_evidence_record_sha256": resolution["raw_resolution_record_sha256"],
                "current_effective_observer_triage_status": resolution["effective_observer_triage_status"],
                "current_partial_resolution_completion_credit": "false",
                "current_partial_resolution_evidence_limit": "Blocker-resolution review only; neutral requirements, current-product comparison, target mapping, benchmark selection, final no-match, runtime, release, and completion credit remain zero.",
            }
        )
    elif base_project:
        current.update(
            {
                "current_partial_resolution_run": "NOT_REQUIRED_RUN_034_COMPLETE",
                "current_partial_resolution_status": "NOT_REQUIRED_ALREADY_COMPLETE_OBSERVER_ONLY",
                "current_partial_resolution_original_issue_count": 0,
                "current_partial_resolution_resolved_issue_count": 0,
                "current_partial_resolution_residual_issue_count": 0,
                "current_partial_resolution_root_licence_status": base_project["root_licence"]["status"],
                "current_partial_resolution_root_licence_spdx": base_project["root_licence"]["spdx"],
                "current_partial_resolution_edition_boundary_status": base_project["edition_boundary"]["status"],
                "current_partial_resolution_evidence_record_sha256": base_project["raw_record_sha256"],
                "current_effective_observer_triage_status": "COMPLETE_OBSERVER_TRIAGE",
                "current_partial_resolution_completion_credit": "false",
                "current_partial_resolution_evidence_limit": "RUN-034 observer evidence only; no downstream credit.",
            }
        )
    else:
        current.update(
            {
                "current_partial_resolution_run": "NOT_IN_PROMPT_DENOMINATOR",
                "current_partial_resolution_status": "NOT_IN_PROMPT_DENOMINATOR",
                "current_partial_resolution_original_issue_count": 0,
                "current_partial_resolution_resolved_issue_count": 0,
                "current_partial_resolution_residual_issue_count": 0,
                "current_partial_resolution_root_licence_status": "NOT_IN_PROMPT_DENOMINATOR",
                "current_partial_resolution_root_licence_spdx": "NOT_IN_PROMPT_DENOMINATOR",
                "current_partial_resolution_edition_boundary_status": "NOT_IN_PROMPT_DENOMINATOR",
                "current_partial_resolution_evidence_record_sha256": "NOT_IN_PROMPT_DENOMINATOR",
                "current_effective_observer_triage_status": "NOT_IN_PROMPT_DENOMINATOR",
                "current_partial_resolution_completion_credit": "false",
                "current_partial_resolution_evidence_limit": "Historical extra outside the exact prompt denominator; no current audit credit.",
            }
        )
    output_register_rows.append(current)

assert len(output_register_rows) == 98
assert all(row["current_partial_resolution_completion_credit"] == "false" for row in output_register_rows)
assert Counter(
    row["current_effective_observer_triage_status"]
    for row in output_register_rows
    if row["current_audit_prompt_denominator_membership"] == "IN_PROMPT_UNIQUE_95"
) == effective_counts

payload = {
    "schema_version": 1,
    "run_id": "RUN-038",
    "status": "ALL_RUN_034_PARTIAL_RECORDS_REVIEWED_NO_DOWNSTREAM_CREDIT",
    "generated_at": GENERATED_AT,
    "source_pin": {
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
        "audit_base_commit": AUDIT_BASE_COMMIT,
        "non_audit_product_diff": 0,
    },
    "inputs": {
        "base_wave": BASE_WAVE,
        "base_wave_sha256": BASE_WAVE_SHA256,
        "base_agent_register_sha256": BASE_AGENT_REGISTER_SHA256,
        "base_register_projection_sha256": BASE_REGISTER_PROJECTION_SHA256,
        "canonical_matrix_guard_sha256": CANONICAL_MATRIX_SHA256,
        "raw_partitions": partition_summaries,
    },
    "counts": {
        "base_partial_records": 16,
        "reviewed_partial_records": len(records),
        "resolution_decisions": dict(sorted(decision_counts.items())),
        "effective_observer_statuses": dict(sorted(effective_counts.items())),
        "formal_upstream_full_triage_credit": 0,
        "neutral_requirement_credit": 0,
        "current_product_comparison_credit": 0,
        "canonical_targets": 340,
        "feature_benchmark_mappings_or_final_no_matches": 0,
    },
    "records": records,
    "credit_boundary": {
        "partial_records_reviewed": 16,
        "upstream_full_triage_credit": 0,
        "neutral_requirement_credit": 0,
        "current_product_comparison_credit": 0,
        "target_specific_mapping_credit": 0,
        "benchmark_completion_credit": 0,
        "runtime_credit": 0,
        "browser_credit": 0,
        "ease_credit": 0,
        "release_credit": 0,
        "completion_credit": 0,
        "audit_complete": False,
    },
}

agent_register = {
    "schema_version": 1,
    "run_id": "RUN-038",
    "status": "THREE_DISJOINT_PARTIAL_RESOLUTION_REVIEWS_INTEGRATED",
    "generated_at": GENERATED_AT,
    "agents": [
        {
            "role": f"upstream_partial_resolution_partition_{summary['partition']}",
            **summary,
            "external_mutations_attestation": "NONE_READ_ONLY_OFFICIAL_SOURCE_REVIEW",
            "target_specific_mapping_credit": False,
            "benchmark_completion_credit": False,
        }
        for summary in partition_summaries
    ],
    "agreement": {
        "disjoint_partial_records_reviewed": 16,
        "remaining_unreviewed_run_034_partial_records": 0,
        "resolution_decisions": dict(sorted(decision_counts.items())),
        "effective_observer_statuses": dict(sorted(effective_counts.items())),
    },
    "credit_boundary": payload["credit_boundary"],
}


def main() -> None:
    matrix_hash_before = sha256_file(AUDIT_DIR / "03-feature-to-benchmark-matrix.csv")
    write_json("evidence/benchmark/current-upstream-partial-resolution-wave-01.json", payload)
    write_json("evidence/benchmark/current-upstream-partial-resolution-agent-register.json", agent_register)
    write_csv(
        "06-open-source-benchmark-register.csv",
        base_register_fields + RESOLUTION_FIELDS,
        output_register_rows,
    )
    matrix_hash_after = sha256_file(AUDIT_DIR / "03-feature-to-benchmark-matrix.csv")
    assert matrix_hash_before == matrix_hash_after == CANONICAL_MATRIX_SHA256
    print(
        json.dumps(
            {
                "status": payload["status"],
                "reviewed_partial_records": len(records),
                "resolution_decisions": dict(sorted(decision_counts.items())),
                "effective_observer_statuses": dict(sorted(effective_counts.items())),
                "feature_benchmark_mappings_or_final_no_matches": 0,
                "canonical_matrix_sha256": matrix_hash_after,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
