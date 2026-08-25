#!/usr/bin/env python3
"""Freeze RUN-117's four unfinished Respite handover page gaps for fresh review.

RUN-113/R accepted the four parent controller actions as route/action owners but
explicitly authorized zero page ownership.  This producer therefore treats the
parent decisions as provenance only, reads each complete page packet, and grants
no ownership before a fresh outcome-neutral page review.
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
BASE_GENERATOR = AUDIT_DIR / "generators/build-outcome-neutral-page-render-owner-tail-cohort-wave-15.py"
OUTPUT_PATH = AUDIT_DIR / "evidence/source/root-run-117-outcome-neutral-respite-handover-page-gap-cohort-wave-17.json"
PROMPT_PATH = Path(r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md")

CHECKPOINT_COMMIT = "d4018e911ce8a1fea2d39549e87759f615a6cc79"
CHECKPOINT_TREE = "d8daa77b3d0f7c9ded1ab93461c6d37fbaa07d79"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
FEATURE_ID = "CAP-RESP-HANDOVER-NOTES"

spec = importlib.util.spec_from_file_location("run109_page_base", BASE_GENERATOR)
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
    "direct_queue": AUDIT_DIR / "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json",
    "run091_cohort": AUDIT_DIR / "evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json",
    "run092_overlay": AUDIT_DIR / "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json",
    "run098_overlay": AUDIT_DIR / "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json",
    "run102_overlay": AUDIT_DIR / "evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json",
    "run105_cohort": AUDIT_DIR / "evidence/source/root-run-105-outcome-neutral-page-render-owner-cohort-wave-14.json",
    "run106_overlay": AUDIT_DIR / "evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json",
    "run109_cohort": AUDIT_DIR / "evidence/source/root-run-109-outcome-neutral-page-render-owner-tail-cohort-wave-15.json",
    "run110_overlay": AUDIT_DIR / "evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json",
    "run113_cohort": AUDIT_DIR / "evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json",
    "run113_review": AUDIT_DIR / "evidence/source/raw-run-113r-independent-outcome-neutral-name-only-route-action-review-wave-16.json",
    "run114_overlay": AUDIT_DIR / "evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json",
    "run114_review": AUDIT_DIR / "evidence/source/raw-run-114r-independent-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json",
    "run115_reporting": AUDIT_DIR / "evidence/source/current-run-115-reviewed-name-only-route-action-reporting-wave-16.json",
    "run116_dashboard": AUDIT_DIR / "evidence/browser/current-audit-dashboard-verification-run-116-wave-16.json",
}

EXPECTED_INPUT_SHA256 = {
    "base_generator": "1005eaad8d3bcecf99f04b40f912e5181f28e33ef5acb044c27ba0201d0c8e0c",
    "matrix": "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390",
    "manifest": "150fcff9b100ad85a7a2e998ed69c8dafdc2d0098e8ec2b4dbac7d3b404061be",
    "classification": "7b534fea15e6c4ec98fbb2cb0c761e26116b2abee500c39e599b0e779729df97",
    "candidate_manifest": "3c6ad4df13a09ae8ff8f19aee09a1907f9754a1877806659676c6c2898652f85",
    "candidate_review": "a6a4f886ca209bc41ffa86afec37f6bddaf062ac80a6b375391adeea20e1c396",
    "page_graph": "f3856a7a86cd236684e223713a99dd64b18df692338e5d7aba688701b7c438f9",
    "page_graph_review": "036394a207f6f31c336f748bae9daed75d86549529de538510374149d56f506e",
    "ownership_ledger": "bfec52a9bed7176045457e8920bdc7b65e1c1d5c5eeb28a266f49c12acf69bcf",
    "direct_queue": "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    "run091_cohort": "7b1fea57104e9f4bfea7ee450ef535ac57e7fa69bc978212b4eafb0fe1ed0cae",
    "run092_overlay": "5390e55d9e47d1845afa8b3848bdab7687747cbb62c946dd4d66d3c435e8de0b",
    "run098_overlay": "7f76c9cfeae906a64c92013fbf9a12cf180d39a315bfa13e778b6332365cdd2a",
    "run102_overlay": "cf68900351832c790ec53b4996daaf640c604c498f902c85dec681113bf492dd",
    "run105_cohort": "4d6868c06a4c94c708e0934682e0c9724b71fc104c3751d02d0acfd3a95370bc",
    "run106_overlay": "9c1600d2365c3527e185f313b7cb1586705f39cefb15d5d212dc708b45b630ea",
    "run109_cohort": "9019306fc317374b673d76fc6023efc11deb1f7f83be67d0df72d196cd076187",
    "run110_overlay": "9375fb374b589c5068334795fa03d80a7ba1fd35695808f99c9e2591e886efca",
    "run113_cohort": "0849ca5c10e31a4f3e5133a521a570d60b004e723fb17a7f2e812e485f345461",
    "run113_review": "b52872c02b2a1b41861d9eb735eb363fd06cd1af645e1e6c0965b1b042333a83",
    "run114_overlay": "cbeb46be682ca0c9ca54012e2c27cf548b54e9cbb75b050fcd61691ca43aaef2",
    "run114_review": "f52ace52820c43ad5043139e18f1d71cf4be904091fbc02e83e045465ded62f2",
    "run115_reporting": "60787aa5f9cac19e58751528f92fe08dbc5068d63567caba3a3eacd57a661ab7",
    "run116_dashboard": "90ec8ab20cb9bf8d1e1509db614f941ad5337033973d754445ab6c88b2f13bf8",
}

# Parent order follows the RUN-113 queue order.  Partitions separate the two
# list surfaces from the create and show surfaces for three fresh reviewers.
SELECTED = (
    (
        "RUN113-NAME-ONLY-ROUTE-ACTION-18", "A", "RUN090-ROUTE-0357", "RUN077-ROUTE-2466",
        "PAGE-ROOT-BE9C48F7D19F1544", "resources/js/pages/respite/handover-notes/unacknowledged.tsx",
        "app/Http/Controllers/Respite/RespiteHandoverNoteController.php:193",
        "353f6b92665fcabe19200a52c4f430e88b127707e7c02d8d3b8528b71ca5d346",
        "446f180d6328242c06bbd9e56b00ea118d463389",
    ),
    (
        "RUN113-NAME-ONLY-ROUTE-ACTION-19", "B", "RUN090-ROUTE-0358", "RUN077-ROUTE-2467",
        "PAGE-ROOT-3A2FE406297F57BB", "resources/js/pages/respite/handover-notes/create.tsx",
        "app/Http/Controllers/Respite/RespiteHandoverNoteController.php:41",
        "0e0cdf4403f75f543ffc9bbdcfc4c0a2210d1bac1ff8babcc8871600c53c8172",
        "7a7324975d4371d19ff2cbcc9322d9fef7f805e7",
    ),
    (
        "RUN113-NAME-ONLY-ROUTE-ACTION-20", "C", "RUN090-ROUTE-0359", "RUN077-ROUTE-2468",
        "PAGE-ROOT-23F781920C41B071", "resources/js/pages/respite/handover-notes/show.tsx",
        "app/Http/Controllers/Respite/RespiteHandoverNoteController.php:104",
        "8e64e973c04efc154dc5883ceadd51fc54365e25563711d79680e498faa37ad1",
        "6d19171e6d6828098a0fb84ffcf8c12c7ac16451",
    ),
    (
        "RUN113-NAME-ONLY-ROUTE-ACTION-21", "A", "RUN090-ROUTE-0360", "RUN077-ROUTE-2469",
        "PAGE-ROOT-244A6D16E2323793", "resources/js/pages/respite/handover-notes/for-stay.tsx",
        "app/Http/Controllers/Respite/RespiteHandoverNoteController.php:179",
        "dd27d7cef7bc41855da0687d317a7ea315fdee27181758425abc6e51eef4edaf",
        "1a32ad20152e833930134afd9d6b53674829a2ce",
    ),
)

EXPECTED_IDENTITY = {
    "page_record_id_list_sha256": "a71054d3753e542d05b84cd0e645c7521ffd367e08fd419d4c4be4c6bae44367",
    "page_file_list_sha256": "5406a77c11e7a77e3bf1c4830339fced8ab37645d47ee7374a4567802cf3b5ab",
    "page_record_id_file_pair_list_sha256": "45b8fc097ed9d7e4babac110fc1e8aa5e2a2f374c7b1a29bb78c686ee3ec6984",
    "render_anchor_list_sha256": "f8b116d62c35a13923c053a7db4104fccffe8a5e96ea1dddac472cf76076cd67",
    "parent_candidate_id_list_sha256": "a9be1de656193791a684757962dcf28328effdf849dc9a65c30568499cbc36bf",
    "parent_queue_id_list_sha256": "97aeb1d938ef1c82ac189766193fb5fc3d1bf4dedd9d9c21851b2bc8917b98ca",
    "parent_decision_record_sha256_list_sha256": "a2b640e97bb83647f3d1db26c8acae1e04f5d4de7d0e7bdfeb6a1ece16fb6798",
    "page_feature_key_list_sha256": "86e1d57b727184388309159271320032722b515a1c78b91e8d36a0d8a75a3777",
    "candidate_record_sha256_list_sha256": "bae799445a849f2b9808e92bf509aa6508283692d3e9d7af2241229e653735a0",
    "records_sha256": "f17b58203e1e9e46ef1ec99db5cafbe09191fb0b9b3fc6f6c93d98a1f505ad1c",
}
EXPECTED_PARTITION_KEY_SHA256 = {
    "A": "176fc164b46824c33c282120799eda59bce6788f5d8ce1c56e88abaa06da4112",
    "B": "dd2ddc79eed01d5711f65e2edd2e7c603ff32228c1df7c2c8215c33d5a8b1222",
    "C": "5daa49bb720b2869ed0bebcbd2e08ede4f78c30650b96e2bb361b3e9dc1e315e",
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
    candidate_manifest = load_json(INPUT_PATHS["candidate_manifest"])
    candidate_review = load_json(INPUT_PATHS["candidate_review"])
    page_graph = load_json(INPUT_PATHS["page_graph"])
    page_graph_review = load_json(INPUT_PATHS["page_graph_review"])
    ownership = load_json(INPUT_PATHS["ownership_ledger"])
    direct_queue = load_json(INPUT_PATHS["direct_queue"])
    run091 = load_json(INPUT_PATHS["run091_cohort"])
    overlays = [
        load_json(INPUT_PATHS[name])
        for name in ("run092_overlay", "run098_overlay", "run102_overlay", "run106_overlay", "run110_overlay", "run114_overlay")
    ]
    run105 = load_json(INPUT_PATHS["run105_cohort"])
    run109 = load_json(INPUT_PATHS["run109_cohort"])
    run113 = load_json(INPUT_PATHS["run113_cohort"])
    run113_review = load_json(INPUT_PATHS["run113_review"])
    run114 = overlays[-1]
    run114_review = load_json(INPUT_PATHS["run114_review"])
    run115 = load_json(INPUT_PATHS["run115_reporting"])
    run116 = load_json(INPUT_PATHS["run116_dashboard"])

    assert candidate_review["verdict"]["decision"] == "GO"
    assert page_graph_review["decision"]["verdict"] == "GO"
    assert run113_review["decision"]["verdict"] == "GO_23_EXPLICIT_OWNER_ROUTE_ACTION_1_EXPLICIT_ALIAS_OR_REDIRECT"
    assert run114_review["decision"]["verdict"] == "GO"
    assert run114["combined_counts"] == run114_review["verified_combined_counts"]
    assert run114["combined_counts"]["source_owner_records"] == 637
    assert run114["combined_counts"]["route_owner_records"] == 288
    assert run114["combined_counts"]["page_owner_records"] == 349
    assert run114["combined_counts"]["bounded_static_source_residual_records"] == 3292
    assert run114["combined_counts"]["residual_unadjudicated_page_roots"] == 353
    assert run114["combined_counts"]["semantic_shared_page_roots"] == 9
    assert run114["combined_counts"]["evidence_gap_page_roots_tagged_within_residual"] == 1
    assert run114["queue_accounting"]["reviewed_queue_surface_rows"] == 84
    assert run114["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 423
    assert run115["status"] == "REVIEWED_NAME_ONLY_ROUTE_ACTION_OVERLAY_REPORTED_GATE_4_INCOMPLETE"
    assert run116["verification"]["state"] == "GO"
    assert run116["audit_completion_test_met"] is False

    page_manifest_rows = list(manifest["page_universe"]["page_roots"])
    page_manifest_by_id = index_unique(page_manifest_rows, "page_record_id")
    page_decision_by_id = index_unique(classification["page_decisions"], "page_record_id")
    page_candidate_by_id = index_unique(
        candidate_manifest["page_static_candidate_census"]["records"], "page_record_id"
    )
    page_graph_by_id = index_unique(
        [row for row in page_graph["records"] if row.get("page_root_id")], "page_root_id"
    )
    run113_by_id = index_unique(run113["records"], "candidate_id")
    run113_decision_by_id = index_unique(run113_review["action_decisions"], "candidate_id")

    current_owner_rows = list(ownership["records"])
    for overlay in overlays:
        current_owner_rows.extend(overlay["overlay_source_records"])
    current_owner_ids = {row["source_record_id"] for row in current_owner_rows}
    current_owner_features = {row["feature_id"] for row in current_owner_rows}
    current_page_features = {
        row["feature_id"] for row in current_owner_rows if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"
    }
    assert len(current_owner_rows) == len(current_owner_ids) == 637
    assert len(current_owner_features) == 256
    assert len(current_page_features) == 242
    assert FEATURE_ID in current_owner_features
    assert FEATURE_ID in current_page_features

    prior_reviewed_page_ids = {
        row["page_source"]["page_record_id"] for row in run091["records"]
    } | {
        row["page_source"]["page_record_id"] for row in run105["records"]
    } | {
        row["page_source"]["page_record_id"] for row in run109["records"]
    }
    assert len(prior_reviewed_page_ids) == 41
    direct_queue_source_ids = {row["source_record_id"] for row in direct_queue["records"]}
    accepted_route_ids = {row["source_record_id"] for row in run114["overlay_source_records"]}
    accepted_bridge_keys = {
        (row["controller_file"], row["method"], row["feature_id"])
        for row in run114["new_static_controller_action_bridges"]
    }

    records: list[dict[str, Any]] = []
    for sequence, selected in enumerate(SELECTED, 1):
        (
            parent_candidate_id, partition, queue_id, route_id, page_id, page_file,
            render_anchor, expected_page_sha256, expected_page_blob_id,
        ) = selected
        parent = run113_by_id[parent_candidate_id]
        parent_decision = run113_decision_by_id[parent_candidate_id]
        page_manifest = page_manifest_by_id[page_id]
        page_decision = page_decision_by_id[page_id]
        page_candidate = page_candidate_by_id[page_id]
        graph_row = page_graph_by_id[page_id]

        assert parent["queue_id"] == queue_id
        assert parent["route_source"]["route_record_id"] == route_id
        assert parent["candidate_feature_id"] == FEATURE_ID
        assert parent_decision["outcome"] == "OWNER_ROUTE_ACTION"
        assert parent_decision["route_ownership_authorized"] is True
        assert parent_decision["controller_action_bridge_authorized"] is True
        assert parent_decision["page_ownership_authorized"] is False
        assert route_id in accepted_route_ids
        primary = parent["controller_action"]["primary_method_slice"]
        controller_file = primary["source_file"]
        controller_method = primary["method"]
        assert (controller_file, controller_method, FEATURE_ID) in accepted_bridge_keys

        parent_contexts = [
            row for row in parent["controller_action"]["literal_inertia_page_callsites"]
            if row["page_record_id"] == page_id
        ]
        assert len(parent_contexts) == 1
        parent_context = parent_contexts[0]
        assert parent_context["page_file"] == page_file
        assert parent_context["source_anchor"] == render_anchor
        assert parent_context["run079_prompt_classification"] == "Evidence gap"
        assert parent_context["run079_reviewed_feature_ids"] == []
        assert parent_context["current_static_source_owner"] is False
        assert parent_context["page_ownership_credit_from_this_cohort"] is False

        assert page_id not in current_owner_ids
        assert page_id not in prior_reviewed_page_ids
        assert page_id not in direct_queue_source_ids
        assert page_manifest["page_file"] == page_candidate["page_file"] == graph_row["path"] == page_file
        assert page_manifest["page_file_sha256"] == page_candidate["page_file_sha256"] == graph_row["sha256"] == expected_page_sha256
        assert page_manifest["page_file_blob_id"] == graph_row["blob_id"] == expected_page_blob_id
        assert sha256_file(REPO / page_file) == expected_page_sha256
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{page_file}") == expected_page_blob_id
        assert page_decision["prompt_classification"] == "Evidence gap"
        assert page_decision["reviewed_feature_ids"] == []
        assert graph_row["partition"] == "LITERAL_RENDERED_PAGE_ROOT"
        assert graph_row["prompt_classification"] == "Evidence gap"
        assert page_candidate["relation_comparison"] == "NO_CANDIDATE_EITHER_LANE"
        assert page_candidate["render_owner_relation"]["candidate_count"] == 0
        assert page_candidate["render_owner_relation"]["candidate_feature_ids"] == []
        assert page_candidate["current_matrix_page_file_relation"]["candidate_count"] == 0
        assert page_candidate["current_matrix_page_file_relation"]["candidate_feature_ids"] == []
        assert page_candidate["candidate_union_feature_ids"] == []

        manifest_callsites = [
            row for row in page_manifest["render_callsites"] if row["source_anchor"] == render_anchor
        ]
        candidate_callsites = [
            row for row in page_candidate["render_callsites"] if row["source_anchor"] == render_anchor
        ]
        assert len(manifest_callsites) == len(candidate_callsites) == 1
        selected_callsite = manifest_callsites[0]
        assert selected_callsite["source_file"] == controller_file
        assert primary["review_slice"]["start_line"] <= selected_callsite["source_line"] <= primary["review_slice"]["end_line"]
        assert sha256_file(REPO / controller_file) == primary["source_file_sha256"]
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{controller_file}") == primary["source_file_blob_id"]

        feature = BASE.BASE.feature_projection(matrix_by_id[FEATURE_ID])
        evidence = {
            "page_manifest_sha256": canonical_json_sha256(page_manifest),
            "page_decision_sha256": canonical_json_sha256(page_decision),
            "zero_candidate_record_sha256": canonical_json_sha256(page_candidate),
            "page_graph_sha256": canonical_json_sha256(graph_row),
            "parent_candidate_record_sha256": parent["candidate_record_sha256"],
            "parent_decision_record_sha256": parent_decision["decision_record_sha256"],
            "parent_method_slice_sha256": primary["review_slice"]["text_sha256"],
            "feature_projection_sha256": canonical_json_sha256(feature),
        }
        record: dict[str, Any] = {
            "candidate_id": f"RUN117-RESPITE-HANDOVER-PAGE-GAP-{sequence:02d}",
            "page_feature_key": f"{page_id}|{FEATURE_ID}",
            "review_partition": partition,
            "candidate_feature_id": FEATURE_ID,
            "page_source": {
                "page_record_id": page_id,
                "page_file": page_file,
                "page_file_sha256": expected_page_sha256,
                "page_file_blob_id": expected_page_blob_id,
                "page_line_count": len((REPO / page_file).read_text(encoding="utf-8").splitlines()),
                "render_names": page_manifest["render_names"],
                "render_call_count": page_manifest["render_call_count"],
                "run079_prompt_classification": "Evidence gap",
                "run082_relation_comparison": "NO_CANDIDATE_EITHER_LANE",
                "run082_candidate_feature_ids": [],
            },
            "reviewed_parent_action_provenance": {
                "parent_candidate_id": parent_candidate_id,
                "queue_id": queue_id,
                "route_record_id": route_id,
                "parent_outcome": parent_decision["outcome"],
                "selected_render_callsite": selected_callsite,
                "controller_file": controller_file,
                "controller_file_sha256": primary["source_file_sha256"],
                "controller_file_blob_id": primary["source_file_blob_id"],
                "method": controller_method,
                "definition_line": primary["definition_line"],
                "definition_anchor": primary["definition_anchor"],
                "method_review_slice": primary["review_slice"],
                "transitive_local_helper_slices": parent["controller_action"]["transitive_local_helper_slices"],
                "request_contracts": parent["controller_action"]["request_contracts"],
                "parent_route_owner_present": True,
                "parent_action_bridge_present": True,
                "parent_page_ownership_authorized": False,
                "page_ownership_inheritance_prohibited": True,
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
                "prior_page_review_collision": False,
                "current_page_owner_collision": False,
                "direct_queue_overlap": False,
                "parent_route_owner_missing": False,
                "parent_action_bridge_missing": False,
                "page_candidate_lane_convergence": False,
            },
            "fresh_review_state": {
                "status": "PENDING",
                "allowed_outcomes": [
                    "OWNER_PAGE", "SHARED_RELATION", "ALIAS_OR_REDIRECT",
                    "DEAD_OR_NONCANONICAL", "EVIDENCE_GAP",
                ],
                "complete_page_read_required": True,
                "parent_route_decision_may_determine_page_outcome": False,
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

    assert len(records) == 4
    assert len({row["page_source"]["page_record_id"] for row in records}) == 4
    assert len({row["page_source"]["page_file"] for row in records}) == 4
    assert {row["candidate_feature_id"] for row in records} == {FEATURE_ID}
    assert Counter(row["review_partition"] for row in records) == {"A": 2, "B": 1, "C": 1}
    assert all(row["fresh_review_state"]["page_ownership_credit"] is False for row in records)

    partitions: dict[str, Any] = {}
    for partition in ("A", "B", "C"):
        assigned = [row for row in records if row["review_partition"] == partition]
        partitions[partition] = {
            "assigned_candidates": len(assigned),
            "candidate_ids": [row["candidate_id"] for row in assigned],
            "page_feature_key_list_sha256": canonical_list_sha256(
                [row["page_feature_key"] for row in assigned]
            ),
            "fresh_reviewer_required": True,
        }
        assert partitions[partition]["page_feature_key_list_sha256"] == EXPECTED_PARTITION_KEY_SHA256[partition]

    selected_page_ids = [row["page_source"]["page_record_id"] for row in records]
    selected_page_files = [row["page_source"]["page_file"] for row in records]
    selected_parent_ids = [row["reviewed_parent_action_provenance"]["parent_candidate_id"] for row in records]
    identity = {
        "page_record_id_list_sha256": canonical_list_sha256(selected_page_ids),
        "page_file_list_sha256": canonical_list_sha256(selected_page_files),
        "page_record_id_file_pair_list_sha256": canonical_list_sha256([
            f"{row['page_source']['page_record_id']}|{row['page_source']['page_file']}" for row in records
        ]),
        "render_anchor_list_sha256": canonical_list_sha256([
            row["reviewed_parent_action_provenance"]["selected_render_callsite"]["source_anchor"]
            for row in records
        ]),
        "parent_candidate_id_list_sha256": canonical_list_sha256(selected_parent_ids),
        "parent_queue_id_list_sha256": canonical_list_sha256([
            row["reviewed_parent_action_provenance"]["queue_id"] for row in records
        ]),
        "parent_decision_record_sha256_list_sha256": canonical_list_sha256([
            row["evidence_digests"]["parent_decision_record_sha256"] for row in records
        ]),
        "page_feature_key_list_sha256": canonical_list_sha256(
            [row["page_feature_key"] for row in records]
        ),
        "candidate_record_sha256_list_sha256": canonical_list_sha256(
            [row["candidate_record_sha256"] for row in records]
        ),
        "records_sha256": canonical_json_sha256(records),
    }
    assert identity == EXPECTED_IDENTITY
    return {
        "schema_version": "run-117-outcome-neutral-respite-handover-page-gap-cohort-wave-17-v1",
        "run_id": "RUN-117-OUTCOME-NEUTRAL-RESPITE-HANDOVER-PAGE-GAP-COHORT-WAVE-17",
        "status": "FOUR_RESPITE_HANDOVER_PAGE_GAPS_PENDING_FRESH_REVIEW_ZERO_CREDIT",
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
            "inputs": {
                INPUT_PATHS[name].relative_to(AUDIT_DIR).as_posix(): digest
                for name, digest in EXPECTED_INPUT_SHA256.items()
            },
        },
        "architecture_rule": (
            "Oblivion Findings is one operating organisation across multiple Sites. Roles, permissions, approved "
            "Sites, canonical record ownership, direct-object concealment, and privacy remain the authorization "
            "boundary. Static page review proves none of those controls."
        ),
        "selection_contract": {
            "outcome_neutral": True,
            "candidate_owner_projection_authorized": False,
            "rule": (
                "Freeze exactly the four unowned literal Respite handover page roots exposed by RUN-113 candidates "
                "18 through 21 after RUN-113R accepted their parent route/actions but explicitly authorized zero page ownership."
            ),
            "unfinished_boundary_rule": (
                "Complete this four-page boundary before starting the broader 22-row Finance route cohort."
            ),
            "no_exact_candidate_rule": (
                "All four page roots remain NO_CANDIDATE_EITHER_LANE because neither an exact matrix page-file token "
                "nor an exact backend-anchor render containment identifies them. This is not negative ownership proof."
            ),
            "parent_provenance_rule": (
                "Accepted route/action ownership identifies review provenance only. It cannot be inherited as page ownership."
            ),
            "prohibited_inheritance": [
                "parent route ownership", "parent action bridge", "render adjacency", "directory containment",
                "component name", "feature prefix", "page presence", "navigation", "framework reachability",
            ],
        },
        "independent_preflight_review": {
            "task_paths": ["/root/run110r_plan", "/root/run111_reporting_verify", "/root/run113_route_cohort"],
            "review_type": "READ_ONLY_EXACT_PAGE_ID_SOURCE_PARENT_COLLISION_AND_ZERO_CREDIT_PREFLIGHT",
            "verdict": "GO_FREEZE_FOUR_UNFINISHED_PAGE_GAPS_ZERO_OWNERSHIP_CREDIT",
            "wrote_files": False,
            "confirmed_page_roots": 4,
            "confirmed_parent_owner_route_actions": 4,
            "confirmed_prior_review_or_owner_collisions": 0,
            "confirmed_direct_queue_overlaps": 0,
        },
        "counts": {
            "candidate_page_records": 4,
            "candidate_route_records": 0,
            "candidate_controller_action_bridges": 0,
            "distinct_feature_ids": 1,
            "distinct_feature_ids_not_in_current_owner_set": 0,
            "distinct_feature_ids_not_in_current_page_owner_set": 0,
            "no_candidate_either_lane_pages": 4,
            "accepted_parent_route_actions": 4,
            "literal_render_callsites": 4,
            "prior_page_review_collisions": 0,
            "current_page_owner_collisions": 0,
            "direct_queue_overlaps": 0,
            "baseline_source_owner_records": 637,
            "baseline_page_owner_records": 349,
            "baseline_shared_page_roots": 9,
            "baseline_residual_page_roots": 353,
            "baseline_bounded_static_source_residual_records": 3292,
            "direct_exact_queue_records": 507,
            "direct_exact_queue_reviewed": 84,
            "direct_exact_queue_pending": 423,
            "direct_exact_queue_without_ownership": 430,
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
            "allowed_outcomes": [
                "OWNER_PAGE", "SHARED_RELATION", "ALIAS_OR_REDIRECT",
                "DEAD_OR_NONCANONICAL", "EVIDENCE_GAP",
            ],
            "integration_rule": (
                "Only explicit OWNER_PAGE may add one page owner. SHARED_RELATION adds one non-owner shared-page "
                "classification. Alias and dead/noncanonical become explicit reviewed non-owner buckets; "
                "EVIDENCE_GAP remains tagged inside the residual. Any reviewer conflict is EVIDENCE_GAP."
            ),
            "route_owner_records_authorized": 0,
            "controller_action_bridges_authorized": 0,
            "ownership_integration_authorized": False,
        },
        "outcome_neutral_conservation_contract": {
            "equation": "O + S + A + D + E = 4",
            "bounded_sources": "3929 = (637 + O) + (3292 - O)",
            "owner_surfaces": "637 + O = 288 routes + (349 + O) pages",
            "pages": "711 = (349 + O) owner + (9 + S) shared + A alias + D dead + (353 - O - S - A - D) residual; existing one gap and E remain tagged residual subsets",
            "controller_action_bridges": "76 unchanged",
            "direct_exact_queue": "507 = 84 reviewed + 423 pending; 430 remain without ownership because all four pages are outside RUN-090",
            "distinct_feature_ids": "256 regardless of O because CAP-RESP-HANDOVER-NOTES is already represented globally and in the page-owner set",
            "projection_credit_awarded": False,
        },
        "denominator_boundary": {
            "run_077_bounded_static_records": 3929,
            "framework_expanded_route_page_denominator": None,
            "gate_4_complete": False,
        },
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
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/build-outcome-neutral-respite-handover-page-gap-cohort-wave-17.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/root-run-117-outcome-neutral-respite-handover-page-gap-cohort-wave-17.json",
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
        "review_partitions": {
            key: value["assigned_candidates"] for key, value in payload["review_partitions"].items()
        },
        "page_ownership_credit_awarded": payload["counts"]["ownership_credit_awarded"],
    }, indent=2))


if __name__ == "__main__":
    main()
