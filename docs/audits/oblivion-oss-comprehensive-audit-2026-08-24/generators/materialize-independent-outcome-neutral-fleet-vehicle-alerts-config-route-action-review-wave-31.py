#!/usr/bin/env python3
"""Materialize the corrected RUN169 independent Fleet alerts-config review."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import subprocess
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT = Path(__file__).resolve().parents[1]
PREFIX = AUDIT.relative_to(REPO).as_posix()
GENERATOR = "generators/materialize-independent-outcome-neutral-fleet-vehicle-alerts-config-route-action-review-wave-31.py"
OUTPUT = "evidence/source/raw-run-169r-independent-outcome-neutral-fleet-vehicle-alerts-config-route-action-review-wave-31.json"
COHORT_GENERATOR = "generators/build-outcome-neutral-fleet-vehicle-alerts-config-route-action-cohort-wave-31.py"
COHORT = "evidence/source/root-run-169-outcome-neutral-fleet-vehicle-alerts-config-route-action-cohort-wave-31.json"
HEAD = "442591be812108626e979b82de7b4e8ec3748f0c"
TREE = "64e5afcd923cb2c5374ad73b5bc4f3f75b57d03b"
APPLICATION_COMMIT = "e488bd3edcda0f154f87e8bbed972f14db409b82"
APPLICATION_TREE = "9e93b8aea4f4b907cde3dc59dd0520fba5bd7080"
SUBTREES = {
    "app": "b9a9a672bea01473d8be96a0afb548e6291aee9c",
    "routes": "9392e22e4c472610da98977bec4e112092d223b9",
    "resources/js": "776359c5b8b06a55fcf5fe4464bc3e00d01248e5",
    "resources/js/pages": "077d40c746018b655c9b9f8c1ee3f87c2d792a8c",
    "tests": "90886d938c57ab7b45c9301514077d16e4c6b470",
}
COHORT_GENERATOR_SHA = "ffb1dba865a50f3cdcbf4e3ce285482e062bb023145089353a68f705d0646c7e"
COHORT_GENERATOR_BLOB = "0199cbc6044817f4484fa7ce4824d0dcff1bd9cf"
COHORT_SHA = "2fc20f6e528adae64979a763e6f28dd86018c2ecd87bbb0b651ddf6eee158fb2"
COHORT_BLOB = "e7e08a7a0232f9691fc48bb46f84770f9bb595dc"
COHORT_SELF_SEAL = "f36a58efc9e6e6c129d795c645bdc6ebb294a63ccbfcdc69ab832a9e5709b6aa"
CANDIDATE_HASH = "7d6b2bddea1f1dce45c8ba3a80feaae4e3efbe5e6b0de022b84ab172dba9a5f1"
QUEUE_SEAL = "d29353be38d964311d6586311d654c13dc2a39da9b7bcdb8a6a75d69fa511731"
SOURCE_PACKET_HASH = "89d8ea3b1818eb9005cf344414dca1f659eb79263e5cc93aee1873cafcebce76"
METHOD_SLICE_HASH = "b9940e2dda6fede48c52b4630cd976e2401b5926fa82e125c15faa31a1b73fe7"
REVIEWED_KEY_HASH = "e598ea44dc0abf67f5dae3374f2d5608d10e5a2edae475ed18a8f0ecaf227e40"
INITIAL_ADJUDICATION = "RUN169 read-only verdict: NO-GO artifact, but independent semantic outcome is OWNER for RUN090-ROUTE-0084 / RUN077-ROUTE-0692 -> CAP-FLEET-VEHICLE-REGISTER. Single exact defect: generator line 55 declares docs/architecture/single-tenant-application.md locus 1-40 although file has 21 lines; receipt lines 243-251 repeats lines=21 + locus 1-40. Generator source_record (105-109) hashes/counts but never validates loci, so seal/hash pass cannot cure the out-of-range anchor. Correct to 1-21 and reseal/regenerate. All other checks passed: strict JSON/no duplicates; current main e488bd3/tree 9e93b8; generator sha 120fab7e/blob 738c88e; receipt sha b7393b9/blob 64dfe9f; self-seal efcbc49; source-packet hash 68cbe245; all 8 file sha/blob/bytes/line counts; seven exact selected anchors; method slice 1061-1088 sha b9940e2. Queue boundary is generator-enforced/sealed: 118 reviewed/hash e598..., 81/82 in, 83 out/current, post-OWNER cursor index84 RUN090-ROUTE-0085 / RUN077-ROUTE-0693 (generator 128-171; receipt 148-158). OWNER rationale: unique fleet.viewAny GET -> VehicleController::alertsConfig, canonical assignableVehicle resolution/404, vehicle alert_config+geofences projection to dedicated vehicle config page, direct vehicle-show caller; Control Room/geofence/page/caller are downstream/context, not co-owners. Credit only exact static route/action ownership after a corrected independent-review integration; no page/model/service correctness, permission/Site/privacy/direct-object, GET-test, runtime/browser/benchmark/NCM/finding/completion credit."
FINAL_ADJUDICATION = "Final RUN169 re-review: GO, no remaining discrepancies. Corrected generator line 55 and receipt lines 243-251 now use architecture locus 1-21 for the 21-line file. Current generator SHA ffb1dba865a50f3cdcbf4e3ce285482e062bb023145089353a68f705d0646c7e/blob 0199cbc6044817f4484fa7ce4824d0dcff1bd9cf; receipt SHA 2fc20f6e528adae64979a763e6f28dd86018c2ecd87bbb0b651ddf6eee158fb2/blob e7e08a7a0232f9691fc48bb46f84770f9bb595dc; self-seal f36a58ef... and source-packet hash 89d8ea3b... independently recompute exactly. Strict JSON duplicate check passes. In-memory reversal of only the locus plus its four derived receipt fields (generator SHA/blob, packet hash, self-seal) reproduces old receipt SHA b7393b9 exactly; reversing only generator locus reproduces old generator SHA 120fab7 exactly, proving no unintended byte change. All 8 source SHA/blob/bytes/line counts and all 11 ranges now pass, all 7 exact anchors pass, method 1061-1088/text SHA b9940e2 pass, e488/tree pins and 118-key/index83 boundary unchanged, all credit/completion flags false. Semantic outcome remains OWNER for RUN090-ROUTE-0084 / RUN077-ROUTE-0692 -> CAP-FLEET-VEHICLE-REGISTER. Credit only exact static route/action ownership upon separate review integration; page/caller/service/model correctness, Site/permission/privacy/direct-object, selected GET tests, runtime/browser/benchmark/NCM/finding/completion remain uncredited. Next cursor after OWNER: index 84, RUN090-ROUTE-0085 / RUN077-ROUTE-0693. No SAFE files inspected; no edits/tests/runtime."


def run(*args: str) -> bytes:
    return subprocess.run(args, cwd=REPO, check=True, capture_output=True).stdout


def git(*args: str) -> str:
    return run("git", *args).decode("utf-8").rstrip("\r\n")


def digest(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def audit_sha(relative: str) -> str:
    return digest((AUDIT / relative).read_bytes())


def canonical(value: Any) -> str:
    return digest(json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode())


def strict_json(relative: str) -> dict[str, Any]:
    def hook(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, (relative, key)
            result[key] = value
        return result

    raw = (AUDIT / relative).read_bytes()
    assert not raw.startswith(b"\xef\xbb\xbf") and b"\r\n" not in raw and raw.endswith(b"\n")
    value = json.loads(raw, object_pairs_hook=hook)
    assert isinstance(value, dict)
    assert (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode() == raw
    return value


def sealed(record: dict[str, Any], field: str) -> dict[str, Any]:
    record[field] = canonical(record)
    return record


def file_record(relative: str) -> dict[str, Any]:
    raw = (AUDIT / relative).read_bytes()
    return {
        "path": f"{PREFIX}/{relative}",
        "sha256": digest(raw),
        "blob_id": git("hash-object", "--", str(AUDIT / relative)),
        "bytes": len(raw),
        "lines": len(raw.splitlines()),
    }


def validate_cohort() -> dict[str, Any]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == HEAD and git("rev-parse", "HEAD^{tree}") == TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    for path, expected in SUBTREES.items():
        assert git("rev-parse", f"HEAD:{path}") == expected
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{path}") == expected
    assert not git("status", "--porcelain", "--", "app", "routes", "resources/js", "tests", "database")
    assert audit_sha(COHORT_GENERATOR) == COHORT_GENERATOR_SHA
    assert audit_sha(COHORT) == COHORT_SHA
    assert git("rev-parse", f"HEAD:{PREFIX}/{COHORT_GENERATOR}") == COHORT_GENERATOR_BLOB
    assert git("rev-parse", f"HEAD:{PREFIX}/{COHORT}") == COHORT_BLOB

    cohort = strict_json(COHORT)
    without_seal = {key: value for key, value in cohort.items() if key != "self_seal"}
    assert cohort["self_seal"] == {"algorithm": "sha256-canonical-json-with-self-seal-omitted", "sha256": COHORT_SELF_SEAL}
    assert canonical(without_seal) == COHORT_SELF_SEAL
    assert cohort["pins"]["checkpoint_commit"] == cohort["pins"]["application_commit"] == APPLICATION_COMMIT
    assert cohort["pins"]["checkpoint_tree"] == cohort["pins"]["application_tree"] == APPLICATION_TREE
    assert cohort["pins"]["generator_sha256"] == COHORT_GENERATOR_SHA
    assert cohort["pins"]["generator_blob_id"] == COHORT_GENERATOR_BLOB
    assert cohort["pins"]["prompt_sha256"] == "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d"
    assert cohort["source_review_packet"]["packet_sha256"] == SOURCE_PACKET_HASH
    assert cohort["source_review_packet"]["selected_controller_method_slice"]["text_sha256"] == METHOD_SLICE_HASH
    assert cohort["queue_boundary"]["reviewed_key_count"] == 118
    assert cohort["queue_boundary"]["reviewed_key_list_sha256"] == REVIEWED_KEY_HASH
    assert cohort["queue_boundary"]["current_next_unresolved_index"] == 83
    assert cohort["queue_boundary"]["post_selection_next_index_if_owner"] == 84

    for source in cohort["source_review_packet"]["required_source_files"]:
        path = source["path"]
        raw = (REPO / path).read_bytes()
        assert digest(raw) == source["sha256"]
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{path}") == source["blob_id"]
        assert len(raw) == source["bytes"] and len(raw.splitlines()) == source["lines"]
        for locus in source["loci"]:
            start, end = (int(value) for value in locus.split("-", 1))
            assert 1 <= start <= end <= source["lines"], (path, locus)

    candidate = cohort["records"][0]
    assert canonical(candidate) == CANDIDATE_HASH
    assert canonical({key: value for key, value in candidate.items() if key != "queue_record_sha256"}) == QUEUE_SEAL
    assert candidate["queue_record_sha256"] == QUEUE_SEAL
    assert (
        candidate["queue_id"], candidate["canonical_key"], candidate["source_record_id"],
        candidate["candidate_feature_id"], candidate["source"]["literal_route_name"],
        candidate["secondary_lane"]["backend_method_relation"]["resolution"]["method"],
    ) == (
        "RUN090-ROUTE-0084", "route|RUN077-ROUTE-0692", "RUN077-ROUTE-0692",
        "CAP-FLEET-VEHICLE-REGISTER", "fleet-assets.vehicles.alerts-config", "alertsConfig",
    )
    assert candidate["review_state"] == {
        "status": "PENDING_FRESH_SEMANTIC_REVIEW",
        "allowed_outcomes": ["OWNER", "SHARED_RELATION", "EVIDENCE_GAP"],
        "ownership_credit": False,
    }
    current_route = next(item for item in cohort["source_review_packet"]["required_source_files"] if item["path"] == "routes/fleet-assets.php")
    assert candidate["source"]["route_file_sha256"] == "68025ffa9447026ea9aa2d111278a86cf47a49c5d83a4d01fbcbdde70ff61ffd"
    assert candidate["source"]["route_file_blob_id"] == "c117901e96a026aba846ce3ccc35a1625dadf1bb"
    assert current_route["sha256"] == "4be79ba4a0957f81f3e99de8eea7f29a398f8a115957bd44af06dbbf78fe2c4c"
    assert current_route["blob_id"] == "f0b2b8c199ada1d8ef8bdb41c99bfc2ac02f93d2"
    assert cohort["historical_pin_reconciliation"] == {
        "task_script_historical_application_pin": "a0493442b9e392d324055c35bf25b69421dc2d35",
        "current_review_pin": APPLICATION_COMMIT,
        "route_file_drifted": True,
        "queue_statement_sha256_still_exact": True,
        "historical_review_or_ownership_inherited": False,
    }
    return cohort


def observation(identifier: str, category: str, loci: list[str], text: str) -> dict[str, Any]:
    return sealed({
        "observation_id": identifier,
        "status": "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING",
        "category": category,
        "loci": loci,
        "observation": text,
        "correctness_credit_authorized": False,
        "final_finding_credit_authorized": False,
    }, "observation_record_sha256")


def build() -> dict[str, Any]:
    cohort = validate_cohort()
    candidate = cohort["records"][0]
    review = sealed({
        "review_id": "RUN169R-INDEPENDENT-REVIEW-A",
        "reviewer_role": "independent semantic route-action and exact-artifact reviewer",
        "independent_from_cohort_producer": True,
        "blinded_review": False,
        "nonblinding_reason": "The source packet and current task context were visible; no blindness is claimed.",
        "other_candidate_reviewer_consulted": False,
        "fresh_committed_source_trace": True,
        "review_method": "FRESH_PINNED_STATIC_SEMANTIC_TRACE_AND_CORRECTED_EXACT_ARTIFACT_REVIEW_NO_EXECUTION",
        "raw_independent_adjudication": {
            "reviewer_task_path": "/root/run167_stale_scan",
            "delivery_channel": "collaboration_message",
            "initial_payload": INITIAL_ADJUDICATION,
            "initial_payload_sha256": digest(INITIAL_ADJUDICATION.encode()),
            "initial_payload_bytes": len(INITIAL_ADJUDICATION.encode()),
            "initial_payload_lines": len(INITIAL_ADJUDICATION.splitlines()),
            "corrected_final_payload": FINAL_ADJUDICATION,
            "corrected_final_payload_sha256": digest(FINAL_ADJUDICATION.encode()),
            "corrected_final_payload_bytes": len(FINAL_ADJUDICATION.encode()),
            "corrected_final_payload_lines": len(FINAL_ADJUDICATION.splitlines()),
            "verbatim_payloads_retained": True,
        },
        "initial_artifact_review": {
            "verdict": "NO_GO_ONE_OUT_OF_RANGE_ARCHITECTURE_LOCUS",
            "generator_sha256": "120fab7e00860e34965dc7131ea7db9399cb639333751e93935fda4d34e65ff0",
            "receipt_sha256": "b7393b977d8dbbdca61e1d5f2145d66131b5220e44d672aca1f3599ba730e3af",
            "discrepancy": "The architecture file has 21 lines but its declared locus was 1-40.",
            "semantic_outcome_reported_as_owner": True,
            "ownership_integration_withheld_until_correction": True,
        },
        "corrected_artifact_review": {
            "verdict": "GO_ZERO_REMAINING_DISCREPANCIES",
            "generator_sha256": COHORT_GENERATOR_SHA,
            "receipt_sha256": COHORT_SHA,
            "cohort_self_seal_sha256": COHORT_SELF_SEAL,
            "source_packet_sha256": SOURCE_PACKET_HASH,
            "correction_limited_to_locus_and_derived_seals": True,
        },
        "canonical_candidate_record_sha256": CANDIDATE_HASH,
        "queue_record_self_seal_sha256": QUEUE_SEAL,
        "queue_index_zero_based": 83,
        "queue_id": "RUN090-ROUTE-0084",
        "queue_canonical_key": "route|RUN077-ROUTE-0692",
        "route_record_id": "RUN077-ROUTE-0692",
        "candidate_feature_id": "CAP-FLEET-VEHICLE-REGISTER",
        "action_key": "RUN077-ROUTE-0692|app/Http/Controllers/FleetAssets/VehicleController.php:alertsConfig|CAP-FLEET-VEHICLE-REGISTER",
        "outcome": "OWNER_ROUTE_ACTION",
        "confidence": "HIGH_STATIC_IDENTITY_ONLY",
        "identity_basis": "EXACT_LITERAL_ROUTE_NAME_PLUS_UNIQUE_ALERTS_CONFIG_ACTION_PLUS_AUTHORIZED_VEHICLE_RESOLUTION_AND_DEDICATED_RENDER",
        "source_loci": [
            "03-feature-to-benchmark-matrix.csv:108",
            "routes/fleet-assets.php:50-58",
            "app/Http/Controllers/FleetAssets/VehicleController.php:1061-1088",
            "app/Domain/SecurityDevices/Services/SecurityDevicesAccessService.php:278-290",
            "resources/js/pages/fleet-assets/vehicles/alerts-config.tsx:167-236",
            "resources/js/pages/fleet-assets/vehicles/show.tsx:346-353",
        ],
        "rationale": "The unique fleet.viewAny GET resolves to VehicleController::alertsConfig, which resolves the canonical assignable vehicle or conceals it with 404, projects that vehicle's alert configuration and geofences, and renders the dedicated vehicle alert-config consumer. The page and vehicle-show caller are consumption context, not co-owners of the exact route action.",
        "denied_alternatives": {
            "SHARED_RELATION": "The resolver, model, component, caller and geofence relation are dependencies or consumers, not co-owners of this exact GET action.",
            "EVIDENCE_GAP": "The exact route name, unique controller action, canonical vehicle resolution and dedicated render close narrow static ownership; correctness remains separate.",
        },
        "ownership_material_expansion": {"status": "NONE_REQUIRED_FOR_NARROW_STATIC_OWNERSHIP", "paths": []},
        "route_ownership_authorized_for_later_overlay": True,
        "controller_action_bridge_authorized_for_later_overlay": True,
        "owner_source_record_key": "route|RUN077-ROUTE-0692|CAP-FLEET-VEHICLE-REGISTER",
        "bridge_key": ["app/Http/Controllers/FleetAssets/VehicleController.php", "alertsConfig", "CAP-FLEET-VEHICLE-REGISTER"],
        "page_ownership_authorized": False,
        "consumer_or_caller_ownership_inherited_or_recredited": False,
        "historical_review_or_neighbor_outcome_inherited": False,
        "current_overlay_credit_awarded": False,
        "correctness_or_downstream_credit_authorized": False,
        "reviewer_wrote_files": False,
        "reviewer_executed_application_or_tests": False,
        "safe_remediation_paths_inspected": False,
    }, "review_record_sha256")

    observations = [
        observation("RUN169R-ASSURANCE-SELECTED-GET-NEGATIVE-EXECUTION", "selected_get_negative_path_execution", ["tests/Feature/FleetAssets/AssetMutationBoundaryTest.php:299-340", "routes/fleet-assets.php:53"], "The available focused boundary test covers the sibling POST, not this selected GET; no executed foreign-Site/direct-ID GET proof is credited."),
        observation("RUN169R-ASSURANCE-SITE-PERMISSION-CORRECTNESS", "approved_site_permission_and_concealment_correctness", ["routes/fleet-assets.php:50-58", "app/Domain/SecurityDevices/Services/SecurityDevicesAccessService.php:278-290"], "The source trace identifies the permission group and canonical resolver but does not prove correct approved-Site access, permission breadth, privacy, or concealment for every fleet.viewAny actor."),
        observation("RUN169R-ASSURANCE-CONSUMER-CALLER-NONOWNERSHIP", "consumer_and_caller_context_only", ["resources/js/pages/fleet-assets/vehicles/alerts-config.tsx:167-236", "resources/js/pages/fleet-assets/vehicles/show.tsx:346-353"], "The dedicated consumer and direct caller support route-action identity but receive no separate page, caller, runtime, browser, or completion ownership credit."),
    ]
    synthesis = sealed({
        "synthesis_id": "RUN169R-INDEPENDENT-REVIEW-SYNTHESIS",
        "verdict": "GO_ACCEPT_1_OWNER_ROUTE_ACTION_FOR_BOUNDED_LATER_INTEGRATION",
        "accepted_independent_review_ids": [review["review_id"]],
        "accepted_independent_review_record_sha256s": [review["review_record_sha256"]],
        "canonical_candidate_record_sha256": CANDIDATE_HASH,
        "queue_record_self_seal_sha256": QUEUE_SEAL,
        "outcome_variables": {"O": 1, "S": 0, "E": 0},
        "independent_reviews_reconciled": True,
        "outcome_discrepancies": 0,
        "material_identity_discrepancies": 0,
        "page_credit_discrepancies": 0,
        "hard_stop_discrepancies": 0,
        "corrected_artifact_discrepancies": 0,
        "provisional_assurance_observation_count": len(observations),
        "provisional_assurance_observations_sha256": canonical(observations),
        "route_ownership_authorized": True,
        "controller_action_bridge_authorized": True,
        "page_ownership_authorized": False,
        "bounded_overlay_integration_authorized_later_only": True,
        "current_overlay_credit_awarded": False,
        "correctness_or_downstream_credit_authorized": False,
        "synthesizer_wrote_files": False,
    }, "synthesis_record_sha256")
    decision = sealed({
        "candidate_record_sha256": CANDIDATE_HASH,
        "queue_record_self_seal_sha256": QUEUE_SEAL,
        "accepted_independent_review_ids": synthesis["accepted_independent_review_ids"],
        "accepted_independent_review_record_sha256s": synthesis["accepted_independent_review_record_sha256s"],
        "synthesis_record_sha256": synthesis["synthesis_record_sha256"],
        "queue_index_zero_based": 83,
        "queue_id": "RUN090-ROUTE-0084",
        "route_record_id": "RUN077-ROUTE-0692",
        "literal_route_name": "fleet-assets.vehicles.alerts-config",
        "controller_file": "app/Http/Controllers/FleetAssets/VehicleController.php",
        "controller_method": "alertsConfig",
        "candidate_feature_id": "CAP-FLEET-VEHICLE-REGISTER",
        "outcome": "OWNER_ROUTE_ACTION",
        "confidence": "HIGH_STATIC_IDENTITY_ONLY_1_OF_1_PLUS_SYNTHESIS",
        "route_ownership_authorized_for_later_overlay": True,
        "controller_action_bridge_authorized_for_later_overlay": True,
        "page_ownership_authorized": False,
        "consumer_or_caller_ownership_inherited_or_recredited": False,
        "historical_review_or_neighbor_outcome_inherited": False,
        "current_overlay_credit_awarded": False,
        "correctness_or_downstream_credit_authorized": False,
        "provisional_assurance_observation_ids": [item["observation_id"] for item in observations],
    }, "decision_record_sha256")

    false_credit = {key: False for key in (
        "current_overlay_ownership", "static_page_feature_ownership", "frontend_caller_ownership",
        "canonical_object_ownership_correctness", "approved_site_scope_correctness", "permission_correctness",
        "privacy_correctness", "direct_object_concealment_correctness", "query_projection_correctness",
        "framework_route_reachability", "runtime", "database", "build", "application_browser",
        "responsive_application", "executed_tests", "benchmark", "final_no_match_or_NCM", "ease",
        "release", "pass", "final_finding", "feature_completion", "completion", "gate_4", "audit_complete",
    )}
    payload: dict[str, Any] = {
        "schema_version": "run-169r-independent-outcome-neutral-fleet-vehicle-alerts-config-route-action-review-wave-31-v1",
        "run_id": "RUN-169R-INDEPENDENT-OUTCOME-NEUTRAL-FLEET-VEHICLE-ALERTS-CONFIG-ROUTE-ACTION-REVIEW-WAVE-31",
        "status": "GO_ONE_STATIC_OWNER_AND_BRIDGE_AUTHORIZED_FOR_LATER_INTEGRATION_ZERO_CURRENT_OR_DOWNSTREAM_CREDIT",
        "reviewed_on": "2026-08-30",
        "decision": "GO",
        "pins": {
            "checkpoint_commit": HEAD,
            "checkpoint_tree": TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "subtrees": SUBTREES,
            "cohort_generator": file_record(COHORT_GENERATOR),
            "cohort": file_record(COHORT),
            "cohort_self_seal_sha256": COHORT_SELF_SEAL,
            "cohort_candidate_record_sha256": CANDIDATE_HASH,
            "queue_record_self_seal_sha256": QUEUE_SEAL,
            "source_packet_sha256": SOURCE_PACKET_HASH,
            "selected_controller_method_slice_sha256": METHOD_SLICE_HASH,
            "prompt_path": cohort["pins"]["prompt_path"],
            "prompt_sha256": cohort["pins"]["prompt_sha256"],
            "cohort_inputs": cohort["pins"]["inputs"],
            "generator": file_record(GENERATOR),
        },
        "architecture_rule": cohort["architecture_rule"],
        "methods": {"reviewers": 1, "synthesizers": 1, "static_source_only": True, "application_executed": False, "framework_routes_executed": False, "database_used": False, "build_used": False, "browser_used": False, "tests_executed": False},
        "verified_counts": {"cohort_records": 1, "independent_review_records": 1, "owner_route_actions": 1, "shared_relations": 0, "evidence_gaps": 0, "route_owners_authorized_for_later_overlay": 1, "controller_action_bridges_authorized_for_later_overlay": 1, "page_owners_authorized": 0, "current_overlay_rows_written": 0, "provisional_assurance_observations": len(observations), "final_findings": 0},
        "independent_candidate_reviews": [review],
        "synthesis_review": synthesis,
        "action_decisions": decision,
        "provisional_assurance_observations": observations,
        "source_packet_boundary": {"corrected_exact_artifact_review_complete": True, "selected_source_review_complete": True, "source_packet_completeness_beyond_selected_action_claimed": False, "ownership_material_expansion_required": False, "correctness_only_observations_authorize_no_credit": True},
        "context_and_historical_reconciliation": {"consumer_and_caller_context_only": cohort["source_review_packet"]["consumer_and_caller_context_only"], "historical_pin_reconciliation": cohort["historical_pin_reconciliation"], "historical_route_file_sha256": candidate["source"]["route_file_sha256"], "historical_route_file_blob_id": candidate["source"]["route_file_blob_id"], "current_route_file_sha256": "4be79ba4a0957f81f3e99de8eea7f29a398f8a115957bd44af06dbbf78fe2c4c", "current_route_file_blob_id": "f0b2b8c199ada1d8ef8bdb41c99bfc2ac02f93d2", "exact_statement_preserved_across_drift": True, "historical_review_or_outcome_inherited": False},
        "queue_boundary_reconciliation": {"reviewed_key_count_before_overlay": 118, "reviewed_key_list_sha256_before_overlay": REVIEWED_KEY_HASH, "selected_index_83_still_unintegrated": True, "true_next_unresolved_index_before_overlay": 83, "true_next_unresolved_queue_id_before_overlay": "RUN090-ROUTE-0084", "post_owner_overlay_next_index": 84, "post_owner_overlay_next_queue_id": "RUN090-ROUTE-0085", "post_owner_overlay_next_route_record_id": "RUN077-ROUTE-0693"},
        "credit_boundary": {"reviewed_static_route_feature_ownership_for_1_record": True, "reviewed_static_controller_action_bridge_for_1_action": True, "bounded_overlay_integration_authorized_later_only": True, **false_credit},
        "completion_boundary": cohort["completion_boundary"],
        "source_review_complete": True,
        "artifact_completion_test_met": False,
        "audit_completion_test_met": False,
        "wrote_files": [f"{PREFIX}/{GENERATOR}", f"{PREFIX}/{OUTPUT}"],
    }
    payload["self_seal"] = {"algorithm": "sha256-canonical-json-with-self-seal-omitted", "sha256": canonical(payload)}
    return payload


def main() -> None:
    payload = build()
    (AUDIT / OUTPUT).write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")
    parsed = strict_json(OUTPUT)
    seal = parsed.pop("self_seal")
    assert seal["sha256"] == canonical(parsed)
    assert not git("status", "--porcelain", "--untracked-files=no")
    expected_untracked = {
        f"{PREFIX}/{OUTPUT}",
        f"{PREFIX}/{GENERATOR}",
        f"{PREFIX}/generators/integrate-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.py",
        f"{PREFIX}/generators/materialize-independent-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-review-wave-31.py",
        f"{PREFIX}/generators/materialize-run-171-reviewed-fleet-vehicle-alerts-config-route-action-reporting-wave-31.py",
        f"{PREFIX}/generators/materialize-run-172-audit-dashboard-verification-wave-31.py",
    }
    actual_untracked = {line[3:] for line in git("status", "--porcelain").splitlines() if line.startswith("?? ")}
    assert actual_untracked == expected_untracked, (actual_untracked, expected_untracked)
    assert not list(AUDIT.rglob("__pycache__"))
    print(json.dumps({
        "status": payload["status"],
        "generator_sha256": audit_sha(GENERATOR),
        "receipt_sha256": audit_sha(OUTPUT),
        "review_seal": payload["independent_candidate_reviews"][0]["review_record_sha256"],
        "synthesis_seal": payload["synthesis_review"]["synthesis_record_sha256"],
        "decision_seal": payload["action_decisions"]["decision_record_sha256"],
        "observation_seals": [item["observation_record_sha256"] for item in payload["provisional_assurance_observations"]],
        "self_seal": payload["self_seal"]["sha256"],
    }, indent=2))


if __name__ == "__main__":
    main()
