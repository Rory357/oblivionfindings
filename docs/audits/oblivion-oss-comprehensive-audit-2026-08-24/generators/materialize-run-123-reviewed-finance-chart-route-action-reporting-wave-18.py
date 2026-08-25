#!/usr/bin/env python3
"""Promote the independently reviewed RUN-122 Finance overlay into reports.

Only five current reporting surfaces are updated. Reports 02-12, inventory,
the 340-row matrix, and all application source remain byte-identical. The
dashboard generated from the updated template requires a fresh RUN-124
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
MATERIALIZER_RELATIVE = "generators/materialize-run-123-reviewed-finance-chart-route-action-reporting-wave-18.py"
OUTPUT_RELATIVE = "evidence/source/current-run-123-reviewed-finance-chart-route-action-reporting-wave-18.json"
SCHEMA_VERSION = "run-123-reviewed-finance-chart-route-action-reporting-wave-18-v1"
RUN_ID = "RUN-123-REVIEWED-FINANCE-CHART-ROUTE-ACTION-REPORTING-WAVE-18"

CHECKPOINT_COMMIT = "588b6821f54e5b6be9ee67cf680cb44098f78adf"
CHECKPOINT_TREE = "24443fb347ebb94ca757b84606dd134e8a05367e"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
RECORDS_SHA256 = "118c1a1f19b2e300c77d5b4d71c60f75b7038382f3c71e18f078367f8473e260"

# This exact independently reviewed predecessor receipt authorizes the single
# deterministic lineage correction below. Later reruns must be self-pinned.
KNOWN_PREDECESSOR_RECEIPT_SHA256S = {
    "878f1501a28942292a5454961b65871051463131d9c6d1001687d070af443bc8",
}

CURRENT_REPORT_INPUTS = {
    "00-executive-summary.md": "7884a29ae68a540249dd9a4a0c7618b5db91cb746df80c74e5bd7588d752ad01",
    "01-repository-module-map.md": "2de5cc4b428f954618d5633191caed634b91482e8ac45463c979de3e632b8a99",
    "13-unresolved-questions-and-evidence-gaps.md": "2f88ee459734d4ac77359357d4893f936f19a7f99764e83244b2a1727470e135",
    "findings.json": "3b21e20ceaea97b5e2d41d7a061d06308694bddbb1503d3b0d7cf26dbaadaf41",
    "generators/build-current-audit-dashboard.py": "e68895e812785b3604b9872f7d38e70306326afaf79e7ba6c8935bc9f779baac",
}

PINNED_INPUTS = {
    "generators/materialize-run-119-reviewed-respite-handover-page-gap-reporting-wave-17.py": "83a827a1ea1f6d9fc8f485dcaf2cd8b6c644a37d6b56ee74f53597edea66be2e",
    "evidence/source/current-run-119-reviewed-respite-handover-page-gap-reporting-wave-17.json": "d2f80b1649fd4f8eaf965986eaf5b85dc4c906364271dbbd6513fe68c315b694",
    "evidence/browser/current-audit-dashboard-verification-run-120-wave-17.json": "0e3ed652833d0e78b3bca85a78cb23f69ddf511e4d1f32f3a8c0bf8dcf20482c",
    "generators/build-outcome-neutral-finance-chart-route-action-cohort-wave-18.py": "c7795bee971e051873e3953eb4e1bb7c62eb372b6890149700d0c401d64305dd",
    "evidence/source/root-run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18.json": "cfe0e3635e5e86bf8e7e2f65d2094743738bfa5edc36e361ecf5eb14986f316e",
    "generators/materialize-independent-outcome-neutral-finance-chart-route-action-review-wave-18.py": "539b48b7aa2859a4b290d63c8d80e5fdcf685a5cb569e37b75499e31dd8d5187",
    "evidence/source/raw-run-121r-independent-outcome-neutral-finance-chart-route-action-review-wave-18.json": "f70ddd2ddc7ac0c734f4b48bdd19cd2733c3572d038b1dfa1aa185591e567e5f",
    "generators/integrate-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.py": "04e28529615267699a2c8e844cf074057e18a9019fc511ed65f7c0203dead390",
    "evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json": "d7aee21e7c4230b44707a22b7fa93478a84e9a5b4775ecd25aaffede764855ca",
    "generators/materialize-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-review-wave-18.py": "4a080c77dc869fffa53daab937ea03d06ee14d8e11dc941e9d54a7f36b26b315",
    "evidence/source/raw-run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json": "2130e3801b6ac163580bc56f23d6647136c83fdadc8ea65804b1559d36b29484",
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
    existing_receipt = read_json(OUTPUT_RELATIVE) if path(OUTPUT_RELATIVE).exists() else None
    for relative, expected in CURRENT_REPORT_INPUTS.items():
        allowed = {expected}
        if existing_receipt is not None:
            assert existing_receipt["run_id"] == RUN_ID
            assert existing_receipt["schema_version"] == SCHEMA_VERSION
            assert existing_receipt["pins"]["checkpoint_commit"] == CHECKPOINT_COMMIT
            assert existing_receipt["pins"]["checkpoint_tree"] == CHECKPOINT_TREE
            existing_receipt_sha256 = sha256_file(OUTPUT_RELATIVE)
            assert (
                existing_receipt_sha256 in KNOWN_PREDECESSOR_RECEIPT_SHA256S
                or existing_receipt["pins"]["materializer_sha256"] == sha256_file(MATERIALIZER_RELATIVE)
            )
            allowed.add(existing_receipt["outputs"][relative])
        assert sha256_file(relative) in allowed, (relative, sha256_file(relative), sorted(allowed))
    for relative, expected in PINNED_INPUTS.items():
        assert sha256_file(relative) == expected, relative
    preserved = {relative: sha256_file(relative) for relative in PRESERVED_PATHS}
    assert preserved["03-feature-to-benchmark-matrix.csv"] == MATRIX_SHA256
    overlay = read_json("evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json")
    review = read_json("evidence/source/raw-run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json")
    counts = overlay["combined_counts"]
    queue = overlay["queue_accounting"]
    assert (counts["source_owner_records"], counts["route_owner_records"], counts["page_owner_records"]) == (648, 295, 353)
    assert (counts["static_controller_action_bridges"], counts["bounded_static_source_residual_records"]) == (83, 3281)
    assert (queue["reviewed_queue_surface_rows"], queue["pending_unreviewed_queue_surface_rows"]) == (106, 401)
    assert review["decision"]["verdict"] == "GO"
    assert review["decision"]["reporting_materialization_authorized"] is True
    assert review["decision"]["downstream_credit_authorized"] is False
    assert review["decision"]["gate_4_complete"] is False
    assert {key for key, value in review["credit_boundary"].items() if value} == {"INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"}
    return overlay, review, preserved


def patch_reports() -> None:
    summary_relative = "00-executive-summary.md"
    summary = path(summary_relative).read_text(encoding="utf-8")
    summary_block = """## RUN-113–123 reviewed route/action and page-ownership lineage

RUN-113/R–116 remain the historical 23-owner / one-alias name-only route-action checkpoint and exact dashboard receipt. RUN-117/R–120 separately establish four Respite handover pages as explicit owners based on their own complete semantics, report that bounded delta, and verify the exact now-superseded dashboard at four viewports with audit-artifact credit only.

RUN-121 freezes the next exact pending RUN-090 cohort: 22 Finance route actions currently projected by literal names to `CAP-FIN-CHART-OF-ACCOUNTS`. Three fresh reviewers read the complete controller, request, service, model/policy, route, and page context. They classify **7 owner route actions, 7 shared relations, 1 cross-feature redirect alias, 0 dead/noncanonical rows, and 7 evidence gaps**. The owner actions are only `accounts.index`, `accounts.create`, `accounts.store`, `accounts.show`, `accounts.edit`, `accounts.update`, and `accounts.destroy`.

RUN-121R independently reconciles every final candidate and decision hash. The seven shared rows are `journals.reverse` plus the six cost-centre/funding-stream mutations; `ledger.index` remains a cross-feature redirect alias; the manual-journal and fiscal-period rows remain evidence gaps. Accounting Period, Cost Centre, Funding Stream, and Manual Journal retain dedicated canonical FEATURE-IDs requiring later mapping repair. Six literal page callsites remain context only: two were already owned and four still require separate page review.

RUN-122 integrates only the seven explicit route owners and seven controller-action bridges. It preserves all 15 reviewed non-owner outcomes and grants zero page ownership. RUN-122R reproduces the committed producer byte-for-byte, verifies all 34 identities, exact ancestry and collision freedom, and authorizes reporting only.

The current bounded checkpoint is **648 records = 295 routes + 353 pages across 256 canonical FEATURE-IDs (234 H + 22 D)**, with 83 controller-action bridges. Route and page owners span 62 and 242 FEATURE-IDs with 48 in their overlap. This is 16.492746% of the bounded 3,929-record source universe; 3,281 records remain. The route universe is **3,218 = 295 owners + 12 shared + 5 aliases + 2,906 residual**, with seven evidence gaps tagged inside that residual. The page universe remains **711 = 353 owners + 9 shared + 349 residual**, with one earlier evidence gap tagged inside its residual. Queue accounting is **507 = 106 reviewed + 401 pending**; reviewed rows are 84 owned, 10 shared, 5 aliases, 0 dead, and 7 evidence gaps, while 423 remain without ownership.

RUN-123 reports only that bounded Finance delta. Oblivion Findings remains one operating organisation across multiple Sites. Framework reachability, navigation, Site access, roles/permissions, canonical object ownership, direct-object concealment, privacy, ledger/lifecycle/concurrency correctness, runtime, database, build, application browser, tests, benchmarks, ease, Passes, findings, completion, and Gate 4 remain separate open or zero-credit gates. The 340-row matrix remains byte-identical and mapping remains 0/340.

"""
    summary = replace_between(
        summary,
        "## RUN-113–119 reviewed route/action and respite handover page-gap overlays\n",
        "## Current raw source census\n",
        summary_block,
        "summary Wave 18 block",
    )
    evidence_marker = "- `generators/materialize-run-119-reviewed-respite-handover-page-gap-reporting-wave-17.py` and `evidence/source/current-run-119-reviewed-respite-handover-page-gap-reporting-wave-17.json`: deterministic RUN-119 reporting refresh preserving the matrix, reports 02–12/inventory, and every downstream zero-credit boundary.\n"
    evidence_addition = evidence_marker + (
        "- `evidence/browser/current-audit-dashboard-verification-run-120-wave-17.json`: exact now-superseded RUN-119 dashboard artifact verification at four viewports; zero application credit.\n"
        "- `generators/build-outcome-neutral-finance-chart-route-action-cohort-wave-18.py` and `evidence/source/root-run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18.json`: exact zero-credit 22-route Finance review cohort.\n"
        "- `generators/materialize-independent-outcome-neutral-finance-chart-route-action-review-wave-18.py` and `evidence/source/raw-run-121r-independent-outcome-neutral-finance-chart-route-action-review-wave-18.json`: fresh 7-owner / 7-shared / 1-alias / 7-gap semantic review with zero page or downstream credit.\n"
        "- `generators/integrate-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.py` and `evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json`: exact seven-route owner-only overlay plus seven bridges and 15 preserved non-owners.\n"
        "- `generators/materialize-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-review-wave-18.py` and `evidence/source/raw-run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json`: independent final-byte, 34-identity, accounting, preservation, and boundary GO receipt.\n"
        "- `generators/materialize-run-123-reviewed-finance-chart-route-action-reporting-wave-18.py` and `evidence/source/current-run-123-reviewed-finance-chart-route-action-reporting-wave-18.json`: deterministic RUN-123 reporting refresh preserving the matrix, reports 02–12/inventory, and every downstream zero-credit boundary.\n"
    )
    summary = replace_once(summary, evidence_marker, evidence_addition, "summary evidence links")
    write_lf(summary_relative, summary)

    module_relative = "01-repository-module-map.md"
    module_map = path(module_relative).read_text(encoding="utf-8")
    module_block = """## RUN-113–123 reviewed route/action and page-ownership lineage

RUN-113/R–120 remain historical reviewed route/action and Respite page-owner checkpoints with exact reporting and dashboard receipts.

RUN-121/R review 22 Finance name-only route actions without treating literal name equality as ownership. Seven Chart of Accounts actions are explicit owners. Seven rows are shared relations, `ledger.index` is a redirect alias, and seven manual-journal/fiscal-period rows remain evidence gaps. RUN-122/R integrate and independently verify only seven route owners and seven controller-action bridges, preserve 15 non-owner outcomes, and add zero page ownership.

The cumulative bounded ledger is 648 source owners (295 route + 353 page) across 256 FEATURE-IDs (234 H + 22 D). Route/page feature sets are 62/242 with overlap 48, and the action-bridge count is 83. Route accounting is 3,218 = 295 owners + 12 shared + 5 aliases + 2,906 residual, with seven evidence gaps tagged within residual. Page accounting remains 711 = 353 owners + 9 shared + 349 residual, with one earlier tagged evidence gap. RUN-090 queue accounting is 507 total, 106 reviewed, 84 owned, 10 shared, 5 aliases, 7 evidence gaps, 401 pending, and 423 without ownership.

Accounting Period, Cost Centre, Funding Stream, and Manual Journal retain dedicated canonical FEATURE-IDs requiring later mapping repair. Six page callsites remain context only: two already owned and four unowned. These relations establish bounded static route ownership, bridges, and explicit reviewed non-owner classes only; they do not establish framework reachability, Site or permission correctness, canonical direct-object concealment, privacy, ledger/lifecycle/concurrency correctness, runtime, build, browser, tests, benchmarks, findings, Passes, or completion.

"""
    module_map = replace_between(
        module_map,
        "## RUN-113–119 reviewed route/action and respite handover page-gap overlays\n",
        "## Candidate register\n",
        module_block,
        "module Wave 18 block",
    )
    write_lf(module_relative, module_map)

    gaps_relative = "13-unresolved-questions-and-evidence-gaps.md"
    gaps = path(gaps_relative).read_text(encoding="utf-8")
    gaps = replace_once(
        gaps,
        "## RUN-077–119 route/page, page-tree, backend, ownership and reporting lineage",
        "## RUN-077–123 route/page, page-tree, backend, ownership and reporting lineage",
        "gaps RUN123 lineage heading",
    )
    rows = {
        "| Required reporting paths |": "| Required reporting paths | 18 / 18 prompt-required files or directories present. RUN-120 independently verified the exact now-superseded RUN-119 dashboard at four viewports; the regenerated RUN-123 dashboard requires a separate fresh RUN-124 artifact receipt. | Presence and prior audit-artifact verification make the reporting contract inspectable but grant no application execution, final-finding, Pass, or completion credit. Gate 26 remains open. | Complete the semantic, execution, benchmark, Pass 8, final reconciliation, and no-live-agent gates; repeat audit-dashboard verification after every later HTML change. |",
        "| Runtime routes |": "| Runtime routes | RUN-122/R establish 295 bounded route-owner records and 83 static controller-action bridges; 2,906 residual route rows, 12 semantic-shared route rows, and 5 reviewed aliases remain distinguished within the bounded 3,218-row static route-like universe, with 7 evidence gaps tagged inside residual. | Wave 18 adds only seven reviewed Finance route owners and seven bridges. Static owner/action linkage is not a framework-expanded route table, reachability proof, ledger-correctness proof, or authorization proof. | Under a fresh bounded runtime grant, use an exact disposable database, execute a separately pinned framework/provider route lane, and reconcile it to all 38 source route files and 3,218 route-like rows. |",
        "| Inertia pages |": "| Inertia pages | RUN-084/R enumerate 1,058 physical page-tree files. RUN-122/R preserve 353 bounded page owners, nine semantic-shared roots, and 349 residual roots including one earlier tagged evidence gap. | Wave 18 grants zero page ownership. Six Finance callsites are context only: two already owned and four unowned. Full-tree structural GO and bounded ownership are not a complete canonical crosswalk, runtime reachability, build resolution, rendered browser behaviour, or final feature mapping. | Separately review the four unowned Finance page roots, reconcile all 711 literal roots and support relations to safely expanded framework routes and frozen FEATURE-IDs, prove build resolution, and observe every safely reachable current-build page under approved roles/Sites. |",
        "| Canonical features |": "| Canonical features | Static canonical identity remains 340 targets: 300 H, 40 D, zero M; RUN-122/R establish 648 bounded source-owner records (295 routes + 353 pages) across 256 FEATURE-IDs (234 H + 22 D) plus 83 controller-action bridges while the matrix remains byte-identical at `dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390`. | This is 16.492746% of the bounded 3,929-record source universe. Gate 4 remains incomplete: 3,281 non-owner records, the framework-expanded denominator, shared, alias, and gap relations, reachability, and the full crosswalk remain open. Chart of Accounts was already in the feature/page union but is newly represented in the route-feature set; `CAP-FIN-ACCOUNTING-PERIOD-LIFECYCLE`, `CAP-FIN-COST-CENTRE-ADMIN`, `CAP-FIN-FUNDING-STREAM-ADMIN`, and `CAP-FIN-MANUAL-JOURNAL-LIFECYCLE` still need mapping repair; matrix target mapping stays 0/340. | Finish canonical denominator and residual ownership adjudication without inheriting FEATURE-IDs from prefixes, imports, containment, names, or presence, and without awarding runtime, browser, test, benchmark, ease, release, or completion credit. |",
        "| Agent universe and writer rule |": "| Agent universe and writer rule | RUN-001 through RUN-123 represented at the current reporting checkpoint; finalization gate false. | RUN-121/R review 22 Finance routes as 7 owners, 7 shared, 1 alias, and 7 gaps; RUN-122/R independently integrate and verify only 7 route owners and 7 bridges while preserving 15 non-owner outcomes; RUN-123 reports only those bounded classes. Runtime, browser, tests, benchmarks, Pass 8, finalization, and completion remain open. | Complete residual ownership and every semantic/execution gate, then dispatch fresh Pass 8/final cross-reviewers, verify the final dashboard, and prove no agent remains live at finalization. |",
    }
    for marker, replacement in rows.items():
        gaps = replace_line_containing(gaps, marker, replacement)
    lineage = "RUN-077 freezes the exhaustive committed-source route/name/page universe through RUN-084B's page-tree and backend structural ledgers. RUN-086/R establish the initial 530 bounded source owners; RUN-090 freezes the zero-credit direct-exact queue; RUN-091/R–103 successively add and report reviewed closed-chain and route/action ownership while preserving shared and alias outcomes, reaching 592 owners. RUN-104 verifies that superseded dashboard. RUN-105/R–107 review, integrate, and report 20 page owners, three shared relations, and one evidence gap, reaching 612 owners; RUN-108 verifies that superseded dashboard. RUN-109/R review the six-page tail as two owners and four shared relations; RUN-110/R integrate and independently verify two page owners and one reviewed-shared queue reconciliation, reaching 614 owners; RUN-111 reports that delta and RUN-112 verifies its superseded dashboard. RUN-113/R review 24 name-only route actions as 23 owners and one alias; RUN-114/R integrate and verify 23 route owners plus 23 bridges, reaching 637 owners; RUN-115 reports that delta and RUN-116 verifies its superseded dashboard. RUN-117/R review four Respite handover page gaps as four explicit owners; RUN-118/R integrate and independently verify those four page owners, reaching 641 owners while route, bridge, feature, and queue counts remain unchanged; RUN-119 reports that delta and RUN-120 verifies its now-superseded dashboard. RUN-121/R review 22 Finance name-only route actions as 7 owners, 7 shared, 1 alias, 0 dead, and 7 gaps. RUN-122/R integrate and independently verify only 7 route owners and 7 bridges, preserve all 15 non-owner outcomes, and reach 648 owners; RUN-123 reports only that bounded delta. Gate 4 and the full route/page/backend crosswalk remain incomplete; Oblivion Findings remains one operating organisation across multiple Sites, and framework reachability, Site/permission/privacy/direct-object/ledger/lifecycle/concurrency correctness, runtime, build, signed-in application browser, executed tests, benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit."
    gaps = replace_line_containing(gaps, "RUN-077 freezes the exhaustive committed-source route/name/page universe", lineage)
    write_lf(gaps_relative, gaps)


def patch_findings(overlay: dict[str, Any], review: dict[str, Any]) -> None:
    relative = "findings.json"
    findings = read_json(relative)
    assert canonical_json_sha256(findings["records"]) == RECORDS_SHA256
    findings["generated_on"] = "2026-08-26"
    findings["pins"].update({
        "run_119_reporting_materializer_sha256": PINNED_INPUTS["generators/materialize-run-119-reviewed-respite-handover-page-gap-reporting-wave-17.py"],
        "run_119_reporting_sha256": PINNED_INPUTS["evidence/source/current-run-119-reviewed-respite-handover-page-gap-reporting-wave-17.json"],
        "run_120_dashboard_verification_sha256": PINNED_INPUTS["evidence/browser/current-audit-dashboard-verification-run-120-wave-17.json"],
        "run_121_finance_chart_route_action_cohort_generator_sha256": PINNED_INPUTS["generators/build-outcome-neutral-finance-chart-route-action-cohort-wave-18.py"],
        "run_121_finance_chart_route_action_cohort_sha256": PINNED_INPUTS["evidence/source/root-run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18.json"],
        "run_121r_finance_chart_route_action_review_materializer_sha256": PINNED_INPUTS["generators/materialize-independent-outcome-neutral-finance-chart-route-action-review-wave-18.py"],
        "run_121r_finance_chart_route_action_review_sha256": PINNED_INPUTS["evidence/source/raw-run-121r-independent-outcome-neutral-finance-chart-route-action-review-wave-18.json"],
        "run_122_finance_chart_route_action_overlay_generator_sha256": PINNED_INPUTS["generators/integrate-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.py"],
        "run_122_finance_chart_route_action_overlay_sha256": PINNED_INPUTS["evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json"],
        "run_122r_finance_chart_route_action_overlay_review_materializer_sha256": PINNED_INPUTS["generators/materialize-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-review-wave-18.py"],
        "run_122r_finance_chart_route_action_overlay_review_sha256": PINNED_INPUTS["evidence/source/raw-run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json"],
        "run_123_reporting_materializer_sha256": sha256_file(MATERIALIZER_RELATIVE),
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
        "direct_exact_queue_dead_or_noncanonical": queue["dead_queue_surface_rows"],
        "direct_exact_queue_evidence_gap": queue["evidence_gap_queue_surface_rows"],
        "direct_exact_queue_pending_unreviewed": queue["pending_unreviewed_queue_surface_rows"],
        "direct_exact_queue_without_ownership": queue["queue_surfaces_without_ownership"],
    })
    checkpoint_findings = json.loads(
        git("show", f"{CHECKPOINT_COMMIT}:docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/findings.json")
    )
    findings["historical_run_118_outcome_neutral_respite_handover_page_gap_ownership"] = checkpoint_findings["current_static_source_feature_ownership"]
    findings["historical_run_118_outcome_neutral_respite_handover_page_gap_ownership_review"] = checkpoint_findings["current_outcome_neutral_respite_handover_page_gap_ownership_review"]
    findings.pop("current_outcome_neutral_respite_handover_page_gap_ownership_review", None)
    findings["current_static_source_feature_ownership"] = {
        "run_id": overlay["run_id"],
        "review_run_id": review["run_id"],
        "status": "GO_REVIEWED_BOUNDED_OUTCOME_NEUTRAL_FINANCE_CHART_ROUTE_ACTION_OWNERSHIP_ONLY",
        "baseline_records": overlay["baseline"]["source_owner_records"],
        "reviewed_route_actions": overlay["reviewed_overlay"]["reviewed_route_actions"],
        "overlay_source_records": len(overlay["overlay_source_records"]),
        "owner_route_actions_added": overlay["reviewed_overlay"]["owner_route_actions"],
        "shared_relations_added": overlay["reviewed_overlay"]["shared_relations"],
        "reviewed_alias_or_redirect": overlay["reviewed_overlay"]["alias_or_redirect"],
        "dead_or_noncanonical": overlay["reviewed_overlay"]["dead_or_noncanonical"],
        "evidence_gaps": overlay["reviewed_overlay"]["evidence_gaps"],
        "route_owner_records_added": overlay["reviewed_overlay"]["accepted_route_owner_records"],
        "page_owner_records_added": overlay["reviewed_overlay"]["accepted_page_owner_records"],
        "controller_action_bridges_added": overlay["reviewed_overlay"]["accepted_controller_action_bridges"],
        "accepted_distinct_feature_ids": overlay["reviewed_overlay"]["accepted_distinct_feature_ids"],
        "new_distinct_feature_ids": overlay["reviewed_overlay"]["new_distinct_feature_ids"],
        "new_route_feature_ids": overlay["reviewed_overlay"]["new_route_feature_ids"],
        "new_page_feature_ids": overlay["reviewed_overlay"]["new_page_feature_ids"],
        "reviewed_non_owner_records_preserved": overlay["reviewed_overlay"]["reviewed_non_owner_records_preserved"],
        "combined_counts": counts,
        "queue_accounting": queue,
        "name_only_provenance": overlay["name_only_provenance"],
        "ownership_basis": "FRESH_EXACT_CONTROLLER_ACTION_SEMANTIC_REVIEW_NAME_ONLY_NOT_SUFFICIENT",
        "identity_reconciliation": overlay["identity_reconciliation"],
        "page_context_boundary": overlay["page_context_boundary"],
        "independent_review_discrepancies": (
            review["decision"]["mechanical_discrepancies"]
            + review["decision"]["semantic_or_preservation_discrepancies"]
            + review["decision"]["arithmetic_or_conservation_discrepancies"]
            + review["decision"]["repository_hygiene_discrepancies_remaining"]
        ),
        "gate_4": {"status": "PARTIAL_BOUNDED_STATIC_SOURCE_OWNERSHIP_INCOMPLETE", "complete": False},
        "credit_boundary": overlay["credit_boundary"],
    }
    findings["current_outcome_neutral_finance_chart_route_action_ownership_review"] = {
        "run_id": review["run_id"],
        "status": review["status"],
        "reviewers": len(review["reviewers"]),
        "route_owner_records_verified": review["decision"]["route_owner_records_authorized"],
        "controller_action_bridges_verified": review["decision"]["controller_action_bridges_authorized"],
        "reviewed_shared_records_verified": review["decision"]["reviewed_shared_records_authorized"],
        "reviewed_alias_records_verified": review["decision"]["reviewed_alias_records_authorized"],
        "reviewed_evidence_gap_records_verified": review["decision"]["reviewed_evidence_gap_records_authorized"],
        "reviewed_non_owner_records_preserved": review["decision"]["reviewed_non_owner_records_preserved"],
        "page_owner_records_authorized": review["decision"]["page_owner_records_authorized"],
        "mechanical_discrepancies": review["decision"]["mechanical_discrepancies"],
        "semantic_or_preservation_discrepancies": review["decision"]["semantic_or_preservation_discrepancies"],
        "arithmetic_or_conservation_discrepancies": review["decision"]["arithmetic_or_conservation_discrepancies"],
        "repository_hygiene_discrepancies_remaining": review["decision"]["repository_hygiene_discrepancies_remaining"],
        "reporting_materialization_authorized": review["decision"]["reporting_materialization_authorized"],
        "downstream_credit_authorized": False,
        "gate_4_complete": False,
        "completion_credit": False,
        "credit_boundary": review["credit_boundary"],
    }
    findings["current_direct_exact_route_page_review_queue"].update({
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
    })
    run120 = read_json("evidence/browser/current-audit-dashboard-verification-run-120-wave-17.json")
    verification = run120["verification"]
    findings["current_audit_artifact_verification_history"]["run_120"] = {
        "status": run120["status"],
        "dashboard_sha256": run120["pins"]["dashboard_html_sha256"],
        "receipt_sha256": PINNED_INPUTS["evidence/browser/current-audit-dashboard-verification-run-120-wave-17.json"],
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
    assert 3929 == counts["source_owner_records"] + counts["bounded_static_source_residual_records"]
    assert counts["source_owner_records"] == counts["route_owner_records"] + counts["page_owner_records"]
    assert 3218 == counts["route_owner_records"] + counts["semantic_shared_routes"] + counts["reviewed_alias_routes"] + counts["reviewed_dead_routes"] + counts["residual_explicit_unmapped_routes"]
    assert 711 == counts["page_owner_records"] + counts["semantic_shared_page_roots"] + counts["reviewed_alias_page_roots"] + counts["reviewed_dead_page_roots"] + counts["residual_unadjudicated_page_roots"]
    assert queue["direct_exact_queue_records"] == queue["reviewed_queue_surface_rows"] + queue["pending_unreviewed_queue_surface_rows"]
    assert queue["reviewed_queue_surface_rows"] == queue["owner_queue_surface_rows"] + queue["shared_queue_surface_rows"] + queue["alias_queue_surface_rows"] + queue["dead_queue_surface_rows"] + queue["evidence_gap_queue_surface_rows"]
    assert queue["queue_surfaces_without_ownership"] == queue["pending_unreviewed_queue_surface_rows"] + queue["shared_queue_surface_rows"] + queue["alias_queue_surface_rows"] + queue["dead_queue_surface_rows"] + queue["evidence_gap_queue_surface_rows"]
    assert findings["current_direct_exact_route_page_review_queue"]["reviewed_queue_surfaces"] == 106
    assert findings["current_direct_exact_route_page_review_queue"]["pending_unreviewed"] == 401
    assert findings["current_direct_exact_route_page_review_queue"]["without_ownership"] == 423
    assert {key for key, value in review["credit_boundary"].items() if value} == {"INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"}
    write_lf(relative, json.dumps(findings, ensure_ascii=False, indent=2) + "\n")


def patch_dashboard_template() -> None:
    relative = "generators/build-current-audit-dashboard.py"
    text = path(relative).read_text(encoding="utf-8")
    read_anchor = 'reviewed_respite_handover_page_overlay_review = read_json("evidence/source/raw-run-118r-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json")\n'
    read_addition = read_anchor + (
        'finance_chart_route_action_cohort = read_json("evidence/source/root-run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18.json")\n'
        'finance_chart_route_action_review = read_json("evidence/source/raw-run-121r-independent-outcome-neutral-finance-chart-route-action-review-wave-18.json")\n'
        'reviewed_finance_chart_route_action_overlay = read_json("evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json")\n'
        'reviewed_finance_chart_route_action_overlay_review = read_json("evidence/source/raw-run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json")\n'
    )
    text = replace_once(text, read_anchor, read_addition, "dashboard Wave 18 reads")
    pin_anchor = 'assert sha256_file("evidence/source/raw-run-118r-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json") == "043d57357e3ff1ede8f0effacdb71e4d802d98d53d555ab39316bce33fe06a2d"\n'
    pin_addition = pin_anchor + (
        'assert sha256_file("generators/materialize-run-119-reviewed-respite-handover-page-gap-reporting-wave-17.py") == "83a827a1ea1f6d9fc8f485dcaf2cd8b6c644a37d6b56ee74f53597edea66be2e"\n'
        'assert sha256_file("evidence/source/current-run-119-reviewed-respite-handover-page-gap-reporting-wave-17.json") == "d2f80b1649fd4f8eaf965986eaf5b85dc4c906364271dbbd6513fe68c315b694"\n'
        'assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-120-wave-17.json") == "0e3ed652833d0e78b3bca85a78cb23f69ddf511e4d1f32f3a8c0bf8dcf20482c"\n'
        'assert sha256_file("generators/build-outcome-neutral-finance-chart-route-action-cohort-wave-18.py") == "c7795bee971e051873e3953eb4e1bb7c62eb372b6890149700d0c401d64305dd"\n'
        'assert sha256_file("evidence/source/root-run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18.json") == "cfe0e3635e5e86bf8e7e2f65d2094743738bfa5edc36e361ecf5eb14986f316e"\n'
        'assert sha256_file("generators/materialize-independent-outcome-neutral-finance-chart-route-action-review-wave-18.py") == "539b48b7aa2859a4b290d63c8d80e5fdcf685a5cb569e37b75499e31dd8d5187"\n'
        'assert sha256_file("evidence/source/raw-run-121r-independent-outcome-neutral-finance-chart-route-action-review-wave-18.json") == "f70ddd2ddc7ac0c734f4b48bdd19cd2733c3572d038b1dfa1aa185591e567e5f"\n'
        'assert sha256_file("generators/integrate-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.py") == "04e28529615267699a2c8e844cf074057e18a9019fc511ed65f7c0203dead390"\n'
        'assert sha256_file("evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json") == "d7aee21e7c4230b44707a22b7fa93478a84e9a5b4775ecd25aaffede764855ca"\n'
        'assert sha256_file("generators/materialize-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-review-wave-18.py") == "4a080c77dc869fffa53daab937ea03d06ee14d8e11dc941e9d54a7f36b26b315"\n'
        'assert sha256_file("evidence/source/raw-run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json") == "2130e3801b6ac163580bc56f23d6647136c83fdadc8ea65804b1559d36b29484"\n'
    )
    text = replace_once(text, pin_anchor, pin_addition, "dashboard Wave 18 pins")
    semantic_anchor = 'assert all(reviewed_respite_handover_page_overlay["credit_boundary"][key] is False for key in ("static_route_feature_ownership_added", "static_controller_action_bridge_added", "direct_exact_queue_review_added", "matrix_mutation", "wholesale_507_queue_ownership", "complete_route_page_feature_crosswalk", "framework_route_reachability", "navigation", "site_authorization_correctness", "permission_correctness", "privacy_correctness", "direct_object_correctness", "lifecycle_correctness", "runtime", "database", "build", "application_browser", "executed_tests", "benchmark", "ease", "pass", "final_finding", "completion", "audit_complete"))\n'
    semantic_addition = semantic_anchor + """
assert finance_chart_route_action_cohort["counts"]["selected_pending_queue_surfaces"] == 22
assert finance_chart_route_action_cohort["counts"]["ownership_credit_awarded"] == 0
assert finance_chart_route_action_review["decision"]["verdict"] == "GO_7_EXPLICIT_OWNER_ROUTE_ACTION_7_SHARED_1_ALIAS_7_EVIDENCE_GAP"
assert (finance_chart_route_action_review["decision"]["owner_route_actions"], finance_chart_route_action_review["decision"]["shared_relations"], finance_chart_route_action_review["decision"]["alias_or_redirect"], finance_chart_route_action_review["decision"]["dead_or_noncanonical"], finance_chart_route_action_review["decision"]["evidence_gaps"]) == (7, 7, 1, 0, 7)
assert reviewed_finance_chart_route_action_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_finance_chart_route_action_overlay_review["decision"]["reporting_materialization_authorized"] is True
assert reviewed_finance_chart_route_action_overlay_review["decision"]["gate_4_complete"] is False
assert len(reviewed_finance_chart_route_action_overlay["overlay_source_records"]) == 7
assert len(reviewed_finance_chart_route_action_overlay["new_static_controller_action_bridges"]) == 7
assert len(reviewed_finance_chart_route_action_overlay["reviewed_non_owner_outcomes"]) == 15
finance_counts = reviewed_finance_chart_route_action_overlay["combined_counts"]
finance_queue = reviewed_finance_chart_route_action_overlay["queue_accounting"]
assert (finance_counts["source_owner_records"], finance_counts["route_owner_records"], finance_counts["page_owner_records"]) == (648, 295, 353)
assert (finance_counts["distinct_feature_ids"], finance_counts["distinct_H_feature_ids"], finance_counts["distinct_D_feature_ids"]) == (256, 234, 22)
assert (finance_counts["route_distinct_feature_ids"], finance_counts["page_distinct_feature_ids"], finance_counts["route_page_feature_overlap"]) == (62, 242, 48)
assert (finance_counts["static_controller_action_bridges"], finance_counts["bounded_static_source_residual_records"]) == (83, 3281)
assert (finance_counts["residual_explicit_unmapped_routes"], finance_counts["semantic_shared_routes"], finance_counts["reviewed_alias_routes"], finance_counts["evidence_gap_routes_tagged_within_residual"]) == (2906, 12, 5, 7)
assert (finance_counts["residual_unadjudicated_page_roots"], finance_counts["semantic_shared_page_roots"], finance_counts["evidence_gap_page_roots_tagged_within_residual"]) == (349, 9, 1)
assert (finance_queue["direct_exact_queue_records"], finance_queue["reviewed_queue_surface_rows"], finance_queue["owner_queue_surface_rows"], finance_queue["shared_queue_surface_rows"], finance_queue["alias_queue_surface_rows"], finance_queue["dead_queue_surface_rows"], finance_queue["evidence_gap_queue_surface_rows"], finance_queue["pending_unreviewed_queue_surface_rows"], finance_queue["queue_surfaces_without_ownership"]) == (507, 106, 84, 10, 5, 0, 7, 401, 423)
assert 3929 == 648 + 3281
assert 648 == 295 + 353
assert 3218 == 295 + 12 + 5 + 2906
assert 711 == 353 + 9 + 349
assert reviewed_finance_chart_route_action_overlay["page_context_boundary"] == {"literal_callsites": 6, "currently_owned_page_callsites": 2, "unowned_page_callsites": 4, "page_ownership_authorized": 0, "rule": "Page callsites remain context only and require separate outcome-neutral page review where still unowned."}
assert reviewed_finance_chart_route_action_overlay["credit_boundary"]["STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_7_RECORDS"] is True
assert reviewed_finance_chart_route_action_overlay_review["credit_boundary"]["INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"] is True
assert all(reviewed_finance_chart_route_action_overlay_review["credit_boundary"][key] is False for key in reviewed_finance_chart_route_action_overlay_review["credit_boundary"] if key != "INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING")
"""
    text = replace_once(text, semantic_anchor, semantic_addition, "dashboard Wave 18 assertions")
    evidence_anchor = '    ("RUN-119 respite handover page-gap reporting/hash receipt", "evidence/source/current-run-119-reviewed-respite-handover-page-gap-reporting-wave-17.json"),\n'
    evidence_addition = evidence_anchor + (
        '    ("RUN-120 verified superseded dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-120-wave-17.json"),\n'
        '    ("RUN-121 Finance chart route/action cohort generator", "generators/build-outcome-neutral-finance-chart-route-action-cohort-wave-18.py"),\n'
        '    ("RUN-121 22-route Finance outcome-neutral cohort", "evidence/source/root-run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18.json"),\n'
        '    ("RUN-121R Finance semantic-review materializer", "generators/materialize-independent-outcome-neutral-finance-chart-route-action-review-wave-18.py"),\n'
        '    ("RUN-121R 7-owner 7-shared 1-alias 7-gap review", "evidence/source/raw-run-121r-independent-outcome-neutral-finance-chart-route-action-review-wave-18.json"),\n'
        '    ("RUN-122 Finance route owner-only overlay generator", "generators/integrate-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.py"),\n'
        '    ("RUN-122 seven-route owner overlay with 15 non-owners", "evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json"),\n'
        '    ("RUN-122R independent Finance overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-review-wave-18.py"),\n'
        '    ("RUN-122R final-byte identity accounting and boundary review", "evidence/source/raw-run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json"),\n'
        '    ("RUN-123 Finance route/action reporting materializer", "generators/materialize-run-123-reviewed-finance-chart-route-action-reporting-wave-18.py"),\n'
        '    ("RUN-123 Finance route/action reporting/hash receipt", "evidence/source/current-run-123-reviewed-finance-chart-route-action-reporting-wave-18.json"),\n'
    )
    text = replace_once(text, evidence_anchor, evidence_addition, "dashboard Wave 18 evidence links")
    text = text.replace("RUN-071–119", "RUN-071–123").replace("RUN-077–119", "RUN-077–123")
    text = replace_once(text, 'f".{output_path.name}.tmp-run119-dashboard"', 'f".{output_path.name}.tmp-run123-dashboard"', "dashboard temp")
    for old, new in (
        ('static_owner_records=reviewed_respite_handover_page_overlay["combined_counts"]["source_owner_records"]', 'static_owner_records=reviewed_finance_chart_route_action_overlay["combined_counts"]["source_owner_records"]'),
        ('static_owner_routes=reviewed_respite_handover_page_overlay["combined_counts"]["route_owner_records"]', 'static_owner_routes=reviewed_finance_chart_route_action_overlay["combined_counts"]["route_owner_records"]'),
        ('static_owner_pages=reviewed_respite_handover_page_overlay["combined_counts"]["page_owner_records"]', 'static_owner_pages=reviewed_finance_chart_route_action_overlay["combined_counts"]["page_owner_records"]'),
        ('static_owner_features=reviewed_respite_handover_page_overlay["combined_counts"]["distinct_feature_ids"]', 'static_owner_features=reviewed_finance_chart_route_action_overlay["combined_counts"]["distinct_feature_ids"]'),
        ('static_owner_h_features=reviewed_respite_handover_page_overlay["combined_counts"]["distinct_H_feature_ids"]', 'static_owner_h_features=reviewed_finance_chart_route_action_overlay["combined_counts"]["distinct_H_feature_ids"]'),
        ('static_owner_d_features=reviewed_respite_handover_page_overlay["combined_counts"]["distinct_D_feature_ids"]', 'static_owner_d_features=reviewed_finance_chart_route_action_overlay["combined_counts"]["distinct_D_feature_ids"]'),
        ('route_feature_ids=reviewed_respite_handover_page_overlay["combined_counts"]["route_distinct_feature_ids"]', 'route_feature_ids=reviewed_finance_chart_route_action_overlay["combined_counts"]["route_distinct_feature_ids"]'),
        ('page_feature_ids=reviewed_respite_handover_page_overlay["combined_counts"]["page_distinct_feature_ids"]', 'page_feature_ids=reviewed_finance_chart_route_action_overlay["combined_counts"]["page_distinct_feature_ids"]'),
        ('route_page_overlap=reviewed_respite_handover_page_overlay["combined_counts"]["route_page_feature_overlap"]', 'route_page_overlap=reviewed_finance_chart_route_action_overlay["combined_counts"]["route_page_feature_overlap"]'),
        ('static_action_bridges=reviewed_respite_handover_page_overlay["combined_counts"]["static_controller_action_bridges"]', 'static_action_bridges=reviewed_finance_chart_route_action_overlay["combined_counts"]["static_controller_action_bridges"]'),
        ('static_residual=f"{reviewed_respite_handover_page_overlay[\'combined_counts\'][\'bounded_static_source_residual_records\']:,}"', 'static_residual=f"{reviewed_finance_chart_route_action_overlay[\'combined_counts\'][\'bounded_static_source_residual_records\']:,}"'),
        ('ownership_percent=reviewed_respite_handover_page_overlay["combined_counts"]["bounded_static_source_ownership_percent"]', 'ownership_percent=reviewed_finance_chart_route_action_overlay["combined_counts"]["bounded_static_source_ownership_percent"]'),
        ('route_residual=f"{reviewed_name_only_route_action_overlay[\'combined_counts\'][\'residual_explicit_unmapped_routes\']:,}"', 'route_residual=f"{reviewed_finance_chart_route_action_overlay[\'combined_counts\'][\'residual_explicit_unmapped_routes\']:,}"'),
        ('route_shared_current=reviewed_name_only_route_action_overlay["combined_counts"]["semantic_shared_routes"]', 'route_shared_current=reviewed_finance_chart_route_action_overlay["combined_counts"]["semantic_shared_routes"]'),
        ('route_alias_current=reviewed_name_only_route_action_overlay["combined_counts"]["reviewed_alias_routes"]', 'route_alias_current=reviewed_finance_chart_route_action_overlay["combined_counts"]["reviewed_alias_routes"]'),
        ('page_shared=reviewed_respite_handover_page_overlay["combined_counts"]["semantic_shared_page_roots"]', 'page_shared=reviewed_finance_chart_route_action_overlay["combined_counts"]["semantic_shared_page_roots"]'),
        ('page_residual=reviewed_respite_handover_page_overlay["combined_counts"]["residual_unadjudicated_page_roots"]', 'page_residual=reviewed_finance_chart_route_action_overlay["combined_counts"]["residual_unadjudicated_page_roots"]'),
        ('page_gap=reviewed_respite_handover_page_overlay["combined_counts"]["evidence_gap_page_roots_tagged_within_residual"]', 'page_gap=reviewed_finance_chart_route_action_overlay["combined_counts"]["evidence_gap_page_roots_tagged_within_residual"]'),
    ):
        text = replace_once(text, old, new, old)
    for field in ("direct_exact_queue_records", "reviewed_queue_surface_rows", "pending_unreviewed_queue_surface_rows", "queue_surfaces_without_ownership", "owner_queue_surface_rows", "shared_queue_surface_rows", "alias_queue_surface_rows"):
        text = text.replace(f'reviewed_respite_handover_page_overlay["queue_accounting"]["{field}"]', f'reviewed_finance_chart_route_action_overlay["queue_accounting"]["{field}"]')
    substitutions_anchor = '    respite_page_review_owner=reviewed_respite_handover_page_overlay["reviewed_overlay"]["owner_pages"],\n'
    substitutions_addition = substitutions_anchor + (
        '    finance_wave_reviewed=reviewed_finance_chart_route_action_overlay["reviewed_overlay"]["reviewed_route_actions"],\n'
        '    finance_review_owner=reviewed_finance_chart_route_action_overlay["reviewed_overlay"]["owner_route_actions"],\n'
        '    finance_review_shared=reviewed_finance_chart_route_action_overlay["reviewed_overlay"]["shared_relations"],\n'
        '    finance_review_alias=reviewed_finance_chart_route_action_overlay["reviewed_overlay"]["alias_or_redirect"],\n'
        '    finance_review_dead=reviewed_finance_chart_route_action_overlay["reviewed_overlay"]["dead_or_noncanonical"],\n'
        '    finance_review_gap=reviewed_finance_chart_route_action_overlay["reviewed_overlay"]["evidence_gaps"],\n'
        '    finance_page_calls=reviewed_finance_chart_route_action_overlay["page_context_boundary"]["literal_callsites"],\n'
        '    finance_page_owned=reviewed_finance_chart_route_action_overlay["page_context_boundary"]["currently_owned_page_callsites"],\n'
        '    finance_page_unowned=reviewed_finance_chart_route_action_overlay["page_context_boundary"]["unowned_page_callsites"],\n'
        '    finance_page_authorized=reviewed_finance_chart_route_action_overlay["page_context_boundary"]["page_ownership_authorized"],\n'
        '    queue_gap=reviewed_finance_chart_route_action_overlay["queue_accounting"]["evidence_gap_queue_surface_rows"],\n'
    )
    text = replace_once(text, substitutions_anchor, substitutions_addition, "dashboard Finance substitutions")
    html_replacements = (
        ('href="#checkpoint">RUN-119</a>', 'href="#checkpoint">RUN-123</a>'),
        ("RUN-101/R–116 remain historical route/action, page-owner, reporting, and exact dashboard checkpoints. RUN-117/R review $respite_page_wave_reviewed Respite handover page gaps as $respite_page_review_owner explicit page owners; RUN-118/R establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges. The four page decisions are based on complete page semantics and are not inherited from parent route ownership.", "RUN-101/R–120 remain historical route/action, page-owner, reporting, and exact dashboard checkpoints. RUN-121/R review $finance_wave_reviewed Finance route actions as $finance_review_owner owners, $finance_review_shared shared, $finance_review_alias alias, $finance_review_dead dead, and $finance_review_gap evidence gaps; RUN-122/R establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges. Only seven account actions add route ownership; all 15 non-owner outcomes and zero page credit are preserved."),
        ("RUN-113/R–116 preserve the historical name-only route/action checkpoint and exact superseded dashboard verification. RUN-117/R–118/R add $respite_page_review_owner explicit page owners, raising bounded ownership to $static_owner_records records while route, bridge, feature, and queue counts remain unchanged; $queue_reviewed queue rows are reviewed, $queue_pending remain pending, and $queue_without_owner remain without ownership. RUN-119 reports only that bounded delta.", "RUN-113/R–120 preserve the historical name-only route/action and Respite page-owner checkpoints with exact dashboard receipts. RUN-121/R–122/R add $finance_review_owner Finance route owners and seven bridges, preserve $finance_review_shared shared, $finance_review_alias alias, and $finance_review_gap gap outcomes, and add zero page owners; $queue_reviewed queue rows are reviewed, $queue_pending remain pending, and $queue_without_owner remain without ownership. RUN-123 reports only that bounded delta."),
        ("RUN-113/R–116 preserve the reviewed name-only route/action checkpoint and exact superseded dashboard verification; RUN-117/R–118/R independently review, integrate, and verify four explicit page owners with zero route, bridge, feature, or queue changes, and RUN-119 refreshes current reporting.", "RUN-113/R–120 preserve historical reviewed route/action and page-owner checkpoints with exact dashboard receipts; RUN-121/R–122/R independently review, integrate, and verify seven Finance route owners plus seven bridges while preserving 15 non-owner outcomes and zero page credit, and RUN-123 refreshes current reporting."),
        ("<tr><td>RUN-117/R → 118/R current Respite handover page overlay</td><td><strong>$respite_page_wave_reviewed reviewed = $respite_page_review_owner owner pages · 4 page rows · 0 route/bridge/queue rows</strong></td><td class=\"partial\">$static_owner_records cumulative owners · $static_owner_routes routes + $static_owner_pages pages · Gate 4 incomplete</td></tr><tr><td>RUN-119 reporting refresh</td><td><strong>four-page owner overlay reported</strong></td><td class=\"partial\">audit-only materialization · matrix byte-identical · fresh RUN-120 verification required</td></tr>", "<tr><td>RUN-117/R → 120 historical Respite handover page overlay</td><td><strong>$respite_page_wave_reviewed reviewed = $respite_page_review_owner owner pages · 4 page rows · 0 route/bridge/queue rows</strong></td><td class=\"partial\">641 cumulative owners · exact superseded dashboard verified</td></tr><tr><td>RUN-121/R → 122/R current Finance route/action overlay</td><td><strong>$finance_wave_reviewed reviewed = $finance_review_owner owner + $finance_review_shared shared + $finance_review_alias alias + $finance_review_dead dead + $finance_review_gap gap · 7 route rows · 7 bridges · 0 page rows</strong></td><td class=\"partial\">$static_owner_records cumulative owners · $static_owner_routes routes + $static_owner_pages pages · Gate 4 incomplete</td></tr><tr><td>RUN-123 reporting refresh</td><td><strong>Finance route/action overlay reported</strong></td><td class=\"partial\">audit-only materialization · matrix byte-identical · fresh RUN-124 verification required</td></tr>"),
        ("<li>RUN-117/R: $respite_page_wave_reviewed Respite handover page gaps · $respite_page_review_owner explicit page owners · Site/privacy/permission/direct-object risks retained</li><li>RUN-118/R: four page rows integrated and independently verified · zero route/bridge/queue changes · $static_owner_records cumulative owner records</li><li>RUN-119: deterministic four-page reporting refresh · matrix and every Site/permission/privacy/direct-object/lifecycle/concurrency/execution/benchmark/Pass/finding/completion boundary unchanged</li>", "<li>RUN-117/R–120: historical four-page Respite handover review, integration, reporting, and exact superseded dashboard receipt</li><li>RUN-121/R: $finance_wave_reviewed Finance route actions · $finance_review_owner owners · $finance_review_shared shared · $finance_review_alias alias · $finance_review_gap gaps · zero page credit</li><li>RUN-122/R: seven route rows and bridges integrated and independently verified · 15 non-owner outcomes preserved · $static_owner_records cumulative owner records</li><li>RUN-123: deterministic Finance reporting refresh · matrix and every Site/permission/privacy/direct-object/ledger/lifecycle/concurrency/execution/benchmark/Pass/finding/completion boundary unchanged</li>"),
        ("RUN-113/R–116 preserve the reviewed name-only route/action checkpoint and exact dashboard receipt; RUN-117/R–118/R add four independently reviewed Respite handover page owners without route, bridge, feature, or queue changes, and RUN-119 refreshes reporting.", "RUN-113/R–120 preserve historical reviewed route/action and page-owner checkpoints with exact dashboard receipts; RUN-121/R–122/R add seven independently reviewed Finance route owners and seven bridges while preserving 15 non-owner outcomes and zero page credit, and RUN-123 refreshes reporting."),
        ("<tr><td>RUN-118/R current Respite handover page ownership</td><td>$static_owner_records = $static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs = $static_owner_h_features H + $static_owner_d_features D · $static_action_bridges action bridges</td><td class=\"partial\">$ownership_percent% of bounded 3,929 · $static_residual residual · features $route_feature_ids route / $page_feature_ids page / $route_page_overlap overlap · routes 3,218 = $static_owner_routes owner + $route_shared_current shared + $route_alias_current alias + $route_residual residual · pages 711 = $static_owner_pages owner + $page_shared shared + $page_residual residual with $page_gap tagged gap · historical RUN-114 page-context observations were $page_context_calls = $page_context_owned already-owned + $page_context_gaps gaps + $page_context_authorized page credit before the separate RUN-117/R review · Gate 4 incomplete · matrix unchanged</td></tr>", "<tr><td>RUN-122/R current Finance route/action ownership</td><td>$static_owner_records = $static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs = $static_owner_h_features H + $static_owner_d_features D · $static_action_bridges action bridges</td><td class=\"partial\">$ownership_percent% of bounded 3,929 · $static_residual residual · features $route_feature_ids route / $page_feature_ids page / $route_page_overlap overlap · routes 3,218 = $static_owner_routes owner + $route_shared_current shared + $route_alias_current alias + $route_residual residual with $finance_review_gap tagged gaps · pages 711 = $static_owner_pages owner + $page_shared shared + $page_residual residual with $page_gap tagged gap · Finance page calls $finance_page_calls = $finance_page_owned already-owned + $finance_page_unowned unowned + $finance_page_authorized page credit · Gate 4 incomplete · matrix unchanged</td></tr>"),
        ("reviewed = $queue_owner owned + $queue_shared shared + $queue_alias alias · $queue_without_owner without ownership", "reviewed = $queue_owner owned + $queue_shared shared + $queue_alias alias + $queue_gap gap · $queue_without_owner without ownership"),
        ("RUN-118/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges while adding four explicit page owners with zero route, bridge, feature, or queue changes;", "RUN-122/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges while adding seven Finance route owners and seven bridges, preserving $finance_review_shared shared, $finance_review_alias alias, and $finance_review_gap gap outcomes, and adding zero page owners;"),
        ("RUN-001 through RUN-119", "RUN-001 through RUN-123"),
    )
    for old, new in html_replacements:
        text = replace_once(text, old, new, old[:80])
    prior_old = "RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, RUN-094, RUN-100, RUN-104, RUN-108, RUN-112, and RUN-116 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-119."
    prior_new = "RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, RUN-094, RUN-100, RUN-104, RUN-108, RUN-112, RUN-116, and RUN-120 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-123."
    text = replace_once(text, prior_old, prior_new, "prior RUN120 verification paragraph")
    prior_link = '<li><a href="evidence/browser/current-audit-dashboard-verification-run-116-wave-16.json">Superseded RUN-116 verification GO</a></li>'
    text = replace_once(text, prior_link, prior_link + '<li><a href="evidence/browser/current-audit-dashboard-verification-run-120-wave-17.json">Superseded RUN-120 verification GO</a></li>', "prior RUN120 link")
    fresh_start = '<section class="panel"><h2>Fresh RUN-120 audit-dashboard verification</h2>'
    fresh_end = '\n    <section class="panel"><h2>RUN-071–123 evidence lineage</h2>'
    fresh_replacement = '<section class="panel"><h2>Fresh RUN-124 audit-dashboard verification</h2><p>The exact regenerated RUN-123 dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-124 receipt must record page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 648/295/353 ownership, 7/7/1/0/7 Finance outcomes, 62/242/48 route/page/overlap feature sets, 83 bridges, route 3,218=295+12+5+2,906 with seven tagged gaps, page 711=353+9+349 with one tagged gap, queue 507=106+401 with 106=84+10+5+7 and 423 without ownership, 3,281 residual records, one operating organisation across multiple Sites, Gate 4 open, mapping 0/340, and all zero-credit boundaries. It verifies the audit artifact only and grants no application-browser, responsive-application, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-124-wave-18.json">RUN-124 responsive audit-dashboard verification receipt</a></li></ul></section>'
    text = replace_between(text, fresh_start, fresh_end, fresh_replacement, "fresh RUN124 section")
    text = replace_once(text, "Every current raw, generated, reviewed, and integrated RUN-077–119 source/reporting artifact", "Every current raw, generated, reviewed, and integrated RUN-077–123 source/reporting artifact", "dashboard lineage prose")
    text = replace_once(text, "Generated deterministically from independently reviewed static evidence through RUN-118/R and reported in RUN-119.", "Generated deterministically from independently reviewed static evidence through RUN-122/R and reported in RUN-123.", "dashboard footer")
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
        "status": "REVIEWED_FINANCE_CHART_ROUTE_ACTION_OVERLAY_REPORTED_GATE_4_INCOMPLETE",
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
            "overlay_sha256": PINNED_INPUTS["evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json"],
            "independent_overlay_review_sha256": PINNED_INPUTS["evidence/source/raw-run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json"],
            "superseded_dashboard_verification_sha256": PINNED_INPUTS["evidence/browser/current-audit-dashboard-verification-run-120-wave-17.json"],
        },
        "inputs": {**CURRENT_REPORT_INPUTS, **PINNED_INPUTS},
        "outputs": outputs,
        "preserved": preserved,
        "counts": {
            **overlay["combined_counts"],
            **overlay["queue_accounting"],
            "reviewed_finance_route_actions": 22,
            "reviewed_owner_route_actions_added": 7,
            "reviewed_shared_routes": 7,
            "reviewed_alias_routes_in_wave": 1,
            "reviewed_evidence_gap_routes": 7,
            "reviewed_non_owner_rows_preserved": 15,
            "controller_action_bridges_added": 7,
            "page_owner_records_added": 0,
            "provisional_finding_records": 12,
            "final_findings": 0,
            "matrix_rows_changed": 0,
            "matrix_cells_changed": 0,
        },
        "checks": {
            "run_121r_review_go": True,
            "run_121r_outcome_conservation": "22=7+7+1+0+7",
            "run_122r_overlay_review_go": True,
            "independent_review_discrepancies": 0,
            "route_owner_records_added": 7,
            "controller_action_bridges_added": 7,
            "reviewed_non_owner_rows_preserved": 15,
            "page_owner_records_added": 0,
            "matrix_byte_identical": True,
            "provisional_finding_record_semantics_preserved": True,
            "application_source_paths_written": 0,
            "one_organisation_multi_site_architecture_preserved": True,
            "dashboard_requires_fresh_run_124_artifact_verification": True,
            "gate_4_complete": False,
        },
        "verified_overlay_credit_boundary": overlay["credit_boundary"],
        "credit_boundary": {
            "REPORTING_REFRESH_FOR_REVIEWED_OVERLAY": True,
            "new_source_ownership": False,
            "new_route_ownership": False,
            "new_page_ownership": False,
            "new_controller_action_bridge": False,
            "new_queue_review": False,
            "matrix_mutation": False,
            "application_source_mutation": False,
            "runtime": False,
            "database": False,
            "build": False,
            "application_browser": False,
            "executed_tests": False,
            "benchmark": False,
            "ease": False,
            "pass": False,
            "final_finding": False,
            "completion": False,
            "audit_complete": False,
        },
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
        "reviewed_queue_surfaces": receipt["counts"]["reviewed_queue_surface_rows"],
        "pending_queue_surfaces": receipt["counts"]["pending_unreviewed_queue_surface_rows"],
        "gate_4_complete": receipt["checks"]["gate_4_complete"],
    }, indent=2))


if __name__ == "__main__":
    main()
