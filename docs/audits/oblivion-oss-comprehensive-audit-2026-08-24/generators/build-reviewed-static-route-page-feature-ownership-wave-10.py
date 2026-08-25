#!/usr/bin/env python3
"""Build the bounded RUN-086 static route/page FEATURE-ID ownership ledger.

This producer materializes only source records whose one-to-one ownership was
already established by the corrected RUN-078 classification and the cyclic
RUN-079 independent review.  The resulting rows remain candidates until a
fresh independent review recomputes this ledger.  They never establish the
framework-expanded route/page denominator, reachability, runtime behaviour,
browser behaviour, tests, benchmarks, Passes, findings, or completion.
"""

from __future__ import annotations

import csv
import hashlib
import json
import os
import subprocess
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_PATH = (
    AUDIT_DIR
    / "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json"
)
PROMPT_PATH = Path(
    r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md"
)

AUDIT_HEAD = "821314b6a8c3c279ff7937d4cd2ee1576b0a47d6"
AUDIT_TREE = "1564890364fd1c7ee54455075fa90ebe22801a7a"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"

INPUT_PATHS = {
    "matrix": AUDIT_DIR / "03-feature-to-benchmark-matrix.csv",
    "manifest": AUDIT_DIR / "evidence/source/root-run-077-route-page-universe-manifest-wave-07.json",
    "classification": AUDIT_DIR / "evidence/source/current-route-page-classification-wave-07.json",
    "independent_review": AUDIT_DIR
    / "evidence/source/current-route-page-independent-review-wave-07.json",
}
EXPECTED_INPUT_SHA256 = {
    "matrix": "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390",
    "manifest": "150fcff9b100ad85a7a2e998ed69c8dafdc2d0098e8ec2b4dbac7d3b404061be",
    "classification": "7b534fea15e6c4ec98fbb2cb0c761e26116b2abee500c39e599b0e779729df97",
    "independent_review": "3910255856a757e612b6f6d75522fe394ac19e4e011836c1aedbcfd29eb344be",
}

EXPECTED_ROUTE_CLASSIFICATIONS = {
    "ALIAS_OR_REDIRECT": 1,
    "EXPLICIT_UNMAPPED_SENTINEL": 3003,
    "OWNER": 211,
    "SHARED_RELATION": 3,
}
EXPECTED_PAGE_CLASSIFICATIONS = {"Evidence gap": 393, "Reviewed": 318}
EXPECTED_SELECTED = {
    "route_records": 212,
    "page_records": 318,
    "records": 530,
    "distinct_feature_ids": 235,
    "distinct_H_feature_ids": 219,
    "distinct_D_feature_ids": 16,
    "route_distinct_feature_ids": 28,
    "page_distinct_feature_ids": 230,
    "route_and_page_distinct_feature_ids": 23,
}
EXPECTED_ROUTE_PARTITIONS = {"A": 56, "B": 46, "C": 110}
EXPECTED_PAGE_PARTITIONS = {"A": 156, "B": 81, "C": 81}


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def canonical_json_sha256(value: Any) -> str:
    raw = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return sha256_bytes(raw.encode("utf-8"))


def canonical_list_sha256(values: list[str] | set[str]) -> str:
    return sha256_bytes("\n".join(sorted(values)).encode("utf-8"))


def load_json(path: Path) -> dict[str, Any]:
    value = json.loads(path.read_text(encoding="utf-8"))
    assert isinstance(value, dict), path
    return value


def git(*args: str) -> str:
    completed = subprocess.run(
        ["git", *args],
        cwd=REPO,
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )
    return completed.stdout.strip()


def index_unique(rows: list[dict[str, Any]], key: str) -> dict[str, dict[str, Any]]:
    indexed: dict[str, dict[str, Any]] = {}
    for row in rows:
        value = row[key]
        assert isinstance(value, str) and value, (key, value)
        assert value not in indexed, (key, value)
        indexed[value] = row
    return indexed


def false_boundary(boundary: dict[str, Any], label: str) -> None:
    assert boundary, label
    assert all(value is False for value in boundary.values()), (label, boundary)


def assert_workspace_and_inputs() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == AUDIT_HEAD
    assert git("rev-parse", "HEAD^{tree}") == AUDIT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("status", "--porcelain", "--", "app", "routes", "resources/js") == ""
    assert PROMPT_PATH.is_file()
    assert sha256_file(PROMPT_PATH) == PROMPT_SHA256
    for name, path in INPUT_PATHS.items():
        assert path.is_file(), path
        assert sha256_file(path) == EXPECTED_INPUT_SHA256[name], (name, sha256_file(path))


def load_matrix() -> tuple[list[dict[str, str]], dict[str, dict[str, str]]]:
    with INPUT_PATHS["matrix"].open("r", encoding="utf-8-sig", newline="") as handle:
        rows = list(csv.DictReader(handle))
    assert len(rows) == 340
    assert set(rows[0]) >= {
        "feature_id",
        "module",
        "user_job",
        "feature_class",
        "route_names",
        "route_paths",
        "page_files",
        "feature_identity_status",
    }
    indexed = index_unique(rows, "feature_id")
    assert Counter(row["feature_class"] for row in rows) == {"H": 300, "D": 40}
    assert all(row["module"] and row["user_job"] for row in rows)
    return rows, indexed


def feature_projection(row: dict[str, str]) -> dict[str, str]:
    return {
        "feature_id": row["feature_id"],
        "feature_class": row["feature_class"],
        "module": row["module"],
        "user_job": row["user_job"],
        "matrix_route_names": row["route_names"],
        "matrix_route_paths": row["route_paths"],
        "matrix_page_files": row["page_files"],
        "feature_identity_status": row["feature_identity_status"],
    }


def assert_manifest(manifest: dict[str, Any]) -> tuple[
    dict[str, dict[str, Any]],
    dict[str, dict[str, Any]],
    dict[str, dict[str, Any]],
]:
    assert (
        manifest["status"]
        == "PRIMARY_ROUTE_METHOD_SCOPE_PLUS_ROUTE_LIKE_SENTINEL_AND_PAGE_UNIVERSE_PARTITIONED_UNREVIEWED_ZERO_DOWNSTREAM_CREDIT"
    )
    pins = manifest["pins"]
    assert pins["application_commit"] == APPLICATION_COMMIT
    assert pins["application_tree"] == APPLICATION_TREE
    assert pins["app_tree"] == APP_TREE
    assert pins["routes_tree"] == ROUTES_TREE
    assert pins["resources_js_tree"] == RESOURCES_JS_TREE
    assert pins["resources_js_pages_tree"] == RESOURCES_JS_PAGES_TREE
    counts = manifest["counts"]
    assert counts["canonical_targets"] == 340
    assert counts["H_targets"] == 300
    assert counts["D_targets"] == 40
    assert counts["static_route_like_review_rows"] == 3218
    assert counts["page_roots"] == 711
    assert counts["final_feature_mappings"] == 0
    for key in (
        "framework_routes_executed",
        "runtime_credit",
        "application_browser_credit",
        "executed_test_credit",
        "benchmark_mapping_credit",
        "pass_credit",
        "completion_credit",
    ):
        assert counts[key] == 0, key

    route_rows = list(manifest["route_universe"]["primary_route_facade_callsites"])
    route_rows += list(manifest["route_universe"]["route_like_sentinels"])
    page_rows = list(manifest["page_universe"]["page_roots"])
    name_rows = list(manifest["route_universe"]["fluent_name_callsites"])
    assert len(route_rows) == 3218
    assert len(page_rows) == 711
    assert len(name_rows) == 3245
    return (
        index_unique(route_rows, "route_record_id"),
        index_unique(page_rows, "page_record_id"),
        index_unique(name_rows, "name_record_id"),
    )


def assert_classification(classification: dict[str, Any]) -> None:
    assert (
        classification["status"]
        == "THREE_PART_PRODUCER_STATIC_CLASSIFICATION_NORMALIZED_PENDING_INDEPENDENT_REVIEW_ZERO_DOWNSTREAM_CREDIT"
    )
    pins = classification["pins"]
    assert pins["application_commit"] == APPLICATION_COMMIT
    assert pins["application_tree"] == APPLICATION_TREE
    assert pins["manifest_sha256"] == EXPECTED_INPUT_SHA256["manifest"]
    counts = classification["counts"]
    assert counts["partitions"] == 3
    assert counts["route_decisions"] == 3218
    assert counts["page_decisions"] == 711
    assert counts["route_classifications"] == EXPECTED_ROUTE_CLASSIFICATIONS
    assert counts["page_prompt_classifications"] == EXPECTED_PAGE_CLASSIFICATIONS
    for key in (
        "benchmark_mapping_credit",
        "runtime_credit",
        "application_browser_credit",
        "executed_test_credit",
        "pass_credit",
        "completion_credit",
    ):
        assert counts[key] == 0, key
    false_boundary(classification["completion_boundary"], "classification completion boundary")
    false_boundary(classification["credit_boundary"], "classification credit boundary")


def assert_prior_review(review: dict[str, Any]) -> dict[str, dict[str, Any]]:
    assert review["status"] == "THREE_PART_CYCLIC_INDEPENDENT_REVIEW_GO_ZERO_DOWNSTREAM_CREDIT"
    pins = review["pins"]
    assert pins["application_commit"] == APPLICATION_COMMIT
    assert pins["application_tree"] == APPLICATION_TREE
    assert pins["manifest_sha256"] == EXPECTED_INPUT_SHA256["manifest"]
    assert pins["normalized_producer_sha256"] == EXPECTED_INPUT_SHA256["classification"]

    counts = review["counts"]
    assert counts["review_artifacts"] == 3
    assert counts["go_reviews"] == 3
    assert counts["route_decisions_reviewed"] == 3218
    assert counts["page_decisions_reviewed"] == 711
    assert counts["invalid_decisions"] == 0
    assert counts["review_artifacts_wrote_files"] == 0
    assert counts["route_classifications"] == EXPECTED_ROUTE_CLASSIFICATIONS
    assert counts["page_prompt_classifications"] == EXPECTED_PAGE_CLASSIFICATIONS

    expected_gate = {
        "all_manifest_producer_and_review_hashes_exact": True,
        "all_producer_pins_exact": True,
        "cyclic_mapping_a_to_b_b_to_c_c_to_a_exact": True,
        "all_three_review_statuses_go": True,
        "all_review_wrote_files_false": True,
        "all_review_write_scopes_empty": True,
        "all_invalid_decision_arrays_empty": True,
        "all_assigned_decisions_reviewed": True,
        "exact_counts_and_pins_match": True,
        "all_credit_boundaries_false": True,
        "independent_cyclic_review_complete": True,
        "static_matrix_field_integration_authorized": True,
        "other_downstream_integration_authorized": False,
    }
    assert review["review_gate"] == expected_gate
    false_boundary(review["completion_boundary"], "prior review completion boundary")
    false_boundary(review["credit_boundary"], "prior review credit boundary")

    reviews_by_partition: dict[str, dict[str, Any]] = {}
    for item in review["reviews"]:
        assert item["status"] == "GO"
        assert item["invalid_decisions"] == 0
        assert item["wrote_files"] is False
        assert item["credit_awarded"] is False
        partition = item["reviewed_partition_id"]
        assert partition not in reviews_by_partition
        reviews_by_partition[partition] = item
    assert set(reviews_by_partition) == {"A", "B", "C"}
    return reviews_by_partition


def prior_review_projection(item: dict[str, Any]) -> dict[str, Any]:
    return {
        "review_id": item["review_id"],
        "review_run_id": item["run_id"],
        "status": item["status"],
        "reviewer_partition_id": item["reviewer_partition_id"],
        "reviewed_partition_id": item["reviewed_partition_id"],
        "path": item["path"],
        "sha256": item["sha256"],
        "invalid_decisions": item["invalid_decisions"],
        "wrote_files": item["wrote_files"],
        "credit_awarded": item["credit_awarded"],
    }


def add_row_digest(record: dict[str, Any]) -> dict[str, Any]:
    assert "ledger_row_sha256" not in record
    record["ledger_row_sha256"] = canonical_json_sha256(record)
    return record


def build_route_record(
    sequence: int,
    decision: dict[str, Any],
    manifest_row: dict[str, Any],
    name_by_id: dict[str, dict[str, Any]],
    matrix_row: dict[str, str],
    prior_review: dict[str, Any],
) -> dict[str, Any]:
    feature_id = decision["reviewed_feature_ids"][0]
    assert decision["partition_id"] == manifest_row["partition"]
    assert decision.get("credit_awarded") in (None, False)
    assert manifest_row["credit_awarded"] is False
    assert manifest_row["feature_mapping_status"] == "NOT_EXECUTED"
    assert manifest_row["framework_reachability"] == "NOT_EXECUTED"
    for key, manifest_key in (
        ("source_key", "source_key"),
        ("route_method", "route_method"),
        ("literal_uri", "literal_uri"),
        ("literal_route_name", "direct_name_literal"),
    ):
        if key in decision:
            assert decision[key] == manifest_row[manifest_key], (decision["route_record_id"], key)

    allowed_anchors = {
        value
        for value in (
            manifest_row.get("source_anchor"),
            manifest_row.get("source_locator"),
            manifest_row.get("source_key"),
        )
        if value
    }
    direct_name_callsite_id = manifest_row.get("direct_name_callsite_id")
    direct_name_row = None
    if direct_name_callsite_id:
        direct_name_row = name_by_id[direct_name_callsite_id]
        allowed_anchors.update(
            value
            for value in (direct_name_row.get("source_anchor"), direct_name_row.get("source_key"))
            if value
        )
    assert decision["source_anchors"]
    assert set(decision["source_anchors"]).issubset(allowed_anchors), decision["route_record_id"]

    projection = feature_projection(matrix_row)
    source_record_sha256 = canonical_json_sha256(manifest_row)
    classification_decision_sha256 = canonical_json_sha256(decision)
    return add_row_digest(
        {
            "mapping_id": f"RUN086-ROUTE-MAP-{sequence:04d}",
            "surface": "ROUTE_SOURCE_RECORD",
            "source_record_id": decision["route_record_id"],
            "source_record_key": f"route|{decision['route_record_id']}|{feature_id}",
            "partition_id": decision["partition_id"],
            "classification": decision["classification"],
            "feature_id": feature_id,
            "feature_class": matrix_row["feature_class"],
            "module": matrix_row["module"],
            "user_job": matrix_row["user_job"],
            "source_anchors": decision["source_anchors"],
            "decision_basis": decision.get("decision_basis", "PRIOR_REVIEWED_LITERAL_SOURCE_RELATION"),
            "rationale": decision["rationale"],
            "route_source": {
                "route_file": manifest_row["route_file"],
                "route_file_sha256": manifest_row["route_file_sha256"],
                "source_key": manifest_row["source_key"],
                "source_locator": manifest_row.get("source_locator"),
                "source_anchor": manifest_row.get("source_anchor"),
                "route_method": manifest_row["route_method"],
                "literal_uri": manifest_row.get("literal_uri"),
                "literal_route_name": manifest_row.get("direct_name_literal"),
                "action_expression": manifest_row.get("action_expression"),
                "statement_sha256": manifest_row.get("statement_sha256"),
                "direct_name_callsite_id": direct_name_callsite_id,
                "direct_name_source_anchor": direct_name_row.get("source_anchor") if direct_name_row else None,
                "direct_name_source_key": direct_name_row.get("source_key") if direct_name_row else None,
            },
            "feature_identity_projection": projection,
            "evidence_digests": {
                "source_record_sha256": source_record_sha256,
                "manifest_record_sha256": source_record_sha256,
                "classification_decision_sha256": classification_decision_sha256,
                "feature_identity_projection_sha256": canonical_json_sha256(projection),
                "joined_source_evidence_sha256": canonical_json_sha256(
                    {
                        "manifest_record_sha256": source_record_sha256,
                        "classification_decision_sha256": classification_decision_sha256,
                        "feature_identity_projection_sha256": canonical_json_sha256(projection),
                    }
                ),
            },
            "prior_independent_review": prior_review_projection(prior_review),
            "producer_credit_boundary": {
                "static_source_feature_ownership_candidate": True,
                "static_source_feature_ownership_credit": False,
                "framework_route_reachability_credit": False,
                "runtime_credit": False,
                "application_browser_credit": False,
                "executed_test_credit": False,
                "benchmark_credit": False,
                "pass_credit": False,
                "completion_credit": False,
            },
        }
    )


def build_page_record(
    sequence: int,
    decision: dict[str, Any],
    manifest_row: dict[str, Any],
    matrix_row: dict[str, str],
    prior_review: dict[str, Any],
) -> dict[str, Any]:
    feature_id = decision["reviewed_feature_ids"][0]
    assert decision["partition_id"] == manifest_row["partition"]
    if "page_file" in decision:
        assert decision["page_file"] == manifest_row["page_file"]
    if "page_file_sha256" in decision:
        assert decision["page_file_sha256"] == manifest_row["page_file_sha256"]
    if "render_call_count" in decision:
        assert decision["render_call_count"] == manifest_row["render_call_count"]
    for key in ("framework_reachability", "build_resolution", "browser_observation"):
        if key in decision:
            assert decision[key] == "NOT_EXECUTED"
    assert decision.get("credit_awarded") in (None, False)
    assert manifest_row["credit_awarded"] is False
    assert feature_id in manifest_row["candidate_feature_ids"]
    allowed_anchors = [manifest_row["page_file"], *manifest_row["render_owner_locators"]]
    assert set(decision["source_anchors"]) == set(allowed_anchors), decision["page_record_id"]
    assert len(decision["source_anchors"]) == len(allowed_anchors), decision["page_record_id"]

    projection = feature_projection(matrix_row)
    source_record_sha256 = canonical_json_sha256(manifest_row)
    classification_decision_sha256 = canonical_json_sha256(decision)
    return add_row_digest(
        {
            "mapping_id": f"RUN086-PAGE-MAP-{sequence:04d}",
            "surface": "PAGE_ROOT_SOURCE_RECORD",
            "source_record_id": decision["page_record_id"],
            "source_record_key": f"page|{decision['page_record_id']}|{feature_id}",
            "partition_id": decision["partition_id"],
            "classification": decision["prompt_classification"],
            "feature_id": feature_id,
            "feature_class": matrix_row["feature_class"],
            "module": matrix_row["module"],
            "user_job": matrix_row["user_job"],
            "source_anchors": decision["source_anchors"],
            "decision_basis": decision.get(
                "decision_basis", "PRIOR_REVIEWED_EXACT_PAGE_AND_RENDER_SOURCE_RELATION"
            ),
            "rationale": decision["rationale"],
            "page_source": {
                "row_key": manifest_row["row_key"],
                "page_file": manifest_row["page_file"],
                "page_file_sha256": manifest_row["page_file_sha256"],
                "page_file_blob_id": manifest_row["page_file_blob_id"],
                "render_names": manifest_row["render_names"],
                "render_call_count": manifest_row["render_call_count"],
                "render_owner_locators": manifest_row["render_owner_locators"],
            },
            "feature_identity_projection": projection,
            "evidence_digests": {
                "source_record_sha256": source_record_sha256,
                "manifest_record_sha256": source_record_sha256,
                "classification_decision_sha256": classification_decision_sha256,
                "feature_identity_projection_sha256": canonical_json_sha256(projection),
                "joined_source_evidence_sha256": canonical_json_sha256(
                    {
                        "manifest_record_sha256": source_record_sha256,
                        "classification_decision_sha256": classification_decision_sha256,
                        "feature_identity_projection_sha256": canonical_json_sha256(projection),
                    }
                ),
            },
            "prior_independent_review": prior_review_projection(prior_review),
            "producer_credit_boundary": {
                "static_source_feature_ownership_candidate": True,
                "static_source_feature_ownership_credit": False,
                "framework_route_reachability_credit": False,
                "runtime_credit": False,
                "application_browser_credit": False,
                "executed_test_credit": False,
                "benchmark_credit": False,
                "pass_credit": False,
                "completion_credit": False,
            },
        }
    )


def build_feature_summaries(records: list[dict[str, Any]]) -> list[dict[str, Any]]:
    grouped: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for record in records:
        grouped[record["feature_id"]].append(record)
    summaries: list[dict[str, Any]] = []
    for feature_id in sorted(grouped):
        feature_records = grouped[feature_id]
        route_records = [row for row in feature_records if row["surface"] == "ROUTE_SOURCE_RECORD"]
        page_records = [row for row in feature_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"]
        sample = feature_records[0]
        summaries.append(
            {
                "feature_id": feature_id,
                "feature_class": sample["feature_class"],
                "module": sample["module"],
                "user_job": sample["user_job"],
                "record_count": len(feature_records),
                "route_record_count": len(route_records),
                "page_record_count": len(page_records),
                "route_record_ids": [row["source_record_id"] for row in route_records],
                "page_record_ids": [row["source_record_id"] for row in page_records],
                "source_record_key_list_sha256": canonical_list_sha256(
                    [row["source_record_key"] for row in feature_records]
                ),
            }
        )
    return summaries


def build() -> dict[str, Any]:
    assert_workspace_and_inputs()
    _, matrix_by_id = load_matrix()
    manifest = load_json(INPUT_PATHS["manifest"])
    classification = load_json(INPUT_PATHS["classification"])
    prior_review = load_json(INPUT_PATHS["independent_review"])
    route_by_id, page_by_id, name_by_id = assert_manifest(manifest)
    assert_classification(classification)
    reviews_by_partition = assert_prior_review(prior_review)

    route_decisions = list(classification["route_decisions"])
    page_decisions = list(classification["page_decisions"])
    assert len(index_unique(route_decisions, "route_record_id")) == 3218
    assert len(index_unique(page_decisions, "page_record_id")) == 711
    assert Counter(row["classification"] for row in route_decisions) == EXPECTED_ROUTE_CLASSIFICATIONS
    assert Counter(row["prompt_classification"] for row in page_decisions) == EXPECTED_PAGE_CLASSIFICATIONS
    assert all(row.get("credit_awarded") in (None, False) for row in route_decisions)
    assert all(row.get("credit_awarded") in (None, False) for row in page_decisions)

    selected_route_decisions = sorted(
        [
            row
            for row in route_decisions
            if row["classification"] in {"OWNER", "ALIAS_OR_REDIRECT"}
        ],
        key=lambda row: row["route_record_id"],
    )
    selected_page_decisions = sorted(
        [row for row in page_decisions if row["prompt_classification"] == "Reviewed"],
        key=lambda row: row["page_record_id"],
    )
    shared_route_decisions = sorted(
        [row for row in route_decisions if row["classification"] == "SHARED_RELATION"],
        key=lambda row: row["route_record_id"],
    )
    assert len(selected_route_decisions) == EXPECTED_SELECTED["route_records"]
    assert len(selected_page_decisions) == EXPECTED_SELECTED["page_records"]
    assert len(shared_route_decisions) == 3
    assert all(len(row["reviewed_feature_ids"]) == 1 for row in selected_route_decisions)
    assert all(len(row["reviewed_feature_ids"]) == 1 for row in selected_page_decisions)

    selected_feature_ids = {
        row["reviewed_feature_ids"][0]
        for row in [*selected_route_decisions, *selected_page_decisions]
    }
    route_feature_ids = {row["reviewed_feature_ids"][0] for row in selected_route_decisions}
    page_feature_ids = {row["reviewed_feature_ids"][0] for row in selected_page_decisions}
    assert selected_feature_ids.issubset(matrix_by_id)
    assert len(selected_feature_ids) == EXPECTED_SELECTED["distinct_feature_ids"]
    assert len(route_feature_ids) == EXPECTED_SELECTED["route_distinct_feature_ids"]
    assert len(page_feature_ids) == EXPECTED_SELECTED["page_distinct_feature_ids"]
    assert len(route_feature_ids & page_feature_ids) == EXPECTED_SELECTED["route_and_page_distinct_feature_ids"]
    feature_class_counts = Counter(matrix_by_id[feature_id]["feature_class"] for feature_id in selected_feature_ids)
    assert feature_class_counts == {
        "H": EXPECTED_SELECTED["distinct_H_feature_ids"],
        "D": EXPECTED_SELECTED["distinct_D_feature_ids"],
    }

    route_records = [
        build_route_record(
            sequence,
            decision,
            route_by_id[decision["route_record_id"]],
            name_by_id,
            matrix_by_id[decision["reviewed_feature_ids"][0]],
            reviews_by_partition[decision["partition_id"]],
        )
        for sequence, decision in enumerate(selected_route_decisions, start=1)
    ]
    page_records = [
        build_page_record(
            sequence,
            decision,
            page_by_id[decision["page_record_id"]],
            matrix_by_id[decision["reviewed_feature_ids"][0]],
            reviews_by_partition[decision["partition_id"]],
        )
        for sequence, decision in enumerate(selected_page_decisions, start=1)
    ]
    records = [*route_records, *page_records]
    assert len(records) == EXPECTED_SELECTED["records"]
    assert len({row["mapping_id"] for row in records}) == len(records)
    assert len({row["source_record_key"] for row in records}) == len(records)
    assert all(
        row["ledger_row_sha256"]
        == canonical_json_sha256({key: value for key, value in row.items() if key != "ledger_row_sha256"})
        for row in records
    )
    assert dict(Counter(row["partition_id"] for row in route_records)) == EXPECTED_ROUTE_PARTITIONS
    assert dict(Counter(row["partition_id"] for row in page_records)) == EXPECTED_PAGE_PARTITIONS

    feature_summaries = build_feature_summaries(records)
    assert len(feature_summaries) == EXPECTED_SELECTED["distinct_feature_ids"]
    assert sum(row["record_count"] for row in feature_summaries) == len(records)

    excluded_shared = [
        {
            "route_record_id": row["route_record_id"],
            "partition_id": row["partition_id"],
            "classification": row["classification"],
            "reviewed_feature_ids": row["reviewed_feature_ids"],
            "source_anchors": row["source_anchors"],
            "rationale": row["rationale"],
            "classification_decision_sha256": canonical_json_sha256(row),
            "exclusion_reason": "Shared ownership requires a discriminator; no one-to-one static ownership credit is permitted.",
        }
        for row in shared_route_decisions
    ]

    record_key_list = [row["source_record_key"] for row in records]
    ledger_row_digest_list = [row["ledger_row_sha256"] for row in records]
    return {
        "schema_version": "1.0.0",
        "run_id": "RUN-086-REVIEWED-STATIC-ROUTE-PAGE-FEATURE-OWNERSHIP-WAVE-10",
        "status": "PENDING_FRESH_INDEPENDENT_LEDGER_REVIEW_ZERO_CREDIT",
        "generated_on": "2026-08-25",
        "pins": {
            "checkpoint_commit": AUDIT_HEAD,
            "checkpoint_tree": AUDIT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "app_tree": APP_TREE,
            "routes_tree": ROUTES_TREE,
            "resources_js_tree": RESOURCES_JS_TREE,
            "resources_js_pages_tree": RESOURCES_JS_PAGES_TREE,
            "prompt_path": str(PROMPT_PATH),
            "prompt_sha256": PROMPT_SHA256,
            "generator": OUTPUT_PATH.parent.parent.parent.joinpath(
                "generators/build-reviewed-static-route-page-feature-ownership-wave-10.py"
            ).relative_to(AUDIT_DIR).as_posix(),
            "generator_sha256": sha256_file(Path(__file__)),
            "inputs": {
                INPUT_PATHS[name].relative_to(AUDIT_DIR).as_posix(): digest
                for name, digest in EXPECTED_INPUT_SHA256.items()
            },
        },
        "architecture_rule": (
            "Oblivion Findings is one operating organisation with multiple Sites. "
            "Static FEATURE-ID ownership does not establish Site authorization, privacy, or runtime behaviour."
        ),
        "scope": {
            "evidence_class": "BOUNDED_STATIC_SOURCE_FEATURE_OWNERSHIP_CANDIDATE",
            "selection_rule": (
                "Select corrected route decisions classified OWNER or ALIAS_OR_REDIRECT and page decisions "
                "classified Reviewed only when exactly one reviewed canonical FEATURE-ID is present."
            ),
            "prior_review_rule": (
                "Every selected decision must belong to one of the three cyclically reviewed RUN-079 "
                "partitions with GO, zero invalid decisions, and zero inherited credit."
            ),
            "exclusion_rule": (
                "Exclude SHARED_RELATION, EXPLICIT_UNMAPPED_SENTINEL, and Evidence gap records; reject "
                "prefix/import/controller/file-presence inheritance as ownership proof."
            ),
            "denominator_boundary": (
                "530/3929 is only a bounded ratio over the RUN-077 source-record census "
                "(3218 route-like records plus 711 page roots). It is not the prompt's framework-expanded "
                "canonical route/page denominator and does not complete Gate 4."
            ),
        },
        "prior_independent_review": {
            "status": prior_review["status"],
            "review_artifacts": prior_review["counts"]["review_artifacts"],
            "go_reviews": prior_review["counts"]["go_reviews"],
            "invalid_decisions": prior_review["counts"]["invalid_decisions"],
            "review_gate": prior_review["review_gate"],
            "reviews": [prior_review_projection(reviews_by_partition[key]) for key in ("A", "B", "C")],
            "credit_inherited": False,
        },
        "counts": {
            "source_universe": {
                "route_like_records": 3218,
                "page_root_records": 711,
                "bounded_source_records": 3929,
                "framework_expanded_canonical_route_page_denominator": None,
                "denominator_complete": False,
            },
            "selected": {
                **EXPECTED_SELECTED,
                "route_classifications": {"OWNER": 211, "ALIAS_OR_REDIRECT": 1},
                "route_partitions": EXPECTED_ROUTE_PARTITIONS,
                "page_partitions": EXPECTED_PAGE_PARTITIONS,
                "distinct_feature_classes": dict(sorted(feature_class_counts.items())),
                "record_feature_classes": dict(
                    sorted(Counter(row["feature_class"] for row in records).items())
                ),
            },
            "excluded": {
                "shared_relation_route_records": 3,
                "explicit_unmapped_route_records": 3003,
                "page_evidence_gap_records": 393,
            },
        },
        "records": records,
        "record_set": {
            "count": len(records),
            "source_record_key_list_sha256": canonical_list_sha256(record_key_list),
            "ledger_row_sha256_list_sha256": canonical_list_sha256(ledger_row_digest_list),
            "records_sha256": canonical_json_sha256(records),
        },
        "feature_summaries": feature_summaries,
        "feature_set": {
            "count": len(feature_summaries),
            "feature_id_list_sha256": canonical_list_sha256(selected_feature_ids),
            "feature_summaries_sha256": canonical_json_sha256(feature_summaries),
        },
        "excluded_shared_relations": excluded_shared,
        "fresh_independent_review": {
            "status": "PENDING",
            "required_partition_reviews": 3,
            "required_verdict": "THREE_GO_ZERO_DISCREPANCIES",
            "required_checks": [
                "Recompute selected route and page record IDs directly from all four pinned inputs.",
                "Recompute every manifest, classification, feature projection, joined-evidence, row, and set digest.",
                "Confirm one and only one reviewed FEATURE-ID per selected source record.",
                "Confirm the three shared relations and every unmapped/evidence-gap record remain excluded.",
                "Confirm only bounded STATIC_SOURCE_FEATURE_OWNERSHIP may be credited after review.",
            ],
        },
        "producer_credit_boundary": {
            "static_source_feature_ownership_candidate": True,
            "static_source_feature_ownership_credit": False,
            "complete_route_page_feature_crosswalk": False,
            "framework_route_reachability_credit": False,
            "navigation_credit": False,
            "runtime_credit": False,
            "database_credit": False,
            "build_credit": False,
            "application_browser_credit": False,
            "executed_test_credit": False,
            "benchmark_credit": False,
            "ease_credit": False,
            "pass_credit": False,
            "final_finding_credit": False,
            "completion_credit": False,
            "audit_complete": False,
        },
        "completion_boundary": {
            "gate_4_complete": False,
            "all_routes_expanded_and_mapped": False,
            "all_page_roots_mapped": False,
            "framework_route_reachability": False,
            "runtime": False,
            "build": False,
            "application_browser": False,
            "executed_tests": False,
            "benchmark_mapping": False,
            "ease": False,
            "pass_1_to_8": False,
            "completion": False,
            "audit_complete": False,
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/"
            "build-reviewed-static-route-page-feature-ownership-wave-10.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/"
            "root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json",
        ],
    }


def main() -> None:
    payload = build()
    encoded = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    candidate_sha256 = sha256_bytes(encoded)
    OUTPUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    if OUTPUT_PATH.exists():
        assert OUTPUT_PATH.read_bytes() == encoded, f"Refusing to overwrite different bytes: {OUTPUT_PATH}"
    else:
        temporary = OUTPUT_PATH.with_suffix(OUTPUT_PATH.suffix + ".tmp")
        temporary.write_bytes(encoded)
        assert sha256_file(temporary) == candidate_sha256
        os.replace(temporary, OUTPUT_PATH)
    assert sha256_file(OUTPUT_PATH) == candidate_sha256
    print(
        json.dumps(
            {
                "status": payload["status"],
                "output": OUTPUT_PATH.relative_to(REPO).as_posix(),
                "sha256": candidate_sha256,
                "records": payload["record_set"]["count"],
                "route_records": payload["counts"]["selected"]["route_records"],
                "page_records": payload["counts"]["selected"]["page_records"],
                "distinct_feature_ids": payload["feature_set"]["count"],
                "source_record_key_list_sha256": payload["record_set"][
                    "source_record_key_list_sha256"
                ],
                "records_sha256": payload["record_set"]["records_sha256"],
                "fresh_independent_review": payload["fresh_independent_review"]["status"],
                "all_downstream_credit": 0,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
