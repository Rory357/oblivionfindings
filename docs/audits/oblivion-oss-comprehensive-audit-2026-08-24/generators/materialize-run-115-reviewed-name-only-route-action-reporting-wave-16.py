#!/usr/bin/env python3
"""Materialize RUN-115 reporting for the independently reviewed RUN-114 overlay."""

from __future__ import annotations

import hashlib
import json
import runpy
import subprocess
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_RELATIVE = "evidence/source/current-run-115-reviewed-name-only-route-action-reporting-wave-16.json"
CHECKPOINT_COMMIT = "03056a3876da64b805ff7787d7d6c52edc584b73"
CHECKPOINT_TREE = "15fa43e3d3e003cfc2808ba53c73609e4c833f87"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
RECORDS_SHA256 = "118c1a1f19b2e300c77d5b4d71c60f75b7038382f3c71e18f078367f8473e260"

PRIOR = runpy.run_path(str(AUDIT_DIR / "generators/materialize-run-111-reviewed-page-render-owner-tail-reporting-wave-15.py"))
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
    "00-executive-summary.md": "c031e3f64d7b7b30b86d3647d28f3ef119447822cba12a0c5e9c8c354df98d13",
    "01-repository-module-map.md": "e264999d179471f2628fdb1bd84bb524794831c0563021ff8c8dac91671b9abd",
    "13-unresolved-questions-and-evidence-gaps.md": "b2dff21da9b5ba4075e35e27c8117eb7eacabedc7ed13dcb7066c6a97d27035a",
    "findings.json": "49ff5e97b102970a1b9f0913059be03c53fc6d883069b51d56c4263e890d69c3",
    "generators/build-current-audit-dashboard.py": "4818a6baf442f2ec674b9338c99690e56f013fd105e2aca1526bd614fa9ae4a7",
}

PINNED_INPUTS = {
    "generators/materialize-run-111-reviewed-page-render-owner-tail-reporting-wave-15.py": "69a47a2c4b85034113cd798c59f558f359065f0237f6fbca1e7d7f9c34a3449a",
    "evidence/source/current-run-111-reviewed-page-render-owner-tail-reporting-wave-15.json": "ba53c4686450ced0ebbfb56f5637f5631a4cd5aca42610c91adbb5e95139c48b",
    "evidence/browser/current-audit-dashboard-verification-run-112-wave-15.json": "5ff6ac0d5905707016b9de4771b572155293d91cbac70a6130a55a3663cb4d8d",
    "generators/build-outcome-neutral-name-only-route-action-cohort-wave-16.py": "9403a58b2949123daaf1b23fb1db7ea5060c81e595f725dbda2701fff680083f",
    "evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json": "0849ca5c10e31a4f3e5133a521a570d60b004e723fb17a7f2e812e485f345461",
    "generators/materialize-independent-outcome-neutral-name-only-route-action-review-wave-16.py": "eacc817d792aee56692012851d9860b2718cb75536203dc9258b838323361238",
    "evidence/source/raw-run-113r-independent-outcome-neutral-name-only-route-action-review-wave-16.json": "b52872c02b2a1b41861d9eb735eb363fd06cd1af645e1e6c0965b1b042333a83",
    "generators/integrate-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.py": "6cc7f8b3238bd985d3051a6dec969bc46dfcdfd2e6e790e8276a36be285df6e4",
    "evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json": "cbeb46be682ca0c9ca54012e2c27cf548b54e9cbb75b050fcd61691ca43aaef2",
    "generators/materialize-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-review-wave-16.py": "5aa088c66db35c18eee9ee12f31b434d40675d3a61392d49d933bad366ddb52f",
    "evidence/source/raw-run-114r-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json": "f52ace52820c43ad5043139e18f1d71cf4be904091fbc02e83e045465ded62f2",
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
    assert sha256_file("03-feature-to-benchmark-matrix.csv") == MATRIX_SHA256
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

    overlay = read_json("evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json")
    review = read_json("evidence/source/raw-run-114r-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json")
    cohort_review = read_json("evidence/source/raw-run-113r-independent-outcome-neutral-name-only-route-action-review-wave-16.json")
    assert cohort_review["decision"]["verdict"] == "GO_23_EXPLICIT_OWNER_ROUTE_ACTION_1_EXPLICIT_ALIAS_OR_REDIRECT"
    assert cohort_review["decision"]["mechanical_discrepancies"] == 0
    assert review["decision"]["verdict"] == "GO"
    assert review["decision"]["independent_reviews"] == 3
    assert review["decision"]["mechanical_checks_reported"] == 269
    assert review["decision"]["mechanical_discrepancies"] == 0
    assert review["decision"]["semantic_boundary_discrepancies"] == 0
    assert review["decision"]["arithmetic_or_conservation_discrepancies"] == 0
    assert review["decision"]["wording_discrepancies_remaining"] == 0
    assert review["decision"]["reporting_materialization_authorized"] is True
    assert review["decision"]["gate_4_complete"] is False
    assert overlay["combined_counts"] == review["verified_combined_counts"]
    assert overlay["queue_accounting"] == review["verified_queue_accounting"]
    assert overlay["outcome_conservation"] == review["verified_conservation"]
    assert overlay["identity"] == review["verified_identity"]
    assert len(overlay["identity"]) == 38
    counts = overlay["combined_counts"]
    assert (counts["source_owner_records"], counts["route_owner_records"], counts["page_owner_records"]) == (637, 288, 349)
    assert (counts["distinct_feature_ids"], counts["distinct_H_feature_ids"], counts["distinct_D_feature_ids"]) == (256, 234, 22)
    assert (counts["route_distinct_feature_ids"], counts["page_distinct_feature_ids"], counts["route_page_feature_overlap"]) == (61, 242, 47)
    assert counts["static_controller_action_bridges"] == 76
    assert counts["bounded_static_source_residual_records"] == 3292
    assert counts["residual_explicit_unmapped_routes"] == 2921
    assert (counts["semantic_shared_page_roots"], counts["residual_unadjudicated_page_roots"], counts["evidence_gap_page_roots_tagged_within_residual"]) == (9, 353, 1)
    assert overlay["reviewed_overlay"]["reviewed_route_actions"] == 24
    assert overlay["reviewed_overlay"]["owner_route_actions"] == 23
    assert overlay["reviewed_overlay"]["alias_or_redirect"] == 1
    assert overlay["reviewed_overlay"]["accepted_page_owner_records"] == 0
    assert len(overlay["overlay_source_records"]) == 23
    assert len(overlay["new_static_controller_action_bridges"]) == 23
    assert len(overlay["reviewed_non_owner_outcomes"]) == 1
    assert overlay["page_context_boundary"] == {
        "literal_callsites": 7,
        "currently_owned_page_callsites": 3,
        "current_page_evidence_gap_callsites": 4,
        "page_ownership_authorized": 0,
        "rule": "Owned pages remain observation only; four Respite page gaps remain gaps and cannot inherit route ownership.",
    }
    queue = overlay["queue_accounting"]
    assert (queue["reviewed_queue_surface_rows"], queue["owner_queue_surface_rows"], queue["shared_queue_surface_rows"], queue["alias_queue_surface_rows"]) == (84, 77, 3, 4)
    assert (queue["pending_unreviewed_queue_surface_rows"], queue["queue_surfaces_without_ownership"]) == (423, 430)
    assert 3929 == 637 + 3292
    assert 637 == 288 + 349
    assert 3218 == 288 + 5 + 4 + 2921
    assert 711 == 349 + 9 + 353
    assert 256 == 61 + 242 - 47
    assert 256 == 234 + 22
    assert 76 == 53 + 23
    assert 507 == 84 + 423
    assert 84 == 77 + 3 + 4
    assert 430 == 423 + 3 + 4
    true_credits = {key for key, value in overlay["credit_boundary"].items() if value is True}
    assert true_credits == {
        "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_23_RECORDS",
        "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_23_ACTIONS",
        "REVIEWED_ALIAS_OR_REDIRECT_FOR_1_RECORD",
    }
    assert overlay["denominator_boundary"]["gate_4_complete"] is False
    assert overlay["audit_completion_test_met"] is False
    return overlay, review


def patch_reports() -> None:
    summary_relative = "00-executive-summary.md"
    summary = path(summary_relative).read_text(encoding="utf-8")
    summary = replace_once_or_present(
        summary,
        "## RUN-109–111 reviewed outcome-neutral page render-owner tail overlay",
        "## Historical RUN-109–111 reviewed outcome-neutral page render-owner tail overlay",
        "historical Wave 15 summary heading",
    )
    summary = replace_once_or_present(
        summary,
        "The current bounded checkpoint is **614 records = 265 routes + 349 pages across 256 canonical FEATURE-IDs (234 H + 22 D)**, with 53 controller-action bridges.",
        "That bounded checkpoint was **614 records = 265 routes + 349 pages across 256 canonical FEATURE-IDs (234 H + 22 D)**, with 53 controller-action bridges.",
        "historical Wave 15 summary count",
    )
    summary = replace_once_or_present(
        summary, "RUN-111 reports only that bounded tail delta.", "RUN-111 reported only that bounded tail delta.",
        "historical Wave 15 reporting tense",
    )
    section = """
## RUN-113–115 reviewed outcome-neutral name-only route/action overlay

RUN-113 freezes 24 still-pending RUN-090 direct-exact route/action rows—16 Fleet incident actions and eight Respite handover-note actions—without pre-awarding ownership. All 24 have `NAME_ONLY` identity and zero backend candidates; absence is not negative proof. Fresh three-part semantic review classifies 23 as `OWNER_ROUTE_ACTION` and the Fleet incident `create` route as `ALIAS_OR_REDIRECT`. Neither name identity nor exact method resolution determines ownership. Seven literal page callsites remain context only: three were already owned and four Respite page contexts still require separate review.

RUN-114 integrates only the 23 explicit owners as 23 bounded route-source records and 23 controller-action bridges. The reviewed create redirect remains a non-owner and occurs in neither owner nor bridge arrays. It adds zero page records and inherits no frontend or page ownership. RUN-114R independently reproduces the exact producer bytes, committed parent and two-blob delta, all 38 identity fields, counts, queue sets, conservation equations, and zero-credit boundaries with zero discrepancies.

The current bounded checkpoint is **637 records = 288 routes + 349 pages across 256 canonical FEATURE-IDs (234 H + 22 D)**, with 76 controller-action bridges. Route and page owners span 61 and 242 FEATURE-IDs with 47 in their overlap; both accepted Wave 16 FEATURE-IDs were already represented globally. This is 16.212777% of the bounded 3,929-record source universe; 3,292 records remain. The page universe remains **711 = 349 owners + 9 shared + 353 residual**, with the earlier tagged evidence gap inside that 353. The route universe is **3,218 = 288 owners + 5 shared + 4 aliases + 2,921 residual**. Queue accounting is **507 = 84 reviewed + 423 pending**; the reviewed set is 77 owned, three shared, and four aliases, while 430 remain without ownership.

RUN-115 reports only that bounded route/action delta. Oblivion Findings remains one operating organisation across multiple Sites. Static page ownership, frontend caller ownership, framework reachability, navigation, Site access, roles/permissions, canonical object ownership, direct-object concealment, privacy, lifecycle, concurrency, runtime, database, build, application browser, tests, benchmarks, ease, Passes, findings, completion, and Gate 4 remain separate open or zero-credit gates. The 340-row matrix remains byte-identical and mapping remains 0/340.
"""
    summary = upsert_section_before(
        summary, "## RUN-113–115 reviewed outcome-neutral name-only route/action overlay",
        "\n## Current raw source census\n", section, "Wave 16 summary section",
    )
    marker = (
        "- `generators/materialize-run-111-reviewed-page-render-owner-tail-reporting-wave-15.py` and "
        "`evidence/source/current-run-111-reviewed-page-render-owner-tail-reporting-wave-15.json`: "
        "deterministic RUN-111 reporting refresh preserving the matrix, reports 02–12/inventory, and every downstream zero-credit boundary.\n"
    )
    addition = marker + (
        "- `evidence/browser/current-audit-dashboard-verification-run-112-wave-15.json`: exact superseded RUN-111 dashboard artifact verification; no metrics transfer to the regenerated RUN-115 dashboard.\n"
        "- `generators/build-outcome-neutral-name-only-route-action-cohort-wave-16.py` and `evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json`: deterministic 24-row name-only route/action cohort with zero pre-review credit.\n"
        "- `generators/materialize-independent-outcome-neutral-name-only-route-action-review-wave-16.py` and `evidence/source/raw-run-113r-independent-outcome-neutral-name-only-route-action-review-wave-16.json`: fresh 23-owner / 1-alias semantic review with zero page or downstream credit.\n"
        "- `generators/integrate-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.py` and `evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json`: exact 23-route owner-only delta plus 23 controller-action bridges and one preserved alias.\n"
        "- `generators/materialize-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-review-wave-16.py` and `evidence/source/raw-run-114r-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json`: independent final-byte, 38-identity, ancestry, queue-accounting, and semantic-boundary GO receipt.\n"
        "- `generators/materialize-run-115-reviewed-name-only-route-action-reporting-wave-16.py` and `evidence/source/current-run-115-reviewed-name-only-route-action-reporting-wave-16.json`: deterministic RUN-115 reporting refresh preserving the matrix, reports 02–12/inventory, and every downstream zero-credit boundary.\n"
    )
    summary = replace_once_or_present(summary, marker, addition, "Wave 16 evidence links")
    write_lf(summary_relative, summary)

    map_relative = "01-repository-module-map.md"
    module_map = path(map_relative).read_text(encoding="utf-8")
    module_map = replace_once_or_present(
        module_map,
        "## RUN-109–110 reviewed page render-owner tail overlay",
        "## Historical RUN-109–110 reviewed page render-owner tail overlay",
        "historical Wave 15 map heading",
    )
    module_map = replace_once_or_present(
        module_map,
        "The cumulative bounded ledger is 614 source owners (265 route + 349 page) across 256 FEATURE-IDs (234 H + 22 D).",
        "That cumulative bounded ledger was 614 source owners (265 route + 349 page) across 256 FEATURE-IDs (234 H + 22 D).",
        "historical Wave 15 map count",
    )
    map_section = """
## RUN-113–114 reviewed outcome-neutral name-only route/action overlay

RUN-113 freezes 24 pending direct-exact route actions for fresh outcome-neutral review: 16 Fleet incident actions and eight Respite handover-note actions. All are `NAME_ONLY` relations with zero pre-review credit. Fresh semantic review accepts 23 explicit route/action owners and preserves the Fleet incident `create` redirect as one reviewed alias; seven literal page callsites remain context only and add zero page ownership.

RUN-114 adds exactly 23 route owners, 23 controller-action bridges, and zero page owners. The cumulative bounded ledger is 637 source owners (288 route + 349 page) across 256 FEATURE-IDs (234 H + 22 D). Route/page feature sets are 61/242 with overlap 47, while the action-bridge count is 76. Route accounting is 3,218 = 288 owners + 5 shared + 4 aliases + 2,921 residual. Page accounting remains 711 = 349 owners + 9 shared + 353 residual. RUN-090 queue accounting is 507 total, 84 reviewed, 77 owned, three shared, four aliases, 423 pending, and 430 without ownership.

RUN-114R verifies exact final bytes, the committed two-blob delta, all 38 identities, queue reconciliation, and all semantic boundaries with zero discrepancies. These relations establish bounded static route ownership, controller-action bridges, and explicit alias classification only; they do not establish page or frontend ownership, framework reachability, Site or permission correctness, canonical direct-object concealment, privacy, lifecycle, concurrency, runtime, build, browser, tests, benchmarks, findings, Passes, or completion.
"""
    module_map = upsert_section_before(
        module_map, "## RUN-113–114 reviewed outcome-neutral name-only route/action overlay",
        "\n## Candidate register\n", map_section, "Wave 16 map section",
    )
    write_lf(map_relative, module_map)

    gaps_relative = "13-unresolved-questions-and-evidence-gaps.md"
    gaps = path(gaps_relative).read_text(encoding="utf-8")
    rows = {
        "| Required reporting paths |": "| Required reporting paths | 18 / 18 prompt-required files or directories present. RUN-112 independently verified the exact now-superseded RUN-111 dashboard at four viewports; the regenerated RUN-115 dashboard requires a separate fresh RUN-116 artifact receipt. | Presence and prior audit-artifact verification make the reporting contract inspectable but grant no application execution, final-finding, Pass, or completion credit. Gate 26 remains open. | Complete the semantic, execution, benchmark, Pass 8, final reconciliation, and no-live-agent gates; repeat audit-dashboard verification after every later HTML change. |",
        "| Runtime routes |": "| Runtime routes | RUN-114/R establish 288 bounded route-owner records and 76 static controller-action bridges; 2,921 residual explicit-unmapped route rows, five semantic-shared route rows, and four reviewed alias rows remain distinguished within the bounded 3,218-row static route-like universe. | RUN-113–114 add exactly 23 route-owner records and 23 action bridges while preserving one reviewed create redirect as a non-owner. Static owner/action linkage and `NAME_ONLY` review are not a framework-expanded route table, reachability proof, or authorization proof. | Under a fresh bounded runtime grant, use an exact disposable database, execute a separately pinned framework/provider route lane, and reconcile it to all 38 source route files and 3,218 route-like rows. |",
        "| Inertia pages |": "| Inertia pages | RUN-084/R enumerate 1,058 physical page-tree files. RUN-114/R retain 349 bounded page owners, nine semantic-shared roots, and 353 residual roots including one earlier tagged evidence gap. | Wave 16 adds zero page owners: three already-owned literal callsites remain observation only and four Respite page contexts still require separate review. Full-tree structural GO and bounded route ownership are not a complete canonical crosswalk, runtime reachability, build resolution, rendered browser behaviour, or final feature mapping. | Reconcile all 711 literal roots and support relations to safely expanded framework routes and frozen FEATURE-IDs, retain shared and gap relations explicitly, prove build resolution, and observe every safely reachable current-build page under approved roles/Sites. |",
        "| Canonical features |": "| Canonical features | Static canonical identity remains 340 targets: 300 H, 40 D, zero M; RUN-114/R establish 637 bounded source-owner records (288 routes + 349 pages) across 256 FEATURE-IDs (234 H + 22 D) plus 76 controller-action bridges while the matrix remains byte-identical at `dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390`. | This is 16.212777% of the bounded 3,929-record source universe. Gate 4 remains incomplete: 3,292 non-owner records, the framework-expanded denominator, shared, alias, and gap relations, reachability, and the full crosswalk remain open; both Wave 16 FEATURE-IDs were already globally represented and matrix target mapping stays 0/340. | Finish canonical denominator and residual ownership adjudication without inheriting FEATURE-IDs from prefixes, imports, containment, names, or presence, and without awarding runtime, browser, test, benchmark, ease, release, or completion credit. |",
        "| Agent universe and writer rule |": "| Agent universe and writer rule | RUN-001 through RUN-115 represented at the current reporting checkpoint; finalization gate false. | RUN-113/R review 24 name-only route actions as 23 owners and one alias; RUN-114/R independently integrate and verify 23 route owners plus 23 action bridges with zero page additions; RUN-115 reports only those bounded classes. Runtime, browser, tests, benchmarks, Pass 8, finalization, and completion remain open. | Complete residual ownership and every semantic/execution gate, then dispatch fresh Pass 8/final cross-reviewers, verify the final dashboard, and prove no agent remains live at finalization. |",
    }
    for marker, replacement in rows.items():
        gaps = replace_line_containing(gaps, marker, replacement, marker)
    gaps = replace_once_or_present(
        gaps,
        "## RUN-077–111 route/page, page-tree, backend, ownership and reporting lineage",
        "## RUN-077–115 route/page, page-tree, backend, ownership and reporting lineage",
        "Wave 16 lineage heading",
    )
    lineage = "RUN-077 freezes the exhaustive committed-source route/name/page universe through RUN-084B's page-tree and backend structural ledgers. RUN-086/R establish the initial 530 bounded source owners; RUN-090 freezes the zero-credit direct-exact queue; RUN-091/R–103 successively add and report reviewed closed-chain and route/action ownership while preserving shared and alias outcomes, reaching 592 owners. RUN-104 verifies that superseded dashboard. RUN-105/R–107 review, integrate, and report 20 page owners, three shared relations, and one evidence gap, reaching 612 owners; RUN-108 verifies that superseded dashboard. RUN-109/R review the six-page tail as two owners and four shared relations; RUN-110/R integrate and independently verify two page owners and one reviewed-shared queue reconciliation, reaching 614 owners; RUN-111 reports that delta and RUN-112 verifies its now-superseded dashboard. RUN-113/R review 24 direct-exact name-only route actions as 23 owners and one alias. RUN-114/R integrate and independently verify exactly 23 route-source owners and 23 controller-action bridges with zero page additions, reaching 637 owners and 76 bridges; RUN-115 reports only that bounded delta. Gate 4 and the full route/page/backend crosswalk remain incomplete; Oblivion Findings remains one operating organisation across multiple Sites, and framework reachability, Site/permission/privacy/direct-object/lifecycle/concurrency correctness, runtime, build, signed-in application browser, executed tests, benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit."
    gaps = replace_line_containing(
        gaps, "RUN-077 freezes the exhaustive committed-source route/name/page universe",
        lineage, "Wave 16 lineage paragraph",
    )
    write_lf(gaps_relative, gaps)


def patch_findings(overlay: dict[str, Any], review: dict[str, Any]) -> None:
    relative = "findings.json"
    findings = read_json(relative)
    assert canonical_json_sha256(findings["records"]) == RECORDS_SHA256
    findings["generated_on"] = "2026-08-26"
    pin_names = {
        "run_111_reporting_materializer_sha256": "generators/materialize-run-111-reviewed-page-render-owner-tail-reporting-wave-15.py",
        "run_111_reporting_sha256": "evidence/source/current-run-111-reviewed-page-render-owner-tail-reporting-wave-15.json",
        "run_112_dashboard_verification_sha256": "evidence/browser/current-audit-dashboard-verification-run-112-wave-15.json",
        "run_113_name_only_route_action_cohort_generator_sha256": "generators/build-outcome-neutral-name-only-route-action-cohort-wave-16.py",
        "run_113_name_only_route_action_cohort_sha256": "evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json",
        "run_113r_name_only_route_action_review_materializer_sha256": "generators/materialize-independent-outcome-neutral-name-only-route-action-review-wave-16.py",
        "run_113r_name_only_route_action_review_sha256": "evidence/source/raw-run-113r-independent-outcome-neutral-name-only-route-action-review-wave-16.json",
        "run_114_name_only_route_action_overlay_generator_sha256": "generators/integrate-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.py",
        "run_114_name_only_route_action_overlay_sha256": "evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json",
        "run_114r_name_only_route_action_overlay_review_materializer_sha256": "generators/materialize-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-review-wave-16.py",
        "run_114r_name_only_route_action_overlay_review_sha256": "evidence/source/raw-run-114r-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json",
    }
    findings["pins"].update({key: PINNED_INPUTS[value] for key, value in pin_names.items()})
    findings["counts"].update({
        "static_source_feature_ownership_records": 637,
        "static_source_feature_ownership_route_records": 288,
        "static_source_feature_ownership_page_records": 349,
        "static_source_feature_ownership_distinct_feature_ids": 256,
        "static_source_feature_ownership_distinct_H_feature_ids": 234,
        "static_source_feature_ownership_distinct_D_feature_ids": 22,
        "static_source_feature_ownership_route_distinct_feature_ids": 61,
        "static_source_feature_ownership_page_distinct_feature_ids": 242,
        "static_source_feature_ownership_route_page_feature_overlap": 47,
        "static_controller_action_bridges": 76,
        "bounded_static_source_ownership_percent": "16.212777",
        "bounded_static_source_residual_records": 3292,
        "direct_exact_queue_reviewed": 84,
        "direct_exact_queue_owned": 77,
        "direct_exact_queue_shared": 3,
        "direct_exact_queue_alias": 4,
        "direct_exact_queue_dead_or_noncanonical": 0,
        "direct_exact_queue_evidence_gap": 0,
        "direct_exact_queue_pending_unreviewed": 423,
        "direct_exact_queue_without_ownership": 430,
    })
    current = findings.get("current_static_source_feature_ownership", {})
    if current.get("run_id", "").startswith("RUN-110-"):
        findings["historical_run_110_outcome_neutral_page_render_owner_tail_ownership"] = findings.pop(
            "current_static_source_feature_ownership"
        )
    findings["current_static_source_feature_ownership"] = {
        "run_id": overlay["run_id"],
        "review_run_id": review["run_id"],
        "status": "GO_REVIEWED_BOUNDED_OUTCOME_NEUTRAL_NAME_ONLY_ROUTE_ACTION_OWNERSHIP_ONLY",
        "baseline_records": 614,
        "reviewed_route_actions": 24,
        "overlay_source_records": 23,
        "owner_route_actions_added": 23,
        "reviewed_alias_or_redirect": 1,
        "shared_relations_added": 0,
        "dead_or_noncanonical": 0,
        "evidence_gaps": 0,
        "controller_action_bridges_added": 23,
        "page_owner_records_added": 0,
        **overlay["combined_counts"],
        "queue_accounting": overlay["queue_accounting"],
        "name_only_provenance": overlay["name_only_provenance"],
        "page_context_boundary": overlay["page_context_boundary"],
        "independent_review_discrepancies": 0,
        "gate_4": {"status": "PARTIAL_BOUNDED_STATIC_SOURCE_OWNERSHIP_INCOMPLETE", "complete": False},
        "credit_boundary": overlay["credit_boundary"],
    }
    prior_review_key = "current_outcome_neutral_page_render_owner_tail_ownership_review"
    if prior_review_key in findings:
        findings["historical_run_110_outcome_neutral_page_render_owner_tail_ownership_review"] = findings.pop(prior_review_key)
    findings["current_outcome_neutral_name_only_route_action_ownership_review"] = {
        "run_id": review["run_id"],
        "status": review["status"],
        "reviewers": 3,
        "mechanical_checks_reported": 269,
        "route_owner_records_authorized": 23,
        "controller_action_bridges_authorized": 23,
        "reviewed_alias_records_authorized": 1,
        "page_owner_records_authorized": 0,
        "mechanical_discrepancies": 0,
        "semantic_boundary_discrepancies": 0,
        "arithmetic_or_conservation_discrepancies": 0,
        "wording_discrepancies_remaining": 0,
        "reporting_materialization_authorized": True,
        "downstream_credit_authorized": False,
        "gate_4_complete": False,
        "completion_credit": False,
    }
    run112 = read_json("evidence/browser/current-audit-dashboard-verification-run-112-wave-15.json")
    findings["current_audit_artifact_verification_history"]["run_112"] = {
        "status": "GO_EXACT_SUPERSEDED_RUN_111_DASHBOARD_ARTIFACT_ZERO_APPLICATION_CREDIT",
        "dashboard_sha256": run112["pins"]["dashboard_html_sha256"],
        "receipt_sha256": PINNED_INPUTS["evidence/browser/current-audit-dashboard-verification-run-112-wave-15.json"],
        "viewports_verified": run112["verification"]["viewports_verified"],
        "unique_local_links_verified": run112["verification"]["unique_local_links"],
        "anchors_verified": run112["verification"]["anchors"],
        "duplicate_authored_ids": run112["verification"]["duplicate_authored_ids"],
        "console_warnings": run112["verification"]["console_warnings"],
        "console_errors": run112["verification"]["console_errors"],
        "page_errors": run112["verification"].get("page_errors", 0),
        "current_dashboard_credit": False,
        "application_browser_credit": False,
    }
    queue = findings["current_direct_exact_route_page_review_queue"]
    assert queue["records"] == 507
    assert queue["reviewed_queue_surfaces"] in {60, 84}
    queue.update({
        "reviewed_queue_surfaces": 84,
        "owned_queue_surfaces": 77,
        "shared_queue_surfaces": 3,
        "alias_queue_surfaces": 4,
        "dead_or_noncanonical_queue_surfaces": 0,
        "evidence_gap_queue_surfaces": 0,
        "pending_unreviewed": 423,
        "without_ownership": 430,
        "wholesale_ownership_authorized": False,
    })
    assert canonical_json_sha256(findings["records"]) == RECORDS_SHA256
    assert findings["counts"]["final_P0"] == 0
    assert findings["counts"]["final_P1"] == 0
    assert findings["counts"]["benchmark_mapped"] == 0
    assert findings["counts"]["final_no_match"] == 0
    write_lf(relative, json.dumps(findings, ensure_ascii=False, indent=2) + "\n")


def patch_dashboard_template() -> None:
    relative = "generators/build-current-audit-dashboard.py"
    text = path(relative).read_text(encoding="utf-8")

    declaration_marker = 'reviewed_page_owner_overlay_review = read_json("evidence/source/raw-run-110r-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json")'
    declaration_addition = declaration_marker + """
name_only_route_action_cohort = read_json("evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json")
name_only_route_action_review = read_json("evidence/source/raw-run-113r-independent-outcome-neutral-name-only-route-action-review-wave-16.json")
reviewed_name_only_route_action_overlay = read_json("evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json")
reviewed_name_only_route_action_overlay_review = read_json("evidence/source/raw-run-114r-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json")"""
    declaration_end = 'assert sha256_file("evidence/source/current-canonical-feature-identity-wave-01.json")'
    declaration_start_index = text.index(declaration_marker)
    declaration_end_index = text.index(declaration_end, declaration_start_index)
    canonical_declarations = declaration_addition + "\n"
    if text[declaration_start_index:declaration_end_index] != canonical_declarations:
        text = text[:declaration_start_index] + canonical_declarations + text[declaration_end_index:]

    malformed_last_pin = 'assert sha256_file("evidence/source/raw-run-114r-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json") == "f52ace52820c43ad5043139e18f1d71cf4be904091fbc02e83e045465ded62f2'
    corrected_last_pin = malformed_last_pin + '"'
    if malformed_last_pin in text and corrected_last_pin not in text:
        text = text.replace(malformed_last_pin, corrected_last_pin, 1)

    pin_marker = 'assert sha256_file("evidence/source/raw-run-110r-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json") == "e9b076e790e5346f99665f8f99ee609b4c7b7bac4767e416abc73a57f7dfd867"'
    pin_addition = pin_marker + """
assert sha256_file("generators/materialize-run-111-reviewed-page-render-owner-tail-reporting-wave-15.py") == "69a47a2c4b85034113cd798c59f558f359065f0237f6fbca1e7d7f9c34a3449a"
assert sha256_file("evidence/source/current-run-111-reviewed-page-render-owner-tail-reporting-wave-15.json") == "ba53c4686450ced0ebbfb56f5637f5631a4cd5aca42610c91adbb5e95139c48b"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-112-wave-15.json") == "5ff6ac0d5905707016b9de4771b572155293d91cbac70a6130a55a3663cb4d8d"
assert sha256_file("generators/build-outcome-neutral-name-only-route-action-cohort-wave-16.py") == "9403a58b2949123daaf1b23fb1db7ea5060c81e595f725dbda2701fff680083f"
assert sha256_file("evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json") == "0849ca5c10e31a4f3e5133a521a570d60b004e723fb17a7f2e812e485f345461"
assert sha256_file("generators/materialize-independent-outcome-neutral-name-only-route-action-review-wave-16.py") == "eacc817d792aee56692012851d9860b2718cb75536203dc9258b838323361238"
assert sha256_file("evidence/source/raw-run-113r-independent-outcome-neutral-name-only-route-action-review-wave-16.json") == "b52872c02b2a1b41861d9eb735eb363fd06cd1af645e1e6c0965b1b042333a83"
assert sha256_file("generators/integrate-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.py") == "6cc7f8b3238bd985d3051a6dec969bc46dfcdfd2e6e790e8276a36be285df6e4"
assert sha256_file("evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json") == "cbeb46be682ca0c9ca54012e2c27cf548b54e9cbb75b050fcd61691ca43aaef2"
assert sha256_file("generators/materialize-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-review-wave-16.py") == "5aa088c66db35c18eee9ee12f31b434d40675d3a61392d49d933bad366ddb52f"
assert sha256_file("evidence/source/raw-run-114r-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json") == "f52ace52820c43ad5043139e18f1d71cf4be904091fbc02e83e045465ded62f2"
""".rstrip("\n")
    text = replace_once_or_present(text, pin_marker, pin_addition, "Wave 16 evidence pins")

    assertion_block = """
assert name_only_route_action_cohort["counts"]["candidate_route_actions"] == 24
assert name_only_route_action_cohort["counts"]["candidate_route_records"] == 24
assert name_only_route_action_cohort["counts"]["candidate_controller_action_bridges"] == 24
assert name_only_route_action_cohort["counts"]["candidate_page_records"] == 0
assert name_only_route_action_cohort["counts"]["distinct_feature_ids"] == 2
assert name_only_route_action_cohort["counts"]["distinct_feature_ids_not_in_current_owner_set"] == 0
assert (name_only_route_action_cohort["counts"]["literal_page_callsites"], name_only_route_action_cohort["counts"]["literal_page_callsites_currently_owned"], name_only_route_action_cohort["counts"]["literal_page_callsites_current_evidence_gap"]) == (7, 3, 4)
assert name_only_route_action_cohort["counts"]["ownership_credit_awarded"] == 0
assert name_only_route_action_cohort["counts"]["page_ownership_credit_awarded"] == 0
assert name_only_route_action_review["decision"]["verdict"] == "GO_23_EXPLICIT_OWNER_ROUTE_ACTION_1_EXPLICIT_ALIAS_OR_REDIRECT"
assert (name_only_route_action_review["decision"]["reviewed_route_actions"], name_only_route_action_review["decision"]["owner_route_actions"], name_only_route_action_review["decision"]["shared_relations"], name_only_route_action_review["decision"]["alias_or_redirect"], name_only_route_action_review["decision"]["dead_or_noncanonical"], name_only_route_action_review["decision"]["evidence_gaps"]) == (24, 23, 0, 1, 0, 0)
assert (name_only_route_action_review["decision"]["static_route_owner_records_authorized"], name_only_route_action_review["decision"]["static_controller_action_bridges_authorized"], name_only_route_action_review["decision"]["static_page_owner_records_authorized"]) == (23, 23, 0)
assert name_only_route_action_review["decision"]["gate_4_complete"] is False
assert reviewed_name_only_route_action_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_name_only_route_action_overlay_review["decision"]["mechanical_checks_reported"] == 269
assert all(reviewed_name_only_route_action_overlay_review["decision"][key] == 0 for key in ("mechanical_discrepancies", "semantic_boundary_discrepancies", "arithmetic_or_conservation_discrepancies", "wording_discrepancies_remaining"))
assert reviewed_name_only_route_action_overlay_review["decision"]["reporting_materialization_authorized"] is True
assert reviewed_name_only_route_action_overlay_review["decision"]["gate_4_complete"] is False
assert reviewed_name_only_route_action_overlay["combined_counts"] == reviewed_name_only_route_action_overlay_review["verified_combined_counts"]
assert reviewed_name_only_route_action_overlay["queue_accounting"] == reviewed_name_only_route_action_overlay_review["verified_queue_accounting"]
assert reviewed_name_only_route_action_overlay["outcome_conservation"] == reviewed_name_only_route_action_overlay_review["verified_conservation"]
assert reviewed_name_only_route_action_overlay["identity"] == reviewed_name_only_route_action_overlay_review["verified_identity"]
assert len(reviewed_name_only_route_action_overlay["identity"]) == len(reviewed_name_only_route_action_overlay_review["verified_identity"]) == 38
assert (len(reviewed_name_only_route_action_overlay["overlay_source_records"]), len(reviewed_name_only_route_action_overlay["new_static_controller_action_bridges"]), len(reviewed_name_only_route_action_overlay["reviewed_non_owner_outcomes"])) == (23, 23, 1)
counts = reviewed_name_only_route_action_overlay["combined_counts"]
queue = reviewed_name_only_route_action_overlay["queue_accounting"]
assert (counts["source_owner_records"], counts["route_owner_records"], counts["page_owner_records"]) == (637, 288, 349)
assert (counts["distinct_feature_ids"], counts["distinct_H_feature_ids"], counts["distinct_D_feature_ids"]) == (256, 234, 22)
assert (counts["route_distinct_feature_ids"], counts["page_distinct_feature_ids"], counts["route_page_feature_overlap"]) == (61, 242, 47)
assert (counts["static_controller_action_bridges"], counts["bounded_static_source_residual_records"]) == (76, 3292)
assert (counts["residual_explicit_unmapped_routes"], counts["semantic_shared_routes"], counts["reviewed_alias_routes"]) == (2921, 5, 4)
assert (counts["residual_unadjudicated_page_roots"], counts["semantic_shared_page_roots"], counts["evidence_gap_page_roots_tagged_within_residual"]) == (353, 9, 1)
assert (queue["direct_exact_queue_records"], queue["reviewed_queue_surface_rows"], queue["owner_queue_surface_rows"], queue["shared_queue_surface_rows"], queue["alias_queue_surface_rows"], queue["pending_unreviewed_queue_surface_rows"], queue["queue_surfaces_without_ownership"]) == (507, 84, 77, 3, 4, 423, 430)
assert 3929 == 637 + 3292
assert 637 == 288 + 349
assert 3218 == 288 + 5 + 4 + 2921
assert 711 == 349 + 9 + 353
assert 256 == 61 + 242 - 47
assert 256 == 234 + 22
assert 76 == 53 + 23
assert 507 == 84 + 423
assert 84 == 77 + 3 + 4
assert 430 == 423 + 3 + 4
assert reviewed_name_only_route_action_overlay["page_context_boundary"] == {"literal_callsites": 7, "currently_owned_page_callsites": 3, "current_page_evidence_gap_callsites": 4, "page_ownership_authorized": 0, "rule": "Owned pages remain observation only; four Respite page gaps remain gaps and cannot inherit route ownership."}
for key in ("STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_23_RECORDS", "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_23_ACTIONS", "REVIEWED_ALIAS_OR_REDIRECT_FOR_1_RECORD"):
    assert reviewed_name_only_route_action_overlay["credit_boundary"][key] is True
assert all(reviewed_name_only_route_action_overlay["credit_boundary"][key] is False for key in ("static_page_feature_ownership", "frontend_caller_ownership", "matrix_mutation", "wholesale_507_queue_ownership", "complete_route_page_feature_crosswalk", "framework_route_reachability", "navigation", "site_authorization_correctness", "permission_correctness", "privacy_correctness", "direct_object_correctness", "lifecycle_correctness", "concurrency_correctness", "runtime", "database", "build", "application_browser", "executed_tests", "benchmark", "ease", "pass", "final_finding", "completion", "audit_complete"))
"""
    assertion_anchor = "\n\n\ncandidates = wave1[\"candidates\"]"
    if assertion_block not in text:
        assert text.count(assertion_anchor) == 1
        text = text.replace(assertion_anchor, "\n" + assertion_block + assertion_anchor)

    evidence_marker = '    ("RUN-111 page-tail reporting/hash receipt", "evidence/source/current-run-111-reviewed-page-render-owner-tail-reporting-wave-15.json"),\n'
    evidence_addition = evidence_marker + """    ("RUN-112 verified superseded dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-112-wave-15.json"),
    ("RUN-113 name-only route/action cohort generator", "generators/build-outcome-neutral-name-only-route-action-cohort-wave-16.py"),
    ("RUN-113 24-row name-only route/action cohort", "evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json"),
    ("RUN-113R independent 23-owner / 1-alias review materializer", "generators/materialize-independent-outcome-neutral-name-only-route-action-review-wave-16.py"),
    ("RUN-113R independent name-only route/action review", "evidence/source/raw-run-113r-independent-outcome-neutral-name-only-route-action-review-wave-16.json"),
    ("RUN-114 owner-only route/action overlay generator", "generators/integrate-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.py"),
    ("RUN-114 23-route owner overlay with one alias non-owner", "evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json"),
    ("RUN-114R independent overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-review-wave-16.py"),
    ("RUN-114R independent final-byte identity queue and boundary review", "evidence/source/raw-run-114r-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json"),
    ("RUN-115 name-only route/action reporting materializer", "generators/materialize-run-115-reviewed-name-only-route-action-reporting-wave-16.py"),
    ("RUN-115 name-only route/action reporting/hash receipt", "evidence/source/current-run-115-reviewed-name-only-route-action-reporting-wave-16.json"),
"""
    text = replace_once_or_present(text, evidence_marker, evidence_addition, "Wave 16 dashboard evidence links")

    mapping_block = """    static_owner_records=reviewed_name_only_route_action_overlay["combined_counts"]["source_owner_records"],
    static_owner_routes=reviewed_name_only_route_action_overlay["combined_counts"]["route_owner_records"],
    static_owner_pages=reviewed_name_only_route_action_overlay["combined_counts"]["page_owner_records"],
    static_owner_features=reviewed_name_only_route_action_overlay["combined_counts"]["distinct_feature_ids"],
    static_owner_h_features=reviewed_name_only_route_action_overlay["combined_counts"]["distinct_H_feature_ids"],
    static_owner_d_features=reviewed_name_only_route_action_overlay["combined_counts"]["distinct_D_feature_ids"],
    route_feature_ids=reviewed_name_only_route_action_overlay["combined_counts"]["route_distinct_feature_ids"],
    page_feature_ids=reviewed_name_only_route_action_overlay["combined_counts"]["page_distinct_feature_ids"],
    route_page_overlap=reviewed_name_only_route_action_overlay["combined_counts"]["route_page_feature_overlap"],
    static_action_bridges=reviewed_name_only_route_action_overlay["combined_counts"]["static_controller_action_bridges"],
    static_residual=f"{reviewed_name_only_route_action_overlay['combined_counts']['bounded_static_source_residual_records']:,}",
    ownership_percent=reviewed_name_only_route_action_overlay["combined_counts"]["bounded_static_source_ownership_percent"],
    route_residual=f"{reviewed_name_only_route_action_overlay['combined_counts']['residual_explicit_unmapped_routes']:,}",
    route_shared_current=reviewed_name_only_route_action_overlay["combined_counts"]["semantic_shared_routes"],
    route_alias_current=reviewed_name_only_route_action_overlay["combined_counts"]["reviewed_alias_routes"],
    page_shared=reviewed_name_only_route_action_overlay["combined_counts"]["semantic_shared_page_roots"],
    page_residual=reviewed_name_only_route_action_overlay["combined_counts"]["residual_unadjudicated_page_roots"],
    page_gap=reviewed_name_only_route_action_overlay["combined_counts"]["evidence_gap_page_roots_tagged_within_residual"],
    page_wave_reviewed=reviewed_page_owner_overlay["reviewed_overlay"]["reviewed_pages"],
    page_review_owner=reviewed_page_owner_overlay["reviewed_overlay"]["owner_pages"],
    page_review_shared=reviewed_page_owner_overlay["reviewed_overlay"]["shared_relations"],
    page_review_gap=reviewed_page_owner_overlay["reviewed_overlay"]["evidence_gaps"],
    route_wave_reviewed=reviewed_name_only_route_action_overlay["reviewed_overlay"]["reviewed_route_actions"],
    route_review_owner=reviewed_name_only_route_action_overlay["reviewed_overlay"]["owner_route_actions"],
    route_review_shared=reviewed_name_only_route_action_overlay["reviewed_overlay"]["shared_relations"],
    route_review_alias=reviewed_name_only_route_action_overlay["reviewed_overlay"]["alias_or_redirect"],
    route_review_dead=reviewed_name_only_route_action_overlay["reviewed_overlay"]["dead_or_noncanonical"],
    route_review_gap=reviewed_name_only_route_action_overlay["reviewed_overlay"]["evidence_gaps"],
    page_context_calls=reviewed_name_only_route_action_overlay["page_context_boundary"]["literal_callsites"],
    page_context_owned=reviewed_name_only_route_action_overlay["page_context_boundary"]["currently_owned_page_callsites"],
    page_context_gaps=reviewed_name_only_route_action_overlay["page_context_boundary"]["current_page_evidence_gap_callsites"],
    page_context_authorized=reviewed_name_only_route_action_overlay["page_context_boundary"]["page_ownership_authorized"],
    queue_records=reviewed_name_only_route_action_overlay["queue_accounting"]["direct_exact_queue_records"],
    queue_reviewed=reviewed_name_only_route_action_overlay["queue_accounting"]["reviewed_queue_surface_rows"],
    queue_pending=reviewed_name_only_route_action_overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"],
    queue_without_owner=reviewed_name_only_route_action_overlay["queue_accounting"]["queue_surfaces_without_ownership"],
    queue_owner=reviewed_name_only_route_action_overlay["queue_accounting"]["owner_queue_surface_rows"],
    queue_shared=reviewed_name_only_route_action_overlay["queue_accounting"]["shared_queue_surface_rows"],
    queue_alias=reviewed_name_only_route_action_overlay["queue_accounting"]["alias_queue_surface_rows"],
"""
    text = replace_between(
        text,
        '    static_owner_records=reviewed_page_owner_overlay["combined_counts"]["source_owner_records"],',
        "    finding_count=len(findings),",
        mapping_block,
        "Wave 16 dashboard substitutions",
    )

    text = text.replace("RUN-071–111", "RUN-071–115").replace("RUN-077–111", "RUN-077–115")
    simple_replacements = [
        ('<a href="#checkpoint">RUN-111</a>', '<a href="#checkpoint">RUN-115</a>', "nav"),
        ('RUN-001 through RUN-111 are represented by audit artifacts;', 'RUN-001 through RUN-115 are represented by audit artifacts;', "wave range"),
        ('Generated deterministically from independently reviewed static evidence through RUN-110/R and reported in RUN-111.', 'Generated deterministically from independently reviewed static evidence through RUN-114/R and reported in RUN-115.', "footer"),
        ('f".{output_path.name}.tmp-run107-dashboard"', 'f".{output_path.name}.tmp-run115-dashboard"', "temporary dashboard path"),
    ]
    for old, new, label in simple_replacements:
        text = replace_once_or_present(text, old, new, label)

    replacements = [
        (
            'RUN-101/R–108 remain historical route/action and page-owner checkpoints with exact dashboard receipts. RUN-109/R review $page_wave_reviewed page roots as $page_review_owner owners and $page_review_shared shared relations with zero new gap; RUN-110/R independently establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges, with two new page owners, zero route/bridge additions, four preserved shared pages, and one queue row reconciled as reviewed shared.',
            'RUN-101/R–112 remain historical route/action and page-owner checkpoints with exact dashboard receipts. RUN-113/R review $route_wave_reviewed name-only route/actions as $route_review_owner owners, $route_review_alias alias, $route_review_shared shared, $route_review_dead dead, and $route_review_gap gap; RUN-114/R establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges, with 23 new route owners, 23 new bridges, one preserved alias, and zero page additions. Seven page contexts remain observation only: $page_context_owned already owned and $page_context_gaps gaps, with $page_context_authorized page credit.',
            "primary notice",
        ),
        (
            'RUN-101/R–108 remain historical route/action and page-owner checkpoints with exact dashboard receipts. RUN-109/R–110/R add two reviewed page owners, preserve four shared pages, and add zero route owners or action bridges, raising bounded ownership to $static_owner_records records while $static_action_bridges action bridges remain unchanged; one queue page becomes reviewed shared, leaving $queue_pending pending and $queue_without_owner without ownership. RUN-111 reports only that bounded delta.',
            'RUN-101/R–112 remain historical route/action and page-owner checkpoints with exact dashboard receipts. RUN-113/R–114/R add $route_review_owner reviewed route owners and 23 controller-action bridges, preserve $route_review_alias reviewed alias, and add zero page owners, raising bounded ownership to $static_owner_records records and $static_action_bridges bridges; $queue_reviewed queue rows are reviewed, $queue_pending remain pending, and $queue_without_owner remain without ownership. RUN-115 reports only that bounded delta.',
            "checkpoint notice",
        ),
        (
            'RUN-097/R–108 preserve the historical route/action and page-owner checkpoints with dashboard verification, RUN-109/R–110/R independently review and integrate two page owners while retaining four shared pages and reconciling one queue page as shared, and RUN-111 refreshes current reporting.',
            'RUN-097/R–112 preserve the historical route/action and page-owner checkpoints with dashboard verification, RUN-113/R–114/R independently review and integrate 23 route owners plus 23 bridges while preserving one alias and adding zero pages, and RUN-115 refreshes current reporting.',
            "checkpoint narrative",
        ),
        (
            '<tr><td>RUN-109/R → 110/R current page render-owner tail overlay</td><td><strong>$page_wave_reviewed reviewed · $page_review_owner owner pages · $page_review_shared shared · $page_review_gap evidence gap · 2 page rows · 0 route/bridge rows · 1 queue-shared row</strong></td><td class="partial">$static_owner_records cumulative owners · $static_owner_routes routes + $static_owner_pages pages · $static_owner_features FEATURE-IDs · Gate 4 incomplete</td></tr><tr><td>RUN-111 reporting refresh</td><td><strong>page-owner tail overlay reported</strong></td><td class="partial">audit-only materialization · matrix byte-identical · fresh RUN-112 verification required</td></tr>',
            '<tr><td>RUN-109/R → 112 historical page render-owner tail</td><td><strong>6 reviewed · 2 owner pages · 4 shared · 0 evidence gap · 2 page rows · 0 route/bridge rows · 1 queue-shared row</strong></td><td class="partial">614 cumulative owners · 265 routes + 349 pages · 256 FEATURE-IDs · exact superseded dashboard verified</td></tr><tr><td>RUN-113/R → 114/R current name-only route/action overlay</td><td><strong>$route_wave_reviewed reviewed = $route_review_owner owner + $route_review_alias alias + $route_review_shared shared + $route_review_dead dead + $route_review_gap gap · 23 route rows · 23 action bridges · 0 page rows</strong></td><td class="partial">$static_owner_records cumulative owners · $static_owner_routes routes + $static_owner_pages pages · $static_owner_features FEATURE-IDs · Gate 4 incomplete</td></tr><tr><td>RUN-115 reporting refresh</td><td><strong>name-only route/action overlay reported</strong></td><td class="partial">audit-only materialization · matrix byte-identical · fresh RUN-116 verification required</td></tr>',
            "checkpoint Wave 16 rows",
        ),
        (
            '<li>RUN-109/R: 6 page-tail candidates · 2 owners · 4 shared · 0 new gap · 0 route/bridge credit</li><li>RUN-110/R: 2 page rows integrated and independently verified · four shared non-owners preserved · one queue page reviewed shared · $static_owner_records cumulative owner records</li><li>RUN-111: deterministic page-tail reporting refresh · matrix and every Site/permission/privacy/direct-object/lifecycle/execution/benchmark/Pass/finding/completion boundary unchanged</li>',
            '<li>RUN-109/R–112: historical 6-page tail · 2 owners · 4 shared · reporting and exact superseded dashboard verification</li><li>RUN-113/R: $route_wave_reviewed route/action candidates · $route_review_owner owners · $route_review_alias alias · $route_review_shared shared · $route_review_dead dead · $route_review_gap gap · 0 page credit</li><li>RUN-114/R: 23 route rows + 23 action bridges integrated and independently verified · one alias non-owner preserved · page calls $page_context_calls = $page_context_owned already-owned + $page_context_gaps gaps + $page_context_authorized credit · $static_owner_records cumulative owner records</li><li>RUN-115: deterministic name-only route/action reporting refresh · matrix and every Site/permission/privacy/direct-object/lifecycle/concurrency/execution/benchmark/Pass/finding/completion boundary unchanged</li>',
            "evidence wave list",
        ),
        (
            'RUN-097/R–108 preserve the historical route/action and page-owner checkpoints, RUN-109/R–110/R add two independently reviewed page owners, preserve four shared pages, and reconcile one queue page as reviewed shared, and RUN-111 refreshes reporting.',
            'RUN-097/R–112 preserve the historical route/action and page-owner checkpoints, RUN-113/R–114/R add 23 independently reviewed route owners and 23 bridges, preserve one alias, add zero page owners, and RUN-115 refreshes reporting.',
            "static census intro",
        ),
        (
            '<tr><td>RUN-110/R current outcome-neutral page-tail ownership</td><td>$static_owner_records records · $static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs · $static_action_bridges action bridges</td><td class="partial">$ownership_percent% of bounded 3,929 records · $static_residual residual · $page_review_owner owner / $page_review_shared shared / $page_review_gap gap page-tail delta · pages $static_owner_pages owner + $page_shared shared + $page_residual residual · one queue page reviewed shared · Gate 4 incomplete · matrix unchanged</td></tr>',
            '<tr><td>RUN-110/R historical outcome-neutral page-tail ownership</td><td>614 records · 265 route + 349 page · 256 FEATURE-IDs · 53 action bridges</td><td class="partial">15.627386% · 3,315 residual · historical bounded checkpoint · exact RUN-112 dashboard verification</td></tr><tr><td>RUN-114/R current name-only route/action ownership</td><td>$static_owner_records = $static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs = $static_owner_h_features H + $static_owner_d_features D · $static_action_bridges action bridges</td><td class="partial">$ownership_percent% of bounded 3,929 · $static_residual residual · features $route_feature_ids route / $page_feature_ids page / $route_page_overlap overlap · routes 3,218 = $static_owner_routes owner + $route_shared_current shared + $route_alias_current alias + $route_residual residual · pages 711 = $static_owner_pages owner + $page_shared shared + $page_residual residual with $page_gap tagged gap · page calls $page_context_calls = $page_context_owned already-owned + $page_context_gaps gaps + $page_context_authorized page credit · Gate 4 incomplete · matrix unchanged</td></tr>',
            "static census current overlay row",
        ),
        (
            '<tr><td>RUN-090 direct-exact queue</td><td>$queue_records total · current overlay: $queue_owner owned · $queue_shared shared · $queue_alias alias · $queue_pending unreviewed · $queue_without_owner without ownership</td><td class="partial">candidate prioritisation only · queue itself grants no wholesale ownership</td></tr>',
            '<tr><td>RUN-090 direct-exact queue</td><td>$queue_records total = $queue_reviewed reviewed + $queue_pending pending · reviewed = $queue_owner owned + $queue_shared shared + $queue_alias alias · $queue_without_owner without ownership</td><td class="partial">candidate prioritisation only · queue itself grants no wholesale ownership</td></tr>',
            "current queue row",
        ),
        (
            'RUN-110/R establish $static_owner_records bounded source-owner records and retain $static_action_bridges action bridges while adding two page owners, preserving four newly reviewed shared pages, and retaining one earlier evidence gap inside the page residual; complete the framework-expanded canonical route/page denominator, $static_residual non-owner records including 5 shared routes, 3 alias routes, $page_shared shared pages, and $page_gap tagged page gap within $page_residual residual pages, the full crosswalk, and route reachability before Gate 4 can close',
            'RUN-114/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges while adding 23 route owners and 23 bridges, preserving one alias non-owner, and adding zero page credit; complete the framework-expanded canonical route/page denominator, $static_residual non-owner records including $route_shared_current shared routes, $route_alias_current alias routes, and $route_residual residual routes plus $page_shared shared pages and $page_gap tagged gap within $page_residual residual pages, the full crosswalk, and route reachability before Gate 4 can close',
            "Gate 4 current overlay",
        ),
        (
            'RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, RUN-094, RUN-100, RUN-104, and RUN-108 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-111.',
            'RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, RUN-094, RUN-100, RUN-104, RUN-108, and RUN-112 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-115.',
            "prior dashboard verification paragraph",
        ),
        (
            '<li><a href="evidence/browser/current-audit-dashboard-verification-run-108-wave-14.json">Superseded RUN-108 verification GO</a></li>',
            '<li><a href="evidence/browser/current-audit-dashboard-verification-run-108-wave-14.json">Superseded RUN-108 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-112-wave-15.json">Superseded RUN-112 verification GO</a></li>',
            "RUN-112 prior verification link",
        ),
        (
            '<section class="panel"><h2>Fresh RUN-112 audit-dashboard verification</h2><p>The exact regenerated RUN-111 dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-112 receipt records page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 614/265/349 ownership, 2-owner/4-shared page-tail outcomes, 349/9/353 page conservation, 60-reviewed/447-pending queue accounting, 53 bridges, and all zero-credit boundaries. It verifies the audit artifact only and grants no application-browser, responsive, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-112-wave-15.json">RUN-112 responsive audit-dashboard verification receipt</a></li></ul></section>',
            '<section class="panel"><h2>Fresh RUN-116 audit-dashboard verification</h2><p>The exact regenerated RUN-115 dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-116 receipt must record page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 637/288/349 ownership, 24=23-owner+1-alias route/action outcomes, 61/242/47 route/page/overlap feature sets, 76 bridges, route 3,218=288+5+4+2,921, page 711=349+9+353, queue 507=84+423 with 84=77+3+4 and 430 without ownership, page calls 7=3 already-owned+4 gaps+0 page credit, 3,292 residual records, one operating organisation across multiple Sites, Gate 4 open, mapping 0/340, and all zero-credit boundaries. It verifies the audit artifact only and grants no application-browser, responsive-application, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-116-wave-16.json">RUN-116 responsive audit-dashboard verification receipt</a></li></ul></section>',
            "fresh dashboard verification",
        ),
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
    assert sha256_file("03-feature-to-benchmark-matrix.csv") == MATRIX_SHA256
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
        "schema_version": "run-115-reviewed-name-only-route-action-reporting-wave-16-v1",
        "run_id": "RUN-115-REVIEWED-NAME-ONLY-ROUTE-ACTION-REPORTING-WAVE-16",
        "status": "REVIEWED_NAME_ONLY_ROUTE_ACTION_OVERLAY_REPORTED_GATE_4_INCOMPLETE",
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
            "materializer_sha256": sha256_file("generators/materialize-run-115-reviewed-name-only-route-action-reporting-wave-16.py"),
            "overlay_sha256": PINNED_INPUTS["evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json"],
            "independent_overlay_review_sha256": PINNED_INPUTS["evidence/source/raw-run-114r-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json"],
            "superseded_dashboard_verification_sha256": PINNED_INPUTS["evidence/browser/current-audit-dashboard-verification-run-112-wave-15.json"],
        },
        "inputs": {**CURRENT_REPORT_INPUTS, **PINNED_INPUTS},
        "outputs": outputs,
        "preserved": PRESERVED,
        "counts": {
            **overlay["combined_counts"],
            **overlay["queue_accounting"],
            "reviewed_route_actions": 24,
            "reviewed_owner_route_actions_added": 23,
            "reviewed_alias_routes_added": 1,
            "controller_action_bridges_added": 23,
            "page_owner_records_added": 0,
            "page_context_callsites": 7,
            "already_owned_page_context_callsites": 3,
            "unowned_page_context_callsites": 4,
            "provisional_finding_records": 12,
            "final_findings": 0,
            "matrix_rows_changed": 0,
            "matrix_cells_changed": 0,
        },
        "checks": {
            "run_113r_review_go": True,
            "run_113r_owner_route_actions": 23,
            "run_113r_alias_or_redirect": 1,
            "run_114r_overlay_review_go": True,
            "independent_review_mechanical_checks": 269,
            "independent_review_discrepancies": 0,
            "route_owner_records_added": 23,
            "controller_action_bridges_added": 23,
            "page_owner_records_added": 0,
            "reviewed_non_owner_records_preserved": 1,
            "identity_fields_verified": 38,
            "matrix_byte_identical": True,
            "provisional_finding_record_semantics_preserved": True,
            "application_source_paths_written": 0,
            "one_organisation_multi_site_architecture_preserved": True,
            "dashboard_requires_fresh_run_116_artifact_verification": True,
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
        "action_bridges": receipt["counts"]["static_controller_action_bridges"],
        "reviewed_queue_rows": receipt["counts"]["reviewed_queue_surface_rows"],
        "gate_4_complete": receipt["checks"]["gate_4_complete"],
    }, indent=2))


if __name__ == "__main__":
    main()
