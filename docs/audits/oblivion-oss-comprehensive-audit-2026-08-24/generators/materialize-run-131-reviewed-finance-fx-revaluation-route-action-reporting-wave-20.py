#!/usr/bin/env python3
"""Report the independently verified RUN-130 FX route/action overlay.

Only the five current reporting surfaces are updated. Reports 02-12,
inventory, the 340-row matrix, application source, tests, and the currently
verified dashboard remain byte-identical. The regenerated dashboard requires
a fresh RUN-132 artifact-only receipt.
"""

from __future__ import annotations

import copy
import hashlib
import json
import os
import subprocess
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
MATERIALIZER_RELATIVE = "generators/materialize-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.py"
OUTPUT_RELATIVE = "evidence/source/current-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.json"
SCHEMA_VERSION = "run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20-v1"
RUN_ID = "RUN-131-REVIEWED-FINANCE-FX-REVALUATION-ROUTE-ACTION-REPORTING-WAVE-20"

CHECKPOINT_COMMIT = "1009bf946fa8e4daff1cad1cecbf4b7d8c501ac1"
CHECKPOINT_TREE = "91251cb4b14769f0e9cf07eb5a1c40c779f7e65f"
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
CURRENT_DASHBOARD_SHA256 = "0c5af1501561fa9a55a5ab9849d5c58338a21351508f875c849cec6aae25da75"
KNOWN_ITERATION_MATERIALIZER_SHA256S = {
    "670e545bba736b44c3e7b1f9d328328db5661827edb3d209c4ef5f104ed280f9",
    "2e8d91ffcceb27c9d088f7eed0f6422626e52b5f34fa6cf23a6131c1300c8853",
    "9042acb56ab3c2a8a6da6c0606bd1eb30cd2d3e45baac3cb52344776c8768e04",
}

CURRENT_REPORT_INPUTS = {
    "00-executive-summary.md": "5330bc30b7be9897331b4d36781e39062ff6df11d5a385b8d7e4858dec6169bb",
    "01-repository-module-map.md": "8c17f4d5e50a88357ac0a2c5b76cf3c25c931726b87c6a6cd32b988bceeaa645",
    "13-unresolved-questions-and-evidence-gaps.md": "8f2dafd9591aa138a90cb14313b2783a6feedefb9313c011da677f7ed39b2193",
    "findings.json": "23224f06c7761d632604f56b584ebecb605c6481322a9f72b78643c643cc2b0e",
    "generators/build-current-audit-dashboard.py": "5512cf1240f0884a3292c31b455316cb26915e4199af912068bf5d8003dc3a6d",
}

PINNED_INPUTS = {
    "generators/materialize-run-127-reviewed-finance-page-gap-reporting-wave-19.py": "a4e1d40d0b3db61b7333c198da54f81423447d8a2b36811fa04cd99f29b9736b",
    "evidence/source/current-run-127-reviewed-finance-page-gap-reporting-wave-19.json": "9db62d439c45af768a7d1cd919251488a8c877fc20f59de27ec88e153588c040",
    "evidence/browser/current-audit-dashboard-verification-run-128-wave-19.json": "c6d92421fa9e51ae875067de414fb9c38e52708cd6293fae42dc82a5bb2bd9bc",
    "generators/build-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.py": "2e23ca7736f0e21460f130a6fafc89a68f228b6f8a52137a2209795d500b0982",
    "evidence/source/root-run-129-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.json": "6d1efad203c368986ada06746f4314382b2dee4d214b25799dc531c02608180c",
    "generators/materialize-independent-outcome-neutral-finance-fx-revaluation-route-action-review-wave-20.py": "c77ac164b6869bca82d929df623a19dd40f0c72fa593d7fb805c72c9ece8d60b",
    "evidence/source/raw-run-129r-independent-outcome-neutral-finance-fx-revaluation-route-action-review-wave-20.json": "9eb86243c72c7aa0c0f1cf6d250b7ad4184c2e0602c8217b7f3c0e70dcded67a",
    "generators/integrate-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.py": "3bdde28921e35b6a8ec45610af9a52cb55c0d37bdb2de179a2fa9eeecfe976e1",
    "evidence/source/current-run-130-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json": "f32b3d997a9e7dd932e041f5acf30dea02ee5b62fee3b0901cfbe5cc59f2ed0a",
    "generators/materialize-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-review-wave-20.py": "b5c879aa46805cdd699c0a39db8c6a1281af634838d772c8f164a8a48df326f3",
    "evidence/source/raw-run-130r-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json": "4f7e5d74ce3711ce5ff00ac2a499ddde125115b1537a0de2e17375792f3d8590",
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
        ["git", *args], cwd=REPO, check=True, capture_output=True, text=True, encoding="utf-8"
    ).stdout.strip()


def write_lf(relative: str, text: str) -> None:
    encoded = text.replace("\r\n", "\n").encode("utf-8")
    if path(relative).read_bytes() != encoded:
        path(relative).write_bytes(encoded)


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if new in text:
        return text
    assert text.count(old) == 1, (label, text.count(old))
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
    assert git("rev-parse", "HEAD:tests") == TESTS_TREE
    assert not git("status", "--porcelain", "--", "app", "routes", "resources/js", "tests")

    existing = read_json(OUTPUT_RELATIVE) if path(OUTPUT_RELATIVE).exists() else None
    for relative, expected in CURRENT_REPORT_INPUTS.items():
        allowed = {expected}
        if existing is not None:
            assert existing["run_id"] == RUN_ID
            assert existing["schema_version"] == SCHEMA_VERSION
            assert existing["pins"]["checkpoint_commit"] == CHECKPOINT_COMMIT
            assert existing["pins"]["materializer_sha256"] in (
                KNOWN_ITERATION_MATERIALIZER_SHA256S | {sha256_file(MATERIALIZER_RELATIVE)}
            )
            allowed.add(existing["outputs"][relative])
        assert sha256_file(relative) in allowed, relative
    for relative, expected in PINNED_INPUTS.items():
        assert sha256_file(relative) == expected, relative
    for relative, expected in PRESERVED.items():
        assert sha256_file(relative) == expected, relative
    assert sha256_file("audit-dashboard.html") == CURRENT_DASHBOARD_SHA256

    overlay = read_json(
        "evidence/source/current-run-130-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json"
    )
    review = read_json(
        "evidence/source/raw-run-130r-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json"
    )
    cohort = read_json(
        "evidence/source/root-run-129-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.json"
    )
    semantic_review = read_json(
        "evidence/source/raw-run-129r-independent-outcome-neutral-finance-fx-revaluation-route-action-review-wave-20.json"
    )
    counts = overlay["combined_counts"]
    queue = overlay["queue_accounting"]
    assert (counts["source_owner_records"], counts["route_owner_records"], counts["page_owner_records"]) == (654, 297, 357)
    assert (counts["static_controller_action_bridges"], counts["bounded_static_source_residual_records"]) == (85, 3275)
    assert counts["bounded_static_source_ownership_percent"] == "16.645457"
    assert (queue["reviewed_queue_surface_rows"], queue["pending_unreviewed_queue_surface_rows"]) == (108, 399)
    assert cohort["counts"]["candidate_route_actions"] == 2
    assert cohort["counts"]["ownership_credit_awarded"] == 0
    assert semantic_review["decision"]["verdict"] == "GO_2_EXPLICIT_OWNER_ROUTE_ACTION"
    assert semantic_review["decision"]["current_overlay_credit_awarded"] is False
    assert review["decision"]["verdict"] == "GO"
    assert review["decision"]["reporting_materialization_authorized"] is True
    assert review["decision"]["gate_4_complete"] is False
    assert review["verified_identity"] == overlay["identity"]
    assert len(review["verified_identity"]) == 40
    assert {key for key, value in review["credit_boundary"].items() if value} == {
        "INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"
    }
    return overlay, review


def patch_reports() -> None:
    summary_relative = "00-executive-summary.md"
    summary = path(summary_relative).read_text(encoding="utf-8")
    summary_block = """## RUN-113–131 reviewed route/action and page-ownership lineage

RUN-113/R–128 remain historical reviewed route/action, page-owner, reporting, and exact audit-dashboard checkpoints. RUN-125/R–128 establish, report, and verify the four Finance page owners while preserving the Manual Journal parent-route gap.

RUN-129 freezes exactly two still-pending Finance FX revaluation actions without pre-awarding ownership: `fx-revaluations.store` and `fx-revaluations.post`. Two fresh partition reviewers and an independent synthesis reviewer trace the exact RUN-090/RUN-077 route identities, controller methods, service semantics, source-packet expansions, and assurance risks. RUN-129R classifies both actions as explicit `OWNER_ROUTE_ACTION` for `CAP-FIN-FX-REVALUATION`; no page or sibling index/create ownership is inherited.

RUN-130 integrates exactly those two route owners and two controller-action bridges. RUN-130R independently verifies the committed bytes, all 40 identities, 16 source-packet expansions, 15 assurance findings, accounting, denominators, lineage, and zero-credit boundaries. The store action creates the draft revaluation and the post action posts its GL journal and marks the revaluation posted. This static ownership does not establish Site, permission, privacy, direct-object, rate/snapshot, ledger, lifecycle, concurrency, durability, runtime, or release correctness.

The current bounded checkpoint is **654 records = 297 routes + 357 pages across 256 canonical FEATURE-IDs (234 H + 22 D)**, with 85 controller-action bridges. Route and page owners span 62 and 242 FEATURE-IDs with 48 in their overlap. This is 16.645457% of the bounded 3,929-record source universe; 3,275 records remain. The route universe is **3,218 = 297 owners + 12 shared + 5 aliases + 2,904 residual**, with seven evidence gaps tagged inside that residual. The page universe is unchanged at **711 = 357 owners + 9 shared + 345 residual**, with one earlier evidence gap tagged inside its residual. Queue accounting is **507 = 108 reviewed + 399 pending**; reviewed rows are 86 owned, 10 shared, 5 aliases, 0 dead, and 7 evidence gaps, while 421 remain without ownership.

RUN-131 reports only that bounded two-action delta. The exact regenerated dashboard requires a fresh RUN-132 artifact receipt. Oblivion Findings remains one operating organisation across multiple Sites. Framework reachability, navigation, Site access, roles/permissions, canonical object ownership, direct-object concealment, privacy, rate/ledger/lifecycle/concurrency/durability correctness, runtime, database, build, application browser, tests, benchmarks, ease, Passes, findings, completion, and Gate 4 remain separate open or zero-credit gates. The 340-row matrix remains byte-identical and mapping remains 0/340.

"""
    summary = replace_between(
        summary,
        "## RUN-113–127 reviewed route/action and page-ownership lineage\n",
        "## Current raw source census\n",
        summary_block,
        "summary RUN131 block",
    )
    evidence_anchor = "- `generators/materialize-run-127-reviewed-finance-page-gap-reporting-wave-19.py` and `evidence/source/current-run-127-reviewed-finance-page-gap-reporting-wave-19.json`: deterministic RUN-127 reporting refresh preserving the matrix, reports 02–12/inventory, and every downstream zero-credit boundary.\n"
    evidence_addition = evidence_anchor + (
        "- `evidence/browser/current-audit-dashboard-verification-run-128-wave-19.json`: exact now-superseded RUN-127 dashboard artifact verification at four viewports; zero application credit.\n"
        "- `generators/build-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.py` and `evidence/source/root-run-129-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.json`: exact zero-credit two-action FX revaluation review cohort.\n"
        "- `generators/materialize-independent-outcome-neutral-finance-fx-revaluation-route-action-review-wave-20.py` and `evidence/source/raw-run-129r-independent-outcome-neutral-finance-fx-revaluation-route-action-review-wave-20.json`: two-part fresh semantic review plus synthesis, with 15 assurance findings and no correctness credit.\n"
        "- `generators/integrate-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.py` and `evidence/source/current-run-130-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json`: exact two-route and two-bridge static-only overlay.\n"
        "- `generators/materialize-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-review-wave-20.py` and `evidence/source/raw-run-130r-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json`: three-part final-byte, identity, accounting, provenance, and boundary GO receipt.\n"
        "- `generators/materialize-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.py` and `evidence/source/current-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.json`: deterministic RUN-131 reporting refresh preserving the matrix, reports 02–12/inventory, and every downstream zero-credit boundary.\n"
    )
    summary = replace_once(summary, evidence_anchor, evidence_addition, "summary RUN131 evidence links")
    write_lf(summary_relative, summary)

    module_relative = "01-repository-module-map.md"
    module_map = path(module_relative).read_text(encoding="utf-8")
    module_block = """## RUN-113–131 reviewed route/action and page-ownership lineage

RUN-113/R–128 remain historical reviewed route/action, page-owner, reporting, and exact-dashboard checkpoints. RUN-125/R–128 preserve the four Finance page owners and the Manual Journal parent-route evidence gap.

RUN-129/R separately review the two pending FX revaluation routes as two explicit route/action owners for `CAP-FIN-FX-REVALUATION`: `store` creates a draft revaluation and `post` creates/posts the adjustment journal and marks the revaluation posted. RUN-130/R integrate and independently verify exactly two route records and two controller-action bridges with zero page, sibling-inheritance, feature-union, or matrix change.

The cumulative bounded ledger is 654 source owners (297 route + 357 page) across 256 FEATURE-IDs (234 H + 22 D). Route/page feature sets are 62/242 with overlap 48, and the action-bridge count is 85. Route accounting is 3,218 = 297 owners + 12 shared + 5 aliases + 2,904 residual, with seven evidence gaps tagged within residual. Page accounting remains 711 = 357 owners + 9 shared + 345 residual, with one earlier tagged evidence gap. RUN-090 queue accounting is 507 total, 108 reviewed, 86 owned, 10 shared, 5 aliases, 7 evidence gaps, 399 pending, and 421 without ownership.

These relations establish bounded static ownership only. The preserved assurance findings leave unproved Site/permission/privacy/direct-object/rate/ledger/lifecycle/concurrency/durability correctness, framework reachability, runtime, build, browser, tests, benchmarks, findings, Passes, and completion.

"""
    module_map = replace_between(
        module_map,
        "## RUN-113–127 reviewed route/action and page-ownership lineage\n",
        "## Candidate register\n",
        module_block,
        "module RUN131 block",
    )
    write_lf(module_relative, module_map)

    gaps_relative = "13-unresolved-questions-and-evidence-gaps.md"
    gaps = path(gaps_relative).read_text(encoding="utf-8")
    gaps = replace_line_containing(gaps, "| Required reporting paths |", "| Required reporting paths | 18 / 18 prompt-required files or directories present. RUN-128 independently verified the exact now-superseded RUN-127 dashboard at four viewports; the regenerated RUN-131 dashboard requires a separate fresh RUN-132 artifact receipt. | Presence and prior audit-artifact verification make the reporting contract inspectable but grant no application execution, final-finding, Pass, or completion credit. Gate 26 remains open. | Complete the semantic, execution, benchmark, Pass 8, final reconciliation, and no-live-agent gates; repeat audit-dashboard verification after every later HTML change. |")
    gaps = replace_line_containing(gaps, "| Runtime routes |", "| Runtime routes | RUN-130/R preserve 297 bounded route-owner records and 85 static controller-action bridges; 2,904 residual route rows, 12 semantic-shared route rows, and 5 reviewed aliases remain distinguished within the bounded 3,218-row static route-like universe, with 7 evidence gaps tagged inside residual. | Wave 20 adds exactly two reviewed FX route owners and two bridges. Static owner/action linkage is not a framework-expanded route table, reachability proof, Site/permission/privacy proof, rate/ledger/lifecycle/concurrency/durability proof, or authorization proof. | Under a fresh bounded runtime grant, use an exact disposable database, execute a separately pinned framework/provider route lane, and reconcile it to all 38 source route files and 3,218 route-like rows. |")
    gaps = replace_line_containing(gaps, "| Inertia pages |", "| Inertia pages | RUN-084/R enumerate 1,058 physical page-tree files. RUN-130/R preserve 357 bounded page owners, nine semantic-shared roots, and 345 residual roots including one earlier tagged evidence gap. | Wave 20 adds zero page owner and inherits no page or sibling-route ownership. Full-tree structural GO and bounded ownership are not a complete canonical crosswalk, runtime reachability, build resolution, rendered browser behaviour, or final feature mapping. | Reconcile all 711 literal roots and support relations to safely expanded framework routes and frozen FEATURE-IDs, prove build resolution, and observe every safely reachable current-build page under approved roles/Sites. |")
    gaps = replace_line_containing(gaps, "| Canonical features |", "| Canonical features | Static canonical identity remains 340 targets: 300 H, 40 D, zero M; RUN-130/R establish 654 bounded source-owner records (297 routes + 357 pages) across 256 FEATURE-IDs (234 H + 22 D) plus 85 controller-action bridges while the matrix remains byte-identical at `dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390`. | This is 16.645457% of the bounded 3,929-record source universe. Gate 4 remains incomplete: 3,275 non-owner records, the framework-expanded denominator, shared, alias, and gap relations, reachability, and the full crosswalk remain open. The 15 FX assurance findings grant no final-finding credit; matrix target mapping stays 0/340. | Finish canonical denominator and residual ownership adjudication without inheriting FEATURE-IDs from prefixes, imports, containment, names, siblings, or presence, and without awarding runtime, browser, test, benchmark, ease, release, or completion credit. |")
    gaps = replace_line_containing(gaps, "| Agent universe and writer rule |", "| Agent universe and writer rule | RUN-001 through RUN-131 represented at the current reporting checkpoint; finalization gate false. | RUN-129/R review two FX actions as two explicit owners; RUN-130/R independently integrate and verify only two route owners and two bridges while preserving 15 assurance findings and every correctness boundary; RUN-131 reports only that bounded class. Runtime, browser, tests, benchmarks, Pass 8, finalization, and completion remain open. | Complete residual ownership and every semantic/execution gate, then dispatch fresh Pass 8/final cross-reviewers, verify the final dashboard, and prove no agent remains live at finalization. |")
    gaps = gaps.replace("## RUN-077–127 route/page, page-tree, backend, ownership and reporting lineage", "## RUN-077–131 route/page, page-tree, backend, ownership and reporting lineage")
    old_lineage = "RUN-077 freezes the exhaustive committed-source route/name/page universe through RUN-084B's page-tree and backend structural ledgers. RUN-086/R establish the initial 530 bounded source owners; RUN-090 freezes the zero-credit direct-exact queue; RUN-091/R–120 successively review, integrate, report, and verify bounded route/action and page ownership, reaching 641 owners while preserving explicit shared, alias, and gap outcomes. RUN-121/R review 22 Finance name-only route actions as 7 owners, 7 shared, 1 alias, 0 dead, and 7 gaps. RUN-122/R integrate and independently verify only 7 route owners and 7 bridges, preserve all 15 non-owner outcomes, and reach 648 owners; RUN-123 reports that delta and RUN-124 verifies its now-superseded dashboard. RUN-125/R review the four remaining Finance page callsites as four explicit owners. RUN-126/R integrate and independently verify only those four page owners, preserve the Manual Journal parent-route gap, leave route, bridge, queue, feature-union, and matrix counts unchanged, and reach 652 owners; RUN-127 reports only that bounded delta. Gate 4 and the full route/page/backend crosswalk remain incomplete; Oblivion Findings remains one operating organisation across multiple Sites, and framework reachability, Site/permission/privacy/direct-object/ledger/lifecycle/concurrency correctness, runtime, build, signed-in application browser, executed tests, benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit."
    new_lineage = "RUN-077 freezes the exhaustive committed-source route/name/page universe through RUN-084B's page-tree and backend structural ledgers. RUN-086/R establish the initial 530 bounded source owners; RUN-090 freezes the zero-credit direct-exact queue; RUN-091/R–124 successively review, integrate, report, and verify bounded route/action and page ownership, reaching 648 owners while preserving explicit shared, alias, and gap outcomes. RUN-125/R review the four remaining Finance page callsites as four explicit owners. RUN-126/R integrate and independently verify only those four page owners, preserve the Manual Journal parent-route gap, and reach 652 owners; RUN-127 reports that delta and RUN-128 verifies its now-superseded dashboard. RUN-129/R review the two pending FX revaluation actions as two explicit route/action owners. RUN-130/R integrate and independently verify exactly two route owners and two controller-action bridges, preserve 16 source-packet expansions and 15 assurance findings without correctness credit, and reach 654 owners; RUN-131 reports only that bounded delta. Gate 4 and the full route/page/backend crosswalk remain incomplete; Oblivion Findings remains one operating organisation across multiple Sites, and framework reachability, Site/permission/privacy/direct-object/rate/ledger/lifecycle/concurrency/durability correctness, runtime, build, signed-in application browser, executed tests, benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit."
    gaps = replace_once(gaps, old_lineage, new_lineage, "gaps RUN131 lineage")
    write_lf(gaps_relative, gaps)


def patch_findings(overlay: dict[str, Any], review: dict[str, Any]) -> None:
    relative = "findings.json"
    findings = read_json(relative)
    counts = overlay["combined_counts"]
    queue = overlay["queue_accounting"]
    findings["pins"].update(
        {
            "run_127_reporting_sha256": PINNED_INPUTS["evidence/source/current-run-127-reviewed-finance-page-gap-reporting-wave-19.json"],
            "run_128_dashboard_verification_sha256": PINNED_INPUTS["evidence/browser/current-audit-dashboard-verification-run-128-wave-19.json"],
            "run_129_finance_fx_revaluation_cohort_generator_sha256": PINNED_INPUTS["generators/build-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.py"],
            "run_129_finance_fx_revaluation_cohort_sha256": PINNED_INPUTS["evidence/source/root-run-129-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.json"],
            "run_129r_finance_fx_revaluation_review_materializer_sha256": PINNED_INPUTS["generators/materialize-independent-outcome-neutral-finance-fx-revaluation-route-action-review-wave-20.py"],
            "run_129r_finance_fx_revaluation_review_sha256": PINNED_INPUTS["evidence/source/raw-run-129r-independent-outcome-neutral-finance-fx-revaluation-route-action-review-wave-20.json"],
            "run_130_finance_fx_revaluation_overlay_generator_sha256": PINNED_INPUTS["generators/integrate-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.py"],
            "run_130_finance_fx_revaluation_overlay_sha256": PINNED_INPUTS["evidence/source/current-run-130-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json"],
            "run_130r_finance_fx_revaluation_overlay_review_materializer_sha256": PINNED_INPUTS["generators/materialize-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-review-wave-20.py"],
            "run_130r_finance_fx_revaluation_overlay_review_sha256": PINNED_INPUTS["evidence/source/raw-run-130r-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json"],
            "run_131_reporting_materializer_sha256": sha256_file(MATERIALIZER_RELATIVE),
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

    if "historical_run_126_outcome_neutral_finance_page_gap_ownership" not in findings:
        findings["historical_run_126_outcome_neutral_finance_page_gap_ownership"] = copy.deepcopy(
            findings["current_static_source_feature_ownership"]
        )
    if "historical_run_126_outcome_neutral_finance_page_gap_ownership_review" not in findings:
        findings["historical_run_126_outcome_neutral_finance_page_gap_ownership_review"] = copy.deepcopy(
            findings["current_outcome_neutral_finance_page_gap_ownership_review"]
        )
    findings.pop("current_outcome_neutral_finance_page_gap_ownership_review", None)

    feature_distribution: dict[str, int] = {}
    for row in overlay["overlay_source_records"]:
        feature_distribution[row["feature_id"]] = feature_distribution.get(row["feature_id"], 0) + 1
    findings["current_static_source_feature_ownership"] = {
        "run_id": overlay["run_id"],
        "review_run_id": review["run_id"],
        "status": "GO_REVIEWED_BOUNDED_OUTCOME_NEUTRAL_FINANCE_FX_REVALUATION_ROUTE_ACTION_OWNERSHIP_ONLY",
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
        "ownership_basis": "FRESH_COMPLETE_ACTION_REVIEW_NOT_SIBLING_INDEX_CREATE_OR_FRONTEND_CALLER_INHERITANCE",
        "identity": overlay["identity"],
        "outcome_conservation": overlay["outcome_conservation"],
        "projection_reconciliation": overlay["projection_reconciliation"],
        "source_packet_expansion_preservation": overlay["source_packet_expansion_preservation"],
        "assurance_findings_preservation": overlay["assurance_findings_preservation"],
        "independent_review_discrepancies": 0,
        "gate_4": {"status": "PARTIAL_BOUNDED_STATIC_SOURCE_OWNERSHIP_INCOMPLETE", "complete": False},
        "credit_boundary": overlay["credit_boundary"],
    }
    findings["current_outcome_neutral_finance_fx_revaluation_route_action_ownership_review"] = {
        "run_id": review["run_id"],
        "status": review["status"],
        "reviewers": len(review["reviewers"]),
        "semantic_logical_checks": review["decision"]["logical_checks_reported_by_semantic_reviewer"],
        "route_owner_records_verified": review["decision"]["route_owner_records_verified"],
        "controller_action_bridges_verified": review["decision"]["controller_action_bridges_verified"],
        "page_owner_records_verified": review["decision"]["page_owner_records_verified"],
        "source_packet_expansion_records_verified": review["decision"]["source_packet_expansion_records_verified"],
        "assurance_findings_verified": review["decision"]["assurance_findings_verified"],
        "published_identity_fields_verified": review["decision"]["published_identity_fields_verified"],
        "mechanical_discrepancies": review["decision"]["mechanical_discrepancies"],
        "semantic_or_preservation_discrepancies": review["decision"]["semantic_or_preservation_discrepancies"],
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

    run128 = read_json("evidence/browser/current-audit-dashboard-verification-run-128-wave-19.json")
    verification = run128["verification"]
    findings["current_audit_artifact_verification_history"]["run_128"] = {
        "status": run128["status"],
        "dashboard_sha256": run128["pins"]["dashboard_html_sha256"],
        "receipt_sha256": PINNED_INPUTS["evidence/browser/current-audit-dashboard-verification-run-128-wave-19.json"],
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
    run131_materializer_sha256 = sha256_file(MATERIALIZER_RELATIVE)
    read_anchor = 'reviewed_finance_page_gap_overlay_review = read_json("evidence/source/raw-run-126r-independent-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json")\n'
    read_addition = read_anchor + (
        'finance_fx_revaluation_cohort = read_json("evidence/source/root-run-129-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.json")\n'
        'finance_fx_revaluation_review = read_json("evidence/source/raw-run-129r-independent-outcome-neutral-finance-fx-revaluation-route-action-review-wave-20.json")\n'
        'reviewed_finance_fx_revaluation_overlay = read_json("evidence/source/current-run-130-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json")\n'
        'reviewed_finance_fx_revaluation_overlay_review = read_json("evidence/source/raw-run-130r-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json")\n'
    )
    text = replace_once(text, read_anchor, read_addition, "dashboard RUN129-130 reads")

    semantic_anchor = "assert 711 == 357 + 9 + 345\n"
    semantic_block = (
        f'''assert sha256_file("generators/materialize-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.py") == "{run131_materializer_sha256}"\n'''
        + """
assert sha256_file("evidence/source/current-run-127-reviewed-finance-page-gap-reporting-wave-19.json") == "9db62d439c45af768a7d1cd919251488a8c877fc20f59de27ec88e153588c040"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-128-wave-19.json") == "c6d92421fa9e51ae875067de414fb9c38e52708cd6293fae42dc82a5bb2bd9bc"
assert sha256_file("generators/build-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.py") == "2e23ca7736f0e21460f130a6fafc89a68f228b6f8a52137a2209795d500b0982"
assert sha256_file("evidence/source/root-run-129-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.json") == "6d1efad203c368986ada06746f4314382b2dee4d214b25799dc531c02608180c"
assert sha256_file("generators/materialize-independent-outcome-neutral-finance-fx-revaluation-route-action-review-wave-20.py") == "c77ac164b6869bca82d929df623a19dd40f0c72fa593d7fb805c72c9ece8d60b"
assert sha256_file("evidence/source/raw-run-129r-independent-outcome-neutral-finance-fx-revaluation-route-action-review-wave-20.json") == "9eb86243c72c7aa0c0f1cf6d250b7ad4184c2e0602c8217b7f3c0e70dcded67a"
assert sha256_file("generators/integrate-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.py") == "3bdde28921e35b6a8ec45610af9a52cb55c0d37bdb2de179a2fa9eeecfe976e1"
assert sha256_file("evidence/source/current-run-130-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json") == "f32b3d997a9e7dd932e041f5acf30dea02ee5b62fee3b0901cfbe5cc59f2ed0a"
assert sha256_file("generators/materialize-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-review-wave-20.py") == "b5c879aa46805cdd699c0a39db8c6a1281af634838d772c8f164a8a48df326f3"
assert sha256_file("evidence/source/raw-run-130r-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json") == "4f7e5d74ce3711ce5ff00ac2a499ddde125115b1537a0de2e17375792f3d8590"
assert finance_fx_revaluation_cohort["counts"]["candidate_route_actions"] == 2
assert finance_fx_revaluation_cohort["counts"]["ownership_credit_awarded"] == 0
assert finance_fx_revaluation_review["decision"]["verdict"] == "GO_2_EXPLICIT_OWNER_ROUTE_ACTION"
assert finance_fx_revaluation_review["decision"]["current_overlay_credit_awarded"] is False
assert reviewed_finance_fx_revaluation_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_finance_fx_revaluation_overlay_review["decision"]["reporting_materialization_authorized"] is True
assert reviewed_finance_fx_revaluation_overlay_review["decision"]["gate_4_complete"] is False
assert reviewed_finance_fx_revaluation_overlay_review["verified_identity"] == reviewed_finance_fx_revaluation_overlay["identity"]
assert len(reviewed_finance_fx_revaluation_overlay_review["verified_identity"]) == 40
assert len(reviewed_finance_fx_revaluation_overlay["overlay_source_records"]) == 2
assert len(reviewed_finance_fx_revaluation_overlay["new_static_controller_action_bridges"]) == 2
assert reviewed_finance_fx_revaluation_overlay["reviewed_non_owner_outcomes"] == []
assert reviewed_finance_fx_revaluation_overlay["page_context_boundary"] == {
    "literal_inertia_page_callsites": 0,
    "existing_caller_pages": 2,
    "new_page_owner_records": 0,
    "page_ownership_inherited": False,
    "rule": "Index and Create remain already-owned caller context and receive no new page credit.",
}
fx_counts = reviewed_finance_fx_revaluation_overlay["combined_counts"]
fx_queue = reviewed_finance_fx_revaluation_overlay["queue_accounting"]
assert (fx_counts["source_owner_records"], fx_counts["route_owner_records"], fx_counts["page_owner_records"]) == (654, 297, 357)
assert (fx_counts["distinct_feature_ids"], fx_counts["distinct_H_feature_ids"], fx_counts["distinct_D_feature_ids"]) == (256, 234, 22)
assert (fx_counts["route_distinct_feature_ids"], fx_counts["page_distinct_feature_ids"], fx_counts["route_page_feature_overlap"]) == (62, 242, 48)
assert (fx_counts["static_controller_action_bridges"], fx_counts["bounded_static_source_residual_records"]) == (85, 3275)
assert fx_counts["bounded_static_source_ownership_percent"] == "16.645457"
assert (fx_counts["residual_explicit_unmapped_routes"], fx_counts["semantic_shared_routes"], fx_counts["reviewed_alias_routes"], fx_counts["evidence_gap_routes_tagged_within_residual"]) == (2904, 12, 5, 7)
assert (fx_counts["residual_unadjudicated_page_roots"], fx_counts["semantic_shared_page_roots"], fx_counts["evidence_gap_page_roots_tagged_within_residual"]) == (345, 9, 1)
assert (fx_queue["direct_exact_queue_records"], fx_queue["reviewed_queue_surface_rows"], fx_queue["owner_queue_surface_rows"], fx_queue["shared_queue_surface_rows"], fx_queue["alias_queue_surface_rows"], fx_queue["dead_queue_surface_rows"], fx_queue["evidence_gap_queue_surface_rows"], fx_queue["pending_unreviewed_queue_surface_rows"], fx_queue["queue_surfaces_without_ownership"]) == (507, 108, 86, 10, 5, 0, 7, 399, 421)
assert (fx_queue["new_reviewed_route_surface_rows"], fx_queue["new_owner_route_surface_rows"]) == (2, 2)
assert reviewed_finance_fx_revaluation_overlay["source_packet_expansion_preservation"]["total_disclosed_expansion_entries"] == 16
assert reviewed_finance_fx_revaluation_overlay["assurance_findings_preservation"]["total_findings"] == 15
assert reviewed_finance_fx_revaluation_overlay["projection_reconciliation"]["run129r_projection_credit_awarded"] is False
assert reviewed_finance_fx_revaluation_overlay["projection_reconciliation"]["run130_current_static_overlay_credit_applied"] is True
assert {key for key, value in reviewed_finance_fx_revaluation_overlay["credit_boundary"].items() if value} == {"STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_2_RECORDS", "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_2_ACTIONS"}
assert {key for key, value in reviewed_finance_fx_revaluation_overlay_review["credit_boundary"].items() if value} == {"INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"}
assert 3929 == 654 + 3275
assert 654 == 297 + 357
assert 3218 == 297 + 12 + 5 + 2904
assert 711 == 357 + 9 + 345
"""
    )
    current_semantic_start = 'assert sha256_file("generators/materialize-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.py") == "'
    prior_semantic_start = 'assert sha256_file("evidence/source/root-run-129-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.json") == "'
    semantic_end = "\n\ncandidates ="
    if current_semantic_start in text:
        text = replace_between(text, current_semantic_start, semantic_end, semantic_block.rstrip(), "dashboard RUN129-131 assertion refresh")
    elif prior_semantic_start in text:
        text = replace_between(text, prior_semantic_start, semantic_end, semantic_block.rstrip(), "dashboard RUN129-131 assertion hardening")
    else:
        text = replace_once(text, semantic_anchor, semantic_anchor + semantic_block, "dashboard RUN129-131 assertions")

    evidence_anchor = '    ("RUN-127 Finance page-gap reporting/hash receipt", "evidence/source/current-run-127-reviewed-finance-page-gap-reporting-wave-19.json"),\n'
    evidence_addition = evidence_anchor + (
        '    ("RUN-128 verified superseded dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-128-wave-19.json"),\n'
        '    ("RUN-129 FX revaluation route/action cohort generator", "generators/build-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.py"),\n'
        '    ("RUN-129 two-action FX cohort", "evidence/source/root-run-129-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.json"),\n'
        '    ("RUN-129R FX semantic-review materializer", "generators/materialize-independent-outcome-neutral-finance-fx-revaluation-route-action-review-wave-20.py"),\n'
        '    ("RUN-129R two-owner FX action review", "evidence/source/raw-run-129r-independent-outcome-neutral-finance-fx-revaluation-route-action-review-wave-20.json"),\n'
        '    ("RUN-130 FX route/action overlay generator", "generators/integrate-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.py"),\n'
        '    ("RUN-130 two-route two-bridge FX overlay", "evidence/source/current-run-130-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json"),\n'
        '    ("RUN-130R independent FX overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-review-wave-20.py"),\n'
        '    ("RUN-130R final-byte identity accounting and boundary review", "evidence/source/raw-run-130r-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json"),\n'
        '    ("RUN-131 FX route/action reporting materializer", "generators/materialize-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.py"),\n'
        '    ("RUN-131 FX route/action reporting/hash receipt", "evidence/source/current-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.json"),\n'
    )
    text = replace_once(text, evidence_anchor, evidence_addition, "dashboard RUN128-131 evidence")

    text = replace_once(text, 'href="#checkpoint">RUN-127</a>', 'href="#checkpoint">RUN-131</a>', "dashboard nav RUN131")
    text = replace_once(text, "RUN-071–127 current reporting checkpoint:", "RUN-071–131 current reporting checkpoint:", "dashboard notice RUN131")
    text = replace_once(text, "RUN-071–127 completion-gate checkpoint", "RUN-071–131 completion-gate checkpoint", "dashboard heading RUN131")
    old_overview = "RUN-101/R–124 remain historical route/action, page-owner, reporting, and exact dashboard checkpoints. RUN-125/R review $finance_page_wave_reviewed Finance page gaps as $finance_page_review_owner explicit owners; RUN-126/R establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges. Exactly four page owners are added; route, bridge, queue, feature-union, and matrix counts remain unchanged from RUN-122, and the Manual Journal parent route remains a gap."
    new_overview = "RUN-101/R–128 remain historical route/action, page-owner, reporting, and exact dashboard checkpoints. RUN-129/R review $finance_fx_wave_reviewed FX revaluation route actions as $finance_fx_review_owner explicit owners; RUN-130/R establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges. Exactly two route owners and two bridges are added; page, feature-union, and matrix counts are unchanged, and all 15 assurance findings retain zero correctness credit."
    text = replace_once(text, old_overview, new_overview, "dashboard overview RUN131")
    old_checkpoint = "RUN-113/R–124 preserve historical route/action and page-owner checkpoints with exact dashboard receipts. RUN-125/R–126/R add $finance_page_review_owner Finance page owners, preserve the Manual Journal parent-route gap, and add zero route, bridge, queue, feature-union, or matrix change; $queue_reviewed queue rows are reviewed, $queue_pending remain pending, and $queue_without_owner remain without ownership. RUN-127 reports only that bounded delta."
    new_checkpoint = "RUN-113/R–128 preserve historical route/action and page-owner checkpoints with exact dashboard receipts. RUN-129/R–130/R add $finance_fx_review_owner FX route owners and two bridges, inherit no page or sibling ownership, and add zero feature-union or matrix change; $queue_reviewed queue rows are reviewed, $queue_pending remain pending, and $queue_without_owner remain without ownership. RUN-131 reports only that bounded delta."
    text = replace_once(text, old_checkpoint, new_checkpoint, "dashboard checkpoint RUN131")
    old_chronology = "RUN-113/R–124 preserve historical reviewed route/action and page-owner checkpoints with exact dashboard receipts; RUN-125/R–126/R independently review, integrate, and verify four Finance page owners while preserving the Manual Journal parent-route gap and zero route, bridge, queue, feature-union, or matrix change, and RUN-127 refreshes current reporting."
    new_chronology = "RUN-113/R–128 preserve historical reviewed route/action and page-owner checkpoints with exact dashboard receipts; RUN-129/R–130/R independently review, integrate, and verify two FX revaluation route owners plus two bridges while preserving 15 assurance findings, zero page/sibling inheritance, and every correctness boundary, and RUN-131 refreshes current reporting."
    text = replace_once(text, old_chronology, new_chronology, "dashboard chronology RUN131")

    progress_start = '<tr><td>RUN-121/R → 124 historical Finance route/action overlay'
    progress_end = "</tbody>"
    progress_replacement = '<tr><td>RUN-121/R → 124 historical Finance route/action overlay</td><td><strong>$finance_wave_reviewed reviewed · 7 owner + 7 shared + 1 alias + 7 gap · 7 route rows · 7 bridges</strong></td><td class="partial">648 cumulative owners · exact superseded dashboard verified</td></tr><tr><td>RUN-125/R → 128 historical Finance page-gap overlay</td><td><strong>$finance_page_wave_reviewed reviewed = $finance_page_review_owner owner pages · 4 page rows · 0 route/bridge/queue rows</strong></td><td class="partial">652 cumulative owners · exact superseded dashboard verified</td></tr><tr><td>RUN-129/R → 130/R current FX revaluation route/action overlay</td><td><strong>$finance_fx_wave_reviewed reviewed = $finance_fx_review_owner owner actions · 2 route rows · 2 bridges · 0 page rows</strong></td><td class="partial">$static_owner_records cumulative owners · $static_owner_routes routes + $static_owner_pages pages · Gate 4 incomplete</td></tr><tr><td>RUN-131 reporting refresh</td><td><strong>FX revaluation route/action overlay reported</strong></td><td class="partial">audit-only materialization · matrix byte-identical · fresh RUN-132 verification required</td></tr>'
    text = replace_between(text, progress_start, progress_end, progress_replacement, "dashboard progress RUN131")
    text = replace_once(text, "RUN-001 through RUN-127 are represented by audit artifacts", "RUN-001 through RUN-131 are represented by audit artifacts", "dashboard agent universe RUN131")

    bullet_start = '<li>RUN-121/R–124: historical Finance route/action review'
    bullet_end = "</ul>"
    bullet_replacement = '<li>RUN-121/R–128: historical Finance route/action and page-owner review, integration, reporting, and exact superseded dashboard receipts</li><li>RUN-129/R: $finance_fx_wave_reviewed FX revaluation actions · $finance_fx_review_owner explicit route/action owners · 15 assurance findings retained without correctness credit</li><li>RUN-130/R: two route rows and two bridges integrated and independently verified · zero page/sibling inheritance · $static_owner_records cumulative owner records</li><li>RUN-131: deterministic FX route/action reporting refresh · matrix and every Site/permission/privacy/direct-object/rate/ledger/lifecycle/concurrency/durability/execution/benchmark/Pass/finding/completion boundary unchanged</li>'
    text = replace_between(text, bullet_start, bullet_end, bullet_replacement, "dashboard bullets RUN131")
    old_timeline = "RUN-113/R–124 preserve historical reviewed route/action and page-owner checkpoints with exact dashboard receipts; RUN-125/R–126/R add four independently reviewed Finance page owners while preserving the Manual Journal parent-route gap and zero route, bridge, queue, feature-union, or matrix change, and RUN-127 refreshes reporting."
    new_timeline = "RUN-113/R–128 preserve historical reviewed route/action and page-owner checkpoints with exact dashboard receipts; RUN-129/R–130/R add two independently reviewed FX revaluation route owners and two bridges while preserving 15 assurance findings, zero page/sibling inheritance, and every correctness boundary, and RUN-131 refreshes reporting."
    text = replace_once(text, old_timeline, new_timeline, "dashboard timeline RUN131")

    current_start = '<tr><td>RUN-126/R current Finance route/action and page ownership'
    current_existing_start = '<tr><td>RUN-130/R current Finance route/action and page ownership'
    current_end = "</tr>"
    current_row = '<tr><td>RUN-130/R current Finance route/action and page ownership</td><td>$static_owner_records = $static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs = $static_owner_h_features H + $static_owner_d_features D · $static_action_bridges action bridges</td><td class="partial">$ownership_percent% of bounded 3,929 · $static_residual residual · features $route_feature_ids route / $page_feature_ids page / $route_page_overlap overlap · routes 3,218 = $static_owner_routes owner + $route_shared_current shared + $route_alias_current alias + $route_residual residual with $finance_fx_route_gap tagged gaps · pages 711 = $static_owner_pages owner + $page_shared shared + $page_residual residual with $page_gap tagged gap · FX wave $finance_fx_wave_reviewed = $finance_fx_review_owner owners · 2 route rows + 2 bridges · page context $finance_fx_page_calls literal Inertia callsites / $finance_fx_existing_caller_pages already-owned Index/Create callers / $finance_fx_page_owners_added new owners / inherited=$finance_fx_page_inherited · 15 assurance findings · zero correctness credit · Gate 4 incomplete · matrix unchanged</td>'
    if current_existing_start in text:
        current_start = current_existing_start
    text = replace_between(text, current_start, current_end, current_row, "dashboard current row RUN131")
    old_gap = "RUN-126/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges while adding four Finance page owners, preserving the Manual Journal parent-route gap, and adding zero route, bridge, queue, feature-union, or matrix change;"
    new_gap = "RUN-130/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges while adding two FX route owners and two bridges, preserving 15 assurance findings, inheriting no page or sibling ownership, and adding zero feature-union or matrix change;"
    text = replace_once(text, old_gap, new_gap, "dashboard open gate RUN131")

    prior_old = "RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, RUN-094, RUN-100, RUN-104, RUN-108, RUN-112, RUN-116, RUN-120, and RUN-124 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-127."
    prior_new = "RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, RUN-094, RUN-100, RUN-104, RUN-108, RUN-112, RUN-116, RUN-120, RUN-124, and RUN-128 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-131."
    text = replace_once(text, prior_old, prior_new, "dashboard prior RUN128")
    prior_link = '<li><a href="evidence/browser/current-audit-dashboard-verification-run-124-wave-18.json">Superseded RUN-124 verification GO</a></li>'
    text = replace_once(text, prior_link, prior_link + '<li><a href="evidence/browser/current-audit-dashboard-verification-run-128-wave-19.json">Superseded RUN-128 verification GO</a></li>', "dashboard prior RUN128 link")

    fresh_start = '<section class="panel"><h2>Fresh RUN-128 audit-dashboard verification</h2>'
    fresh_end = '\n    <section class="panel"><h2>RUN-071–127 evidence lineage</h2>'
    fresh_replacement = '<section class="panel"><h2>Fresh RUN-132 audit-dashboard verification</h2><p>The exact regenerated RUN-131 dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-132 receipt must record page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 654/297/357 ownership, two FX route/action owners and two bridges, 62/242/48 route/page/overlap feature sets, 85 cumulative bridges, route 3,218=297+12+5+2,904 with seven tagged gaps, page 711=357+9+345 with one tagged gap, queue 507=108+399 with 108=86+10+5+7 and 421 without ownership, 3,275 residual records, 15 assurance findings with zero final-finding credit, one operating organisation across multiple Sites, Gate 4 open, mapping 0/340, and all zero-credit boundaries. It verifies the audit artifact only and grants no application-browser, responsive-application, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-132-wave-20.json">RUN-132 responsive audit-dashboard verification receipt</a></li></ul></section>'
    text = replace_between(text, fresh_start, fresh_end, fresh_replacement, "fresh RUN132 section")
    text = text.replace('<section class="panel"><h2>RUN-071–127 evidence lineage</h2><p>Every current raw, generated, reviewed, and integrated RUN-077–127 source/reporting artifact', '<section class="panel"><h2>RUN-071–131 evidence lineage</h2><p>Every current raw, generated, reviewed, and integrated RUN-077–131 source/reporting artifact')
    text = replace_once(text, "Generated deterministically from independently reviewed static evidence through RUN-126/R and reported in RUN-127.", "Generated deterministically from independently reviewed static evidence through RUN-130/R and reported in RUN-131.", "dashboard footer RUN131")
    text = replace_once(text, ".tmp-run127-dashboard", ".tmp-run131-dashboard", "dashboard temp RUN131")

    marker = "dashboard = TEMPLATE.substitute("
    prefix, suffix = text.split(marker, 1)
    suffix = suffix.replace('reviewed_finance_page_gap_overlay["combined_counts"]', 'reviewed_finance_fx_revaluation_overlay["combined_counts"]')
    suffix = suffix.replace("reviewed_finance_page_gap_overlay['combined_counts']", "reviewed_finance_fx_revaluation_overlay['combined_counts']")
    suffix = suffix.replace('reviewed_finance_page_gap_overlay["queue_accounting"]', 'reviewed_finance_fx_revaluation_overlay["queue_accounting"]')
    substitution_anchor = '    finance_page_review_owner=reviewed_finance_page_gap_overlay["reviewed_overlay"]["owner_pages"],\n'
    substitution_addition = substitution_anchor + (
        '    finance_fx_wave_reviewed=reviewed_finance_fx_revaluation_overlay["reviewed_overlay"]["reviewed_route_actions"],\n'
        '    finance_fx_review_owner=reviewed_finance_fx_revaluation_overlay["reviewed_overlay"]["owner_route_actions"],\n'
        '    finance_fx_page_calls=reviewed_finance_fx_revaluation_overlay["page_context_boundary"]["literal_inertia_page_callsites"],\n'
        '    finance_fx_existing_caller_pages=reviewed_finance_fx_revaluation_overlay["page_context_boundary"]["existing_caller_pages"],\n'
        '    finance_fx_page_owners_added=reviewed_finance_fx_revaluation_overlay["page_context_boundary"]["new_page_owner_records"],\n'
        '    finance_fx_page_inherited=reviewed_finance_fx_revaluation_overlay["page_context_boundary"]["page_ownership_inherited"],\n'
        '    finance_fx_route_gap=reviewed_finance_fx_revaluation_overlay["combined_counts"]["evidence_gap_routes_tagged_within_residual"],\n'
    )
    existing_fx_substitution_start = '    finance_fx_wave_reviewed=reviewed_finance_fx_revaluation_overlay["reviewed_overlay"]["reviewed_route_actions"],\n'
    existing_fx_substitution_end = '    finance_wave_reviewed=reviewed_finance_chart_route_action_overlay["reviewed_overlay"]["reviewed_route_actions"],\n'
    if existing_fx_substitution_start in suffix:
        suffix = replace_between(
            suffix,
            existing_fx_substitution_start,
            existing_fx_substitution_end,
            substitution_addition.removeprefix(substitution_anchor),
            "dashboard FX substitution refresh",
        )
    else:
        suffix = replace_once(suffix, substitution_anchor, substitution_addition, "dashboard FX substitutions")
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
        "status": "REVIEWED_FINANCE_FX_REVALUATION_ROUTE_ACTION_OVERLAY_REPORTED_GATE_4_INCOMPLETE",
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
            "overlay_sha256": PINNED_INPUTS["evidence/source/current-run-130-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json"],
            "independent_overlay_review_sha256": PINNED_INPUTS["evidence/source/raw-run-130r-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json"],
            "superseded_dashboard_verification_sha256": PINNED_INPUTS["evidence/browser/current-audit-dashboard-verification-run-128-wave-19.json"],
            "superseded_dashboard_html_sha256": CURRENT_DASHBOARD_SHA256,
        },
        "inputs": {**CURRENT_REPORT_INPUTS, **PINNED_INPUTS},
        "outputs": outputs,
        "preserved": PRESERVED,
        "counts": {
            **overlay["combined_counts"],
            **overlay["queue_accounting"],
            "reviewed_finance_fx_revaluation_route_actions": 2,
            "reviewed_owner_route_actions_added": 2,
            "reviewed_shared_relations": 0,
            "reviewed_alias_or_redirect": 0,
            "reviewed_dead_or_noncanonical": 0,
            "reviewed_evidence_gaps": 0,
            "reviewed_non_owner_rows_preserved": 0,
            "route_owner_records_added": 2,
            "controller_action_bridges_added": 2,
            "page_owner_records_added": 0,
            "direct_exact_queue_rows_added": 2,
            "accepted_distinct_feature_ids": 1,
            "new_distinct_feature_ids": 0,
            "new_route_feature_ids": 0,
            "new_page_feature_ids": 0,
            "source_packet_expansion_records_preserved": 16,
            "assurance_findings_preserved": 15,
            "provisional_finding_records": 12,
            "final_findings": 0,
            "matrix_rows_changed": 0,
            "matrix_cells_changed": 0,
        },
        "checks": {
            "run_129r_review_go": True,
            "run_129r_outcome_conservation": "2=2+0+0+0+0",
            "run_130r_overlay_review_go": True,
            "all_discrepancy_classes_zero": True,
            "published_identity_fields_verified": 40,
            "source_packet_expansion_records_preserved": 16,
            "assurance_findings_preserved": 15,
            "route_owner_records_added": 2,
            "controller_action_bridges_added": 2,
            "page_owner_records_added": 0,
            "direct_exact_queue_rows_added": 2,
            "sibling_or_page_inheritance_used": False,
            "matrix_byte_identical": True,
            "reports_02_through_12_inventory_preserved": True,
            "canonical_finding_record_semantics_preserved": True,
            "application_source_paths_written": 0,
            "one_organisation_multi_site_architecture_preserved": True,
            "dashboard_requires_fresh_run_132_artifact_verification": True,
            "gate_4_complete": False,
        },
        "verified_identity": overlay["identity"],
        "verified_outcome_conservation": overlay["outcome_conservation"],
        "verified_projection_reconciliation": overlay["projection_reconciliation"],
        "verified_denominator_boundary": overlay["denominator_boundary"],
        "verified_source_packet_expansion_preservation": overlay["source_packet_expansion_preservation"],
        "verified_assurance_findings_preservation": overlay["assurance_findings_preservation"],
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
            "rate_and_snapshot_correctness": False,
            "ledger_integrity_correctness": False,
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
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/current-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.json",
        ],
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
    expected_paths = {Path(item).as_posix() for item in receipt["wrote_files"]}
    actual_paths = {
        line.split(maxsplit=1)[1].replace("\\", "/")
        for line in git("status", "--porcelain").splitlines()
    }
    assert actual_paths == expected_paths, (actual_paths, expected_paths)
    print(
        json.dumps(
            {
                "status": receipt["status"],
                "output": output.relative_to(REPO).as_posix(),
                "sha256": sha256_file(OUTPUT_RELATIVE),
                "source_owner_records": receipt["counts"]["source_owner_records"],
                "route_owner_records": receipt["counts"]["route_owner_records"],
                "page_owner_records": receipt["counts"]["page_owner_records"],
                "reviewed_queue_surfaces": receipt["counts"]["reviewed_queue_surface_rows"],
                "pending_queue_surfaces": receipt["counts"]["pending_unreviewed_queue_surface_rows"],
                "gate_4_complete": receipt["checks"]["gate_4_complete"],
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
