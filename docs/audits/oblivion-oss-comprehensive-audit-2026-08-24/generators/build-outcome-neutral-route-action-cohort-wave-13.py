#!/usr/bin/env python3
"""Build the RUN-101 outcome-neutral route/action review cohort.

The producer freezes 24 exact, previously unreviewed RUN-090 route surfaces.
It enriches controller wrappers, IT knowledge-base lifecycle service methods,
request contracts, and the disputed recipe-edit caller/page context.  It awards
zero ownership before three fresh semantic reviews and authorizes zero pages.
"""

from __future__ import annotations

import importlib.util
import json
import re
from collections import Counter, defaultdict, deque
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
BASE_GENERATOR = AUDIT_DIR / "generators/build-route-controller-only-candidate-cohort-wave-12.py"
OUTPUT_PATH = AUDIT_DIR / "evidence/source/root-run-101-outcome-neutral-route-action-cohort-wave-13.json"
PROMPT_PATH = Path(r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md")

CHECKPOINT_COMMIT = "a6e6add624a42cd49715709ea310a8484c4903b6"
CHECKPOINT_TREE = "59a7684269e46592de73d95540c6d7fa5fd18c2c"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"

spec = importlib.util.spec_from_file_location("run097_base", BASE_GENERATOR)
assert spec and spec.loader
BASE = importlib.util.module_from_spec(spec)
spec.loader.exec_module(BASE)

sha256_file = BASE.sha256_file
canonical_json_sha256 = BASE.canonical_json_sha256
canonical_list_sha256 = BASE.canonical_list_sha256
load_json = BASE.load_json
git = BASE.git
index_unique = BASE.index_unique

INPUT_PATHS = dict(BASE.INPUT_PATHS)
INPUT_PATHS.update({
    "base_generator": BASE_GENERATOR,
    "page_graph": AUDIT_DIR / "evidence/source/root-run-084-full-inertia-page-graph-wave-09.json",
    "page_graph_review": AUDIT_DIR / "evidence/source/raw-run-084r-independent-full-inertia-page-graph-review-wave-09.json",
    "run097_cohort": AUDIT_DIR / "evidence/source/root-run-097-route-controller-only-candidate-cohort-wave-12.json",
    "run097_review": AUDIT_DIR / "evidence/source/raw-run-097r-independent-route-controller-only-review-wave-12.json",
    "current_route_overlay": AUDIT_DIR / "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json",
    "current_overlay_review": AUDIT_DIR / "evidence/source/raw-run-098r-independent-reviewed-route-controller-only-ownership-overlay-wave-12.json",
    "current_reporting": AUDIT_DIR / "evidence/source/current-run-099-reviewed-route-controller-only-reporting-wave-12.json",
    "current_dashboard_review": AUDIT_DIR / "evidence/browser/current-audit-dashboard-verification-run-100-wave-12.json",
})

EXPECTED_INPUT_SHA256 = dict(BASE.EXPECTED_INPUT_SHA256)
EXPECTED_INPUT_SHA256.update({
    "base_generator": "b2214935c7a00a1f231d2949b6a5b8a481b654a6c6e16bae016c841c21c9c2f1",
    "page_graph": "f3856a7a86cd236684e223713a99dd64b18df692338e5d7aba688701b7c438f9",
    "page_graph_review": "036394a207f6f31c336f748bae9daed75d86549529de538510374149d56f506e",
    "run097_cohort": "69981d1bc22d76b8f17834040272260d9b33c151535a3ff2ef17ae4643923933",
    "run097_review": "125c36710cff83750e3bc2e443955f34b5c019f60b36b874790fce9de9774f0a",
    "current_route_overlay": "7f76c9cfeae906a64c92013fbf9a12cf180d39a315bfa13e778b6332365cdd2a",
    "current_overlay_review": "b7ef9888eca1f8ab47653b19be44d9de385f2132148dfed38b5d8d5018b1903b",
    "current_reporting": "84e6e9a46c02a82bf9775253919bfde6b1b86a587be280d8248e4b2e38691514",
    "current_dashboard_review": "65c6852f6c39927142aaf0244347cbf6924a086db61eaa6a02938fe59966ab1c",
})

# Zero-based RUN-090 array indices.  The explicit 8/8/8 assignment is a new
# review partition; each record also preserves its original RUN-090 partition.
SELECTED = (
    (19, "A", "RUN090-ROUTE-0020", "RUN077-ROUTE-0100", "CAP-CATER-RECIPE-LIBRARY"),
    (20, "A", "RUN090-ROUTE-0021", "RUN077-ROUTE-0101", "CAP-CATER-RECIPE-LIBRARY"),
    (21, "A", "RUN090-ROUTE-0022", "RUN077-ROUTE-0102", "CAP-CATER-RECIPE-LIBRARY"),
    (22, "A", "RUN090-ROUTE-0023", "RUN077-ROUTE-0103", "CAP-CATER-RECIPE-LIBRARY"),
    (23, "A", "RUN090-ROUTE-0024", "RUN077-ROUTE-0104", "CAP-CATER-RECIPE-LIBRARY"),
    (24, "A", "RUN090-ROUTE-0025", "RUN077-ROUTE-0105", "CAP-CATER-RECIPE-LIBRARY"),
    (25, "A", "RUN090-ROUTE-0026", "RUN077-ROUTE-0106", "CAP-CATER-RECIPE-LIBRARY"),
    (26, "A", "RUN090-ROUTE-0027", "RUN077-ROUTE-0107", "CAP-CATER-PRODUCT-CATALOG"),
    (27, "B", "RUN090-ROUTE-0028", "RUN077-ROUTE-0108", "CAP-CATER-PRODUCT-CATALOG"),
    (28, "B", "RUN090-ROUTE-0029", "RUN077-ROUTE-0109", "CAP-CATER-PRODUCT-CATALOG"),
    (29, "B", "RUN090-ROUTE-0030", "RUN077-ROUTE-0110", "CAP-CATER-PRODUCT-CATALOG"),
    (30, "B", "RUN090-ROUTE-0031", "RUN077-ROUTE-0111", "CAP-CATER-DIETARY-TAG-LIBRARY"),
    (31, "B", "RUN090-ROUTE-0032", "RUN077-ROUTE-0112", "CAP-CATER-DIETARY-TAG-LIBRARY"),
    (32, "B", "RUN090-ROUTE-0033", "RUN077-ROUTE-0113", "CAP-CATER-DIETARY-TAG-LIBRARY"),
    (33, "B", "RUN090-ROUTE-0034", "RUN077-ROUTE-0114", "CAP-CATER-DIETARY-TAG-LIBRARY"),
    (289, "B", "RUN090-ROUTE-0290", "RUN077-ROUTE-2311", "CAP-NOTIF-PERSONAL-INBOX-ACK"),
    (463, "C", "RUN090-ROUTE-0464", "RUN077-ROUTE-2716", "CAP-SEC-REPORTING-EXPORT"),
    (464, "C", "RUN090-ROUTE-0465", "RUN077-ROUTE-2717", "CAP-SEC-REPORTING-EXPORT"),
    (496, "C", "RUN090-ROUTE-0497", "RUN077-ROUTE-3193", "CAP-IT-KNOWLEDGE-BASE"),
    (497, "C", "RUN090-ROUTE-0498", "RUN077-ROUTE-3194", "CAP-IT-KNOWLEDGE-BASE"),
    (498, "C", "RUN090-ROUTE-0499", "RUN077-ROUTE-3195", "CAP-IT-KNOWLEDGE-BASE"),
    (499, "C", "RUN090-ROUTE-0500", "RUN077-ROUTE-3196", "CAP-IT-KNOWLEDGE-BASE"),
    (500, "C", "RUN090-ROUTE-0501", "RUN077-ROUTE-3197", "CAP-IT-KNOWLEDGE-BASE"),
    (501, "C", "RUN090-ROUTE-0502", "RUN077-ROUTE-3198", "CAP-IT-KNOWLEDGE-BASE"),
)

IT_SERVICE_FILE = "app/Domain/It/Services/ItKbLifecycleService.php"
IT_SERVICE_METHOD = {
    496: "update",
    497: "submitForReview",
    498: "publish",
    499: "retire",
    500: "restore",
    501: "deleteDraft",
}
REQUEST_CONTRACTS = {
    496: "app/Http/Requests/It/UpdateKbArticleRequest.php",
    499: "app/Http/Requests/It/RetireKbArticleRequest.php",
    501: "app/Http/Requests/It/DeleteKbArticleRequest.php",
}
CALLER_PATTERNS = {
    "recipe_prefix": re.compile(r"/catering/recipes"),
    "recipe_edit_dynamic_path": re.compile(r"/catering/recipes/\$\{[^}]+\}/edit"),
    "product_prefix": re.compile(r"/catering/products"),
    "tag_prefix": re.compile(r"/catering/tags"),
}
METHOD_DEFINITION_RE = re.compile(r"\bfunction\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(")
LOCAL_CALL_RE = re.compile(r"\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\(")


def method_definitions(relative: str) -> dict[str, int]:
    source = (REPO / relative).read_text(encoding="utf-8")
    masked = BASE.mask_php_comments(source)
    result: dict[str, int] = {}
    for line_number, line in enumerate(masked.splitlines(), 1):
        for match in METHOD_DEFINITION_RE.finditer(line):
            name = match.group(1)
            assert name not in result, (relative, name)
            result[name] = line_number
    return result


def semantic_slice(relative: str, method: str) -> dict[str, Any]:
    definitions = method_definitions(relative)
    assert method in definitions, (relative, method)
    review_slice = BASE.method_review_slice(relative, definitions[method])
    return {
        "source_file": relative,
        "source_file_sha256": sha256_file(REPO / relative),
        "source_file_blob_id": git("rev-parse", f"{APPLICATION_COMMIT}:{relative}"),
        "method": method,
        "definition_line": definitions[method],
        "definition_anchor": f"{relative}:{definitions[method]}",
        "review_slice": review_slice,
    }


def transitive_local_helper_slices(relative: str, primary_method: str, primary_text: str) -> list[dict[str, Any]]:
    definitions = method_definitions(relative)
    pending = deque(sorted(set(LOCAL_CALL_RE.findall(primary_text))))
    seen = {primary_method}
    helpers: list[dict[str, Any]] = []
    while pending:
        method = pending.popleft()
        if method in seen or method not in definitions:
            continue
        seen.add(method)
        item = semantic_slice(relative, method)
        helpers.append(item)
        for nested in sorted(set(LOCAL_CALL_RE.findall(item["review_slice"]["text"]))):
            if nested not in seen:
                pending.append(nested)
    return sorted(helpers, key=lambda row: (row["source_file"], row["definition_line"]))


def request_contract(relative: str) -> dict[str, Any]:
    definitions = method_definitions(relative)
    methods = [semantic_slice(relative, name) for name in ("authorize", "rules") if name in definitions]
    assert methods, relative
    return {
        "source_file": relative,
        "source_file_sha256": sha256_file(REPO / relative),
        "source_file_blob_id": git("rev-parse", f"{APPLICATION_COMMIT}:{relative}"),
        "method_slices": methods,
    }


def caller_census(page_graph_by_path: dict[str, dict[str, Any]]) -> dict[str, Any]:
    tracked = git("ls-tree", "-r", "--name-only", APPLICATION_COMMIT, "--", "resources/js").splitlines()
    source_paths = [path for path in tracked if Path(path).suffix.lower() in {".ts", ".tsx", ".js", ".jsx"}]
    result: dict[str, Any] = {}
    for label, pattern in CALLER_PATTERNS.items():
        occurrences: list[dict[str, Any]] = []
        for relative in source_paths:
            source = (REPO / relative).read_text(encoding="utf-8")
            for line_number, line in enumerate(source.splitlines(), 1):
                if not pattern.search(line):
                    continue
                page_context = page_graph_by_path.get(relative)
                occurrences.append({
                    "source_file": relative,
                    "source_file_sha256": sha256_file(REPO / relative),
                    "source_file_blob_id": git("rev-parse", f"{APPLICATION_COMMIT}:{relative}"),
                    "source_line": line_number,
                    "source_anchor": f"{relative}:{line_number}",
                    "line_text": line.strip(),
                    "page_graph_context": None if page_context is None else {
                        "partition": page_context["partition"],
                        "literal_backend_render_root": page_context["literal_backend_render_root"],
                        "prompt_classification": page_context["prompt_classification"],
                        "production_direct_value_import_count": page_context["production_direct_value_import_count"],
                        "transitive_rendered_root_count": page_context["transitive_rendered_root_count"],
                        "feature_mapping_credit": page_context["feature_mapping_credit"],
                    },
                })
        occurrences.sort(key=lambda row: (row["source_file"], row["source_line"]))
        result[label] = {
            "pattern": pattern.pattern,
            "occurrence_count": len(occurrences),
            "occurrences": occurrences,
            "absence_is_not_dead_or_alias_proof": True,
            "runtime_or_navigation_credit": False,
        }
    return result


def assert_workspace_and_inputs() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("status", "--porcelain", "--", "app", "routes", "resources/js") == ""
    assert sha256_file(PROMPT_PATH) == PROMPT_SHA256
    for name, target in INPUT_PATHS.items():
        assert target.is_file(), target
        assert sha256_file(target) == EXPECTED_INPUT_SHA256[name], name


def build() -> dict[str, Any]:
    assert_workspace_and_inputs()
    with BASE.INPUT_PATHS["matrix"].open("r", encoding="utf-8-sig", newline="") as handle:
        import csv
        matrix_rows = list(csv.DictReader(handle))
    assert len(matrix_rows) == 340
    matrix_by_id = index_unique(matrix_rows, "feature_id")

    manifest = load_json(BASE.INPUT_PATHS["manifest"])
    classification = load_json(BASE.INPUT_PATHS["classification"])
    candidates = load_json(BASE.INPUT_PATHS["candidate_manifest"])
    ownership = load_json(BASE.INPUT_PATHS["ownership_ledger"])
    queue = load_json(BASE.INPUT_PATHS["direct_queue"])
    run091 = load_json(BASE.INPUT_PATHS["closed_cohort"])
    run092 = load_json(BASE.INPUT_PATHS["current_overlay"])
    run097 = load_json(INPUT_PATHS["run097_cohort"])
    run098 = load_json(INPUT_PATHS["current_route_overlay"])
    run098_review = load_json(INPUT_PATHS["current_overlay_review"])
    page_graph = load_json(INPUT_PATHS["page_graph"])

    assert run098["combined_counts"]["source_owner_records"] == 571
    assert run098["combined_counts"]["route_owner_records"] == 244
    assert run098["combined_counts"]["page_owner_records"] == 327
    assert run098["combined_counts"]["static_controller_action_bridges"] == 32
    assert run098["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 472
    assert run098_review["decision"]["verdict"] == "GO"

    route_manifest_rows = list(manifest["route_universe"]["primary_route_facade_callsites"])
    route_manifest_rows += list(manifest["route_universe"]["route_like_sentinels"])
    route_manifest_by_id = index_unique(route_manifest_rows, "route_record_id")
    route_decision_by_id = index_unique(classification["route_decisions"], "route_record_id")
    route_candidate_by_id = index_unique(
        candidates["route_static_candidate_census"]["records"], "route_record_id"
    )
    page_manifest_rows = list(manifest["page_universe"]["page_roots"])
    page_graph_by_path = index_unique(page_graph["records"], "path")

    current_owner_rows = (
        list(ownership["records"])
        + list(run092["overlay_source_records"])
        + list(run098["overlay_source_records"])
    )
    current_owner_ids = {row["source_record_id"] for row in current_owner_rows}
    current_owner_features = {row["feature_id"] for row in current_owner_rows}
    reviewed_ids = {
        row["route_source"]["route_record_id"] for row in run091["records"]
    } | {
        row["route_source"]["route_record_id"] for row in run097["records"]
    }
    existing_bridge_keys = {
        (row["controller_file"], row["method"], row["feature_id"])
        for row in run092["static_controller_action_bridges"]
    } | {
        (row["controller_file"], row["method"], row["feature_id"])
        for row in run098["new_static_controller_action_bridges"]
    }

    frontend_census = caller_census(page_graph_by_path)
    records: list[dict[str, Any]] = []
    for sequence, (queue_index, partition, queue_id, route_id, feature_id) in enumerate(SELECTED, 1):
        queue_row = queue["records"][queue_index]
        assert queue_row["queue_id"] == queue_id
        assert queue_row["source_record_id"] == route_id
        assert queue_row["candidate_feature_id"] == feature_id
        assert queue_row["surface"] == "ROUTE_SOURCE_RECORD"
        assert queue_row["review_state"]["status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
        assert queue_row["secondary_lane"]["relation_comparison"] == "BOTH_LANES_IDENTICAL"
        assert queue_row["secondary_lane"]["contradictory_candidate_present"] is False
        assert route_id not in reviewed_ids
        assert route_id not in current_owner_ids

        route_manifest = route_manifest_by_id[route_id]
        route_decision = route_decision_by_id[route_id]
        route_candidate = route_candidate_by_id[route_id]
        backend = route_candidate["backend_method_relation"]
        resolution = backend["resolution"]
        assert route_decision["classification"] == "EXPLICIT_UNMAPPED_SENTINEL"
        assert route_candidate["relation_comparison"] == "BOTH_LANES_IDENTICAL"
        assert route_candidate["name_relation"]["candidate_feature_ids"] == [feature_id]
        assert backend["candidate_feature_ids"] == [feature_id]
        assert backend["candidate_count"] == 1
        assert resolution["status"] == "EXACT_CLASS_METHOD_ARRAY_RESOLVED_UNIQUE_DEFINITION"
        assert sha256_file(REPO / route_manifest["route_file"]) == route_manifest["route_file_sha256"]
        assert sha256_file(REPO / resolution["controller_file"]) == resolution["controller_file_sha256"]

        primary_slice = semantic_slice(resolution["controller_file"], resolution["method"])
        local_helpers = transitive_local_helper_slices(
            resolution["controller_file"], resolution["method"], primary_slice["review_slice"]["text"]
        )
        external_support = []
        if queue_index in IT_SERVICE_METHOD:
            external_support.append(semantic_slice(IT_SERVICE_FILE, IT_SERVICE_METHOD[queue_index]))
        request_support = None
        if queue_index in REQUEST_CONTRACTS:
            request_support = request_contract(REQUEST_CONTRACTS[queue_index])

        literal_pages: list[dict[str, Any]] = []
        for page_row in page_manifest_rows:
            for callsite in page_row["render_callsites"]:
                if (
                    callsite["source_file"] == resolution["controller_file"]
                    and primary_slice["review_slice"]["start_line"]
                    <= callsite["source_line"]
                    <= primary_slice["review_slice"]["end_line"]
                ):
                    literal_pages.append({
                        "page_record_id": page_row["page_record_id"],
                        "page_file": page_row["page_file"],
                        "source_anchor": callsite["source_anchor"],
                    })
        assert literal_pages == []

        bridge_key = (resolution["controller_file"], resolution["method"], feature_id)
        assert bridge_key not in existing_bridge_keys
        action_key = f"{route_id}|{resolution['controller_file']}:{resolution['method']}|{feature_id}"
        caller_keys: list[str] = []
        if feature_id == "CAP-CATER-RECIPE-LIBRARY":
            caller_keys.append("recipe_prefix")
            if queue_index == 22:
                caller_keys.append("recipe_edit_dynamic_path")
        elif feature_id == "CAP-CATER-PRODUCT-CATALOG":
            caller_keys.append("product_prefix")
        elif feature_id == "CAP-CATER-DIETARY-TAG-LIBRARY":
            caller_keys.append("tag_prefix")

        record: dict[str, Any] = {
            "candidate_id": f"RUN101-ROUTE-ACTION-{sequence:02d}",
            "action_key": action_key,
            "review_partition": partition,
            "run090_original_partition": queue_row["review_partition"],
            "queue_index_zero_based": queue_index,
            "queue_id": queue_id,
            "queue_canonical_key": queue_row["canonical_key"],
            "candidate_feature_id": feature_id,
            "route_source": {
                "route_record_id": route_id,
                "route_file": route_manifest["route_file"],
                "route_file_sha256": route_manifest["route_file_sha256"],
                "route_file_blob_id": route_manifest["route_file_blob_id"],
                "source_key": route_manifest["source_key"],
                "source_anchor": route_manifest["source_anchor"],
                "route_method": route_manifest["route_method"],
                "literal_uri": route_manifest["literal_uri"],
                "literal_route_name": queue_row["source"]["literal_route_name"],
                "action_expression": route_manifest["action_expression"],
                "statement_excerpt": route_manifest["statement_excerpt"],
                "statement_sha256": route_manifest["statement_sha256"],
                "direct_identity": queue_row["direct_identity"],
            },
            "controller_action": {
                "relation_class": "STATIC_CONTROLLER_ACTION_REVIEW_CANDIDATE",
                "controller_fqcn": resolution["resolved_fqcn"],
                "primary_method_slice": primary_slice,
                "transitive_local_helper_slices": local_helpers,
                "external_semantic_support_slices": external_support,
                "request_contract": request_support,
                "literal_inertia_page_callsites": literal_pages,
                "literal_inertia_page_callsite_count": 0,
                "route_ownership_credit": False,
                "controller_action_bridge_credit": False,
                "page_ownership_credit": False,
            },
            "frontend_caller_census_keys": caller_keys,
            "feature_identity_projection": BASE.feature_projection(matrix_by_id[feature_id]),
            "collision_checks": {
                "previous_review_source_collision": False,
                "current_owner_source_collision": False,
                "existing_controller_action_bridge_collision": False,
            },
            "fresh_review_state": {
                "status": "PENDING",
                "allowed_outcomes": [
                    "OWNER_ROUTE_ACTION", "SHARED_RELATION", "ALIAS_OR_REDIRECT",
                    "DEAD_OR_NONCANONICAL", "EVIDENCE_GAP",
                ],
                "route_ownership_credit": False,
                "controller_action_bridge_credit": False,
                "page_ownership_credit": False,
            },
            "evidence_digests": {
                "queue_record_sha256": queue_row["queue_record_sha256"],
                "route_manifest_record_sha256": canonical_json_sha256(route_manifest),
                "route_candidate_record_sha256": canonical_json_sha256(route_candidate),
                "route_decision_sha256": canonical_json_sha256(route_decision),
                "primary_method_slice_sha256": primary_slice["review_slice"]["text_sha256"],
                "local_support_sha256": canonical_json_sha256(local_helpers),
                "external_support_sha256": canonical_json_sha256(external_support),
            },
        }
        record["candidate_record_sha256"] = canonical_json_sha256(record)
        records.append(record)

    assert len(records) == 24
    assert len({row["queue_id"] for row in records}) == 24
    assert len({row["route_source"]["route_record_id"] for row in records}) == 24
    assert len({row["action_key"] for row in records}) == 24
    assert Counter(row["review_partition"] for row in records) == {"A": 8, "B": 8, "C": 8}
    assert Counter(row["run090_original_partition"] for row in records) == {"A": 7, "B": 12, "C": 5}
    feature_ids = {row["candidate_feature_id"] for row in records}
    new_feature_ids = feature_ids - current_owner_features
    assert len(feature_ids) == 6
    assert new_feature_ids == {
        "CAP-CATER-DIETARY-TAG-LIBRARY",
        "CAP-CATER-PRODUCT-CATALOG",
        "CAP-CATER-RECIPE-LIBRARY",
    }

    assert canonical_list_sha256([str(row[0]) for row in SELECTED]) == "8390e6f85767607c95877380f5a4812a9cd880b8a958f291bd212ae84195d2ab"
    assert canonical_list_sha256([row["queue_id"] for row in records]) == "0da0fb01d8c00e325ad8378c20a32f5b98c9c68556749f1b48f5df073feea993"
    assert canonical_list_sha256([row["queue_canonical_key"] for row in records]) == "861db996872ae7d2f1b523cba985ca6a60aa7dc960659a0a043d54f22e233f53"
    assert canonical_list_sha256([row["route_source"]["source_key"] for row in records]) == "671a917c1197c308f49c995cdbd7b6c30dbf50a6eda9c20cef1077f8a109f34c"
    assert canonical_list_sha256([row["route_source"]["route_record_id"] for row in records]) == "a25be8fe6fe75848603b3ee8cd874a9de5a0306e4522fc6c7a25a407ec1d5a65"
    assert canonical_list_sha256(feature_ids) == "0b6fe205e1225dec02f65c88e26e3837fc29f9ffc08ef763c5832774fccbda5e"
    assert canonical_list_sha256([row["action_key"] for row in records]) == "0de943e094a024c397886c0026b8a6a4638908808a75b265753f027084219e18"

    partitions = {}
    expected_partition_hashes = {
        "A": "5b948257fc4d82b22a1001d0b3cfc15a640790eeb7d1a8e83e59b5a8f4c27fe8",
        "B": "751308453cb710379493372976afd4b02e62170a9c54ea8280f71e04cd1b8165",
        "C": "642721ffb787a28cc72c1662723050cc1babb25ff762b01ccf9203382f6aa2e6",
    }
    for partition in ("A", "B", "C"):
        assigned = [row for row in records if row["review_partition"] == partition]
        action_hash = canonical_list_sha256([row["action_key"] for row in assigned])
        assert action_hash == expected_partition_hashes[partition]
        partitions[partition] = {
            "assigned_candidates": 8,
            "candidate_ids": [row["candidate_id"] for row in assigned],
            "action_key_list_sha256": action_hash,
            "fresh_reviewer_required": True,
        }

    return {
        "schema_version": "run-101-outcome-neutral-route-action-cohort-wave-13-v1",
        "run_id": "RUN-101-OUTCOME-NEUTRAL-ROUTE-ACTION-COHORT-WAVE-13",
        "status": "TWENTY_FOUR_EXACT_ROUTE_ACTION_CANDIDATES_PENDING_FRESH_REVIEW_ZERO_CREDIT",
        "generated_on": "2026-08-25",
        "pins": {
            "checkpoint_commit": CHECKPOINT_COMMIT,
            "checkpoint_tree": CHECKPOINT_TREE,
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
            "Oblivion Findings is one operating organisation with multiple Sites. Legacy tenant_id values "
            "are not authorization boundaries. Static route/action ownership never proves approved-Site "
            "reach, permissions, direct-object concealment, privacy, lifecycle correctness, runtime, or release."
        ),
        "selection_contract": {
            "outcome_neutral": True,
            "candidate_owner_projection_authorized": False,
            "rule": (
                "Freeze the exact 24-row module slice from the 472 pending RUN-090 surfaces only after "
                "singleton route-name and exact controller-method convergence, prior-review exclusion, "
                "current-owner exclusion, action-bridge collision checks, and zero literal-page evidence."
            ),
            "disputed_recipe_edit_rule": (
                "The JSON branch and exact caller/page-graph context are evidence for fresh review. "
                "Absence of a live caller is not by itself alias/dead proof; no outcome is preselected."
            ),
            "it_dynamic_delegation_rule": (
                "Each wrapper is paired with lifecycleAction, the literal resolved lifecycle service method, "
                "and material request-contract loci. Any unresolved dynamic call becomes EVIDENCE_GAP."
            ),
            "prohibited_inheritance": [
                "route prefix", "adjacency", "controller containment", "method name alone",
                "legacy page path", "page ownership", "middleware", "navigation", "runtime",
            ],
        },
        "counts": {
            "candidate_route_actions": 24,
            "candidate_route_records": 24,
            "candidate_controller_action_bridges": 24,
            "candidate_page_records": 0,
            "distinct_feature_ids": 6,
            "distinct_feature_ids_not_in_current_owner_set": 3,
            "queue_pending_before": 472,
            "selected_pending_queue_surfaces": 24,
            "queue_unselected_pending": 448,
            "mechanically_eligible_identical_singleton_routes_before_selection": 149,
            "ownership_credit_awarded": 0,
            "page_ownership_credit_awarded": 0,
            "runtime_credit": 0,
            "application_browser_credit": 0,
            "executed_test_credit": 0,
            "benchmark_credit": 0,
            "pass_credit": 0,
            "completion_credit": 0,
        },
        "identity": {
            "queue_index_list_sha256": canonical_list_sha256([str(row[0]) for row in SELECTED]),
            "queue_id_list_sha256": canonical_list_sha256([row["queue_id"] for row in records]),
            "canonical_key_list_sha256": canonical_list_sha256([row["queue_canonical_key"] for row in records]),
            "source_key_list_sha256": canonical_list_sha256([row["route_source"]["source_key"] for row in records]),
            "route_record_id_list_sha256": canonical_list_sha256([row["route_source"]["route_record_id"] for row in records]),
            "feature_id_list_sha256": canonical_list_sha256(feature_ids),
            "new_feature_id_list_sha256": canonical_list_sha256(new_feature_ids),
            "action_key_list_sha256": canonical_list_sha256([row["action_key"] for row in records]),
            "candidate_record_sha256_list_sha256": canonical_list_sha256([row["candidate_record_sha256"] for row in records]),
            "records_sha256": canonical_json_sha256(records),
        },
        "frontend_caller_census": frontend_census,
        "review_partitions": partitions,
        "records": records,
        "fresh_review_contract": {
            "status": "PENDING",
            "required_reviews": 3,
            "reviewers_must_be_fresh_from_discovery_agents": True,
            "required_outcome_per_candidate": True,
            "allowed_outcomes": [
                "OWNER_ROUTE_ACTION", "SHARED_RELATION", "ALIAS_OR_REDIRECT",
                "DEAD_OR_NONCANONICAL", "EVIDENCE_GAP",
            ],
            "integration_rule": (
                "Only explicit OWNER_ROUTE_ACTION may add one route owner and one action bridge. "
                "Every other outcome adds neither; conflicts become EVIDENCE_GAP."
            ),
            "page_owner_records_authorized": 0,
            "ownership_integration_authorized": False,
        },
        "outcome_neutral_conservation_contract": {
            "equation": "O + S + A + D + E = 24",
            "bounded_sources": "3929 = (571 + O) + (3358 - O)",
            "owner_surfaces": "571 + O = (244 + O) routes + 327 pages",
            "queue": "507 = 59 reviewed + 448 pending",
            "queue_reviewed": "59 = (33 + O) owner + (2 + S) shared + A alias + D dead + E gap",
            "queue_without_ownership": "474 - O = 448 + (2 + S) + A + D + E",
            "route_universe": (
                "3218 = (244 + O) owner + (5 + S) shared + A alias + D dead + "
                "(2969 - O - S - A - D) residual; E is a tagged subset of residual"
            ),
            "pages": "711 = 327 owned + 382 unadjudicated + 2 shared",
            "controller_action_bridges": "32 + O",
            "projection_credit_awarded": False,
        },
        "denominator_boundary": {
            "run_077_bounded_static_records": 3929,
            "framework_expanded_route_page_denominator": None,
            "gate_4_complete": False,
        },
        "credit_boundary": {
            "route_action_candidate_cohort": True,
            "static_route_feature_ownership": False,
            "static_controller_action_bridge": False,
            "page_ownership": False,
            "framework_route_reachability": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "privacy_correctness": False,
            "lifecycle_correctness": False,
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
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/build-outcome-neutral-route-action-cohort-wave-13.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/root-run-101-outcome-neutral-route-action-cohort-wave-13.json",
        ],
    }


def main() -> None:
    payload = build()
    encoded = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    if not OUTPUT_PATH.exists() or OUTPUT_PATH.read_bytes() != encoded:
        OUTPUT_PATH.write_bytes(encoded)
    print(json.dumps({
        "status": payload["status"],
        "output": OUTPUT_PATH.relative_to(REPO).as_posix(),
        "sha256": sha256_file(OUTPUT_PATH),
        "candidate_route_actions": payload["counts"]["candidate_route_actions"],
        "review_partitions": {key: value["assigned_candidates"] for key, value in payload["review_partitions"].items()},
        "ownership_credit_awarded": payload["counts"]["ownership_credit_awarded"],
    }, indent=2))


if __name__ == "__main__":
    main()
