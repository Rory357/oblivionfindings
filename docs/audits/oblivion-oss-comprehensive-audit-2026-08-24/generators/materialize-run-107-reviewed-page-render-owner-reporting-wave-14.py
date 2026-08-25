#!/usr/bin/env python3
"""Materialize RUN-107 reporting for the independently reviewed RUN-106 overlay."""

from __future__ import annotations

import hashlib
import json
import runpy
import subprocess
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_RELATIVE = "evidence/source/current-run-107-reviewed-page-render-owner-reporting-wave-14.json"
CHECKPOINT_COMMIT = "7d488c2c84659f1b3896cbf73333d33b65d96b03"
CHECKPOINT_TREE = "a0c4dd95029692fea1a366130d6e2e1f1f17a3cc"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
RECORDS_SHA256 = "118c1a1f19b2e300c77d5b4d71c60f75b7038382f3c71e18f078367f8473e260"

PRIOR = runpy.run_path(str(AUDIT_DIR / "generators/materialize-run-103-reviewed-outcome-neutral-route-action-reporting-wave-13.py"))
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
    "00-executive-summary.md": "b2a67c92bc21086c1bb6baaac5ba66e20e36b71311da88e11ef0030bc2b88919",
    "01-repository-module-map.md": "38bc4277d91cc9f0e2465f6fa16405050e6d8e2da44b4c43226828af3f00c4b7",
    "13-unresolved-questions-and-evidence-gaps.md": "50cb44b2533d68e6947c23b53ed52fd05278852dbf6c6ce20bc4d32c76431d3b",
    "findings.json": "6e76360704a549d98caf07a246a9cceb2f1387aaae831fb6033f37dbc00c7dfc",
    "generators/build-current-audit-dashboard.py": "498c7f12fb8ae188bffd4fe3094a23e3e421fb481411cd6a52360892e28949d3",
}

PINNED_INPUTS = {
    "evidence/source/current-run-103-reviewed-outcome-neutral-route-action-reporting-wave-13.json": "e8e0d5755dc1a3ed88f1c34a5e3d65b881f2d5a551cc40d6aa3bb605652d9bc5",
    "evidence/browser/current-audit-dashboard-verification-run-104-wave-13.json": "3caf6c0970c4ea5c276b51b558d5d736c45576c503049625968e35325148009e",
    "generators/build-outcome-neutral-page-render-owner-cohort-wave-14.py": "564c37de4525a4587c99d455fa08c6a4a4557441551c6ac5628bd8ae7ca1d31a",
    "evidence/source/root-run-105-outcome-neutral-page-render-owner-cohort-wave-14.json": "4d6868c06a4c94c708e0934682e0c9724b71fc104c3751d02d0acfd3a95370bc",
    "generators/materialize-independent-outcome-neutral-page-render-owner-review-wave-14.py": "b1acf84553f91fd6ce71d200126f34ee2c31a622c488d85d490ffd0a536da360",
    "evidence/source/raw-run-105r-independent-outcome-neutral-page-render-owner-review-wave-14.json": "764a0d086b206112d7c6b93f3d1fa733d3c3ca865a5f4ba3887d082deed1f907",
    "generators/integrate-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.py": "5ec50d2740496c793997a6a5e5434bf3623fb11dbbac9d46a147551e762b2a54",
    "evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json": "9c1600d2365c3527e185f313b7cb1586705f39cefb15d5d212dc708b45b630ea",
    "generators/materialize-independent-reviewed-outcome-neutral-page-render-owner-ownership-overlay-review-wave-14.py": "d6cf49d445fabafc615bc3a3cb836da537a2f5939962bb49deda213d2de4db74",
    "evidence/source/raw-run-106r-independent-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json": "4a3252a37d03a609cdf69a4f0a56b41e120d3ba2314dede88317de9c50bfd9e4",
}


def git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args], cwd=REPO, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True
    )
    return result.stdout.strip()


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
            actual = sha256_file(relative)
            if relative == "generators/build-current-audit-dashboard.py" and actual != expected:
                # A first dashboard compile exposed a repeated keyword: the
                # historical RUN-079 page review count and the RUN-105 cohort
                # count had both been named page_reviewed. Accept only the
                # bounded rename already represented by this materializer.
                dashboard_source = path(relative).read_text(encoding="utf-8")
                assert dashboard_source.count("page_reviewed=") == 1, relative
                assert dashboard_source.count("page_wave_reviewed=") == 1, relative
                assert "$page_wave_reviewed" in dashboard_source, relative
            else:
                assert actual == expected, relative
    else:
        for relative, expected in CURRENT_REPORT_INPUTS.items():
            assert sha256_file(relative) == expected, relative

    overlay = read_json("evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json")
    review = read_json("evidence/source/raw-run-106r-independent-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json")
    assert review["decision"]["verdict"] == "GO"
    assert review["decision"]["mechanical_discrepancies"] == 0
    assert review["decision"]["semantic_boundary_discrepancies"] == 0
    assert review["decision"]["reporting_authorized"] is True
    assert review["decision"]["gate_4_complete"] is False
    assert overlay["combined_counts"] == review["verified_counts"]
    assert overlay["queue_accounting"] == review["verified_queue_accounting"]
    counts = overlay["combined_counts"]
    assert (counts["source_owner_records"], counts["route_owner_records"], counts["page_owner_records"]) == (612, 265, 347)
    assert (counts["distinct_feature_ids"], counts["distinct_H_feature_ids"], counts["distinct_D_feature_ids"]) == (256, 234, 22)
    assert (counts["route_distinct_feature_ids"], counts["page_distinct_feature_ids"], counts["route_page_feature_overlap"]) == (59, 242, 45)
    assert counts["bounded_static_source_residual_records"] == 3317
    assert counts["static_controller_action_bridges"] == 53
    assert (counts["semantic_shared_page_roots"], counts["residual_unadjudicated_page_roots"], counts["evidence_gap_page_roots_tagged_within_residual"]) == (5, 359, 1)
    assert overlay["reviewed_overlay"]["owner_pages"] == 20
    assert overlay["reviewed_overlay"]["shared_relations"] == 3
    assert overlay["reviewed_overlay"]["evidence_gaps"] == 1
    assert overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 448
    assert overlay["queue_accounting"]["queue_surfaces_without_ownership"] == 453
    assert overlay["denominator_boundary"]["gate_4_complete"] is False
    assert overlay["audit_completion_test_met"] is False
    return overlay, review


def patch_reports() -> None:
    summary_relative = "00-executive-summary.md"
    summary = path(summary_relative).read_text(encoding="utf-8")
    summary = replace_once_or_present(
        summary,
        "## RUN-101–103 reviewed outcome-neutral route/action overlay",
        "## Historical RUN-101–103 reviewed outcome-neutral route/action overlay",
        "historical Wave 13 summary heading",
    )
    summary = replace_once_or_present(
        summary,
        "The current bounded checkpoint is **592 records = 265 routes + 327 pages across 249 canonical FEATURE-IDs (229 H + 20 D)**, with 53 controller-action bridges.",
        "That bounded checkpoint was **592 records = 265 routes + 327 pages across 249 canonical FEATURE-IDs (229 H + 20 D)**, with 53 controller-action bridges.",
        "historical Wave 13 summary count",
    )
    section = """
## RUN-105–107 reviewed outcome-neutral page render-owner overlay

RUN-105 freezes 24 page render-owner candidates without pre-awarding ownership. Fresh partition review classifies 20 as `OWNER_PAGE`, three as `SHARED_RELATION`, and one as `EVIDENCE_GAP`. The shared pages are the Health & Safety event detail shell, roster-conflict workspace, and dual requester/agent ticket page. The Governance Action index remains an evidence gap because its material generated `showAction` import is absent at the pinned application commit and build resolution was not executed.

RUN-106 integrates only the 20 explicit owners as page-source records. It adds zero route owners and zero controller-action bridges, preserves the three shared pages and one gap as reviewed non-owners, and leaves the 507-row RUN-090 queue unchanged because none of these page candidates belongs to it. RUN-106R independently reproduces the exact final bytes, row and source identities, set unions, collisions, conservation equations, and zero-credit boundaries with zero discrepancies.

The current bounded checkpoint is **612 records = 265 routes + 347 pages across 256 canonical FEATURE-IDs (234 H + 22 D)**, with 53 controller-action bridges. Route and page owners span 59 and 242 FEATURE-IDs with 45 in their overlap. This is 15.576483% of the bounded 3,929-record source universe; 3,317 records remain. The page universe is **711 = 347 owners + 5 shared + 359 residual**, with the one evidence gap tagged inside that 359 rather than counted again. The route universe and queue remain unchanged.

RUN-107 reports only that bounded page delta. Oblivion Findings remains one operating organisation across multiple Sites. Site access, roles/permissions, canonical ownership, direct-object denial, privacy, lifecycle, framework reachability, runtime, database, build, application browser, tests, benchmarks, ease, Passes, findings, completion, and Gate 4 remain separate open or zero-credit gates. The 340-row matrix remains byte-identical and mapping remains 0/340.
"""
    summary = upsert_section_before(
        summary,
        "## RUN-105–107 reviewed outcome-neutral page render-owner overlay",
        "\n## Current raw source census\n",
        section,
        "Wave 14 summary section",
    )
    marker = (
        "- `generators/materialize-run-103-reviewed-outcome-neutral-route-action-reporting-wave-13.py` and "
        "`evidence/source/current-run-103-reviewed-outcome-neutral-route-action-reporting-wave-13.json`: "
        "deterministic RUN-103 reporting refresh preserving the matrix, reports 02–12/inventory, and every downstream zero-credit boundary.\n"
    )
    addition = marker + (
        "- `evidence/browser/current-audit-dashboard-verification-run-104-wave-13.json`: exact superseded RUN-103 dashboard artifact verification; no metrics transfer to the current dashboard.\n"
        "- `generators/build-outcome-neutral-page-render-owner-cohort-wave-14.py` and `evidence/source/root-run-105-outcome-neutral-page-render-owner-cohort-wave-14.json`: deterministic 24-page outcome-neutral cohort with zero pre-review credit.\n"
        "- `generators/materialize-independent-outcome-neutral-page-render-owner-review-wave-14.py` and `evidence/source/raw-run-105r-independent-outcome-neutral-page-render-owner-review-wave-14.json`: fresh 20-owner / 3-shared / 1-gap page review with zero current ownership credit.\n"
        "- `generators/integrate-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.py` and `evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json`: exact 20-page owner-only delta with four preserved non-owners.\n"
        "- `generators/materialize-independent-reviewed-outcome-neutral-page-render-owner-ownership-overlay-review-wave-14.py` and `evidence/source/raw-run-106r-independent-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json`: independent final-byte, mechanical, and semantic-boundary GO receipt.\n"
        "- `generators/materialize-run-107-reviewed-page-render-owner-reporting-wave-14.py` and `evidence/source/current-run-107-reviewed-page-render-owner-reporting-wave-14.json`: deterministic RUN-107 reporting refresh preserving the matrix, reports 02–12/inventory, queue, and every downstream zero-credit boundary.\n"
    )
    summary = replace_once_or_present(summary, marker, addition, "Wave 14 evidence links")
    write_lf(summary_relative, summary)

    map_relative = "01-repository-module-map.md"
    module_map = path(map_relative).read_text(encoding="utf-8")
    module_map = replace_once_or_present(
        module_map,
        "## RUN-101–102 reviewed outcome-neutral route/action overlay",
        "## Historical RUN-101–102 reviewed outcome-neutral route/action overlay",
        "historical Wave 13 map heading",
    )
    module_map = replace_once_or_present(
        module_map,
        "The cumulative bounded ledger is 592 source owners (265 route + 327 page) across 249 FEATURE-IDs (229 H + 20 D).",
        "That cumulative bounded ledger was 592 source owners (265 route + 327 page) across 249 FEATURE-IDs (229 H + 20 D).",
        "historical Wave 13 map count",
    )
    map_section = """
## RUN-105–106 reviewed page render-owner overlay

RUN-105 freezes 24 pending page roots across 17 candidate FEATURE-IDs for fresh outcome-neutral review. Twenty are explicit page owners, three are shared relations, and the Governance Action index remains an evidence gap because a material generated import is absent at the application pin. Containment, imports, presence, and names alone do not grant ownership.

RUN-106 adds exactly 20 page owners, zero route owners, and zero controller-action bridges. The cumulative bounded ledger is 612 source owners (265 route + 347 page) across 256 FEATURE-IDs (234 H + 22 D). Route/page feature sets are 59/242 with overlap 45, while the action-bridge count remains 53. Page accounting is 711 = 347 owners + 5 shared + 359 residual, with one evidence gap tagged inside the residual. RUN-090 queue accounting remains 507 total, 59 reviewed, 54 owned, two shared, three aliases, 448 pending, and 453 without ownership.

RUN-106R verifies exact final bytes and all boundaries with zero discrepancies. These relations establish bounded static page ownership and explicit non-owner classification only; they do not establish framework reachability, Site or permission correctness, canonical direct-object concealment, privacy or lifecycle behaviour, runtime, build, browser, tests, benchmarks, findings, Passes, or completion.
"""
    module_map = upsert_section_before(
        module_map,
        "## RUN-105–106 reviewed page render-owner overlay",
        "\n## Candidate register\n",
        map_section,
        "Wave 14 map section",
    )
    write_lf(map_relative, module_map)

    gaps_relative = "13-unresolved-questions-and-evidence-gaps.md"
    gaps = path(gaps_relative).read_text(encoding="utf-8")
    gaps = replace_line_containing(
        gaps,
        "| Required reporting paths |",
        "| Required reporting paths | 18 / 18 prompt-required files or directories present. RUN-104 independently verified the exact now-superseded RUN-103 dashboard at four viewports; the regenerated RUN-107 dashboard requires a separate fresh RUN-108 artifact receipt. | Presence and prior audit-artifact verification make the reporting contract inspectable but grant no application execution, final-finding, Pass, or completion credit. Gate 26 remains open. | Complete the semantic, execution, benchmark, Pass 8, final reconciliation, and no-live-agent gates; repeat audit-dashboard verification after every later HTML change. |",
        "required paths row",
    )
    gaps = replace_line_containing(
        gaps,
        "| Runtime routes |",
        "| Runtime routes | RUN-106/R retain 265 bounded route-owner records and 53 static controller-action bridges; 2,945 residual explicit-unmapped route rows, five semantic-shared route rows, and three reviewed alias rows remain distinguished within the bounded 3,218-row static route-like universe. | RUN-105–106 add zero route or bridge records. Static owner/action linkage is not a framework-expanded route table or reachability proof. | Under a fresh bounded runtime grant, use an exact disposable database, execute a separately pinned framework/provider route lane, and reconcile it to all 38 source route files and 3,218 route-like rows. |",
        "runtime routes row",
    )
    gaps = replace_line_containing(
        gaps,
        "| Inertia pages |",
        "| Inertia pages | RUN-084/R enumerate 1,058 physical page-tree files. RUN-106/R establish 347 bounded page owners, preserve five semantic-shared roots, and leave 359 residual roots including one tagged evidence gap. | Twenty reviewed page roots gain bounded ownership; three shared roots and the unresolved generated-import gap do not. Full-tree structural GO and bounded ownership are not a complete canonical crosswalk, runtime reachability, build resolution, rendered browser behaviour, or final feature mapping. | Reconcile all 711 literal roots and support relations to safely expanded framework routes and frozen FEATURE-IDs, retain shared and gap relations explicitly, prove build resolution, and observe every safely reachable current-build page under approved roles/Sites. |",
        "pages row",
    )
    gaps = replace_line_containing(
        gaps,
        "| Canonical features |",
        "| Canonical features | Static canonical identity remains 340 targets: 300 H, 40 D, zero M; RUN-106/R establish 612 bounded source-owner records (265 routes + 347 pages) across 256 FEATURE-IDs (234 H + 22 D) plus 53 controller-action bridges while the matrix remains byte-identical at `dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390`. | This is 15.576483% of the bounded 3,929-record source universe. Gate 4 remains incomplete: 3,317 non-owner records, the framework-expanded denominator, shared, alias, and gap relations, reachability, and the full crosswalk remain open; matrix target mapping stays 0/340. | Finish canonical denominator and residual ownership adjudication without inheriting FEATURE-IDs from prefixes, imports, containment, names, or presence, and without awarding runtime, browser, test, benchmark, ease, release, or completion credit. |",
        "canonical row",
    )
    gaps = replace_line_containing(
        gaps,
        "| Agent universe and writer rule |",
        "| Agent universe and writer rule | RUN-001 through RUN-107 represented at the current reporting checkpoint; finalization gate false. | RUN-105/R review 24 pages as 20 owners, three shared, and one evidence gap; RUN-106/R independently integrate and verify 20 page owners with zero route/bridge additions; RUN-107 reports only those bounded classes. Runtime, browser, tests, benchmarks, Pass 8, finalization, and completion remain open. | Complete residual ownership and every semantic/execution gate, then dispatch fresh Pass 8/final cross-reviewers, verify the final dashboard, and prove no agent remains live at finalization. |",
        "agent row",
    )
    gaps = replace_once_or_present(
        gaps,
        "## RUN-077–103 route/page, page-tree, backend, ownership and reporting lineage",
        "## RUN-077–107 route/page, page-tree, backend, ownership and reporting lineage",
        "lineage heading",
    )
    lineage = (
        "RUN-077 freezes the exhaustive committed-source route/name/page universe through RUN-084B's page-tree and backend structural ledgers. RUN-086/R establish the initial 530 bounded source owners; RUN-090 freezes the zero-credit direct-exact queue; RUN-091/R–103 successively add and report reviewed closed-chain and route/action ownership while preserving shared and alias outcomes, reaching 592 owners. RUN-104 verifies that now-superseded dashboard artifact. RUN-105/R review 24 page roots as 20 owners, three shared relations, and one evidence gap. RUN-106/R integrate and independently verify exactly 20 page-source owners with zero route or bridge additions and four preserved non-owners, reaching 612 owners across 256 FEATURE-IDs; RUN-107 reports only that bounded delta. Gate 4 and the full route/page/backend crosswalk remain incomplete; Oblivion Findings remains a single-tenant application for one organisation across multiple Sites, and framework reachability, Site/permission/privacy/direct-object/lifecycle correctness, runtime, build, signed-in application browser, executed tests, benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit."
    )
    gaps = replace_line_containing(
        gaps,
        "RUN-077 freezes the exhaustive committed-source route/name/page universe.",
        lineage,
        "lineage paragraph",
    )
    write_lf(gaps_relative, gaps)


def patch_findings(overlay: dict[str, Any], review: dict[str, Any]) -> None:
    relative = "findings.json"
    findings = read_json(relative)
    assert canonical_json_sha256(findings["records"]) == RECORDS_SHA256
    pin_names = {
        "run_104_dashboard_verification_sha256": "evidence/browser/current-audit-dashboard-verification-run-104-wave-13.json",
        "run_105_page_cohort_generator_sha256": "generators/build-outcome-neutral-page-render-owner-cohort-wave-14.py",
        "run_105_page_cohort_sha256": "evidence/source/root-run-105-outcome-neutral-page-render-owner-cohort-wave-14.json",
        "run_105r_page_review_materializer_sha256": "generators/materialize-independent-outcome-neutral-page-render-owner-review-wave-14.py",
        "run_105r_page_review_sha256": "evidence/source/raw-run-105r-independent-outcome-neutral-page-render-owner-review-wave-14.json",
        "run_106_page_overlay_generator_sha256": "generators/integrate-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.py",
        "run_106_page_overlay_sha256": "evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json",
        "run_106r_page_overlay_review_materializer_sha256": "generators/materialize-independent-reviewed-outcome-neutral-page-render-owner-ownership-overlay-review-wave-14.py",
        "run_106r_page_overlay_review_sha256": "evidence/source/raw-run-106r-independent-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json",
    }
    findings["pins"].update({key: PINNED_INPUTS[value] for key, value in pin_names.items()})
    counts = overlay["combined_counts"]
    findings["counts"].update(
        {
            "static_source_feature_ownership_records": 612,
            "static_source_feature_ownership_route_records": 265,
            "static_source_feature_ownership_page_records": 347,
            "static_source_feature_ownership_distinct_feature_ids": 256,
            "static_source_feature_ownership_distinct_H_feature_ids": 234,
            "static_source_feature_ownership_distinct_D_feature_ids": 22,
            "static_source_feature_ownership_route_distinct_feature_ids": 59,
            "static_source_feature_ownership_page_distinct_feature_ids": 242,
            "static_source_feature_ownership_route_page_feature_overlap": 45,
            "static_controller_action_bridges": 53,
            "bounded_static_source_ownership_percent": "15.576483",
            "bounded_static_source_residual_records": 3317,
        }
    )
    if findings.get("current_static_source_feature_ownership", {}).get("run_id", "").startswith("RUN-102-"):
        findings["historical_run_102_outcome_neutral_route_action_ownership"] = findings.pop("current_static_source_feature_ownership")
    findings["current_static_source_feature_ownership"] = {
        "run_id": overlay["run_id"],
        "review_run_id": review["run_id"],
        "status": "GO_REVIEWED_BOUNDED_OUTCOME_NEUTRAL_PAGE_OWNERSHIP_ONLY",
        "baseline_records": 592,
        "reviewed_pages": 24,
        "overlay_source_records": 20,
        "owner_page_records_added": 20,
        "shared_relations_added": 3,
        "evidence_gaps": 1,
        "route_owner_records_added": 0,
        "controller_action_bridges_added": 0,
        **counts,
        "queue_accounting": overlay["queue_accounting"],
        "independent_review_discrepancies": 0,
        "gate_4": {"status": "PARTIAL_BOUNDED_STATIC_SOURCE_OWNERSHIP_INCOMPLETE", "complete": False},
        "credit_boundary": overlay["credit_boundary"],
    }
    if "current_outcome_neutral_route_action_ownership_review" in findings:
        findings["historical_run_102_outcome_neutral_route_action_ownership_review"] = findings.pop(
            "current_outcome_neutral_route_action_ownership_review"
        )
    findings["current_outcome_neutral_page_render_owner_ownership_review"] = {
        "run_id": review["run_id"],
        "status": review["status"],
        "reviewers": len(review["independent_reviewers"]),
        "page_owner_records_authorized_for_reporting": 20,
        "shared_page_records_verified": 3,
        "evidence_gap_page_records_verified": 1,
        "route_owner_records_authorized_for_reporting": 0,
        "controller_action_bridges_authorized_for_reporting": 0,
        "mechanical_discrepancies": 0,
        "semantic_boundary_discrepancies": 0,
        "gate_4_complete": False,
        "completion_credit": False,
    }
    run104 = read_json("evidence/browser/current-audit-dashboard-verification-run-104-wave-13.json")
    findings["current_audit_artifact_verification_history"]["run_104"] = {
        "status": "GO_EXACT_SUPERSEDED_RUN_103_DASHBOARD_ARTIFACT_ZERO_APPLICATION_CREDIT",
        "dashboard_sha256": run104["pins"]["dashboard_html_sha256"],
        "receipt_sha256": PINNED_INPUTS["evidence/browser/current-audit-dashboard-verification-run-104-wave-13.json"],
        "viewports_verified": run104["verification"]["viewports_verified"],
        "unique_local_links_verified": run104["verification"]["unique_local_links"],
        "anchors_verified": run104["verification"]["anchors"],
        "duplicate_authored_ids": run104["verification"]["duplicate_authored_ids"],
        "console_warnings": run104["verification"]["console_warnings"],
        "console_errors": run104["verification"]["console_errors"],
        "current_dashboard_credit": False,
        "application_browser_credit": False,
    }
    assert findings["current_direct_exact_route_page_review_queue"] == {
        "run_id": "RUN-090-DIRECT-EXACT-ROUTE-PAGE-REVIEW-QUEUE-WAVE-11",
        "status": "CANDIDATE_QUEUE_PARTIALLY_REVIEWED_NO_WHOLESALE_OWNERSHIP_CREDIT",
        "records": 507,
        "reviewed_queue_surfaces": 59,
        "owned_queue_surfaces": 54,
        "shared_queue_surfaces": 2,
        "alias_queue_surfaces": 3,
        "dead_or_noncanonical_queue_surfaces": 0,
        "evidence_gap_queue_surfaces": 0,
        "pending_unreviewed": 448,
        "without_ownership": 453,
        "wholesale_ownership_authorized": False,
    }
    assert canonical_json_sha256(findings["records"]) == RECORDS_SHA256
    assert findings["counts"]["final_P0"] == 0
    assert findings["counts"]["final_P1"] == 0
    assert findings["counts"]["benchmark_mapped"] == 0
    assert findings["counts"]["final_no_match"] == 0
    write_lf(relative, json.dumps(findings, ensure_ascii=False, indent=2) + "\n")


def patch_dashboard_template() -> None:
    relative = "generators/build-current-audit-dashboard.py"
    text = path(relative).read_text(encoding="utf-8")
    replacements = [
        ('<a href="#checkpoint">RUN-103</a>', '<a href="#checkpoint">RUN-107</a>', "nav"),
        ('<strong>RUN-071–103 current reporting checkpoint:</strong>', '<strong>RUN-071–107 current reporting checkpoint:</strong>', "notice heading"),
        ('<h2>RUN-071–103 completion-gate checkpoint</h2>', '<h2>RUN-071–107 completion-gate checkpoint</h2>', "checkpoint heading"),
        ('<h2>RUN-071–103 evidence lineage</h2>', '<h2>RUN-071–107 evidence lineage</h2>', "lineage heading"),
        ('RUN-077–103 source/reporting artifact', 'RUN-077–107 source/reporting artifact', "lineage text"),
        ('RUN-001 through RUN-103 are represented by audit artifacts;', 'RUN-001 through RUN-107 are represented by audit artifacts;', "wave range"),
        ('Generated deterministically from independently reviewed static evidence through RUN-102/R and reported in RUN-103.', 'Generated deterministically from independently reviewed static evidence through RUN-106/R and reported in RUN-107.', "footer"),
    ]
    for old, new, label in replacements:
        text = replace_once_or_present(text, old, new, label)
    text = replace_once_or_present(
        text,
        'RUN-101/R review 24 route actions as 21 owners and 3 aliases; RUN-102/R independently establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges, with 21 new route owners, 3 preserved non-owner aliases, and zero new page owners. $static_residual records remain and Gate 4 is open.',
        'RUN-101/R–104 remain the historical 21-route-owner/3-alias checkpoint and verified dashboard. RUN-105/R review $page_wave_reviewed page roots as $page_review_owner owners, $page_review_shared shared relations, and $page_review_gap evidence gap; RUN-106/R independently establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges, with 20 new page owners, zero route/bridge additions, three preserved shared pages, and one tagged residual gap. $static_residual records remain and Gate 4 is open.',
        "primary notice current overlay",
    )
    text = replace_once_or_present(
        text,
        'RUN-101/R–102/R add 21 reviewed route owners, preserve 3 aliases, and add 21 action bridges with zero page additions, raising bounded ownership to $static_owner_records records and $static_action_bridges action bridges; $queue_pending queue rows remain unreviewed and $queue_without_owner remain without ownership. RUN-103 reports only that bounded delta.',
        'RUN-101/R–104 remain the historical route-owner checkpoint and exact dashboard receipt. RUN-105/R–106/R add 20 reviewed page owners, preserve three shared pages and one evidence gap, and add zero route owners or action bridges, raising bounded ownership to $static_owner_records records while $static_action_bridges action bridges remain unchanged; RUN-090 queue accounting also remains $queue_pending pending and $queue_without_owner without ownership. RUN-107 reports only that bounded delta.',
        "checkpoint notice current overlay",
    )
    text = replace_once_or_present(
        text,
        'RUN-097/R–100 preserve the historical 23-owner checkpoint and dashboard verification, RUN-101/R–102/R independently review and integrate 21 route/action owners plus 3 aliases with zero page additions, and RUN-103 refreshes current reporting.',
        'RUN-097/R–104 preserve the historical route/action checkpoints and dashboard verification, RUN-105/R–106/R independently review and integrate 20 page owners while retaining three shared pages and one evidence gap, and RUN-107 refreshes current reporting.',
        "checkpoint narrative",
    )
    text = replace_once_or_present(
        text,
        '<tr><td>RUN-101/R → 102/R outcome-neutral overlay</td><td><strong>24 reviewed · 21 owner route/actions · 3 aliases · 21 route rows · 21 action bridges · 0 page rows</strong></td><td class="partial">$static_owner_records cumulative owners · $static_owner_routes routes + $static_owner_pages pages · $static_owner_features FEATURE-IDs · Gate 4 incomplete</td></tr><tr><td>RUN-103 reporting refresh</td><td><strong>outcome-neutral overlay reported</strong></td><td class="partial">audit-only materialization · matrix and preserved reports byte-identical · fresh RUN-104 verification required</td></tr>',
        '<tr><td>RUN-101/R → 102/R historical outcome-neutral overlay</td><td><strong>24 reviewed · 21 owner route/actions · 3 aliases · 21 route rows · 21 action bridges · 0 page rows</strong></td><td class="partial">592 cumulative owners · 265 routes + 327 pages · 249 FEATURE-IDs · historical bounded checkpoint</td></tr><tr><td>RUN-103 / RUN-104 historical reporting and dashboard</td><td><strong>route/action overlay reported and exact dashboard verified</strong></td><td class="partial">audit-only history · no verification metrics transfer</td></tr><tr><td>RUN-105/R → 106/R page render-owner overlay</td><td><strong>$page_wave_reviewed reviewed · $page_review_owner owner pages · $page_review_shared shared · $page_review_gap evidence gap · 20 page rows · 0 route/bridge rows</strong></td><td class="partial">$static_owner_records cumulative owners · $static_owner_routes routes + $static_owner_pages pages · $static_owner_features FEATURE-IDs · Gate 4 incomplete</td></tr><tr><td>RUN-107 reporting refresh</td><td><strong>page-owner overlay reported</strong></td><td class="partial">audit-only materialization · matrix and queue byte-identical · fresh RUN-108 verification required</td></tr>',
        "checkpoint Wave 14 rows",
    )
    text = replace_once_or_present(
        text,
        '<li>RUN-101/R: 24 outcome-neutral candidates · 21 owners · 3 aliases · 0 shared/dead/gap · 0 page credit</li><li>RUN-102/R: 21 route rows + 21 action bridges integrated and independently verified · 3 aliases preserved as non-owners · $static_owner_records cumulative owner records</li><li>RUN-103: deterministic outcome-neutral reporting refresh · matrix and every Site/permission/privacy/direct-object/lifecycle/execution/benchmark/Pass/finding/completion boundary unchanged</li>',
        '<li>RUN-101/R: historical 24 route candidates · 21 owners · 3 aliases · 0 page credit</li><li>RUN-102/R: historical 21 route rows + 21 action bridges · 592 cumulative owner records</li><li>RUN-103–104: historical reporting refresh and exact superseded dashboard verification</li><li>RUN-105/R: 24 page candidates · 20 owners · 3 shared · 1 evidence gap · 0 route/bridge credit</li><li>RUN-106/R: 20 page rows integrated and independently verified · four non-owners preserved · $static_owner_records cumulative owner records</li><li>RUN-107: deterministic page-owner reporting refresh · matrix, queue, and every Site/permission/privacy/direct-object/lifecycle/execution/benchmark/Pass/finding/completion boundary unchanged</li>',
        "evidence wave list",
    )
    text = replace_once_or_present(
        text,
        'RUN-097/R–100 preserve the historical 23-owner checkpoint, RUN-101/R–102/R add 21 independently reviewed route/action owners and preserve 3 aliases with zero page additions, and RUN-103 refreshes reporting.',
        'RUN-097/R–104 preserve the historical route/action checkpoints, RUN-105/R–106/R add 20 independently reviewed page owners and preserve 3 shared pages plus one evidence gap, and RUN-107 refreshes reporting.',
        "static census intro",
    )
    text = replace_once_or_present(
        text,
        '<tr><td>RUN-102/R current outcome-neutral route/action ownership</td><td>$static_owner_records records · $static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs · $static_action_bridges action bridges</td><td class="partial">$ownership_percent% of bounded 3,929 records · $static_residual residual · 21 owner / 3 alias / zero page delta · Gate 4 incomplete · matrix unchanged</td></tr>',
        '<tr><td>RUN-102/R historical outcome-neutral route/action ownership</td><td>592 records · 265 route + 327 page · 249 FEATURE-IDs · 53 action bridges</td><td class="partial">15.067447% · 3,337 residual · historical bounded checkpoint</td></tr><tr><td>RUN-106/R current outcome-neutral page ownership</td><td>$static_owner_records records · $static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs · $static_action_bridges action bridges</td><td class="partial">$ownership_percent% of bounded 3,929 records · $static_residual residual · $page_review_owner owner / $page_review_shared shared / $page_review_gap gap page delta · pages $static_owner_pages owner + $page_shared shared + $page_residual residual · Gate 4 incomplete · matrix unchanged</td></tr>',
        "static census current overlay row",
    )
    text = replace_once_or_present(
        text,
        'RUN-102/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges with 3 reviewed aliases and zero page additions; complete the framework-expanded canonical route/page denominator, $static_residual residual records including 5 shared routes, 3 alias routes, and 2 shared pages, the full crosswalk, and route reachability before Gate 4 can close',
        'RUN-106/R establish $static_owner_records bounded source-owner records and retain $static_action_bridges action bridges while adding 20 page owners, preserving 3 newly reviewed shared pages, and tagging one evidence gap inside the page residual; complete the framework-expanded canonical route/page denominator, $static_residual non-owner records including 5 shared routes, 3 alias routes, $page_shared shared pages, and $page_gap tagged page gap within $page_residual residual pages, the full crosswalk, and route reachability before Gate 4 can close',
        "Gate 4 current overlay",
    )
    text = replace_once_or_present(
        text,
        'RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, RUN-094, and RUN-100 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-103.',
        'RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, RUN-094, RUN-100, and RUN-104 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-107.',
        "prior dashboard verification paragraph",
    )
    text = replace_once_or_present(
        text,
        '<li><a href="evidence/browser/current-audit-dashboard-verification-run-100-wave-12.json">Superseded RUN-100 verification GO</a></li>',
        '<li><a href="evidence/browser/current-audit-dashboard-verification-run-100-wave-12.json">Superseded RUN-100 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-104-wave-13.json">Superseded RUN-104 verification GO</a></li>',
        "RUN-104 prior verification link",
    )
    text = replace_once_or_present(
        text,
        '<section class="panel"><h2>Fresh RUN-104 audit-dashboard verification</h2><p>The exact regenerated RUN-103 dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-104 receipt records page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 21-owner/3-alias/no-page and zero-credit boundaries, and exact dashboard/generator/reporting hashes. It verifies the audit artifact only and grants no application-browser, responsive, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-104-wave-13.json">RUN-104 responsive audit-dashboard verification receipt</a></li></ul></section>',
        '<section class="panel"><h2>Fresh RUN-108 audit-dashboard verification</h2><p>The exact regenerated RUN-107 dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-108 receipt records page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 612/265/347 ownership, 20-owner/3-shared/1-gap page outcomes, 347/5/359 page conservation, unchanged queue/53-bridge counts, and all zero-credit boundaries. It verifies the audit artifact only and grants no application-browser, responsive, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-108-wave-14.json">RUN-108 responsive audit-dashboard verification receipt</a></li></ul></section>',
        "fresh dashboard verification",
    )
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
        "schema_version": "run-107-reviewed-page-render-owner-reporting-wave-14-v1",
        "run_id": "RUN-107-REVIEWED-PAGE-RENDER-OWNER-REPORTING-WAVE-14",
        "status": "REVIEWED_PAGE_OWNER_OVERLAY_REPORTED_GATE_4_INCOMPLETE",
        "generated_on": "2026-08-25",
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
            "materializer_sha256": sha256_file("generators/materialize-run-107-reviewed-page-render-owner-reporting-wave-14.py"),
            "overlay_sha256": PINNED_INPUTS["evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json"],
            "independent_overlay_review_sha256": PINNED_INPUTS["evidence/source/raw-run-106r-independent-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json"],
            "superseded_dashboard_verification_sha256": PINNED_INPUTS["evidence/browser/current-audit-dashboard-verification-run-104-wave-13.json"],
        },
        "inputs": {**CURRENT_REPORT_INPUTS, **PINNED_INPUTS},
        "outputs": outputs,
        "preserved": PRESERVED,
        "counts": {
            **overlay["combined_counts"],
            **overlay["queue_accounting"],
            "reviewed_pages": 24,
            "reviewed_owner_pages_added": 20,
            "reviewed_shared_pages": 3,
            "reviewed_evidence_gap_pages": 1,
            "route_owner_records_added": 0,
            "controller_action_bridges_added": 0,
            "page_owner_records_added": 20,
            "provisional_finding_records": 12,
            "final_findings": 0,
            "matrix_rows_changed": 0,
            "matrix_cells_changed": 0,
        },
        "checks": {
            "run_105r_review_go": True,
            "run_105r_owner_pages": 20,
            "run_105r_shared_relations": 3,
            "run_105r_evidence_gaps": 1,
            "run_106r_overlay_review_go": True,
            "independent_review_discrepancies": 0,
            "route_owner_records_added": 0,
            "controller_action_bridges_added": 0,
            "page_owner_records_added": 20,
            "reviewed_non_owner_records_preserved": 4,
            "run_090_queue_unchanged": True,
            "matrix_byte_identical": True,
            "provisional_finding_record_semantics_preserved": True,
            "application_source_paths_written": 0,
            "single_tenant_multi_site_architecture_preserved": True,
            "dashboard_requires_fresh_run_108_artifact_verification": True,
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
        "evidence_gap_pages": receipt["counts"]["evidence_gap_page_roots_tagged_within_residual"],
        "gate_4_complete": receipt["checks"]["gate_4_complete"],
    }, indent=2))


if __name__ == "__main__":
    main()
