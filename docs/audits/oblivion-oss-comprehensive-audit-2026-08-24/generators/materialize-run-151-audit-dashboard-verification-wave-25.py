#!/usr/bin/env python3
"""Serialize bounded RUN151 verification facts for the exact RUN150 dashboard.

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
MATERIALIZER = "generators/materialize-run-151-audit-dashboard-verification-wave-25.py"
OUTPUT = "evidence/browser/current-audit-dashboard-verification-run-151-wave-25.json"
HTML = "audit-dashboard.html"
CHECKPOINT_COMMIT = "61615f34871697866f8214f86adc4c5e1efd963c"
HTML_SHA256 = "7d5556d9e94d9f7c480cbad8b5f4fd5a69990080ff4515364d0821e05ab8f56d"
HTML_BYTES = 217450
BROWSER_CACHEBUSTER = "main-61615f348-7d5556d9"
BROWSER_TARGET_URL = "http://127.0.0.1:8771/audit-dashboard.html?v=main-61615f348-7d5556d9#progress"

VIEWPORTS = [
    {"requested": "1440x900", "actual_browser_viewport": "1440x900", "observed_document_client": "1425x900", "page_level_horizontal_overflow": False, "page_overflow_px": 0, "table_wraps": 9, "tables_needing_bounded_horizontal_scroll": 0, "tables_with_unbounded_overflow": 0, "navigation_needing_bounded_horizontal_scroll": False, "unintended_offscreen_elements": 0},
    {"requested": "1280x800", "actual_browser_viewport": "1280x800", "observed_document_client": "1265x800", "page_level_horizontal_overflow": False, "page_overflow_px": 0, "table_wraps": 9, "tables_needing_bounded_horizontal_scroll": 0, "tables_with_unbounded_overflow": 0, "navigation_needing_bounded_horizontal_scroll": False, "unintended_offscreen_elements": 0},
    {"requested": "1024x768", "actual_browser_viewport": "1024x768", "observed_document_client": "1009x768", "page_level_horizontal_overflow": False, "page_overflow_px": 0, "table_wraps": 9, "tables_needing_bounded_horizontal_scroll": 1, "tables_with_unbounded_overflow": 0, "navigation_needing_bounded_horizontal_scroll": False, "unintended_offscreen_elements": 0},
    {"requested": "390x844", "actual_browser_viewport": "390x844", "observed_document_client": "375x844", "page_level_horizontal_overflow": False, "page_overflow_px": 0, "table_wraps": 9, "tables_needing_bounded_horizontal_scroll": 9, "tables_with_unbounded_overflow": 0, "navigation_needing_bounded_horizontal_scroll": True, "unintended_offscreen_elements": 0},
]

NAVIGATION = [
    ("Progress", "#progress"), ("RUN-150", "#checkpoint"), ("Pages", "#pages"),
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

    id_counts = Counter(parser.ids)
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
        "owners_663_equals_306_routes_plus_357_pages": ("663 bounded source-owner records (306 routes + 357 pages)",),
        "controller_action_bridges_94": ("plus 94 action bridges",),
        "bounded_denominator_3929_percent_16_874523_residual_3266": ("16.874523% of bounded 3,929", "3,266 residual"),
        "route_partition_3218_equals_306_plus_12_plus_5_plus_2895_with_7_tagged_gaps": ("routes 3,218 = 306 owner + 12 shared + 5 alias + 2,895 residual with 7 tagged gaps",),
        "page_partition_711_equals_357_plus_9_plus_345_with_1_tagged_gap": ("pages 711 = 357 owner + 9 shared + 345 residual with 1 tagged gap",),
        "queue_507_equals_117_reviewed_plus_390_pending": ("507 total = 117 reviewed + 390 pending",),
        "reviewed_queue_117_equals_95_plus_10_plus_5_plus_0_plus_7": ("reviewed = 95 owned + 10 shared + 5 alias + 7 gap",),
        "queue_without_ownership_412": ("412 without ownership",),
        "benchmark_mapping_2_of_340": ("target-specific mapping is 2/340",),
        "final_no_match_or_ncm_0_of_340": ("final no-match/NCM is 0/340",),
        "benchmark_unresolved_338": ("338 targets remain unresolved",),
        "mapped_feature_ids_exact": ("CAP-FIN-BILLING-INVOICE-LIFECYCLE", "CAP-FIN-FX-REVALUATION"),
        "credited_projects_exact": ("Dolibarr/dolibarr", "frappe/erpnext"),
        "bigcapital_adjacent_only_unselected": ("BigCapital adjacent-only",),
        "one_operating_organisation_multiple_sites": ("one operating organisation across multiple Sites",),
        "twelve_provisional_findings_separate_no_final_finding": ("separate from the 12 provisional finding records", "no correctness or final-finding credit"),
        "observation_mutation_capability": ("RUN148R-ASSURANCE-MUTATION-CAPABILITY", "The selected POST sits inside a read-labelled OR permission group accepting fleet.viewAny, assets.viewAny, or assets.viewAssigned; an exact daily-check mutation capability is not established.", "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING"),
        "observation_site_direct_object": ("RUN148R-ASSURANCE-SITE-DIRECT-OBJECT", "The store action validates raw assets.id existence but does not resolve a canonical vehicle through approved Sites, AssetPolicy, or a concealment-aware access service before mutation.", "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING"),
        "observation_template_day_concurrency": ("RUN148R-ASSURANCE-TEMPLATE-DAY-CONCURRENCY", "Template first-or-create and same-day run check/update-or-create are not shown under an exact template authority, transaction, lock, or database uniqueness invariant.", "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING"),
        "observation_mutation_test_coverage": ("RUN148R-ASSURANCE-MUTATION-TEST-COVERAGE", "Frozen tests cover GET rendering/compliance or a browser smoke only; no exact selected POST route-name/path mutation assertion was found or executed.", "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING"),
        "reviewer_a_exact_nonblinding_provenance": ("Reviewer A was not blinded and did not have the prior outcome visible in team status.",),
        "reviewer_b_exact_nonblinding_provenance": ("Reviewer B was not blinded and did have the prior outcome visible in team status.",),
        "reviewers_independent_no_consultation": ("Neither reviewer consulted the other and both completed independent evidence traces.",),
        "preceding_queue_index_79_not_recredited": ("Queue index 79 is not recredited",),
        "next_queue_index_81_pending": ("index 81 remains pending",),
        "selected_run077_identity": ("RUN077-ROUTE-0689", "fleet-assets.daily-check.store", "DailyCheckController::store", "CAP-FLEET-DAILY-VEHICLE-CHECK"),
        "run090_queue_identity": ("RUN-090 freezes 507 candidate rows",),
        "run147_verification_does_not_transfer": ("no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-150 dashboard",),
        "gate_4_open": ("Gate 4 is open",),
        "all_application_runtime_test_benchmark_final_finding_completion_credit_zero": ("Current-source framework reachability, runtime, browser, build, rendered visual, executed-test, ease, release, Pass, final-finding, feature-completion, and audit-completion credit remain zero.", "final no-match/NCM is 0/340"),
    }
    visible_static = {key: all(value in text for value in values) for key, values in required_text.items()}
    visible_static["non_tenant_boundary_no_tenant_product_language"] = "tenant" not in text.lower()
    lineage = {
        "run147_receipt": ("evidence/browser/current-audit-dashboard-verification-run-147-wave-24.json", "36e0595b3e90f439770c9e8aadbb01555591c79e38ffac54d3cfd6dc3b892cc0"),
        "run148_cohort_generator": ("generators/build-outcome-neutral-fleet-daily-vehicle-check-store-route-action-cohort-wave-25.py", "c8c6a9f1500fe088f6c61c3edff5351095518d14661a77af86b327a9ee253f65"),
        "run148_cohort": ("evidence/source/root-run-148-outcome-neutral-fleet-daily-vehicle-check-store-route-action-cohort-wave-25.json", "621c1794a73e232b6fc9ff8d2b81ac9ae31ea2ccfe9f038ae77afe332b3ab28d"),
        "run148r_review_generator": ("generators/materialize-independent-outcome-neutral-fleet-daily-vehicle-check-store-route-action-review-wave-25.py", "c41b37679763c0ea0eb4a08fc14368692c5b4cc0176167c4369b637c6f68f4b3"),
        "run148r_review": ("evidence/source/raw-run-148r-independent-outcome-neutral-fleet-daily-vehicle-check-store-route-action-review-wave-25.json", "6720a7570f7f0547fca222758c0632cb7514d953a20605e7c00d6ce88efc18b2"),
        "run149_overlay_generator": ("generators/integrate-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.py", "b5c7f04cd44ecd73dda9c7fe4a9e2e8616c68674cdc52d393ec696b06ad2327e"),
        "run149_overlay": ("evidence/source/current-run-149-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.json", "12a52c434ecd18a5c6a644378070aa5ab046f5e7080726b983ded8d9c7377a55"),
        "run149r_review_generator": ("generators/materialize-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25.py", "bd09980ac26a7e9d026eda518f1964f8a2a87ea75fecf271981e4017e8dcd57c"),
        "run149r_review": ("evidence/source/current-run-149r-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25.json", "545694fc1b7bd5f4af244617fb421ece1265fe6e6f2cad2ca834115e7a9e75a2"),
        "run150_reporting_generator": ("generators/materialize-run-150-reviewed-fleet-daily-vehicle-check-store-route-action-reporting-wave-25.py", "8927a0e203203a739a8c8bb3d0e04dda5f1ebe55a9915fc9fcab6a9c4a73bcc4"),
        "run150_reporting_receipt": ("evidence/source/current-run-150-reviewed-fleet-daily-vehicle-check-store-route-action-reporting-wave-25.json", "f5fd2fd59e8cdf26e30343774c7e76ede235a64cc1f6bb447b9867df2c5f30b2"),
    }
    visible_static.update({f"exact_lineage_{key}_link_and_hash": f'href="{path}"' in text and digest in text for key, (path, digest) in lineage.items()})
    assert all(visible_static.values()), [key for key, value in visible_static.items() if not value]

    receipt = {
        "schema_version": "run-151-audit-dashboard-verification-wave-25-v1",
        "run_id": "RUN-151-AUDIT-DASHBOARD-VERIFICATION-WAVE-25",
        "generated_on": "2026-08-27",
        "status": "AUDIT_ARTIFACT_RESPONSIVE_LINK_CONSOLE_VERIFICATION_GO_ZERO_APPLICATION_CREDIT",
        "architecture_rule": {"operating_organisations": 1, "multiple_sites": True, "multi_tenant": False},
        "scope": "Exact RUN150-generated audit-dashboard artifact only; not the application UI or runtime.",
        "pins": {
            "checkpoint_commit": CHECKPOINT_COMMIT,
            "checkpoint_tree": git("rev-parse", "HEAD^{tree}"),
            "dashboard_html": {"sha256": HTML_SHA256, "blob_id": blob(HTML), "bytes": HTML_BYTES},
            "dashboard_generator": {"sha256": file_sha("generators/build-current-audit-dashboard.py"), "blob_id": blob("generators/build-current-audit-dashboard.py"), "bytes": (AUDIT / "generators/build-current-audit-dashboard.py").stat().st_size},
            "run_150_reporting_receipt": {"sha256": file_sha("evidence/source/current-run-150-reviewed-fleet-daily-vehicle-check-store-route-action-reporting-wave-25.json"), "blob_id": blob("evidence/source/current-run-150-reviewed-fleet-daily-vehicle-check-store-route-action-reporting-wave-25.json")},
            "receipt_materializer": MATERIALIZER,
            "receipt_materializer_sha256": file_sha(MATERIALIZER),
            "receipt_materializer_blob_id": blob(MATERIALIZER),
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
            "navigation_links_exercised_at": "1440x900",
            "navigation_exercise_method": "semantic locators; smooth scroll allowed to settle before target checks",
            "navigation_links": [{"label": label, "target": href, "target_exists": href[1:] in id_counts, "hash_matched": True, "target_visible_after_click": True} for label, href in NAVIGATION],
            "dom_ids_observed": 11,
            "browser_injected_ids": ["codex-browser-sidebar-comments-root"],
            "duplicate_authored_ids_observed": 0,
            "console_warnings": 0,
            "console_errors": 0,
            "page_errors": 0,
            "exact_visible_static_boundary_checks": visible_static,
            "browser_only_facts_not_supplied": [],
        },
        "mutation_attestation": {
            "run_151_materializer_writes_only_receipt": True,
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
    assert {line.lstrip() for line in git("status", "--porcelain").splitlines()} == expected
    assert not list(AUDIT.rglob("__pycache__"))
    print(json.dumps({"status": receipt["status"], "dashboard_sha256": HTML_SHA256, "materializer_sha256": file_sha(MATERIALIZER), "receipt_sha256": file_sha(OUTPUT)}, indent=2))


if __name__ == "__main__":
    main()
