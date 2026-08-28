#!/usr/bin/env python3
"""Serialize bounded RUN158 verification for the exact RUN157 dashboard.

Browser facts are limited to the supplied read-only observation.  This
materializer independently validates the exact local HTML, links, anchors,
displayed hashes, lineage, and reporting receipt, then writes only its JSON
receipt.  It awards no application or downstream completion credit.
"""
from __future__ import annotations

from collections import Counter
import ast
import hashlib
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import subprocess
from typing import Any
from urllib.parse import unquote, urlsplit


ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24"
PREFIX = AUDIT.relative_to(ROOT).as_posix()

RUN_ID = "RUN-158-AUDIT-DASHBOARD-VERIFICATION-WAVE-27"
STATUS = "AUDIT_ARTIFACT_RESPONSIVE_LINK_CONSOLE_VERIFICATION_GO_ZERO_APPLICATION_CREDIT"
MATERIALIZER = "generators/materialize-run-158-audit-dashboard-verification-wave-27.py"
OUTPUT = "evidence/browser/current-audit-dashboard-verification-run-158-wave-27.json"
HTML = "audit-dashboard.html"
BUILDER = "generators/build-current-audit-dashboard.py"
RUN157_GENERATOR = "generators/materialize-run-157-reviewed-medication-governance-source-main-receipt-reporting-wave-27.py"
RUN157_RECEIPT = "evidence/source/current-run-157-reviewed-medication-governance-source-main-receipt-reporting-wave-27.json"

CHECKPOINT_COMMIT = "a8d397c91d50021015165f5d625b455a8a58c5f0"
CHECKPOINT_TREE = "acba72c7a8e9c6a11b2362c7ba2c06cababd7299"
CHECKPOINT_PARENT = "81abe37faa126f98ce47c7ca90cf569fe9c43c0d"
HTML_SHA256 = "1b0747372d70254f9761177c151fb8dba38090d4a2fae919a0ed0ee91431e2b3"
HTML_BLOB_ID = "6cec2d70cc6c4c7596e8d5f1c096b3419cbf8524"
HTML_BYTES = 235542
HTML_LINES = 77
BASELINE_HTML_SHA256 = "7b01f79ed706dc407da445345ea6a5da1a9c0c774657341a3986cb58d0d37f64"
BASELINE_HTML_BLOB_ID = "0792fcfea39c485b42039891415d0cd98bc66cd0"

BROWSER_TARGET_URL = "http://127.0.0.1:43157/audit-dashboard.html"

VIEWPORTS = [
    {
        "requested": "1440x900",
        "actual_browser_viewport": "1440x900",
        "page_level_horizontal_overflow": False,
        "page_overflow_px": 0,
        "table_wraps": 9,
        "tables_needing_bounded_horizontal_scroll": 0,
        "tables_with_unbounded_overflow": 0,
        "main_visible": True,
        "navigation_dom_resolved": True,
        "navigation_overflow_x": "auto",
        "navigation_scroll_width": 1425,
        "navigation_client_width": 1425,
        "navigation_needing_bounded_horizontal_scroll": False,
        "font_status": "loaded",
    },
    {
        "requested": "1280x800",
        "actual_browser_viewport": "1280x800",
        "page_level_horizontal_overflow": False,
        "page_overflow_px": 0,
        "table_wraps": 9,
        "tables_needing_bounded_horizontal_scroll": 0,
        "tables_with_unbounded_overflow": 0,
        "main_visible": True,
        "navigation_dom_resolved": True,
        "navigation_overflow_x": "auto",
        "navigation_scroll_width": 1265,
        "navigation_client_width": 1265,
        "navigation_needing_bounded_horizontal_scroll": False,
        "font_status": "loaded",
    },
    {
        "requested": "1024x768",
        "actual_browser_viewport": "1024x768",
        "page_level_horizontal_overflow": False,
        "page_overflow_px": 0,
        "table_wraps": 9,
        "tables_needing_bounded_horizontal_scroll": 1,
        "tables_with_unbounded_overflow": 0,
        "main_visible": True,
        "navigation_dom_resolved": True,
        "navigation_overflow_x": "auto",
        "navigation_scroll_width": 1009,
        "navigation_client_width": 1009,
        "navigation_needing_bounded_horizontal_scroll": False,
        "font_status": "loaded",
    },
    {
        "requested": "390x844",
        "actual_browser_viewport": "390x844",
        "page_level_horizontal_overflow": False,
        "page_overflow_px": 0,
        "table_wraps": 9,
        "tables_needing_bounded_horizontal_scroll": 9,
        "tables_with_unbounded_overflow": 0,
        "main_visible": True,
        "navigation_dom_resolved": True,
        "navigation_overflow_x": "auto",
        "navigation_scroll_width": 962,
        "navigation_client_width": 375,
        "navigation_needing_bounded_horizontal_scroll": True,
        "font_status": "loaded",
    },
]

NAVIGATION = [
    ("Progress", "#progress"),
    ("RUN-157", "#checkpoint"),
    ("Pages", "#pages"),
    ("Static census", "#static-census"),
    ("Runtime gates", "#runtime"),
    ("Benchmarks", "#benchmarks"),
    ("Modules", "#modules"),
    ("Provisional findings", "#findings"),
    ("Architecture", "#architecture"),
    ("Gaps", "#gaps"),
]

# Exact browser-visible/static boundary results supplied by /root after loading
# the pinned dashboard artifact and exercising its same-document navigation.
# The materializer does not repeat browser execution; the pinned HTML hash keeps
# these supplied observations bound to the exact artifact that was inspected.
EXACT_VISIBLE_STATIC_BOUNDARY_CHECKS = {
    key: True
    for key in (
        "title",
        "main_visible",
        "no_completion_claim",
        "run157_nav",
        "navigation_10",
        "run157_checkpoint_heading",
        "one_org_multiple_sites",
        "owners_664",
        "routes_307_pages_357",
        "bridges_95",
        "residual_3265",
        "queue_118_389_411",
        "benchmark_2_of_340",
        "ncm_0_of_340",
        "benchmark_338",
        "provisional_findings_12",
        "run155_history",
        "run155_viewports",
        "run155_visible_checks",
        "run155_nav_checks",
        "run155_links",
        "run155_zero_errors",
        "medication_panel",
        "historical_merge_commit",
        "effective_checkpoint",
        "two_checkpoint_359",
        "two_checkpoint_358",
        "sole_superseded_path",
        "my_day_three_paths",
        "later_audit_only",
        "non_audit_manifest",
        "run156r_commit",
        "review_disclosure",
        "root_materializer",
        "review_go_zero",
        "med_rbac",
        "med_scope",
        "med_atomicity",
        "historical_med_pin",
        "reference_only",
        "zero_semantic_outcome",
        "local_origin_only",
        "origin_sha",
        "origin_179_0",
        "origin_unverified",
        "fresh_run158_heading",
        "fresh_run158_link",
        "gate4_false",
        "footer_run157",
        "no_audit_complete_badge",
    )
}
assert len(EXACT_VISIBLE_STATIC_BOUNDARY_CHECKS) == 50
assert all(EXACT_VISIBLE_STATIC_BOUNDARY_CHECKS.values())

LINEAGE = {
    "generators/materialize-run-155-audit-dashboard-verification-wave-26.py": {
        "sha256": "1f2bd52237f28cb11f79e4fa65d1f0a82889fd313fbee08d4e222816a7147139",
        "blob_id": "8b9604e3c316be98c33bc0e1d97e2aea4f0fba9c",
        "bytes": 23854,
        "lines": 366,
    },
    "evidence/browser/current-audit-dashboard-verification-run-155-wave-26.json": {
        "sha256": "576605975af18a35be413e48e4da042e6bae706fc2438c9e7cfa89b5c9394fe3",
        "blob_id": "1f8e21521f4247f39cd23dff258909e4bbcc96ce",
        "bytes": 17688,
        "lines": 429,
    },
    "generators/materialize-run-156-medication-governance-source-main-receipt-wave-27.py": {
        "sha256": "e611f494567ce966e5c678a9579bb26278da0a87d814b649ccf973b102bcd4ea",
        "blob_id": "0caeb16bf63e0d6b4cd084c539a6d74c303d6cfb",
        "bytes": 35600,
        "lines": 779,
    },
    "evidence/source/current-run-156-medication-governance-source-main-receipt-wave-27.json": {
        "sha256": "56094f7e83acf8000d0b680d751cc3d27e8627916eef45173002b43207091e76",
        "blob_id": "38e69aa0897cc8b8f7d55363f5bc1ed491411095",
        "bytes": 16444,
        "lines": 330,
    },
    "generators/materialize-independent-medication-governance-source-main-receipt-review-wave-27.py": {
        "sha256": "fc2498be1f1e6539c1dcb898e424c47599588388522e8496f82ef70f3754b915",
        "blob_id": "451638c20d15424ac1d49cffbf3814c6696b0a2c",
        "bytes": 25584,
        "lines": 607,
    },
    "evidence/source/current-run-156r-independent-medication-governance-source-main-receipt-review-wave-27.json": {
        "sha256": "01945390f1d2c8a70dfcef6ea7327aa9f63c84f543dec5a6d8c67c7625dd032a",
        "blob_id": "1fe1f8d9d59a8729cb9c19f71a33b48d59df1e99",
        "bytes": 13268,
        "lines": 277,
    },
    RUN157_GENERATOR: {
        "sha256": "5e55f6380b8fdfce02262b328b545e6fc2a3230f028d3eac706f893d2d3720d7",
        "blob_id": "4ede57dcef28ff49806c2a58fd4773353e782001",
        "bytes": 35189,
        "lines": 822,
    },
    RUN157_RECEIPT: {
        "sha256": "c27cdeea4b41f011b3e528bcc3ce7412e7c146a4df8dd024ca0226986ad47f12",
        "blob_id": "373e536ddaa5b3120128919b370aa2963d1bf4fa",
        "bytes": 14766,
        "lines": 315,
    },
}

BUILDER_PIN = {
    "sha256": "ad8da48d9308c7b0ce2df076e44f8d748d6e47132b9b02f4d98449434b8851f7",
    "blob_id": "2059768eed7430630063e3daa3213e942540ab6b",
    "bytes": 364303,
    "lines": 3145,
}

MED_RECORD_HASHES = {
    "MED-RBAC-01": "aa35c543ac25d15d074b344abd6ce8750975717f6c6e229d36986256c5a301ea",
    "MED-CD-SCOPE-01": "dd86bf94f3b4d894e95c56c95a9409ce803b8d82d108cdd3c42f3343e348cd21",
    "MED-CD-ATOMICITY-01": "9ba4f430ee59efea414b42a8633c1c969a2fd4428fbf3fef173fb5548cc8e7f1",
}


def run_git(*args: str, check: bool = True) -> subprocess.CompletedProcess[bytes]:
    return subprocess.run(
        ["git", *args], cwd=ROOT, check=check, capture_output=True
    )


def git(*args: str) -> str:
    return run_git(*args).stdout.decode("utf-8").rstrip("\r\n")


def git_lines(*args: str) -> list[str]:
    value = git(*args)
    return [] if not value else value.splitlines()


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def canonical_sha256(value: Any) -> str:
    return sha256(
        json.dumps(
            value, ensure_ascii=False, sort_keys=True, separators=(",", ":")
        ).encode("utf-8")
    )


def strict_json_bytes(raw: bytes, label: str) -> dict[str, Any]:
    def hook(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, (label, key)
            result[key] = value
        return result

    value = json.loads(raw, object_pairs_hook=hook)
    assert isinstance(value, dict), label
    return value


def strict_json(relative: str) -> dict[str, Any]:
    return strict_json_bytes((AUDIT / relative).read_bytes(), relative)


def assert_lf(path: Path) -> bytes:
    raw = path.read_bytes()
    assert raw.endswith(b"\n") and b"\r\n" not in raw, path
    assert not raw.startswith(b"\xef\xbb\xbf"), path
    assert all(line.rstrip(b" \t") == line for line in raw.splitlines()), path
    return raw


def file_record(relative: str) -> dict[str, Any]:
    raw = assert_lf(AUDIT / relative)
    return {
        "sha256": sha256(raw),
        "blob_id": git("hash-object", "--", str(AUDIT / relative)),
        "bytes": len(raw),
        "lines": len(raw.decode("utf-8").splitlines()),
    }


def expected_status(include_receipt: bool) -> set[str]:
    result = {
        f" M {PREFIX}/{HTML}",
        f"?? {PREFIX}/{MATERIALIZER}",
    }
    if include_receipt:
        result.add(f"?? {PREFIX}/{OUTPUT}")
    return result


def status_lines() -> set[str]:
    return set(git_lines("status", "--porcelain=v1", "--untracked-files=all"))


def validate_prewrite_status() -> None:
    current = status_lines()
    assert current in (
        expected_status(include_receipt=False),
        expected_status(include_receipt=True),
    ), sorted(current)
    assert not list(AUDIT.rglob("__pycache__"))


class Parser(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.hrefs: list[str] = []
        self.ids: list[str] = []
        self.text_chunks: list[str] = []
        self.headings = 0
        self.tables = 0
        self.table_wraps = 0

    def handle_starttag(
        self, tag: str, attrs: list[tuple[str, str | None]]
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

    def handle_data(self, data: str) -> None:
        normalized = " ".join(data.split())
        if normalized:
            self.text_chunks.append(normalized)


def is_local(href: str) -> bool:
    low = href.lower()
    return not (
        href.startswith("#")
        or href.startswith("//")
        or low.startswith(
            ("http://", "https://", "mailto:", "javascript:", "data:")
        )
    )


def local_path(href: str) -> Path:
    target = (AUDIT / unquote(urlsplit(href).path)).resolve()
    target.relative_to(AUDIT.resolve())
    return target


def validate_checkpoint_and_inputs() -> dict[str, Any]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
    assert git("rev-parse", "HEAD^") == CHECKPOINT_PARENT

    repository_html = f"{PREFIX}/{HTML}"
    baseline = run_git("show", f"HEAD:{repository_html}").stdout
    assert sha256(baseline) == BASELINE_HTML_SHA256
    assert git("rev-parse", f"HEAD:{repository_html}") == BASELINE_HTML_BLOB_ID
    assert len(baseline) == 224633
    assert len(baseline.decode("utf-8").splitlines()) == 76

    html_record = file_record(HTML)
    assert html_record == {
        "sha256": HTML_SHA256,
        "blob_id": HTML_BLOB_ID,
        "bytes": HTML_BYTES,
        "lines": HTML_LINES,
    }
    assert git_lines("diff", "--name-only", "HEAD", "--") == [repository_html]
    diff_check = run_git("diff", "--check", "HEAD", "--", repository_html, check=False)
    assert diff_check.returncode == 0
    assert diff_check.stdout == b"" and diff_check.stderr == b""

    assert file_record(BUILDER) == BUILDER_PIN
    assert git("rev-parse", f"HEAD:{PREFIX}/{BUILDER}") == BUILDER_PIN["blob_id"]
    ast.parse((AUDIT / BUILDER).read_text(encoding="utf-8"))
    ast.parse((AUDIT / MATERIALIZER).read_text(encoding="utf-8"))

    for relative, expected in LINEAGE.items():
        assert file_record(relative) == expected, relative
        assert git("rev-parse", f"HEAD:{PREFIX}/{relative}") == expected["blob_id"]

    run157 = strict_json(RUN157_RECEIPT)
    assert run157["schema_version"] == (
        "run-157-reviewed-medication-governance-source-main-receipt-reporting-wave-27-v1"
    )
    assert run157["run_id"] == (
        "RUN-157-REVIEWED-MEDICATION-GOVERNANCE-SOURCE-MAIN-RECEIPT-"
        "REPORTING-WAVE-27"
    )
    assert run157["status"] == (
        "REPORTING_MATERIALIZED_REVIEWED_MEDICATION_SOURCE_RECEIPT_TWO_CHECKPOINT_"
        "358_OF_359_THREE_PROVISIONAL_REFERENCES_ZERO_REMEDIATION_FINDING_OR_"
        "COMPLETION_CREDIT"
    )
    seal = run157["receipt_self_seal_sha256"]
    assert seal == canonical_sha256(
        {key: value for key, value in run157.items() if key != "receipt_self_seal_sha256"}
    )
    assert run157["pins"]["materializer"]["sha256"] == LINEAGE[RUN157_GENERATOR]["sha256"]
    assert run157["reporting_boundary"]["fresh_run_158_dashboard_verification_required"] is True
    assert run157["reporting_boundary"]["dashboard_html_changed"] is False
    assert [key for key, value in run157["credit_boundary"].items() if value] == [
        "REPORTING_REFRESH_FOR_REVIEWED_MEDICATION_SOURCE_RECEIPT"
    ]
    assert all(value is False for value in run157["completion_boundary"].values())
    return run157


def supplemental_static_checks(visible_text: str) -> dict[str, bool]:
    required: dict[str, tuple[str, ...]] = {
        "current_owner_counts_664_307_357_95": (
            "664/307/357/95 Fleet owner/route/page/bridge counts",
        ),
        "bounded_residual_3265": ("3,265 records remain",),
        "queue_118_389_411": (
            "118 reviewed and 389 pending queue rows",
            "411 rows without ownership",
        ),
        "benchmark_mapping_2_of_340": ("2/340 mappings",),
        "final_ncm_0_of_340": ("0/340 final no-match/NCM",),
        "benchmark_unresolved_338": ("338 unresolved targets",),
        "twelve_provisional_findings": ("12 provisional findings",),
        "one_org_multiple_sites": ("one operating organisation across multiple Sites",),
        "gate_4_open": ("Gate 4 and audit completion are false",),
        "all_downstream_credit_zero": (
            "all other application/runtime/browser/test/coverage/medication-correctness/origin-currency/publication/Pass/finding/completion boundaries remain unchanged",
        ),
        "run155_exact_superseded_artifact": (
            "RUN-155 verifies only the exact now-superseded RUN-154 audit-dashboard artifact",
        ),
        "run155_proof_nontransfer": (
            "None of that proof transfers to the RUN-157 dashboard or the application",
        ),
        "run156_two_checkpoint_boundary": (
            "RUN-156 separates the 359-path historical first-parent merge payload",
            "effective application checkpoint",
        ),
        "historical_merge_cd5": (
            "cd5d34e6b8aa7e494808745041ec1dfa187dc101",
        ),
        "first_parent_359_paths": ("359-path historical first-parent merge payload",),
        "historical_87_added_272_modified": ("87 added", "272 modified"),
        "effective_checkpoint_c5": (
            "c5c0ad0903d2e2e2229d5d0090fc0a69a2206f0f",
        ),
        "effective_358_unchanged": ("358 payload blobs remain unchanged",),
        "effective_one_superseded": ("exactly resources/js/pages/my-day/index.tsx is superseded",),
        "superseded_my_day_index_path": ("resources/js/pages/my-day/index.tsx",),
        "complete_three_path_my_day_delta": (
            "complete post-merge My Day delta is three modified paths",
        ),
        "three_later_audit_only_commits": (
            "three later commits",
            "audit-root-only",
        ),
        "run156_checkpoint_86b": (
            "86b232cb14967c63ff345ac5208ec6d4c379f24f",
        ),
        "non_audit_manifest_12784": ("12,784-entry non-audit manifest",),
        "run156_zero_outcome_credit": (
            "zero final-finding/completion credit",
            "performs no semantic adjudication",
        ),
        "run156r_commit_81abe": (
            "81abe37faa126f98ce47c7ca90cf569fe9c43c0d",
        ),
        "three_distinct_review_lanes": ("Three distinct coordinated review lanes",),
        "cross_reviewer_coordination": ("coordinated review lanes",),
        "not_blind_or_isolated": ("not blind or isolated",),
        "root_record_materializer": ("single /root record materializer disclosed",),
        "review_go_zero_discrepancies": ("return GO with zero discrepancies",),
        "reporting_only_authorization": ("authorize reporting only",),
        "med_rbac_id": ("MED-RBAC-01",),
        "med_rbac_hash": (MED_RECORD_HASHES["MED-RBAC-01"],),
        "med_scope_id": ("MED-CD-SCOPE-01",),
        "med_scope_hash": (MED_RECORD_HASHES["MED-CD-SCOPE-01"],),
        "med_atomicity_id": ("MED-CD-ATOMICITY-01",),
        "med_atomicity_hash": (MED_RECORD_HASHES["MED-CD-ATOMICITY-01"],),
        "historical_a049_pin": ("a0493442b9e392d324055c35bf25b69421dc2d35",),
        "reference_only_provisional": ("reference-only provisional claims",),
        "no_semantic_adjudication_or_rebase": ("no semantic adjudication, rebase",),
        "no_promotion_remediation_verification_closure": (
            "promotion, remediation, verification, closure",
        ),
        "origin_local_tracking_only": ("unfetched local remote-tracking observation only",),
        "origin_main_20ad": (
            "origin/main",
            "20ad5cef0aacb3d055e685d2f8b7b583cb8d78f4",
        ),
        "producer_checkpoint_ahead179_behind0": ("179 ahead and 0 behind",),
        "fetch_unverified": ("fetch, remote currency, publication, and push remain unverified",),
        "remote_currency_unverified": (
            "fetch, remote currency, publication, and push remain unverified",
        ),
        "publication_push_unverified": (
            "fetch, remote currency, publication, and push remain unverified",
        ),
        "run157_counts_unchanged_reporting_only": (
            "RUN-157 reports only this bounded receipt class",
            "12 provisional findings remain unchanged",
        ),
        "fresh_run158_required": (
            "rebuilt dashboard requires fresh RUN-158 verification",
        ),
    }
    assert len(required) == 50
    checks = {
        key: all(token in visible_text for token in tokens)
        for key, tokens in required.items()
    }
    assert all(checks.values()), [key for key, value in checks.items() if not value]
    return checks


def parse_and_validate_html() -> tuple[
    Parser,
    list[str],
    list[str],
    list[dict[str, str]],
    list[dict[str, Any]],
    dict[str, bool],
]:
    raw = assert_lf(AUDIT / HTML)
    assert len(raw) == HTML_BYTES and sha256(raw) == HTML_SHA256
    text = raw.decode("utf-8")
    parser = Parser()
    parser.feed(text)
    assert parser.headings == 25
    assert parser.tables == 9
    assert parser.table_wraps == 9
    assert len(parser.hrefs) == 709
    assert len(parser.ids) == 10

    id_counts = Counter(parser.ids)
    assert not [key for key, count in id_counts.items() if count > 1]
    hash_hrefs = [href for href in parser.hrefs if href.startswith("#")]
    local_hrefs = [href for href in parser.hrefs if is_local(href)]
    unique_local = sorted(set(local_hrefs))
    assert len(hash_hrefs) == len(set(hash_hrefs)) == 10
    assert len(local_hrefs) == 699
    assert len(unique_local) == 387
    assert [href for _, href in NAVIGATION] == hash_hrefs
    missing_anchors = sorted({href for href in hash_hrefs if href[1:] not in id_counts})
    assert not missing_anchors

    missing_local = [href for href in unique_local if not local_path(href).exists()]
    assert missing_local in ([], [OUTPUT]), missing_local

    hash_pairs = re.findall(
        r'<a href="([^"#]+)">[^<]*</a>\s*<code>([0-9a-f]{64})</code>', text
    )
    assert len(hash_pairs) == 599
    assert len(set(hash_pairs)) == 315
    assert len({href for href, _ in hash_pairs}) == 315
    hash_failures: list[dict[str, str]] = []
    for href, expected in hash_pairs:
        target = local_path(href)
        if target.is_file():
            actual = sha256(target.read_bytes())
            if actual != expected:
                hash_failures.append(
                    {"href": href, "expected": expected, "actual": actual}
                )
    assert not hash_failures

    lineage_records: list[dict[str, Any]] = []
    for path, expected in LINEAGE.items():
        link_visible = f'href="{path}"' in text
        hash_visible = expected["sha256"] in text
        assert link_visible and hash_visible, path
        lineage_records.append(
            {
                "path": path,
                **expected,
                "link_visible": link_visible,
                "hash_visible": hash_visible,
            }
        )

    visible_text = " ".join(parser.text_chunks)
    checks = supplemental_static_checks(visible_text)
    assert "tenant" not in visible_text.lower()
    prohibited_current = {
        "run_071_through_154_current_checkpoint": "RUN-071–154 current reporting checkpoint",
        "run_071_through_156_current_checkpoint": "RUN-071–156 current reporting checkpoint",
        "stale_run154_navigation": "RUN-154 Progress Pages Static census",
        "stale_run155_verification_required": "Fresh RUN-155 audit-dashboard verification required",
        "stale_663_owner_checkpoint": "663 bounded source-owner records (306 routes + 357 pages)",
        "stale_94_bridges": "plus 94 action bridges",
        "stale_queue_117_390": "507 total = 117 reviewed + 390 pending",
        "stale_without_ownership_412": "412 without ownership",
        "all_359_claimed_effective": "all 359 merge payload blobs remain effective",
        "medication_records_finalized": "three finalized medication findings",
    }
    hits = {key: token for key, token in prohibited_current.items() if token in visible_text}
    assert not hits
    return parser, unique_local, missing_local, hash_failures, lineage_records, checks


def build_receipt(
    parser: Parser,
    unique_local: list[str],
    missing_local: list[str],
    hash_failures: list[dict[str, str]],
    lineage_records: list[dict[str, Any]],
    checks: dict[str, bool],
    run157: dict[str, Any],
) -> dict[str, Any]:
    id_counts = Counter(parser.ids)
    hash_hrefs = [href for href in parser.hrefs if href.startswith("#")]
    local_hrefs = [href for href in parser.hrefs if is_local(href)]
    text = (AUDIT / HTML).read_text(encoding="utf-8")
    hash_pairs = re.findall(
        r'<a href="([^"#]+)">[^<]*</a>\s*<code>([0-9a-f]{64})</code>', text
    )
    materializer_record = file_record(MATERIALIZER)
    false_credit = (
        "application_browser",
        "responsive_application",
        "visual_or_workflow",
        "application_source_mutation",
        "medication_semantic_adjudication",
        "remediation_or_defect_closure",
        "runtime",
        "database",
        "build",
        "executed_tests",
        "test_coverage",
        "new_benchmark_mapping",
        "new_final_no_match_or_NCM",
        "finding",
        "final_finding",
        "priority_promotion",
        "origin_currency_correctness",
        "origin_currency_coverage",
        "remote_currency",
        "publication_or_push",
        "ease",
        "release",
        "pass",
        "feature_completion",
        "completion",
        "gate_4",
        "audit_complete",
    )
    receipt: dict[str, Any] = {
        "schema_version": "run-158-audit-dashboard-verification-wave-27-v1",
        "run_id": RUN_ID,
        "generated_on": "2026-08-29",
        "status": STATUS,
        "architecture_rule": {
            "operating_organisations": 1,
            "multiple_sites": True,
            "multi_tenant": False,
            "authorization_boundary": (
                "APPROVED_SITES_ROLES_PERMISSIONS_CANONICAL_OWNERSHIP_"
                "DIRECT_OBJECT_CONCEALMENT_PRIVACY"
            ),
        },
        "scope": (
            "Exact RUN157-generated audit-dashboard artifact only; not the "
            "application UI, medication runtime, or application execution."
        ),
        "pins": {
            "checkpoint_commit": CHECKPOINT_COMMIT,
            "checkpoint_tree": CHECKPOINT_TREE,
            "checkpoint_parent": CHECKPOINT_PARENT,
            "baseline_dashboard_html": {
                "sha256": BASELINE_HTML_SHA256,
                "blob_id": BASELINE_HTML_BLOB_ID,
                "bytes": 224633,
                "lines": 76,
            },
            "dashboard_html": {
                "sha256": HTML_SHA256,
                "blob_id": HTML_BLOB_ID,
                "bytes": HTML_BYTES,
                "lines": HTML_LINES,
            },
            "dashboard_generator": {"path": BUILDER, **BUILDER_PIN},
            "run_157_materializer": {
                "path": RUN157_GENERATOR,
                **LINEAGE[RUN157_GENERATOR],
            },
            "run_157_receipt": {
                "path": RUN157_RECEIPT,
                **LINEAGE[RUN157_RECEIPT],
                "receipt_self_seal_sha256": run157["receipt_self_seal_sha256"],
            },
            "receipt_materializer": {
                "path": MATERIALIZER,
                **materializer_record,
            },
        },
        "lineage": {
            "required_artifacts": 8,
            "verified_artifacts": len(lineage_records),
            "all_current_hashes_match": True,
            "all_links_and_hashes_visible": True,
            "artifacts": lineage_records,
        },
        "verification_method": {
            "in_app_browser": {
                "facts_supplied_by_root": True,
                "target_url": BROWSER_TARGET_URL,
                "cachebuster_used": False,
                "cachebuster": None,
                "response_probe": "Read-only local HTTP GET against the exact artifact URL",
                "response_status": 200,
                "response_content_type": "text/html",
                "response_bytes": HTML_BYTES,
                "response_sha256": HTML_SHA256,
                "exact_dashboard_loaded": True,
                "four_viewports_observed": True,
                "all_navigation_links_actually_clicked": True,
                "navigation_only": True,
                "application_or_external_state_changed": False,
            },
            "static_validation": (
                "Parsed exact local HTML bytes; resolved local resources, authored "
                "IDs, anchors, adjacent displayed hashes, and committed lineage."
            ),
            "materializer_browser_execution_performed": False,
            "materializer_byte_identical_runs_required": 2,
        },
        "verification": {
            "state": "GO_FOR_EXACT_AUDIT_ARTIFACT_FACTS_ONLY",
            "viewports_required": 4,
            "viewports_verified": 4,
            "viewports": VIEWPORTS,
            "font_loaded_at_all_viewports": True,
            "main_visible_at_all_viewports": True,
            "navigation_dom_resolved_at_all_viewports": True,
            "navigation_outer_overflow_x": "auto",
            "mobile_navigation_scroll_required": True,
            "desktop_and_tablet_navigation_scroll_required": False,
            "headings": parser.headings,
            "tables": parser.tables,
            "table_wraps": parser.table_wraps,
            "anchor_elements": len(parser.hrefs),
            "artifact_authored_ids": len(parser.ids),
            "dom_ids_observed": 11,
            "browser_injected_ids": ["codex-browser-sidebar-comments-root"],
            "duplicate_authored_ids": sorted(
                key for key, count in id_counts.items() if count > 1
            ),
            "duplicate_authored_ids_observed": 0,
            "hash_anchor_occurrences": len(hash_hrefs),
            "unique_hash_anchors": len(set(hash_hrefs)),
            "anchors": "10/10",
            "anchor_failures": [],
            "local_resource_link_occurrences": len(local_hrefs),
            "unique_local_resources": len(unique_local),
            "pre_materialization_forward_reference": {
                "href": OUTPUT,
                "missing_before_first_materialization": True,
                "prewrite_state_accepts_existing_receipt_on_rerun": True,
                "excluded_from_pre_materialization_failure": True,
                "resolved_after_receipt_materialization": True,
            },
            "post_materialization_local_resources": "387/387",
            "post_materialization_local_resource_failures": [],
            "adjacent_hash_pair_occurrences": len(hash_pairs),
            "unique_adjacent_hash_path_hash_pairs": len(set(hash_pairs)),
            "unique_adjacent_hash_paths": len({href for href, _ in hash_pairs}),
            "hash_bearing_link_failures": hash_failures,
            "navigation_targets": "10/10",
            "navigation_labels_include_run_157": True,
            "navigation_exercise_method": "actual browser clicks against same-document anchors",
            "navigation_links": [
                {
                    "label": label,
                    "target": href,
                    "target_exists": href[1:] in id_counts,
                    "hash_matched": True,
                    "target_visible_after_click": True,
                }
                for label, href in NAVIGATION
            ],
            "console_log_entries": 0,
            "console_warnings": 0,
            "console_errors": 0,
            "page_errors": 0,
            "exact_visible_static_boundary_check_count": len(
                EXACT_VISIBLE_STATIC_BOUNDARY_CHECKS
            ),
            "exact_visible_static_boundary_checks": dict(
                EXACT_VISIBLE_STATIC_BOUNDARY_CHECKS
            ),
            "supplemental_static_boundary_check_count": len(checks),
            "supplemental_static_boundary_checks": checks,
            "browser_only_facts_not_supplied": [],
        },
        "worktree_boundary": {
            "expected_status_count": 3,
            "expected_porcelain_statuses": sorted(
                line.lstrip() for line in expected_status(include_receipt=True)
            ),
            "exact_match_required": True,
        },
        "mutation_attestation": {
            "run_158_change_set_is_exactly_html_generator_and_receipt": True,
            "run_158_materializer_writes_only_receipt": True,
            "dashboard_html_pre_materialized": True,
            "dashboard_builder_changed_by_run_158": False,
            "application_source_changed": False,
            "application_runtime_started_by_materializer": False,
            "browser_started_by_materializer": False,
            "forms_submitted": False,
            "records_opened": False,
            "screenshots_retained": False,
            "database_changed": False,
            "application_tests_or_build_run_by_materializer": False,
        },
        "credit_boundary": {
            "exact_audit_dashboard_artifact": True,
            **{key: False for key in false_credit},
        },
        "completion_boundary": {
            key: False
            for key in (
                "framework_route_reachability_complete",
                "semantic_assurance_complete",
                "execution_complete",
                "coverage_complete",
                "benchmark_complete",
                "pass_8_complete",
                "final_reconciliation_complete",
                "no_live_agent_gate_complete",
                "full_crosswalk_complete",
                "gate_4_complete",
                "audit_complete",
            )
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            f"{PREFIX}/{HTML}",
            f"{PREFIX}/{MATERIALIZER}",
            f"{PREFIX}/{OUTPUT}",
        ],
    }
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    return receipt


def validate_output(receipt: dict[str, Any]) -> None:
    raw = assert_lf(AUDIT / OUTPUT)
    actual = strict_json_bytes(raw, OUTPUT)
    assert actual == receipt
    assert actual["receipt_self_seal_sha256"] == canonical_sha256(
        {
            key: value
            for key, value in actual.items()
            if key != "receipt_self_seal_sha256"
        }
    )
    assert [key for key, value in actual["credit_boundary"].items() if value] == [
        "exact_audit_dashboard_artifact"
    ]
    assert all(value is False for value in actual["completion_boundary"].values())
    assert all(local_path(href).exists() for href in sorted(set(
        href
        for href in ParserHrefReader.read(AUDIT / HTML)
        if is_local(href)
    )))
    assert status_lines() == expected_status(include_receipt=True)
    assert not list(AUDIT.rglob("__pycache__"))


class ParserHrefReader(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.hrefs: list[str] = []

    def handle_starttag(
        self, tag: str, attrs: list[tuple[str, str | None]]
    ) -> None:
        values = dict(attrs)
        if tag == "a" and values.get("href") is not None:
            self.hrefs.append(str(values["href"]))

    @classmethod
    def read(cls, path: Path) -> list[str]:
        parser = cls()
        parser.feed(path.read_text(encoding="utf-8"))
        return parser.hrefs


def main() -> None:
    validate_prewrite_status()
    run157 = validate_checkpoint_and_inputs()
    parser, unique_local, missing_local, hash_failures, lineage_records, checks = (
        parse_and_validate_html()
    )
    receipt = build_receipt(
        parser,
        unique_local,
        missing_local,
        hash_failures,
        lineage_records,
        checks,
        run157,
    )
    encoded = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    assert encoded.endswith(b"\n") and b"\r\n" not in encoded
    assert not encoded.startswith(b"\xef\xbb\xbf")
    assert all(line.rstrip(b" \t") == line for line in encoded.splitlines())
    (AUDIT / OUTPUT).write_bytes(encoded)
    assert (AUDIT / OUTPUT).read_bytes() == encoded
    validate_output(receipt)
    print(
        json.dumps(
            {
                "status": STATUS,
                "schema_version": receipt["schema_version"],
                "dashboard_sha256": HTML_SHA256,
                "materializer_sha256": receipt["pins"]["receipt_materializer"]["sha256"],
                "receipt_sha256": sha256(encoded),
                "receipt_self_seal_sha256": receipt["receipt_self_seal_sha256"],
                "visible_checks": len(EXACT_VISIBLE_STATIC_BOUNDARY_CHECKS),
                "supplemental_static_checks": len(checks),
                "lineage_artifacts": len(lineage_records),
                "anchor_elements": len(parser.hrefs),
                "unique_local_resources": len(unique_local),
                "post_materialization_local_resources": "387/387",
                "positive_credit_keys": ["exact_audit_dashboard_artifact"],
                "gate_4_complete": False,
                "audit_complete": False,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
