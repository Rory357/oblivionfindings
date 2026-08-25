#!/usr/bin/env python3
"""Materialize four fresh RUN-133 accounting-integration semantic reviews.

Three disjoint reviewers adjudicate two actions each. A fourth fresh reviewer
then reconciles all six decisions and their shared integration lifecycle. Only
bounded static route ownership and controller-action bridge integration may be
authorized; all correctness, runtime, test, benchmark, release, and completion
credit remains zero.
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
    / "evidence/source/root-run-133-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.json"
)
COHORT_GENERATOR = (
    AUDIT_DIR
    / "generators/build-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.py"
)
MATRIX_PATH = AUDIT_DIR / "03-feature-to-benchmark-matrix.csv"
OUTPUT_PATH = (
    AUDIT_DIR
    / "evidence/source/raw-run-133r-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21.json"
)

CHECKPOINT_COMMIT = "46d8e54bc65dc3cde6cb4a98db055201f96a9335"
CHECKPOINT_TREE = "754f33a8c885f03470ab5cc2911093a0312a4aa5"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
TESTS_TREE = "fef0122b31fdccbe2f9f805f7515666c74e2880a"
COHORT_SHA256 = "58d87fa101e4e1b51d232baf80e1a2e3ef75dad89f37dc0dcd615c2f16e29ce4"
COHORT_GENERATOR_SHA256 = "476966a02322f59f385fb59dc9a55a3774e868e512cb58d5f0606698cbfd08af"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
SOURCE_PACKET_SHA256 = "d99da8f946f350820b2ed9484180dde61dd180016dd2fece8f472b8d7a0171d3"
FEATURE_ID = "CAP-FIN-ACCOUNTING-INTEGRATION-CONFIGURATION"


EXPANDED_SOURCE_SPECS: dict[str, dict[str, Any]] = {
    "database/migrations/2026_03_28_000200_create_fin_accounts_table.php": {
        "sha256": "40f2b19b953267664856230bfb63f1c7fe6f6338bba722569ba297ec48c31181",
        "expanded_review_loci": ["database/migrations/2026_03_28_000200_create_fin_accounts_table.php:11-37"],
        "reason": "follow account organisation scope, missing Site attribution, and code uniqueness",
    },
    "app/Domain/Finance/Policies/FinAccountPolicy.php": {
        "sha256": "af1f6d4cf0bbaf0c95308f18393de3f2a60f30591ba62badfb39695182c62446",
        "expanded_review_loci": ["app/Domain/Finance/Policies/FinAccountPolicy.php:8-33"],
        "reason": "verify ledger permissions exist but are not invoked by the selected mapping actions",
    },
    "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json": {
        "sha256": "bfec52a9bed7176045457e8920bdc7b65e1c1d5c5eeb28a266f49c12acf69bcf",
        "expanded_review_loci": ["docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json:31080-31149"],
        "reason": "verify the exact existing Mapping-page D-feature attribution and no-credit boundary",
    },
    "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/03-feature-to-benchmark-matrix.csv": {
        "sha256": "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390",
        "expanded_review_loci": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/03-feature-to-benchmark-matrix.csv:1",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/03-feature-to-benchmark-matrix.csv:51",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/03-feature-to-benchmark-matrix.csv:92",
        ],
        "reason": "reconcile the selected H route identity against the existing D Mapping-page identity",
    },
    "app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php": {
        "sha256": "c50111efb044965e3c301338491f67510fecba1d915725a1d2b7efb6d12c1f80",
        "expanded_review_loci": [
            "app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php:27-51",
            "app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php:300-364",
            "app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php:385-483",
            "app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php:520-635",
        ],
        "reason": "follow token refresh, competing mapping persistence, and downstream AccountID consumption",
    },
    "resources/js/pages/finance/Integrations/Mapping.tsx": {
        "sha256": "afe804d9305fa29063e8832dd19cdc30cb4f4593ce411368f071e64df208b8e7",
        "expanded_review_loci": [
            "resources/js/pages/finance/Integrations/Mapping.tsx:63-122",
            "resources/js/pages/finance/Integrations/Mapping.tsx:220-275",
        ],
        "reason": "close the editable account-map UI and absence of tax-map controls",
    },
}


DECISION_SPECS: tuple[dict[str, Any], ...] = (
    {
        "suffix": "01",
        "partition_id": "A",
        "reviewer_task_path": "/root/run125_accounts_show_edit",
        "outcome": "OWNER_ROUTE_ACTION",
        "confidence": "HIGH",
        "literal_inertia_page_callsite_count": 0,
        "source_loci": [
            "03-feature-to-benchmark-matrix.csv:51",
            "routes/finance.php:603-605",
            "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:72-100",
            "app/Domain/Finance/Models/FinAccountingIntegration.php:13-51",
            "database/migrations/2026_03_28_003800_create_fin_accounting_integrations_table.php:11-32",
            "resources/js/pages/finance/Integrations/Index.tsx:123-245",
        ],
        "material_dependencies": [
            "authenticated finance.admin route gate",
            "provider and sync-direction validation",
            "FinAccountingIntegration persistent configuration schema",
            "Index-page creation form and literal caller",
        ],
        "rationale": (
            "The action validates provider configuration, persistently creates a FinAccountingIntegration with "
            "actor and legacy organisation storage context, and returns the canonical creation result. It directly "
            "realises the configure component of the frozen integration job; its post-mutation redirect is not an alias."
        ),
        "review_discrepancies": [
            "Provider capability and credential provisioning remain unproved.",
            "The cohort correctly declared incomplete dependency semantics; bounded expansions are disclosed separately.",
        ],
        "assurance_findings": [
            {
                "finding_id": "RUN133R-A-STORE-UNIQUENESS-LIFECYCLE",
                "severity": "P1",
                "category": "soft_delete_reconnect_and_idempotency",
                "loci": [
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:76-97",
                    "database/migrations/2026_03_28_003800_create_fin_accounting_integrations_table.php:29-32",
                ],
                "observation": "Validation ignores soft-deleted rows while the physical organisation/provider unique key does not; reconnect can collide and concurrent or replayed creates have no idempotency or locking contract.",
            },
            {
                "finding_id": "RUN133R-A-PROVIDER-CAPABILITY",
                "severity": "P1",
                "category": "provider_configuration_lifecycle",
                "loci": [
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:76-97",
                    "app/Domain/Finance/Services/AccountingSyncProviders/MyobSyncProvider.php:10-98",
                    "app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php:574-594",
                ],
                "observation": "Store admits MYOB although every MYOB sync operation is unsupported, and it establishes neither Xero OAuth credentials nor a required external tenant identifier.",
            },
        ],
    },
    {
        "suffix": "02",
        "partition_id": "A",
        "reviewer_task_path": "/root/run125_accounts_show_edit",
        "outcome": "OWNER_ROUTE_ACTION",
        "confidence": "HIGH",
        "literal_inertia_page_callsite_count": 0,
        "source_loci": [
            "03-feature-to-benchmark-matrix.csv:51",
            "routes/finance.php:603-606",
            "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:106-120",
            "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:236-240",
            "app/Domain/Finance/Models/FinAccountingIntegration.php:19-51",
            "resources/js/pages/finance/Integrations/Index.tsx:1-570",
            "resources/js/pages/finance/Integrations/Mapping.tsx:63-122",
        ],
        "material_dependencies": [
            "implicit FinAccountingIntegration binding",
            "legacy organisation comparison helper",
            "mutable sync direction and arbitrary settings",
            "no literal general-update caller in the frozen integration pages",
        ],
        "rationale": (
            "The action authorises the bound configuration, validates mutable configuration fields, and persistently "
            "updates them. It directly realises configuration management. Absence of a literal caller affects "
            "discoverability evidence, not the concrete canonical mutation or its direct ownership."
        ),
        "review_discrepancies": [
            "No literal caller exists in the two frozen integration pages.",
            "Authorization and concurrent update correctness remain zero-credit findings.",
        ],
        "assurance_findings": [
            {
                "finding_id": "RUN133R-A-UPDATE-CONCURRENCY-DISCOVERABILITY",
                "severity": "P2",
                "category": "lost_update_and_navigation_evidence",
                "loci": [
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:106-120",
                    "resources/js/pages/finance/Integrations/Index.tsx:1-570",
                    "resources/js/pages/finance/Integrations/Mapping.tsx:63-122",
                ],
                "observation": "No optimistic version, lock, or idempotency protection prevents lost updates, and the frozen pages provide no literal general-update caller; ownership remains static and direct only.",
            },
        ],
    },
    {
        "suffix": "03",
        "partition_id": "B",
        "reviewer_task_path": "/root/run125_accounts_create",
        "outcome": "OWNER_ROUTE_ACTION",
        "confidence": "HIGH",
        "literal_inertia_page_callsite_count": 0,
        "source_loci": [
            "03-feature-to-benchmark-matrix.csv:51",
            "routes/finance.php:602-612",
            "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:143-159",
            "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:236-240",
            "app/Domain/Finance/Services/GlSyncService.php:239-245",
            "app/Domain/Finance/Contracts/AccountingSyncProviderInterface.php:70-78",
            "app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php:300-364",
            "app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php:520-594",
            "app/Domain/Finance/Services/AccountingSyncProviders/MyobSyncProvider.php:81-96",
            "resources/js/pages/finance/Integrations/Index.tsx:266-275",
            "resources/js/pages/finance/Integrations/Index.tsx:448-455",
        ],
        "material_dependencies": [
            "provider selection through GlSyncService",
            "AccountingSyncProviderInterface connection-test contract",
            "Xero token refresh and external API request chain",
            "MYOB unsupported provider implementation",
            "Index-page literal test caller",
        ],
        "rationale": (
            "The action resolves the configured provider, executes its connection-test contract, and returns the "
            "canonical success or failure result invoked by the integration UI. It directly realises the explicit "
            "test-connection component; provider defects affect correctness, not ownership."
        ),
        "review_discrepancies": [
            "MYOB and Xero credential lifecycle evidence is materially incomplete.",
            "Raw exception and provider-response handling prevents correctness credit.",
        ],
        "assurance_findings": [
            {
                "finding_id": "RUN133R-B-XERO-CREDENTIAL-PROVISIONING",
                "severity": "P1",
                "category": "provider_credential_lifecycle",
                "loci": [
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:76-97",
                    "app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php:574-594",
                    "config/services.php:90-93",
                ],
                "observation": "The UI/controller creates no access or refresh token while Xero testing requires credentials; no bounded OAuth provisioning route or production token writer was found.",
            },
            {
                "finding_id": "RUN133R-B-ERROR-DISCLOSURE",
                "severity": "P2",
                "category": "provider_error_concealment",
                "loci": [
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:147-157",
                    "app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php:520-543",
                ],
                "observation": "Normal Xero connection failures are reduced to a generic result, but raw response or exception material can reach server logs and the controller fallback can expose failures not swallowed by the provider.",
            },
            {
                "finding_id": "RUN133R-B-TEST-REFRESH-RACE",
                "severity": "P1",
                "category": "token_refresh_concurrency",
                "loci": [
                    "app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php:319-359",
                    "app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php:520-594",
                ],
                "observation": "Connection tests can refresh rotating credentials without row locking, a lifecycle token, or compare-and-swap protection.",
            },
            {
                "finding_id": "RUN133R-B-INACTIVE-CONNECTION-TEST",
                "severity": "P1",
                "category": "lifecycle_state_enforcement",
                "loci": [
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:143-159",
                    "app/Domain/Finance/Models/FinAccountingIntegration.php:72-75",
                ],
                "observation": "The selected test action does not reject an inactive integration before provider selection, token refresh, or external connection work.",
            },
        ],
    },
    {
        "suffix": "04",
        "partition_id": "B",
        "reviewer_task_path": "/root/run125_accounts_create",
        "outcome": "OWNER_ROUTE_ACTION",
        "confidence": "HIGH",
        "literal_inertia_page_callsite_count": 0,
        "source_loci": [
            "03-feature-to-benchmark-matrix.csv:51",
            "routes/finance.php:602-612",
            "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:164-173",
            "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:236-240",
            "app/Domain/Finance/Models/FinAccountingIntegration.php:13-51",
            "database/migrations/2026_03_28_003800_create_fin_accounting_integrations_table.php:11-32",
            "resources/js/pages/finance/Integrations/Index.tsx:277-279",
            "resources/js/pages/finance/Integrations/Index.tsx:469-506",
            "app/Domain/Finance/Jobs/SyncAccountingIntegrationJob.php:32-113",
        ],
        "material_dependencies": [
            "implicit FinAccountingIntegration binding and legacy organisation check",
            "SoftDeletes lifecycle",
            "Index-page disconnect confirmation and literal caller",
            "queued sync job lifecycle",
        ],
        "rationale": (
            "The confirmed Disconnect UI calls this action, which locally soft-deletes the configured connection and "
            "returns the canonical disconnect result. Missing external revocation and race control do not negate the "
            "direct ownership of the explicit disconnect component."
        ),
        "review_discrepancies": [
            "Disconnect wording exceeds the proved local soft-delete effect.",
            "Queued work, provider revocation, credential retention, and reconnect correctness remain unproved.",
        ],
        "assurance_findings": [
            {
                "finding_id": "RUN133R-B-DISCONNECT-REVOCATION",
                "severity": "P1",
                "category": "external_revocation_and_credential_retention",
                "loci": [
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:164-173",
                    "app/Domain/Finance/Contracts/AccountingSyncProviderInterface.php:70-79",
                    "app/Domain/Finance/Models/FinAccountingIntegration.php:13-51",
                ],
                "observation": "Disconnect only soft-deletes the local row; the provider contract exposes no revocation operation and encrypted credentials remain retained.",
            },
            {
                "finding_id": "RUN133R-B-DISCONNECT-SYNC-RACE",
                "severity": "P1",
                "category": "lifecycle_concurrency",
                "loci": [
                    "app/Domain/Finance/Jobs/SyncAccountingIntegrationJob.php:32-89",
                    "app/Domain/Finance/Services/GlSyncService.php:209-233",
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:164-173",
                ],
                "observation": "A queued sync that loads the active row before deletion can continue external work after the user receives disconnect success; no cancellation, lock, or lifecycle token closes the race.",
            },
            {
                "finding_id": "RUN133R-B-RECONNECT-UNIQUE-CONFLICT",
                "severity": "P1",
                "category": "soft_delete_reconnect_lifecycle",
                "loci": [
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:80-82",
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:164-173",
                    "database/migrations/2026_03_28_003800_create_fin_accounting_integrations_table.php:29-32",
                ],
                "observation": "A promised reconnect can pass soft-delete-aware validation and then collide with the retained physical organisation/provider unique row.",
            },
        ],
    },
    {
        "suffix": "05",
        "partition_id": "C",
        "reviewer_task_path": "/root/run129_final_seal",
        "outcome": "OWNER_ROUTE_ACTION",
        "confidence": "HIGH",
        "literal_inertia_page_callsite_count": 1,
        "source_loci": [
            "03-feature-to-benchmark-matrix.csv:51",
            "routes/finance.php:603-610",
            "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:178-209",
            "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:236-240",
            "app/Domain/Finance/Models/FinAccount.php:13-98",
            "resources/js/pages/finance/Integrations/Index.tsx:459-467",
        ],
        "material_dependencies": [
            "legacy organisation comparison helper",
            "active organisation-filtered FinAccount projection",
            "provider-specific external-ID selection",
            "existing Mapping-page render owned by the Xero-sync feature",
        ],
        "rationale": (
            "The action authorises the selected integration, resolves active local finance accounts, projects "
            "provider-specific external IDs plus existing maps, and renders the mapping workflow. It directly "
            "realises manage mappings while the rendered page remains a non-inheritable existing owner."
        ),
        "review_discrepancies": [
            "The rendered Mapping page remains exclusively owned by CAP-FIN-XERO-ACCOUNTING-SYNC.",
            "Organisation-wide account selection proves neither approved-Site nor mapping correctness.",
        ],
        "assurance_findings": [
            {
                "finding_id": "RUN133R-C-MAPPING-SCOPE",
                "severity": "P1",
                "category": "canonical_account_and_site_scope",
                "loci": [
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:178-209",
                    "app/Domain/Finance/Models/FinAccount.php:80-87",
                    "database/migrations/2026_03_28_000200_create_fin_accounts_table.php:11-37",
                ],
                "observation": "Account selection is organisation-wide, has no Site attribution, and becomes unscoped when the legacy organisation ID is null; intended global scope does not establish approved-Site or privacy correctness.",
            },
        ],
    },
    {
        "suffix": "06",
        "partition_id": "C",
        "reviewer_task_path": "/root/run129_final_seal",
        "outcome": "OWNER_ROUTE_ACTION",
        "confidence": "HIGH",
        "literal_inertia_page_callsite_count": 0,
        "source_loci": [
            "03-feature-to-benchmark-matrix.csv:51",
            "routes/finance.php:603-611",
            "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:214-232",
            "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:236-240",
            "app/Domain/Finance/Models/FinAccountingIntegration.php:98-110",
            "resources/js/pages/finance/Integrations/Mapping.tsx:104-121",
            "app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php:385-483",
            "app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php:626-632",
        ],
        "material_dependencies": [
            "account and tax map validation and whole-map replacement",
            "Mapping-page literal caller",
            "Xero AccountID downstream consumption",
            "provider-driven read-modify-write mapping persistence",
        ],
        "rationale": (
            "The action accepts and persistently replaces the account and tax mapping configuration before returning "
            "to the workflow. It directly realises the mapping-write component. Downstream Xero consumption does not "
            "make this configuration action shared with the excluded sync action."
        ),
        "review_discrepancies": [
            "The Mapping page and Xero provider are context only and transfer no page or sync ownership.",
            "Mapping validation, uniqueness, concurrency, and downstream posting correctness remain zero-credit.",
        ],
        "assurance_findings": [
            {
                "finding_id": "RUN133R-C-MAPPING-KEY-VALUE-INTEGRITY",
                "severity": "P1",
                "category": "mapping_identity_validation",
                "loci": [
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:214-228",
                    "app/Domain/Finance/Models/FinAccountingIntegration.php:98-110",
                ],
                "observation": "Validation covers values but not whether keys are existing active canonical-scope accounts; arbitrary external strings and duplicates are accepted, and array_flip collapses duplicate reverse mappings.",
            },
            {
                "finding_id": "RUN133R-C-MAPPING-DOWNSTREAM-USE",
                "severity": "P1",
                "category": "external_posting_integrity",
                "loci": [
                    "app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php:385-483",
                ],
                "observation": "Saved values override code fallback and become Xero AccountID values for journal and bill lines without external existence, format, or uniqueness proof.",
            },
            {
                "finding_id": "RUN133R-C-MAPPING-LOST-UPDATE",
                "severity": "P1",
                "category": "mapping_concurrency",
                "loci": [
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:214-228",
                    "app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php:626-632",
                ],
                "observation": "The UI replaces the whole JSON map while account pushes independently read-modify-write it, with no row lock, version, or compare-and-swap protection.",
            },
            {
                "finding_id": "RUN133R-C-TAX-MAPPING-ORPHAN",
                "severity": "P2",
                "category": "unconsumed_configuration",
                "loci": [
                    "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:214-228",
                    "resources/js/pages/finance/Integrations/Mapping.tsx:220-275",
                ],
                "observation": "Tax mapping is accepted and round-tripped but the complete frozen Mapping UI exposes no tax-map editor and no bounded provider consumer was found.",
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
    assert cohort["run_id"] == "RUN-133-OUTCOME-NEUTRAL-FINANCE-ACCOUNTING-INTEGRATION-ROUTE-ACTION-COHORT-WAVE-21"
    assert cohort["status"] == "SIX_FINANCE_ACCOUNTING_INTEGRATION_ROUTE_ACTION_CANDIDATES_PENDING_FRESH_REVIEW_ZERO_CREDIT"
    assert cohort["pins"]["checkpoint_commit"] == "6a92f583b2d675411033af632a6b4fbd4cf48c17"
    assert cohort["source_review_packet"]["source_review_packet_sha256"] == SOURCE_PACKET_SHA256
    assert cohort["source_review_packet"]["source_review_complete"] is False
    assert cohort["source_review_packet"]["source_packet_completeness_claimed"] is False
    assert cohort["source_review_packet"]["material_dependency_semantics_complete"] is False
    candidates = list(cohort["records"])
    assert len(candidates) == 6
    assert [row["candidate_id"] for row in candidates] == [
        "RUN133-FINANCE-ACCOUNTING-INTEGRATION-ROUTE-ACTION-01",
        "RUN133-FINANCE-ACCOUNTING-INTEGRATION-ROUTE-ACTION-02",
        "RUN133-FINANCE-ACCOUNTING-INTEGRATION-ROUTE-ACTION-03",
        "RUN133-FINANCE-ACCOUNTING-INTEGRATION-ROUTE-ACTION-04",
        "RUN133-FINANCE-ACCOUNTING-INTEGRATION-ROUTE-ACTION-05",
        "RUN133-FINANCE-ACCOUNTING-INTEGRATION-ROUTE-ACTION-06",
    ]
    assert [row["candidate_record_sha256"] for row in candidates] == [
        "0fe63f046af0f5e55943f7d16d9f0cf096ad37b2a499129d75456771564fb920",
        "cf47aabd8e79dc20e201fa33dd081842a859c2b3d9c8571946b15389464ed9aa",
        "a45ee7cdf94024775f92203574bf58dc70917d7fc395781cafa587121d7646bb",
        "77135d270260ea7a105ae0d2b30020d6a92506dcfe029bfd7d2865fb0f17201e",
        "1faf128de8ac4de8dbfaef7a08f9c494679586c602317f0f32e1a4fe334d215a",
        "2d16242f90c2d8715b28f00d4e4cbb82d801b9798fced1c03942651a89122cc5",
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
    assert len(source_packet_expansions) == 6
    assert Counter(row["original_packet_present"] for row in source_packet_expansions) == {
        True: 2,
        False: 4,
    }

    action_decisions: list[dict[str, Any]] = []
    for candidate, spec in zip(candidates, DECISION_SPECS, strict=True):
        assert candidate["candidate_id"].endswith(spec["suffix"])
        assert candidate["review_partition"] == spec["partition_id"]
        assert candidate["fresh_review_state"]["status"] == "PENDING"
        assert candidate["candidate_feature_id"] == FEATURE_ID
        assert candidate["controller_action"]["literal_inertia_page_callsite_count"] == spec["literal_inertia_page_callsite_count"]
        assert len(candidate["controller_action"]["literal_inertia_page_callsites"]) == spec["literal_inertia_page_callsite_count"]
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
            "route_ownership_authorized": spec["outcome"] == "OWNER_ROUTE_ACTION",
            "controller_action_bridge_authorized": spec["outcome"] == "OWNER_ROUTE_ACTION",
            "owner_source_record_key": f"route|{route['route_record_id']}|{FEATURE_ID}",
            "bridge_key": [primary["source_file"], primary["method"], FEATURE_ID],
            "page_ownership_authorized": False,
            "prior_owner_or_bridge_inheritance_authorized": False,
            "site_permission_privacy_direct_object_provider_mapping_lifecycle_concurrency_correctness_authorized": False,
            "runtime_database_build_browser_test_benchmark_ease_release_pass_completion_authorized": False,
            "reviewer_wrote_files": False,
        }
        decision["decision_record_sha256"] = canonical_json_sha256(decision)
        action_decisions.append(decision)

    assert Counter(row["outcome"] for row in action_decisions) == {"OWNER_ROUTE_ACTION": 6}
    assert [row["controller_method"] for row in action_decisions] == [
        "store",
        "update",
        "testConnection",
        "destroy",
        "mapping",
        "updateMapping",
    ]
    assert len({tuple(row["bridge_key"]) for row in action_decisions}) == 6
    assert all(row["route_ownership_authorized"] for row in action_decisions)
    assert all(row["controller_action_bridge_authorized"] for row in action_decisions)
    assert not any(row["page_ownership_authorized"] for row in action_decisions)

    shared_assurance_findings = [
        {
            "finding_id": "RUN133R-SHARED-CANONICAL-AUTHORIZATION",
            "severity": "P1",
            "category": "authorization_canonical_record_and_concealment",
            "loci": [
                "routes/finance.php:62",
                "routes/finance.php:603-611",
                "app/Http/Middleware/EnsurePermission.php:11-25",
                "app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:236-240",
                "app/Models/User.php:359-407",
                "app/Providers/AuthServiceProvider.php:137-210",
                "docs/architecture/single-tenant-application.md:3-17",
            ],
            "observation": (
                "All actions require auth plus finance.admin, but no integration policy or canonical scoped binding "
                "exists. The helper compares legacy organisation context even though the architecture says it is not "
                "an access boundary, and returns 403 for a bound foreign direct ID rather than proving concealment."
            ),
            "correctness_credit_authorized": False,
        },
        {
            "finding_id": "RUN133R-SHARED-SITE-PRIVACY-BOUNDARY",
            "severity": "EVIDENCE_BOUNDARY",
            "category": "approved_site_and_privacy_scope",
            "loci": [
                "docs/architecture/single-tenant-application.md:3-17",
                "app/Domain/Finance/Models/FinAccountingIntegration.php:19-35",
                "database/migrations/2026_03_28_000200_create_fin_accounts_table.php:11-37",
            ],
            "observation": (
                "The provider configuration and mapped finance accounts have no Site relation. Organisation-wide "
                "finance configuration may be intended, but approved-Site and privacy correctness is unproved. "
                "Provider tenant_id is external provider context, never application tenancy."
            ),
            "correctness_credit_authorized": False,
        },
        {
            "finding_id": "RUN133R-SHARED-EXACT-PERMISSION",
            "severity": "P1",
            "category": "permission_coupling",
            "loci": [
                "routes/finance.php:62",
                "routes/finance.php:603-611",
                "app/Domain/Finance/Policies/FinAccountPolicy.php:8-33",
                "app/Providers/AuthServiceProvider.php:137-210",
            ],
            "observation": (
                "The selected lifecycle is guarded only by finance.admin. No accounting-integration policy exists, "
                "and the registered finance-account view/manage contracts are not invoked by mapping."
            ),
            "correctness_credit_authorized": False,
        },
        {
            "finding_id": "RUN133R-SHARED-AUDIT-SECRET-DURABILITY",
            "severity": "P1",
            "category": "audit_privacy_and_durability",
            "loci": [
                "app/Domain/Finance/Models/FinAccountingIntegration.php:13-51",
                "app/Models/Concerns/AuditableChanges.php:10-64",
                "app/Support/SafeOperationalData.php:201-212",
                "app/Services/AuditLogger.php:14-23",
                "app/Services/AuditLogger.php:47-75",
            ],
            "observation": (
                "The auditable Finance model declares no token/settings exclusions and is outside the protected "
                "request-context classes. Audit persistence is best effort and suppressed on failure, so secret "
                "representation, privacy, and durable mutation evidence remain unproved."
            ),
            "correctness_credit_authorized": False,
        },
        {
            "finding_id": "RUN133R-SHARED-ACTION-TEST-GAP",
            "severity": "P1",
            "category": "executable_assurance",
            "loci": [
                "tests/Browser/Finance/FinanceIntegrationMappingInteractionTest.php:8-72",
                "tests/Browser/Finance/FinanceMiscTest.php:126-134",
                "tests/Feature/Finance/XeroAccountingSyncProviderTest.php:1-285",
            ],
            "observation": (
                "Frozen tests statically include one mapping/updateMapping happy path plus index and provider coverage. "
                "Store, update, test, and destroy lack selected route-action coverage, and no selected test proves "
                "foreign-ID concealment, approved-Site behavior, exact permissions, replay, or concurrency. Nothing was executed."
            ),
            "correctness_credit_authorized": False,
        },
        {
            "finding_id": "RUN133R-SHARED-SOURCE-PACKET-EXPANSION",
            "severity": "EVIDENCE_BOUNDARY",
            "category": "source_packet_completeness",
            "loci": [],
            "observation": (
                "The cohort correctly claimed no source completeness. The fresh reviews reconcile bounded expanded "
                "loci and newly followed files without rewriting the cohort or authorising correctness."
            ),
            "correctness_credit_authorized": False,
        },
        {
            "finding_id": "RUN133R-SHARED-PAGE-AND-SYNC-NONINHERITANCE",
            "severity": "EVIDENCE_BOUNDARY",
            "category": "ownership_noninheritance",
            "loci": [
                "03-feature-to-benchmark-matrix.csv:51",
                "03-feature-to-benchmark-matrix.csv:92",
                "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json:31080-31149",
                "routes/finance.php:603-611",
            ],
            "observation": (
                "Index-page context transfers no ownership; the Mapping page remains the existing Xero-sync page "
                "owner; and the backend-only integrations.sync sibling remains excluded. No page, prior route, "
                "provider, or sibling inheritance is authorised."
            ),
            "correctness_credit_authorized": False,
        },
    ]
    for finding in shared_assurance_findings:
        for locus in finding["loci"]:
            assert_locus(locus)

    partition_reviews: list[dict[str, Any]] = []
    reviewer_by_partition = {
        "A": "/root/run125_accounts_show_edit",
        "B": "/root/run125_accounts_create",
        "C": "/root/run129_final_seal",
    }
    for partition_id, reviewer_task_path in reviewer_by_partition.items():
        rows = [row for row in action_decisions if row["partition_id"] == partition_id]
        assert len(rows) == 2
        assert {row["reviewer_task_path"] for row in rows} == {reviewer_task_path}
        partition_reviews.append(
            {
                "partition_id": partition_id,
                "reviewer_task_path": reviewer_task_path,
                "assigned_candidates": 2,
                "candidate_ids": [row["candidate_id"] for row in rows],
                "outcome_variables": {"O": 2, "S": 0, "A": 0, "D": 0, "E": 0},
                "verdict": "GO_BOUNDED_STATIC_OWNER_AND_BRIDGE_ONLY",
                "reviewer_wrote_files": False,
                "correctness_or_downstream_credit_authorized": False,
            }
        )

    synthesis_review = {
        "reviewer_task_path": "/root/run133r_synthesis",
        "verdict": "GO_ACCEPT_6_OWNER_ROUTE_ACTION_DECISIONS_FOR_BOUNDED_LATER_INTEGRATION",
        "accepted_candidate_ids": [row["candidate_id"] for row in action_decisions],
        "accepted_decision_record_sha256s": [row["decision_record_sha256"] for row in action_decisions],
        "outcome_variables": {"O": 6, "S": 0, "A": 0, "D": 0, "E": 0},
        "shared_lifecycle_reconciled": True,
        "source_packet_expansion_disclosed": True,
        "prior_index_route_or_bridge_inheritance_authorized": False,
        "index_page_inheritance_authorized": False,
        "mapping_page_reassignment_or_inheritance_authorized": False,
        "excluded_backend_only_sync_ownership_authorized": False,
        "previously_reviewed_index_route_reopened": False,
        "next_queue_boundary_changed": False,
        "page_ownership_authorized": False,
        "current_overlay_credit_awarded": False,
        "bounded_overlay_integration_authorized": True,
        "correctness_or_downstream_credit_authorized": False,
        "reviewer_wrote_files": False,
    }
    synthesis_review["synthesis_record_sha256"] = canonical_json_sha256(synthesis_review)

    baseline = cohort["current_baseline"]
    assert baseline["source_owner_records"] == 654
    assert baseline["route_owner_records"] == 297
    assert baseline["page_owner_records"] == 357
    assert baseline["static_controller_action_bridges"] == 85
    assert baseline["bounded_static_source_residual_records"] == 3275
    assert baseline["reviewed_queue_surface_rows"] == 108
    assert baseline["pending_unreviewed_queue_surface_rows"] == 399
    projection = {
        "O": 6,
        "S": 0,
        "A": 0,
        "D": 0,
        "E": 0,
        "source_owner_records": 660,
        "route_owner_records": 303,
        "page_owner_records": 357,
        "static_controller_action_bridges": 91,
        "bounded_static_source_denominator": 3929,
        "bounded_static_source_residual_records": 3269,
        "bounded_static_source_ownership_percent": str(
            (Decimal(660) * Decimal(100) / Decimal(3929)).quantize(
                Decimal("0.000001"), rounding=ROUND_HALF_UP
            )
        ),
        "direct_exact_queue_records": 507,
        "reviewed_queue_surface_rows": 114,
        "owner_queue_surface_rows": 92,
        "shared_queue_surface_rows": 10,
        "alias_queue_surface_rows": 5,
        "dead_queue_surface_rows": 0,
        "evidence_gap_queue_surface_rows": 7,
        "pending_unreviewed_queue_surface_rows": 393,
        "queue_surfaces_without_ownership": 415,
        "residual_explicit_unmapped_routes": 2898,
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
    assert projection["O"] + projection["S"] + projection["A"] + projection["D"] + projection["E"] == 6
    assert projection["source_owner_records"] + projection["bounded_static_source_residual_records"] == 3929
    assert projection["source_owner_records"] == projection["route_owner_records"] + projection["page_owner_records"]
    assert projection["bounded_static_source_ownership_percent"] == "16.798167"
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
    assert 3218 == 303 + 12 + 5 + 0 + 2898
    assert 711 == 357 + 9 + 345

    expected_cohort_identity = {
        "candidate_id_list_sha256": "d1f690f52a2e43287b1b2f119a7cd7f8d9b6ba3eca622860a401102e68a9f8b0",
        "queue_index_list_sha256": "c6748380bc15a7c90cd3d8a19c639039e6501ff90aac2d64524bcbba9fe2115e",
        "queue_id_list_sha256": "0ab1a5df6be68effc8e1d09302e154ce06100340ae35a0b988f471ff3d9e1b88",
        "canonical_key_list_sha256": "7759f09cbfc7d7965f56274be57a21e1211e709827b3050e3dc07b8c36112791",
        "queue_id_canonical_key_pair_list_sha256": "7588b15b565aa764286dedcea188c902cf42da1950d77b18a139bf98f345d781",
        "route_record_id_list_sha256": "29faf99573a19511a686a6281d363916970ee99f1908fd0ec9c2daeafc9d0ff7",
        "literal_route_name_list_sha256": "9aa95297f83b1106607dd6447d3da6e2e4eb0250b1dc77d1a23e9ff075ace173",
        "source_key_list_sha256": "e67ad8bdec4179b8bee64fd0670962bfa55ceaeb40b6920ebbeb7a81781ed1cd",
        "action_key_list_sha256": "450ddf13b7dc723368d4b7dc51cb3332e51ed7504825a3ab93244af2c123a70b",
        "feature_id_list_sha256": "e8f849f6e2ae93be58d18f78708cfb6c736d3af7cb01d36ea1897b9784377256",
        "new_feature_id_list_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
        "candidate_record_sha256_list_sha256": "24f834eaf951b126287b8a15f076f52eb3bc17555efcae0970502d5ed8c2e7b4",
        "queue_record_sha256_list_sha256": "ccf64df7d9014a0796e3f172a18c45a7aba5e78bc758265be51003ffb67eb97d",
        "records_sha256": "37c0607ace02367a09f11f8f2ddb726b731b46212114ad038bc142fff1ff22cd",
        "source_review_packet_sha256": SOURCE_PACKET_SHA256,
        "prior_owner_context_sha256": "e2a2f3053a600d29f2f978bf31e240df36c3c2cd3ebc015384020cc8c6df6906",
        "prior_bridge_context_sha256": "0d4eb9bc94bead43c084a0d205ab6676c6667529e21d94d6213ae02040fe417e",
        "excluded_sync_route_sha256": "d9571c0f0572917e7e75a9f740ce83f5093e6cf70e24d3f6a7522351a79593f2",
        "next_queue_record_sha256": "ebf85adb661b20cf542365a66e5bac407d7a072fb1627676d4e92ddd20bea933",
    }
    assert cohort["identity"] == expected_cohort_identity
    assert cohort["page_context_boundary"] == {
        "selected_controller_literal_inertia_page_callsites": 1,
        "existing_distinct_caller_or_render_pages": 2,
        "existing_index_page_feature_id": FEATURE_ID,
        "existing_mapping_page_feature_id": "CAP-FIN-XERO-ACCOUNTING-SYNC",
        "selected_frontend_literal_caller_contexts": 5,
        "selected_routes_without_literal_caller_in_frozen_pages": 1,
        "new_page_owner_records": 0,
        "page_ownership_inherited": False,
        "page_ownership_reassigned": False,
        "caller_presence_or_absence_preselects_route_outcome": False,
    }
    assert cohort["excluded_backend_only_sync_context"]["route_record_id"] == "RUN077-ROUTE-0595"
    assert cohort["excluded_backend_only_sync_context"]["ownership_credit_awarded"] is False
    assert cohort["next_queue_boundary"] == {
        "queue_index_zero_based": 77,
        "queue_id": "RUN090-ROUTE-0078",
        "route_record_id": "RUN077-ROUTE-0634",
        "candidate_feature_id": "CAP-FIN-BILLING-INVOICE-LIFECYCLE",
        "selected_for_run133": False,
        "credit_awarded": False,
    }

    decision_hashes = [row["decision_record_sha256"] for row in action_decisions]
    verified_global_identity = {
        "reviewed_candidate_id_list_sha256": cohort["identity"]["candidate_id_list_sha256"],
        "reviewed_queue_index_list_sha256": cohort["identity"]["queue_index_list_sha256"],
        "reviewed_queue_id_list_sha256": cohort["identity"]["queue_id_list_sha256"],
        "reviewed_canonical_key_list_sha256": cohort["identity"]["canonical_key_list_sha256"],
        "reviewed_queue_id_canonical_key_pair_list_sha256": cohort["identity"]["queue_id_canonical_key_pair_list_sha256"],
        "reviewed_route_record_id_list_sha256": cohort["identity"]["route_record_id_list_sha256"],
        "reviewed_literal_route_name_list_sha256": cohort["identity"]["literal_route_name_list_sha256"],
        "reviewed_source_key_list_sha256": cohort["identity"]["source_key_list_sha256"],
        "reviewed_action_key_list_sha256": cohort["identity"]["action_key_list_sha256"],
        "reviewed_feature_id_list_sha256": cohort["identity"]["feature_id_list_sha256"],
        "reviewed_candidate_record_sha256_list_sha256": cohort["identity"]["candidate_record_sha256_list_sha256"],
        "reviewed_queue_record_sha256_list_sha256": cohort["identity"]["queue_record_sha256_list_sha256"],
        "reviewed_records_sha256": cohort["identity"]["records_sha256"],
        "prior_owner_context_sha256": cohort["identity"]["prior_owner_context_sha256"],
        "prior_bridge_context_sha256": cohort["identity"]["prior_bridge_context_sha256"],
        "excluded_sync_route_sha256": cohort["identity"]["excluded_sync_route_sha256"],
        "next_queue_record_sha256": cohort["identity"]["next_queue_record_sha256"],
        "owner_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in action_decisions]),
        "owner_route_record_id_list_sha256": canonical_list_sha256([row["route_record_id"] for row in action_decisions]),
        "owner_source_record_key_list_sha256": canonical_list_sha256([row["owner_source_record_key"] for row in action_decisions]),
        "owner_action_key_list_sha256": canonical_list_sha256([row["action_key"] for row in action_decisions]),
        "owner_bridge_key_list_sha256": canonical_list_sha256(["|".join(row["bridge_key"]) for row in action_decisions]),
        "owner_candidate_record_sha256_list_sha256": canonical_list_sha256([row["candidate_record_sha256"] for row in action_decisions]),
        "owner_feature_id_list_sha256": canonical_list_sha256({FEATURE_ID}),
        "new_owner_feature_id_list_sha256": canonical_list_sha256(set()),
        "decision_record_sha256_list_sha256": canonical_list_sha256(decision_hashes),
        "reviewed_decisions_sha256": canonical_json_sha256(action_decisions),
        "partition_reviews_sha256": canonical_json_sha256(partition_reviews),
        "synthesis_record_sha256": synthesis_review["synthesis_record_sha256"],
        "source_packet_expansions_sha256": canonical_json_sha256(source_packet_expansions),
    }
    assert verified_global_identity["owner_candidate_id_list_sha256"] == "d1f690f52a2e43287b1b2f119a7cd7f8d9b6ba3eca622860a401102e68a9f8b0"
    assert verified_global_identity["owner_route_record_id_list_sha256"] == "29faf99573a19511a686a6281d363916970ee99f1908fd0ec9c2daeafc9d0ff7"
    assert verified_global_identity["owner_source_record_key_list_sha256"] == "dc7246640d2f0dc93b09ea0f30f059eab85f712cdb2a79507fa2eb2053d3e8f0"
    assert verified_global_identity["owner_action_key_list_sha256"] == "450ddf13b7dc723368d4b7dc51cb3332e51ed7504825a3ab93244af2c123a70b"
    assert verified_global_identity["owner_bridge_key_list_sha256"] == "c497a3ad5ccc6e00da357d580b0a329a511430758bb692c795a7b9c5e47a11c1"
    assert verified_global_identity["owner_candidate_record_sha256_list_sha256"] == "24f834eaf951b126287b8a15f076f52eb3bc17555efcae0970502d5ed8c2e7b4"
    assert verified_global_identity["owner_feature_id_list_sha256"] == "e8f849f6e2ae93be58d18f78708cfb6c736d3af7cb01d36ea1897b9784377256"
    assert verified_global_identity["new_owner_feature_id_list_sha256"] == "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"

    return {
        "schema_version": "run-133r-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21-v1",
        "run_id": "RUN-133R-INDEPENDENT-OUTCOME-NEUTRAL-FINANCE-ACCOUNTING-INTEGRATION-ROUTE-ACTION-REVIEW-WAVE-21",
        "status": "GO_THREE_PARTITION_REVIEWS_AND_FRESH_SYNTHESIS_COMPLETE_SIX_BOUNDED_OWNERS_ZERO_DOWNSTREAM_CREDIT",
        "reviewed_on": "2026-08-26",
        "decision": {
            "verdict": "GO_6_EXPLICIT_OWNER_ROUTE_ACTION",
            "mechanical_discrepancies": 0,
            "semantic_outcome_discrepancies": 0,
            "source_packet_expansion_discrepancies_disclosed": len(source_packet_expansions),
            "reviewed_route_actions": 6,
            "owner_route_actions": 6,
            "shared_relations": 0,
            "alias_or_redirect": 0,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 0,
            "static_route_owner_records_authorized": 6,
            "static_controller_action_bridges_authorized": 6,
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
            "Oblivion Findings is one operating organisation across multiple Sites. Legacy organization_id context "
            "is not tenancy or an access boundary, and provider tenant_id is external provider context. Static "
            "ownership proves neither approved-Site reach, exact permission, canonical concealment, privacy, "
            "provider behavior, mapping integrity, lifecycle, concurrency, nor audit durability."
        ),
        "methods": [
            "Three disjoint fresh reviewers adjudicated store/update, testConnection/destroy, and mapping/updateMapping; none wrote files.",
            "A fourth fresh reviewer independently re-read and reconciled all six decisions plus the shared integration lifecycle.",
            "A supplemental independent expansion check challenged partition expansion claims without becoming an action-outcome vote.",
            "Direct action ownership was kept separate from authorization, Site, privacy, provider, mapping, lifecycle, concurrency, audit, and assurance correctness.",
            "The original packet's explicit incompleteness was preserved; every material semantic expansion used by the materializer is pinned and disclosed.",
            "Only OWNER_ROUTE_ACTION authorizes later bounded route and bridge integration; page callers, renders, providers, prior owners, and siblings remain context only.",
        ],
        "verified_counts": {
            "partition_reviews": 3,
            "candidates_per_partition": 2,
            "cohort_synthesis_reviews": 1,
            "total_fresh_semantic_reviews": 4,
            "supplemental_source_expansion_reviews": 1,
            "reviewed_route_actions": 6,
            "owner_route_actions": 6,
            "accepted_route_records": 6,
            "accepted_controller_action_bridges": 6,
            "accepted_page_records": 0,
            "accepted_distinct_feature_ids": 1,
            "new_distinct_feature_ids": 0,
            "selected_controller_literal_inertia_page_callsites": 1,
            "existing_distinct_caller_or_render_pages": 2,
            "selected_frontend_literal_caller_contexts": 5,
            "selected_routes_without_literal_caller_in_frozen_pages": 1,
            "source_packet_expansion_files": len(source_packet_expansions),
            "source_packet_expansion_existing_files": 2,
            "source_packet_expansion_new_files": 4,
            "candidate_assurance_findings": sum(len(row["assurance_findings"]) for row in action_decisions),
            "shared_assurance_findings": len(shared_assurance_findings),
            "assurance_findings": sum(len(row["assurance_findings"]) for row in action_decisions) + len(shared_assurance_findings),
            "reviewer_written_files": 0,
            "matrix_rows_changed": 0,
            "matrix_cells_changed": 0,
        },
        "verified_global_identity": verified_global_identity,
        "partition_reviews": partition_reviews,
        "synthesis_review": synthesis_review,
        "action_decisions": action_decisions,
        "shared_assurance_findings": shared_assurance_findings,
        "source_packet_expansion": {
            "original_source_review_complete": False,
            "original_source_packet_completeness_claimed": False,
            "original_material_dependency_semantics_complete": False,
            "original_packet_retroactively_described_as_complete": False,
            "partition_A_extra_files_needed": 0,
            "expanded_files": source_packet_expansions,
            "expansion_authorizes_action_outcome_change": False,
            "expansion_authorizes_correctness_credit": False,
        },
        "reviewed_projection_if_integrated": projection,
        "shared_chain_reconciliation": {
            "all_six_actions_directly_realise_components_of_canonical_user_job": True,
            "store_output_is_bound_input_for_update_test_destroy_mapping_and_update_mapping": True,
            "update_mapping_values_have_downstream_xero_consumption": True,
            "downstream_consumption_transfers_sync_ownership": False,
            "unsafe_owner_may_remain_owner": True,
            "already_reviewed_index_route_record_id": "RUN077-ROUTE-0592",
            "already_reviewed_index_bridge_id": "RUN092-BRIDGE-03",
            "prior_index_owner_or_bridge_inheritance_used": False,
            "index_page_record_id": "PAGE-ROOT-679D3E7F4B5402CB",
            "mapping_page_record_id": "PAGE-ROOT-BA2E4950746EAF10",
            "mapping_page_existing_feature_id": "CAP-FIN-XERO-ACCOUNTING-SYNC",
            "frontend_page_ownership_inherited_or_reassigned": False,
            "excluded_backend_only_sync_route_record_id": "RUN077-ROUTE-0595",
            "excluded_backend_only_sync_ownership_or_bridge_awarded": False,
            "next_queue_boundary": {
                "queue_index_zero_based": 77,
                "queue_id": "RUN090-ROUTE-0078",
                "route_record_id": "RUN077-ROUTE-0634",
            },
            "next_queue_boundary_changed_or_credited": False,
            "current_overlay_credit_awarded": False,
        },
        "credit_boundary": {
            "REVIEWED_STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_6_RECORDS": True,
            "REVIEWED_STATIC_CONTROLLER_ACTION_BRIDGE_FOR_6_ACTIONS": True,
            "BOUNDED_OVERLAY_INTEGRATION_AUTHORIZED": True,
            "CURRENT_OVERLAY_OWNERSHIP_CREDIT": False,
            "STATIC_PAGE_FEATURE_OWNERSHIP": False,
            "prior_owner_or_bridge_inheritance": False,
            "excluded_backend_only_sync_ownership": False,
            "framework_route_reachability": False,
            "navigation": False,
            "canonical_object_ownership_correctness": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "privacy_correctness": False,
            "direct_object_concealment_correctness": False,
            "provider_credential_and_behavior_correctness": False,
            "mapping_and_external_posting_correctness": False,
            "audit_privacy_and_durability_correctness": False,
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
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/raw-run-133r-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21.json",
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
