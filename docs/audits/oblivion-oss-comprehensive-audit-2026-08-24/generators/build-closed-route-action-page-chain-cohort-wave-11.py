#!/usr/bin/env python3
"""Build the RUN-091 closed route/action/page chain candidate cohort.

The cohort is reconstructed from pinned residual evidence.  Each row joins an
unresolved route whose exact name and exact controller-method lanes agree on a
single FEATURE-ID to the sole literal page render inside that method, where
the unresolved page's candidate union is the same singleton FEATURE-ID.

The output is still a candidate packet.  Literal closure is necessary but is
not semantic ownership proof; three fresh reviewers must read the complete
route statement, controller method, page root, and canonical user job before
any bounded static ownership can be integrated.
"""

from __future__ import annotations

import csv
import hashlib
import json
import os
import re
import subprocess
from collections import Counter
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_PATH = (
    AUDIT_DIR
    / "evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json"
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
    "page_graph": AUDIT_DIR
    / "evidence/source/root-run-084-full-inertia-page-graph-wave-09.json",
    "page_graph_review": AUDIT_DIR
    / "evidence/source/raw-run-084r-independent-full-inertia-page-graph-review-wave-09.json",
    "ownership_ledger": AUDIT_DIR
    / "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json",
    "ownership_review": AUDIT_DIR
    / "evidence/source/raw-run-086r-independent-reviewed-static-route-page-feature-ownership-wave-10.json",
    "direct_queue": AUDIT_DIR
    / "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json",
}
EXPECTED_INPUT_SHA256 = {
    "matrix": "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390",
    "manifest": "150fcff9b100ad85a7a2e998ed69c8dafdc2d0098e8ec2b4dbac7d3b404061be",
    "classification": "7b534fea15e6c4ec98fbb2cb0c761e26116b2abee500c39e599b0e779729df97",
    "candidate_manifest": "3c6ad4df13a09ae8ff8f19aee09a1907f9754a1877806659676c6c2898652f85",
    "candidate_review": "a6a4f886ca209bc41ffa86afec37f6bddaf062ac80a6b375391adeea20e1c396",
    "page_graph": "f3856a7a86cd236684e223713a99dd64b18df692338e5d7aba688701b7c438f9",
    "page_graph_review": "036394a207f6f31c336f748bae9daed75d86549529de538510374149d56f506e",
    "ownership_ledger": "bfec52a9bed7176045457e8920bdc7b65e1c1d5c5eeb28a266f49c12acf69bcf",
    "ownership_review": "56c4832af941353aaf230ca17c792ea7191c6aebfc05bc1c511a757d5998d699",
    "direct_queue": "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
}

EXPECTED_ROUTE_IDS_SHA256 = "a54901d758b2b40afc4071c90248fdaab3229175e7034f07065025fb4aa0d159"
EXPECTED_PAGE_IDS_SHA256 = "a0608b327322ceb6915c62c60767444c26b3e4a77871192d630bc549f18de100"
EXPECTED_FEATURE_IDS_SHA256 = "f0d27f9c01e639eb51d7674a6bffe4dcdd1bfdf6adbd43a7ba404df99715f139"
EXPECTED_TUPLES_SHA256 = "d58a680e353b2cbcaef732f020660e525e08858627398280d3e4d58da3d8eedd"
EXPECTED_QUEUE_OVERLAP_SHA256 = "c2313a4be481c8d20b9baf873caebef68c1e8f1873dac66d1f99dca0c1cd348f"
EXPECTED_QUEUE_CHAIN_UNION_SHA256 = "a34d9af69ca61c41ffb63cea031674030c21cdd612b5dcd55daf9cc7320a2a0d"
EXPECTED_REVIEW_PARTITIONS = {
    "A": {
        "count": 4,
        "tuple_list_sha256": "01f178919158f2b208c3c0687fefc0e4617bbecea8d105613ac577fb203695c3",
    },
    "B": {
        "count": 2,
        "tuple_list_sha256": "aed06c3636befacfe75ba20f0ece6d824d8fbd3c726ee3b0f3fabda9d8b680fe",
    },
    "C": {
        "count": 5,
        "tuple_list_sha256": "3a2069025dfeced7d8c620610b86a9ab4f87835fe2023494daad47b6d873e102",
    },
}

FUNCTION_RE = re.compile(r"\bfunction\s+&?\s*[A-Za-z_][A-Za-z0-9_]*\s*\(")


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
        value = row.get(key)
        assert isinstance(value, str) and value, (key, value)
        assert value not in indexed, (key, value)
        indexed[value] = row
    return indexed


def review_partition(chain_key: str) -> str:
    return ("A", "B", "C")[int(sha256_bytes(chain_key.encode("utf-8")), 16) % 3]


def mask_php_comments(source: str) -> str:
    """Mask PHP comments while preserving strings, offsets, and newlines."""

    chars = list(source)
    index = 0
    state = "code"
    quote = ""
    while index < len(chars):
        char = chars[index]
        nxt = chars[index + 1] if index + 1 < len(chars) else ""
        if state == "code":
            if char in {"'", '"'}:
                state = "string"
                quote = char
                index += 1
                continue
            if char == "/" and nxt == "/":
                chars[index] = chars[index + 1] = " "
                state = "line_comment"
                index += 2
                continue
            if char == "#":
                chars[index] = " "
                state = "line_comment"
                index += 1
                continue
            if char == "/" and nxt == "*":
                chars[index] = chars[index + 1] = " "
                state = "block_comment"
                index += 2
                continue
        elif state == "string":
            if char == "\\":
                index += 2
                continue
            if char == quote:
                state = "code"
                quote = ""
                index += 1
                continue
        elif state == "line_comment":
            if char in {"\r", "\n"}:
                state = "code"
            else:
                chars[index] = " "
        elif state == "block_comment":
            if char == "*" and nxt == "/":
                chars[index] = chars[index + 1] = " "
                state = "code"
                index += 2
                continue
            if char not in {"\r", "\n"}:
                chars[index] = " "
        index += 1
    assert state != "block_comment"
    return "".join(chars)


def method_review_slice(controller_file: str, definition_line: int) -> dict[str, Any]:
    source = (REPO / controller_file).read_text(encoding="utf-8-sig")
    masked = mask_php_comments(source)
    definition_lines = sorted(
        masked.count("\n", 0, match.start()) + 1 for match in FUNCTION_RE.finditer(masked)
    )
    assert definition_line in definition_lines, (controller_file, definition_line)
    later = [line for line in definition_lines if line > definition_line]
    next_definition_line = later[0] if later else len(source.splitlines()) + 1
    lines = source.splitlines()
    review_lines = lines[definition_line - 1 : next_definition_line - 1]
    review_text = "\n".join(review_lines)
    return {
        "start_line": definition_line,
        "end_line": next_definition_line - 1,
        "next_method_definition_line": next_definition_line if later else None,
        "line_count": len(review_lines),
        "text": review_text,
        "text_sha256": sha256_bytes(review_text.encode("utf-8")),
    }


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


def add_chain_digest(record: dict[str, Any]) -> dict[str, Any]:
    assert "chain_record_sha256" not in record
    record["chain_record_sha256"] = canonical_json_sha256(record)
    return record


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
    assert sha256_file(PROMPT_PATH) == PROMPT_SHA256
    for name, path in INPUT_PATHS.items():
        assert path.is_file(), path
        assert sha256_file(path) == EXPECTED_INPUT_SHA256[name], (name, sha256_file(path))


def assert_prior_statuses(
    candidate_review: dict[str, Any],
    page_review: dict[str, Any],
    ownership_review: dict[str, Any],
    direct_queue: dict[str, Any],
) -> None:
    assert candidate_review["verdict"]["decision"] == "GO"
    assert candidate_review["verdict"]["feature_mapping_authorized"] is False
    assert candidate_review["checks"]["review_discrepancies"] == 0
    assert page_review["decision"]["verdict"] == "GO"
    assert page_review["decision"]["discrepancies"] == 0
    assert page_review["decision"]["feature_mapping_authorized"] is False
    assert ownership_review["decision"]["verdict"] == "GO"
    assert ownership_review["decision"]["discrepancies"] == 0
    assert ownership_review["decision"]["static_source_feature_ownership_authorized"] is True
    assert ownership_review["decision"]["gate_4_complete"] is False
    assert (
        direct_queue["status"]
        == "DIRECT_EXACT_SINGLETON_QUEUE_PENDING_FRESH_SEMANTIC_REVIEW_ZERO_CREDIT"
    )
    assert direct_queue["record_set"]["canonical_key_list_sha256"] == (
        "2ae2fcc3e77c4e0928e8f995a86972c79d02e7f2a76911eb543bb24717c5b5b5"
    )
    assert direct_queue["counts"]["ownership_credit_awarded"] == 0


def build() -> dict[str, Any]:
    assert_workspace_and_inputs()
    with INPUT_PATHS["matrix"].open("r", encoding="utf-8-sig", newline="") as handle:
        matrix_rows = list(csv.DictReader(handle))
    assert len(matrix_rows) == 340
    matrix_by_id = index_unique(matrix_rows, "feature_id")

    manifest = load_json(INPUT_PATHS["manifest"])
    classification = load_json(INPUT_PATHS["classification"])
    candidate_manifest = load_json(INPUT_PATHS["candidate_manifest"])
    candidate_review = load_json(INPUT_PATHS["candidate_review"])
    page_graph = load_json(INPUT_PATHS["page_graph"])
    page_review = load_json(INPUT_PATHS["page_graph_review"])
    ownership = load_json(INPUT_PATHS["ownership_ledger"])
    ownership_review = load_json(INPUT_PATHS["ownership_review"])
    direct_queue = load_json(INPUT_PATHS["direct_queue"])
    assert_prior_statuses(candidate_review, page_review, ownership_review, direct_queue)

    route_manifest_rows = list(manifest["route_universe"]["primary_route_facade_callsites"])
    route_manifest_rows += list(manifest["route_universe"]["route_like_sentinels"])
    page_manifest_rows = list(manifest["page_universe"]["page_roots"])
    route_manifest_by_id = index_unique(route_manifest_rows, "route_record_id")
    page_manifest_by_id = index_unique(page_manifest_rows, "page_record_id")
    route_decision_by_id = index_unique(classification["route_decisions"], "route_record_id")
    page_decision_by_id = index_unique(classification["page_decisions"], "page_record_id")
    page_graph_by_root_id = index_unique(
        [row for row in page_graph["records"] if row.get("page_root_id")], "page_root_id"
    )
    route_candidates = list(candidate_manifest["route_static_candidate_census"]["records"])
    page_candidates = list(candidate_manifest["page_static_candidate_census"]["records"])
    page_candidate_by_id = index_unique(page_candidates, "page_record_id")

    owned_source_record_ids = {row["source_record_id"] for row in ownership["records"]}
    queue_keys = {row["canonical_key"] for row in direct_queue["records"]}

    eligible_pages = [
        row
        for row in page_candidates
        if row["relation_comparison"] in {"RENDER_OWNER_ONLY", "BOTH_LANES_IDENTICAL"}
        and len(row["candidate_union_feature_ids"]) == 1
    ]
    all_page_callsites: list[tuple[dict[str, Any], dict[str, Any]]] = []
    for page_row in page_manifest_rows:
        for callsite in page_row["render_callsites"]:
            all_page_callsites.append((page_row, callsite))

    chain_inputs: list[dict[str, Any]] = []
    for route_candidate in route_candidates:
        name_relation = route_candidate["name_relation"]
        backend_relation = route_candidate["backend_method_relation"]
        if route_candidate["relation_comparison"] != "BOTH_LANES_IDENTICAL":
            continue
        if name_relation["candidate_count"] != 1 or backend_relation["candidate_count"] != 1:
            continue
        feature_id = name_relation["candidate_feature_ids"][0]
        if backend_relation["candidate_feature_ids"] != [feature_id]:
            continue
        resolution = backend_relation["resolution"]
        if resolution["status"] != "EXACT_CLASS_METHOD_ARRAY_RESOLVED_UNIQUE_DEFINITION":
            continue
        review_slice = method_review_slice(
            resolution["controller_file"], resolution["definition_line"]
        )
        matching_page_candidates: list[tuple[dict[str, Any], dict[str, Any]]] = []
        for page_candidate in eligible_pages:
            if page_candidate["candidate_union_feature_ids"] != [feature_id]:
                continue
            for callsite in page_candidate["render_callsites"]:
                if (
                    callsite["source_file"] == resolution["controller_file"]
                    and review_slice["start_line"]
                    <= callsite["source_line"]
                    <= review_slice["end_line"]
                ):
                    matching_page_candidates.append((page_candidate, callsite))

        literal_calls_inside_method = [
            (page_row, callsite)
            for page_row, callsite in all_page_callsites
            if callsite["source_file"] == resolution["controller_file"]
            and review_slice["start_line"]
            <= callsite["source_line"]
            <= review_slice["end_line"]
        ]
        if len(matching_page_candidates) != 1 or len(literal_calls_inside_method) != 1:
            continue
        page_candidate, candidate_callsite = matching_page_candidates[0]
        rendered_page, full_callsite = literal_calls_inside_method[0]
        if page_candidate["page_record_id"] != rendered_page["page_record_id"]:
            continue
        if candidate_callsite["source_anchor"] != full_callsite["source_anchor"]:
            continue
        chain_inputs.append(
            {
                "route_candidate": route_candidate,
                "page_candidate": page_candidate,
                "route_manifest": route_manifest_by_id[route_candidate["route_record_id"]],
                "page_manifest": rendered_page,
                "full_render_callsite": full_callsite,
                "method_review_slice": review_slice,
                "feature_id": feature_id,
            }
        )

    chain_inputs.sort(key=lambda row: row["route_candidate"]["route_record_id"])
    assert len(chain_inputs) == 11
    assert len({row["route_candidate"]["route_record_id"] for row in chain_inputs}) == 11
    assert len({row["page_candidate"]["page_record_id"] for row in chain_inputs}) == 11

    records: list[dict[str, Any]] = []
    for sequence, item in enumerate(chain_inputs, start=1):
        route_candidate = item["route_candidate"]
        page_candidate = item["page_candidate"]
        route_manifest = item["route_manifest"]
        page_manifest = item["page_manifest"]
        full_callsite = item["full_render_callsite"]
        review_slice = item["method_review_slice"]
        feature_id = item["feature_id"]
        route_id = route_candidate["route_record_id"]
        page_id = page_candidate["page_record_id"]
        chain_key = f"{route_id}|{page_id}|{feature_id}"
        resolution = route_candidate["backend_method_relation"]["resolution"]
        matrix_row = matrix_by_id[feature_id]
        graph_row = page_graph_by_root_id[page_id]

        assert route_decision_by_id[route_id]["classification"] == "EXPLICIT_UNMAPPED_SENTINEL"
        assert page_decision_by_id[page_id]["prompt_classification"] == "Evidence gap"
        assert route_id not in owned_source_record_ids
        assert page_id not in owned_source_record_ids
        assert graph_row["partition"] == "LITERAL_RENDERED_PAGE_ROOT"
        assert graph_row["prompt_classification"] == "Evidence gap"
        assert graph_row["path"] == page_manifest["page_file"]
        assert graph_row["sha256"] == page_manifest["page_file_sha256"]
        assert graph_row["blob_id"] == page_manifest["page_file_blob_id"]
        assert route_candidate["name_relation"]["candidate_feature_ids"] == [feature_id]
        assert route_candidate["backend_method_relation"]["candidate_feature_ids"] == [feature_id]
        assert page_candidate["candidate_union_feature_ids"] == [feature_id]
        assert route_manifest["statement_excerpt"]
        assert sha256_file(REPO / resolution["controller_file"]) == resolution[
            "controller_file_sha256"
        ]
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{resolution['controller_file']}")
        assert sha256_file(REPO / page_manifest["page_file"]) == page_manifest["page_file_sha256"]
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{page_manifest['page_file']}") == page_manifest[
            "page_file_blob_id"
        ]
        assert full_callsite["source_line"] >= review_slice["start_line"]
        assert full_callsite["source_line"] <= review_slice["end_line"]

        projection = feature_projection(matrix_row)
        evidence_projection = {
            "route_candidate_sha256": canonical_json_sha256(route_candidate),
            "page_candidate_sha256": canonical_json_sha256(page_candidate),
            "route_manifest_sha256": canonical_json_sha256(route_manifest),
            "page_manifest_sha256": canonical_json_sha256(page_manifest),
            "page_graph_sha256": canonical_json_sha256(graph_row),
            "feature_projection_sha256": canonical_json_sha256(projection),
            "method_review_slice_sha256": review_slice["text_sha256"],
        }
        records.append(
            add_chain_digest(
                {
                    "chain_id": f"RUN091-CHAIN-{sequence:02d}",
                    "chain_key": chain_key,
                    "review_partition": review_partition(chain_key),
                    "candidate_feature_id": feature_id,
                    "route_source": {
                        "route_record_id": route_id,
                        "original_partition": route_candidate["partition_id"],
                        "route_file": route_manifest["route_file"],
                        "route_file_sha256": route_manifest["route_file_sha256"],
                        "route_file_blob_id": route_manifest["route_file_blob_id"],
                        "source_key": route_manifest["source_key"],
                        "source_anchor": route_manifest["source_anchor"],
                        "route_method": route_manifest["route_method"],
                        "literal_uri": route_manifest["literal_uri"],
                        "literal_route_name": route_manifest["direct_name_literal"],
                        "action_expression": route_manifest["action_expression"],
                        "statement_excerpt": route_manifest["statement_excerpt"],
                        "statement_sha256": route_manifest["statement_sha256"],
                        "candidate_relation": route_candidate["relation_comparison"],
                        "name_candidate_feature_ids": route_candidate["name_relation"][
                            "candidate_feature_ids"
                        ],
                        "backend_candidate_feature_ids": route_candidate[
                            "backend_method_relation"
                        ]["candidate_feature_ids"],
                    },
                    "controller_action_bridge": {
                        "relation_class": "STATIC_CONTROLLER_ACTION_BRIDGE_CANDIDATE",
                        "controller_fqcn": resolution["resolved_fqcn"],
                        "controller_file": resolution["controller_file"],
                        "controller_file_sha256": resolution["controller_file_sha256"],
                        "controller_file_blob_id": git(
                            "rev-parse", f"{APPLICATION_COMMIT}:{resolution['controller_file']}"
                        ),
                        "method": resolution["method"],
                        "definition_line": resolution["definition_line"],
                        "definition_anchor": resolution["definition_anchor"],
                        "method_review_slice": review_slice,
                        "literal_render_count_inside_method_slice": 1,
                        "render_source_anchor": full_callsite["source_anchor"],
                        "render_syntax": full_callsite["syntax"],
                        "ownership_credit": False,
                    },
                    "page_source": {
                        "page_record_id": page_id,
                        "original_partition": page_candidate["partition_id"],
                        "page_file": page_manifest["page_file"],
                        "page_file_sha256": page_manifest["page_file_sha256"],
                        "page_file_blob_id": page_manifest["page_file_blob_id"],
                        "render_names": page_manifest["render_names"],
                        "render_call_count": page_manifest["render_call_count"],
                        "joined_render_callsite": full_callsite,
                        "candidate_relation": page_candidate["relation_comparison"],
                        "candidate_union_feature_ids": page_candidate["candidate_union_feature_ids"],
                        "page_graph_partition": graph_row["partition"],
                        "page_graph_prompt_classification": graph_row["prompt_classification"],
                        "page_graph_root_source_anchors": graph_row["root_source_anchors"],
                    },
                    "feature_identity_projection": projection,
                    "evidence_digests": {
                        **evidence_projection,
                        "joined_chain_evidence_sha256": canonical_json_sha256(evidence_projection),
                    },
                    "fresh_review_state": {
                        "status": "PENDING",
                        "allowed_outcomes": [
                            "OWNER_CHAIN",
                            "SHARED_RELATION",
                            "ALIAS_OR_REDIRECT",
                            "DEAD_OR_NONCANONICAL",
                            "EVIDENCE_GAP",
                        ],
                        "route_ownership_credit": False,
                        "page_ownership_credit": False,
                        "controller_action_bridge_credit": False,
                    },
                }
            )
        )

    route_ids = [row["route_source"]["route_record_id"] for row in records]
    page_ids = [row["page_source"]["page_record_id"] for row in records]
    feature_ids = {row["candidate_feature_id"] for row in records}
    chain_keys = [row["chain_key"] for row in records]
    assert canonical_list_sha256(route_ids) == EXPECTED_ROUTE_IDS_SHA256
    assert canonical_list_sha256(page_ids) == EXPECTED_PAGE_IDS_SHA256
    assert canonical_list_sha256(feature_ids) == EXPECTED_FEATURE_IDS_SHA256
    assert canonical_list_sha256(chain_keys) == EXPECTED_TUPLES_SHA256
    assert len(feature_ids) == 9
    assert all(matrix_by_id[value]["feature_class"] == "H" for value in feature_ids)
    assert Counter(row["page_source"]["candidate_relation"] for row in records) == {
        "RENDER_OWNER_ONLY": 10,
        "BOTH_LANES_IDENTICAL": 1,
    }
    assert Counter(row["route_source"]["original_partition"] for row in records) == {
        "A": 6,
        "B": 3,
        "C": 2,
    }
    assert Counter(row["page_source"]["original_partition"] for row in records) == {
        "A": 3,
        "B": 5,
        "C": 3,
    }
    assert all(
        row["chain_record_sha256"]
        == canonical_json_sha256({key: value for key, value in row.items() if key != "chain_record_sha256"})
        for row in records
    )

    chain_surface_keys = {
        *[f"route|{value}" for value in route_ids],
        *[f"page|{value}" for value in page_ids],
    }
    overlap = chain_surface_keys & queue_keys
    union = chain_surface_keys | queue_keys
    assert len(overlap) == 12
    assert canonical_list_sha256(overlap) == EXPECTED_QUEUE_OVERLAP_SHA256
    assert len(union) == 517
    assert canonical_list_sha256(union) == EXPECTED_QUEUE_CHAIN_UNION_SHA256

    partition_summaries: list[dict[str, Any]] = []
    for partition_id in ("A", "B", "C"):
        partition_records = [row for row in records if row["review_partition"] == partition_id]
        expected = EXPECTED_REVIEW_PARTITIONS[partition_id]
        assert len(partition_records) == expected["count"]
        assert canonical_list_sha256([row["chain_key"] for row in partition_records]) == expected[
            "tuple_list_sha256"
        ]
        partition_summaries.append(
            {
                "partition_id": partition_id,
                "assignment_rule": "int(SHA256(chain_key), 16) mod 3; 0=A, 1=B, 2=C",
                "chain_count": len(partition_records),
                "chain_key_list_sha256": expected["tuple_list_sha256"],
                "chain_record_sha256_list_sha256": canonical_list_sha256(
                    [row["chain_record_sha256"] for row in partition_records]
                ),
                "review_status": "PENDING",
            }
        )

    return {
        "schema_version": "1.0.0",
        "run_id": "RUN-091-CLOSED-ROUTE-ACTION-PAGE-CHAIN-COHORT-WAVE-11",
        "status": "STATIC_CANDIDATE_COHORT_COMPLETE_PENDING_THREE_FRESH_REVIEWS_ZERO_CREDIT",
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
            "Oblivion Findings is one operating organisation with multiple Sites. Static chain ownership "
            "does not establish permission, approved-Site reach, direct-object concealment, privacy, lifecycle, "
            "runtime, or release correctness."
        ),
        "selection_contract": {
            "rule": (
                "Select RUN-078 explicit-unmapped routes where RUN-082 exact route-name and exact unique "
                "controller-method lanes are identical singletons; require that method slice to contain exactly "
                "one literal RUN-077 rendered unresolved page whose RUN-082 candidate union is the same singleton "
                "FEATURE-ID and whose RUN-084 partition is LITERAL_RENDERED_PAGE_ROOT."
            ),
            "required_page_relations": ["RENDER_OWNER_ONLY", "BOTH_LANES_IDENTICAL"],
            "prohibited_inheritance": [
                "route group prefix",
                "adjacency",
                "import",
                "controller or directory containment",
                "render ownership without exact action closure",
                "page or file presence",
                "navigation",
                "middleware",
                "prior credit",
            ],
            "semantic_boundary": (
                "Exact static closure creates a bounded review cohort only. Reviewers must read every complete "
                "route statement, controller-method slice, page root, and canonical user job before deciding."
            ),
        },
        "counts": {
            "chains": 11,
            "route_records": 11,
            "page_records": 11,
            "controller_actions": 11,
            "controller_action_bridge_candidates": 11,
            "distinct_feature_ids": 9,
            "distinct_H_feature_ids": 9,
            "distinct_D_feature_ids": 0,
            "page_relations": {"RENDER_OWNER_ONLY": 10, "BOTH_LANES_IDENTICAL": 1},
            "queue_overlap_surface_rows": 12,
            "queue_only_surface_rows": 495,
            "auxiliary_chain_page_rows": 10,
            "queue_chain_union_surface_rows": 517,
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
        "identity": {
            "route_record_id_list_sha256": canonical_list_sha256(route_ids),
            "page_record_id_list_sha256": canonical_list_sha256(page_ids),
            "feature_id_list_sha256": canonical_list_sha256(feature_ids),
            "chain_key_list_sha256": canonical_list_sha256(chain_keys),
            "queue_overlap_surface_key_list_sha256": canonical_list_sha256(overlap),
            "queue_chain_union_surface_key_list_sha256": canonical_list_sha256(union),
            "chain_record_sha256_list_sha256": canonical_list_sha256(
                [row["chain_record_sha256"] for row in records]
            ),
            "records_sha256": canonical_json_sha256(records),
        },
        "review_partitions": partition_summaries,
        "records": records,
        "fresh_review_contract": {
            "status": "PENDING",
            "required_reviews": 3,
            "required_outcome_per_chain": True,
            "allowed_outcomes": [
                "OWNER_CHAIN",
                "SHARED_RELATION",
                "ALIAS_OR_REDIRECT",
                "DEAD_OR_NONCANONICAL",
                "EVIDENCE_GAP",
            ],
            "required_checks": [
                "Reconstruct the assigned chains directly from pinned matrix, route/page manifest, classification, candidate manifest, page graph, and prior reviewed ledger.",
                "Read the complete route statement, exact controller-method review slice, rendered page root, and canonical matrix user job.",
                "Reject shared shells, multi-feature actions/pages, semantic mismatch, nonexact actions, multiple/dynamic render roots, dead/noncanonical routes, and evidence gaps.",
                "Return an explicit outcome and rationale for every assigned chain; literal matches alone are insufficient.",
                "Keep permission/Site/privacy readiness and all framework/runtime/browser/test/benchmark/Pass/finding/completion credit separate and false."
            ],
            "ownership_integration_authorized": False,
        },
        "projected_overlay_only_if_all_11_are_explicit_owner_chain": {
            "static_route_owners": {"before": 212, "after": 223},
            "static_page_owners": {"before": 318, "after": 329},
            "bounded_static_source_owners": {"before": 530, "after": 552, "denominator": 3929},
            "bounded_static_source_ownership_percent_after": "14.049376",
            "explicit_unmapped_routes": {"before": 3003, "after": 2992},
            "page_evidence_gaps": {"before": 393, "after": 382},
            "distinct_owned_feature_ids": {"before": 235, "after": 240},
            "static_controller_action_bridges": {"before": 0, "after": 11},
            "projection_credit_awarded": False,
        },
        "denominator_boundary": {
            "run_077_bounded_static_records": 3929,
            "framework_expanded_route_page_denominator": None,
            "page_denominator_711_roots_vs_963_page_tree_files_resolved": False,
            "gate_4_complete": False,
        },
        "credit_boundary": {
            "closed_static_candidate_cohort": True,
            "static_source_feature_ownership": False,
            "static_controller_action_bridge": False,
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
            "build-closed-route-action-page-chain-cohort-wave-11.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/"
            "root-run-091-closed-route-action-page-chain-cohort-wave-11.json",
        ],
    }


def main() -> None:
    payload = build()
    encoded = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    output_sha256 = sha256_bytes(encoded)
    OUTPUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    if OUTPUT_PATH.exists():
        assert OUTPUT_PATH.read_bytes() == encoded, f"Refusing to overwrite different bytes: {OUTPUT_PATH}"
    else:
        temporary = OUTPUT_PATH.with_suffix(OUTPUT_PATH.suffix + ".tmp")
        temporary.write_bytes(encoded)
        assert sha256_file(temporary) == output_sha256
        os.replace(temporary, OUTPUT_PATH)
    assert sha256_file(OUTPUT_PATH) == output_sha256
    print(
        json.dumps(
            {
                "status": payload["status"],
                "output": OUTPUT_PATH.relative_to(REPO).as_posix(),
                "sha256": output_sha256,
                "chains": payload["counts"]["chains"],
                "route_records": payload["counts"]["route_records"],
                "page_records": payload["counts"]["page_records"],
                "distinct_feature_ids": payload["counts"]["distinct_feature_ids"],
                "chain_key_list_sha256": payload["identity"]["chain_key_list_sha256"],
                "fresh_review_status": payload["fresh_review_contract"]["status"],
                "ownership_credit_awarded": payload["counts"]["ownership_credit_awarded"],
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
