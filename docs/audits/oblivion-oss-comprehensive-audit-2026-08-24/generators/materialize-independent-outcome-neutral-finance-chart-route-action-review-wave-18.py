#!/usr/bin/env python3
"""Materialize three fresh RUN-121 Finance route/action semantic reviews."""

from __future__ import annotations

import hashlib
import json
import subprocess
from collections import Counter
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
PRODUCER_PATH = AUDIT_DIR / "evidence/source/root-run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18.json"
PRODUCER_GENERATOR = AUDIT_DIR / "generators/build-outcome-neutral-finance-chart-route-action-cohort-wave-18.py"
BASELINE_OVERLAY = AUDIT_DIR / "evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json"
BASELINE_REVIEW = AUDIT_DIR / "evidence/source/raw-run-118r-independent-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json"
MATRIX_PATH = AUDIT_DIR / "03-feature-to-benchmark-matrix.csv"
OUTPUT_PATH = AUDIT_DIR / "evidence/source/raw-run-121r-independent-outcome-neutral-finance-chart-route-action-review-wave-18.json"

CHECKPOINT_COMMIT = "034efdc3c1778d71de54e67166bb1901b4bc16f0"
CHECKPOINT_TREE = "d53b2343282a2a47c646c52b11808bd8ba93c8fa"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
PRODUCER_SHA256 = "cfe0e3635e5e86bf8e7e2f65d2094743738bfa5edc36e361ecf5eb14986f316e"
PRODUCER_GENERATOR_SHA256 = "c7795bee971e051873e3953eb4e1bb7c62eb372b6890149700d0c401d64305dd"
BASELINE_OVERLAY_SHA256 = "33dd2f8ffa2b35c6651a6bf18923872e70ba5c2f91fce8b7222666bb8a91fc8b"
BASELINE_REVIEW_SHA256 = "043d57357e3ff1ede8f0effacdb71e4d802d98d53d555ab39316bce33fe06a2d"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"


DECISIONS = (
    {
        "suffix": "01", "outcome": "ALIAS_OR_REDIRECT",
        "source_loci": [
            "routes/finance.php:131-134",
            "app/Domain/Finance/Http/Controllers/LedgerController.php:9-31",
            "tests/Feature/Finance/LedgerHubRedirectTest.php:14-40",
            "03-feature-to-benchmark-matrix.csv:61",
        ],
        "material_dependencies": ["permission-to-target map", "accounts, cost-centres, fixed-assets, and FX-revaluation destination routes"],
        "rationale": "The ledger hub performs no ledger-domain operation and renders no page; it redirects to the first permitted feature tab, so it is an explicit cross-feature entry alias rather than a Chart of Accounts owner.",
        "review_discrepancies": ["NAME_ONLY projected a cross-feature redirect hub to Chart of Accounts."],
    },
    {
        "suffix": "02", "outcome": "OWNER_ROUTE_ACTION",
        "source_loci": [
            "routes/finance.php:137-139",
            "app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:19-52",
            "app/Domain/Finance/Services/ChartOfAccountsService.php:15-97",
            "resources/js/pages/finance/accounts/Index.tsx:261-438",
            "resources/js/components/finance/new-account-dialog.tsx:83-131",
        ],
        "material_dependencies": ["FinAccount", "FinJournalLine", "FinTaxRate", "FinFundingStream", "FinAccountPolicy"],
        "rationale": "The action builds the hierarchical account tree and balances, supplies account-maintenance reference data, and renders the canonical Chart of Accounts index.",
        "review_discrepancies": [],
    },
    {
        "suffix": "03", "outcome": "OWNER_ROUTE_ACTION",
        "source_loci": [
            "routes/finance.php:140-142",
            "app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:83-109",
            "resources/js/pages/finance/accounts/Create.tsx:91-143",
            "app/Domain/Finance/Policies/FinAccountPolicy.php:20-23",
        ],
        "material_dependencies": ["FinAccount", "FinTaxRate", "FinFundingStream", "standalone Create Account page"],
        "rationale": "The action loads account-specific parent, tax-rate, and funding-stream choices and renders the complete Create Account surface.",
        "review_discrepancies": ["No current frontend link to the standalone create route was found; the registered substantive action is not thereby dead."],
    },
    {
        "suffix": "04", "outcome": "OWNER_ROUTE_ACTION",
        "source_loci": [
            "routes/finance.php:143-145",
            "app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:111-138",
            "app/Domain/Finance/Services/ChartOfAccountsService.php:173-189",
            "resources/js/components/finance/new-account-dialog.tsx:83-131",
        ],
        "material_dependencies": ["FinAccount", "ChartOfAccountsService::createAccount", "standalone and modal account-create callers"],
        "rationale": "The action validates account attributes, records the creator, delegates account creation and code uniqueness, and returns to the chart.",
        "review_discrepancies": [],
    },
    {
        "suffix": "05", "outcome": "OWNER_ROUTE_ACTION",
        "source_loci": [
            "routes/finance.php:146-148",
            "app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:140-168",
            "app/Domain/Finance/Services/ChartOfAccountsService.php:99-170",
            "resources/js/pages/finance/accounts/Show.tsx:79-274",
        ],
        "material_dependencies": ["FinAccount", "posted FinJournalLine and FinJournal data", "account-ledger service", "account Show page"],
        "rationale": "The action presents one account's identity, balance, and date-filtered account ledger; journal records are read dependencies rather than joint action owners.",
        "review_discrepancies": ["Displayed model balance and service ledger use different journal-status filtering; this remains an uncredited correctness risk."],
    },
    {
        "suffix": "06", "outcome": "OWNER_ROUTE_ACTION",
        "source_loci": [
            "routes/finance.php:149-151",
            "app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:170-203",
            "resources/js/pages/finance/accounts/Edit.tsx:109-203",
            "app/Domain/Finance/Policies/FinAccountPolicy.php:25-28",
        ],
        "material_dependencies": ["FinAccount", "journal-line existence", "FinTaxRate", "FinFundingStream", "account Edit page"],
        "rationale": "The action loads the account, eligible parents and reference data, derives journal-line immutability state, and renders the complete Edit Account surface.",
        "review_discrepancies": ["No current frontend link to the standalone edit route was found; the registered substantive action is not thereby dead."],
    },
    {
        "suffix": "07", "outcome": "OWNER_ROUTE_ACTION",
        "source_loci": [
            "routes/finance.php:152-154",
            "app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:205-230",
            "app/Domain/Finance/Services/ChartOfAccountsService.php:191-223",
            "resources/js/pages/finance/accounts/Edit.tsx:109-144",
        ],
        "material_dependencies": ["FinAccount", "journal-line relationship", "ChartOfAccountsService::updateAccount", "Edit caller"],
        "rationale": "The action validates account changes and delegates system-account, type-change, journal-line, and code-uniqueness constraints to the Chart of Accounts service.",
        "review_discrepancies": [],
    },
    {
        "suffix": "08", "outcome": "OWNER_ROUTE_ACTION",
        "source_loci": [
            "routes/finance.php:155-157",
            "app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:232-244",
            "app/Domain/Finance/Services/ChartOfAccountsService.php:225-239",
            "app/Domain/Finance/Policies/FinAccountPolicy.php:30-33",
        ],
        "material_dependencies": ["soft-deleting FinAccount", "journal-line existence guard", "account delete policy and service"],
        "rationale": "The action performs the account-specific deletion lifecycle and delegates system-account and referenced-journal rejection to the Chart of Accounts service.",
        "review_discrepancies": ["No current client-side delete caller was found; the registered backend lifecycle still has positive semantics."],
    },
    {
        "suffix": "09", "outcome": "EVIDENCE_GAP",
        "source_loci": [
            "routes/finance.php:163-165",
            "app/Domain/Finance/Http/Controllers/JournalController.php:191-215",
            "resources/js/pages/finance/journals/Create.tsx:142-169",
            "03-feature-to-benchmark-matrix.csv:61",
            "03-feature-to-benchmark-matrix.csv:78",
        ],
        "material_dependencies": ["FinJournal", "journal create page", "account, cost-centre, funding-stream, and tax-rate reference queries"],
        "rationale": "The action renders a manual journal-entry surface and supplies journal-line reference data; it does not maintain account definitions.",
        "review_discrepancies": ["The exact route-name projection conflicts with the dedicated CAP-FIN-MANUAL-JOURNAL-LIFECYCLE job whose route mapping remains unfinished."],
    },
    {
        "suffix": "10", "outcome": "EVIDENCE_GAP",
        "source_loci": [
            "routes/finance.php:166-168",
            "app/Domain/Finance/Http/Controllers/JournalController.php:220-243",
            "app/Domain/Finance/Http/Requests/StoreJournalRequest.php:10-34",
            "app/Domain/Finance/Services/JournalPostingService.php:21-137",
            "03-feature-to-benchmark-matrix.csv:78",
        ],
        "material_dependencies": ["StoreJournalRequest", "JournalPostingService", "FinJournal and journal lines"],
        "rationale": "The action validates journal header and lines and creates either a draft or posted journal, which is manual-journal lifecycle rather than account-definition maintenance.",
        "review_discrepancies": ["The singleton Chart of Accounts identity conflicts with the dedicated manual-journal canonical job."],
    },
    {
        "suffix": "11", "outcome": "EVIDENCE_GAP",
        "source_loci": [
            "routes/finance.php:169-171",
            "app/Domain/Finance/Http/Controllers/JournalController.php:248-266",
            "resources/js/pages/finance/journals/Show.tsx:123-280",
            "03-feature-to-benchmark-matrix.csv:61",
            "03-feature-to-benchmark-matrix.csv:78",
        ],
        "material_dependencies": ["FinJournal", "journal lines, period, users, and reversal provenance", "journal Show page"],
        "rationale": "The action loads and renders one journal and its lifecycle provenance; the same page is already statically assigned to the dedicated manual-journal job.",
        "review_discrepancies": ["The exact route name points at the wrong candidate feature."],
    },
    {
        "suffix": "12", "outcome": "EVIDENCE_GAP",
        "source_loci": [
            "routes/finance.php:172-174",
            "app/Domain/Finance/Http/Controllers/JournalController.php:271-283",
            "app/Domain/Finance/Services/JournalPostingService.php:30-137",
            "resources/js/pages/finance/journals/Show.tsx:137-206",
            "03-feature-to-benchmark-matrix.csv:78",
        ],
        "material_dependencies": ["FinJournal", "JournalPostingService posting validation", "journal Show page"],
        "rationale": "The action transitions a draft journal after balance, account, period, cost-centre, and funding-stream checks; it is a manual-journal step, not account maintenance.",
        "review_discrepancies": ["The candidate feature identity is incomplete or wrong; ledger-integrity correctness remains uncredited."],
    },
    {
        "suffix": "13", "outcome": "SHARED_RELATION",
        "source_loci": [
            "routes/finance.php:175-177",
            "app/Domain/Finance/Http/Controllers/JournalController.php:288-315",
            "app/Domain/Finance/Services/JournalPostingService.php:142-255",
            "app/Domain/Finance/Services/FixedAssetService.php:245-323",
            "tests/Feature/Finance/JournalPostingReversalInvariantTest.php:249-285",
            "tests/Feature/Finance/FixedAssetDepreciationIntegrityTest.php:252-279",
        ],
        "material_dependencies": ["generic journal reversal", "fixed-asset depreciation correction", "journal and asset state restoration"],
        "rationale": "The action has both generic journal-reversal and fixed-asset depreciation-correction branches, so no one-feature Chart of Accounts owner is supportable.",
        "review_discrepancies": ["The singleton projection omits both manual-journal and fixed-asset jobs."],
    },
    {
        "suffix": "14", "outcome": "EVIDENCE_GAP",
        "source_loci": [
            "routes/finance.php:180-184",
            "app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:36-55",
            "resources/js/pages/finance/fiscal-periods/Index.tsx:49-147",
            "03-feature-to-benchmark-matrix.csv:52",
            "03-feature-to-benchmark-matrix.csv:61",
        ],
        "material_dependencies": ["FinFiscalPeriod", "fiscal-period create dialog"],
        "rationale": "The action creates an open fiscal period and belongs to the dedicated accounting-period lifecycle, not account-definition maintenance.",
        "review_discrepancies": ["The dedicated accounting-period row has unfinished route identity, causing a wrong singleton projection to Chart of Accounts."],
    },
    {
        "suffix": "15", "outcome": "EVIDENCE_GAP",
        "source_loci": [
            "routes/finance.php:183",
            "app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:57-73",
            "resources/js/pages/finance/fiscal-periods/Index.tsx:149-246",
            "03-feature-to-benchmark-matrix.csv:52",
            "03-feature-to-benchmark-matrix.csv:61",
        ],
        "material_dependencies": ["FinFiscalPeriod", "fiscal-period edit dialog"],
        "rationale": "The action edits an open fiscal period's dates and name, which is the separate accounting-period lifecycle job.",
        "review_discrepancies": ["The matrix's dedicated accounting-period route mapping is unfinished."],
    },
    {
        "suffix": "16", "outcome": "EVIDENCE_GAP",
        "source_loci": [
            "routes/finance.php:184",
            "app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:75-100",
            "resources/js/pages/finance/fiscal-periods/Index.tsx:257-439",
            "03-feature-to-benchmark-matrix.csv:52",
            "03-feature-to-benchmark-matrix.csv:61",
        ],
        "material_dependencies": ["FinFiscalPeriod", "FinJournal draft guard", "fiscal-period close UI"],
        "rationale": "The action checks draft journals and closes the fiscal period; that is accounting-period lifecycle rather than chart maintenance.",
        "review_discrepancies": ["The candidate identity is wrong or incomplete; lifecycle and financial correctness remain uncredited."],
    },
    {
        "suffix": "17", "outcome": "SHARED_RELATION",
        "source_loci": [
            "routes/finance.php:187-193",
            "app/Domain/Finance/Http/Controllers/CostCentreController.php:34-64",
            "resources/js/pages/finance/cost-centres/Index.tsx:50-160",
            "03-feature-to-benchmark-matrix.csv:65",
        ],
        "material_dependencies": ["FinCostCentre", "cost-centre create UI", "journal-line cost-centre allocation"],
        "rationale": "The action creates a cost centre for the dedicated administration job; ledger allocation creates a real chart relation but not Chart of Accounts ownership.",
        "review_discrepancies": ["The Chart of Accounts route list overlaps the dedicated CAP-FIN-COST-CENTRE-ADMIN feature."],
    },
    {
        "suffix": "18", "outcome": "SHARED_RELATION",
        "source_loci": [
            "routes/finance.php:191",
            "app/Domain/Finance/Http/Controllers/CostCentreController.php:65-91",
            "resources/js/pages/finance/cost-centres/Index.tsx:164-264",
            "03-feature-to-benchmark-matrix.csv:65",
        ],
        "material_dependencies": ["FinCostCentre", "cost-centre edit dialog", "journal-line cost-centre allocation"],
        "rationale": "The action updates only cost-centre administration data; it is related to ledger allocation but is not Chart of Accounts ownership.",
        "review_discrepancies": ["The candidate feature overlaps a dedicated canonical cost-centre job."],
    },
    {
        "suffix": "19", "outcome": "SHARED_RELATION",
        "source_loci": [
            "routes/finance.php:192",
            "app/Domain/Finance/Http/Controllers/CostCentreController.php:92-98",
            "resources/js/pages/finance/cost-centres/Index.tsx:272-315",
            "03-feature-to-benchmark-matrix.csv:65",
        ],
        "material_dependencies": ["FinCostCentre", "cost-centre delete UI", "ledger tabs"],
        "rationale": "The action deletes only a cost centre and returns to the dedicated administration page; chart linkage is a relation, not ownership.",
        "review_discrepancies": ["Source-local dependency and canonical-Site guards remain outside this review."],
    },
    {
        "suffix": "20", "outcome": "SHARED_RELATION",
        "source_loci": [
            "routes/finance.php:195-201",
            "app/Domain/Finance/Http/Controllers/FundingStreamController.php:52-83",
            "resources/js/components/finance/funding-stream-dialog.tsx:73-180",
            "03-feature-to-benchmark-matrix.csv:74",
        ],
        "material_dependencies": ["FinFundingStream", "FinAccount default-revenue-account relation", "funding-stream create wizard"],
        "rationale": "The action creates a funding stream for the dedicated administration job; its revenue-account relationship makes it related to the chart without making it chart-owned.",
        "review_discrepancies": ["The Chart of Accounts row overlaps the dedicated CAP-FIN-FUNDING-STREAM-ADMIN feature."],
    },
    {
        "suffix": "21", "outcome": "SHARED_RELATION",
        "source_loci": [
            "routes/finance.php:199",
            "app/Domain/Finance/Http/Controllers/FundingStreamController.php:84-111",
            "resources/js/components/finance/funding-stream-dialog.tsx:73-180",
            "app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:48-49",
            "03-feature-to-benchmark-matrix.csv:74",
        ],
        "material_dependencies": ["FinFundingStream", "FinAccount", "funding-stream edit wizard", "chart funding-stream selectors"],
        "rationale": "The action updates funding-stream administration data; FinAccount relationships establish a shared chart relation rather than chart ownership.",
        "review_discrepancies": ["Bound-object and referenced-account convergence remain uncredited correctness questions."],
    },
    {
        "suffix": "22", "outcome": "SHARED_RELATION",
        "source_loci": [
            "routes/finance.php:200",
            "app/Domain/Finance/Http/Controllers/FundingStreamController.php:112-118",
            "resources/js/pages/finance/funding-streams/Index.tsx:61-127",
            "03-feature-to-benchmark-matrix.csv:74",
        ],
        "material_dependencies": ["FinFundingStream", "default revenue account relation", "funding-stream delete UI"],
        "rationale": "The action deletes a funding stream and returns to its dedicated surface; account references create a relation while canonical ownership remains funding-stream administration.",
        "review_discrepancies": ["Source-local dependency handling before deletion remains an uncredited correctness question."],
    },
)

PARTITION_REVIEWERS = {
    "A": {
        "reviewer_task_path": "/root/run119_counts_review",
        "verdict": "GO_REVIEW_COMPLETE_SEVEN_OWNER_ONE_ALIAS",
        "review_notes": [
            "The ledger hub is an explicit permission-sensitive redirect alias.",
            "All seven account CRUD/detail actions have positive Chart of Accounts semantics; four page callsites receive no page ownership.",
        ],
    },
    "B": {
        "reviewer_task_path": "/root/run119_reporting_review",
        "verdict": "GO_REVIEW_COMPLETE_ONE_SHARED_SEVEN_EVIDENCE_GAP",
        "review_notes": [
            "Journal and accounting-period actions conflict with dedicated canonical feature jobs whose route identity remains unfinished.",
            "Journal reversal is shared with the fixed-asset depreciation correction branch.",
        ],
    },
    "C": {
        "reviewer_task_path": "/root/run120_static_dashboard",
        "verdict": "GO_REVIEW_COMPLETE_SIX_SHARED_RELATIONS",
        "review_notes": [
            "Cost-centre and funding-stream actions belong to dedicated canonical jobs but retain real ledger/account relations.",
            "No candidate in the partition authorizes Chart of Accounts route or bridge ownership.",
        ],
    },
}


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def canonical_json_sha256(value: Any) -> str:
    encoded = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(encoded.encode("utf-8")).hexdigest()


def canonical_list_sha256(values: list[str] | set[str]) -> str:
    return hashlib.sha256("\n".join(sorted(values)).encode("utf-8")).hexdigest()


def git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args], cwd=REPO, check=True, text=True,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    return result.stdout.strip()


def assert_workspace() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("status", "--porcelain", "--", "app", "routes", "resources/js") == ""
    assert sha256_file(PRODUCER_PATH) == PRODUCER_SHA256
    assert sha256_file(PRODUCER_GENERATOR) == PRODUCER_GENERATOR_SHA256
    assert sha256_file(BASELINE_OVERLAY) == BASELINE_OVERLAY_SHA256
    assert sha256_file(BASELINE_REVIEW) == BASELINE_REVIEW_SHA256
    assert sha256_file(MATRIX_PATH) == MATRIX_SHA256


def build() -> dict[str, Any]:
    assert_workspace()
    producer = json.loads(PRODUCER_PATH.read_text(encoding="utf-8"))
    baseline = json.loads(BASELINE_OVERLAY.read_text(encoding="utf-8"))
    records = producer["records"]
    assert len(records) == len(DECISIONS) == 22
    records_by_id = {record["candidate_id"]: record for record in records}
    assert len(records_by_id) == 22
    for record in records:
        digest_source = dict(record)
        claimed = digest_source.pop("candidate_record_sha256")
        assert canonical_json_sha256(digest_source) == claimed
        assert record["fresh_review_state"]["status"] == "PENDING"
        assert record["name_only_identity"]["relation_comparison"] == "NAME_ONLY"
        assert record["name_only_identity"]["backend_candidate_count"] == 0
        assert record["controller_action"]["page_ownership_credit"] is False

    action_decisions: list[dict[str, Any]] = []
    for row in DECISIONS:
        candidate_id = f"RUN121-FINANCE-CHART-ROUTE-ACTION-{row['suffix']}"
        record = records_by_id[candidate_id]
        outcome = row["outcome"]
        assert outcome in record["fresh_review_state"]["allowed_outcomes"]
        for locus in row["source_loci"]:
            source_path = locus.split(":", 1)[0]
            assert (REPO / source_path).is_file() or (AUDIT_DIR / source_path).is_file(), locus
        owner = outcome == "OWNER_ROUTE_ACTION"
        decision: dict[str, Any] = {
            "candidate_id": candidate_id,
            "partition_id": record["review_partition"],
            "queue_index_zero_based": record["queue_index_zero_based"],
            "queue_id": record["queue_id"],
            "route_record_id": record["route_source"]["route_record_id"],
            "literal_route_name": record["route_source"]["literal_route_name"],
            "candidate_feature_id": record["candidate_feature_id"],
            "candidate_record_sha256": record["candidate_record_sha256"],
            "outcome": outcome,
            "source_loci": row["source_loci"],
            "material_dependencies": row["material_dependencies"],
            "rationale": row["rationale"],
            "review_discrepancies": row["review_discrepancies"],
            "route_ownership_authorized": owner,
            "controller_action_bridge_authorized": owner,
            "page_ownership_authorized": False,
            "site_permission_privacy_direct_object_ledger_lifecycle_correctness_authorized": False,
            "runtime_test_benchmark_ease_pass_completion_authorized": False,
        }
        decision["decision_record_sha256"] = canonical_json_sha256(decision)
        action_decisions.append(decision)

    outcome_counts = Counter(row["outcome"] for row in action_decisions)
    assert outcome_counts == {
        "OWNER_ROUTE_ACTION": 7,
        "SHARED_RELATION": 7,
        "ALIAS_OR_REDIRECT": 1,
        "EVIDENCE_GAP": 7,
    }
    owner_decisions = [row for row in action_decisions if row["outcome"] == "OWNER_ROUTE_ACTION"]
    shared_decisions = [row for row in action_decisions if row["outcome"] == "SHARED_RELATION"]
    alias_decisions = [row for row in action_decisions if row["outcome"] == "ALIAS_OR_REDIRECT"]
    gap_decisions = [row for row in action_decisions if row["outcome"] == "EVIDENCE_GAP"]
    assert [row["candidate_id"] for row in owner_decisions] == [
        f"RUN121-FINANCE-CHART-ROUTE-ACTION-{suffix:02d}" for suffix in range(2, 9)
    ]
    assert [row["candidate_id"] for row in alias_decisions] == [
        "RUN121-FINANCE-CHART-ROUTE-ACTION-01"
    ]

    page_contexts = [
        page for record in records for page in record["controller_action"]["literal_inertia_page_callsites"]
    ]
    assert len(page_contexts) == 6
    assert Counter(page["current_static_source_owner"] for page in page_contexts) == {True: 2, False: 4}

    partition_reviews: list[dict[str, Any]] = []
    expected_partition_counts = {
        "A": {"OWNER_ROUTE_ACTION": 7, "ALIAS_OR_REDIRECT": 1},
        "B": {"SHARED_RELATION": 1, "EVIDENCE_GAP": 7},
        "C": {"SHARED_RELATION": 6},
    }
    for partition in ("A", "B", "C"):
        partition_records = [record for record in records if record["review_partition"] == partition]
        partition_decisions = [row for row in action_decisions if row["partition_id"] == partition]
        assert len(partition_records) == len(partition_decisions) == producer["review_partitions"][partition]["assigned_candidates"]
        counts = Counter(row["outcome"] for row in partition_decisions)
        assert counts == expected_partition_counts[partition]
        meta = PARTITION_REVIEWERS[partition]
        partition_reviews.append({
            "partition_id": partition,
            **meta,
            "candidate_count": len(partition_decisions),
            "owner_route_actions": counts["OWNER_ROUTE_ACTION"],
            "shared_relations": counts["SHARED_RELATION"],
            "alias_or_redirect": counts["ALIAS_OR_REDIRECT"],
            "dead_or_noncanonical": counts["DEAD_OR_NONCANONICAL"],
            "evidence_gaps": counts["EVIDENCE_GAP"],
            "action_key_list_sha256": producer["review_partitions"][partition]["action_key_list_sha256"],
            "candidate_record_sha256_list_sha256": canonical_list_sha256([
                record["candidate_record_sha256"] for record in partition_records
            ]),
            "method_slice_sha256_list_sha256": canonical_list_sha256([
                record["controller_action"]["primary_method_slice"]["review_slice"]["text_sha256"]
                for record in partition_records
            ]),
            "outcome_projection_sha256": canonical_list_sha256([
                f"{row['candidate_id']}|{row['outcome']}" for row in partition_decisions
            ]),
            "mechanical_discrepancies": [],
            "missed_candidates": 0,
            "duplicate_adjudications": 0,
            "wrote_files": False,
            "write_scope": [],
        })

    assert baseline["combined_counts"]["source_owner_records"] == 641
    assert baseline["combined_counts"]["route_owner_records"] == 288
    assert baseline["combined_counts"]["page_owner_records"] == 353
    assert baseline["combined_counts"]["bounded_static_source_residual_records"] == 3288
    assert baseline["combined_counts"]["static_controller_action_bridges"] == 76
    assert baseline["queue_accounting"]["reviewed_queue_surface_rows"] == 84
    review_projection = {
        "O": 7, "S": 7, "A": 1, "D": 0, "E": 7,
        "source_owner_records": 648,
        "route_owner_records": 295,
        "page_owner_records": 353,
        "source_residual_records": 3281,
        "distinct_feature_ids": 256,
        "static_controller_action_bridges": 83,
        "bounded_ownership_percent": "16.492746",
        "queue_records": 507,
        "reviewed_queue_surfaces": 106,
        "owned_queue_surfaces": 84,
        "shared_queue_surfaces": 10,
        "alias_queue_surfaces": 5,
        "dead_queue_surfaces": 0,
        "evidence_gap_queue_surfaces": 7,
        "pending_unreviewed": 401,
        "without_ownership": 423,
    }
    assert review_projection["source_owner_records"] + review_projection["source_residual_records"] == 3929
    assert review_projection["source_owner_records"] == review_projection["route_owner_records"] + review_projection["page_owner_records"]
    assert review_projection["queue_records"] == review_projection["reviewed_queue_surfaces"] + review_projection["pending_unreviewed"]
    assert review_projection["reviewed_queue_surfaces"] == (
        review_projection["owned_queue_surfaces"] + review_projection["shared_queue_surfaces"]
        + review_projection["alias_queue_surfaces"] + review_projection["dead_queue_surfaces"]
        + review_projection["evidence_gap_queue_surfaces"]
    )
    assert review_projection["without_ownership"] == (
        review_projection["pending_unreviewed"] + review_projection["shared_queue_surfaces"]
        + review_projection["alias_queue_surfaces"] + review_projection["dead_queue_surfaces"]
        + review_projection["evidence_gap_queue_surfaces"]
    )
    assert 3218 == 295 + 12 + 5 + 0 + 2906
    assert 711 == 353 + 9 + 349

    owner_feature_ids = {row["candidate_feature_id"] for row in owner_decisions}
    assert owner_feature_ids == {"CAP-FIN-CHART-OF-ACCOUNTS"}
    return {
        "schema_version": "run-121r-independent-outcome-neutral-finance-chart-route-action-review-wave-18-v1",
        "run_id": "RUN-121R-INDEPENDENT-OUTCOME-NEUTRAL-FINANCE-CHART-ROUTE-ACTION-REVIEW-WAVE-18",
        "status": "GO_THREE_PART_REVIEW_COMPLETE_7_OWNER_7_SHARED_1_ALIAS_7_EVIDENCE_GAP",
        "reviewed_on": "2026-08-26",
        "decision": {
            "verdict": "GO_7_EXPLICIT_OWNER_ROUTE_ACTION_7_SHARED_1_ALIAS_7_EVIDENCE_GAP",
            "mechanical_discrepancies": 0,
            "reviewed_route_actions": 22,
            "owner_route_actions": 7,
            "shared_relations": 7,
            "alias_or_redirect": 1,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 7,
            "static_route_owner_records_authorized": 7,
            "static_controller_action_bridges_authorized": 7,
            "static_page_owner_records_authorized": 0,
            "bounded_overlay_authorized": True,
            "non_owner_outcomes_preserved": True,
            "complete_route_page_feature_crosswalk_authorized": False,
            "matrix_mutation_authorized": False,
            "downstream_credit_authorized": False,
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
            "producer": PRODUCER_PATH.relative_to(AUDIT_DIR).as_posix(),
            "producer_sha256": PRODUCER_SHA256,
            "producer_generator": PRODUCER_GENERATOR.relative_to(AUDIT_DIR).as_posix(),
            "producer_generator_sha256": PRODUCER_GENERATOR_SHA256,
            "materializer": Path(__file__).relative_to(AUDIT_DIR).as_posix(),
            "materializer_sha256": sha256_file(Path(__file__)),
            "baseline_overlay_sha256": BASELINE_OVERLAY_SHA256,
            "baseline_overlay_review_sha256": BASELINE_REVIEW_SHA256,
            "matrix_sha256": MATRIX_SHA256,
        },
        "architecture_rule": (
            "Oblivion Findings is one operating organisation across multiple Sites. Static semantic ownership "
            "does not prove approved-Site reach, permissions, privacy, direct-object concealment, ledger integrity, "
            "lifecycle, concurrency, runtime, or release correctness."
        ),
        "methods": [
            "Three fresh read-only reviewers independently adjudicated disjoint RUN-121 partitions against complete current source dependencies.",
            "Each reviewer returned exactly one allowed outcome per action and wrote no files.",
            "NAME_ONLY and the old matrix grouping were review inputs, never automatic ownership.",
            "Only OWNER_ROUTE_ACTION authorizes one bounded route owner and one controller-action bridge; page context never confers page ownership.",
            "Dedicated journal, period, cost-centre, and funding-stream jobs were reconciled instead of inheriting Chart of Accounts ownership.",
        ],
        "verified_counts": {
            "partition_reviews": 3,
            "go_review_completeness": 3,
            "mechanical_discrepancies": 0,
            "reviewed_route_actions": 22,
            "owner_route_actions": 7,
            "shared_relations": 7,
            "alias_or_redirect": 1,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 7,
            "accepted_route_records": 7,
            "accepted_controller_action_bridges": 7,
            "accepted_page_records": 0,
            "accepted_distinct_feature_ids": 1,
            "new_distinct_feature_ids": 0,
            "literal_inertia_page_callsites": 6,
            "literal_page_callsites_currently_owned": 2,
            "literal_page_callsites_unowned": 4,
            "reviewer_written_files": 0,
            "matrix_rows_changed": 0,
            "matrix_cells_changed": 0,
        },
        "verified_global_identity": {
            "reviewed_queue_index_list_sha256": producer["identity"]["queue_index_list_sha256"],
            "reviewed_queue_id_list_sha256": producer["identity"]["queue_id_list_sha256"],
            "reviewed_canonical_key_list_sha256": producer["identity"]["canonical_key_list_sha256"],
            "reviewed_source_key_list_sha256": producer["identity"]["source_key_list_sha256"],
            "reviewed_route_record_id_list_sha256": producer["identity"]["route_record_id_list_sha256"],
            "reviewed_feature_id_list_sha256": producer["identity"]["feature_id_list_sha256"],
            "reviewed_action_key_list_sha256": producer["identity"]["action_key_list_sha256"],
            "reviewed_candidate_record_sha256_list_sha256": producer["identity"]["candidate_record_sha256_list_sha256"],
            "owner_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in owner_decisions]),
            "shared_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in shared_decisions]),
            "alias_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in alias_decisions]),
            "evidence_gap_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in gap_decisions]),
            "owner_feature_id_list_sha256": canonical_list_sha256(owner_feature_ids),
            "new_owner_feature_id_list_sha256": canonical_list_sha256(set()),
            "decision_record_sha256_list_sha256": canonical_list_sha256([
                row["decision_record_sha256"] for row in action_decisions
            ]),
            "reviewed_decisions_sha256": canonical_json_sha256(action_decisions),
        },
        "partition_reviews": partition_reviews,
        "action_decisions": action_decisions,
        "reviewed_projection_if_integrated": review_projection,
        "page_context_boundary": {
            "literal_callsites": 6,
            "currently_owned_page_callsites": 2,
            "unowned_page_callsites": 4,
            "page_ownership_authorized": 0,
            "rule": "Page callsites remain context only and require separate outcome-neutral page review where still unowned.",
        },
        "identity_reconciliation": {
            "chart_owner_route_actions": 7,
            "cross_feature_redirect_aliases": 1,
            "shared_cross_feature_relations": 7,
            "wrong_or_unfinished_candidate_feature_identities": 7,
            "dedicated_features_requiring_later_mapping_repair": [
                "CAP-FIN-ACCOUNTING-PERIOD-LIFECYCLE",
                "CAP-FIN-COST-CENTRE-ADMIN",
                "CAP-FIN-FUNDING-STREAM-ADMIN",
                "CAP-FIN-MANUAL-JOURNAL-LIFECYCLE",
            ],
            "dedicated_features_preserved_as_shared_context": [
                "CAP-FIN-COST-CENTRE-ADMIN",
                "CAP-FIN-FIXED-ASSET-LIFECYCLE",
                "CAP-FIN-FUNDING-STREAM-ADMIN",
            ],
            "matrix_mutation_authorized": False,
        },
        "credit_boundary": {
            "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_7_RECORDS": True,
            "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_7_ACTIONS": True,
            "REVIEWED_SHARED_RELATION_FOR_7_RECORDS": True,
            "REVIEWED_ALIAS_OR_REDIRECT_FOR_1_RECORD": True,
            "REVIEWED_EVIDENCE_GAP_FOR_7_RECORDS": True,
            "STATIC_PAGE_FEATURE_OWNERSHIP": False,
            "framework_route_reachability": False,
            "navigation": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "privacy_correctness": False,
            "direct_object_correctness": False,
            "ledger_integrity_correctness": False,
            "lifecycle_correctness": False,
            "concurrency_correctness": False,
            "runtime": False,
            "database": False,
            "build": False,
            "application_browser": False,
            "executed_tests": False,
            "benchmark": False,
            "ease": False,
            "pass": False,
            "final_finding": False,
            "completion": False,
            "audit_complete": False,
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-independent-outcome-neutral-finance-chart-route-action-review-wave-18.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/raw-run-121r-independent-outcome-neutral-finance-chart-route-action-review-wave-18.json",
        ],
    }


def main() -> None:
    payload = build()
    encoded = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    if not OUTPUT_PATH.exists() or OUTPUT_PATH.read_bytes() != encoded:
        OUTPUT_PATH.write_bytes(encoded)
    print(json.dumps({
        "status": payload["status"],
        "output": OUTPUT_PATH.relative_to(REPO).as_posix(),
        "sha256": sha256_file(OUTPUT_PATH),
        "owner_route_actions": payload["decision"]["owner_route_actions"],
        "shared_relations": payload["decision"]["shared_relations"],
        "alias_or_redirect": payload["decision"]["alias_or_redirect"],
        "evidence_gaps": payload["decision"]["evidence_gaps"],
        "page_ownership_authorized": payload["decision"]["static_page_owner_records_authorized"],
    }, indent=2))


if __name__ == "__main__":
    main()
