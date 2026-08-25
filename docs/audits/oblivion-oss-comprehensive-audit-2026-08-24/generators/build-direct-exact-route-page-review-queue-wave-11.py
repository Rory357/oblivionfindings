#!/usr/bin/env python3
"""Build the zero-credit RUN-090 direct-exact route/page review queue.

This queue deterministically selects unresolved RUN-082 records with exactly
one current matrix identity in a direct literal lane and no contradictory
second lane.  Direct identity is a review lead, not ownership proof.  Every
row stays pending until a fresh reviewer inspects the pinned source and the
canonical user job.  No route expansion, runtime, browser, test, benchmark,
Pass, finding, or completion credit is awarded here.
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
    / "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json"
)
PROMPT_PATH = Path(
    r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md"
)

AUDIT_HEAD = "786a2e2f8ab21142d0cb93bd9f5ceb1bf1aa6bb5"
AUDIT_TREE = "a1b32e32ef254a07016990051ed30eb28fdf8b9e"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"

INPUT_PATHS = {
    "matrix": AUDIT_DIR / "03-feature-to-benchmark-matrix.csv",
    "manifest": AUDIT_DIR
    / "evidence/source/root-run-077-route-page-universe-manifest-wave-07.json",
    "classification": AUDIT_DIR
    / "evidence/source/current-route-page-classification-wave-07.json",
    "candidate_manifest": AUDIT_DIR
    / "evidence/source/root-run-082-exact-owner-containment-candidate-manifest-wave-08.json",
    "candidate_review": AUDIT_DIR
    / "evidence/source/raw-run-082r-independent-exact-owner-containment-review-wave-08.json",
    "ownership_ledger": AUDIT_DIR
    / "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json",
    "ownership_review": AUDIT_DIR
    / "evidence/source/raw-run-086r-independent-reviewed-static-route-page-feature-ownership-wave-10.json",
}
EXPECTED_INPUT_SHA256 = {
    "matrix": "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390",
    "manifest": "150fcff9b100ad85a7a2e998ed69c8dafdc2d0098e8ec2b4dbac7d3b404061be",
    "classification": "7b534fea15e6c4ec98fbb2cb0c761e26116b2abee500c39e599b0e779729df97",
    "candidate_manifest": "3c6ad4df13a09ae8ff8f19aee09a1907f9754a1877806659676c6c2898652f85",
    "candidate_review": "a6a4f886ca209bc41ffa86afec37f6bddaf062ac80a6b375391adeea20e1c396",
    "ownership_ledger": "bfec52a9bed7176045457e8920bdc7b65e1c1d5c5eeb28a266f49c12acf69bcf",
    "ownership_review": "56c4832af941353aaf230ca17c792ea7191c6aebfc05bc1c511a757d5998d699",
}

EXPECTED_QUEUE = {
    "records": 507,
    "route_records": 504,
    "page_records": 3,
    "distinct_feature_ids": 79,
    "distinct_H_feature_ids": 71,
    "distinct_D_feature_ids": 8,
    "records_for_features_not_in_run_086": 124,
    "features_not_in_run_086": 23,
}
EXPECTED_PARTITIONS = {
    "A": {
        "count": 156,
        "key_list_sha256": "531724b62d4ecbbb0021696f7b96e0a1698a0ce3920a9adc65ed2d9eace1b597",
    },
    "B": {
        "count": 185,
        "key_list_sha256": "39fec3149c1f5ed0d797218c366784146787840b8838d3c39009f14466c37697",
    },
    "C": {
        "count": 166,
        "key_list_sha256": "79b79da420e5376b70bc9476ac552811519c7ed17a927cce9f1b62a70bda9b0b",
    },
}
EXPECTED_QUEUE_KEY_LIST_SHA256 = (
    "2ae2fcc3e77c4e0928e8f995a86972c79d02e7f2a76911eb543bb24717c5b5b5"
)
EXPECTED_FEATURE_ID_LIST_SHA256 = (
    "4bcb404656c91ed4195053a00d64efd2cb31e8aae8a33e85f1db408f371a8145"
)


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


def split_matrix_values(raw: str) -> list[str]:
    return [value.strip() for value in raw.split(";") if value.strip()]


def review_partition(canonical_key: str) -> str:
    return ("A", "B", "C")[int(sha256_bytes(canonical_key.encode("utf-8")), 16) % 3]


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


def load_matrix() -> tuple[
    list[dict[str, str]],
    dict[str, dict[str, str]],
    dict[str, set[str]],
    dict[str, set[str]],
]:
    with INPUT_PATHS["matrix"].open("r", encoding="utf-8-sig", newline="") as handle:
        rows = list(csv.DictReader(handle))
    assert len(rows) == 340
    by_id = index_unique(rows, "feature_id")
    route_names: dict[str, set[str]] = defaultdict(set)
    page_files: dict[str, set[str]] = defaultdict(set)
    for row in rows:
        assert row["module"] and row["user_job"]
        for value in split_matrix_values(row["route_names"]):
            route_names[value].add(row["feature_id"])
        for value in split_matrix_values(row["page_files"]):
            page_files[value].add(row["feature_id"])
    return rows, by_id, route_names, page_files


def feature_projection(row: dict[str, str]) -> dict[str, str]:
    return {
        "feature_id": row["feature_id"],
        "feature_class": row["feature_class"],
        "module": row["module"],
        "user_job": row["user_job"],
        "route_names": row["route_names"],
        "route_paths": row["route_paths"],
        "page_files": row["page_files"],
        "backend_anchors": row["backend_anchors"],
        "feature_identity_status": row["feature_identity_status"],
    }


def assert_prior_artifacts(
    manifest: dict[str, Any],
    classification: dict[str, Any],
    candidates: dict[str, Any],
    candidate_review: dict[str, Any],
    ownership: dict[str, Any],
    ownership_review: dict[str, Any],
) -> None:
    assert manifest["counts"]["static_route_like_review_rows"] == 3218
    assert manifest["counts"]["page_roots"] == 711
    assert classification["counts"]["route_decisions"] == 3218
    assert classification["counts"]["page_decisions"] == 711
    assert (
        candidates["status"]
        == "STATIC_CANDIDATE_RELATIONS_MATERIALIZED_PENDING_INDEPENDENT_REVIEW_ZERO_CREDIT"
    )
    assert candidates["counts"]["unresolved_route_like_records"] == 3003
    assert candidates["counts"]["page_evidence_gap_records"] == 393
    assert candidate_review["status"] == "GO_STATIC_CANDIDATE_CENSUS_REVIEWED_ZERO_DOWNSTREAM_CREDIT"
    assert candidate_review["verdict"]["decision"] == "GO"
    assert candidate_review["verdict"]["feature_mapping_authorized"] is False
    assert candidate_review["checks"]["review_discrepancies"] == 0
    false_boundary(candidate_review["credit_boundary"], "RUN-082R credit boundary")
    assert ownership["status"] == "PENDING_FRESH_INDEPENDENT_LEDGER_REVIEW_ZERO_CREDIT"
    assert ownership["record_set"]["count"] == 530
    assert ownership_review["decision"]["verdict"] == "GO"
    assert ownership_review["decision"]["discrepancies"] == 0
    assert ownership_review["decision"]["static_source_feature_ownership_authorized"] is True
    assert ownership_review["decision"]["complete_route_page_feature_crosswalk_authorized"] is False
    assert ownership_review["decision"]["gate_4_complete"] is False
    ownership_boundary = dict(ownership_review["credit_boundary"])
    assert ownership_boundary.pop("STATIC_SOURCE_FEATURE_OWNERSHIP") is True
    false_boundary(ownership_boundary, "RUN-086R downstream boundary")


def add_record_digest(record: dict[str, Any]) -> dict[str, Any]:
    assert "queue_record_sha256" not in record
    record["queue_record_sha256"] = canonical_json_sha256(record)
    return record


def source_blob(path: str) -> str:
    return git("rev-parse", f"{APPLICATION_COMMIT}:{path}")


def build_route_record(
    sequence: int,
    candidate: dict[str, Any],
    manifest_row: dict[str, Any],
    matrix_row: dict[str, str],
    route_name_index: dict[str, set[str]],
) -> dict[str, Any]:
    feature_id = candidate["name_relation"]["candidate_feature_ids"][0]
    literal_name = candidate["literal_route_name"]
    canonical_key = f"route|{candidate['route_record_id']}"
    assert route_name_index[literal_name] == {feature_id}
    assert literal_name in split_matrix_values(matrix_row["route_names"])
    assert candidate["relation_comparison"] in {"NAME_ONLY", "BOTH_LANES_IDENTICAL"}
    assert candidate["source_key"] == manifest_row["source_key"]
    assert candidate["source_anchor"] == manifest_row["source_anchor"]
    assert candidate["route_file"] == manifest_row["route_file"]
    assert candidate["route_method"] == manifest_row["route_method"]
    assert candidate["literal_uri"] == manifest_row["literal_uri"]
    assert literal_name == manifest_row["direct_name_literal"]
    assert candidate["action_expression"] == manifest_row["action_expression"]
    assert sha256_file(REPO / manifest_row["route_file"]) == manifest_row["route_file_sha256"]
    assert source_blob(manifest_row["route_file"]) == manifest_row["route_file_blob_id"]

    projection = feature_projection(matrix_row)
    candidate_sha = canonical_json_sha256(candidate)
    manifest_sha = canonical_json_sha256(manifest_row)
    projection_sha = canonical_json_sha256(projection)
    return add_record_digest(
        {
            "queue_id": f"RUN090-ROUTE-{sequence:04d}",
            "canonical_key": canonical_key,
            "review_partition": review_partition(canonical_key),
            "surface": "ROUTE_SOURCE_RECORD",
            "source_record_id": candidate["route_record_id"],
            "candidate_feature_id": feature_id,
            "direct_identity": {
                "lane": "EXACT_LITERAL_ROUTE_NAME",
                "source_field": "literal_route_name",
                "source_value": literal_name,
                "matrix_field": "route_names",
                "matrix_token": literal_name,
                "case_sensitive_exact_equality": True,
                "candidate_cardinality": 1,
            },
            "source": {
                "route_file": manifest_row["route_file"],
                "route_file_sha256": manifest_row["route_file_sha256"],
                "route_file_blob_id": manifest_row["route_file_blob_id"],
                "source_key": manifest_row["source_key"],
                "source_anchor": manifest_row["source_anchor"],
                "source_locator": manifest_row["source_locator"],
                "route_method": manifest_row["route_method"],
                "literal_uri": manifest_row["literal_uri"],
                "literal_route_name": literal_name,
                "action_expression": manifest_row["action_expression"],
                "statement_excerpt": manifest_row["statement_excerpt"],
                "statement_sha256": manifest_row["statement_sha256"],
            },
            "secondary_lane": {
                "relation_comparison": candidate["relation_comparison"],
                "backend_method_relation": candidate["backend_method_relation"],
                "contradictory_candidate_present": False,
            },
            "feature_identity_projection": projection,
            "evidence_digests": {
                "run_082_candidate_record_sha256": candidate_sha,
                "run_077_manifest_record_sha256": manifest_sha,
                "feature_identity_projection_sha256": projection_sha,
                "joined_candidate_evidence_sha256": canonical_json_sha256(
                    {
                        "run_082_candidate_record_sha256": candidate_sha,
                        "run_077_manifest_record_sha256": manifest_sha,
                        "feature_identity_projection_sha256": projection_sha,
                    }
                ),
            },
            "review_state": {
                "status": "PENDING_FRESH_SEMANTIC_REVIEW",
                "allowed_outcomes": ["OWNER", "SHARED_RELATION", "EVIDENCE_GAP"],
                "ownership_credit": False,
            },
        }
    )


def build_page_record(
    sequence: int,
    candidate: dict[str, Any],
    manifest_row: dict[str, Any],
    matrix_row: dict[str, str],
    page_file_index: dict[str, set[str]],
) -> dict[str, Any]:
    feature_id = candidate["current_matrix_page_file_relation"]["candidate_feature_ids"][0]
    page_file = candidate["page_file"]
    canonical_key = f"page|{candidate['page_record_id']}"
    assert page_file_index[page_file] == {feature_id}
    assert page_file in split_matrix_values(matrix_row["page_files"])
    assert candidate["relation_comparison"] in {"PAGE_FILE_ONLY", "BOTH_LANES_IDENTICAL"}
    assert page_file == manifest_row["page_file"]
    assert candidate["page_file_sha256"] == manifest_row["page_file_sha256"]
    assert candidate["render_names"] == manifest_row["render_names"]
    manifest_render_projection = [
        {
            "render_name": row["render_name"],
            "source_file": row["source_file"],
            "source_line": row["source_line"],
            "source_anchor": row["source_anchor"],
            "call_kind": row["call_kind"],
        }
        for row in manifest_row["render_callsites"]
    ]
    assert candidate["render_callsites"] == manifest_render_projection
    assert sha256_file(REPO / page_file) == manifest_row["page_file_sha256"]
    assert source_blob(page_file) == manifest_row["page_file_blob_id"]

    projection = feature_projection(matrix_row)
    candidate_sha = canonical_json_sha256(candidate)
    manifest_sha = canonical_json_sha256(manifest_row)
    projection_sha = canonical_json_sha256(projection)
    return add_record_digest(
        {
            "queue_id": f"RUN090-PAGE-{sequence:04d}",
            "canonical_key": canonical_key,
            "review_partition": review_partition(canonical_key),
            "surface": "PAGE_ROOT_SOURCE_RECORD",
            "source_record_id": candidate["page_record_id"],
            "candidate_feature_id": feature_id,
            "direct_identity": {
                "lane": "EXACT_PAGE_FILE_PATH",
                "source_field": "page_file",
                "source_value": page_file,
                "matrix_field": "page_files",
                "matrix_token": page_file,
                "case_sensitive_exact_equality": True,
                "candidate_cardinality": 1,
            },
            "source": {
                "page_file": page_file,
                "page_file_sha256": manifest_row["page_file_sha256"],
                "page_file_blob_id": manifest_row["page_file_blob_id"],
                "row_key": manifest_row["row_key"],
                "render_names": manifest_row["render_names"],
                "render_call_count": manifest_row["render_call_count"],
                "render_callsites": manifest_row["render_callsites"],
                "render_owner_locators": manifest_row["render_owner_locators"],
            },
            "secondary_lane": {
                "relation_comparison": candidate["relation_comparison"],
                "render_owner_relation": candidate["render_owner_relation"],
                "contradictory_candidate_present": False,
            },
            "feature_identity_projection": projection,
            "evidence_digests": {
                "run_082_candidate_record_sha256": candidate_sha,
                "run_077_manifest_record_sha256": manifest_sha,
                "feature_identity_projection_sha256": projection_sha,
                "joined_candidate_evidence_sha256": canonical_json_sha256(
                    {
                        "run_082_candidate_record_sha256": candidate_sha,
                        "run_077_manifest_record_sha256": manifest_sha,
                        "feature_identity_projection_sha256": projection_sha,
                    }
                ),
            },
            "review_state": {
                "status": "PENDING_FRESH_SEMANTIC_REVIEW",
                "allowed_outcomes": ["OWNER", "SHARED_RELATION", "EVIDENCE_GAP"],
                "ownership_credit": False,
            },
        }
    )


def build() -> dict[str, Any]:
    assert_workspace_and_inputs()
    matrix_rows, matrix_by_id, route_name_index, page_file_index = load_matrix()
    manifest = load_json(INPUT_PATHS["manifest"])
    classification = load_json(INPUT_PATHS["classification"])
    candidates = load_json(INPUT_PATHS["candidate_manifest"])
    candidate_review = load_json(INPUT_PATHS["candidate_review"])
    ownership = load_json(INPUT_PATHS["ownership_ledger"])
    ownership_review = load_json(INPUT_PATHS["ownership_review"])
    assert_prior_artifacts(
        manifest,
        classification,
        candidates,
        candidate_review,
        ownership,
        ownership_review,
    )

    route_manifest_rows = list(manifest["route_universe"]["primary_route_facade_callsites"])
    route_manifest_rows += list(manifest["route_universe"]["route_like_sentinels"])
    page_manifest_rows = list(manifest["page_universe"]["page_roots"])
    route_manifest_by_id = index_unique(route_manifest_rows, "route_record_id")
    page_manifest_by_id = index_unique(page_manifest_rows, "page_record_id")

    route_candidates = list(candidates["route_static_candidate_census"]["records"])
    page_candidates = list(candidates["page_static_candidate_census"]["records"])
    assert len(route_candidates) == 3003
    assert len(page_candidates) == 393
    assert Counter(row["name_relation"]["candidate_count"] for row in route_candidates) == {
        0: 2430,
        1: 527,
        2: 46,
    }
    assert Counter(
        row["current_matrix_page_file_relation"]["candidate_count"] for row in page_candidates
    ) == {0: 386, 1: 4, 2: 3}

    selected_routes = sorted(
        [
            row
            for row in route_candidates
            if row["name_relation"]["candidate_count"] == 1
            and row["relation_comparison"]
            not in {"BOTH_LANES_DISJOINT", "BOTH_LANES_PARTIAL_OVERLAP"}
        ],
        key=lambda row: row["route_record_id"],
    )
    selected_pages = sorted(
        [
            row
            for row in page_candidates
            if row["current_matrix_page_file_relation"]["candidate_count"] == 1
            and row["relation_comparison"]
            not in {"BOTH_LANES_DISJOINT", "BOTH_LANES_PARTIAL_OVERLAP"}
        ],
        key=lambda row: row["page_record_id"],
    )
    conflicting_routes = [
        row
        for row in route_candidates
        if row["name_relation"]["candidate_count"] == 1
        and row["relation_comparison"]
        in {"BOTH_LANES_DISJOINT", "BOTH_LANES_PARTIAL_OVERLAP"}
    ]
    conflicting_pages = [
        row
        for row in page_candidates
        if row["current_matrix_page_file_relation"]["candidate_count"] == 1
        and row["relation_comparison"]
        in {"BOTH_LANES_DISJOINT", "BOTH_LANES_PARTIAL_OVERLAP"}
    ]
    assert len(selected_routes) == EXPECTED_QUEUE["route_records"]
    assert len(selected_pages) == EXPECTED_QUEUE["page_records"]
    assert Counter(row["relation_comparison"] for row in selected_routes) == {
        "BOTH_LANES_IDENTICAL": 183,
        "NAME_ONLY": 321,
    }
    assert Counter(row["relation_comparison"] for row in selected_pages) == {
        "BOTH_LANES_IDENTICAL": 2,
        "PAGE_FILE_ONLY": 1,
    }
    assert len(conflicting_routes) == 23
    assert len(conflicting_pages) == 1

    records = [
        build_route_record(
            sequence,
            row,
            route_manifest_by_id[row["route_record_id"]],
            matrix_by_id[row["name_relation"]["candidate_feature_ids"][0]],
            route_name_index,
        )
        for sequence, row in enumerate(selected_routes, start=1)
    ]
    records += [
        build_page_record(
            sequence,
            row,
            page_manifest_by_id[row["page_record_id"]],
            matrix_by_id[row["current_matrix_page_file_relation"]["candidate_feature_ids"][0]],
            page_file_index,
        )
        for sequence, row in enumerate(selected_pages, start=1)
    ]
    assert len(records) == EXPECTED_QUEUE["records"]
    assert len({row["canonical_key"] for row in records}) == len(records)
    assert all(
        row["queue_record_sha256"]
        == canonical_json_sha256({key: value for key, value in row.items() if key != "queue_record_sha256"})
        for row in records
    )

    canonical_keys = [row["canonical_key"] for row in records]
    assert canonical_list_sha256(canonical_keys) == EXPECTED_QUEUE_KEY_LIST_SHA256
    feature_ids = {row["candidate_feature_id"] for row in records}
    assert len(feature_ids) == EXPECTED_QUEUE["distinct_feature_ids"]
    assert canonical_list_sha256(feature_ids) == EXPECTED_FEATURE_ID_LIST_SHA256
    assert Counter(matrix_by_id[value]["feature_class"] for value in feature_ids) == {
        "H": EXPECTED_QUEUE["distinct_H_feature_ids"],
        "D": EXPECTED_QUEUE["distinct_D_feature_ids"],
    }
    owned_feature_ids = {row["feature_id"] for row in ownership["feature_summaries"]}
    new_feature_ids = feature_ids - owned_feature_ids
    new_feature_records = [row for row in records if row["candidate_feature_id"] in new_feature_ids]
    assert len(new_feature_ids) == EXPECTED_QUEUE["features_not_in_run_086"]
    assert len(new_feature_records) == EXPECTED_QUEUE["records_for_features_not_in_run_086"]

    partition_summaries: list[dict[str, Any]] = []
    for partition_id in ("A", "B", "C"):
        partition_records = [row for row in records if row["review_partition"] == partition_id]
        expected = EXPECTED_PARTITIONS[partition_id]
        assert len(partition_records) == expected["count"]
        assert canonical_list_sha256([row["canonical_key"] for row in partition_records]) == expected[
            "key_list_sha256"
        ]
        partition_summaries.append(
            {
                "partition_id": partition_id,
                "assignment_rule": "int(SHA256(canonical_key), 16) mod 3; 0=A, 1=B, 2=C",
                "record_count": len(partition_records),
                "route_record_count": sum(
                    row["surface"] == "ROUTE_SOURCE_RECORD" for row in partition_records
                ),
                "page_record_count": sum(
                    row["surface"] == "PAGE_ROOT_SOURCE_RECORD" for row in partition_records
                ),
                "canonical_key_list_sha256": expected["key_list_sha256"],
                "queue_record_sha256_list_sha256": canonical_list_sha256(
                    [row["queue_record_sha256"] for row in partition_records]
                ),
                "review_status": "PENDING",
            }
        )

    return {
        "schema_version": "1.0.0",
        "run_id": "RUN-090-DIRECT-EXACT-ROUTE-PAGE-REVIEW-QUEUE-WAVE-11",
        "status": "DIRECT_EXACT_SINGLETON_QUEUE_PENDING_FRESH_SEMANTIC_REVIEW_ZERO_CREDIT",
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
            "generator": Path(__file__).relative_to(AUDIT_DIR).as_posix(),
            "generator_sha256": sha256_file(Path(__file__)),
            "inputs": {
                INPUT_PATHS[name].relative_to(AUDIT_DIR).as_posix(): digest
                for name, digest in EXPECTED_INPUT_SHA256.items()
            },
        },
        "architecture_rule": (
            "Oblivion Findings is one operating organisation with multiple Sites. "
            "Direct static identity does not establish permission, Site, privacy, lifecycle, or runtime correctness."
        ),
        "selection_contract": {
            "route_rule": (
                "Select unresolved RUN-082 route rows whose directly declared literal route name equals exactly "
                "one current matrix route_names token and whose second lane is either absent or identical."
            ),
            "page_rule": (
                "Select unresolved RUN-082 page rows whose exact page-file path equals exactly one current matrix "
                "page_files token and whose second lane is either absent or identical."
            ),
            "exclusions": [
                "Exclude all 24 direct-singleton rows with a disjoint or partial-overlap second lane.",
                "Exclude 49 RUN-082 rows whose direct literal lane has multiple candidates.",
                "Keep the three pre-existing RUN-086 SHARED_RELATION route rows excluded.",
                "Exclude all 2816 RUN-082 rows without a direct exact identity edge.",
                "Reject prefix, group, adjacency, import, controller containment, render containment, file presence, and prior-credit inheritance as direct identity proof."
            ],
            "semantic_boundary": (
                "The queue is heterogeneous: 321 selected routes have no backend candidate and none of the 504 "
                "route rows is closed to a page by this predicate. A fresh reviewer must inspect source and the "
                "canonical user job before classifying any row OWNER, SHARED_RELATION, or EVIDENCE_GAP."
            ),
        },
        "counts": {
            "bounded_residual_before_queue": {
                "route_records": 3006,
                "page_records": 393,
                "records": 3399,
                "note": "Includes three pre-existing shared routes outside the RUN-082 unresolved candidate manifest.",
            },
            "queue": {
                **EXPECTED_QUEUE,
                "route_relation_comparisons": {"BOTH_LANES_IDENTICAL": 183, "NAME_ONLY": 321},
                "page_relation_comparisons": {"BOTH_LANES_IDENTICAL": 2, "PAGE_FILE_ONLY": 1},
                "feature_classes": {"H": 71, "D": 8},
            },
            "explicit_exclusions": {
                "conflicting_direct_singletons": 24,
                "ambiguous_direct_exact_rows_in_run_082": 49,
                "pre_existing_shared_route_rows": 3,
                "run_082_rows_without_direct_exact_identity": 2816,
            },
            "ownership_credit_awarded": 0,
            "framework_routes_executed": 0,
            "runtime_credit": 0,
            "build_credit": 0,
            "application_browser_credit": 0,
            "executed_test_credit": 0,
            "benchmark_credit": 0,
            "pass_credit": 0,
            "completion_credit": 0,
        },
        "record_set": {
            "count": len(records),
            "canonical_key_list_sha256": canonical_list_sha256(canonical_keys),
            "queue_record_sha256_list_sha256": canonical_list_sha256(
                [row["queue_record_sha256"] for row in records]
            ),
            "records_sha256": canonical_json_sha256(records),
            "feature_id_list_sha256": canonical_list_sha256(feature_ids),
        },
        "review_partitions": partition_summaries,
        "records": records,
        "fresh_review_contract": {
            "status": "PENDING",
            "required_reviews": 3,
            "required_checks": [
                "Recompute the partition from the canonical key and reproduce its exact key-list hash.",
                "Recompute direct case-sensitive matrix identity from the pinned matrix and RUN-082 inputs.",
                "Inspect the exact pinned route statement/action or page root/render action against the canonical user job.",
                "Classify every assigned row OWNER, SHARED_RELATION, or EVIDENCE_GAP; no implicit acceptance.",
                "Keep Site/privacy/security readiness, framework reachability, runtime, browser, tests, benchmarks, Passes, findings, and completion separate and false."
            ],
            "ownership_integration_authorized": False,
        },
        "denominator_boundary": {
            "run_077_bounded_static_records": 3929,
            "run_077_route_like_records": 3218,
            "run_077_page_roots": 711,
            "framework_expanded_route_page_denominator": None,
            "page_denominator_711_roots_vs_963_page_tree_files_resolved": False,
            "gate_4_complete": False,
        },
        "credit_boundary": {
            "direct_exact_candidate_queue": True,
            "static_source_feature_ownership": False,
            "complete_route_page_feature_crosswalk": False,
            "framework_route_reachability": False,
            "navigation": False,
            "runtime": False,
            "database": False,
            "build": False,
            "application_browser": False,
            "executed_tests": False,
            "benchmark": False,
            "ease": False,
            "pass": False,
            "final_finding": False,
            "completion": False,
            "audit_complete": False,
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/"
            "build-direct-exact-route-page-review-queue-wave-11.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/"
            "root-run-090-direct-exact-route-page-review-queue-wave-11.json",
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
                "route_records": payload["counts"]["queue"]["route_records"],
                "page_records": payload["counts"]["queue"]["page_records"],
                "distinct_feature_ids": payload["counts"]["queue"]["distinct_feature_ids"],
                "canonical_key_list_sha256": payload["record_set"]["canonical_key_list_sha256"],
                "fresh_review_status": payload["fresh_review_contract"]["status"],
                "ownership_credit_awarded": payload["counts"]["ownership_credit_awarded"],
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
