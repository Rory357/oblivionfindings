#!/usr/bin/env python3
"""Materialize the bounded RUN183 Fleet trip-playback privacy receipt.

This producer records already-completed source adjudication, baseline
reproduction, isolated remediation, local integration, and post-merge
verification. It does not run PHP, touch a database, start a browser, mutate
application source, publish commits, change live reporting, or adjudicate
static route/action ownership.
"""
from __future__ import annotations

import hashlib
import json
import subprocess
from collections import Counter
from pathlib import Path
from typing import Any


SCRIPT = Path(__file__).resolve()
AUDIT = SCRIPT.parent.parent
ROOT = next(parent for parent in SCRIPT.parents if (parent / ".git").exists())
PREFIX = AUDIT.relative_to(ROOT).as_posix()
SCRIPT_REL = SCRIPT.relative_to(AUDIT).as_posix()
OUTPUT_REL = (
    "evidence/runtime/"
    "current-run-183-fleet-trip-playback-site-privacy-remediation-wave-35.json"
)
OUTPUT = AUDIT / OUTPUT_REL
REVIEW_SCRIPT_REL = (
    "generators/"
    "materialize-independent-run-183-fleet-trip-playback-site-privacy-"
    "remediation-review-wave-35.py"
)
REVIEW_OUTPUT_REL = (
    "evidence/runtime/"
    "current-run-183r-independent-fleet-trip-playback-site-privacy-"
    "remediation-review-wave-35.json"
)

RUN_ID = "RUN-183-FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01-REMEDIATION-WAVE-35"
STATUS = (
    "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING_"
    "BOUNDED_VERIFIED_NOT_PUBLISHED_LIVE_REPORTING_NOT_YET_AUTHORIZED_"
    "ZERO_STATIC_OWNERSHIP_OR_COMPLETION_CREDIT"
)
RECORD_STATUS = (
    "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
)
GOVERNING_PROMPT_SHA256 = (
    "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
)
CONTINUATION_PROMPT_SHA256 = (
    "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d"
)

BASE = "db4196ccb3a8d9f6bcb33fb40680527d09c02dac"
BASE_TREE = "68052b68b070dff799d5be1d5515ec0b8472207f"
FIX = "93e576978efae4a0112a95ed406c312f6bcadeb5"
FIX_TREE = "f265c8476773aaceecbfe90680e59b5f4c74b205"
ADVANCED_MAIN = "0537f0f0eacafbeaf635ced4883a8bdf8e49d3f6"
ADVANCED_MAIN_TREE = "5eb8c401847f2da101922aef6c100b8e03d30b9d"
MERGE = "4038cf7fe5a789ca64e436300f2cf4b94ac16db4"
MERGE_TREE = "b9757ccb9010564b8512c0ed47abfc553f38b697"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"
PATCH_ID = "12c306d28e54ff88432d18b271706473ee793871"

FINDING_ID = "FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01"
CANDIDATE_FEATURE_ID = "CAP-FLEET-VEHICLE-REGISTER"
QUEUE_ID = "RUN090-ROUTE-0086"
ROUTE_RECORD_ID = "RUN077-ROUTE-0694"
ROUTE_NAME = "fleet-assets.trips.playback"
ACTION = "FleetTripController::show"

CONTROLLER = "app/Http/Controllers/Fleet/FleetTripController.php"
TEST = "tests/Feature/FleetAssets/FleetTripPlaybackSitePrivacyTest.php"
ROUTE_FILE = "routes/fleet-assets.php"
FINDINGS = f"{PREFIX}/findings.json"
CHANGED_PATHS = {CONTROLLER: (134, 12), TEST: (670, 0)}

ADVANCED_DISJOINT_PATHS = [
    "app/Http/Controllers/Settings/ApiSettingsController.php",
    "app/Services/Integration/GovernedWebhookProbeService.php",
    "app/Services/Queclink/Listener/ConnectionState.php",
    "app/Services/Queclink/Listener/FrameRouter.php",
    "app/Services/Queclink/SerialNumberAllocator.php",
    "tests/Feature/Queclink/FrameRouterTest.php",
    "tests/Feature/Settings/ApiSettingsWebhookDestinationTest.php",
    "tests/Unit/Services/Queclink/CommandBuilderTest.php",
]

EXPECTED_BASE_CONTROLLER = {
    "path": CONTROLLER,
    "sha256": "c99a056b200bf53dafaaf92ea6349e91bd4dd16646eca83a48c49810763c1112",
    "git_blob_id": "e3c5a70f694ec1c0ca9a2634466c59a443762139",
    "bytes": 5449,
    "lines": 159,
}
EXPECTED_FIXED_CONTROLLER = {
    "path": CONTROLLER,
    "sha256": "4a5f448e230c79e4effcad358ef65a5ba9fa6b9774c43d2df87e3485b9b5ad63",
    "git_blob_id": "2373c95b30958399c8ed648915991c01a0fbc84c",
    "bytes": 10934,
    "lines": 281,
    "insertions": 134,
    "deletions": 12,
}
EXPECTED_FIXED_TEST = {
    "path": TEST,
    "sha256": "071675ba9aec303176aa00758371cbedd966e944c172e75146743d3111f1031b",
    "git_blob_id": "68eaf494014abf68924ab47eadd4cb2e8ef12e8d",
    "bytes": 24787,
    "lines": 670,
    "insertions": 670,
    "deletions": 0,
}
EXPECTED_ROUTE_FILE = {
    "path": ROUTE_FILE,
    "sha256": "4be79ba4a0957f81f3e99de8eea7f29a398f8a115957bd44af06dbbf78fe2c4c",
    "git_blob_id": "f0b2b8c199ada1d8ef8bdb41c99bfc2ac02f93d2",
    "bytes": 28332,
    "lines": 351,
}
EXPECTED_FINDINGS = {
    "path": FINDINGS,
    "sha256": "55337abfc8f2fe9fde863715e3d77649ec6dd195008281944881b02e00bb54e1",
    "git_blob_id": "bd0a13dc86ebdc88073ee3ac999b3514ac0a0490",
    "bytes": 590974,
    "lines": 10553,
}
EXPECTED_ADVANCED_RECORDS = [
    {
        "path": "app/Http/Controllers/Settings/ApiSettingsController.php",
        "sha256": "c5d6ba1e5272b80e378e0b124fac1cffd2d9db45372d8663a4c54f0420a9a979",
        "git_blob_id": "81643844551bcaafe91c15db6207f690081db57d",
        "bytes": 13891,
        "lines": 425,
    },
    {
        "path": "app/Services/Integration/GovernedWebhookProbeService.php",
        "sha256": "2af8e642a39bd7a6b04a715eba1570a040e996797131178303489aca64171d19",
        "git_blob_id": "def98bb44e8636c96f4a6a3528efc69701560ec4",
        "bytes": 4082,
        "lines": 131,
    },
    {
        "path": "app/Services/Queclink/Listener/ConnectionState.php",
        "sha256": "c564accb56a55ab5aed125cf7807cd0fe59ced0c688b94fd556fc3ccef3135c4",
        "git_blob_id": "c823d26a0e949df6c75506feb2a88cf9f886a8df",
        "bytes": 1964,
        "lines": 74,
    },
    {
        "path": "app/Services/Queclink/Listener/FrameRouter.php",
        "sha256": "c2956e5a5303bf04b45fbc23ab5f4a53408107903c3cb3b8022e9fcbd7f49142",
        "git_blob_id": "27589b1db7ba8121e1ba3b75e92defd1a605388e",
        "bytes": 24393,
        "lines": 601,
    },
    {
        "path": "app/Services/Queclink/SerialNumberAllocator.php",
        "sha256": "9e8c34f5342e31eadc4d15077a26d6c4676c06891514492a0e421e6e42617e1d",
        "git_blob_id": "b9fdd61d6aa7ce7c80a951d173364331873c7e51",
        "bytes": 1863,
        "lines": 63,
    },
    {
        "path": "tests/Feature/Queclink/FrameRouterTest.php",
        "sha256": "30b8ab02488fb9b39896ff12e1586f4ee90f0215a67034edd7cddd45478e16e2",
        "git_blob_id": "d5106b77909498cd9273110830adebf292e06673",
        "bytes": 41468,
        "lines": 1008,
    },
    {
        "path": "tests/Feature/Settings/ApiSettingsWebhookDestinationTest.php",
        "sha256": "bfdb3163e358615be76835b1ad9135db12e2021763362c7a02be5dceb5ab894e",
        "git_blob_id": "adabf280aae316159c6d6947fb846180a9ed6042",
        "bytes": 7040,
        "lines": 207,
    },
    {
        "path": "tests/Unit/Services/Queclink/CommandBuilderTest.php",
        "sha256": "3069901ece2afde4b8a664cefc00e1f6996bcd4f0ef494c8386c10856046105d",
        "git_blob_id": "eac4a10b4b7549052fe10b3eaa06868c369e3659",
        "bytes": 9569,
        "lines": 266,
    },
]

INITIAL_TRANSFERRED_HARNESS = {
    "path": TEST,
    "sha256": "98da796613e5bd18752ddf64c1357e6a0f0ae392ab550b8e2039c8c64c489353",
    "lines": 301,
    "git_blob_id": None,
    "status": "TEMPORARY_UNTRACKED_MESSAGE_ONLY_HANDOFF_NOT_CURRENT_TEST_BLOB",
}

COMPLETION_GATE_NAMES = [
    "routes_classified",
    "inertia_pages_classified",
    "features_in_canonical_register",
    "routes_and_pages_mapped_to_feature_id",
    "features_with_verified_benchmark_or_final_ncm",
    "human_features_with_task_script_and_ten_scores",
    "common_and_safety_journeys_cross_reviewed",
    "hero_banner_instances_classified",
    "overlay_implementations_and_triggers_classified",
    "safe_routes_observed_at_desktop",
    "selected_families_and_journeys_all_viewports",
    "required_visual_states_classified",
    "material_visual_finding_families_resampled",
    "models_classified",
    "policies_classified",
    "service_domain_entries_classified",
    "critical_async_owners_classified",
    "modules_with_all_eight_passes",
    "prompt_benchmark_projects_formally_triaged",
    "p0_p1_complete_finding_fields",
    "redesigns_neutral_native_no_copy",
    "ease_4_5_claims_independently_reviewed",
    "browser_claims_labeled",
    "visual_inconsistencies_complete_context",
    "official_source_inference_specialist_split",
    "all_agents_returned_reconciled_represented_none_live",
]

EXPECTED_DIRTY = sorted(
    [
        f"{PREFIX}/{SCRIPT_REL}",
        f"{PREFIX}/{OUTPUT_REL}",
    ]
)
EXPECTED_FINAL_DIRTY = sorted(
    EXPECTED_DIRTY
    + [
        f"{PREFIX}/{REVIEW_SCRIPT_REL}",
        f"{PREFIX}/{REVIEW_OUTPUT_REL}",
    ]
)


def git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args],
        cwd=ROOT,
        check=True,
        capture_output=True,
        text=True,
        encoding="utf-8",
    )
    return result.stdout.rstrip()


def git_bytes(revision: str, relative: str) -> bytes:
    return subprocess.run(
        ["git", "show", f"{revision}:{relative}"],
        cwd=ROOT,
        check=True,
        capture_output=True,
    ).stdout


def git_object_exists(specification: str) -> bool:
    return (
        subprocess.run(
            ["git", "cat-file", "-e", specification],
            cwd=ROOT,
            capture_output=True,
        ).returncode
        == 0
    )


def git_is_ancestor(ancestor: str, descendant: str) -> bool:
    return (
        subprocess.run(
            ["git", "merge-base", "--is-ancestor", ancestor, descendant],
            cwd=ROOT,
            capture_output=True,
        ).returncode
        == 0
    )


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def canonical_sha256(value: Any) -> str:
    return sha256(
        json.dumps(
            value,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        ).encode("utf-8")
    )


def strict_text(raw: bytes, label: str) -> None:
    assert not raw.startswith(b"\xef\xbb\xbf"), f"BOM not allowed: {label}"
    assert b"\r" not in raw, f"CR not allowed: {label}"
    assert raw.endswith(b"\n"), f"final LF required: {label}"
    for number, line in enumerate(raw.splitlines(), start=1):
        assert line == line.rstrip(b" \t"), f"trailing whitespace: {label}:{number}"


def strict_json(raw: bytes, label: str) -> dict[str, Any]:
    strict_text(raw, label)

    def no_duplicates(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, f"duplicate JSON key in {label}: {key}"
            result[key] = value
        return result

    value = json.loads(raw.decode("utf-8"), object_pairs_hook=no_duplicates)
    assert isinstance(value, dict)
    assert (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8") == raw
    return value


def file_record(relative: str, revision: str | None = None) -> dict[str, Any]:
    raw = git_bytes(revision, relative) if revision else (ROOT / relative).read_bytes()
    strict_text(raw, f"{revision or 'working'}:{relative}")
    record: dict[str, Any] = {
        "path": relative,
        "sha256": sha256(raw),
        "git_blob_id": (
            git("rev-parse", f"{revision}:{relative}")
            if revision
            else git("hash-object", "--", relative)
        ),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }
    if relative in CHANGED_PATHS and revision in (FIX, MERGE):
        record["insertions"], record["deletions"] = CHANGED_PATHS[relative]
    return record


def validate_findings_snapshot() -> dict[str, Any]:
    raw = git_bytes(MERGE, FINDINGS)
    findings = strict_json(raw, f"{MERGE}:{FINDINGS}")
    records = findings["records"]
    statuses = Counter(record["record_status"] for record in records)
    record_ids = [record["id"] for record in records]
    counts = findings["counts"]
    reconciliation = findings["reconciliation"]

    assert len(records) == len(record_ids) == len(set(record_ids)) == 13
    assert FINDING_ID not in record_ids
    assert statuses == {
        "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING": 8,
        "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING": 2,
        "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING": 3,
    }
    assert counts["retained_claim_records"] == 13
    assert counts["provisional_source_claims"] == 8
    assert counts["historical_already_fixed"] == 2
    assert counts["historical_remediated"] == 3
    assert counts["bounded_disposition_tests_passed"] == 88
    assert counts["bounded_disposition_assertions"] == 1764
    assert counts["static_source_feature_ownership_records"] == 666
    assert counts["static_source_feature_ownership_route_records"] == 309
    assert counts["static_source_feature_ownership_page_records"] == 357
    assert counts["static_controller_action_bridges"] == 97
    assert counts["direct_exact_queue_records"] == 507
    assert counts["direct_exact_queue_reviewed"] == 120
    assert counts["direct_exact_queue_pending_unreviewed"] == 387
    assert counts["direct_exact_queue_owned"] == 98
    assert counts["direct_exact_queue_without_ownership"] == 409
    assert counts["benchmark_mapped"] == 2
    assert counts["final_no_match"] == 0
    assert counts["benchmark_unresolved"] == 338
    assert reconciliation["retained_record_ids_unique"] is True
    assert reconciliation["current_provisional_ids_unique"] is True
    assert reconciliation["retained_record_count"] == 13
    assert reconciliation["current_provisional_count"] == 8
    assert reconciliation["historical_already_fixed_count"] == 2
    assert reconciliation["historical_remediated_count"] == 3
    assert reconciliation["final_ids_cross_file_reconciled"] is False

    return {
        "retained_record_count": 13,
        "current_provisional_count": 8,
        "historical_already_fixed_count": 2,
        "historical_remediated_count": 3,
        "fleet_trip_playback_site_privacy_record_present": False,
        "bounded_disposition_tests_passed": 88,
        "bounded_disposition_assertions": 1764,
    }


def validate_repository() -> dict[str, Any]:
    assert git("rev-parse", "HEAD") == MERGE
    assert git("rev-parse", "main") == MERGE
    assert git("rev-parse", "HEAD^{tree}") == MERGE_TREE
    assert git("show", "-s", "--format=%P", MERGE) == f"{ADVANCED_MAIN} {FIX}"
    assert git("show", "-s", "--format=%s", MERGE) == (
        "merge: scope fleet trip playback to visible sites"
    )
    assert git("rev-parse", f"{FIX}^") == BASE
    assert git("rev-parse", f"{BASE}^{{tree}}") == BASE_TREE
    assert git("rev-parse", f"{FIX}^{{tree}}") == FIX_TREE
    assert git("rev-parse", f"{ADVANCED_MAIN}^{{tree}}") == ADVANCED_MAIN_TREE
    assert git("show", "-s", "--format=%s", FIX) == (
        "fix(fleet): scope trip playback to visible sites"
    )
    assert git("show", "-s", "--format=%s", ADVANCED_MAIN) == (
        "test(settings): isolate internal webhook probe from SSR"
    )
    assert git("rev-parse", "origin/main") == ORIGIN_MAIN
    assert git("rev-list", "--left-right", "--count", "origin/main...main") == "0\t27"
    assert not git_is_ancestor(FIX, ORIGIN_MAIN)
    assert not git_is_ancestor(MERGE, ORIGIN_MAIN)
    assert git("diff", "--cached", "--name-only") == ""

    dirty = sorted(
        line[3:]
        for line in git("status", "--porcelain=v1", "--untracked-files=all").splitlines()
        if line
    )
    assert dirty in (
        [f"{PREFIX}/{SCRIPT_REL}"],
        EXPECTED_DIRTY,
        EXPECTED_FINAL_DIRTY,
    ), dirty
    assert git("diff", "--check") == ""

    fix_names = git("diff", "--name-only", BASE, FIX).splitlines()
    merge_names = git("diff", "--name-only", ADVANCED_MAIN, MERGE).splitlines()
    assert fix_names == list(CHANGED_PATHS)
    assert merge_names == list(CHANGED_PATHS)
    assert git("diff", "--name-status", BASE, FIX).splitlines() == [
        f"M\t{CONTROLLER}",
        f"A\t{TEST}",
    ]
    assert git("diff", "--name-status", ADVANCED_MAIN, MERGE).splitlines() == [
        f"M\t{CONTROLLER}",
        f"A\t{TEST}",
    ]
    assert git("diff", "--numstat", BASE, FIX).splitlines() == [
        f"134\t12\t{CONTROLLER}",
        f"670\t0\t{TEST}",
    ]
    assert git("diff", "--numstat", ADVANCED_MAIN, MERGE).splitlines() == [
        f"134\t12\t{CONTROLLER}",
        f"670\t0\t{TEST}",
    ]

    advanced_names = git("diff", "--name-only", BASE, ADVANCED_MAIN).splitlines()
    assert advanced_names == ADVANCED_DISJOINT_PATHS
    assert set(advanced_names).isdisjoint(CHANGED_PATHS)
    assert git("diff", "--name-status", BASE, ADVANCED_MAIN).splitlines() == [
        f"M\t{ADVANCED_DISJOINT_PATHS[0]}",
        f"A\t{ADVANCED_DISJOINT_PATHS[1]}",
        f"M\t{ADVANCED_DISJOINT_PATHS[2]}",
        f"M\t{ADVANCED_DISJOINT_PATHS[3]}",
        f"M\t{ADVANCED_DISJOINT_PATHS[4]}",
        f"M\t{ADVANCED_DISJOINT_PATHS[5]}",
        f"A\t{ADVANCED_DISJOINT_PATHS[6]}",
        f"M\t{ADVANCED_DISJOINT_PATHS[7]}",
    ]
    assert git_bytes(BASE, CONTROLLER) == git_bytes(ADVANCED_MAIN, CONTROLLER)
    assert not git_object_exists(f"{BASE}:{TEST}")
    assert not git_object_exists(f"{ADVANCED_MAIN}:{TEST}")
    assert git_bytes(FIX, CONTROLLER) == git_bytes(MERGE, CONTROLLER)
    assert git_bytes(FIX, TEST) == git_bytes(MERGE, TEST)
    assert git_bytes(BASE, ROUTE_FILE) == git_bytes(FIX, ROUTE_FILE)
    assert git_bytes(BASE, ROUTE_FILE) == git_bytes(MERGE, ROUTE_FILE)
    assert git_bytes(BASE, FINDINGS) == git_bytes(FIX, FINDINGS)
    assert git_bytes(BASE, FINDINGS) == git_bytes(MERGE, FINDINGS)
    assert (ROOT / CONTROLLER).read_bytes() == git_bytes(MERGE, CONTROLLER)
    assert (ROOT / TEST).read_bytes() == git_bytes(MERGE, TEST)
    assert (ROOT / ROUTE_FILE).read_bytes() == git_bytes(MERGE, ROUTE_FILE)
    assert (ROOT / FINDINGS).read_bytes() == git_bytes(MERGE, FINDINGS)

    patch = subprocess.run(
        ["git", "patch-id", "--stable"],
        cwd=ROOT,
        input=subprocess.run(
            ["git", "diff", BASE, FIX], cwd=ROOT, check=True, capture_output=True
        ).stdout,
        check=True,
        capture_output=True,
    ).stdout.decode("ascii").split()[0]
    assert patch == PATCH_ID

    baseline_controller = file_record(CONTROLLER, BASE)
    fixed_controller = file_record(CONTROLLER, FIX)
    fixed_test = file_record(TEST, FIX)
    merged_controller = file_record(CONTROLLER, MERGE)
    merged_test = file_record(TEST, MERGE)
    route_file = file_record(ROUTE_FILE, MERGE)
    findings_file = file_record(FINDINGS, MERGE)
    advanced_records = [
        file_record(path, ADVANCED_MAIN) for path in ADVANCED_DISJOINT_PATHS
    ]
    assert baseline_controller == EXPECTED_BASE_CONTROLLER
    assert fixed_controller == EXPECTED_FIXED_CONTROLLER
    assert fixed_test == EXPECTED_FIXED_TEST
    assert merged_controller == EXPECTED_FIXED_CONTROLLER
    assert merged_test == EXPECTED_FIXED_TEST
    assert route_file == EXPECTED_ROUTE_FILE
    assert findings_file == EXPECTED_FINDINGS
    assert advanced_records == EXPECTED_ADVANCED_RECORDS

    controller_text = git_bytes(MERGE, CONTROLLER).decode("utf-8")
    test_text = git_bytes(MERGE, TEST).decode("utf-8")
    route_text = git_bytes(MERGE, ROUTE_FILE).decode("utf-8")
    assert controller_text.count("public function show(Request $request, int $trip)") == 1
    assert controller_text.count("public function playback(Request $request, int $trip)") == 1
    assert (
        controller_text[: controller_text.index("public function show(Request $request, int $trip)")]
        .count("\n")
        + 1
        == 24
    )
    assert (
        controller_text[
            : controller_text.index("public function playback(Request $request, int $trip)")
        ].count("\n")
        + 1
        == 88
    )
    assert controller_text.count("private function visibleTripsQuery") == 1
    assert controller_text.count("private function visibleDriverSessionsQuery") == 1
    assert test_text.count("public function test_") == 11
    page_route = (
        "Route::get('/trips/{trip}/playback', "
        "[FleetTripController::class, 'show'])->whereNumber('trip')"
        "->name('fleet-assets.trips.playback');"
    )
    data_route = (
        "Route::get('/trips/{trip}/playback/data', "
        "[FleetTripController::class, 'playback'])->whereNumber('trip')"
        "->name('fleet-assets.trips.playback.data');"
    )
    assert route_text.count(page_route) == 1
    assert route_text.count(data_route) == 1
    assert route_text[: route_text.index(page_route)].count("\n") + 1 == 55
    assert route_text[: route_text.index(data_route)].count("\n") + 1 == 56

    worktrees = git("worktree", "list", "--porcelain")
    assert "C:/w/fleet-trip-playback-privacy-01" not in worktrees.replace("\\", "/")
    assert git(
        "rev-parse",
        "refs/heads/codex/fleet-trip-playback-site-privacy-01-20260830",
    ) == FIX

    return {
        "stable_patch_id": patch,
        "baseline_controller": baseline_controller,
        "fixed_controller": fixed_controller,
        "fixed_test": fixed_test,
        "merged_controller": merged_controller,
        "merged_test": merged_test,
        "current_route_file": route_file,
        "current_findings": findings_file,
        "advanced_disjoint_records": advanced_records,
        "findings_snapshot": validate_findings_snapshot(),
    }


def build_receipt(repository: dict[str, Any]) -> dict[str, Any]:
    focused_cases = [
        "Site-limited viewer cannot open a foreign-Site trip page",
        "Site-limited viewer cannot fetch foreign-Site trip telemetry",
        "visible page suppresses driver identities outside approved historical Sites",
        "same-Site JSON keeps eligible telemetry and filters consent-blocked points",
        "fleet.manage can open another operational Site while archived Sites stay concealed",
        "missing trip IDs are concealed like foreign trip IDs",
        "authentication and fleet.viewAny permission contract remains intact",
        "secondary-Site assignment grants the same playback access as primary Site",
        "direct, home, and client Site provenance is canonical and conflicts fail closed",
        "current trip driver requires matching asset and approved historical Site identity",
        "JSON returns at most 2,000 chronological eligible points",
    ]
    completion_gates = [
        {"gate": number, "name": name, "complete": False}
        for number, name in enumerate(COMPLETION_GATE_NAMES, start=1)
    ]
    completion_boundary = {name: False for name in COMPLETION_GATE_NAMES}
    noninheritance = {
        "settings_or_queclink_application_remediation": False,
        "settings_or_queclink_test_execution": False,
        "settings_and_frame_router_57_tests_318_assertions": False,
        "isolated_fleet_green_replay_recredited": False,
        "fleet_supporting_regressions_recredited": False,
        "baseline_red_passes_failures_or_assertions_recredited": False,
        "static_route_feature_ownership": False,
        "static_controller_action_bridge": False,
        "static_playback_data_route_ownership": False,
        "static_page_or_frontend_ownership": False,
        "queue_matrix_or_feature_union_change": False,
        "write_route_or_telemetry_lifecycle_correctness": False,
        "vehicle_controller_or_trip_index_correctness": False,
        "security_devices_or_user_site_access_service_correctness": False,
        "application_browser_or_ease": False,
        "benchmark_mapping_or_final_no_match_NCM": False,
        "full_suite_coverage_feature_module_pass_or_release": False,
        "publication_final_finding_completion_or_audit_completion": False,
    }
    credit = {
        "historical_condition_confirmed": True,
        "current_defect_reproduced": True,
        "application_remediation": True,
        "bounded_runtime": True,
        "bounded_selected_page_and_json_execution": True,
        "bounded_site_privacy_correctness": True,
        "application_commit_integrated_local_main": True,
        "application_commit_published": False,
        "new_historical_remediated_record_reporting": False,
        "static_route_feature_ownership": False,
        "static_controller_action_bridge": False,
        "framework_route_reachability_complete": False,
        "application_browser": False,
        "benchmark_mapping": False,
        "final_no_match_or_NCM": False,
        "ease": False,
        "full_feature_or_module": False,
        "release": False,
        "final_finding": False,
        "completion": False,
        "audit_complete": False,
    }
    receipt: dict[str, Any] = {
        "schema_version": (
            "run-183-fleet-trip-playback-site-privacy-remediation-wave-35-v1"
        ),
        "run_id": RUN_ID,
        "status": STATUS,
        "materialized_on": "2026-08-30",
        "architecture_boundary": (
            "One operating organisation across multiple Sites; approved Site access, "
            "exact roles and permissions, canonical Asset provenance, direct-object "
            "denial, and privacy are the boundaries. Site is provenance, not a tenant."
        ),
        "pins": {
            "governing_prompt_sha256": GOVERNING_PROMPT_SHA256,
            "continuation_prompt_sha256": CONTINUATION_PROMPT_SHA256,
            "application_baseline_commit": BASE,
            "application_baseline_tree": BASE_TREE,
            "fix_commit": FIX,
            "fix_tree": FIX_TREE,
            "fix_parent": BASE,
            "fix_commit_subject": "fix(fleet): scope trip playback to visible sites",
            "stable_patch_id": repository["stable_patch_id"],
            "clean_advanced_main_commit": ADVANCED_MAIN,
            "clean_advanced_main_tree": ADVANCED_MAIN_TREE,
            "advanced_main_path_count": 8,
            "advanced_main_disjoint_paths": repository["advanced_disjoint_records"],
            "transferred_paths_unchanged_base_to_advanced_main": True,
            "local_main_merge_commit": MERGE,
            "local_main_tree": MERGE_TREE,
            "merge_parents": [ADVANCED_MAIN, FIX],
            "merge_subject": "merge: scope fleet trip playback to visible sites",
            "origin_main_observed": ORIGIN_MAIN,
            "local_main_ahead": 27,
            "local_main_behind": 0,
            "application_remote_publication_observed": False,
            "publication_authorized": False,
            "materializer": file_record(f"{PREFIX}/{SCRIPT_REL}"),
            "baseline_controller": repository["baseline_controller"],
            "temporary_transferred_reproduction_harness": INITIAL_TRANSFERRED_HARNESS,
            "fix_source_and_permanent_regression_test": [
                repository["fixed_controller"],
                repository["fixed_test"],
            ],
            "merged_source_and_permanent_regression_test": [
                repository["merged_controller"],
                repository["merged_test"],
            ],
            "current_route_source": repository["current_route_file"],
            "current_findings_before_run_183": repository["current_findings"],
        },
        "issue_first_disposition": {
            "finding_id": FINDING_ID,
            "record_status": RECORD_STATUS,
            "candidate_feature_id": CANDIDATE_FEATURE_ID,
            "feature_identity_status": "PENDING_FRESH_SEMANTIC_REVIEW",
            "selected_route": {
                "zero_based_index": 85,
                "queue_id": QUEUE_ID,
                "route_record_id": ROUTE_RECORD_ID,
                "route_name": ROUTE_NAME,
                "controller_action": ACTION,
                "supporting_data_route": "fleet-assets.trips.playback.data",
                "supporting_data_action": "FleetTripController::playback",
                "supporting_data_route_static_ownership_adjudicated": False,
            },
            "verdict": "REPRODUCED_AND_REMEDIATED_LOCAL_MAIN_NOT_PUBLISHED",
            "new_discovery_stopped_after_confirmation": True,
            "exclusive_remediation_paths": list(CHANGED_PATHS),
            "red_baseline": {
                "commit": BASE,
                "tests": 5,
                "failed": 3,
                "passed": 2,
                "assertions_reported": 30,
                "duration_seconds": 160.09,
                "exit_code": 1,
                "passing_denominator_credit": 0,
                "observations": [
                    "foreign-Site page returned 200 instead of concealed 404",
                    "foreign-Site playback data returned 200 instead of concealed 404",
                    "visible page exposed hidden-Site driver-session identity",
                    "same-Site consent filtering remained compatible",
                    "fleet.manage operational-Site bypass remained compatible",
                ],
            },
        },
        "remediation": {
            "summary": (
                "Playback page and JSON now resolve trips only through the actor's "
                "visible operational Asset Site provenance before loading, auditing, "
                "or projecting data; driver identity is restricted to the same Asset "
                "and approved historical Site scope."
            ),
            "production_files": 1,
            "regression_test_files": 1,
            "changed_paths": 2,
            "insertions": 804,
            "deletions": 12,
            "page_and_json_get_routes_only": True,
            "ordinary_viewer_approved_site_scope": True,
            "fleet_manage_operational_site_bypass_preserved": True,
            "foreign_missing_archived_inactive_unattributed_or_conflicted_concealment_404": True,
            "denied_page_does_not_emit_view_audit": True,
            "nested_driver_identity_redaction": True,
            "canonical_direct_home_and_client_site_provenance": True,
            "same_asset_time_window_consent_and_two_thousand_point_cap_preserved": True,
            "route_declarations_changed": False,
            "pages_components_copy_or_layout_changed": False,
            "models_migrations_services_or_write_paths_changed": False,
            "third_party_source_assets_wording_or_layout_copied": False,
            "single_tenant_multi_site_boundary_preserved": True,
        },
        "delegated_runtime_execution": {
            "execution_owners": {
                "baseline_red": "root audit lane",
                "isolated_green_regressions_and_post_merge": (
                    "separate Continue OSS audit fixes task"
                ),
            },
            "run_183_producer_executed_tests": False,
            "root_post_merge_reran_tests_for_run_183": False,
            "baseline_red": {
                "tests": 5,
                "failed": 3,
                "passed": 2,
                "assertions_reported": 30,
                "duration_seconds": 160.09,
                "exit_code": 1,
                "credit": "REPRODUCTION_ONLY_ZERO_PASSING_DENOMINATOR_CREDIT",
            },
            "isolated_green_focused": {
                "tests": 11,
                "assertions": 167,
                "duration_seconds": 154.22,
                "added_to_bounded_disposition_denominator": False,
            },
            "isolated_supporting_fleet_regressions": {
                "tests": 20,
                "assertions": 215,
                "duration_seconds": 176.99,
                "scope": "FleetManagementTest plus FleetTripIndexSitePrivacyTest",
                "reported_separately": True,
                "added_to_bounded_disposition_denominator": False,
            },
            "post_merge_green_focused": {
                "tests": 11,
                "assertions": 167,
                "duration_seconds": 174.89,
                "unique_bounded_disposition_denominator_credit": True,
            },
            "focused_cases": focused_cases,
            "focused_replay_aggregated_more_than_once": False,
            "unique_bounded_accounting": {
                "prior": {"tests": 88, "assertions": 1764},
                "increment": {"tests": 11, "assertions": 167},
                "resulting": {"tests": 99, "assertions": 1931},
            },
            "syntax": {
                "isolated_files_passed": 2,
                "post_merge_files_passed": 2,
                "result": "PASS",
            },
            "pint": {"isolated": "PASS", "post_merge": "PASS"},
            "git_diff_and_check": "PASS",
            "full_suite_or_coverage_credit": False,
        },
        "independent_static_review": {
            "root_pre_handoff_route_source_review": "CREDIBLE_CURRENT_DEFECT_CANDIDATE",
            "root_pre_handoff_controller_source_review": (
                "SOURCE_PREDICTS_PAGE_AND_DATA_DISCLOSURE"
            ),
            "audit_reproduction_harness_review": "GO_ZERO_FINDINGS",
            "reported_isolated_controller_and_test_reviews": 2,
            "isolated_review_verdict": "GO",
            "isolated_review_findings": 0,
            "reviewers_executed_tests": False,
            "reviewers_wrote_application_files": False,
            "exact_merge_commit_tree_parents_and_two_path_delta_verified": True,
            "bounded_site_privacy_contract_verified": True,
            "exact_run_183_receipt_review_completed": False,
            "new_record_reporting_authorized": False,
            "run_183r_still_required": True,
        },
        "cleanup_evidence": {
            "isolated_worktree": "C:/w/fleet-trip-playback-privacy-01",
            "isolated_worktree_removed": True,
            "isolated_branch": (
                "codex/fleet-trip-playback-site-privacy-01-20260830"
            ),
            "isolated_branch_retained_at_fix_commit": True,
            "post_merge_owned_pids": [25180, 42264],
            "post_merge_owned_pids_exited": True,
            "post_merge_global_php_or_pest_process_count": 0,
            "numeric_pid_test_schema_count": 0,
            "pre_existing_non_pid_schema_untouched": (
                "oblivion_findings_codex_test_4_test_4"
            ),
            "primary_main_clean_before_audit_writes": True,
            "exclusive_two_path_ownership_released": True,
        },
        "static_ownership_boundary": {
            "owner_records": 666,
            "route_owners": 309,
            "page_owners": 357,
            "action_bridges": 97,
            "queue_total": 507,
            "queue_reviewed": 120,
            "queue_pending": 387,
            "queue_owned": 98,
            "queue_without_ownership": 409,
            "next_zero_based_index": 85,
            "next_queue_id": QUEUE_ID,
            "next_route_record_id": ROUTE_RECORD_ID,
            "next_route_name": ROUTE_NAME,
            "candidate_feature_id": CANDIDATE_FEATURE_ID,
            "ownership_status": "PENDING_FRESH_SEMANTIC_REVIEW",
            "correctness_does_not_adjudicate_static_ownership": True,
            "route_owner_authorized": False,
            "controller_action_bridge_authorized": False,
            "supporting_data_route_owner_authorized": False,
            "queue_advance_authorized": False,
            "fresh_outcome_neutral_ownership_review_required_later": True,
        },
        "benchmark_boundary": {
            "mapped": 2,
            "total": 340,
            "final_no_match_or_NCM": 0,
            "unresolved": 338,
            "changed_by_run_183": False,
        },
        "advanced_main_noninheritance": {
            "path_count": 8,
            "paths": ADVANCED_DISJOINT_PATHS,
            "settings_webhook_finding_credit": False,
            "queclink_session_finding_credit": False,
            "queclink_serial_collision_finding_credit": False,
            "settings_or_queclink_runtime_credit": False,
            "transferred_fleet_paths_unchanged_on_advanced_main": True,
        },
        "noninheritance_boundary": noninheritance,
        "reporting_boundary": {
            "current_findings_snapshot": repository["findings_snapshot"],
            "current_retained_identity_count": 13,
            "current_split": (
                "8 provisional + 2 historical already-fixed + "
                "3 historical remediated"
            ),
            "pending_new_record_id": FINDING_ID,
            "pending_record_status": RECORD_STATUS,
            "pending_candidate_feature_id": CANDIDATE_FEATURE_ID,
            "pending_candidate_association_status": "PENDING_FRESH_SEMANTIC_REVIEW",
            "pending_reporting_delta": {
                "retained_claim_records": 1,
                "current_provisional_source_claims": 0,
                "historical_already_fixed_records": 0,
                "historical_remediated_records": 1,
                "bounded_disposition_tests_passed": 11,
                "bounded_disposition_assertions": 167,
                "final_P0": 0,
                "final_P1": 0,
            },
            "proposed_after_independent_exact_artifact_review": {
                "retained_claim_records": 14,
                "current_provisional_source_claims": 8,
                "historical_already_fixed_records": 2,
                "historical_remediated_records": 4,
                "bounded_disposition_tests_passed": 99,
                "bounded_disposition_assertions": 1931,
                "final_P0": 0,
                "final_P1": 0,
            },
            "independent_review_authorized": False,
            "run_183_changes_live_reporting": False,
            "run_183r_required": True,
            "run_184_reporting_required_after_go": True,
            "run_185_fresh_dashboard_verification_required_after_reporting": True,
        },
        "credit_boundary": credit,
        "completion_gates": completion_gates,
        "completion_boundary": completion_boundary,
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            f"{PREFIX}/{SCRIPT_REL}",
            f"{PREFIX}/{OUTPUT_REL}",
        ],
    }
    assert len(receipt["completion_gates"]) == 26
    assert [row["gate"] for row in receipt["completion_gates"]] == list(range(1, 27))
    assert all(row["complete"] is False for row in receipt["completion_gates"])
    assert len(receipt["completion_boundary"]) == 26
    assert all(value is False for value in receipt["completion_boundary"].values())
    assert all(value is False for value in noninheritance.values())
    assert [key for key, value in credit.items() if value] == [
        "historical_condition_confirmed",
        "current_defect_reproduced",
        "application_remediation",
        "bounded_runtime",
        "bounded_selected_page_and_json_execution",
        "bounded_site_privacy_correctness",
        "application_commit_integrated_local_main",
    ]
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    return receipt


def validate_receipt(receipt: dict[str, Any]) -> None:
    copy = dict(receipt)
    seal = copy.pop("receipt_self_seal_sha256")
    assert seal == canonical_sha256(copy)
    assert receipt["status"] == STATUS
    disposition = receipt["issue_first_disposition"]
    assert disposition["finding_id"] == FINDING_ID
    assert disposition["record_status"] == RECORD_STATUS
    assert disposition["candidate_feature_id"] == CANDIDATE_FEATURE_ID
    assert disposition["feature_identity_status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
    assert disposition["red_baseline"] == {
        "commit": BASE,
        "tests": 5,
        "failed": 3,
        "passed": 2,
        "assertions_reported": 30,
        "duration_seconds": 160.09,
        "exit_code": 1,
        "passing_denominator_credit": 0,
        "observations": [
            "foreign-Site page returned 200 instead of concealed 404",
            "foreign-Site playback data returned 200 instead of concealed 404",
            "visible page exposed hidden-Site driver-session identity",
            "same-Site consent filtering remained compatible",
            "fleet.manage operational-Site bypass remained compatible",
        ],
    }
    runtime = receipt["delegated_runtime_execution"]
    assert runtime["isolated_green_focused"]["tests"] == 11
    assert runtime["isolated_green_focused"]["assertions"] == 167
    assert runtime["isolated_supporting_fleet_regressions"]["tests"] == 20
    assert runtime["isolated_supporting_fleet_regressions"]["assertions"] == 215
    assert runtime["post_merge_green_focused"] == {
        "tests": 11,
        "assertions": 167,
        "duration_seconds": 174.89,
        "unique_bounded_disposition_denominator_credit": True,
    }
    assert runtime["unique_bounded_accounting"] == {
        "prior": {"tests": 88, "assertions": 1764},
        "increment": {"tests": 11, "assertions": 167},
        "resulting": {"tests": 99, "assertions": 1931},
    }
    assert len(runtime["focused_cases"]) == 11
    assert runtime["focused_replay_aggregated_more_than_once"] is False
    assert receipt["pins"]["advanced_main_path_count"] == 8
    assert receipt["advanced_main_noninheritance"]["path_count"] == 8
    assert receipt["static_ownership_boundary"]["ownership_status"] == (
        "PENDING_FRESH_SEMANTIC_REVIEW"
    )
    assert receipt["static_ownership_boundary"]["next_zero_based_index"] == 85
    assert receipt["reporting_boundary"]["current_retained_identity_count"] == 13
    assert receipt["reporting_boundary"][
        "proposed_after_independent_exact_artifact_review"
    ]["retained_claim_records"] == 14
    assert receipt["reporting_boundary"]["independent_review_authorized"] is False
    assert receipt["credit_boundary"]["new_historical_remediated_record_reporting"] is False
    assert receipt["credit_boundary"]["application_commit_published"] is False
    assert len(receipt["completion_gates"]) == 26
    assert all(row["complete"] is False for row in receipt["completion_gates"])
    assert all(value is False for value in receipt["completion_boundary"].values())
    assert receipt["artifact_completion_test_met"] is True
    assert receipt["audit_completion_test_met"] is False


def main() -> None:
    repository = validate_repository()
    receipt = build_receipt(repository)
    validate_receipt(receipt)
    encoded = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_bytes(encoded)
    assert OUTPUT.read_bytes() == encoded
    reloaded = strict_json(OUTPUT.read_bytes(), OUTPUT_REL)
    assert reloaded == receipt
    validate_receipt(reloaded)
    print(
        json.dumps(
            {
                "run_id": RUN_ID,
                "status": STATUS,
                "materializer_sha256": file_record(f"{PREFIX}/{SCRIPT_REL}")["sha256"],
                "receipt_sha256": sha256(encoded),
                "receipt_self_seal_sha256": receipt["receipt_self_seal_sha256"],
                "historical_remediated_reporting_authorized": False,
                "proposed_result": (
                    "14 = 8 provisional + 2 already-fixed + 4 remediated"
                ),
                "proposed_unique_bounded_total": "99/1931",
                "static_ownership_adjudicated": False,
                "application_published": False,
                "all_26_completion_gates_complete": False,
                "audit_complete": False,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
