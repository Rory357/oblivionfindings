#!/usr/bin/env python3
"""Materialize RUN164 verification for the corrected RUN163 dashboard.

This serializes browser observations already made by /root. It does not start a
browser or server, rerun application tests, or grant application, finding,
benchmark, Pass, feature-completion, or audit-completion credit.
"""

from __future__ import annotations

import ast
import hashlib
import json
import re
import subprocess
from collections import Counter
from html.parser import HTMLParser
from pathlib import Path
from typing import Any
from urllib.parse import unquote, urlsplit


AUDIT = Path(__file__).resolve().parents[1]
REPO = AUDIT.parents[2]
PREFIX = AUDIT.relative_to(REPO).as_posix()

MATERIALIZER = "generators/materialize-run-164-audit-dashboard-verification-wave-29.py"
OUTPUT = "evidence/browser/current-audit-dashboard-verification-run-164-wave-29.json"
HTML = "audit-dashboard.html"
BUILDER = "generators/build-current-audit-dashboard.py"
RUN163_GENERATOR = "generators/materialize-run-163-med-cd-scope-remediation-reporting-wave-29.py"
RUN163_RECEIPT = "evidence/source/current-run-163-med-cd-scope-remediation-reporting-wave-29.json"

CHECKPOINT_COMMIT = "1cdec6bd48b096c0569f0e85d8e0e8f444b61062"
CHECKPOINT_TREE = "b3623930ea5cdf1058b158195693aa210ccf30a8"
CHECKPOINT_PARENT = "adc80d437781bc5f2f4a3f072e86b51fb10a1c7d"
APPLICATION_COMMIT = "0b1920dade9251d617f3cb0b69da5c0202b5a6bf"
APPLICATION_TREE = "7b2b5688c90e4da28725e70e38e50fd445f1b4c4"

BASELINE_HTML = {
    "sha256": "c27d0535885c68984b96bf1fbbb91f65f303a8ed8b9255742df9d8f0788370b3",
    "blob_id": "5bb2c6fff53a1e6440359a51546db30dbf7ecc0b",
    "bytes": 244814,
    "lines": 78,
}
BASELINE_BUILDER = {
    "sha256": "027e4be334936aca7db20f7c23ea10e5f24531bda9b3e800d68dee2db4ca7a6f",
    "blob_id": "cddf73cc81d388f25a154370a8172c6d7ce7c0fc",
    "bytes": 427092,
    "lines": 3805,
}
CURRENT_HTML = {
    "sha256": "04fe2430810557f4fe61630f877efc7f827f6bcb1e265ac470ffd2bf277bcbbd",
    "blob_id": "6ddb0bd03425679e0e9c1f5748860cdcc6cd17b3",
    "bytes": 253337,
    "lines": 78,
}
CURRENT_BUILDER = {
    "sha256": "0c5ea8d8885ed21ca45fc1a54400757e87cb17d12d27fd6e9a298b8f427d1667",
    "blob_id": "7b1276d9c4d82af0c7aec7ced8c91cbdd824b5d3",
    "bytes": 429489,
    "lines": 3843,
}
RUN163_GENERATOR_PIN = {
    "sha256": "f62b48cba349e0f50eec25830ac96c70b7ccc281f673c2dba93af9d59796f067",
    "blob_id": "ef7516fc1c3bb0433bce05753e0a2d4168512532",
    "bytes": 23627,
    "lines": 478,
}
RUN163_RECEIPT_PIN = {
    "sha256": "a0403ff218b8267fbbc8aa009b353e94975956b826c1215289525bf65299c4ac",
    "blob_id": "c2905815ea3092f0ce9b883f0e5da6621aea6d3e",
    "bytes": 13703,
    "lines": 335,
}

LINEAGE = {
    "generators/materialize-run-161-audit-dashboard-verification-wave-28.py": "a0970afe9672e878f5a813e59e9d51ee0c95c6e953c4fe3bd8a175e85e6209b9",
    "evidence/browser/current-audit-dashboard-verification-run-161-wave-28.json": "dc62fe1a6242dc42e0f9f75b278a0fbf042a667279ca3a4fdabb279d361613e3",
    "generators/materialize-run-162-med-cd-scope-remediation-wave-29.py": "d305638441b8ff366fa5fbc5a00bcc2b81658bf2611a5633ad79fdb4b63f5fb4",
    "evidence/runtime/current-run-162-med-cd-scope-remediation-wave-29.json": "21564caa435927d89d994a091383409e627c44170304f6ff2a5d5c897c858958",
    "generators/materialize-independent-run-162-med-cd-scope-remediation-review-wave-29.py": "c5278e5b80cd4c8c3c159a8ce3e6ae98788ad2dc9d9a1087820f910c7b203ab2",
    "evidence/runtime/current-run-162r-independent-med-cd-scope-remediation-review-wave-29.json": "7a1decaccfde2246163daef3dbec285b6a5a1a5019d2411615cc7e003660ff78",
    RUN163_GENERATOR: RUN163_GENERATOR_PIN["sha256"],
    RUN163_RECEIPT: RUN163_RECEIPT_PIN["sha256"],
}

NAVIGATION = [
    ("Progress", "#progress"),
    ("RUN-163", "#checkpoint"),
    ("Pages", "#pages"),
    ("Static census", "#static-census"),
    ("Runtime gates", "#runtime"),
    ("Benchmarks", "#benchmarks"),
    ("Modules", "#modules"),
    ("Finding status", "#findings"),
    ("Architecture", "#architecture"),
    ("Gaps", "#gaps"),
]

VISIBLE_CHECKS = {
    "run_163": ("RUN-163",),
    "finding_split": ("10 current provisional P1 + 1 historical already-fixed + 1 historical remediated",),
    "retained_identities": ("12 retained claim identities",),
    "med_cd_scope_runtime": ("5 focused tests / 48 assertions",),
    "med_cd_scope_related_runtime": ("102 related controller/command tests pass",),
    "med_rbac_runtime": ("73 bounded tests / 1,481 assertions",),
    "separate_test_denominators": (
        "separate RUN-159 MED-RBAC 73/1,481",
        "The denominators are not one execution",
    ),
    "forward_gate_finding_split": (
        "12 retained claim identities split into 10 current provisional P1, "
        "1 historical already-fixed, and 1 historical remediated",
    ),
    "current_checkpoint": ("RUN-161–163 current remediation and reporting checkpoint",),
    "historical_fixed_wording": ("historical issue · already fixed on current main · not a final finding",),
    "historical_remediated_wording": ("historical issue · remediated on current main · not a final finding",),
    "med_scope_identity": ("MED-CD-SCOPE-01",),
    "med_atomicity_identity": ("MED-CD-ATOMICITY-01",),
    "base_reproduced_failures": ("two broader INR failures reproduce at base",),
    "full_suite_false": ("full-suite green false",),
    "atomicity_noninheritance": ("inherits no transaction, retry, rollback, lock-order, fractional-value, operation-level concurrency",),
    "fresh_run_164": ("Fresh RUN-164 audit-dashboard verification required",),
    "application_pin": ("0b1920dade92",),
    "tree_pin": ("7b2b5688c90e",),
    "owner_arithmetic": ("664 = 307 route + 357 page",),
    "action_bridges": ("95 action bridges",),
    "queue_arithmetic": ("507 total = 118 reviewed + 389 pending",),
    "benchmark_mapped": ("2/340 mappings",),
    "benchmark_ncm": ("0/340 final no-match/NCM",),
    "benchmark_unresolved": ("338 unresolved targets",),
    "architecture": ("one operating organisation across multiple Sites",),
    "completion_false": ("Gate 4 and audit completion false",),
    "run_159r_attribution": ("RUN-159R independently authorizes retirement reporting",),
    "run_160_attribution": ("RUN-160 alone changes MED-RBAC",),
    "run_162_attribution": ("RUN-162 establishes MED-CD-SCOPE-01 reproduction/remediation/runtime/integration/application-commit publication",),
    "run_162r_attribution": ("RUN-162R alone authorizes retirement reporting",),
    "run_163_attribution": ("RUN-163 alone changes the MED-CD-SCOPE live status",),
    "bounded_execution_exception": (
        "Apart from the separately bounded RUN-159 MED-RBAC and RUN-162 MED-CD-SCOPE executions",
        "no represented wave grants broader or full-suite application runtime or coverage",
    ),
}

PROHIBITED_VISIBLE = (
    "0 current application tests",
    "MED-CD-SCOPE-01 and MED-CD-ATOMICITY-01 remain separate current provisional claims",
    "RUN-162/R closes MED-CD-SCOPE-01",
    "MED-CD-SCOPE-01 closed",
    "MED-CD-SCOPE-01 final finding",
    "no represented wave grants current-source application runtime, signed-in browser, executed-test",
    "10 current provisional P1 and 1 historical already-fixed, RUN-159",
)

VIEWPORTS = [
    {
        "requested": "1440x900",
        "actual_browser_viewport": "1440x900",
        "root_client_width": 1425,
        "root_scroll_width": 1425,
        "body_scroll_width": 1425,
        "page_overflow_px": 0,
        "navigation_client_width": 1425,
        "navigation_scroll_width": 1425,
        "active_table_scrollers": 0,
        "wide_tables": 0,
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
        "active_table_scrollers": 0,
        "wide_tables": 0,
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
        "active_table_scrollers": 1,
        "wide_tables": 0,
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
        "active_table_scrollers": 10,
        "wide_tables": 10,
    },
]


def run_git(*args: str, check: bool = True) -> subprocess.CompletedProcess[bytes]:
    return subprocess.run(
        ["git", *args], cwd=REPO, check=check,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )


def git(*args: str) -> str:
    return run_git(*args).stdout.decode("utf-8").rstrip("\r\n")


def git_lines(*args: str) -> list[str]:
    value = git(*args)
    return [] if not value else value.splitlines()


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def canonical_sha256(value: Any) -> str:
    return sha256(json.dumps(
        value, ensure_ascii=False, sort_keys=True, separators=(",", ":")
    ).encode("utf-8"))


def strict_json_bytes(raw: bytes, label: str) -> dict[str, Any]:
    def reject_duplicates(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        value: dict[str, Any] = {}
        for key, item in pairs:
            assert key not in value, (label, key)
            value[key] = item
        return value

    result = json.loads(raw, object_pairs_hook=reject_duplicates)
    assert isinstance(result, dict), label
    return result


def assert_lf(path: Path) -> bytes:
    raw = path.read_bytes()
    assert raw.endswith(b"\n") and b"\r\n" not in raw, path
    assert not raw.startswith(b"\xef\xbb\xbf"), path
    assert all(line.rstrip(b" \t") == line for line in raw.splitlines()), path
    return raw


def strict_json(relative: str) -> dict[str, Any]:
    raw = assert_lf(AUDIT / relative)
    return strict_json_bytes(raw, relative)


def file_record(relative: str) -> dict[str, Any]:
    raw = assert_lf(AUDIT / relative)
    return {
        "sha256": sha256(raw),
        "blob_id": git("hash-object", "--", str(AUDIT / relative)),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


def commit_file_record(commit: str, relative: str) -> dict[str, Any]:
    repo_relative = f"{PREFIX}/{relative}"
    raw = run_git("show", f"{commit}:{repo_relative}").stdout
    return {
        "sha256": sha256(raw),
        "blob_id": git("rev-parse", f"{commit}:{repo_relative}"),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


def expected_status(include_receipt: bool) -> set[str]:
    expected = {
        f" M {PREFIX}/{HTML}",
        f" M {PREFIX}/{BUILDER}",
        f"?? {PREFIX}/{MATERIALIZER}",
    }
    if include_receipt:
        expected.add(f"?? {PREFIX}/{OUTPUT}")
    return expected


def validate_status(include_receipt: bool | None = None) -> None:
    status = set(git_lines("status", "--porcelain=v1", "--untracked-files=all"))
    if include_receipt is None:
        assert status in (expected_status(False), expected_status(True)), sorted(status)
    else:
        assert status == expected_status(include_receipt), sorted(status)
    assert run_git("diff", "--cached", "--quiet", check=False).returncode == 0
    assert not list(AUDIT.rglob("__pycache__"))


class DashboardParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.hrefs: list[str] = []
        self.ids: list[str] = []
        self.text: list[str] = []
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
            self.text.append(normalized)


def is_local(href: str) -> bool:
    low = href.lower()
    return not (
        href.startswith("#") or href.startswith("//")
        or low.startswith(("http://", "https://", "mailto:", "javascript:", "data:"))
    )


def local_path(href: str) -> Path:
    target = (AUDIT / unquote(urlsplit(href).path)).resolve()
    target.relative_to(AUDIT.resolve())
    return target


def validate_checkpoint() -> dict[str, Any]:
    validate_status()
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
    assert git("rev-parse", "HEAD^") == CHECKPOINT_PARENT
    assert git("rev-parse", "origin/main") == CHECKPOINT_COMMIT
    assert git("rev-list", "--left-right", "--count", "origin/main...HEAD") == "0\t0"
    assert commit_file_record(CHECKPOINT_COMMIT, HTML) == BASELINE_HTML
    assert commit_file_record(CHECKPOINT_COMMIT, BUILDER) == BASELINE_BUILDER
    assert file_record(HTML) == CURRENT_HTML
    assert file_record(BUILDER) == CURRENT_BUILDER
    assert file_record(RUN163_GENERATOR) == RUN163_GENERATOR_PIN
    assert file_record(RUN163_RECEIPT) == RUN163_RECEIPT_PIN
    assert set(git_lines("diff", "--name-only", "HEAD", "--")) == {
        f"{PREFIX}/{HTML}", f"{PREFIX}/{BUILDER}",
    }
    diff_check = run_git("diff", "--check", "HEAD", "--", check=False)
    assert diff_check.returncode == 0
    assert diff_check.stdout == b"" and diff_check.stderr == b""
    ast.parse((AUDIT / BUILDER).read_text(encoding="utf-8"))
    ast.parse((AUDIT / MATERIALIZER).read_text(encoding="utf-8"))

    run163 = strict_json(RUN163_RECEIPT)
    assert run163["schema_version"] == "run-163-med-cd-scope-remediation-reporting-wave-29-v1"
    assert run163["run_id"] == "RUN-163-MED-CD-SCOPE-01-REMEDIATION-REPORTING-WAVE-29"
    seal = run163.pop("receipt_self_seal_sha256")
    assert seal == "5683bfe946083b12d1bb1f39cc71526b96eda76faaba8cb230cfb3e73c1b8fe0"
    assert canonical_sha256(run163) == seal
    run163["receipt_self_seal_sha256"] = seal
    assert run163["pins"]["dashboard_generator"] == {
        "path": BUILDER,
        "sha256": BASELINE_BUILDER["sha256"],
        "git_blob_id": BASELINE_BUILDER["blob_id"],
        "bytes": BASELINE_BUILDER["bytes"],
        "lines": BASELINE_BUILDER["lines"],
    }
    assert run163["pins"]["application_commit"] == APPLICATION_COMMIT
    assert run163["pins"]["application_tree"] == APPLICATION_TREE
    assert run163["dashboard_forward_gate"]["required_run"] == "RUN-164"
    assert run163["dashboard_forward_gate"]["fresh_four_viewport_verification_required"] is True
    assert all(value is False for value in run163["completion_boundary"].values())
    return run163


def validate_finding_boundary() -> dict[str, Any]:
    register = strict_json("findings.json")
    assert register["audit_status"] == (
        "TEN_PROVISIONAL_ONE_HISTORICAL_ALREADY_FIXED_ONE_HISTORICAL_REMEDIATED_"
        "ZERO_FINAL_FINDING_CREDIT"
    )
    counts = register["counts"]
    assert counts["retained_claim_records"] == 12
    assert counts["provisional_source_claims"] == counts["provisional_P1"] == 10
    assert counts["historical_already_fixed"] == 1
    assert counts["historical_remediated"] == 1
    assert counts["final_P0"] == counts["final_P1"] == 0
    assert counts["med_rbac_bounded_tests"] == 73
    assert counts["med_rbac_bounded_test_assertions"] == 1481
    assert counts["med_cd_scope_focused_tests"] == 5
    assert counts["med_cd_scope_focused_test_assertions"] == 48
    assert counts["benchmark_mapped"] == 2
    assert counts["final_no_match"] == 0
    assert counts["benchmark_unresolved"] == 338
    records = {record["id"]: record for record in register["records"]}
    assert len(records) == 12
    assert records["MED-RBAC-01"]["record_status"] == (
        "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING"
    )
    assert records["MED-RBAC-01"]["current_adjudication"]["verdict"] == "ALREADY_FIXED"
    assert records["MED-CD-SCOPE-01"]["record_status"] == (
        "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
    )
    scope = records["MED-CD-SCOPE-01"]["current_adjudication"]
    assert scope["verdict"] == "REPRODUCED_AND_REMEDIATED"
    assert scope["application_commit"] == APPLICATION_COMMIT
    assert scope["repository_tree"] == APPLICATION_TREE
    assert scope["separate_med_cd_atomicity_inherited"] is False
    atomicity = records["MED-CD-ATOMICITY-01"]
    assert atomicity["record_status"] == "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING"
    assert atomicity["current_behaviour"]["runtime_observed"] is False
    assert all(record["completion_credit"] is False for record in records.values())
    assert all(
        all(value is False for value in record["credit"].values())
        for record in records.values()
    )
    return {
        "retained_claim_records": 12,
        "current_provisional_P1": 10,
        "historical_already_fixed": 1,
        "historical_remediated": 1,
        "final_P0": 0,
        "final_P1": 0,
        "med_rbac_bounded_execution": "73 tests / 1,481 assertions",
        "med_cd_scope_focused_execution": "5 tests / 48 assertions",
        "execution_denominators_are_separate": True,
        "benchmark_mapped": 2,
        "final_no_match_or_NCM": 0,
        "benchmark_unresolved": 338,
    }


def parse_dashboard() -> tuple[DashboardParser, list[str], list[str], list[tuple[str, str]]]:
    raw = assert_lf(AUDIT / HTML)
    assert file_record(HTML) == CURRENT_HTML
    source = raw.decode("utf-8")
    parser = DashboardParser()
    parser.feed(source)
    assert parser.headings == 26
    assert parser.tables == parser.table_wraps == 10
    assert len(parser.hrefs) == 743
    assert len(parser.ids) == 10
    id_counts = Counter(parser.ids)
    assert not [item for item, count in id_counts.items() if count > 1]
    hash_hrefs = [href for href in parser.hrefs if href.startswith("#")]
    assert hash_hrefs == [href for _, href in NAVIGATION]
    assert len(hash_hrefs) == len(set(hash_hrefs)) == 10
    assert not [href for href in hash_hrefs if href[1:] not in id_counts]
    local_hrefs = [href for href in parser.hrefs if is_local(href)]
    unique_local = sorted(set(local_hrefs))
    assert len(local_hrefs) == 733
    assert len(unique_local) == 403
    missing_local = [href for href in unique_local if not local_path(href).exists()]
    assert missing_local in ([], [OUTPUT]), missing_local

    pairs = re.findall(
        r'<a href="([^"#]+)">[^<]*</a>\s*<code>([0-9a-f]{64})</code>', source
    )
    assert len(pairs) == 631
    assert len(set(pairs)) == 331
    assert len({href for href, _ in pairs}) == 331
    file_pairs = [(href, digest) for href, digest in pairs if local_path(href).is_file()]
    dir_pairs = [(href, digest) for href, digest in pairs if local_path(href).is_dir()]
    assert len(file_pairs) == 629
    assert len({href for href, _ in file_pairs}) == 330
    assert len(dir_pairs) == 2
    assert {href for href, _ in dir_pairs} == {"task-scripts/"}
    failures = [
        (href, expected, sha256(local_path(href).read_bytes()))
        for href, expected in file_pairs
        if sha256(local_path(href).read_bytes()) != expected
    ]
    assert not failures
    assert source.count(f'href="{OUTPUT}"') == 1
    assert not [pair for pair in pairs if pair[0] == OUTPUT]
    assert "intentionally unhashed" in source
    assert source.count(".notice,.panel>p{overflow-wrap:anywhere}") == 1
    for relative, digest in LINEAGE.items():
        assert file_record(relative)["sha256"] == digest
        assert f'href="{relative}"' in source
        assert f'href="{relative}">' in source and digest in source

    visible = " ".join(parser.text)
    checks = {
        key: all(token in visible for token in tokens)
        for key, tokens in VISIBLE_CHECKS.items()
    }
    assert all(checks.values()), [key for key, value in checks.items() if not value]
    assert not [token for token in PROHIBITED_VISIBLE if token in visible]
    assert "one operating organisation across multiple Sites" in visible
    assert "multi-tenant" not in visible.lower()
    return parser, unique_local, missing_local, pairs


def validate_builder_correction() -> dict[str, Any]:
    source = assert_lf(AUDIT / BUILDER).decode("utf-8")
    assert source.count(".notice,.panel>p{overflow-wrap:anywhere}") == 1
    assert "def git_file_at_commit(commit: str, relative: str) -> bytes:" in source
    assert '"1cdec6bd48b096c0569f0e85d8e0e8f444b61062"' in source
    assert 'run_163_builder_bytes = git_file_at_commit(' in source
    assert 'hashlib.sha256(run_163_builder_bytes).hexdigest()' in source
    assert 'git_blob_id_bytes(run_163_builder_bytes)' in source
    assert (
        'run_163_reporting["pins"]["dashboard_generator"]["sha256"] '
        '== sha256_file("generators/build-current-audit-dashboard.py")'
    ) not in source
    assert (
        "Apart from the separately bounded RUN-159 MED-RBAC and RUN-162 "
        "MED-CD-SCOPE executions"
    ) in source
    assert (
        "12 retained claim identities split into 10 current provisional P1, "
        "1 historical already-fixed, and 1 historical remediated"
    ) in source
    assert (
        'assert "10 current provisional P1 and 1 historical already-fixed, RUN-159" '
        "not in dashboard"
    ) in source
    return {
        "audit_artifact_issue_id": "AUDIT-DASHBOARD-RUN164-CORRECTIONS-01",
        "pre_correction_artifact_sha256": "195809c92573341e9a7369e2d0154f948913e614b9f5656c53b63c8ba6a034ae",
        "pre_correction_artifact_bytes": 253140,
        "observed_viewport": "390x844",
        "pre_correction_root_client_width": 375,
        "pre_correction_root_scroll_width": 428,
        "pre_correction_page_overflow_px": 53,
        "cause": (
            "the slash-delimited reproduction/remediation/runtime/integration/"
            "application-commit token in the RUN-071–163 current reporting checkpoint "
            "notice did not break within the 375 px document width, producing 428 px "
            "scroll width (53 px overflow) at 390x844"
        ),
        "css_correction": ".notice,.panel>p{overflow-wrap:anywhere}",
        "css_breakpoint": "max-width:520px",
        "historical_pin_correction": (
            "validate RUN163's builder pin against the exact builder bytes stored at "
            "RUN163 commit 1cdec6bd48b096c0569f0e85d8e0e8f444b61062, not against the evolving current builder"
        ),
        "semantic_boundary_corrections": [
            {
                "superseded_artifact_sha256": "55b7dba1b69c39757b9f548f4338a4a27a1d1940e0b23b035d5e83481f304fc9",
                "superseded_artifact_bytes": 253180,
                "issue": (
                    "the global evidence-wave boundary incorrectly said no represented "
                    "wave grants current-source runtime or executed-test credit despite "
                    "the separately bounded RUN-159 and RUN-162 executions"
                ),
                "old_visible_wording": (
                    "no represented wave grants current-source application runtime, "
                    "signed-in browser, executed-test"
                ),
                "corrected_visible_wording": (
                    "Apart from the separately bounded RUN-159 MED-RBAC and RUN-162 "
                    "MED-CD-SCOPE executions, no represented wave grants broader or "
                    "full-suite application runtime or coverage"
                ),
                "superseded_browser_evidence_transferred": False,
            },
            {
                "superseded_artifact_sha256": "3dbe85c212c57fc7e2cfd278b9221817e1fd8ae4d5c002d8dc076039bbd42728",
                "superseded_artifact_bytes": 253311,
                "issue": (
                    "the RUN-164 forward gate described 12 retained identities but "
                    "listed only 10 current provisional and 1 historical already-fixed, "
                    "omitting the 1 historical remediated identity"
                ),
                "corrected_visible_wording": (
                    "12 retained claim identities split into 10 current provisional P1, "
                    "1 historical already-fixed, and 1 historical remediated"
                ),
                "superseded_browser_evidence_transferred": False,
            },
        ],
        "post_correction_page_overflow_px": 0,
        "post_correction_mobile_table_scrollers": 10,
        "narrow_native_audit_artifact_fix": True,
    }


def build_receipt(
    run163: dict[str, Any],
    parser: DashboardParser,
    unique_local: list[str],
    missing_local: list[str],
    pairs: list[tuple[str, str]],
    finding_boundary: dict[str, Any],
    correction: dict[str, Any],
) -> dict[str, Any]:
    local_hrefs = [href for href in parser.hrefs if is_local(href)]
    hash_hrefs = [href for href in parser.hrefs if href.startswith("#")]
    file_pairs = [(href, digest) for href, digest in pairs if local_path(href).is_file()]
    dir_pairs = [(href, digest) for href, digest in pairs if local_path(href).is_dir()]
    materializer_pin = file_record(MATERIALIZER)
    navigation_results = [
        {
            "label": label,
            "href": href,
            "browser_click_performed": True,
            "resulting_hash": href,
            "target_exists": True,
            "target_top_px": 0,
            "pass": True,
        }
        for label, href in NAVIGATION
    ]
    credit = {
        "audit_dashboard_run_164_corrections": True,
        "exact_audit_dashboard_artifact": True,
        "application_source": False,
        "application_browser": False,
        "responsive_application": False,
        "application_runtime": False,
        "application_tests": False,
        "test_coverage": False,
        "benchmark_mapping": False,
        "final_no_match_or_NCM": False,
        "finding": False,
        "final_finding": False,
        "ease": False,
        "release": False,
        "Pass": False,
        "feature_completion": False,
        "completion": False,
        "audit_completion": False,
    }
    assert [key for key, value in credit.items() if value] == [
        "audit_dashboard_run_164_corrections",
        "exact_audit_dashboard_artifact",
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
        "schema_version": "run-164-audit-dashboard-verification-wave-29-v1",
        "run_id": "RUN-164-AUDIT-DASHBOARD-VERIFICATION-WAVE-29",
        "generated_on": "2026-08-30",
        "status": (
            "AUDIT_DASHBOARD_MOBILE_OVERFLOW_AND_SEMANTIC_BOUNDARIES_CORRECTED_"
            "EXACT_ARTIFACT_RESPONSIVE_LINK_CONSOLE_VERIFICATION_GO_ZERO_"
            "APPLICATION_OR_COMPLETION_CREDIT"
        ),
        "architecture_rule": (
            "Oblivion Findings is one operating organisation across multiple Sites. "
            "Authorization remains Site access, exact roles/permissions, canonical "
            "ownership, direct-object denial, and privacy; no tenant boundary is introduced."
        ),
        "scope": (
            "Exact corrected RUN163-generated audit-dashboard artifact, its builder's "
            "mobile overflow, historical-pin, bounded-execution wording, and complete "
            "10+1+1 forward-gate corrections, static links/hashes, and fresh in-app "
            "browser evidence only; not the application UI or application browser."
        ),
        "pins": {
            "run_163_checkpoint_commit": CHECKPOINT_COMMIT,
            "run_163_checkpoint_tree": CHECKPOINT_TREE,
            "run_163_checkpoint_parent": CHECKPOINT_PARENT,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "baseline_dashboard_html": {"path": HTML, **BASELINE_HTML},
            "dashboard_html": {"path": HTML, **CURRENT_HTML},
            "baseline_dashboard_generator": {"path": BUILDER, **BASELINE_BUILDER},
            "dashboard_generator": {"path": BUILDER, **CURRENT_BUILDER},
            "materializer": {"path": MATERIALIZER, **materializer_pin},
            "run_163_generator": {"path": RUN163_GENERATOR, **RUN163_GENERATOR_PIN},
            "run_163_receipt": {
                "path": RUN163_RECEIPT,
                **RUN163_RECEIPT_PIN,
                "receipt_self_seal_sha256": run163["receipt_self_seal_sha256"],
            },
        },
        "lineage": {
            "predecessor": "RUN-163-MED-CD-SCOPE-01-REMEDIATION-REPORTING-WAVE-29",
            "current": "RUN-164-AUDIT-DASHBOARD-VERIFICATION-WAVE-29",
            "current_effective_application_commit_remains": APPLICATION_COMMIT,
            "bounded_execution_lineage": {
                "run_159_med_rbac": {
                    "application_commit": "4f57ad4202df90ded375961437879822a908627b",
                    "application_tree": "ee79b8d2733d09da2fd97992ac2a04e862159505",
                    "tests": 73,
                    "assertions": 1481,
                    "shared_with_run_162_denominator": False,
                },
                "run_162_med_cd_scope": {
                    "application_and_test_commit": APPLICATION_COMMIT,
                    "application_tree": APPLICATION_TREE,
                    "focused_tests": 5,
                    "focused_assertions": 48,
                    "related_controller_command_tests_passed": 102,
                    "broader_failures_reproduced_at_base": 2,
                    "full_suite_green": False,
                    "shared_with_run_159_denominator": False,
                },
            },
            "run_163_reporting_commit_remains": CHECKPOINT_COMMIT,
            "current_run_164_commit_not_self_claimed": True,
            "lineage_links_and_adjacent_hashes_verified": [
                {"path": path, "sha256": digest} for path, digest in LINEAGE.items()
            ],
        },
        "dashboard_issue_and_correction": correction,
        "reported_finding_boundary": finding_boundary,
        "noninheritance_boundary": {
            "med_cd_atomicity_remains_provisional": True,
            "transaction_credit_inherited": False,
            "retry_credit_inherited": False,
            "rollback_credit_inherited": False,
            "lock_order_credit_inherited": False,
            "fractional_value_credit_inherited": False,
            "operation_level_concurrency_credit_inherited": False,
            "run_159_and_run_162_test_denominators_combined_as_one_execution": False,
            "application_browser_credit_inferred_from_audit_dashboard": False,
        },
        "remote_state_boundary": {
            "observed_before_run_164_commit": True,
            "local_head": CHECKPOINT_COMMIT,
            "local_origin_main_tracking_ref": CHECKPOINT_COMMIT,
            "authoritative_remote_main_observed_by_run_163_push": CHECKPOINT_COMMIT,
            "run_164_publication_claimed_by_this_precommit_receipt": False,
        },
        "root_browser_resource_cleanup": {
            "temporary_in_app_browser_tab_closed": True,
            "temporary_viewport_override_reset_before_close": True,
            "remaining_controlled_tabs": 0,
            "local_http_server_pid": 1728,
            "local_http_server_port": 43164,
            "local_http_server_stopped": True,
            "residual_listener_count": 0,
            "server_process_exists_after_cleanup": False,
        },
        "verification_method": {
            "browser_observed_on": "2026-08-30",
            "browser": "Codex in-app Browser with explicit viewport capability and locator clicks",
            "local_http_target": "http://127.0.0.1:43164/audit-dashboard.html",
            "cache_busted_target": (
                "http://127.0.0.1:43164/audit-dashboard.html?run164=04fe2430"
            ),
            "cache_busted_http_response": {
                "status": 200,
                "bytes": CURRENT_HTML["bytes"],
                "sha256": CURRENT_HTML["sha256"],
                "content_type": "text/html",
            },
            "materializer_browser_execution_performed": False,
            "materializer_server_execution_performed": False,
            "materializer_serializes_root_observations_only": True,
        },
        "verification": {
            "dashboard_builder_final_byte_identical_runs_observed_by_root": 2,
            "dashboard_builder_final_runs_byte_identical": True,
            "dashboard_builder_python": (
                "C:\\Users\\steph\\.cache\\codex-runtimes\\codex-primary-runtime\\dependencies\\python\\python.exe"
            ),
            "noncreditable_attempts": [
                {
                    "attempt": "python Store alias invoked twice",
                    "result": "Python was not found; dashboard unchanged",
                    "counted_as_builder_execution": False,
                },
                {
                    "attempt": "pre-correction first ten-link browser sweep",
                    "result": "browser action window timed out before evidence serialization",
                    "counted_as_navigation_evidence": False,
                },
                {
                    "attempt": "first builder run after CSS edit",
                    "result": "failed closed on RUN163 current-file self-pin before HTML write",
                    "counted_as_builder_execution": False,
                },
                {
                    "attempt": "post-mobile-fix 55b7dba1 full browser sweep",
                    "result": (
                        "invalidated by the later bounded-execution semantic wording "
                        "correction; evidence does not transfer to changed HTML bytes"
                    ),
                    "counted_as_exact_artifact_evidence": False,
                },
                {
                    "attempt": "post-runtime-wording 3dbe85c2 full browser sweep",
                    "result": (
                        "invalidated by the later complete 10+1+1 forward-gate correction; "
                        "evidence does not transfer to changed HTML bytes"
                    ),
                    "counted_as_exact_artifact_evidence": False,
                },
                {
                    "attempt": "final 04fe2430 first ten-link sweep",
                    "result": (
                        "timed out before result serialization and reset the browser "
                        "control connection; the later chunked 10/10 sweep is the only "
                        "credited final navigation evidence"
                    ),
                    "counted_as_navigation_evidence": False,
                },
            ],
            "pre_correction_browser_issue_received_credit": False,
            "viewports_required": 4,
            "viewports_verified": 4,
            "viewports": VIEWPORTS,
            "font_loaded_at_all_viewports": True,
            "main_visible_at_all_viewports": True,
            "navigation_bounded_at_all_viewports": True,
            "tables_bounded_at_all_viewports": True,
            "page_overflow_zero_at_all_final_viewports": True,
            "navigation_clicks_required": 10,
            "navigation_clicks_passed": 10,
            "navigation_results": navigation_results,
            "console_warning_entries": 0,
            "console_error_entries": 0,
            "uncaught_page_error_entries": 0,
            "cache_busted_warning_or_error_entries": 0,
            "authored_ids": len(parser.ids),
            "duplicate_authored_ids": [],
            "heading_elements": parser.headings,
            "table_elements": parser.tables,
            "table_wrappers": parser.table_wraps,
            "anchor_elements": len(parser.hrefs),
            "anchor_elements_rendered_in_browser": len(parser.hrefs),
            "hash_anchor_occurrences": len(hash_hrefs),
            "unique_hash_anchors": len(set(hash_hrefs)),
            "missing_hash_targets": [],
            "local_resource_link_occurrences": len(local_hrefs),
            "unique_local_resources": len(unique_local),
            "prewrite_missing_local_resources": missing_local,
            "prewrite_missing_resource_is_intentional_unhashed_forward_receipt": (
                missing_local in ([], [OUTPUT])
            ),
            "post_materialization_local_resources": "403/403",
            "post_materialization_local_resource_failures": [],
            "adjacent_hash_pair_occurrences": len(pairs),
            "unique_adjacent_hash_path_hash_pairs": len(set(pairs)),
            "unique_adjacent_hash_paths": len({href for href, _ in pairs}),
            "hash_bearing_file_occurrences_verified": len(file_pairs),
            "unique_hash_bearing_file_paths_verified": len({href for href, _ in file_pairs}),
            "historical_directory_bundle_digest_occurrences": len(dir_pairs),
            "historical_directory_bundle_digest_paths": sorted({href for href, _ in dir_pairs}),
            "hash_bearing_link_failures": [],
            "run_164_forward_receipt_link_occurrences": 1,
            "run_164_forward_receipt_link_adjacent_hash_occurrences": 0,
            "visible_static_checks_required": len(VISIBLE_CHECKS),
            "visible_static_checks_passed": len(VISIBLE_CHECKS),
            "visible_static_checks": {key: True for key in VISIBLE_CHECKS},
            "prohibited_visible_phrase_hits": [],
        },
        "worktree_boundary": {
            "branch": "main",
            "checkpoint_index_clean": True,
            "expected_tracked_changes": [
                f"{PREFIX}/{HTML}", f"{PREFIX}/{BUILDER}",
            ],
            "expected_untracked_materializer": f"{PREFIX}/{MATERIALIZER}",
            "expected_untracked_receipt_after_write": f"{PREFIX}/{OUTPUT}",
            "git_diff_check": "PASS",
            "python_ast_parse_builder": "PASS",
            "python_ast_parse_materializer": "PASS",
            "pycache_paths": 0,
        },
        "mutation_attestation": {
            "application_files_changed_by_run_164": 0,
            "reporting_markdown_changed_by_run_164": 0,
            "findings_json_changed_by_run_164": False,
            "dashboard_builder_changed_by_run_164": True,
            "dashboard_html_changed_by_run_164": True,
            "run_164_materializer_added": True,
            "run_164_receipt_added": True,
            "materializer_writes_only_receipt": True,
            "browser_started_by_materializer": False,
            "server_started_by_materializer": False,
        },
        "credit_boundary": credit,
        "completion_boundary": completion,
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            f"{PREFIX}/{HTML}",
            f"{PREFIX}/{BUILDER}",
            f"{PREFIX}/{MATERIALIZER}",
            f"{PREFIX}/{OUTPUT}",
        ],
    }
    assert all(value is False for value in completion.values())
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    return receipt


def main() -> None:
    run163 = validate_checkpoint()
    finding_boundary = validate_finding_boundary()
    correction = validate_builder_correction()
    parser, unique_local, missing_local, pairs = parse_dashboard()
    receipt = build_receipt(
        run163, parser, unique_local, missing_local, pairs,
        finding_boundary, correction,
    )
    encoded = (
        json.dumps(receipt, indent=2, ensure_ascii=False).encode("utf-8") + b"\n"
    )
    output_path = AUDIT / OUTPUT
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_bytes(encoded)
    assert output_path.read_bytes() == encoded
    actual = strict_json_bytes(assert_lf(output_path), OUTPUT)
    seal = actual.pop("receipt_self_seal_sha256")
    assert seal == canonical_sha256(actual)
    actual["receipt_self_seal_sha256"] = seal
    assert actual == receipt
    validate_status(include_receipt=True)
    assert all(
        local_path(href).exists()
        for href in sorted(set(parser.hrefs))
        if is_local(href)
    )
    print(json.dumps({
        "run_id": receipt["run_id"],
        "status": receipt["status"],
        "dashboard_sha256": CURRENT_HTML["sha256"],
        "builder_sha256": CURRENT_BUILDER["sha256"],
        "viewports": "4/4",
        "navigation": "10/10",
        "local_resources": "403/403",
        "positive_credit_keys": [
            key for key, value in receipt["credit_boundary"].items() if value
        ],
        "receipt_self_seal_sha256": receipt["receipt_self_seal_sha256"],
    }, indent=2))


if __name__ == "__main__":
    main()
