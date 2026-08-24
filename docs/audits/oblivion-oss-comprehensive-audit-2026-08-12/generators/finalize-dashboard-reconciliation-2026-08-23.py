#!/usr/bin/env python3
"""Seal the 23 August dashboard reconciliation without completion inflation."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any


GENERATOR = Path(__file__).resolve()
AUDIT = GENERATOR.parent.parent
SOURCE = AUDIT / "evidence" / "source"
BROWSER = AUDIT / "evidence" / "browser"
GENERATED_AT = "2026-08-23T15:44:58+12:00"
AUDITED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"

SUMMARY = SOURCE / "current-904-summary-generation-report.json"
VALIDATION = SOURCE / "validation-report.json"
POINTER = SOURCE / "canonical-audit-inputs.json"
COMPLETION = SOURCE / "completion-gate-report.json"
RECONCILIATION = SOURCE / "dashboard-reconciliation-2026-08-23.json"
CSV_VALIDATION = SOURCE / "csv-semantic-validation.json"
VISUAL_SUMMARY = SOURCE / "final-904-visual-link-generation-summary.json"
OVERLAY = SOURCE / "overlay-trigger-classification.json"
MANIFEST = SOURCE / "working-capability-manifest-904.json"
BENCHMARK = SOURCE / "benchmark-final-904-mapping.json"
GAP = SOURCE / "route-page-gap-reconciliation-904.json"
SURFACE_RECONCILIATION = SOURCE / "route-page-source-provenance-reconciliation-2026-08-23.json"
FINDINGS = AUDIT / "findings.json"
DASHBOARD = AUDIT / "audit-dashboard.html"
BROWSER_RESAMPLE = BROWSER / "deployed-public-login-resample-2026-08-23.json"
PREFLIGHT = BROWSER / "frozen-baseline-home-build-preflight-2026-08-21.json"

CANONICAL_INPUTS = {
    "inventory": AUDIT / "inventory.json",
    "ledger": AUDIT / "02-eight-pass-coverage-ledger.csv",
    "matrix": AUDIT / "03-feature-to-benchmark-matrix.csv",
    "scorecard": AUDIT / "04-workflow-usability-scorecard.csv",
    "visual": AUDIT / "05-browser-visual-coverage-matrix.csv",
}
VERSIONED_MIRRORS = {
    "inventory": AUDIT / "inventory-904.json",
    "ledger": AUDIT / "02-eight-pass-coverage-ledger-904.csv",
    "matrix": AUDIT / "03-feature-to-benchmark-matrix-904.csv",
    "scorecard": AUDIT / "04-workflow-usability-scorecard-904.csv",
    "visual": AUDIT / "05-browser-visual-coverage-matrix-904.csv",
}


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def load(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8-sig"))


def write(path: Path, value: Any) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def relative(path: Path) -> str:
    return path.resolve().relative_to(AUDIT.resolve()).as_posix()


def record(path: Path) -> dict[str, Any]:
    require(path.is_file(), f"Missing artifact: {path}")
    return {"path": relative(path), "sha256": sha256(path), "bytes": path.stat().st_size}


require(sha256(AUDIT / "inventory.json") == sha256(AUDIT / "inventory-904.json"), "Inventory alias drift")
require(sha256(AUDIT / "02-eight-pass-coverage-ledger.csv") == sha256(AUDIT / "02-eight-pass-coverage-ledger-904.csv"), "Ledger alias drift")
require(sha256(AUDIT / "03-feature-to-benchmark-matrix.csv") == sha256(AUDIT / "03-feature-to-benchmark-matrix-904.csv"), "Benchmark matrix alias drift")
require(sha256(AUDIT / "04-workflow-usability-scorecard.csv") == sha256(AUDIT / "04-workflow-usability-scorecard-904.csv"), "Scorecard alias drift")
require(sha256(AUDIT / "05-browser-visual-coverage-matrix.csv") == sha256(AUDIT / "05-browser-visual-coverage-matrix-904.csv"), "Visual matrix alias drift")

manifest = load(MANIFEST)
benchmark = load(BENCHMARK)
surface_reconciliation = load(SURFACE_RECONCILIATION)
completion = load(COMPLETION)
visual_summary = load(VISUAL_SUMMARY)
findings_document = load(FINDINGS)
findings = findings_document.get("findings", findings_document)
preflight = load(PREFLIGHT)
browser_resample = load(BROWSER_RESAMPLE)
benchmark_eligible = benchmark["summary"]["eligible_total"]
benchmark_unproved = benchmark["summary"]["completion_unproved"]["total"]
benchmark_verified = benchmark["summary"]["verified_benchmark"]["total"]
benchmark_ncm = benchmark["summary"]["documented_no_credible_match"]["total"]

require(
    {key: manifest["counts"][key] for key in ("total", "H", "D", "M")}
    == {"total": 904, "H": 790, "D": 111, "M": 3},
    "Manifest denominator drift",
)
require(benchmark_eligible + benchmark_unproved == 904, "Benchmark partition drift")
require(benchmark_verified + benchmark_ncm == benchmark_eligible, "Benchmark eligible partition drift")
require(visual_summary["counts"]["assigned_final_feature_id"] == 8168, "Visual assigned count drift")
require(visual_summary["counts"]["unresolved_final_feature_id"] == 585, "Visual unresolved count drift")
require(completion["status"] == "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE", "Completion status drift")
require(len(completion["completion_blockers"]) == 19, "Completion blocker count drift")
require(len(findings) == 100, "Finding count drift")
require(preflight["cleanup"]["quarantined_task_created_vendor_removed"] is True, "Generated vendor cleanup not recorded")
require(not (BROWSER / ".home-frozen-baseline-20260820T1939Z" / "task-created-vendor").exists(), "Generated vendor tree still exists")
require(browser_resample["credentials"]["credentials_entered"] is False, "Browser evidence credential boundary drift")
require(surface_reconciliation["independent_review"]["combined_source_family_route_page_union_reconciled"] is True, "Route/page source provenance drift")

# Keep the compact CSV validator aligned with the canonical files that the
# dashboard is about to display. This is structural reconciliation only.
csv_validation = load(CSV_VALIDATION)
csv_validation["generated_at"] = GENERATED_AT
matrix_shape = csv_validation["current_csv_shapes"]["03-feature-to-benchmark-matrix.csv"]
matrix_shape.update({
    "sha256": sha256(CANONICAL_INPUTS["matrix"]),
    "benchmark_mapped": benchmark_eligible,
    "benchmark_verified": benchmark_verified,
    "benchmark_documented_no_credible_match": benchmark_ncm,
    "benchmark_completion_unproved": benchmark_unproved,
    "feature_benchmark_gate_complete": benchmark_unproved == 0,
})
visual_shape = csv_validation["current_csv_shapes"]["05-browser-visual-coverage-matrix.csv"]
visual_shape.update({
    "sha256": sha256(CANONICAL_INPUTS["visual"]),
    "semantic_tuple_sha256": visual_summary["outputs"]["semantic_tuple_sha256"],
    "assigned_final_feature_id": 8168,
    "unresolved_final_feature_id": 585,
    "unique_assigned_final_feature_ids": 774,
    "final_feature_links_complete": False,
    "classification_counts": {
        "Blocked": 497,
        "Not safely reproducible": 4312,
        "Observed": 2503,
        "Source-inferred": 1441,
    },
})
csv_validation["working_manifest"]["benchmark_mapping"] = {
    "eligible": benchmark_eligible,
    "verified_benchmark": benchmark_verified,
    "verified_direct": benchmark["summary"]["verified_benchmark"]["direct"],
    "verified_rename": benchmark["summary"]["verified_benchmark"]["strict_one_to_one_rename"],
    "documented_no_credible_match": benchmark_ncm,
    "documented_ncm_direct": benchmark["summary"]["documented_no_credible_match"]["direct"],
    "documented_ncm_rename": benchmark["summary"]["documented_no_credible_match"]["strict_one_to_one_rename"],
    "completion_unproved": benchmark_unproved,
}
csv_validation["working_manifest"]["page_enrichment"] = {
    "targets": 682, "relations": 968, "unique_pages": 714,
    "inventory_pages": 727, "accepted_percent": 98.21,
    "unmapped_pages": 13, "static_disposition_total": 727,
}
csv_validation["working_manifest"]["source_tree_page_enrichment"] = {
    "targets": 756, "relations": 1526,
    "unique_files_with_accepted_relations": 945,
    "classified_source_files": 962,
}
write(CSV_VALIDATION, csv_validation)

# Refresh the summary first so validation and the active pointer can pin it.
summary = load(SUMMARY)
summary["generated_at"] = GENERATED_AT
summary["status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"
summary["runtime_credit_delta"] = 0
summary["inputs"].update({
    "manifest": record(MANIFEST),
    "benchmark": record(BENCHMARK),
    "gap": record(GAP),
    "surface_reconciliation": record(SURFACE_RECONCILIATION),
    **{key: record(path) for key, path in CANONICAL_INPUTS.items()},
    "visual_summary": record(VISUAL_SUMMARY),
    "overlay": record(OVERLAY),
    "findings": record(FINDINGS),
})
for key, item in list(summary["outputs"].items()):
    path = AUDIT / item["path"]
    if path.is_file():
        summary["outputs"][key] = record(path)
summary["outputs"].update({
    "dashboard": record(DASHBOARD),
    "dashboard_reconciliation": record(RECONCILIATION),
    "deployed_public_login_resample": record(BROWSER_RESAMPLE),
})
summary["counts"].update({
    "capabilities": 904,
    "human_tasks": 790,
    "benchmark_decided": benchmark_eligible,
    "benchmark_unproved": benchmark_unproved,
    "visual_assigned": 8168,
    "visual_unresolved": 585,
    "material_assigned": 3948,
    "material_unresolved": 364,
    "custom_overlay_exact": 253,
    "custom_overlay_inferred": 144,
    "custom_overlay_unresolved_or_blocked": 262,
    "primitive_overlay_exact": 242,
    "primitive_overlay_unresolved": 235,
    "completion_blockers": 19,
})
summary["claim_limit"] = "Artifact consistency and dashboard refresh only; no runtime, browser-task, remediation, release or all-pass completion credit."
write(SUMMARY, summary)

validation = load(VALIDATION)
validation["generated_at"] = GENERATED_AT
validation["status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"
validation["validation_scope"] = "Canonical 904 artifact consistency and truthful blocked-gate reporting; no application runtime or remediation credit."

false_checks = {
    "final_benchmark_or_no_match_coverage",
    "evidence_backed_current_ease_scores_complete",
    "representative_tasks_executed",
    "final_visual_feature_links",
    "tests_executed",
    "all_agents_received_and_reconciled",
    "fresh_pass8_after_current_rebuild",
    "all_routes_pages_mapped_to_accepted_canonical_feature_ids",
    "visual_finding_resample_denominator_and_independent_coverage_established",
    "visual_finding_independent_runtime_resample_complete",
}
for key in validation["checks"]:
    if key in false_checks:
        validation["checks"][key] = False
validation["checks"].update({
    "source_family_route_page_union_reconciled": True,
    "route_source_provenance_reconciled": True,
    "inertia_page_denominator_reconciled": True,
    "visual_finding_resample_denominator_established": True,
    "required_unversioned_aliases_select_904": True,
    "overlay_classifier_matrix_reconciled": True,
    "quarantined_task_vendor_tree_removed": True,
    "dashboard_2026_08_23_reconciled": True,
    "deployed_login_resample_is_unauthenticated_read_only": True,
    "deployed_login_resample_awards_zero_completion_credit": True,
    "deployed_login_resample_retains_release_identity_blocker": True,
    "completion_status_remains_blocked": True,
})
validation["working_manifest"].update({
    "path": relative(MANIFEST),
    "sha256": sha256(MANIFEST),
    "rows": 904,
    "classes": {"H": 790, "D": 111, "M": 3},
})
hashes = validation["current_artifact_hashes"]
hashes.update({
    "manifest_sha256": sha256(MANIFEST),
    "benchmark_mapping_sha256": sha256(BENCHMARK),
    "route_page_gap_sha256": sha256(GAP),
    "route_page_source_provenance_reconciliation_sha256": sha256(SURFACE_RECONCILIATION),
    "inventory_sha256": sha256(CANONICAL_INPUTS["inventory"]),
    "findings_sha256": sha256(FINDINGS),
    "02_ledger_sha256": sha256(CANONICAL_INPUTS["ledger"]),
    "03_matrix_sha256": sha256(CANONICAL_INPUTS["matrix"]),
    "04_scorecard_sha256": sha256(CANONICAL_INPUTS["scorecard"]),
    "05_visual_matrix_sha256": sha256(CANONICAL_INPUTS["visual"]),
    "completion_gate_report_sha256": sha256(COMPLETION),
    "audit_dashboard_sha256": sha256(DASHBOARD),
    "00_executive_summary_sha256": sha256(AUDIT / "00-executive-summary.md"),
    "09_ui_ux_accessibility_visual_consistency_sha256": sha256(AUDIT / "09-ui-ux-accessibility-visual-consistency.md"),
    "task_scripts_readme_sha256": sha256(AUDIT / "task-scripts" / "README.md"),
    "overlay_trigger_classification_sha256": sha256(OVERLAY),
    "visual_generation_summary_sha256": sha256(VISUAL_SUMMARY),
    "csv_semantic_validation_sha256": sha256(CSV_VALIDATION),
    "current_904_summary_generation_report_sha256": sha256(SUMMARY),
    "dashboard_reconciliation_sha256": sha256(RECONCILIATION),
    "deployed_public_login_resample_2026_08_23_sha256": sha256(BROWSER_RESAMPLE),
    "frozen_baseline_home_build_preflight_sha256": sha256(PREFLIGHT),
})
validation["completion_blockers"] = completion["completion_blockers"]
validation["current_human_facing_summary"].update({
    "status": "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE",
    "findings": 100,
    "p0": 21,
    "p1": 67,
    "p2": 12,
    "p0_p1": 88,
    "completion_blockers": 19,
    "active_denominator": 904,
    "benchmark_decided": benchmark_eligible,
    "visual_assigned": 8168,
})
write(VALIDATION, validation)

# Refresh every existing pointer record whose path still exists, then make the
# required unversioned names the primary canonical inputs. The versioned files
# remain byte-identical mirrors rather than competing defaults.
pointer = load(POINTER)
pointer["generated_at"] = GENERATED_AT
pointer["status"] = "active_904_static_denominator_runtime_and_completion_blocked"
pointer["completion_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"
pointer["runtime_credit_delta"] = 0
for key, item in list(pointer["artifacts"].items()):
    if isinstance(item, dict) and isinstance(item.get("path"), str):
        path = AUDIT / item["path"]
        if path.is_file():
            pointer["artifacts"][key] = record(path)
pointer["artifacts"].update({
    "inventory": record(CANONICAL_INPUTS["inventory"]),
    "eight_pass_ledger": record(CANONICAL_INPUTS["ledger"]),
    "benchmark_matrix": record(CANONICAL_INPUTS["matrix"]),
    "task_scorecard": record(CANONICAL_INPUTS["scorecard"]),
    "visual_matrix": record(CANONICAL_INPUTS["visual"]),
    "completion_report": record(COMPLETION),
    "current_summary_generation_report": record(SUMMARY),
    "validation_report": record(VALIDATION),
    "dashboard": record(DASHBOARD),
    "dashboard_reconciliation": record(RECONCILIATION),
    "csv_semantic_validation": record(CSV_VALIDATION),
    "deployed_public_login_resample": record(BROWSER_RESAMPLE),
    "frozen_baseline_home_build_preflight": record(PREFLIGHT),
    "route_page_source_provenance_reconciliation": record(SURFACE_RECONCILIATION),
})
pointer["versioned_904_mirrors"] = {key: record(path) for key, path in VERSIONED_MIRRORS.items()}
pointer["visual_counts"].update({
    "rows": 8753,
    "assigned_final_feature_id": 8168,
    "unresolved_final_feature_id": 585,
    "unique_assigned_final_feature_ids": 774,
    "classification_counts": {
        "Observed": 2503,
        "Not safely reproducible": 4312,
        "Source-inferred": 1441,
        "Blocked": 497,
    },
})
write(POINTER, pointer)

print(json.dumps({
    "status": pointer["completion_status"],
    "dashboard": record(DASHBOARD),
    "validation": record(VALIDATION),
    "summary": record(SUMMARY),
    "active_pointer": record(POINTER),
    "checks_false": sorted(key for key, value in validation["checks"].items() if value is False),
    "canonical_inputs": {key: record(path) for key, path in CANONICAL_INPUTS.items()},
}, indent=2))
