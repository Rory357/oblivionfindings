#!/usr/bin/env python3
"""Seal bounded RUN202 verification for the exact RUN201 audit dashboard.

This producer validates exact committed RUN201 reporting inputs, the unchanged
dashboard builder, deterministic generated HTML, an external
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
    raise RuntimeError("RUN202 materializer refuses optimized Python")


SCRIPT = Path(__file__).resolve()
AUDIT = SCRIPT.parent.parent
ROOT = next(parent for parent in SCRIPT.parents if (parent / ".git").exists())
PREFIX = AUDIT.relative_to(ROOT).as_posix()

MATERIALIZER = SCRIPT.relative_to(AUDIT).as_posix()
OUTPUT = "evidence/browser/current-audit-dashboard-verification-run-202-wave-41.json"
HTML = "audit-dashboard.html"
BUILDER = "generators/build-current-audit-dashboard.py"
FINDINGS = "findings.json"
RUN_201_MATERIALIZER = (
    "generators/materialize-run-201-elig-shift-notification-site-privacy-"
    "remediation-reporting-wave-41.py"
)
RUN_201_RECEIPT = (
    "evidence/source/current-run-201-elig-shift-notification-site-privacy-"
    "remediation-reporting-wave-41.json"
)
RUN_201_HANDOFF = (
    "evidence/source/current-run-201-elig-shift-notification-site-privacy-"
    "coordination-handoff-wave-41.json"
)
RUN_200_RECEIPT = (
    "evidence/browser/current-audit-dashboard-verification-run-200-wave-40.json"
)

RUN_ID = "RUN-202-AUDIT-DASHBOARD-VERIFICATION-WAVE-41"
RUN_201_COMMIT = "18b3e9131bba3361a22dca75ef4eea8079834e38"
RUN_201_TREE = "24d1dc78b6aadd59853e1661207f77c6a9023343"
RUN_201_PARENT = "1382dd4a48b35d9f9155c2dd501a8a3f4f60d47c"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"
LOCAL_MAIN_BEHIND = 0
LOCAL_MAIN_AHEAD = 93
ELIG_SHIFT_FINDING = "ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01"

RUN_201_MATERIALIZER_RECORD = {
    "path": RUN_201_MATERIALIZER,
    "sha256": "d358b4c091f57568a3516538e5e052085f824e671f6bf7141540e958154dcb04",
    "git_blob_id": "d9ff28d8566e3abd4ff1a73fde1b4563e63ee373",
    "bytes": 45589,
    "lines": 1015,
}
RUN_201_RECEIPT_RECORD = {
    "path": RUN_201_RECEIPT,
    "sha256": "4ce93728698919b9ad7810d984efdd3d486af30cd8174bca924b58fb5edb8f8a",
    "git_blob_id": "3697226fc838a6efa59f49ce8b05d2177aee590f",
    "bytes": 20718,
    "lines": 498,
}
RUN_201_RECEIPT_SELF_SEAL = (
    "f9d6bc3086e5afd7da6563c82a951ef4751c53d7647a1c09e4b684526b7f48fb"
)
RUN_201_HANDOFF_RECORD = {
    "path": RUN_201_HANDOFF,
    "sha256": "f17c4c8d91dd040fb0b142196f65fa2c7657160bfc232404d9b6fe629bd156b7",
    "git_blob_id": "185165a5d5c9efc29fc9ef110aa4c347443841da",
    "bytes": 7628,
    "lines": 160,
}
RUN_201_HANDOFF_SELF_SEAL = (
    "225a2548c1f2d0120e3edd5ef26feb02ad8616085a36aa2d502e81700e0da587"
)
RUN_200_RECEIPT_RECORD = {
    "path": RUN_200_RECEIPT,
    "sha256": "59b80aa14c8841f412d9b76003cc8f2dcd135634cd9394a43523bad31f62c520",
    "git_blob_id": "735dfb0346f22091e8bd72ad3d5ef0d31c72efaf",
    "bytes": 30435,
    "lines": 796,
}
RUN_200_RECEIPT_SELF_SEAL = (
    "493b62087f2df1f2ff776f68c162fceb38ab69763a0b2554ba0148dd6c58d216"
)
COMMITTED_FINDINGS = {
    "path": FINDINGS,
    "sha256": "bf2e9fd34cfe4d2f5188d91ebd3431ac6d92a296035d83e05caf77cb5fee142f",
    "git_blob_id": "868d0e8e58b7191de2b8e587f22260c9bf5c75bc",
    "bytes": 724630,
    "lines": 12449,
}
COMMITTED_RUN_201_BUILDER = {
    "path": BUILDER,
    "sha256": "a2197052c211eccf6f00f3b43b280564f22d1b2f92491623973b3c5e49bf8767",
    "git_blob_id": "672fa2efeda8d47d5750c9e3fa77d90a42a6339a",
    "bytes": 901836,
    "lines": 7905,
}
COMMITTED_RUN_200_DASHBOARD = {
    "path": HTML,
    "sha256": "f643ca1ec1716cfb2b32864aba1a97e8d69c3e726453707a3ce71e76b3c43205",
    "git_blob_id": "765c149116283e35cd74aeb2269fe4516fd1465e",
    "bytes": 345157,
    "lines": 78,
}
FINAL_RUN_202_BUILDER = {
    "path": BUILDER,
    "sha256": "a2197052c211eccf6f00f3b43b280564f22d1b2f92491623973b3c5e49bf8767",
    "git_blob_id": "672fa2efeda8d47d5750c9e3fa77d90a42a6339a",
    "bytes": 901836,
    "lines": 7905,
}
FINAL_RUN_202_DASHBOARD = {
    "path": HTML,
    "sha256": "1876db1ff590c86fb30cefb74368b0241c72d9b75966fcbf1a36d6b1096b30e3",
    "git_blob_id": "03442cdb7ec6e17ae55b61494932171bff1e33f4",
    "bytes": 350017,
    "lines": 78,
}
FINAL_RUN_202_DASHBOARD_DIFF = {
    "path": HTML,
    "binary_diff_sha256": (
        "ef093722e68f782593750451397a26a912b7a5a2fb9a1518d66a0b8cc4dc8c1e"
    ),
    "numstat": {"added": 17, "deleted": 17},
}

EXPECTED_FINAL_STATUS = sorted(
    [
        f" M {PREFIX}/{HTML}",
        f"?? {PREFIX}/{MATERIALIZER}",
        f"?? {PREFIX}/{OUTPUT}",
    ]
)
EXPECTED_PREOUTPUT_STATUS = sorted(
    item for item in EXPECTED_FINAL_STATUS if not item.endswith(f"/{OUTPUT}")
)
ELIG_SHIFT_PATH_BLOBS = {
    "app/Jobs/EscalateUnresolvedEligibilityJob.php": (
        "360d1fafa858341f1ffbc0ceecf64fda449fc5ec"
    ),
    "app/Jobs/RecalculateFutureShiftEligibility.php": (
        "5bb72f86adb6165bf43b4f489f59e61bef3eb5bf"
    ),
    "tests/Feature/Rostering/ShiftEligibilityNotificationRecipientPrivacyTest.php": (
        "056116c106f00740d1d51bb125ad924b98ebe225"
    ),
    "tests/Unit/Jobs/EscalateUnresolvedEligibilityJobTest.php": (
        "4e30b5ed794b7260255a045c74a02f18619679da"
    ),
}
RUN_201_PATHS = [
    f"{PREFIX}/00-executive-summary.md",
    f"{PREFIX}/01-repository-module-map.md",
    f"{PREFIX}/07-module-findings.md",
    f"{PREFIX}/11-prioritised-roadmap.md",
    f"{PREFIX}/12-native-build-and-do-not-copy-register.md",
    f"{PREFIX}/13-unresolved-questions-and-evidence-gaps.md",
    f"{PREFIX}/evidence/source/current-run-201-elig-shift-notification-site-privacy-coordination-handoff-wave-41.json",
    f"{PREFIX}/evidence/source/current-run-201-elig-shift-notification-site-privacy-remediation-reporting-wave-41.json",
    f"{PREFIX}/findings.json",
    f"{PREFIX}/generators/build-current-audit-dashboard.py",
    f"{PREFIX}/generators/materialize-run-201-elig-shift-notification-site-privacy-remediation-reporting-wave-41.py",
]

NAVIGATION = [
    ("Progress", "#progress"),
    ("RUN-201", "#checkpoint"),
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
    "RUN-200–201 Shift eligibility-alert recipient Site-privacy checkpoint",
    "RUN-200: exact RUN-199 dashboard verified at 4/4 viewports",
    "48/48 named visible checks per viewport",
    "10/10 navigation · 504/504 resources · zero application credit",
    (
        "RUN-201: Shift eligibility-alert recipient Site privacy reproduced and remediated "
        "in exactly four paths"
    ),
    (
        "19 retained claim identities split into 8 current provisional P1, 2 "
        "historical already-fixed, and 9 historical remediated"
    ),
    "198/2,716 unique bounded disposition total",
    "RUN-071–201 current reporting checkpoint",
    "RUN-071–201 completion-gate checkpoint",
    "RUN-071–201 evidence lineage",
    "index 84 is not recredited",
    "index 85 fleet-assets.trips.playback is integrated",
    "next index 86 RUN090-ROUTE-0087 / RUN077-ROUTE-0695",
    "fleet-assets.trips.playback.data",
    "ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01",
    "RUN-201 Shift eligibility-alert Site-privacy execution",
    "current active/non-ended employee and approved canonical-Shift-Site recipient",
    "one canonical current Shift authorization/payload snapshot",
    "one post-merge eligibility-alert 13/25 execution",
    "delegated coordination transcription, not an original runtime receipt",
    (
        "baseline f7c6f9ee4765 · fix 95fb2677a417 · local merge 1382dd4a48b3 · "
        "origin/main c39b07654705 unchanged"
    ),
    "red 1 failed + 0 passed + 4 pending / 1 assertion",
    "feature unassigned",
    "historical issue · remediated on current main · not a final finding",
    "not published to origin/main",
    "null feature and candidate IDs",
    "zero static ownership",
    "2/340 mappings",
    "0/340 final no-match/NCM",
    "338 unresolved targets",
    "one operating organisation across multiple Sites",
    "Gate 4 and audit completion false",
    "Fresh RUN-202 audit-dashboard verification required",
    "dashboard HTML frozen pending RUN-202",
    "RUN-201 Shift eligibility-alert coordination-handoff transcription",
    "RUN-201 Shift eligibility-alert remediation-reporting materializer",
    "RUN-201 Shift eligibility-alert remediation-reporting receipt",
    "RUN-202 audit-dashboard verification receipt",
    "RUN-202 audit-dashboard verification materializer",
    "None supplies audit-dashboard verification for the new RUN-202 HTML.",
    "The linked RUN-202 receipt must record",
    "It verifies the RUN-201 audit artifact only",
    (
        "visible 667/310/357 ownership, 98 bridges, 121/386 queue accounting, "
        "99 owned/408 without ownership"
    ),
]
EXPECTED_SOURCE_LITERAL_BOUNDARIES = 229
EXPECTED_UNIQUE_LOCAL_RESOURCES = 509
EXPECTED_HASHED_LINK_PAIRS = 841
EXPECTED_HASHED_FILE_OCCURRENCES = 839
EXPECTED_UNIQUE_HASHED_FILES = 435
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
    assert git("rev-parse", "HEAD") == RUN_201_COMMIT
    assert git("rev-parse", "main") == RUN_201_COMMIT
    assert git("show", "-s", "--format=%T", "HEAD") == RUN_201_TREE
    assert git("show", "-s", "--format=%P", "HEAD") == RUN_201_PARENT
    assert git("show", "-s", "--format=%s", "HEAD") == (
        "audit: report RUN201 shift alert privacy"
    )
    assert sorted(
        git(
            "diff-tree",
            "--no-commit-id",
            "--name-only",
            "-r",
            RUN_201_COMMIT,
        ).splitlines()
    ) == sorted(RUN_201_PATHS)
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
    assert git("diff", "--name-only") == f"{PREFIX}/{HTML}"
    assert committed_record(RUN_201_COMMIT, BUILDER) == COMMITTED_RUN_201_BUILDER
    assert committed_record(RUN_201_COMMIT, HTML) == COMMITTED_RUN_200_DASHBOARD
    assert file_record(BUILDER) == FINAL_RUN_202_BUILDER
    assert git("diff", "--exit-code", "--", f"{PREFIX}/{BUILDER}") == ""
    assert file_record(HTML) == FINAL_RUN_202_DASHBOARD
    assert diff_record(HTML) == FINAL_RUN_202_DASHBOARD_DIFF
    for path, expected_blob in ELIG_SHIFT_PATH_BLOBS.items():
        assert git("rev-parse", f"HEAD:{path}") == expected_blob


def validate_run_201() -> tuple[dict[str, Any], dict[str, Any]]:
    assert file_record(RUN_201_MATERIALIZER) == RUN_201_MATERIALIZER_RECORD
    assert file_record(RUN_201_RECEIPT) == RUN_201_RECEIPT_RECORD
    assert file_record(RUN_201_HANDOFF) == RUN_201_HANDOFF_RECORD
    assert committed_record(RUN_201_COMMIT, RUN_201_MATERIALIZER) == (
        RUN_201_MATERIALIZER_RECORD
    )
    assert committed_record(RUN_201_COMMIT, RUN_201_RECEIPT) == (
        RUN_201_RECEIPT_RECORD
    )
    assert committed_record(RUN_201_COMMIT, RUN_201_HANDOFF) == (
        RUN_201_HANDOFF_RECORD
    )
    assert file_record(FINDINGS) == COMMITTED_FINDINGS
    assert file_record(RUN_200_RECEIPT) == RUN_200_RECEIPT_RECORD

    run_201 = strict_json(RUN_201_RECEIPT)
    verify_self_seal(run_201, RUN_201_RECEIPT_SELF_SEAL)
    assert run_201["run_id"] == (
        "RUN-201-ELIG-SHIFT-NOTIFICATION-SITE-PRIVACY-01-"
        "REMEDIATION-REPORTING-WAVE-41"
    )
    assert run_201["scope"] == {
        "finding_id": ELIG_SHIFT_FINDING,
        "type": "AUDIT_REPORTING_ONLY",
        "architecture": "SINGLE_ORGANISATION_MULTI_SITE",
        "application_or_test_source_mutated_by_run_201": False,
        "runtime_database_browser_or_build_executed_by_run_201": False,
        "dashboard_html_mutated_by_run_201": False,
        "delegated_runtime_or_review_authorship_claimed_by_run_201": False,
    }
    transition = run_201["reporting_transition"]
    assert transition["finding_id"] == ELIG_SHIFT_FINDING
    assert transition["counts_after"] == {
        "retained_claim_records": 19,
        "provisional_source_claims": 8,
        "historical_already_fixed": 2,
        "historical_remediated": 9,
        "final_P0": 0,
        "final_P1": 0,
    }
    assert transition["feature_id"] is None
    assert transition["candidate_feature_id"] is None
    assert transition["feature_identity_status"] == (
        "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW"
    )
    assert transition["static_ownership_or_queue_advance"] is False
    assert run_201["bounded_execution_accounting"]["unique_total"] == {
        "tests": 198,
        "assertions": 2716,
    }
    assert run_201["dashboard_forward_gate"] == {
        "required_run": "RUN-202",
        "dashboard_html_changed_by_run_201": False,
        "preserved_run_200_dashboard_sha256": COMMITTED_RUN_200_DASHBOARD["sha256"],
        "generator": MATERIALIZER,
        "receipt": OUTPUT,
        "fresh_four_viewport_navigation_resource_console_verification_required": True,
        "forward_paths_intentionally_unhashed": True,
    }
    preservation = run_201["preservation_boundary"]
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
    }
    assert preservation["benchmark"] == {
        "mapped": 2,
        "targets": 340,
        "final_no_match_or_NCM": 0,
        "unresolved": 338,
    }
    assert len(run_201["completion_boundary"]) == 26
    assert all(value is False for value in run_201["completion_boundary"].values())
    assert run_201["credit_boundary"]["live_findings_register_and_reporting_status"]
    assert all(
        value is False
        for key, value in run_201["credit_boundary"].items()
        if key != "live_findings_register_and_reporting_status"
    )
    assert run_201["artifact_completion_test_met"] is True
    assert run_201["audit_completion_test_met"] is False
    assert run_201["pins"]["coordination_handoff"] == {
        "path": RUN_201_HANDOFF,
        "sha256": RUN_201_HANDOFF_RECORD["sha256"],
        "bytes": RUN_201_HANDOFF_RECORD["bytes"],
        "lines": RUN_201_HANDOFF_RECORD["lines"],
        "receipt_self_seal_sha256": RUN_201_HANDOFF_SELF_SEAL,
    }

    handoff = strict_json(RUN_201_HANDOFF)
    verify_self_seal(handoff, RUN_201_HANDOFF_SELF_SEAL)
    assert handoff["finding"]["id"] == ELIG_SHIFT_FINDING
    assert handoff["remediated_contract"]["architecture"].startswith(
        "One operating organisation across multiple Sites;"
    )
    assert handoff["source"]["original_issue_specific_runtime_receipt_present"] is False
    assert handoff["source"][
        "original_issue_specific_independent_review_receipt_present"
    ] is False

    run_200 = strict_json(RUN_200_RECEIPT)
    verify_self_seal(run_200, RUN_200_RECEIPT_SELF_SEAL)
    assert run_200["run_id"] == "RUN-200-AUDIT-DASHBOARD-VERIFICATION-WAVE-40"
    assert run_200["pins"]["final_run_200_dashboard"] == COMMITTED_RUN_200_DASHBOARD
    assert run_200["artifact_completion_test_met"] is True
    assert run_200["audit_completion_test_met"] is False

    findings = strict_json(FINDINGS)
    records = findings["records"]
    statuses = Counter(record["record_status"] for record in records)
    assert len(records) == len({record["id"] for record in records}) == 19
    assert statuses == {
        "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING": 8,
        "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING": 2,
        "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING": 9,
    }
    elig_shift = next(
        record for record in records if record["id"] == ELIG_SHIFT_FINDING
    )
    assert elig_shift["feature_id"] is None
    assert elig_shift["candidate_feature_id"] is None
    assert elig_shift["completion_credit"] is False
    counts = findings["counts"]
    assert counts["retained_claim_records"] == 19
    assert counts["bounded_disposition_tests_passed"] == 198
    assert counts["bounded_disposition_assertions"] == 2716
    assert counts["benchmark_mapped"] == 2
    assert counts["final_no_match"] == 0
    assert counts["benchmark_unresolved"] == 338
    return run_201, run_200


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
    assert '<a href="#checkpoint">RUN-201</a>' in html_text
    assert "<title>Oblivion Findings current-source audit</title>" in html_text

    parser = Parser()
    parser.feed(html_text)
    visible_text = re.sub(r"\s+", " ", " ".join(parser.text_parts)).strip()
    assert len(BROWSER_VISIBLE_TEXT_BOUNDARIES) == 48
    assert all(boundary in visible_text for boundary in BROWSER_VISIBLE_TEXT_BOUNDARIES)

    assert len(parser.hrefs) == 996
    assert len(parser.anchor_hrefs) == 995
    assert len(parser.ids) == len(set(parser.ids)) == 10
    assert parser.headings == parser.sections == 26
    assert parser.tables == parser.table_wraps == 10
    fragment_hrefs = [href for href in parser.anchor_hrefs if href.startswith("#")]
    assert len(fragment_hrefs) == 10
    assert all(href[1:] in parser.ids for href in fragment_hrefs)

    local_hrefs = [href for href in parser.hrefs if is_local(href)]
    targets = {local_path(href) for href in local_hrefs}
    assert len(local_hrefs) == 985
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
            "response_content_length": FINAL_RUN_202_DASHBOARD["bytes"],
            "browser_fetched_sha256": FINAL_RUN_202_DASHBOARD["sha256"],
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
                "dashboard_sha256": FINAL_RUN_202_DASHBOARD["sha256"],
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
                "dashboard_sha256": FINAL_RUN_202_DASHBOARD["sha256"],
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
            "dashboard_sha256": FINAL_RUN_202_DASHBOARD["sha256"],
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
            "dashboard_sha256": FINAL_RUN_202_DASHBOARD["sha256"],
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
                "dashboard_sha256": FINAL_RUN_202_DASHBOARD["sha256"],
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
            "dashboard_sha256": FINAL_RUN_202_DASHBOARD["sha256"],
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
        FINAL_RUN_202_DASHBOARD["sha256"],
        FINAL_RUN_202_DASHBOARD["sha256"],
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
    run_201: dict[str, Any],
    run_200: dict[str, Any],
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
        "schema_version": "run-202-audit-dashboard-verification-wave-41-v1",
        "run_id": RUN_ID,
        "generated_at": None,
        "pins": {
            "run_201_commit": RUN_201_COMMIT,
            "run_201_tree": RUN_201_TREE,
            "run_201_parent": RUN_201_PARENT,
            "origin_main": ORIGIN_MAIN,
            "local_main_ahead": LOCAL_MAIN_AHEAD,
            "local_main_behind": LOCAL_MAIN_BEHIND,
            "run_201_materializer": RUN_201_MATERIALIZER_RECORD,
            "run_201_receipt": RUN_201_RECEIPT_RECORD,
            "run_201_receipt_self_seal_sha256": RUN_201_RECEIPT_SELF_SEAL,
            "run_201_coordination_handoff": RUN_201_HANDOFF_RECORD,
            "run_201_coordination_handoff_self_seal_sha256": (
                RUN_201_HANDOFF_SELF_SEAL
            ),
            "run_200_receipt": RUN_200_RECEIPT_RECORD,
            "run_200_receipt_self_seal_sha256": RUN_200_RECEIPT_SELF_SEAL,
            "committed_findings": COMMITTED_FINDINGS,
            "committed_run_201_builder": COMMITTED_RUN_201_BUILDER,
            "committed_run_200_dashboard": COMMITTED_RUN_200_DASHBOARD,
            "final_run_202_builder": FINAL_RUN_202_BUILDER,
            "final_run_202_builder_unchanged_from_run_201": True,
            "final_run_202_dashboard": FINAL_RUN_202_DASHBOARD,
            "final_run_202_dashboard_diff": FINAL_RUN_202_DASHBOARD_DIFF,
            "run_202_materializer": file_record(MATERIALIZER),
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
                "records": 19,
                "provisional": 8,
                "historical_already_fixed": 2,
                "historical_remediated": 9,
                "bounded_tests": 198,
                "bounded_assertions": 2716,
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
            "from_dashboard_sha256": COMMITTED_RUN_200_DASHBOARD["sha256"],
            "to_dashboard_sha256": FINAL_RUN_202_DASHBOARD["sha256"],
            "reported_finding_id": ELIG_SHIFT_FINDING,
            "run_201_forward_gate_satisfied_by_this_artifact": artifact_complete,
            "static_ownership_or_queue_advance": False,
            "new_application_finding": False,
        },
        "generation": {
            "builder": FINAL_RUN_202_BUILDER,
            "builder_unchanged_from_run_201": True,
            "dashboard": FINAL_RUN_202_DASHBOARD,
            "dashboard_diff": FINAL_RUN_202_DASHBOARD_DIFF,
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
        "superseded_run_200_observation": {
            "dashboard": COMMITTED_RUN_200_DASHBOARD,
            "receipt": RUN_200_RECEIPT_RECORD,
            "receipt_artifact_completion_test_met": run_200[
                "artifact_completion_test_met"
            ],
            "superseded_by_dashboard_sha256": FINAL_RUN_202_DASHBOARD["sha256"],
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
            "finding_id": ELIG_SHIFT_FINDING,
            "record_status": (
                "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
            ),
            "recipient_site_privacy_and_canonical_shift_snapshot_only": True,
            "delegated_coordination_transcription_not_original_runtime_receipt": True,
            "feature_identity_unassigned": True,
            "static_ownership_credit": False,
            "adjacent_surface_credit": False,
            "my_day_credit": False,
            "application_browser_credit": False,
            "benchmark_credit": False,
            "final_finding": False,
        },
        "execution_boundary": {
            "bounded_unique_tests": 198,
            "bounded_unique_assertions": 2716,
            "run_202_application_runtime_or_tests_executed": False,
            "run_202_browser_scope": "static audit-dashboard HTML only",
            "full_suite_or_coverage": False,
        },
        "benchmark_boundary": {
            "mapped": 2,
            "targets": 340,
            "final_no_match_or_NCM": 0,
            "unresolved": 338,
            "run_202_benchmark_credit": False,
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
            "modified_paths": [f"{PREFIX}/{HTML}"],
            "untracked_paths": [f"{PREFIX}/{MATERIALIZER}", f"{PREFIX}/{OUTPUT}"],
            "application_or_test_dirt": [],
            "builder_matches_committed_run_201_exact_blob": True,
            "elig_shift_paths_match_run_201_exact_blobs": True,
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
            "exact_run_202_dashboard_artifact_verification": artifact_complete,
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
                "reason": "RUN202 verifies one exact dashboard artifact only",
            }
            for name in COMPLETION_NAMES
        ],
        "artifact_scope": {
            "materializer": MATERIALIZER,
            "receipt": OUTPUT,
            "builder": BUILDER,
            "dashboard": HTML,
            "repository_mutation_path_count": 3,
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
    assert run_201["audit_completion_test_met"] is False
    return receipt


def write_receipt(receipt: dict[str, Any]) -> None:
    assert_finite(receipt)
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    raw = (
        json.dumps(receipt, ensure_ascii=False, indent=2, allow_nan=False) + "\n"
    ).encode("utf-8")
    output = AUDIT / OUTPUT
    temporary = output.with_name(f".{output.name}.tmp-run202")
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
    run_201, run_200 = validate_run_201()
    html_graph = validate_builder_and_html()
    finalization = finalization_inputs(args, html_graph)
    receipt = build_receipt(run_201, run_200, html_graph, finalization)
    write_receipt(receipt)


if __name__ == "__main__":
    main()
