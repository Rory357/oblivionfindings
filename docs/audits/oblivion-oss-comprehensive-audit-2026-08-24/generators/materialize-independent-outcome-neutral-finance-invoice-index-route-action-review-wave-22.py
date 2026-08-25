#!/usr/bin/env python3
"""Materialize the three fresh RUN-137 invoice-index semantic reviews.

Two independent reviewers adjudicated the same bounded name-only route-action
candidate. A third fresh reviewer reconciled both votes. The two votes produce
one synthesized action decision, so reviewer agreement cannot double-count the
route or controller-action bridge. Only later bounded static route ownership
and bridge integration is authorized; all current overlay, page, correctness,
runtime, test, browser, benchmark, release, and completion credit remains zero.
"""

from __future__ import annotations

import hashlib
import json
import os
import re
import subprocess
from collections import Counter
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
COHORT_PATH = (
    AUDIT_DIR
    / "evidence/source/root-run-137-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.json"
)
COHORT_GENERATOR = (
    AUDIT_DIR
    / "generators/build-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.py"
)
MATRIX_PATH = AUDIT_DIR / "03-feature-to-benchmark-matrix.csv"
OUTPUT_PATH = (
    AUDIT_DIR
    / "evidence/source/raw-run-137r-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.json"
)

CHECKPOINT_COMMIT = "18b841ea03c89d732fd4786618d1af3b6378211c"
CHECKPOINT_TREE = "51078a6ce8472644e032d934dea75cd5a718efda"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
TESTS_TREE = "fef0122b31fdccbe2f9f805f7515666c74e2880a"
COHORT_SHA256 = "e2a6a346365ada6013b82f4e29aa955ffcedf7f3b53ab88279c700407d3012bc"
COHORT_GENERATOR_SHA256 = "93766689117c88173a08f8548a04d7e62f00eadf71fb7fefa302936e540c9bd9"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
SOURCE_PACKET_SHA256 = "d357634ecafca5373cc7141d4d604599431ac836ed2e7274bd5b508d2fadf81e"
FEATURE_ID = "CAP-FIN-BILLING-INVOICE-LIFECYCLE"
CANDIDATE_ID = "RUN137-FINANCE-INVOICE-INDEX-ROUTE-ACTION-01"
CANDIDATE_RECORD_SHA256 = "ef62ffbe177d4ffb6474492d54a2d08e90ee342f4f05d5bd5094f0dc47c84d8d"
OWNER_SOURCE_RECORD_KEY = "route|RUN077-ROUTE-0634|CAP-FIN-BILLING-INVOICE-LIFECYCLE"
ACTION_KEY = (
    "RUN077-ROUTE-0634|app/Domain/Finance/Http/Controllers/InvoiceController.php:index|"
    "CAP-FIN-BILLING-INVOICE-LIFECYCLE"
)
BRIDGE_KEY = [
    "app/Domain/Finance/Http/Controllers/InvoiceController.php",
    "index",
    FEATURE_ID,
]

GENERATOR_RELATIVE = Path(__file__).relative_to(REPO).as_posix()
OUTPUT_RELATIVE = OUTPUT_PATH.relative_to(REPO).as_posix()
ALLOWED_DIRTY_PATHS = {GENERATOR_RELATIVE, OUTPUT_RELATIVE}


EXPANDED_SOURCE_SPECS: dict[str, dict[str, Any]] = {
    "resources/js/pages/finance/invoices/Index.tsx": {
        "sha256": "316c3abf37728ffadf1a40042714b84e196b2a91a83c512e833e4ae9af920a13",
        "expanded_review_loci": ["resources/js/pages/finance/invoices/Index.tsx:1-583"],
        "reason": "widen the canonical index page through both manager-only invoice-dialog callsites",
    },
    "resources/js/pages/finance/invoices/Show.tsx": {
        "sha256": "815ac5992fafa4c4026db4658d311e9505494ed610f48dab9da8082afec37c19",
        "expanded_review_loci": [
            "resources/js/pages/finance/invoices/Show.tsx:92-190",
            "resources/js/pages/finance/invoices/Show.tsx:467-484",
        ],
        "reason": "include the sibling invoice-detail lifecycle callers without inheriting page ownership",
    },
    "resources/js/components/finance/new-invoice-dialog.tsx": {
        "sha256": "46bb09ed60af52a5e31a13a2e65f6c18aebcbdd3fa8e7312243024e8bc56ee45",
        "expanded_review_loci": ["resources/js/components/finance/new-invoice-dialog.tsx:94-251"],
        "reason": "newly follow the manager-only invoice create/edit modal and raw client-id request contract",
    },
    "resources/js/components/finance/record-receipt-dialog.tsx": {
        "sha256": "1770f05fbc3c0b9ac258900ea90434245a83dde1b995a962417d7c63e69488d5",
        "expanded_review_loci": ["resources/js/components/finance/record-receipt-dialog.tsx:48-102"],
        "reason": "newly follow the receipt caller fed by the index amount-due projection",
    },
    "app/Domain/Finance/Services/PaymentSettlementSiteScope.php": {
        "sha256": "cd837fbf242cb6d94307a141d2df7ce9ea0bd79c31c7de2f80b8517de243645f",
        "expanded_review_loci": ["app/Domain/Finance/Services/PaymentSettlementSiteScope.php:15-122"],
        "reason": "newly compare canonical settlement-Site scope with the index allocation summary query",
    },
    "tests/Feature/Finance/PaymentAllocationIntegrityTest.php": {
        "sha256": "a3fd9da7365aad939c2b2dc5d8a0c348f2e00b1fa0f493a6cd222a73cda6aa50",
        "expanded_review_loci": [
            "tests/Feature/Finance/PaymentAllocationIntegrityTest.php:88-254",
            "tests/Feature/Finance/PaymentAllocationIntegrityTest.php:596-625",
        ],
        "reason": "newly inspect unexecuted allocation integrity and immutable settlement-Site assertions",
    },
    "resources/js/components/app-sidebar.tsx": {
        "sha256": "482fcfd319695ec22da2598abe4c4f00f95c27be42d93a9d79f97f695befb458",
        "expanded_review_loci": ["resources/js/components/app-sidebar.tsx:1843-1861"],
        "reason": "widen the static invoice navigation context without converting it to ownership proof",
    },
    "resources/js/components/finance/receivables-hub.tsx": {
        "sha256": "70a93c6f81f817cf584af58f7306de8e90c545d68f8e8ce22e8123b50061ed65",
        "expanded_review_loci": [
            "resources/js/components/finance/receivables-hub.tsx:38-58",
            "resources/js/components/finance/receivables-hub.tsx:125-145",
        ],
        "reason": "widen the invoice hub link and count-consumer context",
    },
    "resources/js/pages/finance/billing/Index.tsx": {
        "sha256": "ad9c33a4a3573570d447e4085c73cb3ca9c986c5a67b4f29018ede125bda454d",
        "expanded_review_loci": ["resources/js/pages/finance/billing/Index.tsx:211-226"],
        "reason": "widen the billing-hub invoice navigation callsite",
    },
    "resources/js/pages/operations/service-agreements/Show.tsx": {
        "sha256": "295774b0edcd71923b685e0f023ff1a76bb0fbf797d6fd463b9a18863a467378",
        "expanded_review_loci": ["resources/js/pages/operations/service-agreements/Show.tsx:2060-2075"],
        "reason": "widen the service-agreement caller carrying an untrusted client-id filter",
    },
    "app/Http/Middleware/HandleInertiaRequests.php": {
        "sha256": "9c3020ee317545f83d91b07560dc73ba45f3c90a16f24ba17cc86730ef59099f",
        "expanded_review_loci": [
            "app/Http/Middleware/HandleInertiaRequests.php:138-144",
            "app/Http/Middleware/HandleInertiaRequests.php:990-1004",
        ],
        "reason": "widen the shared finance hub-count and exact AR permission context",
    },
    "app/Domain/Finance/Services/FinanceHubCountsService.php": {
        "sha256": "2165eacf985fe2ca6ca9bd51845ff38578930d0fe337b211c6ad1ab7e45884f7",
        "expanded_review_loci": ["app/Domain/Finance/Services/FinanceHubCountsService.php:48-105"],
        "reason": "newly follow application-wide finance hub counts used beside the invoice index",
    },
}


INDEPENDENT_REVIEW_SPECS: tuple[dict[str, Any], ...] = (
    {
        "review_id": "RUN137R-INDEPENDENT-REVIEW-A",
        "reviewer_task_path": "/root/run137_invoice_semantic_a",
        "outcome": "OWNER_ROUTE_ACTION",
        "confidence": "HIGH",
        "source_loci": [
            "03-feature-to-benchmark-matrix.csv:57",
            "routes/finance.php:675",
            "app/Domain/Finance/Http/Controllers/InvoiceController.php:37-125",
            "resources/js/pages/finance/invoices/Index.tsx:118-164",
        ],
        "rationale": (
            "The exact case-sensitive invoices.index matrix token supplies the sole feature candidate, and the "
            "selected action directly constructs, paginates, summarizes, and literally renders the canonical "
            "finance/invoices/Index surface. It is neither an alias nor an adjacency-derived relation."
        ),
        "material_observations": [
            "Unique InvoiceController::index resolution is review context, not a second feature-identity lane.",
            "Site, privacy, allocation, summary, and response-minimization defects affect correctness, not direct static ownership.",
        ],
    },
    {
        "review_id": "RUN137R-INDEPENDENT-REVIEW-B",
        "reviewer_task_path": "/root/run137_invoice_semantic_b",
        "outcome": "OWNER_ROUTE_ACTION",
        "confidence": "HIGH",
        "source_loci": [
            "03-feature-to-benchmark-matrix.csv:57",
            "routes/finance.php:674-676",
            "app/Domain/Finance/Http/Controllers/InvoiceController.php:37-125",
            "resources/js/pages/finance/invoices/Index.tsx:123-180",
            "resources/js/pages/finance/invoices/Show.tsx:92-118",
        ],
        "rationale": (
            "An independent source trace reaches the canonical invoice hub and its literal Index render through "
            "the selected controller method. Existing Index and Show page owners remain contextual and are not "
            "inherited, while the concrete index action still directly realises the invoice-list component."
        ),
        "material_observations": [
            "Frontend callers and existing page owners corroborate discoverability only and supply no new page credit.",
            "Unsafe or incomplete authorization and query semantics do not convert the direct action into shared, alias, dead, or gap ownership.",
        ],
    },
)


CANDIDATE_ASSURANCE_FINDINGS: list[dict[str, Any]] = [
    {
        "finding_id": "RUN137R-CANDIDATE-SITE-PRIVACY-DIRECT-FILTER-CLIENTLESS",
        "severity": "P1",
        "category": "approved_site_privacy_direct_filter_and_canonical_ownership",
        "loci": [
            "app/Domain/Finance/Http/Controllers/InvoiceController.php:39-80",
            "app/Models/Client.php:1-80",
            "app/Services/UserSiteAccessService.php:70-153",
            "docs/architecture/single-tenant-application.md:1-21",
        ],
        "observation": (
            "The list and summary use legacy organisation storage context, and a raw client_id filter is applied "
            "without first converging the Client and invoice through approved-Site scope. Clientless or funder "
            "invoices leave an additional canonical Site/privacy boundary unresolved."
        ),
        "correctness_credit_authorized": False,
    },
    {
        "finding_id": "RUN137R-CANDIDATE-ALLOCATION-PROVENANCE-GROSS-KPI",
        "severity": "P1",
        "category": "allocation_provenance_and_summary_correctness",
        "loci": [
            "app/Domain/Finance/Http/Controllers/InvoiceController.php:82-105",
            "app/Domain/Finance/Models/FinPaymentAllocation.php:1-111",
            "app/Domain/Finance/Services/PaymentSettlementSiteScope.php:15-122",
        ],
        "observation": (
            "The per-page allocation aggregate is not constrained by canonical organisation, settlement Site, "
            "allocation type, or integrity provenance, while total_outstanding sums gross invoice totals rather "
            "than outstanding balances."
        ),
        "correctness_credit_authorized": False,
    },
    {
        "finding_id": "RUN137R-CANDIDATE-VIEW-ONLY-LINE-MINIMIZATION",
        "severity": "P1",
        "category": "response_data_minimization",
        "loci": [
            "app/Domain/Finance/Http/Controllers/InvoiceController.php:41-44",
            "app/Domain/Finance/Http/Controllers/InvoiceController.php:107-123",
            "resources/js/pages/finance/invoices/Index.tsx:53-79",
            "resources/js/pages/finance/invoices/Index.tsx:565-583",
        ],
        "observation": (
            "Invoice lines are eagerly returned to every finance.ar.view caller even though the frozen page uses "
            "them to prefill manager-only edit flows. Static ownership does not authorize that disclosure."
        ),
        "correctness_credit_authorized": False,
    },
    {
        "finding_id": "RUN137R-CANDIDATE-CLIENT-PICKER-PERMISSION-COUPLING",
        "severity": "P1",
        "category": "permission_coupling_and_site_scope",
        "loci": [
            "app/Domain/Finance/Http/Controllers/InvoiceController.php:107-123",
            "app/Domain/Finance/Http/Controllers/InvoiceController.php:746-760",
            "app/Services/UserSiteAccessService.php:70-153",
        ],
        "observation": (
            "The manager-only invoice client picker asks UserSiteAccessService to accept reports.viewAny as a "
            "global fallback. That unrelated permission does not prove finance invoice authority or exact Site scope."
        ),
        "correctness_credit_authorized": False,
    },
    {
        "finding_id": "RUN137R-CANDIDATE-APPLICATION-WIDE-SUMMARY-COUNTS",
        "severity": "P2",
        "category": "application_wide_metadata_and_query_efficiency",
        "loci": [
            "app/Domain/Finance/Http/Controllers/InvoiceController.php:98-105",
            "app/Http/Middleware/HandleInertiaRequests.php:138-144",
            "app/Domain/Finance/Services/FinanceHubCountsService.php:48-105",
        ],
        "observation": (
            "The action loads every application-context invoice into PHP for summary derivation while shared "
            "middleware also computes application-wide finance hub counts. Neither query path proves approved-Site "
            "privacy or efficient bounded aggregation."
        ),
        "correctness_credit_authorized": False,
    },
    {
        "finding_id": "RUN137R-CANDIDATE-CALLER-AUTHORITY-COMPLETENESS",
        "severity": "P2",
        "category": "caller_authority_and_discoverability",
        "loci": [
            "resources/js/pages/finance/invoices/Index.tsx:118-164",
            "resources/js/components/finance/receivables-hub.tsx:38-58",
            "resources/js/components/finance/receivables-hub.tsx:125-145",
            "resources/js/components/app-sidebar.tsx:1843-1861",
            "resources/js/pages/operations/service-agreements/Show.tsx:2060-2075",
            "resources/js/pages/finance/billing/Index.tsx:211-226",
        ],
        "observation": (
            "Static links and filter callers, including a raw client_id query parameter, establish discoverability "
            "only. They do not prove caller authority, route reachability, runtime behavior, or privacy correctness."
        ),
        "correctness_credit_authorized": False,
    },
]


SHARED_ASSURANCE_FINDINGS: list[dict[str, Any]] = [
    {
        "finding_id": "RUN137R-SHARED-ACTION-TEST-GAP",
        "severity": "P1",
        "category": "executable_assurance",
        "loci": [
            "tests/Feature/Finance/InvoiceIndexReceiptTest.php:1-93",
            "tests/Feature/Finance/PaymentAllocationIntegrityTest.php:88-254",
            "tests/Feature/Finance/PaymentAllocationIntegrityTest.php:596-625",
            "tests/Browser/Finance/FinanceInvoicesTest.php:1-24",
        ],
        "observation": (
            "Frozen tests do not establish the selected index action's foreign-Site, no-Site, forged client_id, "
            "view-only minimization, and global-Site positive contracts. No test was executed in this review."
        ),
        "correctness_credit_authorized": False,
    },
    {
        "finding_id": "RUN137R-SHARED-SOURCE-PACKET-EXPANSION",
        "severity": "EVIDENCE_BOUNDARY",
        "category": "source_packet_completeness",
        "loci": [],
        "observation": (
            "Semantic review pinned seven widened packet files and five newly followed files. This bounded "
            "expansion does not rewrite the cohort's explicit incompleteness or grant correctness credit."
        ),
        "correctness_credit_authorized": False,
    },
    {
        "finding_id": "RUN137R-SHARED-PAGE-CALLER-SIBLING-NONINHERITANCE",
        "severity": "EVIDENCE_BOUNDARY",
        "category": "ownership_noninheritance",
        "loci": [
            "resources/js/pages/finance/invoices/Index.tsx:118-164",
            "resources/js/pages/finance/invoices/Show.tsx:92-118",
        ],
        "observation": (
            "The selected Index render, existing Index owner, sibling Show owner, and static callers remain "
            "non-inheritable context. No page owner is added, reassigned, reopened, or re-credited."
        ),
        "correctness_credit_authorized": False,
    },
]


def sha256_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def canonical_json_sha256(value: Any) -> str:
    encoded = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return sha256_bytes(encoded.encode("utf-8"))


def canonical_list_sha256(values: list[str] | set[str] | tuple[str, ...]) -> str:
    return sha256_bytes("\n".join(sorted(values)).encode("utf-8"))


def reject_duplicate_keys(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    value: dict[str, Any] = {}
    for key, item in pairs:
        if key in value:
            raise ValueError(f"duplicate JSON key: {key}")
        value[key] = item
    return value


def strict_json_loads(text: str) -> Any:
    return json.loads(text, object_pairs_hook=reject_duplicate_keys)


def load_json(path: Path) -> dict[str, Any]:
    value = strict_json_loads(path.read_text(encoding="utf-8"))
    assert isinstance(value, dict), path
    return value


def git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args],
        cwd=REPO,
        check=True,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    return result.stdout.strip()


def current_dirty_paths() -> set[str]:
    rows = git("status", "--porcelain=v1", "--untracked-files=all").splitlines()
    paths: set[str] = set()
    for row in rows:
        assert len(row) >= 4, row
        path = row[3:].replace("\\", "/")
        if " -> " in path:
            old_path, new_path = path.split(" -> ", 1)
            paths.add(old_path)
            paths.add(new_path)
        else:
            paths.add(path)
    return paths


def locus_path_and_range(locus: str) -> tuple[Path, int, int]:
    relative, line_spec = locus.rsplit(":", 1)
    match = re.fullmatch(r"(\d+)(?:-(\d+))?", line_spec)
    assert match, locus
    path = REPO / relative
    if not path.is_file():
        path = AUDIT_DIR / relative
    assert path.is_file(), locus
    start = int(match.group(1))
    end = int(match.group(2) or start)
    return path, start, end


def assert_locus(locus: str) -> None:
    path, start, end = locus_path_and_range(locus)
    line_count = len(path.read_text(encoding="utf-8").splitlines())
    assert 1 <= start <= end <= line_count, (locus, line_count)


def assert_workspace() -> None:
    assert os.environ.get("PYTHONDONTWRITEBYTECODE") == "1"
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("rev-parse", "HEAD:tests") == TESTS_TREE
    assert current_dirty_paths() <= ALLOWED_DIRTY_PATHS, current_dirty_paths()
    assert git("status", "--porcelain", "--", "app", "routes", "resources/js", "tests") == ""
    assert not list(AUDIT_DIR.rglob("__pycache__"))
    assert sha256_file(COHORT_PATH) == COHORT_SHA256
    assert sha256_file(COHORT_GENERATOR) == COHORT_GENERATOR_SHA256
    assert sha256_file(MATRIX_PATH) == MATRIX_SHA256


def assert_pinned_application_file(relative: str, expected_sha256: str) -> tuple[str, str]:
    path = REPO / relative
    assert path.is_file(), relative
    assert sha256_file(path) == expected_sha256, relative
    head_blob = git("rev-parse", f"HEAD:{relative}")
    application_blob = git("rev-parse", f"{APPLICATION_COMMIT}:{relative}")
    assert head_blob == application_blob, relative
    return head_blob, application_blob


def build_source_packet_expansions(cohort: dict[str, Any]) -> list[dict[str, Any]]:
    packet_by_path = {
        row["path"]: row for row in cohort["source_review_packet"]["required_source_files"]
    }
    expansions: list[dict[str, Any]] = []
    for relative, spec in EXPANDED_SOURCE_SPECS.items():
        head_blob, application_blob = assert_pinned_application_file(relative, spec["sha256"])
        original = packet_by_path.get(relative)
        for locus in spec["expanded_review_loci"]:
            assert_locus(locus)
        expansions.append(
            {
                "path": relative,
                "sha256": spec["sha256"],
                "head_blob_id": head_blob,
                "application_commit_blob_id": application_blob,
                "head_matches_application_commit_blob": True,
                "original_packet_present": original is not None,
                "original_review_loci": original["review_loci"] if original else [],
                "expanded_review_loci": spec["expanded_review_loci"],
                "expansion_reason": spec["reason"],
                "expansion_changes_original_packet_bytes": False,
                "expansion_authorizes_correctness_credit": False,
            }
        )
    assert len(expansions) == 12
    assert Counter(row["original_packet_present"] for row in expansions) == {True: 7, False: 5}
    return expansions


def build() -> dict[str, Any]:
    assert_workspace()
    cohort = load_json(COHORT_PATH)
    assert cohort["run_id"] == "RUN-137-OUTCOME-NEUTRAL-FINANCE-INVOICE-INDEX-ROUTE-ACTION-COHORT-WAVE-22"
    assert cohort["status"] == "ONE_NAME_ONLY_FINANCE_INVOICE_INDEX_ROUTE_ACTION_CANDIDATE_PENDING_FRESH_REVIEW_ZERO_CREDIT"
    assert cohort["pins"]["checkpoint_commit"] == "9da5bbfa5a575f272cee2389ab5de5178e063c03"
    assert cohort["pins"]["checkpoint_tree"] == "4b84050bc4e960a17cf8321313677cfe53134c28"
    assert cohort["pins"]["application_commit"] == APPLICATION_COMMIT
    assert cohort["pins"]["application_tree"] == APPLICATION_TREE
    assert cohort["source_review_packet"]["source_review_packet_sha256"] == SOURCE_PACKET_SHA256
    assert cohort["source_review_packet"]["source_review_complete"] is False
    assert cohort["source_review_packet"]["source_packet_completeness_claimed"] is False
    assert cohort["source_review_packet"]["material_dependency_semantics_complete"] is False
    assert cohort["fresh_review_contract"]["required_independent_candidate_reviews"] == 2
    assert cohort["fresh_review_contract"]["required_cohort_synthesis"] == 1

    required_files = cohort["source_review_packet"]["required_source_files"]
    assert len(required_files) == 33
    for row in required_files:
        path = REPO / row["path"]
        assert path.is_file(), row["path"]
        assert sha256_file(path) == row["sha256"], row["path"]
        head_blob = git("rev-parse", f"HEAD:{row['path']}")
        application_blob = git("rev-parse", f"{APPLICATION_COMMIT}:{row['path']}")
        assert head_blob == row["blob_id"] == row["application_commit_blob_id"], row["path"]
        assert application_blob == head_blob, row["path"]

    records = list(cohort["records"])
    assert len(records) == 1
    candidate = records[0]
    assert candidate["candidate_id"] == CANDIDATE_ID
    assert candidate["candidate_record_sha256"] == CANDIDATE_RECORD_SHA256
    assert candidate["queue_index_zero_based"] == 77
    assert candidate["queue_id"] == "RUN090-ROUTE-0078"
    assert candidate["queue_canonical_key"] == "route|RUN077-ROUTE-0634"
    assert candidate["candidate_feature_id"] == FEATURE_ID
    assert candidate["action_key"] == ACTION_KEY
    assert candidate["name_only_identity"]["relation_comparison"] == "NAME_ONLY"
    assert candidate["name_only_identity"]["name_candidate_feature_ids"] == [FEATURE_ID]
    assert candidate["name_only_identity"]["backend_candidate_count"] == 0
    assert candidate["name_only_identity"]["unique_controller_resolution_is_review_context_not_feature_identity"] is True
    assert candidate["fresh_review_state"]["status"] == "PENDING"
    assert candidate["collision_checks"]["previous_review_source_collision"] is False
    assert candidate["collision_checks"]["current_owner_source_collision"] is False
    assert candidate["collision_checks"]["existing_controller_action_bridge_collision"] is False
    assert candidate["controller_action"]["literal_inertia_page_callsite_count"] == 1
    assert len(candidate["controller_action"]["literal_inertia_page_callsites"]) == 1
    assert cohort["page_and_caller_context_boundary"] == {
        "selected_controller_literal_inertia_page_callsites": 1,
        "existing_page_owner_context_rows": 2,
        "existing_selected_render_page_owner_rows": 1,
        "frontend_static_path_contexts": 7,
        "new_page_owner_records": 0,
        "page_ownership_inherited": False,
        "page_ownership_reassigned": False,
        "render_or_caller_presence_preselects_route_outcome": False,
    }
    assert cohort["next_queue_boundary"] == {
        "queue_index_zero_based": 78,
        "queue_id": "RUN090-ROUTE-0079",
        "route_record_id": "RUN077-ROUTE-0669",
        "candidate_feature_id": "CAP-FIN-SITE-PORTFOLIO-OVERVIEW",
        "selected_for_run137": False,
        "credit_awarded": False,
    }

    expansions = build_source_packet_expansions(cohort)

    for finding in [*CANDIDATE_ASSURANCE_FINDINGS, *SHARED_ASSURANCE_FINDINGS]:
        for locus in finding["loci"]:
            assert_locus(locus)
        assert finding["correctness_credit_authorized"] is False
    assert len(CANDIDATE_ASSURANCE_FINDINGS) == 6
    assert len(SHARED_ASSURANCE_FINDINGS) == 3
    finding_ids = [
        row["finding_id"] for row in [*CANDIDATE_ASSURANCE_FINDINGS, *SHARED_ASSURANCE_FINDINGS]
    ]
    assert len(finding_ids) == len(set(finding_ids)) == 9

    route = candidate["route_source"]
    primary = candidate["controller_action"]["primary_method_slice"]
    assert route["route_record_id"] == "RUN077-ROUTE-0634"
    assert route["literal_route_name"] == "invoices.index"
    assert route["source_anchor"] == "routes/finance.php:675"
    assert primary["source_file"] == BRIDGE_KEY[0]
    assert primary["method"] == "index"
    assert primary["definition_line"] == 37

    independent_reviews: list[dict[str, Any]] = []
    for spec in INDEPENDENT_REVIEW_SPECS:
        for locus in spec["source_loci"]:
            assert_locus(locus)
        review: dict[str, Any] = {
            "review_id": spec["review_id"],
            "reviewer_task_path": spec["reviewer_task_path"],
            "candidate_id": CANDIDATE_ID,
            "candidate_record_sha256": CANDIDATE_RECORD_SHA256,
            "queue_index_zero_based": 77,
            "queue_id": "RUN090-ROUTE-0078",
            "queue_canonical_key": "route|RUN077-ROUTE-0634",
            "route_record_id": "RUN077-ROUTE-0634",
            "literal_route_name": "invoices.index",
            "candidate_feature_id": FEATURE_ID,
            "controller_file": primary["source_file"],
            "controller_method": primary["method"],
            "outcome": spec["outcome"],
            "confidence": spec["confidence"],
            "identity_basis": "NAME_ONLY_EXACT_LITERAL_ROUTE_NAME",
            "source_loci": spec["source_loci"],
            "rationale": spec["rationale"],
            "material_observations": spec["material_observations"],
            "recommended_owner_source_record_key": OWNER_SOURCE_RECORD_KEY,
            "recommended_action_key": ACTION_KEY,
            "recommended_bridge_key": BRIDGE_KEY,
            "recommends_route_ownership": True,
            "recommends_controller_action_bridge": True,
            "recommends_page_ownership": False,
            "correctness_or_downstream_credit_authorized": False,
            "current_overlay_credit_awarded": False,
            "reviewer_wrote_files": False,
        }
        review["independent_review_record_sha256"] = canonical_json_sha256(review)
        independent_reviews.append(review)
    assert len(independent_reviews) == 2
    assert len({row["reviewer_task_path"] for row in independent_reviews}) == 2
    assert Counter(row["outcome"] for row in independent_reviews) == {"OWNER_ROUTE_ACTION": 2}
    assert len({row["candidate_id"] for row in independent_reviews}) == 1
    assert not any(row["reviewer_wrote_files"] for row in independent_reviews)

    synthesis_review: dict[str, Any] = {
        "reviewer_task_path": "/root/run137_review_synthesis",
        "verdict": "GO_ACCEPT_1_OWNER_ROUTE_ACTION_FOR_BOUNDED_LATER_INTEGRATION",
        "accepted_independent_review_ids": [row["review_id"] for row in independent_reviews],
        "accepted_independent_review_record_sha256s": [
            row["independent_review_record_sha256"] for row in independent_reviews
        ],
        "accepted_candidate_ids": [CANDIDATE_ID],
        "accepted_candidate_record_sha256s": [CANDIDATE_RECORD_SHA256],
        "outcome_variables": {"O": 1, "S": 0, "A": 0, "D": 0, "E": 0},
        "independent_reviews_reconciled": True,
        "outcome_discrepancies": 0,
        "identity_or_key_discrepancies": 0,
        "page_credit_discrepancies": 0,
        "source_packet_expansion_disclosed": True,
        "route_ownership_authorized": True,
        "controller_action_bridge_authorized": True,
        "page_ownership_authorized": False,
        "prior_page_owner_context_inherited_or_recredited": False,
        "next_queue_boundary_changed": False,
        "current_overlay_credit_awarded": False,
        "bounded_overlay_integration_authorized": True,
        "correctness_or_downstream_credit_authorized": False,
        "reviewer_wrote_files": False,
    }
    synthesis_review["synthesis_record_sha256"] = canonical_json_sha256(synthesis_review)

    decision: dict[str, Any] = {
        "candidate_id": CANDIDATE_ID,
        "candidate_record_sha256": CANDIDATE_RECORD_SHA256,
        "accepted_independent_review_ids": synthesis_review["accepted_independent_review_ids"],
        "accepted_independent_review_record_sha256s": synthesis_review[
            "accepted_independent_review_record_sha256s"
        ],
        "synthesis_record_sha256": synthesis_review["synthesis_record_sha256"],
        "queue_index_zero_based": 77,
        "queue_id": "RUN090-ROUTE-0078",
        "queue_canonical_key": "route|RUN077-ROUTE-0634",
        "route_record_id": route["route_record_id"],
        "source_key": route["source_key"],
        "literal_route_name": route["literal_route_name"],
        "action_key": ACTION_KEY,
        "candidate_feature_id": FEATURE_ID,
        "controller_file": primary["source_file"],
        "controller_method": primary["method"],
        "outcome": "OWNER_ROUTE_ACTION",
        "confidence": "HIGH_2_OF_2_PLUS_SYNTHESIS",
        "identity_basis": "NAME_ONLY_EXACT_LITERAL_ROUTE_NAME",
        "source_loci": [
            "03-feature-to-benchmark-matrix.csv:57",
            "routes/finance.php:675",
            "app/Domain/Finance/Http/Controllers/InvoiceController.php:37-125",
            "resources/js/pages/finance/invoices/Index.tsx:118-164",
        ],
        "material_dependencies": [
            "authenticated finance.ar.view route gate",
            "FinInvoice organisation-context list and filter query",
            "FinPaymentAllocation outstanding-balance projection",
            "application-wide invoice summary aggregation",
            "manager-only client and tax-rate reference projections",
            "literal finance/invoices/Index render and existing page-owner context",
        ],
        "rationale": (
            "Both independent reviewers and the fresh synthesizer agree that the exact name-only route identity and "
            "direct canonical Index render establish one bounded route owner and one controller-action bridge. "
            "Correctness defects remain assurance findings and do not create page or downstream credit."
        ),
        "review_discrepancies": [
            "Zero reviewer outcome, identity, key, page-credit, or synthesis discrepancies remain.",
            "Twelve source expansions are disclosed without retroactively claiming packet completeness.",
        ],
        "assurance_findings": CANDIDATE_ASSURANCE_FINDINGS,
        "route_ownership_authorized": True,
        "controller_action_bridge_authorized": True,
        "owner_source_record_key": OWNER_SOURCE_RECORD_KEY,
        "bridge_key": BRIDGE_KEY,
        "page_ownership_authorized": False,
        "prior_page_owner_context_inherited_or_recredited": False,
        "site_permission_privacy_direct_object_query_projection_lifecycle_concurrency_correctness_authorized": False,
        "runtime_database_build_browser_test_benchmark_ease_release_pass_completion_authorized": False,
        "current_overlay_credit_awarded": False,
        "reviewer_wrote_files": False,
    }
    decision["decision_record_sha256"] = canonical_json_sha256(decision)
    action_decisions = [decision]
    assert len(action_decisions) == 1
    assert Counter(row["outcome"] for row in action_decisions) == {"OWNER_ROUTE_ACTION": 1}
    assert action_decisions[0]["owner_source_record_key"] == OWNER_SOURCE_RECORD_KEY
    assert action_decisions[0]["bridge_key"] == BRIDGE_KEY

    baseline = cohort["current_baseline"]
    expected_baseline = {
        "source_owner_records": 660,
        "route_owner_records": 303,
        "page_owner_records": 357,
        "distinct_feature_ids": 256,
        "distinct_H_feature_ids": 234,
        "distinct_D_feature_ids": 22,
        "route_distinct_feature_ids": 62,
        "page_distinct_feature_ids": 242,
        "route_page_feature_overlap": 48,
        "static_controller_action_bridges": 91,
        "bounded_static_source_denominator": 3929,
        "bounded_static_source_ownership_percent": "16.798167",
        "bounded_static_source_residual_records": 3269,
        "residual_explicit_unmapped_routes": 2898,
        "semantic_shared_routes": 12,
        "reviewed_alias_routes": 5,
        "reviewed_dead_routes": 0,
        "evidence_gap_routes_tagged_within_residual": 7,
        "residual_unadjudicated_page_roots": 345,
        "semantic_shared_page_roots": 9,
        "reviewed_alias_page_roots": 0,
        "reviewed_dead_page_roots": 0,
        "evidence_gap_page_roots_tagged_within_residual": 1,
        "direct_exact_queue_records": 507,
        "reviewed_queue_surface_rows": 114,
        "owner_queue_surface_rows": 92,
        "shared_queue_surface_rows": 10,
        "alias_queue_surface_rows": 5,
        "dead_queue_surface_rows": 0,
        "evidence_gap_queue_surface_rows": 7,
        "pending_unreviewed_queue_surface_rows": 393,
        "queue_surfaces_without_ownership": 415,
        "matrix_rows_changed": 0,
        "matrix_cells_changed": 0,
    }
    assert baseline == expected_baseline

    projection = {
        "O": 1,
        "S": 0,
        "A": 0,
        "D": 0,
        "E": 0,
        "source_owner_records": 661,
        "route_owner_records": 304,
        "page_owner_records": 357,
        "static_controller_action_bridges": 92,
        "bounded_static_source_denominator": 3929,
        "bounded_static_source_residual_records": 3268,
        "bounded_static_source_ownership_percent": str(
            (Decimal(661) * Decimal(100) / Decimal(3929)).quantize(
                Decimal("0.000001"), rounding=ROUND_HALF_UP
            )
        ),
        "direct_exact_queue_records": 507,
        "reviewed_queue_surface_rows": 115,
        "owner_queue_surface_rows": 93,
        "shared_queue_surface_rows": 10,
        "alias_queue_surface_rows": 5,
        "dead_queue_surface_rows": 0,
        "evidence_gap_queue_surface_rows": 7,
        "pending_unreviewed_queue_surface_rows": 392,
        "queue_surfaces_without_ownership": 414,
        "residual_explicit_unmapped_routes": 2897,
        "semantic_shared_routes": 12,
        "reviewed_alias_routes": 5,
        "reviewed_dead_routes": 0,
        "evidence_gap_routes_tagged_within_residual": 7,
        "residual_unadjudicated_page_roots": 345,
        "semantic_shared_page_roots": 9,
        "evidence_gap_page_roots_tagged_within_residual": 1,
        "distinct_feature_ids": 256,
        "distinct_H_feature_ids": 234,
        "distinct_D_feature_ids": 22,
        "route_distinct_feature_ids": 63,
        "page_distinct_feature_ids": 242,
        "route_page_feature_overlap": 49,
        "matrix_rows_changed": 0,
        "matrix_cells_changed": 0,
        "projection_credit_awarded": False,
    }
    assert projection["O"] + projection["S"] + projection["A"] + projection["D"] + projection["E"] == 1
    assert projection["source_owner_records"] + projection["bounded_static_source_residual_records"] == 3929
    assert projection["source_owner_records"] == projection["route_owner_records"] + projection["page_owner_records"]
    assert projection["bounded_static_source_ownership_percent"] == "16.823619"
    assert projection["direct_exact_queue_records"] == (
        projection["reviewed_queue_surface_rows"] + projection["pending_unreviewed_queue_surface_rows"]
    )
    assert projection["reviewed_queue_surface_rows"] == (
        projection["owner_queue_surface_rows"]
        + projection["shared_queue_surface_rows"]
        + projection["alias_queue_surface_rows"]
        + projection["dead_queue_surface_rows"]
        + projection["evidence_gap_queue_surface_rows"]
    )
    assert projection["queue_surfaces_without_ownership"] == (
        projection["pending_unreviewed_queue_surface_rows"]
        + projection["shared_queue_surface_rows"]
        + projection["alias_queue_surface_rows"]
        + projection["dead_queue_surface_rows"]
        + projection["evidence_gap_queue_surface_rows"]
    )
    assert 3218 == 304 + 12 + 5 + 0 + 2897
    assert 711 == 357 + 9 + 345

    independent_review_hashes = [
        row["independent_review_record_sha256"] for row in independent_reviews
    ]
    verified_global_identity = {
        "reviewed_candidate_id_list_sha256": cohort["identity"]["candidate_id_list_sha256"],
        "reviewed_queue_index_list_sha256": cohort["identity"]["queue_index_list_sha256"],
        "reviewed_queue_id_list_sha256": cohort["identity"]["queue_id_list_sha256"],
        "reviewed_canonical_key_list_sha256": cohort["identity"]["canonical_key_list_sha256"],
        "reviewed_route_record_id_list_sha256": cohort["identity"]["route_record_id_list_sha256"],
        "reviewed_literal_route_name_list_sha256": cohort["identity"]["literal_route_name_list_sha256"],
        "reviewed_action_key_list_sha256": cohort["identity"]["action_key_list_sha256"],
        "reviewed_candidate_record_sha256_list_sha256": cohort["identity"]["candidate_record_sha256_list_sha256"],
        "reviewed_records_sha256": cohort["identity"]["records_sha256"],
        "source_review_packet_sha256": cohort["identity"]["source_review_packet_sha256"],
        "page_context_sha256": cohort["identity"]["page_context_sha256"],
        "next_queue_record_sha256": cohort["identity"]["next_queue_record_sha256"],
        "independent_review_id_list_sha256": canonical_list_sha256(
            [row["review_id"] for row in independent_reviews]
        ),
        "independent_reviewer_task_path_list_sha256": canonical_list_sha256(
            [row["reviewer_task_path"] for row in independent_reviews]
        ),
        "independent_review_record_sha256_list_sha256": canonical_list_sha256(
            independent_review_hashes
        ),
        "independent_reviews_sha256": canonical_json_sha256(independent_reviews),
        "owner_candidate_id_list_sha256": canonical_list_sha256([CANDIDATE_ID]),
        "owner_route_record_id_list_sha256": canonical_list_sha256([route["route_record_id"]]),
        "owner_source_record_key_list_sha256": canonical_list_sha256([OWNER_SOURCE_RECORD_KEY]),
        "owner_action_key_list_sha256": canonical_list_sha256([ACTION_KEY]),
        "owner_bridge_key_list_sha256": canonical_list_sha256(["|".join(BRIDGE_KEY)]),
        "owner_candidate_record_sha256_list_sha256": canonical_list_sha256(
            [CANDIDATE_RECORD_SHA256]
        ),
        "owner_feature_id_list_sha256": canonical_list_sha256([FEATURE_ID]),
        "new_owner_feature_id_list_sha256": canonical_list_sha256([]),
        "decision_record_sha256_list_sha256": canonical_list_sha256(
            [decision["decision_record_sha256"]]
        ),
        "reviewed_decisions_sha256": canonical_json_sha256(action_decisions),
        "synthesis_record_sha256": synthesis_review["synthesis_record_sha256"],
        "source_packet_expansions_sha256": canonical_json_sha256(expansions),
        "candidate_assurance_findings_sha256": canonical_json_sha256(
            CANDIDATE_ASSURANCE_FINDINGS
        ),
        "shared_assurance_findings_sha256": canonical_json_sha256(
            SHARED_ASSURANCE_FINDINGS
        ),
    }

    return {
        "schema_version": "run-137r-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22-v1",
        "run_id": "RUN-137R-INDEPENDENT-OUTCOME-NEUTRAL-FINANCE-INVOICE-INDEX-ROUTE-ACTION-REVIEW-WAVE-22",
        "status": "GO_TWO_INDEPENDENT_CANDIDATE_REVIEWS_AND_FRESH_SYNTHESIS_COMPLETE_ONE_BOUNDED_OWNER_ZERO_DOWNSTREAM_CREDIT",
        "reviewed_on": "2026-08-26",
        "decision": {
            "verdict": "GO_ACCEPT_1_OWNER_ROUTE_ACTION_FOR_BOUNDED_LATER_INTEGRATION",
            "mechanical_discrepancies": 0,
            "semantic_outcome_discrepancies": 0,
            "identity_or_key_discrepancies": 0,
            "page_credit_discrepancies": 0,
            "source_packet_expansion_discrepancies_disclosed": 12,
            "independent_candidate_reviews": 2,
            "cohort_synthesis_reviews": 1,
            "reviewed_route_actions": 1,
            "owner_route_actions": 1,
            "shared_relations": 0,
            "alias_or_redirect": 0,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 0,
            "static_route_owner_records_authorized": 1,
            "static_controller_action_bridges_authorized": 1,
            "static_page_owner_records_authorized": 0,
            "bounded_overlay_authorized": True,
            "current_overlay_credit_awarded": False,
            "complete_route_page_feature_crosswalk_authorized": False,
            "matrix_mutation_authorized": False,
            "correctness_or_downstream_credit_authorized": False,
            "gate_4_complete": False,
        },
        "pins": {
            "checkpoint_commit": CHECKPOINT_COMMIT,
            "checkpoint_tree": CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "app_tree": APP_TREE,
            "routes_tree": ROUTES_TREE,
            "resources_js_tree": RESOURCES_JS_TREE,
            "resources_js_pages_tree": RESOURCES_JS_PAGES_TREE,
            "tests_tree": TESTS_TREE,
            "cohort": COHORT_PATH.relative_to(AUDIT_DIR).as_posix(),
            "cohort_sha256": COHORT_SHA256,
            "cohort_generator": COHORT_GENERATOR.relative_to(AUDIT_DIR).as_posix(),
            "cohort_generator_sha256": COHORT_GENERATOR_SHA256,
            "cohort_source_review_packet_sha256": SOURCE_PACKET_SHA256,
            "matrix": MATRIX_PATH.relative_to(AUDIT_DIR).as_posix(),
            "matrix_sha256": MATRIX_SHA256,
            "materializer": Path(__file__).relative_to(AUDIT_DIR).as_posix(),
            "materializer_sha256": sha256_file(Path(__file__)),
        },
        "architecture_rule": (
            "Oblivion Findings is one operating organisation across multiple Sites. Legacy organization_id is "
            "storage context, not tenancy or an access boundary. Static ownership proves neither approved-Site "
            "reach, exact permission, canonical-object concealment, privacy, query correctness, nor lifecycle safety."
        ),
        "methods": [
            "Two fresh independent reviewers adjudicated the same frozen name-only candidate; neither wrote files.",
            "A third fresh reviewer reconciled both returns and emitted one synthesized owner decision.",
            "Exact route-name identity remained separate from unique controller resolution, existing page owners, and static caller context.",
            "All twelve material source expansions are pinned to bytes identical at HEAD and the frozen application commit.",
            "Only later bounded route-owner and controller-action bridge integration is authorized; current overlay and all correctness or downstream credit remain zero.",
        ],
        "verified_counts": {
            "independent_candidate_reviews": 2,
            "cohort_synthesis_reviews": 1,
            "total_fresh_semantic_reviews": 3,
            "unique_reviewed_candidates": 1,
            "reviewed_route_actions": 1,
            "owner_route_actions": 1,
            "accepted_route_records": 1,
            "accepted_controller_action_bridges": 1,
            "accepted_page_records": 0,
            "accepted_distinct_feature_ids": 1,
            "new_distinct_feature_ids": 0,
            "selected_controller_literal_inertia_page_callsites": 1,
            "existing_page_owner_context_rows": 2,
            "frontend_static_path_contexts": 7,
            "source_packet_expansion_files": 12,
            "source_packet_expansion_existing_files": 7,
            "source_packet_expansion_new_files": 5,
            "candidate_assurance_findings": 6,
            "shared_assurance_findings": 3,
            "assurance_findings": 9,
            "reviewer_written_files": 0,
            "matrix_rows_changed": 0,
            "matrix_cells_changed": 0,
        },
        "verified_global_identity": verified_global_identity,
        "independent_candidate_reviews": independent_reviews,
        "synthesis_review": synthesis_review,
        "action_decisions": action_decisions,
        "shared_assurance_findings": SHARED_ASSURANCE_FINDINGS,
        "source_packet_expansion": {
            "original_source_review_complete": False,
            "original_source_packet_completeness_claimed": False,
            "original_material_dependency_semantics_complete": False,
            "original_packet_retroactively_described_as_complete": False,
            "expanded_files": expansions,
            "all_expanded_files_match_application_commit_blobs": True,
            "expansion_authorizes_action_outcome_change": False,
            "expansion_authorizes_correctness_credit": False,
        },
        "reviewed_projection_if_integrated": projection,
        "page_and_caller_reconciliation": {
            "selected_controller_literal_inertia_page_callsites": 1,
            "existing_page_owner_context": cohort["current_page_owner_context_non_inheritable"],
            "existing_page_owner_context_rows": 2,
            "existing_index_page_record_id": "PAGE-ROOT-B4964DF8343DF25A",
            "existing_index_page_owner_row_id": "RUN086-PAGE-MAP-0207",
            "existing_show_page_record_id": "PAGE-ROOT-E1ACF667B368A747",
            "existing_show_page_owner_row_id": "RUN086-PAGE-MAP-0272",
            "existing_page_owner_credit_preserved": True,
            "new_page_owner_records": 0,
            "page_ownership_inherited_reassigned_or_recredited": False,
            "caller_presence_preselected_route_outcome": False,
            "next_queue_boundary": cohort["next_queue_boundary"],
            "next_queue_boundary_changed_or_credited": False,
            "current_overlay_credit_awarded": False,
        },
        "credit_boundary": {
            "REVIEWED_STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD": True,
            "REVIEWED_STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION": True,
            "BOUNDED_OVERLAY_INTEGRATION_AUTHORIZED": True,
            "CURRENT_OVERLAY_OWNERSHIP_CREDIT": False,
            "PRIOR_PAGE_OWNER_CONTEXT_PRESERVED": True,
            "STATIC_PAGE_FEATURE_OWNERSHIP": False,
            "prior_page_owner_context_inherited_or_recredited": False,
            "complete_route_page_feature_crosswalk": False,
            "framework_route_reachability": False,
            "navigation": False,
            "canonical_object_ownership_correctness": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "privacy_correctness": False,
            "direct_object_concealment_correctness": False,
            "query_correctness": False,
            "projection_correctness": False,
            "response_minimization_correctness": False,
            "lifecycle_correctness": False,
            "concurrency_and_idempotency_correctness": False,
            "runtime": False,
            "database": False,
            "build": False,
            "application_browser": False,
            "responsive_application": False,
            "visual_application_workflow": False,
            "executed_tests": False,
            "application_source_mutation": False,
            "matrix_mutation": False,
            "benchmark": False,
            "ease": False,
            "release": False,
            "pass": False,
            "final_finding": False,
            "completion": False,
            "audit_complete": False,
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [GENERATOR_RELATIVE, OUTPUT_RELATIVE],
    }


def main() -> None:
    payload = build()
    encoded = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    assert not encoded.startswith(b"\xef\xbb\xbf")
    assert b"\r\n" not in encoded
    assert strict_json_loads(encoded.decode("utf-8")) == payload
    if not OUTPUT_PATH.exists() or OUTPUT_PATH.read_bytes() != encoded:
        OUTPUT_PATH.write_bytes(encoded)
    assert OUTPUT_PATH.read_bytes() == encoded
    assert current_dirty_paths() == ALLOWED_DIRTY_PATHS, current_dirty_paths()
    assert not list(AUDIT_DIR.rglob("__pycache__"))
    print(
        json.dumps(
            {
                "status": payload["status"],
                "output": OUTPUT_RELATIVE,
                "sha256": sha256_file(OUTPUT_PATH),
                "independent_candidate_reviews": payload["decision"]["independent_candidate_reviews"],
                "owner_route_actions": payload["decision"]["owner_route_actions"],
                "authorized_bridges": payload["decision"]["static_controller_action_bridges_authorized"],
                "current_overlay_credit_awarded": payload["decision"]["current_overlay_credit_awarded"],
                "audit_complete": payload["audit_completion_test_met"],
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
