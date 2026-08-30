#!/usr/bin/env python3
"""Seal bounded RUN172 facts for the exact corrected RUN171 audit dashboard.

Static facts are reconstructed from pinned bytes. Browser facts are limited to
the completed loopback-only final-SHA observations recorded below; superseded
or timed-out attempts grant no credit.
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
MATERIALIZER = "generators/materialize-run-172-audit-dashboard-verification-wave-31.py"
OUTPUT = "evidence/browser/current-audit-dashboard-verification-run-172-wave-31.json"
HTML = "audit-dashboard.html"
BUILDER = "generators/build-current-audit-dashboard.py"
RUN_171_MATERIALIZER = "generators/materialize-run-171-reviewed-fleet-vehicle-alerts-config-route-action-reporting-wave-31.py"
RUN_171_RECEIPT = "evidence/source/current-run-171-reviewed-fleet-vehicle-alerts-config-route-action-reporting-wave-31.json"

CHECKPOINT_COMMIT = "f6589d61f249de853431266d5214b6255167594b"
CHECKPOINT_TREE = "6e21d06b088c28af3700709ce5f5da6fa35c3ef6"
CHECKPOINT_PARENT = "ca1c53bc3062a6fe81f2855716de13636d59ac0c"
APPLICATION_COMMIT = "e488bd3edcda0f154f87e8bbed972f14db409b82"
APPLICATION_TREE = "9e93b8aea4f4b907cde3dc59dd0520fba5bd7080"
GOVERNING_PROMPT_SHA256 = "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d"
RUN_171_MATERIALIZER_SHA256 = "a48edf215eed8b0dcf95bff5e0592cab94d6946391b048317f0a21547fb3d68b"
RUN_171_RECEIPT_SHA256 = "9ddc5386a57f782a50564d54d33a14826a84b1c91a6bb276dcd50a15e152a8ba"
RUN_171_RECEIPT_SELF_SEAL = "90698c71cc932d9a2d2d6d3bc030c99465f8b471be035bf85eae841e245548db"
RUN_171_COMMITTED_BUILDER_SHA256 = "f171941c116af15547aecb678e6ebd442d1681c91662645ed1cf7cc2d7f8bbfc"
RUN_168_FROZEN_DASHBOARD_SHA256 = "80360ae152642e4f7c0c90b18c42e76fb156bf8cd34eb9df17b358170cc71b89"
BUILDER_SHA256 = "7043c30868ba11ecb82c8492d5721bd08fc0834d0f5e028457e25a85d48ee5cf"
BUILDER_BYTES = 529981
BUILDER_LINES = 4633
HTML_SHA256 = "79bb5c671606ca6f596bba6d9a0649ceed9acc549ec57174c6a1102ea22d3f47"
HTML_BYTES = 270828
HTML_LINES = 78

BROWSER_CACHEBUSTER = "main-f6589d61-79bb5c67"
BROWSER_TARGET_URL = "http://127.0.0.1:43172/audit-dashboard.html?v=main-f6589d61-79bb5c67#progress"
LOOPBACK_PORT = 43172
LOOPBACK_PID = 28520

VIEWPORTS = [
    {"requested": "1440x900", "actual_browser_viewport": "1440x900", "root_client_width": 1425, "root_scroll_width": 1425, "body_scroll_width": 1425, "page_overflow_px": 0, "navigation_client_width": 1425, "navigation_scroll_width": 1425, "navigation_overflow_x": "auto", "active_table_scrollers": 0, "table_wrappers": 10},
    {"requested": "1280x800", "actual_browser_viewport": "1280x800", "root_client_width": 1265, "root_scroll_width": 1265, "body_scroll_width": 1265, "page_overflow_px": 0, "navigation_client_width": 1265, "navigation_scroll_width": 1265, "navigation_overflow_x": "auto", "active_table_scrollers": 0, "table_wrappers": 10},
    {"requested": "1024x768", "actual_browser_viewport": "1024x768", "root_client_width": 1009, "root_scroll_width": 1009, "body_scroll_width": 1009, "page_overflow_px": 0, "navigation_client_width": 1009, "navigation_scroll_width": 1009, "navigation_overflow_x": "auto", "active_table_scrollers": 1, "table_wrappers": 10},
    {"requested": "390x844", "actual_browser_viewport": "390x844", "root_client_width": 375, "root_scroll_width": 375, "body_scroll_width": 375, "page_overflow_px": 0, "navigation_client_width": 375, "navigation_scroll_width": 922, "navigation_overflow_x": "auto", "active_table_scrollers": 10, "table_wrappers": 10},
]

NAVIGATION = [
    ("Progress", "#progress"),
    ("RUN-171", "#checkpoint"),
    ("Pages", "#pages"),
    ("Static census", "#static-census"),
    ("Runtime gates", "#runtime"),
    ("Benchmarks", "#benchmarks"),
    ("Modules", "#modules"),
    ("Finding status", "#findings"),
    ("Architecture", "#architecture"),
    ("Gaps", "#gaps"),
]


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


def cleanup_state() -> dict[str, Any]:
    script = f"""
$run172Listeners = @(Get-NetTCPConnection -State Listen -LocalPort {LOOPBACK_PORT} -ErrorAction SilentlyContinue)
$run172PidProcess = Get-Process -Id {LOOPBACK_PID} -ErrorAction SilentlyContinue
$run172Matching = @(Get-CimInstance Win32_Process | Where-Object {{ $_.ProcessId -eq {LOOPBACK_PID} -or ($_.Name -like 'php*.exe' -and $_.CommandLine -like '*127.0.0.1:{LOOPBACK_PORT}*') }})
[pscustomobject]@{{ listener_count = $run172Listeners.Count; exact_pid_present = ($null -ne $run172PidProcess); matching_process_count = $run172Matching.Count }} | ConvertTo-Json -Compress
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
    assert git("rev-parse", "origin/main") == CHECKPOINT_COMMIT

    expected_before = {f"M {PREFIX}/{HTML}", f"M {PREFIX}/{BUILDER}", f"?? {PREFIX}/{MATERIALIZER}"}
    if (AUDIT / OUTPUT).exists():
        expected_before.add(f"?? {PREFIX}/{OUTPUT}")
    observed_before = {line.lstrip() for line in git("status", "--porcelain").splitlines()}
    assert observed_before == expected_before, {"expected": sorted(expected_before), "observed": sorted(observed_before)}

    raw = (AUDIT / HTML).read_bytes()
    builder_raw = (AUDIT / BUILDER).read_bytes()
    assert len(raw) == HTML_BYTES and sha256(raw) == HTML_SHA256 and raw.count(b"\n") == HTML_LINES
    assert len(builder_raw) == BUILDER_BYTES and sha256(builder_raw) == BUILDER_SHA256 and builder_raw.count(b"\n") == BUILDER_LINES
    assert b"\r\n" not in raw and raw.endswith(b"\n")
    assert b"\r\n" not in builder_raw and builder_raw.endswith(b"\n")

    committed_builder = committed_record(BUILDER)
    committed_dashboard = committed_record(HTML)
    assert committed_builder["sha256"] == RUN_171_COMMITTED_BUILDER_SHA256
    assert committed_dashboard["sha256"] == RUN_168_FROZEN_DASHBOARD_SHA256
    assert file_sha(RUN_171_MATERIALIZER) == RUN_171_MATERIALIZER_SHA256
    assert file_sha(RUN_171_RECEIPT) == RUN_171_RECEIPT_SHA256

    run_171 = strict_json(RUN_171_RECEIPT)
    run_171_without_seal = dict(run_171)
    run_171_seal = run_171_without_seal.pop("receipt_self_seal_sha256")
    assert canonical_sha256(run_171_without_seal) == run_171_seal == RUN_171_RECEIPT_SELF_SEAL
    assert run_171["pins"]["application_commit"] == APPLICATION_COMMIT
    assert run_171["pins"]["application_tree"] == APPLICATION_TREE
    assert run_171["pins"]["dashboard_generator"]["sha256"] == RUN_171_COMMITTED_BUILDER_SHA256
    assert run_171["pins"]["unchanged_run_168_dashboard"]["sha256"] == RUN_168_FROZEN_DASHBOARD_SHA256
    assert run_171["completion_boundary"]["gate_4_complete"] is False
    assert run_171["completion_boundary"]["audit_complete"] is False

    builder_text = builder_raw.decode("utf-8")
    for required_builder_boundary in (
        "run_172_semantic_attribution_rewrites",
        "Expected one RUN-172 semantic attribution target",
        "RUN-090 frozen denominator / RUN-170R current accounting",
        "visible 665/308/357 ownership, 96 bridges, 119/388 queue accounting, 97 owned/410 without ownership",
        "94b56628243821d33fefec9b96841597cc599a65018aba15008a249deafef799",
        "37e318135d31a588caf32db25de58f338c878b3a0f71ae68d0ce1cede3826ac4",
        ".tmp-run172-dashboard",
    ):
        assert required_builder_boundary in builder_text

    text = raw.decode("utf-8")
    parser = Parser()
    parser.feed(text)
    assert parser.headings == 26
    assert parser.tables == 10
    assert parser.table_wraps == 10
    assert len(parser.hrefs) == 791
    assert len(parser.ids) == 10

    id_counts = Counter(parser.ids)
    duplicate_authored_ids = sorted(key for key, count in id_counts.items() if count > 1)
    assert not duplicate_authored_ids
    hash_hrefs = [href for href in parser.hrefs if href.startswith("#")]
    local_hrefs = [href for href in parser.hrefs if is_local(href)]
    unique_local = sorted(set(local_hrefs))
    assert len(hash_hrefs) == 10 and len(set(hash_hrefs)) == 10
    assert len(local_hrefs) == 781 and len(unique_local) == 426
    assert [href for _, href in NAVIGATION] == hash_hrefs
    missing_anchors = sorted({href for href in hash_hrefs if href[1:] not in id_counts})
    assert not missing_anchors
    assert text.count(f'href="{OUTPUT}"') == 1

    non_forward_unique = [href for href in unique_local if href != OUTPUT]
    prewrite_local_failures = [href for href in non_forward_unique if not local_path(href).exists()]
    assert not prewrite_local_failures

    hash_pairs = re.findall(r'<a href="([^"#]+)">[^<]*</a>\s*<code>([0-9a-f]{64})</code>', text)
    assert len(hash_pairs) == 677
    assert len(set(hash_pairs)) == 354
    assert len({path for path, _ in hash_pairs}) == 354
    hash_failures: list[dict[str, str]] = []
    hash_bearing_files = 0
    hash_bearing_file_paths: set[str] = set()
    directory_digest_paths: set[str] = set()
    for href, expected in hash_pairs:
        target = local_path(href)
        if target.is_file():
            hash_bearing_files += 1
            hash_bearing_file_paths.add(href)
            actual = sha256(target.read_bytes())
            if actual != expected:
                hash_failures.append({"href": href, "expected": expected, "actual": actual})
        elif target.is_dir():
            directory_digest_paths.add(href)
    assert not hash_failures
    assert OUTPUT not in {href for href, _ in hash_pairs}

    required_text = {
        "run_171_navigation": ("RUN-171",),
        "current_checkpoint": ("RUN-168–171 current Fleet alerts-config ownership and reporting checkpoint",),
        "ownership": ("665 owners · 308 routes + 357 pages · 96 bridges",),
        "queue_reviewed_pending": ("119 reviewed / 388 pending",),
        "queue_owned_without": ("97 owned / 410 without ownership",),
        "ownership_percent": ("16.925426%",),
        "residual": ("3,264 records remain",),
        "route_name": ("fleet-assets.vehicles.alerts-config",),
        "route_ids": ("RUN090-ROUTE-0084 / RUN077-ROUTE-0692",),
        "controller_action": ("VehicleController::alertsConfig",),
        "feature_id": ("CAP-FLEET-VEHICLE-REGISTER",),
        "cursor_integrated": ("index 83 integrated",),
        "next_cursor": ("next index 84 RUN090-ROUTE-0085 / RUN077-ROUTE-0693",),
        "next_route": ("fleet-assets.trips.index",),
        "observations": ("three provisional-not-final observations",),
        "run_168": ("RUN-168: exact RUN-167 dashboard verified at 4/4 viewports",),
        "run_169": ("RUN-169/R: queue index 83 Fleet alerts-config candidate independently reviewed OWNER",),
        "run_170": ("RUN-170: exactly one route owner and one action bridge integrated",),
        "run_170r": ("RUN-170R: three sealed post-commit GO reviews",),
        "run_171": ("RUN-171: live static ledger reported",),
        "finding_split": ("9 current provisional P1 + 2 historical already-fixed + 1 historical remediated",),
        "retained_identities": ("12 retained claim identities",),
        "atomicity_runtime": ("3 claim-specific test functions / 146 assertions / 3 synchronized two-process races",),
        "atomicity_nonaggregate": ("separately reported, not added to 78/1,529",),
        "supporting_overlap": ("supporting 43/716 overlaps",),
        "med_rbac_runtime": ("73 bounded tests / 1,481 assertions",),
        "med_scope_runtime": ("5 focused tests / 48 assertions",),
        "source_reviews": ("3 independent current-source ALREADY_FIXED reviews",),
        "historical_fixed": ("historical issue · already fixed on current main · not a final finding",),
        "atomicity_bounded": ("historical issue · already fixed on current main only for the bounded manual-entry register/stock clause · residual compound scope unadjudicated · not a final finding",),
        "historical_remediated": ("historical issue · remediated on current main · not a final finding",),
        "med_scope_id": ("MED-CD-SCOPE-01",),
        "med_atomicity_id": ("MED-CD-ATOMICITY-01",),
        "run_166_boundary": ("RUN-166 separately establishes bounded manual-entry MED-CD-ATOMICITY execution without application or product-test change",),
        "residual_scope": ("balance-check, destruction, delivery/adjustment/loss and sibling-writer, forced transient-deadlock retry, and stress/repeated-schedule scope remains unadjudicated",),
        "base_failures": ("two broader INR failures reproduce at base",),
        "full_suite": ("full-suite green false",),
        "fresh_run_172": ("Fresh RUN-172 audit-dashboard verification required",),
        "source_pin": ("cf0090ec9724",),
        "application_pin": ("0b1920dade92",),
        "tree_pin": ("7b2b5688c90e",),
        "benchmark_mapping": ("2/340 mappings",),
        "benchmark_ncm": ("0/340 final no-match/NCM",),
        "benchmark_unresolved": ("338 unresolved targets",),
        "architecture": ("one operating organisation across multiple Sites",),
        "completion_false": ("Gate 4 and audit completion false",),
        "historical_664": ("RUN-153/R establish the historical 664 bounded source-owner records (307 routes + 357 pages)",),
        "historical_queue": ("at that historical checkpoint 116 queue rows were reviewed, 391 remained pending, and 413 remained without ownership",),
        "historical_662": ("662 historical cumulative owner records",),
        "current_row": ("RUN-170/R current Fleet alerts-config route/action ownership",),
        "current_queue_label": ("RUN-090 frozen denominator / RUN-170R current accounting",),
        "current_cursor_sentence": ("index 83 is integrated and index 84 fleet-assets.trips.index is next",),
        "run_168_temporal": ("RUN-168 subsequently verified the exact RUN-167 dashboard",),
        "run_172_contract": ("visible 665/308/357 ownership, 96 bridges, 119/388 queue accounting, 97 owned/410 without ownership",),
        "in_progress": ("IN PROGRESS · NOT COMPREHENSIVE",),
    }
    visible_static = {key: all(value in text for value in values) for key, values in required_text.items()}
    assert len(visible_static) == 55
    assert all(visible_static.values()), [key for key, value in visible_static.items() if not value]
    assert "tenant" not in text.lower()

    prohibited_text = {
        "hybrid_run153_665": "RUN-153/R establish 665 bounded source-owner records",
        "stale_run153_current": "RUN-153/R current Fleet vehicle-register",
        "stale_run157_current": "RUN-157 current reporting refresh",
        "stale_fresh_run158": "fresh RUN-158 dashboard verification required",
        "stale_run142_665": "RUN-142/R: one route row and one bridge integrated and independently verified · zero page/sibling/caller/neighbor/next-row inheritance · 665 cumulative owner records",
        "hybrid_run153_96": "RUN-153/R establish 665 bounded source-owner records and 96 action bridges",
        "stale_fresh_run168": "and fresh RUN-168 audit-dashboard verification required.",
        "stale_run172_contract": "visible 664/307/357 ownership, 95 bridges, 118/389 queue accounting",
        "stale_run090_label": "<tr><td>RUN-090 direct-exact queue</td>",
    }
    prohibited_hits = {key: value for key, value in prohibited_text.items() if value in text}
    assert not prohibited_hits

    cleanup = cleanup_state()
    builder_diff = diff_record(BUILDER)
    dashboard_diff = diff_record(HTML)
    assert builder_diff["numstat"]["added"] > 0
    assert dashboard_diff["numstat"]["added"] > 0

    navigation_results = [{"label": label, "href": href, "browser_click_performed": True, "resulting_hash": href, "target_exists": True, "target_top_px": 0, "pass": True} for label, href in NAVIGATION]

    receipt: dict[str, Any] = {
        "schema_version": "run-172-audit-dashboard-verification-wave-31-v1",
        "run_id": "RUN-172-AUDIT-DASHBOARD-VERIFICATION-WAVE-31",
        "generated_on": "2026-08-30",
        "status": "AUDIT_DASHBOARD_RUN171_EXACT_ARTIFACT_RESPONSIVE_LINK_CONSOLE_VERIFICATION_GO_SEMANTIC_ATTRIBUTION_CORRECTED_ZERO_APPLICATION_OR_COMPLETION_CREDIT",
        "architecture_rule": {"operating_organisations": 1, "multiple_sites": True, "multi_tenant": False, "authorization_boundary": "Site access, exact roles/permissions, canonical ownership, privacy, and direct-object denial"},
        "scope": "Exact corrected RUN-171 reporting dashboard and narrow audit-generator semantic-attribution correction only",
        "pins": {
            "governing_prompt_sha256": GOVERNING_PROMPT_SHA256,
            "run_171_checkpoint_commit": CHECKPOINT_COMMIT,
            "run_171_checkpoint_tree": CHECKPOINT_TREE,
            "run_171_checkpoint_parent": CHECKPOINT_PARENT,
            "origin_main_before_run_172_commit": CHECKPOINT_COMMIT,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "run_171_materializer": record(RUN_171_MATERIALIZER),
            "run_171_receipt": record(RUN_171_RECEIPT),
            "run_171_receipt_self_seal_sha256": RUN_171_RECEIPT_SELF_SEAL,
            "run_171_committed_builder": committed_builder,
            "run_168_frozen_dashboard_at_run_171_checkpoint": committed_dashboard,
            "run_172_builder": record(BUILDER),
            "run_172_dashboard": record(HTML),
            "run_172_receipt_materializer": record(MATERIALIZER),
        },
        "lineage": {
            "run_168": "verifies only the exact superseded RUN-167 dashboard",
            "run_169_and_run_169r": "establish one Fleet alerts-config OWNER candidate and three provisional-not-final observations",
            "run_170_and_run_170r": "integrate and independently verify exactly one route owner and one action bridge",
            "run_171": "alone reports the current 665/308/357/96 and 119/388/97/410 static ledgers",
            "run_172": "corrects historical/current audit prose and verifies only the exact resulting audit artifact",
        },
        "dashboard_semantic_attribution_correction": {
            "initial_rebuilt_dashboard_sha256": "94b56628243821d33fefec9b96841597cc599a65018aba15008a249deafef799",
            "first_corrected_intermediate_sha256": "d1d392fe4467a39a8ab0390dda92f3f2f29fef64adb113439f102ecb4ea3b070",
            "second_corrected_intermediate_sha256": "37e318135d31a588caf32db25de58f338c878b3a0f71ae68d0ce1cede3826ac4",
            "final_dashboard_sha256": HTML_SHA256,
            "original_browser_semantic_review": "NO-GO with nine historical/current attribution categories",
            "embedded_item_6_temporal_clause_corrected": True,
            "independent_final_label_residue": "RUN-090 frozen denominator now explicitly separated from RUN-170R current accounting",
            "final_independent_semantic_review": "GO with zero discrepancies",
            "independent_reviewer_file_changes": [],
            "builder_change": builder_diff,
            "dashboard_change": dashboard_diff,
            "final_builder_runs_observed": 2,
            "final_builder_runs_byte_identical": True,
            "application_source_or_product_test_change": False,
            "credit_effect": "audit-dashboard attribution correctness and exact-artifact verification only",
        },
        "verification_method": {
            "browser": "Codex in-app browser",
            "served_from": f"temporary loopback-only PHP static server on 127.0.0.1:{LOOPBACK_PORT}",
            "target_url": BROWSER_TARGET_URL,
            "cachebuster": BROWSER_CACHEBUSTER,
            "response_status": 200,
            "response_content_type": "text/html; charset=UTF-8",
            "response_bytes": HTML_BYTES,
            "response_sha256": HTML_SHA256,
            "external_testing": False,
            "desktop_and_mobile_visual_inspection": "GO on final SHA; transient screenshots not retained",
            "viewport_override_reset_after_test": True,
            "browser_tab_closed_after_test": True,
        },
        "verification": {
            "dashboard_builder_final_byte_identical_runs_observed": 2,
            "dashboard_builder_final_runs_byte_identical": True,
            "receipt_materializer_final_byte_identical_runs_required": 2,
            "noncreditable_attempts": [
                {"attempt": "initial browser load of rebuilt SHA 94b56628", "result": "nine historical/current attribution categories exposed; no browser or exact-artifact credit retained", "counted_as_exact_artifact_evidence": False},
                {"attempt": "intermediate SHA d1d392fe static review", "result": "embedded RUN152/153 temporal leakage remained; superseded before final browser verification", "counted_as_exact_artifact_evidence": False},
                {"attempt": "intermediate SHA 37e31813 independent review", "result": "RUN090 frozen denominator/current-accounting row label remained ambiguous; no final credit retained", "counted_as_exact_artifact_evidence": False},
                {"attempt": "first ten-link batch on a superseded SHA", "result": "browser action window timed out and reset; only the later final-SHA bounded batches are credited", "counted_as_navigation_evidence": False},
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
            "navigation_clicks_required": 10,
            "navigation_clicks_passed": 10,
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
            "anchor_elements_rendered_in_browser": 791,
            "hash_anchor_occurrences": len(hash_hrefs),
            "unique_hash_anchors": len(set(hash_hrefs)),
            "missing_hash_targets": missing_anchors,
            "local_resource_link_occurrences": len(local_hrefs),
            "unique_local_resources": len(unique_local),
            "prewrite_non_forward_local_resources": f"{len(non_forward_unique)}/{len(non_forward_unique)}",
            "prewrite_forward_receipt_is_intentional_unhashed_self_link": True,
            "post_materialization_local_resources": f"{len(unique_local)}/{len(unique_local)}",
            "post_materialization_local_resource_failures": [],
            "adjacent_hash_pair_occurrences": len(hash_pairs),
            "unique_adjacent_hash_path_hash_pairs": len(set(hash_pairs)),
            "unique_adjacent_hash_paths": len({path for path, _ in hash_pairs}),
            "hash_bearing_file_occurrences_verified": hash_bearing_files,
            "unique_hash_bearing_file_paths_verified": len(hash_bearing_file_paths),
            "historical_directory_bundle_digest_paths": sorted(directory_digest_paths),
            "hash_bearing_link_failures": hash_failures,
            "run_172_forward_receipt_link_occurrences": text.count(f'href="{OUTPUT}"'),
            "run_172_forward_receipt_link_adjacent_hash_occurrences": sum(1 for href, _ in hash_pairs if href == OUTPUT),
            "visible_static_checks_required": len(visible_static),
            "visible_static_checks_passed": sum(visible_static.values()),
            "visible_static_checks": visible_static,
            "prohibited_visible_phrase_hits": prohibited_hits,
            "single_organisation_multi_site_wording_present": True,
            "tenant_word_present": False,
        },
        "reported_finding_boundary": {
            "retained_claim_records": 12,
            "current_provisional_source_claims": 9,
            "historical_already_fixed_records": 2,
            "historical_remediated_records": 1,
            "final_P0": 0,
            "final_P1": 0,
            "atomicity_claim_specific_denominator": "3 test functions / 146 assertions / 3 synchronized race subscenarios",
            "atomicity_supporting_overlap": "43 tests / 716 assertions not aggregated",
            "existing_bounded_disposition_denominator": "78 tests / 1,529 assertions",
            "residual_atomicity_compound_scope_inherited": False,
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
            "balance_check_destruction_sibling_writer_deadlock_retry_or_stress": False,
            "benchmark_mapping_or_final_no_match_NCM": False,
            "ease": False,
            "final_finding": False,
            "feature_module_or_pass_completion": False,
            "release_or_audit_completion": False,
        },
        "root_browser_resource_cleanup": {
            "temporary_loopback_port": LOOPBACK_PORT,
            "temporary_server_pid": LOOPBACK_PID,
            "listeners_after_cleanup": cleanup["listener_count"],
            "exact_pid_present_after_cleanup": cleanup["exact_pid_present"],
            "matching_php_processes_after_cleanup": cleanup["matching_process_count"],
            "browser_viewport_override_reset": True,
            "browser_tab_closed": True,
        },
        "worktree_boundary": {
            "expected_final_status_count": 4,
            "expected_final_porcelain_statuses": sorted({f"M {PREFIX}/{HTML}", f"M {PREFIX}/{BUILDER}", f"?? {PREFIX}/{MATERIALIZER}", f"?? {PREFIX}/{OUTPUT}"}),
            "exact_match_required": True,
        },
        "mutation_attestation": {
            "sequence_paths": [HTML, BUILDER, MATERIALIZER, OUTPUT],
            "receipt_materializer_persistent_write_scope": [OUTPUT],
            "application_paths_changed": [],
            "product_test_paths_changed": [],
            "findings_register_changed": False,
            "human_readable_reporting_surfaces_changed": False,
            "forms_submitted": False,
            "records_opened": False,
            "screenshots_retained": False,
            "database_changed": False,
            "application_tests_or_build_run": False,
        },
        "remote_state_boundary": {"origin_main_before_run_172_commit": CHECKPOINT_COMMIT, "push_or_publication_performed_by_materializer": False, "publication_claim": False},
        "credit_boundary": {
            "audit_dashboard_run_172_semantic_attribution_corrections": True,
            "exact_audit_dashboard_artifact": True,
            "application_source_or_tests": False,
            "application_runtime": False,
            "application_browser": False,
            "benchmark_mapping": False,
            "final_no_match_or_NCM": False,
            "ease": False,
            "pass": False,
            "release": False,
            "final_finding": False,
            "completion": False,
            "audit_complete": False,
        },
        "completion_boundary": {
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
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [f"{PREFIX}/{HTML}", f"{PREFIX}/{BUILDER}", f"{PREFIX}/{MATERIALIZER}", f"{PREFIX}/{OUTPUT}"],
    }
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    output_bytes = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")

    output_path = AUDIT / OUTPUT
    output_path.parent.mkdir(parents=True, exist_ok=True)
    temporary_path = output_path.with_name(f".{output_path.name}.tmp-run172")
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

    expected_after = {f"M {PREFIX}/{HTML}", f"M {PREFIX}/{BUILDER}", f"?? {PREFIX}/{MATERIALIZER}", f"?? {PREFIX}/{OUTPUT}"}
    observed_after = {line.lstrip() for line in git("status", "--porcelain").splitlines()}
    assert observed_after == expected_after, {"expected": sorted(expected_after), "observed": sorted(observed_after)}
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
