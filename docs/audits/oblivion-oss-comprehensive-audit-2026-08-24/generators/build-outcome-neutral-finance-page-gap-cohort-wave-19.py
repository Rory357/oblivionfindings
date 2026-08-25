#!/usr/bin/env python3
"""Freeze RUN-125's four unfinished Finance page roots for fresh review.

RUN-121/R exposed six literal Finance page callsites but explicitly granted no
page ownership. Two roots were already owned; this producer freezes exactly the
four remaining roots. Parent route decisions are provenance only. In
particular, the journals/Create root is reviewed against the dedicated Manual
Journal lifecycle rather than inheriting RUN-121's stale Chart-of-Accounts
route-name projection.
"""

from __future__ import annotations

import csv
import hashlib
import json
import subprocess
from collections import Counter
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_PATH = AUDIT_DIR / "evidence/source/root-run-125-outcome-neutral-finance-page-gap-cohort-wave-19.json"
PROMPT_PATH = Path(r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md")

CHECKPOINT_COMMIT = "a6c14f0f8354df409b4695c4cd3cb7bcf416f8ba"
CHECKPOINT_TREE = "8e9be24776dd76d0cbc185bfa145f209215ee351"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"

INPUT_SHA256 = {
    "03-feature-to-benchmark-matrix.csv": "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390",
    "evidence/source/root-run-077-route-page-universe-manifest-wave-07.json": "150fcff9b100ad85a7a2e998ed69c8dafdc2d0098e8ec2b4dbac7d3b404061be",
    "evidence/source/current-route-page-classification-wave-07.json": "7b534fea15e6c4ec98fbb2cb0c761e26116b2abee500c39e599b0e779729df97",
    "evidence/source/root-run-082-exact-owner-containment-candidate-manifest-wave-08.json": "3c6ad4df13a09ae8ff8f19aee09a1907f9754a1877806659676c6c2898652f85",
    "evidence/source/root-run-084-full-inertia-page-graph-wave-09.json": "f3856a7a86cd236684e223713a99dd64b18df692338e5d7aba688701b7c438f9",
    "evidence/source/raw-run-084r-independent-full-inertia-page-graph-review-wave-09.json": "036394a207f6f31c336f748bae9daed75d86549529de538510374149d56f506e",
    "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json": "bfec52a9bed7176045457e8920bdc7b65e1c1d5c5eeb28a266f49c12acf69bcf",
    "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json": "5390e55d9e47d1845afa8b3848bdab7687747cbb62c946dd4d66d3c435e8de0b",
    "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json": "7f76c9cfeae906a64c92013fbf9a12cf180d39a315bfa13e778b6332365cdd2a",
    "evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json": "cf68900351832c790ec53b4996daaf640c604c498f902c85dec681113bf492dd",
    "evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json": "9c1600d2365c3527e185f313b7cb1586705f39cefb15d5d212dc708b45b630ea",
    "evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json": "9375fb374b589c5068334795fa03d80a7ba1fd35695808f99c9e2591e886efca",
    "evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json": "cbeb46be682ca0c9ca54012e2c27cf548b54e9cbb75b050fcd61691ca43aaef2",
    "evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json": "33dd2f8ffa2b35c6651a6bf18923872e70ba5c2f91fce8b7222666bb8a91fc8b",
    "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json": "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    "evidence/source/root-run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18.json": "cfe0e3635e5e86bf8e7e2f65d2094743738bfa5edc36e361ecf5eb14986f316e",
    "evidence/source/raw-run-121r-independent-outcome-neutral-finance-chart-route-action-review-wave-18.json": "f70ddd2ddc7ac0c734f4b48bdd19cd2733c3572d038b1dfa1aa185591e567e5f",
    "evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json": "d7aee21e7c4230b44707a22b7fa93478a84e9a5b4775ecd25aaffede764855ca",
    "evidence/source/raw-run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json": "2130e3801b6ac163580bc56f23d6647136c83fdadc8ea65804b1559d36b29484",
    "evidence/source/current-run-123-reviewed-finance-chart-route-action-reporting-wave-18.json": "ffa7c751fb6a87ed358f015d13a28f10a7e5404f3a9569c40dee1e74e25e98b2",
    "evidence/browser/current-audit-dashboard-verification-run-124-wave-18.json": "9eedea2c5d051693a3657614c2f4ce4a5d7afca03aa7e0330dfe254b714b0283",
}

OVERLAY_INPUTS = [
    key for key in INPUT_SHA256
    if "ownership-overlay" in key and "raw-run" not in key
]

# parent candidate, partition, page id, page file, render anchor, semantic feature
SELECTED = (
    ("RUN121-FINANCE-CHART-ROUTE-ACTION-03", "A", "PAGE-ROOT-549F787589B2456E", "resources/js/pages/finance/accounts/Create.tsx", "app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:104", "CAP-FIN-CHART-OF-ACCOUNTS"),
    ("RUN121-FINANCE-CHART-ROUTE-ACTION-05", "B", "PAGE-ROOT-ACDB817C5F423914", "resources/js/pages/finance/accounts/Show.tsx", "app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:149", "CAP-FIN-CHART-OF-ACCOUNTS"),
    ("RUN121-FINANCE-CHART-ROUTE-ACTION-06", "B", "PAGE-ROOT-AD9F857768AC0512", "resources/js/pages/finance/accounts/Edit.tsx", "app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:192", "CAP-FIN-CHART-OF-ACCOUNTS"),
    ("RUN121-FINANCE-CHART-ROUTE-ACTION-09", "C", "PAGE-ROOT-467C1A36E6378EE3", "resources/js/pages/finance/journals/Create.tsx", "app/Domain/Finance/Http/Controllers/JournalController.php:197", "CAP-FIN-MANUAL-JOURNAL-LIFECYCLE"),
)

EXPECTED_IDENTITY = {
    "page_record_id_list_sha256": "7736ff160732728ccca2ff900b181c13ef631dc19c605984848ee3a56e11c75b",
    "page_file_list_sha256": "a8ac99a97f1b156e69ac0f141d81b5bd6487d4f296b4277eafe46229b28168f2",
    "page_record_id_file_pair_list_sha256": "db44435fc97bd9e950a8f06d3f49ab6d0e967fdd01fee29f7bfa769129d74da8",
    "render_anchor_list_sha256": "ffe44d1d3fc25d3bcb7501818a5d0cebe3cb0e0e3de8cfb1424c8e29eb0e37b2",
    "parent_candidate_id_list_sha256": "84b9d3db82af0fc132562359e2d58f60ba1e287b8615244b63d5a856adf5533b",
    "parent_decision_record_sha256_list_sha256": "940ae89aa5f69d7062bc9064a6d69b8b82adc3354be40ceeafd3250739ca513d",
    "page_feature_key_list_sha256": "4479c933cc3608994b660ccbf3d6f55de094a855a3dbf6e6ed5b1845f5697c8a",
    "candidate_record_sha256_list_sha256": "53fe8b95bd3ad0a05acadb64a4d8105c701ab892c17eb9a83d20e69a0cf5aa73",
    "records_sha256": "323e800d57520b3525635eba41f0ad61b1cef5e7080c896d871e2c8dd73d997f",
}


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def canonical_json_sha256(value: Any) -> str:
    encoded = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(encoded).hexdigest()


def canonical_list_sha256(values: list[str]) -> str:
    return canonical_json_sha256(values)


def load(relative: str) -> dict[str, Any]:
    return json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))


def git(*args: str) -> str:
    return subprocess.run(["git", *args], cwd=REPO, check=True, capture_output=True, text=True, encoding="utf-8").stdout.strip()


def index_unique(rows: list[dict[str, Any]], key: str) -> dict[str, dict[str, Any]]:
    result = {row[key]: row for row in rows}
    assert len(result) == len(rows), key
    return result


def feature_projection(row: dict[str, str]) -> dict[str, str]:
    return {key: row[key] for key in (
        "feature_id", "feature_class", "module", "user_job", "route_names",
        "route_paths", "page_files", "backend_anchors", "feature_identity_status",
    )}


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
    for relative, digest in INPUT_SHA256.items():
        target = AUDIT_DIR / relative
        assert target.is_file(), target
        assert sha256_file(target) == digest, relative


def build() -> dict[str, Any]:
    assert_workspace_and_inputs()
    with (AUDIT_DIR / "03-feature-to-benchmark-matrix.csv").open("r", encoding="utf-8-sig", newline="") as handle:
        matrix_rows = list(csv.DictReader(handle))
    assert len(matrix_rows) == 340
    matrix_by_id = index_unique(matrix_rows, "feature_id")

    manifest = load("evidence/source/root-run-077-route-page-universe-manifest-wave-07.json")
    classification = load("evidence/source/current-route-page-classification-wave-07.json")
    candidate_manifest = load("evidence/source/root-run-082-exact-owner-containment-candidate-manifest-wave-08.json")
    graph = load("evidence/source/root-run-084-full-inertia-page-graph-wave-09.json")
    graph_review = load("evidence/source/raw-run-084r-independent-full-inertia-page-graph-review-wave-09.json")
    ownership = load("evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json")
    queue = load("evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json")
    parent_cohort = load("evidence/source/root-run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18.json")
    parent_review = load("evidence/source/raw-run-121r-independent-outcome-neutral-finance-chart-route-action-review-wave-18.json")
    current_overlay = load("evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json")
    overlay_review = load("evidence/source/raw-run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json")
    reporting = load("evidence/source/current-run-123-reviewed-finance-chart-route-action-reporting-wave-18.json")
    dashboard = load("evidence/browser/current-audit-dashboard-verification-run-124-wave-18.json")

    assert graph_review["decision"]["verdict"] == "GO"
    assert parent_review["decision"]["verdict"].startswith("GO_")
    assert overlay_review["decision"]["verdict"] == "GO"
    assert current_overlay["combined_counts"]["source_owner_records"] == 648
    assert current_overlay["combined_counts"]["route_owner_records"] == 295
    assert current_overlay["combined_counts"]["page_owner_records"] == 353
    assert current_overlay["combined_counts"]["bounded_static_source_residual_records"] == 3281
    assert current_overlay["combined_counts"]["residual_unadjudicated_page_roots"] == 349
    assert current_overlay["combined_counts"]["semantic_shared_page_roots"] == 9
    assert current_overlay["combined_counts"]["evidence_gap_page_roots_tagged_within_residual"] == 1
    assert current_overlay["queue_accounting"]["reviewed_queue_surface_rows"] == 106
    assert current_overlay["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 401
    assert reporting["status"] == "REVIEWED_FINANCE_CHART_ROUTE_ACTION_OVERLAY_REPORTED_GATE_4_INCOMPLETE"
    assert dashboard["verification"]["state"] == "GO"
    assert dashboard["audit_completion_test_met"] is False

    page_manifest_by_id = index_unique(list(manifest["page_universe"]["page_roots"]), "page_record_id")
    page_decision_by_id = index_unique(list(classification["page_decisions"]), "page_record_id")
    page_candidate_by_id = index_unique(list(candidate_manifest["page_static_candidate_census"]["records"]), "page_record_id")
    page_graph_by_id = index_unique([row for row in graph["records"] if row.get("page_root_id")], "page_root_id")
    parent_by_id = index_unique(list(parent_cohort["records"]), "candidate_id")
    parent_decision_by_id = index_unique(list(parent_review["action_decisions"]), "candidate_id")

    current_owner_rows = list(ownership["records"])
    for relative in OVERLAY_INPUTS:
        current_owner_rows.extend(load(relative)["overlay_source_records"])
    current_owner_ids = {row["source_record_id"] for row in current_owner_rows}
    current_owner_keys = {row["source_record_key"] for row in current_owner_rows}
    current_features = {row["feature_id"] for row in current_owner_rows}
    current_page_features = {row["feature_id"] for row in current_owner_rows if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"}
    assert len(current_owner_rows) == len(current_owner_ids) == len(current_owner_keys) == 648
    assert {"CAP-FIN-CHART-OF-ACCOUNTS", "CAP-FIN-MANUAL-JOURNAL-LIFECYCLE"} <= current_features
    assert {"CAP-FIN-CHART-OF-ACCOUNTS", "CAP-FIN-MANUAL-JOURNAL-LIFECYCLE"} <= current_page_features
    queue_source_ids = {row["source_record_id"] for row in queue["records"]}

    expected_parent_outcomes = {
        "RUN121-FINANCE-CHART-ROUTE-ACTION-03": "OWNER_ROUTE_ACTION",
        "RUN121-FINANCE-CHART-ROUTE-ACTION-05": "OWNER_ROUTE_ACTION",
        "RUN121-FINANCE-CHART-ROUTE-ACTION-06": "OWNER_ROUTE_ACTION",
        "RUN121-FINANCE-CHART-ROUTE-ACTION-09": "EVIDENCE_GAP",
    }
    records: list[dict[str, Any]] = []
    for sequence, (parent_id, partition, page_id, page_file, anchor, feature_id) in enumerate(SELECTED, 1):
        parent = parent_by_id[parent_id]
        parent_decision = parent_decision_by_id[parent_id]
        page_manifest = page_manifest_by_id[page_id]
        page_decision = page_decision_by_id[page_id]
        page_candidate = page_candidate_by_id[page_id]
        graph_row = page_graph_by_id[page_id]
        projection = feature_projection(matrix_by_id[feature_id])

        contexts = [row for row in parent["controller_action"]["literal_inertia_page_callsites"] if row["page_record_id"] == page_id]
        assert len(contexts) == 1
        context = contexts[0]
        assert context["page_file"] == page_file
        assert context["source_anchor"] == anchor
        assert context["current_static_source_owner"] is False
        assert context["page_ownership_credit_from_this_cohort"] is False
        assert context["current_page_status"] == "UNOWNED_CONTEXT_REQUIRES_SEPARATE_PAGE_REVIEW"
        assert parent_decision["outcome"] == expected_parent_outcomes[parent_id]
        assert parent_decision["page_ownership_authorized"] is False

        assert page_id not in current_owner_ids
        assert page_id not in queue_source_ids
        assert page_manifest["page_file"] == page_candidate["page_file"] == graph_row["path"] == page_file
        assert sha256_file(REPO / page_file) == page_manifest["page_file_sha256"] == graph_row["sha256"]
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{page_file}") == page_manifest["page_file_blob_id"] == graph_row["blob_id"]
        assert page_decision["prompt_classification"] == "Evidence gap"
        assert page_decision["reviewed_feature_ids"] == []
        assert page_candidate["relation_comparison"] == "NO_CANDIDATE_EITHER_LANE"
        assert page_candidate["candidate_union_feature_ids"] == []
        assert graph_row["partition"] == "LITERAL_RENDERED_PAGE_ROOT"
        assert graph_row["prompt_classification"] == "Evidence gap"
        callsites = [row for row in page_manifest["render_callsites"] if row["source_anchor"] == anchor]
        assert len(callsites) == 1
        callsite = callsites[0]
        primary = parent["controller_action"]["primary_method_slice"]
        assert callsite["source_file"] == primary["source_file"]
        assert primary["review_slice"]["start_line"] <= callsite["source_line"] <= primary["review_slice"]["end_line"]
        assert sha256_file(REPO / primary["source_file"]) == primary["source_file_sha256"]
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{primary['source_file']}") == primary["source_file_blob_id"]

        evidence = {
            "page_manifest_sha256": canonical_json_sha256(page_manifest),
            "page_decision_sha256": canonical_json_sha256(page_decision),
            "zero_candidate_record_sha256": canonical_json_sha256(page_candidate),
            "page_graph_sha256": canonical_json_sha256(graph_row),
            "parent_candidate_record_sha256": parent["candidate_record_sha256"],
            "parent_decision_record_sha256": parent_decision["decision_record_sha256"],
            "parent_method_slice_sha256": primary["review_slice"]["text_sha256"],
            "feature_projection_sha256": canonical_json_sha256(projection),
        }
        record: dict[str, Any] = {
            "candidate_id": f"RUN125-FINANCE-PAGE-GAP-{sequence:02d}",
            "page_feature_key": f"{page_id}|{feature_id}",
            "review_partition": partition,
            "candidate_feature_id": feature_id,
            "page_source": {
                "page_record_id": page_id,
                "page_file": page_file,
                "page_file_sha256": page_manifest["page_file_sha256"],
                "page_file_blob_id": page_manifest["page_file_blob_id"],
                "page_line_count": len((REPO / page_file).read_text(encoding="utf-8").splitlines()),
                "render_names": page_manifest["render_names"],
                "render_call_count": page_manifest["render_call_count"],
                "run079_prompt_classification": "Evidence gap",
                "run082_relation_comparison": "NO_CANDIDATE_EITHER_LANE",
                "run082_candidate_feature_ids": [],
            },
            "reviewed_parent_action_provenance": {
                "parent_candidate_id": parent_id,
                "queue_id": parent["queue_id"],
                "route_record_id": parent["route_source"]["route_record_id"],
                "literal_route_name": parent["route_source"]["literal_route_name"],
                "parent_projected_feature_id": parent["candidate_feature_id"],
                "parent_outcome": parent_decision["outcome"],
                "selected_render_callsite": callsite,
                "controller_file": primary["source_file"],
                "controller_file_sha256": primary["source_file_sha256"],
                "controller_file_blob_id": primary["source_file_blob_id"],
                "method": primary["method"],
                "definition_line": primary["definition_line"],
                "definition_anchor": primary["definition_anchor"],
                "method_review_slice": primary["review_slice"],
                "request_contracts": parent["controller_action"]["request_contracts"],
                "page_ownership_inheritance_prohibited": True,
                "parent_route_outcome_may_determine_page_outcome": False,
                "semantic_feature_differs_from_parent_projection": feature_id != parent["candidate_feature_id"],
            },
            "page_graph_context": {
                "partition": graph_row["partition"],
                "prompt_classification": graph_row["prompt_classification"],
                "root_source_anchors": graph_row["root_source_anchors"],
                "production_direct_value_import_count": graph_row["production_direct_value_import_count"],
                "production_direct_value_imports": graph_row["production_direct_value_imports"],
                "transitive_rendered_root_count": graph_row["transitive_rendered_root_count"],
                "transitive_rendered_root_paths": graph_row["transitive_rendered_root_paths"],
                "feature_mapping_credit": False,
                "framework_reachability": "NOT_EXECUTED",
                "build_resolution": "NOT_EXECUTED",
                "browser_observation": "NOT_EXECUTED",
            },
            "feature_identity_projection": projection,
            "collision_checks": {
                "current_page_owner_collision": False,
                "direct_queue_overlap": False,
                "page_candidate_lane_convergence": False,
                "source_record_key_collision": f"page|{page_id}|{feature_id}" in current_owner_keys,
            },
            "fresh_review_state": {
                "status": "PENDING",
                "allowed_outcomes": ["OWNER_PAGE", "SHARED_RELATION", "ALIAS_OR_REDIRECT", "DEAD_OR_NONCANONICAL", "EVIDENCE_GAP"],
                "complete_page_read_required": True,
                "parent_route_decision_may_determine_page_outcome": False,
                "page_ownership_credit": False,
                "route_ownership_credit": False,
                "controller_action_bridge_credit": False,
            },
            "evidence_digests": evidence,
        }
        assert record["collision_checks"]["source_record_key_collision"] is False
        record["evidence_digests"]["joined_candidate_evidence_sha256"] = canonical_json_sha256(evidence)
        record["candidate_record_sha256"] = canonical_json_sha256(record)
        records.append(record)

    assert len(records) == 4
    assert Counter(row["candidate_feature_id"] for row in records) == {
        "CAP-FIN-CHART-OF-ACCOUNTS": 3,
        "CAP-FIN-MANUAL-JOURNAL-LIFECYCLE": 1,
    }
    partitions = {}
    for partition in ("A", "B", "C"):
        assigned = [row for row in records if row["review_partition"] == partition]
        partitions[partition] = {
            "assigned_candidates": len(assigned),
            "candidate_ids": [row["candidate_id"] for row in assigned],
            "page_feature_key_list_sha256": canonical_list_sha256([row["page_feature_key"] for row in assigned]),
            "fresh_reviewer_required": True,
        }
    identity = {
        "page_record_id_list_sha256": canonical_list_sha256([row["page_source"]["page_record_id"] for row in records]),
        "page_file_list_sha256": canonical_list_sha256([row["page_source"]["page_file"] for row in records]),
        "page_record_id_file_pair_list_sha256": canonical_list_sha256([f"{row['page_source']['page_record_id']}|{row['page_source']['page_file']}" for row in records]),
        "render_anchor_list_sha256": canonical_list_sha256([row["reviewed_parent_action_provenance"]["selected_render_callsite"]["source_anchor"] for row in records]),
        "parent_candidate_id_list_sha256": canonical_list_sha256([row["reviewed_parent_action_provenance"]["parent_candidate_id"] for row in records]),
        "parent_decision_record_sha256_list_sha256": canonical_list_sha256([row["evidence_digests"]["parent_decision_record_sha256"] for row in records]),
        "page_feature_key_list_sha256": canonical_list_sha256([row["page_feature_key"] for row in records]),
        "candidate_record_sha256_list_sha256": canonical_list_sha256([row["candidate_record_sha256"] for row in records]),
        "records_sha256": canonical_json_sha256(records),
    }
    if EXPECTED_IDENTITY:
        assert identity == EXPECTED_IDENTITY

    return {
        "schema_version": "run-125-outcome-neutral-finance-page-gap-cohort-wave-19-v1",
        "run_id": "RUN-125-OUTCOME-NEUTRAL-FINANCE-PAGE-GAP-COHORT-WAVE-19",
        "status": "FOUR_FINANCE_PAGE_GAPS_PENDING_FRESH_REVIEW_ZERO_CREDIT",
        "generated_on": "2026-08-26",
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
            "inputs": INPUT_SHA256,
        },
        "architecture_rule": "Oblivion Findings is one operating organisation across multiple Sites. Roles, permissions, approved Sites, canonical record ownership, direct-object concealment, privacy, and ledger integrity are the boundaries. Legacy organisation context is not a tenant boundary.",
        "selection_contract": {
            "outcome_neutral": True,
            "candidate_owner_projection_authorized": False,
            "rule": "Freeze exactly the four RUN-121 literal Finance page callsites that remained unowned after RUN-122/R and RUN-123/RUN-124 reporting verification.",
            "unfinished_boundary_rule": "Complete these four page roots before selecting another RUN-090 route cohort.",
            "no_exact_candidate_rule": "All four roots remain NO_CANDIDATE_EITHER_LANE in RUN-082; exact semantic review may resolve ownership but selection itself proves no outcome.",
            "parent_provenance_rule": "Parent route review identifies a literal renderer only. Neither an accepted route owner nor an evidence-gap parent may determine the page outcome.",
            "semantic_repair_rule": "journals/Create must be reviewed against CAP-FIN-MANUAL-JOURNAL-LIFECYCLE; RUN-121's Chart-of-Accounts name projection is documented conflicting context, not inheritable identity.",
            "prohibited_inheritance": ["parent route outcome", "parent action bridge", "literal route name", "directory containment", "component name", "route prefix", "page presence", "navigation", "framework reachability"],
        },
        "independent_preflight_review": {
            "task_paths": ["/root/run125_accounts_create", "/root/run125_accounts_show_edit", "/root/run125_journal_reporting"],
            "review_type": "READ_ONLY_EXACT_PAGE_SOURCE_SEMANTIC_AUTHORIZATION_AND_REPORTING_PREFLIGHT",
            "verdict": "GO_FREEZE_FOUR_UNFINISHED_FINANCE_PAGE_GAPS_ZERO_OWNERSHIP_CREDIT",
            "wrote_files": False,
            "confirmed_page_roots": 4,
            "confirmed_current_owner_collisions": 0,
            "confirmed_direct_queue_overlaps": 0,
        },
        "counts": {
            "candidate_page_records": 4,
            "candidate_route_records": 0,
            "candidate_controller_action_bridges": 0,
            "distinct_feature_ids": 2,
            "distinct_feature_ids_not_in_current_owner_set": 0,
            "distinct_feature_ids_not_in_current_page_owner_set": 0,
            "no_candidate_either_lane_pages": 4,
            "accepted_parent_route_actions": 3,
            "evidence_gap_parent_route_actions": 1,
            "literal_render_callsites": 4,
            "current_page_owner_collisions": 0,
            "direct_queue_overlaps": 0,
            "baseline_source_owner_records": 648,
            "baseline_route_owner_records": 295,
            "baseline_page_owner_records": 353,
            "baseline_shared_page_roots": 9,
            "baseline_residual_page_roots": 349,
            "baseline_bounded_static_source_residual_records": 3281,
            "direct_exact_queue_records": 507,
            "direct_exact_queue_reviewed": 106,
            "direct_exact_queue_pending": 401,
            "direct_exact_queue_without_ownership": 423,
            "ownership_credit_awarded": 0,
            "route_ownership_credit_awarded": 0,
            "controller_action_bridge_credit_awarded": 0,
            "runtime_credit": 0,
            "application_browser_credit": 0,
            "executed_test_credit": 0,
            "benchmark_credit": 0,
            "pass_credit": 0,
            "completion_credit": 0,
        },
        "identity": identity,
        "review_partitions": partitions,
        "records": records,
        "fresh_review_contract": {
            "status": "PENDING",
            "required_reviews": 3,
            "reviewers_must_be_fresh_from_discovery_agents": True,
            "complete_page_read_required": True,
            "required_outcome_per_candidate": True,
            "allowed_outcomes": ["OWNER_PAGE", "SHARED_RELATION", "ALIAS_OR_REDIRECT", "DEAD_OR_NONCANONICAL", "EVIDENCE_GAP"],
            "integration_rule": "Only explicit OWNER_PAGE adds one page owner. Shared, alias, dead, and evidence-gap outcomes remain explicit non-owner classes. Any reviewer conflict becomes EVIDENCE_GAP.",
            "route_owner_records_authorized": 0,
            "controller_action_bridges_authorized": 0,
            "ownership_integration_authorized": False,
        },
        "outcome_neutral_conservation_contract": {
            "equation": "O + S + A + D + E = 4",
            "bounded_sources": "3929 = (648 + O) + (3281 - O)",
            "owner_surfaces": "648 + O = 295 routes + (353 + O) pages",
            "pages": "711 = (353 + O) owner + (9 + S) shared + A alias + D dead + (349 - O - S - A - D) residual; the existing one gap and E remain tagged residual subsets",
            "routes": "3218 = 295 owners + 12 shared + 5 aliases + 2906 residual; seven route evidence gaps remain tagged within residual",
            "controller_action_bridges": "83 unchanged",
            "direct_exact_queue": "507 = 106 reviewed + 401 pending; 423 remain without ownership because all four page roots are outside RUN-090",
            "distinct_feature_ids": "256 regardless of O because both candidate FEATURE-IDs already occur globally and in the page-owner set",
            "projection_credit_awarded": False,
        },
        "denominator_boundary": {"run_077_bounded_static_records": 3929, "framework_expanded_route_page_denominator": None, "gate_4_complete": False},
        "credit_boundary": {
            "page_gap_candidate_cohort": True,
            "static_page_feature_ownership": False,
            "static_route_feature_ownership": False,
            "static_controller_action_bridge": False,
            "framework_route_reachability": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "direct_object_concealment": False,
            "privacy_correctness": False,
            "ledger_or_lifecycle_correctness": False,
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
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/build-outcome-neutral-finance-page-gap-cohort-wave-19.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/root-run-125-outcome-neutral-finance-page-gap-cohort-wave-19.json",
        ],
    }


def main() -> None:
    payload = build()
    encoded = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    if not OUTPUT_PATH.exists() or OUTPUT_PATH.read_bytes() != encoded:
        OUTPUT_PATH.write_bytes(encoded)
    print(json.dumps({
        "status": payload["status"],
        "output": OUTPUT_PATH.relative_to(AUDIT_DIR).as_posix(),
        "sha256": sha256_file(OUTPUT_PATH),
        "candidate_pages": payload["counts"]["candidate_page_records"],
        "review_partitions": {key: value["assigned_candidates"] for key, value in payload["review_partitions"].items()},
        "identity": payload["identity"],
        "page_ownership_credit_awarded": payload["counts"]["ownership_credit_awarded"],
    }, indent=2))


if __name__ == "__main__":
    main()
