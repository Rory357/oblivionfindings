#!/usr/bin/env python3
"""Materialize the deterministic RUN-144 audit-dashboard verification receipt.

This serializes already-observed browser evidence. It does not reproduce or
upgrade the browser run, application execution, tests, benchmarks, or Gate 4.
"""
from __future__ import annotations

import csv
import hashlib
import html as html_module
from html.parser import HTMLParser
import json
import re
import subprocess
from pathlib import Path
from urllib.parse import unquote, urlsplit


ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24"
PREFIX = AUDIT.relative_to(ROOT).as_posix()
MATERIALIZER = "generators/materialize-run-144-audit-dashboard-verification-wave-23.py"
OUTPUT = "evidence/browser/current-audit-dashboard-verification-run-144-wave-23.json"
HTML = "audit-dashboard.html"
GENERATOR = "generators/build-current-audit-dashboard.py"
MATRIX = "03-feature-to-benchmark-matrix.csv"
RUN143_MATERIALIZER = "generators/materialize-run-143-reviewed-finance-site-portfolio-overview-route-action-reporting-wave-23.py"
RUN143_RECEIPT = "evidence/source/current-run-143-reviewed-finance-site-portfolio-overview-route-action-reporting-wave-23.json"
RUN140_RECEIPT = "evidence/browser/current-audit-dashboard-verification-run-140-wave-22.json"

CHECKPOINT_COMMIT = "7a63b9b23bee738a5c845b9e68e60043ab91fb1a"
CHECKPOINT_TREE = "1e07c83a32c020d2ade23cee12faadd671af432f"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
SUBTREES = {
    "app": "92c8425a7cb15a92609c69a8c2f26bbda4f178b7",
    "routes": "9b7f78510d970db64ea3a6540e8a36b8700bf272",
    "resources/js": "1671a7551c004571c48bb00c34522928e6f1f173",
    "resources/js/pages": "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e",
    "tests": "fef0122b31fdccbe2f9f805f7515666c74e2880a",
}
PINS = {
    MATRIX: ("dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390", "244b5ac7391e473fafb710cca1b9d97f1b670987"),
    GENERATOR: ("010cb3feadec7c47b02c1ee57ec88579d036bd0852c05afc0f9fb64f55d15f9d", "e73076f9dc9a76cdddbb2f13cccd99b3b0faceaf"),
    RUN143_MATERIALIZER: ("f1a2f957208f7ab20a0b7dfcf68e2c09edd33c218c792168e79cb0f55efdc719", "f420a7e3ebff1939b7b54d844579a4b19f2b2935"),
    RUN143_RECEIPT: ("dcb542f98b8ed66bddc92498bcd95cf9c68815bab3f77960ccfbe3bfc7099f21", "5e178b73edf6e978db8a7262467b1c5e202ed2f0"),
    RUN140_RECEIPT: ("1cae6bb23a9ede9bcda9cd975de07476516eeb18d6746f1aacf2653ecfe0c74f", "88ff4a6d72845c5f653e9ddcec170db0e6c646e4"),
}
HTML_SHA256 = "7c58aea6feee37e25c4f7cedf80f00c7a4396df1667066c1d41713b72836a398"
HTML_BLOB = "d9a1bf88cfaa85cb1f8bb3b490d578a4a9a02e87"
HTML_BYTES = 191748
SUPERSEDED_HTML_SHA256 = "f9f11d0ac7d70ab5829ce0e41435b185279beb5141afe701c7a578fdce59399d"
SUPERSEDED_HTML_BLOB = "3527f9197dd4dcf50c8f176cfe187bc98ee82b33"


def run(*args: str) -> bytes:
    return subprocess.run(args, cwd=ROOT, check=True, capture_output=True).stdout


def git(*args: str) -> str:
    return run("git", *args).decode("utf-8").rstrip("\r\n")


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(relative: str) -> str:
    return sha256_bytes((AUDIT / relative).read_bytes())


def blob_id(relative: str) -> str:
    return git("hash-object", "--", str(AUDIT / relative))


def strict_json(relative: str) -> dict:
    def hook(pairs: list[tuple[str, object]]) -> dict:
        result: dict = {}
        for key, value in pairs:
            assert key not in result, (relative, key)
            result[key] = value
        return result

    value = json.loads((AUDIT / relative).read_bytes(), object_pairs_hook=hook)
    assert isinstance(value, dict)
    return value


class DashboardParser(HTMLParser):
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


def is_local_link(href: str) -> bool:
    lowered = href.lower()
    return not (
        href.startswith("#")
        or href.startswith("//")
        or lowered.startswith("http://")
        or lowered.startswith("https://")
        or lowered.startswith("mailto:")
        or lowered.startswith("javascript:")
        or lowered.startswith("data:")
    )


def local_target(href: str) -> Path:
    relative = unquote(urlsplit(href).path)
    target = (AUDIT / relative).resolve()
    target.relative_to(AUDIT.resolve())
    return target


def expected_status(include_receipt: bool) -> set[str]:
    rows = {
        f" M {PREFIX}/{HTML}",
        f"?? {PREFIX}/{MATERIALIZER}",
    }
    if include_receipt:
        rows.add(f"?? {PREFIX}/{OUTPUT}")
    return rows


assert git("branch", "--show-current") == "main"
assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
for subtree, tree in SUBTREES.items():
    assert git("rev-parse", f"HEAD:{subtree}") == tree

for relative, (expected_sha, expected_blob) in PINS.items():
    assert sha256_file(relative) == expected_sha
    assert git("rev-parse", f"HEAD:{PREFIX}/{relative}") == expected_blob

assert sha256_file(HTML) == HTML_SHA256
assert blob_id(HTML) == HTML_BLOB
assert (AUDIT / HTML).stat().st_size == HTML_BYTES
assert git("rev-parse", f"HEAD:{PREFIX}/{HTML}") == SUPERSEDED_HTML_BLOB

run143 = strict_json(RUN143_RECEIPT)
assert run143["pins"]["checkpoint_commit"] == "0306670335b4ea42352cd72cab5c62d4aacc0981"
assert run143["pins"]["checkpoint_tree"] == "d1136413a5c89075f242e15405b9336026370976"
assert run143["pins"]["matrix_sha256"] == PINS[MATRIX][0]
assert run143["pins"]["dashboard_html_sha256"] == SUPERSEDED_HTML_SHA256
assert run143["artifact_completion_test_met"] is True
assert run143["audit_completion_test_met"] is False
assert run143["architecture_rule"] == {
    "operating_organisations": 1,
    "multiple_sites": True,
    "multi_tenant": False,
}

lineage = run143["pins"]["lineage"]
assert len(lineage) == 11
for relative, pin in lineage.items():
    assert sha256_file(relative) == pin["sha256"]
    assert git("rev-parse", f"HEAD:{PREFIX}/{relative}") == pin["blob_id"]

with (AUDIT / MATRIX).open(newline="", encoding="utf-8") as handle:
    matrix_rows = list(csv.DictReader(handle))
assert len(matrix_rows) == 340
assert sum(row.get("benchmark_mapping_credit", "").strip().lower() == "true" for row in matrix_rows) == 0

html_raw = (AUDIT / HTML).read_bytes()
assert html_raw.endswith(b"\n") and b"\r\n" not in html_raw and not html_raw.startswith(b"\xef\xbb\xbf")
html_text = html_raw.decode("utf-8")
parser = DashboardParser()
parser.feed(html_text)
assert parser.headings == 22
assert parser.tables == 9
assert parser.table_wraps == 9
assert len(parser.hrefs) == 587
assert len(parser.ids) == 10 and len(set(parser.ids)) == 10
hash_anchors = [href for href in parser.hrefs if href.startswith("#")]
assert len(hash_anchors) == 10 and len(set(hash_anchors)) == 10
assert all(anchor[1:] in parser.ids for anchor in hash_anchors)
local_links = [href for href in parser.hrefs if is_local_link(href)]
assert len(local_links) == 577 and len(set(local_links)) == 330
assert not re.findall(r"\$[A-Za-z_][A-Za-z0-9_]*", html_text)

receipt_preexists = (AUDIT / OUTPUT).exists()
assert set(git("status", "--porcelain").splitlines()) == expected_status(receipt_preexists)
missing_before = sorted({href for href in local_links if not local_target(href).exists()})
if receipt_preexists:
    assert missing_before == []
else:
    assert missing_before == [OUTPUT]

pair_pattern = re.compile(
    r'<a\s+[^>]*href="([^"]+)"[^>]*>.*?</a>\s*<code>([0-9a-f]{64})</code>',
    re.IGNORECASE | re.DOTALL,
)
pairs: list[tuple[str, str]] = []
for list_item in re.findall(r"<li\b[^>]*>(.*?)</li>", html_text, re.IGNORECASE | re.DOTALL):
    pairs.extend((html_module.unescape(match.group(1)), match.group(2).lower()) for match in pair_pattern.finditer(list_item))
assert len(pairs) == 485 and len(set(pairs)) == 258
directory_pairs: list[tuple[str, str]] = []
file_pairs: list[tuple[str, str]] = []
for href, expected_sha in pairs:
    target = local_target(href)
    assert target.exists()
    if target.is_dir():
        directory_pairs.append((href, expected_sha))
    else:
        assert target.is_file()
        assert sha256_bytes(target.read_bytes()) == expected_sha
        file_pairs.append((href, expected_sha))
assert len(directory_pairs) == 2 and len(set(directory_pairs)) == 1
assert set(directory_pairs) == {("task-scripts/", "4171e361c5abc17a63af20cc04133826977b6a6b9c11af9e8d528a7815a4ea33")}
assert len(file_pairs) == 483 and len(set(file_pairs)) == 257

materializer_sha = sha256_file(MATERIALIZER)
materializer_blob = blob_id(MATERIALIZER)
cachebuster = "main-7a63b9b23-7c58aea6"
target_url = f"http://127.0.0.1:8771/{HTML}?v={cachebuster}#progress"

viewports = [
    {
        "requested": "1440x900",
        "actual_browser_viewport": "1440x900",
        "observed_document_client": "1425x900",
        "response_status": 200,
        "page_level_horizontal_overflow": False,
        "page_overflow_px": 0,
        "table_wraps": 9,
        "tables_needing_bounded_horizontal_scroll": 0,
        "tables_with_unbounded_overflow": 0,
        "navigation_needing_bounded_horizontal_scroll": False,
        "unintended_offscreen_elements": 0,
    },
    {
        "requested": "1280x800",
        "actual_browser_viewport": "1280x800",
        "observed_document_client": "1265x800",
        "response_status": 200,
        "page_level_horizontal_overflow": False,
        "page_overflow_px": 0,
        "table_wraps": 9,
        "tables_needing_bounded_horizontal_scroll": 0,
        "tables_with_unbounded_overflow": 0,
        "navigation_needing_bounded_horizontal_scroll": False,
        "unintended_offscreen_elements": 0,
    },
    {
        "requested": "1024x768",
        "actual_browser_viewport": "1024x768",
        "observed_document_client": "1009x768",
        "response_status": 200,
        "page_level_horizontal_overflow": False,
        "page_overflow_px": 0,
        "table_wraps": 9,
        "tables_needing_bounded_horizontal_scroll": 1,
        "tables_with_unbounded_overflow": 0,
        "navigation_needing_bounded_horizontal_scroll": False,
        "unintended_offscreen_elements": 0,
    },
    {
        "requested": "390x844",
        "actual_browser_viewport": "390x844",
        "observed_document_client": "375x844",
        "response_status": 200,
        "page_level_horizontal_overflow": False,
        "page_overflow_px": 0,
        "table_wraps": 9,
        "tables_needing_bounded_horizontal_scroll": 9,
        "tables_with_unbounded_overflow": 0,
        "navigation_needing_bounded_horizontal_scroll": True,
        "unintended_offscreen_elements": 0,
    },
]

navigation = [
    ("Progress", "#progress"),
    ("RUN-143", "#checkpoint"),
    ("Pages", "#pages"),
    ("Static census", "#static-census"),
    ("Runtime gates", "#runtime"),
    ("Benchmarks", "#benchmarks"),
    ("Modules", "#modules"),
    ("Provisional findings", "#findings"),
    ("Architecture", "#architecture"),
    ("Gaps", "#gaps"),
]
visible_checks = {
    "current_source_owner_records_662": True,
    "current_route_page_split_305_357": True,
    "distinct_feature_ids_256_as_234_h_and_22_d": True,
    "feature_sets_64_route_242_page_overlap_50": True,
    "controller_action_bridges_93": True,
    "bounded_denominator_3929_percent_16_849071_residual_3267": True,
    "route_partition_3218_as_305_owner_12_shared_5_alias_2896_residual_with_7_tagged_gaps": True,
    "page_partition_711_as_357_owner_9_shared_345_residual_with_1_tagged_gap": True,
    "queue_partition_507_as_116_reviewed_391_pending": True,
    "reviewed_queue_116_as_94_owner_10_shared_5_alias_0_dead_7_gap": True,
    "queue_without_ownership_413": True,
    "site_portfolio_json_action_one_owner_one_bridge": True,
    "existing_page_owner_context_page_root_and_run086_row": True,
    "separate_sibling_route_run090_0041_run077_0418": True,
    "three_page_path_callers_and_zero_selected_api_frontend_callers": True,
    "excluded_neighbor_79_and_next_pending_80": True,
    "packet_expansions_24_as_6_existing_18_new": True,
    "source_locus_corrections_1": True,
    "assurance_17_inputs_9_action_3_shared_0_final": True,
    "page_sibling_caller_neighbor_next_noninheritance": True,
    "one_operating_organisation_across_multiple_sites_non_tenant": True,
    "gate_4_open_and_matrix_mapping_0_of_340": True,
    "all_application_runtime_test_benchmark_pass_finding_completion_credit_zero": True,
}
assert len(visible_checks) == 23 and all(visible_checks.values())

credit_boundary = {
    "audit_dashboard_artifact": True,
    "static_source_feature_ownership": False,
    "static_route_feature_ownership": False,
    "static_page_feature_ownership": False,
    "static_controller_action_bridge": False,
    "queue_review": False,
    "frontend_caller_ownership": False,
    "complete_route_page_feature_crosswalk": False,
    "framework_route_reachability": False,
    "application_navigation": False,
    "canonical_object_ownership_correctness": False,
    "site_authorization_correctness": False,
    "permission_correctness": False,
    "privacy_correctness": False,
    "direct_object_concealment_correctness": False,
    "query_correctness": False,
    "projection_correctness": False,
    "period_correctness": False,
    "allocation_provenance_or_reversal_correctness": False,
    "utility_true_up_sign_correctness": False,
    "response_minimization_correctness": False,
    "lifecycle_correctness": False,
    "concurrency_correctness": False,
    "event_or_downstream_durability_correctness": False,
    "application_browser": False,
    "responsive_application": False,
    "visual_or_workflow": False,
    "runtime": False,
    "database": False,
    "build": False,
    "executed_tests": False,
    "benchmark": False,
    "ease": False,
    "release": False,
    "pass": False,
    "final_finding": False,
    "completion": False,
    "audit_complete": False,
}
assert [key for key, value in credit_boundary.items() if value] == ["audit_dashboard_artifact"]

completion_boundary = {
    "framework_route_reachability_complete": False,
    "semantic_assurance_complete": False,
    "execution_complete": False,
    "benchmark_complete": False,
    "pass_8_complete": False,
    "final_reconciliation_complete": False,
    "no_live_agent_gate_complete": False,
    "full_crosswalk_complete": False,
    "gate_4_complete": False,
    "audit_complete": False,
}
assert not any(completion_boundary.values())

receipt = {
    "schema_version": "run-144-audit-dashboard-verification-wave-23-v1",
    "run_id": "RUN-144-AUDIT-DASHBOARD-VERIFICATION",
    "status": "AUDIT_ARTIFACT_RESPONSIVE_LINK_CONSOLE_VERIFICATION_GO_ZERO_APPLICATION_CREDIT",
    "generated_on": "2026-08-26",
    "pins": {
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
        "app_tree": SUBTREES["app"],
        "routes_tree": SUBTREES["routes"],
        "resources_js_tree": SUBTREES["resources/js"],
        "resources_js_pages_tree": SUBTREES["resources/js/pages"],
        "tests_tree": SUBTREES["tests"],
        "checkpoint_commit": CHECKPOINT_COMMIT,
        "checkpoint_tree": CHECKPOINT_TREE,
        "matrix_sha256": PINS[MATRIX][0],
        "matrix_blob": PINS[MATRIX][1],
        "matrix_rows": 340,
        "matrix_mapping_credit": "0/340",
        "dashboard_generator_sha256": PINS[GENERATOR][0],
        "dashboard_generator_blob": PINS[GENERATOR][1],
        "dashboard_html_sha256": HTML_SHA256,
        "dashboard_html_blob": HTML_BLOB,
        "dashboard_html_bytes": HTML_BYTES,
        "reporting_materializer_sha256": PINS[RUN143_MATERIALIZER][0],
        "reporting_materializer_blob": PINS[RUN143_MATERIALIZER][1],
        "reporting_receipt_sha256": PINS[RUN143_RECEIPT][0],
        "reporting_receipt_blob": PINS[RUN143_RECEIPT][1],
        "reviewed_overlay_lineage": lineage,
        "superseded_dashboard_verification_sha256": PINS[RUN140_RECEIPT][0],
        "superseded_dashboard_verification_blob": PINS[RUN140_RECEIPT][1],
        "superseded_dashboard_html_sha256": SUPERSEDED_HTML_SHA256,
        "superseded_dashboard_html_blob": SUPERSEDED_HTML_BLOB,
        "receipt_materializer": MATERIALIZER,
        "receipt_materializer_sha256": materializer_sha,
        "receipt_materializer_blob": materializer_blob,
    },
    "verification_method": {
        "in_app_browser": {
            "tool": "Codex in-app Browser with explicit viewport capability, semantic DOM inspection, transient visual inspection, full navigation exercise, console inspection, and local target checks",
            "target_url": target_url,
            "cachebuster": cachebuster,
            "response_probe": "Read-only local HTTP GET against the same cache-busted artifact URL",
            "response_status": 200,
            "response_bytes": HTML_BYTES,
            "response_sha256": HTML_SHA256,
            "exact_dashboard_loaded": True,
            "semantic_dom_inspection_completed": True,
            "visual_inspection_completed": True,
            "all_navigation_links_exercised": True,
            "navigation_only": True,
            "transient_screenshots_inspected": True,
            "screenshot_retained": False,
            "application_or_external_state_changed": False,
        },
        "static_validation": {
            "local_target_resolution": "Dashboard-relative filesystem target existence after this receipt was materialized",
            "hash_pair_resolution": "Immediate-sibling anchor and 64-hex code rows within one list item; every regular-file target SHA-256 matched",
            "historical_directory_hash_rows_excluded_from_file_hash_denominator": 2,
            "historical_directory_hash_unique_pairs": 1,
            "deterministic_receipt_serialization": True,
            "materializer_byte_identical_runs": 2,
            "browser_reexecution_claimed_by_materializer": False,
        },
    },
    "verification": {
        "state": "GO",
        "viewports_required": 4,
        "viewports_verified": 4,
        "viewports": viewports,
        "responsive_visual_inspection": "4/4",
        "font_loaded_at_all_viewports": True,
        "unresolved_placeholders": 0,
        "headings": 22,
        "tables": 9,
        "table_wraps": 9,
        "anchor_elements": 587,
        "hash_anchor_occurrences": 10,
        "unique_hash_anchors": 10,
        "local_link_occurrences": 577,
        "unique_local_links": "330/330",
        "local_link_failures": [],
        "pre_materialization_forward_reference": {
            "href": OUTPUT,
            "expected_missing_before_receipt_materialization": True,
            "pre_materialization_filesystem_target_exists": False,
            "excluded_from_pre_materialization_failure": True,
            "resolved_after_receipt_materialization": True,
            "hash_pair_required": False,
        },
        "adjacent_hash_rows_total": 485,
        "hash_bearing_file_link_occurrences": 483,
        "unique_hash_bearing_file_path_hash_pairs": 257,
        "hash_bearing_link_failures": [],
        "historical_directory_hash_link_occurrences": 2,
        "historical_directory_hash_unique_pairs": 1,
        "navigation_targets": "10/10",
        "missing_navigation_targets": [],
        "navigation_links_exercised": [target for _, target in navigation],
        "navigation_link_results": [
            {
                "label": label,
                "target": target,
                "target_exists": True,
                "hash_matched": True,
                "target_visible_after_click": True,
            }
            for label, target in navigation
        ],
        "dom_ids_observed": 11,
        "artifact_authored_ids": 10,
        "browser_injected_ids": ["codex-browser-sidebar-comments-root"],
        "duplicate_authored_ids": 0,
        "console_warnings": 0,
        "console_errors": 0,
        "page_errors": 0,
        "anchors": "10/10",
        "anchor_failures": [],
        "exact_visible_boundary_checks": visible_checks,
    },
    "mutation_attestation": {
        "authorized_paths": [
            f"{PREFIX}/{HTML}",
            f"{PREFIX}/{MATERIALIZER}",
            f"{PREFIX}/{OUTPUT}",
        ],
        "whole_repository_status_exactly_authorized_paths": True,
        "dashboard_generator_wrote_only_dashboard_html": True,
        "receipt_materializer_wrote_only_receipt": True,
        "matrix_changed": False,
        "reporting_surfaces_changed": False,
        "application_source_changed": False,
        "tests_changed": False,
        "build_outputs_changed": False,
        "local_static_audit_server_used": True,
        "application_runtime_started": False,
        "navigation_only": True,
        "forms_submitted": False,
        "records_opened": False,
        "screenshots_retained": False,
        "database_changed": False,
        "application_or_external_state_changed": False,
        "application_tests_or_build_run": False,
    },
    "credit_boundary": credit_boundary,
    "completion_boundary": completion_boundary,
    "artifact_completion_test_met": True,
    "audit_completion_test_met": False,
}

raw = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
target = AUDIT / OUTPUT
if not target.exists() or target.read_bytes() != raw:
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_bytes(raw)

assert target.read_bytes() == raw
assert raw.endswith(b"\n") and b"\r\n" not in raw and not raw.startswith(b"\xef\xbb\xbf")
assert strict_json(OUTPUT) == receipt
assert sorted({href for href in local_links if not local_target(href).exists()}) == []
assert set(git("status", "--porcelain").splitlines()) == expected_status(True)
assert not list(AUDIT.rglob("__pycache__"))

print(
    json.dumps(
        {
            "status": receipt["status"],
            "materializer_sha256": materializer_sha,
            "receipt_sha256": sha256_bytes(raw),
            "dashboard_sha256": HTML_SHA256,
            "viewports": "4/4",
            "navigation": "10/10",
            "local_links": "330/330",
            "visible_boundary_checks": "23/23",
            "console_warnings_errors_page_errors": 0,
            "gate_4_complete": False,
            "audit_complete": False,
        },
        indent=2,
    )
)
