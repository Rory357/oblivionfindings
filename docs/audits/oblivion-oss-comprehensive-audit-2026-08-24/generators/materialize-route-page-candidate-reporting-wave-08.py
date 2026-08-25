#!/usr/bin/env python3
"""Materialize RUN-083 reporting from the zero-credit RUN-082 candidate census."""

from __future__ import annotations

import csv
import hashlib
import json
import os
import subprocess
import tempfile
from collections import Counter
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
REPO_DIR = AUDIT_DIR.parents[2]
AUDIT_RELATIVE = AUDIT_DIR.relative_to(REPO_DIR).as_posix()
CHECKPOINT_COMMIT = "35a5228b26c54684718495c33281b24c0992de02"
CHECKPOINT_TREE = "8ba4e28575cdb53682824a9ae604c718646d8a18"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
MATRIX_PATH = "03-feature-to-benchmark-matrix.csv"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
CANDIDATE_PATH = "evidence/source/root-run-082-exact-owner-containment-candidate-manifest-wave-08.json"
CANDIDATE_SHA256 = "3c6ad4df13a09ae8ff8f19aee09a1907f9754a1877806659676c6c2898652f85"
REVIEW_PATH = "evidence/source/raw-run-082r-independent-exact-owner-containment-review-wave-08.json"
REVIEW_SHA256 = "a6a4f886ca209bc41ffa86afec37f6bddaf062ac80a6b375391adeea20e1c396"
OUTPUT_PATH = "evidence/source/current-route-page-candidate-reporting-materialization-wave-08.json"
GENERATOR_PATH = "generators/materialize-route-page-candidate-reporting-wave-08.py"
RUN081_REPORTING_PATH = "evidence/source/current-route-page-reporting-materialization-wave-07.json"
RUN081_REPORTING_SHA256 = "d075bc06da962d932351cb653f3a34dd88cbfc6272488fe06bc26ab61c80e55a"
RUN081_GENERATOR_PATH = "generators/materialize-route-page-reporting-wave-07.py"
RUN081_GENERATOR_SHA256 = "0b07eee85bdd2b2e743ebb4fbe6237e40129ecbdd530c5ad5b263f8f000d019d"
RUN081_BROWSER_PATH = "evidence/browser/current-audit-dashboard-verification-run-081-wave-07.json"
RUN081_BROWSER_SHA256 = "987031c731c8c1f60541bd84cb7f40ff48f10fbb65ca4162ad8e829fd0051aa1"
SENTINEL = "NOT_ESTABLISHED_CURRENT_AUDIT"

REPORT_PATHS = (
    "00-executive-summary.md",
    "01-repository-module-map.md",
    "07-module-findings.md",
    "08-cross-module-journeys.md",
    "09-ui-ux-accessibility-visual-consistency.md",
    "10-architecture-data-integration-security.md",
    "11-prioritised-roadmap.md",
    "12-native-build-and-do-not-copy-register.md",
    "13-unresolved-questions-and-evidence-gaps.md",
    "findings.json",
)
BASE_REPORT_HASHES = {
    "00-executive-summary.md": "d459661909ac9c987ff09798c6aa596036a604d94de3e466ed0856fd13435913",
    "01-repository-module-map.md": "1a382ab39a76ffed9518a5eec1ef23dc1409dec9dbd0c798aa37c5bc7e75f9ab",
    "07-module-findings.md": "b374758ca49463469426764f54d11e2b1a24df64c0ef101c6979026cc3c82a9a",
    "08-cross-module-journeys.md": "ef4471ba75ac9080e4565989e4b038bf7d0ad306cad1984019882457517c853c",
    "09-ui-ux-accessibility-visual-consistency.md": "b91ce38abc9b5babb9e590641bd7b9bdd7efe6338f4b697060de1f9714b59983",
    "10-architecture-data-integration-security.md": "ca5667b1c042024f32f320254baf063dd4bcd2c4b12972cf2aac29c02d782b22",
    "11-prioritised-roadmap.md": "e5c2f41bf98d3415de97d18d853f1d7c351b337ba544fbf8c81330ec63dcf02d",
    "12-native-build-and-do-not-copy-register.md": "44ae85422a6863d4804fec7d495107b9bdc937257f023767fb306ccd755e137a",
    "13-unresolved-questions-and-evidence-gaps.md": "b3948545270710304d6e6c72a992e153ca968aba39f4f4754e580d564b1a27f0",
    "findings.json": "80f9e9439dc909d6f17d4b9904daf8afa63e4aa56707b82a7b7cb9424f5fe455",
}


def sha256_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def sha256(relative: str) -> str:
    return sha256_bytes((AUDIT_DIR / relative).read_bytes())


def read_json(relative: str) -> dict:
    return json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))


def committed_bytes(relative: str) -> bytes:
    result = subprocess.run(
        ["git", "show", f"{CHECKPOINT_COMMIT}:{AUDIT_RELATIVE}/{relative}"],
        cwd=REPO_DIR,
        check=True,
        capture_output=True,
    )
    return result.stdout


def replace_once(text: str, old: str, new: str) -> str:
    assert text.count(old) == 1, old
    return text.replace(old, new, 1)


def replace_line(text: str, prefix: str, replacement: str) -> str:
    lines = text.splitlines()
    matches = [index for index, line in enumerate(lines) if line.startswith(prefix)]
    assert len(matches) == 1, prefix
    lines[matches[0]] = replacement
    return "\n".join(lines) + "\n"


assert sha256(MATRIX_PATH) == MATRIX_SHA256
assert sha256(CANDIDATE_PATH) == CANDIDATE_SHA256
assert sha256(REVIEW_PATH) == REVIEW_SHA256
assert sha256(RUN081_REPORTING_PATH) == RUN081_REPORTING_SHA256
assert sha256(RUN081_GENERATOR_PATH) == RUN081_GENERATOR_SHA256
assert sha256(RUN081_BROWSER_PATH) == RUN081_BROWSER_SHA256

base_report_bytes = {path: committed_bytes(path) for path in REPORT_PATHS}
assert {path: sha256_bytes(value) for path, value in base_report_bytes.items()} == BASE_REPORT_HASHES

candidate = read_json(CANDIDATE_PATH)
review = read_json(REVIEW_PATH)
assert candidate["run_id"] == "RUN-082-EXACT-OWNER-CONTAINMENT-CANDIDATE-CENSUS"
assert candidate["status"] == "STATIC_CANDIDATE_RELATIONS_MATERIALIZED_PENDING_INDEPENDENT_REVIEW_ZERO_CREDIT"
assert candidate["pins"]["checkpoint_commit"] == CHECKPOINT_COMMIT
assert candidate["pins"]["checkpoint_tree"] == CHECKPOINT_TREE
assert candidate["pins"]["application_commit"] == APPLICATION_COMMIT
assert candidate["pins"]["application_tree"] == APPLICATION_TREE
assert candidate["pins"]["matrix_sha256"] == MATRIX_SHA256
assert candidate["pins"]["generator_sha256"] == sha256(candidate["pins"]["generator"])
assert candidate["counts"]["canonical_matrix_features"] == 340
assert candidate["counts"]["unresolved_route_like_records"] == 3003
assert candidate["counts"]["page_evidence_gap_records"] == 393
assert candidate["counts"]["final_feature_mappings"] == 0
assert candidate["counts"]["framework_routes_executed"] == 0
assert not any(candidate["credit_boundary"].values())
assert not any(candidate["completion_boundary"].values())
assert candidate["review_contract"]["review_status"] == "PENDING"

assert review["run_id"] == "RUN-082R-INDEPENDENT-EXACT-OWNER-CONTAINMENT-REVIEW"
assert review["status"] == "GO_STATIC_CANDIDATE_CENSUS_REVIEWED_ZERO_DOWNSTREAM_CREDIT"
assert review["pins"]["checkpoint_commit"] == CHECKPOINT_COMMIT
assert review["pins"]["checkpoint_tree"] == CHECKPOINT_TREE
assert review["pins"]["application_commit"] == APPLICATION_COMMIT
assert review["pins"]["application_tree"] == APPLICATION_TREE
assert review["pins"]["matrix_sha256"] == MATRIX_SHA256
assert review["pins"]["run_082_output_sha256"] == CANDIDATE_SHA256
assert review["pins"]["run_082_generator_sha256"] == sha256(candidate["pins"]["generator"])
assert review["verdict"] == {
    "decision": "GO",
    "scope": "STATIC_CANDIDATE_RELATIONS_AND_STATIC_ROUTE_REGISTRATION_CLOSURE_ONLY",
    "feature_mapping_authorized": False,
    "matrix_mutation_authorized": False,
    "downstream_integration_authorized": False,
}
assert review["checks"]["review_discrepancies"] == 0
assert review["checks"]["route_denominator_and_relations_recomputed"] is True
assert review["checks"]["page_denominator_and_relations_recomputed"] is True
assert review["checks"]["static_registration_closure_recomputed"] is True
assert review["mutation_attestation"]["review_execution_wrote_files"] is False
assert not any(review["credit_boundary"].values())

route_census = candidate["route_static_candidate_census"]
page_census = candidate["page_static_candidate_census"]
route_names = route_census["exact_route_name_cardinalities"]
route_backend = route_census["controller_method_containment_cardinalities_resolved_2879"]
page_render = page_census["render_owner_containment_cardinalities"]
page_files = page_census["current_matrix_page_file_cardinalities"]
closure = candidate["static_route_registration_closure"]
gates = candidate["execution_gates"]

assert {key: row["count"] for key, row in route_names.items()} == {"zero": 2430, "one": 527, "many": 46}
assert {key: row["count"] for key, row in route_backend.items()} == {"zero": 2214, "one": 610, "many": 55}
assert sum(row["count"] for row in route_backend.values()) == candidate["counts"]["route_exact_class_method_arrays_resolved"]
assert candidate["counts"]["route_exact_class_method_arrays_resolved"] + candidate["counts"]["route_non_exact_class_method_array_records"] == 3003
assert {key: row["count"] for key, row in page_render.items()} == {"zero": 348, "one": 43, "many": 2}
assert sum(row["count"] for row in page_files.values()) == 393
assert closure["counts"] == {
    "route_files_in_manifest": 38,
    "direct_bootstrap_surfaces": 5,
    "web_required_surfaces": 33,
    "represented_route_files": 38,
    "missing_route_files": 0,
    "extra_route_files": 0,
    "framework_route_tables_executed": 0,
}
assert closure["framework_route_reachability"] == "NOT_EXECUTED"
assert all(gates[key]["executed"] is False for key in ("framework_runtime", "build", "tests", "application_browser"))
assert all(gates[key]["credit_awarded"] is False for key in ("framework_runtime", "build", "tests", "application_browser"))
assert review["reconciled_counts"] == {
    "route_records": 3003,
    "route_name_candidates": {"zero": 2430, "one": 527, "many": 46},
    "resolved_class_method_actions": 2879,
    "non_exact_class_method_actions": 124,
    "resolved_backend_containment": {"zero": 2214, "one": 610, "many": 55},
    "all_route_backend_candidates": {"zero": 2338, "one": 610, "many": 55},
    "page_records": 393,
    "page_render_containment": {"zero": 348, "one": 43, "many": 2},
    "static_route_registration": {
        "route_files": 38,
        "direct_bootstrap_surfaces": 5,
        "web_required_surfaces": 33,
        "missing": 0,
        "extra": 0,
        "framework_route_tables_executed": 0,
    },
    "matrix_rows_changed": 0,
    "matrix_cells_changed": 0,
    "final_feature_mappings": 0,
}

with (AUDIT_DIR / MATRIX_PATH).open(encoding="utf-8-sig", newline="") as handle:
    matrix_rows = list(csv.DictReader(handle))
assert len(matrix_rows) == len({row["feature_id"] for row in matrix_rows}) == 340
assert Counter(row["feature_class"] for row in matrix_rows) == {"H": 300, "D": 40}
gap_ids = {
    field: sorted(row["feature_id"] for row in matrix_rows if row[field] == SENTINEL)
    for field in ("route_names", "route_paths", "page_files", "backend_anchors", "test_anchors")
}
both_gap_ids = sorted(set(gap_ids["route_paths"]) & set(gap_ids["page_files"]))
assert {field: len(ids) for field, ids in gap_ids.items()} == {
    "route_names": 166,
    "route_paths": 1,
    "page_files": 4,
    "backend_anchors": 0,
    "test_anchors": 8,
}
assert len(both_gap_ids) == 1

staged: dict[str, bytes] = dict(base_report_bytes)

summary = f"""## RUN-082 static candidate census and execution preflight

RUN-082 re-examines the exact {candidate['counts']['unresolved_route_like_records']:,} RUN-078 explicit-unmapped route-like rows and {candidate['counts']['page_evidence_gap_records']} page evidence-gap rows against the unchanged post-RUN-080 matrix. Exact route-name relations contain {route_names['one']['count']} single-candidate, {route_names['many']['count']} multi-candidate, and {route_names['zero']['count']:,} zero-candidate rows. Import-aware exact class/method resolution covers {candidate['counts']['route_exact_class_method_arrays_resolved']:,} rows, with {route_backend['one']['count']} single-candidate, {route_backend['many']['count']} multi-candidate, and {route_backend['zero']['count']:,} zero-candidate containment relations; {candidate['counts']['route_non_exact_class_method_array_records']} rows are explicitly outside that exact-action lane. Page render-owner containment contains {page_render['one']['count']} single-candidate, {page_render['many']['count']} multi-candidate, and {page_render['zero']['count']} zero-candidate rows.

RUN-082R independently recomputes the exact denominators, relation cardinalities, identity hashes, and static registration closure with zero discrepancies and returns GO for candidate-only static evidence. That GO does not authorize feature mapping, matrix mutation, or downstream integration. The matrix remains byte-identical at `{MATRIX_SHA256}` with 0 rows / 0 cells changed and mapping unchanged at 0/340.

Literal bootstrap registration and uncommented `routes/web.php` requires represent all {closure['counts']['represented_route_files']}/{closure['counts']['route_files_in_manifest']} route PHP files: {closure['counts']['direct_bootstrap_surfaces']} direct bootstrap surfaces plus {closure['counts']['web_required_surfaces']} web-required surfaces, with zero missing or extra. This establishes static source registration closure only. Missing `vendor/autoload.php` and route cache, missing pinned Node/Wayfinder dependencies and build provenance, non-isolated database identifiers, and absent authoritative deployed-build identity keep framework runtime, build, tests, and application browser at NO-GO / not executed with zero credit.

"""
executive = staged["00-executive-summary.md"].decode("utf-8")
executive = replace_once(executive, "## Current raw source census\n", summary + "## Current raw source census\n")
evidence_marker = "- `evidence/source/current-route-page-reporting-materialization-wave-07.json`: RUN-081 deterministic report/hash receipt preserving all zero-credit boundaries.\n"
executive = replace_once(
    executive,
    evidence_marker,
    evidence_marker
    + f"- `{CANDIDATE_PATH}`: RUN-082 deterministic static candidate census, 38/38 static registration closure, explicit execution NO-GO gates, and zero mapping/downstream credit.\n"
    + f"- `{REVIEW_PATH}`: RUN-082R independent read-only red-team GO for candidate-only static evidence, with zero discrepancies and no feature-mapping, matrix-mutation, or downstream-integration authority.\n"
    + f"- `{OUTPUT_PATH}`: RUN-083 deterministic report/hash receipt preserving the unchanged matrix and all zero-credit boundaries.\n",
)
executive = replace_line(
    executive,
    "- `audit-dashboard.html`:",
    "- `audit-dashboard.html`: progress dashboard generated only from current structured evidence. A fresh RUN-083 audit-artifact viewport/link/console receipt is required after publication and cannot award application-browser or downstream credit.",
)
executive = replace_line(
    executive,
    "2. Continue from RUN-080's retained matrix gaps",
    f"2. Independently adjudicate RUN-082's candidate relations—including every multi-candidate, disjoint, partial-overlap, and unresolved lane—without treating exact names or line containment as ownership. Retain the unchanged RUN-080 gaps of 1 route path, 166 route names, 4 page files, 0 backend anchors, and 8 static test anchors until separately reviewed evidence authorizes a bounded change; framework runtime, build, browser, tests, mapping, and completion remain zero-credit.",
)
staged["00-executive-summary.md"] = executive.encode("utf-8")

module_map = staged["01-repository-module-map.md"].decode("utf-8")
module_overlay = f"""## RUN-082 exact static candidate-relation overlay

The retained RUN-078 evidence-gap denominators are unchanged: {candidate['counts']['unresolved_route_like_records']:,} route-like records and {candidate['counts']['page_evidence_gap_records']} page records. Exact route-name candidates partition as {route_names['one']['count']} single / {route_names['many']['count']} multiple / {route_names['zero']['count']:,} none. Exact import-aware controller-method containment partitions {candidate['counts']['route_exact_class_method_arrays_resolved']:,} resolvable rows as {route_backend['one']['count']} single / {route_backend['many']['count']} multiple / {route_backend['zero']['count']:,} none, with {candidate['counts']['route_non_exact_class_method_array_records']} non-exact action arrays retained outside that lane. Exact render-owner containment partitions page gaps as {page_render['one']['count']} single / {page_render['many']['count']} multiple / {page_render['zero']['count']} none.

All {closure['counts']['represented_route_files']}/{closure['counts']['route_files_in_manifest']} route PHP files have a literal static registration path, but no Laravel route table, build, test, or application browser lane was executed. RUN-082R independently reproduces the candidate relations and static registration closure with zero discrepancies and returns GO limited to candidate-only static evidence. It awards 0 feature mappings and 0 downstream credit; the matrix remains `{MATRIX_SHA256}`.

"""
module_map = replace_once(module_map, "## Candidate register\n", module_overlay + "## Candidate register\n")
staged["01-repository-module-map.md"] = module_map.encode("utf-8")

module_findings = staged["07-module-findings.md"].decode("utf-8")
findings_overlay = f"""## RUN-082 static candidate census and reachability preflight

| Candidate-only measure | Current static result | Credit boundary |
|---|---:|---|
| Explicit-unmapped route-like denominator | {candidate['counts']['unresolved_route_like_records']:,} | unchanged RUN-078 evidence-gap rows |
| Exact route-name candidates | {route_names['one']['count']} single · {route_names['many']['count']} multiple · {route_names['zero']['count']:,} none | relation only; no ownership |
| Exact controller-method containment | {route_backend['one']['count']} single · {route_backend['many']['count']} multiple · {route_backend['zero']['count']:,} none | {candidate['counts']['route_exact_class_method_arrays_resolved']:,} exact arrays; {candidate['counts']['route_non_exact_class_method_array_records']} non-exact retained |
| Page evidence-gap denominator | {candidate['counts']['page_evidence_gap_records']} | unchanged RUN-078 evidence-gap rows |
| Exact render-owner containment | {page_render['one']['count']} single · {page_render['many']['count']} multiple · {page_render['zero']['count']} none | relation only; no ownership or rendered-page evidence |
| Static route-file registration closure | {closure['counts']['represented_route_files']} / {closure['counts']['route_files_in_manifest']} | 5 bootstrap + 33 web requires; not framework reachability |
| Matrix rows / cells changed | 0 / 0 | SHA-256 unchanged `{MATRIX_SHA256}` |
| RUN-082R independent red-team / final mappings | GO / 0 | candidate-only static evidence; no feature-mapping, matrix-mutation, or downstream-integration authority |
| Framework runtime / build / tests / application browser | 0 / 0 / 0 / 0 | explicit NO-GO, not executed |

RUN-082 establishes reproducible static candidate relations and route-file registration closure; RUN-082R independently reproduces them with zero discrepancies and returns GO only for that static candidate scope. Neither creates a final finding, feature mapping, matrix-mutation authority, runtime, browser, build, executed-test, benchmark, ease, release, Pass, or completion evidence.

"""
module_findings = replace_once(module_findings, "## Exact accounting\n", findings_overlay + "## Exact accounting\n")
staged["07-module-findings.md"] = module_findings.encode("utf-8")

gaps_report = staged["13-unresolved-questions-and-evidence-gaps.md"].decode("utf-8")
gaps_report = replace_line(
    gaps_report,
    "| Required reporting paths |",
    "| Required reporting paths | 18 / 18 prompt-required files or directories present; RUN-081 dashboard verification is immutable history for superseded HTML, and the RUN-083 generated dashboard requires a fresh audit-artifact viewport/link/console receipt | Presence and audit-artifact verification make the reporting contract inspectable but grant no application execution, final-finding, Pass, or completion credit. Gate 26 remains open. | Complete the semantic, execution, benchmark, Pass 8, final reconciliation, and no-live-agent gates; repeat audit-dashboard verification after every later HTML change. |",
)
gaps_report = replace_line(
    gaps_report,
    "| Runtime routes |",
    f"| Runtime routes | All {closure['counts']['represented_route_files']}/{closure['counts']['route_files_in_manifest']} route PHP files have an exact static registration path: {closure['counts']['direct_bootstrap_surfaces']} direct bootstrap surfaces plus {closure['counts']['web_required_surfaces']} `routes/web.php` requires; RUN-082 also retains all {candidate['counts']['unresolved_route_like_records']:,} candidate-census route rows | Static registration closure is not a framework-expanded route table or reachability proof. Missing vendor autoload/route cache keeps framework runtime NO-GO, and the historical 3,024-route denominator cannot be inherited. | Hydrate dependencies only under a fresh bounded runtime grant, use an exact disposable database, execute a separately pinned framework/provider route lane, and reconcile it to all 38 source route files and 3,218 route-like rows. |",
)
gaps_report = replace_line(
    gaps_report,
    "| Inertia pages |",
    f"| Inertia pages | The exact {candidate['counts']['page_evidence_gap_records']} RUN-078 page evidence gaps now have independently reproduced render-owner candidate relations: {page_render['one']['count']} single, {page_render['many']['count']} multiple, and {page_render['zero']['count']} none | RUN-082R GO is limited to candidate-only static evidence; exact line containment is not canonical ownership, runtime reachability, build resolution, rendered browser behavior, or final feature mapping. | Adjudicate candidate ownership separately, reconcile all 711 roots to safely expanded framework routes and frozen feature IDs, prove build resolution, and observe every safely reachable current-build page under approved roles/Sites. |",
)
gaps_report = replace_line(
    gaps_report,
    "| Canonical features |",
    f"| Canonical features | Static canonical identity remains 340 targets: 300 H, 40 D, zero M; RUN-082 changes 0 matrix rows / 0 cells and leaves `{MATRIX_SHA256}` byte-identical | RUN-080's retained gaps remain 1 route-path, 166 route-name, 4 page-file, 1 combined, 0 backend, and 8 static test gaps. RUN-082R independently reviews candidate-only static evidence but authorizes no mapping or matrix mutation; 0/340 mapping credit remains. | Adjudicate ownership and the retained gaps separately without awarding runtime, browser, test, benchmark, ease, release, or completion credit. |",
)
gaps_report = replace_line(
    gaps_report,
    "| Agent universe and writer rule |",
    "| Agent universe and writer rule | RUN-001 through RUN-083 represented at the current reporting checkpoint; finalization gate false | RUN-082 deterministically materializes candidate relations and static registration closure; RUN-082R independently returns GO limited to that candidate-only static evidence with zero discrepancies; RUN-083 refreshes reports only. Neither source closure, candidate overlap, review GO, hashes, nor report presence satisfies runtime, mapping, Pass 8, finalization, or completion. | Complete ownership adjudication and all semantic/execution gates, then dispatch fresh Pass 8/final cross-reviewers, verify the final dashboard, and prove no agent remains live at finalization. |",
)
old_lineage = """## RUN-077–081 route/page classification and reporting lineage

RUN-077 freezes the exhaustive committed-source route/name/page universe. RUN-078 records all 3,218 route-like, 3,245 name, and 711 page decisions. RUN-079's cyclic A→B, B→C, C→A independent reviews are all GO with zero invalid decisions and no writes. RUN-080 integrates only 78 route-name and 2 page-file fields; RUN-081 materializes current reports and their exact hash register. Full route/page-to-feature mapping, framework reachability, runtime, build, browser, executed tests, benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit.

"""
new_lineage = f"""## RUN-077–083 route/page classification, candidate census, and reporting lineage

RUN-077 freezes the exhaustive committed-source route/name/page universe. RUN-078 records all 3,218 route-like, 3,245 name, and 711 page decisions. RUN-079's cyclic A→B, B→C, C→A independent reviews are all GO with zero invalid decisions and no writes. RUN-080 integrates only 78 route-name and 2 page-file fields; RUN-081 materializes those reports and hashes. RUN-082 adds deterministic static candidate relations for {candidate['counts']['unresolved_route_like_records']:,} route-like and {candidate['counts']['page_evidence_gap_records']} page evidence-gap records plus {closure['counts']['represented_route_files']}/{closure['counts']['route_files_in_manifest']} static route-file registration closure. RUN-082R independently reproduces those candidate-only results with zero discrepancies and GO, while explicitly authorizing no feature mapping, matrix mutation, or downstream integration. Framework/build/test/browser gates remain NO-GO and not executed, and RUN-083 refreshes reporting only. Full route/page-to-feature mapping, framework reachability, runtime, build, browser, executed tests, benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit.

"""
gaps_report = replace_once(gaps_report, old_lineage, new_lineage)
gaps_report = replace_line(
    gaps_report,
    "The current `03-feature-to-benchmark-matrix.csv` has 340 canonical static target rows:",
    f"The current `03-feature-to-benchmark-matrix.csv` has 340 canonical static target rows: 300 H and 40 D. RUN-080 changed only independently reviewed route-name/page-file fields, from RUN-076 base `00085d407433307e7f6798c0e8e04629b1746d4bfb1e18024c51ead1dc4f7afd` to `{MATRIX_SHA256}`. RUN-082 changes 0 rows / 0 cells and leaves that file byte-identical; RUN-082R independently confirms those candidate-only static results with zero discrepancies but authorizes no matrix mutation. Retained matrix gaps remain 1 route path, 166 route names, 4 page files, 1 combined, 0 backend anchors, and 8 static test anchors. Runtime, browser, executed-test, benchmark, ease, release, P2–P8, Pass, and completion credit remain zero. RUN-072 task scripts/scorecard remain an unexecuted historical locator snapshot and were not silently relabelled current.",
)
staged["13-unresolved-questions-and-evidence-gaps.md"] = gaps_report.encode("utf-8")

findings = json.loads(staged["findings.json"].decode("utf-8"))
findings["pins"].update({
    "audit_checkpoint_parent": CHECKPOINT_COMMIT,
    "route_page_candidate_census_sha256": sha256(CANDIDATE_PATH),
    "route_page_candidate_independent_review_sha256": sha256(REVIEW_PATH),
    "current_matrix_sha256": MATRIX_SHA256,
})
findings["counts"].update({
    "route_page_candidate_route_records": candidate["counts"]["unresolved_route_like_records"],
    "route_page_candidate_page_records": candidate["counts"]["page_evidence_gap_records"],
    "route_page_exact_name_single_candidates": route_names["one"]["count"],
    "route_page_exact_name_multi_candidates": route_names["many"]["count"],
    "route_page_exact_name_no_candidates": route_names["zero"]["count"],
    "route_page_controller_single_candidates": route_backend["one"]["count"],
    "route_page_controller_multi_candidates": route_backend["many"]["count"],
    "route_page_controller_no_candidates": route_backend["zero"]["count"],
    "route_page_non_exact_action_rows": candidate["counts"]["route_non_exact_class_method_array_records"],
    "route_page_render_owner_single_candidates": page_render["one"]["count"],
    "route_page_render_owner_multi_candidates": page_render["many"]["count"],
    "route_page_render_owner_no_candidates": page_render["zero"]["count"],
    "static_route_files_registered": closure["counts"]["represented_route_files"],
})
findings["current_route_page_candidate_census"] = {
    "status": candidate["status"],
    "route_denominator": candidate["counts"]["unresolved_route_like_records"],
    "page_denominator": candidate["counts"]["page_evidence_gap_records"],
    "exact_route_name_cardinalities": route_names,
    "controller_method_containment_cardinalities": route_backend,
    "non_exact_class_method_array_records": candidate["counts"]["route_non_exact_class_method_array_records"],
    "render_owner_containment_cardinalities": page_render,
    "static_route_registration_closure": {
        "represented": closure["counts"]["represented_route_files"],
        "denominator": closure["counts"]["route_files_in_manifest"],
        "direct_bootstrap": closure["counts"]["direct_bootstrap_surfaces"],
        "web_requires": closure["counts"]["web_required_surfaces"],
    },
    "independent_review": "GO_STATIC_CANDIDATE_ONLY_ZERO_DOWNSTREAM_CREDIT",
    "review_discrepancies": review["checks"]["review_discrepancies"],
    "feature_mapping_authorized": review["verdict"]["feature_mapping_authorized"],
    "matrix_mutation_authorized": review["verdict"]["matrix_mutation_authorized"],
    "downstream_integration_authorized": review["verdict"]["downstream_integration_authorized"],
    "matrix_rows_changed": 0,
    "matrix_cells_changed": 0,
    "final_feature_mappings": 0,
    "framework_route_reachability": False,
    "runtime": False,
    "build": False,
    "application_browser": False,
    "executed_tests": False,
    "benchmark_mapping": False,
    "ease": False,
    "pass": False,
    "completion": False,
}
staged["findings.json"] = (json.dumps(findings, indent=2, ensure_ascii=False) + "\n").encode("utf-8")

changed_reports = {
    "00-executive-summary.md",
    "01-repository-module-map.md",
    "07-module-findings.md",
    "13-unresolved-questions-and-evidence-gaps.md",
    "findings.json",
}
unchanged_reports = set(REPORT_PATHS) - changed_reports
assert all(staged[path] != base_report_bytes[path] for path in changed_reports)
assert all(staged[path] == base_report_bytes[path] for path in unchanged_reports)

output_hashes = {path: sha256_bytes(value) for path, value in staged.items()}
artifact_paths = [
    CANDIDATE_PATH,
    REVIEW_PATH,
    candidate["pins"]["generator"],
    MATRIX_PATH,
    RUN081_REPORTING_PATH,
    RUN081_GENERATOR_PATH,
    RUN081_BROWSER_PATH,
]
receipt = {
    "schema_version": 1,
    "run_id": "RUN-083-ROUTE-PAGE-CANDIDATE-REPORTING-MATERIALIZATION",
    "status": "CURRENT_CANDIDATE_CENSUS_REPORTING_REFRESHED_ZERO_DOWNSTREAM_CREDIT",
    "generated_on": "2026-08-25",
    "pins": {
        "checkpoint_commit": CHECKPOINT_COMMIT,
        "checkpoint_tree": CHECKPOINT_TREE,
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
        "candidate_census_sha256": sha256(CANDIDATE_PATH),
        "candidate_independent_review_sha256": sha256(REVIEW_PATH),
        "matrix_sha256": MATRIX_SHA256,
        "prior_reporting_sha256": RUN081_REPORTING_SHA256,
    },
    "architecture_rule": "One operating organisation across multiple Sites; Site access, exact action permissions, ownership, consent and privacy are the boundaries.",
    "counts": {
        "canonical_features": 340,
        "unresolved_route_like_records": candidate["counts"]["unresolved_route_like_records"],
        "page_evidence_gap_records": candidate["counts"]["page_evidence_gap_records"],
        "exact_route_name_cardinalities": route_names,
        "controller_method_containment_cardinalities": route_backend,
        "non_exact_class_method_array_records": candidate["counts"]["route_non_exact_class_method_array_records"],
        "render_owner_containment_cardinalities": page_render,
        "current_matrix_page_file_cardinalities": page_files,
        "static_route_files_represented": closure["counts"]["represented_route_files"],
        "independent_static_red_team_go": 1,
        "independent_review_discrepancies": review["checks"]["review_discrepancies"],
        "matrix_rows_changed": 0,
        "matrix_cells_changed": 0,
        "final_feature_mappings": 0,
        "framework_routes_executed": 0,
        "runtime_credit": 0,
        "build_credit": 0,
        "application_browser_credit": 0,
        "executed_test_credit": 0,
        "benchmark_mapping_credit": 0,
        "pass_credit": 0,
        "completion_credit": 0,
    },
    "inputs": {path: sha256(path) for path in (CANDIDATE_PATH, REVIEW_PATH, MATRIX_PATH, RUN081_REPORTING_PATH)},
    "artifact_register": {
        path: {
            "sha256": sha256(path),
            "role": (
                "current_matrix" if path == MATRIX_PATH else
                "historical_reporting_or_browser_receipt" if path in {RUN081_REPORTING_PATH, RUN081_BROWSER_PATH} else
                "generator" if path.startswith("generators/") else
                "current_candidate_review" if path == REVIEW_PATH else
                "current_candidate_census"
            ),
        }
        for path in artifact_paths
    },
    "generator": {GENERATOR_PATH: sha256(GENERATOR_PATH)},
    "history": {
        RUN081_REPORTING_PATH: {"sha256": RUN081_REPORTING_SHA256, "rewritten": False},
        RUN081_GENERATOR_PATH: {"sha256": RUN081_GENERATOR_SHA256, "rewritten": False},
        RUN081_BROWSER_PATH: {"sha256": RUN081_BROWSER_SHA256, "rewritten": False, "superseded_for_current_html": True},
        "checkpoint_base_reports": {
            path: {
                "sha256": digest,
                "byte_preserved": path in unchanged_reports,
                "superseded_by_run_083": path in changed_reports,
            }
            for path, digest in BASE_REPORT_HASHES.items()
        },
    },
    "outputs": output_hashes,
    "matrix_validation": {
        "path": MATRIX_PATH,
        "before_sha256": MATRIX_SHA256,
        "after_sha256": MATRIX_SHA256,
        "rows": 340,
        "unique_feature_ids": 340,
        "classes": {"H": 300, "D": 40},
        "rows_changed": 0,
        "cells_changed": 0,
        "remaining_gaps": {field: len(ids) for field, ids in gap_ids.items()} | {"both_route_and_page": len(both_gap_ids)},
    },
    "evidence_boundary": {
        "static_candidate_relations_materialized": True,
        "static_route_file_registration_closure": True,
        "candidate_relations_independently_reviewed": True,
        "independent_review_discrepancies": 0,
        "feature_mapping_authorized": False,
        "matrix_mutation_authorized": False,
        "downstream_integration_authorized": False,
        "complete_route_page_to_feature_mapping": False,
        "framework_route_reachability": False,
        "build_resolution": False,
        "runtime": False,
        "application_browser": False,
        "executed_tests": False,
    },
    "credit_boundary": {
        "artifact_presence_as_feature_mapping": False,
        "candidate_relation_as_feature_mapping": False,
        "static_registration_as_framework_reachability": False,
        "final_finding": False,
        "runtime": False,
        "build": False,
        "application_browser": False,
        "executed_tests": False,
        "benchmark_mapping": False,
        "ease": False,
        "release": False,
        "pass": False,
        "completion": False,
        "audit_complete": False,
    },
    "attestation": "Deterministic RUN-083 reporting refresh from the exact RUN-082 static candidate census and RUN-082R independent read-only red-team GO. GO is limited to candidate-only static evidence, records zero discrepancies, and authorizes no feature mapping, matrix mutation, or downstream integration. Five reports are reconstructed from checkpoint 35a5228b26c5 and published using atomic per-file replacements with rollback; five remain byte-preserved. The matrix is unchanged. No application source, framework reachability, runtime, browser, build, test, database, benchmark, ease, Pass, finding, release, or completion credit is created.",
}
assert not any(receipt["credit_boundary"].values())
receipt_bytes = (json.dumps(receipt, indent=2, ensure_ascii=False) + "\n").encode("utf-8")

with tempfile.TemporaryDirectory(prefix=".run083-reporting-", dir=AUDIT_DIR) as temporary:
    stage = Path(temporary)
    for relative, value in staged.items():
        target = stage / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(value)
    receipt_target = stage / OUTPUT_PATH
    receipt_target.parent.mkdir(parents=True, exist_ok=True)
    receipt_target.write_bytes(receipt_bytes)
    assert {path: sha256_bytes((stage / path).read_bytes()) for path in REPORT_PATHS} == output_hashes
    assert json.loads(receipt_target.read_text(encoding="utf-8"))["outputs"] == output_hashes

    publish_paths = [*REPORT_PATHS, OUTPUT_PATH]
    backups: dict[str, bytes | None] = {}
    try:
        for relative in publish_paths:
            destination = AUDIT_DIR / relative
            backups[relative] = destination.read_bytes() if destination.exists() else None
            destination.parent.mkdir(parents=True, exist_ok=True)
            os.replace(stage / relative, destination)
    except BaseException:
        for relative, prior in backups.items():
            destination = AUDIT_DIR / relative
            if prior is None:
                destination.unlink(missing_ok=True)
            else:
                destination.write_bytes(prior)
        raise

assert sha256(OUTPUT_PATH) == sha256_bytes(receipt_bytes)
assert all(sha256(path) == digest for path, digest in output_hashes.items())
assert sha256(MATRIX_PATH) == MATRIX_SHA256
assert sha256(RUN081_REPORTING_PATH) == RUN081_REPORTING_SHA256
assert sha256(RUN081_GENERATOR_PATH) == RUN081_GENERATOR_SHA256
assert sha256(RUN081_BROWSER_PATH) == RUN081_BROWSER_SHA256
print(json.dumps({
    "run_id": receipt["run_id"],
    "status": receipt["status"],
    "matrix_sha256": MATRIX_SHA256,
    "outputs": output_hashes,
    "receipt_sha256": sha256(OUTPUT_PATH),
}, indent=2))
