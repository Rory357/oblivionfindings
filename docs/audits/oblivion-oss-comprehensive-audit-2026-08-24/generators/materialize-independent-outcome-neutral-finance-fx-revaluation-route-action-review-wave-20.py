#!/usr/bin/env python3
"""Materialize the three fresh RUN-129 FX-revaluation semantic reviews.

Two disjoint reviewers adjudicated store and post. A third reviewer then
reconciled both decisions and their shared service chain. Only bounded static
route ownership and controller-action bridge integration is authorized; all
correctness, runtime, test, benchmark, release, and completion credit remains
zero.
"""

from __future__ import annotations

import hashlib
import json
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
    / "evidence/source/root-run-129-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.json"
)
COHORT_GENERATOR = (
    AUDIT_DIR
    / "generators/build-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.py"
)
MATRIX_PATH = AUDIT_DIR / "03-feature-to-benchmark-matrix.csv"
OUTPUT_PATH = (
    AUDIT_DIR
    / "evidence/source/raw-run-129r-independent-outcome-neutral-finance-fx-revaluation-route-action-review-wave-20.json"
)

CHECKPOINT_COMMIT = "d6bfe72f93ceb88a9d436b31ae5b1844d61455f5"
CHECKPOINT_TREE = "3e5a477a833eacec6a6ca72095279a003a011bf2"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
TESTS_TREE = "fef0122b31fdccbe2f9f805f7515666c74e2880a"
COHORT_SHA256 = "6d1efad203c368986ada06746f4314382b2dee4d214b25799dc531c02608180c"
COHORT_GENERATOR_SHA256 = "2e23ca7736f0e21460f130a6fafc89a68f228b6f8a52137a2209795d500b0982"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
SOURCE_PACKET_SHA256 = "73269f26602fe2213e9715b9183b9765e4151c1d9fc3c37d934a4bfb2e99a940"
FEATURE_ID = "CAP-FIN-FX-REVALUATION"


EXPANDED_SOURCE_SPECS = {
    "docs/architecture/single-tenant-application.md": {
        "sha256": "3dea6218db87ce22bed3cab6b9c500d1a850445d04e9325cb16c23a604979b3c",
        "expanded_review_loci": ["docs/architecture/single-tenant-application.md:1-21"],
        "reason": "include the legacy organisational-context rule at lines 15-17",
    },
    "app/Models/Concerns/WritesLegacyOrganizationStorageContext.php": {
        "sha256": "265b76912c184888fc137988ccd30ba72d43ee89eb7167ba5f6fdcd31ea99118",
        "expanded_review_loci": ["app/Models/Concerns/WritesLegacyOrganizationStorageContext.php:1-32"],
        "reason": "include the complete storage assignment body",
    },
    "app/Http/Middleware/EnsurePermission.php": {
        "sha256": "d9477e5fe8d3dd762332be8ddf3929e4e6098d039e625af40a0241a0ab958e30",
        "expanded_review_loci": ["app/Http/Middleware/EnsurePermission.php:1-29"],
        "reason": "include the explicit 403 denial and pass-through",
    },
    "app/Models/User.php": {
        "sha256": "0d184ebe6a28395b34b195751ffb62390f4c32828c982103ad613430bc4e59ae",
        "expanded_review_loci": ["app/Models/User.php:359-436"],
        "reason": "include canDo and permission-alias resolution",
    },
    "app/Providers/AuthServiceProvider.php": {
        "sha256": "7bcbfb866f657f1e64665806b9f496f3994ba0e90f59686ef71fb97c458b47be",
        "expanded_review_loci": ["app/Providers/AuthServiceProvider.php:1-216"],
        "reason": "include the complete policy registration body",
    },
    "app/Domain/Finance/Models/FinCurrency.php": {
        "sha256": "c38acc4fcfa6705bf58527c3a16c4730a43f96c52f4278e78805f8d35d3d37cd",
        "expanded_review_loci": ["app/Domain/Finance/Models/FinCurrency.php:1-79"],
        "reason": "include organisation, active, and base scopes",
    },
    "app/Domain/Finance/Models/FinFxRate.php": {
        "sha256": "b04ca1e1d52076549fcf721d452ca235cd467beb3c940a1f828cbdd0607ab6a5",
        "expanded_review_loci": ["app/Domain/Finance/Models/FinFxRate.php:1-63"],
        "reason": "include pair and as-at-date scope bodies",
    },
    "app/Domain/Finance/Models/FinBill.php": {
        "sha256": "467220b9a994070626369fcb7e5153cdbcd994e9196f171df8b00aefbfacbc04",
        "expanded_review_loci": ["app/Domain/Finance/Models/FinBill.php:1-153"],
        "reason": "include the complete amount-due and scope semantics",
    },
    "app/Domain/Finance/Models/FinBankAccount.php": {
        "sha256": "7dfde42851923705cdade60a9569eeabc6975e57c0eab9bd1dac9001c01fc78f",
        "expanded_review_loci": ["app/Domain/Finance/Models/FinBankAccount.php:1-86"],
        "reason": "include organisation and active scopes",
    },
    "app/Domain/Finance/Models/FinJournal.php": {
        "sha256": "27455075c067e39e76ef0d29f754ff2d9a684c5bd35af0c9c68deef742e423d8",
        "expanded_review_loci": ["app/Domain/Finance/Models/FinJournal.php:1-137"],
        "reason": "include organisation and journal-status scopes",
    },
    "app/Domain/Finance/Models/FinJournalLine.php": {
        "sha256": "8bc6f37faacfcaab72c651c1baa897ef110e2e77a52ac1db102c33ce72bd633e",
        "expanded_review_loci": ["app/Domain/Finance/Models/FinJournalLine.php:1-78"],
        "reason": "include the complete Site and allocation relationships",
    },
    "app/Domain/Finance/Models/FinFiscalPeriod.php": {
        "sha256": "2c98c141041211b5ae52e54cb68ed511c43d4ff1584e237ed9c19c08f1f83de1",
        "expanded_review_loci": ["app/Domain/Finance/Models/FinFiscalPeriod.php:1-65"],
        "reason": "include the complete organisation and open-period scopes",
    },
    "app/Domain/Finance/Events/JournalPosted.php": {
        "sha256": "7690e35aa5dcf5f30f61a625173f02fc32e6fc6fc5ed56a9dad1c7325c51aa57",
        "expanded_review_loci": ["app/Domain/Finance/Events/JournalPosted.php:1-24"],
        "reason": "include the complete after-commit event contract",
    },
    "app/Listeners/Finance/LogJournalPosted.php": {
        "sha256": "a9427dd5ba554b45c894b06890326ed6eb70dbd2703fa18ac0096ee40e942777",
        "expanded_review_loci": ["app/Listeners/Finance/LogJournalPosted.php:1-18"],
        "reason": "include the complete listener payload and method close",
    },
    "app/Listeners/Finance/AllocatePayrollCosts.php": {
        "sha256": "728932d0d44839cb9f49281d758514c593c3020ff66a25132ebc8c2f55cd30f9",
        "expanded_review_loci": ["app/Listeners/Finance/AllocatePayrollCosts.php:1-49"],
        "reason": "newly follow the second registered listener and its adjustment-journal no-op",
    },
    "database/migrations/2026_03_28_000200_create_fin_accounts_table.php": {
        "sha256": "40f2b19b953267664856230bfb63f1c7fe6f6338bba722569ba297ec48c31181",
        "expanded_review_loci": ["database/migrations/2026_03_28_000200_create_fin_accounts_table.php:1-44"],
        "reason": "newly verify organisation/account-code uniqueness",
    },
}


DECISION_SPECS = (
    {
        "suffix": "01",
        "partition_id": "A",
        "reviewer_task_path": "/root/run125_accounts_create",
        "outcome": "OWNER_ROUTE_ACTION",
        "confidence": "HIGH",
        "source_loci": [
            "03-feature-to-benchmark-matrix.csv:75",
            "routes/finance.php:598",
            "resources/js/pages/finance/fx-revaluations/Create.tsx:66-69",
            "resources/js/pages/finance/fx-revaluations/Create.tsx:271-300",
            "app/Domain/Finance/Http/Controllers/FxRevaluationController.php:54-70",
            "app/Domain/Finance/Services/FxRevaluationService.php:30-143",
        ],
        "material_dependencies": [
            "authenticated finance.ledger.manage route gate",
            "FxRevaluationService calculation and draft persistence",
            "CurrencyService and FinFxRate dated-rate fallback chain",
            "current bills and bank-account exposure population",
            "FinFxRevaluation aggregate-only schema",
        ],
        "rationale": (
            "The action validates the draft request, recalculates unrealised FX exposure, persistently creates a "
            "draft FinFxRevaluation, and returns the canonical creation result. It directly realises the create "
            "component of the frozen FX-revaluation user job."
        ),
        "review_discrepancies": [
            "The frontend preview is not bound to the server-side recalculation or an immutable item/rate snapshot.",
            "The frozen source packet truthfully declared incomplete dependency semantics and required expansion.",
        ],
        "assurance_findings": [
            {
                "finding_id": "RUN129R-A-SITE-SCOPE",
                "severity": "P1",
                "category": "approved_site_and_canonical_scope",
                "loci": [
                    "app/Domain/Finance/Services/FxRevaluationService.php:41-47",
                    "app/Domain/Finance/Models/FinBill.php:26-50",
                    "app/Domain/Finance/Models/FinBankAccount.php:77-80",
                    "app/Domain/Finance/Models/FinFxRevaluation.php:17-26",
                ],
                "observation": "The calculation aggregates organisation-filtered exposure without proving approved-Site scope; the result has no Site attribution.",
            },
            {
                "finding_id": "RUN129R-A-HISTORICAL-POPULATION",
                "severity": "P1",
                "category": "as_at_population_correctness",
                "loci": [
                    "app/Domain/Finance/Services/FxRevaluationService.php:42-57",
                    "app/Domain/Finance/Services/FxRevaluationService.php:81-103",
                    "app/Domain/Finance/Models/FinBill.php:125-133",
                ],
                "observation": "A historical date controls the rate but current bill state, amount paid, and bank balance determine the exposure population.",
            },
            {
                "finding_id": "RUN129R-A-RATE-PROVENANCE",
                "severity": "P1",
                "category": "rate_quality_and_provenance",
                "loci": [
                    "app/Domain/Finance/Services/CurrencyService.php:15-64",
                    "app/Domain/Finance/Services/CurrencyService.php:94-104",
                    "app/Domain/Finance/Models/FinFxRate.php:52-61",
                    "database/migrations/2026_03_28_003200_create_fin_fx_rates_table.php:22-25",
                ],
                "observation": "Missing rates silently fall back to mutable currency values or 1.0, external fetching is a manual placeholder, and equal-date provenance is not unique.",
            },
            {
                "finding_id": "RUN129R-A-SNAPSHOT",
                "severity": "P1",
                "category": "immutable_calculation_snapshot",
                "loci": [
                    "resources/js/pages/finance/fx-revaluations/Create.tsx:51-69",
                    "app/Domain/Finance/Services/FxRevaluationService.php:132-143",
                    "database/migrations/2026_03_28_003300_create_fin_fx_revaluations_table.php:11-22",
                ],
                "observation": "Only an aggregate total and item count survive; source identities, amounts, rates, provenance, and preview identity are discarded.",
            },
            {
                "finding_id": "RUN129R-A-DUPLICATE-DRAFT",
                "severity": "P1",
                "category": "draft_idempotency_and_atomicity",
                "loci": [
                    "app/Domain/Finance/Http/Controllers/FxRevaluationController.php:54-69",
                    "app/Domain/Finance/Services/FxRevaluationService.php:132-143",
                    "database/migrations/2026_03_28_003300_create_fin_fx_revaluations_table.php:11-22",
                ],
                "observation": "No stable idempotency key, uniqueness constraint, or encompassing transaction prevents retry-created duplicate drafts; notes are a second write.",
            },
            {
                "finding_id": "RUN129R-A-ZERO-DRAFT",
                "severity": "P2",
                "category": "draft_lifecycle",
                "loci": [
                    "app/Domain/Finance/Services/FxRevaluationService.php:32-36",
                    "app/Domain/Finance/Services/FxRevaluationService.php:132-143",
                ],
                "observation": "A missing base currency or empty calculation can still be persisted as a zero draft through a direct POST.",
            },
        ],
    },
    {
        "suffix": "02",
        "partition_id": "B",
        "reviewer_task_path": "/root/run125_accounts_show_edit",
        "outcome": "OWNER_ROUTE_ACTION",
        "confidence": "0.99",
        "source_loci": [
            "03-feature-to-benchmark-matrix.csv:75",
            "routes/finance.php:599",
            "resources/js/pages/finance/fx-revaluations/Index.tsx:62-72",
            "resources/js/pages/finance/fx-revaluations/Index.tsx:329-348",
            "app/Domain/Finance/Http/Controllers/FxRevaluationController.php:72-80",
            "app/Domain/Finance/Services/FxRevaluationService.php:149-230",
            "app/Domain/Finance/Services/JournalPostingService.php:30-141",
            "app/Domain/Finance/Services/JournalPostingService.php:261-271",
        ],
        "material_dependencies": [
            "implicit FinFxRevaluation route-model binding",
            "fixed account-code lookup and two-line adjustment journal",
            "JournalPostingService validation, period selection, number sequence, and persistence",
            "FinFxRevaluation lifecycle and journal linkage",
            "after-commit JournalPosted listeners",
        ],
        "rationale": (
            "The action validates draft state, constructs the FX adjustment, creates and posts the journal, then "
            "marks the revaluation posted and links its journal and fiscal period. It directly realises the post-to-GL "
            "component of the frozen FX-revaluation user job."
        ),
        "review_discrepancies": [
            "Direct action ownership remains valid despite material authorization and exactly-once posting defects.",
            "The frozen source packet truthfully declared incomplete dependency semantics and required expansion.",
        ],
        "assurance_findings": [
            {
                "finding_id": "RUN129R-B-DIRECT-OBJECT",
                "severity": "P1",
                "category": "canonical_object_and_direct_id_concealment",
                "loci": [
                    "app/Domain/Finance/Http/Controllers/FxRevaluationController.php:72-75",
                    "app/Domain/Finance/Models/FinFxRevaluation.php:48-52",
                    "app/Providers/AuthServiceProvider.php:164-178",
                ],
                "observation": "Implicit binding is passed directly to the service without a canonical scoped lookup, policy mapping, action authorization, or concealment decision.",
            },
            {
                "finding_id": "RUN129R-B-SITE-ATTRIBUTION",
                "severity": "P1",
                "category": "approved_site_and_journal_attribution",
                "loci": [
                    "docs/architecture/single-tenant-application.md:3-17",
                    "app/Domain/Finance/Models/FinFxRevaluation.php:17-26",
                    "app/Domain/Finance/Services/FxRevaluationService.php:183-221",
                ],
                "observation": "The bound revaluation and its journal lines contain no Site attribution and legacy organisation context proves no approved-Site authority.",
            },
            {
                "finding_id": "RUN129R-B-DUPLICATE-POST",
                "severity": "P1",
                "category": "concurrency_and_exactly_once_lifecycle",
                "loci": [
                    "app/Domain/Finance/Services/FxRevaluationService.php:149-157",
                    "app/Domain/Finance/Services/FxRevaluationService.php:215-227",
                    "app/Domain/Finance/Services/JournalPostingService.php:272-342",
                ],
                "observation": "The pre-transaction draft check and unlocked revaluation permit stale concurrent requests to create separate journals; the mutex protects journal numbering only.",
            },
            {
                "finding_id": "RUN129R-B-IDEMPOTENCY-LINEAGE",
                "severity": "P1",
                "category": "idempotency_and_source_lineage",
                "loci": [
                    "app/Domain/Finance/Services/FxRevaluationService.php:215-221",
                    "app/Domain/Finance/Services/JournalPostingService.php:343-361",
                    "database/migrations/2026_03_28_001000_create_fin_journals_table.php:20-35",
                    "database/migrations/2026_03_28_003300_create_fin_fx_revaluations_table.php:11-22",
                ],
                "observation": "The journal has a human-readable reference but no constrained source identity or stable idempotency key, and the revaluation has no unique posting constraint.",
            },
            {
                "finding_id": "RUN129R-B-LEDGER-PROVENANCE",
                "severity": "P1",
                "category": "ledger_and_rate_provenance",
                "loci": [
                    "app/Domain/Finance/Services/FxRevaluationService.php:132-143",
                    "app/Domain/Finance/Services/FxRevaluationService.php:168-213",
                    "database/migrations/2026_03_28_000200_create_fin_accounts_table.php:11-36",
                ],
                "observation": "Posting relies on an aggregate without item/rate provenance and hard-coded account codes whose universal finance semantics are not established.",
            },
            {
                "finding_id": "RUN129R-B-EVENT-DURABILITY",
                "severity": "P1",
                "category": "event_and_downstream_durability",
                "loci": [
                    "app/Domain/Finance/Events/JournalPosted.php:10-24",
                    "app/Providers/AppServiceProvider.php:524-532",
                    "app/Listeners/Finance/LogJournalPosted.php:8-17",
                    "app/Listeners/Finance/AllocatePayrollCosts.php:21-28",
                ],
                "observation": "The after-commit event is explicitly not a durable outbox; logging is best-effort and payroll allocation is a no-op for adjustment journals.",
            },
        ],
    },
)


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def canonical_json_sha256(value: Any) -> str:
    encoded = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(encoded.encode("utf-8")).hexdigest()


def canonical_list_sha256(values: list[str] | set[str]) -> str:
    return hashlib.sha256("\n".join(sorted(values)).encode("utf-8")).hexdigest()


def load_json(path: Path) -> dict[str, Any]:
    value = json.loads(path.read_text(encoding="utf-8"))
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


def locus_path_and_range(locus: str) -> tuple[Path, int, int]:
    relative, line_spec = locus.rsplit(":", 1)
    match = re.fullmatch(r"(\d+)(?:-(\d+))?", line_spec)
    assert match, locus
    start = int(match.group(1))
    end = int(match.group(2) or match.group(1))
    path = (AUDIT_DIR / relative) if relative.startswith("03-") else (REPO / relative)
    return path, start, end


def assert_locus(locus: str) -> None:
    path, start, end = locus_path_and_range(locus)
    assert path.is_file(), locus
    line_count = len(path.read_text(encoding="utf-8-sig").splitlines())
    assert 1 <= start <= end <= line_count, (locus, line_count)


def assert_workspace() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("rev-parse", "HEAD:tests") == TESTS_TREE
    assert git("status", "--porcelain", "--", "app", "routes", "resources/js", "tests") == ""
    assert sha256_file(COHORT_PATH) == COHORT_SHA256
    assert sha256_file(COHORT_GENERATOR) == COHORT_GENERATOR_SHA256
    assert sha256_file(MATRIX_PATH) == MATRIX_SHA256
    for relative, spec in EXPANDED_SOURCE_SPECS.items():
        path = REPO / relative
        assert path.is_file(), relative
        assert sha256_file(path) == spec["sha256"], relative
        assert git("rev-parse", f"HEAD:{relative}")
        for locus in spec["expanded_review_loci"]:
            assert_locus(locus)


def build() -> dict[str, Any]:
    assert_workspace()
    cohort = load_json(COHORT_PATH)
    assert cohort["run_id"] == "RUN-129-OUTCOME-NEUTRAL-FINANCE-FX-REVALUATION-ROUTE-ACTION-COHORT-WAVE-20"
    assert cohort["status"] == "TWO_FINANCE_FX_REVALUATION_ROUTE_ACTION_CANDIDATES_PENDING_FRESH_REVIEW_ZERO_CREDIT"
    assert cohort["pins"]["checkpoint_commit"] == "73b941a2ff8b587f4cfc813564dab0dd74a3c68b"
    assert cohort["source_review_packet"]["source_review_packet_sha256"] == SOURCE_PACKET_SHA256
    assert cohort["source_review_packet"]["source_review_complete"] is False
    assert cohort["source_review_packet"]["source_packet_completeness_claimed"] is False
    assert cohort["source_review_packet"]["material_dependency_semantics_complete"] is False
    candidates = list(cohort["records"])
    assert len(candidates) == 2
    assert [row["candidate_id"] for row in candidates] == [
        "RUN129-FINANCE-FX-REVALUATION-ROUTE-ACTION-01",
        "RUN129-FINANCE-FX-REVALUATION-ROUTE-ACTION-02",
    ]
    assert [row["candidate_record_sha256"] for row in candidates] == [
        "3e1fd5cb3fde5c96dca76f19ba62d16f4e835518f42ba3d92bd26d4404dd4eb0",
        "7f85f74b38dbe3d609596d38329f0a3066cbef8c77e6600e410540acd584ff27",
    ]

    packet_by_path = {
        row["path"]: row for row in cohort["source_review_packet"]["required_source_files"]
    }
    source_packet_expansions: list[dict[str, Any]] = []
    for relative, spec in EXPANDED_SOURCE_SPECS.items():
        original = packet_by_path.get(relative)
        source_packet_expansions.append(
            {
                "path": relative,
                "sha256": spec["sha256"],
                "blob_id": git("rev-parse", f"HEAD:{relative}"),
                "original_packet_present": original is not None,
                "original_review_loci": original["review_loci"] if original else [],
                "expanded_review_loci": spec["expanded_review_loci"],
                "expansion_reason": spec["reason"],
                "expansion_changes_original_packet_bytes": False,
                "expansion_authorizes_correctness_credit": False,
            }
        )
    assert len(source_packet_expansions) == 16
    assert Counter(row["original_packet_present"] for row in source_packet_expansions) == {
        True: 14,
        False: 2,
    }

    action_decisions: list[dict[str, Any]] = []
    for candidate, spec in zip(candidates, DECISION_SPECS, strict=True):
        assert candidate["candidate_id"].endswith(spec["suffix"])
        assert candidate["review_partition"] == spec["partition_id"]
        assert candidate["fresh_review_state"]["status"] == "PENDING"
        assert candidate["candidate_feature_id"] == FEATURE_ID
        assert candidate["controller_action"]["literal_inertia_page_callsites"] == []
        assert candidate["controller_action"]["literal_inertia_page_callsite_count"] == 0
        assert candidate["collision_checks"]["previous_review_source_collision"] is False
        assert candidate["collision_checks"]["current_owner_source_collision"] is False
        assert candidate["collision_checks"]["existing_controller_action_bridge_collision"] is False
        for locus in spec["source_loci"]:
            assert_locus(locus)
        for finding in spec["assurance_findings"]:
            for locus in finding["loci"]:
                assert_locus(locus)

        primary = candidate["controller_action"]["primary_method_slice"]
        route = candidate["route_source"]
        decision: dict[str, Any] = {
            "candidate_id": candidate["candidate_id"],
            "candidate_record_sha256": candidate["candidate_record_sha256"],
            "partition_id": spec["partition_id"],
            "reviewer_task_path": spec["reviewer_task_path"],
            "queue_index_zero_based": candidate["queue_index_zero_based"],
            "queue_id": candidate["queue_id"],
            "queue_canonical_key": candidate["queue_canonical_key"],
            "route_record_id": route["route_record_id"],
            "source_key": route["source_key"],
            "action_key": candidate["action_key"],
            "candidate_feature_id": candidate["candidate_feature_id"],
            "controller_file": primary["source_file"],
            "controller_method": primary["method"],
            "outcome": spec["outcome"],
            "confidence": spec["confidence"],
            "source_loci": spec["source_loci"],
            "material_dependencies": spec["material_dependencies"],
            "rationale": spec["rationale"],
            "review_discrepancies": spec["review_discrepancies"],
            "assurance_findings": spec["assurance_findings"],
            "route_ownership_authorized": True,
            "controller_action_bridge_authorized": True,
            "owner_source_record_key": f"route|{route['route_record_id']}|{FEATURE_ID}",
            "bridge_key": [primary["source_file"], primary["method"], FEATURE_ID],
            "page_ownership_authorized": False,
            "prior_owner_or_bridge_inheritance_authorized": False,
            "site_permission_privacy_direct_object_rate_ledger_lifecycle_concurrency_correctness_authorized": False,
            "runtime_database_build_browser_test_benchmark_ease_release_pass_completion_authorized": False,
            "reviewer_wrote_files": False,
        }
        decision["decision_record_sha256"] = canonical_json_sha256(decision)
        action_decisions.append(decision)

    assert Counter(row["outcome"] for row in action_decisions) == {"OWNER_ROUTE_ACTION": 2}
    assert [row["controller_method"] for row in action_decisions] == ["store", "post"]
    assert len({tuple(row["bridge_key"]) for row in action_decisions}) == 2
    assert all(row["route_ownership_authorized"] for row in action_decisions)
    assert all(row["controller_action_bridge_authorized"] for row in action_decisions)
    assert not any(row["page_ownership_authorized"] for row in action_decisions)

    shared_assurance_findings = [
        {
            "finding_id": "RUN129R-SHARED-PERMISSION-STATIC-CONTEXT",
            "severity": "UNEXECUTED_STATIC_CONTEXT",
            "category": "permission_declaration",
            "loci": [
                "routes/finance.php:62",
                "routes/finance.php:594-600",
                "bootstrap/app.php:65-73",
                "app/Http/Middleware/EnsurePermission.php:11-27",
                "app/Models/User.php:359-407",
            ],
            "observation": (
                "Both actions declare auth plus finance.ledger.manage and the middleware has deny precedence, "
                "but no representative-role or exact-action execution proves permission correctness."
            ),
            "correctness_credit_authorized": False,
        },
        {
            "finding_id": "RUN129R-SHARED-ACTION-TEST-GAP",
            "severity": "P1",
            "category": "executable_assurance",
            "loci": [
                "tests/Browser/Finance/FinanceMiscTest.php:206-214",
                "tests/Browser/Finance/FinanceMiscTest.php:316-323",
            ],
            "observation": (
                "Frozen tests load Index and Create only; they do not execute store/post, forged IDs, Site scope, "
                "journal assertions, replay, concurrency, rollback, events, or rate snapshots."
            ),
            "correctness_credit_authorized": False,
        },
        {
            "finding_id": "RUN129R-SHARED-SOURCE-PACKET-EXPANSION",
            "severity": "EVIDENCE_BOUNDARY",
            "category": "source_packet_completeness",
            "loci": [],
            "observation": (
                "The cohort correctly claimed no source completeness. Semantic review pinned 14 expanded existing "
                "files and two newly followed files; this does not rewrite the cohort or grant correctness credit."
            ),
            "correctness_credit_authorized": False,
        },
    ]
    for finding in shared_assurance_findings:
        for locus in finding["loci"]:
            assert_locus(locus)

    partition_reviews = [
        {
            "partition_id": decision["partition_id"],
            "reviewer_task_path": decision["reviewer_task_path"],
            "assigned_candidates": 1,
            "candidate_ids": [decision["candidate_id"]],
            "outcome": decision["outcome"],
            "verdict": "GO_BOUNDED_STATIC_OWNER_AND_BRIDGE_ONLY",
            "reviewer_wrote_files": False,
            "correctness_or_downstream_credit_authorized": False,
        }
        for decision in action_decisions
    ]
    synthesis_review = {
        "reviewer_task_path": "/root/run129_final_seal",
        "verdict": "GO_ACCEPT_BOTH_OWNER_ROUTE_ACTION_DECISIONS_FOR_BOUNDED_INTEGRATION",
        "accepted_candidate_ids": [row["candidate_id"] for row in action_decisions],
        "accepted_decision_record_sha256s": [row["decision_record_sha256"] for row in action_decisions],
        "outcome_variables": {"O": 2, "S": 0, "A": 0, "D": 0, "E": 0},
        "shared_chain_reconciled": True,
        "source_packet_expansion_disclosed": True,
        "prior_index_create_owner_and_bridge_inheritance_authorized": False,
        "page_ownership_authorized": False,
        "current_overlay_credit_awarded": False,
        "bounded_overlay_integration_authorized": True,
        "correctness_or_downstream_credit_authorized": False,
        "reviewer_wrote_files": False,
    }
    synthesis_review["synthesis_record_sha256"] = canonical_json_sha256(synthesis_review)

    baseline = cohort["current_baseline"]
    assert baseline["source_owner_records"] == 652
    assert baseline["route_owner_records"] == 295
    assert baseline["page_owner_records"] == 357
    assert baseline["static_controller_action_bridges"] == 83
    assert baseline["bounded_static_source_residual_records"] == 3277
    assert baseline["reviewed_queue_surface_rows"] == 106
    assert baseline["pending_unreviewed_queue_surface_rows"] == 401
    projection = {
        "O": 2,
        "S": 0,
        "A": 0,
        "D": 0,
        "E": 0,
        "source_owner_records": 654,
        "route_owner_records": 297,
        "page_owner_records": 357,
        "static_controller_action_bridges": 85,
        "bounded_static_source_denominator": 3929,
        "bounded_static_source_residual_records": 3275,
        "bounded_static_source_ownership_percent": str(
            (Decimal(654) * Decimal(100) / Decimal(3929)).quantize(
                Decimal("0.000001"), rounding=ROUND_HALF_UP
            )
        ),
        "direct_exact_queue_records": 507,
        "reviewed_queue_surface_rows": 108,
        "owner_queue_surface_rows": 86,
        "shared_queue_surface_rows": 10,
        "alias_queue_surface_rows": 5,
        "dead_queue_surface_rows": 0,
        "evidence_gap_queue_surface_rows": 7,
        "pending_unreviewed_queue_surface_rows": 399,
        "queue_surfaces_without_ownership": 421,
        "residual_explicit_unmapped_routes": 2904,
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
        "route_distinct_feature_ids": 62,
        "page_distinct_feature_ids": 242,
        "route_page_feature_overlap": 48,
        "matrix_rows_changed": 0,
        "matrix_cells_changed": 0,
        "projection_credit_awarded": False,
    }
    assert projection["O"] + projection["S"] + projection["A"] + projection["D"] + projection["E"] == 2
    assert projection["source_owner_records"] + projection["bounded_static_source_residual_records"] == 3929
    assert projection["source_owner_records"] == projection["route_owner_records"] + projection["page_owner_records"]
    assert projection["bounded_static_source_ownership_percent"] == "16.645457"
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
    assert 3218 == 297 + 12 + 5 + 0 + 2904
    assert 711 == 357 + 9 + 345

    decision_hashes = [row["decision_record_sha256"] for row in action_decisions]
    return {
        "schema_version": "run-129r-independent-outcome-neutral-finance-fx-revaluation-route-action-review-wave-20-v1",
        "run_id": "RUN-129R-INDEPENDENT-OUTCOME-NEUTRAL-FINANCE-FX-REVALUATION-ROUTE-ACTION-REVIEW-WAVE-20",
        "status": "GO_TWO_PARTITION_REVIEWS_AND_FRESH_SYNTHESIS_COMPLETE_TWO_BOUNDED_OWNERS_ZERO_DOWNSTREAM_CREDIT",
        "reviewed_on": "2026-08-26",
        "decision": {
            "verdict": "GO_2_EXPLICIT_OWNER_ROUTE_ACTION",
            "mechanical_discrepancies": 0,
            "semantic_outcome_discrepancies": 0,
            "source_packet_expansion_discrepancies_disclosed": 16,
            "reviewed_route_actions": 2,
            "owner_route_actions": 2,
            "shared_relations": 0,
            "alias_or_redirect": 0,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 0,
            "static_route_owner_records_authorized": 2,
            "static_controller_action_bridges_authorized": 2,
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
            "matrix_sha256": MATRIX_SHA256,
            "materializer": Path(__file__).relative_to(AUDIT_DIR).as_posix(),
            "materializer_sha256": sha256_file(Path(__file__)),
        },
        "architecture_rule": (
            "Oblivion Findings is one operating organisation across multiple Sites. Legacy organization_id context "
            "is not tenancy or an access boundary. Static action ownership proves neither approved-Site reach, "
            "permission correctness, canonical-object concealment, privacy, nor finance correctness."
        ),
        "methods": [
            "Reviewer A adjudicated only store; reviewer B adjudicated only post; neither wrote files.",
            "A third fresh reviewer reconciled both decisions and the shared service chain after receiving both returns.",
            "Direct action ownership was kept separate from authorization, Site, rate, ledger, lifecycle, concurrency, and assurance correctness.",
            "The original packet's explicit incompleteness was preserved and every material semantic expansion was pinned and disclosed.",
            "Only OWNER_ROUTE_ACTION authorizes later bounded route and bridge integration; page callers remain context only.",
        ],
        "verified_counts": {
            "partition_reviews": 2,
            "cohort_synthesis_reviews": 1,
            "total_fresh_reviews": 3,
            "reviewed_route_actions": 2,
            "owner_route_actions": 2,
            "accepted_route_records": 2,
            "accepted_controller_action_bridges": 2,
            "accepted_page_records": 0,
            "accepted_distinct_feature_ids": 1,
            "new_distinct_feature_ids": 0,
            "source_packet_expansion_files": len(source_packet_expansions),
            "source_packet_expansion_existing_files": 14,
            "source_packet_expansion_new_files": 2,
            "candidate_assurance_findings": sum(len(row["assurance_findings"]) for row in action_decisions),
            "shared_assurance_findings": len(shared_assurance_findings),
            "assurance_findings": (
                sum(len(row["assurance_findings"]) for row in action_decisions)
                + len(shared_assurance_findings)
            ),
            "reviewer_written_files": 0,
            "matrix_rows_changed": 0,
            "matrix_cells_changed": 0,
        },
        "verified_global_identity": {
            "reviewed_queue_index_list_sha256": cohort["identity"]["queue_index_list_sha256"],
            "reviewed_queue_id_list_sha256": cohort["identity"]["queue_id_list_sha256"],
            "reviewed_queue_id_canonical_key_pair_list_sha256": cohort["identity"]["queue_id_canonical_key_pair_list_sha256"],
            "reviewed_route_record_id_list_sha256": cohort["identity"]["route_record_id_list_sha256"],
            "reviewed_action_key_list_sha256": cohort["identity"]["action_key_list_sha256"],
            "reviewed_candidate_record_sha256_list_sha256": cohort["identity"]["candidate_record_sha256_list_sha256"],
            "owner_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in action_decisions]),
            "owner_route_record_id_list_sha256": canonical_list_sha256([row["route_record_id"] for row in action_decisions]),
            "owner_source_record_key_list_sha256": canonical_list_sha256([row["owner_source_record_key"] for row in action_decisions]),
            "owner_bridge_key_list_sha256": canonical_list_sha256(["|".join(row["bridge_key"]) for row in action_decisions]),
            "owner_feature_id_list_sha256": canonical_list_sha256({FEATURE_ID}),
            "new_owner_feature_id_list_sha256": canonical_list_sha256(set()),
            "decision_record_sha256_list_sha256": canonical_list_sha256(decision_hashes),
            "reviewed_decisions_sha256": canonical_json_sha256(action_decisions),
            "partition_reviews_sha256": canonical_json_sha256(partition_reviews),
            "synthesis_record_sha256": synthesis_review["synthesis_record_sha256"],
            "source_packet_expansions_sha256": canonical_json_sha256(source_packet_expansions),
        },
        "partition_reviews": partition_reviews,
        "synthesis_review": synthesis_review,
        "action_decisions": action_decisions,
        "shared_assurance_findings": shared_assurance_findings,
        "source_packet_expansion": {
            "original_source_review_complete": False,
            "original_source_packet_completeness_claimed": False,
            "original_material_dependency_semantics_complete": False,
            "original_packet_retroactively_described_as_complete": False,
            "expanded_files": source_packet_expansions,
            "expansion_authorizes_correctness_credit": False,
        },
        "reviewed_projection_if_integrated": projection,
        "shared_chain_reconciliation": {
            "store_creates_post_input": True,
            "post_consumes_store_output": True,
            "both_directly_realise_canonical_user_job": True,
            "unsafe_owner_may_remain_owner": True,
            "index_create_owner_or_bridge_inheritance_used": False,
            "frontend_page_ownership_inherited": False,
            "current_overlay_credit_awarded": False,
        },
        "credit_boundary": {
            "REVIEWED_STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_2_RECORDS": True,
            "REVIEWED_STATIC_CONTROLLER_ACTION_BRIDGE_FOR_2_ACTIONS": True,
            "BOUNDED_OVERLAY_INTEGRATION_AUTHORIZED": True,
            "CURRENT_OVERLAY_OWNERSHIP_CREDIT": False,
            "STATIC_PAGE_FEATURE_OWNERSHIP": False,
            "prior_owner_or_bridge_inheritance": False,
            "framework_route_reachability": False,
            "navigation": False,
            "canonical_object_ownership_correctness": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "privacy_correctness": False,
            "direct_object_concealment_correctness": False,
            "rate_and_snapshot_correctness": False,
            "ledger_integrity_correctness": False,
            "lifecycle_correctness": False,
            "concurrency_and_idempotency_correctness": False,
            "event_and_downstream_durability_correctness": False,
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
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-independent-outcome-neutral-finance-fx-revaluation-route-action-review-wave-20.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/raw-run-129r-independent-outcome-neutral-finance-fx-revaluation-route-action-review-wave-20.json",
        ],
    }


def main() -> None:
    payload = build()
    encoded = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    if not OUTPUT_PATH.exists() or OUTPUT_PATH.read_bytes() != encoded:
        OUTPUT_PATH.write_bytes(encoded)
    print(
        json.dumps(
            {
                "status": payload["status"],
                "output": OUTPUT_PATH.relative_to(REPO).as_posix(),
                "sha256": sha256_file(OUTPUT_PATH),
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
