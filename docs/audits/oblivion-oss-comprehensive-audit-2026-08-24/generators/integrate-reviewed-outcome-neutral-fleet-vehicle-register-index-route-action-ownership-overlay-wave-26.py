#!/usr/bin/env python3
"""Integrate exactly one RUN152R vehicle-index route owner and bridge."""
from __future__ import annotations

from collections import Counter
from decimal import Decimal, ROUND_HALF_UP
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
from typing import Any

ROOT = Path(__file__).resolve().parents[4]
AUDIT = Path(__file__).resolve().parents[1]
PREFIX = AUDIT.relative_to(ROOT).as_posix()
GENERATOR = "generators/integrate-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.py"
OUTPUT = "evidence/source/current-run-153-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.json"
RUN149_GENERATOR = "generators/integrate-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.py"
BASELINE = "evidence/source/current-run-149-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.json"
BASELINE_REVIEW_GENERATOR = "generators/materialize-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25.py"
BASELINE_REVIEW = "evidence/source/current-run-149r-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25.json"
COHORT_GENERATOR = "generators/build-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26.py"
COHORT = "evidence/source/root-run-152-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26.json"
REVIEW_GENERATOR = "generators/materialize-independent-outcome-neutral-fleet-vehicle-register-index-route-action-review-wave-26.py"
REVIEW = "evidence/source/raw-run-152r-independent-outcome-neutral-fleet-vehicle-register-index-route-action-review-wave-26.json"
QUEUE = "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json"
HEAD = "12ac4a435deceb364ad0f23e97fad0677dfa1d1c"
TREE = "dfead8751310ceed65566e8c1148cfd1061056fd"
EXPECTED = {
    RUN149_GENERATOR: "b5c7f04cd44ecd73dda9c7fe4a9e2e8616c68674cdc52d393ec696b06ad2327e",
    BASELINE: "12a52c434ecd18a5c6a644378070aa5ab046f5e7080726b983ded8d9c7377a55",
    BASELINE_REVIEW_GENERATOR: "bd09980ac26a7e9d026eda518f1964f8a2a87ea75fecf271981e4017e8dcd57c",
    BASELINE_REVIEW: "545694fc1b7bd5f4af244617fb421ece1265fe6e6f2cad2ca834115e7a9e75a2",
    COHORT_GENERATOR: "7b3e6501d3fe806e7bb27be8d20236467496e20e101d42a9efc0741e67f0e336",
    COHORT: "5e987d8727896183aadf30b9000ed56b318e2f4c8935b6d77e3600999105eac4",
    REVIEW_GENERATOR: "ecf6c7aa7c68d1b7936086316927057726797f7fa61d3b76af0c7435844f4597",
    REVIEW: "43697db4e3a5743d6dc9b47a3e80c6ec5c528dba17c2e99a4a13f95933c899d8",
    QUEUE: "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    "03-feature-to-benchmark-matrix.csv": "3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0",
}
EXPECTED_BLOBS = {
    BASELINE: "c5f0a3bda99167f66650d63bfdb35e18d8ed93b5",
    BASELINE_REVIEW_GENERATOR: "8947c8e02aa766d752aa1c7cd345e62d2ff8fa2b",
    BASELINE_REVIEW: "66f0faa0b1894160e299fecd2e1c43962a3138d7",
    COHORT_GENERATOR: "7171cc58de0152219fc3fad83ca93385f333e80a",
    COHORT: "a7e8e85e36dd4d07cc8653922cabd47e422f41ab",
    REVIEW_GENERATOR: "458b582ca79f2b747c49e1e40a0cafe5415b9661",
    REVIEW: "6302d5e4e0d6e9a522e464eb99692ddc4a0f0c5d",
}
CANDIDATE_HASH = "08f334132340f905b012aea8f45be46ca2248e83c7eb05ecd1247e4d47e50321"
QUEUE_SEAL = "c15a3e4371f5d063066b013b824205c24d1ab6126f49aea3d266e9b897b146de"
DECISION_SEAL = ""  # checked from the self-sealed committed review below

def run(*args: str) -> bytes:
    return subprocess.run(args, cwd=ROOT, check=True, capture_output=True).stdout

def git(*args: str) -> str:
    return run("git", *args).decode().rstrip("\r\n")

def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()

def canonical(value: Any) -> str:
    return hashlib.sha256(json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode()).hexdigest()

def hlist(values: list[str]) -> str:
    return hashlib.sha256("\n".join(sorted(set(values))).encode()).hexdigest()

def strict(relative: str) -> dict[str, Any]:
    def hook(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        out: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in out, (relative, key)
            out[key] = value
        return out
    value = json.loads((AUDIT / relative).read_text(encoding="utf-8"), object_pairs_hook=hook)
    assert isinstance(value, dict)
    return value

def sealed(record: dict[str, Any], field: str) -> dict[str, Any]:
    record[field] = canonical(record)
    return record

def main() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == HEAD and git("rev-parse", "HEAD^{tree}") == TREE
    for relative, expected in EXPECTED.items(): assert sha(AUDIT / relative) == expected, relative
    for relative, expected in EXPECTED_BLOBS.items(): assert git("rev-parse", f"HEAD:{PREFIX}/{relative}") == expected, relative
    assert not git("status", "--porcelain", "--", "app", "routes", "resources/js", "tests", "database")
    spec = importlib.util.spec_from_file_location("run149", AUDIT / RUN149_GENERATOR)
    assert spec and spec.loader
    base = importlib.util.module_from_spec(spec); spec.loader.exec_module(base)
    baseline, baseline_review = strict(BASELINE), strict(BASELINE_REVIEW)
    cohort, review, queue_doc = strict(COHORT), strict(REVIEW), strict(QUEUE)
    assert baseline_review["status"] == "GO_THREE_PART_POST_COMMIT_REVIEW_COMPLETE_REPORTING_ONLY_ZERO_NEW_OR_DOWNSTREAM_CREDIT"
    assert baseline_review["pins"]["producer_sha256"] == EXPECTED[BASELINE]
    assert baseline_review["pins"]["producer_blob_id"] == EXPECTED_BLOBS[BASELINE]
    assert baseline_review["pins"]["producer_generator_sha256"] == EXPECTED[RUN149_GENERATOR]
    assert baseline_review["pins"]["producer_generator_blob_id"] == "e8d9d1c9889be589a22db6dfea53d3122adce247"
    assert baseline_review["pins"]["materializer_sha256"] == EXPECTED[BASELINE_REVIEW_GENERATOR]
    assert baseline_review["credit_boundary"]["INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"] is True
    assert all(baseline_review["credit_boundary"][key] is False for key in ("new_source_ownership", "new_route_ownership", "new_page_ownership", "new_controller_action_bridge", "current_overlay_ownership_credit", "framework_route_reachability", "runtime", "database", "build", "application_browser", "executed_tests", "benchmark", "final_finding", "completion", "audit_complete"))
    candidate, decision = cohort["records"][0], review["action_decisions"]
    assert canonical(candidate) == CANDIDATE_HASH and candidate["queue_record_sha256"] == QUEUE_SEAL
    assert decision["candidate_record_sha256"] == CANDIDATE_HASH and decision["queue_record_self_seal_sha256"] == QUEUE_SEAL
    assert decision["decision_record_sha256"] == canonical({k: v for k, v in decision.items() if k != "decision_record_sha256"})
    reviewers = review["independent_candidate_reviews"]
    assert len(reviewers) == 2 and [r["outcome"] for r in reviewers] == ["OWNER_ROUTE_ACTION", "OWNER_ROUTE_ACTION"]
    for item in reviewers: assert item["review_record_sha256"] == canonical({k: v for k, v in item.items() if k != "review_record_sha256"})
    synthesis = review["synthesis_review"]
    assert synthesis["synthesis_record_sha256"] == canonical({k: v for k, v in synthesis.items() if k != "synthesis_record_sha256"})
    assert synthesis["outcome_variables"] == {"O": 1, "S": 0, "E": 0}
    assert synthesis["bounded_overlay_integration_authorized_later_only"] is True
    page_owner_seal = cohort["existing_page_owner_reconciliation"]["record_sha256"]
    sentinel_seal = cohort["historical_route_classification_reconciliation"]["record_sha256"]
    assert page_owner_seal == "071be3674cfd4d743b32a70ac2ad63c84af38a11aab563b98393f22332282028"
    assert sentinel_seal == "876cc424a505e7b07c08e18c8c5efcbe6ddd7a9a330400055baed769469d11db"

    route = candidate["source"]
    resolution = candidate["secondary_lane"]["backend_method_relation"]["resolution"]
    method_slice = cohort["source_review_packet"]["selected_controller_method_slice"]
    feature = cohort["existing_page_owner_reconciliation"]["record"]["feature_identity_projection"]
    row = sealed({
        "overlay_mapping_id": "RUN153-ROUTE-01", "candidate_record_sha256": CANDIDATE_HASH,
        "queue_record_self_seal_sha256": QUEUE_SEAL, "decision_record_sha256": decision["decision_record_sha256"],
        "surface": "ROUTE_SOURCE_RECORD", "source_record_id": "RUN077-ROUTE-0690",
        "source_record_key": "route|RUN077-ROUTE-0690|CAP-FLEET-VEHICLE-REGISTER",
        "feature_id": "CAP-FLEET-VEHICLE-REGISTER", "feature_class": feature["feature_class"], "module": feature["module"], "user_job": feature["user_job"],
        "source": route, "review_outcome": "OWNER_ROUTE_ACTION",
        "review_rationale": "Two independent reviews and synthesis establish narrow static ownership of the exact vehicles index route/action; no page or correctness outcome is inherited.",
        "static_source_feature_ownership_credit": True,
        "credit_boundary": {key: False for key in ("page_ownership", "framework_route_reachability", "canonical_object_ownership_correctness", "approved_site_scope_correctness", "permission_correctness", "privacy_correctness", "direct_object_correctness", "query_projection_correctness", "runtime", "database", "build", "application_browser", "executed_tests", "benchmark", "final_finding", "completion", "audit_complete")},
    }, "overlay_row_sha256")
    bridge = sealed({
        "bridge_id": "RUN153-BRIDGE-01", "candidate_record_sha256": CANDIDATE_HASH,
        "queue_record_self_seal_sha256": QUEUE_SEAL, "decision_record_sha256": decision["decision_record_sha256"],
        "feature_id": "CAP-FLEET-VEHICLE-REGISTER", "route_record_id": "RUN077-ROUTE-0690",
        "controller_fqcn": resolution["resolved_fqcn"], "controller_file": resolution["controller_file"],
        "controller_file_sha256": resolution["controller_file_sha256"], "controller_file_blob_id": method_slice["source_file_blob_id"],
        "method": "index", "definition_anchor": resolution["definition_anchor"], "method_review_slice_sha256": method_slice["text_sha256"],
        "review_outcome": "OWNER_ROUTE_ACTION", "static_controller_action_bridge_credit": True,
        "page_ownership_credit": False, "correctness_credit": False, "runtime_credit": False,
        "application_browser_credit": False, "executed_test_credit": False, "final_finding_credit": False, "completion_credit": False,
    }, "bridge_row_sha256")

    prior_records, prior_bridges = base.collect_prior_state()
    prior_records += baseline["overlay_source_records"]
    prior_bridges += baseline["new_static_controller_action_bridges"]
    assert (len(prior_records), len(prior_bridges)) == (663, 94)
    records, bridges = prior_records + [row], prior_bridges + [bridge]
    assert len({r["source_record_id"] for r in records}) == len({r["source_record_key"] for r in records}) == 664
    bridge_keys = [(b["controller_file"], b["method"], b["feature_id"]) for b in bridges]
    assert len(bridge_keys) == len(set(bridge_keys)) == 95
    routes = [r for r in records if r["surface"] == "ROUTE_SOURCE_RECORD"]
    pages = [r for r in records if r["surface"] == "PAGE_ROOT_SOURCE_RECORD"]
    assert (len(routes), len(pages)) == (307, 357)
    features, route_features, page_features = ({r["feature_id"] for r in records}, {r["feature_id"] for r in routes}, {r["feature_id"] for r in pages})
    assert (len(features), len(route_features), len(page_features), len(route_features & page_features)) == (256, 64, 242, 50)
    assert Counter({r["feature_id"]: r["feature_class"] for r in records}.values()) == {"H": 234, "D": 22}
    percent = (Decimal(664) * 100 / Decimal(3929)).quantize(Decimal("0.000001"), rounding=ROUND_HALF_UP)
    assert format(percent, "f") == "16.899975"

    prior_keys = base.collect_prior_reviewed_queue_keys() | {"route|RUN077-ROUTE-0689"}
    assert len(prior_keys) == 117 and "route|RUN077-ROUTE-0690" not in prior_keys
    reviewed_keys = prior_keys | {"route|RUN077-ROUTE-0690"}
    assert len(reviewed_keys) == 118
    queue_rows = queue_doc["records"]
    assert queue_rows[80]["canonical_key"] in reviewed_keys and queue_rows[81]["canonical_key"] in reviewed_keys and queue_rows[82]["canonical_key"] in reviewed_keys
    next_index = next(i for i in range(82, len(queue_rows)) if queue_rows[i]["canonical_key"] not in reviewed_keys)
    assert next_index == 83
    next_row = queue_rows[next_index]
    assert (next_row["queue_id"], next_row["source_record_id"], next_row["queue_record_sha256"]) == ("RUN090-ROUTE-0084", "RUN077-ROUTE-0692", "d29353be38d964311d6586311d654c13dc2a39da9b7bcdb8a6a75d69fa511731")

    observations = review["provisional_assurance_observations"]
    assert len(observations) == 6
    for item in observations:
        assert item["status"] == "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING"
        assert item["observation_record_sha256"] == canonical({k: v for k, v in item.items() if k != "observation_record_sha256"})
        assert item["correctness_credit_authorized"] is False and item["final_finding_credit_authorized"] is False
    counts = {"source_owner_records": 664, "route_owner_records": 307, "page_owner_records": 357, "distinct_feature_ids": len(features), "distinct_H_feature_ids": 234, "distinct_D_feature_ids": 22, "route_distinct_feature_ids": len(route_features), "page_distinct_feature_ids": len(page_features), "route_page_feature_overlap": len(route_features & page_features), "static_controller_action_bridges": 95, "bounded_static_source_denominator": 3929, "bounded_static_source_ownership_percent": format(percent, "f"), "bounded_static_source_residual_records": 3265, "residual_explicit_unmapped_routes": 2894, "semantic_shared_routes": 12, "reviewed_alias_routes": 5, "reviewed_dead_routes": 0, "evidence_gap_routes_tagged_within_residual": 7, "residual_unadjudicated_page_roots": 345, "semantic_shared_page_roots": 9, "reviewed_alias_page_roots": 0, "reviewed_dead_page_roots": 0, "evidence_gap_page_roots_tagged_within_residual": 1}
    queue_counts = {"direct_exact_queue_records": 507, "reviewed_queue_surface_rows": 118, "owner_queue_surface_rows": 96, "shared_queue_surface_rows": 10, "alias_queue_surface_rows": 5, "dead_queue_surface_rows": 0, "evidence_gap_queue_surface_rows": 7, "pending_unreviewed_queue_surface_rows": 389, "queue_surfaces_without_ownership": 411, "new_reviewed_route_surface_rows": 1, "new_owner_route_surface_rows": 1}
    assert counts["source_owner_records"] + counts["bounded_static_source_residual_records"] == 3929
    assert queue_counts["reviewed_queue_surface_rows"] + queue_counts["pending_unreviewed_queue_surface_rows"] == 507
    assert sum(queue_counts[k] for k in ("owner_queue_surface_rows", "shared_queue_surface_rows", "alias_queue_surface_rows", "dead_queue_surface_rows", "evidence_gap_queue_surface_rows")) == 118

    input_map = {name: EXPECTED[name] for name in EXPECTED}
    identity = {"prior_source_record_key_list_sha256": hlist([r["source_record_key"] for r in prior_records]), "combined_source_record_key_list_sha256": hlist([r["source_record_key"] for r in records]), "prior_bridge_key_list_sha256": hlist(["|".join((b["controller_file"], b["method"], b["feature_id"])) for b in prior_bridges]), "combined_bridge_key_list_sha256": hlist(["|".join((b["controller_file"], b["method"], b["feature_id"])) for b in bridges]), "prior_reviewed_queue_key_list_sha256": hlist(list(prior_keys)), "combined_reviewed_queue_key_list_sha256": hlist(list(reviewed_keys)), "new_overlay_source_records_sha256": canonical([row]), "new_action_bridges_sha256": canonical([bridge]), "review_record_sha256s": [r["review_record_sha256"] for r in reviewers], "synthesis_record_sha256": synthesis["synthesis_record_sha256"], "decision_record_sha256": decision["decision_record_sha256"], "observation_record_sha256s": [o["observation_record_sha256"] for o in observations]}
    false_credit = {key: False for key in ("static_page_feature_ownership", "frontend_caller_ownership", "complete_route_page_feature_crosswalk", "framework_route_reachability", "canonical_object_ownership_correctness", "approved_site_scope_correctness", "permission_correctness", "privacy_correctness", "direct_object_correctness", "query_projection_correctness", "runtime", "database", "build", "application_browser", "responsive_application", "executed_tests", "application_source_mutation", "matrix_mutation", "benchmark", "final_no_match_or_NCM", "ease", "release", "pass", "final_finding", "feature_completion", "completion", "gate_4", "audit_complete")}
    out = {
        "schema_version": "run-153-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26-v1",
        "run_id": "RUN-153-REVIEWED-OUTCOME-NEUTRAL-FLEET-VEHICLE-REGISTER-INDEX-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-26",
        "status": "ONE_REVIEWED_FLEET_VEHICLE_REGISTER_INDEX_ROUTE_ACTION_OWNER_AND_BRIDGE_INTEGRATED_STATIC_ONLY", "generated_on": "2026-08-27",
        "pins": {"checkpoint_commit": HEAD, "checkpoint_tree": TREE, "application_commit": cohort["pins"]["application_commit"], "application_tree": cohort["pins"]["application_tree"], "subtrees": {k: cohort["pins"][k] for k in ("app_tree", "routes_tree", "resources_js_tree", "resources_js_pages_tree", "tests_tree")}, "generator": f"{PREFIX}/{GENERATOR}", "generator_sha256": sha(AUDIT / GENERATOR), "generator_blob_id": git("hash-object", "--", str(AUDIT / GENERATOR)), "baseline": BASELINE, "baseline_sha256": EXPECTED[BASELINE], "baseline_blob_id": EXPECTED_BLOBS[BASELINE], "baseline_review": BASELINE_REVIEW, "baseline_review_sha256": EXPECTED[BASELINE_REVIEW], "baseline_review_blob_id": EXPECTED_BLOBS[BASELINE_REVIEW], "baseline_review_materializer": BASELINE_REVIEW_GENERATOR, "baseline_review_materializer_sha256": EXPECTED[BASELINE_REVIEW_GENERATOR], "baseline_review_materializer_blob_id": EXPECTED_BLOBS[BASELINE_REVIEW_GENERATOR], "cohort": COHORT, "cohort_sha256": EXPECTED[COHORT], "cohort_blob_id": EXPECTED_BLOBS[COHORT], "cohort_generator_sha256": EXPECTED[COHORT_GENERATOR], "cohort_generator_blob_id": EXPECTED_BLOBS[COHORT_GENERATOR], "review": REVIEW, "review_sha256": EXPECTED[REVIEW], "review_blob_id": EXPECTED_BLOBS[REVIEW], "review_materializer_sha256": EXPECTED[REVIEW_GENERATOR], "review_materializer_blob_id": EXPECTED_BLOBS[REVIEW_GENERATOR], "inputs": input_map, "input_map_sha256": canonical(input_map)},
        "architecture_rule": review["architecture_rule"],
        "baseline": {"source_owner_records": 663, "route_owner_records": 306, "page_owner_records": 357, "static_controller_action_bridges": 94, "reviewed_queue_surface_rows": 117, "pending_unreviewed_queue_surface_rows": 390, "post_commit_review": {"run_id": baseline_review["run_id"], "status": baseline_review["status"], "producer_sha256": baseline_review["pins"]["producer_sha256"], "producer_blob_id": baseline_review["pins"]["producer_blob_id"], "producer_generator_sha256": baseline_review["pins"]["producer_generator_sha256"], "producer_generator_blob_id": baseline_review["pins"]["producer_generator_blob_id"], "independent_overlay_review_for_reporting": True, "new_or_current_or_downstream_credit": False}},
        "reviewed_overlay": {"producer_run_id": cohort["run_id"], "review_run_id": review["run_id"], "reviewed_route_actions": 1, "owner_route_actions": 1, "accepted_source_owner_records": 1, "accepted_route_owner_records": 1, "accepted_page_owner_records": 0, "accepted_controller_action_bridges": 1, "new_distinct_feature_ids": 0, "current_static_overlay_credit_applied": True, "correctness_or_downstream_credit_authorized": False, "final_finding_credit_authorized": False},
        "provisional_assurance_observation_preservation": {"observations": observations, "observation_count": 6, "provisional_source_observations_only": True, "correctness_credit_authorized": False, "final_finding_credit_authorized": False},
        "reviewer_lineage": {"independent_candidate_reviews": reviewers, "synthesis_review": synthesis, "action_decision": decision},
        "source_packet_boundary": review["source_packet_boundary"], "combined_counts": counts, "queue_accounting": queue_counts,
        "queue_boundary": {"preceding_index_80_not_recredited": True, "index_82_reviewed_context_not_recredited": True, "selected_index_81_integrated": True, "next_unresolved_index": next_index, "next_unresolved_queue_id": next_row["queue_id"], "next_unresolved_route_record_id": next_row["source_record_id"], "next_unresolved_queue_record_sha256": next_row["queue_record_sha256"], "reviewed_key_count": len(reviewed_keys), "reviewed_key_list_sha256": hlist(list(reviewed_keys))},
        "noninheritance_boundary": {"existing_page_owner_id": cohort["existing_page_owner_reconciliation"]["existing_page_owner_id"], "existing_page_owner_record_sha256": page_owner_seal, "existing_page_owner_reconciliation_source": COHORT, "page_owner_not_inherited_or_recredited": True, "historical_route_record_id": cohort["historical_route_classification_reconciliation"]["record"]["route_record_id"], "historical_route_classification": cohort["historical_route_classification_reconciliation"]["historical_classification"], "historical_sentinel_record_sha256": sentinel_seal, "historical_sentinel_reconciliation_source": COHORT, "historical_sentinel_preserved_not_rewritten_or_credited": True, "neighbor_identity_or_outcome_not_inherited": True},
        "overlay_source_records": [row], "new_static_controller_action_bridges": [bridge], "reviewed_non_owner_outcomes": [], "identity": identity,
        "outcome_conservation": {"reviewed_outcomes_equation": "1 = 1 owner + 0 shared + 0 evidence gap", "bounded_source_equation": "3929 = 664 owner + 3265 non-owner residual", "owner_surface_equation": "664 = 307 route + 357 page", "feature_union_equation": "256 = 64 route + 242 page - 50 overlap", "route_universe_equation": "3218 = 307 owner + 12 shared + 5 alias + 0 dead + 2894 residual", "page_universe_equation": "711 = 357 owner + 9 shared + 0 alias + 0 dead + 345 residual", "queue_equation": "507 = 118 reviewed + 389 pending", "reviewed_queue_equation": "118 = 96 owner + 10 shared + 5 alias + 0 dead + 7 evidence gap", "queue_without_ownership_equation": "411 = 389 pending + 10 shared + 5 alias + 0 dead + 7 evidence gap"},
        "credit_boundary": {"STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD": True, "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION": True, **false_credit},
        "mutation_attestation": {"application_source_changed": False, "test_files_changed": False, "matrix_changed": False, "reports_changed": False, "dashboard_generator_changed": False, "dashboard_html_changed": False, "runtime_or_external_system_changed": False, "audit_artifacts_only": True, "only_expected_run153_artifacts_present": True},
        "artifact_completion_test_met": True, "audit_completion_test_met": False,
        "wrote_files": [f"{PREFIX}/{GENERATOR}", f"{PREFIX}/{OUTPUT}"],
    }
    (AUDIT / OUTPUT).write_text(json.dumps(out, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")
    strict(OUTPUT)
    expected_status = {f"?? {PREFIX}/{GENERATOR}", f"?? {PREFIX}/{OUTPUT}"}
    assert {line.lstrip() for line in git("status", "--porcelain").splitlines()} == expected_status
    assert not list(AUDIT.rglob("__pycache__"))
    print(json.dumps({"status": out["status"], "generator_sha256": sha(AUDIT / GENERATOR), "receipt_sha256": sha(AUDIT / OUTPUT), "counts": counts, "queue_accounting": queue_counts, "overlay_row_sha256": row["overlay_row_sha256"], "bridge_row_sha256": bridge["bridge_row_sha256"]}, indent=2))

if __name__ == "__main__":
    main()
