#!/usr/bin/env python3
"""Materialize the two fresh independent RUN189 semantic ownership reviews."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import subprocess
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT = Path(__file__).resolve().parents[1]
PREFIX = AUDIT.relative_to(REPO).as_posix()

PACKET_GENERATOR = "generators/build-outcome-neutral-fleet-trip-playback-route-action-cohort-wave-37.py"
PACKET = "evidence/source/root-run-189-outcome-neutral-fleet-trip-playback-route-action-cohort-wave-37.json"
GENERATOR = "generators/materialize-independent-outcome-neutral-fleet-trip-playback-route-action-review-wave-37.py"
OUTPUT = "evidence/source/raw-run-189r-independent-outcome-neutral-fleet-trip-playback-route-action-review-wave-37.json"

HEAD = "d991b2898b70409ce7c019abe9ddbd8394e0b595"
TREE = "46074d7ec2b2a75b6fc4c3fa67187d5b908de79a"
FIRST_PARENT = "10943780e7abea7a9d3b155bcd20154daf9bcc2d"
SECOND_PARENT = "d6e2b22cf765f211763f88213b7145aa5adfde33"

PACKET_GENERATOR_SHA256 = "ccb18a24eb698084736d3851fa1d205e6061e645faabd86400f8e0da50f05774"
PACKET_GENERATOR_BLOB_ID = "01b8b63b676237c3fcba1bd44a5d8012d6edb817"
PACKET_SHA256 = "77b58c6c97153793bd1eda12107ee174d8ea926ede0de940918e42d411d51734"
PACKET_SELF_SEAL = "11d39b08909e6ad3ecbec73c824f26ec82f7bf226440d69df4667e84395cb13d"
SOURCE_PACKET_SHA256 = "5c78cf40387a4eac26682ddf5dbde5ca5d6bdde33ab52f054702481b16128144"

GOVERNING_PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
CONTINUATION_REQUEST_SHA256 = "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d"


def run(*args: str) -> bytes:
    return subprocess.run(args, cwd=REPO, check=True, capture_output=True).stdout


def git(*args: str) -> str:
    return run("git", *args).decode("utf-8").rstrip("\r\n")


def sha(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def audit_sha(relative: str) -> str:
    return sha((AUDIT / relative).read_bytes())


def canonical_hash(value: Any) -> str:
    return sha(
        json.dumps(
            value,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        ).encode("utf-8")
    )


def strict_json(relative: str) -> dict[str, Any]:
    def hook(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, (relative, key)
            result[key] = value
        return result

    value = json.loads(
        (AUDIT / relative).read_text(encoding="utf-8"),
        object_pairs_hook=hook,
    )
    assert isinstance(value, dict)
    return value


def assert_exact_dirty_set() -> None:
    paths = {
        f"?? {PREFIX}/{PACKET_GENERATOR}",
        f"?? {PREFIX}/{PACKET}",
        f"?? {PREFIX}/{GENERATOR}",
    }
    if (AUDIT / OUTPUT).exists():
        paths.add(f"?? {PREFIX}/{OUTPUT}")
    actual = {line for line in git("status", "--porcelain").splitlines() if line}
    assert actual == paths, (actual, paths)


def sealed_review(record: dict[str, Any]) -> dict[str, Any]:
    result = dict(record)
    result["review_record_sha256"] = canonical_hash(result)
    return result


def main() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == HEAD
    assert git("rev-parse", "HEAD^{tree}") == TREE
    assert git("rev-parse", "HEAD^1") == FIRST_PARENT
    assert git("rev-parse", "HEAD^2") == SECOND_PARENT
    assert_exact_dirty_set()

    assert audit_sha(PACKET_GENERATOR) == PACKET_GENERATOR_SHA256
    assert git("hash-object", "--", str(AUDIT / PACKET_GENERATOR)) == PACKET_GENERATOR_BLOB_ID
    assert audit_sha(PACKET) == PACKET_SHA256

    packet = strict_json(PACKET)
    packet_without_seal = dict(packet)
    packet_seal = packet_without_seal.pop("self_seal")
    assert packet_seal["sha256"] == PACKET_SELF_SEAL
    assert canonical_hash(packet_without_seal) == PACKET_SELF_SEAL
    assert packet["pins"]["generator_sha256"] == PACKET_GENERATOR_SHA256
    assert packet["source_review_packet"]["packet_sha256"] == SOURCE_PACKET_SHA256
    assert packet["pins"]["checkpoint_commit"] == HEAD
    assert packet["pins"]["checkpoint_tree"] == TREE
    assert packet["pins"]["checkpoint_parents"] == [FIRST_PARENT, SECOND_PARENT]
    assert packet["pins"]["governing_prompt"]["sha256"] == GOVERNING_PROMPT_SHA256
    assert packet["pins"]["continuation_request"]["sha256"] == CONTINUATION_REQUEST_SHA256
    assert packet["pins"]["continuation_request"]["is_governing_prompt"] is False
    assert packet["selection_contract"]["selected_queue_indices_zero_based"] == [85]
    assert packet["selection_contract"]["selected_queue_ids"] == ["RUN090-ROUTE-0086"]
    assert packet["selection_contract"]["selected_route_record_ids"] == ["RUN077-ROUTE-0694"]
    assert packet["selection_contract"]["selected_route_names"] == ["fleet-assets.trips.playback"]
    assert packet["selection_contract"]["selected_actions"] == [
        "App\\Http\\Controllers\\Fleet\\FleetTripController::show"
    ]
    assert packet["counts"]["ownership_decisions"] == 0
    assert packet["queue_boundary"]["queue_advance_authorized"] is False
    assert packet["audit_completion_test_met"] is False

    common_integrity = {
        "reviewed_generator_sha256": PACKET_GENERATOR_SHA256,
        "reviewed_generator_blob_id": PACKET_GENERATOR_BLOB_ID,
        "reviewed_receipt_sha256": PACKET_SHA256,
        "reviewed_receipt_self_seal_sha256": PACKET_SELF_SEAL,
        "reviewed_source_packet_sha256": SOURCE_PACKET_SHA256,
        "all_pinned_inputs_match": True,
        "all_external_prompt_pins_match": True,
        "all_nine_source_blobs_match": True,
        "all_twenty_two_exact_loci_match": True,
        "all_eight_method_slices_match": True,
        "duplicate_json_keys": 0,
        "blocking_discrepancies": 0,
    }
    common_identity = {
        "queue_id": "RUN090-ROUTE-0086",
        "source_record_id": "RUN077-ROUTE-0694",
        "candidate_feature_id": "CAP-FLEET-VEHICLE-REGISTER",
        "route_name": "fleet-assets.trips.playback",
        "action": "App\\Http\\Controllers\\Fleet\\FleetTripController::show",
    }
    common_noninheritance = {
        "correctness_adjudicated": False,
        "sibling_data_route_ownership_inherited": False,
        "frontend_page_or_caller_ownership_inherited": False,
        "legacy_redirect_ownership_inherited": False,
        "fleet_trip_service_ownership_inherited": False,
        "test_or_runtime_credit_inherited": False,
        "remediation_history_credit_inherited": False,
        "older_bundle_identity_inherited": False,
        "queue_advance_authorized": False,
        "integration_authorized": False,
    }

    review_a = sealed_review(
        {
            "review_id": "RUN189R-SEMANTIC-A",
            "reviewer_task": "/root/run189_semantic_a",
            "review_role": "FRESH_SEMANTIC_SOURCE_REVIEWER_INDEPENDENT_OF_PRODUCER_AND_REVIEW_B",
            "reviewed_on": "2026-08-31",
            "identity": common_identity,
            "integrity": common_integrity,
            "verdict": "GO",
            "outcome": "OWNER_ROUTE_ACTION",
            "ownership_credit_recommended_for_later_overlay": True,
            "rationale": "The show action directly resolves a Site-visible vehicle-linked FleetTrip, loads asset and driver context, audits the view, and renders material vehicle-specific trip state. This is a direct slice of Maintain vehicles and vehicle-specific state rather than route-name adjacency.",
            "candidate_anchor_reconciliation": "The frozen VehicleController and vehicle-index anchors prevent automatic containment credit, but current Asset vehicle and FleetTrip semantics resolve the direct action trace without inheritance.",
            "historical_task_pin_note": {
                "embedded_application_commit": "a0493442b9e392d324055c35bf25b69421dc2d35",
                "embedded_application_tree": "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1",
                "role": "HISTORICAL_FROZEN_TASK_PROVENANCE_NOT_RUN189_APPLICATION_PIN",
                "coherent_pair": True,
                "changes_outcome": False,
            },
            "noninheritance": common_noninheritance,
        }
    )
    review_b = sealed_review(
        {
            "review_id": "RUN189R-SEMANTIC-B",
            "reviewer_task": "/root/run189_semantic_b",
            "review_role": "FRESH_SEMANTIC_SOURCE_REVIEWER_INDEPENDENT_OF_PRODUCER_AND_REVIEW_A",
            "reviewed_on": "2026-08-31",
            "identity": common_identity,
            "integrity": common_integrity,
            "verdict": "GO",
            "outcome": "OWNER_ROUTE_ACTION",
            "ownership_credit_recommended_for_later_overlay": True,
            "rationale": "The action directly resolves a Site-visible FleetTrip, loads vehicle and driver context, and supplies timing, location, distance, duration, status, consent and management state to the playback view. This is a substantive route-action slice of the frozen user job.",
            "candidate_anchor_reconciliation": "The frozen VehicleController and vehicle-index anchors block automatic containment credit; current direct action semantics independently establish the route-action relation.",
            "noninheritance": common_noninheritance,
        }
    )
    reviews = [review_a, review_b]
    assert len({review["reviewer_task"] for review in reviews}) == 2
    assert {review["outcome"] for review in reviews} == {"OWNER_ROUTE_ACTION"}
    assert all(review["verdict"] == "GO" for review in reviews)
    assert all(review["integrity"]["blocking_discrepancies"] == 0 for review in reviews)

    accepted_review_hashes = [review["review_record_sha256"] for review in reviews]
    synthesis_base = {
        "synthesis_id": "RUN189R-SEMANTIC-CONSENSUS",
        "accepted_review_record_sha256s": accepted_review_hashes,
        "fresh_independent_semantic_review_count": 2,
        "outcomes": [review["outcome"] for review in reviews],
        "dissent_present": False,
        "discrepancies": 0,
        "consensus_outcome": "OWNER_ROUTE_ACTION",
        "ownership_credit_recommended_for_later_overlay": True,
        "ownership_credit_materialized_by_run189r": False,
        "queue_advance_materialized_by_run189r": False,
        "correctness_or_downstream_credit": False,
    }
    synthesis = {
        **synthesis_base,
        "synthesis_record_sha256": canonical_hash(synthesis_base),
    }

    generator_raw = (AUDIT / GENERATOR).read_bytes()
    receipt: dict[str, Any] = {
        "schema_version": "run-189r-independent-outcome-neutral-fleet-trip-playback-route-action-review-wave-37-v1",
        "run_id": "RUN-189R-INDEPENDENT-OUTCOME-NEUTRAL-FLEET-TRIP-PLAYBACK-ROUTE-ACTION-REVIEW-WAVE-37",
        "status": "TWO_FRESH_INDEPENDENT_OWNER_ROUTE_ACTION_REVIEWS_RECONCILED_LATER_OVERLAY_ONLY_ZERO_CURRENT_OWNERSHIP_CORRECTNESS_RUNTIME_OR_COMPLETION_CREDIT",
        "generated_on": "2026-08-31",
        "architecture_rule": packet["architecture_rule"],
        "pins": {
            "checkpoint_commit": HEAD,
            "checkpoint_tree": TREE,
            "checkpoint_parents": [FIRST_PARENT, SECOND_PARENT],
            "governing_prompt_sha256": GOVERNING_PROMPT_SHA256,
            "continuation_request_sha256": CONTINUATION_REQUEST_SHA256,
            "continuation_request_is_not_governing_prompt": True,
            "packet_generator": {
                "path": PACKET_GENERATOR,
                "sha256": PACKET_GENERATOR_SHA256,
                "blob_id": PACKET_GENERATOR_BLOB_ID,
            },
            "packet": {
                "path": PACKET,
                "sha256": PACKET_SHA256,
                "self_seal_sha256": PACKET_SELF_SEAL,
                "source_packet_sha256": SOURCE_PACKET_SHA256,
            },
            "review_generator": {
                "path": f"{PREFIX}/{GENERATOR}",
                "sha256": sha(generator_raw),
                "blob_id": git("hash-object", "--", str(AUDIT / GENERATOR)),
            },
        },
        "identity": common_identity,
        "semantic_reviews": reviews,
        "semantic_review_record_count": len(reviews),
        "semantic_review_consensus": synthesis,
        "decision": {
            "verdict": "GO_OWNER_ROUTE_ACTION_FOR_LATER_BOUNDED_OVERLAY",
            "outcome": "OWNER_ROUTE_ACTION",
            "ownership_credit_recommended": True,
            "ownership_credit_materialized": False,
            "controller_action_bridge_materialized": False,
            "queue_advance_materialized": False,
            "overlay_materialization_authorized_after_final_exact_artifact_review": True,
            "correctness_decision": False,
            "finding": False,
        },
        "later_overlay_contract": {
            "maximum_new_route_owner_rows": 1,
            "maximum_new_controller_action_bridges": 1,
            "route_owner_identity": "route|RUN077-ROUTE-0694|CAP-FLEET-VEHICLE-REGISTER",
            "action_bridge_identity": "app/Http/Controllers/Fleet/FleetTripController.php|show|CAP-FLEET-VEHICLE-REGISTER",
            "reviewed_key_identity": "route|RUN077-ROUTE-0694",
            "conditional_next_zero_based_index": 86,
            "conditional_next_queue_id": "RUN090-ROUTE-0087",
            "conditional_next_route_record_id": "RUN077-ROUTE-0695",
            "conditional_next_route_name": "fleet-assets.trips.playback.data",
            "page_owner_rows": 0,
            "sibling_data_route_rows": 0,
            "reporting_or_dashboard_changes": 0,
            "materialized_by_run189r": False,
        },
        "frozen_counts": {
            "owner_records": 666,
            "route_owners": 309,
            "page_owners": 357,
            "action_bridges": 97,
            "queue_reviewed": 120,
            "queue_pending": 387,
            "changed_by_run189r": False,
        },
        "final_exact_artifact_review_contract": {
            "status": "REQUIRED_AFTER_MATERIALIZATION_BEFORE_COMMIT",
            "required_distinct_read_only_reviewers": 2,
            "must_verify_generator_receipt_hashes_and_self_seal": True,
            "must_verify_semantic_review_record_hashes_and_synthesis": True,
            "must_verify_zero_current_overlay_or_queue_advance": True,
            "results_must_be_consumed_by_later_overlay": True,
        },
        "noninheritance_boundary": {
            **common_noninheritance,
            "dedicated_page_ownership_materialized": False,
            "canonical_trip_producer_ownership_materialized": False,
            "approved_site_or_privacy_correctness": False,
            "runtime_or_executed_test_credit": False,
            "benchmark_or_ncm_credit": False,
            "release_or_publication_credit": False,
            "gate_4_or_audit_completion_credit": False,
        },
        "credit_boundary": {
            "two_fresh_semantic_reviews_recorded": True,
            "semantic_consensus_recorded": True,
            **{
                key: False
                for key in (
                    "static_source_feature_ownership_materialized",
                    "static_route_feature_ownership_materialized",
                    "static_page_feature_ownership",
                    "static_controller_action_bridge_materialized",
                    "sibling_data_route_ownership",
                    "correctness",
                    "runtime",
                    "database",
                    "browser",
                    "build",
                    "executed_tests",
                    "remediation",
                    "benchmark",
                    "final_no_match_or_ncm",
                    "final_finding",
                    "feature_completion",
                    "pass",
                    "release",
                    "publication",
                    "gate_4",
                    "audit_complete",
                )
            },
        },
        "artifact_completion_test_met": False,
        "overlay_materialization_complete": False,
        "audit_completion_test_met": False,
        "wrote_files": [f"{PREFIX}/{GENERATOR}", f"{PREFIX}/{OUTPUT}"],
    }
    receipt["self_seal"] = {
        "algorithm": "sha256-canonical-json-with-self-seal-omitted",
        "sha256": canonical_hash(receipt),
    }

    output_path = AUDIT / OUTPUT
    temp_path = output_path.with_name(output_path.name + ".tmp")
    assert not temp_path.exists(), temp_path
    try:
        temp_path.write_text(
            json.dumps(receipt, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
            newline="\n",
        )
        temp_path.replace(output_path)
    finally:
        if temp_path.exists():
            temp_path.unlink()

    assert_exact_dirty_set()
    parsed = strict_json(OUTPUT)
    seal = parsed.pop("self_seal")
    assert seal["sha256"] == canonical_hash(parsed)
    assert not list(AUDIT.rglob("__pycache__"))
    print(
        json.dumps(
            {
                "status": receipt["status"],
                "semantic_reviews": len(reviews),
                "outcome": synthesis["consensus_outcome"],
                "ownership_materialized": False,
                "generator_sha256": audit_sha(GENERATOR),
                "receipt_sha256": audit_sha(OUTPUT),
                "self_seal": seal["sha256"],
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
