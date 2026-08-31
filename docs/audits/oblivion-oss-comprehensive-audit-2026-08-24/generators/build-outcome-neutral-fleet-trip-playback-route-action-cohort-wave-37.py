#!/usr/bin/env python3
"""Freeze RUN189 queue index 85 as an outcome-neutral current-source packet."""
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
GENERATOR = "generators/build-outcome-neutral-fleet-trip-playback-route-action-cohort-wave-37.py"
OUTPUT = "evidence/source/root-run-189-outcome-neutral-fleet-trip-playback-route-action-cohort-wave-37.json"

HEAD = "d991b2898b70409ce7c019abe9ddbd8394e0b595"
TREE = "46074d7ec2b2a75b6fc4c3fa67187d5b908de79a"
FIRST_PARENT = "10943780e7abea7a9d3b155bcd20154daf9bcc2d"
SECOND_PARENT = "d6e2b22cf765f211763f88213b7145aa5adfde33"
SUBTREES = {
    "app": "4f40e299ebb48515959572f9378d66eadb742514",
    "routes": "b62a85f59ba5f45a54fd666b3199a65453034272",
    "resources/js": "8a851516cdb76ded362fb5912e3e930e45c8df86",
    "resources/js/pages": "8ad1ecc5817310f2f45c64733ca72d771c798a2f",
    "tests": "7f1c9ae7d264be9152cc58c670ed83ace78ba5fd",
    "database": "d7ba5adb39f5dc79427a3d6946f719b2be3f41dc",
    "bootstrap": "df6189abe5ab5343d88674c199c4ce46e6152a57",
    "docs/architecture": "3444047114f5f446954b032dedc4e0c7892180bd",
    "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24": "0a9a5a0621df23157efd4c562c25df852180341c",
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
RUN179 = "evidence/source/root-run-179-outcome-neutral-fleet-trip-index-route-action-cohort-wave-34.json"
RUN179R = "evidence/source/raw-run-179r-independent-outcome-neutral-fleet-trip-index-route-action-review-wave-34.json"
RUN180 = "evidence/source/current-run-180-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-wave-34.json"
RUN180R = "evidence/source/current-run-180r-independent-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-review-wave-34.json"
RUN181 = "evidence/source/current-run-181-reviewed-fleet-trip-index-route-action-reporting-wave-34.json"
RUN182 = "evidence/browser/current-audit-dashboard-verification-run-182-wave-34.json"
RUN183 = "evidence/runtime/current-run-183-fleet-trip-playback-site-privacy-remediation-wave-35.json"
RUN183R = "evidence/runtime/current-run-183r-independent-fleet-trip-playback-site-privacy-remediation-review-wave-35.json"
RUN184 = "evidence/source/current-run-184-fleet-trip-playback-site-privacy-remediation-reporting-wave-35.json"
RUN185 = "evidence/browser/current-audit-dashboard-verification-run-185-wave-35.json"
RUN187 = "evidence/source/current-run-187-monitoring-metric-replay-dedupe-remediation-reporting-wave-36.json"
RUN188 = "evidence/browser/current-audit-dashboard-verification-run-188-wave-36.json"
FINDINGS = "findings.json"

PINNED_INPUTS = {
    QUEUE: "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    MATRIX: "3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0",
    TASK: "9dd6e901b7d4ef3f688a246f069c621180b8ebdf72a1ddd6ee30e6dd9f6742bd",
    RUN179: "5505cf17bb68d3e534116ea9d33e501e0222714b6e3779d0ec6b70f819cc3b0a",
    RUN179R: "67c5b09cbb26c95042bd7ba487c2a2c92a75d14363952ca35e9b72ee55e36d62",
    RUN180: "49b0bd12abbd4dd2b9ce0dbe9b6fd60ab79eea92861f6339407fbd05f0b7c925",
    RUN180R: "c6038caa557277124cb58056a2882ce41d1f2ee402f91effb0e6bfab6fe95d96",
    RUN181: "c1db8b498b7344c2ab28f5c6373caaa8f2ac4a1d764e6129fb49c415234794a8",
    RUN182: "d3dc3ef6e842f0b5df74b27948ac6ef8abfda205516f6ac9b6a5d9c9858cd81e",
    RUN183: "7bb1b1013cf67344c48e5a8b6e551bf3c769695e0384c2b333fb47286e53310a",
    RUN183R: "170245898590f6429a171bbd8a41455f096b5b43340b840294735fdbc5522640",
    RUN184: "c01d56df5512183ac8363c58ea73af4abf504ef3bc956967b43b15929d5e84e0",
    RUN185: "e6965bba3f25b80e6ce70aa3656802956bed935d79aaf46576e1420f0c65e07c",
    RUN187: "e84d36fee04b9d39cea9da1d247d92394abf12df4452ffc5d672b9d5cd375412",
    RUN188: "80e54a76673af5aa8fc00e0738c7e7ee219f17d6bb22d2646e37c1cbd2081a56",
    FINDINGS: "9c4aae028a358f0d1cb005fa31650ab7c696fb71731fb6961ccc4962f2cac5c9",
}

SOURCE_FILES = {
    "routes/fleet-assets.php": {
        "review_loci": ["49-58"],
        "role": "selected_route_source",
        "purpose": "permission group, exact selected page route and sibling data route context",
        "context_only": False,
    },
    "app/Http/Controllers/Fleet/FleetTripController.php": {
        "review_loci": ["17-119", "178-280"],
        "role": "selected_controller_action_and_visibility_helpers",
        "purpose": "show action, sibling playback context and controller-local Site/driver visibility helpers",
        "context_only": False,
    },
    "app/Services/Fleet/FleetTripService.php": {
        "review_loci": ["12-105"],
        "role": "canonical_trip_producer_relation",
        "purpose": "telemetry-driven FleetTrip creation and lifecycle relation question only",
        "context_only": True,
    },
    "resources/js/pages/fleet-assets/trips/playback.tsx": {
        "review_loci": ["25-390"],
        "role": "dedicated_page_consumer_context",
        "purpose": "selected show response consumer; sibling data and write calls remain context only",
        "context_only": True,
    },
    "resources/js/pages/fleet-assets/trips/index.tsx": {
        "review_loci": ["706-719"],
        "role": "direct_page_caller_context",
        "purpose": "direct link to selected playback page only",
        "context_only": True,
    },
    "routes/fleet.php": {
        "review_loci": ["28-35"],
        "role": "legacy_redirect_context",
        "purpose": "legacy page redirect to selected route and separate data redirect only",
        "context_only": True,
    },
    "tests/Feature/FleetAssets/FleetTripPlaybackSitePrivacyTest.php": {
        "review_loci": ["141-552"],
        "role": "remediation_test_source_context",
        "purpose": "current page/data privacy test source only; no execution or ownership credit",
        "context_only": True,
    },
    "tests/Feature/Fleet/FleetManagementTest.php": {
        "review_loci": ["65-143"],
        "role": "route_contract_test_source_context",
        "purpose": "legacy redirects and selected page/data route source context only",
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
    assert git("rev-parse", "HEAD^1") == FIRST_PARENT
    assert git("rev-parse", "HEAD^2") == SECOND_PARENT
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
    selected = queue[85]
    assert (
        selected["queue_id"],
        selected["source_record_id"],
        selected["candidate_feature_id"],
        selected["queue_record_sha256"],
    ) == (
        "RUN090-ROUTE-0086",
        "RUN077-ROUTE-0694",
        "CAP-FLEET-VEHICLE-REGISTER",
        "f9df043e4557240020de213961c847fb56b8cd0e2d9b9144ec0b7a877ff84943",
    )
    assert selected["canonical_key"] == "route|RUN077-ROUTE-0694"
    assert selected["source"]["literal_uri"] == "/trips/{trip}/playback"
    assert selected["source"]["literal_route_name"] == "fleet-assets.trips.playback"
    assert selected["source"]["action_expression"] == "[FleetTripController::class, 'show']"
    assert selected["source"]["statement_sha256"] == "a6194d98789884a0fce35263cfaaabd785d72b2965ae1dd6baa616d749f0615d"
    assert selected["review_state"] == {
        "status": "PENDING_FRESH_SEMANTIC_REVIEW",
        "allowed_outcomes": ["OWNER", "SHARED_RELATION", "EVIDENCE_GAP"],
        "ownership_credit": False,
    }
    historical_resolution = selected["secondary_lane"]["backend_method_relation"]["resolution"]
    assert historical_resolution["controller_file"] == "app/Http/Controllers/Fleet/FleetTripController.php"
    assert historical_resolution["method"] == "show"
    assert historical_resolution["definition_line"] == 15
    assert queue[84]["queue_id"] == "RUN090-ROUTE-0085"
    assert (
        queue[86]["queue_id"],
        queue[86]["source_record_id"],
        queue[86]["source"]["literal_route_name"],
        queue[86]["source"]["action_expression"],
    ) == (
        "RUN090-ROUTE-0087",
        "RUN077-ROUTE-0695",
        "fleet-assets.trips.playback.data",
        "[FleetTripController::class, 'playback']",
    )

    with (AUDIT / MATRIX).open(encoding="utf-8-sig", newline="") as handle:
        matrix = {row["feature_id"]: row for row in csv.DictReader(handle)}
    assert len(matrix) == 340
    feature = matrix["CAP-FLEET-VEHICLE-REGISTER"]
    assert feature["module"] == "Fleet & Assets"
    assert feature["user_job"] == "Maintain vehicles and vehicle-specific state"
    assert feature["route_names"].split("; ").count("fleet-assets.trips.playback") == 1
    assert feature["page_files"] == "resources/js/pages/fleet-assets/vehicles/index.tsx"
    assert "FleetAssets/VehicleController.php" in feature["backend_anchors"]
    task_text = (AUDIT / TASK).read_text(encoding="utf-8")
    assert "Matrix source owner, not assumed human actor: `VehicleController`" in task_text
    assert "Representative actor: `NOT_ESTABLISHED_CURRENT_AUDIT`" in task_text

    run180 = strict_json(RUN180)
    run180r = strict_json(RUN180R)
    run181 = strict_json(RUN181)
    run182 = strict_json(RUN182)
    run183 = strict_json(RUN183)
    run183r = strict_json(RUN183R)
    run184 = strict_json(RUN184)
    run185 = strict_json(RUN185)
    run187 = strict_json(RUN187)
    run188 = strict_json(RUN188)
    assert run180["queue_boundary"]["next_unresolved_index"] == 85
    assert run180["queue_boundary"]["next_unresolved_queue_id"] == "RUN090-ROUTE-0086"
    assert run180["queue_boundary"]["reviewed_key_count"] == 120
    assert run180r["decision"]["verdict"].startswith("GO_")
    assert run181["queue_boundary"]["next_unresolved_index"] == 85
    assert run181["audit_completion_test_met"] is False
    for dashboard in (run182, run185, run188):
        boundary = dashboard["static_ownership_boundary"]
        assert boundary["owner_records"] == 666
        assert boundary["route_owners"] == 309
        assert boundary["page_owners"] == 357
        assert boundary["action_bridges"] == 97
        assert boundary["queue_reviewed"] == 120
        assert boundary["queue_pending"] == 387
        assert boundary["next_zero_based_index"] == 85
        assert boundary["next_queue_id"] == "RUN090-ROUTE-0086"
        assert boundary["correctness_credit"] is False
        assert dashboard["audit_completion_test_met"] is False
    assert run183["run_id"] == "RUN-183-FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01-REMEDIATION-WAVE-35"
    assert "ZERO_STATIC_OWNERSHIP_OR_COMPLETION_CREDIT" in run183["status"]
    assert run183r["decision"]["verdict"] == "GO"
    assert run184["reporting_transition"]["status_after"] == "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
    assert run185["static_ownership_boundary"]["next_queue_id"] == "RUN090-ROUTE-0086"
    assert run187["reporting_transition"]["status_after"] == "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
    assert run188["run_id"] == "RUN-188-AUDIT-DASHBOARD-VERIFICATION-WAVE-36"

    exact_loci = {
        "permission_group": exact_line("routes/fleet-assets.php", "Route::middleware('permission:fleet.viewAny')->group", 50),
        "selected_route": exact_line("routes/fleet-assets.php", "Route::get('/trips/{trip}/playback', [FleetTripController::class, 'show'])", 55),
        "sibling_data_route": exact_line("routes/fleet-assets.php", "Route::get('/trips/{trip}/playback/data', [FleetTripController::class, 'playback'])", 56),
        "selected_action": exact_line("app/Http/Controllers/Fleet/FleetTripController.php", "public function show(Request $request, int $trip)", 24),
        "visible_sites": exact_line("app/Http/Controllers/Fleet/FleetTripController.php", "$visibleSiteIds = $this->siteAccess->accessibleSiteIds", 29),
        "visible_trip": exact_line("app/Http/Controllers/Fleet/FleetTripController.php", "$trip = $this->visibleTripsQuery($visibleSiteIds)->findOrFail($trip);", 30),
        "render": exact_line("app/Http/Controllers/Fleet/FleetTripController.php", "return Inertia::render('fleet-assets/trips/playback'", 48),
        "sibling_action": exact_line("app/Http/Controllers/Fleet/FleetTripController.php", "public function playback(Request $request, int $trip)", 88),
        "trip_visibility_helper": exact_line("app/Http/Controllers/Fleet/FleetTripController.php", "private function visibleTripsQuery", 179),
        "asset_site_helper": exact_line("app/Http/Controllers/Fleet/FleetTripController.php", "private function applyTripAssetSiteScope", 188),
        "driver_visibility_helper": exact_line("app/Http/Controllers/Fleet/FleetTripController.php", "private function visibleDriverSessionsQuery", 253),
        "driver_site_helper": exact_line("app/Http/Controllers/Fleet/FleetTripController.php", "private function applyHistoricalTripDriverSiteScope", 263),
        "canonical_trip_producer": exact_line("app/Services/Fleet/FleetTripService.php", "public function handleTelemetry(", 18),
        "canonical_trip_creation": exact_line("app/Services/Fleet/FleetTripService.php", "$openTrip = FleetTrip::create([", 44),
        "page_component": exact_line("resources/js/pages/fleet-assets/trips/playback.tsx", "export default function FleetTripPlayback", 56),
        "page_data_fetch": exact_line("resources/js/pages/fleet-assets/trips/playback.tsx", "fetch(`/fleet-assets/trips/${trip.id}/playback/data`)", 70),
        "direct_page_caller": exact_line("resources/js/pages/fleet-assets/trips/index.tsx", "href={`/fleet-assets/trips/${trip.id}/playback`}", 715),
        "legacy_page_redirect": exact_line("routes/fleet.php", "redirect(\"/fleet-assets/trips/{$trip}/playback\", 301)", 29),
        "legacy_data_redirect": exact_line("routes/fleet.php", "redirect(\"/fleet-assets/trips/{$trip}/playback/data\", 301)", 33),
        "privacy_test_context": exact_line("tests/Feature/FleetAssets/FleetTripPlaybackSitePrivacyTest.php", "test_site_limited_viewer_cannot_open_foreign_trip_page", 141),
        "route_contract_test_context": exact_line("tests/Feature/Fleet/FleetManagementTest.php", "test_trip_playback_page_accessible_by_admin", 98),
        "architecture": exact_line("docs/architecture/single-tenant-application.md", "single-tenant application for one operating organisation", 3),
    }
    assert exact_loci["selected_route"]["source_line"] == selected["source"]["statement_excerpt"]

    controller_slices = {
        method: method_slice("app/Http/Controllers/Fleet/FleetTripController.php", method)
        for method in (
            "show",
            "playback",
            "visibleTripsQuery",
            "applyTripAssetSiteScope",
            "applyOperationalSiteScope",
            "visibleDriverSessionsQuery",
            "applyHistoricalTripDriverSiteScope",
        )
    }
    assert controller_slices["show"]["start_line"] == 24
    assert controller_slices["show"]["end_line"] == 86
    assert controller_slices["playback"]["start_line"] == 88
    assert controller_slices["playback"]["end_line"] == 119
    service_slice = method_slice("app/Services/Fleet/FleetTripService.php", "handleTelemetry")
    assert service_slice["start_line"] == 18
    assert service_slice["end_line"] == 105

    source_packet = [source_record(relative, contract) for relative, contract in SOURCE_FILES.items()]
    current_route_sha = repo_sha("routes/fleet-assets.php")
    current_controller_sha = repo_sha("app/Http/Controllers/Fleet/FleetTripController.php")
    generator_raw = (AUDIT / GENERATOR).read_bytes()

    receipt: dict[str, Any] = {
        "schema_version": "run-189-outcome-neutral-fleet-trip-playback-route-action-cohort-wave-37-v1",
        "run_id": "RUN-189-OUTCOME-NEUTRAL-FLEET-TRIP-PLAYBACK-ROUTE-ACTION-COHORT-WAVE-37",
        "status": "OUTCOME_NEUTRAL_CURRENT_MAIN_SOURCE_PACKET_READY_TWO_FRESH_SEMANTIC_REVIEWS_REQUIRED_ZERO_OWNERSHIP_CORRECTNESS_REMEDIATION_OR_COMPLETION_CREDIT",
        "generated_on": "2026-08-31",
        "architecture_rule": "One operating organisation across multiple Sites. Exact permissions, approved Sites, canonical record ownership, direct-object concealment and privacy are the boundaries; no tenant design or tenant-isolation credit.",
        "pins": {
            "checkpoint_commit": HEAD,
            "checkpoint_tree": TREE,
            "checkpoint_parents": [FIRST_PARENT, SECOND_PARENT],
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
            "run_188_receipt": {
                "path": RUN188,
                "sha256": PINNED_INPUTS[RUN188],
                "git_blob_id": git("rev-parse", f"{HEAD}:{PREFIX}/{RUN188}"),
                "receipt_self_seal_sha256": run188["receipt_self_seal_sha256"],
            },
        },
        "selection_contract": {
            "source": QUEUE,
            "selected_queue_indices_zero_based": [85],
            "selected_queue_ids": ["RUN090-ROUTE-0086"],
            "selected_route_record_ids": ["RUN077-ROUTE-0694"],
            "selected_feature_ids": ["CAP-FLEET-VEHICLE-REGISTER"],
            "selected_route_names": ["fleet-assets.trips.playback"],
            "selected_actions": ["App\\Http\\Controllers\\Fleet\\FleetTripController::show"],
            "selection_outcome_neutral": True,
            "ownership_decisions_authored": 0,
            "correctness_decisions_authored": 0,
            "page_candidates_selected": 0,
            "sibling_data_route_selected": False,
        },
        "records": [selected],
        "candidate_semantic_tension": {
            "candidate_feature_id": feature["feature_id"],
            "candidate_user_job": feature["user_job"],
            "candidate_page_files": feature["page_files"],
            "candidate_backend_anchors": feature["backend_anchors"],
            "selected_controller": "App\\Http\\Controllers\\Fleet\\FleetTripController",
            "selected_method": "show",
            "dedicated_page": "resources/js/pages/fleet-assets/trips/playback.tsx",
            "name_only_candidate_must_not_be_treated_as_owner": True,
            "dedicated_action_or_page_must_not_be_treated_as_owner_without_semantic_review": True,
        },
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
                "route_file_blob_id": git("rev-parse", f"{HEAD}:routes/fleet-assets.php"),
                "source_line_sha256": exact_loci["selected_route"]["source_line_sha256"],
                "statement_excerpt": exact_loci["selected_route"]["source_line"],
            },
            "current_main_controller_resolution": {
                "controller_file_sha256": current_controller_sha,
                "controller_file_blob_id": git("rev-parse", f"{HEAD}:app/Http/Controllers/Fleet/FleetTripController.php"),
                "definition_line": controller_slices["show"]["start_line"],
                "definition_anchor": controller_slices["show"]["definition_anchor"],
                "method_slice_sha256": controller_slices["show"]["text_sha256"],
            },
            "route_file_drifted_since_run090": selected["source"]["route_file_sha256"] != current_route_sha,
            "controller_file_drifted_since_run090": historical_resolution["controller_file_sha256"] != current_controller_sha,
            "controller_definition_line_drifted_from_15_to_24": True,
            "exact_route_statement_text_preserved": True,
            "exact_action_expression_preserved": True,
            "historical_hashes_presented_as_current": False,
            "historical_correctness_or_ownership_inherited": False,
        },
        "queue_boundary": {
            "reviewed_queue_surface_rows_before_run189": 120,
            "pending_queue_surface_rows_before_run189": 387,
            "current_next_unresolved_index": 85,
            "current_next_unresolved_queue_id": "RUN090-ROUTE-0086",
            "preceding_index_84_selected_or_recredited": False,
            "conditional_next_index_after_later_integrated_disposition": 86,
            "conditional_next_queue_id_after_later_integrated_disposition": "RUN090-ROUTE-0087",
            "conditional_next_route_record_id_after_later_integrated_disposition": "RUN077-ROUTE-0695",
            "conditional_next_route_name_after_later_integrated_disposition": "fleet-assets.trips.playback.data",
            "queue_advance_authorized": False,
        },
        "source_review_packet": {
            "required_source_files": source_packet,
            "required_source_file_count": len(source_packet),
            "exact_current_loci": exact_loci,
            "selected_controller_action_and_context_slices": controller_slices,
            "canonical_trip_producer_slice": service_slice,
            "sibling_data_action_is_context_only": True,
            "dedicated_page_is_context_only": True,
            "caller_and_legacy_redirects_are_context_only": True,
            "test_files_are_source_context_only": True,
            "test_execution_inherited": False,
            "page_caller_sibling_or_service_ownership_inherited": False,
            "source_review_complete": False,
            "source_packet_completeness_claimed": False,
            "packet_sha256": canonical_hash(source_packet),
        },
        "remediation_and_history_noninheritance": {
            "finding_id": "FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01",
            "current_reporting_status": "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING",
            "run_183_sha256": PINNED_INPUTS[RUN183],
            "run_183r_sha256": PINNED_INPUTS[RUN183R],
            "run_184_sha256": PINNED_INPUTS[RUN184],
            "run_185_sha256": PINNED_INPUTS[RUN185],
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
        "excluded_history": {
            "older_2026_08_12_bundle_imported": False,
            "older_cap_fleet_trip_playback_identity_imported": False,
            "prior_vehicle_or_trip_owner_wave_outcome_inherited": False,
        },
        "provisional_review_questions": [
            {
                "question_id": "RUN189-Q-OWNERSHIP-IDENTITY",
                "question": "Does the exact GET show action directly implement a material slice of Maintain vehicles and vehicle-specific state, share that job, or remain an evidence gap?",
                "resolved": False,
                "finding": False,
            },
            {
                "question_id": "RUN189-Q-CANDIDATE-ANCHOR-DIVERGENCE",
                "question": "How should reviewers reconcile the name-only route candidate with frozen VehicleController and vehicle-index anchors while the current selected action and page are dedicated FleetTrip playback loci?",
                "resolved": False,
                "finding": False,
            },
            {
                "question_id": "RUN189-Q-PRODUCER-RELATION",
                "question": "Is FleetTripService merely canonical producer context, or does its relation require SHARED_RELATION for this exact read action?",
                "resolved": False,
                "finding": False,
            },
            {
                "question_id": "RUN189-Q-PAGE-CALLER-SIBLING-RELATION",
                "question": "Can reviewers keep the dedicated page, index and legacy callers, write actions and sibling data route as context without inheriting their ownership?",
                "resolved": False,
                "finding": False,
            },
            {
                "question_id": "RUN189-Q-HISTORY-SEPARATION",
                "question": "Can reviewers preserve the already-remediated playback Site-privacy history without importing its tests, correctness, runtime or reporting disposition into static ownership?",
                "resolved": False,
                "finding": False,
            },
        ],
        "fresh_review_contract": {
            "status": "PENDING",
            "required_fresh_semantic_reviews": 2,
            "required_final_exact_artifact_reviews": 2,
            "semantic_reviewers_must_be_independent_of_producer_and_each_other": True,
            "allowed_outcomes": ["OWNER_ROUTE_ACTION", "SHARED_RELATION", "EVIDENCE_GAP"],
            "conflict_rule": "Preserve dissent and stop before any overlay or queue advance unless a separately sealed reconciliation is authorized.",
            "ownership_integration_authorized": False,
            "reviewers_must_separate_ownership_from_correctness": True,
            "reviewers_must_not_treat_questions_as_findings": True,
            "reviewers_must_reconcile_current_lines_and_hashes": True,
            "reviewers_must_preserve_sibling_page_caller_service_and_remediation_noninheritance": True,
        },
        "stop_rules": [
            "Stop on any branch, commit, tree, parent, prompt-role, input, source blob, line, queue identity or dirty-set mismatch.",
            "No exact route name, controller containment, dedicated page, caller, sibling route, test or service relation is an ownership decision by itself.",
            "No prior queue outcome, remediation result, test execution, correctness result, reporting state, old bundle or adjacent route identity is inherited.",
            "No correctness observation becomes a finding in this outcome-neutral packet.",
            "No runtime, database, browser, build, benchmark, NCM, publication, completion, Gate 4 or audit credit.",
        ],
        "counts": {
            "static_owner_records_unchanged": 666,
            "static_route_owners_unchanged": 309,
            "static_page_owners_unchanged": 357,
            "static_action_bridges_unchanged": 97,
            "queue_reviewed_unchanged": 120,
            "queue_pending_unchanged": 387,
            "selected_queue_rows": 1,
            "selected_route_rows": 1,
            "selected_page_rows": 0,
            "ownership_decisions": 0,
            "correctness_decisions": 0,
            "required_source_files": len(source_packet),
            "selected_controller_action_slices": 1,
            "context_controller_slices": len(controller_slices) - 1,
            "canonical_producer_slices": 1,
            "provisional_review_questions": 5,
            "required_fresh_semantic_reviews": 2,
            "required_final_exact_artifact_reviews": 2,
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
                    "sibling_data_route_ownership",
                    "frontend_page_or_caller_ownership",
                    "canonical_trip_producer_ownership",
                    "canonical_object_ownership_correctness",
                    "approved_site_scope_correctness",
                    "permission_correctness",
                    "privacy_correctness",
                    "direct_object_concealment_correctness",
                    "framework_route_reachability",
                    "runtime",
                    "database",
                    "build",
                    "application_browser",
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
                "required_fresh_semantic_reviews": 2,
                "required_final_exact_artifact_reviews": 2,
                "generator_sha256": audit_sha(GENERATOR),
                "receipt_sha256": audit_sha(OUTPUT),
                "self_seal": seal["sha256"],
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
