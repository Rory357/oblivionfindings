#!/usr/bin/env python3
"""Materialize RUN-103 reporting for the independently reviewed RUN-102 overlay.

The update reports 21 additional bounded route owners, 21 controller-action
bridges, and three reviewed non-owner redirects. It adds no page ownership and
preserves every framework, Site/permission/privacy/direct-object/lifecycle,
runtime, browser, test, benchmark, Pass, finding, and completion boundary.
"""

from __future__ import annotations

import hashlib
import json
import subprocess
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_RELATIVE = "evidence/source/current-run-103-reviewed-outcome-neutral-route-action-reporting-wave-13.json"

CHECKPOINT_COMMIT = "a6e6add624a42cd49715709ea310a8484c4903b6"
CHECKPOINT_TREE = "59a7684269e46592de73d95540c6d7fa5fd18c2c"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
RECORDS_SHA256 = "118c1a1f19b2e300c77d5b4d71c60f75b7038382f3c71e18f078367f8473e260"

CURRENT_REPORT_INPUTS = {
    "00-executive-summary.md": "8acae69b3ed9c5b1b55cc10b7ee34304b9bd2193078a64a4d9999d6be1444455",
    "01-repository-module-map.md": "8fce96db3b9d1cd0159c8009a32dd214a0b28570db71dd1be03cf3a85b5ee207",
    "13-unresolved-questions-and-evidence-gaps.md": "b57f2f3348dad9654e398b67fb567d8fb5047a30f23f2683154d318ed748e43b",
    "findings.json": "bdde1fa8d2d1788819afd4a49237632bdd52198664d1983ff081b88b7bb0d2a8",
    "generators/build-current-audit-dashboard.py": "07df34a4af597797f210d05cd5a6aedad4dfd4faa44a78e6cd1c47ae17f6521d",
}

PINNED_INPUTS = {
    "evidence/source/current-run-099-reviewed-route-controller-only-reporting-wave-12.json": "84e6e9a46c02a82bf9775253919bfde6b1b86a587be280d8248e4b2e38691514",
    "evidence/browser/current-audit-dashboard-verification-run-100-wave-12.json": "65c6852f6c39927142aaf0244347cbf6924a086db61eaa6a02938fe59966ab1c",
    "generators/build-outcome-neutral-route-action-cohort-wave-13.py": "f3ada90da486ba700d21596fb765ab10f661c343944899551006d5db5b9e7a0f",
    "evidence/source/root-run-101-outcome-neutral-route-action-cohort-wave-13.json": "3a8f4c3f11668406f34db7e50ae561fe1c6516e7002eb7e8271851e62c3ff655",
    "generators/materialize-independent-outcome-neutral-route-action-review-wave-13.py": "e43c20cb44521a7a6613f7e2b204dd8364142990ccb4d1df16931d922c5f04c2",
    "evidence/source/raw-run-101r-independent-outcome-neutral-route-action-review-wave-13.json": "518321096f6a483321e3ad129f730db4b628cb70a74e1dbec4149b08c9b09eba",
    "generators/integrate-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.py": "648f5bc57cde303568c99a6f9acaf608023a0ef6e17a891eb478553f85b7a9ce",
    "evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json": "cf68900351832c790ec53b4996daaf640c604c498f902c85dec681113bf492dd",
    "generators/materialize-independent-reviewed-outcome-neutral-route-action-ownership-overlay-review-wave-13.py": "5dad0d4308c4a129ee1d5b4d41f581231031872fe3d586f7e346039a8c4ae8e9",
    "evidence/source/raw-run-102r-independent-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json": "f88c3ce6ae7b82ca316c656787547bdd9e6a4cd40469b16d44a6e84f99d14902",
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
    resolved = (AUDIT_DIR / relative).resolve()
    assert resolved.is_relative_to(AUDIT_DIR.resolve()), relative
    return resolved


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(relative: str) -> str:
    target = path(relative)
    assert target.is_file(), relative
    return sha256_bytes(target.read_bytes())


def read_json(relative: str) -> dict[str, Any]:
    value = json.loads(path(relative).read_text(encoding="utf-8"))
    assert isinstance(value, dict), relative
    return value


def canonical_json_sha256(value: Any) -> str:
    raw = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return sha256_bytes(raw.encode("utf-8"))


def git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args], cwd=REPO, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True
    )
    return result.stdout.strip()


def write_lf(relative: str, text: str) -> None:
    assert "\r" not in text
    encoded = text.encode("utf-8")
    target = path(relative)
    if target.exists() and target.read_bytes() == encoded:
        return
    target.write_bytes(encoded)


def replace_once_or_present(text: str, old: str, new: str, label: str) -> str:
    if new in text:
        return text
    assert text.count(old) == 1, (label, text.count(old))
    return text.replace(old, new, 1)


def replace_exact_count(text: str, old: str, new: str, expected: int, label: str) -> str:
    if text.count(new) == expected and old not in text:
        return text
    assert text.count(old) == expected, (label, text.count(old), expected)
    return text.replace(old, new)


def insert_before_once(text: str, marker: str, insertion: str, label: str) -> str:
    if insertion in text:
        return text
    assert text.count(marker) == 1, (label, text.count(marker))
    return text.replace(marker, insertion + marker, 1)


def upsert_section_before(text: str, heading: str, marker: str, section: str, label: str) -> str:
    assert text.count(marker) == 1, (label, "marker", text.count(marker))
    marker_index = text.index(marker)
    if heading not in text:
        return insert_before_once(text, marker, section, label)
    first = text.index(heading)
    last = text.rindex(heading)
    assert first == last and first < marker_index, (label, first, last, marker_index)
    before = text[:first].rstrip("\n")
    return before + "\n\n" + section.strip("\n") + "\n" + text[marker_index:]


def replace_line_containing(text: str, token: str, replacement: str, label: str) -> str:
    lines = text.splitlines()
    if replacement in lines:
        return text
    matches = [index for index, line in enumerate(lines) if token in line]
    assert len(matches) == 1, (label, matches)
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

    overlay = read_json("evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json")
    review = read_json("evidence/source/raw-run-102r-independent-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json")
    assert review["decision"]["verdict"] == "GO"
    assert review["decision"]["mechanical_discrepancies"] == 0
    assert review["decision"]["semantic_boundary_discrepancies"] == 0
    assert review["decision"]["wording_discrepancies_remaining"] == 0
    assert review["decision"]["reporting_materialization_authorized"] is True
    assert review["decision"]["gate_4_complete"] is False
    assert overlay["combined_counts"] == review["verified_combined_counts"]
    assert overlay["queue_accounting"] == review["verified_queue_accounting"]
    assert review["decision"]["route_owner_records_authorized"] == 21
    assert review["decision"]["controller_action_bridges_authorized"] == 21
    assert review["decision"]["reviewed_alias_records_authorized"] == 3
    assert review["decision"]["page_owner_records_authorized"] == 0
    assert overlay["combined_counts"]["source_owner_records"] == 592
    assert overlay["combined_counts"]["route_owner_records"] == 265
    assert overlay["combined_counts"]["page_owner_records"] == 327
    assert overlay["combined_counts"]["distinct_feature_ids"] == 249
    assert overlay["combined_counts"]["static_controller_action_bridges"] == 53
    assert overlay["queue_accounting"]["owner_queue_surface_rows"] == 54
    assert overlay["queue_accounting"]["alias_queue_surface_rows"] == 3
    assert overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 448
    assert overlay["credit_boundary"]["static_page_feature_ownership"] is False
    assert overlay["credit_boundary"]["frontend_caller_ownership"] is False
    assert overlay["credit_boundary"]["direct_object_correctness"] is False
    assert overlay["denominator_boundary"]["gate_4_complete"] is False
    return overlay, review


def patch_reports() -> None:
    summary_relative = "00-executive-summary.md"
    summary = path(summary_relative).read_text(encoding="utf-8")
    summary = replace_once_or_present(
        summary,
        "## RUN-097–099 reviewed route/controller-only ownership overlay",
        "## Historical RUN-097–099 reviewed route/controller-only ownership overlay",
        "historical Wave 12 summary heading",
    )
    summary = replace_once_or_present(
        summary,
        "The current bounded checkpoint is **571 records = 244 routes + 327 pages across 246 canonical FEATURE-IDs (226 H + 20 D)**, with 32 controller-action bridges.",
        "That historical bounded checkpoint is **571 records = 244 routes + 327 pages across 246 canonical FEATURE-IDs (226 H + 20 D)**, with 32 controller-action bridges.",
        "historical Wave 12 summary count",
    )
    summary = replace_once_or_present(
        summary,
        "Of the 507-row queue, 35 surfaces are reviewed (33 owned and two shared), 472 remain unreviewed, and 474 remain without ownership.",
        "At that checkpoint, 35 of the 507 queue surfaces were reviewed (33 owned and two shared), 472 remained unreviewed, and 474 remained without ownership.",
        "historical Wave 12 queue",
    )
    summary_section = """
## RUN-101–103 reviewed outcome-neutral route/action overlay

RUN-101 freezes 24 outcome-neutral route/action candidates from the remaining queue without pre-awarding ownership. Three fresh partition reviews classify 21 as `OWNER_ROUTE_ACTION` and the Recipe Library index, create, and show actions as `ALIAS_OR_REDIRECT`; there are zero shared, dead/noncanonical, or evidence-gap outcomes. The substantive recipe-edit JSON action remains an owner without caller or page credit, and the notification caller-census omission is recorded without ownership inheritance.

RUN-102 integrates only the 21 explicit owners as 21 bounded route-source records and 21 controller-action bridges. The three redirects remain explicit reviewed non-owner records and occur in neither owner nor bridge arrays. RUN-102R independently verifies the exact final bytes, all identities and collision exclusions, the corrected action wording, and every conservation equation with zero discrepancies.

The current bounded checkpoint is **592 records = 265 routes + 327 pages across 249 canonical FEATURE-IDs (229 H + 20 D)**, with 53 controller-action bridges. Route and page owners span 59 and 234 FEATURE-IDs with 44 in their overlap. This is 15.067447% of the bounded 3,929-record source universe; 3,337 records remain. The route universe is 3,218 = 265 owners + five shared + three aliases + 2,945 residual; the page universe remains 711 = 327 owners + 382 unadjudicated + two shared. Of the 507-row queue, 59 surfaces are reviewed (54 owned, two shared, and three aliases), 448 remain unreviewed, and 453 remain without ownership.

RUN-103 reports only that bounded delta. Oblivion Findings remains one operating organisation across multiple Sites. Site access, roles/permissions, canonical ownership, direct-object denial, privacy, lifecycle, framework reachability, runtime, database, build, application browser, tests, benchmarks, ease, Passes, findings, completion, and Gate 4 remain separate open or zero-credit gates. The 340-row matrix remains byte-identical and mapping remains 0/340.
"""
    summary = upsert_section_before(
        summary,
        "## RUN-101–103 reviewed outcome-neutral route/action overlay",
        "\n## Current raw source census\n",
        summary_section,
        "Wave 13 summary section",
    )
    marker = (
        "- `generators/materialize-run-099-reviewed-route-controller-only-reporting-wave-12.py` and "
        "`evidence/source/current-run-099-reviewed-route-controller-only-reporting-wave-12.json`: "
        "deterministic RUN-099 reporting refresh preserving the matrix, reports 02–12/inventory, and "
        "downstream zero-credit boundaries.\n"
    )
    addition = marker + (
        "- `evidence/browser/current-audit-dashboard-verification-run-100-wave-12.json`: exact superseded RUN-099 dashboard artifact verification; no metrics transfer to the current dashboard.\n"
        "- `generators/build-outcome-neutral-route-action-cohort-wave-13.py` and `evidence/source/root-run-101-outcome-neutral-route-action-cohort-wave-13.json`: deterministic 24-row outcome-neutral cohort with zero pre-review credit.\n"
        "- `generators/materialize-independent-outcome-neutral-route-action-review-wave-13.py` and `evidence/source/raw-run-101r-independent-outcome-neutral-route-action-review-wave-13.json`: three fresh reviews, 21 owners, three aliases, and zero page/downstream credit.\n"
        "- `generators/integrate-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.py` and `evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json`: exact 21-route / 21-bridge owner-only delta with three preserved non-owner redirects.\n"
        "- `generators/materialize-independent-reviewed-outcome-neutral-route-action-ownership-overlay-review-wave-13.py` and `evidence/source/raw-run-102r-independent-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json`: independent final-byte mechanical, semantic-credit, and reporting-contract GO receipt.\n"
        "- `generators/materialize-run-103-reviewed-outcome-neutral-route-action-reporting-wave-13.py` and `evidence/source/current-run-103-reviewed-outcome-neutral-route-action-reporting-wave-13.json`: deterministic RUN-103 reporting refresh preserving the matrix, reports 02–12/inventory, and every downstream zero-credit boundary.\n"
    )
    summary = replace_once_or_present(summary, marker, addition, "Wave 13 evidence links")
    write_lf(summary_relative, summary)

    map_relative = "01-repository-module-map.md"
    module_map = path(map_relative).read_text(encoding="utf-8")
    module_map = replace_once_or_present(
        module_map,
        "## RUN-097–098 reviewed route/controller-only overlay",
        "## Historical RUN-097–098 reviewed route/controller-only overlay",
        "historical Wave 12 map heading",
    )
    module_map = replace_once_or_present(
        module_map,
        "The cumulative bounded ledger is 571 source owners (244 route + 327 page) across 246 FEATURE-IDs (226 H + 20 D).",
        "That historical cumulative bounded ledger is 571 source owners (244 route + 327 page) across 246 FEATURE-IDs (226 H + 20 D).",
        "historical Wave 12 map count",
    )
    map_section = """
## RUN-101–102 reviewed outcome-neutral route/action overlay

RUN-101 freezes 24 pending route actions across six FEATURE-IDs for fresh outcome-neutral review. The three recipe index/create/show actions are redirects and remain non-owners; the remaining 21 actions are explicit owners. No outcome inherits page ownership from frontend callers, rendered roots, or page-graph context.

RUN-102 adds exactly 21 route owners and 21 controller-action bridges, preserves three alias records, and adds zero page roots. The cumulative bounded ledger is 592 source owners (265 route + 327 page) across 249 FEATURE-IDs (229 H + 20 D). The route/page feature sets are 59/234 with overlap 44, and the action-bridge count is 53. Queue accounting is 507 total, 59 reviewed, 54 owned, two shared, three aliases, 448 pending, and 453 without ownership.

RUN-102R verifies the exact final bytes with zero discrepancies. These relations establish bounded static route/action ownership and explicit non-owner alias classification only. They do not establish framework reachability, Site or permission correctness, canonical direct-object concealment, privacy or lifecycle behaviour, runtime, build, browser, tests, benchmarks, findings, Passes, or completion.
"""
    module_map = upsert_section_before(
        module_map,
        "## RUN-101–102 reviewed outcome-neutral route/action overlay",
        "\n## Candidate register\n",
        map_section,
        "Wave 13 map section",
    )
    write_lf(map_relative, module_map)

    gaps_relative = "13-unresolved-questions-and-evidence-gaps.md"
    gaps = path(gaps_relative).read_text(encoding="utf-8")
    gaps = replace_line_containing(
        gaps,
        "| Required reporting paths |",
        "| Required reporting paths | 18 / 18 prompt-required files or directories present. RUN-100 independently verified the exact now-superseded RUN-099 dashboard at four viewports; the regenerated RUN-103 dashboard requires a separate fresh RUN-104 artifact receipt. | Presence and prior audit-artifact verification make the reporting contract inspectable but grant no application execution, final-finding, Pass, or completion credit. Gate 26 remains open. | Complete the semantic, execution, benchmark, Pass 8, final reconciliation, and no-live-agent gates; repeat audit-dashboard verification after every later HTML change. |",
        "required paths row",
    )
    gaps = replace_line_containing(
        gaps,
        "| Runtime routes |",
        "| Runtime routes | RUN-102/R establish 265 bounded route-owner records and 53 static controller-action bridges; 2,945 residual explicit-unmapped route rows, five semantic-shared route rows, and three reviewed alias rows remain distinguished within the bounded 3,218-row static route-like universe. | Static owner/action linkage is not a framework-expanded route table or reachability proof. Missing dependency/runtime provenance keeps framework runtime NO-GO, and the historical 3,024-route denominator cannot be inherited. | Under a fresh bounded runtime grant, use an exact disposable database, execute a separately pinned framework/provider route lane, and reconcile it to all 38 source route files and 3,218 route-like rows. |",
        "runtime routes row",
    )
    gaps = replace_line_containing(
        gaps,
        "| Inertia pages |",
        "| Inertia pages | RUN-084/R independently enumerate 1,058 physical page-tree files. RUN-102/R leave bounded page-root ownership unchanged at 327 records; 382 page roots remain unadjudicated and two reviewed roots remain semantic-shared. | RUN-101–102 add zero page ownership. Full-tree structural GO and bounded ownership are not a complete canonical crosswalk, runtime reachability, build resolution, rendered browser behaviour, or final feature mapping. | Reconcile all 711 literal roots and support relations to safely expanded framework routes and frozen FEATURE-IDs, retain shared relations explicitly, prove build resolution, and observe every safely reachable current-build page under approved roles/Sites. |",
        "pages row",
    )
    gaps = replace_line_containing(
        gaps,
        "| Canonical features |",
        "| Canonical features | Static canonical identity remains 340 targets: 300 H, 40 D, zero M; RUN-102/R establish 592 bounded source-owner records (265 routes + 327 pages) across 249 FEATURE-IDs (229 H + 20 D) plus 53 controller-action bridges while the matrix remains byte-identical at `dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390`. | This is 15.067447% of the bounded 3,929-record source universe. Gate 4 remains incomplete: 3,337 residual records, the framework-expanded denominator, shared and alias relations, reachability, and the full crosswalk remain open; matrix target mapping stays 0/340. | Finish canonical denominator and residual ownership adjudication without inheriting FEATURE-IDs from prefixes, imports, containment, names, or presence, and without awarding runtime, browser, test, benchmark, ease, release, or completion credit. |",
        "canonical row",
    )
    gaps = replace_line_containing(
        gaps,
        "| Agent universe and writer rule |",
        "| Agent universe and writer rule | RUN-001 through RUN-103 represented at the current reporting checkpoint; finalization gate false. | RUN-101/R review 24 route actions as 21 owners and three aliases, RUN-102/R independently integrate and verify 21 route owners plus 21 bridges with zero page additions, and RUN-103 reports only those bounded classes. Runtime, browser, tests, benchmarks, Pass 8, finalization, and completion remain open. | Complete residual ownership and every semantic/execution gate, then dispatch fresh Pass 8/final cross-reviewers, verify the final dashboard, and prove no agent remains live at finalization. |",
        "agent row",
    )
    gaps = replace_once_or_present(
        gaps,
        "## RUN-077–099 route/page, page-tree, backend, ownership and reporting lineage",
        "## RUN-077–103 route/page, page-tree, backend, ownership and reporting lineage",
        "lineage heading",
    )
    lineage = (
        "RUN-077 freezes the exhaustive committed-source route/name/page universe. RUN-078 records all "
        "3,218 route-like, 3,245 name, and 711 page decisions. RUN-079's cyclic A→B, B→C, C→A "
        "independent reviews are all GO with zero invalid decisions and no writes. RUN-080 integrates only "
        "78 route-name and 2 page-file fields; RUN-081 materializes those reports and hashes. RUN-082/R "
        "add and independently reproduce zero-credit candidate relations plus 38/38 static route-file "
        "registration closure. RUN-083 refreshes and verifies reporting; RUN-084/R close the 1,058-file "
        "page-tree structural ledger; RUN-084B/BR close the 1,789-role-row backend structural ledger with "
        "zero whole-file semantic reviews; RUN-085 refreshes reporting; RUN-086/R establish the first 530 "
        "bounded source-owner records; and RUN-087 reports them. RUN-089 repeats the signed-out/build-"
        "unattributed application preflight. RUN-090 freezes a 507-row zero-credit review queue. RUN-091/R "
        "accept nine closed chains and retain two as shared. RUN-092/R integrate and independently reproduce "
        "18 additional owner records plus nine controller-action bridges, yielding 548 owners across 239 "
        "FEATURE-IDs, and RUN-093 reports only that bounded overlay. RUN-097/R then freeze and independently "
        "approve 23 route/controller-only owners. RUN-098/R integrate and independently verify 23 route rows "
        "plus 23 action bridges with zero page additions, yielding 571 owners across 246 FEATURE-IDs; RUN-099 "
        "reports only that bounded delta, and RUN-100 verifies its now-superseded dashboard artifact. RUN-101/R "
        "then review 24 route actions as 21 owners and three aliases. RUN-102/R integrate and independently "
        "verify exactly 21 route rows and 21 bridges while preserving three redirect non-owners and adding zero "
        "pages, yielding 592 owners across 249 FEATURE-IDs; RUN-103 reports only that bounded delta. Gate 4 and "
        "the full route/page/backend crosswalk remain incomplete; Oblivion Findings remains a single-tenant "
        "application for one organisation across multiple Sites, and framework reachability, Site/permission/"
        "privacy/direct-object/lifecycle correctness, runtime, build, signed-in application browser, executed "
        "tests, benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit."
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
    findings["pins"].update(
        {
            "run_100_dashboard_verification_sha256": PINNED_INPUTS["evidence/browser/current-audit-dashboard-verification-run-100-wave-12.json"],
            "run_101_outcome_neutral_cohort_generator_sha256": PINNED_INPUTS["generators/build-outcome-neutral-route-action-cohort-wave-13.py"],
            "run_101_outcome_neutral_cohort_sha256": PINNED_INPUTS["evidence/source/root-run-101-outcome-neutral-route-action-cohort-wave-13.json"],
            "run_101r_outcome_neutral_review_materializer_sha256": PINNED_INPUTS["generators/materialize-independent-outcome-neutral-route-action-review-wave-13.py"],
            "run_101r_outcome_neutral_review_sha256": PINNED_INPUTS["evidence/source/raw-run-101r-independent-outcome-neutral-route-action-review-wave-13.json"],
            "run_102_outcome_neutral_overlay_generator_sha256": PINNED_INPUTS["generators/integrate-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.py"],
            "run_102_outcome_neutral_overlay_sha256": PINNED_INPUTS["evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json"],
            "run_102r_outcome_neutral_overlay_review_materializer_sha256": PINNED_INPUTS["generators/materialize-independent-reviewed-outcome-neutral-route-action-ownership-overlay-review-wave-13.py"],
            "run_102r_outcome_neutral_overlay_review_sha256": PINNED_INPUTS["evidence/source/raw-run-102r-independent-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json"],
        }
    )
    findings["counts"].update(
        {
            "static_source_feature_ownership_records": 592,
            "static_source_feature_ownership_route_records": 265,
            "static_source_feature_ownership_page_records": 327,
            "static_source_feature_ownership_distinct_feature_ids": 249,
            "static_controller_action_bridges": 53,
            "bounded_static_source_ownership_percent": "15.067447",
            "bounded_static_source_residual_records": 3337,
            "direct_exact_queue_records": 507,
            "direct_exact_queue_pending_unreviewed": 448,
            "static_source_feature_ownership_distinct_H_feature_ids": 229,
            "static_source_feature_ownership_distinct_D_feature_ids": 20,
            "static_source_feature_ownership_route_distinct_feature_ids": 59,
            "static_source_feature_ownership_page_distinct_feature_ids": 234,
            "static_source_feature_ownership_route_page_feature_overlap": 44,
            "direct_exact_queue_reviewed": 59,
            "direct_exact_queue_owned": 54,
            "direct_exact_queue_shared": 2,
            "direct_exact_queue_alias": 3,
            "direct_exact_queue_dead_or_noncanonical": 0,
            "direct_exact_queue_evidence_gap": 0,
            "direct_exact_queue_without_ownership": 453,
        }
    )
    findings["current_direct_exact_route_page_review_queue"] = {
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
    findings["current_static_source_feature_ownership"] = {
        "run_id": overlay["run_id"],
        "review_run_id": review["run_id"],
        "status": "GO_REVIEWED_BOUNDED_OUTCOME_NEUTRAL_ROUTE_ACTION_OWNERSHIP_ONLY",
        "baseline_records": 571,
        "reviewed_route_actions": 24,
        "overlay_source_records": 21,
        "owner_route_actions_added": 21,
        "reviewed_alias_or_redirect": 3,
        "shared_relations_added": 0,
        "dead_or_noncanonical": 0,
        "evidence_gaps": 0,
        "controller_action_bridges_added": 21,
        "page_owner_records_added": 0,
        **overlay["combined_counts"],
        "queue_accounting": overlay["queue_accounting"],
        "independent_review_discrepancies": 0,
        "gate_4": {"status": "PARTIAL_BOUNDED_STATIC_SOURCE_OWNERSHIP_INCOMPLETE", "complete": False},
        "credit_boundary": overlay["credit_boundary"],
    }
    if "current_route_controller_only_ownership_review" in findings:
        findings["historical_run_098_route_controller_only_ownership_review"] = findings.pop(
            "current_route_controller_only_ownership_review"
        )
    findings["current_outcome_neutral_route_action_ownership_review"] = {
        "run_id": review["run_id"],
        "status": review["status"],
        "reviewers": review["decision"]["independent_reviews"],
        "route_owner_records_authorized": 21,
        "controller_action_bridges_authorized": 21,
        "reviewed_alias_records_authorized": 3,
        "page_owner_records_authorized": 0,
        "mechanical_discrepancies": 0,
        "semantic_boundary_discrepancies": 0,
        "wording_discrepancies_remaining": 0,
        "gate_4_complete": False,
        "completion_credit": False,
    }
    run100 = read_json("evidence/browser/current-audit-dashboard-verification-run-100-wave-12.json")
    findings["current_audit_artifact_verification_history"]["run_100"] = {
        "status": "GO_EXACT_SUPERSEDED_RUN_099_DASHBOARD_ARTIFACT_ZERO_APPLICATION_CREDIT",
        "dashboard_sha256": run100["pins"]["dashboard_html_sha256"],
        "receipt_sha256": PINNED_INPUTS["evidence/browser/current-audit-dashboard-verification-run-100-wave-12.json"],
        "viewports_verified": run100["verification"]["viewports_verified"],
        "unique_local_links_verified": run100["verification"]["unique_local_links"],
        "anchors_verified": run100["verification"]["anchors"],
        "duplicate_authored_ids": run100["verification"]["duplicate_authored_ids"],
        "console_warnings": run100["verification"]["console_warnings"],
        "console_errors": run100["verification"]["console_errors"],
        "current_dashboard_credit": False,
        "application_browser_credit": False,
    }
    assert canonical_json_sha256(findings["records"]) == RECORDS_SHA256
    assert findings["counts"]["final_P0"] == 0
    assert findings["counts"]["final_P1"] == 0
    assert findings["counts"]["benchmark_mapped"] == 0
    assert findings["counts"]["final_no_match"] == 0
    write_lf(relative, json.dumps(findings, ensure_ascii=False, indent=2) + "\n")


def patch_dashboard_generator() -> None:
    relative = "generators/build-current-audit-dashboard.py"
    text = path(relative).read_text(encoding="utf-8")
    load_marker = 'reviewed_route_controller_overlay_review = read_json("evidence/source/raw-run-098r-independent-reviewed-route-controller-only-ownership-overlay-wave-12.json")\n'
    loads = load_marker + (
        'outcome_neutral_cohort = read_json("evidence/source/root-run-101-outcome-neutral-route-action-cohort-wave-13.json")\n'
        'outcome_neutral_review = read_json("evidence/source/raw-run-101r-independent-outcome-neutral-route-action-review-wave-13.json")\n'
        'reviewed_outcome_neutral_overlay = read_json("evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json")\n'
        'reviewed_outcome_neutral_overlay_review = read_json("evidence/source/raw-run-102r-independent-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json")\n'
    )
    text = replace_once_or_present(text, load_marker, loads, "Wave 13 dashboard loads")

    assertion_marker = "\n\ncandidates = wave1[\"candidates\"] + wave2[\"candidates\"] + wave3[\"candidates\"]\n"
    assertions = f'''\n\nassert sha256_file("generators/build-outcome-neutral-route-action-cohort-wave-13.py") == "{PINNED_INPUTS['generators/build-outcome-neutral-route-action-cohort-wave-13.py']}"
assert sha256_file("evidence/source/root-run-101-outcome-neutral-route-action-cohort-wave-13.json") == "{PINNED_INPUTS['evidence/source/root-run-101-outcome-neutral-route-action-cohort-wave-13.json']}"
assert sha256_file("generators/materialize-independent-outcome-neutral-route-action-review-wave-13.py") == "{PINNED_INPUTS['generators/materialize-independent-outcome-neutral-route-action-review-wave-13.py']}"
assert sha256_file("evidence/source/raw-run-101r-independent-outcome-neutral-route-action-review-wave-13.json") == "{PINNED_INPUTS['evidence/source/raw-run-101r-independent-outcome-neutral-route-action-review-wave-13.json']}"
assert sha256_file("generators/integrate-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.py") == "{PINNED_INPUTS['generators/integrate-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.py']}"
assert sha256_file("evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json") == "{PINNED_INPUTS['evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json']}"
assert sha256_file("generators/materialize-independent-reviewed-outcome-neutral-route-action-ownership-overlay-review-wave-13.py") == "{PINNED_INPUTS['generators/materialize-independent-reviewed-outcome-neutral-route-action-ownership-overlay-review-wave-13.py']}"
assert sha256_file("evidence/source/raw-run-102r-independent-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json") == "{PINNED_INPUTS['evidence/source/raw-run-102r-independent-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json']}"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-100-wave-12.json") == "{PINNED_INPUTS['evidence/browser/current-audit-dashboard-verification-run-100-wave-12.json']}"
assert outcome_neutral_cohort["counts"]["candidate_route_actions"] == 24
assert outcome_neutral_cohort["counts"]["candidate_page_records"] == 0
assert outcome_neutral_review["decision"]["owner_route_actions"] == 21
assert outcome_neutral_review["decision"]["alias_or_redirect"] == 3
assert outcome_neutral_review["decision"]["static_page_owner_records_authorized"] == 0
assert reviewed_outcome_neutral_overlay["combined_counts"] == reviewed_outcome_neutral_overlay_review["verified_combined_counts"]
assert reviewed_outcome_neutral_overlay["queue_accounting"] == reviewed_outcome_neutral_overlay_review["verified_queue_accounting"]
assert reviewed_outcome_neutral_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_outcome_neutral_overlay_review["decision"]["mechanical_discrepancies"] == 0
assert reviewed_outcome_neutral_overlay_review["decision"]["semantic_boundary_discrepancies"] == 0
assert reviewed_outcome_neutral_overlay_review["decision"]["wording_discrepancies_remaining"] == 0
assert reviewed_outcome_neutral_overlay_review["decision"]["page_owner_records_authorized"] == 0
assert reviewed_outcome_neutral_overlay_review["decision"]["gate_4_complete"] is False
assert reviewed_outcome_neutral_overlay["combined_counts"]["source_owner_records"] == 592
assert reviewed_outcome_neutral_overlay["combined_counts"]["route_owner_records"] == 265
assert reviewed_outcome_neutral_overlay["combined_counts"]["page_owner_records"] == 327
assert reviewed_outcome_neutral_overlay["combined_counts"]["distinct_feature_ids"] == 249
assert reviewed_outcome_neutral_overlay["combined_counts"]["distinct_H_feature_ids"] == 229
assert reviewed_outcome_neutral_overlay["combined_counts"]["distinct_D_feature_ids"] == 20
assert reviewed_outcome_neutral_overlay["combined_counts"]["route_distinct_feature_ids"] == 59
assert reviewed_outcome_neutral_overlay["combined_counts"]["page_distinct_feature_ids"] == 234
assert reviewed_outcome_neutral_overlay["combined_counts"]["route_page_feature_overlap"] == 44
assert reviewed_outcome_neutral_overlay["combined_counts"]["static_controller_action_bridges"] == 53
assert reviewed_outcome_neutral_overlay["combined_counts"]["bounded_static_source_residual_records"] == 3337
assert reviewed_outcome_neutral_overlay["combined_counts"]["residual_explicit_unmapped_routes"] == 2945
assert reviewed_outcome_neutral_overlay["combined_counts"]["reviewed_alias_routes"] == 3
assert reviewed_outcome_neutral_overlay["queue_accounting"]["reviewed_queue_surface_rows"] == 59
assert reviewed_outcome_neutral_overlay["queue_accounting"]["owner_queue_surface_rows"] == 54
assert reviewed_outcome_neutral_overlay["queue_accounting"]["shared_queue_surface_rows"] == 2
assert reviewed_outcome_neutral_overlay["queue_accounting"]["alias_queue_surface_rows"] == 3
assert reviewed_outcome_neutral_overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 448
assert reviewed_outcome_neutral_overlay["queue_accounting"]["queue_surfaces_without_ownership"] == 453
assert 3929 == 592 + 3337
assert 592 == 265 + 327
assert 3218 == 265 + 5 + 3 + 2945
assert 711 == 327 + 382 + 2
assert 249 == 59 + 234 - 44
assert 507 == 59 + 448
assert 59 == 54 + 2 + 3
assert all(
    reviewed_outcome_neutral_overlay["credit_boundary"][key] is False
    for key in (
        "static_page_feature_ownership", "frontend_caller_ownership",
        "complete_route_page_feature_crosswalk", "framework_route_reachability", "navigation",
        "site_authorization_correctness", "permission_correctness", "privacy_correctness",
        "direct_object_correctness", "lifecycle_correctness", "runtime", "database", "build",
        "application_browser", "executed_tests", "benchmark", "ease", "pass", "final_finding",
        "completion", "audit_complete",
    )
)
'''
    text = insert_before_once(text, assertion_marker, assertions, "Wave 13 dashboard assertions")

    evidence_marker = '    ("RUN-099 route/action reporting/hash receipt", "evidence/source/current-run-099-reviewed-route-controller-only-reporting-wave-12.json"),\n'
    evidence_addition = evidence_marker + (
        '    ("RUN-100 verified superseded dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-100-wave-12.json"),\n'
        '    ("RUN-101 outcome-neutral cohort generator", "generators/build-outcome-neutral-route-action-cohort-wave-13.py"),\n'
        '    ("RUN-101 24-row outcome-neutral route/action cohort", "evidence/source/root-run-101-outcome-neutral-route-action-cohort-wave-13.json"),\n'
        '    ("RUN-101R independent 21-owner / 3-alias review materializer", "generators/materialize-independent-outcome-neutral-route-action-review-wave-13.py"),\n'
        '    ("RUN-101R independent 21-owner / 3-alias review", "evidence/source/raw-run-101r-independent-outcome-neutral-route-action-review-wave-13.json"),\n'
        '    ("RUN-102 owner-only overlay generator", "generators/integrate-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.py"),\n'
        '    ("RUN-102 21-route / 21-bridge overlay with 3 aliases", "evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json"),\n'
        '    ("RUN-102R independent overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-route-action-ownership-overlay-review-wave-13.py"),\n'
        '    ("RUN-102R independent final-byte overlay review", "evidence/source/raw-run-102r-independent-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json"),\n'
        '    ("RUN-103 outcome-neutral reporting materializer", "generators/materialize-run-103-reviewed-outcome-neutral-route-action-reporting-wave-13.py"),\n'
        '    ("RUN-103 outcome-neutral reporting/hash receipt", "evidence/source/current-run-103-reviewed-outcome-neutral-route-action-reporting-wave-13.json"),\n'
    )
    text = replace_once_or_present(text, evidence_marker, evidence_addition, "Wave 13 dashboard evidence")

    replacements = [
        ('<a href="#checkpoint">RUN-099</a>', '<a href="#checkpoint">RUN-103</a>', "nav"),
        ('<strong>RUN-071–099 current reporting checkpoint:</strong>', '<strong>RUN-071–103 current reporting checkpoint:</strong>', "notice heading"),
        ('<h2>RUN-071–099 completion-gate checkpoint</h2>', '<h2>RUN-071–103 completion-gate checkpoint</h2>', "checkpoint heading"),
        ('<h2>RUN-071–099 evidence lineage</h2>', '<h2>RUN-071–103 evidence lineage</h2>', "lineage heading"),
        ('RUN-077–099 source/reporting artifact', 'RUN-077–103 source/reporting artifact', "lineage text"),
        ('RUN-001 through RUN-099 are represented by audit artifacts;', 'RUN-001 through RUN-103 are represented by audit artifacts;', "wave range"),
        ('Generated deterministically from independently reviewed static evidence through RUN-098/R and reported in RUN-099.', 'Generated deterministically from independently reviewed static evidence through RUN-102/R and reported in RUN-103.', "footer"),
        ('.tmp-run099-dashboard', '.tmp-run103-dashboard', "temp suffix"),
    ]
    for old, new, label in replacements:
        text = replace_once_or_present(text, old, new, label)

    text = replace_once_or_present(
        text,
        'RUN-091/R and RUN-092/R remain the historical nine-chain overlay. RUN-097/R accept 23 route/controller-only owners; RUN-098/R independently establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges, with zero new page owners.',
        'RUN-091/R and RUN-092/R remain the historical nine-chain overlay. RUN-097/R–100 remain the historical 23-owner route/action checkpoint and its exact superseded dashboard verification. RUN-101/R review 24 route actions as 21 owners and 3 aliases; RUN-102/R independently establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges, with 21 new route owners, 3 preserved non-owner aliases, and zero new page owners.',
        "primary notice current overlay",
    )
    text = replace_once_or_present(
        text,
        'RUN-097/R–098/R add 23 reviewed route owners and 23 action bridges with zero page additions, raising bounded ownership to $static_owner_records records and $static_action_bridges action bridges; $queue_pending queue rows remain unreviewed and $queue_without_owner remain without ownership.',
        'RUN-097/R–100 remain the historical 23-owner checkpoint and dashboard receipt. RUN-101/R–102/R add 21 reviewed route owners, preserve 3 aliases, and add 21 action bridges with zero page additions, raising bounded ownership to $static_owner_records records and $static_action_bridges action bridges; $queue_pending queue rows remain unreviewed and $queue_without_owner remain without ownership. RUN-103 reports only that bounded delta.',
        "checkpoint notice current overlay",
    )
    text = replace_once_or_present(
        text,
        'RUN-097/R–098/R add and independently verify 23 route/action owners with zero page additions, and RUN-099 refreshes current reporting.',
        'RUN-097/R–100 preserve the historical 23-owner checkpoint and dashboard verification, RUN-101/R–102/R independently review and integrate 21 route/action owners plus 3 aliases with zero page additions, and RUN-103 refreshes current reporting.',
        "checkpoint narrative",
    )
    text = replace_once_or_present(
        text,
        'RUN-097/R–098/R add 23 independently reviewed route/action owners with zero page additions, and RUN-099 refreshes reporting.',
        'RUN-097/R–100 preserve the historical 23-owner checkpoint, RUN-101/R–102/R add 21 independently reviewed route/action owners and preserve 3 aliases with zero page additions, and RUN-103 refreshes reporting.',
        "static census intro",
    )
    text = replace_once_or_present(
        text,
        '<tr><td>RUN-097/R → 098/R route/action overlay</td><td><strong>23 owner route/actions · 23 route rows · 23 action bridges · 0 page rows</strong></td><td class="partial">$static_owner_records cumulative owners · $static_owner_routes routes + $static_owner_pages pages · $static_owner_features FEATURE-IDs · Gate 4 incomplete</td></tr><tr><td>RUN-099 reporting refresh</td><td><strong>route/action overlay reported</strong></td><td class="partial">audit-only materialization · matrix and preserved reports byte-identical</td></tr>',
        '<tr><td>RUN-097/R → 098/R historical route/action overlay</td><td><strong>23 owner route/actions · 23 route rows · 23 action bridges · 0 page rows</strong></td><td class="partial">571 cumulative owners · 244 routes + 327 pages · 246 FEATURE-IDs · historical bounded checkpoint</td></tr><tr><td>RUN-099 / RUN-100 historical reporting and dashboard</td><td><strong>route/action overlay reported and exact dashboard verified</strong></td><td class="partial">audit-only history · no verification metrics transfer</td></tr><tr><td>RUN-101/R → 102/R outcome-neutral overlay</td><td><strong>24 reviewed · 21 owner route/actions · 3 aliases · 21 route rows · 21 action bridges · 0 page rows</strong></td><td class="partial">$static_owner_records cumulative owners · $static_owner_routes routes + $static_owner_pages pages · $static_owner_features FEATURE-IDs · Gate 4 incomplete</td></tr><tr><td>RUN-103 reporting refresh</td><td><strong>outcome-neutral overlay reported</strong></td><td class="partial">audit-only materialization · matrix and preserved reports byte-identical · fresh RUN-104 verification required</td></tr>',
        "checkpoint table Wave 13 rows",
    )
    text = replace_once_or_present(
        text,
        '<li>RUN-097/R: 23 route/controller-only candidates · three fresh reviews · 23 owners · 0 shared/alias/dead/gap · 0 page credit</li><li>RUN-098/R: 23 route rows + 23 action bridges integrated and independently verified · $static_owner_records cumulative owner records</li><li>RUN-099: deterministic route/action reporting refresh · matrix and every Site/permission/privacy/lifecycle/execution/benchmark/Pass/finding/completion boundary unchanged</li>',
        '<li>RUN-097/R: historical 23 route/controller-only owners · 0 page credit</li><li>RUN-098/R: historical 23 route rows + 23 action bridges · 571 cumulative owner records</li><li>RUN-099–100: historical reporting refresh and exact superseded dashboard verification</li><li>RUN-101/R: 24 outcome-neutral candidates · 21 owners · 3 aliases · 0 shared/dead/gap · 0 page credit</li><li>RUN-102/R: 21 route rows + 21 action bridges integrated and independently verified · 3 aliases preserved as non-owners · $static_owner_records cumulative owner records</li><li>RUN-103: deterministic outcome-neutral reporting refresh · matrix and every Site/permission/privacy/direct-object/lifecycle/execution/benchmark/Pass/finding/completion boundary unchanged</li>',
        "evidence wave list",
    )
    text = replace_once_or_present(
        text,
        '<tr><td>RUN-098/R current bounded route/action ownership</td><td>$static_owner_records records · $static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs · $static_action_bridges action bridges</td><td class="partial">$ownership_percent% of bounded 3,929 records · $static_residual residual · zero page additions · Gate 4 incomplete · matrix unchanged</td></tr>',
        '<tr><td>RUN-098/R historical bounded route/action ownership</td><td>571 records · 244 route + 327 page · 246 FEATURE-IDs · 32 action bridges</td><td class="partial">14.532960% · 3,358 residual · historical bounded checkpoint</td></tr><tr><td>RUN-102/R current outcome-neutral route/action ownership</td><td>$static_owner_records records · $static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs · $static_action_bridges action bridges</td><td class="partial">$ownership_percent% of bounded 3,929 records · $static_residual residual · 21 owner / 3 alias / zero page delta · Gate 4 incomplete · matrix unchanged</td></tr>',
        "static census current overlay row",
    )
    text = replace_exact_count(
        text,
        'current overlay: 33 owned · 2 shared · $queue_pending unreviewed · $queue_without_owner without ownership',
        'current overlay: $queue_owner owned · $queue_shared shared · $queue_alias alias · $queue_pending unreviewed · $queue_without_owner without ownership',
        2,
        "queue template counts",
    )
    text = replace_once_or_present(
        text,
        'RUN-098/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges with zero page additions; complete the framework-expanded canonical route/page denominator, $static_residual residual records including 5 shared routes and 2 shared pages, the full crosswalk, and route reachability before Gate 4 can close',
        'RUN-102/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges with 3 reviewed aliases and zero page additions; complete the framework-expanded canonical route/page denominator, $static_residual residual records including 5 shared routes, 3 alias routes, and 2 shared pages, the full crosswalk, and route reachability before Gate 4 can close',
        "Gate 4 current overlay",
    )
    text = replace_once_or_present(
        text,
        '<section class="panel"><h2>Prior audit-dashboard verification</h2><p>RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, and RUN-094 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-099.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-formal-upstream-wave-03.json">Superseded RUN-070 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-072-wave-04.json">Superseded RUN-072 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-073-wave-05.json">Superseded RUN-073 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-076-wave-06.json">Superseded RUN-076 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-081-wave-07.json">Superseded RUN-081 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-083-wave-08.json">Superseded RUN-083 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-085-wave-09.json">Superseded RUN-085 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-088-wave-10.json">Superseded RUN-088 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-094-wave-11.json">Superseded RUN-094 verification GO</a></li></ul></section>',
        '<section class="panel"><h2>Prior audit-dashboard verification</h2><p>RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, RUN-094, and RUN-100 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-103.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-formal-upstream-wave-03.json">Superseded RUN-070 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-072-wave-04.json">Superseded RUN-072 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-073-wave-05.json">Superseded RUN-073 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-076-wave-06.json">Superseded RUN-076 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-081-wave-07.json">Superseded RUN-081 verification</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-083-wave-08.json">Superseded RUN-083 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-085-wave-09.json">Superseded RUN-085 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-088-wave-10.json">Superseded RUN-088 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-094-wave-11.json">Superseded RUN-094 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-100-wave-12.json">Superseded RUN-100 verification GO</a></li></ul></section>',
        "prior dashboard verification",
    )
    text = replace_once_or_present(
        text,
        '<section class="panel"><h2>Fresh RUN-100 audit-dashboard verification</h2><p>The exact regenerated RUN-099 dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-100 receipt records page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible ownership/queue/no-page and zero-credit boundaries, and exact dashboard/generator/reporting hashes. It verifies the audit artifact only and grants no application-browser, responsive, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-100-wave-12.json">RUN-100 responsive audit-dashboard verification receipt</a></li></ul></section>',
        '<section class="panel"><h2>Fresh RUN-104 audit-dashboard verification</h2><p>The exact regenerated RUN-103 dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-104 receipt records page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 21-owner/3-alias/no-page and zero-credit boundaries, and exact dashboard/generator/reporting hashes. It verifies the audit artifact only and grants no application-browser, responsive, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-104-wave-13.json">RUN-104 responsive audit-dashboard verification receipt</a></li></ul></section>',
        "fresh dashboard verification",
    )

    current_bindings = {
        '    static_owner_records=reviewed_route_controller_overlay["combined_counts"]["source_owner_records"],\n':
            '    static_owner_records=reviewed_outcome_neutral_overlay["combined_counts"]["source_owner_records"],\n',
        '    static_owner_routes=reviewed_route_controller_overlay["combined_counts"]["route_owner_records"],\n':
            '    static_owner_routes=reviewed_outcome_neutral_overlay["combined_counts"]["route_owner_records"],\n',
        '    static_owner_pages=reviewed_route_controller_overlay["combined_counts"]["page_owner_records"],\n':
            '    static_owner_pages=reviewed_outcome_neutral_overlay["combined_counts"]["page_owner_records"],\n',
        '    static_owner_features=reviewed_route_controller_overlay["combined_counts"]["distinct_feature_ids"],\n':
            '    static_owner_features=reviewed_outcome_neutral_overlay["combined_counts"]["distinct_feature_ids"],\n',
        '    static_action_bridges=reviewed_route_controller_overlay["combined_counts"]["static_controller_action_bridges"],\n':
            '    static_action_bridges=reviewed_outcome_neutral_overlay["combined_counts"]["static_controller_action_bridges"],\n',
        "    static_residual=f\"{reviewed_route_controller_overlay['combined_counts']['bounded_static_source_residual_records']:,}\",\n":
            "    static_residual=f\"{reviewed_outcome_neutral_overlay['combined_counts']['bounded_static_source_residual_records']:,}\",\n",
        '    ownership_percent=reviewed_route_controller_overlay["combined_counts"]["bounded_static_source_ownership_percent"],\n':
            '    ownership_percent=reviewed_outcome_neutral_overlay["combined_counts"]["bounded_static_source_ownership_percent"],\n',
        '    queue_records=reviewed_route_controller_overlay["queue_accounting"]["direct_exact_queue_records"],\n':
            '    queue_records=reviewed_outcome_neutral_overlay["queue_accounting"]["direct_exact_queue_records"],\n',
        '    queue_pending=reviewed_route_controller_overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"],\n':
            '    queue_pending=reviewed_outcome_neutral_overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"],\n',
        '    queue_without_owner=reviewed_route_controller_overlay["queue_accounting"]["queue_surfaces_without_ownership"],\n':
            '    queue_without_owner=reviewed_outcome_neutral_overlay["queue_accounting"]["queue_surfaces_without_ownership"],\n',
    }
    for old, new in current_bindings.items():
        text = replace_once_or_present(text, old, new, "current RUN-102 dashboard binding")
    binding_marker = '    queue_without_owner=reviewed_outcome_neutral_overlay["queue_accounting"]["queue_surfaces_without_ownership"],\n'
    binding_addition = binding_marker + (
        '    queue_owner=reviewed_outcome_neutral_overlay["queue_accounting"]["owner_queue_surface_rows"],\n'
        '    queue_shared=reviewed_outcome_neutral_overlay["queue_accounting"]["shared_queue_surface_rows"],\n'
        '    queue_alias=reviewed_outcome_neutral_overlay["queue_accounting"]["alias_queue_surface_rows"],\n'
    )
    text = replace_once_or_present(text, binding_marker, binding_addition, "queue bindings")
    write_lf(relative, text)


def main() -> None:
    overlay, review = assert_inputs()
    patch_reports()
    patch_findings(overlay, review)
    patch_dashboard_generator()

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
        "schema_version": "run-103-reviewed-outcome-neutral-route-action-reporting-wave-13-v1",
        "run_id": "RUN-103-REVIEWED-OUTCOME-NEUTRAL-ROUTE-ACTION-REPORTING-WAVE-13",
        "status": "REVIEWED_OUTCOME_NEUTRAL_ROUTE_ACTION_OVERLAY_REPORTED_GATE_4_INCOMPLETE",
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
            "materializer_sha256": sha256_file("generators/materialize-run-103-reviewed-outcome-neutral-route-action-reporting-wave-13.py"),
            "overlay_sha256": PINNED_INPUTS["evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json"],
            "independent_overlay_review_sha256": PINNED_INPUTS["evidence/source/raw-run-102r-independent-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json"],
            "superseded_dashboard_verification_sha256": PINNED_INPUTS["evidence/browser/current-audit-dashboard-verification-run-100-wave-12.json"],
        },
        "inputs": {**CURRENT_REPORT_INPUTS, **PINNED_INPUTS},
        "outputs": outputs,
        "preserved": PRESERVED,
        "counts": {
            **overlay["combined_counts"],
            **overlay["queue_accounting"],
            "reviewed_route_actions": 24,
            "reviewed_owner_route_actions_added": 21,
            "reviewed_alias_or_redirect": 3,
            "route_owner_records_added": 21,
            "controller_action_bridges_added": 21,
            "page_owner_records_added": 0,
            "provisional_finding_records": 12,
            "final_findings": 0,
            "matrix_rows_changed": 0,
            "matrix_cells_changed": 0,
        },
        "checks": {
            "three_part_run_101r_review_go": True,
            "run_101r_owner_route_actions": 21,
            "run_101r_alias_or_redirect": 3,
            "three_part_run_102r_overlay_review_go": True,
            "independent_review_discrepancies": 0,
            "route_owner_records_added": 21,
            "controller_action_bridges_added": 21,
            "reviewed_alias_records_preserved": 3,
            "page_owner_records_added": 0,
            "historical_run_086_run_092_and_run_098_counts_preserved_separately": True,
            "wholesale_queue_ownership_rejected": True,
            "matrix_byte_identical": True,
            "provisional_finding_record_semantics_preserved": True,
            "application_source_paths_written": 0,
            "single_tenant_multi_site_architecture_preserved": True,
            "dashboard_requires_fresh_run_104_artifact_verification": True,
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
        "aliases": receipt["counts"]["reviewed_alias_or_redirect"],
        "gate_4_complete": receipt["checks"]["gate_4_complete"],
    }, indent=2))


if __name__ == "__main__":
    main()
