#!/usr/bin/env python3
"""Materialize the RUN-085 audit-only reporting refresh.

This successor leaves historical evidence immutable, updates only current
reporting surfaces, and preserves every runtime, browser, mapping, benchmark,
test, Pass, final-finding, and completion boundary at zero.
"""

from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any


AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_PATH = AUDIT_DIR / "evidence/source/current-run-085-reporting-materialization-wave-09.json"

APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"

PRESERVED = {
    "02-eight-pass-coverage-ledger.csv": "ee4dc3126113884b4b8661dc3a3d13ac6a61b9661b2cace58fe82dcbe1d2a4a6",
    "03-feature-to-benchmark-matrix.csv": MATRIX_SHA256,
    "04-workflow-usability-scorecard.csv": "ea6879340229541c198b5ac654bde6d26d38eaefdd29ff66e1026263f9546faa",
    "05-browser-visual-coverage-matrix.csv": "564224d295f8a2d3bad6001b74743fb0a1d75eb41315a817264307353b74dd84",
    "06-open-source-benchmark-register.csv": "cc493cd1807e62a9ffa27192c658400e697391b7a0baa3f0014628145c6b7b91",
    "07-module-findings.md": "5a8de7d5c9e181d8da0425e7f040e8744dd85cbfda16573ef824ce3219f85712",
    "08-cross-module-journeys.md": "ef4471ba75ac9080e4565989e4b038bf7d0ad306cad1984019882457517c853c",
    "10-architecture-data-integration-security.md": "ca5667b1c042024f32f320254baf063dd4bcd2c4b12972cf2aac29c02d782b22",
    "11-prioritised-roadmap.md": "e5c2f41bf98d3415de97d18d853f1d7c351b337ba544fbf8c81330ec63dcf02d",
    "12-native-build-and-do-not-copy-register.md": "44ae85422a6863d4804fec7d495107b9bdc937257f023767fb306ccd755e137a",
    "inventory.json": "46cd688dd9543b186a608e950754abe9e30389a792156719f8a999130dfca5fa",
}

INPUTS = {
    "evidence/browser/current-audit-dashboard-verification-run-083-wave-08.json": "e0f7f40b3d49492368ff930d163f8d677bb52f93b848a09126ab12b97a9572ef",
    "evidence/browser/current-designated-application-access-preflight-run-084-wave-09.json": "5422f40ee5663b3d4765f81d8b298e6338f6d0bcb763c761e97b06afa0a5effd",
    "generators/build-full-inertia-page-graph-wave-09.py": "6917252a65c09cb894c0d275d00a770b0f451cb6ff26dc78fbfd2661d81c52e6",
    "evidence/source/root-run-084-full-inertia-page-graph-wave-09.json": "f3856a7a86cd236684e223713a99dd64b18df692338e5d7aba688701b7c438f9",
    "evidence/source/raw-run-084r-independent-full-inertia-page-graph-review-wave-09.json": "036394a207f6f31c336f748bae9daed75d86549529de538510374149d56f506e",
    "generators/build-run-084b-backend-semantic-classification-wave-09.py": "6996e2e9ac957af2af921346cab07edbd797c7077014ddd2e1d39272141f4fc4",
    "evidence/source/root-run-084b-backend-semantic-classification-wave-09.json": "ff1bf008d6dd9d5d478b14328415a4c8187b6e09fa9e2ef57bea8daeec7de879",
}


def path(relative: str) -> Path:
    resolved = (AUDIT_DIR / relative).resolve()
    assert resolved.is_relative_to(AUDIT_DIR.resolve()), relative
    return resolved


def sha256_file(relative: str) -> str:
    target = path(relative)
    assert target.is_file(), relative
    return hashlib.sha256(target.read_bytes()).hexdigest()


def read_json(relative: str) -> dict[str, Any]:
    return json.loads(path(relative).read_bytes().decode("utf-8"))


def write_lf(relative: str, text: str) -> None:
    assert "\r" not in text
    path(relative).write_bytes(text.encode("utf-8"))


def replace_once_or_present(text: str, old: str, new: str, label: str) -> str:
    if new in text:
        return text
    if old in text:
        assert text.count(old) == 1, (label, text.count(old))
        return text.replace(old, new, 1)
    raise AssertionError(f"Neither old nor current text found: {label}")


def patch_text(relative: str, replacements: list[tuple[str, str, str]]) -> None:
    text = path(relative).read_bytes().decode("utf-8")
    assert "\r" not in text, relative
    for old, new, label in replacements:
        text = replace_once_or_present(text, old, new, f"{relative}:{label}")
    write_lf(relative, text)


def keep_one_occurrence(relative: str, literal: str, label: str) -> None:
    text = path(relative).read_bytes().decode("utf-8")
    occurrences = text.count(literal)
    assert occurrences >= 1, f"Missing dedupe literal: {relative}:{label}"
    while text.count(literal) > 1:
        offset = text.rfind(literal)
        text = text[:offset] + text[offset + len(literal):]
    write_lf(relative, text)


def canonical_json_sha256(value: Any) -> str:
    return hashlib.sha256(
        json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
    ).hexdigest()


def assert_inputs() -> tuple[dict[str, Any], dict[str, Any], dict[str, Any], dict[str, Any], dict[str, Any]]:
    for relative, expected in {**PRESERVED, **INPUTS}.items():
        assert sha256_file(relative) == expected, relative

    page_graph = read_json("evidence/source/root-run-084-full-inertia-page-graph-wave-09.json")
    page_review = read_json("evidence/source/raw-run-084r-independent-full-inertia-page-graph-review-wave-09.json")
    backend = read_json("evidence/source/root-run-084b-backend-semantic-classification-wave-09.json")
    backend_review = read_json("evidence/source/raw-run-084br-independent-backend-semantic-classification-review-wave-09.json")
    preflight = read_json("evidence/browser/current-designated-application-access-preflight-run-084-wave-09.json")

    assert page_graph["pins"]["application_commit"] == APPLICATION_COMMIT
    assert page_graph["pins"]["application_tree"] == APPLICATION_TREE
    assert page_review["decision"] == {
        "verdict": "GO",
        "discrepancies": 0,
        "page_tree_enumeration_authorized": True,
        "static_structural_classification_authorized": True,
        "support_owner_relations_authorized_as_candidates_only": True,
        "feature_mapping_authorized": False,
        "matrix_mutation_authorized": False,
        "downstream_credit_authorized": False,
    }
    assert page_graph["denominators"]["production_partition_sum"] == 963
    assert (
        page_graph["denominators"]["literal_rendered_page_roots"],
        page_graph["denominators"]["imported_support_components"],
        page_graph["denominators"]["adjudicated_unrendered_unimported_non_roots"],
    ) == (711, 227, 25)

    assert backend["pins"]["application_commit"] == APPLICATION_COMMIT
    assert backend["pins"]["application_tree"] == APPLICATION_TREE
    assert backend["classification"]["classified_role_rows"] == 1789
    assert backend["classification"]["whole_file_semantically_reviewed"] == 0
    assert backend_review["status"] == "GO_STATIC_BACKEND_CANDIDATE_CLASSIFICATION_ZERO_DOWNSTREAM_CREDIT"
    assert backend_review["decision"]["verdict"] == "GO"
    assert backend_review["decision"]["discrepancies"] == 0
    assert backend_review["pins"]["producer_sha256"] == INPUTS["evidence/source/root-run-084b-backend-semantic-classification-wave-09.json"]
    assert backend_review["pins"]["generator_sha256"] == INPUTS["generators/build-run-084b-backend-semantic-classification-wave-09.py"]

    assert preflight["access_preflight"]["current_browser_session_authenticated"] is False
    assert preflight["mutation_attestation"]["application_or_external_state_changed"] is False
    assert preflight["credit_boundary"]["signed_in_application_browser"] is False
    return page_graph, page_review, backend, backend_review, preflight


def patch_reports() -> None:
    run_084_section = """## RUN-084/R/B/BR static page-tree and backend classification checkpoint

RUN-084 enumerates every physical file under the pinned Inertia page tree: 1,058 files comprising 1,007 TSX and 51 TS files. RUN-084R independently reproduces every path, Git blob, content hash, row identity, partition, and import-graph boundary with zero discrepancies. The production TSX partition is exactly **963 = 711 literal rendered roots + 227 imported support components + 25 adjudicated unrendered/unimported non-roots**; 44 test/spec/story TSX files and 51 TS helpers/tests remain separately classified. This supersedes the historical wording in `current-static-semantic-census.json` that called the 25 non-roots “resolver-imported.” The historical evidence remains immutable; the reviewed RUN-084/R artifacts are the current boundary.

RUN-084B materializes 1,789 overlapping backend role rows across 1,755 unique pinned PHP paths: 782 model, 75 policy, 735 service, 126 job, 14 event, 12 listener, and 45 outbox-related rows. Its 197 async role rows cover 189 unique paths. RUN-084BR independently reproduces the complete ledger with zero discrepancies. Every row remains the prompt-permitted `Evidence gap`; 0 whole declarations have semantic completion review, and lexical tokens remain locators rather than authorization, Site/privacy, ownership, lifecycle, reachability, runtime, test, mapping, finding, or completion proof.

RUN-084's current designated-application access preflight observed only the public home page and signed-out login page. The 1280×720 login observation had no page-level horizontal overflow and zero console warnings/errors. No credentials were read or entered, no form was submitted, no private record or screenshot was retained, and no non-production or deployed-build marker was established. Signed-in application-browser, representative role/Site, route/workflow, responsive-family, runtime, test, Pass, and completion credit remain zero.

"""
    patch_text(
        "00-executive-summary.md",
        [
            (
                "## Current raw source census\n",
                run_084_section + "## Current raw source census\n",
                "insert RUN-084 checkpoint",
            ),
            (
                "The existing signed-in deployed session at `https://oblivionfindings.com/my-day` was inspected without interaction.",
                "The historical RUN-058 signed-in unknown-build deployed session at `https://oblivionfindings.com/my-day` was inspected without interaction.",
                "distinguish historical signed-in session",
            ),
            (
                "- `evidence/source/current-route-page-candidate-reporting-materialization-wave-08.json`: RUN-083 deterministic report/hash receipt preserving the unchanged matrix and all zero-credit boundaries.\n",
                "- `evidence/source/current-route-page-candidate-reporting-materialization-wave-08.json`: RUN-083 deterministic report/hash receipt preserving the unchanged matrix and all zero-credit boundaries.\n"
                "- `evidence/browser/current-audit-dashboard-verification-run-083-wave-08.json`: exact RUN-083 audit-dashboard verification at all four required viewports, 172/172 local links, 10/10 anchors, zero duplicate IDs, and zero console warnings/errors; application-browser and downstream credit remain zero.\n"
                "- `evidence/browser/current-designated-application-access-preflight-run-084-wave-09.json`: current public/login signed-out preflight with no credentials, mutations, screenshots, build attribution, or application-browser credit.\n"
                "- `evidence/source/root-run-084-full-inertia-page-graph-wave-09.json` and `raw-run-084r-independent-full-inertia-page-graph-review-wave-09.json`: full 1,058-file page-tree graph and independent GO review, limited to static structural and candidate-relation evidence.\n"
                "- `evidence/source/root-run-084b-backend-semantic-classification-wave-09.json` and `raw-run-084br-independent-backend-semantic-classification-review-wave-09.json`: 1,789-row backend role ledger and independent GO review, with every row retained as an evidence gap and zero whole-file semantic review.\n"
                "- `evidence/source/current-run-085-reporting-materialization-wave-09.json`: deterministic current reporting/hash receipt preserving matrix, benchmark, usability, visual, inventory, reports 07/08/10/11/12, and all downstream zero-credit boundaries.\n",
                "append RUN-083–085 evidence",
            ),
            (
                "- `audit-dashboard.html`: progress dashboard generated only from current structured evidence. A fresh RUN-083 audit-artifact viewport/link/console receipt is required after publication and cannot award application-browser or downstream credit.",
                "- `audit-dashboard.html`: progress dashboard generated only from current structured evidence. RUN-083 verification is immutable history for its exact superseded HTML; the regenerated RUN-085 artifact has a separate fresh viewport/link/anchor/console receipt and cannot award application-browser or downstream credit.",
                "correct dashboard verification state",
            ),
        ],
    )
    keep_one_occurrence("00-executive-summary.md", run_084_section, "RUN-084 checkpoint")
    for evidence_line in (
        "- `evidence/browser/current-audit-dashboard-verification-run-083-wave-08.json`: exact RUN-083 audit-dashboard verification at all four required viewports, 172/172 local links, 10/10 anchors, zero duplicate IDs, and zero console warnings/errors; application-browser and downstream credit remain zero.\n",
        "- `evidence/browser/current-designated-application-access-preflight-run-084-wave-09.json`: current public/login signed-out preflight with no credentials, mutations, screenshots, build attribution, or application-browser credit.\n",
        "- `evidence/source/root-run-084-full-inertia-page-graph-wave-09.json` and `raw-run-084r-independent-full-inertia-page-graph-review-wave-09.json`: full 1,058-file page-tree graph and independent GO review, limited to static structural and candidate-relation evidence.\n",
        "- `evidence/source/root-run-084b-backend-semantic-classification-wave-09.json` and `raw-run-084br-independent-backend-semantic-classification-review-wave-09.json`: 1,789-row backend role ledger and independent GO review, with every row retained as an evidence gap and zero whole-file semantic review.\n",
        "- `evidence/source/current-run-085-reporting-materialization-wave-09.json`: deterministic current reporting/hash receipt preserving matrix, benchmark, usability, visual, inventory, reports 07/08/10/11/12, and all downstream zero-credit boundaries.\n",
    ):
        keep_one_occurrence("00-executive-summary.md", evidence_line, "RUN-083–085 evidence line")

    map_section = """## RUN-084/R/B/BR full page-tree and backend role overlay

The independently reviewed current page-tree census contains 1,058 physical files: 1,007 TSX and 51 TS. The 963 production TSX paths partition exactly as 711 literal rendered roots, 227 imported supports, and 25 adjudicated unrendered/unimported non-roots. This current boundary explicitly supersedes older “resolver-imported” wording for the 25-path cohort while preserving the historical evidence bytes. Page/import structure and support-owner relations remain static candidates only; no FEATURE-ID inheritance is permitted.

The independently reviewed backend structural ledger contains 1,789 overlapping role rows over 1,755 unique paths: 782 models, 75 policies, 735 services, 126 jobs, 14 events, 12 listeners, 45 outbox-related rows, and 197 async role rows over 189 paths. Every role row is classified `Evidence gap`, and whole-file semantic review remains 0. These denominators establish inspectable source queues only; canonical ownership, action/Site/privacy semantics, runtime, tests, feature mapping, benchmark mapping, findings, Pass, and completion remain open.

"""
    patch_text(
        "01-repository-module-map.md",
        [
            (
                "## Candidate register\n",
                map_section + "## Candidate register\n",
                "insert current page/backend overlay",
            )
        ],
    )
    keep_one_occurrence("01-repository-module-map.md", map_section, "page/backend overlay")

    browser_section = """## RUN-084 current designated-application access preflight

The current controlled session is signed out. A navigation-only preflight observed the public home page and the login form; the login view was checked at 1280×720 with no page-level horizontal overflow and zero console warnings/errors. No credentials were read or entered, no form was submitted, no private record was opened, and no screenshot was retained. The target exposed no independently proven non-production marker or deployed commit/release identity.

This is public/login access evidence only. Signed-in application routes, representative role/Site behavior, responsive families, journeys, workflows, ease, rendered current-source visuals, runtime, tests, Pass, and completion all remain unobserved and zero-credit.

"""
    patch_text(
        "09-ui-ux-accessibility-visual-consistency.md",
        [
            (
                "## Provisional pattern risks requiring attributable resampling\n",
                browser_section + "## Provisional pattern risks requiring attributable resampling\n",
                "insert RUN-084 current browser preflight",
            )
        ],
    )
    keep_one_occurrence("09-ui-ux-accessibility-visual-consistency.md", browser_section, "current browser preflight")

    required_old = "| Required reporting paths | 18 / 18 prompt-required files or directories present; RUN-081 dashboard verification is immutable history for superseded HTML, and the RUN-083 generated dashboard requires a fresh audit-artifact viewport/link/console receipt | Presence and audit-artifact verification make the reporting contract inspectable but grant no application execution, final-finding, Pass, or completion credit. Gate 26 remains open. | Complete the semantic, execution, benchmark, Pass 8, final reconciliation, and no-live-agent gates; repeat audit-dashboard verification after every later HTML change. |"
    required_new = "| Required reporting paths | 18 / 18 prompt-required files or directories present. RUN-083 independently verified its exact now-superseded dashboard at 4/4 viewports, 172/172 local links, 10/10 anchors, zero duplicate IDs, and zero console warnings/errors; regenerated RUN-085 HTML has a separate fresh receipt. | Presence and audit-artifact verification make the reporting contract inspectable but grant no application execution, final-finding, Pass, or completion credit. Gate 26 remains open. | Complete the semantic, execution, benchmark, Pass 8, final reconciliation, and no-live-agent gates; repeat audit-dashboard verification after every later HTML change. |"
    pages_old = "| Inertia pages | The exact 393 RUN-078 page evidence gaps now have independently reproduced render-owner candidate relations: 43 single, 2 multiple, and 348 none | RUN-082R GO is limited to candidate-only static evidence; exact line containment is not canonical ownership, runtime reachability, build resolution, rendered browser behavior, or final feature mapping. | Adjudicate candidate ownership separately, reconcile all 711 roots to safely expanded framework routes and frozen feature IDs, prove build resolution, and observe every safely reachable current-build page under approved roles/Sites. |"
    pages_new = "| Inertia pages | RUN-084/R independently enumerate and review 1,058 physical page-tree files. The 963 production TSX paths partition as 711 literal rendered roots + 227 imported supports + 25 adjudicated unrendered/unimported non-roots; the separate 393 RUN-078 gaps retain RUN-082R candidate relations of 43 single, 2 multiple, and 348 none. | Full-tree structural GO and exact line containment are not canonical ownership, FEATURE-ID inheritance, runtime reachability, build resolution, rendered browser behavior, or final feature mapping. Historical wording that called the 25 non-roots resolver-imported is superseded, not rewritten. | Adjudicate candidate ownership separately, reconcile all 711 roots and support relations to safely expanded framework routes and frozen feature IDs, prove build resolution, and observe every safely reachable current-build page under approved roles/Sites. |"
    backend_old = "| Backend/data/test inventory | Static denominators established: 561 controllers, 735 service entries, 782 models, 75 policies, 126 jobs, 14 events, 12 listeners, 29 observers, 978 migrations, and 1,381 PHP test files | Directory and declaration scopes are orthogonal. Exact prior-candidate anchors cover only 62 controller and 54 service paths and zero model/policy/async/migration paths. Migration history and the schema dump are not current schema; source test files are not execution or coverage. The earlier lexical-case count is omitted because its counting rule was not reproducible. | Complete backend/data/test linkage for all 340 canonical targets, obtain a current schema snapshot safely, classify async/outbox/policy locators, and execute a separately bounded test lane. |"
    backend_new = "| Backend/data/test inventory | RUN-084B/BR independently enumerate and structurally review 1,789 role rows over 1,755 unique paths: 782 models, 75 policies, 735 services, 126 jobs, 14 events, 12 listeners, 45 outbox-related rows, and 197 async role rows over 189 paths. Separate census totals remain 561 controllers, 29 observers, 978 migrations, and 1,381 PHP tests. | Every backend role row remains `Evidence gap`; whole-file semantic review is 0. Directory, role, and declaration scopes overlap and are not feature ownership. Migration history/schema dump are not current schema, and source tests are not execution or coverage. | Independently review complete declarations and action/Site/privacy/lifecycle semantics, link every backend/data/test owner to all 340 canonical targets, obtain a safe current schema snapshot, and execute separately bounded runtime/test lanes. |"
    browser_old = "| Browser route coverage | 0 current-source routes credited; a separately sealed unknown-build sample contains six selected routes and 24 route/viewport cells | The retained signed-in deployed session has unknown build identity. Its assets differ from an untracked local manifest, which proves only that identity is unestablished. Actor role, approved Site, fixture safety, and environment class are also unknown. Previous 622/1,211 evidence is historical. | Provide an authoritative deployed commit/tree or reproducible build marker, prove safe actor/Site/fixture coverage, independently resample the two provisional candidates, and observe every safely reachable route at desktop width. |"
    browser_new = "| Browser route coverage | 0 current-source signed-in routes credited. Historical RUN-058 contains six routes / 24 route-viewport cells on an unknown build; current RUN-084 observed only public home and signed-out login at 1280×720. | RUN-058 lacks build/actor/Site/fixture identity. RUN-084 had no credentials, submissions, private records, screenshots, environment marker, or build attribution; its login page had zero overflow and console warnings/errors. Neither boundary supplies application-browser credit. | Provide an authoritative non-production build identity and a manually signed-in demo session, then prove safe representative actor/Site/resettable-fixture/cleanup coverage, independently resample the two provisional candidates, and observe every safely reachable route. |"
    agent_old = "| Agent universe and writer rule | RUN-001 through RUN-083 represented at the current reporting checkpoint; finalization gate false | RUN-082 deterministically materializes candidate relations and static registration closure; RUN-082R independently returns GO limited to that candidate-only static evidence with zero discrepancies; RUN-083 refreshes reports only. Neither source closure, candidate overlap, review GO, hashes, nor report presence satisfies runtime, mapping, Pass 8, finalization, or completion. | Complete ownership adjudication and all semantic/execution gates, then dispatch fresh Pass 8/final cross-reviewers, verify the final dashboard, and prove no agent remains live at finalization. |"
    agent_new = "| Agent universe and writer rule | RUN-001 through RUN-085 represented at the current reporting checkpoint; finalization gate false | RUN-082/R provide candidate-only route/page relations, RUN-083 reports and verifies its exact dashboard, RUN-084/R provide the reviewed full page tree, RUN-084B/BR provide the reviewed backend structural ledger, and RUN-084 preflight is signed out/build-unattributed. None satisfies whole-file semantics, runtime, mapping, Pass 8, finalization, or completion. | Complete ownership adjudication and all semantic/execution gates, then dispatch fresh Pass 8/final cross-reviewers, verify the final dashboard, and prove no agent remains live at finalization. |"
    lineage_append_old = "Framework/build/test/browser gates remain NO-GO and not executed, and RUN-083 refreshes reporting only. Full route/page-to-feature mapping, framework reachability, runtime, build, browser, executed tests, benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit."
    lineage_append_new = "Framework/build/test/browser gates remain NO-GO and not executed, and RUN-083 refreshes reporting only. RUN-083's exact dashboard then receives artifact-only GO; RUN-084/R independently close the 1,058-file page-tree structural ledger, RUN-084B/BR independently close the 1,789-role-row backend structural ledger while retaining 0 whole-file semantic reviews, RUN-084 records a signed-out/build-unattributed public/login preflight, and RUN-085 refreshes current reporting. Full route/page/backend-to-feature mapping, framework reachability, runtime, build, signed-in application browser, executed tests, benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit."
    patch_text(
        "13-unresolved-questions-and-evidence-gaps.md",
        [
            (required_old, required_new, "correct reporting verification"),
            (pages_old, pages_new, "full page-tree boundary"),
            (backend_old, backend_new, "backend ledger boundary"),
            (browser_old, browser_new, "browser history and current preflight"),
            (agent_old, agent_new, "agent universe"),
            ("## RUN-077–083 route/page classification, candidate census, and reporting lineage", "## RUN-077–085 route/page, page-tree, backend and reporting lineage", "lineage heading"),
            (lineage_append_old, lineage_append_new, "lineage extension"),
        ],
    )


def patch_dashboard_generator() -> None:
    evidence_old = "RUN-001 through RUN-083 are represented by audit artifacts; none grants current-source application runtime, browser, executed-test, benchmark-mapping, or completion credit."
    evidence_new = "RUN-001 through RUN-085 are represented by audit artifacts; none grants current-source application runtime, signed-in browser, executed-test, benchmark-mapping, or completion credit."
    wave_old = "<li>RUN-083: five reports refreshed · five reports byte-preserved · matrix 0 rows / 0 cells changed · zero downstream credit</li>"
    wave_new = wave_old + "<li>RUN-083 dashboard: 4/4 viewports · 172/172 local links · 10/10 anchors · zero duplicate IDs or console warnings/errors · artifact-only GO</li><li>RUN-084/R: $full_page_tree_files physical page-tree files · $full_page_production = $full_page_roots roots + $full_page_support imported supports + $full_page_nonroots adjudicated non-roots · independent GO structural/candidate evidence only</li><li>RUN-084B/BR: $backend_role_rows backend role rows · $backend_unique_paths unique paths · $backend_async_rows async role rows / $backend_async_paths paths · independent GO structural only · 0 whole-file semantic reviews</li><li>RUN-084 designated application: public home + signed-out login only · no credentials/forms/private records/screenshots · build identity unproved · 0 application-browser credit</li><li>RUN-085: deterministic reporting refresh and fresh audit-dashboard verification · matrix and all downstream credit unchanged</li>"
    static_old = "RUN-030 freezes canonical static identity; RUN-077–083 add exhaustive committed static route/name/page decision evidence."
    static_new = "RUN-030 freezes canonical static identity; RUN-077–084B add exhaustive committed static route/name/page, full page-tree, and backend structural evidence; RUN-085 refreshes reporting."
    models_old = "<tr><td>Models / policies / service entries</td><td>$models / $policies / $services</td><td class=\"partial\">directory/declaration census, not ownership completion</td></tr><tr><td>Jobs / events / listeners</td><td>$jobs / $events / $listeners</td><td class=\"partial\">static owners, no queue execution</td></tr>"
    models_new = "<tr><td>RUN-084B models / policies / service role rows</td><td>$backend_models / $backend_policies / $backend_services</td><td class=\"partial\">independently reproduced structural ledger · every row Evidence gap · 0 whole-file semantic reviews</td></tr><tr><td>RUN-084B jobs / events / listeners / outbox</td><td>$jobs / $events / $listeners / 45</td><td class=\"partial\">$backend_async_rows overlapping async role rows over $backend_async_paths paths · no queue/runtime execution</td></tr>"
    runtime_old = "<tr><td>Local PHP/runtime</td>"
    runtime_new = "<tr><td>RUN-084 designated-application preflight</td><td>Public home and signed-out login only at 1280×720; no credentials, submissions, private records, screenshots, environment marker, or build attribution; login overflow 0 and console warnings/errors 0/0</td><td class=\"zero\">Signed-in application, role/Site, route/workflow, responsive-family, runtime, test, Pass, and completion credit all zero</td></tr><tr><td>Local PHP/runtime</td>"
    gap_old = "<li>Complete route/page-to-feature mapping, framework reachability, and backend/data/test ownership</li>"
    gap_new = "<li>Complete route/page-to-feature mapping, framework reachability, and backend/data/test ownership</li><li>Adjudicate the reviewed 1,058-file page-tree graph without inheriting FEATURE-IDs through support imports; preserve $full_page_production = $full_page_roots + $full_page_support + $full_page_nonroots</li><li>Complete semantic review of all $backend_role_rows backend role rows across $backend_unique_paths paths; whole-file semantic review remains 0</li>"
    safe_browser_old = "<li>Safe current-build application browser/runtime lanes</li>"
    safe_browser_new = "<li>Safe current-build application browser/runtime lanes; RUN-084 is signed out and build-unattributed, so signed-in application coverage remains 0</li>"
    prior_old = "RUN-070, RUN-072, RUN-073, RUN-076, and RUN-081 responsive verification are immutable history for superseded HTML; no prior viewport, overflow, navigation, table, link, or console proof transfers to RUN-083."
    prior_new = "RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, and RUN-083 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-085."
    prior_links_old = "<li><a href=\"evidence/browser/current-audit-dashboard-verification-run-081-wave-07.json\">Superseded RUN-081 verification</a></li>"
    prior_links_new = prior_links_old + "<li><a href=\"evidence/browser/current-audit-dashboard-verification-run-083-wave-08.json\">Superseded RUN-083 verification GO</a></li>"
    current_verify_old = "<section class=\"panel\"><h2>Fresh RUN-083 audit-dashboard verification</h2><p>The exact generated dashboard is checked at 1440×900, 1280×800, 1024×768, and 390×844 after publication. The linked receipt records page overflow, bounded mobile table scrolling, navigation, local links, console output, and the exact dashboard/generator hashes. It verifies the audit artifact only and grants no application-browser, responsive, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class=\"list\"><li><a href=\"evidence/browser/current-audit-dashboard-verification-run-083-wave-08.json\">RUN-083 responsive audit-dashboard verification receipt</a></li></ul></section>"
    current_verify_new = "<section class=\"panel\"><h2>Fresh RUN-085 audit-dashboard verification</h2><p>The exact regenerated dashboard is checked at 1440×900, 1280×800, 1024×768, and 390×844 after publication. The linked receipt records page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate IDs, console output, visible zero-credit boundaries, and exact dashboard/generator/reporting hashes. It verifies the audit artifact only and grants no application-browser, responsive, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class=\"list\"><li><a href=\"evidence/browser/current-audit-dashboard-verification-run-085-wave-09.json\">RUN-085 responsive audit-dashboard verification receipt</a></li></ul></section>"
    lineage_old = "<section class=\"panel\"><h2>RUN-071–083 evidence lineage</h2><p>Every current raw, generated, reviewed, and integrated RUN-077–083 source/reporting artifact is linked with its exact SHA-256."
    lineage_new = "<section class=\"panel\"><h2>RUN-071–085 evidence lineage</h2><p>Every current raw, generated, reviewed, and integrated RUN-077–085 source/reporting artifact is linked with its exact SHA-256."
    notice_old = "The live matrix is unchanged at <span class=\"mono\">$route_page_matrix_short</span>"
    notice_new = "RUN-084/R then independently close the $full_page_tree_files-file page-tree structural ledger ($full_page_production = $full_page_roots + $full_page_support + $full_page_nonroots), and RUN-084B/BR independently close the $backend_role_rows-row backend structural ledger while whole-file semantic review stays 0. RUN-084's current designated-application preflight is signed out and build-unattributed. The live matrix is unchanged at <span class=\"mono\">$route_page_matrix_short</span>"
    footer_old = "Generated deterministically from static candidate evidence through RUN-082 and reported in RUN-083."
    footer_new = "Generated deterministically from independently reviewed static candidate evidence through RUN-084B and reported in RUN-085."
    patch_text(
        "generators/build-current-audit-dashboard.py",
        [
            (evidence_old, evidence_new, "wave range"),
            (wave_old, wave_new, "wave list"),
            (static_old, static_new, "static census scope"),
            (models_old, models_new, "backend rows"),
            (runtime_old, runtime_new, "current app preflight row"),
            (gap_old, gap_new, "page/backend gaps"),
            (safe_browser_old, safe_browser_new, "signed-out browser gap"),
            (prior_old, prior_new, "prior dashboard history"),
            (prior_links_old, prior_links_new, "RUN-083 history link"),
            (current_verify_old, current_verify_new, "fresh RUN-085 receipt"),
            (lineage_old, lineage_new, "lineage range"),
            (notice_old, notice_new, "top notice current lanes"),
            (footer_old, footer_new, "footer range"),
        ],
    )
    keep_one_occurrence("generators/build-current-audit-dashboard.py", wave_new[len(wave_old):], "wave list additions")
    keep_one_occurrence("generators/build-current-audit-dashboard.py", runtime_new[:-len(runtime_old)], "runtime preflight row")
    keep_one_occurrence("generators/build-current-audit-dashboard.py", gap_new[len(gap_old):], "page/backend gap additions")
    keep_one_occurrence("generators/build-current-audit-dashboard.py", prior_links_new[len(prior_links_old):], "RUN-083 history link")
    keep_one_occurrence("generators/build-current-audit-dashboard.py", notice_new[:-len(notice_old)], "top notice additions")


def patch_findings(page_graph: dict[str, Any], page_review: dict[str, Any], backend: dict[str, Any], backend_review: dict[str, Any], preflight: dict[str, Any]) -> None:
    relative = "findings.json"
    findings = read_json(relative)
    records_before = canonical_json_sha256(findings["records"])
    assert len(findings["records"]) == 12
    assert findings["pins"]["application_commit"] == APPLICATION_COMMIT
    assert findings["pins"]["application_tree"] == APPLICATION_TREE
    assert findings["pins"]["current_matrix_sha256"] == MATRIX_SHA256

    findings["pins"].update(
        {
            "run_083_dashboard_verification_sha256": INPUTS["evidence/browser/current-audit-dashboard-verification-run-083-wave-08.json"],
            "run_084_designated_application_preflight_sha256": INPUTS["evidence/browser/current-designated-application-access-preflight-run-084-wave-09.json"],
            "run_084_page_graph_sha256": INPUTS["evidence/source/root-run-084-full-inertia-page-graph-wave-09.json"],
            "run_084_page_graph_independent_review_sha256": INPUTS["evidence/source/raw-run-084r-independent-full-inertia-page-graph-review-wave-09.json"],
            "run_084b_backend_semantic_sha256": INPUTS["evidence/source/root-run-084b-backend-semantic-classification-wave-09.json"],
            "run_084br_backend_semantic_independent_review_sha256": sha256_file("evidence/source/raw-run-084br-independent-backend-semantic-classification-review-wave-09.json"),
        }
    )
    findings["counts"].update(
        {
            "full_page_tree_files": 1058,
            "full_page_tree_tsx_files": 1007,
            "full_page_tree_ts_files": 51,
            "full_page_tree_production_tsx": 963,
            "full_page_tree_literal_rendered_roots": 711,
            "full_page_tree_imported_supports": 227,
            "full_page_tree_adjudicated_non_roots": 25,
            "backend_semantic_role_rows": 1789,
            "backend_semantic_unique_paths": 1755,
            "backend_semantic_async_role_rows": 197,
            "backend_semantic_async_unique_paths": 189,
            "backend_whole_file_semantically_reviewed": 0,
        }
    )
    findings["current_route_page_candidate_census"]["status"] = "STATIC_CANDIDATE_RELATIONS_INDEPENDENTLY_REVIEWED_GO_ZERO_DOWNSTREAM_CREDIT"
    findings["current_audit_artifact_verification_history"] = {
        "run_083": {
            "status": "GO_EXACT_SUPERSEDED_DASHBOARD_ARTIFACT_ZERO_APPLICATION_CREDIT",
            "dashboard_sha256": "fb58b937c99542e48f3f449c293720a8805b5a7484d0c8b86057b08c5edbb8e3",
            "receipt_sha256": INPUTS["evidence/browser/current-audit-dashboard-verification-run-083-wave-08.json"],
            "viewports_verified": 4,
            "local_links_verified": "172/172",
            "anchors_verified": "10/10",
            "duplicate_ids": 0,
            "console_warnings": 0,
            "console_errors": 0,
            "current_dashboard_credit": False,
            "application_browser_credit": False,
        }
    }
    findings["current_designated_application_access_preflight"] = {
        "run_id": preflight["run_id"],
        "status": preflight["status"],
        "observed_states": [row["state"] for row in preflight["safe_browser_observations"]],
        "current_browser_session_authenticated": False,
        "visible_environment_or_build_marker": "NOT_PRESENT",
        "credentials_read_or_entered": False,
        "forms_submitted": False,
        "screenshots_retained": False,
        "page_level_horizontal_overflow": False,
        "console_warning_count": 0,
        "console_error_count": 0,
        "application_browser_credit": False,
        "runtime_credit": False,
        "test_credit": False,
        "pass_credit": False,
        "completion_credit": False,
    }
    findings["current_full_inertia_page_tree"] = {
        "run_id": page_graph["run_id"],
        "status": page_review["status"],
        "independent_review_discrepancies": 0,
        "physical_page_tree_files": 1058,
        "tsx_files": 1007,
        "ts_files": 51,
        "production_tsx_partition": {
            "total": 963,
            "literal_rendered_roots": 711,
            "imported_support_components": 227,
            "adjudicated_unrendered_unimported_non_roots": 25,
        },
        "historical_wording_superseded": "The 25-path cohort is unrendered and unimported, not resolver-imported.",
        "feature_mapping_credit": False,
        "framework_route_credit": False,
        "build_credit": False,
        "application_browser_credit": False,
        "runtime_credit": False,
        "executed_test_credit": False,
        "usability_credit": False,
        "pass_credit": False,
        "completion_credit": False,
    }
    findings["current_backend_semantic_classification"] = {
        "run_id": backend["run_id"],
        "status": backend_review["status"],
        "independent_review_discrepancies": 0,
        "role_rows": 1789,
        "unique_source_paths": 1755,
        "role_counts": backend["classification"]["role_counts"],
        "async_role_rows": 197,
        "async_unique_paths": 189,
        "allowed_prompt_classification": "Evidence gap",
        "whole_file_semantically_reviewed": 0,
        "feature_mapping_credit": False,
        "framework_reachability_credit": False,
        "runtime_credit": False,
        "database_credit": False,
        "executed_test_credit": False,
        "application_browser_credit": False,
        "benchmark_credit": False,
        "ease_credit": False,
        "pass_credit": False,
        "final_finding_credit": False,
        "completion_credit": False,
    }
    assert canonical_json_sha256(findings["records"]) == records_before
    write_lf(relative, json.dumps(findings, ensure_ascii=False, indent=2) + "\n")


def main() -> None:
    page_graph, page_review, backend, backend_review, preflight = assert_inputs()
    patch_reports()
    patch_dashboard_generator()
    patch_findings(page_graph, page_review, backend, backend_review, preflight)

    for relative, expected in PRESERVED.items():
        assert sha256_file(relative) == expected, relative
    assert sha256_file("03-feature-to-benchmark-matrix.csv") == MATRIX_SHA256

    outputs = {
        relative: sha256_file(relative)
        for relative in (
            "00-executive-summary.md",
            "01-repository-module-map.md",
            "09-ui-ux-accessibility-visual-consistency.md",
            "13-unresolved-questions-and-evidence-gaps.md",
            "findings.json",
            "generators/build-current-audit-dashboard.py",
        )
    }
    receipt = {
        "schema_version": "run-085-reporting-materialization-wave-09-v1",
        "run_id": "RUN-085-REPORTING-MATERIALIZATION",
        "status": "CURRENT_PAGE_BACKEND_PREFLIGHT_REPORTING_REFRESHED_ZERO_DOWNSTREAM_CREDIT",
        "generated_on": "2026-08-25",
        "pins": {
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "matrix_sha256": MATRIX_SHA256,
            "generator_sha256": sha256_file("generators/materialize-run-085-reporting-wave-09.py"),
            "backend_review_sha256": sha256_file("evidence/source/raw-run-084br-independent-backend-semantic-classification-review-wave-09.json"),
        },
        "inputs": {
            **INPUTS,
            "evidence/source/raw-run-084br-independent-backend-semantic-classification-review-wave-09.json": sha256_file("evidence/source/raw-run-084br-independent-backend-semantic-classification-review-wave-09.json"),
        },
        "outputs": outputs,
        "preserved": PRESERVED,
        "counts": {
            "prompt_required_paths_present": 18,
            "full_page_tree_files": 1058,
            "production_tsx": 963,
            "literal_rendered_roots": 711,
            "imported_support_components": 227,
            "adjudicated_unrendered_unimported_non_roots": 25,
            "backend_role_rows": 1789,
            "backend_unique_paths": 1755,
            "backend_whole_file_semantically_reviewed": 0,
            "canonical_features": 340,
            "feature_mappings": 0,
            "provisional_finding_records": 12,
            "final_findings": 0,
            "current_signed_in_application_routes_observed": 0,
        },
        "checks": {
            "run_083_dashboard_verification_preserved_as_exact_history": True,
            "run_084_page_graph_independent_review_go": True,
            "run_084_backend_independent_review_go": True,
            "run_084_designated_application_session_signed_out": True,
            "historical_25_path_wording_explicitly_superseded_without_rewriting_history": True,
            "provisional_finding_records_byte_semantics_preserved": True,
            "matrix_byte_identical": True,
            "dashboard_requires_fresh_run_085_verification": True,
            "application_source_paths_written": 0,
        },
        "credit_boundary": {
            "feature_mapping": False,
            "benchmark_mapping": False,
            "final_no_match": False,
            "framework_reachability": False,
            "runtime": False,
            "database": False,
            "build": False,
            "executed_tests": False,
            "application_browser": False,
            "workflow_or_ease": False,
            "pass": False,
            "final_finding": False,
            "completion": False,
            "audit_complete": False,
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
    }
    write_lf(
        "evidence/source/current-run-085-reporting-materialization-wave-09.json",
        json.dumps(receipt, ensure_ascii=False, indent=2) + "\n",
    )
    print(
        json.dumps(
            {
                "status": receipt["status"],
                "output": str(OUTPUT_PATH.relative_to(AUDIT_DIR)).replace("\\", "/"),
                "sha256": sha256_file("evidence/source/current-run-085-reporting-materialization-wave-09.json"),
                "outputs": outputs,
                "matrix_sha256": MATRIX_SHA256,
                "all_downstream_credit": 0,
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
