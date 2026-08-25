#!/usr/bin/env python3
"""Build the RUN-078A partition-A static route/page classification packet.

This producer reads only pinned source and audit evidence. It does not boot
Laravel or award framework, runtime, browser, test-execution, benchmark, Pass,
completion, or audit-complete credit.
"""

from __future__ import annotations

import csv
import hashlib
import json
import os
import re
import subprocess
from collections import Counter, defaultdict
from functools import lru_cache
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
REPO_DIR = AUDIT_DIR.parents[2]
GENERATOR_REL = "generators/build-run-078a-route-page-classification-wave-07.py"
OUTPUT_REL = "evidence/source/raw-run-078a-route-page-classification-wave-07.json"
OUTPUT_PATH = AUDIT_DIR / OUTPUT_REL
MANIFEST_REL = "evidence/source/root-run-077-route-page-universe-manifest-wave-07.json"
MATRIX_REL = "03-feature-to-benchmark-matrix.csv"

CHECKPOINT_COMMIT = "87826adc6fb8c9f0b1ca5ea99dcdc06e32bbd6d0"
CHECKPOINT_TREE = "d1eb36fabc0f5150c81f2140e834347dca87dd25"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
MANIFEST_SHA256 = "150fcff9b100ad85a7a2e998ed69c8dafdc2d0098e8ec2b4dbac7d3b404061be"
GENERATED_ON = "2026-08-25T12:12:00+12:00"
PARTITION_ID = "A"

EXPECTED_COUNTS = {
    "route_decisions": 1073,
    "name_decisions": 1105,
    "page_decisions": 237,
    "residual_scoped_decisions": 4,
    "route_name_gap_decisions": 82,
}

LINE_ANCHOR_RE = re.compile(r"^(routes/[^:]+):(\d+)(?:-(\d+))?$")
LINE_SUFFIX_RE = re.compile(r":\d+(?:-\d+)?$")

FINANCE_STATEMENT_ROUTE_RANGES = ((621, 627), (712, 725))
FINANCE_STATEMENT_PAGE_FILES = [
    "resources/js/pages/finance/reports/TrialBalance.tsx",
    "resources/js/pages/finance/reports/ProfitAndLoss.tsx",
    "resources/js/pages/finance/reports/BalanceSheet.tsx",
    "resources/js/pages/finance/reports/CashFlow.tsx",
    "resources/js/pages/finance/reports/AgedPayables.tsx",
    "resources/js/pages/finance/reports/AgedReceivables.tsx",
    "resources/js/pages/finance/reports/FundingStreamSummary.tsx",
    "resources/js/pages/finance/audit-exports/Index.tsx",
]
FINANCE_STATEMENT_ROUTE_NAMES = [
    "reports.trial-balance",
    "reports.profit-loss",
    "reports.balance-sheet",
    "reports.cash-flow",
    "reports.aged-payables",
    "reports.aged-receivables",
    "reports.funding-stream-summary",
    "audit-exports.index",
    "audit-exports.create",
    "audit-exports.store",
    "audit-exports.download",
    "audit-exports.destroy",
]


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def canonical_list_sha256(values: list[str]) -> str:
    ordered = sorted(values, key=lambda value: value.encode("utf-8"))
    return sha256_bytes(("\n".join(ordered) + "\n").encode("utf-8"))


def git_text(*args: str) -> str:
    return subprocess.check_output(
        ["git", *args], cwd=REPO_DIR, text=True, encoding="utf-8"
    ).strip()


@lru_cache(maxsize=None)
def git_blob_bytes(path: str) -> bytes:
    return subprocess.check_output(
        ["git", "show", f"{APPLICATION_COMMIT}:{path.replace(os.sep, '/')}"],
        cwd=REPO_DIR,
    )


@lru_cache(maxsize=None)
def git_blob_text(path: str) -> str:
    return git_blob_bytes(path).decode("utf-8")


@lru_cache(maxsize=None)
def application_paths(prefix: str) -> tuple[str, ...]:
    value = git_text("ls-tree", "-r", "--name-only", APPLICATION_COMMIT, "--", prefix)
    return tuple(line.replace("\\", "/") for line in value.splitlines() if line)


def read_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def split_cell(value: str) -> list[str]:
    if not value or value.startswith("NOT_ESTABLISHED") or value == "NOT_APPLICABLE":
        return []
    return [part.strip() for part in value.split(";") if part.strip()]


def unique(values: list[str]) -> list[str]:
    return list(dict.fromkeys(value for value in values if value))


def line_text(path: str, line: int) -> str:
    lines = git_blob_text(path).splitlines()
    assert 1 <= line <= len(lines), (path, line)
    return lines[line - 1]


def assert_line_contains(path: str, line: int, needle: str) -> None:
    assert needle in line_text(path, line), (path, line, needle)


def current_generator_sha256() -> str:
    return sha256_file(Path(__file__).resolve())


def validate_pins(manifest: dict) -> None:
    assert git_text("branch", "--show-current") == "main"
    assert git_text("rev-parse", f"{CHECKPOINT_COMMIT}^{{tree}}") == CHECKPOINT_TREE
    ancestor = subprocess.run(
        ["git", "merge-base", "--is-ancestor", CHECKPOINT_COMMIT, "HEAD"],
        cwd=REPO_DIR,
        check=False,
    )
    assert ancestor.returncode == 0
    assert git_text("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    product_diff = subprocess.run(
        [
            "git",
            "diff",
            "--quiet",
            APPLICATION_COMMIT,
            "--",
            "app",
            "routes",
            "resources/js",
            "tests",
        ],
        cwd=REPO_DIR,
        check=False,
    )
    assert product_diff.returncode == 0
    assert sha256_file(AUDIT_DIR / MANIFEST_REL) == MANIFEST_SHA256
    assert manifest["pins"]["application_commit"] == APPLICATION_COMMIT
    assert manifest["pins"]["application_tree"] == APPLICATION_TREE
    assert not any(manifest["credit_boundary"].values())
    assert not any(manifest["completion_boundary"].values())
    expected_matrix_sha = manifest["pins"]["inputs"][MATRIX_REL]
    assert sha256_file(AUDIT_DIR / MATRIX_REL) == expected_matrix_sha


def load_matrix() -> tuple[list[dict[str, str]], dict[str, dict[str, str]]]:
    with (AUDIT_DIR / MATRIX_REL).open(encoding="utf-8-sig", newline="") as handle:
        rows = list(csv.DictReader(handle))
    by_id = {row["feature_id"]: row for row in rows}
    assert len(rows) == len(by_id) == 340
    return rows, by_id


def bounded_test_search(terms: list[str]) -> dict:
    hits: list[str] = []
    for path in application_paths("tests"):
        if not path.lower().endswith(".php"):
            continue
        for line_number, line in enumerate(git_blob_text(path).splitlines(), start=1):
            if any(term in line for term in terms):
                hits.append(f"{path}:{line_number}")
    hits = sorted(set(hits), key=lambda value: value.encode("utf-8"))
    return {
        "scope": "All pinned tracked tests/**/*.php at the application commit.",
        "terms": terms,
        "evidence": hits,
        "evidence_locator_sha256": canonical_list_sha256(hits),
        "result": "Exact term hits are listed; adjacent or semantically different hits are not inherited.",
    }


def explicit_route_intervals(matrix_row: dict[str, str]) -> dict[str, list[tuple[int, int]]]:
    result: dict[str, list[tuple[int, int]]] = defaultdict(list)
    for anchor in split_cell(matrix_row["route_paths"]):
        match = LINE_ANCHOR_RE.fullmatch(anchor)
        if match:
            start = int(match.group(2))
            result[match.group(1)].append((start, int(match.group(3) or start)))
    return dict(result)


def line_is_explicitly_anchored(
    matrix_row: dict[str, str], route_file: str, source_line: int
) -> bool:
    return any(
        start <= source_line <= end
        for start, end in explicit_route_intervals(matrix_row).get(route_file, [])
    )


def validate_manifest_source_rows(
    assigned_routes: list[dict], assigned_names: list[dict], assigned_pages: list[dict]
) -> None:
    for row in assigned_routes:
        raw = git_blob_bytes(row["route_file"])
        assert sha256_bytes(raw) == row["route_file_sha256"], row["route_record_id"]
        assert row["source_anchor"].startswith(row["route_file"] + ":")
    for row in assigned_names:
        raw = git_blob_bytes(row["route_file"])
        assert sha256_bytes(raw) == row["route_file_sha256"], row["name_record_id"]
        assert row["source_anchor"].startswith(row["route_file"] + ":")
    for row in assigned_pages:
        raw = git_blob_bytes(row["page_file"])
        assert sha256_bytes(raw) == row["page_file_sha256"], row["page_record_id"]
        for call in row["render_callsites"]:
            source_raw = git_blob_bytes(call["source_file"])
            assert sha256_bytes(source_raw) == call["source_file_sha256"]


def build_route_decisions(
    assigned_ids: list[str],
    route_by_id: dict[str, dict],
    name_by_id: dict[str, dict],
    matrix_rows: list[dict[str, str]],
) -> list[dict]:
    matrix_name_index: dict[str, list[str]] = defaultdict(list)
    for matrix_row in matrix_rows:
        for route_name in split_cell(matrix_row["route_names"]):
            matrix_name_index[route_name].append(matrix_row["feature_id"])

    decisions: list[dict] = []
    for route_id in assigned_ids:
        row = route_by_id[route_id]
        direct_name_id = row.get("direct_name_callsite_id")
        direct_name_row = name_by_id.get(direct_name_id) if direct_name_id else None
        literal_name = row.get("direct_name_literal") or row.get("literal_route_name")
        exact_ids = sorted(set(matrix_name_index.get(literal_name, []))) if literal_name else []

        if not route_id.startswith("RUN077-ROUTE-SENTINEL"):
            assert exact_ids == row["candidate_bases"]["matrix_route_name_exact"]

        if len(exact_ids) == 1:
            classification = "OWNER"
            reviewed_ids = exact_ids
            basis = "EXACT_LITERAL_ROUTE_NAME_UNIQUE_CANONICAL_EQUALITY"
            rationale = (
                f"Pinned source declares literal route name {literal_name!r}, URI {row.get('literal_uri')!r}, "
                f"and action {row.get('action_expression')!r}; the canonical matrix lists that exact literal only "
                f"for {exact_ids[0]}. This is static source ownership only and awards no downstream credit."
            )
        elif len(exact_ids) > 1:
            assert route_id in {"RUN077-ROUTE-2393", "RUN077-ROUTE-2394"}
            assert "{module}" in (row.get("literal_uri") or "")
            assert "ModuleReportController" in (row.get("action_expression") or "")
            classification = "SHARED_RELATION"
            reviewed_ids = exact_ids
            basis = "EXACT_GENERIC_MODULE_ROUTE_NAME_SHARED_CANONICAL_EQUALITY"
            rationale = (
                f"The pinned generic {{module}} report callsite declares {literal_name!r} through "
                "ModuleReportController, and the canonical matrix lists that same literal for each reviewed "
                "module-export target. It is retained as a static shared relation without runtime or credit claims."
            )
        else:
            classification = "EXPLICIT_UNMAPPED_SENTINEL"
            reviewed_ids = []
            basis = "NO_EXACT_LITERAL_ROUTE_NAME_CANONICAL_EQUALITY"
            candidate_count = len(row.get("candidate_feature_ids", []))
            rationale = (
                f"Pinned callsite reviewed at {row['source_anchor']}; {candidate_count} matrix anchor/name-overlap "
                "candidates remain context only. No exact literal route-name equality establishes a canonical "
                "FEATURE-ID, so adjacent-anchor inheritance is prohibited and this row remains explicitly unmapped."
            )

        anchors = [row["source_anchor"]]
        if direct_name_row is not None:
            anchors.append(direct_name_row["source_anchor"])
        decisions.append(
            {
                "route_record_id": route_id,
                "classification": classification,
                "reviewed_feature_ids": reviewed_ids,
                "source_anchors": unique(anchors),
                "rationale": rationale,
                "source_key": row["source_key"],
                "route_method": row["route_method"],
                "literal_uri": row.get("literal_uri"),
                "literal_route_name": literal_name,
                "decision_basis": basis,
                "candidate_feature_ids_reviewed": row.get("candidate_feature_ids", []),
                "framework_reachability": "NOT_EXECUTED",
                "credit_awarded": False,
            }
        )
    return decisions


def build_name_decisions(
    assigned_ids: list[str],
    name_by_id: dict[str, dict],
    route_by_id: dict[str, dict],
    matrix_rows: list[dict[str, str]],
) -> list[dict]:
    matrix_name_index: dict[str, list[str]] = defaultdict(list)
    for matrix_row in matrix_rows:
        for route_name in split_cell(matrix_row["route_names"]):
            matrix_name_index[route_name].append(matrix_row["feature_id"])

    decisions: list[dict] = []
    for name_id in assigned_ids:
        row = name_by_id[name_id]
        literal_name = row["literal_route_name"]
        exact_ids = sorted(set(matrix_name_index.get(literal_name, []))) if literal_name else []
        assert exact_ids == row["candidate_feature_ids"]
        relationship = row["relationship_classification"]
        anchors = [row["source_anchor"]]
        if row["parent_route_callsite_id"]:
            anchors.append(route_by_id[row["parent_route_callsite_id"]]["source_anchor"])
        if row["route_like_sentinel_id"]:
            anchors.append(route_by_id[row["route_like_sentinel_id"]]["source_anchor"])

        if relationship == "ROUTE_GROUP_PREFIX":
            assert not exact_ids
            rationale = (
                f"Confirmed as a {row['group_prefix_kind']} group-prefix name callsite. The literal prefix is "
                "recorded only as source structure; it is not propagated into effective runtime names or feature mappings."
            )
        elif relationship == "DIRECT_COUNTED_ROUTE" and exact_ids:
            rationale = (
                f"Confirmed inside the parent primary route statement. Exact literal {literal_name!r} equality "
                f"links the static name locator to {len(exact_ids)} canonical target(s); no runtime or credit is inferred."
            )
        elif relationship == "DIRECT_COUNTED_ROUTE":
            rationale = (
                "Confirmed inside the parent primary route statement, but no canonical matrix row lists this exact "
                "literal. Parentage is confirmed without feature mapping or group-prefix propagation."
            )
        elif relationship == "FLUENT_REGISTRAR_ROUTE_OUTSIDE_PRIMARY_DENOMINATOR":
            assert row["route_like_sentinel_id"] == "RUN077-ROUTE-SENTINEL-0001"
            assert not exact_ids
            rationale = (
                "Confirmed as the hr.php fluent-registrar sentinel name outside the 3,217 primary denominator; "
                "it remains unmapped and no runtime reachability is claimed."
            )
        else:
            assert relationship == "NON_ROUTE_SCHEDULE" and not exact_ids
            rationale = (
                "Confirmed as a Schedule registration name rather than a route name. It is retained for census "
                "reconciliation only and receives no route or feature mapping."
            )

        decisions.append(
            {
                "name_record_id": name_id,
                "relationship_classification_confirmed": relationship,
                "reviewed_feature_ids": exact_ids,
                "source_anchors": unique(anchors),
                "rationale": rationale,
                "literal_route_name": literal_name,
                "parent_route_callsite_id": row["parent_route_callsite_id"],
                "route_like_sentinel_id": row["route_like_sentinel_id"],
                "group_prefix_kind": row["group_prefix_kind"],
                "effective_runtime_name_propagated": False,
                "credit_awarded": False,
            }
        )
    return decisions


def build_page_decisions(
    assigned_ids: list[str],
    page_by_id: dict[str, dict],
    matrix_by_id: dict[str, dict[str, str]],
) -> list[dict]:
    decisions: list[dict] = []
    for page_id in assigned_ids:
        row = page_by_id[page_id]
        candidates = row["candidate_feature_ids"]
        for feature_id in candidates:
            exact_paths = {
                LINE_SUFFIX_RE.sub("", value)
                for value in split_cell(matrix_by_id[feature_id]["page_files"])
            }
            assert row["page_file"] in exact_paths
        anchors = unique([row["page_file"], *row["render_owner_locators"]])
        if len(candidates) == 1:
            prompt_classification = "Reviewed"
            reviewed_ids = candidates
            rationale = (
                f"Pinned file {row['page_file']} exists with SHA-256 {row['page_file_sha256']} and is selected by "
                f"{row['render_call_count']} literal backend render callsite(s). The canonical matrix lists this exact "
                f"case-sensitive path only for {candidates[0]}; this establishes static identity only."
            )
            basis = "UNIQUE_EXACT_PAGE_PATH_AND_LITERAL_RENDER_SOURCE"
        elif candidates:
            prompt_classification = "Evidence gap"
            reviewed_ids = []
            rationale = (
                f"Pinned page and literal render ownership are established, but the exact path overlaps "
                f"{len(candidates)} canonical targets. Source inspected here does not adjudicate shared ownership, "
                "so candidate overlap is not promoted to mapping."
            )
            basis = "AMBIGUOUS_EXACT_PAGE_PATH_OVERLAP"
        else:
            prompt_classification = "Evidence gap"
            reviewed_ids = []
            rationale = (
                "Pinned page and literal backend render callsite(s) are established, but no canonical matrix page_files "
                "entry matches this exact case-sensitive path. The root remains an explicit static mapping evidence gap."
            )
            basis = "NO_EXACT_CANONICAL_PAGE_PATH"
        decisions.append(
            {
                "page_record_id": page_id,
                "prompt_classification": prompt_classification,
                "reviewed_feature_ids": reviewed_ids,
                "source_anchors": anchors,
                "rationale": rationale,
                "page_file": row["page_file"],
                "page_file_sha256": row["page_file_sha256"],
                "render_call_count": row["render_call_count"],
                "decision_basis": basis,
                "framework_reachability": "NOT_EXECUTED",
                "build_resolution": "NOT_EXECUTED",
                "browser_observation": "NOT_EXECUTED",
                "credit_awarded": False,
            }
        )
    return decisions


def retain_field(original_value: str, rationale: str, bounded_search: dict) -> dict:
    assert original_value == "NOT_ESTABLISHED_CURRENT_AUDIT"
    return {
        "status": "RETAIN_NOT_ESTABLISHED",
        "value": original_value,
        "rationale": rationale,
        "bounded_search": bounded_search,
    }


def retain_rejected_field(
    original_value: str, rationale: str, bounded_search: dict
) -> dict:
    decision = retain_field(original_value, rationale, bounded_search)
    decision["source_anchors"] = []
    return decision


def establish_field(value: str, anchors: list[str], rationale: str, bounded_search: dict) -> dict:
    assert value and value != "NOT_ESTABLISHED_CURRENT_AUDIT"
    return {
        "status": "ESTABLISHED",
        "value": value,
        "source_anchors": unique(anchors),
        "rationale": rationale,
        "bounded_search": bounded_search,
    }


def build_residual_decisions(
    assigned_ids: list[str],
    residual_by_id: dict[str, dict],
    matrix_by_id: dict[str, dict[str, str]],
) -> list[dict]:
    support_search = bounded_test_search(["ClientSupportPlanController", "care-support-plan"])
    assert support_search["evidence"] == []
    governance_pack_test_search = bounded_test_search(
        ["AuditEvidencePackService", "governance.reports.evidence-pack"]
    )
    assert governance_pack_test_search["evidence"] == []

    for line, needle in {
        621: "FinancialReportController::class",
        627: "FinancialReportController::class",
        712: "AuditExportController::class",
        725: "audit-exports.destroy",
    }.items():
        assert_line_contains("routes/finance.php", line, needle)
    assert_line_contains(
        "app/Domain/Finance/Http/Controllers/FinancialReportController.php",
        23,
        "finance/reports/TrialBalance",
    )
    assert_line_contains(
        "app/Domain/Finance/Http/Controllers/AuditExportController.php",
        24,
        "finance/audit-exports/Index",
    )
    assert_line_contains(
        "tests/Feature/Finance/AuditExportSecurityTest.php", 26, "AuditExportService::class"
    )
    for path in FINANCE_STATEMENT_PAGE_FILES:
        assert git_blob_bytes(path)

    assert_line_contains(
        "app/Domain/Governance/Http/Controllers/ActionItemController.php",
        40,
        "Governance/Actions/Index",
    )
    assert_line_contains(
        "app/Domain/Governance/Http/Controllers/ActionItemController.php",
        50,
        "Governance/Actions/Show",
    )
    for path in (
        "resources/js/pages/Governance/Actions/Index.tsx",
        "resources/js/pages/Governance/Actions/Show.tsx",
    ):
        assert git_blob_bytes(path)

    governance_report_slice = "\n".join(
        git_blob_text(
            "app/Domain/Governance/Http/Controllers/ReportController.php"
        ).splitlines()[160:193]
    )
    assert "response()->json" in governance_report_slice
    assert "Inertia::render" not in governance_report_slice

    decisions: list[dict] = []
    for feature_id in assigned_ids:
        residual = residual_by_id[feature_id]
        matrix_row = matrix_by_id[feature_id]
        field_decisions: dict[str, dict] = {}

        if feature_id == "CAP-CLI-CLIENT-SUPPORT-PLAN":
            field_decisions["test_anchors"] = retain_field(
                residual["original_values"]["test_anchors"],
                "No exact pinned test references ClientSupportPlanController or the care-support-plan page token; "
                "broader support-plan prose/model hits are adjacent evidence and are not inherited.",
                support_search,
            )
            outer_anchors = unique(
                split_cell(matrix_row["route_paths"])
                + split_cell(matrix_row["page_files"])
                + split_cell(matrix_row["backend_anchors"])
            )
        elif feature_id == "CAP-FIN-FINANCIAL-STATEMENTS-EXPORT":
            field_decisions["route_paths"] = retain_rejected_field(
                residual["original_values"]["route_paths"],
                "The pinned FinancialReportController surface exposes GET/Inertia report views only. "
                "routes/finance.php:712-725, AuditExportController, FinAuditExport, and AuditExportService "
                "implement the separate encrypted audit-pack lifecycle owned by CAP-FIN-ENCRYPTED-AUDIT-PACK "
                "and cannot be inherited as financial-statement export transport.",
                {
                    "scope": (
                        "Pinned routes/finance.php plus exact FinancialReportController/FinancialReportService owner "
                        "chain; AuditExportController/AuditExportService checked only to reject cross-feature inheritance."
                    ),
                    "terms": [
                        "export",
                        "download",
                        "CSV",
                        "PDF",
                        "FinancialReportController",
                        "FinancialReportService",
                    ],
                    "evidence": [
                        "routes/finance.php:619-630 declares report-view GET routes only",
                        "app/Domain/Finance/Http/Controllers/FinancialReportController.php:16-134 returns seven Inertia renders and no export/download response",
                        "routes/finance.php:711-726 and app/Domain/Finance/Http/Controllers/AuditExportController.php:12-99 use FinAuditExport/AuditExportService for the separately canonical CAP-FIN-ENCRYPTED-AUDIT-PACK",
                    ],
                    "result": "No exact financial-statement export route was established.",
                },
            )
            field_decisions["page_files"] = retain_rejected_field(
                residual["original_values"]["page_files"],
                "The seven finance/reports pages are render/filter views and do not own a financial-statement "
                "export control. resources/js/pages/finance/audit-exports/Index.tsx is the exact page owner of "
                "CAP-FIN-ENCRYPTED-AUDIT-PACK, not this target; adjacent ownership cannot be inherited.",
                {
                    "scope": (
                        "The seven listed finance/reports TSX roots, finance/audit-exports/Index.tsx, and their "
                        "exact pinned render owners."
                    ),
                    "terms": [
                        "export",
                        "download",
                        "CSV",
                        "PDF",
                        "FinancialReportService",
                        "AuditExportService",
                    ],
                    "evidence": [
                        "FinancialReportController.php:23-124 renders the seven report roots without an export response",
                        "the report pages render/filter statements and expose no exact statement-export owner",
                        "AuditExportController.php:24 renders finance/audit-exports/Index for FinAuditExport/AuditExportService; A's own page lane uniquely maps that file to CAP-FIN-ENCRYPTED-AUDIT-PACK",
                    ],
                    "result": "No exact page owner for financial-statement export was established.",
                },
            )
            field_decisions["test_anchors"] = retain_rejected_field(
                residual["original_values"]["test_anchors"],
                "AuditExportSecurityTest.php:8-36 creates FinAuditExport and calls AuditExportService only; it "
                "verifies encrypted audit-pack ZIP storage/download and never references FinancialReportService "
                "or a financial-statement export. Report rendering tests are also not export tests.",
                {
                    "scope": (
                        "All pinned tests/Feature/Finance PHP sources, with exact re-open of AuditExportSecurityTest "
                        "and report-render tests."
                    ),
                    "terms": [
                        "FinancialReportService",
                        "financial statement",
                        "statement export",
                        "export",
                        "download",
                        "CSV",
                        "PDF",
                    ],
                    "evidence": [
                        "tests/Feature/Finance/AuditExportSecurityTest.php:8-36 uses FinAuditExport and AuditExportService only",
                        "tests/Feature/Finance/ReportsRenderTest.php:51-78 exercises GET/rendered report figures only and no export response",
                    ],
                    "result": "No exact financial-statement export test anchor was established.",
                },
            )
            outer_anchors = []
        elif feature_id == "CAP-GOV-ACTION-ITEM-WORKFLOW":
            field_decisions["page_files"] = retain_rejected_field(
                residual["original_values"]["page_files"],
                "Actions/Index.tsx owns list-to-show navigation and Actions/Show.tsx owns completion only. No "
                "pinned resources/js caller exists for actions.store, actions.progress, actions.block, "
                "actions.unblock, or actions.escalate, so these partial pages cannot establish the full "
                "create/progress/block/unblock/escalate workflow.",
                {
                    "scope": (
                        "Pinned Governance/Actions Index and Show pages plus all resources/js callsites for the "
                        "eight accepted governance action routes."
                    ),
                    "terms": [
                        "actions.store",
                        "actions.progress",
                        "actions.block",
                        "actions.unblock",
                        "actions.escalate",
                        "actions.complete",
                    ],
                    "evidence": [
                        "resources/js/pages/Governance/Actions/Index.tsx:8,159-164 imports/links show only",
                        "resources/js/pages/Governance/Actions/Show.tsx:15,91-94 posts complete only",
                        "complete pinned resources/js search found no caller for store/progress/block/unblock/escalate",
                    ],
                    "result": "Partial list/show/complete UI found; no page owner for the full workflow established.",
                },
            )
            outer_anchors = []
        else:
            assert feature_id == "CAP-GOV-AUDIT-EVIDENCE-PACK"
            field_decisions["page_files"] = retain_field(
                residual["original_values"]["page_files"],
                "The exact evidencePack source returns JSON and the export source returns a download; no literal "
                "Inertia render occurs in the bounded owner methods. Absence of a dedicated page is not promoted to "
                "NOT_APPLICABLE without independent review.",
                {
                    "scope": "Pinned ReportController evidencePack/export methods and exact governance evidence service.",
                    "terms": [
                        "evidencePack",
                        "response()->json",
                        "AuditEvidencePackService",
                        "Inertia::render",
                    ],
                    "evidence": [
                        "routes/governance.php:47-48",
                        "app/Domain/Governance/Http/Controllers/ReportController.php:161-193",
                        "app/Domain/Governance/Services/AuditEvidencePackService.php:11",
                    ],
                    "result": "Headless JSON/download source found; no exact page locator established.",
                },
            )
            field_decisions["test_anchors"] = retain_field(
                residual["original_values"]["test_anchors"],
                "No pinned test contains the exact AuditEvidencePackService or governance evidence-pack route token; "
                "tests for other modules' evidence packs are adjacent and are not inherited.",
                governance_pack_test_search,
            )
            outer_anchors = [
                "routes/governance.php:47-48",
                "app/Domain/Governance/Http/Controllers/ReportController.php:161-193",
                "app/Domain/Governance/Services/AuditEvidencePackService.php:11",
            ]

        assert set(field_decisions) == set(residual["missing_fields"])
        decisions.append(
            {
                "feature_id": feature_id,
                "missing_field_decisions": field_decisions,
                "source_anchors": unique(outer_anchors),
                "rationale": (
                    "Each originally missing field received a bounded pinned-source decision. Exact locators are "
                    "established only where the named owner chain resolves directly; otherwise NOT_ESTABLISHED is retained."
                ),
            }
        )
    return decisions


def build_route_name_gap_decisions(
    assigned_ids: list[str],
    gap_by_id: dict[str, dict],
    matrix_by_id: dict[str, dict[str, str]],
    route_rows: list[dict],
    name_by_id: dict[str, dict],
) -> list[dict]:
    decisions: list[dict] = []
    for feature_id in assigned_ids:
        gap = gap_by_id[feature_id]
        matrix_row = matrix_by_id[feature_id]
        assert gap["original_value"] == "NOT_ESTABLISHED_CURRENT_AUDIT"
        exact_rows = [
            row
            for row in route_rows
            if row.get("direct_name_literal")
            and row["candidate_bases"]["matrix_route_anchor_overlap"] == [feature_id]
            and line_is_explicitly_anchored(
                matrix_row, row["route_file"], row["source_line"]
            )
        ]

        if feature_id == "CAP-FIN-FINANCIAL-STATEMENTS-EXPORT":
            assert not exact_rows

        literal_names = sorted(
            {row["direct_name_literal"] for row in exact_rows},
            key=lambda value: value.encode("utf-8"),
        )
        name_anchors = unique(
            [
                name_by_id[row["direct_name_callsite_id"]]["source_anchor"]
                for row in exact_rows
            ]
        )
        all_explicit_rows = [
            row
            for row in route_rows
            if row.get("direct_name_literal")
            and feature_id in row["candidate_bases"]["matrix_route_anchor_overlap"]
            and line_is_explicitly_anchored(
                matrix_row, row["route_file"], row["source_line"]
            )
        ]
        all_explicit_anchors = unique(
            [
                name_by_id[row["direct_name_callsite_id"]]["source_anchor"]
                for row in all_explicit_rows
            ]
        )

        if feature_id == "CAP-FIN-FINANCIAL-STATEMENTS-EXPORT":
            assert not literal_names
            route_name_decision = {
                "status": "RETAIN_NOT_ESTABLISHED",
                "value": gap["original_value"],
                "source_anchors": [],
                "rationale": (
                    "reports.* literals name report-view GET routes, while audit-exports.* literals name the "
                    "separately canonical encrypted audit-pack lifecycle. None names a financial-statement "
                    "export transport, so view and cross-feature names cannot be inherited."
                ),
                "bounded_search": {
                    "scope": (
                        "Pinned direct name callsites in routes/finance.php:619-630 and :711-726, checked against "
                        "the two distinct canonical owner chains."
                    ),
                    "terms": [
                        "reports.trial-balance",
                        "reports.profit-loss",
                        "reports.balance-sheet",
                        "reports.cash-flow",
                        "audit-exports.*",
                    ],
                    "evidence": [
                        "routes/finance.php:621-627 are report-view names",
                        "routes/finance.php:713-725 are FinAuditExport/AuditExportService names owned by CAP-FIN-ENCRYPTED-AUDIT-PACK",
                    ],
                    "result": "Zero exact financial-statement export route names established.",
                },
            }
            outer_anchors = []
            rationale = (
                "No exact literal financial-statement export route-name identity was established; report-view "
                "and encrypted audit-pack names are adjacent or cross-feature evidence only."
            )
        elif literal_names:
            route_name_decision = {
                "status": "ESTABLISHED",
                "value": "; ".join(literal_names),
                "source_anchors": name_anchors,
                "rationale": (
                    "Exact literal ->name(...) callsites are established from a uniquely assigned explicit matrix "
                    "line/range (or the separately proven finance owner chain). Values are source literals only: "
                    "group-prefix propagation and effective runtime-name claims are prohibited."
                ),
                "bounded_search": {
                    "scope": "Pinned primary route rows, direct-name relationships, and explicit matrix route line/ranges.",
                    "terms": split_cell(matrix_row["route_paths"]),
                    "evidence": name_anchors,
                    "evidence_locator_sha256": canonical_list_sha256(name_anchors),
                    "result": f"{len(literal_names)} unique literal name value(s) established without prefix propagation.",
                },
            }
            outer_anchors = name_anchors
            rationale = (
                "The missing route_names cell can be populated with exact pinned literal name callsites, subject to "
                "the explicit no-runtime-expansion boundary."
            )
        else:
            source_anchors = unique(
                split_cell(matrix_row["route_paths"])
                + split_cell(matrix_row["backend_anchors"])
                + split_cell(matrix_row["page_files"])
            )
            assert source_anchors
            route_name_decision = {
                "status": "RETAIN_NOT_ESTABLISHED",
                "value": gap["original_value"],
                "rationale": (
                    "The bounded static scan found no direct literal name callsite uniquely bound by an explicit "
                    "feature line/range. Ambiguous overlaps, whole-file anchors, registrar prefixes, and framework "
                    "expansion are not inherited, so NOT_ESTABLISHED is retained."
                ),
                "bounded_search": {
                    "scope": "Pinned primary route rows, direct-name relationships, and explicit matrix route line/ranges.",
                    "terms": split_cell(matrix_row["route_paths"]),
                    "evidence": all_explicit_anchors,
                    "evidence_locator_sha256": canonical_list_sha256(
                        all_explicit_anchors
                    ),
                    "result": (
                        f"{len(all_explicit_anchors)} anchored literal callsite(s) were ambiguous or non-unique; "
                        "zero exact names were promoted."
                    ),
                },
            }
            outer_anchors = source_anchors
            rationale = (
                "No exact literal route-name identity survived the uniqueness and explicit-line boundary; the "
                "canonical gap remains open."
            )

        decisions.append(
            {
                "feature_id": feature_id,
                "route_name_decision": route_name_decision,
                "source_anchors": unique(outer_anchors),
                "rationale": rationale,
            }
        )
    return decisions


def assert_decision_ids(
    rows: list[dict], id_key: str, expected_ids: list[str]
) -> None:
    actual = [row[id_key] for row in rows]
    assert len(actual) == len(set(actual)) == len(expected_ids)
    assert actual == expected_ids


def main() -> None:
    manifest_path = AUDIT_DIR / MANIFEST_REL
    manifest = read_json(manifest_path)
    validate_pins(manifest)
    matrix_rows, matrix_by_id = load_matrix()
    canonical_feature_ids = set(matrix_by_id)

    partition = next(
        row
        for row in manifest["partitions"]["records"]
        if row["partition_id"] == PARTITION_ID
    )
    route_ids = partition["route_record_ids"] + partition["route_like_sentinel_ids"]
    name_ids = partition["name_record_ids"]
    page_ids = partition["page_record_ids"]
    residual_ids = partition["residual_feature_ids"]
    route_name_gap_ids = partition["route_name_gap_feature_ids"]

    route_by_id = {
        row["route_record_id"]: row
        for row in manifest["route_universe"]["primary_route_facade_callsites"]
    }
    route_by_id.update(
        {
            row["route_record_id"]: row
            for row in manifest["route_universe"]["route_like_sentinels"]
        }
    )
    name_by_id = {
        row["name_record_id"]: row
        for row in manifest["route_universe"]["fluent_name_callsites"]
    }
    page_by_id = {
        row["page_record_id"]: row
        for row in manifest["page_universe"]["page_roots"]
    }
    residual_by_id = {
        row["feature_id"]: row
        for row in manifest["residual_scoped_gaps"]["records"]
    }
    route_name_gap_by_id = {
        row["feature_id"]: row for row in manifest["route_name_gaps"]["records"]
    }

    assigned_routes = [route_by_id[row_id] for row_id in route_ids]
    assigned_names = [name_by_id[row_id] for row_id in name_ids]
    assigned_pages = [page_by_id[row_id] for row_id in page_ids]
    validate_manifest_source_rows(assigned_routes, assigned_names, assigned_pages)

    route_decisions = build_route_decisions(
        route_ids, route_by_id, name_by_id, matrix_rows
    )
    name_decisions = build_name_decisions(
        name_ids, name_by_id, route_by_id, matrix_rows
    )
    page_decisions = build_page_decisions(page_ids, page_by_id, matrix_by_id)
    residual_decisions = build_residual_decisions(
        residual_ids, residual_by_id, matrix_by_id
    )
    route_name_gap_decisions = build_route_name_gap_decisions(
        route_name_gap_ids,
        route_name_gap_by_id,
        matrix_by_id,
        manifest["route_universe"]["primary_route_facade_callsites"],
        name_by_id,
    )

    assert_decision_ids(route_decisions, "route_record_id", route_ids)
    assert_decision_ids(name_decisions, "name_record_id", name_ids)
    assert_decision_ids(page_decisions, "page_record_id", page_ids)
    assert_decision_ids(residual_decisions, "feature_id", residual_ids)
    assert_decision_ids(
        route_name_gap_decisions, "feature_id", route_name_gap_ids
    )
    assert all(
        set(row["reviewed_feature_ids"]).issubset(canonical_feature_ids)
        for row in route_decisions + name_decisions + page_decisions
    )
    assert all(
        not row["reviewed_feature_ids"]
        for row in route_decisions
        if row["classification"] == "EXPLICIT_UNMAPPED_SENTINEL"
    )

    route_counts = Counter(row["classification"] for row in route_decisions)
    page_counts = Counter(row["prompt_classification"] for row in page_decisions)
    residual_field_counts = Counter(
        field_decision["status"]
        for row in residual_decisions
        for field_decision in row["missing_field_decisions"].values()
    )
    route_name_gap_counts = Counter(
        row["route_name_decision"]["status"] for row in route_name_gap_decisions
    )
    assert route_counts == Counter(
        {"EXPLICIT_UNMAPPED_SENTINEL": 1015, "OWNER": 56, "SHARED_RELATION": 2}
    )
    assert page_counts == Counter({"Reviewed": 156, "Evidence gap": 81})
    assert residual_field_counts == Counter({"RETAIN_NOT_ESTABLISHED": 7})
    assert route_name_gap_counts == Counter(
        {"ESTABLISHED": 30, "RETAIN_NOT_ESTABLISHED": 52}
    )

    counts = {
        "route_decisions": len(route_decisions),
        "name_decisions": len(name_decisions),
        "page_decisions": len(page_decisions),
        "residual_scoped_decisions": len(residual_decisions),
        "route_name_gap_decisions": len(route_name_gap_decisions),
        "route_classifications": dict(sorted(route_counts.items())),
        "name_relationship_confirmations": dict(
            sorted(
                Counter(
                    row["relationship_classification_confirmed"]
                    for row in name_decisions
                ).items()
            )
        ),
        "page_prompt_classifications": dict(sorted(page_counts.items())),
        "residual_field_statuses": dict(sorted(residual_field_counts.items())),
        "route_name_gap_statuses": dict(sorted(route_name_gap_counts.items())),
        "reviewed_route_feature_links": sum(
            len(row["reviewed_feature_ids"]) for row in route_decisions
        ),
        "reviewed_name_feature_links": sum(
            len(row["reviewed_feature_ids"]) for row in name_decisions
        ),
        "reviewed_page_feature_links": sum(
            len(row["reviewed_feature_ids"]) for row in page_decisions
        ),
        "runtime_credit": 0,
        "application_browser_credit": 0,
        "executed_test_credit": 0,
        "benchmark_mapping_credit": 0,
        "pass_credit": 0,
        "completion_credit": 0,
    }
    for key, expected in EXPECTED_COUNTS.items():
        assert counts[key] == expected

    completion_test = {
        "partition_id": PARTITION_ID,
        "assigned_counts": EXPECTED_COUNTS,
        "decision_counts": {key: counts[key] for key in EXPECTED_COUNTS},
        "every_assigned_id_exactly_once": True,
        "no_extra_ids": True,
        "all_required_decision_keys_present": True,
        "all_reviewed_feature_ids_canonical": True,
        "candidate_overlap_not_auto_promoted": True,
        "group_prefix_runtime_names_not_propagated": True,
        "producer_partition_complete": True,
        "independent_review_complete": False,
        "integration_complete": False,
        "audit_complete": False,
    }

    payload = {
        "schema_version": 1,
        "run_id": "RUN-078A-ROUTE-PAGE-CLASSIFICATION-PRODUCER",
        "status": "PARTITION_A_STATIC_CLASSIFICATION_COMPLETE_PENDING_INDEPENDENT_REVIEW_ZERO_DOWNSTREAM_CREDIT",
        "generated_on": GENERATED_ON,
        "pins": {
            "manifest_path": MANIFEST_REL,
            "manifest_sha256": MANIFEST_SHA256,
            "checkpoint_commit": CHECKPOINT_COMMIT,
            "checkpoint_tree": CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "partition_id": PARTITION_ID,
            "matrix_path": MATRIX_REL,
            "matrix_sha256": manifest["pins"]["inputs"][MATRIX_REL],
            "generator_path": GENERATOR_REL,
            "generator_sha256": current_generator_sha256(),
        },
        "partition_id": PARTITION_ID,
        "counts": counts,
        "route_decisions": route_decisions,
        "name_decisions": name_decisions,
        "page_decisions": page_decisions,
        "residual_scoped_decisions": residual_decisions,
        "route_name_gap_decisions": route_name_gap_decisions,
        "completion_test": completion_test,
        "credit_boundary": manifest["credit_boundary"],
        "wrote_files": True,
        "write_scope": [GENERATOR_REL, OUTPUT_REL],
        "outside_scope_files_written": [],
        "attestation": (
            "Partition-A producer evidence only. Every assigned ID has one static decision. No Laravel boot, "
            "framework/provider expansion, runtime, database, build, application browser, executed tests, benchmark "
            "mapping, ease, Pass, release, completion, or audit-complete credit occurred. The only producer writes "
            "are the assigned generator and raw JSON output."
        ),
    }

    required_top = set(
        manifest["review_contract"]["producer_required_top_level_keys"]
    )
    required_top.update({"write_scope", "outside_scope_files_written"})
    assert required_top.issubset(payload)
    assert payload["credit_boundary"] == manifest["credit_boundary"]
    assert not any(payload["credit_boundary"].values())

    required_route = set(
        manifest["review_contract"]["producer_required_route_decision_keys"]
    )
    required_name = set(
        manifest["review_contract"]["producer_required_name_decision_keys"]
    )
    required_page = set(
        manifest["review_contract"]["producer_required_page_decision_keys"]
    )
    required_residual = set(
        manifest["review_contract"][
            "producer_required_residual_scoped_decision_keys"
        ]
    )
    required_gap = set(
        manifest["review_contract"]["producer_required_route_name_gap_decision_keys"]
    )
    assert all(required_route.issubset(row) for row in route_decisions)
    assert all(required_name.issubset(row) for row in name_decisions)
    assert all(required_page.issubset(row) for row in page_decisions)
    assert all(required_residual.issubset(row) for row in residual_decisions)
    assert all(required_gap.issubset(row) for row in route_name_gap_decisions)

    encoded = (json.dumps(payload, indent=2, ensure_ascii=False) + "\n").encode(
        "utf-8"
    )
    assert json.loads(encoded.decode("utf-8")) == payload
    candidate_sha256 = sha256_bytes(encoded)
    OUTPUT_PATH.write_bytes(encoded)
    assert sha256_file(OUTPUT_PATH) == candidate_sha256
    assert read_json(OUTPUT_PATH) == payload
    print(
        json.dumps(
            {
                "status": payload["status"],
                "output": OUTPUT_REL,
                "sha256": candidate_sha256,
                "generator_sha256": current_generator_sha256(),
                "counts": counts,
            },
            separators=(",", ":"),
        )
    )


if __name__ == "__main__":
    main()
