#!/usr/bin/env python3
"""Build RUN-109's six-record tail of safe page render-owner candidates.

RUN-105 froze the first 24 of 30 non-conflicting singleton candidates.  This
producer freezes the remaining six in the original RUN-082 order.  It grants
zero ownership: exact render containment remains discovery evidence until
three fresh reviewers read each complete page and containing controller method.
"""

from __future__ import annotations

import csv
import importlib.util
import json
from collections import Counter
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
BASE_GENERATOR = AUDIT_DIR / "generators/build-outcome-neutral-page-render-owner-cohort-wave-14.py"
OUTPUT_PATH = AUDIT_DIR / "evidence/source/root-run-109-outcome-neutral-page-render-owner-tail-cohort-wave-15.json"
PROMPT_PATH = Path(r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md")

CHECKPOINT_COMMIT = "cc3b548179f94e053edfe5146a00d0b6f55bb868"
CHECKPOINT_TREE = "db7efc5d43ecdbb99df19de2390cbf995f8abc4d"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"

spec = importlib.util.spec_from_file_location("run105_base", BASE_GENERATOR)
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
    "matrix": BASE.INPUT_PATHS["matrix"],
    "manifest": BASE.INPUT_PATHS["manifest"],
    "classification": BASE.INPUT_PATHS["classification"],
    "candidate_manifest": BASE.INPUT_PATHS["candidate_manifest"],
    "candidate_review": BASE.INPUT_PATHS["candidate_review"],
    "page_graph": BASE.INPUT_PATHS["page_graph"],
    "page_graph_review": BASE.INPUT_PATHS["page_graph_review"],
    "ownership_ledger": BASE.INPUT_PATHS["ownership_ledger"],
    "ownership_review": BASE.INPUT_PATHS["ownership_review"],
    "direct_queue": BASE.INPUT_PATHS["direct_queue"],
    "run091_cohort": BASE.INPUT_PATHS["run091_cohort"],
    "run091_review": BASE.INPUT_PATHS["run091_review"],
    "run092_overlay": BASE.INPUT_PATHS["run092_overlay"],
    "run092_review": BASE.INPUT_PATHS["run092_review"],
    "run098_overlay": BASE.INPUT_PATHS["run098_overlay"],
    "run102_overlay": BASE.INPUT_PATHS["run102_overlay"],
    "run102_review": BASE.INPUT_PATHS["run102_review"],
    "run105_cohort": AUDIT_DIR / "evidence/source/root-run-105-outcome-neutral-page-render-owner-cohort-wave-14.json",
    "run105_review": AUDIT_DIR / "evidence/source/raw-run-105r-independent-outcome-neutral-page-render-owner-review-wave-14.json",
    "run106_overlay": AUDIT_DIR / "evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json",
    "run106_review": AUDIT_DIR / "evidence/source/raw-run-106r-independent-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json",
    "run107_reporting": AUDIT_DIR / "evidence/source/current-run-107-reviewed-page-render-owner-reporting-wave-14.json",
    "run108_dashboard_review": AUDIT_DIR / "evidence/browser/current-audit-dashboard-verification-run-108-wave-14.json",
}

EXPECTED_INPUT_SHA256 = {
    "base_generator": "564c37de4525a4587c99d455fa08c6a4a4557441551c6ac5628bd8ae7ca1d31a",
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
    "run105_cohort": "4d6868c06a4c94c708e0934682e0c9724b71fc104c3751d02d0acfd3a95370bc",
    "run105_review": "764a0d086b206112d7c6b93f3d1fa733d3c3ca865a5f4ba3887d082deed1f907",
    "run106_overlay": "9c1600d2365c3527e185f313b7cb1586705f39cefb15d5d212dc708b45b630ea",
    "run106_review": "4a3252a37d03a609cdf69a4f0a56b41e120d3ba2314dede88317de9c50bfd9e4",
    "run107_reporting": "83e52ffb239fcd8fdff72eb02fba1a96258659f4b7e891275227adca4f85aea2",
    "run108_dashboard_review": "1ec434d0a30703a50da0d3def477fdeb4f671f0e03b0a85326f238b89d428f79",
}

EXPECTED_SELECTED = (
    (311, "PAGE-ROOT-C90A22826391A256", "resources/js/pages/health-clinical/protocols/Edit.tsx", "CAP-CLIN-PROTOCOL-LIFECYCLE", "RENDER_OWNER_ONLY", "app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:108"),
    (319, "PAGE-ROOT-CC7789D402A601DA", "resources/js/pages/privacy/dashboard.tsx", "CAP-PRIV-DASHBOARD-WORKLIST", "RENDER_OWNER_ONLY", "app/Http/Controllers/PrivacyDashboardController.php:48"),
    (330, "PAGE-ROOT-D25DE8AB268739E6", "resources/js/pages/emar/Medications.tsx", "CAP-MED-PHARMACY-ACTIONS", "BOTH_LANES_IDENTICAL", "app/Http/Controllers/Emar/EmarController.php:1954"),
    (332, "PAGE-ROOT-D4DBB045A52FAF0C", "resources/js/pages/fleet-assets/reports/index.tsx", "CAP-FLEET-REPORTING-EXPORT", "RENDER_OWNER_ONLY", "app/Http/Controllers/FleetAssets/ReportController.php:362"),
    (366, "PAGE-ROOT-EFDAE3984ECD777B", "resources/js/pages/hr/candidates/show.tsx", "CAP-HR-CANDIDATE-APPLICATION-LIFECYCLE", "RENDER_OWNER_ONLY", "app/Http/Controllers/Hr/CandidateController.php:410"),
    (388, "PAGE-ROOT-FA0EA841617E840E", "resources/js/pages/hr/employees/index.tsx", "CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE", "RENDER_OWNER_ONLY", "app/Http/Controllers/Hr/EmployeeProfileController.php:442"),
)

EXPECTED_CONFLICTING_SINGLETON_IDS = {
    "PAGE-ROOT-52909034DDC0736A",
    "PAGE-ROOT-5C1B7A69CC59D2A7",
}
EXPECTED_PENDING_QUEUE_PAGE = {
    "source_record_id": "PAGE-ROOT-D25DE8AB268739E6",
    "queue_id": "RUN090-PAGE-0003",
    "candidate_feature_id": "CAP-MED-PHARMACY-ACTIONS",
    "queue_record_sha256": "82486fbab4968319f65ff7b3b71b7528be21f3df1bd026a165552d0626385ee0",
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
    run105 = load_json(INPUT_PATHS["run105_cohort"])
    run105_review = load_json(INPUT_PATHS["run105_review"])
    run106 = load_json(INPUT_PATHS["run106_overlay"])
    run106_review = load_json(INPUT_PATHS["run106_review"])
    run107 = load_json(INPUT_PATHS["run107_reporting"])
    run108 = load_json(INPUT_PATHS["run108_dashboard_review"])

    assert candidate_review["verdict"]["decision"] == "GO"
    assert page_graph_review["decision"]["verdict"] == "GO"
    assert ownership_review["decision"]["verdict"] == "GO"
    assert run091_review["decision"]["verdict"] == "GO_WITH_EXPLICIT_NON_OWNER_DECISIONS"
    assert run092_review["decision"]["verdict"] == "GO"
    assert run102_review["decision"]["verdict"] == "GO"
    assert run105_review["decision"]["verdict"] == "GO_20_EXPLICIT_OWNER_PAGE_3_SHARED_RELATION_1_EVIDENCE_GAP"
    assert run106_review["decision"]["verdict"] == "GO"
    assert run106["combined_counts"] == run106_review["verified_counts"]
    assert run106["combined_counts"]["source_owner_records"] == 612
    assert run106["combined_counts"]["route_owner_records"] == 265
    assert run106["combined_counts"]["page_owner_records"] == 347
    assert run106["combined_counts"]["bounded_static_source_residual_records"] == 3317
    assert run106["combined_counts"]["residual_unadjudicated_page_roots"] == 359
    assert run106["combined_counts"]["semantic_shared_page_roots"] == 5
    assert run106["combined_counts"]["evidence_gap_page_roots_tagged_within_residual"] == 1
    assert run107["status"] == "REVIEWED_PAGE_OWNER_OVERLAY_REPORTED_GATE_4_INCOMPLETE"
    assert run108["verification"]["state"] == "GO"
    assert run108["audit_completion_test_met"] is False

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
        + list(run106["overlay_source_records"])
    )
    assert len(current_owner_rows) == 612
    current_owner_ids = {row["source_record_id"] for row in current_owner_rows}
    assert len(current_owner_ids) == 612
    current_owner_features = {row["feature_id"] for row in current_owner_rows}
    current_page_features = {
        row["feature_id"] for row in current_owner_rows if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"
    }
    assert len(current_owner_features) == 256
    assert len(current_page_features) == 242

    reviewed_page_ids = {
        row["page_source"]["page_record_id"] for row in run091["records"]
    } | {
        row["page_source"]["page_record_id"] for row in run105["records"]
    }
    assert len(reviewed_page_ids) == 35

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
        (index, row)
        for index, row in enumerate(page_candidate_rows)
        if row["render_owner_relation"]["candidate_count"] == 1
        and row["relation_comparison"] in {"RENDER_OWNER_ONLY", "BOTH_LANES_IDENTICAL"}
        and row["page_record_id"] not in reviewed_page_ids
        and row["page_record_id"] not in current_owner_ids
    ]
    projection = tuple(
        (
            index,
            row["page_record_id"],
            row["page_file"],
            row["render_owner_relation"]["candidate_feature_ids"][0],
            row["relation_comparison"],
            row["render_callsites"][0]["source_anchor"],
        )
        for index, row in eligible
    )
    assert projection == EXPECTED_SELECTED
    assert Counter(row["relation_comparison"] for _, row in eligible) == {
        "RENDER_OWNER_ONLY": 5,
        "BOTH_LANES_IDENTICAL": 1,
    }

    selected_page_ids = [row["page_record_id"] for _, row in eligible]
    selected_paths = [row["page_file"] for _, row in eligible]
    selected_features = {row["render_owner_relation"]["candidate_feature_ids"][0] for _, row in eligible}
    selected_render_anchors = [row["render_callsites"][0]["source_anchor"] for _, row in eligible]
    assert len(selected_features) == 6
    assert selected_features - current_owner_features == {"CAP-PRIV-DASHBOARD-WORKLIST"}
    assert selected_features - current_page_features == {"CAP-PRIV-DASHBOARD-WORKLIST"}

    all_render_callsites: list[dict[str, Any]] = []
    for page_row in page_manifest_rows:
        all_render_callsites.extend(page_row["render_callsites"])
    direct_queue_by_source_id = index_unique(direct_queue["records"], "source_record_id")
    selected_queue_rows = [
        direct_queue_by_source_id[page_id]
        for page_id in selected_page_ids
        if page_id in direct_queue_by_source_id
    ]
    assert len(selected_queue_rows) == 1
    assert {
        "source_record_id": selected_queue_rows[0]["source_record_id"],
        "queue_id": selected_queue_rows[0]["queue_id"],
        "candidate_feature_id": selected_queue_rows[0]["candidate_feature_id"],
        "queue_record_sha256": selected_queue_rows[0]["queue_record_sha256"],
    } == EXPECTED_PENDING_QUEUE_PAGE
    assert selected_queue_rows[0]["review_state"]["status"] == "PENDING_FRESH_SEMANTIC_REVIEW"

    records: list[dict[str, Any]] = []
    for sequence, (candidate_index, candidate) in enumerate(eligible, 1):
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
            row
            for row in page_manifest["render_callsites"]
            if row["source_anchor"] == selected_callsite["source_anchor"]
        ]
        assert len(manifest_callsites) == 1
        full_callsite = manifest_callsites[0]
        controller = BASE.containing_method_slice(full_callsite["source_file"], full_callsite["source_line"])
        method_slice = controller["review_slice"]
        controller_file = controller["source_file"]
        controller_method = controller["method"]
        assert sha256_file(REPO / controller_file) == full_callsite["source_file_sha256"]
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{controller_file}") == full_callsite["source_file_blob_id"]
        calls_in_method = [
            row
            for row in all_render_callsites
            if row["source_file"] == controller_file
            and method_slice["start_line"] <= row["source_line"] <= method_slice["end_line"]
        ]
        assert any(row["source_anchor"] == full_callsite["source_anchor"] for row in calls_in_method)
        local_helpers = BASE.BASE.transitive_local_helper_slices(
            controller_file, controller_method, method_slice["text"]
        )
        delegated_calls = [
            {"property": prop, "method": method}
            for prop, method in sorted(set(BASE.DELEGATED_CALL_RE.findall(method_slice["text"])))
        ]
        feature = BASE.feature_projection(matrix_by_id[feature_id])
        queue_row = direct_queue_by_source_id.get(page_id)
        evidence = {
            "page_candidate_sha256": canonical_json_sha256(candidate),
            "page_manifest_sha256": canonical_json_sha256(page_manifest),
            "page_decision_sha256": canonical_json_sha256(page_decision),
            "page_graph_sha256": canonical_json_sha256(graph_row),
            "controller_method_slice_sha256": method_slice["text_sha256"],
            "local_helper_slices_sha256": canonical_json_sha256(local_helpers),
            "feature_projection_sha256": canonical_json_sha256(feature),
        }
        record: dict[str, Any] = {
            "candidate_id": f"RUN109-PAGE-TAIL-{sequence:02d}",
            "page_feature_key": f"{page_id}|{feature_id}",
            "review_partition": ("A", "B", "C")[(sequence - 1) // 2],
            "candidate_manifest_index_zero_based": candidate_index,
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
                "relation_class": "STATIC_PAGE_RENDER_OWNER_TAIL_REVIEW_CANDIDATE",
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
            "feature_identity_projection": feature,
            "collision_checks": {
                "prior_review_page_collision": False,
                "current_owner_page_collision": False,
                "conflicting_candidate_lane": False,
                "direct_queue_pending_overlap_present": queue_row is not None,
                "direct_queue_pending_overlap_reconciled": queue_row is not None,
                "unreconciled_direct_queue_collision": False,
            },
            "direct_queue_context": None if queue_row is None else {
                "queue_id": queue_row["queue_id"],
                "queue_record_sha256": queue_row["queue_record_sha256"],
                "surface": queue_row["surface"],
                "candidate_feature_id": queue_row["candidate_feature_id"],
                "review_status_before": queue_row["review_state"]["status"],
                "queue_review_credit_awarded": False,
                "integration_must_reconcile_queue_accounting": True,
            },
            "fresh_review_state": {
                "status": "PENDING",
                "allowed_outcomes": [
                    "OWNER_PAGE",
                    "SHARED_RELATION",
                    "ALIAS_OR_REDIRECT",
                    "DEAD_OR_NONCANONICAL",
                    "EVIDENCE_GAP",
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

    assert len(records) == 6
    assert len({row["page_source"]["page_record_id"] for row in records}) == 6
    assert len({row["page_source"]["page_file"] for row in records}) == 6
    assert Counter(row["review_partition"] for row in records) == {"A": 2, "B": 2, "C": 2}
    assert all(row["fresh_review_state"]["page_ownership_credit"] is False for row in records)

    partitions: dict[str, Any] = {}
    for partition in ("A", "B", "C"):
        assigned = [row for row in records if row["review_partition"] == partition]
        partitions[partition] = {
            "assigned_candidates": 2,
            "candidate_ids": [row["candidate_id"] for row in assigned],
            "page_feature_key_list_sha256": canonical_list_sha256(
                [row["page_feature_key"] for row in assigned]
            ),
            "fresh_reviewer_required": True,
        }

    return {
        "schema_version": "run-109-outcome-neutral-page-render-owner-tail-cohort-wave-15-v1",
        "run_id": "RUN-109-OUTCOME-NEUTRAL-PAGE-RENDER-OWNER-TAIL-COHORT-WAVE-15",
        "status": "SIX_PAGE_RENDER_OWNER_TAIL_CANDIDATES_PENDING_FRESH_REVIEW_ZERO_CREDIT",
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
                "all 35 RUN-091/RUN-105-reviewed pages and all current owners, reject disjoint/partial-overlap "
                "lanes, and freeze the complete six-record non-conflicting singleton tail."
            ),
            "prior_reviewed_page_ids_excluded": 35,
            "conflicting_singleton_page_ids_excluded": sorted(EXPECTED_CONFLICTING_SINGLETON_IDS),
            "remaining_eligible_tail_after_selection": 0,
            "pending_direct_queue_page_overlap": EXPECTED_PENDING_QUEUE_PAGE,
            "queue_overlap_rule": (
                "RUN109-PAGE-TAIL-03 is also pending RUN090-PAGE-0003. Cohort materialization "
                "does not review that queue row; any later reviewed outcome must update queue accounting."
            ),
            "render_containment_is_not_semantic_ownership": True,
            "required_semantic_evidence": [
                "complete page source",
                "exact containing render method",
                "material page imports",
                "all literal render calls in the method",
                "canonical matrix user job",
            ],
            "prohibited_inheritance": [
                "render containment alone",
                "page-file presence",
                "controller containment",
                "route ownership",
                "navigation",
                "middleware",
                "framework reachability",
                "runtime",
            ],
        },
        "counts": {
            "eligible_nonconflicting_singleton_pages_before_selection": 6,
            "selected_page_candidates": 6,
            "unselected_eligible_page_candidates": 0,
            "candidate_route_records": 0,
            "candidate_controller_action_bridges": 0,
            "distinct_feature_ids": 6,
            "distinct_feature_ids_not_in_current_owner_set": 1,
            "current_source_owner_records": 612,
            "current_route_owner_records": 265,
            "current_page_owner_records": 347,
            "current_page_residual_unadjudicated": 359,
            "current_page_shared_relations": 5,
            "current_page_evidence_gaps_tagged_within_residual": 1,
            "current_total_residual_records": 3317,
            "current_static_controller_action_bridges": 53,
            "direct_exact_queue_records": 507,
            "direct_exact_queue_reviewed": 59,
            "direct_exact_queue_pending": 448,
            "selected_pending_direct_queue_page_records": 1,
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
        "yield": {
            "selected_pages_over_current_unadjudicated_page_residual_percent": 1.671309,
            "safe_nonconflicting_singleton_tail_closed_percent": 100.0,
            "page_candidate_feature_ids": 6,
            "page_candidate_new_feature_ids": 1,
            "decision": "FINISH_EXISTING_PAGE_SINGLETON_LANE_BEFORE_NEW_ROUTE_COHORT",
        },
        "identity": {
            "page_record_id_list_sha256": canonical_list_sha256(selected_page_ids),
            "page_path_list_sha256": canonical_list_sha256(selected_paths),
            "feature_id_list_sha256": canonical_list_sha256(selected_features),
            "new_feature_id_list_sha256": canonical_list_sha256(selected_features - current_owner_features),
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
                "OWNER_PAGE",
                "SHARED_RELATION",
                "ALIAS_OR_REDIRECT",
                "DEAD_OR_NONCANONICAL",
                "EVIDENCE_GAP",
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
            "outcome_equation": "O + S + A + D + E = 6",
            "source_conservation": "3929 = (612 + O) + (3317 - O)",
            "owner_split": "612 + O = 265 route owners + (347 + O) page owners",
            "page_conservation": "711 = (347 + O) owner + (5 + S) shared + A alias + D dead + (359 - O - S - A - D) residual; existing one gap and E remain tagged residual subsets",
            "controller_action_bridges": "53 unchanged",
            "direct_exact_queue": "cohort remains 507 = 59 reviewed + 448 pending; one selected page is RUN090-PAGE-0003 and any later reviewed outcome must move it from pending to reviewed",
            "maximum_owner_ceiling_not_credit": 618,
            "maximum_page_owner_ceiling_not_credit": 353,
            "minimum_residual_ceiling_not_credit": 3311,
            "maximum_ownership_percent_ceiling_not_credit": 15.729193,
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
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/build-outcome-neutral-page-render-owner-tail-cohort-wave-15.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/root-run-109-outcome-neutral-page-render-owner-tail-cohort-wave-15.json",
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
