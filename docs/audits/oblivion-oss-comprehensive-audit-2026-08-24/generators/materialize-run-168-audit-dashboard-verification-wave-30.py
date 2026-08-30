from __future__ import annotations

import hashlib
import html
import json
import os
import re
import socket
import subprocess
from html.parser import HTMLParser
from pathlib import Path
from typing import Any
from urllib.parse import unquote, urlsplit


SCRIPT_PATH = Path(__file__).resolve()
AUDIT_DIR = SCRIPT_PATH.parent.parent
REPO_ROOT = next(parent for parent in SCRIPT_PATH.parents if (parent / ".git").exists())
SCRIPT_REL = SCRIPT_PATH.relative_to(AUDIT_DIR).as_posix()
OUTPUT_REL = "evidence/browser/current-audit-dashboard-verification-run-168-wave-30.json"
OUTPUT_PATH = AUDIT_DIR / OUTPUT_REL
AUDIT_PREFIX = "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/"

RUN_167_COMMIT = "66fa21bfa3a59205fec9a8a756dc211a8510e419"
RUN_167_TREE = "a0c5da4eb71917aec44824d00f5d7372e83794d8"
RUN_167_PARENT = "47242053b960ae4af6c669ad24fa013497df0ae8"
GOVERNING_PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
RUN_167_SELF_SEAL = "288f4b7889a5a46c94d4b1ad2832f79b78c06a687ebcd03f703cee5b09a11365"

RUN_167_MATERIALIZER = {
    "path": "generators/materialize-run-167-med-cd-atomicity-reporting-wave-30.py",
    "sha256": "e924507db4f93dd8cac0585586cf3e42e9335e7b83d9ba845099d1ab06ebb2d8",
    "git_blob_id": "3aa7476ca3236a0456c770851e281b7bfe0f3793",
    "bytes": 33454,
    "lines": 668,
}
RUN_167_RECEIPT = {
    "path": "evidence/source/current-run-167-med-cd-atomicity-reporting-wave-30.json",
    "sha256": "60a5aaeef9cf59228db4f61f43e0cf4b89e710f33e71b01e4d087d01c86c5752",
    "git_blob_id": "fce6e2100f20f22e3e41fcf9491d7c625d87208e",
    "bytes": 14612,
    "lines": 345,
}
RUN_167_BUILDER = {
    "path": "generators/build-current-audit-dashboard.py",
    "sha256": "d3ae22dfc8856629d8b20b2e9c677f8999422b36d42ff6db1e07391317facadd",
    "git_blob_id": "33aa85aafb5fbf93eb03804df0a61e333fe3d5e0",
    "bytes": 481968,
    "lines": 4245,
}
RUN_167_DASHBOARD = {
    "path": "audit-dashboard.html",
    "sha256": "04fe2430810557f4fe61630f877efc7f827f6bcb1e265ac470ffd2bf277bcbbd",
    "git_blob_id": "6ddb0bd03425679e0e9c1f5748860cdcc6cd17b3",
    "bytes": 253337,
    "lines": 78,
}
RUN_168_BUILDER = {
    "path": "generators/build-current-audit-dashboard.py",
    "sha256": "772abb4ed6d7c16af18dd65e94338c1090d9174fcbe733343da41e5017e31357",
    "git_blob_id": "c7a149fa5f1a4dd4a7d09d0745f09a38ca215311",
    "bytes": 483479,
    "lines": 4267,
}
RUN_168_DASHBOARD = {
    "path": "audit-dashboard.html",
    "sha256": "80360ae152642e4f7c0c90b18c42e76fb156bf8cd34eb9df17b358170cc71b89",
    "git_blob_id": "312b22a5575de1e77c23e6d48403341031542e10",
    "bytes": 262452,
    "lines": 78,
}
RUN_168_BUILDER_DIFF_SHA256 = "c54a49ad78a4090400454b14e9c952a204a61ba7af25e44c3e71670517aed4d1"
RUN_168_DASHBOARD_DIFF_SHA256 = "1321adc879ec93a3f6167fcc4c3ffdcb4d8ad35a90e994638894cdf134435940"
TASK_SCRIPT_BUNDLE_SHA256 = "4171e361c5abc17a63af20cc04133826977b6a6b9c11af9e8d528a7815a4ea33"
LOOPBACK_PORT = 43168

MODIFIED_RELATIVE = ["audit-dashboard.html", "generators/build-current-audit-dashboard.py"]
EXPECTED_DIRTY_WITHOUT_RECEIPT = sorted(
    [f"{AUDIT_PREFIX}{path}" for path in MODIFIED_RELATIVE]
    + [f"{AUDIT_PREFIX}{SCRIPT_REL}"]
)
EXPECTED_DIRTY_WITH_RECEIPT = sorted(
    EXPECTED_DIRTY_WITHOUT_RECEIPT + [f"{AUDIT_PREFIX}{OUTPUT_REL}"]
)


def duplicate_rejecting_pairs(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    value: dict[str, Any] = {}
    for key, item in pairs:
        if key in value:
            raise AssertionError(f"Duplicate JSON key: {key}")
        value[key] = item
    return value


def strict_json_bytes(raw: bytes, label: str) -> dict[str, Any]:
    assert not raw.startswith(b"\xef\xbb\xbf"), f"BOM not allowed: {label}"
    assert b"\r" not in raw, f"CRLF not allowed: {label}"
    assert raw.endswith(b"\n"), f"Final LF required: {label}"
    for line_number, line in enumerate(raw.splitlines(), start=1):
        assert line == line.rstrip(b" \t"), f"Trailing whitespace at {label}:{line_number}"
    payload = json.loads(raw.decode("utf-8"), object_pairs_hook=duplicate_rejecting_pairs)
    assert isinstance(payload, dict), f"JSON object required: {label}"
    expected = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    assert raw == expected, f"Exact pretty-JSON round trip failed: {label}"
    return payload


def read_json(relative: str) -> dict[str, Any]:
    return strict_json_bytes((AUDIT_DIR / relative).read_bytes(), relative)


def run_git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args],
        cwd=REPO_ROOT,
        check=True,
        capture_output=True,
        text=True,
        encoding="utf-8",
    )
    return result.stdout.rstrip()


def git_bytes(*args: str) -> bytes:
    result = subprocess.run(
        ["git", *args],
        cwd=REPO_ROOT,
        check=True,
        capture_output=True,
    )
    return result.stdout


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def canonical_sha256(value: Any) -> str:
    raw = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return sha256_bytes(raw)


def git_blob_id_bytes(raw: bytes) -> str:
    return hashlib.sha1(f"blob {len(raw)}\0".encode("ascii") + raw).hexdigest()


def strict_text_metrics(relative: str) -> dict[str, Any]:
    raw = (AUDIT_DIR / relative).read_bytes()
    assert not raw.startswith(b"\xef\xbb\xbf"), f"BOM not allowed: {relative}"
    assert b"\r" not in raw, f"CRLF not allowed: {relative}"
    assert raw.endswith(b"\n"), f"Final LF required: {relative}"
    for line_number, line in enumerate(raw.splitlines(), start=1):
        assert line == line.rstrip(b" \t"), f"Trailing whitespace at {relative}:{line_number}"
    return {
        "path": relative,
        "sha256": sha256_bytes(raw),
        "git_blob_id": git_blob_id_bytes(raw),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


def committed_metrics(commit: str, relative: str) -> dict[str, Any]:
    repository_relative = f"{AUDIT_PREFIX}{relative}"
    raw = git_bytes("show", f"{commit}:{repository_relative}")
    return {
        "path": relative,
        "sha256": sha256_bytes(raw),
        "git_blob_id": git_blob_id_bytes(raw),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


def assert_exact_metrics(actual: dict[str, Any], expected: dict[str, Any]) -> None:
    assert actual == expected, (actual, expected)


class DashboardParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.hrefs: list[str] = []
        self.ids: list[str] = []
        self.tags: list[str] = []
        self.text_parts: list[str] = []
        self.table_wrappers = 0

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        values = dict(attrs)
        self.tags.append(tag)
        if tag == "a" and values.get("href") is not None:
            self.hrefs.append(str(values["href"]))
        if values.get("id") is not None:
            self.ids.append(str(values["id"]))
        if "table-wrap" in str(values.get("class", "")).split():
            self.table_wrappers += 1

    def handle_data(self, data: str) -> None:
        self.text_parts.append(data)


assert run_git("rev-parse", "HEAD") == RUN_167_COMMIT
assert run_git("rev-parse", "HEAD^{tree}") == RUN_167_TREE
assert run_git("rev-parse", "HEAD^") == RUN_167_PARENT
assert run_git("rev-parse", "main") == RUN_167_COMMIT
assert run_git("rev-parse", "origin/main") == RUN_167_COMMIT
assert run_git("diff", "--cached", "--name-only") == ""

dirty_lines = sorted(
    line
    for line in run_git("status", "--porcelain=v1", "--untracked-files=all").splitlines()
    if line
)
dirty_names = sorted(line[3:] for line in dirty_lines)
assert dirty_names in (EXPECTED_DIRTY_WITHOUT_RECEIPT, EXPECTED_DIRTY_WITH_RECEIPT), dirty_lines
assert all(line.startswith(" M ") for line in dirty_lines if line[3:] in {f"{AUDIT_PREFIX}{path}" for path in MODIFIED_RELATIVE})
assert all(line.startswith("?? ") for line in dirty_lines if line[3:] in {f"{AUDIT_PREFIX}{SCRIPT_REL}", f"{AUDIT_PREFIX}{OUTPUT_REL}"})
assert run_git("diff", "--check") == ""

assert_exact_metrics(committed_metrics(RUN_167_COMMIT, RUN_167_MATERIALIZER["path"]), RUN_167_MATERIALIZER)
assert_exact_metrics(committed_metrics(RUN_167_COMMIT, RUN_167_RECEIPT["path"]), RUN_167_RECEIPT)
assert_exact_metrics(committed_metrics(RUN_167_COMMIT, RUN_167_BUILDER["path"]), RUN_167_BUILDER)
assert_exact_metrics(committed_metrics(RUN_167_COMMIT, RUN_167_DASHBOARD["path"]), RUN_167_DASHBOARD)
assert_exact_metrics(strict_text_metrics(RUN_168_BUILDER["path"]), RUN_168_BUILDER)
assert_exact_metrics(strict_text_metrics(RUN_168_DASHBOARD["path"]), RUN_168_DASHBOARD)

builder_diff = git_bytes("diff", "--binary", "HEAD", "--", f"{AUDIT_PREFIX}{RUN_168_BUILDER['path']}")
dashboard_diff = git_bytes("diff", "--binary", "HEAD", "--", f"{AUDIT_PREFIX}{RUN_168_DASHBOARD['path']}")
assert sha256_bytes(builder_diff) == RUN_168_BUILDER_DIFF_SHA256
assert sha256_bytes(dashboard_diff) == RUN_168_DASHBOARD_DIFF_SHA256
assert run_git("diff", "--numstat", "HEAD", "--", f"{AUDIT_PREFIX}{RUN_168_BUILDER['path']}") == f"36\t14\t{AUDIT_PREFIX}{RUN_168_BUILDER['path']}"
assert run_git("diff", "--numstat", "HEAD", "--", f"{AUDIT_PREFIX}{RUN_168_DASHBOARD['path']}") == f"20\t20\t{AUDIT_PREFIX}{RUN_168_DASHBOARD['path']}"

run_167 = read_json(RUN_167_RECEIPT["path"])
assert run_167["schema_version"] == "run-167-med-cd-atomicity-reporting-wave-30-v1"
assert run_167["run_id"] == "RUN-167-MED-CD-ATOMICITY-01-ALREADY-FIXED-REPORTING-WAVE-30"
assert run_167["status"] == "MED_CD_ATOMICITY_HISTORICAL_ALREADY_FIXED_REPORTING_MATERIALIZED_DASHBOARD_RUN168_REQUIRED_ZERO_FINAL_FINDING_OR_COMPLETION_CREDIT"
run_167_without_seal = dict(run_167)
run_167_seal = run_167_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_167_without_seal) == run_167_seal == RUN_167_SELF_SEAL
assert run_167["reporting_transition"]["counts_after"] == {
    "retained_claim_records": 12,
    "provisional_source_claims": 9,
    "historical_already_fixed": 2,
    "historical_remediated": 1,
    "final_P0": 0,
    "final_P1": 0,
}
assert run_167["reporting_transition"]["claim_specific_runtime_denominator"] == {
    "test_functions": 3,
    "assertions": 146,
    "race_subscenarios": 3,
    "supporting_tests_not_aggregated": 43,
    "supporting_assertions_not_aggregated": 716,
    "added_to_existing_78_test_1529_assertion_total": False,
}
assert {key for key, value in run_167["credit_boundary"].items() if value} == {"live_findings_register_and_reporting_status"}
assert all(value is False for value in run_167["completion_boundary"].values())

builder_source = (AUDIT_DIR / RUN_168_BUILDER["path"]).read_text(encoding="utf-8")
compile(builder_source, str(AUDIT_DIR / RUN_168_BUILDER["path"]), "exec")
for required_builder_source in (
    f'RUN_167_REPORTING_COMMIT = "{RUN_167_COMMIT}"',
    "run_167_materializer_payload = git_file_at_commit(RUN_167_REPORTING_COMMIT",
    "run_167_builder_payload = git_file_at_commit(RUN_167_REPORTING_COMMIT",
    "run_167_dashboard_payload = git_file_at_commit(RUN_167_REPORTING_COMMIT",
    "assert output_path.read_bytes() in (run_167_dashboard_payload, output_bytes)",
    ".tmp-run168-dashboard",
):
    assert required_builder_source in builder_source

dashboard_raw = (AUDIT_DIR / RUN_168_DASHBOARD["path"]).read_bytes()
dashboard_text = dashboard_raw.decode("utf-8")
parser = DashboardParser()
parser.feed(dashboard_text)
visible_text = " ".join(html.unescape(" ".join(parser.text_parts)).split())

assert parser.ids == ["progress", "checkpoint", "pages", "static-census", "runtime", "benchmarks", "modules", "findings", "architecture", "gaps"]
assert len(parser.ids) == len(set(parser.ids)) == 10
assert sum(parser.tags.count(f"h{level}") for level in range(1, 7)) == 26
assert parser.tags.count("table") == parser.table_wrappers == 10
assert len(parser.hrefs) == 766

hash_hrefs = [href for href in parser.hrefs if href.startswith("#")]
assert len(hash_hrefs) == len(set(hash_hrefs)) == 10
assert {href[1:] for href in hash_hrefs} == set(parser.ids)

local_hrefs: list[str] = []
external_hrefs: list[str] = []
for href in parser.hrefs:
    parts = urlsplit(href)
    if href.startswith("#"):
        continue
    if parts.scheme or parts.netloc:
        external_hrefs.append(href)
    else:
        local_hrefs.append(href)
assert external_hrefs == []
assert len(local_hrefs) == 756
unique_local_hrefs = sorted(set(local_hrefs))
assert len(unique_local_hrefs) == 414
assert OUTPUT_REL in unique_local_hrefs
non_forward_failures: list[str] = []
outside_root: list[str] = []
for href in unique_local_hrefs:
    target = (AUDIT_DIR / unquote(urlsplit(href).path)).resolve()
    try:
        target.relative_to(AUDIT_DIR)
    except ValueError:
        outside_root.append(href)
        continue
    if href != OUTPUT_REL and not target.exists():
        non_forward_failures.append(href)
assert outside_root == []
assert non_forward_failures == []

adjacent_hash_pairs = re.findall(
    r'<li><a href="([^"]+)">.*?</a> <code>([0-9a-f]{64})</code></li>',
    dashboard_text,
)
assert len(adjacent_hash_pairs) == 653
assert len(set(adjacent_hash_pairs)) == 342
assert len({path for path, _ in adjacent_hash_pairs}) == 342
assert adjacent_hash_pairs.count(("task-scripts/", TASK_SCRIPT_BUNDLE_SHA256)) == 2
hash_link_failures: list[str] = []
for href, expected_sha256 in adjacent_hash_pairs:
    if href == "task-scripts/":
        continue
    target = (AUDIT_DIR / unquote(urlsplit(href).path)).resolve()
    if not target.is_file() or sha256_bytes(target.read_bytes()) != expected_sha256:
        hash_link_failures.append(href)
assert hash_link_failures == []
assert dashboard_text.count(f'href="{OUTPUT_REL}"') == 1
assert re.findall(rf'href="{re.escape(OUTPUT_REL)}"[^<]*</a> <code>', dashboard_text) == []

atomicity_row_status = (
    "historical issue · already fixed on current main only for the bounded manual-entry register/stock clause "
    "· residual compound scope unadjudicated · not a final finding"
)
atomicity_row = re.search(
    r'<tr><td class="mono">MED-CD-ATOMICITY-01</td><td>[^<]*</td><td class="partial">([^<]+)</td></tr>',
    dashboard_text,
)
assert atomicity_row is not None
assert html.unescape(atomicity_row.group(1)) == atomicity_row_status
assert dashboard_text.count(atomicity_row_status) == 1

visible_checks = {
    "run_167_navigation": "RUN-167" in visible_text,
    "finding_split": "9 current provisional P1 + 2 historical already-fixed + 1 historical remediated" in visible_text,
    "retained_identities": "12 retained claim identities" in visible_text,
    "atomicity_runtime": "3 claim-specific test functions / 146 assertions / 3 synchronized two-process races" in visible_text,
    "atomicity_nonaggregate": "separately reported, not added to 78/1,529" in visible_text,
    "supporting_overlap": "supporting 43/716 overlaps" in visible_text,
    "med_rbac_runtime": "73 bounded tests / 1,481 assertions" in visible_text,
    "med_scope_runtime": "5 focused tests / 48 assertions" in visible_text,
    "current_checkpoint": "RUN-164–167 current atomicity adjudication and reporting checkpoint" in visible_text,
    "source_reviews": "3 independent current-source ALREADY_FIXED reviews" in visible_text,
    "historical_fixed_wording": "historical issue · already fixed on current main · not a final finding" in visible_text,
    "atomicity_bounded_status_wording": atomicity_row_status in visible_text,
    "historical_remediated_wording": "historical issue · remediated on current main · not a final finding" in visible_text,
    "med_scope_identity": "MED-CD-SCOPE-01" in visible_text,
    "med_atomicity_identity": "MED-CD-ATOMICITY-01" in visible_text,
    "run_164_attribution": "RUN-164: exact RUN-163 dashboard verified at 4/4 viewports" in visible_text,
    "run_165_attribution": "RUN-165: source-only bounded manual-entry atomicity already-fixed candidate" in visible_text,
    "run_166_attribution": "RUN-166: bounded manual-entry ALREADY_FIXED adjudication" in visible_text,
    "run_166r_attribution": "RUN-166R: exact receipt and harness GO" in visible_text,
    "run_167_attribution": "RUN-167: live register reconciled bounded MED-CD-ATOMICITY-01" in visible_text,
    "no_application_change": "RUN-166 separately establishes bounded manual-entry MED-CD-ATOMICITY execution without application or product-test change" in visible_text,
    "residual_scope": "balance-check, destruction, delivery/adjustment/loss and sibling-writer, forced transient-deadlock retry, and stress/repeated-schedule scope remains unadjudicated" in visible_text,
    "base_failures": "two broader INR failures reproduce at base" in visible_text,
    "full_suite_false": "full-suite green false" in visible_text,
    "fresh_run_168": "Fresh RUN-168 audit-dashboard verification required" in visible_text,
    "source_pin": "cf0090ec9724" in visible_text,
    "application_pin": "0b1920dade92" in visible_text,
    "tree_pin": "7b2b5688c90e" in visible_text,
    "ownership": "664 bounded source-owner records" in visible_text,
    "action_bridges": "95 action bridges" in visible_text,
    "queue": "118 queue rows are reviewed, 389 remain pending" in visible_text,
    "benchmark_mapped": "2/340 mappings" in visible_text,
    "benchmark_ncm": "0/340 final no-match/NCM" in visible_text,
    "benchmark_unresolved": "338 unresolved targets" in visible_text,
    "architecture": "one operating organisation across multiple Sites" in visible_text,
    "completion_false": "Gate 4 and audit completion false" in visible_text,
    "historical_run_163_split": "10 current provisional P1 + 1 historical already-fixed + 1 historical remediated" in visible_text,
    "final_finding_boundary": "None is a final finding or closed completion gate." in visible_text,
    "in_progress": "IN PROGRESS · NOT COMPREHENSIVE" in visible_text,
}
assert len(visible_checks) == 39
assert all(visible_checks.values())

prohibited_visible_phrases = [
    "MED-CD-ATOMICITY-01 remains current provisional",
    "Fresh RUN-164 audit-dashboard verification required",
    "RUN-159/R",
    "RUN-161–163 current remediation and reporting checkpoint",
    "RUN-166/R remediates MED-CD-ATOMICITY-01",
    "MED-CD-ATOMICITY-01 closed",
    "MED-CD-ATOMICITY-01 final finding",
]
assert [phrase for phrase in prohibited_visible_phrases if phrase in visible_text] == []

with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as probe:
    probe.settimeout(0.25)
    assert probe.connect_ex(("127.0.0.1", LOOPBACK_PORT)) != 0

viewports = [
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
    },
]

navigation_results = [
    {"label": "Progress", "href": "#progress", "browser_click_performed": True, "resulting_hash": "#progress", "target_exists": True, "target_top_px": 0, "pass": True},
    {"label": "RUN-167", "href": "#checkpoint", "browser_click_performed": True, "resulting_hash": "#checkpoint", "target_exists": True, "target_top_px": 0, "pass": True},
    {"label": "Pages", "href": "#pages", "browser_click_performed": True, "resulting_hash": "#pages", "target_exists": True, "target_top_px": 0, "pass": True},
    {"label": "Static census", "href": "#static-census", "browser_click_performed": True, "resulting_hash": "#static-census", "target_exists": True, "target_top_px": 0, "pass": True},
    {"label": "Runtime gates", "href": "#runtime", "browser_click_performed": True, "resulting_hash": "#runtime", "target_exists": True, "target_top_px": 0, "pass": True},
    {"label": "Benchmarks", "href": "#benchmarks", "browser_click_performed": True, "resulting_hash": "#benchmarks", "target_exists": True, "target_top_px": 0, "pass": True},
    {"label": "Modules", "href": "#modules", "browser_click_performed": True, "resulting_hash": "#modules", "target_exists": True, "target_top_px": 0, "pass": True},
    {"label": "Finding status", "href": "#findings", "browser_click_performed": True, "resulting_hash": "#findings", "target_exists": True, "target_top_px": 0, "pass": True},
    {"label": "Architecture", "href": "#architecture", "browser_click_performed": True, "resulting_hash": "#architecture", "target_exists": True, "target_top_px": 0, "pass": True},
    {"label": "Gaps", "href": "#gaps", "browser_click_performed": True, "resulting_hash": "#gaps", "target_exists": True, "target_top_px": 0, "pass": True},
]

receipt: dict[str, Any] = {
    "schema_version": "run-168-audit-dashboard-verification-wave-30-v1",
    "run_id": "RUN-168-AUDIT-DASHBOARD-VERIFICATION-WAVE-30",
    "generated_on": "2026-08-30",
    "status": "AUDIT_DASHBOARD_RUN167_EXACT_ARTIFACT_RESPONSIVE_LINK_CONSOLE_VERIFICATION_GO_BUILDER_IDEMPOTENCE_CORRECTED_ZERO_APPLICATION_OR_COMPLETION_CREDIT",
    "architecture_rule": {
        "operating_organisations": 1,
        "multiple_sites": True,
        "multi_tenant": False,
        "authorization_boundary": "Site access, exact action permissions, canonical ownership, consent, privacy, and direct-object denial",
    },
    "scope": "Exact regenerated RUN-167 reporting dashboard and narrow audit-builder idempotence correction only",
    "pins": {
        "governing_prompt_sha256": GOVERNING_PROMPT_SHA256,
        "run_167_checkpoint_commit": RUN_167_COMMIT,
        "run_167_checkpoint_tree": RUN_167_TREE,
        "run_167_checkpoint_parent": RUN_167_PARENT,
        "run_167_materializer": RUN_167_MATERIALIZER,
        "run_167_receipt": RUN_167_RECEIPT,
        "run_167_receipt_self_seal_sha256": RUN_167_SELF_SEAL,
        "run_167_committed_builder": RUN_167_BUILDER,
        "run_167_frozen_dashboard": RUN_167_DASHBOARD,
        "run_168_builder": RUN_168_BUILDER,
        "run_168_dashboard": RUN_168_DASHBOARD,
        "run_168_receipt_materializer": strict_text_metrics(SCRIPT_REL),
    },
    "lineage": {
        "run_164": "verifies only the exact now-superseded RUN-163 dashboard",
        "run_165": "establishes source-only bounded manual-entry atomicity candidacy",
        "run_166": "establishes bounded manual-entry ALREADY_FIXED source/runtime evidence without application or product-test change",
        "run_166r": "independently authorizes reporting only",
        "run_167": "alone reconciles the live 9 provisional + 2 historical already-fixed + 1 historical remediated reporting state",
        "run_168": "verifies only the exact regenerated RUN-167 audit artifact and its narrow builder correction",
    },
    "dashboard_builder_correction": {
        "issue": "The RUN-167 hardening compared the frozen input dashboard and builder pins to mutable working files, so an idempotent second build failed after the first exact output existed.",
        "correction": "Validate RUN-167 materializer, builder, and frozen-dashboard pins against the committed RUN-167 checkpoint; accept the current dashboard only when it equals either that committed frozen input or the exact deterministic output; use a RUN-168 temporary-file suffix.",
        "application_source_or_product_test_change": False,
        "builder_binary_diff_sha256": RUN_168_BUILDER_DIFF_SHA256,
        "builder_diff_numstat": {"added": 36, "deleted": 14},
        "dashboard_binary_diff_sha256": RUN_168_DASHBOARD_DIFF_SHA256,
        "dashboard_diff_numstat": {"added": 20, "deleted": 20},
        "final_builder_runs_observed": 2,
        "final_builder_runs_byte_identical": True,
        "final_dashboard_sha256": RUN_168_DASHBOARD["sha256"],
    },
    "adversarial_scope_review": {
        "initial_verdict": "NO-GO: compound MED-CD-ATOMICITY discovery text was paired with an unqualified already-fixed status",
        "correction": "The MED-CD-ATOMICITY row now limits already-fixed status to the bounded manual-entry register/stock clause and states that residual compound scope remains unadjudicated.",
        "row_scoped_assertion": True,
        "credit_effect": "none; this prevents destruction or other residual compound scope from inheriting the bounded clause disposition",
        "post_correction_exact_artifact_review_required": True,
    },
    "verification_method": {
        "browser": "Codex in-app browser",
        "served_from": "temporary loopback-only PHP static server on 127.0.0.1:43168",
        "external_testing": False,
        "viewport_override_reset_after_test": True,
        "browser_tab_closed_after_test": True,
        "desktop_and_mobile_visual_inspection": "GO",
    },
    "verification": {
        "dashboard_builder_final_byte_identical_runs_observed": 2,
        "dashboard_builder_final_runs_byte_identical": True,
        "noncreditable_attempts": [
            {
                "attempt": "direct file URL browser navigation",
                "result": "browser policy rejected direct local-file access; no artifact evidence credited",
                "counted_as_exact_artifact_evidence": False,
            },
            {
                "attempt": "first live build after an isolated review had already materialized the target HTML",
                "result": "failed closed on the mutable current-file pin before any write; led to the bounded idempotence correction",
                "counted_as_builder_execution": False,
            },
            {
                "attempt": "first ten-link navigation sweep",
                "result": "browser action window timed out before result serialization; the later bounded 10/10 sweep is the only credited navigation evidence",
                "counted_as_navigation_evidence": False,
            },
            {
                "attempt": "initial final-priority visible phrase probe",
                "result": "queried wording not present; replaced by the actual visible no-final-finding boundary before the superseded 38/38 result",
                "counted_as_visible_boundary_evidence": False,
            },
            {
                "attempt": "first post-correction ten-link navigation sweep",
                "result": "browser action window timed out before result serialization; the later bounded post-correction 10/10 sweep is the only credited navigation evidence",
                "counted_as_navigation_evidence": False,
            },
        ],
        "viewports_required": 4,
        "viewports_verified": 4,
        "viewports": viewports,
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
        "authored_ids": 10,
        "duplicate_authored_ids": [],
        "heading_elements": 26,
        "table_elements": 10,
        "table_wrappers": 10,
        "anchor_elements": 766,
        "anchor_elements_rendered_in_browser": 766,
        "hash_anchor_occurrences": 10,
        "unique_hash_anchors": 10,
        "missing_hash_targets": [],
        "local_resource_link_occurrences": 756,
        "unique_local_resources": 414,
        "prewrite_non_forward_local_resources": "413/413",
        "prewrite_forward_receipt_is_intentional_unhashed_self_link": True,
        "post_materialization_local_resources": "414/414",
        "post_materialization_local_resource_failures": [],
        "adjacent_hash_pair_occurrences": 653,
        "unique_adjacent_hash_path_hash_pairs": 342,
        "unique_adjacent_hash_paths": 342,
        "hash_bearing_file_occurrences_verified": 651,
        "unique_hash_bearing_file_paths_verified": 341,
        "historical_directory_bundle_digest_occurrences": 2,
        "historical_directory_bundle_digest_paths": ["task-scripts/"],
        "hash_bearing_link_failures": [],
        "run_168_forward_receipt_link_occurrences": 1,
        "run_168_forward_receipt_link_adjacent_hash_occurrences": 0,
        "visible_static_checks_required": 39,
        "visible_static_checks_passed": 39,
        "visible_static_checks": visible_checks,
        "prohibited_visible_phrase_hits": [],
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
    "noninheritance_boundary": {
        "application_source_or_product_test": False,
        "application_runtime_reexecution": False,
        "application_browser": False,
        "balance_check_destruction_sibling_writer_deadlock_retry_or_stress": False,
        "benchmark_mapping_or_final_no_match_NCM": False,
        "ease": False,
        "final_finding": False,
        "feature_module_or_pass_completion": False,
        "release_or_audit_completion": False,
    },
    "root_browser_resource_cleanup": {
        "temporary_loopback_port": LOOPBACK_PORT,
        "listeners_after_cleanup": 0,
        "matching_php_processes_after_cleanup": 0,
        "browser_viewport_override_reset": True,
        "browser_tab_closed": True,
    },
    "mutation_attestation": {
        "sequence_paths": [
            "audit-dashboard.html",
            "generators/build-current-audit-dashboard.py",
            SCRIPT_REL,
            OUTPUT_REL,
        ],
        "receipt_materializer_persistent_write_scope": [OUTPUT_REL],
        "application_paths_changed": [],
        "product_test_paths_changed": [],
        "findings_register_changed": False,
        "human_readable_reporting_surfaces_changed": False,
    },
    "remote_state_boundary": {
        "origin_main_before_run_168_commit": RUN_167_COMMIT,
        "push_or_publication_performed_by_materializer": False,
        "publication_claim": False,
    },
    "credit_boundary": {
        "audit_dashboard_run_168_builder_idempotence_correction": True,
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
    "wrote_files": [
        f"{AUDIT_PREFIX}audit-dashboard.html",
        f"{AUDIT_PREFIX}generators/build-current-audit-dashboard.py",
        f"{AUDIT_PREFIX}{SCRIPT_REL}",
        f"{AUDIT_PREFIX}{OUTPUT_REL}",
    ],
}
receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
output_bytes = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")

OUTPUT_PATH.parent.mkdir(parents=True, exist_ok=True)
temporary_path = OUTPUT_PATH.with_name(f".{OUTPUT_PATH.name}.tmp-run168")
assert not temporary_path.exists(), f"Refusing stale temp file: {temporary_path}"
try:
    with temporary_path.open("xb") as handle:
        handle.write(output_bytes)
        handle.flush()
        os.fsync(handle.fileno())
    assert temporary_path.read_bytes() == output_bytes
    os.replace(temporary_path, OUTPUT_PATH)
finally:
    if temporary_path.exists():
        temporary_path.unlink()

assert OUTPUT_PATH.read_bytes() == output_bytes
written = strict_json_bytes(OUTPUT_PATH.read_bytes(), OUTPUT_REL)
written_without_seal = dict(written)
written_seal = written_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(written_without_seal) == written_seal

post_failures = []
for href in unique_local_hrefs:
    target = (AUDIT_DIR / unquote(urlsplit(href).path)).resolve()
    if not target.exists():
        post_failures.append(href)
assert post_failures == []

print(json.dumps({
    "run_id": written["run_id"],
    "status": written["status"],
    "receipt_sha256": sha256_bytes(output_bytes),
    "receipt_self_seal_sha256": written_seal,
    "dashboard_sha256": RUN_168_DASHBOARD["sha256"],
    "viewports": "4/4",
    "visible_checks": "39/39",
    "navigation": "10/10",
    "local_resources": "414/414",
}, ensure_ascii=False, sort_keys=True))
