#!/usr/bin/env python3
"""Materialize two independent RUN-148 ownership reviews and synthesis.

This producer reviews only the committed outcome-neutral RUN-148 cohort.  It
authorizes a later bounded route-owner and controller-action-bridge overlay,
but it does not mutate the current overlay and awards no current ownership,
correctness, runtime, browser, test, benchmark, ease, Pass, finding,
completion, or audit-complete credit.
"""

from __future__ import annotations

import importlib.util
import json
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
COHORT_GENERATOR = (
    AUDIT_DIR
    / "generators/build-outcome-neutral-fleet-daily-vehicle-check-store-route-action-cohort-wave-25.py"
)
COHORT_PATH = (
    AUDIT_DIR
    / "evidence/source/root-run-148-outcome-neutral-fleet-daily-vehicle-check-store-route-action-cohort-wave-25.json"
)
OUTPUT_PATH = (
    AUDIT_DIR
    / "evidence/source/raw-run-148r-independent-outcome-neutral-fleet-daily-vehicle-check-store-route-action-review-wave-25.json"
)

CHECKPOINT_COMMIT = "faca23e26044655d6fe869449100820deac7786c"
CHECKPOINT_TREE = "4df4ef0ef1e5aff9fecd5277c1c830939cbf6468"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
TESTS_TREE = "fef0122b31fdccbe2f9f805f7515666c74e2880a"
COHORT_GENERATOR_SHA256 = "c8c6a9f1500fe088f6c61c3edff5351095518d14661a77af86b327a9ee253f65"
COHORT_GENERATOR_BLOB = "b9deb131d69a1770f5754675d0c773bf0ccb0f30"
COHORT_SHA256 = "621c1794a73e232b6fc9ff8d2b81ac9ae31ea2ccfe9f038ae77afe332b3ab28d"
COHORT_BLOB = "caa779f64c30dc653f243a85568bd3156b4764e1"
CANDIDATE_SHA256 = "589212109db42fd2e0b1611ea855ea76c469a492528d084949880d3601ac45b2"
SOURCE_PACKET_SHA256 = "9c36ffc8d02ab1b749931f9db5b8502c9d5e554c0e139074700ed22906e0ec27"
REQUIRED_SOURCE_IDENTITY_SHA256 = "d8fecae4408cc9b63100ce7e05c48e3691d9084cccb8bad363f485fb3e026349"

spec = importlib.util.spec_from_file_location("run148_cohort", COHORT_GENERATOR)
assert spec and spec.loader
BASE = importlib.util.module_from_spec(spec)
spec.loader.exec_module(BASE)

sha256_file = BASE.sha256_file
canonical_json_sha256 = BASE.canonical_json_sha256
canonical_list_sha256 = BASE.canonical_list_sha256
load_json = BASE.load_json
git = BASE.git

CORRECTNESS_ONLY_EXPANSION = {
    "routes/web.php": {
        "sha256": "5894a96a99997c984047b3aa9aef793c34c3d2d67fdac091e1022bcc3c05837e",
        "blob_id": "cb6aec6dd2c44079a678ee1b92bcd1bddf2079fd",
        "loci": ["routes/web.php:340"],
        "reason": "confirm the Fleet route-file loader and composed /fleet-assets prefix",
    },
    "app/Policies/AssetPolicy.php": {
        "sha256": "5fd2040e66fcc0a761ba729c03db9602d0375f6a85c982b79ba92ef3354b79a0",
        "blob_id": "ef4931d4c88542a85f7de7bf343da8d668e49f57",
        "loci": ["app/Policies/AssetPolicy.php:17-21", "app/Policies/AssetPolicy.php:74-87"],
        "reason": "contrast the selected raw asset-id mutation with the existing canonical Asset policy boundary",
    },
    "app/Domain/SecurityDevices/Services/SecurityDevicesAccessService.php": {
        "sha256": "c6ad39b72e659086e62a63e7510b8e8cb14e2a80192841cdddb5dc71436f9534",
        "blob_id": "3737bf5327cd86e9b551ce86296005b1813c9616",
        "loci": [
            "app/Domain/SecurityDevices/Services/SecurityDevicesAccessService.php:312-315",
            "app/Domain/SecurityDevices/Services/SecurityDevicesAccessService.php:1038-1042",
            "app/Domain/SecurityDevices/Services/SecurityDevicesAccessService.php:1125-1184",
        ],
        "reason": "contrast an existing canonical approved-Site vehicle and direct-object concealment path",
    },
    "app/Providers/AuthServiceProvider.php": {
        "sha256": "7bcbfb866f657f1e64665806b9f496f3994ba0e90f59686ef71fb97c458b47be",
        "blob_id": "6a8a0d76b68f18ef5df613e41a7a97ba08431cb5",
        "loci": ["app/Providers/AuthServiceProvider.php:139-210"],
        "reason": "confirm AssetPolicy registration and absence of checklist-model policy registration",
    },
}


def assert_workspace() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("rev-parse", "HEAD:tests") == TESTS_TREE
    assert git("status", "--porcelain", "--", "app", "routes", "resources/js", "tests", "database") == ""
    allowed = {
        f"?? {Path(__file__).relative_to(REPO).as_posix()}",
        f"?? {OUTPUT_PATH.relative_to(REPO).as_posix()}",
    }
    status = {line for line in git("status", "--porcelain").splitlines() if line}
    assert status <= allowed, status
    assert sha256_file(COHORT_GENERATOR) == COHORT_GENERATOR_SHA256
    assert git("rev-parse", f"HEAD:{COHORT_GENERATOR.relative_to(REPO).as_posix()}") == COHORT_GENERATOR_BLOB
    assert sha256_file(COHORT_PATH) == COHORT_SHA256
    assert git("rev-parse", f"HEAD:{COHORT_PATH.relative_to(REPO).as_posix()}") == COHORT_BLOB
    for relative, expected in CORRECTNESS_ONLY_EXPANSION.items():
        path = REPO / relative
        assert path.is_file(), relative
        assert sha256_file(path) == expected["sha256"], relative
        assert git("rev-parse", f"HEAD:{relative}") == expected["blob_id"], relative
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{relative}") == expected["blob_id"], relative


def review_record(
    *,
    review_id: str,
    reviewer_role: str,
    rationale: str,
    denied_shared: str,
    denied_gap: str,
    prior_outcome_visible_in_team_status: bool,
) -> dict[str, Any]:
    record: dict[str, Any] = {
        "review_id": review_id,
        "reviewer_role": reviewer_role,
        "independent_from_cohort_producer": True,
        "blinded_review": False,
        "prior_outcome_visible_in_team_status": prior_outcome_visible_in_team_status,
        "other_candidate_reviewer_consulted": False,
        "independent_evidence_trace_completed": True,
        "review_method": "FRESH_PINNED_STATIC_SEMANTIC_TRACE_NO_EXECUTION",
        "candidate_id": "RUN148-FLEET-DAILY-VEHICLE-CHECK-STORE-ROUTE-ACTION-01",
        "candidate_record_sha256": CANDIDATE_SHA256,
        "queue_index_zero_based": 80,
        "queue_id": "RUN090-ROUTE-0081",
        "queue_canonical_key": "route|RUN077-ROUTE-0689",
        "route_record_id": "RUN077-ROUTE-0689",
        "candidate_feature_id": "CAP-FLEET-DAILY-VEHICLE-CHECK",
        "action_key": "RUN077-ROUTE-0689|app/Http/Controllers/FleetAssets/DailyCheckController.php:store|CAP-FLEET-DAILY-VEHICLE-CHECK",
        "outcome": "OWNER_ROUTE_ACTION",
        "confidence": "HIGH_STATIC_IDENTITY_ONLY",
        "identity_basis": "EXACT_LITERAL_ROUTE_PLUS_UNIQUE_CONTROLLER_ACTION_PLUS_DIRECT_SUBMITTER_PLUS_DIRECT_DAILY_CHECK_PERSISTENCE",
        "source_loci": [
            "03-feature-to-benchmark-matrix.csv:94",
            "routes/web.php:340",
            "routes/fleet-assets.php:34",
            "routes/fleet-assets.php:40-47",
            "app/Http/Controllers/FleetAssets/DailyCheckController.php:134-190",
            "resources/js/pages/fleet-assets/daily-check.tsx:83-100",
            "app/Models/FleetChecklistRun.php:10-38",
            "app/Models/FleetChecklistTemplate.php:13-27",
            "database/migrations/2026_03_22_100300_create_fleet_maintenance_tables.php:11-33",
        ],
        "rationale": rationale,
        "denied_alternatives": {
            "SHARED_RELATION": denied_shared,
            "EVIDENCE_GAP": denied_gap,
        },
        "ownership_material_expansion": {
            "status": "NONE_REQUIRED_FOR_NARROW_STATIC_OWNERSHIP",
            "paths": [],
            "original_packet_completion_flags_preserved_false": True,
        },
        "route_ownership_authorized_for_later_overlay": True,
        "controller_action_bridge_authorized_for_later_overlay": True,
        "owner_source_record_key": "route|RUN077-ROUTE-0689|CAP-FLEET-DAILY-VEHICLE-CHECK",
        "bridge_key": [
            "app/Http/Controllers/FleetAssets/DailyCheckController.php",
            "store",
            "CAP-FLEET-DAILY-VEHICLE-CHECK",
        ],
        "page_ownership_authorized": False,
        "prior_sibling_page_owner_inherited_or_recredited": False,
        "current_overlay_credit_awarded": False,
        "correctness_or_downstream_credit_authorized": False,
        "reviewer_wrote_files": False,
    }
    record["review_record_sha256"] = canonical_json_sha256(record)
    return record


def build() -> dict[str, Any]:
    assert_workspace()
    cohort = load_json(COHORT_PATH)
    assert cohort["status"] == "OUTCOME_NEUTRAL_SOURCE_PACKET_READY_FRESH_REVIEW_REQUIRED_ZERO_OWNERSHIP_CREDIT"
    assert cohort["pins"]["checkpoint_commit"] == "2e578dab7cebf861f1b1e466c6d75f8e200d9d20"
    assert cohort["source_review_packet"]["source_review_packet_sha256"] == SOURCE_PACKET_SHA256
    assert cohort["source_review_packet"]["required_source_file_identity_sha256"] == REQUIRED_SOURCE_IDENTITY_SHA256
    assert cohort["records"][0]["candidate_record_sha256"] == CANDIDATE_SHA256
    assert cohort["records"][0]["fresh_review_state"]["ownership_credit"] is False
    assert cohort["credit_boundary"]["static_source_ownership"] is False
    assert cohort["credit_boundary"]["static_controller_action_bridge"] is False
    assert cohort["next_pending_boundary"]["queue_id"] == "RUN090-ROUTE-0082"

    review_a = review_record(
        review_id="RUN148R-INDEPENDENT-REVIEW-A",
        reviewer_role="fresh semantic route-action reviewer A",
        rationale=(
            "The exact authenticated POST route resolves uniquely to DailyCheckController::store; the action validates "
            "the daily-check payload, obtains the daily_check template, updates today's asset/template run or creates "
            "FleetChecklistRun, and has one tracked direct submitter. Those semantics directly implement the canonical "
            "daily-vehicle-check job. Correctness gaps remain separate."
        ),
        denied_shared=(
            "FleetChecklistTemplate and FleetChecklistRun are persistence dependencies, not co-owners; the selected "
            "action does not delegate the user job to InspectionController, ChecklistController, WorkOrderController, "
            "FleetTimelineService, FleetVehicleBooking, or the already-reviewed sibling GET action."
        ),
        denied_gap=(
            "The exact route identity, unique controller action, direct submitter, and direct update/create persistence "
            "close narrow static ownership without relying on runtime or sibling ownership."
        ),
        prior_outcome_visible_in_team_status=False,
    )
    review_b = review_record(
        review_id="RUN148R-INDEPENDENT-REVIEW-B",
        reviewer_role="fresh semantic route-action reviewer B",
        rationale=(
            "The committed packet and frozen source form a complete narrow ownership trace: POST route, unique store "
            "method, exact payload, daily_check template resolution, same-day run update-or-create, confirmation, and "
            "the direct frontend caller all converge on CAP-FLEET-DAILY-VEHICLE-CHECK."
        ),
        denied_shared=(
            "Generic checklist storage and adjacent Fleet workflows support the selected action but do not execute or "
            "co-own this exact daily-check recording job; sibling page context is explicitly non-inheritable."
        ),
        denied_gap=(
            "No ownership-material dependency is unresolved. Permission, Site, direct-object, template, concurrency, "
            "audit, and test gaps are correctness-only boundaries and cannot erase direct static identity."
        ),
        prior_outcome_visible_in_team_status=True,
    )
    assert review_a["outcome"] == review_b["outcome"] == "OWNER_ROUTE_ACTION"

    correctness_expansion = [
        {
            "path": relative,
            "sha256": item["sha256"],
            "head_blob_id": item["blob_id"],
            "application_commit_blob_id": item["blob_id"],
            "head_matches_application_commit_blob": True,
            "review_loci": item["loci"],
            "reason": item["reason"],
            "outcome_material": False,
            "correctness_only": True,
            "authorizes_correctness_credit": False,
        }
        for relative, item in CORRECTNESS_ONLY_EXPANSION.items()
    ]
    expansion_manifest_sha256 = canonical_list_sha256(
        [f"{row['path']}|{row['sha256']}|{row['head_blob_id']}" for row in correctness_expansion]
    )

    observations = [
        {
            "observation_id": "RUN148R-ASSURANCE-MUTATION-CAPABILITY",
            "status": "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING",
            "category": "exact_mutation_capability",
            "loci": ["routes/fleet-assets.php:40-47", "app/Http/Middleware/EnsurePermission.php:11-27"],
            "observation": (
                "The selected POST sits inside a read-labelled OR permission group accepting fleet.viewAny, "
                "assets.viewAny, or assets.viewAssigned; an exact daily-check mutation capability is not established."
            ),
            "correctness_credit_authorized": False,
            "final_finding_credit_authorized": False,
        },
        {
            "observation_id": "RUN148R-ASSURANCE-SITE-DIRECT-OBJECT",
            "status": "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING",
            "category": "approved_site_canonical_vehicle_direct_object_concealment",
            "loci": [
                "app/Http/Controllers/FleetAssets/DailyCheckController.php:136-140",
                "app/Policies/AssetPolicy.php:17-21",
                "app/Policies/AssetPolicy.php:74-87",
                "app/Domain/SecurityDevices/Services/SecurityDevicesAccessService.php:1125-1184",
            ],
            "observation": (
                "The store action validates raw assets.id existence but does not resolve a canonical vehicle through "
                "approved Sites, AssetPolicy, or a concealment-aware access service before mutation."
            ),
            "correctness_credit_authorized": False,
            "final_finding_credit_authorized": False,
        },
        {
            "observation_id": "RUN148R-ASSURANCE-TEMPLATE-DAY-CONCURRENCY",
            "status": "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING",
            "category": "template_authority_and_daily_run_concurrency",
            "loci": [
                "app/Http/Controllers/FleetAssets/DailyCheckController.php:142-186",
                "database/migrations/2026_03_22_100300_create_fleet_maintenance_tables.php:11-33",
            ],
            "observation": (
                "Template first-or-create and same-day run check/update-or-create are not shown under an exact "
                "template authority, transaction, lock, or database uniqueness invariant."
            ),
            "correctness_credit_authorized": False,
            "final_finding_credit_authorized": False,
        },
        {
            "observation_id": "RUN148R-ASSURANCE-MUTATION-TEST-COVERAGE",
            "status": "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING",
            "category": "representative_role_site_negative_path_execution",
            "loci": [
                "tests/Feature/FleetAssets/FleetHeroRolloutContractTest.php:58-100",
                "tests/Browser/Fleet/FleetTest.php:66-73",
            ],
            "observation": (
                "Frozen tests cover GET rendering/compliance or a browser smoke only; no exact selected POST "
                "route-name/path mutation assertion was found or executed."
            ),
            "correctness_credit_authorized": False,
            "final_finding_credit_authorized": False,
        },
    ]
    for row in observations:
        row["observation_record_sha256"] = canonical_json_sha256(row)

    synthesis: dict[str, Any] = {
        "synthesis_id": "RUN148R-INDEPENDENT-REVIEW-SYNTHESIS",
        "verdict": "GO_ACCEPT_1_OWNER_ROUTE_ACTION_FOR_BOUNDED_LATER_INTEGRATION",
        "accepted_independent_review_ids": [review_a["review_id"], review_b["review_id"]],
        "accepted_independent_review_record_sha256s": [
            review_a["review_record_sha256"],
            review_b["review_record_sha256"],
        ],
        "accepted_candidate_id": "RUN148-FLEET-DAILY-VEHICLE-CHECK-STORE-ROUTE-ACTION-01",
        "accepted_candidate_record_sha256": CANDIDATE_SHA256,
        "outcome_variables": {"O": 1, "S": 0, "E": 0},
        "independent_reviews_reconciled": True,
        "outcome_discrepancies": 0,
        "identity_or_key_discrepancies": 0,
        "page_credit_discrepancies": 0,
        "hard_stop_discrepancies": 0,
        "ownership_material_expansion_required": False,
        "correctness_only_expansion_manifest_sha256": expansion_manifest_sha256,
        "provisional_assurance_observation_count": len(observations),
        "provisional_assurance_observations_sha256": canonical_json_sha256(observations),
        "route_ownership_authorized": True,
        "controller_action_bridge_authorized": True,
        "page_ownership_authorized": False,
        "current_overlay_credit_awarded": False,
        "correctness_or_downstream_credit_authorized": False,
        "synthesizer_wrote_files": False,
    }
    synthesis["synthesis_record_sha256"] = canonical_json_sha256(synthesis)

    action_decision: dict[str, Any] = {
        "candidate_id": "RUN148-FLEET-DAILY-VEHICLE-CHECK-STORE-ROUTE-ACTION-01",
        "candidate_record_sha256": CANDIDATE_SHA256,
        "accepted_independent_review_ids": synthesis["accepted_independent_review_ids"],
        "accepted_independent_review_record_sha256s": synthesis[
            "accepted_independent_review_record_sha256s"
        ],
        "synthesis_record_sha256": synthesis["synthesis_record_sha256"],
        "queue_index_zero_based": 80,
        "queue_id": "RUN090-ROUTE-0081",
        "queue_canonical_key": "route|RUN077-ROUTE-0689",
        "route_record_id": "RUN077-ROUTE-0689",
        "source_key": "routes/fleet-assets.php:46:9:post:6",
        "literal_route_name": "fleet-assets.daily-check.store",
        "effective_route_name": "fleet-assets.daily-check.store",
        "effective_uri": "/fleet-assets/daily-check",
        "action_key": "RUN077-ROUTE-0689|app/Http/Controllers/FleetAssets/DailyCheckController.php:store|CAP-FLEET-DAILY-VEHICLE-CHECK",
        "candidate_feature_id": "CAP-FLEET-DAILY-VEHICLE-CHECK",
        "controller_file": "app/Http/Controllers/FleetAssets/DailyCheckController.php",
        "controller_method": "store",
        "outcome": "OWNER_ROUTE_ACTION",
        "confidence": "HIGH_STATIC_IDENTITY_ONLY_2_OF_2_PLUS_SYNTHESIS",
        "identity_basis": "EXACT_LITERAL_ROUTE_PLUS_UNIQUE_CONTROLLER_ACTION_PLUS_DIRECT_SUBMITTER_PLUS_DIRECT_DAILY_CHECK_PERSISTENCE",
        "rationale": (
            "Two independent reviews and a separate synthesis agree that the exact POST action directly records the "
            "canonical daily vehicle check. The four provisional correctness observations authorize no correctness, "
            "finding, runtime, test, or completion credit."
        ),
        "provisional_assurance_observation_ids": [row["observation_id"] for row in observations],
        "route_ownership_authorized": True,
        "controller_action_bridge_authorized": True,
        "owner_source_record_key": "route|RUN077-ROUTE-0689|CAP-FLEET-DAILY-VEHICLE-CHECK",
        "bridge_key": [
            "app/Http/Controllers/FleetAssets/DailyCheckController.php",
            "store",
            "CAP-FLEET-DAILY-VEHICLE-CHECK",
        ],
        "page_ownership_authorized": False,
        "prior_sibling_page_owner_inherited_or_recredited": False,
        "next_queue_context_inherited_or_recredited": False,
        "site_permission_privacy_direct_object_template_concurrency_audit_correctness_authorized": False,
        "runtime_database_build_browser_test_benchmark_ease_release_pass_final_finding_completion_authorized": False,
        "current_overlay_credit_awarded": False,
        "reviewer_wrote_files": False,
    }
    action_decision["decision_record_sha256"] = canonical_json_sha256(action_decision)

    question_dispositions = [
        {
            "question_id": "RUN148-Q-OWNERSHIP-IDENTITY",
            "disposition": "RESOLVED_OWNER_ROUTE_ACTION",
            "outcome_material_to_static_ownership": True,
            "correctness_credit_authorized": False,
        },
        *[
            {
                "question_id": question_id,
                "disposition": "UNRESOLVED_CORRECTNESS_ONLY_ZERO_CREDIT",
                "outcome_material_to_static_ownership": False,
                "correctness_credit_authorized": False,
            }
            for question_id in (
                "RUN148-Q-MUTATION-PERMISSION",
                "RUN148-Q-SITE-DIRECT-OBJECT",
                "RUN148-Q-DUPLICATE-CONCURRENCY",
                "RUN148-Q-TEMPLATE-AUTHORITY",
                "RUN148-Q-AUDIT-ASSURANCE",
            )
        ],
    ]

    payload: dict[str, Any] = {
        "schema_version": "run-148r-independent-outcome-neutral-fleet-daily-vehicle-check-store-route-action-review-wave-25-v1",
        "run_id": "RUN-148R-INDEPENDENT-OUTCOME-NEUTRAL-FLEET-DAILY-VEHICLE-CHECK-STORE-ROUTE-ACTION-REVIEW-WAVE-25",
        "status": "GO_ONE_STATIC_OWNER_AND_BRIDGE_AUTHORIZED_FOR_LATER_INTEGRATION_ZERO_CURRENT_OR_DOWNSTREAM_CREDIT",
        "reviewed_on": "2026-08-27",
        "decision": "GO",
        "pins": {
            "checkpoint_commit": CHECKPOINT_COMMIT,
            "checkpoint_tree": CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "app_tree": APP_TREE,
            "routes_tree": ROUTES_TREE,
            "resources_js_tree": RESOURCES_JS_TREE,
            "resources_js_pages_tree": RESOURCES_JS_PAGES_TREE,
            "tests_tree": TESTS_TREE,
            "cohort_generator_sha256": COHORT_GENERATOR_SHA256,
            "cohort_generator_blob_id": COHORT_GENERATOR_BLOB,
            "cohort_sha256": COHORT_SHA256,
            "cohort_blob_id": COHORT_BLOB,
            "cohort_candidate_record_sha256": CANDIDATE_SHA256,
            "cohort_source_packet_sha256": SOURCE_PACKET_SHA256,
            "generator": Path(__file__).relative_to(REPO).as_posix(),
            "generator_sha256": sha256_file(Path(__file__)),
        },
        "architecture_rule": (
            "One operating organisation across multiple Sites; route ownership does not establish exact mutation "
            "permission, approved-Site reach, canonical record ownership, direct-object concealment, or privacy."
        ),
        "methods": {
            "reviewers": 2,
            "synthesizers": 1,
            "static_source_only": True,
            "application_executed": False,
            "framework_routes_executed": False,
            "database_used": False,
            "build_used": False,
            "browser_used": False,
            "tests_executed": False,
        },
        "verified_counts": {
            "cohort_records": 1,
            "independent_review_records": 2,
            "owner_route_actions": 1,
            "shared_relations": 0,
            "evidence_gaps": 0,
            "route_owners_authorized_for_later_overlay": 1,
            "controller_action_bridges_authorized_for_later_overlay": 1,
            "page_owners_authorized": 0,
            "current_overlay_rows_written": 0,
            "provisional_assurance_observations": len(observations),
            "final_findings": 0,
        },
        "independent_candidate_reviews": [review_a, review_b],
        "question_dispositions": question_dispositions,
        "synthesis_review": synthesis,
        "action_decisions": action_decision,
        "provisional_assurance_observations": observations,
        "source_packet_expansion": {
            "original_source_review_complete": False,
            "original_source_packet_completeness_claimed": False,
            "original_material_dependency_semantics_complete": False,
            "original_known_expansion_candidates_adjudicated": False,
            "original_packet_retroactively_described_as_complete": False,
            "ownership_material_expansion": [],
            "ownership_material_expansion_required": False,
            "narrow_ownership_decision_complete": True,
            "correctness_only_expanded_files": correctness_expansion,
            "correctness_only_expansion_manifest_sha256": expansion_manifest_sha256,
            "all_expanded_files_match_application_commit_blobs": True,
            "requested_but_not_fully_inspected": [
                "app/Services/UserSiteAccessService.php approved-Site derivation beyond the selected store action",
                "exact permission-definition and role-assignment source for a daily-check write capability",
                "audit observer, event, or listener census for FleetChecklistRun mutations",
                "exact selected-POST feature and architecture tests",
            ],
            "expansion_authorizes_action_outcome_change": False,
            "expansion_authorizes_correctness_credit": False,
        },
        "selected_action_evidence": {
            "route_file_sha256": "68025ffa9447026ea9aa2d111278a86cf47a49c5d83a4d01fbcbdde70ff61ffd",
            "route_file_blob_id": "c117901e96a026aba846ce3ccc35a1625dadf1bb",
            "route_statement_sha256": "2811e26b388bcf4402eee8164d4ea1b15ed7fa79b73c17b82ccc6866b4d5db3e",
            "controller_file_sha256": "e517cf535bdd6807ef03c0c1b263b774af79b9c4d78a11aa5ed614e652cc18a7",
            "controller_file_blob_id": "28fec8f04cc41ab55d6b37d750c58f5bc773dec5",
            "controller_store_slice_sha256": "532915dacc635e8b448dcb9351e3971b5f27458fc27e037c72c0e6435d49206a",
            "frontend_page_sha256": "dab19a7f92f20548ef8a315f7e10c4a3ac992fb3e4388a277fe57d0faca3aab4",
            "frontend_page_blob_id": "ca4a3b3ae1d1c72c636588ebcb96966c62d056bd",
            "queue_record_sha256": "b73b6a2cf4340520554c6725d701e26f1b313334e8025d6db4f5e7de51392fda",
            "matrix_sha256": "3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0",
            "task_contract_sha256": "48c79c9c220d1ddfa9ab063ed8e3196d64259eb3389b171a93fc48c404604b34",
        },
        "page_sibling_and_next_boundary_reconciliation": {
            "preceding_queue_index": 79,
            "preceding_queue_id": "RUN090-ROUTE-0080",
            "preceding_owner_not_inherited_or_recredited": True,
            "page_ownership_authorized": False,
            "frontend_caller_ownership_authorized": False,
            "next_queue_index": 81,
            "next_queue_id": "RUN090-ROUTE-0082",
            "next_queue_selected_or_credited": False,
        },
        "credit_boundary": {
            "REVIEWED_STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD": True,
            "REVIEWED_STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION": True,
            "BOUNDED_OVERLAY_INTEGRATION_AUTHORIZED": True,
            "CURRENT_OVERLAY_OWNERSHIP_CREDIT": False,
            "STATIC_PAGE_FEATURE_OWNERSHIP": False,
            "correctness": False,
            "framework_route_reachability": False,
            "runtime": False,
            "database": False,
            "build": False,
            "application_browser": False,
            "executed_tests": False,
            "application_source_mutation": False,
            "matrix_mutation": False,
            "benchmark": False,
            "ease": False,
            "release": False,
            "pass": False,
            "final_finding": False,
            "completion": False,
            "audit_complete": False,
        },
        "artifact_completion_test_met": False,
        "audit_completion_test_met": False,
        "wrote_files": [
            Path(__file__).relative_to(REPO).as_posix(),
            OUTPUT_PATH.relative_to(REPO).as_posix(),
        ],
    }
    return payload


def main() -> None:
    payload = build()
    encoded = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    if not OUTPUT_PATH.exists() or OUTPUT_PATH.read_bytes() != encoded:
        OUTPUT_PATH.write_bytes(encoded)
    print(
        json.dumps(
            {
                "output": OUTPUT_PATH.relative_to(REPO).as_posix(),
                "sha256": sha256_file(OUTPUT_PATH),
                "bytes": OUTPUT_PATH.stat().st_size,
                "decision_record_sha256": payload["action_decisions"]["decision_record_sha256"],
                "review_record_sha256s": [
                    row["review_record_sha256"] for row in payload["independent_candidate_reviews"]
                ],
                "status": payload["status"],
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
