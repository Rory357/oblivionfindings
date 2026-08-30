#!/usr/bin/env python3
"""Seal bounded RUN182 facts for the exact RUN181 audit dashboard.

The released RUN181 reporting sources and the generated HTML are validated
from exact bytes. Browser and final-artifact facts remain explicit fail-closed
placeholders until the root browser lane returns its bounded loopback-only
observations. This producer writes only its receipt and grants no application,
runtime, product-test, benchmark, NCM, finding, release, publication, Gate 4,
feature, module, or audit-completion credit.
"""
from __future__ import annotations

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


ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24"
PREFIX = AUDIT.relative_to(ROOT).as_posix()
MATERIALIZER = (
    "generators/materialize-run-182-audit-dashboard-verification-wave-34.py"
)
OUTPUT = (
    "evidence/browser/current-audit-dashboard-verification-run-182-wave-34.json"
)
HTML = "audit-dashboard.html"
BUILDER = "generators/build-current-audit-dashboard.py"
FINDINGS = "findings.json"
RUN_181_MATERIALIZER = (
    "generators/materialize-run-181-reviewed-fleet-trip-index-route-action-"
    "reporting-wave-34.py"
)
RUN_181_RECEIPT = (
    "evidence/source/current-run-181-reviewed-fleet-trip-index-route-action-"
    "reporting-wave-34.json"
)
RUN_178_MATERIALIZER = (
    "generators/materialize-run-178-audit-dashboard-verification-wave-33.py"
)
RUN_178_RECEIPT = (
    "evidence/browser/current-audit-dashboard-verification-run-178-wave-33.json"
)
AUDIT_RUN_MANIFEST = "evidence/source/audit-run-manifest.json"

CHECKPOINT_COMMIT = "0975bf1cd3355da1f30e84056ae53107bd9b5bfc"
CHECKPOINT_TREE = "f329927c358a3a8f53425170de786a9349755413"
CHECKPOINT_PARENT = "673d2aadd477e6fa265e62aacad19273cb21122a"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"
LOCAL_MAIN_AHEAD = 17
LOCAL_MAIN_BEHIND = 0
GOVERNING_PROMPT_SHA256 = (
    "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
)
CONTINUATION_REQUEST_SHA256 = (
    "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d"
)

RUN_181_MATERIALIZER_RECORD = {
    "path": RUN_181_MATERIALIZER,
    "sha256": "31d674365e17035b9013864f061760f7034367b6b54551124bf4ec482df642ea",
    "git_blob_id": "444320a449e684029082472c07e16de0d8d7489f",
    "bytes": 37396,
    "lines": 760,
}
RUN_181_RECEIPT_RECORD = {
    "path": RUN_181_RECEIPT,
    "sha256": "c1db8b498b7344c2ab28f5c6373caaa8f2ac4a1d764e6129fb49c415234794a8",
    "git_blob_id": "25cdf8834fcdd6fe966fb5d660c1b94cf479f585",
    "bytes": 16164,
    "lines": 379,
}
RUN_181_RECEIPT_SELF_SEAL = (
    "862854486a06bca5d4c05917cef2e9b45885d1e85a0f1d9130205a800e9ae70c"
)
RUN_181_COMMITTED_BUILDER = {
    "path": BUILDER,
    "sha256": "d4e8efd2aa9e80ad26389bdb0e6f21faefb02c53face26d9fb9a6119e673dc26",
    "git_blob_id": "7343321d4f3b1e51b77d94c94258a3466430a88f",
    "bytes": 635636,
    "lines": 5515,
}
CORRECTED_RUN_182_BUILDER = {
    "path": BUILDER,
    "sha256": "4391b5a4ae8a6e464ded635b6fbf66801fe9bc83a4e5175bbea5619278405fcc",
    "git_blob_id": "036329690584f7c59381d3175b8776c8bbe5b6f0",
    "bytes": 635684,
    "lines": 5518,
}
CORRECTED_RUN_182_BUILDER_DIFF = {
    "path": BUILDER,
    "binary_diff_sha256": (
        "1e547eeb252e0d306667708aa209f4a83753acaf3c08422f0e4a6c8e6fd370d2"
    ),
    "numstat": {"added": 5, "deleted": 2},
}
COMMITTED_FINDINGS = {
    "path": FINDINGS,
    "sha256": "55337abfc8f2fe9fde863715e3d77649ec6dd195008281944881b02e00bb54e1",
    "git_blob_id": "bd0a13dc86ebdc88073ee3ac999b3514ac0a0490",
    "bytes": 590974,
    "lines": 10553,
}
FROZEN_RUN_178_DASHBOARD = {
    "path": HTML,
    "sha256": "70472c39504600f8c0b26b9ce05eb0f3e5903f1c6e9445163dba0581a2382600",
    "git_blob_id": "54d72ff77c370c09c0b06ba35c6afbedca3d738c",
    "bytes": 288289,
    "lines": 78,
}
RUN_178_MATERIALIZER_SHA256 = (
    "ffedf87ea3cae8b74cd280f676f3fb671e9a2885dad0a3ef8564d0ed21f8d53d"
)
RUN_178_RECEIPT_SHA256 = (
    "9a41983d86fa3fbe054d1ddb848a2ab4027284aa78210b78937d9728f7fbdaf2"
)

# Exact final bytes and independent prebrowser review returned to this lane.
FINAL_ARTIFACT_FACTS: dict[str, Any] = {
    "html_sha256": "8779848cc1a95ef82f2c5eba1a542e5a4784559f75ef20e9eb06696abde56457",
    "html_git_blob_id": "eba39723cdd892249714dc32d9589b718593b24f",
    "html_bytes": 296602,
    "html_lines": 78,
    "html_binary_diff_sha256": (
        "095f0a65a4487795d31d03d56f8774853e2b91a5463f4e18629caf38c965af53"
    ),
    "html_diff_added": 13,
    "html_diff_deleted": 13,
    "builder_runs_observed": 2,
    "builder_runs_byte_identical": True,
    "independent_source_review_result": "GO",
    "independent_source_review_findings": [],
}

# Exact bounded loopback observations and cleanup returned by the root browser
# lane. They verify only the audit artifact.
BROWSER_FACTS: dict[str, Any] = {
    "facts_supplied": True,
    "browser": "Codex in-app browser",
    "cachebuster": "main-0975bf1c-8779848c",
    "target_url": (
        "http://127.0.0.1:43182/audit-dashboard.html?"
        "v=main-0975bf1c-8779848c#progress"
    ),
    "loopback_port": 43182,
    "loopback_pid": 37680,
    "loopback_bind": "127.0.0.1",
    "server_executable": (
        "C:\\Users\\steph\\.cache\\codex-runtimes\\codex-primary-runtime\\"
        "dependencies\\python\\python.exe"
    ),
    "server_command_line_suffix": (
        "-B -m http.server 43182 --bind 127.0.0.1"
    ),
    "server_stdout_empty": True,
    "server_stderr_get_requests": 2,
    "pre_stop_listener": "127.0.0.1:43182",
    "pre_stop_listener_pid": 37680,
    "only_exact_server_pid_stopped": True,
    "response_status": 200,
    "response_content_type": "text/html",
    "server_get_requests": 2,
    "server_get_response_statuses": [200, 200],
    "response_bytes": 296602,
    "response_sha256": (
        "8779848cc1a95ef82f2c5eba1a542e5a4784559f75ef20e9eb06696abde56457"
    ),
    "document_title": "Oblivion Findings current-source audit",
    "body_font_family": "Inter",
    "computed_body_font_family": (
        'Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif'
    ),
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
        "Progress": 0,
        "RUN-181": 0,
        "Pages": 0,
        "Static census": 0,
        "Runtime gates": 0,
        "Benchmarks": 0,
        "Modules": 0,
        "Finding status": 0,
        "Architecture": 0,
        "Gaps": 0,
    },
    "navigation_target_width_px": {
        "Progress": 1140,
        "RUN-181": 1140,
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
        "RUN-181": 2387,
        "Pages": 21440,
        "Static census": 27625,
        "Runtime gates": 30721,
        "Benchmarks": 32145,
        "Modules": 33579,
        "Finding status": 35077,
        "Architecture": 36900,
        "Gaps": 37691,
    },
    "console_warning_entries": 0,
    "console_error_entries": 0,
    "uncaught_page_error_entries": 0,
    "browser_dev_log_entries": 0,
    "browser_dom_ids": 11,
    "browser_injected_ids": ["codex-browser-sidebar-comments-root"],
    "anchor_elements_rendered_in_browser": 852,
    "unique_local_resources_observed": 455,
    "browser_remaining_tabs_after_close": 0,
    "screenshots_retained": False,
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
        "navigation_rect": {"left": 0, "right": 1425, "width": 1425},
        "table_wrappers": 10,
        "table_wrappers_with_overflow_x_auto": 10,
        "active_table_scrollers": 0,
        "font_loaded": True,
        "main_visible": True,
        "visual_inspection": "GO",
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
        "navigation_rect": {"left": 0, "right": 1265, "width": 1265},
        "table_wrappers": 10,
        "table_wrappers_with_overflow_x_auto": 10,
        "active_table_scrollers": 0,
        "font_loaded": True,
        "main_visible": True,
        "visual_inspection": "GO",
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
        "navigation_rect": {"left": 0, "right": 1009, "width": 1009},
        "table_wrappers": 10,
        "table_wrappers_with_overflow_x_auto": 10,
        "active_table_scrollers": 1,
        "font_loaded": True,
        "main_visible": True,
        "visual_inspection": "GO",
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
        "navigation_rect": {"left": 0, "right": 375, "width": 375},
        "table_wrappers": 10,
        "table_wrappers_with_overflow_x_auto": 10,
        "active_table_scrollers": 10,
        "font_loaded": True,
        "main_visible": True,
        "visual_inspection": "GO",
    },
]
NAVIGATION = [
    ("Progress", "#progress"),
    ("RUN-181", "#checkpoint"),
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
    ["Progress", "RUN-181", "Pages"],
    ["Static census", "Runtime gates", "Benchmarks"],
    ["Modules", "Finding status", "Architecture", "Gaps"],
]


def run(*args: str) -> bytes:
    return subprocess.run(
        args,
        cwd=ROOT,
        check=True,
        capture_output=True,
    ).stdout


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
            allow_nan=False,
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
    value = json.loads(
        strict_text(raw, relative),
        object_pairs_hook=hook,
        parse_constant=lambda token: (_ for _ in ()).throw(
            AssertionError((relative, token))
        ),
    )
    assert isinstance(value, dict)
    return value


def assert_finite(value: Any, label: str = "root") -> None:
    if isinstance(value, float):
        assert math.isfinite(value), label
    elif isinstance(value, dict):
        for key, child in value.items():
            assert_finite(child, f"{label}.{key}")
    elif isinstance(value, list):
        for index, child in enumerate(value):
            assert_finite(child, f"{label}[{index}]")


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
        "git_blob_id": git(
            "rev-parse",
            f"{CHECKPOINT_COMMIT}:{repository_path}",
        ),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


def diff_record(relative: str) -> dict[str, Any]:
    repository_path = f"{PREFIX}/{relative}"
    binary = run("git", "diff", "--binary", "--", repository_path)
    fields = git(
        "diff",
        "--numstat",
        "--",
        repository_path,
    ).split("\t")
    assert len(fields) == 3 and fields[2] == repository_path
    return {
        "path": relative,
        "binary_diff_sha256": sha256(binary),
        "numstat": {
            "added": int(fields[0]),
            "deleted": int(fields[1]),
        },
    }


def verify_self_seal(value: dict[str, Any], expected: str) -> None:
    without_seal = dict(value)
    observed = without_seal.pop("receipt_self_seal_sha256")
    assert observed == expected
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
    expected_final_keys = {
        "html_sha256",
        "html_git_blob_id",
        "html_bytes",
        "html_lines",
        "html_binary_diff_sha256",
        "html_diff_added",
        "html_diff_deleted",
        "builder_runs_observed",
        "builder_runs_byte_identical",
        "independent_source_review_result",
        "independent_source_review_findings",
    }
    assert set(FINAL_ARTIFACT_FACTS) == expected_final_keys
    assert not any(
        isinstance(value, str) and value.startswith("PENDING_RUN182")
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
    assert FINAL_ARTIFACT_FACTS["builder_runs_observed"] == 2
    assert FINAL_ARTIFACT_FACTS["builder_runs_byte_identical"] is True
    assert FINAL_ARTIFACT_FACTS["independent_source_review_result"] == "GO"
    assert FINAL_ARTIFACT_FACTS["independent_source_review_findings"] == []
    assert FINAL_ARTIFACT_FACTS["html_sha256"] != (
        FROZEN_RUN_178_DASHBOARD["sha256"]
    )

    assert BROWSER_FACTS["facts_supplied"] is True
    assert BROWSER_FACTS["browser"] == "Codex in-app browser"
    assert not any(
        isinstance(value, str) and value.startswith("PENDING_RUN182")
        for value in BROWSER_FACTS.values()
    )
    assert BROWSER_FACTS["loopback_port"] > 0
    assert BROWSER_FACTS["loopback_pid"] > 0
    assert BROWSER_FACTS["loopback_bind"] == "127.0.0.1"
    assert BROWSER_FACTS["server_executable"].lower().endswith("\\python.exe")
    assert BROWSER_FACTS["target_url"].startswith(
        f"http://127.0.0.1:{BROWSER_FACTS['loopback_port']}/"
        "audit-dashboard.html?"
    )
    assert BROWSER_FACTS["target_url"].endswith("#progress")
    assert f"v={BROWSER_FACTS['cachebuster']}" in BROWSER_FACTS["target_url"]
    assert BROWSER_FACTS["cachebuster"] == (
        f"main-{CHECKPOINT_COMMIT[:8]}-"
        f"{FINAL_ARTIFACT_FACTS['html_sha256'][:8]}"
    )
    assert BROWSER_FACTS["response_status"] == 200
    assert BROWSER_FACTS["response_content_type"] == "text/html"
    assert BROWSER_FACTS["server_command_line_suffix"] == (
        "-B -m http.server 43182 --bind 127.0.0.1"
    )
    assert BROWSER_FACTS["server_stdout_empty"] is True
    assert BROWSER_FACTS["server_stderr_get_requests"] == 2
    assert BROWSER_FACTS["pre_stop_listener"] == "127.0.0.1:43182"
    assert BROWSER_FACTS["pre_stop_listener_pid"] == BROWSER_FACTS[
        "loopback_pid"
    ]
    assert BROWSER_FACTS["only_exact_server_pid_stopped"] is True
    assert BROWSER_FACTS["server_get_requests"] == 2
    assert BROWSER_FACTS["server_get_response_statuses"] == [200, 200]
    assert BROWSER_FACTS["response_bytes"] == FINAL_ARTIFACT_FACTS["html_bytes"]
    assert BROWSER_FACTS["response_sha256"] == FINAL_ARTIFACT_FACTS["html_sha256"]
    assert BROWSER_FACTS["document_title"] == (
        "Oblivion Findings current-source audit"
    )
    assert BROWSER_FACTS["body_font_family"] == "Inter"
    assert BROWSER_FACTS["computed_body_font_family"] == (
        'Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif'
    )
    assert BROWSER_FACTS["desktop_and_mobile_visual_inspection"] == "GO"
    assert BROWSER_FACTS["reset_browser_viewport"] == "1280x720"
    assert BROWSER_FACTS["reset_document_client"] == "1265x720"
    for key in (
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
        assert BROWSER_FACTS[key] is True
    assert BROWSER_FACTS["navigation_clicks_required"] == 10
    assert BROWSER_FACTS["navigation_clicks_passed"] == 10
    assert BROWSER_FACTS["navigation_credited_batch_sizes"] == [3, 3, 4]
    labels = {label for label, _ in NAVIGATION}
    for key in (
        "navigation_target_top_px",
        "navigation_target_width_px",
        "navigation_scroll_y",
    ):
        assert set(BROWSER_FACTS[key]) == labels
    assert all(
        isinstance(value, (int, float)) and abs(value) <= 1
        for value in BROWSER_FACTS["navigation_target_top_px"].values()
    )
    assert all(
        isinstance(value, int) and value > 0
        for value in BROWSER_FACTS["navigation_target_width_px"].values()
    )
    assert all(
        isinstance(value, int) and value >= 0
        for value in BROWSER_FACTS["navigation_scroll_y"].values()
    )
    for key in (
        "console_warning_entries",
        "console_error_entries",
        "uncaught_page_error_entries",
        "browser_dev_log_entries",
    ):
        assert BROWSER_FACTS[key] == 0
    assert BROWSER_FACTS["browser_dom_ids"] == 11
    assert BROWSER_FACTS["browser_injected_ids"] == [
        "codex-browser-sidebar-comments-root"
    ]
    assert BROWSER_FACTS["anchor_elements_rendered_in_browser"] == 852
    assert BROWSER_FACTS["unique_local_resources_observed"] == 455
    assert BROWSER_FACTS["browser_remaining_tabs_after_close"] == 0
    assert BROWSER_FACTS["screenshots_retained"] is False

    assert [viewport["requested"] for viewport in VIEWPORTS] == [
        "1440x900",
        "1280x800",
        "1024x768",
        "390x844",
    ]
    for viewport in VIEWPORTS:
        assert not any(
            isinstance(value, str) and value.startswith("PENDING_RUN182")
            for value in viewport.values()
        )
        assert viewport["actual_browser_viewport"] == viewport["requested"]
        assert viewport["root_client_width"] > 0
        assert viewport["root_scroll_width"] == viewport["root_client_width"]
        assert viewport["body_scroll_width"] == viewport["root_client_width"]
        assert viewport["page_overflow_px"] == 0
        assert viewport["navigation_client_width"] > 0
        assert viewport["navigation_scroll_width"] >= (
            viewport["navigation_client_width"]
        )
        assert viewport["navigation_overflow_x"] == "auto"
        assert viewport["navigation_rect"] == {
            "left": 0,
            "right": viewport["navigation_client_width"],
            "width": viewport["navigation_client_width"],
        }
        assert viewport["table_wrappers"] == 10
        assert viewport["table_wrappers_with_overflow_x_auto"] == 10
        assert 0 <= viewport["active_table_scrollers"] <= 10
        assert viewport["font_loaded"] is True
        assert viewport["main_visible"] is True
        assert viewport["visual_inspection"] == "GO"


def cleanup_state() -> dict[str, Any]:
    port = BROWSER_FACTS["loopback_port"]
    pid = BROWSER_FACTS["loopback_pid"]
    script = f"""
$run182Listeners = @(Get-NetTCPConnection -State Listen -LocalPort {port} -ErrorAction SilentlyContinue)
$run182PidProcess = Get-Process -Id {pid} -ErrorAction SilentlyContinue
$run182Matching = @(Get-CimInstance Win32_Process | Where-Object {{ $_.ProcessId -eq {pid} -or ($_.Name -like 'python*.exe' -and $_.CommandLine -like '*http.server*{port}*') }})
[pscustomobject]@{{ listener_count = $run182Listeners.Count; exact_pid_present = ($null -ne $run182PidProcess); matching_process_count = $run182Matching.Count }} | ConvertTo-Json -Compress
"""
    value = json.loads(
        run(
            "powershell.exe",
            "-NoProfile",
            "-NonInteractive",
            "-Command",
            script,
        ).decode("utf-8-sig")
    )
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
        f" M {PREFIX}/{BUILDER}",
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
    assert set(git("diff", "--name-only", "HEAD").splitlines()) == {
        f"{PREFIX}/{HTML}",
        f"{PREFIX}/{BUILDER}",
    }
    assert committed_record(BUILDER) == RUN_181_COMMITTED_BUILDER
    assert file_record(BUILDER) == CORRECTED_RUN_182_BUILDER
    assert diff_record(BUILDER) == CORRECTED_RUN_182_BUILDER_DIFF
    assert committed_record(FINDINGS) == COMMITTED_FINDINGS
    assert file_record(FINDINGS) == COMMITTED_FINDINGS
    assert committed_record(HTML) == FROZEN_RUN_178_DASHBOARD
    transient = []
    for pattern in ("__pycache__", ".pytest_cache", ".mypy_cache", ".ruff_cache"):
        transient.extend(AUDIT.rglob(pattern))
    transient.extend(AUDIT.rglob("*.tmp"))
    assert not transient, [path.relative_to(AUDIT).as_posix() for path in transient]
    return observed


def validate_run_181_lineage() -> dict[str, Any]:
    manifest = strict_json(AUDIT_RUN_MANIFEST)
    assert manifest["governing_prompt"]["sha256"] == GOVERNING_PROMPT_SHA256
    assert GOVERNING_PROMPT_SHA256 != CONTINUATION_REQUEST_SHA256
    assert file_record(RUN_181_MATERIALIZER) == RUN_181_MATERIALIZER_RECORD
    assert file_record(RUN_181_RECEIPT) == RUN_181_RECEIPT_RECORD
    assert file_record(BUILDER) == CORRECTED_RUN_182_BUILDER
    assert file_record(FINDINGS) == COMMITTED_FINDINGS
    assert file_record(HTML) == {
        "path": HTML,
        "sha256": FINAL_ARTIFACT_FACTS["html_sha256"],
        "git_blob_id": FINAL_ARTIFACT_FACTS["html_git_blob_id"],
        "bytes": FINAL_ARTIFACT_FACTS["html_bytes"],
        "lines": FINAL_ARTIFACT_FACTS["html_lines"],
    }
    assert file_record(RUN_178_MATERIALIZER)["sha256"] == (
        RUN_178_MATERIALIZER_SHA256
    )
    assert file_record(RUN_178_RECEIPT)["sha256"] == RUN_178_RECEIPT_SHA256

    receipt = strict_json(RUN_181_RECEIPT)
    verify_self_seal(receipt, RUN_181_RECEIPT_SELF_SEAL)
    assert receipt["schema_version"] == (
        "run-181-reviewed-fleet-trip-index-route-action-reporting-wave-34-v1"
    )
    assert receipt["run_id"] == (
        "RUN-181-REVIEWED-FLEET-TRIP-INDEX-ROUTE-ACTION-REPORTING-WAVE-34"
    )
    pins = receipt["pins"]
    assert pins["reporting_input_commit"] == CHECKPOINT_PARENT
    assert pins["reporting_input_tree"] == (
        "54e63a9339746e399b4ab57958c3650b08cb66e3"
    )
    assert pins["reporting_input_parent"] == (
        "e6dd903e2374ebccbd34adf1c2c483905643ae36"
    )
    assert pins["governing_prompt_sha256"] == GOVERNING_PROMPT_SHA256
    assert pins["continuation_request_sha256"] == CONTINUATION_REQUEST_SHA256
    assert pins["continuation_request_is_not_governing_prompt"] is True
    assert pins["reporting_materializer"] == RUN_181_MATERIALIZER_RECORD
    assert pins["current_findings"] == COMMITTED_FINDINGS
    assert pins["dashboard_generator"] == RUN_181_COMMITTED_BUILDER
    assert pins["unchanged_run_178_dashboard"] == FROZEN_RUN_178_DASHBOARD

    snapshot = receipt["reporting_snapshot"]
    assert snapshot["combined_counts"] == {
        "source_owner_records": 666,
        "route_owner_records": 309,
        "page_owner_records": 357,
        "distinct_feature_ids": 256,
        "distinct_H_feature_ids": 234,
        "distinct_D_feature_ids": 22,
        "route_distinct_feature_ids": 64,
        "page_distinct_feature_ids": 242,
        "route_page_feature_overlap": 50,
        "static_controller_action_bridges": 97,
        "bounded_static_source_denominator": 3929,
        "bounded_static_source_ownership_percent": "16.950878",
        "bounded_static_source_residual_records": 3263,
        "residual_explicit_unmapped_routes": 2892,
    }
    assert snapshot["queue_accounting"] == {
        "direct_exact_queue_records": 507,
        "reviewed_queue_surface_rows": 120,
        "owner_queue_surface_rows": 98,
        "shared_queue_surface_rows": 10,
        "alias_queue_surface_rows": 5,
        "dead_queue_surface_rows": 0,
        "evidence_gap_queue_surface_rows": 7,
        "pending_unreviewed_queue_surface_rows": 387,
        "queue_surfaces_without_ownership": 409,
    }
    assert receipt["queue_boundary"] == {
        "preceding_index_83_not_recredited": True,
        "selected_index_84_integrated": True,
        "next_unresolved_index": 85,
        "next_unresolved_queue_id": "RUN090-ROUTE-0086",
        "next_unresolved_route_record_id": "RUN077-ROUTE-0694",
        "next_unresolved_route_name": "fleet-assets.trips.playback",
        "next_unresolved_action_expression": "[FleetTripController::class, 'show']",
        "next_unresolved_queue_record_sha256": (
            "f9df043e4557240020de213961c847fb56b8cd0e2d9b9144ec0b7a877ff84943"
        ),
        "reviewed_key_count": 120,
        "reviewed_key_list_sha256": (
            "5dbcecd3986300fe255fdb75efe6013c07f3adc4071745ebebf0c4a525ee99c9"
        ),
        "reviewed_key_list_canonical_json_sha256": (
            "738c7836dd770e12d67de62d4f28441825814d619bb641e070e25468786fb75e"
        ),
    }
    assert receipt["findings_boundary"] == {
        "retained_claim_records": 13,
        "current_provisional": 8,
        "historical_already_fixed": 2,
        "historical_remediated": 3,
        "final_P0": 0,
        "final_P1": 0,
    }
    assert receipt["bounded_execution_boundary"] == {
        "unique_tests": 88,
        "unique_assertions": 1764,
        "changed_by_run_181": False,
    }
    assert receipt["benchmark_boundary"] == {
        "mapped": 2,
        "final_no_match_or_NCM": 0,
        "unresolved": 338,
    }
    assert receipt["dashboard_forward_gate"] == {
        "required_run": "RUN-182",
        "dashboard_html_changed_by_run_181": False,
        "unchanged_dashboard_sha256": FROZEN_RUN_178_DASHBOARD["sha256"],
        "fresh_four_viewport_verification_required": True,
        "required_viewports": [
            "1440x900",
            "1280x800",
            "1024x768",
            "390x844",
        ],
        "future_receipt_link_is_unhashed_to_avoid_cycle": True,
    }
    assert {
        key for key, value in receipt["credit_boundary"].items() if value
    } == {"live_static_ownership_and_queue_reporting"}
    assert all(value is False for value in receipt["completion_boundary"].values())
    assert receipt["artifact_completion_test_met"] is True
    assert receipt["audit_completion_test_met"] is False

    commit_paths = set(
        git(
            "diff-tree",
            "--no-commit-id",
            "--name-only",
            "-r",
            CHECKPOINT_COMMIT,
        ).splitlines()
    )
    expected_commit_paths = {
        f"{PREFIX}/00-executive-summary.md",
        f"{PREFIX}/01-repository-module-map.md",
        f"{PREFIX}/13-unresolved-questions-and-evidence-gaps.md",
        f"{PREFIX}/{FINDINGS}",
        f"{PREFIX}/{BUILDER}",
        f"{PREFIX}/{RUN_181_MATERIALIZER}",
        f"{PREFIX}/{RUN_181_RECEIPT}",
    }
    assert commit_paths == expected_commit_paths
    assert f"{PREFIX}/{HTML}" not in commit_paths
    return receipt


def validate_static_dashboard(
    raw: bytes,
) -> dict[str, Any]:
    text = strict_text(raw, HTML)
    parser = Parser()
    parser.feed(text)
    assert parser.headings == 26
    assert parser.tables == 10
    assert parser.table_wraps == 10
    assert len(parser.ids) == 10
    id_counts = Counter(parser.ids)
    duplicates = sorted(key for key, count in id_counts.items() if count > 1)
    assert not duplicates

    hash_hrefs = [href for href in parser.hrefs if href.startswith("#")]
    assert [href for _, href in NAVIGATION] == hash_hrefs
    assert len(hash_hrefs) == len(set(hash_hrefs)) == 10
    missing_hash_targets = sorted(
        href for href in set(hash_hrefs) if href[1:] not in id_counts
    )
    assert not missing_hash_targets

    local_hrefs = [href for href in parser.hrefs if is_local(href)]
    unique_local = sorted(set(local_hrefs))
    external_or_empty = [
        href
        for href in parser.hrefs
        if not href.startswith("#") and not is_local(href)
    ]
    assert not external_or_empty
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

    non_forward = [href for href in unique_local if href != OUTPUT]
    prewrite_failures = [
        href for href in non_forward if not local_path(href).exists()
    ]
    assert not prewrite_failures

    hash_pairs = re.findall(
        r'<a href="([^"#]+)">[^<]*</a>\s*<code>([0-9a-f]{64})</code>',
        text,
    )
    hash_failures: list[dict[str, str]] = []
    missing_hash_paths: list[str] = []
    file_occurrences = 0
    file_paths: set[str] = set()
    directory_occurrences = 0
    directory_paths: set[str] = set()
    for href, expected in hash_pairs:
        target = local_path(href)
        if target.is_file():
            file_occurrences += 1
            file_paths.add(href)
            actual = sha256(target.read_bytes())
            if actual != expected:
                hash_failures.append(
                    {"href": href, "expected": expected, "actual": actual}
                )
        elif target.is_dir():
            directory_occurrences += 1
            directory_paths.add(href)
        else:
            missing_hash_paths.append(href)
    assert not hash_failures
    assert not missing_hash_paths
    assert directory_paths == {"task-scripts/"}
    assert sum(1 for href, _ in hash_pairs if href == MATERIALIZER) == 0
    assert sum(1 for href, _ in hash_pairs if href == OUTPUT) == 0

    builder_text = strict_text((AUDIT / BUILDER).read_bytes(), BUILDER)
    for required in (
        "current_visible_boundaries = [",
        "RUN-178–181 Fleet trip-index route/action ownership checkpoint",
        "Fresh RUN-182 audit-dashboard verification required",
        MATERIALIZER,
        OUTPUT,
        "run_178_dashboard_payload = git_file_at_commit(",
        CHECKPOINT_COMMIT,
        ".tmp-run182-dashboard",
        "existing_output_bytes in (run_178_dashboard_payload, output_bytes)",
    ):
        assert required in builder_text
    assert (
        'run_178_dashboard_payload = (AUDIT_DIR / "audit-dashboard.html").read_bytes()'
        not in builder_text
    )
    assert f'read_json_strict("{OUTPUT}")' not in builder_text
    required_visible = literal_list_assignment(
        builder_text,
        "current_visible_boundaries",
    )
    assert len(required_visible) == len(set(required_visible))
    visible_static = {value: value in text for value in required_visible}
    assert all(visible_static.values()), [
        value for value, present in visible_static.items() if not present
    ]
    assert "tenant" not in text.lower()

    required_live = (
        '<a href="#checkpoint">RUN-181</a>',
        "666 owners · 309 routes + 357 pages · 97 bridges",
        "120 reviewed / 387 pending",
        "98 owned / 409 without ownership",
        "16.950878%",
        "3,263 records remain",
        "index 84 fleet-assets.trips.index is integrated",
        "next index 85 RUN090-ROUTE-0086 / RUN077-ROUTE-0694",
        "fleet-assets.trips.playback",
        "13 retained claim identities split into 8 current provisional P1, 2 historical already-fixed, and 3 historical remediated",
        "88 / 1,764",
        "2/340 mappings",
        "0/340 final no-match/NCM",
        "338 unresolved targets",
        "one operating organisation across multiple Sites",
        "Gate 4 and audit completion false",
        "Fresh RUN-182 audit-dashboard verification required",
    )
    required_live_checks = {value: value in text for value in required_live}
    assert all(required_live_checks.values()), [
        value for value, present in required_live_checks.items() if not present
    ]
    prohibited = {
        "stale_current_navigation": '<a href="#checkpoint">RUN-177</a>',
        "stale_fresh_run_178": (
            "Fresh RUN-178 audit-dashboard verification required"
        ),
        "incorrect_gate_4": "Gate 4 and audit completion true",
        "incorrect_current_owner_count": (
            "665 owners · 308 routes + 357 pages · 96 bridges"
        ),
        "incorrect_queue_accounting": "119 reviewed / 388 pending",
        "incorrect_playback_owner": "index 85 fleet-assets.trips.playback is integrated",
        "incorrect_findings_split": (
            "13 retained claim identities split into 9 current provisional P1"
        ),
        "incorrect_publication": "RUN-181 published to origin/main",
    }
    prohibited_hits = {
        key: value for key, value in prohibited.items() if value in text
    }
    assert not prohibited_hits

    return {
        "parser": parser,
        "hash_hrefs": hash_hrefs,
        "local_hrefs": local_hrefs,
        "unique_local": unique_local,
        "hash_pairs": hash_pairs,
        "file_occurrences": file_occurrences,
        "file_paths": sorted(file_paths),
        "directory_occurrences": directory_occurrences,
        "directory_paths": sorted(directory_paths),
        "visible_static": visible_static,
        "required_live_checks": required_live_checks,
        "prohibited_hits": prohibited_hits,
    }


def build_receipt(
    run_181: dict[str, Any],
    static: dict[str, Any],
    cleanup: dict[str, Any],
    dashboard_diff: dict[str, Any],
) -> dict[str, Any]:
    parser: Parser = static["parser"]
    hash_hrefs: list[str] = static["hash_hrefs"]
    local_hrefs: list[str] = static["local_hrefs"]
    unique_local: list[str] = static["unique_local"]
    hash_pairs: list[tuple[str, str]] = static["hash_pairs"]
    visible_static: dict[str, bool] = static["visible_static"]
    batch_by_label = {
        label: batch
        for batch, labels in enumerate(CREDITED_NAVIGATION_BATCHES, 1)
        for label in labels
    }
    navigation_results = [
        {
            "label": label,
            "href": href,
            "credited_batch": batch_by_label[label],
            "browser_click_performed": True,
            "resulting_hash": href,
            "target_exists": True,
            "target_top_px": BROWSER_FACTS["navigation_target_top_px"][label],
            "target_width_px": BROWSER_FACTS["navigation_target_width_px"][label],
            "scroll_y": BROWSER_FACTS["navigation_scroll_y"][label],
            "pass": True,
        }
        for label, href in NAVIGATION
    ]
    credit = {
        "exact_audit_dashboard_artifact": True,
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
        "ease": False,
        "final_finding": False,
        "release": False,
        "publication": False,
        "feature_or_module_completion": False,
        "gate_4": False,
        "audit_complete": False,
    }
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
    final_dashboard = file_record(HTML)
    final_builder = file_record(BUILDER)
    receipt: dict[str, Any] = {
        "schema_version": "run-182-audit-dashboard-verification-wave-34-v1",
        "run_id": "RUN-182-AUDIT-DASHBOARD-VERIFICATION-WAVE-34",
        "generated_on": "2026-08-30",
        "status": (
            "AUDIT_DASHBOARD_RUN181_EXACT_ARTIFACT_RESPONSIVE_LINK_CONSOLE_"
            "VERIFICATION_GO_LOCAL_ONLY_ZERO_APPLICATION_PUBLICATION_FINAL_"
            "FINDING_GATE4_OR_AUDIT_COMPLETION_CREDIT"
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
            "Exact RUN-181 reporting dashboard and bounded audit-artifact "
            "verification only"
        ),
        "pins": {
            "governing_prompt_sha256": GOVERNING_PROMPT_SHA256,
            "continuation_request_sha256": CONTINUATION_REQUEST_SHA256,
            "continuation_request_is_not_governing_prompt": True,
            "audit_run_manifest": file_record(AUDIT_RUN_MANIFEST),
            "run_181_checkpoint_commit": CHECKPOINT_COMMIT,
            "run_181_checkpoint_tree": CHECKPOINT_TREE,
            "run_181_checkpoint_parent": CHECKPOINT_PARENT,
            "origin_main_before_run_182_commit": ORIGIN_MAIN,
            "local_main_ahead": LOCAL_MAIN_AHEAD,
            "local_main_behind": LOCAL_MAIN_BEHIND,
            "run_181_materializer": RUN_181_MATERIALIZER_RECORD,
            "run_181_receipt": {
                **RUN_181_RECEIPT_RECORD,
                "receipt_self_seal_sha256": RUN_181_RECEIPT_SELF_SEAL,
            },
            "run_181_findings": COMMITTED_FINDINGS,
            "run_181_committed_builder": RUN_181_COMMITTED_BUILDER,
            "run_182_corrected_builder": CORRECTED_RUN_182_BUILDER,
            "run_182_builder_diff": CORRECTED_RUN_182_BUILDER_DIFF,
            "run_178_frozen_dashboard_at_run_181_checkpoint": (
                FROZEN_RUN_178_DASHBOARD
            ),
            "run_182_builder": final_builder,
            "run_182_dashboard": final_dashboard,
            "run_182_receipt_materializer": file_record(MATERIALIZER),
        },
        "lineage": {
            "run_178": (
                "verifies only the now-superseded RUN-177 dashboard"
            ),
            "run_179_and_run_179r": (
                "preserve the strict-current split and two fresh OWNER "
                "tiebreaks while excluding older-bundle identity or credit"
            ),
            "run_180_and_run_180r": (
                "integrate one route owner and one action bridge and authorize "
                "reporting only after three post-commit GO lanes"
            ),
            "run_181": (
                "reports 666 owners, 97 bridges, and 120 reviewed rows while "
                "freezing the RUN-178 HTML"
            ),
            "run_182": (
                "generates from exact committed RUN-181 sources and verifies "
                "only the resulting audit artifact"
            ),
            "older_bundle_identity_or_credit_imported": False,
            "index_83_recredited": False,
            "index_85_playback_inherited": False,
        },
        "dashboard_generation": {
            "run_181_committed_builder": RUN_181_COMMITTED_BUILDER,
            "final_builder": final_builder,
            "builder_changed_by_run_182": True,
            "builder_change_classification": (
                "two verification-guard corrections discovered before and "
                "during deterministic HTML replay"
            ),
            "builder_change": CORRECTED_RUN_182_BUILDER_DIFF,
            "builder_guard_corrections": [
                {
                    "name": "current-visible-boundary-lineage",
                    "before": (
                        "RUN-175 verifies only the superseded RUN-174 HTML"
                    ),
                    "after": (
                        "RUN-178 verifies only the superseded RUN-177 HTML"
                    ),
                    "effect": "align required literal with existing RUN181 rewrite",
                },
                {
                    "name": "frozen-dashboard-input-read",
                    "before": (
                        "read audit-dashboard.html from the mutable working tree"
                    ),
                    "after": (
                        "read audit-dashboard.html at exact committed RUN181 "
                        "checkpoint via git_file_at_commit"
                    ),
                    "effect": (
                        "preserve the frozen hash assertion and permit exact "
                        "same-checkout deterministic replay"
                    ),
                },
            ],
            "builder_rewrite_or_output_content_changed": False,
            "committed_dashboard": FROZEN_RUN_178_DASHBOARD,
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
                "builder_sha256": CORRECTED_RUN_182_BUILDER["sha256"],
                "visible_static_checks": (
                    f"{len(visible_static)}/{len(visible_static)}"
                ),
                "browser_credit": False,
                "reviewer_file_changes": [],
            },
            "forward_generator_is_intentionally_unhashed": True,
            "forward_receipt_is_intentionally_unhashed": True,
            "hash_cycle_present": False,
            "application_source_or_product_test_change": False,
            "credit_effect": "exact audit-dashboard artifact verification only",
        },
        "verification_method": {
            "browser": BROWSER_FACTS["browser"],
            "served_from": (
                "temporary loopback-only HTTP server on "
                f"127.0.0.1:{BROWSER_FACTS['loopback_port']}"
            ),
            "server_executable": BROWSER_FACTS["server_executable"],
            "server_command_line_suffix": BROWSER_FACTS[
                "server_command_line_suffix"
            ],
            "server_stdout_empty": BROWSER_FACTS["server_stdout_empty"],
            "server_stderr_get_requests": BROWSER_FACTS[
                "server_stderr_get_requests"
            ],
            "target_url": BROWSER_FACTS["target_url"],
            "cachebuster": BROWSER_FACTS["cachebuster"],
            "response_status": BROWSER_FACTS["response_status"],
            "response_content_type": BROWSER_FACTS["response_content_type"],
            "server_get_requests": BROWSER_FACTS["server_get_requests"],
            "server_get_response_statuses": BROWSER_FACTS[
                "server_get_response_statuses"
            ],
            "response_bytes": FINAL_ARTIFACT_FACTS["html_bytes"],
            "response_sha256": FINAL_ARTIFACT_FACTS["html_sha256"],
            "computed_body_font_family": BROWSER_FACTS[
                "computed_body_font_family"
            ],
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
            "reset_document_client": BROWSER_FACTS["reset_document_client"],
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
            "navigation_clicks_required": 10,
            "navigation_clicks_passed": 10,
            "navigation_credited_batch_sizes": [3, 3, 4],
            "navigation_results": navigation_results,
            "console_warning_entries": BROWSER_FACTS[
                "console_warning_entries"
            ],
            "console_error_entries": BROWSER_FACTS["console_error_entries"],
            "uncaught_page_error_entries": BROWSER_FACTS[
                "uncaught_page_error_entries"
            ],
            "browser_dev_log_entries": BROWSER_FACTS[
                "browser_dev_log_entries"
            ],
            "authored_ids": len(parser.ids),
            "browser_dom_ids": BROWSER_FACTS["browser_dom_ids"],
            "browser_injected_ids": BROWSER_FACTS["browser_injected_ids"],
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
            "run_182_forward_receipt_is_intentional_unhashed_self_link": True,
            "post_materialization_local_resources": (
                f"{len(unique_local)}/{len(unique_local)}"
            ),
            "post_materialization_local_resource_failures": [],
            "adjacent_hash_pair_occurrences": len(hash_pairs),
            "unique_adjacent_hash_path_hash_pairs": len(set(hash_pairs)),
            "unique_adjacent_hash_paths": len({path for path, _ in hash_pairs}),
            "hash_bearing_file_occurrences_verified": static[
                "file_occurrences"
            ],
            "unique_hash_bearing_file_paths_verified": len(
                static["file_paths"]
            ),
            "historical_directory_bundle_digest_occurrences": static[
                "directory_occurrences"
            ],
            "historical_directory_bundle_digest_paths": static[
                "directory_paths"
            ],
            "hash_bearing_link_failures": [],
            "run_182_generator_link_occurrences": (
                (AUDIT / HTML).read_text(encoding="utf-8").count(
                    f'href="{MATERIALIZER}"'
                )
            ),
            "run_182_generator_link_adjacent_hash_occurrences": sum(
                1 for href, _ in hash_pairs if href == MATERIALIZER
            ),
            "run_182_forward_receipt_link_occurrences": (
                (AUDIT / HTML).read_text(encoding="utf-8").count(
                    f'href="{OUTPUT}"'
                )
            ),
            "run_182_forward_receipt_link_adjacent_hash_occurrences": sum(
                1 for href, _ in hash_pairs if href == OUTPUT
            ),
            "visible_static_checks_required": len(visible_static),
            "visible_static_checks_passed": sum(visible_static.values()),
            "visible_static_checks": visible_static,
            "required_live_checks": static["required_live_checks"],
            "prohibited_visible_phrase_hits": static["prohibited_hits"],
            "single_organisation_multi_site_wording_present": True,
            "tenant_word_present": False,
        },
        "reported_finding_boundary": {
            "retained_claim_records": 13,
            "current_provisional_source_claims": 8,
            "historical_already_fixed_records": 2,
            "historical_remediated_records": 3,
            "final_P0": 0,
            "final_P1": 0,
            "changed_by_run_182": False,
        },
        "bounded_execution_accounting": {
            "unique_tests": 88,
            "unique_assertions": 1764,
            "changed_by_run_182": False,
            "executed_by_run_182": False,
            "red_replay_or_supporting_recredit": False,
        },
        "static_ownership_boundary": {
            "owner_records": 666,
            "route_owners": 309,
            "page_owners": 357,
            "action_bridges": 97,
            "source_denominator": 3929,
            "source_ownership_percent": "16.950878",
            "source_residual_records": 3263,
            "residual_explicit_unmapped_routes": 2892,
            "queue_total": 507,
            "queue_reviewed": 120,
            "queue_pending": 387,
            "queue_owned": 98,
            "queue_without_ownership": 409,
            "next_zero_based_index": 85,
            "next_queue_id": "RUN090-ROUTE-0086",
            "next_route_record_id": "RUN077-ROUTE-0694",
            "next_route": "fleet-assets.trips.playback",
            "next_action": "FleetTripController::show",
            "next_ownership_status": "PENDING_FRESH_SEMANTIC_REVIEW",
            "changed_by_run_182": False,
            "correctness_credit": False,
        },
        "benchmark_boundary": {
            "mapped": 2,
            "total": 340,
            "final_no_match_or_NCM": 0,
            "unresolved": 338,
            "changed_by_run_182": False,
        },
        "noninheritance_boundary": {
            "run_180_static_route_feature_ownership_recredited": False,
            "run_180_static_controller_action_bridge_recredited": False,
            "application_source_or_product_test": False,
            "application_runtime_reexecution": False,
            "application_browser": False,
            "executed_product_tests": False,
            "queue_advance": False,
            "playback_or_adjacent_route_outcome": False,
            "broader_fleet_correctness": False,
            "benchmark_mapping_or_final_no_match_NCM": False,
            "ease": False,
            "final_finding": False,
            "feature_module_or_pass_completion": False,
            "release_publication_gate4_or_audit_completion": False,
        },
        "root_browser_resource_cleanup": {
            "temporary_loopback_port": BROWSER_FACTS["loopback_port"],
            "temporary_server_pid": BROWSER_FACTS["loopback_pid"],
            "temporary_server_executable": BROWSER_FACTS[
                "server_executable"
            ],
            "pre_stop_listener": BROWSER_FACTS["pre_stop_listener"],
            "pre_stop_listener_pid": BROWSER_FACTS[
                "pre_stop_listener_pid"
            ],
            "only_exact_server_pid_stopped": BROWSER_FACTS[
                "only_exact_server_pid_stopped"
            ],
            "server_get_requests": BROWSER_FACTS["server_get_requests"],
            "listeners_after_cleanup": cleanup["listener_count"],
            "exact_pid_present_after_cleanup": cleanup["exact_pid_present"],
            "matching_loopback_server_processes_after_cleanup": cleanup[
                "matching_process_count"
            ],
            "browser_viewport_override_reset": BROWSER_FACTS[
                "viewport_override_reset_after_test"
            ],
            "browser_tab_closed": BROWSER_FACTS["browser_tab_closed_after_test"],
            "browser_remaining_tabs_after_close": BROWSER_FACTS[
                "browser_remaining_tabs_after_close"
            ],
        },
        "worktree_boundary": {
            "expected_final_status_count": 4,
            "expected_final_porcelain_statuses": sorted(
                {
                    f" M {PREFIX}/{HTML}",
                    f" M {PREFIX}/{BUILDER}",
                    f"?? {PREFIX}/{MATERIALIZER}",
                    f"?? {PREFIX}/{OUTPUT}",
                }
            ),
            "no_staged_paths": True,
            "git_diff_check_clean": True,
            "exact_match_required": True,
        },
        "mutation_attestation": {
            "sequence_paths": [BUILDER, HTML, MATERIALIZER, OUTPUT],
            "receipt_materializer_persistent_write_scope": [OUTPUT],
            "builder_changed_by_run_182": True,
            "builder_change_is_two_verification_guards_only": True,
            "application_paths_changed": [],
            "product_test_paths_changed": [],
            "findings_register_changed_by_run_182": False,
            "run_181_reporting_surfaces_changed_by_run_182": False,
            "audit_dashboard_html_changed_by_run_182": True,
            "forms_submitted": False,
            "records_opened": False,
            "screenshots_retained": BROWSER_FACTS["screenshots_retained"],
            "database_changed": False,
            "application_tests_or_build_run": False,
        },
        "remote_state_boundary": {
            "origin_main_before_run_182_commit": ORIGIN_MAIN,
            "local_main_ahead_before_run_182_commit": LOCAL_MAIN_AHEAD,
            "local_main_behind_before_run_182_commit": LOCAL_MAIN_BEHIND,
            "application_merges_remain_local_only": True,
            "push_or_publication_performed_by_materializer": False,
            "publication_claim": False,
        },
        "credit_boundary": credit,
        "completion_boundary": completion,
        "artifact_completion_scope": [BUILDER, HTML, MATERIALIZER, OUTPUT],
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "run_182_sequence_written_paths": [
            f"{PREFIX}/{BUILDER}",
            f"{PREFIX}/{HTML}",
            f"{PREFIX}/{MATERIALIZER}",
            f"{PREFIX}/{OUTPUT}",
        ],
    }
    assert {
        key for key, value in credit.items() if value
    } == {"exact_audit_dashboard_artifact"}
    assert all(value is False for value in completion.values())
    assert run_181["audit_completion_test_met"] is False
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    assert_finite(receipt)
    return receipt


def write_receipt(receipt: dict[str, Any]) -> bytes:
    output_bytes = (
        json.dumps(
            receipt,
            ensure_ascii=False,
            indent=2,
            allow_nan=False,
        )
        + "\n"
    ).encode("utf-8")
    output_path = AUDIT / OUTPUT
    temporary_path = output_path.with_name(f".{output_path.name}.tmp-run182")
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
    observed_seal = without_seal.pop("receipt_self_seal_sha256")
    assert canonical_sha256(without_seal) == observed_seal
    assert written == receipt
    return output_bytes


def main() -> None:
    validate_supplied_facts()
    validate_repository_state()
    run_181 = validate_run_181_lineage()
    expected_dashboard = {
        "path": HTML,
        "sha256": FINAL_ARTIFACT_FACTS["html_sha256"],
        "git_blob_id": FINAL_ARTIFACT_FACTS["html_git_blob_id"],
        "bytes": FINAL_ARTIFACT_FACTS["html_bytes"],
        "lines": FINAL_ARTIFACT_FACTS["html_lines"],
    }
    assert file_record(HTML) == expected_dashboard
    assert expected_dashboard != FROZEN_RUN_178_DASHBOARD
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
    static = validate_static_dashboard((AUDIT / HTML).read_bytes())
    cleanup = cleanup_state()
    receipt = build_receipt(run_181, static, cleanup, dashboard_diff)
    output_bytes = write_receipt(receipt)

    assert file_record(BUILDER) == CORRECTED_RUN_182_BUILDER
    assert file_record(FINDINGS) == COMMITTED_FINDINGS
    assert file_record(HTML) == expected_dashboard
    assert all(local_path(href).exists() for href in static["unique_local"])
    validate_repository_state()
    print(
        json.dumps(
            {
                "run_id": receipt["run_id"],
                "status": receipt["status"],
                "dashboard_sha256": FINAL_ARTIFACT_FACTS["html_sha256"],
                "builder_sha256": CORRECTED_RUN_182_BUILDER["sha256"],
                "materializer_sha256": file_record(MATERIALIZER)["sha256"],
                "receipt_sha256": sha256(output_bytes),
                "receipt_self_seal_sha256": receipt[
                    "receipt_self_seal_sha256"
                ],
                "visible_checks": len(static["visible_static"]),
                "navigation": "10/10",
                "viewports": "4/4",
                "unique_local_resources": len(static["unique_local"]),
                "published": False,
                "audit_complete": False,
            },
            ensure_ascii=False,
            indent=2,
            allow_nan=False,
        )
    )


if __name__ == "__main__":
    main()
