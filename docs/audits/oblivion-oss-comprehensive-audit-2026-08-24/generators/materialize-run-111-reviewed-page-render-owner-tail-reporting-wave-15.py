#!/usr/bin/env python3
"""Materialize RUN-111 reporting for the independently reviewed RUN-110 overlay."""

from __future__ import annotations

import hashlib
import json
import runpy
import subprocess
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_RELATIVE = "evidence/source/current-run-111-reviewed-page-render-owner-tail-reporting-wave-15.json"
CHECKPOINT_COMMIT = "3fa8ca96bf939520c3e952af8f1182302a1c3ce1"
CHECKPOINT_TREE = "d9b45ebe89fd32b3db3f4cdeb5faf1aa481b04c3"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
RECORDS_SHA256 = "118c1a1f19b2e300c77d5b4d71c60f75b7038382f3c71e18f078367f8473e260"

PRIOR = runpy.run_path(str(AUDIT_DIR / "generators/materialize-run-107-reviewed-page-render-owner-reporting-wave-14.py"))
path = PRIOR["path"]
sha256_file = PRIOR["sha256_file"]
read_json = PRIOR["read_json"]
canonical_json_sha256 = PRIOR["canonical_json_sha256"]
write_lf = PRIOR["write_lf"]
replace_once_or_present = PRIOR["replace_once_or_present"]
upsert_section_before = PRIOR["upsert_section_before"]
replace_line_containing = PRIOR["replace_line_containing"]
PRESERVED = PRIOR["PRESERVED"]

CURRENT_REPORT_INPUTS = {
    "00-executive-summary.md": "46665d3563fdd8843510f109ce6190227f471d6bc97f820bd82f1be2680cb656",
    "01-repository-module-map.md": "c696139498cc9162a7f8b1af9830d7cea02ab25dd510663a73daea0ab4799791",
    "13-unresolved-questions-and-evidence-gaps.md": "e6a048be0554102931516b5d422abcaec6b8d6934b14efd56b4fab54e46e6f40",
    "findings.json": "dbfdba58e9f4fa86e1cfdcc32dff5550c2f04143b5f8904f0d614100c4805362",
    "generators/build-current-audit-dashboard.py": "8cb5eafa53dab3f781d5eed85a8074303c2ac083e4ee5480233fa25ec40aabd0",
}

PINNED_INPUTS = {
    "generators/materialize-run-107-reviewed-page-render-owner-reporting-wave-14.py": "4e0cc512006312207755fea09cbb09a9e702878157daddcb4283eceea08d5e8e",
    "evidence/source/current-run-107-reviewed-page-render-owner-reporting-wave-14.json": "83e52ffb239fcd8fdff72eb02fba1a96258659f4b7e891275227adca4f85aea2",
    "evidence/browser/current-audit-dashboard-verification-run-108-wave-14.json": "1ec434d0a30703a50da0d3def477fdeb4f671f0e03b0a85326f238b89d428f79",
    "generators/build-outcome-neutral-page-render-owner-tail-cohort-wave-15.py": "1005eaad8d3bcecf99f04b40f912e5181f28e33ef5acb044c27ba0201d0c8e0c",
    "evidence/source/root-run-109-outcome-neutral-page-render-owner-tail-cohort-wave-15.json": "9019306fc317374b673d76fc6023efc11deb1f7f83be67d0df72d196cd076187",
    "generators/materialize-independent-outcome-neutral-page-render-owner-tail-review-wave-15.py": "afd6646d04d53f8585eb2dbbeb706fbf5db24a0ccaa404d1d9042ff0773cf184",
    "evidence/source/raw-run-109r-independent-outcome-neutral-page-render-owner-tail-review-wave-15.json": "2d0110c3b44a3e226549d2f9bc3b4fed76d7fed2e70094c04ccf7c3c0c7c94f0",
    "generators/integrate-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.py": "8f57e6b888652f67edcea2671239a5403f15e9d144fc369eb2791e2bbd41f9d7",
    "evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json": "9375fb374b589c5068334795fa03d80a7ba1fd35695808f99c9e2591e886efca",
    "generators/materialize-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-review-wave-15.py": "534c7e8658729637cdfcb8a87d68782a0cd9da04e6b034c624fcc0d1886c9f88",
    "evidence/source/raw-run-110r-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json": "e9b076e790e5346f99665f8f99ee609b4c7b7bac4767e416abc73a57f7dfd867",
}


def git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args], cwd=REPO, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True
    )
    return result.stdout.strip()


def replace_between(text: str, start: str, end: str, replacement: str, label: str) -> str:
    if replacement in text:
        return text
    assert text.count(start) == 1, (label, "start", text.count(start))
    start_index = text.index(start)
    assert text.count(end, start_index) == 1, (label, "end", text.count(end, start_index))
    end_index = text.index(end, start_index)
    return text[:start_index] + replacement + text[end_index:]


def assert_inputs() -> tuple[dict[str, Any], dict[str, Any]]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("status", "--porcelain", "--", "app", "routes", "resources/js") == ""
    for relative, expected in PINNED_INPUTS.items():
        assert sha256_file(relative) == expected, relative
    for relative, expected in PRESERVED.items():
        assert sha256_file(relative) == expected, relative

    receipt_path = path(OUTPUT_RELATIVE)
    if receipt_path.exists():
        prior = read_json(OUTPUT_RELATIVE)
        for relative, expected in prior["outputs"].items():
            assert sha256_file(relative) == expected, relative
    else:
        for relative, expected in CURRENT_REPORT_INPUTS.items():
            assert sha256_file(relative) == expected, relative

    overlay = read_json("evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json")
    review = read_json("evidence/source/raw-run-110r-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json")
    assert review["decision"]["verdict"] == "GO"
    assert review["decision"]["mechanical_discrepancies"] == 0
    assert review["decision"]["semantic_boundary_discrepancies"] == 0
    assert review["decision"]["queue_accounting_discrepancies"] == 0
    assert review["decision"]["reporting_authorized"] is True
    assert review["decision"]["gate_4_complete"] is False
    assert overlay["combined_counts"] == review["verified_counts"]
    assert overlay["queue_accounting"] == review["verified_queue_accounting"]
    counts = overlay["combined_counts"]
    assert (counts["source_owner_records"], counts["route_owner_records"], counts["page_owner_records"]) == (614, 265, 349)
    assert (counts["distinct_feature_ids"], counts["distinct_H_feature_ids"], counts["distinct_D_feature_ids"]) == (256, 234, 22)
    assert (counts["route_distinct_feature_ids"], counts["page_distinct_feature_ids"], counts["route_page_feature_overlap"]) == (59, 242, 45)
    assert counts["bounded_static_source_residual_records"] == 3315
    assert counts["static_controller_action_bridges"] == 53
    assert (counts["semantic_shared_page_roots"], counts["residual_unadjudicated_page_roots"], counts["evidence_gap_page_roots_tagged_within_residual"]) == (9, 353, 1)
    assert overlay["reviewed_overlay"]["owner_pages"] == 2
    assert overlay["reviewed_overlay"]["shared_relations"] == 4
    assert overlay["reviewed_overlay"]["evidence_gaps"] == 0
    assert overlay["queue_accounting"]["reviewed_queue_surface_rows"] == 60
    assert overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 447
    assert overlay["queue_accounting"]["queue_surfaces_without_ownership"] == 453
    assert overlay["denominator_boundary"]["gate_4_complete"] is False
    assert overlay["audit_completion_test_met"] is False
    return overlay, review


def patch_reports() -> None:
    summary_relative = "00-executive-summary.md"
    summary = path(summary_relative).read_text(encoding="utf-8")
    summary = replace_once_or_present(
        summary,
        "## RUN-105–107 reviewed outcome-neutral page render-owner overlay",
        "## Historical RUN-105–107 reviewed outcome-neutral page render-owner overlay",
        "historical Wave 14 summary heading",
    )
    summary = replace_once_or_present(
        summary,
        "The current bounded checkpoint is **612 records = 265 routes + 347 pages across 256 canonical FEATURE-IDs (234 H + 22 D)**, with 53 controller-action bridges.",
        "That bounded checkpoint was **612 records = 265 routes + 347 pages across 256 canonical FEATURE-IDs (234 H + 22 D)**, with 53 controller-action bridges.",
        "historical Wave 14 summary count",
    )
    summary = replace_once_or_present(
        summary,
        "RUN-107 reports only that bounded page delta.",
        "RUN-107 reported only that bounded page delta.",
        "historical Wave 14 reporting tense",
    )
    section = """
## RUN-109–111 reviewed outcome-neutral page render-owner tail overlay

RUN-109 freezes the six remaining non-conflicting singleton page candidates without pre-awarding ownership. Fresh three-part review classifies the clinical-protocol Edit page and Fleet reporting/export workspace as `OWNER_PAGE`. The Privacy dashboard, eMAR Medications workspace, HR candidate detail, and HR employee hub are `SHARED_RELATION` surfaces spanning their complete canonical jobs. No alias, dead, or new evidence-gap outcome is created.

RUN-110 integrates only the two explicit owners as page-source records. It adds zero route owners and zero controller-action bridges, preserves all four shared pages as reviewed non-owners, and reconciles exact pending queue row `RUN090-PAGE-0003` as reviewed shared rather than owned. RUN-110R independently reproduces the final bytes, all 340 unique matrix IDs and 21 reviewed canonical IDs, row/source identities, queue sets, conservation equations, and zero-credit boundaries with zero discrepancies.

The current bounded checkpoint is **614 records = 265 routes + 349 pages across 256 canonical FEATURE-IDs (234 H + 22 D)**, with 53 controller-action bridges. Route and page owners still span 59 and 242 FEATURE-IDs with 45 in their overlap. This is 15.627386% of the bounded 3,929-record source universe; 3,315 records remain. The page universe is **711 = 349 owners + 9 shared + 353 residual**, with the existing evidence gap tagged inside that 353 rather than counted again. The route universe remains **3,218 = 265 owners + 5 shared + 3 aliases + 2,945 residual**. Queue accounting is **507 = 60 reviewed + 447 pending**; the reviewed set is 54 owned, three shared, and three aliases, while 453 remain without ownership.

RUN-111 reports only that bounded tail delta. Oblivion Findings remains one operating organisation across multiple Sites. Site access, roles/permissions, canonical ownership, direct-object denial, privacy, lifecycle, framework reachability, runtime, database, build, application browser, tests, benchmarks, ease, Passes, findings, completion, and Gate 4 remain separate open or zero-credit gates. The 340-row matrix remains byte-identical and mapping remains 0/340.
"""
    summary = upsert_section_before(
        summary,
        "## RUN-109–111 reviewed outcome-neutral page render-owner tail overlay",
        "\n## Current raw source census\n",
        section,
        "Wave 15 summary section",
    )
    marker = (
        "- `generators/materialize-run-107-reviewed-page-render-owner-reporting-wave-14.py` and "
        "`evidence/source/current-run-107-reviewed-page-render-owner-reporting-wave-14.json`: "
        "deterministic RUN-107 reporting refresh preserving the matrix, reports 02–12/inventory, queue, and every downstream zero-credit boundary.\n"
    )
    addition = marker + (
        "- `evidence/browser/current-audit-dashboard-verification-run-108-wave-14.json`: exact superseded RUN-107 dashboard artifact verification; no metrics transfer to the current dashboard.\n"
        "- `generators/build-outcome-neutral-page-render-owner-tail-cohort-wave-15.py` and `evidence/source/root-run-109-outcome-neutral-page-render-owner-tail-cohort-wave-15.json`: deterministic six-page tail cohort with zero pre-review credit and one explicitly reconciled pending queue overlap.\n"
        "- `generators/materialize-independent-outcome-neutral-page-render-owner-tail-review-wave-15.py` and `evidence/source/raw-run-109r-independent-outcome-neutral-page-render-owner-tail-review-wave-15.json`: fresh 2-owner / 4-shared page review with zero current ownership credit.\n"
        "- `generators/integrate-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.py` and `evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json`: exact two-page owner-only delta plus one reviewed-shared queue reconciliation.\n"
        "- `generators/materialize-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-review-wave-15.py` and `evidence/source/raw-run-110r-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json`: independent final-byte, queue-accounting, and semantic-boundary GO receipt.\n"
        "- `generators/materialize-run-111-reviewed-page-render-owner-tail-reporting-wave-15.py` and `evidence/source/current-run-111-reviewed-page-render-owner-tail-reporting-wave-15.json`: deterministic RUN-111 reporting refresh preserving the matrix, reports 02–12/inventory, and every downstream zero-credit boundary.\n"
    )
    summary = replace_once_or_present(summary, marker, addition, "Wave 15 evidence links")
    write_lf(summary_relative, summary)

    map_relative = "01-repository-module-map.md"
    module_map = path(map_relative).read_text(encoding="utf-8")
    module_map = replace_once_or_present(
        module_map,
        "## RUN-105–106 reviewed page render-owner overlay",
        "## Historical RUN-105–106 reviewed page render-owner overlay",
        "historical Wave 14 map heading",
    )
    module_map = replace_once_or_present(
        module_map,
        "The cumulative bounded ledger is 612 source owners (265 route + 347 page) across 256 FEATURE-IDs (234 H + 22 D).",
        "That cumulative bounded ledger was 612 source owners (265 route + 347 page) across 256 FEATURE-IDs (234 H + 22 D).",
        "historical Wave 14 map count",
    )
    map_section = """
## RUN-109–110 reviewed page render-owner tail overlay

RUN-109 freezes six pending singleton page roots for fresh outcome-neutral review. Two are explicit page owners and four are shared relations: Privacy dashboard, eMAR Medications, HR candidate detail, and the HR employee hub. Complete-page and canonical-matrix review, not containment or names, controls the outcome.

RUN-110 adds exactly two page owners, zero route owners, and zero controller-action bridges. The cumulative bounded ledger is 614 source owners (265 route + 349 page) across 256 FEATURE-IDs (234 H + 22 D). Route/page feature sets remain 59/242 with overlap 45, while the action-bridge count remains 53. Page accounting is 711 = 349 owners + 9 shared + 353 residual, with one earlier evidence gap tagged inside the residual. RUN-090 queue accounting is 507 total, 60 reviewed, 54 owned, three shared, three aliases, 447 pending, and 453 without ownership.

RUN-110R verifies exact final bytes, canonical IDs, queue reconciliation, and all boundaries with zero discrepancies. These relations establish bounded static page ownership and explicit reviewed-shared classification only; they do not establish framework reachability, Site or permission correctness, canonical direct-object concealment, privacy or lifecycle behaviour, runtime, build, browser, tests, benchmarks, findings, Passes, or completion.
"""
    module_map = upsert_section_before(
        module_map,
        "## RUN-109–110 reviewed page render-owner tail overlay",
        "\n## Candidate register\n",
        map_section,
        "Wave 15 map section",
    )
    write_lf(map_relative, module_map)

    gaps_relative = "13-unresolved-questions-and-evidence-gaps.md"
    gaps = path(gaps_relative).read_text(encoding="utf-8")
    gaps = replace_line_containing(
        gaps,
        "| Required reporting paths |",
        "| Required reporting paths | 18 / 18 prompt-required files or directories present. RUN-108 independently verified the exact now-superseded RUN-107 dashboard at four viewports; the regenerated RUN-111 dashboard requires a separate fresh RUN-112 artifact receipt. | Presence and prior audit-artifact verification make the reporting contract inspectable but grant no application execution, final-finding, Pass, or completion credit. Gate 26 remains open. | Complete the semantic, execution, benchmark, Pass 8, final reconciliation, and no-live-agent gates; repeat audit-dashboard verification after every later HTML change. |",
        "required paths row",
    )
    gaps = replace_line_containing(
        gaps,
        "| Runtime routes |",
        "| Runtime routes | RUN-110/R retain 265 bounded route-owner records and 53 static controller-action bridges; 2,945 residual explicit-unmapped route rows, five semantic-shared route rows, and three reviewed alias rows remain distinguished within the bounded 3,218-row static route-like universe. | RUN-109–110 add zero route or bridge records. Static owner/action linkage is not a framework-expanded route table or reachability proof. | Under a fresh bounded runtime grant, use an exact disposable database, execute a separately pinned framework/provider route lane, and reconcile it to all 38 source route files and 3,218 route-like rows. |",
        "runtime routes row",
    )
    gaps = replace_line_containing(
        gaps,
        "| Inertia pages |",
        "| Inertia pages | RUN-084/R enumerate 1,058 physical page-tree files. RUN-110/R establish 349 bounded page owners, preserve nine semantic-shared roots, and leave 353 residual roots including one tagged evidence gap. | Two reviewed page roots gain bounded ownership and four become reviewed shared. Full-tree structural GO and bounded ownership are not a complete canonical crosswalk, runtime reachability, build resolution, rendered browser behaviour, or final feature mapping. | Reconcile all 711 literal roots and support relations to safely expanded framework routes and frozen FEATURE-IDs, retain shared and gap relations explicitly, prove build resolution, and observe every safely reachable current-build page under approved roles/Sites. |",
        "pages row",
    )
    gaps = replace_line_containing(
        gaps,
        "| Canonical features |",
        "| Canonical features | Static canonical identity remains 340 targets: 300 H, 40 D, zero M; RUN-110/R establish 614 bounded source-owner records (265 routes + 349 pages) across 256 FEATURE-IDs (234 H + 22 D) plus 53 controller-action bridges while the matrix remains byte-identical at `dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390`. | This is 15.627386% of the bounded 3,929-record source universe. Gate 4 remains incomplete: 3,315 non-owner records, the framework-expanded denominator, shared, alias, and gap relations, reachability, and the full crosswalk remain open; matrix target mapping stays 0/340. | Finish canonical denominator and residual ownership adjudication without inheriting FEATURE-IDs from prefixes, imports, containment, names, or presence, and without awarding runtime, browser, test, benchmark, ease, release, or completion credit. |",
        "canonical row",
    )
    gaps = replace_line_containing(
        gaps,
        "| Agent universe and writer rule |",
        "| Agent universe and writer rule | RUN-001 through RUN-111 represented at the current reporting checkpoint; finalization gate false. | RUN-109/R review six pages as two owners and four shared; RUN-110/R independently integrate and verify two page owners plus one reviewed-shared queue row with zero route/bridge additions; RUN-111 reports only those bounded classes. Runtime, browser, tests, benchmarks, Pass 8, finalization, and completion remain open. | Complete residual ownership and every semantic/execution gate, then dispatch fresh Pass 8/final cross-reviewers, verify the final dashboard, and prove no agent remains live at finalization. |",
        "agent row",
    )
    gaps = replace_once_or_present(
        gaps,
        "## RUN-077–107 route/page, page-tree, backend, ownership and reporting lineage",
        "## RUN-077–111 route/page, page-tree, backend, ownership and reporting lineage",
        "lineage heading",
    )
    lineage = (
        "RUN-077 freezes the exhaustive committed-source route/name/page universe through RUN-084B's page-tree and backend structural ledgers. RUN-086/R establish the initial 530 bounded source owners; RUN-090 freezes the zero-credit direct-exact queue; RUN-091/R–103 successively add and report reviewed closed-chain and route/action ownership while preserving shared and alias outcomes, reaching 592 owners. RUN-104 verifies that superseded dashboard. RUN-105/R–107 review, integrate, and report 20 page owners, three shared relations, and one evidence gap, reaching 612 owners; RUN-108 verifies that superseded dashboard. RUN-109/R review the six-page tail as two owners and four shared relations. RUN-110/R integrate and independently verify exactly two page-source owners and one reviewed-shared queue reconciliation with zero route or bridge additions, reaching 614 owners across the same 256 FEATURE-IDs; RUN-111 reports only that bounded delta. Gate 4 and the full route/page/backend crosswalk remain incomplete; Oblivion Findings remains a single-tenant application for one organisation across multiple Sites, and framework reachability, Site/permission/privacy/direct-object/lifecycle correctness, runtime, build, signed-in application browser, executed tests, benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit."
    )
    gaps = replace_line_containing(
        gaps,
        "RUN-077 freezes the exhaustive committed-source route/name/page universe",
        lineage,
        "lineage paragraph",
    )
    write_lf(gaps_relative, gaps)


def patch_findings(overlay: dict[str, Any], review: dict[str, Any]) -> None:
    relative = "findings.json"
    findings = read_json(relative)
    assert canonical_json_sha256(findings["records"]) == RECORDS_SHA256
    pin_names = {
        "run_107_reporting_materializer_sha256": "generators/materialize-run-107-reviewed-page-render-owner-reporting-wave-14.py",
        "run_107_reporting_sha256": "evidence/source/current-run-107-reviewed-page-render-owner-reporting-wave-14.json",
        "run_108_dashboard_verification_sha256": "evidence/browser/current-audit-dashboard-verification-run-108-wave-14.json",
        "run_109_page_tail_cohort_generator_sha256": "generators/build-outcome-neutral-page-render-owner-tail-cohort-wave-15.py",
        "run_109_page_tail_cohort_sha256": "evidence/source/root-run-109-outcome-neutral-page-render-owner-tail-cohort-wave-15.json",
        "run_109r_page_tail_review_materializer_sha256": "generators/materialize-independent-outcome-neutral-page-render-owner-tail-review-wave-15.py",
        "run_109r_page_tail_review_sha256": "evidence/source/raw-run-109r-independent-outcome-neutral-page-render-owner-tail-review-wave-15.json",
        "run_110_page_tail_overlay_generator_sha256": "generators/integrate-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.py",
        "run_110_page_tail_overlay_sha256": "evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json",
        "run_110r_page_tail_overlay_review_materializer_sha256": "generators/materialize-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-review-wave-15.py",
        "run_110r_page_tail_overlay_review_sha256": "evidence/source/raw-run-110r-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json",
    }
    findings["pins"].update({key: PINNED_INPUTS[value] for key, value in pin_names.items()})
    counts = overlay["combined_counts"]
    findings["counts"].update(
        {
            "static_source_feature_ownership_records": 614,
            "static_source_feature_ownership_route_records": 265,
            "static_source_feature_ownership_page_records": 349,
            "static_source_feature_ownership_distinct_feature_ids": 256,
            "static_source_feature_ownership_distinct_H_feature_ids": 234,
            "static_source_feature_ownership_distinct_D_feature_ids": 22,
            "static_source_feature_ownership_route_distinct_feature_ids": 59,
            "static_source_feature_ownership_page_distinct_feature_ids": 242,
            "static_source_feature_ownership_route_page_feature_overlap": 45,
            "static_controller_action_bridges": 53,
            "bounded_static_source_ownership_percent": "15.627386",
            "bounded_static_source_residual_records": 3315,
            "direct_exact_queue_reviewed": 60,
            "direct_exact_queue_shared": 3,
            "direct_exact_queue_pending_unreviewed": 447,
        }
    )
    current = findings.get("current_static_source_feature_ownership", {})
    if current.get("run_id", "").startswith("RUN-106-"):
        findings["historical_run_106_outcome_neutral_page_render_owner_ownership"] = findings.pop(
            "current_static_source_feature_ownership"
        )
    findings["current_static_source_feature_ownership"] = {
        "run_id": overlay["run_id"],
        "review_run_id": review["run_id"],
        "status": "GO_REVIEWED_BOUNDED_OUTCOME_NEUTRAL_PAGE_TAIL_OWNERSHIP_ONLY",
        "baseline_records": 612,
        "reviewed_pages": 6,
        "overlay_source_records": 2,
        "owner_page_records_added": 2,
        "shared_relations_added": 4,
        "evidence_gaps": 0,
        "route_owner_records_added": 0,
        "controller_action_bridges_added": 0,
        "reviewed_queue_shared_records": 1,
        **counts,
        "queue_accounting": overlay["queue_accounting"],
        "independent_review_discrepancies": 0,
        "gate_4": {"status": "PARTIAL_BOUNDED_STATIC_SOURCE_OWNERSHIP_INCOMPLETE", "complete": False},
        "credit_boundary": overlay["credit_boundary"],
    }
    prior_review_key = "current_outcome_neutral_page_render_owner_ownership_review"
    if prior_review_key in findings:
        findings["historical_run_106_outcome_neutral_page_render_owner_ownership_review"] = findings.pop(prior_review_key)
    findings["current_outcome_neutral_page_render_owner_tail_ownership_review"] = {
        "run_id": review["run_id"],
        "status": review["status"],
        "reviewers": len(review["independent_reviewers"]),
        "page_owner_records_authorized_for_reporting": 2,
        "shared_page_records_verified": 4,
        "evidence_gap_page_records_verified": 0,
        "reviewed_queue_shared_records_verified": 1,
        "route_owner_records_authorized_for_reporting": 0,
        "controller_action_bridges_authorized_for_reporting": 0,
        "mechanical_discrepancies": 0,
        "semantic_boundary_discrepancies": 0,
        "queue_accounting_discrepancies": 0,
        "gate_4_complete": False,
        "completion_credit": False,
    }
    run108 = read_json("evidence/browser/current-audit-dashboard-verification-run-108-wave-14.json")
    findings["current_audit_artifact_verification_history"]["run_108"] = {
        "status": "GO_EXACT_SUPERSEDED_RUN_107_DASHBOARD_ARTIFACT_ZERO_APPLICATION_CREDIT",
        "dashboard_sha256": run108["pins"]["dashboard_html_sha256"],
        "receipt_sha256": PINNED_INPUTS["evidence/browser/current-audit-dashboard-verification-run-108-wave-14.json"],
        "viewports_verified": run108["verification"]["viewports_verified"],
        "unique_local_links_verified": run108["verification"]["unique_local_links"],
        "anchors_verified": run108["verification"]["anchors"],
        "duplicate_authored_ids": run108["verification"]["duplicate_authored_ids"],
        "console_warnings": run108["verification"]["console_warnings"],
        "console_errors": run108["verification"]["console_errors"],
        "current_dashboard_credit": False,
        "application_browser_credit": False,
    }
    queue = findings["current_direct_exact_route_page_review_queue"]
    assert queue["records"] == 507
    assert queue["reviewed_queue_surfaces"] in {59, 60}
    assert queue["pending_unreviewed"] in {448, 447}
    if queue["reviewed_queue_surfaces"] == 60:
        assert queue["shared_queue_surfaces"] == 3
        assert queue["pending_unreviewed"] == 447
    queue.update(
        {
            "reviewed_queue_surfaces": 60,
            "owned_queue_surfaces": 54,
            "shared_queue_surfaces": 3,
            "alias_queue_surfaces": 3,
            "dead_or_noncanonical_queue_surfaces": 0,
            "evidence_gap_queue_surfaces": 0,
            "pending_unreviewed": 447,
            "without_ownership": 453,
            "wholesale_ownership_authorized": False,
        }
    )
    assert canonical_json_sha256(findings["records"]) == RECORDS_SHA256
    assert findings["counts"]["final_P0"] == 0
    assert findings["counts"]["final_P1"] == 0
    assert findings["counts"]["benchmark_mapped"] == 0
    assert findings["counts"]["final_no_match"] == 0
    write_lf(relative, json.dumps(findings, ensure_ascii=False, indent=2) + "\n")


def patch_dashboard_template() -> None:
    relative = "generators/build-current-audit-dashboard.py"
    text = path(relative).read_text(encoding="utf-8")
    declarations = {
        'page_render_owner_cohort = read_json("evidence/source/root-run-105-outcome-neutral-page-render-owner-cohort-wave-14.json")':
            'page_render_owner_cohort = read_json("evidence/source/root-run-109-outcome-neutral-page-render-owner-tail-cohort-wave-15.json")',
        'page_render_owner_review = read_json("evidence/source/raw-run-105r-independent-outcome-neutral-page-render-owner-review-wave-14.json")':
            'page_render_owner_review = read_json("evidence/source/raw-run-109r-independent-outcome-neutral-page-render-owner-tail-review-wave-15.json")',
        'reviewed_page_owner_overlay = read_json("evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json")':
            'reviewed_page_owner_overlay = read_json("evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json")',
        'reviewed_page_owner_overlay_review = read_json("evidence/source/raw-run-106r-independent-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json")':
            'reviewed_page_owner_overlay_review = read_json("evidence/source/raw-run-110r-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json")',
    }
    for old, new in declarations.items():
        text = replace_once_or_present(text, old, new, "Wave 15 current evidence binding")

    old_last_pin = 'assert sha256_file("evidence/source/raw-run-106r-independent-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json") == "4a3252a37d03a609cdf69a4f0a56b41e120d3ba2314dede88317de9c50bfd9e4"\n'
    new_pins = old_last_pin + """assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-108-wave-14.json") == "1ec434d0a30703a50da0d3def477fdeb4f671f0e03b0a85326f238b89d428f79"
assert sha256_file("generators/build-outcome-neutral-page-render-owner-tail-cohort-wave-15.py") == "1005eaad8d3bcecf99f04b40f912e5181f28e33ef5acb044c27ba0201d0c8e0c"
assert sha256_file("evidence/source/root-run-109-outcome-neutral-page-render-owner-tail-cohort-wave-15.json") == "9019306fc317374b673d76fc6023efc11deb1f7f83be67d0df72d196cd076187"
assert sha256_file("generators/materialize-independent-outcome-neutral-page-render-owner-tail-review-wave-15.py") == "afd6646d04d53f8585eb2dbbeb706fbf5db24a0ccaa404d1d9042ff0773cf184"
assert sha256_file("evidence/source/raw-run-109r-independent-outcome-neutral-page-render-owner-tail-review-wave-15.json") == "2d0110c3b44a3e226549d2f9bc3b4fed76d7fed2e70094c04ccf7c3c0c7c94f0"
assert sha256_file("generators/integrate-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.py") == "8f57e6b888652f67edcea2671239a5403f15e9d144fc369eb2791e2bbd41f9d7"
assert sha256_file("evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json") == "9375fb374b589c5068334795fa03d80a7ba1fd35695808f99c9e2591e886efca"
assert sha256_file("generators/materialize-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-review-wave-15.py") == "534c7e8658729637cdfcb8a87d68782a0cd9da04e6b034c624fcc0d1886c9f88"
assert sha256_file("evidence/source/raw-run-110r-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json") == "e9b076e790e5346f99665f8f99ee609b4c7b7bac4767e416abc73a57f7dfd867"
"""
    text = replace_once_or_present(text, old_last_pin, new_pins, "Wave 15 dashboard pins")

    assertion_block = """assert page_render_owner_cohort["counts"]["selected_page_candidates"] == 6
assert page_render_owner_cohort["counts"]["page_ownership_credit_awarded"] == 0
assert page_render_owner_cohort["counts"]["selected_pending_direct_queue_page_records"] == 1
assert page_render_owner_review["decision"]["verdict"] == "GO_2_EXPLICIT_OWNER_PAGE_4_SHARED_RELATION"
assert page_render_owner_review["decision"]["owner_pages"] == 2
assert page_render_owner_review["decision"]["shared_relations"] == 4
assert page_render_owner_review["decision"]["evidence_gaps"] == 0
assert page_render_owner_review["decision"]["direct_queue_reviewed_shared_records_authorized"] == 1
assert reviewed_page_owner_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_page_owner_overlay_review["decision"]["mechanical_discrepancies"] == 0
assert reviewed_page_owner_overlay_review["decision"]["semantic_boundary_discrepancies"] == 0
assert reviewed_page_owner_overlay_review["decision"]["queue_accounting_discrepancies"] == 0
assert reviewed_page_owner_overlay_review["decision"]["reporting_authorized"] is True
assert reviewed_page_owner_overlay["combined_counts"] == reviewed_page_owner_overlay_review["verified_counts"]
assert reviewed_page_owner_overlay["queue_accounting"] == reviewed_page_owner_overlay_review["verified_queue_accounting"]
assert reviewed_page_owner_overlay["combined_counts"]["source_owner_records"] == 614
assert reviewed_page_owner_overlay["combined_counts"]["route_owner_records"] == 265
assert reviewed_page_owner_overlay["combined_counts"]["page_owner_records"] == 349
assert reviewed_page_owner_overlay["combined_counts"]["distinct_feature_ids"] == 256
assert reviewed_page_owner_overlay["combined_counts"]["distinct_H_feature_ids"] == 234
assert reviewed_page_owner_overlay["combined_counts"]["distinct_D_feature_ids"] == 22
assert reviewed_page_owner_overlay["combined_counts"]["route_distinct_feature_ids"] == 59
assert reviewed_page_owner_overlay["combined_counts"]["page_distinct_feature_ids"] == 242
assert reviewed_page_owner_overlay["combined_counts"]["route_page_feature_overlap"] == 45
assert reviewed_page_owner_overlay["combined_counts"]["static_controller_action_bridges"] == 53
assert reviewed_page_owner_overlay["combined_counts"]["bounded_static_source_residual_records"] == 3315
assert reviewed_page_owner_overlay["combined_counts"]["residual_unadjudicated_page_roots"] == 353
assert reviewed_page_owner_overlay["combined_counts"]["semantic_shared_page_roots"] == 9
assert reviewed_page_owner_overlay["combined_counts"]["evidence_gap_page_roots_tagged_within_residual"] == 1
assert reviewed_page_owner_overlay["queue_accounting"]["reviewed_queue_surface_rows"] == 60
assert reviewed_page_owner_overlay["queue_accounting"]["owner_queue_surface_rows"] == 54
assert reviewed_page_owner_overlay["queue_accounting"]["shared_queue_surface_rows"] == 3
assert reviewed_page_owner_overlay["queue_accounting"]["alias_queue_surface_rows"] == 3
assert reviewed_page_owner_overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 447
assert reviewed_page_owner_overlay["queue_accounting"]["queue_surfaces_without_ownership"] == 453
assert 3929 == 614 + 3315
assert 614 == 265 + 349
assert 3218 == 265 + 5 + 3 + 2945
assert 711 == 349 + 9 + 353
assert 256 == 59 + 242 - 45
assert 507 == 60 + 447
assert 60 == 54 + 3 + 3
assert 453 == 447 + 3 + 3
assert reviewed_page_owner_overlay["credit_boundary"]["STATIC_PAGE_FEATURE_OWNERSHIP_FOR_2_RECORDS"] is True
assert reviewed_page_owner_overlay["credit_boundary"]["REVIEWED_SHARED_RELATION_FOR_4_RECORDS"] is True
assert reviewed_page_owner_overlay["credit_boundary"]["DIRECT_QUEUE_REVIEWED_SHARED_FOR_1_RECORD"] is True
assert all(
    reviewed_page_owner_overlay["credit_boundary"][key] is False
    for key in (
        "static_route_feature_ownership_added", "static_controller_action_bridge_added",
        "matrix_mutation", "wholesale_507_queue_ownership",
        "complete_route_page_feature_crosswalk", "framework_route_reachability", "navigation",
        "site_authorization_correctness", "permission_correctness", "privacy_correctness",
        "direct_object_correctness", "lifecycle_correctness", "runtime", "database", "build",
        "application_browser", "executed_tests", "benchmark", "ease", "pass", "final_finding",
        "completion", "audit_complete",
    )
)
"""
    text = replace_between(
        text,
        'assert page_render_owner_cohort["counts"]["selected_page_candidates"]',
        "\n\n\ncandidates =",
        assertion_block,
        "Wave 15 dashboard assertion block",
    )

    evidence_marker = (
        '    ("RUN-107 page-owner reporting materializer", "generators/materialize-run-107-reviewed-page-render-owner-reporting-wave-14.py"),\n'
        '    ("RUN-107 page-owner reporting/hash receipt", "evidence/source/current-run-107-reviewed-page-render-owner-reporting-wave-14.json"),\n'
    )
    evidence_addition = evidence_marker + """    ("RUN-108 verified superseded dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-108-wave-14.json"),
    ("RUN-109 page render-owner tail cohort generator", "generators/build-outcome-neutral-page-render-owner-tail-cohort-wave-15.py"),
    ("RUN-109 six-page outcome-neutral tail cohort", "evidence/source/root-run-109-outcome-neutral-page-render-owner-tail-cohort-wave-15.json"),
    ("RUN-109R independent 2-owner / 4-shared review materializer", "generators/materialize-independent-outcome-neutral-page-render-owner-tail-review-wave-15.py"),
    ("RUN-109R independent page-tail semantic review", "evidence/source/raw-run-109r-independent-outcome-neutral-page-render-owner-tail-review-wave-15.json"),
    ("RUN-110 page-tail owner-only overlay generator", "generators/integrate-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.py"),
    ("RUN-110 two-page owner overlay with four shared non-owners", "evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json"),
    ("RUN-110R independent overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-review-wave-15.py"),
    ("RUN-110R independent final-byte queue and boundary review", "evidence/source/raw-run-110r-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json"),
    ("RUN-111 page-tail reporting materializer", "generators/materialize-run-111-reviewed-page-render-owner-tail-reporting-wave-15.py"),
    ("RUN-111 page-tail reporting/hash receipt", "evidence/source/current-run-111-reviewed-page-render-owner-tail-reporting-wave-15.json"),
"""
    text = replace_once_or_present(text, evidence_marker, evidence_addition, "Wave 15 dashboard evidence links")

    text = text.replace("RUN-071–107", "RUN-071–111").replace("RUN-077–107", "RUN-077–111")
    replacements = [
        ('<a href="#checkpoint">RUN-107</a>', '<a href="#checkpoint">RUN-111</a>', "nav"),
        ('RUN-001 through RUN-107 are represented by audit artifacts;', 'RUN-001 through RUN-111 are represented by audit artifacts;', "wave range"),
        ('Generated deterministically from independently reviewed static evidence through RUN-106/R and reported in RUN-107.', 'Generated deterministically from independently reviewed static evidence through RUN-110/R and reported in RUN-111.', "footer"),
        ('RUN-101/R–104 remain the historical 21-route-owner/3-alias checkpoint and verified dashboard. RUN-105/R review $page_wave_reviewed page roots as $page_review_owner owners, $page_review_shared shared relations, and $page_review_gap evidence gap; RUN-106/R independently establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges, with 20 new page owners, zero route/bridge additions, three preserved shared pages, and one tagged residual gap.', 'RUN-101/R–108 remain historical route/action and page-owner checkpoints with exact dashboard receipts. RUN-109/R review $page_wave_reviewed page roots as $page_review_owner owners and $page_review_shared shared relations with zero new gap; RUN-110/R independently establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges, with two new page owners, zero route/bridge additions, four preserved shared pages, and one queue row reconciled as reviewed shared.', "primary notice"),
        ('RUN-101/R–104 remain the historical route-owner checkpoint and exact dashboard receipt. RUN-105/R–106/R add 20 reviewed page owners, preserve three shared pages and one evidence gap, and add zero route owners or action bridges, raising bounded ownership to $static_owner_records records while $static_action_bridges action bridges remain unchanged; RUN-090 queue accounting also remains $queue_pending pending and $queue_without_owner without ownership. RUN-107 reports only that bounded delta.', 'RUN-101/R–108 remain historical route/action and page-owner checkpoints with exact dashboard receipts. RUN-109/R–110/R add two reviewed page owners, preserve four shared pages, and add zero route owners or action bridges, raising bounded ownership to $static_owner_records records while $static_action_bridges action bridges remain unchanged; one queue page becomes reviewed shared, leaving $queue_pending pending and $queue_without_owner without ownership. RUN-111 reports only that bounded delta.', "checkpoint notice"),
        ('RUN-097/R–104 preserve the historical route/action checkpoints and dashboard verification, RUN-105/R–106/R independently review and integrate 20 page owners while retaining three shared pages and one evidence gap, and RUN-107 refreshes current reporting.', 'RUN-097/R–108 preserve the historical route/action and page-owner checkpoints with dashboard verification, RUN-109/R–110/R independently review and integrate two page owners while retaining four shared pages and reconciling one queue page as shared, and RUN-111 refreshes current reporting.', "checkpoint narrative"),
        ('<li>RUN-105/R: 24 page candidates · 20 owners · 3 shared · 1 evidence gap · 0 route/bridge credit</li><li>RUN-106/R: 20 page rows integrated and independently verified · four non-owners preserved · $static_owner_records cumulative owner records</li><li>RUN-107: deterministic page-owner reporting refresh · matrix, queue, and every Site/permission/privacy/direct-object/lifecycle/execution/benchmark/Pass/finding/completion boundary unchanged</li>', '<li>RUN-105/R–108: historical 24-page review · 20 owners · 3 shared · 1 gap · reporting and exact superseded dashboard verification</li><li>RUN-109/R: 6 page-tail candidates · 2 owners · 4 shared · 0 new gap · 0 route/bridge credit</li><li>RUN-110/R: 2 page rows integrated and independently verified · four shared non-owners preserved · one queue page reviewed shared · $static_owner_records cumulative owner records</li><li>RUN-111: deterministic page-tail reporting refresh · matrix and every Site/permission/privacy/direct-object/lifecycle/execution/benchmark/Pass/finding/completion boundary unchanged</li>', "evidence wave list"),
        ('RUN-097/R–104 preserve the historical route/action checkpoints, RUN-105/R–106/R add 20 independently reviewed page owners and preserve 3 shared pages plus one evidence gap, and RUN-107 refreshes reporting.', 'RUN-097/R–108 preserve the historical route/action and page-owner checkpoints, RUN-109/R–110/R add two independently reviewed page owners, preserve four shared pages, and reconcile one queue page as reviewed shared, and RUN-111 refreshes reporting.', "static census intro"),
        ('<tr><td>RUN-105/R → 106/R page render-owner overlay</td><td><strong>$page_wave_reviewed reviewed · $page_review_owner owner pages · $page_review_shared shared · $page_review_gap evidence gap · 20 page rows · 0 route/bridge rows</strong></td><td class="partial">$static_owner_records cumulative owners · $static_owner_routes routes + $static_owner_pages pages · $static_owner_features FEATURE-IDs · Gate 4 incomplete</td></tr><tr><td>RUN-107 reporting refresh</td><td><strong>page-owner overlay reported</strong></td><td class="partial">audit-only materialization · matrix and queue byte-identical · fresh RUN-108 verification required</td></tr>', '<tr><td>RUN-105/R → 106/R historical page render-owner overlay</td><td><strong>24 reviewed · 20 owner pages · 3 shared · 1 evidence gap · 20 page rows · 0 route/bridge rows</strong></td><td class="partial">612 cumulative owners · 265 routes + 347 pages · 256 FEATURE-IDs · historical bounded checkpoint</td></tr><tr><td>RUN-107 / RUN-108 historical reporting and dashboard</td><td><strong>page-owner overlay reported and exact dashboard verified</strong></td><td class="partial">audit-only history · no verification metrics transfer</td></tr><tr><td>RUN-109/R → 110/R current page render-owner tail overlay</td><td><strong>$page_wave_reviewed reviewed · $page_review_owner owner pages · $page_review_shared shared · $page_review_gap evidence gap · 2 page rows · 0 route/bridge rows · 1 queue-shared row</strong></td><td class="partial">$static_owner_records cumulative owners · $static_owner_routes routes + $static_owner_pages pages · $static_owner_features FEATURE-IDs · Gate 4 incomplete</td></tr><tr><td>RUN-111 reporting refresh</td><td><strong>page-owner tail overlay reported</strong></td><td class="partial">audit-only materialization · matrix byte-identical · fresh RUN-112 verification required</td></tr>', "checkpoint Wave 15 rows"),
        ('<tr><td>RUN-106/R current outcome-neutral page ownership</td><td>$static_owner_records records · $static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs · $static_action_bridges action bridges</td><td class="partial">$ownership_percent% of bounded 3,929 records · $static_residual residual · $page_review_owner owner / $page_review_shared shared / $page_review_gap gap page delta · pages $static_owner_pages owner + $page_shared shared + $page_residual residual · Gate 4 incomplete · matrix unchanged</td></tr>', '<tr><td>RUN-106/R historical outcome-neutral page ownership</td><td>612 records · 265 route + 347 page · 256 FEATURE-IDs · 53 action bridges</td><td class="partial">15.576483% · 3,317 residual · historical bounded checkpoint</td></tr><tr><td>RUN-110/R current outcome-neutral page-tail ownership</td><td>$static_owner_records records · $static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs · $static_action_bridges action bridges</td><td class="partial">$ownership_percent% of bounded 3,929 records · $static_residual residual · $page_review_owner owner / $page_review_shared shared / $page_review_gap gap page-tail delta · pages $static_owner_pages owner + $page_shared shared + $page_residual residual · one queue page reviewed shared · Gate 4 incomplete · matrix unchanged</td></tr>', "static census current overlay row"),
        ('RUN-106/R establish $static_owner_records bounded source-owner records and retain $static_action_bridges action bridges while adding 20 page owners, preserving 3 newly reviewed shared pages, and tagging one evidence gap inside the page residual;', 'RUN-110/R establish $static_owner_records bounded source-owner records and retain $static_action_bridges action bridges while adding two page owners, preserving four newly reviewed shared pages, and retaining one earlier evidence gap inside the page residual;', "Gate 4 current overlay"),
        ('RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, RUN-094, RUN-100, and RUN-104 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-107.', 'RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, RUN-094, RUN-100, RUN-104, and RUN-108 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-111.', "prior dashboard verification paragraph"),
        ('<li><a href="evidence/browser/current-audit-dashboard-verification-run-104-wave-13.json">Superseded RUN-104 verification GO</a></li>', '<li><a href="evidence/browser/current-audit-dashboard-verification-run-104-wave-13.json">Superseded RUN-104 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-108-wave-14.json">Superseded RUN-108 verification GO</a></li>', "RUN-108 prior verification link"),
        ('<section class="panel"><h2>Fresh RUN-108 audit-dashboard verification</h2><p>The exact regenerated RUN-107 dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-108 receipt records page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 612/265/347 ownership, 20-owner/3-shared/1-gap page outcomes, 347/5/359 page conservation, unchanged queue/53-bridge counts, and all zero-credit boundaries. It verifies the audit artifact only and grants no application-browser, responsive, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-108-wave-14.json">RUN-108 responsive audit-dashboard verification receipt</a></li></ul></section>', '<section class="panel"><h2>Fresh RUN-112 audit-dashboard verification</h2><p>The exact regenerated RUN-111 dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-112 receipt records page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 614/265/349 ownership, 2-owner/4-shared page-tail outcomes, 349/9/353 page conservation, 60-reviewed/447-pending queue accounting, 53 bridges, and all zero-credit boundaries. It verifies the audit artifact only and grants no application-browser, responsive, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-112-wave-15.json">RUN-112 responsive audit-dashboard verification receipt</a></li></ul></section>', "fresh dashboard verification"),
    ]
    for old, new, label in replacements:
        text = replace_once_or_present(text, old, new, label)
    write_lf(relative, text)


def main() -> None:
    overlay, review = assert_inputs()
    patch_reports()
    patch_findings(overlay, review)
    patch_dashboard_template()
    for relative, expected in PRESERVED.items():
        assert sha256_file(relative) == expected, relative
    assert git("status", "--porcelain", "--", "app", "routes", "resources/js") == ""
    outputs = {
        relative: sha256_file(relative)
        for relative in (
            "00-executive-summary.md",
            "01-repository-module-map.md",
            "13-unresolved-questions-and-evidence-gaps.md",
            "findings.json",
            "generators/build-current-audit-dashboard.py",
        )
    }
    receipt = {
        "schema_version": "run-111-reviewed-page-render-owner-tail-reporting-wave-15-v1",
        "run_id": "RUN-111-REVIEWED-PAGE-RENDER-OWNER-TAIL-REPORTING-WAVE-15",
        "status": "REVIEWED_PAGE_OWNER_TAIL_OVERLAY_REPORTED_GATE_4_INCOMPLETE",
        "generated_on": "2026-08-26",
        "pins": {
            "checkpoint_commit": CHECKPOINT_COMMIT,
            "checkpoint_tree": CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "app_tree": APP_TREE,
            "routes_tree": ROUTES_TREE,
            "resources_js_tree": RESOURCES_JS_TREE,
            "resources_js_pages_tree": RESOURCES_JS_PAGES_TREE,
            "matrix_sha256": MATRIX_SHA256,
            "materializer_sha256": sha256_file("generators/materialize-run-111-reviewed-page-render-owner-tail-reporting-wave-15.py"),
            "overlay_sha256": PINNED_INPUTS["evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json"],
            "independent_overlay_review_sha256": PINNED_INPUTS["evidence/source/raw-run-110r-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json"],
            "superseded_dashboard_verification_sha256": PINNED_INPUTS["evidence/browser/current-audit-dashboard-verification-run-108-wave-14.json"],
        },
        "inputs": {**CURRENT_REPORT_INPUTS, **PINNED_INPUTS},
        "outputs": outputs,
        "preserved": PRESERVED,
        "counts": {
            **overlay["combined_counts"],
            **overlay["queue_accounting"],
            "reviewed_pages": 6,
            "reviewed_owner_pages_added": 2,
            "reviewed_shared_pages": 4,
            "reviewed_evidence_gap_pages": 0,
            "reviewed_queue_shared_pages": 1,
            "route_owner_records_added": 0,
            "controller_action_bridges_added": 0,
            "page_owner_records_added": 2,
            "provisional_finding_records": 12,
            "final_findings": 0,
            "matrix_rows_changed": 0,
            "matrix_cells_changed": 0,
        },
        "checks": {
            "run_109r_review_go": True,
            "run_109r_owner_pages": 2,
            "run_109r_shared_relations": 4,
            "run_109r_evidence_gaps": 0,
            "run_110r_overlay_review_go": True,
            "independent_review_discrepancies": 0,
            "route_owner_records_added": 0,
            "controller_action_bridges_added": 0,
            "page_owner_records_added": 2,
            "reviewed_non_owner_records_preserved": 4,
            "run_090_queue_reviewed_shared_delta": 1,
            "matrix_byte_identical": True,
            "provisional_finding_record_semantics_preserved": True,
            "application_source_paths_written": 0,
            "single_tenant_multi_site_architecture_preserved": True,
            "dashboard_requires_fresh_run_112_artifact_verification": True,
            "gate_4_complete": False,
        },
        "credit_boundary": overlay["credit_boundary"],
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
    }
    encoded = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    output = path(OUTPUT_RELATIVE)
    if output.exists():
        prior = read_json(OUTPUT_RELATIVE)
        assert prior["run_id"] == receipt["run_id"]
        assert prior["schema_version"] == receipt["schema_version"]
    if not output.exists() or output.read_bytes() != encoded:
        output.write_bytes(encoded)
    print(json.dumps({
        "status": receipt["status"],
        "output": output.relative_to(REPO).as_posix(),
        "sha256": sha256_file(OUTPUT_RELATIVE),
        "source_owner_records": receipt["counts"]["source_owner_records"],
        "route_owner_records": receipt["counts"]["route_owner_records"],
        "page_owner_records": receipt["counts"]["page_owner_records"],
        "shared_pages": receipt["counts"]["semantic_shared_page_roots"],
        "reviewed_queue_rows": receipt["counts"]["reviewed_queue_surface_rows"],
        "gate_4_complete": receipt["checks"]["gate_4_complete"],
    }, indent=2))


if __name__ == "__main__":
    main()
