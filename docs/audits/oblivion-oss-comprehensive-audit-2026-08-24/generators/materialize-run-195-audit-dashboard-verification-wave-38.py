#!/usr/bin/env python3
"""Seal bounded RUN195 facts for the exact RUN194 audit dashboard.

This producer validates the committed RUN194 reporting inputs, the narrow
dashboard-builder continuation guard, the deterministic generated HTML, the
reported Codex in-app-browser observations, and the local resource graph from
exact bytes. It writes only its paired browser-evidence receipt. Application
browser/runtime/test, correctness, benchmark, finding, release, publication,
feature/module, Gate 4, and audit-completion credit remain excluded.
"""
from __future__ import annotations

import argparse
import ast
from collections import Counter
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
    raise RuntimeError("RUN195 materializer refuses optimized Python; assertions are required")


SCRIPT = Path(__file__).resolve()
AUDIT = SCRIPT.parent.parent
ROOT = next(parent for parent in SCRIPT.parents if (parent / ".git").exists())
PREFIX = AUDIT.relative_to(ROOT).as_posix()
MATERIALIZER = SCRIPT.relative_to(AUDIT).as_posix()
OUTPUT = "evidence/browser/current-audit-dashboard-verification-run-195-wave-38.json"
HTML = "audit-dashboard.html"
BUILDER = "generators/build-current-audit-dashboard.py"
FINDINGS = "findings.json"
RUN_194_MATERIALIZER = (
    "generators/materialize-run-194-fleet-fuel-index-site-privacy-remediation-"
    "reporting-wave-38.py"
)
RUN_194_RECEIPT = (
    "evidence/source/current-run-194-fleet-fuel-index-site-privacy-remediation-"
    "reporting-wave-38.json"
)

RUN_ID = "RUN-195-AUDIT-DASHBOARD-VERIFICATION-WAVE-38"
RUN_194_COMMIT = "47a6d231c52a78c9f0f606e41d4492d754771027"
RUN_194_TREE = "c1e262a50c67797b819d3f1085ece2782b41237e"
RUN_194_PARENT = "39a5d97d7d0ff9ea03070e90193581479f423022"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"
LOCAL_MAIN_AHEAD = 78
LOCAL_MAIN_BEHIND = 0
SERVER_PORT = 43217
SERVER_PID = 35108
SERVER_EXECUTABLE = (
    "C:\\Users\\steph\\.cache\\codex-runtimes\\codex-primary-runtime\\"
    "dependencies\\python\\python.exe"
)

RUN_194_MATERIALIZER_RECORD = {
    "path": RUN_194_MATERIALIZER,
    "sha256": "816f8e9449a434eef79e64ec5cb7d10a4e6f1628a8c099a441c9beab0fac17f8",
    "git_blob_id": "9f758662b5265c4f8c43f08d45ae897333746f92",
    "bytes": 40384,
    "lines": 768,
}
RUN_194_RECEIPT_RECORD = {
    "path": RUN_194_RECEIPT,
    "sha256": "84663ee10905b82203e32203a78d1086b4388bf68617718b975b812d08b8e63d",
    "git_blob_id": "2f8b8eeb63a99e58c7b77fa77f4bbcc50ea18f8b",
    "bytes": 18867,
    "lines": 452,
}
RUN_194_RECEIPT_SELF_SEAL = (
    "e6e882b0db2a391d64432f88d577d67b362de78090c910d5f902051a6e738a96"
)
COMMITTED_FINDINGS = {
    "path": FINDINGS,
    "sha256": "268b63e20dcc40ecc0ba772e8431a9d8a35c9df9bfa98197abdfc273e972e525",
    "git_blob_id": "6735e906278726d34dcbd6aba30e5feb5f60b27f",
    "bytes": 664123,
    "lines": 11633,
}
COMMITTED_RUN_194_BUILDER = {
    "path": BUILDER,
    "sha256": "cd93d61882ef577695eb1765f44c9a4b7b3bda853881dce58de5e3c46dd31e82",
    "git_blob_id": "cd41915fb6a42d0314536c7f4cc0cfdc9761c5ba",
    "bytes": 777100,
    "lines": 6827,
}
FINAL_RUN_195_BUILDER = {
    "path": BUILDER,
    "sha256": "44fe804d6671672fbe0c2cc73d2f0917f4042c466901419f9b76d89ecbdfd5a4",
    "git_blob_id": "3971a40720327b053f83ad5d8a812f14ccbc8ead",
    "bytes": 785868,
    "lines": 6892,
}
FINAL_RUN_195_BUILDER_DIFF = {
    "path": BUILDER,
    "binary_diff_sha256": (
        "38a2fbb775c00cdfad999262402761bfeac69dea2877990030c5c69ad80a9e50"
    ),
    "numstat": {"added": 71, "deleted": 6},
}
COMMITTED_RUN_192_DASHBOARD = {
    "path": HTML,
    "sha256": "8d19569e7bfb256edeecdc754e2bc47e2ddad3ecd8de099e3bb0dad9b50e313b",
    "git_blob_id": "fb0ba424878117bf1362aea77c892a00fda32b95",
    "bytes": 317284,
    "lines": 78,
}
FINAL_RUN_195_DASHBOARD = {
    "path": HTML,
    "sha256": "9a87dc70a7b190347ca7029c12abf8e025e4c722a6256502ba8c1c9af542f3b9",
    "git_blob_id": "6b8bd0606ed2aade854bd9f17d5022c542ddd856",
    "bytes": 331400,
    "lines": 78,
}
FINAL_RUN_195_DASHBOARD_DIFF = {
    "path": HTML,
    "binary_diff_sha256": (
        "ff9c98272a67c064764a89b3002b2a5aada6a48b7be145028a30f25790f8e4f8"
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
    value for value in EXPECTED_FINAL_STATUS if not value.endswith(f"/{OUTPUT}")
)
NAVIGATION = [
    ("Progress", "#progress"),
    ("RUN-194", "#checkpoint"),
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
FINAL_BROWSER_PROVIDER_ID: str | None = "-f212-4901-940d-6bb6bd3f36ed"
FINAL_BROWSER_TAB_ID: str | None = "19"
FINAL_BROWSER_OBSERVED_AT: str | None = "2026-09-01T10:39:53.592265+12:00"
BROWSER_VISIBLE_TEXT_BOUNDARIES = [
    "667 = 310 route + 357 page",
    "121 reviewed / 386 pending",
    "reviewed = 99 owned + 10 shared + 5 alias + 7 gap",
    "16.976330%",
    "3,262 records remain",
    "RUN-192–194 Fleet Fuel index Site-privacy remediation reporting checkpoint",
    "RUN-192: exact RUN-191 dashboard verified at 4/4 viewports",
    "30/30 named visible checks per viewport",
    "10/10 navigation · 476/476 resources · 893 anchors",
    (
        "RUN-193: Fleet Fuel index/CSV Site-privacy defect reproduced and "
        "remediated in exactly two transferred paths"
    ),
    "RUN-193R: exact remediation artifacts independently reviewed GO",
    "RUN-194: Fuel historical-remediated record added",
    (
        "16 retained claim identities split into 8 current provisional P1, 2 "
        "historical already-fixed, and 6 historical remediated"
    ),
    "161/2,609 unique bounded disposition total",
    "RUN-071–194 current reporting checkpoint",
    "index 84 is not recredited",
    "index 85 fleet-assets.trips.playback is integrated",
    "next index 86 RUN090-ROUTE-0087 / RUN077-ROUTE-0695",
    "fleet-assets.trips.playback.data",
    "candidate index 87 pending behind index 86",
    "FLEET-FUEL-INDEX-SITE-PRIVACY-01",
    (
        "Before remediation, a user could receive foreign-Site fuel rows, "
        "row-attached vehicle and logger identity, notes, vehicle choices, CSV "
        "data, month-to-date fuel totals, a rolling 30-day entry count, and "
        "per-vehicle efficiency information."
    ),
    (
        "historical issue · remediated on local main · not published to "
        "origin/main · selected GET index/CSV Site privacy and row-scoped "
        "attached identity only · candidate feature association only · index "
        "87 static route owner and action bridge remain pending behind index "
        "86 playback.data · zero static ownership, adjacent-route, or "
        "independent logger-Site inheritance · not a final finding"
    ),
    "baseline 6 failed + 0 passed / 65 assertions",
    "unique post-merge focused 6/206",
    "supporting 20/215 regressions",
    (
        "baseline df65322f8eb7 · fix 2ec4b70e379c · local merge 04c32c36fdda · "
        "origin/main c39b07654705 unchanged"
    ),
    "2/340 mappings",
    "0/340 final no-match/NCM",
    "338 unresolved targets",
    "Gate 4 and audit completion false",
    "Fresh RUN-195 audit-dashboard verification required",
    "dashboard HTML frozen pending RUN-195",
    (
        "RUN-195 responsive audit-dashboard verification receipt (forward "
        "reference until materialized; intentionally unhashed)"
    ),
    (
        "RUN-195 audit-dashboard verification materializer (forward reference; "
        "intentionally unhashed)"
    ),
    (
        "no prior viewport, overflow, navigation, table, link, anchor, or console "
        "proof transfers to the current RUN-194 reporting sources or the RUN-195 "
        "dashboard generated from them"
    ),
    "None supplies audit-dashboard verification for the new RUN-195 HTML.",
    "The linked RUN-195 receipt must record",
    "It verifies the RUN-194 audit artifact only",
]
FUTURE_LINKS = sorted([MATERIALIZER, OUTPUT])
EXPECTED_UNIQUE_LOCAL_RESOURCES = 491
EXPECTED_HASHED_LINK_PAIRS = 805
EXPECTED_HASHED_FILE_OCCURRENCES = 803
EXPECTED_UNIQUE_HASHED_FILES = 417
EXPECTED_TASK_SCRIPT_DIRECTORY_OCCURRENCES = 2
EXPECTED_TASK_SCRIPT_BUNDLE_SHA256 = (
    "4171e361c5abc17a63af20cc04133826977b6a6b9c11af9e8d528a7815a4ea33"
)
SUMMARY_TIMELINE_RESERVED_PATHS = [
    "app/Http/Controllers/SummaryController.php",
    "app/Http/Controllers/TimelineController.php",
    "app/Jobs/GenerateSummaryJob.php",
    "tests/Feature/Security/SummaryRagTimelineAuthorizationTest.php",
]
MY_DAY_RESERVED_PATHS = [
    "app/Http/Controllers/MyTasksController.php",
    "app/Http/Controllers/MyDayActionsController.php",
    "app/Services/ControlRoom/ControlRoomAlertLifecycleService.php",
    "tests/Feature/ControlRoom/MyDayControlRoomSlaTest.php",
]
FLEET_FUEL_FINDING = "FLEET-FUEL-INDEX-SITE-PRIVACY-01"


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
    assert (
        json.dumps(value, ensure_ascii=False, indent=2, allow_nan=False) + "\n"
    ).encode("utf-8") == raw
    return value


def strict_json_path(path: Path, label: str) -> dict[str, Any]:
    return strict_json_bytes(path.read_bytes(), label)


def strict_json(relative: str) -> dict[str, Any]:
    return strict_json_path(AUDIT / relative, relative)


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


def verify_self_seal(value: dict[str, Any], expected: str) -> None:
    without_seal = dict(value)
    observed = without_seal.pop("receipt_self_seal_sha256")
    assert observed == expected
    assert canonical_sha256(without_seal) == expected


def assert_exact_structure(value: Any, expected: Any, label: str) -> None:
    assert type(value) is type(expected), (
        f"unexpected {label} type: {type(value).__name__} != {type(expected).__name__}"
    )
    if isinstance(expected, dict):
        assert list(value) == list(expected), (
            f"unexpected {label} key order: {list(value)} != {list(expected)}"
        )
        for key in expected:
            assert_exact_structure(value[key], expected[key], f"{label}.{key}")
        return
    if isinstance(expected, list):
        assert len(value) == len(expected), (
            f"unexpected {label} length: {len(value)} != {len(expected)}"
        )
        for index, expected_item in enumerate(expected):
            assert_exact_structure(value[index], expected_item, f"{label}[{index}]")
        return
    assert value == expected, f"unexpected {label}: {value!r} != {expected!r}"


def validate_browser_observation(
    path: Path,
    html_validation: dict[str, Any],
) -> tuple[dict[str, Any], dict[str, Any]]:
    resolved = path.expanduser().resolve(strict=True)
    assert resolved.is_file(), f"browser observation is not a file: {resolved}"
    try:
        resolved.relative_to(ROOT.resolve())
    except ValueError:
        pass
    else:
        raise AssertionError("browser observation input must remain outside the repository")

    assert FINAL_BROWSER_PROVIDER_ID is not None
    assert FINAL_BROWSER_TAB_ID is not None
    assert FINAL_BROWSER_OBSERVED_AT is not None
    assert re.fullmatch(
        r"2026-09-01T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\+12:00",
        FINAL_BROWSER_OBSERVED_AT,
    )

    label = str(resolved)
    raw = resolved.read_bytes()
    observation = strict_json_bytes(raw, label)
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
    observed_seal = without_seal.pop("observation_self_seal_sha256")
    assert type(observed_seal) is str and re.fullmatch(r"[0-9a-f]{64}", observed_seal)
    assert canonical_sha256(without_seal) == observed_seal
    assert_exact_structure(
        observation["schema_version"], BROWSER_OBSERVATION_SCHEMA, "schema_version"
    )
    assert_exact_structure(observation["run_id"], RUN_ID, "run_id")
    assert_exact_structure(
        observation["observed_at"], FINAL_BROWSER_OBSERVED_AT, "observed_at"
    )

    browser = observation["browser"]
    assert_exact_structure(
        browser,
        {
            "name": "Codex in-app browser",
            "provider_id": FINAL_BROWSER_PROVIDER_ID,
            "tab_id": FINAL_BROWSER_TAB_ID,
        },
        "browser",
    )
    tab_id = browser["tab_id"]
    provider_id = browser["provider_id"]

    dashboard_raw = (AUDIT / HTML).read_bytes()
    dashboard_sha = sha256(dashboard_raw)
    assert dashboard_sha == FINAL_RUN_195_DASHBOARD["sha256"]
    artifact = observation["artifact"]
    assert_exact_structure(
        artifact,
        {
            "url": f"http://127.0.0.1:{SERVER_PORT}/audit-dashboard.html",
            "document_title": "Oblivion Findings current-source audit",
            "server_host": "127.0.0.1",
            "server_port": SERVER_PORT,
            "server_pid": SERVER_PID,
            "server_executable": SERVER_EXECUTABLE,
            "http_status": 200,
            "response_content_length": len(dashboard_raw),
            "browser_fetched_sha256": dashboard_sha,
            "observed_at": FINAL_BROWSER_OBSERVED_AT,
        },
        "artifact",
    )

    viewport_records = observation["viewports"]
    assert type(viewport_records) is dict
    assert list(viewport_records) == VIEWPORTS
    for viewport in VIEWPORTS:
        record = viewport_records[viewport]
        assert_exact_structure(
            record,
            {
                "requested": viewport,
                "actual": viewport,
                "provider_id": provider_id,
                "tab_id": tab_id,
                "dashboard_sha256": dashboard_sha,
                "observed_at": FINAL_BROWSER_OBSERVED_AT,
                "visible_text_checks": [
                    {"text": boundary, "visible": True}
                    for boundary in BROWSER_VISIBLE_TEXT_BOUNDARIES
                ],
                "visible_text_passed": len(BROWSER_VISIBLE_TEXT_BOUNDARIES),
                "visible_text_total": len(BROWSER_VISIBLE_TEXT_BOUNDARIES),
                "anchor_elements": html_validation["anchor_element_count"],
                "fragment_anchors": html_validation["fragment_anchor_count"],
                "authored_ids": html_validation["authored_id_count"],
                "browser_dom_ids": html_validation["authored_id_count"] + 1,
                "browser_only_injected_id_count": 1,
                "duplicate_ids": [],
                "headings": html_validation["heading_count"],
                "sections": html_validation["section_count"],
                "navigation_links": len(NAVIGATION),
                "visible_navigation_links": len(NAVIGATION),
                "tables": html_validation["table_count"],
                "table_wraps": html_validation["table_wrap_count"],
                "table_containment_failures": 0,
                "unique_local_resources": html_validation["unique_local_resources"],
                "local_relative_link_occurrences": html_validation[
                    "local_relative_link_occurrences"
                ],
                "missing_fragments": [],
                "page_horizontal_overflow": False,
            },
            f"viewports.{viewport}",
        )

    navigation = observation["navigation"]
    assert_exact_structure(
        navigation,
        [
            {
                "label": label,
                "expected_hash": target,
                "observed_hash": target,
                "browser_click_performed": True,
                "loaded_url": (
                    f"http://127.0.0.1:{SERVER_PORT}/audit-dashboard.html{target}"
                ),
                "final_url": (
                    f"http://127.0.0.1:{SERVER_PORT}/audit-dashboard.html{target}"
                ),
                "target_exists": True,
                "target_visible": True,
                "provider_id": provider_id,
                "tab_id": tab_id,
                "dashboard_sha256": dashboard_sha,
                "observed_at": FINAL_BROWSER_OBSERVED_AT,
            }
            for label, target in NAVIGATION
        ],
        "navigation",
    )

    console = observation["console"]
    assert_exact_structure(
        console,
        {
            "messages": [],
            "page_errors": [],
            "warning_or_error_logs": [],
            "provider_id": provider_id,
            "tab_id": tab_id,
            "dashboard_sha256": dashboard_sha,
            "observed_at": FINAL_BROWSER_OBSERVED_AT,
        },
        "console",
    )

    assert_exact_structure(
        observation["visual_checks"],
        {
            "desktop_result": VISUAL_GO,
            "mobile_result": VISUAL_GO,
            "mobile_navigation_horizontally_scrollable_at_390x844": True,
            "provider_id": provider_id,
            "tab_id": tab_id,
            "dashboard_sha256": dashboard_sha,
            "observed_at": FINAL_BROWSER_OBSERVED_AT,
        },
        "visual_checks",
    )
    assert_exact_structure(
        observation["screenshots"],
        [
            {
                "viewport": viewport,
                "provider_id": provider_id,
                "tab_id": tab_id,
                "dashboard_sha256": dashboard_sha,
                "observed_at": FINAL_BROWSER_OBSERVED_AT,
                "captured": True,
                "retained": False,
                "visual_inspection": VISUAL_GO,
            }
            for viewport in VIEWPORTS
        ],
        "screenshots",
    )
    assert_exact_structure(
        observation["deliverable"],
        {
            "dashboard_tab_marked_deliverable": True,
            "current_exact_dashboard_tab_retained": True,
            "browser_viewport_override_reset": True,
            "provider_id": provider_id,
            "tab_id": tab_id,
            "dashboard_sha256": dashboard_sha,
            "observed_at": FINAL_BROWSER_OBSERVED_AT,
        },
        "deliverable",
    )

    return observation, {
        "path": str(resolved),
        "sha256": sha256(raw),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
        "observation_self_seal_sha256": observed_seal,
    }


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
    tree = ast.parse(source, filename=str(AUDIT / BUILDER))
    matches: list[tuple[str, ...]] = []
    for node in ast.walk(tree):
        if not isinstance(node, ast.Assign):
            continue
        if not any(
            isinstance(target, ast.Name) and target.id == name
            for target in node.targets
        ):
            continue
        value = ast.literal_eval(node.value)
        assert isinstance(value, list)
        assert all(isinstance(item, str) for item in value)
        matches.append(tuple(value))
    assert len(matches) == 1
    return matches[0]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--final-http-head-verified-count", type=int)
    parser.add_argument("--final-http-head-failure-count", type=int)
    parser.add_argument("--listeners-after-cleanup", type=int)
    parser.add_argument(
        "--exact-server-pid-present-after-cleanup", choices=("true", "false")
    )
    parser.add_argument("--matching-loopback-processes-after-cleanup", type=int)
    parser.add_argument("--browser-observation-file", type=Path)
    return parser.parse_args()


def finalization_inputs(
    args: argparse.Namespace,
    html_validation: dict[str, Any],
) -> dict[str, Any]:
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

    browser_observation: dict[str, Any] | None = None
    browser_observation_input: dict[str, Any] | None = None
    if args.browser_observation_file is not None:
        browser_observation, browser_observation_input = validate_browser_observation(
            args.browser_observation_file,
            html_validation,
        )
    browser_complete = browser_observation is not None

    return {
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
        "browser_complete": browser_complete,
        "browser_observation": browser_observation,
        "browser_observation_input": browser_observation_input,
        "final_navigation_verified_count": len(NAVIGATION) if browser_complete else None,
        "final_browser_warning_error_count": 0 if browser_complete else None,
        "dashboard_tab_marked_deliverable": True if browser_complete else None,
    }


def validate_repository_state() -> None:
    assert git("rev-parse", "HEAD") == RUN_194_COMMIT
    assert git("rev-parse", "main") == RUN_194_COMMIT
    assert git("show", "-s", "--format=%T", "HEAD") == RUN_194_TREE
    assert git("show", "-s", "--format=%P", "HEAD") == RUN_194_PARENT
    assert git("show", "-s", "--format=%s", "HEAD") == (
        "audit: report RUN194 Fleet fuel privacy remediation"
    )
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
        [f"{PREFIX}/{HTML}", f"{PREFIX}/{BUILDER}"]
    )
    assert committed_record(RUN_194_COMMIT, BUILDER) == COMMITTED_RUN_194_BUILDER
    assert committed_record(RUN_194_COMMIT, HTML) == COMMITTED_RUN_192_DASHBOARD
    assert file_record(BUILDER) == FINAL_RUN_195_BUILDER
    assert file_record(HTML) == FINAL_RUN_195_DASHBOARD
    assert diff_record(BUILDER) == FINAL_RUN_195_BUILDER_DIFF
    assert diff_record(HTML) == FINAL_RUN_195_DASHBOARD_DIFF
    assert git(
        "diff", "--name-only", RUN_194_PARENT, "HEAD", "--",
        *SUMMARY_TIMELINE_RESERVED_PATHS
    ) == ""
    assert git(
        "diff", "--name-only", RUN_194_PARENT, "HEAD", "--",
        *MY_DAY_RESERVED_PATHS
    ) == ""


def validate_run_194() -> dict[str, Any]:
    assert file_record(RUN_194_MATERIALIZER) == RUN_194_MATERIALIZER_RECORD
    assert file_record(RUN_194_RECEIPT) == RUN_194_RECEIPT_RECORD
    assert committed_record(RUN_194_COMMIT, RUN_194_MATERIALIZER) == (
        RUN_194_MATERIALIZER_RECORD
    )
    assert committed_record(RUN_194_COMMIT, RUN_194_RECEIPT) == (
        RUN_194_RECEIPT_RECORD
    )
    assert file_record(FINDINGS) == COMMITTED_FINDINGS
    run_194 = strict_json(RUN_194_RECEIPT)
    verify_self_seal(run_194, RUN_194_RECEIPT_SELF_SEAL)
    assert run_194["run_id"] == (
        "RUN-194-FLEET-FUEL-INDEX-SITE-PRIVACY-01-REMEDIATION-REPORTING-WAVE-38"
    )
    transition = run_194["reporting_transition"]
    assert transition["finding_id"] == FLEET_FUEL_FINDING
    assert transition["authorized_by_run_193r"] is True
    assert transition["transition"] == (
        "ABSENT_TO_HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
    )
    assert transition["static_ownership_credit"] is False
    assert transition["counts_after"] == {
        "retained_claim_records": 16,
        "provisional_source_claims": 8,
        "historical_already_fixed": 2,
        "historical_remediated": 6,
        "final_P0": 0,
        "final_P1": 0,
    }
    assert run_194["dashboard_forward_gate"] == {
        "required_run": "RUN-195",
        "generator": MATERIALIZER,
        "receipt": OUTPUT,
        "dashboard_html_changed_by_run_194": False,
        "fresh_four_viewport_navigation_resource_console_verification_required": True,
        "forward_paths_intentionally_unhashed": True,
    }
    assert len(run_194["completion_boundary"]) == 26
    assert all(value is False for value in run_194["completion_boundary"].values())
    assert run_194["credit_boundary"]["live_findings_register_and_reporting_status"] is True
    assert all(
        value is False
        for key, value in run_194["credit_boundary"].items()
        if key != "live_findings_register_and_reporting_status"
    )
    assert run_194["audit_completion_test_met"] is False

    findings = strict_json(FINDINGS)
    records = findings["records"]
    statuses = Counter(record["record_status"] for record in records)
    assert len(records) == len({record["id"] for record in records}) == 16
    assert statuses == {
        "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING": 8,
        "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING": 2,
        "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING": 6,
    }
    counts = findings["counts"]
    assert counts["retained_claim_records"] == 16
    assert counts["bounded_disposition_tests_passed"] == 161
    assert counts["bounded_disposition_assertions"] == 2609
    assert counts["benchmark_mapped"] == 2
    assert counts["final_no_match"] == 0
    assert counts["benchmark_unresolved"] == 338
    return run_194


def validate_builder_and_html() -> dict[str, Any]:
    builder_source = strict_text((AUDIT / BUILDER).read_bytes(), BUILDER)
    html_text = strict_text((AUDIT / HTML).read_bytes(), HTML)
    source_literal_boundaries = literal_list_assignment(
        builder_source, "current_visible_boundaries"
    )
    assert len(source_literal_boundaries) == len(set(source_literal_boundaries)) == 193
    assert all(boundary in html_text for boundary in source_literal_boundaries)
    expected_navigation_markup = (
        '<nav aria-label="Audit sections"><div>'
        + "".join(
            f'<a href="{target}">{label}</a>' for label, target in NAVIGATION
        )
        + "</div></nav>"
    )
    assert html_text.count(expected_navigation_markup) == 1
    assert '<a href="#checkpoint">RUN-194</a>' in html_text
    required_current = [
        "RUN-192–194 Fleet Fuel index Site-privacy remediation reporting checkpoint",
        "16 retained claim identities split into 8 current provisional P1, 2 historical already-fixed, and 6 historical remediated",
        "161/2,609 unique bounded disposition total",
        "RUN-071–194 current reporting checkpoint",
        "candidate index 87 pending behind index 86",
        FLEET_FUEL_FINDING,
        "2/340 mappings",
        "0/340 final no-match/NCM",
        "338 unresolved targets",
        "one operating organisation across multiple Sites",
        "Gate 4 and audit completion false",
        "Fresh RUN-195 audit-dashboard verification required",
    ]
    assert all(value in html_text for value in required_current)
    stale_current = [
        "RUN-071–191 current reporting checkpoint",
        "RUN-071–191 completion-gate checkpoint",
        "RUN-071–191 evidence lineage",
        "Every current raw, generated, reviewed, and integrated RUN-077–191",
        "current RUN-191 split of 15 retained claim identities",
        "Fresh RUN-192 audit-dashboard verification required",
        "RUN-071–184 current reporting checkpoint",
        "RUN-071–184 completion-gate checkpoint",
        "RUN-071–184 evidence lineage",
        "None supplies audit-dashboard verification for the new RUN-185 HTML.",
        "Every current raw, generated, reviewed, and integrated RUN-077–183R",
        "12 retained claim identities (8 current provisional + 2 historical already-fixed + 4 historical remediated)",
        "visible 664/307/357 ownership, 95 bridges, 118/389 queue accounting",
        "current RUN-177 reporting sources or the RUN-178 dashboard",
        "new RUN-188 HTML",
        "The linked RUN-188 receipt must record",
        "It verifies the RUN-187 audit artifact only",
        '<a href="generators/materialize-run-188-audit-dashboard-verification-wave-36.py">RUN-188 audit-dashboard verification materializer</a> <span>forward generator',
        '<a href="evidence/browser/current-audit-dashboard-verification-run-188-wave-36.json">RUN-188 audit-dashboard verification receipt</a> <span>forward receipt',
    ]
    assert all(value not in html_text for value in stale_current)
    assert "<title>Oblivion Findings current-source audit</title>" in html_text

    parser = Parser()
    parser.feed(html_text)
    normalized_visible_text = re.sub(r"\s+", " ", " ".join(parser.text_parts)).strip()
    assert all(
        boundary in normalized_visible_text
        for boundary in BROWSER_VISIBLE_TEXT_BOUNDARIES
    )
    assert len(parser.hrefs) == 945
    assert len(parser.anchor_hrefs) == 944
    assert len(parser.ids) == len(set(parser.ids)) == 10
    assert parser.headings == 26
    assert parser.sections == 26
    assert parser.tables == 10
    assert parser.table_wraps == 10
    fragment_hrefs = [href for href in parser.anchor_hrefs if href.startswith("#")]
    assert len(fragment_hrefs) == 10
    assert all(href[1:] in parser.ids for href in fragment_hrefs)
    local_hrefs = [href for href in parser.hrefs if is_local(href)]
    targets = {local_path(href) for href in local_hrefs}
    assert len(local_hrefs) == 934
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
    hashed_file_pairs = [pair for pair in hash_pairs if not pair[0].endswith("/")]
    hashed_directory_pairs = [pair for pair in hash_pairs if pair[0].endswith("/")]
    assert len(hashed_file_pairs) == EXPECTED_HASHED_FILE_OCCURRENCES
    assert len({href for href, _ in hashed_file_pairs}) == EXPECTED_UNIQUE_HASHED_FILES
    assert hashed_directory_pairs == [
        ("task-scripts/", EXPECTED_TASK_SCRIPT_BUNDLE_SHA256)
    ] * EXPECTED_TASK_SCRIPT_DIRECTORY_OCCURRENCES
    for href, displayed_sha256 in hashed_file_pairs:
        assert is_local(href), href
        target = local_path(href)
        assert target.is_file(), href
        assert sha256(target.read_bytes()) == displayed_sha256, href
    return {
        "source_literal_boundary_count": len(source_literal_boundaries),
        "browser_visible_text_boundary_count": len(BROWSER_VISIBLE_TEXT_BOUNDARIES),
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
        "hashed_file_occurrences": len(hashed_file_pairs),
        "unique_hashed_files": len({href for href, _ in hashed_file_pairs}),
        "hashed_directory_occurrences": len(hashed_directory_pairs),
        "hash_mismatches": [],
        "ordered_navigation_label_hash_pairs_verified": True,
    }


def build_receipt(
    run_194: dict[str, Any],
    html_validation: dict[str, Any],
    finalization: dict[str, Any],
) -> dict[str, Any]:
    browser = finalization["browser_observation"]
    browser_summary: dict[str, Any] | None = None
    if browser is not None:
        browser_summary = {
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
                    "anchor_elements": browser["viewports"][viewport][
                        "anchor_elements"
                    ],
                    "fragment_anchors": browser["viewports"][viewport][
                        "fragment_anchors"
                    ],
                    "authored_ids": browser["viewports"][viewport]["authored_ids"],
                    "browser_dom_ids": browser["viewports"][viewport][
                        "browser_dom_ids"
                    ],
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
            "observation_self_seal_sha256": browser[
                "observation_self_seal_sha256"
            ],
        }

    completion_names = [
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
    artifact_complete = bool(
        finalization["browser_complete"]
        and finalization["resource_complete"]
        and finalization["cleanup_complete"]
    )
    receipt = {
        "schema_version": "run-195-audit-dashboard-verification-wave-38-v1",
        "run_id": RUN_ID,
        "generated_at": None,
        "pins": {
            "run_194_commit": RUN_194_COMMIT,
            "run_194_tree": RUN_194_TREE,
            "run_194_parent": RUN_194_PARENT,
            "origin_main": ORIGIN_MAIN,
            "local_main_ahead": LOCAL_MAIN_AHEAD,
            "local_main_behind": LOCAL_MAIN_BEHIND,
            "run_194_materializer": RUN_194_MATERIALIZER_RECORD,
            "run_194_receipt": RUN_194_RECEIPT_RECORD,
            "run_194_receipt_self_seal_sha256": RUN_194_RECEIPT_SELF_SEAL,
            "committed_findings": COMMITTED_FINDINGS,
            "committed_run_194_builder": COMMITTED_RUN_194_BUILDER,
            "committed_run_192_dashboard": COMMITTED_RUN_192_DASHBOARD,
            "final_run_195_builder": FINAL_RUN_195_BUILDER,
            "final_run_195_builder_diff": FINAL_RUN_195_BUILDER_DIFF,
            "final_run_195_dashboard": FINAL_RUN_195_DASHBOARD,
            "final_run_195_dashboard_diff": FINAL_RUN_195_DASHBOARD_DIFF,
            "run_195_materializer": file_record(MATERIALIZER),
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
                "records": 16,
                "provisional": 8,
                "historical_already_fixed": 2,
                "historical_remediated": 6,
                "bounded_tests": 161,
                "bounded_assertions": 2609,
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
            "from_dashboard_sha256": COMMITTED_RUN_192_DASHBOARD["sha256"],
            "to_dashboard_sha256": FINAL_RUN_195_DASHBOARD["sha256"],
            "reported_finding_id": FLEET_FUEL_FINDING,
            "run_194_forward_gate_satisfied_by_this_artifact": artifact_complete,
            "static_ownership_or_queue_advance": False,
            "new_application_finding": False,
        },
        "generation": {
            "builder": FINAL_RUN_195_BUILDER,
            "dashboard": FINAL_RUN_195_DASHBOARD,
            "builder_diff": FINAL_RUN_195_BUILDER_DIFF,
            "dashboard_diff": FINAL_RUN_195_DASHBOARD_DIFF,
            "builder_executed_twice_with_identical_dashboard_bytes": True,
            "source_literal_boundaries": html_validation[
                "source_literal_boundary_count"
            ],
            "output_utf8_lf": True,
        },
        "superseded_run_192_observation": {
            "dashboard": COMMITTED_RUN_192_DASHBOARD,
            "superseded_by_dashboard_sha256": FINAL_RUN_195_DASHBOARD["sha256"],
            "no_current_browser_credit_inherited": True,
        },
        "current_browser_input": finalization["browser_observation_input"],
        "current_browser_verification": browser_summary,
        "html_graph": html_validation,
        "http_head_verification": {
            "expected_unique_resources": EXPECTED_UNIQUE_LOCAL_RESOURCES,
            "verified_count": finalization["final_http_head_verified_count"],
            "failure_count": finalization["final_http_head_failure_count"],
            "complete": finalization["resource_complete"],
        },
        "server_cleanup": {
            "temporary_loopback_host": "127.0.0.1",
            "temporary_loopback_port": SERVER_PORT,
            "temporary_server_pid": SERVER_PID,
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
            "finding_id": FLEET_FUEL_FINDING,
            "record_status": (
                "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
            ),
            "selected_get_index_and_csv_site_privacy_only": True,
            "candidate_feature_association_only": True,
            "static_ownership_credit": False,
            "adjacent_route_credit": False,
            "independent_logger_site_inheritance_credit": False,
            "final_finding": False,
        },
        "execution_boundary": {
            "bounded_unique_tests": 161,
            "bounded_unique_assertions": 2609,
            "run_195_application_runtime_or_tests_executed": False,
            "run_195_browser_scope": "static audit-dashboard HTML only",
            "full_suite_or_coverage": False,
        },
        "benchmark_boundary": {
            "mapped": 2,
            "targets": 340,
            "final_no_match_or_NCM": 0,
            "unresolved": 338,
            "run_195_benchmark_credit": False,
        },
        "noninheritance": {
            "application_browser": False,
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
            "summary_timeline_reserved_paths_unchanged": True,
            "my_day_reserved_paths_unchanged": True,
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
            "exact_run_195_dashboard_artifact_verification": artifact_complete,
            "application_source_or_test_change": False,
            "application_runtime_reexecution": False,
            "application_browser": False,
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
                "reason": "RUN195 verifies one exact dashboard artifact only",
            }
            for name in completion_names
        ],
        "artifact_scope": {
            "materializer": MATERIALIZER,
            "receipt": OUTPUT,
            "builder": BUILDER,
            "dashboard": HTML,
            "path_count": 4,
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
    assert run_194["audit_completion_test_met"] is False
    return receipt


def write_receipt(receipt: dict[str, Any]) -> None:
    assert_finite(receipt)
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    raw = (
        json.dumps(receipt, ensure_ascii=False, indent=2, allow_nan=False) + "\n"
    ).encode("utf-8")
    output_path = AUDIT / OUTPUT
    temporary = output_path.with_name(f".{output_path.name}.tmp-run195")
    assert not temporary.exists(), f"stale receipt temp: {temporary}"
    try:
        with temporary.open("xb") as handle:
            handle.write(raw)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, output_path)
    finally:
        if temporary.exists():
            temporary.unlink()
    observed = strict_json(OUTPUT)
    verify_self_seal(observed, receipt["receipt_self_seal_sha256"])
    assert file_record(OUTPUT)["sha256"] == sha256(raw)
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
    run_194 = validate_run_194()
    html_validation = validate_builder_and_html()
    finalization = finalization_inputs(args, html_validation)
    receipt = build_receipt(run_194, html_validation, finalization)
    write_receipt(receipt)


if __name__ == "__main__":
    main()
