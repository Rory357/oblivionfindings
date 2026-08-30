#!/usr/bin/env python3
"""Seal bounded RUN175 facts for the exact corrected RUN174 audit dashboard.

Static facts are reconstructed from pinned bytes. Browser facts are limited to
the completed loopback-only final-SHA observations recorded below; the failed
builder self-check and timed-out navigation attempt grant no credit.
"""
from __future__ import annotations

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
MATERIALIZER = "generators/materialize-run-175-audit-dashboard-verification-wave-32.py"
OUTPUT = "evidence/browser/current-audit-dashboard-verification-run-175-wave-32.json"
HTML = "audit-dashboard.html"
BUILDER = "generators/build-current-audit-dashboard.py"
RUN_174_MATERIALIZER = (
    "generators/materialize-run-174-safe-alert-dedup-identity-remediation-reporting-wave-32.py"
)
RUN_174_RECEIPT = (
    "evidence/source/current-run-174-safe-alert-dedup-identity-remediation-reporting-wave-32.json"
)

CHECKPOINT_COMMIT = "3cc6852cc03e20f4dc390b506e3899f55c31dbdc"
CHECKPOINT_TREE = "8639a7ddd7514e842bb93f450ea6d46622672d0c"
CHECKPOINT_PARENT = "b8bb062320733a1dd6721a54f20d7eef4d914cae"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"
LOCAL_MAIN_AHEAD = 4
LOCAL_MAIN_BEHIND = 0
APPLICATION_MERGE_COMMIT = "705db2dc3ba05a8fdf647cd28bdc9c226a694068"
APPLICATION_MERGE_TREE = "59b4fc58567f64bc80ff3d2e47b52860ce44cb02"
SAFE_BASE_COMMIT = "e488bd3edcda0f154f87e8bbed972f14db409b82"
SAFE_BASE_TREE = "9e93b8aea4f4b907cde3dc59dd0520fba5bd7080"
SAFE_FIX_COMMIT = "dc04067e304adebb47335d4f65e8c61061ec6e29"
SAFE_FIX_TREE = "15a2e4b47788e9f2779030ec6d4d9ca7c1022727"
GOVERNING_PROMPT_SHA256 = "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d"

RUN_174_MATERIALIZER_SHA256 = "b0d6894979126709c4db18226a046940029d05a16d297257b9f1e58ee7db61c1"
RUN_174_RECEIPT_SHA256 = "5d5e2ea892a1d89f7175a1842bef325637bd3c5f1da43563c31aa5fe5a840d18"
RUN_174_RECEIPT_SELF_SEAL = "f50f447a81080dd39a644f93ff7d9546645e3cad4a2238c3cf2723f873f7af9c"

COMMITTED_BUILDER_SHA256 = "8a944241b432d42814abecbffa68a23ecf9369903d63c112d6460fedf27c1f79"
COMMITTED_BUILDER_BLOB = "13beddd417bfc3265f8ba8d58284d4d7a0211d6a"
COMMITTED_BUILDER_BYTES = 573865
COMMITTED_BUILDER_LINES = 4937
COMMITTED_HTML_SHA256 = "79bb5c671606ca6f596bba6d9a0649ceed9acc549ec57174c6a1102ea22d3f47"
COMMITTED_HTML_BLOB = "a4daa6ea9d1ba91caf77aa875e4c7bc752dabbac"
COMMITTED_HTML_BYTES = 270828
COMMITTED_HTML_LINES = 78

BUILDER_SHA256 = "c6406e0b5e63facca149c8ab506a382b663ee9b76bcb5c2010353f2884a2c1e7"
BUILDER_BLOB = "2ce4790af6645457fd6c76e57b2f5aed654e1e26"
BUILDER_BYTES = 574175
BUILDER_LINES = 4937
BUILDER_DIFF_SHA256 = "ae2e373100a3693573ef1d24c773c49e4c60ce5e9a2239df8be6b7513378f421"
HTML_SHA256 = "8586a2cb3cc6c248788ea71ecc20c2e0c4785fd5a7a5a00fa11d2ee48f48490c"
HTML_BLOB = "1c1c521b674bcc12b5227aff1418a49ba0ace06a"
HTML_BYTES = 280930
HTML_LINES = 78
HTML_DIFF_SHA256 = "fc3cf0a89b67dee56928a2475ffe0991cf5e7e8e8b797179db80bce21395aff7"

BROWSER_CACHEBUSTER = "main-3cc6852c-8586a2cb"
BROWSER_TARGET_URL = (
    "http://127.0.0.1:43175/audit-dashboard.html?"
    "v=main-3cc6852c-8586a2cb#progress"
)
LOOPBACK_PORT = 43175
LOOPBACK_PID = 6288

VIEWPORTS = [
    {"requested": "1440x900", "actual_browser_viewport": "1440x900", "root_client_width": 1425, "root_scroll_width": 1425, "body_scroll_width": 1425, "page_overflow_px": 0, "navigation_client_width": 1425, "navigation_scroll_width": 1425, "navigation_overflow_x": "auto", "active_table_scrollers": 0, "table_wrappers": 10, "table_wrappers_with_overflow_x_auto": 10},
    {"requested": "1280x800", "actual_browser_viewport": "1280x800", "root_client_width": 1265, "root_scroll_width": 1265, "body_scroll_width": 1265, "page_overflow_px": 0, "navigation_client_width": 1265, "navigation_scroll_width": 1265, "navigation_overflow_x": "auto", "active_table_scrollers": 0, "table_wrappers": 10, "table_wrappers_with_overflow_x_auto": 10},
    {"requested": "1024x768", "actual_browser_viewport": "1024x768", "root_client_width": 1009, "root_scroll_width": 1009, "body_scroll_width": 1009, "page_overflow_px": 0, "navigation_client_width": 1009, "navigation_scroll_width": 1009, "navigation_overflow_x": "auto", "active_table_scrollers": 1, "table_wrappers": 10, "table_wrappers_with_overflow_x_auto": 10},
    {"requested": "390x844", "actual_browser_viewport": "390x844", "root_client_width": 375, "root_scroll_width": 375, "body_scroll_width": 375, "page_overflow_px": 0, "navigation_client_width": 375, "navigation_scroll_width": 922, "navigation_overflow_x": "auto", "active_table_scrollers": 10, "table_wrappers": 10, "table_wrappers_with_overflow_x_auto": 10},
]

NAVIGATION = [
    ("Progress", "#progress"),
    ("RUN-174", "#checkpoint"),
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
    ["Progress", "RUN-174", "Pages"],
    ["Static census", "Runtime gates", "Benchmarks"],
    ["Modules", "Finding status", "Architecture", "Gaps"],
]

# Exact builder list at build-current-audit-dashboard.py:4787-4867.
REQUIRED_VISIBLE_BOUNDARIES = (
    '<a href="#checkpoint">RUN-174</a>',
    '<a href="#findings">Finding status</a>',
    "665 owners · 308 routes + 357 pages · 96 bridges",
    "119 reviewed / 388 pending",
    "97 owned / 410 without ownership",
    "16.925426%",
    "3,264 records remain",
    "RUN-172–174 SAFE alert dedup remediation and reporting checkpoint",
    "fleet-assets.vehicles.alerts-config",
    "RUN090-ROUTE-0084 / RUN077-ROUTE-0692",
    "VehicleController::alertsConfig",
    "CAP-FLEET-VEHICLE-REGISTER",
    "index 83 integrated",
    "next index 84 RUN090-ROUTE-0085 / RUN077-ROUTE-0693",
    "fleet-assets.trips.index",
    "three provisional-not-final observations",
    "RUN-168: exact RUN-167 dashboard verified at 4/4 viewports",
    "RUN-169/R: queue index 83 Fleet alerts-config candidate independently reviewed OWNER",
    "RUN-170: exactly one route owner and one action bridge integrated",
    "RUN-170R: three sealed post-commit GO reviews",
    "RUN-171: live static ledger reported",
    "RUN-172: exact RUN-171 dashboard verified at 4/4 viewports",
    "RUN-173: SAFE concern-identity defect reproduced and remediated in exactly two transferred paths",
    "RUN-173R: exact remediation artifacts independently reviewed GO",
    "RUN-174: SAFE record reclassified in place",
    "9 current provisional P1 + 2 historical already-fixed + 1 historical remediated",
    "8 current provisional P1 + 2 historical already-fixed + 2 historical remediated",
    "12 retained claim identities",
    "83 / 1,589",
    "83/1,589 unique bounded total",
    "SAFE-ALERT-DEDUP-IDENTITY-01",
    "post-merge SAFE alert-dedup tests / 60 assertions",
    "4 failed + 1 warning-pass / 10 assertions",
    "supporting 28/73 bridge and 3/5 HsEvent evidence reported separately",
    "6 terminal-fixture failures occurred before bridge/dedup execution",
    "30-minute dedup window",
    "+5 minutes stays idempotent",
    "+31-minute lifecycle remains unchanged",
    "e488bd3edcda",
    "dc04067e304a",
    "705db2dc3ba0",
    "c39b07654705",
    "a8d813f1878c6a720f5308f28e5a591f90097961444876f93fcfe5a9262e909a",
    "not published to origin/main",
    "3 claim-specific test functions / 146 assertions / 3 synchronized two-process races",
    "excluded from both the historical 78/1,529 and current 83/1,589 totals",
    "supporting 43/716 overlaps",
    "73 bounded tests / 1,481 assertions",
    "5 focused tests / 48 assertions",
    "3 independent current-source ALREADY_FIXED reviews",
    "historical issue · already fixed on current main · not a final finding",
    "historical issue · already fixed on current main only for the bounded manual-entry register/stock clause · residual compound scope unadjudicated · not a final finding",
    "historical issue · remediated on current main · not a final finding",
    "historical issue · remediated on local main · not published to origin/main · 30-minute dedup contract and +31-minute lifecycle preserved · not a final finding",
    "MED-CD-SCOPE-01",
    "MED-CD-ATOMICITY-01",
    "RUN-166 separately establishes bounded manual-entry MED-CD-ATOMICITY execution without application or product-test change",
    "balance-check, destruction, delivery/adjustment/loss and sibling-writer, forced transient-deadlock retry, and stress/repeated-schedule scope remains unadjudicated",
    "two broader INR failures reproduce at base",
    "full-suite green false",
    "Fresh RUN-175 audit-dashboard verification required",
    "cf0090ec9724",
    "0b1920dade92",
    "7b2b5688c90e",
    "2/340 mappings",
    "0/340 final no-match/NCM",
    "338 unresolved targets",
    "one operating organisation across multiple Sites",
    "Gate 4 and audit completion false",
    "RUN-153/R establish the historical 664 bounded source-owner records (307 routes + 357 pages)",
    "at that historical checkpoint 116 queue rows were reviewed, 391 remained pending, and 413 remained without ownership",
    "662 historical cumulative owner records",
    "RUN-170/R current Fleet alerts-config route/action ownership",
    "RUN-090 frozen denominator / RUN-170R current accounting",
    "index 83 is integrated and index 84 fleet-assets.trips.index is next",
    "RUN-168 verifies that exact dashboard",
    "RUN-172 verifies only the superseded RUN-171 HTML",
    "dashboard HTML unchanged · fresh RUN-175 required",
    "visible 665/308/357 ownership, 96 bridges, 119/388 queue accounting, 97 owned/410 without ownership",
)


def run(*args: str) -> bytes:
    return subprocess.run(args, cwd=ROOT, check=True, capture_output=True).stdout


def git(*args: str) -> str:
    return run("git", *args).decode("utf-8").rstrip("\r\n")


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def canonical_sha256(value: Any) -> str:
    raw = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return sha256(raw)


def file_sha(relative: str) -> str:
    return sha256((AUDIT / relative).read_bytes())


def blob(relative: str) -> str:
    return git("hash-object", "--", str(AUDIT / relative))


def record(relative: str) -> dict[str, Any]:
    raw = (AUDIT / relative).read_bytes()
    return {"path": relative, "sha256": sha256(raw), "git_blob_id": blob(relative), "bytes": len(raw), "lines": raw.count(b"\n")}


def committed_record(relative: str) -> dict[str, Any]:
    repository_path = f"{PREFIX}/{relative}"
    raw = run("git", "show", f"{CHECKPOINT_COMMIT}:{repository_path}")
    return {"path": relative, "sha256": sha256(raw), "git_blob_id": git("rev-parse", f"{CHECKPOINT_COMMIT}:{repository_path}"), "bytes": len(raw), "lines": raw.count(b"\n")}


def diff_record(relative: str) -> dict[str, Any]:
    repository_path = f"{PREFIX}/{relative}"
    binary = run("git", "diff", "--binary", "--", repository_path)
    numstat = git("diff", "--numstat", "--", repository_path).split("\t")
    assert len(numstat) == 3 and numstat[2] == repository_path
    return {"path": relative, "binary_diff_sha256": sha256(binary), "numstat": {"added": int(numstat[0]), "deleted": int(numstat[1])}}


class Parser(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.hrefs: list[str] = []
        self.ids: list[str] = []
        self.headings = 0
        self.tables = 0
        self.table_wraps = 0

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
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
    return not (href.startswith("#") or href.startswith("//") or low.startswith(("http://", "https://", "mailto:", "tel:", "javascript:", "data:")))


def local_path(href: str) -> Path:
    target = (AUDIT / unquote(urlsplit(href).path)).resolve()
    target.relative_to(AUDIT.resolve())
    return target


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


def verify_self_seal(value: dict[str, Any], expected: str) -> None:
    without_seal = dict(value)
    actual = without_seal.pop("receipt_self_seal_sha256")
    assert actual == expected
    assert canonical_sha256(without_seal) == expected


def cleanup_state() -> dict[str, Any]:
    script = f"""
$run175Listeners = @(Get-NetTCPConnection -State Listen -LocalPort {LOOPBACK_PORT} -ErrorAction SilentlyContinue)
$run175PidProcess = Get-Process -Id {LOOPBACK_PID} -ErrorAction SilentlyContinue
$run175Matching = @(Get-CimInstance Win32_Process | Where-Object {{ $_.ProcessId -eq {LOOPBACK_PID} -or ($_.Name -like 'python*.exe' -and $_.CommandLine -like '*http.server*{LOOPBACK_PORT}*') }})
[pscustomobject]@{{ listener_count = $run175Listeners.Count; exact_pid_present = ($null -ne $run175PidProcess); matching_process_count = $run175Matching.Count }} | ConvertTo-Json -Compress
"""
    raw = run("powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script)
    value = json.loads(raw.decode("utf-8-sig"))
    assert value == {"listener_count": 0, "exact_pid_present": False, "matching_process_count": 0}
    return value


def main() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("show", "-s", "--format=%T", "HEAD") == CHECKPOINT_TREE
    assert git("show", "-s", "--format=%P", "HEAD") == CHECKPOINT_PARENT
    assert git("rev-parse", "origin/main") == ORIGIN_MAIN
    behind_ahead = git("rev-list", "--left-right", "--count", "origin/main...HEAD").split()
    assert behind_ahead == [str(LOCAL_MAIN_BEHIND), str(LOCAL_MAIN_AHEAD)]
    run("git", "diff", "--cached", "--quiet")
    assert git("diff", "--check") == ""

    expected_before = {
        f"M {PREFIX}/{HTML}",
        f"M {PREFIX}/{BUILDER}",
        f"?? {PREFIX}/{MATERIALIZER}",
    }
    if (AUDIT / OUTPUT).exists():
        expected_before.add(f"?? {PREFIX}/{OUTPUT}")
    observed_before = {line.lstrip() for line in git("status", "--porcelain").splitlines()}
    assert observed_before == expected_before, {"expected": sorted(expected_before), "observed": sorted(observed_before)}

    raw = (AUDIT / HTML).read_bytes()
    builder_raw = (AUDIT / BUILDER).read_bytes()
    assert (len(raw), sha256(raw), raw.count(b"\n")) == (HTML_BYTES, HTML_SHA256, HTML_LINES)
    assert (len(builder_raw), sha256(builder_raw), builder_raw.count(b"\n")) == (BUILDER_BYTES, BUILDER_SHA256, BUILDER_LINES)
    assert b"\r\n" not in raw and raw.endswith(b"\n")
    assert b"\r\n" not in builder_raw and builder_raw.endswith(b"\n")
    assert blob(HTML) == HTML_BLOB
    assert blob(BUILDER) == BUILDER_BLOB

    committed_builder = committed_record(BUILDER)
    committed_dashboard = committed_record(HTML)
    assert committed_builder == {
        "path": BUILDER,
        "sha256": COMMITTED_BUILDER_SHA256,
        "git_blob_id": COMMITTED_BUILDER_BLOB,
        "bytes": COMMITTED_BUILDER_BYTES,
        "lines": COMMITTED_BUILDER_LINES,
    }
    assert committed_dashboard == {
        "path": HTML,
        "sha256": COMMITTED_HTML_SHA256,
        "git_blob_id": COMMITTED_HTML_BLOB,
        "bytes": COMMITTED_HTML_BYTES,
        "lines": COMMITTED_HTML_LINES,
    }
    assert file_sha(RUN_174_MATERIALIZER) == RUN_174_MATERIALIZER_SHA256
    assert file_sha(RUN_174_RECEIPT) == RUN_174_RECEIPT_SHA256

    run_174 = strict_json(RUN_174_RECEIPT)
    verify_self_seal(run_174, RUN_174_RECEIPT_SELF_SEAL)
    assert run_174["pins"]["reporting_input_commit"] == CHECKPOINT_PARENT
    assert run_174["pins"]["origin_main_observed"] == ORIGIN_MAIN
    assert run_174["pins"]["safe_application_baseline_commit"] == SAFE_BASE_COMMIT
    assert run_174["pins"]["safe_application_baseline_tree"] == SAFE_BASE_TREE
    assert run_174["pins"]["safe_fix_commit"] == SAFE_FIX_COMMIT
    assert run_174["pins"]["safe_fix_tree"] == SAFE_FIX_TREE
    assert run_174["pins"]["safe_local_main_merge_commit"] == APPLICATION_MERGE_COMMIT
    assert run_174["pins"]["safe_local_main_merge_tree"] == APPLICATION_MERGE_TREE
    assert run_174["pins"]["dashboard_builder"]["sha256"] == COMMITTED_BUILDER_SHA256
    assert run_174["pins"]["unchanged_run_172_dashboard"]["sha256"] == COMMITTED_HTML_SHA256
    assert run_174["reporting_transition"]["counts_after"] == {
        "retained_claim_records": 12,
        "provisional_source_claims": 8,
        "historical_already_fixed": 2,
        "historical_remediated": 2,
        "final_P0": 0,
        "final_P1": 0,
    }
    accounting = run_174["bounded_execution_accounting"]
    assert accounting["prior_unique_total"] == {"tests": 78, "assertions": 1529}
    assert accounting["run_173_post_merge_unique_increment"] == {"tests": 5, "assertions": 60, "counted_once": True}
    assert accounting["unique_total"] == {"tests": 83, "assertions": 1589}
    assert accounting["excluded_from_unique_total"] == {
        "safe_red": {"failed": 4, "warning_pass": 1, "assertions_reported": 10},
        "isolated_green_replay": {"tests": 5, "assertions": 60},
        "supporting_control_room_bridge": {"tests": 28, "assertions": 73},
        "adjacent_hs_event_safeguarding": {"tests": 3, "assertions": 5},
        "pre_bridge_terminal_fixture_failures": 6,
        "med_cd_atomicity_and_overlapping_support": True,
    }
    assert run_174["publication_boundary"]["safe_application_published"] is False
    assert run_174["publication_boundary"]["publication_authorized"] is False
    assert run_174["dashboard_forward_gate"]["required_run"] == "RUN-175"
    assert run_174["dashboard_forward_gate"]["fresh_rebuild_required"] is True
    assert all(value is False for value in run_174["completion_boundary"].values())

    builder_text = builder_raw.decode("utf-8")
    for required_builder_boundary in (
        "current_visible_boundaries = [",
        "RUN-172–174 SAFE alert dedup remediation and reporting checkpoint",
        "Fresh RUN-175 audit-dashboard verification required",
        "cf0090ec9724",
        MATERIALIZER,
        OUTPUT,
        ".tmp-run175-dashboard",
    ):
        assert required_builder_boundary in builder_text

    text = raw.decode("utf-8")
    parser = Parser()
    parser.feed(text)
    assert parser.headings == 26
    assert parser.tables == 10
    assert parser.table_wraps == 10
    assert len(parser.hrefs) == 812
    assert len(parser.ids) == 10

    id_counts = Counter(parser.ids)
    duplicate_authored_ids = sorted(key for key, count in id_counts.items() if count > 1)
    assert not duplicate_authored_ids
    hash_hrefs = [href for href in parser.hrefs if href.startswith("#")]
    local_hrefs = [href for href in parser.hrefs if is_local(href)]
    unique_local = sorted(set(local_hrefs))
    assert len(hash_hrefs) == 10 and len(set(hash_hrefs)) == 10
    assert len(local_hrefs) == 802 and len(unique_local) == 435
    assert [href for _, href in NAVIGATION] == hash_hrefs
    missing_anchors = sorted({href for href in hash_hrefs if href[1:] not in id_counts})
    assert not missing_anchors
    assert text.count(f'href="{MATERIALIZER}"') == 2
    assert text.count(f'href="{OUTPUT}"') == 3

    non_forward_unique = [href for href in unique_local if href != OUTPUT]
    assert len(non_forward_unique) == 434
    prewrite_local_failures = [href for href in non_forward_unique if not local_path(href).exists()]
    assert not prewrite_local_failures

    hash_pairs = re.findall(r'<a href="([^"#]+)">[^<]*</a>\s*<code>([0-9a-f]{64})</code>', text)
    assert len(hash_pairs) == 693
    assert len(set(hash_pairs)) == 362
    assert len({path for path, _ in hash_pairs}) == 362
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
                hash_failures.append({"href": href, "expected": expected, "actual": actual})
        elif target.is_dir():
            directory_digest_occurrences += 1
            directory_digest_paths.add(href)
    assert hash_bearing_file_occurrences == 691
    assert len(hash_bearing_file_paths) == 361
    assert directory_digest_occurrences == 2
    assert directory_digest_paths == {"task-scripts/"}
    assert not hash_failures
    assert sum(1 for href, _ in hash_pairs if href == MATERIALIZER) == 0
    assert sum(1 for href, _ in hash_pairs if href == OUTPUT) == 0

    assert len(REQUIRED_VISIBLE_BOUNDARIES) == 79
    visible_static = {value: value in text for value in REQUIRED_VISIBLE_BOUNDARIES}
    assert len(visible_static) == 79
    assert all(visible_static.values()), [value for value, present in visible_static.items() if not present]
    assert "tenant" not in text.lower()
    prohibited_text = {
        "stale_current_checkpoint": "RUN-168–171 current Fleet alerts-config ownership and reporting checkpoint",
        "stale_fresh_run_172": "Fresh RUN-172 audit-dashboard verification required",
        "stale_current_9_2_1_split": "12 retained claim identities · 9 current provisional P1 · 2 historical already-fixed · 1 historical remediated",
        "incorrect_publication": "SAFE application fix published to origin/main",
        "incorrect_gate_4": "Gate 4 and audit completion true",
    }
    prohibited_hits = {key: value for key, value in prohibited_text.items() if value in text}
    assert not prohibited_hits

    cleanup = cleanup_state()
    builder_diff = diff_record(BUILDER)
    dashboard_diff = diff_record(HTML)
    assert builder_diff == {"path": BUILDER, "binary_diff_sha256": BUILDER_DIFF_SHA256, "numstat": {"added": 1, "deleted": 1}}
    assert dashboard_diff == {"path": HTML, "binary_diff_sha256": HTML_DIFF_SHA256, "numstat": {"added": 20, "deleted": 20}}

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
            "target_top_px": 0,
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

    receipt: dict[str, Any] = {
        "schema_version": "run-175-audit-dashboard-verification-wave-32-v1",
        "run_id": "RUN-175-AUDIT-DASHBOARD-VERIFICATION-WAVE-32",
        "generated_on": "2026-08-30",
        "status": (
            "AUDIT_DASHBOARD_RUN174_EXACT_ARTIFACT_RESPONSIVE_LINK_CONSOLE_"
            "VERIFICATION_GO_MED_PIN_CORRECTED_LOCAL_ONLY_ZERO_APPLICATION_"
            "PUBLICATION_FINAL_FINDING_OR_COMPLETION_CREDIT"
        ),
        "architecture_rule": {
            "operating_organisations": 1,
            "multiple_sites": True,
            "multi_tenant": False,
            "authorization_boundary": "Site access, exact roles/permissions, canonical ownership, privacy, and direct-object denial",
        },
        "scope": "Exact corrected RUN-174 dashboard, one-line exact medication-pin preservation in its builder, and bounded audit-artifact verification only",
        "pins": {
            "governing_prompt_sha256": GOVERNING_PROMPT_SHA256,
            "run_174_checkpoint_commit": CHECKPOINT_COMMIT,
            "run_174_checkpoint_tree": CHECKPOINT_TREE,
            "run_174_checkpoint_parent": CHECKPOINT_PARENT,
            "origin_main_before_run_175_commit": ORIGIN_MAIN,
            "local_main_ahead": LOCAL_MAIN_AHEAD,
            "local_main_behind": LOCAL_MAIN_BEHIND,
            "effective_local_application_merge_commit": APPLICATION_MERGE_COMMIT,
            "effective_local_application_merge_tree": APPLICATION_MERGE_TREE,
            "safe_baseline_commit": SAFE_BASE_COMMIT,
            "safe_baseline_tree": SAFE_BASE_TREE,
            "safe_fix_commit": SAFE_FIX_COMMIT,
            "safe_fix_tree": SAFE_FIX_TREE,
            "run_174_materializer": record(RUN_174_MATERIALIZER),
            "run_174_receipt": {**record(RUN_174_RECEIPT), "receipt_self_seal_sha256": RUN_174_RECEIPT_SELF_SEAL},
            "run_174_committed_builder": committed_builder,
            "run_172_frozen_dashboard_at_run_174_checkpoint": committed_dashboard,
            "run_175_builder": record(BUILDER),
            "run_175_dashboard": record(HTML),
            "run_175_receipt_materializer": record(MATERIALIZER),
        },
        "lineage": {
            "run_172": "verifies only the now-superseded RUN-171 dashboard",
            "run_173": "establishes SAFE remediation, bounded runtime, local-main integration, and nonpublication",
            "run_173r": "independently authorizes bounded SAFE retirement reporting only",
            "run_174": "reclassifies SAFE and reports 8+2+2 with 83/1,589 unique bounded execution",
            "run_175": "restores exact MED-RBAC, MED-CD-SCOPE, and MED-CD-ATOMICITY pin wording in the builder and verifies only the resulting audit artifact",
            "historical_9_plus_2_plus_1_visible_only_as_superseded_lineage": True,
        },
        "dashboard_generation_and_med_pin_correction": {
            "committed_builder": committed_builder,
            "final_builder": record(BUILDER),
            "committed_dashboard": committed_dashboard,
            "final_dashboard": record(HTML),
            "builder_change": builder_diff,
            "dashboard_change": dashboard_diff,
            "correction_scope": "one-line preservation of MED-RBAC 4f57ad4202df, MED-CD-SCOPE 0b1920dade92/tree 7b2b5688c90e, and MED-CD-ATOMICITY source-adjudication cf0090ec9724 pin attribution",
            "correction_expands_medication_credit": False,
            "final_builder_runs_observed": 2,
            "final_builder_runs_byte_identical": True,
            "independent_semantic_static_review": {
                "result": "GO",
                "findings": [],
                "dashboard_sha256": HTML_SHA256,
                "builder_sha256": BUILDER_SHA256,
                "visible_static_checks": "79/79",
                "adjacent_file_hashes": "691/691",
                "browser_credit": False,
                "reviewer_file_changes": [],
            },
            "application_source_or_product_test_change": False,
            "credit_effect": "audit-dashboard attribution correctness and exact-artifact verification only",
        },
        "verification_method": {
            "browser": "Codex in-app browser",
            "served_from": f"temporary loopback-only Python HTTP server on 127.0.0.1:{LOOPBACK_PORT}",
            "target_url": BROWSER_TARGET_URL,
            "cachebuster": BROWSER_CACHEBUSTER,
            "response_status": 200,
            "response_content_type": "text/html",
            "response_bytes": HTML_BYTES,
            "response_sha256": HTML_SHA256,
            "external_testing": False,
            "desktop_and_mobile_visual_inspection": "GO on final SHA",
            "viewport_override_reset_after_test": True,
            "browser_tab_closed_after_test": True,
        },
        "verification": {
            "dashboard_builder_final_byte_identical_runs_observed": 2,
            "dashboard_builder_final_runs_byte_identical": True,
            "receipt_materializer_final_byte_identical_runs_required": 2,
            "noncreditable_attempts": [
                {
                    "attempt": "initial corrected-builder self-check",
                    "result": "missing exact cf0090ec9724 MED-CD-ATOMICITY source-adjudication pin; all three medication pins were restored before final generation",
                    "counted_as_generation_or_artifact_evidence": False,
                },
                {
                    "attempt": "initial ten-link navigation batch",
                    "result": "timed out; only later final-SHA 3+3+4 batches are credited",
                    "counted_as_navigation_evidence": False,
                },
            ],
            "viewports_required": 4,
            "viewports_verified": 4,
            "viewports": VIEWPORTS,
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
            "navigation_results": navigation_results,
            "console_warning_entries": 0,
            "console_error_entries": 0,
            "uncaught_page_error_entries": 0,
            "browser_dev_log_entries": 0,
            "authored_ids": len(parser.ids),
            "browser_dom_ids": 11,
            "browser_injected_ids": ["codex-browser-sidebar-comments-root"],
            "duplicate_authored_ids": duplicate_authored_ids,
            "heading_elements": parser.headings,
            "table_elements": parser.tables,
            "table_wrappers": parser.table_wraps,
            "anchor_elements": len(parser.hrefs),
            "anchor_elements_rendered_in_browser": 812,
            "hash_anchor_occurrences": len(hash_hrefs),
            "unique_hash_anchors": len(set(hash_hrefs)),
            "missing_hash_targets": missing_anchors,
            "local_resource_link_occurrences": len(local_hrefs),
            "unique_local_resources": len(unique_local),
            "prewrite_non_forward_local_resources": "434/434",
            "prewrite_non_forward_local_resource_failures": prewrite_local_failures,
            "run_175_forward_receipt_is_intentional_unhashed_self_link": True,
            "post_materialization_local_resources": "435/435",
            "post_materialization_local_resource_failures": [],
            "adjacent_hash_pair_occurrences": len(hash_pairs),
            "unique_adjacent_hash_path_hash_pairs": len(set(hash_pairs)),
            "unique_adjacent_hash_paths": len({path for path, _ in hash_pairs}),
            "hash_bearing_file_occurrences_verified": hash_bearing_file_occurrences,
            "unique_hash_bearing_file_paths_verified": len(hash_bearing_file_paths),
            "historical_directory_bundle_digest_occurrences": directory_digest_occurrences,
            "historical_directory_bundle_digest_paths": sorted(directory_digest_paths),
            "hash_bearing_link_failures": hash_failures,
            "run_175_generator_link_occurrences": text.count(f'href="{MATERIALIZER}"'),
            "run_175_generator_link_adjacent_hash_occurrences": sum(1 for href, _ in hash_pairs if href == MATERIALIZER),
            "run_175_forward_receipt_link_occurrences": text.count(f'href="{OUTPUT}"'),
            "run_175_forward_receipt_link_adjacent_hash_occurrences": sum(1 for href, _ in hash_pairs if href == OUTPUT),
            "visible_static_checks_required": len(visible_static),
            "visible_static_checks_passed": sum(visible_static.values()),
            "visible_static_checks": visible_static,
            "prohibited_visible_phrase_hits": prohibited_hits,
            "single_organisation_multi_site_wording_present": True,
            "tenant_word_present": False,
        },
        "reported_finding_boundary": {
            "retained_claim_records": 12,
            "current_provisional_source_claims": 8,
            "historical_already_fixed_records": 2,
            "historical_remediated_records": 2,
            "superseded_pre_run_174_split": {
                "current_provisional_source_claims": 9,
                "historical_already_fixed_records": 2,
                "historical_remediated_records": 1,
                "current_credit": False,
            },
            "final_P0": 0,
            "final_P1": 0,
            "safe_alert_dedup_identity_status": "HISTORICAL_SOURCE_ISSUE_REMEDIATED_LOCAL_MAIN_NOT_PUBLISHED_NOT_FINAL_FINDING",
            "safe_contract": "single-organisation multi-Site identity; 30-minute window, +5-minute retry idempotency, unchanged +31-minute lifecycle",
        },
        "bounded_execution_accounting": {
            "med_rbac": {"tests": 73, "assertions": 1481},
            "med_scope": {"tests": 5, "assertions": 48},
            "safe_post_merge_unique_increment": {"tests": 5, "assertions": 60, "counted_once": True},
            "unique_total": {"tests": 83, "assertions": 1589},
            "excluded_from_unique_total": {
                "safe_red": {"failed": 4, "warning_pass": 1, "assertions_reported": 10},
                "safe_isolated_green_replay": {"tests": 5, "assertions": 60},
                "supporting_control_room_bridge": {"tests": 28, "assertions": 73},
                "adjacent_hs_event_safeguarding": {"tests": 3, "assertions": 5},
                "pre_bridge_terminal_fixture_failures": 6,
                "med_cd_atomicity_claim_specific": "3 test functions / 146 assertions / 3 synchronized two-process races",
                "med_cd_atomicity_supporting_overlap": "43 tests / 716 assertions",
            },
            "red_warning_or_terminal_failure_credit": False,
            "supporting_or_adjacent_recredit": False,
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
            "next_route": "fleet-assets.trips.index",
            "correctness_credit": False,
        },
        "noninheritance_boundary": {
            "application_source_or_product_test": False,
            "application_runtime_reexecution": False,
            "application_browser": False,
            "consumer_caller_service_model_page_or_neighbor": False,
            "safe_red_isolated_green_supporting_or_terminal_runs": False,
            "timeless_retry_within_window_escalation_or_unused_parameter": False,
            "balance_check_destruction_sibling_writer_deadlock_retry_or_stress": False,
            "benchmark_mapping_or_final_no_match_NCM": False,
            "ease": False,
            "final_finding": False,
            "feature_module_or_pass_completion": False,
            "release_publication_or_audit_completion": False,
        },
        "root_browser_resource_cleanup": {
            "temporary_loopback_port": LOOPBACK_PORT,
            "temporary_server_pid": LOOPBACK_PID,
            "listeners_after_cleanup": cleanup["listener_count"],
            "exact_pid_present_after_cleanup": cleanup["exact_pid_present"],
            "matching_python_http_server_processes_after_cleanup": cleanup["matching_process_count"],
            "browser_viewport_override_reset": True,
            "browser_tab_closed": True,
        },
        "worktree_boundary": {
            "expected_final_status_count": 4,
            "expected_final_porcelain_statuses": sorted({
                f"M {PREFIX}/{HTML}",
                f"M {PREFIX}/{BUILDER}",
                f"?? {PREFIX}/{MATERIALIZER}",
                f"?? {PREFIX}/{OUTPUT}",
            }),
            "no_staged_paths": True,
            "git_diff_check_clean": True,
            "exact_match_required": True,
        },
        "mutation_attestation": {
            "sequence_paths": [HTML, BUILDER, MATERIALIZER, OUTPUT],
            "receipt_materializer_persistent_write_scope": [OUTPUT],
            "application_paths_changed": [],
            "product_test_paths_changed": [],
            "findings_register_changed_by_run_175": False,
            "run_174_human_readable_source_reporting_surfaces_changed_by_run_175": False,
            "audit_dashboard_html_changed_by_run_175": True,
            "forms_submitted": False,
            "records_opened": False,
            "screenshots_retained": False,
            "database_changed": False,
            "application_tests_or_build_run": False,
        },
        "remote_state_boundary": {
            "origin_main_before_run_175_commit": ORIGIN_MAIN,
            "local_main_ahead_before_run_175_commit": LOCAL_MAIN_AHEAD,
            "local_main_behind_before_run_175_commit": LOCAL_MAIN_BEHIND,
            "safe_application_merge_remains_local_only": True,
            "push_or_publication_performed_by_materializer": False,
            "publication_claim": False,
        },
        "credit_boundary": {
            "audit_dashboard_run_175_med_pin_correction": True,
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
        },
        "completion_boundary": completion,
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "run_175_sequence_written_paths": [
            f"{PREFIX}/{HTML}",
            f"{PREFIX}/{BUILDER}",
            f"{PREFIX}/{MATERIALIZER}",
            f"{PREFIX}/{OUTPUT}",
        ],
    }
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    output_bytes = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")

    output_path = AUDIT / OUTPUT
    output_path.parent.mkdir(parents=True, exist_ok=True)
    temporary_path = output_path.with_name(f".{output_path.name}.tmp-run175")
    assert not temporary_path.exists(), f"Refusing to overwrite stale receipt temp: {temporary_path}"
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
    assert file_sha(HTML) == HTML_SHA256
    assert file_sha(BUILDER) == BUILDER_SHA256
    assert all(local_path(href).exists() for href in unique_local)

    expected_after = {
        f"M {PREFIX}/{HTML}",
        f"M {PREFIX}/{BUILDER}",
        f"?? {PREFIX}/{MATERIALIZER}",
        f"?? {PREFIX}/{OUTPUT}",
    }
    observed_after = {line.lstrip() for line in git("status", "--porcelain").splitlines()}
    assert observed_after == expected_after, {"expected": sorted(expected_after), "observed": sorted(observed_after)}
    assert git("diff", "--check") == ""
    run("git", "diff", "--cached", "--quiet")
    assert not list(AUDIT.rglob("__pycache__"))

    print(json.dumps({
        "run_id": written["run_id"],
        "status": written["status"],
        "dashboard_sha256": HTML_SHA256,
        "builder_sha256": BUILDER_SHA256,
        "materializer_sha256": file_sha(MATERIALIZER),
        "receipt_sha256": file_sha(OUTPUT),
        "receipt_self_seal_sha256": written_seal,
        "visible_checks": len(visible_static),
        "navigation": "10/10",
        "viewports": "4/4",
        "unique_local_resources": len(unique_local),
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
