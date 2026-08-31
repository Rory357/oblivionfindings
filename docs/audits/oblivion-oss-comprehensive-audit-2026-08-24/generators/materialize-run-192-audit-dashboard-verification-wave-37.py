#!/usr/bin/env python3
"""Seal bounded RUN192 facts for the exact RUN191 audit dashboard.

This producer validates the committed RUN191 reporting inputs, the narrow
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
    raise RuntimeError("RUN192 materializer refuses optimized Python; assertions are required")


SCRIPT = Path(__file__).resolve()
AUDIT = SCRIPT.parent.parent
ROOT = next(parent for parent in SCRIPT.parents if (parent / ".git").exists())
PREFIX = AUDIT.relative_to(ROOT).as_posix()
MATERIALIZER = SCRIPT.relative_to(AUDIT).as_posix()
OUTPUT = "evidence/browser/current-audit-dashboard-verification-run-192-wave-37.json"
HTML = "audit-dashboard.html"
BUILDER = "generators/build-current-audit-dashboard.py"
FINDINGS = "findings.json"
RUN_191_MATERIALIZER = (
    "generators/materialize-run-191-reviewed-fleet-trip-playback-route-action-"
    "reporting-wave-37.py"
)
RUN_191_RECEIPT = (
    "evidence/source/current-run-191-reviewed-fleet-trip-playback-route-action-"
    "reporting-wave-37.json"
)

RUN_ID = "RUN-192-AUDIT-DASHBOARD-VERIFICATION-WAVE-37"
RUN_191_COMMIT = "df65322f8eb7d7d0f1623c4bcb8cc8c87573b71d"
RUN_191_TREE = "0bd43711942416069675075ce3d515b92b9eaf7d"
RUN_191_PARENT = "b35d267efd067ac8fab8c4ac8111dad993c65444"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"
LOCAL_MAIN_AHEAD = 53
LOCAL_MAIN_BEHIND = 0
SERVER_PORT = 43192
SERVER_PID = 39264
SERVER_EXECUTABLE = (
    "C:\\Users\\steph\\.cache\\codex-runtimes\\codex-primary-runtime\\"
    "dependencies\\python\\python.exe"
)

RUN_191_MATERIALIZER_RECORD = {
    "path": RUN_191_MATERIALIZER,
    "sha256": "f5a9bc02efa6927d83c38bd0c0f14dd4c65e143a2c85116888235aa5ef912c58",
    "git_blob_id": "aec37f7a0e59122c66968ca1cff6191e969a00f5",
    "bytes": 38963,
    "lines": 842,
}
RUN_191_RECEIPT_RECORD = {
    "path": RUN_191_RECEIPT,
    "sha256": "1c3d1b47bad10f601084d61e92cd1bedafad955800609ed845a57c0f4636dc15",
    "git_blob_id": "7154f43bafbc36124f705618c1c7505988831e0f",
    "bytes": 20172,
    "lines": 450,
}
RUN_191_RECEIPT_SELF_SEAL = (
    "0ebeb1a84398f2a5e5622e7cdcf91b1250c2b60c6b57138ece7c72c1dc791e27"
)
COMMITTED_FINDINGS = {
    "path": FINDINGS,
    "sha256": "91ccad95997c802f56c68a3cfc2678ae2364e7bad47c3f11ecaa55f4fc3e4843",
    "git_blob_id": "4b407f1137b121f6d5c0ad123bbd7a8fdb4223ce",
    "bytes": 643616,
    "lines": 11357,
}
COMMITTED_RUN_191_BUILDER = {
    "path": BUILDER,
    "sha256": "3fa7cb8be9a12d6e7c53999cb05a04187083ee1e44bf3646690607b10d4dd4aa",
    "git_blob_id": "e52609fcc80802413dd1926fe9b315a5875688b6",
    "bytes": 732053,
    "lines": 6417,
}
FINAL_RUN_192_BUILDER = {
    "path": BUILDER,
    "sha256": "e2b5c461cd9f22e0dba35d3555788534a3d244ea40a3c3424d1b80b003a6c242",
    "git_blob_id": "4a79b8441c84d4824642f08c6ba9d7908e035184",
    "bytes": 735472,
    "lines": 6459,
}
FINAL_RUN_192_BUILDER_DIFF = {
    "path": BUILDER,
    "binary_diff_sha256": (
        "19f562706db88ceca377644ce08cff1c71a432d20d7a8ec7eb351deebae1dd49"
    ),
    "numstat": {"added": 70, "deleted": 28},
}
COMMITTED_RUN_188_DASHBOARD = {
    "path": HTML,
    "sha256": "3d65bd82b8bc0f650158c4587f9618a03079f75d51e83496dc7d71addf257d79",
    "git_blob_id": "4c6dc53cc4070e626ff0489f4c80e4177709d4ae",
    "bytes": 314007,
    "lines": 78,
}
FINAL_RUN_192_DASHBOARD = {
    "path": HTML,
    "sha256": "8d19569e7bfb256edeecdc754e2bc47e2ddad3ecd8de099e3bb0dad9b50e313b",
    "git_blob_id": "fb0ba424878117bf1362aea77c892a00fda32b95",
    "bytes": 317284,
    "lines": 78,
}
FINAL_RUN_192_DASHBOARD_DIFF = {
    "path": HTML,
    "binary_diff_sha256": (
        "123432d7c980f010c238599b625e2156cba2b62a1a010038e91f878f38b0b5e5"
    ),
    "numstat": {"added": 16, "deleted": 16},
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
    ("RUN-191", "#checkpoint"),
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
FINAL_BROWSER_PROVIDER_ID: str | None = "-9245-4848-9bdf-727cba67d89e"
FINAL_BROWSER_TAB_ID: str | None = "18"
FINAL_BROWSER_OBSERVED_AT: str | None = "2026-09-01T08:12:13.589013+12:00"
BROWSER_VISIBLE_TEXT_BOUNDARIES = [
    "667 = 310 route + 357 page",
    "121 reviewed / 386 pending",
    "reviewed = 99 owned + 10 shared + 5 alias + 7 gap",
    "16.976330%",
    "3,262 records remain",
    "RUN-188–191 Fleet playback route/action ownership reporting checkpoint",
    "fleet-assets.vehicles.alerts-config",
    "RUN090-ROUTE-0084 / RUN077-ROUTE-0692",
    "VehicleController::alertsConfig",
    "CAP-FLEET-VEHICLE-REGISTER",
    "index 84 is not recredited",
    "index 85 fleet-assets.trips.playback is integrated",
    "next index 86 RUN090-ROUTE-0087 / RUN077-ROUTE-0695",
    "fleet-assets.trips.index",
    "fleet-assets.trips.playback",
    "fleet-assets.trips.playback.data",
    (
        "RUN-183: Fleet trip-playback page/data Site-privacy defect reproduced and "
        "remediated in exactly two transferred paths"
    ),
    "RUN-184: Fleet trip-playback historical-remediated record added",
    (
        "RUN-186: MON-METRIC-REPLAY-DEDUPE-01 initial remediation later adjudicated "
        "NO-GO and corrective remediation integrated"
    ),
    "only final post-corrective-merge 56/472 counted once",
    "RUN-188: exact RUN-187 dashboard verified at 4/4 viewports",
    (
        "RUN-189/R: index 85 fleet-assets.trips.playback / FleetTripController::show "
        "independently reviewed OWNER twice"
    ),
    (
        "RUN-190: exactly one playback/show route owner and controller-action bridge "
        "integrated"
    ),
    "RUN-191: live static ledger reported",
    (
        "15 retained claim identities split into 8 current provisional P1, 2 "
        "historical already-fixed, and 5 historical remediated"
    ),
    "155/2,403 unique bounded disposition total",
    "2/340 mappings",
    "0/340 final no-match/NCM",
    "338 unresolved targets",
    "Gate 4 and audit completion false",
]
FUTURE_LINKS = sorted([MATERIALIZER, OUTPUT])
EXPECTED_UNIQUE_LOCAL_RESOURCES = 476
EXPECTED_HASHED_LINK_PAIRS = 765
EXPECTED_HASHED_FILE_OCCURRENCES = 763
EXPECTED_UNIQUE_HASHED_FILES = 397
EXPECTED_TASK_SCRIPT_DIRECTORY_OCCURRENCES = 2
EXPECTED_TASK_SCRIPT_BUNDLE_SHA256 = (
    "4171e361c5abc17a63af20cc04133826977b6a6b9c11af9e8d528a7815a4ea33"
)
CALENDAR_RESERVED_PATHS = [
    "app/Jobs/SendEventReminderJob.php",
    "tests/Feature/Sites/Calendar/SiteCalendarReminderJobTest.php",
]
HAZARD_RESERVED_PATHS = [
    "app/Jobs/HazardOverdueJob.php",
    "tests/Feature/Sites/HazardOverdueJobSitePrivacyTest.php",
]
CHECKLIST_RESERVED_PATHS = [
    "app/Jobs/ChecklistDueJob.php",
    "tests/Feature/Sites/ChecklistDueJobRecipientPrivacyTest.php",
]
INSPECTION_RESERVED_PATHS = [
    "app/Jobs/InspectionDueJob.php",
    "tests/Feature/Sites/InspectionDueJobRecipientPrivacyTest.php",
]
RISK_REVIEW_RESERVED_PATHS = [
    "app/Console/Commands/CheckRiskReviews.php",
    "tests/Feature/Governance/RiskReviewReminderAuthorizationTest.php",
]
COMPLIANCE_REMINDER_RESERVED_PATHS = [
    "app/Domain/Governance/Jobs/SendComplianceReminder.php",
    "tests/Feature/Governance/ComplianceReminderQueuedRecipientAuthorizationTest.php",
]
VOTING_REMINDER_RESERVED_PATHS = [
    "app/Domain/Governance/Jobs/SendVotingReminder.php",
    "tests/Feature/Governance/VotingReminderQueuedAuthorizationTest.php",
]
ACTION_ESCALATION_RESERVED_PATHS = [
    "app/Domain/Governance/Jobs/EscalateOverdueActionItems.php",
    "tests/Feature/Governance/ActionItemEscalationRecipientAuthorizationTest.php",
]
ROADMAP_DIGEST_RESERVED_PATHS = [
    "app/Domain/Roadmap/Jobs/SendRoadmapDigestJob.php",
    "tests/Feature/Roadmap/RoadmapDigestRecipientAuthorizationTest.php",
]
HR_REPORT_RESERVED_PATHS = [
    "app/Domain/Hr/Jobs/RunHrScheduledReportsJob.php",
    "tests/Feature/Hr/HrScheduledReportRecipientRevalidationTest.php",
]
SECOND_CYCLE_RESERVED_FIXES = {
    "SITE-CALENDAR-REMINDER-TRIGGER-01": {
        "sealed_commit": "81e13d20a2b992d3b9c0cf105f9a710a4e22b04c",
        "paths": CALENDAR_RESERVED_PATHS,
    },
    "HS-HAZARD-REMINDER-SITE-PRIVACY-01": {
        "sealed_commit": "61de675f80b9b7a8b3d3a124d5ceca9f395101ab",
        "paths": HAZARD_RESERVED_PATHS,
    },
    "SITE-CHECKLIST-REMINDER-RECIPIENT-01": {
        "sealed_commit": "fe17fbd28bfae643e7c1b944fde2662a25909c31",
        "paths": CHECKLIST_RESERVED_PATHS,
    },
    "SITE-INSPECTION-DUE-RECIPIENT-PRIVACY-01": {
        "sealed_commit": "3e47842cb3e6de04c816396621657e038e9148ea",
        "paths": INSPECTION_RESERVED_PATHS,
    },
    "GOV-RISK-REVIEW-RECIPIENT-AUTHORITY-01": {
        "sealed_commit": "fa55272cb1cb08b912b18b24d6aec2c29166f6f2",
        "paths": RISK_REVIEW_RESERVED_PATHS,
    },
    "GOV-COMPLIANCE-QUEUED-RECIPIENT-REVALIDATION-01": {
        "sealed_commit": "4026c5de89ff2ce7ece6c731fa1ce5a881dd35d7",
        "paths": COMPLIANCE_REMINDER_RESERVED_PATHS,
    },
    "GOV-VOTING-REMINDER-QUEUE-REVALIDATION-01": {
        "sealed_commit": "30b8541483c20f0bb9ed30e251b6dcdc52912685",
        "paths": VOTING_REMINDER_RESERVED_PATHS,
    },
    "GOV-ACTION-ESCALATION-RECIPIENT-AUTHORIZATION-01": {
        "sealed_commit": "d87330143e1eb90c69d31889416c9301a6da7d71",
        "paths": ACTION_ESCALATION_RESERVED_PATHS,
    },
    "ROADMAP-DIGEST-RECIPIENT-AUTHORIZATION-01": {
        "sealed_commit": "28065e00754ee8a96a8f72841df3949c2c499f7d",
        "paths": ROADMAP_DIGEST_RESERVED_PATHS,
    },
    "HR-SCHEDULED-REPORT-RECIPIENT-REVALIDATION-01": {
        "sealed_commit": "2e2705904729d3392ddef6b6308649c4f4039044",
        "paths": HR_REPORT_RESERVED_PATHS,
    },
}
FLEET_FUEL_RESERVED_PATHS = [
    "app/Http/Controllers/FleetAssets/VehicleController.php",
    "tests/Feature/FleetAssets/FleetFuelIndexSitePrivacyTest.php",
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
    assert dashboard_sha == FINAL_RUN_192_DASHBOARD["sha256"]
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
    assert git("rev-parse", "HEAD") == RUN_191_COMMIT
    assert git("rev-parse", "main") == RUN_191_COMMIT
    assert git("show", "-s", "--format=%T", "HEAD") == RUN_191_TREE
    assert git("show", "-s", "--format=%P", "HEAD") == RUN_191_PARENT
    assert git("show", "-s", "--format=%s", "HEAD") == (
        "audit: materialize RUN191 playback ownership reporting"
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
    assert committed_record(RUN_191_COMMIT, BUILDER) == COMMITTED_RUN_191_BUILDER
    assert committed_record(RUN_191_COMMIT, HTML) == COMMITTED_RUN_188_DASHBOARD
    assert file_record(BUILDER) == FINAL_RUN_192_BUILDER
    assert file_record(HTML) == FINAL_RUN_192_DASHBOARD
    assert diff_record(BUILDER) == FINAL_RUN_192_BUILDER_DIFF
    assert diff_record(HTML) == FINAL_RUN_192_DASHBOARD_DIFF
    assert len(SECOND_CYCLE_RESERVED_FIXES) == 10
    assert sum(
        len(record["paths"]) for record in SECOND_CYCLE_RESERVED_FIXES.values()
    ) == 20
    for record in SECOND_CYCLE_RESERVED_FIXES.values():
        paths = record["paths"]
        assert git(
            "diff", "--name-only", RUN_191_PARENT, "HEAD", "--", *paths
        ) == ""
    assert git(
        "diff", "--name-only", RUN_191_COMMIT, "HEAD", "--", *FLEET_FUEL_RESERVED_PATHS
    ) == ""


def validate_run_191() -> dict[str, Any]:
    assert file_record(RUN_191_MATERIALIZER) == RUN_191_MATERIALIZER_RECORD
    assert file_record(RUN_191_RECEIPT) == RUN_191_RECEIPT_RECORD
    assert committed_record(RUN_191_COMMIT, RUN_191_MATERIALIZER) == (
        RUN_191_MATERIALIZER_RECORD
    )
    assert committed_record(RUN_191_COMMIT, RUN_191_RECEIPT) == (
        RUN_191_RECEIPT_RECORD
    )
    assert file_record(FINDINGS) == COMMITTED_FINDINGS
    run_191 = strict_json(RUN_191_RECEIPT)
    verify_self_seal(run_191, RUN_191_RECEIPT_SELF_SEAL)
    assert run_191["run_id"] == (
        "RUN-191-REVIEWED-FLEET-TRIP-PLAYBACK-ROUTE-ACTION-REPORTING-WAVE-37"
    )
    assert run_191["reporting_transition"] == {
        "reported_integrated_run": (
            "RUN-190-REVIEWED-OUTCOME-NEUTRAL-FLEET-TRIP-PLAYBACK-"
            "ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-37"
        ),
        "reported_independent_review_run": (
            "RUN-190R-INDEPENDENT-REVIEWED-OUTCOME-NEUTRAL-FLEET-TRIP-"
            "PLAYBACK-ROUTE-ACTION-OWNERSHIP-OVERLAY-REVIEW-WAVE-37"
        ),
        "selected_queue_index": 85,
        "selected_route_name": "fleet-assets.trips.playback",
        "selected_controller_action": "FleetTripController::show",
        "reported_existing_route_owner_records": 1,
        "reported_existing_controller_action_bridges": 1,
        "run_191_new_ownership_records": 0,
        "next_unresolved_index": 86,
        "next_unresolved_route_name": "fleet-assets.trips.playback.data",
        "next_unresolved_controller_action": "FleetTripController::playback",
    }
    assert run_191["reporting_snapshot"] == {
        "combined_counts": {
            "source_owner_records": 667,
            "route_owner_records": 310,
            "page_owner_records": 357,
            "static_controller_action_bridges": 98,
            "bounded_source_denominator": 3929,
            "bounded_source_residual": 3262,
            "bounded_source_ownership_percent": "16.976330",
            "route_universe": 3218,
            "route_residual": 2891,
            "page_universe": 711,
            "page_residual": 345,
            "feature_union": 256,
            "route_feature_ids": 64,
            "page_feature_ids": 242,
            "route_page_feature_overlap": 50,
        },
        "queue_accounting": {
            "direct_exact_queue_records": 507,
            "reviewed_queue_surface_rows": 121,
            "pending_unreviewed_queue_surface_rows": 386,
            "owner_queue_surface_rows": 99,
            "queue_surfaces_without_ownership": 408,
        },
        "finding_and_execution_accounting": {
            "finding_records": 15,
            "provisional_findings": 8,
            "historical_already_fixed": 2,
            "historical_remediated": 5,
            "bounded_execution_tests": 155,
            "bounded_execution_assertions": 2403,
            "final_P0": 0,
            "final_P1": 0,
        },
        "benchmark_accounting": {
            "benchmark_mapped": 2,
            "benchmark_targets": 340,
            "final_no_match_or_NCM": 0,
            "benchmark_unresolved": 338,
        },
    }
    assert run_191["identity"]["next_unresolved_queue_record_sha256"] == (
        "ed12617b478e0a22014fb6c81402e5cf79aa574720e8ef8e2ce93f198a099893"
    )
    assert run_191["dashboard_forward_gate"]["required_run"] == "RUN-192"
    assert run_191["dashboard_forward_gate"]["dashboard_html_changed_by_run_191"] is False
    assert len(run_191["completion_boundary"]) == 9
    assert all(value is False for value in run_191["completion_boundary"].values())
    assert run_191["credit_boundary"]["live_findings_register_and_reporting_status"] is True
    assert all(
        value is False
        for key, value in run_191["credit_boundary"].items()
        if key != "live_findings_register_and_reporting_status"
    )
    assert run_191["audit_completion_test_met"] is False

    findings = strict_json(FINDINGS)
    records = findings["records"]
    statuses = Counter(record["record_status"] for record in records)
    assert len(records) == len({record["id"] for record in records}) == 15
    assert statuses == {
        "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING": 8,
        "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING": 2,
        "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING": 5,
    }
    counts = findings["counts"]
    assert counts["retained_claim_records"] == 15
    assert counts["bounded_disposition_tests_passed"] == 155
    assert counts["bounded_disposition_assertions"] == 2403
    assert counts["benchmark_mapped"] == 2
    assert counts["final_no_match"] == 0
    assert counts["benchmark_unresolved"] == 338
    return run_191


def validate_builder_and_html() -> dict[str, Any]:
    builder_source = strict_text((AUDIT / BUILDER).read_bytes(), BUILDER)
    html_text = strict_text((AUDIT / HTML).read_bytes(), HTML)
    source_literal_boundaries = literal_list_assignment(
        builder_source, "current_visible_boundaries"
    )
    assert len(source_literal_boundaries) == len(set(source_literal_boundaries)) == 166
    assert all(boundary in html_text for boundary in source_literal_boundaries)
    expected_navigation_markup = (
        '<nav aria-label="Audit sections"><div>'
        + "".join(
            f'<a href="{target}">{label}</a>' for label, target in NAVIGATION
        )
        + "</div></nav>"
    )
    assert html_text.count(expected_navigation_markup) == 1
    assert '<a href="#checkpoint">RUN-191</a>' in html_text
    assert "next index 86 RUN090-ROUTE-0087 / RUN077-ROUTE-0695" in html_text
    assert "fleet-assets.trips.playback.data / FleetTripController::playback" in html_text
    assert "667 = 310 route + 357 page" in html_text
    assert "121 reviewed / 386 pending" in html_text
    assert "reviewed = 99 owned + 10 shared + 5 alias + 7 gap" in html_text
    assert "2/340 mappings" in html_text
    assert "0/340 final no-match/NCM" in html_text
    assert "338 unresolved targets" in html_text
    assert "one operating organisation across multiple Sites" in html_text
    assert "Gate 4 and audit completion false" in html_text
    assert "Fresh RUN-192 audit-dashboard verification required" in html_text
    assert "Fresh RUN-191 audit-dashboard verification required" not in html_text
    assert "<title>Oblivion Findings current-source audit</title>" in html_text
    assert (
        "RUN-181: live static ledger reported as 666 owners / 309 routes / 357 "
        "pages / 97 bridges · 120 reviewed / 387 pending / 409 without ownership"
        in html_text
    )
    assert (
        "<tr><td>RUN-181 live reporting</td><td><strong>666 owners / 309 routes / "
        "357 pages / 97 bridges · 120 reviewed / 387 pending / 409 without ownership"
        in html_text
    )
    assert "RUN-181: live static ledger reported as 667 owners" not in html_text
    assert "<tr><td>RUN-181 live reporting</td><td><strong>667 owners" not in html_text

    parser = Parser()
    parser.feed(html_text)
    normalized_visible_text = re.sub(r"\s+", " ", " ".join(parser.text_parts)).strip()
    assert all(
        boundary in normalized_visible_text
        for boundary in BROWSER_VISIBLE_TEXT_BOUNDARIES
    )
    assert len(parser.hrefs) == 894
    assert len(parser.anchor_hrefs) == 893
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
    assert len(local_hrefs) == 883
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
    run_191: dict[str, Any],
    html_validation: dict[str, Any],
    finalization: dict[str, Any],
) -> dict[str, Any]:
    resource_complete = finalization["resource_complete"]
    cleanup_complete = finalization["cleanup_complete"]
    browser_complete = finalization["browser_complete"]
    browser_observation = finalization["browser_observation"]
    all_complete = resource_complete and cleanup_complete and browser_complete
    receipt: dict[str, Any] = {
        "schema_version": "oblivion-audit-dashboard-verification-v1",
        "run_id": RUN_ID,
        "status": (
            "VERIFIED_EXACT_ARTIFACT_ONLY"
            if all_complete
            else "PENDING_BROWSER_FINALIZATION_EXACT_ARTIFACT_UNCREDITED"
        ),
        "materialized_on": "2026-09-01",
        "architecture_rule": (
            "single operating organisation across multiple Sites; authorization "
            "uses approved Site access, canonical ownership, roles, permissions, "
            "privacy, and direct-object denial; no tenant boundary is introduced"
        ),
        "pins": {
            "run_191_commit": RUN_191_COMMIT,
            "run_191_tree": RUN_191_TREE,
            "run_191_parent": RUN_191_PARENT,
            "origin_main": ORIGIN_MAIN,
            "local_main_ahead": LOCAL_MAIN_AHEAD,
            "local_main_behind": LOCAL_MAIN_BEHIND,
            "run_191_materializer": RUN_191_MATERIALIZER_RECORD,
            "run_191_receipt": RUN_191_RECEIPT_RECORD,
            "run_191_receipt_self_seal_sha256": RUN_191_RECEIPT_SELF_SEAL,
            "committed_findings": COMMITTED_FINDINGS,
            "committed_run_191_builder": COMMITTED_RUN_191_BUILDER,
            "committed_run_188_dashboard": COMMITTED_RUN_188_DASHBOARD,
            "final_run_192_builder": FINAL_RUN_192_BUILDER,
            "final_run_192_builder_diff": FINAL_RUN_192_BUILDER_DIFF,
            "final_run_192_dashboard": FINAL_RUN_192_DASHBOARD,
            "final_run_192_dashboard_diff": FINAL_RUN_192_DASHBOARD_DIFF,
            "run_192_materializer": file_record(MATERIALIZER),
        },
        "reported_snapshot": run_191["reporting_snapshot"],
        "reported_transition": run_191["reporting_transition"],
        "dashboard_generation": {
            "builder_executed_by_root": True,
            "final_snapshot_builder_executions": 2,
            "superseded_pre_run181_correction_builder_executions": 2,
            "superseded_pre_semantic_correction_builder_executions": 2,
            "byte_identical_outputs": True,
            "baseline_dashboard_sha256": COMMITTED_RUN_188_DASHBOARD["sha256"],
            "final_dashboard_sha256": FINAL_RUN_192_DASHBOARD["sha256"],
            "builder_precondition_extended_for_run_188_input": True,
            "builder_precondition_extended_for_prior_run192_snapshot": True,
            "run_190r_queue_identity_guard_completed": True,
            "run_190r_queue_ids": ["RUN090-ROUTE-0087", "RUN077-ROUTE-0695"],
            "historical_checkpoint_attribution_corrected_before_seal": True,
            "temporary_builder_path_absent_after_generation": True,
        },
        "superseded_browser_observation": {
            "browser": "Codex in-app browser",
            "browser_tab_id": "10",
            "loopback_url": f"http://127.0.0.1:{SERVER_PORT}/audit-dashboard.html",
            "document_title": "Oblivion Findings current-source audit",
            "network_scope": "loopback static audit directory only",
            "dashboard_sha256": (
                "5548a5cb461c2c13ef237a2c622db597289c6bfc9f14bb992a3c89a498a4666a"
            ),
            "superseded_by_dashboard_sha256": FINAL_RUN_192_DASHBOARD["sha256"],
            "current_exact_artifact_credit": False,
            "live_application_browser": False,
            "application_authentication": False,
            "forms_submitted": False,
            "records_opened": False,
            "viewports": {
                viewport: {
                    "requested": viewport,
                    "actual": viewport,
                    "visible_boundaries": 166,
                    "expected_visible_boundaries": 166,
                    "missing_visible_boundaries": [],
                    "anchor_elements": 893,
                    "fragment_anchors": 10,
                    "authored_ids": 10,
                    "browser_dom_ids": 11,
                    "browser_only_injected_id_count": 1,
                    "duplicate_ids": [],
                    "headings": 26,
                    "sections": 26,
                    "navigation_links": 10,
                    "visible_navigation_links": 10,
                    "tables": 10,
                    "table_wraps": 10,
                    "table_containment_failures": 0,
                    "unique_local_resources": 476,
                    "local_relative_link_occurrences": 883,
                    "missing_fragments": [],
                    "page_horizontal_overflow": False,
                }
                for viewport in VIEWPORTS
            },
            "mobile_navigation_horizontally_scrollable_at_390x844": True,
            "desktop_visual_inspection": "GO_NO_CLIPPING_OR_OVERLAP",
            "mobile_visual_inspection": "GO_NO_CLIPPING_OR_OVERLAP",
            "navigation": [
                {
                    "label": label,
                    "expected_hash": target,
                    "observed_hash": target if index < 8 else None,
                    "target_exists": True if index < 8 else None,
                    "target_visible": True if index < 8 else None,
                }
                for index, (label, target) in enumerate(NAVIGATION)
            ],
            "navigation_passed": 8,
            "navigation_total": 10,
            "pending_navigation_labels": ["Architecture", "Gaps"],
            "browser_warning_or_error_logs": None,
            "browser_warning_or_error_count": None,
            "browser_kernel_timeout_during_oversized_combined_check": True,
            "timeout_credit": False,
            "eight_short_bounded_navigation_checks_passed_on_superseded_bytes": True,
            "reclaimed_loopback_reload_blocked_by_browser_url_policy": True,
            "blocked_reload_retried_or_circumvented": False,
            "browser_finalization_complete": False,
        },
        "browser_observation_input": finalization["browser_observation_input"],
        "browser_verification": {
            "browser": (
                browser_observation["browser"]["name"]
                if browser_complete
                else "Codex in-app browser"
            ),
            "browser_provider_id": (
                browser_observation["browser"]["provider_id"]
                if browser_complete
                else None
            ),
            "browser_tab_id": (
                browser_observation["browser"]["tab_id"]
                if browser_complete
                else None
            ),
            "loopback_url": (
                browser_observation["artifact"]["url"]
                if browser_complete
                else f"http://127.0.0.1:{SERVER_PORT}/audit-dashboard.html"
            ),
            "document_title": (
                browser_observation["artifact"]["document_title"]
                if browser_complete
                else "Oblivion Findings current-source audit"
            ),
            "network_scope": "loopback static audit directory only",
            "dashboard_sha256": FINAL_RUN_192_DASHBOARD["sha256"],
            "browser_fetched_dashboard_sha256": (
                browser_observation["artifact"]["browser_fetched_sha256"]
                if browser_complete
                else None
            ),
            "browser_observed_at": (
                browser_observation["observed_at"] if browser_complete else None
            ),
            "server_http_attestation": (
                browser_observation["artifact"] if browser_complete else None
            ),
            "current_exact_artifact_observed": browser_complete,
            "live_application_browser": False,
            "application_authentication": False,
            "forms_submitted": False,
            "records_opened": False,
            "required_viewports": VIEWPORTS,
            "viewports": browser_observation["viewports"] if browser_complete else {},
            "viewports_verified": (
                len(browser_observation["viewports"]) if browser_complete else 0
            ),
            "expected_viewports": len(VIEWPORTS),
            "screenshot_inspections": (
                browser_observation["screenshots"] if browser_complete else []
            ),
            "screenshots_retained": (
                any(item["retained"] for item in browser_observation["screenshots"])
                if browser_complete
                else False
            ),
            "mobile_navigation_horizontally_scrollable_at_390x844": (
                browser_observation["visual_checks"][
                    "mobile_navigation_horizontally_scrollable_at_390x844"
                ]
                if browser_complete
                else None
            ),
            "desktop_visual_inspection": (
                browser_observation["visual_checks"]["desktop_result"]
                if browser_complete
                else None
            ),
            "mobile_visual_inspection": (
                browser_observation["visual_checks"]["mobile_result"]
                if browser_complete
                else None
            ),
            "navigation": (
                browser_observation["navigation"]
                if browser_complete
                else [
                    {
                        "label": label,
                        "expected_hash": target,
                        "observed_hash": None,
                        "browser_click_performed": None,
                        "loaded_url": None,
                        "final_url": None,
                        "target_exists": None,
                        "target_visible": None,
                        "provider_id": None,
                        "tab_id": None,
                        "dashboard_sha256": None,
                        "observed_at": None,
                    }
                    for label, target in NAVIGATION
                ]
            ),
            "navigation_passed": (
                len(browser_observation["navigation"]) if browser_complete else 0
            ),
            "navigation_total": len(NAVIGATION),
            "pending_navigation_labels": (
                [] if browser_complete else [label for label, _ in NAVIGATION]
            ),
            "browser_console_messages": (
                browser_observation["console"]["messages"]
                if browser_complete
                else None
            ),
            "browser_page_errors": (
                browser_observation["console"]["page_errors"]
                if browser_complete
                else None
            ),
            "browser_warning_or_error_logs": (
                browser_observation["console"]["warning_or_error_logs"]
                if browser_complete
                else None
            ),
            "browser_warning_or_error_count": finalization[
                "final_browser_warning_error_count"
            ],
            "browser_finalization_blocker": (
                None
                if browser_complete
                else (
                    "the current exact dashboard loopback reload was rejected by the "
                    "in-app-browser URL policy; the error tab was returned to the user for "
                    "one manual reload and the blocked action was not retried or circumvented"
                )
            ),
            "current_exact_reload_attempted": True,
            "current_exact_reload_blocked_by_browser_url_policy": not browser_complete,
            "manual_user_reload_required": not browser_complete,
            "blocked_reload_retried_or_circumvented": False,
            "browser_finalization_complete": browser_complete,
        },
        "html_and_resource_graph": html_validation,
        "final_http_head_verification": {
            "expected_unique_local_resources": EXPECTED_UNIQUE_LOCAL_RESOURCES,
            "verified_count": finalization["final_http_head_verified_count"],
            "failure_count": finalization["final_http_head_failure_count"],
            "complete": resource_complete,
        },
        "root_browser_resource_cleanup": {
            "browser_viewport_override_reset": (
                browser_observation["deliverable"]["browser_viewport_override_reset"]
                if browser_complete
                else False
            ),
            "current_exact_dashboard_tab_retained": (
                browser_observation["deliverable"][
                    "current_exact_dashboard_tab_retained"
                ]
                if browser_complete
                else False
            ),
            "error_tab_marked_deliverable_for_manual_reload": not browser_complete,
            "dashboard_tab_marked_deliverable": finalization[
                "dashboard_tab_marked_deliverable"
            ],
            "agent_created_tab_closed": False,
            "temporary_loopback_port": SERVER_PORT,
            "temporary_server_pid": SERVER_PID,
            "temporary_server_executable": SERVER_EXECUTABLE,
            "listeners_after_cleanup": finalization["listeners_after_cleanup"],
            "exact_pid_present_after_cleanup": finalization[
                "exact_server_pid_present_after_cleanup"
            ],
            "matching_loopback_server_processes_after_cleanup": finalization[
                "matching_loopback_processes_after_cleanup"
            ],
            "cleanup_finalized": cleanup_complete,
            "temporary_server_left_running_for_manual_reload": (
                not cleanup_complete and not browser_complete
            ),
        },
        "reported_finding_boundary": {
            "retained_claim_records": 15,
            "current_provisional_source_claims": 8,
            "historical_already_fixed_records": 2,
            "historical_remediated_records": 5,
            "final_P0": 0,
            "final_P1": 0,
            "changed_by_run_192": False,
        },
        "bounded_execution_accounting": {
            "unique_tests": 155,
            "unique_assertions": 2403,
            "changed_by_run_192": False,
            "executed_by_run_192": False,
        },
        "benchmark_boundary": {
            "mapped": 2,
            "total": 340,
            "final_no_match_or_NCM": 0,
            "unresolved": 338,
            "changed_by_run_192": False,
        },
        "noninheritance_boundary": {
            "run_191_reporting_recredited": False,
            "run_190_ownership_recredited": False,
            "superseded_browser_observations_recredited": False,
            "calendar_hazard_or_checklist_work_recredited": False,
            "second_cycle_ten_fix_work_recredited": False,
            "fleet_fuel_finding_or_remediation_recredited": False,
            "static_route_feature_ownership": False,
            "static_controller_action_bridge": False,
            "application_source_or_product_test": False,
            "application_runtime": False,
            "application_browser": False,
            "executed_product_tests": False,
            "queue_advance": False,
            "benchmark_mapping_or_final_no_match_NCM": False,
            "deployment": False,
            "ease": False,
            "final_finding": False,
            "feature_module_or_pass_completion": False,
            "release_publication_gate4_or_audit_completion": False,
        },
        "reserved_application_path_boundaries": {
            "second_cycle_shared_baseline": RUN_191_PARENT,
            "second_cycle_sealed_fix_count": len(SECOND_CYCLE_RESERVED_FIXES),
            "second_cycle_reserved_path_count": sum(
                len(record["paths"])
                for record in SECOND_CYCLE_RESERVED_FIXES.values()
            ),
            "second_cycle_fixes": SECOND_CYCLE_RESERVED_FIXES,
            "fleet_fuel_finding": FLEET_FUEL_FINDING,
            "fleet_fuel_transfer_baseline": RUN_191_COMMIT,
            "fleet_fuel_paths": FLEET_FUEL_RESERVED_PATHS,
            "fleet_fuel_remediation_ownership_transferred": True,
            "unchanged_from_shared_baseline": True,
            "run_192_changes_or_credit": False,
        },
        "worktree_boundary": {
            "expected_final_status_count": 4,
            "expected_final_porcelain_statuses": EXPECTED_FINAL_STATUS,
            "no_staged_paths": True,
            "git_diff_check_clean": True,
            "application_paths_changed": [],
            "product_test_paths_changed": [],
            "exact_match_required": True,
        },
        "mutation_attestation": {
            "sequence_paths": [BUILDER, HTML, MATERIALIZER, OUTPUT],
            "receipt_materializer_persistent_write_scope": [OUTPUT],
            "builder_changed_before_materializer": True,
            "dashboard_changed_before_materializer": True,
            "findings_register_changed_by_run_192": False,
            "run_191_reporting_surfaces_changed_by_run_192": False,
            "screenshot_inspections_recorded": browser_complete,
            "screenshots_retained": (
                any(item["retained"] for item in browser_observation["screenshots"])
                if browser_complete
                else False
            ),
            "database_changed": False,
            "application_tests_or_build_run_by_materializer": False,
        },
        "credit_boundary": {
            "exact_audit_dashboard_artifact": all_complete,
            "static_route_feature_ownership": False,
            "static_controller_action_bridge": False,
            "static_page_or_frontend_ownership": False,
            "correctness": False,
            "site_privacy_or_direct_object": False,
            "application_source_or_product_tests": False,
            "application_runtime": False,
            "application_browser": False,
            "executed_product_tests": False,
            "benchmark_mapping": False,
            "final_no_match_or_NCM": False,
            "deployment": False,
            "ease": False,
            "final_finding": False,
            "release": False,
            "publication": False,
            "feature_or_module_completion": False,
            "gate_4": False,
            "audit_complete": False,
        },
        "completion_gates": [
            {"gate": gate, "name": name, "complete": False}
            for gate, name in enumerate(
                [
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
                ],
                start=1,
            )
        ],
        "artifact_completion_scope": [BUILDER, HTML, MATERIALIZER, OUTPUT],
        "artifact_completion_test_met": all_complete,
        "audit_completion_test_met": False,
        "run_192_sequence_written_paths": [
            f"{PREFIX}/{path}" for path in (BUILDER, HTML, MATERIALIZER, OUTPUT)
        ],
        "remote_state_boundary": {
            "origin_main_before_run_192_commit": ORIGIN_MAIN,
            "local_main_ahead_before_run_192_commit": LOCAL_MAIN_AHEAD,
            "local_main_behind_before_run_192_commit": LOCAL_MAIN_BEHIND,
            "push_or_publication_performed_by_materializer": False,
            "publication_claim": False,
        },
        "root_finalization_required": {
            "post_materialization_http_head": not resource_complete,
            "server_cleanup": not cleanup_complete,
            "browser_navigation_and_log_finalization": not browser_complete,
            "receipt_materializer_arguments": {
                "final_http_head_verified_count": EXPECTED_UNIQUE_LOCAL_RESOURCES,
                "final_http_head_failure_count": 0,
                "listeners_after_cleanup": 0,
                "exact_server_pid_present_after_cleanup": False,
                "matching_loopback_processes_after_cleanup": 0,
                "browser_observation_file": (
                    finalization["browser_observation_input"]["path"]
                    if browser_complete
                    else "<absolute-path-to-strict-hash-bound-browser-observation.json>"
                ),
            },
            "browser_observation_contract": {
                "schema_version": BROWSER_OBSERVATION_SCHEMA,
                "dashboard_sha256": FINAL_RUN_192_DASHBOARD["sha256"],
                "provider_id": (
                    FINAL_BROWSER_PROVIDER_ID
                    if FINAL_BROWSER_PROVIDER_ID is not None
                    else "<pin-after-current-browser-observation>"
                ),
                "tab_id": (
                    FINAL_BROWSER_TAB_ID
                    if FINAL_BROWSER_TAB_ID is not None
                    else "<pin-after-current-browser-observation>"
                ),
                "observed_at": (
                    FINAL_BROWSER_OBSERVED_AT
                    if FINAL_BROWSER_OBSERVED_AT is not None
                    else "<pin-after-current-browser-observation>"
                ),
                "server": {
                    "host": "127.0.0.1",
                    "port": SERVER_PORT,
                    "pid": SERVER_PID,
                    "executable": SERVER_EXECUTABLE,
                    "http_status": 200,
                    "response_content_length": FINAL_RUN_192_DASHBOARD["bytes"],
                },
                "required_viewports": VIEWPORTS,
                "required_browser_visible_text_checks": BROWSER_VISIBLE_TEXT_BOUNDARIES,
                "required_browser_visible_text_count": len(
                    BROWSER_VISIBLE_TEXT_BOUNDARIES
                ),
                "source_literal_manifest_count_separate_from_browser": 166,
                "required_navigation": [
                    {"label": label, "expected_hash": target}
                    for label, target in NAVIGATION
                ],
                "browser_click_required_for_each_navigation": True,
                "loaded_and_final_url_required_for_each_navigation": True,
                "console_messages": [],
                "warning_or_error_logs": [],
                "page_errors": [],
                "visual_result": VISUAL_GO,
                "dashboard_tab_marked_deliverable": True,
                "self_seal_required": True,
                "input_must_remain_outside_repository": True,
                "single_read_parse_and_hash_required": True,
                "optimized_python_refused": True,
            },
        },
    }
    receipt["completion_boundary"] = {
        item["name"]: item["complete"] for item in receipt["completion_gates"]
    }
    assert len(receipt["completion_boundary"]) == 26
    assert all(value is False for value in receipt["completion_boundary"].values())
    return receipt


def write_receipt(receipt: dict[str, Any]) -> None:
    assert_finite(receipt)
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    raw = (
        json.dumps(receipt, ensure_ascii=False, indent=2, allow_nan=False) + "\n"
    ).encode("utf-8")
    output_path = AUDIT / OUTPUT
    temporary = output_path.with_name(f".{output_path.name}.tmp-run192")
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
    run_191 = validate_run_191()
    html_validation = validate_builder_and_html()
    finalization = finalization_inputs(args, html_validation)
    receipt = build_receipt(run_191, html_validation, finalization)
    write_receipt(receipt)


if __name__ == "__main__":
    main()
