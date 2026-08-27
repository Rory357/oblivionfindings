#!/usr/bin/env python3
"""Freeze RUN-148's daily-vehicle-check store action without an outcome.

The committed RUN-147 boundary leaves zero-based RUN-090 queue index 80 as
the next pending exact route/action surface.  This producer freezes that one
surface and its bounded source context.  It awards no ownership, correctness,
runtime, browser, test, benchmark, ease, release, Pass, finding, completion,
or audit credit; a fresh independent semantic review remains mandatory.
"""

from __future__ import annotations

import csv
import hashlib
import importlib.util
import json
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
TEMPLATE_GENERATOR = (
    AUDIT_DIR
    / "generators/build-outcome-neutral-finance-site-portfolio-overview-route-action-cohort-wave-23.py"
)
OUTPUT_PATH = (
    AUDIT_DIR
    / "evidence/source/root-run-148-outcome-neutral-fleet-daily-vehicle-check-store-route-action-cohort-wave-25.json"
)
PROMPT_PATH = Path(
    r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md"
)

CHECKPOINT_COMMIT = "2e578dab7cebf861f1b1e466c6d75f8e200d9d20"
CHECKPOINT_TREE = "c59743a1b48db2f278ec9fd1eb7a25bf44d270df"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
TESTS_TREE = "fef0122b31fdccbe2f9f805f7515666c74e2880a"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"

FEATURE_ID = "CAP-FLEET-DAILY-VEHICLE-CHECK"
QUEUE_INDEX = 80
QUEUE_ID = "RUN090-ROUTE-0081"
ROUTE_ID = "RUN077-ROUTE-0689"
PREVIOUS_REVIEWED_INDEX = 79
PREVIOUS_REVIEWED_QUEUE_ID = "RUN090-ROUTE-0080"
PREVIOUS_REVIEWED_ROUTE_ID = "RUN077-ROUTE-0688"
NEXT_PENDING_INDEX = 81
NEXT_PENDING_QUEUE_ID = "RUN090-ROUTE-0082"
NEXT_PENDING_ROUTE_ID = "RUN077-ROUTE-0690"

spec = importlib.util.spec_from_file_location("run141_template", TEMPLATE_GENERATOR)
assert spec and spec.loader
BASE = importlib.util.module_from_spec(spec)
spec.loader.exec_module(BASE)

sha256_file = BASE.sha256_file
canonical_json_sha256 = BASE.canonical_json_sha256
canonical_list_sha256 = BASE.canonical_list_sha256
load_json = BASE.load_json
git = BASE.git
semantic_slice = BASE.semantic_slice

INPUT_PATHS = {
    "template_generator": TEMPLATE_GENERATOR,
    "matrix": AUDIT_DIR / "03-feature-to-benchmark-matrix.csv",
    "task_contract": AUDIT_DIR / "task-scripts/cap-fleet-daily-vehicle-check.md",
    "direct_queue": AUDIT_DIR / "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json",
    "run142_overlay": AUDIT_DIR / "evidence/source/current-run-142-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-wave-23.json",
    "run142_overlay_review": AUDIT_DIR / "evidence/source/raw-run-142r-independent-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-wave-23.json",
    "run143_reporting": AUDIT_DIR / "evidence/source/current-run-143-reviewed-finance-site-portfolio-overview-route-action-reporting-wave-23.json",
    "run144_dashboard_receipt": AUDIT_DIR / "evidence/browser/current-audit-dashboard-verification-run-144-wave-23.json",
    "run145_benchmark_receipt": AUDIT_DIR / "evidence/benchmark/current-run-145-finance-invoice-fx-benchmark-mapping-wave-24.json",
    "run146_reporting": AUDIT_DIR / "evidence/source/current-run-146-finance-benchmark-reporting-wave-24.json",
    "run147_dashboard_receipt": AUDIT_DIR / "evidence/browser/current-audit-dashboard-verification-run-147-wave-24.json",
    "dashboard_html": AUDIT_DIR / "audit-dashboard.html",
}

EXPECTED_INPUT_SHA256 = {
    "template_generator": "d3cfd34687ba6c6a9b6afecfe9bfc02d2b700b15de881c1ef651877c486fd6a0",
    "matrix": "3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0",
    "task_contract": "48c79c9c220d1ddfa9ab063ed8e3196d64259eb3389b171a93fc48c404604b34",
    "direct_queue": "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    "run142_overlay": "2d5228394090bcdad9ebfc3976be87260bf1dc5d110a8297974b00d908b63cdb",
    "run142_overlay_review": "005cbe019f16d7705f7d632b97a8f2629bf7c5653ba3ff9b30c50bd10e2a44df",
    "run143_reporting": "dcb542f98b8ed66bddc92498bcd95cf9c68815bab3f77960ccfbe3bfc7099f21",
    "run144_dashboard_receipt": "fd21527929483cca88e03af8a8ff2f5e8095c5af280fc27546486e3ddc6dd7f5",
    "run145_benchmark_receipt": "8306a8aefe0a490ebf206d0c4716d92930326988f19e0ed495a3c2d0002c7cf9",
    "run146_reporting": "50953b6281cf198f6dc6ff56027d0eebe7e78697781d459dd620ed9bb2b1277e",
    "run147_dashboard_receipt": "36e0595b3e90f439770c9e8aadbb01555591c79e38ffac54d3cfd6dc3b892cc0",
    "dashboard_html": "277db943400776d0bd3be1b0c97afff69ea7b76e97c861abf5c135dc6be00c33",
}

SOURCE_FILE_PURPOSES = {
    "routes/fleet-assets.php": (
        "routes/fleet-assets.php:34-52",
        "auth group, read-permission group, selected POST route, preceding reviewed GET route, and next vehicle route",
    ),
    "app/Http/Controllers/FleetAssets/DailyCheckController.php": (
        "app/Http/Controllers/FleetAssets/DailyCheckController.php:15-190",
        "complete selected controller including the sibling page action and selected store mutation",
    ),
    "app/Models/FleetChecklistRun.php": (
        "app/Models/FleetChecklistRun.php:1-40",
        "selected mutation target fillable fields, casts, and relations",
    ),
    "app/Models/FleetChecklistTemplate.php": (
        "app/Models/FleetChecklistTemplate.php:1-29",
        "selected first-or-create template target and legacy storage-context concern",
    ),
    "app/Models/Asset.php": (
        "app/Models/Asset.php:1-336",
        "raw exists target, Site/home-Site fields and canonical vehicle scope context",
    ),
    "app/Models/User.php": (
        "app/Models/User.php:346-437",
        "exact capability evaluation context for route middleware",
    ),
    "app/Http/Middleware/EnsurePermission.php": (
        "app/Http/Middleware/EnsurePermission.php:1-29",
        "pipe-separated route permission enforcement",
    ),
    "bootstrap/app.php": (
        "bootstrap/app.php:1-96",
        "permission middleware alias registration",
    ),
    "database/migrations/2026_03_22_100300_create_fleet_maintenance_tables.php": (
        "database/migrations/2026_03_22_100300_create_fleet_maintenance_tables.php:9-33",
        "checklist template/run schema and asset/user foreign keys",
    ),
    "resources/js/pages/fleet-assets/daily-check.tsx": (
        "resources/js/pages/fleet-assets/daily-check.tsx:59-101",
        "tracked selected POST caller and payload",
    ),
    "resources/js/pages/fleet-assets/vehicles/index.tsx": (
        "resources/js/pages/fleet-assets/vehicles/index.tsx:275-295",
        "separate navigation caller to the already-reviewed daily-check page",
    ),
    "resources/js/pages/fleet-assets/vehicles/show.tsx": (
        "resources/js/pages/fleet-assets/vehicles/show.tsx:1140-1160",
        "separate vehicle-detail navigation caller",
    ),
    "resources/js/components/app-sidebar.tsx": (
        "resources/js/components/app-sidebar.tsx:1360-1380",
        "sidebar navigation context only",
    ),
    "tests/Feature/FleetAssets/FleetHeroRolloutContractTest.php": (
        "tests/Feature/FleetAssets/FleetHeroRolloutContractTest.php:58-100",
        "unexecuted GET-only page/compliance assertion; not selected mutation evidence",
    ),
    "tests/Browser/Fleet/FleetTest.php": (
        "tests/Browser/Fleet/FleetTest.php:66-73",
        "unexecuted GET-only browser smoke; not selected mutation evidence",
    ),
    "docs/architecture/single-tenant-application.md": (
        "docs/architecture/single-tenant-application.md:1-21",
        "canonical one-organisation multi-Site boundary",
    ),
}


def exact_lines(relative: str, needles: list[str]) -> list[dict[str, Any]]:
    lines = (REPO / relative).read_text(encoding="utf-8-sig").splitlines()
    rows: list[dict[str, Any]] = []
    for needle in needles:
        matches = [(number, line) for number, line in enumerate(lines, 1) if needle in line]
        assert len(matches) == 1, (relative, needle, matches)
        number, line = matches[0]
        rows.append(
            {
                "source_file": relative,
                "source_file_sha256": sha256_file(REPO / relative),
                "source_file_blob_id": git("rev-parse", f"{APPLICATION_COMMIT}:{relative}"),
                "source_anchor": f"{relative}:{number}",
                "source_line": line.strip(),
                "source_line_sha256": hashlib.sha256(line.encode("utf-8")).hexdigest(),
                "needle": needle,
            }
        )
    return rows


def assert_workspace_and_inputs() -> None:
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
    assert PROMPT_PATH.is_file() and sha256_file(PROMPT_PATH) == PROMPT_SHA256
    for name, target in INPUT_PATHS.items():
        assert target.is_file(), target
        assert sha256_file(target) == EXPECTED_INPUT_SHA256[name], name
    for relative in SOURCE_FILE_PURPOSES:
        assert (REPO / relative).is_file(), relative
        assert git("rev-parse", f"HEAD:{relative}") == git(
            "rev-parse", f"{APPLICATION_COMMIT}:{relative}"
        ), relative


def source_review_packet() -> dict[str, Any]:
    required_files = [
        {
            "path": relative,
            "sha256": sha256_file(REPO / relative),
            "blob_id": git("rev-parse", f"HEAD:{relative}"),
            "application_commit_blob_id": git("rev-parse", f"{APPLICATION_COMMIT}:{relative}"),
            "review_loci": [part.strip() for part in loci.split(";")],
            "purpose": purpose,
        }
        for relative, (loci, purpose) in SOURCE_FILE_PURPOSES.items()
    ]
    primary = semantic_slice(
        "app/Http/Controllers/FleetAssets/DailyCheckController.php", "store"
    )
    sibling = semantic_slice(
        "app/Http/Controllers/FleetAssets/DailyCheckController.php", "index"
    )
    dependency_slices = [
        semantic_slice("app/Models/Asset.php", "scopeVehicles"),
        semantic_slice("app/Models/User.php", "canDo"),
        semantic_slice("app/Http/Middleware/EnsurePermission.php", "handle"),
    ]
    packet = {
        "source_tree_pinning_basis": {
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "head_app_tree": APP_TREE,
            "head_routes_tree": ROUTES_TREE,
            "head_resources_js_tree": RESOURCES_JS_TREE,
            "head_resources_js_pages_tree": RESOURCES_JS_PAGES_TREE,
            "head_tests_tree": TESTS_TREE,
            "every_required_file_matches_application_commit_blob": True,
        },
        "required_source_files": required_files,
        "required_source_file_count": len(required_files),
        "required_source_file_identity_sha256": canonical_list_sha256(
            [f"{row['path']}|{row['sha256']}|{row['blob_id']}" for row in required_files]
        ),
        "selected_controller_action_slice": primary,
        "selected_controller_action_slice_sha256": primary["review_slice"]["text_sha256"],
        "already_reviewed_sibling_page_action_slice": sibling,
        "already_reviewed_sibling_page_action_inheritable": False,
        "material_dependency_method_slices": dependency_slices,
        "material_dependency_method_slice_count": len(dependency_slices),
        "selected_frontend_submit_callsites": exact_lines(
            "resources/js/pages/fleet-assets/daily-check.tsx",
            ["router.post(", "'/fleet-assets/daily-check',", "asset_id: vehicleId,"],
        ),
        "selected_route_and_permission_callsites": exact_lines(
            "routes/fleet-assets.php",
            [
                "// Dashboard & Map - viewable if user can see fleet or assets",
                "Route::post('/daily-check', [DailyCheckController::class, 'store'])",
            ],
        ),
        "known_excluded_expansion_candidates": [
            "app/Http/Controllers/FleetAssets/InspectionController.php",
            "app/Http/Controllers/FleetAssets/ChecklistController.php",
            "app/Http/Controllers/FleetAssets/WorkOrderController.php",
            "app/Services/Fleet/FleetTimelineService.php",
            "app/Models/FleetVehicleBooking.php",
        ],
        "source_review_complete": False,
        "source_packet_completeness_claimed": False,
        "material_dependency_semantics_complete": False,
        "known_expansion_candidates_adjudicated": False,
        "unexecuted_test_context_is_runtime_evidence": False,
        "review_rule": (
            "Review the exact selected POST action and expand the packet for every ownership-material dependency. "
            "Ownership identity is distinct from permission, approved-Site, canonical record, direct-object, "
            "privacy, mutation, concurrency, audit, runtime, database, browser, test, and completion correctness. "
            "Any unresolved ownership identity requires EVIDENCE_GAP; correctness gaps do not erase an otherwise "
            "direct static owner and receive no downstream credit."
        ),
    }
    packet["source_review_packet_sha256"] = canonical_json_sha256(packet)
    return packet


def build() -> dict[str, Any]:
    assert_workspace_and_inputs()
    with INPUT_PATHS["matrix"].open("r", encoding="utf-8-sig", newline="") as handle:
        matrix_rows = list(csv.DictReader(handle))
    assert len(matrix_rows) == 340
    matrix_by_id = {row["feature_id"]: row for row in matrix_rows}
    assert len(matrix_by_id) == 340 and FEATURE_ID in matrix_by_id

    queue = load_json(INPUT_PATHS["direct_queue"])
    run142 = load_json(INPUT_PATHS["run142_overlay"])
    run142_review = load_json(INPUT_PATHS["run142_overlay_review"])
    run143 = load_json(INPUT_PATHS["run143_reporting"])
    run144 = load_json(INPUT_PATHS["run144_dashboard_receipt"])
    run145 = load_json(INPUT_PATHS["run145_benchmark_receipt"])
    run146 = load_json(INPUT_PATHS["run146_reporting"])
    run147 = load_json(INPUT_PATHS["run147_dashboard_receipt"])

    assert len(queue["records"]) == 507
    assert run142["combined_counts"]["source_owner_records"] == 662
    assert run142["combined_counts"]["route_owner_records"] == 305
    assert run142["combined_counts"]["page_owner_records"] == 357
    assert run142["queue_accounting"]["reviewed_queue_surface_rows"] == 116
    assert run142["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 391
    assert run142_review["audit_completion_test_met"] is False
    assert run143["counts"] == {
        **run142["combined_counts"],
        **run142["queue_accounting"],
    }
    assert run143["noninheritance"]["next_index_80_not_selected_or_credited"] is True
    assert run144["audit_completion_test_met"] is False
    assert run145["counts"]["benchmark_mappings"] == 2
    assert run145["counts"]["final_no_matches_or_NCMs"] == 0
    assert run146["credit_boundary"]["audit_completion"] == 0
    assert run147["audit_completion_test_met"] is False

    selected = queue["records"][QUEUE_INDEX]
    previous = queue["records"][PREVIOUS_REVIEWED_INDEX]
    next_pending = queue["records"][NEXT_PENDING_INDEX]
    assert (
        selected["queue_id"],
        selected["source_record_id"],
        selected["candidate_feature_id"],
        selected["queue_record_sha256"],
    ) == (
        QUEUE_ID,
        ROUTE_ID,
        FEATURE_ID,
        "b73b6a2cf4340520554c6725d701e26f1b313334e8025d6db4f5e7de51392fda",
    )
    assert (
        previous["queue_id"],
        previous["source_record_id"],
        previous["candidate_feature_id"],
    ) == (PREVIOUS_REVIEWED_QUEUE_ID, PREVIOUS_REVIEWED_ROUTE_ID, FEATURE_ID)
    assert (
        next_pending["queue_id"],
        next_pending["source_record_id"],
        next_pending["candidate_feature_id"],
    ) == (
        NEXT_PENDING_QUEUE_ID,
        NEXT_PENDING_ROUTE_ID,
        "CAP-FLEET-VEHICLE-REGISTER",
    )
    assert selected["review_state"] == {
        "status": "PENDING_FRESH_SEMANTIC_REVIEW",
        "allowed_outcomes": ["OWNER", "SHARED_RELATION", "EVIDENCE_GAP"],
        "ownership_credit": False,
    }
    assert selected["direct_identity"]["candidate_cardinality"] == 1
    assert selected["secondary_lane"]["relation_comparison"] == "BOTH_LANES_IDENTICAL"
    resolution = selected["secondary_lane"]["backend_method_relation"]["resolution"]
    assert resolution["controller_file"] == "app/Http/Controllers/FleetAssets/DailyCheckController.php"
    assert resolution["method"] == "store" and resolution["definition_line"] == 134
    assert selected["source"]["literal_route_name"] == "fleet-assets.daily-check.store"
    assert selected["source"]["route_method"] == "post"
    assert selected["source"]["statement_sha256"] == "2811e26b388bcf4402eee8164d4ea1b15ed7fa79b73c17b82ccc6866b4d5db3e"

    feature = matrix_by_id[FEATURE_ID]
    route_names = [part.strip() for part in feature["route_names"].split(";") if part.strip()]
    assert "fleet-assets.daily-check.store" in route_names
    assert feature["feature_class"] == "H"
    assert feature["module"] == "Fleet & Assets"
    assert feature["completion_status"] == "INCOMPLETE_CANONICAL_STATIC_IDENTITY_ONLY"

    controller_action = semantic_slice(resolution["controller_file"], resolution["method"])
    record: dict[str, Any] = {
        "candidate_id": "RUN148-FLEET-DAILY-VEHICLE-CHECK-STORE-ROUTE-ACTION-01",
        "action_key": f"{ROUTE_ID}|{resolution['controller_file']}:{resolution['method']}|{FEATURE_ID}",
        "run090_original_partition": selected["review_partition"],
        "queue_index_zero_based": QUEUE_INDEX,
        "queue_id": QUEUE_ID,
        "queue_canonical_key": selected["canonical_key"],
        "candidate_feature_id": FEATURE_ID,
        "name_only_identity": selected["direct_identity"],
        "route_source": {
            "route_record_id": ROUTE_ID,
            **selected["source"],
        },
        "controller_action": {
            "resolved_fqcn": resolution["resolved_fqcn"],
            **controller_action,
        },
        "feature_identity_projection": selected["feature_identity_projection"],
        "frontend_submit_context": exact_lines(
            "resources/js/pages/fleet-assets/daily-check.tsx",
            ["router.post(", "'/fleet-assets/daily-check',", "asset_id: vehicleId,"],
        ),
        "fresh_review_state": {
            "status": "PENDING_FRESH_SEMANTIC_REVIEW",
            "allowed_outcomes": ["OWNER", "SHARED_RELATION", "EVIDENCE_GAP"],
            "ownership_credit": False,
            "controller_action_bridge_credit": False,
            "correctness_credit": False,
            "runtime_credit": False,
            "application_browser_credit": False,
            "executed_test_credit": False,
            "completion_credit": False,
        },
        "evidence_digests": {
            **selected["evidence_digests"],
            "run090_queue_record_sha256": selected["queue_record_sha256"],
            "controller_action_slice_sha256": controller_action["review_slice"]["text_sha256"],
            "task_contract_sha256": EXPECTED_INPUT_SHA256["task_contract"],
        },
    }
    record["candidate_record_sha256"] = canonical_json_sha256(record)

    questions = [
        {
            "question_id": "RUN148-Q-OWNERSHIP-IDENTITY",
            "question": "Does the exact POST action directly implement the matrix user job, or is a shared relation/evidence gap required?",
            "outcome_material_to_static_ownership": True,
            "resolved": False,
        },
        {
            "question_id": "RUN148-Q-MUTATION-PERMISSION",
            "question": "Does a read-capability route group authorize this write, or is an exact mutation capability absent?",
            "outcome_material_to_static_ownership": False,
            "correctness_only": True,
            "resolved": False,
        },
        {
            "question_id": "RUN148-Q-SITE-DIRECT-OBJECT",
            "question": "Does raw exists validation enforce canonical vehicle identity, approved-Site reach, and foreign-Site direct-object denial before mutation?",
            "outcome_material_to_static_ownership": False,
            "correctness_only": True,
            "resolved": False,
        },
        {
            "question_id": "RUN148-Q-DUPLICATE-CONCURRENCY",
            "question": "Is one daily run per vehicle/template/day protected under concurrency rather than application-only first/update logic?",
            "outcome_material_to_static_ownership": False,
            "correctness_only": True,
            "resolved": False,
        },
        {
            "question_id": "RUN148-Q-TEMPLATE-AUTHORITY",
            "question": "Is first-or-create template creation governed, uniquely constrained, and authorised for the selected actor?",
            "outcome_material_to_static_ownership": False,
            "correctness_only": True,
            "resolved": False,
        },
        {
            "question_id": "RUN148-Q-AUDIT-ASSURANCE",
            "question": "Are mutation audit/event/durability and representative-role negative-path assertions present and executed?",
            "outcome_material_to_static_ownership": False,
            "correctness_only": True,
            "resolved": False,
        },
    ]

    source_packet = source_review_packet()
    identity = {
        "queue_index_list_sha256": canonical_list_sha256([str(QUEUE_INDEX)]),
        "queue_id_list_sha256": canonical_list_sha256([QUEUE_ID]),
        "canonical_key_list_sha256": canonical_list_sha256([selected["canonical_key"]]),
        "source_key_list_sha256": canonical_list_sha256([selected["source"]["source_key"]]),
        "route_record_id_list_sha256": canonical_list_sha256([ROUTE_ID]),
        "action_key_list_sha256": canonical_list_sha256([record["action_key"]]),
        "candidate_record_sha256_list_sha256": canonical_list_sha256(
            [record["candidate_record_sha256"]]
        ),
        "records_sha256": canonical_json_sha256([record]),
    }
    payload: dict[str, Any] = {
        "schema_version": "run-148-outcome-neutral-fleet-daily-vehicle-check-store-route-action-cohort-wave-25-v1",
        "run_id": "RUN-148-OUTCOME-NEUTRAL-FLEET-DAILY-VEHICLE-CHECK-STORE-ROUTE-ACTION-COHORT-WAVE-25",
        "status": "OUTCOME_NEUTRAL_SOURCE_PACKET_READY_FRESH_REVIEW_REQUIRED_ZERO_OWNERSHIP_CREDIT",
        "generated_on": "2026-08-27",
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
            "prompt_path": str(PROMPT_PATH),
            "prompt_sha256": PROMPT_SHA256,
            "generator": Path(__file__).relative_to(REPO).as_posix(),
            "generator_sha256": sha256_file(Path(__file__)),
            "inputs": {
                INPUT_PATHS[name].relative_to(AUDIT_DIR).as_posix(): digest
                for name, digest in EXPECTED_INPUT_SHA256.items()
            },
        },
        "architecture_rule": (
            "One operating organisation across multiple Sites. Exact roles and capabilities, approved Sites, "
            "canonical record ownership, foreign-Site/direct-object concealment, and privacy are the boundary; "
            "legacy tenant_id or organization_id storage is not a new tenancy design."
        ),
        "selection_contract": {
            "source": INPUT_PATHS["direct_queue"].relative_to(AUDIT_DIR).as_posix(),
            "rule": (
                "Select all and only zero-based RUN-090 queue index 80 after the committed RUN-147 boundary. "
                "Preserve the preceding reviewed GET route and next pending vehicle route as non-inheritable context."
            ),
            "selected_queue_indices_zero_based": [QUEUE_INDEX],
            "selected_queue_ids": [QUEUE_ID],
            "selected_route_record_ids": [ROUTE_ID],
            "selected_feature_ids": [FEATURE_ID],
            "selection_outcome_neutral": True,
            "ownership_decisions_authored": 0,
            "page_candidates_selected": 0,
        },
        "current_baseline": {
            "run142_overlay_sha256": EXPECTED_INPUT_SHA256["run142_overlay"],
            "run142_overlay_review_sha256": EXPECTED_INPUT_SHA256["run142_overlay_review"],
            "run143_reporting_sha256": EXPECTED_INPUT_SHA256["run143_reporting"],
            "run144_dashboard_receipt_sha256": EXPECTED_INPUT_SHA256["run144_dashboard_receipt"],
            "run145_benchmark_receipt_sha256": EXPECTED_INPUT_SHA256["run145_benchmark_receipt"],
            "run146_reporting_sha256": EXPECTED_INPUT_SHA256["run146_reporting"],
            "run147_dashboard_receipt_sha256": EXPECTED_INPUT_SHA256["run147_dashboard_receipt"],
            "dashboard_html_sha256": EXPECTED_INPUT_SHA256["dashboard_html"],
            "source_owner_records": 662,
            "route_owner_records": 305,
            "page_owner_records": 357,
            "static_controller_action_bridges": 93,
            "reviewed_queue_surface_rows": 116,
            "pending_unreviewed_queue_surface_rows": 391,
            "benchmark_mappings": 2,
            "benchmark_final_ncms": 0,
            "benchmark_unresolved_targets": 338,
        },
        "source_review_packet": source_packet,
        "excluded_preceding_reviewed_neighbor": {
            "queue_index_zero_based": PREVIOUS_REVIEWED_INDEX,
            "queue_id": PREVIOUS_REVIEWED_QUEUE_ID,
            "route_record_id": PREVIOUS_REVIEWED_ROUTE_ID,
            "candidate_feature_id": FEATURE_ID,
            "reviewed_owner_origin": "RUN-098",
            "reviewed_outcome": "OWNER_ROUTE_ACTION",
            "selected_for_run148": False,
            "recredit_authorized": False,
            "identity_or_outcome_inheritable": False,
        },
        "next_pending_boundary": {
            "queue_index_zero_based": NEXT_PENDING_INDEX,
            "queue_id": NEXT_PENDING_QUEUE_ID,
            "route_record_id": NEXT_PENDING_ROUTE_ID,
            "candidate_feature_id": "CAP-FLEET-VEHICLE-REGISTER",
            "review_state": "PENDING_FRESH_SEMANTIC_REVIEW",
            "selected_for_run148": False,
            "credit_awarded": False,
        },
        "provisional_assurance_questions": questions,
        "provisional_assurance_question_count": len(questions),
        "semantic_review_focus": [
            "exact static route/action ownership identity",
            "possible shared relation versus direct owner",
            "ownership-material source-packet expansion",
            "write capability boundary separated from route identity",
            "canonical vehicle and approved-Site direct-object boundary separated from route identity",
            "template/run concurrency and audit assurance separated from route identity",
        ],
        "stop_rules": [
            "Stop on any checkpoint, tree, prompt, input, source blob, queue identity, or record digest mismatch.",
            "Stop rather than inherit the preceding GET owner, page, caller, or any other route outcome.",
            "Stop rather than assign OWNER when an ownership-material dependency is unresolved.",
            "Do not convert correctness gaps into correctness findings or downstream credit in this cohort.",
        ],
        "counts": {
            "selected_route_actions": 1,
            "selected_page_records": 0,
            "selected_distinct_feature_ids": 1,
            "fresh_semantic_reviews_completed": 0,
            "owner_route_actions": 0,
            "shared_relations": 0,
            "evidence_gaps": 0,
            "static_source_owner_records_authorized": 0,
            "static_controller_action_bridges_authorized": 0,
        },
        "identity": identity,
        "records": [record],
        "fresh_review_contract": {
            "required_reviewers": [
                "fresh semantic reviewer independent of the producer",
                "independent integration reviewer after any accepted decision",
            ],
            "allowed_outcomes": ["OWNER", "SHARED_RELATION", "EVIDENCE_GAP"],
            "required_decision_fields": [
                "candidate_id",
                "candidate_record_sha256",
                "review_outcome",
                "ownership_material_expansion",
                "review_rationale",
                "denied_alternatives",
                "credit_boundary",
                "decision_record_sha256",
            ],
            "reviewers_must_not_treat_provisional_questions_as_findings": True,
            "reviewers_must_separate_ownership_from_correctness": True,
        },
        "outcome_neutral_conservation_contract": {
            "ownership_credit_before_review": 0,
            "bridge_credit_before_review": 0,
            "page_credit_before_review": 0,
            "correctness_credit_before_review": 0,
            "runtime_credit_before_review": 0,
            "application_browser_credit_before_review": 0,
            "executed_test_credit_before_review": 0,
            "completion_credit_before_review": 0,
        },
        "denominator_boundary": {
            "bounded_static_source_denominator": 3929,
            "direct_exact_queue_records": 507,
            "queue_rows_selected": 1,
            "wholesale_queue_ownership_authorized": False,
            "full_crosswalk_complete": False,
        },
        "credit_boundary": {
            "outcome_neutral_source_packet": True,
            "static_source_ownership": False,
            "static_controller_action_bridge": False,
            "page_ownership": False,
            "correctness": False,
            "framework_route_reachability": False,
            "runtime": False,
            "database": False,
            "build": False,
            "application_browser": False,
            "executed_tests": False,
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
                "queue_index_zero_based": payload["records"][0]["queue_index_zero_based"],
                "queue_id": payload["records"][0]["queue_id"],
                "candidate_record_sha256": payload["records"][0]["candidate_record_sha256"],
                "status": payload["status"],
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
