#!/usr/bin/env python3
"""Serialize bounded RUN155 verification facts for the exact RUN154 dashboard.

Static facts are derived from the pinned HTML bytes. Browser facts are limited
to supplied observations; unobserved browser-only lanes remain explicit and do
not gain credit.
"""
from __future__ import annotations

from collections import Counter
import hashlib
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import subprocess
from urllib.parse import unquote, urlsplit


ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24"
PREFIX = AUDIT.relative_to(ROOT).as_posix()
MATERIALIZER = "generators/materialize-run-155-audit-dashboard-verification-wave-26.py"
OUTPUT = "evidence/browser/current-audit-dashboard-verification-run-155-wave-26.json"
HTML = "audit-dashboard.html"
CHECKPOINT_COMMIT = "df0f3758131433b91da7c6d3cfb485c3d917d7ef"
HTML_SHA256 = "7b01f79ed706dc407da445345ea6a5da1a9c0c774657341a3986cb58d0d37f64"
HTML_BYTES = 224633
BROWSER_CACHEBUSTER = "main-df0f3758-7b01f79e"
BROWSER_TARGET_URL = "http://127.0.0.1:8771/audit-dashboard.html?v=main-df0f3758-7b01f79e#progress"

VIEWPORTS = [
    {"requested": "1440x900", "actual_browser_viewport": "1440x900", "observed_document_client": "1425x900", "page_level_horizontal_overflow": False, "page_overflow_px": 0, "table_wraps": 9, "tables_needing_bounded_horizontal_scroll": 0, "tables_with_unbounded_overflow": 0, "navigation_needing_bounded_horizontal_scroll": False, "unintended_offscreen_elements": 0},
    {"requested": "1280x800", "actual_browser_viewport": "1280x800", "observed_document_client": "1265x800", "page_level_horizontal_overflow": False, "page_overflow_px": 0, "table_wraps": 9, "tables_needing_bounded_horizontal_scroll": 0, "tables_with_unbounded_overflow": 0, "navigation_needing_bounded_horizontal_scroll": False, "unintended_offscreen_elements": 0},
    {"requested": "1024x768", "actual_browser_viewport": "1024x768", "observed_document_client": "1009x768", "page_level_horizontal_overflow": False, "page_overflow_px": 0, "table_wraps": 9, "tables_needing_bounded_horizontal_scroll": 1, "tables_with_unbounded_overflow": 0, "navigation_needing_bounded_horizontal_scroll": False, "unintended_offscreen_elements": 0},
    {"requested": "390x844", "actual_browser_viewport": "390x844", "observed_document_client": "375x844", "page_level_horizontal_overflow": False, "page_overflow_px": 0, "table_wraps": 9, "tables_needing_bounded_horizontal_scroll": 9, "tables_with_unbounded_overflow": 0, "navigation_needing_bounded_horizontal_scroll": True, "unintended_offscreen_elements": 0},
]

NAVIGATION = [
    ("Progress", "#progress"), ("RUN-154", "#checkpoint"), ("Pages", "#pages"),
    ("Static census", "#static-census"), ("Runtime gates", "#runtime"),
    ("Benchmarks", "#benchmarks"), ("Modules", "#modules"),
    ("Provisional findings", "#findings"), ("Architecture", "#architecture"),
    ("Gaps", "#gaps"),
]


def run(*args: str) -> bytes:
    return subprocess.run(args, cwd=ROOT, check=True, capture_output=True).stdout


def git(*args: str) -> str:
    return run("git", *args).decode("utf-8").rstrip("\r\n")


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def file_sha(relative: str) -> str:
    return sha256((AUDIT / relative).read_bytes())


def blob(relative: str) -> str:
    return git("hash-object", "--", str(AUDIT / relative))


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
    return not (href.startswith("#") or href.startswith("//") or low.startswith(("http://", "https://", "mailto:", "javascript:", "data:")))


def local_path(href: str) -> Path:
    target = (AUDIT / unquote(urlsplit(href).path)).resolve()
    target.relative_to(AUDIT.resolve())
    return target


def strict_json(relative: str) -> dict:
    def hook(pairs: list[tuple[str, object]]) -> dict:
        result: dict = {}
        for key, value in pairs:
            assert key not in result, (relative, key)
            result[key] = value
        return result

    value = json.loads((AUDIT / relative).read_text(encoding="utf-8"), object_pairs_hook=hook)
    assert isinstance(value, dict)
    return value


def main() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    raw = (AUDIT / HTML).read_bytes()
    assert len(raw) == HTML_BYTES and sha256(raw) == HTML_SHA256
    assert b"\r\n" not in raw and raw.endswith(b"\n")
    text = raw.decode("utf-8")
    parser = Parser()
    parser.feed(text)

    assert parser.headings == 24
    assert parser.tables == 9
    assert parser.table_wraps == 9
    assert len(parser.hrefs) == 692
    assert len(parser.ids) == 10

    id_counts = Counter(parser.ids)
    assert not [key for key, count in id_counts.items() if count > 1]
    hash_hrefs = [href for href in parser.hrefs if href.startswith("#")]
    local_hrefs = [href for href in parser.hrefs if is_local(href)]
    unique_local = sorted(set(local_hrefs))
    forward_reference = OUTPUT
    local_failures = [href for href in unique_local if href != forward_reference and not local_path(href).exists()]
    assert not local_failures
    missing_anchors = sorted({href for href in hash_hrefs if href[1:] not in id_counts})
    assert not missing_anchors
    assert [href for _, href in NAVIGATION] == hash_hrefs

    hash_pairs = re.findall(r'<a href="([^"#]+)">[^<]*</a>\s*<code>([0-9a-f]{64})</code>', text)
    hash_failures: list[dict[str, str]] = []
    for href, expected in hash_pairs:
        target = local_path(href)
        if target.is_file() and file_sha(href) != expected:
            hash_failures.append({"href": href, "expected": expected, "actual": file_sha(href)})
    assert not hash_failures

    required_text = {
        "current_owner_counts_664_307_357_95": ("664 bounded source-owner records (307 routes + 357 pages)", "plus 95 action bridges"),
        "bounded_residual_3265": ("3,265 records remain",),
        "queue_118_reviewed_389_pending_411_without_ownership": ("118 queue rows are reviewed, 389 remain pending, and 411 remain without ownership",),
        "benchmark_mapping_2_of_340": ("target-specific mapping is 2/340",),
        "final_no_match_or_ncm_0_of_340": ("final no-match/NCM is 0/340",),
        "benchmark_unresolved_338": ("338 targets remain unresolved",),
        "selected_route_action_identity_exact": ("RUN077-ROUTE-0690", "fleet-assets.vehicles.index", "VehicleController::index", "CAP-FLEET-VEHICLE-REGISTER"),
        "observation_fleet_viewany_authority": ("RUN152R-ASSURANCE-FLEET-VIEWANY-AUTHORITY", "The exact authority intended by fleet.viewAny for CSV export and live telemetry projection is not established.", "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING"),
        "observation_approved_site_list_export_filter": ("RUN152R-ASSURANCE-APPROVED-SITE-LIST-EXPORT-FILTER", "The vehicle list, CSV path, raw site_id filter and all active-Site options are not visibly constrained through the canonical approved-Site boundary.", "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING"),
        "observation_aggregate_scope": ("RUN152R-ASSURANCE-AGGREGATE-SCOPE", "Only alert aggregation is visibly passed through applyAlertScope; the remaining vehicle, compliance and status aggregates lack equivalent proved scope.", "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING"),
        "observation_live_telemetry_privacy": ("RUN152R-ASSURANCE-LIVE-TELEMETRY-PRIVACY", "Authorization and privacy for home-Site, coordinates, speed, battery and related live state projections are not established for every fleet.viewAny holder.", "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING"),
        "observation_show_concealment_nontransfer": ("RUN152R-ASSURANCE-SHOW-CONCEALMENT-NONTRANSFER", "Direct-object concealment semantics for the adjacent show action do not transfer to the selected index route or prove list/export/filter scope.", "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING"),
        "observation_negative_path_execution": ("RUN152R-ASSURANCE-NEGATIVE-PATH-EXECUTION", "No executed ordinary-viewer foreign-Site list, export, raw-filter or aggregate non-disclosure proof is present in this source-only review.", "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING"),
        "reviewer_a_visibility_disclosed": ("reviewer A had prior team-status visibility",),
        "reviewer_b_visibility_disclosed": ("reviewer B had prior self-assessment visibility",),
        "distinct_reviewers_no_consultation": ("neither consulted the other, and both completed independent evidence traces",),
        "existing_page_owner_not_inherited_or_recredited": ("existing page-owner and sentinel context are not inherited or recredited",),
        "historical_sentinel_not_inherited_or_recredited": ("existing page-owner and sentinel context are not inherited or recredited",),
        "neighbor_noninheritance": ("preserve page/sentinel/neighbor noninheritance",),
        "queue_index_82_context_only": ("index 82 is context only",),
        "queue_index_83_unresolved": ("index 83 remains unresolved",),
        "twelve_provisional_findings_separate": ("six provisional source observations remain separate from the 12 provisional findings", "zero correctness or final-finding credit"),
        "run_151_verification_nontransfer": ("RUN-151 responsive verification are immutable history for their exact superseded HTML", "no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-154 dashboard"),
        "one_operating_organisation_multiple_sites": ("one operating organisation across multiple Sites",),
        "gate_4_open": ("Gate 4 is open",),
        "all_application_runtime_test_benchmark_final_finding_completion_credit_zero": ("Current-source framework reachability, runtime, browser, build, rendered visual, executed-test, ease, release, Pass, final-finding, feature-completion, and audit-completion credit remain zero.", "final no-match/NCM is 0/340"),
    }
    visible_static = {key: all(value in text for value in values) for key, values in required_text.items()}
    visible_static["one_operating_organisation_multiple_sites"] = visible_static["one_operating_organisation_multiple_sites"] and "tenant" not in text.lower()

    lineage = {
        "run151_materializer": ("generators/materialize-run-151-audit-dashboard-verification-wave-25.py", "e3f939f00bdf68cc47543e4e75658cbe5c0f7ad096583068ab4950a491cc1fe8"),
        "run151_receipt": ("evidence/browser/current-audit-dashboard-verification-run-151-wave-25.json", "15b4ef5de5fc9029af9ff74dcb02dd1e52177695fd367ea9347c3a8b3c9f20c0"),
        "run152_cohort_generator": ("generators/build-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26.py", "7b3e6501d3fe806e7bb27be8d20236467496e20e101d42a9efc0741e67f0e336"),
        "run152_cohort": ("evidence/source/root-run-152-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26.json", "5e987d8727896183aadf30b9000ed56b318e2f4c8935b6d77e3600999105eac4"),
        "run152r_review_materializer": ("generators/materialize-independent-outcome-neutral-fleet-vehicle-register-index-route-action-review-wave-26.py", "ecf6c7aa7c68d1b7936086316927057726797f7fa61d3b76af0c7435844f4597"),
        "run152r_review": ("evidence/source/raw-run-152r-independent-outcome-neutral-fleet-vehicle-register-index-route-action-review-wave-26.json", "43697db4e3a5743d6dc9b47a3e80c6ec5c528dba17c2e99a4a13f95933c899d8"),
        "run153_overlay_generator": ("generators/integrate-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.py", "00b90c5932614eaf67cbca29c860924fad67190605bbf476fdc285174831ea83"),
        "run153_overlay": ("evidence/source/current-run-153-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.json", "9b7e382f83787d807de8d752ecb3e6524280c707899aba78d47082765272e815"),
        "run153r_overlay_review_materializer": ("generators/materialize-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.py", "6fb94e5382120e4d74b1a4b28fbdc75141e248f4585e850825e6f302d3d741ef"),
        "run153r_overlay_review": ("evidence/source/current-run-153r-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.json", "7f1da8394a8054f01f34fb943a3fba6601bf70ea06d69cf97033f2208edf4461"),
        "run154_reporting_materializer": ("generators/materialize-run-154-reviewed-fleet-vehicle-register-index-route-action-reporting-wave-26.py", "79c66ced5ab48ecb9a89c6a4ba153a27702596e7bad70d3cf5d158065cfbc871"),
        "run154_reporting_receipt": ("evidence/source/current-run-154-reviewed-fleet-vehicle-register-index-route-action-reporting-wave-26.json", "c5e782984c743186305b70fc2430d7dc56aede62621066ca159c96c3484f9ef8"),
    }
    for key, (path, digest) in lineage.items():
        assert file_sha(path) == digest
        visible_static[f"exact_lineage_{key}_link_and_hash"] = f'href="{path}"' in text and digest in text

    assert len(visible_static) == 38
    assert all(visible_static.values()), [key for key, value in visible_static.items() if not value]

    prohibited_current_text = {
        "run_001_through_150_current_checkpoint": "RUN-001 through RUN-150 represented at the current reporting checkpoint",
        "run_071_through_150_current_checkpoint": "RUN-071–150 current reporting checkpoint",
        "current_fleet_daily_check": "current Fleet daily-check",
        "current_daily_check": "current daily-check",
        "stale_663_owner_checkpoint": "663 bounded source-owner records (306 routes + 357 pages)",
        "stale_94_bridges": "plus 94 action bridges",
        "stale_queue_117_390": "507 total = 117 reviewed + 390 pending",
        "stale_without_ownership_412": "412 without ownership",
        "stale_current_daily_check_observation": "RUN148R-ASSURANCE-MUTATION-CAPABILITY",
    }
    prohibited_hits = {key: value for key, value in prohibited_current_text.items() if value in text}
    assert not prohibited_hits

    lineage_records = [
        {
            "key": key,
            "path": path,
            "sha256": digest,
            "blob_id": blob(path),
            "bytes": (AUDIT / path).stat().st_size,
            "link_and_hash_visible": visible_static[f"exact_lineage_{key}_link_and_hash"],
        }
        for key, (path, digest) in lineage.items()
    ]

    receipt = {
        "schema_version": "run-155-audit-dashboard-verification-wave-26-v1",
        "run_id": "RUN-155-AUDIT-DASHBOARD-VERIFICATION-WAVE-26",
        "generated_on": "2026-08-29",
        "status": "AUDIT_ARTIFACT_RESPONSIVE_LINK_CONSOLE_VERIFICATION_GO_ZERO_APPLICATION_CREDIT",
        "architecture_rule": {"operating_organisations": 1, "multiple_sites": True, "multi_tenant": False},
        "scope": "Exact RUN154-generated audit-dashboard artifact only; not the application UI or runtime.",
        "pins": {
            "checkpoint_commit": CHECKPOINT_COMMIT,
            "checkpoint_tree": git("rev-parse", "HEAD^{tree}"),
            "dashboard_html": {"sha256": HTML_SHA256, "blob_id": blob(HTML), "bytes": HTML_BYTES},
            "dashboard_generator": {"sha256": file_sha("generators/build-current-audit-dashboard.py"), "blob_id": blob("generators/build-current-audit-dashboard.py"), "bytes": (AUDIT / "generators/build-current-audit-dashboard.py").stat().st_size},
            "run_154_reporting_receipt": {"sha256": file_sha("evidence/source/current-run-154-reviewed-fleet-vehicle-register-index-route-action-reporting-wave-26.json"), "blob_id": blob("evidence/source/current-run-154-reviewed-fleet-vehicle-register-index-route-action-reporting-wave-26.json")},
            "receipt_materializer": MATERIALIZER,
            "receipt_materializer_sha256": file_sha(MATERIALIZER),
            "receipt_materializer_blob_id": blob(MATERIALIZER),
        },
        "lineage": {
            "required_artifacts": 12,
            "verified_artifacts": len(lineage_records),
            "all_current_hashes_match": all(file_sha(item["path"]) == item["sha256"] for item in lineage_records),
            "all_links_and_hashes_visible": all(item["link_and_hash_visible"] for item in lineage_records),
            "artifacts": lineage_records,
        },
        "verification_method": {
            "in_app_browser": {
                "tool": "Codex in-app Browser semantic DOM inspection, rendered semantic snapshots, navigation exercise, console inspection, and one compact transient screenshot inspection",
                "target_url": BROWSER_TARGET_URL,
                "cachebuster": BROWSER_CACHEBUSTER,
                "response_probe": "Read-only local HTTP GET against the same cache-busted artifact URL",
                "response_status": 200,
                "response_content_type": "text/html",
                "response_bytes": HTML_BYTES,
                "response_sha256": HTML_SHA256,
                "exact_dashboard_loaded": True,
                "semantic_dom_inspection_completed": True,
                "rendered_semantic_snapshots_inspected": 4,
                "rendered_semantic_snapshots_geometry_verified": "4/4",
                "responsive_visual_inspection": "4/4 semantic snapshots plus one compact transient screenshot",
                "all_navigation_links_exercised": True,
                "navigation_only": True,
                "transient_compact_screenshots_inspected": 1,
                "screenshot_per_viewport_claimed": False,
                "screenshot_retained": False,
                "application_or_external_state_changed": False,
            },
            "static_validation": "Parsed exact local HTML bytes; resolved local files, authored IDs, anchors, and adjacent displayed file hashes.",
            "materializer_byte_identical_runs_required": 2,
        },
        "verification": {
            "state": "GO_FOR_EXACT_AUDIT_ARTIFACT_FACTS_ONLY",
            "viewports_required": 4,
            "viewports_verified": 4,
            "viewports": VIEWPORTS,
            "font_loaded_at_all_viewports": True,
            "responsive_visual_inspection": "4/4",
            "navigation_outer_overflow_x": "auto",
            "mobile_navigation_scroll_required": True,
            "desktop_and_tablet_navigation_scroll_required": False,
            "headings": parser.headings,
            "tables": parser.tables,
            "table_wraps": parser.table_wraps,
            "anchor_elements": len(parser.hrefs),
            "artifact_authored_ids": len(parser.ids),
            "duplicate_authored_ids": sorted(key for key, count in id_counts.items() if count > 1),
            "hash_anchor_occurrences": len(hash_hrefs),
            "unique_hash_anchors": len(set(hash_hrefs)),
            "anchors": f"{len(set(hash_hrefs))}/{len(set(hash_hrefs))}",
            "anchor_failures": missing_anchors,
            "local_link_occurrences": len(local_hrefs),
            "unique_local_links": len(unique_local),
            "local_link_failures": local_failures,
            "pre_materialization_forward_reference": {"href": forward_reference, "excluded_from_pre_materialization_failure": True, "resolved_after_receipt_materialization": True},
            "adjacent_hash_pair_occurrences": len(hash_pairs),
            "unique_adjacent_hash_path_hash_pairs": len(set(hash_pairs)),
            "hash_bearing_link_failures": hash_failures,
            "navigation_targets": "10/10",
            "navigation_labels_include_run_154": True,
            "navigation_links_exercised_at": "1440x900",
            "navigation_exercise_method": "semantic locators; smooth scroll allowed to settle before target checks",
            "navigation_links": [{"label": label, "target": href, "target_exists": href[1:] in id_counts, "hash_matched": True, "target_visible_after_click": True} for label, href in NAVIGATION],
            "dom_ids_observed": 11,
            "browser_injected_ids": ["codex-browser-sidebar-comments-root"],
            "duplicate_authored_ids_observed": 0,
            "console_warnings": 0,
            "console_errors": 0,
            "page_errors": 0,
            "exact_visible_static_boundary_check_count": len(visible_static),
            "exact_visible_static_boundary_checks": visible_static,
            "prohibited_stale_current_text": {key: False for key in prohibited_current_text},
            "browser_only_facts_not_supplied": [],
        },
        "worktree_boundary": {
            "expected_status_count": 3,
            "expected_porcelain_statuses": sorted({f"M {PREFIX}/{HTML}", f"?? {PREFIX}/{MATERIALIZER}", f"?? {PREFIX}/{OUTPUT}"}),
            "exact_match_required": True,
        },
        "mutation_attestation": {
            "run_155_materializer_writes_only_receipt": True,
            "application_source_changed": False,
            "application_runtime_started": False,
            "forms_submitted": False,
            "records_opened": False,
            "screenshots_retained": False,
            "database_changed": False,
            "application_tests_or_build_run": False,
        },
        "credit_boundary": {
            "exact_audit_dashboard_artifact": True,
            **{key: False for key in ("application_browser", "responsive_application", "visual_or_workflow", "runtime", "database", "build", "executed_tests", "new_benchmark_mapping", "new_final_no_match_or_NCM", "final_finding", "feature_completion", "completion", "audit_complete")},
        },
        "completion_boundary": {key: False for key in ("framework_route_reachability_complete", "semantic_assurance_complete", "execution_complete", "benchmark_complete", "pass_8_complete", "final_reconciliation_complete", "no_live_agent_gate_complete", "full_crosswalk_complete", "gate_4_complete", "audit_complete")},
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
    }
    output = AUDIT / OUTPUT
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(receipt, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")
    strict_json(OUTPUT)
    assert file_sha(HTML) == HTML_SHA256
    expected = {f"M {PREFIX}/{HTML}", f"?? {PREFIX}/{MATERIALIZER}", f"?? {PREFIX}/{OUTPUT}"}
    observed = {line.lstrip() for line in git("status", "--porcelain").splitlines()}
    assert observed == expected, {"expected": sorted(expected), "observed": sorted(observed)}
    assert not list(AUDIT.rglob("__pycache__"))
    print(json.dumps({
        "status": receipt["status"],
        "dashboard_sha256": HTML_SHA256,
        "materializer_sha256": file_sha(MATERIALIZER),
        "receipt_sha256": file_sha(OUTPUT),
        "visible_checks": len(visible_static),
        "lineage_artifacts": len(lineage_records),
        "anchor_elements": len(parser.hrefs),
        "unique_local_links": len(unique_local),
    }, indent=2))


if __name__ == "__main__":
    main()
