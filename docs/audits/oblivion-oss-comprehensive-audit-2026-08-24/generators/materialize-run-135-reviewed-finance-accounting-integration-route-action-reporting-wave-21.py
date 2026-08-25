#!/usr/bin/env python3
"""Report the independently verified RUN-134 accounting-integration overlay.

Only five current reporting surfaces are updated. Reports 02-12, inventory,
the 340-row matrix, application source, tests, and the currently verified
RUN-131 dashboard remain byte-identical. The regenerated dashboard requires a
fresh RUN-136 audit-artifact receipt.
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
    "generators/materialize-run-135-reviewed-finance-accounting-integration-"
    "route-action-reporting-wave-21.py"
)
OUTPUT_RELATIVE = (
    "evidence/source/current-run-135-reviewed-finance-accounting-integration-"
    "route-action-reporting-wave-21.json"
)
SCHEMA_VERSION = (
    "run-135-reviewed-finance-accounting-integration-route-action-reporting-wave-21-v1"
)
RUN_ID = (
    "RUN-135-REVIEWED-FINANCE-ACCOUNTING-INTEGRATION-ROUTE-ACTION-REPORTING-WAVE-21"
)

CHECKPOINT_COMMIT = "9e95e31f17747ca307dc9137cfacc1a11c48e6fb"
CHECKPOINT_TREE = "f5032aed2f0f34a2d1a9c0a25df270ac79edf85a"
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
CURRENT_DASHBOARD_SHA256 = "4cbc516cdac0d7d4d2ff499a2f4cdc44baafb72de6a1256adf249b44bdebb89f"
KNOWN_ITERATION_MATERIALIZER_SHA256S = {
    "e6cdb31b5e5e62cdfc1398b1c242b50e65c8dd0eb8fb41a43dbdef302d1a9e4a",
    "5b5da12e26b66fd1361dc1553afba0163f69f9d71a953275edd4a97441e3bc88",
}

CURRENT_REPORT_INPUTS = {
    "00-executive-summary.md": "633c98e5b6857fe60b8f59ad8d27878b1b86ce9fd83a33458f989c1764d1f731",
    "01-repository-module-map.md": "7908307b07ad861a2b23b6cafd6798477711b8ec8d40a920c7aeb51f1282c9f9",
    "13-unresolved-questions-and-evidence-gaps.md": "2e2a4be80aa1ba6b0c9a11a7c90eea17942a527dd9f35347b6aa66899f4ba2c5",
    "findings.json": "153fe6e3b4e7190ebf0f1a1aa8010a53a5c11f7ac0442ada4bca062523110289",
    "generators/build-current-audit-dashboard.py": "075d263989028297b4c35aea252f32ce1041f8bb08db53e45eff1fee8c64245e",
}

PINNED_INPUTS = {
    "generators/materialize-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.py": "1fef8770c67099440468c12a2bd310f202f6d42c58d67e6586ce63cb49194e4f",
    "evidence/source/current-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.json": "191d428161b0f96758bf4ca32d968d87cd9efb1e0a4e9fdd26741f8952063099",
    "evidence/browser/current-audit-dashboard-verification-run-132-wave-20.json": "c6b8991bd63628bc9dc34bd458067cd89cb612cbb8096f2c9f5fa7792d5c3014",
    "generators/build-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.py": "476966a02322f59f385fb59dc9a55a3774e868e512cb58d5f0606698cbfd08af",
    "evidence/source/root-run-133-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.json": "58d87fa101e4e1b51d232baf80e1a2e3ef75dad89f37dc0dcd615c2f16e29ce4",
    "generators/materialize-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21.py": "f878b16d485ff802d9ca5fd51bbd82628d37efed3151eaadcc72ed777ad5783d",
    "evidence/source/raw-run-133r-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21.json": "7b56e738132dad35a0273b764d7f5e401219d6d52394306b41d2afac3a821420",
    "generators/integrate-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.py": "dec764ee611f3dd3bcc21484a04aab1773332dfec1e6cfec547f7abb4f2c56db",
    "evidence/source/current-run-134-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json": "e82514d96ac01db1cba72e9a469b2bb9c15404d2c42ff124c816e38b086bb669",
    "generators/materialize-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-review-wave-21.py": "773bdf925ae3ae4a3d4aafc3767b50347fca8fcfa4e761337a4f5584aecd78c3",
    "evidence/source/raw-run-134r-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json": "da3107cdcbb4ab286c208f85d994676d00f933d4002a966fb89773f8ef0857d3",
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
        "evidence/source/current-run-134-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json"
    )
    review = read_json(
        "evidence/source/raw-run-134r-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json"
    )
    cohort = read_json(
        "evidence/source/root-run-133-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.json"
    )
    semantic_review = read_json(
        "evidence/source/raw-run-133r-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21.json"
    )
    counts = overlay["combined_counts"]
    queue = overlay["queue_accounting"]
    assert (counts["source_owner_records"], counts["route_owner_records"], counts["page_owner_records"]) == (660, 303, 357)
    assert (counts["static_controller_action_bridges"], counts["bounded_static_source_residual_records"]) == (91, 3269)
    assert counts["bounded_static_source_ownership_percent"] == "16.798167"
    assert (counts["residual_explicit_unmapped_routes"], counts["semantic_shared_routes"], counts["reviewed_alias_routes"]) == (2898, 12, 5)
    assert (queue["reviewed_queue_surface_rows"], queue["pending_unreviewed_queue_surface_rows"]) == (114, 393)
    assert queue["queue_surfaces_without_ownership"] == 415
    assert cohort["counts"]["candidate_route_actions"] == 6
    assert cohort["counts"]["ownership_credit_awarded"] == 0
    assert semantic_review["decision"]["verdict"] == "GO_6_EXPLICIT_OWNER_ROUTE_ACTION"
    assert semantic_review["decision"]["current_overlay_credit_awarded"] is False
    assert review["decision"]["verdict"] == "GO"
    assert review["decision"]["reporting_materialization_authorized"] is True
    assert review["decision"]["gate_4_complete"] is False
    assert review["verified_identity"] == overlay["identity"]
    assert len(review["verified_identity"]) == 40
    assert overlay["source_packet_expansion_preservation"]["total_disclosed_expansion_entries"] == 6
    assert overlay["source_packet_expansion_preservation"]["widened_existing_packet_files"] == 2
    assert overlay["source_packet_expansion_preservation"]["newly_followed_files"] == 4
    assert overlay["assurance_findings_preservation"]["candidate_findings"] == 15
    assert overlay["assurance_findings_preservation"]["shared_findings"] == 7
    assert overlay["assurance_findings_preservation"]["total_findings"] == 22
    assert {key for key, value in overlay["credit_boundary"].items() if value} == {
        "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_6_RECORDS",
        "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_6_ACTIONS",
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
    summary_relative = "00-executive-summary.md"
    summary = path(summary_relative).read_text(encoding="utf-8")
    summary_block = """## RUN-113–135 reviewed route/action and page-ownership lineage

RUN-113/R–132 remain historical reviewed route/action, page-owner, reporting, and exact audit-dashboard checkpoints. RUN-129/R–132 preserve the two Finance FX revaluation route/action owners and the zero-page-inheritance boundary.

RUN-133 freezes exactly six still-pending accounting-integration actions without pre-awarding ownership: integrations.store, integrations.update, integrations.test, integrations.destroy, integrations.mapping, and integrations.mapping.update. Three fresh partition reviewers and an independent synthesis reviewer trace their exact RUN-090/RUN-077 route identities, controller methods, page context, provider and mapping semantics, source-packet expansions, and assurance risks. RUN-133R classifies all six as explicit OWNER_ROUTE_ACTION records for CAP-FIN-ACCOUNTING-INTEGRATION-CONFIGURATION; the already-owned Index and Mapping pages, the already-reviewed index route, and the backend-only sync route confer no inherited ownership.

RUN-134 integrates exactly those six route owners and six controller-action bridges. RUN-134R independently verifies the committed bytes, all 40 identities, six source-packet expansions (two widened existing files plus four newly followed files), 22 assurance findings (15 candidate plus seven shared), accounting, denominators, lineage, and zero-credit boundaries. Provider tenant_id is external-provider context, not application tenancy. This static ownership does not establish approved-Site access, exact permissions, privacy, direct-object concealment, provider or mapping behaviour, lifecycle, concurrency, durability, runtime, or release correctness.

The current bounded checkpoint is **660 records = 303 routes + 357 pages across 256 canonical FEATURE-IDs (234 H + 22 D)**, with 91 controller-action bridges. Route and page owners span 62 and 242 FEATURE-IDs with 48 in their overlap. This is 16.798167% of the bounded 3,929-record source universe; 3,269 records remain. The route universe is **3,218 = 303 owners + 12 shared + 5 aliases + 2,898 residual**, with seven evidence gaps tagged inside that residual. The page universe remains **711 = 357 owners + 9 shared + 345 residual**, with one earlier evidence gap tagged inside its residual. Queue accounting is **507 = 114 reviewed + 393 pending**; reviewed rows are 92 owned, 10 shared, 5 aliases, 0 dead, and 7 evidence gaps, while 415 remain without ownership.

RUN-135 reports only that bounded six-action delta. The exact regenerated dashboard requires a fresh RUN-136 audit-artifact receipt. Oblivion Findings remains one operating organisation across multiple Sites. Framework reachability, navigation, approved-Site access, roles/permissions, canonical object ownership, direct-object concealment, privacy, provider/mapping/lifecycle/concurrency/durability correctness, runtime, database, build, application browser, tests, benchmarks, ease, Passes, findings, completion, and Gate 4 remain separate open or zero-credit gates. The 340-row matrix remains byte-identical and mapping remains 0/340.

"""
    summary = replace_between(
        summary,
        "## RUN-113–131 reviewed route/action and page-ownership lineage\n",
        "## Current raw source census\n",
        summary_block,
        "summary RUN135 block",
    )
    evidence_anchor = "- generators/materialize-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.py and evidence/source/current-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.json: deterministic RUN-131 reporting refresh preserving the matrix, reports 02–12/inventory, and every downstream zero-credit boundary.\n"
    # The source line uses Markdown code quoting; construct it without embedding
    # literal backticks in this materializer source.
    tick = chr(96)
    evidence_anchor = (
        f"- {tick}generators/materialize-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.py{tick} "
        f"and {tick}evidence/source/current-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.json{tick}: "
        "deterministic RUN-131 reporting refresh preserving the matrix, reports 02–12/inventory, and every downstream zero-credit boundary.\n"
    )
    links = [
        ("evidence/browser/current-audit-dashboard-verification-run-132-wave-20.json", "exact now-superseded RUN-131 dashboard artifact verification at four viewports; zero application credit"),
        ("generators/build-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.py and evidence/source/root-run-133-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.json", "exact zero-credit six-action accounting-integration review cohort"),
        ("generators/materialize-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21.py and evidence/source/raw-run-133r-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21.json", "three-part fresh semantic review plus synthesis, with six disclosed expansions, 22 assurance findings, and no correctness credit"),
        ("generators/integrate-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.py and evidence/source/current-run-134-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json", "exact six-route and six-bridge static-only overlay"),
        ("generators/materialize-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-review-wave-21.py and evidence/source/raw-run-134r-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json", "three-part final-byte, identity, accounting, provenance, and boundary GO receipt"),
        (f"{MATERIALIZER_RELATIVE} and {OUTPUT_RELATIVE}", "deterministic RUN-135 reporting refresh preserving the matrix, reports 02–12/inventory, and every downstream zero-credit boundary"),
    ]
    additions = evidence_anchor
    for paths, description in links:
        rendered = " and ".join(f"{tick}{item}{tick}" for item in paths.split(" and "))
        additions += f"- {rendered}: {description}.\n"
    summary = replace_once(summary, evidence_anchor, additions, "summary RUN135 links")
    write_lf(summary_relative, summary)

    module_relative = "01-repository-module-map.md"
    module_map = path(module_relative).read_text(encoding="utf-8")
    module_block = """## RUN-113–135 reviewed route/action and page-ownership lineage

RUN-113/R–132 remain historical reviewed route/action, page-owner, reporting, and exact-dashboard checkpoints. RUN-129/R–132 preserve the two FX revaluation route owners and the zero-page-inheritance boundary.

RUN-133/R separately review six pending accounting-integration routes as explicit route/action owners for CAP-FIN-ACCOUNTING-INTEGRATION-CONFIGURATION: store, update, testConnection, destroy, mapping, and updateMapping. RUN-134/R integrate and independently verify exactly six route records and six controller-action bridges with zero page, sibling-index, backend-sync, feature-union, or matrix change.

The cumulative bounded ledger is 660 source owners (303 route + 357 page) across 256 FEATURE-IDs (234 H + 22 D). Route/page feature sets are 62/242 with overlap 48, and the action-bridge count is 91. Route accounting is 3,218 = 303 owners + 12 shared + 5 aliases + 2,898 residual, with seven evidence gaps tagged within residual. Page accounting remains 711 = 357 owners + 9 shared + 345 residual, with one earlier tagged evidence gap. RUN-090 queue accounting is 507 total, 114 reviewed, 92 owned, 10 shared, 5 aliases, 7 evidence gaps, 393 pending, and 415 without ownership.

These relations establish bounded static ownership only. The six packet expansions and 22 assurance findings leave unproved approved-Site, permission, privacy, direct-object, provider, mapping, lifecycle, concurrency, and durability correctness, framework reachability, runtime, build, browser, tests, benchmarks, findings, Passes, and completion. Provider tenant_id is external-provider context, not application tenancy.

"""
    module_map = replace_between(
        module_map,
        "## RUN-113–131 reviewed route/action and page-ownership lineage\n",
        "## Candidate register\n",
        module_block,
        "module RUN135 block",
    )
    write_lf(module_relative, module_map)

    gaps_relative = "13-unresolved-questions-and-evidence-gaps.md"
    gaps = path(gaps_relative).read_text(encoding="utf-8")
    gaps = replace_line_containing(
        gaps,
        "| Required reporting paths |",
        "| Required reporting paths | 18 / 18 prompt-required files or directories present. RUN-132 independently verified the exact now-superseded RUN-131 dashboard at four viewports; the regenerated RUN-135 dashboard requires a separate fresh RUN-136 audit-artifact receipt. | Presence and prior audit-artifact verification make the reporting contract inspectable but grant no application execution, final-finding, Pass, or completion credit. Gate 26 remains open. | Complete the semantic, execution, benchmark, Pass 8, final reconciliation, and no-live-agent gates; repeat audit-dashboard verification after every later HTML change. |",
    )
    gaps = replace_line_containing(
        gaps,
        "| Runtime routes |",
        "| Runtime routes | RUN-134/R preserve 303 bounded route-owner records and 91 static controller-action bridges; 2,898 residual route rows, 12 semantic-shared route rows, and 5 reviewed aliases remain distinguished within the bounded 3,218-row static route-like universe, with 7 evidence gaps tagged inside residual. | Wave 21 adds exactly six reviewed accounting-integration route owners and six bridges. Static owner/action linkage is not a framework-expanded route table, reachability proof, approved-Site/permission/privacy/direct-object proof, provider/mapping/lifecycle/concurrency/durability proof, or authorization proof. | Under a fresh bounded runtime grant, use an exact disposable database, execute a separately pinned framework/provider route lane, and reconcile it to all 38 source route files and 3,218 route-like rows. |",
    )
    gaps = replace_line_containing(
        gaps,
        "| Inertia pages |",
        "| Inertia pages | RUN-084/R enumerate 1,058 physical page-tree files. RUN-134/R preserve 357 bounded page owners, nine semantic-shared roots, and 345 residual roots including one earlier tagged evidence gap. | Wave 21 adds zero page owner and inherits no page, sibling-index, or backend-sync ownership. Full-tree structural GO and bounded ownership are not a complete canonical crosswalk, runtime reachability, build resolution, rendered browser behaviour, or final feature mapping. | Reconcile all 711 literal roots and support relations to safely expanded framework routes and frozen FEATURE-IDs, prove build resolution, and observe every safely reachable current-build page under approved roles/Sites. |",
    )
    gaps = replace_line_containing(
        gaps,
        "| Canonical features |",
        f"| Canonical features | Static canonical identity remains 340 targets: 300 H, 40 D, zero M; RUN-134/R establish 660 bounded source-owner records (303 routes + 357 pages) across 256 FEATURE-IDs (234 H + 22 D) plus 91 controller-action bridges while the matrix remains byte-identical at {tick}{MATRIX_SHA256}{tick}. | This is 16.798167% of the bounded 3,929-record source universe. Gate 4 remains incomplete: 3,269 non-owner records, the framework-expanded denominator, shared, alias, and gap relations, reachability, and the full crosswalk remain open. The 22 accounting-integration assurance findings grant no final-finding credit; matrix target mapping stays 0/340. | Finish canonical denominator and residual ownership adjudication without inheriting FEATURE-IDs from prefixes, imports, containment, names, siblings, or presence, and without awarding runtime, browser, test, benchmark, ease, release, or completion credit. |",
    )
    gaps = replace_line_containing(
        gaps,
        "| Agent universe and writer rule |",
        "| Agent universe and writer rule | RUN-001 through RUN-135 represented at the current reporting checkpoint; finalization gate false. | RUN-133/R review six accounting-integration actions as six explicit owners; RUN-134/R independently integrate and verify only six route owners and six bridges while preserving six packet expansions, 22 assurance findings, provider tenant_id as external context, and every correctness boundary; RUN-135 reports only that bounded class. Runtime, browser, tests, benchmarks, Pass 8, finalization, and completion remain open. | Complete residual ownership and every semantic/execution gate, then dispatch fresh Pass 8/final cross-reviewers, verify the final dashboard, and prove no agent remains live at finalization. |",
    )
    lineage = """## RUN-077–135 route/page, page-tree, backend, ownership and reporting lineage

RUN-077 freezes the exhaustive committed-source route/name/page universe through RUN-084B's page-tree and backend structural ledgers. RUN-086/R establish the initial 530 bounded source owners; RUN-090 freezes the zero-credit direct-exact queue; RUN-091/R–128 successively review, integrate, report, and verify bounded route/action and page ownership, reaching 652 owners while preserving explicit shared, alias, and gap outcomes. RUN-129/R review and RUN-130/R integrate two FX revaluation route/action owners; RUN-131 reports that delta and RUN-132 verifies its now-superseded dashboard. RUN-133/R review six pending accounting-integration actions as six explicit route/action owners. RUN-134/R integrate and independently verify exactly six route owners and six controller-action bridges, preserve six source-packet expansions and 22 assurance findings without correctness credit, and reach 660 owners; RUN-135 reports only that bounded delta. Gate 4 and the full route/page/backend crosswalk remain incomplete; Oblivion Findings remains one operating organisation across multiple Sites, provider tenant_id remains external-provider context, and framework reachability, approved-Site/permission/privacy/direct-object/provider/mapping/lifecycle/concurrency/durability correctness, runtime, build, signed-in application browser, executed tests, benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit.

"""
    gaps = replace_between(
        gaps,
        "## RUN-077–131 route/page, page-tree, backend, ownership and reporting lineage\n",
        "## Current provisional source findings\n",
        lineage,
        "gaps RUN135 lineage",
    )
    write_lf(gaps_relative, gaps)


def patch_findings(overlay: dict[str, Any], review: dict[str, Any]) -> None:
    relative = "findings.json"
    findings = read_json(relative)
    counts = overlay["combined_counts"]
    queue = overlay["queue_accounting"]
    findings["pins"].update(
        {
            "run_131_reporting_sha256": PINNED_INPUTS[
                "evidence/source/current-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.json"
            ],
            "run_132_dashboard_verification_sha256": PINNED_INPUTS[
                "evidence/browser/current-audit-dashboard-verification-run-132-wave-20.json"
            ],
            "run_133_finance_accounting_integration_cohort_generator_sha256": PINNED_INPUTS[
                "generators/build-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.py"
            ],
            "run_133_finance_accounting_integration_cohort_sha256": PINNED_INPUTS[
                "evidence/source/root-run-133-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.json"
            ],
            "run_133r_finance_accounting_integration_review_materializer_sha256": PINNED_INPUTS[
                "generators/materialize-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21.py"
            ],
            "run_133r_finance_accounting_integration_review_sha256": PINNED_INPUTS[
                "evidence/source/raw-run-133r-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21.json"
            ],
            "run_134_finance_accounting_integration_overlay_generator_sha256": PINNED_INPUTS[
                "generators/integrate-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.py"
            ],
            "run_134_finance_accounting_integration_overlay_sha256": PINNED_INPUTS[
                "evidence/source/current-run-134-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json"
            ],
            "run_134r_finance_accounting_integration_overlay_review_materializer_sha256": PINNED_INPUTS[
                "generators/materialize-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-review-wave-21.py"
            ],
            "run_134r_finance_accounting_integration_overlay_review_sha256": PINNED_INPUTS[
                "evidence/source/raw-run-134r-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json"
            ],
            "run_135_reporting_materializer_sha256": sha256_file(MATERIALIZER_RELATIVE),
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

    if "historical_run_130_outcome_neutral_finance_fx_revaluation_route_action_ownership" not in findings:
        findings[
            "historical_run_130_outcome_neutral_finance_fx_revaluation_route_action_ownership"
        ] = copy.deepcopy(findings["current_static_source_feature_ownership"])
    if "historical_run_130_outcome_neutral_finance_fx_revaluation_route_action_ownership_review" not in findings:
        findings[
            "historical_run_130_outcome_neutral_finance_fx_revaluation_route_action_ownership_review"
        ] = copy.deepcopy(
            findings[
                "current_outcome_neutral_finance_fx_revaluation_route_action_ownership_review"
            ]
        )
    findings.pop(
        "current_outcome_neutral_finance_fx_revaluation_route_action_ownership_review",
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
        "status": "GO_REVIEWED_BOUNDED_OUTCOME_NEUTRAL_FINANCE_ACCOUNTING_INTEGRATION_ROUTE_ACTION_OWNERSHIP_ONLY",
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
        "ownership_basis": "FRESH_COMPLETE_ACTION_REVIEW_NOT_PAGE_SIBLING_INDEX_BACKEND_SYNC_OR_CALLER_INHERITANCE",
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
        "current_outcome_neutral_finance_accounting_integration_route_action_ownership_review"
    ] = {
        "run_id": review["run_id"],
        "status": review["status"],
        "reviewers": len(review["reviewers"]),
        "mechanical_explicit_checks": review["decision"]["mechanical_explicit_checks_reported"],
        "mechanical_generator_assertion_evaluations": review["decision"][
            "mechanical_generator_assertion_evaluations_reported"
        ],
        "semantic_logical_assertions": review["decision"]["semantic_logical_assertions_reported"],
        "semantic_source_loci": review["decision"]["semantic_source_loci_reported"],
        "semantic_source_files": review["decision"]["semantic_source_files_reported"],
        "route_owner_records_verified": review["decision"]["route_owner_records_verified"],
        "controller_action_bridges_verified": review["decision"][
            "controller_action_bridges_verified"
        ],
        "page_owner_records_verified": review["decision"]["page_owner_records_verified"],
        "source_packet_expansion_records_verified": review["decision"][
            "source_packet_expansion_records_verified"
        ],
        "assurance_findings_verified": review["decision"]["assurance_findings_verified"],
        "published_identity_fields_verified": review["decision"][
            "published_identity_fields_verified"
        ],
        "mechanical_discrepancies": review["decision"]["mechanical_discrepancies"],
        "semantic_or_preservation_discrepancies": review["decision"][
            "semantic_or_preservation_discrepancies"
        ],
        "arithmetic_identity_or_denominator_discrepancies": review["decision"][
            "arithmetic_identity_or_denominator_discrepancies"
        ],
        "byte_provenance_or_credit_discrepancies": review["decision"][
            "byte_provenance_or_credit_discrepancies"
        ],
        "reporting_materialization_authorized": review["decision"][
            "reporting_materialization_authorized"
        ],
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

    run132 = read_json(
        "evidence/browser/current-audit-dashboard-verification-run-132-wave-20.json"
    )
    verification = run132["verification"]
    findings["current_audit_artifact_verification_history"]["run_132"] = {
        "status": run132["status"],
        "dashboard_sha256": run132["pins"]["dashboard_html_sha256"],
        "receipt_sha256": PINNED_INPUTS[
            "evidence/browser/current-audit-dashboard-verification-run-132-wave-20.json"
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
    run135_materializer_sha256 = sha256_file(MATERIALIZER_RELATIVE)

    read_anchor = (
        'reviewed_finance_fx_revaluation_overlay_review = read_json('
        '"evidence/source/raw-run-130r-independent-reviewed-outcome-neutral-finance-'
        'fx-revaluation-route-action-ownership-overlay-wave-20.json")\n'
    )
    read_addition = read_anchor + (
        'finance_accounting_integration_cohort = read_json("evidence/source/root-run-133-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.json")\n'
        'finance_accounting_integration_review = read_json("evidence/source/raw-run-133r-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21.json")\n'
        'reviewed_finance_accounting_integration_overlay = read_json("evidence/source/current-run-134-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json")\n'
        'reviewed_finance_accounting_integration_overlay_review = read_json("evidence/source/raw-run-134r-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json")\n'
    )
    text = replace_once(
        text, read_anchor, read_addition, "dashboard RUN133-134 reads"
    )

    assertion_anchor = "\ncandidates ="
    assertion_block = f"""
assert sha256_file("generators/materialize-run-135-reviewed-finance-accounting-integration-route-action-reporting-wave-21.py") == "{run135_materializer_sha256}"
assert sha256_file("evidence/source/current-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.json") == "191d428161b0f96758bf4ca32d968d87cd9efb1e0a4e9fdd26741f8952063099"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-132-wave-20.json") == "c6b8991bd63628bc9dc34bd458067cd89cb612cbb8096f2c9f5fa7792d5c3014"
assert sha256_file("generators/build-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.py") == "476966a02322f59f385fb59dc9a55a3774e868e512cb58d5f0606698cbfd08af"
assert sha256_file("evidence/source/root-run-133-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.json") == "58d87fa101e4e1b51d232baf80e1a2e3ef75dad89f37dc0dcd615c2f16e29ce4"
assert sha256_file("generators/materialize-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21.py") == "f878b16d485ff802d9ca5fd51bbd82628d37efed3151eaadcc72ed777ad5783d"
assert sha256_file("evidence/source/raw-run-133r-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21.json") == "7b56e738132dad35a0273b764d7f5e401219d6d52394306b41d2afac3a821420"
assert sha256_file("generators/integrate-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.py") == "dec764ee611f3dd3bcc21484a04aab1773332dfec1e6cfec547f7abb4f2c56db"
assert sha256_file("evidence/source/current-run-134-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json") == "e82514d96ac01db1cba72e9a469b2bb9c15404d2c42ff124c816e38b086bb669"
assert sha256_file("generators/materialize-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-review-wave-21.py") == "773bdf925ae3ae4a3d4aafc3767b50347fca8fcfa4e761337a4f5584aecd78c3"
assert sha256_file("evidence/source/raw-run-134r-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json") == "da3107cdcbb4ab286c208f85d994676d00f933d4002a966fb89773f8ef0857d3"
assert finance_accounting_integration_cohort["counts"]["candidate_route_actions"] == 6
assert finance_accounting_integration_cohort["counts"]["ownership_credit_awarded"] == 0
assert finance_accounting_integration_review["decision"]["verdict"] == "GO_6_EXPLICIT_OWNER_ROUTE_ACTION"
assert finance_accounting_integration_review["decision"]["current_overlay_credit_awarded"] is False
assert reviewed_finance_accounting_integration_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_finance_accounting_integration_overlay_review["decision"]["reporting_materialization_authorized"] is True
assert reviewed_finance_accounting_integration_overlay_review["decision"]["gate_4_complete"] is False
assert reviewed_finance_accounting_integration_overlay_review["verified_identity"] == reviewed_finance_accounting_integration_overlay["identity"]
assert len(reviewed_finance_accounting_integration_overlay_review["verified_identity"]) == 40
assert len(reviewed_finance_accounting_integration_overlay["overlay_source_records"]) == 6
assert len(reviewed_finance_accounting_integration_overlay["new_static_controller_action_bridges"]) == 6
assert reviewed_finance_accounting_integration_overlay["reviewed_non_owner_outcomes"] == []
assert reviewed_finance_accounting_integration_overlay["page_context_boundary"] == {{
    "literal_inertia_page_callsites": 1,
    "existing_caller_or_render_pages": 2,
    "selected_frontend_literal_caller_contexts": 5,
    "selected_routes_without_literal_caller_in_frozen_pages": 1,
    "existing_index_page_record_id": "PAGE-ROOT-679D3E7F4B5402CB",
    "existing_index_page_feature_id": "CAP-FIN-ACCOUNTING-INTEGRATION-CONFIGURATION",
    "existing_mapping_page_record_id": "PAGE-ROOT-BA2E4950746EAF10",
    "existing_mapping_page_feature_id": "CAP-FIN-XERO-ACCOUNTING-SYNC",
    "new_page_owner_records": 0,
    "page_ownership_inherited": False,
    "page_ownership_reassigned": False,
    "rule": "Index and Mapping remain existing page-owner context; no caller or render transfers page ownership.",
}}
accounting_counts = reviewed_finance_accounting_integration_overlay["combined_counts"]
accounting_queue = reviewed_finance_accounting_integration_overlay["queue_accounting"]
assert (accounting_counts["source_owner_records"], accounting_counts["route_owner_records"], accounting_counts["page_owner_records"]) == (660, 303, 357)
assert (accounting_counts["distinct_feature_ids"], accounting_counts["distinct_H_feature_ids"], accounting_counts["distinct_D_feature_ids"]) == (256, 234, 22)
assert (accounting_counts["route_distinct_feature_ids"], accounting_counts["page_distinct_feature_ids"], accounting_counts["route_page_feature_overlap"]) == (62, 242, 48)
assert (accounting_counts["static_controller_action_bridges"], accounting_counts["bounded_static_source_residual_records"]) == (91, 3269)
assert accounting_counts["bounded_static_source_ownership_percent"] == "16.798167"
assert (accounting_counts["residual_explicit_unmapped_routes"], accounting_counts["semantic_shared_routes"], accounting_counts["reviewed_alias_routes"], accounting_counts["evidence_gap_routes_tagged_within_residual"]) == (2898, 12, 5, 7)
assert (accounting_counts["residual_unadjudicated_page_roots"], accounting_counts["semantic_shared_page_roots"], accounting_counts["evidence_gap_page_roots_tagged_within_residual"]) == (345, 9, 1)
assert (accounting_queue["direct_exact_queue_records"], accounting_queue["reviewed_queue_surface_rows"], accounting_queue["owner_queue_surface_rows"], accounting_queue["shared_queue_surface_rows"], accounting_queue["alias_queue_surface_rows"], accounting_queue["dead_queue_surface_rows"], accounting_queue["evidence_gap_queue_surface_rows"], accounting_queue["pending_unreviewed_queue_surface_rows"], accounting_queue["queue_surfaces_without_ownership"]) == (507, 114, 92, 10, 5, 0, 7, 393, 415)
assert (accounting_queue["new_reviewed_route_surface_rows"], accounting_queue["new_owner_route_surface_rows"]) == (6, 6)
assert reviewed_finance_accounting_integration_overlay["source_packet_expansion_preservation"]["total_disclosed_expansion_entries"] == 6
assert reviewed_finance_accounting_integration_overlay["source_packet_expansion_preservation"]["widened_existing_packet_files"] == 2
assert reviewed_finance_accounting_integration_overlay["source_packet_expansion_preservation"]["newly_followed_files"] == 4
assert reviewed_finance_accounting_integration_overlay["assurance_findings_preservation"]["candidate_findings"] == 15
assert reviewed_finance_accounting_integration_overlay["assurance_findings_preservation"]["shared_findings"] == 7
assert reviewed_finance_accounting_integration_overlay["assurance_findings_preservation"]["total_findings"] == 22
assert reviewed_finance_accounting_integration_overlay["projection_reconciliation"]["run133r_projection_credit_awarded"] is False
assert reviewed_finance_accounting_integration_overlay["projection_reconciliation"]["run134_current_static_overlay_credit_applied"] is True
assert reviewed_finance_accounting_integration_overlay["noninheritance_boundary"]["already_reviewed_index_route_record_id"] == "RUN077-ROUTE-0592"
assert reviewed_finance_accounting_integration_overlay["noninheritance_boundary"]["excluded_backend_only_sync_route_record_id"] == "RUN077-ROUTE-0595"
assert reviewed_finance_accounting_integration_overlay["noninheritance_boundary"]["excluded_backend_only_sync_selected"] is False
assert {{key for key, value in reviewed_finance_accounting_integration_overlay["credit_boundary"].items() if value}} == {{"STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_6_RECORDS", "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_6_ACTIONS"}}
assert {{key for key, value in reviewed_finance_accounting_integration_overlay_review["credit_boundary"].items() if value}} == {{"INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"}}
assert 3929 == 660 + 3269
assert 660 == 303 + 357
assert 3218 == 303 + 12 + 5 + 2898
assert 711 == 357 + 9 + 345
"""
    current_assertion_start = (
        'assert sha256_file("generators/materialize-run-135-reviewed-finance-'
        'accounting-integration-route-action-reporting-wave-21.py") == "'
    )
    if current_assertion_start in text:
        text = replace_between(
            text,
            current_assertion_start,
            assertion_anchor,
            assertion_block.lstrip(),
            "dashboard RUN133-135 assertion refresh",
        )
    else:
        text = replace_once(
            text,
            assertion_anchor,
            assertion_block + assertion_anchor,
            "dashboard RUN133-135 assertions",
        )

    evidence_anchor = (
        '    ("RUN-131 FX route/action reporting/hash receipt", '
        '"evidence/source/current-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.json"),\n'
    )
    evidence_addition = evidence_anchor + (
        '    ("RUN-132 verified superseded dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-132-wave-20.json"),\n'
        '    ("RUN-133 accounting-integration route/action cohort generator", "generators/build-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.py"),\n'
        '    ("RUN-133 six-action accounting-integration cohort", "evidence/source/root-run-133-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.json"),\n'
        '    ("RUN-133R accounting-integration semantic-review materializer", "generators/materialize-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21.py"),\n'
        '    ("RUN-133R six-owner accounting-integration action review", "evidence/source/raw-run-133r-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21.json"),\n'
        '    ("RUN-134 accounting-integration route/action overlay generator", "generators/integrate-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.py"),\n'
        '    ("RUN-134 six-route six-bridge accounting-integration overlay", "evidence/source/current-run-134-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json"),\n'
        '    ("RUN-134R independent accounting-integration overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-review-wave-21.py"),\n'
        '    ("RUN-134R final-byte identity accounting and boundary review", "evidence/source/raw-run-134r-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json"),\n'
        '    ("RUN-135 accounting-integration reporting materializer", "generators/materialize-run-135-reviewed-finance-accounting-integration-route-action-reporting-wave-21.py"),\n'
        '    ("RUN-135 accounting-integration reporting/hash receipt", "evidence/source/current-run-135-reviewed-finance-accounting-integration-route-action-reporting-wave-21.json"),\n'
    )
    text = replace_once(
        text, evidence_anchor, evidence_addition, "dashboard RUN132-135 evidence"
    )

    text = replace_once(
        text, 'href="#checkpoint">RUN-131</a>', 'href="#checkpoint">RUN-135</a>', "dashboard nav RUN135"
    )
    text = replace_once(
        text,
        "RUN-071–131 current reporting checkpoint:",
        "RUN-071–135 current reporting checkpoint:",
        "dashboard notice RUN135",
    )
    text = replace_once(
        text,
        "RUN-071–131 completion-gate checkpoint",
        "RUN-071–135 completion-gate checkpoint",
        "dashboard heading RUN135",
    )
    old_overview = (
        "RUN-101/R–128 remain historical route/action, page-owner, reporting, and exact dashboard checkpoints. "
        "RUN-129/R review $finance_fx_wave_reviewed FX revaluation route actions as $finance_fx_review_owner explicit owners; "
        "RUN-130/R establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) "
        "across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges. Exactly two route owners and two bridges are added; "
        "page, feature-union, and matrix counts are unchanged, and all 15 assurance findings retain zero correctness credit."
    )
    new_overview = (
        "RUN-101/R–132 remain historical route/action, page-owner, reporting, and exact dashboard checkpoints. "
        "RUN-133/R review $finance_accounting_wave_reviewed accounting-integration route actions as $finance_accounting_review_owner explicit owners; "
        "RUN-134/R establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) "
        "across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges. Exactly six route owners and six bridges are added; "
        "page, feature-union, and matrix counts are unchanged, and all 22 assurance findings retain zero correctness credit."
    )
    text = replace_once(text, old_overview, new_overview, "dashboard overview RUN135")

    old_checkpoint = (
        "RUN-113/R–128 preserve historical route/action and page-owner checkpoints with exact dashboard receipts. "
        "RUN-129/R–130/R add $finance_fx_review_owner FX route owners and two bridges, inherit no page or sibling ownership, and add zero feature-union or matrix change; "
        "$queue_reviewed queue rows are reviewed, $queue_pending remain pending, and $queue_without_owner remain without ownership. RUN-131 reports only that bounded delta."
    )
    new_checkpoint = (
        "RUN-113/R–132 preserve historical route/action and page-owner checkpoints with exact dashboard receipts. "
        "RUN-133/R–134/R add $finance_accounting_review_owner accounting-integration route owners and six bridges, inherit no page, sibling-index, or backend-sync ownership, and add zero feature-union or matrix change; "
        "$queue_reviewed queue rows are reviewed, $queue_pending remain pending, and $queue_without_owner remain without ownership. RUN-135 reports only that bounded delta."
    )
    text = replace_once(
        text, old_checkpoint, new_checkpoint, "dashboard checkpoint RUN135"
    )

    old_chronology = (
        "RUN-113/R–128 preserve historical reviewed route/action and page-owner checkpoints with exact dashboard receipts; "
        "RUN-129/R–130/R independently review, integrate, and verify two FX revaluation route owners plus two bridges while preserving 15 assurance findings, "
        "zero page/sibling inheritance, and every correctness boundary, and RUN-131 refreshes current reporting."
    )
    new_chronology = (
        "RUN-113/R–132 preserve historical reviewed route/action and page-owner checkpoints with exact dashboard receipts; "
        "RUN-133/R–134/R independently review, integrate, and verify six accounting-integration route owners plus six bridges while preserving six packet expansions, "
        "22 assurance findings, zero page/sibling-index/backend-sync inheritance, and every correctness boundary, and RUN-135 refreshes current reporting."
    )
    text = replace_once(
        text, old_chronology, new_chronology, "dashboard chronology RUN135"
    )

    progress_start = '<tr><td>RUN-121/R → 124 historical Finance route/action overlay'
    progress_end = "</tbody>"
    progress_replacement = (
        '<tr><td>RUN-121/R → 124 historical Finance route/action overlay</td><td><strong>$finance_wave_reviewed reviewed · 7 owner + 7 shared + 1 alias + 7 gap · 7 route rows · 7 bridges</strong></td><td class="partial">648 cumulative owners · exact superseded dashboard verified</td></tr>'
        '<tr><td>RUN-125/R → 128 historical Finance page-gap overlay</td><td><strong>$finance_page_wave_reviewed reviewed = $finance_page_review_owner owner pages · 4 page rows · 0 route/bridge/queue rows</strong></td><td class="partial">652 cumulative owners · exact superseded dashboard verified</td></tr>'
        '<tr><td>RUN-129/R → 132 historical FX revaluation route/action overlay</td><td><strong>$finance_fx_wave_reviewed reviewed = $finance_fx_review_owner owner actions · 2 route rows · 2 bridges · 0 page rows</strong></td><td class="partial">654 cumulative owners · exact superseded dashboard verified</td></tr>'
        '<tr><td>RUN-133/R → 134/R current accounting-integration route/action overlay</td><td><strong>$finance_accounting_wave_reviewed reviewed = $finance_accounting_review_owner owner actions · 6 route rows · 6 bridges · 0 page rows</strong></td><td class="partial">$static_owner_records cumulative owners · $static_owner_routes routes + $static_owner_pages pages · Gate 4 incomplete</td></tr>'
        '<tr><td>RUN-135 reporting refresh</td><td><strong>accounting-integration route/action overlay reported</strong></td><td class="partial">audit-only materialization · matrix byte-identical · fresh RUN-136 verification required</td></tr>'
    )
    text = replace_between(
        text,
        progress_start,
        progress_end,
        progress_replacement,
        "dashboard progress RUN135",
    )
    text = replace_once(
        text,
        "RUN-001 through RUN-131 are represented by audit artifacts",
        "RUN-001 through RUN-135 are represented by audit artifacts",
        "dashboard agent universe RUN135",
    )

    bullet_start = '<li>RUN-121/R–128: historical Finance route/action and page-owner review'
    bullet_end = "</ul>"
    bullet_replacement = (
        '<li>RUN-121/R–132: historical Finance route/action and page-owner review, integration, reporting, and exact superseded dashboard receipts</li>'
        '<li>RUN-133/R: $finance_accounting_wave_reviewed accounting-integration actions · $finance_accounting_review_owner explicit route/action owners · six packet expansions and 22 assurance findings retained without correctness credit</li>'
        '<li>RUN-134/R: six route rows and six bridges integrated and independently verified · zero page/sibling-index/backend-sync inheritance · $static_owner_records cumulative owner records</li>'
        '<li>RUN-135: deterministic accounting-integration reporting refresh · matrix and every Site/permission/privacy/direct-object/provider/mapping/lifecycle/concurrency/durability/execution/benchmark/Pass/finding/completion boundary unchanged</li>'
    )
    text = replace_between(
        text, bullet_start, bullet_end, bullet_replacement, "dashboard bullets RUN135"
    )

    old_timeline = (
        "RUN-113/R–128 preserve historical reviewed route/action and page-owner checkpoints with exact dashboard receipts; "
        "RUN-129/R–130/R add two independently reviewed FX revaluation route owners and two bridges while preserving 15 assurance findings, "
        "zero page/sibling inheritance, and every correctness boundary, and RUN-131 refreshes reporting."
    )
    new_timeline = (
        "RUN-113/R–132 preserve historical reviewed route/action and page-owner checkpoints with exact dashboard receipts; "
        "RUN-133/R–134/R add six independently reviewed accounting-integration route owners and six bridges while preserving six packet expansions, "
        "22 assurance findings, zero page/sibling-index/backend-sync inheritance, and every correctness boundary, and RUN-135 refreshes reporting."
    )
    text = replace_once(
        text, old_timeline, new_timeline, "dashboard timeline RUN135"
    )

    current_start = '<tr><td>RUN-130/R current Finance route/action and page ownership'
    current_end = "</tr>"
    current_row = (
        '<tr><td>RUN-134/R current Finance route/action and page ownership</td>'
        '<td>$static_owner_records = $static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs = $static_owner_h_features H + $static_owner_d_features D · $static_action_bridges action bridges</td>'
        '<td class="partial">$ownership_percent% of bounded 3,929 · $static_residual residual · features $route_feature_ids route / $page_feature_ids page / $route_page_overlap overlap · routes 3,218 = $static_owner_routes owner + $route_shared_current shared + $route_alias_current alias + $route_residual residual with $finance_accounting_route_gap tagged gaps · pages 711 = $static_owner_pages owner + $page_shared shared + $page_residual residual with $page_gap tagged gap · accounting-integration wave $finance_accounting_wave_reviewed = $finance_accounting_review_owner owners · 6 route rows + 6 bridges · page context $finance_accounting_page_calls literal Inertia callsite / $finance_accounting_existing_pages existing caller/render pages / $finance_accounting_frontend_contexts caller contexts / $finance_accounting_no_literal_callers route without a frozen literal caller / $finance_accounting_page_owners_added new owners / inherited=$finance_accounting_page_inherited / reassigned=$finance_accounting_page_reassigned · 6 packet expansions (2 existing + 4 new) · 22 assurance findings (15 + 7) · provider tenant_id external context · zero correctness credit · Gate 4 incomplete · matrix unchanged</td>'
    )
    text = replace_between(
        text, current_start, current_end, current_row, "dashboard current row RUN135"
    )
    old_gap = (
        "RUN-130/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges "
        "while adding two FX route owners and two bridges, preserving 15 assurance findings, inheriting no page or sibling ownership, and adding zero feature-union or matrix change;"
    )
    new_gap = (
        "RUN-134/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges "
        "while adding six accounting-integration route owners and six bridges, preserving six packet expansions and 22 assurance findings, "
        "inheriting no page, sibling-index, or backend-sync ownership, and adding zero feature-union or matrix change;"
    )
    text = replace_once(text, old_gap, new_gap, "dashboard open gate RUN135")

    prior_old = (
        "RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, RUN-094, RUN-100, RUN-104, "
        "RUN-108, RUN-112, RUN-116, RUN-120, RUN-124, and RUN-128 responsive verification are immutable history for their exact superseded HTML; "
        "no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-131."
    )
    prior_new = (
        "RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, RUN-094, RUN-100, RUN-104, "
        "RUN-108, RUN-112, RUN-116, RUN-120, RUN-124, RUN-128, and RUN-132 responsive verification are immutable history for their exact superseded HTML; "
        "no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-135."
    )
    text = replace_once(text, prior_old, prior_new, "dashboard prior RUN132")
    prior_link = (
        '<li><a href="evidence/browser/current-audit-dashboard-verification-run-128-wave-19.json">'
        "Superseded RUN-128 verification GO</a></li>"
    )
    text = replace_once(
        text,
        prior_link,
        prior_link
        + '<li><a href="evidence/browser/current-audit-dashboard-verification-run-132-wave-20.json">Superseded RUN-132 verification GO</a></li>',
        "dashboard prior RUN132 link",
    )

    fresh_start = '<section class="panel"><h2>Fresh RUN-132 audit-dashboard verification</h2>'
    fresh_end = '\n    <section class="panel"><h2>RUN-071–131 evidence lineage</h2>'
    fresh_replacement = (
        '<section class="panel"><h2>Fresh RUN-136 audit-dashboard verification</h2>'
        '<p>The exact regenerated RUN-135 dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844. '
        'The linked RUN-136 receipt must record page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, '
        'visible 660/303/357 ownership, six accounting-integration route/action owners and six bridges, 62/242/48 route/page/overlap feature sets, 91 cumulative bridges, '
        'route 3,218=303+12+5+2,898 with seven tagged gaps, page 711=357+9+345 with one tagged gap, '
        'queue 507=114+393 with 114=92+10+5+7 and 415 without ownership, 3,269 residual records, '
        'six packet expansions (two existing plus four new), 22 assurance findings (15 plus seven) with zero final-finding credit, '
        'one operating organisation across multiple Sites, provider tenant_id as external-provider context, Gate 4 open, mapping 0/340, and all zero-credit boundaries. '
        'It verifies the audit artifact only and grants no application-browser, responsive-application, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p>'
        '<ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-136-wave-21.json">RUN-136 responsive audit-dashboard verification receipt</a></li></ul></section>'
    )
    text = replace_between(
        text, fresh_start, fresh_end, fresh_replacement, "fresh RUN136 section"
    )
    text = text.replace(
        '<section class="panel"><h2>RUN-071–131 evidence lineage</h2><p>Every current raw, generated, reviewed, and integrated RUN-077–131 source/reporting artifact',
        '<section class="panel"><h2>RUN-071–135 evidence lineage</h2><p>Every current raw, generated, reviewed, and integrated RUN-077–135 source/reporting artifact',
    )
    text = replace_once(
        text,
        "Generated deterministically from independently reviewed static evidence through RUN-130/R and reported in RUN-131.",
        "Generated deterministically from independently reviewed static evidence through RUN-134/R and reported in RUN-135.",
        "dashboard footer RUN135",
    )
    text = replace_once(
        text, ".tmp-run131-dashboard", ".tmp-run135-dashboard", "dashboard temp RUN135"
    )

    marker = "dashboard = TEMPLATE.substitute("
    prefix, suffix = text.split(marker, 1)
    suffix = suffix.replace(
        'reviewed_finance_fx_revaluation_overlay["combined_counts"]',
        'reviewed_finance_accounting_integration_overlay["combined_counts"]',
    )
    suffix = suffix.replace(
        'reviewed_finance_fx_revaluation_overlay["queue_accounting"]',
        'reviewed_finance_accounting_integration_overlay["queue_accounting"]',
    )
    suffix = suffix.replace(
        "reviewed_finance_fx_revaluation_overlay['combined_counts']",
        "reviewed_finance_accounting_integration_overlay['combined_counts']",
    )
    suffix = suffix.replace(
        "reviewed_finance_fx_revaluation_overlay['queue_accounting']",
        "reviewed_finance_accounting_integration_overlay['queue_accounting']",
    )
    substitution_anchor = (
        '    finance_fx_route_gap=reviewed_finance_accounting_integration_overlay["combined_counts"]["evidence_gap_routes_tagged_within_residual"],\n'
    )
    substitution_addition = substitution_anchor + (
        '    finance_accounting_wave_reviewed=reviewed_finance_accounting_integration_overlay["reviewed_overlay"]["reviewed_route_actions"],\n'
        '    finance_accounting_review_owner=reviewed_finance_accounting_integration_overlay["reviewed_overlay"]["owner_route_actions"],\n'
        '    finance_accounting_page_calls=reviewed_finance_accounting_integration_overlay["page_context_boundary"]["literal_inertia_page_callsites"],\n'
        '    finance_accounting_existing_pages=reviewed_finance_accounting_integration_overlay["page_context_boundary"]["existing_caller_or_render_pages"],\n'
        '    finance_accounting_frontend_contexts=reviewed_finance_accounting_integration_overlay["page_context_boundary"]["selected_frontend_literal_caller_contexts"],\n'
        '    finance_accounting_no_literal_callers=reviewed_finance_accounting_integration_overlay["page_context_boundary"]["selected_routes_without_literal_caller_in_frozen_pages"],\n'
        '    finance_accounting_page_owners_added=reviewed_finance_accounting_integration_overlay["page_context_boundary"]["new_page_owner_records"],\n'
        '    finance_accounting_page_inherited=reviewed_finance_accounting_integration_overlay["page_context_boundary"]["page_ownership_inherited"],\n'
        '    finance_accounting_page_reassigned=reviewed_finance_accounting_integration_overlay["page_context_boundary"]["page_ownership_reassigned"],\n'
        '    finance_accounting_route_gap=reviewed_finance_accounting_integration_overlay["combined_counts"]["evidence_gap_routes_tagged_within_residual"],\n'
    )
    suffix = replace_once(
        suffix,
        substitution_anchor,
        substitution_addition,
        "dashboard accounting substitutions",
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
        "status": "REVIEWED_FINANCE_ACCOUNTING_INTEGRATION_ROUTE_ACTION_OVERLAY_REPORTED_GATE_4_INCOMPLETE",
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
                "evidence/source/current-run-134-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json"
            ],
            "independent_overlay_review_sha256": PINNED_INPUTS[
                "evidence/source/raw-run-134r-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json"
            ],
            "superseded_dashboard_verification_sha256": PINNED_INPUTS[
                "evidence/browser/current-audit-dashboard-verification-run-132-wave-20.json"
            ],
            "superseded_dashboard_html_sha256": CURRENT_DASHBOARD_SHA256,
        },
        "inputs": {**CURRENT_REPORT_INPUTS, **PINNED_INPUTS},
        "outputs": outputs,
        "preserved": PRESERVED,
        "counts": {
            **overlay["combined_counts"],
            **overlay["queue_accounting"],
            "reviewed_finance_accounting_integration_route_actions": 6,
            "reviewed_owner_route_actions_added": 6,
            "reviewed_shared_relations": 0,
            "reviewed_alias_or_redirect": 0,
            "reviewed_dead_or_noncanonical": 0,
            "reviewed_evidence_gaps": 0,
            "reviewed_non_owner_rows_preserved": 0,
            "route_owner_records_added": 6,
            "controller_action_bridges_added": 6,
            "page_owner_records_added": 0,
            "direct_exact_queue_rows_added": 6,
            "accepted_distinct_feature_ids": 1,
            "new_distinct_feature_ids": 0,
            "new_route_feature_ids": 0,
            "new_page_feature_ids": 0,
            "source_packet_expansion_records_preserved": 6,
            "source_packet_expansion_existing_files": 2,
            "source_packet_expansion_new_files": 4,
            "assurance_candidate_findings_preserved": 15,
            "assurance_shared_findings_preserved": 7,
            "assurance_findings_preserved": 22,
            "provisional_finding_records": 12,
            "final_findings": 0,
            "matrix_rows_changed": 0,
            "matrix_cells_changed": 0,
        },
        "checks": {
            "run_133r_review_go": True,
            "run_133r_outcome_conservation": "6=6+0+0+0+0",
            "run_134r_overlay_review_go": True,
            "all_discrepancy_classes_zero": True,
            "published_identity_fields_verified": 40,
            "source_packet_expansion_records_preserved": 6,
            "source_packet_expansion_partition": "6=2+4",
            "assurance_findings_preserved": 22,
            "assurance_finding_partition": "22=15+7",
            "route_owner_records_added": 6,
            "controller_action_bridges_added": 6,
            "page_owner_records_added": 0,
            "direct_exact_queue_rows_added": 6,
            "sibling_page_index_or_backend_sync_inheritance_used": False,
            "provider_tenant_id_is_external_provider_context": True,
            "matrix_byte_identical": True,
            "matrix_mapping_credit": "0/340",
            "reports_02_through_12_inventory_preserved": True,
            "canonical_provisional_finding_record_semantics_preserved": True,
            "canonical_provisional_findings": 12,
            "application_source_paths_written": 0,
            "one_organisation_multi_site_architecture_preserved": True,
            "dashboard_requires_fresh_run_136_artifact_verification": True,
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
            "provider_and_mapping_correctness": False,
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
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-run-135-reviewed-finance-accounting-integration-route-action-reporting-wave-21.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/current-run-135-reviewed-finance-accounting-integration-route-action-reporting-wave-21.json",
        ],
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
                "pending_queue": receipt["counts"][
                    "pending_unreviewed_queue_surface_rows"
                ],
                "matrix_mapping": "0/340",
                "fresh_dashboard_verification": "RUN-136",
                "gate_4_complete": False,
                "audit_complete": False,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
