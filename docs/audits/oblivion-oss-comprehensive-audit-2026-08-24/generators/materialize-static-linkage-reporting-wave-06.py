#!/usr/bin/env python3
"""Transactionally refresh RUN-076 matrix-derived reports without rewriting history."""

from __future__ import annotations

import csv
import hashlib
import io
import json
import os
import runpy
import subprocess
import tempfile
from collections import Counter
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
REPO_DIR = AUDIT_DIR.parents[2]
AUDIT_RELATIVE = AUDIT_DIR.relative_to(REPO_DIR).as_posix()
BASE_CHECKPOINT = "0d5a05e30878d4c24cb7b83c27e63e8c09b498a3"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
BASE_MATRIX_SHA256 = "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4"
MATRIX_PATH = "03-feature-to-benchmark-matrix.csv"
PRODUCER_PATH = "evidence/source/current-static-linkage-review-wave-06.json"
REVIEW_PATH = "evidence/source/current-static-linkage-independent-review-wave-06.json"
INTEGRATION_PATH = "evidence/source/current-static-linkage-integration-wave-06.json"
OUTPUT_PATH = "evidence/source/current-static-linkage-reporting-materialization-wave-06.json"
STAGED_GENERATOR = "generators/materialize-required-reporting-staged-wave-06.py"
SENTINEL = "NOT_ESTABLISHED_CURRENT_AUDIT"
ALLOWED_FIELDS = {"route_names", "route_paths", "page_files", "backend_anchors", "test_anchors"}
SCOPED_FIELDS = ("route_paths", "page_files", "backend_anchors", "test_anchors")
HISTORICAL = {
    "evidence/source/current-required-reporting-materialization-wave-05.json": "5fc76430b6a33f76182a470dfce774415c1d0618df14c6685c66105eef026c51",
    "evidence/source/raw-run-073f-independent-reporting-materialization-review-wave-05.json": "a97d3b9810dd6298b3c46bfd40b6dd23ed1e747be43be097006bc19750fb9f5d",
    "evidence/source/current-run-073-checkpoint-validation-wave-05.json": "827829d1b48ef804b81a42ade614f8f09de6bab11d39e732d5ed20bc4b8fbc1f",
    "evidence/browser/current-audit-dashboard-verification-run-073-wave-05.json": "814a53449a96abb7a821711ab33a7111d83e64b897ea3cb42ded2448178efdf7",
    "generators/materialize-required-reporting-wave-05.py": "2275a5ef8159a43b4106f13259452dbf376ef566c75de8ffb6550f2b397fd170",
}
BENCHMARK_AND_CREDIT_FIELDS = (
    "benchmark_candidates", "selected_open_source_benchmark", "benchmark_url_and_sha",
    "verified_behaviour", "neutral_requirements_extracted", "no_match_evidence",
    "current_ease_score", "target_ease_score", "P1", "P2", "P3", "P4", "P5",
    "P6", "P7", "P8", "finding_ids", "confidence", "feature_class",
    "feature_identity_status", "benchmark_mapping_credit", "completion_status",
    "evidence_limit",
)


def sha256_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def sha256(relative: str) -> str:
    return sha256_bytes((AUDIT_DIR / relative).read_bytes())


def read_json(relative: str):
    return json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))


def read_csv_bytes(value: bytes) -> tuple[list[str], list[dict[str, str]]]:
    reader = csv.DictReader(io.StringIO(value.decode("utf-8-sig"), newline=""))
    assert reader.fieldnames is not None
    return reader.fieldnames, list(reader)


def projection_sha256(rows: list[dict[str, str]], columns: list[str]) -> str:
    value = {"columns": columns, "rows": [[row[column] for column in columns] for row in rows]}
    return sha256_bytes(json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode("utf-8"))


def committed_bytes(relative: str) -> bytes:
    result = subprocess.run(
        ["git", "show", f"{BASE_CHECKPOINT}:{AUDIT_RELATIVE}/{relative}"],
        cwd=REPO_DIR,
        check=True,
        capture_output=True,
    )
    return result.stdout


def replace_once(text: str, old: str, new: str) -> str:
    assert text.count(old) == 1, old
    return text.replace(old, new, 1)


def replace_line(text: str, prefix: str, replacement: str) -> str:
    lines = text.splitlines()
    matches = [index for index, line in enumerate(lines) if line.startswith(prefix)]
    assert len(matches) == 1, prefix
    lines[matches[0]] = replacement
    return "\n".join(lines) + "\n"


for historical_path, expected_hash in HISTORICAL.items():
    assert sha256(historical_path) == expected_hash

legacy_receipt = read_json("evidence/source/current-required-reporting-materialization-wave-05.json")
assert legacy_receipt["run_id"] == "RUN-073-REPORTING-MATERIALIZATION"
legacy_non_matrix_inputs = {
    path: digest
    for path, digest in legacy_receipt["inputs"].items()
    if path != MATRIX_PATH
}
for input_path, expected_hash in legacy_non_matrix_inputs.items():
    assert sha256(input_path) == expected_hash

producer = read_json(PRODUCER_PATH)
review = read_json(REVIEW_PATH)
integration = read_json(INTEGRATION_PATH)
assert producer["run_id"] == "RUN-074-STATIC-LINKAGE-NORMALIZATION"
assert producer["pins"]["application_commit"] == APPLICATION_COMMIT
assert producer["counts"]["targets"] == 288
assert producer["counts"]["invalid_anchors"] == 0
original_missing_cells = sum(len(row["original_missing_fields"]) for row in producer["records"])
assert original_missing_cells == 503
assert review["run_id"] == "RUN-075-STATIC-LINKAGE-INDEPENDENT-REVIEW"
assert review["pins"]["application_commit"] == APPLICATION_COMMIT
assert review["pins"]["normalized_producer_sha256"] == sha256(PRODUCER_PATH)
assert review["counts"]["targets"] == 288
assert review["counts"]["invalid_final_anchors"] == 0
assert review["review_gate"] == {
    "all_producer_targets_reviewed_by_a_different_agent": True,
    "all_producer_field_decisions_reviewed": True,
    "mechanical_anchor_validation_passed": True,
    "matrix_integration_completed": False,
}
assert integration["run_id"] == "RUN-076-STATIC-LINKAGE-INTEGRATION"
assert integration["pins"]["application_commit"] == APPLICATION_COMMIT
assert integration["pins"]["base_matrix_sha256"] == BASE_MATRIX_SHA256
assert integration["pins"]["independent_review_sha256"] == sha256(REVIEW_PATH)
assert integration["matrix"]["base_sha256"] == BASE_MATRIX_SHA256
assert integration["matrix"]["updated_sha256"] == sha256(MATRIX_PATH)
assert integration["matrix"]["row_order_preserved"] is True
assert integration["matrix"]["feature_id_denominator_preserved"] is True
assert integration["matrix"]["base_immutable_projection_sha256"] == integration["matrix"]["updated_immutable_projection_sha256"]
assert integration["matrix"]["base_benchmark_and_credit_projection_sha256"] == integration["matrix"]["updated_benchmark_and_credit_projection_sha256"]
assert integration["completion_boundary"] == {
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
}
assert all(
    integration["counts"][key] == 0
    for key in (
        "benchmark_mapping_credit", "runtime_credit", "browser_credit",
        "executed_test_credit", "pass_credit", "completion_credit",
    )
)

base_matrix_bytes = committed_bytes(MATRIX_PATH)
assert sha256_bytes(base_matrix_bytes) == BASE_MATRIX_SHA256
fieldnames, base_rows = read_csv_bytes(base_matrix_bytes)
live_matrix_bytes = (AUDIT_DIR / MATRIX_PATH).read_bytes()
live_fieldnames, live_rows = read_csv_bytes(live_matrix_bytes)
assert fieldnames == live_fieldnames
assert len(base_rows) == len(live_rows) == 340
assert [row["feature_id"] for row in base_rows] == [row["feature_id"] for row in live_rows]
assert len({row["feature_id"] for row in live_rows}) == 340
assert Counter(row["feature_class"] for row in live_rows) == {"H": 300, "D": 40}
changed_cells = [
    (base["feature_id"], field)
    for base, live in zip(base_rows, live_rows, strict=True)
    for field in fieldnames
    if base[field] != live[field]
]
assert changed_cells
assert {field for _, field in changed_cells}.issubset(ALLOWED_FIELDS)
changed_rows = sorted({feature_id for feature_id, _ in changed_cells})
assert len(changed_rows) == integration["counts"]["matrix_rows_changed"]
assert len(changed_cells) == integration["counts"]["matrix_field_changes"]
assert changed_rows == sorted(integration["changed_feature_ids"])
field_change_counts = dict(sorted(Counter(field for _, field in changed_cells).items()))
assert field_change_counts == integration["counts"]["field_changes"]
assert integration["counts"]["canonical_targets"] == 340
assert integration["counts"]["reviewed_gap_targets"] == 288
immutable_columns = [field for field in fieldnames if field not in ALLOWED_FIELDS]
assert projection_sha256(base_rows, immutable_columns) == integration["matrix"]["base_immutable_projection_sha256"]
assert projection_sha256(live_rows, immutable_columns) == integration["matrix"]["updated_immutable_projection_sha256"]
assert projection_sha256(base_rows, list(BENCHMARK_AND_CREDIT_FIELDS)) == integration["matrix"]["base_benchmark_and_credit_projection_sha256"]
assert projection_sha256(live_rows, list(BENCHMARK_AND_CREDIT_FIELDS)) == integration["matrix"]["updated_benchmark_and_credit_projection_sha256"]
assert all(row["benchmark_mapping_credit"] == "false" for row in live_rows)

gap_ids = {
    field: sorted(row["feature_id"] for row in live_rows if row[field] == SENTINEL)
    for field in ("route_names", *SCOPED_FIELDS)
}
both_gap_ids = sorted(set(gap_ids["route_paths"]) & set(gap_ids["page_files"]))
for field, ids in gap_ids.items():
    assert ids == integration["remaining_gaps"][field]
assert both_gap_ids == integration["remaining_gaps"]["both_route_and_page"]
assert len(gap_ids["route_paths"]) == integration["counts"]["remaining_missing_route_paths"]
assert len(gap_ids["route_names"]) == integration["counts"]["remaining_missing_route_names"]
assert len(gap_ids["page_files"]) == integration["counts"]["remaining_missing_page_files"]
assert len(both_gap_ids) == integration["counts"]["remaining_missing_both_route_and_page"]
assert len(gap_ids["backend_anchors"]) == integration["counts"]["remaining_missing_backend_anchors"]
assert len(gap_ids["test_anchors"]) == integration["counts"]["remaining_missing_test_anchors"]
any_scoped_gap_ids = sorted(set().union(*(set(gap_ids[field]) for field in SCOPED_FIELDS)))
assert any_scoped_gap_ids == integration["remaining_gaps"]["any_scoped_field"]
assert len(any_scoped_gap_ids) == integration["counts"]["targets_with_any_remaining_scoped_gap"]

with (AUDIT_DIR / "04-workflow-usability-scorecard.csv").open(encoding="utf-8-sig", newline="") as handle:
    scorecard_rows = list(csv.DictReader(handle))
assert len(scorecard_rows) == 300
assert all(row["source_matrix_sha256"] == BASE_MATRIX_SHA256 for row in scorecard_rows)
usability_history = read_json("evidence/source/current-usability-task-script-materialization-wave-01.json")
assert usability_history["counts"]["validated_task_scripts"] == 0
assert usability_history["outputs"]["scorecard"]["sha256"] == sha256("04-workflow-usability-scorecard.csv")

counts = integration["counts"]
field_statuses = counts["field_final_statuses"]
staged_generator_sha = sha256(STAGED_GENERATOR)
wrapper_sha = sha256("generators/materialize-static-linkage-reporting-wave-06.py")
output_paths = [
    "00-executive-summary.md",
    "01-repository-module-map.md",
    "07-module-findings.md",
    "08-cross-module-journeys.md",
    "09-ui-ux-accessibility-visual-consistency.md",
    "10-architecture-data-integration-security.md",
    "11-prioritised-roadmap.md",
    "12-native-build-and-do-not-copy-register.md",
    "13-unresolved-questions-and-evidence-gaps.md",
    "findings.json",
]

with tempfile.TemporaryDirectory(prefix=".run076-reporting-", dir=AUDIT_DIR) as temporary:
    stage = Path(temporary)
    prior_output_env = os.environ.get("OBLIVION_AUDIT_REPORT_OUTPUT_DIR")
    os.environ["OBLIVION_AUDIT_REPORT_OUTPUT_DIR"] = str(stage)
    try:
        runpy.run_path(str(AUDIT_DIR / STAGED_GENERATOR), run_name="__run076_staged_reporting__")
    finally:
        if prior_output_env is None:
            os.environ.pop("OBLIVION_AUDIT_REPORT_OUTPUT_DIR", None)
        else:
            os.environ["OBLIVION_AUDIT_REPORT_OUTPUT_DIR"] = prior_output_env

    transient = json.loads((stage / "staged-reporting-materialization-wave-06.json").read_text(encoding="utf-8"))
    assert transient["run_id"] == "RUN-076-STAGED-REPORTING-MATERIALIZATION"
    assert transient["inputs"][MATRIX_PATH] == integration["matrix"]["updated_sha256"]
    assert set(transient["outputs"]) == {
        "07-module-findings.md", "08-cross-module-journeys.md",
        "09-ui-ux-accessibility-visual-consistency.md",
        "10-architecture-data-integration-security.md", "11-prioritised-roadmap.md",
        "12-native-build-and-do-not-copy-register.md", "findings.json",
    }
    assert all(sha256_bytes((stage / path).read_bytes()) == digest for path, digest in transient["outputs"].items())
    assert {
        path: transient["inputs"][path]
        for path in legacy_non_matrix_inputs
    } == legacy_non_matrix_inputs
    for unchanged_report in (
        "08-cross-module-journeys.md",
        "09-ui-ux-accessibility-visual-consistency.md",
        "10-architecture-data-integration-security.md",
        "11-prioritised-roadmap.md",
        "12-native-build-and-do-not-copy-register.md",
    ):
        assert sha256_bytes((stage / unchanged_report).read_bytes()) == legacy_receipt["outputs"][unchanged_report]

    section_07 = f"""## RUN-074–076 feature-side static linkage

Three producer partitions reconstructed {original_missing_cells} previously missing scoped cells across {counts['reviewed_gap_targets']} canonical targets. Cyclic independent review reopened every decision before deterministic matrix integration. This is committed-source linkage only: it does not establish framework reachability, full route/page-universe mapping, runtime behavior, rendered browser behavior, executed tests, benchmark equivalence, ease, Pass, or audit completion.

| Measure | Current result | Credit boundary |
|---|---:|---|
| Reviewed gap targets | {counts['reviewed_gap_targets']} / 288 | independently reviewed static source only |
| Matrix rows / fields changed | {counts['matrix_rows_changed']} / {counts['matrix_field_changes']} | permitted linkage columns only |
| Remaining route / page / both gaps | {counts['remaining_missing_route_paths']} / {counts['remaining_missing_page_files']} / {counts['remaining_missing_both_route_and_page']} | universe mapping and reachability remain open |
| Remaining backend / test gaps | {counts['remaining_missing_backend_anchors']} / {counts['remaining_missing_test_anchors']} | static locators; tests unexecuted |
| Targets with any remaining scoped gap | {counts['targets_with_any_remaining_scoped_gap']} | explicit retained set |
| Benchmark mappings / final findings / completion | 0 / 0 / 0 | unchanged |

Established final decisions include {field_statuses.get('route_paths:ESTABLISHED', 0)} route-path, {field_statuses.get('page_files:ESTABLISHED', 0)} page-file, {field_statuses.get('backend_anchors:ESTABLISHED', 0)} backend-anchor, and {field_statuses.get('test_anchors:ESTABLISHED', 0)} test-anchor cells. Retained sentinels remain visible.

"""
    report_07 = (stage / "07-module-findings.md").read_text(encoding="utf-8")
    report_07 = replace_once(report_07, "## Exact accounting\n", section_07 + "## Exact accounting\n")
    (stage / "07-module-findings.md").write_text(report_07, encoding="utf-8", newline="\n")

    findings_path = stage / "findings.json"
    findings = json.loads(findings_path.read_text(encoding="utf-8"))
    assert len(findings["records"]) == findings["counts"]["provisional_source_claims"] == 12
    assert findings["counts"]["final_P0"] == findings["counts"]["final_P1"] == 0
    findings["pins"].update({
        "audit_checkpoint_parent": BASE_CHECKPOINT,
        "base_matrix_sha256": BASE_MATRIX_SHA256,
        "current_matrix_sha256": integration["matrix"]["updated_sha256"],
        "static_linkage_independent_review_sha256": sha256(REVIEW_PATH),
        "static_linkage_integration_sha256": sha256(INTEGRATION_PATH),
    })
    findings["counts"].update({
        "static_linkage_reviewed_gap_targets": counts["reviewed_gap_targets"],
        "static_linkage_matrix_rows_changed": counts["matrix_rows_changed"],
        "static_linkage_matrix_field_changes": counts["matrix_field_changes"],
        "remaining_static_linkage_gap_targets": counts["targets_with_any_remaining_scoped_gap"],
    })
    findings["current_static_linkage"] = {
        "status": "INDEPENDENTLY_REVIEWED_COMMITTED_SOURCE_LINKAGE_ONLY",
        "remaining_gap_counts": {
            "route_paths": counts["remaining_missing_route_paths"],
            "page_files": counts["remaining_missing_page_files"],
            "both_route_and_page": counts["remaining_missing_both_route_and_page"],
            "backend_anchors": counts["remaining_missing_backend_anchors"],
            "test_anchors": counts["remaining_missing_test_anchors"],
        },
        "framework_route_reachability": False,
        "complete_route_page_universe_mapping": False,
        "runtime": False,
        "browser": False,
        "executed_tests": False,
        "benchmark_mapping": False,
        "ease": False,
        "pass": False,
        "completion": False,
    }
    findings["task_script_boundary"] = {
        "status": "HISTORICAL_RUN_072_SOURCE_CONTRACT_SNAPSHOT_UNEXECUTED",
        "source_matrix_sha256": BASE_MATRIX_SHA256,
        "locator_refresh_completed": False,
        "measurement_credit": False,
    }
    findings_path.write_text(json.dumps(findings, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")

    summary_section = f"""## RUN-074–076 static-linkage checkpoint

RUN-074 partitions and reconstructs all {original_missing_cells} previously missing route-path, page-file, backend-anchor, and static test-anchor cells across {counts['reviewed_gap_targets']} canonical targets. RUN-075 cyclically reviews every producer decision under a different agent, and RUN-076 integrates only the permitted linkage columns. The matrix changes {counts['matrix_rows_changed']} rows / {counts['matrix_field_changes']} cells from base SHA-256 `{BASE_MATRIX_SHA256}` to `{integration['matrix']['updated_sha256']}`.

Live retained gaps are {counts['remaining_missing_route_paths']} route paths, {counts['remaining_missing_page_files']} page files, {counts['remaining_missing_both_route_and_page']} with both missing, {counts['remaining_missing_backend_anchors']} backend anchors, and {counts['remaining_missing_test_anchors']} static test anchors. These are feature-side locators only. Full framework route reachability, the complete route/page universe, RUN-072 task-locator refresh, runtime, application browser, executed tests, benchmark mapping, ease, Pass, final findings, and completion remain open and receive zero credit.

"""
    executive = committed_bytes("00-executive-summary.md").decode("utf-8")
    executive = replace_once(executive, "## Current raw source census\n", summary_section + "## Current raw source census\n")
    executive = replace_once(
        executive,
        "Static linkage gaps remain explicit: 120 targets lack a route anchor, 226 lack a page anchor, and 116 lack both.",
        f"The RUN-030/RUN-073 snapshot retained 120 route-anchor, 226 page-anchor, and 116 combined gaps. RUN-076 now retains {counts['remaining_missing_route_paths']} route-path, {counts['remaining_missing_page_files']} page-file, and {counts['remaining_missing_both_route_and_page']} combined gaps after cyclic independent review; this is static linkage only.",
    )
    executive = replace_once(
        executive,
        "2. Resolve the static linkage gaps—120 targets without route anchors, 226 without page anchors, and 116 without either—then finish safe framework-route reachability, route/page-to-feature mapping, and canonical backend/data/test ownership. The semantic/runtime graph remains partial.",
        f"2. Continue from RUN-076's retained gaps—{counts['remaining_missing_route_paths']} route paths, {counts['remaining_missing_page_files']} page files, {counts['remaining_missing_backend_anchors']} backend anchors, and {counts['remaining_missing_test_anchors']} static test anchors—then finish the complete route/page-universe mapping, safe framework reachability, canonical backend/data/test ownership, and a separate RUN-072 task-locator refresh. The semantic/runtime graph remains partial.",
    )
    evidence_marker = "- `evidence/source/raw-run-073a-required-artifact-contract-wave-05.json`"
    assert executive.count(evidence_marker) == 1
    new_evidence_bullets = (
        f"- `evidence/source/current-static-linkage-review-wave-06.json`: RUN-074 normalized three-part producer reconstruction for {counts['reviewed_gap_targets']} targets / {original_missing_cells} original missing cells; zero downstream credit.\n"
        "- `evidence/source/current-static-linkage-independent-review-wave-06.json`: RUN-075 cyclic independent review of every producer target and field decision, with zero invalid final anchors.\n"
        f"- `evidence/source/current-static-linkage-integration-wave-06.json`: RUN-076 linkage-only matrix integration ({counts['matrix_rows_changed']} rows / {counts['matrix_field_changes']} fields), unchanged immutable and benchmark/credit projections, and zero runtime/browser/test/mapping/ease/Pass/completion credit.\n"
        "- `evidence/source/current-static-linkage-reporting-materialization-wave-06.json`: current report/hash receipt preserving RUN-073 reporting receipts as immutable history.\n"
    )
    executive = replace_once(executive, evidence_marker, new_evidence_bullets + evidence_marker)
    (stage / "00-executive-summary.md").write_text(executive.rstrip() + "\n", encoding="utf-8", newline="\n")

    module_map = committed_bytes("01-repository-module-map.md").decode("utf-8")
    module_update = f"""## RUN-074–076 current linkage overlay

The historical discovery register below remains source provenance. The live 340-target matrix now carries cyclically reviewed feature-side linkage changes on {counts['matrix_rows_changed']} rows / {counts['matrix_field_changes']} cells. Retained gaps are {counts['remaining_missing_route_paths']} route paths, {counts['remaining_missing_page_files']} page files, {counts['remaining_missing_backend_anchors']} backend anchors, and {counts['remaining_missing_test_anchors']} static test anchors. Headless endpoints and support components remain distinct from route-owned pages. Full framework reachability, the 711-page-root universe mapping, runtime, browser, executed-test, benchmark, ease, Pass, and completion evidence remain open.

"""
    module_map = replace_once(module_map, "## Candidate register\n", module_update + "## Candidate register\n")
    (stage / "01-repository-module-map.md").write_text(module_map.rstrip() + "\n", encoding="utf-8", newline="\n")

    gaps = committed_bytes("13-unresolved-questions-and-evidence-gaps.md").decode("utf-8")
    gaps = replace_line(
        gaps,
        "| Required reporting paths |",
        "| Required reporting paths | 18 / 18 prompt-required files or directories present; RUN-073 dashboard verification is immutable historical evidence for the superseded HTML, and fresh RUN-076 audit-artifact verification is recorded against the final published dashboard | Presence plus audit-artifact verification makes the reporting contract inspectable but grants no application execution, final-finding, Pass, or completion credit. Gate 26 remains open. | Complete the semantic, execution, benchmark, Pass 8, final reconciliation, and no-live-agent gates; repeat audit-dashboard verification after every later HTML change. |",
    )
    gaps = replace_line(
        gaps,
        "| Canonical features |",
        f"| Canonical features | Static canonical identity remains 340 targets: 300 H, 40 D, zero M; RUN-076 changes only independently reviewed feature-side linkage cells | RUN-030/RUN-073 retained 120 route, 226 page, and 116 combined gaps. RUN-076 changes {counts['matrix_rows_changed']} rows / {counts['matrix_field_changes']} permitted linkage cells and retains {counts['remaining_missing_route_paths']} route-path, {counts['remaining_missing_page_files']} page-file, {counts['remaining_missing_both_route_and_page']} combined, {counts['remaining_missing_backend_anchors']} backend, and {counts['remaining_missing_test_anchors']} static test gaps. Immutable and benchmark/credit projections remain equal; 0/340 mapping credit remains. | Continue the complete route/page universe, framework reachability, retained backend/test linkage, and separate RUN-072 task-locator refresh without awarding runtime, browser, test, benchmark, ease, release, or completion credit. |",
    )
    gaps = replace_line(
        gaps,
        "| Agent universe and writer rule |",
        "| Agent universe and writer rule | RUN-001 through RUN-076 represented at the current checkpoint; finalization gate false | RUN-074 producer partitions, RUN-075 cyclic independent reviews, and RUN-076 root integration/reporting are represented. Root alone writes normalized artifacts and generators. File presence, agent acceptance, lineage PASS, hash validity, static-linkage establishment, or mechanical correction does not satisfy execution, mapping, Pass 8, finalization, or completion. | Continue all semantic/execution gates, then dispatch fresh Pass 8/final cross-reviewers, represent every return, verify the final dashboard, and prove no agent remains live at finalization. |",
    )
    matrix_prefix = "The current `03-feature-to-benchmark-matrix.csv` has 340 canonical static target rows:"
    matrix_lines = [index for index, line in enumerate(gaps.splitlines()) if line.startswith(matrix_prefix)]
    assert len(matrix_lines) == 1
    gaps_lines = gaps.splitlines()
    gaps_lines[matrix_lines[0]] = (
        f"The current `03-feature-to-benchmark-matrix.csv` has 340 canonical static target rows: 300 H and 40 D. RUN-076 changes only independently reviewed feature-side linkage columns, from base `{BASE_MATRIX_SHA256}` to `{integration['matrix']['updated_sha256']}`. Retained gaps are {counts['remaining_missing_route_paths']} route paths, {counts['remaining_missing_page_files']} page files, {counts['remaining_missing_both_route_and_page']} combined, {counts['remaining_missing_backend_anchors']} backend anchors, and {counts['remaining_missing_test_anchors']} static test anchors. Immutable and benchmark/credit projections are unchanged; runtime, browser, executed-test, benchmark, ease, release, P2–P8, Pass, and completion credit remain zero. RUN-072 task scripts/scorecard remain an unexecuted historical base-matrix locator snapshot and were not silently relabelled current."
    )
    gaps = "\n".join(gaps_lines) + "\n"
    linkage_section = f"""## RUN-074–076 static-linkage lineage

All {counts['reviewed_gap_targets']} producer targets and their field decisions were reviewed cyclically by a different agent. RUN-076 integrates only the five permitted linkage columns and retains explicit sentinel sets. This closes no framework route, page-universe, runtime, browser, executed-test, benchmark, ease, Pass, final-finding, or audit-completion gate.

"""
    gaps = replace_once(gaps, "## Current provisional source findings\n", linkage_section + "## Current provisional source findings\n")
    (stage / "13-unresolved-questions-and-evidence-gaps.md").write_text(gaps.rstrip() + "\n", encoding="utf-8", newline="\n")

    historical_inputs = {
        "source_matrix_snapshot_sha256": BASE_MATRIX_SHA256,
        "04-workflow-usability-scorecard.csv": transient["inputs"].pop("04-workflow-usability-scorecard.csv"),
        "evidence/source/current-usability-task-script-materialization-wave-01.json": transient["inputs"].pop("evidence/source/current-usability-task-script-materialization-wave-01.json"),
        "task_script_bundle_sha256": usability_history["outputs"]["task_scripts"]["bundle_sha256"],
        "status": "HISTORICAL_RUN_072_UNEXECUTED_LOCATOR_SNAPSHOT_NOT_REFRESHED",
    }
    transient_inputs = dict(transient["inputs"])
    transient_inputs[PRODUCER_PATH] = sha256(PRODUCER_PATH)
    transient_inputs[REVIEW_PATH] = sha256(REVIEW_PATH)
    transient_inputs[INTEGRATION_PATH] = sha256(INTEGRATION_PATH)

    evidence = {
        "schema_version": 1,
        "run_id": "RUN-076-STATIC-LINKAGE-REPORTING-MATERIALIZATION",
        "status": "CURRENT_STATIC_LINKAGE_REPORTING_REFRESHED_ZERO_DOWNSTREAM_CREDIT",
        "generated_on": "2026-08-25",
        "pins": {
            "application_commit": APPLICATION_COMMIT,
            "application_tree": integration["pins"]["application_tree"],
            "audit_checkpoint_parent": BASE_CHECKPOINT,
            "base_matrix_sha256": BASE_MATRIX_SHA256,
            "updated_matrix_sha256": integration["matrix"]["updated_sha256"],
            "normalized_producer_sha256": sha256(PRODUCER_PATH),
            "independent_review_sha256": sha256(REVIEW_PATH),
            "integration_sha256": sha256(INTEGRATION_PATH),
        },
        "architecture_rule": transient["architecture_rule"],
        "counts": {
            **transient["counts"],
            "static_linkage_original_missing_cells": original_missing_cells,
            "static_linkage_reviewed_gap_targets": counts["reviewed_gap_targets"],
            "static_linkage_matrix_rows_changed": counts["matrix_rows_changed"],
            "static_linkage_matrix_field_changes": counts["matrix_field_changes"],
            "remaining_missing_route_paths": counts["remaining_missing_route_paths"],
            "remaining_missing_page_files": counts["remaining_missing_page_files"],
            "remaining_missing_both_route_and_page": counts["remaining_missing_both_route_and_page"],
            "remaining_missing_backend_anchors": counts["remaining_missing_backend_anchors"],
            "remaining_missing_test_anchors": counts["remaining_missing_test_anchors"],
            "remaining_targets_with_any_scoped_gap": counts["targets_with_any_remaining_scoped_gap"],
        },
        "history": {
            path: {"sha256": digest, "rewritten": False}
            for path, digest in HISTORICAL.items()
        },
        "historical_inputs": historical_inputs,
        "inputs": transient_inputs,
        "generators": {
            STAGED_GENERATOR: staged_generator_sha,
            "generators/materialize-static-linkage-reporting-wave-06.py": wrapper_sha,
        },
        "outputs": {path: sha256_bytes((stage / path).read_bytes()) for path in output_paths},
        "matrix_validation": {
            "rows": 340,
            "unique_feature_ids": 340,
            "classes": {"H": 300, "D": 40},
            "changed_columns": sorted({field for _, field in changed_cells}),
            "immutable_projection_equal": True,
            "benchmark_and_credit_projection_equal": True,
            "gap_lists_recomputed_from_live_csv": True,
        },
        "evidence_boundary": {
            "feature_side_static_linkage_cells_integrated": True,
            "framework_route_reachability": False,
            "complete_route_page_universe_mapping": False,
            "run_072_locator_refresh": False,
        },
        "credit_boundary": {
            "artifact_presence": False,
            "final_finding": False,
            "application_browser": False,
            "runtime": False,
            "executed_tests": False,
            "ease": False,
            "benchmark_mapping": False,
            "final_no_match": False,
            "pass": False,
            "completion": False,
            "audit_complete": False,
        },
        "attestation": "Staged deterministic RUN-076 reporting refresh. RUN-073 receipts were never targeted or rewritten. Reports were validated before per-file atomic publication. Application source, runtime, browser, tests, build, database, network, benchmark mapping, ease, Pass and completion credit remain unchanged at zero.",
    }
    evidence_path = stage / OUTPUT_PATH
    evidence_path.parent.mkdir(parents=True, exist_ok=True)
    evidence_path.write_text(json.dumps(evidence, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")

    assert all(sha256_bytes((stage / path).read_bytes()) == digest for path, digest in evidence["outputs"].items())
    assert all(value is False for value in evidence["credit_boundary"].values())
    for historical_path, expected_hash in HISTORICAL.items():
        assert sha256(historical_path) == expected_hash

    publication_paths = [*output_paths, OUTPUT_PATH]
    prior_destinations = {
        relative: (AUDIT_DIR / relative).read_bytes() if (AUDIT_DIR / relative).is_file() else None
        for relative in publication_paths
    }
    published: list[str] = []
    try:
        for relative in publication_paths:
            source = stage / relative
            destination = AUDIT_DIR / relative
            assert source.is_file()
            destination.parent.mkdir(parents=True, exist_ok=True)
            os.replace(source, destination)
            published.append(relative)
    except BaseException:
        for relative in reversed(published):
            destination = AUDIT_DIR / relative
            prior = prior_destinations[relative]
            if prior is None:
                destination.unlink(missing_ok=True)
                continue
            rollback = stage / ".rollback" / relative
            rollback.parent.mkdir(parents=True, exist_ok=True)
            rollback.write_bytes(prior)
            os.replace(rollback, destination)
        raise

assert all(sha256(path) == digest for path, digest in evidence["outputs"].items())
assert read_json(OUTPUT_PATH) == evidence
for historical_path, expected_hash in HISTORICAL.items():
    assert sha256(historical_path) == expected_hash

print(json.dumps({
    "status": evidence["status"],
    "updated_matrix_sha256": integration["matrix"]["updated_sha256"],
    "matrix_rows_changed": counts["matrix_rows_changed"],
    "matrix_field_changes": counts["matrix_field_changes"],
    "remaining_gaps": {
        "route_paths": counts["remaining_missing_route_paths"],
        "page_files": counts["remaining_missing_page_files"],
        "both_route_and_page": counts["remaining_missing_both_route_and_page"],
        "backend_anchors": counts["remaining_missing_backend_anchors"],
        "test_anchors": counts["remaining_missing_test_anchors"],
    },
    "outputs": evidence["outputs"],
    "history_rewritten": False,
    "benchmark_mapping_credit": 0,
    "runtime_credit": 0,
    "browser_credit": 0,
    "executed_test_credit": 0,
    "pass_credit": 0,
    "completion_credit": 0,
}, separators=(",", ":")))
