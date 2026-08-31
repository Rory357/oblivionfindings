#!/usr/bin/env python3
"""Integrate the one bounded RUN189R Fleet trip-playback route/action owner."""
from __future__ import annotations

from collections import Counter
from decimal import Decimal, ROUND_HALF_UP
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
from typing import Any


sys.dont_write_bytecode = True
REPO = Path(__file__).resolve().parents[4]
AUDIT = Path(__file__).resolve().parents[1]
PREFIX = AUDIT.relative_to(REPO).as_posix()
GENERATOR = "generators/integrate-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-wave-37.py"
OUTPUT = "evidence/source/current-run-190-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-wave-37.json"

HEAD = "e32dcf40d2c4e648464d73524704c3025ffe0651"
TREE = "d3bd0be17614790e2bf971d848ff6228b067b173"
FIRST_PARENT = "c055397fd9a8bd57ab5757f0e120c43fb9a14da1"
SECOND_PARENT = "b5ce255019294c3a0423b0adb53c3d0fa7bab467"
REVIEWED_APPLICATION_COMMIT = "d991b2898b70409ce7c019abe9ddbd8394e0b595"
REVIEWED_APPLICATION_TREE = "46074d7ec2b2a75b6fc4c3fa67187d5b908de79a"
CURRENT_SUBTREES = {
    "app": "030e29afb6bf375a98461316aeb13c360a92a538",
    "routes": "b62a85f59ba5f45a54fd666b3199a65453034272",
    "resources/js": "8a851516cdb76ded362fb5912e3e930e45c8df86",
    "resources/js/pages": "8ad1ecc5817310f2f45c64733ca72d771c798a2f",
    "tests": "56f1d25aa65aa7c0b925ce46f36a921bc126b273",
    "database": "d7ba5adb39f5dc79427a3d6946f719b2be3f41dc",
    "bootstrap": "df6189abe5ab5343d88674c199c4ce46e6152a57",
    "docs/architecture": "3444047114f5f446954b032dedc4e0c7892180bd",
    "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24": "d55f1c4dbf8d18230806a0989efcab1185997890",
}

GOVERNING_PROMPT = {
    "path": r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md",
    "sha256": "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f",
    "role": "GOVERNING_AUDIT_PROMPT",
}
CONTINUATION_REQUEST = {
    "path": r"C:\Users\steph\.codex\attachments\8b35b9fe-b295-4a84-bdf9-a8afb05b2daa\pasted-text-1.txt",
    "sha256": "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d",
    "role": "CONTINUATION_REQUEST_ONLY",
    "is_governing_prompt": False,
}

BASE_COLLECTOR = "generators/integrate-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.py"
RUN149 = "evidence/source/current-run-149-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.json"
RUN153 = "evidence/source/current-run-153-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.json"
RUN153R = "evidence/source/current-run-153r-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.json"
RUN170_GENERATOR = "generators/integrate-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.py"
RUN170 = "evidence/source/current-run-170-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.json"
RUN170R = "evidence/source/current-run-170r-independent-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-review-wave-31.json"
RUN180_GENERATOR = "generators/integrate-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-wave-34.py"
RUN180 = "evidence/source/current-run-180-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-wave-34.json"
RUN180R = "evidence/source/current-run-180r-independent-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-review-wave-34.json"
RUN181 = "evidence/source/current-run-181-reviewed-fleet-trip-index-route-action-reporting-wave-34.json"
RUN182 = "evidence/browser/current-audit-dashboard-verification-run-182-wave-34.json"
QUEUE = "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json"
MATRIX = "03-feature-to-benchmark-matrix.csv"
RUN189_GENERATOR = "generators/build-outcome-neutral-fleet-trip-playback-route-action-cohort-wave-37.py"
RUN189 = "evidence/source/root-run-189-outcome-neutral-fleet-trip-playback-route-action-cohort-wave-37.json"
RUN189R_GENERATOR = "generators/materialize-independent-outcome-neutral-fleet-trip-playback-route-action-review-wave-37.py"
RUN189R = "evidence/source/raw-run-189r-independent-outcome-neutral-fleet-trip-playback-route-action-review-wave-37.json"

EXPECTED = {
    BASE_COLLECTOR: "b5c7f04cd44ecd73dda9c7fe4a9e2e8616c68674cdc52d393ec696b06ad2327e",
    RUN149: "12a52c434ecd18a5c6a644378070aa5ab046f5e7080726b983ded8d9c7377a55",
    RUN153: "9b7e382f83787d807de8d752ecb3e6524280c707899aba78d47082765272e815",
    RUN153R: "7f1da8394a8054f01f34fb943a3fba6601bf70ea06d69cf97033f2208edf4461",
    RUN170_GENERATOR: "c732926f3112c987fbaaf3f398bc18b3d25027c7f1495c38016237a5cb6f28a3",
    RUN170: "c739a36e1975b60d42988be3de36b9fe1ea88cf942752c90112f40ebaa04cd8d",
    RUN170R: "62474100b0c2f027fa0c15f2bb841f08ad3de058da67725a931fcafec17dd139",
    RUN180_GENERATOR: "cdbeeae65d0d5d928d6356de7c2433d437b6f2bae9fd80bb7a942b97d41f6594",
    RUN180: "49b0bd12abbd4dd2b9ce0dbe9b6fd60ab79eea92861f6339407fbd05f0b7c925",
    RUN180R: "c6038caa557277124cb58056a2882ce41d1f2ee402f91effb0e6bfab6fe95d96",
    RUN181: "c1db8b498b7344c2ab28f5c6373caaa8f2ac4a1d764e6129fb49c415234794a8",
    RUN182: "d3dc3ef6e842f0b5df74b27948ac6ef8abfda205516f6ac9b6a5d9c9858cd81e",
    QUEUE: "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    MATRIX: "3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0",
    RUN189_GENERATOR: "ccb18a24eb698084736d3851fa1d205e6061e645faabd86400f8e0da50f05774",
    RUN189: "77b58c6c97153793bd1eda12107ee174d8ea926ede0de940918e42d411d51734",
    RUN189R_GENERATOR: "ee22f7dbbf652f326edfa14af0ba23985295c2ac782a26b6370b3acaca5ffa84",
    RUN189R: "2723e1a066fa6fda53f9d9f582903b5b7dea607b88b03edc490091318800903d",
}
EXPECTED_BLOBS = {
    BASE_COLLECTOR: "e8d9d1c9889be589a22db6dfea53d3122adce247",
    RUN149: "c5f0a3bda99167f66650d63bfdb35e18d8ed93b5",
    RUN153: "818b891cff9965193c60d83d0580c21a48d1a682",
    RUN153R: "20bb3580ba2cb60205694d52aa72e16cd2f2a423",
    RUN170_GENERATOR: "2603b130a0a674e6803413583c95b51bc3f83545",
    RUN170: "8cff90e1e86e5752cbfc3e59d03ccc5423e23ed6",
    RUN170R: "fbcccd7e19ea57db52a1d6ca462aa107107159d1",
    RUN180_GENERATOR: "f3bd1cae87ff0b9f74bd1be8d5e963db91cd0813",
    RUN180: "b9d3d623d22e7ee8cad21fca62d703cd5881b0a9",
    RUN180R: "4ee55ff346533b39848a69c8fe62347b4519d8ca",
    RUN181: "25cdf8834fcdd6fe966fb5d660c1b94cf479f585",
    RUN182: "3efd68bae4f397beb56293adadef0c42621e7acd",
    QUEUE: "66809274d25916f4e0d2426419bfde6e371ba1f1",
    MATRIX: "1f5fdab3ae80ae4ec1b9bc4ee47eef695bdd5416",
    RUN189_GENERATOR: "01b8b63b676237c3fcba1bd44a5d8012d6edb817",
    RUN189: "e0721374fd269240828fece0e8b867f7e25820a9",
    RUN189R_GENERATOR: "77fc107b5781be19b38936f4152f2440f9116f3a",
    RUN189R: "5cee0253416ce3f0d6f4a63e12a0c4bc693f7817",
}

RUN189_PACKET_SELF_SEAL = "11d39b08909e6ad3ecbec73c824f26ec82f7bf226440d69df4667e84395cb13d"
RUN189R_SELF_SEAL = "878d244b7b5eed9010d8e152e693840f82ee9dc14a32a56c17010329fae20d3f"
SEMANTIC_REVIEW_A = "0247add20542aa4578ca20747796e01d6528b0b7b01fb93486ccba09253d728d"
SEMANTIC_REVIEW_B = "469fa03b2a408f95aca7636fae7a4ab0a16ff53cf38c69db7584a6e9d164cd17"
SEMANTIC_CONSENSUS = "d9f89cb1c19d9894150d1df63fdd85482cdcf0a66fb2fe37297f2757aa810227"
RUN190_COMPUTED_DECISION_HASH = "b3456ba2d566a616dbd314c50c4b9a4f8ed019193ecb60076e668cc046d27a7f"
RUN189R_LATER_OVERLAY_CONTRACT_HASH = "69507753b418b28c401c3bef15530581f3141e123d8eeb25a08f21c427685ca6"
CANDIDATE_HASH = "b188512066f812df668c88c4ce38d02e2af8b9a972d04decf41c60b0aa2df64c"
QUEUE_RECORD_HASH = "f9df043e4557240020de213961c847fb56b8cd0e2d9b9144ec0b7a877ff84943"
PRIOR_SOURCE_IDENTITY = "1648a470ca0293c4c065b30925b8eda5a9f78d35fa64935e644a3354e17cdbba"
PRIOR_BRIDGE_IDENTITY = "6ab1b8c1045ac6c159ba4aa5856ac58e648263a530f4f7c3031e4eed5d84fa32"
PRIOR_REVIEWED_IDENTITY = "5dbcecd3986300fe255fdb75efe6013c07f3adc4071745ebebf0c4a525ee99c9"
PRIOR_REVIEWED_CANONICAL = "738c7836dd770e12d67de62d4f28441825814d619bb641e070e25468786fb75e"
COMBINED_SOURCE_IDENTITY = "2e5f82279c71860a6fc2576859fb4351a6e3fbd3010f7c9f2fe598b48facf5a6"
COMBINED_BRIDGE_IDENTITY = "354fd9239de4233eff3e1b20b7df5c2c519e11c8a90b88490cab9513e9f1b42c"
COMBINED_REVIEWED_IDENTITY = "2329f613c5310950191a5206fd764a78afc9e6f5bf0d502d0a65751a580f1393"
COMBINED_REVIEWED_CANONICAL = "5d45c0c6b47770e42d68f6d1ee44c82774346b5d8909648c85ce74b793c02c8e"
FINAL_ARTIFACT_REVIEW_A = "d67eef3feefadecaaef269d22398879dc04cb550d275fa0a2c8d7a7b8520bf0b"
FINAL_ARTIFACT_REVIEW_B = "2b117150aa4b3152a0f576536210e082f15f91414f1ede48dc8d5c5804167009"
OVERLAY_ROW_SEAL = "31a2f128dacd47d73377db8422e2d89448909d9f4d98fe8089fa0522cb0ddfb2"
BRIDGE_ROW_SEAL = "a8934922a42c1270c62276c2dc345066372a8ea73fa3ca0875cd3c75020fc5c9"


def run(*args: str) -> bytes:
    return subprocess.run(args, cwd=REPO, check=True, capture_output=True).stdout


def git(*args: str) -> str:
    return run("git", *args).decode("utf-8").rstrip("\r\n")


def digest(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def audit_sha(relative: str) -> str:
    return digest((AUDIT / relative).read_bytes())


def canonical(value: Any) -> str:
    return digest(
        json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode()
    )


def hlist(values: list[str] | set[str]) -> str:
    return digest("\n".join(sorted(set(values))).encode())


def strict_json(relative: str) -> dict[str, Any]:
    def hook(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, (relative, key)
            result[key] = value
        return result

    value = json.loads(
        (AUDIT / relative).read_text(encoding="utf-8"), object_pairs_hook=hook
    )
    assert isinstance(value, dict)
    return value


def sealed(record: dict[str, Any], field: str) -> dict[str, Any]:
    record[field] = canonical(record)
    return record


def verify_seal(record: dict[str, Any], field: str, expected: str) -> None:
    value = record[field]
    actual = value["sha256"] if isinstance(value, dict) else value
    assert actual == expected
    assert actual == canonical({key: item for key, item in record.items() if key != field})


def assert_exact_dirty_set() -> None:
    expected = {f"?? {PREFIX}/{GENERATOR}"}
    if (AUDIT / OUTPUT).exists():
        expected.add(f"?? {PREFIX}/{OUTPUT}")
    actual = {line for line in git("status", "--porcelain").splitlines() if line}
    assert actual == expected, (actual, expected)


def normalized_final_review(
    review_id: str,
    reviewer_task: str,
    role: str,
    checks: int | None,
) -> dict[str, Any]:
    return sealed(
        {
            "review_id": review_id,
            "reviewer_task": reviewer_task,
            "review_role": role,
            "verdict": "GO",
            "discrepancies": 0,
            "read_only": True,
            "generator_or_application_execution": False,
            "producer_supplied_payload_hash_claimed": False,
            "independent_check_count_reported": checks,
            "reviewed_artifacts": {
                "run189_generator_sha256": EXPECTED[RUN189_GENERATOR],
                "run189_receipt_sha256": EXPECTED[RUN189],
                "run189_receipt_self_seal_sha256": RUN189_PACKET_SELF_SEAL,
                "run189r_generator_sha256": EXPECTED[RUN189R_GENERATOR],
                "run189r_receipt_sha256": EXPECTED[RUN189R],
                "run189r_receipt_self_seal_sha256": RUN189R_SELF_SEAL,
            },
            "reviewed_semantic_records": [SEMANTIC_REVIEW_A, SEMANTIC_REVIEW_B],
            "reviewed_semantic_consensus": SEMANTIC_CONSENSUS,
            "bounded_later_overlay": {
                "maximum_route_owner_rows": 1,
                "maximum_controller_action_bridges": 1,
                "page_or_sibling_rows": 0,
                "current_materialization_before_run190": False,
                "current_queue_advance_before_run190": False,
                "correctness_runtime_or_completion_credit": False,
            },
        },
        "review_record_sha256",
    )


def validate_inputs() -> tuple[dict[str, Any], ...]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == HEAD
    assert git("rev-parse", "HEAD^{tree}") == TREE
    assert git("rev-parse", "HEAD^1") == FIRST_PARENT
    assert git("rev-parse", "HEAD^2") == SECOND_PARENT
    assert git("rev-parse", f"{REVIEWED_APPLICATION_COMMIT}^{{tree}}") == REVIEWED_APPLICATION_TREE
    for path, expected in CURRENT_SUBTREES.items():
        assert git("rev-parse", f"HEAD:{path}") == expected, path
    assert_exact_dirty_set()

    for relative, expected in EXPECTED.items():
        assert audit_sha(relative) == expected, relative
        assert git("rev-parse", f"HEAD:{PREFIX}/{relative}") == EXPECTED_BLOBS[relative]
    assert digest(Path(GOVERNING_PROMPT["path"]).read_bytes()) == GOVERNING_PROMPT["sha256"]
    assert digest(Path(CONTINUATION_REQUEST["path"]).read_bytes()) == CONTINUATION_REQUEST["sha256"]
    assert GOVERNING_PROMPT["sha256"] != CONTINUATION_REQUEST["sha256"]

    run149 = strict_json(RUN149)
    run153 = strict_json(RUN153)
    run153r = strict_json(RUN153R)
    run170 = strict_json(RUN170)
    run170r = strict_json(RUN170R)
    run180 = strict_json(RUN180)
    run180r = strict_json(RUN180R)
    run181 = strict_json(RUN181)
    run182 = strict_json(RUN182)
    packet = strict_json(RUN189)
    review = strict_json(RUN189R)

    verify_seal(packet, "self_seal", RUN189_PACKET_SELF_SEAL)
    verify_seal(review, "self_seal", RUN189R_SELF_SEAL)
    assert run180["combined_counts"]["source_owner_records"] == 666
    assert run180["combined_counts"]["static_controller_action_bridges"] == 97
    assert run180["queue_accounting"]["reviewed_queue_surface_rows"] == 120
    assert run180["identity"]["combined_source_record_key_list_sha256"] == PRIOR_SOURCE_IDENTITY
    assert run180["identity"]["combined_bridge_key_list_sha256"] == PRIOR_BRIDGE_IDENTITY
    assert run180["identity"]["combined_reviewed_queue_key_list_sha256"] == PRIOR_REVIEWED_IDENTITY
    assert run180r["decision"]["verdict"].startswith("GO_")
    assert run181["queue_boundary"]["next_unresolved_index"] == 85
    assert run182["static_ownership_boundary"]["next_zero_based_index"] == 85

    assert len(packet["records"]) == 1
    candidate = packet["records"][0]
    assert canonical(candidate) == CANDIDATE_HASH
    assert candidate["queue_record_sha256"] == QUEUE_RECORD_HASH
    assert candidate["canonical_key"] == "route|RUN077-ROUTE-0694"
    assert candidate["source"]["literal_route_name"] == "fleet-assets.trips.playback"
    assert candidate["source"]["action_expression"] == "[FleetTripController::class, 'show']"
    assert review["decision"]["outcome"] == "OWNER_ROUTE_ACTION"
    assert review["decision"]["ownership_credit_materialized"] is False
    assert review["decision"]["queue_advance_materialized"] is False
    assert canonical(review["decision"]) == RUN190_COMPUTED_DECISION_HASH
    assert canonical(review["later_overlay_contract"]) == RUN189R_LATER_OVERLAY_CONTRACT_HASH
    semantic_reviews = review["semantic_reviews"]
    assert len(semantic_reviews) == 2
    assert [item["review_record_sha256"] for item in semantic_reviews] == [
        SEMANTIC_REVIEW_A,
        SEMANTIC_REVIEW_B,
    ]
    assert len({item["reviewer_task"] for item in semantic_reviews}) == 2
    assert {item["outcome"] for item in semantic_reviews} == {"OWNER_ROUTE_ACTION"}
    assert review["semantic_review_consensus"]["synthesis_record_sha256"] == SEMANTIC_CONSENSUS
    assert review["semantic_review_consensus"]["dissent_present"] is False
    assert review["later_overlay_contract"]["maximum_new_route_owner_rows"] == 1
    assert review["later_overlay_contract"]["maximum_new_controller_action_bridges"] == 1
    assert review["later_overlay_contract"]["page_owner_rows"] == 0
    assert review["later_overlay_contract"]["sibling_data_route_rows"] == 0

    reviewed_sources = packet["source_review_packet"]["required_source_files"]
    assert len(reviewed_sources) == 9
    for item in reviewed_sources:
        path = item["path"]
        assert git("rev-parse", f"{REVIEWED_APPLICATION_COMMIT}:{path}") == item["blob_id"]
        assert git("rev-parse", f"HEAD:{path}") == item["blob_id"]
    assert git("rev-parse", f"{REVIEWED_APPLICATION_COMMIT}:app") != CURRENT_SUBTREES["app"]
    assert git("rev-parse", f"{REVIEWED_APPLICATION_COMMIT}:tests") != CURRENT_SUBTREES["tests"]
    return (
        run149,
        run153,
        run153r,
        run170,
        run170r,
        run180,
        run180r,
        run181,
        run182,
        packet,
        review,
    )


def main() -> None:
    (
        run149,
        run153,
        run153r,
        run170,
        run170r,
        run180,
        run180r,
        run181,
        run182,
        packet,
        review,
    ) = validate_inputs()
    candidate = packet["records"][0]
    feature = candidate["feature_identity_projection"]
    current_route = packet["current_source_reconciliation"]["current_main_route_source"]
    current_controller = packet["current_source_reconciliation"]["current_main_controller_resolution"]
    method_slice = packet["source_review_packet"]["selected_controller_action_and_context_slices"]["show"]
    original_resolution = candidate["secondary_lane"]["backend_method_relation"]["resolution"]
    semantic_reviews = review["semantic_reviews"]
    final_reviews = [
        normalized_final_review(
            "RUN189R-FINAL-ARTIFACT-A",
            "/root/run189_cursor_plan",
            "FINAL_EXACT_ARTIFACT_REVIEWER_A_INDEPENDENT_OF_SEMANTIC_REVIEWERS_AND_REVIEW_B",
            None,
        ),
        normalized_final_review(
            "RUN189R-FINAL-ARTIFACT-B",
            "/root/run189_source_scope",
            "FINAL_EXACT_ARTIFACT_REVIEWER_B_INDEPENDENT_OF_SEMANTIC_REVIEWERS_AND_REVIEW_A",
            142,
        ),
    ]
    assert len({item["reviewer_task"] for item in final_reviews}) == 2
    assert all(item["verdict"] == "GO" and item["discrepancies"] == 0 for item in final_reviews)
    assert [item["review_record_sha256"] for item in final_reviews] == [
        FINAL_ARTIFACT_REVIEW_A,
        FINAL_ARTIFACT_REVIEW_B,
    ]

    row = sealed(
        {
            "overlay_mapping_id": "RUN190-ROUTE-01",
            "candidate_record_sha256": CANDIDATE_HASH,
            "queue_record_self_seal_sha256": QUEUE_RECORD_HASH,
            "review_receipt_self_seal_sha256": RUN189R_SELF_SEAL,
            "semantic_consensus_record_sha256": SEMANTIC_CONSENSUS,
            "run190_computed_decision_sha256": RUN190_COMPUTED_DECISION_HASH,
            "run189r_later_overlay_contract_sha256": RUN189R_LATER_OVERLAY_CONTRACT_HASH,
            "surface": "ROUTE_SOURCE_RECORD",
            "source_record_id": "RUN077-ROUTE-0694",
            "source_record_key": "route|RUN077-ROUTE-0694|CAP-FLEET-VEHICLE-REGISTER",
            "feature_id": "CAP-FLEET-VEHICLE-REGISTER",
            "feature_class": feature["feature_class"],
            "module": feature["module"],
            "user_job": feature["user_job"],
            "source": candidate["source"],
            "source_provenance": {
                "immutable_queue_route_file_sha256": candidate["source"]["route_file_sha256"],
                "immutable_queue_route_file_blob_id": candidate["source"]["route_file_blob_id"],
                "current_review_route_file_sha256": current_route["route_file_sha256"],
                "current_review_route_file_blob_id": current_route["route_file_blob_id"],
                "exact_statement_preserved_across_drift": True,
                "historical_hash_not_presented_as_current": True,
            },
            "review_outcome": "OWNER_ROUTE_ACTION",
            "accepted_semantic_review_ids": [item["review_id"] for item in semantic_reviews],
            "accepted_semantic_review_record_sha256s": [
                item["review_record_sha256"] for item in semantic_reviews
            ],
            "accepted_final_exact_artifact_review_record_sha256s": [
                item["review_record_sha256"] for item in final_reviews
            ],
            "semantic_dissent_present": False,
            "static_source_feature_ownership_credit": True,
            "sibling_data_route_ownership_credit": False,
            "credit_boundary": {
                key: False
                for key in (
                    "page_ownership",
                    "adjacent_route_ownership",
                    "frontend_caller_or_redirect_ownership",
                    "service_model_helper_or_test_ownership",
                    "framework_route_reachability",
                    "canonical_object_ownership_correctness",
                    "approved_site_scope_correctness",
                    "permission_correctness",
                    "privacy_correctness",
                    "direct_object_concealment_correctness",
                    "runtime",
                    "database",
                    "build",
                    "application_browser",
                    "executed_tests",
                    "benchmark",
                    "final_no_match_or_NCM",
                    "final_finding",
                    "completion",
                    "gate_4",
                    "publication",
                    "audit_complete",
                )
            },
        },
        "overlay_row_sha256",
    )

    bridge = sealed(
        {
            "bridge_id": "RUN190-BRIDGE-01",
            "candidate_record_sha256": CANDIDATE_HASH,
            "queue_record_self_seal_sha256": QUEUE_RECORD_HASH,
            "review_receipt_self_seal_sha256": RUN189R_SELF_SEAL,
            "semantic_consensus_record_sha256": SEMANTIC_CONSENSUS,
            "run190_computed_decision_sha256": RUN190_COMPUTED_DECISION_HASH,
            "accepted_semantic_review_record_sha256s": [
                item["review_record_sha256"] for item in semantic_reviews
            ],
            "accepted_final_exact_artifact_review_record_sha256s": [
                item["review_record_sha256"] for item in final_reviews
            ],
            "feature_id": "CAP-FLEET-VEHICLE-REGISTER",
            "route_record_id": "RUN077-ROUTE-0694",
            "controller_fqcn": original_resolution["resolved_fqcn"],
            "controller_file": method_slice["source_file"],
            "controller_file_sha256": method_slice["source_file_sha256"],
            "controller_file_blob_id": method_slice["source_file_blob_id"],
            "method": "show",
            "definition_anchor": current_controller["definition_anchor"],
            "method_review_slice_sha256": method_slice["text_sha256"],
            "review_outcome": "OWNER_ROUTE_ACTION",
            "semantic_dissent_present": False,
            "static_controller_action_bridge_credit": True,
            "page_ownership_credit": False,
            "sibling_data_route_ownership_credit": False,
            "service_model_helper_caller_redirect_or_test_ownership_credit": False,
            "correctness_credit": False,
            "site_permission_privacy_or_direct_object_credit": False,
            "runtime_credit": False,
            "application_browser_credit": False,
            "executed_test_credit": False,
            "benchmark_credit": False,
            "final_finding_credit": False,
            "completion_credit": False,
            "publication_credit": False,
        },
        "bridge_row_sha256",
    )
    assert row["overlay_row_sha256"] == OVERLAY_ROW_SEAL
    assert bridge["bridge_row_sha256"] == BRIDGE_ROW_SEAL

    spec = importlib.util.spec_from_file_location("run149_base", AUDIT / BASE_COLLECTOR)
    assert spec and spec.loader
    base = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(base)
    prior_records, prior_bridges = base.collect_prior_state()
    for source in (run149, run153, run170, run180):
        prior_records += source["overlay_source_records"]
        prior_bridges += source["new_static_controller_action_bridges"]
    assert (len(prior_records), len(prior_bridges)) == (666, 97)
    prior_source_keys = [item["source_record_key"] for item in prior_records]
    prior_bridge_keys = [
        "|".join((item["controller_file"], item["method"], item["feature_id"]))
        for item in prior_bridges
    ]
    assert len(prior_source_keys) == len(set(prior_source_keys)) == 666
    assert len(prior_bridge_keys) == len(set(prior_bridge_keys)) == 97
    assert hlist(prior_source_keys) == PRIOR_SOURCE_IDENTITY
    assert hlist(prior_bridge_keys) == PRIOR_BRIDGE_IDENTITY
    assert row["source_record_key"] not in set(prior_source_keys)
    new_bridge_key = "|".join(
        (bridge["controller_file"], bridge["method"], bridge["feature_id"])
    )
    assert new_bridge_key not in set(prior_bridge_keys)

    records, bridges = prior_records + [row], prior_bridges + [bridge]
    assert len({item["source_record_id"] for item in records}) == 667
    assert len({item["source_record_key"] for item in records}) == 667
    bridge_keys = [
        (item["controller_file"], item["method"], item["feature_id"])
        for item in bridges
    ]
    assert len(bridge_keys) == len(set(bridge_keys)) == 98
    routes = [item for item in records if item["surface"] == "ROUTE_SOURCE_RECORD"]
    pages = [item for item in records if item["surface"] == "PAGE_ROOT_SOURCE_RECORD"]
    assert (len(routes), len(pages)) == (310, 357)
    features = {item["feature_id"] for item in records}
    route_features = {item["feature_id"] for item in routes}
    page_features = {item["feature_id"] for item in pages}
    assert (len(features), len(route_features), len(page_features), len(route_features & page_features)) == (
        256,
        64,
        242,
        50,
    )
    assert Counter({item["feature_id"]: item["feature_class"] for item in records}.values()) == {
        "H": 234,
        "D": 22,
    }
    percent = (Decimal(667) * 100 / Decimal(3929)).quantize(
        Decimal("0.000001"), rounding=ROUND_HALF_UP
    )
    assert format(percent, "f") == "16.976330"

    prior_keys = base.collect_prior_reviewed_queue_keys() | {
        "route|RUN077-ROUTE-0689",
        "route|RUN077-ROUTE-0690",
        "route|RUN077-ROUTE-0692",
        "route|RUN077-ROUTE-0693",
    }
    assert len(prior_keys) == 120
    assert "route|RUN077-ROUTE-0694" not in prior_keys
    assert hlist(prior_keys) == PRIOR_REVIEWED_IDENTITY
    assert canonical(sorted(prior_keys)) == PRIOR_REVIEWED_CANONICAL
    reviewed_keys = prior_keys | {"route|RUN077-ROUTE-0694"}
    assert len(reviewed_keys) == 121
    queue_rows = strict_json(QUEUE)["records"]
    assert queue_rows[85]["canonical_key"] in reviewed_keys
    next_index = next(
        index
        for index in range(86, len(queue_rows))
        if queue_rows[index]["canonical_key"] not in reviewed_keys
    )
    assert next_index == 86
    next_row = queue_rows[next_index]
    assert (
        next_row["queue_id"],
        next_row["source_record_id"],
        next_row["source"]["literal_route_name"],
        next_row["source"]["action_expression"],
        next_row["queue_record_sha256"],
    ) == (
        "RUN090-ROUTE-0087",
        "RUN077-ROUTE-0695",
        "fleet-assets.trips.playback.data",
        "[FleetTripController::class, 'playback']",
        "ed12617b478e0a22014fb6c81402e5cf79aa574720e8ef8e2ce93f198a099893",
    )

    counts = {
        "source_denominator": 3929,
        "source_owner_records": 667,
        "route_owner_records": 310,
        "page_owner_records": 357,
        "static_controller_action_bridges": 98,
        "source_owner_percent": format(percent, "f"),
        "source_residual_records": 3262,
        "route_denominator": 3218,
        "residual_explicit_unmapped_routes": 2891,
        "page_denominator": 711,
        "residual_explicit_unmapped_pages": 345,
        "distinct_feature_ids": len(features),
        "distinct_route_feature_ids": len(route_features),
        "distinct_page_feature_ids": len(page_features),
        "route_page_feature_overlap": len(route_features & page_features),
    }
    queue_counts = {
        "queue_surface_rows": 507,
        "reviewed_queue_surface_rows": 121,
        "pending_unreviewed_queue_surface_rows": 386,
        "reviewed_owner_route_rows": 99,
        "reviewed_shared_relation_rows": 10,
        "reviewed_alias_rows": 5,
        "reviewed_dead_or_retired_rows": 0,
        "reviewed_evidence_gap_rows": 7,
        "queue_surfaces_without_ownership": 408,
        "new_reviewed_route_surface_rows": 1,
        "new_owner_route_surface_rows": 1,
    }
    assert 3929 == 667 + 3262 and 667 == 310 + 357
    assert 3218 == 310 + 12 + 5 + 0 + 2891
    assert 711 == 357 + 9 + 0 + 0 + 345
    assert 507 == 121 + 386 and 121 == 99 + 10 + 5 + 0 + 7
    assert 408 == 386 + 10 + 5 + 0 + 7

    identity = {
        "hash_algorithm": "sha256-of-lf-joined-sorted-unique-utf8",
        "prior_source_record_key_list_sha256": hlist(prior_source_keys),
        "combined_source_record_key_list_sha256": hlist(
            [item["source_record_key"] for item in records]
        ),
        "prior_bridge_key_list_sha256": hlist(prior_bridge_keys),
        "combined_bridge_key_list_sha256": hlist(
            [
                "|".join((item["controller_file"], item["method"], item["feature_id"]))
                for item in bridges
            ]
        ),
        "prior_reviewed_queue_key_list_sha256": hlist(prior_keys),
        "combined_reviewed_queue_key_list_sha256": hlist(reviewed_keys),
        "canonical_json_reviewed_key_hashes": {
            "prior": canonical(sorted(prior_keys)),
            "combined": canonical(sorted(reviewed_keys)),
        },
        "new_overlay_source_records_sha256": canonical([row]),
        "new_action_bridges_sha256": canonical([bridge]),
        "accepted_semantic_review_record_sha256s": [SEMANTIC_REVIEW_A, SEMANTIC_REVIEW_B],
        "accepted_final_exact_artifact_review_record_sha256s": [
            item["review_record_sha256"] for item in final_reviews
        ],
        "semantic_consensus_record_sha256": SEMANTIC_CONSENSUS,
        "run190_computed_decision_sha256": RUN190_COMPUTED_DECISION_HASH,
    }
    assert identity["prior_source_record_key_list_sha256"] == PRIOR_SOURCE_IDENTITY
    assert identity["prior_bridge_key_list_sha256"] == PRIOR_BRIDGE_IDENTITY
    assert identity["prior_reviewed_queue_key_list_sha256"] == PRIOR_REVIEWED_IDENTITY
    assert identity["combined_source_record_key_list_sha256"] == COMBINED_SOURCE_IDENTITY
    assert identity["combined_bridge_key_list_sha256"] == COMBINED_BRIDGE_IDENTITY
    assert identity["combined_reviewed_queue_key_list_sha256"] == COMBINED_REVIEWED_IDENTITY
    assert identity["canonical_json_reviewed_key_hashes"]["combined"] == COMBINED_REVIEWED_CANONICAL

    false_credit = {
        key: False
        for key in (
            "static_page_feature_ownership",
            "sibling_or_adjacent_route_ownership",
            "frontend_page_caller_or_redirect_ownership",
            "service_model_helper_or_test_ownership",
            "complete_route_page_feature_crosswalk",
            "framework_route_reachability",
            "canonical_object_ownership_correctness",
            "approved_site_scope_correctness",
            "permission_correctness",
            "privacy_correctness",
            "direct_object_concealment_correctness",
            "runtime",
            "database",
            "build",
            "application_browser",
            "responsive_application",
            "executed_tests",
            "application_source_mutation",
            "matrix_mutation",
            "benchmark",
            "final_no_match_or_NCM",
            "ease",
            "release",
            "publication",
            "pass",
            "final_finding",
            "feature_completion",
            "completion",
            "gate_4",
            "audit_complete",
        )
    }
    generator_raw = (AUDIT / GENERATOR).read_bytes()
    output: dict[str, Any] = {
        "schema_version": "run-190-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-wave-37-v1",
        "run_id": "RUN-190-REVIEWED-OUTCOME-NEUTRAL-FLEET-TRIP-PLAYBACK-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-37",
        "status": "ONE_REVIEWED_FLEET_TRIP_PLAYBACK_ROUTE_ACTION_OWNER_AND_BRIDGE_INTEGRATED_STATIC_ONLY_ZERO_SIBLING_CORRECTNESS_RUNTIME_OR_COMPLETION_CREDIT",
        "generated_on": "2026-08-31",
        "pins": {
            "checkpoint_commit": HEAD,
            "checkpoint_tree": TREE,
            "checkpoint_parents": [FIRST_PARENT, SECOND_PARENT],
            "reviewed_application_commit": REVIEWED_APPLICATION_COMMIT,
            "reviewed_application_tree": REVIEWED_APPLICATION_TREE,
            "current_subtrees": CURRENT_SUBTREES,
            "whole_app_or_tests_subtree_equality_with_reviewed_snapshot_claimed": False,
            "all_nine_reviewed_source_paths_byte_identical": True,
            "governing_prompt": GOVERNING_PROMPT,
            "continuation_request": CONTINUATION_REQUEST,
            "generator": {
                "path": f"{PREFIX}/{GENERATOR}",
                "sha256": digest(generator_raw),
                "blob_id": git("hash-object", "--", str(AUDIT / GENERATOR)),
                "bytes": len(generator_raw),
                "lines": len(generator_raw.splitlines()),
            },
            "inputs": EXPECTED,
            "input_blobs": EXPECTED_BLOBS,
            "input_map_sha256": canonical(EXPECTED),
            "run189_packet_self_seal_sha256": RUN189_PACKET_SELF_SEAL,
            "run189r_self_seal_sha256": RUN189R_SELF_SEAL,
            "semantic_consensus_record_sha256": SEMANTIC_CONSENSUS,
            "run190_computed_decision_sha256": RUN190_COMPUTED_DECISION_HASH,
        },
        "architecture_rule": packet["architecture_rule"],
        "baseline": {
            "source_owner_records": 666,
            "route_owner_records": 309,
            "page_owner_records": 357,
            "static_controller_action_bridges": 97,
            "reviewed_queue_surface_rows": 120,
            "pending_unreviewed_queue_surface_rows": 387,
            "latest_integrated_overlay": {
                "run_id": run180["run_id"],
                "status": run180["status"],
            },
            "latest_independent_overlay_review": {
                "run_id": run180r["run_id"],
                "status": run180r["status"],
                "new_or_current_or_downstream_credit": False,
            },
        },
        "reviewed_overlay": {
            "producer_run_id": packet["run_id"],
            "review_run_id": review["run_id"],
            "reviewed_route_actions": 1,
            "owner_route_actions": 1,
            "accepted_source_owner_records": 1,
            "accepted_route_owner_records": 1,
            "accepted_page_owner_records": 0,
            "accepted_controller_action_bridges": 1,
            "accepted_sibling_or_adjacent_route_owners": 0,
            "accepted_service_model_helper_page_caller_redirect_or_test_owners": 0,
            "new_distinct_feature_ids": 0,
            "current_static_overlay_credit_applied": True,
            "correctness_or_downstream_credit_authorized": False,
            "final_finding_credit_authorized": False,
        },
        "reviewer_lineage": {
            "accepted_semantic_reviews": semantic_reviews,
            "semantic_consensus": review["semantic_review_consensus"],
            "run189r_decision": review["decision"],
            "run190_computed_decision_sha256": RUN190_COMPUTED_DECISION_HASH,
            "accepted_final_exact_artifact_reviews": final_reviews,
            "semantic_dissent_present": False,
            "final_artifact_discrepancies": 0,
        },
        "source_packet_boundary": packet["source_review_packet"],
        "remediation_and_history_noninheritance": packet[
            "remediation_and_history_noninheritance"
        ],
        "combined_counts": counts,
        "queue_accounting": queue_counts,
        "queue_boundary": {
            "preceding_index_84_not_recredited": True,
            "selected_index_85_integrated": True,
            "next_unresolved_index": next_index,
            "next_unresolved_queue_id": next_row["queue_id"],
            "next_unresolved_route_record_id": next_row["source_record_id"],
            "next_unresolved_route_name": next_row["source"]["literal_route_name"],
            "next_unresolved_action_expression": next_row["source"]["action_expression"],
            "next_unresolved_queue_record_sha256": next_row["queue_record_sha256"],
            "reviewed_key_count": len(reviewed_keys),
            "reviewed_key_list_sha256": hlist(reviewed_keys),
            "reviewed_key_list_canonical_json_sha256": canonical(sorted(reviewed_keys)),
        },
        "noninheritance_boundary": {
            "historical_remediation": packet["remediation_and_history_noninheritance"],
            "historical_route_or_task_hash_presented_as_current": False,
            "page_caller_redirect_service_model_helper_or_test_inherited_or_recredited": False,
            "sibling_playback_data_route_identity_or_outcome_inherited": False,
            "older_2026_08_12_bundle_role": "NON_GOVERNING_EXCLUDED_FROM_IDENTITY_MAPPING_BENCHMARK_AND_CREDIT",
            "older_2026_08_12_feature_identity_imported": False,
            "older_2026_08_12_mapping_or_benchmark_credit_imported": False,
        },
        "overlay_source_records": [row],
        "new_static_controller_action_bridges": [bridge],
        "reviewed_non_owner_outcomes": [],
        "identity": identity,
        "outcome_conservation": {
            "reviewed_outcomes_equation": "1 = 1 owner + 0 shared + 0 evidence gap",
            "bounded_source_equation": "3929 = 667 owner + 3262 non-owner residual",
            "owner_surface_equation": "667 = 310 route + 357 page",
            "feature_union_equation": "256 = 64 route + 242 page - 50 overlap",
            "route_universe_equation": "3218 = 310 owner + 12 shared + 5 alias + 0 dead + 2891 residual",
            "page_universe_equation": "711 = 357 owner + 9 shared + 0 alias + 0 dead + 345 residual",
            "queue_equation": "507 = 121 reviewed + 386 pending",
            "reviewed_queue_equation": "121 = 99 owner + 10 shared + 5 alias + 0 dead + 7 evidence gap",
            "queue_without_ownership_equation": "408 = 386 pending + 10 shared + 5 alias + 0 dead + 7 evidence gap",
        },
        "credit_boundary": {
            "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD": True,
            "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION": True,
            **false_credit,
        },
        "mutation_attestation": {
            "application_source_changed_by_run190": False,
            "test_files_changed_by_run190": False,
            "matrix_changed": False,
            "reports_changed": False,
            "dashboard_generator_changed": False,
            "dashboard_html_changed": False,
            "runtime_or_external_system_changed": False,
            "audit_artifacts_only": True,
            "run190_producer_scope_contains_only_generator_and_receipt": True,
        },
        "completion_boundary": packet["completion_boundary"],
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [f"{PREFIX}/{GENERATOR}", f"{PREFIX}/{OUTPUT}"],
    }
    output["self_seal"] = {
        "algorithm": "sha256-canonical-json-with-self-seal-omitted",
        "sha256": canonical(output),
    }
    target = AUDIT / OUTPUT
    temporary = target.with_name(target.name + ".tmp")
    assert not temporary.exists()
    payload = (json.dumps(output, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    temporary.write_bytes(payload)
    os.replace(temporary, target)
    assert_exact_dirty_set()
    parsed = strict_json(OUTPUT)
    verify_seal(parsed, "self_seal", output["self_seal"]["sha256"])
    assert not list(AUDIT.rglob("__pycache__"))
    print(
        json.dumps(
            {
                "status": output["status"],
                "new_route_owners": 1,
                "new_action_bridges": 1,
                "next_index": 86,
                "overlay_row_sha256": row["overlay_row_sha256"],
                "bridge_row_sha256": bridge["bridge_row_sha256"],
                "final_review_record_sha256s": [
                    item["review_record_sha256"] for item in final_reviews
                ],
                "generator_sha256": audit_sha(GENERATOR),
                "receipt_sha256": audit_sha(OUTPUT),
                "self_seal": output["self_seal"]["sha256"],
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
