#!/usr/bin/env python3
"""Finalize validation hash pins for the active canonical 904 audit bundle."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any


AUDIT = Path(__file__).resolve().parent.parent
SOURCE = AUDIT / "evidence" / "source"
RUN_AT = "2026-08-21T22:50:00+12:00"
VALIDATION = SOURCE / "validation-report.json"
POINTER = SOURCE / "canonical-audit-inputs.json"
MANIFEST = SOURCE / "working-capability-manifest-904.json"
BENCHMARK = SOURCE / "benchmark-final-904-mapping.json"
GAP = SOURCE / "route-page-gap-reconciliation-904.json"
SURFACE_RECONCILIATION = SOURCE / "route-page-source-provenance-reconciliation-2026-08-23.json"
INVENTORY = AUDIT / "inventory-904.json"
LEDGER = AUDIT / "02-eight-pass-coverage-ledger-904.csv"
MATRIX = AUDIT / "03-feature-to-benchmark-matrix-904.csv"
SCORECARD = AUDIT / "04-workflow-usability-scorecard-904.csv"
VISUAL = AUDIT / "05-browser-visual-coverage-matrix-904.csv"
VISUAL_SUMMARY = SOURCE / "final-904-visual-link-generation-summary.json"
WAVE32 = SOURCE / "benchmark-target-specific-adjudication-904-wave32.json"
WAVE33 = SOURCE / "benchmark-target-specific-adjudication-904-wave33.json"
WAVE34 = SOURCE / "benchmark-target-specific-adjudication-904-wave34.json"
OVERLAY_WAVE1 = SOURCE / "overlay-trigger-adjudication-904-wave1.json"
OVERLAY_WAVE2 = SOURCE / "overlay-trigger-adjudication-904-wave2.json"
VISUAL_WAVE03 = SOURCE / "visual-final-id-adjudication-904-wave03.json"
OVERLAY_CLASSIFIER = SOURCE / "overlay-trigger-classification.json"
PASS8_HR = SOURCE / "pass8-human-resources-904-2026-08-21.json"
HR_WEBHOOK_SUMMARY = SOURCE / "final-904-hr-webhook-ssrf-generation-summary.json"
FINDING_RECONCILIATION = SOURCE / "finding-link-reconciliation.json"
OFFICIAL_FINDING_MAP = SOURCE / "official-nz-finding-proposition-map.json"
COMPLETION = SOURCE / "completion-gate-report.json"
ORCHESTRATION = SOURCE / "orchestration-status-2026-08-14.json"
REMEDIATION_DELIVERY = SOURCE / "remediation-delivery-snapshot-2026-08-23.json"
DASHBOARD = AUDIT / "audit-dashboard.html"
FINDINGS = AUDIT / "findings.json"
DEPLOYED_LOGIN_RESAMPLE = AUDIT / "evidence" / "browser" / "deployed-public-login-resample-2026-08-21.json"


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def load(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def write(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")


def record(path: Path) -> dict[str, Any]:
    return {"path": path.relative_to(AUDIT).as_posix(), "sha256": sha(path), "bytes": path.stat().st_size}


pointer_input = load(POINTER)
manifest = load(MANIFEST)
benchmark = load(BENCHMARK)
visual_summary = load(VISUAL_SUMMARY)
deployed_login_resample = load(DEPLOYED_LOGIN_RESAMPLE)
completion = load(COMPLETION)
findings_doc = load(FINDINGS)
findings = findings_doc.get("findings", findings_doc)
finding_reconciliation = load(FINDING_RECONCILIATION)
official_finding_map = load(OFFICIAL_FINDING_MAP)
overlay_classifier = load(OVERLAY_CLASSIFIER)
surface_reconciliation = load(SURFACE_RECONCILIATION)
GENERATED_AT = max(
    [RUN_AT, str(pointer_input.get("generated_at", ""))]
    + [
        str(document.get("generated_at", ""))
        for document in (
            manifest,
            benchmark,
            visual_summary,
            deployed_login_resample,
            completion,
            findings_doc,
            finding_reconciliation,
            official_finding_map,
            overlay_classifier,
        )
        if isinstance(document, dict)
    ]
)
dashboard_source = DASHBOARD.read_text(encoding="utf-8")
priority = {key: sum(row["priority"] == key for row in findings) for key in ("P0", "P1", "P2")}
benchmark_summary = benchmark["summary"]
eligible = benchmark_summary["eligible_total"]
unproved = benchmark_summary["completion_unproved"]["total"]
verified = benchmark_summary["verified_benchmark"]["total"]
verified_direct = benchmark_summary["verified_benchmark"]["direct"]
verified_rename = benchmark_summary["verified_benchmark"]["strict_one_to_one_rename"]
ncm = benchmark_summary["documented_no_credible_match"]["total"]
ncm_direct = benchmark_summary["documented_no_credible_match"]["direct"]
ncm_rename = benchmark_summary["documented_no_credible_match"]["strict_one_to_one_rename"]
p0_p1 = priority["P0"] + priority["P1"]
literal_links = finding_reconciliation["current_final_id_link_summary"]["literal_links"]
overlay_exact = overlay_classifier["custom_usage_layer"]["exact_trigger_resolved"]
page_total = surface_reconciliation["page_provenance"]["prompt_page_denominator"]
page_mapped = surface_reconciliation["page_provenance"]["accepted_feature_id_mapped"]
page_unmapped = page_total - page_mapped
route_total = surface_reconciliation["route_provenance"]["streams"]["all"]["rows"]
route_mapped = surface_reconciliation["route_provenance"]["streams"]["source_backed"]["rows"]
assert {key: manifest["counts"][key] for key in ("total", "H", "D", "M")} == {"total": 904, "H": 790, "D": 111, "M": 3}
assert eligible + unproved == 904
assert verified + ncm == eligible
assert visual_summary["counts"]["assigned_final_feature_id"] == 8168
assert sum(priority.values()) == len(findings)
assert official_finding_map["reviewed"] == official_finding_map["denominator"]
assert finding_reconciliation["current_final_id_link_summary"]["p0_p1_with_literal_exact_current_id"] == p0_p1
assert overlay_exact <= 659
assert len(completion["completion_blockers"]) == 19
assert surface_reconciliation["independent_review"]["combined_source_family_route_page_union_reconciled"] is True
assert (route_total, route_mapped, page_total, page_mapped) == (3024, 2994, 727, 714)
dashboard_denominators_current = all(
    literal in dashboard_source
    for literal in (
        "904 stable capability identities.",
        '<div class="track-count">904 / 904</div>',
        "790 human · 111 API/download · 3 machine",
        '<div class="track-count">0 / 790</div>',
        "Open the complete 904-row benchmark matrix",
        "Current authority: 904-capability audit snapshot",
    )
)
dashboard_current_counts = all(
    literal in dashboard_source
    for literal in (
        f'{eligible} / 904',
        f'{unproved} remain',
        f'{verified} verified analogues plus {ncm} bounded',
        f'aria-label="{verified} verified, {ncm} no credible match, {unproved} unproved"',
    )
)
dashboard_stale_live_denominators_absent = not any(
    literal in dashboard_source
    for literal in (
        "902 stable capability identities",
        "How the 902 capabilities divide today",
        "Current authority: 902-capability audit snapshot",
        "0/788 canonical human tasks",
        "0 / 788</div>",
        "All 788 human capabilities",
    )
)
assert dashboard_denominators_current
assert dashboard_current_counts
assert dashboard_stale_live_denominators_absent

validation = load(VALIDATION)
validation.update({
    "schema_version": "4.1",
    "generated_at": GENERATED_AT,
    "status": "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE",
    "validation_scope": "active canonical 904 bundle after all pointer-registered benchmark, visual, overlay and Pass-8 source-only waves",
    "structural_errors": [
        "All 3,024 route identities and all 727 true Inertia-page identities have reconciled static provenance. The 962-file resources/js/pages source census remains separately classified.",
        f"Literal accepted FEATURE-ID ownership is {route_mapped:,}/{route_total:,} routes and {page_mapped}/{page_total} true Inertia pages; {route_total - route_mapped} routes and {page_unmapped} pages remain unmapped.",
        f"The feature benchmark gate is {eligible}/904; {unproved} targets remain completion-unproved.",
        "The 790 task scripts and scorecard rows are structural only: 0 canonical tasks, 0 independent usability reviews and 0 current ten-dimension scores are complete.",
        "The visual matrix assigns 8,168/8,753 rows to final IDs and leaves 585 unresolved; material-state linkage is 3,948/4,312 with 364 unresolved.",
        "Finding linkage and remediation statuses remain evidence-bounded; source-ready, branch-published and main-merged states do not imply runtime verification.",
        "Substantive product-evidence gates remain blocked despite coherent static successor artifacts.",
    ],
    "working_manifest": {
        "path": "working-capability-manifest-904.json", "sha256": sha(MANIFEST), "rows": 904, "unique_stable_ids": 904,
        "classes": {"H": 790, "D": 111, "M": 3},
        "stable_id_provenance": {"exact_current": 881, "source_stable": 5, "audit_assigned": 18},
        "route_enrichment": {"targets": 903, "relations": 3076, "unique_routes": 2994, "inventory_routes": 3024, "accepted_percent": 99.01, "excluded_surface_relations": 30, "static_disposition_total": 3024},
        "page_enrichment": {"targets": 682, "relations": 968, "unique_pages": page_mapped, "inventory_pages": page_total, "accepted_percent": round(page_mapped / page_total * 100, 2), "unmapped_pages": page_unmapped, "static_disposition_total": page_total},
        "source_tree_page_enrichment": {"targets": 756, "relations": 1526, "unique_files_with_accepted_relations": 945, "classified_source_files": 962},
        "backend_enrichment": {"targets": 731, "relations": 830, "unique_anchors": 469},
        "benchmark_mapping": {"eligible": eligible, "verified_benchmark": verified, "verified_direct": verified_direct, "verified_rename": verified_rename, "documented_no_credible_match": ncm, "documented_ncm_direct": ncm_direct, "documented_ncm_rename": ncm_rename, "completion_unproved": unproved},
        "derivation_note": "The versioned 904 successor preserves the frozen 902 artifacts and adds two omitted source-distinct medical human capabilities. The active pointer selects the successor; excluded surfaces remain outside H/D/M counts.",
    },
    "completion_blockers": completion["completion_blockers"],
    "secret_pattern_hit_locations": [],
    "current_human_facing_summary": {
        "status": "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE", "findings": len(findings),
        "p0": priority["P0"], "p1": priority["P1"], "p2": priority["P2"], "p0_p1": priority["P0"] + priority["P1"],
        "literal_exact_current_id_links": literal_links, "completion_blockers": 19,
        "historical_browser_evidence_pin": validation["current_human_facing_summary"]["historical_browser_evidence_pin"],
        "active_denominator": 904, "benchmark_decided": eligible, "visual_assigned": 8168,
    },
})
validation["checks"].update({
    "source_family_route_page_union_reconciled": True,
    "route_source_provenance_reconciled": True,
    "inertia_page_denominator_reconciled": True,
    "final_benchmark_or_no_match_coverage": False,
    "evidence_backed_current_ease_scores_complete": False,
    "representative_tasks_executed": False,
    "final_visual_feature_links": False,
    "tests_executed": False,
    "all_agents_received_and_reconciled": False,
    "fresh_pass8_after_current_rebuild": False,
    "all_routes_pages_mapped_to_accepted_canonical_feature_ids": False,
    "visual_finding_resample_denominator_and_independent_coverage_established": False,
    "visual_finding_resample_denominator_established": True,
    "visual_finding_independent_runtime_resample_complete": False,
})
validation["checks"].update({
    "canonical_904_successor_active": True,
    "frozen_902_artifacts_preserved": True,
    "wave32_target_specific_adjudication_integrated": True,
    "wave33_target_specific_adjudication_integrated": True,
    "wave34_target_specific_adjudication_integrated": True,
    "wave35_target_specific_adjudication_integrated": True,
    "wave36_target_specific_adjudication_integrated": True,
    "overlay_trigger_wave1_integrated_static_only": True,
    "overlay_trigger_wave2_integrated_static_only": True,
    "overlay_trigger_wave3_integrated_static_only": True,
    "overlay_trigger_wave4_integrated_static_only": True,
    "overlay_trigger_wave5_integrated_static_only": True,
    "visual_wave904_03_zero_promotion_integrated": True,
    "visual_wave904_04_zero_promotion_integrated": True,
    "visual_wave904_05_zero_promotion_integrated": True,
    "visual_wave904_06_zero_promotion_integrated": True,
    "medication_reader_site_concealment_finding_integrated": True,
    "pass8_human_resources_source_challenge_integrated": True,
    "hr_webhook_outbound_ssrf_finding_integrated": True,
    "clinical_protocol_scheduling_finding_integrated": True,
    "fleet_booking_site_privacy_finding_integrated": True,
    "visual_904_final_id_links_integrated": True,
    "dashboard_visible_and_embedded_counts_derived_from_904": True,
    "dashboard_live_904_and_790_denominators_rendered": dashboard_denominators_current,
    "dashboard_current_benchmark_counts_rendered": dashboard_current_counts,
    "dashboard_stale_live_902_and_788_denominators_absent": dashboard_stale_live_denominators_absent,
    "completion_status_remains_blocked": True,
    "deployed_login_resample_is_unauthenticated_read_only": (
        deployed_login_resample["browser"]["credentials_entered"] is False
        and deployed_login_resample["browser"]["authenticated_task_executed"] is False
    ),
    "deployed_login_resample_awards_zero_completion_credit": (
        deployed_login_resample["credit_boundary"]["completion_credit_delta"] == 0
        and deployed_login_resample["credit_boundary"]["audited_baseline_browser_credit_delta"] == 0
        and deployed_login_resample["credit_boundary"]["current_main_browser_credit_delta"] == 0
    ),
    "deployed_login_resample_retains_release_identity_blocker": (
        deployed_login_resample["source_boundary"]["deployed_git_or_release_identifier_exposed"] is False
    ),
})

hashes = validation["current_artifact_hashes"]
hashes.update({
    "manifest_sha256": sha(MANIFEST), "benchmark_mapping_sha256": sha(BENCHMARK), "route_page_gap_sha256": sha(GAP),
    "inventory_sha256": sha(INVENTORY), "findings_sha256": sha(FINDINGS), "02_ledger_sha256": sha(LEDGER),
    "03_matrix_sha256": sha(MATRIX), "04_scorecard_sha256": sha(SCORECARD), "05_visual_matrix_sha256": sha(VISUAL),
    "05_visual_semantic_tuple_sha256": visual_summary["outputs"]["semantic_tuple_sha256"],
    "benchmark_wave32_adjudication_sha256": sha(WAVE32),
    "benchmark_wave33_adjudication_sha256": sha(WAVE33),
    "benchmark_wave34_adjudication_sha256": sha(WAVE34),
    "overlay_trigger_wave1_adjudication_sha256": sha(OVERLAY_WAVE1),
    "overlay_trigger_wave2_adjudication_sha256": sha(OVERLAY_WAVE2),
    "visual_wave904_03_adjudication_sha256": sha(VISUAL_WAVE03),
    "overlay_trigger_classification_sha256": sha(OVERLAY_CLASSIFIER),
    "pass8_human_resources_sha256": sha(PASS8_HR),
    "hr_webhook_ssrf_generation_summary_sha256": sha(HR_WEBHOOK_SUMMARY),
    "finding_link_reconciliation_sha256": sha(FINDING_RECONCILIATION),
    "official_nz_finding_proposition_map_sha256": sha(OFFICIAL_FINDING_MAP),
    "route_page_source_provenance_reconciliation_sha256": sha(SURFACE_RECONCILIATION),
    "00_executive_summary_sha256": sha(AUDIT / "00-executive-summary.md"),
    "01_repository_module_map_sha256": sha(AUDIT / "01-repository-module-map.md"),
    "07_module_findings_sha256": sha(AUDIT / "07-module-findings.md"),
    "09_ui_ux_accessibility_visual_consistency_sha256": sha(AUDIT / "09-ui-ux-accessibility-visual-consistency.md"),
    "10_architecture_data_integration_security_sha256": sha(AUDIT / "10-architecture-data-integration-security.md"),
    "13_unresolved_questions_sha256": sha(AUDIT / "13-unresolved-questions-and-evidence-gaps.md"),
    "completion_gate_report_sha256": sha(COMPLETION), "orchestration_status_sha256": sha(ORCHESTRATION),
    "remediation_delivery_snapshot_sha256": sha(REMEDIATION_DELIVERY),
    "audit_dashboard_sha256": sha(DASHBOARD),
    "deployed_public_login_resample_sha256": sha(DEPLOYED_LOGIN_RESAMPLE),
})
for prefix, pattern in (
    ("benchmark_wave", "benchmark-target-specific-adjudication-904-wave*.json"),
    ("visual_wave", "visual-final-id-adjudication-904-wave*.json"),
    ("overlay_wave", "overlay-trigger-adjudication-904-wave*.json"),
    ("pass8", "pass8-*-904-2026-08-21.json"),
    ("generation_summary", "final-904-*-generation-summary.json"),
):
    for path in sorted(SOURCE.glob(pattern), key=lambda item: item.name):
        key = f"{prefix}_{path.stem.replace('-', '_')}_sha256"
        hashes[key] = sha(path)
write(VALIDATION, validation)

pointer = load(POINTER)
pointer["generated_at"] = max(str(pointer.get("generated_at", "")), GENERATED_AT)
pointer["artifacts"]["validation_report"] = record(VALIDATION)
pointer["artifacts"]["dashboard"] = record(DASHBOARD)
pointer["artifacts"]["remediation_delivery_snapshot"] = record(REMEDIATION_DELIVERY)
pointer["artifacts"]["deployed_public_login_resample"] = record(DEPLOYED_LOGIN_RESAMPLE)
pointer["artifacts"]["route_page_source_provenance_reconciliation"] = record(SURFACE_RECONCILIATION)
write(POINTER, pointer)

print(json.dumps({"validation": record(VALIDATION), "dashboard": record(DASHBOARD), "active_inputs": record(POINTER), "checks": len(validation["checks"]), "blockers": len(completion["completion_blockers"])}, indent=2))
