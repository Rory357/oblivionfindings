#!/usr/bin/env python3
"""Seal bounded RUN178 facts for the exact RUN177 audit dashboard.

Static facts are reconstructed from exact committed bytes. Browser facts remain
fail-closed placeholders until the bounded loopback-only verification is
complete. This producer writes only its receipt. It grants no application,
publication, final-finding, Pass, release, feature, module, Gate 4, or audit
completion credit.
"""
from __future__ import annotations

import ast
from collections import Counter
import hashlib
from html.parser import HTMLParser
import json
import os
from pathlib import Path
import re
import subprocess
from typing import Any
from urllib.parse import unquote, urlsplit


ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24"
PREFIX = AUDIT.relative_to(ROOT).as_posix()
MATERIALIZER = "generators/materialize-run-178-audit-dashboard-verification-wave-33.py"
OUTPUT = "evidence/browser/current-audit-dashboard-verification-run-178-wave-33.json"
HTML = "audit-dashboard.html"
BUILDER = "generators/build-current-audit-dashboard.py"
RUN_177_MATERIALIZER = (
    "generators/materialize-run-177-fleet-trip-index-site-privacy-remediation-"
    "reporting-wave-33.py"
)
RUN_177_RECEIPT = (
    "evidence/source/current-run-177-fleet-trip-index-site-privacy-remediation-"
    "reporting-wave-33.json"
)
AUDIT_RUN_MANIFEST = "evidence/source/audit-run-manifest.json"

CHECKPOINT_COMMIT = "519e00a9789343720f4e85e18908908ce278d65c"
CHECKPOINT_TREE = "56b45224304ac8dd46282fa6da9088724239c2c4"
CHECKPOINT_PARENT = "167ae89131d9fe2aa7a2636e5d20796002ca7c03"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"
LOCAL_MAIN_AHEAD = 11
LOCAL_MAIN_BEHIND = 0
GOVERNING_PROMPT_SHA256 = (
    "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
)
CONTINUATION_REQUEST_SHA256 = (
    "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d"
)

RUN_177_MATERIALIZER_SHA256 = (
    "0537b22c0c2a3707c65ab422a87689b09bcdf645b1221d5876a067e8ac94a9f5"
)
RUN_177_RECEIPT_SHA256 = (
    "1b4cd64704c3137cfc98d0068900fe9068619dae700e519080b1d183639b5c2f"
)
RUN_177_RECEIPT_SELF_SEAL = (
    "f0fb73ebde74dfd53890a96572e6bdae56b2d832d665506eaf95f9e44e20a059"
)
FLEET_RECORD_CANONICAL_SHA256 = (
    "98c82f01cf8348fc4b60a4c17feea675182dc287e4c7907174b13d44af331fab"
)

COMMITTED_BUILDER = {
    "path": BUILDER,
    "sha256": "2e23eb4838a7ab4ddac1576c750fa56dcfd4b3828217bad003bf7aadec73dd96",
    "git_blob_id": "3f935ff5ea7b7bff9948ea6fd108eed1633592b0",
    "bytes": 615238,
    "lines": 5361,
}
FROZEN_RUN_175_DASHBOARD = {
    "path": HTML,
    "sha256": "8586a2cb3cc6c248788ea71ecc20c2e0c4785fd5a7a5a00fa11d2ee48f48490c",
    "git_blob_id": "1c1c521b674bcc12b5227aff1418a49ba0ace06a",
    "bytes": 280930,
    "lines": 78,
}

# Replace every PENDING value only after final-SHA builder and browser evidence
# has been returned to this lane. The assertions below intentionally make this
# source non-executable before that exact fact handoff.
FINAL_ARTIFACT_FACTS: dict[str, Any] = {
    "html_sha256": "70472c39504600f8c0b26b9ce05eb0f3e5903f1c6e9445163dba0581a2382600",
    "html_git_blob_id": "54d72ff77c370c09c0b06ba35c6afbedca3d738c",
    "html_bytes": 288289,
    "html_lines": 78,
    "html_binary_diff_sha256": "793b49415c1ea8bb35cb877b563abb628641caed9973fc9e7551fdafb79985d3",
    "html_diff_added": 19,
    "html_diff_deleted": 19,
    "builder_runs_observed": 2,
    "builder_runs_byte_identical": True,
    "independent_source_review_result": "GO",
    "independent_source_review_findings": [],
}

BROWSER_FACTS: dict[str, Any] = {
    "facts_supplied": True,
    "browser": "Codex in-app browser",
    "cachebuster": "main-519e00a9-70472c39",
    "target_url": (
        "http://127.0.0.1:43178/audit-dashboard.html?"
        "v=main-519e00a9-70472c39#progress"
    ),
    "loopback_port": 43178,
    "loopback_pid": 42288,
    "loopback_bind": "127.0.0.1",
    "server_executable": (
        "C:\\Users\\steph\\.cache\\codex-runtimes\\codex-primary-runtime\\"
        "dependencies\\python\\python.exe"
    ),
    "response_status": 200,
    "response_content_type": "text/html",
    "server_get_requests": 2,
    "server_get_response_statuses": [200, 200],
    "response_bytes": 288289,
    "response_sha256": (
        "70472c39504600f8c0b26b9ce05eb0f3e5903f1c6e9445163dba0581a2382600"
    ),
    "document_title": "Oblivion Findings current-source audit",
    "body_font_family": "Inter",
    "desktop_and_mobile_visual_inspection": "GO",
    "viewport_override_reset_after_test": True,
    "browser_tab_closed_after_test": True,
    "reset_browser_viewport": "1280x720",
    "reset_document_client": "1265x720",
    "font_loaded_at_all_viewports": True,
    "main_visible_at_all_viewports": True,
    "navigation_bounded_at_all_viewports": True,
    "navigation_scroll_contained_at_mobile": True,
    "tables_bounded_at_all_viewports": True,
    "table_wrappers_overflow_auto_at_all_viewports": True,
    "page_overflow_zero_at_all_final_viewports": True,
    "screens_visually_go": True,
    "navigation_clicks_required": 10,
    "navigation_clicks_passed": 10,
    "navigation_credited_batch_sizes": [3, 3, 4],
    "navigation_target_top_px": {
        "Progress": 0.21875,
        "RUN-177": 0.4375,
        "Pages": -0.09375,
        "Static census": -0.015625,
        "Runtime gates": 0.171875,
        "Benchmarks": -0.15625,
        "Modules": 0.203125,
        "Finding status": -0.109375,
        "Architecture": -0.484375,
        "Gaps": -0.296875,
    },
    "navigation_target_width_px": {
        "Progress": 1140,
        "RUN-177": 1140,
        "Pages": 1140,
        "Static census": 1140,
        "Runtime gates": 1140,
        "Benchmarks": 1140,
        "Modules": 1140,
        "Finding status": 1140,
        "Architecture": 1140,
        "Gaps": 1140,
    },
    "navigation_scroll_y": {
        "Progress": 2050,
        "RUN-177": 2387,
        "Pages": 20700,
        "Static census": 26598,
        "Runtime gates": 29693,
        "Benchmarks": 31118,
        "Modules": 32551,
        "Finding status": 34050,
        "Architecture": 35873,
        "Gaps": 36664,
    },
    "console_warning_entries": 0,
    "console_error_entries": 0,
    "uncaught_page_error_entries": 0,
    "browser_dev_log_entries": 0,
    "browser_dom_ids": 11,
    "browser_injected_ids": ["codex-browser-sidebar-comments-root"],
    "anchor_elements_rendered_in_browser": 828,
    "unique_local_resources_observed": 443,
}

VIEWPORTS: list[dict[str, Any]] = [
    {
        "requested": "1440x900",
        "actual_browser_viewport": "1440x900",
        "root_client_width": 1425,
        "root_scroll_width": 1425,
        "body_scroll_width": 1425,
        "page_overflow_px": 0,
        "navigation_client_width": 1425,
        "navigation_scroll_width": 1425,
        "navigation_overflow_x": "auto",
        "active_table_scrollers": 0,
        "table_wrappers": 10,
        "table_wrappers_with_overflow_x_auto": 10,
    },
    {
        "requested": "1280x800",
        "actual_browser_viewport": "1280x800",
        "root_client_width": 1265,
        "root_scroll_width": 1265,
        "body_scroll_width": 1265,
        "page_overflow_px": 0,
        "navigation_client_width": 1265,
        "navigation_scroll_width": 1265,
        "navigation_overflow_x": "auto",
        "active_table_scrollers": 0,
        "table_wrappers": 10,
        "table_wrappers_with_overflow_x_auto": 10,
    },
    {
        "requested": "1024x768",
        "actual_browser_viewport": "1024x768",
        "root_client_width": 1009,
        "root_scroll_width": 1009,
        "body_scroll_width": 1009,
        "page_overflow_px": 0,
        "navigation_client_width": 1009,
        "navigation_scroll_width": 1009,
        "navigation_overflow_x": "auto",
        "active_table_scrollers": 1,
        "table_wrappers": 10,
        "table_wrappers_with_overflow_x_auto": 10,
    },
    {
        "requested": "390x844",
        "actual_browser_viewport": "390x844",
        "root_client_width": 375,
        "root_scroll_width": 375,
        "body_scroll_width": 375,
        "page_overflow_px": 0,
        "navigation_client_width": 375,
        "navigation_scroll_width": 922,
        "navigation_overflow_x": "auto",
        "active_table_scrollers": 10,
        "table_wrappers": 10,
        "table_wrappers_with_overflow_x_auto": 10,
    },
]

NAVIGATION = [
    ("Progress", "#progress"),
    ("RUN-177", "#checkpoint"),
    ("Pages", "#pages"),
    ("Static census", "#static-census"),
    ("Runtime gates", "#runtime"),
    ("Benchmarks", "#benchmarks"),
    ("Modules", "#modules"),
    ("Finding status", "#findings"),
    ("Architecture", "#architecture"),
    ("Gaps", "#gaps"),
]
CREDITED_NAVIGATION_BATCHES = [
    ["Progress", "RUN-177", "Pages"],
    ["Static census", "Runtime gates", "Benchmarks"],
    ["Modules", "Finding status", "Architecture", "Gaps"],
]


def run(*args: str) -> bytes:
    return subprocess.run(args, cwd=ROOT, check=True, capture_output=True).stdout


def git(*args: str) -> str:
    return run("git", *args).decode("utf-8").rstrip("\r\n")


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


def strict_text(raw: bytes, label: str) -> str:
    assert not raw.startswith(b"\xef\xbb\xbf"), f"{label}: UTF-8 BOM"
    assert b"\r" not in raw, f"{label}: CR byte"
    assert raw.endswith(b"\n"), f"{label}: missing terminal LF"
    assert all(
        not line.endswith((b" ", b"\t")) for line in raw.splitlines()
    ), f"{label}: trailing whitespace"
    return raw.decode("utf-8")


def strict_json(relative: str) -> dict[str, Any]:
    def hook(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, (relative, key)
            result[key] = value
        return result

    raw = (AUDIT / relative).read_bytes()
    value = json.loads(strict_text(raw, relative), object_pairs_hook=hook)
    assert isinstance(value, dict)
    return value


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


def committed_record(relative: str) -> dict[str, Any]:
    repository_path = f"{PREFIX}/{relative}"
    raw = run("git", "show", f"{CHECKPOINT_COMMIT}:{repository_path}")
    return {
        "path": relative,
        "sha256": sha256(raw),
        "git_blob_id": git("rev-parse", f"{CHECKPOINT_COMMIT}:{repository_path}"),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


def diff_record(relative: str) -> dict[str, Any]:
    repository_path = f"{PREFIX}/{relative}"
    binary = run("git", "diff", "--binary", "--", repository_path)
    numstat = git("diff", "--numstat", "--", repository_path).split("\t")
    assert len(numstat) == 3 and numstat[2] == repository_path
    return {
        "path": relative,
        "binary_diff_sha256": sha256(binary),
        "numstat": {
            "added": int(numstat[0]),
            "deleted": int(numstat[1]),
        },
    }


def verify_self_seal(value: dict[str, Any], expected: str) -> None:
    without_seal = dict(value)
    actual = without_seal.pop("receipt_self_seal_sha256")
    assert actual == expected
    assert canonical_sha256(without_seal) == expected


class Parser(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.hrefs: list[str] = []
        self.ids: list[str] = []
        self.headings = 0
        self.tables = 0
        self.table_wraps = 0

    def handle_starttag(
        self,
        tag: str,
        attrs: list[tuple[str, str | None]],
    ) -> None:
        values = dict(attrs)
        if values.get("id"):
            self.ids.append(str(values["id"]))
        if tag == "a" and values.get("href") is not None:
            self.hrefs.append(str(values["href"]))
        if re.fullmatch(r"h[1-6]", tag):
            self.headings += 1
        if tag == "table":
            self.tables += 1
        if "table-wrap" in str(values.get("class", "")).split():
            self.table_wraps += 1


def is_local(href: str) -> bool:
    low = href.lower()
    return not (
        href.startswith("#")
        or href.startswith("//")
        or low.startswith(
            (
                "http://",
                "https://",
                "mailto:",
                "tel:",
                "javascript:",
                "data:",
            )
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


def validate_supplied_facts() -> None:
    assert FINAL_ARTIFACT_FACTS == {
        "html_sha256": FINAL_ARTIFACT_FACTS["html_sha256"],
        "html_git_blob_id": FINAL_ARTIFACT_FACTS["html_git_blob_id"],
        "html_bytes": FINAL_ARTIFACT_FACTS["html_bytes"],
        "html_lines": FINAL_ARTIFACT_FACTS["html_lines"],
        "html_binary_diff_sha256": FINAL_ARTIFACT_FACTS[
            "html_binary_diff_sha256"
        ],
        "html_diff_added": FINAL_ARTIFACT_FACTS["html_diff_added"],
        "html_diff_deleted": FINAL_ARTIFACT_FACTS["html_diff_deleted"],
        "builder_runs_observed": FINAL_ARTIFACT_FACTS[
            "builder_runs_observed"
        ],
        "builder_runs_byte_identical": FINAL_ARTIFACT_FACTS[
            "builder_runs_byte_identical"
        ],
        "independent_source_review_result": FINAL_ARTIFACT_FACTS[
            "independent_source_review_result"
        ],
        "independent_source_review_findings": FINAL_ARTIFACT_FACTS[
            "independent_source_review_findings"
        ],
    }
    assert not any(
        isinstance(value, str) and value.startswith("PENDING_RUN178")
        for value in FINAL_ARTIFACT_FACTS.values()
    )
    assert re.fullmatch(r"[0-9a-f]{64}", FINAL_ARTIFACT_FACTS["html_sha256"])
    assert re.fullmatch(
        r"[0-9a-f]{40}",
        FINAL_ARTIFACT_FACTS["html_git_blob_id"],
    )
    assert re.fullmatch(
        r"[0-9a-f]{64}",
        FINAL_ARTIFACT_FACTS["html_binary_diff_sha256"],
    )
    assert FINAL_ARTIFACT_FACTS["html_bytes"] > 0
    assert FINAL_ARTIFACT_FACTS["html_lines"] > 0
    assert FINAL_ARTIFACT_FACTS["html_diff_added"] > 0
    assert FINAL_ARTIFACT_FACTS["html_diff_deleted"] > 0
    assert FINAL_ARTIFACT_FACTS["builder_runs_observed"] >= 2
    assert FINAL_ARTIFACT_FACTS["builder_runs_byte_identical"] is True
    assert FINAL_ARTIFACT_FACTS["independent_source_review_result"] == "GO"
    assert FINAL_ARTIFACT_FACTS["independent_source_review_findings"] == []

    assert BROWSER_FACTS["facts_supplied"] is True
    assert BROWSER_FACTS["browser"] == "Codex in-app browser"
    assert not any(
        isinstance(value, str) and value.startswith("PENDING_RUN178")
        for value in BROWSER_FACTS.values()
    )
    assert BROWSER_FACTS["loopback_port"] > 0
    assert BROWSER_FACTS["loopback_pid"] > 0
    assert BROWSER_FACTS["loopback_bind"] == "127.0.0.1"
    assert BROWSER_FACTS["server_executable"].lower().endswith(
        "\\python.exe"
    )
    assert BROWSER_FACTS["target_url"].startswith(
        f"http://127.0.0.1:{BROWSER_FACTS['loopback_port']}/"
        "audit-dashboard.html?"
    )
    assert (
        f"v={BROWSER_FACTS['cachebuster']}" in BROWSER_FACTS["target_url"]
    )
    assert BROWSER_FACTS["target_url"].endswith("#progress")
    assert BROWSER_FACTS["response_status"] == 200
    assert BROWSER_FACTS["response_content_type"] == "text/html"
    assert BROWSER_FACTS["server_get_requests"] == 2
    assert BROWSER_FACTS["server_get_response_statuses"] == [200, 200]
    assert BROWSER_FACTS["response_bytes"] == FINAL_ARTIFACT_FACTS[
        "html_bytes"
    ]
    assert BROWSER_FACTS["response_sha256"] == FINAL_ARTIFACT_FACTS[
        "html_sha256"
    ]
    assert BROWSER_FACTS["cachebuster"] == (
        f"main-{CHECKPOINT_COMMIT[:8]}-"
        f"{FINAL_ARTIFACT_FACTS['html_sha256'][:8]}"
    )
    assert BROWSER_FACTS["document_title"] == (
        "Oblivion Findings current-source audit"
    )
    assert BROWSER_FACTS["body_font_family"] == "Inter"
    assert BROWSER_FACTS["desktop_and_mobile_visual_inspection"] == "GO"
    assert BROWSER_FACTS["reset_browser_viewport"] == "1280x720"
    assert BROWSER_FACTS["reset_document_client"] == "1265x720"
    for boolean_key in (
        "viewport_override_reset_after_test",
        "browser_tab_closed_after_test",
        "font_loaded_at_all_viewports",
        "main_visible_at_all_viewports",
        "navigation_bounded_at_all_viewports",
        "navigation_scroll_contained_at_mobile",
        "tables_bounded_at_all_viewports",
        "table_wrappers_overflow_auto_at_all_viewports",
        "page_overflow_zero_at_all_final_viewports",
        "screens_visually_go",
    ):
        assert BROWSER_FACTS[boolean_key] is True
    assert BROWSER_FACTS["navigation_clicks_required"] == 10
    assert BROWSER_FACTS["navigation_clicks_passed"] == 10
    assert BROWSER_FACTS["navigation_credited_batch_sizes"] == [3, 3, 4]
    navigation_labels = {label for label, _ in NAVIGATION}
    for fact_key in (
        "navigation_target_top_px",
        "navigation_target_width_px",
        "navigation_scroll_y",
    ):
        assert set(BROWSER_FACTS[fact_key]) == navigation_labels
    assert all(
        isinstance(value, (int, float)) and abs(value) <= 1
        for value in BROWSER_FACTS["navigation_target_top_px"].values()
    )
    assert all(
        value == 1140
        for value in BROWSER_FACTS["navigation_target_width_px"].values()
    )
    assert all(
        isinstance(value, int) and value > 0
        for value in BROWSER_FACTS["navigation_scroll_y"].values()
    )
    for zero_key in (
        "console_warning_entries",
        "console_error_entries",
        "uncaught_page_error_entries",
        "browser_dev_log_entries",
    ):
        assert BROWSER_FACTS[zero_key] == 0
    assert BROWSER_FACTS["browser_dom_ids"] > 0
    assert BROWSER_FACTS["browser_injected_ids"] == [
        "codex-browser-sidebar-comments-root"
    ]
    assert BROWSER_FACTS["anchor_elements_rendered_in_browser"] > 0
    assert BROWSER_FACTS["unique_local_resources_observed"] > 0

    assert [viewport["requested"] for viewport in VIEWPORTS] == [
        "1440x900",
        "1280x800",
        "1024x768",
        "390x844",
    ]
    for viewport in VIEWPORTS:
        assert not any(
            isinstance(value, str) and value.startswith("PENDING_RUN178")
            for value in viewport.values()
        )
        assert viewport["actual_browser_viewport"] == viewport["requested"]
        assert viewport["root_client_width"] > 0
        assert viewport["root_scroll_width"] > 0
        assert viewport["body_scroll_width"] > 0
        assert viewport["root_scroll_width"] == viewport["root_client_width"]
        assert viewport["body_scroll_width"] == viewport["root_client_width"]
        assert viewport["page_overflow_px"] == 0
        assert viewport["navigation_client_width"] > 0
        assert viewport["navigation_scroll_width"] > 0
        assert viewport["navigation_overflow_x"] == "auto"
        assert viewport["active_table_scrollers"] >= 0
        assert (
            viewport["active_table_scrollers"]
            <= viewport["table_wrappers"]
        )
        assert viewport["table_wrappers"] > 0
        assert (
            viewport["table_wrappers_with_overflow_x_auto"]
            == viewport["table_wrappers"]
        )


def cleanup_state() -> dict[str, Any]:
    port = BROWSER_FACTS["loopback_port"]
    pid = BROWSER_FACTS["loopback_pid"]
    script = f"""
$run178Listeners = @(Get-NetTCPConnection -State Listen -LocalPort {port} -ErrorAction SilentlyContinue)
$run178PidProcess = Get-Process -Id {pid} -ErrorAction SilentlyContinue
$run178Matching = @(Get-CimInstance Win32_Process | Where-Object {{ $_.ProcessId -eq {pid} -or ($_.Name -like 'python*.exe' -and $_.CommandLine -like '*http.server*{port}*') }})
[pscustomobject]@{{ listener_count = $run178Listeners.Count; exact_pid_present = ($null -ne $run178PidProcess); matching_process_count = $run178Matching.Count }} | ConvertTo-Json -Compress
"""
    raw = run(
        "powershell.exe",
        "-NoProfile",
        "-NonInteractive",
        "-Command",
        script,
    )
    value = json.loads(raw.decode("utf-8-sig"))
    assert value == {
        "listener_count": 0,
        "exact_pid_present": False,
        "matching_process_count": 0,
    }
    return value


def validate_repository_state() -> set[str]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("show", "-s", "--format=%T", "HEAD") == CHECKPOINT_TREE
    assert git("show", "-s", "--format=%P", "HEAD") == CHECKPOINT_PARENT
    assert git("rev-parse", "origin/main") == ORIGIN_MAIN
    assert git(
        "rev-list",
        "--left-right",
        "--count",
        "origin/main...HEAD",
    ).split() == [str(LOCAL_MAIN_BEHIND), str(LOCAL_MAIN_AHEAD)]
    run("git", "diff", "--cached", "--quiet")
    assert git("diff", "--check") == ""

    expected = {
        f" M {PREFIX}/{HTML}",
        f"?? {PREFIX}/{MATERIALIZER}",
    }
    if (AUDIT / OUTPUT).exists():
        expected.add(f"?? {PREFIX}/{OUTPUT}")
    observed = {
        line
        for line in git(
            "status",
            "--porcelain=v1",
            "--untracked-files=all",
        ).splitlines()
        if line
    }
    assert observed == expected, {
        "expected": sorted(expected),
        "observed": sorted(observed),
    }
    assert git("diff", "--name-only", "HEAD") == f"{PREFIX}/{HTML}"
    assert committed_record(BUILDER) == COMMITTED_BUILDER
    assert file_record(BUILDER) == COMMITTED_BUILDER
    assert committed_record(HTML) == FROZEN_RUN_175_DASHBOARD
    assert not list(AUDIT.rglob("__pycache__"))
    return observed


def validate_run_177_lineage() -> dict[str, Any]:
    audit_manifest = strict_json(AUDIT_RUN_MANIFEST)
    assert audit_manifest["governing_prompt"]["sha256"] == (
        GOVERNING_PROMPT_SHA256
    )
    assert GOVERNING_PROMPT_SHA256 != CONTINUATION_REQUEST_SHA256
    assert file_record(RUN_177_MATERIALIZER)["sha256"] == (
        RUN_177_MATERIALIZER_SHA256
    )
    assert file_record(RUN_177_RECEIPT)["sha256"] == RUN_177_RECEIPT_SHA256
    receipt = strict_json(RUN_177_RECEIPT)
    verify_self_seal(receipt, RUN_177_RECEIPT_SELF_SEAL)

    assert receipt["schema_version"] == (
        "run-177-fleet-trip-index-site-privacy-remediation-reporting-wave-33-v1"
    )
    assert receipt["run_id"] == (
        "RUN-177-FLEET-TRIP-INDEX-SITE-PRIVACY-01-REMEDIATION-REPORTING-WAVE-33"
    )
    assert receipt["pins"]["reporting_input_commit"] == CHECKPOINT_PARENT
    assert receipt["pins"]["reporting_input_tree"] == (
        "96c892f41d42b7e46b2825c1022032800238c0fc"
    )
    assert receipt["pins"]["origin_main_observed"] == ORIGIN_MAIN
    assert receipt["pins"]["reporting_materializer"] == file_record(
        RUN_177_MATERIALIZER
    )
    assert receipt["pins"]["dashboard_builder"] == COMMITTED_BUILDER
    assert receipt["pins"]["unchanged_run_175_dashboard"] == (
        FROZEN_RUN_175_DASHBOARD
    )
    assert receipt["pins"]["current_fleet_record_canonical_sha256"] == (
        FLEET_RECORD_CANONICAL_SHA256
    )
    assert receipt["reporting_transition"]["counts_after"] == {
        "retained_claim_records": 13,
        "provisional_source_claims": 8,
        "historical_already_fixed": 2,
        "historical_remediated": 3,
        "final_P0": 0,
        "final_P1": 0,
    }
    assert receipt["reporting_transition"][
        "new_target_record_canonical_sha256"
    ] == FLEET_RECORD_CANONICAL_SHA256
    assert receipt["bounded_execution_accounting"]["unique_total"] == {
        "tests": 88,
        "assertions": 1764,
    }
    assert receipt["preservation_boundary"]["static_ownership"] == {
        "owners": 665,
        "routes": 308,
        "pages": 357,
        "controller_action_bridges": 96,
        "ownership_status": "PENDING_FRESH_SEMANTIC_REVIEW",
    }
    assert receipt["preservation_boundary"]["queue"] == {
        "next_zero_based_index": 84,
        "next_queue_id": "RUN090-ROUTE-0085",
        "next_route_record_id": "RUN077-ROUTE-0693",
        "reviewed": 119,
        "pending": 388,
        "owned": 97,
        "without_ownership": 410,
        "advanced_by_run_177": False,
    }
    assert receipt["preservation_boundary"]["benchmark"] == {
        "mapped": 2,
        "total": 340,
        "final_no_match_or_NCM": 0,
        "unresolved": 338,
    }
    assert receipt["publication_boundary"] == {
        "origin_main": ORIGIN_MAIN,
        "fleet_application_published": False,
        "run_176_to_177_published": False,
        "publication_authorized": False,
        "materializer_performed_push_or_publication": False,
    }
    assert receipt["dashboard_forward_gate"]["required_run"] == "RUN-178"
    assert receipt["dashboard_forward_gate"]["dashboard_html_changed_by_run_177"] is False
    assert receipt["dashboard_forward_gate"]["fresh_rebuild_required"] is True
    assert receipt["dashboard_forward_gate"]["fresh_verification_required"] is True
    assert {
        key
        for key, value in receipt["credit_boundary"].items()
        if value
    } == {"live_findings_register_and_reporting_status"}
    assert receipt["artifact_completion_test_met"] is True
    assert receipt["audit_completion_test_met"] is False
    assert all(value is False for value in receipt["completion_boundary"].values())

    checkpoint_paths = set(
        git(
            "diff-tree",
            "--no-commit-id",
            "--name-only",
            "-r",
            CHECKPOINT_COMMIT,
        ).splitlines()
    )
    expected_checkpoint_paths = {
        f"{PREFIX}/{path}"
        for path in receipt["reporting_transition"]["reporting_surface_paths"]
    } | {
        f"{PREFIX}/{RUN_177_MATERIALIZER}",
        f"{PREFIX}/{RUN_177_RECEIPT}",
    }
    assert checkpoint_paths == expected_checkpoint_paths
    assert f"{PREFIX}/{HTML}" not in checkpoint_paths
    return receipt


def validate_static_dashboard(
    raw: bytes,
) -> tuple[
    Parser,
    list[str],
    list[str],
    list[str],
    list[tuple[str, str]],
    dict[str, bool],
    dict[str, str],
]:
    text = strict_text(raw, HTML)
    parser = Parser()
    parser.feed(text)
    assert parser.headings == 26
    assert parser.tables == 10
    assert parser.table_wraps == 10
    assert len(parser.ids) == 10

    id_counts = Counter(parser.ids)
    duplicate_authored_ids = sorted(
        key for key, count in id_counts.items() if count > 1
    )
    assert not duplicate_authored_ids
    hash_hrefs = [href for href in parser.hrefs if href.startswith("#")]
    assert len(hash_hrefs) == 10
    assert len(set(hash_hrefs)) == 10
    assert [href for _, href in NAVIGATION] == hash_hrefs
    missing_anchors = sorted(
        {href for href in hash_hrefs if href[1:] not in id_counts}
    )
    assert not missing_anchors

    local_hrefs = [href for href in parser.hrefs if is_local(href)]
    unique_local = sorted(set(local_hrefs))
    external_or_empty_hrefs = [
        href
        for href in parser.hrefs
        if not href.startswith("#") and not is_local(href)
    ]
    assert not external_or_empty_hrefs
    assert BROWSER_FACTS["anchor_elements_rendered_in_browser"] == len(
        parser.hrefs
    )
    assert BROWSER_FACTS["browser_dom_ids"] == (
        len(parser.ids) + len(BROWSER_FACTS["browser_injected_ids"])
    )
    assert BROWSER_FACTS["unique_local_resources_observed"] == len(unique_local)
    assert text.count(f'href="{MATERIALIZER}"') == 2
    assert text.count(f'href="{OUTPUT}"') == 3
    assert MATERIALIZER in unique_local
    assert OUTPUT in unique_local

    non_forward_unique = [href for href in unique_local if href != OUTPUT]
    prewrite_local_failures = [
        href for href in non_forward_unique if not local_path(href).exists()
    ]
    assert not prewrite_local_failures

    hash_pairs = re.findall(
        r'<a href="([^"#]+)">[^<]*</a>\s*<code>([0-9a-f]{64})</code>',
        text,
    )
    assert len(hash_pairs) == 709
    assert len(set(hash_pairs)) == 370
    assert len({path for path, _ in hash_pairs}) == 370
    hash_failures: list[dict[str, str]] = []
    hash_bearing_file_occurrences = 0
    hash_bearing_file_paths: set[str] = set()
    directory_digest_occurrences = 0
    directory_digest_paths: set[str] = set()
    for href, expected in hash_pairs:
        target = local_path(href)
        if target.is_file():
            hash_bearing_file_occurrences += 1
            hash_bearing_file_paths.add(href)
            actual = sha256(target.read_bytes())
            if actual != expected:
                hash_failures.append(
                    {
                        "href": href,
                        "expected": expected,
                        "actual": actual,
                    }
                )
        elif target.is_dir():
            directory_digest_occurrences += 1
            directory_digest_paths.add(href)
    assert not hash_failures
    assert hash_bearing_file_occurrences == 707
    assert len(hash_bearing_file_paths) == 369
    assert directory_digest_occurrences == 2
    assert directory_digest_paths == {"task-scripts/"}
    assert sum(1 for href, _ in hash_pairs if href == MATERIALIZER) == 0
    assert sum(1 for href, _ in hash_pairs if href == OUTPUT) == 0

    builder_text = strict_text((AUDIT / BUILDER).read_bytes(), BUILDER)
    for required_builder_boundary in (
        "current_visible_boundaries = [",
        "RUN-175–177 Fleet trip index Site privacy remediation checkpoint",
        "Fresh RUN-178 audit-dashboard verification required",
        MATERIALIZER,
        OUTPUT,
        ".tmp-run178-dashboard",
        "existing_output_bytes in (run_175_dashboard_payload, output_bytes)",
    ):
        assert required_builder_boundary in builder_text
    assert (
        f'read_json_strict("{OUTPUT}")'
        not in builder_text
    )
    required_visible = literal_list_assignment(
        builder_text,
        "current_visible_boundaries",
    )
    assert len(required_visible) == 97
    assert len(set(required_visible)) == 97
    visible_static = {value: value in text for value in required_visible}
    assert len(visible_static) == 97
    assert all(visible_static.values()), [
        value for value, present in visible_static.items() if not present
    ]
    assert "tenant" not in text.lower()

    prohibited_text = {
        "stale_current_navigation": '<a href="#checkpoint">RUN-174</a>',
        "stale_fresh_run_175": (
            "Fresh RUN-175 audit-dashboard verification required"
        ),
        "stale_run_174_checkpoint": (
            "RUN-172–174 SAFE alert dedup remediation and reporting checkpoint"
        ),
        "incorrect_fleet_publication": (
            "Fleet application fix published to origin/main"
        ),
        "incorrect_gate_4": "Gate 4 and audit completion true",
        "incorrect_fleet_final_finding": (
            "FLEET-TRIP-INDEX-SITE-PRIVACY-01 final finding"
        ),
        "incorrect_fleet_completion": (
            "Fleet remediation completes Fleet Assets"
        ),
        "incorrect_queue_ownership": "queue index 84 is owned",
        "incorrect_route_ownership": (
            "CAP-FLEET-VEHICLE-REGISTER owns fleet-assets.trips.index"
        ),
    }
    prohibited_hits = {
        key: value
        for key, value in prohibited_text.items()
        if value in text
    }
    assert not prohibited_hits

    return (
        parser,
        hash_hrefs,
        local_hrefs,
        unique_local,
        hash_pairs,
        visible_static,
        prohibited_hits,
    )


def build_receipt(
    run_177: dict[str, Any],
    parser: Parser,
    hash_hrefs: list[str],
    local_hrefs: list[str],
    unique_local: list[str],
    hash_pairs: list[tuple[str, str]],
    visible_static: dict[str, bool],
    prohibited_hits: dict[str, str],
    cleanup: dict[str, Any],
    dashboard_diff: dict[str, Any],
) -> dict[str, Any]:
    navigation_batch_by_label = {
        label: batch_number
        for batch_number, labels in enumerate(CREDITED_NAVIGATION_BATCHES, 1)
        for label in labels
    }
    navigation_results = [
        {
            "label": label,
            "href": href,
            "credited_batch": navigation_batch_by_label[label],
            "browser_click_performed": True,
            "resulting_hash": href,
            "target_exists": True,
            "target_top_px": BROWSER_FACTS["navigation_target_top_px"][label],
            "target_width_px": BROWSER_FACTS[
                "navigation_target_width_px"
            ][label],
            "scroll_y": BROWSER_FACTS["navigation_scroll_y"][label],
            "pass": True,
        }
        for label, href in NAVIGATION
    ]
    completion = {
        "framework_route_reachability_complete": False,
        "semantic_assurance_complete": False,
        "execution_complete": False,
        "coverage_complete": False,
        "benchmark_complete": False,
        "pass_8_complete": False,
        "final_reconciliation_complete": False,
        "no_live_agent_gate_complete": False,
        "full_crosswalk_complete": False,
        "gate_4_complete": False,
        "audit_complete": False,
    }
    credit = {
        "exact_audit_dashboard_artifact": True,
        "application_source_or_tests": False,
        "application_runtime": False,
        "application_browser": False,
        "publication": False,
        "benchmark_mapping": False,
        "final_no_match_or_NCM": False,
        "ease": False,
        "pass": False,
        "release": False,
        "final_finding": False,
        "completion": False,
        "audit_complete": False,
    }

    hash_bearing_file_occurrences = sum(
        local_path(href).is_file() for href, _ in hash_pairs
    )
    hash_bearing_file_paths = {
        href for href, _ in hash_pairs if local_path(href).is_file()
    }
    directory_digest_occurrences = sum(
        local_path(href).is_dir() for href, _ in hash_pairs
    )
    directory_digest_paths = sorted(
        {href for href, _ in hash_pairs if local_path(href).is_dir()}
    )
    final_dashboard = file_record(HTML)
    final_builder = file_record(BUILDER)

    receipt: dict[str, Any] = {
        "schema_version": "run-178-audit-dashboard-verification-wave-33-v1",
        "run_id": "RUN-178-AUDIT-DASHBOARD-VERIFICATION-WAVE-33",
        "generated_on": "2026-08-30",
        "status": (
            "AUDIT_DASHBOARD_RUN177_EXACT_ARTIFACT_RESPONSIVE_LINK_CONSOLE_"
            "VERIFICATION_GO_LOCAL_ONLY_ZERO_APPLICATION_PUBLICATION_FINAL_"
            "FINDING_OR_COMPLETION_CREDIT"
        ),
        "architecture_rule": {
            "operating_organisations": 1,
            "multiple_sites": True,
            "multi_tenant": False,
            "authorization_boundary": (
                "Site access, exact roles and permissions, canonical ownership, "
                "privacy, and direct-object denial"
            ),
        },
        "scope": (
            "Exact RUN-177 reporting dashboard and bounded audit-artifact "
            "verification only"
        ),
        "pins": {
            "governing_prompt_sha256": GOVERNING_PROMPT_SHA256,
            "continuation_request_sha256": CONTINUATION_REQUEST_SHA256,
            "continuation_request_is_not_governing_prompt": True,
            "audit_run_manifest": file_record(AUDIT_RUN_MANIFEST),
            "run_177_checkpoint_commit": CHECKPOINT_COMMIT,
            "run_177_checkpoint_tree": CHECKPOINT_TREE,
            "run_177_checkpoint_parent": CHECKPOINT_PARENT,
            "origin_main_before_run_178_commit": ORIGIN_MAIN,
            "local_main_ahead": LOCAL_MAIN_AHEAD,
            "local_main_behind": LOCAL_MAIN_BEHIND,
            "run_177_materializer": file_record(RUN_177_MATERIALIZER),
            "run_177_receipt": {
                **file_record(RUN_177_RECEIPT),
                "receipt_self_seal_sha256": RUN_177_RECEIPT_SELF_SEAL,
            },
            "run_177_committed_builder": COMMITTED_BUILDER,
            "run_175_frozen_dashboard_at_run_177_checkpoint": (
                FROZEN_RUN_175_DASHBOARD
            ),
            "run_178_builder": final_builder,
            "run_178_dashboard": final_dashboard,
            "run_178_receipt_materializer": file_record(MATERIALIZER),
            "fleet_record_canonical_sha256": (
                FLEET_RECORD_CANONICAL_SHA256
            ),
        },
        "lineage": {
            "run_175": (
                "verifies only the now-superseded RUN-174 dashboard"
            ),
            "run_176": (
                "establishes Fleet remediation, bounded runtime, local-main "
                "integration, and nonpublication"
            ),
            "run_176r": (
                "independently authorizes one historical-remediated record only"
            ),
            "run_177": (
                "adds that record and reports 8+2+3 with 88/1,764 unique "
                "bounded execution while freezing RUN-175 HTML"
            ),
            "run_178": (
                "generates from exact committed RUN-177 sources and verifies "
                "only the resulting audit artifact"
            ),
            "historical_8_plus_2_plus_2_visible_only_as_superseded_lineage": True,
        },
        "dashboard_generation": {
            "committed_builder": COMMITTED_BUILDER,
            "final_builder": final_builder,
            "builder_changed_by_run_178": False,
            "committed_dashboard": FROZEN_RUN_175_DASHBOARD,
            "final_dashboard": final_dashboard,
            "dashboard_change": dashboard_diff,
            "final_builder_runs_observed": FINAL_ARTIFACT_FACTS[
                "builder_runs_observed"
            ],
            "final_builder_runs_byte_identical": FINAL_ARTIFACT_FACTS[
                "builder_runs_byte_identical"
            ],
            "independent_source_review": {
                "result": FINAL_ARTIFACT_FACTS[
                    "independent_source_review_result"
                ],
                "findings": FINAL_ARTIFACT_FACTS[
                    "independent_source_review_findings"
                ],
                "dashboard_sha256": FINAL_ARTIFACT_FACTS["html_sha256"],
                "builder_sha256": COMMITTED_BUILDER["sha256"],
                "visible_static_checks": (
                    f"{len(visible_static)}/{len(visible_static)}"
                ),
                "browser_credit": False,
                "reviewer_file_changes": [],
            },
            "application_source_or_product_test_change": False,
            "credit_effect": (
                "exact audit-dashboard artifact verification only"
            ),
        },
        "verification_method": {
            "browser": BROWSER_FACTS["browser"],
            "served_from": (
                "temporary loopback-only HTTP server on "
                f"127.0.0.1:{BROWSER_FACTS['loopback_port']}"
            ),
            "server_executable": BROWSER_FACTS["server_executable"],
            "target_url": BROWSER_FACTS["target_url"],
            "cachebuster": BROWSER_FACTS["cachebuster"],
            "response_status": BROWSER_FACTS["response_status"],
            "response_content_type": BROWSER_FACTS[
                "response_content_type"
            ],
            "server_get_requests": BROWSER_FACTS["server_get_requests"],
            "server_get_response_statuses": BROWSER_FACTS[
                "server_get_response_statuses"
            ],
            "response_bytes": FINAL_ARTIFACT_FACTS["html_bytes"],
            "response_sha256": FINAL_ARTIFACT_FACTS["html_sha256"],
            "external_testing": False,
            "desktop_and_mobile_visual_inspection": BROWSER_FACTS[
                "desktop_and_mobile_visual_inspection"
            ],
            "viewport_override_reset_after_test": BROWSER_FACTS[
                "viewport_override_reset_after_test"
            ],
            "browser_tab_closed_after_test": BROWSER_FACTS[
                "browser_tab_closed_after_test"
            ],
            "reset_browser_viewport": BROWSER_FACTS[
                "reset_browser_viewport"
            ],
            "reset_document_client": BROWSER_FACTS[
                "reset_document_client"
            ],
        },
        "verification": {
            "dashboard_builder_final_byte_identical_runs_observed": (
                FINAL_ARTIFACT_FACTS["builder_runs_observed"]
            ),
            "dashboard_builder_final_runs_byte_identical": (
                FINAL_ARTIFACT_FACTS["builder_runs_byte_identical"]
            ),
            "receipt_materializer_final_byte_identical_runs_required": 2,
            "noncreditable_attempts": [],
            "viewports_required": 4,
            "viewports_verified": 4,
            "viewports": VIEWPORTS,
            "font_loaded_at_all_viewports": BROWSER_FACTS[
                "font_loaded_at_all_viewports"
            ],
            "main_visible_at_all_viewports": BROWSER_FACTS[
                "main_visible_at_all_viewports"
            ],
            "navigation_bounded_at_all_viewports": BROWSER_FACTS[
                "navigation_bounded_at_all_viewports"
            ],
            "navigation_scroll_contained_at_mobile": BROWSER_FACTS[
                "navigation_scroll_contained_at_mobile"
            ],
            "tables_bounded_at_all_viewports": BROWSER_FACTS[
                "tables_bounded_at_all_viewports"
            ],
            "table_wrappers_overflow_auto_at_all_viewports": BROWSER_FACTS[
                "table_wrappers_overflow_auto_at_all_viewports"
            ],
            "page_overflow_zero_at_all_final_viewports": BROWSER_FACTS[
                "page_overflow_zero_at_all_final_viewports"
            ],
            "screens_visually_go": BROWSER_FACTS["screens_visually_go"],
            "navigation_clicks_required": BROWSER_FACTS[
                "navigation_clicks_required"
            ],
            "navigation_clicks_passed": BROWSER_FACTS[
                "navigation_clicks_passed"
            ],
            "navigation_credited_batch_sizes": BROWSER_FACTS[
                "navigation_credited_batch_sizes"
            ],
            "navigation_results": navigation_results,
            "console_warning_entries": BROWSER_FACTS[
                "console_warning_entries"
            ],
            "console_error_entries": BROWSER_FACTS[
                "console_error_entries"
            ],
            "uncaught_page_error_entries": BROWSER_FACTS[
                "uncaught_page_error_entries"
            ],
            "browser_dev_log_entries": BROWSER_FACTS[
                "browser_dev_log_entries"
            ],
            "authored_ids": len(parser.ids),
            "browser_dom_ids": BROWSER_FACTS["browser_dom_ids"],
            "browser_injected_ids": BROWSER_FACTS[
                "browser_injected_ids"
            ],
            "duplicate_authored_ids": [],
            "heading_elements": parser.headings,
            "table_elements": parser.tables,
            "table_wrappers": parser.table_wraps,
            "anchor_elements": len(parser.hrefs),
            "anchor_elements_rendered_in_browser": BROWSER_FACTS[
                "anchor_elements_rendered_in_browser"
            ],
            "hash_anchor_occurrences": len(hash_hrefs),
            "unique_hash_anchors": len(set(hash_hrefs)),
            "missing_hash_targets": [],
            "local_resource_link_occurrences": len(local_hrefs),
            "unique_local_resources": len(unique_local),
            "prewrite_non_forward_local_resources": (
                f"{len(unique_local) - 1}/{len(unique_local) - 1}"
            ),
            "prewrite_non_forward_local_resource_failures": [],
            "run_178_forward_receipt_is_intentional_unhashed_self_link": True,
            "post_materialization_local_resources": (
                f"{len(unique_local)}/{len(unique_local)}"
            ),
            "post_materialization_local_resource_failures": [],
            "adjacent_hash_pair_occurrences": len(hash_pairs),
            "unique_adjacent_hash_path_hash_pairs": len(set(hash_pairs)),
            "unique_adjacent_hash_paths": len(
                {path for path, _ in hash_pairs}
            ),
            "hash_bearing_file_occurrences_verified": (
                hash_bearing_file_occurrences
            ),
            "unique_hash_bearing_file_paths_verified": len(
                hash_bearing_file_paths
            ),
            "historical_directory_bundle_digest_occurrences": (
                directory_digest_occurrences
            ),
            "historical_directory_bundle_digest_paths": (
                directory_digest_paths
            ),
            "hash_bearing_link_failures": [],
            "run_178_generator_link_occurrences": (
                (AUDIT / HTML).read_text(encoding="utf-8").count(
                    f'href="{MATERIALIZER}"'
                )
            ),
            "run_178_generator_link_adjacent_hash_occurrences": sum(
                1 for href, _ in hash_pairs if href == MATERIALIZER
            ),
            "run_178_forward_receipt_link_occurrences": (
                (AUDIT / HTML).read_text(encoding="utf-8").count(
                    f'href="{OUTPUT}"'
                )
            ),
            "run_178_forward_receipt_link_adjacent_hash_occurrences": sum(
                1 for href, _ in hash_pairs if href == OUTPUT
            ),
            "visible_static_checks_required": len(visible_static),
            "visible_static_checks_passed": sum(visible_static.values()),
            "visible_static_checks": visible_static,
            "prohibited_visible_phrase_hits": prohibited_hits,
            "single_organisation_multi_site_wording_present": True,
            "tenant_word_present": False,
        },
        "reported_finding_boundary": {
            "retained_claim_records": 13,
            "current_provisional_source_claims": 8,
            "historical_already_fixed_records": 2,
            "historical_remediated_records": 3,
            "superseded_pre_run_177_split": {
                "current_provisional_source_claims": 8,
                "historical_already_fixed_records": 2,
                "historical_remediated_records": 2,
                "current_credit": False,
            },
            "final_P0": 0,
            "final_P1": 0,
            "fleet_trip_index_site_privacy_status": (
                "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_"
                "NOT_PUBLISHED_NOT_FINAL_FINDING"
            ),
            "fleet_feature_identity_status": (
                "PENDING_FRESH_SEMANTIC_REVIEW"
            ),
            "fleet_candidate_feature_id": "CAP-FLEET-VEHICLE-REGISTER",
        },
        "bounded_execution_accounting": {
            "med_rbac": {"tests": 73, "assertions": 1481},
            "med_scope": {"tests": 5, "assertions": 48},
            "safe_post_merge_unique_increment": {
                "tests": 5,
                "assertions": 60,
                "counted_once": True,
            },
            "fleet_post_merge_unique_increment": {
                "tests": 5,
                "assertions": 175,
                "counted_once": True,
            },
            "unique_total": {"tests": 88, "assertions": 1764},
            "excluded_from_unique_total": run_177[
                "bounded_execution_accounting"
            ]["excluded_from_unique_total"],
            "red_replay_or_supporting_recredit": False,
        },
        "static_ownership_boundary": {
            "owner_records": 665,
            "route_owners": 308,
            "page_owners": 357,
            "action_bridges": 96,
            "queue_total": 507,
            "queue_reviewed": 119,
            "queue_pending": 388,
            "queue_owned": 97,
            "queue_without_ownership": 410,
            "next_zero_based_index": 84,
            "next_queue_id": "RUN090-ROUTE-0085",
            "next_route_record_id": "RUN077-ROUTE-0693",
            "next_route": "fleet-assets.trips.index",
            "ownership_status": "PENDING_FRESH_SEMANTIC_REVIEW",
            "correctness_credit": False,
        },
        "benchmark_boundary": {
            "mapped": 2,
            "total": 340,
            "final_no_match_or_NCM": 0,
            "unresolved": 338,
            "changed_by_run_178": False,
        },
        "noninheritance_boundary": {
            "application_source_or_product_test": False,
            "application_runtime_reexecution": False,
            "application_browser": False,
            "static_route_feature_ownership": False,
            "static_controller_action_bridge": False,
            "static_page_or_frontend_ownership": False,
            "queue_advance": False,
            "fleet_red_isolated_green_or_supporting_runs": False,
            "broader_fleet_permission_privacy_or_direct_object_correctness": False,
            "benchmark_mapping_or_final_no_match_NCM": False,
            "ease": False,
            "final_finding": False,
            "feature_module_or_pass_completion": False,
            "release_publication_or_audit_completion": False,
        },
        "root_browser_resource_cleanup": {
            "temporary_loopback_port": BROWSER_FACTS[
                "loopback_port"
            ],
            "temporary_server_pid": BROWSER_FACTS["loopback_pid"],
            "temporary_server_executable": BROWSER_FACTS[
                "server_executable"
            ],
            "server_get_requests": BROWSER_FACTS[
                "server_get_requests"
            ],
            "listeners_after_cleanup": cleanup["listener_count"],
            "exact_pid_present_after_cleanup": cleanup[
                "exact_pid_present"
            ],
            "matching_loopback_server_processes_after_cleanup": cleanup[
                "matching_process_count"
            ],
            "browser_viewport_override_reset": BROWSER_FACTS[
                "viewport_override_reset_after_test"
            ],
            "browser_tab_closed": BROWSER_FACTS[
                "browser_tab_closed_after_test"
            ],
        },
        "worktree_boundary": {
            "expected_final_status_count": 3,
            "expected_final_porcelain_statuses": sorted(
                {
                    f" M {PREFIX}/{HTML}",
                    f"?? {PREFIX}/{MATERIALIZER}",
                    f"?? {PREFIX}/{OUTPUT}",
                }
            ),
            "no_staged_paths": True,
            "git_diff_check_clean": True,
            "exact_match_required": True,
        },
        "mutation_attestation": {
            "sequence_paths": [HTML, MATERIALIZER, OUTPUT],
            "receipt_materializer_persistent_write_scope": [OUTPUT],
            "builder_changed_by_run_178": False,
            "application_paths_changed": [],
            "product_test_paths_changed": [],
            "findings_register_changed_by_run_178": False,
            "run_177_human_readable_reporting_surfaces_changed_by_run_178": False,
            "audit_dashboard_html_changed_by_run_178": True,
            "forms_submitted": False,
            "records_opened": False,
            "screenshots_retained": False,
            "database_changed": False,
            "application_tests_or_build_run": False,
        },
        "remote_state_boundary": {
            "origin_main_before_run_178_commit": ORIGIN_MAIN,
            "local_main_ahead_before_run_178_commit": LOCAL_MAIN_AHEAD,
            "local_main_behind_before_run_178_commit": LOCAL_MAIN_BEHIND,
            "safe_fleet_and_board_application_merges_remain_local_only": True,
            "push_or_publication_performed_by_materializer": False,
            "publication_claim": False,
        },
        "credit_boundary": credit,
        "completion_boundary": completion,
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "run_178_sequence_written_paths": [
            f"{PREFIX}/{HTML}",
            f"{PREFIX}/{MATERIALIZER}",
            f"{PREFIX}/{OUTPUT}",
        ],
    }
    assert {
        key for key, value in credit.items() if value
    } == {"exact_audit_dashboard_artifact"}
    assert all(value is False for value in completion.values())
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    return receipt


def write_receipt(receipt: dict[str, Any]) -> bytes:
    output_bytes = (
        json.dumps(receipt, ensure_ascii=False, indent=2) + "\n"
    ).encode("utf-8")
    output_path = AUDIT / OUTPUT
    temporary_path = output_path.with_name(
        f".{output_path.name}.tmp-run178"
    )
    assert not temporary_path.exists(), (
        f"Refusing to overwrite stale receipt temp: {temporary_path}"
    )
    try:
        with temporary_path.open("xb") as handle:
            handle.write(output_bytes)
            handle.flush()
            os.fsync(handle.fileno())
        assert temporary_path.read_bytes() == output_bytes
        os.replace(temporary_path, output_path)
    finally:
        if temporary_path.exists():
            temporary_path.unlink()
    assert output_path.read_bytes() == output_bytes
    written = strict_json(OUTPUT)
    without_seal = dict(written)
    written_seal = without_seal.pop("receipt_self_seal_sha256")
    assert canonical_sha256(without_seal) == written_seal
    return output_bytes


def main() -> None:
    validate_supplied_facts()
    validate_repository_state()
    run_177 = validate_run_177_lineage()

    raw = (AUDIT / HTML).read_bytes()
    expected_final_dashboard = {
        "path": HTML,
        "sha256": FINAL_ARTIFACT_FACTS["html_sha256"],
        "git_blob_id": FINAL_ARTIFACT_FACTS["html_git_blob_id"],
        "bytes": FINAL_ARTIFACT_FACTS["html_bytes"],
        "lines": FINAL_ARTIFACT_FACTS["html_lines"],
    }
    assert file_record(HTML) == expected_final_dashboard
    assert expected_final_dashboard != FROZEN_RUN_175_DASHBOARD
    dashboard_diff = diff_record(HTML)
    assert dashboard_diff == {
        "path": HTML,
        "binary_diff_sha256": FINAL_ARTIFACT_FACTS[
            "html_binary_diff_sha256"
        ],
        "numstat": {
            "added": FINAL_ARTIFACT_FACTS["html_diff_added"],
            "deleted": FINAL_ARTIFACT_FACTS["html_diff_deleted"],
        },
    }
    (
        parser,
        hash_hrefs,
        local_hrefs,
        unique_local,
        hash_pairs,
        visible_static,
        prohibited_hits,
    ) = validate_static_dashboard(raw)
    cleanup = cleanup_state()
    receipt = build_receipt(
        run_177,
        parser,
        hash_hrefs,
        local_hrefs,
        unique_local,
        hash_pairs,
        visible_static,
        prohibited_hits,
        cleanup,
        dashboard_diff,
    )
    output_bytes = write_receipt(receipt)

    assert file_record(BUILDER) == COMMITTED_BUILDER
    assert file_record(HTML) == expected_final_dashboard
    assert all(local_path(href).exists() for href in unique_local)
    validate_repository_state()
    print(
        json.dumps(
            {
                "run_id": receipt["run_id"],
                "status": receipt["status"],
                "dashboard_sha256": FINAL_ARTIFACT_FACTS["html_sha256"],
                "builder_sha256": COMMITTED_BUILDER["sha256"],
                "materializer_sha256": file_record(MATERIALIZER)["sha256"],
                "receipt_sha256": sha256(output_bytes),
                "receipt_self_seal_sha256": receipt[
                    "receipt_self_seal_sha256"
                ],
                "visible_checks": len(visible_static),
                "navigation": "10/10",
                "viewports": "4/4",
                "unique_local_resources": len(unique_local),
                "published": False,
                "audit_complete": False,
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
