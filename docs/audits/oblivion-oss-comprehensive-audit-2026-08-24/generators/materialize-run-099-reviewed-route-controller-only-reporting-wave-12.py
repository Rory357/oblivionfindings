#!/usr/bin/env python3
"""Materialize RUN-099 reporting for the reviewed RUN-098 route/action overlay.

The update reports 23 additional bounded route-owner records and controller
action bridges. It adds no page ownership and preserves every framework,
Site/permission/privacy/lifecycle, runtime, browser, test, benchmark, Pass,
finding, and completion boundary.
"""

from __future__ import annotations

import hashlib
import json
import subprocess
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_RELATIVE = (
    "evidence/source/current-run-099-reviewed-route-controller-only-reporting-wave-12.json"
)

CHECKPOINT_COMMIT = "76e03b1d57826e18b0965405279215d56122e7a1"
CHECKPOINT_TREE = "7c00f20aedbcc6d3f091747abc19bd9d831b3aff"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
RECORDS_SHA256 = "118c1a1f19b2e300c77d5b4d71c60f75b7038382f3c71e18f078367f8473e260"

CURRENT_REPORT_INPUTS = {
    "00-executive-summary.md": "4bbe0db2d7b743ef75c5ad28b58932de6d511ab0845be7bf0109861019fff273",
    "01-repository-module-map.md": "31cd9d8c876bef923e9b592eab6c5a7d920e286abc19f4687786bb03cc409284",
    "13-unresolved-questions-and-evidence-gaps.md": "7e4ab6edcf48d3f20bce6b8d02c678993fa01df992be410b902a5114e8c8f8d9",
    "findings.json": "272ac939ef2b641aca3eed9d16c5e0205c22c3db1d2d70b3db082ac98e53c209",
    "generators/build-current-audit-dashboard.py": "94d08b89f7fb066a506a2a35230d813f3b270508cdda010e8d479cc070872d84",
}

PINNED_INPUTS = {
    "generators/build-route-controller-only-candidate-cohort-wave-12.py": "b2214935c7a00a1f231d2949b6a5b8a481b654a6c6e16bae016c841c21c9c2f1",
    "evidence/source/root-run-097-route-controller-only-candidate-cohort-wave-12.json": "69981d1bc22d76b8f17834040272260d9b33c151535a3ff2ef17ae4643923933",
    "evidence/source/raw-run-097r-independent-route-controller-only-review-wave-12.json": "125c36710cff83750e3bc2e443955f34b5c019f60b36b874790fce9de9774f0a",
    "generators/integrate-reviewed-route-controller-only-ownership-overlay-wave-12.py": "b2c3dd9b12f6cbe27f7114d9ed8164600fb05c36b4751f9e9384d9fd33ce0fdf",
    "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json": "7f76c9cfeae906a64c92013fbf9a12cf180d39a315bfa13e778b6332365cdd2a",
    "evidence/source/current-run-093-reviewed-owner-chain-reporting-wave-11.json": "3b9a694f1c127b49cedee7979681baff985adb1aec859deff10b99c5e07d85ae",
    "evidence/browser/current-audit-dashboard-verification-run-094-wave-11.json": "55e0c2e53dc53774256cbcbc57fe9767631bef3934a453d3c0b2a6997300689a",
}

OVERLAY_REVIEW_RELATIVE = (
    "evidence/source/raw-run-098r-independent-reviewed-route-controller-only-ownership-overlay-wave-12.json"
)

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
        ["git", *args],
        cwd=REPO,
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
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


def assert_inputs() -> tuple[dict[str, Any], dict[str, Any], str]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
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
        expected_review_sha = prior["pins"]["independent_overlay_review_sha256"]
    else:
        for relative, expected in CURRENT_REPORT_INPUTS.items():
            assert sha256_file(relative) == expected, relative
        expected_review_sha = sha256_file(OVERLAY_REVIEW_RELATIVE)
    assert sha256_file(OVERLAY_REVIEW_RELATIVE) == expected_review_sha

    overlay = read_json(
        "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json"
    )
    review = read_json(OVERLAY_REVIEW_RELATIVE)
    assert review["decision"]["verdict"] == "GO"
    assert review["decision"]["mechanical_discrepancies"] == 0
    assert review["decision"]["semantic_boundary_discrepancies"] == 0
    assert review["decision"]["gate_4_complete"] is False
    assert overlay["combined_counts"] == review["verified_combined_counts"]
    assert overlay["queue_accounting"] == review["verified_queue_accounting"]
    assert review["decision"]["route_owner_records_authorized"] == 23
    assert review["decision"]["controller_action_bridges_authorized"] == 23
    assert review["decision"]["page_owner_records_authorized"] == 0
    assert overlay["combined_counts"]["source_owner_records"] == 571
    assert overlay["combined_counts"]["route_owner_records"] == 244
    assert overlay["combined_counts"]["page_owner_records"] == 327
    assert overlay["combined_counts"]["distinct_feature_ids"] == 246
    assert overlay["combined_counts"]["distinct_H_feature_ids"] == 226
    assert overlay["combined_counts"]["distinct_D_feature_ids"] == 20
    assert overlay["combined_counts"]["route_distinct_feature_ids"] == 56
    assert overlay["combined_counts"]["page_distinct_feature_ids"] == 234
    assert overlay["combined_counts"]["route_page_feature_overlap"] == 44
    assert overlay["combined_counts"]["static_controller_action_bridges"] == 32
    assert len(overlay["overlay_source_records"]) == 23
    assert all(row["surface"] == "ROUTE_SOURCE_RECORD" for row in overlay["overlay_source_records"])
    assert len(overlay["new_static_controller_action_bridges"]) == 23
    assert overlay["credit_boundary"]["static_page_feature_ownership"] is False
    assert overlay["denominator_boundary"]["gate_4_complete"] is False
    return overlay, review, expected_review_sha


def patch_reports() -> None:
    summary_relative = "00-executive-summary.md"
    summary = path(summary_relative).read_text(encoding="utf-8")
    summary = replace_once_or_present(
        summary,
        "The current bounded source-owner checkpoint is **548 records = 221 routes + 327 pages across "
        "239 canonical FEATURE-IDs**, with nine controller-action bridges.",
        "The historical RUN-092/R bounded source-owner checkpoint is **548 records = 221 routes + 327 "
        "pages across 239 canonical FEATURE-IDs**, with nine controller-action bridges.",
        "historical RUN-092 summary checkpoint",
    )
    summary_section = """
## RUN-097–099 reviewed route/controller-only ownership overlay

RUN-097 freezes 23 route/controller-only candidates from the 495-row unreviewed queue remainder. The cohort spans 22 canonical FEATURE-IDs and records complete route statements, exact controller methods, method-review slices, feature projections, prior-owner checks, and three disjoint fresh-review partitions. It grants zero ownership before review and contains no candidate page records.

RUN-097R materializes the three fresh read-only reviews. All 23 candidates independently reconstruct with zero mechanical discrepancies and receive `OWNER_ROUTE_ACTION`; no shared, alias, dead/noncanonical, or evidence-gap result is hidden. The receipt authorizes exactly 23 route-owner records and 23 controller-action bridges, with zero page-owner records and no inherited page credit.

RUN-098 integrates only those explicit decisions, and RUN-098R independently verifies the joins, identities, cumulative arithmetic, semantic boundary, and no-page rule. The current bounded checkpoint is **571 records = 244 routes + 327 pages across 246 canonical FEATURE-IDs (226 H + 20 D)**, with 32 controller-action bridges. Route and page owners span 56 and 234 FEATURE-IDs with 44 in their overlap. This is 14.532960% of the bounded 3,929-record source universe; 3,358 records remain, including 2,969 explicit-unmapped routes, five semantic-shared routes, 382 unadjudicated page roots, and two semantic-shared page roots. Of the 507-row queue, 35 surfaces are reviewed (33 owned and two shared), 472 remain unreviewed, and 474 remain without ownership.

RUN-099 reports only that bounded delta. Oblivion Findings remains one operating organisation across multiple Sites: Site access, roles/permissions, canonical ownership, direct-object denial, and privacy/lifecycle behaviour remain separate unproved gates. The matrix, framework denominator, reachability, runtime, database, build, signed-in application browser, tests, benchmarks, ease, Passes, findings, completion, and Gate 4 remain unchanged or false.
"""
    summary = upsert_section_before(
        summary,
        "## RUN-097–099 reviewed route/controller-only ownership overlay",
        "\n## Current raw source census\n",
        summary_section,
        "RUN-097–099 summary section",
    )
    marker = (
        "- `evidence/source/current-run-093-reviewed-owner-chain-reporting-wave-11.json`: "
        "deterministic RUN-093 reporting receipt preserving the matrix, reports 02–12/inventory, "
        "and every downstream zero-credit boundary.\n"
    )
    addition = marker + (
        "- `generators/build-route-controller-only-candidate-cohort-wave-12.py` and `evidence/source/root-run-097-route-controller-only-candidate-cohort-wave-12.json`: deterministic 23-row route/action-only cohort with zero pre-review credit.\n"
        "- `evidence/source/raw-run-097r-independent-route-controller-only-review-wave-12.json`: three fresh partition reviews, 23 explicit owners, zero discrepancies, and zero page credit.\n"
        "- `generators/integrate-reviewed-route-controller-only-ownership-overlay-wave-12.py` and `evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json`: exact 23-route / 23-bridge delta overlay and cumulative identities.\n"
        "- `evidence/source/raw-run-098r-independent-reviewed-route-controller-only-ownership-overlay-wave-12.json`: independent mechanical, semantic, and arithmetic GO review of RUN-098.\n"
        "- `generators/materialize-run-099-reviewed-route-controller-only-reporting-wave-12.py` and `evidence/source/current-run-099-reviewed-route-controller-only-reporting-wave-12.json`: deterministic RUN-099 reporting refresh preserving the matrix, reports 02–12/inventory, and downstream zero-credit boundaries.\n"
    )
    summary = replace_once_or_present(summary, marker, addition, "Wave 12 evidence links")
    write_lf(summary_relative, summary)

    map_relative = "01-repository-module-map.md"
    module_map = path(map_relative).read_text(encoding="utf-8")
    map_section = """
## RUN-097–098 reviewed route/controller-only overlay

RUN-097 freezes 23 pending route surfaces across 22 FEATURE-IDs for exact controller-action review. Three fresh reviewers independently accept all 23 as `OWNER_ROUTE_ACTION`. The accepted relations add no page roots: nine observed literal render callsites were already owned and are context only.

RUN-098 therefore adds exactly 23 route owners and 23 controller-action bridges to the RUN-092 checkpoint. The cumulative bounded ledger is 571 source owners (244 route + 327 page) across 246 FEATURE-IDs (226 H + 20 D). The route/page feature sets are 56/234 with overlap 44, and the action-bridge count is 32. Queue accounting is 507 total, 35 reviewed, 33 owned, two shared, 472 pending, and 474 without ownership.

These relations establish bounded static route/action ownership only. They do not establish framework reachability, Site or permission correctness, canonical direct-object concealment, privacy or lifecycle behaviour, runtime, build, browser, tests, benchmarks, findings, Passes, or completion.
"""
    module_map = upsert_section_before(
        module_map,
        "## RUN-097–098 reviewed route/controller-only overlay",
        "\n## Candidate register\n",
        map_section,
        "RUN-097–098 map section",
    )
    write_lf(map_relative, module_map)

    gaps_relative = "13-unresolved-questions-and-evidence-gaps.md"
    gaps = path(gaps_relative).read_text(encoding="utf-8")
    gaps = replace_line_containing(
        gaps,
        "| Required reporting paths |",
        "| Required reporting paths | 18 / 18 prompt-required files or directories present. RUN-094 independently verified the exact now-superseded RUN-093 dashboard at four viewports; the regenerated RUN-099 dashboard requires a separate fresh RUN-100 artifact receipt. | Presence and prior audit-artifact verification make the reporting contract inspectable but grant no application execution, final-finding, Pass, or completion credit. Gate 26 remains open. | Complete the semantic, execution, benchmark, Pass 8, final reconciliation, and no-live-agent gates; repeat audit-dashboard verification after every later HTML change. |",
        "required paths row",
    )
    gaps = replace_line_containing(
        gaps,
        "| Runtime routes |",
        "| Runtime routes | RUN-098/R establish 244 bounded route-owner records and 32 static controller-action bridges; 2,969 explicit-unmapped route rows and five semantic-shared route rows remain within the bounded 3,218-row static route-like universe. | Static owner/action linkage is not a framework-expanded route table or reachability proof. Missing dependency/runtime provenance keeps framework runtime NO-GO, and the historical 3,024-route denominator cannot be inherited. | Under a fresh bounded runtime grant, use an exact disposable database, execute a separately pinned framework/provider route lane, and reconcile it to all 38 source route files and 3,218 route-like rows. |",
        "runtime routes row",
    )
    gaps = replace_line_containing(
        gaps,
        "| Inertia pages |",
        "| Inertia pages | RUN-084/R independently enumerate 1,058 physical page-tree files. RUN-098/R leave bounded page-root ownership unchanged at 327 records; 382 page roots remain unadjudicated and two reviewed roots remain semantic-shared. | RUN-097–098 add zero page ownership. Full-tree structural GO and bounded ownership are not a complete canonical crosswalk, runtime reachability, build resolution, rendered browser behaviour, or final feature mapping. | Reconcile all 711 literal roots and support relations to safely expanded framework routes and frozen FEATURE-IDs, retain shared relations explicitly, prove build resolution, and observe every safely reachable current-build page under approved roles/Sites. |",
        "pages row",
    )
    gaps = replace_line_containing(
        gaps,
        "| Canonical features |",
        "| Canonical features | Static canonical identity remains 340 targets: 300 H, 40 D, zero M; RUN-098/R establish 571 bounded source-owner records (244 routes + 327 pages) across 246 FEATURE-IDs (226 H + 20 D) plus 32 controller-action bridges while the matrix remains byte-identical at `dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390`. | This is 14.532960% of the bounded 3,929-record source universe. Gate 4 remains incomplete: 3,358 residual records, the framework-expanded denominator, shared relations, reachability, and the full crosswalk remain open; matrix target mapping stays 0/340. | Finish canonical denominator and residual ownership adjudication without inheriting FEATURE-IDs from prefixes, imports, containment, names, or presence, and without awarding runtime, browser, test, benchmark, ease, release, or completion credit. |",
        "canonical row",
    )
    gaps = replace_line_containing(
        gaps,
        "| Agent universe and writer rule |",
        "| Agent universe and writer rule | RUN-001 through RUN-099 represented at the current reporting checkpoint; finalization gate false. | RUN-097/R review 23 route/action candidates, RUN-098/R independently integrate and verify 23 route owners plus 23 bridges with zero page additions, and RUN-099 reports only those bounded classes. Runtime, browser, tests, benchmarks, Pass 8, finalization, and completion remain open. | Complete residual ownership and every semantic/execution gate, then dispatch fresh Pass 8/final cross-reviewers, verify the final dashboard, and prove no agent remains live at finalization. |",
        "agent row",
    )
    gaps = replace_once_or_present(
        gaps,
        "## RUN-077–093 route/page, page-tree, backend, ownership and reporting lineage",
        "## RUN-077–099 route/page, page-tree, backend, ownership and reporting lineage",
        "lineage heading",
    )
    new_lineage = (
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
        "reports only that bounded delta. Gate 4 and the full route/page/backend crosswalk remain incomplete; "
        "Oblivion Findings remains a single-tenant application for one organisation across multiple Sites, "
        "and framework reachability, Site/permission/privacy/lifecycle correctness, runtime, build, signed-in "
        "application browser, executed tests, benchmark mapping, ease, release, Pass, final-finding, and "
        "completion remain zero-credit."
    )
    gaps = replace_line_containing(
        gaps,
        "RUN-077 freezes the exhaustive committed-source route/name/page universe.",
        new_lineage,
        "lineage paragraph",
    )
    write_lf(gaps_relative, gaps)


def patch_findings(overlay: dict[str, Any], review: dict[str, Any], review_sha: str) -> None:
    relative = "findings.json"
    findings = read_json(relative)
    assert canonical_json_sha256(findings["records"]) == RECORDS_SHA256
    findings["pins"].update(
        {
            "run_097_route_controller_candidate_generator_sha256": PINNED_INPUTS[
                "generators/build-route-controller-only-candidate-cohort-wave-12.py"
            ],
            "run_097_route_controller_candidate_cohort_sha256": PINNED_INPUTS[
                "evidence/source/root-run-097-route-controller-only-candidate-cohort-wave-12.json"
            ],
            "run_097r_route_controller_review_sha256": PINNED_INPUTS[
                "evidence/source/raw-run-097r-independent-route-controller-only-review-wave-12.json"
            ],
            "run_098_route_controller_overlay_generator_sha256": PINNED_INPUTS[
                "generators/integrate-reviewed-route-controller-only-ownership-overlay-wave-12.py"
            ],
            "run_098_route_controller_overlay_sha256": PINNED_INPUTS[
                "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json"
            ],
            "run_098r_route_controller_overlay_review_sha256": review_sha,
        }
    )
    counts = overlay["combined_counts"]
    queue = overlay["queue_accounting"]
    findings["counts"].update(
        {
            "static_source_feature_ownership_records": counts["source_owner_records"],
            "static_source_feature_ownership_route_records": counts["route_owner_records"],
            "static_source_feature_ownership_page_records": counts["page_owner_records"],
            "static_source_feature_ownership_distinct_feature_ids": counts["distinct_feature_ids"],
            "static_source_feature_ownership_distinct_H_feature_ids": counts[
                "distinct_H_feature_ids"
            ],
            "static_source_feature_ownership_distinct_D_feature_ids": counts[
                "distinct_D_feature_ids"
            ],
            "static_source_feature_ownership_route_distinct_feature_ids": counts[
                "route_distinct_feature_ids"
            ],
            "static_source_feature_ownership_page_distinct_feature_ids": counts[
                "page_distinct_feature_ids"
            ],
            "static_source_feature_ownership_route_page_feature_overlap": counts[
                "route_page_feature_overlap"
            ],
            "static_controller_action_bridges": counts["static_controller_action_bridges"],
            "bounded_static_source_ownership_percent": counts[
                "bounded_static_source_ownership_percent"
            ],
            "bounded_static_source_residual_records": counts[
                "bounded_static_source_residual_records"
            ],
            "direct_exact_queue_records": queue["direct_exact_queue_records"],
            "direct_exact_queue_reviewed": queue["reviewed_queue_surface_rows"],
            "direct_exact_queue_owned": queue["owner_queue_surface_rows"],
            "direct_exact_queue_shared": queue["shared_queue_surface_rows"],
            "direct_exact_queue_pending_unreviewed": queue[
                "pending_unreviewed_queue_surface_rows"
            ],
            "direct_exact_queue_without_ownership": queue["queue_surfaces_without_ownership"],
        }
    )
    findings["current_direct_exact_route_page_review_queue"] = {
        "run_id": "RUN-090-DIRECT-EXACT-ROUTE-PAGE-REVIEW-QUEUE-WAVE-11",
        "status": "CANDIDATE_QUEUE_PARTIALLY_REVIEWED_NO_WHOLESALE_OWNERSHIP_CREDIT",
        "records": 507,
        "reviewed_queue_surfaces": 35,
        "owned_queue_surfaces": 33,
        "shared_queue_surfaces": 2,
        "pending_unreviewed": 472,
        "without_ownership": 474,
        "wholesale_ownership_authorized": False,
    }
    findings["current_static_source_feature_ownership"] = {
        "run_id": overlay["run_id"],
        "review_run_id": review["run_id"],
        "status": "GO_REVIEWED_BOUNDED_ROUTE_ACTION_OWNERSHIP_ONLY",
        "baseline_records": overlay["baseline"]["source_owner_records"],
        "overlay_source_records": len(overlay["overlay_source_records"]),
        "owner_route_actions_added": 23,
        "controller_action_bridges_added": 23,
        "page_owner_records_added": 0,
        "source_owner_records": counts["source_owner_records"],
        "route_owner_records": counts["route_owner_records"],
        "page_owner_records": counts["page_owner_records"],
        "distinct_feature_ids": counts["distinct_feature_ids"],
        "distinct_H_feature_ids": counts["distinct_H_feature_ids"],
        "distinct_D_feature_ids": counts["distinct_D_feature_ids"],
        "route_distinct_feature_ids": counts["route_distinct_feature_ids"],
        "page_distinct_feature_ids": counts["page_distinct_feature_ids"],
        "route_page_feature_overlap": counts["route_page_feature_overlap"],
        "static_controller_action_bridges": counts["static_controller_action_bridges"],
        "bounded_denominator": counts["bounded_static_source_denominator"],
        "bounded_ownership_percent": counts["bounded_static_source_ownership_percent"],
        "bounded_residual_records": counts["bounded_static_source_residual_records"],
        "queue_accounting": queue,
        "independent_review_discrepancies": 0,
        "gate_4": {
            "status": "PARTIAL_BOUNDED_STATIC_SOURCE_OWNERSHIP_INCOMPLETE",
            "complete": False,
        },
        "credit_boundary": overlay["credit_boundary"],
    }
    findings["current_route_controller_only_ownership_review"] = {
        "run_id": review["run_id"],
        "status": review["status"],
        "reviewers": 3,
        "route_owner_records_authorized": 23,
        "controller_action_bridges_authorized": 23,
        "page_owner_records_authorized": 0,
        "mechanical_discrepancies": 0,
        "semantic_boundary_discrepancies": 0,
        "gate_4_complete": False,
        "completion_credit": False,
    }
    assert canonical_json_sha256(findings["records"]) == RECORDS_SHA256
    write_lf(relative, json.dumps(findings, ensure_ascii=False, indent=2) + "\n")


def patch_dashboard_generator(review_sha: str) -> None:
    relative = "generators/build-current-audit-dashboard.py"
    text = path(relative).read_text(encoding="utf-8")
    load_marker = (
        'reviewed_owner_overlay_review = read_json("evidence/source/'
        'raw-run-092r-independent-reviewed-static-source-ownership-overlay-wave-11.json")\n'
    )
    load_addition = load_marker + (
        'route_controller_cohort = read_json("evidence/source/root-run-097-route-controller-only-candidate-cohort-wave-12.json")\n'
        'route_controller_review = read_json("evidence/source/raw-run-097r-independent-route-controller-only-review-wave-12.json")\n'
        'reviewed_route_controller_overlay = read_json("evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json")\n'
        'reviewed_route_controller_overlay_review = read_json("evidence/source/raw-run-098r-independent-reviewed-route-controller-only-ownership-overlay-wave-12.json")\n'
    )
    text = replace_once_or_present(text, load_marker, load_addition, "load RUN-097–098R")

    assertion_marker = "\n\ncandidates = wave1[\"candidates\"] + wave2[\"candidates\"] + wave3[\"candidates\"]\n"
    assertions = f"""

assert sha256_file("generators/build-route-controller-only-candidate-cohort-wave-12.py") == "{PINNED_INPUTS['generators/build-route-controller-only-candidate-cohort-wave-12.py']}"
assert sha256_file("evidence/source/root-run-097-route-controller-only-candidate-cohort-wave-12.json") == "{PINNED_INPUTS['evidence/source/root-run-097-route-controller-only-candidate-cohort-wave-12.json']}"
assert sha256_file("evidence/source/raw-run-097r-independent-route-controller-only-review-wave-12.json") == "{PINNED_INPUTS['evidence/source/raw-run-097r-independent-route-controller-only-review-wave-12.json']}"
assert sha256_file("generators/integrate-reviewed-route-controller-only-ownership-overlay-wave-12.py") == "{PINNED_INPUTS['generators/integrate-reviewed-route-controller-only-ownership-overlay-wave-12.py']}"
assert sha256_file("evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json") == "{PINNED_INPUTS['evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json']}"
assert sha256_file("evidence/source/raw-run-098r-independent-reviewed-route-controller-only-ownership-overlay-wave-12.json") == "{review_sha}"
assert route_controller_cohort["counts"]["candidate_route_actions"] == 23
assert route_controller_cohort["counts"]["candidate_page_records"] == 0
assert route_controller_review["decision"]["owner_route_actions"] == 23
assert route_controller_review["decision"]["static_page_owner_records_authorized"] == 0
assert reviewed_route_controller_overlay["combined_counts"]["source_owner_records"] == 571
assert reviewed_route_controller_overlay["combined_counts"]["route_owner_records"] == 244
assert reviewed_route_controller_overlay["combined_counts"]["page_owner_records"] == 327
assert reviewed_route_controller_overlay["combined_counts"]["distinct_feature_ids"] == 246
assert reviewed_route_controller_overlay["combined_counts"]["distinct_H_feature_ids"] == 226
assert reviewed_route_controller_overlay["combined_counts"]["distinct_D_feature_ids"] == 20
assert reviewed_route_controller_overlay["combined_counts"]["route_distinct_feature_ids"] == 56
assert reviewed_route_controller_overlay["combined_counts"]["page_distinct_feature_ids"] == 234
assert reviewed_route_controller_overlay["combined_counts"]["route_page_feature_overlap"] == 44
assert reviewed_route_controller_overlay["combined_counts"]["static_controller_action_bridges"] == 32
assert reviewed_route_controller_overlay["combined_counts"]["bounded_static_source_residual_records"] == 3358
assert reviewed_route_controller_overlay["queue_accounting"]["reviewed_queue_surface_rows"] == 35
assert reviewed_route_controller_overlay["queue_accounting"]["owner_queue_surface_rows"] == 33
assert reviewed_route_controller_overlay["queue_accounting"]["shared_queue_surface_rows"] == 2
assert reviewed_route_controller_overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 472
assert reviewed_route_controller_overlay["queue_accounting"]["queue_surfaces_without_ownership"] == 474
assert reviewed_route_controller_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_route_controller_overlay_review["decision"]["mechanical_discrepancies"] == 0
assert reviewed_route_controller_overlay_review["decision"]["semantic_boundary_discrepancies"] == 0
assert reviewed_route_controller_overlay_review["decision"]["page_owner_records_authorized"] == 0
assert reviewed_route_controller_overlay_review["decision"]["gate_4_complete"] is False
assert reviewed_route_controller_overlay["architecture_rule"] == (
    "Oblivion Findings is one operating organisation with multiple Sites. Bounded static route/action "
    "ownership does not establish permission, Site/privacy/lifecycle correctness, runtime behaviour, "
    "or release readiness."
)
assert reviewed_route_controller_overlay["credit_boundary"]["static_page_feature_ownership"] is False
assert reviewed_route_controller_overlay["credit_boundary"]["wholesale_507_queue_ownership"] is False
assert all(
    reviewed_route_controller_overlay["credit_boundary"][key] is False
    for key in (
        "complete_route_page_feature_crosswalk", "framework_route_reachability", "navigation",
        "site_authorization_correctness", "permission_correctness", "privacy_correctness",
        "lifecycle_correctness", "runtime", "database", "build", "application_browser",
        "executed_tests", "benchmark", "ease", "pass", "final_finding", "completion",
        "audit_complete",
    )
)
"""
    text = insert_before_once(text, assertion_marker, assertions, "assert RUN-097–098R")

    evidence_marker = (
        '    ("RUN-093 reviewed-owner reporting/hash receipt", '
        '"evidence/source/current-run-093-reviewed-owner-chain-reporting-wave-11.json"),\n'
    )
    evidence_addition = evidence_marker + (
        '    ("RUN-097 route/controller-only cohort generator", "generators/build-route-controller-only-candidate-cohort-wave-12.py"),\n'
        '    ("RUN-097 23-row route/controller-only cohort", "evidence/source/root-run-097-route-controller-only-candidate-cohort-wave-12.json"),\n'
        '    ("RUN-097R three-part 23-owner route/action review", "evidence/source/raw-run-097r-independent-route-controller-only-review-wave-12.json"),\n'
        '    ("RUN-098 reviewed route/action overlay generator", "generators/integrate-reviewed-route-controller-only-ownership-overlay-wave-12.py"),\n'
        '    ("RUN-098 23-route / 23-bridge ownership overlay", "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json"),\n'
        '    ("RUN-098R independent overlay review", "evidence/source/raw-run-098r-independent-reviewed-route-controller-only-ownership-overlay-wave-12.json"),\n'
        '    ("RUN-099 route/action reporting materializer", "generators/materialize-run-099-reviewed-route-controller-only-reporting-wave-12.py"),\n'
        '    ("RUN-099 route/action reporting/hash receipt", "evidence/source/current-run-099-reviewed-route-controller-only-reporting-wave-12.json"),\n'
    )
    text = replace_once_or_present(text, evidence_marker, evidence_addition, "RUN-097–099 evidence")

    old_values = (
        '    static_owner_records=reviewed_owner_overlay["combined_counts"]["source_owner_records"],\n'
        '    static_owner_routes=reviewed_owner_overlay["combined_counts"]["route_owner_records"],\n'
        '    static_owner_pages=reviewed_owner_overlay["combined_counts"]["page_owner_records"],\n'
        '    static_owner_features=reviewed_owner_overlay["combined_counts"]["distinct_feature_ids"],\n'
        '    static_action_bridges=reviewed_owner_overlay["combined_counts"]["static_controller_action_bridges"],\n'
        '    static_residual=f"{reviewed_owner_overlay[\'combined_counts\'][\'bounded_static_source_residual_records\']:,}",\n'
        '    ownership_percent=reviewed_owner_overlay["combined_counts"]["bounded_static_source_ownership_percent"],\n'
        '    queue_records=reviewed_owner_overlay["queue_accounting"]["direct_exact_queue_records"],\n'
        '    queue_pending=reviewed_owner_overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"],\n'
        '    queue_without_owner=reviewed_owner_overlay["queue_accounting"]["queue_surfaces_without_ownership"],\n'
    )
    new_values = (
        '    static_owner_records=reviewed_route_controller_overlay["combined_counts"]["source_owner_records"],\n'
        '    static_owner_routes=reviewed_route_controller_overlay["combined_counts"]["route_owner_records"],\n'
        '    static_owner_pages=reviewed_route_controller_overlay["combined_counts"]["page_owner_records"],\n'
        '    static_owner_features=reviewed_route_controller_overlay["combined_counts"]["distinct_feature_ids"],\n'
        '    static_action_bridges=reviewed_route_controller_overlay["combined_counts"]["static_controller_action_bridges"],\n'
        '    static_residual=f"{reviewed_route_controller_overlay[\'combined_counts\'][\'bounded_static_source_residual_records\']:,}",\n'
        '    ownership_percent=reviewed_route_controller_overlay["combined_counts"]["bounded_static_source_ownership_percent"],\n'
        '    queue_records=reviewed_route_controller_overlay["queue_accounting"]["direct_exact_queue_records"],\n'
        '    queue_pending=reviewed_route_controller_overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"],\n'
        '    queue_without_owner=reviewed_route_controller_overlay["queue_accounting"]["queue_surfaces_without_ownership"],\n'
    )
    text = replace_once_or_present(text, old_values, new_values, "latest substitution values")

    replacements = [
        ('<a href="#checkpoint">RUN-093</a>', '<a href="#checkpoint">RUN-099</a>', "nav"),
        ('<strong>RUN-071–093 current reporting checkpoint:</strong>', '<strong>RUN-071–099 current reporting checkpoint:</strong>', "checkpoint notice"),
        ('<h2>RUN-071–093 completion-gate checkpoint</h2>', '<h2>RUN-071–099 completion-gate checkpoint</h2>', "checkpoint heading"),
        ('queue itself grants no wholesale ownership · current overlay: 10 owned · 2 shared · $queue_pending unreviewed · $queue_without_owner without ownership', 'queue itself grants no wholesale ownership · current overlay: 33 owned · 2 shared · $queue_pending unreviewed · $queue_without_owner without ownership', "checkpoint queue counts"),
        ('RUN-086/R add the initial independently reviewed bounded ownership, RUN-089 repeats the signed-out preflight, RUN-090–092/R queue, review, integrate, and independently verify nine further closed chains while retaining two shared, and RUN-093 refreshes current reporting.', 'RUN-086/R add the initial independently reviewed bounded ownership, RUN-089 repeats the signed-out preflight, RUN-090–092/R queue, review, integrate, and independently verify nine closed chains while retaining two shared, RUN-097/R–098/R add and independently verify 23 route/action owners with zero page additions, and RUN-099 refreshes current reporting.', "checkpoint narrative"),
        ('RUN-030 freezes canonical static identity; RUN-077–084B add exhaustive committed static route/name/page, full page-tree, and backend structural evidence; RUN-086/R establish the initial bounded ownership, RUN-090–092/R add the independently reviewed closed-chain overlay, and RUN-093 refreshes reporting.', 'RUN-030 freezes canonical static identity; RUN-077–084B add exhaustive committed static route/name/page, full page-tree, and backend structural evidence; RUN-086/R establish the initial bounded ownership, RUN-090–092/R add the independently reviewed closed-chain overlay, RUN-097/R–098/R add 23 independently reviewed route/action owners with zero page additions, and RUN-099 refreshes reporting.', "static census intro"),
        ('RUN-092/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges; complete the framework-expanded canonical route/page denominator, $static_residual residual records including 5 shared routes and 2 shared pages, the full crosswalk, and route reachability before Gate 4 can close', 'RUN-098/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges with zero page additions; complete the framework-expanded canonical route/page denominator, $static_residual residual records including 5 shared routes and 2 shared pages, the full crosswalk, and route reachability before Gate 4 can close', "Gate 4 current overlay"),
        ('RUN-001 through RUN-093 are represented by audit artifacts;', 'RUN-001 through RUN-099 are represented by audit artifacts;', "wave range"),
        ('<h2>RUN-071–093 evidence lineage</h2>', '<h2>RUN-071–099 evidence lineage</h2>', "lineage heading"),
        ('RUN-077–093 source/reporting artifact', 'RUN-077–099 source/reporting artifact', "lineage text"),
        ('Generated deterministically from independently reviewed static evidence through RUN-092/R and reported in RUN-093.', 'Generated deterministically from independently reviewed static evidence through RUN-098/R and reported in RUN-099.', "footer"),
        ('.tmp-run093-dashboard', '.tmp-run099-dashboard', "temp name"),
        ('RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, and RUN-088 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-093.', 'RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, and RUN-094 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-099.', "prior verification"),
        ('<li><a href="evidence/browser/current-audit-dashboard-verification-run-088-wave-10.json">Superseded RUN-088 verification GO</a></li></ul>', '<li><a href="evidence/browser/current-audit-dashboard-verification-run-088-wave-10.json">Superseded RUN-088 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-094-wave-11.json">Superseded RUN-094 verification GO</a></li></ul>', "prior verification link"),
        ('<section class="panel"><h2>Fresh RUN-094 audit-dashboard verification</h2><p>The exact regenerated RUN-093 dashboard is checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-094 receipt records page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible ownership/shared/queue and zero-credit boundaries, and exact dashboard/generator/reporting hashes. It verifies the audit artifact only and grants no application-browser, responsive, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-094-wave-11.json">RUN-094 responsive audit-dashboard verification receipt</a></li></ul></section>', '<section class="panel"><h2>Fresh RUN-100 audit-dashboard verification</h2><p>The exact regenerated RUN-099 dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-100 receipt records page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible ownership/queue/no-page and zero-credit boundaries, and exact dashboard/generator/reporting hashes. It verifies the audit artifact only and grants no application-browser, responsive, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-100-wave-12.json">RUN-100 responsive audit-dashboard verification receipt</a></li></ul></section>', "fresh verification"),
    ]
    for old, new, label in replacements:
        text = replace_once_or_present(text, old, new, label)

    old_notice = (
        "RUN-091/R accept nine closed chains and reject two shared relations; RUN-092/R independently "
        "establish $static_owner_records bounded source-owner records ($static_owner_routes routes + "
        "$static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges "
        "action bridges. $static_residual records remain and Gate 4 is open."
    )
    prior_notice = (
        "RUN-091/R and RUN-092/R remain the historical nine-chain overlay. RUN-097/R accept 23 "
        "route/controller-only owners; RUN-098/R independently establish $static_owner_records bounded "
        "source-owner records ($static_owner_routes routes + $static_owner_pages pages) across "
        "$static_owner_features FEATURE-IDs plus $static_action_bridges action bridges, with zero new page "
        "owners. $static_residual records remain and Gate 4 is open."
    )
    new_notice = (
        "RUN-091/R and RUN-092/R remain the historical nine-chain overlay. RUN-097/R accept 23 "
        "route/controller-only owners; RUN-098/R independently establish $static_owner_records bounded "
        "source-owner records ($static_owner_routes routes + $static_owner_pages pages) across "
        "$static_owner_features FEATURE-IDs plus $static_action_bridges action bridges, with zero new page "
        "owners. $static_residual records remain and Gate 4 is open. Oblivion Findings remains one "
        "operating organisation across multiple Sites; Site access, roles/permissions, canonical "
        "ownership, direct-object denial, privacy, and lifecycle correctness remain separate unproved gates."
    )
    text = replace_once_or_present(text, old_notice, prior_notice, "primary notice baseline")
    text = replace_once_or_present(text, prior_notice, new_notice, "primary notice architecture rule")

    old_checkpoint = (
        "RUN-090 freezes $queue_records candidate rows without ownership. RUN-091/R accept nine owner "
        "chains and retain two shared; RUN-092/R raise bounded ownership to $static_owner_records records "
        "and $static_action_bridges action bridges, with $queue_pending queue rows unreviewed and "
        "$queue_without_owner without ownership."
    )
    new_checkpoint = (
        "RUN-090 freezes $queue_records candidate rows without wholesale ownership. RUN-091/R–092/R "
        "remain the historical nine-owner/two-shared overlay. RUN-097/R–098/R add 23 reviewed route "
        "owners and 23 action bridges with zero page additions, raising bounded ownership to "
        "$static_owner_records records and $static_action_bridges action bridges; $queue_pending queue "
        "rows remain unreviewed and $queue_without_owner remain without ownership."
    )
    text = replace_once_or_present(text, old_checkpoint, new_checkpoint, "checkpoint boundary")

    old_checkpoint_rows = (
        '<tr><td>RUN-091/R → 092/R closed-chain overlay</td><td><strong>9 owner chains · 2 shared · 18 owner rows · $static_action_bridges action bridges</strong></td><td class="partial">$static_owner_records cumulative owners · $static_owner_routes routes + $static_owner_pages pages · $static_owner_features FEATURE-IDs · Gate 4 incomplete</td></tr>'
        '<tr><td>RUN-093 reporting refresh</td><td><strong>reviewed overlay reported</strong></td><td class="partial">audit-only materialization · matrix and preserved reports byte-identical</td></tr>'
    )
    new_checkpoint_rows = (
        '<tr><td>RUN-091/R → 092/R closed-chain overlay</td><td><strong>9 owner chains · 2 shared · 18 owner rows · 9 action bridges</strong></td><td class="partial">548 cumulative owners · 221 routes + 327 pages · 239 FEATURE-IDs · historical bounded checkpoint</td></tr>'
        '<tr><td>RUN-093 reporting refresh</td><td><strong>historical reviewed overlay reported</strong></td><td class="partial">audit-only materialization · superseded dashboard separately verified by RUN-094</td></tr>'
        '<tr><td>RUN-097/R → 098/R route/action overlay</td><td><strong>23 owner route/actions · 23 route rows · 23 action bridges · 0 page rows</strong></td><td class="partial">$static_owner_records cumulative owners · $static_owner_routes routes + $static_owner_pages pages · $static_owner_features FEATURE-IDs · Gate 4 incomplete</td></tr>'
        '<tr><td>RUN-099 reporting refresh</td><td><strong>route/action overlay reported</strong></td><td class="partial">audit-only materialization · matrix and preserved reports byte-identical</td></tr>'
    )
    text = replace_once_or_present(text, old_checkpoint_rows, new_checkpoint_rows, "checkpoint rows")

    old_census = (
        '<tr><td>RUN-092/R bounded source FEATURE-ID ownership</td><td>$static_owner_records records · '
        '$static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs · '
        '$static_action_bridges action bridges</td><td class="partial">two-part independent overlay GO · '
        '$ownership_percent% of bounded 3,929 records · $static_residual residual · Gate 4 incomplete · '
        'matrix unchanged</td></tr><tr><td>RUN-090 direct-exact queue</td><td>$queue_records total · '
        'current overlay: 10 owned · 2 shared · $queue_pending unreviewed · $queue_without_owner without '
        'ownership</td><td class="partial">candidate prioritisation only · queue itself grants no wholesale '
        'ownership</td></tr>'
    )
    new_census = (
        '<tr><td>RUN-092/R historical bounded ownership</td><td>548 records · 221 route + 327 page · '
        '239 FEATURE-IDs · 9 action bridges</td><td class="partial">13.947569% · 3,381 residual · historical '
        'bounded checkpoint</td></tr><tr><td>RUN-098/R current bounded route/action ownership</td><td>'
        '$static_owner_records records · $static_owner_routes route + $static_owner_pages page · '
        '$static_owner_features FEATURE-IDs · $static_action_bridges action bridges</td><td class="partial">'
        '$ownership_percent% of bounded 3,929 records · $static_residual residual · zero page additions · '
        'Gate 4 incomplete · matrix unchanged</td></tr><tr><td>RUN-090 direct-exact queue</td><td>'
        '$queue_records total · current overlay: 33 owned · 2 shared · $queue_pending unreviewed · '
        '$queue_without_owner without ownership</td><td class="partial">candidate prioritisation only · '
        'queue itself grants no wholesale ownership</td></tr>'
    )
    text = replace_once_or_present(text, old_census, new_census, "census rows")

    old_wave_items = (
        '<li>RUN-093: deterministic reviewed-overlay reporting refresh · matrix and every execution/'
        'benchmark/Pass/finding/completion boundary unchanged</li>'
    )
    new_wave_items = old_wave_items + (
        '<li>RUN-097/R: 23 route/controller-only candidates · three fresh reviews · 23 owners · '
        '0 shared/alias/dead/gap · 0 page credit</li><li>RUN-098/R: 23 route rows + 23 action '
        'bridges integrated and independently verified · $static_owner_records cumulative owner records</li>'
        '<li>RUN-099: deterministic route/action reporting refresh · matrix and every Site/permission/'
        'privacy/lifecycle/execution/benchmark/Pass/finding/completion boundary unchanged</li>'
    )
    text = replace_once_or_present(text, old_wave_items, new_wave_items, "wave items")

    text = replace_once_or_present(
        text,
        '<li>RUN-092/R: 18 owner rows + $static_action_bridges action bridges integrated · one independent '
        'mechanical reconstruction + one semantic-boundary review · $static_owner_records cumulative owner '
        'records</li>',
        '<li>RUN-092/R: 18 owner rows + 9 action bridges integrated · one independent mechanical '
        'reconstruction + one semantic-boundary review · 548 cumulative owner records</li>',
        "historical RUN-092 wave counts",
    )
    text = replace_once_or_present(
        text,
        '.list{margin:0;padding-left:20px}.list code{overflow-wrap:anywhere}',
        '.list{margin:0;padding-left:20px}.list li,.list code{overflow-wrap:anywhere}',
        "mobile list wrapping",
    )
    write_lf(relative, text)


def main() -> None:
    overlay, review, review_sha = assert_inputs()
    patch_reports()
    patch_findings(overlay, review, review_sha)
    patch_dashboard_generator(review_sha)

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
        "schema_version": "run-099-reviewed-route-controller-only-reporting-wave-12-v1",
        "run_id": "RUN-099-REVIEWED-ROUTE-CONTROLLER-ONLY-REPORTING-WAVE-12",
        "status": "REVIEWED_ROUTE_ACTION_OVERLAY_REPORTED_GATE_4_INCOMPLETE",
        "generated_on": "2026-08-25",
        "pins": {
            "checkpoint_commit": CHECKPOINT_COMMIT,
            "checkpoint_tree": CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "app_tree": APP_TREE,
            "routes_tree": ROUTES_TREE,
            "resources_js_tree": RESOURCES_JS_TREE,
            "matrix_sha256": MATRIX_SHA256,
            "materializer_sha256": sha256_file(
                "generators/materialize-run-099-reviewed-route-controller-only-reporting-wave-12.py"
            ),
            "overlay_sha256": PINNED_INPUTS[
                "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json"
            ],
            "independent_overlay_review_sha256": review_sha,
        },
        "inputs": {
            **CURRENT_REPORT_INPUTS,
            **PINNED_INPUTS,
            OVERLAY_REVIEW_RELATIVE: review_sha,
        },
        "outputs": outputs,
        "preserved": PRESERVED,
        "counts": {
            **overlay["combined_counts"],
            "direct_exact_queue_records": 507,
            "reviewed_queue_surface_rows": 35,
            "owner_queue_surface_rows": 33,
            "shared_queue_surface_rows": 2,
            "pending_unreviewed_queue_surface_rows": 472,
            "queue_surfaces_without_ownership": 474,
            "reviewed_owner_route_actions_added": 23,
            "route_owner_records_added": 23,
            "controller_action_bridges_added": 23,
            "page_owner_records_added": 0,
            "provisional_finding_records": 12,
            "final_findings": 0,
            "matrix_rows_changed": 0,
            "matrix_cells_changed": 0,
        },
        "checks": {
            "three_part_run_097r_review_go": True,
            "run_097r_owner_route_actions": 23,
            "three_part_run_098r_overlay_review_go": True,
            "independent_review_discrepancies": 0,
            "route_owner_records_added": 23,
            "controller_action_bridges_added": 23,
            "page_owner_records_added": 0,
            "historical_run_086_and_run_092_counts_preserved_separately": True,
            "wholesale_queue_ownership_rejected": True,
            "matrix_byte_identical": True,
            "provisional_finding_record_semantics_preserved": True,
            "application_source_paths_written": 0,
            "single_tenant_multi_site_architecture_preserved": True,
            "dashboard_requires_fresh_run_100_artifact_verification": True,
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


if __name__ == "__main__":
    main()
