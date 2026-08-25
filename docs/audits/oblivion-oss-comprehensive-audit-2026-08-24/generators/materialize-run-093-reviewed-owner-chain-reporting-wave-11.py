#!/usr/bin/env python3
"""Materialize RUN-093 reporting for the independently reviewed RUN-092 overlay.

The update reports nine accepted route-action-page owner chains, excludes two
shared relations, and preserves every framework, runtime, browser, test,
benchmark, Pass, finding, and completion boundary.
"""

from __future__ import annotations

import hashlib
import json
import subprocess
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_RELATIVE = "evidence/source/current-run-093-reviewed-owner-chain-reporting-wave-11.json"

CHECKPOINT_COMMIT = "786a2e2f8ab21142d0cb93bd9f5ceb1bf1aa6bb5"
CHECKPOINT_TREE = "a1b32e32ef254a07016990051ed30eb28fdf8b9e"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"

CURRENT_REPORT_INPUTS = {
    "00-executive-summary.md": "2046b20a3687f8e7691aff2eb6203c5cc1ec0454c68939de8c4ef3141f31cae8",
    "01-repository-module-map.md": "1e1f4a096b895cd13d04ebf246552938fa92d2e0d93641b8f866c6c7833008ff",
    "13-unresolved-questions-and-evidence-gaps.md": "df5cca535d4420d5f988031fefd917aa9f17ec4f6df06e9ea9a5952aad15cef1",
    "findings.json": "afdecfec73b0b7a55ba81b26df274fa724e93ab4583d79763639d6cc223963a4",
    "generators/build-current-audit-dashboard.py": "91e6ce125b3ca447db942d97ba968f1371b4d7beb7f3e229a9b1b89f74a0aaf7",
}

PINNED_INPUTS = {
    "evidence/browser/current-designated-application-access-preflight-run-089-wave-11.json": "d9c47be6e4f7f2c1f179548321d674c104d56b94f6920d8c9256b0727369e3f8",
    "generators/build-direct-exact-route-page-review-queue-wave-11.py": "73b12d328cfee86631670b0b6b6a9bb6e7cc4ee45380af1136d361584f6d241d",
    "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json": "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    "generators/build-closed-route-action-page-chain-cohort-wave-11.py": "68c47e238fa0ab11971b867bebe56ca8b5ffe93429ef0d2a026881d55f29d9a9",
    "evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json": "7b1fea57104e9f4bfea7ee450ef535ac57e7fa69bc978212b4eafb0fe1ed0cae",
    "evidence/source/raw-run-091r-independent-closed-route-action-page-chain-review-wave-11.json": "fb88ca666bc9f91298ab33fefa1dadbb39a4a612215fca814932f59bfc2f199b",
    "generators/integrate-reviewed-static-source-ownership-overlay-wave-11.py": "100921c48ea9588af96ec47231055b6ce15877f30a38dc479cf15ff1ef7be1f3",
    "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json": "5390e55d9e47d1845afa8b3848bdab7687747cbb62c946dd4d66d3c435e8de0b",
    "evidence/source/raw-run-092r-independent-reviewed-static-source-ownership-overlay-wave-11.json": "1111d30aa24935116c37f27bead824ca1bcca7444157e456d959e821af00669a",
    "evidence/source/current-run-087-static-source-feature-ownership-reporting.json": "ca3dc7a09b19a3f656f6eda4a653c27f4ad14479796d6eecd83677db44c01936",
    "evidence/browser/current-audit-dashboard-verification-run-088-wave-10.json": "56c90413d821a5d2cad111c8f0032584ae6a3edb0d81b05e0bdeb858d7bad080",
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
        ["git", *args], cwd=REPO, check=True, stdout=subprocess.PIPE,
        stderr=subprocess.PIPE, text=True,
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


def replace_one_of_or_present(text: str, olds: tuple[str, ...], new: str, label: str) -> str:
    if new in text:
        return text
    matches = [(old, text.count(old)) for old in olds]
    assert sum(count for _old, count in matches) == 1, (label, matches)
    old = next(old for old, count in matches if count == 1)
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
    assert first < marker_index and last < marker_index, (label, first, last, marker_index)
    before = text[:first].rstrip("\n")
    normalized = section.strip("\n")
    return before + "\n\n" + normalized + "\n" + text[marker_index:]


def replace_line_containing(text: str, token: str, replacement: str, label: str) -> str:
    lines = text.splitlines()
    if replacement in lines:
        return text
    matches = [index for index, line in enumerate(lines) if token in line]
    assert len(matches) == 1, (label, matches)
    lines[matches[0]] = replacement
    return "\n".join(lines) + "\n"


def assert_inputs() -> tuple[dict[str, Any], dict[str, Any], dict[str, Any]]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
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

    queue = read_json("evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json")
    overlay = read_json("evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json")
    review = read_json("evidence/source/raw-run-092r-independent-reviewed-static-source-ownership-overlay-wave-11.json")
    assert queue["record_set"]["count"] == 507
    assert overlay["combined_counts"] == review["verified_combined_counts"]
    assert overlay["queue_accounting"] == review["verified_queue_accounting"]
    assert review["decision"]["verdict"] == "GO"
    assert review["decision"]["mechanical_discrepancies"] == 0
    assert review["decision"]["semantic_boundary_discrepancies"] == 0
    assert review["decision"]["gate_4_complete"] is False
    assert overlay["baseline"]["source_owner_records"] == 530
    assert overlay["baseline"]["route_owner_records"] == 212
    assert overlay["baseline"]["page_owner_records"] == 318
    assert overlay["baseline"]["distinct_feature_ids"] == 235
    assert overlay["credit_boundary"]["STATIC_SOURCE_FEATURE_OWNERSHIP"] is True
    assert overlay["credit_boundary"]["STATIC_CONTROLLER_ACTION_BRIDGE"] is True
    assert overlay["credit_boundary"]["two_shared_relations_as_one_to_one_ownership"] is False
    assert overlay["credit_boundary"]["wholesale_507_queue_ownership"] is False
    return queue, overlay, review


def patch_reports() -> None:
    summary_relative = "00-executive-summary.md"
    summary = path(summary_relative).read_text(encoding="utf-8")
    section = """
## RUN-089–092 reviewed closed-chain ownership overlay

RUN-089 repeats the owner-designated application access preflight. The controlled tab again observes only the public home and signed-out login: the earlier user login is not present in that tab, no credential value is read or entered, no form is submitted, and no build, environment, representative role, approved Site, or resettable-fixture identity is established. This grants public/login observation only and no signed-in application-browser or current-source attribution credit.

RUN-090 deterministically freezes a 507-row direct-exact review queue from the prior zero-credit candidate census. The queue is a prioritisation aid, not ownership: at RUN-090 all 504 route rows and 3 page rows received zero ownership credit, with later review reported below.

RUN-091 narrows that queue to 11 closed route → exact controller method → sole literal page → singleton-feature chains. Three fresh partition reviews classify nine as `OWNER_CHAIN` and two as `SHARED_RELATION`: the Milesight page also directly implements provider synchronisation, and the resource-calendar settings page also directly initiates calendar synchronisation. RUN-091R therefore authorizes exactly 18 source-owner records and nine controller-action bridges, while rejecting the all-11 ownership projection.

RUN-092 integrates only those nine accepted chains, and RUN-092R independently performs one mechanical reconstruction plus one semantic-boundary review with zero discrepancies and no reviewer writes. The current bounded source-owner checkpoint is **548 records = 221 routes + 327 pages across 239 canonical FEATURE-IDs**, with nine controller-action bridges. This is 13.947569% of the bounded 3,929-record RUN-077 source universe; 3,381 records remain, including 2,992 explicit-unmapped routes, five semantic-shared routes, 382 unadjudicated page roots, and two semantic-shared page roots. Of the 507-row queue, 12 surfaces have been reviewed (10 owned and two shared), 495 remain unreviewed, and 497 remain without ownership.

Only bounded `STATIC_SOURCE_FEATURE_OWNERSHIP` and `STATIC_CONTROLLER_ACTION_BRIDGE` are credited. The framework-expanded denominator, complete crosswalk, route reachability, navigation, Site/permission/privacy behaviour, runtime, database, build, signed-in browser, executed tests, benchmarks, ease, Passes, findings, and completion remain open. Gate 4 is false and the 340-row matrix remains byte-identical at `dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390`.
"""
    summary = upsert_section_before(
        summary,
        "## RUN-089–092 reviewed closed-chain ownership overlay",
        "\n## Current raw source census\n",
        section,
        "RUN-089–092 section",
    )
    summary = replace_once_or_present(
        summary,
        "RUN-084's current designated-application access preflight",
        "RUN-084's historical, now-superseded designated-application access preflight",
        "historical RUN-084 application preflight",
    )
    summary = replace_once_or_present(
        summary,
        "`evidence/browser/current-designated-application-access-preflight-run-084-wave-09.json`: current public/login signed-out preflight",
        "`evidence/browser/current-designated-application-access-preflight-run-084-wave-09.json`: historical, now-superseded public/login signed-out preflight",
        "historical RUN-084 evidence label",
    )
    evidence_marker = "- `evidence/source/current-run-087-static-source-feature-ownership-reporting.json`: deterministic RUN-087 reporting receipt; matrix and every execution, benchmark, Pass, finding, and completion boundary remain unchanged.\n"
    evidence_addition = evidence_marker + (
        "- `evidence/browser/current-audit-dashboard-verification-run-088-wave-10.json`: exact four-viewport verification of the now-superseded RUN-087 dashboard artifact; zero application credit.\n"
        "- `evidence/browser/current-designated-application-access-preflight-run-089-wave-11.json`: current public/login signed-out preflight with no credential use, mutation, screenshots, build attribution, or application-browser credit.\n"
        "- `generators/build-direct-exact-route-page-review-queue-wave-11.py` and `evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json`: deterministic 507-row zero-credit review queue and exact pins.\n"
        "- `generators/build-closed-route-action-page-chain-cohort-wave-11.py`, `evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json`, and `evidence/source/raw-run-091r-independent-closed-route-action-page-chain-review-wave-11.json`: 11 closed-chain candidates and the fresh 9-owner / 2-shared independent decision.\n"
        "- `generators/integrate-reviewed-static-source-ownership-overlay-wave-11.py`, `evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json`, and `evidence/source/raw-run-092r-independent-reviewed-static-source-ownership-overlay-wave-11.json`: exact 18-row / 9-bridge overlay and two-part independent GO review.\n"
        "- `evidence/source/current-run-093-reviewed-owner-chain-reporting-wave-11.json`: deterministic RUN-093 reporting receipt preserving the matrix, reports 02–12/inventory, and every downstream zero-credit boundary.\n"
    )
    summary = replace_once_or_present(summary, evidence_marker, evidence_addition, "new evidence links")
    write_lf(summary_relative, summary)

    map_relative = "01-repository-module-map.md"
    module_map = path(map_relative).read_text(encoding="utf-8")
    map_section = """
## RUN-090–092 direct-exact queue and reviewed owner-chain overlay

RUN-090 freezes 507 zero-credit direct-exact candidate surfaces: 504 routes and 3 pages across 79 candidate FEATURE-IDs. It deliberately selects no disjoint, partial, or shared relation and grants no ownership. RUN-091 then closes controller resolution, exact method containment, sole literal render, page identity, and singleton matrix projection for 11 high-confidence chains.

Complete semantic review accepts nine chains and excludes two shared chains. RUN-092 adds 9 route owners, 9 page owners, and 9 controller-action bridges with immutable source/blob/content/method-slice and row digests. The combined bounded overlay is 548 owner records (221 route + 327 page) across 239 FEATURE-IDs; four feature IDs are new to the prior 235-ID owner set. The route and page owner sets span 36 and 234 FEATURE-IDs respectively, with 31 in their overlap.

The two excluded chains remain explicit `SHARED_RELATION` records: the Milesight connection/settings page also implements provider-sync behaviour, and the resource-calendar connection/settings page also implements calendar-sync behaviour. The queue remains 495 rows unreviewed and 497 rows without ownership. These closed-chain bridges do not imply framework reachability, navigation, runtime, build, Site/permission/privacy correctness, tests, mapping completeness, benchmark equivalence, findings, Passes, or completion.
"""
    module_map = upsert_section_before(
        module_map,
        "## RUN-090–092 direct-exact queue and reviewed owner-chain overlay",
        "\n## Candidate register\n",
        map_section,
        "RUN-090–092 map section",
    )
    write_lf(map_relative, module_map)

    gaps_relative = "13-unresolved-questions-and-evidence-gaps.md"
    gaps = path(gaps_relative).read_text(encoding="utf-8")
    gaps = replace_line_containing(
        gaps, "| Required reporting paths |",
        "| Required reporting paths | 18 / 18 prompt-required files or directories present. RUN-088 independently verified the exact now-superseded RUN-087 dashboard at four viewports with no page overflow, duplicate authored IDs, or console warnings/errors; the current RUN-093 dashboard has a separate RUN-094 artifact receipt. | Presence and audit-artifact verification make the reporting contract inspectable but grant no application execution, final-finding, Pass, or completion credit. Gate 26 remains open. | Complete the semantic, execution, benchmark, Pass 8, final reconciliation, and no-live-agent gates; repeat audit-dashboard verification after every later HTML change. |",
        "required paths row",
    )
    gaps = replace_line_containing(
        gaps, "| Runtime routes |",
        "| Runtime routes | RUN-092/R establish 221 bounded route-owner records and nine static controller-action bridges; 2,992 explicit-unmapped route rows and five semantic-shared route rows remain within the bounded 3,218-row static route-like review universe | Static owner/action linkage is not a framework-expanded route table or reachability proof. Missing vendor autoload/route cache keeps framework runtime NO-GO, and the historical 3,024-route denominator cannot be inherited. | Hydrate dependencies only under a fresh bounded runtime grant, use an exact disposable database, execute a separately pinned framework/provider route lane, and reconcile it to all 38 source route files and 3,218 route-like rows. |",
        "runtime routes row",
    )
    gaps = replace_line_containing(
        gaps, "| Inertia pages |",
        "| Inertia pages | RUN-084/R independently enumerate 1,058 physical page-tree files. RUN-092/R raise bounded page-root ownership to 327 records while 382 page roots remain unadjudicated and two reviewed roots remain semantic-shared. | Full-tree structural GO and bounded ownership are not a complete canonical crosswalk, runtime reachability, build resolution, rendered browser behavior, or final feature mapping. Historical wording that called the 25 non-roots resolver-imported remains superseded. | Reconcile all 711 literal roots and support relations to safely expanded framework routes and frozen FEATURE-IDs, retain shared relations explicitly, prove build resolution, and observe every safely reachable current-build page under approved roles/Sites. |",
        "pages row",
    )
    gaps = replace_line_containing(
        gaps, "| Canonical features |",
        "| Canonical features | Static canonical identity remains 340 targets: 300 H, 40 D, zero M; RUN-092/R establish 548 bounded source-owner records (221 routes + 327 pages) across 239 FEATURE-IDs plus nine controller-action bridges while the matrix remains byte-identical at `dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390` | This is 13.947569% of the bounded 3,929-record source universe. Gate 4 remains incomplete: 3,381 residual records, the framework-expanded denominator, shared relations, reachability, and the full crosswalk remain open; matrix target mapping stays 0/340. | Finish the canonical denominator and residual ownership adjudication without inheriting FEATURE-IDs from prefixes, imports, containment, names, or presence, and without awarding runtime, browser, test, benchmark, ease, release, or completion credit. |",
        "canonical row",
    )
    gaps = replace_line_containing(
        gaps, "| Browser route coverage |",
        "| Browser route coverage | 0 current-source signed-in routes credited. Historical RUN-058 contains six routes / 24 route-viewport cells on an unknown build; current RUN-089 observed only public home and signed-out login. | RUN-058 lacks build/actor/Site/fixture identity. RUN-089 confirms the user's earlier login did not persist in the controlled tab and records no credentials, submissions, private records, screenshots, environment marker, or build attribution. Neither boundary supplies application-browser credit. | Provide an authoritative non-production build identity and a manually signed-in controlled demo session, then prove safe representative actor/Site/resettable-fixture/cleanup coverage, independently resample the two provisional candidates, and observe every safely reachable route. |",
        "browser row",
    )
    gaps = replace_line_containing(
        gaps, "| Agent universe and writer rule |",
        "| Agent universe and writer rule | RUN-001 through RUN-093 represented at the current reporting checkpoint; finalization gate false | RUN-090 queues 507 candidate surfaces without ownership, RUN-091/R accept nine chains and retain two shared, RUN-092/R independently integrate 18 owner rows plus nine bridges, and RUN-093 reports only those bounded classes. Runtime, browser, tests, benchmarks, Pass 8, finalization, and completion remain open. | Complete residual ownership and every semantic/execution gate, then dispatch fresh Pass 8/final cross-reviewers, verify the final dashboard, and prove no agent remains live at finalization. |",
        "agent row",
    )
    gaps = replace_once_or_present(gaps, "## RUN-077–087 route/page, page-tree, backend, ownership and reporting lineage", "## RUN-077–093 route/page, page-tree, backend, ownership and reporting lineage", "lineage heading")
    gaps = replace_line_containing(
        gaps, "RUN-077 freezes the exhaustive committed-source route/name/page universe.",
        "RUN-077 freezes the exhaustive committed-source route/name/page universe. RUN-078 records all 3,218 route-like, 3,245 name, and 711 page decisions. RUN-079's cyclic A→B, B→C, C→A independent reviews are all GO with zero invalid decisions and no writes. RUN-080 integrates only 78 route-name and 2 page-file fields; RUN-081 materializes those reports and hashes. RUN-082/R add and independently reproduce zero-credit candidate relations plus 38/38 static route-file registration closure. RUN-083 refreshes and verifies reporting; RUN-084/R close the 1,058-file page-tree structural ledger; RUN-084B/BR close the 1,789-role-row backend structural ledger with zero whole-file semantic reviews; RUN-085 refreshes reporting; RUN-086/R establish the first 530 bounded source-owner records; and RUN-087 reports them. RUN-089 repeats the signed-out/build-unattributed application preflight. RUN-090 freezes a 507-row zero-credit review queue. RUN-091/R accept nine closed chains and retain two as shared. RUN-092/R integrate and independently reproduce 18 additional owner records plus nine controller-action bridges, yielding 548 owners across 239 FEATURE-IDs, and RUN-093 reports only that bounded overlay. Gate 4 and the full route/page/backend crosswalk remain incomplete; framework reachability, runtime, build, signed-in application browser, executed tests, benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit.",
        "lineage paragraph",
    )
    write_lf(gaps_relative, gaps)


def patch_findings(overlay: dict[str, Any], review: dict[str, Any]) -> None:
    relative = "findings.json"
    findings = read_json(relative)
    records_hash = canonical_json_sha256(findings["records"])
    findings["pins"].update({
        "run_089_designated_application_preflight_sha256": PINNED_INPUTS["evidence/browser/current-designated-application-access-preflight-run-089-wave-11.json"],
        "run_090_direct_exact_review_queue_sha256": PINNED_INPUTS["evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json"],
        "run_091_closed_chain_cohort_sha256": PINNED_INPUTS["evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json"],
        "run_091r_closed_chain_review_sha256": PINNED_INPUTS["evidence/source/raw-run-091r-independent-closed-route-action-page-chain-review-wave-11.json"],
        "run_092_reviewed_ownership_overlay_sha256": PINNED_INPUTS["evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json"],
        "run_092r_reviewed_ownership_overlay_review_sha256": PINNED_INPUTS["evidence/source/raw-run-092r-independent-reviewed-static-source-ownership-overlay-wave-11.json"],
    })
    counts = overlay["combined_counts"]
    findings["counts"].update({
        "static_source_feature_ownership_records": counts["source_owner_records"],
        "static_source_feature_ownership_route_records": counts["route_owner_records"],
        "static_source_feature_ownership_page_records": counts["page_owner_records"],
        "static_source_feature_ownership_distinct_feature_ids": counts["distinct_feature_ids"],
        "static_controller_action_bridges": counts["static_controller_action_bridges"],
        "bounded_static_source_ownership_percent": counts["bounded_static_source_ownership_percent"],
        "bounded_static_source_residual_records": counts["bounded_static_source_residual_records"],
        "direct_exact_queue_records": overlay["queue_accounting"]["direct_exact_queue_records"],
        "direct_exact_queue_pending_unreviewed": overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"],
    })
    findings["current_designated_application_access_preflight"] = {
        "run_id": "RUN-089-DESIGNATED-APPLICATION-ACCESS-PREFLIGHT",
        "status": "BLOCKED_AUTHENTICATION_AND_BUILD_ATTRIBUTION_CONTINUE_STATIC_AUDIT",
        "observed_states": ["PUBLIC_HOME", "SIGNED_OUT_LOGIN_FORM"],
        "current_browser_session_authenticated": False,
        "earlier_user_login_persisted_in_controlled_tab": False,
        "visible_environment_or_build_marker": "NOT_PRESENT",
        "credentials_read_or_entered": False,
        "forms_submitted": False,
        "screenshots_retained": False,
        "application_browser_credit": False,
        "runtime_credit": False,
        "test_credit": False,
        "pass_credit": False,
        "completion_credit": False,
    }
    findings["current_direct_exact_route_page_review_queue"] = {
        "run_id": "RUN-090-DIRECT-EXACT-ROUTE-PAGE-REVIEW-QUEUE-WAVE-11",
        "status": "CANDIDATE_QUEUE_PARTIALLY_REVIEWED_NO_WHOLESALE_OWNERSHIP_CREDIT",
        "records": 507,
        "reviewed_queue_surfaces": 12,
        "owned_queue_surfaces": 10,
        "shared_queue_surfaces": 2,
        "pending_unreviewed": 495,
        "without_ownership": 497,
        "wholesale_ownership_authorized": False,
    }
    findings["current_static_source_feature_ownership"] = {
        "run_id": overlay["run_id"],
        "review_run_id": review["run_id"],
        "status": "GO_REVIEWED_BOUNDED_STATIC_SOURCE_OWNERSHIP_AND_ACTION_BRIDGES_ONLY",
        "baseline_records": overlay["baseline"]["source_owner_records"],
        "overlay_source_records": len(overlay["overlay_source_records"]),
        "owner_chains_added": 9,
        "shared_relation_chains_retained_unowned": 2,
        "source_owner_records": counts["source_owner_records"],
        "route_owner_records": counts["route_owner_records"],
        "page_owner_records": counts["page_owner_records"],
        "distinct_feature_ids": counts["distinct_feature_ids"],
        "static_controller_action_bridges": counts["static_controller_action_bridges"],
        "bounded_denominator": counts["bounded_static_source_denominator"],
        "bounded_ownership_percent": counts["bounded_static_source_ownership_percent"],
        "bounded_residual_records": counts["bounded_static_source_residual_records"],
        "queue_accounting": overlay["queue_accounting"],
        "independent_review_discrepancies": 0,
        "gate_4": {"status": "PARTIAL_BOUNDED_STATIC_SOURCE_OWNERSHIP_INCOMPLETE", "complete": False},
        "credit_boundary": overlay["credit_boundary"],
    }
    assert canonical_json_sha256(findings["records"]) == records_hash
    write_lf(relative, json.dumps(findings, ensure_ascii=False, indent=2) + "\n")


def patch_dashboard_generator() -> None:
    relative = "generators/build-current-audit-dashboard.py"
    text = path(relative).read_text(encoding="utf-8")
    load_marker = 'static_source_ownership_review = read_json("evidence/source/raw-run-086r-independent-reviewed-static-route-page-feature-ownership-wave-10.json")\n'
    load_addition = load_marker + (
        'current_designated_app_preflight = read_json("evidence/browser/current-designated-application-access-preflight-run-089-wave-11.json")\n'
        'direct_exact_review_queue = read_json("evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json")\n'
        'closed_chain_cohort = read_json("evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json")\n'
        'closed_chain_review = read_json("evidence/source/raw-run-091r-independent-closed-route-action-page-chain-review-wave-11.json")\n'
        'reviewed_owner_overlay = read_json("evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json")\n'
        'reviewed_owner_overlay_review = read_json("evidence/source/raw-run-092r-independent-reviewed-static-source-ownership-overlay-wave-11.json")\n'
    )
    text = replace_once_or_present(text, load_marker, load_addition, "load RUN-089–092R")

    assertion_marker = "\n\ncandidates = wave1[\"candidates\"] + wave2[\"candidates\"] + wave3[\"candidates\"]\n"
    assertions = f"""

assert sha256_file("evidence/browser/current-designated-application-access-preflight-run-089-wave-11.json") == "{PINNED_INPUTS['evidence/browser/current-designated-application-access-preflight-run-089-wave-11.json']}"
assert sha256_file("generators/build-direct-exact-route-page-review-queue-wave-11.py") == "{PINNED_INPUTS['generators/build-direct-exact-route-page-review-queue-wave-11.py']}"
assert sha256_file("evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json") == "{PINNED_INPUTS['evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json']}"
assert sha256_file("generators/build-closed-route-action-page-chain-cohort-wave-11.py") == "{PINNED_INPUTS['generators/build-closed-route-action-page-chain-cohort-wave-11.py']}"
assert sha256_file("evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json") == "{PINNED_INPUTS['evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json']}"
assert sha256_file("evidence/source/raw-run-091r-independent-closed-route-action-page-chain-review-wave-11.json") == "{PINNED_INPUTS['evidence/source/raw-run-091r-independent-closed-route-action-page-chain-review-wave-11.json']}"
assert sha256_file("generators/integrate-reviewed-static-source-ownership-overlay-wave-11.py") == "{PINNED_INPUTS['generators/integrate-reviewed-static-source-ownership-overlay-wave-11.py']}"
assert sha256_file("evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json") == "{PINNED_INPUTS['evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json']}"
assert sha256_file("evidence/source/raw-run-092r-independent-reviewed-static-source-ownership-overlay-wave-11.json") == "{PINNED_INPUTS['evidence/source/raw-run-092r-independent-reviewed-static-source-ownership-overlay-wave-11.json']}"
assert current_designated_app_preflight["run_id"] == "RUN-089-DESIGNATED-APPLICATION-ACCESS-PREFLIGHT"
assert current_designated_app_preflight["access_preflight"]["current_browser_session_authenticated"] is False
assert current_designated_app_preflight["mutation_attestation"]["application_or_external_state_changed"] is False
assert direct_exact_review_queue["record_set"]["count"] == 507
assert closed_chain_cohort["counts"]["chains"] == 11
assert closed_chain_review["decision"]["owner_chains"] == 9
assert closed_chain_review["decision"]["shared_relation_chains"] == 2
assert reviewed_owner_overlay["combined_counts"]["source_owner_records"] == 548
assert reviewed_owner_overlay["combined_counts"]["route_owner_records"] == 221
assert reviewed_owner_overlay["combined_counts"]["page_owner_records"] == 327
assert reviewed_owner_overlay["combined_counts"]["distinct_feature_ids"] == 239
assert reviewed_owner_overlay["combined_counts"]["static_controller_action_bridges"] == 9
assert reviewed_owner_overlay["combined_counts"]["bounded_static_source_residual_records"] == 3381
assert reviewed_owner_overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 495
assert reviewed_owner_overlay["queue_accounting"]["queue_surfaces_without_ownership"] == 497
assert reviewed_owner_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_owner_overlay_review["decision"]["mechanical_discrepancies"] == 0
assert reviewed_owner_overlay_review["decision"]["semantic_boundary_discrepancies"] == 0
assert reviewed_owner_overlay_review["decision"]["gate_4_complete"] is False
assert reviewed_owner_overlay["credit_boundary"]["two_shared_relations_as_one_to_one_ownership"] is False
assert reviewed_owner_overlay["credit_boundary"]["complete_route_page_feature_crosswalk"] is False
assert all(
    reviewed_owner_overlay["credit_boundary"][key] is False
    for key in (
        "framework_route_reachability", "navigation", "runtime", "database", "build",
        "application_browser", "executed_tests", "benchmark", "ease", "pass",
        "final_finding", "completion", "audit_complete",
    )
)
"""
    text = insert_before_once(text, assertion_marker, assertions, "assert RUN-089–092R")

    evidence_marker = '    ("RUN-087 ownership reporting/hash receipt", "evidence/source/current-run-087-static-source-feature-ownership-reporting.json"),\n'
    evidence_addition = evidence_marker + (
        '    ("RUN-088 verified superseded dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-088-wave-10.json"),\n'
        '    ("RUN-089 current designated-application signed-out preflight", "evidence/browser/current-designated-application-access-preflight-run-089-wave-11.json"),\n'
        '    ("RUN-090 direct-exact review-queue generator", "generators/build-direct-exact-route-page-review-queue-wave-11.py"),\n'
        '    ("RUN-090 507-row zero-credit direct-exact queue", "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json"),\n'
        '    ("RUN-091 closed-chain cohort generator", "generators/build-closed-route-action-page-chain-cohort-wave-11.py"),\n'
        '    ("RUN-091 11-chain review cohort", "evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json"),\n'
        '    ("RUN-091R three-part 9-owner / 2-shared review", "evidence/source/raw-run-091r-independent-closed-route-action-page-chain-review-wave-11.json"),\n'
        '    ("RUN-092 reviewed-owner overlay generator", "generators/integrate-reviewed-static-source-ownership-overlay-wave-11.py"),\n'
        '    ("RUN-092 18-row / 9-bridge ownership overlay", "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json"),\n'
        '    ("RUN-092R two-part independent overlay review", "evidence/source/raw-run-092r-independent-reviewed-static-source-ownership-overlay-wave-11.json"),\n'
        '    ("RUN-093 reviewed-owner reporting materializer", "generators/materialize-run-093-reviewed-owner-chain-reporting-wave-11.py"),\n'
        '    ("RUN-093 reviewed-owner reporting/hash receipt", "evidence/source/current-run-093-reviewed-owner-chain-reporting-wave-11.json"),\n'
    )
    text = replace_once_or_present(text, evidence_marker, evidence_addition, "RUN-089–093 evidence")

    old_current_queue_boundary = "zero ownership credit · $queue_pending unreviewed · $queue_without_owner without ownership"
    new_current_queue_boundary = "queue itself grants no wholesale ownership · current overlay: 10 owned · 2 shared · $queue_pending unreviewed · $queue_without_owner without ownership"
    if new_current_queue_boundary not in text:
        assert text.count(old_current_queue_boundary) <= 1
        text = text.replace(old_current_queue_boundary, new_current_queue_boundary, 1)

    old_current_queue_census = '<tr><td>RUN-090 direct-exact queue</td><td>$queue_records total · $queue_pending unreviewed · $queue_without_owner without ownership</td><td class="partial">candidate prioritisation only · no wholesale ownership</td></tr>'
    new_current_queue_census = '<tr><td>RUN-090 direct-exact queue</td><td>$queue_records total · current overlay: 10 owned · 2 shared · $queue_pending unreviewed · $queue_without_owner without ownership</td><td class="partial">candidate prioritisation only · queue itself grants no wholesale ownership</td></tr>'
    if new_current_queue_census not in text:
        assert text.count(old_current_queue_census) <= 1
        text = text.replace(old_current_queue_census, new_current_queue_census, 1)

    replacements = [
        ('  <meta name="viewport" content="width=device-width,initial-scale=1">\n  <title>', '  <meta name="viewport" content="width=device-width,initial-scale=1">\n  <link rel="icon" href="data:,">\n  <title>', "inline favicon"),
        ('<a href="#checkpoint">RUN-087</a>', '<a href="#checkpoint">RUN-093</a>', "nav"),
        ("RUN-084's current designated-application preflight is signed out and build-unattributed. RUN-086/R independently establish $static_owner_records bounded static source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs; Gate 4 and the complete crosswalk remain open.", "RUN-089 confirms the controlled application tab is still signed out and build-unattributed. RUN-091/R accept nine closed chains and reject two shared relations; RUN-092/R independently establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges. $static_residual records remain and Gate 4 is open.", "primary notice"),
        ("<strong>RUN-071–087 current reporting checkpoint:</strong>", "<strong>RUN-071–093 current reporting checkpoint:</strong>", "checkpoint notice"),
        ("<h2>RUN-071–087 completion-gate checkpoint</h2>", "<h2>RUN-071–093 completion-gate checkpoint</h2>", "checkpoint heading"),
        ("RUN-086/R add $static_owner_records bounded static source-owner records only; the framework-expanded denominator, residual ownership, and full route/page/backend crosswalk remain open.", "RUN-090 freezes $queue_records candidate rows without ownership. RUN-091/R accept nine owner chains and retain two shared; RUN-092/R raise bounded ownership to $static_owner_records records and $static_action_bridges action bridges, with $queue_pending queue rows unreviewed and $queue_without_owner without ownership. The framework-expanded denominator, residual ownership, and full route/page/backend crosswalk remain open.", "checkpoint boundary"),
        ("RUN-086/R add three-part independently reviewed bounded source ownership, and RUN-087 refreshes current reporting.", "RUN-086/R add the initial independently reviewed bounded ownership, RUN-089 repeats the signed-out preflight, RUN-090–092/R queue, review, integrate, and independently verify nine further closed chains while retaining two shared, and RUN-093 refreshes current reporting.", "checkpoint narrative"),
        ('<tr><td>RUN-087 reporting refresh</td><td><strong>bounded ownership overlay reported</strong></td><td class="partial">audit-only materialization · matrix and preserved reports byte-identical</td></tr>', '<tr><td>RUN-087 reporting refresh</td><td><strong>initial bounded ownership reported</strong></td><td class="partial">audit-only materialization · matrix and preserved reports byte-identical</td></tr><tr><td>RUN-089 application preflight</td><td><strong>public + signed-out login only</strong></td><td class="zero">earlier login absent in controlled tab · no credentials/forms/private records/screenshots · build and non-production identity unproved</td></tr><tr><td>RUN-090 direct-exact review queue</td><td><strong>$queue_records candidate surfaces</strong></td><td class="partial">queue itself grants no wholesale ownership · current overlay: 10 owned · 2 shared · $queue_pending unreviewed · $queue_without_owner without ownership</td></tr><tr><td>RUN-091/R → 092/R closed-chain overlay</td><td><strong>9 owner chains · 2 shared · 18 owner rows · $static_action_bridges action bridges</strong></td><td class="partial">$static_owner_records cumulative owners · $static_owner_routes routes + $static_owner_pages pages · $static_owner_features FEATURE-IDs · Gate 4 incomplete</td></tr><tr><td>RUN-093 reporting refresh</td><td><strong>reviewed overlay reported</strong></td><td class="partial">audit-only materialization · matrix and preserved reports byte-identical</td></tr>', "checkpoint rows"),
        ('<tr><td>RUN-086/R static source feature ownership</td><td><strong>$static_owner_records records · $static_owner_routes routes + $static_owner_pages pages · $static_owner_features FEATURE-IDs</strong></td>', '<tr><td>RUN-086/R static source feature ownership</td><td><strong>530 records · 212 routes + 318 pages · 235 FEATURE-IDs</strong></td>', "baseline ownership row"),
        ('<tr><td>RUN-084 designated application preflight</td>', '<tr><td>RUN-084 historical designated application preflight</td>', "historical checkpoint preflight row"),
        ('<li>RUN-084 designated application:', '<li>RUN-084 historical designated application:', "historical lineage preflight item"),
        ('<li>RUN-086/R: $static_owner_records independently reviewed bounded static source-owner records · $static_owner_routes routes + $static_owner_pages pages · $static_owner_features FEATURE-IDs · Gate 4 incomplete</li>', '<li>RUN-086/R: 530 independently reviewed bounded static source-owner records · 212 routes + 318 pages · 235 FEATURE-IDs · Gate 4 incomplete</li>', "lineage baseline ownership item"),
        ('<tr><td>Route decision classes</td>', '<tr><td>RUN-078 baseline route decision classes</td>', "baseline route decision label"),
        ('<tr><td>Page-root prompt status</td>', '<tr><td>RUN-079 baseline page-root prompt status</td>', "baseline page decision label"),
        ("RUN-001 through RUN-087 are represented by audit artifacts;", "RUN-001 through RUN-093 are represented by audit artifacts;", "wave range"),
        ("RUN-077–084B add exhaustive committed static route/name/page, full page-tree, and backend structural evidence; RUN-086/R add bounded static source ownership and RUN-087 refreshes reporting.", "RUN-077–084B add exhaustive committed static route/name/page, full page-tree, and backend structural evidence; RUN-086/R establish the initial bounded ownership, RUN-090–092/R add the independently reviewed closed-chain overlay, and RUN-093 refreshes reporting.", "census intro"),
        ('<tr><td>RUN-086/R static source FEATURE-ID ownership</td><td>$static_owner_records records · $static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs</td><td class="partial">three-part independent GO · bounded source ownership only · Gate 4 incomplete · matrix unchanged</td></tr>', '<tr><td>RUN-092/R bounded source FEATURE-ID ownership</td><td>$static_owner_records records · $static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs · $static_action_bridges action bridges</td><td class="partial">two-part independent overlay GO · $ownership_percent% of bounded 3,929 records · $static_residual residual · Gate 4 incomplete · matrix unchanged</td></tr><tr><td>RUN-090 direct-exact queue</td><td>$queue_records total · current overlay: 10 owned · 2 shared · $queue_pending unreviewed · $queue_without_owner without ownership</td><td class="partial">candidate prioritisation only · queue itself grants no wholesale ownership</td></tr>', "census rows"),
        ('<tr><td>RUN-084 designated-application preflight</td><td>Public home and signed-out login only at 1280×720; no credentials, submissions, private records, screenshots, environment marker, or build attribution; login overflow 0 and console warnings/errors 0/0</td><td class="zero">Signed-in application, role/Site, route/workflow, responsive-family, runtime, test, Pass, and completion credit all zero</td></tr>', '<tr><td>RUN-089 designated-application preflight</td><td>Public home and signed-out login only; the earlier user login did not persist in the controlled tab; no credentials, submissions, private records, screenshots, environment marker, or build attribution</td><td class="zero">Signed-in application, role/Site, route/workflow, responsive-family, runtime, test, Pass, and completion credit all zero</td></tr>', "runtime preflight row"),
        ("RUN-086/R establish $static_owner_records bounded static source-owner records; complete the framework-expanded canonical route/page denominator, the 3 shared relations, residual ownership, full crosswalk, and route reachability before Gate 4 can close", "RUN-092/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges; complete the framework-expanded canonical route/page denominator, $static_residual residual records including 5 shared routes and 2 shared pages, the full crosswalk, and route reachability before Gate 4 can close", "gate 4 list"),
        ("RUN-084 is signed out and build-unattributed, so signed-in application coverage remains 0", "RUN-089 remains signed out and build-unattributed, so signed-in application coverage remains 0", "browser gap"),
        ("RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, and RUN-085 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-087.", "RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, and RUN-088 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-093.", "prior verification"),
        ('<li><a href="evidence/browser/current-audit-dashboard-verification-run-085-wave-09.json">Superseded RUN-085 verification GO</a></li></ul>', '<li><a href="evidence/browser/current-audit-dashboard-verification-run-085-wave-09.json">Superseded RUN-085 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-088-wave-10.json">Superseded RUN-088 verification GO</a></li></ul>', "prior verification links"),
        ('<section class="panel"><h2>Fresh RUN-088 audit-dashboard verification</h2><p>The exact regenerated RUN-087 dashboard is checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-088 receipt records page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate IDs, console output, visible ownership and zero-credit boundaries, and exact dashboard/generator/reporting hashes. It verifies the audit artifact only and grants no application-browser, responsive, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-088-wave-10.json">RUN-088 responsive audit-dashboard verification receipt</a></li></ul></section>', '<section class="panel"><h2>Fresh RUN-094 audit-dashboard verification</h2><p>The exact regenerated RUN-093 dashboard is checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-094 receipt records page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible ownership/shared/queue and zero-credit boundaries, and exact dashboard/generator/reporting hashes. It verifies the audit artifact only and grants no application-browser, responsive, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-094-wave-11.json">RUN-094 responsive audit-dashboard verification receipt</a></li></ul></section>', "fresh verification"),
        ("<h2>RUN-071–087 evidence lineage</h2>", "<h2>RUN-071–093 evidence lineage</h2>", "lineage heading"),
        ("RUN-077–087 source/reporting artifact", "RUN-077–093 source/reporting artifact", "lineage text"),
        ("Generated deterministically from independently reviewed static evidence through RUN-086/R and reported in RUN-087.", "Generated deterministically from independently reviewed static evidence through RUN-092/R and reported in RUN-093.", "footer"),
        (".tmp-run087-dashboard", ".tmp-run093-dashboard", "temp name"),
    ]
    for old, new, label in replacements:
        text = replace_once_or_present(text, old, new, label)

    wave_items = "<li>RUN-087: deterministic initial bounded-ownership reporting refresh · downstream boundaries unchanged</li><li>RUN-089: current public/login signed-out preflight · no signed-in or build-attributed application credit</li><li>RUN-090: $queue_records-row direct-exact review queue · zero wholesale ownership</li><li>RUN-091/R: 11 closed chains reviewed · 9 owner · 2 shared</li><li>RUN-092/R: 18 owner rows + $static_action_bridges action bridges integrated · one independent mechanical reconstruction + one semantic-boundary review · $static_owner_records cumulative owner records</li><li>RUN-093: deterministic reviewed-overlay reporting refresh · matrix and every execution/benchmark/Pass/finding/completion boundary unchanged</li>"
    text = replace_one_of_or_present(
        text,
        (
            "<li>RUN-087: deterministic bounded-ownership reporting refresh · matrix and every execution/benchmark/Pass/finding/completion boundary unchanged</li>",
            "<li>RUN-087: deterministic initial bounded-ownership reporting refresh · downstream boundaries unchanged</li><li>RUN-089: current public/login signed-out preflight · no signed-in or build-attributed application credit</li><li>RUN-090: $queue_records-row direct-exact review queue · zero wholesale ownership</li><li>RUN-091/R: 11 closed chains reviewed · 9 owner · 2 shared</li><li>RUN-092/R: 18 owner rows + $static_action_bridges action bridges integrated and independently reproduced · $static_owner_records cumulative owner records</li><li>RUN-093: deterministic reviewed-overlay reporting refresh · matrix and every execution/benchmark/Pass/finding/completion boundary unchanged</li>",
        ),
        wave_items,
        "wave items",
    )

    old_values = (
        '    static_owner_records=static_source_ownership["record_set"]["count"],\n'
        '    static_owner_routes=static_source_ownership["counts"]["selected"]["route_records"],\n'
        '    static_owner_pages=static_source_ownership["counts"]["selected"]["page_records"],\n'
        '    static_owner_features=static_source_ownership["counts"]["selected"]["distinct_feature_ids"],\n'
    )
    new_values = (
        '    static_owner_records=reviewed_owner_overlay["combined_counts"]["source_owner_records"],\n'
        '    static_owner_routes=reviewed_owner_overlay["combined_counts"]["route_owner_records"],\n'
        '    static_owner_pages=reviewed_owner_overlay["combined_counts"]["page_owner_records"],\n'
        '    static_owner_features=reviewed_owner_overlay["combined_counts"]["distinct_feature_ids"],\n'
        '    static_action_bridges=reviewed_owner_overlay["combined_counts"]["static_controller_action_bridges"],\n'
        "    static_residual=f\"{reviewed_owner_overlay['combined_counts']['bounded_static_source_residual_records']:,}\",\n"
        '    ownership_percent=reviewed_owner_overlay["combined_counts"]["bounded_static_source_ownership_percent"],\n'
        '    queue_records=reviewed_owner_overlay["queue_accounting"]["direct_exact_queue_records"],\n'
        '    queue_pending=reviewed_owner_overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"],\n'
        '    queue_without_owner=reviewed_owner_overlay["queue_accounting"]["queue_surfaces_without_ownership"],\n'
    )
    text = replace_once_or_present(text, old_values, new_values, "substitution values")
    assert '<tr><td>RUN-086/R static source feature ownership</td><td><strong>530 records · 212 routes + 318 pages · 235 FEATURE-IDs</strong></td>' in text
    assert '<li>RUN-086/R: 530 independently reviewed bounded static source-owner records · 212 routes + 318 pages · 235 FEATURE-IDs · Gate 4 incomplete</li>' in text
    assert '<tr><td>RUN-078 baseline route decision classes</td>' in text
    assert '<tr><td>RUN-079 baseline page-root prompt status</td>' in text
    write_lf(relative, text)


def main() -> None:
    _queue, overlay, review = assert_inputs()
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
        "schema_version": "run-093-reviewed-owner-chain-reporting-wave-11-v2",
        "run_id": "RUN-093-REVIEWED-OWNER-CHAIN-REPORTING",
        "status": "REVIEWED_BOUNDED_OWNER_OVERLAY_REPORTED_GATE_4_INCOMPLETE",
        "generated_on": "2026-08-25",
        "pins": {
            "checkpoint_commit": CHECKPOINT_COMMIT,
            "checkpoint_tree": CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "matrix_sha256": MATRIX_SHA256,
            "materializer_sha256": sha256_file("generators/materialize-run-093-reviewed-owner-chain-reporting-wave-11.py"),
            "overlay_sha256": PINNED_INPUTS["evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json"],
            "independent_overlay_review_sha256": PINNED_INPUTS["evidence/source/raw-run-092r-independent-reviewed-static-source-ownership-overlay-wave-11.json"],
        },
        "inputs": {**CURRENT_REPORT_INPUTS, **PINNED_INPUTS},
        "outputs": outputs,
        "preserved": PRESERVED,
        "counts": {
            **overlay["combined_counts"],
            "direct_exact_queue_records": 507,
            "pending_unreviewed_queue_surface_rows": 495,
            "queue_surfaces_without_ownership": 497,
            "reviewed_owner_chains_added": 9,
            "shared_relation_chains_retained_unowned": 2,
            "provisional_finding_records": 12,
            "final_findings": 0,
            "matrix_rows_changed": 0,
            "matrix_cells_changed": 0,
        },
        "checks": {
            "two_part_independent_overlay_review_go": True,
            "independent_review_discrepancies": 0,
            "run_086_baseline_counts_verified": True,
            "dashboard_generator_preserves_run_086_baseline_counts": True,
            "only_nine_owner_chains_integrated": True,
            "two_shared_chains_excluded_from_ownership": True,
            "wholesale_queue_ownership_rejected": True,
            "matrix_byte_identical": True,
            "provisional_finding_record_semantics_preserved": True,
            "application_source_paths_written": 0,
            "dashboard_requires_fresh_run_094_artifact_verification": True,
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
