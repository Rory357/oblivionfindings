#!/usr/bin/env python3
"""Seal bounded RUN185 facts for the exact RUN184 audit dashboard.

The committed RUN184 reporting inputs, corrected builder, generated HTML,
reported browser observations, and local resource graph are validated from
exact bytes. Final loopback HTTP HEAD and server-cleanup fields remain
fail-closed until root supplies explicit command-line attestations. This
producer writes only its paired receipt and grants no application, runtime,
product-test, benchmark, NCM, finding, release, publication, Gate 4, feature,
module, or audit-completion credit.
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
    "current-audit-dashboard-verification-run-185-wave-35.json"
)
HTML = "audit-dashboard.html"
BUILDER = "generators/build-current-audit-dashboard.py"
FINDINGS = "findings.json"
RUN_184_MATERIALIZER = (
    "generators/materialize-run-184-fleet-trip-playback-site-privacy-"
    "remediation-reporting-wave-35.py"
)
RUN_184_RECEIPT = (
    "evidence/source/current-run-184-fleet-trip-playback-site-privacy-"
    "remediation-reporting-wave-35.json"
)

RUN_ID = "RUN-185-AUDIT-DASHBOARD-VERIFICATION-WAVE-35"
CHECKPOINT_COMMIT = "15b2c988f4bb7f737727cc777ab32ad771c4be06"
CHECKPOINT_TREE = "b71caa8f2a0b616db92fd3058c8f8cbc014e0459"
CHECKPOINT_PARENT = "a900f078c9c05f587f6f7884f5fe715076891416"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"
LOCAL_MAIN_AHEAD = 31
LOCAL_MAIN_BEHIND = 0
GOVERNING_PROMPT_SHA256 = (
    "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
)
CONTINUATION_REQUEST_SHA256 = (
    "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d"
)

RUN_184_MATERIALIZER_RECORD = {
    "path": RUN_184_MATERIALIZER,
    "sha256": "a68c61a72b7a6d956c776bbe5aca3e3ed421a4602328364c96e3ea9557258156",
    "git_blob_id": "0355a8d8abcf2cbe0af2b1a8f9d0c76837650df8",
    "bytes": 31599,
    "lines": 595,
}
RUN_184_RECEIPT_RECORD = {
    "path": RUN_184_RECEIPT,
    "sha256": "c01d56df5512183ac8363c58ea73af4abf504ef3bc956967b43b15929d5e84e0",
    "git_blob_id": "1a07f1e3e20c8adfbd2dee893553ed9bcc0e5c92",
    "bytes": 17255,
    "lines": 396,
}
RUN_184_RECEIPT_SELF_SEAL = (
    "d798f2ee3bae70539ad764dcc0204f32146012c0c0d82424651419908f0b6aac"
)
COMMITTED_FINDINGS = {
    "path": FINDINGS,
    "sha256": "28622b14799477cfa37069bffd16f500f429ff5013ac418ed75394486cb24bc3",
    "git_blob_id": "00fc8cc934bae3cc1b7d830f4dbc5bf74b679e0b",
    "bytes": 610120,
    "lines": 10824,
}
COMMITTED_RUN_184_BUILDER = {
    "path": BUILDER,
    "sha256": "9d4b3b1a197bd231da931781190b8ddde26367f7f7013ae17573ad5d12723975",
    "git_blob_id": "4bab68986c1928ea8c468ad5f724ad62d0f0cc0e",
    "bytes": 667421,
    "lines": 5816,
}
FINAL_RUN_185_BUILDER = {
    "path": BUILDER,
    "sha256": "c050ce639b0523e1864ddd861f88fdac6af6545666798605362f81e6b2763fe2",
    "git_blob_id": "7b8b48550fe06d4d5f886ce032e9dfd8cefb8531",
    "bytes": 676644,
    "lines": 5883,
}
FINAL_RUN_185_BUILDER_DIFF = {
    "path": BUILDER,
    "binary_diff_sha256": (
        "2652dbd904aa3dc7b5f5bb9b891d6573d81ff230329ec50ea0708d5090089c73"
    ),
    "numstat": {"added": 73, "deleted": 6},
}
COMMITTED_RUN_182_DASHBOARD = {
    "path": HTML,
    "sha256": "8779848cc1a95ef82f2c5eba1a542e5a4784559f75ef20e9eb06696abde56457",
    "git_blob_id": "eba39723cdd892249714dc32d9589b718593b24f",
    "bytes": 296602,
    "lines": 78,
}
FINAL_RUN_185_DASHBOARD = {
    "path": HTML,
    "sha256": "3c339da7e05349a7bd5cbed9ec4986e3b4871eb04d497d26078e92268a67e5e7",
    "git_blob_id": "35e98d2f17e081eb01ec51de1429e9eab1208697",
    "bytes": 304332,
    "lines": 78,
}
FINAL_RUN_185_DASHBOARD_DIFF = {
    "path": HTML,
    "binary_diff_sha256": (
        "dd09a57930c0bc6cd80a316a1d09e4dcf8494c80a6efd25bdac5c0ff2616c128"
    ),
    "numstat": {"added": 18, "deleted": 18},
}
SUPPLIED_STALE_BUILDER_CANDIDATE_SHA256 = (
    "6691fe0fa68051817d565cfddf5cf0b3fab05ddd4d415172235f8aaad827c2d2"
)
SUPERSEDED_DASHBOARDS = [
    {
        "sha256": (
            "07168e6b686eb0c976b18391e53979db2c605c7f9901bfdb73f4bf792c3b791c"
        ),
        "result": "NO_GO",
        "credit": False,
        "reason": (
            "independent review found RUN174 current-tense lineage and stale "
            "ownership or contributor-footer contradictions"
        ),
    },
    {
        "sha256": (
            "b1c93c817244512e21e6e322cbec6617b87aed08fe52c268c292ef0bc53a812b"
        ),
        "result": "NO_GO",
        "credit": False,
        "reason": (
            "follow-up review found the historical RUN171 12-record clause "
            "leaking the current 8+2+4 split"
        ),
    },
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
    ("RUN-184", "#checkpoint"),
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
EXPECTED_UNIQUE_LOCAL_RESOURCES = 463


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


def committed_file_record(relative: str) -> dict[str, Any]:
    raw = git_bytes(CHECKPOINT_COMMIT, f"{PREFIX}/{relative}")
    strict_text(raw, f"{CHECKPOINT_COMMIT}:{relative}")
    return {
        "path": relative,
        "sha256": sha256(raw),
        "git_blob_id": git(
            "rev-parse",
            f"{CHECKPOINT_COMMIT}:{PREFIX}/{relative}",
        ),
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
        exact_pid_present = (
            args.exact_server_pid_present_after_cleanup == "true"
        )
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
    assert git("show", "-s", "--format=%P", CHECKPOINT_COMMIT) == (
        CHECKPOINT_PARENT
    )
    assert git("show", "-s", "--format=%s", CHECKPOINT_COMMIT) == (
        "audit: report RUN184 Fleet playback remediation"
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
    assert git("diff", "--name-only").splitlines() == [
        f"{PREFIX}/{HTML}",
        f"{PREFIX}/{BUILDER}",
    ]
    assert committed_file_record(BUILDER) == COMMITTED_RUN_184_BUILDER
    assert committed_file_record(HTML) == COMMITTED_RUN_182_DASHBOARD
    assert file_record(BUILDER) == FINAL_RUN_185_BUILDER
    assert file_record(HTML) == FINAL_RUN_185_DASHBOARD
    assert diff_record(BUILDER) == FINAL_RUN_185_BUILDER_DIFF
    assert diff_record(HTML) == FINAL_RUN_185_DASHBOARD_DIFF
    assert FINAL_RUN_185_BUILDER["sha256"] != (
        SUPPLIED_STALE_BUILDER_CANDIDATE_SHA256
    )


def validate_run_184() -> dict[str, Any]:
    assert committed_file_record(RUN_184_MATERIALIZER) == RUN_184_MATERIALIZER_RECORD
    assert committed_file_record(RUN_184_RECEIPT) == RUN_184_RECEIPT_RECORD
    assert file_record(RUN_184_MATERIALIZER) == RUN_184_MATERIALIZER_RECORD
    assert file_record(RUN_184_RECEIPT) == RUN_184_RECEIPT_RECORD
    assert file_record(FINDINGS) == COMMITTED_FINDINGS
    run_184 = strict_json(RUN_184_RECEIPT)
    verify_self_seal(run_184, RUN_184_RECEIPT_SELF_SEAL)
    assert run_184["run_id"] == (
        "RUN-184-FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01-"
        "REMEDIATION-REPORTING-WAVE-35"
    )
    assert run_184["reporting_transition"]["finding_id"] == (
        "FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01"
    )
    assert run_184["reporting_transition"]["candidate_feature_id"] == (
        "CAP-FLEET-VEHICLE-REGISTER"
    )
    assert run_184["reporting_transition"]["feature_identity_status"] == (
        "PENDING_FRESH_SEMANTIC_REVIEW"
    )
    assert run_184["reporting_transition"]["counts_after"] == {
        "retained_claim_records": 14,
        "provisional_source_claims": 8,
        "historical_already_fixed": 2,
        "historical_remediated": 4,
        "final_P0": 0,
        "final_P1": 0,
    }
    assert run_184["pins"]["current_findings"] == COMMITTED_FINDINGS
    assert run_184["pins"]["dashboard_builder"] == COMMITTED_RUN_184_BUILDER
    assert run_184["pins"]["unchanged_run_182_dashboard"] == (
        COMMITTED_RUN_182_DASHBOARD
    )
    assert len(run_184["completion_boundary"]) == 26
    assert all(value is False for value in run_184["completion_boundary"].values())
    assert run_184["credit_boundary"][
        "live_findings_register_and_reporting_status"
    ] is True
    assert all(
        value is False
        for key, value in run_184["credit_boundary"].items()
        if key != "live_findings_register_and_reporting_status"
    )
    assert run_184["audit_completion_test_met"] is False

    findings = strict_json(FINDINGS)
    records = findings["records"]
    statuses = Counter(record["record_status"] for record in records)
    ids = [record["id"] for record in records]
    assert len(records) == len(ids) == len(set(ids)) == 14
    assert statuses == {
        "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING": 8,
        "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING": 2,
        "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING": 4,
    }
    counts = findings["counts"]
    assert counts["retained_claim_records"] == 14
    assert counts["provisional_source_claims"] == 8
    assert counts["historical_already_fixed"] == 2
    assert counts["historical_remediated"] == 4
    assert counts["bounded_disposition_tests_passed"] == 99
    assert counts["bounded_disposition_assertions"] == 1931
    assert counts["benchmark_mapped"] == 2
    assert counts["final_no_match"] == 0
    assert counts["benchmark_unresolved"] == 338
    assert counts["static_source_feature_ownership_records"] == 666
    assert counts["static_source_feature_ownership_route_records"] == 309
    assert counts["static_source_feature_ownership_page_records"] == 357
    assert counts["static_controller_action_bridges"] == 97
    assert counts["direct_exact_queue_reviewed"] == 120
    assert counts["direct_exact_queue_pending_unreviewed"] == 387
    return run_184


def validate_builder_corrections() -> list[dict[str, Any]]:
    text = strict_text((AUDIT / BUILDER).read_bytes(), BUILDER)
    required = [
        'RUN_184_REPORTING_COMMIT = "15b2c988f4bb7f737727cc777ab32ad771c4be06"',
        '"07168e6b686eb0c976b18391e53979db2c605c7f9901bfdb73f4bf792c3b791c"',
        '"b1c93c817244512e21e6e322cbec6617b87aed08fe52c268c292ef0bc53a812b"',
        '[gate["gate"] for gate in run_183_remediation["completion_gates"]]',
        'not any(gate["complete"] for gate in run_183_remediation["completion_gates"])',
        '[gate["gate"] for gate in run_183r_review["completion_gates"]]',
        'not any(gate["complete"] for gate in run_183r_review["completion_gates"])',
        "run_184_builder_payload = git_file_at_commit(",
        "historical RUN-171 checkpoint of 12 retained claim identities",
        "historical 8 provisional + 2 already-fixed + 3 remediated",
        "index 84 integrated · next index 85 RUN090-ROUTE-0086 / RUN077-ROUTE-0694",
        "RUN-183 adds one post-merge 11/167 Fleet playback execution",
        "RUN-071–184 current reporting checkpoint:",
        "Generated deterministically from independently reviewed static",
        "SUPERSEDED_PRE_REVIEW_RUN_185_DASHBOARD_SHA256",
    ]
    assert all(value in text for value in required)
    corrections = [
        {
            "name": "run183-and-run183r-list-shaped-completion-gates",
            "effect": "validate IDs 1 through 26 and each explicit complete=false",
            "credit": False,
        },
        {
            "name": "committed-run184-builder-byte-pin",
            "effect": "read RUN184 builder bytes at exact commit 15b2c988",
            "credit": False,
        },
        {
            "name": "frozen-run177-88-1764-history",
            "effect": "prevent current 99/1931 values from leaking into RUN177",
            "credit": False,
        },
        {
            "name": "run181-next-index-identities",
            "effect": "retain index 85 RUN090-ROUTE-0086 and RUN077-ROUTE-0694",
            "credit": False,
        },
        {
            "name": "run183-playback-boundary-wording",
            "effect": "identify the one post-merge 11/167 Fleet playback increment",
            "credit": False,
        },
        {
            "name": "qualified-index84-index85-ownership-history",
            "effect": "separate index 84 owner credit from pending index 85 correctness",
            "credit": False,
        },
        {
            "name": "current-contributor-and-footer-lineage",
            "effect": "report through RUN184 without stale RUN174 or RUN171 current tense",
            "credit": False,
        },
        {
            "name": "two-hash-superseded-output-guard",
            "effect": "permit only the two explicitly rejected RUN185 HTML inputs",
            "credit": False,
        },
    ]
    assert len(corrections) == 8
    return corrections


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
    duplicate_ids = sorted(key for key, count in id_counts.items() if count > 1)
    assert duplicate_ids == []

    navigation_pairs = re.findall(
        r'<a href="(#[^"]+)">([^<]+)</a>',
        text,
    )
    assert [(label, href) for href, label in navigation_pairs] == NAVIGATION
    hash_hrefs = [href for href in parser.hrefs if href.startswith("#")]
    assert hash_hrefs == [href for _, href in NAVIGATION]
    assert len(hash_hrefs) == len(set(hash_hrefs)) == 10
    missing_hash_targets = sorted(
        href for href in set(hash_hrefs) if href[1:] not in id_counts
    )
    assert missing_hash_targets == []

    local_hrefs = [href for href in parser.hrefs if is_local(href)]
    unique_local = sorted(set(local_hrefs))
    external_or_empty = [
        href
        for href in parser.hrefs
        if not href.startswith("#") and not is_local(href)
    ]
    assert external_or_empty == []
    assert len(parser.hrefs) == 868
    assert len(local_hrefs) == 858
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
    assert directory_paths == {"task-scripts/"}
    assert sum(1 for href, _ in hash_pairs if href == MATERIALIZER) == 0
    assert sum(1 for href, _ in hash_pairs if href == OUTPUT) == 0

    builder_text = strict_text((AUDIT / BUILDER).read_bytes(), BUILDER)
    boundaries = literal_list_assignment(
        builder_text,
        "current_visible_boundaries",
    )
    assert len(boundaries) == len(set(boundaries)) == 117
    visible_checks = {value: value in text for value in boundaries}
    assert all(visible_checks.values()), [
        value for value, present in visible_checks.items() if not present
    ]
    required_live = (
        '<a href="#checkpoint">RUN-184</a>',
        "14 retained claim identities split into 8 current provisional P1, 2 historical already-fixed, and 4 historical remediated",
        "99 / 1,931",
        "FLEET-TRIP-PLAYBACK-SITE-PRIVACY-01",
        "RUN-183 adds one post-merge 11/167 Fleet playback execution",
        "supporting 20/215",
        "CAP-FLEET-VEHICLE-REGISTER",
        "index 84 integrated · next index 85 RUN090-ROUTE-0086 / RUN077-ROUTE-0694",
        "2/340 mappings",
        "0/340 final no-match/NCM",
        "338 unresolved targets",
        "one operating organisation across multiple Sites",
        "Gate 4 and audit completion false",
        "Fresh RUN-185 audit-dashboard verification required",
    )
    required_live_checks = {value: value in text for value in required_live}
    assert all(required_live_checks.values())
    prohibited = {
        "hybrid_run171_count": (
            "12 retained claim identities (8 current provisional + "
            "2 historical already-fixed + 4 historical remediated)"
        ),
        "stale_index84_unresolved": "index 84 fleet-assets.trips.index unresolved",
        "incorrect_current_split": (
            "14 retained claim identities split into 9 current provisional P1"
        ),
        "incorrect_gate4": "Gate 4 and audit completion true",
        "incorrect_publication": "RUN-184 published to origin/main",
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
    run_184: dict[str, Any],
    static: dict[str, Any],
    corrections: list[dict[str, Any]],
    finalization: dict[str, Any],
) -> dict[str, Any]:
    parser: Parser = static["parser"]
    resource_complete = finalization["resource_complete"]
    cleanup_complete = finalization["cleanup_complete"]
    status = (
        "AUDIT_DASHBOARD_RUN184_EXACT_ARTIFACT_RESPONSIVE_NAVIGATION_"
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
            "visible_boundaries_required": 117,
            "visible_boundaries_passed": 117,
            "anchor_elements": 868,
            "fragment_anchors": 10,
            "browser_dom_ids": 11,
            "semantic_navigation_sections": 10,
            "table_wrappers": 10,
            "unique_local_relative_resources": 463,
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
    completion_boundary = dict(run_184["completion_boundary"])
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
    receipt: dict[str, Any] = {
        "schema_version": "run-185-audit-dashboard-verification-wave-35-v1",
        "run_id": RUN_ID,
        "generated_on": "2026-08-30",
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
            "Exact RUN184 reporting dashboard and bounded audit-artifact "
            "verification only"
        ),
        "pins": {
            "governing_prompt_sha256": GOVERNING_PROMPT_SHA256,
            "continuation_request_sha256": CONTINUATION_REQUEST_SHA256,
            "continuation_request_is_not_governing_prompt": True,
            "run_184_checkpoint_commit": CHECKPOINT_COMMIT,
            "run_184_checkpoint_tree": CHECKPOINT_TREE,
            "run_184_checkpoint_parent": CHECKPOINT_PARENT,
            "origin_main_before_run_185_commit": ORIGIN_MAIN,
            "local_main_ahead": LOCAL_MAIN_AHEAD,
            "local_main_behind": LOCAL_MAIN_BEHIND,
            "run_184_materializer": RUN_184_MATERIALIZER_RECORD,
            "run_184_receipt": {
                **RUN_184_RECEIPT_RECORD,
                "receipt_self_seal_sha256": RUN_184_RECEIPT_SELF_SEAL,
            },
            "run_184_findings": COMMITTED_FINDINGS,
            "run_184_committed_builder": COMMITTED_RUN_184_BUILDER,
            "run_185_final_builder": FINAL_RUN_185_BUILDER,
            "run_185_builder_diff": FINAL_RUN_185_BUILDER_DIFF,
            "run_182_committed_dashboard": COMMITTED_RUN_182_DASHBOARD,
            "run_185_final_dashboard": FINAL_RUN_185_DASHBOARD,
            "run_185_dashboard_diff": FINAL_RUN_185_DASHBOARD_DIFF,
            "run_185_receipt_materializer": file_record(MATERIALIZER),
            "supplied_stale_builder_candidate_sha256": (
                SUPPLIED_STALE_BUILDER_CANDIDATE_SHA256
            ),
            "supplied_stale_builder_candidate_matches_final": False,
        },
        "lineage": {
            "run_183_and_run_183r": (
                "bounded Fleet playback remediation and exact review only"
            ),
            "run_184": (
                "adds one historical-remediated record and reports 14=8+2+4 "
                "with unique bounded execution 99/1931"
            ),
            "run_185": (
                "generates from exact committed RUN184 sources and verifies "
                "only the resulting audit artifact"
            ),
            "candidate_feature_association": "CAP-FLEET-VEHICLE-REGISTER",
            "candidate_feature_identity_status": "PENDING_FRESH_SEMANTIC_REVIEW",
            "index_84_static_owner_recredited": False,
            "index_85_static_ownership_adjudicated": False,
        },
        "dashboard_generation": {
            "committed_builder": COMMITTED_RUN_184_BUILDER,
            "final_builder": FINAL_RUN_185_BUILDER,
            "builder_changed_by_run_185_sequence": True,
            "builder_change": FINAL_RUN_185_BUILDER_DIFF,
            "builder_execution_guard_corrections": corrections,
            "guard_failure_credit": False,
            "final_builder_runs_observed": 2,
            "final_builder_runs_byte_identical": True,
            "independent_final_source_and_html_review": {
                "result": "GO",
                "findings": [],
                "dashboard_sha256": FINAL_RUN_185_DASHBOARD["sha256"],
                "builder_sha256": FINAL_RUN_185_BUILDER["sha256"],
                "credit": "EXACT_AUDIT_DASHBOARD_ARTIFACT_ONLY",
            },
            "committed_dashboard": COMMITTED_RUN_182_DASHBOARD,
            "final_dashboard": FINAL_RUN_185_DASHBOARD,
            "dashboard_change": FINAL_RUN_185_DASHBOARD_DIFF,
            "superseded_validation_history": SUPERSEDED_DASHBOARDS,
            "final_validation": {
                "sha256": FINAL_RUN_185_DASHBOARD["sha256"],
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
                "temporary loopback-only HTTP server on 127.0.0.1:43185"
            ),
            "server_executable": (
                "C:\\Users\\steph\\.cache\\codex-runtimes\\"
                "codex-primary-runtime\\dependencies\\python\\python.exe"
            ),
            "server_command_line_suffix": (
                "-B -m http.server 43185 --bind 127.0.0.1"
            ),
            "target_url": (
                "http://127.0.0.1:43185/audit-dashboard.html?"
                "run=185&sha=3c339da7"
            ),
            "target_document_loaded": True,
            "document_title": "Oblivion Findings current-source audit",
            "loopback_get_status": 200,
            "loopback_content_type": "text/html",
            "loopback_response_bytes": 304332,
            "loopback_response_sha256": FINAL_RUN_185_DASHBOARD["sha256"],
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
            "exact_utf8_visible_boundary_manifest_required": 117,
            "exact_utf8_visible_boundary_manifest_passed": 117,
            "screens_visually_go": True,
            "navigation_clicks_required": 10,
            "navigation_clicks_passed": 10,
            "navigation_results": navigation_results,
            "console_warning_entries": 0,
            "console_error_entries": 0,
            "uncaught_page_error_entries": None,
            "browser_dev_log_entries": None,
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
            "anchor_elements_rendered_in_browser": 868,
            "hash_anchor_occurrences": len(static["hash_hrefs"]),
            "unique_hash_anchors": len(set(static["hash_hrefs"])),
            "missing_hash_targets": [],
            "local_resource_link_occurrences": len(static["local_hrefs"]),
            "unique_local_resources": len(static["unique_local"]),
            "pre_materialization_resource_diagnostic": {
                "filesystem_and_http_head_200": "461/463",
                "expected_missing_future_paths": FUTURE_LINKS,
                "credit": False,
            },
            "post_materialization_filesystem_resources": "463/463",
            "post_materialization_filesystem_failures": [],
            "post_materialization_http_head_resources": (
                "463/463"
                if resource_complete
                else "PENDING_ROOT_LOOPBACK_HTTP_HEAD_REPLAY"
            ),
            "post_materialization_http_head_failures": (
                [] if resource_complete else None
            ),
            "post_materialization_http_head_finalized": resource_complete,
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
            "run_185_generator_link_occurrences": static["text"].count(
                f'href="{MATERIALIZER}"'
            ),
            "run_185_generator_link_adjacent_hash_occurrences": sum(
                1
                for href, _ in static["hash_pairs"]
                if href == MATERIALIZER
            ),
            "run_185_forward_receipt_link_occurrences": static["text"].count(
                f'href="{OUTPUT}"'
            ),
            "run_185_forward_receipt_link_adjacent_hash_occurrences": sum(
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
            "retained_claim_records": 14,
            "current_provisional_source_claims": 8,
            "historical_already_fixed_records": 2,
            "historical_remediated_records": 4,
            "final_P0": 0,
            "final_P1": 0,
            "changed_by_run_185": False,
        },
        "bounded_execution_accounting": {
            "unique_tests": 99,
            "unique_assertions": 1931,
            "changed_by_run_185": False,
            "executed_by_run_185": False,
            "red_replay_isolated_supporting_or_other_fix_recredit": False,
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
            "changed_by_run_185": False,
            "correctness_credit": False,
        },
        "benchmark_boundary": {
            "mapped": 2,
            "total": 340,
            "final_no_match_or_NCM": 0,
            "unresolved": 338,
            "changed_by_run_185": False,
        },
        "noninheritance_boundary": {
            "builder_guard_correction_credit": False,
            "superseded_html_validation_credit": False,
            "run_184_reporting_recredited": False,
            "run_183_application_remediation_recredited": False,
            "static_route_feature_ownership": False,
            "static_controller_action_bridge": False,
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
            "browser_viewport_override_reset": True,
            "agent_created_tab_closed": True,
            "remaining_controlled_tabs": 0,
            "temporary_loopback_port": 43185,
            "temporary_server_pid": 3004,
            "temporary_server_executable": (
                "C:\\Users\\steph\\.cache\\codex-runtimes\\"
                "codex-primary-runtime\\dependencies\\python\\python.exe"
            ),
            "listeners_after_cleanup": finalization[
                "listeners_after_cleanup"
            ],
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
            "builder_changed_before_run_185_materializer": True,
            "audit_dashboard_html_changed_before_run_185_materializer": True,
            "application_paths_changed": [],
            "product_test_paths_changed": [],
            "findings_register_changed_by_run_185": False,
            "run_184_reporting_surfaces_changed_by_run_185": False,
            "forms_submitted": False,
            "records_opened": False,
            "screenshots_retained": False,
            "database_changed": False,
            "application_tests_or_build_run_by_materializer": False,
        },
        "remote_state_boundary": {
            "origin_main_before_run_185_commit": ORIGIN_MAIN,
            "local_main_ahead_before_run_185_commit": LOCAL_MAIN_AHEAD,
            "local_main_behind_before_run_185_commit": LOCAL_MAIN_BEHIND,
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
        "run_185_sequence_written_paths": [
            f"{PREFIX}/{BUILDER}",
            f"{PREFIX}/{HTML}",
            f"{PREFIX}/{MATERIALIZER}",
            f"{PREFIX}/{OUTPUT}",
        ],
        "root_finalization_required": {
            "post_materialization_http_head": not resource_complete,
            "server_cleanup": not cleanup_complete,
            "receipt_materializer_arguments": {
                "final_http_head_verified_count": (
                    finalization["final_http_head_verified_count"]
                ),
                "final_http_head_failure_count": (
                    finalization["final_http_head_failure_count"]
                ),
                "listeners_after_cleanup": finalization[
                    "listeners_after_cleanup"
                ],
                "exact_server_pid_present_after_cleanup": finalization[
                    "exact_server_pid_present_after_cleanup"
                ],
                "matching_loopback_processes_after_cleanup": finalization[
                    "matching_loopback_processes_after_cleanup"
                ],
            },
        },
    }
    assert {
        key for key, value in credit.items() if value
    } == {"exact_audit_dashboard_artifact"}
    assert len(completion_gates) == 26
    assert [row["gate"] for row in completion_gates] == list(range(1, 27))
    assert all(row["complete"] is False for row in completion_gates)
    assert len(completion_boundary) == 26
    assert all(value is False for value in completion_boundary.values())
    assert all(
        value is False
        for value in receipt["noninheritance_boundary"].values()
    )
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    assert_finite(receipt)
    return receipt


def validate_receipt(receipt: dict[str, Any]) -> None:
    copy = dict(receipt)
    seal = copy.pop("receipt_self_seal_sha256")
    assert seal == canonical_sha256(copy)
    assert receipt["reported_finding_boundary"] == {
        "retained_claim_records": 14,
        "current_provisional_source_claims": 8,
        "historical_already_fixed_records": 2,
        "historical_remediated_records": 4,
        "final_P0": 0,
        "final_P1": 0,
        "changed_by_run_185": False,
    }
    assert receipt["bounded_execution_accounting"]["unique_tests"] == 99
    assert receipt["bounded_execution_accounting"]["unique_assertions"] == 1931
    assert receipt["benchmark_boundary"] == {
        "mapped": 2,
        "total": 340,
        "final_no_match_or_NCM": 0,
        "unresolved": 338,
        "changed_by_run_185": False,
    }
    assert receipt["static_ownership_boundary"]["next_zero_based_index"] == 85
    assert receipt["static_ownership_boundary"]["next_ownership_status"] == (
        "PENDING_FRESH_SEMANTIC_REVIEW"
    )
    assert receipt["verification"]["visible_static_checks_required"] == 117
    assert receipt["verification"]["visible_static_checks_passed"] == 117
    assert receipt["verification"]["anchor_elements"] == 868
    assert receipt["verification"]["unique_local_resources"] == 463
    assert receipt["verification"]["navigation_clicks_passed"] == 10
    assert receipt["verification"]["console_warning_entries"] == 0
    assert receipt["verification"]["console_error_entries"] == 0
    assert receipt["verification"]["browser_dev_log_entries"] is None
    assert receipt["verification"]["unreported_dev_log_fields_fabricated"] is False
    assert len(receipt["completion_gates"]) == 26
    assert all(row["complete"] is False for row in receipt["completion_gates"])
    assert all(value is False for value in receipt["completion_boundary"].values())
    assert receipt["credit_boundary"]["exact_audit_dashboard_artifact"] is True
    assert receipt["credit_boundary"]["application_browser"] is False
    assert receipt["root_browser_resource_cleanup"][
        "browser_viewport_override_reset"
    ] is True
    assert receipt["root_browser_resource_cleanup"][
        "agent_created_tab_closed"
    ] is True
    assert receipt["root_browser_resource_cleanup"][
        "remaining_controlled_tabs"
    ] == 0
    assert receipt["artifact_completion_test_met"] is True
    assert receipt["audit_completion_test_met"] is False


def main() -> None:
    args = parse_args()
    finalization = finalization_inputs(args)
    validate_repository_state()
    run_184 = validate_run_184()
    corrections = validate_builder_corrections()
    static = validate_static_dashboard()
    receipt = build_receipt(run_184, static, corrections, finalization)
    validate_receipt(receipt)
    encoded = (
        json.dumps(
            receipt,
            ensure_ascii=False,
            indent=2,
            allow_nan=False,
        )
        + "\n"
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
                "dashboard_sha256": FINAL_RUN_185_DASHBOARD["sha256"],
                "builder_sha256": FINAL_RUN_185_BUILDER["sha256"],
                "materializer_sha256": file_record(MATERIALIZER)["sha256"],
                "receipt_sha256": sha256(encoded),
                "receipt_self_seal_sha256": receipt[
                    "receipt_self_seal_sha256"
                ],
                "visible_checks": "117/117",
                "navigation": "10/10",
                "viewports": "4/4",
                "unique_local_resources": len(static["unique_local"]),
                "final_http_head": (
                    "463/463"
                    if finalization["resource_complete"]
                    else "PENDING"
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
