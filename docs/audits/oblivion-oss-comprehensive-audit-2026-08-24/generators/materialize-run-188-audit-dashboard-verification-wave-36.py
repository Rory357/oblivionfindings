#!/usr/bin/env python3
"""Seal bounded RUN188 facts for the exact RUN187 audit dashboard.

This producer validates the committed RUN187 reporting inputs, the narrow
builder guard correction, the deterministic generated HTML, the reported
in-app-browser observations, and the local resource graph from exact bytes.
It writes only its paired receipt. Facility, driver-licence, IT delivery,
application-browser, runtime, test, benchmark, finding, release, publication,
Gate 4, feature, module, and audit-completion credit remain excluded.
"""
from __future__ import annotations

import argparse
import ast
from collections import Counter
import hashlib
from html.parser import HTMLParser
import json
import math
from pathlib import Path
import re
import subprocess
from typing import Any
from urllib.parse import unquote, urlsplit


SCRIPT = Path(__file__).resolve()
AUDIT = SCRIPT.parent.parent
ROOT = next(parent for parent in SCRIPT.parents if (parent / ".git").exists())
PREFIX = AUDIT.relative_to(ROOT).as_posix()
MATERIALIZER = SCRIPT.relative_to(AUDIT).as_posix()
OUTPUT = (
    "evidence/browser/"
    "current-audit-dashboard-verification-run-188-wave-36.json"
)
HTML = "audit-dashboard.html"
BUILDER = "generators/build-current-audit-dashboard.py"
FINDINGS = "findings.json"
RUN_187_MATERIALIZER = (
    "generators/materialize-run-187-monitoring-metric-replay-dedupe-"
    "remediation-reporting-wave-36.py"
)
RUN_187_RECEIPT = (
    "evidence/source/current-run-187-monitoring-metric-replay-dedupe-"
    "remediation-reporting-wave-36.json"
)

RUN_ID = "RUN-188-AUDIT-DASHBOARD-VERIFICATION-WAVE-36"
RUN_187_COMMIT = "581f2405771c73edd827e929fa361fcadecc66c2"
RUN_187_TREE = "f8d460f28f8ad5082e23796280bdbc003040e9dd"
RUN_187_PARENT = "50878f2d3008e17979e049d08d66d4b2254499fa"
CHECKPOINT_COMMIT = "5b0a70289de1feec7336ee23158d554816c406b5"
CHECKPOINT_TREE = "d1b1da5aa7638497819b44e86426f8336477cee4"
CHECKPOINT_PARENTS = (
    "9d405a17b4c3c9581252f4ef9a5aea4e2caae7b6 "
    "4b62cf9e9ac371053bca37536fd591f47659dd69"
)
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"
LOCAL_MAIN_AHEAD = 44
LOCAL_MAIN_BEHIND = 0
GOVERNING_PROMPT_SHA256 = (
    "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
)
CONTINUATION_REQUEST_SHA256 = (
    "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d"
)

RUN_187_MATERIALIZER_RECORD = {
    "path": RUN_187_MATERIALIZER,
    "sha256": "342e5cddf6e8e4150a20e43e7efbfa56abc8754af97055e1a66eb59582dcde65",
    "git_blob_id": "f2c8b4d70c178aab69d615665ef699ad0252a52d",
    "bytes": 32816,
    "lines": 608,
}
RUN_187_RECEIPT_RECORD = {
    "path": RUN_187_RECEIPT,
    "sha256": "e84d36fee04b9d39cea9da1d247d92394abf12df4452ffc5d672b9d5cd375412",
    "git_blob_id": "252a18e5f1a7235e4d5ca60b7d31c65b6f39b9cb",
    "bytes": 16717,
    "lines": 412,
}
RUN_187_RECEIPT_SELF_SEAL = (
    "ed9fe03582bc147a5524bb2810051e0721cfaa65257893d3f18066b7afa39c97"
)
COMMITTED_FINDINGS = {
    "path": FINDINGS,
    "sha256": "9c4aae028a358f0d1cb005fa31650ab7c696fb71731fb6961ccc4962f2cac5c9",
    "git_blob_id": "22c2766988b684c8a3c3f6cd8b817ce37741f4b2",
    "bytes": 630225,
    "lines": 11115,
}
COMMITTED_RUN_187_BUILDER = {
    "path": BUILDER,
    "sha256": "91d6fc4d29fad8f584a8dbc5248d07254c736d605932babd119158754168ba25",
    "git_blob_id": "90b7377f34b8da4a0c5a3920c4bda0fac3705c6b",
    "bytes": 717425,
    "lines": 6245,
}
FINAL_RUN_188_BUILDER = {
    "path": BUILDER,
    "sha256": "fd0c4c13d4299934f2347f434b47f349cbc16c45ac39802724b4a11a0eee50c0",
    "git_blob_id": "61797d438347a97ec8c6c559aa4e17a8f2100133",
    "bytes": 717366,
    "lines": 6242,
}
FINAL_RUN_188_BUILDER_DIFF = {
    "path": BUILDER,
    "binary_diff_sha256": (
        "14005892951a193a114bfd0b3fa236699cbdcbaddff8b10ad74c20e8723b60d6"
    ),
    "numstat": {"added": 7, "deleted": 10},
}
COMMITTED_RUN_185_DASHBOARD = {
    "path": HTML,
    "sha256": "3c339da7e05349a7bd5cbed9ec4986e3b4871eb04d497d26078e92268a67e5e7",
    "git_blob_id": "35e98d2f17e081eb01ec51de1429e9eab1208697",
    "bytes": 304332,
    "lines": 78,
}
FINAL_RUN_188_DASHBOARD = {
    "path": HTML,
    "sha256": "3d65bd82b8bc0f650158c4587f9618a03079f75d51e83496dc7d71addf257d79",
    "git_blob_id": "4c6dc53cc4070e626ff0489f4c80e4177709d4ae",
    "bytes": 314007,
    "lines": 78,
}
FINAL_RUN_188_DASHBOARD_DIFF = {
    "path": HTML,
    "binary_diff_sha256": (
        "a572a7e52a6f93992bbd5526e8965e95eb1599527b12928c87010ea0bd87c3e4"
    ),
    "numstat": {"added": 16, "deleted": 16},
}

FACILITY_PATHS = sorted(
    [
        "app/Console/Commands/RecoverSafetySignalDeliveries.php",
        "app/Console/Commands/RetrySafetySignalDelivery.php",
        "app/Http/Controllers/Sites/SiteInspectionController.php",
        "app/Jobs/DispatchFacilitySignalOutbox.php",
        "app/Models/FacilitySignal.php",
        "app/Models/FacilitySignalOutbox.php",
        "app/Services/ControlRoom/SafetySignalDeliveryRecoveryService.php",
        "app/Services/ControlRoom/SignalProcessingService.php",
        "app/Services/Facility/FacilitySignalService.php",
        "database/migrations/2026_08_30_000200_create_facility_signal_delivery_outbox.php",
        "tests/Feature/ControlRoom/FacilitySignalDeliveryRecoveryTest.php",
    ]
)
ISSUE_9_PATHS = [
    "app/Services/Eligibility/Rules/DriverLicenceExpiryRule.php",
    "app/Services/Eligibility/Rules/RequiredDriverLicenceRule.php",
    "tests/Feature/Hr/ShiftLicenceRequirementSeamTest.php",
    "tests/Feature/Rostering/DriverLicenceEligibilityWarningTest.php",
]
ISSUE_10_PATHS = [
    "app/Domain/It/Services/ItEmailDeliveryService.php",
    "app/Http/Requests/It/UpdateItEmailDeliveryStatusRequest.php",
    "tests/Feature/It/ItServiceOperationsTest.php",
]

EXPECTED_FINAL_STATUS = sorted(
    [
        f" M {PREFIX}/{HTML}",
        f" M {PREFIX}/{BUILDER}",
        f"?? {PREFIX}/{MATERIALIZER}",
        f"?? {PREFIX}/{OUTPUT}",
    ]
)
EXPECTED_PREOUTPUT_STATUS = sorted(
    value for value in EXPECTED_FINAL_STATUS if not value.endswith(f"/{OUTPUT}")
)
NAVIGATION = [
    ("Progress", "#progress"),
    ("RUN-187", "#checkpoint"),
    ("Pages", "#pages"),
    ("Static census", "#static-census"),
    ("Runtime gates", "#runtime"),
    ("Benchmarks", "#benchmarks"),
    ("Modules", "#modules"),
    ("Finding status", "#findings"),
    ("Architecture", "#architecture"),
    ("Gaps", "#gaps"),
]
VIEWPORTS = ["1440x900", "1280x800", "1024x768", "390x844"]
FUTURE_LINKS = sorted([MATERIALIZER, OUTPUT])
EXPECTED_UNIQUE_LOCAL_RESOURCES = 471


def git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args],
        cwd=ROOT,
        check=True,
        capture_output=True,
        text=True,
        encoding="utf-8",
    )
    return result.stdout.rstrip()


def run_bytes(*args: str) -> bytes:
    return subprocess.run(
        list(args),
        cwd=ROOT,
        check=True,
        capture_output=True,
    ).stdout


def git_bytes(revision: str, relative: str) -> bytes:
    return run_bytes("git", "show", f"{revision}:{relative}")


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


def assert_finite(value: Any) -> None:
    if isinstance(value, float):
        assert math.isfinite(value)
    elif isinstance(value, dict):
        for item in value.values():
            assert_finite(item)
    elif isinstance(value, list):
        for item in value:
            assert_finite(item)


def strict_text(raw: bytes, label: str) -> str:
    assert not raw.startswith(b"\xef\xbb\xbf"), f"BOM not allowed: {label}"
    assert b"\r" not in raw, f"CR not allowed: {label}"
    assert raw.endswith(b"\n"), f"final LF required: {label}"
    for number, line in enumerate(raw.splitlines(), start=1):
        assert line == line.rstrip(b" \t"), f"trailing whitespace: {label}:{number}"
    return raw.decode("utf-8")


def strict_json(relative: str) -> dict[str, Any]:
    raw = (AUDIT / relative).read_bytes()
    strict_text(raw, relative)

    def no_duplicates(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, f"duplicate JSON key in {relative}: {key}"
            result[key] = value
        return result

    value = json.loads(
        raw.decode("utf-8"),
        object_pairs_hook=no_duplicates,
        parse_constant=lambda token: (_ for _ in ()).throw(
            AssertionError(f"non-finite JSON in {relative}: {token}")
        ),
    )
    assert isinstance(value, dict)
    assert (
        json.dumps(value, ensure_ascii=False, indent=2, allow_nan=False) + "\n"
    ).encode("utf-8") == raw
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


def committed_record(revision: str, relative: str) -> dict[str, Any]:
    raw = git_bytes(revision, f"{PREFIX}/{relative}")
    strict_text(raw, f"{revision}:{relative}")
    return {
        "path": relative,
        "sha256": sha256(raw),
        "git_blob_id": git("rev-parse", f"{revision}:{PREFIX}/{relative}"),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


def diff_record(relative: str) -> dict[str, Any]:
    repository_path = f"{PREFIX}/{relative}"
    binary = run_bytes("git", "diff", "--binary", "--", repository_path)
    fields = git("diff", "--numstat", "--", repository_path).split("\t")
    assert len(fields) == 3 and fields[2] == repository_path
    return {
        "path": relative,
        "binary_diff_sha256": sha256(binary),
        "numstat": {"added": int(fields[0]), "deleted": int(fields[1])},
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
        self.sections = 0
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
        if tag == "section":
            self.sections += 1
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
            ("http://", "https://", "mailto:", "tel:", "javascript:", "data:")
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


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--final-http-head-verified-count", type=int)
    parser.add_argument("--final-http-head-failure-count", type=int)
    parser.add_argument("--listeners-after-cleanup", type=int)
    parser.add_argument(
        "--exact-server-pid-present-after-cleanup",
        choices=("true", "false"),
    )
    parser.add_argument("--matching-loopback-processes-after-cleanup", type=int)
    return parser.parse_args()


def finalization_inputs(args: argparse.Namespace) -> dict[str, Any]:
    resource_values = (
        args.final_http_head_verified_count,
        args.final_http_head_failure_count,
    )
    assert all(value is None for value in resource_values) or all(
        value is not None for value in resource_values
    )
    resource_complete = all(value is not None for value in resource_values)
    if resource_complete:
        assert args.final_http_head_verified_count == EXPECTED_UNIQUE_LOCAL_RESOURCES
        assert args.final_http_head_failure_count == 0

    cleanup_values = (
        args.listeners_after_cleanup,
        args.exact_server_pid_present_after_cleanup,
        args.matching_loopback_processes_after_cleanup,
    )
    assert all(value is None for value in cleanup_values) or all(
        value is not None for value in cleanup_values
    )
    cleanup_complete = all(value is not None for value in cleanup_values)
    exact_pid_present: bool | None = None
    if cleanup_complete:
        exact_pid_present = args.exact_server_pid_present_after_cleanup == "true"
        assert args.listeners_after_cleanup == 0
        assert exact_pid_present is False
        assert args.matching_loopback_processes_after_cleanup == 0

    return {
        "resource_complete": resource_complete,
        "final_http_head_verified_count": (
            args.final_http_head_verified_count if resource_complete else None
        ),
        "final_http_head_failure_count": (
            args.final_http_head_failure_count if resource_complete else None
        ),
        "cleanup_complete": cleanup_complete,
        "listeners_after_cleanup": (
            args.listeners_after_cleanup if cleanup_complete else None
        ),
        "exact_server_pid_present_after_cleanup": exact_pid_present,
        "matching_loopback_processes_after_cleanup": (
            args.matching_loopback_processes_after_cleanup
            if cleanup_complete
            else None
        ),
    }


def validate_repository_state() -> None:
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("rev-parse", "main") == CHECKPOINT_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
    assert git("show", "-s", "--format=%P", CHECKPOINT_COMMIT) == CHECKPOINT_PARENTS
    assert git("show", "-s", "--format=%s", CHECKPOINT_COMMIT) == (
        "merge: facility failed inspection identity"
    )
    assert git("rev-parse", "origin/main") == ORIGIN_MAIN
    assert git("rev-list", "--left-right", "--count", "origin/main...main") == (
        f"{LOCAL_MAIN_BEHIND}\t{LOCAL_MAIN_AHEAD}"
    )
    assert git("diff", "--cached", "--name-only") == ""
    assert git("diff", "--check") == ""
    status = sorted(
        git("status", "--porcelain=v1", "--untracked-files=all").splitlines()
    )
    assert status in (EXPECTED_PREOUTPUT_STATUS, EXPECTED_FINAL_STATUS), status
    assert sorted(git("diff", "--name-only").splitlines()) == sorted(
        [f"{PREFIX}/{HTML}", f"{PREFIX}/{BUILDER}"]
    )
    assert git(
        "diff",
        "--name-only",
        RUN_187_COMMIT,
        CHECKPOINT_COMMIT,
        "--",
        PREFIX,
    ) == ""
    assert sorted(
        git("diff", "--name-only", RUN_187_COMMIT, CHECKPOINT_COMMIT).splitlines()
    ) == FACILITY_PATHS
    assert (
        git(
            "diff",
            "--name-only",
            RUN_187_PARENT,
            CHECKPOINT_COMMIT,
            "--",
            *ISSUE_9_PATHS,
        )
        == ""
    )
    assert committed_record(CHECKPOINT_COMMIT, BUILDER) == COMMITTED_RUN_187_BUILDER
    assert committed_record(CHECKPOINT_COMMIT, HTML) == COMMITTED_RUN_185_DASHBOARD
    assert file_record(BUILDER) == FINAL_RUN_188_BUILDER
    assert file_record(HTML) == FINAL_RUN_188_DASHBOARD
    assert diff_record(BUILDER) == FINAL_RUN_188_BUILDER_DIFF
    assert diff_record(HTML) == FINAL_RUN_188_DASHBOARD_DIFF


def validate_run_187() -> dict[str, Any]:
    assert committed_record(RUN_187_COMMIT, RUN_187_MATERIALIZER) == (
        RUN_187_MATERIALIZER_RECORD
    )
    assert committed_record(RUN_187_COMMIT, RUN_187_RECEIPT) == (
        RUN_187_RECEIPT_RECORD
    )
    assert file_record(RUN_187_MATERIALIZER) == RUN_187_MATERIALIZER_RECORD
    assert file_record(RUN_187_RECEIPT) == RUN_187_RECEIPT_RECORD
    assert file_record(FINDINGS) == COMMITTED_FINDINGS
    run_187 = strict_json(RUN_187_RECEIPT)
    verify_self_seal(run_187, RUN_187_RECEIPT_SELF_SEAL)
    assert run_187["run_id"] == (
        "RUN-187-MON-METRIC-REPLAY-DEDUPE-01-"
        "REMEDIATION-REPORTING-WAVE-36"
    )
    assert run_187["reporting_transition"]["finding_id"] == (
        "MON-METRIC-REPLAY-DEDUPE-01"
    )
    assert run_187["reporting_transition"]["feature_id"] is None
    assert run_187["reporting_transition"]["candidate_feature_id"] is None
    assert run_187["reporting_transition"]["related_feature_ids"] == []
    assert run_187["reporting_transition"]["feature_identity_status"] == (
        "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW"
    )
    assert run_187["reporting_transition"]["counts_after"] == {
        "retained_claim_records": 15,
        "provisional_source_claims": 8,
        "historical_already_fixed": 2,
        "historical_remediated": 5,
        "final_P0": 0,
        "final_P1": 0,
    }
    assert run_187["bounded_execution_accounting"]["unique_total"] == {
        "tests": 155,
        "assertions": 2403,
    }
    assert len(run_187["completion_boundary"]) == 26
    assert all(value is False for value in run_187["completion_boundary"].values())
    assert run_187["credit_boundary"][
        "live_findings_register_and_reporting_status"
    ] is True
    assert all(
        value is False
        for key, value in run_187["credit_boundary"].items()
        if key != "live_findings_register_and_reporting_status"
    )
    assert run_187["audit_completion_test_met"] is False

    findings = strict_json(FINDINGS)
    records = findings["records"]
    statuses = Counter(record["record_status"] for record in records)
    ids = [record["id"] for record in records]
    assert len(records) == len(ids) == len(set(ids)) == 15
    assert statuses == {
        "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING": 8,
        "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING": 2,
        "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING": 5,
    }
    metric = next(
        record for record in records if record["id"] == "MON-METRIC-REPLAY-DEDUPE-01"
    )
    assert metric["feature_id"] is None
    assert metric["candidate_feature_id"] is None
    assert metric["related_feature_ids"] == []
    counts = findings["counts"]
    assert counts["retained_claim_records"] == 15
    assert counts["provisional_source_claims"] == 8
    assert counts["historical_already_fixed"] == 2
    assert counts["historical_remediated"] == 5
    assert counts["bounded_disposition_tests_passed"] == 155
    assert counts["bounded_disposition_assertions"] == 2403
    assert counts["benchmark_mapped"] == 2
    assert counts["final_no_match"] == 0
    assert counts["benchmark_unresolved"] == 338
    assert counts["static_source_feature_ownership_records"] == 666
    assert counts["static_source_feature_ownership_route_records"] == 309
    assert counts["static_source_feature_ownership_page_records"] == 357
    assert counts["static_controller_action_bridges"] == 97
    assert counts["direct_exact_queue_reviewed"] == 120
    assert counts["direct_exact_queue_pending_unreviewed"] == 387
    return run_187


def validate_builder_corrections() -> list[dict[str, Any]]:
    text = strict_text((AUDIT / BUILDER).read_bytes(), BUILDER)
    required = [
        'run_185_dashboard_payload = git_file_at_commit(',
        '"badd86d566f3354e455b92f12ab683ce6d29c965"',
        '"3c339da7e05349a7bd5cbed9ec4986e3b4871eb04d497d26078e92268a67e5e7"',
        "existing_output_bytes in (run_185_dashboard_payload, output_bytes)",
        'with_name(f".{output_path.name}.tmp-run188-dashboard")',
    ]
    assert all(value in text for value in required)
    forbidden = [
        "SUPERSEDED_PRE_REVIEW_RUN_185_DASHBOARD_SHA256",
        ".tmp-run185-dashboard",
        "existing_output_bytes in (run_182_dashboard_payload, output_bytes)",
    ]
    assert all(value not in text for value in forbidden)
    return [
        {
            "name": "verified-run185-dashboard-input-pin",
            "effect": "accept only exact badd86d5 verified RUN185 HTML as prior input",
            "credit": False,
        },
        {
            "name": "fail-closed-run188-output-guard",
            "effect": "accept only verified RUN185 input or byte-identical RUN188 output",
            "credit": False,
        },
        {
            "name": "run188-exclusive-temporary-output",
            "effect": "write through a RUN188-specific exclusive temp and atomic replace",
            "credit": False,
        },
    ]


def validate_static_dashboard() -> dict[str, Any]:
    raw = (AUDIT / HTML).read_bytes()
    text = strict_text(raw, HTML)
    parser = Parser()
    parser.feed(text)
    assert parser.headings == 26
    assert parser.sections == 26
    assert parser.tables == 10
    assert parser.table_wraps == 10
    assert len(parser.ids) == 10
    id_counts = Counter(parser.ids)
    assert sorted(key for key, count in id_counts.items() if count > 1) == []

    navigation_pairs = re.findall(r'<a href="(#[^"]+)">([^<]+)</a>', text)
    assert [(label, href) for href, label in navigation_pairs] == NAVIGATION
    hash_hrefs = [href for href in parser.hrefs if href.startswith("#")]
    assert hash_hrefs == [href for _, href in NAVIGATION]
    assert len(hash_hrefs) == len(set(hash_hrefs)) == 10
    assert sorted(
        href for href in set(hash_hrefs) if href[1:] not in id_counts
    ) == []

    local_hrefs = [href for href in parser.hrefs if is_local(href)]
    unique_local = sorted(set(local_hrefs))
    assert [
        href
        for href in parser.hrefs
        if not href.startswith("#") and not is_local(href)
    ] == []
    assert len(parser.hrefs) == 888
    assert len(local_hrefs) == 878
    assert len(unique_local) == EXPECTED_UNIQUE_LOCAL_RESOURCES
    assert text.count(f'href="{MATERIALIZER}"') == 2
    assert text.count(f'href="{OUTPUT}"') == 3
    assert MATERIALIZER in unique_local
    assert OUTPUT in unique_local
    missing_now = sorted(
        href for href in unique_local if not local_path(href).exists()
    )
    assert missing_now in ([], [OUTPUT]), missing_now

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
    assert hash_failures == []
    assert missing_hash_paths == []
    assert file_occurrences == 763
    assert len(file_paths) == 397
    assert directory_occurrences == 2
    assert directory_paths == {"task-scripts/"}
    assert sum(1 for href, _ in hash_pairs if href == MATERIALIZER) == 0
    assert sum(1 for href, _ in hash_pairs if href == OUTPUT) == 0

    builder_text = strict_text((AUDIT / BUILDER).read_bytes(), BUILDER)
    boundaries = literal_list_assignment(builder_text, "current_visible_boundaries")
    assert len(boundaries) == len(set(boundaries)) == 152
    visible_checks = {value: value in text for value in boundaries}
    assert all(visible_checks.values())

    required_live = (
        '<a href="#checkpoint">RUN-187</a>',
        "15 retained claim identities split into 8 current provisional P1, 2 historical already-fixed, and 5 historical remediated",
        "155 / 2,403",
        "MON-METRIC-REPLAY-DEDUPE-01",
        "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW",
        "only final post-corrective-merge 56/472 counted once",
        "initial 49/392 and all replays/subsets/DNS/Facility excluded",
        "quiesce old monitoring workers",
        "reconcile pending or incoherent rows",
        "apply migration 000110",
        "start new workers only after cutover reconciliation",
        "poisoned subsecond evidence requires operator reconciliation",
        "next index 85 RUN090-ROUTE-0086 / RUN077-ROUTE-0694",
        "2/340 mappings",
        "0/340 final no-match/NCM",
        "338 unresolved targets",
        "one operating organisation across multiple Sites",
        "Gate 4 and audit completion false",
        "Fresh RUN-188 audit-dashboard verification required",
    )
    required_live_checks = {value: value in text for value in required_live}
    assert all(required_live_checks.values())
    prohibited = {
        "run184_navigation": '<a href="#checkpoint">RUN-184</a>',
        "run184_current_heading": "RUN-071–184 current reporting checkpoint",
        "run184_completion_heading": "RUN-071–184 completion-gate checkpoint",
        "run184_evidence_heading": "RUN-071–184 evidence lineage",
        "stale_run185_requirement": "Fresh RUN-185 audit-dashboard verification required",
        "stale_run185_forward": (
            "RUN-185 responsive audit-dashboard verification receipt</a> "
            "(forward reference until materialized; intentionally unhashed)"
        ),
        "stale_run185_supply": (
            "None supplies audit-dashboard verification for the new RUN-185 HTML."
        ),
        "stale_run183r_current": (
            "Every current raw, generated, reviewed, and integrated RUN-077–183R"
        ),
        "incorrect_gate4": "Gate 4 and audit completion true",
        "incorrect_publication": "RUN-187 published to origin/main",
        "unreported_dns_finding": "MON-DNS-RESPONSE-BINDING-01",
        "unreported_facility_finding": "FACILITY-SIGNAL-DELIVERY-RECOVERY-01",
        "unreported_facility_identity": "FACILITY-FAILED-INSPECTION-IDENTITY-01",
        "unreported_driver_finding": "ELIG-DRIVER-LICENCE-DUTY-WINDOW-01",
    }
    prohibited_hits = {
        key: value for key, value in prohibited.items() if value in text
    }
    assert prohibited_hits == {}
    assert "tenant" not in text.lower()

    return {
        "parser": parser,
        "text": text,
        "hash_hrefs": hash_hrefs,
        "local_hrefs": local_hrefs,
        "unique_local": unique_local,
        "hash_pairs": hash_pairs,
        "file_occurrences": file_occurrences,
        "file_paths": sorted(file_paths),
        "directory_occurrences": directory_occurrences,
        "directory_paths": sorted(directory_paths),
        "visible_checks": visible_checks,
        "required_live_checks": required_live_checks,
        "prohibited_hits": prohibited_hits,
    }


def build_receipt(
    run_187: dict[str, Any],
    static: dict[str, Any],
    corrections: list[dict[str, Any]],
    finalization: dict[str, Any],
) -> dict[str, Any]:
    parser: Parser = static["parser"]
    resource_complete = finalization["resource_complete"]
    cleanup_complete = finalization["cleanup_complete"]
    status = (
        "AUDIT_DASHBOARD_RUN187_EXACT_ARTIFACT_RESPONSIVE_NAVIGATION_"
        + (
            "RESOURCE_VERIFICATION_GO"
            if resource_complete
            else "VERIFICATION_GO_FINAL_RESOURCE_HEAD_PENDING"
        )
        + "_LOCAL_ONLY_SERVER_CLEANUP_"
        + ("COMPLETE" if cleanup_complete else "PENDING")
        + "_ZERO_APPLICATION_PUBLICATION_FINAL_FINDING_GATE4_OR_AUDIT_"
        "COMPLETION_CREDIT"
    )
    navigation_results = [
        {
            "label": label,
            "href": href,
            "browser_click_performed": True,
            "resulting_hash": href,
            "target_exists": True,
            "target_visible": True,
            "pass": True,
        }
        for label, href in NAVIGATION
    ]
    viewport_results = [
        {
            "requested": viewport,
            "actual_browser_viewport": viewport,
            "visible_boundaries_required": 152,
            "visible_boundaries_passed": 152,
            "anchor_elements": 888,
            "fragment_anchors": 10,
            "browser_dom_ids": 11,
            "semantic_navigation_sections": 10,
            "table_wrappers": 10,
            "unique_local_relative_resources": 471,
            "navigation_links_visible": 10,
            "page_overflow": False,
            "duplicate_ids": False,
            "missing_fragment_targets": False,
            "table_containment_failure": False,
            "visual_inspection": (
                "GO_NO_CLIPPING_OVERLAP_OR_ILLEGIBILITY"
                if viewport in ("1440x900", "390x844")
                else "STRUCTURAL_CHECKS_GO_VISUAL_EXTREMES_REVIEWED"
            ),
            "mobile_navigation_horizontally_scrollable": (
                True if viewport == "390x844" else None
            ),
        }
        for viewport in VIEWPORTS
    ]
    completion_boundary = dict(run_187["completion_boundary"])
    completion_gates = [
        {"gate": number, "name": name, "complete": False}
        for number, name in enumerate(completion_boundary, start=1)
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
        "facility_remediation_or_execution": False,
        "driver_licence_remediation_or_execution": False,
        "it_delivery_remediation_or_execution": False,
        "benchmark_mapping": False,
        "final_no_match_or_NCM": False,
        "deployment": False,
        "ease": False,
        "final_finding": False,
        "release": False,
        "publication": False,
        "feature_or_module_completion": False,
        "gate_4": False,
        "audit_complete": False,
    }
    receipt: dict[str, Any] = {
        "schema_version": "run-188-audit-dashboard-verification-wave-36-v1",
        "run_id": RUN_ID,
        "generated_on": "2026-08-31",
        "status": status,
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
            "Exact RUN187 reporting dashboard and bounded audit-artifact "
            "verification only"
        ),
        "pins": {
            "governing_prompt_sha256": GOVERNING_PROMPT_SHA256,
            "continuation_request_sha256": CONTINUATION_REQUEST_SHA256,
            "continuation_request_is_not_governing_prompt": True,
            "run_187_reporting_commit": RUN_187_COMMIT,
            "run_187_reporting_tree": RUN_187_TREE,
            "run_187_reporting_parent": RUN_187_PARENT,
            "current_post_facility_checkpoint_commit": CHECKPOINT_COMMIT,
            "current_post_facility_checkpoint_tree": CHECKPOINT_TREE,
            "current_post_facility_checkpoint_parents": CHECKPOINT_PARENTS.split(),
            "origin_main_before_run_188_commit": ORIGIN_MAIN,
            "local_main_ahead": LOCAL_MAIN_AHEAD,
            "local_main_behind": LOCAL_MAIN_BEHIND,
            "run_187_materializer": RUN_187_MATERIALIZER_RECORD,
            "run_187_receipt": {
                **RUN_187_RECEIPT_RECORD,
                "receipt_self_seal_sha256": RUN_187_RECEIPT_SELF_SEAL,
            },
            "run_187_findings": COMMITTED_FINDINGS,
            "run_187_committed_builder": COMMITTED_RUN_187_BUILDER,
            "run_188_final_builder": FINAL_RUN_188_BUILDER,
            "run_188_builder_diff": FINAL_RUN_188_BUILDER_DIFF,
            "run_185_committed_dashboard": COMMITTED_RUN_185_DASHBOARD,
            "run_188_final_dashboard": FINAL_RUN_188_DASHBOARD,
            "run_188_dashboard_diff": FINAL_RUN_188_DASHBOARD_DIFF,
            "run_188_receipt_materializer": file_record(MATERIALIZER),
        },
        "lineage": {
            "run_185": (
                "verified only the exact now-superseded RUN184 audit dashboard"
            ),
            "run_186_and_run_186r": (
                "bounded Monitoring metric-replay corrective remediation and "
                "exact review only"
            ),
            "run_187": (
                "adds one historical-remediated record and reports 15=8+2+5 "
                "with unique bounded execution 155/2403"
            ),
            "run_188": (
                "generates from exact committed RUN187 sources and verifies "
                "only the resulting audit artifact"
            ),
            "intervening_facility_merges": {
                "issue_7_merge": "9d405a17b4c3c9581252f4ef9a5aea4e2caae7b6",
                "issue_8_merge": CHECKPOINT_COMMIT,
                "audit_path_delta": [],
                "run_188_credit": False,
            },
            "monitoring_feature_id": None,
            "monitoring_candidate_feature_id": None,
            "monitoring_related_feature_ids": [],
            "monitoring_identity_status": (
                "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW"
            ),
            "next_index_85_ownership_adjudicated": False,
        },
        "dashboard_generation": {
            "committed_builder": COMMITTED_RUN_187_BUILDER,
            "final_builder": FINAL_RUN_188_BUILDER,
            "builder_changed_by_run_188_sequence": True,
            "builder_change": FINAL_RUN_188_BUILDER_DIFF,
            "builder_execution_guard_corrections": corrections,
            "guard_failure_credit": False,
            "final_builder_runs_observed": 2,
            "final_builder_runs_byte_identical": True,
            "independent_final_source_and_html_review": {
                "result": "GO",
                "findings": [],
                "dashboard_sha256": FINAL_RUN_188_DASHBOARD["sha256"],
                "builder_sha256": FINAL_RUN_188_BUILDER["sha256"],
                "credit": "EXACT_AUDIT_DASHBOARD_ARTIFACT_ONLY",
            },
            "committed_dashboard": COMMITTED_RUN_185_DASHBOARD,
            "final_dashboard": FINAL_RUN_188_DASHBOARD,
            "dashboard_change": FINAL_RUN_188_DASHBOARD_DIFF,
            "final_validation": {
                "sha256": FINAL_RUN_188_DASHBOARD["sha256"],
                "result": "GO",
                "findings": [],
            },
            "forward_generator_is_intentionally_unhashed": True,
            "forward_receipt_is_intentionally_unhashed": True,
            "hash_cycle_present": False,
            "application_source_or_product_test_change": False,
            "credit_effect": "exact audit-dashboard artifact verification only",
        },
        "verification_method": {
            "browser": "Codex in-app browser",
            "served_from": (
                "temporary loopback-only HTTP server on 127.0.0.1:43188"
            ),
            "server_executable": (
                "C:\\Users\\steph\\.cache\\codex-runtimes\\"
                "codex-primary-runtime\\dependencies\\python\\python.exe"
            ),
            "server_command_line_suffix": (
                "-B -m http.server 43188 --bind 127.0.0.1"
            ),
            "target_url": (
                "http://127.0.0.1:43188/audit-dashboard.html?"
                "run=188&sha=3d65bd82"
            ),
            "target_document_loaded": True,
            "document_title": "Oblivion Findings current-source audit",
            "loopback_get_status": 200,
            "loopback_content_type": "text/html",
            "loopback_response_bytes": 314007,
            "loopback_response_sha256": FINAL_RUN_188_DASHBOARD["sha256"],
            "page_sandbox_fetch_status": (
                "UNAVAILABLE_IN_READ_ONLY_PAGE_SANDBOX_NOT_A_PAGE_FAILURE"
            ),
            "external_testing": False,
            "desktop_and_mobile_visual_inspection": "GO",
        },
        "verification": {
            "dashboard_builder_final_byte_identical_runs_observed": 2,
            "dashboard_builder_final_runs_byte_identical": True,
            "receipt_materializer_final_byte_identical_runs_required": 2,
            "viewports_required": 4,
            "viewports_verified": 4,
            "viewports": viewport_results,
            "exact_utf8_visible_boundary_manifest_required": 152,
            "exact_utf8_visible_boundary_manifest_passed": 152,
            "screens_visually_go": True,
            "navigation_clicks_required": 10,
            "navigation_clicks_passed": 10,
            "navigation_results": navigation_results,
            "console_warning_entries": 0,
            "console_error_entries": 0,
            "uncaught_page_error_entries": None,
            "browser_dev_log_entries": 0,
            "unreported_dev_log_fields_fabricated": False,
            "authored_ids": len(parser.ids),
            "browser_dom_ids": 11,
            "browser_only_injected_id_count": 1,
            "duplicate_authored_ids": [],
            "heading_elements": parser.headings,
            "section_elements": parser.sections,
            "semantic_navigation_sections": 10,
            "table_elements": parser.tables,
            "table_wrappers": parser.table_wraps,
            "anchor_elements": len(parser.hrefs),
            "anchor_elements_rendered_in_browser": 888,
            "hash_anchor_occurrences": len(static["hash_hrefs"]),
            "unique_hash_anchors": len(set(static["hash_hrefs"])),
            "missing_hash_targets": [],
            "local_resource_link_occurrences": len(static["local_hrefs"]),
            "unique_local_resources": len(static["unique_local"]),
            "pre_materialization_resource_diagnostic": {
                "filesystem_and_http_head_200": "469/471",
                "expected_missing_future_paths": FUTURE_LINKS,
                "credit": False,
            },
            "post_materialization_filesystem_resources": "471/471",
            "post_materialization_filesystem_failures": [],
            "post_materialization_http_head_resources": (
                "471/471"
                if resource_complete
                else "PENDING_ROOT_LOOPBACK_HTTP_HEAD_REPLAY"
            ),
            "post_materialization_http_head_failures": (
                [] if resource_complete else None
            ),
            "post_materialization_http_head_finalized": resource_complete,
            "hash_bearing_file_occurrences_verified": static["file_occurrences"],
            "unique_hash_bearing_file_paths_verified": len(static["file_paths"]),
            "historical_directory_bundle_digest_occurrences": static[
                "directory_occurrences"
            ],
            "historical_directory_bundle_digest_paths": static["directory_paths"],
            "hash_bearing_link_failures": [],
            "run_188_generator_link_occurrences": static["text"].count(
                f'href="{MATERIALIZER}"'
            ),
            "run_188_generator_link_adjacent_hash_occurrences": sum(
                1 for href, _ in static["hash_pairs"] if href == MATERIALIZER
            ),
            "run_188_forward_receipt_link_occurrences": static["text"].count(
                f'href="{OUTPUT}"'
            ),
            "run_188_forward_receipt_link_adjacent_hash_occurrences": sum(
                1 for href, _ in static["hash_pairs"] if href == OUTPUT
            ),
            "visible_static_checks_required": len(static["visible_checks"]),
            "visible_static_checks_passed": sum(
                static["visible_checks"].values()
            ),
            "visible_static_checks": static["visible_checks"],
            "required_live_checks": static["required_live_checks"],
            "prohibited_visible_phrase_hits": static["prohibited_hits"],
            "single_organisation_multi_site_wording_present": True,
            "tenant_word_present": False,
        },
        "reported_finding_boundary": {
            "retained_claim_records": 15,
            "current_provisional_source_claims": 8,
            "historical_already_fixed_records": 2,
            "historical_remediated_records": 5,
            "final_P0": 0,
            "final_P1": 0,
            "changed_by_run_188": False,
        },
        "bounded_execution_accounting": {
            "unique_tests": 155,
            "unique_assertions": 2403,
            "changed_by_run_188": False,
            "executed_by_run_188": False,
            "initial_monitoring_49_392_credit": False,
            "replay_subset_dns_facility_or_driver_recredit": False,
        },
        "monitoring_identity_and_deployment_boundary": {
            "finding_id": "MON-METRIC-REPLAY-DEDUPE-01",
            "feature_id": None,
            "candidate_feature_id": None,
            "related_feature_ids": [],
            "feature_identity_status": (
                "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW"
            ),
            "static_ownership_credit": False,
            "option_a_prerequisite_verified": False,
            "quiesce_old_monitoring_workers": False,
            "reconcile_pending_or_incoherent_rows": False,
            "apply_migration_000110": False,
            "start_new_workers_after_cutover_reconciliation": False,
            "poisoned_subsecond_operator_reconciliation_complete": False,
            "deployment_credit": False,
        },
        "static_ownership_boundary": {
            "owner_records": 666,
            "route_owners": 309,
            "page_owners": 357,
            "action_bridges": 97,
            "source_denominator": 3929,
            "source_ownership_percent": "16.950878",
            "source_residual_records": 3263,
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
            "changed_by_run_188": False,
            "correctness_credit": False,
        },
        "benchmark_boundary": {
            "mapped": 2,
            "total": 340,
            "final_no_match_or_NCM": 0,
            "unresolved": 338,
            "changed_by_run_188": False,
        },
        "noninheritance_boundary": {
            "builder_guard_correction_credit": False,
            "run_187_reporting_recredited": False,
            "run_186_application_remediation_recredited": False,
            "intervening_facility_merges_recredited": False,
            "sealed_driver_licence_issue_recredited": False,
            "reserved_it_delivery_issue_recredited": False,
            "static_route_feature_ownership": False,
            "static_controller_action_bridge": False,
            "application_source_or_product_test": False,
            "application_runtime_reexecution": False,
            "application_browser": False,
            "executed_product_tests": False,
            "queue_advance": False,
            "benchmark_mapping_or_final_no_match_NCM": False,
            "deployment": False,
            "ease": False,
            "final_finding": False,
            "feature_module_or_pass_completion": False,
            "release_publication_gate4_or_audit_completion": False,
        },
        "root_browser_resource_cleanup": {
            "browser_viewport_override_reset": True,
            "agent_created_tab_closed": True,
            "remaining_controlled_tabs": 0,
            "temporary_loopback_port": 43188,
            "temporary_server_pid": 36704,
            "temporary_server_executable": (
                "C:\\Users\\steph\\.cache\\codex-runtimes\\"
                "codex-primary-runtime\\dependencies\\python\\python.exe"
            ),
            "listeners_after_cleanup": finalization["listeners_after_cleanup"],
            "exact_pid_present_after_cleanup": finalization[
                "exact_server_pid_present_after_cleanup"
            ],
            "matching_loopback_server_processes_after_cleanup": finalization[
                "matching_loopback_processes_after_cleanup"
            ],
            "cleanup_finalized": cleanup_complete,
            "pending_fields_if_not_finalized": (
                []
                if cleanup_complete
                else [
                    "listeners_after_cleanup",
                    "exact_pid_present_after_cleanup",
                    "matching_loopback_server_processes_after_cleanup",
                ]
            ),
        },
        "worktree_boundary": {
            "expected_final_status_count": 4,
            "expected_final_porcelain_statuses": EXPECTED_FINAL_STATUS,
            "no_staged_paths": True,
            "git_diff_check_clean": True,
            "exact_match_required": True,
        },
        "mutation_attestation": {
            "sequence_paths": [BUILDER, HTML, MATERIALIZER, OUTPUT],
            "receipt_materializer_persistent_write_scope": [OUTPUT],
            "builder_changed_before_run_188_materializer": True,
            "audit_dashboard_html_changed_before_run_188_materializer": True,
            "application_paths_changed": [],
            "product_test_paths_changed": [],
            "findings_register_changed_by_run_188": False,
            "run_187_reporting_surfaces_changed_by_run_188": False,
            "forms_submitted": False,
            "records_opened": False,
            "screenshots_retained": False,
            "database_changed": False,
            "application_tests_or_build_run_by_materializer": False,
        },
        "reserved_application_path_boundaries": {
            "issue_9_paths": ISSUE_9_PATHS,
            "issue_10_paths": ISSUE_10_PATHS,
            "run_188_changes_or_credit": False,
        },
        "remote_state_boundary": {
            "origin_main_before_run_188_commit": ORIGIN_MAIN,
            "local_main_ahead_before_run_188_commit": LOCAL_MAIN_AHEAD,
            "local_main_behind_before_run_188_commit": LOCAL_MAIN_BEHIND,
            "application_merges_remain_local_only": True,
            "push_or_publication_performed_by_materializer": False,
            "publication_claim": False,
        },
        "credit_boundary": credit,
        "completion_gates": completion_gates,
        "completion_boundary": completion_boundary,
        "artifact_completion_scope": [BUILDER, HTML, MATERIALIZER, OUTPUT],
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "run_188_sequence_written_paths": [
            f"{PREFIX}/{BUILDER}",
            f"{PREFIX}/{HTML}",
            f"{PREFIX}/{MATERIALIZER}",
            f"{PREFIX}/{OUTPUT}",
        ],
        "root_finalization_required": {
            "post_materialization_http_head": not resource_complete,
            "server_cleanup": not cleanup_complete,
            "receipt_materializer_arguments": {
                "final_http_head_verified_count": finalization[
                    "final_http_head_verified_count"
                ],
                "final_http_head_failure_count": finalization[
                    "final_http_head_failure_count"
                ],
                "listeners_after_cleanup": finalization["listeners_after_cleanup"],
                "exact_server_pid_present_after_cleanup": finalization[
                    "exact_server_pid_present_after_cleanup"
                ],
                "matching_loopback_processes_after_cleanup": finalization[
                    "matching_loopback_processes_after_cleanup"
                ],
            },
        },
    }
    assert {key for key, value in credit.items() if value} == {
        "exact_audit_dashboard_artifact"
    }
    assert len(completion_gates) == 26
    assert [row["gate"] for row in completion_gates] == list(range(1, 27))
    assert all(row["complete"] is False for row in completion_gates)
    assert len(completion_boundary) == 26
    assert all(value is False for value in completion_boundary.values())
    assert all(
        value is False for value in receipt["noninheritance_boundary"].values()
    )
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    assert_finite(receipt)
    return receipt


def validate_receipt(receipt: dict[str, Any]) -> None:
    copy = dict(receipt)
    seal = copy.pop("receipt_self_seal_sha256")
    assert seal == canonical_sha256(copy)
    assert receipt["reported_finding_boundary"] == {
        "retained_claim_records": 15,
        "current_provisional_source_claims": 8,
        "historical_already_fixed_records": 2,
        "historical_remediated_records": 5,
        "final_P0": 0,
        "final_P1": 0,
        "changed_by_run_188": False,
    }
    assert receipt["bounded_execution_accounting"]["unique_tests"] == 155
    assert receipt["bounded_execution_accounting"]["unique_assertions"] == 2403
    assert receipt["benchmark_boundary"] == {
        "mapped": 2,
        "total": 340,
        "final_no_match_or_NCM": 0,
        "unresolved": 338,
        "changed_by_run_188": False,
    }
    assert receipt["verification"]["visible_static_checks_required"] == 152
    assert receipt["verification"]["visible_static_checks_passed"] == 152
    assert receipt["verification"]["anchor_elements"] == 888
    assert receipt["verification"]["unique_local_resources"] == 471
    assert receipt["verification"]["navigation_clicks_passed"] == 10
    assert receipt["verification"]["console_warning_entries"] == 0
    assert receipt["verification"]["console_error_entries"] == 0
    assert receipt["verification"]["browser_dev_log_entries"] == 0
    assert receipt["static_ownership_boundary"]["next_zero_based_index"] == 85
    assert receipt["monitoring_identity_and_deployment_boundary"][
        "option_a_prerequisite_verified"
    ] is False
    assert len(receipt["completion_gates"]) == 26
    assert all(row["complete"] is False for row in receipt["completion_gates"])
    assert all(value is False for value in receipt["completion_boundary"].values())
    assert receipt["credit_boundary"]["exact_audit_dashboard_artifact"] is True
    assert receipt["credit_boundary"]["application_browser"] is False
    assert receipt["artifact_completion_test_met"] is True
    assert receipt["audit_completion_test_met"] is False


def main() -> None:
    args = parse_args()
    finalization = finalization_inputs(args)
    validate_repository_state()
    run_187 = validate_run_187()
    corrections = validate_builder_corrections()
    static = validate_static_dashboard()
    receipt = build_receipt(run_187, static, corrections, finalization)
    validate_receipt(receipt)
    encoded = (
        json.dumps(receipt, ensure_ascii=False, indent=2, allow_nan=False) + "\n"
    ).encode("utf-8")
    output_path = AUDIT / OUTPUT
    output_path.write_bytes(encoded)
    assert output_path.read_bytes() == encoded
    assert all(local_path(href).exists() for href in static["unique_local"])
    reloaded = strict_json(OUTPUT)
    assert reloaded == receipt
    validate_receipt(reloaded)
    validate_repository_state()
    print(
        json.dumps(
            {
                "run_id": receipt["run_id"],
                "status": receipt["status"],
                "dashboard_sha256": FINAL_RUN_188_DASHBOARD["sha256"],
                "builder_sha256": FINAL_RUN_188_BUILDER["sha256"],
                "materializer_sha256": file_record(MATERIALIZER)["sha256"],
                "receipt_sha256": sha256(encoded),
                "receipt_self_seal_sha256": receipt[
                    "receipt_self_seal_sha256"
                ],
                "visible_checks": "152/152",
                "navigation": "10/10",
                "viewports": "4/4",
                "unique_local_resources": len(static["unique_local"]),
                "final_http_head": (
                    "471/471" if finalization["resource_complete"] else "PENDING"
                ),
                "server_cleanup": (
                    "COMPLETE" if finalization["cleanup_complete"] else "PENDING"
                ),
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
