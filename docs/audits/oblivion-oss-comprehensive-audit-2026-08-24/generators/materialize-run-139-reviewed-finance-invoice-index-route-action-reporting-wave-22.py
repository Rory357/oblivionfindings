#!/usr/bin/env python3
"""Report the independently verified RUN-138 invoice-index overlay.

Only five current reporting surfaces are updated. Reports 02-12, inventory,
the 340-row matrix, application source, tests, and the currently verified
RUN-135 dashboard remain byte-identical. The regenerated dashboard requires a
fresh RUN-140 audit-artifact receipt.
"""

from __future__ import annotations

import copy
import csv
import hashlib
import json
import os
import subprocess
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
MATERIALIZER_RELATIVE = (
    "generators/materialize-run-139-reviewed-finance-invoice-index-"
    "route-action-reporting-wave-22.py"
)
OUTPUT_RELATIVE = (
    "evidence/source/current-run-139-reviewed-finance-invoice-index-"
    "route-action-reporting-wave-22.json"
)
SCHEMA_VERSION = (
    "run-139-reviewed-finance-invoice-index-route-action-reporting-wave-22-v1"
)
RUN_ID = (
    "RUN-139-REVIEWED-FINANCE-INVOICE-INDEX-ROUTE-ACTION-REPORTING-WAVE-22"
)

CHECKPOINT_COMMIT = "c2af6b0e75ba7204f8c208f28cfa7b4e406a935f"
CHECKPOINT_TREE = "3bb5294707712f9ee60b11ef5cdf0e1b011860b8"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
TESTS_TREE = "fef0122b31fdccbe2f9f805f7515666c74e2880a"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
RECORDS_SHA256 = "118c1a1f19b2e300c77d5b4d71c60f75b7038382f3c71e18f078367f8473e260"
RECORD_ID_LIST_SHA256 = "ae6d9d23a873eff3403740d25b8e19ff70af1ec70a85987e416433f9eb35d62f"
CURRENT_DASHBOARD_SHA256 = "dc13e4fcbf5aca80412c49ce120438ef84a14db908603fe4ab963a9e7c2afb42"
KNOWN_ITERATION_MATERIALIZER_SHA256S: set[str] = set()

CURRENT_REPORT_INPUTS = {
    "00-executive-summary.md": "41f63d6ef1534fc41acc8c50d5d2807b6fd31c52fc75f4ca57e98fbf674a06af",
    "01-repository-module-map.md": "0a6e6545390c198e94a0291078f73529b405ec6ddeb652a0f6336ca3713a950e",
    "13-unresolved-questions-and-evidence-gaps.md": "e65f7347d897d68dfb6f12896e2ddcaeb80dc3e0ab741a4d9366976abd2664ef",
    "findings.json": "dfbc8976c51caac6dfda28e1efe986c93b3b2abd3cb51794b8913084b7cefba8",
    "generators/build-current-audit-dashboard.py": "af1d80da0aa3898a46d0f36823f6577b0ab9a3183d039006a4d6e45bf5e481d5",
}

PINNED_INPUTS = {
    "generators/materialize-run-135-reviewed-finance-accounting-integration-route-action-reporting-wave-21.py": "8f0590834cc8d1f64bc0ac2cd1bc53f88ab1a3b161147863f3def389777dddad",
    "evidence/source/current-run-135-reviewed-finance-accounting-integration-route-action-reporting-wave-21.json": "af70461527e7b22855b0a7917121112ca973fe4e88450b6b87ef0b5ae39d99da",
    "evidence/browser/current-audit-dashboard-verification-run-136-wave-21.json": "24838333225819640bc767d7f5149aaaadcfa11377e4035e985af314fc549d1e",
    "generators/build-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.py": "93766689117c88173a08f8548a04d7e62f00eadf71fb7fefa302936e540c9bd9",
    "evidence/source/root-run-137-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.json": "e2a6a346365ada6013b82f4e29aa955ffcedf7f3b53ab88279c700407d3012bc",
    "generators/materialize-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.py": "4a4eb33dd34832b2182bfe27bf13f90f3a30e7406b74552e82dda2f0c73b99c5",
    "evidence/source/raw-run-137r-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.json": "a3659294a8d2f9c203968a885da7b48f928d5341dbcb2b177eb85b40a058411f",
    "generators/integrate-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.py": "76f9f34a249b901e4448166155eb0e5a314390bebfc90c6d28f5df08c1cb6baf",
    "evidence/source/current-run-138-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json": "005a55c952ec3f3b2a5bac9f3c99000fa4eae65a488764dfd1f4662063431701",
    "generators/materialize-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-review-wave-22.py": "867cf33924bcaf7cf34fa5d22c0a99a920d75f2255ef437adeca1e0a9734af3f",
    "evidence/source/raw-run-138r-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json": "befb7b4463b588d7ebbb9e42c6c7b34bf02de78b962628f0c75f423c2b7b5e31",
}

PRESERVED = {
    "02-eight-pass-coverage-ledger.csv": "ee4dc3126113884b4b8661dc3a3d13ac6a61b9661b2cace58fe82dcbe1d2a4a6",
    "03-feature-to-benchmark-matrix.csv": MATRIX_SHA256,
    "04-workflow-usability-scorecard.csv": "ea6879340229541c198b5ac654bde6d26d38eaefdd29ff66e1026263f9546faa",
    "05-browser-visual-coverage-matrix.csv": "564224d295f8a2d3bad6001b74743fb0a1d75eb41315a817264307353b74dd84",
    "06-open-source-benchmark-register.csv": "cc493cd1807e62a9ffa27192c658400e697391b7a0baa3f0014628145c6b7b91",
    "07-module-findings.md": "5a8de7d5c9e181d8da0425e7f040e8744dd85cbfda16573ef824ce3219f85712",
    "08-cross-module-journeys.md": "ef4471ba75ac9080e4565989e4b038bf7d0ad306cad1984019882457517c853c",
    "09-ui-ux-accessibility-visual-consistency.md": "27fa04e15cbd0eedb92514835884d0344db09f279a2295cea94ae0d1071a6e7c",
    "10-architecture-data-integration-security.md": "ca5667b1c042024f32f320254baf063dd4bcd2c4b12972cf2aac29c02d782b22",
    "11-prioritised-roadmap.md": "e5c2f41bf98d3415de97d18d853f1d7c351b337ba544fbf8c81330ec63dcf02d",
    "12-native-build-and-do-not-copy-register.md": "44ae85422a6863d4804fec7d495107b9bdc937257f023767fb306ccd755e137a",
    "inventory.json": "46cd688dd9543b186a608e950754abe9e30389a792156719f8a999130dfca5fa",
}


def path(relative: str) -> Path:
    return AUDIT_DIR / relative


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(relative: str) -> str:
    return sha256_bytes(path(relative).read_bytes())


def canonical_json_sha256(value: Any) -> str:
    raw = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return sha256_bytes(raw.encode("utf-8"))


def canonical_list_sha256(values: list[str]) -> str:
    return sha256_bytes("\n".join(sorted(values)).encode("utf-8"))


def read_json(relative: str) -> dict[str, Any]:
    source = path(relative)

    def reject_duplicates(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        value: dict[str, Any] = {}
        for key, item in pairs:
            assert key not in value, (relative, key)
            value[key] = item
        return value

    value = json.loads(source.read_text(encoding="utf-8"), object_pairs_hook=reject_duplicates)
    assert isinstance(value, dict), relative
    return value


def git(*args: str) -> str:
    return subprocess.run(
        ["git", *args],
        cwd=REPO,
        check=True,
        capture_output=True,
        text=True,
        encoding="utf-8",
    ).stdout.rstrip()


def write_lf(relative: str, text: str) -> None:
    encoded = text.replace("\r\n", "\n").encode("utf-8")
    target = path(relative)
    if not target.exists() or target.read_bytes() != encoded:
        target.write_bytes(encoded)


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if new in text:
        return text
    assert text.count(old) == 1, (label, text.count(old))
    return text.replace(old, new, 1)


def replace_between(
    text: str, start: str, end: str, replacement: str, label: str
) -> str:
    if replacement in text:
        return text
    start_index = text.find(start)
    assert start_index >= 0, (label, "missing start")
    end_index = text.find(end, start_index)
    assert end_index >= 0, (label, "missing end")
    return text[:start_index] + replacement + text[end_index:]


def replace_line_containing(text: str, marker: str, replacement: str) -> str:
    lines = text.splitlines()
    if replacement in lines:
        return text
    matches = [index for index, line in enumerate(lines) if marker in line]
    assert len(matches) == 1, (marker, len(matches))
    lines[matches[0]] = replacement
    return "\n".join(lines) + "\n"


def expected_status_paths() -> set[str]:
    relatives = [
        "00-executive-summary.md",
        "01-repository-module-map.md",
        "13-unresolved-questions-and-evidence-gaps.md",
        "findings.json",
        "generators/build-current-audit-dashboard.py",
        MATERIALIZER_RELATIVE,
        OUTPUT_RELATIVE,
    ]
    prefix = AUDIT_DIR.relative_to(REPO).as_posix()
    return {f"{prefix}/{relative}" for relative in relatives}


def assert_inputs() -> tuple[dict[str, Any], dict[str, Any]]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("rev-parse", "HEAD:tests") == TESTS_TREE
    assert not git("status", "--porcelain", "--", "app", "routes", "resources/js", "tests")
    status_paths = {
        line[3:].replace("\\", "/")
        for line in git("status", "--porcelain").splitlines()
        if line
    }
    assert status_paths <= expected_status_paths(), status_paths

    existing = read_json(OUTPUT_RELATIVE) if path(OUTPUT_RELATIVE).exists() else None
    for relative, expected in CURRENT_REPORT_INPUTS.items():
        allowed = {expected}
        if existing is not None:
            assert existing["run_id"] == RUN_ID
            assert existing["schema_version"] == SCHEMA_VERSION
            assert existing["pins"]["checkpoint_commit"] == CHECKPOINT_COMMIT
            assert existing["pins"]["materializer_sha256"] in (
                KNOWN_ITERATION_MATERIALIZER_SHA256S
                | {sha256_file(MATERIALIZER_RELATIVE)}
            )
            allowed.add(existing["outputs"][relative])
        assert sha256_file(relative) in allowed, relative
    for relative, expected in PINNED_INPUTS.items():
        assert sha256_file(relative) == expected, relative
    for relative, expected in PRESERVED.items():
        assert sha256_file(relative) == expected, relative
    assert sha256_file("audit-dashboard.html") == CURRENT_DASHBOARD_SHA256

    overlay = read_json(
        "evidence/source/current-run-138-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json"
    )
    review = read_json(
        "evidence/source/raw-run-138r-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json"
    )
    cohort = read_json(
        "evidence/source/root-run-137-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.json"
    )
    semantic_review = read_json(
        "evidence/source/raw-run-137r-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.json"
    )
    counts = overlay["combined_counts"]
    queue = overlay["queue_accounting"]
    assert (counts["source_owner_records"], counts["route_owner_records"], counts["page_owner_records"]) == (661, 304, 357)
    assert (counts["distinct_feature_ids"], counts["distinct_H_feature_ids"], counts["distinct_D_feature_ids"]) == (256, 234, 22)
    assert (counts["route_distinct_feature_ids"], counts["page_distinct_feature_ids"], counts["route_page_feature_overlap"]) == (63, 242, 49)
    assert (counts["static_controller_action_bridges"], counts["bounded_static_source_residual_records"]) == (92, 3268)
    assert counts["bounded_static_source_ownership_percent"] == "16.823619"
    assert (counts["residual_explicit_unmapped_routes"], counts["semantic_shared_routes"], counts["reviewed_alias_routes"], counts["evidence_gap_routes_tagged_within_residual"]) == (2897, 12, 5, 7)
    assert (counts["residual_unadjudicated_page_roots"], counts["semantic_shared_page_roots"], counts["evidence_gap_page_roots_tagged_within_residual"]) == (345, 9, 1)
    assert (queue["direct_exact_queue_records"], queue["reviewed_queue_surface_rows"], queue["owner_queue_surface_rows"], queue["shared_queue_surface_rows"], queue["alias_queue_surface_rows"], queue["dead_queue_surface_rows"], queue["evidence_gap_queue_surface_rows"], queue["pending_unreviewed_queue_surface_rows"], queue["queue_surfaces_without_ownership"]) == (507, 115, 93, 10, 5, 0, 7, 392, 414)
    assert cohort["counts"]["candidate_route_actions"] == 1
    assert cohort["counts"]["ownership_credit_awarded"] == 0
    assert semantic_review["decision"]["verdict"] == "GO_ACCEPT_1_OWNER_ROUTE_ACTION_FOR_BOUNDED_LATER_INTEGRATION"
    assert semantic_review["decision"]["current_overlay_credit_awarded"] is False
    assert review["decision"]["verdict"] == "GO"
    assert review["decision"]["reporting_materialization_authorized"] is True
    assert review["decision"]["gate_4_complete"] is False
    assert review["verified_identity"] == overlay["identity"]
    assert len(review["verified_identity"]) == 41
    assert len(overlay["overlay_source_records"]) == 1
    assert len(overlay["new_static_controller_action_bridges"]) == 1
    assert overlay["reviewed_non_owner_outcomes"] == []
    assert overlay["source_packet_expansion_preservation"]["total_disclosed_expansion_entries"] == 12
    assert overlay["source_packet_expansion_preservation"]["widened_existing_packet_files"] == 7
    assert overlay["source_packet_expansion_preservation"]["newly_followed_files"] == 5
    assert overlay["assurance_findings_preservation"]["candidate_findings"] == 6
    assert overlay["assurance_findings_preservation"]["shared_findings"] == 3
    assert overlay["assurance_findings_preservation"]["total_findings"] == 9
    assert overlay["page_context_boundary"]["existing_page_owner_context_rows"] == 2
    assert overlay["page_context_boundary"]["new_page_owner_records"] == 0
    assert overlay["page_context_boundary"]["page_ownership_inherited"] is False
    assert overlay["page_context_boundary"]["page_ownership_reassigned"] is False
    assert overlay["noninheritance_boundary"]["selected_queue_index_zero_based"] == 77
    assert overlay["noninheritance_boundary"]["next_queue_index_zero_based"] == 78
    assert overlay["noninheritance_boundary"]["next_route_record_id"] == "RUN077-ROUTE-0669"
    assert overlay["noninheritance_boundary"]["next_boundary_selected_or_credited"] is False
    assert {key for key, value in overlay["credit_boundary"].items() if value} == {
        "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD",
        "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION",
    }
    assert {key for key, value in review["credit_boundary"].items() if value} == {
        "INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"
    }
    with path("03-feature-to-benchmark-matrix.csv").open(
        newline="", encoding="utf-8-sig"
    ) as handle:
        matrix_rows = list(csv.DictReader(handle))
    assert len(matrix_rows) == 340
    assert sum(
        row.get("benchmark_mapping_credit", "").strip().lower() == "true"
        for row in matrix_rows
    ) == 0
    return overlay, review

def patch_reports() -> None:
    tick = chr(96)
    summary_relative = "00-executive-summary.md"
    summary = path(summary_relative).read_text(encoding="utf-8")
    summary_block = """## RUN-113–139 reviewed route/action and page-ownership lineage

RUN-113/R–136 remain historical reviewed route/action, page-owner, reporting, and exact audit-dashboard checkpoints. RUN-133/R–136 preserve the six Finance accounting-integration route/action owners, six bridges, and zero-page-inheritance boundary.

RUN-137 freezes exactly one still-pending invoice-index action without pre-awarding ownership: invoices.index, RUN090-ROUTE-0078 / RUN077-ROUTE-0634 for CAP-FIN-BILLING-INVOICE-LIFECYCLE. Two fresh independent candidate reviewers and an independent synthesis reviewer trace the exact route identity, InvoiceController::index method, literal Index render, existing Index and Show page owners, caller context, source-packet expansions, and assurance risks. RUN-137R classifies it as one explicit OWNER_ROUTE_ACTION; the two existing page owners and frontend callers confer no inherited or reassigned ownership.

RUN-138 integrates exactly that one route owner and one controller-action bridge. RUN-138R independently verifies the committed bytes, all 41 identities, 12 source-packet expansions (seven widened existing files plus five newly followed files), nine assurance findings (six candidate plus three shared), accounting, denominators, lineage, and zero-credit boundaries. This static ownership does not establish approved-Site access, exact permissions, privacy, direct-object concealment, query or projection correctness, response minimization, lifecycle, concurrency, durability, runtime, or release correctness.

The current bounded checkpoint is **661 records = 304 routes + 357 pages across 256 canonical FEATURE-IDs (234 H + 22 D)**, with 92 controller-action bridges. Route and page owners span 63 and 242 FEATURE-IDs with 49 in their overlap. This is 16.823619% of the bounded 3,929-record source universe; 3,268 records remain. The route universe is **3,218 = 304 owners + 12 shared + 5 aliases + 2,897 residual**, with seven evidence gaps tagged inside that residual. The page universe remains **711 = 357 owners + 9 shared + 345 residual**, with one earlier evidence gap tagged inside its residual. Queue accounting is **507 = 115 reviewed + 392 pending**; reviewed rows are 93 owned, 10 shared, 5 aliases, 0 dead, and 7 evidence gaps, while 414 remain without ownership.

RUN-139 reports only that bounded one-action delta. The exact regenerated dashboard requires a fresh RUN-140 audit-artifact receipt. Oblivion Findings remains one operating organisation across multiple Sites. Framework reachability, navigation, approved-Site access, roles/permissions, canonical object ownership, direct-object concealment, privacy, query/projection/minimization/lifecycle/concurrency/durability correctness, runtime, database, build, application browser, tests, benchmarks, ease, Passes, findings, completion, and Gate 4 remain separate open or zero-credit gates. The 340-row matrix remains byte-identical and mapping remains 0/340.

"""
    summary = replace_between(
        summary,
        "## RUN-113–135 reviewed route/action and page-ownership lineage\n",
        "## Current raw source census\n",
        summary_block,
        "summary RUN139 block",
    )
    evidence_anchor = (
        f"- {tick}generators/materialize-run-135-reviewed-finance-accounting-integration-route-action-reporting-wave-21.py{tick} "
        f"and {tick}evidence/source/current-run-135-reviewed-finance-accounting-integration-route-action-reporting-wave-21.json{tick}: "
        "deterministic RUN-135 reporting refresh preserving the matrix, reports 02–12/inventory, and every downstream zero-credit boundary.\n"
    )
    links = [
        ("evidence/browser/current-audit-dashboard-verification-run-136-wave-21.json", "exact now-superseded RUN-135 dashboard artifact verification at four viewports; zero application credit"),
        ("generators/build-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.py and evidence/source/root-run-137-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.json", "exact zero-credit one-action invoice-index review cohort"),
        ("generators/materialize-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.py and evidence/source/raw-run-137r-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.json", "two independent candidate reviews plus synthesis, with 12 disclosed expansions, nine assurance findings, and no correctness credit"),
        ("generators/integrate-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.py and evidence/source/current-run-138-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json", "exact one-route and one-bridge static-only overlay"),
        ("generators/materialize-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-review-wave-22.py and evidence/source/raw-run-138r-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json", "three-part final-byte, identity, accounting, provenance, and boundary GO receipt"),
        (f"{MATERIALIZER_RELATIVE} and {OUTPUT_RELATIVE}", "deterministic RUN-139 reporting refresh preserving the matrix, reports 02–12/inventory, and every downstream zero-credit boundary"),
    ]
    additions = evidence_anchor
    for paths, description in links:
        rendered = " and ".join(f"{tick}{item}{tick}" for item in paths.split(" and "))
        additions += f"- {rendered}: {description}.\n"
    summary = replace_once(summary, evidence_anchor, additions, "summary RUN139 links")
    write_lf(summary_relative, summary)

    module_relative = "01-repository-module-map.md"
    module_map = path(module_relative).read_text(encoding="utf-8")
    module_block = """## RUN-113–139 reviewed route/action and page-ownership lineage

RUN-113/R–136 remain historical reviewed route/action, page-owner, reporting, and exact-dashboard checkpoints. RUN-133/R–136 preserve the six accounting-integration route owners and their zero-page-inheritance boundary.

RUN-137/R separately review invoices.index as one explicit route/action owner for CAP-FIN-BILLING-INVOICE-LIFECYCLE. RUN-138/R integrate and independently verify exactly one route record and one controller-action bridge with zero page, caller, sibling, feature-union, or matrix change.

The cumulative bounded ledger is 661 source owners (304 route + 357 page) across 256 FEATURE-IDs (234 H + 22 D). Route/page feature sets are 63/242 with overlap 49, and the action-bridge count is 92. Route accounting is 3,218 = 304 owners + 12 shared + 5 aliases + 2,897 residual, with seven evidence gaps tagged within residual. Page accounting remains 711 = 357 owners + 9 shared + 345 residual, with one earlier tagged evidence gap. RUN-090 queue accounting is 507 total, 115 reviewed, 93 owned, 10 shared, 5 aliases, 7 evidence gaps, 392 pending, and 414 without ownership.

These relations establish bounded static ownership only. The 12 packet expansions and nine assurance findings leave unproved approved-Site, permission, privacy, direct-object, query, projection, response-minimization, lifecycle, concurrency, and durability correctness, framework reachability, runtime, build, browser, tests, benchmarks, findings, Passes, and completion. The existing Index and Show page owners remain context only and are not recredited.

"""
    module_map = replace_between(
        module_map,
        "## RUN-113–135 reviewed route/action and page-ownership lineage\n",
        "## Candidate register\n",
        module_block,
        "module RUN139 block",
    )
    write_lf(module_relative, module_map)

    gaps_relative = "13-unresolved-questions-and-evidence-gaps.md"
    gaps = path(gaps_relative).read_text(encoding="utf-8")
    gaps = replace_line_containing(
        gaps,
        "| Required reporting paths |",
        "| Required reporting paths | 18 / 18 prompt-required files or directories present. RUN-136 independently verified the exact now-superseded RUN-135 dashboard at four viewports; the regenerated RUN-139 dashboard requires a separate fresh RUN-140 audit-artifact receipt. | Presence and prior audit-artifact verification make the reporting contract inspectable but grant no application execution, final-finding, Pass, or completion credit. Gate 26 remains open. | Complete the semantic, execution, benchmark, Pass 8, final reconciliation, and no-live-agent gates; repeat audit-dashboard verification after every later HTML change. |",
    )
    gaps = replace_line_containing(
        gaps,
        "| Runtime routes |",
        "| Runtime routes | RUN-138/R preserve 304 bounded route-owner records and 92 static controller-action bridges; 2,897 residual route rows, 12 semantic-shared route rows, and 5 reviewed aliases remain distinguished within the bounded 3,218-row static route-like universe, with 7 evidence gaps tagged inside residual. | Wave 22 adds exactly one reviewed invoices.index route owner and one bridge. Static owner/action linkage is not a framework-expanded route table, reachability proof, approved-Site/permission/privacy/direct-object proof, query/projection/minimization/lifecycle/concurrency/durability proof, or authorization proof. | Under a fresh bounded runtime grant, use an exact disposable database, execute a separately pinned invoice route lane, and reconcile it to all 38 source route files and 3,218 route-like rows. |",
    )
    gaps = replace_line_containing(
        gaps,
        "| Inertia pages |",
        "| Inertia pages | RUN-084/R enumerate 1,058 physical page-tree files. RUN-138/R preserve 357 bounded page owners, nine semantic-shared roots, and 345 residual roots including one earlier tagged evidence gap. | Wave 22 adds zero page owner and inherits or recredits neither the existing Index/Show owners nor frontend callers. Full-tree structural GO and bounded ownership are not a complete canonical crosswalk, runtime reachability, build resolution, rendered browser behaviour, or final feature mapping. | Reconcile all 711 literal roots and support relations to safely expanded framework routes and frozen FEATURE-IDs, prove build resolution, and observe every safely reachable current-build page under approved roles/Sites. |",
    )
    gaps = replace_line_containing(
        gaps,
        "| Canonical features |",
        f"| Canonical features | Static canonical identity remains 340 targets: 300 H, 40 D, zero M; RUN-138/R establish 661 bounded source-owner records (304 routes + 357 pages) across 256 FEATURE-IDs (234 H + 22 D) plus 92 controller-action bridges while the matrix remains byte-identical at {tick}{MATRIX_SHA256}{tick}. | This is 16.823619% of the bounded 3,929-record source universe. Gate 4 remains incomplete: 3,268 non-owner records, the framework-expanded denominator, shared, alias, and gap relations, reachability, and the full crosswalk remain open. The nine invoice-index assurance findings grant no final-finding credit; matrix target mapping stays 0/340. | Finish canonical denominator and residual ownership adjudication without inheriting FEATURE-IDs from prefixes, imports, containment, names, siblings, callers, or presence, and without awarding runtime, browser, test, benchmark, ease, release, or completion credit. |",
    )
    gaps = replace_line_containing(
        gaps,
        "| Agent universe and writer rule |",
        "| Agent universe and writer rule | RUN-001 through RUN-139 represented at the current reporting checkpoint; finalization gate false. | RUN-137/R review one invoice-index action as an explicit owner; RUN-138/R independently integrate and verify only one route owner and one bridge while preserving 12 packet expansions, nine assurance findings, existing page context, and every correctness boundary; RUN-139 reports only that bounded class. Runtime, browser, tests, benchmarks, Pass 8, finalization, and completion remain open. | Complete residual ownership and every semantic/execution gate, then dispatch fresh Pass 8/final cross-reviewers, verify the final dashboard, and prove no agent remains live at finalization. |",
    )
    lineage = """## RUN-077–139 route/page, page-tree, backend, ownership and reporting lineage

RUN-077 freezes the exhaustive committed-source route/name/page universe through RUN-084B's page-tree and backend structural ledgers. RUN-086/R establish the initial 530 bounded source owners; RUN-090 freezes the zero-credit direct-exact queue; RUN-091/R–136 successively review, integrate, report, and verify bounded route/action and page ownership, reaching 660 owners while preserving explicit shared, alias, and gap outcomes. RUN-137/R review invoices.index as one explicit route/action owner. RUN-138/R integrate and independently verify exactly one route owner and one controller-action bridge, preserve 12 source-packet expansions and nine assurance findings without correctness credit, and reach 661 owners; RUN-139 reports only that bounded delta. Gate 4 and the full route/page/backend crosswalk remain incomplete; Oblivion Findings remains one operating organisation across multiple Sites, and framework reachability, approved-Site/permission/privacy/direct-object/query/projection/minimization/lifecycle/concurrency/durability correctness, runtime, build, signed-in application browser, executed tests, benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit.

"""
    gaps = replace_between(
        gaps,
        "## RUN-077–135 route/page, page-tree, backend, ownership and reporting lineage\n",
        "## Current provisional source findings\n",
        lineage,
        "gaps RUN139 lineage",
    )
    write_lf(gaps_relative, gaps)

def patch_findings(overlay: dict[str, Any], review: dict[str, Any]) -> None:
    relative = "findings.json"
    findings = read_json(relative)
    counts = overlay["combined_counts"]
    queue = overlay["queue_accounting"]
    findings["pins"].update(
        {
            "run_135_reporting_sha256": PINNED_INPUTS[
                "evidence/source/current-run-135-reviewed-finance-accounting-integration-route-action-reporting-wave-21.json"
            ],
            "run_136_dashboard_verification_sha256": PINNED_INPUTS[
                "evidence/browser/current-audit-dashboard-verification-run-136-wave-21.json"
            ],
            "run_137_finance_invoice_index_cohort_generator_sha256": PINNED_INPUTS[
                "generators/build-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.py"
            ],
            "run_137_finance_invoice_index_cohort_sha256": PINNED_INPUTS[
                "evidence/source/root-run-137-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.json"
            ],
            "run_137r_finance_invoice_index_review_materializer_sha256": PINNED_INPUTS[
                "generators/materialize-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.py"
            ],
            "run_137r_finance_invoice_index_review_sha256": PINNED_INPUTS[
                "evidence/source/raw-run-137r-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.json"
            ],
            "run_138_finance_invoice_index_overlay_generator_sha256": PINNED_INPUTS[
                "generators/integrate-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.py"
            ],
            "run_138_finance_invoice_index_overlay_sha256": PINNED_INPUTS[
                "evidence/source/current-run-138-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json"
            ],
            "run_138r_finance_invoice_index_overlay_review_materializer_sha256": PINNED_INPUTS[
                "generators/materialize-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-review-wave-22.py"
            ],
            "run_138r_finance_invoice_index_overlay_review_sha256": PINNED_INPUTS[
                "evidence/source/raw-run-138r-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json"
            ],
            "run_139_reporting_materializer_sha256": sha256_file(MATERIALIZER_RELATIVE),
        }
    )
    findings["counts"].update(
        {
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
            "direct_exact_queue_dead_or_noncanonical": queue["dead_queue_surface_rows"],
            "direct_exact_queue_evidence_gap": queue["evidence_gap_queue_surface_rows"],
            "direct_exact_queue_pending_unreviewed": queue["pending_unreviewed_queue_surface_rows"],
            "direct_exact_queue_without_ownership": queue["queue_surfaces_without_ownership"],
        }
    )

    if "historical_run_134_outcome_neutral_finance_accounting_integration_route_action_ownership" not in findings:
        findings[
            "historical_run_134_outcome_neutral_finance_accounting_integration_route_action_ownership"
        ] = copy.deepcopy(findings["current_static_source_feature_ownership"])
    if "historical_run_134_outcome_neutral_finance_accounting_integration_route_action_ownership_review" not in findings:
        findings[
            "historical_run_134_outcome_neutral_finance_accounting_integration_route_action_ownership_review"
        ] = copy.deepcopy(
            findings[
                "current_outcome_neutral_finance_accounting_integration_route_action_ownership_review"
            ]
        )
    findings.pop(
        "current_outcome_neutral_finance_accounting_integration_route_action_ownership_review",
        None,
    )

    feature_distribution: dict[str, int] = {}
    for row in overlay["overlay_source_records"]:
        feature_distribution[row["feature_id"]] = (
            feature_distribution.get(row["feature_id"], 0) + 1
        )
    findings["current_static_source_feature_ownership"] = {
        "run_id": overlay["run_id"],
        "review_run_id": review["run_id"],
        "status": "GO_REVIEWED_BOUNDED_OUTCOME_NEUTRAL_FINANCE_INVOICE_INDEX_ROUTE_ACTION_OWNERSHIP_ONLY",
        "baseline_records": overlay["baseline"]["source_owner_records"],
        "reviewed_route_actions": overlay["reviewed_overlay"]["reviewed_route_actions"],
        "owner_route_actions": overlay["reviewed_overlay"]["owner_route_actions"],
        "overlay_source_records": len(overlay["overlay_source_records"]),
        "route_owner_records_added": overlay["reviewed_overlay"]["accepted_route_owner_records"],
        "page_owner_records_added": overlay["reviewed_overlay"]["accepted_page_owner_records"],
        "controller_action_bridges_added": overlay["reviewed_overlay"]["accepted_controller_action_bridges"],
        "shared_relations": overlay["reviewed_overlay"]["shared_relations"],
        "reviewed_alias_or_redirect": overlay["reviewed_overlay"]["alias_or_redirect"],
        "dead_or_noncanonical": overlay["reviewed_overlay"]["dead_or_noncanonical"],
        "evidence_gaps": overlay["reviewed_overlay"]["evidence_gaps"],
        "accepted_distinct_feature_ids": overlay["reviewed_overlay"]["accepted_distinct_feature_ids"],
        "new_distinct_feature_ids": overlay["reviewed_overlay"]["new_distinct_feature_ids"],
        "new_route_feature_ids": overlay["reviewed_overlay"]["new_route_feature_ids"],
        "new_page_feature_ids": overlay["reviewed_overlay"]["new_page_feature_ids"],
        "feature_owner_distribution": feature_distribution,
        "reviewed_non_owner_records_preserved": len(overlay["reviewed_non_owner_outcomes"]),
        "combined_counts": counts,
        "queue_accounting": queue,
        "page_context_boundary": overlay["page_context_boundary"],
        "noninheritance_boundary": overlay["noninheritance_boundary"],
        "ownership_basis": "FRESH_COMPLETE_ACTION_REVIEW_NOT_PAGE_CALLER_SIBLING_OR_NAVIGATION_INHERITANCE",
        "identity": overlay["identity"],
        "outcome_conservation": overlay["outcome_conservation"],
        "projection_reconciliation": overlay["projection_reconciliation"],
        "source_packet_expansion_preservation": overlay["source_packet_expansion_preservation"],
        "assurance_findings_preservation": overlay["assurance_findings_preservation"],
        "independent_review_discrepancies": 0,
        "gate_4": {
            "status": "PARTIAL_BOUNDED_STATIC_SOURCE_OWNERSHIP_INCOMPLETE",
            "complete": False,
        },
        "credit_boundary": overlay["credit_boundary"],
    }
    findings[
        "current_outcome_neutral_finance_invoice_index_route_action_ownership_review"
    ] = {
        "run_id": review["run_id"],
        "status": review["status"],
        "reviewers": len(review["reviewers"]),
        "mechanical_source_blob_checks": review["decision"]["mechanical_source_blob_checks_reported"],
        "mechanical_identity_fields_recomputed": review["decision"]["mechanical_identity_fields_recomputed"],
        "lineage_identity_fields_recomputed": review["decision"]["lineage_identity_fields_recomputed"],
        "input_hashes_verified": review["decision"]["input_hashes_verified"],
        "route_owner_records_verified": review["decision"]["route_owner_records_verified"],
        "controller_action_bridges_verified": review["decision"]["controller_action_bridges_verified"],
        "page_owner_records_verified": review["decision"]["page_owner_records_verified"],
        "source_packet_expansion_records_verified": review["decision"]["source_packet_expansion_records_verified"],
        "assurance_findings_verified": review["decision"]["assurance_findings_verified"],
        "published_identity_fields_verified": review["decision"]["published_identity_fields_verified"],
        "mechanical_discrepancies": review["decision"]["mechanical_discrepancies"],
        "semantic_or_preservation_discrepancies": review["decision"]["semantic_or_preservation_discrepancies"],
        "lineage_or_credit_discrepancies": review["decision"]["lineage_or_credit_discrepancies"],
        "arithmetic_identity_or_denominator_discrepancies": review["decision"]["arithmetic_identity_or_denominator_discrepancies"],
        "byte_provenance_or_credit_discrepancies": review["decision"]["byte_provenance_or_credit_discrepancies"],
        "reporting_materialization_authorized": review["decision"]["reporting_materialization_authorized"],
        "correctness_or_downstream_credit_authorized": False,
        "gate_4_complete": False,
        "completion_credit": False,
        "credit_boundary": review["credit_boundary"],
    }
    findings["current_direct_exact_route_page_review_queue"].update(
        {
            "reconciled_through_overlay_run_id": overlay["run_id"],
            "reconciled_through_review_run_id": review["run_id"],
            "records": queue["direct_exact_queue_records"],
            "reviewed_queue_surfaces": queue["reviewed_queue_surface_rows"],
            "owned_queue_surfaces": queue["owner_queue_surface_rows"],
            "shared_queue_surfaces": queue["shared_queue_surface_rows"],
            "alias_queue_surfaces": queue["alias_queue_surface_rows"],
            "dead_or_noncanonical_queue_surfaces": queue["dead_queue_surface_rows"],
            "evidence_gap_queue_surfaces": queue["evidence_gap_queue_surface_rows"],
            "pending_unreviewed": queue["pending_unreviewed_queue_surface_rows"],
            "without_ownership": queue["queue_surfaces_without_ownership"],
            "wholesale_ownership_authorized": False,
        }
    )

    run136 = read_json(
        "evidence/browser/current-audit-dashboard-verification-run-136-wave-21.json"
    )
    verification = run136["verification"]
    findings["current_audit_artifact_verification_history"]["run_136"] = {
        "status": run136["status"],
        "dashboard_sha256": run136["pins"]["dashboard_html_sha256"],
        "receipt_sha256": PINNED_INPUTS[
            "evidence/browser/current-audit-dashboard-verification-run-136-wave-21.json"
        ],
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
    assert canonical_list_sha256([row["id"] for row in findings["records"]]) == RECORD_ID_LIST_SHA256
    assert len(findings["records"]) == 12
    assert findings["counts"]["provisional_P1"] == 12
    assert findings["counts"]["final_P0"] == findings["counts"]["final_P1"] == 0
    assert findings["counts"]["benchmark_mapped"] == findings["counts"]["final_no_match"] == 0
    assert 3929 == counts["source_owner_records"] + counts["bounded_static_source_residual_records"]
    assert counts["source_owner_records"] == counts["route_owner_records"] + counts["page_owner_records"]
    assert 3218 == counts["route_owner_records"] + counts["semantic_shared_routes"] + counts["reviewed_alias_routes"] + counts["reviewed_dead_routes"] + counts["residual_explicit_unmapped_routes"]
    assert 711 == counts["page_owner_records"] + counts["semantic_shared_page_roots"] + counts["reviewed_alias_page_roots"] + counts["reviewed_dead_page_roots"] + counts["residual_unadjudicated_page_roots"]
    assert queue["direct_exact_queue_records"] == queue["reviewed_queue_surface_rows"] + queue["pending_unreviewed_queue_surface_rows"]
    assert queue["reviewed_queue_surface_rows"] == queue["owner_queue_surface_rows"] + queue["shared_queue_surface_rows"] + queue["alias_queue_surface_rows"] + queue["dead_queue_surface_rows"] + queue["evidence_gap_queue_surface_rows"]
    assert queue["queue_surfaces_without_ownership"] == queue["pending_unreviewed_queue_surface_rows"] + queue["shared_queue_surface_rows"] + queue["alias_queue_surface_rows"] + queue["dead_queue_surface_rows"] + queue["evidence_gap_queue_surface_rows"]
    write_lf(relative, json.dumps(findings, ensure_ascii=False, indent=2) + "\n")

def patch_dashboard_template() -> None:
    relative = "generators/build-current-audit-dashboard.py"
    text = path(relative).read_text(encoding="utf-8")
    run139_materializer_sha256 = sha256_file(MATERIALIZER_RELATIVE)

    read_anchor = (
        'reviewed_finance_accounting_integration_overlay_review = read_json('
        '"evidence/source/raw-run-134r-independent-reviewed-outcome-neutral-finance-'
        'accounting-integration-route-action-ownership-overlay-wave-21.json")\n'
    )
    read_addition = read_anchor + (
        'finance_invoice_index_cohort = read_json("evidence/source/root-run-137-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.json")\n'
        'finance_invoice_index_review = read_json("evidence/source/raw-run-137r-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.json")\n'
        'reviewed_finance_invoice_index_overlay = read_json("evidence/source/current-run-138-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json")\n'
        'reviewed_finance_invoice_index_overlay_review = read_json("evidence/source/raw-run-138r-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json")\n'
    )
    text = replace_once(text, read_anchor, read_addition, "dashboard RUN137-138 reads")

    assertion_anchor = (
        'assert {key for key, value in reviewed_finance_accounting_integration_overlay_review["credit_boundary"].items() if value} == {"INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"}\n'
    )
    assertion_addition = assertion_anchor + f'''assert sha256_file("generators/materialize-run-135-reviewed-finance-accounting-integration-route-action-reporting-wave-21.py") == "8f0590834cc8d1f64bc0ac2cd1bc53f88ab1a3b161147863f3def389777dddad"
assert sha256_file("evidence/source/current-run-135-reviewed-finance-accounting-integration-route-action-reporting-wave-21.json") == "af70461527e7b22855b0a7917121112ca973fe4e88450b6b87ef0b5ae39d99da"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-136-wave-21.json") == "24838333225819640bc767d7f5149aaaadcfa11377e4035e985af314fc549d1e"
assert sha256_file("generators/build-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.py") == "93766689117c88173a08f8548a04d7e62f00eadf71fb7fefa302936e540c9bd9"
assert sha256_file("evidence/source/root-run-137-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.json") == "e2a6a346365ada6013b82f4e29aa955ffcedf7f3b53ab88279c700407d3012bc"
assert sha256_file("generators/materialize-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.py") == "4a4eb33dd34832b2182bfe27bf13f90f3a30e7406b74552e82dda2f0c73b99c5"
assert sha256_file("evidence/source/raw-run-137r-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.json") == "a3659294a8d2f9c203968a885da7b48f928d5341dbcb2b177eb85b40a058411f"
assert sha256_file("generators/integrate-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.py") == "76f9f34a249b901e4448166155eb0e5a314390bebfc90c6d28f5df08c1cb6baf"
assert sha256_file("evidence/source/current-run-138-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json") == "005a55c952ec3f3b2a5bac9f3c99000fa4eae65a488764dfd1f4662063431701"
assert sha256_file("generators/materialize-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-review-wave-22.py") == "867cf33924bcaf7cf34fa5d22c0a99a920d75f2255ef437adeca1e0a9734af3f"
assert sha256_file("evidence/source/raw-run-138r-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json") == "befb7b4463b588d7ebbb9e42c6c7b34bf02de78b962628f0c75f423c2b7b5e31"
assert sha256_file("generators/materialize-run-139-reviewed-finance-invoice-index-route-action-reporting-wave-22.py") == "{run139_materializer_sha256}"
assert finance_invoice_index_cohort["counts"]["candidate_route_actions"] == 1
assert finance_invoice_index_cohort["counts"]["ownership_credit_awarded"] == 0
assert finance_invoice_index_review["decision"]["verdict"] == "GO_ACCEPT_1_OWNER_ROUTE_ACTION_FOR_BOUNDED_LATER_INTEGRATION"
assert finance_invoice_index_review["decision"]["current_overlay_credit_awarded"] is False
assert reviewed_finance_invoice_index_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_finance_invoice_index_overlay_review["decision"]["reporting_materialization_authorized"] is True
assert reviewed_finance_invoice_index_overlay_review["decision"]["gate_4_complete"] is False
assert reviewed_finance_invoice_index_overlay_review["verified_identity"] == reviewed_finance_invoice_index_overlay["identity"]
assert len(reviewed_finance_invoice_index_overlay_review["verified_identity"]) == 41
assert len(reviewed_finance_invoice_index_overlay["overlay_source_records"]) == 1
assert len(reviewed_finance_invoice_index_overlay["new_static_controller_action_bridges"]) == 1
assert reviewed_finance_invoice_index_overlay["reviewed_non_owner_outcomes"] == []
invoice_counts = reviewed_finance_invoice_index_overlay["combined_counts"]
invoice_queue = reviewed_finance_invoice_index_overlay["queue_accounting"]
assert (invoice_counts["source_owner_records"], invoice_counts["route_owner_records"], invoice_counts["page_owner_records"]) == (661, 304, 357)
assert (invoice_counts["distinct_feature_ids"], invoice_counts["distinct_H_feature_ids"], invoice_counts["distinct_D_feature_ids"]) == (256, 234, 22)
assert (invoice_counts["route_distinct_feature_ids"], invoice_counts["page_distinct_feature_ids"], invoice_counts["route_page_feature_overlap"]) == (63, 242, 49)
assert (invoice_counts["static_controller_action_bridges"], invoice_counts["bounded_static_source_residual_records"]) == (92, 3268)
assert invoice_counts["bounded_static_source_ownership_percent"] == "16.823619"
assert (invoice_counts["residual_explicit_unmapped_routes"], invoice_counts["semantic_shared_routes"], invoice_counts["reviewed_alias_routes"], invoice_counts["evidence_gap_routes_tagged_within_residual"]) == (2897, 12, 5, 7)
assert (invoice_counts["residual_unadjudicated_page_roots"], invoice_counts["semantic_shared_page_roots"], invoice_counts["evidence_gap_page_roots_tagged_within_residual"]) == (345, 9, 1)
assert (invoice_queue["direct_exact_queue_records"], invoice_queue["reviewed_queue_surface_rows"], invoice_queue["owner_queue_surface_rows"], invoice_queue["shared_queue_surface_rows"], invoice_queue["alias_queue_surface_rows"], invoice_queue["dead_queue_surface_rows"], invoice_queue["evidence_gap_queue_surface_rows"], invoice_queue["pending_unreviewed_queue_surface_rows"], invoice_queue["queue_surfaces_without_ownership"]) == (507, 115, 93, 10, 5, 0, 7, 392, 414)
assert reviewed_finance_invoice_index_overlay["source_packet_expansion_preservation"]["total_disclosed_expansion_entries"] == 12
assert reviewed_finance_invoice_index_overlay["source_packet_expansion_preservation"]["widened_existing_packet_files"] == 7
assert reviewed_finance_invoice_index_overlay["source_packet_expansion_preservation"]["newly_followed_files"] == 5
assert reviewed_finance_invoice_index_overlay["assurance_findings_preservation"]["candidate_findings"] == 6
assert reviewed_finance_invoice_index_overlay["assurance_findings_preservation"]["shared_findings"] == 3
assert reviewed_finance_invoice_index_overlay["assurance_findings_preservation"]["total_findings"] == 9
assert reviewed_finance_invoice_index_overlay["page_context_boundary"]["existing_page_owner_context_rows"] == 2
assert reviewed_finance_invoice_index_overlay["page_context_boundary"]["new_page_owner_records"] == 0
assert reviewed_finance_invoice_index_overlay["page_context_boundary"]["page_ownership_inherited"] is False
assert reviewed_finance_invoice_index_overlay["page_context_boundary"]["page_ownership_reassigned"] is False
assert reviewed_finance_invoice_index_overlay["noninheritance_boundary"]["next_queue_index_zero_based"] == 78
assert reviewed_finance_invoice_index_overlay["noninheritance_boundary"]["next_boundary_selected_or_credited"] is False
assert {{key for key, value in reviewed_finance_invoice_index_overlay["credit_boundary"].items() if value}} == {{"STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD", "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION"}}
assert {{key for key, value in reviewed_finance_invoice_index_overlay_review["credit_boundary"].items() if value}} == {{"INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"}}
assert 3929 == 661 + 3268
assert 661 == 304 + 357
assert 3218 == 304 + 12 + 5 + 2897
assert 711 == 357 + 9 + 345
'''
    text = replace_once(text, assertion_anchor, assertion_addition, "dashboard RUN135-139 assertions")

    evidence_anchor = (
        '    ("RUN-135 accounting-integration reporting/hash receipt", '
        '"evidence/source/current-run-135-reviewed-finance-accounting-integration-route-action-reporting-wave-21.json"),\n'
    )
    evidence_addition = evidence_anchor + (
        '    ("RUN-136 verified superseded dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-136-wave-21.json"),\n'
        '    ("RUN-137 invoice-index route/action cohort generator", "generators/build-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.py"),\n'
        '    ("RUN-137 one-action invoice-index cohort", "evidence/source/root-run-137-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.json"),\n'
        '    ("RUN-137R invoice-index semantic-review materializer", "generators/materialize-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.py"),\n'
        '    ("RUN-137R one-owner invoice-index action review", "evidence/source/raw-run-137r-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.json"),\n'
        '    ("RUN-138 invoice-index route/action overlay generator", "generators/integrate-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.py"),\n'
        '    ("RUN-138 one-route one-bridge invoice-index overlay", "evidence/source/current-run-138-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json"),\n'
        '    ("RUN-138R independent invoice-index overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-review-wave-22.py"),\n'
        '    ("RUN-138R final-byte identity accounting and boundary review", "evidence/source/raw-run-138r-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json"),\n'
        '    ("RUN-139 invoice-index reporting materializer", "generators/materialize-run-139-reviewed-finance-invoice-index-route-action-reporting-wave-22.py"),\n'
        '    ("RUN-139 invoice-index reporting/hash receipt", "evidence/source/current-run-139-reviewed-finance-invoice-index-route-action-reporting-wave-22.json"),\n'
    )
    text = replace_once(text, evidence_anchor, evidence_addition, "dashboard RUN136-139 evidence")

    text = replace_once(text, 'href="#checkpoint">RUN-135</a>', 'href="#checkpoint">RUN-139</a>', "dashboard nav")
    text = replace_once(text, "RUN-071–135 current reporting checkpoint:", "RUN-071–139 current reporting checkpoint:", "dashboard notice heading")
    text = replace_once(text, "RUN-071–135 completion-gate checkpoint", "RUN-071–139 completion-gate checkpoint", "dashboard checkpoint heading")
    text = replace_once(text, "RUN-001 through RUN-135 are represented", "RUN-001 through RUN-139 are represented", "dashboard agent universe")
    text = replace_once(text, "RUN-071–135 evidence lineage", "RUN-071–139 evidence lineage", "dashboard evidence lineage heading")
    text = replace_once(text, "RUN-077–135 source/reporting artifact", "RUN-077–139 source/reporting artifact", "dashboard evidence lineage text")

    text = replace_between(
        text,
        "RUN-101/R–132 remain historical route/action, page-owner, reporting, and exact dashboard checkpoints.",
        "</div>\n    <div class=\"notice\"",
        """RUN-101/R–136 remain historical route/action, page-owner, reporting, and exact dashboard checkpoints. RUN-137/R review invoices.index as one explicit route/action owner; RUN-138/R establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges. Exactly one route owner and one bridge are added; the two existing page owners and frontend callers are context only, route/page/overlap sets are $route_feature_ids/$page_feature_ids/$route_page_overlap, and all nine assurance findings retain zero correctness credit. $static_residual records remain and Gate 4 is open. Oblivion Findings remains one operating organisation across multiple Sites; Site access, roles/permissions, canonical ownership, direct-object denial, privacy, query/projection/minimization, and lifecycle correctness remain separate unproved gates. The live matrix is unchanged at <span class="mono">$route_page_matrix_short</span>, mapping remains 0/340, and current-source framework reachability, runtime, browser, build, rendered visual, executed-test, benchmark, ease, release, Pass, and audit-completion credit remain zero.""",
        "dashboard top notice",
    )
    text = replace_between(
        text,
        "RUN-113/R–132 preserve historical route/action and page-owner checkpoints with exact dashboard receipts.",
        "</div>\n    <section id=\"progress\"",
        """RUN-113/R–136 preserve historical route/action and page-owner checkpoints with exact dashboard receipts. RUN-137/R–138/R add one invoices.index route owner and one bridge, inherit or recredit no page or caller ownership, and add zero union or matrix change; $queue_reviewed queue rows are reviewed, $queue_pending remain pending, and $queue_without_owner remain without ownership. RUN-139 reports only that bounded delta. The framework-expanded denominator, residual ownership, and full route/page/backend crosswalk remain open. Every execution, benchmark, Pass, finding, and completion credit remains zero.""",
        "dashboard second notice",
    )
    text = replace_between(
        text,
        "RUN-113/R–132 preserve historical reviewed route/action and page-owner checkpoints with exact dashboard receipts;",
        " Static relation, structural classification",
        """RUN-113/R–136 preserve historical reviewed route/action and page-owner checkpoints with exact dashboard receipts; RUN-137/R–138/R independently review, integrate, and verify one invoices.index route owner plus one bridge while preserving 12 packet expansions, nine assurance findings, two existing page-owner contexts without recredit, and every correctness boundary, and RUN-139 refreshes current reporting.""",
        "dashboard checkpoint narrative",
    )

    progress_replacement = (
        '<tr><td>RUN-133/R → 136 historical accounting-integration route/action overlay</td><td><strong>$finance_accounting_wave_reviewed reviewed = $finance_accounting_review_owner owner actions · 6 route rows · 6 bridges · 0 page rows</strong></td><td class="partial">660 cumulative owners · exact superseded dashboard verified</td></tr>'
        '<tr><td>RUN-137/R → 138/R current invoice-index route/action overlay</td><td><strong>$finance_invoice_wave_reviewed reviewed = $finance_invoice_review_owner owner action · 1 route row · 1 bridge · 0 page rows</strong></td><td class="partial">$static_owner_records cumulative owners · $static_owner_routes routes + $static_owner_pages pages · Gate 4 incomplete</td></tr>'
        '<tr><td>RUN-139 reporting refresh</td><td><strong>invoice-index route/action overlay reported</strong></td><td class="partial">audit-only materialization · matrix byte-identical · fresh RUN-140 verification required</td></tr>'
    )
    text = replace_between(
        text,
        '<tr><td>RUN-133/R → 134/R current accounting-integration route/action overlay',
        '</tbody></table></div>',
        progress_replacement,
        "dashboard progress rows",
    )

    bullet_replacement = (
        '<li>RUN-133/R–136: historical accounting-integration review, integration, reporting, and exact superseded dashboard verification · six route rows and six bridges · zero correctness credit</li>'
        '<li>RUN-137/R: one invoices.index action · one explicit route/action owner · 12 packet expansions and nine assurance findings retained without correctness credit</li>'
        '<li>RUN-138/R: one route row and one bridge integrated and independently verified · zero page/caller/sibling inheritance · $static_owner_records cumulative owner records</li>'
        '<li>RUN-139: deterministic invoice-index reporting refresh · matrix and every Site/permission/privacy/direct-object/query/projection/minimization/lifecycle/concurrency/durability/execution/benchmark/Pass/finding/completion boundary unchanged</li>'
    )
    text = replace_between(
        text,
        "<li>RUN-133/R:",
        "</ul></section><section class=\"panel\"><h2>Execution credit",
        bullet_replacement,
        "dashboard bullets",
    )

    text = replace_between(
        text,
        '<section id="static-census" class="panel"><h2>Expanded static coverage wave</h2><p>',
        '<div class="table-wrap">',
        """<section id="static-census" class="panel"><h2>Expanded static coverage wave</h2><p>RUN-030 freezes canonical static identity; RUN-077–084B add exhaustive committed static route/name/page, full page-tree, and backend structural evidence; RUN-086/R establish the initial bounded ownership, RUN-090–092/R add the independently reviewed closed-chain overlay, RUN-097/R–136 preserve historical route/action and page-owner checkpoints with exact dashboard receipts; RUN-137/R–138/R add one independently reviewed invoices.index route owner and one bridge while preserving 12 packet expansions, nine assurance findings, two existing page-owner contexts without recredit, and every correctness boundary, and RUN-139 refreshes reporting. Rendered coverage, schema truth, runtime, benchmark, ease, release, and completion gates remain open.</p>""",
        "dashboard static intro",
    )

    current_row = (
        '<tr><td>RUN-138/R current Finance route/action and page ownership</td>'
        '<td>$static_owner_records = $static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs = $static_owner_h_features H + $static_owner_d_features D · $static_action_bridges action bridges</td>'
        '<td class="partial">$ownership_percent% of bounded 3,929 · $static_residual residual · features $route_feature_ids route / $page_feature_ids page / $route_page_overlap overlap · routes 3,218 = $static_owner_routes owner + $route_shared_current shared + $route_alias_current alias + $route_residual residual with $finance_invoice_route_gap tagged gaps · pages 711 = $static_owner_pages owner + $page_shared shared + $page_residual residual with $page_gap tagged gap · invoice-index wave $finance_invoice_wave_reviewed = $finance_invoice_review_owner owner · 1 route row + 1 bridge · page context $finance_invoice_page_calls literal Inertia callsite / $finance_invoice_existing_pages existing page-owner contexts / $finance_invoice_frontend_contexts static caller contexts / $finance_invoice_page_owners_added new owners / inherited=$finance_invoice_page_inherited / reassigned=$finance_invoice_page_reassigned · 12 packet expansions (7 existing + 5 new) · 9 assurance findings (6 + 3) · zero correctness credit · Gate 4 incomplete · matrix unchanged</td></tr>'
    )
    text = replace_between(
        text,
        '<tr><td>RUN-134/R current Finance route/action and page ownership',
        '<tr><td>RUN-090 direct-exact queue',
        current_row,
        "dashboard current row",
    )

    text = replace_between(
        text,
        "<li>RUN-134/R establish",
        "</li><li>RUN-080 retained matrix gaps",
        """<li>RUN-138/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges while adding one invoices.index route owner and one bridge, preserving 12 packet expansions and nine assurance findings, inheriting or recrediting no page/caller ownership, and adding zero feature-union or matrix change; complete the framework-expanded canonical route/page denominator, $static_residual non-owner records including $route_shared_current shared routes, $route_alias_current alias routes, and $route_residual residual routes plus $page_shared shared pages and $page_gap tagged gap within $page_residual residual pages, the full crosswalk, and route reachability before Gate 4 can close""",
        "dashboard gaps",
    )

    text = replace_once(
        text,
        "RUN-120, RUN-124, RUN-128, and RUN-132 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-135.",
        "RUN-120, RUN-124, RUN-128, RUN-132, and RUN-136 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-139.",
        "dashboard prior verification text",
    )
    run132_link = '<li><a href="evidence/browser/current-audit-dashboard-verification-run-132-wave-20.json">Superseded RUN-132 verification GO</a></li>'
    text = replace_once(
        text,
        run132_link,
        run132_link + '<li><a href="evidence/browser/current-audit-dashboard-verification-run-136-wave-21.json">Superseded RUN-136 verification GO</a></li>',
        "dashboard prior RUN136 link",
    )

    fresh_replacement = (
        '<section class="panel"><h2>Fresh RUN-140 audit-dashboard verification</h2>'
        '<p>The exact regenerated RUN-139 dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844. '
        'The linked RUN-140 receipt must record page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 661/304/357 ownership, one invoices.index route/action owner and one bridge, 63/242/49 route/page/overlap feature sets, 92 cumulative bridges, route 3,218=304+12+5+2,897 with seven tagged gaps, page 711=357+9+345 with one tagged gap, queue 507=115+392 with 115=93+10+5+7 and 414 without ownership, 3,268 residual records, 12 packet expansions (seven existing plus five new), nine assurance findings (six plus three) with zero final-finding credit, two existing page-owner contexts without recredit, one operating organisation across multiple Sites, Gate 4 open, mapping 0/340, and all zero-credit boundaries. '
        'It verifies the audit artifact only and grants no application-browser, responsive-application, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p>'
        '<ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-140-wave-22.json">RUN-140 responsive audit-dashboard verification receipt</a></li></ul></section>\n    '
    )
    text = replace_between(
        text,
        '<section class="panel"><h2>Fresh RUN-136 audit-dashboard verification</h2>',
        '<section class="panel"><h2>RUN-071–139 evidence lineage</h2>',
        fresh_replacement,
        "dashboard fresh RUN140",
    )

    text = replace_once(
        text,
        "Generated deterministically from independently reviewed static evidence through RUN-134/R and reported in RUN-135.",
        "Generated deterministically from independently reviewed static evidence through RUN-138/R and reported in RUN-139.",
        "dashboard footer",
    )
    text = replace_once(text, ".tmp-run135-dashboard", ".tmp-run139-dashboard", "dashboard temp name")

    marker = "dashboard = TEMPLATE.substitute("
    prefix, separator, suffix = text.partition(marker)
    assert separator
    suffix = suffix.replace(
        'reviewed_finance_accounting_integration_overlay["combined_counts"]',
        'reviewed_finance_invoice_index_overlay["combined_counts"]',
    )
    suffix = suffix.replace(
        'reviewed_finance_accounting_integration_overlay["queue_accounting"]',
        'reviewed_finance_invoice_index_overlay["queue_accounting"]',
    )
    suffix = suffix.replace(
        "reviewed_finance_accounting_integration_overlay['combined_counts']",
        "reviewed_finance_invoice_index_overlay['combined_counts']",
    )
    suffix = suffix.replace(
        "reviewed_finance_accounting_integration_overlay['queue_accounting']",
        "reviewed_finance_invoice_index_overlay['queue_accounting']",
    )
    substitution_anchor = (
        '    finance_accounting_route_gap=reviewed_finance_invoice_index_overlay["combined_counts"]["evidence_gap_routes_tagged_within_residual"],\n'
    )
    substitution_addition = substitution_anchor + (
        '    finance_invoice_wave_reviewed=reviewed_finance_invoice_index_overlay["reviewed_overlay"]["reviewed_route_actions"],\n'
        '    finance_invoice_review_owner=reviewed_finance_invoice_index_overlay["reviewed_overlay"]["owner_route_actions"],\n'
        '    finance_invoice_page_calls=reviewed_finance_invoice_index_overlay["page_context_boundary"]["literal_inertia_page_callsites"],\n'
        '    finance_invoice_existing_pages=reviewed_finance_invoice_index_overlay["page_context_boundary"]["existing_page_owner_context_rows"],\n'
        '    finance_invoice_frontend_contexts=reviewed_finance_invoice_index_overlay["page_context_boundary"]["frontend_static_path_contexts"],\n'
        '    finance_invoice_page_owners_added=reviewed_finance_invoice_index_overlay["page_context_boundary"]["new_page_owner_records"],\n'
        '    finance_invoice_page_inherited=reviewed_finance_invoice_index_overlay["page_context_boundary"]["page_ownership_inherited"],\n'
        '    finance_invoice_page_reassigned=reviewed_finance_invoice_index_overlay["page_context_boundary"]["page_ownership_reassigned"],\n'
        '    finance_invoice_route_gap=reviewed_finance_invoice_index_overlay["combined_counts"]["evidence_gap_routes_tagged_within_residual"],\n'
    )
    suffix = replace_once(
        suffix,
        substitution_anchor,
        substitution_addition,
        "dashboard invoice substitutions",
    )
    text = prefix + marker + suffix
    write_lf(relative, text)

def main() -> None:
    overlay, review = assert_inputs()
    patch_reports()
    patch_findings(overlay, review)
    patch_dashboard_template()

    for relative, expected in PRESERVED.items():
        assert sha256_file(relative) == expected, relative
    assert sha256_file("audit-dashboard.html") == CURRENT_DASHBOARD_SHA256
    assert not git("status", "--porcelain", "--", "app", "routes", "resources/js", "tests")

    outputs = {relative: sha256_file(relative) for relative in CURRENT_REPORT_INPUTS}
    receipt = {
        "schema_version": SCHEMA_VERSION,
        "run_id": RUN_ID,
        "status": "REVIEWED_FINANCE_INVOICE_INDEX_ROUTE_ACTION_OVERLAY_REPORTED_GATE_4_INCOMPLETE",
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
            "tests_tree": TESTS_TREE,
            "matrix_sha256": MATRIX_SHA256,
            "materializer_sha256": sha256_file(MATERIALIZER_RELATIVE),
            "overlay_sha256": PINNED_INPUTS[
                "evidence/source/current-run-138-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json"
            ],
            "independent_overlay_review_sha256": PINNED_INPUTS[
                "evidence/source/raw-run-138r-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json"
            ],
            "superseded_dashboard_verification_sha256": PINNED_INPUTS[
                "evidence/browser/current-audit-dashboard-verification-run-136-wave-21.json"
            ],
            "superseded_dashboard_html_sha256": CURRENT_DASHBOARD_SHA256,
        },
        "inputs": {**CURRENT_REPORT_INPUTS, **PINNED_INPUTS},
        "outputs": outputs,
        "preserved": PRESERVED,
        "counts": {
            **overlay["combined_counts"],
            **overlay["queue_accounting"],
            "reviewed_finance_invoice_index_route_actions": 1,
            "reviewed_owner_route_actions_added": 1,
            "reviewed_shared_relations": 0,
            "reviewed_alias_or_redirect": 0,
            "reviewed_dead_or_noncanonical": 0,
            "reviewed_evidence_gaps": 0,
            "reviewed_non_owner_rows_preserved": 0,
            "route_owner_records_added": 1,
            "controller_action_bridges_added": 1,
            "page_owner_records_added": 0,
            "direct_exact_queue_rows_added": 1,
            "accepted_distinct_feature_ids": 1,
            "new_distinct_feature_ids": 0,
            "new_route_feature_ids": 1,
            "new_page_feature_ids": 0,
            "source_packet_expansion_records_preserved": 12,
            "source_packet_expansion_existing_files": 7,
            "source_packet_expansion_new_files": 5,
            "assurance_candidate_findings_preserved": 6,
            "assurance_shared_findings_preserved": 3,
            "assurance_findings_preserved": 9,
            "provisional_finding_records": 12,
            "final_findings": 0,
            "matrix_rows_changed": 0,
            "matrix_cells_changed": 0,
        },
        "checks": {
            "run_137r_review_go": True,
            "run_137r_outcome_conservation": "1=1+0+0+0+0",
            "run_138r_overlay_review_go": True,
            "all_discrepancy_classes_zero": True,
            "published_identity_fields_verified": 41,
            "source_packet_expansion_records_preserved": 12,
            "source_packet_expansion_partition": "12=7+5",
            "assurance_findings_preserved": 9,
            "assurance_finding_partition": "9=6+3",
            "route_owner_records_added": 1,
            "controller_action_bridges_added": 1,
            "page_owner_records_added": 0,
            "direct_exact_queue_rows_added": 1,
            "existing_page_or_caller_inheritance_used": False,
            "next_queue_boundary_selected_or_credited": False,
            "matrix_byte_identical": True,
            "matrix_mapping_credit": "0/340",
            "reports_02_through_12_inventory_preserved": True,
            "canonical_provisional_finding_record_semantics_preserved": True,
            "canonical_provisional_findings": 12,
            "application_source_paths_written": 0,
            "one_organisation_multi_site_architecture_preserved": True,
            "dashboard_requires_fresh_run_140_artifact_verification": True,
            "gate_4_complete": False,
        },
        "verified_identity": overlay["identity"],
        "verified_outcome_conservation": overlay["outcome_conservation"],
        "verified_projection_reconciliation": overlay["projection_reconciliation"],
        "verified_denominator_boundary": overlay["denominator_boundary"],
        "verified_page_context_boundary": overlay["page_context_boundary"],
        "verified_noninheritance_boundary": overlay["noninheritance_boundary"],
        "verified_source_packet_expansion_preservation": overlay[
            "source_packet_expansion_preservation"
        ],
        "verified_assurance_findings_preservation": overlay[
            "assurance_findings_preservation"
        ],
        "verified_overlay_credit_boundary": overlay["credit_boundary"],
        "verified_overlay_review_credit_boundary": review["credit_boundary"],
        "credit_boundary": {
            "REPORTING_REFRESH_FOR_REVIEWED_OVERLAY": True,
            "new_source_ownership": False,
            "new_route_ownership": False,
            "new_page_ownership": False,
            "new_controller_action_bridge": False,
            "new_queue_review": False,
            "matrix_mutation": False,
            "application_source_mutation": False,
            "canonical_object_ownership_correctness": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "privacy_correctness": False,
            "direct_object_correctness": False,
            "query_correctness": False,
            "projection_correctness": False,
            "response_minimization_correctness": False,
            "lifecycle_correctness": False,
            "concurrency_or_idempotency_correctness": False,
            "event_or_downstream_durability_correctness": False,
            "runtime": False,
            "database": False,
            "build": False,
            "application_browser": False,
            "responsive_application": False,
            "visual_application_workflow": False,
            "executed_tests": False,
            "benchmark": False,
            "ease": False,
            "release": False,
            "pass": False,
            "final_finding": False,
            "completion": False,
            "audit_complete": False,
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/00-executive-summary.md",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/01-repository-module-map.md",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/13-unresolved-questions-and-evidence-gaps.md",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/findings.json",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/build-current-audit-dashboard.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-run-139-reviewed-finance-invoice-index-route-action-reporting-wave-22.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/current-run-139-reviewed-finance-invoice-index-route-action-reporting-wave-22.json",
        ],
    }
    assert {key for key, value in receipt["credit_boundary"].items() if value} == {
        "REPORTING_REFRESH_FOR_REVIEWED_OVERLAY"
    }
    encoded = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode(
        "utf-8"
    )
    output = path(OUTPUT_RELATIVE)
    if output.exists():
        prior = read_json(OUTPUT_RELATIVE)
        assert prior["run_id"] == RUN_ID
        assert prior["schema_version"] == SCHEMA_VERSION
    if not output.exists() or output.read_bytes() != encoded:
        temporary = output.with_suffix(output.suffix + ".tmp")
        temporary.write_bytes(encoded)
        os.replace(temporary, output)
    assert output.read_bytes() == encoded

    prefix = AUDIT_DIR.relative_to(REPO).as_posix()
    expected_status = {
        f" M {prefix}/00-executive-summary.md",
        f" M {prefix}/01-repository-module-map.md",
        f" M {prefix}/13-unresolved-questions-and-evidence-gaps.md",
        f" M {prefix}/findings.json",
        f" M {prefix}/generators/build-current-audit-dashboard.py",
        f"?? {prefix}/{MATERIALIZER_RELATIVE}",
        f"?? {prefix}/{OUTPUT_RELATIVE}",
    }
    assert set(git("status", "--porcelain").splitlines()) == expected_status
    print(
        json.dumps(
            {
                "status": receipt["status"],
                "output": output.relative_to(REPO).as_posix(),
                "sha256": sha256_bytes(encoded),
                "owners": receipt["counts"]["source_owner_records"],
                "routes": receipt["counts"]["route_owner_records"],
                "pages": receipt["counts"]["page_owner_records"],
                "bridges": receipt["counts"]["static_controller_action_bridges"],
                "reviewed_queue": receipt["counts"]["reviewed_queue_surface_rows"],
                "pending_queue": receipt["counts"]["pending_unreviewed_queue_surface_rows"],
                "matrix_mapping": "0/340",
                "fresh_dashboard_verification": "RUN-140",
                "gate_4_complete": False,
                "audit_complete": False,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
