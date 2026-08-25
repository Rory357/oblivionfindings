#!/usr/bin/env python3
"""Build the RUN-113 outcome-neutral name-only route/action review cohort.

The producer freezes 24 exact literal-name RUN-090 route surfaces whose
controller class/method resolutions are exact but whose backend-anchor lane has
zero candidates.  It deliberately grants no ownership before three fresh
semantic reviews.
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
OUTPUT_PATH = AUDIT_DIR / "evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json"
PROMPT_PATH = Path(r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md")

CHECKPOINT_COMMIT = "a1cb1dc693a5bf64f04fe9bd35b8df950b6bf166"
CHECKPOINT_TREE = "47580362a40a8660aa38e92cd4ffdb61d43dc844"
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
semantic_slice = BASE.semantic_slice
transitive_local_helper_slices = BASE.transitive_local_helper_slices
request_contract = BASE.request_contract
feature_projection = BASE.BASE.feature_projection

INPUT_PATHS = {
    "base_generator": BASE_GENERATOR,
    "matrix": AUDIT_DIR / "03-feature-to-benchmark-matrix.csv",
    "manifest": AUDIT_DIR / "evidence/source/root-run-077-route-page-universe-manifest-wave-07.json",
    "classification": AUDIT_DIR / "evidence/source/current-route-page-classification-wave-07.json",
    "candidate_manifest": AUDIT_DIR / "evidence/source/root-run-082-exact-owner-containment-candidate-manifest-wave-08.json",
    "candidate_review": AUDIT_DIR / "evidence/source/raw-run-082r-independent-exact-owner-containment-review-wave-08.json",
    "ownership_ledger": AUDIT_DIR / "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json",
    "direct_queue": AUDIT_DIR / "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json",
    "run091_cohort": AUDIT_DIR / "evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json",
    "run092_overlay": AUDIT_DIR / "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json",
    "run097_cohort": AUDIT_DIR / "evidence/source/root-run-097-route-controller-only-candidate-cohort-wave-12.json",
    "run098_overlay": AUDIT_DIR / "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json",
    "run101_cohort": AUDIT_DIR / "evidence/source/root-run-101-outcome-neutral-route-action-cohort-wave-13.json",
    "run102_overlay": AUDIT_DIR / "evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json",
    "run106_overlay": AUDIT_DIR / "evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json",
    "run110_overlay": AUDIT_DIR / "evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json",
    "run110_review": AUDIT_DIR / "evidence/source/raw-run-110r-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json",
    "run111_reporting": AUDIT_DIR / "evidence/source/current-run-111-reviewed-page-render-owner-tail-reporting-wave-15.json",
    "run112_dashboard": AUDIT_DIR / "evidence/browser/current-audit-dashboard-verification-run-112-wave-15.json",
}

EXPECTED_INPUT_SHA256 = {
    "base_generator": "f3ada90da486ba700d21596fb765ab10f661c343944899551006d5db5b9e7a0f",
    "matrix": "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390",
    "manifest": "150fcff9b100ad85a7a2e998ed69c8dafdc2d0098e8ec2b4dbac7d3b404061be",
    "classification": "7b534fea15e6c4ec98fbb2cb0c761e26116b2abee500c39e599b0e779729df97",
    "candidate_manifest": "3c6ad4df13a09ae8ff8f19aee09a1907f9754a1877806659676c6c2898652f85",
    "candidate_review": "a6a4f886ca209bc41ffa86afec37f6bddaf062ac80a6b375391adeea20e1c396",
    "ownership_ledger": "bfec52a9bed7176045457e8920bdc7b65e1c1d5c5eeb28a266f49c12acf69bcf",
    "direct_queue": "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    "run091_cohort": "7b1fea57104e9f4bfea7ee450ef535ac57e7fa69bc978212b4eafb0fe1ed0cae",
    "run092_overlay": "5390e55d9e47d1845afa8b3848bdab7687747cbb62c946dd4d66d3c435e8de0b",
    "run097_cohort": "69981d1bc22d76b8f17834040272260d9b33c151535a3ff2ef17ae4643923933",
    "run098_overlay": "7f76c9cfeae906a64c92013fbf9a12cf180d39a315bfa13e778b6332365cdd2a",
    "run101_cohort": "3a8f4c3f11668406f34db7e50ae561fe1c6516e7002eb7e8271851e62c3ff655",
    "run102_overlay": "cf68900351832c790ec53b4996daaf640c604c498f902c85dec681113bf492dd",
    "run106_overlay": "9c1600d2365c3527e185f313b7cb1586705f39cefb15d5d212dc708b45b630ea",
    "run110_overlay": "9375fb374b589c5068334795fa03d80a7ba1fd35695808f99c9e2591e886efca",
    "run110_review": "e9b076e790e5346f99665f8f99ee609b4c7b7bac4767e416abc73a57f7dfd867",
    "run111_reporting": "ba53c4686450ced0ebbfb56f5637f5631a4cd5aca42610c91adbb5e95139c48b",
    "run112_dashboard": "5ff6ac0d5905707016b9de4771b572155293d91cbac70a6130a55a3663cb4d8d",
}

# Zero-based RUN-090 queue positions.  Review partitions are three fresh,
# sorted, disjoint 8-row assignments and do not preserve the old partitions.
SELECTED = (
    (137, "A", "RUN090-ROUTE-0138", "RUN077-ROUTE-0793", "CAP-FLEET-INCIDENT-RECORD"),
    (138, "A", "RUN090-ROUTE-0139", "RUN077-ROUTE-0794", "CAP-FLEET-INCIDENT-RECORD"),
    (139, "A", "RUN090-ROUTE-0140", "RUN077-ROUTE-0795", "CAP-FLEET-INCIDENT-RECORD"),
    (140, "A", "RUN090-ROUTE-0141", "RUN077-ROUTE-0796", "CAP-FLEET-INCIDENT-RECORD"),
    (141, "A", "RUN090-ROUTE-0142", "RUN077-ROUTE-0797", "CAP-FLEET-INCIDENT-RECORD"),
    (142, "A", "RUN090-ROUTE-0143", "RUN077-ROUTE-0798", "CAP-FLEET-INCIDENT-RECORD"),
    (143, "A", "RUN090-ROUTE-0144", "RUN077-ROUTE-0799", "CAP-FLEET-INCIDENT-RECORD"),
    (144, "A", "RUN090-ROUTE-0145", "RUN077-ROUTE-0800", "CAP-FLEET-INCIDENT-RECORD"),
    (145, "B", "RUN090-ROUTE-0146", "RUN077-ROUTE-0801", "CAP-FLEET-INCIDENT-RECORD"),
    (146, "B", "RUN090-ROUTE-0147", "RUN077-ROUTE-0802", "CAP-FLEET-INCIDENT-RECORD"),
    (147, "B", "RUN090-ROUTE-0148", "RUN077-ROUTE-0803", "CAP-FLEET-INCIDENT-RECORD"),
    (148, "B", "RUN090-ROUTE-0149", "RUN077-ROUTE-0804", "CAP-FLEET-INCIDENT-RECORD"),
    (149, "B", "RUN090-ROUTE-0150", "RUN077-ROUTE-0805", "CAP-FLEET-INCIDENT-RECORD"),
    (150, "B", "RUN090-ROUTE-0151", "RUN077-ROUTE-0806", "CAP-FLEET-INCIDENT-RECORD"),
    (151, "B", "RUN090-ROUTE-0152", "RUN077-ROUTE-0807", "CAP-FLEET-INCIDENT-RECORD"),
    (152, "B", "RUN090-ROUTE-0153", "RUN077-ROUTE-0808", "CAP-FLEET-INCIDENT-RECORD"),
    (355, "C", "RUN090-ROUTE-0356", "RUN077-ROUTE-2465", "CAP-RESP-HANDOVER-NOTES"),
    (356, "C", "RUN090-ROUTE-0357", "RUN077-ROUTE-2466", "CAP-RESP-HANDOVER-NOTES"),
    (357, "C", "RUN090-ROUTE-0358", "RUN077-ROUTE-2467", "CAP-RESP-HANDOVER-NOTES"),
    (358, "C", "RUN090-ROUTE-0359", "RUN077-ROUTE-2468", "CAP-RESP-HANDOVER-NOTES"),
    (359, "C", "RUN090-ROUTE-0360", "RUN077-ROUTE-2469", "CAP-RESP-HANDOVER-NOTES"),
    (360, "C", "RUN090-ROUTE-0361", "RUN077-ROUTE-2470", "CAP-RESP-HANDOVER-NOTES"),
    (361, "C", "RUN090-ROUTE-0362", "RUN077-ROUTE-2471", "CAP-RESP-HANDOVER-NOTES"),
    (362, "C", "RUN090-ROUTE-0363", "RUN077-ROUTE-2472", "CAP-RESP-HANDOVER-NOTES"),
)

REQUEST_USE_RE = re.compile(r"^use\s+(App\\Http\\Requests\\[^;]+);", re.MULTILINE)


def request_contracts_for_slice(controller_file: str, slice_text: str) -> list[dict[str, Any]]:
    source = (REPO / controller_file).read_text(encoding="utf-8-sig")
    contracts: list[dict[str, Any]] = []
    for fqcn in sorted(set(REQUEST_USE_RE.findall(source))):
        short = fqcn.rsplit("\\", 1)[-1]
        if not re.search(rf"\b{re.escape(short)}\b", slice_text):
            continue
        relative = fqcn.replace("\\", "/") + ".php"
        if (REPO / relative).is_file():
            contracts.append(request_contract(relative))
    return contracts


def literal_page_callsites(
    page_rows: list[dict[str, Any]], controller_file: str, review_slice: dict[str, Any],
    current_owner_ids: set[str], page_decision_by_id: dict[str, dict[str, Any]],
) -> list[dict[str, Any]]:
    result: list[dict[str, Any]] = []
    for page_row in page_rows:
        for callsite in page_row["render_callsites"]:
            if callsite["source_file"] != controller_file:
                continue
            if not review_slice["start_line"] <= callsite["source_line"] <= review_slice["end_line"]:
                continue
            decision = page_decision_by_id[page_row["page_record_id"]]
            result.append({
                "page_record_id": page_row["page_record_id"],
                "page_file": page_row["page_file"],
                "source_anchor": callsite["source_anchor"],
                "run079_prompt_classification": decision["prompt_classification"],
                "run079_reviewed_feature_ids": decision["reviewed_feature_ids"],
                "current_static_source_owner": page_row["page_record_id"] in current_owner_ids,
                "current_page_status": (
                    "CURRENT_STATIC_SOURCE_OWNER"
                    if page_row["page_record_id"] in current_owner_ids
                    else "UNOWNED_CONTEXT_REQUIRES_SEPARATE_PAGE_REVIEW"
                ),
                "page_ownership_credit_from_this_cohort": False,
            })
    return sorted(result, key=lambda row: (row["source_anchor"], row["page_record_id"]))


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


def cohort_route_ids(payload: dict[str, Any]) -> set[str]:
    result: set[str] = set()
    for row in payload["records"]:
        route_id = row.get("route_record_id") or row.get("route_source", {}).get("route_record_id")
        assert isinstance(route_id, str) and route_id
        result.add(route_id)
    return result


def build() -> dict[str, Any]:
    assert_workspace_and_inputs()
    with INPUT_PATHS["matrix"].open("r", encoding="utf-8-sig", newline="") as handle:
        matrix_rows = list(csv.DictReader(handle))
    assert len(matrix_rows) == 340
    matrix_by_id = index_unique(matrix_rows, "feature_id")

    manifest = load_json(INPUT_PATHS["manifest"])
    classification = load_json(INPUT_PATHS["classification"])
    candidates = load_json(INPUT_PATHS["candidate_manifest"])
    ownership = load_json(INPUT_PATHS["ownership_ledger"])
    queue = load_json(INPUT_PATHS["direct_queue"])
    run091 = load_json(INPUT_PATHS["run091_cohort"])
    run092 = load_json(INPUT_PATHS["run092_overlay"])
    run097 = load_json(INPUT_PATHS["run097_cohort"])
    run098 = load_json(INPUT_PATHS["run098_overlay"])
    run101 = load_json(INPUT_PATHS["run101_cohort"])
    run102 = load_json(INPUT_PATHS["run102_overlay"])
    run106 = load_json(INPUT_PATHS["run106_overlay"])
    run110 = load_json(INPUT_PATHS["run110_overlay"])
    run110_review = load_json(INPUT_PATHS["run110_review"])
    run112 = load_json(INPUT_PATHS["run112_dashboard"])

    assert run110["combined_counts"]["source_owner_records"] == 614
    assert run110["combined_counts"]["route_owner_records"] == 265
    assert run110["combined_counts"]["page_owner_records"] == 349
    assert run110["combined_counts"]["static_controller_action_bridges"] == 53
    assert run110["combined_counts"]["distinct_feature_ids"] == 256
    assert run110["queue_accounting"]["reviewed_queue_surface_rows"] == 60
    assert run110["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 447
    assert run110_review["decision"]["verdict"] == "GO"
    assert run112["verification"]["state"] == "GO"

    route_rows = list(manifest["route_universe"]["primary_route_facade_callsites"])
    route_rows += list(manifest["route_universe"]["route_like_sentinels"])
    route_by_id = index_unique(route_rows, "route_record_id")
    decision_by_id = index_unique(classification["route_decisions"], "route_record_id")
    candidate_by_id = index_unique(candidates["route_static_candidate_census"]["records"], "route_record_id")
    page_rows = list(manifest["page_universe"]["page_roots"])
    page_decision_by_id = index_unique(classification["page_decisions"], "page_record_id")

    overlay_payloads = [run092, run098, run102, run106, run110]
    current_owner_rows = list(ownership["records"])
    for payload in overlay_payloads:
        current_owner_rows.extend(payload["overlay_source_records"])
    current_owner_ids = {row["source_record_id"] for row in current_owner_rows}
    current_owner_features = {row["feature_id"] for row in current_owner_rows}
    assert len(current_owner_ids) == 614
    assert len(current_owner_features) == 256

    reviewed_route_ids = cohort_route_ids(run091) | cohort_route_ids(run097) | cohort_route_ids(run101)
    assert len(reviewed_route_ids) == 58
    bridge_rows = list(run092["static_controller_action_bridges"])
    for payload in (run098, run102, run106, run110):
        bridge_rows.extend(payload["new_static_controller_action_bridges"])
    assert len(bridge_rows) == 53
    existing_bridge_keys = {
        (row["controller_file"], row["method"], row["feature_id"]) for row in bridge_rows
    }

    records: list[dict[str, Any]] = []
    for sequence, (queue_index, partition, queue_id, route_id, feature_id) in enumerate(SELECTED, 1):
        queue_row = queue["records"][queue_index]
        assert queue_row["queue_id"] == queue_id
        assert queue_row["source_record_id"] == route_id
        assert queue_row["candidate_feature_id"] == feature_id
        assert queue_row["surface"] == "ROUTE_SOURCE_RECORD"
        assert queue_row["review_state"]["status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
        assert queue_row["secondary_lane"]["relation_comparison"] == "NAME_ONLY"
        assert queue_row["secondary_lane"]["contradictory_candidate_present"] is False
        assert route_id not in reviewed_route_ids
        assert route_id not in current_owner_ids

        route_row = route_by_id[route_id]
        decision = decision_by_id[route_id]
        candidate = candidate_by_id[route_id]
        backend = candidate["backend_method_relation"]
        resolution = backend["resolution"]
        assert decision["classification"] == "EXPLICIT_UNMAPPED_SENTINEL"
        assert candidate["relation_comparison"] == "NAME_ONLY"
        assert candidate["name_relation"]["candidate_feature_ids"] == [feature_id]
        assert backend["candidate_count"] == 0
        assert backend["candidate_feature_ids"] == []
        assert resolution["status"] == "EXACT_CLASS_METHOD_ARRAY_RESOLVED_UNIQUE_DEFINITION"
        assert sha256_file(REPO / route_row["route_file"]) == route_row["route_file_sha256"]
        assert sha256_file(REPO / resolution["controller_file"]) == resolution["controller_file_sha256"]

        primary = semantic_slice(resolution["controller_file"], resolution["method"])
        assert primary["definition_line"] == resolution["definition_line"]
        helpers = transitive_local_helper_slices(
            resolution["controller_file"], resolution["method"], primary["review_slice"]["text"]
        )
        requests = request_contracts_for_slice(
            resolution["controller_file"], primary["review_slice"]["text"]
        )
        pages = literal_page_callsites(
            page_rows, resolution["controller_file"], primary["review_slice"], current_owner_ids,
            page_decision_by_id,
        )
        bridge_key = (resolution["controller_file"], resolution["method"], feature_id)
        assert bridge_key not in existing_bridge_keys

        action_key = f"{route_id}|{resolution['controller_file']}:{resolution['method']}|{feature_id}"
        record: dict[str, Any] = {
            "candidate_id": f"RUN113-NAME-ONLY-ROUTE-ACTION-{sequence:02d}",
            "action_key": action_key,
            "review_partition": partition,
            "run090_original_partition": queue_row["review_partition"],
            "queue_index_zero_based": queue_index,
            "queue_id": queue_id,
            "queue_canonical_key": queue_row["canonical_key"],
            "candidate_feature_id": feature_id,
            "name_only_identity": {
                "direct_identity": queue_row["direct_identity"],
                "relation_comparison": "NAME_ONLY",
                "backend_candidate_count": 0,
                "backend_candidate_absence_is_not_negative_proof": True,
            },
            "route_source": {
                "route_record_id": route_id,
                "route_file": route_row["route_file"],
                "route_file_sha256": route_row["route_file_sha256"],
                "route_file_blob_id": route_row["route_file_blob_id"],
                "source_key": route_row["source_key"],
                "source_anchor": route_row["source_anchor"],
                "route_method": route_row["route_method"],
                "literal_uri": route_row["literal_uri"],
                "literal_route_name": queue_row["source"]["literal_route_name"],
                "action_expression": route_row["action_expression"],
                "statement_excerpt": route_row["statement_excerpt"],
                "statement_sha256": route_row["statement_sha256"],
            },
            "controller_action": {
                "relation_class": "NAME_ONLY_EXACT_CONTROLLER_ACTION_REVIEW_CANDIDATE",
                "controller_fqcn": resolution["resolved_fqcn"],
                "primary_method_slice": primary,
                "transitive_local_helper_slices": helpers,
                "request_contracts": requests,
                "literal_inertia_page_callsites": pages,
                "literal_inertia_page_callsite_count": len(pages),
                "external_dependency_semantics_complete": False,
                "route_ownership_credit": False,
                "controller_action_bridge_credit": False,
                "page_ownership_credit": False,
            },
            "feature_identity_projection": feature_projection(matrix_by_id[feature_id]),
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
                "route_manifest_record_sha256": canonical_json_sha256(route_row),
                "route_candidate_record_sha256": canonical_json_sha256(candidate),
                "route_decision_sha256": canonical_json_sha256(decision),
                "primary_method_slice_sha256": primary["review_slice"]["text_sha256"],
                "local_support_sha256": canonical_json_sha256(helpers),
                "request_support_sha256": canonical_json_sha256(requests),
                "literal_page_context_sha256": canonical_json_sha256(pages),
            },
        }
        record["candidate_record_sha256"] = canonical_json_sha256(record)
        records.append(record)

    assert len(records) == 24
    assert len({row["queue_id"] for row in records}) == 24
    assert len({row["route_source"]["route_record_id"] for row in records}) == 24
    assert len({row["action_key"] for row in records}) == 24
    assert Counter(row["review_partition"] for row in records) == {"A": 8, "B": 8, "C": 8}
    assert Counter(row["run090_original_partition"] for row in records) == {"A": 6, "B": 8, "C": 10}
    feature_ids = {row["candidate_feature_id"] for row in records}
    new_feature_ids = feature_ids - current_owner_features
    assert feature_ids == {"CAP-FLEET-INCIDENT-RECORD", "CAP-RESP-HANDOVER-NOTES"}
    assert new_feature_ids == set()
    page_contexts = [page for row in records for page in row["controller_action"]["literal_inertia_page_callsites"]]
    assert len(page_contexts) == 7
    assert Counter(page["current_static_source_owner"] for page in page_contexts) == {True: 3, False: 4}
    assert Counter(page["run079_prompt_classification"] for page in page_contexts) == {
        "Reviewed": 3, "Evidence gap": 4,
    }
    assert canonical_list_sha256([str(row[0]) for row in SELECTED]) == "90dd77a83c80d36516435ad248bf9a2275543db64e1f5f32ab79ea829f12cc0d"
    assert canonical_list_sha256([row["queue_id"] for row in records]) == "ba71a9f4c6c6e9a1a15114a2d11a24fcfa6deb1c26731586b7e610c4c12c2477"
    assert canonical_list_sha256([row["queue_canonical_key"] for row in records]) == "82c669035537d18a36460e991ade5bc46a035d8bd10057b8b95d59b58c5527be"
    assert canonical_list_sha256([
        f"{row['queue_id']}|{row['queue_canonical_key']}" for row in records
    ]) == "b3219c7ea8431111f03fa0773e72ad5507566fec84a4dfad605979987a57ba65"
    assert canonical_list_sha256([
        row["route_source"]["source_key"] for row in records
    ]) == "3a8d7a7a7aa8c0f72cd0872d5d9c9cb3ee4ec350f3f9f02d33aa0304c3d2c20a"
    assert canonical_list_sha256([
        row["route_source"]["route_record_id"] for row in records
    ]) == "0dae88c87b7708facd8bd7e1182452860dddeefa4256d2672834c204293fe757"
    assert canonical_list_sha256([
        row["action_key"] for row in records
    ]) == "1c5e5b1935a4739271db4762fc513bc4733650af83167fb401a17f6cfbd231df"

    partitions: dict[str, dict[str, Any]] = {}
    expected_partition_hashes = {
        "A": {
            "queue": "61ee8e1baa883205dd22e6428c0401f7a90161076caaed0eee784c0289cd6049",
            "canonical": "4aef4e05db444ddee84521f0813b578f13e637a1718b01a79043be90f90b36d4",
            "pair": "d7b866fa4c5c6e759243a294f14c4d6f65832891b3b7fab4888c27bab6cefc1e",
            "action": "7f64bb48b616ba04df8b14f34aacd59d69ac9e97fedaf97d4148b0d7aebc72ff",
        },
        "B": {
            "queue": "22ab8f7a7f4e1d0bdf0fc2b99ed99bb88f453641ec8f16bc6209d1beb99f85e1",
            "canonical": "4bcf07fa4d36ba6ea6bdb6a52345d42c362cd1ceb8de4d562f962912e20ffc13",
            "pair": "e31eed94c366f25d3f6efc01bb4ad77e75e4e2aa87584ea75d6c5a4a824b77e4",
            "action": "39d5af4ee4ea6757ca589c6b492f31014d463164c59ed38379a125358b0aa982",
        },
        "C": {
            "queue": "e1473ca4f100857988b2777ead6c4ef69b819a9836f6322e093ea8102b940970",
            "canonical": "d4b0d45560d10e6d3b5823e20b17d2fc41b1a71ec7aefcc9b799d8e1b1918efc",
            "pair": "a36e9f3193408c525965c3bc29861a4c9e0b29d1cbe3810f4a3e394051a920e4",
            "action": "18cbf7e74c545ee78123b135309006202b9c3a51b69c1afab92b99b968338d1c",
        },
    }
    for partition in ("A", "B", "C"):
        assigned = [row for row in records if row["review_partition"] == partition]
        queue_hash = canonical_list_sha256([row["queue_id"] for row in assigned])
        canonical_hash = canonical_list_sha256([row["queue_canonical_key"] for row in assigned])
        pair_hash = canonical_list_sha256([
            f"{row['queue_id']}|{row['queue_canonical_key']}" for row in assigned
        ])
        action_hash = canonical_list_sha256([row["action_key"] for row in assigned])
        assert {"queue": queue_hash, "canonical": canonical_hash, "pair": pair_hash, "action": action_hash} == expected_partition_hashes[partition]
        partitions[partition] = {
            "assigned_candidates": 8,
            "candidate_ids": [row["candidate_id"] for row in assigned],
            "queue_id_list_sha256": queue_hash,
            "canonical_key_list_sha256": canonical_hash,
            "queue_pair_list_sha256": pair_hash,
            "action_key_list_sha256": action_hash,
            "fresh_reviewer_required": True,
        }

    return {
        "schema_version": "run-113-outcome-neutral-name-only-route-action-cohort-wave-16-v1",
        "run_id": "RUN-113-OUTCOME-NEUTRAL-NAME-ONLY-ROUTE-ACTION-COHORT-WAVE-16",
        "status": "TWENTY_FOUR_NAME_ONLY_EXACT_CONTROLLER_ACTION_CANDIDATES_PENDING_FRESH_REVIEW_ZERO_CREDIT",
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
            "Oblivion Findings is one operating organisation across multiple Sites. Legacy tenant_id values "
            "are not authorization boundaries. Static name/controller review never proves approved-Site reach, "
            "permissions, direct-object concealment, privacy, lifecycle correctness, runtime, or release."
        ),
        "selection_contract": {
            "outcome_neutral": True,
            "candidate_owner_projection_authorized": False,
            "rule": (
                "Freeze exactly 24 still-pending RUN-090 route surfaces with singleton exact literal-name identity, "
                "exact unique controller-method resolution, zero backend-anchor candidates, no contradictory backend "
                "candidate, no prior semantic review, no current owner, and no action-bridge collision."
            ),
            "name_only_rule": (
                "NAME_ONLY is weaker than independent lane convergence. Route-name identity and exact controller "
                "resolution justify fresh semantic review only; neither selects an ownership outcome."
            ),
            "page_rule": (
                "Literal Inertia callsites are review context only. This cohort authorizes zero page owners even "
                "when a reviewed controller action renders a page."
            ),
            "prohibited_inheritance": [
                "route prefix", "adjacency", "controller containment", "method name alone",
                "backend-anchor absence", "page ownership", "middleware", "navigation", "runtime",
            ],
        },
        "independent_preflight_review": {
            "task_path": "/root/run113_route_cohort",
            "review_type": "READ_ONLY_SELECTION_IDENTITY_COLLISION_AND_SEMANTIC_HAZARD_PREFLIGHT",
            "verdict": "GO_FREEZE_FOR_FRESH_OUTCOME_NEUTRAL_REVIEW_ZERO_OWNERSHIP_CREDIT",
            "wrote_files": False,
            "confirmed_name_only_rows": 24,
            "confirmed_backend_candidate_rows": 0,
            "confirmed_prior_review_or_owner_collisions": 0,
            "confirmed_existing_action_bridge_collisions": 0,
        },
        "semantic_review_focus": {
            "fleet_incident": [
                "create is a retired legacy redirect and therefore requires an explicit alias-versus-owner outcome",
                "index contains list, statistics, CSV export, modal detail, reporting, and option-data branches",
                "show returns JSON detail or redirects to index rather than rendering a separate page",
                "store has incident-journey, client-incident, safeguarding, signal, audit, and notification effects",
                "create/store are registered inside the fleet read middleware group while later writes use manage middleware",
                "query, option, arbitrary site_id, form-option, and direct-bound record scope require separate approved-Site review",
                "userCanManage response shaping is not permission or direct-object proof",
            ],
            "respite_handover": [
                "queries and direct-bound records require separate approved-Site and direct-object review",
                "sensitive_flag requires separate privacy review",
                "store, update, show, and acknowledge include audit or event side effects",
                "acknowledge is a check-then-update flow whose concurrency semantics require separate review",
            ],
            "policy_and_request_boundary": (
                "The selected actions use generic Request or route-bound models; the read-only preflight found no "
                "action-level authorize call or matching FleetIncident/RespiteHandoverNote policy. This is a review "
                "hazard, not a correctness finding, and grants no permission, Site, privacy, or lifecycle credit."
            ),
        },
        "counts": {
            "candidate_route_actions": 24,
            "candidate_route_records": 24,
            "candidate_controller_action_bridges": 24,
            "candidate_page_records": 0,
            "distinct_feature_ids": 2,
            "distinct_feature_ids_not_in_current_owner_set": 0,
            "fleet_incident_candidates": 16,
            "respite_handover_candidates": 8,
            "name_only_candidates": 24,
            "backend_candidate_rows": 0,
            "literal_page_callsites": 7,
            "literal_page_callsites_currently_owned": 3,
            "literal_page_callsites_current_evidence_gap": 4,
            "queue_pending_before": 447,
            "selected_pending_queue_surfaces": 24,
            "queue_unselected_pending": 423,
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
            "queue_id_canonical_key_pair_list_sha256": canonical_list_sha256([
                f"{row['queue_id']}|{row['queue_canonical_key']}" for row in records
            ]),
            "source_key_list_sha256": canonical_list_sha256([row["route_source"]["source_key"] for row in records]),
            "route_record_id_list_sha256": canonical_list_sha256([
                row["route_source"]["route_record_id"] for row in records
            ]),
            "feature_id_list_sha256": canonical_list_sha256(feature_ids),
            "new_feature_id_list_sha256": canonical_list_sha256(new_feature_ids),
            "action_key_list_sha256": canonical_list_sha256([row["action_key"] for row in records]),
            "candidate_record_sha256_list_sha256": canonical_list_sha256([
                row["candidate_record_sha256"] for row in records
            ]),
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
                "OWNER_ROUTE_ACTION", "SHARED_RELATION", "ALIAS_OR_REDIRECT",
                "DEAD_OR_NONCANONICAL", "EVIDENCE_GAP",
            ],
            "integration_rule": (
                "Only explicit OWNER_ROUTE_ACTION may add one route owner and one action bridge. Every other "
                "outcome adds neither; any reviewer conflict or unresolved external dependency becomes EVIDENCE_GAP."
            ),
            "page_owner_records_authorized": 0,
            "ownership_integration_authorized": False,
        },
        "outcome_neutral_conservation_contract": {
            "equation": "O + S + A + D + E = 24",
            "bounded_sources": "3929 = (614 + O) + (3315 - O)",
            "owner_surfaces": "614 + O = (265 + O) routes + 349 pages",
            "queue": "507 = (60 + 24 reviewed) + 423 pending",
            "queue_reviewed": "84 = (54 + O) owner + (3 + S) shared + (3 + A) alias + D dead + E gap",
            "queue_without_ownership": "453 - O = 423 pending + (3 + S) shared + (3 + A) alias + D dead + E gap",
            "route_universe": (
                "3218 = (265 + O) owner + (5 + S) shared + (3 + A) alias + D dead + "
                "(2945 - O - S - A - D) residual; E is a tagged subset of residual"
            ),
            "pages": "711 = 349 owner + 9 shared + 353 residual; one earlier gap remains tagged inside residual",
            "controller_action_bridges": "53 + O",
            "distinct_feature_ids": "256 regardless of O because both candidate FEATURE-IDs are already represented",
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
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/build-outcome-neutral-name-only-route-action-cohort-wave-16.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json",
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
        "review_partitions": {
            key: value["assigned_candidates"] for key, value in payload["review_partitions"].items()
        },
        "ownership_credit_awarded": payload["counts"]["ownership_credit_awarded"],
    }, indent=2))


if __name__ == "__main__":
    main()
