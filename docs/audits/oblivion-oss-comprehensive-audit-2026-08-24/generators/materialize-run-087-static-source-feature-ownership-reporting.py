#!/usr/bin/env python3
"""Materialize RUN-087 reporting for reviewed bounded source ownership.

RUN-086/R may authorize one narrow evidence class only:
STATIC_SOURCE_FEATURE_OWNERSHIP for 530 reviewed route/page source records.
This successor leaves the 340-row matrix and all runtime, browser, test,
benchmark, Pass, finding, and completion boundaries unchanged.
"""

from __future__ import annotations

import hashlib
import json
import subprocess
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_RELATIVE = "evidence/source/current-run-087-static-source-feature-ownership-reporting.json"

AUDIT_HEAD = "821314b6a8c3c279ff7937d4cd2ee1576b0a47d6"
AUDIT_TREE = "1564890364fd1c7ee54455075fa90ebe22801a7a"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
LEDGER_RELATIVE = "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json"
LEDGER_SHA256 = "bfec52a9bed7176045457e8920bdc7b65e1c1d5c5eeb28a266f49c12acf69bcf"
LEDGER_GENERATOR_RELATIVE = "generators/build-reviewed-static-route-page-feature-ownership-wave-10.py"
LEDGER_GENERATOR_SHA256 = "d6933ea5d4078cc8de459552d43a471d57974cb271977654fdb4d8866b387567"
REVIEW_RELATIVE = "evidence/source/raw-run-086r-independent-reviewed-static-route-page-feature-ownership-wave-10.json"
REVIEW_SHA256 = "56c4832af941353aaf230ca17c792ea7191c6aebfc05bc1c511a757d5998d699"

CURRENT_INPUTS = {
    "00-executive-summary.md": "1f28efae63107d7c872611ec55147d7a1af5ccac90af474de3e522433f382651",
    "01-repository-module-map.md": "60198fc16c70f2f52da487a6b59f1bfc153a6fc80973821bd6e906d8eac2ff60",
    "13-unresolved-questions-and-evidence-gaps.md": "6f4b7ddf5b7bc296bb7c04f4f23bf8d09e197675ac73bc9c1ceec3ba2db7c792",
    "findings.json": "eb06c23b7940d3602f9b34fa7dc68da828e54b81236ea037a9d06edeff949e46",
    "generators/build-current-audit-dashboard.py": "31a5f4f0ae62f945377620c6d4ba011126555b9cf00c379eeae187837e5cda89",
    "evidence/source/current-run-085-reporting-materialization-wave-09.json": "d07f39f8442ea137e999d62de2045ebd7d442831477e74da8bc42ba4f7949e5c",
    "evidence/browser/current-audit-dashboard-verification-run-085-wave-09.json": "ad33c44f3b2266ccff66caec030d41dfbbcfa844026f4eaacb1356338e921df6",
}

MATERIALIZED_OUTPUTS = {
    "00-executive-summary.md": "2046b20a3687f8e7691aff2eb6203c5cc1ec0454c68939de8c4ef3141f31cae8",
    "01-repository-module-map.md": "1e1f4a096b895cd13d04ebf246552938fa92d2e0d93641b8f866c6c7833008ff",
    "13-unresolved-questions-and-evidence-gaps.md": "df5cca535d4420d5f988031fefd917aa9f17ec4f6df06e9ea9a5952aad15cef1",
    "findings.json": "afdecfec73b0b7a55ba81b26df274fa724e93ab4583d79763639d6cc223963a4",
    "generators/build-current-audit-dashboard.py": "91e6ce125b3ca447db942d97ba968f1371b4d7beb7f3e229a9b1b89f74a0aaf7",
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
    "evidence/source/raw-run-071a-completion-gate-accounting-wave-04.json": "6f481a03a2ebba8fcfeaef15735b37d3137a14bd90977db8f8c566ed0ff9fa7d",
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


def patch_text(relative: str, replacements: list[tuple[str, str, str]]) -> None:
    text = path(relative).read_text(encoding="utf-8")
    assert "\r" not in text, relative
    for old, new, label in replacements:
        text = replace_once_or_present(text, old, new, f"{relative}:{label}")
    write_lf(relative, text)


def assert_inputs() -> tuple[dict[str, Any], dict[str, Any]]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == AUDIT_HEAD
    assert git("rev-parse", "HEAD^{tree}") == AUDIT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("status", "--porcelain", "--", "app", "routes", "resources/js") == ""

    for relative, expected in CURRENT_INPUTS.items():
        actual = sha256_file(relative)
        if relative in MATERIALIZED_OUTPUTS:
            assert actual in {expected, MATERIALIZED_OUTPUTS[relative]}, relative
        else:
            assert actual == expected, relative
    for relative, expected in PRESERVED.items():
        assert sha256_file(relative) == expected, relative
    assert sha256_file(LEDGER_RELATIVE) == LEDGER_SHA256
    assert sha256_file(LEDGER_GENERATOR_RELATIVE) == LEDGER_GENERATOR_SHA256
    assert sha256_file(REVIEW_RELATIVE) == REVIEW_SHA256

    ledger = read_json(LEDGER_RELATIVE)
    review = read_json(REVIEW_RELATIVE)
    selected = ledger["counts"]["selected"]
    assert ledger["status"] == "PENDING_FRESH_INDEPENDENT_LEDGER_REVIEW_ZERO_CREDIT"
    assert ledger["pins"]["application_commit"] == APPLICATION_COMMIT
    assert ledger["pins"]["application_tree"] == APPLICATION_TREE
    assert ledger["pins"]["generator_sha256"] == LEDGER_GENERATOR_SHA256
    assert ledger["pins"]["inputs"]["03-feature-to-benchmark-matrix.csv"] == MATRIX_SHA256
    assert ledger["record_set"] == {
        "count": 530,
        "source_record_key_list_sha256": "bcfd68e4b34d9c936715846c161ea9d4a90f9090a9009adbcd2ed4e3e8e9c85a",
        "ledger_row_sha256_list_sha256": "aa041749de94ce28f92631c1d95279f29ea4a6aadea7a0c2d3b5bdb4af5cfabd",
        "records_sha256": "f09b3af490589118a2818092436bdc976863768eba0f6578db1dc3e0b58726e8",
    }
    assert (selected["records"], selected["route_records"], selected["page_records"]) == (530, 212, 318)
    assert selected["distinct_feature_ids"] == 235
    assert selected["route_classifications"] == {"OWNER": 211, "ALIAS_OR_REDIRECT": 1}
    assert ledger["counts"]["excluded"]["shared_relation_route_records"] == 3
    assert ledger["counts"]["source_universe"]["denominator_complete"] is False
    assert ledger["producer_credit_boundary"]["static_source_feature_ownership_credit"] is False
    assert all(
        ledger["producer_credit_boundary"][key] is False
        for key in (
            "complete_route_page_feature_crosswalk",
            "framework_route_reachability_credit",
            "navigation_credit",
            "runtime_credit",
            "database_credit",
            "build_credit",
            "application_browser_credit",
            "executed_test_credit",
            "benchmark_credit",
            "ease_credit",
            "pass_credit",
            "final_finding_credit",
            "completion_credit",
            "audit_complete",
        )
    )

    assert review["status"] == "GO_THREE_PART_FRESH_INDEPENDENT_REVIEW_STATIC_SOURCE_FEATURE_OWNERSHIP_ONLY"
    assert review["decision"] == {
        "verdict": "GO",
        "discrepancies": 0,
        "static_source_feature_ownership_authorized": True,
        "complete_route_page_feature_crosswalk_authorized": False,
        "matrix_mutation_authorized": False,
        "downstream_credit_authorized": False,
        "gate_4_complete": False,
    }
    assert review["pins"]["producer_sha256"] == LEDGER_SHA256
    assert review["pins"]["generator_sha256"] == LEDGER_GENERATOR_SHA256
    assert review["verified_counts"] == {
        "partition_reviews": 3,
        "go_reviews": 3,
        "records": 530,
        "route_records": 212,
        "page_records": 318,
        "distinct_feature_ids": 235,
        "shared_relation_route_records_excluded": 3,
        "review_discrepancies": 0,
        "reviewer_written_files": 0,
        "matrix_rows_changed": 0,
        "matrix_cells_changed": 0,
    }
    verified_global_record_set = review["verified_global_record_set"]
    assert verified_global_record_set["records_sha256"] == ledger["record_set"]["records_sha256"]
    assert verified_global_record_set["source_record_key_list_sha256"] == ledger["record_set"][
        "source_record_key_list_sha256"
    ]
    assert verified_global_record_set["ledger_row_sha256_list_sha256"] == ledger["record_set"][
        "ledger_row_sha256_list_sha256"
    ]
    assert verified_global_record_set["feature_id_list_sha256"] == ledger["feature_set"][
        "feature_id_list_sha256"
    ]
    assert verified_global_record_set["feature_id_list_serialization"] == (
        "sorted unique UTF-8 FEATURE-ID values joined with LF (0x0A), no terminal LF; "
        "matches producer canonical_list_sha256"
    )
    assert verified_global_record_set["serialization_reconciliation"] == {
        "status": "RESOLVED_BEFORE_PUBLICATION",
        "terminal_lf_variant_sha256": "1c051e7bd598543954043216ae2a75f00a516b34a43fc9a5f368739c347f28ca",
        "set_membership_changed": False,
        "normalized_hash_matches_producer": True,
    }
    assert [item["partition_id"] for item in review["partition_reviews"]] == ["A", "B", "C"]
    assert all(item["verdict"] == "GO" for item in review["partition_reviews"])
    assert all(item["discrepancy_count"] == 0 for item in review["partition_reviews"])
    assert all(item["wrote_files"] is False and item["write_scope"] == [] for item in review["partition_reviews"])
    assert review["credit_boundary"]["STATIC_SOURCE_FEATURE_OWNERSHIP"] is True
    assert all(
        review["credit_boundary"][key] is False
        for key in (
            "feature_mapping_complete",
            "framework_route_reachability",
            "runtime",
            "database",
            "build",
            "application_browser",
            "executed_tests",
            "benchmark_mapping",
            "ease",
            "pass",
            "final_finding",
            "completion",
            "audit_complete",
        )
    )
    return ledger, review


def patch_reports() -> None:
    summary_relative = "00-executive-summary.md"
    summary = path(summary_relative).read_text(encoding="utf-8")
    summary_section = """
## RUN-086/R bounded static source feature ownership checkpoint

RUN-086 materializes 530 exact record-level owner relations from the already cyclically reviewed static source decisions: 212 route records (211 `OWNER` and 1 `ALIAS_OR_REDIRECT`) plus 318 page-root records, spanning 235 canonical `FEATURE-ID`s. Three fresh read-only reviewers independently reconstruct partitions A, B, and C from the pinned matrix, manifest, classification, and prior review; RUN-086R returns three GO verdicts with zero discrepancies and zero reviewer writes.

This awards only bounded `STATIC_SOURCE_FEATURE_OWNERSHIP` for those 530 records. It does not establish the framework-expanded canonical route/page denominator, a complete route/page-to-feature crosswalk, shared-relation ownership, route reachability, navigation, runtime, database, build, browser, executed tests, benchmarks, ease, Passes, findings, or completion. Gate 4 therefore remains incomplete, and the 340-row matrix remains byte-identical at `dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390`.
"""
    summary = insert_before_once(summary, "\n## Current raw source census\n", summary_section, "RUN-086/R section")
    evidence_marker = "- `evidence/source/current-run-085-reporting-materialization-wave-09.json`: deterministic current reporting/hash receipt preserving matrix, benchmark, usability, visual, inventory, reports 07/08/10/11/12, and all downstream zero-credit boundaries.\n"
    evidence_addition = evidence_marker + (
        "- `generators/build-reviewed-static-route-page-feature-ownership-wave-10.py`: deterministic RUN-086 producer for the bounded 530-record static source ownership ledger.\n"
        "- `evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json`: 212 route plus 318 page source-owner records across 235 canonical FEATURE-IDs, pending no credit inside the producer itself.\n"
        "- `evidence/source/raw-run-086r-independent-reviewed-static-route-page-feature-ownership-wave-10.json`: three-part fresh independent GO review with zero discrepancies, authorizing only bounded static source feature ownership.\n"
        "- `evidence/source/current-run-087-static-source-feature-ownership-reporting.json`: deterministic RUN-087 reporting receipt; matrix and every execution, benchmark, Pass, finding, and completion boundary remain unchanged.\n"
    )
    summary = replace_once_or_present(summary, evidence_marker, evidence_addition, "RUN-086/R evidence links")
    write_lf(summary_relative, summary)

    map_relative = "01-repository-module-map.md"
    module_map = path(map_relative).read_text(encoding="utf-8")
    map_section = """
## RUN-086/R bounded static source ownership overlay

RUN-086/R independently establishes 530 one-to-one static source-owner records: 212 route records and 318 rendered page-root records across 235 canonical `FEATURE-ID`s. Each row retains its exact source record, anchor, source/blob/content digest, canonical identity projection, and ledger-row digest. The three `SHARED_RELATION` routes remain excluded pending a discriminator.

This ledger is a bounded source subset, not an all-route/all-page crosswalk. `FEATURE-ID` ownership must not be inherited through support imports, route-group prefixes, controller containment, whole-file symbols, names, presence, or candidate overlap. The framework-expanded route/page denominator, reachability, Site/permission/privacy behaviour, runtime, browser, test, benchmark, Pass, finding, and completion evidence all remain open.
"""
    module_map = insert_before_once(module_map, "\n## Candidate register\n", map_section, "RUN-086/R map section")
    write_lf(map_relative, module_map)

    gaps_relative = "13-unresolved-questions-and-evidence-gaps.md"
    gaps = path(gaps_relative).read_text(encoding="utf-8")
    old_canonical = "| Canonical features | Static canonical identity remains 340 targets: 300 H, 40 D, zero M; RUN-082 changes 0 matrix rows / 0 cells and leaves `dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390` byte-identical | RUN-080's retained gaps remain 1 route-path, 166 route-name, 4 page-file, 1 combined, 0 backend, and 8 static test gaps. RUN-082R independently reviews candidate-only static evidence but authorizes no mapping or matrix mutation; 0/340 mapping credit remains. | Adjudicate ownership and the retained gaps separately without awarding runtime, browser, test, benchmark, ease, release, or completion credit. |"
    new_canonical = "| Canonical features | Static canonical identity remains 340 targets: 300 H, 40 D, zero M; RUN-086/R establishes 530 bounded static source-owner records (212 routes + 318 pages) across 235 FEATURE-IDs while the matrix remains byte-identical at `dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390` | RUN-086/R awards only bounded static source ownership. Gate 4 remains incomplete because the framework-expanded canonical route/page denominator, 3 shared relations, residual ownership, reachability, and full crosswalk remain open; matrix target mapping remains 0/340. | Finish the canonical denominator and residual ownership adjudication without inheriting FEATURE-IDs from prefixes, imports, containment, names, or presence, and without awarding runtime, browser, test, benchmark, ease, release, or completion credit. |"
    gaps = replace_once_or_present(gaps, old_canonical, new_canonical, "canonical features row")
    old_agent = "| Agent universe and writer rule | RUN-001 through RUN-085 represented at the current reporting checkpoint; finalization gate false | RUN-082/R provide candidate-only route/page relations, RUN-083 reports and verifies its exact dashboard, RUN-084/R provide the reviewed full page tree, RUN-084B/BR provide the reviewed backend structural ledger, and RUN-084 preflight is signed out/build-unattributed. None satisfies whole-file semantics, runtime, mapping, Pass 8, finalization, or completion. | Complete ownership adjudication and all semantic/execution gates, then dispatch fresh Pass 8/final cross-reviewers, verify the final dashboard, and prove no agent remains live at finalization. |"
    new_agent = "| Agent universe and writer rule | RUN-001 through RUN-087 represented at the current reporting checkpoint; finalization gate false | RUN-086/R adds 530 three-part independently reviewed static source-owner records only; RUN-087 reports that bounded overlay. The complete route/page denominator, whole-file semantics, runtime, browser, tests, benchmarks, Pass 8, finalization, and completion remain open. | Complete residual ownership and every semantic/execution gate, then dispatch fresh Pass 8/final cross-reviewers, verify the final dashboard, and prove no agent remains live at finalization. |"
    gaps = replace_once_or_present(gaps, old_agent, new_agent, "agent row")
    old_heading = "## RUN-077–085 route/page, page-tree, backend and reporting lineage"
    new_heading = "## RUN-077–087 route/page, page-tree, backend, ownership and reporting lineage"
    gaps = replace_once_or_present(gaps, old_heading, new_heading, "lineage heading")
    old_lineage_end = "RUN-084 records a signed-out/build-unattributed public/login preflight, and RUN-085 refreshes current reporting. Full route/page/backend-to-feature mapping, framework reachability, runtime, build, signed-in application browser, executed tests, benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit."
    new_lineage_end = "RUN-084 records a signed-out/build-unattributed public/login preflight, and RUN-085 refreshes current reporting. RUN-086/R then establishes 530 bounded static source-owner records through three fresh partition reviews, and RUN-087 reports only that narrow evidence class. Gate 4 and the full route/page/backend crosswalk remain incomplete; framework reachability, runtime, build, signed-in application browser, executed tests, benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit."
    gaps = replace_once_or_present(gaps, old_lineage_end, new_lineage_end, "lineage boundary")
    write_lf(gaps_relative, gaps)


def patch_findings(ledger: dict[str, Any], review: dict[str, Any]) -> None:
    relative = "findings.json"
    findings = read_json(relative)
    records_before = canonical_json_sha256(findings["records"])
    assert len(findings["records"]) == 12
    assert findings["pins"]["current_matrix_sha256"] == MATRIX_SHA256
    findings["pins"].update(
        {
            "run_086_static_source_feature_ownership_generator_sha256": LEDGER_GENERATOR_SHA256,
            "run_086_static_source_feature_ownership_ledger_sha256": LEDGER_SHA256,
            "run_086r_static_source_feature_ownership_review_sha256": REVIEW_SHA256,
        }
    )
    findings["counts"].update(
        {
            "static_source_feature_ownership_records": 530,
            "static_source_feature_ownership_route_records": 212,
            "static_source_feature_ownership_page_records": 318,
            "static_source_feature_ownership_distinct_feature_ids": 235,
        }
    )
    findings["current_static_source_feature_ownership"] = {
        "run_id": ledger["run_id"],
        "review_run_id": review["run_id"],
        "status": "GO_STATIC_SOURCE_FEATURE_OWNERSHIP_ONLY",
        "ledger_records": 530,
        "route_records": 212,
        "page_records": 318,
        "distinct_feature_ids": 235,
        "ledger_sha256": LEDGER_SHA256,
        "independent_review_sha256": REVIEW_SHA256,
        "independent_review_discrepancies": 0,
        "gate_4": {
            "status": "PARTIAL_BOUNDED_STATIC_SOURCE_OWNERSHIP_INCOMPLETE",
            "static_records": 530,
            "canonical_route_page_denominator": None,
            "complete": False,
        },
        "credit_boundary": {
            "STATIC_SOURCE_FEATURE_OWNERSHIP": True,
            "feature_mapping_complete": False,
            "framework_route_reachability": False,
            "runtime": False,
            "database": False,
            "build": False,
            "application_browser": False,
            "executed_tests": False,
            "benchmark_mapping": False,
            "ease": False,
            "pass": False,
            "final_finding": False,
            "completion": False,
            "audit_complete": False,
        },
    }
    assert canonical_json_sha256(findings["records"]) == records_before
    assert sha256_file("03-feature-to-benchmark-matrix.csv") == MATRIX_SHA256
    write_lf(relative, json.dumps(findings, ensure_ascii=False, indent=2) + "\n")


def patch_dashboard_generator() -> None:
    relative = "generators/build-current-audit-dashboard.py"
    text = path(relative).read_text(encoding="utf-8")
    load_old = 'backend_semantic_review = read_json("evidence/source/raw-run-084br-independent-backend-semantic-classification-review-wave-09.json")\n'
    load_new = load_old + (
        'static_source_ownership = read_json("evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json")\n'
        'static_source_ownership_review = read_json("evidence/source/raw-run-086r-independent-reviewed-static-route-page-feature-ownership-wave-10.json")\n'
    )
    text = replace_once_or_present(text, load_old, load_new, "load RUN-086/R")

    assertion_marker = """assert all(
    backend_semantic_review["credit_boundary"][key] is False
    for key in (
        "whole_file_semantic_review_credit",
        "feature_mapping_credit",
        "framework_reachability_credit",
        "runtime_credit",
        "database_credit",
        "executed_test_credit",
        "application_browser_credit",
        "benchmark_credit",
        "ease_credit",
        "pass_credit",
        "final_finding_credit",
        "completion_credit",
        "audit_complete",
    )
)
"""
    ownership_assertions = assertion_marker + f"""
assert sha256_file("{LEDGER_GENERATOR_RELATIVE}") == "{LEDGER_GENERATOR_SHA256}"
assert sha256_file("{LEDGER_RELATIVE}") == "{LEDGER_SHA256}"
assert sha256_file("{REVIEW_RELATIVE}") == "{REVIEW_SHA256}"
assert static_source_ownership["record_set"]["count"] == 530
assert static_source_ownership["counts"]["selected"]["route_records"] == 212
assert static_source_ownership["counts"]["selected"]["page_records"] == 318
assert static_source_ownership["counts"]["selected"]["distinct_feature_ids"] == 235
assert static_source_ownership["counts"]["source_universe"]["denominator_complete"] is False
assert static_source_ownership_review["decision"]["verdict"] == "GO"
assert static_source_ownership_review["decision"]["discrepancies"] == 0
assert static_source_ownership_review["decision"]["static_source_feature_ownership_authorized"] is True
assert static_source_ownership_review["decision"]["gate_4_complete"] is False
assert static_source_ownership_review["credit_boundary"]["STATIC_SOURCE_FEATURE_OWNERSHIP"] is True
assert all(
    static_source_ownership_review["credit_boundary"][key] is False
    for key in (
        "feature_mapping_complete",
        "framework_route_reachability",
        "runtime",
        "database",
        "build",
        "application_browser",
        "executed_tests",
        "benchmark_mapping",
        "ease",
        "pass",
        "final_finding",
        "completion",
        "audit_complete",
    )
)
"""
    text = replace_once_or_present(text, assertion_marker, ownership_assertions, "assert RUN-086/R")

    evidence_old = '    ("RUN-085 reporting/hash receipt", "evidence/source/current-run-085-reporting-materialization-wave-09.json"),\n):'
    evidence_new = (
        '    ("RUN-085 reporting/hash receipt", "evidence/source/current-run-085-reporting-materialization-wave-09.json"),\n'
        '    ("RUN-086 deterministic static source ownership generator", "generators/build-reviewed-static-route-page-feature-ownership-wave-10.py"),\n'
        '    ("RUN-086 bounded static source ownership ledger", "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json"),\n'
        '    ("RUN-086R three-part independent ownership review", "evidence/source/raw-run-086r-independent-reviewed-static-route-page-feature-ownership-wave-10.json"),\n'
        '    ("RUN-087 deterministic ownership reporting materializer", "generators/materialize-run-087-static-source-feature-ownership-reporting.py"),\n'
        '    ("RUN-087 ownership reporting/hash receipt", "evidence/source/current-run-087-static-source-feature-ownership-reporting.json"),\n'
        '):'
    )
    text = replace_once_or_present(text, evidence_old, evidence_new, "checkpoint evidence")
    text = replace_once_or_present(text, '<a href="#checkpoint">RUN-085</a>', '<a href="#checkpoint">RUN-087</a>', "nav")

    notice_old = "RUN-084's current designated-application preflight is signed out and build-unattributed. The live matrix is unchanged"
    notice_new = "RUN-084's current designated-application preflight is signed out and build-unattributed. RUN-086/R independently establish $static_owner_records bounded static source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs; Gate 4 and the complete crosswalk remain open. The live matrix is unchanged"
    text = replace_once_or_present(text, notice_old, notice_new, "primary notice")
    checkpoint_old = "<strong>RUN-071–085 current reporting checkpoint:</strong>"
    checkpoint_new = "<strong>RUN-071–087 current reporting checkpoint:</strong>"
    text = replace_once_or_present(text, checkpoint_old, checkpoint_new, "checkpoint heading")
    text = replace_once_or_present(
        text,
        "<h2>RUN-071–085 completion-gate checkpoint</h2>",
        "<h2>RUN-071–087 completion-gate checkpoint</h2>",
        "checkpoint panel heading",
    )
    boundary_old = "Full route/page/backend-to-feature mapping remains open; every downstream credit remains zero."
    boundary_new = "RUN-086/R add $static_owner_records bounded static source-owner records only; the framework-expanded denominator, residual ownership, and full route/page/backend crosswalk remain open. Every execution, benchmark, Pass, finding, and completion credit remains zero."
    text = replace_once_or_present(text, boundary_old, boundary_new, "checkpoint boundary")
    progress_old = "RUN-084/R/B/BR added independently reviewed page-tree and backend structural ledgers plus a signed-out designated-application preflight. Static relation"
    progress_new = "RUN-084/R/B/BR added independently reviewed page-tree and backend structural ledgers plus a signed-out designated-application preflight, RUN-086/R add three-part independently reviewed bounded source ownership, and RUN-087 refreshes current reporting. Static relation"
    text = replace_once_or_present(text, progress_old, progress_new, "progress narrative")

    table_old = '<tr><td>RUN-085 reporting refresh</td><td><strong>current page/backend/preflight boundaries</strong></td><td class="partial">audit-only materialization · matrix and preserved reports byte-identical · fresh dashboard receipt linked separately</td></tr>'
    table_new = table_old + '<tr><td>RUN-086/R static source feature ownership</td><td><strong>$static_owner_records records · $static_owner_routes routes + $static_owner_pages pages · $static_owner_features FEATURE-IDs</strong></td><td class="partial">bounded source ownership only · Gate 4 incomplete · 0 framework/runtime/browser/test/benchmark/Pass/completion credit</td></tr><tr><td>RUN-087 reporting refresh</td><td><strong>bounded ownership overlay reported</strong></td><td class="partial">audit-only materialization · matrix and preserved reports byte-identical</td></tr>'
    text = replace_once_or_present(text, table_old, table_new, "checkpoint rows")
    waves_old = "RUN-001 through RUN-085 are represented by audit artifacts;"
    waves_new = "RUN-001 through RUN-087 are represented by audit artifacts;"
    text = replace_once_or_present(text, waves_old, waves_new, "waves range")
    wave_item_old = "<li>RUN-085: deterministic reporting refresh and fresh audit-dashboard verification · matrix and all downstream credit unchanged</li>"
    wave_item_new = wave_item_old + "<li>RUN-086/R: $static_owner_records independently reviewed bounded static source-owner records · $static_owner_routes routes + $static_owner_pages pages · $static_owner_features FEATURE-IDs · Gate 4 incomplete</li><li>RUN-087: deterministic bounded-ownership reporting refresh · matrix and every execution/benchmark/Pass/finding/completion boundary unchanged</li>"
    text = replace_once_or_present(text, wave_item_old, wave_item_new, "wave items")
    census_old = "RUN-077–084B add exhaustive committed static route/name/page, full page-tree, and backend structural evidence; RUN-085 refreshes reporting."
    census_new = "RUN-077–084B add exhaustive committed static route/name/page, full page-tree, and backend structural evidence; RUN-086/R add bounded static source ownership and RUN-087 refreshes reporting."
    text = replace_once_or_present(text, census_old, census_new, "static census intro")
    page_row_old = '<tr><td>Page-root prompt status</td><td>$page_reviewed reviewed · $page_evidence_gaps evidence gap</td><td class="partial">$route_page_roots roots total; 0 rendered</td></tr>'
    page_row_new = page_row_old + '<tr><td>RUN-086/R static source FEATURE-ID ownership</td><td>$static_owner_records records · $static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs</td><td class="partial">three-part independent GO · bounded source ownership only · Gate 4 incomplete · matrix unchanged</td></tr>'
    text = replace_once_or_present(text, page_row_old, page_row_new, "static census row")
    gap_old = "<li>Framework route reachability and route/page-to-feature mapping; RUN-082 closes only 38/38 static source registration</li>"
    gap_new = "<li>RUN-086/R establish $static_owner_records bounded static source-owner records; complete the framework-expanded canonical route/page denominator, the 3 shared relations, residual ownership, full crosswalk, and route reachability before Gate 4 can close</li>"
    text = replace_once_or_present(text, gap_old, gap_new, "gate 4 gap")

    prior_old = "RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, and RUN-083 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-085."
    prior_new = "RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, and RUN-085 responsive verification are immutable history for their exact superseded HTML; no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-087."
    text = replace_once_or_present(text, prior_old, prior_new, "prior verification text")
    prior_links_old = '<li><a href="evidence/browser/current-audit-dashboard-verification-run-083-wave-08.json">Superseded RUN-083 verification GO</a></li></ul>'
    prior_links_new = '<li><a href="evidence/browser/current-audit-dashboard-verification-run-083-wave-08.json">Superseded RUN-083 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-085-wave-09.json">Superseded RUN-085 verification GO</a></li></ul>'
    text = replace_once_or_present(text, prior_links_old, prior_links_new, "prior verification links")
    current_verify_old = '<section class="panel"><h2>Fresh RUN-085 audit-dashboard verification</h2><p>The exact regenerated dashboard is checked at 1440×900, 1280×800, 1024×768, and 390×844 after publication. The linked receipt records page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate IDs, console output, visible zero-credit boundaries, and exact dashboard/generator/reporting hashes. It verifies the audit artifact only and grants no application-browser, responsive, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-085-wave-09.json">RUN-085 responsive audit-dashboard verification receipt</a></li></ul></section>'
    current_verify_new = '<section class="panel"><h2>Fresh RUN-088 audit-dashboard verification</h2><p>The exact regenerated RUN-087 dashboard is checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-088 receipt records page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate IDs, console output, visible ownership and zero-credit boundaries, and exact dashboard/generator/reporting hashes. It verifies the audit artifact only and grants no application-browser, responsive, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-088-wave-10.json">RUN-088 responsive audit-dashboard verification receipt</a></li></ul></section>'
    text = replace_once_or_present(text, current_verify_old, current_verify_new, "current verification")
    text = replace_once_or_present(text, "<h2>RUN-071–085 evidence lineage</h2>", "<h2>RUN-071–087 evidence lineage</h2>", "lineage heading")
    text = replace_once_or_present(text, "RUN-077–085 source/reporting artifact", "RUN-077–087 source/reporting artifact", "lineage text")
    footer_old = "Generated deterministically from independently reviewed static candidate evidence through RUN-084B and reported in RUN-085."
    footer_new = "Generated deterministically from independently reviewed static evidence through RUN-086/R and reported in RUN-087."
    text = replace_once_or_present(text, footer_old, footer_new, "footer")
    text = replace_once_or_present(
        text,
        '    backend_async_paths=backend_semantic["denominators"]["async_unique_paths"],\n',
        '    backend_async_paths=backend_semantic["denominators"]["async_unique_paths"],\n'
        '    static_owner_records=static_source_ownership["record_set"]["count"],\n'
        '    static_owner_routes=static_source_ownership["counts"]["selected"]["route_records"],\n'
        '    static_owner_pages=static_source_ownership["counts"]["selected"]["page_records"],\n'
        '    static_owner_features=static_source_ownership["counts"]["selected"]["distinct_feature_ids"],\n',
        "substitution values",
    )
    text = replace_once_or_present(text, ".tmp-run085-dashboard", ".tmp-run087-dashboard", "temp name")
    write_lf(relative, text)


def main() -> None:
    ledger, review = assert_inputs()
    patch_reports()
    patch_findings(ledger, review)
    patch_dashboard_generator()

    for relative, expected in PRESERVED.items():
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
    assert outputs == MATERIALIZED_OUTPUTS
    receipt = {
        "schema_version": "run-087-static-source-feature-ownership-reporting-v1",
        "run_id": "RUN-087-STATIC-SOURCE-FEATURE-OWNERSHIP-REPORTING",
        "status": "BOUNDED_STATIC_SOURCE_FEATURE_OWNERSHIP_REPORTED_GATE_4_INCOMPLETE",
        "generated_on": "2026-08-25",
        "pins": {
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "matrix_sha256": MATRIX_SHA256,
            "ledger_generator_sha256": LEDGER_GENERATOR_SHA256,
            "ledger_sha256": LEDGER_SHA256,
            "independent_review_sha256": REVIEW_SHA256,
            "materializer_sha256": sha256_file(
                "generators/materialize-run-087-static-source-feature-ownership-reporting.py"
            ),
        },
        "inputs": {
            **CURRENT_INPUTS,
            LEDGER_GENERATOR_RELATIVE: LEDGER_GENERATOR_SHA256,
            LEDGER_RELATIVE: LEDGER_SHA256,
            REVIEW_RELATIVE: REVIEW_SHA256,
        },
        "outputs": outputs,
        "preserved": PRESERVED,
        "counts": {
            "prompt_required_paths_present": 18,
            "canonical_features": 340,
            "static_source_feature_ownership_records": 530,
            "route_records": 212,
            "page_records": 318,
            "distinct_feature_ids": 235,
            "shared_relation_route_records_excluded": 3,
            "matrix_rows_changed": 0,
            "matrix_cells_changed": 0,
            "provisional_finding_records": 12,
            "final_findings": 0,
        },
        "checks": {
            "three_fresh_independent_partition_reviews_go": True,
            "independent_review_discrepancies": 0,
            "ledger_rows_have_immutable_static_provenance_and_row_digests": True,
            "prefix_import_containment_name_presence_inheritance_rejected": True,
            "complete_route_page_denominator_known": False,
            "gate_4_complete": False,
            "matrix_byte_identical": True,
            "provisional_finding_record_semantics_preserved": True,
            "application_source_paths_written": 0,
            "dashboard_requires_fresh_run_088_artifact_verification": True,
        },
        "credit_boundary": {
            "static_source_feature_ownership": True,
            "feature_mapping_complete": False,
            "framework_route_reachability": False,
            "navigation": False,
            "runtime": False,
            "database": False,
            "build": False,
            "executed_tests": False,
            "application_browser": False,
            "benchmark_mapping": False,
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
        assert output.read_bytes() == encoded, f"Refusing to overwrite different receipt bytes: {output}"
    else:
        output.write_bytes(encoded)
    assert output.read_bytes() == encoded
    print(
        json.dumps(
            {
                "status": receipt["status"],
                "output": OUTPUT_RELATIVE,
                "sha256": sha256_file(OUTPUT_RELATIVE),
                "outputs": outputs,
                "matrix_sha256": MATRIX_SHA256,
                "static_source_feature_ownership_records": 530,
                "gate_4_complete": False,
                "all_execution_and_completion_credit": 0,
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
