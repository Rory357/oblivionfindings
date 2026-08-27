#!/usr/bin/env python3
"""Materialize RUN152R independent reviews and synthesis; do not integrate."""
from __future__ import annotations

import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
from typing import Any

REPO = Path(__file__).resolve().parents[4]
AUDIT = Path(__file__).resolve().parents[1]
PREFIX = AUDIT.relative_to(REPO).as_posix()
GENERATOR = "generators/materialize-independent-outcome-neutral-fleet-vehicle-register-index-route-action-review-wave-26.py"
OUTPUT = "evidence/source/raw-run-152r-independent-outcome-neutral-fleet-vehicle-register-index-route-action-review-wave-26.json"
COHORT_GENERATOR = "generators/build-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26.py"
COHORT = "evidence/source/root-run-152-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26.json"
HEAD = "4347b92dc80547d102a2f4c9680a1885ac79d3af"
TREE = "a402ff212e1ef6926e508dae4f50db1ddadd6b51"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
SUBTREES = {"app": "92c8425a7cb15a92609c69a8c2f26bbda4f178b7", "routes": "9b7f78510d970db64ea3a6540e8a36b8700bf272", "resources/js": "1671a7551c004571c48bb00c34522928e6f1f173", "resources/js/pages": "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e", "tests": "fef0122b31fdccbe2f9f805f7515666c74e2880a"}
COHORT_GENERATOR_SHA = "7b3e6501d3fe806e7bb27be8d20236467496e20e101d42a9efc0741e67f0e336"
COHORT_GENERATOR_BLOB = "7171cc58de0152219fc3fad83ca93385f333e80a"
COHORT_SHA = "5e987d8727896183aadf30b9000ed56b318e2f4c8935b6d77e3600999105eac4"
COHORT_BLOB = "a7e8e85e36dd4d07cc8653922cabd47e422f41ab"
CANDIDATE_HASH = "08f334132340f905b012aea8f45be46ca2248e83c7eb05ecd1247e4d47e50321"
QUEUE_SEAL = "c15a3e4371f5d063066b013b824205c24d1ab6126f49aea3d266e9b897b146de"
REVIEWER_A_REPORTED_HASH = "24f9a0ccf4681997827ee992ea72a66bcd1e053c55f1f2671fc2b3c716b1e610"
REVIEWER_B_REPORTED_HASH = "2d540694691db267013856b9ebe1d0b398a2b0b0f24d9fec2b06c5fc2bf56820"

def run(*args: str) -> bytes:
    return subprocess.run(args, cwd=REPO, check=True, capture_output=True).stdout

def git(*args: str) -> str:
    return run("git", *args).decode().rstrip("\r\n")

def sha(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()

def audit_sha(relative: str) -> str:
    return sha((AUDIT / relative).read_bytes())

def canonical(value: Any) -> str:
    return sha(json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode())

def strict_json(relative: str) -> dict[str, Any]:
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

def review(review_id: str, role: str, provenance: dict[str, Any], reported_hash: str, rationale: str) -> dict[str, Any]:
    return sealed({
        "review_id": review_id, "reviewer_role": role, **provenance,
        "independent_from_cohort_producer": True,
        "other_candidate_reviewer_consulted": False,
        "independent_evidence_trace_completed": True,
        "review_method": "FRESH_PINNED_STATIC_SEMANTIC_TRACE_NO_EXECUTION",
        "reported_review_hash": reported_hash,
        "canonical_candidate_record_sha256": CANDIDATE_HASH,
        "queue_record_self_seal_sha256": QUEUE_SEAL,
        "queue_index_zero_based": 81, "queue_id": "RUN090-ROUTE-0082",
        "queue_canonical_key": "route|RUN077-ROUTE-0690", "route_record_id": "RUN077-ROUTE-0690",
        "candidate_feature_id": "CAP-FLEET-VEHICLE-REGISTER",
        "action_key": "RUN077-ROUTE-0690|app/Http/Controllers/FleetAssets/VehicleController.php:index|CAP-FLEET-VEHICLE-REGISTER",
        "outcome": "OWNER_ROUTE_ACTION", "confidence": "HIGH_STATIC_IDENTITY_ONLY",
        "identity_basis": "EXACT_LITERAL_ROUTE_NAME_PLUS_UNIQUE_CONTROLLER_INDEX_PLUS_DIRECT_VEHICLE_QUERY_AND_RENDER",
        "source_loci": ["03-feature-to-benchmark-matrix.csv:108", "routes/fleet-assets.php:34-52", "app/Http/Controllers/FleetAssets/VehicleController.php:31-245", "resources/js/pages/fleet-assets/vehicles/index.tsx:1-430"],
        "rationale": rationale,
        "denied_alternatives": {"SHARED_RELATION": "Models, services, page and callers are dependencies or context, not co-owners of this exact route action.", "EVIDENCE_GAP": "The exact route, unique action, Asset::vehicles query and literal render close narrow static ownership; correctness remains separate."},
        "ownership_material_expansion": {"status": "NONE_REQUIRED_FOR_NARROW_STATIC_OWNERSHIP", "paths": []},
        "route_ownership_authorized_for_later_overlay": True,
        "controller_action_bridge_authorized_for_later_overlay": True,
        "owner_source_record_key": "route|RUN077-ROUTE-0690|CAP-FLEET-VEHICLE-REGISTER",
        "bridge_key": ["app/Http/Controllers/FleetAssets/VehicleController.php", "index", "CAP-FLEET-VEHICLE-REGISTER"],
        "page_ownership_authorized": False, "existing_page_owner_inherited_or_recredited": False,
        "historical_sentinel_inherited_or_rewritten": False, "neighbor_identity_or_outcome_inherited": False,
        "current_overlay_credit_awarded": False, "correctness_or_downstream_credit_authorized": False,
        "reviewer_wrote_files": False,
    }, "review_record_sha256")

def observation(observation_id: str, category: str, loci: list[str], text: str) -> dict[str, Any]:
    return sealed({"observation_id": observation_id, "status": "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING", "category": category, "loci": loci, "observation": text, "correctness_credit_authorized": False, "final_finding_credit_authorized": False}, "observation_record_sha256")

def build() -> dict[str, Any]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == HEAD and git("rev-parse", "HEAD^{tree}") == TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    for path, tree in SUBTREES.items(): assert git("rev-parse", f"HEAD:{path}") == tree
    assert audit_sha(COHORT_GENERATOR) == COHORT_GENERATOR_SHA
    assert git("rev-parse", f"HEAD:{PREFIX}/{COHORT_GENERATOR}") == COHORT_GENERATOR_BLOB
    assert audit_sha(COHORT) == COHORT_SHA and git("rev-parse", f"HEAD:{PREFIX}/{COHORT}") == COHORT_BLOB
    assert not git("status", "--porcelain", "--", "app", "routes", "resources/js", "tests", "database")
    cohort = strict_json(COHORT)
    candidate = cohort["records"][0]
    assert canonical(candidate) == CANDIDATE_HASH and candidate["queue_record_sha256"] == QUEUE_SEAL
    assert cohort["source_review_packet"]["selected_controller_method_slice"]["text_sha256"] == "92e5cfea63e7ce6b1c3c784cc4ac3a4ebae2c89ca5a0d65bba9b2119743b9191"
    assert cohort["source_review_packet"]["packet_sha256"] == "8bc5a6189e483d546c14938e342488f439e29b7e9c3505cfad8ec6df114b6890"
    assert cohort["existing_page_owner_reconciliation"]["record_sha256"] == "071be3674cfd4d743b32a70ac2ad63c84af38a11aab563b98393f22332282028"
    assert cohort["historical_route_classification_reconciliation"]["record_sha256"] == "876cc424a505e7b07c08e18c8c5efcbe6ddd7a9a330400055baed769469d11db"
    assert cohort["current_reviewed_set_reconciliation"] == {"reviewed_key_count": 117, "reviewed_key_list_sha256": "163e5bdc3cf7a37c400772ab354c34cc50be4f1c66b17ecdc568ec60c8be5256", "index_80_member": True, "index_81_member": False, "index_82_member": True, "index_83_member": False, "immutable_run090_pending_label_does_not_override_later_review": True}

    review_a = review("RUN152R-INDEPENDENT-REVIEW-A", "fresh semantic route-action reviewer A", {"blinded_review": False, "nonblinding_reason": "prior outcome visible in team status", "prior_outcome_visible_in_team_status": True, "reviewer_b_consulted": False, "fresh_committed_source_trace": True, "reported_matrix_locus": "03-feature-to-benchmark-matrix.csv:94", "normalized_matrix_locus": "03-feature-to-benchmark-matrix.csv:108", "matrix_locus_correction": "NON_MATERIAL_STALE_LOCATOR_CORRECTED_TO_EXACT_CAP_FLEET_VEHICLE_REGISTER_LINE"}, REVIEWER_A_REPORTED_HASH, "The exact GET route resolves uniquely to VehicleController::index, which directly builds the vehicle register query and renders the canonical vehicle register page. This establishes only narrow static route/action ownership.")
    review_b = review("RUN152R-INDEPENDENT-REVIEW-B", "fresh semantic route-action reviewer B", {"blinded_review": False, "nonblinding_reason": "prior self-assessment visible", "prior_self_assessment_visible": True, "reviewer_a_consulted": False, "reviewer_a_outcome_visible": False, "external_session_consulted": False, "fresh_committed_source_trace": True, "reported_matrix_locus": "03-feature-to-benchmark-matrix.csv:108", "normalized_matrix_locus": "03-feature-to-benchmark-matrix.csv:108", "reported_candidate_hash_basis": "queue_record_self_seal", "reported_candidate_hash_basis_value": QUEUE_SEAL, "candidate_hash_basis_difference": "NON_MATERIAL_LABEL_OR_BASIS_DIFFERENCE_RECONCILED_TO_CANONICAL_COHORT_RECORD"}, REVIEWER_B_REPORTED_HASH, "A fresh committed trace links the unique vehicles index route to VehicleController::index, Asset::vehicles and the exact Inertia page. Page ownership and correctness are not inherited.")
    reviews = [review_a, review_b]
    assert [r["outcome"] for r in reviews] == ["OWNER_ROUTE_ACTION", "OWNER_ROUTE_ACTION"]

    observations = [
        observation("RUN152R-ASSURANCE-FLEET-VIEWANY-AUTHORITY", "exact_read_export_telemetry_authority", ["routes/fleet-assets.php:34-52", "app/Http/Controllers/FleetAssets/VehicleController.php:60-245"], "The exact authority intended by fleet.viewAny for CSV export and live telemetry projection is not established."),
        observation("RUN152R-ASSURANCE-APPROVED-SITE-LIST-EXPORT-FILTER", "approved_site_list_export_raw_filter_and_options", ["app/Http/Controllers/FleetAssets/VehicleController.php:60-245", "app/Services/UserSiteAccessService.php:1-1625"], "The vehicle list, CSV path, raw site_id filter and all active-Site options are not visibly constrained through the canonical approved-Site boundary."),
        observation("RUN152R-ASSURANCE-AGGREGATE-SCOPE", "aggregate_scope", ["app/Http/Controllers/FleetAssets/VehicleController.php:60-245", "app/Services/UserSiteAccessService.php:1598"], "Only alert aggregation is visibly passed through applyAlertScope; the remaining vehicle, compliance and status aggregates lack equivalent proved scope."),
        observation("RUN152R-ASSURANCE-LIVE-TELEMETRY-PRIVACY", "live_telemetry_and_privacy", ["app/Http/Controllers/FleetAssets/VehicleController.php:60-245", "resources/js/pages/fleet-assets/vehicles/index.tsx:1-430"], "Authorization and privacy for home-Site, coordinates, speed, battery and related live state projections are not established for every fleet.viewAny holder."),
        observation("RUN152R-ASSURANCE-SHOW-CONCEALMENT-NONTRANSFER", "adjacent_show_direct_object_concealment_nontransfer", ["app/Policies/AssetPolicy.php:1-75", "app/Domain/SecurityDevices/Services/SecurityDevicesAccessService.php:1125-1184", "app/Http/Controllers/FleetAssets/VehicleController.php:247"], "Direct-object concealment semantics for the adjacent show action do not transfer to the selected index route or prove list/export/filter scope."),
        observation("RUN152R-ASSURANCE-NEGATIVE-PATH-EXECUTION", "ordinary_viewer_foreign_site_execution", ["tests/Feature/FleetAssets/FleetHeroRolloutContractTest.php:58-110", "tests/Feature/FleetAssets/FleetControlRoomAlertHeroScopeTest.php:26-59", "tests/Browser/Fleet/FleetPermissionsTest.php:170-195"], "No executed ordinary-viewer foreign-Site list, export, raw-filter or aggregate non-disclosure proof is present in this source-only review."),
    ]
    synthesis = sealed({
        "synthesis_id": "RUN152R-INDEPENDENT-REVIEW-SYNTHESIS", "verdict": "GO_ACCEPT_1_OWNER_ROUTE_ACTION_FOR_BOUNDED_LATER_INTEGRATION",
        "accepted_independent_review_ids": [r["review_id"] for r in reviews], "accepted_independent_review_record_sha256s": [r["review_record_sha256"] for r in reviews],
        "canonical_candidate_record_sha256": CANDIDATE_HASH, "queue_record_self_seal_sha256": QUEUE_SEAL,
        "reviewer_a_stale_matrix_line_94_corrected_to_exact_line_108": True, "reviewer_a_locator_correction_material": False,
        "reviewer_b_reported_exact_matrix_line_108": True,
        "reviewer_b_hash_basis_difference_reconciled": True, "reviewer_b_hash_basis_difference_material": False,
        "outcome_variables": {"O": 1, "S": 0, "E": 0}, "independent_reviews_reconciled": True,
        "outcome_discrepancies": 0, "material_identity_discrepancies": 0, "page_credit_discrepancies": 0, "hard_stop_discrepancies": 0,
        "ownership_material_expansion_required": False, "provisional_assurance_observation_count": 6,
        "provisional_assurance_observations_sha256": canonical(observations),
        "route_ownership_authorized": True, "controller_action_bridge_authorized": True, "page_ownership_authorized": False,
        "bounded_overlay_integration_authorized_later_only": True, "current_overlay_credit_awarded": False,
        "correctness_or_downstream_credit_authorized": False, "synthesizer_wrote_files": False,
    }, "synthesis_record_sha256")
    decision = sealed({
        "candidate_record_sha256": CANDIDATE_HASH, "queue_record_self_seal_sha256": QUEUE_SEAL,
        "accepted_independent_review_ids": synthesis["accepted_independent_review_ids"], "accepted_independent_review_record_sha256s": synthesis["accepted_independent_review_record_sha256s"], "synthesis_record_sha256": synthesis["synthesis_record_sha256"],
        "queue_index_zero_based": 81, "queue_id": "RUN090-ROUTE-0082", "route_record_id": "RUN077-ROUTE-0690",
        "literal_route_name": "fleet-assets.vehicles.index", "controller_file": "app/Http/Controllers/FleetAssets/VehicleController.php", "controller_method": "index", "candidate_feature_id": "CAP-FLEET-VEHICLE-REGISTER",
        "outcome": "OWNER_ROUTE_ACTION", "confidence": "HIGH_STATIC_IDENTITY_ONLY_2_OF_2_PLUS_SYNTHESIS",
        "route_ownership_authorized_for_later_overlay": True, "controller_action_bridge_authorized_for_later_overlay": True,
        "page_ownership_authorized": False, "existing_page_owner_inherited_or_recredited": False, "historical_sentinel_inherited_or_rewritten": False,
        "neighbor_identity_or_outcome_inherited": False, "current_overlay_credit_awarded": False, "correctness_or_downstream_credit_authorized": False,
        "provisional_assurance_observation_ids": [o["observation_id"] for o in observations],
    }, "decision_record_sha256")
    false_credit = {key: False for key in ("current_overlay_ownership", "static_page_feature_ownership", "correctness", "framework_route_reachability", "runtime", "database", "build", "application_browser", "responsive_application", "executed_tests", "benchmark", "final_no_match_or_NCM", "ease", "release", "pass", "final_finding", "feature_completion", "completion", "gate_4", "audit_complete")}
    payload = {
        "schema_version": "run-152r-independent-outcome-neutral-fleet-vehicle-register-index-route-action-review-wave-26-v1",
        "run_id": "RUN-152R-INDEPENDENT-OUTCOME-NEUTRAL-FLEET-VEHICLE-REGISTER-INDEX-ROUTE-ACTION-REVIEW-WAVE-26",
        "status": "GO_ONE_STATIC_OWNER_AND_BRIDGE_AUTHORIZED_FOR_LATER_INTEGRATION_ZERO_CURRENT_OR_DOWNSTREAM_CREDIT", "reviewed_on": "2026-08-27", "decision": "GO",
        "pins": {"checkpoint_commit": HEAD, "checkpoint_tree": TREE, "application_commit": APPLICATION_COMMIT, "application_tree": APPLICATION_TREE, "subtrees": SUBTREES, "cohort_generator_sha256": COHORT_GENERATOR_SHA, "cohort_generator_blob_id": COHORT_GENERATOR_BLOB, "cohort_sha256": COHORT_SHA, "cohort_blob_id": COHORT_BLOB, "cohort_candidate_record_sha256": CANDIDATE_HASH, "queue_record_self_seal_sha256": QUEUE_SEAL, "prompt_path": cohort["pins"]["prompt_path"], "prompt_sha256": cohort["pins"]["prompt_sha256"], "cohort_inputs": cohort["pins"]["inputs"], "generator": f"{PREFIX}/{GENERATOR}", "generator_sha256": audit_sha(GENERATOR), "generator_blob_id": git("hash-object", "--", str(AUDIT / GENERATOR))},
        "architecture_rule": cohort["architecture_rule"],
        "methods": {"reviewers": 2, "synthesizers": 1, "static_source_only": True, "application_executed": False, "framework_routes_executed": False, "database_used": False, "build_used": False, "browser_used": False, "tests_executed": False},
        "verified_counts": {"cohort_records": 1, "independent_review_records": 2, "owner_route_actions": 1, "shared_relations": 0, "evidence_gaps": 0, "route_owners_authorized_for_later_overlay": 1, "controller_action_bridges_authorized_for_later_overlay": 1, "page_owners_authorized": 0, "current_overlay_rows_written": 0, "provisional_assurance_observations": 6, "final_findings": 0},
        "independent_candidate_reviews": reviews, "synthesis_review": synthesis, "action_decisions": decision, "provisional_assurance_observations": observations,
        "source_packet_boundary": {"original_source_review_complete": False, "original_source_packet_completeness_claimed": False, "original_material_dependency_semantics_complete": False, "original_known_expansion_adjudicated": False, "original_packet_retroactively_described_as_complete": False, "ownership_material_expansion_required": False, "correctness_only_observations_authorize_no_credit": True},
        "page_owner_and_historical_sentinel_reconciliation": {"page_owner_id": "PAGE-ROOT-07E63287EC196468", "page_owner_record_sha256": "071be3674cfd4d743b32a70ac2ad63c84af38a11aab563b98393f22332282028", "page_selected_or_recredited": False, "historical_route_record_id": "RUN077-ROUTE-0690", "historical_classification": "EXPLICIT_UNMAPPED_SENTINEL", "historical_record_sha256": "876cc424a505e7b07c08e18c8c5efcbe6ddd7a9a330400055baed769469d11db", "historical_provenance_preserved": True, "historical_record_rewritten_or_credited": False, "current_candidate_discovery_superseding_runs": ["RUN-082", "RUN-090"]},
        "queue_boundary_reconciliation": {"preceding_index_80_reviewed_context_no_recredit": True, "index_82_reviewed_context_no_recredit": True, "index_82_reviewed_origin": "RUN-091R review / RUN-092 integration", "true_next_unresolved_index": 83, "true_next_unresolved_queue_id": "RUN090-ROUTE-0084", "true_next_unresolved_route_record_id": "RUN077-ROUTE-0692", **cohort["current_reviewed_set_reconciliation"]},
        "credit_boundary": {"reviewed_static_route_feature_ownership_for_1_record": True, "reviewed_static_controller_action_bridge_for_1_action": True, "bounded_overlay_integration_authorized_later_only": True, **false_credit},
        "completion_boundary": cohort["completion_boundary"], "artifact_completion_test_met": False, "audit_completion_test_met": False,
        "wrote_files": [f"{PREFIX}/{GENERATOR}", f"{PREFIX}/{OUTPUT}"],
    }
    return payload

def main() -> None:
    payload = build()
    encoded = json.dumps(payload, ensure_ascii=False, indent=2) + "\n"
    (AUDIT / OUTPUT).write_text(encoded, encoding="utf-8", newline="\n")
    strict_json(OUTPUT)
    expected = {f"?? {PREFIX}/{GENERATOR}", f"?? {PREFIX}/{OUTPUT}"}
    assert {line.lstrip() for line in git("status", "--porcelain").splitlines()} == expected
    assert not list(AUDIT.rglob("__pycache__"))
    print(json.dumps({"status": payload["status"], "generator_sha256": audit_sha(GENERATOR), "receipt_sha256": audit_sha(OUTPUT), "review_seals": [r["review_record_sha256"] for r in payload["independent_candidate_reviews"]], "synthesis_seal": payload["synthesis_review"]["synthesis_record_sha256"], "observation_seals": [o["observation_record_sha256"] for o in payload["provisional_assurance_observations"]]}, indent=2))

if __name__ == "__main__":
    main()
