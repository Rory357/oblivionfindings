#!/usr/bin/env python3
"""Synchronise current audit summaries to the canonical 901 register.

This rewrites audit evidence only. Frozen derivation-stage artifacts are not
recomputed; they receive explicit supersession markers instead.
"""

from __future__ import annotations

import csv
import hashlib
import json
import os
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path


GENERATOR_DIR = Path(__file__).resolve().parent
AUDIT = GENERATOR_DIR.parent
SOURCE = AUDIT / "evidence" / "source"
COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
MANIFEST_NAME = "working-capability-manifest-901.json"
MANIFEST_SHA = "5b477cc3fa5e5343b223b7ba559919f708f945426f193dbb0510245771148900"
BENCHMARK_NAME = "benchmark-final-901-mapping.json"
BENCHMARK_SHA = "e3b650ab6303424e925acdcf6a4c9d0077de9d472bd71d8cb362290aa9294cc5"
GAP_NAME = "route-page-gap-reconciliation-901.json"
GAP_SHA = "00ed9729f729e8d52a9fe8adafc1243d2e676572ef5c5ad941952bfcbec5eec2"


def load(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8-sig"))


def save(path: Path, value: dict) -> None:
    temp = path.with_suffix(path.suffix + ".tmp")
    temp.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    json.loads(temp.read_text(encoding="utf-8"))
    os.replace(temp, path)


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def csv_shape(name: str) -> tuple[int, int, str]:
    path = AUDIT / name
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.reader(handle)
        rows = list(reader)
    return len(rows) - 1, len(rows[0]), sha(path)


manifest = load(SOURCE / MANIFEST_NAME)
benchmark = load(SOURCE / BENCHMARK_NAME)
findings = load(AUDIT / "findings.json")
visual_summary = load(SOURCE / "final-901-visual-link-generation-summary.json")
task_summary = load(SOURCE / "final-901-task-script-generation-summary.json")
browser_role_pass = load(SOURCE / "browser-representative-role-pass-901.json")

assert sha(SOURCE / MANIFEST_NAME) == MANIFEST_SHA
assert sha(SOURCE / BENCHMARK_NAME) == BENCHMARK_SHA
assert sha(SOURCE / GAP_NAME) == GAP_SHA
assert len(manifest["targets"]) == 901
assert len(findings["findings"]) == 77
assert browser_role_pass.get("audited_commit") == COMMIT
assert len(browser_role_pass.get("role_observations", [])) == 11
assert browser_role_pass.get("required_actor_coverage", {}).get("observed") == 11

NOW = datetime.now(timezone.utc).isoformat()
COUNTS = {"total": 901, "H": 788, "D": 111, "M": 2}
PROVENANCE = {"exact_current": 881, "source_stable": 5, "audit_assigned": 15}
ROUTES = {"targets": 901, "relations": 3065, "accepted_unique": 2985, "excluded": 39, "total": 3024, "percent": 98.71}
PAGES = {"targets": 756, "relations": 1507, "accepted_unique": 935, "excluded": 27, "total": 962, "percent": 97.19}
BACKEND = {"targets": 728, "relations": 825, "unique_anchors": 466}
BENCH = {"eligible": 302, "verified": 218, "verified_direct": 196, "verified_rename": 22, "ncm": 84, "ncm_direct": 77, "ncm_rename": 7, "unproved": 599}
VISUAL = {"rows": 8753, "assigned": 7785, "unresolved": 968, "unique": 742, "lineage": 821}
MATERIAL = {"rows": 4312, "assigned": 3749, "unresolved": 563, "unique": 688, "percent": 86.94}
manifest_ids = {target["working_key"] for target in manifest["targets"]}
finding_rows = findings["findings"]
finding_priorities = Counter(row["priority"] for row in finding_rows)
finding_exact_pairs = [
    (row["id"], feature_id)
    for row in finding_rows
    for feature_id in row.get("feature_ids", [])
    if feature_id in manifest_ids
]
finding_exact_ids = {finding_id for finding_id, _ in finding_exact_pairs}
p0p1_rows = [row for row in finding_rows if row["priority"] in {"P0", "P1"}]
p0p1_exact_ids = {row["id"] for row in p0p1_rows} & finding_exact_ids
FINDING = {
    "total": len(finding_rows),
    "P0": finding_priorities.get("P0", 0),
    "P1": finding_priorities.get("P1", 0),
    "P2": finding_priorities.get("P2", 0),
    "links": sum(len(row.get("feature_ids", [])) for row in finding_rows),
    "exact_links": len(finding_exact_pairs),
    "exact_targets": len({feature_id for _, feature_id in finding_exact_pairs}),
    "with_exact": len(finding_exact_ids),
    "without_exact": len(finding_rows) - len(finding_exact_ids),
    "p0p1": len(p0p1_rows),
    "p0p1_exact": len(p0p1_exact_ids),
    "p0p1_without": len(p0p1_rows) - len(p0p1_exact_ids),
    "uncertain": sum(
        bool(row.get("feature_link_reconciliation", {}).get("uncertainties"))
        for row in finding_rows
    ),
}
WAVE_DECISION_ID = "exact-current-901-source-intersection-wave-2026-08-13"
BASE_EXPLICIT_FINDING_IDS = {
    "FIN-INSIGHTS-DIRECT-OBJECT-01",
    "SITE-RBAC-001",
    "AUTH-EMAIL-VERIFY-CONTRACT-01",
    "HR-COMPLIANCE-EXPORT-PERMISSION-01",
    "HR-COMPLIANCE-RENEWALS-DISCLOSURE-01",
}
wave_finding_ids = set()
wave_link_count = 0
for finding in finding_rows:
    for decision in finding.get("feature_link_reconciliation", {}).get("decisions", []):
        if decision.get("legacy_family_id") == WAVE_DECISION_ID:
            wave_finding_ids.add(finding["id"])
            wave_link_count += len(decision.get("feature_ids", []))
explicit_re_adjudicated_findings = sorted(BASE_EXPLICIT_FINDING_IDS | wave_finding_ids)
explicit_re_adjudicated_links = 10 + wave_link_count
assert len(wave_finding_ids) == 36 and wave_link_count == 78
assert len(explicit_re_adjudicated_findings) == 41 and explicit_re_adjudicated_links == 88


def working_manifest_summary() -> dict:
    return {
        "path": MANIFEST_NAME, "sha256": MANIFEST_SHA, "rows": 901, "unique_stable_ids": 901,
        "classes": {"H": 788, "D": 111, "M": 2}, "stable_id_provenance": PROVENANCE,
        "route_enrichment": {
            "targets": ROUTES["targets"], "relations": ROUTES["relations"], "unique_routes": ROUTES["accepted_unique"],
            "inventory_routes": ROUTES["total"], "accepted_percent": ROUTES["percent"],
            "excluded_surface_relations": ROUTES["excluded"], "static_disposition_total": ROUTES["total"],
        },
        "page_enrichment": {
            "targets": PAGES["targets"], "relations": PAGES["relations"], "unique_pages": PAGES["accepted_unique"],
            "inventory_pages": PAGES["total"], "accepted_percent": PAGES["percent"],
            "excluded_surface_relations": PAGES["excluded"], "static_disposition_total": PAGES["total"],
        },
        "backend_enrichment": BACKEND,
        "benchmark_mapping": {
            "eligible": BENCH["eligible"], "verified_benchmark": BENCH["verified"],
            "verified_direct": BENCH["verified_direct"], "verified_rename": BENCH["verified_rename"],
            "documented_no_credible_match": BENCH["ncm"], "documented_ncm_direct": BENCH["ncm_direct"],
            "documented_ncm_rename": BENCH["ncm_rename"], "completion_unproved": BENCH["unproved"],
        },
        "derivation_note": "The 901 register supersedes the 894 stage after source adjudication added four reachable human jobs and three parameter-distinct exports. Excluded surfaces remain outside H/D/M counts.",
    }


def gate(completed: int, denominator: int, status: str, detail: str = "") -> dict:
    value = {"completed": completed, "denominator": denominator, "percent": round(100 * completed / denominator, 2), "status": status}
    if detail:
        value["detail"] = detail
    return value


# Completion gate report.
path = SOURCE / "completion-gate-report.json"
report = load(path)
report["audit_boundary"] = "Audit artifacts only for filesystem writes. No application code, configuration, routes, domain data, tests, deployment or Git history was changed. Authorised impersonation created only normal start/stop audit-log entries, and the browser identity was restored to Demo Administrator."
report["canonical_register"] = {"total": 901, "H": 788, "D": 111, "M": 2, "manifest": MANIFEST_NAME, "manifest_sha256": MANIFEST_SHA}
gates = report["gates"]
gates["canonical_features_registered"] = gate(901, 901, "complete-static-identity-only")
gates["routes_mapped_to_accepted_canonical_feature_id"] = gate(
    2985, 3024, "blocked",
    "39 routes are classified under excluded non-denominator SURFACE dispositions, not accepted canonical capability IDs. Static disposition is complete, but the prompt's literal FEATURE-ID mapping gate is not.",
)
gates["pages_mapped_to_accepted_canonical_feature_id"] = gate(
    935, 962, "blocked",
    "27 pages are classified under excluded non-denominator SURFACE dispositions, not accepted canonical capability IDs. Static disposition is complete, but the prompt's literal FEATURE-ID mapping gate is not.",
)
gates["combined_route_page_accepted_feature_id_mapping"] = gate(
    3920, 3986, "blocked",
    "66 classified surfaces remain outside the accepted capability denominator under SURFACE dispositions; they are not silently counted as canonical FEATURE-ID mappings.",
)
gates["routes_with_stable_static_disposition_id"] = gate(3024, 3024, "complete-static-disposition", "2,985 routes map to accepted targets; 39 retain excluded non-denominator SURFACE dispositions.")
gates["pages_with_stable_static_disposition_id"] = gate(962, 962, "complete-static-disposition", "935 pages map to accepted targets; 27 retain excluded non-denominator SURFACE dispositions.")
gates["combined_route_page_static_disposition"] = gate(3986, 3986, "complete-static-disposition")
gates["feature_benchmark_or_documented_no_match"] = gate(302, 901, "blocked", "218 verified benchmark mappings (196 direct, 22 rename) and 84 target-specific NCM decisions (77 direct, 7 rename); 599 unproved.")
gates["final_id_task_scripts_structural"] = gate(788, 788, "complete-structural-only", "788 Markdown files and scorecard rows; current scores blank and runtime unexecuted.")
gates["ten_dimension_ease_scores_measured_and_independently_validated"] = gate(0, 788, "blocked")
gates["representative_role_tasks_executed"] = gate(0, 788, "blocked")
gates["representative_actor_classes"] = gate(
    11, 12, "blocked-partial-browser-sample",
    "Eleven required actor classes were sampled read-only in the signed-in demo session; the Clinical/Medication Lead filter returned no current user, and task-level completion remains unexecuted.",
)
gates["visual_rows_linked_to_exact_final_feature_id"] = gate(7785, 8753, "blocked", "968 unresolved; 742 final IDs assigned and 821 have some visual lineage.")
gates["material_required_states_linked_to_exact_final_feature_id"] = gate(3749, 4312, "blocked", "563 unresolved; 688 final IDs represented; runtime execution 0/4,312.")
gates["visual_findings_independently_resampled"] = {
    "completed": 0,
    "denominator": None,
    "percent": None,
    "status": "blocked-denominator-unestablished",
    "detail": "The current evidence does not establish a canonical denominator of material hero and overlay finding families or a finding-ID-level independent resample numerator. Do not infer completion from general fresh-review prose.",
}
gates["p0_p1_required_evidence_fields"] = gate(FINDING["p0p1"], FINDING["p0p1"], "complete-structural")
gates["p0_p1_exact_final_feature_link"] = gate(
    FINDING["p0p1_exact"], FINDING["p0p1"], "blocked",
    f"{FINDING['p0p1_exact']}/{FINDING['p0p1']} P0/P1 findings contain a literal current ID; "
    f"{FINDING['p0p1_without']} do not. Literal equality is not runtime validation.",
)
gates["p0_p1_exact_owner_or_explicit_no_owner_disposition"] = gate(
    FINDING["p0p1"], FINDING["p0p1"], "complete-static-accountability",
    "65 P0/P1 findings have literal current-target links; CTRL-SIGNAL-002 has an explicit shared-backend no-owner disposition. This is not runtime validation.",
)
gates["findings_with_neutral_requirements_and_no_copy_boundary"] = gate(FINDING["total"], FINDING["total"], "complete-structural")
gates["agent_assignments_reconciled_and_none_running"] = gate(1, 1, "complete-process", "71 real assignments/partials are represented in the reconciliation register; no subagent remains running.")
report["completion_blockers"] = [
    "routes_mapped_to_accepted_canonical_feature_id", "pages_mapped_to_accepted_canonical_feature_id",
    "combined_route_page_accepted_feature_id_mapping", "visual_findings_independently_resampled",
    "feature_benchmark_or_documented_no_match", "ten_dimension_ease_scores_measured_and_independently_validated",
    "representative_role_tasks_executed", "journeys_executed_all_viewports", "safe_routes_against_all_user_facing_gets",
    "fully_measured_component_viewport_rows", "visual_rows_linked_to_exact_final_feature_id",
    "custom_overlay_static_trigger_classification", "primitive_overlay_static_trigger_classification",
    "material_required_states_linked_to_exact_final_feature_id", "representative_actor_classes", "tests_executed",
    "benchmark_project_specific_triage", "p0_p1_exact_final_feature_link", "modules_with_all_eight_passes_complete",
    "pass8_fresh_reconciliation",
]
report["remaining_static_work_not_requiring_user_input"] = [
    "Target-specific benchmark/NCM research for 599 targets.",
    "Resolve 968 visual rows and 563 material-state rows without family-level inheritance.",
    "Preserve the explicit no-owner disposition for CTRL-SIGNAL-002 unless the denominator is deliberately reopened for its shared backend processing job.",
]
save(path, report)


# CSV semantic validation.
path = SOURCE / "csv-semantic-validation.json"
csv_report = load(path)
shapes = csv_report["current_csv_shapes"]
for name, required in (("02-eight-pass-coverage-ledger.csv", 901), ("03-feature-to-benchmark-matrix.csv", 901), ("04-workflow-usability-scorecard.csv", 788), ("05-browser-visual-coverage-matrix.csv", 8753)):
    rows, columns, digest = csv_shape(name)
    shapes[name]["data_rows"] = rows
    shapes[name]["columns"] = columns
    shapes[name]["sha256"] = digest
    shapes[name]["required_rows"] = required
shapes["03-feature-to-benchmark-matrix.csv"].update({"benchmark_mapped": 302, "benchmark_verified": 218, "benchmark_documented_no_credible_match": 84, "benchmark_completion_unproved": 599})
shapes["04-workflow-usability-scorecard.csv"].update({"runtime_executed": 0, "independently_reviewed": 0, "current_scores_measured": 0})
shapes["05-browser-visual-coverage-matrix.csv"].update({
    "semantic_tuple_sha256": visual_summary["outputs"]["semantic_tuple_sha256"],
    "assigned_final_feature_id": 7785, "unresolved_final_feature_id": 968,
    "unique_assigned_final_feature_ids": 742, "manifest_ids_with_any_visual_lineage": 821,
})
csv_report["working_manifest"] = working_manifest_summary()
csv_report["semantic_checks"]["all_route_page_inventory_ids_have_static_disposition"] = True
csv_report["completion_boundary"] = "Structural identity, route/page static disposition and task files are complete. Benchmark research, exact visual/finding linkage, current ease scores, representative runtime roles/tasks/states, project-specific benchmark triage and tests remain incomplete."
save(path, csv_report)


# Validation report.
path = SOURCE / "validation-report.json"
validation = load(path)
validation["validation_scope"] = "current canonical 901 bundle after deterministic inventory, ledger, task-script, benchmark and partial visual/finding-link generation"
checks = validation["checks"]
checks.pop("corrected_894_denominator_independently_reestablished", None)
checks["corrected_901_denominator_independently_reestablished"] = True
checks["downstream_manifest_integration_complete"] = True
checks["all_routes_pages_have_stable_static_disposition_ids"] = True
checks["all_routes_pages_mapped_to_accepted_canonical_feature_ids"] = False
checks["visual_finding_resample_denominator_and_independent_coverage_established"] = False
checks["all_agents_received_and_reconciled"] = True
checks["fresh_pass8_after_current_rebuild"] = False
validation["working_manifest"] = working_manifest_summary()
validation["structural_errors"] = [
    "All 3,024 route IDs and 962 page IDs have stable static dispositions; 39 routes and 27 pages are deliberately excluded non-denominator surfaces rather than accepted capabilities.",
    "The prompt's literal accepted FEATURE-ID mapping gate is 2,985/3,024 routes and 935/962 pages, not 100%; excluded SURFACE dispositions close classification only.",
    "The material hero/overlay finding-family resample denominator and finding-ID-level independent numerator are not established, so that completion gate remains blocked.",
    "The feature benchmark gate is 302/901; 599 targets remain completion-unproved.",
    "The 788 task scripts and scorecard rows are structural only: a bounded 11-of-12-actor browser sample exists, but 0 canonical task scripts, 0 independent usability reviews and 0 current ten-dimension scores are complete.",
    "The visual matrix assigns 7,785/8,753 rows to final IDs and leaves 968 unresolved; its material-state subset assigns 3,749/4,312 and leaves 563 unresolved.",
    f"Finding linkage is incomplete: {FINDING['exact_links']} literal exact-ID links across {FINDING['exact_targets']} final targets and {FINDING['with_exact']} findings do not establish runtime validation. Only {FINDING['p0p1_exact']}/{FINDING['p0p1']} P0/P1 findings contain at least one literal current-manifest ID; {FINDING['p0p1_without']}/{FINDING['p0p1']} do not.",
    "61 of 97 benchmark projects have catalogue-level rather than project-specific triage.",
    "Final independent static bundle validation and process-register closure passed. Substantive product-evidence gates remain blocked.",
]
validation["current_artifact_hashes"] = {
    "manifest_sha256": MANIFEST_SHA, "benchmark_mapping_sha256": BENCHMARK_SHA,
    "route_page_gap_sha256": GAP_SHA, "inventory_sha256": sha(AUDIT / "inventory.json"),
    "findings_sha256": sha(AUDIT / "findings.json"),
    "02_ledger_sha256": sha(AUDIT / "02-eight-pass-coverage-ledger.csv"),
    "03_matrix_sha256": sha(AUDIT / "03-feature-to-benchmark-matrix.csv"),
    "04_scorecard_sha256": sha(AUDIT / "04-workflow-usability-scorecard.csv"),
    "05_visual_matrix_sha256": sha(AUDIT / "05-browser-visual-coverage-matrix.csv"),
    "05_visual_semantic_tuple_sha256": visual_summary["outputs"]["semantic_tuple_sha256"],
    "browser_representative_role_pass_sha256": sha(SOURCE / "browser-representative-role-pass-901.json"),
}
validation["completion_blockers"] = report["completion_blockers"]
save(path, validation)


# Fresh Pass-8 bundle reconciliation.
path = SOURCE / "fresh-pass8-bundle-reconciliation.json"
fresh = load(path)
fresh["review_scope"] = "Fresh static reconciliation of the canonical 901 audit bundle after route/page disposition, benchmark, ledger, task and visual regeneration"
fresh["denominator"] = {
    "accepted_total": 901, "human_ui": 788, "download_or_api": 111, "machine_ingress": 2,
    "superseded_894_base": 894, "source_adjudicated_additions": 7,
    "source_families": 595, "route_references": 3024, "unique_pages": 962,
    "arithmetic_validated": True, "working_manifest": MANIFEST_NAME, "working_manifest_sha256": MANIFEST_SHA,
    "working_manifest_unique_stable_ids": 901, "stable_id_provenance": PROVENANCE,
    "accepted_route_enrichment": ROUTES, "accepted_page_enrichment": PAGES, "backend_enrichment": BACKEND,
    "full_static_surface_disposition": {"routes": "3024/3024", "pages": "962/962"},
    "durable_working_target_manifest_materialized": True,
}
fresh["artifact_integration"] = {
    "inventory": {"rows": 901, "status": "canonical-static-register-current"},
    "02_eight_pass_coverage_ledger": {"rows": 901, "status": "canonical-structural-current"},
    "03_feature_benchmark_matrix": {"rows": 901, "mapped": 302, "blocked": 599, "status": "canonical-structural-current-substantive-coverage-blocked"},
    "04_workflow_usability_scorecard": {"rows": 788, "measured_scores": 0, "runtime_executed": 0, "status": "canonical-structural-current-runtime-blocked"},
    "05_browser_visual_matrix": {"rows": 8753, "final_id_assigned": 7785, "unresolved": 968, "status": "partial-final-id-linkage"},
    "task_scripts_final_901": {"files": 788, "nul_files": 0, "runtime_executed": 0, "status": "canonical-structural-current-runtime-blocked"},
    "browser_representative_role_pass": {"roles_sampled": 11, "canonical_tasks_completed": 0, "status": "bounded-read-only-sample-not-completion"},
}
fresh["current_visual_state_reconciliation"] = {
    "rows": 8753, "assigned_to_final_id": 7785, "unresolved": 968,
    "assigned_unique_final_ids": 742, "final_ids_with_any_visual_lineage": 821,
    "classification_counts": visual_summary["counts"]["classification_counts"],
    "material_state_rows": 4312, "material_state_final_id_assigned": 3749,
    "material_state_unresolved": 688, "material_state_unique_final_ids": 626, "runtime_executed": 0,
}
fresh.setdefault("runtime_blockers", {})["representative_actor_classes_executed"] = "11/12 (bounded signed-in browser sample; Clinical/Medication Lead unavailable; no canonical task completion)"
fresh["benchmark_reconciliation"] = {
    "catalogue_projects": 97, "substantive_project_specific_triage": 36, "catalogue_level_only": 61,
    "final_targets_with_completion_credit": 302, "verified_benchmark": 218,
    "verified_direct": 196, "verified_rename": 22, "documented_no_credible_match": 84,
    "documented_ncm_direct": 77, "documented_ncm_rename": 7, "completion_unproved": 599,
    "mapping_artifact": BENCHMARK_NAME, "mapping_sha256": BENCHMARK_SHA,
}
fresh["finding_reconciliation"] = {
    "findings": FINDING["total"], "p0": FINDING["P0"], "p1": FINDING["P1"], "p2": FINDING["P2"], "links": FINDING["links"],
    "literal_exact_current_links": FINDING["exact_links"], "literal_exact_current_targets": FINDING["exact_targets"],
    "explicitly_re_adjudicated_findings": explicit_re_adjudicated_findings,
    "p0_p1_with_core_required_fields": FINDING["p0p1"], "p0_p1_with_exact_final_feature_id": FINDING["p0p1_exact"],
    "p0_p1_without_exact_final_feature_id": FINDING["p0p1_without"], "findings_with_exact_final_feature_id": FINDING["with_exact"],
    "findings_without_exact_final_feature_id": FINDING["without_exact"], "final_link_coverage_established": False,
    "definition_boundary": "Literal stable-ID equality is not runtime or target-outcome validation.",
}
fresh["retained_audit_reproducibility_generators"] = {
    "inventory": "generators/rebuild-canonical-inventory-register.py", "ledgers": "generators/rebuild-final-901-ledgers.py",
    "tasks": "generators/rebuild-final-901-task-scripts.ps1", "visual_links": "generators/rebuild-final-901-visual-links.py",
    "findings": "generators/append-three-901-findings.py", "summary_sync": "generators/sync-current-901-summaries.py",
}
fresh["remaining_reconciliation_order"] = [
    "Complete 599 target-specific benchmark/NCM decisions.", "Resolve 968 visual and 563 material-state final-ID links.",
    "Preserve CTRL-SIGNAL-002 as an explicit current-manifest no-owner finding unless a denominator reopen is authorised.",
    "Provide a dedicated Clinical/Medication Lead user and expand the bounded 11-role sample into canonical task execution; obtain resettable fixtures and an isolated test runtime.",
    "Execute task, failure, recovery, handoff and required viewport validation.",
]
save(path, fresh)


# Material-state summary.
path = SOURCE / "material-state-reconciliation.json"
material = load(path)
material["generated_at"] = NOW
material["denominator_status"] = "canonical_901_register_materialized_material_links_partial_runtime_unexecuted"
material["working_human_capabilities"] = 788
material["earlier_894_derivation_superseded"] = True
material["working_manifest"] = working_manifest_summary()
material["final_feature_linkage"] = {
    "assigned_rows": 3749, "unresolved_rows": 563, "assigned_unique_final_feature_ids": 688, "percent": 86.94,
    "proof_boundary": "Static identity linkage only; it does not establish rendered, recovery or completion behavior.",
}
material["final_feature_link_completion_credit"] = 3749
material["runtime_state_completion_credit"] = 0
save(path, material)


# Finding-link reconciliation rebuilt directly from findings.json.
path = SOURCE / "finding-link-reconciliation.json"
link_report = load(path)
manifest_ids = {row["working_key"] for row in manifest["targets"]}
rows = findings["findings"]
exact = [(row["id"], feature) for row in rows for feature in row.get("feature_ids", []) if feature in manifest_ids]
exact_findings = {finding_id for finding_id, _ in exact}
p0p1 = [row for row in rows if row["priority"] in {"P0", "P1"}]
p0p1_exact = {row["id"] for row in p0p1} & exact_findings
link_report["generated_at"] = NOW
link_report["status"] = "current_901_literal_link_reconciliation_partial_runtime_unverified"
link_report["scope_boundary"] = "Links preserve source evidence and literal current IDs; neither literal equality nor route/page intersection establishes runtime outcome completion."
link_report["current_final_id_link_summary"] = {
    "literal_links": len(exact), "literal_targets": len({feature for _, feature in exact}),
    "explicitly_re_adjudicated_links": explicit_re_adjudicated_links,
    "explicitly_re_adjudicated_findings": explicit_re_adjudicated_findings,
    "findings_with_literal_exact_current_id": len(exact_findings),
    "findings_without_literal_exact_current_id": len(rows) - len(exact_findings),
    "p0_p1_with_literal_exact_current_id": len(p0p1_exact),
    "p0_p1_without_literal_exact_current_id": len(p0p1) - len(p0p1_exact), "complete": False,
}
decisions = [decision for row in rows for decision in row.get("feature_link_reconciliation", {}).get("decisions", [])]
link_report["counts"] = {
    "findings": len(rows), "total_links": sum(len(row.get("feature_ids", [])) for row in rows),
    "findings_with_uncertainty": sum(bool(row.get("feature_link_reconciliation", {}).get("uncertainties")) for row in rows),
    "findings_without_literal_exact_current_id": len(rows) - len(exact_findings),
    "route_intersection_groups": sum(bool(decision.get("route_hits")) for decision in decisions),
    "unique_page_intersection_groups": sum(bool(decision.get("page_hits")) for decision in decisions),
    "one_to_one_groups": sum("one-to-one" in str(decision.get("method", "")).lower() for decision in decisions),
}
link_report["findings"] = [
    {"finding_id": row["id"], "feature_ids": row.get("feature_ids", []), "literal_current_feature_ids": [feature for feature in row.get("feature_ids", []) if feature in manifest_ids], "reconciliation": row.get("feature_link_reconciliation", {})}
    for row in rows
]
save(path, link_report)


# Historical-stage authority labels.
path = SOURCE / "capability-integration-reconciliation.json"
historical = load(path)
historical["current_authority_summary"] = {
    "status": "canonical_901_register_current", "manifest": MANIFEST_NAME, "manifest_sha256": MANIFEST_SHA,
    "counts": COUNTS, "benchmark_completion_credit": 302, "benchmark_completion_unproved": 599,
    "note": "All other projection counts in this artifact are frozen historical lineage evidence.",
}
save(path, historical)

path = SOURCE / "final-capability-denominator-reconciliation.json"
historical = load(path)
historical["status"] = "historical_superseded_894_denominator_derivation_evidence_only"
historical["superseded_by"] = ["capability-denominator-901-adjudication.json", MANIFEST_NAME]
historical["current_authority"] = {"manifest": MANIFEST_NAME, "sha256": MANIFEST_SHA, "counts": COUNTS}
save(path, historical)

path = SOURCE / "static-route-enrichment-application-summary.json"
historical = load(path)
historical["status"] = "historical_894_enrichment_stage_superseded_by_901_static_disposition_register"
historical["superseded_by"] = [MANIFEST_NAME, GAP_NAME, "canonical-inventory-register-generation-summary.json"]
historical["derivation_stage_boundary"] = "Frozen 894-stage enrichment evidence; do not read its after-counts as current authority."
save(path, historical)

path = SOURCE / "visual-matrix-generation-summary.json"
historical = load(path)
historical["superseded_by"] = "final-901-visual-link-generation-summary.json"
historical["note"] = "Historical pre-link snapshot only. Current authority is final-901-visual-link-generation-summary.json: 7,785/8,753 assigned, 968 unresolved, 742 assigned IDs, 821 IDs with lineage; material subset 3,749/4,312 assigned and 563 unresolved."
save(path, historical)

print(json.dumps({
    "status": "current_901_summaries_synchronised_runtime_still_blocked",
    "manifest": MANIFEST_SHA, "inventory": sha(AUDIT / "inventory.json"), "findings": sha(AUDIT / "findings.json"),
    "ledger": sha(AUDIT / "02-eight-pass-coverage-ledger.csv"), "matrix": sha(AUDIT / "03-feature-to-benchmark-matrix.csv"),
    "scorecard": sha(AUDIT / "04-workflow-usability-scorecard.csv"), "visual": sha(AUDIT / "05-browser-visual-coverage-matrix.csv"),
}, indent=2))
