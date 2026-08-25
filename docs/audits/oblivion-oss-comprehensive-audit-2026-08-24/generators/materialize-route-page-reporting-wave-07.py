#!/usr/bin/env python3
"""Materialize RUN-081 route/page reporting from reviewed RUN-077--080 evidence."""

from __future__ import annotations

import csv
import hashlib
import io
import json
import os
import subprocess
import tempfile
from collections import Counter
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
REPO_DIR = AUDIT_DIR.parents[2]
AUDIT_RELATIVE = AUDIT_DIR.relative_to(REPO_DIR).as_posix()
CHECKPOINT_COMMIT = "87826adc6fb8c9f0b1ca5ea99dcdc06e32bbd6d0"
CHECKPOINT_TREE = "d1eb36fabc0f5150c81f2140e834347dca87dd25"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
MATRIX_PATH = "03-feature-to-benchmark-matrix.csv"
MANIFEST_PATH = "evidence/source/root-run-077-route-page-universe-manifest-wave-07.json"
PRODUCER_PATH = "evidence/source/current-route-page-classification-wave-07.json"
REVIEW_PATH = "evidence/source/current-route-page-independent-review-wave-07.json"
INTEGRATION_PATH = "evidence/source/current-route-page-static-linkage-integration-wave-07.json"
OUTPUT_PATH = "evidence/source/current-route-page-reporting-materialization-wave-07.json"
GENERATOR_PATH = "generators/materialize-route-page-reporting-wave-07.py"
PREVIOUS_REPORTING_PATH = "evidence/source/current-static-linkage-reporting-materialization-wave-06.json"
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
    "00-executive-summary.md": "231e339c0a830c08ff8b65a290faab710242b224a321289a709b0e228681247a",
    "01-repository-module-map.md": "82a16b1b4eadd205716299e301ed15e3f3233489399fa992c864c12924c53c61",
    "07-module-findings.md": "3ebe59ac1891a5bbec78b3b5a32c67993e750ab1d2a6821ab3aebb3899406826",
    "08-cross-module-journeys.md": "ef4471ba75ac9080e4565989e4b038bf7d0ad306cad1984019882457517c853c",
    "09-ui-ux-accessibility-visual-consistency.md": "b91ce38abc9b5babb9e590641bd7b9bdd7efe6338f4b697060de1f9714b59983",
    "10-architecture-data-integration-security.md": "ca5667b1c042024f32f320254baf063dd4bcd2c4b12972cf2aac29c02d782b22",
    "11-prioritised-roadmap.md": "e5c2f41bf98d3415de97d18d853f1d7c351b337ba544fbf8c81330ec63dcf02d",
    "12-native-build-and-do-not-copy-register.md": "44ae85422a6863d4804fec7d495107b9bdc937257f023767fb306ccd755e137a",
    "13-unresolved-questions-and-evidence-gaps.md": "c39e2ddf9ba75f2d8cc8b573af2316df15f5908351798e320d7a267fc61708e2",
    "findings.json": "abc4e92c4399a1e818ec82eb17eaa42aeb6c125355177f7cc24ea755b98972a4",
}
PREVIOUS_REPORTING_SHA256 = "04d5fd61048c2c877f6bdba3785fa46365ba85464649eb1b83779cc0daf39906"


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


assert sha256(PREVIOUS_REPORTING_PATH) == PREVIOUS_REPORTING_SHA256
base_report_bytes = {path: committed_bytes(path) for path in REPORT_PATHS}
assert {path: sha256_bytes(value) for path, value in base_report_bytes.items()} == BASE_REPORT_HASHES

manifest = read_json(MANIFEST_PATH)
producer = read_json(PRODUCER_PATH)
review = read_json(REVIEW_PATH)
integration = read_json(INTEGRATION_PATH)

assert manifest["run_id"] == "RUN-077-ROUTE-PAGE-UNIVERSE-MANIFEST"
assert manifest["pins"]["application_commit"] == APPLICATION_COMMIT
assert manifest["pins"]["application_tree"] == APPLICATION_TREE
assert manifest["counts"]["canonical_targets"] == 340
assert manifest["counts"]["primary_route_facade_callsites"] == 3217
assert manifest["counts"]["route_like_sentinels_outside_primary_denominator"] == 1
assert manifest["counts"]["static_route_like_review_rows"] == 3218
assert manifest["counts"]["fluent_name_callsites"] == 3245
assert manifest["counts"]["page_roots"] == 711
assert manifest["counts"]["remaining_scoped_targets"] == 12
assert manifest["counts"]["remaining_scoped_cells"] == 15
assert manifest["counts"]["separate_route_name_gap_targets"] == 244
assert producer["run_id"] == "RUN-078-ROUTE-PAGE-CLASSIFICATION-NORMALIZATION"
assert producer["pins"]["checkpoint_commit"] == CHECKPOINT_COMMIT
assert producer["pins"]["checkpoint_tree"] == CHECKPOINT_TREE
assert producer["pins"]["manifest_sha256"] == sha256(MANIFEST_PATH)
assert producer["counts"]["route_decisions"] == 3218
assert producer["counts"]["name_decisions"] == 3245
assert producer["counts"]["page_decisions"] == 711
assert producer["counts"]["residual_scoped_decisions"] == 12
assert producer["counts"]["route_name_gap_decisions"] == 244
assert review["run_id"] == "RUN-079-ROUTE-PAGE-INDEPENDENT-REVIEW-NORMALIZATION"
assert review["status"] == "THREE_PART_CYCLIC_INDEPENDENT_REVIEW_GO_ZERO_DOWNSTREAM_CREDIT"
assert review["pins"]["checkpoint_commit"] == CHECKPOINT_COMMIT
assert review["pins"]["checkpoint_tree"] == CHECKPOINT_TREE
assert review["pins"]["manifest_sha256"] == sha256(MANIFEST_PATH)
assert review["pins"]["normalized_producer_sha256"] == sha256(PRODUCER_PATH)
assert review["counts"]["go_reviews"] == 3
assert review["counts"]["invalid_decisions"] == 0
assert review["counts"]["review_artifacts_wrote_files"] == 0
assert review["review_gate"]["independent_cyclic_review_complete"] is True
assert review["review_gate"]["static_matrix_field_integration_authorized"] is True
assert review["review_gate"]["other_downstream_integration_authorized"] is False
assert integration["run_id"] == "RUN-080-ROUTE-PAGE-STATIC-LINKAGE-INTEGRATION"
assert integration["pins"]["checkpoint_commit"] == CHECKPOINT_COMMIT
assert integration["pins"]["checkpoint_tree"] == CHECKPOINT_TREE
assert integration["pins"]["application_commit"] == APPLICATION_COMMIT
assert integration["pins"]["application_tree"] == APPLICATION_TREE
assert integration["pins"]["normalized_producer_sha256"] == sha256(PRODUCER_PATH)
assert integration["pins"]["independent_review_sha256"] == sha256(REVIEW_PATH)

counts = integration["counts"]
assert counts["canonical_targets"] == 340
assert counts["reviewed_route_name_gap_targets"] == 244
assert counts["reviewed_residual_targets"] == 12
assert counts["matrix_field_changes"] == 80
assert counts["field_changes"] == {"page_files": 2, "route_names": 78}
assert all(
    counts[key] == 0
    for key in (
        "benchmark_mapping_credit",
        "runtime_credit",
        "browser_credit",
        "executed_test_credit",
        "pass_credit",
        "completion_credit",
    )
)
assert integration["matrix"]["updated_sha256"] == sha256(MATRIX_PATH)
assert integration["matrix"]["base_immutable_projection_sha256"] == integration["matrix"]["updated_immutable_projection_sha256"]
assert integration["matrix"]["base_benchmark_and_credit_projection_sha256"] == integration["matrix"]["updated_benchmark_and_credit_projection_sha256"]

with (AUDIT_DIR / MATRIX_PATH).open(encoding="utf-8-sig", newline="") as handle:
    matrix_rows = list(csv.DictReader(handle))
assert len(matrix_rows) == len({row["feature_id"] for row in matrix_rows}) == 340
assert Counter(row["feature_class"] for row in matrix_rows) == {"H": 300, "D": 40}
gap_ids = {
    field: sorted(row["feature_id"] for row in matrix_rows if row[field] == SENTINEL)
    for field in ("route_names", "route_paths", "page_files", "backend_anchors", "test_anchors")
}
both_gap_ids = sorted(set(gap_ids["route_paths"]) & set(gap_ids["page_files"]))
any_linkage_gap_ids = sorted(set().union(*(set(ids) for ids in gap_ids.values())))
for field, ids in gap_ids.items():
    assert ids == integration["remaining_gaps"][field]
assert both_gap_ids == integration["remaining_gaps"]["both_route_and_page"]
assert len(gap_ids["route_names"]) == counts["remaining_missing_route_names"]
assert len(gap_ids["route_paths"]) == counts["remaining_missing_route_paths"]
assert len(gap_ids["page_files"]) == counts["remaining_missing_page_files"]
assert len(both_gap_ids) == counts["remaining_missing_both_route_and_page"]
assert len(gap_ids["backend_anchors"]) == counts["remaining_missing_backend_anchors"]
assert len(gap_ids["test_anchors"]) == counts["remaining_missing_test_anchors"]

route_classes = review["counts"]["route_classifications"]
page_classes = review["counts"]["page_prompt_classifications"]
residual_statuses = review["counts"]["residual_field_statuses"]
route_name_statuses = review["counts"]["route_name_gap_statuses"]
assert route_classes == {
    "ALIAS_OR_REDIRECT": 1,
    "EXPLICIT_UNMAPPED_SENTINEL": 3003,
    "OWNER": 211,
    "SHARED_RELATION": 3,
}
assert page_classes == {"Evidence gap": 393, "Reviewed": 318}
assert residual_statuses == {"ESTABLISHED": 2, "RETAIN_NOT_ESTABLISHED": 13}
assert route_name_statuses == {"ESTABLISHED": 78, "RETAIN_NOT_ESTABLISHED": 166}

staged: dict[str, bytes] = dict(base_report_bytes)

executive = staged["00-executive-summary.md"].decode("utf-8")
run_077_080_summary = f"""## RUN-077–080 exhaustive static route/page checkpoint

RUN-077 freezes the committed-source universe at {manifest['counts']['primary_route_facade_callsites']:,} primary nine-method `Route::<method>` callsites plus {manifest['counts']['route_like_sentinels_outside_primary_denominator']} separately identified route-like fluent registrar sentinel, {manifest['counts']['fluent_name_callsites']:,} fluent-name callsites, and {manifest['counts']['page_roots']:,} file-backed page roots. The sentinel is explicit and remains outside the primary route-facade denominator.

RUN-078 records a static decision for all {producer['counts']['route_decisions']:,} route-like review rows, {producer['counts']['name_decisions']:,} name callsites, and {producer['counts']['page_decisions']:,} page roots. The route decisions are {route_classes['OWNER']} owner, {route_classes['SHARED_RELATION']} shared-relation, {route_classes['ALIAS_OR_REDIRECT']} alias/redirect, and {route_classes['EXPLICIT_UNMAPPED_SENTINEL']:,} explicit unmapped sentinels. Page prompt-status decisions are {page_classes['Reviewed']} reviewed and {page_classes['Evidence gap']} evidence gaps. RUN-079 records three exact cyclic independent GO reviews with zero invalid decisions and no reviewer writes.

RUN-080 integrates only {counts['field_changes']['route_names']} established route-name fields and {counts['field_changes']['page_files']} established page-file fields, changing {counts['matrix_rows_changed']} matrix rows / {counts['matrix_field_changes']} fields. Retained matrix gaps are {counts['remaining_missing_route_paths']} route path, {counts['remaining_missing_route_names']} route names, {counts['remaining_missing_page_files']} page files, {counts['remaining_missing_both_route_and_page']} combined route/page, {counts['remaining_missing_backend_anchors']} backend anchors, and {counts['remaining_missing_test_anchors']} static test anchors. Immutable and benchmark/credit projections remain equal. Full route/page-to-feature mapping, framework reachability, runtime, application browser, build, executed tests, benchmark mapping, ease, release, Pass, final findings, and completion remain open with zero credit.

"""
executive = replace_once(executive, "## Current raw source census\n", run_077_080_summary + "## Current raw source census\n")
executive = replace_line(
    executive,
    "Live retained gaps are 1 route paths, 6 page files, 1 with both missing",
    "At the RUN-076 checkpoint, retained gaps were 1 route path, 6 page files, 1 with both missing, 0 backend anchors, and 8 static test anchors. That historical feature-side snapshot is superseded by the separately reported RUN-080 matrix state below. Full framework route reachability, complete route/page-to-feature mapping, RUN-072 task-locator refresh, runtime, application browser, executed tests, benchmark mapping, ease, Pass, final findings, and completion remain open and receive zero credit.",
)
executive = replace_line(
    executive,
    "Three independent reconstructions agree, with zero remaining identity conflicts.",
    f"Three independent reconstructions agree, with zero remaining identity conflicts. The normalized Layer-A edge SHA-256 is `131fe9434e94d6158f7349c0522f42a40cf878fb3f7c4a2b7b71d0cc5e4831c0`, and the global target class/module row SHA-256 is `f33d53cf3c9ed7520b683686520eaca9903e50713f438768a8a70819f1c787ac`. The RUN-030/RUN-073 snapshot retained 120 route-anchor, 226 page-anchor, and 116 combined gaps. RUN-076 reduced the feature-side sentinel set; RUN-080 now retains {counts['remaining_missing_route_paths']} route path, {counts['remaining_missing_route_names']} route-name, {counts['remaining_missing_page_files']} page-file, {counts['remaining_missing_both_route_and_page']} combined, {counts['remaining_missing_backend_anchors']} backend-anchor, and {counts['remaining_missing_test_anchors']} static test-anchor gaps after exact cyclic review. The separate exhaustive static universe still contains {route_classes['EXPLICIT_UNMAPPED_SENTINEL']:,} explicitly unmapped route-like rows and {page_classes['Evidence gap']} page evidence gaps, so final feature mapping remains 0/340.",
)
evidence_marker = "- `evidence/source/current-static-linkage-reporting-materialization-wave-06.json`: current report/hash receipt preserving RUN-073 reporting receipts as immutable history.\n"
evidence_additions = (
    evidence_marker
    + f"- `{MANIFEST_PATH}`: RUN-077 exhaustive committed-source route/name/page universe and exact three-part partition manifest; zero downstream credit.\n"
    + f"- `{PRODUCER_PATH}`: RUN-078 normalized {producer['counts']['route_decisions']:,} route-like, {producer['counts']['name_decisions']:,} name, and {producer['counts']['page_decisions']:,} page decisions.\n"
    + f"- `{REVIEW_PATH}`: RUN-079 three-part cyclic independent GO review, zero invalid decisions, zero reviewer writes, and static matrix-field integration only.\n"
    + f"- `{INTEGRATION_PATH}`: RUN-080 integration of {counts['matrix_field_changes']} reviewed route-name/page-file fields with immutable and benchmark/credit projections unchanged.\n"
    + f"- `{OUTPUT_PATH}`: RUN-081 deterministic report/hash receipt preserving all zero-credit boundaries.\n"
)
executive = replace_once(executive, evidence_marker, evidence_additions)
executive = replace_line(
    executive,
    "- `03-feature-to-benchmark-matrix.csv`:",
    f"- `03-feature-to-benchmark-matrix.csv`: 340-row canonical static identity matrix with P1 identity frozen only; retained gaps are {counts['remaining_missing_route_paths']} route path, {counts['remaining_missing_route_names']} route names, {counts['remaining_missing_page_files']} page files, {counts['remaining_missing_backend_anchors']} backend anchors, and {counts['remaining_missing_test_anchors']} static test anchors. Benchmark, ease, executed-test, runtime, browser, release, P2–P8, and completion credit remain explicit and zero.",
)
executive = replace_line(
    executive,
    "- `audit-dashboard.html`:",
    "- `audit-dashboard.html`: progress dashboard generated only from current structured evidence. A fresh RUN-081 audit-artifact viewport/link/console receipt is required after publication and cannot award application-browser or downstream credit.",
)
executive = replace_line(
    executive,
    "2. Continue from RUN-076's retained gaps",
    f"2. Continue from RUN-080's retained matrix gaps—{counts['remaining_missing_route_paths']} route path, {counts['remaining_missing_route_names']} route names, {counts['remaining_missing_page_files']} page files, {counts['remaining_missing_backend_anchors']} backend anchors, and {counts['remaining_missing_test_anchors']} static test anchors—and separately adjudicate the {route_classes['EXPLICIT_UNMAPPED_SENTINEL']:,} explicit unmapped route-like rows and {page_classes['Evidence gap']} page evidence gaps to exact canonical feature IDs. Then establish safe framework reachability and refresh the RUN-072 task locators without inheriting runtime, browser, test, mapping, or completion credit.",
)
staged["00-executive-summary.md"] = executive.encode("utf-8")

module_map = staged["01-repository-module-map.md"].decode("utf-8")
module_map = replace_line(
    module_map,
    "The historical discovery register below remains source provenance.",
    "The historical discovery register below remains source provenance. At RUN-076, the 340-target matrix carried cyclically reviewed feature-side linkage changes on 282 rows / 575 cells and retained 1 route path, 6 page files, 0 backend anchors, and 8 static test anchors. Those historical counts are superseded by the current RUN-080 overlay below. Headless endpoints and support components remain distinct from route-owned pages; full framework reachability, route/page-to-feature mapping, runtime, browser, executed-test, benchmark, ease, Pass, and completion evidence remain open.",
)
module_overlay = f"""## RUN-077–080 exhaustive static route/page overlay

RUN-077 materializes {manifest['counts']['primary_route_facade_callsites']:,} primary route-facade callsites, one separate route-like sentinel, {manifest['counts']['fluent_name_callsites']:,} fluent-name callsites, and {manifest['counts']['page_roots']:,} page roots from the committed application pin. RUN-078 records a decision for every row and RUN-079 independently reviews the A→B, B→C, C→A cycle: {route_classes['OWNER']} route owners, {route_classes['SHARED_RELATION']} shared relations, {route_classes['ALIAS_OR_REDIRECT']} alias/redirect, {route_classes['EXPLICIT_UNMAPPED_SENTINEL']:,} explicit unmapped sentinels, {page_classes['Reviewed']} reviewed pages, and {page_classes['Evidence gap']} page evidence gaps.

RUN-080 establishes {counts['field_changes']['route_names']} route-name and {counts['field_changes']['page_files']} page-file matrix fields. It retains {counts['remaining_missing_route_names']} route-name, {counts['remaining_missing_route_paths']} route-path, {counts['remaining_missing_page_files']} page-file, {counts['remaining_missing_backend_anchors']} backend-anchor, and {counts['remaining_missing_test_anchors']} static test-anchor sentinels. These are reviewed static-source classifications and locators, not framework-expanded routes, runtime reachability, rendered pages, executed tests, or final route/page-to-`FEATURE-ID` mappings.

"""
module_map = replace_once(module_map, "## Candidate register\n", module_overlay + "## Candidate register\n")
staged["01-repository-module-map.md"] = module_map.encode("utf-8")

module_findings = staged["07-module-findings.md"].decode("utf-8")
module_findings = replace_once(
    module_findings,
    "## RUN-074–076 feature-side static linkage\n",
    "## RUN-074–076 historical feature-side static linkage\n",
)
module_findings = replace_once(
    module_findings,
    "| Measure | Current result | Credit boundary |\n",
    "| Measure | RUN-076 result | Credit boundary |\n",
)
findings_overlay = f"""## RUN-077–080 route/page universe and reviewed classification

The complete committed static input contains {manifest['counts']['primary_route_facade_callsites']:,} primary route-facade callsites, one separately scoped route-like sentinel, {manifest['counts']['fluent_name_callsites']:,} fluent-name callsites, and {manifest['counts']['page_roots']:,} page roots. Three producer partitions materialized a decision record for every row; three different-agent cyclic reviews returned GO with zero invalid decisions and zero writes.

| Measure | Independently reviewed result | Credit boundary |
|---|---:|---|
| Route-like rows | {review['counts']['route_decisions_reviewed']:,} | {route_classes['OWNER']} owner · {route_classes['SHARED_RELATION']} shared · {route_classes['ALIAS_OR_REDIRECT']} alias · {route_classes['EXPLICIT_UNMAPPED_SENTINEL']:,} explicit unmapped |
| Fluent-name decisions | {review['counts']['name_decisions_reviewed']:,} | static names only; framework reachability unproved |
| Page-root decisions | {review['counts']['page_decisions_reviewed']:,} | {page_classes['Reviewed']} reviewed · {page_classes['Evidence gap']} evidence gap |
| Residual scoped cells | {review['counts']['residual_scoped_cells_reviewed']} | {residual_statuses['ESTABLISHED']} established · {residual_statuses['RETAIN_NOT_ESTABLISHED']} retained |
| Route-name gap targets | {review['counts']['route_name_gap_decisions_reviewed']} | {route_name_statuses['ESTABLISHED']} established · {route_name_statuses['RETAIN_NOT_ESTABLISHED']} retained |
| RUN-080 matrix rows / fields changed | {counts['matrix_rows_changed']} / {counts['matrix_field_changes']} | route names {counts['field_changes']['route_names']} · page files {counts['field_changes']['page_files']} only |
| Remaining route-path / route-name / page-file gaps | {counts['remaining_missing_route_paths']} / {counts['remaining_missing_route_names']} / {counts['remaining_missing_page_files']} | explicit matrix sentinels |
| Runtime / application browser / executed tests / completion | 0 / 0 / 0 / 0 | unchanged |

All classification, review, and integration evidence is committed-source static evidence. It establishes no framework route execution, build resolution, final feature mapping, benchmark equivalence, ease, release, Pass, finding, or audit-completion credit.

"""
module_findings = replace_once(module_findings, "## Exact accounting\n", findings_overlay + "## Exact accounting\n")
staged["07-module-findings.md"] = module_findings.encode("utf-8")

gaps_report = staged["13-unresolved-questions-and-evidence-gaps.md"].decode("utf-8")
gaps_report = replace_line(
    gaps_report,
    "| Required reporting paths |",
    "| Required reporting paths | 18 / 18 prompt-required files or directories present; RUN-076 dashboard verification is immutable history for superseded HTML, and the RUN-081 generated dashboard requires a fresh audit-artifact viewport/link/console receipt | Presence and audit-artifact verification make the reporting contract inspectable but grant no application execution, final-finding, Pass, or completion credit. Gate 26 remains open. | Complete the semantic, execution, benchmark, Pass 8, final reconciliation, and no-live-agent gates; repeat audit-dashboard verification after every later HTML change. |",
)
gaps_report = replace_line(
    gaps_report,
    "| Runtime routes |",
    f"| Runtime routes | All 38 route PHP files classified at source level; {manifest['counts']['primary_route_facade_callsites']:,} primary route-facade callsites plus one separate route-like sentinel and {manifest['counts']['fluent_name_callsites']:,} fluent-name callsites; RUN-079 independently reviews all {review['counts']['route_decisions_reviewed']:,} route-like decisions | The static result retains {route_classes['EXPLICIT_UNMAPPED_SENTINEL']:,} explicit unmapped sentinels and does not include framework/provider expansion or prove reachability. The historical 3,024-route denominator cannot be inherited. | Hydrated dependencies in a disposable current-source runtime, exact database ownership/cleanup, and a separately bounded framework/provider route lane reconciled to every static route-file and route-like row. |",
)
gaps_report = replace_line(
    gaps_report,
    "| Inertia pages |",
    f"| Inertia pages | {manifest['counts']['page_roots']}/{manifest['counts']['page_roots']} committed file-backed page roots have independently reviewed decision records: {page_classes['Reviewed']} `Reviewed`, {page_classes['Evidence gap']} `Evidence gap` | Static triage covers the denominator, but prompt classification remains incomplete because {page_classes['Evidence gap']} roots still lack enough exact ownership evidence. Runtime reachability, build resolution, rendered browser behavior, and final route/page-to-feature mapping remain open. | Resolve every page evidence gap to an exact canonical owner or retained final evidence gap, reconcile all roots to safely expanded framework routes and frozen `FEATURE-ID`s, prove build resolution, and observe every safely reachable current-build page under approved roles/Sites. |",
)
gaps_report = replace_line(
    gaps_report,
    "| Canonical features |",
    f"| Canonical features | Static canonical identity remains 340 targets: 300 H, 40 D, zero M; RUN-080 integrates only {counts['matrix_field_changes']} independently reviewed route-name/page-file fields | RUN-080 changes {counts['matrix_rows_changed']} rows / {counts['matrix_field_changes']} fields from the RUN-076 matrix and retains {counts['remaining_missing_route_paths']} route-path, {counts['remaining_missing_route_names']} route-name, {counts['remaining_missing_page_files']} page-file, {counts['remaining_missing_both_route_and_page']} combined, {counts['remaining_missing_backend_anchors']} backend, and {counts['remaining_missing_test_anchors']} static test gaps. Immutable and benchmark/credit projections remain equal; 0/340 mapping credit remains. | Continue exact route/page-to-feature adjudication, safe framework reachability, retained test linkage, and separate RUN-072 task-locator refresh without awarding runtime, browser, test, benchmark, ease, release, or completion credit. |",
)
gaps_report = replace_line(
    gaps_report,
    "| Agent universe and writer rule |",
    "| Agent universe and writer rule | RUN-001 through RUN-081 represented at the current reporting checkpoint; finalization gate false | RUN-077 manifest, RUN-078 producer partitions, RUN-079 cyclic independent reviews, RUN-080 root matrix integration, and RUN-081 deterministic reporting are represented. Static classification, review GO, hashes, or matrix linkage do not satisfy runtime, mapping, Pass 8, finalization, or completion. | Continue all semantic/execution gates, then dispatch fresh Pass 8/final cross-reviewers, represent every return, verify the final dashboard, and prove no agent remains live at finalization. |",
)
new_lineage = f"""## RUN-074–076 static-linkage lineage

All 288 producer targets and their field decisions were reviewed cyclically by a different agent. RUN-076 integrates only the five permitted linkage columns and retains explicit sentinel sets. This closes no framework route, page-universe, runtime, browser, executed-test, benchmark, ease, Pass, final-finding, or audit-completion gate.

## RUN-077–081 route/page classification and reporting lineage

RUN-077 freezes the exhaustive committed-source route/name/page universe. RUN-078 records all {producer['counts']['route_decisions']:,} route-like, {producer['counts']['name_decisions']:,} name, and {producer['counts']['page_decisions']:,} page decisions. RUN-079's cyclic A→B, B→C, C→A independent reviews are all GO with zero invalid decisions and no writes. RUN-080 integrates only {counts['field_changes']['route_names']} route-name and {counts['field_changes']['page_files']} page-file fields; RUN-081 materializes current reports and their exact hash register. Full route/page-to-feature mapping, framework reachability, runtime, build, browser, executed tests, benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit.

"""
old_lineage = """## RUN-074–076 static-linkage lineage

All 288 producer targets and their field decisions were reviewed cyclically by a different agent. RUN-076 integrates only the five permitted linkage columns and retains explicit sentinel sets. This closes no framework route, page-universe, runtime, browser, executed-test, benchmark, ease, Pass, final-finding, or audit-completion gate.

"""
gaps_report = replace_once(gaps_report, old_lineage, new_lineage)
gaps_report = replace_line(
    gaps_report,
    "The current `03-feature-to-benchmark-matrix.csv` has 340 canonical static target rows:",
    f"The current `03-feature-to-benchmark-matrix.csv` has 340 canonical static target rows: 300 H and 40 D. RUN-080 changes only independently reviewed route-name/page-file fields, from RUN-076 base `{integration['matrix']['base_sha256']}` to `{integration['matrix']['updated_sha256']}`. Retained matrix gaps are {counts['remaining_missing_route_paths']} route path, {counts['remaining_missing_route_names']} route names, {counts['remaining_missing_page_files']} page files, {counts['remaining_missing_both_route_and_page']} combined, {counts['remaining_missing_backend_anchors']} backend anchors, and {counts['remaining_missing_test_anchors']} static test anchors. Immutable and benchmark/credit projections are unchanged; runtime, browser, executed-test, benchmark, ease, release, P2–P8, Pass, and completion credit remain zero. RUN-072 task scripts/scorecard remain an unexecuted historical locator snapshot and were not silently relabelled current.",
)
staged["13-unresolved-questions-and-evidence-gaps.md"] = gaps_report.encode("utf-8")

findings = json.loads(staged["findings.json"].decode("utf-8"))
findings["pins"].update({
    "audit_checkpoint_parent": CHECKPOINT_COMMIT,
    "route_page_manifest_sha256": sha256(MANIFEST_PATH),
    "route_page_normalized_producer_sha256": sha256(PRODUCER_PATH),
    "route_page_independent_review_sha256": sha256(REVIEW_PATH),
    "route_page_static_linkage_integration_sha256": sha256(INTEGRATION_PATH),
    "base_matrix_sha256": integration["matrix"]["base_sha256"],
    "current_matrix_sha256": integration["matrix"]["updated_sha256"],
})
findings["counts"].update({
    "route_page_primary_route_facade_callsites": manifest["counts"]["primary_route_facade_callsites"],
    "route_page_route_like_review_rows": manifest["counts"]["static_route_like_review_rows"],
    "route_page_fluent_name_callsites": manifest["counts"]["fluent_name_callsites"],
    "route_page_page_roots": manifest["counts"]["page_roots"],
    "route_page_explicit_unmapped_route_rows": route_classes["EXPLICIT_UNMAPPED_SENTINEL"],
    "route_page_page_evidence_gaps": page_classes["Evidence gap"],
    "route_page_matrix_rows_changed": counts["matrix_rows_changed"],
    "route_page_matrix_field_changes": counts["matrix_field_changes"],
    "remaining_route_name_gap_targets": counts["remaining_missing_route_names"],
    "remaining_non_route_name_scoped_gap_targets": counts["targets_with_any_remaining_scoped_gap"],
    "remaining_any_linkage_gap_targets": len(any_linkage_gap_ids),
})
findings["counts"].pop("remaining_static_linkage_gap_targets", None)
findings["current_static_linkage"] = {
    "status": "RUN_074_TO_080_INDEPENDENTLY_REVIEWED_COMMITTED_SOURCE_LINKAGE_ONLY",
    "remaining_gap_counts": {
        "route_paths": counts["remaining_missing_route_paths"],
        "route_names": counts["remaining_missing_route_names"],
        "page_files": counts["remaining_missing_page_files"],
        "both_route_and_page": counts["remaining_missing_both_route_and_page"],
        "backend_anchors": counts["remaining_missing_backend_anchors"],
        "test_anchors": counts["remaining_missing_test_anchors"],
    },
    "framework_route_reachability": False,
    "complete_route_page_to_feature_mapping": False,
    "runtime": False,
    "browser": False,
    "executed_tests": False,
    "benchmark_mapping": False,
    "ease": False,
    "pass": False,
    "completion": False,
}
findings["current_route_page_classification"] = {
    "status": "THREE_PART_CYCLIC_INDEPENDENT_REVIEW_GO_STATIC_ONLY",
    "route_universe": {
        "primary_route_facade_callsites": manifest["counts"]["primary_route_facade_callsites"],
        "route_like_sentinels_outside_primary_denominator": manifest["counts"]["route_like_sentinels_outside_primary_denominator"],
        "static_route_like_review_rows": manifest["counts"]["static_route_like_review_rows"],
        "fluent_name_callsites": manifest["counts"]["fluent_name_callsites"],
        "classifications": route_classes,
    },
    "page_universe": {
        "page_roots": manifest["counts"]["page_roots"],
        "prompt_classifications": page_classes,
    },
    "cyclic_independent_reviews_go": review["counts"]["go_reviews"],
    "invalid_decisions": review["counts"]["invalid_decisions"],
    "review_artifacts_wrote_files": review["counts"]["review_artifacts_wrote_files"],
    "final_feature_mappings": 0,
    "runtime": False,
    "application_browser": False,
    "executed_tests": False,
    "completion": False,
}
staged["findings.json"] = (json.dumps(findings, indent=2, ensure_ascii=False) + "\n").encode("utf-8")

unchanged_reports = set(REPORT_PATHS) - {
    "00-executive-summary.md",
    "01-repository-module-map.md",
    "07-module-findings.md",
    "13-unresolved-questions-and-evidence-gaps.md",
    "findings.json",
}
assert all(staged[path] == base_report_bytes[path] for path in unchanged_reports)
assert all(staged[path] != base_report_bytes[path] for path in set(REPORT_PATHS) - unchanged_reports)

artifact_paths = [MANIFEST_PATH, PRODUCER_PATH, REVIEW_PATH, INTEGRATION_PATH, MATRIX_PATH]
artifact_paths.extend(item["path"] for item in producer["pins"]["raw_producers"])
artifact_paths.extend(item["generator"] for item in producer["pins"]["raw_producers"])
artifact_paths.extend(item["path"] for item in review["pins"]["raw_reviews"])
artifact_paths.extend([
    manifest["pins"]["generator"],
    producer["pins"]["generator"],
    review["pins"]["generator"],
    integration["pins"]["generator"],
    PREVIOUS_REPORTING_PATH,
])
artifact_paths = list(dict.fromkeys(artifact_paths))
artifact_register = {
    path: {
        "sha256": sha256(path),
        "role": (
            "current_matrix" if path == MATRIX_PATH else
            "historical_reporting_receipt" if path == PREVIOUS_REPORTING_PATH else
            "generator" if path.startswith("generators/") else
            "current_wave_evidence"
        ),
    }
    for path in artifact_paths
}

output_hashes = {path: sha256_bytes(value) for path, value in staged.items()}
receipt = {
    "schema_version": 1,
    "run_id": "RUN-081-ROUTE-PAGE-REPORTING-MATERIALIZATION",
    "status": "CURRENT_ROUTE_PAGE_REPORTING_REFRESHED_ZERO_DOWNSTREAM_CREDIT",
    "generated_on": "2026-08-25",
    "pins": {
        "checkpoint_commit": CHECKPOINT_COMMIT,
        "checkpoint_tree": CHECKPOINT_TREE,
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
        "manifest_sha256": sha256(MANIFEST_PATH),
        "normalized_producer_sha256": sha256(PRODUCER_PATH),
        "independent_review_sha256": sha256(REVIEW_PATH),
        "integration_sha256": sha256(INTEGRATION_PATH),
        "base_matrix_sha256": integration["matrix"]["base_sha256"],
        "updated_matrix_sha256": integration["matrix"]["updated_sha256"],
    },
    "architecture_rule": "One operating organisation across multiple Sites; Site access, exact action permissions, ownership, consent and privacy are the boundaries.",
    "counts": {
        "canonical_features": 340,
        "H_features": 300,
        "D_features": 40,
        "primary_route_facade_callsites": manifest["counts"]["primary_route_facade_callsites"],
        "route_like_sentinels_outside_primary_denominator": manifest["counts"]["route_like_sentinels_outside_primary_denominator"],
        "route_like_review_rows": review["counts"]["route_decisions_reviewed"],
        "fluent_name_callsites": review["counts"]["name_decisions_reviewed"],
        "page_roots": review["counts"]["page_decisions_reviewed"],
        "route_classifications": route_classes,
        "page_prompt_classifications": page_classes,
        "cyclic_independent_go_reviews": review["counts"]["go_reviews"],
        "invalid_decisions": review["counts"]["invalid_decisions"],
        "matrix_rows_changed": counts["matrix_rows_changed"],
        "matrix_field_changes": counts["matrix_field_changes"],
        "field_changes": counts["field_changes"],
        "remaining_missing_route_paths": counts["remaining_missing_route_paths"],
        "remaining_missing_route_names": counts["remaining_missing_route_names"],
        "remaining_missing_page_files": counts["remaining_missing_page_files"],
        "remaining_missing_both_route_and_page": counts["remaining_missing_both_route_and_page"],
        "remaining_missing_backend_anchors": counts["remaining_missing_backend_anchors"],
        "remaining_missing_test_anchors": counts["remaining_missing_test_anchors"],
        "remaining_targets_with_any_non_route_name_scoped_gap": counts["targets_with_any_remaining_scoped_gap"],
        "remaining_targets_with_any_linkage_gap_including_route_names": len(any_linkage_gap_ids),
        "final_feature_mappings": 0,
        "runtime_credit": 0,
        "application_browser_credit": 0,
        "executed_test_credit": 0,
        "completion_credit": 0,
    },
    "inputs": {path: sha256(path) for path in (MANIFEST_PATH, PRODUCER_PATH, REVIEW_PATH, INTEGRATION_PATH, MATRIX_PATH)},
    "artifact_register": artifact_register,
    "generator": {GENERATOR_PATH: sha256(GENERATOR_PATH)},
    "history": {
        PREVIOUS_REPORTING_PATH: {"sha256": PREVIOUS_REPORTING_SHA256, "rewritten": False},
        "checkpoint_base_reports": {
            path: {
                "sha256": digest,
                "byte_preserved": path in unchanged_reports,
                "superseded_by_run_081": path not in unchanged_reports,
            }
            for path, digest in BASE_REPORT_HASHES.items()
        },
    },
    "outputs": output_hashes,
    "matrix_validation": {
        "rows": 340,
        "unique_feature_ids": 340,
        "classes": {"H": 300, "D": 40},
        "changed_columns": sorted(counts["field_changes"]),
        "immutable_projection_equal": True,
        "benchmark_and_credit_projection_equal": True,
        "gap_lists_recomputed_from_live_csv": True,
    },
    "evidence_boundary": {
        "exhaustive_committed_static_route_name_page_universe_materialized": True,
        "three_part_cyclic_independent_static_review_go": True,
        "reviewed_route_name_and_page_file_fields_integrated": True,
        "complete_route_page_to_feature_mapping": False,
        "framework_route_reachability": False,
        "build_resolution": False,
        "run_072_locator_refresh": False,
    },
    "credit_boundary": {
        "artifact_presence": False,
        "route_or_page_presence_as_feature_mapping": False,
        "final_finding": False,
        "application_browser": False,
        "runtime": False,
        "build": False,
        "executed_tests": False,
        "ease": False,
        "benchmark_mapping": False,
        "final_no_match": False,
        "release": False,
        "pass": False,
        "completion": False,
        "audit_complete": False,
    },
    "attestation": "Deterministic RUN-081 reporting refresh from exact RUN-077 through RUN-080 static evidence. Reports are reconstructed from checkpoint 87826adc6fb8 and atomically published. No application source, runtime, browser, build, tests, database, network, benchmark mapping, ease, Pass, finding, or completion credit is created.",
}
receipt_bytes = (json.dumps(receipt, indent=2, ensure_ascii=False) + "\n").encode("utf-8")

with tempfile.TemporaryDirectory(prefix=".run081-reporting-", dir=AUDIT_DIR) as temporary:
    stage = Path(temporary)
    for relative, value in staged.items():
        target = stage / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(value)
    receipt_target = stage / OUTPUT_PATH
    receipt_target.parent.mkdir(parents=True, exist_ok=True)
    receipt_target.write_bytes(receipt_bytes)
    assert {path: sha256_bytes((stage / path).read_bytes()) for path in REPORT_PATHS} == output_hashes
    parsed_receipt = json.loads(receipt_target.read_text(encoding="utf-8"))
    assert parsed_receipt["outputs"] == output_hashes
    assert all(value is False for value in parsed_receipt["credit_boundary"].values())

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
assert sha256(PREVIOUS_REPORTING_PATH) == PREVIOUS_REPORTING_SHA256
print(json.dumps({
    "run_id": receipt["run_id"],
    "status": receipt["status"],
    "matrix_sha256": integration["matrix"]["updated_sha256"],
    "outputs": output_hashes,
    "receipt_sha256": sha256(OUTPUT_PATH),
}, indent=2))
