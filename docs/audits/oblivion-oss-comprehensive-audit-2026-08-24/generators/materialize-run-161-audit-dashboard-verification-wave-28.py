#!/usr/bin/env python3
"""Serialize the bounded RUN161 reporting correction and dashboard verification.

Browser facts are limited to the supplied read-only observation. This
materializer independently validates the exact audit-only attribution
correction, local HTML, links, anchors, displayed hashes, lineage, and
historical RUN160 reporting receipt, then writes only its JSON receipt. It
awards no application or downstream completion credit.
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

RUN_ID = "RUN-161-AUDIT-DASHBOARD-VERIFICATION-WAVE-28"
STATUS = "AUDIT_REPORTING_ATTRIBUTION_CORRECTED_EXACT_ARTIFACT_VERIFIED_ZERO_APPLICATION_CREDIT"
MATERIALIZER = "generators/materialize-run-161-audit-dashboard-verification-wave-28.py"
OUTPUT = "evidence/browser/current-audit-dashboard-verification-run-161-wave-28.json"
HTML = "audit-dashboard.html"
BUILDER = "generators/build-current-audit-dashboard.py"
RUN160_GENERATOR = "generators/materialize-run-160-med-rbac-already-fixed-reporting-wave-28.py"
RUN160_RECEIPT = "evidence/source/current-run-160-med-rbac-already-fixed-reporting-wave-28.json"

CHECKPOINT_COMMIT = "1ff92f28ffbb939d48d300cffbc8f33ab4489d93"
CHECKPOINT_TREE = "b035b9ba02155e5e33e0cdcaab342dd21a2a961e"
CHECKPOINT_PARENT = "bbf587870909d8f3f0ba4de89bb7a50eeab8a3e3"
HTML_SHA256 = "c27d0535885c68984b96bf1fbbb91f65f303a8ed8b9255742df9d8f0788370b3"
HTML_BLOB_ID = "5bb2c6fff53a1e6440359a51546db30dbf7ecc0b"
HTML_BYTES = 244814
HTML_LINES = 78
BASELINE_HTML_SHA256 = "1b0747372d70254f9761177c151fb8dba38090d4a2fae919a0ed0ee91431e2b3"
BASELINE_HTML_BLOB_ID = "6cec2d70cc6c4c7596e8d5f1c096b3419cbf8524"
BASELINE_HTML_BYTES = 235542
BASELINE_HTML_LINES = 77

BROWSER_TARGET_URL = (
    "http://127.0.0.1:43163/audit-dashboard.html"
    "?run=161&sha=c27d0535885c"
)

VIEWPORTS = [
    {
        "requested": "1440x900",
        "actual_browser_viewport": "1440x900",
        "page_level_horizontal_overflow": False,
        "page_overflow_px": 0,
        "page_scroll_width": 1425,
        "page_client_width": 1425,
        "table_wraps": 10,
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
        "page_scroll_width": 1265,
        "page_client_width": 1265,
        "table_wraps": 10,
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
        "page_scroll_width": 1009,
        "page_client_width": 1009,
        "table_wraps": 10,
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
        "page_scroll_width": 375,
        "page_client_width": 375,
        "table_wraps": 10,
        "tables_needing_bounded_horizontal_scroll": 10,
        "tables_with_unbounded_overflow": 0,
        "main_visible": True,
        "navigation_dom_resolved": True,
        "navigation_overflow_x": "auto",
        "navigation_scroll_width": 922,
        "navigation_client_width": 375,
        "navigation_needing_bounded_horizontal_scroll": True,
        "font_status": "loaded",
    },
]

NAVIGATION = [
    ("Progress", "#progress"),
    ("RUN-160", "#checkpoint"),
    ("Pages", "#pages"),
    ("Static census", "#static-census"),
    ("Runtime gates", "#runtime"),
    ("Benchmarks", "#benchmarks"),
    ("Modules", "#modules"),
    ("Finding status", "#findings"),
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
        "h1",
        "run160_nav",
        "finding_status_nav",
        "navigation_10",
        "run160_checkpoint_heading",
        "adjudication_checkpoint_heading",
        "one_org_multiple_sites",
        "site_permission_boundary",
        "owners_664",
        "routes_307_pages_357",
        "bridges_95",
        "residual_3265",
        "queue_118_389",
        "queue_411",
        "benchmark_2_of_340",
        "ncm_0_of_340",
        "benchmark_338",
        "retained_claims_12",
        "provisional_findings_11",
        "historical_fixed_1",
        "zero_final_findings",
        "med_tests_73",
        "med_assertions_1481",
        "no_full_suite_coverage",
        "run158_superseded",
        "run158_viewports",
        "run158_visible_checks",
        "run158_nav_checks",
        "run158_links",
        "run158_zero_errors",
        "historical_med_pin",
        "current_med_pin",
        "med_historical_fixed",
        "med_rbac_disposition_established",
        "run159r_retirement_reporting_authorized",
        "run160_live_register_reconciled",
        "checkpoint_role_attribution_exact",
        "separate_run159_run159r_rows",
        "three_reviews",
        "runtime_73_1481",
        "exact_receipt_go",
        "no_application_change",
        "no_scope_atomicity_inheritance",
        "scope_separate",
        "scope_no_inheritance",
        "run160_split_after_reconciliation",
        "run160_materializer_hash",
        "run160_receipt_hash",
        "run161_heading",
        "run161_link",
        "run161_four_viewports",
        "run161_artifact_only",
        "no_application_browser_credit",
        "gate4_false",
        "audit_completion_false",
        "current_browser_zero",
        "cleanup_bounded",
        "reporting_not_runtime",
        "old_queue_ambiguity_absent",
        "stale_provisional_12_current_absent",
    )
}
assert len(EXACT_VISIBLE_STATIC_BOUNDARY_CHECKS) == 63
assert all(EXACT_VISIBLE_STATIC_BOUNDARY_CHECKS.values())

LINEAGE = {
    "generators/materialize-run-158-audit-dashboard-verification-wave-27.py": {
        "sha256": "e5d2bb3dd0a0cfd3db1f24ea859813c107b10767cf4e22f12aa8842d37103e49",
        "blob_id": "09cb906d08310313db61a2fef8c194bbf3a62f47",
        "bytes": 38017,
        "lines": 977,
    },
    "evidence/browser/current-audit-dashboard-verification-run-158-wave-27.json": {
        "sha256": "4b3cf785c5d9f4f0f0263b90ddc722818d1d8fdb4e9bf89bd44f1fec117752fb",
        "blob_id": "3268066ca3204e9c9d3233c2497ce88183b54d85",
        "bytes": 19841,
        "lines": 527,
    },
    "generators/materialize-run-159-med-rbac-already-fixed-adjudication-wave-28.py": {
        "sha256": "cfd37697847c57a5e8116adb5836945daf21208fb00d0885abf7f3d594379ae7",
        "blob_id": "3f0965f58ea4855f76288d662616b0ad6b7d9964",
        "bytes": 23846,
        "lines": 472,
    },
    "evidence/runtime/current-run-159-med-rbac-already-fixed-adjudication-wave-28.json": {
        "sha256": "bc666ded05774b03b849743436cec47cbdb260c8ab763cf502e71c804af7fd8e",
        "blob_id": "116664410ebeb4fa97ed93e7badbd7537c9a4b5d",
        "bytes": 17319,
        "lines": 379,
    },
    "generators/materialize-independent-run-159-med-rbac-adjudication-review-wave-28.py": {
        "sha256": "bc1ef82dfe6459b726acf2567d6d976dbefb8cf869441e32eb0cb02c626a6b5e",
        "blob_id": "1d028d1f90876453e88d578ee9b70b06cc2fd311",
        "bytes": 10808,
        "lines": 249,
    },
    "evidence/runtime/current-run-159r-independent-med-rbac-adjudication-review-wave-28.json": {
        "sha256": "be0651adf9edfbf7694ac535908cf43a5631675bcf6d5264add68fe947437d18",
        "blob_id": "531218a89947b42bf9137a0d588d29c617ee96f0",
        "bytes": 3368,
        "lines": 78,
    },
    RUN160_GENERATOR: {
        "sha256": "2b224ceb98e2889f436ed266576db96f814847db4a49770d382ecabbc2b01ec6",
        "blob_id": "946cef04795ca2540033811afb554992b9c90383",
        "bytes": 39480,
        "lines": 615,
    },
    RUN160_RECEIPT: {
        "sha256": "d5ff3afb23812ddffc7c5c864e745e8685471e3bae2f89171867dd764eafae52",
        "blob_id": "44d4231cebf99f322d6a9513db623d9c34707dcb",
        "bytes": 15753,
        "lines": 375,
    },
}

BASELINE_BUILDER_PIN = {
    "sha256": "0d23faac294a3dc950788e0c8614c0b8473f9ddb960be2e9e13440626d91c865",
    "blob_id": "9b89784b41177a09405a8094cc7c507e6965d594",
    "bytes": 387187,
    "lines": 3417,
}

BUILDER_PIN = {
    "sha256": "d77c1ced750c7661fae2d7033b083f69ca75e1be70036f8e9b3a4d87baf61585",
    "blob_id": "b037d9af06fdf9c62f7fb0bd06a8b2cdae14b38e",
    "bytes": 389526,
    "lines": 3432,
}

REPORTING_CORRECTION_BASELINE = {
    "findings.json": {
        "sha256": "fd27711496bb381b79ed6c42c7bbd4abedccdbd0d90f5059aab75075ea822b02",
        "blob_id": "fd69f61fbc01f927cd0e73ee1d1d39059b9c1254",
        "bytes": 523609,
        "lines": 9651,
    },
    "00-executive-summary.md": {
        "sha256": "22defb3ef6738740d03d097c5b0f7c2f5cba74df387ad7b02bac00a9a8cae18f",
        "blob_id": "cb7bb0cb3806a81ea86328126f84f7a4ad30d990",
        "bytes": 111652,
        "lines": 484,
    },
    "01-repository-module-map.md": {
        "sha256": "0304defc69c33481f1163f639f579fc283856f80446d0d5bfa570174e77fa4a7",
        "blob_id": "c82eeb84db39b9e17f4111c47b7ce078d727afbd",
        "bytes": 32538,
        "lines": 216,
    },
    BUILDER: BASELINE_BUILDER_PIN,
}

REPORTING_CORRECTION_CURRENT = {
    "findings.json": {
        "sha256": "c78810ff3b8acf36d2334abccecbe17fa8386d943b9fc6e8f3c9cf2541887abb",
        "blob_id": "75c5340780152f0ce366c1626736d7c5462c996b",
        "bytes": 523745,
        "lines": 9651,
    },
    "00-executive-summary.md": {
        "sha256": "3f8f1c23156075f91dcacc846dc2ed5311928491a57b55caf3e5fb8efa1be3fa",
        "blob_id": "5b49516bd0d08f59f8f256ed328e0de2d1b9ee5d",
        "bytes": 111916,
        "lines": 484,
    },
    "01-repository-module-map.md": {
        "sha256": "c80fc0a180b4bd41aa26f38a067964594eed885918a466bb17c2079a0a2af43a",
        "blob_id": "9c32c1786a8cdd07fb89f921f06f6d4eb6b1292f",
        "bytes": 32768,
        "lines": 216,
    },
    BUILDER: BUILDER_PIN,
}

INVALIDATED_PRE_CORRECTION_RUN161 = {
    "dashboard_html": {
        "sha256": "990b3978539bd961ee58a6aff2feeb19ced170d2e9293b90f38c293577b1eef0",
        "blob_id": "4541ffe49f39aec2f93d596f2a990bd5538f4f74",
        "bytes": 243138,
        "lines": 78,
    },
    "materializer": {
        "sha256": "d28bc8fa3b5989d2dcdec3741e0df2e359b2b84d7bc356a80e18756a365a1652",
        "blob_id": "896c7b984a52908eb7d164965f07e1fa6fead799",
        "bytes": 45366,
        "lines": 1123,
    },
    "receipt": {
        "sha256": "a18a62cb872153fffa8d7079ce408172a37189d102588b03036f6272dba4f6c8",
        "blob_id": "e582082a7bab61aff485155ef3f75aa1d589c9a8",
        "bytes": 23060,
        "lines": 609,
        "receipt_self_seal_sha256": "07919f58e417efa3200274f863c6db21475967faa928af2529aedc8d28d650d8",
    },
}

MED_RECORD_HASHES = {
    "MED-RBAC-01": "aa35c543ac25d15d074b344abd6ce8750975717f6c6e229d36986256c5a301ea",
    "MED-RBAC-01-CURRENT": "3aeac2fd6d69cc84cae814773912eea1bcc9417c3daedb8f08d1ac7d959069cb",
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
        f" M {PREFIX}/00-executive-summary.md",
        f" M {PREFIX}/01-repository-module-map.md",
        f" M {PREFIX}/{HTML}",
        f" M {PREFIX}/findings.json",
        f" M {PREFIX}/{BUILDER}",
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


def validate_reporting_correction_semantics() -> None:
    findings = strict_json("findings.json")
    assert findings["credit_boundary"] == (
        "Eleven current provisional records and one retained historical already-fixed "
        "record remain reviewable. RUN-159 establishes the MED-RBAC-01 ALREADY_FIXED "
        "disposition, RUN-159R independently authorizes retirement reporting, and "
        "RUN-160 alone reclassifies MED-RBAC-01 from current provisional to retained "
        "historical already fixed. This does not satisfy any final finding, benchmark, "
        "browser, ease, Pass, feature-completion, or audit-completion gate."
    )

    summary = assert_lf(AUDIT / "00-executive-summary.md").decode("utf-8")
    module_map = assert_lf(AUDIT / "01-repository-module-map.md").decode("utf-8")
    builder = assert_lf(AUDIT / BUILDER).decode("utf-8")
    assert all(
        token in summary
        for token in (
            "RUN-159 establishes the `MED-RBAC-01` ALREADY_FIXED disposition",
            "RUN-159R independently authorizes retirement reporting",
            "RUN-160 alone reclassifies it from current provisional to retained historical already fixed",
        )
    )
    assert all(
        token in module_map
        for token in (
            "RUN-159 then adjudicates `MED-RBAC-01`",
            "RUN-159R independently reviews the corrected exact artifact",
            "RUN-159R independently authorizes retirement reporting",
            "RUN-160 alone reclassifies the identity from current provisional to retained historical already fixed",
        )
    )
    assert all(
        token in builder
        for token in (
            "RUN-159 establishes the",
            "ALREADY_FIXED disposition",
            "RUN-159R independently authorizes retirement reporting",
            "RUN-160 alone reclassifies",
            "For MED-RBAC-01 in this bounded adjudication wave, no application remediation was required or performed",
            "That statement grants no disposition or remediation credit to any other finding",
            "for stale_attribution in (",
            "assert stale_attribution not in dashboard",
        )
    )

    stale = (
        "RUN-159/R retire only historical MED-RBAC",
        "historical MED-RBAC identity retired from current provisional queue",
        "MED-RBAC-only retirement",
        "RUN-159/R retire only",
        "bounded MED-RBAC retirement evidence",
        "exact MED-RBAC-only retirement",
        "RUN-159 later retires only",
        "RUN-159 bounded test evidence retires only",
        "Audit artifacts only; no application remediation was required or performed",
        "RUN-159/R authorize MED-RBAC retirement after bounded source/runtime evidence",
        "RUN-159/R authorize MED-RBAC retirement",
        "RUN-159/R",
    )
    for relative in REPORTING_CORRECTION_CURRENT:
        if relative == BUILDER:
            continue
        text = assert_lf(AUDIT / relative).decode("utf-8")
        assert not [token for token in stale if token in text], relative


def validate_checkpoint_and_inputs() -> dict[str, Any]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
    assert git("rev-parse", "HEAD^") == CHECKPOINT_PARENT
    assert git("rev-parse", "origin/main") == CHECKPOINT_COMMIT
    assert git("rev-list", "--left-right", "--count", "origin/main...HEAD") == "0\t0"

    repository_html = f"{PREFIX}/{HTML}"
    baseline = run_git("show", f"HEAD:{repository_html}").stdout
    assert sha256(baseline) == BASELINE_HTML_SHA256
    assert git("rev-parse", f"HEAD:{repository_html}") == BASELINE_HTML_BLOB_ID
    assert len(baseline) == BASELINE_HTML_BYTES
    assert len(baseline.decode("utf-8").splitlines()) == BASELINE_HTML_LINES

    html_record = file_record(HTML)
    assert html_record == {
        "sha256": HTML_SHA256,
        "blob_id": HTML_BLOB_ID,
        "bytes": HTML_BYTES,
        "lines": HTML_LINES,
    }
    expected_tracked = {
        f"{PREFIX}/00-executive-summary.md",
        f"{PREFIX}/01-repository-module-map.md",
        repository_html,
        f"{PREFIX}/findings.json",
        f"{PREFIX}/{BUILDER}",
    }
    assert set(git_lines("diff", "--name-only", "HEAD", "--")) == expected_tracked
    diff_check = run_git(
        "diff", "--check", "HEAD", "--", *sorted(expected_tracked), check=False
    )
    assert diff_check.returncode == 0
    assert diff_check.stdout == b"" and diff_check.stderr == b""

    assert file_record(BUILDER) == BUILDER_PIN
    assert git("rev-parse", f"HEAD:{PREFIX}/{BUILDER}") == BASELINE_BUILDER_PIN["blob_id"]
    ast.parse((AUDIT / BUILDER).read_text(encoding="utf-8"))
    ast.parse((AUDIT / MATERIALIZER).read_text(encoding="utf-8"))

    for relative, current in REPORTING_CORRECTION_CURRENT.items():
        assert file_record(relative) == current, relative
        baseline_record = REPORTING_CORRECTION_BASELINE[relative]
        assert git("rev-parse", f"HEAD:{PREFIX}/{relative}") == baseline_record["blob_id"]
    validate_reporting_correction_semantics()

    for relative, expected in LINEAGE.items():
        assert file_record(relative) == expected, relative
        assert git("rev-parse", f"HEAD:{PREFIX}/{relative}") == expected["blob_id"]

    run160 = strict_json(RUN160_RECEIPT)
    assert run160["schema_version"] == "run-160-med-rbac-already-fixed-reporting-wave-28-v1"
    assert run160["run_id"] == "RUN-160-MED-RBAC-01-ALREADY-FIXED-REPORTING-WAVE-28"
    assert run160["status"] == (
        "LIVE_REGISTER_RECONCILED_11_PROVISIONAL_PLUS_1_HISTORICAL_ALREADY_FIXED_"
        "73_TESTS_1481_ASSERTIONS_ZERO_FINAL_FINDING_OR_COMPLETION_CREDIT"
    )
    seal = run160["receipt_self_seal_sha256"]
    assert seal == "e3904cbdfcfb514a08402e80dff3c77f0999dba51e12146c6a8770ea814144ad"
    assert seal == canonical_sha256(
        {key: value for key, value in run160.items() if key != "receipt_self_seal_sha256"}
    )
    assert run160["pins"]["materializer"] == {
        "path": RUN160_GENERATOR,
        **LINEAGE[RUN160_GENERATOR],
    }
    assert run160["pins"]["current_application_commit"] == "4f57ad4202df90ded375961437879822a908627b"
    assert run160["pins"]["current_application_tree"] == "ee79b8d2733d09da2fd97992ac2a04e862159505"
    assert run160["pins"]["historical_audit_commit"] == "a0493442b9e392d324055c35bf25b69421dc2d35"
    for relative, baseline_record in REPORTING_CORRECTION_BASELINE.items():
        assert run160["reporting_surfaces"]["changed"][relative]["current"] == baseline_record
    forward = run160["dashboard_forward_gate"]
    assert forward["run_160_dashboard_source_changed"] is True
    assert forward["run_160_dashboard_html_materialized"] is False
    assert forward["run_160_dashboard_browser_verified"] is False
    assert forward["fresh_run_161_required"] is True
    assert forward["required_viewports"] == [row["requested"] for row in VIEWPORTS]
    register = run160["finding_register"]
    assert register["retained_historical_claim_identities"] == 12
    assert register["current_provisional_source_claims"] == register["current_provisional_P1"] == 11
    assert register["historical_already_fixed"] == 1
    assert register["historical_already_fixed_id"] == "MED-RBAC-01"
    assert register["current_final_P0"] == register["current_final_P1"] == 0
    assert register["run_159_bounded_MED_RBAC_tests_reported"] == 73
    assert register["run_159_bounded_MED_RBAC_assertions_reported"] == 1481
    assert register["tests_executed_or_recredited_by_run_160"] is False
    assert register["benchmark_mapped"] == 2
    assert register["final_no_match_or_NCM"] == 0
    assert register["benchmark_unresolved"] == 338
    adjudication = run160["adjudication_boundary"]
    assert adjudication["finding_id"] == "MED-RBAC-01"
    assert adjudication["current_verdict"] == "ALREADY_FIXED"
    assert adjudication["independent_current_source_review_lanes"] == 3
    assert adjudication["bounded_tests_passed"] == 73
    assert adjudication["bounded_assertions"] == 1481
    assert adjudication["exact_receipt_review"] == "GO"
    assert adjudication["application_remediation_required"] is False
    assert adjudication["application_source_changed"] is False
    assert adjudication["current_orders_manage_only_bypass_reproduced"] is False
    assert adjudication["current_final_finding"] is False
    assert all(value is False for value in run160["noninheritance_boundary"].values())
    assert [key for key, value in run160["credit_boundary"].items() if value] == [
        "AUDIT_REPORTING_REFRESH_FOR_MED_RBAC_ALREADY_FIXED_DISPOSITION"
    ]
    assert all(value is False for value in run160["completion_boundary"].values())
    assert run160["artifact_completion_test_met"] is True
    assert run160["audit_completion_test_met"] is False
    return run160


def supplemental_static_checks(visible_text: str) -> dict[str, bool]:
    required: dict[str, tuple[str, ...]] = {
        "current_owner_counts_664_307_357_95": (
            "664 bounded source-owner records",
            "307 routes + 357 pages",
            "95 action bridges",
        ),
        "bounded_residual_3265": ("3,265 records remain",),
        "queue_118_389": ("118 queue rows are reviewed, 389 remain pending",),
        "queue_without_ownership_411": ("411 remain without ownership",),
        "benchmark_mapping_2_of_340": ("target-specific mapping is 2/340",),
        "final_ncm_0_of_340": ("0/340 final no-match/NCM",),
        "benchmark_unresolved_338": ("338 targets remain unresolved",),
        "retained_identity_split_12_11_1": (
            "12 retained identities = 11 current provisional P1 + 1 historical already-fixed",
        ),
        "current_provisional_11": ("11 current provisional P1",),
        "historical_already_fixed_1": ("1 historical already-fixed",),
        "none_final": ("none final",),
        "one_org_multiple_sites": ("one operating organisation across multiple Sites",),
        "site_authorization_boundary": (
            "Site access, roles/permissions, canonical ownership, direct-object denial, privacy",
        ),
        "gate_4_false": ("Gate 4 false",),
        "audit_completion_false": ("audit completion false",),
        "run158_exact_superseded_artifact": (
            "RUN-158 verifies only the exact now-superseded RUN-157 audit-dashboard artifact",
        ),
        "run158_four_viewports": ("4/4 required viewports",),
        "run158_visible_checks": ("50/50 visible boundary checks",),
        "run158_navigation": ("10/10 navigation targets",),
        "run158_links": ("387/387 local resources",),
        "run158_zero_errors": (
            "zero console warnings, console errors, or page errors",
        ),
        "run158_proof_nontransfer": (
            "None of that proof transfers to the RUN-160 dashboard or the application",
        ),
        "historical_a049_pin": ("a0493442b9e392d324055c35bf25b69421dc2d35",),
        "current_application_4f57_pin": (
            "4f57ad4202df90ded375961437879822a908627b",
        ),
        "med_rbac_historical_identity": (
            "MED-RBAC-01",
            MED_RECORD_HASHES["MED-RBAC-01"],
        ),
        "med_scope_separate_identity": (
            "MED-CD-SCOPE-01",
            MED_RECORD_HASHES["MED-CD-SCOPE-01"],
        ),
        "med_atomicity_separate_identity": (
            "MED-CD-ATOMICITY-01",
            MED_RECORD_HASHES["MED-CD-ATOMICITY-01"],
        ),
        "med_rbac_already_fixed": ("historical issue already fixed on current main",),
        "run159_establishes_already_fixed_disposition": (
            "RUN-159 establishes the MED-RBAC-01 ALREADY_FIXED disposition",
        ),
        "run159r_authorizes_retirement_reporting": (
            "RUN-159R independently authorizes retirement reporting",
        ),
        "run160_alone_reclassifies_live_register": (
            "RUN-160 alone reclassifies MED-RBAC-01 from current provisional to retained historical already-fixed",
        ),
        "checkpoint_role_attribution_exact": (
            "RUN-159 establishes the MED-RBAC-01 ALREADY_FIXED disposition after bounded source/runtime evidence",
            "RUN-159R independently authorizes retirement reporting",
            "RUN-160 alone reconciles the live finding register",
        ),
        "separate_run159_run159r_rows": (
            "RUN-159 MED-RBAC adjudication",
            "RUN-159R exact receipt review",
        ),
        "three_current_source_reviews": ("3 independent current-source ALREADY_FIXED reviews",),
        "bounded_med_runtime": ("73 tests", "1,481 assertions"),
        "exact_receipt_go": ("exact corrected receipt GO",),
        "no_application_change": ("no application change",),
        "med_rbac_only_remediation_scope": (
            "For MED-RBAC-01 in this bounded adjudication wave, no application remediation was required or performed",
            "That statement grants no disposition or remediation credit to any other finding",
        ),
        "no_current_bypass": ("no current bypass",),
        "exact_capabilities_current": (
            "current controlled and stock mutations require exact capabilities",
        ),
        "no_scope_atomicity_inheritance": ("no scope/atomicity inheritance",),
        "scope_atomicity_remain_separate": (
            "MED-CD-SCOPE-01 and MED-CD-ATOMICITY-01 remain separate current provisional claims",
        ),
        "scope_atomicity_no_inherited_credit": (
            "inherit no source, runtime, browser, closure, or completion credit from MED-RBAC",
        ),
        "cleanup_bounded": (
            "configured base and matching effective schemas absent after cleanup",
        ),
        "run160_live_split": (
            "RUN-160 live reporting",
            "11 current provisional P1 + 1 historical already-fixed · 12 retained identities",
        ),
        "run160_materializer_hash": (
            "2b224ceb98e2889f436ed266576db96f814847db4a49770d382ecabbc2b01ec6",
        ),
        "run160_receipt_hash": (
            "d5ff3afb23812ddffc7c5c864e745e8685471e3bae2f89171867dd764eafae52",
        ),
        "run160_materializer_link": (
            "RUN-160 MED-RBAC already-fixed reporting materializer",
        ),
        "run160_receipt_link": (
            "RUN-160 MED-RBAC already-fixed reporting receipt",
        ),
        "fresh_run161_required": (
            "Fresh RUN-161 audit-reporting correction and dashboard verification required",
        ),
        "run161_receipt_link": (
            "RUN-161 corrected reporting and responsive audit-dashboard verification receipt",
        ),
        "run161_exact_attribution_correction_scope": (
            "RUN-161 corrects attribution wording only across the executive summary, repository module map, live findings register, dashboard builder, and dashboard",
        ),
        "run161_four_viewports": (
            "1440×900, 1280×800, 1024×768, and 390×844",
        ),
        "run161_artifact_only": ("verifies the corrected audit artifact only",),
        "no_application_browser_credit": (
            "no application-browser, responsive-application, visual, workflow, release, Pass, feature-completion, or audit-complete credit",
        ),
        "no_full_suite_or_coverage_credit": ("no full-suite or coverage credit",),
        "run159_bounded_execution_only": (
            "RUN-159 establishes bounded current-source MED-RBAC test execution only",
        ),
        "current_source_browser_zero": (
            "current-source browser routes",
            "attribution unproved",
        ),
    }
    assert len(required) == 58
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
    assert parser.headings == 26
    assert parser.tables == 10
    assert parser.table_wraps == 10
    assert len(parser.hrefs) == 726
    assert len(parser.ids) == 10

    id_counts = Counter(parser.ids)
    assert not [key for key, count in id_counts.items() if count > 1]
    hash_hrefs = [href for href in parser.hrefs if href.startswith("#")]
    local_hrefs = [href for href in parser.hrefs if is_local(href)]
    unique_local = sorted(set(local_hrefs))
    assert len(hash_hrefs) == len(set(hash_hrefs)) == 10
    assert len(local_hrefs) == 716
    assert len(unique_local) == 395
    assert [href for _, href in NAVIGATION] == hash_hrefs
    missing_anchors = sorted({href for href in hash_hrefs if href[1:] not in id_counts})
    assert not missing_anchors

    missing_local = [href for href in unique_local if not local_path(href).exists()]
    assert missing_local in ([], [OUTPUT]), missing_local

    hash_pairs = re.findall(
        r'<a href="([^"#]+)">[^<]*</a>\s*<code>([0-9a-f]{64})</code>', text
    )
    assert len(hash_pairs) == 615
    assert len(set(hash_pairs)) == 323
    assert len({href for href, _ in hash_pairs}) == 323
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
    assert '<a href="#checkpoint">RUN-160</a>' in text
    assert '<a href="#findings">Finding status</a>' in text
    assert '<a href="#checkpoint">RUN-157</a>' not in text
    assert '<a href="#findings">Provisional findings</a>' not in text
    prohibited_current = {
        "run_071_through_157_current_checkpoint": "RUN-071–157 current reporting checkpoint",
        "stale_run157_navigation": "RUN-157 Progress Pages Static census",
        "stale_run158_verification_required": "Fresh RUN-158 audit-dashboard verification required",
        "stale_663_owner_checkpoint": "663 bounded source-owner records (306 routes + 357 pages)",
        "stale_94_bridges": "plus 94 action bridges",
        "stale_queue_117_390": "507 total = 117 reviewed + 390 pending",
        "stale_without_ownership_412": "412 without ownership",
        "stale_current_provisional_12": "12 current provisional P1 claims",
        "stale_runtime_zero": "0 current application tests",
        "stale_vendor_absent": "vendor absent; setup not run",
        "ambiguous_queue_statement": "every Fleet, queue, benchmark, final-finding",
        "stale_run159r_retires_history": "RUN-159/R retire only historical MED-RBAC",
        "stale_run159r_retired_queue": "historical MED-RBAC identity retired from current provisional queue",
        "stale_med_rbac_retirement_label": "MED-RBAC-only retirement",
        "stale_run159r_retires_queue": "RUN-159/R retire only",
        "stale_bounded_retirement_evidence": "bounded MED-RBAC retirement evidence",
        "stale_exact_retirement_checklist": "exact MED-RBAC-only retirement",
        "overbroad_no_remediation_footer": "Audit artifacts only; no application remediation was required or performed",
        "compressed_run159r_retirement_authorization": "RUN-159/R authorize MED-RBAC retirement after bounded source/runtime evidence",
        "compressed_run159r_retirement_authorization_prefix": "RUN-159/R authorize MED-RBAC retirement",
        "visible_combined_run159r_shorthand": "RUN-159/R",
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
    run160: dict[str, Any],
) -> dict[str, Any]:
    id_counts = Counter(parser.ids)
    hash_hrefs = [href for href in parser.hrefs if href.startswith("#")]
    local_hrefs = [href for href in parser.hrefs if is_local(href)]
    text = (AUDIT / HTML).read_text(encoding="utf-8")
    hash_pairs = re.findall(
        r'<a href="([^"#]+)">[^<]*</a>\s*<code>([0-9a-f]{64})</code>', text
    )
    hash_file_pairs = [(href, digest) for href, digest in hash_pairs if local_path(href).is_file()]
    hash_directory_pairs = [(href, digest) for href, digest in hash_pairs if local_path(href).is_dir()]
    assert len(hash_file_pairs) == 613
    assert len({href for href, _ in hash_file_pairs}) == 322
    assert len(hash_directory_pairs) == 2
    assert {href for href, _ in hash_directory_pairs} == {"task-scripts/"}
    materializer_record = file_record(MATERIALIZER)
    false_credit = (
        "application_browser",
        "responsive_application",
        "visual_or_workflow",
        "application_source_mutation",
        "application_remediation",
        "medication_semantic_adjudication",
        "med_cd_scope_disposition",
        "med_cd_atomicity_disposition",
        "remediation_or_defect_closure",
        "run_159_source_review_recredit",
        "run_159_bounded_test_evidence_recredit",
        "run_159r_exact_review_recredit",
        "run_160_reporting_recredit",
        "runtime",
        "database",
        "build",
        "executed_tests",
        "full_test_suite",
        "test_coverage",
        "new_benchmark_mapping",
        "new_final_no_match_or_NCM",
        "finding",
        "final_finding",
        "final_P0",
        "final_P1",
        "priority_promotion",
        "module_completion",
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
        "schema_version": "run-161-audit-dashboard-verification-wave-28-v1",
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
            "Exact audit-only RUN161 attribution correction across the executive summary, "
            "repository module map, live findings register, dashboard builder, and dashboard, "
            "plus exact corrected dashboard artifact verification; not the application UI, "
            "application browser, medication runtime, or application execution."
        ),
        "pins": {
            "governing_prompt_sha256": "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f",
            "checkpoint_commit": CHECKPOINT_COMMIT,
            "checkpoint_tree": CHECKPOINT_TREE,
            "checkpoint_parent": CHECKPOINT_PARENT,
            "current_application_commit": "4f57ad4202df90ded375961437879822a908627b",
            "current_application_tree": "ee79b8d2733d09da2fd97992ac2a04e862159505",
            "historical_audit_commit": "a0493442b9e392d324055c35bf25b69421dc2d35",
            "baseline_dashboard_html": {
                "sha256": BASELINE_HTML_SHA256,
                "blob_id": BASELINE_HTML_BLOB_ID,
                "bytes": BASELINE_HTML_BYTES,
                "lines": BASELINE_HTML_LINES,
            },
            "dashboard_html": {
                "sha256": HTML_SHA256,
                "blob_id": HTML_BLOB_ID,
                "bytes": HTML_BYTES,
                "lines": HTML_LINES,
            },
            "baseline_dashboard_generator": {
                "path": BUILDER,
                **BASELINE_BUILDER_PIN,
            },
            "dashboard_generator": {"path": BUILDER, **BUILDER_PIN},
            "run_160_materializer": {
                "path": RUN160_GENERATOR,
                **LINEAGE[RUN160_GENERATOR],
            },
            "run_160_receipt": {
                "path": RUN160_RECEIPT,
                **LINEAGE[RUN160_RECEIPT],
                "receipt_self_seal_sha256": run160["receipt_self_seal_sha256"],
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
        "reporting_attribution_correction": {
            "status": "CORRECTED_EXACT_RUN_ATTRIBUTION_WITH_COUNTS_AND_DISPOSITIONS_UNCHANGED",
            "historical_run_159_run_159r_and_run_160_receipts_mutated": False,
            "historical_run_160_surface_hashes_preserved_in_run_160_receipt": True,
            "historical_run_160_surface_hashes_are_pre_correction_checkpoint": True,
            "changed_committed_surfaces": 5,
            "changed_source_surfaces": {
                relative: {
                    "baseline": REPORTING_CORRECTION_BASELINE[relative],
                    "current": REPORTING_CORRECTION_CURRENT[relative],
                }
                for relative in REPORTING_CORRECTION_CURRENT
            },
            "dashboard": {
                "baseline": {
                    "sha256": BASELINE_HTML_SHA256,
                    "blob_id": BASELINE_HTML_BLOB_ID,
                    "bytes": BASELINE_HTML_BYTES,
                    "lines": BASELINE_HTML_LINES,
                },
                "current": {
                    "sha256": HTML_SHA256,
                    "blob_id": HTML_BLOB_ID,
                    "bytes": HTML_BYTES,
                    "lines": HTML_LINES,
                },
            },
            "correct_attribution": {
                "run_159": "ESTABLISHES_MED_RBAC_ALREADY_FIXED_DISPOSITION",
                "run_159r": "INDEPENDENTLY_AUTHORIZES_RETIREMENT_REPORTING",
                "run_160": "ALONE_RECLASSIFIES_CURRENT_PROVISIONAL_TO_RETAINED_HISTORICAL_ALREADY_FIXED",
            },
            "finding_counts_changed": False,
            "finding_dispositions_changed": False,
            "benchmark_counts_changed": False,
            "application_source_changed": False,
            "stale_attribution_phrases_absent_from_corrected_dashboard": True,
        },
        "invalidated_pre_correction_run_161_candidate": {
            "publication_status": "UNCOMMITTED_SUPERSEDED_SEMANTIC_NO_GO",
            "exact_bytes": INVALIDATED_PRE_CORRECTION_RUN161,
            "mechanical_checks_had_passed_before_semantic_no_go": True,
            "semantic_no_go_reason": (
                "The candidate attributed live-register retirement to RUN159/R; "
                "RUN159 established the ALREADY_FIXED disposition, RUN159R independently "
                "authorized retirement reporting, and RUN160 alone reconciled the live register."
            ),
            "browser_facts_transferred_to_corrected_artifact": False,
            "mechanical_go_transferred_to_corrected_artifact": False,
            "credit_transferred_to_corrected_artifact": False,
        },
        "reported_finding_boundary": {
            "retained_historical_claim_identities": 12,
            "current_provisional_P1": 11,
            "historical_already_fixed": 1,
            "historical_already_fixed_id": "MED-RBAC-01",
            "current_final_P0": 0,
            "current_final_P1": 0,
            "run_159_bounded_MED_RBAC_tests_reported_not_reexecuted": 73,
            "run_159_bounded_MED_RBAC_assertions_reported_not_reexecuted": 1481,
            "benchmark_mapped": 2,
            "final_no_match_or_NCM": 0,
            "benchmark_unresolved": 338,
            "gate_4_complete": False,
            "audit_complete": False,
        },
        "noninheritance_boundary": {
            "MED_RBAC_01_only_already_fixed_disposition": True,
            "MED_CD_SCOPE_01_retired_or_credited": False,
            "MED_CD_ATOMICITY_01_retired_or_credited": False,
            "run_159_runtime_reexecuted_or_recredited": False,
            "run_160_reporting_recredited": False,
            "run_161_attribution_correction_changes_finding_or_completion_credit": False,
            "run_158_dashboard_proof_transferred": False,
            "invalidated_pre_correction_run_161_browser_proof_transferred": False,
            "application_browser_credit_inferred": False,
        },
        "remote_state_boundary": {
            "local_origin_main_observed_equal_to_checkpoint": True,
            "ahead": 0,
            "behind": 0,
            "fetch_performed_by_run_161": False,
            "remote_currency_verified_by_run_161": False,
            "publication_or_push_performed_by_run_161": False,
        },
        "root_browser_resource_cleanup": {
            "temporary_in_app_browser_tab_closed": True,
            "temporary_viewport_override_reset_before_close": True,
            "local_http_server_port": 43163,
            "local_http_server_stopped_before_artifact_close": True,
            "post_stop_listener_count": 0,
            "post_stop_server_process_count": 0,
        },
        "verification_method": {
            "in_app_browser": {
                "facts_supplied_by_root": True,
                "target_url": BROWSER_TARGET_URL,
                "cachebuster_used": True,
                "cachebuster": "run=161&sha=c27d0535885c",
                "response_probe": "Read-only local HTTP GET against the exact artifact URL",
                "response_status": 200,
                "response_content_type": "text/html",
                "response_bytes": HTML_BYTES,
                "response_sha256": HTML_SHA256,
                "exact_dashboard_loaded": True,
                "four_viewports_observed": True,
                "all_navigation_links_actually_clicked": True,
                "read_only_navigation_and_layout_inspection": True,
                "application_or_external_state_changed": False,
            },
            "static_validation": (
                "Parsed exact local HTML bytes; resolved local resources, authored "
                "IDs, anchors, adjacent displayed hashes, and committed lineage."
            ),
            "materializer_browser_execution_performed": False,
            "materializer_byte_identical_runs_required": 2,
            "dashboard_builder_byte_identical_runs_observed_by_root": 2,
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
            "anchor_elements_visible_in_browser": len(parser.hrefs),
            "all_anchor_elements_visible_in_browser": True,
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
            "post_materialization_local_resources": "395/395",
            "post_materialization_local_resource_failures": [],
            "adjacent_hash_pair_occurrences": len(hash_pairs),
            "unique_adjacent_hash_path_hash_pairs": len(set(hash_pairs)),
            "unique_adjacent_hash_paths": len({href for href, _ in hash_pairs}),
            "hash_bearing_file_occurrences_verified": len(hash_file_pairs),
            "unique_hash_bearing_file_paths_verified": len(
                {href for href, _ in hash_file_pairs}
            ),
            "historical_directory_bundle_digest_occurrences_not_file_hashes": len(
                hash_directory_pairs
            ),
            "historical_directory_bundle_digest_paths": sorted(
                {href for href, _ in hash_directory_pairs}
            ),
            "hash_bearing_link_failures": hash_failures,
            "navigation_targets": "10/10",
            "navigation_labels_include_run_160": True,
            "navigation_labels_include_finding_status": True,
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
            "expected_status_count": 7,
            "expected_porcelain_statuses": sorted(
                line.lstrip() for line in expected_status(include_receipt=True)
            ),
            "exact_match_required": True,
        },
        "mutation_attestation": {
            "run_161_change_set_is_exactly_three_reporting_surfaces_dashboard_builder_html_materializer_and_receipt": True,
            "run_161_materializer_writes_only_receipt": True,
            "dashboard_html_pre_materialized": True,
            "dashboard_builder_changed_by_run_161": True,
            "audit_reporting_attribution_corrected_by_run_161": True,
            "dashboard_builder_executed_twice_byte_identically_by_root": True,
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
            "audit_reporting_attribution_correction": True,
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
            f"{PREFIX}/00-executive-summary.md",
            f"{PREFIX}/01-repository-module-map.md",
            f"{PREFIX}/{HTML}",
            f"{PREFIX}/findings.json",
            f"{PREFIX}/{BUILDER}",
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
        "audit_reporting_attribution_correction",
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
    run160 = validate_checkpoint_and_inputs()
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
        run160,
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
                "post_materialization_local_resources": "395/395",
                "positive_credit_keys": [
                    "audit_reporting_attribution_correction",
                    "exact_audit_dashboard_artifact",
                ],
                "gate_4_complete": False,
                "audit_complete": False,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
