#!/usr/bin/env python3
"""Freeze RUN152 queue index 81 as an outcome-neutral source packet."""
from __future__ import annotations

import csv
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT = Path(__file__).resolve().parents[1]
PREFIX = AUDIT.relative_to(REPO).as_posix()
GENERATOR = "generators/build-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26.py"
OUTPUT = "evidence/source/root-run-152-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26.json"
HEAD = "7e25e7fb3c1f9054f5e1cff02e2775ffbb76161f"
TREE = "371c6614b67df484fa2939054879b5fe7885f7dc"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
TESTS_TREE = "fef0122b31fdccbe2f9f805f7515666c74e2880a"
PROMPT = Path(r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md")
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
QUEUE = "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json"
MATRIX = "03-feature-to-benchmark-matrix.csv"
TASK = "task-scripts/cap-fleet-vehicle-register.md"
REVIEWED_SET_GENERATOR = "generators/integrate-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.py"
RUN086_LEDGER = "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json"
RUN079_CLASSIFICATION = "evidence/source/current-route-page-classification-wave-07.json"

PINNED_INPUTS = {
    QUEUE: "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    MATRIX: "3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0",
    TASK: "9dd6e901b7d4ef3f688a246f069c621180b8ebdf72a1ddd6ee30e6dd9f6742bd",
    REVIEWED_SET_GENERATOR: "b5c7f04cd44ecd73dda9c7fe4a9e2e8616c68674cdc52d393ec696b06ad2327e",
    RUN086_LEDGER: "bfec52a9bed7176045457e8920bdc7b65e1c1d5c5eeb28a266f49c12acf69bcf",
    RUN079_CLASSIFICATION: "7b534fea15e6c4ec98fbb2cb0c761e26116b2abee500c39e599b0e779729df97",
    "evidence/source/current-run-149-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.json": "12a52c434ecd18a5c6a644378070aa5ab046f5e7080726b983ded8d9c7377a55",
    "evidence/source/current-run-149r-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25.json": "545694fc1b7bd5f4af244617fb421ece1265fe6e6f2cad2ca834115e7a9e75a2",
    "evidence/source/current-run-150-reviewed-fleet-daily-vehicle-check-store-route-action-reporting-wave-25.json": "f5fd2fd59e8cdf26e30343774c7e76ede235a64cc1f6bb447b9867df2c5f30b2",
    "evidence/browser/current-audit-dashboard-verification-run-151-wave-25.json": "15b4ef5de5fc9029af9ff74dcb02dd1e52177695fd367ea9347c3a8b3c9f20c0",
    "audit-dashboard.html": "7d5556d9e94d9f7c480cbad8b5f4fd5a69990080ff4515364d0821e05ab8f56d",
}

SOURCE_FILES = {
    "routes/fleet-assets.php": {"loci": ["34-52"], "purpose": "auth, fleet.viewAny read group, selected route, preceding and next queue context"},
    "app/Http/Controllers/FleetAssets/VehicleController.php": {"loci": ["31-245"], "purpose": "constructor, complete selected index action, queries, projections and render"},
    "app/Models/Asset.php": {"loci": ["1-80", "315-336"], "purpose": "vehicle model fields/relations and category-only vehicles scope"},
    "app/Policies/AssetPolicy.php": {"loci": ["1-75"], "purpose": "viewAny capability and per-object concealment policy context not invoked by selected index"},
    "app/Services/UserSiteAccessService.php": {"loci": ["1-1625"], "purpose": "approved-Site access service through applyAlertScope near line 1598; selected action uses it only for alert counts"},
    "app/Domain/SecurityDevices/Services/SecurityDevicesAccessService.php": {"loci": ["1-1184"], "purpose": "canonical Asset access/concealment service through applyAssetSiteScope lines 1125-1184; injected but not used by selected index"},
    "app/Domain/SecurityDevices/Presenters/FleetVehicleTechnologyProjectionPresenter.php": {"loci": ["1-180"], "purpose": "vehicle technology projection dependency injected but not used by selected index"},
    "app/Services/Assets/AssetMutationIntegrityService.php": {"loci": ["1-260"], "purpose": "mutation dependency injected but not used by selected read index"},
    "app/Http/Middleware/EnsurePermission.php": {"loci": ["1-40"], "purpose": "route permission middleware semantics"},
    "app/Models/User.php": {"loci": ["346-437"], "purpose": "capability evaluation context"},
    "resources/js/pages/fleet-assets/vehicles/index.tsx": {"loci": ["1-430"], "purpose": "selected rendered page, vehicle/state/map projection, filtering and links"},
    "resources/js/components/app-sidebar.tsx": {"loci": ["1370-1390"], "purpose": "navigation caller to selected URI"},
    "resources/js/pages/fleet-assets/dashboard.tsx": {"loci": ["345-365", "650-700"], "purpose": "dashboard and status-filter callers to selected URI"},
    "tests/Feature/FleetAssets/FleetHeroRolloutContractTest.php": {"loci": ["58-110"], "purpose": "unexecuted selected GET/render and hero contract"},
    "tests/Feature/FleetAssets/FleetControlRoomAlertHeroScopeTest.php": {"loci": ["26-59"], "purpose": "unexecuted alert hero Site-scope contract only"},
    "tests/Feature/SecurityDevices/AssetTrackerRetirementTest.php": {"loci": ["190-210"], "purpose": "unexecuted selected search query"},
    "tests/Browser/Fleet/FleetTest.php": {"loci": ["20-40"], "purpose": "unexecuted selected browser visit"},
    "tests/Browser/Fleet/FleetPermissionsTest.php": {"loci": ["170-195"], "purpose": "unexecuted selected permission browser visits"},
    "docs/architecture/single-tenant-application.md": {"loci": ["1-40"], "purpose": "one-organisation multi-Site authorization boundary"},
}


def run(*args: str) -> bytes:
    return subprocess.run(args, cwd=REPO, check=True, capture_output=True).stdout


def git(*args: str) -> str:
    return run("git", *args).decode("utf-8").rstrip("\r\n")


def sha(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def audit_sha(relative: str) -> str:
    return sha((AUDIT / relative).read_bytes())


def repo_sha(relative: str) -> str:
    return sha((REPO / relative).read_bytes())


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


def source_record(relative: str, contract: dict[str, Any]) -> dict[str, Any]:
    raw = (REPO / relative).read_bytes()
    current_blob = git("hash-object", "--", str(REPO / relative))
    application_blob = git("rev-parse", f"{APPLICATION_COMMIT}:{relative}")
    assert current_blob == application_blob
    return {"path": relative, "sha256": sha(raw), "blob_id": current_blob, "application_commit_blob_id": application_blob, **contract}


def exact_line(relative: str, needle: str, occurrence: int | None = None) -> dict[str, Any]:
    lines = (REPO / relative).read_text(encoding="utf-8-sig").splitlines()
    matches = [(index, line.strip()) for index, line in enumerate(lines, 1) if needle in line]
    if occurrence is None:
        assert len(matches) == 1, (relative, needle, matches)
        occurrence = 0
    assert 0 <= occurrence < len(matches), (relative, needle, matches)
    number, line = matches[occurrence]
    return {"source_anchor": f"{relative}:{number}", "source_line": line}


def canonical_hash(value: Any) -> str:
    return sha(json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode())


def find_record(value: Any, field: str, expected: str) -> dict[str, Any]:
    matches: list[dict[str, Any]] = []
    def walk(node: Any) -> None:
        if isinstance(node, dict):
            if node.get(field) == expected:
                matches.append(node)
            for child in node.values():
                walk(child)
        elif isinstance(node, list):
            for child in node:
                walk(child)
    walk(value)
    assert len(matches) == 1, (field, expected, len(matches))
    return matches[0]


def selected_method_slice() -> dict[str, Any]:
    relative = "app/Http/Controllers/FleetAssets/VehicleController.php"
    lines = (REPO / relative).read_text(encoding="utf-8").splitlines()
    starts = [index for index, line in enumerate(lines) if "public function index(Request $request)" in line]
    next_methods = [index for index, line in enumerate(lines) if "public function show(Request $request, Asset $asset)" in line]
    assert starts == [59] and next_methods == [246]
    selected = lines[59:245]
    text = "\n".join(selected) + "\n"
    assert selected[0].strip() == "public function index(Request $request)"
    assert selected[-1].strip() == "}"
    assert text.count("{") == text.count("}")
    assert "return Inertia::render('fleet-assets/vehicles/index'" in text
    assert "public function show" not in text
    return {"source_file": relative, "method": "index", "start_line": 60, "end_line": 245, "definition_anchor": f"{relative}:60", "text": text, "text_sha256": sha(text.encode("utf-8")), "source_file_sha256": repo_sha(relative), "source_file_blob_id": git("rev-parse", f"{APPLICATION_COMMIT}:{relative}"), "brace_balanced": True}


def main() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == HEAD
    assert git("rev-parse", "HEAD^{tree}") == TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("rev-parse", "HEAD:tests") == TESTS_TREE
    assert PROMPT.is_file() and sha(PROMPT.read_bytes()) == PROMPT_SHA256
    assert not git("status", "--porcelain", "--", "app", "routes", "resources/js", "tests", "database")
    for relative, expected in PINNED_INPUTS.items():
        assert audit_sha(relative) == expected, relative

    queue_doc = strict_json(QUEUE)
    run086 = strict_json(RUN086_LEDGER)
    classification_doc = strict_json(RUN079_CLASSIFICATION)
    page_owner = find_record(run086, "source_record_id", "PAGE-ROOT-07E63287EC196468")
    assert page_owner["mapping_id"] == "RUN086-PAGE-MAP-0007"
    assert page_owner["feature_id"] == "CAP-FLEET-VEHICLE-REGISTER"
    assert page_owner["classification"] == "Reviewed"
    assert page_owner["page_source"]["page_file"] == "resources/js/pages/fleet-assets/vehicles/index.tsx"
    assert page_owner["ledger_row_sha256"] == "abf15322c3c30e1aad403c1558244896ae564ece1bc0e99267b1239120f97fc4"
    historical_route = find_record(classification_doc, "route_record_id", "RUN077-ROUTE-0690")
    assert historical_route["classification"] == "EXPLICIT_UNMAPPED_SENTINEL"
    assert historical_route["reviewed_feature_ids"] == []
    assert historical_route["candidate_feature_ids_reviewed"] == ["CAP-FLEET-VEHICLE-REGISTER"]
    assert historical_route["credit_awarded"] is False
    run086_blob = git("rev-parse", f"HEAD:{PREFIX}/{RUN086_LEDGER}")
    classification_blob = git("rev-parse", f"HEAD:{PREFIX}/{RUN079_CLASSIFICATION}")
    assert run086_blob == git("hash-object", "--", str(AUDIT / RUN086_LEDGER))
    assert classification_blob == git("hash-object", "--", str(AUDIT / RUN079_CLASSIFICATION))
    queue = queue_doc.get("records") or queue_doc.get("queue_records")
    assert isinstance(queue, list) and len(queue) == 507
    preceding, selected, adjacent_following = queue[80:83]
    assert (preceding["queue_id"], preceding["source_record_id"]) == ("RUN090-ROUTE-0081", "RUN077-ROUTE-0689")
    assert (selected["queue_id"], selected["source_record_id"], selected["candidate_feature_id"]) == ("RUN090-ROUTE-0082", "RUN077-ROUTE-0690", "CAP-FLEET-VEHICLE-REGISTER")
    assert (adjacent_following["queue_id"], adjacent_following["source_record_id"], adjacent_following["candidate_feature_id"]) == ("RUN090-ROUTE-0083", "RUN077-ROUTE-0691", "CAP-FLEET-VEHICLE-REGISTER")
    spec = importlib.util.spec_from_file_location("run149_reviewed_set", AUDIT / REVIEWED_SET_GENERATOR)
    assert spec and spec.loader
    reviewed_module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(reviewed_module)
    current_reviewed_keys = reviewed_module.collect_prior_reviewed_queue_keys() | {preceding["canonical_key"]}
    assert len(current_reviewed_keys) == 117
    assert preceding["canonical_key"] in current_reviewed_keys
    assert selected["canonical_key"] not in current_reviewed_keys
    assert adjacent_following["canonical_key"] in current_reviewed_keys
    next_unresolved_index = next(index for index in range(82, len(queue)) if queue[index]["canonical_key"] not in current_reviewed_keys)
    assert next_unresolved_index == 83
    next_unresolved = queue[next_unresolved_index]
    assert (next_unresolved["queue_id"], next_unresolved["source_record_id"]) == ("RUN090-ROUTE-0084", "RUN077-ROUTE-0692")
    assert next_unresolved["canonical_key"] not in current_reviewed_keys
    assert selected["queue_record_sha256"] == "c15a3e4371f5d063066b013b824205c24d1ab6126f49aea3d266e9b897b146de"
    assert selected["source"] == {
        "route_file": "routes/fleet-assets.php", "route_file_sha256": "68025ffa9447026ea9aa2d111278a86cf47a49c5d83a4d01fbcbdde70ff61ffd", "route_file_blob_id": "c117901e96a026aba846ce3ccc35a1625dadf1bb", "source_key": "routes/fleet-assets.php:51:9:get:7", "source_anchor": "routes/fleet-assets.php:51", "source_locator": "routes/fleet-assets.php:51:9:get", "route_method": "get", "literal_uri": "/vehicles", "literal_route_name": "fleet-assets.vehicles.index", "action_expression": "[VehicleController::class, 'index']", "statement_excerpt": "Route::get('/vehicles', [VehicleController::class, 'index'])->name('fleet-assets.vehicles.index');", "statement_sha256": "930b098f1dc5c103fc23ab6086f690d9a0dc1d30a69edf331479cd21b0a2b3ce"
    }
    assert selected["secondary_lane"]["relation_comparison"] == "BOTH_LANES_IDENTICAL"
    assert selected["secondary_lane"]["backend_method_relation"]["resolution"]["definition_anchor"] == "app/Http/Controllers/FleetAssets/VehicleController.php:60"
    assert selected["review_state"]["status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
    assert selected["review_state"]["ownership_credit"] is False

    with (AUDIT / MATRIX).open(encoding="utf-8", newline="") as handle:
        matrix = {row["feature_id"]: row for row in csv.DictReader(handle)}
    feature = matrix["CAP-FLEET-VEHICLE-REGISTER"]
    assert feature["module"] == "Fleet & Assets"
    assert feature["route_names"].split("; ").count("fleet-assets.vehicles.index") == 1
    assert feature["page_files"] == "resources/js/pages/fleet-assets/vehicles/index.tsx"

    required_lines = {
        "route": exact_line("routes/fleet-assets.php", "Route::get('/vehicles', [VehicleController::class, 'index'])"),
        "permission": exact_line("routes/fleet-assets.php", "Route::middleware('permission:fleet.viewAny')->group"),
        "controller": exact_line("app/Http/Controllers/FleetAssets/VehicleController.php", "public function index(Request $request)"),
        "vehicle_query": exact_line("app/Http/Controllers/FleetAssets/VehicleController.php", "$query = Asset::vehicles()"),
        "site_filter": exact_line("app/Http/Controllers/FleetAssets/VehicleController.php", "$query->where('site_id', (int) $request->input('site_id'))"),
        "site_options": exact_line("app/Http/Controllers/FleetAssets/VehicleController.php", "$sites = Site::query()->where('is_active', true)", 0),
        "alert_scope": exact_line("app/Http/Controllers/FleetAssets/VehicleController.php", "$this->siteAccess->applyAlertScope"),
        "render": exact_line("app/Http/Controllers/FleetAssets/VehicleController.php", "return Inertia::render('fleet-assets/vehicles/index'"),
        "frontend_page": exact_line("resources/js/pages/fleet-assets/vehicles/index.tsx", "export default function VehiclesIndex"),
        "sidebar_caller": exact_line("resources/js/components/app-sidebar.tsx", "href: '/fleet-assets/vehicles'"),
    }
    source_packet = [source_record(path, contract) for path, contract in SOURCE_FILES.items()]
    method_slice = selected_method_slice()

    credit_keys = (
        "static_source_feature_ownership", "static_route_feature_ownership", "static_page_feature_ownership",
        "static_controller_action_bridge", "canonical_object_ownership_correctness", "approved_site_scope_correctness",
        "permission_correctness", "privacy_correctness", "direct_object_concealment_correctness",
        "query_projection_correctness", "runtime", "database", "build", "application_browser",
        "responsive_application", "executed_tests", "benchmark", "final_no_match_or_NCM", "final_finding",
        "feature_completion", "completion", "audit_complete",
    )
    receipt = {
        "schema_version": "run-152-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26-v1",
        "run_id": "RUN-152-OUTCOME-NEUTRAL-FLEET-VEHICLE-REGISTER-INDEX-ROUTE-ACTION-COHORT-WAVE-26",
        "status": "OUTCOME_NEUTRAL_SOURCE_PACKET_READY_FRESH_REVIEW_REQUIRED_ZERO_OWNERSHIP_CREDIT",
        "generated_on": "2026-08-27",
        "architecture_rule": "One operating organisation across multiple Sites. Exact roles/capabilities, approved Sites, canonical ownership, direct-object concealment and privacy are the authorization boundary; no tenant design or tenant-isolation credit.",
        "pins": {
            "checkpoint_commit": HEAD, "checkpoint_tree": TREE, "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE, "app_tree": APP_TREE, "routes_tree": ROUTES_TREE,
            "resources_js_tree": RESOURCES_JS_TREE, "resources_js_pages_tree": RESOURCES_JS_PAGES_TREE,
            "tests_tree": TESTS_TREE, "prompt_path": str(PROMPT), "prompt_sha256": PROMPT_SHA256,
            "generator": f"{PREFIX}/{GENERATOR}",
            "generator_sha256": audit_sha(GENERATOR), "generator_blob_id": git("hash-object", "--", str(AUDIT / GENERATOR)),
            "inputs": PINNED_INPUTS,
        },
        "selection_contract": {
            "source": QUEUE, "selected_queue_indices_zero_based": [81], "selected_queue_ids": ["RUN090-ROUTE-0082"],
            "selected_route_record_ids": ["RUN077-ROUTE-0690"], "selected_feature_ids": ["CAP-FLEET-VEHICLE-REGISTER"],
            "selection_outcome_neutral": True, "ownership_decisions_authored": 0, "page_candidates_selected": 0,
        },
        "identity": {"route_method": "get", "literal_uri": "/vehicles", "literal_route_name": "fleet-assets.vehicles.index", "action_expression": "[VehicleController::class, 'index']", "resolved_controller": "App\\Http\\Controllers\\FleetAssets\\VehicleController", "controller_method": "index", "definition_anchor": "app/Http/Controllers/FleetAssets/VehicleController.php:60", "candidate_feature_id": "CAP-FLEET-VEHICLE-REGISTER", "candidate_relation": "BOTH_LANES_IDENTICAL_PENDING_REVIEW"},
        "records": [selected],
        "preceding_context_only": {"queue_index_zero_based": 80, "queue_id": preceding["queue_id"], "route_record_id": preceding["source_record_id"], "queue_record_sha256": preceding["queue_record_sha256"], "selected_or_recredited": False, "identity_or_outcome_inherited": False},
        "adjacent_following_reviewed_context": {"queue_index_zero_based": 82, "queue_id": adjacent_following["queue_id"], "route_record_id": adjacent_following["source_record_id"], "candidate_feature_id": adjacent_following["candidate_feature_id"], "immutable_run090_review_state": adjacent_following["review_state"]["status"], "current_reviewed_key_membership": True, "reviewed_origin": "RUN-091R review / RUN-092 integration", "selected_or_recredited": False, "identity_or_outcome_inherited": False},
        "next_unresolved_boundary": {"queue_index_zero_based": next_unresolved_index, "queue_id": next_unresolved["queue_id"], "route_record_id": next_unresolved["source_record_id"], "candidate_feature_id": next_unresolved["candidate_feature_id"], "queue_record_sha256": next_unresolved["queue_record_sha256"], "current_reviewed_key_membership": False, "selected_or_credited": False},
        "current_reviewed_set_reconciliation": {"reviewed_key_count": 117, "reviewed_key_list_sha256": canonical_hash(sorted(current_reviewed_keys)), "index_80_member": True, "index_81_member": False, "index_82_member": True, "index_83_member": False, "immutable_run090_pending_label_does_not_override_later_review": True},
        "source_review_packet": {
            "required_source_files": source_packet,
            "required_source_file_count": len(source_packet),
            "exact_selected_loci": required_lines,
            "selected_controller_method_slice": method_slice,
            "page_and_caller_context_only": {
                "page_file": "resources/js/pages/fleet-assets/vehicles/index.tsx",
                "sidebar_caller": "resources/js/components/app-sidebar.tsx",
                "selected": False,
                "ownership_recredited": False,
                "context_only": True,
            },
            "packet_sha256": canonical_hash(source_packet),
        },
        "existing_page_owner_reconciliation": {
            "input_path": RUN086_LEDGER,
            "input_sha256": PINNED_INPUTS[RUN086_LEDGER],
            "input_blob_id": run086_blob,
            "record": page_owner,
            "record_sha256": canonical_hash(page_owner),
            "existing_page_owner_id": "PAGE-ROOT-07E63287EC196468",
            "page_selected_in_run_152": False,
            "page_ownership_recredited": False,
            "identity_or_outcome_inherited": False,
        },
        "historical_route_classification_reconciliation": {
            "input_path": RUN079_CLASSIFICATION,
            "input_sha256": PINNED_INPUTS[RUN079_CLASSIFICATION],
            "input_blob_id": classification_blob,
            "record": historical_route,
            "record_sha256": canonical_hash(historical_route),
            "historical_classification": "EXPLICIT_UNMAPPED_SENTINEL",
            "historical_provenance_preserved": True,
            "superseded_for_current_candidate_discovery_by": ["RUN-082", "RUN-090"],
            "historical_record_rewritten": False,
            "ownership_credit_from_historical_record": False,
        },
        "provisional_assurance_questions": [
            "Does fleet.viewAny intentionally grant organisation-wide vehicle visibility, or must the vehicle query be constrained to approved Sites?",
            "Why are the vehicle query, CSV export, hero/compliance totals and active-Site options not visibly passed through an approved-Site scope?",
            "Should the selected index invoke AssetPolicy or SecurityDevicesAccessService rather than relying only on route permission middleware?",
            "Are vehicle home-Site and live state coordinates/speed/battery projections authorized for every fleet.viewAny holder?",
            "Do the unexecuted tests prove foreign-Site list exclusion, arbitrary site_id denial, and aggregate/export non-disclosure?",
        ],
        "provisional_assurance_question_count": 5,
        "fresh_review_contract": {"status": "PENDING", "required_independent_reviews": 2, "distinct_synthesis_required": True, "allowed_outcomes": ["OWNER", "SHARED_RELATION", "EVIDENCE_GAP"], "ownership_integration_authorized": False},
        "stop_rules": ["No route/name/backend containment candidate is an ownership decision.", "No adjacent row, page, frontend caller, model, policy or service identity is inherited.", "Correctness questions remain provisional source observations even if ownership is later accepted.", "No runtime, browser, test, benchmark, finding, completion or Gate 4 credit."],
        "counts": {"selected_queue_rows": 1, "selected_route_rows": 1, "selected_page_rows": 0, "ownership_decisions": 0, "ownership_material_source_files": len(source_packet), "provisional_assurance_questions": 5},
        "credit_boundary": {key: False for key in credit_keys},
        "completion_boundary": {key: False for key in ("framework_route_reachability_complete", "semantic_assurance_complete", "execution_complete", "benchmark_complete", "pass_8_complete", "final_reconciliation_complete", "no_live_agent_gate_complete", "full_crosswalk_complete", "gate_4_complete", "audit_complete")},
        "artifact_completion_test_met": False,
        "source_review_complete": False,
        "source_packet_completeness_claimed": False,
        "material_dependency_semantics_complete": False,
        "known_expansion_adjudicated": False,
        "audit_completion_test_met": False,
        "wrote_files": [f"{PREFIX}/{GENERATOR}", f"{PREFIX}/{OUTPUT}"],
    }
    (AUDIT / OUTPUT).write_text(json.dumps(receipt, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")
    strict_json(OUTPUT)
    expected = {f"?? {PREFIX}/{GENERATOR}", f"?? {PREFIX}/{OUTPUT}"}
    assert {line.lstrip() for line in git("status", "--porcelain").splitlines()} == expected
    assert not list(AUDIT.rglob("__pycache__"))
    print(json.dumps({"status": receipt["status"], "selected": 1, "source_files": len(source_packet), "questions": 5, "generator_sha256": audit_sha(GENERATOR), "receipt_sha256": audit_sha(OUTPUT)}, indent=2))


if __name__ == "__main__":
    main()
