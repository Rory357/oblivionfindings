#!/usr/bin/env python3
"""Materialize source-bound, unmeasured task contracts for the 300 H features."""

from __future__ import annotations

import csv
import hashlib
import io
import json
import os
import re
import stat
from collections import Counter
from pathlib import Path
from typing import Any


AUDIT_DIR = Path(__file__).resolve().parents[1]
REPOSITORY_DIR = AUDIT_DIR.parents[2]
SOURCE_DIR = AUDIT_DIR / "evidence" / "source"
MATRIX_PATH = AUDIT_DIR / "03-feature-to-benchmark-matrix.csv"
IDENTITY_PATH = SOURCE_DIR / "current-canonical-feature-identity-wave-01.json"
INVENTORY_PATH = AUDIT_DIR / "inventory.json"
INSTRUCTIONS_PATH = REPOSITORY_DIR / "AGENTS.md"
GOVERNING_PROMPT_PATH = Path("C:/Users/steph/Downloads/oblivion-open-source-benchmark-and-8-pass-audit-prompt.md")
SCORECARD_PATH = AUDIT_DIR / "04-workflow-usability-scorecard.csv"
TASK_DIR = AUDIT_DIR / "task-scripts"
EVIDENCE_PATH = SOURCE_DIR / "current-usability-task-script-materialization-wave-01.json"

RUN_ID = "RUN-072"
GENERATED_ON = "2026-08-25"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"

NOT_ESTABLISHED = "NOT_ESTABLISHED_CURRENT_AUDIT"
NOT_ADJUDICATED = "NOT_ADJUDICATED_CURRENT_AUDIT"
NOT_SCORED = "NOT_SCORED_CURRENT_AUDIT"
NOT_MEASURED = "NOT_MEASURED"
STATIC_IDENTITY = "STATIC_CANONICAL_IDENTITY_FROZEN"
MATRIX_COMPLETION = "INCOMPLETE_CANONICAL_STATIC_IDENTITY_ONLY"
EVIDENCE_LIMIT = (
    "Static identity and source ownership only; no runtime, browser, executed-test, "
    "benchmark, ease, release, or completion credit."
)
ARCHITECTURE_SCOPE = (
    "ONE_OPERATING_ORGANISATION_MULTIPLE_SITES; "
    "FEATURE_SPECIFIC_ROLE_PERMISSION_SITE_OWNERSHIP_DIRECT_OBJECT_PRIVACY_"
    "BOUNDARY_NOT_ADJUDICATED"
)
PREREQUISITES = (
    "NOT_ESTABLISHED_CURRENT_AUDIT; execution requires a documented representative "
    "role, approved Site/record scope where applicable, and resettable synthetic data."
)

INPUT_SPECS = {
    "matrix": {
        "path": MATRIX_PATH,
        "relative_path": "03-feature-to-benchmark-matrix.csv",
        "bytes": 507_413,
        "sha256": "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4",
    },
    "canonical_identity": {
        "path": IDENTITY_PATH,
        "relative_path": "evidence/source/current-canonical-feature-identity-wave-01.json",
        "bytes": 459_083,
        "sha256": "f4feae2598622afe346b1163fed2bb842305a8d973a89ec890c02746d99b5999",
    },
    "inventory": {
        "path": INVENTORY_PATH,
        "relative_path": "inventory.json",
        "bytes": 2_580_297,
        "sha256": "46cd688dd9543b186a608e950754abe9e30389a792156719f8a999130dfca5fa",
    },
    "governing_prompt": {
        "path": GOVERNING_PROMPT_PATH,
        "relative_path": "C:/Users/steph/Downloads/oblivion-open-source-benchmark-and-8-pass-audit-prompt.md",
        "bytes": 88_305,
        "sha256": "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f",
    },
    "repository_instructions": {
        "path": INSTRUCTIONS_PATH,
        "relative_path": "AGENTS.md",
        "bytes": 1_081,
        "sha256": "2972a5612c834f4745010658aeaa2cd4640d4d7a29d932e9952e406c790718ed",
    },
}

EXPECTED_H_ID_SET_SHA256 = "f25d75f69579ef831d4fb69d012b264cbc5503595b57e5cde9bbc52731d7074f"
EXPECTED_H_TASK_INPUT_PROJECTION_SHA256 = "2304837b5b3c363f9ade39637304e7064fa3491ca305dec2464d5a796c7bb892"
EXPECTED_H_BOTH_ROUTE_PAGE_GAP_SHA256 = "00e6c3c882fe98e218986929c416ba38d1f4c7929ed8d8c54ff6df6b99d21e24"

MATRIX_FIELDS = [
    "feature_id", "module", "submodule", "owning_actor", "secondary_actors",
    "user_job", "criticality", "navigation_entry", "route_names", "route_paths",
    "page_files", "backend_anchors", "current_states", "current_workflow_summary",
    "benchmark_candidates", "selected_open_source_benchmark", "benchmark_url_and_sha",
    "verified_behaviour", "neutral_requirements_extracted", "no_match_evidence",
    "current_ease_score", "target_ease_score", "P1", "P2", "P3", "P4", "P5",
    "P6", "P7", "P8", "finding_ids", "confidence", "feature_class",
    "feature_identity_status", "test_anchors", "benchmark_mapping_credit",
    "completion_status", "evidence_limit",
]

DIMENSIONS = [
    "discoverability", "comprehension", "learnability", "efficiency",
    "error_prevention", "recovery", "accessibility", "safety_and_trust",
    "consistency", "cross_module_continuity",
]
TARGET_DIMENSION_FIELDS = [f"target_{name}" for name in DIMENSIONS]
FRICTION_FIELDS = [
    "completion_time", "step_count", "required_field_count", "decision_count",
    "context_switches", "dead_ends",
]

# The historical 32-column prefix is retained structurally. Four explicit
# matrix-provenance fields make this current scorecard exactly 78 columns.
SCORECARD_FIELDS = [
    "task_script_id", "feature_id", "module", "actor", "task", "start_condition",
    "goal", "prerequisites", "observed_or_inferred", "validation_status",
    "score_measurement_status", "score_scale", "discoverability", "comprehension",
    "learnability", "efficiency", "error_prevention", "recovery", "accessibility",
    "safety_and_trust", "consistency", "cross_module_continuity", "completion_time",
    "step_count", "required_field_count", "decision_count", "context_switches",
    "dead_ends", "recovery_path", "target_scores", "independent_review", "finding_ids",
    "target_discoverability", "target_comprehension", "target_learnability",
    "target_efficiency", "target_error_prevention", "target_recovery",
    "target_accessibility", "target_safety_and_trust", "target_consistency",
    "target_cross_module_continuity", "task_success", "feature_class", "submodule",
    "matrix_owning_actor", "matrix_secondary_actors", "user_job", "criticality",
    "matrix_current_ease_score", "matrix_target_ease_score", "feature_identity_status",
    "matrix_completion_status", "risk_adjudication_status", "safety_adjudication_status",
    "high_risk_alternative_status", "architecture_scope", "navigation_entry",
    "route_names", "route_paths", "page_files", "backend_anchors", "test_anchors",
    "current_states", "current_workflow_summary", "entry_point_status",
    "source_anchor_status", "task_script_path", "application_source_commit",
    "application_source_tree", "source_matrix_sha256", "canonical_identity_sha256",
    "representative_role_execution", "browser_observation", "executed_test_evidence",
    "ease_credit", "completion_credit", "evidence_limit",
]

COPIED_H_FIELDS = [
    "feature_id", "module", "submodule", "owning_actor", "secondary_actors",
    "user_job", "criticality", "navigation_entry", "route_names", "route_paths",
    "page_files", "backend_anchors", "test_anchors", "current_states",
    "current_workflow_summary", "current_ease_score", "target_ease_score",
    "finding_ids", "feature_identity_status", "completion_status", "evidence_limit",
]
ANCHOR_FIELDS = ["navigation_entry", "route_paths", "page_files", "backend_anchors", "test_anchors"]
ID_PATTERN = re.compile(r"^CAP-[A-Z0-9]+(?:-[A-Z0-9]+)*$")
WINDOWS_RESERVED_STEMS = {
    "CON", "PRN", "AUX", "NUL",
    *(f"COM{number}" for number in range(1, 10)),
    *(f"LPT{number}" for number in range(1, 10)),
}
REPARSE_ATTRIBUTE = getattr(stat, "FILE_ATTRIBUTE_REPARSE_POINT", 0x400)


class MaterializationError(RuntimeError):
    """A fail-closed materialization error."""


def require(condition: bool, message: str) -> None:
    if not condition:
        raise MaterializationError(message)


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def digest_sorted_lines(lines: list[str]) -> str:
    return sha256_bytes("\n".join(sorted(lines)).encode("utf-8"))


def path_lexists(path: Path) -> bool:
    return os.path.lexists(str(path))


def is_reparse_point(path: Path) -> bool:
    try:
        path_stat = path.lstat()
    except FileNotFoundError:
        return False
    attributes = getattr(path_stat, "st_file_attributes", 0)
    return path.is_symlink() or bool(attributes & REPARSE_ATTRIBUTE)


def verify_regular_input(path: Path, label: str) -> None:
    require(path_lexists(path), f"{label} is missing: {path}")
    require(not is_reparse_point(path), f"{label} is a symlink or reparse point: {path}")
    require(path.is_file(), f"{label} is not a regular file: {path}")


def verify_pinned_inputs() -> dict[str, bytes]:
    payloads: dict[str, bytes] = {}
    for label, spec in INPUT_SPECS.items():
        path = spec["path"]
        require(isinstance(path, Path), f"Invalid configured path for {label}")
        verify_regular_input(path, label)
        data = path.read_bytes()
        require(len(data) == spec["bytes"], f"{label} byte count drifted")
        require(sha256_bytes(data) == spec["sha256"], f"{label} SHA-256 drifted")
        require(not data.startswith(b"\xef\xbb\xbf"), f"{label} unexpectedly has a BOM")
        payloads[label] = data
    return payloads


def decode_json(data: bytes, label: str) -> dict[str, Any]:
    try:
        value = json.loads(data.decode("utf-8", errors="strict"))
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise MaterializationError(f"Cannot decode {label}: {exc}") from exc
    require(isinstance(value, dict), f"{label} top-level value must be an object")
    return value


def parse_matrix(data: bytes) -> list[dict[str, str]]:
    try:
        text = data.decode("utf-8", errors="strict")
    except UnicodeDecodeError as exc:
        raise MaterializationError(f"Matrix is not valid UTF-8: {exc}") from exc
    reader = csv.DictReader(io.StringIO(text, newline=""))
    require(reader.fieldnames == MATRIX_FIELDS, "Matrix header differs from the exact 38-column contract")
    rows = list(reader)
    require(len(rows) == 340, "Matrix must contain exactly 340 rows")
    for index, row in enumerate(rows, start=2):
        require(None not in row, f"Matrix row {index} has an unbound extra column")
        require(set(row) == set(MATRIX_FIELDS), f"Matrix row {index} field set drifted")
        require(all(isinstance(value, str) for value in row.values()), f"Matrix row {index} has a non-string cell")
    return rows


def established(value: str) -> bool:
    return bool(value) and value != NOT_ESTABLISHED and not value.startswith("NOT_")


def entry_status(row: dict[str, str]) -> str:
    has_route = established(row["route_paths"])
    has_page = established(row["page_files"])
    if has_route and has_page:
        return "ROUTE_AND_PAGE_SOURCE_ANCHORS_PRESENT_UNVALIDATED"
    if has_route:
        return "ROUTE_SOURCE_ANCHOR_ONLY_UNVALIDATED"
    if has_page:
        return "PAGE_SOURCE_ANCHOR_ONLY_UNVALIDATED"
    return "NOT_ESTABLISHED_CURRENT_AUDIT_NO_ROUTE_OR_PAGE_ANCHOR"


def validate_inventory(inventory: dict[str, Any]) -> None:
    require(inventory.get("schema_version") == "0.1-current-source-census", "Inventory schema drifted")
    require(inventory.get("audit_status") == "IN_PROGRESS_NOT_COMPREHENSIVE_OR_COMPLETE", "Inventory status drifted")
    require(inventory.get("application_source_commit") == APPLICATION_COMMIT, "Inventory commit drifted")
    require(inventory.get("application_source_tree") == APPLICATION_TREE, "Inventory tree drifted")
    require(
        inventory.get("architecture_rule")
        == "Single tenant, multiple Sites; assess Site, role, ownership, direct-object concealment, and privacy boundaries, not tenant isolation.",
        "Inventory architecture boundary drifted",
    )
    require(
        inventory.get("credit_boundary")
        == {
            "static_source_census": True,
            "semantic_feature_classification_complete": False,
            "runtime_routes_executed": False,
            "tests_executed": False,
            "browser_credit_from_this_generator": False,
            "benchmark_credit_from_this_generator": False,
        },
        "Inventory credit boundary drifted",
    )


def validate_identity(identity: dict[str, Any]) -> dict[str, dict[str, Any]]:
    require(identity.get("schema_version") == 1, "Canonical identity schema drifted")
    require(identity.get("status") == "STATIC_CANONICAL_FEATURE_IDENTITY_FROZEN_AUDIT_INCOMPLETE", "Canonical identity status drifted")
    require(
        identity.get("source_pin")
        == {
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "audit_input_commit": "9a50423dbc35e4d49ae6290d1fd90ba5e75e4fde",
            "non_audit_product_diff": 0,
        },
        "Canonical identity source pin drifted",
    )
    counts = identity.get("counts")
    require(isinstance(counts, dict), "Canonical identity counts must be an object")
    require(counts.get("canonical_targets") == 340, "Canonical target count drifted")
    require(counts.get("classes") == {"H": 300, "D": 40, "M": 0}, "Identity class counts drifted")
    require(
        identity.get("completion_gate")
        == {
            "canonical_static_identity_frozen": True,
            "runtime_credit": 0,
            "browser_credit": 0,
            "test_execution_credit": 0,
            "benchmark_credit": 0,
            "ease_credit": 0,
            "release_credit": 0,
            "completion_credit": 0,
            "audit_complete": False,
        },
        "Canonical identity completion gate drifted",
    )
    targets = identity.get("targets")
    require(isinstance(targets, list) and len(targets) == 340, "Canonical identity target denominator drifted")
    target_map: dict[str, dict[str, Any]] = {}
    required = {
        "feature_id", "feature_class", "module", "user_job", "canonical_owner",
        "anchors", "identity_status", "runtime_credit", "browser_credit",
        "test_execution_credit", "benchmark_credit", "ease_credit", "completion_credit",
    }
    for index, target in enumerate(targets):
        require(isinstance(target, dict) and required.issubset(target), f"Identity target {index} is invalid")
        feature_id = target["feature_id"]
        require(isinstance(feature_id, str) and feature_id not in target_map, f"Duplicate or invalid target {feature_id}")
        require(isinstance(target["anchors"], list) and all(isinstance(anchor, str) for anchor in target["anchors"]), f"Invalid anchors for {feature_id}")
        require(target["identity_status"] == STATIC_IDENTITY, f"Identity status drifted for {feature_id}")
        for credit in ("runtime_credit", "browser_credit", "test_execution_credit", "benchmark_credit", "ease_credit", "completion_credit"):
            require(target[credit] == 0, f"Unexpected {credit} for {feature_id}")
        target_map[feature_id] = target
    return target_map


def validate_cell(value: str, field: str, feature_id: str) -> None:
    require(value == value.strip(), f"{feature_id}.{field} has outer whitespace")
    require(not any(ord(character) < 32 or ord(character) == 127 for character in value), f"{feature_id}.{field} has a control character")
    require("`" not in value, f"{feature_id}.{field} contains a backtick")
    require(not value.startswith(("=", "+", "-", "@")), f"{feature_id}.{field} is unsafe for a spreadsheet")


def validate_matrix_and_reconcile(
    rows: list[dict[str, str]], target_map: dict[str, dict[str, Any]]
) -> tuple[list[dict[str, str]], Counter[str], list[str]]:
    matrix_ids = [row["feature_id"] for row in rows]
    require(len(set(matrix_ids)) == 340 and set(matrix_ids) == set(target_map), "Matrix and identity ID sets differ")
    require(Counter(row["feature_class"] for row in rows) == Counter({"H": 300, "D": 40}), "Matrix class counts drifted")
    for row in rows:
        feature_id = row["feature_id"]
        target = target_map[feature_id]
        for matrix_field, target_field in {
            "feature_class": "feature_class", "module": "module", "user_job": "user_job",
            "owning_actor": "canonical_owner", "feature_identity_status": "identity_status",
        }.items():
            require(row[matrix_field] == target[target_field], f"Identity mismatch for {feature_id}.{matrix_field}")
        require(row["criticality"] == NOT_ADJUDICATED, f"{feature_id} criticality drifted")
        require(row["current_ease_score"] == NOT_SCORED, f"{feature_id} current ease drifted")
        require(row["target_ease_score"] == NOT_SCORED, f"{feature_id} target ease drifted")
        require(row["benchmark_mapping_credit"] == "false", f"{feature_id} benchmark credit drifted")
        require(row["completion_status"] == MATRIX_COMPLETION, f"{feature_id} completion status drifted")
        require(row["confidence"] == "STATIC_IDENTITY_RECONCILED", f"{feature_id} confidence drifted")
        require(row["evidence_limit"] == EVIDENCE_LIMIT, f"{feature_id} evidence limit drifted")

    h_rows = sorted((row for row in rows if row["feature_class"] == "H"), key=lambda row: row["feature_id"])
    require(len(h_rows) == 300 and len({row["user_job"] for row in h_rows}) == 300, "H denominator or user-job uniqueness drifted")
    require(len({row["module"] for row in h_rows}) == 29, "H module denominator drifted")
    for row in h_rows:
        feature_id = row["feature_id"]
        require(ID_PATTERN.fullmatch(feature_id) is not None, f"Unsafe feature ID: {feature_id}")
        require(row["submodule"] == "CANONICAL_STATIC_IDENTITY_DENOMINATOR", f"{feature_id} submodule drifted")
        require(row["secondary_actors"] == NOT_ESTABLISHED, f"{feature_id} secondary actors drifted")
        require(row["route_names"] == NOT_ESTABLISHED, f"{feature_id} route names drifted")
        require(row["current_states"] == NOT_ESTABLISHED, f"{feature_id} states drifted")
        require(row["finding_ids"] == "NOT_LINKED_TO_CANONICAL_TARGET_CURRENT_AUDIT", f"{feature_id} finding linkage drifted")
        require(row["P1"] == STATIC_IDENTITY, f"{feature_id} P1 status drifted")
        for field in COPIED_H_FIELDS:
            validate_cell(row[field], field, feature_id)
        require(any(established(row[field]) for field in ANCHOR_FIELDS), f"{feature_id} lacks every source anchor")
        filename = f"{feature_id.lower()}.md"
        require(Path(filename).stem.upper() not in WINDOWS_RESERVED_STEMS, f"Reserved Windows filename: {filename}")
        require(not any(character in filename for character in '<>:"/\\|?*'), f"Unsafe Windows filename: {filename}")

    filenames = [f"{row['feature_id'].lower()}.md" for row in h_rows]
    require(len({filename.casefold() for filename in filenames}) == 300, "Case-insensitive task filename collision")
    require(min(len(row["feature_id"]) for row in h_rows) == 16, "Minimum H ID length drifted")
    require(max(len(row["feature_id"]) for row in h_rows) == 44, "Maximum H ID length drifted")
    require(digest_sorted_lines([row["feature_id"] for row in h_rows]) == EXPECTED_H_ID_SET_SHA256, "H ID set hash drifted")
    projection_fields = [
        "feature_id", "module", "owning_actor", "user_job", "navigation_entry",
        "route_names", "route_paths", "page_files", "backend_anchors", "test_anchors",
    ]
    projection_lines = ["|".join(row[field] for field in projection_fields) for row in h_rows]
    require(digest_sorted_lines(projection_lines) == EXPECTED_H_TASK_INPUT_PROJECTION_SHA256, "H task projection hash drifted")
    for field, expected in {
        "navigation_entry": 9, "route_names": 0, "route_paths": 196,
        "page_files": 99, "backend_anchors": 292, "test_anchors": 166,
    }.items():
        require(sum(established(row[field]) for row in h_rows) == expected, f"H {field} count drifted")
    entry_counts = Counter(entry_status(row) for row in h_rows)
    require(
        entry_counts == Counter({
            "ROUTE_AND_PAGE_SOURCE_ANCHORS_PRESENT_UNVALIDATED": 96,
            "ROUTE_SOURCE_ANCHOR_ONLY_UNVALIDATED": 100,
            "PAGE_SOURCE_ANCHOR_ONLY_UNVALIDATED": 3,
            "NOT_ESTABLISHED_CURRENT_AUDIT_NO_ROUTE_OR_PAGE_ANCHOR": 101,
        }),
        "H route/page classification drifted",
    )
    blocked = [row["feature_id"] for row in h_rows if entry_status(row) == "NOT_ESTABLISHED_CURRENT_AUDIT_NO_ROUTE_OR_PAGE_ANCHOR"]
    require(digest_sorted_lines(blocked) == EXPECTED_H_BOTH_ROUTE_PAGE_GAP_SHA256, "H no-route/page set hash drifted")
    return h_rows, entry_counts, blocked


def target_scores_json() -> str:
    return json.dumps({dimension: NOT_MEASURED for dimension in DIMENSIONS}, ensure_ascii=False, separators=(",", ":"))


def make_scorecard_row(row: dict[str, str], script_path: str) -> dict[str, str]:
    status = entry_status(row)
    result: dict[str, str] = {
        "task_script_id": f"TASK-{row['feature_id']}", "feature_id": row["feature_id"],
        "module": row["module"], "actor": NOT_ESTABLISHED, "task": row["user_job"],
        "start_condition": NOT_ESTABLISHED, "goal": row["user_job"],
        "prerequisites": PREREQUISITES, "observed_or_inferred": "SOURCE_BOUND_STATIC_IDENTITY_ONLY",
        "validation_status": "NOT_EXECUTED_REPRESENTATIVE_ROLE", "score_measurement_status": NOT_MEASURED,
        "score_scale": "0-5; NOT_MEASURED is not 0", "completion_time": NOT_MEASURED,
        "step_count": NOT_MEASURED, "required_field_count": NOT_MEASURED,
        "decision_count": NOT_MEASURED, "context_switches": NOT_MEASURED,
        "dead_ends": NOT_MEASURED, "recovery_path": NOT_MEASURED,
        "target_scores": target_scores_json(), "independent_review": "NOT_PERFORMED",
        "finding_ids": row["finding_ids"], "task_success": NOT_MEASURED,
        "feature_class": row["feature_class"], "submodule": row["submodule"],
        "matrix_owning_actor": row["owning_actor"], "matrix_secondary_actors": row["secondary_actors"],
        "user_job": row["user_job"], "criticality": row["criticality"],
        "matrix_current_ease_score": row["current_ease_score"],
        "matrix_target_ease_score": row["target_ease_score"],
        "feature_identity_status": row["feature_identity_status"],
        "matrix_completion_status": row["completion_status"],
        "risk_adjudication_status": NOT_ADJUDICATED,
        "safety_adjudication_status": NOT_ADJUDICATED,
        "high_risk_alternative_status": "NOT_DETERMINED_CURRENT_AUDIT",
        "architecture_scope": ARCHITECTURE_SCOPE, "navigation_entry": row["navigation_entry"],
        "route_names": row["route_names"], "route_paths": row["route_paths"],
        "page_files": row["page_files"], "backend_anchors": row["backend_anchors"],
        "test_anchors": row["test_anchors"], "current_states": row["current_states"],
        "current_workflow_summary": row["current_workflow_summary"],
        "entry_point_status": status, "source_anchor_status": status,
        "task_script_path": script_path, "application_source_commit": APPLICATION_COMMIT,
        "application_source_tree": APPLICATION_TREE,
        "source_matrix_sha256": INPUT_SPECS["matrix"]["sha256"],
        "canonical_identity_sha256": INPUT_SPECS["canonical_identity"]["sha256"],
        "representative_role_execution": "false", "browser_observation": "false",
        "executed_test_evidence": "false", "ease_credit": "false",
        "completion_credit": "false", "evidence_limit": row["evidence_limit"],
    }
    for dimension in DIMENSIONS:
        result[dimension] = NOT_MEASURED
    for field in TARGET_DIMENSION_FIELDS:
        result[field] = NOT_MEASURED
    require(set(result) == set(SCORECARD_FIELDS), f"Scorecard field mismatch for {row['feature_id']}")
    return {field: result[field] for field in SCORECARD_FIELDS}


def render_task_script(row: dict[str, str]) -> bytes:
    labels = {
        "discoverability": "Discoverability", "comprehension": "Comprehension",
        "learnability": "Learnability", "efficiency": "Efficiency",
        "error_prevention": "Error prevention", "recovery": "Recovery",
        "accessibility": "Accessibility", "safety_and_trust": "Safety and trust",
        "consistency": "Consistency", "cross_module_continuity": "Cross-module continuity",
    }
    status = entry_status(row)
    lines = [
        f"# {row['feature_id']}", "",
        "- Status: `SOURCE_BOUND_STATIC_TASK_CONTRACT`; not executed or scored.",
        f"- Module: `{row['module']}`", f"- User job: {row['user_job']}",
        f"- Matrix source owner, not assumed human actor: `{row['owning_actor']}`",
        f"- Representative actor: `{NOT_ESTABLISHED}`",
        f"- Application pin: `{APPLICATION_COMMIT}` / `{APPLICATION_TREE}`",
        f"- Entry status: `{status}`", "", "## Source anchors", "",
        f"- Navigation: `{row['navigation_entry']}`", f"- Route names: `{row['route_names']}`",
        f"- Route paths: `{row['route_paths']}`", f"- Pages: `{row['page_files']}`",
        f"- Backend: `{row['backend_anchors']}`", f"- Tests: `{row['test_anchors']}`",
        "", "## Planned representative-role validation", "",
        "1. Use only the listed source-supported entry. If no route or page anchor exists, stop and record the entry-point gap.",
        "2. Establish the documented actor, permission, approved Site, canonical record ownership, direct-object and privacy boundary before disclosure or action.",
        f"3. Attempt only the matrix-defined user job: {row['user_job']}.",
        "4. Record actual fields, decisions, states, errors, recovery, completion evidence and hand-off; do not infer them from source presence.",
        "5. Require independent review before assigning any ease score or completion claim.",
        "", "These are future audit instructions, not a measured user-task step count.",
        "", "## Unmeasured task evidence", "",
        f"- Start condition: `{NOT_ESTABLISHED}`", f"- Prerequisites: `{NOT_ESTABLISHED}`",
        f"- Decisions/states: `{NOT_ESTABLISHED}`", f"- Recovery path: `{NOT_MEASURED}`",
        f"- Completion evidence: `{NOT_MEASURED}`", f"- Next hand-off: `{NOT_ESTABLISHED}`",
        f"- Completion time: `{NOT_MEASURED}`", f"- Step count: `{NOT_MEASURED}`",
        f"- Required-field count: `{NOT_MEASURED}`", f"- Decision count: `{NOT_MEASURED}`",
        f"- Context switches: `{NOT_MEASURED}`", f"- Dead ends: `{NOT_MEASURED}`",
        "", "| Ease dimension | Current | Target |", "|---|---|---|",
    ]
    lines.extend(f"| {labels[dimension]} | `{NOT_MEASURED}` | `{NOT_MEASURED}` |" for dimension in DIMENSIONS)
    lines.extend([
        "", f"- Risk adjudication: `{NOT_ADJUDICATED}`",
        f"- Safety criticality: `{NOT_ADJUDICATED}`",
        "- High-risk alternative script need: `NOT_DETERMINED_CURRENT_AUDIT`",
        "- Representative-role execution: `false`", "- Browser observation: `false`",
        "- Executed-test evidence: `false`", "- Ease credit: `false`",
        "- Completion credit: `false`", f"- Evidence limit: {row['evidence_limit']}", "",
    ])
    data = "\n".join(lines).encode("utf-8")
    require(data.endswith(b"\n"), f"Task script lacks newline: {row['feature_id']}")
    return data


def render_scorecard(rows: list[dict[str, str]]) -> bytes:
    buffer = io.StringIO(newline="")
    writer = csv.DictWriter(buffer, fieldnames=SCORECARD_FIELDS, extrasaction="raise", lineterminator="\n", quoting=csv.QUOTE_MINIMAL)
    writer.writeheader()
    writer.writerows(rows)
    data = buffer.getvalue().encode("utf-8")
    require(data.endswith(b"\n"), "Scorecard lacks terminal newline")
    return data


def build_evidence_payload(
    entry_counts: Counter[str], blocked_ids: list[str], scorecard_bytes: bytes,
    script_outputs: dict[str, bytes], feature_id_by_path: dict[str, str],
) -> dict[str, Any]:
    manifest = [
        {
            "feature_id": feature_id_by_path[path], "path": path, "bytes": len(data),
            "sha256": sha256_bytes(data),
        }
        for path, data in sorted(script_outputs.items())
    ]
    task_lines = [f"{item['path']}|{item['sha256']}|{item['bytes']}" for item in manifest]
    scorecard_sha = sha256_bytes(scorecard_bytes)
    payload_lines = [f"04-workflow-usability-scorecard.csv|{scorecard_sha}|{len(scorecard_bytes)}", *task_lines]
    return {
        "schema_version": 1,
        "run_id": RUN_ID,
        "status": "SOURCE_BOUND_USABILITY_TASK_CONTRACTS_MATERIALIZED_AUDIT_INCOMPLETE",
        "generated_on": GENERATED_ON,
        "source_pin": {"application_commit": APPLICATION_COMMIT, "application_tree": APPLICATION_TREE},
        "inputs": {
            label: {"path": spec["relative_path"], "bytes": spec["bytes"], "sha256": spec["sha256"]}
            for label, spec in INPUT_SPECS.items()
        },
        "architecture_rule": "One operating organisation across multiple Sites; use roles, permissions, approved Sites, canonical ownership, direct-object denial, and privacy boundaries.",
        "counts": {
            "matrix_rows": 340, "canonical_targets": 340, "H_features": 300,
            "D_features": 40, "scorecard_columns": 78, "scorecard_rows": 300,
            "task_script_files": 300, "validated_task_scripts": 0,
            "representative_role_tasks_executed": 0, "current_ease_scores_measured": 0,
            "target_ease_scores_measured": 0, "independent_reviews_completed": 0,
            "entry_point_classifications": dict(sorted(entry_counts.items())),
        },
        "source_anchor_counts": {
            "navigation_entry_established": 9, "route_names_established": 0,
            "route_paths_established": 196, "page_files_established": 99,
            "backend_anchors_established": 292, "test_anchors_established": 166,
            "features_without_any_source_anchor": 0,
        },
        "entry_blocked_features": {
            "definition": "feature_class == H and route_paths == NOT_ESTABLISHED_CURRENT_AUDIT and page_files == NOT_ESTABLISHED_CURRENT_AUDIT",
            "count": 101, "sha256": EXPECTED_H_BOTH_ROUTE_PAGE_GAP_SHA256,
            "feature_ids": blocked_ids,
        },
        "sentinels": {
            "current_dimension_scores": NOT_MEASURED, "target_dimension_scores": NOT_MEASURED,
            "friction_measurements": NOT_MEASURED, "task_success": NOT_MEASURED,
            "representative_actor": NOT_ESTABLISHED, "risk_and_safety": NOT_ADJUDICATED,
            "high_risk_alternative_status": "NOT_DETERMINED_CURRENT_AUDIT", "credit": False,
        },
        "outputs": {
            "scorecard": {"path": "04-workflow-usability-scorecard.csv", "bytes": len(scorecard_bytes), "sha256": scorecard_sha, "columns": 78, "rows": 300},
            "task_scripts": {
                "directory": "task-scripts", "files": 300,
                "bundle_normalization": "sorted relative_path|sha256|bytes, UTF-8 LF, no terminal LF",
                "bundle_sha256": digest_sorted_lines(task_lines), "manifest": manifest,
            },
            "materialized_payload_bundle": {
                "normalization": "scorecard then sorted task relative_path|sha256|bytes, UTF-8 LF, no terminal LF; evidence JSON excludes itself",
                "sha256": sha256_bytes("\n".join(payload_lines).encode("utf-8")),
            },
        },
        "input_set_hashes": {
            "H_feature_id_set_sha256": EXPECTED_H_ID_SET_SHA256,
            "H_task_input_projection_sha256": EXPECTED_H_TASK_INPUT_PROJECTION_SHA256,
        },
        "completion_gate": {
            "source_bound_task_contract_files_present": 300,
            "validated_representative_role_task_scripts": 0,
            "ten_dimension_ease_scores_measured": 0,
            "independent_ease_reviews": 0, "ease_credit": 0,
            "completion_credit": 0, "gate_satisfied": False, "audit_complete": False,
        },
        "attestation": {
            "current_audit_inputs_only": True, "historical_artifacts_read": False,
            "application_runtime_used": False, "tests_executed": False,
            "browser_used": False, "network_used": False,
            "human_actor_inferred_from_source_owner": False,
            "risk_or_safety_inferred_from_keywords": False,
            "numeric_scores_assigned": False, "ease_or_completion_credit_awarded": False,
        },
    }


def render_json(payload: dict[str, Any]) -> bytes:
    return (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")


def preflight_file(path: Path, expected: bytes, label: str) -> None:
    if not path_lexists(path):
        return
    require(not is_reparse_point(path) and path.is_file(), f"{label} is not a regular file: {path}")
    require(path.read_bytes() == expected, f"{label} exists with different bytes: {path}")


def preflight_task_directory(expected: dict[str, bytes]) -> None:
    if not path_lexists(TASK_DIR):
        return
    require(not is_reparse_point(TASK_DIR) and TASK_DIR.is_dir(), f"Invalid task directory: {TASK_DIR}")
    expected_names = {Path(path).name for path in expected}
    for item in TASK_DIR.iterdir():
        require(not is_reparse_point(item) and item.is_file(), f"Unexpected task entry: {item}")
        require(item.name in expected_names, f"Unexpected task file: {item.name}")
        relative_path = f"task-scripts/{item.name}"
        require(item.read_bytes() == expected[relative_path], f"Different existing task script: {item}")


def exclusive_write(path: Path, data: bytes, label: str) -> None:
    if path_lexists(path):
        require(path.read_bytes() == data, f"{label} changed after preflight: {path}")
        return
    try:
        with path.open("xb") as handle:
            written = handle.write(data)
    except FileExistsError as exc:
        raise MaterializationError(f"{label} appeared during write: {path}") from exc
    require(written == len(data), f"Short write for {label}: {path}")


def verify_materialized_outputs(
    expected_files: dict[Path, bytes], expected_task_names: set[str], evidence_payload: dict[str, Any]
) -> None:
    for path, expected in expected_files.items():
        verify_regular_input(path, f"generated output {path.name}")
        actual = path.read_bytes()
        require(actual == expected and not actual.startswith(b"\xef\xbb\xbf") and actual.endswith(b"\n"), f"Generated bytes drifted: {path}")
    actual_task_names = {item.name for item in TASK_DIR.iterdir() if item.is_file() and not is_reparse_point(item)}
    require(actual_task_names == expected_task_names, "Task-script file set differs")
    reader = csv.DictReader(io.StringIO(SCORECARD_PATH.read_text(encoding="utf-8"), newline=""))
    require(reader.fieldnames == SCORECARD_FIELDS, "Generated scorecard header drifted")
    rows = list(reader)
    require(len(rows) == 300 and len({row["feature_id"] for row in rows}) == 300, "Generated scorecard denominator drifted")
    require(len({row["task_script_id"] for row in rows}) == 300, "Generated task IDs are not unique")
    expected_target_scores = {dimension: NOT_MEASURED for dimension in DIMENSIONS}
    for row in rows:
        for field in [*DIMENSIONS, *TARGET_DIMENSION_FIELDS, *FRICTION_FIELDS]:
            require(row[field] == NOT_MEASURED, f"Unexpected measurement: {row['feature_id']}.{field}")
        require(row["task_success"] == NOT_MEASURED and row["recovery_path"] == NOT_MEASURED, f"Unexpected task measurement: {row['feature_id']}")
        require(json.loads(row["target_scores"]) == expected_target_scores, f"Target scores drifted: {row['feature_id']}")
        for field in ("representative_role_execution", "browser_observation", "executed_test_evidence", "ease_credit", "completion_credit"):
            require(row[field] == "false", f"Unexpected credit: {row['feature_id']}.{field}")
        require((AUDIT_DIR / Path(row["task_script_path"])).is_file(), f"Missing task path: {row['task_script_path']}")
    require(decode_json(EVIDENCE_PATH.read_bytes(), "generated evidence") == evidence_payload, "Evidence payload drifted")


def main() -> None:
    require(len(MATRIX_FIELDS) == 38, "Matrix field list must contain 38 columns")
    require(len(SCORECARD_FIELDS) == 78, "Scorecard field list must contain 78 columns")
    require(len(DIMENSIONS) == 10, "Ease denominator must contain 10 dimensions")
    require(AUDIT_DIR.is_dir() and not is_reparse_point(AUDIT_DIR), "Invalid audit directory")
    require(SOURCE_DIR.is_dir() and not is_reparse_point(SOURCE_DIR), "Invalid source evidence directory")

    pinned_inputs = verify_pinned_inputs()
    matrix_rows = parse_matrix(pinned_inputs["matrix"])
    identity = decode_json(pinned_inputs["canonical_identity"], "canonical identity")
    inventory = decode_json(pinned_inputs["inventory"], "inventory")
    validate_inventory(inventory)
    h_rows, entry_counts, blocked_ids = validate_matrix_and_reconcile(matrix_rows, validate_identity(identity))

    scorecard_rows: list[dict[str, str]] = []
    script_outputs: dict[str, bytes] = {}
    feature_id_by_path: dict[str, str] = {}
    for row in h_rows:
        relative_path = f"task-scripts/{row['feature_id'].lower()}.md"
        require(relative_path not in script_outputs, f"Duplicate task path: {relative_path}")
        script_outputs[relative_path] = render_task_script(row)
        feature_id_by_path[relative_path] = row["feature_id"]
        scorecard_rows.append(make_scorecard_row(row, relative_path))
    require(len(scorecard_rows) == len(script_outputs) == 300, "In-memory output denominator drifted")

    scorecard_bytes = render_scorecard(scorecard_rows)
    evidence_payload = build_evidence_payload(entry_counts, blocked_ids, scorecard_bytes, script_outputs, feature_id_by_path)
    evidence_bytes = render_json(evidence_payload)
    expected_files = {
        SCORECARD_PATH: scorecard_bytes,
        EVIDENCE_PATH: evidence_bytes,
        **{AUDIT_DIR / Path(path): data for path, data in script_outputs.items()},
    }

    preflight_file(SCORECARD_PATH, scorecard_bytes, "scorecard")
    preflight_file(EVIDENCE_PATH, evidence_bytes, "evidence JSON")
    preflight_task_directory(script_outputs)
    require(verify_pinned_inputs() == pinned_inputs, "Pinned inputs changed before writing")

    if not path_lexists(TASK_DIR):
        try:
            TASK_DIR.mkdir()
        except FileExistsError as exc:
            raise MaterializationError("Task directory appeared during creation") from exc
    require(TASK_DIR.is_dir() and not is_reparse_point(TASK_DIR), "Invalid task output directory")
    exclusive_write(SCORECARD_PATH, scorecard_bytes, "scorecard")
    for relative_path, data in sorted(script_outputs.items()):
        exclusive_write(AUDIT_DIR / Path(relative_path), data, relative_path)
    exclusive_write(EVIDENCE_PATH, evidence_bytes, "evidence JSON")

    verify_materialized_outputs(expected_files, {Path(path).name for path in script_outputs}, evidence_payload)
    require(verify_pinned_inputs() == pinned_inputs, "Pinned inputs changed during materialization")
    print(json.dumps({
        "status": evidence_payload["status"], "scorecard_columns": 78,
        "scorecard_rows": 300, "task_scripts": 300, "entry_blocked": 101,
        "ease_credit": 0, "completion_credit": 0,
        "scorecard_sha256": sha256_bytes(scorecard_bytes),
        "task_script_bundle_sha256": evidence_payload["outputs"]["task_scripts"]["bundle_sha256"],
    }, ensure_ascii=False, separators=(",", ":")))


if __name__ == "__main__":
    main()
