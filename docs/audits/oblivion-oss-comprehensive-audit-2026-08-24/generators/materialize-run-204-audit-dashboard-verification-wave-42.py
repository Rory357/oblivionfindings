#!/usr/bin/env python3
"""Seal bounded RUN204 verification for the exact RUN203 audit dashboard.

This producer validates exact committed RUN203 reporting inputs, the narrowly
corrected dashboard builder, deterministic generated HTML, an external
self-sealed Codex browser observation, the local resource graph, and terminal
loopback-server cleanup. It writes only its paired browser receipt.

Application browser/runtime/test, correctness, benchmark, finding, ownership,
queue, release, publication, feature/module, Gate 4, and audit-completion
credit remain excluded.
"""
from __future__ import annotations

import argparse
import ast
from collections import Counter
from datetime import date, datetime, timedelta
import hashlib
from html.parser import HTMLParser
import json
import math
import os
from pathlib import Path
import re
import subprocess
from typing import Any
from urllib.parse import unquote, urlsplit


if not __debug__:
    raise RuntimeError("RUN204 materializer refuses optimized Python")


SCRIPT = Path(__file__).resolve()
AUDIT = SCRIPT.parent.parent
ROOT = next(parent for parent in SCRIPT.parents if (parent / ".git").exists())
PREFIX = AUDIT.relative_to(ROOT).as_posix()

MATERIALIZER = SCRIPT.relative_to(AUDIT).as_posix()
OUTPUT = "evidence/browser/current-audit-dashboard-verification-run-204-wave-42.json"
HTML = "audit-dashboard.html"
BUILDER = "generators/build-current-audit-dashboard.py"
FINDINGS = "findings.json"
RUN_203_MATERIALIZER = (
    "generators/materialize-run-203-fleet-trip-playback-data-point-eligibility-"
    "remediation-reporting-wave-42.py"
)
RUN_203_RECEIPT = (
    "evidence/source/current-run-203-fleet-trip-playback-data-point-eligibility-"
    "remediation-reporting-wave-42.json"
)
RUN_203_HANDOFF = (
    "evidence/source/current-run-203-fleet-trip-playback-data-point-eligibility-"
    "coordination-handoff-wave-42.json"
)
RUN_202_RECEIPT = (
    "evidence/browser/current-audit-dashboard-verification-run-202-wave-41.json"
)

RUN_ID = "RUN-204-AUDIT-DASHBOARD-VERIFICATION-WAVE-42"
RUN_203_COMMIT = "09524394fc488e83a960d6c655b6f13095bf86eb"
RUN_203_TREE = "b7f511203e0845abf22f103a1eeb1c1512e23352"
RUN_203_PARENT = "ba39cbc36694164ca9e0f232efd2de00013191b5"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"
LOCAL_MAIN_BEHIND = 0
LOCAL_MAIN_AHEAD = 97
FLEET_PLAYBACK_DATA_FINDING = "FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01"

RUN_203_MATERIALIZER_RECORD = {
    "path": RUN_203_MATERIALIZER,
    "sha256": "bae4f9a4584cc528a09e4375c0f5aca57dbe2e225c49adb14d0e3ae89b10ba9c",
    "git_blob_id": "db022dc6ec4a85518ff6e232ab781970ef691cc4",
    "bytes": 51197,
    "lines": 1247,
}
RUN_203_RECEIPT_RECORD = {
    "path": RUN_203_RECEIPT,
    "sha256": "aeaeddffb45d6eb7b668cbb159b054329d725a368fee7a32fe6f98a8807e492f",
    "git_blob_id": "daf68521d5bea181dd49c3b05ad52ad6fb1f3a2a",
    "bytes": 21099,
    "lines": 506,
}
RUN_203_RECEIPT_SELF_SEAL = (
    "44cadf139df15144c11495d9f5287fe6b43f9bcbe25572d75a926d334b7c0594"
)
RUN_203_HANDOFF_RECORD = {
    "path": RUN_203_HANDOFF,
    "sha256": "ef75a5c6392225fb5c50d3f2964f4cc9d4bf2eda6646b4cdf65968c674d762cd",
    "git_blob_id": "7035edf7f20c04d35b7cffd9e967c857fd1ceff0",
    "bytes": 8093,
    "lines": 174,
}
RUN_203_HANDOFF_SELF_SEAL = (
    "a4b9ca491ffd65a11551bb850fd067a45980c8b1fa9084623a56e081e833acbd"
)
RUN_202_RECEIPT_RECORD = {
    "path": RUN_202_RECEIPT,
    "sha256": "b63ed9585a03cc852d0f772be42de303f0866c73e80cc8522e8de0d328887471",
    "git_blob_id": "80ad75f5f2ee5349a1d6509e2f5b15a2c682cfbc",
    "bytes": 30379,
    "lines": 788,
}
RUN_202_RECEIPT_SELF_SEAL = (
    "a4d296e2a3f779bfa2c7cf34233958a37dc74bb5f6e4f7d78a867d6cb12dc3b8"
)
COMMITTED_FINDINGS = {
    "path": FINDINGS,
    "sha256": "88a66599242d986c6306a1bbd02c95c4088dfc18c5beef5279d68cdd4c6531b2",
    "git_blob_id": "d3b4bfbcb5478eef5e8d77787dd1de7dbc06dead",
    "bytes": 744275,
    "lines": 12725,
}
COMMITTED_RUN_203_BUILDER = {
    "path": BUILDER,
    "sha256": "981030fb81e9ac769f617517702b19f3169865bb535faf9053e873f70ade7ca9",
    "git_blob_id": "e0c180b04795609fe1ed8e06475dda4c510648f6",
    "bytes": 950053,
    "lines": 8271,
}
COMMITTED_RUN_202_DASHBOARD = {
    "path": HTML,
    "sha256": "1876db1ff590c86fb30cefb74368b0241c72d9b75966fcbf1a36d6b1096b30e3",
    "git_blob_id": "03442cdb7ec6e17ae55b61494932171bff1e33f4",
    "bytes": 350017,
    "lines": 78,
}
FINAL_RUN_204_BUILDER = {
    "path": BUILDER,
    "sha256": "ace457993251ae83065c3297a44d1af4059bcfb96f6d3c94baec74926b977854",
    "git_blob_id": "aaec31b0adf13c1379ff8d6170a3ff2357ce47e4",
    "bytes": 950327,
    "lines": 8276,
}
FINAL_RUN_204_BUILDER_DIFF = {
    "path": BUILDER,
    "binary_diff_sha256": (
        "168be8878bc25cb2f5ce414a9ee2299380042668433fdc5b276b2d85ff46a664"
    ),
    "numstat": {"added": 7, "deleted": 2},
}
FINAL_RUN_204_DASHBOARD = {
    "path": HTML,
    "sha256": "4017139aba80c74c16a7e7c0ce8c8fa6f765e85ca9f761a90cfaf2b99bf18682",
    "git_blob_id": "c1cf8a9db57c52720d47a41fccdf1d50c22e6ab7",
    "bytes": 355785,
    "lines": 78,
}
FINAL_RUN_204_DASHBOARD_DIFF = {
    "path": HTML,
    "binary_diff_sha256": (
        "485b144f8a742df1f5776de8ebd5e2b2556a09609bf547a0bb9c7312f29bc577"
    ),
    "numstat": {"added": 17, "deleted": 17},
}

EXPECTED_FINAL_STATUS = sorted(
    [
        f" M {PREFIX}/{HTML}",
        f" M {PREFIX}/{BUILDER}",
        f"?? {PREFIX}/{MATERIALIZER}",
        f"?? {PREFIX}/{OUTPUT}",
    ]
)
EXPECTED_PREOUTPUT_STATUS = sorted(
    item for item in EXPECTED_FINAL_STATUS if not item.endswith(f"/{OUTPUT}")
)
FLEET_PLAYBACK_DATA_PATH_BLOBS = {
    "app/Http/Controllers/Fleet/FleetTripController.php": (
        "c8c16d928610206281720571c64ab6d5b7c7010d"
    ),
    "tests/Feature/FleetAssets/FleetTripPlaybackSitePrivacyTest.php": (
        "013595266e53c0d31c8be95b3736fede630d0b3e"
    ),
}
RUN_203_PATHS = [
    f"{PREFIX}/00-executive-summary.md",
    f"{PREFIX}/01-repository-module-map.md",
    f"{PREFIX}/07-module-findings.md",
    f"{PREFIX}/11-prioritised-roadmap.md",
    f"{PREFIX}/12-native-build-and-do-not-copy-register.md",
    f"{PREFIX}/13-unresolved-questions-and-evidence-gaps.md",
    f"{PREFIX}/evidence/source/current-run-203-fleet-trip-playback-data-point-eligibility-coordination-handoff-wave-42.json",
    f"{PREFIX}/evidence/source/current-run-203-fleet-trip-playback-data-point-eligibility-remediation-reporting-wave-42.json",
    f"{PREFIX}/findings.json",
    f"{PREFIX}/generators/build-current-audit-dashboard.py",
    f"{PREFIX}/generators/materialize-run-203-fleet-trip-playback-data-point-eligibility-remediation-reporting-wave-42.py",
]

NAVIGATION = [
    ("Progress", "#progress"),
    ("RUN-203", "#checkpoint"),
    ("Pages", "#pages"),
    ("Static census", "#static-census"),
    ("Runtime gates", "#runtime"),
    ("Benchmarks", "#benchmarks"),
    ("Modules", "#modules"),
    ("Finding status", "#findings"),
    ("Architecture", "#architecture"),
    ("Gaps", "#gaps"),
]
VIEWPORTS = ["1440x900", "1280x800", "1024x768", "390x844"]
BROWSER_OBSERVATION_SCHEMA = "oblivion-audit-dashboard-browser-observation-v1"
VISUAL_GO = "GO_NO_CLIPPING_OR_OVERLAP"
BROWSER_VISIBLE_TEXT_BOUNDARIES = [
    "667 = 310 route + 357 page",
    "121 reviewed / 386 pending",
    "reviewed = 99 owned + 10 shared + 5 alias + 7 gap",
    "16.976330%",
    "3,262 records remain",
    "RUN-202–203 Fleet playback data-point eligibility checkpoint",
    "RUN-202: exact RUN-201 dashboard verified at 4/4 viewports",
    "48/48 named visible checks per viewport",
    "10/10 navigation · 509/509 resources · zero application credit",
    (
        "RUN-203: Fleet playback data-point eligibility reproduced and remediated "
        "in exactly two paths"
    ),
    (
        "20 retained claim identities split into 8 current provisional P1, 2 "
        "historical already-fixed, and 10 historical remediated"
    ),
    "199/2,722 unique bounded disposition total",
    "RUN-071–203 current reporting checkpoint",
    "RUN-071–203 completion-gate checkpoint",
    "RUN-071–203 evidence lineage",
    "index 84 is not recredited",
    "index 85 fleet-assets.trips.playback is integrated",
    "next index 86 RUN090-ROUTE-0087 / RUN077-ROUTE-0695",
    "fleet-assets.trips.playback.data",
    "FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01",
    "RUN-203 Fleet playback data-point eligibility execution",
    "coordinate-complete rows before ordering and the 2,000-point cap only",
    "candidate/reporting association to CAP-FLEET-VEHICLE-REGISTER",
    "index 86 remains pending fresh semantic review",
    "only the new post-merge 1/6 regression component counted once",
    "delegated coordination transcription, not an original runtime receipt",
    (
        "baseline 9c01f5a4f57f · fix 9c40c51a2604 · local merge ba39cbc36694 · "
        "origin/main c39b07654705 unchanged"
    ),
    "valid red 1 failed + 0 passed + 0 pending / 3 assertions",
    "environment-invalid shared-vendor/classmap attempt",
    "prior playback 11/167",
    "unchanged FleetManagement 15/40",
    "candidate feature association only",
    "2/340 mappings",
    "0/340 final no-match/NCM",
    "338 unresolved targets",
    "one operating organisation across multiple Sites",
    "Gate 4 and audit completion false",
    "Fresh RUN-204 audit-dashboard verification required",
    "dashboard HTML frozen pending RUN-204",
    "RUN-203 Fleet playback data-point eligibility coordination-handoff transcription",
    "RUN-203 Fleet playback data-point eligibility remediation-reporting materializer",
    "RUN-203 Fleet playback data-point eligibility remediation-reporting receipt",
    "RUN-204 audit-dashboard verification receipt",
    "RUN-204 audit-dashboard verification materializer",
    "None supplies audit-dashboard verification for the new RUN-204 HTML.",
    "The linked RUN-204 receipt must record",
    "It verifies the RUN-203 audit artifact only",
    (
        "visible 667/310/357 ownership, 98 bridges, 121/386 queue accounting, "
        "99 owned/408 without ownership"
    ),
]
EXPECTED_SOURCE_LITERAL_BOUNDARIES = 244
EXPECTED_UNIQUE_LOCAL_RESOURCES = 514
EXPECTED_HASHED_LINK_PAIRS = 851
EXPECTED_HASHED_FILE_OCCURRENCES = 849
EXPECTED_UNIQUE_HASHED_FILES = 440
EXPECTED_TASK_SCRIPT_DIRECTORY_OCCURRENCES = 2
EXPECTED_TASK_SCRIPT_BUNDLE_SHA256 = (
    "4171e361c5abc17a63af20cc04133826977b6a6b9c11af9e8d528a7815a4ea33"
)
FUTURE_LINKS = sorted([MATERIALIZER, OUTPUT])


def git(*args: str) -> str:
    return subprocess.run(
        ["git", *args],
        cwd=ROOT,
        check=True,
        capture_output=True,
        text=True,
        encoding="utf-8",
    ).stdout.rstrip()


def run_bytes(*args: str) -> bytes:
    return subprocess.run(
        list(args), cwd=ROOT, check=True, capture_output=True
    ).stdout


def git_bytes(revision: str, relative: str) -> bytes:
    return run_bytes("git", "show", f"{revision}:{relative}")


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def canonical_sha256(value: Any) -> str:
    return sha256(
        json.dumps(
            value,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
            allow_nan=False,
        ).encode("utf-8")
    )


def assert_finite(value: Any) -> None:
    if isinstance(value, float):
        assert math.isfinite(value)
    elif isinstance(value, dict):
        for item in value.values():
            assert_finite(item)
    elif isinstance(value, list):
        for item in value:
            assert_finite(item)


def strict_text(raw: bytes, label: str) -> str:
    assert not raw.startswith(b"\xef\xbb\xbf"), f"BOM not allowed: {label}"
    assert b"\r" not in raw, f"CR not allowed: {label}"
    assert raw.endswith(b"\n"), f"final LF required: {label}"
    for number, line in enumerate(raw.splitlines(), start=1):
        assert line == line.rstrip(b" \t"), f"trailing whitespace: {label}:{number}"
    return raw.decode("utf-8")


def strict_json_bytes(raw: bytes, label: str) -> dict[str, Any]:
    strict_text(raw, label)

    def no_duplicates(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, f"duplicate JSON key in {label}: {key}"
            result[key] = value
        return result

    value = json.loads(
        raw.decode("utf-8"),
        object_pairs_hook=no_duplicates,
        parse_constant=lambda token: (_ for _ in ()).throw(
            AssertionError(f"non-finite JSON in {label}: {token}")
        ),
    )
    assert isinstance(value, dict)
    expected = (
        json.dumps(value, ensure_ascii=False, indent=2, allow_nan=False) + "\n"
    ).encode("utf-8")
    assert expected == raw, f"non-canonical JSON formatting: {label}"
    return value


def strict_json(relative: str) -> dict[str, Any]:
    return strict_json_bytes((AUDIT / relative).read_bytes(), relative)


def file_record(relative: str) -> dict[str, Any]:
    raw = (AUDIT / relative).read_bytes()
    strict_text(raw, relative)
    return {
        "path": relative,
        "sha256": sha256(raw),
        "git_blob_id": git("hash-object", "--", f"{PREFIX}/{relative}"),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


def committed_record(revision: str, relative: str) -> dict[str, Any]:
    raw = git_bytes(revision, f"{PREFIX}/{relative}")
    strict_text(raw, f"{revision}:{relative}")
    return {
        "path": relative,
        "sha256": sha256(raw),
        "git_blob_id": git("rev-parse", f"{revision}:{PREFIX}/{relative}"),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


def diff_record(relative: str) -> dict[str, Any]:
    repository_path = f"{PREFIX}/{relative}"
    binary = run_bytes("git", "diff", "--binary", "--", repository_path)
    fields = git("diff", "--numstat", "--", repository_path).split("\t")
    assert len(fields) == 3 and fields[2] == repository_path
    return {
        "path": relative,
        "binary_diff_sha256": sha256(binary),
        "numstat": {"added": int(fields[0]), "deleted": int(fields[1])},
    }


def verify_self_seal(value: dict[str, Any], expected: str | None = None) -> str:
    without_seal = dict(value)
    observed = without_seal.pop("receipt_self_seal_sha256")
    assert type(observed) is str and re.fullmatch(r"[0-9a-f]{64}", observed)
    assert canonical_sha256(without_seal) == observed
    if expected is not None:
        assert observed == expected
    return observed


def assert_exact(value: Any, expected: Any, label: str) -> None:
    assert type(value) is type(expected), (
        f"{label} type: {type(value).__name__} != {type(expected).__name__}"
    )
    if isinstance(expected, dict):
        assert list(value) == list(expected), f"{label} key order"
        for key in expected:
            assert_exact(value[key], expected[key], f"{label}.{key}")
    elif isinstance(expected, list):
        assert len(value) == len(expected), f"{label} length"
        for index, item in enumerate(expected):
            assert_exact(value[index], item, f"{label}[{index}]" )
    else:
        assert value == expected, f"{label}: {value!r} != {expected!r}"


class Parser(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.hrefs: list[str] = []
        self.anchor_hrefs: list[str] = []
        self.ids: list[str] = []
        self.headings = 0
        self.sections = 0
        self.tables = 0
        self.table_wraps = 0
        self.text_parts: list[str] = []

    def handle_starttag(
        self, tag: str, attrs: list[tuple[str, str | None]]
    ) -> None:
        values = dict(attrs)
        if values.get("id"):
            self.ids.append(str(values["id"]))
        if values.get("href") is not None:
            self.hrefs.append(str(values["href"]))
        if tag == "a" and values.get("href") is not None:
            self.anchor_hrefs.append(str(values["href"]))
        if re.fullmatch(r"h[1-6]", tag):
            self.headings += 1
        if tag == "section":
            self.sections += 1
        if tag == "table":
            self.tables += 1
        if "table-wrap" in str(values.get("class", "")).split():
            self.table_wraps += 1

    def handle_data(self, data: str) -> None:
        self.text_parts.append(data)


def is_local(href: str) -> bool:
    low = href.lower()
    return not (
        href.startswith("#")
        or href.startswith("//")
        or low.startswith(
            ("http://", "https://", "mailto:", "tel:", "javascript:", "data:")
        )
    )


def local_path(href: str) -> Path:
    target = (AUDIT / unquote(urlsplit(href).path)).resolve()
    target.relative_to(AUDIT.resolve())
    return target


def literal_list_assignment(source: str, name: str) -> tuple[str, ...]:
    matches: list[tuple[str, ...]] = []
    for node in ast.walk(ast.parse(source, filename=str(AUDIT / BUILDER))):
        if not isinstance(node, ast.Assign):
            continue
        if not any(
            isinstance(target, ast.Name) and target.id == name
            for target in node.targets
        ):
            continue
        value = ast.literal_eval(node.value)
        assert isinstance(value, list) and all(isinstance(item, str) for item in value)
        matches.append(tuple(value))
    assert len(matches) == 1
    return matches[0]


def validate_repository_state() -> None:
    assert git("rev-parse", "HEAD") == RUN_203_COMMIT
    assert git("rev-parse", "main") == RUN_203_COMMIT
    assert git("show", "-s", "--format=%T", "HEAD") == RUN_203_TREE
    assert git("show", "-s", "--format=%P", "HEAD") == RUN_203_PARENT
    assert git("show", "-s", "--format=%s", "HEAD") == (
        "audit: report RUN203 Fleet playback point eligibility"
    )
    assert sorted(
        git(
            "diff-tree",
            "--no-commit-id",
            "--name-only",
            "-r",
            RUN_203_COMMIT,
        ).splitlines()
    ) == sorted(RUN_203_PATHS)
    assert git("rev-parse", "origin/main") == ORIGIN_MAIN
    assert git("rev-list", "--left-right", "--count", "origin/main...main") == (
        f"{LOCAL_MAIN_BEHIND}\t{LOCAL_MAIN_AHEAD}"
    )
    assert git("diff", "--cached", "--name-only") == ""
    assert git("diff", "--check") == ""
    status = sorted(
        git("status", "--porcelain=v1", "--untracked-files=all").splitlines()
    )
    assert status in (EXPECTED_PREOUTPUT_STATUS, EXPECTED_FINAL_STATUS), status
    assert sorted(git("diff", "--name-only").splitlines()) == sorted(
        [f"{PREFIX}/{BUILDER}", f"{PREFIX}/{HTML}"]
    )
    assert committed_record(RUN_203_COMMIT, BUILDER) == COMMITTED_RUN_203_BUILDER
    assert committed_record(RUN_203_COMMIT, HTML) == COMMITTED_RUN_202_DASHBOARD
    assert file_record(BUILDER) == FINAL_RUN_204_BUILDER
    assert diff_record(BUILDER) == FINAL_RUN_204_BUILDER_DIFF
    assert file_record(HTML) == FINAL_RUN_204_DASHBOARD
    assert diff_record(HTML) == FINAL_RUN_204_DASHBOARD_DIFF
    for path, expected_blob in FLEET_PLAYBACK_DATA_PATH_BLOBS.items():
        assert git("rev-parse", f"HEAD:{path}") == expected_blob


def validate_run_203() -> tuple[dict[str, Any], dict[str, Any]]:
    assert file_record(RUN_203_MATERIALIZER) == RUN_203_MATERIALIZER_RECORD
    assert file_record(RUN_203_RECEIPT) == RUN_203_RECEIPT_RECORD
    assert file_record(RUN_203_HANDOFF) == RUN_203_HANDOFF_RECORD
    assert committed_record(RUN_203_COMMIT, RUN_203_MATERIALIZER) == (
        RUN_203_MATERIALIZER_RECORD
    )
    assert committed_record(RUN_203_COMMIT, RUN_203_RECEIPT) == (
        RUN_203_RECEIPT_RECORD
    )
    assert committed_record(RUN_203_COMMIT, RUN_203_HANDOFF) == (
        RUN_203_HANDOFF_RECORD
    )
    assert file_record(FINDINGS) == COMMITTED_FINDINGS
    assert file_record(RUN_202_RECEIPT) == RUN_202_RECEIPT_RECORD

    run_203 = strict_json(RUN_203_RECEIPT)
    verify_self_seal(run_203, RUN_203_RECEIPT_SELF_SEAL)
    assert run_203["run_id"] == (
        "RUN-203-FLEET-TRIP-PLAYBACK-DATA-POINT-ELIGIBILITY-01-"
        "REMEDIATION-REPORTING-WAVE-42"
    )
    assert run_203["scope"] == {
        "finding_id": FLEET_PLAYBACK_DATA_FINDING,
        "type": "AUDIT_REPORTING_ONLY",
        "architecture": "SINGLE_ORGANISATION_MULTI_SITE",
        "application_or_test_source_mutated_by_run_203": False,
        "runtime_database_browser_or_build_executed_by_run_203": False,
        "dashboard_html_mutated_by_run_203": False,
        "delegated_runtime_or_review_authorship_claimed_by_run_203": False,
    }
    transition = run_203["reporting_transition"]
    assert transition["finding_id"] == FLEET_PLAYBACK_DATA_FINDING
    assert transition["counts_after"] == {
        "retained_claim_records": 20,
        "provisional_source_claims": 8,
        "historical_already_fixed": 2,
        "historical_remediated": 10,
        "final_P0": 0,
        "final_P1": 0,
    }
    assert transition["feature_id"] == "CAP-FLEET-VEHICLE-REGISTER"
    assert transition["candidate_feature_id"] == "CAP-FLEET-VEHICLE-REGISTER"
    assert transition["feature_identity_status"] == (
        "PENDING_FRESH_SEMANTIC_REVIEW"
    )
    assert transition["static_ownership_or_queue_advance"] is False
    assert run_203["bounded_execution_accounting"]["unique_total"] == {
        "tests": 199,
        "assertions": 2722,
    }
    assert run_203["dashboard_forward_gate"] == {
        "required_run": "RUN-204",
        "dashboard_html_changed_by_run_203": False,
        "preserved_run_202_dashboard_sha256": COMMITTED_RUN_202_DASHBOARD["sha256"],
        "generator": MATERIALIZER,
        "receipt": OUTPUT,
        "fresh_four_viewport_navigation_resource_console_verification_required": True,
        "forward_paths_intentionally_unhashed": True,
    }
    preservation = run_203["preservation_boundary"]
    assert preservation["static_ownership"] == {
        "owners": 667,
        "routes": 310,
        "pages": 357,
        "controller_action_bridges": 98,
    }
    assert preservation["queue"] == {
        "total": 507,
        "reviewed": 121,
        "pending": 386,
        "owned": 99,
        "without_ownership": 408,
        "next_zero_based_index": 86,
        "next_queue_id": "RUN090-ROUTE-0087",
        "next_route_record_id": "RUN077-ROUTE-0695",
        "next_route_name": "fleet-assets.trips.playback.data",
        "next_controller_action": "FleetTripController::playback",
    }
    assert preservation["benchmark"] == {
        "mapped": 2,
        "targets": 340,
        "final_no_match_or_NCM": 0,
        "unresolved": 338,
    }
    assert len(run_203["completion_boundary"]) == 26
    assert all(value is False for value in run_203["completion_boundary"].values())
    assert run_203["credit_boundary"]["live_findings_register_and_reporting_status"]
    assert all(
        value is False
        for key, value in run_203["credit_boundary"].items()
        if key != "live_findings_register_and_reporting_status"
    )
    assert run_203["artifact_completion_test_met"] is True
    assert run_203["audit_completion_test_met"] is False
    assert run_203["pins"]["coordination_handoff"] == {
        "path": RUN_203_HANDOFF,
        "sha256": RUN_203_HANDOFF_RECORD["sha256"],
        "bytes": RUN_203_HANDOFF_RECORD["bytes"],
        "lines": RUN_203_HANDOFF_RECORD["lines"],
        "git_blob_id": RUN_203_HANDOFF_RECORD["git_blob_id"],
        "receipt_self_seal_sha256": RUN_203_HANDOFF_SELF_SEAL,
    }

    handoff = strict_json(RUN_203_HANDOFF)
    verify_self_seal(handoff, RUN_203_HANDOFF_SELF_SEAL)
    assert handoff["finding"]["id"] == FLEET_PLAYBACK_DATA_FINDING
    assert handoff["remediated_contract"]["architecture"].startswith(
        "One operating organisation across multiple Sites;"
    )
    assert handoff["source"]["original_issue_specific_runtime_receipt_present"] is False
    assert handoff["source"][
        "original_issue_specific_independent_review_receipt_present"
    ] is False

    run_202 = strict_json(RUN_202_RECEIPT)
    verify_self_seal(run_202, RUN_202_RECEIPT_SELF_SEAL)
    assert run_202["run_id"] == "RUN-202-AUDIT-DASHBOARD-VERIFICATION-WAVE-41"
    assert run_202["pins"]["final_run_202_dashboard"] == COMMITTED_RUN_202_DASHBOARD
    assert run_202["artifact_completion_test_met"] is True
    assert run_202["audit_completion_test_met"] is False

    findings = strict_json(FINDINGS)
    records = findings["records"]
    statuses = Counter(record["record_status"] for record in records)
    assert len(records) == len({record["id"] for record in records}) == 20
    assert statuses == {
        "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING": 8,
        "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING": 2,
        "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING": 10,
    }
    fleet_playback_data = next(
        record for record in records if record["id"] == FLEET_PLAYBACK_DATA_FINDING
    )
    assert fleet_playback_data["feature_id"] == "CAP-FLEET-VEHICLE-REGISTER"
    assert fleet_playback_data["candidate_feature_id"] == (
        "CAP-FLEET-VEHICLE-REGISTER"
    )
    assert fleet_playback_data["feature_identity_status"] == (
        "PENDING_FRESH_SEMANTIC_REVIEW"
    )
    assert fleet_playback_data["completion_credit"] is False
    counts = findings["counts"]
    assert counts["retained_claim_records"] == 20
    assert counts["bounded_disposition_tests_passed"] == 199
    assert counts["bounded_disposition_assertions"] == 2722
    assert counts["benchmark_mapped"] == 2
    assert counts["final_no_match"] == 0
    assert counts["benchmark_unresolved"] == 338
    return run_203, run_202


def validate_builder_and_html() -> dict[str, Any]:
    builder_source = strict_text((AUDIT / BUILDER).read_bytes(), BUILDER)
    html_text = strict_text((AUDIT / HTML).read_bytes(), HTML)
    source_boundaries = literal_list_assignment(
        builder_source, "current_visible_boundaries"
    )
    assert len(source_boundaries) == len(set(source_boundaries)) == (
        EXPECTED_SOURCE_LITERAL_BOUNDARIES
    )
    assert all(boundary in html_text for boundary in source_boundaries)

    navigation_markup = (
        '<nav aria-label="Audit sections"><div>'
        + "".join(
            f'<a href="{target}">{label}</a>' for label, target in NAVIGATION
        )
        + "</div></nav>"
    )
    assert html_text.count(navigation_markup) == 1
    assert '<a href="#checkpoint">RUN-203</a>' in html_text
    assert "<title>Oblivion Findings current-source audit</title>" in html_text

    parser = Parser()
    parser.feed(html_text)
    visible_text = re.sub(r"\s+", " ", " ".join(parser.text_parts)).strip()
    assert len(BROWSER_VISIBLE_TEXT_BOUNDARIES) == 48
    assert all(boundary in visible_text for boundary in BROWSER_VISIBLE_TEXT_BOUNDARIES)

    assert len(parser.hrefs) == 1006
    assert len(parser.anchor_hrefs) == 1005
    assert len(parser.ids) == len(set(parser.ids)) == 10
    assert parser.headings == parser.sections == 26
    assert parser.tables == parser.table_wraps == 10
    fragment_hrefs = [href for href in parser.anchor_hrefs if href.startswith("#")]
    assert len(fragment_hrefs) == 10
    assert all(href[1:] in parser.ids for href in fragment_hrefs)

    local_hrefs = [href for href in parser.hrefs if is_local(href)]
    targets = {local_path(href) for href in local_hrefs}
    assert len(local_hrefs) == 995
    assert len(targets) == EXPECTED_UNIQUE_LOCAL_RESOURCES
    missing = sorted(
        target.relative_to(AUDIT).as_posix()
        for target in targets
        if not target.exists()
    )
    assert missing in ([OUTPUT], []), missing
    assert sorted(
        target.relative_to(AUDIT).as_posix()
        for target in targets
        if target.relative_to(AUDIT).as_posix() in FUTURE_LINKS
    ) == FUTURE_LINKS

    hash_pairs = re.findall(
        r'<a href="([^"]+)">[^<]*</a>\s*<code>([0-9a-f]{64})</code>',
        html_text,
    )
    assert len(hash_pairs) == EXPECTED_HASHED_LINK_PAIRS
    file_pairs = [pair for pair in hash_pairs if not pair[0].endswith("/")]
    directory_pairs = [pair for pair in hash_pairs if pair[0].endswith("/")]
    assert len(file_pairs) == EXPECTED_HASHED_FILE_OCCURRENCES
    assert len({href for href, _ in file_pairs}) == EXPECTED_UNIQUE_HASHED_FILES
    assert directory_pairs == [
        ("task-scripts/", EXPECTED_TASK_SCRIPT_BUNDLE_SHA256)
    ] * EXPECTED_TASK_SCRIPT_DIRECTORY_OCCURRENCES
    for href, displayed_hash in file_pairs:
        assert is_local(href)
        target = local_path(href)
        assert target.is_file(), href
        assert sha256(target.read_bytes()) == displayed_hash, href

    return {
        "source_literal_boundary_count": len(source_boundaries),
        "browser_visible_text_boundary_count": len(
            BROWSER_VISIBLE_TEXT_BOUNDARIES
        ),
        "missing_browser_visible_text_boundaries_before_browser": [],
        "href_attribute_count": len(parser.hrefs),
        "anchor_element_count": len(parser.anchor_hrefs),
        "fragment_anchor_count": len(fragment_hrefs),
        "authored_id_count": len(parser.ids),
        "duplicate_id_count": len(parser.ids) - len(set(parser.ids)),
        "heading_count": parser.headings,
        "section_count": parser.sections,
        "table_count": parser.tables,
        "table_wrap_count": parser.table_wraps,
        "local_relative_link_occurrences": len(local_hrefs),
        "unique_local_resources": len(targets),
        "existing_unique_local_resources": len(targets) - len(missing),
        "missing_local_resources_before_receipt_write": missing,
        "hash_bearing_link_pairs": len(hash_pairs),
        "hashed_file_occurrences": len(file_pairs),
        "unique_hashed_files": len({href for href, _ in file_pairs}),
        "hashed_directory_occurrences": len(directory_pairs),
        "hash_mismatches": [],
        "ordered_navigation_label_hash_pairs_verified": True,
    }


def validate_browser_observation(
    path: Path, html_graph: dict[str, Any]
) -> tuple[dict[str, Any], dict[str, Any]]:
    resolved = path.expanduser().resolve(strict=True)
    assert resolved.is_file()
    try:
        resolved.relative_to(ROOT.resolve())
    except ValueError:
        pass
    else:
        raise AssertionError("browser observation must remain outside repository")

    raw = resolved.read_bytes()
    observation = strict_json_bytes(raw, str(resolved))
    assert_finite(observation)
    assert list(observation) == [
        "schema_version",
        "run_id",
        "observed_at",
        "browser",
        "artifact",
        "viewports",
        "navigation",
        "console",
        "visual_checks",
        "screenshots",
        "deliverable",
        "observation_self_seal_sha256",
    ]
    without_seal = dict(observation)
    observation_seal = without_seal.pop("observation_self_seal_sha256")
    assert type(observation_seal) is str
    assert re.fullmatch(r"[0-9a-f]{64}", observation_seal)
    assert canonical_sha256(without_seal) == observation_seal

    observed_at = observation["observed_at"]
    assert type(observed_at) is str
    assert re.fullmatch(
        r"2026-09-01T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\+12:00", observed_at
    )
    observed_instant = datetime.fromisoformat(observed_at)
    assert observed_instant.date() == date(2026, 9, 1)
    assert observed_instant.utcoffset() == timedelta(hours=12)
    assert_exact(observation["schema_version"], BROWSER_OBSERVATION_SCHEMA, "schema")
    assert_exact(observation["run_id"], RUN_ID, "run_id")

    browser = observation["browser"]
    assert list(browser) == ["name", "provider_id", "tab_id"]
    assert browser["name"] == "Codex in-app browser"
    assert type(browser["provider_id"]) is str and browser["provider_id"]
    assert type(browser["tab_id"]) is str and browser["tab_id"]
    provider_id = browser["provider_id"]
    tab_id = browser["tab_id"]

    artifact = observation["artifact"]
    assert list(artifact) == [
        "url",
        "document_title",
        "server_host",
        "server_port",
        "server_pid",
        "server_executable",
        "http_status",
        "response_content_length",
        "browser_fetched_sha256",
        "observed_at",
    ]
    port = artifact["server_port"]
    pid = artifact["server_pid"]
    executable = artifact["server_executable"]
    assert type(port) is int and 1024 <= port <= 65535
    assert type(pid) is int and pid > 0
    assert type(executable) is str and Path(executable).is_absolute()
    assert_exact(
        artifact,
        {
            "url": f"http://127.0.0.1:{port}/audit-dashboard.html",
            "document_title": "Oblivion Findings current-source audit",
            "server_host": "127.0.0.1",
            "server_port": port,
            "server_pid": pid,
            "server_executable": executable,
            "http_status": 200,
            "response_content_length": FINAL_RUN_204_DASHBOARD["bytes"],
            "browser_fetched_sha256": FINAL_RUN_204_DASHBOARD["sha256"],
            "observed_at": observed_at,
        },
        "artifact",
    )

    viewports = observation["viewports"]
    assert type(viewports) is dict and list(viewports) == VIEWPORTS
    for viewport in VIEWPORTS:
        assert_exact(
            viewports[viewport],
            {
                "requested": viewport,
                "actual": viewport,
                "provider_id": provider_id,
                "tab_id": tab_id,
                "dashboard_sha256": FINAL_RUN_204_DASHBOARD["sha256"],
                "observed_at": observed_at,
                "visible_text_checks": [
                    {"text": boundary, "visible": True}
                    for boundary in BROWSER_VISIBLE_TEXT_BOUNDARIES
                ],
                "visible_text_passed": len(BROWSER_VISIBLE_TEXT_BOUNDARIES),
                "visible_text_total": len(BROWSER_VISIBLE_TEXT_BOUNDARIES),
                "anchor_elements": html_graph["anchor_element_count"],
                "fragment_anchors": html_graph["fragment_anchor_count"],
                "authored_ids": html_graph["authored_id_count"],
                "browser_dom_ids": html_graph["authored_id_count"] + 1,
                "browser_only_injected_id_count": 1,
                "duplicate_ids": [],
                "headings": html_graph["heading_count"],
                "sections": html_graph["section_count"],
                "navigation_links": len(NAVIGATION),
                "visible_navigation_links": len(NAVIGATION),
                "tables": html_graph["table_count"],
                "table_wraps": html_graph["table_wrap_count"],
                "table_containment_failures": 0,
                "unique_local_resources": html_graph["unique_local_resources"],
                "local_relative_link_occurrences": html_graph[
                    "local_relative_link_occurrences"
                ],
                "missing_fragments": [],
                "page_horizontal_overflow": False,
            },
            f"viewports.{viewport}",
        )

    base_url = f"http://127.0.0.1:{port}/audit-dashboard.html"
    assert_exact(
        observation["navigation"],
        [
            {
                "label": label,
                "expected_hash": target,
                "observed_hash": target,
                "browser_click_performed": True,
                "loaded_url": f"{base_url}{target}",
                "final_url": f"{base_url}{target}",
                "target_exists": True,
                "target_visible": True,
                "provider_id": provider_id,
                "tab_id": tab_id,
                "dashboard_sha256": FINAL_RUN_204_DASHBOARD["sha256"],
                "observed_at": observed_at,
            }
            for label, target in NAVIGATION
        ],
        "navigation",
    )
    assert_exact(
        observation["console"],
        {
            "messages": [],
            "page_errors": [],
            "warning_or_error_logs": [],
            "provider_id": provider_id,
            "tab_id": tab_id,
            "dashboard_sha256": FINAL_RUN_204_DASHBOARD["sha256"],
            "observed_at": observed_at,
        },
        "console",
    )
    assert_exact(
        observation["visual_checks"],
        {
            "desktop_result": VISUAL_GO,
            "mobile_result": VISUAL_GO,
            "mobile_navigation_horizontally_scrollable_at_390x844": True,
            "provider_id": provider_id,
            "tab_id": tab_id,
            "dashboard_sha256": FINAL_RUN_204_DASHBOARD["sha256"],
            "observed_at": observed_at,
        },
        "visual_checks",
    )
    assert_exact(
        observation["screenshots"],
        [
            {
                "viewport": viewport,
                "provider_id": provider_id,
                "tab_id": tab_id,
                "dashboard_sha256": FINAL_RUN_204_DASHBOARD["sha256"],
                "observed_at": observed_at,
                "captured": True,
                "retained": False,
                "visual_inspection": VISUAL_GO,
            }
            for viewport in VIEWPORTS
        ],
        "screenshots",
    )
    assert_exact(
        observation["deliverable"],
        {
            "dashboard_tab_marked_deliverable": True,
            "current_exact_dashboard_tab_retained": True,
            "browser_viewport_override_reset": True,
            "provider_id": provider_id,
            "tab_id": tab_id,
            "dashboard_sha256": FINAL_RUN_204_DASHBOARD["sha256"],
            "observed_at": observed_at,
        },
        "deliverable",
    )
    return observation, {
        "path": str(resolved),
        "sha256": sha256(raw),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
        "observation_self_seal_sha256": observation_seal,
    }


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--browser-observation-file", type=Path, required=True)
    parser.add_argument(
        "--builder-execution-sha256",
        action="append",
        dest="builder_execution_sha256s",
        required=True,
    )
    parser.add_argument("--final-http-head-verified-count", type=int)
    parser.add_argument("--final-http-head-failure-count", type=int)
    parser.add_argument("--listeners-after-cleanup", type=int)
    parser.add_argument(
        "--exact-server-pid-present-after-cleanup",
        choices=("true", "false"),
    )
    parser.add_argument("--matching-loopback-processes-after-cleanup", type=int)
    return parser.parse_args()


def finalization_inputs(
    args: argparse.Namespace, html_graph: dict[str, Any]
) -> dict[str, Any]:
    assert args.builder_execution_sha256s == [
        FINAL_RUN_204_DASHBOARD["sha256"],
        FINAL_RUN_204_DASHBOARD["sha256"],
    ]
    resource_values = (
        args.final_http_head_verified_count,
        args.final_http_head_failure_count,
    )
    assert all(value is None for value in resource_values) or all(
        value is not None for value in resource_values
    )
    resource_complete = all(value is not None for value in resource_values)
    if resource_complete:
        assert args.final_http_head_verified_count == EXPECTED_UNIQUE_LOCAL_RESOURCES
        assert args.final_http_head_failure_count == 0

    cleanup_values = (
        args.listeners_after_cleanup,
        args.exact_server_pid_present_after_cleanup,
        args.matching_loopback_processes_after_cleanup,
    )
    assert all(value is None for value in cleanup_values) or all(
        value is not None for value in cleanup_values
    )
    cleanup_complete = all(value is not None for value in cleanup_values)
    exact_pid_present: bool | None = None
    if cleanup_complete:
        exact_pid_present = args.exact_server_pid_present_after_cleanup == "true"
        assert args.listeners_after_cleanup == 0
        assert exact_pid_present is False
        assert args.matching_loopback_processes_after_cleanup == 0

    browser: dict[str, Any] | None = None
    browser_input: dict[str, Any] | None = None
    if args.browser_observation_file is not None:
        browser, browser_input = validate_browser_observation(
            args.browser_observation_file, html_graph
        )
    return {
        "invoker_attested_builder_execution_sha256s": (
            args.builder_execution_sha256s
        ),
        "invoker_attested_builder_execution_count": len(
            args.builder_execution_sha256s
        ),
        "invoker_attested_deterministic_rerun_bytes_match": (
            len(set(args.builder_execution_sha256s)) == 1
        ),
        "browser_complete": browser is not None,
        "browser_observation": browser,
        "browser_observation_input": browser_input,
        "resource_complete": resource_complete,
        "final_http_head_verified_count": (
            args.final_http_head_verified_count if resource_complete else None
        ),
        "final_http_head_failure_count": (
            args.final_http_head_failure_count if resource_complete else None
        ),
        "cleanup_complete": cleanup_complete,
        "listeners_after_cleanup": (
            args.listeners_after_cleanup if cleanup_complete else None
        ),
        "exact_server_pid_present_after_cleanup": exact_pid_present,
        "matching_loopback_processes_after_cleanup": (
            args.matching_loopback_processes_after_cleanup
            if cleanup_complete
            else None
        ),
        "final_navigation_verified_count": len(NAVIGATION) if browser else None,
        "final_browser_warning_error_count": 0 if browser else None,
        "dashboard_tab_marked_deliverable": True if browser else None,
    }


def browser_summary(
    browser: dict[str, Any] | None,
) -> dict[str, Any] | None:
    if browser is None:
        return None
    return {
        "browser": browser["browser"],
        "artifact": browser["artifact"],
        "viewports": {
            viewport: {
                "visible_text_passed": browser["viewports"][viewport][
                    "visible_text_passed"
                ],
                "visible_text_total": browser["viewports"][viewport][
                    "visible_text_total"
                ],
                "anchor_elements": browser["viewports"][viewport]["anchor_elements"],
                "fragment_anchors": browser["viewports"][viewport]["fragment_anchors"],
                "authored_ids": browser["viewports"][viewport]["authored_ids"],
                "browser_dom_ids": browser["viewports"][viewport]["browser_dom_ids"],
                "browser_only_injected_id_count": browser["viewports"][viewport][
                    "browser_only_injected_id_count"
                ],
                "page_horizontal_overflow": browser["viewports"][viewport][
                    "page_horizontal_overflow"
                ],
                "table_containment_failures": browser["viewports"][viewport][
                    "table_containment_failures"
                ],
            }
            for viewport in VIEWPORTS
        },
        "navigation": browser["navigation"],
        "console": browser["console"],
        "visual_checks": browser["visual_checks"],
        "screenshots": browser["screenshots"],
        "deliverable": browser["deliverable"],
        "observation_self_seal_sha256": browser["observation_self_seal_sha256"],
    }


COMPLETION_NAMES = [
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


def build_receipt(
    run_203: dict[str, Any],
    run_202: dict[str, Any],
    html_graph: dict[str, Any],
    finalization: dict[str, Any],
) -> dict[str, Any]:
    browser = finalization["browser_observation"]
    artifact_complete = bool(
        finalization["browser_complete"]
        and finalization["resource_complete"]
        and finalization["cleanup_complete"]
    )
    server = browser["artifact"] if browser is not None else None
    receipt: dict[str, Any] = {
        "schema_version": "run-204-audit-dashboard-verification-wave-42-v1",
        "run_id": RUN_ID,
        "generated_at": None,
        "pins": {
            "run_203_commit": RUN_203_COMMIT,
            "run_203_tree": RUN_203_TREE,
            "run_203_parent": RUN_203_PARENT,
            "origin_main": ORIGIN_MAIN,
            "local_main_ahead": LOCAL_MAIN_AHEAD,
            "local_main_behind": LOCAL_MAIN_BEHIND,
            "run_203_materializer": RUN_203_MATERIALIZER_RECORD,
            "run_203_receipt": RUN_203_RECEIPT_RECORD,
            "run_203_receipt_self_seal_sha256": RUN_203_RECEIPT_SELF_SEAL,
            "run_203_coordination_handoff": RUN_203_HANDOFF_RECORD,
            "run_203_coordination_handoff_self_seal_sha256": (
                RUN_203_HANDOFF_SELF_SEAL
            ),
            "run_202_receipt": RUN_202_RECEIPT_RECORD,
            "run_202_receipt_self_seal_sha256": RUN_202_RECEIPT_SELF_SEAL,
            "committed_findings": COMMITTED_FINDINGS,
            "committed_run_203_builder": COMMITTED_RUN_203_BUILDER,
            "committed_run_202_dashboard": COMMITTED_RUN_202_DASHBOARD,
            "final_run_204_builder": FINAL_RUN_204_BUILDER,
            "final_run_204_builder_diff": FINAL_RUN_204_BUILDER_DIFF,
            "final_run_204_builder_unchanged_from_run_203": False,
            "final_run_204_dashboard": FINAL_RUN_204_DASHBOARD,
            "final_run_204_dashboard_diff": FINAL_RUN_204_DASHBOARD_DIFF,
            "run_204_materializer": file_record(MATERIALIZER),
        },
        "reported_snapshot": {
            "static": {
                "source_owner_records": 667,
                "route_owner_records": 310,
                "page_owner_records": 357,
                "static_controller_action_bridges": 98,
                "bounded_source_denominator": 3929,
                "bounded_source_residual": 3262,
                "bounded_source_ownership_percent": "16.976330",
            },
            "queue": {
                "reviewed": 121,
                "pending": 386,
                "owned": 99,
                "shared": 10,
                "alias": 5,
                "gap": 7,
                "without_ownership": 408,
            },
            "finding_lineage": {
                "records": 20,
                "provisional": 8,
                "historical_already_fixed": 2,
                "historical_remediated": 10,
                "bounded_tests": 199,
                "bounded_assertions": 2722,
                "final_P0": 0,
                "final_P1": 0,
            },
            "benchmark": {
                "mapped": 2,
                "targets": 340,
                "final_no_match_or_NCM": 0,
                "unresolved": 338,
            },
        },
        "reported_transition": {
            "from_dashboard_sha256": COMMITTED_RUN_202_DASHBOARD["sha256"],
            "to_dashboard_sha256": FINAL_RUN_204_DASHBOARD["sha256"],
            "reported_finding_id": FLEET_PLAYBACK_DATA_FINDING,
            "run_203_forward_gate_satisfied_by_this_artifact": artifact_complete,
            "static_ownership_or_queue_advance": False,
            "new_application_finding": False,
        },
        "generation": {
            "builder": FINAL_RUN_204_BUILDER,
            "builder_diff": FINAL_RUN_204_BUILDER_DIFF,
            "builder_unchanged_from_run_203": False,
            "builder_changes_run204_assertion_only": True,
            "dashboard": FINAL_RUN_204_DASHBOARD,
            "dashboard_diff": FINAL_RUN_204_DASHBOARD_DIFF,
            "final_builder_and_dashboard_exact_bytes_pinned": True,
            "invoker_attested_builder_execution_count": finalization[
                "invoker_attested_builder_execution_count"
            ],
            "invoker_attested_builder_execution_sha256s": finalization[
                "invoker_attested_builder_execution_sha256s"
            ],
            "invoker_attested_deterministic_rerun_bytes_match": finalization[
                "invoker_attested_deterministic_rerun_bytes_match"
            ],
            "source_literal_boundaries": html_graph[
                "source_literal_boundary_count"
            ],
            "output_utf8_lf": True,
        },
        "superseded_run_202_observation": {
            "dashboard": COMMITTED_RUN_202_DASHBOARD,
            "receipt": RUN_202_RECEIPT_RECORD,
            "receipt_artifact_completion_test_met": run_202[
                "artifact_completion_test_met"
            ],
            "superseded_by_dashboard_sha256": FINAL_RUN_204_DASHBOARD["sha256"],
            "no_current_browser_credit_inherited": True,
        },
        "current_browser_input": finalization["browser_observation_input"],
        "current_browser_verification": browser_summary(browser),
        "html_graph": html_graph,
        "http_head_verification": {
            "expected_unique_resources": EXPECTED_UNIQUE_LOCAL_RESOURCES,
            "verified_count": finalization["final_http_head_verified_count"],
            "failure_count": finalization["final_http_head_failure_count"],
            "complete": finalization["resource_complete"],
        },
        "server_cleanup": {
            "temporary_loopback_host": "127.0.0.1",
            "temporary_loopback_port": server["server_port"] if server else None,
            "temporary_server_pid": server["server_pid"] if server else None,
            "temporary_server_executable": (
                server["server_executable"] if server else None
            ),
            "listeners_after_cleanup": finalization["listeners_after_cleanup"],
            "exact_server_pid_present_after_cleanup": finalization[
                "exact_server_pid_present_after_cleanup"
            ],
            "matching_loopback_processes_after_cleanup": finalization[
                "matching_loopback_processes_after_cleanup"
            ],
            "complete": finalization["cleanup_complete"],
        },
        "finding_boundary": {
            "finding_id": FLEET_PLAYBACK_DATA_FINDING,
            "record_status": (
                "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
            ),
            "coordinate_complete_rows_before_ordering_and_cap_only": True,
            "delegated_coordination_transcription_not_original_runtime_receipt": True,
            "candidate_feature_association_only": True,
            "playback_data_queue_index_86_pending_fresh_review": True,
            "static_ownership_credit": False,
            "prior_playback_privacy_credit": False,
            "telemetry_lifecycle_range_or_write_credit": False,
            "map_frontend_or_adjacent_fleet_credit": False,
            "application_browser_credit": False,
            "benchmark_credit": False,
            "final_finding": False,
        },
        "execution_boundary": {
            "bounded_unique_tests": 199,
            "bounded_unique_assertions": 2722,
            "run_204_application_runtime_or_tests_executed": False,
            "run_204_browser_scope": "static audit-dashboard HTML only",
            "full_suite_or_coverage": False,
        },
        "benchmark_boundary": {
            "mapped": 2,
            "targets": 340,
            "final_no_match_or_NCM": 0,
            "unresolved": 338,
            "run_204_benchmark_credit": False,
        },
        "noninheritance": {
            "application_browser": False,
            "responsive_application_or_visual_credit": False,
            "application_correctness": False,
            "application_privacy": False,
            "application_runtime": False,
            "static_route_or_page_feature_ownership": False,
            "static_controller_action_bridge": False,
            "queue_advance": False,
            "benchmark_mapping": False,
            "final_no_match_or_NCM": False,
            "deployment": False,
            "release": False,
            "publication": False,
            "gate_4": False,
            "audit_complete": False,
        },
        "worktree_attestation": {
            "staged_paths": [],
            "modified_paths": [f"{PREFIX}/{HTML}", f"{PREFIX}/{BUILDER}"],
            "untracked_paths": [f"{PREFIX}/{MATERIALIZER}", f"{PREFIX}/{OUTPUT}"],
            "application_or_test_dirt": [],
            "builder_matches_committed_run_203_exact_blob": False,
            "builder_matches_final_run_204_exact_blob": True,
            "builder_changes_run204_assertion_only": True,
            "fleet_playback_data_paths_match_run_203_exact_blobs": True,
        },
        "mutation_attestation": {
            "materializer_writes_only": OUTPUT,
            "atomic_exclusive_temp_write": True,
            "fsync_before_replace": True,
            "stale_temp_refused": True,
            "optimized_python_refused": True,
            "strict_duplicate_key_free_json": True,
            "canonical_self_seal": True,
        },
        "credit_boundary": {
            "exact_run_204_dashboard_artifact_verification": artifact_complete,
            "application_source_or_test_change": False,
            "application_runtime_reexecution": False,
            "application_browser": False,
            "responsive_application_or_visual_credit": False,
            "correctness_or_privacy_finding": False,
            "static_route_or_page_feature_ownership": False,
            "static_controller_action_bridge": False,
            "queue_advance": False,
            "benchmark_mapping": False,
            "final_no_match_or_NCM": False,
            "ease": False,
            "full_suite_or_coverage": False,
            "pass": False,
            "deployment": False,
            "release": False,
            "publication": False,
            "final_finding": False,
            "feature_or_module_completion": False,
            "gate_4": False,
            "audit_complete": False,
        },
        "completion_gates": [
            {
                "name": name,
                "complete": False,
                "reason": "RUN204 verifies one exact dashboard artifact only",
            }
            for name in COMPLETION_NAMES
        ],
        "artifact_scope": {
            "materializer": MATERIALIZER,
            "receipt": OUTPUT,
            "builder": BUILDER,
            "dashboard": HTML,
            "repository_mutation_path_count": 4,
        },
        "remote_state": {
            "origin_main": ORIGIN_MAIN,
            "push_performed": False,
            "publication_claimed": False,
        },
        "finalization_state": {
            "browser_complete": finalization["browser_complete"],
            "resource_complete": finalization["resource_complete"],
            "cleanup_complete": finalization["cleanup_complete"],
            "navigation_verified_count": finalization[
                "final_navigation_verified_count"
            ],
            "browser_warning_error_count": finalization[
                "final_browser_warning_error_count"
            ],
            "dashboard_tab_marked_deliverable": finalization[
                "dashboard_tab_marked_deliverable"
            ],
        },
        "artifact_completion_test_met": artifact_complete,
        "audit_completion_test_met": False,
    }
    receipt["completion_boundary"] = {
        item["name"]: item["complete"] for item in receipt["completion_gates"]
    }
    assert len(receipt["completion_boundary"]) == 26
    assert all(value is False for value in receipt["completion_boundary"].values())
    assert run_203["audit_completion_test_met"] is False
    return receipt


def write_receipt(receipt: dict[str, Any]) -> None:
    assert_finite(receipt)
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    raw = (
        json.dumps(receipt, ensure_ascii=False, indent=2, allow_nan=False) + "\n"
    ).encode("utf-8")
    output = AUDIT / OUTPUT
    temporary = output.with_name(f".{output.name}.tmp-run204")
    assert not temporary.exists(), f"stale receipt temp: {temporary}"
    try:
        with temporary.open("xb") as handle:
            handle.write(raw)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, output)
    finally:
        if temporary.exists():
            temporary.unlink()

    observed = strict_json(OUTPUT)
    verify_self_seal(observed, receipt["receipt_self_seal_sha256"])
    assert file_record(OUTPUT)["sha256"] == sha256(raw)
    status = sorted(
        git("status", "--porcelain=v1", "--untracked-files=all").splitlines()
    )
    assert status == EXPECTED_FINAL_STATUS, status
    print(
        json.dumps(
            {
                "run_id": RUN_ID,
                "output": OUTPUT,
                "sha256": sha256(raw),
                "receipt_self_seal_sha256": receipt[
                    "receipt_self_seal_sha256"
                ],
                "artifact_completion_test_met": receipt[
                    "artifact_completion_test_met"
                ],
            },
            sort_keys=True,
        )
    )


def main() -> None:
    args = parse_args()
    validate_repository_state()
    run_203, run_202 = validate_run_203()
    html_graph = validate_builder_and_html()
    finalization = finalization_inputs(args, html_graph)
    receipt = build_receipt(run_203, run_202, html_graph, finalization)
    write_receipt(receipt)


if __name__ == "__main__":
    main()
