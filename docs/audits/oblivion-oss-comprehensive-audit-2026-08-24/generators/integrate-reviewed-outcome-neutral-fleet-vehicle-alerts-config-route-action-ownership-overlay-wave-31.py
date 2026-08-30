#!/usr/bin/env python3
"""Integrate exactly one reviewed vehicle alerts-config route owner and bridge."""
from __future__ import annotations

from collections import Counter
from decimal import Decimal, ROUND_HALF_UP
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT = Path(__file__).resolve().parents[1]
PREFIX = AUDIT.relative_to(REPO).as_posix()
GENERATOR = "generators/integrate-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.py"
OUTPUT = "evidence/source/current-run-170-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.json"
HEAD = "ce81babc43c2077e573214dcb5c9e212e2d0a418"
TREE = "5c7c4ef73d64009eac9928f5ef968b05c1e7a74d"
APPLICATION_COMMIT = "e488bd3edcda0f154f87e8bbed972f14db409b82"
APPLICATION_TREE = "9e93b8aea4f4b907cde3dc59dd0520fba5bd7080"
SUBTREES = {
    "app": "b9a9a672bea01473d8be96a0afb548e6291aee9c",
    "routes": "9392e22e4c472610da98977bec4e112092d223b9",
    "resources/js": "776359c5b8b06a55fcf5fe4464bc3e00d01248e5",
    "resources/js/pages": "077d40c746018b655c9b9f8c1ee3f87c2d792a8c",
    "tests": "90886d938c57ab7b45c9301514077d16e4c6b470",
}
BASE_COLLECTOR = "generators/integrate-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.py"
RUN149 = "evidence/source/current-run-149-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.json"
RUN149R = "evidence/source/current-run-149r-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25.json"
RUN153_GENERATOR = "generators/integrate-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.py"
RUN153 = "evidence/source/current-run-153-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.json"
RUN153R_GENERATOR = "generators/materialize-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.py"
RUN153R = "evidence/source/current-run-153r-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.json"
QUEUE = "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json"
MATRIX = "03-feature-to-benchmark-matrix.csv"
COHORT_GENERATOR = "generators/build-outcome-neutral-fleet-vehicle-alerts-config-route-action-cohort-wave-31.py"
COHORT = "evidence/source/root-run-169-outcome-neutral-fleet-vehicle-alerts-config-route-action-cohort-wave-31.json"
REVIEW_GENERATOR = "generators/materialize-independent-outcome-neutral-fleet-vehicle-alerts-config-route-action-review-wave-31.py"
REVIEW = "evidence/source/raw-run-169r-independent-outcome-neutral-fleet-vehicle-alerts-config-route-action-review-wave-31.json"

EXPECTED = {
    BASE_COLLECTOR: "b5c7f04cd44ecd73dda9c7fe4a9e2e8616c68674cdc52d393ec696b06ad2327e",
    RUN149: "12a52c434ecd18a5c6a644378070aa5ab046f5e7080726b983ded8d9c7377a55",
    RUN149R: "545694fc1b7bd5f4af244617fb421ece1265fe6e6f2cad2ca834115e7a9e75a2",
    RUN153_GENERATOR: "00b90c5932614eaf67cbca29c860924fad67190605bbf476fdc285174831ea83",
    RUN153: "9b7e382f83787d807de8d752ecb3e6524280c707899aba78d47082765272e815",
    RUN153R_GENERATOR: "6fb94e5382120e4d74b1a4b28fbdc75141e248f4585e850825e6f302d3d741ef",
    RUN153R: "7f1da8394a8054f01f34fb943a3fba6601bf70ea06d69cf97033f2208edf4461",
    QUEUE: "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    MATRIX: "3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0",
    COHORT_GENERATOR: "ffb1dba865a50f3cdcbf4e3ce285482e062bb023145089353a68f705d0646c7e",
    COHORT: "2fc20f6e528adae64979a763e6f28dd86018c2ecd87bbb0b651ddf6eee158fb2",
    REVIEW_GENERATOR: "6cdceb0f2b25a33fba8675f614f61a2aa5692dfb0a02768887755dd8fdfa4687",
    REVIEW: "698257a0e6543d685397977d658d9681281ce6634f709ced73939c09e76f02bc",
}
EXPECTED_BLOBS = {
    BASE_COLLECTOR: "e8d9d1c9889be589a22db6dfea53d3122adce247",
    RUN149: "c5f0a3bda99167f66650d63bfdb35e18d8ed93b5",
    RUN149R: "66f0faa0b1894160e299fecd2e1c43962a3138d7",
    RUN153_GENERATOR: "c46a48c87203410951715006c8253c84851e9d76",
    RUN153: "818b891cff9965193c60d83d0580c21a48d1a682",
    RUN153R_GENERATOR: "0c5551cefeaf89e04c427079c1f7659e130e83f7",
    RUN153R: "20bb3580ba2cb60205694d52aa72e16cd2f2a423",
    QUEUE: "66809274d25916f4e0d2426419bfde6e371ba1f1",
    MATRIX: "1f5fdab3ae80ae4ec1b9bc4ee47eef695bdd5416",
    COHORT_GENERATOR: "0199cbc6044817f4484fa7ce4824d0dcff1bd9cf",
    COHORT: "e7e08a7a0232f9691fc48bb46f84770f9bb595dc",
    REVIEW_GENERATOR: "f848b03deca71e3bf3d5a2041227e06d2a83fb02",
    REVIEW: "7a6f7d4a6967462e27f1811ba3d64d4aaae1422b",
}
CANDIDATE_HASH = "7d6b2bddea1f1dce45c8ba3a80feaae4e3efbe5e6b0de022b84ab172dba9a5f1"
QUEUE_SEAL = "d29353be38d964311d6586311d654c13dc2a39da9b7bcdb8a6a75d69fa511731"
EXPECTED_IDENTITY = {
    "prior_source": "93e9ab12d9441ec6f568221db39c4d215c41cb9fc642219a2697e70df79e84c2",
    "combined_source": "d691bbfc9eabfa3f34f0df294c24c6890d3082b2149ed8b553cc88747e3143e5",
    "prior_bridge": "3ad7591c68727b4d8f724fd819b91d8a5b775cb76072f4e20f05e8cf458e2c0e",
    "combined_bridge": "19ed2b2cabf56de20dc2ae10b70877536140dc76285c5c64462d71535b302498",
    "prior_reviewed": "2b0612a3dc8ca47d3e5d12877677e1c85598364cf61bfda1ea5c20232ae029f5",
    "combined_reviewed": "acfca5e54d64c54334dbd94b30104244b3d2d6722a5426439aec7a8aa62d3ab5",
    "prior_reviewed_canonical_json": "e598ea44dc0abf67f5dae3374f2d5608d10e5a2edae475ed18a8f0ecaf227e40",
    "combined_reviewed_canonical_json": "e85b37e5410c1cc861f9116061e88fb82fdb854e5dc94e56eefe1947b3a7b510",
}


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


def hlist(values: list[str] | set[str]) -> str:
    return digest("\n".join(sorted(set(values))).encode())


def strict_json(relative: str) -> dict[str, Any]:
    def hook(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, (relative, key)
            result[key] = value
        return result

    value = json.loads((AUDIT / relative).read_text(encoding="utf-8"), object_pairs_hook=hook)
    assert isinstance(value, dict)
    return value


def sealed(record: dict[str, Any], field: str) -> dict[str, Any]:
    record[field] = canonical(record)
    return record


def validate_inputs() -> tuple[dict[str, Any], dict[str, Any], dict[str, Any], dict[str, Any], dict[str, Any]]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == HEAD and git("rev-parse", "HEAD^{tree}") == TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    for path, expected in SUBTREES.items():
        assert git("rev-parse", f"HEAD:{path}") == expected
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{path}") == expected
    assert not git("status", "--porcelain", "--", "app", "routes", "resources/js", "tests", "database")
    for relative, expected in EXPECTED.items():
        assert audit_sha(relative) == expected, relative
        assert git("rev-parse", f"HEAD:{PREFIX}/{relative}") == EXPECTED_BLOBS[relative], relative

    run149, run153, run153r = strict_json(RUN149), strict_json(RUN153), strict_json(RUN153R)
    cohort, review = strict_json(COHORT), strict_json(REVIEW)
    assert run153["combined_counts"]["source_owner_records"] == 664
    assert run153["combined_counts"]["static_controller_action_bridges"] == 95
    assert run153["queue_accounting"]["reviewed_queue_surface_rows"] == 118
    assert run153r["status"] == "GO_THREE_PART_POST_COMMIT_REVIEW_COMPLETE_REPORTING_ONLY_ZERO_NEW_OR_DOWNSTREAM_CREDIT"
    assert run153r["decision"]["verdict"] == "GO" and run153r["decision"]["discrepancies"] == 0
    assert {key for key, value in run153r["credit_boundary"].items() if value} == {"INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"}

    candidate = cohort["records"][0]
    decision = review["action_decisions"]
    assert canonical(candidate) == CANDIDATE_HASH and candidate["queue_record_sha256"] == QUEUE_SEAL
    assert review["status"] == "GO_ONE_STATIC_OWNER_AND_BRIDGE_AUTHORIZED_FOR_LATER_INTEGRATION_ZERO_CURRENT_OR_DOWNSTREAM_CREDIT"
    review_without_seal = {key: value for key, value in review.items() if key != "self_seal"}
    assert review["self_seal"]["sha256"] == canonical(review_without_seal)
    assert decision["decision_record_sha256"] == canonical({key: value for key, value in decision.items() if key != "decision_record_sha256"})
    assert decision["outcome"] == "OWNER_ROUTE_ACTION"
    assert decision["route_ownership_authorized_for_later_overlay"] is True
    assert decision["controller_action_bridge_authorized_for_later_overlay"] is True
    assert decision["page_ownership_authorized"] is False and decision["current_overlay_credit_awarded"] is False
    assert review["synthesis_review"]["outcome_variables"] == {"O": 1, "S": 0, "E": 0}
    assert {key for key, value in review["credit_boundary"].items() if value} == {
        "reviewed_static_route_feature_ownership_for_1_record",
        "reviewed_static_controller_action_bridge_for_1_action",
        "bounded_overlay_integration_authorized_later_only",
    }
    return run149, run153, run153r, cohort, review


def main() -> None:
    run149, run153, run153r, cohort, review = validate_inputs()
    candidate = cohort["records"][0]
    decision = review["action_decisions"]
    review_record = review["independent_candidate_reviews"][0]
    synthesis = review["synthesis_review"]
    method_slice = cohort["source_review_packet"]["selected_controller_method_slice"]
    resolution = candidate["secondary_lane"]["backend_method_relation"]["resolution"]
    feature = candidate["feature_identity_projection"]

    row = sealed({
        "overlay_mapping_id": "RUN170-ROUTE-01",
        "candidate_record_sha256": CANDIDATE_HASH,
        "queue_record_self_seal_sha256": QUEUE_SEAL,
        "decision_record_sha256": decision["decision_record_sha256"],
        "surface": "ROUTE_SOURCE_RECORD",
        "source_record_id": "RUN077-ROUTE-0692",
        "source_record_key": "route|RUN077-ROUTE-0692|CAP-FLEET-VEHICLE-REGISTER",
        "feature_id": "CAP-FLEET-VEHICLE-REGISTER",
        "feature_class": feature["feature_class"],
        "module": feature["module"],
        "user_job": feature["user_job"],
        "source": candidate["source"],
        "source_provenance": {
            "immutable_queue_route_file_sha256": candidate["source"]["route_file_sha256"],
            "immutable_queue_route_file_blob_id": candidate["source"]["route_file_blob_id"],
            "current_review_route_file_sha256": review["context_and_historical_reconciliation"]["current_route_file_sha256"],
            "current_review_route_file_blob_id": review["context_and_historical_reconciliation"]["current_route_file_blob_id"],
            "exact_statement_preserved_across_drift": True,
            "historical_hash_not_presented_as_current": True,
        },
        "review_outcome": "OWNER_ROUTE_ACTION",
        "review_rationale": review_record["rationale"],
        "static_source_feature_ownership_credit": True,
        "credit_boundary": {key: False for key in (
            "page_ownership", "frontend_caller_ownership", "framework_route_reachability",
            "canonical_object_ownership_correctness", "approved_site_scope_correctness", "permission_correctness",
            "privacy_correctness", "direct_object_concealment_correctness", "query_projection_correctness",
            "runtime", "database", "build", "application_browser", "executed_tests", "benchmark",
            "final_no_match_or_NCM", "final_finding", "completion", "audit_complete",
        )},
    }, "overlay_row_sha256")
    bridge = sealed({
        "bridge_id": "RUN170-BRIDGE-01",
        "candidate_record_sha256": CANDIDATE_HASH,
        "queue_record_self_seal_sha256": QUEUE_SEAL,
        "decision_record_sha256": decision["decision_record_sha256"],
        "feature_id": "CAP-FLEET-VEHICLE-REGISTER",
        "route_record_id": "RUN077-ROUTE-0692",
        "controller_fqcn": resolution["resolved_fqcn"],
        "controller_file": resolution["controller_file"],
        "controller_file_sha256": resolution["controller_file_sha256"],
        "controller_file_blob_id": method_slice["source_file_blob_id"],
        "method": "alertsConfig",
        "definition_anchor": resolution["definition_anchor"],
        "method_review_slice_sha256": method_slice["text_sha256"],
        "review_outcome": "OWNER_ROUTE_ACTION",
        "static_controller_action_bridge_credit": True,
        "page_ownership_credit": False,
        "correctness_credit": False,
        "runtime_credit": False,
        "application_browser_credit": False,
        "executed_test_credit": False,
        "final_finding_credit": False,
        "completion_credit": False,
    }, "bridge_row_sha256")

    spec = importlib.util.spec_from_file_location("run149_base", AUDIT / BASE_COLLECTOR)
    assert spec and spec.loader
    base = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(base)
    prior_records, prior_bridges = base.collect_prior_state()
    prior_records += run149["overlay_source_records"] + run153["overlay_source_records"]
    prior_bridges += run149["new_static_controller_action_bridges"] + run153["new_static_controller_action_bridges"]
    assert (len(prior_records), len(prior_bridges)) == (664, 95)
    assert hlist([item["source_record_key"] for item in prior_records]) == EXPECTED_IDENTITY["prior_source"]
    assert hlist(["|".join((item["controller_file"], item["method"], item["feature_id"])) for item in prior_bridges]) == EXPECTED_IDENTITY["prior_bridge"]
    records, bridges = prior_records + [row], prior_bridges + [bridge]
    assert len({item["source_record_id"] for item in records}) == len({item["source_record_key"] for item in records}) == 665
    bridge_keys = [(item["controller_file"], item["method"], item["feature_id"]) for item in bridges]
    assert len(bridge_keys) == len(set(bridge_keys)) == 96
    routes = [item for item in records if item["surface"] == "ROUTE_SOURCE_RECORD"]
    pages = [item for item in records if item["surface"] == "PAGE_ROOT_SOURCE_RECORD"]
    assert (len(routes), len(pages)) == (308, 357)
    features = {item["feature_id"] for item in records}
    route_features = {item["feature_id"] for item in routes}
    page_features = {item["feature_id"] for item in pages}
    assert (len(features), len(route_features), len(page_features), len(route_features & page_features)) == (256, 64, 242, 50)
    assert Counter({item["feature_id"]: item["feature_class"] for item in records}.values()) == {"H": 234, "D": 22}
    percent = (Decimal(665) * 100 / Decimal(3929)).quantize(Decimal("0.000001"), rounding=ROUND_HALF_UP)
    assert format(percent, "f") == "16.925426"

    prior_keys = base.collect_prior_reviewed_queue_keys() | {"route|RUN077-ROUTE-0689", "route|RUN077-ROUTE-0690"}
    assert len(prior_keys) == 118 and "route|RUN077-ROUTE-0692" not in prior_keys
    assert hlist(prior_keys) == EXPECTED_IDENTITY["prior_reviewed"]
    assert canonical(sorted(prior_keys)) == EXPECTED_IDENTITY["prior_reviewed_canonical_json"]
    reviewed_keys = prior_keys | {"route|RUN077-ROUTE-0692"}
    assert len(reviewed_keys) == 119
    assert hlist(reviewed_keys) == EXPECTED_IDENTITY["combined_reviewed"]
    assert canonical(sorted(reviewed_keys)) == EXPECTED_IDENTITY["combined_reviewed_canonical_json"]
    queue_rows = strict_json(QUEUE)["records"]
    assert queue_rows[81]["canonical_key"] in reviewed_keys
    assert queue_rows[82]["canonical_key"] in reviewed_keys
    assert queue_rows[83]["canonical_key"] in reviewed_keys
    next_index = next(index for index in range(84, len(queue_rows)) if queue_rows[index]["canonical_key"] not in reviewed_keys)
    assert next_index == 84
    next_row = queue_rows[next_index]
    assert (
        next_row["queue_id"], next_row["source_record_id"], next_row["source"]["literal_route_name"],
        next_row["source"]["action_expression"], next_row["queue_record_sha256"],
    ) == (
        "RUN090-ROUTE-0085", "RUN077-ROUTE-0693", "fleet-assets.trips.index",
        "[VehicleController::class, 'trips']", "928eeec741742f8329dd7e191a71f2d5249775b6de64e6a698a72836345ca011",
    )

    observations = review["provisional_assurance_observations"]
    assert len(observations) == 3
    for item in observations:
        assert item["status"] == "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING"
        assert item["observation_record_sha256"] == canonical({key: value for key, value in item.items() if key != "observation_record_sha256"})
        assert item["correctness_credit_authorized"] is False and item["final_finding_credit_authorized"] is False

    counts = {
        "source_owner_records": 665,
        "route_owner_records": 308,
        "page_owner_records": 357,
        "distinct_feature_ids": len(features),
        "distinct_H_feature_ids": 234,
        "distinct_D_feature_ids": 22,
        "route_distinct_feature_ids": len(route_features),
        "page_distinct_feature_ids": len(page_features),
        "route_page_feature_overlap": len(route_features & page_features),
        "static_controller_action_bridges": 96,
        "bounded_static_source_denominator": 3929,
        "bounded_static_source_ownership_percent": format(percent, "f"),
        "bounded_static_source_residual_records": 3264,
        "residual_explicit_unmapped_routes": 2893,
        "semantic_shared_routes": 12,
        "reviewed_alias_routes": 5,
        "reviewed_dead_routes": 0,
        "evidence_gap_routes_tagged_within_residual": 7,
        "residual_unadjudicated_page_roots": 345,
        "semantic_shared_page_roots": 9,
        "reviewed_alias_page_roots": 0,
        "reviewed_dead_page_roots": 0,
        "evidence_gap_page_roots_tagged_within_residual": 1,
    }
    queue_counts = {
        "direct_exact_queue_records": 507,
        "reviewed_queue_surface_rows": 119,
        "owner_queue_surface_rows": 97,
        "shared_queue_surface_rows": 10,
        "alias_queue_surface_rows": 5,
        "dead_queue_surface_rows": 0,
        "evidence_gap_queue_surface_rows": 7,
        "pending_unreviewed_queue_surface_rows": 388,
        "queue_surfaces_without_ownership": 410,
        "new_reviewed_route_surface_rows": 1,
        "new_owner_route_surface_rows": 1,
    }
    assert 3929 == 665 + 3264 and 665 == 308 + 357
    assert 3218 == 308 + 12 + 5 + 0 + 2893
    assert 711 == 357 + 9 + 0 + 0 + 345
    assert 507 == 119 + 388 and 119 == 97 + 10 + 5 + 0 + 7
    assert 410 == 388 + 10 + 5 + 0 + 7

    identity = {
        "hash_algorithm": "sha256-of-lf-joined-sorted-unique-utf8",
        "prior_source_record_key_list_sha256": hlist([item["source_record_key"] for item in prior_records]),
        "combined_source_record_key_list_sha256": hlist([item["source_record_key"] for item in records]),
        "prior_bridge_key_list_sha256": hlist(["|".join((item["controller_file"], item["method"], item["feature_id"])) for item in prior_bridges]),
        "combined_bridge_key_list_sha256": hlist(["|".join((item["controller_file"], item["method"], item["feature_id"])) for item in bridges]),
        "prior_reviewed_queue_key_list_sha256": hlist(prior_keys),
        "combined_reviewed_queue_key_list_sha256": hlist(reviewed_keys),
        "canonical_json_reviewed_key_hashes": {"prior": canonical(sorted(prior_keys)), "combined": canonical(sorted(reviewed_keys))},
        "new_overlay_source_records_sha256": canonical([row]),
        "new_action_bridges_sha256": canonical([bridge]),
        "review_record_sha256": review_record["review_record_sha256"],
        "synthesis_record_sha256": synthesis["synthesis_record_sha256"],
        "decision_record_sha256": decision["decision_record_sha256"],
        "observation_record_sha256s": [item["observation_record_sha256"] for item in observations],
    }
    assert identity["combined_source_record_key_list_sha256"] == EXPECTED_IDENTITY["combined_source"]
    assert identity["combined_bridge_key_list_sha256"] == EXPECTED_IDENTITY["combined_bridge"]

    false_credit = {key: False for key in (
        "static_page_feature_ownership", "frontend_caller_ownership", "complete_route_page_feature_crosswalk",
        "framework_route_reachability", "canonical_object_ownership_correctness", "approved_site_scope_correctness",
        "permission_correctness", "privacy_correctness", "direct_object_concealment_correctness",
        "query_projection_correctness", "runtime", "database", "build", "application_browser",
        "responsive_application", "executed_tests", "application_source_mutation", "matrix_mutation",
        "benchmark", "final_no_match_or_NCM", "ease", "release", "pass", "final_finding",
        "feature_completion", "completion", "gate_4", "audit_complete",
    )}
    input_map = dict(EXPECTED)
    output: dict[str, Any] = {
        "schema_version": "run-170-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31-v1",
        "run_id": "RUN-170-REVIEWED-OUTCOME-NEUTRAL-FLEET-VEHICLE-ALERTS-CONFIG-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-31",
        "status": "ONE_REVIEWED_FLEET_VEHICLE_ALERTS_CONFIG_ROUTE_ACTION_OWNER_AND_BRIDGE_INTEGRATED_STATIC_ONLY",
        "generated_on": "2026-08-30",
        "pins": {
            "checkpoint_commit": HEAD,
            "checkpoint_tree": TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "subtrees": SUBTREES,
            "generator": f"{PREFIX}/{GENERATOR}",
            "generator_sha256": audit_sha(GENERATOR),
            "generator_blob_id": git("hash-object", "--", str(AUDIT / GENERATOR)),
            "inputs": input_map,
            "input_blobs": EXPECTED_BLOBS,
            "input_map_sha256": canonical(input_map),
        },
        "architecture_rule": review["architecture_rule"],
        "baseline": {
            "source_owner_records": 664,
            "route_owner_records": 307,
            "page_owner_records": 357,
            "static_controller_action_bridges": 95,
            "reviewed_queue_surface_rows": 118,
            "pending_unreviewed_queue_surface_rows": 389,
            "latest_independent_overlay_review": {"run_id": run153r["run_id"], "status": run153r["status"], "reporting_only_credit": True, "new_or_current_or_downstream_credit": False},
        },
        "reviewed_overlay": {"producer_run_id": cohort["run_id"], "review_run_id": review["run_id"], "reviewed_route_actions": 1, "owner_route_actions": 1, "accepted_source_owner_records": 1, "accepted_route_owner_records": 1, "accepted_page_owner_records": 0, "accepted_controller_action_bridges": 1, "new_distinct_feature_ids": 0, "current_static_overlay_credit_applied": True, "correctness_or_downstream_credit_authorized": False, "final_finding_credit_authorized": False},
        "provisional_assurance_observation_preservation": {"observations": observations, "observation_count": len(observations), "provisional_source_observations_only": True, "correctness_credit_authorized": False, "final_finding_credit_authorized": False},
        "reviewer_lineage": {"independent_candidate_review": review_record, "synthesis_review": synthesis, "action_decision": decision},
        "source_packet_boundary": review["source_packet_boundary"],
        "combined_counts": counts,
        "queue_accounting": queue_counts,
        "queue_boundary": {"preceding_indices_81_and_82_not_recredited": True, "selected_index_83_integrated": True, "next_unresolved_index": next_index, "next_unresolved_queue_id": next_row["queue_id"], "next_unresolved_route_record_id": next_row["source_record_id"], "next_unresolved_route_name": next_row["source"]["literal_route_name"], "next_unresolved_action_expression": next_row["source"]["action_expression"], "next_unresolved_queue_record_sha256": next_row["queue_record_sha256"], "reviewed_key_count": len(reviewed_keys), "reviewed_key_list_sha256": hlist(reviewed_keys), "reviewed_key_list_canonical_json_sha256": canonical(sorted(reviewed_keys))},
        "noninheritance_boundary": {"consumer_and_caller_context_only": cohort["source_review_packet"]["consumer_and_caller_context_only"], "historical_pin_reconciliation": cohort["historical_pin_reconciliation"], "historical_route_hash_not_presented_as_current": True, "page_caller_service_or_model_not_inherited_or_recredited": True, "neighbor_identity_or_outcome_not_inherited": True},
        "overlay_source_records": [row],
        "new_static_controller_action_bridges": [bridge],
        "reviewed_non_owner_outcomes": [],
        "identity": identity,
        "outcome_conservation": {"reviewed_outcomes_equation": "1 = 1 owner + 0 shared + 0 evidence gap", "bounded_source_equation": "3929 = 665 owner + 3264 non-owner residual", "owner_surface_equation": "665 = 308 route + 357 page", "feature_union_equation": "256 = 64 route + 242 page - 50 overlap", "route_universe_equation": "3218 = 308 owner + 12 shared + 5 alias + 0 dead + 2893 residual", "page_universe_equation": "711 = 357 owner + 9 shared + 0 alias + 0 dead + 345 residual", "queue_equation": "507 = 119 reviewed + 388 pending", "reviewed_queue_equation": "119 = 97 owner + 10 shared + 5 alias + 0 dead + 7 evidence gap", "queue_without_ownership_equation": "410 = 388 pending + 10 shared + 5 alias + 0 dead + 7 evidence gap"},
        "credit_boundary": {"STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD": True, "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION": True, **false_credit},
        "mutation_attestation": {"application_source_changed": False, "test_files_changed": False, "matrix_changed": False, "reports_changed": False, "dashboard_generator_changed": False, "dashboard_html_changed": False, "runtime_or_external_system_changed": False, "audit_artifacts_only": True, "run170_producer_scope_contains_only_generator_and_receipt": True, "later_owned_generator_drafts_present_outside_run170_scope": True},
        "completion_boundary": review["completion_boundary"],
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [f"{PREFIX}/{GENERATOR}", f"{PREFIX}/{OUTPUT}"],
    }
    output["self_seal"] = {"algorithm": "sha256-canonical-json-with-self-seal-omitted", "sha256": canonical(output)}
    (AUDIT / OUTPUT).write_text(json.dumps(output, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")
    parsed = strict_json(OUTPUT)
    seal = parsed.pop("self_seal")
    assert seal["sha256"] == canonical(parsed)
    assert not git("status", "--porcelain", "--untracked-files=no")
    expected_untracked = {
        f"{PREFIX}/{GENERATOR}", f"{PREFIX}/{OUTPUT}",
        f"{PREFIX}/generators/materialize-independent-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-review-wave-31.py",
        f"{PREFIX}/generators/materialize-run-171-reviewed-fleet-vehicle-alerts-config-route-action-reporting-wave-31.py",
        f"{PREFIX}/generators/materialize-run-172-audit-dashboard-verification-wave-31.py",
    }
    actual_untracked = {line[3:] for line in git("status", "--porcelain").splitlines() if line.startswith("?? ")}
    assert actual_untracked == expected_untracked, (actual_untracked, expected_untracked)
    assert not list(AUDIT.rglob("__pycache__"))
    print(json.dumps({"status": output["status"], "generator_sha256": audit_sha(GENERATOR), "receipt_sha256": audit_sha(OUTPUT), "self_seal": output["self_seal"]["sha256"], "counts": counts, "queue_accounting": queue_counts, "identity": identity, "overlay_row_sha256": row["overlay_row_sha256"], "bridge_row_sha256": bridge["bridge_row_sha256"]}, indent=2))


if __name__ == "__main__":
    main()
