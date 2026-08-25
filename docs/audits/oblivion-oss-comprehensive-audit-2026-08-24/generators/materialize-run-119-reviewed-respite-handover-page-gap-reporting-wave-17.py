#!/usr/bin/env python3
"""Promote the independently reviewed RUN-118 page-owner overlay into reports.

Only five current reporting surfaces are updated. Reports 02-12, inventory,
the 340-row matrix, and all application source remain byte-identical. The
dashboard generated from the updated template requires a fresh RUN-120
artifact-only browser receipt.
"""

from __future__ import annotations

import hashlib
import json
import os
import subprocess
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
MATERIALIZER_RELATIVE = "generators/materialize-run-119-reviewed-respite-handover-page-gap-reporting-wave-17.py"
OUTPUT_RELATIVE = "evidence/source/current-run-119-reviewed-respite-handover-page-gap-reporting-wave-17.json"
SCHEMA_VERSION = "run-119-reviewed-respite-handover-page-gap-reporting-wave-17-v1"
RUN_ID = "RUN-119-REVIEWED-RESPITE-HANDOVER-PAGE-GAP-REPORTING-WAVE-17"

# These receipts are the two exact development predecessors that can authorize
# one transition to the current materializer. Later reruns must be self-pinned.
KNOWN_PREDECESSOR_RECEIPT_SHA256S = {
    "f4c3e8fcdbe581e4f4906dc9eb6543b3cf6458c96240f432e3720fb15ebc051b",
    "a20eeab6adef15c78279ebd1f6ebeffc86399b808bb45c47927c5980c5ce1963",
}

CHECKPOINT_COMMIT = "67e1ccc9ce95b6ce286a683b0f7aa48bb5940fc1"
CHECKPOINT_TREE = "a85b972cb88aa89726a9cd640692e2c914acc3b4"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
RECORDS_SHA256 = "118c1a1f19b2e300c77d5b4d71c60f75b7038382f3c71e18f078367f8473e260"

CURRENT_REPORT_INPUTS = {
    "00-executive-summary.md": "d2d9eafb159d2e151bdf8acf956e2784b195a934cc7d103c97af6b53d9a365d5",
    "01-repository-module-map.md": "3819efff09ebc26f1d04bf345557a761d2a8e3fabcdd52e2b278db8d32d85307",
    "13-unresolved-questions-and-evidence-gaps.md": "e7a59071c83701f1d1729720995f1bde73da9b404451cffafd78458c7b30a509",
    "findings.json": "5c2d0932b48242a4e692170051da0dd20e92499c73272d30ba760873b98bee91",
    "generators/build-current-audit-dashboard.py": "14c82e1bf1473f59e023199fb31ede6a72cffbb154a6502d05c2dc1852994294",
}

PINNED_INPUTS = {
    "evidence/source/current-run-115-reviewed-name-only-route-action-reporting-wave-16.json": "60787aa5f9cac19e58751528f92fe08dbc5068d63567caba3a3eacd57a661ab7",
    "evidence/browser/current-audit-dashboard-verification-run-116-wave-16.json": "90ec8ab20cb9bf8d1e1509db614f941ad5337033973d754445ab6c88b2f13bf8",
    "generators/build-outcome-neutral-respite-handover-page-gap-cohort-wave-17.py": "85068c7a0170e155b3f5e41b87c91d27c7a45f3e2a117ea2444af91eb45a4374",
    "evidence/source/root-run-117-outcome-neutral-respite-handover-page-gap-cohort-wave-17.json": "e468e7e7736e49eea629b4faec1fdce94d7de30eee478b08c81b90793622bd2e",
    "generators/materialize-independent-outcome-neutral-respite-handover-page-gap-review-wave-17.py": "717803e612e94ccc0af3e356050a7e72353d2fc7b31dfd2ab00e30b51af8e11f",
    "evidence/source/raw-run-117r-independent-outcome-neutral-respite-handover-page-gap-review-wave-17.json": "264236eccceb279522fb784a7c27db2ecc8fd0434e4e5668c33fbe263f1cbc9b",
    "generators/integrate-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.py": "990d6bbf4879cbaf10e6b4031f640be6bcef346b7e9685e3d3c7da2d846271fb",
    "evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json": "33dd2f8ffa2b35c6651a6bf18923872e70ba5c2f91fce8b7222666bb8a91fc8b",
    "generators/materialize-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-review-wave-17.py": "9c8a23e77504ebef3d648b5bd0e894d4c95a8065f04748091b9f33e4aec4fa88",
    "evidence/source/raw-run-118r-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json": "043d57357e3ff1ede8f0effacdb71e4d802d98d53d555ab39316bce33fe06a2d",
}

PRESERVED_PATHS = [
    "02-eight-pass-coverage-ledger.csv",
    "03-feature-to-benchmark-matrix.csv",
    "04-workflow-usability-scorecard.csv",
    "05-browser-visual-coverage-matrix.csv",
    "06-open-source-benchmark-register.csv",
    "07-module-findings.md",
    "08-cross-module-journeys.md",
    "09-ui-ux-accessibility-visual-consistency.md",
    "10-architecture-data-integration-security.md",
    "11-prioritised-roadmap.md",
    "12-native-build-and-do-not-copy-register.md",
    "inventory.json",
]


def path(relative: str) -> Path:
    return AUDIT_DIR / relative


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(relative: str) -> str:
    return sha256_bytes(path(relative).read_bytes())


def canonical_json_sha256(value: Any) -> str:
    raw = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return sha256_bytes(raw.encode("utf-8"))


def read_json(relative: str) -> dict[str, Any]:
    value = json.loads(path(relative).read_text(encoding="utf-8"))
    assert isinstance(value, dict), relative
    return value


def git(*args: str) -> str:
    completed = subprocess.run(
        ["git", *args], cwd=REPO, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True
    )
    return completed.stdout.strip()


def write_lf(relative: str, text: str) -> None:
    encoded = text.replace("\r\n", "\n").encode("utf-8")
    target = path(relative)
    if target.read_bytes() != encoded:
        target.write_bytes(encoded)


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if new in text:
        return text
    count = text.count(old)
    assert count == 1, (label, count)
    return text.replace(old, new, 1)


def replace_between(text: str, start: str, end: str, replacement: str, label: str) -> str:
    if replacement in text:
        return text
    start_index = text.find(start)
    assert start_index >= 0, (label, "missing start")
    end_index = text.find(end, start_index)
    assert end_index >= 0, (label, "missing end")
    return text[:start_index] + replacement + text[end_index:]


def replace_line_containing(text: str, marker: str, replacement: str) -> str:
    lines = text.splitlines()
    matches = [index for index, line in enumerate(lines) if marker in line]
    assert len(matches) == 1, (marker, len(matches))
    lines[matches[0]] = replacement
    return "\n".join(lines) + "\n"


def assert_inputs() -> tuple[dict[str, Any], dict[str, Any], dict[str, str]]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("status", "--porcelain", "--", "app", "routes", "resources/js") == ""
    existing_receipt = None
    if path(OUTPUT_RELATIVE).exists():
        candidate_receipt = read_json(OUTPUT_RELATIVE)
        candidate_hash = sha256_file(OUTPUT_RELATIVE)
        assert candidate_receipt["schema_version"] == SCHEMA_VERSION
        assert candidate_receipt["run_id"] == RUN_ID
        assert candidate_receipt["pins"]["checkpoint_commit"] == CHECKPOINT_COMMIT
        assert candidate_receipt["pins"]["checkpoint_tree"] == CHECKPOINT_TREE
        assert candidate_receipt["inputs"] == {**CURRENT_REPORT_INPUTS, **PINNED_INPUTS}
        current_materializer_sha256 = sha256_file(MATERIALIZER_RELATIVE)
        assert (
            candidate_hash in KNOWN_PREDECESSOR_RECEIPT_SHA256S
            or candidate_receipt["pins"]["materializer_sha256"] == current_materializer_sha256
        ), (candidate_hash, candidate_receipt["pins"]["materializer_sha256"], current_materializer_sha256)
        existing_receipt = candidate_receipt
    for relative, expected in CURRENT_REPORT_INPUTS.items():
        actual = sha256_file(relative)
        allowed = {expected}
        if existing_receipt is not None:
            allowed.add(existing_receipt["outputs"][relative])
        assert actual in allowed, (relative, actual, sorted(allowed))
    for relative, expected in PINNED_INPUTS.items():
        assert sha256_file(relative) == expected, relative
    preserved = {relative: sha256_file(relative) for relative in PRESERVED_PATHS}
    assert preserved["03-feature-to-benchmark-matrix.csv"] == MATRIX_SHA256
    overlay = read_json("evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json")
    review = read_json("evidence/source/raw-run-118r-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json")
    assert overlay["combined_counts"]["source_owner_records"] == 641
    assert overlay["combined_counts"]["route_owner_records"] == 288
    assert overlay["combined_counts"]["page_owner_records"] == 353
    assert overlay["queue_accounting"]["reviewed_queue_surface_rows"] == 84
    assert review["decision"]["verdict"] == "GO"
    assert review["decision"]["reporting_promotion_authorized"] is True
    assert review["decision"]["gate_4_complete"] is False
    return overlay, review, preserved


def patch_reports() -> None:
    summary_relative = "00-executive-summary.md"
    summary = path(summary_relative).read_text(encoding="utf-8")
    summary_block = """## RUN-113–119 reviewed route/action and respite handover page-gap overlays

RUN-113/R review 24 still-pending RUN-090 name-only route/action rows as 23 explicit route/action owners and one Fleet incident create redirect. RUN-114/R integrate and independently verify those 23 route owners plus 23 controller-action bridges, with zero page ownership. RUN-115 reports that bounded delta, and RUN-116 verifies the exact now-superseded dashboard at four viewports with audit-artifact credit only.

RUN-117 freezes the four Respite handover page contexts left open by Wave 16. Three fresh partition reviewers independently read the complete page/controller/route context and classify all four as `OWNER_PAGE` for the already represented `CAP-RESP-HANDOVER-NOTES` feature. Ownership is based on the pages' own complete semantics and is not inherited from their accepted parent routes. The reviews preserve explicit unresolved Site scope, permission, sensitive-draft/privacy, and direct-object-concealment risks with zero readiness credit.

RUN-118 integrates only those four explicit page owners. RUN-118R independently verifies exact bytes, all 641 unique owner keys and IDs, the four candidate/decision/overlay joins, parent route/bridge membership, unchanged queue accounting, conservation, and every downstream boundary with zero discrepancies.

The current bounded checkpoint is **641 records = 288 routes + 353 pages across 256 canonical FEATURE-IDs (234 H + 22 D)**, with 76 controller-action bridges. Route and page owners span 61 and 242 FEATURE-IDs with 47 in their overlap. This is 16.314584% of the bounded 3,929-record source universe; 3,288 records remain. The page universe is **711 = 353 owners + 9 shared + 349 residual**, with the earlier tagged evidence gap inside that 349. The route universe remains **3,218 = 288 owners + 5 shared + 4 aliases + 2,921 residual**. Queue accounting is unchanged at **507 = 84 reviewed + 423 pending**; the reviewed set is 77 owned, three shared, and four aliases, while 430 remain without ownership.

RUN-119 reports only that bounded four-page delta. Oblivion Findings remains one operating organisation across multiple Sites. Framework reachability, navigation, Site access, roles/permissions, canonical object ownership, direct-object concealment, privacy, lifecycle, concurrency, runtime, database, build, application browser, tests, benchmarks, ease, Passes, findings, completion, and Gate 4 remain separate open or zero-credit gates. The 340-row matrix remains byte-identical and mapping remains 0/340.

"""
    summary = replace_between(
        summary,
        "## RUN-113–115 reviewed outcome-neutral name-only route/action overlay\n",
        "## Current raw source census\n",
        summary_block,
        "summary Wave 17 block",
    )
    evidence_marker = "- `generators/materialize-run-115-reviewed-name-only-route-action-reporting-wave-16.py` and `evidence/source/current-run-115-reviewed-name-only-route-action-reporting-wave-16.json`: deterministic RUN-115 reporting refresh preserving the matrix, reports 02–12/inventory, and every downstream zero-credit boundary.\n"
    evidence_addition = evidence_marker + (
        "- `evidence/browser/current-audit-dashboard-verification-run-116-wave-16.json`: exact now-superseded RUN-115 dashboard artifact verification at four viewports; zero application credit.\n"
        "- `generators/build-outcome-neutral-respite-handover-page-gap-cohort-wave-17.py` and `evidence/source/root-run-117-outcome-neutral-respite-handover-page-gap-cohort-wave-17.json`: exact zero-credit four-page review cohort.\n"
        "- `generators/materialize-independent-outcome-neutral-respite-handover-page-gap-review-wave-17.py` and `evidence/source/raw-run-117r-independent-outcome-neutral-respite-handover-page-gap-review-wave-17.json`: fresh three-part four-owner page review with Site/privacy/permission risks retained.\n"
        "- `generators/integrate-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.py` and `evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json`: exact four-page owner-only overlay with unchanged route, bridge, feature, and queue counts.\n"
        "- `generators/materialize-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-review-wave-17.py` and `evidence/source/raw-run-118r-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json`: independent final-byte, 641-record identity, accounting, and boundary GO receipt.\n"
        "- `generators/materialize-run-119-reviewed-respite-handover-page-gap-reporting-wave-17.py` and `evidence/source/current-run-119-reviewed-respite-handover-page-gap-reporting-wave-17.json`: deterministic RUN-119 reporting refresh preserving the matrix, reports 02–12/inventory, and every downstream zero-credit boundary.\n"
    )
    summary = replace_once(summary, evidence_marker, evidence_addition, "summary evidence links")
    write_lf(summary_relative, summary)

    module_relative = "01-repository-module-map.md"
    module_map = path(module_relative).read_text(encoding="utf-8")
    module_block = """## RUN-113–119 reviewed route/action and respite handover page-gap overlays

RUN-113/R freeze and review 24 name-only route actions as 23 explicit owners and one alias. RUN-114/R integrate 23 route owners and 23 controller-action bridges with zero page ownership; RUN-115 reports that result and RUN-116 verifies the exact superseded dashboard.

RUN-117/R separately review the four Respite handover page gaps left by Wave 16. All four are explicit `OWNER_PAGE` decisions for `CAP-RESP-HANDOVER-NOTES`, based on complete page semantics rather than inherited route ownership. RUN-118/R integrate and independently verify exactly four page owners, zero route owners, zero bridges, and zero queue changes.

The cumulative bounded ledger is 641 source owners (288 route + 353 page) across 256 FEATURE-IDs (234 H + 22 D). Route/page feature sets remain 61/242 with overlap 47, and the action-bridge count remains 76. Route accounting is 3,218 = 288 owners + 5 shared + 4 aliases + 2,921 residual. Page accounting is 711 = 353 owners + 9 shared + 349 residual. RUN-090 queue accounting remains 507 total, 84 reviewed, 77 owned, three shared, four aliases, 423 pending, and 430 without ownership.

RUN-118R verifies exact final bytes, the complete 641-record owner ledger, parent route/bridge provenance, unchanged queue accounting, and all semantic boundaries with zero discrepancies. These relations establish bounded static page ownership only; they do not establish framework reachability, Site or permission correctness, canonical direct-object concealment, sensitive-data privacy, lifecycle, concurrency, runtime, build, browser, tests, benchmarks, findings, Passes, or completion.

"""
    module_map = replace_between(
        module_map,
        "## RUN-113–114 reviewed outcome-neutral name-only route/action overlay\n",
        "## Candidate register\n",
        module_block,
        "module Wave 17 block",
    )
    write_lf(module_relative, module_map)

    gaps_relative = "13-unresolved-questions-and-evidence-gaps.md"
    gaps = path(gaps_relative).read_text(encoding="utf-8")
    gaps = replace_once(
        gaps,
        "## RUN-077–115 route/page, page-tree, backend, ownership and reporting lineage",
        "## RUN-077–119 route/page, page-tree, backend, ownership and reporting lineage",
        "gaps RUN119 lineage heading",
    )
    rows = {
        "| Required reporting paths |": "| Required reporting paths | 18 / 18 prompt-required files or directories present. RUN-116 independently verified the exact now-superseded RUN-115 dashboard at four viewports; the regenerated RUN-119 dashboard requires a separate fresh RUN-120 artifact receipt. | Presence and prior audit-artifact verification make the reporting contract inspectable but grant no application execution, final-finding, Pass, or completion credit. Gate 26 remains open. | Complete the semantic, execution, benchmark, Pass 8, final reconciliation, and no-live-agent gates; repeat audit-dashboard verification after every later HTML change. |",
        "| Runtime routes |": "| Runtime routes | RUN-114/R establish 288 bounded route-owner records and 76 static controller-action bridges; 2,921 residual explicit-unmapped route rows, five semantic-shared route rows, and four reviewed alias rows remain distinguished within the bounded 3,218-row static route-like universe. | RUN-117–118 add zero route or bridge records and do not change RUN-090 queue accounting. Static owner/action linkage is not a framework-expanded route table, reachability proof, or authorization proof. | Under a fresh bounded runtime grant, use an exact disposable database, execute a separately pinned framework/provider route lane, and reconcile it to all 38 source route files and 3,218 route-like rows. |",
        "| Inertia pages |": "| Inertia pages | RUN-084/R enumerate 1,058 physical page-tree files. RUN-118/R establish 353 bounded page owners, nine semantic-shared roots, and 349 residual roots including one earlier tagged evidence gap. | Wave 17 adds exactly four independently reviewed Respite handover page owners; none inherits ownership from its parent route. Full-tree structural GO and bounded ownership are not a complete canonical crosswalk, runtime reachability, build resolution, rendered browser behaviour, or final feature mapping. | Reconcile all 711 literal roots and support relations to safely expanded framework routes and frozen FEATURE-IDs, retain shared and gap relations explicitly, prove build resolution, and observe every safely reachable current-build page under approved roles/Sites. |",
        "| Canonical features |": "| Canonical features | Static canonical identity remains 340 targets: 300 H, 40 D, zero M; RUN-118/R establish 641 bounded source-owner records (288 routes + 353 pages) across 256 FEATURE-IDs (234 H + 22 D) plus 76 controller-action bridges while the matrix remains byte-identical at `dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390`. | This is 16.314584% of the bounded 3,929-record source universe. Gate 4 remains incomplete: 3,288 non-owner records, the framework-expanded denominator, shared, alias, and gap relations, reachability, and the full crosswalk remain open; `CAP-RESP-HANDOVER-NOTES` was already represented globally and in the page set, and matrix target mapping stays 0/340. | Finish canonical denominator and residual ownership adjudication without inheriting FEATURE-IDs from prefixes, imports, containment, names, or presence, and without awarding runtime, browser, test, benchmark, ease, release, or completion credit. |",
        "| Agent universe and writer rule |": "| Agent universe and writer rule | RUN-001 through RUN-119 represented at the current reporting checkpoint; finalization gate false. | RUN-117/R review four page gaps as four explicit owners; RUN-118/R independently integrate and verify four page owners with zero route, bridge, queue, or downstream credit; RUN-119 reports only those bounded classes. Runtime, browser, tests, benchmarks, Pass 8, finalization, and completion remain open. | Complete residual ownership and every semantic/execution gate, then dispatch fresh Pass 8/final cross-reviewers, verify the final dashboard, and prove no agent remains live at finalization. |",
    }
    for marker, replacement in rows.items():
        gaps = replace_line_containing(gaps, marker, replacement)
    lineage = "RUN-077 freezes the exhaustive committed-source route/name/page universe through RUN-084B's page-tree and backend structural ledgers. RUN-086/R establish the initial 530 bounded source owners; RUN-090 freezes the zero-credit direct-exact queue; RUN-091/R–103 successively add and report reviewed closed-chain and route/action ownership while preserving shared and alias outcomes, reaching 592 owners. RUN-104 verifies that superseded dashboard. RUN-105/R–107 review, integrate, and report 20 page owners, three shared relations, and one evidence gap, reaching 612 owners; RUN-108 verifies that superseded dashboard. RUN-109/R review the six-page tail as two owners and four shared relations; RUN-110/R integrate and independently verify two page owners and one reviewed-shared queue reconciliation, reaching 614 owners; RUN-111 reports that delta and RUN-112 verifies its superseded dashboard. RUN-113/R review 24 name-only route actions as 23 owners and one alias; RUN-114/R integrate and verify 23 route owners plus 23 bridges, reaching 637 owners; RUN-115 reports that delta and RUN-116 verifies its now-superseded dashboard. RUN-117/R review four Respite handover page gaps as four explicit owners. RUN-118/R integrate and independently verify those four page owners, reaching 641 owners while route, bridge, feature, and queue counts remain unchanged; RUN-119 reports only that bounded delta. Gate 4 and the full route/page/backend crosswalk remain incomplete; Oblivion Findings remains one operating organisation across multiple Sites, and framework reachability, Site/permission/privacy/direct-object/lifecycle/concurrency correctness, runtime, build, signed-in application browser, executed tests, benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit."
    gaps = replace_line_containing(
        gaps,
        "RUN-077 freezes the exhaustive committed-source route/name/page universe",
        lineage,
    )
    write_lf(gaps_relative, gaps)


def patch_findings(overlay: dict[str, Any], review: dict[str, Any]) -> None:
    relative = "findings.json"
    findings = read_json(relative)
    assert canonical_json_sha256(findings["records"]) == RECORDS_SHA256
    findings["generated_on"] = "2026-08-26"
    findings["pins"].update({
        "run_116_dashboard_verification_sha256": PINNED_INPUTS["evidence/browser/current-audit-dashboard-verification-run-116-wave-16.json"],
        "run_117_page_gap_cohort_generator_sha256": PINNED_INPUTS["generators/build-outcome-neutral-respite-handover-page-gap-cohort-wave-17.py"],
        "run_117_page_gap_cohort_sha256": PINNED_INPUTS["evidence/source/root-run-117-outcome-neutral-respite-handover-page-gap-cohort-wave-17.json"],
        "run_117r_page_gap_review_materializer_sha256": PINNED_INPUTS["generators/materialize-independent-outcome-neutral-respite-handover-page-gap-review-wave-17.py"],
        "run_117r_page_gap_review_sha256": PINNED_INPUTS["evidence/source/raw-run-117r-independent-outcome-neutral-respite-handover-page-gap-review-wave-17.json"],
        "run_118_page_gap_overlay_generator_sha256": PINNED_INPUTS["generators/integrate-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.py"],
        "run_118_page_gap_overlay_sha256": PINNED_INPUTS["evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json"],
        "run_118r_page_gap_overlay_review_materializer_sha256": PINNED_INPUTS["generators/materialize-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-review-wave-17.py"],
        "run_118r_page_gap_overlay_review_sha256": PINNED_INPUTS["evidence/source/raw-run-118r-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json"],
        "run_119_reporting_materializer_sha256": sha256_file(MATERIALIZER_RELATIVE),
    })
    counts = overlay["combined_counts"]
    queue = overlay["queue_accounting"]
    findings["counts"].update({
        "static_source_feature_ownership_records": counts["source_owner_records"],
        "static_source_feature_ownership_route_records": counts["route_owner_records"],
        "static_source_feature_ownership_page_records": counts["page_owner_records"],
        "static_source_feature_ownership_distinct_feature_ids": counts["distinct_feature_ids"],
        "static_source_feature_ownership_distinct_H_feature_ids": counts["distinct_H_feature_ids"],
        "static_source_feature_ownership_distinct_D_feature_ids": counts["distinct_D_feature_ids"],
        "static_source_feature_ownership_route_distinct_feature_ids": counts["route_distinct_feature_ids"],
        "static_source_feature_ownership_page_distinct_feature_ids": counts["page_distinct_feature_ids"],
        "static_source_feature_ownership_route_page_feature_overlap": counts["route_page_feature_overlap"],
        "static_controller_action_bridges": counts["static_controller_action_bridges"],
        "bounded_static_source_ownership_percent": counts["bounded_static_source_ownership_percent"],
        "bounded_static_source_residual_records": counts["bounded_static_source_residual_records"],
        "direct_exact_queue_records": queue["direct_exact_queue_records"],
        "direct_exact_queue_reviewed": queue["reviewed_queue_surface_rows"],
        "direct_exact_queue_owned": queue["owner_queue_surface_rows"],
        "direct_exact_queue_shared": queue["shared_queue_surface_rows"],
        "direct_exact_queue_alias": queue["alias_queue_surface_rows"],
        "direct_exact_queue_pending_unreviewed": queue["pending_unreviewed_queue_surface_rows"],
        "direct_exact_queue_without_ownership": queue["queue_surfaces_without_ownership"],
    })
    checkpoint_findings = json.loads(
        git("show", f"{CHECKPOINT_COMMIT}:docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/findings.json")
    )
    findings["historical_run_114_outcome_neutral_name_only_route_action_ownership"] = checkpoint_findings[
        "current_static_source_feature_ownership"
    ]
    findings["historical_run_114_outcome_neutral_name_only_route_action_ownership_review"] = checkpoint_findings[
        "current_outcome_neutral_name_only_route_action_ownership_review"
    ]
    findings.pop("current_outcome_neutral_name_only_route_action_ownership_review", None)
    findings["current_static_source_feature_ownership"] = {
        "run_id": overlay["run_id"],
        "review_run_id": review["run_id"],
        "status": "GO_REVIEWED_BOUNDED_RESPITE_HANDOVER_PAGE_OWNERSHIP_ONLY",
        "baseline_records": overlay["baseline"]["source_owner_records"],
        "reviewed_pages": overlay["reviewed_overlay"]["reviewed_pages"],
        "overlay_source_records": len(overlay["overlay_source_records"]),
        "page_owner_records_added": overlay["reviewed_overlay"]["accepted_page_owner_records"],
        "route_owner_records_added": overlay["reviewed_overlay"]["accepted_route_owner_records"],
        "controller_action_bridges_added": overlay["reviewed_overlay"]["accepted_controller_action_bridges"],
        **counts,
        "queue_accounting": queue,
        "ownership_basis": "FRESH_COMPLETE_PAGE_SEMANTIC_REVIEW_NOT_PARENT_ROUTE_INHERITANCE",
        "static_readiness_risks_preserved": [
            "canonical approved-Site scope not established",
            "permission/UI action convergence not established",
            "sensitive draft and note privacy not established",
            "foreign-Site direct-object concealment not established",
        ],
        "independent_review_discrepancies": review["decision"]["mechanical_discrepancies"] + review["decision"]["semantic_discrepancies"],
        "gate_4": {"status": "PARTIAL_BOUNDED_STATIC_SOURCE_OWNERSHIP_INCOMPLETE", "complete": False},
        "credit_boundary": overlay["credit_boundary"],
    }
    findings["current_outcome_neutral_respite_handover_page_gap_ownership_review"] = {
        "run_id": review["run_id"],
        "status": review["status"],
        "reviewers": len(review["reviewers"]),
        "owner_overlay_records_verified": review["decision"]["owner_overlay_records_verified"],
        "mechanical_discrepancies": review["decision"]["mechanical_discrepancies"],
        "semantic_discrepancies": review["decision"]["semantic_discrepancies"],
        "reporting_materialization_authorized": review["decision"]["reporting_promotion_authorized"],
        "downstream_credit_authorized": False,
        "gate_4_complete": False,
        "completion_credit": False,
    }
    run116 = read_json("evidence/browser/current-audit-dashboard-verification-run-116-wave-16.json")
    verification = run116["verification"]
    findings["current_audit_artifact_verification_history"]["run_116"] = {
        "status": run116["status"],
        "dashboard_sha256": run116["pins"]["dashboard_html_sha256"],
        "receipt_sha256": PINNED_INPUTS["evidence/browser/current-audit-dashboard-verification-run-116-wave-16.json"],
        "viewports_verified": verification["viewports_verified"],
        "unique_local_links_verified": verification["unique_local_links"],
        "anchors_verified": verification["anchors"],
        "duplicate_authored_ids": verification["duplicate_authored_ids"],
        "console_warnings": verification["console_warnings"],
        "console_errors": verification["console_errors"],
        "page_errors": verification["page_errors"],
        "current_dashboard_credit": False,
        "application_browser_credit": False,
    }
    assert canonical_json_sha256(findings["records"]) == RECORDS_SHA256
    assert findings["counts"]["final_P0"] == findings["counts"]["final_P1"] == 0
    assert findings["counts"]["benchmark_mapped"] == findings["counts"]["final_no_match"] == 0
    write_lf(relative, json.dumps(findings, ensure_ascii=False, indent=2) + "\n")


def patch_dashboard_template() -> None:
    relative = "generators/build-current-audit-dashboard.py"
    text = path(relative).read_text(encoding="utf-8")
    read_anchor = 'reviewed_name_only_route_action_overlay_review = read_json("evidence/source/raw-run-114r-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json")\n'
    read_addition = read_anchor + (
        'respite_handover_page_gap_cohort = read_json("evidence/source/root-run-117-outcome-neutral-respite-handover-page-gap-cohort-wave-17.json")\n'
        'respite_handover_page_gap_review = read_json("evidence/source/raw-run-117r-independent-outcome-neutral-respite-handover-page-gap-review-wave-17.json")\n'
        'reviewed_respite_handover_page_overlay = read_json("evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json")\n'
        'reviewed_respite_handover_page_overlay_review = read_json("evidence/source/raw-run-118r-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json")\n'
    )
    text = replace_once(text, read_anchor, read_addition, "dashboard Wave 17 reads")
    pin_anchor = 'assert sha256_file("evidence/source/raw-run-114r-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json") == "f52ace52820c43ad5043139e18f1d71cf4be904091fbc02e83e045465ded62f2"\n'
    pin_addition = pin_anchor + (
        'assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-116-wave-16.json") == "90ec8ab20cb9bf8d1e1509db614f941ad5337033973d754445ab6c88b2f13bf8"\n'
        'assert sha256_file("generators/build-outcome-neutral-respite-handover-page-gap-cohort-wave-17.py") == "85068c7a0170e155b3f5e41b87c91d27c7a45f3e2a117ea2444af91eb45a4374"\n'
        'assert sha256_file("evidence/source/root-run-117-outcome-neutral-respite-handover-page-gap-cohort-wave-17.json") == "e468e7e7736e49eea629b4faec1fdce94d7de30eee478b08c81b90793622bd2e"\n'
        'assert sha256_file("generators/materialize-independent-outcome-neutral-respite-handover-page-gap-review-wave-17.py") == "717803e612e94ccc0af3e356050a7e72353d2fc7b31dfd2ab00e30b51af8e11f"\n'
        'assert sha256_file("evidence/source/raw-run-117r-independent-outcome-neutral-respite-handover-page-gap-review-wave-17.json") == "264236eccceb279522fb784a7c27db2ecc8fd0434e4e5668c33fbe263f1cbc9b"\n'
        'assert sha256_file("generators/integrate-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.py") == "990d6bbf4879cbaf10e6b4031f640be6bcef346b7e9685e3d3c7da2d846271fb"\n'
        'assert sha256_file("evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json") == "33dd2f8ffa2b35c6651a6bf18923872e70ba5c2f91fce8b7222666bb8a91fc8b"\n'
        'assert sha256_file("generators/materialize-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-review-wave-17.py") == "9c8a23e77504ebef3d648b5bd0e894d4c95a8065f04748091b9f33e4aec4fa88"\n'
        'assert sha256_file("evidence/source/raw-run-118r-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json") == "043d57357e3ff1ede8f0effacdb71e4d802d98d53d555ab39316bce33fe06a2d"\n'
    )
    text = replace_once(text, pin_anchor, pin_addition, "dashboard Wave 17 pins")
    semantic_anchor = 'assert all(reviewed_name_only_route_action_overlay["credit_boundary"][key] is False for key in ("static_page_feature_ownership", "frontend_caller_ownership", "matrix_mutation", "wholesale_507_queue_ownership", "complete_route_page_feature_crosswalk", "framework_route_reachability", "navigation", "site_authorization_correctness", "permission_correctness", "privacy_correctness", "direct_object_correctness", "lifecycle_correctness", "concurrency_correctness", "runtime", "database", "build", "application_browser", "executed_tests", "benchmark", "ease", "pass", "final_finding", "completion", "audit_complete"))\n'
    semantic_addition = semantic_anchor + """
assert respite_handover_page_gap_cohort["counts"]["candidate_page_records"] == 4
assert respite_handover_page_gap_cohort["counts"]["ownership_credit_awarded"] == 0
assert respite_handover_page_gap_review["decision"]["verdict"] == "GO_4_EXPLICIT_OWNER_PAGE"
assert respite_handover_page_gap_review["decision"]["owner_pages"] == 4
assert respite_handover_page_gap_review["decision"]["static_page_owner_records_authorized"] == 4
assert reviewed_respite_handover_page_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_respite_handover_page_overlay_review["decision"]["reporting_promotion_authorized"] is True
assert reviewed_respite_handover_page_overlay_review["decision"]["gate_4_complete"] is False
assert len(reviewed_respite_handover_page_overlay["overlay_source_records"]) == 4
assert reviewed_respite_handover_page_overlay["new_static_controller_action_bridges"] == []
counts = reviewed_respite_handover_page_overlay["combined_counts"]
queue = reviewed_respite_handover_page_overlay["queue_accounting"]
assert (counts["source_owner_records"], counts["route_owner_records"], counts["page_owner_records"]) == (641, 288, 353)
assert (counts["distinct_feature_ids"], counts["distinct_H_feature_ids"], counts["distinct_D_feature_ids"]) == (256, 234, 22)
assert (counts["route_distinct_feature_ids"], counts["page_distinct_feature_ids"], counts["route_page_feature_overlap"]) == (61, 242, 47)
assert (counts["static_controller_action_bridges"], counts["bounded_static_source_residual_records"]) == (76, 3288)
assert (counts["residual_explicit_unmapped_routes"], counts["semantic_shared_routes"], counts["reviewed_alias_routes"]) == (2921, 5, 4)
assert (counts["residual_unadjudicated_page_roots"], counts["semantic_shared_page_roots"], counts["evidence_gap_page_roots_tagged_within_residual"]) == (349, 9, 1)
assert (queue["direct_exact_queue_records"], queue["reviewed_queue_surface_rows"], queue["owner_queue_surface_rows"], queue["shared_queue_surface_rows"], queue["alias_queue_surface_rows"], queue["pending_unreviewed_queue_surface_rows"], queue["queue_surfaces_without_ownership"]) == (507, 84, 77, 3, 4, 423, 430)
assert 3929 == 641 + 3288
assert 641 == 288 + 353
assert 711 == 353 + 9 + 349
assert reviewed_respite_handover_page_overlay["credit_boundary"]["STATIC_PAGE_FEATURE_OWNERSHIP_FOR_4_RECORDS"] is True
assert all(reviewed_respite_handover_page_overlay["credit_boundary"][key] is False for key in ("static_route_feature_ownership_added", "static_controller_action_bridge_added", "direct_exact_queue_review_added", "matrix_mutation", "wholesale_507_queue_ownership", "complete_route_page_feature_crosswalk", "framework_route_reachability", "navigation", "site_authorization_correctness", "permission_correctness", "privacy_correctness", "direct_object_correctness", "lifecycle_correctness", "runtime", "database", "build", "application_browser", "executed_tests", "benchmark", "ease", "pass", "final_finding", "completion", "audit_complete"))
"""
    text = replace_once(text, semantic_anchor, semantic_addition, "dashboard Wave 17 assertions")
    evidence_anchor = '    ("RUN-115 name-only route/action reporting/hash receipt", "evidence/source/current-run-115-reviewed-name-only-route-action-reporting-wave-16.json"),\n'
    evidence_addition = evidence_anchor + (
        '    ("RUN-116 verified superseded dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-116-wave-16.json"),\n'
        '    ("RUN-117 respite handover page-gap cohort generator", "generators/build-outcome-neutral-respite-handover-page-gap-cohort-wave-17.py"),\n'
        '    ("RUN-117 four-page outcome-neutral cohort", "evidence/source/root-run-117-outcome-neutral-respite-handover-page-gap-cohort-wave-17.json"),\n'
        '    ("RUN-117R page semantic-review materializer", "generators/materialize-independent-outcome-neutral-respite-handover-page-gap-review-wave-17.py"),\n'
        '    ("RUN-117R four-owner page semantic review", "evidence/source/raw-run-117r-independent-outcome-neutral-respite-handover-page-gap-review-wave-17.json"),\n'
        '    ("RUN-118 page owner-only overlay generator", "generators/integrate-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.py"),\n'
        '    ("RUN-118 four-page owner-only overlay", "evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json"),\n'
        '    ("RUN-118R independent overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-review-wave-17.py"),\n'
        '    ("RUN-118R final-byte identity and boundary review", "evidence/source/raw-run-118r-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json"),\n'
        '    ("RUN-119 respite handover page-gap reporting materializer", "generators/materialize-run-119-reviewed-respite-handover-page-gap-reporting-wave-17.py"),\n'
        '    ("RUN-119 respite handover page-gap reporting/hash receipt", "evidence/source/current-run-119-reviewed-respite-handover-page-gap-reporting-wave-17.json"),\n'
    )
    text = replace_once(text, evidence_anchor, evidence_addition, "dashboard evidence links")
    text = text.replace("RUN-071–115", "RUN-071–119").replace("RUN-077–115", "RUN-077–119")
    text = replace_once(text, 'f".{output_path.name}.tmp-run115-dashboard"', 'f".{output_path.name}.tmp-run119-dashboard"', "dashboard temp")
    for old, new in (
        ('static_owner_records=reviewed_name_only_route_action_overlay["combined_counts"]["source_owner_records"]', 'static_owner_records=reviewed_respite_handover_page_overlay["combined_counts"]["source_owner_records"]'),
        ('static_owner_routes=reviewed_name_only_route_action_overlay["combined_counts"]["route_owner_records"]', 'static_owner_routes=reviewed_respite_handover_page_overlay["combined_counts"]["route_owner_records"]'),
        ('static_owner_pages=reviewed_name_only_route_action_overlay["combined_counts"]["page_owner_records"]', 'static_owner_pages=reviewed_respite_handover_page_overlay["combined_counts"]["page_owner_records"]'),
        ('static_owner_features=reviewed_name_only_route_action_overlay["combined_counts"]["distinct_feature_ids"]', 'static_owner_features=reviewed_respite_handover_page_overlay["combined_counts"]["distinct_feature_ids"]'),
        ('static_owner_h_features=reviewed_name_only_route_action_overlay["combined_counts"]["distinct_H_feature_ids"]', 'static_owner_h_features=reviewed_respite_handover_page_overlay["combined_counts"]["distinct_H_feature_ids"]'),
        ('static_owner_d_features=reviewed_name_only_route_action_overlay["combined_counts"]["distinct_D_feature_ids"]', 'static_owner_d_features=reviewed_respite_handover_page_overlay["combined_counts"]["distinct_D_feature_ids"]'),
        ('route_feature_ids=reviewed_name_only_route_action_overlay["combined_counts"]["route_distinct_feature_ids"]', 'route_feature_ids=reviewed_respite_handover_page_overlay["combined_counts"]["route_distinct_feature_ids"]'),
        ('page_feature_ids=reviewed_name_only_route_action_overlay["combined_counts"]["page_distinct_feature_ids"]', 'page_feature_ids=reviewed_respite_handover_page_overlay["combined_counts"]["page_distinct_feature_ids"]'),
        ('route_page_overlap=reviewed_name_only_route_action_overlay["combined_counts"]["route_page_feature_overlap"]', 'route_page_overlap=reviewed_respite_handover_page_overlay["combined_counts"]["route_page_feature_overlap"]'),
        ('static_action_bridges=reviewed_name_only_route_action_overlay["combined_counts"]["static_controller_action_bridges"]', 'static_action_bridges=reviewed_respite_handover_page_overlay["combined_counts"]["static_controller_action_bridges"]'),
        ('static_residual=f"{reviewed_name_only_route_action_overlay[\'combined_counts\'][\'bounded_static_source_residual_records\']:,}"', 'static_residual=f"{reviewed_respite_handover_page_overlay[\'combined_counts\'][\'bounded_static_source_residual_records\']:,}"'),
        ('ownership_percent=reviewed_name_only_route_action_overlay["combined_counts"]["bounded_static_source_ownership_percent"]', 'ownership_percent=reviewed_respite_handover_page_overlay["combined_counts"]["bounded_static_source_ownership_percent"]'),
        ('page_residual=reviewed_name_only_route_action_overlay["combined_counts"]["residual_unadjudicated_page_roots"]', 'page_residual=reviewed_respite_handover_page_overlay["combined_counts"]["residual_unadjudicated_page_roots"]'),
        ('page_shared=reviewed_name_only_route_action_overlay["combined_counts"]["semantic_shared_page_roots"]', 'page_shared=reviewed_respite_handover_page_overlay["combined_counts"]["semantic_shared_page_roots"]'),
        ('page_gap=reviewed_name_only_route_action_overlay["combined_counts"]["evidence_gap_page_roots_tagged_within_residual"]', 'page_gap=reviewed_respite_handover_page_overlay["combined_counts"]["evidence_gap_page_roots_tagged_within_residual"]'),
    ):
        text = replace_once(text, old, new, old)
    for field in ("direct_exact_queue_records", "reviewed_queue_surface_rows", "pending_unreviewed_queue_surface_rows", "queue_surfaces_without_ownership", "owner_queue_surface_rows", "shared_queue_surface_rows", "alias_queue_surface_rows"):
        text = text.replace(f'reviewed_name_only_route_action_overlay["queue_accounting"]["{field}"]', f'reviewed_respite_handover_page_overlay["queue_accounting"]["{field}"]')
    substitutions_anchor = '    page_context_authorized=reviewed_name_only_route_action_overlay["page_context_boundary"]["page_ownership_authorized"],\n'
    substitutions_addition = substitutions_anchor + (
        '    respite_page_wave_reviewed=reviewed_respite_handover_page_overlay["reviewed_overlay"]["reviewed_pages"],\n'
        '    respite_page_review_owner=reviewed_respite_handover_page_overlay["reviewed_overlay"]["owner_pages"],\n'
    )
    text = replace_once(text, substitutions_anchor, substitutions_addition, "dashboard page substitutions")
    html_replacements = (
        ('href="#checkpoint">RUN-115</a>', 'href="#checkpoint">RUN-119</a>'),
        ("RUN-113/R–114/R independently review and integrate 23 route owners plus 23 bridges while preserving one alias and adding zero pages, and RUN-115 refreshes current reporting.", "RUN-113/R–116 preserve the reviewed name-only route/action checkpoint and exact superseded dashboard verification; RUN-117/R–118/R independently review, integrate, and verify four explicit page owners with zero route, bridge, feature, or queue changes, and RUN-119 refreshes current reporting."),
        ("RUN-001 through RUN-115 are represented by audit artifacts", "RUN-001 through RUN-119 are represented by audit artifacts"),
        ("RUN-101/R–112 remain historical route/action and page-owner checkpoints with exact dashboard receipts. RUN-113/R review $route_wave_reviewed name-only route/actions as $route_review_owner owners, $route_review_alias alias, $route_review_shared shared, $route_review_dead dead, and $route_review_gap gap; RUN-114/R establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges, with 23 new route owners, 23 new bridges, one preserved alias, and zero page additions. Seven page contexts remain observation only: $page_context_owned already owned and $page_context_gaps gaps, with $page_context_authorized page credit.", "RUN-101/R–116 remain historical route/action, page-owner, reporting, and exact dashboard checkpoints. RUN-117/R review $respite_page_wave_reviewed Respite handover page gaps as $respite_page_review_owner explicit page owners; RUN-118/R establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges. The four page decisions are based on complete page semantics and are not inherited from parent route ownership."),
        ("RUN-113/R–114/R add $route_review_owner reviewed route owners and 23 controller-action bridges, preserve $route_review_alias reviewed alias, and add zero page owners, raising bounded ownership to $static_owner_records records and $static_action_bridges bridges; $queue_reviewed queue rows are reviewed, $queue_pending remain pending, and $queue_without_owner remain without ownership. RUN-115 reports only that bounded delta.", "RUN-113/R–116 preserve the historical name-only route/action checkpoint and exact superseded dashboard verification. RUN-117/R–118/R add $respite_page_review_owner explicit page owners, raising bounded ownership to $static_owner_records records while route, bridge, feature, and queue counts remain unchanged; $queue_reviewed queue rows are reviewed, $queue_pending remain pending, and $queue_without_owner remain without ownership. RUN-119 reports only that bounded delta."),
        ("<tr><td>RUN-113/R → 114/R current name-only route/action overlay</td>", "<tr><td>RUN-113/R → 116 historical name-only route/action overlay</td>"),
        ("<tr><td>RUN-113/R → 116 historical name-only route/action overlay</td><td><strong>$route_wave_reviewed reviewed = $route_review_owner owner + $route_review_alias alias + $route_review_shared shared + $route_review_dead dead + $route_review_gap gap · 23 route rows · 23 action bridges · 0 page rows</strong></td><td class=\"partial\">$static_owner_records cumulative owners · $static_owner_routes routes + $static_owner_pages pages · $static_owner_features FEATURE-IDs · Gate 4 incomplete</td></tr>", "<tr><td>RUN-113/R → 116 historical name-only route/action overlay</td><td><strong>$route_wave_reviewed reviewed = $route_review_owner owner + $route_review_alias alias + $route_review_shared shared + $route_review_dead dead + $route_review_gap gap · 23 route rows · 23 action bridges · 0 page rows</strong></td><td class=\"partial\">637 cumulative owners · 288 routes + 349 pages · 256 FEATURE-IDs · exact superseded dashboard verified</td></tr>"),
        ("<tr><td>RUN-115 reporting refresh</td><td><strong>name-only route/action overlay reported</strong></td><td class=\"partial\">audit-only materialization · matrix byte-identical · fresh RUN-116 verification required</td></tr>", "<tr><td>RUN-115 / RUN-116 historical reporting and dashboard</td><td><strong>name-only route/action overlay reported and exact dashboard verified</strong></td><td class=\"partial\">audit-only history · no verification metrics transfer</td></tr><tr><td>RUN-117/R → 118/R current Respite handover page overlay</td><td><strong>$respite_page_wave_reviewed reviewed = $respite_page_review_owner owner pages · 4 page rows · 0 route/bridge/queue rows</strong></td><td class=\"partial\">$static_owner_records cumulative owners · $static_owner_routes routes + $static_owner_pages pages · Gate 4 incomplete</td></tr><tr><td>RUN-119 reporting refresh</td><td><strong>four-page owner overlay reported</strong></td><td class=\"partial\">audit-only materialization · matrix byte-identical · fresh RUN-120 verification required</td></tr>"),
        ("<li>RUN-113/R: $route_wave_reviewed route/action candidates · $route_review_owner owners · $route_review_alias alias · $route_review_shared shared · $route_review_dead dead · $route_review_gap gap · 0 page credit</li><li>RUN-114/R: 23 route rows + 23 action bridges integrated and independently verified · one alias non-owner preserved · page calls $page_context_calls = $page_context_owned already-owned + $page_context_gaps gaps + $page_context_authorized credit · $static_owner_records cumulative owner records</li><li>RUN-115: deterministic name-only route/action reporting refresh · matrix and every Site/permission/privacy/direct-object/lifecycle/concurrency/execution/benchmark/Pass/finding/completion boundary unchanged</li>", "<li>RUN-113/R–116: historical 24 name-only route actions · 23 owners · one alias · 23 route rows and bridges · reporting and exact superseded dashboard verification</li><li>RUN-117/R: $respite_page_wave_reviewed Respite handover page gaps · $respite_page_review_owner explicit page owners · Site/privacy/permission/direct-object risks retained</li><li>RUN-118/R: four page rows integrated and independently verified · zero route/bridge/queue changes · $static_owner_records cumulative owner records</li><li>RUN-119: deterministic four-page reporting refresh · matrix and every Site/permission/privacy/direct-object/lifecycle/concurrency/execution/benchmark/Pass/finding/completion boundary unchanged</li>"),
        ("RUN-113/R–114/R add 23 independently reviewed route owners and 23 bridges, preserve one alias, add zero page owners, and RUN-115 refreshes reporting.", "RUN-113/R–116 preserve the reviewed name-only route/action checkpoint and exact dashboard receipt; RUN-117/R–118/R add four independently reviewed Respite handover page owners without route, bridge, feature, or queue changes, and RUN-119 refreshes reporting."),
        ("<tr><td>RUN-114/R current name-only route/action ownership</td>", "<tr><td>RUN-118/R current Respite handover page ownership</td>"),
        ("RUN-114/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges while adding 23 route owners and 23 bridges, preserving one alias non-owner, and adding zero page credit;", "RUN-118/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges while adding four explicit page owners with zero route, bridge, feature, or queue changes;"),
        (" · page calls $page_context_calls = $page_context_owned already-owned + $page_context_gaps gaps + $page_context_authorized page credit", " · historical RUN-114 page-context observations were $page_context_calls = $page_context_owned already-owned + $page_context_gaps gaps + $page_context_authorized page credit before the separate RUN-117/R review")
    )
    for old, new in html_replacements:
        text = replace_once(text, old, new, old[:80])
    prior_old = '<section class="panel"><h2>Prior audit-dashboard verification</h2><p>RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, RUN-094, RUN-100, RUN-104, RUN-108, and RUN-112 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-115.</p>'
    prior_new = '<section class="panel"><h2>Prior audit-dashboard verification</h2><p>RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, RUN-094, RUN-100, RUN-104, RUN-108, RUN-112, and RUN-116 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-119.</p>'
    text = replace_once(text, prior_old, prior_new, "prior verification paragraph")
    prior_link = '<li><a href="evidence/browser/current-audit-dashboard-verification-run-112-wave-15.json">Superseded RUN-112 verification GO</a></li>'
    text = replace_once(text, prior_link, prior_link + '<li><a href="evidence/browser/current-audit-dashboard-verification-run-116-wave-16.json">Superseded RUN-116 verification GO</a></li>', "prior RUN116 link")
    fresh_start = '<section class="panel"><h2>Fresh RUN-116 audit-dashboard verification</h2>'
    fresh_end = '\n    <section class="panel"><h2>RUN-071–119 evidence lineage</h2>'
    fresh_replacement = '<section class="panel"><h2>Fresh RUN-120 audit-dashboard verification</h2><p>The exact regenerated RUN-119 dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-120 receipt must record page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 641/288/353 ownership, four independently reviewed page owners, 61/242/47 route/page/overlap feature sets, 76 bridges, route 3,218=288+5+4+2,921, page 711=353+9+349, queue 507=84+423 with 84=77+3+4 and 430 without ownership, 3,288 residual records, one operating organisation across multiple Sites, Gate 4 open, mapping 0/340, and all zero-credit boundaries. It verifies the audit artifact only and grants no application-browser, responsive-application, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-120-wave-17.json">RUN-120 responsive audit-dashboard verification receipt</a></li></ul></section>'
    text = replace_between(text, fresh_start, fresh_end, fresh_replacement, "fresh RUN120 section")
    duplicate_lineage = '<section class="panel"><h2>RUN-071–119 evidence lineage</h2></section>\n    <section class="panel"><h2>RUN-071–119 evidence lineage</h2>'
    if duplicate_lineage in text:
        text = text.replace(duplicate_lineage, '<section class="panel"><h2>RUN-071–119 evidence lineage</h2>', 1)
    assert duplicate_lineage not in text
    text = replace_once(text, "Generated deterministically from independently reviewed static evidence through RUN-114/R and reported in RUN-115.", "Generated deterministically from independently reviewed static evidence through RUN-118/R and reported in RUN-119.", "dashboard footer")
    write_lf(relative, text)


def main() -> None:
    overlay, review, preserved = assert_inputs()
    patch_reports()
    patch_findings(overlay, review)
    patch_dashboard_template()
    for relative, expected in preserved.items():
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
        "schema_version": SCHEMA_VERSION,
        "run_id": RUN_ID,
        "status": "REVIEWED_RESPITE_HANDOVER_PAGE_OWNER_OVERLAY_REPORTED_GATE_4_INCOMPLETE",
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
            "materializer_sha256": sha256_file(MATERIALIZER_RELATIVE),
            "overlay_sha256": PINNED_INPUTS["evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json"],
            "independent_overlay_review_sha256": PINNED_INPUTS["evidence/source/raw-run-118r-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json"],
            "superseded_dashboard_verification_sha256": PINNED_INPUTS["evidence/browser/current-audit-dashboard-verification-run-116-wave-16.json"],
        },
        "inputs": {**CURRENT_REPORT_INPUTS, **PINNED_INPUTS},
        "outputs": outputs,
        "preserved": preserved,
        "counts": {
            **overlay["combined_counts"],
            **overlay["queue_accounting"],
            "reviewed_page_gaps": 4,
            "reviewed_owner_pages_added": 4,
            "route_owner_records_added": 0,
            "controller_action_bridges_added": 0,
            "queue_rows_reconciled": 0,
            "provisional_finding_records": 12,
            "final_findings": 0,
            "matrix_rows_changed": 0,
            "matrix_cells_changed": 0,
        },
        "checks": {
            "run_117r_review_go": True,
            "run_117r_owner_pages": 4,
            "run_118r_overlay_review_go": True,
            "independent_review_discrepancies": 0,
            "page_owner_records_added": 4,
            "route_owner_records_added": 0,
            "controller_action_bridges_added": 0,
            "queue_rows_reconciled": 0,
            "matrix_byte_identical": True,
            "provisional_finding_record_semantics_preserved": True,
            "application_source_paths_written": 0,
            "one_organisation_multi_site_architecture_preserved": True,
            "dashboard_requires_fresh_run_120_artifact_verification": True,
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
        temporary = output.with_suffix(output.suffix + ".tmp")
        temporary.write_bytes(encoded)
        os.replace(temporary, output)
    assert output.read_bytes() == encoded
    print(json.dumps({
        "status": receipt["status"],
        "output": output.relative_to(REPO).as_posix(),
        "sha256": sha256_file(OUTPUT_RELATIVE),
        "source_owner_records": receipt["counts"]["source_owner_records"],
        "route_owner_records": receipt["counts"]["route_owner_records"],
        "page_owner_records": receipt["counts"]["page_owner_records"],
        "gate_4_complete": receipt["checks"]["gate_4_complete"],
    }, indent=2))


if __name__ == "__main__":
    main()
