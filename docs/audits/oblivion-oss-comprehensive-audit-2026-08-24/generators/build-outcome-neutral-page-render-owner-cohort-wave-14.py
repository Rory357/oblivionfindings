#!/usr/bin/env python3
"""Build the RUN-105 outcome-neutral page render-owner review cohort.

The producer freezes the first 24 still-unreviewed, non-conflicting singleton
render-owner candidates in the pinned RUN-082 page-candidate order.  Exact
render containment is only a discovery relation: every page, containing
controller method, material import context, and canonical user job still needs
fresh semantic review before any bounded page ownership may be integrated.
"""

from __future__ import annotations

import csv
import importlib.util
import json
import re
from collections import Counter
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
BASE_GENERATOR = AUDIT_DIR / "generators/build-outcome-neutral-route-action-cohort-wave-13.py"
OUTPUT_PATH = AUDIT_DIR / "evidence/source/root-run-105-outcome-neutral-page-render-owner-cohort-wave-14.json"
PROMPT_PATH = Path(r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md")

CHECKPOINT_COMMIT = "ed561394411fff4caaffd8b24290bf06bae9bd22"
CHECKPOINT_TREE = "b57fc91fb81e2a12e8e830104603a5fdf9b1546b"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"

spec = importlib.util.spec_from_file_location("run101_base", BASE_GENERATOR)
assert spec and spec.loader
BASE = importlib.util.module_from_spec(spec)
spec.loader.exec_module(BASE)

sha256_file = BASE.sha256_file
canonical_json_sha256 = BASE.canonical_json_sha256
canonical_list_sha256 = BASE.canonical_list_sha256
load_json = BASE.load_json
git = BASE.git
index_unique = BASE.index_unique

INPUT_PATHS = {
    "base_generator": BASE_GENERATOR,
    "matrix": AUDIT_DIR / "03-feature-to-benchmark-matrix.csv",
    "manifest": AUDIT_DIR / "evidence/source/root-run-077-route-page-universe-manifest-wave-07.json",
    "classification": AUDIT_DIR / "evidence/source/current-route-page-classification-wave-07.json",
    "candidate_manifest": AUDIT_DIR / "evidence/source/root-run-082-exact-owner-containment-candidate-manifest-wave-08.json",
    "candidate_review": AUDIT_DIR / "evidence/source/raw-run-082r-independent-exact-owner-containment-review-wave-08.json",
    "page_graph": AUDIT_DIR / "evidence/source/root-run-084-full-inertia-page-graph-wave-09.json",
    "page_graph_review": AUDIT_DIR / "evidence/source/raw-run-084r-independent-full-inertia-page-graph-review-wave-09.json",
    "ownership_ledger": AUDIT_DIR / "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json",
    "ownership_review": AUDIT_DIR / "evidence/source/raw-run-086r-independent-reviewed-static-route-page-feature-ownership-wave-10.json",
    "direct_queue": AUDIT_DIR / "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json",
    "run091_cohort": AUDIT_DIR / "evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json",
    "run091_review": AUDIT_DIR / "evidence/source/raw-run-091r-independent-closed-route-action-page-chain-review-wave-11.json",
    "run092_overlay": AUDIT_DIR / "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json",
    "run092_review": AUDIT_DIR / "evidence/source/raw-run-092r-independent-reviewed-static-source-ownership-overlay-wave-11.json",
    "run098_overlay": AUDIT_DIR / "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json",
    "run102_overlay": AUDIT_DIR / "evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json",
    "run102_review": AUDIT_DIR / "evidence/source/raw-run-102r-independent-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json",
    "run103_reporting": AUDIT_DIR / "evidence/source/current-run-103-reviewed-outcome-neutral-route-action-reporting-wave-13.json",
    "run104_dashboard_review": AUDIT_DIR / "evidence/browser/current-audit-dashboard-verification-run-104-wave-13.json",
}

EXPECTED_INPUT_SHA256 = {
    "base_generator": "f3ada90da486ba700d21596fb765ab10f661c343944899551006d5db5b9e7a0f",
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
    "run091_cohort": "7b1fea57104e9f4bfea7ee450ef535ac57e7fa69bc978212b4eafb0fe1ed0cae",
    "run091_review": "fb88ca666bc9f91298ab33fefa1dadbb39a4a612215fca814932f59bfc2f199b",
    "run092_overlay": "5390e55d9e47d1845afa8b3848bdab7687747cbb62c946dd4d66d3c435e8de0b",
    "run092_review": "1111d30aa24935116c37f27bead824ca1bcca7444157e456d959e821af00669a",
    "run098_overlay": "7f76c9cfeae906a64c92013fbf9a12cf180d39a315bfa13e778b6332365cdd2a",
    "run102_overlay": "cf68900351832c790ec53b4996daaf640c604c498f902c85dec681113bf492dd",
    "run102_review": "f88c3ce6ae7b82ca316c656787547bdd9e6a4cd40469b16d44a6e84f99d14902",
    "run103_reporting": "e8e0d5755dc1a3ed88f1c34a5e3d65b881f2d5a551cc40d6aa3bb605652d9bc5",
    "run104_dashboard_review": "3caf6c0970c4ea5c276b51b558d5d736c45576c503049625968e35325148009e",
}

EXPECTED_PAGE_IDS_SHA256 = "a2417deef667069b3ac51252f508a973b54ed14470a5dea69259016ebe9aae20"
EXPECTED_PAGE_PATHS_SHA256 = "9c453b6cc7303ef76523f32f2ec3f18828f5e0a9e0198109b129a969d09f5192"
EXPECTED_FEATURE_IDS_SHA256 = "87364e111d2b2d4d117c1202b6e8a667aefe7287511b8119ef2428992efb3f0c"
EXPECTED_RENDER_ANCHORS_SHA256 = "eb6bba04cc4d01c13f072007f1ad83a9ddd2a8aa03518252d0e02a2b28638ad4"
EXPECTED_CONFLICTING_SINGLETON_IDS = {
    "PAGE-ROOT-52909034DDC0736A",
    "PAGE-ROOT-5C1B7A69CC59D2A7",
}
EXPECTED_NEW_FEATURE_IDS = {
    "CAP-FLEET-REPORTING-EXPORT",
    "CAP-GOV-ACTION-ITEM-WORKFLOW",
    "CAP-HR-CANDIDATE-APPLICATION-LIFECYCLE",
    "CAP-IT-SELF-SERVICE-TICKET",
    "CAP-MED-PHARMACY-ACTIONS",
    "CAP-OPS-ROSTER-PLANNING-WORKSPACE",
    "CAP-RESP-STAY-LIFECYCLE",
    "CAP-SITE-REPORTING-EXPORT",
}

DELEGATED_CALL_RE = re.compile(
    r"\$this->([A-Za-z_][A-Za-z0-9_]*)->([A-Za-z_][A-Za-z0-9_]*)\s*\("
)


def containing_method_slice(source_file: str, source_line: int) -> dict[str, Any]:
    definitions = BASE.method_definitions(source_file)
    candidates = [(line, name) for name, line in definitions.items() if line <= source_line]
    assert candidates, (source_file, source_line)
    definition_line, method = max(candidates)
    semantic = BASE.semantic_slice(source_file, method)
    review_slice = semantic["review_slice"]
    assert review_slice["start_line"] <= source_line <= review_slice["end_line"]
    return semantic


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
    with INPUT_PATHS["matrix"].open("r", encoding="utf-8-sig", newline="") as handle:
        matrix_rows = list(csv.DictReader(handle))
    assert len(matrix_rows) == 340
    matrix_by_id = index_unique(matrix_rows, "feature_id")

    manifest = load_json(INPUT_PATHS["manifest"])
    classification = load_json(INPUT_PATHS["classification"])
    candidates = load_json(INPUT_PATHS["candidate_manifest"])
    candidate_review = load_json(INPUT_PATHS["candidate_review"])
    page_graph = load_json(INPUT_PATHS["page_graph"])
    page_graph_review = load_json(INPUT_PATHS["page_graph_review"])
    ownership = load_json(INPUT_PATHS["ownership_ledger"])
    ownership_review = load_json(INPUT_PATHS["ownership_review"])
    direct_queue = load_json(INPUT_PATHS["direct_queue"])
    run091 = load_json(INPUT_PATHS["run091_cohort"])
    run091_review = load_json(INPUT_PATHS["run091_review"])
    run092 = load_json(INPUT_PATHS["run092_overlay"])
    run092_review = load_json(INPUT_PATHS["run092_review"])
    run098 = load_json(INPUT_PATHS["run098_overlay"])
    run102 = load_json(INPUT_PATHS["run102_overlay"])
    run102_review = load_json(INPUT_PATHS["run102_review"])
    run103 = load_json(INPUT_PATHS["run103_reporting"])
    run104 = load_json(INPUT_PATHS["run104_dashboard_review"])

    assert candidate_review["verdict"]["decision"] == "GO"
    assert page_graph_review["decision"]["verdict"] == "GO"
    assert ownership_review["decision"]["verdict"] == "GO"
    assert run091_review["decision"]["verdict"] == "GO_WITH_EXPLICIT_NON_OWNER_DECISIONS"
    assert run092_review["decision"]["verdict"] == "GO"
    assert run102_review["decision"]["verdict"] == "GO"
    assert run102["combined_counts"]["source_owner_records"] == 592
    assert run102["combined_counts"]["route_owner_records"] == 265
    assert run102["combined_counts"]["page_owner_records"] == 327
    assert run102["combined_counts"]["distinct_feature_ids"] == 249
    assert run102["combined_counts"]["static_controller_action_bridges"] == 53
    assert run102["combined_counts"]["bounded_static_source_residual_records"] == 3337
    assert run103["status"] == "REVIEWED_OUTCOME_NEUTRAL_ROUTE_ACTION_OVERLAY_REPORTED_GATE_4_INCOMPLETE"
    assert run104["verification"]["state"] == "GO"
    assert run104["audit_completion_test_met"] is False

    page_manifest_rows = list(manifest["page_universe"]["page_roots"])
    page_manifest_by_id = index_unique(page_manifest_rows, "page_record_id")
    page_decision_by_id = index_unique(classification["page_decisions"], "page_record_id")
    page_graph_by_id = index_unique(
        [row for row in page_graph["records"] if row.get("page_root_id")], "page_root_id"
    )
    page_candidate_rows = list(candidates["page_static_candidate_census"]["records"])

    current_owner_rows = (
        list(ownership["records"])
        + list(run092["overlay_source_records"])
        + list(run098["overlay_source_records"])
        + list(run102["overlay_source_records"])
    )
    assert len(current_owner_rows) == 592
    current_owner_ids = {row["source_record_id"] for row in current_owner_rows}
    current_owner_features = {row["feature_id"] for row in current_owner_rows}
    current_route_features = {
        row["feature_id"] for row in current_owner_rows if row["surface"] == "ROUTE_SOURCE_RECORD"
    }
    current_page_features = {
        row["feature_id"] for row in current_owner_rows if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"
    }
    assert len(current_owner_features) == 249
    assert len(current_route_features) == 59
    assert len(current_page_features) == 234

    reviewed_page_ids = {row["page_source"]["page_record_id"] for row in run091["records"]}
    assert len(reviewed_page_ids) == 11
    assert sum(row["surface"] == "PAGE_ROOT_SOURCE_RECORD" for row in run092["overlay_source_records"]) == 9
    assert len(run092["shared_relation_chains"]) == 2

    conflicting_singletons = {
        row["page_record_id"]
        for row in page_candidate_rows
        if row["render_owner_relation"]["candidate_count"] == 1
        and row["relation_comparison"] in {"BOTH_LANES_PARTIAL_OVERLAP", "BOTH_LANES_DISJOINT"}
        and row["page_record_id"] not in reviewed_page_ids
        and row["page_record_id"] not in current_owner_ids
    }
    assert conflicting_singletons == EXPECTED_CONFLICTING_SINGLETON_IDS

    eligible = [
        row
        for row in page_candidate_rows
        if row["render_owner_relation"]["candidate_count"] == 1
        and row["relation_comparison"] in {"RENDER_OWNER_ONLY", "BOTH_LANES_IDENTICAL"}
        and row["page_record_id"] not in reviewed_page_ids
        and row["page_record_id"] not in current_owner_ids
    ]
    assert len(eligible) == 30
    selected = eligible[:24]
    assert len(selected) == 24
    assert Counter(row["relation_comparison"] for row in selected) == {"RENDER_OWNER_ONLY": 24}

    selected_page_ids = [row["page_record_id"] for row in selected]
    selected_paths = [row["page_file"] for row in selected]
    selected_features = {row["render_owner_relation"]["candidate_feature_ids"][0] for row in selected}
    selected_render_anchors = [row["render_callsites"][0]["source_anchor"] for row in selected]
    assert canonical_list_sha256(selected_page_ids) == EXPECTED_PAGE_IDS_SHA256
    assert canonical_list_sha256(selected_paths) == EXPECTED_PAGE_PATHS_SHA256
    assert canonical_list_sha256(selected_features) == EXPECTED_FEATURE_IDS_SHA256
    assert canonical_list_sha256(selected_render_anchors) == EXPECTED_RENDER_ANCHORS_SHA256

    new_feature_ids = selected_features - current_owner_features
    assert new_feature_ids == EXPECTED_NEW_FEATURE_IDS
    assert Counter(matrix_by_id[value]["feature_class"] for value in new_feature_ids) == {"H": 6, "D": 2}
    assert (selected_features & current_route_features) - current_page_features == {
        "CAP-DAY-ALL-TASKS-WORKBENCH"
    }

    all_render_callsites: list[dict[str, Any]] = []
    for page_row in page_manifest_rows:
        all_render_callsites.extend(page_row["render_callsites"])
    direct_queue_source_ids = {row["source_record_id"] for row in direct_queue["records"]}
    assert not (set(selected_page_ids) & direct_queue_source_ids)

    records: list[dict[str, Any]] = []
    for sequence, candidate in enumerate(selected, 1):
        page_id = candidate["page_record_id"]
        feature_id = candidate["render_owner_relation"]["candidate_feature_ids"][0]
        page_manifest = page_manifest_by_id[page_id]
        graph_row = page_graph_by_id[page_id]
        page_decision = page_decision_by_id[page_id]
        assert page_decision["prompt_classification"] == "Evidence gap"
        assert graph_row["partition"] == "LITERAL_RENDERED_PAGE_ROOT"
        assert graph_row["prompt_classification"] == "Evidence gap"
        assert graph_row["path"] == page_manifest["page_file"] == candidate["page_file"]
        assert graph_row["sha256"] == page_manifest["page_file_sha256"]
        assert graph_row["blob_id"] == page_manifest["page_file_blob_id"]
        assert sha256_file(REPO / page_manifest["page_file"]) == page_manifest["page_file_sha256"]
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{page_manifest['page_file']}") == page_manifest["page_file_blob_id"]

        assert len(candidate["render_callsites"]) == 1
        selected_callsite = candidate["render_callsites"][0]
        manifest_callsites = [
            row for row in page_manifest["render_callsites"]
            if row["source_anchor"] == selected_callsite["source_anchor"]
        ]
        assert len(manifest_callsites) == 1
        full_callsite = manifest_callsites[0]
        controller = containing_method_slice(full_callsite["source_file"], full_callsite["source_line"])
        method_slice = controller["review_slice"]
        controller_file = controller["source_file"]
        controller_method = controller["method"]
        assert sha256_file(REPO / controller_file) == full_callsite["source_file_sha256"]
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{controller_file}") == full_callsite["source_file_blob_id"]
        calls_in_method = [
            row for row in all_render_callsites
            if row["source_file"] == controller_file
            and method_slice["start_line"] <= row["source_line"] <= method_slice["end_line"]
        ]
        assert any(row["source_anchor"] == full_callsite["source_anchor"] for row in calls_in_method)
        local_helpers = BASE.transitive_local_helper_slices(
            controller_file, controller_method, method_slice["text"]
        )
        delegated_calls = [
            {"property": prop, "method": method}
            for prop, method in sorted(set(DELEGATED_CALL_RE.findall(method_slice["text"])))
        ]
        projection = feature_projection(matrix_by_id[feature_id])
        evidence = {
            "page_candidate_sha256": canonical_json_sha256(candidate),
            "page_manifest_sha256": canonical_json_sha256(page_manifest),
            "page_decision_sha256": canonical_json_sha256(page_decision),
            "page_graph_sha256": canonical_json_sha256(graph_row),
            "controller_method_slice_sha256": method_slice["text_sha256"],
            "local_helper_slices_sha256": canonical_json_sha256(local_helpers),
            "feature_projection_sha256": canonical_json_sha256(projection),
        }
        record: dict[str, Any] = {
            "candidate_id": f"RUN105-PAGE-RENDER-{sequence:02d}",
            "page_feature_key": f"{page_id}|{feature_id}",
            "review_partition": ("A", "B", "C")[(sequence - 1) // 8],
            "candidate_manifest_index_zero_based": page_candidate_rows.index(candidate),
            "candidate_feature_id": feature_id,
            "page_source": {
                "page_record_id": page_id,
                "original_partition": candidate["partition_id"],
                "page_file": page_manifest["page_file"],
                "page_file_sha256": page_manifest["page_file_sha256"],
                "page_file_blob_id": page_manifest["page_file_blob_id"],
                "page_line_count": len((REPO / page_manifest["page_file"]).read_text(encoding="utf-8").splitlines()),
                "render_names": page_manifest["render_names"],
                "render_call_count": page_manifest["render_call_count"],
                "candidate_relation": candidate["relation_comparison"],
                "candidate_feature_ids": candidate["candidate_union_feature_ids"],
            },
            "render_owner": {
                "relation_class": "STATIC_PAGE_RENDER_OWNER_REVIEW_CANDIDATE",
                "selected_render_callsite": full_callsite,
                "controller_file": controller_file,
                "controller_file_sha256": controller["source_file_sha256"],
                "controller_file_blob_id": controller["source_file_blob_id"],
                "method": controller_method,
                "definition_line": controller["definition_line"],
                "definition_anchor": controller["definition_anchor"],
                "method_review_slice": method_slice,
                "literal_render_calls_inside_method_slice": calls_in_method,
                "literal_render_call_count_inside_method_slice": len(calls_in_method),
                "transitive_local_helper_slices": local_helpers,
                "delegated_property_calls": delegated_calls,
                "page_ownership_credit": False,
                "route_ownership_credit": False,
                "controller_action_bridge_credit": False,
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
                "prior_review_page_collision": False,
                "current_owner_page_collision": False,
                "direct_queue_collision": False,
                "conflicting_candidate_lane": False,
            },
            "fresh_review_state": {
                "status": "PENDING",
                "allowed_outcomes": [
                    "OWNER_PAGE", "SHARED_RELATION", "ALIAS_OR_REDIRECT",
                    "DEAD_OR_NONCANONICAL", "EVIDENCE_GAP",
                ],
                "page_ownership_credit": False,
                "route_ownership_credit": False,
                "controller_action_bridge_credit": False,
            },
            "evidence_digests": {
                **evidence,
                "joined_candidate_evidence_sha256": canonical_json_sha256(evidence),
            },
        }
        record["candidate_record_sha256"] = canonical_json_sha256(record)
        records.append(record)

    assert len(records) == 24
    assert len({row["page_source"]["page_record_id"] for row in records}) == 24
    assert len({row["page_source"]["page_file"] for row in records}) == 24
    assert Counter(row["review_partition"] for row in records) == {"A": 8, "B": 8, "C": 8}
    assert all(row["fresh_review_state"]["page_ownership_credit"] is False for row in records)

    partitions: dict[str, Any] = {}
    for partition in ("A", "B", "C"):
        assigned = [row for row in records if row["review_partition"] == partition]
        partitions[partition] = {
            "assigned_candidates": 8,
            "candidate_ids": [row["candidate_id"] for row in assigned],
            "page_feature_key_list_sha256": canonical_list_sha256(
                [row["page_feature_key"] for row in assigned]
            ),
            "fresh_reviewer_required": True,
        }

    return {
        "schema_version": "run-105-outcome-neutral-page-render-owner-cohort-wave-14-v1",
        "run_id": "RUN-105-OUTCOME-NEUTRAL-PAGE-RENDER-OWNER-COHORT-WAVE-14",
        "status": "TWENTY_FOUR_PAGE_RENDER_OWNER_CANDIDATES_PENDING_FRESH_REVIEW_ZERO_CREDIT",
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
            "are not authorization boundaries. Static page ownership never proves approved-Site reach, "
            "permissions, direct-object concealment, privacy, lifecycle correctness, runtime, or release."
        ),
        "selection_contract": {
            "outcome_neutral": True,
            "candidate_owner_projection_authorized": False,
            "rule": (
                "Preserve RUN-082 page-candidate order, require one exact render-owner candidate, exclude "
                "all RUN-091-reviewed and current-owner pages, reject disjoint/partial-overlap lanes, and "
                "freeze the first 24 RENDER_OWNER_ONLY or BOTH_LANES_IDENTICAL candidates."
            ),
            "prior_reviewed_page_ids_excluded": 11,
            "conflicting_singleton_page_ids_excluded": sorted(EXPECTED_CONFLICTING_SINGLETON_IDS),
            "render_containment_is_not_semantic_ownership": True,
            "required_semantic_evidence": [
                "complete page source", "exact containing render method", "material page imports",
                "all literal render calls in the method", "canonical matrix user job",
            ],
            "prohibited_inheritance": [
                "render containment alone", "page-file presence", "controller containment",
                "route ownership", "navigation", "middleware", "framework reachability", "runtime",
            ],
        },
        "counts": {
            "eligible_nonconflicting_singleton_pages_before_selection": 30,
            "selected_page_candidates": 24,
            "unselected_eligible_page_candidates": 6,
            "candidate_route_records": 0,
            "candidate_controller_action_bridges": 0,
            "distinct_feature_ids": 17,
            "distinct_feature_ids_not_in_current_owner_set": 8,
            "current_source_owner_records": 592,
            "current_route_owner_records": 265,
            "current_page_owner_records": 327,
            "current_page_residual_unadjudicated": 382,
            "current_page_shared_relations": 2,
            "current_total_residual_records": 3337,
            "current_static_controller_action_bridges": 53,
            "direct_exact_queue_records": 507,
            "direct_exact_queue_reviewed": 59,
            "direct_exact_queue_pending": 448,
            "page_ownership_credit_awarded": 0,
            "route_ownership_credit_awarded": 0,
            "controller_action_bridge_credit_awarded": 0,
            "runtime_credit": 0,
            "application_browser_credit": 0,
            "executed_test_credit": 0,
            "benchmark_credit": 0,
            "pass_credit": 0,
            "completion_credit": 0,
        },
        "yield_comparison": {
            "selected_pages_over_current_unadjudicated_page_residual_percent": 6.282723,
            "reconciled_route_alternative_candidates": 24,
            "route_alternative_over_explicit_unmapped_route_residual_percent": 0.814941,
            "page_candidate_feature_ids": 17,
            "route_alternative_feature_ids": 11,
            "page_candidate_new_feature_ids": 8,
            "route_alternative_new_feature_ids": 2,
            "decision": "PAGE_RENDER_OWNER_LANE_HIGHER_BOUNDED_YIELD",
        },
        "identity": {
            "page_record_id_list_sha256": canonical_list_sha256(selected_page_ids),
            "page_path_list_sha256": canonical_list_sha256(selected_paths),
            "feature_id_list_sha256": canonical_list_sha256(selected_features),
            "new_feature_id_list_sha256": canonical_list_sha256(new_feature_ids),
            "render_anchor_list_sha256": canonical_list_sha256(selected_render_anchors),
            "page_feature_key_list_sha256": canonical_list_sha256(
                [row["page_feature_key"] for row in records]
            ),
            "candidate_record_sha256_list_sha256": canonical_list_sha256(
                [row["candidate_record_sha256"] for row in records]
            ),
            "records_sha256": canonical_json_sha256(records),
        },
        "review_partitions": partitions,
        "records": records,
        "fresh_review_contract": {
            "status": "PENDING",
            "required_reviews": 3,
            "reviewers_must_be_fresh_from_discovery_agents": True,
            "required_outcome_per_candidate": True,
            "allowed_outcomes": [
                "OWNER_PAGE", "SHARED_RELATION", "ALIAS_OR_REDIRECT",
                "DEAD_OR_NONCANONICAL", "EVIDENCE_GAP",
            ],
            "required_checks": [
                "Reconstruct each assigned page from the pinned manifest, classification, candidate census, page graph, current owner ledger, and source tree.",
                "Read the complete page source, exact containing render method, material imports, all sibling render roots in that method, and canonical matrix user job.",
                "Reject shared shells, multi-feature pages, semantic mismatch, dead/noncanonical roots, partial/disjoint lanes, and unresolved evidence gaps.",
                "Keep route, bridge, Site, permission, privacy, lifecycle, framework, runtime, browser, test, benchmark, Pass, finding, and completion credit false.",
            ],
            "ownership_integration_authorized": False,
        },
        "projected_conservation_only": {
            "outcome_equation": "O + S + A + D + E = 24",
            "source_conservation": "3929 = (592 + O) + (3337 - O)",
            "owner_split": "592 + O = 265 route owners + (327 + O) page owners",
            "page_conservation": "711 = (327 + O) owner + (2 + S) shared + A alias + D dead + (382 - O - S - A - D) residual; E remains a tagged residual subset",
            "controller_action_bridges": "53 unchanged",
            "direct_exact_queue": "507 = 59 reviewed + 448 pending; selected page IDs are outside RUN-090",
            "maximum_owner_ceiling_not_credit": 616,
            "maximum_page_owner_ceiling_not_credit": 351,
            "minimum_residual_ceiling_not_credit": 3313,
            "maximum_ownership_percent_ceiling_not_credit": 15.678290,
        },
        "credit_boundary": {
            "page_render_owner_candidate_packet": True,
            "page_ownership": False,
            "route_ownership": False,
            "static_controller_action_bridge": False,
            "framework_route_reachability": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "direct_object_concealment": False,
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
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/build-outcome-neutral-page-render-owner-cohort-wave-14.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/root-run-105-outcome-neutral-page-render-owner-cohort-wave-14.json",
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
        "selected_page_candidates": payload["counts"]["selected_page_candidates"],
        "review_partitions": {
            key: value["assigned_candidates"] for key, value in payload["review_partitions"].items()
        },
        "page_ownership_credit_awarded": payload["counts"]["page_ownership_credit_awarded"],
    }, indent=2))


if __name__ == "__main__":
    main()
