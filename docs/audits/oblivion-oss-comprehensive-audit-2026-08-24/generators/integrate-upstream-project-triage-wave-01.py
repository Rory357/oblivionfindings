#!/usr/bin/env python3
"""Integrate the three current upstream-project observer triage partitions.

The raw partitions inspect official upstream sources only. This deterministic
step validates and materializes their project-level evidence without cloning or
executing benchmark code, changing the frozen 340-target matrix, performing a
target-specific comparison, or awarding benchmark/completion credit.
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
GENERATED_AT = "2026-08-24T21:35:00+12:00"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
AUDIT_BASE_COMMIT = "295468a40b376187a251bb10d8c69c6c1f359b1a"
PROJECT_UNIVERSE_SHA256 = "2db4c952e46f4f30fb3b063b8bc6d365a793bf6b3b2c901e589430307db33b1e"
BASE_PROJECT_REGISTER_SHA256 = "eda79bf19ef37ee30204da547b3526536f1988118d63e8e851529952114e2129"
CANONICAL_MATRIX_SHA256 = "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4"

BASE_INPUT_HASHES = {
    "evidence/source/current-canonical-feature-identity-wave-01.json": "f4feae2598622afe346b1163fed2bb842305a8d973a89ec890c02746d99b5999",
    "evidence/source/current-canonical-identity-agent-register.json": "21ebd8b004b5ade11aa01281958cda2be2ca966d1fb7c46576e039fab5f47baf",
    "evidence/benchmark/current-benchmark-wave-01.json": "a024bf1dffbf0608c3aaaa1026d461daf3f29e5c39f9a92882ce41c20b3ec138",
    "evidence/benchmark/current-github-project-metadata-snapshot.json": "7bbbeb263436ecbf6d409e5c1f88c2f3866147045208f551884e8917f0ba7458",
    "evidence/benchmark/current-prompt-project-denominator-reconciliation.json": "51180ed25968ea7dd28bc6bdc39ccc575e3777c8ce210a8a78dd874316d5f5c9",
}

RAW_RUNS = {
    31: {
        "file": "evidence/benchmark/raw-run-031-upstream-project-triage-partition-01.json",
        "repository_count": 32,
        "occurrence_weight": 33,
        "selection_sha256": "081f1591b78f127b9264283b08e9f828c40c58c63c04da9ba8d95ea18c66bf3e",
        "sha256": "e6b8e4c9ef95f546397ea0a4e640840503d24fd42aac09abe688f952313032be",
    },
    32: {
        "file": "evidence/benchmark/raw-run-032-upstream-project-triage-partition-02.json",
        "repository_count": 32,
        "occurrence_weight": 33,
        "selection_sha256": "67130520a61bea1c16be5d65b0972f362850245eb4cae3da6f7ad1127f444c28",
        "sha256": "dc6006728e0ebbaf40febb3139619e9e01f9b6e8a5d46ef12f0667a885f9cc49",
    },
    33: {
        "file": "evidence/benchmark/raw-run-033-upstream-project-triage-partition-03.json",
        "repository_count": 31,
        "occurrence_weight": 32,
        "selection_sha256": "36150aefa2634ec3cb4833f29f03c67a33578953578479fb96c6a233a827935d",
        "sha256": "f58f77c34136c4dfdc93e83aaa741a79ca067c709bbe8d0b15e646bfc7ab3a16",
    },
}

NEW_REGISTER_FIELDS = [
    "current_project_triage_run",
    "current_project_triage_source_status",
    "current_project_triage_status",
    "current_project_triage_inspected_ref",
    "current_project_triage_commit_sha",
    "current_project_triage_observer_metadata_head_sha",
    "current_project_triage_baseline_metadata_head_sha",
    "current_project_triage_metadata_head_relationship",
    "current_project_triage_root_licence_status",
    "current_project_triage_root_licence_spdx",
    "current_project_triage_root_licence_locus",
    "current_project_triage_edition_boundary_status",
    "current_project_triage_maintenance_status",
    "current_project_triage_observed_behaviour_count",
    "current_project_relevance_candidate",
    "current_project_relevance_statement",
    "current_project_triage_record_sha256",
    "current_project_triage_completion_credit",
    "current_project_triage_evidence_limit",
]

RAW_COMPLETE_STATUSES = {
    "COMPLETE_OBSERVER_ONLY",
    "OBSERVED_NO_CREDIT",
    "OBSERVED_WITH_LIMITS_NO_CREDIT",
}
RAW_PARTIAL_STATUSES = {
    "PARTIAL_BLOCKED",
    "PARTIAL_BLOCKED_NO_CREDIT",
}
GITHUB_IMMUTABLE_LOCUS = re.compile(
    r"^https://github\.com/(?P<repository>[^/]+/[^/]+)/(?:blob|tree)/"
    r"(?P<commit_sha>[0-9a-f]{40})(?:/(?P<path>[^#?]+))?(?P<fragment>[#?].*)?$",
    re.IGNORECASE,
)


def read_json(relative: str) -> dict[str, Any]:
    return json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))


def write_json(relative: str, payload: object) -> None:
    path = AUDIT_DIR / relative
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")


def csv_text(fieldnames: list[str], rows: list[dict[str, object]]) -> str:
    buffer = io.StringIO(newline="")
    writer = csv.DictWriter(buffer, fieldnames=fieldnames, lineterminator="\n", quoting=csv.QUOTE_MINIMAL)
    writer.writeheader()
    writer.writerows(rows)
    return buffer.getvalue()


def write_csv(relative: str, fieldnames: list[str], rows: list[dict[str, object]]) -> None:
    (AUDIT_DIR / relative).write_text(csv_text(fieldnames, rows), encoding="utf-8", newline="\n")


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def sha256_text(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def stable_hash(value: object) -> str:
    raw = json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    return hashlib.sha256(raw).hexdigest()


def normalize_project(value: object) -> str:
    assert isinstance(value, str) and value.strip()
    return value.strip().lower()


def evidence_status(value: object) -> str:
    if isinstance(value, dict):
        for key in ("status", "signal", "assessment", "result", "classification"):
            if isinstance(value.get(key), str) and value[key].strip():
                return value[key].strip()
        return "EVIDENCE_RECORDED"
    if isinstance(value, str) and value.strip():
        return "EVIDENCE_RECORDED"
    return "NOT_ESTABLISHED_CURRENT_OBSERVER_TRIAGE"


def status_is_blocked(value: object) -> bool:
    text = evidence_status(value).upper()
    return "PARTIAL" in text or "BLOCK" in text or text in {
        "UNKNOWN",
        "NOT_ESTABLISHED_CURRENT_OBSERVER_TRIAGE",
        "NOT_VERIFIED",
    }


def immutable_locus(value: object) -> dict[str, str] | None:
    if not isinstance(value, str):
        return None
    match = GITHUB_IMMUTABLE_LOCUS.fullmatch(value.strip())
    if not match:
        return None
    return {
        "url": value.strip(),
        "repository": match.group("repository"),
        "commit_sha": match.group("commit_sha").lower(),
        "path": match.group("path") or "",
        "fragment": match.group("fragment") or "",
    }


def credit_value(record: dict[str, Any], direct_keys: tuple[str, ...], nested_key: str) -> object:
    for key in direct_keys:
        if key in record:
            return record[key]
    nested = record.get("credits")
    if isinstance(nested, dict) and nested_key in nested:
        return nested[nested_key]
    return None


def raw_project(record: dict[str, Any]) -> str:
    return normalize_project(record.get("project") or record.get("requested_project"))


def raw_occurrence_weight(record: dict[str, Any]) -> int:
    value = record.get("prompt_occurrence_count", record.get("occurrence_weight"))
    assert isinstance(value, int) and value > 0
    return value


def normalize_root_licence(value: object) -> dict[str, Any]:
    if not isinstance(value, dict):
        return {
            "status": "NOT_ESTABLISHED_CURRENT_OBSERVER_TRIAGE",
            "spdx": "NOT_ESTABLISHED_CURRENT_OBSERVER_TRIAGE",
            "reported_spdx": None,
            "github_api_spdx": None,
            "locus": None,
            "blocker": "Root licence evidence is absent.",
            "raw_source": value,
        }
    spdx = value.get("spdx") or value.get("reported_spdx") or value.get("github_api_spdx")
    return {
        "status": evidence_status(value),
        "spdx": spdx or "NOT_ESTABLISHED_CURRENT_OBSERVER_TRIAGE",
        "reported_spdx": value.get("reported_spdx"),
        "github_api_spdx": value.get("github_api_spdx"),
        "locus": value.get("locus") or value.get("official_url"),
        "blocker": value.get("blocker"),
        "raw_source": value,
    }


def normalize_edition_boundary(value: object) -> dict[str, Any]:
    if isinstance(value, str) and value.strip():
        return {
            "status": "BOUNDED_REPOSITORY_ONLY",
            "statement": value.strip(),
            "locus": None,
            "blocker": None,
        }
    if isinstance(value, dict):
        locus_or_blocker = value.get("locus_or_blocker")
        locus = value.get("locus")
        if not isinstance(locus, str) and isinstance(locus_or_blocker, str) and locus_or_blocker.startswith("https://"):
            locus = locus_or_blocker
        blocker = value.get("blocker")
        if not isinstance(blocker, str) and isinstance(locus_or_blocker, str) and not locus_or_blocker.startswith("https://"):
            blocker = locus_or_blocker
        return {
            "status": evidence_status(value),
            "statement": value.get("statement"),
            "locus": locus if isinstance(locus, str) else None,
            "blocker": blocker if isinstance(blocker, str) else None,
        }
    return {
        "status": "NOT_ESTABLISHED_CURRENT_OBSERVER_TRIAGE",
        "statement": None,
        "locus": None,
        "blocker": "Edition boundary evidence is absent.",
    }


def normalize_maintenance(value: object) -> dict[str, Any]:
    if isinstance(value, str) and value.strip():
        return {
            "status": "SIGNALS_RECORDED_NO_QUALITY_INFERENCE",
            "statement": value.strip(),
            "locus": None,
        }
    if isinstance(value, dict):
        return {
            "status": evidence_status(value),
            "statement": value.get("detail") or value.get("statement"),
            "locus": value.get("locus"),
        }
    return {
        "status": "NOT_ESTABLISHED_CURRENT_OBSERVER_TRIAGE",
        "statement": None,
        "locus": None,
    }


def normalize_behaviours(record: dict[str, Any]) -> list[dict[str, Any]]:
    raw_behaviours: list[dict[str, Any]] = []
    singular = record.get("observed_behaviour")
    if isinstance(singular, dict) and isinstance(singular.get("loci"), list):
        for locus in singular["loci"]:
            raw_behaviours.append(
                {
                    "status": singular.get("status"),
                    "observation": singular.get("statement"),
                    "locus": locus,
                }
            )
    plural = record.get("observed_behaviours")
    if isinstance(plural, list):
        raw_behaviours.extend(item for item in plural if isinstance(item, dict))

    behaviours: list[dict[str, Any]] = []
    for raw in raw_behaviours:
        locus_value = raw.get("locus")
        parsed = immutable_locus(locus_value)
        behaviours.append(
            {
                "status": evidence_status(raw),
                "observation": raw.get("observation") or raw.get("statement"),
                "official_locus": parsed or {"url": locus_value},
            }
        )
    return behaviours


for relative, expected_hash in BASE_INPUT_HASHES.items():
    assert sha256_file(AUDIT_DIR / relative) == expected_hash
assert sha256_file(AUDIT_DIR / "03-feature-to-benchmark-matrix.csv") == CANONICAL_MATRIX_SHA256

canonical = read_json("evidence/source/current-canonical-feature-identity-wave-01.json")
canonical_agents = read_json("evidence/source/current-canonical-identity-agent-register.json")
benchmark_wave = read_json("evidence/benchmark/current-benchmark-wave-01.json")
metadata_snapshot = read_json("evidence/benchmark/current-github-project-metadata-snapshot.json")
assert canonical["counts"]["canonical_targets"] == 340
assert canonical["counts"]["classes"] == {"H": 300, "D": 40, "M": 0}
assert canonical_agents["agreement"]["remaining_identity_conflicts"] == 0
assert benchmark_wave["current_feature_gate"]["verified_benchmark_or_documented_no_credible_match"] == 0
assert metadata_snapshot["result_counts"]["successful_metadata_records"] == 95
assert metadata_snapshot["result_counts"]["failed_records"] == 0
assert metadata_snapshot["denominator"]["unique_prompt_repositories"] == 95
assert metadata_snapshot["denominator"]["occurrence_weight_sum"] == 98

register_path = AUDIT_DIR / "06-open-source-benchmark-register.csv"
with register_path.open(encoding="utf-8", newline="") as handle:
    reader = csv.DictReader(handle)
    register_fields = list(reader.fieldnames or [])
    register_rows = list(reader)

assert len(register_rows) == 98
assert "current_evidence_limit" in register_fields
base_field_end = register_fields.index("current_evidence_limit") + 1
base_fields = register_fields[:base_field_end]
assert register_fields in (base_fields, base_fields + NEW_REGISTER_FIELDS)
base_projection_rows = [{field: row[field] for field in base_fields} for row in register_rows]
assert sha256_text(csv_text(base_fields, base_projection_rows)) == BASE_PROJECT_REGISTER_SHA256

prompt_rows = [row for row in register_rows if row["current_audit_prompt_denominator_membership"] == "IN_PROMPT_UNIQUE_95"]
assert len(prompt_rows) == 95
project_rows = {normalize_project(row["current_canonical_identity"]): row for row in prompt_rows}
project_universe = sorted(project_rows)
assert len(project_rows) == 95
assert sha256_text("\n".join(project_universe)) == PROJECT_UNIVERSE_SHA256
assert sum(int(row["current_prompt_occurrence_count"]) for row in prompt_rows) == 98

metadata_by_project = {normalize_project(record["requested_repository"]): record for record in metadata_snapshot["records"]}
assert set(metadata_by_project) == set(project_universe)

all_records: list[dict[str, Any]] = []
records_by_project: dict[str, dict[str, Any]] = {}
partition_summaries: list[dict[str, Any]] = []
partition_offset = 0

for run_number, contract in RAW_RUNS.items():
    raw_path = AUDIT_DIR / contract["file"]
    assert sha256_file(raw_path) == contract["sha256"]
    raw_payload = read_json(contract["file"])
    assert str(raw_payload["schema_version"]).split(".", 1)[0] == "1"
    assert raw_payload["run_id"] == f"RUN-{run_number:03d}"

    pins = raw_payload["input_pins"]
    assert pins["canonical_identity_json_sha256"] == BASE_INPUT_HASHES["evidence/source/current-canonical-feature-identity-wave-01.json"]
    assert (pins.get("canonical_matrix_sha256") or pins.get("feature_matrix_sha256")) == CANONICAL_MATRIX_SHA256
    assert pins["project_universe_sha256"] == PROJECT_UNIVERSE_SHA256
    if "benchmark_register_sha256" in pins:
        assert pins["benchmark_register_sha256"] == BASE_PROJECT_REGISTER_SHA256
    if "github_metadata_snapshot_sha256" in pins:
        assert pins["github_metadata_snapshot_sha256"] == BASE_INPUT_HASHES["evidence/benchmark/current-github-project-metadata-snapshot.json"]
    if "audit_head_commit" in pins:
        assert pins["audit_head_commit"] == AUDIT_BASE_COMMIT
    if "source_commit" in pins:
        assert pins["source_commit"] == AUDIT_BASE_COMMIT
    if "application_commit" in pins:
        assert pins["application_commit"] == APPLICATION_COMMIT
    if "application_tree" in pins:
        assert pins["application_tree"] == APPLICATION_TREE

    boundary = raw_payload["credit_boundary"]
    assert boundary["canonical_target_mapping_status"] == "NOT_PERFORMED_OBSERVER_ONLY"
    assert boundary.get("target_mapping_credit", boundary.get("mapping_credit")) is False
    assert boundary["benchmark_credit"] is False
    assert boundary["completion_credit"] is False
    for optional_credit in ("runtime_credit", "browser_credit", "test_credit"):
        if optional_credit in boundary:
            assert boundary[optional_credit] is False

    expected_projects = project_universe[partition_offset:partition_offset + contract["repository_count"]]
    expected_ordinals = list(range(partition_offset + 1, partition_offset + contract["repository_count"] + 1))
    partition_offset += contract["repository_count"]
    raw_records = raw_payload["results"]
    assert isinstance(raw_records, list) and len(raw_records) == contract["repository_count"]
    assert [raw_project(record) for record in raw_records] == expected_projects
    assert [record["ordinal"] for record in raw_records] == expected_ordinals
    assert len({raw_project(record) for record in raw_records}) == contract["repository_count"]

    partition = raw_payload["partition"]
    assert (partition.get("number") or partition.get("index")) == run_number - 30
    assert partition["selection_sha256"] == contract["selection_sha256"]
    if "expected_selection_sha256" in partition:
        assert partition["expected_selection_sha256"] == contract["selection_sha256"]
    assert sha256_text("\n".join(expected_projects)) == contract["selection_sha256"]
    reported_count = partition.get("project_count", partition.get("unique_project_count"))
    assert reported_count == contract["repository_count"]
    if "unique_project_count" in partition:
        assert partition["unique_project_count"] == contract["repository_count"]
    reported_weight = partition.get("prompt_occurrence_weight", partition.get("occurrence_weight"))
    assert reported_weight == contract["occurrence_weight"]
    computed_weight = sum(raw_occurrence_weight(record) for record in raw_records)
    register_weight = sum(int(project_rows[project]["current_prompt_occurrence_count"]) for project in expected_projects)
    assert computed_weight == register_weight == contract["occurrence_weight"]

    computed_statuses: Counter[str] = Counter()
    reported_statuses: Counter[str] = Counter()
    head_relationships: Counter[str] = Counter()

    for raw_record in raw_records:
        project = raw_project(raw_record)
        source_register_row = project_rows[project]
        baseline_metadata = metadata_by_project[project]
        assert raw_occurrence_weight(raw_record) == int(source_register_row["current_prompt_occurrence_count"])
        assert raw_record["canonical_url"].rstrip("/").lower() == baseline_metadata["canonical_url"].rstrip("/").lower()
        canonical_repository = raw_record.get("canonical_repository") or baseline_metadata["canonical_repository"]
        assert normalize_project(canonical_repository) == normalize_project(baseline_metadata["canonical_repository"])

        reported_status = raw_record.get("status") or raw_record.get("triage_status")
        assert reported_status in RAW_COMPLETE_STATUSES | RAW_PARTIAL_STATUSES
        is_reported_partial = reported_status in RAW_PARTIAL_STATUSES
        reported_class = "PARTIAL_BLOCKED_REPORTED" if is_reported_partial else "COMPLETE_OBSERVER_REPORTED"
        reported_statuses[reported_class] += 1

        assert raw_record["canonical_target_mapping_status"] == "NOT_PERFORMED_OBSERVER_ONLY"
        assert credit_value(raw_record, ("target_mapping_credit", "mapping_credit"), "mapping") is False
        assert credit_value(raw_record, ("benchmark_credit",), "benchmark") is False
        assert credit_value(raw_record, ("completion_credit",), "completion") is False

        observer_metadata = raw_record["metadata_snapshot"]
        assert isinstance(observer_metadata, dict)
        observer_head = observer_metadata.get("head_sha")
        assert isinstance(observer_head, str) and re.fullmatch(r"[0-9a-f]{40}", observer_head)
        baseline_head = baseline_metadata["default_branch_head_sha"]
        head_relationship = (
            "SAME_AS_BASELINE"
            if observer_head == baseline_head
            else "DIFFERENT_LATER_OBSERVATION_NO_ANCESTRY_INFERENCE"
        )
        head_relationships[head_relationship] += 1

        inspected_raw = raw_record.get("inspected_immutable_ref")
        inspected_ref = inspected_raw if isinstance(inspected_raw, str) else inspected_raw.get("sha") if isinstance(inspected_raw, dict) else None
        assert isinstance(inspected_ref, str) and re.fullmatch(r"[0-9a-f]{40}", inspected_ref)

        root_licence = normalize_root_licence(raw_record.get("root_licence"))
        edition_boundary = normalize_edition_boundary(raw_record.get("edition_boundary"))
        maintenance = normalize_maintenance(raw_record.get("maintenance_release_signals"))
        behaviours = normalize_behaviours(raw_record)
        blockers = raw_record.get("blockers")
        assert isinstance(blockers, list) and all(isinstance(blocker, str) and blocker.strip() for blocker in blockers)
        if is_reported_partial:
            assert blockers

        issues: list[str] = []
        if is_reported_partial:
            issues.append("reported_partial_blocker")
        if observer_metadata.get("archived") is True:
            issues.append("repository_archived")
        licence_status = root_licence["status"].upper()
        licence_spdx = str(root_licence["spdx"]).upper()
        if "PARTIAL" in licence_status or "BLOCK" in licence_status:
            issues.append("root_licence_status_blocked")
        if licence_spdx in {"NOASSERTION", "UNKNOWN", "NONE", "NOT_ESTABLISHED_CURRENT_OBSERVER_TRIAGE"}:
            issues.append("root_licence_spdx_unresolved")
        if immutable_locus(root_licence["locus"]) is None:
            issues.append("root_licence_immutable_locus_missing")
        if status_is_blocked(edition_boundary):
            issues.append("edition_boundary_blocked")
        if not isinstance(edition_boundary["statement"], str) or not edition_boundary["statement"].strip():
            issues.append("edition_boundary_statement_missing")
        if status_is_blocked(maintenance):
            issues.append("maintenance_release_evidence_blocked")
        if not isinstance(maintenance["statement"], str) or not maintenance["statement"].strip():
            issues.append("maintenance_release_evidence_missing")
        if not behaviours:
            issues.append("observed_behaviour_missing")
        else:
            behaviour_commits: set[str] = set()
            for behaviour in behaviours:
                locus = behaviour["official_locus"]
                if set(locus) != {"url", "repository", "commit_sha", "path", "fragment"}:
                    issues.append("immutable_behaviour_locus_incomplete")
                    continue
                behaviour_commits.add(locus["commit_sha"])
                if normalize_project(locus["repository"]) != normalize_project(canonical_repository):
                    issues.append("behaviour_locus_cross_repository")
                if not isinstance(behaviour["observation"], str) or not behaviour["observation"].strip():
                    issues.append("observed_behaviour_statement_missing")
                if status_is_blocked(behaviour):
                    issues.append("observed_behaviour_status_blocked")
            if inspected_ref not in behaviour_commits:
                issues.append("inspected_ref_not_present_in_behaviour_loci")

        computed_status = "COMPLETE_OBSERVER_TRIAGE" if not issues else "PARTIAL_BLOCKED"
        computed_statuses[computed_status] += 1
        relevance_statement = raw_record.get("relevance_candidate")
        assert isinstance(relevance_statement, str) and relevance_statement.strip()
        relevance_candidate = "BLOCKED" if computed_status == "PARTIAL_BLOCKED" else "SEPARATE_FUTURE_DECISION"
        raw_hash = stable_hash(raw_record)
        normalized = {
            "ordinal": raw_record["ordinal"],
            "project": project,
            "canonical_repository": canonical_repository,
            "canonical_url": raw_record["canonical_url"],
            "prompt_occurrence_count": raw_occurrence_weight(raw_record),
            "source_run": f"RUN-{run_number:03d}",
            "reported_observer_status": reported_status,
            "computed_observer_triage_status": computed_status,
            "integration_completeness_issues": sorted(set(issues)),
            "observer_metadata_snapshot": observer_metadata,
            "observer_metadata_head_sha": observer_head,
            "baseline_metadata_head_sha": baseline_head,
            "metadata_head_relationship": head_relationship,
            "source_inspected_immutable_ref_evidence": inspected_raw,
            "inspected_ref": inspected_ref,
            "commit_sha": inspected_ref,
            "root_licence": root_licence,
            "edition_boundary": edition_boundary,
            "maintenance_release_signals": maintenance,
            "observed_behaviours": behaviours,
            "strengths": raw_record.get("strengths"),
            "limitations": raw_record.get("limitations"),
            "project_relevance_candidate": relevance_candidate,
            "project_relevance_statement": relevance_statement,
            "reported_constraints": blockers,
            "canonical_target_mapping_status": "NOT_PERFORMED_OBSERVER_ONLY",
            "target_specific_mapping_credit": False,
            "benchmark_credit": False,
            "completion_credit": False,
            "benchmark_completion_credit": False,
            "raw_record_sha256": raw_hash,
        }
        assert project not in records_by_project
        records_by_project[project] = normalized
        all_records.append(normalized)

    partition_summaries.append(
        {
            "run_id": f"RUN-{run_number:03d}",
            "raw_file": contract["file"],
            "raw_sha256": contract["sha256"],
            "selection_sha256": contract["selection_sha256"],
            "repository_count": contract["repository_count"],
            "occurrence_weight": contract["occurrence_weight"],
            "reported_statuses": dict(sorted(reported_statuses.items())),
            "computed_statuses": dict(sorted(computed_statuses.items())),
            "metadata_head_relationships": dict(sorted(head_relationships.items())),
            "agent_status": raw_payload["status"],
        }
    )

assert partition_offset == 95
assert len(records_by_project) == len(all_records) == 95
assert set(records_by_project) == set(project_universe)
assert sum(summary["occurrence_weight"] for summary in partition_summaries) == 98
all_records.sort(key=lambda record: record["ordinal"])
assert [record["project"] for record in all_records] == project_universe
assert [record["ordinal"] for record in all_records] == list(range(1, 96))

observer_status_counts = Counter(record["computed_observer_triage_status"] for record in all_records)
reported_status_counts = Counter(
    "PARTIAL_BLOCKED_REPORTED"
    if record["reported_observer_status"] in RAW_PARTIAL_STATUSES
    else "COMPLETE_OBSERVER_REPORTED"
    for record in all_records
)
metadata_head_relationship_counts = Counter(record["metadata_head_relationship"] for record in all_records)
assert reported_status_counts == {"COMPLETE_OBSERVER_REPORTED": 79, "PARTIAL_BLOCKED_REPORTED": 16}
assert observer_status_counts == {"COMPLETE_OBSERVER_TRIAGE": 79, "PARTIAL_BLOCKED": 16}
assert metadata_head_relationship_counts == {
    "SAME_AS_BASELINE": 78,
    "DIFFERENT_LATER_OBSERVATION_NO_ANCESTRY_INFERENCE": 17,
}

output_register_rows: list[dict[str, object]] = []
for source_row in register_rows:
    current = dict(source_row)
    project = normalize_project(source_row["current_canonical_identity"])
    record = records_by_project.get(project)
    if record:
        root_licence = record["root_licence"]
        edition_boundary = record["edition_boundary"]
        current.update(
            {
                "current_project_triage_run": record["source_run"],
                "current_project_triage_source_status": record["reported_observer_status"],
                "current_project_triage_status": record["computed_observer_triage_status"],
                "current_project_triage_inspected_ref": record["inspected_ref"],
                "current_project_triage_commit_sha": record["commit_sha"],
                "current_project_triage_observer_metadata_head_sha": record["observer_metadata_head_sha"],
                "current_project_triage_baseline_metadata_head_sha": record["baseline_metadata_head_sha"],
                "current_project_triage_metadata_head_relationship": record["metadata_head_relationship"],
                "current_project_triage_root_licence_status": root_licence["status"],
                "current_project_triage_root_licence_spdx": root_licence["spdx"],
                "current_project_triage_root_licence_locus": root_licence["locus"] or "NOT_ESTABLISHED_CURRENT_OBSERVER_TRIAGE",
                "current_project_triage_edition_boundary_status": edition_boundary["status"],
                "current_project_triage_maintenance_status": record["maintenance_release_signals"]["status"],
                "current_project_triage_observed_behaviour_count": len(record["observed_behaviours"]),
                "current_project_relevance_candidate": record["project_relevance_candidate"],
                "current_project_relevance_statement": record["project_relevance_statement"],
                "current_project_triage_record_sha256": record["raw_record_sha256"],
                "current_project_triage_completion_credit": "false",
                "current_project_triage_evidence_limit": "Upstream observer evidence only; neutral requirement rewriting, current-product comparison, target selection, feature mapping, benchmark equivalence, release, and completion credit remain zero.",
            }
        )
    else:
        current.update(
            {
                "current_project_triage_run": "NOT_IN_PROMPT_OBSERVER_TRIAGE",
                "current_project_triage_source_status": "NOT_IN_PROMPT_OBSERVER_TRIAGE",
                "current_project_triage_status": "NOT_IN_PROMPT_OBSERVER_TRIAGE",
                "current_project_triage_inspected_ref": "NOT_IN_PROMPT_OBSERVER_TRIAGE",
                "current_project_triage_commit_sha": "NOT_IN_PROMPT_OBSERVER_TRIAGE",
                "current_project_triage_observer_metadata_head_sha": "NOT_IN_PROMPT_OBSERVER_TRIAGE",
                "current_project_triage_baseline_metadata_head_sha": "NOT_IN_PROMPT_OBSERVER_TRIAGE",
                "current_project_triage_metadata_head_relationship": "NOT_IN_PROMPT_OBSERVER_TRIAGE",
                "current_project_triage_root_licence_status": "NOT_IN_PROMPT_OBSERVER_TRIAGE",
                "current_project_triage_root_licence_spdx": "NOT_IN_PROMPT_OBSERVER_TRIAGE",
                "current_project_triage_root_licence_locus": "NOT_IN_PROMPT_OBSERVER_TRIAGE",
                "current_project_triage_edition_boundary_status": "NOT_IN_PROMPT_OBSERVER_TRIAGE",
                "current_project_triage_maintenance_status": "NOT_IN_PROMPT_OBSERVER_TRIAGE",
                "current_project_triage_observed_behaviour_count": 0,
                "current_project_relevance_candidate": "NOT_IN_PROMPT_OBSERVER_TRIAGE",
                "current_project_relevance_statement": "NOT_IN_PROMPT_OBSERVER_TRIAGE",
                "current_project_triage_record_sha256": "NOT_IN_PROMPT_OBSERVER_TRIAGE",
                "current_project_triage_completion_credit": "false",
                "current_project_triage_evidence_limit": "Historical extra outside the exact 95-project prompt denominator; no current observer, mapping, benchmark, or completion credit.",
            }
        )
    output_register_rows.append(current)

assert len(output_register_rows) == 98
assert all(
    output[field] == source[field]
    for output, source in zip(output_register_rows, register_rows)
    for field in base_fields
)
assert sum(row["current_project_triage_run"].startswith("RUN-") for row in output_register_rows) == 95
assert all(row["current_target_specific_mapping_credit"] == "false" for row in output_register_rows)
assert all(row["current_project_triage_completion_credit"] == "false" for row in output_register_rows)

payload = {
    "schema_version": 1,
    "run_id": "RUN-034",
    "status": "UPSTREAM_PROJECT_OBSERVER_TRIAGE_INTEGRATED_NO_TARGET_MAPPING_OR_BENCHMARK_CREDIT",
    "generated_at": GENERATED_AT,
    "source_pin": {
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
        "audit_base_commit": AUDIT_BASE_COMMIT,
        "non_audit_product_diff": 0,
    },
    "inputs": {
        "base_sha256": BASE_INPUT_HASHES,
        "base_project_register_projection_sha256": BASE_PROJECT_REGISTER_SHA256,
        "canonical_matrix_guard_sha256": CANONICAL_MATRIX_SHA256,
        "raw_partitions": partition_summaries,
    },
    "project_universe": {
        "repositories": 95,
        "prompt_occurrences": 98,
        "normalization": "Lowercase lexical repository identities, UTF-8 LF, no BOM, no trailing LF",
        "sha256": PROJECT_UNIVERSE_SHA256,
    },
    "counts": {
        "observer_records": 95,
        "reported_statuses": dict(sorted(reported_status_counts.items())),
        "observer_statuses": dict(sorted(observer_status_counts.items())),
        "metadata_head_relationships": dict(sorted(metadata_head_relationship_counts.items())),
        "formal_upstream_full_triage_credit": 0,
        "canonical_targets": 340,
        "feature_benchmark_mappings_or_final_no_matches": 0,
    },
    "projects": all_records,
    "credit_boundary": {
        "observer_project_evidence_materialized": 95,
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
    "run_id": "RUN-034",
    "status": "THREE_DISJOINT_UPSTREAM_OBSERVER_PARTITIONS_INTEGRATED",
    "generated_at": GENERATED_AT,
    "agents": [
        {
            "role": f"upstream_project_observer_partition_{index}",
            **summary,
            "external_mutations_attestation": "NOT_RECORDED_IN_RAW_ARTIFACT",
            "target_specific_mapping_credit": False,
            "benchmark_completion_credit": False,
        }
        for index, summary in enumerate(partition_summaries, 1)
    ],
    "agreement": {
        "disjoint_union_repositories": 95,
        "prompt_occurrence_weight": 98,
        "project_universe_sha256": PROJECT_UNIVERSE_SHA256,
        "remaining_partition_collisions": 0,
    },
    "credit_boundary": payload["credit_boundary"],
}


def main() -> None:
    matrix_hash_before = sha256_file(AUDIT_DIR / "03-feature-to-benchmark-matrix.csv")
    write_json("evidence/benchmark/current-upstream-project-triage-wave-01.json", payload)
    write_json("evidence/benchmark/current-upstream-project-triage-agent-register.json", agent_register)
    write_csv("06-open-source-benchmark-register.csv", base_fields + NEW_REGISTER_FIELDS, output_register_rows)
    matrix_hash_after = sha256_file(AUDIT_DIR / "03-feature-to-benchmark-matrix.csv")
    assert matrix_hash_before == matrix_hash_after == CANONICAL_MATRIX_SHA256
    print(
        json.dumps(
            {
                "status": payload["status"],
                "repositories": len(all_records),
                "observer_statuses": dict(sorted(observer_status_counts.items())),
                "metadata_head_relationships": dict(sorted(metadata_head_relationship_counts.items())),
                "formal_upstream_full_triage_credit": 0,
                "feature_benchmark_mappings_or_final_no_matches": 0,
                "canonical_matrix_sha256": matrix_hash_after,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
