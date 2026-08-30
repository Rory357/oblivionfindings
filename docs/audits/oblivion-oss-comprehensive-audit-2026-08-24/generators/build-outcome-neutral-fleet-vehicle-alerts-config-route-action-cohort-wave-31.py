#!/usr/bin/env python3
"""Freeze RUN169 queue index 83 as a current-pin outcome-neutral source packet."""
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
GENERATOR = "generators/build-outcome-neutral-fleet-vehicle-alerts-config-route-action-cohort-wave-31.py"
OUTPUT = "evidence/source/root-run-169-outcome-neutral-fleet-vehicle-alerts-config-route-action-cohort-wave-31.json"
HEAD = "e488bd3edcda0f154f87e8bbed972f14db409b82"
TREE = "9e93b8aea4f4b907cde3dc59dd0520fba5bd7080"
APP_TREE = "b9a9a672bea01473d8be96a0afb548e6291aee9c"
ROUTES_TREE = "9392e22e4c472610da98977bec4e112092d223b9"
RESOURCES_JS_TREE = "776359c5b8b06a55fcf5fe4464bc3e00d01248e5"
RESOURCES_JS_PAGES_TREE = "077d40c746018b655c9b9f8c1ee3f87c2d792a8c"
TESTS_TREE = "90886d938c57ab7b45c9301514077d16e4c6b470"
PROMPT = Path(r"C:\Users\steph\.codex\attachments\8b35b9fe-b295-4a84-bdf9-a8afb05b2daa\pasted-text-1.txt")
PROMPT_SHA256 = "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d"
QUEUE = "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json"
MATRIX = "03-feature-to-benchmark-matrix.csv"
TASK = "task-scripts/cap-fleet-vehicle-register.md"
REVIEWED_SET_GENERATOR = "generators/integrate-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.py"
RUN153_GENERATOR = "generators/integrate-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.py"

PINNED_INPUTS = {
    QUEUE: "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    MATRIX: "3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0",
    TASK: "9dd6e901b7d4ef3f688a246f069c621180b8ebdf72a1ddd6ee30e6dd9f6742bd",
    REVIEWED_SET_GENERATOR: "b5c7f04cd44ecd73dda9c7fe4a9e2e8616c68674cdc52d393ec696b06ad2327e",
    RUN153_GENERATOR: "00b90c5932614EAF67CBCA29C860924FAD67190605BBF476FDC285174831EA83".lower(),
    "evidence/source/current-run-153-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.json": "9b7e382f83787d807de8d752ecb3e6524280c707899aba78d47082765272e815",
    "evidence/source/current-run-153r-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.json": "7f1da8394a8054f01f34fb943a3fba6601bf70ea06d69cf97033f2208edf4461",
    "evidence/source/current-run-154-reviewed-fleet-vehicle-register-index-route-action-reporting-wave-26.json": "c5e782984c743186305b70fc2430d7dc56aede62621066ca159c96c3484f9ef8",
    "evidence/browser/current-audit-dashboard-verification-run-168-wave-30.json": "95f5eff21563ff010cd49f2ff6cf958825f1d1f7717066ed571e9e078dea4998",
    "audit-dashboard.html": "80360ae152642e4f7c0c90b18c42e76fb156bf8cd34eb9df17b358170cc71b89",
}

SOURCE_FILES = {
    "routes/fleet-assets.php": {"loci": ["49-58"], "purpose": "permission group and exact selected GET route"},
    "app/Http/Controllers/FleetAssets/VehicleController.php": {"loci": ["55-57", "1061-1088"], "purpose": "schema guard, authorized vehicle resolution, alert config/geofences projection and render"},
    "app/Domain/SecurityDevices/Services/SecurityDevicesAccessService.php": {"loci": ["278-290"], "purpose": "canonical authorized vehicle resolver used by the action"},
    "app/Models/Asset.php": {"loci": ["58-86"], "purpose": "canonical vehicle alert_config field and array cast"},
    "resources/js/pages/fleet-assets/vehicles/alerts-config.tsx": {"loci": ["31-59", "167-236", "520-538"], "purpose": "dedicated vehicle alert configuration consumer; context only"},
    "resources/js/pages/fleet-assets/vehicles/show.tsx": {"loci": ["346-353"], "purpose": "direct Configure Alerts caller; context only"},
    "tests/Feature/FleetAssets/AssetMutationBoundaryTest.php": {"loci": ["299-340"], "purpose": "sibling POST denial only; no selected GET coverage"},
    "docs/architecture/single-tenant-application.md": {"loci": ["1-21"], "purpose": "one-organisation multi-Site boundary"},
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


def canonical_hash(value: Any) -> str:
    return sha(json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode())


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


def exact_line(relative: str, needle: str, expected_line: int | None = None) -> dict[str, Any]:
    lines = (REPO / relative).read_text(encoding="utf-8-sig").splitlines()
    matches = [(index, line.strip()) for index, line in enumerate(lines, 1) if needle in line]
    if expected_line is not None:
        matches = [match for match in matches if match[0] == expected_line]
    assert len(matches) == 1, (relative, needle, matches)
    number, line = matches[0]
    return {"source_anchor": f"{relative}:{number}", "source_line": line, "source_line_sha256": sha((line + "\n").encode())}


def source_record(relative: str, contract: dict[str, Any]) -> dict[str, Any]:
    raw = (REPO / relative).read_bytes()
    blob_id = git("hash-object", "--", str(REPO / relative))
    assert blob_id == git("rev-parse", f"{HEAD}:{relative}")
    return {"path": relative, "sha256": sha(raw), "blob_id": blob_id, "bytes": len(raw), "lines": len(raw.splitlines()), **contract}


def selected_method_slice() -> dict[str, Any]:
    relative = "app/Http/Controllers/FleetAssets/VehicleController.php"
    lines = (REPO / relative).read_text(encoding="utf-8").splitlines()
    starts = [index for index, line in enumerate(lines) if "public function alertsConfig(Request $request, Asset $asset)" in line]
    ends = [index for index, line in enumerate(lines) if "public function saveAlertsConfig(Request $request, Asset $asset)" in line]
    assert starts == [1060] and ends == [1089]
    selected = lines[1060:1088]
    text = "\n".join(selected) + "\n"
    assert selected[0].strip() == "public function alertsConfig(Request $request, Asset $asset)"
    assert selected[-1].strip() == "}"
    assert text.count("{") == text.count("}")
    assert "assignableVehicle" in text and "fleet-assets/vehicles/alerts-config" in text
    assert "public function saveAlertsConfig" not in text
    return {"source_file": relative, "method": "alertsConfig", "start_line": 1061, "end_line": 1088, "definition_anchor": f"{relative}:1061", "text": text, "text_sha256": sha(text.encode()), "source_file_sha256": repo_sha(relative), "source_file_blob_id": git("rev-parse", f"{HEAD}:{relative}"), "brace_balanced": True}


def current_reviewed_keys() -> set[str]:
    spec = importlib.util.spec_from_file_location("run149_reviewed_set", AUDIT / REVIEWED_SET_GENERATOR)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    keys = module.collect_prior_reviewed_queue_keys() | {"route|RUN077-ROUTE-0689", "route|RUN077-ROUTE-0690"}
    assert len(keys) == 118
    return keys


def main() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == HEAD
    assert git("rev-parse", "HEAD^{tree}") == TREE
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
    queue = queue_doc.get("records") or queue_doc.get("queue_records")
    assert isinstance(queue, list) and len(queue) == 507
    selected = queue[83]
    assert (selected["queue_id"], selected["source_record_id"], selected["candidate_feature_id"]) == ("RUN090-ROUTE-0084", "RUN077-ROUTE-0692", "CAP-FLEET-VEHICLE-REGISTER")
    assert selected["queue_record_sha256"] == "d29353be38d964311d6586311d654c13dc2a39da9b7bcdb8a6a75d69fa511731"
    assert selected["source"]["statement_sha256"] == "ca8700b0ad42f46141b6acefd691bf135687daad904621ab1cf0a52fb8d310d1"
    assert selected["source"]["literal_route_name"] == "fleet-assets.vehicles.alerts-config"
    assert selected["source"]["literal_uri"] == "/vehicles/{asset}/alerts-config"
    assert selected["source"]["action_expression"] == "[VehicleController::class, 'alertsConfig']"
    assert selected["review_state"]["status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
    assert selected["review_state"]["ownership_credit"] is False

    reviewed_keys = current_reviewed_keys()
    assert queue[81]["canonical_key"] in reviewed_keys
    assert queue[82]["canonical_key"] in reviewed_keys
    assert selected["canonical_key"] not in reviewed_keys
    assert next(index for index in range(83, len(queue)) if queue[index]["canonical_key"] not in reviewed_keys) == 83
    assert next(index for index in range(84, len(queue)) if queue[index]["canonical_key"] not in reviewed_keys) == 84
    assert (queue[84]["queue_id"], queue[84]["source_record_id"]) == ("RUN090-ROUTE-0085", "RUN077-ROUTE-0693")

    with (AUDIT / MATRIX).open(encoding="utf-8", newline="") as handle:
        matrix = {row["feature_id"]: row for row in csv.DictReader(handle)}
    feature = matrix["CAP-FLEET-VEHICLE-REGISTER"]
    assert feature["module"] == "Fleet & Assets"
    assert feature["route_names"].split("; ").count("fleet-assets.vehicles.alerts-config") == 1
    assert feature["page_files"] == "resources/js/pages/fleet-assets/vehicles/index.tsx"

    exact_loci = {
        "route": exact_line("routes/fleet-assets.php", "Route::get('/vehicles/{asset}/alerts-config'"),
        "permission": exact_line("routes/fleet-assets.php", "Route::middleware('permission:fleet.viewAny')->group"),
        "controller": exact_line("app/Http/Controllers/FleetAssets/VehicleController.php", "public function alertsConfig(Request $request, Asset $asset)"),
        "authorized_vehicle": exact_line("app/Http/Controllers/FleetAssets/VehicleController.php", "$asset = $this->deviceAccess->assignableVehicle($user, (int) $asset->getKey()) ?? abort(404);", 1064),
        "render": exact_line("app/Http/Controllers/FleetAssets/VehicleController.php", "return Inertia::render('fleet-assets/vehicles/alerts-config'"),
        "consumer": exact_line("resources/js/pages/fleet-assets/vehicles/alerts-config.tsx", "export default function VehicleAlertsConfig"),
        "caller": exact_line("resources/js/pages/fleet-assets/vehicles/show.tsx", "href={`/fleet-assets/vehicles/${vehicle.id}/alerts-config`}"),
    }
    source_packet = [source_record(path, contract) for path, contract in SOURCE_FILES.items()]
    method_slice = selected_method_slice()
    generator_raw = (AUDIT / GENERATOR).read_bytes()

    receipt: dict[str, Any] = {
        "schema_version": "run-169-outcome-neutral-fleet-vehicle-alerts-config-route-action-cohort-wave-31-v1",
        "run_id": "RUN-169-OUTCOME-NEUTRAL-FLEET-VEHICLE-ALERTS-CONFIG-ROUTE-ACTION-COHORT-WAVE-31",
        "status": "OUTCOME_NEUTRAL_CURRENT_PIN_SOURCE_PACKET_READY_INDEPENDENT_REVIEW_REQUIRED_ZERO_OWNERSHIP_OR_CORRECTNESS_CREDIT",
        "generated_on": "2026-08-30",
        "architecture_rule": "One operating organisation across multiple Sites. Exact permissions, approved Sites, canonical ownership, direct-object concealment and privacy are the boundaries; no tenant design or tenant-isolation credit.",
        "pins": {"checkpoint_commit": HEAD, "checkpoint_tree": TREE, "application_commit": HEAD, "application_tree": TREE, "app_tree": APP_TREE, "routes_tree": ROUTES_TREE, "resources_js_tree": RESOURCES_JS_TREE, "resources_js_pages_tree": RESOURCES_JS_PAGES_TREE, "tests_tree": TESTS_TREE, "prompt_path": str(PROMPT), "prompt_sha256": PROMPT_SHA256, "generator": f"{PREFIX}/{GENERATOR}", "generator_sha256": sha(generator_raw), "generator_blob_id": git("hash-object", "--", str(AUDIT / GENERATOR)), "inputs": PINNED_INPUTS},
        "selection_contract": {"source": QUEUE, "selected_queue_indices_zero_based": [83], "selected_queue_ids": ["RUN090-ROUTE-0084"], "selected_route_record_ids": ["RUN077-ROUTE-0692"], "selected_feature_ids": ["CAP-FLEET-VEHICLE-REGISTER"], "selection_outcome_neutral": True, "ownership_decisions_authored": 0, "page_candidates_selected": 0},
        "identity": {"route_method": "get", "literal_uri": "/vehicles/{asset}/alerts-config", "literal_route_name": "fleet-assets.vehicles.alerts-config", "action_expression": "[VehicleController::class, 'alertsConfig']", "resolved_controller": "App\\Http\\Controllers\\FleetAssets\\VehicleController", "controller_method": "alertsConfig", "definition_anchor": "app/Http/Controllers/FleetAssets/VehicleController.php:1061", "candidate_feature_id": "CAP-FLEET-VEHICLE-REGISTER", "candidate_relation": "BOTH_LANES_IDENTICAL_PENDING_REVIEW"},
        "records": [selected],
        "queue_boundary": {"reviewed_key_count": 118, "reviewed_key_list_sha256": canonical_hash(sorted(reviewed_keys)), "selected_index_83_member": False, "preceding_index_81_member": True, "preceding_index_82_member": True, "current_next_unresolved_index": 83, "current_next_unresolved_queue_id": "RUN090-ROUTE-0084", "post_selection_next_index_if_owner": 84, "post_selection_next_queue_id_if_owner": "RUN090-ROUTE-0085", "immutable_run090_pending_label_does_not_override_later_review": True},
        "source_review_packet": {"required_source_files": source_packet, "required_source_file_count": len(source_packet), "exact_selected_loci": exact_loci, "selected_controller_method_slice": method_slice, "consumer_and_caller_context_only": {"page_file": "resources/js/pages/fleet-assets/vehicles/alerts-config.tsx", "caller_file": "resources/js/pages/fleet-assets/vehicles/show.tsx", "selected": False, "ownership_recredited": False, "context_only": True}, "packet_sha256": canonical_hash(source_packet)},
        "historical_pin_reconciliation": {"task_script_historical_application_pin": "a0493442b9e392d324055c35bf25b69421dc2d35", "current_review_pin": HEAD, "route_file_drifted": True, "queue_statement_sha256_still_exact": True, "historical_review_or_ownership_inherited": False},
        "provisional_assurance_questions": ["Does the selected GET retain exact approved-Site and direct-object concealment for every fleet.viewAny actor?", "Why is there no focused test for the selected GET action/component while the sibling POST denial is tested?", "Do the page and caller express only consumption/navigation rather than co-ownership of the exact GET user job?"],
        "fresh_review_contract": {"status": "PENDING", "required_independent_reviews": 1, "allowed_outcomes": ["OWNER", "SHARED_RELATION", "EVIDENCE_GAP"], "ownership_integration_authorized": False},
        "stop_rules": ["No route/name/backend containment candidate is an ownership decision.", "No page, caller, model, service, sibling POST or adjacent queue row identity is inherited.", "Correctness and test-coverage questions remain separate even if ownership is accepted.", "No runtime, browser, test, benchmark, finding, completion or Gate 4 credit."],
        "counts": {"selected_queue_rows": 1, "selected_route_rows": 1, "selected_page_rows": 0, "ownership_decisions": 0, "ownership_material_source_files": len(source_packet), "provisional_assurance_questions": 3},
        "credit_boundary": {key: False for key in ("static_source_feature_ownership", "static_route_feature_ownership", "static_page_feature_ownership", "static_controller_action_bridge", "canonical_object_ownership_correctness", "approved_site_scope_correctness", "permission_correctness", "privacy_correctness", "direct_object_concealment_correctness", "query_projection_correctness", "runtime", "database", "build", "application_browser", "responsive_application", "executed_tests", "benchmark", "final_no_match_or_NCM", "final_finding", "feature_completion", "completion", "audit_complete")},
        "completion_boundary": {key: False for key in ("framework_route_reachability_complete", "semantic_assurance_complete", "execution_complete", "benchmark_complete", "pass_8_complete", "final_reconciliation_complete", "no_live_agent_gate_complete", "full_crosswalk_complete", "gate_4_complete", "audit_complete")},
        "artifact_completion_test_met": False,
        "source_review_complete": False,
        "audit_completion_test_met": False,
        "wrote_files": [f"{PREFIX}/{GENERATOR}", f"{PREFIX}/{OUTPUT}"],
    }
    receipt["self_seal"] = {"algorithm": "sha256-canonical-json-with-self-seal-omitted", "sha256": canonical_hash(receipt)}
    (AUDIT / OUTPUT).write_text(json.dumps(receipt, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")
    parsed = strict_json(OUTPUT)
    seal = parsed.pop("self_seal")
    assert seal["sha256"] == canonical_hash(parsed)
    assert not list(AUDIT.rglob("__pycache__"))
    print(json.dumps({"status": receipt["status"], "selected": 1, "source_files": len(source_packet), "questions": 3, "generator_sha256": audit_sha(GENERATOR), "receipt_sha256": audit_sha(OUTPUT), "self_seal": seal["sha256"]}, indent=2))


if __name__ == "__main__":
    main()
