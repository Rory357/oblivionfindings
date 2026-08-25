#!/usr/bin/env python3
"""Freeze RUN-121's 22 Finance route/action candidates for fresh review.

RUN-117 explicitly deferred this bounded cohort until the four unfinished
Respite handover pages were reviewed.  RUN-118 through RUN-120 completed and
reported that boundary.  This producer therefore freezes every still-pending
RUN-090 CAP-FIN-CHART-OF-ACCOUNTS route row, preserves complete static action
evidence, and grants no ownership or downstream credit before fresh semantic
review.
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
BASE_GENERATOR = AUDIT_DIR / "generators/build-outcome-neutral-name-only-route-action-cohort-wave-16.py"
OUTPUT_PATH = AUDIT_DIR / "evidence/source/root-run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18.json"
PROMPT_PATH = Path(r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md")

CHECKPOINT_COMMIT = "1acb8b7c04dfcb492c6e65f4c2a3ed94410c3665"
CHECKPOINT_TREE = "0850c4397821487bd2fb60ad187eaf53baa12f92"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
FEATURE_ID = "CAP-FIN-CHART-OF-ACCOUNTS"

spec = importlib.util.spec_from_file_location("run113_base", BASE_GENERATOR)
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
literal_page_callsites = BASE.literal_page_callsites
feature_projection = BASE.feature_projection

APP_USE_RE = re.compile(r"^use\s+(App\\[^;]+);", re.MULTILINE)

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
    "run113_cohort": AUDIT_DIR / "evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json",
    "run113_review": AUDIT_DIR / "evidence/source/raw-run-113r-independent-outcome-neutral-name-only-route-action-review-wave-16.json",
    "run114_overlay": AUDIT_DIR / "evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json",
    "run117_cohort": AUDIT_DIR / "evidence/source/root-run-117-outcome-neutral-respite-handover-page-gap-cohort-wave-17.json",
    "run117_review": AUDIT_DIR / "evidence/source/raw-run-117r-independent-outcome-neutral-respite-handover-page-gap-review-wave-17.json",
    "run118_overlay": AUDIT_DIR / "evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json",
    "run119_reporting": AUDIT_DIR / "evidence/source/current-run-119-reviewed-respite-handover-page-gap-reporting-wave-17.json",
    "run120_dashboard": AUDIT_DIR / "evidence/browser/current-audit-dashboard-verification-run-120-wave-17.json",
}

EXPECTED_INPUT_SHA256 = {
    "base_generator": "9403a58b2949123daaf1b23fb1db7ea5060c81e595f725dbda2701fff680083f",
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
    "run113_cohort": "0849ca5c10e31a4f3e5133a521a570d60b004e723fb17a7f2e812e485f345461",
    "run113_review": "b52872c02b2a1b41861d9eb735eb363fd06cd1af645e1e6c0965b1b042333a83",
    "run114_overlay": "cbeb46be682ca0c9ca54012e2c27cf548b54e9cbb75b050fcd61691ca43aaef2",
    "run117_cohort": "e468e7e7736e49eea629b4faec1fdce94d7de30eee478b08c81b90793622bd2e",
    "run117_review": "264236eccceb279522fb784a7c27db2ecc8fd0434e4e5668c33fbe263f1cbc9b",
    "run118_overlay": "33dd2f8ffa2b35c6651a6bf18923872e70ba5c2f91fce8b7222666bb8a91fc8b",
    "run119_reporting": "d2f80b1649fd4f8eaf965986eaf5b85dc4c906364271dbbd6513fe68c315b694",
    "run120_dashboard": "0e3ed652833d0e78b3bca85a78cb23f69ddf511e4d1f32f3a8c0bf8dcf20482c",
}

EXPECTED_QUEUE_IDS = [f"RUN090-ROUTE-{number:04d}" for number in range(44, 66)]
EXPECTED_ROUTE_IDS = [
    "RUN077-ROUTE-0435", "RUN077-ROUTE-0436", "RUN077-ROUTE-0437", "RUN077-ROUTE-0438",
    "RUN077-ROUTE-0439", "RUN077-ROUTE-0440", "RUN077-ROUTE-0441", "RUN077-ROUTE-0442",
    "RUN077-ROUTE-0444", "RUN077-ROUTE-0445", "RUN077-ROUTE-0446", "RUN077-ROUTE-0447",
    "RUN077-ROUTE-0448", "RUN077-ROUTE-0450", "RUN077-ROUTE-0451", "RUN077-ROUTE-0452",
    "RUN077-ROUTE-0454", "RUN077-ROUTE-0455", "RUN077-ROUTE-0456", "RUN077-ROUTE-0458",
    "RUN077-ROUTE-0459", "RUN077-ROUTE-0460",
]


def request_contracts_for_slice(controller_file: str, slice_text: str) -> list[dict[str, Any]]:
    """Capture both top-level and domain-scoped application FormRequests."""
    source = (REPO / controller_file).read_text(encoding="utf-8-sig")
    contracts: list[dict[str, Any]] = []
    for fqcn in sorted(set(APP_USE_RE.findall(source))):
        short = fqcn.rsplit("\\", 1)[-1]
        if not re.search(rf"\b{re.escape(short)}\b", slice_text):
            continue
        relative = "app/" + fqcn.removeprefix("App\\").replace("\\", "/") + ".php"
        if "/Http/Requests/" not in relative or not (REPO / relative).is_file():
            continue
        contracts.append(request_contract(relative))
    return contracts


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
    assert PROMPT_PATH.is_file()
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


def partition_for(sequence: int) -> str:
    if sequence <= 8:
        return "A"
    if sequence <= 16:
        return "B"
    return "C"


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
    ownership = load_json(INPUT_PATHS["ownership_ledger"])
    queue = load_json(INPUT_PATHS["direct_queue"])
    run091 = load_json(INPUT_PATHS["run091_cohort"])
    run097 = load_json(INPUT_PATHS["run097_cohort"])
    run101 = load_json(INPUT_PATHS["run101_cohort"])
    run113 = load_json(INPUT_PATHS["run113_cohort"])
    run113_review = load_json(INPUT_PATHS["run113_review"])
    run117 = load_json(INPUT_PATHS["run117_cohort"])
    run117_review = load_json(INPUT_PATHS["run117_review"])
    run119 = load_json(INPUT_PATHS["run119_reporting"])
    run120 = load_json(INPUT_PATHS["run120_dashboard"])
    overlays = [
        load_json(INPUT_PATHS[name])
        for name in (
            "run092_overlay", "run098_overlay", "run102_overlay", "run106_overlay",
            "run110_overlay", "run114_overlay", "run118_overlay",
        )
    ]
    run118 = overlays[-1]

    assert candidate_review["verdict"]["decision"] == "GO"
    assert run113_review["decision"]["verdict"].startswith("GO_")
    assert run117_review["decision"]["verdict"] == "GO_4_EXPLICIT_OWNER_PAGE"
    assert run117["selection_contract"]["unfinished_boundary_rule"] == (
        "Complete this four-page boundary before starting the broader 22-row Finance route cohort."
    )
    assert run118["combined_counts"]["source_owner_records"] == 641
    assert run118["combined_counts"]["route_owner_records"] == 288
    assert run118["combined_counts"]["page_owner_records"] == 353
    assert run118["combined_counts"]["bounded_static_source_residual_records"] == 3288
    assert run118["combined_counts"]["static_controller_action_bridges"] == 76
    assert run118["combined_counts"]["distinct_feature_ids"] == 256
    assert run118["queue_accounting"]["reviewed_queue_surface_rows"] == 84
    assert run118["queue_accounting"]["owner_queue_surface_rows"] == 77
    assert run118["queue_accounting"]["shared_queue_surface_rows"] == 3
    assert run118["queue_accounting"]["alias_queue_surface_rows"] == 4
    assert run118["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 423
    assert run119["status"] == "REVIEWED_RESPITE_HANDOVER_PAGE_OWNER_OVERLAY_REPORTED_GATE_4_INCOMPLETE"
    assert run120["verification"]["state"] == "GO"
    assert run120["audit_completion_test_met"] is False

    route_rows = list(manifest["route_universe"]["primary_route_facade_callsites"])
    route_rows += list(manifest["route_universe"]["route_like_sentinels"])
    route_by_id = index_unique(route_rows, "route_record_id")
    decision_by_id = index_unique(classification["route_decisions"], "route_record_id")
    candidate_by_id = index_unique(
        candidates["route_static_candidate_census"]["records"], "route_record_id"
    )
    page_rows = list(manifest["page_universe"]["page_roots"])
    page_decision_by_id = index_unique(classification["page_decisions"], "page_record_id")

    current_owner_rows = list(ownership["records"])
    for overlay in overlays:
        current_owner_rows.extend(overlay["overlay_source_records"])
    current_owner_ids = {row["source_record_id"] for row in current_owner_rows}
    current_owner_features = {row["feature_id"] for row in current_owner_rows}
    assert len(current_owner_rows) == len(current_owner_ids) == 641
    assert len(current_owner_features) == 256
    assert FEATURE_ID in current_owner_features

    reviewed_route_ids = (
        cohort_route_ids(run091) | cohort_route_ids(run097) |
        cohort_route_ids(run101) | cohort_route_ids(run113)
    )
    assert len(reviewed_route_ids) == 82
    bridge_rows = list(overlays[0]["static_controller_action_bridges"])
    for overlay in overlays[1:]:
        bridge_rows.extend(overlay["new_static_controller_action_bridges"])
    assert len(bridge_rows) == 76
    existing_bridge_keys = {
        (row["controller_file"], row["method"], row["feature_id"]) for row in bridge_rows
    }

    selected = [
        (index, row) for index, row in enumerate(queue["records"])
        if row["candidate_feature_id"] == FEATURE_ID
    ]
    assert [index for index, _ in selected] == list(range(43, 65))
    assert [row["queue_id"] for _, row in selected] == EXPECTED_QUEUE_IDS
    assert [row["source_record_id"] for _, row in selected] == EXPECTED_ROUTE_IDS

    records: list[dict[str, Any]] = []
    for sequence, (queue_index, queue_row) in enumerate(selected, 1):
        route_id = queue_row["source_record_id"]
        partition = partition_for(sequence)
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
        assert candidate["name_relation"]["candidate_feature_ids"] == [FEATURE_ID]
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
        bridge_key = (resolution["controller_file"], resolution["method"], FEATURE_ID)
        assert bridge_key not in existing_bridge_keys

        action_key = f"{route_id}|{resolution['controller_file']}:{resolution['method']}|{FEATURE_ID}"
        record: dict[str, Any] = {
            "candidate_id": f"RUN121-FINANCE-CHART-ROUTE-ACTION-{sequence:02d}",
            "action_key": action_key,
            "review_partition": partition,
            "run090_original_partition": queue_row["review_partition"],
            "queue_index_zero_based": queue_index,
            "queue_id": queue_row["queue_id"],
            "queue_canonical_key": queue_row["canonical_key"],
            "candidate_feature_id": FEATURE_ID,
            "name_only_identity": {
                "direct_identity": queue_row["direct_identity"],
                "relation_comparison": "NAME_ONLY",
                "backend_candidate_count": 0,
                "backend_candidate_absence_is_not_negative_proof": True,
                "single_feature_projection_does_not_preselect_semantic_ownership": True,
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
            "feature_identity_projection": feature_projection(matrix_by_id[FEATURE_ID]),
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

    assert len(records) == 22
    assert len({row["queue_id"] for row in records}) == 22
    assert len({row["route_source"]["route_record_id"] for row in records}) == 22
    assert len({row["action_key"] for row in records}) == 22
    assert Counter(row["review_partition"] for row in records) == {"A": 8, "B": 8, "C": 6}
    assert Counter(row["run090_original_partition"] for row in records) == {"A": 7, "B": 9, "C": 6}
    assert {row["candidate_feature_id"] for row in records} == {FEATURE_ID}
    assert all(row["name_only_identity"]["backend_candidate_count"] == 0 for row in records)

    page_contexts = [
        page for row in records for page in row["controller_action"]["literal_inertia_page_callsites"]
    ]
    controller_counts = Counter(
        row["controller_action"]["primary_method_slice"]["source_file"] for row in records
    )
    assert controller_counts == {
        "app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php": 7,
        "app/Domain/Finance/Http/Controllers/CostCentreController.php": 3,
        "app/Domain/Finance/Http/Controllers/FiscalPeriodController.php": 3,
        "app/Domain/Finance/Http/Controllers/FundingStreamController.php": 3,
        "app/Domain/Finance/Http/Controllers/JournalController.php": 5,
        "app/Domain/Finance/Http/Controllers/LedgerController.php": 1,
    }

    partitions: dict[str, dict[str, Any]] = {}
    for partition in ("A", "B", "C"):
        assigned = [row for row in records if row["review_partition"] == partition]
        partitions[partition] = {
            "assigned_candidates": len(assigned),
            "candidate_ids": [row["candidate_id"] for row in assigned],
            "queue_id_list_sha256": canonical_list_sha256([row["queue_id"] for row in assigned]),
            "action_key_list_sha256": canonical_list_sha256([row["action_key"] for row in assigned]),
            "controller_files": sorted({
                row["controller_action"]["primary_method_slice"]["source_file"] for row in assigned
            }),
            "fresh_reviewer_required": True,
        }

    feature_ids = {row["candidate_feature_id"] for row in records}
    new_feature_ids = feature_ids - current_owner_features
    assert new_feature_ids == set()
    identity = {
        "queue_index_list_sha256": canonical_list_sha256([
            str(row["queue_index_zero_based"]) for row in records
        ]),
        "queue_id_list_sha256": canonical_list_sha256([row["queue_id"] for row in records]),
        "canonical_key_list_sha256": canonical_list_sha256([
            row["queue_canonical_key"] for row in records
        ]),
        "route_record_id_list_sha256": canonical_list_sha256([
            row["route_source"]["route_record_id"] for row in records
        ]),
        "source_key_list_sha256": canonical_list_sha256([
            row["route_source"]["source_key"] for row in records
        ]),
        "action_key_list_sha256": canonical_list_sha256([row["action_key"] for row in records]),
        "feature_id_list_sha256": canonical_list_sha256(feature_ids),
        "candidate_record_sha256_list_sha256": canonical_list_sha256([
            row["candidate_record_sha256"] for row in records
        ]),
        "records_sha256": canonical_json_sha256(records),
    }

    return {
        "schema_version": "run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18-v1",
        "run_id": "RUN-121-OUTCOME-NEUTRAL-FINANCE-CHART-ROUTE-ACTION-COHORT-WAVE-18",
        "status": "TWENTY_TWO_FINANCE_CHART_NAME_ONLY_ROUTE_ACTION_CANDIDATES_PENDING_FRESH_REVIEW_ZERO_CREDIT",
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
            "Oblivion Findings is one operating organisation across multiple Sites. Roles, permissions, "
            "approved Sites, canonical record ownership, direct-object concealment, privacy, and ledger "
            "integrity are the boundaries. Static name/controller review proves none of them."
        ),
        "selection_contract": {
            "outcome_neutral": True,
            "candidate_owner_projection_authorized": False,
            "rule": (
                "After RUN-118 through RUN-120 completed the deferred four-page boundary, freeze all and only "
                "the 22 still-pending RUN-090 CAP-FIN-CHART-OF-ACCOUNTS route surfaces in queue order. Require "
                "singleton exact literal-name identity, exact unique controller-method resolution, zero backend-"
                "anchor candidates, no contradiction, no prior review, no current owner, and no action-bridge collision."
            ),
            "semantic_spread_rule": (
                "The 22 names span a ledger dashboard, account CRUD, journals, fiscal periods, cost centres, and "
                "funding streams. That spread is a semantic hazard to adjudicate, not evidence that one route owner "
                "or one user job has already been proved."
            ),
            "name_only_rule": (
                "NAME_ONLY is weaker than independent lane convergence. Exact route-name identity and controller "
                "resolution justify fresh semantic review only and select no outcome."
            ),
            "page_rule": (
                "Literal Inertia callsites are context only. This cohort authorizes zero page owners and cannot "
                "inherit the existing accounts page owner."
            ),
            "prohibited_inheritance": [
                "route prefix", "adjacency", "controller containment", "method name alone",
                "backend-anchor absence", "existing page ownership", "middleware", "navigation",
                "benchmark", "runtime",
            ],
        },
        "semantic_review_focus": {
            "account_boundary": [
                "distinguish the ledger dashboard from chart-of-accounts maintenance",
                "read create/store/show/edit/update/destroy actions and request or service boundaries completely",
                "treat posting-history and delete guards as ledger-integrity evidence requiring separate correctness review",
            ],
            "journal_and_period_boundary": [
                "distinguish journal creation/posting/reversal from maintaining ledger accounts",
                "inspect transaction, balancing, posted-state, reversal, locking, and period-close semantics",
                "do not infer financial correctness or canonical ownership from a shared finance route group",
            ],
            "cost_centre_and_funding_boundary": [
                "distinguish cost-centre and funding-stream lifecycle jobs from account maintenance",
                "inspect direct-bound record scope, delete guards, cross-module references, and audit effects",
            ],
            "authorization_and_site_boundary": (
                "Middleware, request validation, policies, Site scope, direct-object concealment, finance authority, "
                "and ledger correctness remain separate audit questions and receive zero credit here."
            ),
        },
        "counts": {
            "candidate_route_actions": 22,
            "candidate_route_records": 22,
            "candidate_controller_action_bridges": 22,
            "candidate_page_records": 0,
            "distinct_feature_ids": 1,
            "distinct_feature_ids_not_in_current_owner_set": 0,
            "name_only_candidates": 22,
            "backend_candidate_rows": 0,
            "controller_files": len(controller_counts),
            "literal_page_callsites": len(page_contexts),
            "literal_page_callsites_currently_owned": sum(
                1 for page in page_contexts if page["current_static_source_owner"]
            ),
            "literal_page_callsites_unowned": sum(
                1 for page in page_contexts if not page["current_static_source_owner"]
            ),
            "queue_pending_before": 423,
            "selected_pending_queue_surfaces": 22,
            "queue_unselected_pending": 401,
            "ownership_credit_awarded": 0,
            "page_ownership_credit_awarded": 0,
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
            "reviewers_must_be_fresh_from_discovery_producer": True,
            "required_outcome_per_candidate": True,
            "allowed_outcomes": [
                "OWNER_ROUTE_ACTION", "SHARED_RELATION", "ALIAS_OR_REDIRECT",
                "DEAD_OR_NONCANONICAL", "EVIDENCE_GAP",
            ],
            "integration_rule": (
                "Only explicit OWNER_ROUTE_ACTION may add one route owner and one controller-action bridge. "
                "Every other outcome adds neither. Reviewer conflict, partial semantics, or unresolved external "
                "dependency becomes EVIDENCE_GAP."
            ),
            "page_owner_records_authorized": 0,
            "ownership_integration_authorized": False,
        },
        "outcome_neutral_conservation_contract": {
            "equation": "O + S + A + D + E = 22",
            "bounded_sources": "3929 = (641 + O) + (3288 - O)",
            "owner_surfaces": "641 + O = (288 + O) routes + 353 pages",
            "queue": "507 = (84 + 22 reviewed) + 401 pending",
            "queue_reviewed": "106 = (77 + O) owner + (3 + S) shared + (4 + A) alias + D dead + E gap",
            "queue_without_ownership": "430 - O = 401 pending + (3 + S) shared + (4 + A) alias + D dead + E gap",
            "route_universe": (
                "3218 = (288 + O) owner + (5 + S) shared + (4 + A) alias + D dead + "
                "(2921 - O - S - A - D) residual; E is tagged within residual"
            ),
            "pages": "711 = 353 owner + 9 shared + 349 residual; one earlier gap remains tagged within residual",
            "controller_action_bridges": "76 + O",
            "distinct_feature_ids": "256 regardless of O because the candidate FEATURE-ID is already represented",
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
            "direct_object_concealment": False,
            "privacy_correctness": False,
            "ledger_integrity_correctness": False,
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
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/build-outcome-neutral-finance-chart-route-action-cohort-wave-18.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/root-run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18.json",
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
