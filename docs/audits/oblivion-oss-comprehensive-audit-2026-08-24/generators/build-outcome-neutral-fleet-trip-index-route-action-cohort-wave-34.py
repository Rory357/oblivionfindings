#!/usr/bin/env python3
"""Freeze RUN179 queue index 84 as an outcome-neutral current-source packet."""
from __future__ import annotations

import csv
import hashlib
import json
from pathlib import Path
import re
import subprocess
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT = Path(__file__).resolve().parents[1]
PREFIX = AUDIT.relative_to(REPO).as_posix()
GENERATOR = "generators/build-outcome-neutral-fleet-trip-index-route-action-cohort-wave-34.py"
OUTPUT = "evidence/source/root-run-179-outcome-neutral-fleet-trip-index-route-action-cohort-wave-34.json"

HEAD = "f40e3d63ea99d774265ff9f2eefef8176ab0cbc7"
TREE = "880721d56b7d379abf9628abb22a5a9b9445194b"
PARENT = "519e00a9789343720f4e85e18908908ce278d65c"
SUBTREES = {
    "app": "3a83cf8acdd88071870634501ab7eacf2d76e62a",
    "routes": "b62a85f59ba5f45a54fd666b3199a65453034272",
    "resources/js": "8a851516cdb76ded362fb5912e3e930e45c8df86",
    "resources/js/pages": "8ad1ecc5817310f2f45c64733ca72d771c798a2f",
    "tests": "332a54fe95c85c1c1ea9477a1ea115bce9f7b4ac",
    "database": "341446159b5d8f6e303db9e9cddabfd446b0e034",
    "bootstrap": "df6189abe5ab5343d88674c199c4ce46e6152a57",
    "docs/architecture": "3444047114f5f446954b032dedc4e0c7892180bd",
    "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24": "55c64d6d46404160a1729d463c266ce12cb9b6bf",
}

GOVERNING_PROMPT = Path(
    r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md"
)
GOVERNING_PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
CONTINUATION_REQUEST = Path(
    r"C:\Users\steph\.codex\attachments\8b35b9fe-b295-4a84-bdf9-a8afb05b2daa\pasted-text-1.txt"
)
CONTINUATION_REQUEST_SHA256 = "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d"

QUEUE = "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json"
MATRIX = "03-feature-to-benchmark-matrix.csv"
TASK = "task-scripts/cap-fleet-vehicle-register.md"
RUN170 = "evidence/source/current-run-170-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.json"
RUN170R = "evidence/source/current-run-170r-independent-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-review-wave-31.json"
RUN171 = "evidence/source/current-run-171-reviewed-fleet-vehicle-alerts-config-route-action-reporting-wave-31.json"
RUN172 = "evidence/browser/current-audit-dashboard-verification-run-172-wave-31.json"
RUN176 = "evidence/runtime/current-run-176-fleet-trip-index-site-privacy-remediation-wave-33.json"
RUN176R = "evidence/runtime/current-run-176r-independent-fleet-trip-index-site-privacy-remediation-review-wave-33.json"
RUN177 = "evidence/source/current-run-177-fleet-trip-index-site-privacy-remediation-reporting-wave-33.json"
RUN178 = "evidence/browser/current-audit-dashboard-verification-run-178-wave-33.json"

PINNED_INPUTS = {
    QUEUE: "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    MATRIX: "3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0",
    TASK: "9dd6e901b7d4ef3f688a246f069c621180b8ebdf72a1ddd6ee30e6dd9f6742bd",
    RUN170: "c739a36e1975b60d42988be3de36b9fe1ea88cf942752c90112f40ebaa04cd8d",
    RUN170R: "62474100b0c2f027fa0c15f2bb841f08ad3de058da67725a931fcafec17dd139",
    RUN171: "9ddc5386a57f782a50564d54d33a14826a84b1c91a6bb276dcd50a15e152a8ba",
    RUN172: "a18f2c03d6cb7273c36a296c8bb4f9db2e80a440bc62157c6600dff9d9aec657",
    RUN176: "6e9fa6d855e6ec168d4c651921702dab8872810ddd89f6ba3cd353bf49e0c87c",
    RUN176R: "f1f7369306235ad7d5f318b512dca94e853d96e182ff5c63ddc509534fa545c1",
    RUN177: "1b4cd64704c3137cfc98d0068900fe9068619dae700e519080b1d183639b5c2f",
    RUN178: "9a41983d86fa3fbe054d1ddb848a2ab4027284aa78210b78937d9728f7fbdaf2",
}

SOURCE_FILES = {
    "routes/fleet-assets.php": {
        "review_loci": ["49-58"],
        "role": "selected_route_source",
        "purpose": "permission group, exact selected GET route, and adjacent route context",
        "context_only": False,
    },
    "app/Http/Controllers/FleetAssets/VehicleController.php": {
        "review_loci": ["35-42", "566-822", "1132-1238"],
        "role": "selected_controller_action_and_helpers",
        "purpose": "current trips action and all controller-local trip visibility/projection helpers",
        "context_only": False,
    },
    "app/Services/Fleet/FleetTripService.php": {
        "review_loci": ["12-105"],
        "role": "canonical_trip_producer",
        "purpose": "telemetry-driven FleetTrip creation and lifecycle producer relevant to ownership review",
        "context_only": False,
    },
    "resources/js/pages/fleet-assets/trips/index.tsx": {
        "review_loci": ["94-132", "151-633", "635-745"],
        "role": "current_trip_page_consumer",
        "purpose": "current trip-index page contract and selected GET/filter consumption",
        "context_only": True,
    },
    "resources/js/components/app-sidebar.tsx": {
        "review_loci": ["1399-1408"],
        "role": "navigation_caller_context",
        "purpose": "direct Fleet Trips navigation caller only",
        "context_only": True,
    },
    "resources/js/pages/fleet-assets/dashboard.tsx": {
        "review_loci": ["699-707"],
        "role": "dashboard_caller_context",
        "purpose": "direct Trips dashboard caller only",
        "context_only": True,
    },
    "resources/js/pages/fleet-assets/vehicles/show.tsx": {
        "review_loci": ["548-556"],
        "role": "vehicle_detail_caller_context",
        "purpose": "direct vehicle-detail Trips caller only",
        "context_only": True,
    },
    "tests/Feature/FleetAssets/FleetTripIndexSitePrivacyTest.php": {
        "review_loci": ["148-459"],
        "role": "current_test_source_context",
        "purpose": "current focused GET/CSV Site-privacy source context; no execution credit",
        "context_only": True,
    },
    "docs/architecture/single-tenant-application.md": {
        "review_loci": ["1-21"],
        "role": "architecture_boundary",
        "purpose": "one-organisation multi-Site authorization boundary",
        "context_only": False,
    },
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
    generator_status = f"?? {PREFIX}/{GENERATOR}"
    output_status = f"?? {PREFIX}/{OUTPUT}"
    actual = {line for line in git("status", "--porcelain").splitlines() if line}
    expected = (
        {generator_status, output_status}
        if (AUDIT / OUTPUT).exists()
        else {generator_status}
    )
    assert actual == expected, (actual, expected)


def exact_line(relative: str, needle: str, expected_line: int) -> dict[str, Any]:
    lines = (REPO / relative).read_text(encoding="utf-8-sig").splitlines()
    matches = [
        (index, line.strip())
        for index, line in enumerate(lines, 1)
        if needle in line and index == expected_line
    ]
    assert len(matches) == 1, (relative, needle, expected_line, matches)
    number, line = matches[0]
    return {
        "source_anchor": f"{relative}:{number}",
        "source_line": line,
        "source_line_sha256": sha((line + "\n").encode("utf-8")),
    }


def source_record(relative: str, contract: dict[str, Any]) -> dict[str, Any]:
    raw = (REPO / relative).read_bytes()
    line_count = len(raw.splitlines())
    review_loci = contract.get("review_loci")
    assert isinstance(review_loci, list) and review_loci, (relative, review_loci)
    for locus in review_loci:
        assert isinstance(locus, str), (relative, locus)
        match = re.fullmatch(r"([1-9]\d*)-([1-9]\d*)", locus)
        assert match is not None, (relative, locus)
        start, end = (int(value) for value in match.groups())
        assert 1 <= start <= end <= line_count, (relative, locus, line_count)
    blob_id = git("hash-object", "--", str(REPO / relative))
    assert blob_id == git("rev-parse", f"{HEAD}:{relative}")
    return {
        "path": relative,
        "sha256": sha(raw),
        "blob_id": blob_id,
        "bytes": len(raw),
        "lines": line_count,
        "review_loci_validated": True,
        **contract,
    }


def method_slice(relative: str, method: str) -> dict[str, Any]:
    lines = (REPO / relative).read_text(encoding="utf-8-sig").splitlines()
    definition = re.compile(
        rf"^    (?:public|protected|private) function {re.escape(method)}\("
    )
    starts = [index for index, line in enumerate(lines) if definition.search(line)]
    assert len(starts) == 1, (relative, method, starts)
    start = starts[0]
    method_closes = [
        index
        for index, line in enumerate(lines[start + 1 :], start + 1)
        if line == "    }"
    ]
    assert method_closes, (relative, method)
    end = method_closes[0]
    selected = lines[start : end + 1]
    assert f"function {method}(" in selected[0]
    assert selected[-1].strip() == "}"
    text = "\n".join(selected) + "\n"
    return {
        "source_file": relative,
        "source_file_sha256": repo_sha(relative),
        "source_file_blob_id": git("rev-parse", f"{HEAD}:{relative}"),
        "method": method,
        "start_line": start + 1,
        "end_line": end + 1,
        "definition_anchor": f"{relative}:{start + 1}",
        "text": text,
        "text_sha256": sha(text.encode("utf-8")),
    }


def main() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == HEAD
    assert git("rev-parse", "HEAD^{tree}") == TREE
    assert git("rev-parse", "HEAD^") == PARENT
    for relative, expected in SUBTREES.items():
        assert git("rev-parse", f"HEAD:{relative}") == expected, relative
    assert_exact_dirty_set()

    assert GOVERNING_PROMPT.is_file()
    assert sha(GOVERNING_PROMPT.read_bytes()) == GOVERNING_PROMPT_SHA256
    assert CONTINUATION_REQUEST.is_file()
    assert sha(CONTINUATION_REQUEST.read_bytes()) == CONTINUATION_REQUEST_SHA256
    assert GOVERNING_PROMPT_SHA256 != CONTINUATION_REQUEST_SHA256

    for relative, expected in PINNED_INPUTS.items():
        assert audit_sha(relative) == expected, relative
        tracked = f"{PREFIX}/{relative}"
        assert git("rev-parse", f"HEAD:{tracked}") == git(
            "hash-object", "--", str(AUDIT / relative)
        ), relative

    queue_doc = strict_json(QUEUE)
    queue = queue_doc.get("records") or queue_doc.get("queue_records")
    assert isinstance(queue, list) and len(queue) == 507
    selected = queue[84]
    assert (
        selected["queue_id"],
        selected["source_record_id"],
        selected["candidate_feature_id"],
        selected["queue_record_sha256"],
    ) == (
        "RUN090-ROUTE-0085",
        "RUN077-ROUTE-0693",
        "CAP-FLEET-VEHICLE-REGISTER",
        "928eeec741742f8329dd7e191a71f2d5249775b6de64e6a698a72836345ca011",
    )
    assert selected["canonical_key"] == "route|RUN077-ROUTE-0693"
    assert selected["source"]["literal_uri"] == "/trips"
    assert selected["source"]["literal_route_name"] == "fleet-assets.trips.index"
    assert selected["source"]["action_expression"] == "[VehicleController::class, 'trips']"
    assert selected["source"]["statement_sha256"] == "215529de9bef35463b260cb426036bdfa28646405de99c02518acce18ff053ce"
    assert selected["review_state"] == {
        "status": "PENDING_FRESH_SEMANTIC_REVIEW",
        "allowed_outcomes": ["OWNER", "SHARED_RELATION", "EVIDENCE_GAP"],
        "ownership_credit": False,
    }
    assert selected["secondary_lane"]["relation_comparison"] == "NAME_ONLY"
    historical_resolution = selected["secondary_lane"]["backend_method_relation"]["resolution"]
    assert historical_resolution["controller_file"] == "app/Http/Controllers/FleetAssets/VehicleController.php"
    assert historical_resolution["method"] == "trips"
    assert historical_resolution["definition_line"] == 562
    assert queue[83]["canonical_key"] == "route|RUN077-ROUTE-0692"
    assert (
        queue[85]["queue_id"],
        queue[85]["source_record_id"],
        queue[85]["source"]["literal_route_name"],
        queue[85]["source"]["action_expression"],
    ) == (
        "RUN090-ROUTE-0086",
        "RUN077-ROUTE-0694",
        "fleet-assets.trips.playback",
        "[FleetTripController::class, 'show']",
    )

    with (AUDIT / MATRIX).open(encoding="utf-8-sig", newline="") as handle:
        matrix = {row["feature_id"]: row for row in csv.DictReader(handle)}
    assert len(matrix) == 340
    feature = matrix["CAP-FLEET-VEHICLE-REGISTER"]
    assert feature["module"] == "Fleet & Assets"
    assert feature["user_job"] == "Maintain vehicles and vehicle-specific state"
    assert feature["route_names"].split("; ").count("fleet-assets.trips.index") == 1
    assert feature["page_files"] == "resources/js/pages/fleet-assets/vehicles/index.tsx"
    assert "VehicleController.php:475-550" in feature["backend_anchors"]

    run170 = strict_json(RUN170)
    run170r = strict_json(RUN170R)
    run171 = strict_json(RUN171)
    run172 = strict_json(RUN172)
    run176 = strict_json(RUN176)
    run176r = strict_json(RUN176R)
    run177 = strict_json(RUN177)
    run178 = strict_json(RUN178)
    assert run170["queue_boundary"]["next_unresolved_index"] == 84
    assert run170["queue_boundary"]["next_unresolved_queue_id"] == "RUN090-ROUTE-0085"
    assert run170["queue_boundary"]["reviewed_key_count"] == 119
    assert run170r["decision"]["verdict"] == "GO"
    assert run171["audit_completion_test_met"] is False
    assert run172["audit_completion_test_met"] is False
    assert run176["run_id"] == "RUN-176-FLEET-TRIP-INDEX-SITE-PRIVACY-01-REMEDIATION-WAVE-33"
    assert "ZERO_STATIC_OWNERSHIP_FINAL_FINDING_OR_COMPLETION_CREDIT" in run176["status"]
    assert run176r["decision"]["verdict"] == "GO"
    assert "ZERO_STATIC_OWNERSHIP_PUBLICATION_FINAL_FINDING_OR_COMPLETION_CREDIT" in run176r["status"]
    assert run177["reporting_transition"]["finding_id"] == "FLEET-TRIP-INDEX-SITE-PRIVACY-01"
    assert run177["reporting_transition"]["status_after"] == "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
    assert run177["noninheritance_boundary"]["static_route_feature_ownership"] is False
    assert run177["noninheritance_boundary"]["queue_advance"] is False
    assert run178["run_id"] == "RUN-178-AUDIT-DASHBOARD-VERIFICATION-WAVE-33"
    assert run178["pins"]["governing_prompt_sha256"] == GOVERNING_PROMPT_SHA256
    assert run178["pins"]["continuation_request_sha256"] == CONTINUATION_REQUEST_SHA256
    assert run178["pins"]["continuation_request_is_not_governing_prompt"] is True
    assert run178["static_ownership_boundary"]["next_zero_based_index"] == 84
    assert run178["static_ownership_boundary"]["next_queue_id"] == "RUN090-ROUTE-0085"
    assert run178["static_ownership_boundary"]["queue_reviewed"] == 119
    assert run178["static_ownership_boundary"]["queue_pending"] == 388
    assert run178["static_ownership_boundary"]["ownership_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
    assert run178["static_ownership_boundary"]["correctness_credit"] is False
    assert run178["audit_completion_test_met"] is False

    exact_loci = {
        "permission_group": exact_line("routes/fleet-assets.php", "Route::middleware('permission:fleet.viewAny')->group", 50),
        "selected_route": exact_line("routes/fleet-assets.php", "Route::get('/trips', [VehicleController::class, 'trips'])", 54),
        "controller_action": exact_line("app/Http/Controllers/FleetAssets/VehicleController.php", "public function trips(Request $request)", 566),
        "visible_sites": exact_line("app/Http/Controllers/FleetAssets/VehicleController.php", "$visibleSiteIds = $this->siteAccess->accessibleSiteIds", 569),
        "visible_vehicles": exact_line("app/Http/Controllers/FleetAssets/VehicleController.php", "$visibleVehicles = $this->visibleTripVehiclesQuery", 570),
        "selected_filter_concealment": exact_line("app/Http/Controllers/FleetAssets/VehicleController.php", "$selectedVehicleId > 0 && (clone $visibleVehicles)->whereKey", 587),
        "csv_projection": exact_line("app/Http/Controllers/FleetAssets/VehicleController.php", "$exportQuery = $this->withTripIndexRelations", 616),
        "pagination_projection": exact_line("app/Http/Controllers/FleetAssets/VehicleController.php", "$trips = $query->paginate(25)->withQueryString();", 758),
        "render": exact_line("app/Http/Controllers/FleetAssets/VehicleController.php", "return Inertia::render('fleet-assets/trips/index'", 764),
        "visible_vehicle_helper": exact_line("app/Http/Controllers/FleetAssets/VehicleController.php", "private function visibleTripVehiclesQuery", 1133),
        "asset_site_helper": exact_line("app/Http/Controllers/FleetAssets/VehicleController.php", "private function applyTripAssetSiteScope", 1139),
        "relation_helper": exact_line("app/Http/Controllers/FleetAssets/VehicleController.php", "private function withTripIndexRelations", 1197),
        "driver_site_helper": exact_line("app/Http/Controllers/FleetAssets/VehicleController.php", "private function applyHistoricalTripDriverSiteScope", 1210),
        "driver_projection_helper": exact_line("app/Http/Controllers/FleetAssets/VehicleController.php", "private function visibleTripDriver", 1229),
        "canonical_trip_producer": exact_line("app/Services/Fleet/FleetTripService.php", "public function handleTelemetry(", 18),
        "canonical_trip_creation": exact_line("app/Services/Fleet/FleetTripService.php", "$openTrip = FleetTrip::create([", 44),
        "current_page": exact_line("resources/js/pages/fleet-assets/trips/index.tsx", "export default function TripsIndex", 151),
        "current_page_request": exact_line("resources/js/pages/fleet-assets/trips/index.tsx", "'/fleet-assets/trips',", 241),
        "sidebar_caller": exact_line("resources/js/components/app-sidebar.tsx", "href: '/fleet-assets/trips',", 1405),
        "dashboard_caller": exact_line("resources/js/pages/fleet-assets/dashboard.tsx", 'href="/fleet-assets/trips"', 703),
        "vehicle_detail_caller": exact_line("resources/js/pages/fleet-assets/vehicles/show.tsx", "<Link href=", 552),
        "focused_test": exact_line("tests/Feature/FleetAssets/FleetTripIndexSitePrivacyTest.php", "test_trip_index_scopes_rows_nested_identity_filters_and_every_aggregate_to_approved_sites", 148),
        "architecture": exact_line("docs/architecture/single-tenant-application.md", "single-tenant application for one operating organisation", 3),
    }
    assert exact_loci["selected_route"]["source_line"] == selected["source"]["statement_excerpt"]

    controller_slices = {
        method: method_slice("app/Http/Controllers/FleetAssets/VehicleController.php", method)
        for method in (
            "trips",
            "visibleTripVehiclesQuery",
            "applyTripAssetSiteScope",
            "withTripIndexRelations",
            "applyHistoricalTripDriverSiteScope",
            "visibleTripDriver",
        )
    }
    assert controller_slices["trips"]["start_line"] == 566
    assert controller_slices["trips"]["end_line"] == 822
    service_slice = method_slice("app/Services/Fleet/FleetTripService.php", "handleTelemetry")
    assert service_slice["start_line"] == 18
    assert service_slice["end_line"] == 105

    source_packet = [source_record(relative, contract) for relative, contract in SOURCE_FILES.items()]
    current_route_sha = repo_sha("routes/fleet-assets.php")
    current_route_blob = git("rev-parse", f"{HEAD}:routes/fleet-assets.php")
    current_controller_sha = repo_sha("app/Http/Controllers/FleetAssets/VehicleController.php")
    current_controller_blob = git("rev-parse", f"{HEAD}:app/Http/Controllers/FleetAssets/VehicleController.php")
    generator_raw = (AUDIT / GENERATOR).read_bytes()

    receipt: dict[str, Any] = {
        "schema_version": "run-179-outcome-neutral-fleet-trip-index-route-action-cohort-wave-34-v1",
        "run_id": "RUN-179-OUTCOME-NEUTRAL-FLEET-TRIP-INDEX-ROUTE-ACTION-COHORT-WAVE-34",
        "status": "OUTCOME_NEUTRAL_CURRENT_MAIN_SOURCE_PACKET_READY_TWO_INDEPENDENT_REVIEWS_REQUIRED_ZERO_OWNERSHIP_CORRECTNESS_REMEDIATION_OR_COMPLETION_CREDIT",
        "generated_on": "2026-08-30",
        "architecture_rule": "One operating organisation across multiple Sites. Exact permissions, approved Sites, canonical record ownership, direct-object concealment and privacy are the boundaries; no tenant design or tenant-isolation credit.",
        "pins": {
            "checkpoint_commit": HEAD,
            "checkpoint_tree": TREE,
            "checkpoint_parent": PARENT,
            "application_commit": HEAD,
            "application_tree": TREE,
            "subtrees": SUBTREES,
            "governing_prompt": {
                "path": str(GOVERNING_PROMPT),
                "sha256": GOVERNING_PROMPT_SHA256,
                "role": "GOVERNING_AUDIT_PROMPT",
            },
            "continuation_request": {
                "path": str(CONTINUATION_REQUEST),
                "sha256": CONTINUATION_REQUEST_SHA256,
                "role": "CONTINUATION_REQUEST_ONLY",
                "is_governing_prompt": False,
            },
            "generator": f"{PREFIX}/{GENERATOR}",
            "generator_sha256": sha(generator_raw),
            "generator_blob_id": git("hash-object", "--", str(AUDIT / GENERATOR)),
            "inputs": PINNED_INPUTS,
            "run_178_receipt": {
                "path": RUN178,
                "sha256": PINNED_INPUTS[RUN178],
                "git_blob_id": git("rev-parse", f"{HEAD}:{PREFIX}/{RUN178}"),
                "receipt_self_seal_sha256": run178["receipt_self_seal_sha256"],
            },
        },
        "selection_contract": {
            "source": QUEUE,
            "selected_queue_indices_zero_based": [84],
            "selected_queue_ids": ["RUN090-ROUTE-0085"],
            "selected_route_record_ids": ["RUN077-ROUTE-0693"],
            "selected_feature_ids": ["CAP-FLEET-VEHICLE-REGISTER"],
            "selected_route_names": ["fleet-assets.trips.index"],
            "selected_actions": ["App\\Http\\Controllers\\FleetAssets\\VehicleController::trips"],
            "selection_outcome_neutral": True,
            "ownership_decisions_authored": 0,
            "correctness_decisions_authored": 0,
            "page_candidates_selected": 0,
        },
        "records": [selected],
        "current_source_reconciliation": {
            "immutable_run090_route_source": {
                "source_anchor": selected["source"]["source_anchor"],
                "route_file_sha256": selected["source"]["route_file_sha256"],
                "route_file_blob_id": selected["source"]["route_file_blob_id"],
                "statement_sha256": selected["source"]["statement_sha256"],
                "statement_excerpt": selected["source"]["statement_excerpt"],
            },
            "immutable_run090_controller_resolution": {
                "controller_file_sha256": historical_resolution["controller_file_sha256"],
                "definition_line": historical_resolution["definition_line"],
                "definition_anchor": historical_resolution["definition_anchor"],
            },
            "current_main_route_source": {
                "source_anchor": exact_loci["selected_route"]["source_anchor"],
                "route_file_sha256": current_route_sha,
                "route_file_blob_id": current_route_blob,
                "source_line_sha256": exact_loci["selected_route"]["source_line_sha256"],
                "statement_excerpt": exact_loci["selected_route"]["source_line"],
            },
            "current_main_controller_resolution": {
                "controller_file_sha256": current_controller_sha,
                "controller_file_blob_id": current_controller_blob,
                "definition_line": controller_slices["trips"]["start_line"],
                "definition_anchor": controller_slices["trips"]["definition_anchor"],
                "method_slice_sha256": controller_slices["trips"]["text_sha256"],
            },
            "route_file_drifted_since_run090": selected["source"]["route_file_sha256"] != current_route_sha,
            "controller_file_drifted_since_run090": historical_resolution["controller_file_sha256"] != current_controller_sha,
            "controller_definition_line_drifted_from_562_to_566": True,
            "exact_route_statement_text_preserved": True,
            "exact_action_expression_preserved": True,
            "historical_hashes_presented_as_current": False,
            "historical_correctness_or_ownership_inherited": False,
        },
        "queue_boundary": {
            "reviewed_queue_surface_rows_before_run179": 119,
            "pending_queue_surface_rows_before_run179": 388,
            "current_next_unresolved_index": 84,
            "current_next_unresolved_queue_id": "RUN090-ROUTE-0085",
            "preceding_index_83_selected_or_recredited": False,
            "post_selection_next_index_if_owner": 85,
            "post_selection_next_queue_id_if_owner": "RUN090-ROUTE-0086",
            "post_selection_next_route_record_id_if_owner": "RUN077-ROUTE-0694",
            "queue_advance_authorized": False,
        },
        "source_review_packet": {
            "required_source_files": source_packet,
            "required_source_file_count": len(source_packet),
            "exact_current_loci": exact_loci,
            "selected_controller_action_and_helper_slices": controller_slices,
            "canonical_trip_producer_slice": service_slice,
            "current_trip_page_is_context_only": True,
            "caller_files_are_context_only": True,
            "focused_test_file_is_source_context_only": True,
            "test_execution_inherited": False,
            "page_or_caller_ownership_inherited": False,
            "source_review_complete": False,
            "source_packet_completeness_claimed": False,
            "packet_sha256": canonical_hash(source_packet),
        },
        "remediation_and_history_noninheritance": {
            "finding_id": "FLEET-TRIP-INDEX-SITE-PRIVACY-01",
            "current_reporting_status": "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING",
            "run_176_sha256": PINNED_INPUTS[RUN176],
            "run_176r_sha256": PINNED_INPUTS[RUN176R],
            "run_177_sha256": PINNED_INPUTS[RUN177],
            "run_178_sha256": PINNED_INPUTS[RUN178],
            "remediation_files_or_runtime_recredited": False,
            "red_reproduction_recredited": False,
            "isolated_or_post_merge_green_recredited": False,
            "supporting_regressions_recredited": False,
            "historical_remediated_reporting_recredited": False,
            "static_route_ownership_inherited_from_remediation": False,
            "controller_action_bridge_inherited_from_remediation": False,
            "correctness_inherited_from_static_identity": False,
            "final_finding_inherited": False,
        },
        "provisional_review_questions": [
            {
                "question_id": "RUN179-Q-OWNERSHIP-IDENTITY",
                "question": "Does the exact GET action directly implement the vehicle-specific state user job, or is SHARED_RELATION or EVIDENCE_GAP required?",
                "resolved": False,
                "finding": False,
            },
            {
                "question_id": "RUN179-Q-PRODUCER-RELATION",
                "question": "Is the canonical FleetTripService producer ownership-material to this read action, and does its relation alter direct versus shared ownership?",
                "resolved": False,
                "finding": False,
            },
            {
                "question_id": "RUN179-Q-PAGE-CALLER-RELATION",
                "question": "Do the current page and callers remain consumption/navigation context rather than co-owners of the exact route action?",
                "resolved": False,
                "finding": False,
            },
            {
                "question_id": "RUN179-Q-HISTORY-SEPARATION",
                "question": "Can reviewers preserve the already-remediated Site-privacy history without importing its tests, correctness, or reporting disposition into static ownership?",
                "resolved": False,
                "finding": False,
            },
        ],
        "fresh_review_contract": {
            "status": "PENDING",
            "required_independent_reviews": 2,
            "required_reviewers": [
                "fresh semantic source reviewer independent of this producer",
                "different independent exact-hash reviewer before any integration",
            ],
            "allowed_outcomes": ["OWNER", "SHARED_RELATION", "EVIDENCE_GAP"],
            "ownership_integration_authorized": False,
            "reviewers_must_separate_ownership_from_correctness": True,
            "reviewers_must_not_treat_questions_as_findings": True,
            "reviewers_must_reconcile_current_lines_and_hashes": True,
            "reviewers_must_preserve_remediation_noninheritance": True,
        },
        "stop_rules": [
            "Stop on any branch, commit, tree, prompt-role, input, source blob, line, queue identity, or dirty-set mismatch.",
            "No route/name/controller/page/caller/test/service containment candidate is an ownership decision.",
            "No prior queue outcome, remediation result, test execution, correctness result, reporting state, page, caller, service, model, or adjacent route identity is inherited.",
            "No correctness observation becomes a finding in this outcome-neutral packet.",
            "No runtime, database, browser, build, benchmark, NCM, publication, completion, Gate 4, or audit credit.",
        ],
        "counts": {
            "selected_queue_rows": 1,
            "selected_route_rows": 1,
            "selected_page_rows": 0,
            "ownership_decisions": 0,
            "correctness_decisions": 0,
            "required_source_files": len(source_packet),
            "controller_action_slices": 1,
            "controller_helper_slices": len(controller_slices) - 1,
            "canonical_producer_slices": 1,
            "provisional_review_questions": 4,
            "required_independent_reviews": 2,
        },
        "credit_boundary": {
            "outcome_neutral_source_packet": True,
            **{
                key: False
                for key in (
                    "static_source_feature_ownership",
                    "static_route_feature_ownership",
                    "static_page_feature_ownership",
                    "static_controller_action_bridge",
                    "frontend_caller_ownership",
                    "canonical_trip_producer_ownership",
                    "canonical_object_ownership_correctness",
                    "approved_site_scope_correctness",
                    "permission_correctness",
                    "privacy_correctness",
                    "direct_object_concealment_correctness",
                    "query_projection_correctness",
                    "runtime",
                    "database",
                    "build",
                    "application_browser",
                    "responsive_application",
                    "executed_tests",
                    "remediation",
                    "benchmark",
                    "final_no_match_or_NCM",
                    "final_finding",
                    "feature_completion",
                    "pass",
                    "release",
                    "publication",
                    "completion",
                    "gate_4",
                    "audit_complete",
                )
            },
        },
        "completion_boundary": {
            key: False
            for key in (
                "framework_route_reachability_complete",
                "semantic_assurance_complete",
                "execution_complete",
                "benchmark_complete",
                "pass_8_complete",
                "final_reconciliation_complete",
                "no_live_agent_gate_complete",
                "full_crosswalk_complete",
                "gate_4_complete",
                "audit_complete",
            )
        },
        "artifact_completion_test_met": False,
        "source_review_complete": False,
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
                "selected": 1,
                "source_files": len(source_packet),
                "required_independent_reviews": 2,
                "generator_sha256": audit_sha(GENERATOR),
                "receipt_sha256": audit_sha(OUTPUT),
                "self_seal": seal["sha256"],
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
