#!/usr/bin/env python3
"""Report the independently reviewed RUN-126 Finance page-owner overlay.

Only the five current reporting surfaces are updated. Reports 02-12,
inventory, the 340-row matrix, and application source remain byte-identical.
The regenerated dashboard requires a fresh RUN-128 artifact-only receipt.
"""

from __future__ import annotations

import copy
import hashlib
import importlib.util
import json
import os
import subprocess
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
MATERIALIZER_RELATIVE = "generators/materialize-run-127-reviewed-finance-page-gap-reporting-wave-19.py"
OUTPUT_RELATIVE = "evidence/source/current-run-127-reviewed-finance-page-gap-reporting-wave-19.json"
SCHEMA_VERSION = "run-127-reviewed-finance-page-gap-reporting-wave-19-v1"
RUN_ID = "RUN-127-REVIEWED-FINANCE-PAGE-GAP-REPORTING-WAVE-19"

CHECKPOINT_COMMIT = "02bc1220a11a121cf0463ae8e76c290102a22d7b"
CHECKPOINT_TREE = "9050acbdfe114bb3ac99ddff80641ed74c8b9cdd"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
RECORDS_SHA256 = "118c1a1f19b2e300c77d5b4d71c60f75b7038382f3c71e18f078367f8473e260"
KNOWN_PREDECESSOR_RECEIPT_SHA256S = {
    "93c1c5436e050f5eeddb1c7048b95fc4a3e901df2a67391291d24eb1b303ea82",
    "be971a57182c8293cdc5b94988cebd8aff08d742f82d3d7347f79a1ff075c247",
    "41f41db7eb4a0dbe5864e17f6b0c400884e433bbaf540f12855577e909e15c9a",
    "01056f9a1e22837d088485f19a18de5063ab0c55eb4ac92708dbecfdaf4ae7ff",
}

CURRENT_REPORT_INPUTS = {
    "00-executive-summary.md": "de51c37dd492c32611f1e27ae29cb4cdbe114c22df9bed6b3cbda9651cc9821b",
    "01-repository-module-map.md": "b9383b190fc92c8261ef076094afdd23a1347e79e06cceb8d9bcdae1a7dd8a39",
    "13-unresolved-questions-and-evidence-gaps.md": "bc81e0e621763135e39a0d5b4c38a75e2ea380bb73d55a021991ab3f87fe5d45",
    "findings.json": "8decdbee29ef08dd1bd139371b45d70825b32b0a0aecc60296a61b03af7e84e9",
    "generators/build-current-audit-dashboard.py": "d69e79b03ef820b52b1146300452e1d55edfcf11ad2b60fd3efdb03e33fbda4c",
}

PINNED_INPUTS = {
    "generators/materialize-run-123-reviewed-finance-chart-route-action-reporting-wave-18.py": "4057dcf7fc745219a6fe4a47da141723503e8f6984ded0f36ee243a487946b03",
    "evidence/source/current-run-123-reviewed-finance-chart-route-action-reporting-wave-18.json": "ffa7c751fb6a87ed358f015d13a28f10a7e5404f3a9569c40dee1e74e25e98b2",
    "evidence/browser/current-audit-dashboard-verification-run-124-wave-18.json": "9eedea2c5d051693a3657614c2f4ce4a5d7afca03aa7e0330dfe254b714b0283",
    "generators/build-outcome-neutral-finance-page-gap-cohort-wave-19.py": "e27ba0b1c7cc4e0fdeeea67272efe628700e9b70dffdc9ef3210b449c7d2ca84",
    "evidence/source/root-run-125-outcome-neutral-finance-page-gap-cohort-wave-19.json": "7d0df6edfacb63a9a7ab64140d47b2570a617db0147e4b0be6d5317fe38e3d92",
    "generators/materialize-independent-outcome-neutral-finance-page-gap-review-wave-19.py": "4ea69659b9994458ad9993a3af65092362ceaf2c67af672b3ce962b40c60ef98",
    "evidence/source/raw-run-125r-independent-outcome-neutral-finance-page-gap-review-wave-19.json": "b26d70eeee965d7dcbbf8e3e439f54bd35b5ab7fa1dfbf7a26c278cc59bb6c73",
    "generators/integrate-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.py": "36f3afd3a3bf9cf1b20789b4a6ca762ad55409d769870f19ff100466d1c6fccc",
    "evidence/source/current-run-126-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json": "15ab65b479daa7e7c3f2f3fbd979a13ead87dfbedf31c163a27b5eb809b12f10",
    "generators/materialize-independent-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-review-wave-19.py": "c58b9a84e00577bf2891f7ea1136e3f26ad0a5efcc9abca5a38152e419420720",
    "evidence/source/raw-run-126r-independent-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json": "78d969e823885ed7a12a3b6c4e3b2856e91823588e4f51f9dbeefb12f5d22be2",
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


def read_json(relative: str) -> dict[str, Any]:
    value = json.loads(path(relative).read_text(encoding="utf-8"))
    assert isinstance(value, dict), relative
    return value


def git(*args: str) -> str:
    completed = subprocess.run(
        ["git", *args], cwd=REPO, check=True,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
    )
    return completed.stdout.strip()


def write_lf(relative: str, text: str) -> None:
    encoded = text.replace("\r\n", "\n").encode("utf-8")
    if path(relative).read_bytes() != encoded:
        path(relative).write_bytes(encoded)


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

    existing = read_json(OUTPUT_RELATIVE) if path(OUTPUT_RELATIVE).exists() else None
    for relative, expected in CURRENT_REPORT_INPUTS.items():
        allowed = {expected}
        if existing is not None:
            assert existing["run_id"] == RUN_ID
            assert existing["schema_version"] == SCHEMA_VERSION
            assert existing["pins"]["checkpoint_commit"] == CHECKPOINT_COMMIT
            assert (
                sha256_file(OUTPUT_RELATIVE) in KNOWN_PREDECESSOR_RECEIPT_SHA256S
                or existing["pins"]["materializer_sha256"] == sha256_file(MATERIALIZER_RELATIVE)
            )
            allowed.add(existing["outputs"][relative])
        actual = sha256_file(relative)
        assert actual in allowed, (relative, actual, sorted(allowed))
    for relative, expected in PINNED_INPUTS.items():
        assert sha256_file(relative) == expected, relative
    for relative, expected in PRESERVED.items():
        assert sha256_file(relative) == expected, relative

    overlay = read_json("evidence/source/current-run-126-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json")
    review = read_json("evidence/source/raw-run-126r-independent-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json")
    semantic_review = read_json("evidence/source/raw-run-125r-independent-outcome-neutral-finance-page-gap-review-wave-19.json")
    counts = overlay["combined_counts"]
    queue = overlay["queue_accounting"]
    assert (counts["source_owner_records"], counts["route_owner_records"], counts["page_owner_records"]) == (652, 295, 357)
    assert (counts["static_controller_action_bridges"], counts["bounded_static_source_residual_records"]) == (83, 3277)
    assert counts["bounded_static_source_ownership_percent"] == "16.594553"
    assert (counts["residual_unadjudicated_page_roots"], counts["semantic_shared_page_roots"]) == (345, 9)
    assert (queue["reviewed_queue_surface_rows"], queue["pending_unreviewed_queue_surface_rows"]) == (106, 401)
    assert overlay["page_context_boundary"]["remaining_unowned_from_run_121_context"] == 0
    assert overlay["page_context_boundary"]["journal_page_feature_repaired"] is True
    assert overlay["page_context_boundary"]["journal_parent_route_evidence_gap_preserved"] is True
    assert len(overlay["reviewed_non_owner_outcomes"]) == 15
    assert semantic_review["decision"]["verdict"] == "GO_4_EXPLICIT_OWNER_PAGE"
    assert (semantic_review["decision"]["chart_of_accounts_owner_pages"], semantic_review["decision"]["manual_journal_owner_pages"]) == (3, 1)
    assert review["decision"]["verdict"] == "GO"
    assert review["decision"]["reporting_promotion_authorized"] is True
    assert review["decision"]["gate_4_complete"] is False
    assert {key for key, value in review["credit_boundary"].items() if value} == {"INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"}
    return overlay, review


def patch_reports() -> None:
    summary_relative = "00-executive-summary.md"
    summary = path(summary_relative).read_text(encoding="utf-8")
    summary_block = """## RUN-113–127 reviewed route/action and page-ownership lineage

RUN-113/R–120 remain historical reviewed route/action and page-owner checkpoints. RUN-121/R–123 establish and report the bounded Finance route/action split: seven Chart of Accounts route owners and bridges, seven shared relations, one redirect alias, and seven evidence gaps. RUN-124 independently verifies that now-superseded dashboard at all four required viewports with audit-artifact credit only.

RUN-125 freezes the four then-unowned Finance page roots from the RUN-121 context without pre-awarding ownership. Three fresh reviewers read each complete page plus its controller, request, route, policy/model/service, database, and canonical-feature context. RUN-125R classifies all four as explicit `OWNER_PAGE`: `finance/accounts/Create`, `Show`, and `Edit` own Chart of Accounts page semantics, while `finance/journals/Create` owns Manual Journal page semantics.

RUN-126 integrates exactly those four page owners and no route owner, controller-action bridge, or direct-exact queue row. RUN-126R reproduces the final bytes, verifies all identities and conservation equations, and authorizes reporting only. The Manual Journal page mapping is repaired, but its parent `journals.create` route remains an evidence gap; the page decision does not inherit or mutate the route outcome. Accounting Period, Cost Centre, and Funding Stream canonical mapping repairs also remain open.

The current bounded checkpoint is **652 records = 295 routes + 357 pages across 256 canonical FEATURE-IDs (234 H + 22 D)**, with 83 controller-action bridges. Route and page owners span 62 and 242 FEATURE-IDs with 48 in their overlap. This is 16.594553% of the bounded 3,929-record source universe; 3,277 records remain. The route universe is unchanged at **3,218 = 295 owners + 12 shared + 5 aliases + 2,906 residual**, with seven evidence gaps tagged inside that residual. The page universe is **711 = 357 owners + 9 shared + 345 residual**, with one earlier evidence gap tagged inside its residual. Queue accounting is unchanged at **507 = 106 reviewed + 401 pending**; reviewed rows are 84 owned, 10 shared, 5 aliases, 0 dead, and 7 evidence gaps, while 423 remain without ownership.

RUN-127 reports only that bounded four-page delta. The exact regenerated dashboard requires a fresh RUN-128 artifact receipt. Oblivion Findings remains one operating organisation across multiple Sites. Framework reachability, navigation, Site access, roles/permissions, canonical object ownership, direct-object concealment, privacy, ledger/lifecycle/concurrency correctness, runtime, database, build, application browser, tests, benchmarks, ease, Passes, findings, completion, and Gate 4 remain separate open or zero-credit gates. The 340-row matrix remains byte-identical and mapping remains 0/340.

"""
    summary = replace_between(
        summary,
        "## RUN-113–123 reviewed route/action and page-ownership lineage\n",
        "## Current raw source census\n",
        summary_block,
        "summary RUN127 block",
    )
    evidence_anchor = "- `generators/materialize-run-123-reviewed-finance-chart-route-action-reporting-wave-18.py` and `evidence/source/current-run-123-reviewed-finance-chart-route-action-reporting-wave-18.json`: deterministic RUN-123 reporting refresh preserving the matrix, reports 02–12/inventory, and every downstream zero-credit boundary.\n"
    evidence_addition = evidence_anchor + (
        "- `evidence/browser/current-audit-dashboard-verification-run-124-wave-18.json`: exact now-superseded RUN-123 dashboard artifact verification at four viewports; zero application credit.\n"
        "- `generators/build-outcome-neutral-finance-page-gap-cohort-wave-19.py` and `evidence/source/root-run-125-outcome-neutral-finance-page-gap-cohort-wave-19.json`: exact zero-credit four-page Finance review cohort.\n"
        "- `generators/materialize-independent-outcome-neutral-finance-page-gap-review-wave-19.py` and `evidence/source/raw-run-125r-independent-outcome-neutral-finance-page-gap-review-wave-19.json`: fresh four-owner page semantic review with the Manual Journal page/route boundary explicit.\n"
        "- `generators/integrate-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.py` and `evidence/source/current-run-126-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json`: exact four-page owner-only overlay with zero route, bridge, queue, or matrix change.\n"
        "- `generators/materialize-independent-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-review-wave-19.py` and `evidence/source/raw-run-126r-independent-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json`: independent final-byte, identity, accounting, preservation, and boundary GO receipt.\n"
        "- `generators/materialize-run-127-reviewed-finance-page-gap-reporting-wave-19.py` and `evidence/source/current-run-127-reviewed-finance-page-gap-reporting-wave-19.json`: deterministic RUN-127 reporting refresh preserving the matrix, reports 02–12/inventory, and every downstream zero-credit boundary.\n"
    )
    summary = replace_once(summary, evidence_anchor, evidence_addition, "summary RUN127 evidence links")
    write_lf(summary_relative, summary)

    module_relative = "01-repository-module-map.md"
    module_map = path(module_relative).read_text(encoding="utf-8")
    module_block = """## RUN-113–127 reviewed route/action and page-ownership lineage

RUN-113/R–124 remain historical reviewed route/action, page-owner, reporting, and exact-dashboard checkpoints. RUN-121/R–122/R's Finance route review remains 7 owners, 7 shared relations, 1 alias, and 7 evidence gaps, with seven route owners and bridges integrated and all 15 non-owner outcomes preserved.

RUN-125/R separately review the four then-unowned Finance page roots as four explicit page owners: three Chart of Accounts pages and one Manual Journal page. RUN-126/R integrate and independently verify exactly those four page records with zero route, bridge, queue, feature-union, or matrix change.

The cumulative bounded ledger is 652 source owners (295 route + 357 page) across 256 FEATURE-IDs (234 H + 22 D). Route/page feature sets are 62/242 with overlap 48, and the action-bridge count is 83. Route accounting remains 3,218 = 295 owners + 12 shared + 5 aliases + 2,906 residual, with seven evidence gaps tagged within residual. Page accounting is 711 = 357 owners + 9 shared + 345 residual, with one earlier tagged evidence gap. RUN-090 queue accounting remains 507 total, 106 reviewed, 84 owned, 10 shared, 5 aliases, 7 evidence gaps, 401 pending, and 423 without ownership.

All six RUN-121 Finance page callsites are now bounded page owners: two pre-existing plus the four reviewed in RUN-125/R. The Manual Journal page mapping is repaired, but its parent route remains an evidence gap. Accounting Period, Cost Centre, and Funding Stream mapping repairs remain open. These relations establish bounded static ownership only; they do not establish framework reachability, Site or permission correctness, canonical direct-object concealment, privacy, ledger/lifecycle/concurrency correctness, runtime, build, browser, tests, benchmarks, findings, Passes, or completion.

"""
    module_map = replace_between(
        module_map,
        "## RUN-113–123 reviewed route/action and page-ownership lineage\n",
        "## Candidate register\n",
        module_block,
        "module map RUN127 block",
    )
    write_lf(module_relative, module_map)

    gaps_relative = "13-unresolved-questions-and-evidence-gaps.md"
    gaps = path(gaps_relative).read_text(encoding="utf-8")
    gaps = replace_line_containing(gaps, "| Required reporting paths |", "| Required reporting paths | 18 / 18 prompt-required files or directories present. RUN-124 independently verified the exact now-superseded RUN-123 dashboard at four viewports; the regenerated RUN-127 dashboard requires a separate fresh RUN-128 artifact receipt. | Presence and prior audit-artifact verification make the reporting contract inspectable but grant no application execution, final-finding, Pass, or completion credit. Gate 26 remains open. | Complete the semantic, execution, benchmark, Pass 8, final reconciliation, and no-live-agent gates; repeat audit-dashboard verification after every later HTML change. |")
    gaps = replace_line_containing(gaps, "| Runtime routes |", "| Runtime routes | RUN-126/R preserve 295 bounded route-owner records and 83 static controller-action bridges; 2,906 residual route rows, 12 semantic-shared route rows, and 5 reviewed aliases remain distinguished within the bounded 3,218-row static route-like universe, with 7 evidence gaps tagged inside residual. | Wave 19 adds zero route owner or bridge. Static owner/action linkage is not a framework-expanded route table, reachability proof, ledger-correctness proof, or authorization proof. | Under a fresh bounded runtime grant, use an exact disposable database, execute a separately pinned framework/provider route lane, and reconcile it to all 38 source route files and 3,218 route-like rows. |")
    gaps = replace_line_containing(gaps, "| Inertia pages |", "| Inertia pages | RUN-084/R enumerate 1,058 physical page-tree files. RUN-126/R establish 357 bounded page owners, nine semantic-shared roots, and 345 residual roots including one earlier tagged evidence gap. | Wave 19 adds exactly four Finance page owners after complete page review: three Chart of Accounts pages and one Manual Journal page. Full-tree structural GO and bounded ownership are not a complete canonical crosswalk, runtime reachability, build resolution, rendered browser behaviour, or final feature mapping. | Reconcile all 711 literal roots and support relations to safely expanded framework routes and frozen FEATURE-IDs, prove build resolution, and observe every safely reachable current-build page under approved roles/Sites. |")
    gaps = replace_line_containing(gaps, "| Canonical features |", "| Canonical features | Static canonical identity remains 340 targets: 300 H, 40 D, zero M; RUN-126/R establish 652 bounded source-owner records (295 routes + 357 pages) across 256 FEATURE-IDs (234 H + 22 D) plus 83 controller-action bridges while the matrix remains byte-identical at `dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390`. | This is 16.594553% of the bounded 3,929-record source universe. Gate 4 remains incomplete: 3,277 non-owner records, the framework-expanded denominator, shared, alias, and gap relations, reachability, and the full crosswalk remain open. Manual Journal page mapping is repaired while its parent route remains an evidence gap; Accounting Period, Cost Centre, and Funding Stream mapping repairs remain open; matrix target mapping stays 0/340. | Finish canonical denominator and residual ownership adjudication without inheriting FEATURE-IDs from prefixes, imports, containment, names, or presence, and without awarding runtime, browser, test, benchmark, ease, release, or completion credit. |")
    gaps = replace_line_containing(gaps, "| Agent universe and writer rule |", "| Agent universe and writer rule | RUN-001 through RUN-127 represented at the current reporting checkpoint; finalization gate false. | RUN-125/R review four Finance pages as four explicit owners; RUN-126/R independently integrate and verify only four page owners while preserving the parent Manual Journal route gap; RUN-127 reports only that bounded class. Runtime, browser, tests, benchmarks, Pass 8, finalization, and completion remain open. | Complete residual ownership and every semantic/execution gate, then dispatch fresh Pass 8/final cross-reviewers, verify the final dashboard, and prove no agent remains live at finalization. |")
    gaps = gaps.replace("## RUN-077–123 route/page, page-tree, backend, ownership and reporting lineage", "## RUN-077–127 route/page, page-tree, backend, ownership and reporting lineage")
    lineage_old = "RUN-077 freezes the exhaustive committed-source route/name/page universe through RUN-084B's page-tree and backend structural ledgers. RUN-086/R establish the initial 530 bounded source owners; RUN-090 freezes the zero-credit direct-exact queue; RUN-091/R–103 successively add and report reviewed closed-chain and route/action ownership while preserving shared and alias outcomes, reaching 592 owners. RUN-104 verifies that superseded dashboard. RUN-105/R–107 review, integrate, and report 20 page owners, three shared relations, and one evidence gap, reaching 612 owners; RUN-108 verifies that superseded dashboard. RUN-109/R review the six-page tail as two owners and four shared relations; RUN-110/R integrate and independently verify two page owners and one reviewed-shared queue reconciliation, reaching 614 owners; RUN-111 reports that delta and RUN-112 verifies its superseded dashboard. RUN-113/R review 24 name-only route actions as 23 owners and one alias; RUN-114/R integrate and verify 23 route owners plus 23 bridges, reaching 637 owners; RUN-115 reports that delta and RUN-116 verifies its superseded dashboard. RUN-117/R review four Respite handover page gaps as four explicit owners; RUN-118/R integrate and independently verify those four page owners, reaching 641 owners while route, bridge, feature, and queue counts remain unchanged; RUN-119 reports that delta and RUN-120 verifies its now-superseded dashboard. RUN-121/R review 22 Finance name-only route actions as 7 owners, 7 shared, 1 alias, 0 dead, and 7 gaps. RUN-122/R integrate and independently verify only 7 route owners and 7 bridges, preserve all 15 non-owner outcomes, and reach 648 owners; RUN-123 reports only that bounded delta. Gate 4 and the full route/page/backend crosswalk remain incomplete; Oblivion Findings remains one operating organisation across multiple Sites, and framework reachability, Site/permission/privacy/direct-object/ledger/lifecycle/concurrency correctness, runtime, build, signed-in application browser, executed tests, benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit."
    lineage_new = "RUN-077 freezes the exhaustive committed-source route/name/page universe through RUN-084B's page-tree and backend structural ledgers. RUN-086/R establish the initial 530 bounded source owners; RUN-090 freezes the zero-credit direct-exact queue; RUN-091/R–120 successively review, integrate, report, and verify bounded route/action and page ownership, reaching 641 owners while preserving explicit shared, alias, and gap outcomes. RUN-121/R review 22 Finance name-only route actions as 7 owners, 7 shared, 1 alias, 0 dead, and 7 gaps. RUN-122/R integrate and independently verify only 7 route owners and 7 bridges, preserve all 15 non-owner outcomes, and reach 648 owners; RUN-123 reports that delta and RUN-124 verifies its now-superseded dashboard. RUN-125/R review the four remaining Finance page callsites as four explicit owners. RUN-126/R integrate and independently verify only those four page owners, preserve the Manual Journal parent-route gap, leave route, bridge, queue, feature-union, and matrix counts unchanged, and reach 652 owners; RUN-127 reports only that bounded delta. Gate 4 and the full route/page/backend crosswalk remain incomplete; Oblivion Findings remains one operating organisation across multiple Sites, and framework reachability, Site/permission/privacy/direct-object/ledger/lifecycle/concurrency correctness, runtime, build, signed-in application browser, executed tests, benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit."
    gaps = replace_once(gaps, lineage_old, lineage_new, "gaps RUN127 lineage")
    write_lf(gaps_relative, gaps)


def patch_findings(overlay: dict[str, Any], review: dict[str, Any]) -> None:
    relative = "findings.json"
    findings = read_json(relative)
    counts = overlay["combined_counts"]
    queue = overlay["queue_accounting"]
    materializer_sha = sha256_file(MATERIALIZER_RELATIVE)
    findings["pins"].update({
        "run_123_reporting_sha256": PINNED_INPUTS["evidence/source/current-run-123-reviewed-finance-chart-route-action-reporting-wave-18.json"],
        "run_124_dashboard_verification_sha256": PINNED_INPUTS["evidence/browser/current-audit-dashboard-verification-run-124-wave-18.json"],
        "run_125_finance_page_gap_cohort_generator_sha256": PINNED_INPUTS["generators/build-outcome-neutral-finance-page-gap-cohort-wave-19.py"],
        "run_125_finance_page_gap_cohort_sha256": PINNED_INPUTS["evidence/source/root-run-125-outcome-neutral-finance-page-gap-cohort-wave-19.json"],
        "run_125r_finance_page_gap_review_materializer_sha256": PINNED_INPUTS["generators/materialize-independent-outcome-neutral-finance-page-gap-review-wave-19.py"],
        "run_125r_finance_page_gap_review_sha256": PINNED_INPUTS["evidence/source/raw-run-125r-independent-outcome-neutral-finance-page-gap-review-wave-19.json"],
        "run_126_finance_page_gap_overlay_generator_sha256": PINNED_INPUTS["generators/integrate-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.py"],
        "run_126_finance_page_gap_overlay_sha256": PINNED_INPUTS["evidence/source/current-run-126-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json"],
        "run_126r_finance_page_gap_overlay_review_materializer_sha256": PINNED_INPUTS["generators/materialize-independent-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-review-wave-19.py"],
        "run_126r_finance_page_gap_overlay_review_sha256": PINNED_INPUTS["evidence/source/raw-run-126r-independent-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json"],
        "run_127_reporting_materializer_sha256": materializer_sha,
    })
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

    if "historical_run_122_outcome_neutral_finance_chart_route_action_ownership" not in findings:
        findings["historical_run_122_outcome_neutral_finance_chart_route_action_ownership"] = copy.deepcopy(findings["current_static_source_feature_ownership"])
    if "historical_run_122_outcome_neutral_finance_chart_route_action_ownership_review" not in findings:
        findings["historical_run_122_outcome_neutral_finance_chart_route_action_ownership_review"] = copy.deepcopy(findings["current_outcome_neutral_finance_chart_route_action_ownership_review"])
    findings.pop("current_outcome_neutral_finance_chart_route_action_ownership_review", None)

    feature_distribution = {}
    for row in overlay["overlay_source_records"]:
        feature_distribution[row["feature_id"]] = feature_distribution.get(row["feature_id"], 0) + 1
    findings["current_static_source_feature_ownership"] = {
        "run_id": overlay["run_id"],
        "review_run_id": review["run_id"],
        "status": "GO_REVIEWED_BOUNDED_OUTCOME_NEUTRAL_FINANCE_PAGE_OWNERSHIP_ONLY",
        "baseline_records": overlay["baseline"]["source_owner_records"],
        "reviewed_pages": overlay["reviewed_overlay"]["reviewed_pages"],
        "overlay_source_records": len(overlay["overlay_source_records"]),
        "owner_pages_added": overlay["reviewed_overlay"]["owner_pages"],
        "shared_relations_added": overlay["reviewed_overlay"]["shared_relations"],
        "reviewed_alias_or_redirect": overlay["reviewed_overlay"]["alias_or_redirect"],
        "dead_or_noncanonical": overlay["reviewed_overlay"]["dead_or_noncanonical"],
        "evidence_gaps": overlay["reviewed_overlay"]["evidence_gaps"],
        "route_owner_records_added": overlay["reviewed_overlay"]["accepted_route_owner_records"],
        "page_owner_records_added": overlay["reviewed_overlay"]["accepted_page_owner_records"],
        "controller_action_bridges_added": overlay["reviewed_overlay"]["accepted_controller_action_bridges"],
        "accepted_distinct_feature_ids": overlay["reviewed_overlay"]["accepted_distinct_feature_ids"],
        "new_distinct_feature_ids": overlay["reviewed_overlay"]["new_distinct_feature_ids"],
        "new_feature_ids": overlay["reviewed_overlay"]["new_feature_ids"],
        "new_page_feature_ids": overlay["reviewed_overlay"]["new_page_feature_ids"],
        "feature_owner_distribution": feature_distribution,
        "baseline_reviewed_non_owner_records_preserved": len(overlay["reviewed_non_owner_outcomes"]),
        "combined_counts": counts,
        "queue_accounting": queue,
        "page_context_boundary": overlay["page_context_boundary"],
        "ownership_basis": "FRESH_COMPLETE_PAGE_SEMANTIC_REVIEW_NOT_PARENT_ROUTE_INHERITANCE",
        "identity": overlay["identity"],
        "outcome_conservation": overlay["outcome_conservation"],
        "independent_review_discrepancies": review["decision"]["mechanical_discrepancies"] + review["decision"]["semantic_discrepancies"],
        "gate_4": {"status": "PARTIAL_BOUNDED_STATIC_SOURCE_OWNERSHIP_INCOMPLETE", "complete": False},
        "credit_boundary": overlay["credit_boundary"],
    }
    findings["current_outcome_neutral_finance_page_gap_ownership_review"] = {
        "run_id": review["run_id"],
        "status": review["status"],
        "reviewers": len(review["reviewers"]),
        "page_owner_records_verified": review["decision"]["owner_overlay_records_verified"],
        "route_owner_records_authorized": 0,
        "controller_action_bridges_authorized": 0,
        "direct_exact_queue_rows_authorized": 0,
        "journal_parent_route_gap_preserved": review["decision"]["journal_parent_route_gap_preserved"],
        "mechanical_discrepancies": review["decision"]["mechanical_discrepancies"],
        "semantic_discrepancies": review["decision"]["semantic_discrepancies"],
        "reporting_materialization_authorized": review["decision"]["reporting_promotion_authorized"],
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

    run124 = read_json("evidence/browser/current-audit-dashboard-verification-run-124-wave-18.json")
    verification = run124["verification"]
    findings["current_audit_artifact_verification_history"]["run_124"] = {
        "status": run124["status"],
        "dashboard_sha256": run124["pins"]["dashboard_html_sha256"],
        "receipt_sha256": PINNED_INPUTS["evidence/browser/current-audit-dashboard-verification-run-124-wave-18.json"],
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
    write_lf(relative, json.dumps(findings, ensure_ascii=False, indent=2) + "\n")


def patch_dashboard_template() -> None:
    relative = "generators/build-current-audit-dashboard.py"
    text = path(relative).read_text(encoding="utf-8")
    read_anchor = 'reviewed_finance_chart_route_action_overlay_review = read_json("evidence/source/raw-run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json")\n'
    read_addition = read_anchor + (
        'finance_page_gap_cohort = read_json("evidence/source/root-run-125-outcome-neutral-finance-page-gap-cohort-wave-19.json")\n'
        'finance_page_gap_review = read_json("evidence/source/raw-run-125r-independent-outcome-neutral-finance-page-gap-review-wave-19.json")\n'
        'reviewed_finance_page_gap_overlay = read_json("evidence/source/current-run-126-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json")\n'
        'reviewed_finance_page_gap_overlay_review = read_json("evidence/source/raw-run-126r-independent-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json")\n'
    )
    text = replace_once(text, read_anchor, read_addition, "dashboard RUN125-126 reads")

    pin_anchor = 'assert sha256_file("evidence/source/raw-run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json") == "2130e3801b6ac163580bc56f23d6647136c83fdadc8ea65804b1559d36b29484"\n'
    pin_lines = "".join(
        f'assert sha256_file("{relative_path}") == "{expected}"\n'
        for relative_path, expected in PINNED_INPUTS.items()
    )
    self_pin = f'assert sha256_file("{MATERIALIZER_RELATIVE}") == "{sha256_file(MATERIALIZER_RELATIVE)}"'
    if MATERIALIZER_RELATIVE in text:
        text = replace_line_containing(text, f'assert sha256_file("{MATERIALIZER_RELATIVE}")', self_pin)
    else:
        text = replace_once(text, pin_anchor, pin_anchor + pin_lines + self_pin + "\n", "dashboard RUN123-127 pins")

    semantic_anchor = 'assert all(reviewed_finance_chart_route_action_overlay_review["credit_boundary"][key] is False for key in reviewed_finance_chart_route_action_overlay_review["credit_boundary"] if key != "INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING")\n'
    semantic_block_start = 'assert finance_page_gap_cohort["counts"]["candidate_page_records"] == 4\n'
    semantic_block_end = 'candidates = wave1["candidates"] + wave2["candidates"] + wave3["candidates"]'
    if semantic_block_start in text:
        block_start_index = text.index(semantic_block_start)
        block_end_index = text.index(semantic_block_end, block_start_index)
        text = text[:block_start_index] + text[block_end_index:]
    semantic_addition = semantic_anchor + """
assert finance_page_gap_cohort["counts"]["candidate_page_records"] == 4
assert finance_page_gap_cohort["counts"]["ownership_credit_awarded"] == 0
assert finance_page_gap_review["decision"]["verdict"] == "GO_4_EXPLICIT_OWNER_PAGE"
assert finance_page_gap_review["decision"]["owner_pages"] == 4
assert (finance_page_gap_review["decision"]["chart_of_accounts_owner_pages"], finance_page_gap_review["decision"]["manual_journal_owner_pages"]) == (3, 1)
assert reviewed_finance_page_gap_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_finance_page_gap_overlay_review["decision"]["reporting_promotion_authorized"] is True
assert reviewed_finance_page_gap_overlay_review["decision"]["gate_4_complete"] is False
assert len(reviewed_finance_page_gap_overlay["overlay_source_records"]) == 4
assert reviewed_finance_page_gap_overlay["new_static_controller_action_bridges"] == []
assert len(reviewed_finance_page_gap_overlay["reviewed_non_owner_outcomes"]) == 15
page_finance_counts = reviewed_finance_page_gap_overlay["combined_counts"]
page_finance_queue = reviewed_finance_page_gap_overlay["queue_accounting"]
assert (page_finance_counts["source_owner_records"], page_finance_counts["route_owner_records"], page_finance_counts["page_owner_records"]) == (652, 295, 357)
assert (page_finance_counts["distinct_feature_ids"], page_finance_counts["distinct_H_feature_ids"], page_finance_counts["distinct_D_feature_ids"]) == (256, 234, 22)
assert (page_finance_counts["route_distinct_feature_ids"], page_finance_counts["page_distinct_feature_ids"], page_finance_counts["route_page_feature_overlap"]) == (62, 242, 48)
assert (page_finance_counts["static_controller_action_bridges"], page_finance_counts["bounded_static_source_residual_records"]) == (83, 3277)
assert page_finance_counts["bounded_static_source_ownership_percent"] == "16.594553"
assert (page_finance_counts["residual_explicit_unmapped_routes"], page_finance_counts["semantic_shared_routes"], page_finance_counts["reviewed_alias_routes"], page_finance_counts["evidence_gap_routes_tagged_within_residual"]) == (2906, 12, 5, 7)
assert (page_finance_counts["residual_unadjudicated_page_roots"], page_finance_counts["semantic_shared_page_roots"], page_finance_counts["evidence_gap_page_roots_tagged_within_residual"]) == (345, 9, 1)
assert (page_finance_queue["direct_exact_queue_records"], page_finance_queue["reviewed_queue_surface_rows"], page_finance_queue["owner_queue_surface_rows"], page_finance_queue["shared_queue_surface_rows"], page_finance_queue["alias_queue_surface_rows"], page_finance_queue["dead_queue_surface_rows"], page_finance_queue["evidence_gap_queue_surface_rows"], page_finance_queue["pending_unreviewed_queue_surface_rows"], page_finance_queue["queue_surfaces_without_ownership"]) == (507, 106, 84, 10, 5, 0, 7, 401, 423)
assert all(page_finance_queue[key] == 0 for key in ("new_reviewed_route_surface_rows", "new_owner_route_surface_rows", "new_shared_route_surface_rows", "new_alias_route_surface_rows", "new_evidence_gap_route_surface_rows", "new_reviewed_page_surface_rows", "new_owner_page_surface_rows"))
assert reviewed_finance_page_gap_overlay["page_context_boundary"]["remaining_unowned_from_run_121_context"] == 0
assert reviewed_finance_page_gap_overlay["page_context_boundary"]["journal_page_feature_repaired"] is True
assert reviewed_finance_page_gap_overlay["page_context_boundary"]["journal_parent_route_evidence_gap_preserved"] is True
assert reviewed_finance_page_gap_overlay["credit_boundary"]["STATIC_PAGE_FEATURE_OWNERSHIP_FOR_4_RECORDS"] is True
assert reviewed_finance_page_gap_overlay_review["credit_boundary"]["INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"] is True
assert all(reviewed_finance_page_gap_overlay_review["credit_boundary"][key] is False for key in reviewed_finance_page_gap_overlay_review["credit_boundary"] if key != "INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING")
assert 3929 == 652 + 3277
assert 652 == 295 + 357
assert 3218 == 295 + 12 + 5 + 2906
assert 711 == 357 + 9 + 345
"""
    text = replace_once(text, semantic_anchor, semantic_addition, "dashboard RUN125-126 assertions")

    evidence_anchor = '    ("RUN-123 Finance route/action reporting/hash receipt", "evidence/source/current-run-123-reviewed-finance-chart-route-action-reporting-wave-18.json"),\n'
    evidence_addition = evidence_anchor + (
        '    ("RUN-124 verified superseded dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-124-wave-18.json"),\n'
        '    ("RUN-125 Finance page-gap cohort generator", "generators/build-outcome-neutral-finance-page-gap-cohort-wave-19.py"),\n'
        '    ("RUN-125 four-page outcome-neutral cohort", "evidence/source/root-run-125-outcome-neutral-finance-page-gap-cohort-wave-19.json"),\n'
        '    ("RUN-125R Finance page semantic-review materializer", "generators/materialize-independent-outcome-neutral-finance-page-gap-review-wave-19.py"),\n'
        '    ("RUN-125R four-owner Finance page review", "evidence/source/raw-run-125r-independent-outcome-neutral-finance-page-gap-review-wave-19.json"),\n'
        '    ("RUN-126 Finance page owner-only overlay generator", "generators/integrate-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.py"),\n'
        '    ("RUN-126 four-page owner overlay", "evidence/source/current-run-126-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json"),\n'
        '    ("RUN-126R independent Finance page overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-review-wave-19.py"),\n'
        '    ("RUN-126R final-byte identity accounting and boundary review", "evidence/source/raw-run-126r-independent-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json"),\n'
        '    ("RUN-127 Finance page-gap reporting materializer", "generators/materialize-run-127-reviewed-finance-page-gap-reporting-wave-19.py"),\n'
        '    ("RUN-127 Finance page-gap reporting/hash receipt", "evidence/source/current-run-127-reviewed-finance-page-gap-reporting-wave-19.json"),\n'
    )
    text = replace_once(text, evidence_anchor, evidence_addition, "dashboard RUN124-127 evidence")

    text = replace_once(text, 'href="#checkpoint">RUN-123</a>', 'href="#checkpoint">RUN-127</a>', "dashboard nav checkpoint")
    text = replace_once(text, "RUN-071–123 current reporting checkpoint:", "RUN-071–127 current reporting checkpoint:", "dashboard notice heading RUN127")
    text = replace_once(text, "RUN-071–123 completion-gate checkpoint", "RUN-071–127 completion-gate checkpoint", "dashboard checkpoint heading RUN127")
    old_overview = "RUN-101/R–120 remain historical route/action, page-owner, reporting, and exact dashboard checkpoints. RUN-121/R review $finance_wave_reviewed Finance route actions as $finance_review_owner owners, $finance_review_shared shared, $finance_review_alias alias, $finance_review_dead dead, and $finance_review_gap evidence gaps; RUN-122/R establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges. Only seven account actions add route ownership; all 15 non-owner outcomes and zero page credit are preserved."
    new_overview = "RUN-101/R–124 remain historical route/action, page-owner, reporting, and exact dashboard checkpoints. RUN-125/R review $finance_page_wave_reviewed Finance page gaps as $finance_page_review_owner explicit owners; RUN-126/R establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges. Exactly four page owners are added; route, bridge, queue, feature-union, and matrix counts remain unchanged from RUN-122, and the Manual Journal parent route remains a gap."
    text = replace_once(text, old_overview, new_overview, "dashboard overview RUN127")
    old_checkpoint = "RUN-113/R–120 preserve the historical name-only route/action and Respite page-owner checkpoints with exact dashboard receipts. RUN-121/R–122/R add $finance_review_owner Finance route owners and seven bridges, preserve $finance_review_shared shared, $finance_review_alias alias, and $finance_review_gap gap outcomes, and add zero page owners; $queue_reviewed queue rows are reviewed, $queue_pending remain pending, and $queue_without_owner remain without ownership. RUN-123 reports only that bounded delta."
    new_checkpoint = "RUN-113/R–124 preserve historical route/action and page-owner checkpoints with exact dashboard receipts. RUN-125/R–126/R add $finance_page_review_owner Finance page owners, preserve the Manual Journal parent-route gap, and add zero route, bridge, queue, feature-union, or matrix change; $queue_reviewed queue rows are reviewed, $queue_pending remain pending, and $queue_without_owner remain without ownership. RUN-127 reports only that bounded delta."
    text = replace_once(text, old_checkpoint, new_checkpoint, "dashboard checkpoint RUN127")
    old_chronology = "RUN-113/R–120 preserve historical reviewed route/action and page-owner checkpoints with exact dashboard receipts; RUN-121/R–122/R independently review, integrate, and verify seven Finance route owners plus seven bridges while preserving 15 non-owner outcomes and zero page credit, and RUN-123 refreshes current reporting."
    new_chronology = "RUN-113/R–124 preserve historical reviewed route/action and page-owner checkpoints with exact dashboard receipts; RUN-125/R–126/R independently review, integrate, and verify four Finance page owners while preserving the Manual Journal parent-route gap and zero route, bridge, queue, feature-union, or matrix change, and RUN-127 refreshes current reporting."
    text = replace_once(text, old_chronology, new_chronology, "dashboard chronology RUN127")

    progress_start = '<tr><td>RUN-121/R → 122/R current Finance route/action overlay'
    progress_end = '</tbody>'
    progress_replacement = '<tr><td>RUN-121/R → 124 historical Finance route/action overlay</td><td><strong>$finance_wave_reviewed reviewed = $finance_review_owner owner + $finance_review_shared shared + $finance_review_alias alias + $finance_review_dead dead + $finance_review_gap gap · 7 route rows · 7 bridges · 0 page rows</strong></td><td class="partial">648 cumulative owners · exact superseded dashboard verified</td></tr><tr><td>RUN-125/R → 126/R current Finance page-gap overlay</td><td><strong>$finance_page_wave_reviewed reviewed = $finance_page_review_owner owner pages · 4 page rows · 0 route/bridge/queue rows</strong></td><td class="partial">$static_owner_records cumulative owners · $static_owner_routes routes + $static_owner_pages pages · Gate 4 incomplete</td></tr><tr><td>RUN-127 reporting refresh</td><td><strong>Finance page-gap overlay reported</strong></td><td class="partial">audit-only materialization · matrix byte-identical · fresh RUN-128 verification required</td></tr>'
    text = replace_between(text, progress_start, progress_end, progress_replacement, "dashboard progress rows RUN127")
    text = replace_once(text, "RUN-001 through RUN-123 are represented by audit artifacts", "RUN-001 through RUN-127 are represented by audit artifacts", "dashboard agent universe")

    bullet_start = '<li>RUN-121/R: $finance_wave_reviewed Finance route actions'
    bullet_end = '</ul>'
    bullet_replacement = '<li>RUN-121/R–124: historical Finance route/action review, integration, reporting, and exact superseded dashboard receipt</li><li>RUN-125/R: $finance_page_wave_reviewed Finance page gaps · $finance_page_review_owner explicit page owners · three Chart pages + one Manual Journal page</li><li>RUN-126/R: four page rows integrated and independently verified · Manual Journal parent-route gap preserved · zero route/bridge/queue change · $static_owner_records cumulative owner records</li><li>RUN-127: deterministic Finance page-gap reporting refresh · matrix and every Site/permission/privacy/direct-object/ledger/lifecycle/concurrency/execution/benchmark/Pass/finding/completion boundary unchanged</li>'
    text = replace_between(text, bullet_start, bullet_end, bullet_replacement, "dashboard RUN127 bullets")
    old_timeline = "RUN-113/R–120 preserve historical reviewed route/action and page-owner checkpoints with exact dashboard receipts; RUN-121/R–122/R add seven independently reviewed Finance route owners and seven bridges while preserving 15 non-owner outcomes and zero page credit, and RUN-123 refreshes reporting."
    new_timeline = "RUN-113/R–124 preserve historical reviewed route/action and page-owner checkpoints with exact dashboard receipts; RUN-125/R–126/R add four independently reviewed Finance page owners while preserving the Manual Journal parent-route gap and zero route, bridge, queue, feature-union, or matrix change, and RUN-127 refreshes reporting."
    text = replace_once(text, old_timeline, new_timeline, "dashboard timeline RUN127")

    current_row_start = '<tr><td>RUN-122/R current Finance route/action ownership'
    current_row_end = '</tr>'
    current_row = '<tr><td>RUN-126/R current Finance route/action and page ownership</td><td>$static_owner_records = $static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs = $static_owner_h_features H + $static_owner_d_features D · $static_action_bridges action bridges</td><td class="partial">$ownership_percent% of bounded 3,929 · $static_residual residual · features $route_feature_ids route / $page_feature_ids page / $route_page_overlap overlap · routes 3,218 = $static_owner_routes owner + $route_shared_current shared + $route_alias_current alias + $route_residual residual with $finance_review_gap tagged gaps · pages 711 = $static_owner_pages owner + $page_shared shared + $page_residual residual with $page_gap tagged gap · Finance page calls $finance_page_calls = $finance_page_owned prior-owned + $finance_page_authorized newly owned + $finance_page_unowned remaining unowned · Manual Journal page repaired while parent route remains a gap · Gate 4 incomplete · matrix unchanged</td>'
    text = replace_between(text, current_row_start, current_row_end, current_row, "dashboard current ownership row RUN127")
    old_gap = "RUN-122/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges while adding seven Finance route owners and seven bridges, preserving $finance_review_shared shared, $finance_review_alias alias, and $finance_review_gap gap outcomes, and adding zero page owners;"
    new_gap = "RUN-126/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges while adding four Finance page owners, preserving the Manual Journal parent-route gap, and adding zero route, bridge, queue, feature-union, or matrix change;"
    text = replace_once(text, old_gap, new_gap, "dashboard open gate RUN127")
    prior_old = "RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, RUN-094, RUN-100, RUN-104, RUN-108, RUN-112, RUN-116, and RUN-120 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-123."
    prior_new = "RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, RUN-094, RUN-100, RUN-104, RUN-108, RUN-112, RUN-116, RUN-120, and RUN-124 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-127."
    text = replace_once(text, prior_old, prior_new, "dashboard prior RUN124 paragraph")
    prior_link = '<li><a href="evidence/browser/current-audit-dashboard-verification-run-120-wave-17.json">Superseded RUN-120 verification GO</a></li>'
    text = replace_once(text, prior_link, prior_link + '<li><a href="evidence/browser/current-audit-dashboard-verification-run-124-wave-18.json">Superseded RUN-124 verification GO</a></li>', "dashboard prior RUN124 link")
    fresh_start = '<section class="panel"><h2>Fresh RUN-124 audit-dashboard verification</h2>'
    fresh_end = '\n    <section class="panel"><h2>RUN-071–123 evidence lineage</h2>'
    fresh_replacement = '<section class="panel"><h2>Fresh RUN-128 audit-dashboard verification</h2><p>The exact regenerated RUN-127 dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-128 receipt must record page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 652/295/357 ownership, four Finance page owners split as three Chart pages plus one Manual Journal page, 62/242/48 route/page/overlap feature sets, 83 bridges, route 3,218=295+12+5+2,906 with seven tagged gaps, page 711=357+9+345 with one tagged gap, queue 507=106+401 with 106=84+10+5+7 and 423 without ownership, 3,277 residual records, the preserved Manual Journal parent-route gap, one operating organisation across multiple Sites, Gate 4 open, mapping 0/340, and all zero-credit boundaries. It verifies the audit artifact only and grants no application-browser, responsive-application, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-128-wave-19.json">RUN-128 responsive audit-dashboard verification receipt</a></li></ul></section>'
    text = replace_between(text, fresh_start, fresh_end, fresh_replacement, "fresh RUN128 section")
    text = text.replace('<section class="panel"><h2>RUN-071–123 evidence lineage</h2><p>Every current raw, generated, reviewed, and integrated RUN-077–123 source/reporting artifact', '<section class="panel"><h2>RUN-071–127 evidence lineage</h2><p>Every current raw, generated, reviewed, and integrated RUN-077–127 source/reporting artifact')
    text = replace_once(text, "Generated deterministically from independently reviewed static evidence through RUN-122/R and reported in RUN-123.", "Generated deterministically from independently reviewed static evidence through RUN-126/R and reported in RUN-127.", "dashboard footer RUN127")
    text = replace_once(text, '.tmp-run123-dashboard', '.tmp-run127-dashboard', "dashboard temp suffix RUN127")

    marker = "dashboard = TEMPLATE.substitute("
    prefix, suffix = text.split(marker, 1)
    suffix = suffix.replace('reviewed_finance_chart_route_action_overlay["combined_counts"]', 'reviewed_finance_page_gap_overlay["combined_counts"]')
    suffix = suffix.replace("reviewed_finance_chart_route_action_overlay['combined_counts']", "reviewed_finance_page_gap_overlay['combined_counts']")
    suffix = suffix.replace('reviewed_finance_chart_route_action_overlay["queue_accounting"]', 'reviewed_finance_page_gap_overlay["queue_accounting"]')
    old_context = """    finance_page_calls=reviewed_finance_chart_route_action_overlay["page_context_boundary"]["literal_callsites"],
    finance_page_owned=reviewed_finance_chart_route_action_overlay["page_context_boundary"]["currently_owned_page_callsites"],
    finance_page_unowned=reviewed_finance_chart_route_action_overlay["page_context_boundary"]["unowned_page_callsites"],
    finance_page_authorized=reviewed_finance_chart_route_action_overlay["page_context_boundary"]["page_ownership_authorized"],
"""
    new_context = """    finance_page_calls=reviewed_finance_page_gap_overlay["page_context_boundary"]["run_121_literal_page_callsites"],
    finance_page_owned=reviewed_finance_page_gap_overlay["page_context_boundary"]["already_owned_before_run_125"],
    finance_page_unowned=reviewed_finance_page_gap_overlay["page_context_boundary"]["remaining_unowned_from_run_121_context"],
    finance_page_authorized=reviewed_finance_page_gap_overlay["page_context_boundary"]["new_page_owner_records"],
"""
    suffix = replace_once(suffix, old_context, new_context, "dashboard Finance page context substitutions")
    substitution_anchor = '    respite_page_review_owner=reviewed_respite_handover_page_overlay["reviewed_overlay"]["owner_pages"],\n'
    substitution_addition = substitution_anchor + (
        '    finance_page_wave_reviewed=reviewed_finance_page_gap_overlay["reviewed_overlay"]["reviewed_pages"],\n'
        '    finance_page_review_owner=reviewed_finance_page_gap_overlay["reviewed_overlay"]["owner_pages"],\n'
    )
    suffix = replace_once(suffix, substitution_anchor, substitution_addition, "dashboard RUN125 page substitutions")
    text = prefix + marker + suffix
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
        for relative in CURRENT_REPORT_INPUTS
    }
    receipt = {
        "schema_version": SCHEMA_VERSION,
        "run_id": RUN_ID,
        "status": "REVIEWED_FINANCE_PAGE_GAP_OVERLAY_REPORTED_GATE_4_INCOMPLETE",
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
            "overlay_sha256": PINNED_INPUTS["evidence/source/current-run-126-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json"],
            "independent_overlay_review_sha256": PINNED_INPUTS["evidence/source/raw-run-126r-independent-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json"],
            "superseded_dashboard_verification_sha256": PINNED_INPUTS["evidence/browser/current-audit-dashboard-verification-run-124-wave-18.json"],
        },
        "inputs": {**CURRENT_REPORT_INPUTS, **PINNED_INPUTS},
        "outputs": outputs,
        "preserved": PRESERVED,
        "counts": {
            **overlay["combined_counts"],
            **overlay["queue_accounting"],
            "reviewed_finance_pages": 4,
            "reviewed_owner_pages_added": 4,
            "chart_of_accounts_pages_added": 3,
            "manual_journal_pages_added": 1,
            "reviewed_non_owner_rows_preserved": 15,
            "route_owner_records_added": 0,
            "controller_action_bridges_added": 0,
            "direct_exact_queue_rows_added": 0,
            "provisional_finding_records": 12,
            "final_findings": 0,
            "matrix_rows_changed": 0,
            "matrix_cells_changed": 0,
        },
        "checks": {
            "run_125r_review_go": True,
            "run_125r_outcome_conservation": "4=4+0+0+0+0",
            "run_126r_overlay_review_go": True,
            "independent_review_discrepancies": 0,
            "page_owner_records_added": 4,
            "route_owner_records_added": 0,
            "controller_action_bridges_added": 0,
            "direct_exact_queue_rows_added": 0,
            "reviewed_non_owner_rows_preserved": 15,
            "journal_page_feature_repaired": True,
            "journal_parent_route_gap_preserved": True,
            "matrix_byte_identical": True,
            "reports_02_through_12_inventory_preserved": True,
            "provisional_finding_record_semantics_preserved": True,
            "application_source_paths_written": 0,
            "one_organisation_multi_site_architecture_preserved": True,
            "dashboard_requires_fresh_run_128_artifact_verification": True,
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
        assert prior["run_id"] == RUN_ID
        assert prior["schema_version"] == SCHEMA_VERSION
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
